<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 follow-up — add a UNIQUE index on `users.uuid` (resolves TECH_DEBT TD-02).
 *
 * The S1-04 `users` table (docs/backend/AUTH_SERVICE.md DDL) declared only `uq_users_email` and
 * left `uuid` with a default but NO uniqueness. ADR-0010 (AUTH_SERVICE.md authoritative for the
 * identity/RBAC schema) makes ONE deliberate carve-out: `uuid` uniqueness follows
 * MULTI_TENANCY.md's tenant-ID rule (every `uuid` is UNIQUE), because a `uuid` is a stable,
 * publicly-exposed identifier and a non-unique one is a latent correctness bug. `companies.uuid`
 * already carries `companies_uuid_uk`; this migration brings `users.uuid` in line.
 *
 * Kept as a separate, reversible migration (not an edit to the committed S1-04 migration), matching
 * the S1-05 `is_platform_admin` pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX uq_users_uuid ON users (uuid)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_users_uuid');
    }
};
