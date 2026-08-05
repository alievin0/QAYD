<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\GuardsPeriodTransitions;
use App\Domain\Accounting\FiscalPeriodCalendarResolver;
use App\Enums\AuditCategory;
use App\Events\Accounting\FiscalPeriodClosed;
use App\Exceptions\Accounting\PeriodRuleException;
use App\Models\FiscalPeriod;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\PermissionResolver;

/**
 * Close a fiscal period (S2-07, docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR").
 *
 * Closing a month is the moment its numbers become an assertion rather than a work in progress: from
 * here the posting engine refuses every new entry dated inside it ({@see FiscalPeriodCalendarResolver}),
 * so the trial balance for that month stops moving. That is why it needs the `accounting.period.close`
 * permission, why it is audited, and why it announces itself — downstream consumers (statement runs,
 * sub-ledger reconciliations, cached reports) are waiting for exactly this fact.
 *
 * Only an `open` period can be closed. The status is re-read under a row lock, so a close racing another
 * close does not double-close, and a close racing a *post* resolves one way or the other rather than
 * half-way: the posting engine locks the same row, so either the post completes and then the period
 * closes, or the period closes and the post is refused. There is no interleaving in which a posting
 * lands in a period that is already closed.
 */
final class ClosePeriodAction
{
    use GuardsPeriodTransitions;

    private const PERMISSION = 'accounting.period.close';

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function execute(FiscalPeriod $period, ?int $actorUserId = null): FiscalPeriod
    {
        $connection = $this->tenantConnection();

        $this->assertPermitted($this->permissions, $actorUserId, $period->company_id, self::PERMISSION, 'close');

        $connection->transaction(function () use ($period, $actorUserId, $connection): void {
            $status = $this->lockPeriodStatus($connection, $period);

            if ($status !== FiscalPeriod::STATUS_OPEN) {
                throw PeriodRuleException::notOpen($status);
            }

            FiscalPeriod::query()->whereKey($period->id)->update([
                'status' => FiscalPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actorUserId,
                'updated_by' => $actorUserId,
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: FiscalPeriodClosed::NAME,
                category: AuditCategory::DataMutation,
                entityType: 'fiscal_periods',
                entityId: $period->id,
                oldValues: ['status' => FiscalPeriod::STATUS_OPEN],
                newValues: ['status' => FiscalPeriod::STATUS_CLOSED],
                companyId: $period->company_id,
                actorUserId: $actorUserId,
            );
        });

        $period->refresh();

        // Emitted only after the close has COMMITTED, so a subscriber never acts on a month that might
        // still roll back open.
        event(FiscalPeriodClosed::fromPeriod($period));

        return $period;
    }
}
