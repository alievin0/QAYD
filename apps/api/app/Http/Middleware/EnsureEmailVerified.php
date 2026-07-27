<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Identity\EmailNotVerifiedException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The S1-07 email-verification gate (docs/backend/AUTH_SERVICE.md: "an unverified user cannot create a
 * company"). Placed AFTER the auth guard on gated routes (e.g. company creation), it refuses an
 * email-unverified caller with `403 EMAIL_NOT_VERIFIED`. Deny-by-default: anything that is not a
 * verified {@see User} is rejected.
 */
final class EnsureEmailVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        return $next($request);
    }
}
