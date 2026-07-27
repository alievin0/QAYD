<?php

declare(strict_types=1);

use App\Actions\Onboarding\CreateCompanyAction;
use App\Data\Onboarding\CreateCompanyData;
use App\Models\User;
use App\Services\Identity\PermissionResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S1-10 — create-company backend (Epic D). A verified user with zero companies creates one, becomes its
 * Owner with the full permission grant, and the company gets its first fiscal year — all in one
 * transaction, audited. Needs real PostgreSQL (RLS, the fiscal_years table, the audit ledger), so it
 * boots the two-connection harness and seeds the RBAC catalogue the Owner system role comes from.
 */
uses()->group('identity', 'onboarding');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
});

/**
 * A complete, valid create-company body. Callers override individual fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Falcon Trading Co '.uniqid(),
        'trade_name' => 'Falcon',
        'name_en' => 'Falcon Trading',
        'name_ar' => 'شركة الصقر للتجارة',
        'base_currency' => 'KWD',
        'fiscal_year_start_month' => 1,
        'timezone' => 'Asia/Kuwait',
        'locale' => 'ar',
    ], $overrides);
}

it('lets a verified, zero-company user create a company and become its Owner with the full grant', function (): void {
    $user = User::factory()->create(); // verified by default, no memberships

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', companyPayload());

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.company.role', 'owner');

    $uuid = $response->json('data.company.uuid');
    expect($uuid)->toBeString();

    $owner = TenantHarness::owner();
    $companyId = (int) $owner->table('companies')->where('uuid', $uuid)->value('id');

    // The creator has exactly one active membership in the new company...
    $membership = $owner->table('company_users')
        ->where('company_id', $companyId)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull();
    expect($membership->status)->toBe('active');

    // ...bound to the Owner SYSTEM role (company_id IS NULL, is_system true) from S1-09.
    $role = $owner->table('roles')->where('id', $membership->role_id)->first();
    expect($role->key)->toBe('owner');
    expect($role->company_id)->toBeNull();
    expect((bool) $role->is_system)->toBeTrue();

    // And the resolved permission set is the ENTIRE catalogue — the full Owner grant.
    $resolved = app(PermissionResolver::class)->resolve($user->id, $companyId);
    $totalPerms = (int) $owner->table('permissions')->count();

    expect($resolved->all())->toHaveCount($totalPerms);
    expect($resolved->has('bank.transfer'))->toBeTrue();   // a representative sensitive permission
    expect($resolved->has('accounting.read'))->toBeTrue();
});

it('creates the first fiscal year, opened for posting', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', companyPayload(['fiscal_year_start_month' => 1]));

    $response->assertCreated()
        ->assertJsonPath('data.company.fiscal_year.status', 'open');

    // A January fiscal-year start yields a calendar year: 01-01 → 12-31.
    expect($response->json('data.company.fiscal_year.start_date'))->toEndWith('-01-01');
    expect($response->json('data.company.fiscal_year.end_date'))->toEndWith('-12-31');

    $owner = TenantHarness::owner();
    $companyId = (int) $owner->table('companies')->where('uuid', $response->json('data.company.uuid'))->value('id');

    $fiscalYears = $owner->table('fiscal_years')->where('company_id', $companyId)->get();

    // Exactly one fiscal year, company-stamped and open.
    expect($fiscalYears)->toHaveCount(1);
    $fy = $fiscalYears->first();
    expect((int) $fy->company_id)->toBe($companyId);
    expect($fy->status)->toBe('open');
    expect((string) $fy->name)->toStartWith('FY');
});

it('derives a non-calendar fiscal year from the start month (April → Apr 1 to Mar 31)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', companyPayload(['fiscal_year_start_month' => 4]));

    $response->assertCreated();

    $start = (string) $response->json('data.company.fiscal_year.start_date');
    $end = (string) $response->json('data.company.fiscal_year.end_date');

    expect($start)->toEndWith('-04-01');
    expect($end)->toEndWith('-03-31');
    // A full 12-month span: the year rolls over.
    expect((int) substr($end, 0, 4))->toBe((int) substr($start, 0, 4) + 1);
});

it('is transactional — an injected failure leaves NO orphan company, membership, or fiscal year', function (): void {
    $owner = TenantHarness::owner();

    $legalName = 'Rollback Co '.uniqid();
    $data = new CreateCompanyData(
        legalName: $legalName,
        nameEn: 'Rollback',
        baseCurrency: 'KWD',
        fiscalYearStartMonth: 1,
    );

    // Inject a real mid-transaction failure: a creator whose user row does not exist. The companies row
    // (created_by has no FK) inserts first, then the company_users INSERT violates its user_id → users FK,
    // aborting the transaction. If the write is truly atomic, the already-inserted company is rolled back.
    $ghost = new User;
    $ghost->id = 987_654_321; // no such user

    $companiesBefore = (int) $owner->table('companies')->count();

    $threw = false;
    try {
        app(CreateCompanyAction::class)->execute($data, $ghost);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('the injected FK failure must abort the action');

    // Nothing persisted: no company by that name, and the total company count is unchanged (the
    // inserted-then-rolled-back row left no trace), so there is no orphan membership/fiscal year either.
    expect($owner->table('companies')->where('legal_name', $legalName)->exists())->toBeFalse();
    expect((int) $owner->table('companies')->count())->toBe($companiesBefore);
});

it('refuses an email-unverified user with 403 EMAIL_NOT_VERIFIED', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', companyPayload())
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'EMAIL_NOT_VERIFIED');

    // The guard stops creation before any row is written.
    expect(TenantHarness::owner()->table('company_users')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('writes a company.created audit row', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/companies', companyPayload());

    $response->assertCreated();

    $owner = TenantHarness::owner();
    $companyId = (int) $owner->table('companies')->where('uuid', $response->json('data.company.uuid'))->value('id');

    $audit = $owner->table('audit_logs')
        ->where('company_id', $companyId)
        ->where('action', 'company.created')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->category)->toBe('data_mutation');
    expect($audit->entity_type)->toBe('companies');
    expect((int) $audit->entity_id)->toBe($companyId);
    expect((int) $audit->actor_user_id)->toBe($user->id);
});

it('returns the created company in the standard envelope, never the internal id', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/companies', [
        'legal_name' => 'Envelope Trading Co',
        'trade_name' => 'EnvCo',
        'name_en' => 'Envelope Trading',
        'name_ar' => 'شركة المغلف',
        'base_currency' => 'kwd', // lower-case on input; normalised to upper on the way out
        'fiscal_year_start_month' => 1,
        'timezone' => 'Asia/Kuwait',
        'locale' => 'ar',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'identity.company.created')
        ->assertJsonPath('errors', [])
        ->assertJsonStructure(['success', 'data', 'message', 'errors', 'meta', 'request_id', 'timestamp'])
        ->assertJsonPath('data.company.legal_name', 'Envelope Trading Co')
        ->assertJsonPath('data.company.trade_name', 'EnvCo')
        ->assertJsonPath('data.company.name_en', 'Envelope Trading')
        ->assertJsonPath('data.company.name_ar', 'شركة المغلف')
        ->assertJsonPath('data.company.base_currency', 'KWD')
        ->assertJsonPath('data.company.status', 'active')
        ->assertJsonPath('data.company.role', 'owner');

    $company = $response->json('data.company');
    expect($company)->toBeArray();
    expect($company)->not->toHaveKey('id');            // internal sequential id never leaks
    expect($company['uuid'])->toBeString();
});
