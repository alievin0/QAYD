<?php

declare(strict_types=1);

use Database\Seeders\AccountTypeSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S2-01 hardening — the global reference catalogues (account_types, permissions) must be immutable to
 * the runtime application role. They carry no RLS (shared read-only across tenants), so the guarantee
 * is a grant-level REVOKE: the `qayd_app` role may SELECT them but never INSERT/UPDATE/DELETE.
 */
uses()->group('accounting', 'rls');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
});

it('forbids the app role from mutating account_types (INSERT / UPDATE / DELETE)', function (): void {
    $app = TenantHarness::app();

    foreach ([
        "INSERT INTO account_types (key, name_en, name_ar, normal_balance, is_balance_sheet) VALUES ('hack', 'H', 'ه', 'debit', true)",
        "UPDATE account_types SET name_en = 'tampered' WHERE key = 'asset'",
        "DELETE FROM account_types WHERE key = 'asset'",
    ] as $sql) {
        $threw = false;
        try {
            $app->statement($sql);
        } catch (Throwable) {
            $threw = true;
        }
        expect($threw)->toBeTrue("the app role must be denied: {$sql}");
    }
});

it('forbids the app role from mutating the permissions catalogue', function (): void {
    $threw = false;
    try {
        TenantHarness::app()->statement("INSERT INTO permissions (key, area) VALUES ('hack.exploit', 'hack')");
    } catch (Throwable) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('still lets the app role READ both catalogues normally', function (): void {
    $accountTypes = TenantHarness::app()->selectOne('SELECT count(*) AS c FROM account_types');
    $permissions = TenantHarness::app()->selectOne('SELECT count(*) AS c FROM permissions');

    expect((int) $accountTypes->c)->toBe(7);
    expect((int) $permissions->c)->toBeGreaterThan(0);
});
