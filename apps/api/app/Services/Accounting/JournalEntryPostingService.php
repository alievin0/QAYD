<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Domain\Accounting\FiscalCalendarResolver;
use App\Domain\Accounting\ResolvedFiscalPeriod;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\PostingRuleException;
use App\Exceptions\Accounting\UnbalancedEntryException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerEntry;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Posting Engine (S2-05, docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine") — the ONE and only
 * code path in the platform authorized to write a posted line to the general ledger. Every future caller
 * (a human click, a cross-module event listener, a scheduled job, an approved AI draft) posts through
 * here; there is no bypass. The whole post is one transaction: it either lands wholly (balanced header,
 * permanent number, one ledger row per line) or not at all.
 *
 * The invariants it enforces, in order, all inside one `DB::transaction` on the RLS-enforced tenant
 * connection:
 *   1. Row-lock the entry `FOR UPDATE` and re-check it is in a postable status (draft/approved) — this
 *      also serializes a duplicate post and any concurrent draft edit (idempotency: a re-post of a
 *      `posted` entry is refused, projecting nothing; `uq_ledger_entries_journal_line` is the DB backstop).
 *   2. Re-derive the balance from the lines themselves (never the cached header totals) with ZERO
 *      tolerance, in both the entry currency and base currency → {@see assertBalanced}.
 *   3. Resolve + lock the open fiscal period for the date via the {@see FiscalCalendarResolver} seam
 *      (never touching `fiscal_years`/`fiscal_periods` directly) → a non-open period is a closed period.
 *   4. Verify every line's account is still active (postable) → an inactive account is refused.
 *   5. Assign the permanent `journal_number`, mark the entry posted with balanced cached totals, and
 *      project one immutable `ledger_entries` row per line (`signed_base_amount` normalized for SUM()).
 *
 * The after-commit `accounting.journal.posted` event is emitted by the wrapping
 * {@see PostJournalEntryAction}, not here — this service is pure DB work.
 */
final class JournalEntryPostingService
{
    /** The only statuses an entry may be posted FROM (JOURNAL_ENTRIES.md lifecycle). */
    private const POSTABLE_STATUSES = ['draft', 'approved'];

    public function __construct(
        private readonly FiscalCalendarResolver $calendar,
        private readonly JournalNumberAllocator $numbers,
    ) {}

    /**
     * Post $entry to the general ledger and return it refreshed as `posted`. Throws (rolling back the
     * whole transaction, so no partial post is ever visible) on any invariant failure.
     *
     * @throws PostingRuleException the entry is not postable, is empty, or hits an inactive account
     * @throws UnbalancedEntryException debits do not equal credits (either currency)
     * @throws ClosedPeriodException no open period covers the date
     */
    public function post(JournalEntry $entry, ?int $actorUserId = null): JournalEntry
    {
        $connection = DB::connection(TenantContext::connection());

        $connection->transaction(function () use ($entry, $actorUserId, $connection): void {
            // 1. Row-lock the header and re-read its authoritative status under the lock.
            $locked = $connection->selectOne(
                'SELECT company_id, journal_date, entry_type, status FROM journal_entries WHERE id = ? FOR UPDATE',
                [$entry->id],
            );

            if ($locked === null) {
                throw PostingRuleException::notPostable('unknown');
            }

            $status = SqlRow::string($locked, 'status');
            if (! in_array($status, self::POSTABLE_STATUSES, true)) {
                throw PostingRuleException::notPostable($status);
            }

            $companyId = SqlRow::int($locked, 'company_id');
            $journalDate = SqlRow::string($locked, 'journal_date');
            $entryType = SqlRow::string($locked, 'entry_type');

            // 2. Lines + zero-tolerance balance, re-derived from the lines table (never the header cache).
            /** @var Collection<int, JournalLine> $lines */
            $lines = JournalLine::query()
                ->where('journal_entry_id', $entry->id)
                ->orderBy('line_number')
                ->get();

            if ($lines->isEmpty()) {
                throw PostingRuleException::emptyEntry();
            }

            $totals = $this->assertBalanced($lines, $entry->currency_code);

            // 3. Resolve + lock the open fiscal period for the date (seam — no direct calendar coupling).
            $period = $this->calendar->resolveOpenPeriodForPosting($companyId, $journalDate);

            // 4. Every targeted account must still be active (postable).
            $this->assertPostableAccounts($lines);

            // 5. Permanent number → mark posted → project the ledger.
            $number = $this->numbers->allocate($companyId, $period->fiscalYearId, $period->fiscalYearName, $entryType);
            $postedAt = now();

            JournalEntry::query()->whereKey($entry->id)->update([
                'status' => 'posted',
                'journal_number' => $number,
                'fiscal_year_id' => $period->fiscalYearId,
                'fiscal_period_id' => $period->fiscalPeriodId,
                'posting_date' => $postedAt,
                'posted_at' => $postedAt,
                'posted_by' => $actorUserId,
                'locked' => true,
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
                'base_total_debit' => $totals['base_debit'],
                'base_total_credit' => $totals['base_credit'],
                'updated_by' => $actorUserId,
                'updated_at' => $postedAt,
            ]);

            $this->projectLines($lines, $entry, $companyId, $period, $entryType, $journalDate, $postedAt);
        });

        return $entry->refresh();
    }

    /**
     * Re-derive `SUM(debit)`/`SUM(credit)` (and the base-currency pair) from the lines and require exact
     * equality — zero tolerance — in BOTH currencies. Returns the four balanced totals for the header
     * cache. Throws {@see UnbalancedEntryException} on any mismatch, before anything is written.
     *
     * @param  Collection<int, JournalLine>  $lines
     * @return array{debit: numeric-string, credit: numeric-string, base_debit: numeric-string, base_credit: numeric-string}
     */
    private function assertBalanced($lines, string $currencyCode): array
    {
        $debit = '0';
        $credit = '0';
        $baseDebit = '0';
        $baseCredit = '0';

        foreach ($lines as $line) {
            $debit = bcadd($debit, $this->money($line->debit), 4);
            $credit = bcadd($credit, $this->money($line->credit), 4);
            $baseDebit = bcadd($baseDebit, $this->money($line->base_debit), 4);
            $baseCredit = bcadd($baseCredit, $this->money($line->base_credit), 4);
        }

        if (bccomp($debit, $credit, 4) !== 0) {
            throw new UnbalancedEntryException($debit, $credit, bcsub($debit, $credit, 4), $currencyCode);
        }
        if (bccomp($baseDebit, $baseCredit, 4) !== 0) {
            throw new UnbalancedEntryException($baseDebit, $baseCredit, bcsub($baseDebit, $baseCredit, 4), $currencyCode);
        }

        return ['debit' => $debit, 'credit' => $credit, 'base_debit' => $baseDebit, 'base_credit' => $baseCredit];
    }

    /**
     * Every distinct account a line targets must be active; an inactive account can carry no new posting
     * (docs/accounting/JOURNAL_ENTRIES.md "# Locking Rules" §4). Reads run RLS-scoped, so an account not
     * visible in the active company is treated exactly as non-postable.
     *
     * @param  Collection<int, JournalLine>  $lines
     */
    private function assertPostableAccounts($lines): void
    {
        $accountIds = [];
        foreach ($lines as $line) {
            $accountIds[$line->account_id] = $line->account_id;
        }

        /** @var array<int, string> $statuses */
        $statuses = Account::query()
            ->whereIn('id', $accountIds)
            ->pluck('status', 'id')
            ->all();

        foreach ($accountIds as $accountId) {
            if (($statuses[$accountId] ?? null) !== Account::STATUS_ACTIVE) {
                throw PostingRuleException::inactiveAccount($accountId);
            }
        }
    }

    /**
     * Write one immutable {@see LedgerEntry} per posted line — the 1:1 GL projection. `signed_base_amount`
     * is `+base_debit` for a debit leg and `-base_credit` for a credit leg, so an account balance is a
     * single `SUM(signed_base_amount)`. Since S2-07 the seam resolves the fiscal PERIOD as well as the
     * year, so `fiscal_period_id` is always populated (the column is `NOT NULL` with a real FK); the
     * dimension/source copies land when `journal_lines` carries them.
     *
     * @param  Collection<int, JournalLine>  $lines
     */
    private function projectLines(
        $lines,
        JournalEntry $entry,
        int $companyId,
        ResolvedFiscalPeriod $period,
        string $entryType,
        string $journalDate,
        \DateTimeInterface $postedAt,
    ): void {
        foreach ($lines as $line) {
            $signed = bcsub($this->money($line->base_debit), $this->money($line->base_credit), 4);

            $ledger = new LedgerEntry;
            $ledger->forceFill([
                'company_id' => $companyId,
                'journal_entry_id' => $entry->id,
                'journal_line_id' => $line->id,
                'account_id' => $line->account_id,
                'fiscal_year_id' => $period->fiscalYearId,
                'fiscal_period_id' => $period->fiscalPeriodId,
                'entry_date' => $journalDate,
                'posted_at' => $postedAt,
                'entry_type' => $entryType,
                'currency_code' => $line->currency_code,
                'debit_amount' => $line->debit,
                'credit_amount' => $line->credit,
                'base_debit_amount' => $line->base_debit,
                'base_credit_amount' => $line->base_credit,
                'signed_base_amount' => $signed,
                'description' => $line->description,
                'reference' => $entry->reference,
            ]);
            $ledger->save();
        }
    }

    /**
     * Narrow a money value read from the database (an Eloquent attribute is `mixed` to the analyser) to a
     * `numeric-string` for bcmath. The `NUMERIC(19,4)` columns always return numeric text; a non-numeric
     * value would be a schema/driver invariant break.
     *
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';

        if (! is_numeric($string)) {
            throw new \LogicException('Non-numeric money value read from the ledger.');
        }

        return $string;
    }
}
