<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A trial-balance run frozen at a point in time (S2-09, docs/accounting/TRIAL_BALANCE.md). A strict
 * tenant-owned row, so it uses {@see BelongsToCompany}: RLS + CompanyScope + the `pgsql_app` connection.
 *
 * Deliberately THIN. The lifecycle lives in the S2-09 Actions and in the database triggers — an
 * approved snapshot's figures are immutable at the table level, not merely by convention.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $fiscal_year_id
 * @property int|null $fiscal_period_id
 * @property string $as_of_date
 * @property string $period_start_date
 * @property string $type
 * @property string $status
 * @property int|null $parent_snapshot_id
 * @property int $version
 * @property bool $is_current
 * @property string $currency_code
 * @property string $total_debit
 * @property string $total_credit
 * @property string $variance
 * @property string $rounding_tolerance
 * @property bool $has_warnings
 * @property int $account_count
 * @property int $line_count
 * @property string|null $content_hash
 * @property string $generation_mode
 * @property int|null $approved_by
 */
class TrialBalanceSnapshot extends Model
{
    use BelongsToCompany;

    /** The run is under way — figures are not yet trustworthy. */
    public const STATUS_GENERATING = 'generating';

    /** Computed and stored; debits equal credits. */
    public const STATUS_GENERATED = 'generated';

    /** Checked and confirmed. */
    public const STATUS_VALIDATED = 'validated';

    /** Computed, but debits do NOT equal credits — recorded honestly rather than balanced. */
    public const STATUS_OUT_OF_BALANCE = 'out_of_balance';

    public const STATUS_UNDER_REVIEW = 'under_review';

    /** A human with `accounting.trial_balance.approve` signed it. Figures are frozen from here. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * The states a snapshot may be approved FROM. `generating` is excluded because its figures are not
     * final, `out_of_balance` because signing an arithmetically false statement is the one thing this
     * module exists to prevent, and the terminal states because a signed trial balance is superseded by
     * a new version, never re-signed.
     *
     * @var list<string>
     */
    public const APPROVABLE_STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_VALIDATED,
        self::STATUS_UNDER_REVIEW,
    ];

    /** @var list<string> */
    public const FINAL_STATUSES = [self::STATUS_APPROVED, self::STATUS_ARCHIVED];

    /** @var list<string> */
    public const TYPES = ['unadjusted', 'adjusted', 'post_closing'];

    protected $table = 'trial_balance_snapshots';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'fiscal_period_id' => 'integer',
            'parent_snapshot_id' => 'integer',
            'version' => 'integer',
            'account_count' => 'integer',
            'line_count' => 'integer',
            'is_current' => 'boolean',
            'has_warnings' => 'boolean',
            'is_locked' => 'boolean',
            'generated_at' => 'datetime',
            'validated_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
