<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one place the tenant session contract is named.
 *
 * The GUC (PostgreSQL session variable) names are fixed for the whole codebase per SPRINT_01
 * §S1-05 — reconciling the `app.company_id` vs `app.current_company_id` drift between the database
 * docs — and are referenced from the middleware, the RLS migration, and the isolation tests through
 * these constants so they can never disagree.
 */
final class TenantContext
{
    /** GUC holding the active company id (BIGINT as text). Unset ⇒ no tenant context ⇒ zero rows. */
    public const GUC_COMPANY_ID = 'app.current_company_id';

    /** GUC holding the authenticated user id (BIGINT as text). */
    public const GUC_USER_ID = 'app.current_user_id';

    /** GUC holding whether the session is a platform-admin session ('true'/'false'). */
    public const GUC_IS_PLATFORM_ADMIN = 'app.is_platform_admin';

    /** Default runtime connection name — a NON-superuser, NOBYPASSRLS role, so RLS enforces. */
    public const CONNECTION = 'pgsql_app';

    /** The container binding key holding the resolved active company id for the request. */
    public const BINDING_COMPANY_ID = 'tenant.company_id';

    /**
     * The connection tenant-owned models and the tenant middleware run on.
     */
    public static function connection(): string
    {
        $connection = config('tenancy.connection', self::CONNECTION);

        return is_string($connection) ? $connection : self::CONNECTION;
    }

    /**
     * The active company id resolved for the current request, or null outside a tenant context.
     */
    public static function companyId(): ?int
    {
        if (! app()->bound(self::BINDING_COMPANY_ID)) {
            return null;
        }

        $companyId = app(self::BINDING_COMPANY_ID);

        return is_numeric($companyId) ? (int) $companyId : null;
    }
}
