<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant root (docs/database/MULTI_TENANCY.md "The companies table"). Not tenant-scoped itself —
 * it *is* the tenant — so it does NOT use {@see BelongsToCompany}. It is
 * resolved from the `X-Company-Id` (UUID) header on the privileged owner connection before any
 * tenant GUC is set, which is why it must not live on the RLS-scoped connection.
 *
 * @property int $id
 * @property string $uuid
 * @property string $status
 */
class Company extends Model
{
    protected $table = 'companies';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
