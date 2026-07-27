<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Data\Accounting\ReclassifyAccountData;
use App\Domain\Accounting\PostedActivityGuard;
use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;
use App\Models\AccountType;

/**
 * Reclassify an account to a different account type (S2-01, docs/accounting/CHART_OF_ACCOUNTS.md). A
 * chart that can be retyped under the ledger is a chart that can make history lie, so this is refused
 * the moment the account carries posted lines — corrected instead by moving activity to a new account.
 * On success the account's denormalised `normal_balance` is re-copied from the new type.
 */
final class ReclassifyAccountAction
{
    public function __construct(private readonly PostedActivityGuard $postedActivity) {}

    public function execute(Account $account, ReclassifyAccountData $data): Account
    {
        if ($this->postedActivity->hasPostedLines($account)) {
            throw AccountRuleException::hasPostings('reclassified');
        }

        $type = AccountType::query()->find($data->accountTypeId);
        if (! $type instanceof AccountType) {
            throw AccountRuleException::accountTypeNotFound();
        }

        $account->forceFill([
            'account_type_id' => $type->id,
            'normal_balance' => $type->normal_balance,
        ]);
        $account->save();

        return $account->refresh();
    }
}
