<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Data\Accounting\UpdateAccountData;
use App\Domain\Accounting\PostedActivityGuard;
use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;

/**
 * Update an account's editable attributes (S2-01, docs/accounting/CHART_OF_ACCOUNTS.md). Renaming
 * (name_en / name_ar) is always allowed — a name does not change what history refers to. Renumbering
 * (a `code` change) is refused once the account carries posted lines, because the code is how the
 * ledger points at it; a new code must also stay unique within the company.
 */
final class UpdateAccountAction
{
    public function __construct(private readonly PostedActivityGuard $postedActivity) {}

    public function execute(Account $account, UpdateAccountData $data): Account
    {
        if ($data->code !== null && $data->code !== $account->code) {
            if ($this->postedActivity->hasPostedLines($account)) {
                throw AccountRuleException::hasPostings('renumbered');
            }
            if (Account::query()->where('code', $data->code)->whereKeyNot($account->id)->exists()) {
                throw AccountRuleException::duplicateCode($data->code);
            }
            $account->code = $data->code;
        }

        if ($data->nameEn !== null) {
            $account->name_en = $data->nameEn;
        }

        if ($data->nameAr !== null) {
            $account->name_ar = $data->nameAr;
        }

        $account->save();

        return $account->refresh();
    }
}
