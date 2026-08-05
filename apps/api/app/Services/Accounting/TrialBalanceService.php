<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\TrialBalance;
use App\Domain\Accounting\TrialBalanceRow;
use App\Exceptions\Accounting\TrialBalanceRuleException;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Compute a trial balance (S2-09, docs/accounting/TRIAL_BALANCE.md).
 *
 * A single aggregate over `ledger_entries`, grouped by account. Nothing here writes and nothing is
 * cached: recomputing always reproduces the same figures from the same posted lines, which is the
 * property that lets a stored snapshot be checked against the ledger at any time.
 *
 * **Why this is a proof, not a report.** Every journal entry is balanced by the posting engine, and
 * `signed_base_amount` is `+base_debit − base_credit`. So summing it across every account of a company
 * must be exactly zero. `SUM(closing_debit) − SUM(closing_credit)` is that same sum rearranged, which
 * means a non-zero variance cannot be explained away as presentation — it means a line reached the
 * ledger that should not have. A run like that is recorded as `out_of_balance`, never balanced for it.
 *
 * Consistent with {@see LedgerQueryService}: all the arithmetic (the debit/credit split, the totals)
 * happens over `NUMERIC(19,4)` — in PostgreSQL for the split, in bcmath for the totals, never a float —
 * and the queries carry NO `company_id` predicate, because the tenant boundary is RLS's on this
 * connection rather than a `where` clause a reporting query could forget.
 */
final class TrialBalanceService
{
    /**
     * The trial balance for one fiscal period: balances brought forward to the period start, the
     * period's own movement, and the closing balance each account carries out.
     *
     * @throws TrialBalanceRuleException when the period is not visible to the active company
     */
    public function compute(int $fiscalPeriodId): TrialBalance
    {
        $connection = DB::connection(TenantContext::connection());

        $period = $connection->selectOne(
            'SELECT start_date::text AS start_date, end_date::text AS end_date
             FROM fiscal_periods WHERE id = ? AND deleted_at IS NULL',
            [$fiscalPeriodId],
        );

        if ($period === null) {
            throw TrialBalanceRuleException::unknownPeriod($fiscalPeriodId);
        }

        return $this->computeForRange(
            SqlRow::string($period, 'start_date'),
            SqlRow::string($period, 'end_date'),
        );
    }

    /**
     * The same aggregate over an explicit date range. `$periodStart` splits opening from period
     * movement; `$asOf` bounds the whole computation, so the closing balance is "the ledger as at this
     * date" and a later backdated posting simply produces a different — and correct — answer next run.
     */
    public function computeForRange(string $periodStart, string $asOf): TrialBalance
    {
        $connection = DB::connection(TenantContext::connection());

        $records = $connection->select(
            <<<'SQL'
                SELECT a.id                                         AS account_id,
                       a.code                                       AS account_code,
                       a.name_en                                    AS account_name_en,
                       a.name_ar                                    AS account_name_ar,
                       a.account_type_id                            AS account_type_id,
                       a.normal_balance::text                       AS normal_balance,
                       GREATEST( COALESCE(SUM(le.signed_base_amount) FILTER (WHERE le.entry_date < ?), 0), 0)
                           ::numeric(19,4)::text                    AS opening_debit,
                       GREATEST(-COALESCE(SUM(le.signed_base_amount) FILTER (WHERE le.entry_date < ?), 0), 0)
                           ::numeric(19,4)::text                    AS opening_credit,
                       COALESCE(SUM(le.base_debit_amount)  FILTER (WHERE le.entry_date >= ?), 0)
                           ::numeric(19,4)::text                    AS period_debit,
                       COALESCE(SUM(le.base_credit_amount) FILTER (WHERE le.entry_date >= ?), 0)
                           ::numeric(19,4)::text                    AS period_credit,
                       GREATEST( COALESCE(SUM(le.signed_base_amount), 0), 0)
                           ::numeric(19,4)::text                    AS closing_debit,
                       GREATEST(-COALESCE(SUM(le.signed_base_amount), 0), 0)
                           ::numeric(19,4)::text                    AS closing_credit,
                       COUNT(le.id)                                 AS source_line_count
                FROM ledger_entries le
                JOIN accounts a ON a.id = le.account_id
                WHERE le.entry_date <= ?
                GROUP BY a.id, a.code, a.name_en, a.name_ar, a.account_type_id, a.normal_balance
                ORDER BY a.code, a.id
                SQL,
            [$periodStart, $periodStart, $periodStart, $periodStart, $asOf],
        );

        $rows = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($records as $record) {
            $closingDebit = $this->money(SqlRow::string($record, 'closing_debit'));
            $closingCredit = $this->money(SqlRow::string($record, 'closing_credit'));
            $normalBalance = SqlRow::string($record, 'normal_balance');

            $rows[] = new TrialBalanceRow(
                accountId: SqlRow::int($record, 'account_id'),
                accountCode: SqlRow::string($record, 'account_code'),
                accountNameEn: SqlRow::string($record, 'account_name_en'),
                accountNameAr: $this->nullableString($record, 'account_name_ar'),
                accountTypeId: SqlRow::int($record, 'account_type_id'),
                normalBalance: $normalBalance,
                openingDebit: $this->money(SqlRow::string($record, 'opening_debit')),
                openingCredit: $this->money(SqlRow::string($record, 'opening_credit')),
                periodDebit: $this->money(SqlRow::string($record, 'period_debit')),
                periodCredit: $this->money(SqlRow::string($record, 'period_credit')),
                closingDebit: $closingDebit,
                closingCredit: $closingCredit,
                // Sitting on the opposite side from the account's normal balance.
                isAbnormalBalance: ($normalBalance === 'debit' && bccomp($closingCredit, '0', 4) > 0)
                    || ($normalBalance === 'credit' && bccomp($closingDebit, '0', 4) > 0),
                sourceLineCount: SqlRow::int($record, 'source_line_count'),
            );

            $totalDebit = bcadd($totalDebit, $closingDebit, 4);
            $totalCredit = bcadd($totalCredit, $closingCredit, 4);
        }

        return new TrialBalance(
            rows: $rows,
            totalDebit: $this->money($totalDebit),
            totalCredit: $this->money($totalCredit),
            variance: $this->money(bcsub($totalDebit, $totalCredit, 4)),
            asOfDate: $asOf,
            periodStartDate: $periodStart,
        );
    }

    /**
     * @return numeric-string
     */
    private function money(string $value): string
    {
        if (! is_numeric($value)) {
            throw new LogicException("Non-numeric amount read from the ledger aggregate: {$value}");
        }

        return $value;
    }

    private function nullableString(mixed $row, string $column): ?string
    {
        if (! is_object($row) || ! property_exists($row, $column)) {
            return null;
        }

        $value = $row->{$column};

        return is_string($value) ? $value : null;
    }
}
