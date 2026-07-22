# 14 — Development Plan — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: Development
---

# Purpose

This chapter is the development plan for QAYD at PRD altitude: how the product versions of
[13_ROADMAP.md](./13_ROADMAP.md) actually get built, tested, and shipped. It describes the execution
roadmap and sprint model, the milestones that mark real progress, the engineering workflow that moves
a change from a working tree to `main`, the seven-band testing pyramid that proves the platform
correct, and the release-and-deployment strategy that makes shipping boring. It is a map of the
delivery discipline, not a restatement of it — the authoritative mechanics live in the sprint docs,
the testing strategy, and the developer guides, and this chapter routes to them rather than copying
them.

QAYD is an AI Financial Operating System split across three independently deployable codebases and one
shared database package: a Laravel 12 / PHP 8.4 backend (the single write path to business state), a
Next.js 15 / React 19 web client, and a FastAPI / Python AI engine that reasons but never writes the
database, with a Flutter mobile client arriving at scale. A missed check in this programme is not a
cosmetic regression — it is a wrong debit in a customer's ledger, a row leaked across a tenant
boundary, or an English label with no Arabic counterpart. The whole plan below exists to make those
failures impossible to merge, not merely discouraged.

# Execution roadmap

The programme is delivered in the dependency-ordered phases of
[../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md), which map onto the product versions in
[13_ROADMAP.md](./13_ROADMAP.md). The near-term, fully-detailed slice is the MVP (product V1): an
approximately eight-week core build of four two-week sprints against a small senior team, followed by
a hardening and pilot window before general availability. Later versions are placed on the roadmap's
directional calendar and re-baselined per phase; their dates are planning baselines, not commitments,
and slippage in an earlier phase pushes later phases rather than overlapping them across a dependency
boundary.

The MVP build order is fixed by [../MODULE_DEPENDENCIES.md](../MODULE_DEPENDENCIES.md): foundations and
the ledger skeleton first, the live ledger and banking ingestion next, the AI engine and draft loop
third, and reconciliation, approvals, reporting, and hardening last. Nothing that depends on the
ledger is scheduled before the ledger exists; nothing AI-facing is scheduled before the API surface it
calls exists.

| Sprint | Product-facing outcome | Detail |
|---|---|---|
| Sprint 01 — Foundations | A signed-in user in a seeded tenant sees an empty dashboard, and every layer that will later protect their ledger is already in place and tested. | [../SPRINT_01.md](../SPRINT_01.md) |
| Sprint 02 — Accounting core | Post and view a balanced journal entry end to end; an unbalanced entry is refused and a posted entry cannot be edited. | [../SPRINT_02.md](../SPRINT_02.md) |
| Sprint 03 — Banking and reconciliation | Import a statement, reconcile it with AI suggestions, and close the period — the first visible AI touchpoint. | [../SPRINT_03.md](../SPRINT_03.md) |
| Sprint 04 — AI drafting and reporting | An AI-drafted journal entry, approved by a human, and generated financial statements — QAYD is a demoable MVP. | [../SPRINT_04.md](../SPRINT_04.md) |

# Sprint model

QAYD builds in **two-week sprints (10 working days)** against a single senior, cross-functional squad
carried across all four MVP sprints — a tech lead, two Laravel engineers, two Next.js engineers, one
FastAPI engineer (ramping to full load from Sprint 03), a shared platform/DevOps engineer, a QA/SDET,
and a part-time product-plus-design pairing. Target velocity is roughly 48 story points on a Fibonacci
scale, with each sprint committing slightly under target to absorb friction. The ceremony rhythm is
fixed: planning on day 1, a daily stand-up, a mid-sprint architecture or design review on the
highest-risk story, and a demo plus retrospective on day 10. The team composition and cadence
assumption for the whole programme is in [../README.md](../README.md); the per-sprint squad and
ceremonies are in each sprint doc.

Two disciplines make the sprint model trustworthy rather than theatrical:

- **A story ends in a demoable, tested increment.** Acceptance criteria are tested assertions, not
  aspirations — an unbalanced draft *throws*, a cross-tenant read *returns 404*, a sensitive AI
  proposal *requires approval regardless of confidence*.
- **A sprint's exit is unforgiving and end-to-end.** The demo/exit criteria in each sprint doc must be
  demonstrable live against the demo environment with the money-critical tests green in CI; a sprint
  does not claim a capability it has only half-built.

# Milestones

Milestones mark the points where QAYD's claim about itself measurably changes. They are the same
events as the version and sprint exits, named for what they unlock.

| Milestone | Meaning | Governing document |
|---|---|---|
| **Foundation proven** | Tenant isolation fails closed; a person becomes a signed-in, scoped user; the shell is bilingual and themable. | [../SPRINT_01.md](../SPRINT_01.md) |
| **Ledger alive** | The double-entry invariant is code: balanced, atomic, immutable posting with a rebuildable ledger projection. | [../SPRINT_02.md](../SPRINT_02.md) |
| **First AI touchpoint** | The AI visibly helps — reconciliation suggestions with confidence — and never silently commits. | [../SPRINT_03.md](../SPRINT_03.md) |
| **MVP (V1) complete** | The seven-step MVP journey works on a real company's books; all pass/fail invariants hold. | [../MVP_SCOPE.md](../MVP_SCOPE.md) |
| **V2 / V3 / V4 exits** | Each later product generation meets its phase exit gate. | [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md) |

A milestone is reached only when the relevant slice of the test pyramid passes; feature-completeness
alone never marks a milestone, because in a ledger system the tests are what make the feature true.

# Engineering workflow

The workflow that connects a working tree to `main` is one procedural contract every contributor —
human engineer or AI coding agent — follows, defined authoritatively in
[../../developer/CONTRIBUTING.md](../../developer/CONTRIBUTING.md). At PRD altitude its shape is:

- **Trunk-based, short-lived branches.** `main` is always releasable; every commit on it has passed
  the full CI gate set. Feature branches are days-not-weeks, named `<type>/<issue-key>-<slug>`, branch
  from the latest `main`, and rebase (never merge) `main` back in to keep a linear history. Nobody
  pushes to `main` — it is a protected branch enforced by configuration, not honour.
- **Every change traces to an issue, and scope is single-purpose.** One pull request changes one
  thing; a PR that "also refactors the query client while adding the trial-balance export" is two PRs.
  This keeps review honest, `git bisect` meaningful, and a revert surgical — which matters more in a
  ledger system, where a bad revert can strand a half-posted transaction.
- **Conventional Commits drive the release.** Commit subjects follow Conventional Commits 1.0.0
  (`<type>(<scope>): summary`), because the changelog and the SemVer bump for each release are
  generated mechanically from history; a malformed subject breaks the release tooling. Commit messages
  end with the `Co-Authored-By: Claude Opus 4.8` footer where an AI agent contributed.
- **The `en` + `ar` same-commit rule.** Any commit that adds, renames, or removes a user-facing string
  does so in both `en.ts` and `ar.ts` in the *same commit*; a key present in one and not the other is a
  compile-time failure of the shared `Dictionary` type and a hard failure of `i18n:check`. This is why
  QAYD has no "translate later" backlog and no screen that renders a key verbatim to an Arabic user.
- **Pull requests are reviewed by code owners, squash-merged.** Every touched path has an owner in
  `CODEOWNERS`; a change to ledger posting, tenant scoping, permissions, or money math draws a second
  security- or ledger-owning reviewer automatically — a single approval never merges a change to a
  financial invariant. Merge is squash-and-merge only, producing one Conventional-Commit-formatted
  commit on `main` per PR, landed by the author once approvals and CI are green. A reviewer spends
  attention on what tooling cannot see: is the business rule correct and enforced on the backend, does
  every query stay inside the tenant boundary, is every sensitive action permission-gated with
  maker ≠ checker preserved, does an AI-originated number carry its confidence and reasoning, and is
  the change tested at the *right layer*.

*How QAYD code is written* — thin controllers over a rich Action/Service/DTO layer, `Money` as a
fixed-scale value object never a float, ambient tenancy via `BelongsToCompany`/`CompanyScope`/RLS,
logical CSS properties for RTL, and the `/api/v1` envelope — is the subject of
[../../developer/CODING_GUIDELINES.md](../../developer/CODING_GUIDELINES.md) and the platform standard
in [../../foundation/CODING_STANDARDS.md](../../foundation/CODING_STANDARDS.md), indexed in
[15_APPENDICES.md](./15_APPENDICES.md).

## Definition of done

"Done" in QAYD is a per-change application of the module-architecture release checklist, confirmed by
the reviewer and encoded in the pull-request template. A change merges only when the behavior is
correct and specified against its issue's acceptance criteria and enforced on the backend; it is tested
at the right layer (a unit test for an invariant, not just an E2E that happens to touch it) with no
coverage regression; every required CI gate is green; tenancy and authority hold (every query
company-scoped, every sensitive action permission-gated, maker ≠ checker where required); every new
user-facing string is bilingual in the same commit and mirrors correctly in RTL; any AI-originated
figure carries its confidence and reasoning with an explicit human decision affordance on sensitive
actions; the relevant `docs/` document is updated in the same change; and any migration is reversible,
tenant-scoped, and RLS-forced. A pull request that cannot check every box is not "almost done" — it is
not done, and it waits. The authoritative list is in
[../../developer/CONTRIBUTING.md](../../developer/CONTRIBUTING.md).

# Testing and QA — the seven-band pyramid

QAYD proves itself correct before any change reaches a customer's ledger through a **seven-band test
pyramid** whose shape is *audited, not aspirational*. The cheap lower bands are numerous and run on
every commit; the expensive upper bands are few and gated at merge or release. A CI audit flags
directories that have grown top-heavy, and no upper band is ever accepted as a substitute for a lower
one — an E2E test that happens to hit a journal-entry endpoint does not excuse that endpoint from a
backend feature test. The authoritative strategy, tool matrix, coverage floors, and CI stage ordering
are in [../../testing/TESTING_STRATEGY.md](../../testing/TESTING_STRATEGY.md); the decision that fixes
the pyramid is ADR-012.

| Band | Owns | Runs on |
|---|---|---|
| Unit | Pure logic in isolation — the posting-engine balance invariant, value objects, policies, Zod schemas, formatters, deterministic AI tool functions and the autonomy resolver | Every commit |
| Integration | A unit wired to real neighbors — `/api/v1` feature tests through auth → tenant → RBAC → validation → service → DB → envelope; RLS negative tests; migration tests | Every commit |
| Contract | The shapes crossing a codebase boundary — OpenAPI validation, the shared `ai/proposals` JSON Schema | Every commit (validate), nightly (fuzz) |
| E2E | A whole user journey through the browser — Playwright with four-variant visual regression (light/dark × LTR/RTL) | PR smoke subset, full nightly |
| Load | Latency/throughput under concurrency — k6 | PR smoke (blocking), nightly ramp, pre-release soak |
| Security | Tenant-isolation/RLS negative suite, permission matrix, secret and dependency scanning | Every PR + scheduled |
| AI-Eval | Model answer quality | Scheduled in the AI repo (non-gating in the platform pipeline) |

Target composition is roughly 60% unit / 26% integration / 6% contract / 4% E2E / 2% load / 2%
security. What matters more than the numbers is that QAYD's three most load-bearing invariants each
have a *named home* so they can never be orphaned: **tenant isolation** in the backend integration and
security bands (company A gets a 404, never a leaked row); **`debit = credit` and posted immutability**
in the backend unit and integration bands; and **AI never auto-commits a sensitive action** in the
autonomy-resolver unit truth table plus the proposal-schema contract test. Financial and tenancy paths
carry the highest coverage floor deliberately — 95% line / 90% branch — because there a regression is a
wrong number in a real ledger. Quality is not a separate seat: every feature ships with its tests, and
no feature is "done" without them. Flakiness is a debt with a ceiling — flaky tests are quarantined
(still running, still reporting, never silently skipped) with a ticket, never blanket-retried into a
silently-shipping bug.

# Release strategy and deployment

QAYD's release goal is to be **boring** — frequent, reversible, and non-surprising — which for
financial software consumed by month-end close scripts, bank reconciliation crons, and tax-filing
integrations is the highest praise a release process can earn. The authoritative process is
[../../developer/RELEASE_PROCESS.md](../../developer/RELEASE_PROCESS.md); its load-bearing rules are:

- **Semantic versioning driven by what merged.** The platform release is `MAJOR.MINOR.PATCH`;
  breaking-vs-non-breaking is not a release-time judgment call but a classification applied at
  code-review time — if any plausible unmodified client would throw, mis-parse, or mis-compute, it is
  breaking. When in doubt, treat it as breaking: an unnecessary major bump is far cheaper than a
  silently corrupted ledger.
- **URI-path API versioning with a 12-month sunset.** The API is versioned at `/api/v1`; non-breaking
  additive changes ship continuously without a path change, and only a breaking change introduces
  `/api/v2`. Both majors run in production simultaneously for the deprecation window — a minimum of 12
  months for any version with a live third-party integration — and a deprecated version emits
  machine-readable `Deprecation`, `Sunset`, and `Link` headers. Business logic (Services and
  Repositories) is never versioned, so a debit/credit check or a tax calculation can never fork between
  `v1` and `v2` clients. This is ADR-008.
- **Expand → migrate → contract migrations.** A schema change that would break a running older version
  is split across releases: add the new structure (nullable/defaulted), backfill and dual-write, and
  only drop the old shape in a later release after the last version that read it has retired. A rename
  is therefore never a rename. Every new tenant table enables *and forces* Row-Level Security in its
  creating migration, and posted financial data is never mutated by a migration — a correction is a
  reversing entry through the application, never an `UPDATE`.
- **Promote one artifact, never rebuild.** CI builds the release artifact once from the tagged commit
  and records its digest; that exact digest moves from staging to production, so what was verified is
  what ships. Migrations run ahead of the code that depends on them; post-deploy health, error-rate,
  and latency dashboards plus a synthetic smoke of the critical paths (login, post a journal entry in a
  test company, fetch a report) confirm health before the release is declared done.
- **Rollback and hotfix are first-class.** Because artifacts are promoted (not rebuilt) and migrations
  are expand-only within a window, rollback is redeploying the previous tag's immutable artifact, not
  running a destructive down-migration in production. A hotfix branches from the release tag being
  fixed, carries the smallest possible change, still clears the full CI gate set on the fast path, and
  merges back everywhere so it is never lost in the next regular release.
- **Releases speak in both languages.** User-facing release notes are written in English and Arabic
  consistent with the platform's bilingual requirement, and API lifecycle events reach integrators as
  webhooks and company admins as dashboard banners.

The infrastructure, environments, and deploy mechanics the release process runs on are owned by the
deployment specification, reached through
[../../developer/RELEASE_PROCESS.md](../../developer/RELEASE_PROCESS.md). Launch-time posture questions
that shadow this plan — single-region versus multi-region at launch, the dev-on-Hetzner/prod-on-AWS
split, and AI spend governance — are tracked, with owners and recommendations, in
[../OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md).

## Related Documents

- [../SPRINT_01.md](../SPRINT_01.md), [../SPRINT_02.md](../SPRINT_02.md), [../SPRINT_03.md](../SPRINT_03.md), [../SPRINT_04.md](../SPRINT_04.md) — the four MVP sprints this plan sequences.
- [../FEATURE_ROADMAP.md](../FEATURE_ROADMAP.md), [../MVP_SCOPE.md](../MVP_SCOPE.md), [../MODULE_DEPENDENCIES.md](../MODULE_DEPENDENCIES.md), [../README.md](../README.md) — the phase plan, MVP cut, build-order graph, and team/cadence assumptions.
- [../OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md) — the launch-posture and ops decisions still open.
- [../../testing/TESTING_STRATEGY.md](../../testing/TESTING_STRATEGY.md), [../../testing/UNIT_TESTS.md](../../testing/UNIT_TESTS.md), [../../testing/INTEGRATION_TESTS.md](../../testing/INTEGRATION_TESTS.md) — the seven-band pyramid, coverage floors, and CI gates.
- [../../developer/CONTRIBUTING.md](../../developer/CONTRIBUTING.md), [../../developer/CODING_GUIDELINES.md](../../developer/CODING_GUIDELINES.md), [../../developer/RELEASE_PROCESS.md](../../developer/RELEASE_PROCESS.md), [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — the workflow, coding, release, and decision record.
- [../../foundation/PROJECT_STRUCTURE.md](../../foundation/PROJECT_STRUCTURE.md), [../../foundation/CODING_STANDARDS.md](../../foundation/CODING_STANDARDS.md) — the monorepo layout and platform coding standards.
- [13_ROADMAP.md](./13_ROADMAP.md), [15_APPENDICES.md](./15_APPENDICES.md) — the product versions this plan delivers and the reference appendices it draws on.

# End of Document
