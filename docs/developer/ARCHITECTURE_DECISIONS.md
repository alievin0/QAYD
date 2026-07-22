# Architecture Decision Records — QAYD Developer
Version: 1.0
Status: Design Specification
Module: Developer
Submodule: Architecture Decisions
---

# Purpose

This document is QAYD's **Architecture Decision Record (ADR) log** — the single, append-only history
of the load-bearing technical decisions the platform has already made, why each was made, and what
each one costs. QAYD is an AI Financial Operating System: a multi-tenant, bilingual (English /
Arabic), double-entry accounting core with a Laravel 12 backend as the single source of truth, a
Next.js 15 web client, a Flutter mobile app, and a FastAPI/Python AI engine that acts through the same
API as any other client. A system of that shape lives or dies by the consistency of a small number of
foundational choices. When those choices are written down once, with their context and their
consequences, an engineer — or an AI coding agent — reading a controller, a migration, or a component
can tell the difference between "this is how it is because someone decided so, on purpose" and "this
is an accident nobody has cleaned up yet." That distinction is the entire value of an ADR log.

The decisions recorded here are not proposals. They are the decisions already fixed by the platform's
foundation, API, database, AI, frontend, backend, security, and testing specifications, restated in
ADR form so they have one canonical home and one changelog. Every ADR below cross-links the
specification document(s) that own its detail; the ADR states the decision and its trade-offs, and the
linked document remains authoritative for the mechanics.

# What an ADR Is

An Architecture Decision Record captures **one** architecturally significant decision. "Architecturally
significant" means the decision is expensive to reverse, constrains many future decisions, or is the
kind of thing a new engineer would otherwise have to reverse-engineer from the code and get wrong.
Choosing Tailwind's class order is not architecturally significant; choosing single-database
multi-tenancy with row-level security is — it shapes every migration, every query, every test, and
every incident review for the life of the platform.

QAYD ADRs follow the widely-used Michael Nygard format. Every ADR in this log has exactly these
sections:

| Section | What it holds |
|---|---|
| **Title** | `ADR-NNN: <short imperative phrase>` — the decision, not the problem. |
| **Status** | One of `Proposed`, `Accepted`, `Superseded by ADR-NNN`, `Deprecated`. Every ADR in this initial log is `Accepted` — they record decisions already fixed by the specification set. |
| **Context** | The forces in play: the requirement, the constraints, the alternatives, and what made the decision necessary. Written so it still makes sense years later, to someone who was not in the room. |
| **Decision** | The choice, stated in active voice: "QAYD does X." Specific enough that a reviewer can tell whether a given pull request honors or violates it. |
| **Consequences** | What becomes easier and what becomes harder as a result — the positive, the negative, and the neutral-but-notable. An ADR with only upsides in its Consequences section is not finished. |

Three rules keep the log trustworthy:

1. **ADRs are immutable once Accepted.** You do not edit the Decision of an Accepted ADR to change
   your mind. You write a new ADR that supersedes it, and you set the old one's status to
   `Superseded by ADR-NNN`. This mirrors QAYD's own accounting rule (ADR-006): a posted fact is never
   silently rewritten; it is corrected by a new, linked record. The history is the point.
2. **Numbers are never reused.** `ADR-004` means one thing forever, even after it is superseded. A
   superseded ADR stays in the log with its original text and a pointer forward.
3. **One decision per record.** If a paragraph in a Decision section contains the word "and" joining two
   independently-reversible choices, it is probably two ADRs.

## Where ADRs Live and How New Ones Are Added

ADRs live in this document as numbered sections, newest-highest-numbered, and are also mirrored as
individual files under `docs/developer/adr/NNNN-<slug>.md` for the ones added after this initial log,
so a pull request that introduces a new architectural decision carries its ADR in the same diff as the
code that first honors it. A new ADR is proposed by opening a pull request that adds the record with
status `Proposed`; it becomes `Accepted` when that pull request merges after review (see
[CONTRIBUTING.md](./CONTRIBUTING.md) for the review gates and [RELEASE_PROCESS.md](./RELEASE_PROCESS.md)
for how a decision that changes the public contract is versioned and communicated). Superseding an
existing ADR is itself an ADR-bearing pull request: the new record cites the old, and the old record's
status line is updated to point forward — the only permitted edit to an Accepted ADR.

# The Decision Log

The records below capture the twelve foundational decisions the platform is built on. Read them in
order the first time: the later ones assume the earlier ones (RLS multi-tenancy assumes the modular
monolith; immutable posting assumes the shared service layer; the AI approval gate assumes both).

---

## ADR-001: Build a modular monolith in Laravel with a separate AI engine, not microservices

**Status:** Accepted

**Context.** QAYD spans many business domains — Accounting, Sales, Purchasing, Banking, Inventory,
Payroll, Tax, Reports — plus an AI layer, and the long-term vision is fifty-plus independent business
modules (see [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)). The obvious
temptation for a system of that ambition is to start as microservices: one deployable per domain. But
the domains here are not independent in the way microservices assume. A single sale posts to the
General Ledger, decrements inventory, accrues tax, and updates reports **inside one financial
transaction that must be atomic** — a debit without its credit, or a stock decrement without its
journal line, is a corrupted ledger, not an eventually-consistent inconvenience. Distributing that
transaction across services would force sagas and compensating actions onto the exact operation where
correctness is least negotiable. The team is also small relative to the domain surface; paying the
operational tax of many services (network hops, distributed tracing, per-service deploy pipelines,
schema-contract choreography) before there is any scaling pressure that demands it would slow every
feature for a benefit nobody yet needs. Separately, the AI workload is genuinely different in kind —
Python's ML/OCR/LLM ecosystem, GPU-shaped scaling, latency profiles measured in seconds not
milliseconds — and does not belong inside the PHP request cycle.

**Decision.** QAYD is a **modular monolith**: one Laravel 12 (PHP 8.4+) application containing every
business module, each module internally layered (Presentation → Application → Domain → Infrastructure)
and communicating with its peers through **events and service interfaces, never through another
module's internal implementation or its tables directly** (see
[../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) and
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)). The **AI engine is the one
deliberate carve-out**: a separate FastAPI/Python service, because its ecosystem and runtime shape
differ fundamentally (ADR is reinforced by the AI-never-writes rule in ADR-007). Module boundaries are
enforced in code and review, not by process boundaries, so a future extraction to microservices — if
scale ever demands it — is a refactor along seams that already exist, not a rewrite.

**Consequences.**
- Positive: one atomic database transaction covers the whole "invoice posts everything" workflow (ADR-006); one deploy, one test suite, one log stream; refactoring across module lines is a compiler-and-test-assisted change, not a cross-service contract negotiation.
- Positive: the module discipline (events, no cross-module table reads) means the seams for a future split already exist and are tested.
- Negative: the whole backend scales as one unit — a hot Payroll run and a quiet Accounting read share a deployment; horizontal scaling is by replica, not by domain, until a module is deliberately extracted.
- Negative: nothing but code review and a boundary-lint stops a lazy pull request from reaching across a module boundary; the discipline is cultural and must be enforced every time.
- Neutral: two languages and two runtimes to operate (PHP + Python) from day one — accepted as the cost of putting AI where its ecosystem lives.

---

## ADR-002: Isolate tenants in a single shared database using PostgreSQL Row-Level Security

**Status:** Accepted

**Context.** QAYD is multi-tenant: many companies' financial data live in one platform, and a
cross-tenant leak is the single worst failure the system could have — it is a regulatory and
existential event, not a bug ticket. Three isolation models were available: a separate database per
tenant, a separate schema per tenant, or a single shared schema with a `company_id` column on every
tenant table. Database-per-tenant gives the strongest physical isolation but does not scale
operationally to thousands of companies (migrations, connection pools, backups, and monitoring
multiply per tenant) and makes cross-tenant platform analytics and onboarding painful. Application-level
`WHERE company_id = ?` filtering scales beautifully but is only as strong as the discipline of every
query ever written — one forgotten clause in one report is a breach. See
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md).

**Decision.** QAYD uses a **single shared PostgreSQL database**, one schema, with a `company_id` on
every tenant-scoped table, isolated by **defense in depth in three layers**: (1) a global Eloquent
scope that injects the tenant predicate into every ORM query; (2) an explicit `company_id` filter as a
correctness-and-performance measure in repositories; and (3) **PostgreSQL Row-Level Security as the
layer that cannot be forgotten** — every tenant table runs `ENABLE ROW LEVEL SECURITY` **and** `FORCE
ROW LEVEL SECURITY`, with a policy of the form `company_id = current_setting('app.current_company_id',
true)::bigint`. The tenant identity is pushed into the database per request via `SET LOCAL
app.current_company_id = ?` inside the request's transaction, so the database itself refuses to return
or write another tenant's rows even if application layers 1 and 2 are both defeated.

**Consequences.**
- Positive: one migration, one backup, one connection pool, one monitoring surface for all tenants — operations scale sublinearly with tenant count.
- Positive: isolation survives an application bug. A forgotten `WHERE` clause returns zero rows, not another company's ledger, because RLS is the floor beneath the ORM.
- Positive: platform-level maintenance jobs run through an explicit `is_platform_admin = true` session flag and an audited bypass repository, so the one place isolation is intentionally lifted is narrow, named, and logged.
- Negative: every request must set the session variable before touching tenant data, and every tenant migration must remember to enable and force RLS on the new table — both are mandatory, both are checked by the security test band (ADR-012).
- Negative: `SET LOCAL` is transaction-scoped, so tenant-touching requests must run inside an explicit transaction; the framework wraps this, but raw-connection work must respect it.
- Neutral: a noisy tenant shares infrastructure with quiet ones; per-tenant resource governance is an application-and-rate-limit concern, not a physical one.

---

## ADR-003: Make the Next.js web tier an unprivileged client of /api/v1

**Status:** Accepted

**Context.** The Next.js 15 web app is a first-party surface, which invites a tempting shortcut: give
it a privileged internal path to the database or to un-permissioned backend internals, "because it's
ours." That shortcut would create a second source of truth for business rules (some in Laravel, some in
the web tier), a second place tenant isolation could be bypassed, and a surface whose trust level
differs from the mobile app and the AI engine for no principled reason. QAYD's foundational rule is
that the backend is the **single source of truth** for every fact and every rule (see
[../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md) and the frontend contract
in [../frontend/README.md](../frontend/README.md)).

**Decision.** The Next.js web tier is a **pure presentation and interaction layer with no special
privilege**. It is its own Node.js deployable that calls `https://api.qayd.app/api/v1/...` exactly the
way a browser would — no database access, no service credential, no internal trust. Server Components
and Server Actions make that call server-side (forwarding the visitor's own Sanctum session); Client
Components make it from the browser. Every business rule, monetary calculation, validation the backend
also enforces, and permission decision lives authoritatively on the backend; client-side checks exist
**only** to make the UI feel instant and to avoid round-trips for actions the user cannot legally take.
The backend re-checks everything.

**Consequences.**
- Positive: exactly one implementation of every business rule; the web tier can never drift from the mobile app or the AI engine, because all three consume the identical versioned, permissioned, enveloped `/api/v1` contract.
- Positive: a compromise of the web tier grants no more than a compromised browser session would — there is no privileged path to steal.
- Positive: the frontend can be developed and tested against the real API with no mocked-privilege mode, which is why there is no `API_SECRET_KEY` in the web tier's environment at all.
- Negative: the web tier cannot "just query the database" for a one-off screen; every datum needs an endpoint, which occasionally means building an endpoint before a screen.
- Neutral: server-side rendering still forwards the human's own session cookie rather than acting with elevated rights, so SSR is a convenience, not a trust escalation.

---

## ADR-004: Split authentication — Sanctum cookie sessions for web, RS256 JWTs for mobile, engine, and partners

**Status:** Accepted

**Context.** QAYD has structurally different clients with different security needs. The first-party web
app runs in a browser, where the priorities are CSRF protection and the ability to **revoke a session
instantly, server-side**. The Flutter mobile app, the FastAPI AI engine, and third-party integrations
are stateless consumers that hold a credential and call the API without a cookie jar, and need
offline-tolerant, self-contained tokens. A single credential type cannot serve both well: a
self-contained JWT is only as revocable as its denylist (bad for a browser session an admin may need to
kill this instant), while a stateful cookie session is awkward-to-impossible for a headless integration
or a mobile client managing its own refresh cycle. See
[../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md).

**Decision.** QAYD uses **two credential families, chosen by client shape**:
1. **Laravel Sanctum SPA session cookies** (`XSRF-TOKEN` + `qayd_session`) for the first-party Next.js
   web app — stateful, CSRF-protected, and **instantly revocable server-side**.
2. **RS256 (asymmetric) JWT bearer tokens** for the Flutter mobile app, the AI engine's per-company
   service tokens, and third-party integrations — stateless and offline-tolerant. Signing is **RS256,
   never HS256**, so QAYD publishes only the public key to verifiers and no verifier ever holds the
   secret needed to mint tokens. Server-to-server scripts use Sanctum personal access tokens rebranded
   as **API Keys** (`qyd_live_…` / `qyd_test_…`).

Regardless of credential type, the request is resolved to a `company_id` and permission set and checked
identically — a JWT's `company_id` claim, once verified, is the source of truth for RLS (ADR-002); the
credential decides *how you prove who you are*, never *what you are allowed to do*.

**Consequences.**
- Positive: browser sessions are killable on demand (revocation, forced logout, security response) because they are stateful; headless clients get self-contained, offline-friendly tokens.
- Positive: RS256 means a compromised verifier cannot forge tokens — the blast radius of a leaked verification key is zero minting capability.
- Positive: the AI engine authenticates exactly like any other bearer client, which is what makes ADR-007's "same front door" property literally true at the auth layer.
- Negative: two auth code paths to build, test, and reason about, plus JWT signing-key rotation machinery (`jwt_signing_keys`, single active key) the cookie path does not need.
- Neutral: API Keys are Sanctum tokens under the hood, so the token-issuance machinery is shared even though the developer-facing story (cookie vs. bearer vs. key) is three-headed.

---

## ADR-005: Make TanStack Query the single owner of server state in the web client

**Status:** Accepted

**Context.** A data-dense financial UI has many kinds of state, and the classic React failure mode is
smearing server data across `useState`, a global store, and ad-hoc `useEffect` fetches until nobody can
say where the current value of an invoice total actually lives, or why one panel shows a stale figure
after another panel mutated it. QAYD needs caching, background refetch, request deduplication,
optimistic updates for actions like posting a journal entry, and a disciplined invalidation story so a
mutation in one place reliably refreshes every dependent view. See
[../frontend/README.md](../frontend/README.md).

**Decision.** In the web client, **TanStack Query v5 is the exclusive owner of anything that came from
`/api/v1`.** Every read is a `useQuery`/`useSuspenseQuery`; every write is a `useMutation` with explicit
cache invalidation or an optimistic update. Query keys come from **centralized factories** in
`lib/query/keys.ts`, never inlined per component. **Zustand** holds only ephemeral UI state (sidebar
collapsed, active drawer, wizard step); **React Context** holds cross-tree singletons (session, active
company, resolved permissions, locale, theme); **React Hook Form** owns a single form's in-progress
values. None of Zustand, Context, or `useState` ever holds server data. Realtime pushes from Reverb
(ADR-011) are merged into the query cache via `queryClient.setQueryData`, so there is never a parallel
"realtime state" to reconcile against the cache by hand.

**Consequences.**
- Positive: exactly one place to look for any server value, and one invalidation mechanism, so cross-view staleness bugs largely stop existing.
- Positive: caching, dedup, background refetch, and optimistic UI come from one well-understood library rather than a bespoke fetch layer per screen.
- Positive: a Server Component can fetch first paint and hand it to a Client Component as `initialData`, so the first render already has real data with no client-visible spinner.
- Negative: the team must hold the discipline that "server data never goes in Zustand/Context/useState"; it is a convention caught in review, not by a type error.
- Neutral: query-key factories are a small upfront ceremony per resource, paid back the first time an invalidation needs to be exactly right.

---

## ADR-006: Post double-entry facts immutably — correct by reversal, never by edit

**Status:** Accepted

**Context.** The General Ledger is QAYD's source of financial truth, and financial records carry a
legal and audit weight consumer data does not. If a posted journal entry could be edited in place, the
books would have no trustworthy history: an auditor could never be sure a balance reflects what was
recorded at the time, and a bug (or a bad actor) could rewrite the past silently. Every downstream
artifact — Trial Balance, Balance Sheet, P&L, Cash Flow — is a derived view over posted
`journal_lines`, so the integrity of those lines is the integrity of the whole system. See
[../accounting/JOURNAL_ENTRIES.md](../accounting/JOURNAL_ENTRIES.md).

**Decision.** Posting is **immutable and double-entry-enforced**. A journal entry moves `draft →
approved → posted`; an entry can only reach `posted` if its debits equal its credits and it falls in an
open fiscal period, and the balance invariant is enforced in the backend `PostingService` inside one
atomic transaction. **Once posted, an entry's header and lines are frozen — no mutation whatsoever.**
Every correction is a **new, system-generated reversing entry** (`reversed`/`voided` states), never an
edit of the original. The full life story of any figure — who drafted it, what the AI suggested, who
approved it, when it posted, whether and why it was reversed — is permanently reconstructable.

**Consequences.**
- Positive: the ledger is auditable by construction; a balance can always be traced to the immutable lines that produced it, and every correction is itself a permanent, attributable record.
- Positive: reversal-not-edit composes cleanly with immutable audit logging and with ADR-007's proposal history — the same "append, never overwrite" discipline runs end to end.
- Negative: correcting a mistake is heavier than editing a field — it creates additional entries, which users must understand as normal accounting practice rather than friction.
- Negative: reporting views must account for reversed/voided entries (exclude them from active balances by convention while retaining them), adding complexity to every balance query.
- Neutral: this is standard double-entry bookkeeping; the decision is to enforce it in software with no escape hatch, not to invent it.

---

## ADR-007: The AI engine never auto-commits — every AI action is a proposal through the same API, gated by human approval

**Status:** Accepted

**Context.** QAYD is a "system of action": an AI workforce continuously determines what should happen
next and prepares it. The danger is obvious — an AI with a backdoor to the database, or with the
authority to commit financial facts unsupervised, would be an unaudited actor able to corrupt the
ledger at machine speed. The platform's defining bet is that AI can be trusted with *drafting* and
*analysis* precisely because it is never trusted with *unsupervised commitment* of anything sensitive.
See [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) and the frontend contract in
[../frontend/README.md](../frontend/README.md).

**Decision.** **The AI engine never writes to the database directly, and never auto-commits a sensitive
action.** Every AI action — from routine categorization to a multi-million-KWD wire draft — is a
**proposal** submitted through the same Laravel `/api/v1`, the same `FormRequest` validation, and the
same RBAC checks a human action would face. The AI has the *same front door* as every other actor and
can knock only as hard as its permission grant (`ai.approve`, `ai.automation`) allows. Every
AI-originated number, suggestion, or flag rendered anywhere carries its **confidence score and its
reasoning**; every AI action touching money, tax, payroll, or posted data renders an explicit
approve/reject/delegate affordance. The frontend auto-commits a *human's decision* about a proposal —
never a proposal itself. A fixed sensitive-operations list defines what stays irreducibly
human-approved, and that list does not shrink as autonomy expands elsewhere.

**Consequences.**
- Positive: there is no path by which AI corrupts the ledger unsupervised — it is constrained by the identical auth, validation, and permission machinery as every other client, plus an approval gate on top.
- Positive: every AI decision is inspectable (confidence + reasoning), reversible, and recorded, and learning happens per-tenant via retrieved corrections rather than shared model weights, so one company's data never leaks into another's model behavior.
- Positive: the "same front door" property is not a slogan — it is enforced by ADR-004 (the engine authenticates as an ordinary bearer client) and ADR-002 (its `company_id` is RLS-scoped like anyone's).
- Negative: even high-confidence routine proposals incur a human approval step for sensitive classes, which caps how much throughput full autonomy can ever reach — accepted deliberately as the price of trust.
- Neutral: the AI engine needs its own service-token issuance and per-company context, adding auth surface (see ADR-004) that a database-backdoor design would not.

---

## ADR-008: Version the API in the URI path (/api/v1) with a 12-month deprecation policy

**Status:** Accepted

**Context.** Three categories of client depend on the API not breaking under them: first-party web and
mobile, the AI engine, and third-party integrations (accountants' tooling, bank connectors, government
e-invoicing gateways) that may not touch their code for years. Financial software is held to a higher
bar than consumer software — a month-end close script or a tax-filing integration cannot fail because
QAYD renamed a field. Versioning could be by header, by query parameter, or by URI path. See
[../api/API_VERSIONING.md](../api/API_VERSIONING.md).

**Decision.** QAYD versions its REST API at the **URI path level** under `/api/v1/`, with a bare major
integer (`v1`, `v2`) and **no minor/patch in the path** — non-breaking additive changes ship
continuously into the current major without changing the path; only a breaking change bumps the major.
The version maps to a Laravel route group and per-version Controllers, FormRequests, and Resources,
while **Services and Repositories are never versioned** — business logic lives once and is called by
every version's controller, so financial correctness can never fork between `v1` and `v2` clients.
Deprecation is formal and machine-readable: `Deprecation`, `Sunset` (RFC 8594), and `Link` headers on
every response from a deprecated version, a **minimum 12-month** sunset window for any version with a
live third-party integration, and a five-state lifecycle (`beta → current → deprecated → sunset →
retired`).

**Consequences.**
- Positive: the version is visible in every log line, curl, and network tab — zero ambiguity about which contract a request used, which matters enormously when debugging a financial discrepancy.
- Positive: multiple versions run simultaneously over one shared Service layer, so an old integration keeps working for a contractually stated minimum lifetime without duplicating business rules.
- Positive: additive changes (new fields, endpoints, enum members) ship without a version bump because clients are contractually required to ignore unknown fields and treat unknown enums as an `unknown` bucket.
- Negative: breaking changes are genuinely expensive — a new major version, migration guide, cross-version test matrix, and a year of running two versions — which is the point, but it makes some cleanups not worth doing.
- Neutral: database migrations must follow expand → migrate → contract so a column an older version's Resource still reads is never dropped before that version retires (see ADR-002/ADR-006 interplay).

---

## ADR-009: Represent money as NUMERIC(19,4) and render Western-Arabic (latn) numerals in the Arabic locale

**Status:** Accepted

**Context.** QAYD's base currency is the Kuwaiti dinar, which has **three** minor units (fils), and the
platform is multi-currency (AED, SAR, USD). Floating-point money is a classic corruption source —
`0.1 + 0.2` is not `0.3`, and an accounting system that loses a fil to rounding loses trust. Separately,
QAYD is bilingual with full RTL Arabic, which raises a real question no consumer app has to answer: in
an Arabic-locale ledger, should figures render in Eastern Arabic numerals (٠١٢٣) or Western Arabic
(0123)? Mixed numeral systems inside one ledger row read as an *error* to an accountant. See
[../accounting/JOURNAL_ENTRIES.md](../accounting/JOURNAL_ENTRIES.md) and the i18n rules in
[../frontend/README.md](../frontend/README.md).

**Decision.** Monetary amounts are stored as **`NUMERIC(19,4)`** in PostgreSQL and handled through a
`Money` value object in the backend — never as floats — giving exact decimal arithmetic with headroom
below KWD's three-decimal precision. Over the API, amounts crossing the wire in contexts sensitive to
float parsing are sent as **strings**, not JSON numbers. In the frontend, all figures are formatted
through `Intl.NumberFormat` parameterized by locale and the company's base currency, and financial
figures explicitly render **Western Arabic (`latn`) numerals even in the Arabic locale**
(`numberingSystem: "latn"`), configurable per company for the rare tenant that wants Eastern Arabic
numerals throughout.

**Consequences.**
- Positive: no floating-point drift in stored or computed money; the double-entry balance invariant (ADR-006) is checked on exact decimals, not approximations.
- Positive: Arabic-locale ledgers read correctly to accountants because numerals are consistent within a row, while the rest of the UI still mirrors fully RTL.
- Negative: developers must route every amount through the `Money` value object and the `Intl` formatter — a raw float or a hand-rolled currency string in a pull request is a defect the review must catch.
- Neutral: sending amounts as strings on the wire means clients deserialize money deliberately rather than letting a JSON parser coerce it — a small, intentional friction that prevents a large, silent bug.

---

## ADR-010: Own shadcn/Radix component primitives in-repo rather than depend on an opaque UI library

**Status:** Accepted

**Context.** QAYD's design taste is a **stated product requirement**: the editorial calm of Linear,
Stripe, and Tap Payments — near-monochrome ink, one disciplined accent, a grotesque display face, and
purposeful micro-motion — explicitly rejecting the cartoonish, over-rounded, loudly-colorful register
of generic SaaS admin templates. Achieving that against a conventional component library means fighting
its default styling on every component and being unable to restyle deeply without forking. But building
primitives from scratch means re-implementing accessibility (focus trapping, ARIA roles, keyboard
navigation) that is genuinely hard to get right. See [../frontend/README.md](../frontend/README.md) and
[../frontend/COMPONENT_LIBRARY.md](../frontend/COMPONENT_LIBRARY.md).

**Decision.** QAYD uses **shadcn/ui** — Radix UI primitives generated **into the repository** under
`components/ui/` and **owned as source**, not consumed as an opaque npm dependency. Radix provides the
accessibility (preserved, never stripped, when restyling); shadcn's generate-into-repo model means every
primitive is QAYD's own code, freely restyled to the design tokens in
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) without fighting an upstream release.
Components never import from `radix-ui` directly — always through `components/ui/` — and never hard-code
a color, font, or spacing value a token already covers.

**Consequences.**
- Positive: full control over appearance to hit the editorial design bar, with Radix's accessibility as a floor that restyling cannot accidentally remove.
- Positive: no upstream-library version churn dictating QAYD's UI; primitives evolve on QAYD's schedule.
- Negative: QAYD owns the maintenance of its primitives — a Radix upgrade or an accessibility fix is a deliberate in-repo change, not a `npm update`.
- Neutral: the boundary "never import radix directly, always through `components/ui/`" is a convention enforced in review; it is what keeps the restyle layer coherent.

---

## ADR-011: Use Laravel Reverb for realtime, one-way backend → client, over company-scoped private channels

**Status:** Accepted

**Context.** A live financial dashboard, AI proposal notifications, and reconciliation status need to
update without polling. Realtime introduces two risks specific to QAYD: a channel must never leak one
company's events to another (the multi-tenancy guarantee, ADR-002, extended to the WebSocket layer), and
realtime must not become a *second* write path that clients treat as authoritative, competing with the
API source of truth (ADR-003). See [../frontend/README.md](../frontend/README.md) and
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md).

**Decision.** Realtime is **Laravel Reverb** (a Pusher-protocol-compatible WebSocket server) with
Laravel Echo as the browser client. Updates flow **one way only: backend → Reverb → clients** — the
backend remains the sole authoritative writer, and a realtime message is a *notification to refresh
cached truth*, never a write. Channels are **private and company-scoped**
(`private-company.{companyId}.dashboard`, `.notifications`, `.ai`), authorized per subscription, and
subscribed once in the authenticated app shell and torn down on company switch or sign-out. On the
client, realtime payloads are merged into the TanStack Query cache (ADR-005), so there is exactly one
copy of any server value.

**Consequences.**
- Positive: live dashboards and instant AI-proposal notifications without polling, with tenant isolation carried onto the socket layer by per-company private channels.
- Positive: because realtime is one-way and merged into the query cache, it never becomes a competing source of truth or a second write path to reason about.
- Positive: Reverb is first-party Laravel, so it shares the app's auth, config, and deploy rather than adding a third-party realtime vendor.
- Negative: the app must correctly subscribe/unsubscribe across company switches, or a stale subscription could deliver the wrong company's events — a bug class the security band (ADR-012) must test for.
- Neutral: a single Reverb layer serves all live versions of the API (ADR-008), keeping realtime consistent across `v1`/`v2` clients.

---

## ADR-012: Enforce a seven-band test pyramid whose shape is audited, not aspirational

**Status:** Accepted

**Context.** A financial platform with immutable posting (ADR-006), multi-layer tenant isolation
(ADR-002), an AI proposal gateway (ADR-007), and multiple live API versions (ADR-008) has more
invariants that *must* hold than any manual QA process can cover. Test suites also rot toward
top-heaviness: teams reach for a slow end-to-end test because it is easy to write, and the fast layer
that should catch a regression in milliseconds never gets it. See
[../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md).

**Decision.** QAYD enforces a **seven-band test pyramid** — Unit, Integration, Contract, E2E, Load,
Security, and AI-Eval — with the cheap lower bands numerous and run on every commit and the expensive
upper bands few and gated at merge or release:

| Band | Owns | Runs on |
|---|---|---|
| Unit | Pure logic in isolation (services, `PostingService` balance invariant, value objects, policies, Zod schemas, formatters, AI tool functions) | Every commit |
| Integration | A unit wired to real neighbors — `/api/v1` feature tests through auth → tenant → RBAC → validation → service → DB → envelope; RLS negative tests; migration tests | Every commit |
| Contract | The shapes crossing a codebase boundary — Spectator/Schemathesis vs the OpenAPI spec; the shared `ai/proposals` JSON Schema | Every commit (validate), nightly (fuzz) |
| E2E | A whole user journey through the browser — Playwright + four-variant visual regression (light/dark × LTR/RTL) | PR smoke subset, full nightly |
| Load | Latency/throughput under concurrency — k6 | PR smoke (blocking), nightly ramp, pre-release soak |
| Security | Tenant-isolation/RLS negative suite, permission matrix, secret and dependency scanning | Every PR + scheduled |
| AI-Eval | Model answer quality | Scheduled in the FastAPI repo (non-gating in the platform pipeline) |

The shape is **enforced, not aspirational**: a CI audit flags directories that have grown top-heavy,
and no upper band is ever accepted as a substitute for a lower one — an E2E test that happens to hit
`POST /api/v1/accounting/journal-entries` does not excuse that endpoint from a Pest feature test.
Target composition is roughly 60% unit / 26% integration / 6% contract / 4% E2E / 2% load / 2% security.

**Consequences.**
- Positive: the three most load-bearing invariants (posting balance, tenant isolation, AI proposal contract) each have a *named home* in a specific band, so a regression in any of them fails a fast, deterministic test rather than surfacing in production.
- Positive: most confidence comes from fast isolated tests, keeping CI quick while the slow, flaky layers stay deliberately small.
- Positive: the cross-version regression suite (ADR-008) proves the shared-Service architecture holds — the same operation via `v1` and `v2` shapes produces byte-identical database state.
- Negative: every new endpoint and screen owes tests in multiple bands (a feature test, a contract assertion, an E2E happy path, and often a security case), which is real up-front cost on every feature.
- Neutral: AI-Eval is non-gating in the platform pipeline because model quality is measured on its own cadence in the AI repo — a deliberate choice to keep the deploy gate deterministic.

---

# Relationship to Other Developer Documents

- [CONTRIBUTING.md](./CONTRIBUTING.md) — how a change (including a new ADR) is proposed, reviewed, and
  merged; the review gates every decision above is enforced through.
- [CHANGELOG.md](./CHANGELOG.md) — the dated, human-facing record of what shipped; an ADR explains
  *why* a decision holds, the changelog records *when* work honoring it landed.
- [RELEASE_PROCESS.md](./RELEASE_PROCESS.md) — how a decision that alters the public contract (most
  directly ADR-008) is versioned, deprecated, and communicated to clients.

The authoritative mechanics for any decision remain in its linked specification document; this log
records the decision, its rationale, and its cost so that none of them ever has to be reverse-engineered
from the code again.

# End of Document
