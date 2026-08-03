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
 * Hard-lock a closed fiscal period (S2-07, docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR").
 *
 * `closed` and `locked` both refuse postings, so locking changes nothing about what can be posted. What
 * it changes is who can undo it: a closed period can be reopened with `accounting.period.reopen`, a
 * locked one only with `accounting.period.hard_lock_override`. That is the point — a period is locked
 * once external audit has signed off on it, and the lock exists to make quietly reopening an audited
 * month something a company has to decide to do at a higher level, not something a month-end routine
 * can do by accident.
 *
 * The path is strictly `open → closed → locked`: a period cannot be locked straight from open. Skipping
 * the close would mean a month became audit-final without ever having been closed and reviewed, and the
 * ordinary close is where the review happens.
 */
final class LockPeriodAction
{
    use GuardsPeriodTransitions;

    private const PERMISSION = 'accounting.period.lock';

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function execute(FiscalPeriod $period, ?int $actorUserId = null): FiscalPeriod
    {
        $connection = $this->tenantConnection();

        $this->assertPermitted($this->permissions, $actorUserId, $period->company_id, self::PERMISSION, 'lock');

        $connection->transaction(function () use ($period, $actorUserId, $connection): void {
            $status = $this->lockPeriodStatus($connection, $period);

            if ($status !== FiscalPeriod::STATUS_CLOSED) {
                throw PeriodRuleException::notClosed($status);
            }

            FiscalPeriod::query()->whereKey($period->id)->update([
                'status' => FiscalPeriod::STATUS_LOCKED,
                'locked_at' => now(),
                'locked_by' => $actorUserId,
                'updated_by' => $actorUserId,
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'accounting.period.locked',
                category: AuditCategory::DataMutation,
                entityType: 'fiscal_periods',
                entityId: $period->id,
                oldValues: ['status' => FiscalPeriod::STATUS_CLOSED],
                newValues: ['status' => FiscalPeriod::STATUS_LOCKED],
                companyId: $period->company_id,
                actorUserId: $actorUserId,
            );
        });

        return $period->refresh();
    }
}
