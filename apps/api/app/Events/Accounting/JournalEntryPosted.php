<?php

declare(strict_types=1);

namespace App\Events\Accounting;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Models\JournalEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The `accounting.journal.posted` domain event (docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine"
 * step 13). Emitted by {@see PostJournalEntryAction} AFTER the posting
 * transaction commits, so downstream subscribers (Reverb real-time push, webhooks, AR/AP sub-ledger
 * caches, Trial Balance invalidation) only ever react to a fact that is durably in the ledger.
 *
 * S2-05 defines and emits the event; wiring concrete listeners (broadcasting, cache busting) is later
 * cross-module work. The payload is the minimal set a subscriber needs without re-reading the entry.
 */
final class JournalEntryPosted
{
    use Dispatchable;

    /** The logical event name (for broadcasting / webhooks once those listeners are wired). */
    public const NAME = 'accounting.journal.posted';

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
}
