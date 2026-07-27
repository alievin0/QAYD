<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use App\Exceptions\DomainException;

/**
 * `ACCOUNT_TEMPORARILY_LOCKED` (429) — the sliding-window throttle tripped after too many failed
 * sign-ins (docs/backend/AUTH_SERVICE.md "# Error Handling", docs/frontend/flows/LOGIN_FLOW.md
 * "# Alternate & Error Paths").
 *
 * Carries the bounded-backoff cooldown as a `Retry-After` header (seconds) AND in the envelope
 * `errors[].meta.retry_after`, so both a raw HTTP client and the SPA lockout countdown read the same
 * server-authoritative value — the frontend never guesses the wait.
 */
final class AccountLockedException extends DomainException
{
    public function __construct(private readonly int $retryAfter, string $message = 'Too many failed sign-in attempts. Please try again later.')
    {
        parent::__construct($message);

        $this->meta = ['retry_after' => $this->retryAfter];
    }

    public function errorCode(): string
    {
        return 'ACCOUNT_TEMPORARILY_LOCKED';
    }

    public function errorStatus(): int
    {
        return 429;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['Retry-After' => (string) $this->retryAfter];
    }
}
