# Technical Decisions Log — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: TECH_DECISIONS

---

# Purpose

This document is the consolidated, execution-level technical decisions log for QAYD, the AI Financial
Operating System: a multi-tenant, bilingual (English / Arabic, full RTL) double-entry accounting
platform for Kuwait and the GCC, built as a Laravel 12 modular monolith backend, a separate FastAPI AI
engine, a Next.js 15 web client, and a Flutter mobile app, on PostgreSQL with Row-Level Security, Redis,
and Laravel Reverb.

It serves two audiences at once. For an engineer or AI agent orienting to the platform, Part A is a
**one-screen summary** of the load-bearing decisions already fixed — the stack choices and *why* each
was made — so the reasoning does not have to be reverse-engineered from the code. For the team planning
the build, Part B is an **open-decisions register**: the execution-level choices that are *not yet
settled* — queue infrastructure, the vector database for AI memory, the feature-flagging system, and the
CI/CD provider — each with options, a recommendation, and a status.

The authoritative record of the *architectural* decisions remains
[../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md) (the ADR-001…ADR-012
log). **This document does not restate or duplicate those ADRs; it summarizes each in one line and links
to it, then adds the execution-level decisions the ADR log deliberately does not cover.** Where a summary
here and an ADR ever disagree, the ADR wins, and this document is corrected. The stack itself is fixed by
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) and is not reopened here.

---

# Part A — Settled Decisions (summary of the ADR log)

The twelve architectural decisions below are **Accepted** and owned by
[../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md). This table is a map,
not a substitute — the linked ADR holds the full context, decision, and consequences.

| ADR | Decision (one line) | Why it holds | Owner doc |
|---|---|---|---|
| ADR-001 | A **modular monolith in Laravel 12** with a **separate FastAPI AI engine**, not microservices | A single sale must post to GL, inventory, and tax in one atomic transaction; the AI workload is a different runtime shape | [ADR-001](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-002 | **Single shared PostgreSQL DB** with `company_id` + **Row-Level Security** (`ENABLE`+`FORCE`) | A cross-tenant leak is existential; RLS is the isolation layer that cannot be forgotten | [ADR-002](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-003 | The **Next.js web tier is an unprivileged client** of `/api/v1` | One source of truth for business rules; a compromised web tier is only as strong as a browser session | [ADR-003](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-004 | **Sanctum cookie sessions for web; RS256 JWT** for mobile, AI engine, and partners | Browser sessions must be instantly revocable; headless clients need self-contained, offline-tolerant tokens | [ADR-004](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-005 | **TanStack Query v5** is the single owner of server state in the web client | Ends the smear of server data across `useState`/store/effects; one cache, one invalidation story | [ADR-005](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-006 | **Immutable double-entry posting** — correct by reversal, never by edit | The ledger is auditable by construction; a posted fact is never silently rewritten | [ADR-006](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-007 | The **AI engine never auto-commits** — every AI action is a proposal through the same API, human-gated | AI is trusted with drafting precisely because it is never trusted with unsupervised commitment | [ADR-007](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-008 | **URI-path API versioning** (`/api/v1`) with a 12-month deprecation policy | Financial integrations cannot break silently; the version is visible in every log line | [ADR-008](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-009 | Money as **`NUMERIC(19,4)`**; Western-Arabic (`latn`) numerals in the Arabic locale | No float drift below KWD's three fils; consistent numerals read correctly to accountants | [ADR-009](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-010 | **Own shadcn/Radix primitives in-repo** rather than depend on an opaque UI library | Hit the editorial design bar while keeping Radix accessibility as a floor | [ADR-010](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-011 | **Laravel Reverb** realtime, one-way backend→client, over company-scoped private channels | Live updates without a second write path; tenant isolation carried onto the socket layer | [ADR-011](../developer/ARCHITECTURE_DECISIONS.md) |
| ADR-012 | A **seven-band test pyramid** whose shape is audited, not aspirational | Posting balance, tenant isolation, and the AI proposal contract each get a named test home | [ADR-012](../developer/ARCHITECTURE_DECISIONS.md) |

These twelve interlock: RLS multi-tenancy (002) assumes the modular monolith (001); immutable posting
(006) assumes the shared service layer (001); the AI proposal gate (007) assumes both the auth split
(004) and RLS scoping (002). The dependency reading order is the ADR order.

## Stack choices at a glance

The stack is fixed by [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); the *reasons* most
relevant at execution time are summarized here so a builder does not have to re-derive them.

| Layer | Choice | One-line rationale |
|---|---|---|
| Backend | Laravel 12 / PHP 8.4+ | Batteries-included enterprise framework: auth, queues, events, broadcasting, scheduling, testing (ADR-001) |
| AI engine | FastAPI / Python | Python owns the ML/OCR/LLM ecosystem; runs out-of-process, never in the PHP request cycle (ADR-001) |
| Web | Next.js 15 / React 19 / TypeScript strict | SSR + App Router; unprivileged `/api/v1` client (ADR-003) |
| Web state | TanStack Query v5 (server) + Zustand (UI) + Context (singletons) + React Hook Form | Clear ownership boundaries per state kind (ADR-005) |
| UI | shadcn/Radix in-repo + Tailwind + Framer Motion + Lucide | Editorial design control with accessibility floor (ADR-010) |
| Database | PostgreSQL 15+ | ACID, JSONB, full-text search, RLS as the tenant boundary (ADR-002) |
| Cache/queue | Redis | Sessions, cache, queue, rate limiting, locks, AI memory cache |
| Realtime | Laravel Reverb (Pusher protocol) + Laravel Echo | First-party, one-way, company-scoped channels (ADR-011) |
| Storage | Supabase Storage (S3-compatible fallback) | Invoices, PDFs, exports, AI attachments |
| Auth | Sanctum (web) + RS256 JWT (headless) | Revocable browser sessions; self-contained service/mobile tokens (ADR-004) |
| Mobile | Flutter | One codebase for iOS/Android/tablet against the same `/api/v1` |
| Money | `NUMERIC(19,4)` + `Money` value object; strings on the wire | Exact decimal arithmetic below KWD's three fils (ADR-009) |
| API | REST/JSON, `/api/v1`, standard envelope | Path-versioned, 12-month deprecation (ADR-008) |

---

# Part B — Open Build-Time Decisions

The decisions below are **not settled by the ADR log** and must be made during execution. Each is
recorded in a lightweight format — context, options with trade-offs, recommendation, and status — so that
when it is decided it can be promoted to an Accepted ADR in
[../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md) rather than lingering as
tribal knowledge. None of them reopens a fixed stack choice; each fills a gap the stack docs leave open.

## OD-1: Queue worker infrastructure — Horizon vs. raw Redis workers vs. SQS

**Status:** Recommended, not yet Accepted. Target: decide in Phase 0, before the first queued listener.

**Context.** The platform is async-by-default: OCR, report generation, bulk import, AI analysis, webhook
delivery, notifications, and — most importantly — the queued cross-module posting listeners all run off
the request thread on named Redis queues (`realtime`, `default`, `ai`, `reports`, `integrations`,
`maintenance`), per [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md). Redis is
already fixed as the queue backend by [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); what is
open is the *worker management and observability layer* over it. Money-affecting jobs are idempotent and
must never double-post (see [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md)), so
per-queue visibility into backlog, retries, and failures is a correctness concern, not a nicety.

| Option | Pros | Cons |
|---|---|---|
| **Laravel Horizon** (on Redis) | First-party; per-queue metrics, throughput, retry/fail dashboards; tag-based monitoring; balances workers across queues automatically | Redis-only (no managed-broker durability); an extra process to run and secure |
| Raw `queue:work` workers | Minimal moving parts; full control via supervisor | No built-in dashboard; per-queue observability must be hand-built into Prometheus/Grafana |
| AWS SQS | Managed durability; scales without Redis memory pressure | Diverges from the fixed Redis-queue decision; no Laravel-native dashboard; FIFO/idempotency semantics differ |

**Recommendation.** **Laravel Horizon over Redis.** It is first-party (consistent with the "prefer
first-party Laravel" thread behind Reverb in ADR-011), gives per-queue backlog and failure dashboards
that the idempotency/observability model already wants, and keeps the fixed Redis-queue choice intact.
Prometheus scraping of Horizon metrics feeds the existing Grafana dashboards. Revisit only if a specific
queue's durability needs exceed Redis's memory-backed guarantees.

## OD-2: Vector database for AI memory and retrieval — pgvector vs. a dedicated vector store

**Status:** Recommended (staged), not yet Accepted. Target: decide before Phase 1 AI agents; revisit at
Phase 4 scale.

**Context.** The AI engine needs per-company memory and retrieval: company policies, frequently used
accounts, approval chains, and — central to ADR-007's learning model — *approved corrections retrieved
per tenant*, so learning happens without shared model weights and one company's data never shapes
another's behavior ([../foundation/AI_ARCHITECTURE.md](../foundation/AI_ARCHITECTURE.md),
[../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), and the memory specs under `../ai/memory/`). Retrieval
must be tenant-isolated to the same standard as everything else (ADR-002). TECH_STACK lists "Vector
Database" and "AI Memory Engine" as *future* technologies, so the choice is genuinely open, and the
tension is between reusing the fixed PostgreSQL for retrieval versus adding a dedicated store.

| Option | Pros | Cons |
|---|---|---|
| **pgvector in the existing PostgreSQL** | Reuses the fixed database; embeddings inherit RLS tenant isolation for free; one backup/ops surface; transactional consistency with the rows they describe | Vector-index performance ceilings at very high dimensionality/volume; competes with OLTP load on the primary |
| Dedicated store (Qdrant / Weaviate / Milvus) | Purpose-built ANN performance; scales independently of the OLTP database | New datastore to secure, back up, and *re-implement tenant isolation in* (RLS does not extend to it); another operational surface |
| Managed vector service (e.g. Pinecone) | No ops burden; elastic scale | Data residency risk for GCC/Kuwait tenants (see [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md)); vendor lock-in against the "minimize lock-in" stack principle |

**Recommendation.** **Start on pgvector**, because it inherits RLS isolation (the hardest part to get
right for AI memory) and adds no new residency or ops surface, then **re-evaluate a dedicated store at
Phase 4 scale** if measured recall/latency on real tenant volumes demands it. If a dedicated store is
ever adopted, tenant isolation must be re-implemented in it explicitly (namespacing per `company_id`),
because RLS will not be there to catch a mistake — a fact that itself argues for staying on pgvector as
long as it performs.

## OD-3: Feature-flagging — Laravel Pennant vs. a third-party platform

**Status:** Recommended, not yet Accepted. Target: decide in Phase 1, before the first design-partner
rollout.

**Context.** The phased roadmap ([FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md)) ships modules incrementally
and needs to gate incomplete or design-partner-only features, run per-tenant rollouts, and — importantly
for ADR-007 — gate *AI autonomy scopes* per company (which classes of proposal may auto-commit). Flags
are therefore both a delivery tool and a safety control. Any flag store must be tenant-aware
(`company_id`-scoped) and must not become a second, un-audited authorization mechanism competing with
RBAC ([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)) — flags decide *what is
available*, RBAC decides *who may use it*.

| Option | Pros | Cons |
|---|---|---|
| **Laravel Pennant** | First-party; database/Redis-backed; scopes flags to any model (`company`, `user`); no external dependency or residency concern | Fewer targeting/experimentation features than a dedicated platform; dashboards are self-built |
| LaunchDarkly / Flagsmith (SaaS) | Rich targeting, gradual rollout, audit UI, experimentation | External dependency + data residency exposure; cost; another config surface outside the audited platform |
| Home-grown config table | Full control; trivially tenant-scoped | Reinvents evaluation, caching, and tooling; easy to accidentally build a shadow authorization system |

**Recommendation.** **Laravel Pennant**, scoped to the `company` (and where needed `user`) model, with
flag changes written to the audit trail. It is first-party, tenant-scopes cleanly, and keeps AI-autonomy
gating inside the same platform that audits everything else. Keep flags strictly to *availability*; never
let a flag substitute for an RBAC permission check.

## OD-4: CI/CD provider and pipeline shape

**Status:** Largely settled by the stack; execution details open. Target: finalize in Phase 0.

**Context.** [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) fixes **GitHub + GitHub Actions**
for CI/CD, and [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md) and
[../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md) fix the gates: PHPUnit/Pest, PHPStan, and
Laravel Pint on every backend PR; the seven-band pyramid of ADR-012; and migrations run as a gated
release step against the primary before new code takes traffic. What remains open is the *execution
shape* across three independently deployable codebases (Laravel backend, Next.js web, FastAPI engine),
each with its own runner and idioms per [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md).

| Open sub-decision | Options | Recommendation |
|---|---|---|
| Runner infrastructure | GitHub-hosted vs. self-hosted runners | GitHub-hosted to start; self-hosted only if build minutes or secrets isolation demand it |
| Monorepo vs. multi-repo pipelines | One pipeline with path filters vs. three independent pipelines | Independent pipelines per codebase (backend / web / AI engine) with a shared contract-test stage, matching the three-codebase test strategy |
| Deployment trigger | Auto-deploy on merge vs. gated release with manual promotion | Gated release: migrations run and pass before new code takes traffic; promotion is deliberate for a system of record |
| Contract enforcement | Where the OpenAPI/`ai/proposals` contract tests run | In every pipeline's contract stage, so the four SDKs and the AI proposal schema never drift (ADR-008/ADR-012) |
| Static/style gates | PHPStan + Pint (backend); ESLint + Prettier + `tsc` strict (web); ruff/mypy (engine) | All blocking on PR, per TECH_STACK coding standards |

**Recommendation.** Adopt GitHub Actions with **three independent pipelines** (backend, web, AI engine)
sharing a **contract-test stage**, GitHub-hosted runners initially, and a **gated release** that runs
migrations before cutover. This honors the fixed provider while matching the three-codebase reality and
the ADR-012 test bands.

## OD-5 (watch-list): decisions deferred but named

These are not yet ripe, but are recorded so they are not forgotten and are picked up in the phase that
forces them.

| Decision | Forced by | Phase |
|---|---|---|
| Search tier upgrade PostgreSQL → Meilisearch → Elasticsearch | Search volume/relevance needs (TECH_STACK phased search) | Phase 4 (Meilisearch), later Elasticsearch |
| OCR provider mix Google Document AI vs. Azure Document Intelligence (Tesseract fallback) | First document-intake feature | Phase 2 |
| Primary LLM provider posture OpenAI vs. Anthropic vs. local, per-company choice | AI agent rollout; residency and cost | Phase 1, revisit Phase 4 |
| Multi-region posture active-passive vs. active-active | Residency + DR requirements ([../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md)) | Phase 4 |
| Web push / mobile push transport for notifications | Mobile app + cross-device notifications | Phase 4 |

# How a Decision Graduates

An open decision in Part B is **provisional** until it is made and used in code. When it is decided, it is
promoted to an Accepted ADR in [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md)
following that log's rules — immutable once Accepted, superseded by a new number rather than edited — and
its row here is updated to point at the new ADR. This keeps a single canonical home for *architectural*
decisions while letting this execution log carry the *in-flight* ones, exactly the division of labor the
ADR log's "Relationship to Other Developer Documents" section anticipates.

# Related Documents

- [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md) — the authoritative ADR-001…ADR-012 log Part A summarizes.
- [FEATURE_ROADMAP.md](./FEATURE_ROADMAP.md) — the phased plan these decisions enable.
- [MODULE_DEPENDENCIES.md](./MODULE_DEPENDENCIES.md) — the build-order map the stack choices assume.
- [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) — the fixed technology stack.
- [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md), [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md), [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md), [../deployment/DEPLOYMENT.md](../deployment/DEPLOYMENT.md), [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md)
- [../foundation/AI_ARCHITECTURE.md](../foundation/AI_ARCHITECTURE.md), [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)

# End of Document
