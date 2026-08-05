<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;
use App\Services\Accounting\JournalEntryPostingService;

/**
 * The double-entry invariant was violated at posting: `SUM(debit) <> SUM(credit)` (in the entry's own
 * currency or, independently, in base currency), re-derived server-side from the lines with ZERO
 * tolerance (docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine → Balancing tolerance"). Thrown by
 * {@see JournalEntryPostingService} BEFORE any ledger projection row is
 * written, so a post either lands wholly balanced or not at all. Renders `422 BALANCE_MISMATCH`.
 */
final class UnbalancedEntryException extends DomainException
{
    /**
     * @param  numeric-string  $totalDebit
     * @param  numeric-string  $totalCredit
     * @param  numeric-string  $difference
     */
    public function __construct(string $totalDebit, string $totalCredit, string $difference, string $currencyCode)
    {
        parent::__construct(
            "The journal entry is not balanced: total debit {$totalDebit} does not equal total credit "
            ."{$totalCredit} ({$currencyCode})."
        );

        $this->field = 'lines';
        $this->meta = [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => $difference,
            'currency_code' => $currencyCode,
        ];
    }

    public function errorCode(): string
    {
        return 'BALANCE_MISMATCH';
    }

    public function errorStatus(): int
    {
        return 422;
    }
}
