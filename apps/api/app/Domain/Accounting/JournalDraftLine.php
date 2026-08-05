<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use InvalidArgumentException;

/**
 * One line of a {@see JournalDraft} (SPRINT_03 Phase 0).
 *
 * A line is debit OR credit, never both — the one shape rule enforced here rather than deferred, because
 * a two-sided line is a mistake in the calling module's own arithmetic, and it is cheaper to reject it at
 * the boundary than to let the posting engine discover it later as an imbalance.
 *
 * Everything else is Accounting's business. Whether the account may be posted to, whether the entry
 * balances, whether the period is open — none of that is asked here. The seam carries a proposal, not a
 * pre-validated fact.
 */
final readonly class JournalDraftLine
{
    /** @var numeric-string */
    public string $debit;

    /** @var numeric-string */
    public string $credit;

    /**
     * The amounts are NOT promoted, and that is the point rather than a style choice: the *parameters*
     * are untrusted strings arriving from another module, while the *properties* are numeric strings
     * that have passed the check below. Promoting them would collapse the two into one type and make the
     * analyser certify, at the boundary, a guarantee that only exists after validation.
     */
    public function __construct(
        public int $accountId,
        string $debit = '0',
        string $credit = '0',
        public ?string $description = null,
    ) {
        if (! is_numeric($debit) || ! is_numeric($credit)) {
            throw new InvalidArgumentException('Journal line amounts must be numeric strings.');
        }

        if (bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0) {
            throw new InvalidArgumentException('A journal line carries a debit or a credit, never both.');
        }

        $this->debit = $debit;
        $this->credit = $credit;
    }

    /** A debit line for $amount on $accountId. */
    public static function debit(int $accountId, string $amount, ?string $description = null): self
    {
        return new self($accountId, $amount, '0', $description);
    }

    /** A credit line for $amount on $accountId. */
    public static function credit(int $accountId, string $amount, ?string $description = null): self
    {
        return new self($accountId, '0', $amount, $description);
    }
}
