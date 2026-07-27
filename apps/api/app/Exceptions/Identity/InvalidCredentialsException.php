<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Actions\Identity\LoginAction;
use App\Exceptions\DomainException;

/**
 * `INVALID_CREDENTIALS` (401) — a bad email/password pair (docs/backend/AUTH_SERVICE.md
 * "# Error Handling", docs/frontend/flows/LOGIN_FLOW.md).
 *
 * The message is deliberately generic and identical whether the email is unknown or the password is
 * wrong: it must never reveal which, so an attacker cannot enumerate accounts. The constant-time /
 * dummy-hash check in {@see LoginAction} guarantees the timing carries the same
 * signal — none.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct(string $message = 'The email address or password is incorrect.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }

    public function errorStatus(): int
    {
        return 401;
    }
}
