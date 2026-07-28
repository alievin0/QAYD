<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Allocates a posted entry's PERMANENT `journal_number` from its per-scope sequence
 * (docs/accounting/JOURNAL_ENTRIES.md "# Database Design → journal_number_sequences",
 * "# Locking Rules" §6). One monotonic, gapless sequence per `(company_id, fiscal_year_id, entry_type)`,
 * formatted `{prefix}-{fiscal_year.name}-{n, zero-padded}` — e.g. `JE-FY2026-000001`.
 *
 * Concurrency (Locking Rule §6): the number is drawn with a single atomic upsert-increment
 * (`INSERT … ON CONFLICT DO UPDATE SET next_number = next_number + 1 RETURNING …`). The conflicting
 * sequence row is row-locked for the surrounding posting transaction, so two concurrent posts in the
 * same scope can never receive the same number and the sequence never gaps. Runs on the RLS-enforced
 * tenant connection, inside the posting engine's transaction.
 */
final class JournalNumberAllocator
{
    /** Per-entry-type number prefix; anything unlisted falls back to the generic journal prefix `JE`. */
    private const PREFIXES = [
        'invoice' => 'INV',
        'bill' => 'BILL',
        'payment' => 'PAY',
        'receipt' => 'RCT',
        'payroll' => 'PAY',
        'reversal' => 'REV',
        'opening_balance' => 'OB',
        'year_closing' => 'YC',
    ];

    /**
     * Reserve and format the next number for the scope. Must be called inside the posting transaction so
     * the sequence-row lock is held until the post commits.
     */
    public function allocate(int $companyId, int $fiscalYearId, string $fiscalYearName, string $entryType): string
    {
        $prefix = self::PREFIXES[$entryType] ?? 'JE';

        $row = DB::connection(TenantContext::connection())->selectOne(
            <<<'SQL'
                INSERT INTO journal_number_sequences (company_id, fiscal_year_id, entry_type, prefix, next_number)
                VALUES (?, ?, ?::journal_entry_type, ?, 2)
                ON CONFLICT (company_id, fiscal_year_id, entry_type)
                DO UPDATE SET next_number = journal_number_sequences.next_number + 1, updated_at = now()
                RETURNING prefix, padding, (next_number - 1) AS allocated
                SQL,
            [$companyId, $fiscalYearId, $entryType, $prefix],
        );

        $padding = SqlRow::int($row, 'padding');
        $allocated = SqlRow::int($row, 'allocated');

        return sprintf(
            '%s-%s-%s',
            SqlRow::string($row, 'prefix'),
            $fiscalYearName,
            str_pad((string) $allocated, $padding, '0', STR_PAD_LEFT),
        );
    }
}
