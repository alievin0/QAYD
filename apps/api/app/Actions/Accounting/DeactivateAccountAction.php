<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;

/**
 * Deactivate an account (S2-01, docs/accounting/CHART_OF_ACCOUNTS.md): a `status` change to 'inactive',
 * never a delete — a posted account may be deactivated but must never vanish. Idempotent (deactivating
 * an already-inactive account is a no-op) and refused while the account still has active children, so a
 * parent is never deactivated out from under a live sub-account.
 */
final class DeactivateAccountAction
{
    public function execute(Account $account): Account
    {
        if ($account->status === Account::STATUS_INACTIVE) {
            return $account;
        }

        $hasActiveChildren = Account::query()
            ->where('parent_id', $account->id)
            ->where('status', Account::STATUS_ACTIVE)
            ->exists();

        if ($hasActiveChildren) {
            throw AccountRuleException::hasActiveChildren();
        }

        $account->status = Account::STATUS_INACTIVE;
        $account->save();

        return $account->refresh();
    }
}
