<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;

/**
 * The issued bearer credential (docs/backend/AUTH_SERVICE.md "# Domain Model": the `Credential` value
 * object — "never an array in the Application layer").
 *
 * An RS256 access token (short-lived) plus its opaque, rotating refresh token, bound to the subject
 * user. The web SPA additionally rides the Sanctum stateful session cookie the controller establishes;
 * bearer clients use these two tokens.
 */
final readonly class Credential
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public int $accessExpiresIn,
        public string $refreshToken,
        public int $refreshExpiresIn,
        public string $tokenType = 'Bearer',
    ) {}
}
