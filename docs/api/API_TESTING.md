# API Testing Strategy — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API_TESTING
---

# Purpose

QAYD is an AI Financial Operating System. Every number a customer sees — a trial balance, a payroll
run total, a bank reconciliation, a tax return — is derived from data that passed through the API
layer described in `API_ARCHITECTURE` and `API_AUTH`. If the API layer regresses, the regression is
not cosmetic: it is a wrong debit, a duplicated payroll release, a leaked cross-tenant balance, or a
webhook that silently stops firing while a customer's accountant assumes it did not. This document
defines how QAYD tests that layer so those failure modes are caught in CI, not in a customer's ledger.

The API is consumed by four surfaces that must never disagree with each other or with the OpenAPI
contract: the Next.js 15 web app, the Flutter mobile app, the FastAPI/Python AI engine, and
third-party integrations built against the published SDKs (PHP, JavaScript/TypeScript, Python,
Flutter/Dart). Because Laravel 12 is the single source of truth and nothing bypasses it to the
database, the API test suite is the highest-leverage test suite in the platform: a bug caught here
is a bug prevented in every client at once. A bug missed here reaches every client at once too.

This document covers:

- The test pyramid QAYD enforces across unit, feature, contract, end-to-end, and performance layers,
  and which tool owns which layer (Pest/PHPUnit, Playwright, k6, Spectator/Schemathesis).
- How every endpoint is exercised as a Laravel feature test, including the standard response envelope,
  and how authentication (Sanctum + JWT bearer) is simulated without hitting real identity providers.
- How multi-tenant isolation — the single most important invariant in the platform — is asserted as
  its own first-class test suite, not as an afterthought inside unrelated feature tests.
- How the OpenAPI specification is treated as an executable contract rather than static documentation,
  so the docs cannot silently drift from what the API actually returns.
- Conventions for factories, seeders, and permission/403 coverage, validation and error-shape testing,
  pagination/filtering testing, idempotency and webhook testing, load testing with k6, and the CI/CD
  pipeline that gates every merge and every deploy.
- The minimum coverage bar per module and what "covered" means in a financial system (it means more
  than a green coverage badge — it means every permission boundary and every money-math path is
  exercised by a test that fails loudly when the boundary or the math changes).

Everything in this document assumes the platform facts fixed in `DESIGN_CONTEXT.md`: Laravel 12 /
PHP 8.4+ backend, PostgreSQL, Redis, `/api/v1/` REST JSON, Sanctum + JWT bearer auth, the standard
response envelope, `X-Company-Id` tenant scoping, dotted permission keys, and OpenAPI as the contract
format. No test example in this document invents a different convention.

# Test Pyramid

QAYD's API test pyramid has five layers. Lower layers are cheap, numerous, and run on every commit.
Higher layers are expensive, few, and run on a schedule or at merge/release gates. The pyramid is
enforced, not aspirational: CI fails if the proportion of feature tests without a corresponding unit
test for their service-layer logic drops, and a quarterly test-audit (tracked in `reports.export`
against the test suite itself) flags directories that have grown E2E-heavy because a team found unit
testing inconvenient.

```
                         ▲
                        / \          k6 (scheduled + release gate)
                       / P \         Performance & Load — few, slow, run nightly/pre-release
                      /-----\
                     /  E2E  \       Playwright + Flutter integration_test
                    /---------\      End-to-End — critical user journeys only
                   / Contract  \     Spectator + Schemathesis vs openapi/qayd-api-v1.yaml
                  /-------------\    Contract — every documented endpoint, every commit
                 /   Feature      \  Pest feature tests over HTTP kernel
                /-------------------\ Integration — majority of the suite, every commit
               /        Unit          \ Pest unit tests — Services, Repositories, value objects
              /---------------------------\ Fast, isolated, no DB — every commit, sub-second each
```

| Layer | Tool | Runs against | Speed | Runs on | Owner |
|---|---|---|---|---|---|
| Unit | Pest (PHPUnit runner) | Isolated PHP classes, no DB, no HTTP | < 50ms/test | Every commit, every PR | Backend engineer who wrote the code |
| Feature / Integration | Pest + `Illuminate\Foundation\Testing\TestCase` | Full HTTP kernel, real PostgreSQL (test DB), real middleware/auth/validation | 50–300ms/test | Every commit, every PR | Backend engineer who owns the endpoint |
| Contract | Spectator (Pest plugin) + Schemathesis (CI job) | `openapi/qayd-api-v1.yaml` vs actual HTTP responses | 100–500ms/test | Every commit (Spectator), nightly fuzz (Schemathesis) | API platform team |
| End-to-End | Playwright (web), Flutter `integration_test` (mobile) | Deployed ephemeral/staging environment, real browser/device | 2–20s/test | Every PR (smoke subset), full suite nightly | QA + frontend engineers |
| Performance / Load | k6 | Ephemeral/staging environment | Minutes per run | Smoke on every PR (blocking), full load nightly (non-blocking), pre-release soak (blocking) | Platform/SRE team |

Target composition of the total automated-test count (not effort, not lines of code): approximately
55% unit, 32% feature/integration, 6% contract, 5% end-to-end, 2% performance scenarios. This
document focuses on the feature, contract, permission, validation, idempotency/webhook, and
performance layers because those are the layers unique to an API-first financial platform; unit
testing of pure PHP value objects (e.g. a `Money` object, an `ExchangeRate` converter) follows
ordinary Pest unit-test practice and is not repeated here endpoint-by-endpoint.

Two rules apply across every layer:

1. **No layer substitutes for another.** A Playwright test that happens to exercise `POST
   /api/v1/sales/invoices` does not excuse that endpoint from having a Pest feature test. E2E tests
   verify the UI and the integration seam; feature tests verify the endpoint's contract and business
   rules exhaustively (happy path + every documented error path). E2E suites are deliberately kept
   small because they are the slowest and flakiest layer to maintain.
2. **Every layer runs against the same seeded permission/role model.** Roles, permissions, and
   account types are seeded once via `database/seeders/` (see `# Data Setup`) and every layer —
   Pest, Playwright, k6 — authenticates using the same helper conventions so that a permission bug
   cannot hide because one layer used a different, more permissive test user.

# Feature Tests

Feature tests are the backbone of the suite. Every route registered in `routes/api.php` MUST have at
least one feature test under `tests/Feature/Api/V1/<Module>/` that asserts the happy path against the
standard envelope, and at least one test per documented failure mode (403, 404, 409, 422 as
applicable). Tests live one-to-one with controllers: `JournalEntryController` → `JournalEntryTest.php`.

**Conventions**

- Base class: `Tests\TestCase` (extends `Illuminate\Foundation\Testing\TestCase`, includes
  `CreatesApplication`). Configured in `tests/Pest.php` via
  `uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');`
- Use `RefreshDatabase` (migrates + wraps each test in a transaction) for correctness. Use
  `LazilyRefreshDatabase` on suites where most tests are read-only, to skip migration when no
  mutation touches the schema, cutting suite time materially on large modules like Reports.
- Every request goes through `postJson()`, `getJson()`, `patchJson()`, `putJson()`, `deleteJson()` —
  never `Route::dispatch()` directly — so middleware (auth, `X-Company-Id` resolution, rate limiting,
  request validation) is exercised exactly as production traffic experiences it.
- Assert the full envelope shape on every test, not just the HTTP status code. A passing test that
  only checks `assertStatus(200)` would not catch a regression that silently drops `meta.pagination`.

```php
// tests/Feature/Api/V1/Accounting/JournalEntryTest.php

use App\Models\Account;
use App\Models\JournalEntry;
use function Pest\Laravel\{postJson, getJson};

beforeEach(function () {
    $this->company = companyWithChartOfAccounts();
    $this->user = actingAsCompanyUser($this->company, permissions: ['accounting.journal.create']);
});

it('creates a draft journal entry and returns the standard envelope', function () {
    $cash = Account::where('code', '1000')->firstOrFail();
    $revenue = Account::where('code', '4000')->firstOrFail();

    $response = postJson('/api/v1/accounting/journal-entries', [
        'entry_date' => '2026-07-16',
        'memo' => 'Cash sale — POS batch #4471',
        'currency_code' => 'KWD',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => '120.0000', 'credit' => '0.0000'],
            ['account_id' => $revenue->id, 'debit' => '0.0000', 'credit' => '120.0000'],
        ],
    ], ['X-Company-Id' => $this->company->id]);

    $response->assertCreated()
        ->assertJsonStructure([
            'success', 'data' => ['id', 'status', 'entry_date', 'currency_code', 'lines'],
            'message', 'errors', 'meta' => ['pagination'], 'request_id', 'timestamp',
        ])
        ->assertJson([
            'success' => true,
            'data' => ['status' => 'draft', 'currency_code' => 'KWD'],
            'errors' => [],
        ]);

    $this->assertDatabaseHas('journal_entries', [
        'id' => $response->json('data.id'),
        'company_id' => $this->company->id,
        'status' => 'draft',
    ]);
});

it('rejects an unbalanced journal entry with 422', function () {
    $cash = Account::where('code', '1000')->firstOrFail();

    $response = postJson('/api/v1/accounting/journal-entries', [
        'entry_date' => '2026-07-16',
        'currency_code' => 'KWD',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => '120.0000', 'credit' => '0.0000'],
        ],
    ], ['X-Company-Id' => $this->company->id]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonFragment(['code' => 'JOURNAL_UNBALANCED']);
});

it('posts a draft journal entry and materializes ledger entries', function () {
    $entry = JournalEntry::factory()
        ->for($this->company)
        ->draft()
        ->has(\App\Models\JournalLine::factory()->balancedPair('120.0000'), 'lines')
        ->create();

    actingAsCompanyUser($this->company, permissions: ['accounting.journal.post']);

    $response = $this->postJson("/api/v1/accounting/journal-entries/{$entry->id}/post", [],
        ['X-Company-Id' => $this->company->id]);

    $response->assertOk()->assertJsonPath('data.status', 'posted');

    $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'status' => 'posted']);
    $this->assertDatabaseCount('ledger_entries', 2);
    expect(
        \App\Models\LedgerEntry::where('journal_entry_id', $entry->id)->sum('debit')
    )->toEqual(
        \App\Models\LedgerEntry::where('journal_entry_id', $entry->id)->sum('credit')
    );
});
```

Table of the endpoints this document's examples exercise (a representative slice; every module doc
carries the exhaustive table for its own resources):

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | /api/v1/accounting/journal-entries | accounting.journal.create | Create a draft journal entry |
| POST | /api/v1/accounting/journal-entries/{id}/post | accounting.journal.post | Post a draft entry to the GL |
| POST | /api/v1/accounting/journal-entries/{id}/void | accounting.journal.void | Void a posted entry (reversing) |
| GET | /api/v1/sales/invoices | sales.invoice.read | List invoices, paginated/filterable |
| POST | /api/v1/sales/invoices | sales.invoice.create | Create a sales invoice |
| POST | /api/v1/sales/invoices/{id}/void | sales.invoice.void | Void a posted invoice |
| POST | /api/v1/banking/transfers | bank.transfer | Initiate an inter-account transfer |
| POST | /api/v1/banking/transfers/{id}/approve | bank.transfer | Approve a pending transfer |
| POST | /api/v1/payroll/runs/{id}/approve | payroll.approve | Approve a calculated payroll run |
| POST | /api/v1/payroll/runs/{id}/release | payroll.release | Release approved payroll for payment |
| POST | /api/v1/tax/returns/{id}/submit | tax.submit | Submit a tax return to the authority |
| GET | /api/v1/reports/trial-balance | reports.read | Generate a trial balance report |

# Authentication In Tests

Every feature test authenticates through the same real guards production traffic uses — Sanctum for
first-party web/mobile sessions and personal access tokens, and the JWT guard for stateless
third-party/integration bearer tokens — never a bypassed "test-only" auth shortcut that could hide a
real authentication or authorization bug.

**Sanctum tests (web app, mobile app, first-party tokens)**

```php
// tests/Support/ActingAs.php — shared Pest helper, autoloaded via composer.json "autoload-dev"

use App\Models\User;
use App\Models\Company;
use Laravel\Sanctum\Sanctum;

function actingAsCompanyUser(Company $company, array $permissions = [], ?string $role = null): User
{
    $user = User::factory()
        ->hasAttached($company, ['role' => $role ?? 'Accountant'], 'companies')
        ->create();

    grantPermissions($user, $company, $permissions);

    Sanctum::actingAs($user, abilities: ['*']);

    // Simulate the resolved tenant context precisely as the ResolveCompanyContext
    // middleware would from the X-Company-Id header, so tests fail if that
    // middleware's contract changes.
    app()->instance('currentCompany', $company);

    return $user;
}
```

```php
it('rejects requests with no bearer token', function () {
    $this->getJson('/api/v1/accounting/journal-entries')
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonFragment(['code' => 'UNAUTHENTICATED']);
});

it('rejects an expired Sanctum token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-device', ['*'], expiresAt: now()->subMinute());

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->getJson('/api/v1/accounting/journal-entries')
        ->assertStatus(401);
});

it('rejects a token whose abilities do not cover the request', function () {
    $user = User::factory()->create();
    $token = $user->createToken('read-only-integration', ['accounting.read']);

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->postJson('/api/v1/accounting/journal-entries', [])
        ->assertStatus(403);
});
```

**JWT bearer tests (third-party integrations, Flutter offline-first refresh flow)**

```php
it('issues a JWT bearer token on login and accepts it on protected routes', function () {
    $company = companyWithChartOfAccounts();
    $user = User::factory()->hasAttached($company, ['role' => 'Accountant'], 'companies')->create([
        'password' => bcrypt('correct-horse-battery-staple'),
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
        'company_id' => $company->id,
    ])->assertOk();

    $token = $login->json('data.access_token');
    expect($token)->not->toBeNull();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Company-Id', $company->id)
        ->getJson('/api/v1/accounting/accounts')
        ->assertOk();
});

it('refreshes an access token using a valid refresh token before expiry', function () {
    ['refresh_token' => $refresh] = loginAndReturnTokens();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);
});

it('rejects a malformed JWT with 401 and does not leak the parser exception', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-jwt.abc.def')
        ->getJson('/api/v1/accounting/accounts')
        ->assertStatus(401)
        ->assertJsonMissingPath('errors.0.trace');
});
```

Time-based auth assertions (expiry, refresh windows) always pin the clock with
`Carbon::setTestNow('2026-07-16 09:00:00')` rather than relying on wall-clock timing, eliminating a
whole class of CI flakiness described further in `# Examples`.

# Multi-Tenant Test Isolation

Tenant isolation is the single invariant QAYD cannot afford to regress silently, so it is tested as
its own dedicated suite — `tests/Feature/Security/TenantIsolation*Test.php` — independent of, and in
addition to, the isolation assertions sprinkled through ordinary feature tests. Every model that
carries `company_id` uses a `BelongsToCompany` trait that applies a global Eloquent scope
constraining every query to `currentCompany()->id`; the isolation suite exists specifically to prove
that scope cannot be bypassed, forgotten, or overridden by a header mismatch.

**The core isolation test pattern**

```php
// tests/Feature/Security/TenantIsolationTest.php

it('never returns another company\'s resource, even by guessing a valid ID', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();

    $invoiceB = \App\Models\Invoice::factory()->for($companyB, 'company')->posted()->create();

    actingAsCompanyUser($companyA, permissions: ['sales.invoice.read']);

    // Company A's token is valid; the resource exists; it simply is not theirs.
    // Correct response is 404, NOT 403 — a 403 would confirm the ID exists,
    // which is itself a cross-tenant information leak (an IDOR oracle).
    $this->withHeader('X-Company-Id', $companyA->id)
        ->getJson("/api/v1/sales/invoices/{$invoiceB->id}")
        ->assertStatus(404)
        ->assertJsonFragment(['code' => 'RESOURCE_NOT_FOUND']);
});

it('rejects an X-Company-Id the authenticated user is not a member of', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    $user = actingAsCompanyUser($companyA, permissions: ['accounting.read']);

    $this->withHeader('X-Company-Id', $companyB->id)
        ->getJson('/api/v1/accounting/journal-entries')
        ->assertStatus(403)
        ->assertJsonFragment(['code' => 'COMPANY_CONTEXT_FORBIDDEN']);
});

it('excludes soft-deleted rows and never exposes deleted_at cross-tenant either', function () {
    $company = companyWithChartOfAccounts();
    $invoice = \App\Models\Invoice::factory()->for($company, 'company')->create();
    $invoice->delete(); // soft delete only — financial records are never hard-deleted

    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    $this->withHeader('X-Company-Id', $company->id)
        ->getJson("/api/v1/sales/invoices/{$invoice->id}")
        ->assertStatus(404);

    $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
});

it('never leaks Company B totals into Company A aggregate report endpoints', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();

    postedInvoice($companyA, total: '500.0000');
    postedInvoice($companyB, total: '999999.0000'); // would be an obvious tell if it leaked

    actingAsCompanyUser($companyA, permissions: ['reports.read']);

    $this->withHeader('X-Company-Id', $companyA->id)
        ->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('data.total_debits', '500.0000');
});
```

**Architecture test — every tenant model MUST use the isolation trait**

Rather than trust that every future model remembers to apply `BelongsToCompany`, QAYD runs a Pest
architecture test that fails the build if a new Eloquent model under `app/Models` introduces a
`company_id` column without the trait, or if a model is added to the foundation whitelist without
explicit review sign-off:

```php
// tests/Architecture/TenantScopeTest.php

arch('every tenant-scoped model uses BelongsToCompany')
    ->expect(App\Models\Concerns\HasCompanyIdColumn::modelsWithCompanyId())
    ->toUseTrait(App\Models\Concerns\BelongsToCompany::class);

arch('no repository or service queries journal_lines/ledger_entries without a company scope')
    ->expect('App\Repositories')
    ->not->toUse(['DB::table', 'Model::withoutGlobalScope']);
```

**Queue jobs and webhooks carry tenant context explicitly.** Because queued jobs and scheduled
webhook deliveries run outside an HTTP request, they cannot rely on a middleware-resolved
`currentCompany()`. Every job constructor accepts and stores `company_id` explicitly, and the
isolation suite asserts a dispatched job for Company A never touches Company B's queue payload or
webhook endpoint:

```php
it('scopes a queued webhook dispatch to the company that owns the triggering event', function () {
    Queue::fake();
    $company = companyWithChartOfAccounts();
    $invoice = postedInvoice($company, total: '250.0000');

    recordReceipt($invoice);

    Queue::assertPushed(\App\Jobs\DispatchWebhookJob::class, function ($job) use ($company) {
        return $job->companyId === $company->id;
    });
});
```

# Contract Testing (against OpenAPI)

`openapi/qayd-api-v1.yaml` is the API contract, not a description written after the fact. Contract
testing exists to make it structurally impossible for the implementation and the published OpenAPI
document to drift apart without a test failing. QAYD runs three complementary contract-testing
mechanisms:

1. **Spectator (Pest/PHPUnit plugin) — every commit.** Validates that real HTTP responses produced
   during the ordinary feature-test suite match the OpenAPI schema for that operation: correct
   status code documented, correct required/optional response fields, correct types, correct enum
   values. This runs inline with feature tests, so it costs nothing extra in CI time and catches
   drift the moment a controller change alters a response shape.
2. **Schemathesis (CI job) — nightly + pre-release.** Property-based/fuzz testing that reads the
   OpenAPI spec and generates hundreds of request permutations per endpoint (boundary values,
   missing optional fields, wrong types, extra properties, injection-shaped strings) against a
   running ephemeral environment, asserting every response still matches its documented schema and
   that the server never 500s on malformed-but-schema-legal input.
3. **Prism (Stoplight) mock server — frontend development.** The Next.js and Flutter teams can run
   `prism mock openapi/qayd-api-v1.yaml` to develop against the contract before the Laravel
   implementation ships a given endpoint, and Playwright's early smoke tests can point at the mock
   server to validate UI wiring independent of backend readiness.

```php
// tests/Feature/Api/V1/Accounting/JournalEntryContractTest.php

use Spectator\Spectator;

beforeEach(fn () => Spectator::using('qayd-api-v1.yaml'));

it('matches the OpenAPI contract for creating a journal entry', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['accounting.journal.create']);

    $response = postJson('/api/v1/accounting/journal-entries', validJournalEntryPayload(),
        ['X-Company-Id' => $company->id]);

    $response->assertValidRequest()->assertValidResponse(201);
});

it('matches the OpenAPI contract on the 422 validation error path', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['accounting.journal.create']);

    postJson('/api/v1/accounting/journal-entries', ['lines' => []], ['X-Company-Id' => $company->id])
        ->assertValidResponse(422);
});
```

```bash
# CI job: fuzz every operation in the spec against an ephemeral review-app deployment
schemathesis run \
  --url https://review-pr-1842.qayd-staging.dev \
  --header "Authorization: Bearer ${SCHEMATHESIS_SERVICE_TOKEN}" \
  --header "X-Company-Id: ${SCHEMATHESIS_TEST_COMPANY_ID}" \
  --checks all \
  --hypothesis-max-examples=200 \
  openapi/qayd-api-v1.yaml
```

**Drift detection.** A dedicated CI step generates a controller-derived OpenAPI document (via
`dedoc/scramble` introspecting FormRequest rules, API Resource shapes, and route definitions) and
diffs it against the committed `openapi/qayd-api-v1.yaml`. Any unexplained diff — a field the code
returns that the spec doesn't document, or a spec field the code no longer returns — fails the build.
Intentional contract changes must update the committed spec file in the same PR; the diff step is a
guardrail against accidental drift, not a generator that auto-writes the contract.

| Check | Tool | Frequency | Blocking? |
|---|---|---|---|
| Response-shape validation on tested endpoints | Spectator | Every commit | Yes |
| Fuzzed input across full spec | Schemathesis | Nightly, pre-release | Pre-release only |
| Controller-derived spec vs committed spec diff | Scramble + diff script | Every commit | Yes |
| Mock-server contract for frontend dev | Prism | On demand (local/dev) | No |

# Data Setup (factories/seeders)

Every model has a corresponding `database/factories/*Factory.php`. Factories default to a *valid,
postable* state; invalid states are opt-in via explicit factory states (`->unbalanced()`,
`->missingRequiredField()`) so that a test reader can tell at a glance which tests are exercising the
happy path and which are deliberately constructing a failure.

```php
// database/factories/InvoiceFactory.php

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'customer_id' => Customer::factory(),
            'invoice_number' => 'INV-' . fake()->unique()->numerify('#####'),
            'currency_code' => 'KWD',
            'exchange_rate' => '1.000000',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '0.0000',
            'name_en' => fake()->words(3, true),
            'name_ar' => fake('ar_SA')->words(3, true),
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => ['status' => 'posted'])
            ->afterCreating(fn (Invoice $invoice) => app(InvoicePostingService::class)->post($invoice));
    }

    public function withItems(int $count = 2, string $unitPrice = '50.0000'): static
    {
        return $this->has(InvoiceItem::factory()->count($count)->state(['unit_price' => $unitPrice]),
            'items');
    }
}
```

```php
// database/seeders/PermissionSeeder.php — run in every test DB via RefreshDatabase + $this->seed()

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'accounting.read', 'accounting.journal.create', 'accounting.journal.post',
            'accounting.journal.void', 'bank.transfer', 'bank.reconcile', 'payroll.calculate',
            'payroll.approve', 'payroll.release', 'inventory.adjust', 'tax.submit', 'reports.export',
            'sales.invoice.create', 'sales.invoice.read', 'sales.invoice.void',
        ];

        foreach ($permissions as $key) {
            Permission::firstOrCreate(['key' => $key]);
        }

        $roleGrants = [
            'Owner' => $permissions, // Owner and CFO get everything for their company
            'CFO' => $permissions,
            'Finance Manager' => array_diff($permissions, ['tax.submit']),
            'Senior Accountant' => ['accounting.read', 'accounting.journal.create', 'accounting.journal.post'],
            'Accountant' => ['accounting.read', 'accounting.journal.create'],
            'Read Only' => ['accounting.read', 'sales.invoice.read'],
            'External Auditor' => ['accounting.read', 'reports.export'],
        ];

        foreach ($roleGrants as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        }
    }
}
```

**Reducing per-test boilerplate.** A `CompanyTestContext` builder composes the common "give me a
fully usable tenant" fixture in one call, used at the top of most feature tests instead of manually
wiring a company, chart of accounts, and admin user every time:

```php
// tests/Support/CompanyTestContext.php

function companyWithChartOfAccounts(string $baseCurrency = 'KWD'): Company
{
    $company = Company::factory()->create(['base_currency' => $baseCurrency]);

    $this->seed(AccountTypeSeeder::class); // system-level, idempotent
    app(ChartOfAccountsSeeder::class)->seedDefaultsFor($company);

    return $company;
}
```

`TestCase` seeds `PermissionSeeder` and `AccountTypeSeeder` once per test via a `beforeEach` in
`tests/Pest.php`, so every feature test starts from the same known-good foundation without repeating
seeder calls inline:

```php
// tests/Pest.php
uses(Tests\TestCase::class, RefreshDatabase::class)->beforeEach(function () {
    $this->seed([PermissionSeeder::class, AccountTypeSeeder::class]);
})->in('Feature');
```

# Testing Permissions & 403 Paths

QAYD is default-deny. The permission test suite is written as a dataset-driven matrix so that adding
a new sensitive endpoint forces the author to also declare which roles may call it — the dataset is
the enforcement mechanism, not a suggestion.

| Permission | Owner | CFO | Finance Manager | Senior Accountant | Accountant | Read Only | External Auditor |
|---|---|---|---|---|---|---|---|
| accounting.journal.create | Yes | Yes | Yes | Yes | Yes | No | No |
| accounting.journal.post | Yes | Yes | Yes | Yes | No | No | No |
| accounting.journal.void | Yes | Yes | Yes | No | No | No | No |
| bank.transfer | Yes | Yes | Yes | No | No | No | No |
| payroll.approve | Yes | Yes | No | No | No | No | No |
| payroll.release | Yes | Yes | No | No | No | No | No |
| tax.submit | Yes | Yes | No | No | No | No | No |
| reports.export | Yes | Yes | Yes | Yes | No | No | Yes |

```php
// tests/Feature/Api/V1/Security/PermissionMatrixTest.php

it('enforces the permission matrix on sensitive endpoints', function (string $path, string $method,
    string $permission, array $allowedRoles) {

    $company = companyWithChartOfAccounts();

    foreach (['Owner', 'CFO', 'Finance Manager', 'Senior Accountant', 'Accountant', 'Read Only',
              'External Auditor'] as $role) {

        actingAsCompanyUser($company, role: $role);

        $response = $this->json($method, $path, minimalValidPayloadFor($path),
            ['X-Company-Id' => $company->id]);

        if (in_array($role, $allowedRoles, true)) {
            expect($response->status())->not->toBe(403);
        } else {
            $response->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonFragment(['code' => 'PERMISSION_DENIED']);
        }
    }
})->with([
    ['/api/v1/accounting/journal-entries/{id}/void', 'POST', 'accounting.journal.void',
        ['Owner', 'CFO', 'Finance Manager']],
    ['/api/v1/banking/transfers', 'POST', 'bank.transfer', ['Owner', 'CFO', 'Finance Manager']],
    ['/api/v1/payroll/runs/{id}/release', 'POST', 'payroll.release', ['Owner', 'CFO']],
    ['/api/v1/tax/returns/{id}/submit', 'POST', 'tax.submit', ['Owner', 'CFO']],
]);
```

**Sensitive operations require a human approval chain even for an authorized caller.** An Owner
calling `POST /api/v1/banking/transfers` with a permitted role does not move money immediately —
the endpoint returns `202 Accepted` with `data.status = "pending_approval"`, and a second,
independent user holding `bank.transfer` must call the approval endpoint before funds move. The test
suite asserts both halves, and asserts a single user cannot self-approve:

```php
it('creates a transfer as pending_approval and forbids the creator from self-approving', function () {
    $company = companyWithChartOfAccounts();
    $creator = actingAsCompanyUser($company, permissions: ['bank.transfer']);

    $create = postJson('/api/v1/banking/transfers', transferPayload(), ['X-Company-Id' => $company->id])
        ->assertStatus(202);

    expect($create->json('data.status'))->toBe('pending_approval');

    // Same user attempts to approve their own initiated transfer.
    postJson("/api/v1/banking/transfers/{$create->json('data.id')}/approve", [],
        ['X-Company-Id' => $company->id])
        ->assertStatus(403)
        ->assertJsonFragment(['code' => 'SELF_APPROVAL_FORBIDDEN']);

    // A second authorized approver succeeds.
    actingAsCompanyUser($company, permissions: ['bank.transfer']);
    postJson("/api/v1/banking/transfers/{$create->json('data.id')}/approve", [],
        ['X-Company-Id' => $company->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});
```

Every `403` response in the suite is asserted against one shared fixture of the error shape so a
future change to the envelope's `errors[]` structure is caught in a single place:

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to perform this action.",
  "errors": [
    { "field": null, "code": "PERMISSION_DENIED", "message": "Missing permission: bank.transfer" }
  ],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 0, "cursor": null } },
  "request_id": "5b1b6e9e-2f0a-4c3a-9a7e-2b6f9e6a1c02",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

# Testing Validation & Errors

QAYD standardizes the shape of every `errors[]` entry across the whole API:
`{ "field": string|null, "code": string, "message": string }`. `field` uses dot-notation for nested
payloads (e.g. `lines.0.account_id`) and is `null` for entry-level or business-rule errors that are
not attributable to a single field. Every FormRequest's `rules()` is covered by a feature test that
asserts both the 422 status and the specific `code` values, because asserting only the status lets a
regression silently change which rule fired.

```php
// app/Http/Requests/Api/V1/Accounting/StoreJournalEntryRequest.php (excerpt, referenced by tests)

public function rules(): array
{
    return [
        'entry_date' => ['required', 'date'],
        'currency_code' => ['required', 'string', 'size:3', Rule::in(['KWD', 'AED', 'SAR', 'USD'])],
        'lines' => ['required', 'array', 'min:2'],
        'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')
            ->where('company_id', fn () => currentCompany()->id)],
        'lines.*.debit' => ['required', 'decimal:0,4', 'min:0'],
        'lines.*.credit' => ['required', 'decimal:0,4', 'min:0'],
    ];
}
```

```php
it('returns field-level validation errors with dot-notation paths', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['accounting.journal.create']);

    postJson('/api/v1/accounting/journal-entries', [
        'entry_date' => 'not-a-date',
        'currency_code' => 'KW',
        'lines' => [['account_id' => 999999, 'debit' => '-5.0000', 'credit' => '0.0000']],
    ], ['X-Company-Id' => $company->id])
        ->assertStatus(422)
        ->assertJsonFragment(['field' => 'entry_date', 'code' => 'date'])
        ->assertJsonFragment(['field' => 'currency_code', 'code' => 'size'])
        ->assertJsonFragment(['field' => 'lines.0.account_id', 'code' => 'exists'])
        ->assertJsonFragment(['field' => 'lines.0.debit', 'code' => 'min']);
});

it('returns a business-rule 422 with a null field for unbalanced entries', function () {
    // see JournalEntryTest — asserts code JOURNAL_UNBALANCED with field: null
});

it('returns 409 on a duplicate invoice number within the same company', function () {
    $company = companyWithChartOfAccounts();
    Invoice::factory()->for($company, 'company')->create(['invoice_number' => 'INV-1001']);
    actingAsCompanyUser($company, permissions: ['sales.invoice.create']);

    postJson('/api/v1/sales/invoices', ['invoice_number' => 'INV-1001', /* ... */],
        ['X-Company-Id' => $company->id])
        ->assertStatus(409)
        ->assertJsonFragment(['code' => 'DUPLICATE_RESOURCE']);
});

it('returns 404 with a stable error code for an unknown resource', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    getJson('/api/v1/sales/invoices/999999999', ['X-Company-Id' => $company->id])
        ->assertStatus(404)
        ->assertJsonFragment(['code' => 'RESOURCE_NOT_FOUND']);
});

it('returns 400 on malformed JSON without a Laravel debug trace leaking', function () {
    config(['app.debug' => false]);

    $this->call('POST', '/api/v1/accounting/journal-entries', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X-Company-Id' => companyWithChartOfAccounts()->id,
    ], content: '{"lines": [invalid json')
        ->assertStatus(400)
        ->assertJsonMissingPath('errors.0.trace')
        ->assertJsonMissingPath('exception');
});

it('returns 429 with Retry-After once the rate limit is exceeded', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['accounting.read']);

    RateLimiter::clear('api:' . request()->ip());

    collect(range(1, 61))->each(fn () => getJson('/api/v1/accounting/journal-entries',
        ['X-Company-Id' => $company->id]));

    $response = getJson('/api/v1/accounting/journal-entries', ['X-Company-Id' => $company->id]);
    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonFragment(['code' => 'RATE_LIMITED']);
});

it('maps an unhandled server exception to a safe 500 envelope in production mode', function () {
    config(['app.debug' => false]);
    $this->mock(\App\Services\JournalPostingService::class)
        ->shouldReceive('post')->andThrow(new \RuntimeException('unexpected ledger state'));

    $company = companyWithChartOfAccounts();
    $entry = JournalEntry::factory()->for($company, 'company')->draft()->create();
    actingAsCompanyUser($company, permissions: ['accounting.journal.post']);

    postJson("/api/v1/accounting/journal-entries/{$entry->id}/post", [], ['X-Company-Id' => $company->id])
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Something went wrong. Our team has been notified.'])
        ->assertJsonMissingPath('errors.0.trace');
});
```

| Status | When | Stable `code` examples |
|---|---|---|
| 400 | Malformed request body/headers before routing/validation runs | `MALFORMED_REQUEST` |
| 401 | Missing/invalid/expired credentials | `UNAUTHENTICATED`, `TOKEN_EXPIRED` |
| 403 | Authenticated but not authorized, or wrong `X-Company-Id` | `PERMISSION_DENIED`, `COMPANY_CONTEXT_FORBIDDEN`, `SELF_APPROVAL_FORBIDDEN` |
| 404 | Resource does not exist, or exists in another tenant | `RESOURCE_NOT_FOUND` |
| 409 | Conflicting state (duplicate number, concurrent status change) | `DUPLICATE_RESOURCE`, `STATE_CONFLICT` |
| 422 | FormRequest validation or domain business-rule violation | `JOURNAL_UNBALANCED`, field-rule codes (`required`, `min`, `exists`, ...) |
| 429 | Rate limit exceeded | `RATE_LIMITED` |
| 500 | Unhandled server error | `INTERNAL_ERROR` |

# Testing Pagination/Filtering

Every list endpoint defaults to `per_page = 25`, supports cursor pagination, and supports `filter`,
`sort`, and `q` (search) query parameters. The test suite for pagination lives once per resource but
follows an identical shared pattern via a reusable Pest higher-order test helper so new list
endpoints inherit the same coverage automatically.

```php
it('paginates with the default page size and correct meta shape', function () {
    $company = companyWithChartOfAccounts();
    Invoice::factory()->for($company, 'company')->count(40)->create();
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    $response = getJson('/api/v1/sales/invoices', ['X-Company-Id' => $company->id])->assertOk();

    expect($response->json('data'))->toHaveCount(25);
    expect($response->json('meta.pagination'))->toMatchArray([
        'page' => 1, 'per_page' => 25, 'total' => 40,
    ]);
    expect($response->json('meta.pagination.cursor'))->not->toBeNull();
});

it('walks a full cursor pagination sequence without gaps or overlap', function () {
    $company = companyWithChartOfAccounts();
    Invoice::factory()->for($company, 'company')->count(60)->create();
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    $seen = collect();
    $cursor = null;

    do {
        $response = getJson('/api/v1/sales/invoices?' . http_build_query(array_filter([
            'per_page' => 25, 'cursor' => $cursor,
        ])), ['X-Company-Id' => $company->id])->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($seen->intersect($ids))->toBeEmpty(); // no page overlap
        $seen = $seen->merge($ids);
        $cursor = $response->json('meta.pagination.cursor');
    } while ($cursor !== null);

    expect($seen->unique())->toHaveCount(60); // no gaps
});

it('clamps per_page to the documented maximum instead of erroring', function () {
    $company = companyWithChartOfAccounts();
    Invoice::factory()->for($company, 'company')->count(150)->create();
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    $response = getJson('/api/v1/sales/invoices?per_page=500', ['X-Company-Id' => $company->id])
        ->assertOk();

    expect($response->json('meta.pagination.per_page'))->toBe(100); // documented max
});

it('rejects a garbage cursor with 400 rather than 500', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    getJson('/api/v1/sales/invoices?cursor=not-a-real-cursor', ['X-Company-Id' => $company->id])
        ->assertStatus(400)
        ->assertJsonFragment(['code' => 'INVALID_CURSOR']);
});

it('filters, sorts, and searches in combination', function () {
    $company = companyWithChartOfAccounts();
    Invoice::factory()->for($company, 'company')->posted()->create(['total' => '900.0000',
        'name_en' => 'Falcon Trading Co. invoice']);
    Invoice::factory()->for($company, 'company')->draft()->create(['total' => '100.0000']);
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    $response = getJson(
        '/api/v1/sales/invoices?status=posted&q=Falcon&sort=-total',
        ['X-Company-Id' => $company->id]
    )->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('posted');
});

it('returns an empty array, not null, when a filter matches nothing', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['sales.invoice.read']);

    getJson('/api/v1/sales/invoices?status=void', ['X-Company-Id' => $company->id])
        ->assertOk()
        ->assertJson(['data' => [], 'meta' => ['pagination' => ['total' => 0]]]);
});

it('ignores an attempt to filter by another company\'s foreign key values', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    $foreignCustomer = Customer::factory()->for($companyB, 'company')->create();
    actingAsCompanyUser($companyA, permissions: ['sales.invoice.read']);

    getJson("/api/v1/sales/invoices?customer_id={$foreignCustomer->id}", ['X-Company-Id' => $companyA->id])
        ->assertOk()
        ->assertJson(['data' => []]); // matches nothing — never an information-leaking error
});
```

# Testing Idempotency & Webhooks

**Idempotency.** Every state-mutating endpoint that is unsafe to retry blindly — bank transfers,
payroll release, tax submission, receipt recording — accepts an `Idempotency-Key` header. The key is
scoped to `(company_id, key)` and stored with a hash of the request body for 24 hours (Redis-backed
`idempotency_keys` cache). A retried request with the same key and same body replays the original
response verbatim without re-executing side effects; the same key with a different body is rejected,
because silently executing a different action under a retried key would itself be a correctness bug.

```php
it('replays the cached response for a repeated Idempotency-Key without re-executing the transfer', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['bank.transfer']);
    $key = (string) Str::uuid();
    $payload = transferPayload();

    $first = postJson('/api/v1/banking/transfers', $payload,
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])->assertStatus(202);

    $second = postJson('/api/v1/banking/transfers', $payload,
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])->assertStatus(202);

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->assertDatabaseCount('transfers', 1); // only ever created once
});

it('rejects a reused Idempotency-Key sent with a different request body', function () {
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['bank.transfer']);
    $key = (string) Str::uuid();

    postJson('/api/v1/banking/transfers', transferPayload(amount: '100.0000'),
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])->assertStatus(202);

    postJson('/api/v1/banking/transfers', transferPayload(amount: '999.0000'),
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])
        ->assertStatus(422)
        ->assertJsonFragment(['code' => 'IDEMPOTENCY_KEY_REUSED']);
});

it('expires an idempotency key after the TTL and allows the same key to be reused safely', function () {
    Carbon::setTestNow('2026-07-16 09:00:00');
    $company = companyWithChartOfAccounts();
    actingAsCompanyUser($company, permissions: ['bank.transfer']);
    $key = (string) Str::uuid();

    postJson('/api/v1/banking/transfers', transferPayload(), ['X-Company-Id' => $company->id,
        'Idempotency-Key' => $key])->assertStatus(202);

    Carbon::setTestNow('2026-07-17 10:00:00'); // > 24h later

    $second = postJson('/api/v1/banking/transfers', transferPayload(),
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])->assertStatus(202);

    $this->assertDatabaseCount('transfers', 2);
});
```

**Webhooks.** Domain events (`invoice.created`, `invoice.paid`, `payment.received`,
`payroll.completed`, `inventory.updated`, `bank.synced`, `ai.finished`, `journal.posted`) are
delivered asynchronously to each company's registered `webhook_endpoints` by a queued
`DispatchWebhookJob`, signed with HMAC-SHA256 over the raw payload using the endpoint's secret, sent
as `X-Qayd-Signature: t=<unix_ts>,v1=<hex_hmac>`. Tests fake the outbound HTTP layer rather than
standing up a real receiver, and assert both the signature and the payload schema.

```php
it('dispatches a signed invoice.paid webhook to every active endpoint for the company', function () {
    Http::fake(['*' => Http::response(['received' => true], 200)]);
    $company = companyWithChartOfAccounts();
    $endpoint = WebhookEndpoint::factory()->for($company, 'company')
        ->create(['url' => 'https://erp.customer.example/hooks/qayd', 'events' => ['invoice.paid']]);

    $invoice = postedInvoice($company, total: '500.0000');
    recordReceipt($invoice, amount: '500.0000');

    Http::assertSent(function ($request) use ($endpoint) {
        $payload = $request->data();
        $expectedSig = hash_hmac('sha256', $request->body(), $endpoint->secret);

        return $request->url() === $endpoint->url
            && $payload['event'] === 'invoice.paid'
            && str_contains($request->header('X-Qayd-Signature')[0], "v1={$expectedSig}");
    });
});

it('retries a webhook delivery with backoff on a non-2xx response and opens the circuit after N failures', function () {
    Http::fake(['*' => Http::response(['error' => 'down'], 503)]);
    $company = companyWithChartOfAccounts();
    $endpoint = WebhookEndpoint::factory()->for($company, 'company')->create(['events' => ['invoice.paid']]);

    Queue::fake();
    recordReceipt(postedInvoice($company, total: '100.0000'), amount: '100.0000');

    Queue::assertPushed(\App\Jobs\DispatchWebhookJob::class, function ($job) {
        return $job->tries === 5 && $job->backoff() === [30, 120, 600, 1800, 3600];
    });

    // Simulate five consecutive failures via the job's own failure handler.
    $delivery = WebhookDelivery::factory()->for($endpoint)->count(5)->create(['status' => 'failed']);
    app(\App\Services\WebhookHealthService::class)->evaluate($endpoint);

    expect($endpoint->fresh()->status)->toBe('disabled');
});

it('lets a consumer safely process a redelivered event because event id is stable and unique', function () {
    Http::fake(['*' => Http::response(['received' => true], 200)]);
    $company = companyWithChartOfAccounts();
    WebhookEndpoint::factory()->for($company, 'company')->create(['events' => ['journal.posted']]);

    $entry = postAndCaptureJournalEntry($company);

    Http::assertSentCount(1);
    $firstEventId = Http::recorded()->first()[0]->data()['id'];

    app(\App\Jobs\DispatchWebhookJob::class, [
        'companyId' => $company->id, 'event' => 'journal.posted', 'eventId' => $firstEventId,
        'payload' => ['journal_entry_id' => $entry->id],
    ])->handle();

    // Same event id redelivered — consumers dedupe on it; QAYD itself never sends
    // two different ids for the same underlying occurrence.
    expect(Http::recorded()->pluck(fn ($pair) => $pair[0]->data()['id'])->unique())->toHaveCount(1);
});
```

| Header | Direction | Purpose |
|---|---|---|
| `Idempotency-Key` | Client → API | Client-generated UUID; safe retry of a mutating POST |
| `X-Qayd-Signature` | API → Webhook endpoint | `t=<timestamp>,v1=<hmac_sha256>` over the raw body |
| `X-Qayd-Event-Id` | API → Webhook endpoint | Stable unique id for the occurrence; consumer dedupe key |
| `X-Qayd-Delivery-Attempt` | API → Webhook endpoint | 1-indexed attempt count, for observability on the receiver |

# Performance/Load Testing

k6 exercises the API against an ephemeral or staging environment — never local SQLite, never mocked
services — because the numbers that matter (p95 latency under Redis contention, PostgreSQL lock
behavior under concurrent journal posting) do not exist in a unit-test environment. Scripts live in
`tests/Performance/*.js` and are versioned alongside the endpoints they cover.

**Smoke test — runs on every PR, blocking.** Small VU count, short duration, hard latency threshold.
Its purpose is to catch an obvious regression (an accidentally removed index, an N+1 query
introduced in a PR) before it reaches staging, not to characterize capacity.

```javascript
// tests/Performance/smoke-journal-entries.js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
  vus: 5,
  duration: '30s',
  thresholds: {
    http_req_duration: ['p(95)<300', 'p(99)<800'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.QAYD_BASE_URL;
const TOKEN = __ENV.QAYD_SMOKE_TOKEN;
const COMPANY_ID = __ENV.QAYD_SMOKE_COMPANY_ID;

export default function () {
  const res = http.get(`${BASE_URL}/api/v1/accounting/journal-entries?per_page=25`, {
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'X-Company-Id': COMPANY_ID,
      Accept: 'application/json',
    },
  });

  check(res, {
    'status is 200': (r) => r.status === 200,
    'envelope has success:true': (r) => JSON.parse(r.body).success === true,
    'pagination meta present': (r) => JSON.parse(r.body).meta.pagination !== undefined,
  });
}
```

**Load/ramp test — nightly, non-blocking (annotated in the dashboard).** Ramps virtual users up and
down across stages to find the point where latency degrades, and confirms the rate limiter engages
gracefully (429s with `Retry-After`, not 500s) rather than the origin falling over.

```javascript
// tests/Performance/load-sales-invoices.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    ramping_read_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: 50 },
        { duration: '5m', target: 50 },
        { duration: '2m', target: 200 },
        { duration: '5m', target: 200 },
        { duration: '2m', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500'],
    'http_req_duration{scenario:ramping_read_load}': ['p(99)<1200'],
  },
};

export default function () {
  const res = http.get(`${__ENV.QAYD_BASE_URL}/api/v1/sales/invoices?status=posted&sort=-issue_date`, {
    headers: { Authorization: `Bearer ${__ENV.QAYD_LOAD_TOKEN}`, 'X-Company-Id': __ENV.QAYD_LOAD_COMPANY_ID },
  });
  check(res, { 'status is 200 or 429': (r) => r.status === 200 || r.status === 429 });
  if (res.status === 429) {
    check(res, { 'has Retry-After header': (r) => r.headers['Retry-After'] !== undefined });
  }
  sleep(1);
}
```

**Concurrency/race scenario — pre-release gate.** Fires N simultaneous requests to post the *same*
draft journal entry, and to create *distinct* concurrent transfers against the *same* bank account,
to prove optimistic-locking/row-locking in `JournalPostingService` and `TransferService` prevents
double-posting and prevents the ledger from ever going out of balance under contention.

```javascript
// tests/Performance/concurrency-journal-post.js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    concurrent_post_same_entry: {
      executor: 'shared-iterations',
      vus: 20,
      iterations: 20,
      maxDuration: '10s',
    },
  },
};

export default function () {
  const res = http.post(
    `${__ENV.QAYD_BASE_URL}/api/v1/accounting/journal-entries/${__ENV.QAYD_TARGET_ENTRY_ID}/post`,
    null,
    { headers: { Authorization: `Bearer ${__ENV.QAYD_LOAD_TOKEN}`, 'X-Company-Id': __ENV.QAYD_LOAD_COMPANY_ID } }
  );
  // Exactly one of the 20 concurrent callers should succeed with 200;
  // the other 19 must get a clean 409 STATE_CONFLICT, never a duplicated posting.
  check(res, { 'status is 200 or 409': (r) => r.status === 200 || r.status === 409 });
}
```

A post-run assertion script (run outside k6, via Pest or a small PHP CLI command) queries the
database directly after the concurrency scenario and asserts `journal_entries` shows exactly one
`posted` status transition and `ledger_entries` has exactly two rows for that entry — the k6 script
proves the HTTP-level behavior; the database assertion proves the ledger integrity outcome.

| Scenario | Frequency | Blocking | Threshold |
|---|---|---|---|
| Smoke (single endpoint, low VU) | Every PR | Yes | p95 < 300ms, error rate < 1% |
| Ramping load (module-level) | Nightly | No (dashboard only) | p95 < 500ms up to 200 VU |
| Soak (steady VU, multi-hour) | Weekly | No (dashboard only) | No memory/connection-pool growth over time |
| Concurrency/race (ledger integrity) | Pre-release | Yes | Zero duplicate postings, zero unbalanced ledgers |
| Spike (rate limiter behavior) | Pre-release | Yes | 429s with `Retry-After`, zero 500s |

Results stream to Grafana Cloud k6 (or self-hosted InfluxDB + Grafana); the pre-release gate job
fails the pipeline on threshold breach and posts a summary comment to the release PR.

# CI/CD Integration

Every PR runs unit, feature, contract, static-analysis, and a performance smoke; merges to `main`
additionally run the full Playwright suite; nightly and pre-release pipelines add Schemathesis fuzz,
full k6 load/soak, and the concurrency/race scenario. Required checks are enforced via branch
protection so a red pipeline cannot be merged around.

```yaml
# .github/workflows/api-tests.yml
name: API Test Suite

on:
  pull_request:
  push:
    branches: [main]
  schedule:
    - cron: '0 2 * * *' # nightly fuzz + full load

jobs:
  static-analysis:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: composer:v2 }
      - run: composer install --prefer-dist --no-interaction
      - run: vendor/bin/phpstan analyse --level=8
      - run: vendor/bin/pint --test

  unit-and-feature:
    runs-on: ubuntu-latest
    needs: static-analysis
    services:
      postgres:
        image: postgres:16
        env: { POSTGRES_DB: qayd_test, POSTGRES_PASSWORD: secret }
        ports: ['5432:5432']
        options: >-
          --health-cmd pg_isready --health-interval 10s --health-timeout 5s --health-retries 5
      redis:
        image: redis:7
        ports: ['6379:6379']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: composer:v2, coverage: xdebug }
      - run: composer install --prefer-dist --no-interaction
      - run: cp .env.testing.example .env.testing
      - run: php artisan key:generate --env=testing
      - run: php artisan migrate --env=testing --force
      - name: Run Pest (unit + feature, parallel, with coverage gate)
        run: vendor/bin/pest --parallel --coverage --min=80
      - uses: codecov/codecov-action@v4
        with: { files: ./coverage.xml }

  contract:
    runs-on: ubuntu-latest
    needs: unit-and-feature
    steps:
      - uses: actions/checkout@v4
      - run: composer install --prefer-dist --no-interaction
      - run: vendor/bin/pest --group=contract
      - name: Diff controller-derived spec vs committed OpenAPI
        run: |
          php artisan scramble:export --output=openapi/generated.yaml
          php scripts/assert-no-openapi-drift.php openapi/qayd-api-v1.yaml openapi/generated.yaml

  perf-smoke:
    runs-on: ubuntu-latest
    needs: unit-and-feature
    steps:
      - uses: actions/checkout@v4
      - uses: grafana/setup-k6-action@v1
      - name: Deploy ephemeral review app
        run: ./scripts/deploy-ephemeral.sh ${{ github.event.pull_request.number }}
      - run: k6 run tests/Performance/smoke-journal-entries.js
        env:
          QAYD_BASE_URL: https://review-pr-${{ github.event.pull_request.number }}.qayd-staging.dev
          QAYD_SMOKE_TOKEN: ${{ secrets.QAYD_SMOKE_TOKEN }}
          QAYD_SMOKE_COMPANY_ID: ${{ secrets.QAYD_SMOKE_COMPANY_ID }}

  e2e:
    runs-on: ubuntu-latest
    needs: perf-smoke
    if: github.ref == 'refs/heads/main' || contains(github.event.pull_request.labels.*.name, 'run-e2e')
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - run: npm ci && npx playwright install --with-deps
      - run: npm run test:e2e -- --reporter=github

  nightly-fuzz-and-load:
    runs-on: ubuntu-latest
    if: github.event_name == 'schedule'
    steps:
      - uses: actions/checkout@v4
      - name: Schemathesis contract fuzz
        run: |
          pip install schemathesis
          schemathesis run --url https://staging.qayd.internal --checks all \
            --hypothesis-max-examples=200 openapi/qayd-api-v1.yaml
      - uses: grafana/setup-k6-action@v1
      - run: k6 run tests/Performance/load-sales-invoices.js
        env: { QAYD_BASE_URL: https://staging.qayd.internal }
```

Branch protection on `main` requires `static-analysis`, `unit-and-feature`, `contract`, and
`perf-smoke` to pass. `e2e` is required before a release tag is cut, not before every PR merge, to
keep PR feedback fast; the label `run-e2e` opts a specific PR into the full suite early when a
reviewer wants it. `nightly-fuzz-and-load` is monitored but does not block PRs — findings become
tracked issues, and a hard regression escalates to blocking the next release, not the next commit.

# Coverage Targets

Coverage is enforced per module, not as one blended repository-wide number, because a 90%-covered
Reports module cannot compensate for an undertested Payroll module — the risk is not fungible across
modules in a financial system.

| Module | Line coverage (Pest `--min`) | Endpoint coverage (happy + failure path) | Mutation score (Infection MSI) |
|---|---|---|---|
| Accounting (journals, ledger, chart of accounts) | 90% | 100% | 75% |
| Banking (transfers, reconciliation) | 92% | 100% | 80% |
| Payroll | 90% | 100% | 75% |
| Tax | 90% | 100% | 75% |
| Sales (invoices, receipts, credit notes) | 85% | 100% | 65% |
| Purchasing (bills, vendor payments) | 85% | 100% | 65% |
| Inventory | 82% | 100% | 60% |
| Reports | 75% | 90% | 50% |
| AI orchestration boundary (Laravel-side only) | 80% | 100% | 55% |
| Overall repository gate (`pest --min=`) | 80% | — | — |

"Endpoint coverage" is not a code-coverage metric — it is a checklist enforced by a custom Pest
architecture assertion that cross-references `php artisan route:list --path=api/v1` against a
generated inventory of tested routes (routes hit by at least one `postJson`/`getJson`/etc. call
across the suite, tagged via a `#[CoversRoute]` PHP attribute on each test). A route with zero
covering tests fails the build; a route covered only on its happy path is flagged in the weekly
coverage report as "partial" and tracked to closure, but does not fail the build immediately so a
large new module can land incrementally without being all-or-nothing blocked. Mutation testing
(Infection PHP) runs weekly against Accounting, Banking, Payroll, and Tax specifically, because line
coverage alone does not prove an assertion is meaningful — a test that calls an endpoint but asserts
only `assertOk()` inflates line coverage without proving the response is correct, and mutation
testing is what catches that gap.

# Examples

**1. Full lifecycle — invoice to paid, GL balanced, webhook fired (Pest feature test spanning
Sales, Accounting, and the webhook subsystem in one assertion chain).**

```php
it('takes an invoice from draft to paid, posts a balanced GL entry, and fires invoice.paid', function () {
    Http::fake(['*' => Http::response(['received' => true], 200)]);
    $company = companyWithChartOfAccounts();
    WebhookEndpoint::factory()->for($company, 'company')->create(['events' => ['invoice.paid']]);
    actingAsCompanyUser($company, permissions: ['sales.invoice.create', 'sales.invoice.read']);

    $invoice = postJson('/api/v1/sales/invoices', [
        'customer_id' => Customer::factory()->for($company, 'company')->create()->id,
        'currency_code' => 'KWD',
        'items' => [['description' => 'Consulting — July', 'quantity' => '10.0000', 'unit_price' => '50.0000']],
    ], ['X-Company-Id' => $company->id])->assertCreated();

    postJson("/api/v1/sales/invoices/{$invoice->json('data.id')}/post", [],
        ['X-Company-Id' => $company->id])->assertOk()->assertJsonPath('data.status', 'posted');

    postJson('/api/v1/sales/receipts', [
        'invoice_id' => $invoice->json('data.id'), 'amount' => '500.0000', 'currency_code' => 'KWD',
        'received_at' => '2026-07-16',
    ], ['X-Company-Id' => $company->id])->assertCreated();

    $paid = getJson("/api/v1/sales/invoices/{$invoice->json('data.id')}", ['X-Company-Id' => $company->id]);
    expect($paid->json('data.status'))->toBe('paid');

    $entry = JournalEntry::where('company_id', $company->id)->latest()->firstOrFail();
    expect($entry->lines()->sum('debit'))->toEqual($entry->lines()->sum('credit'));

    Http::assertSent(fn ($r) => $r->data()['event'] === 'invoice.paid');
});
```

**2. Playwright E2E — creating an invoice through the Next.js UI and asserting the underlying API
contract in the same test (network interception, not just visual assertion).**

```typescript
// e2e/sales/create-invoice.spec.ts
import { test, expect } from '@playwright/test';

test('user creates an invoice and the UI reflects the API-computed total', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email').fill('accountant@acme-test.qayd.dev');
  await page.getByLabel('Password').fill(process.env.E2E_TEST_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard/);

  const [createResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v1/sales/invoices') && r.request().method() === 'POST'),
    (async () => {
      await page.goto('/sales/invoices/new');
      await page.getByLabel('Customer').selectOption({ label: 'Falcon Trading Co.' });
      await page.getByRole('button', { name: 'Add line item' }).click();
      await page.getByLabel('Description').fill('Consulting — July');
      await page.getByLabel('Quantity').fill('10');
      await page.getByLabel('Unit price').fill('50.000');
      await page.getByRole('button', { name: 'Save invoice' }).click();
    })(),
  ]);

  const body = await createResponse.json();
  expect(body.success).toBe(true);
  expect(body.data.status).toBe('draft');
  expect(body.errors).toEqual([]);

  await expect(page.getByTestId('invoice-total')).toHaveText('KWD 500.000');
});
```

**3. Contract test — asserting a real 403 response validates against the OpenAPI-documented error
schema, not just the 2xx paths (contract testing must cover error responses too, or the contract
silently under-specifies what clients should expect on failure).**

```php
it('validates the documented 403 response schema for a permission-denied journal void', function () {
    Spectator::using('qayd-api-v1.yaml');
    $company = companyWithChartOfAccounts();
    $entry = JournalEntry::factory()->for($company, 'company')->posted()->create();
    actingAsCompanyUser($company, permissions: ['accounting.read']); // no journal.void

    postJson("/api/v1/accounting/journal-entries/{$entry->id}/void", [], ['X-Company-Id' => $company->id])
        ->assertValidResponse(403);
});
```

**4. Eliminating flakiness — the three sources QAYD explicitly guards against, with the fix applied
in the test helper layer so individual authors don't have to remember it.**

```php
// Source 1: wall-clock time. Fix: always pin the clock explicitly.
beforeEach(fn () => Carbon::setTestNow('2026-07-16 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

// Source 2: real outbound HTTP (webhooks, AI engine calls, bank aggregator calls).
// Fix: Http::fake() by default in the base TestCase; tests that need to assert
// a specific outbound call opt into asserting on the fake, never hit the network.
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // fails loudly if a test forgets to fake an HTTP call
    }
}

// Source 3: queue timing. Fix: Queue::fake()/Bus::fake() in any test asserting
// side effects of an async job, and a separate, explicitly-marked integration
// test (tagged @group queue-worker) that runs a real worker against a real
// queue connection in CI's dedicated queue-worker job, not inlined into the
// fast feature suite.
it('processes a queued webhook job end-to-end against a real worker', function () {
    $this->artisan('queue:work', ['--once' => true, '--queue' => 'webhooks'])
        ->assertExitCode(0);
})->group('queue-worker');
```

**5. k6 result assertion feeding back into a Pest-run integrity check** — the two tools hand off:
k6 generates concurrent load and asserts HTTP-level behavior (200/409, never 500); a Pest test run
immediately after (`tests/Performance/PostLoadIntegrityTest.php`, run via `pest --group=post-load`
in the pre-release job, never in the normal suite) asserts the database converged to a valid state:

```php
it('leaves the ledger balanced after the concurrency scenario has run', function () {
    $entry = JournalEntry::findOrFail((int) env('QAYD_TARGET_ENTRY_ID'));

    expect($entry->status)->toBe('posted');
    expect(JournalEntry::where('id', $entry->id)->count())->toBe(1); // never duplicated
    expect($entry->lines()->sum('debit'))->toEqual($entry->lines()->sum('credit'));
})->group('post-load');
```

# End of Document

