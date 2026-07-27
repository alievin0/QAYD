<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 — `role_permissions` (docs/backend/AUTH_SERVICE.md "Database Tables Owned").
 *
 * The role → permission join. A pure junction: composite PRIMARY KEY (role_id, permission_id),
 * no surrogate id, no standard timestamp columns — exactly as the spec DDL declares. It is not
 * company-scoped; a company-specific role (roles.company_id set) simply owns its own rows here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE role_permissions (
                role_id       BIGINT NOT NULL REFERENCES roles(id),
                permission_id BIGINT NOT NULL REFERENCES permissions(id),
                PRIMARY KEY (role_id, permission_id)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS role_permissions');
    }
};
