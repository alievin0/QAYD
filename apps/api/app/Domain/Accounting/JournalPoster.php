<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\JournalRuleException;
use App\Exceptions\Accounting\PostingRuleException;
use App\Exceptions\Accounting\UnbalancedEntryException;

/**
 * The only way another module puts something in the ledger (SPRINT_03 Phase 0).
 *
 * MODULE_ARCHITECTURE and ADR-0006 require modules to talk through service interfaces rather than reach
 * into each other's internals, and this is Accounting's side of that boundary. Banking's clear-and-post
 * depends on this one method; it never sees `PostJournalEntryAction`, `CreateJournalEntryAction`,
 * `JournalEntry`, or the `journal_lines` table. Accounting can therefore restructure any of them without
 * a banking change — and, more usefully, Banking cannot acquire a second way into the ledger by
 * accident, because there is nothing else exposed to reach for.
 *
 * **One method, on purpose.** Every widening is a decision to take deliberately: a `postDraft` returning
 * an unposted entry would hand callers a half-written ledger row, and a `reverse` here would let another
 * module undo accounting history without passing the accounting rules that govern it. When Banking needs
 * reversal (out of scope this sprint), it gets an explicit method with its own review, not a
 * general-purpose door.
 *
 * **Transactions belong to the caller.** This does not open one. S3-03 requires the bank balance and the
 * journal to move inside a single transaction, so the caller opens it and this participates — the
 * implementation's own writes nest as savepoints. A seam that opened its own transaction would quietly
 * make that atomicity impossible to express.
 *
 * Accounting's exceptions travel out unchanged. An unbalanced draft, a closed period, an inactive
 * account: the caller sees exactly what the posting engine decided, because a wrapped exception would be
 * Banking's opinion about an accounting rule.
 */
interface JournalPoster
{
    /**
     * Post $draft and return what it became.
     *
     * @throws UnbalancedEntryException when debits and credits do not agree, in either currency
     * @throws PostingRuleException when the draft cannot be posted — an inactive account, no lines
     * @throws JournalRuleException when the draft is not a valid journal in the first place
     * @throws ClosedPeriodException when the date falls in a period that is not open
     */
    public function post(JournalDraft $draft, ?int $actorUserId = null): PostedJournalEntry;
}
