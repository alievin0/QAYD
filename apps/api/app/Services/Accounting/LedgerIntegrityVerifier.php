<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\LedgerAccountDiscrepancy;
use App\Domain\Accounting\LedgerActivityQuery;
use App\Domain\Accounting\LedgerIntegrityReport;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rebuild one company's ledger from its posted journals and compare (SPRINT_02 §S2-14).
 *
 * `ledger_entries` is a projection: every row is derivable from a posted `journal_lines` row, and the
 * posting engine is the only thing that may write one. This service checks that the derivation still
 * holds — it re-derives the whole projection from the journals and asks whether the stored one still
 * matches. The journals are the source of truth in that comparison; a difference means the projection
 * drifted, never the reverse.
 *
 * **The rebuild happens in a scratch table and the live projection is never written to.** That is not
 * only the specification's instruction, it is the point: `trg_ledger_entries_append_only` refuses
 * UPDATE and DELETE even to the schema owner, so a check that rewrote the ledger would be dismantling
 * the guarantee it exists to verify. The scratch table is `TEMP … ON COMMIT DROP`, which makes cleanup
 * a property of the transaction rather than of this code remembering — so a crash mid-rebuild leaves
 * nothing behind on a pooled connection.
 *
 * Tenant isolation is inherited rather than re-implemented: every read runs on the RLS-scoped app
 * connection inside the caller's tenant context, so "this company's journals" is enforced by the
 * database, and there is deliberately no `company_id` predicate anywhere below.
 *
 * The statement side comes from {@see TrialBalanceService} and the per-account detail from
 * {@see LedgerQueryService}. Neither is re-implemented here: a checker that computed balances its own
 * way would be verifying its own arithmetic rather than the ledger, and would agree with itself in
 * precisely the case that matters.
 */
final class LedgerIntegrityVerifier
{
    /** Accounts detailed through the statement reader before the report stops asking. */
    private const DETAIL_LIMIT = 25;

    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly LedgerQueryService $ledger,
    ) {}

    public function verify(int $companyId): LedgerIntegrityReport
    {
        $connection = DB::connection(TenantContext::connection());

        if ($connection->transactionLevel() === 0) {
            // ON COMMIT DROP needs a transaction, and so does the tenant GUC every read below depends
            // on. Failing loudly beats silently verifying someone else's ledger.
            throw new RuntimeException(
                'The ledger integrity rebuild must run inside a transaction with an established tenant context.',
            );
        }

        $this->buildScratch($connection);

        $rebuiltRowCount = SqlRow::int(
            $connection->selectOne('SELECT COUNT(*) AS n FROM ledger_rebuild'),
            'n',
        );
        $ledgerRowCount = SqlRow::int(
            $connection->selectOne('SELECT COUNT(*) AS n FROM ledger_entries'),
            'n',
        );

        $discrepancies = $this->compareAccounts($connection);
        [$rebuiltDebit, $rebuiltCredit] = $this->rebuiltTotals($connection);
        [$statementDebit, $statementCredit] = $this->statementTotals($connection);

        return new LedgerIntegrityReport(
            companyId: $companyId,
            rebuiltRowCount: $rebuiltRowCount,
            ledgerRowCount: $ledgerRowCount,
            discrepancies: $discrepancies,
            rebuiltDebitTotal: $rebuiltDebit,
            rebuiltCreditTotal: $rebuiltCredit,
            statementDebitTotal: $statementDebit,
            statementCreditTotal: $statementCredit,
        );
    }

    /**
     * Re-derive the projection into the scratch table.
     *
     * The source condition is `posted_at IS NOT NULL`, not a status list. A posted entry can later
     * become `reversed`, and its ledger rows stay — correctly, since the ledger is append-only and the
     * reversal is a separate posting. Keying on the timestamp asks "was this ever posted", which is the
     * question the projection actually answers, and it cannot fall out of step with the status enum the
     * way a hard-coded IN list would.
     *
     * `signed_base_amount` is re-derived exactly as the posting engine derives it — base debit minus
     * base credit — so a stored value that disagrees is the finding rather than an artefact.
     */
    private function buildScratch(ConnectionInterface $connection): void
    {
        $connection->statement(<<<'SQL'
            CREATE TEMP TABLE ledger_rebuild (
                account_id         BIGINT         NOT NULL,
                entry_date         DATE           NOT NULL,
                base_debit_amount  NUMERIC(19,4)  NOT NULL,
                base_credit_amount NUMERIC(19,4)  NOT NULL,
                signed_base_amount NUMERIC(19,4)  NOT NULL
            ) ON COMMIT DROP
            SQL);

        $connection->statement(<<<'SQL'
            INSERT INTO ledger_rebuild (
                account_id, entry_date, base_debit_amount, base_credit_amount, signed_base_amount
            )
            SELECT jl.account_id,
                   je.journal_date,
                   jl.base_debit,
                   jl.base_credit,
                   (jl.base_debit - jl.base_credit)::numeric(19,4)
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE je.posted_at IS NOT NULL
            SQL);
    }

    /**
     * Every account whose rebuilt movement differs from the stored one.
     *
     * A FULL OUTER JOIN rather than an inner one, because the two most alarming findings are exactly
     * the ones an inner join hides: an account the journals know and the ledger does not, and an
     * account the ledger carries that no posted journal explains. `IS DISTINCT FROM` compares the two
     * sides without NULL swallowing the answer.
     *
     * @return list<LedgerAccountDiscrepancy>
     */
    private function compareAccounts(ConnectionInterface $connection): array
    {
        $records = $connection->select(<<<'SQL'
            WITH rebuilt AS (
                SELECT account_id,
                       SUM(signed_base_amount)::numeric(19,4) AS signed,
                       COUNT(*)                               AS lines
                FROM ledger_rebuild GROUP BY account_id
            ), stored AS (
                SELECT account_id,
                       SUM(signed_base_amount)::numeric(19,4) AS signed,
                       COUNT(*)                               AS lines
                FROM ledger_entries GROUP BY account_id
            )
            SELECT COALESCE(r.account_id, s.account_id)              AS account_id,
                   COALESCE(a.code, '(unknown)')                     AS account_code,
                   COALESCE(r.signed, 0)::numeric(19,4)::text        AS rebuilt_signed,
                   COALESCE(s.signed, 0)::numeric(19,4)::text        AS ledger_signed,
                   (COALESCE(r.signed, 0) - COALESCE(s.signed, 0))
                       ::numeric(19,4)::text                         AS difference,
                   COALESCE(r.lines, 0)                              AS rebuilt_lines,
                   COALESCE(s.lines, 0)                              AS ledger_lines
            FROM rebuilt r
            FULL OUTER JOIN stored s ON s.account_id = r.account_id
            LEFT JOIN accounts a ON a.id = COALESCE(r.account_id, s.account_id)
            WHERE r.signed IS DISTINCT FROM s.signed
               OR r.lines  IS DISTINCT FROM s.lines
            ORDER BY account_code, account_id
            SQL);

        $discrepancies = [];

        foreach (array_values($records) as $index => $record) {
            $accountId = SqlRow::int($record, 'account_id');

            $discrepancies[] = new LedgerAccountDiscrepancy(
                accountId: $accountId,
                accountCode: SqlRow::string($record, 'account_code'),
                rebuiltSigned: $this->money(SqlRow::string($record, 'rebuilt_signed')),
                ledgerSigned: $this->money(SqlRow::string($record, 'ledger_signed')),
                difference: $this->money(SqlRow::string($record, 'difference')),
                rebuiltLineCount: SqlRow::int($record, 'rebuilt_lines'),
                ledgerLineCount: SqlRow::int($record, 'ledger_lines'),
                // Bounded: a company whose entire ledger drifted should produce one alert, not a
                // statement read per account.
                ledgerClosingBalance: $index < self::DETAIL_LIMIT
                    ? $this->ledger->accountActivity(new LedgerActivityQuery(accountId: $accountId))
                        ->closingBalance
                    : null,
            );
        }

        return $discrepancies;
    }

    /**
     * The rebuild's own debit and credit totals.
     *
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function rebuiltTotals(ConnectionInterface $connection): array
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT COALESCE(SUM(base_debit_amount), 0)::numeric(19,4)::text  AS debit,
                   COALESCE(SUM(base_credit_amount), 0)::numeric(19,4)::text AS credit
            FROM ledger_rebuild
            SQL);

        return [
            $this->money(SqlRow::string($row, 'debit')),
            $this->money(SqlRow::string($row, 'credit')),
        ];
    }

    /**
     * The same totals as the live trial balance reports them.
     *
     * Read over the full span of posted activity so the statement covers everything the rebuild does;
     * the range comes from the data and never from the clock, which is what keeps a nightly job's
     * result reproducible when someone re-runs it by hand the next morning.
     *
     * A ledger with no rows ties trivially at zero — a brand-new company is intact, not suspicious.
     *
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function statementTotals(ConnectionInterface $connection): array
    {
        $span = $connection->selectOne(<<<'SQL'
            SELECT to_char(MIN(entry_date), 'YYYY-MM-DD') AS first_date,
                   to_char(MAX(entry_date), 'YYYY-MM-DD') AS last_date
            FROM ledger_entries
            SQL);

        $first = $this->nullableString($span, 'first_date');
        $last = $this->nullableString($span, 'last_date');

        if ($first === null || $last === null) {
            return ['0.0000', '0.0000'];
        }

        $balance = $this->trialBalance->computeForRange($first, $last);

        return [$this->money($balance->totalDebit), $this->money($balance->totalCredit)];
    }

    private function nullableString(mixed $row, string $column): ?string
    {
        if (! is_object($row)) {
            return null;
        }

        $value = $row->{$column} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return numeric-string
     */
    private function money(string $value): string
    {
        if (! is_numeric($value)) {
            throw new RuntimeException('Non-numeric money value read while verifying the ledger.');
        }

        return $value;
    }
}
