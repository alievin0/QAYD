<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * What Accounting hands back once a journal is posted (SPRINT_03 Phase 0).
 *
 * Two facts, because two facts are all a calling module needs: the id it stores as its foreign key, and
 * the number a person reads on screen. Returning the `JournalEntry` model instead would hand Banking a
 * mutable Eloquent object with the whole ledger hanging off its relations — and the first time someone
 * called `->save()` on it, the posting engine would have stopped being the only way into the ledger.
 *
 * The absence of a status field is deliberate too. This type exists only as the result of a successful
 * post, so "is it posted?" has exactly one answer and does not need carrying.
 */
final readonly class PostedJournalEntry
{
    public function __construct(
        public int $journalEntryId,
        public string $journalNumber,
    ) {}
}
