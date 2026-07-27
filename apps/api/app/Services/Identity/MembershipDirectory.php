<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads a user's company memberships for `GET /auth/me` (docs/backend/AUTH_SERVICE.md
 * "# Multi-Tenancy Enforcement" step 2: "an authenticated, self-scoped read of the caller's own
 * memberships").
 *
 * This runs BEFORE any tenant context exists, so it deliberately uses the privileged (owner)
 * connection and self-scopes by `user_id` — reading `company_users` through the RLS-scoped tenant
 * connection here would return zero rows (no company GUC is set on `/auth/me`). It never crosses to
 * another user's memberships: the `user_id` predicate is the trusted authenticated id.
 */
final class MembershipDirectory
{
    /**
     * The active, non-revoked memberships for a user, joined to company + role for display.
     *
     * @return list<array{company_uuid: string, name_en: string, name_ar: string|null, role: string, perms_ver: int}>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->connection()->table('company_users as cu')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->join('roles as r', 'r.id', '=', 'cu.role_id')
            ->where('cu.user_id', $userId)
            ->where('cu.status', 'active')
            ->whereNull('cu.deleted_at')
            ->whereNull('c.deleted_at')
            ->orderBy('c.name_en')
            ->get(['c.uuid as company_uuid', 'c.name_en', 'c.name_ar', 'r.key as role', 'cu.perms_ver']);

        $memberships = [];
        foreach ($rows as $row) {
            $memberships[] = [
                'company_uuid' => $this->asString($row->company_uuid),
                'name_en' => $this->asString($row->name_en),
                'name_ar' => $row->name_ar !== null ? $this->asString($row->name_ar) : null,
                'role' => $this->asString($row->role),
                'perms_ver' => is_numeric($row->perms_ver) ? (int) $row->perms_ver : 1,
            ];
        }

        return $memberships;
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    /** Coerce a (mixed) database row value to string. */
    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
