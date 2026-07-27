<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-05 — add `users.is_platform_admin`.
 *
 * The `ResolveTenantCompany` middleware (docs/database/MULTI_TENANCY.md "Resolving the active
 * company per request") reads `$request->user()->is_platform_admin`, and the RLS policies expose a
 * read-only cross-tenant escape hatch keyed on the `app.is_platform_admin` GUC. S1-04's `users`
 * table (docs/backend/AUTH_SERVICE.md) has no such column, so this NEW migration adds it rather
 * than rewriting the committed S1-04 migration. Boolean, NOT NULL, default false: identity grants
 * nothing by itself, so a user is a normal tenant member unless explicitly elevated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE users ADD COLUMN is_platform_admin BOOLEAN NOT NULL DEFAULT false'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS is_platform_admin');
    }
};
