<?php

declare(strict_types=1);

use App\Actions\Accounting\ApproveTrialBalanceSnapshotAction;
use App\Actions\Accounting\GenerateTrialBalanceSnapshotAction;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Exceptions\Accounting\TrialBalanceRuleException;
use App\Jobs\Accounting\GenerateTrialBalanceSnapshotJob;
use App\Models\TrialBalanceSnapshot;
use App\Models\User;
use App\Services\Accounting\TrialBalanceService;
use App\Support\TenantContext;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * S2-09 — trial balance compute, snapshot, and approval, against real PostgreSQL.
 *
 * The central assertion is that the trial balance PROVES: total debits equal total credits for any
 * posted set, because every entry balances and `signed_base_amount` sums to zero. Everything else here
 * defends that proof — that a snapshot freezes it, that an approved snapshot cannot be edited, that an
 * out-of-balance run can never be signed, and that a queued generation runs inside its own tenant
 * context rather than inheriting one.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/** Grant permission keys to the harness role. */
function tbGrant(int $roleId, string ...$keys): void
{
    $owner = TenantHarness::owner();

    foreach ($keys as $key) {
        $owner->statement(
            "INSERT INTO permissions (key, area) VALUES (?, 'accounting') ON CONFLICT (key) DO NOTHING",
            [$key],
        );
        $owner->statement(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT ?, id FROM permissions WHERE key = ? ON CONFLICT DO NOTHING',
            [$roleId, $key],
        );
    }
}

function tbYear(int $companyId): int
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

function tbPeriodOn(int $companyId, string $date): int
{
    return (int) TenantHarness::owner()->selectOne(
        'SELECT id FROM fiscal_periods WHERE company_id = ? AND ?::date BETWEEN start_date AND end_date',
        [$companyId, $date],
    )->id;
}

function tbAccount(int $companyId, string $typeKey = 'asset', string $normal = 'debit', ?string $code = null): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', $typeKey)->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, 'Acc', 'حساب', ?, 'active') RETURNING id",
        [$companyId, $typeId, $code ?? 'T'.bin2hex(random_bytes(6)), $normal],
    )->id;
}

/**
 * A committed balanced posted entry: one debit leg on `$debitAccount`, one credit leg on
 * `$creditAccount`, and the two ledger rows the posting engine would project.
 *
 * `$onlyFirstLeg` deliberately projects a HALF entry — the header still balances, so the database
 * accepts it, but the ledger no longer sums to zero. That is the only honest way to manufacture the
 * out-of-balance condition the trial balance exists to detect.
 */
function tbEntry(
    int $companyId,
    int $yearId,
    int $debitAccount,
    int $creditAccount,
    string $date,
    string $amount,
    bool $onlyFirstLeg = false,
): void {
    $owner = TenantHarness::owner();
    $periodId = tbPeriodOn($companyId, $date);

    $entryId = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code,
                                      status, fiscal_year_id, fiscal_period_id, posted_at, locked,
                                      total_debit, total_credit, base_total_debit, base_total_credit)
         VALUES (?, ?, ?, 'manual', 'KWD', 'posted', ?, ?, now(), true, ?, ?, ?, ?) RETURNING id",
        [$companyId, 'JE-'.bin2hex(random_bytes(8)), $date, $yearId, $periodId, $amount, $amount, $amount, $amount],
    )->id;

    $legs = [[$debitAccount, $amount, '0'], [$creditAccount, '0', $amount]];

    foreach ($legs as $index => [$accountId, $debit, $credit]) {
        $lineId = (int) $owner->selectOne(
            "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit,
                                        currency_code, base_debit, base_credit)
             VALUES (?, ?, ?, ?, ?, ?, 'KWD', ?, ?) RETURNING id",
            [$companyId, $entryId, $index + 1, $accountId, $debit, $credit, $debit, $credit],
        )->id;

        if ($onlyFirstLeg && $index === 1) {
            continue;
        }

        $owner->statement(
            "INSERT INTO ledger_entries (company_id, journal_entry_id, journal_line_id, account_id, fiscal_year_id,
                                         fiscal_period_id, entry_date, posted_at, entry_type, currency_code,
                                         debit_amount, credit_amount, base_debit_amount, base_credit_amount,
                                         signed_base_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, now(), 'manual', 'KWD', ?, ?, ?, ?, ?::numeric - ?::numeric)",
            [$companyId, $entryId, $lineId, $accountId, $yearId, $periodId, $date,
                $debit, $credit, $debit, $credit, $debit, $credit],
        );
    }
}

/**
 * Cash (asset/debit) and Revenue (revenue/credit), with 100 in January and 40 in February.
 *
 * @return array{year_id: int, cash: int, revenue: int}
 */
function tbFixture(int $companyId): array
{
    $yearId = tbYear($companyId);
    $cash = tbAccount($companyId, 'asset', 'debit', '1000-'.bin2hex(random_bytes(3)));
    $revenue = tbAccount($companyId, 'revenue', 'credit', '4000-'.bin2hex(random_bytes(3)));

    tbEntry($companyId, $yearId, $cash, $revenue, '2026-01-10', '100.0000');
    tbEntry($companyId, $yearId, $cash, $revenue, '2026-02-10', '40.0000');

    return ['year_id' => $yearId, 'cash' => $cash, 'revenue' => $revenue];
}

// ---------------------------------------------------------------- the proof

it('proves out: total debits equal total credits for any posted set', function (): void {
    $c = TenantHarness::seedCompany('Proof Co');
    tbFixture($c['company_id']);
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    $balance = TenantHarness::runInTenant($c['company_id'], fn () => app(TrialBalanceService::class)
        ->compute($february));

    expect($balance->totalDebit)->toBe('140.0000');
    expect($balance->totalCredit)->toBe('140.0000');
    expect($balance->variance)->toBe('0.0000');
    expect($balance->isBalanced())->toBeTrue();
    expect($balance->rows)->toHaveCount(2);
});

it('splits opening, period movement and closing across the period boundary', function (): void {
    $c = TenantHarness::seedCompany('Split Co');
    $f = tbFixture($c['company_id']);
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    $balance = TenantHarness::runInTenant($c['company_id'], fn () => app(TrialBalanceService::class)
        ->compute($february));

    $cash = collect($balance->rows)->firstWhere('accountId', $f['cash']);
    $revenue = collect($balance->rows)->firstWhere('accountId', $f['revenue']);

    // Cash: 100 brought forward from January, 40 moved in February, 140 carried out.
    expect($cash?->openingDebit)->toBe('100.0000');
    expect($cash?->periodDebit)->toBe('40.0000');
    expect($cash?->closingDebit)->toBe('140.0000');
    expect($cash?->closingCredit)->toBe('0.0000');

    // Revenue is the mirror, on the credit side.
    expect($revenue?->openingCredit)->toBe('100.0000');
    expect($revenue?->periodCredit)->toBe('40.0000');
    expect($revenue?->closingCredit)->toBe('140.0000');
    expect($revenue?->isAbnormalBalance)->toBeFalse();
});

it('flags an account sitting on the wrong side of its normal balance', function (): void {
    $c = TenantHarness::seedCompany('Abnormal Co');
    $yearId = tbYear($c['company_id']);
    $cash = tbAccount($c['company_id'], 'asset', 'debit');
    $payable = tbAccount($c['company_id'], 'liability', 'credit');

    // Pay out more than was ever received: the asset account goes into credit.
    tbEntry($c['company_id'], $yearId, $payable, $cash, '2026-01-10', '50.0000');
    $january = tbPeriodOn($c['company_id'], '2026-01-10');

    $balance = TenantHarness::runInTenant($c['company_id'], fn () => app(TrialBalanceService::class)
        ->compute($january));

    $cashRow = collect($balance->rows)->firstWhere('accountId', $cash);
    expect($cashRow?->closingCredit)->toBe('50.0000');
    expect($cashRow?->isAbnormalBalance)->toBeTrue();
    // It still proves out — an abnormal balance is a warning, not an imbalance.
    expect($balance->variance)->toBe('0.0000');
});

it('refuses a fiscal period that is not this company (422 UNKNOWN_FISCAL_PERIOD)', function (): void {
    $a = TenantHarness::seedCompany('Reader A');
    $b = TenantHarness::seedCompany('Owner B');
    tbFixture($b['company_id']);
    $foreignPeriod = tbPeriodOn($b['company_id'], '2026-02-15');

    TenantHarness::runInTenant($a['company_id'], function () use ($foreignPeriod) {
        try {
            app(TrialBalanceService::class)->compute($foreignPeriod);
            expect(false)->toBeTrue('a foreign period id should not resolve');
        } catch (TrialBalanceRuleException $e) {
            expect($e->errorCode())->toBe('UNKNOWN_FISCAL_PERIOD');
            expect($e->errorStatus())->toBe(422);
        }
    });
});

it('never mixes another company ledger into a trial balance', function (): void {
    $a = TenantHarness::seedCompany('TB A');
    $b = TenantHarness::seedCompany('TB B');
    tbFixture($a['company_id']);
    tbFixture($b['company_id']);
    $february = tbPeriodOn($a['company_id'], '2026-02-15');

    $balance = TenantHarness::runInTenant($a['company_id'], fn () => app(TrialBalanceService::class)
        ->compute($february));

    // Two accounts, not four: B's identically-shaped ledger is invisible.
    expect($balance->rows)->toHaveCount(2);
    expect($balance->totalDebit)->toBe('140.0000');
});

// ---------------------------------------------------------------- snapshots

it('freezes a snapshot with one line per account and a content hash', function (): void {
    $c = TenantHarness::seedCompany('Snap Co');
    tbFixture($c['company_id']);
    tbGrant($c['role_id'], 'accounting.trial_balance.generate');
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $february, $c['user_id']);

        expect($snapshot->status)->toBe(TrialBalanceSnapshot::STATUS_GENERATED);
        expect($snapshot->total_debit)->toBe('140.0000');
        expect($snapshot->total_credit)->toBe('140.0000');
        expect($snapshot->variance)->toBe('0.0000');
        expect($snapshot->line_count)->toBe(2);
        expect($snapshot->version)->toBe(1);
        expect($snapshot->is_current)->toBeTrue();
        expect($snapshot->content_hash)->not->toBeNull();

        $lines = TenantHarness::app()->scalar(
            'SELECT COUNT(*) FROM trial_balance_snapshot_lines WHERE snapshot_id = ?',
            [$snapshot->id],
        );
        expect((int) $lines)->toBe(2);
    });
});

it('supersedes the previous snapshot with a new version instead of mutating it', function (): void {
    $c = TenantHarness::seedCompany('Version Co');
    tbFixture($c['company_id']);
    tbGrant($c['role_id'], 'accounting.trial_balance.generate');
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        $action = app(GenerateTrialBalanceSnapshotAction::class);

        $first = $action->execute($c['company_id'], $february, $c['user_id']);
        $second = $action->execute($c['company_id'], $february, $c['user_id']);

        expect($second->version)->toBe(2);
        expect($second->parent_snapshot_id)->toBe($first->id);
        expect($second->is_current)->toBeTrue();

        // The superseded run keeps its figures and only loses the current flag.
        $old = TrialBalanceSnapshot::query()->findOrFail($first->id);
        expect($old->is_current)->toBeFalse();
        expect($old->total_debit)->toBe('140.0000');
        expect($old->status)->toBe(TrialBalanceSnapshot::STATUS_GENERATED);
    });
});

it('records an out-of-balance ledger honestly rather than balancing it', function (): void {
    $c = TenantHarness::seedCompany('Broken Co');
    $yearId = tbYear($c['company_id']);
    $cash = tbAccount($c['company_id'], 'asset', 'debit');
    $revenue = tbAccount($c['company_id'], 'revenue', 'credit');
    tbGrant($c['role_id'], 'accounting.trial_balance.generate');

    // A half-projected entry: the header balances, the ledger does not.
    tbEntry($c['company_id'], $yearId, $cash, $revenue, '2026-01-10', '75.0000', onlyFirstLeg: true);
    $january = tbPeriodOn($c['company_id'], '2026-01-10');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $january) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $january, $c['user_id']);

        expect($snapshot->status)->toBe(TrialBalanceSnapshot::STATUS_OUT_OF_BALANCE);
        expect($snapshot->variance)->toBe('75.0000');
    });
});

it('lets the database reject an update to a snapshot line', function (): void {
    $c = TenantHarness::seedCompany('Append Co');
    $owner = TenantHarness::owner();

    $snapshotId = (int) $owner->selectOne(
        "INSERT INTO trial_balance_snapshots (company_id, as_of_date, period_start_date, type, status, currency_code)
         VALUES (?, '2026-02-28', '2026-02-01', 'unadjusted', 'generated', 'KWD') RETURNING id",
        [$c['company_id']],
    )->id;

    $typeId = (int) $owner->table('account_types')->where('key', 'asset')->value('id');
    $accountId = tbAccount($c['company_id']);

    $lineId = (int) $owner->selectOne(
        "INSERT INTO trial_balance_snapshot_lines (snapshot_id, company_id, account_id, account_code,
                                                   account_name_en, account_type_id, normal_balance, closing_debit)
         VALUES (?, ?, ?, '1000', 'Cash', ?, 'debit', '10.0000') RETURNING id",
        [$snapshotId, $c['company_id'], $accountId, $typeId],
    )->id;

    expect(fn () => $owner->statement(
        "UPDATE trial_balance_snapshot_lines SET closing_debit = '999.0000' WHERE id = ?",
        [$lineId],
    ))->toThrow(QueryException::class);
});

it('lets the database freeze an approved snapshot figures', function (): void {
    $c = TenantHarness::seedCompany('Frozen Co');
    $owner = TenantHarness::owner();

    $id = (int) $owner->selectOne(
        "INSERT INTO trial_balance_snapshots (company_id, as_of_date, period_start_date, type, status,
                                              currency_code, total_debit, total_credit, variance)
         VALUES (?, '2026-02-28', '2026-02-01', 'unadjusted', 'approved', 'KWD', '140.0000', '140.0000', 0)
         RETURNING id",
        [$c['company_id']],
    )->id;

    expect(fn () => $owner->statement(
        "UPDATE trial_balance_snapshots SET total_debit = '999.0000', variance = '859.0000' WHERE id = ?",
        [$id],
    ))->toThrow(QueryException::class);

    // Non-figure fields still move — only the numbers and the identity are frozen.
    $owner->statement("UPDATE trial_balance_snapshots SET notes = 'reviewed' WHERE id = ?", [$id]);
    expect((string) $owner->selectOne('SELECT notes FROM trial_balance_snapshots WHERE id = ?', [$id])->notes)
        ->toBe('reviewed');
});

// ---------------------------------------------------------------- approval

it('approves a balanced snapshot, records the signer, and audits it', function (): void {
    $c = TenantHarness::seedCompany('Approve Co');
    tbFixture($c['company_id']);
    tbGrant($c['role_id'], 'accounting.trial_balance.generate', 'accounting.trial_balance.approve');
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $february, $c['user_id']);

        $approved = app(ApproveTrialBalanceSnapshotAction::class)->execute($snapshot, $c['user_id']);

        expect($approved->status)->toBe(TrialBalanceSnapshot::STATUS_APPROVED);
        expect($approved->approved_by)->toBe($c['user_id']);

        $audit = TenantHarness::app()->selectOne(
            "SELECT action FROM audit_logs WHERE entity_type = 'trial_balance_snapshots'
             AND entity_id = ? AND action = 'accounting.trial_balance.approved' LIMIT 1",
            [$snapshot->id],
        );
        expect($audit?->action)->toBe('accounting.trial_balance.approved');
    });
});

it('refuses to approve an out-of-balance trial balance (409)', function (): void {
    $c = TenantHarness::seedCompany('Refuse Co');
    $yearId = tbYear($c['company_id']);
    $cash = tbAccount($c['company_id'], 'asset', 'debit');
    $revenue = tbAccount($c['company_id'], 'revenue', 'credit');
    tbGrant($c['role_id'], 'accounting.trial_balance.generate', 'accounting.trial_balance.approve');

    tbEntry($c['company_id'], $yearId, $cash, $revenue, '2026-01-10', '75.0000', onlyFirstLeg: true);
    $january = tbPeriodOn($c['company_id'], '2026-01-10');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $january) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $january, $c['user_id']);

        try {
            app(ApproveTrialBalanceSnapshotAction::class)->execute($snapshot, $c['user_id']);
            expect(false)->toBeTrue('an out-of-balance trial balance must not be signable');
        } catch (TrialBalanceRuleException $e) {
            expect($e->errorCode())->toBe('TRIAL_BALANCE_OUT_OF_BALANCE');
            expect($e->errorStatus())->toBe(409);
        }
    });
});

it('refuses to approve the same snapshot twice (409 SNAPSHOT_ALREADY_FINAL)', function (): void {
    $c = TenantHarness::seedCompany('Twice Co');
    tbFixture($c['company_id']);
    tbGrant($c['role_id'], 'accounting.trial_balance.generate', 'accounting.trial_balance.approve');
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $february, $c['user_id']);
        $approve = app(ApproveTrialBalanceSnapshotAction::class);
        $approve->execute($snapshot, $c['user_id']);

        try {
            $approve->execute($snapshot->refresh(), $c['user_id']);
            expect(false)->toBeTrue('a signed trial balance must not be re-signed');
        } catch (TrialBalanceRuleException $e) {
            expect($e->errorCode())->toBe('SNAPSHOT_ALREADY_FINAL');
        }
    });
});

it('refuses to approve without accounting.trial_balance.approve (403)', function (): void {
    $c = TenantHarness::seedCompany('NoSign Co');
    tbFixture($c['company_id']);
    tbGrant($c['role_id'], 'accounting.trial_balance.generate');
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $february, $c['user_id']);

        try {
            app(ApproveTrialBalanceSnapshotAction::class)->execute($snapshot, $c['user_id']);
            expect(false)->toBeTrue('approving without the permission should be refused');
        } catch (TrialBalanceRuleException $e) {
            expect($e->errorCode())->toBe('PERMISSION_DENIED');
            expect($e->errorStatus())->toBe(403);
        }
    });
});

it('refuses to generate without accounting.trial_balance.generate (403)', function (): void {
    $c = TenantHarness::seedCompany('NoGen Co');
    tbFixture($c['company_id']);
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        try {
            app(GenerateTrialBalanceSnapshotAction::class)->execute($c['company_id'], $february, $c['user_id']);
            expect(false)->toBeTrue('generating without the permission should be refused');
        } catch (TrialBalanceRuleException $e) {
            expect($e->errorCode())->toBe('PERMISSION_DENIED');
        }
    });
});

// ---------------------------------------------------------------- the queue + tenant context

it('hands a large generation to the reports queue and leaves the snapshot generating', function (): void {
    Queue::fake();
    config(['accounting.trial_balance.async_account_threshold' => 1]);

    $c = TenantHarness::seedCompany('Async Co');
    tbFixture($c['company_id']);
    tbGrant($c['role_id'], 'accounting.trial_balance.generate');
    $february = tbPeriodOn($c['company_id'], '2026-02-15');

    TenantHarness::runInTenant($c['company_id'], function () use ($c, $february) {
        $snapshot = app(GenerateTrialBalanceSnapshotAction::class)
            ->execute($c['company_id'], $february, $c['user_id']);

        expect($snapshot->status)->toBe(TrialBalanceSnapshot::STATUS_GENERATING);
        expect($snapshot->total_debit)->toBe('0.0000');

        Queue::assertPushedOn('reports', GenerateTrialBalanceSnapshotJob::class);
        Queue::assertPushed(
            GenerateTrialBalanceSnapshotJob::class,
            fn (GenerateTrialBalanceSnapshotJob $job) => $job->companyId === $c['company_id']
                && $job->snapshotId === $snapshot->id,
        );
    });
});

it('runs the queued job inside its own tenant context, with no ambient company', function (): void {
    $c = TenantHarness::seedCompany('Worker Co');
    tbFixture($c['company_id']);
    $february = tbPeriodOn($c['company_id'], '2026-02-15');
    $owner = TenantHarness::owner();

    // A committed snapshot in `generating`, exactly as the HTTP request would have left it.
    $snapshotId = (int) $owner->selectOne(
        "INSERT INTO trial_balance_snapshots (company_id, fiscal_period_id, as_of_date, period_start_date,
                                              type, status, currency_code)
         VALUES (?, ?, '2026-02-28', '2026-02-01', 'unadjusted', 'generating', 'KWD') RETURNING id",
        [$c['company_id'], $february],
    )->id;

    // No runInTenant here: a worker has no request and no ambient tenant. The job must establish its
    // own, which is the whole point of RunsInTenantContext.
    expect(TenantContext::companyId())->toBeNull();

    (new GenerateTrialBalanceSnapshotJob($c['company_id'], $snapshotId, $c['user_id']))
        ->handle(app(GenerateTrialBalanceSnapshotAction::class));

    $row = $owner->selectOne(
        'SELECT status, total_debit, total_credit, line_count FROM trial_balance_snapshots WHERE id = ?',
        [$snapshotId],
    );

    expect($row->status)->toBe('generated');
    expect($row->total_debit)->toBe('140.0000');
    expect($row->total_credit)->toBe('140.0000');
    expect((int) $row->line_count)->toBe(2);

    // And it left no tenant behind for the next job in this worker process.
    expect(TenantContext::companyId())->toBeNull();
});

it('refuses to run a queued job with no company, rather than running unscoped', function (): void {
    expect(fn () => (new GenerateTrialBalanceSnapshotJob(0, 1, null))
        ->handle(app(GenerateTrialBalanceSnapshotAction::class)))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------- HTTP surface

/**
 * @param  list<string>  $permissions
 * @return array{user: User, uuid: string, company_id: int}
 */
function tbMember(array $permissions): array
{
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'TB '.uniqid(), 'tb_role');

    foreach ($permissions as $key) {
        RbacFixtures::attachToRole($m['role_id'], RbacFixtures::permission($key, 'accounting'));
    }

    return ['user' => $user, 'uuid' => $m['company_uuid'], 'company_id' => (int) $m['company_id']];
}

it('serves the live trial balance over HTTP', function (): void {
    $m = tbMember(['accounting.trial_balance.read']);
    tbFixture($m['company_id']);
    $february = tbPeriodOn($m['company_id'], '2026-02-15');

    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/reports/trial-balance?fiscal_period_id={$february}", [
            'X-Company-Id' => $m['uuid'],
        ])
        ->assertOk()
        ->assertJsonPath('data.total_debit', '140.0000')
        ->assertJsonPath('data.total_credit', '140.0000')
        ->assertJsonPath('data.variance', '0.0000')
        ->assertJsonPath('data.is_balanced', true)
        ->assertJsonCount(2, 'data.lines');
});

it('returns 201 when a snapshot is generated inline', function (): void {
    $m = tbMember(['accounting.trial_balance.read', 'accounting.trial_balance.generate']);
    tbFixture($m['company_id']);
    $february = tbPeriodOn($m['company_id'], '2026-02-15');

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/reports/trial-balance', ['fiscal_period_id' => $february], [
            'X-Company-Id' => $m['uuid'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.queued', false)
        ->assertJsonPath('data.snapshot.status', 'generated')
        ->assertJsonPath('data.snapshot.total_debit', '140.0000');
});

it('returns 202 when the generation is handed to the reports queue', function (): void {
    Queue::fake();
    config(['accounting.trial_balance.async_account_threshold' => 1]);

    $m = tbMember(['accounting.trial_balance.generate']);
    tbFixture($m['company_id']);
    $february = tbPeriodOn($m['company_id'], '2026-02-15');

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/reports/trial-balance', ['fiscal_period_id' => $february], [
            'X-Company-Id' => $m['uuid'],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.queued', true)
        ->assertJsonPath('data.snapshot.status', 'generating');

    Queue::assertPushedOn('reports', GenerateTrialBalanceSnapshotJob::class);
});

it('denies trial-balance reads without accounting.trial_balance.read (403)', function (): void {
    $m = tbMember([]);
    tbFixture($m['company_id']);
    $february = tbPeriodOn($m['company_id'], '2026-02-15');

    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/reports/trial-balance?fiscal_period_id={$february}", [
            'X-Company-Id' => $m['uuid'],
        ])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('denies generating for a reader (403), so preparing is separate from reading', function (): void {
    $m = tbMember(['accounting.trial_balance.read']);
    tbFixture($m['company_id']);
    $february = tbPeriodOn($m['company_id'], '2026-02-15');

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/reports/trial-balance', ['fiscal_period_id' => $february], [
            'X-Company-Id' => $m['uuid'],
        ])
        ->assertStatus(403);
});

it('requires authentication for the trial balance', function (): void {
    $this->getJson('/api/v1/accounting/reports/trial-balance?fiscal_period_id=1', [
        'X-Company-Id' => 'anything',
    ])->assertStatus(401);
});
