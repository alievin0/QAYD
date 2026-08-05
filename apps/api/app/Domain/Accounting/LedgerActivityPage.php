<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * One page of account activity (S2-08).
 *
 * `openingBalance` is the Balance Forward for the whole requested range — the balance the account
 * carried into it from all earlier posted activity — and it is what seeds the first row's running
 * balance (docs/accounting/GENERAL_LEDGER.md "# Balance Forward", "# Running Balance"). It is
 * identical on every page of a sweep, because it describes the range, not the page.
 *
 * `closingBalance` is the running balance after the LAST row on this page. On the final page it is the
 * range's closing balance; mid-sweep it is simply where the next page picks up. The identity
 * `openingBalance + Σ(signedBaseAmount over the range) = closingBalance of the last page` always
 * holds, which is what makes the report self-checking.
 *
 * `nextCursor` is null exactly when this is the last page — the service knows by reading one row more
 * than it returns, never by counting the table.
 */
final readonly class LedgerActivityPage
{
    /**
     * @param  list<LedgerActivityRow>  $rows
     * @param  numeric-string  $openingBalance
     * @param  numeric-string  $closingBalance
     */
    public function __construct(
        public array $rows,
        public string $openingBalance,
        public string $closingBalance,
        public ?string $nextCursor,
        public int $perPage,
    ) {}
}
