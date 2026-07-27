<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Data\Accounting\CreateAccountData;
use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;
use App\Models\AccountType;

/**
 * Create one account in the active company's chart (S2-01, docs/accounting/CHART_OF_ACCOUNTS.md). Runs
 * inside an established tenant context: {@see Account} (via BelongsToCompany) stamps `company_id` from
 * the resolved tenant and writes on the RLS-enforced `pgsql_app` connection, so the account can only
 * ever land in the caller's own company; its `normal_balance` is taken from the chosen account type.
 *
 * Business rules (each a 422 {@see AccountRuleException}): the account type must exist; a parent, when
 * given, must be an account in the same company (a cross-tenant id is invisible under RLS, so it reads
 * as "not found"); the code must be unique within the company.
 */
final class CreateAccountAction
{
    public function execute(CreateAccountData $data): Account
    {
        $type = AccountType::query()->find($data->accountTypeId);
        if (! $type instanceof AccountType) {
            throw AccountRuleException::accountTypeNotFound();
        }

        if ($data->parentId !== null && ! Account::query()->whereKey($data->parentId)->exists()) {
            throw AccountRuleException::invalidParent();
        }

        if (Account::query()->where('code', $data->code)->exists()) {
            throw AccountRuleException::duplicateCode($data->code);
        }

        $account = new Account;
        $account->forceFill([
            'account_type_id' => $type->id,
            'parent_id' => $data->parentId,
            'code' => $data->code,
            'name_en' => $data->nameEn,
            'name_ar' => $data->nameAr,
            'normal_balance' => $type->normal_balance,
            'status' => Account::STATUS_ACTIVE,
            'is_control_account' => $data->isControlAccount,
            'control_account_of' => $data->controlAccountOf,
        ]);
        $account->save();

        return $account->refresh();
    }
}
