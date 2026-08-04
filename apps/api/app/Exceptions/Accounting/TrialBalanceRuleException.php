<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;

/**
 * A trial-balance lifecycle or scope rule was violated (S2-09, docs/accounting/TRIAL_BALANCE.md).
 *
 * State violations render 409 — the request was well formed, the snapshot is in the wrong state; a
 * missing permission renders 403; an unusable scope renders 422.
 */
final class TrialBalanceRuleException extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly string $catalogCode,
        private readonly int $status,
        string $message,
        ?string $field = null,
        array $meta = [],
    ) {
        parent::__construct($message);

        $this->field = $field;
        $this->meta = $meta;
    }

    /** The requested fiscal period is not visible to this company (or does not exist). */
    public static function unknownPeriod(int $fiscalPeriodId): self
    {
        return new self(
            'UNKNOWN_FISCAL_PERIOD',
            422,
            "No fiscal period {$fiscalPeriodId} exists for this company.",
            'fiscal_period_id',
            ['fiscal_period_id' => $fiscalPeriodId],
        );
    }

    /** Approval was attempted on a snapshot that is not in an approvable state. */
    public static function notApprovable(string $status): self
    {
        return new self(
            'SNAPSHOT_NOT_APPROVABLE',
            409,
            "Only a generated, validated or reviewed trial balance can be approved; this one is '{$status}'.",
            'status',
            ['status' => $status],
        );
    }

    /**
     * Approval was attempted on a snapshot whose debits do not equal its credits. Approving an
     * out-of-balance trial balance would put a human signature on a statement that is arithmetically
     * false, so it is refused outright rather than warned about.
     */
    public static function outOfBalance(string $variance): self
    {
        return new self(
            'TRIAL_BALANCE_OUT_OF_BALANCE',
            409,
            "This trial balance is out of balance by {$variance} and cannot be approved.",
            'variance',
            ['variance' => $variance],
        );
    }

    /** A snapshot that is already approved or archived cannot be approved again. */
    public static function alreadyFinal(string $status): self
    {
        return new self(
            'SNAPSHOT_ALREADY_FINAL',
            409,
            "This trial balance is already '{$status}'; generate a new version to supersede it.",
            'status',
            ['status' => $status],
        );
    }

    /** The actor lacks the permission the action requires. */
    public static function forbidden(string $permission, string $action): self
    {
        return new self(
            'PERMISSION_DENIED',
            403,
            "You do not have permission to {$action} a trial balance ({$permission}).",
            null,
            ['permission' => $permission],
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
