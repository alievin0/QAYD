<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Identity\InsufficientPermissionException;
use App\Models\User;
use App\Services\Identity\PermissionResolver;
use App\Support\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route authorization gate (S1-09, docs/backend/AUTH_SERVICE.md "# Endpoints Backed": "a route
 * `can:` gate"). Applied as `permission:<key>[,<key>…]` on a tenant-scoped route, it authorizes the
 * request against the permission set {@see PermissionResolver} resolves for the caller in the active
 * company, and refuses anything not explicitly granted.
 *
 * It is deny-by-default and fail-closed:
 *  - no authenticated user ⇒ 401;
 *  - no active company established (the gate must sit AFTER the `tenant` middleware) ⇒ 403;
 *  - the resolved set is missing ANY required permission ⇒ 403 `INSUFFICIENT_PERMISSION`.
 *
 * When several keys are listed, ALL are required (logical AND) — the most restrictive default.
 */
final class EnsurePermission
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        // The active company must already be pinned by the `tenant` middleware. If it is not, we cannot
        // make a confident positive authorization decision — so we deny (fail closed), never allow.
        $companyId = TenantContext::companyId();
        if ($companyId === null) {
            throw new InsufficientPermissionException;
        }

        $resolved = $this->resolver->resolve($user->id, $companyId);

        foreach ($permissions as $permission) {
            if (! $resolved->has($permission)) {
                throw new InsufficientPermissionException($permission);
            }
        }

        return $next($request);
    }
}
