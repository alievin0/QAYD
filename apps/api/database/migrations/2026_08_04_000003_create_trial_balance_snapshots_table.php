<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-09 — `trial_balance_snapshots` + `trial_balance_snapshot_lines`
 * (docs/accounting/TRIAL_BALANCE.md "# Data Model").
 *
 * A trial balance is *derived* — every figure in it is a `SUM` over `ledger_entries`, and recomputing
 * it always reproduces it. So why store one? Because a snapshot is not a cache of a derived number: it
 * is the immutable record of **what a human asserted the ledger said at a point in time**, which is a
 * different fact from what the ledger says now. An approved snapshot is referenced by an auditor
 * forever, and it must not silently change when a later backdated entry lands.
 *
 * That distinction is what keeps the ledger's governing rule intact, and it is enforced here rather
 * than trusted:
 *   - `trg_tbs_immutable_when_final` — once a snapshot is `approved` or `archived`, its figures, scope
 *     and identity can never be updated again. Only the archive fields may still move.
 *   - `trg_tbsl_append_only` — a snapshot LINE can never be updated at all. Correcting a trial balance
 *     means generating a NEW version (`parent_snapshot_id`, `version + 1`), never editing the old one,
 *     exactly as TRIAL_BALANCE.md requires ("the existing snapshot is never mutated").
 *   - `ux_tbs_current_logical_key` — at most one `is_current` snapshot per (company, period, type), so
 *     "the authoritative trial balance for June" always has exactly one answer.
 *   - `chk_tbs_variance_matches` — `variance` is not free-form: it must equal `total_debit -
 *     total_credit`, so a snapshot cannot claim to balance while its own totals disagree.
 *
 * DEFERRED, exactly as S2-03 and S2-05 deferred theirs: the `branch_id` / `department_id` /
 * `project_id` / `cost_center_id` dimension columns the frozen DDL carries. Their tables do not exist,
 * so those FKs cannot be real (TD-14). They land with the modules that own them, and the logical-key
 * index is written so adding them later widens an index rather than migrating data.
 *
 * The frozen DDL qualifies these tables as `accounting.*`; every shipped table in this database lives
 * in `public`, so they are created unqualified to match the schema that actually exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'trial_balance_type') THEN
                    CREATE TYPE trial_balance_type AS ENUM ('unadjusted', 'adjusted', 'post_closing');
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'trial_balance_status') THEN
                    CREATE TYPE trial_balance_status AS ENUM (
                        'generating', 'generated', 'validated', 'out_of_balance',
                        'under_review', 'approved', 'archived'
                    );
                END IF;
            END
            $$;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE trial_balance_snapshots (
                id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id          BIGINT NOT NULL REFERENCES companies(id),
                fiscal_year_id      BIGINT NULL REFERENCES fiscal_years(id),
                fiscal_period_id    BIGINT NULL REFERENCES fiscal_periods(id),
                as_of_date          DATE NOT NULL,
                period_start_date   DATE NOT NULL,
                type                trial_balance_type NOT NULL DEFAULT 'unadjusted',
                status              trial_balance_status NOT NULL DEFAULT 'generating',
                parent_snapshot_id  BIGINT NULL REFERENCES trial_balance_snapshots(id),
                version             INTEGER NOT NULL DEFAULT 1,
                is_current          BOOLEAN NOT NULL DEFAULT true,
                currency_code       VARCHAR(3) NOT NULL,
                total_debit         NUMERIC(19,4) NOT NULL DEFAULT 0,
                total_credit        NUMERIC(19,4) NOT NULL DEFAULT 0,
                variance            NUMERIC(19,4) NOT NULL DEFAULT 0,
                rounding_tolerance  NUMERIC(19,4) NOT NULL DEFAULT 0.0050,
                has_warnings        BOOLEAN NOT NULL DEFAULT false,
                account_count       INTEGER NOT NULL DEFAULT 0,
                line_count          INTEGER NOT NULL DEFAULT 0,
                content_hash        VARCHAR(64) NULL,
                generation_mode     VARCHAR(20) NOT NULL DEFAULT 'manual',
                generated_by        BIGINT NULL REFERENCES users(id),
                generated_at        TIMESTAMPTZ NULL,
                validated_at        TIMESTAMPTZ NULL,
                approved_at         TIMESTAMPTZ NULL,
                approved_by         BIGINT NULL REFERENCES users(id),
                archived_by         BIGINT NULL REFERENCES users(id),
                archived_at         TIMESTAMPTZ NULL,
                is_locked           BOOLEAN NOT NULL DEFAULT false,
                notes               TEXT NULL,
                created_by          BIGINT NULL REFERENCES users(id),
                updated_by          BIGINT NULL REFERENCES users(id),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at          TIMESTAMPTZ NULL,
                CONSTRAINT chk_tbs_variance_matches CHECK (variance = total_debit - total_credit),
                CONSTRAINT chk_tbs_dates CHECK (as_of_date >= period_start_date),
                CONSTRAINT chk_tbs_version_min CHECK (version >= 1),
                CONSTRAINT chk_tbs_no_self_parent CHECK (parent_snapshot_id IS NULL OR parent_snapshot_id <> id),
                CONSTRAINT chk_tbs_post_closing_requires_year CHECK (
                    type <> 'post_closing' OR fiscal_year_id IS NOT NULL
                )
            )
            SQL);

        // Exactly one authoritative trial balance per (company, period, type). Dimension columns are
        // deferred, so the logical key is the subset that exists today; adding them later widens this
        // index without touching a row.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ux_tbs_current_logical_key ON trial_balance_snapshots (
                company_id, COALESCE(fiscal_period_id, 0), as_of_date, type
            ) WHERE is_current = true AND deleted_at IS NULL
            SQL);
        DB::statement('CREATE INDEX idx_tbs_company_period ON trial_balance_snapshots (company_id, fiscal_period_id)');
        DB::statement('CREATE INDEX idx_tbs_status ON trial_balance_snapshots (company_id, status)');

        DB::statement(<<<'SQL'
            CREATE TABLE trial_balance_snapshot_lines (
                id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                snapshot_id         BIGINT NOT NULL REFERENCES trial_balance_snapshots(id) ON DELETE CASCADE,
                company_id          BIGINT NOT NULL REFERENCES companies(id),
                account_id          BIGINT NOT NULL REFERENCES accounts(id),
                account_code        VARCHAR(30) NOT NULL,
                account_name_en     VARCHAR(255) NOT NULL,
                account_name_ar     VARCHAR(255) NULL,
                account_type_id     BIGINT NOT NULL REFERENCES account_types(id),
                normal_balance      VARCHAR(6) NOT NULL,
                opening_debit       NUMERIC(19,4) NOT NULL DEFAULT 0,
                opening_credit      NUMERIC(19,4) NOT NULL DEFAULT 0,
                period_debit        NUMERIC(19,4) NOT NULL DEFAULT 0,
                period_credit       NUMERIC(19,4) NOT NULL DEFAULT 0,
                closing_debit       NUMERIC(19,4) NOT NULL DEFAULT 0,
                closing_credit      NUMERIC(19,4) NOT NULL DEFAULT 0,
                is_abnormal_balance BOOLEAN NOT NULL DEFAULT false,
                source_line_count   INTEGER NOT NULL DEFAULT 0,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT chk_tbsl_debit_xor_credit_closing CHECK (closing_debit = 0 OR closing_credit = 0),
                CONSTRAINT chk_tbsl_nonnegative CHECK (
                    opening_debit >= 0 AND opening_credit >= 0 AND
                    period_debit  >= 0 AND period_credit  >= 0 AND
                    closing_debit >= 0 AND closing_credit >= 0
                ),
                CONSTRAINT uq_tbsl_line_key UNIQUE (snapshot_id, account_id)
            )
            SQL);

        DB::statement('CREATE INDEX idx_tbsl_snapshot ON trial_balance_snapshot_lines (snapshot_id)');
        DB::statement('CREATE INDEX idx_tbsl_company_account ON trial_balance_snapshot_lines (company_id, account_id)');

        $this->applyImmutabilityTriggers();
        $this->applyRowLevelSecurity('trial_balance_snapshots');
        $this->applyRowLevelSecurity('trial_balance_snapshot_lines');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS trial_balance_snapshot_lines');
        DB::statement('DROP TABLE IF EXISTS trial_balance_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS trial_balance_snapshot_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS trial_balance_line_append_only()');
        DB::statement('DROP TYPE IF EXISTS trial_balance_status');
        DB::statement('DROP TYPE IF EXISTS trial_balance_type');
    }

    /**
     * The immutability half of "a snapshot is an assertion, not a cache".
     *
     * A snapshot's LINES are append-only outright: there is no legitimate reason to edit one, because a
     * corrected trial balance is a new version. The HEADER stays editable while the run is still in
     * flight (`generating` → `generated` → `validated`/`out_of_balance` → `approved`) and freezes on
     * approval — so approving is the point of no return the audit trail depends on.
     *
     * Enforced in the database, not the Action, for the same reason the ledger's append-only trigger
     * is: an application guarantee only protects the paths the application knows about.
     */
    private function applyImmutabilityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trial_balance_snapshot_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('approved', 'archived') THEN
                    IF NEW.total_debit       IS DISTINCT FROM OLD.total_debit
                    OR NEW.total_credit      IS DISTINCT FROM OLD.total_credit
                    OR NEW.variance          IS DISTINCT FROM OLD.variance
                    OR NEW.account_count     IS DISTINCT FROM OLD.account_count
                    OR NEW.line_count        IS DISTINCT FROM OLD.line_count
                    OR NEW.content_hash      IS DISTINCT FROM OLD.content_hash
                    OR NEW.as_of_date        IS DISTINCT FROM OLD.as_of_date
                    OR NEW.period_start_date IS DISTINCT FROM OLD.period_start_date
                    OR NEW.fiscal_period_id  IS DISTINCT FROM OLD.fiscal_period_id
                    OR NEW.type              IS DISTINCT FROM OLD.type
                    OR NEW.version           IS DISTINCT FROM OLD.version
                    OR NEW.approved_by       IS DISTINCT FROM OLD.approved_by
                    OR NEW.approved_at       IS DISTINCT FROM OLD.approved_at
                    THEN
                        RAISE EXCEPTION
                            'trial balance snapshot % is % and its figures are immutable; generate a new version instead',
                            OLD.id, OLD.status
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION trial_balance_line_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'trial_balance_snapshot_lines is append-only (attempted %)', TG_OP
                    USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_tbs_immutable_when_final
                BEFORE UPDATE ON trial_balance_snapshots
                FOR EACH ROW EXECUTE FUNCTION trial_balance_snapshot_immutable()
            SQL);

        // UPDATE only: the parent's ON DELETE CASCADE must still be able to remove lines when a
        // never-approved run is discarded, so DELETE stays with the parent's lifecycle.
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_tbsl_append_only
                BEFORE UPDATE ON trial_balance_snapshot_lines
                FOR EACH ROW EXECUTE FUNCTION trial_balance_line_append_only()
            SQL);
    }

    /**
     * The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md): a RESTRICTIVE company
     * boundary no permissive policy can OR past, plus the permissive per-verb policies. With no tenant
     * GUC set a read returns zero rows and a write is rejected — including from a queue worker, which
     * is exactly the isolation the async generation path depends on.
     */
    private function applyRowLevelSecurity(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement("CREATE POLICY {$table}_company_boundary ON {$table}
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())");
        DB::statement("CREATE POLICY {$table}_tenant_select ON {$table}
            FOR SELECT USING (company_id = app_current_company_id())");
        DB::statement("CREATE POLICY {$table}_tenant_insert ON {$table}
            FOR INSERT WITH CHECK (company_id = app_current_company_id())");
        DB::statement("CREATE POLICY {$table}_tenant_update ON {$table}
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())");
        DB::statement("CREATE POLICY {$table}_tenant_delete ON {$table}
            FOR DELETE USING (company_id = app_current_company_id())");
    }
};
