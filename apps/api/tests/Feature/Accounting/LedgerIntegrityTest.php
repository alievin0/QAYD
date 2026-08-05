<?php

declare(strict_types=1);

use App\Actions\Accounting\PostJournalEntryAction;
use App\Domain\Accounting\LedgerIntegrityReport;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Jobs\Accounting\VerifyLedgerIntegrityJob;
use App\Models\JournalEntry;
use App\Services\Accounting\LedgerIntegrityVerifier;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TenantHarness;

/**
 * The nightly ledger integrity check (SPRINT_02 §S2-14).
 *
 * The job asks one question — does `ledger_entries` still say exactly what the posted journals say —
 * and the tests that matter are the ones that make it answer "no". A check only ever shown passing is
 * not evidence of anything, so drift is seeded in both directions here: a ledger row no posted journal
 * explains, and a posted journal line the ledger never received.
 *
 * Drift is seeded by INSERTING, never by updating: `trg_ledger_entries_append_only` refuses UPDATE and
 * DELETE even to the schema owner. That constraint is why the rebuild happens in a scratch table at
 * all, and it means a test cannot corrupt the ledger the obvious way even deliberately.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

function liAccount(int $companyId, string $code): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit', 'active') RETURNING id",
        [$companyId, $typeId, $code.bin2hex(random_bytes(4))],
    )->id;
}

function liFiscalYear(int $companyId): int
{
    $yearId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2026-01-01', '2026-12-31', 'open'::fiscal_year_status) RETURNING id",
        [$companyId, 'FY-'.bin2hex(random_bytes(4))],
    )->id;

    MonthlyFiscalPeriodGenerator::generate(
        TenantHarness::owner(), $companyId, $yearId, '2026-01-01', '2026-12-31', 'open',
    );

    return $yearId;
}

function liPeriod(int $fiscalYearId): int
{
    return (int) TenantHarness::owner()->selectOne(
        "SELECT id FROM fiscal_periods WHERE fiscal_year_id = ? AND '2026-07-01' BETWEEN start_date AND end_date",
        [$fiscalYearId],
    )->id;
}

/** A balanced draft, persisted on the owner connection so it survives the tenant rollback. */
function liDraft(int $companyId, int $fiscalYearId, int $debitAccount, int $creditAccount, string $amount): int
{
    $owner = TenantHarness::owner();

    $entryId = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, fiscal_year_id)
         VALUES (?, ?, '2026-07-01', 'manual', 'KWD', 'draft', ?) RETURNING id",
        [$companyId, 'JE-LI-'.bin2hex(random_bytes(8)), $fiscalYearId],
    )->id;

    foreach ([[$debitAccount, $amount, '0'], [$creditAccount, '0', $amount]] as $i => [$account, $debit, $credit]) {
        $owner->insert(
            'INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit, currency_code, base_debit, base_credit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$companyId, $entryId, $i + 1, $account, $debit, $credit, 'KWD', $debit, $credit],
        );
    }

    return $entryId;
}

/** A spurious ledger row for a line that was never posted — drift the rebuild must notice. */
function liOrphanLedgerRow(int $companyId, int $yearId, int $entryId, int $accountId, string $amount): void
{
    $lineId = (int) TenantHarness::owner()->selectOne(
        'SELECT id FROM journal_lines WHERE journal_entry_id = ? ORDER BY line_number LIMIT 1',
        [$entryId],
    )->id;

    TenantHarness::owner()->insert(
        "INSERT INTO ledger_entries (company_id, journal_entry_id, journal_line_id, account_id, fiscal_year_id,
                                     fiscal_period_id, entry_date, posted_at, entry_type, currency_code,
                                     debit_amount, credit_amount, base_debit_amount, base_credit_amount, signed_base_amount)
         VALUES (?, ?, ?, ?, ?, ?, '2026-07-01', now(), 'manual', 'KWD', ?, 0, ?, 0, ?)",
        [$companyId, $entryId, $lineId, $accountId, $yearId, liPeriod($yearId), $amount, $amount, $amount],
    );
}

/**
 * A company with one balanced draft ready to post.
 *
 * @return array<string, int|string>
 */
function liPostedCompany(string $name): array
{
    $co = TenantHarness::seedCompany($name);
    $yearId = liFiscalYear($co['company_id']);
    $co['fiscal_year_id'] = $yearId;
    $co['entry_id'] = liDraft(
        $co['company_id'], $yearId,
        liAccount($co['company_id'], 'D'), liAccount($co['company_id'], 'C'),
        '500.0000',
    );

    return $co;
}

/** Post the seeded draft through the real engine, then verify — both inside one tenant context. */
function liPostAndVerify(array $co): LedgerIntegrityReport
{
    return TenantHarness::runInTenant($co['company_id'], function () use ($co): LedgerIntegrityReport {
        $entry = JournalEntry::query()->findOrFail($co['entry_id']);
        app(PostJournalEntryAction::class)->execute($entry, $co['user_id']);

        return app(LedgerIntegrityVerifier::class)->verify($co['company_id']);
    });
}

describe('a ledger that matches its journals', function (): void {
    it('verifies a genuinely posted entry as intact', function (): void {
        $report = liPostAndVerify(liPostedCompany('Integrity Clean Co'));

        expect($report->isIntact())->toBeTrue()
            ->and($report->discrepancies)->toBe([])
            ->and($report->rebuiltRowCount)->toBe(2)
            ->and($report->ledgerRowCount)->toBe(2);
    });

    it('ties the rebuild to the live trial balance', function (): void {
        $report = liPostAndVerify(liPostedCompany('Integrity Ties Co'));

        expect($report->statementTies())->toBeTrue()
            ->and($report->rebuiltDebitTotal)->toBe('500.0000')
            ->and($report->statementDebitTotal)->toBe('500.0000')
            ->and($report->rebuiltCreditTotal)->toBe('500.0000');
    });

    it('treats a company that has never posted as intact, not suspicious', function (): void {
        $co = TenantHarness::seedCompany('Integrity Empty Co');

        $report = TenantHarness::runInTenant(
            $co['company_id'],
            fn (): LedgerIntegrityReport => app(LedgerIntegrityVerifier::class)->verify($co['company_id']),
        );

        expect($report->isIntact())->toBeTrue()
            ->and($report->rebuiltRowCount)->toBe(0)
            ->and($report->ledgerRowCount)->toBe(0);
    });
});

describe('seeded drift', function (): void {
    it('detects a ledger row that no posted journal explains', function (): void {
        $co = TenantHarness::seedCompany('Integrity Extra Co');
        $yearId = liFiscalYear($co['company_id']);
        $account = liAccount($co['company_id'], 'X');
        // A DRAFT entry — never posted — given a ledger row anyway. The rebuild reads posted journals,
        // so it will not produce this row, and the ledger is left carrying one nobody can explain.
        $entryId = liDraft($co['company_id'], $yearId, $account, liAccount($co['company_id'], 'Y'), '75.0000');
        liOrphanLedgerRow($co['company_id'], $yearId, $entryId, $account, '75.0000');

        $report = TenantHarness::runInTenant(
            $co['company_id'],
            fn (): LedgerIntegrityReport => app(LedgerIntegrityVerifier::class)->verify($co['company_id']),
        );

        expect($report->isIntact())->toBeFalse()
            ->and($report->rebuiltRowCount)->toBe(0)
            ->and($report->ledgerRowCount)->toBe(1)
            ->and($report->discrepancies)->toHaveCount(1);

        $finding = $report->discrepancies[0];
        expect($finding->accountId)->toBe($account)
            ->and($finding->rebuiltSigned)->toBe('0.0000')
            ->and($finding->ledgerSigned)->toBe('75.0000')
            // Signed, so the direction of the drift is part of the finding.
            ->and($finding->difference)->toBe('-75.0000')
            ->and($finding->ledgerLineCount)->toBe(1)
            ->and($finding->rebuiltLineCount)->toBe(0);
    });

    it('detects a posted journal line the ledger never received', function (): void {
        $co = TenantHarness::seedCompany('Integrity Missing Co');
        $yearId = liFiscalYear($co['company_id']);
        $account = liAccount($co['company_id'], 'M');

        // Marked posted but never projected — the failure a rebuild exists to catch, and the one an
        // append-only ledger cannot be made to show by deleting a row.
        $entryId = (int) TenantHarness::owner()->selectOne(
            "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, fiscal_year_id, posted_at, locked)
             VALUES (?, ?, '2026-07-01', 'manual', 'KWD', 'posted', ?, now(), true) RETURNING id",
            [$co['company_id'], 'JE-LI-'.bin2hex(random_bytes(8)), $yearId],
        )->id;

        TenantHarness::owner()->insert(
            "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit, currency_code, base_debit, base_credit)
             VALUES (?, ?, 1, ?, '120.0000', 0, 'KWD', '120.0000', 0)",
            [$co['company_id'], $entryId, $account],
        );

        $report = TenantHarness::runInTenant(
            $co['company_id'],
            fn (): LedgerIntegrityReport => app(LedgerIntegrityVerifier::class)->verify($co['company_id']),
        );

        expect($report->isIntact())->toBeFalse()
            ->and($report->rebuiltRowCount)->toBe(1)
            ->and($report->ledgerRowCount)->toBe(0);

        $finding = $report->discrepancies[0];
        expect($finding->rebuiltSigned)->toBe('120.0000')
            ->and($finding->ledgerSigned)->toBe('0.0000')
            ->and($finding->difference)->toBe('120.0000');
    });

    it('reports the drifted account with its live closing balance, for whoever reads the alert', function (): void {
        $co = TenantHarness::seedCompany('Integrity Detail Co');
        $yearId = liFiscalYear($co['company_id']);
        $account = liAccount($co['company_id'], 'B');
        $entryId = liDraft($co['company_id'], $yearId, $account, liAccount($co['company_id'], 'Z'), '40.0000');
        liOrphanLedgerRow($co['company_id'], $yearId, $entryId, $account, '40.0000');

        $report = TenantHarness::runInTenant(
            $co['company_id'],
            fn (): LedgerIntegrityReport => app(LedgerIntegrityVerifier::class)->verify($co['company_id']),
        );

        // Read a third way — through the statement reader an accountant would open next.
        expect($report->discrepancies[0]->ledgerClosingBalance)->toBe('40.0000');
    });
});

describe('the guarantees the check itself must keep', function (): void {
    it('never writes to the live ledger', function (): void {
        $co = liPostedCompany('Integrity Readonly Co');

        [$before, $after] = TenantHarness::runInTenant($co['company_id'], function () use ($co): array {
            $entry = JournalEntry::query()->findOrFail($co['entry_id']);
            app(PostJournalEntryAction::class)->execute($entry, $co['user_id']);

            $connection = TenantHarness::app();
            $before = (int) $connection->selectOne('SELECT COUNT(*) AS n FROM ledger_entries')->n;
            app(LedgerIntegrityVerifier::class)->verify($co['company_id']);
            $after = (int) $connection->selectOne('SELECT COUNT(*) AS n FROM ledger_entries')->n;

            return [$before, $after];
        });

        expect($after)->toBe($before)->and($before)->toBe(2);
    });

    it('drops its scratch table with the transaction', function (): void {
        liPostAndVerify(liPostedCompany('Integrity Scratch Co'));

        // Nothing survives the rebuild: a leaked temp table on a pooled connection would make the next
        // company's rebuild fail on a name collision — or worse, succeed against stale rows.
        $leaked = (int) TenantHarness::owner()->selectOne(
            "SELECT COUNT(*) AS n FROM pg_class WHERE relname = 'ledger_rebuild'",
        )->n;

        expect($leaked)->toBe(0);
    });

    it('refuses to run without a transaction, rather than verify the wrong tenant', function (): void {
        $failed = false;
        try {
            app(LedgerIntegrityVerifier::class)->verify(1);
        } catch (RuntimeException $e) {
            $failed = str_contains($e->getMessage(), 'must run inside a transaction');
        }

        expect($failed)->toBeTrue();
    });

    it('sees only its own company, so one tenant drift cannot implicate another', function (): void {
        $drifted = TenantHarness::seedCompany('Integrity Noisy Co');
        $yearId = liFiscalYear($drifted['company_id']);
        $account = liAccount($drifted['company_id'], 'N');
        $entryId = liDraft($drifted['company_id'], $yearId, $account, liAccount($drifted['company_id'], 'O'), '99.0000');
        liOrphanLedgerRow($drifted['company_id'], $yearId, $entryId, $account, '99.0000');

        $quiet = TenantHarness::seedCompany('Integrity Quiet Co');

        $report = TenantHarness::runInTenant(
            $quiet['company_id'],
            fn (): LedgerIntegrityReport => app(LedgerIntegrityVerifier::class)->verify($quiet['company_id']),
        );

        // RLS is the boundary — the verifier carries no company_id predicate of its own.
        expect($report->isIntact())->toBeTrue()
            ->and($report->ledgerRowCount)->toBe(0)
            ->and($report->discrepancies)->toBe([]);
    });
});

describe('alerting', function (): void {
    it('logs a critical alert when drift is found', function (): void {
        $co = TenantHarness::seedCompany('Integrity Alert Co');
        $yearId = liFiscalYear($co['company_id']);
        $account = liAccount($co['company_id'], 'A');
        $entryId = liDraft($co['company_id'], $yearId, $account, liAccount($co['company_id'], 'B'), '10.0000');
        liOrphanLedgerRow($co['company_id'], $yearId, $entryId, $account, '10.0000');

        Log::spy();

        $report = (new VerifyLedgerIntegrityJob($co['company_id']))
            ->handle(app(LedgerIntegrityVerifier::class));

        expect($report->isIntact())->toBeFalse();

        Log::shouldHaveReceived('critical')->once()
            ->withArgs(function (string $message, array $context) use ($co): bool {
                return str_contains($message, 'Ledger integrity check FAILED')
                    && $context['company_id'] === $co['company_id']
                    && $context['intact'] === false
                    && $context['discrepancy_count'] === 1
                    // Both sides travel in the alert, so it is actionable without a database session.
                    && $context['discrepancies'][0]['ledger_signed'] === '10.0000'
                    && $context['discrepancies'][0]['rebuilt_signed'] === '0.0000';
            });
    });

    it('logs a clean run too, so silence never passes for success', function (): void {
        $co = TenantHarness::seedCompany('Integrity Quiet Log Co');

        Log::spy();

        (new VerifyLedgerIntegrityJob($co['company_id']))->handle(app(LedgerIntegrityVerifier::class));

        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('critical');
    });

    it('runs on the maintenance queue, away from latency-sensitive work', function (): void {
        expect((new VerifyLedgerIntegrityJob(1))->queue)->toBe('maintenance');
    });
});

describe('the nightly command', function (): void {
    it('queues one job per live company', function (): void {
        Queue::fake();

        $a = TenantHarness::seedCompany('Integrity Cmd A');
        $b = TenantHarness::seedCompany('Integrity Cmd B');

        Artisan::call('accounting:verify-ledger-integrity');

        // One job per company: the tenant context has to be established per company, and a single
        // process looping over tenants is how that guarantee gets quietly lost.
        Queue::assertPushed(
            VerifyLedgerIntegrityJob::class,
            fn (VerifyLedgerIntegrityJob $job): bool => $job->companyId === $a['company_id'],
        );
        Queue::assertPushed(
            VerifyLedgerIntegrityJob::class,
            fn (VerifyLedgerIntegrityJob $job): bool => $job->companyId === $b['company_id'],
        );
    });

    it('can be pointed at a single company', function (): void {
        Queue::fake();

        $only = TenantHarness::seedCompany('Integrity Cmd Only');
        TenantHarness::seedCompany('Integrity Cmd Other');

        Artisan::call('accounting:verify-ledger-integrity', ['--company' => (string) $only['company_id']]);

        Queue::assertPushed(VerifyLedgerIntegrityJob::class, 1);
    });
});
