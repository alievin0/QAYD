# Integration Tests — QAYD Testing
Version: 1.0
Status: Design Specification
Module: Testing
Submodule: INTEGRATION_TESTS
---

# Purpose

Integration tests are the band of QAYD's test pyramid (see [TESTING_STRATEGY.md](./TESTING_STRATEGY.md))
where a unit meets its real neighbors: the database, the middleware stack, the query cache, the network
seam between codebases. Where [UNIT_TESTS.md](./UNIT_TESTS.md) proves a service or a schema in
isolation, this document proves that when those pieces are wired together — a request travels through
auth, tenant resolution, RBAC, validation, the service layer, PostgreSQL with Row-Level Security, and
back out through the response envelope — the system as a whole upholds its invariants. This is the
highest-leverage band in a financial platform: a bug caught in a backend feature test is a bug
prevented in every one of QAYD's four API surfaces at once, and a tenant-isolation test that fails
loudly in CI is a cross-company data leak that never reaches a customer's ledger.

This document specifies integration testing across the seams QAYD cannot afford to get wrong:

- **Backend API-level tests** hitting `/api/v1` through the *full* middleware stack against a real test
  PostgreSQL with RLS enabled — never a bypassed kernel, never SQLite.
- **Tenant isolation** as its own first-class suite: user A cannot read company B's resource (404, not
  403), aggregate endpoints never leak another tenant's totals, and queued jobs carry company context
  explicitly.
- **The permission matrix**, dataset-driven, so adding a sensitive endpoint forces the author to declare
  who may call it.
- **Mutation, event, and queue assertions** — a write emits the right event after commit, and a
  listener is safe to run twice.
- **Frontend integration** — TanStack Query hooks and the components that consume them, against an
  MSW-mocked `/api/v1` that speaks the real envelope.
- **AI-engine ↔ backend contract tests** — the `POST /api/v1/ai/proposals` gateway, proven against the
  shared schema both sides test against, including the human-in-the-loop and cross-tenant guards.
- **Database migration and RLS-policy tests** — that the policies exist, are forced, and actually deny
  a raw cross-tenant query.

Everything here assumes the platform facts in [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md)
and the service layering in [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md). No
example invents a convention that document does not already establish.

# Scope

In scope:

- Backend feature tests through the HTTP kernel: happy path plus every documented failure mode (401,
  403, 404, 409, 422) against the standard envelope, on a real test PostgreSQL with RLS.
- The dedicated tenant-isolation suite and the architecture tests that keep it from being forgotten.
- The permission/role matrix and maker-checker/self-approval tests.
- Event, listener, and queue-dispatch assertions tied to mutations.
- Frontend integration: query hooks + components against MSW; cache invalidation and optimistic-update
  behavior; the envelope-unwrapping seam.
- AI-engine ↔ backend contract round-trips against a fake engine client and the shared JSON Schema.
- Migration tests: RLS enablement/forcing, GUC helper functions, and raw-SQL policy denial.

Out of scope (owned elsewhere):

- Pure logic with no real collaborator — [UNIT_TESTS.md](./UNIT_TESTS.md).
- Whole-journey browser tests and four-variant visual regression — the frontend E2E layer (see
  [TESTING_STRATEGY.md](./TESTING_STRATEGY.md) `# The pyramid`).
- OpenAPI contract fuzzing and k6 load — [../api/API_TESTING.md](../api/API_TESTING.md).
- Model answer quality — the FastAPI eval harness (non-gating here).

# Tooling

| Seam | Tool | Runs against |
|---|---|---|
| Backend API | Pest + `Illuminate\Foundation\Testing\TestCase` + `RefreshDatabase` | Full HTTP kernel, real test PostgreSQL with RLS, real middleware/auth/validation |
| Backend RLS | Pest + raw `DB::table(...)` / `DB::statement(...)` | The policies themselves, via connections that set the session GUCs |
| Backend architecture | Pest `arch()` | Static structural invariants (every tenant model uses `BelongsToCompany`) |
| Frontend integration | Vitest + Testing Library + MSW (Mock Service Worker) | Query hooks + components against a mocked `/api/v1` speaking the real envelope |
| AI contract | Pest (backend side) + pytest (engine side) + shared JSON Schema fixture | The proposal request/response wire both sides must agree on |
| Migrations | Pest against a freshly-migrated test DB | `information_schema` / `pg_policies` assertions |

A backend integration test runs against **real PostgreSQL**, never SQLite: RLS, `NUMERIC(19,4)`
arithmetic, cursor pagination, and lock behavior do not exist or behave differently in SQLite, and a
test that passes on SQLite but fails on Postgres is worse than no test. The frontend integration layer
uses MSW rather than a real backend so it stays fast and deterministic, while still exercising the real
envelope shape and the real query-cache behavior.

# Conventions & Structure

## Directory layout

```text
backend/tests/
├── Feature/
│   ├── Api/V1/<Module>/         # one file per controller: JournalEntryTest.php
│   ├── Security/                # TenantIsolationTest, PermissionMatrixTest
│   └── Rls/                     # raw-SQL policy negative tests
├── Architecture/               # TenantScopeTest and friends
├── Migrations/                 # RLS enablement + policy-existence tests
└── Support/                    # ActingAs, CompanyTestContext

frontend/tests/integration/
├── handlers/                   # MSW request handlers keyed by X-Company-Id
├── hooks/                      # useJournalEntries.spec.tsx, useReconciliation.spec.tsx
└── flows/                      # multi-component wiring against MSW

ai/tests/contract/
├── test_proposal_roundtrip.py
└── fixtures/proposal.schema.json   # the shared schema the backend also tests against
```

## The full-stack rule

Every backend integration test goes through `postJson()`/`getJson()`/`patchJson()`/`deleteJson()` —
never `Route::dispatch()` — so auth, `X-Company-Id` resolution, RLS GUC setup, rate limiting, and
FormRequest validation are exercised exactly as production traffic experiences them. Every test asserts
the *full envelope shape*, not just the status code: a test that only checks `assertStatus(200)` would
miss a regression that silently dropped `meta.pagination`.

## The two-company default

Because tenant isolation is the invariant QAYD cannot regress silently, the default fixture for any
read/list/detail test constructs *two* companies, so a leak always has somewhere to leak to. The
`companyWithChartOfAccounts()` builder and the `actingAsCompanyUser()` helper (from
[../api/API_TESTING.md](../api/API_TESTING.md) `# Data Setup`) are reused verbatim so every layer
authenticates through the same seeded role model.

## Data Setup

Feature tests use `RefreshDatabase` (migrate + transaction-wrap each test), and `tests/Pest.php` seeds
the permission and account-type foundations once per test so every case starts from the same known-good
baseline:

```php
// tests/Pest.php
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([PermissionSeeder::class, AccountTypeSeeder::class]);
    })->in('Feature');
```

Factories default to a valid, postable state; invalid states are explicit (`->unbalanced()`,
`->posted()`, `->released()`). Per-tenant fixtures (a company plus its chart of accounts plus an admin)
are one call, so the Arrange block of an isolation test is two lines and the Assert block is the focus.

# Patterns

## Assert the boundary the layer is responsible for

A feature test proves the thin controller, the FormRequest, the policy, the service, and the envelope
cooperate; it proves tenant isolation (A gets 404 for B's resource) and default-deny (missing
permission → 403). It does *not* re-prove the balance invariant line by line — that is the unit layer's
job — but it *does* prove that posting through the real stack materializes balanced ledger rows, which
only a real database can show.

## Prove isolation with a resource that exists

The strongest isolation assertion uses a real, valid id belonging to another company: the resource
exists, the caller's token is valid, it simply is not theirs. The correct response is **404, not 403** —
a 403 would confirm the id exists, which is itself a cross-tenant information leak (an IDOR oracle).

## Fake the outbound, keep the inbound real

For events, queues, and webhooks, the inbound path (the mutation) runs for real and the outbound
side-effect is faked (`Event::fake`, `Queue::fake`, `Http::fake`) so the test asserts *that the right
effect was scheduled with the right tenant context* without standing up a real consumer.

## Mock the network at the edge, not the cache

Frontend integration tests mock `/api/v1` with MSW at the network boundary and let the *real* TanStack
Query client, the *real* hooks, and the *real* components run, so cache keys, invalidation, and
optimistic updates are exercised, not stubbed.

# What to Test / Coverage

## Backend API-level tests through the full stack

Every route in `routes/api.php` has at least one feature test asserting the happy path against the
envelope, and at least one per documented failure mode. The request travels the whole middleware chain:
`auth → resolve tenant (X-Company-Id) → RBAC → validation → service → DB (RLS) → envelope`.

```php
// tests/Feature/Api/V1/Accounting/JournalEntryTest.php

use App\Models\{Account, JournalEntry};
use function Pest\Laravel\{postJson, getJson};

beforeEach(function () {
    $this->company = companyWithChartOfAccounts();
    $this->user = actingAsCompanyUser($this->company, permissions: ['accounting.journal.create']);
});

it('creates a draft entry and returns the full standard envelope', function () {
    $cash = Account::where('code', '1000')->firstOrFail();
    $revenue = Account::where('code', '4000')->firstOrFail();

    postJson('/api/v1/accounting/journal-entries', [
        'entry_date' => '2026-07-16',
        'memo' => 'Cash sale — POS batch #4471',
        'currency_code' => 'KWD',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => '120.0000', 'credit' => '0.0000'],
            ['account_id' => $revenue->id, 'debit' => '0.0000', 'credit' => '120.0000'],
        ],
    ], ['X-Company-Id' => $this->company->id])
        ->assertCreated()
        ->assertJsonStructure([
            'success', 'data' => ['id', 'status', 'entry_date', 'currency_code', 'lines'],
            'message', 'errors', 'meta' => ['pagination'], 'request_id', 'timestamp',
        ])
        ->assertJson(['success' => true, 'data' => ['status' => 'draft'], 'errors' => []]);
});

it('posts a draft and materializes balanced ledger rows in the real DB', function () {
    $entry = JournalEntry::factory()->for($this->company)->draft()
        ->has(\App\Models\JournalLine::factory()->balancedPair('120.0000'), 'lines')
        ->create();

    actingAsCompanyUser($this->company, permissions: ['accounting.journal.post']);

    postJson("/api/v1/accounting/journal-entries/{$entry->id}/post", [],
        ['X-Company-Id' => $this->company->id])
        ->assertOk()->assertJsonPath('data.status', 'posted');

    // Only a real database shows the invariant materialized: debits == credits.
    $this->assertDatabaseCount('ledger_entries', 2);
    expect(\App\Models\LedgerEntry::where('journal_entry_id', $entry->id)->sum('debit'))
        ->toEqual(\App\Models\LedgerEntry::where('journal_entry_id', $entry->id)->sum('credit'));
});

it('rejects an unbalanced entry through the full validation stack with 422', function () {
    $cash = Account::where('code', '1000')->firstOrFail();

    postJson('/api/v1/accounting/journal-entries', [
        'entry_date' => '2026-07-16', 'currency_code' => 'KWD',
        'lines' => [['account_id' => $cash->id, 'debit' => '120.0000', 'credit' => '0.0000']],
    ], ['X-Company-Id' => $this->company->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonFragment(['code' => 'JOURNAL_UNBALANCED']);
});
```

## Tenant isolation

The isolation suite is dedicated (`tests/Feature/Security/`) and runs in addition to the isolation
assertions sprinkled through ordinary feature tests. It proves the boundary holds through the real
stack, and — separately — that RLS holds even when the ORM scope is bypassed (see `# Migration & RLS`).

```php
// tests/Feature/Security/TenantIsolationTest.php

it('returns 404, never 403, for another company\'s resource by valid id', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    $invoiceB = \App\Models\Invoice::factory()->for($companyB, 'company')->posted()->create();

    actingAsCompanyUser($companyA, permissions: ['sales.invoice.read']);

    // 403 would confirm the id exists — an IDOR oracle. Correct is 404.
    getJson("/api/v1/sales/invoices/{$invoiceB->id}", ['X-Company-Id' => $companyA->id])
        ->assertStatus(404)
        ->assertJsonFragment(['code' => 'RESOURCE_NOT_FOUND']);
});

it('rejects an X-Company-Id the user is not a member of', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    actingAsCompanyUser($companyA, permissions: ['accounting.read']);

    getJson('/api/v1/accounting/journal-entries', ['X-Company-Id' => $companyB->id])
        ->assertStatus(403)
        ->assertJsonFragment(['code' => 'COMPANY_CONTEXT_FORBIDDEN']);
});

it('never leaks company B totals into company A aggregate reports', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    postedInvoice($companyA, total: '500.0000');
    postedInvoice($companyB, total: '999999.0000'); // an obvious tell if it leaked

    actingAsCompanyUser($companyA, permissions: ['reports.read']);

    getJson('/api/v1/reports/trial-balance', ['X-Company-Id' => $companyA->id])
        ->assertOk()->assertJsonPath('data.total_debits', '500.0000');
});

it('ignores a filter naming another company\'s foreign key rather than leaking or erroring', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    $foreignCustomer = \App\Models\Customer::factory()->for($companyB, 'company')->create();
    actingAsCompanyUser($companyA, permissions: ['sales.invoice.read']);

    getJson("/api/v1/sales/invoices?customer_id={$foreignCustomer->id}", ['X-Company-Id' => $companyA->id])
        ->assertOk()->assertJson(['data' => []]); // matches nothing, never an information-leaking error
});
```

An architecture test keeps the trait from being forgotten as the schema grows:

```php
// tests/Architecture/TenantScopeTest.php

arch('every tenant-scoped model uses BelongsToCompany')
    ->expect(App\Models\Concerns\HasCompanyIdColumn::modelsWithCompanyId())
    ->toUseTrait(App\Models\Concerns\BelongsToCompany::class);

arch('repositories never bypass tenant scope with raw builders')
    ->expect('App\Repositories')
    ->not->toUse(['DB::table', 'Model::withoutGlobalScope']);
```

And queued jobs carry tenant context explicitly, because they run outside an HTTP request:

```php
it('scopes a queued webhook dispatch to the company that owns the triggering event', function () {
    Queue::fake();
    $company = companyWithChartOfAccounts();
    recordReceipt(postedInvoice($company, total: '250.0000'));

    Queue::assertPushed(\App\Jobs\DispatchWebhookJob::class,
        fn ($job) => $job->companyId === $company->id);
});
```

## The permission matrix

QAYD is default-deny, and the permission suite is a dataset-driven matrix so adding a sensitive endpoint
*forces* the author to declare which roles may call it — the dataset is the enforcement mechanism.

| Permission | Owner | CFO | Finance Manager | Senior Accountant | Accountant | Read Only | External Auditor |
|---|---|---|---|---|---|---|---|
| accounting.journal.create | Yes | Yes | Yes | Yes | Yes | No | No |
| accounting.journal.post | Yes | Yes | Yes | Yes | No | No | No |
| accounting.journal.void | Yes | Yes | Yes | No | No | No | No |
| bank.transfer | Yes | Yes | Yes | No | No | No | No |
| payroll.release | Yes | Yes | No | No | No | No | No |
| tax.submit | Yes | Yes | No | No | No | No | No |

```php
// tests/Feature/Security/PermissionMatrixTest.php

it('enforces the permission matrix on sensitive endpoints', function (
    string $path, string $method, string $permission, array $allowedRoles
) {
    $company = companyWithChartOfAccounts();

    foreach (['Owner', 'CFO', 'Finance Manager', 'Senior Accountant', 'Accountant',
              'Read Only', 'External Auditor'] as $role) {
        actingAsCompanyUser($company, role: $role);

        $response = $this->json($method, $path, minimalValidPayloadFor($path),
            ['X-Company-Id' => $company->id]);

        if (in_array($role, $allowedRoles, true)) {
            expect($response->status())->not->toBe(403);
        } else {
            $response->assertStatus(403)->assertJsonFragment(['code' => 'PERMISSION_DENIED']);
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

Maker-checker is proven through the stack: a sensitive action returns `202 pending_approval`, the
creator cannot self-approve, and a second authorized approver succeeds:

```php
it('creates a transfer as pending_approval and forbids self-approval', function () {
    $company = companyWithChartOfAccounts();
    $creator = actingAsCompanyUser($company, permissions: ['bank.transfer']);

    $create = postJson('/api/v1/banking/transfers', transferPayload(),
        ['X-Company-Id' => $company->id])->assertStatus(202);
    expect($create->json('data.status'))->toBe('pending_approval');

    postJson("/api/v1/banking/transfers/{$create->json('data.id')}/approve", [],
        ['X-Company-Id' => $company->id])
        ->assertStatus(403)->assertJsonFragment(['code' => 'SELF_APPROVAL_FORBIDDEN']);

    actingAsCompanyUser($company, permissions: ['bank.transfer']); // an independent approver
    postJson("/api/v1/banking/transfers/{$create->json('data.id')}/approve", [],
        ['X-Company-Id' => $company->id])
        ->assertOk()->assertJsonPath('data.status', 'approved');
});
```

## Mutation + event/queue assertions

A mutation must emit the right domain event *after commit* (so a rolled-back write never fires a
downstream ledger post), and cross-module listeners must be safe to run twice.

```php
// tests/Feature/Api/V1/Sales/InvoicePostingEventsTest.php

it('emits InvoicePosted after commit, driving the ledger listener exactly once', function () {
    Event::fake([\App\Events\Sales\InvoicePosted::class]);
    $company = companyWithChartOfAccounts();
    $invoice = \App\Models\Invoice::factory()->for($company, 'company')->draft()->withItems()->create();
    actingAsCompanyUser($company, permissions: ['sales.invoice.post']);

    postJson("/api/v1/sales/invoices/{$invoice->id}/post", [], ['X-Company-Id' => $company->id])
        ->assertOk();

    Event::assertDispatched(\App\Events\Sales\InvoicePosted::class,
        fn ($e) => $e->invoiceId === $invoice->id);
});

it('is idempotent when the ledger listener runs twice (at-least-once delivery)', function () {
    $company = companyWithChartOfAccounts();
    $invoice = postedInvoice($company, total: '400.0000');

    $listener = app(\App\Listeners\Accounting\CreateJournalForInvoice::class);
    $event = new \App\Events\Sales\InvoicePosted($invoice->id);

    $listener->handle($event);
    $listener->handle($event); // redelivery must not double-post

    expect(\App\Models\JournalEntry::where('source_type', 'invoice')
        ->where('source_id', $invoice->id)->count())->toBe(1);
});
```

## Frontend integration (TanStack Query + MSW)

Frontend integration tests mount the real query client, hooks, and components against an MSW-mocked
`/api/v1` that returns the real envelope. They prove the seam the unit layer cannot: envelope
unwrapping, cache keys, invalidation after a mutation, and optimistic updates.

```tsx
// tests/integration/hooks/use-journal-entries.spec.tsx
import { describe, it, expect, beforeAll, afterAll, afterEach } from "vitest";
import { setupServer } from "msw/node";
import { http, HttpResponse } from "msw";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useJournalEntries } from "@/hooks/use-journal-entries";
import { envelope } from "./support/envelope";

const server = setupServer(
  http.get("*/api/v1/accounting/journal-entries", ({ request }) => {
    // The mock enforces tenant scoping too: without the header there is no data.
    if (!request.headers.get("X-Company-Id")) return HttpResponse.json(envelope.forbidden(), { status: 403 });
    return HttpResponse.json(envelope.page([{ id: 1, status: "posted" }], { total: 1 }));
  }),
);

beforeAll(() => server.listen()); afterEach(() => server.resetHandlers()); afterAll(() => server.close());

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    {children}
  </QueryClientProvider>
);

describe("useJournalEntries", () => {
  it("unwraps the envelope's data and exposes pagination meta", async () => {
    const { result } = renderHook(() => useJournalEntries({ status: "posted" }), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.data).toHaveLength(1);
    expect(result.current.data?.meta.pagination.total).toBe(1);
  });

  it("surfaces a typed ApiError when the API returns success:false", async () => {
    server.use(http.get("*/api/v1/accounting/journal-entries",
      () => HttpResponse.json(envelope.error("RATE_LIMITED"), { status: 429 })));
    const { result } = renderHook(() => useJournalEntries({}), { wrapper });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect((result.current.error as { code?: string })?.code).toBe("RATE_LIMITED");
  });
});
```

```tsx
// tests/integration/flows/post-journal-entry.spec.tsx — mutation invalidates the list cache
it("invalidates the entries list after a successful post so the row flips to posted", async () => {
  let posted = false;
  server.use(
    http.post("*/api/v1/accounting/journal-entries/:id/post", () => {
      posted = true; return HttpResponse.json(envelope.item({ id: 1, status: "posted" }));
    }),
    http.get("*/api/v1/accounting/journal-entries",
      () => HttpResponse.json(envelope.page([{ id: 1, status: posted ? "posted" : "draft" }], { total: 1 }))),
  );
  // ... render the table, click Post, await the optimistic → confirmed transition ...
  await waitFor(() => expect(screen.getByTestId("entry-1-status")).toHaveTextContent(/posted/i));
});
```

## AI-engine ↔ backend contract

The engine never writes to the database; it calls back through `POST /api/v1/ai/proposals`, which runs
the identical FormRequest + RBAC + Service pipeline a human would. Contract tests are run on *both*
sides against the same shared schema so the two codebases cannot drift, and they prove the
human-in-the-loop and cross-tenant guards at the integration boundary.

```php
// tests/Feature/Api/V1/Ai/ProposalGatewayTest.php — backend side, real stack, faked engine

it('auto-executes a high-confidence reversible proposal through the owning Service', function () {
    $company = companyWithChartOfAccounts();
    actingAsAiAgent($company); // ai_agent service scope

    postJson('/api/v1/ai/proposals', validJournalProposal($company),
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'auto_executed');

    // Written by the owning Service, not a raw insert; audit marks it AI-assisted.
    $this->assertDatabaseHas('journal_entries', ['company_id' => $company->id, 'source_type' => 'bill']);
    $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'ai_assisted' => true]);
});

it('forces a bank.transfer proposal to requires_approval even at 0.99 confidence', function () {
    $company = companyWithChartOfAccounts();
    actingAsAiAgent($company);

    postJson('/api/v1/ai/proposals', bankTransferProposal($company, confidence: '0.9900'),
        ['X-Company-Id' => $company->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_approval');

    $this->assertDatabaseCount('transfers', 0); // no funds moved
    $this->assertDatabaseHas('approval_requests', ['company_id' => $company->id]);
});

it('rejects a proposal whose payload references another company as 403 COMPANY_MISMATCH', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    $foreignAccount = \App\Models\Account::factory()->for($companyB, 'company')->create();
    actingAsAiAgent($companyA);

    postJson('/api/v1/ai/proposals', proposalReferencingAccount($foreignAccount->id),
        ['X-Company-Id' => $companyA->id])
        ->assertStatus(403)->assertJsonFragment(['code' => 'COMPANY_MISMATCH']);

    // A cross-tenant proposal is a security signal, logged as a system audit event.
    $this->assertDatabaseHas('audit_logs', ['company_id' => $companyA->id, 'event_type' => 'system']);
});

it('is idempotent: two identical callbacks produce one decision and one write', function () {
    $company = companyWithChartOfAccounts();
    actingAsAiAgent($company);
    $key = (string) Str::uuid();

    $first = postJson('/api/v1/ai/proposals', validJournalProposal($company),
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])->assertCreated();
    $second = postJson('/api/v1/ai/proposals', validJournalProposal($company),
        ['X-Company-Id' => $company->id, 'Idempotency-Key' => $key])->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->assertDatabaseCount('ai_decisions', 1);
});
```

The engine side tests the same wire against the same schema fixture, so a field the backend requires can
never silently disappear from what the engine sends:

```python
# ai/tests/contract/test_proposal_roundtrip.py
import json, pathlib, jsonschema
from qayd_ai.gateway import build_proposal_payload

SCHEMA = json.loads((pathlib.Path(__file__).parent / "fixtures/proposal.schema.json").read_text())

def test_built_proposal_matches_the_shared_schema(sample_decision):
    payload = build_proposal_payload(sample_decision, company_id=4021)
    jsonschema.validate(payload, SCHEMA)  # same schema the backend validates against
    assert payload["confidence"] == "0.9400"          # fixed 4-dp string, not a float
    assert payload["proposed_action"]["permission_required"] == "accounting.journal.create"

def test_company_id_in_payload_must_match_header_contract(sample_decision):
    payload = build_proposal_payload(sample_decision, company_id=4021)
    # The engine never sets a company_id in the body that contradicts the routing header.
    assert payload["proposed_action"]["payload"].get("company_id") in (None, 4021)
```

## Database migration & RLS-policy tests

Migrations are integration-tested against a freshly-migrated test PostgreSQL: the policies must *exist*,
be *forced*, and actually *deny* a raw cross-tenant query. This is the layer that proves RLS holds even
when the ORM scope is absent — the whole reason RLS exists as a second line of defense (see
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) `# Why RLS`).

```php
// tests/Migrations/RlsEnablementTest.php

it('enables and forces RLS on every tenant table', function (string $table) {
    $row = DB::selectOne(
        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]
    );
    expect($row->relrowsecurity)->toBeTrue("RLS not enabled on {$table}");
    expect($row->relforcerowsecurity)->toBeTrue("RLS not FORCED on {$table}"); // the common misconfig
})->with(tenantTables()); // the same list the migration generator drives

it('registers the company-boundary RESTRICTIVE policy on each tenant table', function (string $table) {
    $policies = DB::select('SELECT policyname, permissive FROM pg_policies WHERE tablename = ?', [$table]);
    $names = collect($policies)->pluck('policyname');
    expect($names)->toContain("{$table}_company_boundary");
    expect(collect($policies)->firstWhere('policyname', "{$table}_company_boundary")->permissive)
        ->toBe('RESTRICTIVE'); // the un-bypassable floor, not a PERMISSIVE OR-able policy
})->with(tenantTables());
```

```php
// tests/Feature/Rls/JournalLinesRlsTest.php — the policy denies a RAW cross-tenant write

it('blocks a posted journal line from being updated even via raw SQL by a privileged role', function () {
    $entry = JournalEntry::factory()->posted()->for($this->companyA)->create();
    $line = JournalLine::factory()->for($entry)->create();

    actingAsCompanyUser($this->companyA, permissions: ['accounting.journal.create']);
    setSessionGucs($this->companyA->id); // SET LOCAL app.company_id / app.user_id, as middleware does

    // Raw DB — proves this is NOT merely a controller guard; RLS itself matches zero rows.
    $affected = DB::table('journal_lines')->where('id', $line->id)->update(['description' => 'tampered']);

    expect($affected)->toBe(0);
    $this->assertDatabaseHas('journal_lines', ['id' => $line->id, 'description' => $line->description]);
});

it('returns zero rows when a session queries another company under a mismatched GUC', function () {
    $rowB = JournalLine::factory()->for(
        JournalEntry::factory()->for($this->companyB)->create()
    )->create();

    setSessionGucs($this->companyA->id); // acting as A
    $visible = DB::table('journal_lines')->where('id', $rowB->id)->count();

    expect($visible)->toBe(0); // RLS hides B's row from A's session entirely
});
```

## Coverage expectations

| Seam | Requirement |
|---|---|
| Backend routes | Every route in `routes/api.php` has ≥1 feature test asserting the full envelope on the happy path, and ≥1 per documented failure mode. |
| Tenant isolation | Every module with a tenant resource has a 404-not-403 test and, for aggregate endpoints, a no-leak-into-totals test. |
| Permissions | Every sensitive endpoint appears in the permission-matrix dataset with its allowed roles. |
| Events/queues | Every money-affecting mutation asserts its post-commit event and its listener's twice-run safety. |
| Frontend | Every resource with a query hook has an integration test for envelope unwrapping and post-mutation invalidation. |
| AI contract | The proposal gateway is tested on both sides against the shared schema, including auto/suggest/approve routing, cross-tenant 403, and idempotency. |
| Migrations | Every tenant table is asserted RLS-enabled + forced + carrying the RESTRICTIVE boundary policy. |

# Examples

## One feature, traced through the integration band

Posting a sales invoice touches the most seams at once — validation, service, the ledger listener, the
event bus, and the audit trail — so it is the canonical end-to-end integration case:

```php
it('posts an invoice, creates its journal via the queued listener, and audits it — all scoped to one tenant', function () {
    Event::fake([\App\Events\Sales\InvoicePosted::class]);
    $company = companyWithChartOfAccounts();
    $invoice = \App\Models\Invoice::factory()->for($company, 'company')->draft()->withItems()->create();
    actingAsCompanyUser($company, permissions: ['sales.invoice.post']);

    postJson("/api/v1/sales/invoices/{$invoice->id}/post", [], ['X-Company-Id' => $company->id])
        ->assertOk()->assertJsonPath('data.status', 'posted');

    Event::assertDispatched(\App\Events\Sales\InvoicePosted::class);
    // Run the (normally queued) listener and assert the balanced journal it creates stays in-tenant.
    app(\App\Listeners\Accounting\CreateJournalForInvoice::class)
        ->handle(new \App\Events\Sales\InvoicePosted($invoice->id));

    $journal = \App\Models\JournalEntry::where('source_id', $invoice->id)->firstOrFail();
    expect($journal->company_id)->toBe($company->id);
    expect($journal->lines->sum('debit'))->toEqual($journal->lines->sum('credit'));
});
```

## The isolation test that would have caught a real leak

```php
// A regression that let a report aggregate across tenants (a missing company scope on a raw
// SUM query) is caught here because company B's deliberately huge total would appear in A's report.
it('a raw-SQL report path cannot sum across tenants', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    postedInvoice($companyA, total: '10.0000');
    postedInvoice($companyB, total: '1000000.0000');

    actingAsCompanyUser($companyA, permissions: ['reports.read']);
    getJson('/api/v1/reports/trial-balance', ['X-Company-Id' => $companyA->id])
        ->assertOk()->assertJsonPath('data.total_debits', '10.0000');
});
```

# Edge Cases

- **SQLite masquerading as the test DB.** Running feature tests on SQLite hides RLS, `NUMERIC(19,4)`
  behavior, and lock semantics; the integration suite must run on real PostgreSQL with the RLS
  migrations applied, and CI provisions a Postgres service for exactly this reason.
- **403 where 404 belongs.** A cross-tenant resource by valid id must return 404 — a 403 confirms
  existence and is itself an IDOR oracle. Isolation tests assert the status *code* explicitly, not just
  "not 200".
- **An event asserted before commit.** Asserting a domain event fired *inside* a transaction that later
  rolls back would pass while production never sends it; event tests assert *after-commit* dispatch, and
  listener tests run the listener twice to prove at-least-once safety.
- **MSW handlers that ignore the tenant header.** A frontend mock that returns data regardless of
  `X-Company-Id` lets a tenant-scoping bug pass; integration handlers key their fixtures on the header
  and return 403 without it, mirroring the backend.
- **The GUC-name discrepancy.** The RLS policies read `app.company_id`; some AI-layer prose references
  `app.current_company_id`. The RLS feature/migration tests set and assert the GUC the *policies
  actually read*, so tests track the deployed name rather than whichever doc a reader opened; the
  discrepancy is flagged for reconciliation and does not change current assertions.
- **A proposal that buys autonomy with confidence.** The gateway contract test asserts a sensitive
  operation (`bank.transfer`) at 0.99 confidence still routes to `requires_approval` with no write — the
  most important AI-boundary assertion, and one that only the full stack (resolver + gateway + DB) can
  prove end to end.
- **Idempotent retries double-writing.** A retried proposal or webhook with the same key must produce
  exactly one decision/one delivery; the count assertion (`assertDatabaseCount(..., 1)`) is the guard,
  because a silent double-post is a money-affecting correctness bug.
- **A new tenant table shipped without RLS.** The migration test drives the same table list the RLS
  generator uses and cross-checks `pg_class`/`pg_policies`, failing the build if a table has a
  `company_id` column but no forced RLS or no boundary policy.

# Related Documents

- [TESTING_STRATEGY.md](./TESTING_STRATEGY.md) — the pyramid, gates, coverage philosophy, and flaky-test
  policy this band runs under.
- [UNIT_TESTS.md](./UNIT_TESTS.md) — the isolated-logic band on the other side of the unit-vs-integration
  line, including the posting-engine and `AutonomyResolver` unit proofs these tests build on.
- [../api/API_TESTING.md](../api/API_TESTING.md) — the API layer's feature/contract/idempotency/webhook
  conventions and the `actingAsCompanyUser` / `companyWithChartOfAccounts` helpers reused here.
- [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) — the middleware/service layering
  these tests traverse and the transaction/event/idempotency rules they assert.
- [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) — the proposal gateway, autonomy routing, and
  cross-tenant guard the AI contract tests prove.
- [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) — the RLS policies, GUC contract,
  and `pg_policies` structure the migration/RLS tests verify.
- [../frontend/README.md](../frontend/README.md) — the TanStack Query + MSW conventions and the envelope
  contract the frontend integration tests exercise.

# End of Document
