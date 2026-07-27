<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsurePermission;

/**
 * `INSUFFICIENT_PERMISSION` (403) — a guarded route was reached without the permission it requires, in
 * the caller's active company. Thrown by {@see EnsurePermission}.
 *
 * This is the deny-by-default outcome of the permission system (docs/foundation/PERMISSION_SYSTEM.md
 * "# Default Rule": "Everything is denied"): authentication grants nothing, so any route not covered
 * by the resolved permission set fails closed here. The required permission key is carried in `meta`
 * for the client and audit, never leaked into the human-facing message.
 */
final class InsufficientPermissionException extends DomainException
{
    public function __construct(string $requiredPermission = '')
    {
        parent::__construct('You do not have permission to perform this action.');

        if ($requiredPermission !== '') {
            $this->meta = ['required_permission' => $requiredPermission];
        }
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_PERMISSION';
    }

    public function errorStatus(): int
    {
        return 403;
    }
}
