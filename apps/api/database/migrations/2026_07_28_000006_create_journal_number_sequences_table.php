<?php

use App\Services\Accounting\JournalNumberAllocator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-05 — `journal_number_sequences`: the per-scope monotonic counter that assigns a posted entry its
 * PERMANENT `journal_number` (docs/accounting/JOURNAL_ENTRIES.md "# Database Design" +
 * "# Locking Rules" §6). A draft carries a provisional `DRAFT-{id}`; the posting engine (S2-05)
 * allocates the real number here, at posting, so numbers are gapless per scope and abandoned drafts
 * never burn a number.
 *
 * One sequence per `(company_id, fiscal_year_id, entry_type)`. The number is formatted
 * `{prefix}-{fiscal_year.name}-{next_number, zero-padded to padding}` — e.g. `JE-FY2026-000001`.
 * Concurrency safety (Locking Rule §6) is delivered by an atomic upsert-increment
 * (`INSERT … ON CONFLICT DO UPDATE SET next_number = next_number + 1 RETURNING …`): the conflicting row
 * is locked for the allocating transaction, so two concurrent posts in the same scope can never receive
 * the same number — see {@see JournalNumberAllocator}.
 *
 * Scope note (deferred columns): the full JOURNAL_ENTRIES.md sequence also keys on `branch_id`, whose
 * table does not exist yet (branches) and which `journal_entries` itself defers; it is added — with its
 * real FK and folded into the unique key — by the migration that creates `branches`. Strict tenant table
 * (`company_id NOT NULL`) ⇒ the uniform RLS boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE journal_number_sequences (
                id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id     BIGINT NOT NULL REFERENCES companies(id),
                fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
                entry_type     journal_entry_type NOT NULL,
                prefix         VARCHAR(10) NOT NULL DEFAULT 'JE',
                next_number    BIGINT NOT NULL DEFAULT 1,
                padding        SMALLINT NOT NULL DEFAULT 6,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT chk_jns_next_number_min CHECK (next_number >= 1),
                CONSTRAINT chk_jns_padding_range   CHECK (padding BETWEEN 1 AND 12),
                CONSTRAINT uq_jns UNIQUE (company_id, fiscal_year_id, entry_type)
            )
            SQL);

        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE removes the table's RLS policies, unique/check constraints, and FKs with it.
        DB::statement('DROP TABLE IF EXISTS journal_number_sequences');
    }

    /** The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), as on every tenant table. */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE journal_number_sequences ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE journal_number_sequences FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY jns_company_boundary ON journal_number_sequences
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY jns_tenant_select ON journal_number_sequences
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY jns_tenant_insert ON journal_number_sequences
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY jns_tenant_update ON journal_number_sequences
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY jns_tenant_delete ON journal_number_sequences
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
