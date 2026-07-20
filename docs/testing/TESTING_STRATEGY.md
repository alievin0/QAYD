# Testing Strategy — QAYD Testing
Version: 1.0
Status: Design Specification
Module: Testing
Submodule: TESTING_STRATEGY
---

# Purpose

This document is the entry point to `docs/testing/` and the authoritative statement of how QAYD proves
itself correct before any change reaches a customer's ledger. QAYD is an AI Financial Operating System:
a multi-tenant, double-entry accounting core wrapped in an AI layer, split across three independently
deployable codebases — a Laravel 12 / PHP 8.4 backend, a Next.js 15 / React 19 web frontend, and a
separate FastAPI / Python AI engine. Each has its own runner, its own idioms, and its own failure
modes; none of them is safe to test in isolation as if the other two did not exist, because the
invariants that matter most — no cross-tenant leakage, `debit = credit`, an AI proposal never
auto-committing a sensitive action — are enforced at seams *between* the codebases.

This document defines the whole-platform test strategy: the layered test pyramid and what each layer
owns across the three codebases; the coverage targets and what "covered" means in a system where a
missed test is a wrong debit rather than a cosmetic bug; the test-data, factory, and per-tenant
seeding approach; the test-database and isolation model; the CI pipeline stages and the quality gates
that block a merge; the flaky-test policy; and the four-variant visual-regression rule that governs
every screen. It is deliberately the *index* the other two testing documents specialize:
[UNIT_TESTS.md](./UNIT_TESTS.md) drills into per-codebase unit testing, and
[INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md) drills into API-level, cross-codebase, and database
integration testing. Where those documents go deep on one layer, this one stays wide and never
duplicates their content — it points to it.

Everything here assumes the platform facts fixed in [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md):
Laravel 12 / PHP 8.4+, PostgreSQL with Row-Level Security, Redis, `/api/v1/` REST JSON with the
standard envelope, Sanctum + JWT auth, `X-Company-Id` tenant scoping, dotted RBAC permission keys,
English/Arabic with full RTL, light/dark theming, and a FastAPI engine that reasons but never writes
to the database. No test convention in this folder invents a different platform fact.

# Scope

This strategy governs every automated test in the three first-party codebases and the contracts
between them. It covers what is tested, at which layer, with which tool, and which gate blocks a merge
when it fails.

In scope:

- The Laravel backend: services, actions, the posting engine, policies, value objects, API feature
  tests through the full middleware stack, RLS/tenant-isolation tests, migration tests.
- The Next.js frontend: components, hooks, Zod schemas, formatters, TanStack Query integration with a
  mocked API, and Playwright end-to-end plus four-variant visual regression.
- The FastAPI AI engine: deterministic tool functions, confidence normalization, the human-in-the-loop
  proposal contract, and the engine ↔ backend contract fixtures shared by both sides.
- The cross-codebase seams: the `/api/v1` envelope contract, the `POST /api/v1/ai/proposals` proposal
  gateway contract, and the OpenAPI document treated as an executable contract.
- The CI pipeline, its required gates (`lint`, `typecheck`, `test`, `test:e2e`, `i18n:check`, and
  their backend/AI equivalents), coverage thresholds, and the flaky-test quarantine process.

Out of scope for this folder, cross-referenced where relevant:

- Agent *quality* evaluation — whether the model's answer is *good* — lives with the FastAPI engine's
  own eval harness (see [# The AI-Eval Layer](#the-ai-eval-layer)); this folder proves the *boundary*
  around the model is safe, not that the model is smart.
- Manual QA scripts, exploratory-testing charters, and release sign-off checklists, which live in the
  QA runbook, not the automated suite.
- Load-test capacity planning targets (the numbers themselves), owned by the SRE performance budget in
  [../api/API_TESTING.md](../api/API_TESTING.md); this document owns only *that* load tests exist, run,
  and gate.

# Tooling

Each codebase tests with the runner idiomatic to its language, and every layer authenticates against
the same seeded permission/role model so a permission bug can never hide behind a more permissive test
user in one layer.

| Codebase | Layer | Tool | Notes |
|---|---|---|---|
| Backend (Laravel) | Unit | Pest (PHPUnit runner) | Pure PHP, no DB, no HTTP; services, actions, value objects, `AutonomyResolver`. |
| Backend | Feature / Integration | Pest + `Illuminate\Foundation\Testing\TestCase` | Full HTTP kernel, real test PostgreSQL with RLS, real middleware/auth/validation. |
| Backend | Contract | Spectator (Pest plugin) + Schemathesis | Real responses validated against `openapi/qayd-api-v1.yaml`; nightly fuzz. |
| Backend | Architecture | Pest arch tests | Structural invariants: every tenant model uses `BelongsToCompany`, no repo uses `DB::table`. |
| Backend | Static analysis | PHPStan (max), Laravel Pint | Type-level correctness and PSR-12 formatting, run as their own gates. |
| Frontend (Next.js) | Unit / Component | Vitest + Testing Library | Components, hooks, Zod schemas, derived totals, formatters; jsdom environment. |
| Frontend | Integration | Vitest + Testing Library + MSW | TanStack Query hooks against a mocked `/api/v1` served by Mock Service Worker. |
| Frontend | E2E + Visual regression | Playwright | Critical journeys against an ephemeral env; four screenshot variants per covered screen. |
| Frontend | Static analysis | ESLint (typescript-eslint, jsx-a11y, react-hooks), `tsc --noEmit`, Prettier | Lint, typecheck, and deterministic Tailwind class order as separate gates. |
| Frontend | i18n | `i18n:check` script | Fails if a key exists in `en.ts` but not `ar.ts`, or vice versa. |
| AI engine (FastAPI) | Unit | pytest | Deterministic tools, confidence normalization, prompt-assembly scoping; no live model calls. |
| AI engine | Contract | pytest + shared JSON Schema | The `ai/proposals` request/response validated against the same fixture the backend tests against. |
| AI engine | Static analysis | ruff, mypy (strict) | Lint and type-check as separate gates. |
| Cross-cutting | Load | k6 | Smoke on every PR (blocking), full ramp nightly, soak pre-release. |
| Cross-cutting | Security | Static + dependency scans, RLS negative suite | Secret scanning, dependency audit, and the dedicated tenant-isolation suite. |

This tool matrix is fixed. Changing a runner or adding a layer is an architectural decision recorded
here and in [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md), not a per-PR choice.

# Conventions & Structure

## The pyramid, and why it is enforced not aspirational

QAYD's test pyramid has seven bands. The lower bands are cheap, numerous, and run on every commit; the
upper bands are expensive, few, and gated at merge or release. The shape is enforced: a CI audit flags
directories that have grown top-heavy because a team found unit testing inconvenient, and no upper band
is ever accepted as a substitute for a lower one (an E2E test that happens to exercise `POST
/api/v1/accounting/journal-entries` does not excuse that endpoint from a Pest feature test).

```
                          ▲
                         / \        AI-Eval — engine quality (FastAPI repo, non-gating here)
                        /----\
                       / Sec  \     Security & RLS negative suite — isolation, secrets, deps
                      /--------\
                     /   Load   \   k6 — smoke (PR, blocking), ramp (nightly), soak (pre-release)
                    /------------\
                   /     E2E      \  Playwright + 4-variant visual — critical journeys only
                  /----------------\
                 /    Contract       \ Spectator/Schemathesis + shared AI-proposal schema
                /---------------------\
               /   Integration          \ Pest feature (full stack), Vitest+MSW, migration/RLS
              /--------------------------\
             /          Unit               \ Pest, Vitest, pytest — fast, isolated, every commit
            /-------------------------------\
```

| Band | Owns | Codebases | Runs on |
|---|---|---|---|
| Unit | Pure logic in isolation | Backend, Frontend, AI | Every commit |
| Integration | A unit wired to its real neighbors (DB, query cache, middleware) | Backend, Frontend, AI↔Backend | Every commit |
| Contract | The shapes crossing a codebase boundary | Backend↔all clients, AI↔Backend | Every commit (validate), nightly (fuzz) |
| E2E | A whole user journey through the browser | Frontend → real API | PR smoke subset, full nightly |
| Load | Latency/throughput under concurrency | Backend | PR smoke (blocking), nightly, pre-release |
| Security | Isolation, secrets, dependency CVEs | All | Every PR + scheduled |
| AI-Eval | Model answer quality | AI engine | Scheduled in the FastAPI repo (non-gating here) |

Target composition of the total automated-test *count* (not effort): approximately 60% unit, 26%
integration, 6% contract, 4% E2E, 2% load scenarios, 2% dedicated security/isolation. The exact
numbers are less important than the shape: most confidence comes from fast, isolated tests, and the
slow layers stay deliberately small because they are the most expensive and flakiest to maintain.

## What each layer owns across the three codebases

| Layer | Laravel backend | Next.js frontend | FastAPI AI engine |
|---|---|---|---|
| Unit | Services, actions, `PostingService` balance invariant, value objects (`Money`, `ExchangeRate`), policies, `AutonomyResolver` | Components, hooks in isolation, Zod schemas, derived totals, `Intl` formatters | Deterministic tool functions, confidence normalization, prompt-context scoping, retry/backoff logic |
| Integration | `/api/v1` feature tests through auth→tenant→RBAC→validation→service→DB→envelope; RLS negative tests; migration tests | TanStack Query hooks + components against MSW-mocked API; router/layout wiring | Engine ↔ backend proposal round-trip against a fake Laravel; tool-dispatch against a stubbed HTTP client |
| Contract | Spectator/Schemathesis vs OpenAPI; the `ai/proposals` request/response schema | The typed `envelope.ts` and endpoint functions vs the same OpenAPI types | The `ai/proposals` payload vs the shared JSON Schema fixture |
| E2E | (Provides the seeded ephemeral API E2E runs against) | Playwright journeys + four-variant visual regression | (Exercised end-to-end via the backend during AI-flow E2E) |
| Load | k6 against ephemeral API | (n/a — measured via the API) | (n/a — engine latency observed through the backend) |
| Security | RLS/tenant-isolation suite, permission matrix, secret scanning | XSS-in-render checks, no-secret-in-bundle scan | Injection-defense of the proposal payload, no model key in logs |

The three most load-bearing invariants each have a *named home* in this matrix so they cannot be
orphaned: **tenant isolation** is owned by the backend integration + security layers
([INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md) `# Tenant Isolation`), **`debit = credit` and posted
immutability** by the backend unit + integration layers ([UNIT_TESTS.md](./UNIT_TESTS.md) `# The
Posting Engine`), and **AI human-in-the-loop** by the backend unit (`AutonomyResolver`) + contract
layers plus the AI engine's own unit tests.

## Directory layout

```text
backend/tests/
├── Unit/            # Pest unit — no DB, no HTTP
├── Feature/
│   ├── Api/V1/<Module>/     # one file per controller
│   ├── Security/            # TenantIsolation*Test, PermissionMatrixTest
│   └── Rls/                 # raw-SQL RLS negative tests
├── Architecture/    # arch() structural invariants
├── Contract/        # Spectator specs
├── Performance/     # k6 scripts (*.js)
└── Support/         # ActingAs, CompanyTestContext helpers

frontend/tests/
├── unit/            # Vitest — components, hooks, schemas, formatters
├── integration/     # Vitest + MSW — query hooks against a mocked API
└── e2e/             # Playwright specs + visual-regression baselines

ai/tests/
├── unit/            # pytest — tools, confidence, scoping
├── contract/        # proposal-schema round-trip
└── fixtures/        # shared JSON Schema + golden payloads
```

## Naming

| Codebase | Unit of test | Convention | Example |
|---|---|---|---|
| Backend | Feature file | `<Controller>Test.php` | `JournalEntryTest.php` |
| Backend | Test case | `it('does X', ...)` present-tense behavior | `it('rejects an unbalanced journal entry with 422')` |
| Frontend | Spec file | `<subject>.spec.ts(x)` colocated by feature | `journal-line-grid.spec.tsx` |
| Frontend | E2E file | `<flow>.spec.ts` by user journey | `journal-entry-posting.spec.ts` |
| AI engine | Test file | `test_<subject>.py` | `test_confidence_normalization.py` |

# Patterns

## Per-tenant fixtures are the unit of setup

Because tenant isolation is the invariant QAYD cannot regress, the *default* test fixture is never a
bare row — it is a fully usable company. The backend's `companyWithChartOfAccounts()` builder composes
a company, its seeded chart of accounts, and an admin user in one call, and the isolation suite always
constructs *two* companies so a leak has somewhere to leak to. Every layer inherits this discipline:
the frontend's MSW handlers key their fixtures by `X-Company-Id`, and the AI engine's proposal
fixtures carry an explicit `company_id` that the backend contract test validates against the header.

```php
// backend — the canonical two-tenant setup that every isolation test starts from
$companyA = companyWithChartOfAccounts();
$companyB = companyWithChartOfAccounts();
// A resource that exists but is not the caller's — the thing a leak would expose.
$foreignInvoice = Invoice::factory()->for($companyB, 'company')->posted()->create();
```

## Factories default to valid; invalidity is opt-in

Factories produce a *valid, postable* state by default; every invalid state is an explicit, named
factory state (`->unbalanced()`, `->released()`, `->missingRequiredField()`) so a reader can tell at a
glance which tests exercise the happy path and which deliberately construct a failure. The same
principle holds in the frontend (a schema fixture is valid unless a test names the invalid field) and
the AI engine (a proposal fixture clears its confidence bar unless a test lowers it). Full mechanics
are in [UNIT_TESTS.md](./UNIT_TESTS.md) `# Fixtures & Factories` and
[INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md) `# Data Setup`.

## Every layer authenticates through the real guard

No layer uses a test-only auth bypass that could hide a real authentication or authorization bug.
Backend feature tests go through Sanctum/JWT exactly as production traffic does; frontend E2E signs in
through the real `/login` screen against the seeded staging user; the AI proposal contract test
authenticates with the `ai_agent` service scope. This is why a permission regression surfaces in the
permission-matrix test rather than silently passing because one layer ran as an over-privileged user.

## Time and randomness are pinned

Time-dependent assertions pin the clock (`Carbon::setTestNow(...)` in PHP,
`vi.setSystemTime(...)` in Vitest, `freezegun` in pytest) rather than trusting wall-clock timing, and
seeded RNG makes factory output reproducible. This eliminates a whole class of CI flakiness before the
flaky-test policy ever has to engage.

# What to Test / Coverage

## Coverage targets

Coverage is a floor, not a goal: a green badge on code that never exercises a permission boundary or a
money-math path is worthless. QAYD therefore pairs a line/branch floor with *named mandatory scenarios*
that must exist regardless of what the percentage says.

| Codebase | Line floor | Branch floor | Mandatory-scenario rule |
|---|---|---|---|
| Backend — money/posting/tenancy paths | 95% | 90% | Every posting path, every RLS policy, every sensitive endpoint's 403 path has an explicit test. |
| Backend — overall | 85% | 80% | Every route in `routes/api.php` has ≥1 feature test asserting the full envelope; every FormRequest rule has a 422 test asserting the `code`. |
| Frontend — overall | 80% | 75% | Every screen ships ≥1 Playwright happy-path spec and ≥1 Vitest spec for its riskiest client logic (usually a Zod schema or a derived total). |
| Frontend — visual regression | n/a | n/a | Every covered screen has all four variants (light/dark × LTR/RTL) baselined. |
| AI engine — deterministic tools | 90% | 85% | Every tool function tested for its typed output; `AutonomyResolver`'s equivalent in the engine tested across the full sensitive-op truth table. |

The financial and tenancy paths carry the highest floor deliberately: they are the paths where a
regression is a wrong number in a real ledger, not a UI annoyance.

## The three invariants, and the tests that prove them

| Invariant | Proven by | Home document |
|---|---|---|
| No cross-tenant leakage | Two-company isolation feature tests (A gets 404 for B's id), the permission matrix, RLS raw-SQL negative tests, an arch test that every `company_id` model uses `BelongsToCompany` | [INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md) |
| `debit = credit` and posted immutability | `PostingService` unit tests (unbalanced draft throws), a feature test that posting materializes balanced ledger rows, an RLS test that a posted line cannot be updated even by a privileged role via raw SQL | [UNIT_TESTS.md](./UNIT_TESTS.md) `# The Posting Engine` |
| AI never auto-commits a sensitive action | `AutonomyResolver` exhaustive unit truth table, a proposal-gateway feature test that a `bank.transfer` at 0.99 confidence still requires approval, the shared proposal-schema contract test | [UNIT_TESTS.md](./UNIT_TESTS.md) + `# Contract Testing` below |

## The AI-Eval layer

The top band — whether the model's *answer* is good — is not a gate in this folder. It runs as a
scheduled eval harness in the FastAPI repo against golden datasets and reports drift, and it is
explicitly non-blocking for backend/frontend merges because model quality is a tuning surface, not a
correctness boundary. What *is* gated here is the boundary around the model: that a low-confidence
field is flagged not guessed, that the engine cannot exceed the initiating human's permission scope,
and that a sensitive proposal is forced to `requires_approval` regardless of confidence — all of which
are deterministic and live in the unit/contract bands above.

# CI Integration

## Pipeline stages

CI runs as a fan-out of per-codebase jobs that share nothing but the seeded permission/role model, then
a cross-codebase contract stage, then the slow bands. A merge to a protected branch requires every
blocking gate green.

```
                 ┌─ backend:  pint → phpstan → pest-unit → pest-feature(+spectator) → arch → rls
 PR opened ──────┼─ frontend: lint → typecheck → i18n:check → vitest → vitest-integration(MSW)
                 └─ ai:       ruff → mypy → pytest-unit → proposal-contract
                                        │
                                        ▼
                          contract stage: OpenAPI drift diff + shared AI-proposal schema
                                        │
                                        ▼
                          e2e stage: Playwright smoke (4-variant on changed screens)
                                        │
                                        ▼
                          load stage: k6 smoke (blocking) ; security: dep-audit + secret-scan
                                        │
                                        ▼
                                   merge allowed
```

## Required gates

Every row below must pass before merge. None is an optional "nice to have"; the frontend's five gates
(`lint`, `typecheck`, `test`, `test:e2e`, `i18n:check`) are matched by equivalent backend and AI gates.

| Gate | Command | Codebase | Blocking |
|---|---|---|---|
| Backend format | `vendor/bin/pint --test` | Backend | Yes |
| Backend static analysis | `vendor/bin/phpstan analyse` | Backend | Yes |
| Backend unit + feature | `php artisan test` (Pest, incl. Spectator) | Backend | Yes |
| Backend architecture | Pest `arch()` suite | Backend | Yes |
| Backend RLS/isolation | `php artisan test --group=rls,isolation` | Backend | Yes |
| Frontend lint | `npm run lint` | Frontend | Yes |
| Frontend typecheck | `npm run typecheck` (`tsc --noEmit`) | Frontend | Yes |
| Frontend unit + integration | `npm run test` | Frontend | Yes |
| Frontend E2E | `npm run test:e2e` | Frontend | Yes |
| Frontend i18n parity | `npm run i18n:check` | Frontend | Yes |
| AI lint | `ruff check` | AI | Yes |
| AI typecheck | `mypy --strict` | AI | Yes |
| AI unit + contract | `pytest` | AI | Yes |
| OpenAPI drift | Scramble-derived spec vs committed spec diff | Cross | Yes |
| Load smoke | `k6 run tests/Performance/smoke-*.js` | Backend | Yes |
| Dependency + secret scan | `composer audit` / `npm audit` / `pip-audit` + secret scan | All | Yes |
| Coverage floor | Per-codebase threshold (see `# What to Test`) | All | Yes |
| Schemathesis fuzz | `schemathesis run ...` | Backend | Pre-release only |
| Load ramp/soak | `k6 run tests/Performance/load-*.js` | Backend | Nightly (non-blocking) |
| AI-eval | Engine golden-set eval | AI | Scheduled (non-blocking) |

## Example GitHub Actions job (backend)

```yaml
# .github/workflows/backend.yml (excerpt)
jobs:
  backend:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env: { POSTGRES_DB: qayd_test, POSTGRES_PASSWORD: postgres }
        options: >-
          --health-cmd pg_isready --health-interval 5s --health-timeout 5s --health-retries 5
      redis:
        image: redis:7
    env:
      DB_CONNECTION: pgsql
      DB_DATABASE: qayd_test
      DB_USERNAME: postgres
      DB_PASSWORD: postgres
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", coverage: pcov }
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/pint --test           # format gate
      - run: vendor/bin/phpstan analyse        # static-analysis gate
      - run: php artisan migrate --force       # applies RLS migrations to the test DB
      - run: php artisan test --coverage --min=85   # unit + feature + spectator + arch + rls
```

# Examples

## A representative slice of every band

The following names one concrete test per band so a reader can trace the pyramid end to end for a
single feature — posting a journal entry — across all three codebases.

```php
// UNIT (backend) — the balance invariant in isolation, no DB
it('rejects an unbalanced draft before it can touch the ledger', function () {
    $draft = JournalDraft::make()->debit('1000', '120.0000')->credit('4000', '100.0000');
    expect(fn () => app(PostingService::class)->post($draft))
        ->toThrow(UnbalancedEntryException::class);
});
```

```php
// INTEGRATION (backend) — the full stack + tenant isolation in one assertion
it('posts through /api/v1 and never exposes another company\'s entry', function () {
    $companyA = companyWithChartOfAccounts();
    $companyB = companyWithChartOfAccounts();
    $foreign = JournalEntry::factory()->for($companyB, 'company')->posted()->create();

    actingAsCompanyUser($companyA, permissions: ['accounting.journal.post']);
    getJson("/api/v1/accounting/journal-entries/{$foreign->id}", ['X-Company-Id' => $companyA->id])
        ->assertStatus(404)                       // exists, not yours → 404, never 403
        ->assertJsonFragment(['code' => 'RESOURCE_NOT_FOUND']);
});
```

```tsx
// UNIT (frontend) — the derived total the line grid renders, in isolation
import { describe, it, expect } from "vitest";
import { deriveBalance } from "@/lib/accounting/derive-balance";

describe("deriveBalance", () => {
  it("flags an unbalanced set of lines the same way the backend will", () => {
    const result = deriveBalance([
      { debit: "120.0000", credit: "0.0000" },
      { debit: "0.0000", credit: "100.0000" },
    ]);
    expect(result.balanced).toBe(false);
    expect(result.difference).toBe("20.0000");
  });
});
```

```python
# UNIT (AI engine) — a sensitive op is forced to requires_approval regardless of confidence
def test_bank_transfer_is_never_auto(resolver):
    draft = decision_draft(decision_type="bank.transfer", confidence=0.99,
                           reversibility="reversible", expected_impact=10.0)
    assert resolver.resolve(draft, settings(auto_post_confidence=0.7)) == "requires_approval"
```

```ts
// E2E (frontend) — the whole journey, asserted in all four variants
import { test, expect } from "@playwright/test";
import { VARIANTS } from "./support/variants";

for (const v of VARIANTS) { // light/LTR, light/RTL, dark/LTR, dark/RTL
  test(`post a journal entry — ${v.name}`, async ({ page }) => {
    await page.goto(`/accounting/journal-entries/new?theme=${v.theme}&lang=${v.lang}`);
    // ... fill a balanced entry, post it ...
    await expect(page.getByTestId("entry-status")).toHaveText(v.lang === "ar" ? "مُرحّل" : "Posted");
    await expect(page).toHaveScreenshot(`journal-entry-posted-${v.name}.png`);
  });
}
```

# Edge Cases

- **A green coverage badge with no isolation test.** Coverage percentage alone cannot catch a missing
  tenant boundary because the leaking code path *is* executed — just never asserted to be scoped. This
  is why the mandatory-scenario rules exist alongside the floor: the isolation and permission-matrix
  suites are required to exist regardless of the number.
- **An E2E test standing in for a feature test.** Rejected by policy: no upper band substitutes for a
  lower one. The reviewer checklist treats "the E2E covers it" as insufficient justification for a
  missing Pest feature test on a new endpoint.
- **Time-zone-dependent close/roll-over logic.** Any test touching fiscal-period boundaries, dose-style
  daily roll-over, or `Retry-After` windows must pin the clock; an unpinned time assertion is treated
  as a flaky test on sight and quarantined.
- **The GUC name mismatch across docs.** The RLS layer reads `app.company_id` while some AI-layer prose
  references `app.current_company_id`; the migration-test suite asserts the *actual* GUC the policies
  read, so tests are written against the deployed name, not against whichever doc a reader opened first.
  This discrepancy is tracked for reconciliation and does not change what the tests assert today.
- **RTL screenshots that "pass" because the diff tolerance is too loose.** Visual-regression baselines
  use a tight per-pixel threshold; a mirrored layout that fails to mirror is a diff, not a rounding
  artifact. Each of the four variants is an independent baseline — a shared baseline across directions
  would mask exactly the RTL regressions the four-variant rule exists to catch.
- **A flaky test masking a real race.** The flaky-test policy (below) quarantines to keep the suite
  green *and* files a ticket, because an intermittently failing tenant-isolation or posting test may be
  surfacing a genuine concurrency bug, not test noise.

## Flaky-test policy

1. **Detect.** CI records per-test pass/fail history. A test that fails then passes on retry without a
   code change is marked flaky automatically.
2. **Quarantine, don't ignore.** A flaky test is moved to a quarantined group that still runs and still
   reports, but does not block merge — and the move opens a tracking ticket in the same commit. It is
   never deleted or `skip`-annotated silently.
3. **Budget.** The quarantine has a hard cap (a small fixed number per codebase). Exceeding it fails
   the build with "flaky budget exceeded" — flakiness is a debt with a ceiling, not an accumulating
   backlog.
4. **Root-cause, then restore.** A quarantined test is fixed (pin time, seed RNG, await the real
   condition instead of sleeping, tighten the MSW handler) and returned to the blocking suite, or
   deleted with a written justification. Tests touching the three core invariants are highest priority
   and never left quarantined across a release.
5. **No blanket retries in the gate.** Automatic retry-until-green is banned on the blocking suite,
   because it converts a real race condition into a silent, occasionally-shipping bug. Retries are
   allowed only inside the quarantine group.

# Related Documents

- [UNIT_TESTS.md](./UNIT_TESTS.md) — unit testing across all three codebases: services, actions, the
  posting-engine balance invariant, value objects, policies (backend); components, hooks, Zod schemas,
  derived totals, formatters (frontend); deterministic tools and confidence normalization (AI engine).
- [INTEGRATION_TESTS.md](./INTEGRATION_TESTS.md) — API-level feature tests through the full middleware
  stack, tenant-isolation and permission-matrix suites, TanStack Query + MSW frontend integration, the
  AI-engine ↔ backend contract, and database migration/RLS-policy tests.
- [../api/API_TESTING.md](../api/API_TESTING.md) — the API layer's own test pyramid, contract testing,
  idempotency/webhook testing, and k6 load scripts.
- [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) — the Action/Service/DTO
  layering the unit tests target and why it is chosen for testability.
- [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md) — the proposal gateway, `AutonomyResolver`, and
  the human-in-the-loop boundary the AI tests prove.
- [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) — the RLS policies the
  migration/isolation suite verifies at the database level.
- [../frontend/README.md](../frontend/README.md) — the frontend quality gates, four-variant visual
  rule, and Vitest/Playwright conventions this strategy indexes.
- [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)

# End of Document
