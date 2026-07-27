<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\TenantHarness;

/**
 * S1-08 — the rotating refresh token: rotation-on-use plus family-wide reuse detection
 * (docs/backend/AUTH_SERVICE.md "identity.refresh.reuse_detected").
 */
uses()->group('identity', 'auth');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('rotates a refresh token on use', function (): void {
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $refresh1 = (string) $login->json('data.refresh_token');

    $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh1])
        ->assertOk()
        ->assertJsonPath('data.status', 'authenticated');

    $refresh2 = (string) $rotated->json('data.refresh_token');

    expect($refresh2)->not->toBe($refresh1);
    expect((string) $rotated->json('data.access_token'))->not->toBe('');
});

it('detects reuse of a rotated token and revokes the whole family', function (): void {
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $refresh1 = (string) $login->json('data.refresh_token');

    // Rotate once — refresh1 is now spent, refresh2 is live.
    $refresh2 = (string) $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh1])
        ->assertOk()
        ->json('data.refresh_token');

    // Replaying the spent refresh1 is treated as theft → 401 …
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh1])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'REFRESH_TOKEN_REUSE_DETECTED');

    // … and it revokes the entire family, so even the live refresh2 no longer works.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh2])
        ->assertStatus(401);
});
