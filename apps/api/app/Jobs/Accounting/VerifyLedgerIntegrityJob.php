<?php

declare(strict_types=1);

namespace App\Jobs\Accounting;

use App\Domain\Accounting\LedgerIntegrityReport;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Services\Accounting\LedgerIntegrityVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Verify one company's ledger against its posted journals, nightly (SPRINT_02 §S2-14).
 *
 * Follows the pattern the first queued job established: the tenant travels as a constructor argument
 * rather than being inferred at execution time, and all work happens inside
 * {@see RunsInTenantContext}, whose transaction-local GUCs mean a crash cannot leave a pooled
 * connection carrying this company's identity. Here that trait does double duty — the rebuild's scratch
 * table is `ON COMMIT DROP`, so the transaction it opens is also what cleans up.
 *
 * One company per job, deliberately. A tenant whose ledger is enormous, or whose rebuild fails, should
 * delay and alert for itself and not for everyone else; and a per-company job is the only shape in
 * which "re-establish tenant context per company" can be honestly true.
 *
 * `tries = 1`: the check is a pure read, so a retry would re-derive the same answer from the same data.
 * A failure here means the check could not run, which is worth surfacing as one failed job rather than
 * three identical ones.
 */
final class VerifyLedgerIntegrityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInTenantContext;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $companyId)
    {
        $queue = config('accounting.integrity.queue', 'maintenance');
        $this->onQueue(is_string($queue) ? $queue : 'maintenance');
    }

    public function handle(LedgerIntegrityVerifier $verifier): LedgerIntegrityReport
    {
        $report = $this->runInTenantContext(
            $this->companyId,
            null,
            fn (): LedgerIntegrityReport => $verifier->verify($this->companyId),
        );

        $this->announce($report);

        return $report;
    }

    /**
     * Say what was found.
     *
     * Drift in a ledger is `critical` and nothing softer. It means the derived projection no longer
     * matches the journals it came from, so every balance, statement and report read from it is suspect
     * until someone looks — that is not a warning, it is the accounting system saying it cannot
     * currently be trusted. The structured body carries both sides of every discrepancy, so the alert
     * is actionable without opening a database session.
     *
     * A clean run logs at `info`: a check that is silent when it passes is indistinguishable from one
     * that never ran, and "it has been green every night" is exactly the evidence this job exists to
     * produce.
     */
    private function announce(LedgerIntegrityReport $report): void
    {
        if ($report->isIntact()) {
            Log::info('Ledger integrity verified.', $report->toArray());

            return;
        }

        Log::critical(
            'Ledger integrity check FAILED — the stored ledger no longer matches its posted journals.',
            $report->toArray(),
        );
    }
}
