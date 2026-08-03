<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A double-entry journal header (docs/accounting/JOURNAL_ENTRIES.md). A strict tenant-owned row
 * (`company_id NOT NULL`), so it uses {@see BelongsToCompany}: RLS + CompanyScope + the `pgsql_app`
 * connection. Deliberately THIN — every lifecycle rule lives in the S2-04 Actions and the S2-03 database
 * constraints/triggers (balanced-header CHECK, no-AI-autopost trigger, line immutability), never here.
 * Money is `NUMERIC(19,4)` and is read/written as a string to preserve precision.
 *
 * @property int $id
 * @property int $company_id
 * @property string $journal_number
 * @property string $journal_date
 * @property string $entry_type
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $total_debit
 * @property string $total_credit
 * @property string $base_total_debit
 * @property string $base_total_credit
 * @property string $status
 * @property bool $is_reversal
 * @property int|null $reversed_entry_id
 * @property int|null $reversal_entry_id
 * @property int|null $created_by
 * @property bool $ai_generated
 * @property string|null $ai_confidence
 * @property int $version
 * @property string|null $reference
 * @property string|null $memo
 */
class JournalEntry extends Model
{
    use BelongsToCompany;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_VOIDED = 'voided';

    /**
     * The only statuses whose entry (and lines) may still be edited — the application-layer half of the
     * immutability guarantee (the S2-03 trigger independently blocks line writes for terminal parents).
     *
     * @var list<string>
     */
    public const EDITABLE_STATUSES = ['draft', 'rejected'];

    /**
     * The `journal_entry_type` enum values (S2-03 migration). Validated in the Actions for a clean 422
     * before the enum cast would otherwise reject the insert.
     *
     * @var list<string>
     */
    public const ENTRY_TYPES = [
        'manual', 'invoice', 'bill', 'payment', 'receipt', 'inventory', 'payroll', 'depreciation',
        'loan', 'asset', 'tax', 'revaluation', 'year_closing', 'opening_balance', 'ai_generated',
        'reversal', 'adjustment',
    ];

    protected $table = 'journal_entries';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'version' => 'integer',
            'ai_generated' => 'boolean',
            'is_recurring' => 'boolean',
            'is_reversal' => 'boolean',
            'locked' => 'boolean',
            'posting_date' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
