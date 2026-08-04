<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Run a queued job's work inside a real tenant context (S2-09, docs/database/MULTI_TENANCY.md).
 *
 * This is the project's first background execution path, and tenancy does not survive the trip on its
 * own: the HTTP middleware that normally pins the company is long gone by the time a worker picks the
 * job up, and the worker's database connection is pooled and shared with whatever ran before it. Four
 * properties make that safe, and each is deliberate:
 *
 *  1. **The company id travels in the job payload, never as ambient state.** A job is constructed with
 *     the company it belongs to and serialized with it. Nothing here reads "the current company",
 *     because at execution time there is no such thing.
 *
 *  2. **The GUCs are transaction-local (`set_config(..., true)`), inside an explicit transaction.**
 *     PostgreSQL discards them at COMMIT or ROLLBACK, so a job that throws, times out, or is killed
 *     cannot leave a pooled connection still carrying a tenant for the next job — or, under
 *     transaction pooling, for a different application entirely. A session-level `SET` would leak
 *     exactly that way, which is why it is not used.
 *
 *  3. **Fail-closed twice over.** A job with no usable company id throws before it touches the
 *     database. And if it somehow ran anyway, RLS with no GUC set resolves `app_current_company_id()`
 *     to NULL, which returns zero rows and rejects every write — the boundary does not depend on this
 *     trait being correct.
 *
 *  4. **The container binding is restored, not just set.** A queue worker is a long-lived process that
 *     handles many jobs; leaving `tenant.company_id` bound would silently hand the next job this job's
 *     tenant. It is restored in a `finally`, so that holds even when the work throws.
 *
 * A background job is never a platform-admin session, so `app.is_platform_admin` is pinned to `false`
 * rather than inherited from anywhere.
 */
trait RunsInTenantContext
{
    /**
     * Execute `$work` with the tenant GUCs set for `$companyId`, in one transaction on the
     * RLS-enforced connection.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws RuntimeException when the job carries no usable company — refuse rather than run
     *                          unscoped
     */
    protected function runInTenantContext(int $companyId, ?int $actorUserId, Closure $work): mixed
    {
        if ($companyId <= 0) {
            throw new RuntimeException(
                'A queued job cannot run without a company: refusing to execute outside a tenant context.'
            );
        }

        $connection = DB::connection(TenantContext::connection());

        $previousCompanyId = TenantContext::companyId();

        // Bind for the Eloquent CompanyScope + BelongsToCompany auto-fill, which read the container
        // rather than the GUC. Both halves must agree, or a model write would be scoped one way and
        // checked by RLS another.
        app()->instance(TenantContext::BINDING_COMPANY_ID, $companyId);

        try {
            return $connection->transaction(function () use ($connection, $companyId, $actorUserId, $work): mixed {
                $connection->select('SELECT set_config(?, ?, true)', [
                    TenantContext::GUC_COMPANY_ID, (string) $companyId,
                ]);
                $connection->select('SELECT set_config(?, ?, true)', [
                    TenantContext::GUC_USER_ID, (string) ($actorUserId ?? 0),
                ]);
                $connection->select('SELECT set_config(?, ?, true)', [
                    TenantContext::GUC_IS_PLATFORM_ADMIN, 'false',
                ]);

                return $work();
            });
        } finally {
            if ($previousCompanyId !== null) {
                app()->instance(TenantContext::BINDING_COMPANY_ID, $previousCompanyId);
            } else {
                app()->forgetInstance(TenantContext::BINDING_COMPANY_ID);
            }
        }
    }
}
