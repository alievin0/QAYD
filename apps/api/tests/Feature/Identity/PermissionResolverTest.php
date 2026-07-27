<?php

declare(strict_types=1);

use App\Models\User;
use App\Repositories\Identity\MembershipRepository;
use App\Services\Identity\PermissionResolver;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * S1-09 — the PermissionResolver invariants (docs/backend/AUTH_SERVICE.md "# Key Classes",
 * "# Multi-Tenancy Enforcement"): `role ∪ grant − deny`, the empty set for a missing membership,
 * `perms_ver` cache invalidation, and cross-company isolation. Real PostgreSQL via {@see TenantHarness};
 * the perms cache is the `array` store (phpunit.xml) so caching is observable in-process.
 */
uses()->group('identity', 'auth', 'rbac');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('resolves the empty set for a missing membership (deny by default)', function (): void {
    $resolved = app(PermissionResolver::class)->resolve(999_999, 888_888);

    expect($resolved->all())->toBe([]);
    expect($resolved->isEmpty())->toBeTrue();
    expect($resolved->permsVer())->toBe(0);
});

it('composes role permissions ∪ grants − denies', function (): void {
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Compose Co', 'accountant');

    $read = RbacFixtures::permission('accounting.read', 'accounting');
    $create = RbacFixtures::permission('accounting.create', 'accounting');
    $export = RbacFixtures::permission('reports.export', 'reports');
    RbacFixtures::attachToRole($m['role_id'], $read);
    RbacFixtures::attachToRole($m['role_id'], $create);

    // Add a grant (reports.export) and a deny that removes a role permission (accounting.create).
    RbacFixtures::grant($m['company_user_id'], $export);
    RbacFixtures::deny($m['company_user_id'], $create);

    $resolved = app(PermissionResolver::class)->resolve($user->id, $m['company_id']);

    expect($resolved->all())->toBe(['accounting.read', 'reports.export']);
    expect($resolved->has('accounting.create'))->toBeFalse(); // deny wins over the role grant
});

it('excludes an expired temporary grant', function (): void {
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Expiry Co', 'accountant');

    $active = RbacFixtures::permission('accounting.read', 'accounting');
    $expired = RbacFixtures::permission('reports.export', 'reports');
    RbacFixtures::attachToRole($m['role_id'], $active);
    RbacFixtures::grant($m['company_user_id'], $expired, expiresAt: now()->subDay()->toDateTimeString());

    $resolved = app(PermissionResolver::class)->resolve($user->id, $m['company_id']);

    expect($resolved->all())->toBe(['accounting.read']);
});

it('bumps perms_ver so a permission change is effective on the next request', function (): void {
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Bump Co', 'accountant');

    $read = RbacFixtures::permission('accounting.read', 'accounting');
    RbacFixtures::attachToRole($m['role_id'], $read);

    $resolver = app(PermissionResolver::class);

    // First resolve caches the set under perms_ver = 1.
    expect($resolver->resolve($user->id, $m['company_id'])->all())->toBe(['accounting.read']);

    // Add a grant WITHOUT bumping perms_ver: the cache still serves the stale set (proves caching).
    $export = RbacFixtures::permission('reports.export', 'reports');
    RbacFixtures::grant($m['company_user_id'], $export);
    expect($resolver->resolve($user->id, $m['company_id'])->all())->toBe(['accounting.read']);

    // Bumping perms_ver changes the cache key, so the change is effective on the next resolve.
    $newVer = app(MembershipRepository::class)->bumpPermsVer($m['company_user_id']);
    expect($newVer)->toBe(2);

    $after = $resolver->resolve($user->id, $m['company_id']);
    expect($after->all())->toBe(['accounting.read', 'reports.export']);
    expect($after->permsVer())->toBe(2);
});

it('never lets a permission change in Company A alter resolved perms in Company B', function (): void {
    $user = User::factory()->create();
    $a = AuthFixtures::membership($user->id, 'Company A', 'accountant');
    $b = AuthFixtures::membership($user->id, 'Company B', 'accountant');

    $aRead = RbacFixtures::permission('accounting.read', 'accounting');
    $bRead = RbacFixtures::permission('sales.read', 'sales');
    RbacFixtures::attachToRole($a['role_id'], $aRead);
    RbacFixtures::attachToRole($b['role_id'], $bRead);

    $resolver = app(PermissionResolver::class);

    // Baseline: each company sees only its own role's permission.
    expect($resolver->resolve($user->id, $a['company_id'])->all())->toBe(['accounting.read']);
    expect($resolver->resolve($user->id, $b['company_id'])->all())->toBe(['sales.read']);

    // Change a permission in Company A only: grant + bump A's perms_ver.
    $extra = RbacFixtures::permission('accounting.export', 'accounting');
    RbacFixtures::grant($a['company_user_id'], $extra);
    app(MembershipRepository::class)->bumpPermsVer($a['company_user_id']);

    // A reflects the change…
    $aAfter = $resolver->resolve($user->id, $a['company_id']);
    expect($aAfter->all())->toBe(['accounting.export', 'accounting.read']);

    // …B is completely unaffected: no A permission ever leaks across the tenant boundary.
    $bAfter = $resolver->resolve($user->id, $b['company_id']);
    expect($bAfter->all())->toBe(['sales.read']);
    expect($bAfter->has('accounting.export'))->toBeFalse();
    expect($bAfter->has('accounting.read'))->toBeFalse();
});
