# Sprint 01 — Foundations — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: Sprint 01
---

# Purpose / Goal

Sprint 01 lays the load-bearing foundation the rest of the MVP is built on top of, and nothing more.
It is the only sprint that ships no accounting feature, and that is deliberate: every invariant QAYD
promises — no cross-tenant leakage, `debit = credit`, an AI proposal that never auto-commits — is
enforced at seams that do not exist yet on day one. This sprint builds those seams first, so that
Sprint 02 can post a journal entry into a tenant that is already isolated, authenticated, authorized,
and observable.

Concretely, by the end of Sprint 01 the three codebases named in
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) — a Laravel 12 / PHP 8.4 backend, a
Next.js 15 / React 19 web frontend, and a FastAPI / Python AI engine — exist as a single monorepo with
a green CI pipeline; PostgreSQL carries the `companies` / `users` / `company_users` / `roles` /
`permissions` schema with Row-Level Security enforcing the tenant boundary at the storage engine; a
person can register, verify their email, create a company, sign in against the real Sanctum + JWT
guard, and land inside an app shell (sidebar, topbar, company switcher, light/dark theme, EN/AR with
full RTL) on an intentionally empty dashboard scoped to their seeded tenant.

The exit is small on the screen and enormous underneath it: **a signed-in user in a seeded tenant sees
an empty dashboard, and every layer that will later protect their ledger is already in place and
tested.** This document is the first entry in `docs/execution/` and establishes the sprint-doc shape
(objectives, a backlog of demoable stories, a definition of done, risks, and exit criteria) that
[SPRINT_02.md](./SPRINT_02.md), [SPRINT_03.md](./SPRINT_03.md), and [SPRINT_04.md](./SPRINT_04.md)
follow.

# Sprint duration & team

- **Duration:** 2 weeks (10 working days). Sprint 01 of an ~8-week MVP build (Sprints 01–04).
- **Ceremonies:** planning (day 1), daily stand-up, mid-sprint architecture review (day 5), demo +
  retro (day 10).
- **Team (the squad carried across all four sprints):**

| Role | Count | Primary focus this sprint |
|---|---|---|
| Tech Lead / Eng Manager | 1 | Monorepo shape, CI gates, RLS review, unblocks |
| Backend engineers (Laravel) | 2 | Schema + RLS + Auth/RBAC + onboarding |
| Frontend engineers (Next.js) | 2 | App shell, i18n/RTL, auth + onboarding screens |
| AI engineer (FastAPI) | 1 | AI-engine repo scaffold + CI job only (ramps up S03) |
| Platform / DevOps | 1 (shared) | Docker dev env, GitHub Actions, Postgres/Redis services |
| QA / SDET | 1 | Two-tenant isolation harness, CI test-gate wiring |
| Product + Designer | 1 (part-time) | Onboarding + shell acceptance, EN/AR copy |

- **Target velocity:** ~48 story points (six delivery ICs at ~8 points). Sprint 01 is intentionally
  loaded slightly under target (44 pts committed) to absorb first-sprint environment friction.

# Objectives

1. **One repository, one green pipeline.** A polyglot monorepo containing `apps/api`, `apps/web`, and
   `apps/ai`, each with its idiomatic tooling and a GitHub Actions fan-out whose blocking gates
   (`pint`/`phpstan`/`pest`, `lint`/`typecheck`/`vitest`/`i18n:check`, `ruff`/`mypy`/`pytest`) all pass
   on an empty-but-real slice, per [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md).
2. **A tenant boundary that fails closed.** The shared-schema + `company_id` discriminator model of
   [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), enforced by all layers that exist yet:
   the `ResolveTenantCompany` middleware, the `BelongsToCompany`/`CompanyScope` Eloquent layer, and
   PostgreSQL RLS keyed on `app.current_company_id` that returns **zero rows** when the GUC is unset.
3. **Identity that grants nothing by itself.** Registration, email verification, login (web cookie +
   bearer JWT), throttle/lockout, `/auth/me`, and a `PermissionResolver` that composes
   `role ∪ grant − deny` — the split from [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md) where
   authentication identifies and authorization resolves grants per request.
4. **Company onboarding.** An email-verified user with zero companies is routed into a create-company
   flow that seeds the company, the owner membership, the system default roles, and the first fiscal
   year — the precondition Sprint 02's accounting work assumes.
5. **An app shell that reflects, never authors.** The `(auth)` and `(app)` route groups, the auth-gate
   middleware, sidebar/topbar/company-switcher, light/dark theming, and EN/AR full-RTL i18n, ending on
   an empty dashboard that renders inside the seeded tenant.
6. **Observability from line one.** The standard response envelope, a global exception handler that maps
   typed exceptions to codes, `request_id` propagation, and an append-only audit-log skeleton — so every
   later feature is debuggable and auditable by default.

# Backlog

Story points use a Fibonacci scale (1, 2, 3, 5, 8). "Module" names the owning area from
[../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md). Every story ends in a
demoable, tested increment; acceptance criteria are the tested assertions, not aspirations.

## Epic A — Monorepo & CI/CD skeleton

Nothing else in the sprint can be reviewed, tested, or demoed until the three codebases live in one
repository behind one pipeline. This epic is deliberately the first thing merged: it establishes the
tool matrix fixed in [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) (Pint/PHPStan/Pest,
ESLint/`tsc`/Vitest, ruff/mypy/pytest) as *blocking* gates from the very first PR, so quality is a
property the codebase is born with rather than one retrofitted later. The AI tree is scaffolded here but
carries only a health route and its lint/type gates this sprint; it does real work from Sprint 03.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S1-01 | Monorepo scaffold + tooling | Platform | Create the `apps/api` (Laravel 12/PHP 8.4), `apps/web` (Next.js 15/React 19/TS strict), `apps/ai` (FastAPI/Python) trees plus the `packages/*` and `infrastructure/*` layout, with pinned per-app toolchains and formatter/linter configs (Pint, PHPStan max, ESLint+Prettier, ruff, mypy strict). | Each app boots locally with a health route; `composer install`, `npm ci`/`pnpm install`, `pip install` all succeed; formatters/linters run clean on the scaffold; README documents each app's run command. | 5 | — |
| S1-02 | CI pipeline fan-out | Platform | GitHub Actions with three parallel jobs (backend/frontend/ai) plus a cross-codebase contract stage, wiring the required gates from [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) even against near-empty suites. | PR to a protected branch runs all three jobs; a deliberately failing lint fails the gate; Postgres 16 + Redis 7 services attach for backend; coverage-floor step present (non-fatal until suites exist). | 5 | S1-01 |
| S1-03 | Local dev environment | Platform | `docker-compose` (Postgres 15, Redis 7), a `Makefile`/`just` for `up`/`migrate`/`seed`/`test`, and `.env.example` per app. | `make up && make migrate && make seed` yields a running stack; documented in `docs/developer` setup notes; ports and secrets read from env, none committed. | 3 | S1-01 |

## Epic B — Database, multi-tenancy & RLS

This is the epic that decides whether QAYD is safe to sell. The whole platform is a single shared-schema
deployment, so the *only* thing standing between Company A and Company B's bank balances is the
three-layer boundary of [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md): the middleware that
pins the active company, the Eloquent scope that injects the predicate, and the PostgreSQL RLS policy
that enforces it at the storage engine regardless of what the ORM forgot. The engineering discipline
here is that the boundary must *fail closed* — an unset GUC yields zero rows, never another tenant's —
and that discipline is proven by a negative test suite (S1-06) that constructs two companies so a leak
has somewhere to leak to.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S1-04 | Core identity/tenant schema | Database | Migrations for `companies`, `users`, `company_users`, `roles`, `permissions`, `role_permissions`, `company_user_permissions` with the DDL and standard columns from [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md). | `php artisan migrate` applies cleanly and rolls back; `companies.uuid`, `citext` email uniqueness, and the `company_users` unique `(company_id, user_id)` constraints exist; a migration test asserts the schema. | 5 | S1-03 |
| S1-05 | RLS + tenant-scope enforcement | Database | PostgreSQL RLS policies `USING (company_id = current_setting('app.current_company_id', true)::bigint)` on every tenant table; the `ResolveTenantCompany` middleware issuing `SET LOCAL`; the `BelongsToCompany` trait + `CompanyScope` global scope. | With no GUC set, a raw `SELECT` on a tenant table returns **zero rows**; middleware validates `X-Company-Id` against a live `company_users` row (else 404); an arch test asserts every tenant model uses `BelongsToCompany`. | 8 | S1-04 |
| S1-06 | Two-tenant isolation harness | Testing | The `companyWithChartOfAccounts()`-style builder (company + admin user seed) and the canonical two-company test context, plus the first RLS negative tests in `tests/Feature/Rls`. | The isolation suite constructs two companies; a cross-tenant id read returns 404 (never 403); an RLS raw-SQL test runs with no GUC and asserts zero rows; suite wired into the `--group=rls,isolation` CI gate. | 3 | S1-05 |

## Epic C — Authentication & RBAC

Authentication identifies; it grants nothing. The rigor of this epic is keeping those two halves
separate: a perfect login yields a principal holding zero permissions until the resolver composes a
grant, per request, against the active company — the exact scope split of
[../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md). Every path here is deny-by-default and
fail-closed, because every other module in every later sprint trusts that by the time its Service runs,
this epic has already established a real user, a real membership, and a real permission set.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S1-07 | Registration + email verification | Identity | `RegisterUserAction` (creates `users`, argon2id hash), signed verification link, `VerifyEmailAction`; unverified users cannot create a company. | `POST /auth/register` creates a user and sends a verification email; the signed link marks `email_verified_at`; a create-company attempt by an unverified user returns `403 email_not_verified`; feature-tested. | 5 | S1-05 |
| S1-08 | Login, throttle & sessions | Identity | `LoginAction` (constant-time check, dummy-hash on missing user), Sanctum stateful cookie for web + RS256 JWT + rotating refresh for bearer, sliding-window throttle/backoff via `login_attempts`, `GET /auth/me`, logout. | Valid login returns a full credential; the 6th login in a minute is `429` with `Retry-After`; `/auth/me` returns identity, memberships, active company, resolved permissions, `perms_ver`; timing does not leak user existence. | 8 | S1-07 |
| S1-09 | Permission resolver + RBAC seed | Identity | `PermissionResolver` (`role ∪ grant − deny`, `perms_ver` cache), the seeded `permissions` catalogue + system default `roles`, route `can:` gates, and `SwitchCompanyAction`. | Resolver returns the empty set for a missing membership; a permission change bumps `perms_ver` and is effective next request; `switch-company` to a non-membership returns 404; a permission change in Company A never alters resolved perms in Company B (isolation test). | 5 | S1-08, S1-06 |

## Epic D — Company onboarding

Onboarding is where an authenticated *person* becomes a scoped *tenant context*. It is small in surface
but load-bearing: Sprint 02's accounting core assumes a company already has an owner membership, the
system default roles, and a first fiscal year, and this epic is where those are seeded — transactionally,
so a half-created company can never exist.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S1-10 | Create-company backend | Onboarding | `CreateCompanyAction`: seeds `companies` (base currency, fiscal-year-start month, timezone, locale), the owner `company_users` membership + Owner role, the system default roles, and the first `fiscal_years` row. | A verified user with zero companies can create one; the creator becomes Owner with full `company_users` grant; a first fiscal year is created; the action is transactional and audited; feature-tested. | 5 | S1-09 |
| S1-11 | Onboarding wizard (frontend) | Frontend | The post-auth Create-Company wizard (`/onboarding`) collecting legal/trade name (EN/AR), base currency, fiscal-year start; calls the backend action; routes to the dashboard on success. | A zero-company user is routed to `/onboarding` after auth (per [../frontend/flows/LOGIN_FLOW.md](../frontend/flows/LOGIN_FLOW.md)); the wizard validates via Zod, submits, and lands on the empty dashboard; EN + AR variants render. | 3 | S1-10, S1-15 |

## Epic E — App shell & i18n

The shell is the frame every later screen renders inside, so it must embody the platform's three
frontend constraints from day one: the frontend computes nothing that establishes identity, AI is
visible-and-labeled (and correctly *absent* from the `(auth)` shell), and RBAC is enforced by the API
with the UI only reflecting it. Bilingual EN/AR with full RTL is not a later polish pass — it is built
into the shell now and gated by `i18n:check`, so no English-only string ever accretes.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S1-12 | App shell + auth-gate middleware | Frontend | The `(auth)` and `(app)` Next.js route groups, `middleware.ts` bouncing unauthenticated `(app)` requests to `/login?next=…` (same-origin allow-list), and the BFF route handlers that set httpOnly session cookies. | An unauthenticated `(app)` request redirects to `/login` preserving a validated `next`; a signed-in user's session cookie is httpOnly/Secure/SameSite; a lapsed session returns to the intended route after the chain. | 5 | S1-01 |
| S1-13 | Sidebar, topbar, company switcher, theme | Frontend | The shell chrome: navigation sidebar, topbar, `CompanySwitcher` (from `/auth/me` memberships → `switch-company`), and light/dark theming with the design tokens. | Switching companies re-scopes the session and re-fetches `/auth/me`; theme toggle persists and applies light/dark; keyboard-focus and AA contrast pass; renders with zero business data. | 5 | S1-12, S1-09 |
| S1-14 | EN/AR i18n + full RTL | Frontend | The `en.ts`/`ar.ts` dictionaries, locale switching, `dir="rtl"` document direction for Arabic, and the `i18n:check` key-parity gate. | Switching to Arabic mirrors the layout (RTL) and translates all shell strings; `i18n:check` fails if a key exists in one dictionary but not the other; both directions baselined for later visual regression. | 3 | S1-13 |
| S1-15 | Login / MFA / select-company + empty dashboard | Frontend | The `(auth)` screens (`/login`, `/mfa`, `/forgot-password`, `/reset-password`, `/select-company`) wired to the auth API, and the empty `/dashboard` that is the sprint exit surface. | The full login journey works end to end incl. multi-company `/select-company`; a lockout surfaces the server `Retry-After`; the dashboard renders an intentional empty state scoped to the active company; no AI presence in the `(auth)` shell. | 5 | S1-08, S1-14 |

## Epic F — Cross-cutting foundations

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S1-16 | Envelope, error handler & audit skeleton | Platform | The standard response envelope `{success,data,message,errors,meta,request_id,timestamp}`, the global exception handler mapping typed exceptions → codes, `request_id` propagation, and an append-only `audit_logs` write path. | Every route returns the envelope; an unhandled domain exception renders as a coded error, never a stack trace; `request_id` is present and log-correlated; a login writes an audit row; contract-checked against the OpenAPI skeleton. | 3 | S1-05 |

**Committed total: 44 points.** MFA enrollment (TOTP), passkeys/WebAuthn, SSO/OIDC, and API-key
issuance from [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md) are explicitly **out of scope**
for Sprint 01 and tracked for the post-MVP hardening track; login treats an MFA-enrolled account as a
future branch that is stubbed to the non-MFA path this sprint.

# Sequencing & capacity

The dependency graph forces a clear two-week shape. Week 1 is foundations that unblock everyone: the
monorepo and CI (S1-01, S1-02), the dev environment (S1-03), and the schema + RLS (S1-04, S1-05). No
feature work can begin before the tenant boundary exists, so the two backend engineers pair on Epic B
early while the frontend engineers build the shell chrome (S1-12, S1-13) against a mocked API. Week 2 is
the vertical slice that produces the exit: auth (S1-07, S1-08, S1-09), onboarding (S1-10, S1-11), i18n
and the auth screens (S1-14, S1-15), and the cross-cutting envelope/audit skeleton (S1-16).

| Week | Backend | Frontend | AI / Platform / QA |
|---|---|---|---|
| Week 1 | S1-04 schema, S1-05 RLS + middleware, S1-07 register/verify | S1-12 route groups + auth gate, S1-13 shell chrome (mocked API) | S1-01 scaffold, S1-02 CI, S1-03 dev env, S1-06 isolation harness |
| Week 2 | S1-08 login/throttle, S1-09 resolver + RBAC seed, S1-10 create-company, S1-16 envelope/audit | S1-14 i18n/RTL, S1-15 auth screens + empty dashboard, S1-11 onboarding wizard | S1-06 RLS negative tests, contract-fixture wiring, demo-env prep |

The critical path is **S1-04 → S1-05 → S1-08/S1-09 → S1-15**: the schema unblocks RLS, which unblocks
login and the resolver, which unblock the auth screens that produce the exit. S1-06 (isolation harness)
runs in parallel and gates everything touching a tenant table.

# Story spotlights

Two stories carry most of the sprint's risk and deserve detail beyond their backlog row.

**S1-05 — RLS + tenant-scope enforcement (the crown jewel of the sprint).** This story implements the
defense-in-depth of [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md): the
`ResolveTenantCompany` middleware validates `X-Company-Id` against a live `company_users` row and issues
`SET LOCAL app.current_company_id` inside the request transaction envelope; the `BelongsToCompany` trait
fills `company_id` on insert and `CompanyScope` adds the predicate on read; and each tenant table gets an
RLS policy `USING (company_id = current_setting('app.current_company_id', true)::bigint)`. The single
most important assertion is the *fail-to-empty* behaviour: with the GUC unset the policy evaluates false
and returns zero rows. Because the whole platform is one shared schema, this is the story a reviewer
reads line by line, and it is where the GUC-name reconciliation (see Risks) is settled once for the
codebase.

**S1-08 — Login, throttle & sessions.** The subtlety here is that the same `LoginAction` serves two
client models: a Sanctum stateful cookie for the Next.js SPA and an RS256 JWT + rotating refresh for
bearer clients. The story must get the security details right on the first pass because they are hard to
retrofit: a constant-time password check that hashes a dummy on a missing user so timing never leaks
existence, a sliding-window throttle recorded in `login_attempts` with bounded exponential backoff, and
session-id regeneration on login to defeat fixation. `/auth/me` becomes the client's source of truth for
identity, memberships, and the resolved permission set.

# Out of scope (deferred)

Explicitly *not* in Sprint 01, to protect the goal — each is a tracked backlog item, not a gap:

- **MFA (TOTP/SMS/WebAuthn), passkeys, recovery codes, and the `mfa_pending` step-up handshake** — the
  login path is built with the MFA branch stubbed to the non-MFA route; full MFA lands in the security
  hardening track.
- **SSO / OIDC (`sso_identities`, `oauth_clients`), enterprise IdP login** — QAYD-as-OAuth-client is
  post-MVP.
- **API-key issuance (`personal_access_tokens`, `qyd_live_…`) and the maker-checker `auth_approvals`
  queue** — deferred until there is a partner integration to serve.
- **Any accounting, banking, or AI feature** — the dashboard is intentionally empty; its only job is to
  prove a scoped, authenticated render. Real widgets begin in [SPRINT_02.md](./SPRINT_02.md).
- **Geographic sharding, backup/DR tenancy specifics** — the model in
  [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) is honored in schema shape but not
  operationalized this sprint.

# Definition of Done

A story is done only when every item below is true — the release checklist from
[../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) applied at foundation
scale:

- **Migration.** Any schema change ships as a reversible migration that applies and rolls back on a
  clean database, with a migration test.
- **API + envelope.** Every new endpoint returns the standard envelope, is documented in the committed
  OpenAPI skeleton, and has ≥1 Pest feature test asserting the full response shape.
- **Permissions.** Every guarded route is default-deny; the 403 path has an explicit test; no endpoint
  relies on a client-side check for authority.
- **Tenant isolation.** Any table added carries `company_id` (or is justified as global per
  [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)), uses `BelongsToCompany`, and is covered
  by the RLS policy; the isolation suite still passes on two companies.
- **Audit + observability.** State-changing actions write an `audit_logs` row; `request_id` propagates;
  no secret, password, token, or session id appears in a log line (CI log-scrub assertion).
- **Tests green.** Unit + feature tests pass; frontend `lint`, `typecheck`, `vitest`, `i18n:check`
  pass; AI `ruff`/`mypy`/`pytest` pass on the scaffold; all blocking CI gates green.
- **i18n.** New user-facing strings exist in both `en.ts` and `ar.ts`; RTL renders correctly.
- **Reviewed + demoable.** Peer-reviewed, merged to the protected branch behind the green gate, and
  runnable in the demo environment.

# Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| RLS GUC name drift (`app.current_company_id` vs `app.company_id`) across docs (noted in [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md)) causes policies and middleware to disagree, silently disabling isolation. | Medium | Critical | Fix the GUC name **once** in S1-05, assert the *deployed* name in the migration/RLS test, and reference that constant everywhere; make it a blocking arch test. |
| PgBouncer transaction pooling drops `SET LOCAL` scope between statements, breaking RLS under the real connection setup. | Medium | High | Wrap tenant-scoped work in `DB::transaction` so `SET LOCAL` holds for the statement set; add an integration test that runs through the pooled connection config. |
| First-sprint environment friction (toolchain versions, Docker, CI runners) eats delivery capacity. | High | Medium | Front-load Epic A on day 1–2; under-commit (44 vs 48 pts); Platform engineer pairs on setup blockers. |
| Auth scope creep (MFA, SSO, passkeys, API keys) pulls the team past the sprint goal. | Medium | Medium | Hard scope line: only registration/verify/login/resolver/switch-company this sprint; everything else is a tracked backlog item, stubbed at the branch. |
| Frontend and backend auth contracts drift (cookie vs bearer, error codes). | Medium | Medium | Treat [../frontend/flows/LOGIN_FLOW.md](../frontend/flows/LOGIN_FLOW.md) + the OpenAPI skeleton as the shared contract; contract test the envelope; FE consumes MSW fixtures generated from it. |
| An empty dashboard tempts scope pull toward real widgets. | Low | Low | The dashboard's *only* Sprint 01 job is to prove a scoped, authenticated render; real widgets are Sprint 02+. |

# Demo / exit criteria

The sprint is complete when the following can be demonstrated live, end to end, against the demo
environment, with the supporting tests green in CI:

1. **CI is real and green.** Open a trivial PR; show the three-codebase fan-out running and every
   blocking gate passing; show a deliberately broken lint failing the gate, then fixed.
2. **A tenant is isolated at the database.** In a console, `SET` no GUC and `SELECT` from a tenant
   table — zero rows. Set a valid `app.current_company_id` — the tenant's rows appear. Show the RLS
   negative test passing in CI.
3. **A person becomes a signed-in, scoped user.** Register a new account, click the verification link,
   create a company through the onboarding wizard, and sign in through the real `/login` screen against
   the Sanctum + JWT guard.
4. **The shell renders inside the seeded tenant.** The signed-in user lands on an **empty dashboard**
   inside the app shell, with a working sidebar, topbar, and company switcher, scoped to their company.
5. **The platform is bilingual and themable.** Toggle to Arabic and watch the shell mirror to full RTL;
   toggle dark mode; both persist across navigation.
6. **Identity grants nothing on its own.** Show `/auth/me` returning the resolved permission set and
   `perms_ver`; show that a `switch-company` to a non-membership returns 404, not 403.

Meeting these criteria proves the foundation the accounting core lands on in
[SPRINT_02.md](./SPRINT_02.md): an isolated, authenticated, authorized, observable, bilingual tenant
with an app shell ready to render its first real feature.

# Success metrics

Signals reviewed at the retro to judge whether the sprint delivered its intent, not just its tickets:

| Metric | Target |
|---|---|
| Committed points delivered | ≥ 40 of 44 (≥ 90%); any carry-over is on the non-critical-path stories |
| Blocking CI gates green on `main` | 100% — no story merges with a red gate |
| Tenant-isolation suite | Present and green, constructed on two companies; zero cross-tenant reads |
| RLS fail-closed assertion | The unset-GUC → zero-rows test exists and passes |
| Critical-path coverage (auth, RLS) | ≥ 90% line on the auth/tenancy paths per the testing-strategy floor |
| i18n parity | `i18n:check` green; every shell string present in `en` and `ar` |
| Exit demo | Accepted by Product without a follow-up "does it actually isolate?" question |

These are leading indicators for the sprints that follow: a shaky isolation suite or a red gate here
compounds into every later feature, so they are treated as release-blocking, not informational.

# Related Documents

- [SPRINT_02.md](./SPRINT_02.md) — Accounting core (COA, journal entries, posting engine, GL, TB).
- [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) — the module model and the official stack.
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) — the tenant boundary this sprint builds.
- [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md) — the identity/RBAC service Epic C instantiates.
- [../frontend/flows/LOGIN_FLOW.md](../frontend/flows/LOGIN_FLOW.md) — the login journey Epic E implements.
- [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) — the CI gates and isolation-test bar.

# End of Document
