<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Repositories\Identity\MembershipRepository;
use Illuminate\Http\Request;

/**
 * Determines which company is "active" for a self-scoped identity request (`GET /auth/me`) — the
 * company whose resolved permissions `/auth/me` returns (docs/backend/AUTH_SERVICE.md
 * "# Endpoints Backed": me "carries … the permissions resolved for the currently active company").
 *
 * Resolution order, all self-scoped to the authenticated user and re-validated against a live
 * membership so a switched-away or revoked company never lingers:
 *
 *   1. **Bearer `cid`.** A company-scoped access token (minted by `switch-company`) carries the active
 *      company in its `cid` claim — this is how a stateless bearer client's switch survives.
 *   2. **Session.** The web SPA's active company is stored in the Sanctum session by `switch-company`.
 *   3. **Sole membership.** A user who belongs to exactly one company is implicitly scoped to it; a
 *      multi-company user who has not chosen yet has no active company (and no resolved permissions).
 */
final class ActiveCompanyResolver
{
    public const SESSION_KEY = 'active_company_id';

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly TokenService $tokens,
    ) {}

    /**
     * The internal id of the active company for this request, or null when none is (yet) selected.
     */
    public function forRequest(Request $request, User $user): ?int
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $cid = $this->tokens->companyIdFromAccessToken($bearer);
            if ($cid !== null && $this->memberships->activeFor($user->id, $cid) !== null) {
                return $cid;
            }
        }

        if ($request->hasSession()) {
            $sessionCid = $request->session()->get(self::SESSION_KEY);
            if (is_int($sessionCid) && $this->memberships->activeFor($user->id, $sessionCid) !== null) {
                return $sessionCid;
            }
        }

        $companyIds = $this->memberships->activeCompanyIdsFor($user->id);

        return count($companyIds) === 1 ? $companyIds[0] : null;
    }
}
