<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-07 — make the fiscal PERIOD a first-class part of a posting, closing TD-13.
 *
 * S2-05 shipped the posting engine against a year-level calendar and left `ledger_entries.fiscal_period_id`
 * nullable with no foreign key, because there was no `fiscal_periods` table for it to point at. Now that
 * there is, this migration finishes the job in the only order that is safe on a live database:
 *
 *   1. **Generate the periods that must already exist.** Every fiscal year created before this migration
 *      has none. The seam is about to start resolving postings against periods, so a year without them
 *      would refuse every post in it — the rebind would be a regression, not a feature. Each existing year
 *      gets monthly periods covering it exactly (first and last clamped to the year's own boundaries, so a
 *      part-year or non-calendar fiscal year is covered without gaps or overlaps).
 *
 *      Their status is **derived from the parent year**, deliberately: an `open` year's periods are all
 *      `open`. That makes the rebind behaviour-preserving — exactly the dates that were postable before
 *      this migration are postable after it. S2-07 adds the *ability* to close a month; it does not close
 *      any month on a company's behalf.
 *
 *   2. **Add `journal_entries.fiscal_period_id`** — nullable, because a draft has no period until it is
 *      posted (the period is resolved and locked at posting time, not at drafting time).
 *
 *   3. **Backfill, then constrain `ledger_entries.fiscal_period_id`.** Every posted line already sits
 *      inside some fiscal year, and step 1 guarantees that year is now fully covered by periods, so
 *      matching on `(company_id, entry_date)` resolves every existing row. Only once that is true does
 *      the column take its FK and `NOT NULL` — a projection row that cannot say which period it belongs
 *      to is not a projection anyone can close a month against.
 *
 * `down()` reverses the schema changes only. It does not delete the generated period rows: they are
 * ordinary calendar data a company may already have closed months against by then, and the migration that
 * created the table (`create_fiscal_periods_table`) is the one that owns removing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->generatePeriodsForExistingYears();

        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
                ADD COLUMN fiscal_period_id BIGINT NULL REFERENCES fiscal_periods(id)
            SQL);
        DB::statement('CREATE INDEX idx_journal_entries_period ON journal_entries (company_id, fiscal_period_id)');

        DB::statement(<<<'SQL'
            UPDATE ledger_entries le
            SET fiscal_period_id = fp.id
            FROM fiscal_periods fp
            WHERE le.fiscal_period_id IS NULL
              AND fp.company_id = le.company_id
              AND le.entry_date BETWEEN fp.start_date AND fp.end_date
            SQL);

        // Fail loudly rather than silently dropping the guarantee: if any posted line still cannot name
        // its period, the calendar is incomplete and SET NOT NULL below would abort anyway — this says
        // why. (An orphan is only possible if a year was deleted out from under its ledger rows.)
        $orphans = DB::scalar('SELECT COUNT(*) FROM ledger_entries WHERE fiscal_period_id IS NULL');

        if (is_numeric($orphans) && (int) $orphans > 0) {
            throw new RuntimeException(
                "Cannot bind the ledger to fiscal periods: {$orphans} posted line(s) fall outside every ".
                'fiscal period. Create the fiscal years covering those entry dates, then re-run.'
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
                ADD CONSTRAINT ledger_entries_fiscal_period_id_foreign
                FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods(id)
            SQL);
        DB::statement('ALTER TABLE ledger_entries ALTER COLUMN fiscal_period_id SET NOT NULL');
        DB::statement('CREATE INDEX idx_ledger_entries_period ON ledger_entries (company_id, fiscal_period_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_ledger_entries_period');
        DB::statement('ALTER TABLE ledger_entries ALTER COLUMN fiscal_period_id DROP NOT NULL');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_fiscal_period_id_foreign');

        DB::statement('DROP INDEX IF EXISTS idx_journal_entries_period');
        DB::statement('ALTER TABLE journal_entries DROP COLUMN IF EXISTS fiscal_period_id');
    }

    /**
     * One monthly period per calendar month touched by each existing fiscal year, clamped to the year's
     * own start and end so the coverage is exact — `GREATEST`/`LEAST` handle a fiscal year that begins or
     * ends mid-month (a part-year at company setup, or a 1 Apr – 31 Mar calendar).
     *
     * `period_number` is the ordinal within the year (1-based), which is what
     * `uq_fiscal_periods_year_number` keys on and what every period-based report groups by — never a
     * calendar month number, so a non-January fiscal year needs no special-casing.
     *
     * Runs on the owner connection (migrations always do), so it sees and writes across every company;
     * the generated rows carry each year's own `company_id` and are RLS-scoped from then on.
     */
    private function generatePeriodsForExistingYears(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO fiscal_periods (
                company_id, fiscal_year_id, period_type, period_number, name,
                start_date, end_date, status
            )
            SELECT
                fy.id_company,
                fy.id_year,
                'monthly'::period_type,
                fy.ordinal::SMALLINT,
                to_char(fy.month_start, 'Mon YYYY'),
                GREATEST(fy.month_start, fy.year_start),
                LEAST((fy.month_start + INTERVAL '1 month - 1 day')::DATE, fy.year_end),
                CASE fy.year_status
                    WHEN 'open'   THEN 'open'::fiscal_period_status
                    WHEN 'future' THEN 'future'::fiscal_period_status
                    ELSE 'closed'::fiscal_period_status
                END
            FROM (
                SELECT
                    y.company_id                                   AS id_company,
                    y.id                                           AS id_year,
                    y.start_date                                   AS year_start,
                    y.end_date                                     AS year_end,
                    y.status::TEXT                                 AS year_status,
                    m.month_start::DATE                            AS month_start,
                    ROW_NUMBER() OVER (PARTITION BY y.id ORDER BY m.month_start) AS ordinal
                FROM fiscal_years y
                CROSS JOIN LATERAL generate_series(
                    date_trunc('month', y.start_date),
                    date_trunc('month', y.end_date),
                    INTERVAL '1 month'
                ) AS m(month_start)
                WHERE y.deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM fiscal_periods p WHERE p.fiscal_year_id = y.id
                  )
            ) AS fy
            SQL);
    }
};
