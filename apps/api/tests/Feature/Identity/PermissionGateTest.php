<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * S1-09 — route authorization via the `permission:` gate (docs/backend/AUTH_SERVICE.md
 * "# Endpoints Backed": "a route `can:` gate", docs/foundation/PERMISSION_SYSTEM.md "# Default Rule").
 * Exercised on the local/testing-only guarded probe route `GET /api/v1/_probe/guarded`, which requires
 * `reports.read` in the active company. Deny-by-default: the gate allows only when the resolved set
 * contains the permission, else 403 INSUFFICIENT_PERMISSION.
 */
uses()->group('identity', 'auth', 'rbac');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('allows a guarded route when the resolved set contains the permission', function (): void {
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Allowed Co', 'manager');

    $perm = RbacFixtures::permission('reports.read', 'reports');
    RbacFixtures::attachToRole($m['role_id'], $perm);

    $this->actingAs($user)
        ->getJson('/api/v1/_probe/guarded', ['X-Company-Id' => $m['company_uuid']])
        ->assertOk()
        ->assertJsonPath('data.ok', true);
});

it('denies a guarded route with 403 INSUFFICIENT_PERMISSION when the permission is missing', function (): void {
    $user = User::factory()->create();
    // A member whose role carries no reports.read (no role_permissions rows at all).
    $m = AuthFixtures::membership($user->id, 'Denied Co', 'clerk');

    $this->actingAs($user)
        ->getJson('/api/v1/_probe/guarded', ['X-Company-Id' => $m['company_uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('surfaces the required permission in the error meta', function (): void {
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Meta Co', 'clerk');

    $this->actingAs($user)
        ->getJson('/api/v1/_probe/guarded', ['X-Company-Id' => $m['company_uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.meta.required_permission', 'reports.read');
});
