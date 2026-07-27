<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Exceptions\DomainException;

/**
 * `REFRESH_TOKEN_INVALID` (401) — a presented refresh token is unknown, expired, or already revoked
 * (docs/backend/AUTH_SERVICE.md "# Error Handling"). Distinct from a *reuse* of a rotated token, which
 * is the more serious {@see RefreshTokenReusedException}.
 */
final class RefreshTokenInvalidException extends DomainException
{
    public function __construct(string $message = 'The refresh token is invalid or has expired.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'REFRESH_TOKEN_INVALID';
    }

    public function errorStatus(): int
    {
        return 401;
    }
}
