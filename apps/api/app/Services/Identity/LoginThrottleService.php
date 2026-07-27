<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Exceptions\Identity\AccountLockedException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brute-force defense for the login path (docs/backend/AUTH_SERVICE.md "# Responsibilities —
 * Brute-force defense", SPRINT_01 §S1-08): a sliding-window rate limit recorded in `login_attempts`
 * with bounded exponential-backoff lockout.
 *
 * Policy: at most {@see MAX_ATTEMPTS} failed sign-ins per account inside a {@see WINDOW_SECONDS}
 * window. The 6th attempt within the minute is refused with a `429` and a `Retry-After` cooldown that
 * doubles for each attempt beyond the threshold, capped at {@see CEILING_SECONDS} (4 hours). The check
 * runs BEFORE the password comparison, so a locked account never even reaches the (constant-time) hash.
 *
 * `login_attempts` is a global-identity table read on the privileged (owner) connection, keyed by the
 * presented email — never the tenant scope.
 */
final class LoginThrottleService
{
    /** Sliding window length in seconds. */
    private const WINDOW_SECONDS = 60;

    /** Failed attempts allowed inside the window before the account locks. */
    private const MAX_ATTEMPTS = 5;

    /** Base backoff applied at the threshold, doubled per attempt beyond it. */
    private const BASE_BACKOFF_SECONDS = 60;

    /** Upper bound on the backoff cooldown (4 hours). */
    private const CEILING_SECONDS = 14400;

    /**
     * Refuse the login with a 429 if the account is currently locked. Throws {@see AccountLockedException}
     * carrying the server-authoritative `Retry-After`.
     */
    public function assertNotLocked(string $email, ?string $ip = null): void
    {
        $since = Carbon::now()->subSeconds(self::WINDOW_SECONDS);

        $failures = $this->connection()->table('login_attempts')
            ->where('email', $email)
            ->where('successful', false)
            ->where('attempted_at', '>=', $since)
            ->count();

        if ($failures < self::MAX_ATTEMPTS) {
            return;
        }

        // Record the blocked attempt too, so persistent hammering during a lockout ESCALATES the
        // window count — that is what makes the backoff genuinely exponential rather than a flat 60s
        // cap. Recording here (before the throw) also keeps the sliding window from ageing out while
        // an attacker is still active.
        $this->record($email, $ip, false);
        $failures++;

        // 6th attempt (failures now 6, MAX 5) ⇒ overage 1 ⇒ 60s; each further attempt doubles it up to
        // the 4-hour ceiling.
        $overage = $failures - self::MAX_ATTEMPTS;
        $retryAfter = (int) min(self::BASE_BACKOFF_SECONDS * (2 ** ($overage - 1)), self::CEILING_SECONDS);

        throw new AccountLockedException(max(1, $retryAfter));
    }

    public function recordFailure(string $email, ?string $ip = null): void
    {
        $this->record($email, $ip, false);
    }

    /**
     * Record a successful sign-in and clear the account's recent failures, so a legitimate login
     * immediately resets the sliding window rather than leaving a nearly-locked account.
     */
    public function recordSuccess(string $email, ?string $ip = null): void
    {
        $this->connection()->table('login_attempts')
            ->where('email', $email)
            ->where('successful', false)
            ->delete();

        $this->record($email, $ip, true);
    }

    private function record(string $email, ?string $ip, bool $successful): void
    {
        $this->connection()->table('login_attempts')->insert([
            'email' => $email,
            'ip' => $ip,
            'successful' => $successful,
            'attempted_at' => Carbon::now(),
        ]);
    }

    /** The privileged (owner) connection — `login_attempts` is a global-identity table, not RLS-scoped. */
    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
