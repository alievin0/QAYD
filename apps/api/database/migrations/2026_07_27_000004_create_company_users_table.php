<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 — `company_users` (docs/backend/AUTH_SERVICE.md "Database Tables Owned").
 *
 * The membership pivot: a user's identity *inside* one company. Company-scoped (carries
 * `company_id BIGINT NOT NULL REFERENCES companies(id)`) and RLS-enforced from S1-05. It is the one
 * table that legitimately references two tenants' concepts (a user and a company) from a single row,
 * which is how one human holds independent roles in many companies. The UNIQUE (company_id, user_id)
 * is the hard invariant S1-04 must land: a user has at most one membership row per company.
 *
 * Columns follow AUTH_SERVICE.md (the identity module that owns this table). Note MULTI_TENANCY.md
 * shows a divergent contextual shape for company_users (is_default / invited_at / accepted_at /
 * revoked_at, no deleted_at) — see the handoff report; AUTH_SERVICE.md is treated as authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE company_users (
                id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id       BIGINT NOT NULL REFERENCES companies(id),
                user_id          BIGINT NOT NULL REFERENCES users(id),
                role_id          BIGINT NOT NULL REFERENCES roles(id),
                branch_scope     JSONB NOT NULL DEFAULT '[]',
                department_scope JSONB NOT NULL DEFAULT '[]',
                perms_ver        INTEGER NOT NULL DEFAULT 1,
                status           VARCHAR(16) NOT NULL DEFAULT 'active'
                                   CHECK (status IN ('active', 'suspended', 'revoked')),
                invited_by       BIGINT NULL REFERENCES users(id),
                joined_at        TIMESTAMPTZ NULL,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at       TIMESTAMPTZ NULL,
                CONSTRAINT uq_company_users UNIQUE (company_id, user_id)
            )
        SQL);

        DB::statement('CREATE INDEX idx_company_users_user ON company_users (user_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS company_users');
    }
};
