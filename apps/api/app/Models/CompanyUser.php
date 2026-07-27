<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's membership inside one company (docs/backend/AUTH_SERVICE.md). This is the tenant-owned
 * pivot that exists after S1-04: it carries `company_id NOT NULL`, so it uses
 * {@see BelongsToCompany} and is scoped by RLS + {@see CompanyScope} on the tenant
 * connection.
 *
 * The `ResolveTenantCompany` middleware validates membership through the privileged owner connection
 * (query builder), NOT through this model, precisely because this model is RLS-scoped and would
 * return zero rows before the company GUC is set.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int $role_id
 * @property string $status
 */
class CompanyUser extends Model
{
    use BelongsToCompany;

    protected $table = 'company_users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_scope' => 'array',
            'department_scope' => 'array',
            'joined_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
