<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Exceptions\Identity\InvalidVerificationLinkException;
use App\Models\User;
use App\Repositories\Identity\UserRepository;
use Illuminate\Support\Carbon;

/**
 * Consume a signed email-verification link (SPRINT_01 §S1-07, docs/backend/AUTH_SERVICE.md
 * "# Responsibilities — Registration and email verification").
 *
 * The URL signature itself is validated upstream by the `signed` middleware; this action performs the
 * secondary integrity check — the `hash` must equal `sha1(email)` for the resolved user — so a link is
 * bound to one account's exact email at mint time. Idempotent: re-verifying an already-verified account
 * is a no-op success, matching the "reset link opened a second time" tolerance in LOGIN_FLOW.md.
 */
final class VerifyEmailAction
{
    public function __construct(private readonly UserRepository $users) {}

    public function execute(int $userId, string $hash): User
    {
        $user = $this->users->findById($userId);

        if ($user === null || ! hash_equals(sha1($user->email), $hash)) {
            throw new InvalidVerificationLinkException;
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = Carbon::now();
            $user->save();
        }

        return $user;
    }
}
