<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use InvalidArgumentException;

/**
 * What another module hands Accounting when it needs a journal posted (SPRINT_03 Phase 0).
 *
 * Deliberately narrower than `JournalEntryData`, the DTO the Accounting module uses internally, and the
 * difference is the whole point of the seam: a caller outside Accounting cannot set `ai_generated`,
 * cannot supply a version, and cannot reach any field that governs how the posting engine behaves. It
 * states what happened in its own domain — a date, a currency, some lines — and Accounting decides
 * everything about how that becomes a ledger entry.
 *
 * Money is a decimal string, as everywhere else. A float would disagree with `NUMERIC(19,4)` in the
 * fourth decimal, and in a ledger that is not a rounding difference but a wrong number.
 */
final readonly class JournalDraft
{
    /**
     * @param  list<JournalDraftLine>  $lines
     */
    public function __construct(
        public string $journalDate,
        public string $entryType,
        public string $currencyCode,
        public array $lines,
        public ?string $reference = null,
        public ?string $memo = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('A journal draft needs at least one line.');
        }
    }
}
