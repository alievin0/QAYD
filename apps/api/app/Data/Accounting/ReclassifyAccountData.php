<?php

declare(strict_types=1);

namespace App\Data\Accounting;

use App\Actions\Accounting\ReclassifyAccountAction;

/**
 * Validated input for {@see ReclassifyAccountAction}: the new account type an account is reclassified
 * to. The action refuses it when the account already carries posted lines.
 */
final readonly class ReclassifyAccountData
{
    public function __construct(
        public int $accountTypeId,
    ) {}
}
