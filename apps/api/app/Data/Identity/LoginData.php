<?php

declare(strict_types=1);

namespace App\Data\Identity;

use App\Actions\Identity\LoginAction;

/**
 * The validated input for {@see LoginAction}. Immutable; carries the credential
 * plus the request fingerprint the throttle keys on (docs/backend/SERVICE_ARCHITECTURE.md).
 */
final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $deviceName = null,
        public bool $remember = false,
    ) {}
}
