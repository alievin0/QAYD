<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-11 prerequisite — `idempotency_keys` (docs/api/API_IDEMPOTENCY.md; the storage half of S2-13).
 *
 * A journal post moves money. If the browser retries — a dropped connection, a double click, a mobile
 * network that resends — the second request must not create a second posted entry. The posting engine's
 * row lock already refuses to post the same entry twice, but that only helps when the retry names the
 * same draft; it says nothing about a retried *create-and-post*. This table is what lets the answer be
 * "you already did that, here is what happened" rather than a duplicate or a confusing error.
 *
 * `UNIQUE (company_id, endpoint, idempotency_key)` is the key shape SPRINT_02 §S2-13 specifies, and the
 * uniqueness is the mechanism rather than a nicety: two concurrent retries race to INSERT, exactly one
 * wins, and the loser reads the winner's stored response instead of executing.
 *
 * `request_hash` exists so a key can never be silently reused for a different request. Replaying a
 * stored response for a body that does not match would be worse than having no idempotency at all — the
 * caller would be told an operation succeeded that never ran.
 *
 * **Scope.** This is the storage plus the replay/conflict behaviour S2-11 needs in order to post safely.
 * The rest of S2-13 — the `accounting.journal.posted` Reverb broadcast, and rolling the middleware out
 * across every money-moving endpoint — stays in S2-13.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE idempotency_keys (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id      BIGINT NOT NULL REFERENCES companies(id),
                endpoint        VARCHAR(160) NOT NULL,
                idempotency_key VARCHAR(255) NOT NULL,
                request_hash    CHAR(64) NOT NULL,
                response_status SMALLINT NOT NULL,
                response_body   JSONB NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_idempotency_scope UNIQUE (company_id, endpoint, idempotency_key),
                CONSTRAINT chk_idempotency_status CHECK (response_status BETWEEN 100 AND 599)
            )
            SQL);

        // Retention sweeps delete by age; S2-13 owns scheduling one.
        DB::statement('CREATE INDEX idx_idempotency_created ON idempotency_keys (created_at)');

        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS idempotency_keys');
    }

    /**
     * The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md). It matters more here
     * than the table's modest contents suggest: without it, a key minted by one company could be read —
     * and its stored response replayed — by another.
     */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE idempotency_keys ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE idempotency_keys FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY idempotency_keys_company_boundary ON idempotency_keys
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY idempotency_keys_tenant_select ON idempotency_keys
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY idempotency_keys_tenant_insert ON idempotency_keys
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY idempotency_keys_tenant_delete ON idempotency_keys
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
