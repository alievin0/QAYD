<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-05 — `ledger_entries`: the General-Ledger read-model, a 1:1 projection of every POSTED
 * `journal_lines` row (docs/accounting/GENERAL_LEDGER.md "# LEDGER ENTRIES"). The posting engine
 * (S2-05) writes exactly one ledger row per posted line inside the same transaction, so Trial Balance
 * and account-activity reads (S2-11) are never stale. `signed_base_amount` (= `+base_debit` for a debit
 * leg, `-base_credit` for a credit leg) is the normalized column a balance is a single `SUM()` over.
 *
 * Immutability: the ledger is the derived source of truth for balances and is APPEND-ONLY — never
 * updated, never deleted (a correction is a NEW reversing entry's new ledger rows, S2-06). The
 * `uq_ledger_entries_journal_line` UNIQUE makes the 1:1 projection exact and is the DB backstop that a
 * line can be projected at most once (idempotent posting); the `trg_ledger_entries_append_only` trigger
 * independently rejects any UPDATE/DELETE, so an application bug cannot mutate posted history.
 *
 * Scope note (deferred columns): GENERAL_LEDGER.md's full ledger row also carries `fiscal_period_id`
 * (NOT NULL FK) and the `branch_id`/`customer_id`/`vendor_id`/`cost_center_id`/`project_id`/
 * `department_id` dimensions + `source`/`description`/`reference` copies. `fiscal_periods` and the
 * dimension tables do not exist yet (S2-07 / later), and the S2-03 `journal_lines` source itself does
 * not yet carry those dimensions — so this projection lands only the columns its source can supply.
 * `fiscal_period_id` is present but nullable with NO FK; S2-07 adds the FK, backfills, and makes it NOT
 * NULL (exactly as `journal_entries.fiscal_year_id` was staged in S2-03). Building the empty dimension
 * columns ahead of a source that can fill them would be building the future (MANIFEST Law 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ledger_entries (
                id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id         BIGINT NOT NULL REFERENCES companies(id),
                journal_entry_id   BIGINT NOT NULL REFERENCES journal_entries(id),
                journal_line_id    BIGINT NOT NULL REFERENCES journal_lines(id),
                account_id         BIGINT NOT NULL REFERENCES accounts(id),
                fiscal_year_id     BIGINT NOT NULL REFERENCES fiscal_years(id),
                fiscal_period_id   BIGINT NULL,                    -- FK + NOT NULL land with fiscal_periods (S2-07)
                entry_date         DATE NOT NULL,
                posted_at          TIMESTAMPTZ NOT NULL,
                entry_type         journal_entry_type NOT NULL,
                currency_code      CHAR(3) NOT NULL,
                debit_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
                credit_amount      NUMERIC(19,4) NOT NULL DEFAULT 0,
                base_debit_amount  NUMERIC(19,4) NOT NULL DEFAULT 0,
                base_credit_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
                signed_base_amount NUMERIC(19,4) NOT NULL,         -- +base_debit or -base_credit (fast SUM)
                source_type        VARCHAR(60) NULL,
                source_id          BIGINT NULL,
                description        TEXT NULL,
                reference          VARCHAR(100) NULL,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT chk_le_one_sided CHECK (
                    debit_amount >= 0 AND credit_amount >= 0 AND NOT (debit_amount > 0 AND credit_amount > 0)
                ),
                CONSTRAINT chk_le_signed CHECK (signed_base_amount = base_debit_amount - base_credit_amount),
                CONSTRAINT uq_ledger_entries_journal_line UNIQUE (journal_line_id)
            )
            SQL);

        DB::statement('CREATE INDEX idx_ledger_account_date ON ledger_entries (company_id, account_id, entry_date)');
        DB::statement('CREATE INDEX idx_ledger_entry ON ledger_entries (journal_entry_id)');
        DB::statement('CREATE INDEX idx_ledger_year ON ledger_entries (fiscal_year_id)');
        DB::statement('CREATE INDEX idx_ledger_source ON ledger_entries (source_type, source_id)');

        $this->createAppendOnlyTrigger();
        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE removes the RLS policies, indexes, the append-only trigger, and the CHECK/FK/UNIQUE
        // constraints with it; then the now table-independent trigger function can be dropped.
        DB::statement('DROP TABLE IF EXISTS ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS fn_ledger_entries_append_only()');
    }

    /**
     * Defense-in-depth for the "the ledger has no update path at all" invariant
     * (docs/accounting/GENERAL_LEDGER.md): reject any UPDATE or DELETE on a ledger row at the database
     * level, independent of the application layer. INSERT (the posting projection) is unaffected.
     */
    private function createAppendOnlyTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ledger_entries_append_only() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_entries_append_only();
            SQL);
    }

    /** The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), as on every tenant table. */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE ledger_entries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ledger_entries FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY ledger_entries_company_boundary ON ledger_entries
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY ledger_entries_tenant_select ON ledger_entries
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY ledger_entries_tenant_insert ON ledger_entries
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY ledger_entries_tenant_update ON ledger_entries
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY ledger_entries_tenant_delete ON ledger_entries
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
