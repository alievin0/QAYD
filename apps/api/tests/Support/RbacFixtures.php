<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\RbacSeeder;

/**
 * Seeds the RBAC rows the S1-09 resolver/gate tests need — a permission, a role→permission link, and a
 * per-membership grant/deny — all on the OWNER connection (bypasses RLS), mirroring {@see AuthFixtures}.
 * Kept minimal and explicit so a test controls exactly which permission a role/membership carries,
 * independent of the full {@see RbacSeeder} catalogue.
 */
final class RbacFixtures
{
    /**
     * Upsert a permission by its unique key and return its id. Safe to call for the same key twice.
     */
    public static function permission(string $key, string $area, bool $sensitive = false): int
    {
        $owner = TenantHarness::owner();

        $existing = $owner->table('permissions')->where('key', $key)->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) $owner->selectOne(
            'INSERT INTO permissions (key, area, is_sensitive) VALUES (?, ?, ?) RETURNING id',
            [$key, $area, $sensitive],
        )->id;
    }

    /** Attach a permission to a role (idempotent on the (role_id, permission_id) primary key). */
    public static function attachToRole(int $roleId, int $permissionId): void
    {
        TenantHarness::owner()->table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    /** Add an additive per-membership grant (optionally temporary via $expiresAt). */
    public static function grant(int $companyUserId, int $permissionId, ?string $expiresAt = null): void
    {
        self::override($companyUserId, $permissionId, 'grant', $expiresAt);
    }

    /** Add a subtractive per-membership deny. */
    public static function deny(int $companyUserId, int $permissionId): void
    {
        self::override($companyUserId, $permissionId, 'deny', null);
    }

    private static function override(int $companyUserId, int $permissionId, string $effect, ?string $expiresAt): void
    {
        TenantHarness::owner()->table('company_user_permissions')->insert([
            'company_user_id' => $companyUserId,
            'permission_id' => $permissionId,
            'effect' => $effect,
            'expires_at' => $expiresAt,
        ]);
    }
}
