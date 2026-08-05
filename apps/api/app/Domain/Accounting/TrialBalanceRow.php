<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * One account's line on a trial balance (S2-09, docs/accounting/TRIAL_BALANCE.md "# Snapshot Line").
 *
 * Opening / period / closing are each split into a debit and a credit column, only ever one of which
 * is non-zero — that is the shape an accountant reads, and the shape `chk_tbsl_debit_xor_credit_closing`
 * enforces on the stored line. The split is computed in PostgreSQL from `signed_base_amount`, so the
 * sign convention has exactly one definition.
 *
 * `isAbnormalBalance` flags an account sitting on the opposite side from its normal balance — a bank
 * account in credit, a payable in debit. Not an error, but the first thing a reviewer wants to see.
 */
final readonly class TrialBalanceRow
{
    /**
     * @param  numeric-string  $openingDebit
     * @param  numeric-string  $openingCredit
     * @param  numeric-string  $periodDebit
     * @param  numeric-string  $periodCredit
     * @param  numeric-string  $closingDebit
     * @param  numeric-string  $closingCredit
     */
    public function __construct(
        public int $accountId,
        public string $accountCode,
        public string $accountNameEn,
        public ?string $accountNameAr,
        public int $accountTypeId,
        public string $normalBalance,
        public string $openingDebit,
        public string $openingCredit,
        public string $periodDebit,
        public string $periodCredit,
        public string $closingDebit,
        public string $closingCredit,
        public bool $isAbnormalBalance,
        public int $sourceLineCount,
    ) {}
}
