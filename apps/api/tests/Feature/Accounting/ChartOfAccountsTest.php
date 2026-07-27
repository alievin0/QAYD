<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateAccountAction;
use App\Actions\Accounting\DeactivateAccountAction;
use App\Actions\Accounting\ReclassifyAccountAction;
use App\Actions\Accounting\UpdateAccountAction;
use App\Data\Accounting\CreateAccountData;
use App\Data\Accounting\ReclassifyAccountData;
use App\Data\Accounting\UpdateAccountData;
use App\Domain\Accounting\PostedActivityGuard;
use App\Exceptions\Accounting\AccountRuleException;
use App\Models\Account;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TenantHarness;

/**
 * S2-01 — chart of accounts. Real PostgreSQL (RLS, the account_types catalogue, the accounts tree),
 * so it boots the two-connection harness and seeds the seven system account types the actions read.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

function coaTypeId(string $key): int
{
    return (int) TenantHarness::owner()->table('account_types')->where('key', $key)->value('id');
}

// ---------------------------------------------------------------- schema

it('creates the account_types and accounts tables', function (): void {
    $schema = Schema::connection(TenantHarness::OWNER);
    expect($schema->hasTable('account_types'))->toBeTrue();
    expect($schema->hasTable('accounts'))->toBeTrue();
});

it('models account_types as a global catalogue with no company_id', function (): void {
    $schema = Schema::connection(TenantHarness::OWNER);
    expect($schema->hasColumns('account_types', [
        'id', 'key', 'name_en', 'name_ar', 'normal_balance', 'is_balance_sheet', 'sort_order',
    ]))->toBeTrue();
    expect($schema->hasColumn('account_types', 'company_id'))->toBeFalse();
});

it('gives accounts the tenant shape with a NOT NULL company_id', function (): void {
    $schema = Schema::connection(TenantHarness::OWNER);
    expect($schema->hasColumns('accounts', [
        'id', 'company_id', 'account_type_id', 'parent_id', 'code', 'name_en', 'name_ar',
        'normal_balance', 'status', 'is_control_account', 'control_account_of', 'deleted_at',
    ]))->toBeTrue();

    $nullable = TenantHarness::owner()->selectOne(
        "SELECT is_nullable FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'accounts' AND column_name = 'company_id'"
    );
    assert($nullable instanceof stdClass);
    expect($nullable->is_nullable)->toBe('NO');
});

it('enables and forces row-level security on accounts', function (): void {
    $rls = TenantHarness::owner()->selectOne(
        "SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'public.accounts'::regclass"
    );
    assert($rls instanceof stdClass);
    expect((bool) $rls->relrowsecurity)->toBeTrue();
    expect((bool) $rls->relforcerowsecurity)->toBeTrue();

    $policies = TenantHarness::owner()->select(
        "SELECT policyname FROM pg_policies WHERE schemaname = 'public' AND tablename = 'accounts'"
    );
    expect(count($policies))->toBeGreaterThanOrEqual(5);
});

// ---------------------------------------------------------------- seed

it('seeds exactly the seven system account types in order', function (): void {
    $types = TenantHarness::owner()->table('account_types')->orderBy('sort_order')->get();

    expect($types)->toHaveCount(7);
    expect($types->pluck('key')->all())->toBe([
        'asset', 'liability', 'equity', 'revenue', 'expense', 'other_income', 'other_expense',
    ]);

    $asset = $types->firstWhere('key', 'asset');
    expect($asset->normal_balance)->toBe('debit');
    expect((bool) $asset->is_balance_sheet)->toBeTrue();

    $revenue = $types->firstWhere('key', 'revenue');
    expect($revenue->normal_balance)->toBe('credit');
    expect((bool) $revenue->is_balance_sheet)->toBeFalse();
});

it('seeds account types idempotently', function (): void {
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
    expect((int) TenantHarness::owner()->table('account_types')->count())->toBe(7);
});

// ---------------------------------------------------------------- create

it('creates a leaf account scoped to the active company with the type normal balance', function (): void {
    $co = TenantHarness::seedCompany('COA Co');

    $account = TenantHarness::runInTenant($co['company_id'], fn (): Account => app(CreateAccountAction::class)->execute(
        new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '1000', nameEn: 'Cash', nameAr: 'النقد'),
    ));

    expect($account->company_id)->toBe($co['company_id']);
    expect($account->code)->toBe('1000');
    expect($account->normal_balance)->toBe('debit');
    expect($account->status)->toBe('active');
});

it('nests a child account under a parent in the same company', function (): void {
    $co = TenantHarness::seedCompany('Tree Co');

    [$parentId, $child] = TenantHarness::runInTenant($co['company_id'], function (): array {
        $create = app(CreateAccountAction::class);
        $parent = $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '1', nameEn: 'Assets', nameAr: 'الأصول'));
        $child = $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '1100', nameEn: 'Bank', nameAr: 'البنك', parentId: $parent->id));

        return [$parent->id, $child];
    });

    expect($child->parent_id)->toBe($parentId);
});

it('refuses a duplicate account code within a company (422 DUPLICATE_ACCOUNT_CODE)', function (): void {
    $co = TenantHarness::seedCompany('Dup Co');

    TenantHarness::runInTenant($co['company_id'], function (): void {
        $create = app(CreateAccountAction::class);
        $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '1000', nameEn: 'Cash', nameAr: 'نقد'));

        try {
            $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '1000', nameEn: 'Cash 2', nameAr: 'نقد ٢'));
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('DUPLICATE_ACCOUNT_CODE');
            expect($e->errorStatus())->toBe(422);
        }

        expect($threw)->toBeTrue();
    });
});

it('keeps the tenant transaction alive after a caught duplicate (savepoint) and never leaks a raw DB error', function (): void {
    $co = TenantHarness::seedCompany('Savepoint Co');

    TenantHarness::runInTenant($co['company_id'], function (): void {
        $create = app(CreateAccountAction::class);
        $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '7000', nameEn: 'First', nameAr: 'أول'));

        // A second insert of the same code is caught at the uq_accounts_company_code constraint inside
        // the action's savepoint and converted to the domain exception — never a raw QueryException.
        try {
            $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '7000', nameEn: 'Second', nameAr: 'ثاني'));
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('DUPLICATE_ACCOUNT_CODE');
        }
        expect($threw)->toBeTrue();

        // The surrounding transaction survived the caught violation: a different code still commits.
        $survivor = $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '7001', nameEn: 'Third', nameAr: 'ثالث'));
        expect($survivor->code)->toBe('7001');
    });
});

it('refuses an unknown account type (422 ACCOUNT_TYPE_NOT_FOUND)', function (): void {
    $co = TenantHarness::seedCompany('Type Co');

    TenantHarness::runInTenant($co['company_id'], function (): void {
        try {
            app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: 999999, code: '1', nameEn: 'X', nameAr: 'اكس'));
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('ACCOUNT_TYPE_NOT_FOUND');
        }

        expect($threw)->toBeTrue();
    });
});

it('refuses a parent that belongs to another company (422 INVALID_ACCOUNT_PARENT)', function (): void {
    $a = TenantHarness::seedCompany('Parent A');
    $b = TenantHarness::seedCompany('Child B');

    // A real, persisted account in company A (inserted on the owner connection, which bypasses RLS).
    $foreignParentId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, '9', 'A Root', 'جذر', 'debit') RETURNING id",
        [$a['company_id'], coaTypeId('asset')],
    )->id;

    TenantHarness::runInTenant($b['company_id'], function () use ($foreignParentId): void {
        try {
            app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '1', nameEn: 'B', nameAr: 'ب', parentId: $foreignParentId));
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('INVALID_ACCOUNT_PARENT');
        }

        expect($threw)->toBeTrue();
    });
});

// ---------------------------------------------------------------- reclassify

it('reclassifies an account when it carries no postings, re-deriving the normal balance', function (): void {
    $co = TenantHarness::seedCompany('Reclass Co');

    $result = TenantHarness::runInTenant($co['company_id'], function (): Account {
        $account = app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '2000', nameEn: 'Misc', nameAr: 'متنوع'));

        return app(ReclassifyAccountAction::class)->execute($account, new ReclassifyAccountData(accountTypeId: coaTypeId('liability')));
    });

    expect($result->account_type_id)->toBe(coaTypeId('liability'));
    expect($result->normal_balance)->toBe('credit');
});

it('refuses to reclassify an account that carries posted lines (422 ACCOUNT_HAS_POSTINGS)', function (): void {
    $co = TenantHarness::seedCompany('Posted Co');

    // Stand in the ledger-backed guard S2-05 will provide: this account reports posted activity.
    app()->instance(PostedActivityGuard::class, new class implements PostedActivityGuard
    {
        public function hasPostedLines(Account $account): bool
        {
            return true;
        }
    });

    TenantHarness::runInTenant($co['company_id'], function (): void {
        $account = app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '3000', nameEn: 'Used', nameAr: 'مستخدم'));

        try {
            app(ReclassifyAccountAction::class)->execute($account, new ReclassifyAccountData(accountTypeId: coaTypeId('liability')));
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('ACCOUNT_HAS_POSTINGS');
        }

        expect($threw)->toBeTrue();
    });
});

// ---------------------------------------------------------------- update

it('renames an account freely', function (): void {
    $co = TenantHarness::seedCompany('Rename Co');

    $renamed = TenantHarness::runInTenant($co['company_id'], function (): Account {
        $account = app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '6000', nameEn: 'Old', nameAr: 'قديم'));

        return app(UpdateAccountAction::class)->execute($account, new UpdateAccountData(nameEn: 'New Name', nameAr: 'اسم جديد'));
    });

    expect($renamed->name_en)->toBe('New Name');
    expect($renamed->name_ar)->toBe('اسم جديد');
});

it('refuses to renumber an account that carries posted lines (422 ACCOUNT_HAS_POSTINGS)', function (): void {
    $co = TenantHarness::seedCompany('Renumber Co');

    app()->instance(PostedActivityGuard::class, new class implements PostedActivityGuard
    {
        public function hasPostedLines(Account $account): bool
        {
            return true;
        }
    });

    TenantHarness::runInTenant($co['company_id'], function (): void {
        $account = app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '6100', nameEn: 'X', nameAr: 'اكس'));

        try {
            app(UpdateAccountAction::class)->execute($account, new UpdateAccountData(code: '6199'));
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('ACCOUNT_HAS_POSTINGS');
        }

        expect($threw)->toBeTrue();
    });
});

// ---------------------------------------------------------------- deactivate

it('deactivates a leaf account', function (): void {
    $co = TenantHarness::seedCompany('Deact Co');

    $result = TenantHarness::runInTenant($co['company_id'], function (): Account {
        $account = app(CreateAccountAction::class)->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '4000', nameEn: 'Leaf', nameAr: 'ورقة'));

        return app(DeactivateAccountAction::class)->execute($account);
    });

    expect($result->status)->toBe('inactive');
});

it('refuses to deactivate an account with active children (422 ACCOUNT_HAS_ACTIVE_CHILDREN)', function (): void {
    $co = TenantHarness::seedCompany('Parent Deact Co');

    TenantHarness::runInTenant($co['company_id'], function (): void {
        $create = app(CreateAccountAction::class);
        $parent = $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '5', nameEn: 'Parent', nameAr: 'أب'));
        $create->execute(new CreateAccountData(accountTypeId: coaTypeId('asset'), code: '5100', nameEn: 'Child', nameAr: 'ابن', parentId: $parent->id));

        try {
            app(DeactivateAccountAction::class)->execute($parent);
            $threw = false;
        } catch (AccountRuleException $e) {
            $threw = true;
            expect($e->errorCode())->toBe('ACCOUNT_HAS_ACTIVE_CHILDREN');
        }

        expect($threw)->toBeTrue();
    });
});
