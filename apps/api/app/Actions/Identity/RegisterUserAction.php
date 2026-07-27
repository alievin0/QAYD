<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Data\Identity\RegisterData;
use App\Http\Middleware\EnsureEmailVerified;
use App\Models\User;
use App\Notifications\Identity\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Register a new global identity (SPRINT_01 §S1-07, docs/backend/AUTH_SERVICE.md "# Responsibilities —
 * Registration and email verification").
 *
 * Creates the `users` row with the password hashed into `password_hash` using **argon2id** (the
 * configured driver), leaves `email_verified_at` NULL, and dispatches a signed, time-limited email
 * verification link. The account exists immediately but cannot create a company until it is verified
 * ({@see EnsureEmailVerified}).
 */
final class RegisterUserAction
{
    /** How long a verification link stays valid. */
    private const LINK_TTL_HOURS = 24;

    public function execute(RegisterData $data): User
    {
        $user = new User;
        $user->name = $data->name;
        $user->email = $data->email;
        $user->password_hash = Hash::make($data->password); // argon2id per config/hashing.php
        $user->locale = $data->locale;
        $user->status = 'active';
        $user->mfa_enrolled = false;
        $user->save();

        // Reload DB-generated columns (uuid) so the subject id is available downstream.
        $user->refresh();

        $user->notify(new VerifyEmailNotification($this->verificationUrl($user)));

        return $user;
    }

    /**
     * A tamper-evident, expiring signed URL. The `hash` binds the link to the CURRENT email, so a link
     * minted before an email change can never verify the new address.
     */
    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'auth.email.verify',
            Carbon::now()->addHours(self::LINK_TTL_HOURS),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
    }
}
