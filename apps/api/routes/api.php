<?php

use App\Http\Controllers\MembershipController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok', 'service' => 'qayd-api']));

// Tenant-scoped routes: the `tenant` middleware resolves + verifies the active company and pins the
// RLS session context. A real auth guard is wired in S1-08; until then the middleware enforces its
// own 401 when no authenticated user is present.
Route::middleware('tenant')->group(function (): void {
    Route::get('/v1/memberships/{id}', [MembershipController::class, 'show'])->whereNumber('id');
});
