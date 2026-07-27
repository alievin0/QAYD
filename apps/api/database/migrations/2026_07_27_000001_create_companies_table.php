<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1-04 — `companies`: the tenant root (docs/database/MULTI_TENANCY.md "The `companies` table").
 *
 * companies is NOT itself tenant-scoped — it carries no `company_id`; it IS the tenant every other
 * tenant-owned table discriminates on. Internal joins/FKs use the cheap BIGINT `id`; anything
 * exposed to a client uses the `uuid` so sequential ids never leak (tenant-count enumeration).
 * `created_by`/`updated_by` are BIGINT NULL with NO FK to users, exactly as the spec DDL declares
 * (a company can be seeded before/independently of a user row; avoids a circular dependency).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE companies (
                id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                legal_name              VARCHAR(255) NOT NULL,
                trade_name              VARCHAR(255),
                name_en                 VARCHAR(255) NOT NULL,
                name_ar                 VARCHAR(255),
                tax_registration_no     VARCHAR(64),
                commercial_reg_no       VARCHAR(64),
                base_currency           CHAR(3) NOT NULL DEFAULT 'KWD',
                fiscal_year_start_month SMALLINT NOT NULL DEFAULT 1
                                          CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
                region                  VARCHAR(32) NOT NULL DEFAULT 'me-central-1',
                timezone                VARCHAR(64) NOT NULL DEFAULT 'Asia/Kuwait',
                locale_default          VARCHAR(8) NOT NULL DEFAULT 'ar',
                plan                    VARCHAR(32) NOT NULL DEFAULT 'trial',
                status                  VARCHAR(16) NOT NULL DEFAULT 'active'
                                          CHECK (status IN ('active', 'suspended', 'trial', 'archived', 'closed')),
                max_users               INTEGER NOT NULL DEFAULT 5,
                max_branches            INTEGER NOT NULL DEFAULT 1,
                settings                JSONB NOT NULL DEFAULT '{}'::jsonb,
                trial_ends_at           TIMESTAMPTZ,
                suspended_at            TIMESTAMPTZ,
                suspended_reason        TEXT,
                created_by              BIGINT NULL,
                updated_by              BIGINT NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at              TIMESTAMPTZ NULL
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX companies_uuid_uk ON companies (uuid)');
        DB::statement('CREATE INDEX companies_status_idx ON companies (status) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX companies_region_idx ON companies (region) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS companies');
    }
};
