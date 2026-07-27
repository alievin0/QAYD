<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Domain\Identity\Credential;
use App\Exceptions\Identity\RefreshTokenInvalidException;
use App\Exceptions\Identity\RefreshTokenReusedException;
use App\Models\User;
use App\Repositories\Identity\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single place bearer credentials are minted and verified (docs/backend/AUTH_SERVICE.md
 * "# Key Classes — TokenService"): RS256 sign/verify by `kid`, opaque refresh-token issuance hashed at
 * rest, and rotation-on-use with family-wide reuse detection.
 *
 * Access tokens are stateless RS256 JWTs (15-minute default); refresh tokens are opaque random secrets
 * QAYD never stores in cleartext (only their SHA-256). Refresh rows are a **global-identity** table
 * read on the privileged (owner) connection, never the tenant scope — see AUTH_SERVICE.md
 * "# Multi-Tenancy Enforcement".
 */
final class TokenService
{
    public function __construct(
        private readonly SigningKeyService $keys,
        private readonly UserRepository $users,
    ) {}

    /**
     * Mint an RS256 access token for the subject. `sub` is the public UUID (never the sequential id);
     * `cid` optionally scopes it to an active company.
     *
     * @return array{token: string, expires_in: int}
     */
    public function issueAccessToken(User $user, ?int $companyId = null): array
    {
        $ttl = $this->accessTtl();
        $now = time();

        /** @var array<string, mixed> $claims */
        $claims = [
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => (string) Str::uuid(),
            'sub' => $user->uuid,
            'uid' => $user->id,
            'typ' => 'access',
            'cid' => $companyId,
        ];

        $token = JWT::encode($claims, $this->keys->privateKey(), $this->algorithm(), $this->keys->kid());

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * Verify a bearer access token and resolve its subject, or return null on ANY failure (bad
     * signature, wrong/none algorithm, expired, wrong issuer/audience, unknown or non-active user).
     * Fail-closed: every doubt is "not authenticated".
     */
    public function userFromAccessToken(string $jwt): ?User
    {
        try {
            JWT::$leeway = $this->leeway();
            $decoded = JWT::decode($jwt, new Key($this->keys->publicKey(), $this->algorithm()));
        } catch (Throwable) {
            return null;
        }

        if (($decoded->iss ?? null) !== $this->issuer()
            || ($decoded->aud ?? null) !== $this->audience()
            || ($decoded->typ ?? null) !== 'access') {
            return null;
        }

        $sub = $decoded->sub ?? null;
        if (! is_string($sub) || $sub === '') {
            return null;
        }

        $user = $this->users->findByUuid($sub);

        if ($user === null || $user->status !== 'active') {
            return null;
        }

        return $user;
    }

    /**
     * The active company id a bearer access token is scoped to (its `cid` claim), or null when the
     * token carries none (a company-unscoped token) or fails to decode. Used by `/auth/me` to reflect a
     * `switch-company` that re-minted a company-scoped token, so a stateless bearer client's active
     * company survives without server-side session state. Fail-closed: any decode/type failure ⇒ null.
     */
    public function companyIdFromAccessToken(string $jwt): ?int
    {
        try {
            JWT::$leeway = $this->leeway();
            $decoded = JWT::decode($jwt, new Key($this->keys->publicKey(), $this->algorithm()));
        } catch (Throwable) {
            return null;
        }

        if (($decoded->typ ?? null) !== 'access') {
            return null;
        }

        $cid = $decoded->cid ?? null;

        return is_numeric($cid) ? (int) $cid : null;
    }

    /**
     * Issue an opaque refresh token (prefixed `rft_`), storing only its SHA-256. A new `familyId`
     * starts a fresh rotation chain; passing an existing one continues the chain on rotation.
     *
     * @return array{token: string, expires_in: int, family_id: string}
     */
    public function issueRefreshToken(User $user, ?int $companyId = null, ?string $familyId = null): array
    {
        $ttl = $this->refreshTtl();
        $secret = 'rft_'.$this->randomToken();
        $family = $familyId ?? (string) Str::uuid();

        $this->connection()->table('refresh_tokens')->insert([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'family_id' => $family,
            'token_hash' => $this->hash($secret),
            'expires_at' => Carbon::now()->addSeconds($ttl),
            'created_at' => Carbon::now(),
        ]);

        return ['token' => $secret, 'expires_in' => $ttl, 'family_id' => $family];
    }

    /**
     * Rotation-on-use with reuse detection. Presenting a valid, live token rotates it: the old row is
     * revoked and a fresh access+refresh pair is issued in the same family. Presenting an
     * already-rotated/revoked token is treated as theft — the WHOLE family is revoked and
     * {@see RefreshTokenReusedException} is thrown (docs/backend/AUTH_SERVICE.md
     * "identity.refresh.reuse_detected").
     */
    public function rotate(string $presented): Credential
    {
        $connection = $this->connection();

        // Decide and mutate atomically inside the transaction, but NEVER throw from within it — a
        // thrown exception would roll the transaction back and UNDO the very family-revocation that
        // reuse detection depends on. The closure records its outcome by reference and commits; the
        // rejection is thrown only after the commit.
        $credential = null;
        $reuseDetected = false;

        $connection->transaction(function () use ($connection, $presented, &$credential, &$reuseDetected): void {
            $row = $connection->table('refresh_tokens')
                ->where('token_hash', $this->hash($presented))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return; // unknown token → invalid (nothing to revoke)
            }

            // Replay of a token already rotated or revoked ⇒ revoke the entire rotation chain. This
            // UPDATE must COMMIT, which is why the throw happens after the transaction, not here.
            if ($row->revoked_at !== null || $row->rotated_to !== null) {
                $connection->table('refresh_tokens')
                    ->where('family_id', $row->family_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => Carbon::now()]);
                $reuseDetected = true;

                return;
            }

            if (Carbon::parse($this->asString($row->expires_at))->isPast()) {
                $connection->table('refresh_tokens')
                    ->where('id', $row->id)
                    ->update(['revoked_at' => Carbon::now()]);

                return; // expired → invalid
            }

            $user = $this->users->findById($this->asInt($row->user_id));
            if ($user === null || $user->status !== 'active') {
                return; // unknown/suspended subject → invalid
            }

            $companyId = $row->company_id !== null ? $this->asInt($row->company_id) : null;

            $access = $this->issueAccessToken($user, $companyId);
            $refresh = $this->issueRefreshToken($user, $companyId, $this->asString($row->family_id));

            $newId = $connection->table('refresh_tokens')
                ->where('token_hash', $this->hash($refresh['token']))
                ->value('id');

            $connection->table('refresh_tokens')
                ->where('id', $row->id)
                ->update(['revoked_at' => Carbon::now(), 'rotated_to' => $newId]);

            $credential = new Credential(
                user: $user,
                accessToken: $access['token'],
                accessExpiresIn: $access['expires_in'],
                refreshToken: $refresh['token'],
                refreshExpiresIn: $refresh['expires_in'],
            );
        });

        if ($reuseDetected) {
            throw new RefreshTokenReusedException;
        }

        if (! $credential instanceof Credential) {
            throw new RefreshTokenInvalidException;
        }

        return $credential;
    }

    /**
     * Revoke a single presented refresh token (logout of the calling credential). No-op if unknown, so
     * a logout never leaks whether a token existed.
     */
    public function revokeRefreshToken(string $presented): void
    {
        $this->connection()->table('refresh_tokens')
            ->where('token_hash', $this->hash($presented))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Coerce a (mixed) database row value to string. */
    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** Coerce a (mixed) database row value to int. */
    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** The privileged (owner) connection — refresh tokens are a global-identity table, never RLS-scoped. */
    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    private function algorithm(): string
    {
        $algo = config('jwt.algorithm');

        return is_string($algo) && $algo !== '' ? $algo : 'RS256';
    }

    private function issuer(): string
    {
        $value = config('jwt.issuer');

        return is_string($value) ? $value : '';
    }

    private function audience(): string
    {
        $value = config('jwt.audience');

        return is_string($value) ? $value : '';
    }

    private function leeway(): int
    {
        $value = config('jwt.leeway');

        return is_int($value) ? $value : 10;
    }

    private function accessTtl(): int
    {
        $value = config('jwt.access_ttl');

        return is_int($value) ? $value : 900;
    }

    private function refreshTtl(): int
    {
        $value = config('jwt.refresh_ttl');

        return is_int($value) ? $value : 2592000;
    }
}
