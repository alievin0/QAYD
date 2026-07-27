<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Actions\Identity\VerifyEmailAction;
use App\Exceptions\DomainException;

/**
 * `INVALID_VERIFICATION_LINK` (403) — an email-verification link whose signature is missing/tampered
 * or whose embedded email hash no longer matches the account (docs/backend/AUTH_SERVICE.md).
 *
 * The signed-URL signature itself is validated by the `signed` middleware; this exception covers the
 * secondary integrity check (`hash` == sha1(email)) inside {@see VerifyEmailAction},
 * so a link minted for an old email can never verify a changed one.
 */
final class InvalidVerificationLinkException extends DomainException
{
    public function __construct(string $message = 'This verification link is invalid or has expired.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'INVALID_VERIFICATION_LINK';
    }

    public function errorStatus(): int
    {
        return 403;
    }
}
