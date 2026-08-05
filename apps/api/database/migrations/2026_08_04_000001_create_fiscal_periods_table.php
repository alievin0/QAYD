<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-07 — `fiscal_periods`: the month-level accounting calendar
 * (docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR").
 *
 * `fiscal_years` (S1-10) says which YEAR a date belongs to; this table says which PERIOD within it, and
 * it is the period — not the year — that the posting engine resolves and row-locks at posting time. That
 * granularity is the whole point: a company closes January while February is still being posted into, so
 * a year-level gate can never express "you cannot post into a closed month."
 *
 * Status is the postable gate: `open` accepts postings; `future`, `closed` and `locked` refuse them.
 * `closed` is reversible by an actor holding `accounting.period.reopen`; `locked` is the post-audit state
 * and needs `accounting.period.hard_lock_override` — the difference between the two is *who can undo it*,
 * which is why they are separate statuses rather than one flag.
 *
 * `module_lock` (JSONB) exists because period close is not all-or-nothing across modules: Sales and
 * Purchases can be closed for a period while Payroll's accrual is still finalizing. Nothing writes it
 * yet — the sub-ledger modules that will are later sprints — but the column ships with the table so
 * adding them is not a migration on a populated ledger-adjacent table.
 *
 * Integrity is in the database, not only the Actions:
 *   - `excl_fiscal_periods_no_overlap` — two periods of one company can never cover the same day, so
 *     "which period does this date belong to?" always has exactly one answer.
 *   - `trg_fiscal_periods_within_year` — a period must lie inside its parent fiscal year. A CHECK cannot
 *     read another table, so this is a trigger; without it a mis-generated period would silently accept
 *     postings the year it belongs to has already closed.
 *   - `uq_fiscal_periods_year_number` + the 1..53 range check — period numbering is dense and bounded
 *     (12 monthly, 13 for 4-4-5 retail calendars, 4 quarterly, up to 53 weekly).
 *
 * Strict tenant-owned table, so it gets the uniform RLS treatment every tenant table gets (ENABLE +
 * FORCE + a RESTRICTIVE company boundary plus permissive per-verb policies), keyed on the shared
 * `app_current_company_id()` helper — with no tenant GUC set a read returns zero rows and a write is
 * rejected. The `qayd_app` role inherits its grants from the S1-05 `ALTER DEFAULT PRIVILEGES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so migrate:fresh is idempotent whether or not the cluster still carries the types
        // from a prior run (same pattern as the S1-10 fiscal_years migration).
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'fiscal_period_status') THEN
                    CREATE TYPE fiscal_period_status AS ENUM ('future', 'open', 'closed', 'locked');
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'period_type') THEN
                    CREATE TYPE period_type AS ENUM ('monthly', 'quarterly', 'weekly_4_4_5', 'custom');
                END IF;
            END
            $$;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE fiscal_periods (
                id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id     BIGINT NOT NULL REFERENCES companies(id),
                fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
                period_type    period_type NOT NULL DEFAULT 'monthly',
                period_number  SMALLINT NOT NULL,
                name           VARCHAR(50) NOT NULL,
                start_date     DATE NOT NULL,
                end_date       DATE NOT NULL,
                status         fiscal_period_status NOT NULL DEFAULT 'future',
                module_lock    JSONB NOT NULL DEFAULT '{}',
                closed_at      TIMESTAMPTZ NULL,
                closed_by      BIGINT NULL REFERENCES users(id),
                locked_at      TIMESTAMPTZ NULL,
                locked_by      BIGINT NULL REFERENCES users(id),
                reopened_at    TIMESTAMPTZ NULL,
                reopened_by    BIGINT NULL REFERENCES users(id),
                reopen_reason  TEXT NULL,
                created_by     BIGINT NULL REFERENCES users(id),
                updated_by     BIGINT NULL REFERENCES users(id),
                created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at     TIMESTAMPTZ NULL,
                CONSTRAINT chk_fiscal_periods_dates CHECK (end_date >= start_date),
                CONSTRAINT chk_fiscal_periods_number CHECK (period_number BETWEEN 1 AND 53),
                CONSTRAINT uq_fiscal_periods_year_number UNIQUE (fiscal_year_id, period_number)
            )
            SQL);

        DB::statement('CREATE INDEX idx_fiscal_periods_company_dates ON fiscal_periods (company_id, start_date, end_date)');
        DB::statement('CREATE INDEX idx_fiscal_periods_status ON fiscal_periods (company_id, status)');
        DB::statement('CREATE INDEX idx_fiscal_periods_year ON fiscal_periods (fiscal_year_id, period_number)');

        // Exactly one period may cover any given day for a company (btree_gist comes from S1-10).
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_periods ADD CONSTRAINT excl_fiscal_periods_no_overlap
                EXCLUDE USING gist (company_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
                WHERE (deleted_at IS NULL)
            SQL);

        $this->applyContainmentTrigger();
        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE takes the policies, indexes, exclusion constraint and the trigger with it; the
        // trigger FUNCTION is table-independent and is dropped explicitly. `btree_gist` and
        // `app_current_company_id()` belong to earlier migrations and stay.
        DB::statement('DROP TABLE IF EXISTS fiscal_periods');
        DB::statement('DROP FUNCTION IF EXISTS fiscal_period_within_year()');
        DB::statement('DROP TYPE IF EXISTS fiscal_period_status');
        DB::statement('DROP TYPE IF EXISTS period_type');
    }

    /**
     * A period must fall inside its parent fiscal year, and must belong to the same company as that
     * year. Neither is expressible as a CHECK (both read `fiscal_years`), and both matter: a period
     * straddling a year boundary would let a posting land in a year whose own status says closed, and a
     * period pointing at another company's year would be a tenancy hole the FK alone does not close
     * (`fiscal_year_id` references `fiscal_years(id)` without carrying `company_id`).
     */
    private function applyContainmentTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fiscal_period_within_year() RETURNS trigger AS $$
            DECLARE
                y_company   BIGINT;
                y_start     DATE;
                y_end       DATE;
            BEGIN
                SELECT company_id, start_date, end_date
                INTO y_company, y_start, y_end
                FROM fiscal_years
                WHERE id = NEW.fiscal_year_id;

                IF y_company IS NULL THEN
                    RAISE EXCEPTION 'fiscal_periods.fiscal_year_id % does not exist', NEW.fiscal_year_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF y_company <> NEW.company_id THEN
                    RAISE EXCEPTION 'fiscal period company % does not match its fiscal year company %',
                        NEW.company_id, y_company
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.start_date < y_start OR NEW.end_date > y_end THEN
                    RAISE EXCEPTION 'fiscal period % .. % falls outside its fiscal year % .. %',
                        NEW.start_date, NEW.end_date, y_start, y_end
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_fiscal_periods_within_year
                BEFORE INSERT OR UPDATE OF company_id, fiscal_year_id, start_date, end_date
                ON fiscal_periods
                FOR EACH ROW EXECUTE FUNCTION fiscal_period_within_year()
            SQL);
    }

    /**
     * The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), identical in shape to
     * `fiscal_years`: a RESTRICTIVE company boundary no permissive policy can OR past, plus the
     * permissive per-verb policies that actually grant access.
     */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE fiscal_periods ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE fiscal_periods FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_periods_company_boundary ON fiscal_periods
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_periods_tenant_select ON fiscal_periods
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_periods_tenant_insert ON fiscal_periods
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_periods_tenant_update ON fiscal_periods
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_periods_tenant_delete ON fiscal_periods
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
