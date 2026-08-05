<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Data\Accounting\UpdateAccountData;
use App\Domain\Accounting\PostedActivityGuard;
use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Update an account's editable attributes (S2-01, docs/accounting/CHART_OF_ACCOUNTS.md). Renaming
 * (name_en / name_ar) is always allowed — a name does not change what history refers to. Renumbering
 * (a `code` change) is refused once the account carries posted lines, because the code is how the
 * ledger points at it; a new code must also stay unique within the company — enforced race-free by the
 * `uq_accounts_company_code` constraint and converted to a domain error (never a raw DB exception).
 */
final class UpdateAccountAction
{
    /** PostgreSQL SQLSTATE for a unique-constraint violation. */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(private readonly PostedActivityGuard $postedActivity) {}

    public function execute(Account $account, UpdateAccountData $data): Account
    {
        if ($data->code !== null && $data->code !== $account->code) {
            if ($this->postedActivity->hasPostedLines($account)) {
                throw AccountRuleException::hasPostings('renumbered');
            }
            $account->code = $data->code;
        }

        if ($data->nameEn !== null) {
            $account->name_en = $data->nameEn;
        }

        if ($data->nameAr !== null) {
            $account->name_ar = $data->nameAr;
        }

        $this->persist($account);

        return $account->refresh();
    }

    /**
     * Save inside a SAVEPOINT so a duplicate-code collision rolls back only this write, leaves the
     * surrounding request transaction intact, and surfaces as the standard domain exception; any other
     * database error is re-thrown for the global handler to render as a safe, non-leaking 500.
     */
    private function persist(Account $account): void
    {
        try {
            DB::connection($account->getConnectionName())->transaction(static function () use ($account): void {
                $account->save();
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === self::UNIQUE_VIOLATION) {
                throw AccountRuleException::duplicateCode($account->code);
            }

            throw $e;
        }
    }
}
