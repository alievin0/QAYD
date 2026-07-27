<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureEmailVerified;

/**
 * `EMAIL_NOT_VERIFIED` (403) — an email-unverified user attempted a gated action, the canonical case
 * being "create a company" (docs/backend/AUTH_SERVICE.md "# Error Handling", SPRINT_01 §S1-07).
 *
 * Registration succeeds without verification, but the account cannot cross into creating a tenant
 * until the signed verification link is consumed. Enforced by
 * {@see EnsureEmailVerified}.
 */
final class EmailNotVerifiedException extends DomainException
{
    public function __construct(string $message = 'You must verify your email address before creating a company.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'EMAIL_NOT_VERIFIED';
    }

    public function errorStatus(): int
    {
        return 403;
    }
}
