<?php

declare(strict_types=1);

namespace App\Repositories\Identity;

use App\Domain\Identity\MembershipRecord;
use App\Services\Identity\MembershipDirectory;
use App\Services\Identity\PermissionResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The authorization-side reads of the identity module (docs/backend/AUTH_SERVICE.md
 * "# Key Classes — PermissionResolver"): the membership row, its role→permission bundle, and its
 * per-user grant/deny overrides — the three inputs the {@see PermissionResolver}
 * composes as `role ∪ grant − deny`.
 *
 * Every read runs on the privileged (owner) connection, exactly like {@see UserRepository} and
 * {@see MembershipDirectory}: resolution happens BEFORE any tenant context
 * exists (on `/auth/me`, `/auth/switch-company`, and inside the permission gate), so reading these
 * RLS-scoped tables through the tenant connection would return zero rows with no company GUC set.
 * Safety comes from self-scoping every query by the trusted authenticated `user_id` + the requested
 * `company_id`/`company_user_id`, never from raw client input.
 */
final class MembershipRepository
{
    /**
     * The caller's live (active, non-deleted) membership in one company, joined to its role key — or
     * null if there is none (which resolves to the empty permission set: deny by default).
     */
    public function activeFor(int $userId, int $companyId): ?MembershipRecord
    {
        $row = $this->connection()->table('company_users as cu')
            ->join('roles as r', 'r.id', '=', 'cu.role_id')
            ->where('cu.user_id', $userId)
            ->where('cu.company_id', $companyId)
            ->where('cu.status', 'active')
            ->whereNull('cu.deleted_at')
            ->first([
                'cu.id',
                'cu.company_id',
                'cu.user_id',
                'cu.role_id',
                'r.key as role_key',
                'cu.perms_ver',
                'cu.status',
            ]);

        if ($row === null) {
            return null;
        }

        return new MembershipRecord(
            id: $this->asInt($row->id),
            companyId: $this->asInt($row->company_id),
            userId: $this->asInt($row->user_id),
            roleId: $this->asInt($row->role_id),
            roleKey: $this->asString($row->role_key),
            permsVer: $this->asInt($row->perms_ver),
            status: $this->asString($row->status),
        );
    }

    /**
     * The permission keys a role confers (its `role_permissions` bundle).
     *
     * @return list<string>
     */
    public function rolePermissions(int $roleId): array
    {
        $rows = $this->connection()->table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->pluck('p.key');

        return $this->toStringList($rows->all());
    }

    /**
     * The membership's active custom GRANTS — additive overrides, excluding any whose temporary
     * `expires_at` has passed (docs/foundation/PERMISSION_SYSTEM.md "# Temporary Permissions").
     *
     * @return list<string>
     */
    public function grants(int $companyUserId): array
    {
        return $this->overrides($companyUserId, 'grant', excludeExpired: true);
    }

    /**
     * The membership's custom DENIES — subtractive overrides. Denies are applied regardless of an
     * `expires_at`, matching the resolver contract in AUTH_SERVICE.md (`customDenies($id)` carries no
     * not-expired flag): a deny fails closed until it is explicitly removed.
     *
     * @return list<string>
     */
    public function denies(int $companyUserId): array
    {
        return $this->overrides($companyUserId, 'deny', excludeExpired: false);
    }

    /**
     * @return list<string>
     */
    private function overrides(int $companyUserId, string $effect, bool $excludeExpired): array
    {
        $query = $this->connection()->table('company_user_permissions as cup')
            ->join('permissions as p', 'p.id', '=', 'cup.permission_id')
            ->where('cup.company_user_id', $companyUserId)
            ->where('cup.effect', $effect);

        if ($excludeExpired) {
            $query->where(function (Builder $q): void {
                $q->whereNull('cup.expires_at')->orWhere('cup.expires_at', '>', Carbon::now());
            });
        }

        return $this->toStringList($query->pluck('p.key')->all());
    }

    /**
     * The internal ids of every company the user has a live membership in (used to derive an implicit
     * active company when the user belongs to exactly one).
     *
     * @return list<int>
     */
    public function activeCompanyIdsFor(int $userId): array
    {
        $rows = $this->connection()->table('company_users as cu')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->where('cu.user_id', $userId)
            ->where('cu.status', 'active')
            ->whereNull('cu.deleted_at')
            ->whereNull('c.deleted_at')
            ->pluck('cu.company_id');

        $ids = [];
        foreach ($rows->all() as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * Bump the membership's `perms_ver` counter so the resolved-permission cache key changes and the
     * change is effective on the next request (docs/backend/AUTH_SERVICE.md "# Multi-Tenancy
     * Enforcement": "takes effect on the next request … without a stale-cache window"). Returns the new
     * version. Runs on the owner connection so it is not gated by RLS on the authorization path.
     */
    public function bumpPermsVer(int $companyUserId): int
    {
        $row = $this->connection()->selectOne(
            'UPDATE company_users SET perms_ver = perms_ver + 1, updated_at = now() WHERE id = ? RETURNING perms_ver',
            [$companyUserId],
        );

        return is_object($row) && isset($row->perms_ver) ? $this->asInt($row->perms_ver) : 0;
    }

    /** The privileged (owner) connection — the authorization tables are read past RLS, self-scoped. */
    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function toStringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
