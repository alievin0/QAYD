# Changelog — QAYD Developer
Version: 1.0
Status: Design Specification
Module: Developer
Submodule: Changelog
---

# Purpose

This file is the human-facing, chronological record of what has changed in QAYD. It answers one
question for an engineer, an integrator, or an AI coding agent: *"what is different now, and when did
it change?"* An [Architecture Decision Record](./ARCHITECTURE_DECISIONS.md) explains *why* a decision
holds; this changelog records *when* work honoring it landed and what a reader needs to know to react
to it. The two are complementary and neither replaces the other.

## Format

QAYD's changelog follows the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) convention and
[Semantic Versioning 2.0.0](https://semver.org/). Concretely:

- The newest entries live at the top, under an `[Unreleased]` heading, and roll down into a dated,
  versioned section when a release is cut (see [RELEASE_PROCESS.md](./RELEASE_PROCESS.md) for how a
  release is cut).
- Every change is filed under one of six standard groups, and only those six:
  - **Added** — new capabilities, documents, endpoints, or fields.
  - **Changed** — changes to existing behavior that is already released.
  - **Deprecated** — soon-to-be-removed capabilities still present and working.
  - **Removed** — capabilities taken out in this release.
  - **Fixed** — bug fixes.
  - **Security** — anything touching vulnerabilities, isolation, secrets, or auth.
- Versions are `MAJOR.MINOR.PATCH`. A **MAJOR** bump signals a breaking change to a public contract (most
  often a new `/api/vN`, per [ADR-008](./ARCHITECTURE_DECISIONS.md) and
  [../api/API_VERSIONING.md](../api/API_VERSIONING.md)); a **MINOR** bump adds capability
  backward-compatibly; a **PATCH** bump is a backward-compatible fix. During the pre-`1.0.0` design and
  build phase, the API surface is not yet frozen, and the leading `0.` communicates exactly that.
- Each entry names the artifact it touched (a document, an endpoint, a module) so a reader can jump
  straight to it.

## Scope and honesty rule

**This is a specification-stage changelog and it says only true things.** QAYD is, at the date of the
first entry below, a fully-specified platform whose documentation set is complete and whose
implementation is beginning against that specification. This file therefore records the establishment
and evolution of the **specification and its tooling** — it does not, and must not, claim shipped
product features the documents themselves do not claim exist. When application code begins landing,
its entries will appear under `[Unreleased]` and roll into `0.2.0` and beyond. Until then, the honest
statement of record is: the design is done; the build is starting. Any entry that would imply a
running, customer-usable feature that the spec set does not describe is a defect in this file.

---

# Changelog

## [Unreleased]

Nothing yet. New entries land here as work merges, grouped under the six standard headings, and roll
into a dated version section when a release is cut. When the first application code merges, its entry
appears here — for example, a first `Added` line for the Accounting posting engine or the `/api/v1`
authentication endpoints — always describing what actually shipped, never what is merely planned.

### Added
- _(none yet)_

### Changed
- _(none yet)_

### Deprecated
- _(none yet)_

### Removed
- _(none yet)_

### Fixed
- _(none yet)_

### Security
- _(none yet)_

---

## [0.1.0] — 2026-07-22

The **Design Specification** milestone. This release establishes QAYD's complete written architecture
across every documentation domain, the decision log that records the foundational choices, and the
developer-process documents that govern how the platform is built from here. No application code is
part of this milestone; it is the specification that all subsequent code is written against and
reviewed for conformance to.

### Added

**Foundation specification** (`docs/foundation/`)
- Established the platform's founding documents: `MISSION.md`, `PRODUCT_PRINCIPLES.md`,
  `SYSTEM_ARCHITECTURE.md`, `MODULE_ARCHITECTURE.md`, `TECH_STACK.md`, `PROJECT_STRUCTURE.md`,
  `CODING_STANDARDS.md`, `DESIGN_SYSTEM.md`, `COMPANY_STRUCTURE.md`, `PERMISSION_SYSTEM.md`,
  `AUTHENTICATION.md`, `SECURITY_ARCHITECTURE.md`, `DATABASE_ARCHITECTURE.md`, `API_ARCHITECTURE.md`,
  `AI_ARCHITECTURE.md`, and `USER_ONBOARDING.md`.
- Fixed the official technology stack in `TECH_STACK.md`: Next.js 15 / React 19 / TypeScript web tier,
  Laravel 12 / PHP 8.4+ backend, FastAPI / Python AI engine, PostgreSQL, Redis, Cloudflare R2, Laravel
  Reverb realtime, and Flutter mobile — none replaceable without a documented architectural decision.
- Defined the modular-monolith module model, the four internal layers, and the events-not-direct-calls
  communication rule in `MODULE_ARCHITECTURE.md`.

**API specification** (`docs/api/`)
- Established the full API document set, including `API_ARCHITECTURE.md`, `REST_STANDARDS.md`,
  `API_DESIGN_PRINCIPLES.md`, `AUTHENTICATION_API.md`, `AUTHORIZATION_API.md`, `API_VERSIONING.md`,
  `API_ERROR_HANDLING.md`, `API_PAGINATION.md`, `API_FILTERING_SORTING.md`, `API_RATE_LIMITING.md`,
  `API_IDEMPOTENCY.md`, `API_WEBHOOKS.md`, `API_OPENAPI_SPEC.md`, `API_SDK_GUIDELINES.md`,
  `API_TESTING.md`, `API_LOGGING.md`, `API_MONITORING.md`, `API_SECURITY.md`, `INTERNAL_API.md`,
  `PARTNER_API.md`, and `PUBLIC_API.md`.
- Specified `/api/v1` URI-path versioning, the breaking-vs-non-breaking change taxonomy, the five-state
  version lifecycle (`beta → current → deprecated → sunset → retired`), a minimum 12-month deprecation
  window, and the `Deprecation`/`Sunset`/`Link`/`X-Qayd-Api-Version` response-header contract in
  `API_VERSIONING.md`.
- Defined the standard response envelope (`success`, `data`, `message`, `errors`, `meta`, `request_id`,
  `timestamp`) that every endpoint and every client SDK is written against.

**Database specification** (`docs/database/`)
- Established the database document set, including `DATABASE_ARCHITECTURE.md`, `DATABASE_STANDARDS.md`,
  `DATABASE_NAMING_CONVENTIONS.md`, `MULTI_TENANCY.md`, `ROW_LEVEL_SECURITY.md`, `ERD.md`,
  `DATABASE_MIGRATIONS.md`, `DATABASE_INDEXING.md`, `DATABASE_PARTITIONING.md`,
  `DATABASE_CONSTRAINTS.md`, `DATABASE_PERFORMANCE.md`, `DATABASE_CACHING.md`, `DATABASE_AUDIT_LOGS.md`,
  `DATABASE_SOFT_DELETE.md`, `DATABASE_ARCHIVING.md`, `DATABASE_BACKUP_RECOVERY.md`,
  `DATABASE_MONITORING.md`, `DATABASE_SEEDING.md`, `DATABASE_EVENTS.md`, and `DATABASE_VERSIONING.md`.
- Specified single-database multi-tenancy with a `company_id` on every tenant table and defense-in-depth
  isolation culminating in PostgreSQL Row-Level Security (`ENABLE` + `FORCE`) keyed on
  `app.current_company_id`, set per request via `SET LOCAL`.
- Fixed `NUMERIC(19,4)` as the money type for the KWD-base, multi-currency ledger.

**AI specification** (`docs/ai/`)
- Established the AI document set: the `AI_FINANCE_OS.md` and `AI_COMMAND_CENTER.md` overviews; the
  thirteen agent specifications under `agents/` (Accountant, Auditor, Banking, CEO, CFO, Compliance,
  Fraud, Inventory, Payroll, Purchasing, Sales, Tax, Treasury); the memory model under `memory/`
  (Accounting, Business, Company, Knowledge, User); the prompt specifications under `prompts/`; the AI
  tool catalogue under `tools/`; and the AI-run financial workflows under `workflows/`.
- Fixed the non-negotiable rule that the AI engine never writes to the database directly and never
  auto-commits: every AI action is a permission-checked, validated proposal through `/api/v1`, carrying
  a confidence score and reasoning, with a human approval gate on every sensitive operation.

**Frontend specification** (`docs/frontend/`)
- Established the web-client document set: `README.md` (index and conventions), `FRONTEND_ARCHITECTURE.md`,
  `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `LAYOUT_SYSTEM.md`, `NAVIGATION_SYSTEM.md`,
  `RESPONSIVE_DESIGN.md`, `ACCESSIBILITY.md`, `THEMING.md`, `DARK_MODE.md`, `ICONOGRAPHY.md`, and the
  full `components/`, `flows/`, and `screens/` catalogues.
- Fixed the frontend contract: Next.js 15 App Router, TanStack Query as the sole owner of server state,
  the RSC-first / Client-Component-when-needed boundary, shadcn/Radix primitives owned in-repo, and
  bilingual EN/AR with single-root-attribute RTL and `latn`-numeral financial figures.

**Backend specification** (`docs/backend/`)
- Established the service document set: `BACKEND_ARCHITECTURE.md`, `SERVICE_ARCHITECTURE.md`, and the
  per-domain services (`ACCOUNTING_SERVICE.md`, `AI_SERVICE.md`, `AUDIT_SERVICE.md`, `AUTH_SERVICE.md`,
  `AUTOMATION_ENGINE.md`, `BANKING_SERVICE.md`, `FILE_SERVICE.md`, `INVENTORY_SERVICE.md`,
  `NOTIFICATION_SERVICE.md`, `PAYROLL_SERVICE.md`, `REPORTING_SERVICE.md`, `SEARCH_SERVICE.md`,
  `TAX_SERVICE.md`, `WORKFLOW_ENGINE.md`).
- Specified immutable double-entry posting — a posted journal entry is frozen and corrected only by a
  system-generated reversing entry — and one-way backend → Reverb → client realtime.

**Security specification** (`docs/security/`)
- Established `SECURITY_ARCHITECTURE.md`, `AUTHENTICATION.md`, `AUTHORIZATION.md`, `TENANT_ISOLATION.md`,
  `ENCRYPTION.md`, `SECRETS.md`, `DATA_PRIVACY.md`, `AUDIT_LOGS.md`, `API_SECURITY.md`, and
  `AI_SECURITY.md`.
- Fixed the split-credential model: Sanctum SPA cookie sessions for the first-party web app, RS256
  (never HS256) JWTs for the mobile app, AI engine, and third-party integrations, and Sanctum-backed
  API Keys for server-to-server scripts.

**Testing specification** (`docs/testing/`)
- Established `TESTING_STRATEGY.md`, `UNIT_TESTS.md`, `INTEGRATION_TESTS.md`, `E2E_TESTS.md`,
  `LOAD_TESTS.md`, `SECURITY_TESTS.md`, and `AI_EVALUATION.md`.
- Fixed the seven-band, shape-enforced test pyramid (Unit, Integration, Contract, E2E, Load, Security,
  AI-Eval) with its named homes for the posting-balance, tenant-isolation, and AI-proposal invariants.

**Deployment specification** (`docs/deployment/`)
- Established the deployment and operations document set that `RELEASE_PROCESS.md` cross-links for the
  staging → production promotion, rollback, and infrastructure detail.

**Developer process** (`docs/developer/`)
- Added `ARCHITECTURE_DECISIONS.md` — the ADR log recording the twelve foundational decisions
  (modular monolith + separate AI engine; single-DB RLS multi-tenancy; unprivileged web tier;
  split Sanctum/JWT auth; TanStack Query server state; immutable double-entry posting; AI human-in-the-loop;
  `/api/v1` path versioning; `NUMERIC(19,4)` + `latn` numerals; in-repo shadcn/Radix primitives; Reverb
  realtime; the seven-band test pyramid).
- Added `CHANGELOG.md` — this file.
- Added `RELEASE_PROCESS.md` — the SemVer policy, `/api/v1` deprecation policy, branch/tag strategy,
  release checklist, staging → production promotion, expand-contract migration safety, rollback and
  hotfix flows, release notes and customer communications, and mobile-app/SDK versioning.
- Added `CONTRIBUTING.md` — the contribution and review workflow the release checklist depends on.

### Security
- Documented the complete tenant-isolation model (Eloquent global scope + explicit repository filter +
  PostgreSQL RLS with `FORCE`) and the RS256-only JWT signing policy as part of establishing the
  security specification; both are captured as decisions in `ARCHITECTURE_DECISIONS.md`
  ([ADR-002](./ARCHITECTURE_DECISIONS.md), [ADR-004](./ARCHITECTURE_DECISIONS.md)).

---

# Maintaining This File

- **Every merged change that a reader outside the pull request would care about gets a line**, under the
  correct one of the six groups, in `[Unreleased]`, in the same pull request that makes the change. A
  pull request that changes observable behavior without a changelog line is incomplete — this is one of
  the gates in the [release checklist](./RELEASE_PROCESS.md).
- **Write for the reader, not the committer.** "Renamed the `debit` field to `debit_amount` on
  `POST /api/v1/accounting/journal-entries`; send amounts as strings" is useful; "refactored controller"
  is not.
- **Breaking changes are called out explicitly** and link their migration guide; they drive the MAJOR
  version bump and the deprecation process in [RELEASE_PROCESS.md](./RELEASE_PROCESS.md).
- **Do not invent history.** If a capability is planned but not merged, it does not belong in this file
  — it belongs in a roadmap. The changelog records only what has actually landed.

# End of Document
