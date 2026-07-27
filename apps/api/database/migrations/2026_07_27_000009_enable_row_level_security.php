<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-05 — PostgreSQL Row-Level Security: the storage-engine tenant boundary
 * (docs/database/ROW_LEVEL_SECURITY.md, docs/database/MULTI_TENANCY.md "# Security").
 *
 * The GUC name is fixed once for the whole codebase here as `app.current_company_id` — the name
 * SPRINT_01 §S1-05 and its Risks table mandate (reconciling the `app.company_id` vs
 * `app.current_company_id` drift between the two database docs). The middleware, the Eloquent scope,
 * and every test read the same three GUCs: `app.current_company_id`, `app.current_user_id`,
 * `app.is_platform_admin`.
 *
 * Fail-closed is the invariant: every policy reads the GUC through `app_current_company_id()`, which
 * returns NULL when the GUC is unset, so `company_id = NULL` evaluates UNKNOWN and NO row is
 * returned. With no tenant context a raw SELECT on a tenant table yields zero rows, never another
 * tenant's — proven by tests/Feature/Rls.
 *
 * RLS only bites for a NON-superuser, NON-BYPASSRLS role (the `qayd_app` role from the previous
 * migration, used by the `pgsql_app` runtime connection). Migrations run as the owner (`qayd`), which
 * bypasses RLS — that is expected and why the owner can build/seed the schema.
 */
return new class extends Migration
{
    /**
     * Strict tenant-owned tables that exist after S1-04: `company_id BIGINT NOT NULL`.
     * They get the uniform boundary + per-verb policy shape.
     *
     * @var list<string>
     */
    private array $tenantTables = [
        'company_users',
    ];

    public function up(): void
    {
        $this->createHelperFunctions();

        foreach ($this->tenantTables as $table) {
            $this->applyTenantPolicies($table);
        }

        $this->applyCompaniesPolicies();
        $this->applyUsersPolicies();
        $this->applyRolesPolicies();
    }

    public function down(): void
    {
        foreach (['roles', 'users', 'companies'] as $table) {
            $this->dropAllPolicies($table);
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        foreach ($this->tenantTables as $table) {
            $this->dropAllPolicies($table);
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP FUNCTION IF EXISTS app_is_platform_admin()');
        DB::statement('DROP FUNCTION IF EXISTS app_current_user_id()');
        DB::statement('DROP FUNCTION IF EXISTS app_current_company_id()');
    }

    /**
     * The single place the GUC names and NULL-safety/casting live, so every policy expression is
     * short and drift-free. STABLE + PARALLEL SAFE lets the planner fold repeated calls per query.
     */
    private function createHelperFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_company_id()
            RETURNS BIGINT
            LANGUAGE sql
            STABLE
            PARALLEL SAFE
            AS $$
              SELECT NULLIF(current_setting('app.current_company_id', true), '')::BIGINT;
            $$;

            CREATE OR REPLACE FUNCTION app_current_user_id()
            RETURNS BIGINT
            LANGUAGE sql
            STABLE
            PARALLEL SAFE
            AS $$
              SELECT NULLIF(current_setting('app.current_user_id', true), '')::BIGINT;
            $$;

            CREATE OR REPLACE FUNCTION app_is_platform_admin()
            RETURNS BOOLEAN
            LANGUAGE sql
            STABLE
            PARALLEL SAFE
            AS $$
              SELECT COALESCE(NULLIF(current_setting('app.is_platform_admin', true), ''), 'false')::BOOLEAN;
            $$;

            COMMENT ON FUNCTION app_current_company_id() IS
              'Tenant company_id for the current session from GUC app.current_company_id, or NULL if unset. Read by every RLS policy; NULL denies all rows (fail-closed).';
            SQL);
    }

    /**
     * Uniform shape for a strict tenant table (docs/database/ROW_LEVEL_SECURITY.md "The uniform
     * generator"): a RESTRICTIVE company boundary that no permissive policy can OR past, plus the
     * permissive per-verb policies that actually grant access, all keyed on the same predicate.
     */
    private function applyTenantPolicies(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement("
            CREATE POLICY {$table}_company_boundary ON {$table}
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
        ");
        DB::statement("
            CREATE POLICY {$table}_tenant_select ON {$table}
            FOR SELECT USING (company_id = app_current_company_id())
        ");
        DB::statement("
            CREATE POLICY {$table}_tenant_insert ON {$table}
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
        ");
        DB::statement("
            CREATE POLICY {$table}_tenant_update ON {$table}
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
        ");
        DB::statement("
            CREATE POLICY {$table}_tenant_delete ON {$table}
            FOR DELETE USING (company_id = app_current_company_id())
        ");
    }

    /**
     * `companies` is the tenant root: it has no `company_id`, so the policy compares `id`, and a
     * session may additionally see the companies the user is a member of (for the switch-company
     * UI). Permissive SELECT grants; RESTRICTIVE would deny-all with no companion grant.
     */
    private function applyCompaniesPolicies(): void
    {
        DB::statement('ALTER TABLE companies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE companies FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY companies_select ON companies
            FOR SELECT
            USING (
                id = app_current_company_id()
                OR id IN (
                    SELECT company_id FROM company_users
                    WHERE user_id = app_current_user_id() AND deleted_at IS NULL
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY companies_update ON companies
            FOR UPDATE
            USING (id = app_current_company_id())
            WITH CHECK (id = app_current_company_id())
            SQL);

        // A brand-new company has no active-company context to check against; creation is gated by
        // the onboarding action + auth at the application layer (S1-10).
        DB::statement('CREATE POLICY companies_insert ON companies FOR INSERT WITH CHECK (true)');
    }

    /**
     * `users` is a cross-tenant identity table: a session may see itself and anyone who shares its
     * active company. Registration (S1-07) inserts a global user with no tenant context, so INSERT
     * is permitted and gated at the application layer.
     */
    private function applyUsersPolicies(): void
    {
        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY users_select ON users
            FOR SELECT
            USING (
                id = app_current_user_id()
                OR id IN (
                    SELECT cu2.user_id
                    FROM company_users cu1
                    JOIN company_users cu2 ON cu2.company_id = cu1.company_id
                    WHERE cu1.user_id = app_current_user_id()
                      AND cu1.company_id = app_current_company_id()
                      AND cu1.deleted_at IS NULL
                      AND cu2.deleted_at IS NULL
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY users_update_self ON users
            FOR UPDATE
            USING (id = app_current_user_id())
            WITH CHECK (id = app_current_user_id())
            SQL);

        DB::statement('CREATE POLICY users_insert ON users FOR INSERT WITH CHECK (true)');
    }

    /**
     * `roles` is the documented mixed table (docs/database/MULTI_TENANCY.md "What is NOT
     * multi-tenant"): system-default roles carry `company_id IS NULL` and are shared read-only across
     * every tenant; company-specific roles carry a `company_id` and are strictly tenant-scoped.
     * Reads therefore expose system rows plus the active company's rows; writes are confined to the
     * active company (system roles are seeded by the owner role, which bypasses RLS).
     */
    private function applyRolesPolicies(): void
    {
        DB::statement('ALTER TABLE roles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE roles FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY roles_select ON roles
            FOR SELECT
            USING (company_id IS NULL OR company_id = app_current_company_id())
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY roles_insert ON roles
            FOR INSERT
            WITH CHECK (company_id = app_current_company_id())
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY roles_update ON roles
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY roles_delete ON roles
            FOR DELETE
            USING (company_id = app_current_company_id())
            SQL);
    }

    private function dropAllPolicies(string $table): void
    {
        /** @var list<stdClass> $policies */
        $policies = DB::select(
            'SELECT policyname FROM pg_policies WHERE schemaname = ? AND tablename = ?',
            ['public', $table],
        );

        foreach ($policies as $policy) {
            $name = $policy->policyname;
            if (is_string($name)) {
                $quoted = '"'.str_replace('"', '""', $name).'"';
                DB::statement("DROP POLICY IF EXISTS {$quoted} ON {$table}");
            }
        }
    }
};
