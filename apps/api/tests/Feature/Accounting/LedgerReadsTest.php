<?php

declare(strict_types=1);

use App\Domain\Accounting\LedgerActivityQuery;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Models\User;
use App\Services\Accounting\LedgerQueryService;
use App\Support\Cursor;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * S2-08 — general ledger reads, against real PostgreSQL.
 *
 * The report's correctness IS the running balance, so most of these tests assert exact money strings
 * rather than shapes. The two that matter most are the backdated-line sweep (a cursor keyed on `id`
 * alone would silently drop that row) and the unscoped-query isolation test (which proves the tenant
 * boundary belongs to the database, not to a `where` clause).
 *
 * Ledger rows are seeded on the OWNER connection so they are committed and therefore visible to reads
 * inside `runInTenant`, whose own transaction is rolled back.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/** An open FY2026 for the company, with its twelve monthly periods. */
function lgYear(int $companyId): int
{
    $yearId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2026-01-01', '2026-12-31', 'open') RETURNING id",
        [$companyId, 'FY-'.bin2hex(random_bytes(4))],
    )->id;

    MonthlyFiscalPeriodGenerator::generate(
        TenantHarness::owner(), $companyId, $yearId, '2026-01-01', '2026-12-31', 'open',
    );

    return $yearId;
}

function lgAccount(int $companyId, string $name = 'Cash'): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, ?, 'حساب', 'debit', 'active') RETURNING id",
        [$companyId, $typeId, 'L'.bin2hex(random_bytes(6)), $name],
    )->id;
}

/** The company's contra account — every fixture entry balances against it. */
function lgContra(int $companyId): int
{
    static $cache = [];

    if (! isset($cache[$companyId])) {
        $cache[$companyId] = lgAccount($companyId, 'Contra');
    }

    return $cache[$companyId];
}

/**
 * A committed posted ENTRY: a balanced header, its two legs, and the two `ledger_entries` rows the
 * posting engine would project, with `signed_base_amount = base_debit - base_credit`.
 *
 * It has to be a balanced pair, not a single leg: `chk_je_balanced` is unconditional, so a one-sided
 * header is rejected by the database — which is the S2-03 invariant doing its job even in a fixture.
 * The mirror leg lands on a separate contra account, so it never appears in the activity of the
 * account under test.
 *
 * Seeded directly rather than posted through the engine because this story tests the READ side; the
 * write path that produces these rows is already proven by the S2-05 and S2-07 suites.
 */
function lgLine(
    int $companyId,
    int $yearId,
    int $accountId,
    string $date,
    string $debit,
    string $credit,
    string $entryType = 'manual',
    ?string $description = null,
): int {
    $owner = TenantHarness::owner();
    $contraId = lgContra($companyId);
    $amount = $debit !== '0' ? $debit : $credit;

    $periodId = (int) $owner->selectOne(
        'SELECT id FROM fiscal_periods WHERE company_id = ? AND ?::date BETWEEN start_date AND end_date',
        [$companyId, $date],
    )->id;

    $entryId = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code,
                                      status, fiscal_year_id, fiscal_period_id, posted_at, locked,
                                      total_debit, total_credit, base_total_debit, base_total_credit)
         VALUES (?, ?, ?, ?::journal_entry_type, 'KWD', 'posted', ?, ?, now(), true, ?, ?, ?, ?) RETURNING id",
        [$companyId, 'JE-'.bin2hex(random_bytes(8)), $date, $entryType, $yearId, $periodId,
            $amount, $amount, $amount, $amount],
    )->id;

    $ledgerId = 0;
    $legs = [
        [$accountId, $debit, $credit],
        [$contraId, $credit, $debit],   // the mirror leg, on a different account
    ];

    foreach ($legs as $index => [$legAccountId, $legDebit, $legCredit]) {
        $lineId = (int) $owner->selectOne(
            "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit,
                                        currency_code, base_debit, base_credit, description)
             VALUES (?, ?, ?, ?, ?, ?, 'KWD', ?, ?, ?) RETURNING id",
            [$companyId, $entryId, $index + 1, $legAccountId, $legDebit, $legCredit,
                $legDebit, $legCredit, $description],
        )->id;

        $id = (int) $owner->selectOne(
            "INSERT INTO ledger_entries (company_id, journal_entry_id, journal_line_id, account_id, fiscal_year_id,
                                         fiscal_period_id, entry_date, posted_at, entry_type, currency_code,
                                         debit_amount, credit_amount, base_debit_amount, base_credit_amount,
                                         signed_base_amount, description, reference)
             VALUES (?, ?, ?, ?, ?, ?, ?, now(), ?::journal_entry_type, 'KWD', ?, ?, ?, ?,
                     ?::numeric - ?::numeric, ?, ?)
             RETURNING id",
            [$companyId, $entryId, $lineId, $legAccountId, $yearId, $periodId, $date, $entryType,
                $legDebit, $legCredit, $legDebit, $legCredit, $legDebit, $legCredit,
                $description, 'REF-'.$date],
        )->id;

        if ($index === 0) {
            $ledgerId = $id;
        }
    }

    return $ledgerId;
}

/**
 * The four-line fixture every balance assertion below is written against.
 *
 * @return array{year_id: int, account_id: int}
 */
function lgFixture(int $companyId): array
{
    $yearId = lgYear($companyId);
    $accountId = lgAccount($companyId);

    lgLine($companyId, $yearId, $accountId, '2026-01-10', '100.0000', '0');
    lgLine($companyId, $yearId, $accountId, '2026-02-10', '0', '40.0000');
    lgLine($companyId, $yearId, $accountId, '2026-03-10', '25.0000', '0');
    lgLine($companyId, $yearId, $accountId, '2026-04-10', '0', '10.0000');

    return ['year_id' => $yearId, 'account_id' => $accountId];
}

// ---------------------------------------------------------------- running balance

it('returns posted lines in date order with a running balance derived from signed_base_amount', function (): void {
    $c = TenantHarness::seedCompany('Ledger Co');
    $f = lgFixture($c['company_id']);

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(accountId: $f['account_id'])));

    expect($page->rows)->toHaveCount(4);
    expect(array_map(fn ($r) => $r->entryDate, $page->rows))
        ->toBe(['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10']);
    expect(array_map(fn ($r) => $r->signedBaseAmount, $page->rows))
        ->toBe(['100.0000', '-40.0000', '25.0000', '-10.0000']);
    expect(array_map(fn ($r) => $r->runningBalance, $page->rows))
        ->toBe(['100.0000', '60.0000', '85.0000', '75.0000']);

    expect($page->openingBalance)->toBe('0.0000');
    expect($page->closingBalance)->toBe('75.0000');
    expect($page->nextCursor)->toBeNull();
});

it('seeds the running balance with the balance forward when a date range starts mid-history', function (): void {
    $c = TenantHarness::seedCompany('Forward Co');
    $f = lgFixture($c['company_id']);

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(accountId: $f['account_id'], from: '2026-03-01')));

    // Everything before 1 March: +100 − 40 = 60.
    expect($page->openingBalance)->toBe('60.0000');
    expect($page->rows)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->runningBalance, $page->rows))->toBe(['85.0000', '75.0000']);
    expect($page->closingBalance)->toBe('75.0000');
});

it('honours the to-date bound and closes at the last line inside the range', function (): void {
    $c = TenantHarness::seedCompany('Bounded Co');
    $f = lgFixture($c['company_id']);

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(
            accountId: $f['account_id'], from: '2026-01-01', to: '2026-02-28',
        )));

    expect($page->rows)->toHaveCount(2);
    expect($page->openingBalance)->toBe('0.0000');
    expect($page->closingBalance)->toBe('60.0000');
});

it('keeps opening + sum(lines) = closing, so the report reconciles to itself', function (): void {
    $c = TenantHarness::seedCompany('Reconcile Co');
    $f = lgFixture($c['company_id']);

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(accountId: $f['account_id'], from: '2026-02-01')));

    $sum = array_reduce(
        $page->rows,
        fn (string $carry, $row): string => bcadd($carry, $row->signedBaseAmount, 4),
        '0.0000',
    );

    expect(bcadd($page->openingBalance, $sum, 4))->toBe($page->closingBalance);
});

it('returns an empty page with a zero balance for an account that has never been posted to', function (): void {
    $c = TenantHarness::seedCompany('Quiet Co');
    lgYear($c['company_id']);
    $accountId = lgAccount($c['company_id'], 'Unused');

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(accountId: $accountId)));

    expect($page->rows)->toBe([]);
    expect($page->openingBalance)->toBe('0.0000');
    expect($page->closingBalance)->toBe('0.0000');
    expect($page->nextCursor)->toBeNull();
});

it('filters by entry type, and moves the balance forward with it so the page still reconciles', function (): void {
    $c = TenantHarness::seedCompany('Typed Co');
    $yearId = lgYear($c['company_id']);
    $accountId = lgAccount($c['company_id']);

    lgLine($c['company_id'], $yearId, $accountId, '2026-01-10', '100.0000', '0', 'manual');
    lgLine($c['company_id'], $yearId, $accountId, '2026-02-10', '50.0000', '0', 'adjustment');
    lgLine($c['company_id'], $yearId, $accountId, '2026-03-10', '25.0000', '0', 'manual');

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(
            accountId: $accountId, from: '2026-02-01', entryType: 'manual',
        )));

    expect($page->rows)->toHaveCount(1);
    // The 50.00 adjustment is excluded from the lines AND from the balance forward, so the two agree.
    expect($page->openingBalance)->toBe('100.0000');
    expect($page->closingBalance)->toBe('125.0000');
});

// ---------------------------------------------------------------- pagination

it('carries the running balance across a cursor-paginated sweep', function (): void {
    $c = TenantHarness::seedCompany('Sweep Co');
    $f = lgFixture($c['company_id']);

    [$first, $second] = TenantHarness::runInTenant($c['company_id'], function () use ($f) {
        $service = app(LedgerQueryService::class);
        $first = $service->accountActivity(new LedgerActivityQuery(accountId: $f['account_id'], perPage: 2));

        $pointer = Cursor::decode($first->nextCursor);
        $second = $service->accountActivity(new LedgerActivityQuery(
            accountId: $f['account_id'],
            afterDate: (string) $pointer['d'],
            afterId: (int) $pointer['i'],
            perPage: 2,
        ));

        return [$first, $second];
    });

    expect($first->rows)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->runningBalance, $first->rows))->toBe(['100.0000', '60.0000']);
    expect($first->nextCursor)->not->toBeNull();

    // The second page continues the balance rather than restarting at the opening balance.
    expect($second->rows)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->runningBalance, $second->rows))->toBe(['85.0000', '75.0000']);
    expect($second->openingBalance)->toBe('0.0000');
    expect($second->closingBalance)->toBe('75.0000');
    expect($second->nextCursor)->toBeNull();
});

it('never drops a backdated line from a paginated sweep (the keyset cursor is (entry_date, id))', function (): void {
    $c = TenantHarness::seedCompany('Backdated Co');
    $f = lgFixture($c['company_id']);

    // Posted last, dated first: it sorts to the very front while carrying the highest id. A cursor
    // keyed on `id` alone would treat it as "already seen" and skip it.
    lgLine($c['company_id'], $f['year_id'], $f['account_id'], '2026-01-05', '7.0000', '0');

    $dates = TenantHarness::runInTenant($c['company_id'], function () use ($f) {
        $service = app(LedgerQueryService::class);
        $seen = [];
        $afterDate = null;
        $afterId = null;

        do {
            $page = $service->accountActivity(new LedgerActivityQuery(
                accountId: $f['account_id'], afterDate: $afterDate, afterId: $afterId, perPage: 2,
            ));

            foreach ($page->rows as $row) {
                $seen[] = $row->entryDate;
            }

            $pointer = Cursor::decode($page->nextCursor);
            $afterDate = $pointer === null ? null : (string) $pointer['d'];
            $afterId = $pointer === null ? null : (int) $pointer['i'];
        } while ($pointer !== null);

        return $seen;
    });

    expect($dates)->toBe(['2026-01-05', '2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10']);
});

it('reports a null next cursor on the last page and never counts the table', function (): void {
    $c = TenantHarness::seedCompany('Terminal Co');
    $f = lgFixture($c['company_id']);

    $page = TenantHarness::runInTenant($c['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(accountId: $f['account_id'], perPage: 4)));

    expect($page->rows)->toHaveCount(4);
    expect($page->nextCursor)->toBeNull();
});

it('treats a forged or corrupt cursor as no cursor rather than an error', function (): void {
    expect(Cursor::decode('not-base64-!!'))->toBeNull();
    expect(Cursor::decode(base64_encode('{"not":')))->toBeNull();
    expect(Cursor::decode(null))->toBeNull();
    expect(Cursor::decode(Cursor::encode(['d' => '2026-01-10', 'i' => 7])))
        ->toBe(['d' => '2026-01-10', 'i' => 7]);
});

// ---------------------------------------------------------------- tenant isolation

it('keeps the ledger tenant-isolated even for a query that scopes nothing itself', function (): void {
    $a = TenantHarness::seedCompany('Ledger A');
    $b = TenantHarness::seedCompany('Ledger B');
    lgFixture($a['company_id']);
    lgFixture($b['company_id']);

    // Deliberately unscoped: no company_id, no account_id. Only RLS stands between this and B's rows.
    $visible = TenantHarness::runInTenant($a['company_id'], fn () => TenantHarness::app()
        ->scalar('SELECT COUNT(*) FROM ledger_entries'));

    $foreign = TenantHarness::runInTenant($a['company_id'], fn () => TenantHarness::app()
        ->scalar('SELECT COUNT(*) FROM ledger_entries WHERE company_id = ?', [$b['company_id']]));

    // Four entries × two legs each — all of company A's, none of company B's.
    expect((int) $visible)->toBe(8);
    expect((int) $foreign)->toBe(0);
});

it('returns no activity for another company account id, rather than that company data', function (): void {
    $a = TenantHarness::seedCompany('Reader A');
    $b = TenantHarness::seedCompany('Owner B');
    lgFixture($a['company_id']);
    $bFixture = lgFixture($b['company_id']);

    $page = TenantHarness::runInTenant($a['company_id'], fn () => app(LedgerQueryService::class)
        ->accountActivity(new LedgerActivityQuery(accountId: $bFixture['account_id'])));

    expect($page->rows)->toBe([]);
    expect($page->closingBalance)->toBe('0.0000');
});

// ---------------------------------------------------------------- HTTP surface

/**
 * @param  list<string>  $permissions
 * @return array{user: User, uuid: string, company_id: int}
 */
function lgMember(array $permissions): array
{
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Ledger '.uniqid(), 'ledger_role');

    foreach ($permissions as $key) {
        RbacFixtures::attachToRole($m['role_id'], RbacFixtures::permission($key, 'accounting'));
    }

    return ['user' => $user, 'uuid' => $m['company_uuid'], 'company_id' => (int) $m['company_id']];
}

it('serves account activity over HTTP with the standard cursor-pagination envelope', function (): void {
    $m = lgMember(['accounting.journal.read']);
    $f = lgFixture($m['company_id']);

    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/ledger/accounts/{$f['account_id']}/activity", ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.opening_balance', '0.0000')
        ->assertJsonPath('data.closing_balance', '75.0000')
        ->assertJsonPath('data.lines.0.running_balance', '100.0000')
        ->assertJsonPath('data.lines.3.running_balance', '75.0000')
        ->assertJsonPath('meta.pagination.page', null)      // cursor style: no page number
        ->assertJsonPath('meta.pagination.total', null)     // and no count of the table
        ->assertJsonPath('meta.pagination.per_page', 50)
        ->assertJsonPath('meta.pagination.cursor', null);
});

it('clamps an oversized per_page to the ledger ceiling instead of rejecting it', function (): void {
    $m = lgMember(['accounting.journal.read']);
    $f = lgFixture($m['company_id']);

    $this->actingAs($m['user'], 'web')
        ->getJson(
            "/api/v1/accounting/ledger/accounts/{$f['account_id']}/activity?per_page=5000",
            ['X-Company-Id' => $m['uuid']],
        )
        ->assertOk()
        ->assertJsonPath('meta.pagination.per_page', LedgerActivityQuery::MAX_PER_PAGE);
});

it('rejects a malformed date filter with 422', function (): void {
    $m = lgMember(['accounting.journal.read']);
    $f = lgFixture($m['company_id']);

    $this->actingAs($m['user'], 'web')
        ->getJson(
            "/api/v1/accounting/ledger/accounts/{$f['account_id']}/activity?from=01-01-2026",
            ['X-Company-Id' => $m['uuid']],
        )
        ->assertStatus(422);
});

it('denies ledger reads without accounting.journal.read (403)', function (): void {
    $m = lgMember([]);
    $f = lgFixture($m['company_id']);

    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/ledger/accounts/{$f['account_id']}/activity", ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('requires authentication for ledger reads', function (): void {
    $this->getJson('/api/v1/accounting/ledger/accounts/1/activity', ['X-Company-Id' => 'anything'])
        ->assertStatus(401);
});

it('returns 404, never another company data, for a cross-tenant account id', function (): void {
    $m = lgMember(['accounting.journal.read']);
    lgFixture($m['company_id']);

    $other = TenantHarness::seedCompany('Outsider');
    $otherFixture = lgFixture($other['company_id']);

    $this->actingAs($m['user'], 'web')
        ->getJson(
            "/api/v1/accounting/ledger/accounts/{$otherFixture['account_id']}/activity",
            ['X-Company-Id' => $m['uuid']],
        )
        ->assertStatus(404);
});
