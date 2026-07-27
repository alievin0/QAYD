<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Data\Identity\LoginData;
use App\Domain\Identity\Credential;
use App\Exceptions\Identity\InvalidCredentialsException;
use App\Repositories\Identity\UserRepository;
use App\Services\Identity\LoginThrottleService;
use App\Services\Identity\TokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Verify a password under brute-force protection and issue a full bearer credential (SPRINT_01 §S1-08,
 * docs/backend/AUTH_SERVICE.md "# Key Classes — LoginAction").
 *
 * Two security details are load-bearing and hard to retrofit, so they are correct on the first pass:
 *
 *  1. **Constant-time, existence-hiding check.** Whether or not the email resolves to a user, a
 *     password hash comparison ALWAYS runs — a real user's `password_hash`, or a fixed dummy argon2id
 *     hash otherwise. There is no early return before the comparison, so response timing never reveals
 *     whether an account exists (docs/security/AUTHENTICATION.md).
 *  2. **Throttle before hash.** The sliding-window lockout is checked before the comparison, so a
 *     locked account is refused with a `429` without doing (or timing) the expensive hash.
 *
 * MFA is out of Sprint 1 scope: the step-up branch is stubbed to the non-MFA path (SPRINT_01
 * "# Out of scope"). The HTTP-layer concerns — establishing the Sanctum session cookie, regenerating
 * the session id, and writing the audit row — belong to the controller; this Action is transport-agnostic.
 */
final class LoginAction
{
    /**
     * A valid argon2id hash of a fixed placeholder. Comparing against it on a missing user costs the
     * same as a real verify, so the "no such user" and "wrong password" paths are timing-identical.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$aGJKaDFYL1RqVC5kbWV0MA$5JpLXI34n6PNBsP71r1lhEtlDiqWI34FEatHk8wMYYs';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottleService $throttle,
        private readonly TokenService $tokens,
    ) {}

    public function execute(LoginData $data): Credential
    {
        $this->throttle->assertNotLocked($data->email, $data->ip);

        $user = $this->users->findByEmail($data->email);

        // Always hash — a real user's hash, or the dummy — so a missing user does the same work and the
        // path never short-circuits before the comparison (constant-time; no existence leak).
        $hash = ($user !== null && $user->password_hash !== null) ? $user->password_hash : self::DUMMY_HASH;
        $passwordMatches = Hash::check($data->password, $hash);

        if ($user === null || $user->password_hash === null || ! $passwordMatches || $user->status !== 'active') {
            $this->throttle->recordFailure($data->email, $data->ip);

            throw new InvalidCredentialsException;
        }

        $this->throttle->recordSuccess($data->email, $data->ip);

        // MFA step-up is deferred (SPRINT_01 "# Out of scope"): Sprint 1 always issues a full credential.
        // TODO(security-hardening): if MfaService::isRequiredFor($user), return an mfa_pending credential.

        $access = $this->tokens->issueAccessToken($user);
        $refresh = $this->tokens->issueRefreshToken($user);

        $user->last_login_at = Carbon::now();
        $user->save();

        return new Credential(
            user: $user,
            accessToken: $access['token'],
            accessExpiresIn: $access['expires_in'],
            refreshToken: $refresh['token'],
            refreshExpiresIn: $refresh['expires_in'],
        );
    }
}
