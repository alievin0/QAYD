<?php

declare(strict_types=1);

namespace App\Data\Identity;

use App\Actions\Identity\RegisterUserAction;

/**
 * The validated input for {@see RegisterUserAction}. An immutable DTO — the
 * Action never receives a raw array or Request (docs/backend/SERVICE_ARCHITECTURE.md "every entrypoint
 * takes a DTO").
 */
final readonly class RegisterData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $locale = 'ar',
    ) {}
}
