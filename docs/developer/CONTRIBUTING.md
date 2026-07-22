# Contributing to QAYD — QAYD Developer
Version: 1.0
Status: Design Specification
Module: Developer
Submodule: CONTRIBUTING
---

# Purpose

This document defines how a change gets into QAYD: how you branch, how you name a commit, how you open
a pull request, what must be true before that pull request can merge, and who has to approve it. It is
the single procedural contract that every contributor — human engineer or AI coding agent — follows,
regardless of which of the five packages in the monorepo they are touching. It does not teach you
*how to write* QAYD code (that is [CODING_GUIDELINES.md](./CODING_GUIDELINES.md)), nor how to stand up
a local environment (that is [LOCAL_SETUP.md](./LOCAL_SETUP.md)), nor how a release is cut
([RELEASE_PROCESS.md](./RELEASE_PROCESS.md)); it defines the workflow that connects a working tree on
your machine to the `main` branch everyone else builds on.

QAYD is an AI Financial Operating System: a multi-tenant, double-entry accounting core wrapped in an
AI layer, split across three independently deployable codebases and one shared database package. A
missed check here is not a cosmetic regression — it is a wrong debit in a customer's ledger, a leaked
row across a tenant boundary, or an English label with no Arabic counterpart shipped to a bilingual
audience. The process below exists to make those failures impossible to merge, not merely
discouraged. Nothing in it is optional, and no reviewer may waive a required gate by hand.

The monorepo layout this document assumes is fixed by
[../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md):

```text
QAYD/
├── frontend/     Next.js 15 / React 19 / TypeScript (strict) — web client
├── backend/      Laravel 12 / PHP 8.4 — the only write path to business state
├── ai/           FastAPI / Python (typed) — reasons, never writes to the database
├── database/     migrations, seeders, factories, ERD, schema snapshots
├── mobile/       Flutter — native iOS/Android client
├── docs/         this documentation tree
└── .github/      workflows, CODEOWNERS, issue/PR templates
```

# Before You Start

Two rules govern *whether* work should begin at all, and both come from the platform's non-negotiable
principles.

1. **Every change traces to an issue.** A branch that does not close or reference a tracked issue does
   not merge. The issue is where scope, acceptance criteria, and the affected packages are agreed
   *before* code exists. Trivial fixes (a typo, a broken link) may use a one-line issue, but they
   still get one, so the changelog and audit trail stay complete.
2. **Scope is single-purpose.** One pull request changes one thing. A PR that "also refactors the
   query client while adding the trial-balance export" is two PRs. This keeps review honest, keeps
   `git bisect` meaningful, and keeps a revert surgical — which matters more in a ledger system than
   in most software, because a bad revert can strand a half-posted transaction.

If your change spans packages — say, a new `/api/v1` field consumed by both the frontend and the
Flutter app — that is allowed and expected in a monorepo, but it is still *one logical change* and
ships behind one issue, with the contract change (backend + `database/` + OpenAPI) landing in the same
PR as, or a clearly-prior PR to, the clients that consume it. A client must never merge against a
contract that does not yet exist on `main`.

# Branch Strategy

QAYD uses trunk-based development with short-lived feature branches off `main`. `main` is always
releasable: every commit on it has passed the full CI gate set, and any commit on it can be tagged and
deployed. There are no long-running `develop` or per-release branches; release trains are cut as tags
from `main` (see [RELEASE_PROCESS.md](./RELEASE_PROCESS.md)).

Branch names are lowercase, slash-delimited, and lead with a type that matches the Conventional
Commit types below, followed by the issue key and a short kebab-case slug:

```text
<type>/<issue-key>-<short-slug>
```

| Branch | Meaning |
|---|---|
| `feat/QAYD-812-trial-balance-export` | A new capability tied to issue QAYD-812. |
| `fix/QAYD-833-reconciliation-rounding` | A bug fix. |
| `refactor/QAYD-790-invoice-action-split` | Behaviour-preserving restructuring. |
| `chore/QAYD-801-bump-node-20-11` | Tooling, dependencies, non-shipping housekeeping. |
| `docs/QAYD-777-contributing-guide` | Documentation only. |

Rules:

- **Branch from the latest `main`**, and rebase (never merge) `main` back into your branch to stay
  current. QAYD keeps a linear history; merge commits inside a feature branch are noise that a rebase
  removes. `git pull --rebase origin main` is the update loop.
- **A branch is short-lived** — days, not weeks. A branch open long enough to drift badly against
  `main` is a signal the work was scoped too large; split it.
- **Never push to `main`.** It is a protected branch: no direct pushes, no force-pushes, no merges
  without a green PR and the required approvals. This is enforced by branch protection, not honour
  system.
- **Delete the branch on merge.** The squash-merge preserves the history; the branch does not need to
  linger.

# Commit Conventions

QAYD commit messages follow [Conventional Commits](https://www.conventionalcommits.org/) 1.0.0. This
is not cosmetic: the changelog and the semantic-version bump for each release are generated
mechanically from commit history (see [RELEASE_PROCESS.md](./RELEASE_PROCESS.md)), so a malformed
subject line breaks the release tooling. A CI check (`commitlint`) validates every commit on a PR and
the squashed subject on merge.

The subject grammar:

```text
<type>(<scope>): <imperative summary, no trailing period, <= 72 chars>

<optional body — the WHY, wrapped at 72 columns>

<optional footer — BREAKING CHANGE:, Refs:, Co-Authored-By:>
```

Types (aligned with the branch prefixes above):

| Type | Use for | Version effect |
|---|---|---|
| `feat` | A user-facing capability. | minor |
| `fix` | A bug fix. | patch |
| `refactor` | Behaviour-preserving internal change. | none |
| `perf` | A change that improves performance without changing behaviour. | patch |
| `docs` | Documentation only. | none |
| `test` | Adding or correcting tests only. | none |
| `chore` | Build, tooling, dependencies, CI. | none |
| `revert` | Reverting a prior commit. | context-dependent |

Scope is the package or module the change lives in — `frontend`, `backend`, `ai`, `database`,
`mobile`, or a finer module (`accounting`, `banking`, `i18n`, `auth`). Examples:

```text
feat(accounting): post AI-drafted journal entries behind accounting.journal.post

The proposal-commit path now constructs the same JournalEntryData DTO a human
composer produces, so an AI-originated and human-originated entry run through
one PostJournalEntryAction and one balance invariant. Adds the review drawer
and the ai.approve gate on the commit control.

Refs: QAYD-812
Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

A change that alters the `/api/v1` contract in a backward-incompatible way carries a
`BREAKING CHANGE:` footer describing the migration path; this forces a major-version consideration and
is surfaced to reviewers and consumers automatically.

# The `en` + `ar` Same-Commit Rule

QAYD ships English and Arabic on day one, and the two dictionaries are a single source of truth split
across two files, sharing one `Dictionary` type. **Any commit that adds, renames, or removes a
user-facing string adds, renames, or removes it in both `lib/i18n/en.ts` and `lib/i18n/ar.ts` in that
same commit.** A key that exists in one file and not the other is a compile-time failure of the shared
`Dictionary` type and a hard failure of `npm run i18n:check` in CI — it is never a silent runtime
fallback to the key name.

This rule is not a style preference; it is why QAYD has no "translate later" backlog and no screen
that renders `journalEntries.postAction` verbatim to an Arabic user. If you genuinely do not yet have
the reviewed Arabic copy, you commit a marked draft (`ar` value present, flagged for localization
review by a `// L10N-REVIEW` comment and the localization label on the PR) — you never commit a
missing key. The same discipline applies to the Flutter app's ARB dictionaries and any backend-issued,
user-facing string (validation messages, notification bodies): the translation lands with the string,
in the same commit, in every language QAYD supports.

The RTL corollary belongs to code, not copy, and is covered in
[CODING_GUIDELINES.md](./CODING_GUIDELINES.md): a new string is bilingual in the same commit, and the
component rendering it uses logical CSS properties so it mirrors correctly with no second code path.

# Pull Request Process

## Opening a PR

Push your branch and open a PR against `main` using the repository PR template (below). The PR
description is not a formality — it is the artifact a reviewer reads first and the record that
survives in history. It must state, at minimum: what changed and why, which packages are affected,
the issue it closes, how it was verified, and any contract or migration implications.

The PR template (`.github/pull_request_template.md`):

```markdown
## Summary
<!-- What changed and why. Link the issue: Closes QAYD-XXX -->

## Packages touched
- [ ] frontend
- [ ] backend
- [ ] ai
- [ ] database
- [ ] mobile
- [ ] docs

## Type of change
- [ ] feat  - [ ] fix  - [ ] refactor  - [ ] perf  - [ ] docs  - [ ] chore

## Contract / data impact
- [ ] Changes the /api/v1 contract (OpenAPI updated, version considered)
- [ ] Adds or changes a database migration (reversible, tenant-scoped)
- [ ] Adds or changes a permission key (mirrored backend + frontend)
- [ ] No contract or data impact

## i18n
- [ ] Added/changed user-facing strings — updated en.ts AND ar.ts in this PR
- [ ] No user-facing string changes

## Verification
<!-- Commands run and their result; screenshots for UI in light+dark, LTR+RTL -->

## Definition of Done
- [ ] All required CI gates green
- [ ] Tests added/updated for the change
- [ ] Docs updated (or N/A)
- [ ] Code-owner review obtained for each touched area
```

## Draft vs. ready

Open early as a **draft** to run CI and gather informal feedback; mark it **ready for review** only
when every box you can check yourself is checked and CI is green. Requesting review on a red PR wastes
a reviewer's pass — the first thing they would do is wait for CI anyway.

## Size

Keep PRs reviewable. A diff a reviewer can hold in their head reviews well; a 2,000-line diff gets
rubber-stamped, which in a ledger system is how wrong debits ship. If a change is genuinely large,
stage it: land the contract and migration first, then the backend Action, then the client — each its
own green, reviewed PR.

# Review Requirements

## Code-owner review

Every path in the repository has an owner declared in `.github/CODEOWNERS`. A PR cannot merge until
**every owner of every touched path has approved**. This is enforced by branch protection; it is not a
courtesy request.

| Path pattern | Owner (illustrative) |
|---|---|
| `backend/app/**` | `@qayd/backend` |
| `backend/app/Services/Accounting/**`, `database/migrations/**` | `@qayd/backend` + `@qayd/ledger` |
| `frontend/**` | `@qayd/frontend` |
| `frontend/lib/i18n/**`, `**/ar.ts` | `@qayd/frontend` + `@qayd/localization` |
| `ai/**` | `@qayd/ai` |
| `**/permissions*`, `backend/app/Policies/**` | `@qayd/security` |
| `docs/**` | `@qayd/docs` |

A change that touches ledger posting, tenant scoping, permissions, or money math draws a second,
security- or ledger-owning reviewer *in addition to* the package owner — the CODEOWNERS patterns above
make that automatic. A single approval never merges a change to a financial invariant.

## What a reviewer verifies

Reviewers check for correctness, security, and adherence to
[CODING_GUIDELINES.md](./CODING_GUIDELINES.md) — not for things CI already proves. CI proves the code
lints, type-checks, tests pass, and dictionaries match; a reviewer must not re-litigate those. A
reviewer spends their attention on what tooling cannot see:

- Is the business rule correct, and is it enforced on the backend (never only client-side)?
- Does every query stay inside the tenant boundary (no raw unscoped query, no `withoutCompanyScope`
  outside the sanctioned platform-admin path)?
- Is every sensitive action permission-gated with the right key, and is maker ≠ checker preserved?
- Does an AI-originated number carry its confidence and reasoning, and does every sensitive AI action
  render an explicit human approve/reject affordance rather than auto-committing?
- Is the change tested at the right layer (a unit test for the invariant, not just an E2E that happens
  to touch it)?

## Approving and merging

Merge is **squash-and-merge only**, producing one Conventional-Commit-formatted commit on `main` per
PR. The person who merges is the author (once approvals and CI are green), not the reviewer — the
reviewer approves; the author owns landing it. Force-merging past a failing required check is
impossible by configuration and forbidden by policy.

# Required CI Gates

Every PR runs the full gate matrix; **all of it must be green before merge**, and none of it is
overridable by a reviewer. The gates below map one-to-one onto the commands you run locally (see
[LOCAL_SETUP.md](./LOCAL_SETUP.md) and each package's README) — CI runs exactly what you can run, so a
green local run predicts a green CI run.

| Gate | Frontend (`frontend/`) | Backend (`backend/`) | AI (`ai/`) |
|---|---|---|---|
| Lint | `npm run lint` (ESLint: typescript-eslint, jsx-a11y, react-hooks) | `composer pint -- --test` (Laravel Pint / PSR-12) | `ruff check .` |
| Format | Prettier check (`prettier-plugin-tailwindcss` ordering) | Pint (same gate) | `black --check .` |
| Typecheck | `npm run typecheck` (`tsc --noEmit`, strict) | `composer stan` (PHPStan max) | `mypy --strict .` |
| Unit tests | `npm run test` (Vitest + Testing Library) | `php artisan test --testsuite=Unit` (Pest) | `pytest tests/unit` |
| Integration tests | Vitest + MSW against mocked `/api/v1` | `php artisan test --testsuite=Feature` (real test PostgreSQL + RLS) | `pytest tests/contract` |
| E2E tests | `npm run test:e2e` (Playwright, 4-variant visual: light/dark × LTR/RTL) | — | — |
| Contract | OpenAPI diff + Spectator validation | Spectator + Schemathesis against `openapi/qayd-api-v1.yaml` | shared AI-proposal JSON Schema |
| i18n | `npm run i18n:check` (en ⟷ ar key parity) | user-facing message parity | — |
| Security scan | dependency audit, secret scan | dependency audit, secret scan, **RLS negative suite** (tenant isolation) | dependency audit, secret scan |
| Architecture | — | Pest arch tests (every tenant model uses `BelongsToCompany`; no repo uses `DB::table`) | import-boundary check |

Cross-cutting gates run once for the whole PR regardless of package: the **secret scan** (no key,
token, or credential ever enters history), the **dependency audit** (no known-vulnerable dependency),
and — because tenant isolation is the property a customer trusts most — the **RLS negative suite**,
which asserts that company A receives a 404, never a leaked row, for company B's resources. A red
security or RLS gate is never waved through; it is fixed.

The full pipeline definition, stage ordering, and coverage thresholds live in
[../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md); this document states only that the
gates exist, run on every PR, and block merge.

# Definition of Done

A change is *done* — mergeable — only when all of the following hold. This is the checklist the PR
template encodes and the reviewer confirms; it is the release checklist from
[../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md) applied per change.

1. **The behaviour is correct and specified.** It matches the issue's acceptance criteria; a business
   rule it introduces is enforced on the backend.
2. **It is tested at the right layer.** New logic ships with the test that proves it — a unit test for
   an invariant or a form schema, a feature test through `/api/v1` for an endpoint, a Playwright
   happy-path for a new screen. Coverage does not regress.
3. **All required CI gates are green.** Lint, format, typecheck, unit, integration, E2E (frontend),
   contract, i18n, security/RLS, and architecture — every one.
4. **Tenancy and authority hold.** Every query is company-scoped; every sensitive action is
   permission-gated with the correct key; maker ≠ checker where required.
5. **Bilingual in the same commit.** Every new user-facing string exists in both `en` and `ar`
   (and the Flutter ARBs, if the mobile app renders it); the component mirrors correctly in RTL.
6. **AI is visible, never silent.** Any AI-originated figure carries confidence and reasoning; any
   sensitive AI action renders an explicit human decision affordance.
7. **Docs updated.** A new endpoint, permission key, env var, or module updates the relevant document
   in `docs/` in the same PR — a feature is not complete without its documentation.
8. **Migrations are safe.** Any `database/` change is reversible (`down()` present and tested),
   tenant-scoped, and leads its indexes with `company_id`.
9. **Code-owner review obtained** for every touched area, with the additional ledger/security reviewer
   where the paths above require it.

A PR that cannot check every box is not "almost done" — it is not done, and it waits.

# Issue Reporting

Issues use the templates in `.github/ISSUE_TEMPLATE/`. A good issue is the cheapest correctness
control QAYD has, because it settles scope before code exists.

| Template | Use for | Must include |
|---|---|---|
| `bug_report.md` | Something behaves wrong. | Expected vs. actual, reproduction steps, affected package(s), whether money/tenant data is involved (escalates severity), environment. |
| `feature_request.md` | A new capability. | The user problem, acceptance criteria, affected packages, contract/permission/i18n impact. |
| `contract_change.md` | An `/api/v1` change. | The endpoint(s), the shape diff, consumers affected (frontend/mobile/SDK), and backward-compatibility plan. |
| `security.md` | A vulnerability. | Filed privately per the security policy; never a public issue for an exploitable defect. |

A bug that touches a customer's ledger, crosses a tenant boundary, or exposes PII is **P0** by
definition and is triaged immediately regardless of the reporter — the "money or tenant data involved"
checkbox exists precisely to force that classification.

# Related Documents

- [CODING_GUIDELINES.md](./CODING_GUIDELINES.md) — how QAYD code is written across TypeScript, PHP,
  and Python; the tenancy discipline, the `/api/v1` envelope, and formatting/linting rules whose gates
  this document enforces.
- [LOCAL_SETUP.md](./LOCAL_SETUP.md) — standing up all three services locally and running every
  quality gate before you push.
- [RELEASE_PROCESS.md](./RELEASE_PROCESS.md) — how `main` becomes a tagged, deployed release, and how
  the Conventional-Commit history drives the changelog and version bump.
- [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) — the full CI pipeline, the test
  pyramid, coverage thresholds, and the flaky-test quarantine process behind the gates above.
- [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md),
  [../foundation/CODING_STANDARDS.md](../foundation/CODING_STANDARDS.md) — the monorepo layout and
  platform coding standards this document operationalizes.

# End of Document
