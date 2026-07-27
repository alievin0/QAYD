<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Domain\Identity\MembershipRecord;
use App\Domain\Identity\ResolvedPermissions;
use App\Repositories\Identity\MembershipRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves and caches the effective permission set for a (user, active-company) pair
 * (docs/backend/AUTH_SERVICE.md "# Key Classes — PermissionResolver"). This is the hot path behind
 * every authorization check in every module, so its shape is deliberate:
 *
 *  - **Deny by default.** A missing membership resolves to {@see ResolvedPermissions::empty()} — zero
 *    permissions — never a partial or best-effort grant.
 *  - **`role ∪ grant − deny`.** The set is the role's `role_permissions` bundle, plus the membership's
 *    additive custom grants, minus its subtractive custom denies (a deny always wins).
 *  - **`perms_ver`-keyed cache.** The resolved set is cached under the `company_user_id` + the
 *    membership's `perms_ver`. Any role/grant/deny change bumps `perms_ver`
 *    ({@see MembershipRepository::bumpPermsVer()}), which changes the cache key, so the change is
 *    effective on the very next request with no stale-cache window and no explicit flush. A change in
 *    one company bumps only that membership's counter, so it can never alter another company's cached
 *    set — the cross-tenant isolation property.
 *
 * The cache store is Redis in every deployed environment (config/identity.php); the resolver goes
 * through the cache abstraction so the test suite can pin it to the `array` store.
 */
final class PermissionResolver
{
    public function __construct(private readonly MembershipRepository $memberships) {}

    /**
     * The set a request is authorized against — `role permissions ∪ custom grants − custom denies`,
     * or the empty set when the user has no live membership in the company.
     */
    public function resolve(int $userId, int $companyId): ResolvedPermissions
    {
        $membership = $this->memberships->activeFor($userId, $companyId);

        if ($membership === null) {
            return ResolvedPermissions::empty();
        }

        $key = $this->cacheKey($membership->id, $membership->permsVer);

        /** @var array{perms: list<string>, perms_ver: int} $payload */
        $payload = $this->cache()->remember($key, $this->ttl(), fn (): array => [
            'perms' => $this->compose($membership),
            'perms_ver' => $membership->permsVer,
        ]);

        return ResolvedPermissions::fromList($payload['perms'], $payload['perms_ver']);
    }

    /**
     * Invalidate the cached set for a membership and, in the same step, make the change effective on the
     * next request by bumping `perms_ver`. Returns the new version. Callers that mutate a role or a
     * grant/deny for a membership use this so the resolver never serves a stale set.
     */
    public function invalidate(int $companyUserId): int
    {
        return $this->memberships->bumpPermsVer($companyUserId);
    }

    /**
     * Compose `role ∪ grant − deny` into a sorted, unique key list.
     *
     * @return list<string>
     */
    private function compose(MembershipRecord $membership): array
    {
        $rolePerms = $this->memberships->rolePermissions($membership->roleId);
        $grants = $this->memberships->grants($membership->id);
        $denies = $this->memberships->denies($membership->id);

        $granted = array_merge($rolePerms, $grants);
        $effective = array_diff($granted, $denies);

        $effective = array_values(array_unique($effective));
        sort($effective);

        return $effective;
    }

    private function cacheKey(int $companyUserId, int $permsVer): string
    {
        return "perms:cu:{$companyUserId}:v{$permsVer}";
    }

    private function cache(): CacheRepository
    {
        $store = config('identity.perms_cache.store');

        return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
    }

    private function ttl(): int
    {
        $ttl = config('identity.perms_cache.ttl');

        return is_int($ttl) && $ttl > 0 ? $ttl : 1800;
    }
}
