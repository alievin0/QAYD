<?php

namespace App\Providers;

use App\Domain\Accounting\NoLedgerPostedActivityGuard;
use App\Domain\Accounting\PostedActivityGuard;
use App\Models\User;
use App\Services\Identity\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Chart-of-accounts posted-activity guard (S2-01). Until the ledger exists (S2-03 / S2-05) no
        // account can carry posted lines; the posting stories replace this binding with a ledger-backed
        // implementation without touching the Actions.
        $this->app->bind(PostedActivityGuard::class, NoLedgerPostedActivityGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The `jwt` bearer guard (S1-08): resolve the RS256 access token in the Authorization header to
        // its subject via TokenService, or null on ANY failure (fail closed). Paired with the `web`
        // session guard on protected auth routes as `auth:web,jwt`.
        Auth::viaRequest('jwt', function (Request $request): ?User {
            $token = $request->bearerToken();

            if ($token === null || $token === '') {
                return null;
            }

            return $this->app->make(TokenService::class)->userFromAccessToken($token);
        });
    }
}
