<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 — `company_user_permissions` (docs/backend/AUTH_SERVICE.md "Database Tables Owned").
 *
 * Per-membership permission overrides — the `grant`/`deny` deltas the S1-09 PermissionResolver
 * composes as `role ∪ grant − deny`. Scoped to a company through its `company_user_id` FK (not a
 * direct `company_id`); `expires_at` supports temporary grants (e.g. an external auditor). UNIQUE
 * (company_user_id, permission_id) means at most one override per (membership, permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE company_user_permissions (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_user_id BIGINT NOT NULL REFERENCES company_users(id),
                permission_id   BIGINT NOT NULL REFERENCES permissions(id),
                effect          VARCHAR(6) NOT NULL CHECK (effect IN ('grant', 'deny')),
                expires_at      TIMESTAMPTZ NULL,
                created_by      BIGINT NULL REFERENCES users(id),
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_cup UNIQUE (company_user_id, permission_id)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS company_user_permissions');
    }
};
