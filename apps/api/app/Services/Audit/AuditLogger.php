<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditCategory;
use App\Models\AuditLog;
use App\Support\RequestId;
use Illuminate\Support\Facades\Auth;

/**
 * The single write helper for the append-only audit ledger (S1-16, docs/database/DATABASE_AUDIT_LOGS.md
 * "## Laravel: the Auditable trait and Observer", docs/backend/AUDIT_SERVICE.md).
 *
 * Later stories call {@see record()} to log auth, onboarding, permission, and mutation events. It
 * stamps every row with the request correlation id ({@see RequestId}), the acting user, and the
 * request's IP/user-agent, then persists through {@see AuditLog} (RLS-scoped, `pgsql_app` connection).
 * `company_id` is auto-filled from the resolved tenant context by the BelongsToCompany trait unless a
 * value is passed explicitly (e.g. a login that already knows the target company).
 *
 * This is the skeleton write path only — it is deliberately NOT yet wired into auth/onboarding (those
 * are later stories) and does not implement the async outbox/queue, hash chain, or PII masking layers
 * the full audit service adds.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(
        string $action,
        AuditCategory $category = AuditCategory::DataMutation,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $actorUserId = null,
        ?string $actorService = null,
        ?int $actingAsUserId = null,
    ): AuditLog {
        $request = request();

        $attributes = [
            'category' => $category,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_user_id' => $actorUserId ?? Auth::id(),
            'actor_service' => $actorService,
            'acting_as_user_id' => $actingAsUserId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => self::changedFields($newValues),
            'reason' => $reason,
            'request_id' => RequestId::get(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        if ($companyId !== null) {
            $attributes['company_id'] = $companyId;
        }

        if ($branchId !== null) {
            $attributes['branch_id'] = $branchId;
        }

        return AuditLog::create($attributes);
    }

    /**
     * The denormalized list of top-level keys present in the new-value snapshot, so "who changed
     * column X" stays index-friendly.
     *
     * @param  array<string, mixed>|null  $newValues
     * @return list<string>|null
     */
    private static function changedFields(?array $newValues): ?array
    {
        if ($newValues === null) {
            return null;
        }

        // Coerce to strings: at runtime an array key may be an int (a list passed as new-values),
        // and the text[] column / cast is strictly a list of strings.
        $fields = [];
        foreach (array_keys($newValues) as $key) {
            $fields[] = (string) $key;
        }

        return $fields;
    }
}
