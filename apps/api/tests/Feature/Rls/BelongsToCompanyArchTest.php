<?php

declare(strict_types=1);

use App\Models\CompanyUser;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\TenantHarness;

uses()->group('rls', 'isolation');

beforeEach(function (): void {
    TenantHarness::boot();
});

/**
 * Every model whose table carries a NOT NULL `company_id` (a strict tenant-owned table) must use the
 * BelongsToCompany trait, so it is auto-scoped and bound to the RLS-enforced connection. Tables with
 * a NULLABLE `company_id` (the documented `roles` exception — system + company rows) are not strict
 * tenant models and are excluded.
 */
it('makes every strict tenant model use BelongsToCompany', function (): void {
    $files = glob(app_path('Models').'/*.php');
    expect($files)->not->toBeFalse();

    $checked = [];

    foreach ($files as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();

        $column = TenantHarness::owner()->selectOne(
            "SELECT is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ? AND column_name = 'company_id'",
            [$table],
        );

        $isStrictTenantTable = $column !== null && $column->is_nullable === 'NO';
        if (! $isStrictTenantTable) {
            continue;
        }

        $checked[] = $class;
        expect(in_array(BelongsToCompany::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} owns a NOT NULL company_id table ({$table}) but does not use BelongsToCompany");
    }

    // Guard against a vacuous pass: the known S1-05 tenant model must have been exercised.
    expect($checked)->toContain(CompanyUser::class);
});
