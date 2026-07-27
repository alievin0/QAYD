<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Actions\Identity\LoginAction;
use App\Data\Identity\LoginData;
use App\Domain\Identity\Credential;
use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\MembershipDirectory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /api/v1/auth/login` (SPRINT_01 §S1-08). The one entrypoint that serves BOTH client models:
 *
 *  - **Web SPA** — a Sanctum stateful session cookie: {@see Auth::guard('web')->login()} logs the user
 *    into the session and {@see Session::regenerate()} rotates the session
 *    id to defeat session fixation.
 *  - **Bearer clients** — the RS256 JWT + opaque rotating refresh token returned in the body.
 *
 * The credential check, throttle, and constant-time comparison live in {@see LoginAction}; this
 * controller owns the HTTP-layer concerns the Action is agnostic to (session, cookie, audit). A
 * successful login writes a platform-level `audit_logs` row (no active company yet ⇒ `company_id` NULL,
 * written on the privileged connection past RLS).
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly LoginAction $login,
        private readonly MembershipDirectory $memberships,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credential = $this->login->execute(new LoginData(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            deviceName: $request->string('device_name')->toString() ?: null,
            remember: $request->boolean('remember_me'),
        ));

        // Establish the Sanctum stateful session (web SPA) and rotate the session id (anti-fixation).
        Auth::guard('web')->login($credential->user);
        $request->session()->regenerate();

        AuditLogger::record(
            action: 'user.logged_in',
            category: AuditCategory::Auth,
            actorUserId: $credential->user->id,
            newValues: ['method' => 'password', 'ip' => $request->ip()],
            connection: $this->privilegedConnection(),
        );

        return ApiResponse::success($this->payload($credential), 'identity.authenticated');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Credential $credential): array
    {
        $user = $credential->user;
        $companies = $this->memberships->forUser($user->id);

        $activeCompanyId = count($companies) === 1 ? $companies[0]['company_uuid'] : null;

        return [
            'status' => 'authenticated',
            'token_type' => $credential->tokenType,
            'access_token' => $credential->accessToken,
            'expires_in' => $credential->accessExpiresIn,
            'refresh_token' => $credential->refreshToken,
            'refresh_expires_in' => $credential->refreshExpiresIn,
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'mfa_enrolled' => $user->mfa_enrolled,
            ],
            'companies' => $companies,
            'active_company_id' => $activeCompanyId,
            'company_selection_required' => count($companies) > 1,
        ];
    }

    /** The privileged (owner) connection — the platform-level login audit row bypasses tenant RLS here. */
    private function privilegedConnection(): string
    {
        $default = config('database.default');

        return is_string($default) && $default !== '' ? $default : 'pgsql';
    }
}
