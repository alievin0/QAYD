<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\AuthFixtures;
use Tests\Support\TenantHarness;

/**
 * The `Idempotency-Key` middleware (S2-11 prerequisite; the rest of the behaviour is S2-13).
 *
 * The probe route returns a fresh random token every time its handler runs, so "the same token came
 * back" is direct evidence the handler was never re-entered — the property that stops a retried post
 * from becoming a second posted entry. A fixed payload could not tell a replay from a coincidence.
 */
uses()->group('api');

beforeEach(function (): void {
    TenantHarness::boot();
});

/**
 * @return array{user: User, uuid: string, company_id: int}
 */
function idemMember(): array
{
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'Idem '.uniqid(), 'idem_role');

    return ['user' => $user, 'uuid' => $m['company_uuid'], 'company_id' => (int) $m['company_id']];
}

it('runs the handler once and replays the stored response for a repeated key', function (): void {
    $m = idemMember();
    $headers = ['X-Company-Id' => $m['uuid'], 'Idempotency-Key' => 'key-'.uniqid()];

    $first = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)
        ->assertStatus(201);

    $second = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)
        ->assertStatus(201);

    // Same token: the handler did not run the second time.
    expect($second->json('data.token'))->toBe($first->json('data.token'));
    expect($second->headers->get('Idempotent-Replay'))->toBe('true');
    expect($first->headers->get('Idempotent-Replay'))->toBeNull();
});

it('refuses a key reused for a different body (409 IDEMPOTENCY_KEY_CONFLICT)', function (): void {
    $m = idemMember();
    $headers = ['X-Company-Id' => $m['uuid'], 'Idempotency-Key' => 'key-'.uniqid()];

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)
        ->assertStatus(201);

    // Replaying the first response here would tell the caller an operation succeeded that never ran.
    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '999.0000'], $headers)
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_CONFLICT');
});

it('runs every time when no key is sent, so idempotency stays opt-in', function (): void {
    $m = idemMember();
    $headers = ['X-Company-Id' => $m['uuid']];

    $first = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)
        ->assertStatus(201);

    $second = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)
        ->assertStatus(201);

    expect($second->json('data.token'))->not->toBe($first->json('data.token'));
});

it('scopes a key to its company, so two tenants can mint the same one', function (): void {
    $a = idemMember();
    $b = idemMember();
    $key = 'shared-'.uniqid();

    $first = $this->actingAs($a['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], [
            'X-Company-Id' => $a['uuid'], 'Idempotency-Key' => $key,
        ])->assertStatus(201);

    // Company B's identical key is a different key: its request must actually run.
    $second = $this->actingAs($b['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], [
            'X-Company-Id' => $b['uuid'], 'Idempotency-Key' => $key,
        ])->assertStatus(201);

    expect($second->json('data.token'))->not->toBe($first->json('data.token'));
    expect($second->headers->get('Idempotent-Replay'))->toBeNull();
});

it('records exactly one key row per company for a repeated request', function (): void {
    $m = idemMember();
    $headers = ['X-Company-Id' => $m['uuid'], 'Idempotency-Key' => 'key-'.uniqid()];

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)->assertStatus(201);
    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/_probe/idempotent', ['amount' => '10.0000'], $headers)->assertStatus(201);

    $count = TenantHarness::owner()->scalar(
        'SELECT COUNT(*) FROM idempotency_keys WHERE company_id = ?',
        [$m['company_id']],
    );

    expect((int) $count)->toBe(1);
});
