<?php

declare(strict_types=1);

namespace App\Repositories\Identity;

use App\Models\User;

/**
 * Privileged identity reads (docs/backend/AUTH_SERVICE.md "# Key Classes"). {@see User} runs on the
 * default (owner) connection — the "privileged auth lookups" path per config/tenancy.php — so these
 * lookups deliberately run BEFORE any tenant context exists and are not subject to the tenant scope.
 * They are self-scoped by the exact credential presented (email / uuid / id), never by client input.
 */
final class UserRepository
{
    /** Resolve a login identity by its (case-insensitive, citext) email, excluding soft-deleted rows. */
    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->first();
    }

    /** Resolve an identity by its public UUID — the subject (`sub`) of a bearer JWT. */
    public function findByUuid(string $uuid): ?User
    {
        return User::query()
            ->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();
    }

    public function findById(int $id): ?User
    {
        return User::query()
            ->whereKey($id)
            ->whereNull('deleted_at')
            ->first();
    }
}
