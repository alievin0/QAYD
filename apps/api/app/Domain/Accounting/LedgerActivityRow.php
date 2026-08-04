<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * One posted line on an account statement (S2-08), with the running balance it produces.
 *
 * `runningBalance` is computed at query time and never stored — persisting a balance per line would
 * mean rewriting every later row on every new posting, which is exactly the mutable derived state this
 * ledger exists to avoid (docs/accounting/GENERAL_LEDGER.md "# Running Balance"). Every money field is
 * carried as a `numeric-string`, never a float.
 */
final readonly class LedgerActivityRow
{
    /**
     * @param  numeric-string  $debit
     * @param  numeric-string  $credit
     * @param  numeric-string  $baseDebit
     * @param  numeric-string  $baseCredit
     * @param  numeric-string  $signedBaseAmount
     * @param  numeric-string  $runningBalance
     */
    public function __construct(
        public int $id,
        public int $journalEntryId,
        public int $journalLineId,
        public string $entryDate,
        public string $entryType,
        public string $currencyCode,
        public string $debit,
        public string $credit,
        public string $baseDebit,
        public string $baseCredit,
        public string $signedBaseAmount,
        public string $runningBalance,
        public ?string $description,
        public ?string $reference,
    ) {}
}
