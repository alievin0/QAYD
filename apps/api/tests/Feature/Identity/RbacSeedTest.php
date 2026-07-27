<?php

declare(strict_types=1);

use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S1-09 — the RBAC seed (docs/foundation/PERMISSION_SYSTEM.md "# Roles", docs/backend/AUTH_SERVICE.md
 * "# Database Tables Owned"): the fixed permission catalogue and the system default roles
 * (company_id IS NULL, is_system = true) with their role→permission mappings — and, crucially, that
 * re-running is idempotent (no duplicate NULL-company system roles, no duplicated permissions/links).
 */
uses()->group('identity', 'auth', 'rbac');

beforeEach(function (): void {
    TenantHarness::boot();
});

function seedRbac(): void
{
    Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
}

it('seeds the permission catalogue with correct sensitivity flags', function (): void {
    seedRbac();

    $owner = TenantHarness::owner();
    $permCount = $owner->table('permissions')->count();
    expect($permCount)->toBeGreaterThan(40);

    // The catalogue includes the areas from PERMISSION_SYSTEM.md.
    foreach (['accounting.read', 'bank.transfer', 'payroll.approve', 'reports.export', 'ai.chat', 'settings.roles.approve'] as $key) {
        expect($owner->table('permissions')->where('key', $key)->exists())->toBeTrue("missing permission {$key}");
    }

    // Sensitive = ends in .approve/.release/.submit/.transfer.
    expect((bool) $owner->table('permissions')->where('key', 'bank.transfer')->value('is_sensitive'))->toBeTrue();
    expect((bool) $owner->table('permissions')->where('key', 'tax.submit')->value('is_sensitive'))->toBeTrue();
    expect((bool) $owner->table('permissions')->where('key', 'accounting.read')->value('is_sensitive'))->toBeFalse();
});

it('seeds system default roles as company_id NULL, is_system true', function (): void {
    seedRbac();

    $owner = TenantHarness::owner();

    foreach (['owner', 'cfo', 'accountant', 'read_only', 'external_auditor'] as $key) {
        $role = $owner->table('roles')->whereNull('company_id')->where('key', $key)->first();
        expect($role)->not->toBeNull("missing system role {$key}");
        expect((bool) $role->is_system)->toBeTrue("role {$key} must be a system role");
    }

    // Every system role carries company_id IS NULL.
    $systemRoleCount = $owner->table('roles')->whereNull('company_id')->count();
    expect($systemRoleCount)->toBeGreaterThanOrEqual(17);
});

it('grants the Owner role the entire permission catalogue', function (): void {
    seedRbac();

    $owner = TenantHarness::owner();
    $ownerRoleId = (int) $owner->table('roles')->whereNull('company_id')->where('key', 'owner')->value('id');

    $totalPerms = $owner->table('permissions')->count();
    $ownerPerms = $owner->table('role_permissions')->where('role_id', $ownerRoleId)->count();

    expect($ownerPerms)->toBe($totalPerms);
});

it('grants Read Only only the .read permissions', function (): void {
    seedRbac();

    $owner = TenantHarness::owner();
    $roleId = (int) $owner->table('roles')->whereNull('company_id')->where('key', 'read_only')->value('id');

    $keys = $owner->table('role_permissions as rp')
        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->where('rp.role_id', $roleId)
        ->pluck('p.key')
        ->all();

    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect(str_ends_with((string) $key, '.read'))->toBeTrue("read_only must not hold {$key}");
    }
});

it('is idempotent — re-running never duplicates roles, permissions, or mappings', function (): void {
    seedRbac();

    $owner = TenantHarness::owner();
    $permsAfterFirst = $owner->table('permissions')->count();
    $rolesAfterFirst = $owner->table('roles')->whereNull('company_id')->count();
    $linksAfterFirst = $owner->table('role_permissions')->count();

    // Run it again.
    seedRbac();

    expect($owner->table('permissions')->count())->toBe($permsAfterFirst);
    expect($owner->table('roles')->whereNull('company_id')->count())->toBe($rolesAfterFirst);
    expect($owner->table('role_permissions')->count())->toBe($linksAfterFirst);

    // Specifically: exactly one NULL-company 'owner' role, not a duplicate on re-seed.
    expect($owner->table('roles')->whereNull('company_id')->where('key', 'owner')->count())->toBe(1);
});
