<?php

use App\Support\TenantContext;

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant connection
    |--------------------------------------------------------------------------
    |
    | The database connection tenant-owned models and the tenant middleware use for all
    | RLS-enforced work. It must authenticate as a NON-superuser, NOBYPASSRLS role so PostgreSQL
    | Row-Level Security actually applies. The default `pgsql` connection (the schema owner) is used
    | for migrations and privileged auth lookups and deliberately bypasses RLS.
    |
    */
    'connection' => env('DB_TENANT_CONNECTION', TenantContext::CONNECTION),

    /*
    |--------------------------------------------------------------------------
    | Session GUC names
    |--------------------------------------------------------------------------
    |
    | The PostgreSQL session variables (GUCs) the middleware sets and every RLS policy reads. Fixed
    | for the whole codebase per SPRINT_01 §S1-05; the deployed name is asserted in tests.
    |
    */
    'guc' => [
        'company_id' => TenantContext::GUC_COMPANY_ID,
        'user_id' => TenantContext::GUC_USER_ID,
        'is_platform_admin' => TenantContext::GUC_IS_PLATFORM_ADMIN,
    ],
];
