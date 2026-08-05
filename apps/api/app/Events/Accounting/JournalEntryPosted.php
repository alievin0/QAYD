<?php

declare(strict_types=1);

namespace App\Events\Accounting;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The `accounting.journal.posted` domain event (docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine"
 * step 13). Emitted by {@see PostJournalEntryAction} AFTER the posting
 * transaction commits, so downstream subscribers (Reverb real-time push, webhooks, AR/AP sub-ledger
 * caches, Trial Balance invalidation) only ever react to a fact that is durably in the ledger.
 *
 * S2-13 makes it broadcastable. What travels is a **refresh notification**, not a fact: an open ledger
 * screen learns that something was posted and re-reads the API, which remains the only place a figure
 * comes from. Nothing may act on the payload itself — see {@see self::broadcastWith()}.
 *
 * **Architectural note (deliberate and temporary — ADR-0011, TD-30).** ADR-0006 routes domain events
 * through a transactional outbox that a relay delivers. That outbox does not exist yet, so this event
 * broadcasts directly. The trade is bounded by what a refresh hint can lose: a dropped push leaves a
 * screen stale until the person reloads — never a lost or invented fact, because the ledger was already
 * committed before this event was constructed. A consumer whose correctness depends on receiving every
 * event must wait for the outbox rather than subscribe here.
 */
final class JournalEntryPosted implements ShouldBroadcast
{
    use Dispatchable;

    /** The logical event name (for broadcasting / webhooks once those listeners are wired). */
    public const NAME = 'accounting.journal.posted';

    /**
     * The name this event travels under on the socket (SPRINT_02 §S2-13).
     *
     * Shorter than {@see self::NAME} on purpose: `NAME` identifies the event inside the domain, where
     * `accounting.` separates it from other modules' journals; the wire name is already scoped by the
     * company channel it arrives on, and it is what a client hard-codes into a `.listen()` call.
     */
    public const BROADCAST_AS = 'journal.posted';

    /**
     * @param  numeric-string  $baseTotal
     */
    public function __construct(
        public readonly int $companyId,
        public readonly int $journalEntryId,
        public readonly string $journalNumber,
        public readonly string $entryType,
        public readonly string $baseTotal,
        public readonly string $currencyCode,
    ) {}

    /** Build the event from a freshly-posted entry. */
    public static function fromEntry(JournalEntry $entry): self
    {
        $baseTotal = $entry->base_total_debit;

        return new self(
            companyId: (int) $entry->company_id,
            journalEntryId: (int) $entry->id,
            journalNumber: (string) $entry->journal_number,
            entryType: (string) $entry->entry_type,
            baseTotal: is_numeric($baseTotal) ? $baseTotal : '0',
            currencyCode: (string) $entry->currency_code,
        );
    }

    /**
     * The company's private refresh channel, or none at all.
     *
     * Resolved from the internal company id at broadcast time rather than carried on the event, so the
     * event's own shape stays what S2-05 defined. The channel is named by the company's **UUID**: the
     * internal id is not a value clients are given, and a channel name is written into client code.
     *
     * An unresolvable company yields no channel, so the broadcast is simply dropped. That is the right
     * failure for a refresh hint — the posting it describes is already committed, and there is no one
     * to tell.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $uuid = Company::query()->whereKey($this->companyId)->value('uuid');

        if (! is_string($uuid) || $uuid === '') {
            return [];
        }

        return [new PrivateChannel('company.'.$uuid)];
    }

    /** The wire name a client listens for. */
    public function broadcastAs(): string
    {
        return self::BROADCAST_AS;
    }

    /**
     * The compact projection (SPRINT_02 §S2-13).
     *
     * Enough for a screen to decide whether what it is showing is now stale, and no more. It is
     * deliberately NOT the entry: a client that rendered these figures would be reading the ledger off
     * a socket, which is the one thing ADR-0006 forbids realtime to become. The amount is here so a
     * dashboard can decide a refresh is worth it, not so it can be added to anything.
     *
     * `company_id` is absent although the event carries it. The channel already establishes which
     * company this is, and the internal id is not a value clients are given.
     *
     * @return array<string, string|int>
     */
    public function broadcastWith(): array
    {
        return [
            'journal_entry_id' => $this->journalEntryId,
            'journal_number' => $this->journalNumber,
            'entry_type' => $this->entryType,
            'base_total' => $this->baseTotal,
            'currency_code' => $this->currencyCode,
        ];
    }
}
