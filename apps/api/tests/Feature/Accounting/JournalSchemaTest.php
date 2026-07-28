<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Tests\Support\TenantHarness;

/**
 * S2-03 — the journal schema: tables, enums, money precision, CHECK/UNIQUE/FK constraints, the guard
 * triggers, and RLS. Asserted against real PostgreSQL via the two-connection harness.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('creates the journal_entries and journal_lines tables', function (): void {
    $schema = Schema::connection(TenantHarness::OWNER);
    expect($schema->hasTable('journal_entries'))->toBeTrue();
    expect($schema->hasTable('journal_lines'))->toBeTrue();
});

it('creates the lifecycle-status and entry-type enums with the specified labels', function (): void {
    $statusLabels = array_map(
        static fn ($r): string => (string) $r->enumlabel,
        TenantHarness::owner()->select(
            "SELECT e.enumlabel FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
             WHERE t.typname = 'journal_entry_status' ORDER BY e.enumsortorder"
        ),
    );
    expect($statusLabels)->toBe([
        'draft', 'pending_approval', 'approved', 'rejected', 'posted', 'reversed', 'voided', 'archived',
    ]);

    $typeCount = TenantHarness::owner()->selectOne(
        "SELECT count(*) AS c FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = 'journal_entry_type'"
    );
    expect((int) $typeCount->c)->toBe(17);
});

it('gives journal_entries the tenant shape with money as NUMERIC(19,4)', function (): void {
    $schema = Schema::connection(TenantHarness::OWNER);
    expect($schema->hasColumns('journal_entries', [
        'id', 'company_id', 'fiscal_year_id', 'journal_number', 'journal_date', 'entry_type', 'status',
        'currency_code', 'total_debit', 'total_credit', 'base_total_debit', 'base_total_credit',
        'reversed_entry_id', 'reversal_entry_id', 'ai_generated', 'ai_confidence', 'locked', 'version', 'deleted_at',
    ]))->toBeTrue();

    $companyId = TenantHarness::owner()->selectOne(
        "SELECT is_nullable FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'journal_entries' AND column_name = 'company_id'"
    );
    expect($companyId->is_nullable)->toBe('NO');

    $total = TenantHarness::owner()->selectOne(
        "SELECT numeric_precision, numeric_scale FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'journal_entries' AND column_name = 'total_debit'"
    );
    expect((int) $total->numeric_precision)->toBe(19);
    expect((int) $total->numeric_scale)->toBe(4);
});

it('gives journal_lines money columns as NUMERIC(19,4) and a NOT NULL company_id', function (): void {
    $companyId = TenantHarness::owner()->selectOne(
        "SELECT is_nullable FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'journal_lines' AND column_name = 'company_id'"
    );
    expect($companyId->is_nullable)->toBe('NO');

    foreach (['debit', 'credit', 'base_debit', 'base_credit'] as $col) {
        $c = TenantHarness::owner()->selectOne(
            "SELECT numeric_precision, numeric_scale FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'journal_lines' AND column_name = ?",
            [$col],
        );
        expect((int) $c->numeric_precision)->toBe(19);
        expect((int) $c->numeric_scale)->toBe(4);
    }
});

it('defers columns whose FK targets do not exist yet (documented S2-03 scope)', function (): void {
    $schema = Schema::connection(TenantHarness::OWNER);

    foreach (['branch_id', 'fiscal_period_id', 'recurring_template_id', 'ai_conversation_id'] as $col) {
        expect($schema->hasColumn('journal_entries', $col))->toBeFalse("journal_entries.{$col} should be deferred");
    }
    foreach (['branch_id', 'cost_center_id', 'project_id', 'department_id', 'customer_id', 'vendor_id', 'tax_code_id', 'tax_rate_id'] as $col) {
        expect($schema->hasColumn('journal_lines', $col))->toBeFalse("journal_lines.{$col} should be deferred");
    }
});

it('carries the journal_entries CHECK + UNIQUE constraints', function (): void {
    $names = array_map(
        static fn ($r): string => (string) $r->conname,
        TenantHarness::owner()->select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'public.journal_entries'::regclass AND contype = 'c'"
        ),
    );
    foreach ([
        'chk_je_balanced', 'chk_je_base_balanced', 'chk_je_totals_nonneg',
        'chk_je_rate_positive', 'chk_je_ai_confidence', 'chk_je_no_self_reverse', 'chk_je_version_min',
    ] as $c) {
        expect($names)->toContain($c);
    }

    $uniques = array_map(
        static fn ($r): string => (string) $r->conname,
        TenantHarness::owner()->select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'public.journal_entries'::regclass AND contype = 'u'"
        ),
    );
    expect($uniques)->toContain('uq_je_number');
});

it('carries the journal_lines CHECK + UNIQUE + cascading FK', function (): void {
    $rows = TenantHarness::owner()->select(
        "SELECT conname, contype FROM pg_constraint WHERE conrelid = 'public.journal_lines'::regclass"
    );
    $byType = [];
    foreach ($rows as $r) {
        $byType[(string) $r->contype][] = (string) $r->conname;
    }

    foreach (['chk_jl_one_sided', 'chk_jl_base_nonneg', 'chk_jl_rate_positive', 'chk_jl_line_number_positive'] as $c) {
        expect($byType['c'] ?? [])->toContain($c);
    }
    expect($byType['u'] ?? [])->toContain('uq_jl_line_number');

    $fk = TenantHarness::owner()->selectOne(
        "SELECT confdeltype FROM pg_constraint
         WHERE conrelid = 'public.journal_lines'::regclass AND contype = 'f' AND conname LIKE '%journal_entry_id%'"
    );
    expect($fk?->confdeltype)->toBe('c'); // 'c' = ON DELETE CASCADE
});

it('enables and forces RLS with per-verb policies on both journal tables', function (): void {
    foreach (['journal_entries', 'journal_lines'] as $table) {
        $rls = TenantHarness::owner()->selectOne(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass',
            ['public.'.$table],
        );
        expect((bool) $rls->relrowsecurity)->toBeTrue("{$table}: RLS enabled");
        expect((bool) $rls->relforcerowsecurity)->toBeTrue("{$table}: RLS forced");

        $policies = TenantHarness::owner()->select(
            "SELECT policyname FROM pg_policies WHERE schemaname = 'public' AND tablename = ?",
            [$table],
        );
        expect(count($policies))->toBeGreaterThanOrEqual(5);
    }
});

it('registers the two guard triggers', function (): void {
    $names = array_map(
        static fn ($r): string => (string) $r->tgname,
        TenantHarness::owner()->select(
            "SELECT tgname FROM pg_trigger
             WHERE NOT tgisinternal
               AND tgrelid IN ('public.journal_entries'::regclass, 'public.journal_lines'::regclass)"
        ),
    );
    expect($names)->toContain('trg_no_ai_autopost');
    expect($names)->toContain('trg_journal_lines_no_update_when_posted');
});
