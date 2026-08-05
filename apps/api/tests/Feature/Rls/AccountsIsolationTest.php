<?php

declare(strict_types=1);

use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S2-01 — two-tenant isolation for the accounts table, proven at the storage engine (raw SQL on the
 * RLS-enforced app role), the same shape as the S1-06 company_users isolation suite.
 */
uses()->group('rls', 'isolation');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

function isoAssetTypeId(): int
{
    return (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');
}

it('shows only the active company\'s accounts and never another tenant\'s', function (): void {
    $typeId = isoAssetTypeId();
    $a = TenantHarness::seedCompany('Acc Co A');
    $b = TenantHarness::seedCompany('Acc Co B');
    $owner = TenantHarness::owner();

    $aAccount = (int) $owner->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, 'A1', 'A Cash', 'نقد', 'debit') RETURNING id",
        [$a['company_id'], $typeId],
    )->id;
    $bAccount = (int) $owner->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, 'B1', 'B Cash', 'نقد', 'debit') RETURNING id",
        [$b['company_id'], $typeId],
    )->id;

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $a['company_id']]);

    $rows = $app->select('SELECT id, company_id FROM accounts');
    $visibleCompanyIds = array_values(array_unique(array_map(static fn ($r): int => (int) $r->company_id, $rows)));
    $visibleIds = array_map(static fn ($r): int => (int) $r->id, $rows);

    $app->rollBack();

    expect($visibleCompanyIds)->toBe([$a['company_id']]);
    expect($visibleIds)->toContain($aAccount);
    expect($visibleIds)->not->toContain($bAccount);
});

it('rejects a cross-tenant account insert via the WITH CHECK policy', function (): void {
    $typeId = isoAssetTypeId();
    $a = TenantHarness::seedCompany('Ins A');
    $b = TenantHarness::seedCompany('Ins B');

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', ['app.current_company_id', (string) $a['company_id']]);

    $threw = false;
    try {
        // Scoped to A, attempt to plant an account into B: the WITH CHECK / RESTRICTIVE boundary rejects it.
        $app->insert(
            "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
             VALUES (?, ?, 'X1', 'x', 'x', 'debit')",
            [$b['company_id'], $typeId],
        );
    } catch (Throwable) {
        $threw = true;
    } finally {
        $app->rollBack();
    }

    expect($threw)->toBeTrue();
});

it('returns zero accounts when no tenant context is set (fail-closed)', function (): void {
    $typeId = isoAssetTypeId();
    $a = TenantHarness::seedCompany('FailClosed Acc');
    TenantHarness::owner()->insert(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, 'FC1', 'x', 'x', 'debit')",
        [$a['company_id'], $typeId],
    );

    $count = TenantHarness::app()->selectOne('SELECT count(*) AS c FROM accounts');

    expect((int) $count->c)->toBe(0);
});
