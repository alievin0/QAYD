<?php

use App\Http\Middleware\ResolveTenantCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
