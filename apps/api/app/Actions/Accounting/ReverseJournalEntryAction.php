<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Data\Accounting\JournalEntryData;
use App\Data\Accounting\JournalLineData;
use App\Exceptions\Accounting\ReversalRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reverse a POSTED journal entry (S2-06, docs/accounting/JOURNAL_ENTRIES.md "# Journal Entry Lifecycle").
 *
 * Posted history is immutable, so a correction is never an edit — it is a NEW, balanced mirror entry with
 * every debit and credit exchanged, posted through the SAME {@see PostJournalEntryAction} that every other
 * posting uses. There is no special reversal path into the ledger. The original is left byte-identical
 * except for its status and its `reversal_entry_id` back-link, so the pair is traceable in both
 * directions: original → `reversal_entry_id` → mirror, and mirror → `reversed_entry_id` → original.
 *
 * Guards, in order: the original must be `posted`; it must not already carry a reversal (also enforced by
 * the `uq_je_one_reversal` partial unique index, so a concurrent double-reverse cannot slip through); and
 * segregation of duties — the entry's creator may not reverse their own entry, relaxed only for a company
 * with a single active member, which is derived from the membership data rather than a caller flag.
 */
final class ReverseJournalEntryAction
{
    public function __construct(
        private readonly CreateJournalEntryAction $create,
        private readonly PostJournalEntryAction $post,
    ) {}

    /**
     * @param  string  $reason  recorded on the mirror's memo — a reversal without a stated reason is not
     *                          an auditable correction
     */
    public function execute(
        JournalEntry $entry,
        string $reason,
        ?int $actorUserId = null,
        ?string $reversalDate = null,
    ): JournalEntry {
        $connection = DB::connection(TenantContext::connection());

        /** @var JournalEntry $mirror */
        $mirror = $connection->transaction(function () use ($entry, $reason, $actorUserId, $reversalDate, $connection): JournalEntry {
            // Row-lock the original and re-read its authoritative state under the lock, so a concurrent
            // reversal or archive cannot race this one.
            $original = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->first();

            if ($original === null) {
                throw ReversalRuleException::notPosted('unknown');
            }

            if ($original->status !== JournalEntry::STATUS_POSTED) {
                throw ReversalRuleException::notPosted($original->status);
            }

            if ($original->reversal_entry_id !== null) {
                throw ReversalRuleException::alreadyReversed($original->reversal_entry_id);
            }

            $this->assertSegregationOfDuties(
                $connection,
                $original->company_id,
                $original->created_by,
                $actorUserId,
            );

            $mirror = $this->createMirrorDraft($original, $reason, $actorUserId, $reversalDate);

            // Stamp the reversal linkage while the mirror is still a draft — once posted it is terminal.
            JournalEntry::query()->whereKey($mirror->id)->update([
                'is_reversal' => true,
                'reversed_entry_id' => $original->id,
            ]);

            // Post the mirror through the one authorized path. It is balanced by construction (every
            // line's debit and credit are exchanged), so the zero-tolerance balance check passes.
            $posted = $this->post->execute($mirror->refresh(), $actorUserId);

            // Only now mark the original reversed and link it forward. This header UPDATE is permitted:
            // the S2-03 immutability trigger protects journal LINES, and no line is touched here.
            JournalEntry::query()->whereKey($original->id)->update([
                'status' => JournalEntry::STATUS_REVERSED,
                'reversal_entry_id' => $posted->id,
                'updated_by' => $actorUserId,
                'updated_at' => now(),
            ]);

            return $posted;
        });

        $entry->refresh();

        return $mirror;
    }

    /**
     * Build and create the mirror as a DRAFT: same date (or an explicit reversal date), same currency and
     * rate, with every line's debit and credit exchanged. Amounts are copied verbatim as strings, so no
     * arithmetic — and therefore no rounding — happens anywhere on the reversal path.
     */
    private function createMirrorDraft(
        JournalEntry $original,
        string $reason,
        ?int $actorUserId,
        ?string $reversalDate,
    ): JournalEntry {
        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::query()
            ->where('journal_entry_id', $original->id)
            ->orderBy('line_number')
            ->get();

        $mirrorLines = array_values(
            $lines
                ->map(fn (JournalLine $line): JournalLineData => new JournalLineData(
                    accountId: $line->account_id,
                    debit: $this->money($line->credit),   // exchanged
                    credit: $this->money($line->debit),   // exchanged
                    description: $line->description,
                ))
                ->all()
        );

        $originalNumber = $original->journal_number;

        return $this->create->execute(
            new JournalEntryData(
                journalDate: $reversalDate ?? $original->journal_date,
                entryType: 'reversal',
                currencyCode: $original->currency_code,
                lines: $mirrorLines,
                exchangeRate: $this->money($original->exchange_rate),
                reference: $originalNumber,
                memo: "Reversal of {$originalNumber}: {$reason}",
            ),
            $actorUserId,
        );
    }

    /**
     * The creator of an entry may not reverse it (SPRINT_02 §S2-06). The documented exception is a
     * company with exactly one active member, where insisting on a second pair of eyes would make the
     * product unusable; that carve-out is read from `company_users`, so a caller cannot assert it.
     */
    private function assertSegregationOfDuties(
        ConnectionInterface $connection,
        int $companyId,
        ?int $createdBy,
        ?int $actorUserId,
    ): void {
        if ($createdBy === null || $actorUserId === null || $createdBy !== $actorUserId) {
            return;
        }

        $activeMembers = $connection->scalar(
            "SELECT COUNT(*) FROM company_users WHERE company_id = ? AND status = 'active'",
            [$companyId],
        );

        if (is_numeric($activeMembers) && (int) $activeMembers > 1) {
            throw ReversalRuleException::segregationOfDuties();
        }
    }

    /**
     * Narrow a money/rate string read from the database to a `numeric-string`. The `NUMERIC` columns
     * always return numeric text; a non-numeric value would be a schema or driver invariant break.
     *
     * @return numeric-string
     */
    private function money(string $value): string
    {
        if (! is_numeric($value)) {
            throw new LogicException("Non-numeric amount read from a journal line: {$value}");
        }

        return $value;
    }
}
