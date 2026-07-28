<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The open accounting period a posting resolves to, returned (already row-locked) by
 * {@see FiscalCalendarResolver}. An immutable value object: it names the fiscal YEAR the posting belongs
 * to and — once fiscal periods exist (S2-07) — the fiscal PERIOD within it.
 *
 * In S2-05 only the fiscal year is resolvable ({@see FiscalYearCalendarResolver}); `fiscalPeriodId` is
 * therefore null. When S2-07 lands period-level resolution it will populate `fiscalPeriodId`, and the
 * posting engine — which already threads this value straight through to the ledger projection — needs no
 * change. The `fiscalYearName` (e.g. `FY2026`) is the label the permanent journal number embeds.
 */
final readonly class ResolvedFiscalPeriod
{
    public function __construct(
        public int $fiscalYearId,
        public string $fiscalYearName,
        public ?int $fiscalPeriodId = null,
    ) {}
}
