<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Actions\Identity\SwitchCompanyAction;
use App\Exceptions\DomainException;

/**
 * `switch-company` (or any active-company selection) to a company the caller holds no live membership
 * in — thrown by {@see SwitchCompanyAction}.
 *
 * Rendered as **404**, never 403 (docs/backend/AUTH_SERVICE.md "# Error Handling":
 * "NotACompanyMemberException … 404 not_found (never 403)"). A 403 would confirm the company exists —
 * a tenant-enumeration side channel — so a non-existent company and a company the caller simply is not
 * a member of are deliberately indistinguishable. It reuses the catalog's `RESOURCE_NOT_FOUND` code so
 * it is identical on the wire to the middleware's cross-tenant 404 (SPRINT_01 §S1-06).
 */
final class NotACompanyMemberException extends DomainException
{
    public function __construct(string $message = 'The requested resource was not found.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'RESOURCE_NOT_FOUND';
    }

    public function errorStatus(): int
    {
        return 404;
    }
}
