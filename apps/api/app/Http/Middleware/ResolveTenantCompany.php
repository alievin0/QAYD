<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth layer 1 (docs/database/MULTI_TENANCY.md "Resolving the active company per
 * request"): turns an authenticated *person* into a scoped *tenant context* for exactly one company.
 *
 * Flow:
 *  1. Resolve the `X-Company-Id` (UUID) header to a company on the privileged owner connection.
 *  2. Confirm the authenticated user holds a live `company_users` membership for it — the check runs
 *     on the owner connection (which bypasses RLS) with trusted inputs (auth user id + requested
 *     company), never on the RLS-scoped tenant connection where a pre-context read would return zero
 *     rows. Tenant context is therefore derived from the verified membership, never from raw client
 *     input.
 *  3. On any failure to establish that membership, return **404** (never 403): a 403 would confirm
 *     the company exists, an enumeration side-channel; 404 leaks nothing.
 *  4. On success, open the request's DB transaction on the tenant connection and `SET LOCAL` the
 *     three RLS GUCs there, so PostgreSQL enforces the same boundary as the independent backstop.
 *
 * `set_config(name, value, true)` is the parameter-safe, transaction-local form of `SET LOCAL`
 * (the `true` flag = local); it must run inside the transaction opened here so the setting survives
 * for the request and is discarded on commit/rollback — the PgBouncer-transaction-pooling safety
 * property the docs require.
 */
final class ResolveTenantCompany
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $companyUuid = $request->header('X-Company-Id');

        if (! is_string($companyUuid) || trim($companyUuid) === '') {
            abort(400, 'X-Company-Id header is required.');
        }

        $company = Company::query()
            ->where('uuid', $companyUuid)
            ->where('status', '!=', 'archived')
            ->whereNull('deleted_at')
            ->first();

        // 404 (not 403) for a company that does not exist OR that the user is not a member of: both
        // must be indistinguishable so an attacker cannot enumerate tenants (SPRINT_01 §S1-06).
        if (! $company instanceof Company) {
            abort(404, 'Not found.');
        }

        $isMember = $company->getConnection()->table('company_users')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();

        if (! $isMember && ! $user->is_platform_admin) {
            abort(404, 'Not found.');
        }

        // Pin the verified company for the Eloquent scope + downstream code.
        app()->instance(TenantContext::BINDING_COMPANY_ID, $company->id);
        app()->instance('tenant.company', $company);

        $tenant = DB::connection(TenantContext::connection());

        return $tenant->transaction(function () use ($tenant, $next, $request, $company, $user): Response {
            $tenant->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_COMPANY_ID, (string) $company->id]);
            $tenant->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_USER_ID, (string) $user->id]);
            $tenant->select('SELECT set_config(?, ?, true)', [
                TenantContext::GUC_IS_PLATFORM_ADMIN,
                $user->is_platform_admin ? 'true' : 'false',
            ]);

            return $next($request);
        });
    }
}
