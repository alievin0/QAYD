<?php

declare(strict_types=1);

namespace App\Jobs\Accounting;

use App\Actions\Accounting\GenerateTrialBalanceSnapshotAction;
use App\Jobs\Concerns\RunsInTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fill a trial-balance snapshot on the `reports` queue (S2-09).
 *
 * The project's FIRST queued job, so it establishes the pattern every later one follows:
 *
 *  - **It carries its tenant as data.** `companyId` is a constructor argument, serialized into the
 *    payload. A worker has no request, no session and no ambient company, so anything that tried to
 *    infer the tenant at execution time would either fail or — far worse — pick up the previous job's.
 *  - **It carries an ID, not a model.** `SerializesModels` would re-resolve a `TrialBalanceSnapshot`
 *    on unserialize, outside any tenant context, where RLS correctly returns nothing. Passing the id
 *    and re-reading it inside the context is the only order that works.
 *  - **All its work happens inside {@see RunsInTenantContext}**, which sets transaction-local GUCs so a
 *    crash cannot leave a pooled connection carrying this company's identity.
 *
 * The work itself is `GenerateTrialBalanceSnapshotAction::fill()` — the exact method the synchronous
 * path calls. Queuing changes when the trial balance is computed, never what it is.
 *
 * `tries = 1`: filling is idempotent by status (a snapshot that is no longer `generating` is left
 * alone), so a retry would be silently useless rather than helpful, and a failed report generation
 * should surface as one failed job rather than three.
 */
final class GenerateTrialBalanceSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInTenantContext;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $companyId,
        public readonly int $snapshotId,
        public readonly ?int $actorUserId = null,
    ) {
        $queue = config('accounting.trial_balance.queue', 'reports');
        $this->onQueue(is_string($queue) ? $queue : 'reports');
    }

    public function handle(GenerateTrialBalanceSnapshotAction $action): void
    {
        $this->runInTenantContext(
            $this->companyId,
            $this->actorUserId,
            fn () => $action->fill($this->snapshotId, $this->actorUserId),
        );
    }
}
