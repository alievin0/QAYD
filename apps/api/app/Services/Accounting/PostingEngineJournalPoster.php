<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Actions\Accounting\PostJournalEntryAction;
use App\Data\Accounting\JournalEntryData;
use App\Data\Accounting\JournalLineData;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\JournalDraftLine;
use App\Domain\Accounting\JournalPoster;
use App\Domain\Accounting\PostedJournalEntry;

/**
 * Accounting's side of the {@see JournalPoster} seam (SPRINT_03 Phase 0).
 *
 * An adapter and nothing more. It translates a foreign module's {@see JournalDraft} into the DTO the
 * Accounting Actions already take, runs the same two Actions the HTTP controller runs, and translates
 * the result back. **No accounting rule is implemented, duplicated, or relaxed here** — balance, account
 * postability, period state, numbering, and the ledger projection all stay where they were, in the
 * posting engine, which remains the single authorized path into the ledger.
 *
 * Draft-then-post rather than one call, because that is what the engine actually does: a journal exists
 * as a draft first, and posting is a separate transition with its own guards. Collapsing the two here
 * would mean reimplementing that transition, which is precisely what this class must not do.
 *
 * `ai_generated` is never set. The draft type has no such field, so a module calling through this seam
 * cannot mark an entry as machine-produced — and equally cannot launder one by omitting the flag. When
 * an AI path does post, it will post through its own governed route with its own principal, not this one.
 */
final class PostingEngineJournalPoster implements JournalPoster
{
    public function __construct(
        private readonly CreateJournalEntryAction $create,
        private readonly PostJournalEntryAction $post,
    ) {}

    public function post(JournalDraft $draft, ?int $actorUserId = null): PostedJournalEntry
    {
        $entry = $this->create->execute($this->toEntryData($draft), $actorUserId);

        // Any accounting refusal — unbalanced, closed period, inactive account — propagates untouched.
        $posted = $this->post->execute($entry, $actorUserId);

        return new PostedJournalEntry(
            journalEntryId: (int) $posted->id,
            journalNumber: (string) $posted->journal_number,
        );
    }

    private function toEntryData(JournalDraft $draft): JournalEntryData
    {
        return new JournalEntryData(
            journalDate: $draft->journalDate,
            entryType: $draft->entryType,
            currencyCode: $draft->currencyCode,
            lines: array_map(
                static fn (JournalDraftLine $line): JournalLineData => new JournalLineData(
                    accountId: $line->accountId,
                    debit: $line->debit,
                    credit: $line->credit,
                    description: $line->description,
                ),
                $draft->lines,
            ),
            reference: $draft->reference,
            memo: $draft->memo,
        );
    }
}
