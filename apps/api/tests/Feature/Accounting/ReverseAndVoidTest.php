<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Accounting\VoidJournalEntryAction;
use App\Data\Accounting\JournalEntryData;
use App\Data\Accounting\JournalLineData;
use App\Exceptions\Accounting\ReversalRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerEntry;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S2-06 — reverse & void, against real PostgreSQL.
 *
 * Reversal is a NEW posted mirror entry with debit/credit exchanged, created through the same posting
 * path as everything else; the original is never mutated beyond its status and back-link. Voiding is only
 * for entries that never took financial effect. The immutability of posted history is asserted here at
 * the Action layer AND at the database layer.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/** An asset account in the given company (owner insert — persists across the rolled-back tenant tx). */
function revAccount(int $companyId): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit') RETURNING id",
        [$companyId, $typeId, 'R'.bin2hex(random_bytes(6))],
    )->id;
}

/** An OPEN fiscal year covering 2026 — the posting engine refuses a date outside an open year. */
function revFiscalYear(int $companyId): int
{
    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2026-01-01', '2026-12-31', 'open') RETURNING id",
        [$companyId, 'FY2026-'.bin2hex(random_bytes(3))],
    )->id;
}

/** A second active member, so segregation of duties is actually in force for the company. */
function revSecondMember(int $companyId, int $roleId): int
{
    $userId = (int) TenantHarness::owner()->selectOne(
        'INSERT INTO users (email, name) VALUES (?, ?) RETURNING id',
        [uniqid('u', true).'@example.test', 'Second User'],
    )->id;

    TenantHarness::owner()->insert(
        "INSERT INTO company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active')",
        [$companyId, $userId, $roleId],
    );

    return $userId;
}

/** Create + post a balanced two-line entry. Runs inside an established tenant context. */
function revPostedEntry(int $debitAccount, int $creditAccount, ?int $actorUserId = null): JournalEntry
{
    $draft = app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
        journalDate: '2026-06-15',
        entryType: 'manual',
        currencyCode: 'KWD',
        lines: [
            new JournalLineData(accountId: $debitAccount, debit: '100.0000'),
            new JournalLineData(accountId: $creditAccount, credit: '100.0000'),
        ],
        memo: 'Original entry',
    ), $actorUserId);

    return app(PostJournalEntryAction::class)->execute($draft, $actorUserId);
}

// ---------------------------------------------------------------- reverse

it('reverses a posted entry into a balanced mirror linked both ways, leaving the original intact', function (): void {
    $co = TenantHarness::seedCompany('Reverse Co');
    revFiscalYear($co['company_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    [$original, $mirror, $mirrorLines, $originalLines] = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit) {
        $original = revPostedEntry($debit, $credit);
        $originalNumber = $original->journal_number;
        $originalLinesBefore = JournalLine::query()->where('journal_entry_id', $original->id)
            ->orderBy('line_number')->get()->map(fn ($l) => [$l->debit, $l->credit])->all();

        $mirror = app(ReverseJournalEntryAction::class)->execute($original, 'Duplicate posting');

        expect($original->journal_number)->toBe($originalNumber);

        return [
            $original->refresh(),
            $mirror,
            JournalLine::query()->where('journal_entry_id', $mirror->id)->orderBy('line_number')->get(),
            $originalLinesBefore,
        ];
    });

    // The mirror is posted, flagged a reversal, and points back at the original.
    expect($mirror->status)->toBe('posted');
    expect($mirror->is_reversal)->toBeTrue();
    expect((int) $mirror->reversed_entry_id)->toBe($original->id);
    expect($mirror->entry_type)->toBe('reversal');

    // The original is now `reversed` and links forward — traceable in both directions.
    expect($original->status)->toBe('reversed');
    expect((int) $original->reversal_entry_id)->toBe($mirror->id);

    // The mirror balances, and every line's debit/credit is the exchange of the original's.
    expect($mirror->total_debit)->toBe($mirror->total_credit);
    expect($mirrorLines)->toHaveCount(2);
    expect($mirrorLines[0]->credit)->toBe($originalLines[0][0]);   // original debit → mirror credit
    expect($mirrorLines[1]->debit)->toBe($originalLines[1][1]);    // original credit → mirror debit
});

it('nets the ledger to zero after a reversal', function (): void {
    $co = TenantHarness::seedCompany('Reverse Net Co');
    revFiscalYear($co['company_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    $net = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit) {
        $original = revPostedEntry($debit, $credit);
        app(ReverseJournalEntryAction::class)->execute($original, 'Correcting an error');

        $rows = LedgerEntry::query()
            ->whereIn('journal_entry_id', [$original->id, $original->refresh()->reversal_entry_id])
            ->get();

        return array_reduce(
            $rows->all(),
            fn (string $carry, LedgerEntry $row): string => bcadd($carry, $row->signed_base_amount, 4),
            '0',
        );
    });

    // Original + reversal must net to exactly zero across the projection — no rounding, no drift.
    expect($net)->toBe('0.0000');
});

it('refuses to reverse the same entry twice', function (): void {
    $co = TenantHarness::seedCompany('Double Reverse Co');
    revFiscalYear($co['company_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit): void {
        $original = revPostedEntry($debit, $credit);
        app(ReverseJournalEntryAction::class)->execute($original, 'First reversal');

        try {
            app(ReverseJournalEntryAction::class)->execute($original->refresh(), 'Second reversal');
            expect(false)->toBeTrue('a second reversal should be refused');
        } catch (ReversalRuleException $e) {
            // The original is now `reversed`, so the status guard trips first — either refusal is correct.
            expect($e->errorCode())->toBeIn(['ALREADY_REVERSED', 'ENTRY_NOT_POSTED']);
            expect($e->errorStatus())->toBe(409);
        }
    });
});

it('refuses to reverse an entry that is not posted', function (): void {
    $co = TenantHarness::seedCompany('Reverse Draft Co');
    revFiscalYear($co['company_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit): void {
        $draft = app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
            journalDate: '2026-06-15',
            entryType: 'manual',
            currencyCode: 'KWD',
            lines: [
                new JournalLineData(accountId: $debit, debit: '50.0000'),
                new JournalLineData(accountId: $credit, credit: '50.0000'),
            ],
        ));

        expect(fn () => app(ReverseJournalEntryAction::class)->execute($draft, 'nope'))
            ->toThrow(ReversalRuleException::class);
    });
});

it('enforces segregation of duties: the creator cannot reverse their own entry', function (): void {
    $co = TenantHarness::seedCompany('SoD Co');
    revFiscalYear($co['company_id']);
    revSecondMember($co['company_id'], $co['role_id']);   // ⇒ more than one active member
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit, $co): void {
        $original = revPostedEntry($debit, $credit, $co['user_id']);

        try {
            app(ReverseJournalEntryAction::class)->execute($original, 'self reversal', $co['user_id']);
            expect(false)->toBeTrue('the creator should not be able to reverse their own entry');
        } catch (ReversalRuleException $e) {
            expect($e->errorCode())->toBe('SEGREGATION_OF_DUTIES');
            expect($e->errorStatus())->toBe(403);
        }
    });
});

it('allows a second user to reverse an entry they did not create', function (): void {
    $co = TenantHarness::seedCompany('SoD Allowed Co');
    revFiscalYear($co['company_id']);
    $second = revSecondMember($co['company_id'], $co['role_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    $mirror = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit, $co, $second) {
        $original = revPostedEntry($debit, $credit, $co['user_id']);

        return app(ReverseJournalEntryAction::class)->execute($original, 'reviewed correction', $second);
    });

    expect($mirror->status)->toBe('posted');
    expect($mirror->is_reversal)->toBeTrue();
});

it('permits a sole member to reverse their own entry (single-member company exception)', function (): void {
    $co = TenantHarness::seedCompany('Solo Co');   // seedCompany creates exactly one active member
    revFiscalYear($co['company_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    $mirror = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit, $co) {
        $original = revPostedEntry($debit, $credit, $co['user_id']);

        return app(ReverseJournalEntryAction::class)->execute($original, 'sole operator fix', $co['user_id']);
    });

    expect($mirror->status)->toBe('posted');
});

// ---------------------------------------------------------------- void

it('voids a draft entry', function (): void {
    $co = TenantHarness::seedCompany('Void Draft Co');
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    $voided = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit) {
        $draft = app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
            journalDate: '2026-06-15',
            entryType: 'manual',
            currencyCode: 'KWD',
            lines: [
                new JournalLineData(accountId: $debit, debit: '25.0000'),
                new JournalLineData(accountId: $credit, credit: '25.0000'),
            ],
        ));

        return app(VoidJournalEntryAction::class)->execute($draft);
    });

    expect($voided->status)->toBe('voided');
});

it('refuses to void a posted entry and names reverse as the remedy (409 IMMUTABLE_RECORD)', function (): void {
    $co = TenantHarness::seedCompany('Void Posted Co');
    revFiscalYear($co['company_id']);
    $debit = revAccount($co['company_id']);
    $credit = revAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit): void {
        $original = revPostedEntry($debit, $credit);

        try {
            app(VoidJournalEntryAction::class)->execute($original);
            expect(false)->toBeTrue('voiding a posted entry should be refused');
        } catch (ReversalRuleException $e) {
            expect($e->errorCode())->toBe('IMMUTABLE_RECORD');
            expect($e->errorStatus())->toBe(409);
            expect($e->getMessage())->toContain('reverse');
            expect($e->meta['remedy'])->toBe('reverse');
        }
    });
});

// ---------------------------------------------------------------- database-level guarantee

it('rejects a second reversal of the same original at the database, not just in the Action', function (): void {
    $co = TenantHarness::seedCompany('DB Guard Co');
    $owner = TenantHarness::owner();

    $originalId = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status)
         VALUES (?, ?, '2026-06-15', 'manual', 'KWD', 'posted') RETURNING id",
        [$co['company_id'], 'JE-'.bin2hex(random_bytes(8))],
    )->id;

    $insertMirror = fn (): int => (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code,
                                      status, is_reversal, reversed_entry_id)
         VALUES (?, ?, '2026-06-15', 'reversal', 'KWD', 'posted', true, ?) RETURNING id",
        [$co['company_id'], 'JE-'.bin2hex(random_bytes(8)), $originalId],
    )->id;

    $insertMirror();

    // uq_je_one_reversal makes "one reversal per original" a database guarantee — bypassing the Action
    // entirely, as a backfill or a psql session would.
    expect(fn () => $insertMirror())->toThrow(QueryException::class);
});
