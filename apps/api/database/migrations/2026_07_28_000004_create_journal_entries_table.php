<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-03 — `journal_entries`: the double-entry posting header (docs/accounting/JOURNAL_ENTRIES.md
 * "# Database Design"). The single transactional core every posted financial fact passes through.
 *
 * This story lands the SCHEMA + its PostgreSQL-level integrity only — no lifecycle Actions, no posting
 * engine, no API (those are S2-04 / S2-05). Database integrity is preferred over application guarantees:
 * the balance invariant (the cached header totals must always be equal), the AI-safety rule (an
 * AI-generated entry can never be *created* as anything but a draft), non-negative money, a positive FX
 * rate, and the AI-confidence range are all enforced HERE by CHECK constraints and a trigger, so an
 * application-layer bug can never breach them.
 *
 * Scope note (deferred columns): the full JOURNAL_ENTRIES.md header also carries `branch_id`,
 * `fiscal_period_id`, `recurring_template_id`, and `ai_conversation_id` FKs whose target tables do not
 * exist yet (branches; fiscal_periods → S2-07; journal_entry_templates; ai_conversations). They are added
 * — with their real FK constraints — by the migrations that create those tables, so every FK in QAYD is a
 * genuine referential guarantee rather than a bare id column. `fiscal_year_id` (its table exists) is
 * nullable here; the NOT NULL + server-side derivation lands with fiscal-period resolution (S2-07).
 *
 * Money is NUMERIC(19,4) (never float); this is a strict tenant table (company_id NOT NULL) and gets the
 * uniform RLS boundary. The enums are created idempotently so migrate:fresh is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createEnums();

        DB::statement(<<<'SQL'
            CREATE TABLE journal_entries (
                id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                company_id         BIGINT NOT NULL REFERENCES companies(id),
                fiscal_year_id     BIGINT NULL REFERENCES fiscal_years(id),
                journal_number     VARCHAR(30) NOT NULL,
                journal_date       DATE NOT NULL,
                posting_date       TIMESTAMPTZ NULL,
                entry_type         journal_entry_type NOT NULL,
                source_module      VARCHAR(30) NULL,
                source_type        VARCHAR(60) NULL,
                source_id          BIGINT NULL,
                reference          VARCHAR(100) NULL,
                memo               TEXT NULL,
                currency_code      CHAR(3) NOT NULL,
                exchange_rate      NUMERIC(18,6) NOT NULL DEFAULT 1,
                total_debit        NUMERIC(19,4) NOT NULL DEFAULT 0,
                total_credit       NUMERIC(19,4) NOT NULL DEFAULT 0,
                base_total_debit   NUMERIC(19,4) NOT NULL DEFAULT 0,
                base_total_credit  NUMERIC(19,4) NOT NULL DEFAULT 0,
                status             journal_entry_status NOT NULL DEFAULT 'draft',
                is_recurring       BOOLEAN NOT NULL DEFAULT false,
                is_reversal        BOOLEAN NOT NULL DEFAULT false,
                reversed_entry_id  BIGINT NULL REFERENCES journal_entries(id),
                reversal_entry_id  BIGINT NULL REFERENCES journal_entries(id),
                ai_generated       BOOLEAN NOT NULL DEFAULT false,
                ai_confidence      NUMERIC(5,4) NULL,
                approved_by        BIGINT NULL REFERENCES users(id),
                approved_at        TIMESTAMPTZ NULL,
                posted_by          BIGINT NULL REFERENCES users(id),
                posted_at          TIMESTAMPTZ NULL,
                locked             BOOLEAN NOT NULL DEFAULT false,
                version            INTEGER NOT NULL DEFAULT 1,
                created_by         BIGINT NULL REFERENCES users(id),
                updated_by         BIGINT NULL REFERENCES users(id),
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at         TIMESTAMPTZ NULL,
                CONSTRAINT chk_je_balanced        CHECK (total_debit = total_credit),
                CONSTRAINT chk_je_base_balanced   CHECK (base_total_debit = base_total_credit),
                CONSTRAINT chk_je_totals_nonneg   CHECK (
                    total_debit >= 0 AND total_credit >= 0
                    AND base_total_debit >= 0 AND base_total_credit >= 0
                ),
                CONSTRAINT chk_je_rate_positive   CHECK (exchange_rate > 0),
                CONSTRAINT chk_je_version_min     CHECK (version >= 1),
                CONSTRAINT chk_je_no_self_reverse CHECK (
                    (reversed_entry_id IS NULL OR reversed_entry_id <> id)
                    AND (reversal_entry_id IS NULL OR reversal_entry_id <> id)
                ),
                CONSTRAINT chk_je_ai_confidence   CHECK (
                    ai_generated = false
                    OR (ai_confidence IS NOT NULL AND ai_confidence BETWEEN 0 AND 1)
                ),
                CONSTRAINT uq_je_number           UNIQUE (company_id, journal_number)
            )
            SQL);

        DB::statement('CREATE INDEX idx_je_company_date ON journal_entries (company_id, journal_date)');
        DB::statement('CREATE INDEX idx_je_company_status ON journal_entries (company_id, status)');
        DB::statement('CREATE INDEX idx_je_source ON journal_entries (source_type, source_id)');
        DB::statement('CREATE INDEX idx_je_not_deleted ON journal_entries (company_id) WHERE deleted_at IS NULL');

        $this->createNoAiAutopostTrigger();
        $this->applyRowLevelSecurity();
    }

    public function down(): void
    {
        // DROP TABLE removes the RLS policies, indexes, the INSERT trigger, and the CHECK/FK constraints
        // with it; then the (now table-independent) trigger function and enum types can be dropped.
        DB::statement('DROP TABLE IF EXISTS journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS fn_no_ai_autopost()');
        DB::statement('DROP TYPE IF EXISTS journal_entry_status');
        DB::statement('DROP TYPE IF EXISTS journal_entry_type');
    }

    /** The lifecycle-status and entry-type enums (docs/accounting/JOURNAL_ENTRIES.md). Idempotent. */
    private function createEnums(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'journal_entry_status') THEN
                    CREATE TYPE journal_entry_status AS ENUM (
                        'draft', 'pending_approval', 'approved', 'rejected',
                        'posted', 'reversed', 'voided', 'archived'
                    );
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'journal_entry_type') THEN
                    CREATE TYPE journal_entry_type AS ENUM (
                        'manual', 'invoice', 'bill', 'payment', 'receipt', 'inventory', 'payroll',
                        'depreciation', 'loan', 'asset', 'tax', 'revaluation', 'year_closing',
                        'opening_balance', 'ai_generated', 'reversal', 'adjustment'
                    );
                END IF;
            END
            $$;
            SQL);
    }

    /**
     * The AI-safety guard (SPRINT_02 §S2-03 `trg_no_ai_autopost`): an AI-generated entry can only ever be
     * CREATED as a draft — never inserted directly as posted/approved/etc. A human must review and post it
     * through the posting engine later. Enforced on INSERT, so the FastAPI layer cannot create-and-post in
     * one step regardless of any application-layer check.
     */
    private function createNoAiAutopostTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_no_ai_autopost() RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.ai_generated AND NEW.status <> 'draft' THEN
                    RAISE EXCEPTION 'journal_entries: an AI-generated entry may only be created as a draft (got status=%)', NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_no_ai_autopost
                BEFORE INSERT ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION fn_no_ai_autopost();
            SQL);
    }

    /** The uniform strict-tenant boundary (docs/database/ROW_LEVEL_SECURITY.md), as on every tenant table. */
    private function applyRowLevelSecurity(): void
    {
        DB::statement('ALTER TABLE journal_entries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE journal_entries FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY journal_entries_company_boundary ON journal_entries
            AS RESTRICTIVE FOR ALL
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_entries_tenant_select ON journal_entries
            FOR SELECT USING (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_entries_tenant_insert ON journal_entries
            FOR INSERT WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_entries_tenant_update ON journal_entries
            FOR UPDATE
            USING (company_id = app_current_company_id())
            WITH CHECK (company_id = app_current_company_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY journal_entries_tenant_delete ON journal_entries
            FOR DELETE USING (company_id = app_current_company_id())
            SQL);
    }
};
