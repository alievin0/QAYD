<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\TrialBalanceRow;
use App\Enums\AuditCategory;
use App\Exceptions\Accounting\TrialBalanceRuleException;
use App\Jobs\Accounting\GenerateTrialBalanceSnapshotJob;
use App\Models\TrialBalanceSnapshot;
use App\Models\TrialBalanceSnapshotLine;
use App\Services\Accounting\TrialBalanceService;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\PermissionResolver;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Generate a trial-balance snapshot (S2-09, docs/accounting/TRIAL_BALANCE.md "# Generate").
 *
 * The run happens in two halves, and the split is what makes the asynchronous path possible without a
 * second implementation of the work:
 *
 *  - {@see open()} creates the header immediately in `generating`, superseding whatever was previously
 *    current for the same (company, period, type) by clearing its `is_current` flag and pointing the
 *    new row at it as `parent_snapshot_id` with `version + 1`. The old snapshot is not otherwise
 *    touched — TRIAL_BALANCE.md is explicit that a snapshot is never mutated, so a correction is a new
 *    version and the superseded one stays exactly as it was signed.
 *  - {@see fill()} computes the aggregate, writes the lines, and finalises the header.
 *
 * A synchronous request calls both. A large one calls `open()`, hands the id to
 * {@see GenerateTrialBalanceSnapshotJob}, and the caller gets `202` with a snapshot already visible in
 * `generating`. The job then calls the SAME `fill()` — there is one implementation of what a trial
 * balance is, whichever path produced it.
 *
 * A run whose debits do not equal its credits is stored as `out_of_balance` with the variance intact.
 * It is not retried, not rounded, and not hidden: a trial balance that does not prove is the single
 * most important thing this module can tell a finance team.
 */
final class GenerateTrialBalanceSnapshotAction
{
    private const PERMISSION = 'accounting.trial_balance.generate';

    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * Open a snapshot for the period and either fill it now or hand it to the reports queue.
     *
     * The returned snapshot is still `generating` exactly when the work was queued, which is how the
     * controller knows to answer 202 rather than 201.
     */
    public function execute(
        int $companyId,
        int $fiscalPeriodId,
        ?int $actorUserId = null,
        string $type = 'unadjusted',
    ): TrialBalanceSnapshot {
        if ($actorUserId === null || ! $this->permissions->resolve($actorUserId, $companyId)->has(self::PERMISSION)) {
            throw TrialBalanceRuleException::forbidden(self::PERMISSION, 'generate');
        }

        $snapshot = $this->open($companyId, $fiscalPeriodId, $actorUserId, $type);

        if ($this->shouldQueue($snapshot->as_of_date)) {
            GenerateTrialBalanceSnapshotJob::dispatch($companyId, $snapshot->id, $actorUserId);

            return $snapshot;
        }

        return $this->fill($snapshot->id, $actorUserId);
    }

    /**
     * Create the header row for a new run, superseding the previous current snapshot for the same
     * scope. One transaction, so the supersede and the insert cannot half-happen and leave two rows
     * claiming to be current — which `ux_tbs_current_logical_key` would reject anyway.
     */
    public function open(
        int $companyId,
        int $fiscalPeriodId,
        ?int $actorUserId,
        string $type = 'unadjusted',
    ): TrialBalanceSnapshot {
        $connection = DB::connection(TenantContext::connection());

        /** @var TrialBalanceSnapshot $snapshot */
        $snapshot = $connection->transaction(function () use ($connection, $companyId, $fiscalPeriodId, $actorUserId, $type): TrialBalanceSnapshot {
            $period = $connection->selectOne(
                'SELECT start_date::text AS start_date, end_date::text AS end_date, fiscal_year_id
                 FROM fiscal_periods WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$fiscalPeriodId],
            );

            if ($period === null) {
                throw TrialBalanceRuleException::unknownPeriod($fiscalPeriodId);
            }

            $previous = TrialBalanceSnapshot::query()
                ->where('fiscal_period_id', $fiscalPeriodId)
                ->where('type', $type)
                ->where('is_current', true)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            $parentId = null;
            $version = 1;

            if ($previous !== null) {
                // Only the flag moves. The superseded snapshot's figures, status and signatures are
                // left exactly as they were — the immutability trigger would refuse anything else.
                TrialBalanceSnapshot::query()->whereKey($previous->id)->update(['is_current' => false]);

                $parentId = $previous->id;
                $version = $previous->version + 1;
            }

            $new = new TrialBalanceSnapshot;
            $new->forceFill([
                'company_id' => $companyId,
                'fiscal_year_id' => SqlRow::int($period, 'fiscal_year_id'),
                'fiscal_period_id' => $fiscalPeriodId,
                'as_of_date' => SqlRow::string($period, 'end_date'),
                'period_start_date' => SqlRow::string($period, 'start_date'),
                'type' => $type,
                'status' => TrialBalanceSnapshot::STATUS_GENERATING,
                'parent_snapshot_id' => $parentId,
                'version' => $version,
                'is_current' => true,
                'currency_code' => $this->baseCurrency($companyId),
                'rounding_tolerance' => $this->tolerance(),
                'generation_mode' => 'manual',
                'generated_by' => $actorUserId,
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);
            $new->save();

            return $new;
        });

        return $snapshot->refresh();
    }

    /**
     * Compute the trial balance, write its lines, and finalise the header. Idempotent by state: a
     * snapshot that is no longer `generating` has already been filled and is returned untouched, so a
     * retried job cannot double-write lines (`uq_tbsl_line_key` is the database's backstop).
     */
    public function fill(int $snapshotId, ?int $actorUserId = null): TrialBalanceSnapshot
    {
        $connection = DB::connection(TenantContext::connection());

        $connection->transaction(function () use ($snapshotId, $actorUserId): void {
            $snapshot = TrialBalanceSnapshot::query()->whereKey($snapshotId)->lockForUpdate()->first();

            if ($snapshot === null || $snapshot->status !== TrialBalanceSnapshot::STATUS_GENERATING) {
                return;
            }

            $balance = $this->trialBalance->computeForRange($snapshot->period_start_date, $snapshot->as_of_date);

            foreach ($balance->rows as $row) {
                $line = new TrialBalanceSnapshotLine;
                $line->forceFill([
                    'snapshot_id' => $snapshot->id,
                    'company_id' => $snapshot->company_id,
                    'account_id' => $row->accountId,
                    'account_code' => $row->accountCode,
                    'account_name_en' => $row->accountNameEn,
                    'account_name_ar' => $row->accountNameAr,
                    'account_type_id' => $row->accountTypeId,
                    'normal_balance' => $row->normalBalance,
                    'opening_debit' => $row->openingDebit,
                    'opening_credit' => $row->openingCredit,
                    'period_debit' => $row->periodDebit,
                    'period_credit' => $row->periodCredit,
                    'closing_debit' => $row->closingDebit,
                    'closing_credit' => $row->closingCredit,
                    'is_abnormal_balance' => $row->isAbnormalBalance,
                    'source_line_count' => $row->sourceLineCount,
                ]);
                $line->save();
            }

            $balanced = $balance->isBalanced($this->tolerance());
            $now = now();

            TrialBalanceSnapshot::query()->whereKey($snapshot->id)->update([
                'status' => $balanced
                    ? TrialBalanceSnapshot::STATUS_GENERATED
                    : TrialBalanceSnapshot::STATUS_OUT_OF_BALANCE,
                'total_debit' => $balance->totalDebit,
                'total_credit' => $balance->totalCredit,
                'variance' => $balance->variance,
                'account_count' => count($balance->rows),
                'line_count' => count($balance->rows),
                'has_warnings' => $this->hasAbnormalRows($balance->rows),
                'content_hash' => $this->contentHash($balance->rows),
                'generated_at' => $now,
                'validated_at' => $balanced ? $now : null,
                'updated_by' => $actorUserId,
                'updated_at' => $now,
            ]);

            AuditLogger::record(
                action: 'accounting.trial_balance.generated',
                category: AuditCategory::DataMutation,
                entityType: 'trial_balance_snapshots',
                entityId: $snapshot->id,
                newValues: [
                    'total_debit' => $balance->totalDebit,
                    'total_credit' => $balance->totalCredit,
                    'variance' => $balance->variance,
                ],
                companyId: $snapshot->company_id,
                actorUserId: $actorUserId,
            );
        });

        /** @var TrialBalanceSnapshot $fresh */
        $fresh = TrialBalanceSnapshot::query()->whereKey($snapshotId)->firstOrFail();

        return $fresh;
    }

    /**
     * Whether this run is big enough to belong on the queue. Measured on the group count, not the row
     * count: the aggregate's cost scales with how many accounts it has to group by.
     */
    private function shouldQueue(string $asOf): bool
    {
        $configured = config('accounting.trial_balance.async_account_threshold', 500);
        $threshold = is_numeric($configured) ? (int) $configured : 500;

        $accounts = DB::connection(TenantContext::connection())->scalar(
            'SELECT COUNT(DISTINCT account_id) FROM ledger_entries WHERE entry_date <= ?',
            [$asOf],
        );

        return is_numeric($accounts) && (int) $accounts > $threshold;
    }

    /**
     * A sha256 over the ordered line set — tamper evidence, so a stored snapshot can be checked against
     * its own lines later without recomputing from the ledger.
     *
     * @param  list<TrialBalanceRow>  $rows
     */
    private function contentHash(array $rows): string
    {
        $material = '';
        foreach ($rows as $row) {
            $material .= $row->accountCode.'|'.$row->closingDebit.'|'.$row->closingCredit."\n";
        }

        return hash('sha256', $material);
    }

    /**
     * @param  list<TrialBalanceRow>  $rows
     */
    private function hasAbnormalRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->isAbnormalBalance) {
                return true;
            }
        }

        return false;
    }

    /** The company's base currency — the one a trial balance is always expressed in. */
    private function baseCurrency(int $companyId): string
    {
        $row = DB::connection(TenantContext::connection())->selectOne(
            'SELECT base_currency FROM companies WHERE id = ?',
            [$companyId],
        );

        return $row === null ? 'KWD' : SqlRow::string($row, 'base_currency');
    }

    /** @return numeric-string */
    private function tolerance(): string
    {
        $tolerance = config('accounting.trial_balance.rounding_tolerance', '0.0050');

        return is_numeric($tolerance) ? (string) $tolerance : '0.0050';
    }
}
