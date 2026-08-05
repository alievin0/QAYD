<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-03 — `journal_lines`: the individual debit/credit legs of a journal entry
 * (docs/accounting/JOURNAL_ENTRIES.md "# Journal Lines" / "# Database Design").
 *
 * Schema + PostgreSQL-level integrity only (no lifecycle / posting / API). The core double-entry
 * invariants live in the database: a line is one-sided and non-negative (`chk_jl_one_sided` — exactly one
 * of debit/credit is > 0, both ≥ 0), the FX rate is positive, base amounts are non-negative, and line
 * numbers are positive and unique within an entry. The IMMUTABILITY guarantee lives here too: a
 * PostgreSQL trigger (`trg_journal_lines_no_update_when_posted`) blocks any UPDATE or DELETE of a line
 * whose parent entry is terminal (posted / reversed / voided / archived) — a second line of defense
 * independent of application bugs, so a raw SQL statement, even under a privileged role, is refused.
 *
 * Scope note (deferred columns): the full JOURNAL_ENTRIES.md line also carries `branch_id`, the dimension
 * FKs (`cost_center_id`, `project_id`, `department_id`), the sub-ledger tags (`customer_id`, `vendor_id`),
 * and the tax FKs (`tax_code_id`, `tax_rate_id`) — all targeting tables that do not exist yet. They are
 * added with their real FK constraints by the migrations that create those modules. `account_id` and
 * `ai_suggested_account_id` (→ accounts, S2-01) are here now.
 *
 * Strict tenant table (company_id NOT NULL) → the uniform RLS boundary. Money is NUMERIC(19,4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE journal_lines (
                id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id              BIGINT NOT NULL REFERENCES companies(id),
                journal_entry_id        BIGINT NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
                line_number             SMALLINT NOT NULL,
                account_id              BIGINT NOT NULL REFERENCES accounts(id),
                description             TEXT NULL,
                debit                   NUMERIC(19,4) NOT NULL DEFAULT 0,
                credit                  NUMERIC(19,4) NOT NULL DEFAULT 0,
                currency_code           CHAR(3) NOT NULL,
                exchange_rate           NUMERIC(18,6) NOT NULL DEFAULT 1,
                base_debit              NUMERIC(19,4) NOT NULL DEFAULT 0,
                base_credit             NUMERIC(19,4) NOT NULL DEFAULT 0,
                tax_amount              NUMERIC(19,4) NULL DEFAULT 0,
                reference               VARCHAR(100) NULL,
                reconciled              BOOLEAN NOT NULL DEFAULT false,
                reconciled_at           TIMESTAMPTZ NULL,
                ai_confidence           NUMERIC(5,4) NULL,
                ai_suggested_account_id BIGINT NULL REFERENCES accounts(id),
                created_by              BIGINT NULL REFERENCES users(id),
                updated_by              BIGINT NULL REFERENCES users(id),
                created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at              TIMESTAMPTZ NULL,
                CONSTRAINT chk_jl_one_sided CHECK (
                    (debit >= 0 AND credit >= 0)
                    AND NOT (debit > 0 AND credit > 0)
                    AND (debit > 0 OR credit > 0)
                ),
                CONSTRAINT chk_jl_base_nonneg          CHECK (base_debit >= 0 AND base_credit >= 0),
                CONSTRAINT chk_jl_rate_positive        CHECK (exchange_rate > 0),
                CONSTRAINT chk_jl_line_number_positive CHECK (line_number > 0),
                CONSTRAINT chk_jl_ai_confidence        CHECK (
                    ai_confidence IS NULL OR ai_confidence BETWEEN 0 AND 1
                ),
                CONSTRAINT uq_jl_line_number UNIQUE (journal_entry_id, line_number)
            )
            SQL);

        DB::statement('CREATE INDEX idx_jl_entry ON journal_lines (journal_entry_id)');
        DB::statement('CREATE INDEX idx_jl_account ON journal_lines (company_id, account_id)');

        $this->createImmutabilityTrigger();
        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS fn_block_update_when_posted()');
    }

    /**
     * Posted-immutability defense in depth (docs/accounting/JOURNAL_ENTRIES.md "# Locking Rules",
     * SPRINT_02 §S2-03). Any UPDATE or DELETE of a line whose parent entry is in a terminal state is
     * rejected at the storage engine — so even a raw SQL statement under a privileged role cannot alter a
     * posted line. The parent-status lookup is coupled to the line by `company_id`: RLS makes a line and
     * its parent visible together (same tenant), and the owner role bypasses RLS entirely, so the trigger
     * always reads the true parent status without needing SECURITY DEFINER.
     *
     * Note vs. the spec DDL: the reference function returns NEW unconditionally, which for a DELETE (where
     * NEW is NULL) would silently CANCEL the delete. This implementation returns OLD on DELETE so a
     * legitimate delete of a draft line proceeds; the block still fires for terminal parents.
     */
    private function createImmutabilityTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_block_update_when_posted() RETURNS TRIGGER AS $$
            DECLARE
                v_status journal_entry_status;
            BEGIN
                SELECT status INTO v_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                IF v_status IN ('posted', 'reversed', 'voided', 'archived') THEN
                    RAISE EXCEPTION 'journal_lines: cannot modify a line of a % entry (id=%)', v_status, OLD.journal_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_journal_lines_no_update_when_posted
                BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION fn_block_update_when_posted();
            SQL);
    }

    /** The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), as on every tenant table. */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE journal_lines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE journal_lines FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY journal_lines_company_boundary ON journal_lines
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_lines_tenant_select ON journal_lines
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_lines_tenant_insert ON journal_lines
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_lines_tenant_update ON journal_lines
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_lines_tenant_delete ON journal_lines
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
