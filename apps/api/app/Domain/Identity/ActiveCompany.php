<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * The result of re-scoping the caller to a company they are a member of (S1-09 SwitchCompanyAction,
 * docs/backend/AUTH_SERVICE.md "# Endpoints Backed — POST /auth/switch-company"): the now-active
 * company identified by its public UUID, the caller's role in it, and the freshly-resolved permission
 * set for it. The controller projects this to the response and (for bearer clients) re-mints a
 * company-scoped access token from it.
 */
final readonly class ActiveCompany
{
    public function __construct(
        public int $id,            // internal company id — never serialised to a client
        public string $uuid,       // public company id
        public string $roleKey,
        public ResolvedPermissions $permissions,
    ) {}
}
