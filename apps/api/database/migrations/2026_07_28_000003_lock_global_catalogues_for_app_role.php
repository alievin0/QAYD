<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-01 hardening — make the global reference catalogues (`account_types`, `permissions`) genuinely
 * read-only to the runtime application role.
 *
 * The S1-05 role migration granted `qayd_app` SELECT/INSERT/UPDATE/DELETE on every table (and, via
 * ALTER DEFAULT PRIVILEGES, on future ones), and neither catalogue carries RLS — they are global,
 * shared read-only across all tenants. Without this, the non-superuser tenant role could mutate a
 * catalogue for every tenant at once. This REVOKEs write (INSERT/UPDATE/DELETE) on those two tables
 * from the app role, leaving SELECT intact; the owner role (which runs migrations + the seeders) keeps
 * full access. Defense in depth: no application path writes these tables through `qayd_app` today (the
 * models read on the owner connection, the seeders run as the owner), so this closes the gap at the
 * grant layer rather than fixing a live exploit.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $catalogues = ['account_types', 'permissions'];

    public function up(): void
    {
        $role = $this->quoteIdentifier($this->appRole());

        foreach ($this->catalogues as $table) {
            DB::statement("REVOKE INSERT, UPDATE, DELETE ON {$this->quoteIdentifier($table)} FROM {$role}");
        }
    }

    public function down(): void
    {
        $role = $this->quoteIdentifier($this->appRole());

        // Restore the DML the S1-05 default-privilege grant would otherwise give the app role.
        foreach ($this->catalogues as $table) {
            DB::statement("GRANT INSERT, UPDATE, DELETE ON {$this->quoteIdentifier($table)} TO {$role}");
        }
    }

    /** The runtime app role, resolved + validated exactly as the S1-05 create-role migration does. */
    private function appRole(): string
    {
        $role = (string) env('DB_APP_USERNAME', 'qayd_app');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Refusing unsafe DB_APP_USERNAME: {$role}");
        }

        return $role;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
