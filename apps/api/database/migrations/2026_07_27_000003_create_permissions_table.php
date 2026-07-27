<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 — `permissions` (docs/backend/AUTH_SERVICE.md "Database Tables Owned").
 *
 * The platform-wide permission catalogue: a global reference table, deliberately NOT company-scoped
 * (MULTI_TENANCY.md lists `permissions` among the global, non-tenant tables). Per-company grants are
 * expressed elsewhere (role_permissions, company_user_permissions), never by cloning this catalogue.
 * Seeding the real permission rows is a later story (S1-09); this migration only creates the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE permissions (
                id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key          VARCHAR(80) NOT NULL,
                area         VARCHAR(40) NOT NULL,
                is_sensitive BOOLEAN NOT NULL DEFAULT false,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_permissions_key UNIQUE (key)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS permissions');
    }
};
