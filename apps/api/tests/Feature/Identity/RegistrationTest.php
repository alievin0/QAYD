<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Identity\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\TenantHarness;

/**
 * S1-07 — registration + email verification. Needs real PostgreSQL (citext email, RLS), so it boots the
 * two-connection harness like the other identity feature tests.
 */
uses()->group('identity', 'auth');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('registers a user (argon2id) and sends the verification email', function (): void {
    Notification::fake();

    $email = 'newuser_'.uniqid().'@example.test';

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Fahad Al-Kandari',
        'email' => $email,
        'password' => 'sup3r-secret-pw',
        'locale' => 'ar',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', $email)
        ->assertJsonPath('data.user.email_verified', false);

    $user = User::query()->where('email', $email)->firstOrFail();

    // Password is stored in password_hash as argon2id, never in cleartext, never as `password`.
    expect($user->password_hash)->toStartWith('$argon2id$');
    expect($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('marks the email verified when the signed link is followed', function (): void {
    Notification::fake();

    $email = 'verifyme_'.uniqid().'@example.test';

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Verify Me',
        'email' => $email,
        'password' => 'sup3r-secret-pw',
    ])->assertCreated();

    $user = User::query()->where('email', $email)->firstOrFail();

    // Capture the real signed URL the registration built.
    $verifyUrl = null;
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use (&$verifyUrl): bool {
        $verifyUrl = $n->verificationUrl;

        return true;
    });

    expect($verifyUrl)->toBeString();

    $this->postJson((string) $verifyUrl)
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', true);

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
});

it('rejects a verification link with a tampered signature', function (): void {
    $user = User::factory()->unverified()->create();

    $good = URL::temporarySignedRoute('auth.email.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    // Corrupt the signature.
    $tampered = $good.'x';

    $this->postJson($tampered)->assertStatus(403);

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('blocks an email-unverified user from creating a company (403 EMAIL_NOT_VERIFIED)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', [])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'EMAIL_NOT_VERIFIED');
});

it('lets a verified user past the company-create guard', function (): void {
    $user = User::factory()->create(); // verified by default

    // The email-verification guard now fronts the REAL create-company endpoint (S1-10). A verified user
    // is no longer blocked by the guard, so an empty body falls through to input validation (422
    // VALIDATION_ERROR) — never the 403 EMAIL_NOT_VERIFIED an unverified caller gets. That the response
    // is a validation error, not the verification error, is exactly what "past the guard" means here.
    $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', [])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
});
