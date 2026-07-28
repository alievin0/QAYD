<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Events\Accounting\JournalEntryPosted;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalEntryPostingService;

/**
 * Posts a journal entry through the one authorized posting path and announces it (S2-05,
 * docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine"). The Action is the thin orchestration seam
 * every caller uses (a controller in a later story, a cross-module listener, a scheduled job): it
 * delegates the whole atomic post to {@see JournalEntryPostingService} and, only once that transaction
 * has COMMITTED, emits {@see JournalEntryPosted} so subscribers react to a durable ledger fact — never a
 * post that might still roll back. If the post throws, nothing is emitted.
 */
final class PostJournalEntryAction
{
    public function __construct(private readonly JournalEntryPostingService $posting) {}

    public function execute(JournalEntry $entry, ?int $actorUserId = null): JournalEntry
    {
        $posted = $this->posting->post($entry, $actorUserId);

        event(JournalEntryPosted::fromEntry($posted));

        return $posted;
    }
}
