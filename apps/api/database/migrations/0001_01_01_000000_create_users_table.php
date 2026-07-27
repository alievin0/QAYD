<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1-04 — Core identity/tenant schema.
 *
 * Reconciled from Laravel's stock create_users_table so the `users` table matches the QAYD
 * global-identity schema in docs/backend/AUTH_SERVICE.md ("Database Tables Owned"): one human,
 * one row, many company memberships; NOT company-scoped. The stock columns (name/email varchar,
 * password, remember_token) are replaced wholesale by the QAYD columns (uuid, citext email,
 * password_hash, locale, mfa_enrolled, status, soft-delete).
 *
 * This is the first migration to run, so it also enables the `citext` extension that
 * `users.email` (and later auth tables) depend on. The framework `password_reset_tokens` and
 * `sessions` tables are retained here because this environment's SESSION/CACHE/QUEUE drivers are
 * `database`; the QAYD move to Redis-backed sessions is a later story, not S1-04.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Case-insensitive email uniqueness depends on citext. Must exist before `users`.
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        DB::statement(<<<'SQL'
            CREATE TABLE users (
                id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid              UUID NOT NULL DEFAULT gen_random_uuid(),
                email             CITEXT NOT NULL,
                email_verified_at TIMESTAMPTZ NULL,
                password_hash     TEXT NULL,
                name              VARCHAR(150) NOT NULL,
                locale            VARCHAR(8) NOT NULL DEFAULT 'ar',
                mfa_enrolled      BOOLEAN NOT NULL DEFAULT false,
                status            VARCHAR(16) NOT NULL DEFAULT 'active'
                                    CHECK (status IN ('active', 'suspended', 'locked')),
                last_login_at     TIMESTAMPTZ NULL,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at        TIMESTAMPTZ NULL,
                CONSTRAINT uq_users_email UNIQUE (email)
            )
        SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        DB::statement('DROP TABLE IF EXISTS users');
        DB::statement('DROP EXTENSION IF EXISTS citext');
    }
};
