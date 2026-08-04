<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\LedgerActivityPage;
use App\Domain\Accounting\LedgerActivityQuery;
use App\Domain\Accounting\LedgerActivityRow;
use App\Support\Cursor;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Read the general ledger (S2-08, docs/accounting/GENERAL_LEDGER.md "# Reports — General Ledger").
 *
 * The GL is derived, never stored: every balance here is a `SUM(signed_base_amount)` over the
 * append-only `ledger_entries` projection the posting engine writes. Nothing in this class can change a
 * number — it has no writes, and the only way a figure it returns can move is for a new line to be
 * posted through {@see JournalEntryPostingService}. That is the point of a projection with one writer.
 *
 * Three decisions carry the design:
 *
 *  - **All the arithmetic happens in PostgreSQL.** The running balance is a `SUM(...) OVER (ORDER BY
 *    entry_date, id ROWS UNBOUNDED PRECEDING)` over `NUMERIC(19,4)`, seeded by binding the opening
 *    balance into the same expression. No money is ever added in PHP here, so there is no float to get
 *    wrong and no second implementation of "what the balance is" to drift from the database's.
 *
 *  - **Ordering is `(entry_date, id)` and the cursor is keyed on both.** Date order is what an account
 *    statement means; `id` breaks ties so the order is total and a sweep is repeatable. A cursor keyed
 *    on `id` alone — the common case the API standard describes — would skip a backdated line, which
 *    sorts early but carries a late id.
 *
 *  - **Tenancy is left to RLS.** These queries deliberately carry NO `company_id` predicate. They run
 *    on the RLS-enforced tenant connection, where the company boundary is the database's to enforce, so
 *    a forgotten `where` in a future reporting query cannot leak another company's lines. The story's
 *    acceptance criterion says exactly this, and the isolation test proves it by issuing a query with
 *    no scoping of its own.
 */
final class LedgerQueryService
{
    /**
     * Every posted line on one account, in date order, each with the running balance it produces.
     *
     * The read costs at most three queries and never a `COUNT(*)`: the Balance Forward, the page (read
     * one row long to know whether another page exists), and — only when continuing a sweep — the
     * catch-up sum that carries the running balance across the page boundary.
     */
    public function accountActivity(LedgerActivityQuery $query): LedgerActivityPage
    {
        $connection = DB::connection(TenantContext::connection());

        $openingBalance = $this->balanceForward($connection, $query);
        $seed = $query->hasCursor()
            ? $this->balanceThroughCursor($connection, $query, $openingBalance)
            : $openingBalance;

        [$rows, $nextCursor] = $this->page($connection, $query, $seed);

        // An empty page closes where it opened — for a page mid-sweep that is the carried seed, and for
        // an account with no activity at all it is zero.
        $closingBalance = $rows === [] ? $seed : $rows[count($rows) - 1]->runningBalance;

        return new LedgerActivityPage(
            rows: $rows,
            openingBalance: $openingBalance,
            closingBalance: $closingBalance,
            nextCursor: $nextCursor,
            perPage: $query->perPage,
        );
    }

    /**
     * The Balance Forward: the account's net balance from all posted activity BEFORE the requested
     * range. Zero when no `from` was given, because then nothing precedes the range.
     *
     * The non-date filters are applied here too, so that `opening + Σ(displayed lines) = closing` holds
     * for a filtered view as well. A filtered report whose opening balance ignored the filter would
     * show a running balance that reconciles to nothing on the page.
     *
     * @return numeric-string
     */
    private function balanceForward(ConnectionInterface $connection, LedgerActivityQuery $query): string
    {
        if ($query->from === null) {
            return '0.0000';
        }

        $sql = 'SELECT COALESCE(SUM(signed_base_amount), 0)::numeric(19,4)::text AS balance
                FROM ledger_entries
                WHERE account_id = ? AND entry_date < ?';
        $bindings = [$query->accountId, $query->from];

        if ($query->entryType !== null) {
            $sql .= ' AND entry_type = ?::journal_entry_type';
            $bindings[] = $query->entryType;
        }

        $row = $connection->selectOne($sql, $bindings);

        return $row === null ? '0.0000' : $this->money(SqlRow::string($row, 'balance'));
    }

    /**
     * The running balance the current page starts from: the Balance Forward plus every in-range line up
     * to and including the cursor row. Without this a second page would restart its running balance at
     * the opening balance and every figure on it would be wrong.
     *
     * @param  numeric-string  $openingBalance
     * @return numeric-string
     */
    private function balanceThroughCursor(
        ConnectionInterface $connection,
        LedgerActivityQuery $query,
        string $openingBalance,
    ): string {
        [$where, $bindings] = $this->filters($query);

        $sql = 'SELECT (?::numeric + COALESCE(SUM(signed_base_amount), 0))::numeric(19,4)::text AS balance
                FROM ledger_entries'.$where.' AND (entry_date, id) <= (?::date, ?::bigint)';

        $row = $connection->selectOne(
            $sql,
            [$openingBalance, ...$bindings, $query->afterDate, $query->afterId],
        );

        return $row === null ? $openingBalance : $this->money(SqlRow::string($row, 'balance'));
    }

    /**
     * One page of lines. Reads `perPage + 1` rows: if the extra row comes back there is another page,
     * and the cursor points at the last row actually returned. That is how the page is terminated
     * without a `COUNT(*)` — the standard makes `total` null on cursor responses precisely to avoid
     * counting a table this size on every request.
     *
     * @param  numeric-string  $seed
     * @return array{0: list<LedgerActivityRow>, 1: string|null}
     */
    private function page(ConnectionInterface $connection, LedgerActivityQuery $query, string $seed): array
    {
        [$where, $bindings] = $this->filters($query);
        $keyset = '';

        if ($query->hasCursor()) {
            $keyset = ' AND (entry_date, id) > (?::date, ?::bigint)';
            $bindings[] = $query->afterDate;
            $bindings[] = $query->afterId;
        }

        $sql = 'SELECT id,
                       journal_entry_id,
                       journal_line_id,
                       entry_date::text            AS entry_date,
                       entry_type::text            AS entry_type,
                       currency_code,
                       debit_amount::text          AS debit_amount,
                       credit_amount::text         AS credit_amount,
                       base_debit_amount::text     AS base_debit_amount,
                       base_credit_amount::text    AS base_credit_amount,
                       signed_base_amount::text    AS signed_base_amount,
                       (?::numeric + SUM(signed_base_amount) OVER (ORDER BY entry_date, id ROWS UNBOUNDED PRECEDING))
                           ::numeric(19,4)::text   AS running_balance,
                       description,
                       reference
                FROM ledger_entries'.$where.$keyset.'
                ORDER BY entry_date, id
                LIMIT '.($query->perPage + 1);

        $records = $connection->select($sql, [$seed, ...$bindings]);

        $hasMore = count($records) > $query->perPage;
        $records = array_slice($records, 0, $query->perPage);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = new LedgerActivityRow(
                id: SqlRow::int($record, 'id'),
                journalEntryId: SqlRow::int($record, 'journal_entry_id'),
                journalLineId: SqlRow::int($record, 'journal_line_id'),
                entryDate: SqlRow::string($record, 'entry_date'),
                entryType: SqlRow::string($record, 'entry_type'),
                currencyCode: SqlRow::string($record, 'currency_code'),
                debit: $this->money(SqlRow::string($record, 'debit_amount')),
                credit: $this->money(SqlRow::string($record, 'credit_amount')),
                baseDebit: $this->money(SqlRow::string($record, 'base_debit_amount')),
                baseCredit: $this->money(SqlRow::string($record, 'base_credit_amount')),
                signedBaseAmount: $this->money(SqlRow::string($record, 'signed_base_amount')),
                runningBalance: $this->money(SqlRow::string($record, 'running_balance')),
                description: $this->nullableString($record, 'description'),
                reference: $this->nullableString($record, 'reference'),
            );
        }

        $last = $rows === [] ? null : $rows[count($rows) - 1];
        $nextCursor = ($hasMore && $last !== null)
            ? Cursor::encode(['d' => $last->entryDate, 'i' => $last->id])
            : null;

        return [$rows, $nextCursor];
    }

    /**
     * The predicate shared by every query in this read. Note what is absent: `company_id`. The tenant
     * boundary belongs to RLS on this connection, not to a `where` clause a future query could forget.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function filters(LedgerActivityQuery $query): array
    {
        $where = ' WHERE account_id = ?';
        /** @var list<scalar> $bindings */
        $bindings = [$query->accountId];

        if ($query->from !== null) {
            $where .= ' AND entry_date >= ?';
            $bindings[] = $query->from;
        }

        if ($query->to !== null) {
            $where .= ' AND entry_date <= ?';
            $bindings[] = $query->to;
        }

        if ($query->entryType !== null) {
            $where .= ' AND entry_type = ?::journal_entry_type';
            $bindings[] = $query->entryType;
        }

        return [$where, $bindings];
    }

    /**
     * Narrow a money value read from the database to a `numeric-string`. The `NUMERIC(19,4)` columns and
     * the `::numeric(19,4)::text` casts above always yield numeric text; anything else is a schema or
     * driver invariant break, not a value to paper over.
     *
     * @return numeric-string
     */
    private function money(string $value): string
    {
        if (! is_numeric($value)) {
            throw new LogicException("Non-numeric amount read from the ledger: {$value}");
        }

        return $value;
    }

    /** A nullable text column (`description`, `reference`) read from a raw row. */
    private function nullableString(mixed $row, string $column): ?string
    {
        if (! is_object($row) || ! property_exists($row, $column)) {
            return null;
        }

        $value = $row->{$column};

        return is_string($value) ? $value : null;
    }
}
