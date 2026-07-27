<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S2-01 — `account_types`: the seven system classifications every account is one of
 * (docs/accounting/CHART_OF_ACCOUNTS.md). A GLOBAL reference catalogue, deliberately NOT
 * company-scoped — exactly like `permissions` (MULTI_TENANCY.md lists the fixed catalogues among the
 * non-tenant tables). It carries no `company_id` and gets no RLS: every tenant reads the same seven
 * rows and no tenant may add or mutate them (the seeder — run by the owner role — is the only writer).
 *
 * Each type fixes the two facts the ledger needs about any account of that type: which side is its
 * `normal_balance` (debit/credit) and whether it lives on the balance sheet (`is_balance_sheet`) or the
 * income statement. The rows themselves are planted idempotently by AccountTypeSeeder (S2-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE account_types (
                id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key              VARCHAR(32) NOT NULL,
                name_en          VARCHAR(80) NOT NULL,
                name_ar          VARCHAR(80) NOT NULL,
                normal_balance   VARCHAR(6) NOT NULL CHECK (normal_balance IN ('debit', 'credit')),
                is_balance_sheet BOOLEAN NOT NULL,
                sort_order       SMALLINT NOT NULL DEFAULT 0,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_account_types_key UNIQUE (key)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS account_types');
    }
};
