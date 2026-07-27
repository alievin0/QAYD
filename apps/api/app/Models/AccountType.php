<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the seven system account classifications (docs/accounting/CHART_OF_ACCOUNTS.md). A GLOBAL
 * reference row shared read-only across every tenant — no `company_id`, no RLS, seeded once by
 * AccountTypeSeeder — so it does NOT use {@see BelongsToCompany} and runs on the
 * default (owner) connection like the other fixed catalogues (permissions). It fixes the two facts the
 * ledger needs about any account of this type: its `normal_balance` (debit/credit) and whether it sits
 * on the balance sheet (`is_balance_sheet`) or the income statement.
 *
 * @property int $id
 * @property string $key
 * @property string $name_en
 * @property string $name_ar
 * @property string $normal_balance
 * @property bool $is_balance_sheet
 * @property int $sort_order
 */
class AccountType extends Model
{
    protected $table = 'account_types';

    protected $guarded = [];

    /** account_types carries only created_at (immutable reference data); no updated_at to maintain. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_balance_sheet' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
