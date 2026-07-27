<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Identity\TokenService;
use Tests\Support\AuthFixtures;
use Tests\Support\TenantHarness;

/**
 * S1-08 — GET /auth/me returns identity, memberships, the active company and perms_ver, resolvable via
 * EITHER the Sanctum session cookie or a bearer JWT. The permission set is deliberately empty until the
 * S1-09 PermissionResolver lands.
 */
uses()->group('identity', 'auth');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('returns identity, memberships, active company and perms_ver via the session cookie', function (): void {
    $user = User::factory()->create();
    $membership = AuthFixtures::membership($user->id, 'Kandari Trading', 'owner');

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.uuid', $user->uuid)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.companies.0.uuid', $membership['company_uuid'])
        ->assertJsonPath('data.companies.0.role', 'owner')
        ->assertJsonPath('data.active_company.uuid', $membership['company_uuid'])
        ->assertJsonPath('data.active_company.role', 'owner')
        ->assertJsonPath('data.perms_ver', 1)
        ->assertJsonPath('data.permissions', []); // TODO(S1-09): resolved permissions
});

it('returns the same identity payload via a bearer JWT', function (): void {
    $user = User::factory()->create();
    $membership = AuthFixtures::membership($user->id);

    $token = app(TokenService::class)->issueAccessToken($user)['token'];

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.uuid', $user->uuid)
        ->assertJsonPath('data.active_company.uuid', $membership['company_uuid'])
        ->assertJsonPath('data.permissions', []);
});

it('rejects an unauthenticated /auth/me with 401', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('rejects a garbage bearer token with 401', function (): void {
    $this->withToken('not-a-real-jwt')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});
