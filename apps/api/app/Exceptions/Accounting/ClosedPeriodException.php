<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Domain\Accounting\FiscalCalendarResolver;
use App\Exceptions\DomainException;

/**
 * No OPEN accounting period covers the entry's `journal_date`, so it cannot be posted
 * (docs/accounting/JOURNAL_ENTRIES.md "# Fiscal Period Rules", "# Locking Rules" §5). Thrown by the
 * fiscal-calendar seam ({@see FiscalCalendarResolver}) when the date falls in no
 * fiscal period, or in one that is not open (locked/closed/future). Renders `422 CLOSED_PERIOD`.
 *
 * S2-05 resolves at fiscal-YEAR granularity (the only calendar that exists yet); S2-07 refines the seam
 * to fiscal-PERIOD granularity without changing this exception or the posting engine.
 */
final class ClosedPeriodException extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(string $message, array $meta)
    {
        parent::__construct($message);

        $this->field = 'journal_date';
        $this->meta = $meta;
    }

    /** No fiscal period of any kind covers $date for the company. */
    public static function noPeriodForDate(string $date): self
    {
        return new self(
            "No fiscal period covers the date {$date}; define an open fiscal year for it before posting.",
            ['journal_date' => $date],
        );
    }

    /** A fiscal period covers $date but is not open (its status blocks posting). */
    public static function periodNotOpen(string $date, string $status): self
    {
        return new self(
            "The fiscal period covering {$date} is '{$status}'; only an open period can be posted into.",
            ['journal_date' => $date, 'status' => $status],
        );
    }

    public function errorCode(): string
    {
        return 'CLOSED_PERIOD';
    }

    public function errorStatus(): int
    {
        return 422;
    }
}
