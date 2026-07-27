<?php

use App\Actions\Onboarding\CreateCompanyAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-10 — `fiscal_years`: a company's accounting calendar year
 * (docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR").
 *
 * Onboarding (Epic D) seeds the company's FIRST fiscal year transactionally with the company + owner
 * membership ({@see CreateCompanyAction}); this migration lands the table that
 * row lives in. It is a strict tenant-owned table — `company_id BIGINT NOT NULL REFERENCES
 * companies(id)` — so it gets the exact same RLS treatment every tenant table gets (ENABLE + FORCE +
 * a RESTRICTIVE company boundary plus permissive per-verb policies), reusing the `app_current_company_id()`
 * helper fixed once in the S1-05 migration. With no tenant GUC set a read returns zero rows (fail-closed),
 * and a write is confined to the active company by the WITH CHECK boundary.
 *
 * Scope: this is the fiscal-year TABLE + its first-row shape that S1-10 needs. The full fiscal-calendar
 * feature (the `fiscal_periods` children auto-populated per year, closing/opening lifecycle, the
 * partial-year transition rules) is Sprint 02 accounting work and is deliberately NOT built here.
 *
 * The runtime `qayd_app` role receives SELECT/INSERT/UPDATE/DELETE automatically: the S1-05
 * `create_app_database_role` migration issued `ALTER DEFAULT PRIVILEGES … GRANT … ON TABLES`, so every
 * table the owner creates afterward (this one included) inherits those grants — no explicit GRANT needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The fiscal-year lifecycle states (docs/accounting/GENERAL_LEDGER.md). Guarded so migrate:fresh
        // is idempotent whether or not the cluster still carries the type from a prior run.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'fiscal_year_status') THEN
                    CREATE TYPE fiscal_year_status AS ENUM ('future', 'open', 'closing', 'closed');
                END IF;
            END
            $$;
            SQL);

        // Required by the per-company no-overlap exclusion constraint below (GiST over a daterange).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            CREATE TABLE fiscal_years (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id    BIGINT NOT NULL REFERENCES companies(id),
                name          VARCHAR(50) NOT NULL,            -- e.g. 'FY2026'
                start_date    DATE NOT NULL,
                end_date      DATE NOT NULL,
                status        fiscal_year_status NOT NULL DEFAULT 'future',
                closed_at     TIMESTAMPTZ NULL,
                closed_by     BIGINT NULL REFERENCES users(id),
                created_by    BIGINT NULL REFERENCES users(id),
                updated_by    BIGINT NULL REFERENCES users(id),
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at    TIMESTAMPTZ NULL,
                CONSTRAINT chk_fiscal_years_dates CHECK (end_date > start_date),
                CONSTRAINT uq_fiscal_years_company_name UNIQUE (company_id, name)
            )
            SQL);

        DB::statement('CREATE INDEX idx_fiscal_years_company ON fiscal_years (company_id, start_date)');

        // A company can never have two fiscal years whose date ranges overlap (GENERAL_LEDGER.md).
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_years ADD CONSTRAINT excl_fiscal_years_no_overlap
                EXCLUDE USING gist (company_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
                WHERE (deleted_at IS NULL)
            SQL);

        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE removes the table's RLS policies, indexes, and the exclusion constraint; then the
        // enum type (table-independent) can be dropped. The `btree_gist` extension and the
        // `app_current_company_id()` helper are owned by earlier migrations and are left in place.
        DB::statement('DROP TABLE IF EXISTS fiscal_years');
        DB::statement('DROP TYPE IF EXISTS fiscal_year_status');
    }

    /**
     * The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), identical in shape to
     * `company_users` in the S1-05 migration: a RESTRICTIVE company boundary no permissive policy can OR
     * past, plus the permissive per-verb policies that actually grant access, all keyed on the shared
     * `app_current_company_id()` helper (⇒ zero rows / rejected write when the GUC is unset).
     */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE fiscal_years ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE fiscal_years FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_years_company_boundary ON fiscal_years
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_years_tenant_select ON fiscal_years
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_years_tenant_insert ON fiscal_years
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_years_tenant_update ON fiscal_years
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY fiscal_years_tenant_delete ON fiscal_years
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
