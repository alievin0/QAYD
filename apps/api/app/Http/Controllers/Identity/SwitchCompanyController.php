<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Actions\Identity\SwitchCompanyAction;
use App\Domain\Identity\ActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Identity\ActiveCompanyResolver;
use App\Services\Identity\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/switch-company` (S1-09, docs/backend/AUTH_SERVICE.md "# Endpoints Backed").
 * Re-scopes the caller's active company after {@see SwitchCompanyAction} has validated a live
 * membership (else 404). Like login, it serves BOTH client models and owns only the HTTP-layer
 * re-scoping the Action is agnostic to:
 *
 *  - **Web SPA** — the new active company is stored in the Sanctum session and the session id is
 *    regenerated (anti-fixation), mirroring the login flow.
 *  - **Bearer clients** — a fresh company-scoped access token (`cid` = the new company) plus a rotating
 *    refresh token are re-minted and returned, so the next `/auth/me` reflects the switch statelessly.
 *
 * The response carries the new active company (public UUID + role) and its freshly-resolved permission
 * set, so a client need not round-trip to `/auth/me` to re-render.
 */
final class SwitchCompanyController extends Controller
{
    public function __construct(
        private readonly SwitchCompanyAction $action,
        private readonly TokenService $tokens,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        /** @var array{company_id: string} $validated */
        $validated = $request->validate([
            'company_id' => ['required', 'string'],
        ]);

        $active = $this->action->execute($user, $validated['company_id']);

        // Web SPA: pin the new active company in the session and rotate the session id.
        if ($request->hasSession()) {
            $request->session()->put(ActiveCompanyResolver::SESSION_KEY, $active->id);
            $request->session()->regenerate();
        }

        return ApiResponse::success($this->payload($request, $user, $active), 'identity.company.switched');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, User $user, ActiveCompany $active): array
    {
        $data = [
            'active_company' => [
                'uuid' => $active->uuid,
                'role' => $active->roleKey,
            ],
            'perms_ver' => $active->permissions->permsVer(),
            'permissions' => $active->permissions->all(),
        ];

        // Bearer clients: re-mint a company-scoped access + rotating refresh token for the new company.
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $accessToken = $this->tokens->issueAccessToken($user, $active->id);
            $refreshToken = $this->tokens->issueRefreshToken($user, $active->id);

            $data['token_type'] = 'Bearer';
            $data['access_token'] = $accessToken['token'];
            $data['expires_in'] = $accessToken['expires_in'];
            $data['refresh_token'] = $refreshToken['token'];
            $data['refresh_expires_in'] = $refreshToken['expires_in'];
        }

        return $data;
    }
}
