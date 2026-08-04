<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One account's frozen line on a trial-balance snapshot (S2-09).
 *
 * The account's code and names are DENORMALIZED copies taken at snapshot time, on purpose: renumbering
 * or renaming an account later must not silently rewrite a trial balance a human already approved. The
 * `account_id` FK still points at the live account for drill-down, but the printed figures stand on
 * their own.
 *
 * Append-only at the database level (`trg_tbsl_append_only`) — a corrected trial balance is a new
 * snapshot version, never an edit.
 *
 * @property int $id
 * @property int $snapshot_id
 * @property int $company_id
 * @property int $account_id
 * @property string $account_code
 * @property string $account_name_en
 * @property string|null $account_name_ar
 * @property int $account_type_id
 * @property string $normal_balance
 * @property string $opening_debit
 * @property string $opening_credit
 * @property string $period_debit
 * @property string $period_credit
 * @property string $closing_debit
 * @property string $closing_credit
 * @property bool $is_abnormal_balance
 * @property int $source_line_count
 */
class TrialBalanceSnapshotLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'trial_balance_snapshot_lines';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'snapshot_id' => 'integer',
            'account_id' => 'integer',
            'account_type_id' => 'integer',
            'source_line_count' => 'integer',
            'is_abnormal_balance' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
