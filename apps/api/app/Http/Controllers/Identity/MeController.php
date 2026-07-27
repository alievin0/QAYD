<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Identity\ActiveCompanyResolver;
use App\Services\Identity\MembershipDirectory;
use App\Services\Identity\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/auth/me` (SPRINT_01 §S1-08/09) — the client's source of truth for identity, the
 * companies the caller may act in, the active company, and — as of S1-09 — the permission set
 * {@see PermissionResolver} resolves for that active company plus its `perms_ver`
 * (docs/backend/AUTH_SERVICE.md "# Endpoints Backed"). Authenticated via EITHER the Sanctum session
 * cookie (`web`) OR a bearer JWT.
 *
 * The active company is whatever the caller last switched to ({@see ActiveCompanyResolver}: a bearer
 * `cid`, the web session, or the sole membership); a multi-company user who has not chosen yet has no
 * active company and therefore an empty permission set — authentication grants nothing on its own.
 */
final class MeController extends Controller
{
    public function __construct(
        private readonly MembershipDirectory $memberships,
        private readonly ActiveCompanyResolver $activeCompany,
        private readonly PermissionResolver $resolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $memberships = $this->memberships->forUser($user->id);
        $activeCompanyId = $this->activeCompany->forRequest($request, $user);

        $active = null;
        foreach ($memberships as $membership) {
            if ($membership['company_id'] === $activeCompanyId) {
                $active = $membership;
                break;
            }
        }

        // Resolve `role ∪ grant − deny` for the active company; no active company ⇒ the empty set.
        $permissions = [];
        $permsVer = null;
        if ($active !== null) {
            $resolved = $this->resolver->resolve($user->id, $active['company_id']);
            $permissions = $resolved->all();
            $permsVer = $resolved->permsVer();
        }

        return ApiResponse::success([
            'user' => [
                'uuid' => $user->uuid,
                'email' => $user->email,
                'name' => $user->name,
                'locale' => $user->locale,
                'mfa_enrolled' => $user->mfa_enrolled,
            ],
            'companies' => array_map(fn (array $m): array => [
                'uuid' => $m['company_uuid'],
                'name_en' => $m['name_en'],
                'name_ar' => $m['name_ar'],
                'role' => $m['role'],
            ], $memberships),
            'active_company' => $active !== null
                ? ['uuid' => $active['company_uuid'], 'role' => $active['role']]
                : null,
            'perms_ver' => $permsVer,
            'permissions' => $permissions,
        ], 'identity.me');
    }
}
