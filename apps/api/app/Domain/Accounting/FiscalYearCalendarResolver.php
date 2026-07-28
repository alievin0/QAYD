<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Exceptions\Accounting\ClosedPeriodException;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The S2-05 implementation of {@see FiscalCalendarResolver}: it resolves the posting date to the
 * company's fiscal YEAR and locks that row `FOR UPDATE` so a concurrent year close cannot race the post
 * (docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine" step 4, "# Fiscal Period Rules"). `fiscal_years`
 * is the only calendar that exists in Sprint 2 so far; month-level `fiscal_periods` are S2-07, which
 * rebinds the seam to a finer resolver without touching the posting engine (the accepted S2-05↔S2-07
 * sequencing decision).
 *
 * The `open` year status is the postable gate; `future`/`closing`/`closed` are refused as a closed
 * period. The query runs on the RLS-enforced tenant connection, so it can only ever see — and lock — the
 * active company's own fiscal years. Date matching is inclusive of both endpoints, matching the
 * `daterange(start_date, end_date, '[]')` no-overlap constraint the fiscal-year table enforces.
 */
final class FiscalYearCalendarResolver implements FiscalCalendarResolver
{
    public function resolveOpenPeriodForPosting(int $companyId, string $date): ResolvedFiscalPeriod
    {
        $year = DB::connection(TenantContext::connection())->selectOne(
            <<<'SQL'
                SELECT id, name, status
                FROM fiscal_years
                WHERE company_id = ?
                  AND ? BETWEEN start_date AND end_date
                  AND deleted_at IS NULL
                FOR UPDATE
                SQL,
            [$companyId, $date],
        );

        if ($year === null) {
            throw ClosedPeriodException::noPeriodForDate($date);
        }

        $status = SqlRow::string($year, 'status');

        if ($status !== 'open') {
            throw ClosedPeriodException::periodNotOpen($date, $status);
        }

        return new ResolvedFiscalPeriod(
            fiscalYearId: SqlRow::int($year, 'id'),
            fiscalYearName: SqlRow::string($year, 'name'),
        );
    }
}
