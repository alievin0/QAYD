<?php

declare(strict_types=1);

use App\Support\TenantContext;
use Tests\Support\TenantHarness;

uses()->group('rls', 'isolation');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('runs the app connection as a non-superuser, non-BYPASSRLS role', function (): void {
    // The lynchpin of the whole story: RLS is silently bypassed for a superuser or a BYPASSRLS role,
    // so every isolation assertion below is only meaningful because the app connects as neither. If
    // the app connection were ever the superuser, this test fails and the suite refuses to go green.
    $who = TenantHarness::app()->selectOne(
        'SELECT current_user AS role,
                (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) AS is_super,
                (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS is_bypass'
    );

    expect($who->is_super)->toBeFalse('the app role must NOT be a superuser or RLS is bypassed');
    expect($who->is_bypass)->toBeFalse('the app role must NOT have BYPASSRLS or RLS is bypassed');
    expect($who->role)->not->toBe('qayd');
});

it('deploys the RLS GUC under the fixed name app.current_company_id', function (): void {
    // The GUC name is fixed once for the codebase (SPRINT_01 §S1-05 Risks). Assert the constant AND
    // that the *deployed* helper function reads exactly that GUC name — set it, read it back.
    expect(TenantContext::GUC_COMPANY_ID)->toBe('app.current_company_id');

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', ['app.current_company_id', '424242']);
    $readBack = $app->selectOne('SELECT app_current_company_id() AS id');
    $app->rollBack();

    expect((int) $readBack->id)->toBe(424242);

    // The company_users policies are keyed on that helper (and therefore that GUC name).
    $policyQuals = TenantHarness::owner()->select(
        "SELECT qual, with_check FROM pg_policies WHERE schemaname = 'public' AND tablename = 'company_users'"
    );
    $blob = json_encode($policyQuals);
    expect($blob)->toContain('app_current_company_id');
});

it('returns ZERO rows from a tenant table when no company GUC is set (fail-closed)', function (): void {
    // Seed real rows so the table is genuinely non-empty...
    TenantHarness::seedCompany('Fail-Closed Co');

    // ...then read it on the RLS-enforced connection with NO tenant context. The policy predicate
    // `company_id = app_current_company_id()` is `company_id = NULL` ⇒ UNKNOWN ⇒ no row. The single
    // most important assertion in the sprint: with no GUC set, a tenant table yields zero rows.
    $count = TenantHarness::app()->selectOne('SELECT count(*) AS c FROM company_users');

    expect((int) $count->c)->toBe(0);
});

it('shows only the active company rows and never another tenant\'s', function (): void {
    $a = TenantHarness::seedCompany('Company A');
    $b = TenantHarness::seedCompany('Company B');

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $a['company_id']]);

    $rows = $app->select('SELECT id, company_id FROM company_users');
    $visibleCompanyIds = array_values(array_unique(array_map(static fn ($r): int => (int) $r->company_id, $rows)));
    $visibleMembershipIds = array_map(static fn ($r): int => (int) $r->id, $rows);

    $app->rollBack();

    expect($visibleCompanyIds)->toBe([$a['company_id']]);
    expect($visibleMembershipIds)->toContain($a['membership_id']);
    expect($visibleMembershipIds)->not->toContain($b['membership_id']);
});

it('rejects a cross-tenant write via the WITH CHECK policy', function (): void {
    $a = TenantHarness::seedCompany('Writer A');
    $b = TenantHarness::seedCompany('Victim B');

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $a['company_id']]);

    $threw = false;
    try {
        // Scoped to A, attempt to plant a row into B: WITH CHECK / RESTRICTIVE boundary rejects it.
        $app->insert(
            "INSERT INTO company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active')",
            [$b['company_id'], $b['user_id'], $b['role_id']],
        );
    } catch (Throwable $e) {
        $threw = true;
    } finally {
        $app->rollBack();
    }

    expect($threw)->toBeTrue('cross-tenant INSERT must be rejected by the RLS WITH CHECK policy');
});
