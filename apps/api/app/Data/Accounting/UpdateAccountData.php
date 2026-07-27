<?php

declare(strict_types=1);

namespace App\Data\Accounting;

use App\Actions\Accounting\UpdateAccountAction;

/**
 * Validated input for {@see UpdateAccountAction}: an account's editable attributes. Names are always
 * editable; a `code` change (renumber) is guarded against a posted account. A null field is left
 * unchanged.
 */
final readonly class UpdateAccountData
{
    public function __construct(
        public ?string $code = null,
        public ?string $nameEn = null,
        public ?string $nameAr = null,
    ) {}
}
