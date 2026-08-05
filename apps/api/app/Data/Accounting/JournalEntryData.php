<?php

declare(strict_types=1);

namespace App\Data\Accounting;

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Actions\Accounting\UpdateJournalDraftAction;

/**
 * The validated input for a journal draft — {@see CreateJournalEntryAction} and
 * {@see UpdateJournalDraftAction}. An immutable DTO; the Actions never receive a raw array. Money is a
 * string (`NUMERIC(19,4)`), never a float. `aiConfidence` (0..1) is required when `aiGenerated` is true —
 * the Action enforces it, and the S2-03 `chk_je_ai_confidence` CHECK + `trg_no_ai_autopost` trigger are
 * the database backstops (an AI-generated entry can only ever be created as a draft).
 */
final readonly class JournalEntryData
{
    /**
     * @param  list<JournalLineData>  $lines
     * @param  numeric-string  $exchangeRate
     */
    public function __construct(
        public string $journalDate,
        public string $entryType,
        public string $currencyCode,
        public array $lines,
        public string $exchangeRate = '1',
        public ?string $reference = null,
        public ?string $memo = null,
        public bool $aiGenerated = false,
        public ?float $aiConfidence = null,
    ) {}
}
