<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Actions\Accounting\SubmitForApprovalAction;
use App\Actions\Accounting\UpdateJournalDraftAction;
use App\Data\Accounting\JournalEntryData;
use App\Data\Accounting\JournalLineData;
use App\Exceptions\Accounting\JournalRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S2-04 — the journal draft lifecycle over the immutable S2-03 schema. Exercised against real PostgreSQL
 * (the CHECKs, the RLS boundary, the immutability + no-AI-autopost triggers) through the Actions directly
 * (S2-04 has no HTTP layer). Create/edit run inside a tenant context (TenantHarness::runInTenant); entries
 * acted upon are seeded on the owner connection so they persist across the rolled-back tenant transaction.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/** An asset account in the given company (owner insert — persists, bypasses RLS). */
function jeAccount(int $companyId): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit') RETURNING id",
        [$companyId, $typeId, 'A'.bin2hex(random_bytes(6))],
    )->id;
}

/** A persisted journal entry (+ optional two balanced lines) in the given status/version. */
function jeSeedEntry(int $companyId, string $status = 'draft', int $version = 1, bool $withLines = true): int
{
    $owner = TenantHarness::owner();

    $id = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, version)
         VALUES (?, ?, '2026-07-01', 'manual', 'KWD', ?::journal_entry_status, ?) RETURNING id",
        [$companyId, 'JE-'.bin2hex(random_bytes(8)), $status, $version],
    )->id;

    if ($withLines) {
        foreach ([[1, '100.0000', '0'], [2, '0', '100.0000']] as [$n, $debit, $credit]) {
            $owner->insert(
                "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit, currency_code, base_debit, base_credit)
                 VALUES (?, ?, ?, ?, ?, ?, 'KWD', ?, ?)",
                [$companyId, $id, $n, jeAccount($companyId), $debit, $credit, $debit, $credit],
            );
        }
    }

    return $id;
}

// ---------------------------------------------------------------- create

it('creates a balanced draft: status draft, provisional number, zero balanced header totals, numbered lines', function (): void {
    $co = TenantHarness::seedCompany('JE Create Co');
    $debitAccount = jeAccount($co['company_id']);
    $creditAccount = jeAccount($co['company_id']);

    [$entry, $lines] = TenantHarness::runInTenant($co['company_id'], function () use ($debitAccount, $creditAccount) {
        $entry = app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
            journalDate: '2026-07-01',
            entryType: 'manual',
            currencyCode: 'KWD',
            lines: [
                new JournalLineData(accountId: $debitAccount, debit: '100.0000'),
                new JournalLineData(accountId: $creditAccount, credit: '100.0000'),
            ],
        ));
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->orderBy('line_number')->get();

        return [$entry, $lines];
    });

    expect($entry->status)->toBe('draft');
    expect($entry->journal_number)->toStartWith('DRAFT-');
    expect($entry->version)->toBe(1);
    // The unconditional chk_je_balanced invariant: cached header totals are balanced (at zero) for a draft.
    expect($entry->total_debit)->toBe($entry->total_credit);
    expect($entry->total_debit)->toBe('0.0000');

    expect($lines)->toHaveCount(2);
    expect((int) $lines[0]->line_number)->toBe(1);
    expect($lines[0]->debit)->toBe('100.0000');
    expect($lines[0]->base_debit)->toBe('100.0000');
    expect($lines[1]->credit)->toBe('100.0000');
});

it('creates an AI-generated entry only as a draft, never posted', function (): void {
    $co = TenantHarness::seedCompany('JE AI Co');
    $a = jeAccount($co['company_id']);
    $b = jeAccount($co['company_id']);

    $entry = TenantHarness::runInTenant($co['company_id'], fn (): JournalEntry => app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
        journalDate: '2026-07-01',
        entryType: 'ai_generated',
        currencyCode: 'KWD',
        lines: [new JournalLineData($a, '50.0000'), new JournalLineData($b, '0', '50.0000')],
        aiGenerated: true,
        aiConfidence: 0.9,
    )));

    expect($entry->status)->toBe('draft');
    expect($entry->ai_generated)->toBeTrue();
});

it('refuses an AI-generated entry without ai_confidence (422 AI_CONFIDENCE_REQUIRED)', function (): void {
    $co = TenantHarness::seedCompany('JE AI Bad');
    $a = jeAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($a): void {
        try {
            app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
                journalDate: '2026-07-01', entryType: 'ai_generated', currencyCode: 'KWD',
                lines: [new JournalLineData($a, '50.0000')], aiGenerated: true,
            ));
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('AI_CONFIDENCE_REQUIRED');
            expect($e->errorStatus())->toBe(422);
        }
        expect($threw)->toBeTrue();
    });
});

it('refuses a two-sided line (422 INVALID_JOURNAL_LINE)', function (): void {
    $co = TenantHarness::seedCompany('JE Two Sided');
    $a = jeAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($a): void {
        try {
            app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
                journalDate: '2026-07-01', entryType: 'manual', currencyCode: 'KWD',
                lines: [new JournalLineData($a, '10.0000', '5.0000')], // both sides > 0
            ));
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('INVALID_JOURNAL_LINE');
        }
        expect($threw)->toBeTrue();
    });
});

it('refuses a line whose account belongs to another company (422 INVALID_JOURNAL_ACCOUNT)', function (): void {
    $a = TenantHarness::seedCompany('JE Own');
    $b = TenantHarness::seedCompany('JE Foreign');
    $foreignAccount = jeAccount($b['company_id']);

    TenantHarness::runInTenant($a['company_id'], function () use ($foreignAccount): void {
        try {
            app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
                journalDate: '2026-07-01', entryType: 'manual', currencyCode: 'KWD',
                lines: [new JournalLineData($foreignAccount, '100.0000')],
            ));
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('INVALID_JOURNAL_ACCOUNT');
        }
        expect($threw)->toBeTrue();
    });
});

it('refuses an unknown entry type (422 INVALID_ENTRY_TYPE)', function (): void {
    $co = TenantHarness::seedCompany('JE Bad Type');
    $a = jeAccount($co['company_id']);

    TenantHarness::runInTenant($co['company_id'], function () use ($a): void {
        try {
            app(CreateJournalEntryAction::class)->execute(new JournalEntryData(
                journalDate: '2026-07-01', entryType: 'not_a_type', currencyCode: 'KWD',
                lines: [new JournalLineData($a, '100.0000')],
            ));
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('INVALID_ENTRY_TYPE');
        }
        expect($threw)->toBeTrue();
    });
});

// ---------------------------------------------------------------- update

it('updates a draft, replaces its lines, and bumps the version', function (): void {
    $co = TenantHarness::seedCompany('JE Upd Co');
    $id = jeSeedEntry($co['company_id'], 'draft', 1);
    $newDebit = jeAccount($co['company_id']);
    $newCredit = jeAccount($co['company_id']);

    [$updated, $lines] = TenantHarness::runInTenant($co['company_id'], function () use ($id, $newDebit, $newCredit) {
        $entry = JournalEntry::query()->findOrFail($id);
        $updated = app(UpdateJournalDraftAction::class)->execute($entry, new JournalEntryData(
            journalDate: '2026-08-01',
            entryType: 'adjustment',
            currencyCode: 'KWD',
            lines: [new JournalLineData($newDebit, '250.0000'), new JournalLineData($newCredit, '0', '250.0000')],
        ), 1);
        $lines = JournalLine::query()->where('journal_entry_id', $id)->orderBy('line_number')->get();

        return [$updated, $lines];
    });

    expect($updated->version)->toBe(2);
    expect($updated->entry_type)->toBe('adjustment');
    expect($updated->total_debit)->toBe($updated->total_credit); // header still balanced
    expect($lines)->toHaveCount(2);
    expect($lines[0]->debit)->toBe('250.0000');
});

it('rejects an update with a stale version (409 VERSION_CONFLICT)', function (): void {
    $co = TenantHarness::seedCompany('JE Stale Co');
    $id = jeSeedEntry($co['company_id'], 'draft', 1);

    TenantHarness::runInTenant($co['company_id'], function () use ($id): void {
        $entry = JournalEntry::query()->findOrFail($id);
        try {
            app(UpdateJournalDraftAction::class)->execute($entry, new JournalEntryData(
                journalDate: '2026-07-01', entryType: 'manual', currencyCode: 'KWD', lines: [],
            ), 99); // stale expected version
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('VERSION_CONFLICT');
            expect($e->errorStatus())->toBe(409);
        }
        expect($threw)->toBeTrue();
    });
});

it('refuses to edit a non-draft entry (409 JOURNAL_NOT_EDITABLE)', function (): void {
    $co = TenantHarness::seedCompany('JE Posted Co');
    $id = jeSeedEntry($co['company_id'], 'posted', 1);

    TenantHarness::runInTenant($co['company_id'], function () use ($id): void {
        $entry = JournalEntry::query()->findOrFail($id);
        try {
            app(UpdateJournalDraftAction::class)->execute($entry, new JournalEntryData(
                journalDate: '2026-07-01', entryType: 'manual', currencyCode: 'KWD', lines: [],
            ), 1);
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('JOURNAL_NOT_EDITABLE');
            expect($e->errorStatus())->toBe(409);
        }
        expect($threw)->toBeTrue();
    });
});

// ---------------------------------------------------------------- submit

it('submits a draft for approval and bumps the version', function (): void {
    $co = TenantHarness::seedCompany('JE Submit Co');
    $id = jeSeedEntry($co['company_id'], 'draft', 1);

    $submitted = TenantHarness::runInTenant($co['company_id'], function () use ($id): JournalEntry {
        $entry = JournalEntry::query()->findOrFail($id);

        return app(SubmitForApprovalAction::class)->execute($entry, 1);
    });

    expect($submitted->status)->toBe('pending_approval');
    expect($submitted->version)->toBe(2);
});

it('rejects a submit with a stale version (409 VERSION_CONFLICT)', function (): void {
    $co = TenantHarness::seedCompany('JE Submit Stale');
    $id = jeSeedEntry($co['company_id'], 'draft', 1);

    TenantHarness::runInTenant($co['company_id'], function () use ($id): void {
        $entry = JournalEntry::query()->findOrFail($id);
        try {
            app(SubmitForApprovalAction::class)->execute($entry, 99);
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('VERSION_CONFLICT');
        }
        expect($threw)->toBeTrue();
    });
});

it('refuses to submit an entry with no lines (422 CANNOT_SUBMIT_EMPTY)', function (): void {
    $co = TenantHarness::seedCompany('JE Empty Co');
    $id = jeSeedEntry($co['company_id'], 'draft', 1, withLines: false);

    TenantHarness::runInTenant($co['company_id'], function () use ($id): void {
        $entry = JournalEntry::query()->findOrFail($id);
        try {
            app(SubmitForApprovalAction::class)->execute($entry, 1);
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('CANNOT_SUBMIT_EMPTY');
        }
        expect($threw)->toBeTrue();
    });
});

it('never lets an AI actor submit an entry (403 AI_CANNOT_SUBMIT)', function (): void {
    $co = TenantHarness::seedCompany('JE AI Submit');
    $id = jeSeedEntry($co['company_id'], 'draft', 1);

    TenantHarness::runInTenant($co['company_id'], function () use ($id): void {
        $entry = JournalEntry::query()->findOrFail($id);
        try {
            app(SubmitForApprovalAction::class)->execute($entry, 1, actorIsAi: true);
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('AI_CANNOT_SUBMIT');
            expect($e->errorStatus())->toBe(403);
        }
        expect($threw)->toBeTrue();
    });
});

it('refuses to submit a non-draft entry (409 JOURNAL_NOT_EDITABLE)', function (): void {
    $co = TenantHarness::seedCompany('JE Submit Posted');
    $id = jeSeedEntry($co['company_id'], 'posted', 1);

    TenantHarness::runInTenant($co['company_id'], function () use ($id): void {
        $entry = JournalEntry::query()->findOrFail($id);
        try {
            app(SubmitForApprovalAction::class)->execute($entry, 1);
            $threw = false;
        } catch (JournalRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('JOURNAL_NOT_EDITABLE');
        }
        expect($threw)->toBeTrue();
    });
});

// ---------------------------------------------------------------- isolation

it('never exposes one company\'s journal entry to another tenant', function (): void {
    $a = TenantHarness::seedCompany('JE Tenant A');
    $b = TenantHarness::seedCompany('JE Tenant B');
    $id = jeSeedEntry($a['company_id'], 'draft', 1);

    $visibleToB = TenantHarness::runInTenant($b['company_id'], fn (): bool => JournalEntry::query()->whereKey($id)->exists());

    expect($visibleToB)->toBeFalse();
});
