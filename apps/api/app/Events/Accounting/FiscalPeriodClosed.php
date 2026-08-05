<?php

declare(strict_types=1);

namespace App\Events\Accounting;

use App\Actions\Accounting\ClosePeriodAction;
use App\Models\FiscalPeriod;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The `accounting.period.closed` domain event (S2-07, docs/accounting/GENERAL_LEDGER.md
 * "# FISCAL CALENDAR"). Emitted by {@see ClosePeriodAction} AFTER the close transaction commits, so a
 * subscriber only ever reacts to a month that is durably shut.
 *
 * It matters more than a status change usually would: closing a period is the signal every downstream
 * consumer waits on — the trial balance for that month is now final and cacheable, the sub-ledger
 * reconciliations for it can be frozen, and a scheduled statement run can proceed. S2-07 defines and
 * emits the event; the concrete listeners belong to the stories that own those consumers.
 */
final class FiscalPeriodClosed
{
    use Dispatchable;

    /** The logical event name (for broadcasting / webhooks once those listeners are wired). */
    public const NAME = 'accounting.period.closed';

    public function __construct(
        public readonly int $companyId,
        public readonly int $fiscalPeriodId,
        public readonly int $fiscalYearId,
        public readonly string $periodName,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $closedByUserId,
    ) {}

    /** Build the event from a freshly-closed period. */
    public static function fromPeriod(FiscalPeriod $period): self
    {
        return new self(
            companyId: $period->company_id,
            fiscalPeriodId: $period->id,
            fiscalYearId: $period->fiscal_year_id,
            periodName: $period->name,
            startDate: $period->start_date,
            endDate: $period->end_date,
            closedByUserId: $period->closed_by,
        );
    }
}
