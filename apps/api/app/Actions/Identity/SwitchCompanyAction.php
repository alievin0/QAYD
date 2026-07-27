<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Domain\Identity\ActiveCompany;
use App\Exceptions\Identity\NotACompanyMemberException;
use App\Models\Company;
use App\Models\User;
use App\Repositories\Identity\MembershipRepository;
use App\Services\Identity\PermissionResolver;

/**
 * Re-scope the caller to another company they are a member of (S1-09, docs/backend/AUTH_SERVICE.md
 * "# Key Classes — SwitchCompanyAction"): validate a live `company_users` membership for the target,
 * then resolve the permission set for the new active company.
 *
 * The HTTP-layer re-scoping — regenerating the web session id, or re-minting a company-scoped bearer
 * token — belongs to the controller; this Action is transport-agnostic and owns the security decision.
 *
 * Enumeration-safe by construction: a company that does not exist and a company the caller simply is
 * not a member of are BOTH surfaced as {@see NotACompanyMemberException} → 404, so switching can never
 * be used to probe which tenants exist. The company is looked up on the privileged (owner) connection
 * and membership is checked there too — never through the RLS-scoped tenant connection, which has no
 * company context on this route.
 */
final class SwitchCompanyAction
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly PermissionResolver $resolver,
    ) {}

    /**
     * @param  string  $companyUuid  the target company's public UUID (never its internal id)
     *
     * @throws NotACompanyMemberException 404 — no such company, or the caller is not a live member
     */
    public function execute(User $user, string $companyUuid): ActiveCompany
    {
        $company = Company::query()
            ->where('uuid', $companyUuid)
            ->where('status', '!=', 'archived')
            ->whereNull('deleted_at')
            ->first();

        if (! $company instanceof Company) {
            throw new NotACompanyMemberException;
        }

        $membership = $this->memberships->activeFor($user->id, $company->id);
        if ($membership === null) {
            throw new NotACompanyMemberException;
        }

        $permissions = $this->resolver->resolve($user->id, $company->id);

        return new ActiveCompany(
            id: $company->id,
            uuid: (string) $company->uuid,
            roleKey: $membership->roleKey,
            permissions: $permissions,
        );
    }
}
