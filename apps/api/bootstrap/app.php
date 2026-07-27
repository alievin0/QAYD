<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ApiEnvelope;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ResolveTenantCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied per tenant-scoped route group (not globally) so unauthenticated routes such as
        // the health check stay reachable. Once auth (S1-08) lands it is appended to the tenant API.
        $middleware->alias([
            'tenant' => ResolveTenantCompany::class,
            // S1-07 email-verification gate (e.g. company creation): 403 EMAIL_NOT_VERIFIED.
            'verified.email' => EnsureEmailVerified::class,
            // S1-09 route authorization: `permission:<key>` authorizes against the resolved permission
            // set for the active company (deny-by-default → 403 INSUFFICIENT_PERMISSION). Applied AFTER
            // the `tenant` middleware, which pins the active company.
            'permission' => EnsurePermission::class,
        ]);

        // S1-16 cross-cutting foundations, front of the `api` group so they wrap every /api response:
        // AssignRequestId mints/propagates the correlation id first; ApiEnvelope guarantees the
        // standard envelope on the way out.
        $middleware->prependToGroup('api', [
            AssignRequestId::class,
            ApiEnvelope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // S1-16 global exception handler: every /api (or JSON) request renders as a coded error
        // envelope, never a stack trace. Non-API/HTML requests keep Laravel's default rendering.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiExceptionRenderer::render($e);
        });
    })->create();
