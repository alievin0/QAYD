<?php

use App\Http\Controllers\Identity\CreateCompanyController;
use App\Http\Controllers\Identity\LoginController;
use App\Http\Controllers\Identity\LogoutController;
use App\Http\Controllers\Identity\MeController;
use App\Http\Controllers\Identity\RefreshController;
use App\Http\Controllers\Identity\RegisterController;
use App\Http\Controllers\Identity\VerifyEmailController;
use App\Http\Controllers\MembershipController;
use App\Http\Responses\ApiResponse;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => ApiResponse::success(
    ['status' => 'ok', 'service' => 'qayd-api'],
    'Service is healthy.',
));

// The Sanctum stateful SPA session (S1-08): cookie + session middleware so the login route can
// establish and rotate (anti-fixation) the session cookie, and the `web` guard can read it back on
// protected routes. Bearer clients skip the cookie entirely and present an RS256 JWT.
$stateful = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
];

Route::prefix('v1/auth')->group(function () use ($stateful): void {
    // Public credential-issuing routes (S1-07, S1-08).
    Route::post('register', RegisterController::class);
    Route::post('email/verify', VerifyEmailController::class)
        ->name('auth.email.verify')
        ->middleware('signed');
    Route::post('login', LoginController::class)->middleware($stateful);
    Route::post('refresh', RefreshController::class);

    // Authenticated via EITHER the Sanctum session cookie (`web`) OR a bearer JWT (`jwt`).
    Route::middleware([...$stateful, 'auth:web,jwt'])->group(function (): void {
        Route::get('me', MeController::class);
        Route::post('logout', LogoutController::class);
    });
});

// S1-07 email-verification gate: an unverified user cannot create a company (403 EMAIL_NOT_VERIFIED).
// The real CreateCompanyAction lands in S1-10; this route hosts the guard now.
Route::post('/v1/companies', CreateCompanyController::class)
    ->middleware([...$stateful, 'auth:web,jwt', 'verified.email']);

// Tenant-scoped example route (S1-05): the `tenant` middleware resolves + verifies the active company
// and pins the RLS session context.
Route::middleware('tenant')->group(function (): void {
    Route::get('/v1/memberships/{id}', [MembershipController::class, 'show'])->whereNumber('id');
});
