<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-06 — a posted journal entry may be reversed **at most once**.
 *
 * `journal_entries.reversal_entry_id` is a single column on the original, so without this guard a second
 * reversal would overwrite the first link and silently orphan the earlier mirror entry — leaving two
 * posted mirrors in the ledger with only one of them reachable. The mirror carries `reversed_entry_id`
 * pointing back at its original, so a partial UNIQUE index on that column makes "one reversal per
 * original" a database guarantee rather than an application check
 * (docs/accounting/JOURNAL_ENTRIES.md "# Locking Rules").
 *
 * The index is partial because `reversed_entry_id` is NULL on every ordinary entry: it keeps the index
 * to the handful of rows that are actually reversals, and states the intent explicitly.
 *
 * The complementary `chk_je_no_self_reverse` CHECK (an entry cannot reverse itself) already exists from
 * the S2-03 migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_je_one_reversal
                ON journal_entries (reversed_entry_id)
                WHERE reversed_entry_id IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_je_one_reversal');
    }
};
