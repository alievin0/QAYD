<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Defense-in-depth layer 2 (docs/database/MULTI_TENANCY.md "The global Eloquent scope"): injects
 * `company_id = <active company>` into every read of a tenant-owned model, mirroring the RLS policy
 * at the ORM layer for intention-revealing queries and query-planner clarity.
 *
 * It fails closed: with no resolved tenant context (a console command, a queued job that forgot to
 * bind the tenant, a bug) it adds `1 = 0` so the query returns zero rows rather than every tenant's.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = TenantContext::companyId();

        if ($companyId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.company_id', $companyId);
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutCompanyScope',
            /** @param Builder<Model> $query */
            fn (Builder $query): Builder => $query->withoutGlobalScope(self::class)
        );

        $builder->macro('forCompany',
            /** @param Builder<Model> $query */
            function (Builder $query, int $companyId): Builder {
                return $query->withoutGlobalScope(self::class)
                    ->where($query->getModel()->getTable().'.company_id', $companyId);
            }
        );
    }
}
