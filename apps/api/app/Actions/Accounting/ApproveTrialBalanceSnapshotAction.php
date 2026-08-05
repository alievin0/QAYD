<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Enums\AuditCategory;
use App\Exceptions\Accounting\TrialBalanceRuleException;
use App\Models\TrialBalanceSnapshot;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\PermissionResolver;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Approve a trial-balance snapshot (S2-09, docs/accounting/TRIAL_BALANCE.md "# Approve").
 *
 * Approval is what turns a computation into an assertion. Before it, the snapshot is one of many
 * possible runs; after it, a named human has signed that this is what the books said for that period,
 * and the figures are frozen at the database level (`trg_tbs_immutable_when_final`) rather than by
 * convention. That is why it needs its own permission, why it is audited, and why it is refused in
 * three specific cases:
 *
 *  - **Out of balance.** A trial balance whose debits do not equal its credits is arithmetically
 *    false. Signing one would put a person's name on a statement that cannot be true, so it is refused
 *    outright — not warned about, not overridable. The remedy is to fix the ledger and generate a new
 *    version.
 *  - **Still generating.** Its figures are not final yet; there is nothing to sign.
 *  - **Already approved or archived.** A signed trial balance is superseded by a NEW version, never
 *    re-signed, so the audit trail records who signed what and when, exactly once.
 *
 * The status is re-read under a row lock, so two simultaneous approvals resolve to one.
 */
final class ApproveTrialBalanceSnapshotAction
{
    private const PERMISSION = 'accounting.trial_balance.approve';

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function execute(TrialBalanceSnapshot $snapshot, ?int $actorUserId = null): TrialBalanceSnapshot
    {
        if ($actorUserId === null
            || ! $this->permissions->resolve($actorUserId, $snapshot->company_id)->has(self::PERMISSION)) {
            throw TrialBalanceRuleException::forbidden(self::PERMISSION, 'approve');
        }

        $connection = DB::connection(TenantContext::connection());

        $connection->transaction(function () use ($snapshot, $actorUserId): void {
            $locked = TrialBalanceSnapshot::query()->whereKey($snapshot->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw TrialBalanceRuleException::notApprovable('unknown');
            }

            if (in_array($locked->status, TrialBalanceSnapshot::FINAL_STATUSES, true)) {
                throw TrialBalanceRuleException::alreadyFinal($locked->status);
            }

            if ($locked->status === TrialBalanceSnapshot::STATUS_OUT_OF_BALANCE) {
                throw TrialBalanceRuleException::outOfBalance($locked->variance);
            }

            if (! in_array($locked->status, TrialBalanceSnapshot::APPROVABLE_STATUSES, true)) {
                throw TrialBalanceRuleException::notApprovable($locked->status);
            }

            $now = now();

            TrialBalanceSnapshot::query()->whereKey($locked->id)->update([
                'status' => TrialBalanceSnapshot::STATUS_APPROVED,
                'approved_by' => $actorUserId,
                'approved_at' => $now,
                'updated_by' => $actorUserId,
                'updated_at' => $now,
            ]);

            AuditLogger::record(
                action: 'accounting.trial_balance.approved',
                category: AuditCategory::DataMutation,
                entityType: 'trial_balance_snapshots',
                entityId: $locked->id,
                oldValues: ['status' => $locked->status],
                newValues: [
                    'status' => TrialBalanceSnapshot::STATUS_APPROVED,
                    'total_debit' => $locked->total_debit,
                    'total_credit' => $locked->total_credit,
                ],
                companyId: $locked->company_id,
                actorUserId: $actorUserId,
            );
        });

        return $snapshot->refresh();
    }
}
