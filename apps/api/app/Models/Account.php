<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A single account in a company's chart of accounts (docs/accounting/CHART_OF_ACCOUNTS.md). A strict
 * tenant-owned row (`company_id BIGINT NOT NULL`), so it uses {@see BelongsToCompany}: it is scoped by
 * RLS + the CompanyScope and bound to the RLS-enforced `pgsql_app` connection, and its `company_id` is
 * stamped from the active tenant on create — an account can only ever land in the caller's own company.
 *
 * Each account is exactly one {@see AccountType} (its classification), may nest under a `parent_id` in
 * the same company, and stores a denormalised `normal_balance` copied from its type. Deactivation is a
 * `status` change, never a delete.
 *
 * @property int $id
 * @property int $company_id
 * @property int $account_type_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name_en
 * @property string $name_ar
 * @property string $normal_balance
 * @property string $status
 * @property bool $is_control_account
 * @property string|null $control_account_of
 * @property bool $allow_posting
 */
class Account extends Model
{
    use BelongsToCompany;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'accounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_control_account' => 'boolean',
            // Whether a journal line may reference this account directly. The database keeps it true
            // only while the account has no children (S2-11 prerequisite migration).
            'allow_posting' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }
}
