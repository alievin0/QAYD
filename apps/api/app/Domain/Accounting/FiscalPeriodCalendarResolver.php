<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Exceptions\Accounting\ClosedPeriodException;
use App\Models\FiscalPeriod;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The S2-07 implementation of {@see FiscalCalendarResolver}: it resolves a posting date to the company's
 * fiscal PERIOD (the month), locks THAT row `FOR UPDATE`, and refuses anything that is not `open`
 * (docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR", SPRINT_02 §S2-07 — "period rows are the ones
 * the posting engine locks FOR UPDATE").
 *
 * This replaces {@see FiscalYearCalendarResolver} behind the seam, and the posting engine does not change
 * by a line — which is the entire reason the seam was introduced before the engine was written.
 *
 * Two things move with it:
 *
 *  - **The gate gets finer.** A year-level gate cannot express "January is closed but February is open",
 *    which is the actual way a business closes its books. Now it can.
 *  - **The lock gets narrower** (TD-13). `FOR UPDATE OF p` takes the lock on the period row only; the
 *    fiscal year is joined for its name (the permanent journal number embeds it) but is deliberately not
 *    locked. Under the year-level resolver every concurrent post in a company serialized on one row for
 *    a whole year; now two postings into different months do not contend at all, and two into the same
 *    month serialize only against each other — which they must, because that is what makes a close
 *    landing mid-post safe.
 *
 * The date match is inclusive of both endpoints, matching the `daterange(start_date, end_date, '[]')`
 * no-overlap exclusion constraint, so exactly one period can ever match. The query runs on the
 * RLS-enforced tenant connection: it can only see, and only lock, the active company's own periods.
 */
final class FiscalPeriodCalendarResolver implements FiscalCalendarResolver
{
    public function resolveOpenPeriodForPosting(int $companyId, string $date): ResolvedFiscalPeriod
    {
        $period = DB::connection(TenantContext::connection())->selectOne(
            <<<'SQL'
                SELECT p.id AS period_id, p.status AS period_status,
                       y.id AS year_id, y.name AS year_name
                FROM fiscal_periods p
                JOIN fiscal_years y ON y.id = p.fiscal_year_id
                WHERE p.company_id = ?
                  AND ? BETWEEN p.start_date AND p.end_date
                  AND p.deleted_at IS NULL
                FOR UPDATE OF p
                SQL,
            [$companyId, $date],
        );

        if ($period === null) {
            throw ClosedPeriodException::noPeriodForDate($date);
        }

        $status = SqlRow::string($period, 'period_status');

        if ($status !== FiscalPeriod::STATUS_OPEN) {
            throw ClosedPeriodException::periodNotOpen($date, $status);
        }

        return new ResolvedFiscalPeriod(
            fiscalYearId: SqlRow::int($period, 'year_id'),
            fiscalYearName: SqlRow::string($period, 'year_name'),
            fiscalPeriodId: SqlRow::int($period, 'period_id'),
        );
    }
}
