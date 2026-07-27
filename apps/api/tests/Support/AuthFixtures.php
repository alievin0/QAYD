<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Seeds the identity fixtures the S1-07/08 feature tests need, on the OWNER connection (bypasses RLS),
 * the same way {@see TenantHarness::seedCompany()} does. Kept separate so a test can attach an
 * arbitrary (e.g. freshly-registered) user to a company + role + active membership.
 */
final class AuthFixtures
{
    /**
     * Give a user an active membership in a fresh company with a (company-specific) role. Returns the
     * ids/keys a test asserts on — including `role_id` and `company_user_id` so a test can attach
     * role permissions or per-membership grants/denies for the S1-09 resolver.
     *
     * @return array{company_id: int, company_uuid: string, role: string, role_id: int, company_user_id: int}
     */
    public static function membership(int $userId, string $name = 'Acme Trading', string $roleKey = 'owner'): array
    {
        $owner = TenantHarness::owner();

        $company = $owner->selectOne(
            'INSERT INTO companies (legal_name, name_en, name_ar) VALUES (?, ?, ?) RETURNING id, uuid',
            [$name, $name, $name.' AR'],
        );

        $roleId = (int) $owner->selectOne(
            'INSERT INTO roles (company_id, key, name_en, name_ar) VALUES (?, ?, ?, ?) RETURNING id',
            [$company->id, $roleKey, ucfirst($roleKey), 'دور'],
        )->id;

        $membershipId = (int) $owner->selectOne(
            "INSERT INTO company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active') RETURNING id",
            [$company->id, $userId, $roleId],
        )->id;

        return [
            'company_id' => (int) $company->id,
            'company_uuid' => (string) $company->uuid,
            'role' => $roleKey,
            'role_id' => $roleId,
            'company_user_id' => $membershipId,
        ];
    }
}
