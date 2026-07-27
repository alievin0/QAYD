<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-08 — the two credential/brute-force tables the login path owns
 * (docs/backend/AUTH_SERVICE.md "Database Tables Owned").
 *
 * Both are **global-identity** tables, not company-scoped: they describe a human's sign-in activity
 * and a bearer credential, not a company's business data. Per AUTH_SERVICE.md "# Multi-Tenancy
 * Enforcement" they carry no `company_id` discriminator and get NO tenant RLS policy — they are read
 * only on the privileged (owner) auth connection, filtered by `email`/`user_id` at the application
 * layer, exactly like `users` and `mfa_factors`. `email_verified_at` already exists on `users` from
 * the S1-04 schema, so no column is added here.
 *
 *  - `login_attempts` backs the sliding-window throttle + bounded backoff lockout (LoginThrottleService).
 *  - `refresh_tokens` are opaque, hashed-at-rest, rotated-on-use bearer refresh credentials with
 *    family-wide reuse detection (TokenService / RefreshTokenAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sliding-window brute-force ledger. `email` is CITEXT to match `users.email`; `ip` is INET.
        DB::statement(<<<'SQL'
            CREATE TABLE login_attempts (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                email         CITEXT NOT NULL,
                ip            INET NULL,
                successful    BOOLEAN NOT NULL DEFAULT false,
                attempted_at  TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement('CREATE INDEX idx_login_attempts_window ON login_attempts (email, attempted_at)');
        DB::statement('CREATE INDEX idx_login_attempts_ip_window ON login_attempts (ip, attempted_at)');

        // Opaque refresh tokens for bearer clients; rotation + reuse detection by family.
        DB::statement(<<<'SQL'
            CREATE TABLE refresh_tokens (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id       BIGINT NOT NULL REFERENCES users(id),
                company_id    BIGINT NULL REFERENCES companies(id),
                family_id     UUID NOT NULL,
                token_hash    CHAR(64) NOT NULL,
                device_fp     VARCHAR(128) NULL,
                rotated_to    BIGINT NULL REFERENCES refresh_tokens(id),
                revoked_at    TIMESTAMPTZ NULL,
                expires_at    TIMESTAMPTZ NOT NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_refresh_hash UNIQUE (token_hash)
            )
        SQL);

        DB::statement('CREATE INDEX idx_refresh_family ON refresh_tokens (family_id)');
        DB::statement('CREATE INDEX idx_refresh_user ON refresh_tokens (user_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS refresh_tokens');
        DB::statement('DROP TABLE IF EXISTS login_attempts');
    }
};
