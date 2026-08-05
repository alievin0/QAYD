<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Data\Accounting\CreateAccountData;
use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create one account in the active company's chart (S2-01, docs/accounting/CHART_OF_ACCOUNTS.md). Runs
 * inside an established tenant context: {@see Account} (via BelongsToCompany) stamps `company_id` from
 * the resolved tenant and writes on the RLS-enforced `pgsql_app` connection, so the account can only
 * ever land in the caller's own company; its `normal_balance` is taken from the chosen account type.
 *
 * Business rules (each a 422 {@see AccountRuleException}): the account type must exist; a parent, when
 * given, must be an account in the same company (a cross-tenant id is invisible under RLS, so it reads
 * as "not found"); the code must be unique within the company — enforced race-free by the
 * `uq_accounts_company_code` constraint and converted to a domain error (never a raw DB exception).
 */
final class CreateAccountAction
{
    /** PostgreSQL SQLSTATE for a unique-constraint violation. */
    private const UNIQUE_VIOLATION = '23505';

    public function execute(CreateAccountData $data): Account
    {
        $type = AccountType::query()->find($data->accountTypeId);
        if (! $type instanceof AccountType) {
            throw AccountRuleException::accountTypeNotFound();
        }

        if ($data->parentId !== null && ! Account::query()->whereKey($data->parentId)->exists()) {
            throw AccountRuleException::invalidParent();
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

        $this->persist($account, $data->code);

        return $account->refresh();
    }

    /**
     * Save inside a SAVEPOINT so a duplicate-code collision (the `uq_accounts_company_code` constraint —
     * the single, race-free source of truth for code uniqueness) rolls back only this insert, leaves the
     * surrounding request transaction intact, and surfaces as the standard domain exception. Any other
     * database error is re-thrown for the global handler to render as a safe, non-leaking 500.
     */
    private function persist(Account $account, string $code): void
    {
        try {
            DB::connection($account->getConnectionName())->transaction(static function () use ($account): void {
                $account->save();
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === self::UNIQUE_VIOLATION) {
                throw AccountRuleException::duplicateCode($code);
            }

            throw $e;
        }
    }
}
