<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\AccountTypeController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Identity\CreateCompanyController;
use App\Http\Controllers\Identity\LoginController;
use App\Http\Controllers\Identity\LogoutController;
use App\Http\Controllers\Identity\MeController;
use App\Http\Controllers\Identity\RefreshController;
use App\Http\Controllers\Identity\RegisterController;
use App\Http\Controllers\Identity\SwitchCompanyController;
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

    // Authenticated via EITHER the Sanctum session cookie (`web`) OR a bearer JWT (`jwt`). `me` and
    // `switch-company` deliberately need NO active company (no X-Company-Id): switch-company is how the
    // active company is chosen, and me reports the currently-active one (S1-09).
    Route::middleware([...$stateful, 'auth:web,jwt'])->group(function (): void {
        Route::get('me', MeController::class);
        Route::post('logout', LogoutController::class);
        Route::post('switch-company', SwitchCompanyController::class);
    });
});

// S1-07 email-verification gate: an unverified user cannot create a company (403 EMAIL_NOT_VERIFIED).
// The real CreateCompanyAction lands in S1-10; this route hosts the guard now.
Route::post('/v1/companies', CreateCompanyController::class)
    ->middleware([...$stateful, 'auth:web,jwt', 'verified.email']);

// Tenant-scoped example route (S1-05): authenticated via EITHER the Sanctum session cookie (`web`) OR a
// bearer JWT (`jwt`) FIRST — so `$request->user()` is populated for a real HTTP client — then the
// `tenant` middleware resolves + verifies the active company and pins the RLS session context. Without
// the auth guard the tenant middleware sees a null user and 401s every real request.
Route::middleware([...$stateful, 'auth:web,jwt', 'tenant'])->group(function (): void {
    Route::get('/v1/memberships/{id}', [MembershipController::class, 'show'])->whereNumber('id');
});

// S2-02 — Chart of Accounts API. Tenant-scoped: auth → tenant (pins the active company + RLS) → the
// `permission:` gate. Reads require accounting.journal.read; writes accounting.coa.manage. Route-model
// binding of {account} is RLS-scoped, so a cross-tenant id resolves to 404 (never another tenant's row).
Route::prefix('v1/accounting')
    ->middleware([...$stateful, 'auth:web,jwt', 'tenant'])
    ->group(function (): void {
        Route::middleware('permission:accounting.journal.read')->group(function (): void {
            // The global account-type catalogue. Read-only at the database grant level (S2-01 revoked
            // writes on it from the runtime role), and needed before an account can be created at all —
            // `account_type_id` is required and its ids are database-generated.
            Route::get('account-types', [AccountTypeController::class, 'index']);

            // The accounting calendar, read-only (S2-12 prerequisite). A trial balance is computed for
            // a period and period ids are database-generated, so a client that must send
            // `fiscal_period_id` has to be able to enumerate them. Close/lock/reopen stay with their
            // own Actions and permissions — there is deliberately no write counterpart here.
            Route::get('fiscal-periods', [FiscalPeriodController::class, 'index']);

            Route::get('accounts', [AccountController::class, 'index']);
            Route::get('accounts/tree', [AccountController::class, 'tree']);
            Route::get('accounts/{account}', [AccountController::class, 'show'])->whereNumber('account');

            // S2-08 — general ledger reads. A read of posted history, so it sits under the same
            // read permission as the chart itself; the running balance is derived at query time from
            // `ledger_entries` and nothing on this path writes.
            Route::get('ledger/accounts/{account}/activity', [LedgerController::class, 'activity'])
                ->whereNumber('account');
        });

        // S2-09 — trial balance. Reading a trial balance, generating a durable snapshot, and signing
        // one are three different levels of authority over the same figures, so they take three
        // permissions rather than sharing the generic accounting read.
        // Journal entries (S2-11 prerequisite) — the HTTP surface over the S2-04/05/06 Actions. The
        // permission split follows who does the work: read to look, create to draft, update to edit /
        // submit / discard, approve for the two actions that move the ledger.
        Route::middleware('permission:accounting.journal.read')->group(function (): void {
            Route::get('journal-entries', [JournalEntryController::class, 'index']);
            Route::get('journal-entries/{entry}', [JournalEntryController::class, 'show'])->whereNumber('entry');
        });

        Route::middleware('permission:accounting.create')->group(function (): void {
            Route::post('journal-entries', [JournalEntryController::class, 'store']);
        });

        Route::middleware('permission:accounting.update')->group(function (): void {
            Route::patch('journal-entries/{entry}', [JournalEntryController::class, 'update'])->whereNumber('entry');
            Route::post('journal-entries/{entry}/submit', [JournalEntryController::class, 'submit'])->whereNumber('entry');
            Route::post('journal-entries/{entry}/void', [JournalEntryController::class, 'void'])->whereNumber('entry');
        });

        Route::middleware('permission:accounting.approve')->group(function (): void {
            // `idempotent` is on THIS route and no other: posting is the only call here that moves the
            // ledger, so it is the only one where a retry must not repeat the work.
            Route::post('journal-entries/{entry}/post', [JournalEntryController::class, 'post'])
                ->middleware('idempotent')
                ->whereNumber('entry');

            Route::post('journal-entries/{entry}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('entry');
        });

        Route::middleware('permission:accounting.trial_balance.read')->group(function (): void {
            Route::get('reports/trial-balance', [TrialBalanceController::class, 'compute']);
            Route::get('reports/trial-balance/{snapshot}', [TrialBalanceController::class, 'show'])
                ->whereNumber('snapshot');
        });

        Route::middleware('permission:accounting.trial_balance.generate')->group(function (): void {
            Route::post('reports/trial-balance', [TrialBalanceController::class, 'generate']);
        });

        Route::middleware('permission:accounting.trial_balance.approve')->group(function (): void {
            Route::post('reports/trial-balance/{snapshot}/approve', [TrialBalanceController::class, 'approve'])
                ->whereNumber('snapshot');
        });

        Route::middleware('permission:accounting.coa.manage')->group(function (): void {
            Route::post('accounts', [AccountController::class, 'store']);
            Route::patch('accounts/{account}', [AccountController::class, 'update'])->whereNumber('account');
            Route::post('accounts/{account}/reclassify', [AccountController::class, 'reclassify'])->whereNumber('account');
            Route::post('accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->whereNumber('account');
        });
    });

// S1-09 route-authorization demonstration (local/testing only — never exposed in production). A
// tenant-scoped route guarded by `permission:reports.read`: the resolved permission set for the active
// company must include the key, else the gate returns 403 INSUFFICIENT_PERMISSION (deny-by-default).
if (app()->environment(['local', 'testing'])) {
    Route::middleware([...$stateful, 'auth:web,jwt', 'tenant', 'permission:reports.read'])
        ->get('/v1/_probe/guarded', fn () => ApiResponse::success(['ok' => true], 'probe.guarded'));

    // S2-11 prerequisite: exercises the `idempotent` middleware end to end. The handler returns a fresh
    // random value each time it runs, so a response that repeats a previous value proves the handler was
    // NOT re-entered — which is the property the middleware exists to provide, and one a fixed payload
    // could not distinguish from a coincidence.
    Route::middleware([...$stateful, 'auth:web,jwt', 'tenant', 'idempotent'])
        ->post('/v1/_probe/idempotent', fn () => ApiResponse::success(
            ['token' => bin2hex(random_bytes(8))],
            'probe.idempotent',
            [],
            201,
        ));
}
