<?php

declare(strict_types=1);

namespace App\Data\Accounting;

/**
 * One validated debit/credit leg for a journal draft (S2-04). Immutable. Money is a string
 * (`NUMERIC(19,4)`), never a float. Exactly one of `debit`/`credit` is expected to be greater than zero;
 * the Action enforces that (and the S2-03 `chk_jl_one_sided` CHECK is the database backstop).
 */
final readonly class JournalLineData
{
    /**
     * @param  numeric-string  $debit
     * @param  numeric-string  $credit
     */
    public function __construct(
        public int $accountId,
        public string $debit = '0',
        public string $credit = '0',
        public ?string $description = null,
    ) {}
}
