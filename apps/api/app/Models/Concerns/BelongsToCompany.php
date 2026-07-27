<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Scopes\CompanyScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as tenant-owned (docs/database/MULTI_TENANCY.md "A base model … BelongsToCompany").
 *
 * Applying this trait:
 *  - adds the {@see CompanyScope} global scope (auto-scopes every read by the active company);
 *  - auto-fills `company_id` (and, where the table has them, `branch_id`/`created_by`/`updated_by`)
 *    on creation from the resolved tenant/auth context;
 *  - binds the model to the RLS-enforced tenant connection (a non-superuser role), so a tenant model
 *    can never accidentally read through the privileged owner connection.
 *
 * The column guards matter because not every tenant table has the full audit-column set — S1-04's
 * `company_users`, for instance, has `company_id` but no `branch_id`/`created_by`/`updated_by`.
 *
 * Every model whose table carries `company_id NOT NULL` MUST use this trait; an arch test
 * (tests/Feature/Rls) fails the build otherwise.
 */
trait BelongsToCompany
{
    /** @var array<string, array<string, bool>> table => column => exists */
    private static array $tenantColumnCache = [];

    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('company_id')) && ($companyId = TenantContext::companyId()) !== null) {
                $model->setAttribute('company_id', $companyId);
            }

            if (self::hasTenantColumn($model, 'branch_id')
                && blank($model->getAttribute('branch_id'))
                && app()->bound('tenant.branch_id')) {
                $model->setAttribute('branch_id', app('tenant.branch_id'));
            }

            if (auth()->check()) {
                if (self::hasTenantColumn($model, 'created_by') && blank($model->getAttribute('created_by'))) {
                    $model->setAttribute('created_by', auth()->id());
                }
                if (self::hasTenantColumn($model, 'updated_by')) {
                    $model->setAttribute('updated_by', auth()->id());
                }
            }
        });

        static::updating(function (Model $model): void {
            if (auth()->check() && self::hasTenantColumn($model, 'updated_by')) {
                $model->setAttribute('updated_by', auth()->id());
            }
        });
    }

    /**
     * Bind every tenant-owned model instance to the RLS-enforced (non-superuser) connection.
     */
    public function initializeBelongsToCompany(): void
    {
        $this->setConnection(TenantContext::connection());
    }

    private static function hasTenantColumn(Model $model, string $column): bool
    {
        $table = $model->getTable();

        if (! isset(self::$tenantColumnCache[$table][$column])) {
            self::$tenantColumnCache[$table][$column] = $model->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($table, $column);
        }

        return self::$tenantColumnCache[$table][$column];
    }
}
