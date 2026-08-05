<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Accounting\ClosePeriodAction;
use App\Actions\Accounting\LockPeriodAction;
use App\Actions\Accounting\ReopenPeriodAction;
use App\Domain\Accounting\FiscalPeriodCalendarResolver;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A month-level accounting period (S2-07, docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR").
 * A strict tenant-owned row (`company_id NOT NULL`), so it uses {@see BelongsToCompany}: RLS +
 * CompanyScope + the `pgsql_app` connection.
 *
 * Deliberately THIN. The lifecycle rules live in the S2-07 Actions ({@see ClosePeriodAction},
 * {@see LockPeriodAction}, {@see ReopenPeriodAction}) and in the
 * database constraints (no-overlap exclusion, within-year containment trigger, dense period numbering);
 * the posting gate itself lives in {@see FiscalPeriodCalendarResolver}, which is the
 * only thing the posting engine ever talks to.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $period_type
 * @property int $period_number
 * @property string $name
 * @property string $start_date
 * @property string $end_date
 * @property string $status
 * @property array<string, string> $module_lock
 * @property string|null $reopen_reason
 * @property int|null $closed_by
 * @property int|null $locked_by
 * @property int|null $reopened_by
 */
class FiscalPeriod extends Model
{
    use BelongsToCompany;

    /** Not yet reached: the period lies ahead of the company's open month. Refuses postings. */
    public const STATUS_FUTURE = 'future';

    /** The only status that accepts a posting. */
    public const STATUS_OPEN = 'open';

    /** Closed for routine posting; an actor with `accounting.period.reopen` can reopen it. */
    public const STATUS_CLOSED = 'closed';

    /** Closed AND audit-signed-off; only `accounting.period.hard_lock_override` can undo it. */
    public const STATUS_LOCKED = 'locked';

    /**
     * The `period_type` enum values (S2-07 migration). Monthly is the default; the others exist because
     * every period-based report groups by `fiscal_period_id`, never a month number, so a 13-period retail
     * or quarterly calendar needs no special-casing anywhere downstream.
     *
     * @var list<string>
     */
    public const PERIOD_TYPES = ['monthly', 'quarterly', 'weekly_4_4_5', 'custom'];

    protected $table = 'fiscal_periods';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'period_number' => 'integer',
            'module_lock' => 'array',
            'closed_at' => 'datetime',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
