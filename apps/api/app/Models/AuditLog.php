<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PostgresTextArray;
use App\Enums\AuditCategory;
use App\Models\Concerns\BelongsToCompany;
use App\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The append-only audit ledger row (S1-16, docs/database/DATABASE_AUDIT_LOGS.md,
 * docs/backend/AUDIT_SERVICE.md).
 *
 * Written only through {@see AuditLogger}; later stories (auth login, onboarding)
 * record via that service. It uses {@see BelongsToCompany} so it is tenant-scoped by RLS +
 * {@see CompanyScope} and bound to the RLS-enforced `pgsql_app` connection — the same treatment as
 * every tenant table (its `company_id` is NULLABLE for platform-level events, so it is exempt from the
 * strict-tenant arch check, but the RLS boundary still applies).
 *
 * Insert-only is enforced in depth: the database revokes UPDATE/DELETE and a trigger rejects them;
 * this model additionally throws on any update/delete attempt so the append-only contract is visible
 * and enforced in application code too. There is no `updated_at` — only `created_at`.
 *
 * @property int $id
 * @property int|null $company_id
 * @property AuditCategory $category
 * @property string $action
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property int|null $actor_user_id
 * @property string|null $request_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property list<string>|null $changed_fields
 */
class AuditLog extends Model
{
    use BelongsToCompany;

    protected $table = 'audit_logs';

    /** Append-only: only `created_at` exists, and it is set by the database default. */
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('audit_logs is append-only: rows cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('audit_logs is append-only: rows cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => PostgresTextArray::class,
            'created_at' => 'datetime',
        ];
    }
}
