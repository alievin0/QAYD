# Release Process — QAYD Developer
Version: 1.0
Status: Design Specification
Module: Developer
Submodule: Release Process
---

# Purpose

This document defines how a change becomes a release in QAYD — how versions are numbered, how branches
and tags are structured, what must be true before anything ships, how a build is promoted from staging
to production, how database migrations are made safe to roll forward and back, how a release is rolled
back or hotfixed when it goes wrong, and how all of that is communicated to the humans and integrations
that depend on QAYD not breaking under them.

QAYD is an AI Financial Operating System: a multi-tenant, double-entry accounting core whose Laravel 12
backend is the single source of truth for every company's financial facts, consumed by a Next.js web
app, a Flutter mobile app, a FastAPI AI engine, and third-party integrations (accountants' tooling,
bank connectors, government e-invoicing gateways). That client mix is the reason releasing QAYD is
different from releasing an ordinary web app: an accountant's month-end close script, a bank
reconciliation cron job, or a tax-filing integration cannot be allowed to fail because a release
renamed a field, dropped a column, or shifted a default. Every rule in this document exists to make
QAYD's releases **boring** — frequent, reversible, and non-surprising — which for financial software is
the highest praise a release process can earn.

This document is process; the mechanics it references live elsewhere and are authoritative there:
- [CONTRIBUTING.md](./CONTRIBUTING.md) — how a change is authored, reviewed, and merged before it is
  ever eligible for a release.
- [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md) — the infrastructure, environments, and
  deploy mechanics a release runs on.
- [../api/API_VERSIONING.md](../api/API_VERSIONING.md) — the binding `/api/v1` versioning and
  deprecation contract this document operationalizes.
- [CHANGELOG.md](./CHANGELOG.md) and [ARCHITECTURE_DECISIONS.md](./ARCHITECTURE_DECISIONS.md) — the
  record of what shipped and the decisions that constrain how it ships.

# Semantic Versioning Policy

QAYD versions the **platform release** with [Semantic Versioning 2.0.0](https://semver.org/):
`MAJOR.MINOR.PATCH`.

| Bump | Meaning | Examples |
|---|---|---|
| **MAJOR** | A breaking change to a public contract. In practice this almost always means a new API major version (`/api/v2`), because the API is the contract third parties depend on. | Renaming or removing an API field; changing a field's type or semantic meaning; making an optional request field required; changing the response-envelope shape; changing which error code fires for a condition; changing a default sort/pagination/filter. |
| **MINOR** | A backward-compatible capability addition. | A new endpoint; a new optional request or response field; a new webhook event type; a new enum member; a new report; a new AI agent capability. |
| **PATCH** | A backward-compatible fix. | A bug fix that does not change any documented or observable behavior a client could reasonably rely on; a performance improvement; a security patch with no contract change. |

The breaking-vs-non-breaking classification is not a judgment call made at release time — it is fixed by
the taxonomy in [../api/API_VERSIONING.md](../api/API_VERSIONING.md) § *Breaking vs Non-Breaking
Changes*, applied at code-review time, and the release version is a mechanical consequence of what
merged. The decision rule of thumb is inherited verbatim: **if any plausible unmodified client (PHP,
JS/TS, Python, or Dart) would throw, silently mis-parse, or silently mis-compute after the change, it
is breaking.** When in doubt, treat it as breaking — an unnecessary MAJOR bump is far cheaper than a
silently corrupted ledger.

Two adopted subtleties, both straight from QAYD's API policy:
- **Fixing a bug a client was unintentionally relying on is a breaking change.** Per Stripe's written
  policy, which QAYD adopts: if a fix changes any observable behavior a client could reasonably depend
  on, it ships behind a new version, not as a silent PATCH.
- **A tightened rate limit is operational, not contractual** — it does not bump the version, but it goes
  through the change-communication process below.

**Pre-1.0 note.** During the design-and-build phase the platform version carries a leading `0.`
(`0.1.0` is the Design Specification milestone; see [CHANGELOG.md](./CHANGELOG.md)). While the public
API contract is not yet frozen, MINOR and PATCH carry their SemVer meanings but the "breaking change ⇒
new API major" guarantee is not yet owed to external integrators. The first production API contract
freeze coincides with `1.0.0` and the first `current`-status `/api/v1`.

# API Versioning and the 12-Month Deprecation Policy

The platform version above and the **API major version** are related but not identical: a platform
MAJOR bump that introduces `/api/v2` does **not** delete `/api/v1`. Both run in production simultaneously
for the entire deprecation window — this is the normal steady state, not an edge case (see
[ADR-008](./ARCHITECTURE_DECISIONS.md)). A release, therefore, never "cuts over" an API version; it
*adds* the new one and begins the old one's clock.

The binding rules, operationalized from [../api/API_VERSIONING.md](../api/API_VERSIONING.md):

- **Path versioning.** The API is versioned in the URI (`/api/v1/`, `/api/v2/`), a bare major integer,
  no minor/patch in the path. Non-breaking changes ship continuously into the `current` version without
  changing the path; only a breaking change introduces a new one.
- **Shared business logic.** Only per-version Controllers, FormRequests, and API Resources differ
  between versions; Services and Repositories are never versioned. A release can therefore add `v2`
  response shapes with zero risk of forking a debit/credit balance check or a tax calculation between
  what `v1` and `v2` clients experience.
- **Minimum 12-month sunset window.** Any API version with at least one active third-party integration
  gets a **minimum of 12 months** between deprecation announcement and hard removal. The window may be
  lengthened, never shortened, absent a documented regulatory or security exception. Internal-only
  versions may use an explicitly-approved shorter window, but never less than 90 days (the minimum
  cadence of a Flutter app-store review).
- **Machine-readable deprecation on every response.** Once a version is `deprecated`, every response
  from it carries `Deprecation: true`, a `Sunset` header (RFC 8594 HTTP-date), a `Link` header pointing
  at the migration guide, and `X-Qayd-Api-Version` echoing which version served the request. After
  sunset, the version returns `410 Gone` for a 30-day observation window before its routes are
  physically removed.
- **Five-state lifecycle.** Every version moves `beta → current → deprecated → sunset → retired`, tracked
  in the `api_versions` reference table that the `GET /api/version` discovery endpoint reads from. A
  release is the event that advances a version between these states.

The **release engineer's deprecation checklist** when a release introduces a new API major:

1. The migration guide at `docs.qayd.com/api/migration/{from}-to-{to}` exists and passes its CI lint
   **before** the deprecation announcement — never after.
2. The new version enters `current`; the previous version's `api_versions` row flips to `deprecated`
   with `deprecated_at = now()` and `sunset_at = now() + 12 months`.
3. The `AnnounceApiDeprecation` middleware begins emitting `Deprecation`/`Sunset`/`Link` headers for the
   deprecated version (driven by config, no code change per release).
4. The changelog carries a dated `Deprecated` entry naming the version and its `Sunset` date and linking
   the migration guide.
5. The `api.version.announced` / `api.version.deprecated` webhook events fire to every registered
   integration, mirrored as a dashboard banner for affected companies' admins.
6. The sunset-warning escalation (T+9 month direct notice to still-active clients, T+11 month daily
   notice plus a `Warning: 299` header) is scheduled — these are automated, not per-release manual steps,
   but the release that starts the clock is responsible for confirming they are armed.

# Release Cadence

| Track | Cadence | Contents |
|---|---|---|
| **Regular release** | Scheduled, roughly weekly, from `main` | Accumulated MINOR and PATCH changes that have passed all gates. Most releases are here. |
| **Patch release** | As needed | A backward-compatible fix that should not wait for the next scheduled train — a non-urgent bug fix, a small correction. |
| **Hotfix release** | Immediately, out of band | A production-critical fix (data-integrity risk, security issue, sustained outage). Follows the hotfix flow below. |
| **Major release** | Deliberately infrequent | Introduces a new API major (`/api/vN`). Batched: multiple concurrent breaking changes go into one new major rather than a string of MAJOR bumps, so clients migrate once, not repeatedly. |
| **Mobile release** | On its own app-store cadence | The Flutter app versions and ships independently through App Store / Play review; see *Versioning the Mobile App & SDKs*. |

The default posture is **small, frequent, boring releases.** A large release is a large rollback and a
large blast radius; the process is deliberately biased toward shipping less at a time, more often.
Breaking API changes are the sole exception where infrequency is a virtue — they are batched precisely
so integrators are asked to migrate as rarely as possible.

# Branch and Tag Strategy

QAYD uses a trunk-based model with short-lived branches. The mechanics of authoring and reviewing a
branch are in [CONTRIBUTING.md](./CONTRIBUTING.md); this section covers only what the release process
depends on.

| Ref | Role |
|---|---|
| `main` | The trunk. Always releasable: every commit on `main` has passed the full CI gate set. Feature branches merge here via reviewed pull requests. Releases are cut from `main`. |
| `feature/*`, `fix/*` | Short-lived branches off `main`, one logical change each, deleted after merge. |
| `release/x.y` | Optional stabilization branch cut from `main` when a release needs a short hardening window (typically a MAJOR). PATCH fixes for that line land here and are merged back to `main`. Most regular releases skip this and tag `main` directly. |
| `hotfix/*` | Branched from the **release tag** being fixed (not from `main`), for an out-of-band production fix; merged to both `main` and the affected `release/x.y` line. |

**Tags.** Every release is an annotated, signed git tag `vX.Y.Z` (e.g. `v0.2.0`, `v1.4.1`) on the exact
commit that was built and deployed. The tag is the immutable identity of a release: the changelog
section, the deployed artifact, the container image digest, and the tag all refer to the same commit.
Tags are never moved or deleted. An API major introduction is reflected in the platform tag (the MAJOR
component) and, separately, in the `api_versions` table — the two are related but the git tag is the
platform release, not the API version.

# The Release Checklist

No release ships until **all** of the following are true. This checklist is enforced — a release
manager confirms each item, and the CI gates that can be automated are blocking, not advisory.

## Gate 1 — All CI gates green on the release commit

Every one of these passes on the exact commit being tagged, for every affected codebase. These are the
same gates every pull request already passed; the release re-confirms them on the merge result:

**Backend (Laravel)**
- `composer lint` / Laravel Pint — PSR-12 formatting.
- PHPStan — static analysis at the configured level.
- Pest **Unit** and **Feature/Integration** suites green, including the RLS negative (tenant-isolation)
  suite and migration tests.
- **Contract** tests (Spectator/Schemathesis) — real responses validated against
  `openapi/qayd-api-v1.yaml` for every live (non-retired) version.

**Frontend (Next.js)**
- `npm run lint` (typescript-eslint, jsx-a11y, react-hooks).
- `npm run typecheck` (`tsc --noEmit`), independent of the Next build.
- `npm run test` (Vitest + Testing Library).
- `npm run test:e2e` (Playwright, including four-variant visual regression: light/dark × LTR/RTL for
  each covered screen).
- `npm run i18n:check` — fails if any key exists in `en.ts` but not `ar.ts`, or vice versa.

**AI engine (FastAPI)**
- pytest unit suite and the `ai/proposals` contract test against the shared JSON Schema fixture.

**Cross-cutting**
- **Load** — k6 PR smoke is blocking; the pre-release **soak** run has been executed and reviewed for a
  MAJOR or any release touching a hot path.
- **Security** — secret scanning, dependency-CVE audit, and the dedicated tenant-isolation suite all
  clean.

The seven-band pyramid ([ADR-012](./ARCHITECTURE_DECISIONS.md),
[../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md)) is the definition of "green" here — an
upper-band pass never substitutes for a missing lower-band test.

## Gate 2 — Migrations reviewed for safety

Every database migration in the release has been reviewed against the expand → migrate → contract rules
in *Database Migration Safety* below. Specifically: no migration in this release drops or renames a
column that any still-live API version's Resource reads, and every new tenant table enables **and forces**
Row-Level Security in the same migration.

## Gate 3 — CHANGELOG updated

The `[Unreleased]` section of [CHANGELOG.md](./CHANGELOG.md) contains a line, under the correct one of
the six Keep-a-Changelog groups, for every change a reader outside the pull request would care about. At
release time these roll down into a new dated `[X.Y.Z]` section. Any breaking change carries an explicit
call-out and a migration-guide link.

## Gate 4 — Docs updated

Any change to the specification set is reflected in the same release: a new or changed endpoint updates
its API and screen/service docs and the OpenAPI spec; a new architectural decision carries its ADR in
[ARCHITECTURE_DECISIONS.md](./ARCHITECTURE_DECISIONS.md); a change to the stack is reflected in
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md). A release whose behavior and whose
documentation disagree is not shippable.

## Gate 5 — Version, tag, and release notes prepared

- The platform version is bumped per the SemVer policy as a mechanical consequence of what merged.
- The annotated signed tag `vX.Y.Z` is prepared on the release commit.
- Release notes (see *Release Notes & Customer Communications*) are drafted from the changelog.
- For a MAJOR introducing a new API version, the deprecation checklist above is complete.

# Staging → Production Promotion

QAYD promotes the **same artifact** through environments — it never rebuilds between staging and
production, so what was verified is exactly what ships. The environments and their infrastructure are
defined in [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md); the promotion *process* is:

1. **Build once.** CI builds the release artifact (backend image, frontend build, AI-engine image) from
   the tagged commit and records its digest. This digest is what moves forward; nothing is rebuilt
   downstream.
2. **Deploy to staging.** The artifact deploys to the staging environment, which runs against a
   production-like, seeded dataset and has both the `current` and any `deprecated` API versions live.
3. **Verify on staging.** The E2E smoke journeys run against staging; the four-variant visual regression
   is reviewed; QAYD's own first-party clients (web, mobile) and the AI engine's Python client are
   exercised against staging with their pinned `apiVersion`. For a release preceding a scheduled sunset,
   the **sunset rehearsal** runs here: staging flips the soon-to-sunset version to `sunset` early to
   confirm no first-party client is still silently calling it.
4. **Promote to production.** The identical artifact digest deploys to production behind the standard
   rollout (health-checked, gradual where the platform supports it). Database migrations run per the
   migration-safety rules below, ahead of the code that depends on them.
5. **Post-deploy verification.** Production health checks, error-rate and latency dashboards (Sentry,
   Prometheus/Grafana), and a synthetic smoke of the critical paths (login, post a journal entry in a
   test company, fetch a report) confirm the release is healthy before it is declared done.
6. **Announce.** The changelog section is published, release notes go out, and any API deprecation
   notices for this release are confirmed sent.

If any step before promotion fails, the release stops and the fix re-enters the normal pull-request flow;
staging is never bypassed to "just push the fix to production."

# Database Migration Safety

Migrations are the single most dangerous part of any release of a financial platform, because a bad
migration can corrupt or lose the ledger and because multiple API versions read the same schema
simultaneously (ADR-002, ADR-008). QAYD's rules make every migration **backward-compatible and
reversible within a release window**.

## Expand → Migrate → Contract

A schema change that would otherwise break a running older version is split across releases:

1. **Expand** (release *N*) — add the new structure without removing the old. Add the new column
   (nullable or defaulted), the new table, or the new index. Deploy. Old code and every live API version
   keep working because nothing they read has changed.
2. **Migrate** (release *N*, background) — backfill the new structure and **dual-write**: application
   code writes both the old and new shapes for the duration of the transition, so a rollback to release
   *N-1* still finds consistent data.
3. **Contract** (a later release, only after the last version that read the old structure has reached
   `retired`) — drop the old column/table/index. This is the only release in which a destructive schema
   change happens, and it happens only when provably nothing reads the old shape.

A migration that renames a column is therefore never a rename — it is *add new, dual-write, backfill,
switch reads, then drop old*, spread across releases. A pull request that tries to drop or rename a
column an live version's Resource still reads is blocked at Gate 2 and by the contract test suite, which
asserts the older version's response still contains the field.

## Migration rules that hold every time

- **Migrations run before the code that depends on them**, and every migration is written so the *prior*
  release's code still functions against the new schema (that is what makes rollback safe).
- **No destructive change (`DROP`, `ALTER … TYPE` that narrows, `NOT NULL` on a populated column without
  a default) ships in the same release as the code that first needs the new shape** — destructive
  changes are always a separate, later Contract release.
- **Every new tenant table enables and forces RLS in its creating migration** (`ENABLE ROW LEVEL
  SECURITY` + `FORCE ROW LEVEL SECURITY` + the `company_id = current_setting('app.current_company_id',
  true)::bigint` policy). A tenant table without RLS is a breach waiting to happen and is caught by the
  security band.
- **Large backfills run as background/queued jobs**, not inline in the migration, so a deploy is not
  blocked on rewriting millions of rows, and the backfill can be paused or resumed.
- **Posted financial data is never mutated by a migration.** Immutability (ADR-006) applies to schema
  changes too: a migration may add columns or derived structures, but it does not rewrite the value of a
  posted `journal_line`. A correction to posted data is a reversing entry created through the
  application, never an `UPDATE` in a migration.

# Rollback Procedure

Because artifacts are promoted (not rebuilt) and migrations are expand-contract (not destructive within
a window), rollback is a first-class, rehearsed operation, not an emergency improvisation.

1. **Decide fast on a clear signal.** A rollback trigger is objective: error rate or latency breaching
   the post-deploy thresholds, a data-integrity alarm, a failed critical synthetic. The on-call release
   owner decides; the bias is to roll back and investigate rather than debug forward in production.
2. **Redeploy the previous tag's artifact.** Because the prior release is an immutable, still-available
   artifact digest, rollback is redeploying `vX.Y.(Z-1)` (or the prior MINOR) — the same promote
   mechanism in reverse.
3. **Code rolls back cleanly because the schema did not move destructively.** The just-shipped release's
   migrations were Expand-only (additive, dual-written), so the previous release's code runs correctly
   against the current schema — this is the entire reason the expand-contract discipline exists.
   Additive columns left behind by the rolled-back release are harmless and are cleaned up (or reused) in
   a later release.
4. **Never “roll back” by running a destructive down-migration in production under pressure.** Down
   migrations exist for local/CI use; production recovery is redeploying the prior artifact, not dropping
   columns live. If data written in the new shape must be reconciled, that is a forward-fix through the
   application, done deliberately.
5. **Record it.** A rollback produces an incident note and, once the fix lands, a changelog entry —
   rollbacks are learning events, and the post-deploy thresholds that triggered them are reviewed.

# Hotfix Flow

A hotfix is for a production-critical problem that cannot wait for the next scheduled release —
data-integrity risk, an active security issue, or a sustained outage.

1. **Branch from the release tag, not from `main`.** `hotfix/*` is cut from the exact `vX.Y.Z` currently
   in production, so the fix contains only the fix and nothing that has merged to `main` since.
2. **Smallest possible change.** A hotfix fixes one thing. It does not carry refactors, dependency
   bumps, or unrelated improvements.
3. **Full gates still apply, on the fast path.** The CI gate set (Gate 1) still runs — a hotfix that
   skips tests is how a second incident is caused. The pyramid is not waived; it is run with urgency.
4. **Tag a PATCH release** (`vX.Y.(Z+1)`), update the changelog under `Fixed` (or `Security`), promote
   through staging to production via the same artifact-promotion path. Staging is not skipped even under
   time pressure; the sunset/verification steps that do not apply to the specific fix may be
   proportionally scoped, but the deploy path is the standard one.
5. **Merge back everywhere.** The hotfix merges to `main` and to any active `release/x.y` line so the fix
   is never lost in the next regular release (a regression via a forgotten hotfix is its own incident
   class).
6. **A security hotfix is still communicated**, but its disclosure is coordinated per the security
   policy — the *fix* ships immediately; the *detail* is disclosed responsibly.

# Release Notes & Customer Communications

A release speaks to two audiences, and QAYD serves both from the single source of truth that is the
changelog.

- **Developer/integrator audience.** The dated [CHANGELOG.md](./CHANGELOG.md) section is the canonical
  technical record, mirrored to the public developer changelog at `docs.qayd.com/api/changelog`. It is
  generated from the same source that drives the OpenAPI spec diff, so it can never drift from the
  contract that actually shipped. Additive API changes carry an `X-Qayd-Changelog` response header for
  30 days so integrators notice new fields without watching the changelog by hand; breaking changes carry
  the full `Deprecation`/`Sunset`/`Link` header set and a linked migration guide.
- **Company/admin audience.** User-facing release notes — written in plain language, in **both English
  and Arabic** consistent with the platform's bilingual requirement — summarize what changed for the
  people using the product, and surface as an in-app dashboard banner for the relevant company admins.
  API version lifecycle events (`api.version.announced`, `api.version.deprecated`,
  `api.version.sunset_warning`, `api.version.sunset`) are emitted as webhooks **and** mirrored as
  dashboard banners, so the message reaches a human even when an integration is unattended.

Release-notes discipline:
- **Say what changed for the reader, not what the committer did.** "You can now export the Trial Balance
  to Excel" beats "added export handler."
- **Every breaking change is unmissable** and links its migration guide.
- **Security fixes are noted** at the appropriate level of detail for the audience and the disclosure
  timeline.
- **Nothing is announced that did not ship.** Release notes describe the tagged artifact, never a plan.

# Versioning the Mobile App & SDKs

QAYD ships client software on cadences of their own, decoupled from the platform version but disciplined
by the same SemVer principles.

## Flutter mobile app

- The mobile app carries its **own** `MAJOR.MINOR.PATCH` version and ships through App Store and Google
  Play review on its own schedule — which is exactly why the API deprecation window is a **minimum 12
  months**: an old app version can sit on a user's phone, unupdated, for a long time, and must keep
  working against the API for the full window (and never less than the ~90-day floor of one app-store
  review cycle).
- The mobile app **pins the API version** it targets (`ApiVersion.v1`) — there is no silent
  "use-newest" default. Moving the app to a new API major is a deliberate mobile release, coordinated
  against that version's `current` promotion, never an implicit follow-along.
- Because the app is stateless-JWT-authenticated (ADR-004), a mobile release never needs a server
  session migration — the credential model is forward-compatible by design.

## Official SDKs (PHP, JS/TS, Python, Flutter/Dart)

Each SDK follows SemVer for its **own** package version, decoupled from the API major number but mapped
onto the API lifecycle per [../api/API_VERSIONING.md](../api/API_VERSIONING.md):

| SDK change | SDK bump | Trigger |
|---|---|---|
| New optional field/endpoint support (additive API change) | MINOR | Any `Added` changelog entry |
| SDK-internal bug fix, no API contract change | PATCH | Internal fix |
| SDK begins supporting a new API major alongside the old one | MINOR | New API version enters `current` |
| SDK's default target version changes to the new major | MAJOR | New API version promoted to `current`, old one `deprecated` |
| SDK drops support for a sunset/retired API version | MAJOR | API version reaches `retired` |

Every SDK exposes a **required, explicit** version selector at construction (`apiVersion: 'v1'`) — the
silent-default failure mode is exactly what this whole process exists to prevent — and each SDK release
note states which API version(s) it targets. The AI engine's Python client is treated as an ordinary
integration: it pins its `api_version` per environment and is upgraded in its own reviewed pull request
on the same cadence as any other client, with no fast-path around this policy.

# Cross-References

- [CONTRIBUTING.md](./CONTRIBUTING.md) — authoring, review, and merge gates a change clears before it is
  release-eligible.
- [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md) — the environments and infrastructure this
  release process runs on.
- [../api/API_VERSIONING.md](../api/API_VERSIONING.md) — the binding API versioning and deprecation
  contract operationalized here.
- [CHANGELOG.md](./CHANGELOG.md) — the record of what each release contained.
- [ARCHITECTURE_DECISIONS.md](./ARCHITECTURE_DECISIONS.md) — the decisions (path versioning, immutable
  posting, RLS multi-tenancy, the test pyramid) this process protects.

# End of Document
