<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Exceptions\DomainException;
use App\Services\Identity\TokenService;

/**
 * `REFRESH_TOKEN_REUSE_DETECTED` (401) — a refresh token that had already been rotated was replayed,
 * the signature of a stolen-token attack (docs/backend/AUTH_SERVICE.md "# Events" →
 * `identity.refresh.reuse_detected`).
 *
 * When {@see TokenService} sees a token whose row is already rotated/revoked, it
 * revokes the **entire family** (every descendant of that rotation chain) and throws this, forcing a
 * full re-authentication — the legitimate holder's newest token dies too, which is the intended,
 * fail-closed outcome.
 */
final class RefreshTokenReusedException extends DomainException
{
    public function __construct(string $message = 'This session has been revoked for your security. Please sign in again.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'REFRESH_TOKEN_REUSE_DETECTED';
    }

    public function errorStatus(): int
    {
        return 401;
    }
}
