<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\GuardsPeriodTransitions;
use App\Enums\AuditCategory;
use App\Exceptions\Accounting\PeriodRuleException;
use App\Models\FiscalPeriod;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\PermissionResolver;

/**
 * Reopen a closed or locked fiscal period (S2-07, docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR";
 * docs/accounting/JOURNAL_ENTRIES.md "# Fiscal Period Rules" — "Reopening is never a casual action").
 *
 * Reopening undoes a published assertion: a month whose trial balance somebody has already read starts
 * moving again. So it is the most controlled transition in the calendar, and three things are required
 * rather than one:
 *
 *  - **A reason.** Not optional and not defaulted. An audit trail that records the reopen but not the
 *    justification cannot answer the only question anyone will ask about it later.
 *  - **The permission matching the state.** A `closed` period needs `accounting.period.reopen`; a
 *    `locked` one — audit-signed-off — needs `accounting.period.hard_lock_override`. The required grant
 *    is derived from the status read under the lock, never from what the caller says the status is.
 *  - **An audit row**, written inside the same transaction as the status change, carrying the reason.
 *
 * `closed_at`/`closed_by` are deliberately left in place: they are the record of the close that this
 * reopen is an exception to, and the audit ledger — not these columns — is where the full history lives.
 */
final class ReopenPeriodAction
{
    use GuardsPeriodTransitions;

    /** Reopening an ordinary closed period. */
    private const PERMISSION_REOPEN = 'accounting.period.reopen';

    /** Reopening a period that has been hard-locked after audit sign-off. */
    private const PERMISSION_OVERRIDE = 'accounting.period.hard_lock_override';

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function execute(FiscalPeriod $period, string $reason, ?int $actorUserId = null): FiscalPeriod
    {
        if (trim($reason) === '') {
            throw PeriodRuleException::reasonRequired();
        }

        $connection = $this->tenantConnection();

        $connection->transaction(function () use ($period, $reason, $actorUserId, $connection): void {
            $status = $this->lockPeriodStatus($connection, $period);

            $permission = match ($status) {
                FiscalPeriod::STATUS_CLOSED => self::PERMISSION_REOPEN,
                FiscalPeriod::STATUS_LOCKED => self::PERMISSION_OVERRIDE,
                default => throw PeriodRuleException::notReopenable($status),
            };

            $this->assertPermitted($this->permissions, $actorUserId, $period->company_id, $permission, 'reopen');

            FiscalPeriod::query()->whereKey($period->id)->update([
                'status' => FiscalPeriod::STATUS_OPEN,
                'reopened_at' => now(),
                'reopened_by' => $actorUserId,
                'reopen_reason' => $reason,
                'updated_by' => $actorUserId,
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'accounting.period.reopened',
                category: AuditCategory::DataMutation,
                entityType: 'fiscal_periods',
                entityId: $period->id,
                oldValues: ['status' => $status],
                newValues: ['status' => FiscalPeriod::STATUS_OPEN],
                reason: $reason,
                companyId: $period->company_id,
                actorUserId: $actorUserId,
            );
        });

        return $period->refresh();
    }
}
