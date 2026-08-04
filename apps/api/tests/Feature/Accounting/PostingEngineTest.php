<?php

declare(strict_types=1);

use App\Actions\Accounting\PostJournalEntryAction;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Events\Accounting\JournalEntryPosted;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\PostingRuleException;
use App\Exceptions\Accounting\UnbalancedEntryException;
use App\Models\JournalEntry;
use App\Models\LedgerEntry;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Support\TenantHarness;

/**
 * S2-05 — the posting engine against real PostgreSQL: the balance invariant, the fiscal-calendar gate,
 * postable accounts, permanent numbering, the ledger projection, idempotency, atomicity (no partial
 * post), the after-commit event, and tenant isolation. Entries are seeded on the owner connection so
 * they persist across the rolled-back tenant transaction; posting runs inside `runInTenant`.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/** An account in the given company, active by default (owner insert — bypasses RLS, persists). */
function peAccount(int $companyId, string $status = 'active'): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit', ?) RETURNING id",
        [$companyId, $typeId, 'A'.bin2hex(random_bytes(6)), $status],
    )->id;
}

/**
 * A fiscal year covering 2026 for the company, in the given status, filled with its twelve monthly
 * periods in the matching status. Since S2-07 the posting engine resolves a date to a PERIOD, so a year
 * without periods accepts nothing — generating them is part of building a usable year, and going through
 * the production generator means the fixture cannot drift from what onboarding actually creates.
 */
function peFiscalYear(int $companyId, string $status = 'open', string $name = 'FY2026'): int
{
    $yearId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2026-01-01', '2026-12-31', ?::fiscal_year_status) RETURNING id",
        [$companyId, $name, $status],
    )->id;

    MonthlyFiscalPeriodGenerator::generate(
        TenantHarness::owner(), $companyId, $yearId, '2026-01-01', '2026-12-31', $status,
    );

    return $yearId;
}

/**
 * A persisted draft entry with the given legs.
 *
 * @param  list<array{0:int,1:string,2:string}>  $legs  [accountId, debit, credit]
 */
function peDraft(int $companyId, array $legs, string $status = 'draft', string $date = '2026-07-01'): int
{
    $owner = TenantHarness::owner();

    $id = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status)
         VALUES (?, ?, ?, 'manual', 'KWD', ?::journal_entry_status) RETURNING id",
        [$companyId, 'DRAFT-'.bin2hex(random_bytes(8)), $date, $status],
    )->id;

    $n = 0;
    foreach ($legs as [$accountId, $debit, $credit]) {
        $n++;
        $owner->insert(
            "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit, currency_code, base_debit, base_credit)
             VALUES (?, ?, ?, ?, ?, ?, 'KWD', ?, ?)",
            [$companyId, $id, $n, $accountId, $debit, $credit, $debit, $credit],
        );
    }

    return $id;
}

/** Post the entry inside a tenant context. */
function pePost(int $companyId, int $entryId, ?int $actorUserId = null): JournalEntry
{
    return TenantHarness::runInTenant($companyId, fn (): JournalEntry => pePostHere($entryId, $actorUserId));
}

/**
 * Post the entry in an ALREADY-established tenant context. `runInTenant` rolls its transaction back for
 * test isolation, so any assertion about persisted post-effects (ledger rows, the number sequence) must
 * run inside the same context — hence this in-context variant.
 */
function pePostHere(int $entryId, ?int $actorUserId = null): JournalEntry
{
    $entry = JournalEntry::query()->findOrFail($entryId);

    return app(PostJournalEntryAction::class)->execute($entry, $actorUserId);
}

/**
 * A COMMITTED posted entry + line + ledger row for the company, written on the owner connection (which
 * bypasses RLS), so it survives outside any tenant transaction — what the append-only and cross-tenant
 * visibility assertions need.
 *
 * @return array{entry_id:int, ledger_id:int}
 */
function peSeedPostedLedgerRow(int $companyId): array
{
    $owner = TenantHarness::owner();
    $fiscalYearId = peFiscalYear($companyId, 'open', 'FY-Seed-'.bin2hex(random_bytes(4)));
    $accountId = peAccount($companyId);

    $entryId = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, fiscal_year_id, posted_at, locked)
         VALUES (?, ?, '2026-07-01', 'manual', 'KWD', 'posted', ?, now(), true) RETURNING id",
        [$companyId, 'JE-SEED-'.bin2hex(random_bytes(8)), $fiscalYearId],
    )->id;

    $lineId = (int) $owner->selectOne(
        "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit, currency_code, base_debit, base_credit)
         VALUES (?, ?, 1, ?, '30.0000', 0, 'KWD', '30.0000', 0) RETURNING id",
        [$companyId, $entryId, $accountId],
    )->id;

    // The period covering the entry date. `ledger_entries.fiscal_period_id` is NOT NULL since S2-07 —
    // a projection row that cannot name its period is not something a month can be closed against — so
    // even a hand-seeded ledger row has to resolve it.
    $periodId = (int) $owner->selectOne(
        "SELECT id FROM fiscal_periods WHERE fiscal_year_id = ? AND '2026-07-01' BETWEEN start_date AND end_date",
        [$fiscalYearId],
    )->id;

    $ledgerId = (int) $owner->selectOne(
        "INSERT INTO ledger_entries (company_id, journal_entry_id, journal_line_id, account_id, fiscal_year_id,
                                     fiscal_period_id, entry_date, posted_at, entry_type, currency_code,
                                     debit_amount, credit_amount, base_debit_amount, base_credit_amount, signed_base_amount)
         VALUES (?, ?, ?, ?, ?, ?, '2026-07-01', now(), 'manual', 'KWD', '30.0000', 0, '30.0000', 0, '30.0000') RETURNING id",
        [$companyId, $entryId, $lineId, $accountId, $fiscalYearId, $periodId],
    )->id;

    return ['entry_id' => $entryId, 'ledger_id' => $ledgerId];
}

// ---------------------------------------------------------------- the happy path

it('posts a balanced draft: status posted, permanent number, balanced totals, locked, fiscal year stamped', function (): void {
    $co = TenantHarness::seedCompany('Post Co');
    peFiscalYear($co['company_id']);
    $debit = peAccount($co['company_id']);
    $credit = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$debit, '850.0000', '0'], [$credit, '0', '850.0000']]);

    $posted = pePost($co['company_id'], $id, $co['user_id']);

    expect($posted->status)->toBe('posted');
    expect($posted->journal_number)->toBe('JE-FY2026-000001');
    expect($posted->locked)->toBeTrue();
    expect($posted->total_debit)->toBe('850.0000');
    expect($posted->total_credit)->toBe('850.0000');
    expect($posted->base_total_debit)->toBe('850.0000');
    expect($posted->fiscal_year_id)->not->toBeNull();
    expect($posted->posted_at)->not->toBeNull();
    expect((int) $posted->posted_by)->toBe($co['user_id']);
});

it('projects exactly one ledger row per posted line, with the correct signed base amount', function (): void {
    $co = TenantHarness::seedCompany('Ledger Co');
    peFiscalYear($co['company_id']);
    $debit = peAccount($co['company_id']);
    $credit = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$debit, '100.0000', '0'], [$credit, '0', '100.0000']]);

    // The projection is asserted INSIDE the tenant transaction (runInTenant rolls back afterwards).
    $rows = TenantHarness::runInTenant($co['company_id'], function () use ($id): array {
        pePostHere($id);

        return LedgerEntry::query()->where('journal_entry_id', $id)->orderBy('id')->get()->all();
    });

    expect($rows)->toHaveCount(2);
    // Debit leg: +base_debit. Credit leg: -base_credit. The pair nets to zero — a balanced ledger.
    expect($rows[0]->signed_base_amount)->toBe('100.0000');
    expect($rows[1]->signed_base_amount)->toBe('-100.0000');
    expect($rows[0]->account_id)->toBe($debit);
    expect($rows[1]->account_id)->toBe($credit);
    expect($rows[0]->entry_type)->toBe('manual');
    expect($rows[0]->posted_at)->not->toBeNull();
    expect($rows[0]->fiscal_year_id)->toBeGreaterThan(0);
    expect($rows[0]->debit_amount)->toBe('100.0000');
    expect($rows[1]->credit_amount)->toBe('100.0000');

    $net = bcadd($rows[0]->signed_base_amount, $rows[1]->signed_base_amount, 4);
    expect($net)->toBe('0.0000');
});

it('emits accounting.journal.posted after the post, carrying the permanent number', function (): void {
    $co = TenantHarness::seedCompany('Event Co');
    peFiscalYear($co['company_id']);
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '25.0000', '0'], [$b, '0', '25.0000']]);

    Event::fake([JournalEntryPosted::class]);
    pePost($co['company_id'], $id);

    Event::assertDispatched(JournalEntryPosted::class, function (JournalEntryPosted $e) use ($id, $co): bool {
        return $e->journalEntryId === $id
            && $e->companyId === $co['company_id']
            && $e->journalNumber === 'JE-FY2026-000001'
            && $e->baseTotal === '25.0000';
    });
});

// ---------------------------------------------------------------- the invariants

it('refuses an unbalanced entry (422 BALANCE_MISMATCH) and writes no ledger row', function (): void {
    $co = TenantHarness::seedCompany('Unbalanced Co');
    peFiscalYear($co['company_id']);
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '850.0000', '0'], [$b, '0', '800.0000']]);

    expect(fn () => pePost($co['company_id'], $id))
        ->toThrow(UnbalancedEntryException::class);

    // No partial post: the entry is untouched and the ledger is empty.
    $entry = TenantHarness::owner()->table('journal_entries')->where('id', $id)->first();
    expect($entry->status)->toBe('draft');
    expect($entry->journal_number)->toStartWith('DRAFT-');
    expect(TenantHarness::owner()->table('ledger_entries')->where('journal_entry_id', $id)->count())->toBe(0);
});

it('refuses posting into a closed fiscal year (422 CLOSED_PERIOD)', function (): void {
    $co = TenantHarness::seedCompany('Closed Year Co');
    peFiscalYear($co['company_id'], 'closed');
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '10.0000', '0'], [$b, '0', '10.0000']]);

    expect(fn () => pePost($co['company_id'], $id))->toThrow(ClosedPeriodException::class);
    expect(TenantHarness::owner()->table('ledger_entries')->where('journal_entry_id', $id)->count())->toBe(0);
});

it('refuses posting when no fiscal period covers the date (422 CLOSED_PERIOD)', function (): void {
    $co = TenantHarness::seedCompany('No Year Co');
    peFiscalYear($co['company_id']);                        // covers 2026 only
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '10.0000', '0'], [$b, '0', '10.0000']], 'draft', '2030-03-01');

    expect(fn () => pePost($co['company_id'], $id))->toThrow(ClosedPeriodException::class);
});

it('refuses a line on an inactive account (422 ACCOUNT_INACTIVE) and writes no ledger row', function (): void {
    $co = TenantHarness::seedCompany('Inactive Acct Co');
    peFiscalYear($co['company_id']);
    $active = peAccount($co['company_id']);
    $inactive = peAccount($co['company_id'], 'inactive');
    $id = peDraft($co['company_id'], [[$active, '10.0000', '0'], [$inactive, '0', '10.0000']]);

    expect(fn () => pePost($co['company_id'], $id))->toThrow(PostingRuleException::class);
    expect(TenantHarness::owner()->table('ledger_entries')->where('journal_entry_id', $id)->count())->toBe(0);
});

it('refuses a line on a header account (422 ACCOUNT_NOT_POSTABLE) and writes no ledger row', function (): void {
    $c = TenantHarness::seedCompany('Header Post Co');
    peFiscalYear($c['company_id']);

    // A parent becomes a header the moment it gains a child — the database sees to that.
    $header = peAccount($c['company_id']);
    $child = peAccount($c['company_id']);
    TenantHarness::owner()->statement('UPDATE accounts SET parent_id = ? WHERE id = ?', [$header, $child]);

    $other = peAccount($c['company_id']);
    $entryId = peDraft($c['company_id'], [[$header, '10.0000', '0'], [$other, '0', '10.0000']]);

    TenantHarness::runInTenant($c['company_id'], function () use ($entryId) {
        try {
            app(PostJournalEntryAction::class)->execute(JournalEntry::query()->findOrFail($entryId));
            expect(false)->toBeTrue('posting to a header account should have been refused');
        } catch (PostingRuleException $e) {
            expect($e->errorCode())->toBe('ACCOUNT_NOT_POSTABLE');
            expect($e->errorStatus())->toBe(422);
        }
    });

    $count = TenantHarness::owner()->scalar(
        'SELECT COUNT(*) FROM ledger_entries WHERE journal_entry_id = ?',
        [$entryId],
    );
    expect((int) $count)->toBe(0);
});

it('refuses posting an entry with no lines (422 CANNOT_POST_EMPTY)', function (): void {
    $co = TenantHarness::seedCompany('Empty Co');
    peFiscalYear($co['company_id']);
    $id = peDraft($co['company_id'], []);

    expect(fn () => pePost($co['company_id'], $id))->toThrow(PostingRuleException::class);
});

// ---------------------------------------------------------------- idempotency & immutability

it('refuses to post the same entry twice (409) and never double-projects the ledger', function (): void {
    $co = TenantHarness::seedCompany('Idempotent Co');
    peFiscalYear($co['company_id']);
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '40.0000', '0'], [$b, '0', '40.0000']]);

    // Both posts and the assertions run in ONE tenant transaction, so the first post's writes are still
    // visible to the second (runInTenant rolls the whole thing back at the end).
    [$thrown, $ledgerRows, $number] = TenantHarness::runInTenant($co['company_id'], function () use ($id): array {
        $first = pePostHere($id);

        $thrown = null;
        try {
            pePostHere($id);
        } catch (PostingRuleException $e) {
            $thrown = $e;
        }

        return [$thrown, LedgerEntry::query()->where('journal_entry_id', $id)->count(), $first->journal_number];
    });

    expect($thrown)->toBeInstanceOf(PostingRuleException::class);
    expect($thrown->errorCode())->toBe('JOURNAL_NOT_POSTABLE');
    expect($thrown->errorStatus())->toBe(409);
    // Still exactly one projection per line, and the number was allocated exactly once.
    expect($ledgerRows)->toBe(2);
    expect($number)->toBe('JE-FY2026-000001');
});

it('refuses to post an entry that is pending approval (409 JOURNAL_NOT_POSTABLE)', function (): void {
    $co = TenantHarness::seedCompany('Pending Co');
    peFiscalYear($co['company_id']);
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '10.0000', '0'], [$b, '0', '10.0000']], 'pending_approval');

    expect(fn () => pePost($co['company_id'], $id))->toThrow(PostingRuleException::class);
});

it('posts an approved entry (the post-approval path)', function (): void {
    $co = TenantHarness::seedCompany('Approved Co');
    peFiscalYear($co['company_id']);
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);
    $id = peDraft($co['company_id'], [[$a, '77.0000', '0'], [$b, '0', '77.0000']], 'approved');

    expect(pePost($co['company_id'], $id)->status)->toBe('posted');
});

it('keeps the ledger append-only: an UPDATE or DELETE of a ledger row is rejected by the database', function (): void {
    $co = TenantHarness::seedCompany('Append Only Co');
    $seeded = peSeedPostedLedgerRow($co['company_id']);

    $owner = TenantHarness::owner();

    // Even the schema owner (which bypasses RLS) cannot mutate posted ledger history — the trigger is
    // independent of the application layer. Each statement runs in its own implicit transaction.
    $threwUpdate = false;
    try {
        $owner->update('UPDATE ledger_entries SET signed_base_amount = 0 WHERE id = ?', [$seeded['ledger_id']]);
    } catch (Throwable $e) {
        $threwUpdate = true;
    }

    $threwDelete = false;
    try {
        $owner->delete('DELETE FROM ledger_entries WHERE id = ?', [$seeded['ledger_id']]);
    } catch (Throwable $e) {
        $threwDelete = true;
    }

    expect($threwUpdate)->toBeTrue('UPDATE on ledger_entries must be rejected by the append-only trigger');
    expect($threwDelete)->toBeTrue('DELETE on ledger_entries must be rejected by the append-only trigger');

    // The row is still there, unchanged.
    $row = $owner->table('ledger_entries')->where('id', $seeded['ledger_id'])->first();
    expect($row->signed_base_amount)->toBe('30.0000');
});

// ---------------------------------------------------------------- numbering & isolation

it('allocates permanent numbers monotonically per company/year/type', function (): void {
    $co = TenantHarness::seedCompany('Numbering Co');
    peFiscalYear($co['company_id']);
    $a = peAccount($co['company_id']);
    $b = peAccount($co['company_id']);

    $first = peDraft($co['company_id'], [[$a, '1.0000', '0'], [$b, '0', '1.0000']]);
    $second = peDraft($co['company_id'], [[$a, '2.0000', '0'], [$b, '0', '2.0000']]);

    // Both posts share one tenant transaction so the second sees the first's sequence increment.
    $numbers = TenantHarness::runInTenant($co['company_id'], fn (): array => [
        pePostHere($first)->journal_number,
        pePostHere($second)->journal_number,
    ]);

    expect($numbers[0])->toBe('JE-FY2026-000001');
    expect($numbers[1])->toBe('JE-FY2026-000002');
});

it('numbers per tenant: two companies each start their own sequence at 1', function (): void {
    $one = TenantHarness::seedCompany('Tenant One');
    $two = TenantHarness::seedCompany('Tenant Two');
    peFiscalYear($one['company_id']);
    peFiscalYear($two['company_id']);

    $a1 = peAccount($one['company_id']);
    $b1 = peAccount($one['company_id']);
    $a2 = peAccount($two['company_id']);
    $b2 = peAccount($two['company_id']);

    $e1 = peDraft($one['company_id'], [[$a1, '5.0000', '0'], [$b1, '0', '5.0000']]);
    $e2 = peDraft($two['company_id'], [[$a2, '6.0000', '0'], [$b2, '0', '6.0000']]);

    // Sequences are keyed by company — one tenant's postings never advance another's numbering.
    expect(pePost($one['company_id'], $e1)->journal_number)->toBe('JE-FY2026-000001');
    expect(pePost($two['company_id'], $e2)->journal_number)->toBe('JE-FY2026-000001');
});

it('keeps the ledger tenant-isolated: a company never reads another company\'s ledger rows', function (): void {
    $one = TenantHarness::seedCompany('Ledger Tenant One');
    $two = TenantHarness::seedCompany('Ledger Tenant Two');

    // Both rows are committed on the owner connection, so both exist for real; RLS decides visibility.
    $mine = peSeedPostedLedgerRow($one['company_id']);
    $theirs = peSeedPostedLedgerRow($two['company_id']);

    $visible = TenantHarness::runInTenant(
        $one['company_id'],
        fn (): array => LedgerEntry::query()->pluck('journal_entry_id')->map(fn ($v): int => (int) $v)->all(),
    );

    expect($visible)->toContain($mine['entry_id']);
    expect($visible)->not->toContain($theirs['entry_id']);
});
