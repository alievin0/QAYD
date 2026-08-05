<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The result of one company's ledger rebuild (SPRINT_02 §S2-14).
 *
 * The job answers a single question — does `ledger_entries` still say exactly what the posted journals
 * say — and this is the answer, in the three independent ways it can be wrong:
 *
 *  - **Counts.** A projection row went missing, or an extra one appeared.
 *  - **Per-account movement.** Every row is present but one carries a different amount, which counts
 *    alone would never catch.
 *  - **The statement.** The trial balance derived from the live projection no longer ties to the
 *    journals it came from — the same drift seen from the direction an auditor would see it.
 *
 * `isIntact()` requires all three to agree. A report that passed on counts alone would be a check that
 * almost cannot fail, which is worse than no check at all, because it would be trusted.
 */
final readonly class LedgerIntegrityReport
{
    /**
     * @param  list<LedgerAccountDiscrepancy>  $discrepancies
     * @param  numeric-string  $rebuiltDebitTotal
     * @param  numeric-string  $rebuiltCreditTotal
     * @param  numeric-string  $statementDebitTotal  the live trial balance's total debit
     * @param  numeric-string  $statementCreditTotal  the live trial balance's total credit
     */
    public function __construct(
        public int $companyId,
        public int $rebuiltRowCount,
        public int $ledgerRowCount,
        public array $discrepancies,
        public string $rebuiltDebitTotal,
        public string $rebuiltCreditTotal,
        public string $statementDebitTotal,
        public string $statementCreditTotal,
    ) {}

    /** Whether the stored ledger still reproduces the journals exactly. */
    public function isIntact(): bool
    {
        return $this->rebuiltRowCount === $this->ledgerRowCount
            && $this->discrepancies === []
            && $this->statementTies();
    }

    /**
     * Whether the live trial balance's totals match the rebuild's.
     *
     * Compared with `bccomp` at scale 4 rather than `===`: both sides are `NUMERIC(19,4)` rendered as
     * text, and `"0.0000"` and `"0.00"` are the same number written two ways. String equality here
     * would report drift that does not exist.
     */
    public function statementTies(): bool
    {
        return bccomp($this->rebuiltDebitTotal, $this->statementDebitTotal, 4) === 0
            && bccomp($this->rebuiltCreditTotal, $this->statementCreditTotal, 4) === 0;
    }

    /**
     * The structured body of the alert. Flat and scalar so it survives a log driver unchanged.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'intact' => $this->isIntact(),
            'rebuilt_row_count' => $this->rebuiltRowCount,
            'ledger_row_count' => $this->ledgerRowCount,
            'row_count_delta' => $this->rebuiltRowCount - $this->ledgerRowCount,
            'statement_ties' => $this->statementTies(),
            'rebuilt_debit_total' => $this->rebuiltDebitTotal,
            'rebuilt_credit_total' => $this->rebuiltCreditTotal,
            'statement_debit_total' => $this->statementDebitTotal,
            'statement_credit_total' => $this->statementCreditTotal,
            'discrepancy_count' => count($this->discrepancies),
            'discrepancies' => array_map(
                static fn (LedgerAccountDiscrepancy $d): array => $d->toArray(),
                $this->discrepancies,
            ),
        ];
    }
}
