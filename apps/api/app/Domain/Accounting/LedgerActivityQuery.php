<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The parameters of one account-activity read (S2-08, docs/accounting/GENERAL_LEDGER.md
 * "# Reports — General Ledger"). An immutable value object, so the service that runs the query cannot
 * be handed a half-built request or quietly mutate one mid-flight.
 *
 * `afterDate`/`afterId` together are the decoded keyset cursor — the last row the caller already has.
 * They are a PAIR because the activity report is ordered by `(entry_date, id)`, not by `id`: a
 * backdated posting lands in the middle of the date order while carrying the highest id, so an
 * id-only cursor would skip it on the next page. Keying the cursor on exactly the sort columns is what
 * makes a page-by-page sweep lossless.
 *
 * Dimension filters (branch / cost centre / project / department / customer / vendor) are named in the
 * S2-08 story but are NOT expressible yet: `ledger_entries` does not carry those columns, because the
 * tables they would reference do not exist (TD-14). They arrive with the modules that own them.
 */
final readonly class LedgerActivityQuery
{
    /** The standard's default page size for high-volume ledger data. */
    public const DEFAULT_PER_PAGE = 50;

    /** The standard's cap for high-volume ledger data; a larger request is clamped, never rejected. */
    public const MAX_PER_PAGE = 200;

    public function __construct(
        public int $accountId,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $entryType = null,
        public ?string $afterDate = null,
        public ?int $afterId = null,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /** True when this read continues a previous page rather than starting one. */
    public function hasCursor(): bool
    {
        return $this->afterDate !== null && $this->afterId !== null;
    }
}
