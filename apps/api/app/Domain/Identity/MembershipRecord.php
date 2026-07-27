<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Repositories\Identity\MembershipRepository;

/**
 * A resolved, authoritative company membership — the `company_users` row (joined to its role key)
 * that authorization is composed from (docs/backend/AUTH_SERVICE.md "# Domain Model": "a company
 * membership pins the user's `role_id` … plus any per-user custom grants and denials").
 *
 * Read on the privileged (owner) connection by {@see MembershipRepository},
 * always self-scoped by the trusted authenticated `user_id` + the requested `company_id`.
 */
final readonly class MembershipRecord
{
    public function __construct(
        public int $id,          // company_users.id — the company_user_id the perms cache is keyed by
        public int $companyId,
        public int $userId,
        public int $roleId,
        public string $roleKey,
        public int $permsVer,
        public string $status,
    ) {}
}
