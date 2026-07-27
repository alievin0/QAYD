<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Identity\TokenService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthFixtures;
use Tests\Support\TenantHarness;

/**
 * S1-08 — login, throttle & sessions. Real PostgreSQL (citext/inet/RLS + login_attempts/refresh_tokens).
 */
uses()->group('identity', 'auth');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('issues a full credential — Sanctum session cookie + RS256 JWT + rotating refresh', function (): void {
    $user = User::factory()->create();
    AuthFixtures::membership($user->id);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'authenticated')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in', 'refresh_expires_in', 'user', 'companies']]);

    // 1) The stateful Sanctum session cookie was set.
    $cookieNames = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    expect($cookieNames)->toContain(config('session.cookie'));

    // 2) The RS256 access token verifies back to this exact user.
    $accessToken = $response->json('data.access_token');
    $resolved = app(TokenService::class)->userFromAccessToken((string) $accessToken);
    expect($resolved?->id)->toBe($user->id);

    // 3) A rotating refresh token was persisted (hashed at rest).
    $refresh = (string) $response->json('data.refresh_token');
    expect($refresh)->toStartWith('rft_');
    $stored = TenantHarness::owner()->table('refresh_tokens')
        ->where('user_id', $user->id)
        ->where('token_hash', hash('sha256', $refresh))
        ->exists();
    expect($stored)->toBeTrue();
});

it('rejects a wrong password with 401 INVALID_CREDENTIALS', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])->assertStatus(401)->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('locks the account on the 6th attempt in a minute with a 429 + Retry-After', function (): void {
    $user = User::factory()->create();

    // Five failed attempts are refused as invalid credentials (401)…
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    // …the sixth trips the sliding-window lockout.
    $sixth = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong',
    ]);

    $sixth->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'ACCOUNT_TEMPORARILY_LOCKED');

    expect($sixth->headers->get('Retry-After'))->not->toBeNull();
    expect((int) $sixth->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('does a dummy hash on a missing user so timing never leaks existence', function (): void {
    Hash::spy();

    // No such account — the constant-time path must STILL run a hash comparison (no early return).
    $this->postJson('/api/v1/auth/login', [
        'email' => 'ghost_'.uniqid().'@example.test',
        'password' => 'whatever',
    ])->assertStatus(401)->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');

    Hash::shouldHaveReceived('check')->once();
});

it('writes an audit_logs row on a successful login', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $audited = TenantHarness::owner()->table('audit_logs')
        ->where('actor_user_id', $user->id)
        ->where('action', 'user.logged_in')
        ->where('category', 'auth')
        ->exists();

    expect($audited)->toBeTrue();
});

it('logs out: bearer logout revokes the presented refresh token', function (): void {
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $access = (string) $login->json('data.access_token');
    $refresh = (string) $login->json('data.refresh_token');

    $this->withToken($access)
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])
        ->assertOk()
        ->assertJsonPath('success', true);

    // The refresh token is now revoked and can no longer be rotated.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertStatus(401);

    $revoked = TenantHarness::owner()->table('refresh_tokens')
        ->where('token_hash', hash('sha256', $refresh))
        ->whereNotNull('revoked_at')
        ->exists();
    expect($revoked)->toBeTrue();
});
