<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * A computed trial balance (S2-09, docs/accounting/TRIAL_BALANCE.md).
 *
 * `variance` is `totalDebit − totalCredit` and, over a correctly posted ledger, is exactly zero: every
 * journal entry balances, `signed_base_amount` is `+debit − credit`, so summing it across all accounts
 * must vanish. That makes the trial balance a genuine PROOF rather than a report — a non-zero variance
 * is not a rounding artifact to be presented, it is evidence that something got into the ledger that
 * should not have, and the snapshot records it as `out_of_balance` rather than quietly balancing it.
 */
final readonly class TrialBalance
{
    /**
     * @param  list<TrialBalanceRow>  $rows
     * @param  numeric-string  $totalDebit
     * @param  numeric-string  $totalCredit
     * @param  numeric-string  $variance
     */
    public function __construct(
        public array $rows,
        public string $totalDebit,
        public string $totalCredit,
        public string $variance,
        public string $asOfDate,
        public string $periodStartDate,
    ) {}

    /**
     * Whether the ledger proves out, within the company's rounding tolerance.
     *
     * The tolerance exists because TRIAL_BALANCE.md allows one for multi-currency rounding; it is NOT a
     * licence to accept a real imbalance, which is why the default is 0.0050 — half a fils — and why
     * the variance itself is always stored rather than discarded once it passes.
     *
     * @param  numeric-string  $tolerance
     */
    public function isBalanced(string $tolerance = '0.0050'): bool
    {
        return bccomp($this->absoluteVariance(), $tolerance, 4) <= 0;
    }

    /** @return numeric-string */
    public function absoluteVariance(): string
    {
        return bccomp($this->variance, '0', 4) < 0
            ? bcsub('0', $this->variance, 4)
            : $this->variance;
    }
}
