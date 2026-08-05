<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One posted general-ledger leg — a 1:1 projection of a posted {@see JournalLine}
 * (docs/accounting/GENERAL_LEDGER.md "# LEDGER ENTRIES"). A strict tenant-owned row (`company_id NOT
 * NULL`), so it uses {@see BelongsToCompany}: RLS + CompanyScope + the `pgsql_app` connection. The table
 * is APPEND-ONLY (a DB trigger rejects UPDATE/DELETE), so this model is written once by the posting
 * engine (S2-05) and thereafter read-only. Money is `NUMERIC(19,4)`, read/written as a string.
 *
 * @property int $id
 * @property int $company_id
 * @property int $journal_entry_id
 * @property int $journal_line_id
 * @property int $account_id
 * @property int $fiscal_year_id
 * @property int|null $fiscal_period_id
 * @property string $entry_date
 * @property string $posted_at
 * @property string $entry_type
 * @property string $currency_code
 * @property string $debit_amount
 * @property string $credit_amount
 * @property string $base_debit_amount
 * @property string $base_credit_amount
 * @property string $signed_base_amount
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $description
 * @property string|null $reference
 */
class LedgerEntry extends Model
{
    use BelongsToCompany;

    /** Append-only projection: no `updated_at` column (a ledger row is never updated). */
    public const UPDATED_AT = null;

    protected $table = 'ledger_entries';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'journal_entry_id' => 'integer',
            'journal_line_id' => 'integer',
            'account_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'fiscal_period_id' => 'integer',
            'source_id' => 'integer',
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }
}
