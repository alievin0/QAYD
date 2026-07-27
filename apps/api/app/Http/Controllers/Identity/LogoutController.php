<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Identity\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /api/v1/auth/logout` (SPRINT_01 §S1-08) — revokes the calling credential only
 * (docs/backend/AUTH_SERVICE.md "# Endpoints Backed").
 *
 *  - Web SPA: log the user out of the Sanctum session and invalidate + re-key the session, so the
 *    session cookie can never be replayed.
 *  - Bearer: if a refresh token is presented, revoke it so it can no longer be rotated. The stateless
 *    access token simply expires (a jti denylist for instant access-token revocation is later work).
 */
final class LogoutController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function __invoke(Request $request): JsonResponse
    {
        $refreshToken = $request->string('refresh_token')->toString();
        if ($refreshToken !== '') {
            $this->tokens->revokeRefreshToken($refreshToken);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(null, 'identity.logged_out');
    }
}
