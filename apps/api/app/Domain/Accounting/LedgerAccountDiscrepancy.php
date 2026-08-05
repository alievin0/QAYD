<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * One account where the rebuilt ledger and the stored projection disagree (SPRINT_02 §S2-14).
 *
 * Both sides are carried, never just the difference. "Account 1000 is out by 250.0000" tells whoever
 * reads the alert nothing about which side moved; "the journals say 25000.0000 and the ledger says
 * 24750.0000" says immediately whether a projection row went missing or an extra one appeared.
 *
 * `ledgerClosingBalance` is the same figure read a third way — through the ledger's own statement
 * reader — so the finding can be checked against the screen an accountant would open next.
 */
final readonly class LedgerAccountDiscrepancy
{
    /**
     * @param  numeric-string  $rebuiltSigned  net movement re-derived from posted journal lines
     * @param  numeric-string  $ledgerSigned  net movement as `ledger_entries` currently stores it
     * @param  numeric-string  $difference  rebuilt − ledger; signed, so its direction is part of the finding
     * @param  numeric-string|null  $ledgerClosingBalance  the live statement's closing balance, if read
     */
    public function __construct(
        public int $accountId,
        public string $accountCode,
        public string $rebuiltSigned,
        public string $ledgerSigned,
        public string $difference,
        public int $rebuiltLineCount,
        public int $ledgerLineCount,
        public ?string $ledgerClosingBalance = null,
    ) {}

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'rebuilt_signed' => $this->rebuiltSigned,
            'ledger_signed' => $this->ledgerSigned,
            'difference' => $this->difference,
            'rebuilt_line_count' => $this->rebuiltLineCount,
            'ledger_line_count' => $this->ledgerLineCount,
            'ledger_closing_balance' => $this->ledgerClosingBalance,
        ];
    }
}
