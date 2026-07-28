<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Exceptions\Accounting\ClosedPeriodException;

/**
 * The seam between the posting engine and the fiscal calendar. {@see
 * \App\Services\Accounting\JournalEntryPostingService} depends ONLY on this interface — never directly on
 * `fiscal_years` or `fiscal_periods` — so the granularity of "which period is open for this date" can be
 * refined without touching the posting engine (the architectural requirement Ali added when authorizing
 * S2-05, and the outcome of the S2-05↔S2-07 sequencing review).
 *
 * S2-05 binds {@see FiscalYearCalendarResolver}, which resolves and locks the fiscal YEAR (the only
 * calendar that exists yet). S2-07 (Fiscal Periods) will transparently rebind this to a period-level
 * resolver; the posting engine, this interface, and {@see ResolvedFiscalPeriod} do not change.
 */
interface FiscalCalendarResolver
{
    /**
     * Resolve the OPEN accounting period covering $date for $companyId and lock it FOR UPDATE (so a
     * concurrent period close cannot race the post), returning the resolved year/period. The lock is
     * held for the caller's surrounding transaction.
     *
     * @param  string  $date  the journal date, `YYYY-MM-DD`
     *
     * @throws ClosedPeriodException when no period covers $date, or the covering period is not open
     */
    public function resolveOpenPeriodForPosting(int $companyId, string $date): ResolvedFiscalPeriod;
}
