<?php

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-16 — the append-only `audit_logs` write path (docs/database/DATABASE_AUDIT_LOGS.md,
 * docs/backend/AUDIT_SERVICE.md).
 *
 * `audit_logs` is the system-wide, tenant-scoped ledger of mutation/auth/permission/AI events. This
 * migration lands the SKELETON the later stories (auth login, onboarding) write into via
 * {@see AuditLogger}: the table with the spec's column set, the RLS tenant
 * boundary applied exactly like every other tenant table (ENABLE + FORCE + company-keyed policies),
 * and — the defining property of an audit table — hard append-only enforcement.
 *
 * Deliberately deferred to later hardening stories (kept out of the skeleton): monthly range
 * partitioning, the per-company SHA-256 hash chain, the PL/pgSQL row-diff capture trigger + shadow
 * table, and the outbox/queue write path. The `hash`/`prev_hash` columns exist now so adding the
 * chain later needs no table rewrite. `branch_id`/`entity_id` are plain BIGINTs with no FK by design
 * (branches do not exist until a later sprint; an audit row must survive a hard-deleted entity).
 *
 * Append-only is enforced at two layers, both at the database:
 *  1. Privilege — UPDATE/DELETE are revoked from PUBLIC and the runtime `qayd_app` role; only
 *     INSERT + SELECT remain.
 *  2. A BEFORE UPDATE OR DELETE trigger that RAISES unconditionally, so even the table owner /
 *     a superuser connection cannot rewrite history (the guarantee the doc requires).
 * There are correspondingly NO update/delete RLS policies — the two write verbs simply do not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The small closed set of structural categories. The specific `action` (e.g. 'invoice.voided')
        // stays a free-form VARCHAR so new actions never need a migration; the enum is for fast
        // filtering by broad type. Guarded so migrate:fresh is idempotent whether or not the cluster
        // still carries the type from a prior run.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'audit_category') THEN
                    CREATE TYPE audit_category AS ENUM (
                        'data_mutation',
                        'auth',
                        'permission',
                        'ai_action',
                        'system'
                    );
                END IF;
            END
            $$;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id        BIGINT NULL REFERENCES companies(id),
                                    -- NULL only for platform-level events with no tenant context
                                    -- (e.g. a failed login for an unknown user). Every tenant
                                    -- mutation MUST set this.
                branch_id         BIGINT NULL,     -- no FK: branches table lands in a later sprint

                category          audit_category NOT NULL,
                action            VARCHAR(100) NOT NULL,
                                    -- dot-namespaced, e.g. 'journal_entry.posted', 'user.login_failed'

                entity_type       VARCHAR(100) NULL,   -- e.g. 'invoices', 'users'
                entity_id         BIGINT NULL,         -- PK of the affected row; no FK by design

                actor_user_id     BIGINT NULL REFERENCES users(id),
                actor_service     VARCHAR(100) NULL,   -- e.g. 'ai:reporting-agent', 'scheduler:period-close'
                acting_as_user_id BIGINT NULL REFERENCES users(id),   -- populated during impersonation

                old_values        JSONB NULL,
                new_values        JSONB NULL,
                changed_fields    TEXT[] NULL,

                reason            TEXT NULL,
                request_id        UUID NULL,           -- correlates to the API response envelope request_id
                ip_address        INET NULL,
                user_agent        TEXT NULL,
                device_id         VARCHAR(150) NULL,

                hash              CHAR(64) NULL,       -- reserved for the per-company hash chain (later)
                prev_hash         CHAR(64) NULL,

                created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
                -- No updated_at / deleted_at: audit_logs rows are append-only, never updated or
                -- soft-deleted. It is the table that records the mutability of OTHER tables and must
                -- not itself be mutable.
            )
            SQL);

        DB::statement('CREATE INDEX idx_audit_logs_company_created ON audit_logs (company_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_logs_actor ON audit_logs (actor_user_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_logs_action ON audit_logs (company_id, action, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_logs_category ON audit_logs (company_id, category, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_logs_request ON audit_logs (request_id)');
        DB::statement('CREATE INDEX idx_audit_logs_new_values_gin ON audit_logs USING GIN (new_values jsonb_path_ops)');

        $this->enforceAppendOnly();
        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE removes the table's trigger, policies and indexes; then the generic guard
        // function and the enum type (both table-independent) can be dropped in dependency order.
        DB::statement('DROP TABLE IF EXISTS audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_block_mutation()');
        DB::statement('DROP TYPE IF EXISTS audit_category');
    }

    /**
     * Hard append-only: revoke the two mutating verbs from every application-reachable grantee, and
     * add a trigger that refuses UPDATE/DELETE even for a privileged/owner connection (the doc's
     * "cannot silently rewrite history" guarantee).
     */
    private function enforceAppendOnly(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_audit_logs_immutable
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
            SQL);

        // Default privileges (previous migration) grant the runtime role full CRUD on new tables;
        // strip the two mutating verbs so only INSERT + SELECT remain at the privilege layer too.
        DB::statement('REVOKE UPDATE, DELETE ON audit_logs FROM PUBLIC');

        $role = $this->appRole();
        DB::unprepared(sprintf(
            <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = %s) THEN
                    EXECUTE format('REVOKE UPDATE, DELETE ON audit_logs FROM %%I', %s);
                    EXECUTE format('GRANT INSERT, SELECT ON audit_logs TO %%I', %s);
                END IF;
            END
            $$;
            SQL,
            $this->quoteLiteral($role),
            $this->quoteLiteral($role),
            $this->quoteLiteral($role),
        ));
    }

    /**
     * The same RLS treatment every tenant table gets (docs/database/ROW_LEVEL_SECURITY.md), reusing
     * the helper functions from the S1-05 migration. `company_id` is NULLABLE here (platform events),
     * and a platform-admin session may see/write those rows, so the predicate additionally allows
     * `app_is_platform_admin()`. Because the table is append-only there are only SELECT + INSERT
     * policies — no UPDATE/DELETE path exists for any tenant.
     */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY');

        // RESTRICTIVE boundary no permissive policy can OR past.
        DB::statement(<<<'SQL'
            CREATE POLICY audit_logs_company_boundary ON audit_logs
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id() OR app_is_platform_admin())
            WITH CHECK (company_id = app_current_company_id() OR app_is_platform_admin())
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY audit_logs_tenant_select ON audit_logs
            FOR SELECT
            USING (company_id = app_current_company_id() OR app_is_platform_admin())
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY audit_logs_tenant_insert ON audit_logs
            FOR INSERT
            WITH CHECK (company_id = app_current_company_id() OR app_is_platform_admin())
            SQL);
    }

    private function appRole(): string
    {
        $role = (string) env('DB_APP_USERNAME', 'qayd_app');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Refusing unsafe DB_APP_USERNAME: {$role}");
        }

        return $role;
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
