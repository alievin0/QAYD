<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-01 — `accounts`: a company's chart of accounts, the tree every later posting speaks in
 * (docs/accounting/CHART_OF_ACCOUNTS.md). A strict tenant-owned table (`company_id BIGINT NOT NULL`),
 * so it gets the uniform RLS treatment every tenant table gets — ENABLE + FORCE + a RESTRICTIVE
 * company boundary plus permissive per-verb policies keyed on the shared `app_current_company_id()`
 * helper (S1-05): with no tenant GUC a read returns zero rows (fail-closed) and a write is confined to
 * the active company by WITH CHECK.
 *
 * Each account is exactly one `account_types` row (its classification + normal balance), may nest under
 * a `parent_id` in the same company (a tree), and carries a denormalised `normal_balance` copied from
 * its type at create/reclassify time. Deactivation is a `status` change ('active' → 'inactive'), never
 * a delete — an account history points at must never vanish. Account codes are unique within a company
 * (`uq_accounts_company_code`). The guard that a *posted* account cannot be silently renumbered or
 * retyped is enforced in the Actions (S2-01) and, once the ledger exists, against posted lines
 * (S2-03 / S2-05); opening balances post through the posting engine (S2-05), never as a stored column
 * here — the ledger has one and only one way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE accounts (
                id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id         BIGINT NOT NULL REFERENCES companies(id),
                account_type_id    BIGINT NOT NULL REFERENCES account_types(id),
                parent_id          BIGINT NULL REFERENCES accounts(id),
                code               VARCHAR(40) NOT NULL,
                name_en            VARCHAR(255) NOT NULL,
                name_ar            VARCHAR(255) NOT NULL,
                normal_balance     VARCHAR(6) NOT NULL CHECK (normal_balance IN ('debit', 'credit')),
                status             VARCHAR(10) NOT NULL DEFAULT 'active'
                                     CHECK (status IN ('active', 'inactive')),
                is_control_account BOOLEAN NOT NULL DEFAULT false,
                control_account_of VARCHAR(40) NULL,
                created_by         BIGINT NULL REFERENCES users(id),
                updated_by         BIGINT NULL REFERENCES users(id),
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at         TIMESTAMPTZ NULL,
                CONSTRAINT chk_accounts_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
                CONSTRAINT chk_accounts_control_designation
                    CHECK (control_account_of IS NULL OR is_control_account = true),
                CONSTRAINT uq_accounts_company_code UNIQUE (company_id, code)
            )
            SQL);

        DB::statement('CREATE INDEX idx_accounts_company_parent ON accounts (company_id, parent_id)');
        DB::statement('CREATE INDEX idx_accounts_company_type ON accounts (company_id, account_type_id)');

        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE removes the table's RLS policies and indexes with it. The account_types catalogue,
        // the app_current_company_id() helper, and the companies/users tables are owned by earlier
        // migrations and are left in place.
        DB::statement('DROP TABLE IF EXISTS accounts');
    }

    /**
     * The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), identical in shape to
     * `fiscal_years` / `company_users`: a RESTRICTIVE company boundary no permissive policy can OR past,
     * plus the permissive per-verb policies that actually grant access, all keyed on the shared
     * `app_current_company_id()` helper (⇒ zero rows / rejected write when the GUC is unset).
     */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE accounts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE accounts FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY accounts_company_boundary ON accounts
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY accounts_tenant_select ON accounts
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY accounts_tenant_insert ON accounts
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY accounts_tenant_update ON accounts
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY accounts_tenant_delete ON accounts
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
