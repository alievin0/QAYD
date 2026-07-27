<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-05 — the runtime application database role `qayd_app` (docs/database/ROW_LEVEL_SECURITY.md
 * "# Roles").
 *
 * RLS is silently a no-op for a PostgreSQL SUPERUSER or a role with the BYPASSRLS attribute — even
 * with FORCE ROW LEVEL SECURITY. The dev/CI owner role (`qayd`) is a superuser, so if the
 * application served tenant traffic as `qayd`, every policy in the next migration would be bypassed
 * and an isolation test would wrongly "pass" by seeing all rows. This migration therefore creates a
 * dedicated, LOGIN, NOT superuser, NOBYPASSRLS role that the application uses for all runtime /
 * tenant-scoped queries (the `pgsql_app` connection), so RLS actually applies. Migrations continue
 * to run as the owner (`qayd`), which owns every table.
 *
 * The role is a cluster-level object that survives `migrate:fresh` (which only drops tables), so
 * CREATE ROLE is idempotent; the table/sequence GRANTs live here so they are re-applied every time
 * the schema is rebuilt. Credentials come from env with dev defaults so the connection works out of
 * the box locally and in CI.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = $this->appRole();
        $password = $this->appPassword();

        // Idempotent CREATE ROLE (roles are cluster-level and survive migrate:fresh).
        // Password is interpolated as a quoted literal because CREATE ROLE cannot be parameterized;
        // the role name is validated to a safe identifier and the password is single-quote-escaped.
        DB::unprepared(sprintf(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = %s) THEN
                    EXECUTE format(
                        'CREATE ROLE %%I LOGIN PASSWORD %%L NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT',
                        %s, %s
                    );
                END IF;
            END
            $$;
            SQL,
            $this->quoteLiteral($role),
            $this->quoteLiteral($role),
            $this->quoteLiteral($password),
        ));

        // Keep the password in sync with env on every run (dev/CI convenience; harmless in prod
        // where the password matches). Uses format(%I/%L) so the identifier/literal are escaped.
        DB::unprepared(sprintf(
            "DO $$ BEGIN EXECUTE format('ALTER ROLE %%I WITH LOGIN PASSWORD %%L', %s, %s); END $$;",
            $this->quoteLiteral($role),
            $this->quoteLiteral($password),
        ));

        $roleIdent = $this->quoteIdentifier($role);
        $database = $this->quoteIdentifier((string) DB::connection()->getDatabaseName());

        // Connect + schema usage.
        DB::statement("GRANT CONNECT ON DATABASE {$database} TO {$roleIdent}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$roleIdent}");

        // Runtime CRUD on the current tables; RLS policies (next migration) are what scope it.
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$roleIdent}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$roleIdent}");

        // Default privileges so future tables/sequences created by the owner are covered too.
        DB::statement(
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$roleIdent}"
        );
        DB::statement(
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$roleIdent}"
        );
    }

    public function down(): void
    {
        $role = $this->appRole();
        $roleIdent = $this->quoteIdentifier($role);
        $database = $this->quoteIdentifier((string) DB::connection()->getDatabaseName());

        // Revoke everything, but do NOT DROP the role: it may still own nothing yet be referenced by
        // active sessions/other databases in a shared cluster. Reversible enough for a migration.
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM {$roleIdent}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM {$roleIdent}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$roleIdent}");
        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$roleIdent}");
        DB::statement("REVOKE USAGE ON SCHEMA public FROM {$roleIdent}");
        DB::statement("REVOKE CONNECT ON DATABASE {$database} FROM {$roleIdent}");
    }

    private function appRole(): string
    {
        $role = (string) env('DB_APP_USERNAME', 'qayd_app');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Refusing unsafe DB_APP_USERNAME: {$role}");
        }

        return $role;
    }

    private function appPassword(): string
    {
        return (string) env('DB_APP_PASSWORD', 'qayd_app_password');
    }

    /** Double-quote a validated SQL identifier. */
    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** Single-quote a SQL string literal. */
    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
