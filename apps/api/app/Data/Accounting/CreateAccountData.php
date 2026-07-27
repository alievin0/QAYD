<?php

declare(strict_types=1);

namespace App\Data\Accounting;

use App\Actions\Accounting\CreateAccountAction;

/**
 * Validated input for {@see CreateAccountAction}. An immutable DTO — the Action never receives a raw
 * array. The account's `normal_balance` is derived from its account type, never passed in.
 */
final readonly class CreateAccountData
{
    public function __construct(
        public int $accountTypeId,
        public string $code,
        public string $nameEn,
        public string $nameAr,
        public ?int $parentId = null,
        public bool $isControlAccount = false,
        public ?string $controlAccountOf = null,
    ) {}
}
