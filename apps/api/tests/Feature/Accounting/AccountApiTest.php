<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * S2-02 — the chart-of-accounts HTTP API. Exercised end to end through the real middleware stack
 * (auth → tenant → the `permission:` gate → route-model binding), so authorization, tenant isolation,
 * the standard envelope, and the controller→Action orchestration are all proven together.
 */
uses()->group('accounting', 'api');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/**
 * A user with an active membership in a fresh company whose role carries the given permissions.
 *
 * @param  list<string>  $permissions
 * @return array{user: User, uuid: string}
 */
function apiMember(array $permissions, string $name = 'COA'): array
{
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, $name.' '.uniqid(), 'coa_role');

    foreach ($permissions as $key) {
        RbacFixtures::attachToRole($m['role_id'], RbacFixtures::permission($key, 'accounting'));
    }

    return ['user' => $user, 'uuid' => $m['company_uuid']];
}

function apiType(string $key): int
{
    return (int) TenantHarness::owner()->table('account_types')->where('key', $key)->value('id');
}

// ---------------------------------------------------------------- auth + permission

it('requires authentication', function (): void {
    $this->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => 'anything'])->assertStatus(401);
});

it('denies reads without accounting.journal.read (403 INSUFFICIENT_PERMISSION)', function (): void {
    $m = apiMember([]);

    $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('denies writes without accounting.coa.manage (403), even for a reader', function (): void {
    $m = apiMember(['accounting.journal.read']);

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', [
            'account_type_id' => apiType('asset'), 'code' => '1000', 'name_en' => 'Cash', 'name_ar' => 'نقد',
        ], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

// ---------------------------------------------------------------- create + contract

it('creates an account and returns the standard envelope with a bilingual, typed payload', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', [
            'account_type_id' => apiType('asset'), 'code' => '1000', 'name_en' => 'Cash', 'name_ar' => 'النقد',
        ], ['X-Company-Id' => $m['uuid']])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'accounting.account.created')
        ->assertJsonPath('errors', [])
        ->assertJsonStructure([
            'success',
            'data' => ['account' => [
                'id', 'code', 'name_en', 'name_ar', 'parent_id', 'normal_balance', 'status',
                'is_control_account', 'control_account_of',
                'account_type' => ['id', 'key', 'name_en', 'name_ar', 'normal_balance', 'is_balance_sheet'],
            ]],
            'message', 'errors', 'meta', 'request_id', 'timestamp',
        ])
        ->assertJsonPath('data.account.code', '1000')
        ->assertJsonPath('data.account.name_ar', 'النقد')
        ->assertJsonPath('data.account.normal_balance', 'debit')
        ->assertJsonPath('data.account.account_type.key', 'asset');
});

it('validates the create body (422 VALIDATION_ERROR)', function (): void {
    $m = apiMember(['accounting.coa.manage']);

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', [
            'account_type_id' => apiType('asset'), 'code' => '1000', // missing name_en / name_ar
        ], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
});

it('surfaces a duplicate code as 422 DUPLICATE_ACCOUNT_CODE (the Action, via the controller)', function (): void {
    $m = apiMember(['accounting.coa.manage']);
    $body = ['account_type_id' => apiType('asset'), 'code' => '2000', 'name_en' => 'A', 'name_ar' => 'ا'];

    $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', $body, ['X-Company-Id' => $m['uuid']])->assertCreated();

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', $body, ['X-Company-Id' => $m['uuid']])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'DUPLICATE_ACCOUNT_CODE');
});

// ---------------------------------------------------------------- list + tree (deterministic)

it('lists accounts flat, deterministically ordered by code', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);
    $create = fn (array $b) => $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', $b, ['X-Company-Id' => $m['uuid']])->assertCreated();

    $create(['account_type_id' => apiType('asset'), 'code' => '3000', 'name_en' => 'C', 'name_ar' => 'ج']);
    $create(['account_type_id' => apiType('asset'), 'code' => '1000', 'name_en' => 'A', 'name_ar' => 'ا']);
    $create(['account_type_id' => apiType('asset'), 'code' => '2000', 'name_en' => 'B', 'name_ar' => 'ب']);

    $codes = $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->json('data.accounts.*.code');

    expect($codes)->toBe(['1000', '2000', '3000']);
});

it('returns the accounts nested as a tree', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);
    $create = fn (array $b) => $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', $b, ['X-Company-Id' => $m['uuid']])->assertCreated()->json('data.account.id');

    $parentId = $create(['account_type_id' => apiType('asset'), 'code' => '1', 'name_en' => 'Assets', 'name_ar' => 'الأصول']);
    $create(['account_type_id' => apiType('asset'), 'code' => '1100', 'name_en' => 'Bank', 'name_ar' => 'بنك', 'parent_id' => $parentId]);

    $tree = $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/accounts/tree', ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->json('data.accounts');

    expect($tree)->toHaveCount(1);
    expect($tree[0]['code'])->toBe('1');
    expect($tree[0]['children'])->toHaveCount(1);
    expect($tree[0]['children'][0]['code'])->toBe('1100');
});

// ---------------------------------------------------------------- route-model binding + isolation

it('returns 404 for a cross-tenant account id at route-model binding', function (): void {
    $a = apiMember(['accounting.journal.read', 'accounting.coa.manage'], 'A');
    $accId = $this->actingAs($a['user'], 'web')
        ->postJson('/api/v1/accounting/accounts', [
            'account_type_id' => apiType('asset'), 'code' => '9000', 'name_en' => 'A only', 'name_ar' => 'أ',
        ], ['X-Company-Id' => $a['uuid']])->assertCreated()->json('data.account.id');

    $b = apiMember(['accounting.journal.read'], 'B');
    $this->actingAs($b['user'], 'web')
        ->getJson("/api/v1/accounting/accounts/{$accId}", ['X-Company-Id' => $b['uuid']])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'RESOURCE_NOT_FOUND');
});

it('never shows one company\'s accounts in another\'s list', function (): void {
    $a = apiMember(['accounting.journal.read', 'accounting.coa.manage'], 'A');
    $this->actingAs($a['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => 'AA1', 'name_en' => 'A', 'name_ar' => 'ا',
    ], ['X-Company-Id' => $a['uuid']])->assertCreated();

    $b = apiMember(['accounting.journal.read'], 'B');
    $codes = $this->actingAs($b['user'], 'web')
        ->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $b['uuid']])
        ->assertOk()
        ->json('data.accounts.*.code');

    expect($codes)->not->toContain('AA1');
});

// ---------------------------------------------------------------- update / reclassify / deactivate

it('updates account names', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);
    $id = $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '4000', 'name_en' => 'Old', 'name_ar' => 'قديم',
    ], ['X-Company-Id' => $m['uuid']])->assertCreated()->json('data.account.id');

    $this->actingAs($m['user'], 'web')
        ->patchJson("/api/v1/accounting/accounts/{$id}", ['name_en' => 'New'], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.account.name_en', 'New');
});

it('reclassifies an account to a new type, re-deriving the normal balance', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);
    $id = $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '5000', 'name_en' => 'X', 'name_ar' => 'س',
    ], ['X-Company-Id' => $m['uuid']])->assertCreated()->json('data.account.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/accounts/{$id}/reclassify", ['account_type_id' => apiType('liability')], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.account.account_type.key', 'liability')
        ->assertJsonPath('data.account.normal_balance', 'credit');
});

it('deactivates an account', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);
    $id = $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '6000', 'name_en' => 'Leaf', 'name_ar' => 'ورقة',
    ], ['X-Company-Id' => $m['uuid']])->assertCreated()->json('data.account.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/accounts/{$id}/deactivate", [], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.account.status', 'inactive');
});

// ---------------------------------------------------------------- postability (S2-11 prerequisite)

it('carries allow_posting on every account payload', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);

    $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '1500', 'name_en' => 'Leaf', 'name_ar' => 'ورقة',
    ], ['X-Company-Id' => $m['uuid']])
        ->assertCreated()
        // A new account is postable until it becomes a parent.
        ->assertJsonPath('data.account.allow_posting', true);
});

it('stops a parent being postable the moment it gains a child', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);

    $parentId = $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '1600', 'name_en' => 'Header', 'name_ar' => 'رئيسي',
    ], ['X-Company-Id' => $m['uuid']])->assertCreated()->json('data.account.id');

    $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '1610', 'name_en' => 'Child', 'name_ar' => 'فرعي',
        'parent_id' => $parentId,
    ], ['X-Company-Id' => $m['uuid']])->assertCreated();

    // The database cleared the parent's flag; nothing in the application had to remember to.
    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/accounts/{$parentId}", ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.account.allow_posting', false);
});

it('lets the database refuse making a parent postable again', function (): void {
    $m = apiMember(['accounting.journal.read', 'accounting.coa.manage']);

    $parentId = $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '1700', 'name_en' => 'Header', 'name_ar' => 'رئيسي',
    ], ['X-Company-Id' => $m['uuid']])->assertCreated()->json('data.account.id');

    $this->actingAs($m['user'], 'web')->postJson('/api/v1/accounting/accounts', [
        'account_type_id' => apiType('asset'), 'code' => '1710', 'name_en' => 'Child', 'name_ar' => 'فرعي',
        'parent_id' => $parentId,
    ], ['X-Company-Id' => $m['uuid']])->assertCreated();

    expect(fn () => TenantHarness::owner()->statement(
        'UPDATE accounts SET allow_posting = true WHERE id = ?',
        [$parentId],
    ))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- account-type catalogue (S2-10)

it('lists the seven global account types in presentation order', function (): void {
    $m = apiMember(['accounting.journal.read']);

    $response = $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/account-types', ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(7, 'data.account_types');

    // The shape is exactly the one already embedded as account.account_type, so a client has one
    // notion of an account type rather than two that can drift.
    $response->assertJsonStructure([
        'data' => [
            'account_types' => [
                ['id', 'key', 'name_en', 'name_ar', 'normal_balance', 'is_balance_sheet'],
            ],
        ],
    ]);

    $keys = $response->json('data.account_types.*.key');
    expect($keys)->toContain('asset', 'liability', 'equity', 'revenue', 'expense');
});

it('denies the account-type catalogue without accounting.journal.read (403)', function (): void {
    $m = apiMember([]);

    $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/account-types', ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('requires authentication for the account-type catalogue', function (): void {
    $this->getJson('/api/v1/accounting/account-types', ['X-Company-Id' => 'anything'])
        ->assertStatus(401);
});

it('serves the same global catalogue to every company', function (): void {
    $first = apiMember(['accounting.journal.read'], 'Cat A');
    $second = apiMember(['accounting.journal.read'], 'Cat B');

    $a = $this->actingAs($first['user'], 'web')
        ->getJson('/api/v1/accounting/account-types', ['X-Company-Id' => $first['uuid']])
        ->assertOk()->json('data.account_types');

    $b = $this->actingAs($second['user'], 'web')
        ->getJson('/api/v1/accounting/account-types', ['X-Company-Id' => $second['uuid']])
        ->assertOk()->json('data.account_types');

    // A shared catalogue, not tenant data: identical for both, and nothing here is company-specific.
    expect($a)->toBe($b);
});
