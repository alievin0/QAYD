<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Services\Identity\PermissionResolver;

/**
 * The immutable, `perms_ver`-stamped permission set a request is authorized against
 * (docs/backend/AUTH_SERVICE.md "# Domain Model": `ResolvedPermissions` — "never an array in the
 * Application layer").
 *
 * It is the deterministic composition `role_permissions ∪ custom_grants − custom_denies` for one
 * (user, active-company) pair, produced by {@see PermissionResolver}. A caller
 * with no membership resolves to {@see empty()} — the zero-permission, deny-by-default state.
 *
 * The permission keys are stored as a sorted, de-duplicated list so equality and the `/auth/me`
 * projection are stable; `perms_ver` is the counter the cache is keyed by so a role/grant change is
 * effective on the next request.
 */
final readonly class ResolvedPermissions
{
    /**
     * @param  list<string>  $permissions  sorted, unique permission keys
     */
    private function __construct(
        private array $permissions,
        private int $permsVer,
    ) {}

    /**
     * The empty set: no membership ⇒ zero permissions. `perms_ver` is 0 because there is no membership
     * row to carry a version.
     */
    public static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * Build from an already-composed permission list. The list is normalised (unique + sorted) so two
     * equal sets are represented identically regardless of input order.
     *
     * @param  list<string>  $permissions
     */
    public static function fromList(array $permissions, int $permsVer): self
    {
        $unique = array_values(array_unique($permissions));
        sort($unique);

        return new self($unique, $permsVer);
    }

    /** Whether the resolved set grants the given permission key (strict, exact match — deny by default). */
    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * The permission keys, sorted and unique — the shape `/auth/me` serialises under `permissions`.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->permissions;
    }

    /** The membership `perms_ver` this set was resolved at (0 for the empty set). */
    public function permsVer(): int
    {
        return $this->permsVer;
    }

    public function isEmpty(): bool
    {
        return $this->permissions === [];
    }
}
