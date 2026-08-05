<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use Illuminate\Database\ConnectionInterface;

/**
 * Fills a fiscal year with its monthly periods (S2-07, docs/accounting/GENERAL_LEDGER.md
 * "# FISCAL CALENDAR").
 *
 * A fiscal year with no periods is unpostable — the posting engine resolves against periods, not years —
 * so period generation is not a convenience, it is part of creating a usable year. Onboarding calls this
 * in the same transaction that creates the company's first fiscal year, and the S2-07 migration runs the
 * identical rule in SQL over the years that already existed.
 *
 * Two details carry weight:
 *
 *  - **Coverage is exact.** Each period is clamped to the year's own boundaries, so a fiscal year that
 *    starts or ends mid-month (a part-year at company setup, or a 1 Apr – 31 Mar calendar) is covered
 *    with no gap and no overlap. A gap would make a date unpostable for no stated reason; an overlap
 *    would make "which period is this?" ambiguous, and the exclusion constraint would reject it anyway.
 *  - **`period_number` is the ordinal within the year, never the calendar month.** Every period-based
 *    report groups by the period, so a non-January fiscal year needs no special-casing, and a 13-period
 *    retail calendar drops in without touching anything downstream.
 *
 * Status is inherited from the parent year rather than computed from today's date: generating periods
 * must never decide, on its own, that a month is closed.
 */
final class MonthlyFiscalPeriodGenerator
{
    /**
     * Insert the monthly periods covering `[$startDate, $endDate]` for `$fiscalYearId`.
     *
     * Written through the passed connection so the caller controls the transaction and the privilege
     * level: onboarding runs it on the owner connection before any tenant GUC exists, while an in-tenant
     * caller runs it RLS-scoped.
     *
     * @param  string  $yearStatus  the parent fiscal year's status; periods inherit it
     * @return int the number of periods the year now has
     */
    public static function generate(
        ConnectionInterface $connection,
        int $companyId,
        int $fiscalYearId,
        string $startDate,
        string $endDate,
        string $yearStatus = 'open',
        ?int $actorUserId = null,
    ): int {
        $status = match ($yearStatus) {
            'open' => 'open',
            'future' => 'future',
            default => 'closed',
        };

        $connection->statement(
            <<<'SQL'
                INSERT INTO fiscal_periods (
                    company_id, fiscal_year_id, period_type, period_number, name,
                    start_date, end_date, status, created_by, updated_by
                )
                SELECT
                    ?, ?, 'monthly'::period_type,
                    (ROW_NUMBER() OVER (ORDER BY m.month_start))::SMALLINT,
                    to_char(m.month_start, 'Mon YYYY'),
                    GREATEST(m.month_start::DATE, ?::DATE),
                    LEAST((m.month_start + INTERVAL '1 month - 1 day')::DATE, ?::DATE),
                    ?::fiscal_period_status,
                    ?, ?
                FROM generate_series(
                    date_trunc('month', ?::DATE),
                    date_trunc('month', ?::DATE),
                    INTERVAL '1 month'
                ) AS m(month_start)
                SQL,
            [
                $companyId, $fiscalYearId,
                $startDate, $endDate,
                $status,
                $actorUserId, $actorUserId,
                $startDate, $endDate,
            ],
        );

        $created = $connection->scalar(
            'SELECT COUNT(*) FROM fiscal_periods WHERE fiscal_year_id = ?',
            [$fiscalYearId],
        );

        return is_numeric($created) ? (int) $created : 0;
    }
}
