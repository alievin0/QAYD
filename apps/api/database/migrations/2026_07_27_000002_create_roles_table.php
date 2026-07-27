<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 — `roles` (docs/backend/AUTH_SERVICE.md "Database Tables Owned").
 *
 * A role is either a system-seeded default (`company_id IS NULL`, shared read-only across all
 * tenants) or a company-specific customization (`company_id` set). This is why `company_id` here is
 * NULLABLE, the one deliberate exception to the "tenant tables carry company_id NOT NULL" rule
 * (MULTI_TENANCY.md "What is NOT multi-tenant"). Uniqueness of (company_id, key) lets each company
 * override a key without colliding with the system default of the same key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE roles (
                id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id BIGINT NULL REFERENCES companies(id),
                key        VARCHAR(50) NOT NULL,
                name_en    VARCHAR(100) NOT NULL,
                name_ar    VARCHAR(100) NOT NULL,
                is_system  BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_roles_company_key UNIQUE (company_id, key)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS roles');
    }
};
