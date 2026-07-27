<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Identity\TokenService;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * S1-09 — POST /auth/switch-company (docs/backend/AUTH_SERVICE.md "# Key Classes — SwitchCompanyAction",
 * "# Multi-Tenancy Enforcement"): re-scopes the active company after validating a live membership;
 * a non-membership is 404 (never 403) so tenants cannot be enumerated. Bearer flow is used because a
 * re-minted company-scoped token makes the switch deterministically observable on the next request.
 */
uses()->group('identity', 'auth', 'rbac');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('switches the active company and reflects it (with resolved perms) on the next /auth/me', function (): void {
    $user = User::factory()->create();
    AuthFixtures::membership($user->id, 'Company A', 'accountant');
    $b = AuthFixtures::membership($user->id, 'Company B', 'owner');

    // Company B's role carries a permission so the switch surfaces a non-empty resolved set.
    $read = RbacFixtures::permission('accounting.read', 'accounting');
    RbacFixtures::attachToRole($b['role_id'], $read);

    $token = app(TokenService::class)->issueAccessToken($user)['token'];

    $switch = $this->withToken($token)
        ->postJson('/api/v1/auth/switch-company', ['company_id' => $b['company_uuid']])
        ->assertOk()
        ->assertJsonPath('data.active_company.uuid', $b['company_uuid'])
        ->assertJsonPath('data.active_company.role', 'owner')
        ->assertJsonPath('data.permissions', ['accounting.read'])
        ->assertJsonPath('data.perms_ver', 1);

    // A fresh company-scoped bearer token is re-minted for the new active company.
    $newToken = (string) $switch->json('data.access_token');
    expect($newToken)->not->toBe('');

    // The next /auth/me, presenting the re-minted token, reflects Company B and its resolved perms.
    $this->withToken($newToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.active_company.uuid', $b['company_uuid'])
        ->assertJsonPath('data.active_company.role', 'owner')
        ->assertJsonPath('data.permissions', ['accounting.read'])
        ->assertJsonPath('data.perms_ver', 1);
});

it('returns 404 (never 403) when switching to a company the user is not a member of', function (): void {
    $user = User::factory()->create();
    AuthFixtures::membership($user->id, 'Home Co', 'owner');

    // A real, existing company the user has NO membership in — must be indistinguishable from a
    // non-existent one: 404, not 403, so the existence of the tenant never leaks.
    $stranger = TenantHarness::seedCompany('Stranger Co');

    $token = app(TokenService::class)->issueAccessToken($user)['token'];

    $this->withToken($token)
        ->postJson('/api/v1/auth/switch-company', ['company_id' => $stranger['company_uuid']])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'RESOURCE_NOT_FOUND');
});

it('returns 404 when switching to a non-existent company uuid', function (): void {
    $user = User::factory()->create();
    AuthFixtures::membership($user->id, 'Home Co', 'owner');

    $token = app(TokenService::class)->issueAccessToken($user)['token'];

    $this->withToken($token)
        ->postJson('/api/v1/auth/switch-company', ['company_id' => '00000000-0000-0000-0000-000000000000'])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'RESOURCE_NOT_FOUND');
});
