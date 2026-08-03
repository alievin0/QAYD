<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;
use App\Models\FiscalPeriod;

/**
 * A fiscal-period lifecycle rule was violated (S2-07, docs/accounting/GENERAL_LEDGER.md
 * "# FISCAL CALENDAR", "# Permissions").
 *
 * Distinct from {@see ClosedPeriodException}, which is what a *posting* hits when its date lands in a
 * period that will not accept it. This one is what a *period action* hits: closing something that is not
 * open, locking something that is not closed, reopening something that was never closed, or attempting
 * any of it without the permission. State violations render 409 (the request was well-formed, the record
 * is in the wrong state); a missing permission renders 403.
 */
final class PeriodRuleException extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly string $catalogCode,
        private readonly int $status,
        string $message,
        array $meta = [],
    ) {
        parent::__construct($message);

        $this->field = 'status';
        $this->meta = $meta;
    }

    /** Close was attempted on a period that is not `open`. */
    public static function notOpen(string $status): self
    {
        return new self(
            'PERIOD_NOT_OPEN',
            409,
            "Only an open period can be closed; this one is '{$status}'.",
            ['status' => $status, 'required' => FiscalPeriod::STATUS_OPEN],
        );
    }

    /** Lock was attempted on a period that is not `closed` — a period is closed first, then locked. */
    public static function notClosed(string $status): self
    {
        return new self(
            'PERIOD_NOT_CLOSED',
            409,
            "Only a closed period can be locked; this one is '{$status}'. Close it first.",
            ['status' => $status, 'required' => FiscalPeriod::STATUS_CLOSED],
        );
    }

    /** Reopen was attempted on a period that is neither `closed` nor `locked`. */
    public static function notReopenable(string $status): self
    {
        return new self(
            'PERIOD_NOT_REOPENABLE',
            409,
            "Only a closed or locked period can be reopened; this one is '{$status}'.",
            ['status' => $status],
        );
    }

    /**
     * The actor lacks the permission the action requires. Naming the permission is deliberate: the
     * caller is an authorized user of the system who needs to know which grant to ask for, and the
     * permission catalogue is not a secret.
     */
    public static function forbidden(string $permission, string $action): self
    {
        return new self(
            'PERMISSION_DENIED',
            403,
            "You do not have permission to {$action} a fiscal period ({$permission}).",
            ['permission' => $permission],
        );
    }

    /**
     * Reopening a closed period is an audited exception to the close, so it must say why. A reopen with
     * no stated reason leaves an audit trail that records the act but not the justification.
     */
    public static function reasonRequired(): self
    {
        return new self(
            'REOPEN_REASON_REQUIRED',
            422,
            'Reopening a fiscal period requires a stated reason.',
            [],
        );
    }

    public function errorCode(): string
    {
        return $this->catalogCode;
    }

    public function errorStatus(): int
    {
        return $this->status;
    }
}
