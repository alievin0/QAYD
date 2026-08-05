<?php

namespace App\Providers;

use App\Domain\Accounting\FiscalCalendarResolver;
use App\Domain\Accounting\FiscalPeriodCalendarResolver;
use App\Domain\Accounting\JournalPoster;
use App\Domain\Accounting\NoLedgerPostedActivityGuard;
use App\Domain\Accounting\PostedActivityGuard;
use App\Models\User;
use App\Services\Accounting\PostingEngineJournalPoster;
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

        // Fiscal-calendar seam (S2-05 → S2-07). The posting engine depends only on the
        // FiscalCalendarResolver interface, never on fiscal_years/fiscal_periods directly — which is what
        // let S2-07 swap the year-level resolver for the period-level one below without changing a line
        // of JournalEntryPostingService. The period resolver also narrows the posting lock from the
        // fiscal YEAR row to the PERIOD row (TD-13), so postings into different months no longer
        // serialize against each other.
        $this->app->bind(FiscalCalendarResolver::class, FiscalPeriodCalendarResolver::class);

        // Cross-module posting seam (S3-03 prerequisite). Banking clears a transaction by posting a
        // balanced journal, and it does that through this interface — never through the Actions, the
        // JournalEntry model, or journal_lines. Accounting therefore keeps the ledger's only entrance,
        // and Banking cannot acquire a second one by reaching for something it was never given.
        $this->app->bind(JournalPoster::class, PostingEngineJournalPoster::class);
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
