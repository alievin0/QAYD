<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Identity\MembershipDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/auth/me` (SPRINT_01 §S1-08) — the client's source of truth for identity, the companies
 * the caller may act in, the active company, and its `perms_ver` (docs/backend/AUTH_SERVICE.md
 * "# Endpoints Backed"). Authenticated via EITHER the Sanctum session cookie (`web`) OR a bearer JWT.
 *
 * The resolved permission set is deliberately an EMPTY array here: the {@see PermissionResolver} that
 * composes `role ∪ grant − deny` lands in the NEXT story (S1-09). Returning `[]` now — rather than a
 * fake resolver — keeps the contract shape stable without pretending to authorize anything.
 */
final class MeController extends Controller
{
    public function __construct(private readonly MembershipDirectory $memberships) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $memberships = $this->memberships->forUser($user->id);

        // With no switch-company yet (S1-09), a single membership is the implicit active company; a
        // multi-company user has none until they choose one.
        $active = count($memberships) === 1 ? $memberships[0] : null;

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
            'perms_ver' => $active !== null ? $active['perms_ver'] : null,
            // TODO(S1-09): resolve via PermissionResolver (role ∪ grant − deny) for the active company.
            'permissions' => [],
        ], 'identity.me');
    }
}
