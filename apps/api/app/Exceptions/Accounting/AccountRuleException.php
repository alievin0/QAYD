<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;

/**
 * A chart-of-accounts business-rule violation (docs/accounting/CHART_OF_ACCOUNTS.md,
 * docs/api/API_ERROR_HANDLING.md "# Business-Rule Errors"). It carries the stable catalog code for the
 * specific rule; every COA rule renders as 422 (a content / business violation). Constructed through the
 * named factories so each call site reads as the rule it enforces.
 */
final class AccountRuleException extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly string $catalogCode,
        string $message,
        ?string $field = null,
        array $meta = [],
    ) {
        parent::__construct($message);

        $this->field = $field;
        $this->meta = $meta;
    }

    public function errorCode(): string
    {
        return $this->catalogCode;
    }

    public function errorStatus(): int
    {
        return 422;
    }

    public static function duplicateCode(string $code): self
    {
        return new self(
            'DUPLICATE_ACCOUNT_CODE',
            "An account with code '{$code}' already exists in this company.",
            'code',
            ['code' => $code],
        );
    }

    public static function invalidParent(): self
    {
        return new self(
            'INVALID_ACCOUNT_PARENT',
            'The parent account does not exist in this company.',
            'parent_id',
        );
    }

    public static function accountTypeNotFound(): self
    {
        return new self(
            'ACCOUNT_TYPE_NOT_FOUND',
            'The specified account type does not exist.',
            'account_type_id',
        );
    }

    public static function hasPostings(string $operation): self
    {
        return new self(
            'ACCOUNT_HAS_POSTINGS',
            "This account carries posted entries and cannot be {$operation}; correct it with a new account instead.",
            null,
            ['operation' => $operation],
        );
    }

    public static function hasActiveChildren(): self
    {
        return new self(
            'ACCOUNT_HAS_ACTIVE_CHILDREN',
            'This account has active sub-accounts; deactivate them first.',
        );
    }
}
