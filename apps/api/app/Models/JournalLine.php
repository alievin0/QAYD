<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One debit/credit leg of a {@see JournalEntry} (docs/accounting/JOURNAL_ENTRIES.md). Strict tenant-owned
 * (`company_id NOT NULL`) → {@see BelongsToCompany}. Thin: the one-sided/non-negative invariants are S2-03
 * database CHECKs, and its editability is governed by the parent's status (enforced in the S2-04 Actions
 * and the S2-03 immutability trigger). Money is `NUMERIC(19,4)`, read/written as a string.
 *
 * @property int $id
 * @property int $company_id
 * @property int $journal_entry_id
 * @property int $line_number
 * @property int $account_id
 * @property string|null $description
 * @property string $debit
 * @property string $credit
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $base_debit
 * @property string $base_credit
 */
class JournalLine extends Model
{
    use BelongsToCompany;

    protected $table = 'journal_lines';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'journal_entry_id' => 'integer',
            'line_number' => 'integer',
            'account_id' => 'integer',
            'reconciled' => 'boolean',
            'reconciled_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
