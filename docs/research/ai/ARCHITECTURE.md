# 05 — The Proposed QAYD AI Engine Architecture

**In depth · `docs/research/ai/`**

Version 1.0 · 2026-07-28

This document proposes the internal architecture of `apps/ai` and its contract with the Laravel domain
layer. It assumes `P15`, `P-12`, `P-13` and `R-31…R-34` and does not re-argue them. It assumes the
practices in `BEST_PRACTICES.md` and cites them by id.

**Status: proposal.** Nothing here is built. `apps/ai` today is a health route with two dependencies
`[CODE]` (`apps/ai/pyproject.toml`, `apps/ai/src/qayd_ai/main.py`). Anything adopted must pass through
`08_MASTER_BACKLOG.md`'s intake rule first.

---

## Contents

1. [The design problem, and the answer in one sentence](#1)
2. [Trust zones](#2)
3. [The layered view — what runs where](#3)
4. [One runtime, N task configurations](#4)
5. [Inside the engine — the pipeline](#5)
6. [Retrieval and memory](#6)
7. [The injection defence stack](#7)
8. [Sequence flows](#8)
9. [The Laravel ↔ FastAPI contract](#9)
10. [The evaluation harness](#10)
11. [Cost controls in the architecture](#11)
12. [Deployment, observability, failure modes](#12)
13. [What is deliberately not built](#13)
14. [Open questions this architecture does not settle](#14)

---

<a id="1"></a>
## 1 · The design problem, and the answer in one sentence

The requirement is a contradiction in its usual framing:

- The AI must be **useful enough to be the product**. QAYD is sold as an AI Financial Operating System.
  A system whose AI only offers suggestions nobody uses is a conventional accounting package with a
  marketing problem.
- The AI must be **structurally incapable of corrupting the books**. Not unlikely to. Incapable — as a
  property of the schema and the privilege model, holding against a bug, a bad model version, a hostile
  supplier document, and a fully compromised AI service (`P15`, `P-12` layer 1).

The contradiction dissolves once you notice that the two requirements constrain **different things**.
Usefulness is a property of *what the model produces*. Safety is a property of *what the system does with
it*. They are only in tension if the model's output is also the system's action — which is precisely the
coupling `P15` refuses.

**The answer:**

> **The AI engine is a pure function from a code-assembled input to a typed proposal. Code chooses what
> the model sees; code chooses what happens next; the database refuses anything else. Exactly one
> bounded, read-only agentic loop exists — the Copilot — and it lives outside the posting path.**

Everything in this document is an elaboration of that sentence.

### 1.1 Why this is not a limitation

The instinct is that a non-agentic engine must be less capable. It is worth stating why that is false
here specifically.

The capability the agentic framing buys is **the ability to handle tasks nobody enumerated**. That is
enormously valuable for open-ended research and for coding, where the task space genuinely is unbounded.
Bookkeeping's task space is not unbounded — it is a finite list that has been stable for five hundred
years: classify a document, propose an entry, match a payment, explain a balance, flag an anomaly, answer
a question about the books. QAYD's own product spec enumerates them across thirteen agent documents and
four workflow documents.

**When the task list is enumerable, agency buys nothing and costs 4–15× in tokens**
(`OVERVIEW.md` §2.2), plus reproducibility, plus debuggability, plus the entire injection threat model.

---

<a id="2"></a>
## 2 · Trust zones

Everything below follows from where the boundaries between these sit.

```
 ┌───────────────────────────────────────────────────────────────────────────────────┐
 │ ZONE 0 — TRUSTED                                                                  │
 │ Laravel domain layer · PostgreSQL · Actions · RLS · PostingService · audit_logs   │
 │                                                                                   │
 │ Authority: writes financial data. Holds the tenant credential.                    │
 │ Everything here is reviewed code operating on validated input.                    │
 └───────────────────────────────────┬───────────────────────────────────────────────┘
                                     │  typed DTO over mTLS + internal bearer
                                     │  (S3-07)
 ┌───────────────────────────────────▼───────────────────────────────────────────────┐
 │ ZONE 1 — SEMI-TRUSTED  (the AI engine, apps/ai)                                    │
 │ Deterministic Python. Owns prompt assembly, model calls, parsing, validation.     │
 │                                                                                   │
 │ Authority: NONE over data. No DB driver. No egress except the model provider.     │
 │ Compromise here yields: the ability to return a bad proposal. Nothing else.       │
 └───────────────────────────────────┬───────────────────────────────────────────────┘
                                     │  prompt (assembled by Zone 1 code)
 ┌───────────────────────────────────▼───────────────────────────────────────────────┐
 │ ZONE 2 — UNTRUSTED  (the model's token stream)                                    │
 │ Everything the model emits. Treated exactly as user input from an anonymous       │
 │ source: parsed, schema-validated, value-constrained, never executed, never used   │
 │ to select code, a tool, a query or a tenant.                                      │
 └───────────────────────────────────▲───────────────────────────────────────────────┘
                                     │  quoted as data inside a constant wrapper
 ┌───────────────────────────────────┴───────────────────────────────────────────────┐
 │ ZONE 3 — HOSTILE  (ingested content)                                              │
 │ Supplier invoice PDFs and images · bank statement files · vendor emails ·         │
 │ filenames · OCR output · any customer-uploaded attachment.                        │
 │                                                                                   │
 │ Authored by parties with no QAYD account and no relationship to the tenant        │
 │ beyond commerce. Assume every byte is attacker-chosen.                            │
 └───────────────────────────────────────────────────────────────────────────────────┘
```

**The rules that define the zones, stated as invariants:**

| # | Invariant | Enforced by |
|---|---|---|
| Z1 | Zone 1 holds no credential that can write Zone 0 data | Absence of a DB driver + Laravel authz on the callback |
| Z2 | Zone 1 has no network route except the model provider | Network policy / egress proxy, asserted by test |
| Z3 | No Zone 2 or Zone 3 value determines control flow, tool selection, query parameters, or tenant scope | Code review + architecture (§5); the pipeline has no branch on model output except a validated enum |
| Z4 | Zone 3 content enters exactly one model call, quarantined, and leaves as a typed record | The extraction stage (§5.3), `B-15` |
| Z5 | Zone 2 output lands only in a schema whose value space is constrained to sets the model did not author | Pydantic + per-tenant enums, `B-06` |
| Z6 | Nothing from Zone 1 or 2 is rendered as a link, fetched, or executed | Frontend citation policy, `B-14` |

`[INFERENCE]` Z3 is the one that does the real work and it is the one most likely to be eroded by a
well-intentioned change. It is the property Google DeepMind's CaMeL manufactures at a measured cost of
7 percentage points of task success (`OVERVIEW.md` §6.4); QAYD has it for free and should treat losing it
as a security regression, not a refactor.

---

<a id="3"></a>
## 3 · The layered view — what runs where

```
╔══════════════════════════════════════════════════════════════════════════════════════╗
║  BROWSER  (Next.js 15)                                                               ║
║   Decision queue · proposal review · Copilot chat · Command Center                    ║
║   ▸ never calls FastAPI directly (S4-10)                                              ║
║   ▸ renders NO model-authored URL; citations are ids resolved to signed URLs (B-14)   ║
╚══════════════════════════════════════╤═══════════════════════════════════════════════╝
                                       │ HTTPS · session · CSRF
╔══════════════════════════════════════▼═══════════════════════════════════════════════╗
║  ZONE 0 — LARAVEL 12 / PHP 8.4                                                       ║
║                                                                                       ║
║  Controller → FormRequest → Action  (the only write path, P-01/P23)                   ║
║  ┌─────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ AiProposalService      validates an inbound proposal as UNTRUSTED input          │ ║
║  │ AutonomyResolver       side-effect-free: auto | suggest_only | requires_approval │ ║
║  │ AiCostGovernor         per-company token + spend budget, Redis rate limit        │ ║
║  │ AiContextAssembler     ← builds the dossier the engine will be given (§6)        │ ║
║  │ AiEngineClient         mTLS + internal bearer → FastAPI                          │ ║
║  │ Accept/Reject Actions  promote a proposal through the NORMAL path (P-12)         │ ║
║  └─────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                       ║
║  PostgreSQL 17 · RLS FORCE · append-only ledger_entries · trg_no_ai_autopost           ║
║  Redis (queue, cache, rate limit) · Reverb (realtime)                                 ║
╚══════════════════════════════════════╤═══════════════════════════════════════════════╝
                       ▲               │ POST /internal/invoke   { task, subject, dossier }
       proposal DTO    │               │ mTLS verify-full + hmac.compare_digest bearer
       (typed, no      │               ▼
        free text)     │  ╔════════════════════════════════════════════════════════════╗
                       │  ║  ZONE 1 — FASTAPI AI ENGINE  (apps/ai)                      ║
                       └──╢                                                             ║
                          ║   TaskRouter ── closed enum, no default branch (B-02)        ║
                          ║        │                                                     ║
                          ║        ├─ ContextBuilder    budgeted, deterministic (B-03)   ║
                          ║        ├─ PromptRenderer    versioned template (B-07)        ║
                          ║        ├─ ModelGateway      tier + cache + batch + retry     ║
                          ║        ├─ OutputParser      Pydantic, value-constrained (B-06)║
                          ║        ├─ Validator         task invariants, pre-flight       ║
                          ║        └─ ProposalEmitter   typed DTO + cost record (B-16)    ║
                          ║                                                              ║
                          ║   NO psycopg · NO sqlalchemy · NO general HTTP client        ║
                          ║   egress allowlist: model provider only  (Z2)                ║
                          ╚═════════════════════════╤════════════════════════════════════╝
                                                    │ HTTPS, allowlisted host
                                          ╔═════════▼════════════╗
                                          ║  MODEL PROVIDER      ║
                                          ║  Haiku / Sonnet / Opus║
                                          ╚══════════════════════╝
```

### 3.1 The allocation, stated as a rule

| Concern | Zone 0 (Laravel/PG) | Zone 1 (FastAPI) |
|---|---|---|
| Any write to financial data | ✅ exclusively | ⛔ no credential |
| Tenant resolution and RLS | ✅ | ⛔ receives a resolved dossier |
| **Retrieval queries** | ✅ **authored here** | ⛔ consumes results |
| Deterministic rules (reconciliation matching, validation, balance) | ✅ | ⛔ sees only the residual |
| Autonomy decision | ✅ `AutonomyResolver` | ⛔ — a permission decision, not an AI one |
| Cost budget enforcement | ✅ `AiCostGovernor` | ⚠️ per-request ceiling only |
| Prompt assembly and rendering | ⛔ | ✅ |
| Model invocation, tiering, caching, batching | ⛔ | ✅ |
| OCR / document parsing | ⛔ | ✅ |
| Embedding generation | ⛔ | ✅ (returns vectors to Zone 0 to store) |
| Output parsing and schema validation | ⛔ | ✅ |
| Eval harness execution | ⛔ | ✅ (against fixtures) |

`[INFERENCE]` The row that will be argued about is **retrieval queries authored in Zone 0**. It costs one
internal hop. It buys Z3 — the property that no untrusted value ever reaches a query. §3.2 argues it
properly, because it resolves a real contradiction in the existing knowledge base.

### 3.2 Resolving the P15 / P-12 contradiction: no database driver

The prior work contains two incompatible positions, both defensible, and a decision is needed before
S3-07 writes a line of transport code.

**Position A — `P-12`.** A dedicated `qayd_ai` PostgreSQL role: `SELECT` on read models and reference
data, `INSERT` on `*_proposals` only, no privileges on any financial table. `P-12` calls the GRANT matrix
"THIS is the guarantee" and specifies a catalog-driven test that connects as `qayd_ai` and asserts every
financial write fails.

**Position B — `P15`.** *"No database driver in the AI service — today `apps/ai` depends on nothing but
`fastapi` and `uvicorn`; there is no `psycopg`, no `sqlalchemy`, no connection string. This is real
enforcement and should be treated as deliberate rather than incidental: a CI check asserting the AI
service declares no database driver is one line of `grep`."* (Gap G-8, effort 1.)

They cannot both be primary. If the engine holds a connection, G-8's check must be deleted.

**Recommendation: Position B. The engine holds no driver.** Retrieval is a **Laravel-mediated read API**
(`POST /internal/context` returning the assembled dossier), and proposals return over the same transport
S3-07 already builds.

Reasons, in order of weight:

1. **G-8 is the cheapest auditable enforcement in the entire system.** One grep, effort 1, no runtime
   cost, no configuration, impossible to misconfigure, and readable by a non-expert reviewer. `P-12`'s
   GRANT matrix is stronger *in principle* and considerably more fragile *in practice* — it depends on a
   role's privileges being correct across every future migration, which is precisely why `P-12` has to
   specify a catalog-driven test to defend it. A capability that does not exist needs no test to prove
   it is scoped correctly.
2. **It preserves Z3 by construction.** If retrieval is a Laravel endpoint, the *query* is authored in
   Zone 0 from trusted parameters. If the engine queries directly, the query is authored in Zone 1 — one
   refactor away from a parameter derived from model output. This is the exact property CaMeL exists to
   manufacture (`OVERVIEW.md` §6.4), and it is worth more than a network hop.
3. **It makes retrieval observable.** Every context assembly becomes a request that can be logged, rate
   limited, budgeted, audited and replayed. Ad-hoc SQL from a Python service is none of those.
4. **The latency argument does not survive contact with the numbers.** An internal request over mTLS is
   single-digit milliseconds. The model call it precedes is hundreds to thousands. The hop is noise.
5. **It aligns with what S3-07 already specifies** — "the engine holds no tenant DB connection string
   (verified)" is already an acceptance criterion `[CODE]` (`docs/execution/SPRINT_03.md:146`), and the
   S3 risk register already names "the AI engine gains a path to write tenant data directly (DB
   credential…)" as a Critical-impact risk. Position B keeps that criterion true permanently rather than
   until Sprint 4.

**What is given up.** Two things, both real:

- **Embedding writes need a path.** The engine generates vectors; something must store them. Resolution:
  the engine *returns* vectors in its response and Laravel writes them through a normal Action. This is
  slightly awkward and it is correct — it keeps every write on one path, which is `P-01`.
- **`P-12`'s GRANT matrix loses its primary role.** It should be **retained as the fallback design**, to
  be adopted only if measurement proves the mediated read path is untenable, and adopted in full
  (SELECT-on-views + INSERT-on-proposals + the catalog test) if it ever is. It should not be quietly
  half-adopted.

**Recorded as `AIR-02`, and it needs an ADR** — it refines a pattern in a frozen architecture document,
which MANIFEST Law 1 governs.

---

<a id="4"></a>
## 4 · One runtime, N task configurations

`docs/ai/agents/*` specifies thirteen agents. This architecture implements them as **thirteen
configurations of one runtime**, for the reasons in `ANTI_PATTERNS.md` **A-07**.

```
                         ┌──────────────────────────────────────┐
                         │        ONE RUNTIME (apps/ai)          │
                         │  one pipeline · one loop · one deploy │
                         └───────────────┬──────────────────────┘
                                         │ parameterised by
        ┌────────────────────────────────┼────────────────────────────────┐
        ▼                                ▼                                ▼
┌───────────────────┐         ┌───────────────────┐          ┌───────────────────┐
│ CAPABILITY        │         │ CAPABILITY        │          │ CAPABILITY        │
│ accountant.draft  │         │ banking.match     │          │ copilot.answer    │
├───────────────────┤         ├───────────────────┤          ├───────────────────┤
│ dossier_spec      │         │ dossier_spec      │          │ dossier_spec      │
│ prompt_version    │         │ prompt_version    │          │ prompt_version    │
│ output_schema     │         │ output_schema     │          │ output_schema     │
│ model_tier        │         │ model_tier        │          │ model_tier        │
│ context_budget    │         │ context_budget    │          │ context_budget    │
│ proposal_type     │         │ proposal_type     │          │ tool_surface (RO) │
│ autonomy_class    │         │ autonomy_class    │          │ step_budget       │
│ eval_suite        │         │ eval_suite        │          │ eval_suite        │
└───────────────────┘         └───────────────────┘          └───────────────────┘
        │                                │                                │
        └────────────────────────────────┴────────────────────────────────┘
                                         │
                    Zone 0 owns:  which capability a caller may invoke,
                                  under which permission, at which autonomy.
```

**The `Capability` is the unit of everything.** It is what the `AutonomyResolver` resolves against, what
the cost governor budgets, what the eval suite measures, what the calibration curve is computed for, and
what a customer sees named in the UI as "the Accountant Agent".

Thirteen personas therefore cost thirteen config objects, thirteen prompt files and thirteen eval suites
— and **one** deployment, one tenant-context path, one retry policy, one cost ledger, one observability
surface.

### 4.1 Why the persona vocabulary can stay in the product

Nothing here requires the product to stop saying "the Treasury Agent". The mapping is one-to-one and the
user-facing behaviour is identical. What changes is that a question like *"should the CFO Agent approve
the Accountant Agent's proposal?"* becomes structurally unaskable, because there is no CFO Agent to hold
an approval credential — there is a capability, and `P-12` already forbids "letting one AI approve
another's proposal."

---

<a id="5"></a>
## 5 · Inside the engine — the pipeline

Every task is the same seven stages. There is no other code path.

```
   POST /internal/invoke
   { task: <enum>, subject_ref, dossier, request_id, budget }
            │
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 1 · ADMIT            validate against the closed task enum                   │
   │                      reject unknown task → 400, no default branch (B-02)     │
   │                      apply per-request token ceiling from `budget`           │
   └────────┬────────────────────────────────────────────────────────────────────┘
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 2 · BUILD CONTEXT    assemble to the capability's ContextBudget (B-03)       │
   │                      fixed section order · deterministic serialisation       │
   │                      priority truncation · records truncated_sections        │
   │                      ── cache breakpoints planted here ──                    │
   └────────┬────────────────────────────────────────────────────────────────────┘
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 3 · RENDER           versioned template + interpolated DATA only (B-07)      │
   │                      untrusted text goes in its own wrapped block (B-15)     │
   │                      NEVER into the instruction section (R-33)               │
   └────────┬────────────────────────────────────────────────────────────────────┘
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 4 · CALL             ModelGateway: tier, cache_control, batch-or-live,       │
   │                      timeout, bounded retry, idempotency key                 │
   └────────┬────────────────────────────────────────────────────────────────────┘
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 5 · PARSE            Pydantic model with reasoning-first field order (B-05)  │
   │                      value space constrained to caller-supplied sets (B-06)  │
   │                      parse failure → ONE repair attempt → give up (B-08)     │
   └────────┬────────────────────────────────────────────────────────────────────┘
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 6 · VALIDATE         task invariants BEFORE the proposal leaves Zone 1:      │
   │                      lines balance · accounts came from the supplied enum ·  │
   │                      amounts within document tolerance · no over-consumption │
   │                      violation → structured violations[] (S2+A shape)        │
   └────────┬────────────────────────────────────────────────────────────────────┘
   ┌────────▼────────────────────────────────────────────────────────────────────┐
   │ 7 · EMIT             typed ProposalDTO + structured rationale (A-10)         │
   │                      + model_id, model_version, prompt_version, confidence   │
   │                      + CostRecord (B-16)  + outcome enum (A-15)              │
   └────────┬────────────────────────────────────────────────────────────────────┘
            ▼  200 { outcome, proposal?, violations?, rationale, cost, versions }
```

### 5.1 Stage 1 — ADMIT, and why there is no default branch

The task field is an enum. An unrecognised value is a 400, not a fallback to a general handler. This is
the entire content of `B-02` expressed as five lines of code, and it is the difference between a system
with a knowable input distribution and one without.

The `budget` field is supplied by Zone 0's `AiCostGovernor`, not chosen by the engine. Zone 1 enforces a
ceiling it was given; it does not decide what a tenant may spend.

### 5.2 Stage 2 — the context dossier

The dossier arrives from Zone 0 already tenant-resolved. Zone 1 *shapes* it; it does not fetch it.

Section order is fixed because the order is the cache key:

```
   ┌─────────────────────────────────────────────┬──────────┬───────────────────┐
   │ SECTION                                     │ BUDGET   │ CACHE             │
   ├─────────────────────────────────────────────┼──────────┼───────────────────┤
   │ 1. tool definitions (Copilot only)          │  ~400    │ breakpoint 1      │
   │ 2. instruction block (task-specific)        │  ~900    │ ┐                 │
   │ 3. few-shot examples (task-specific)        │ ~1,500   │ ├ breakpoint 2    │
   │ 4. accounting policy / materiality (tenant) │  ~400    │ ┘                 │
   │ 5. chart of accounts, ORDER BY code         │ ~2,400   │ breakpoint 3      │
   │ 6. active judgements (I-05), ORDER BY id    │  ~800    │ ┐ breakpoint 4    │
   │ 7. precedent rows for this subject          │  ~600    │ ┘                 │
   ├─────────────────────────────────────────────┼──────────┼───────────────────┤
   │ 8. THE SUBJECT — document text / candidates │ variable │ never cached      │
   └─────────────────────────────────────────────┴──────────┴───────────────────┘
       sections 1–7 ≈ 7,000 tokens: comfortably above the 4,096 minimum that
       Haiku 4.5 requires, which is the tier that runs highest volume (OVERVIEW §8.2)
```

Four rules, each of which has cost money for someone `[DOCS]` / `[INFERENCE]`:

- **Four breakpoints maximum.** Budget them; do not scatter them.
- **Everything variable goes after breakpoint 4.** A timestamp or a request id anywhere earlier
  invalidates the rest.
- **Every collection carries an explicit `ORDER BY`.** The chart of accounts arriving in a different order
  is a total cache miss with no error.
- **Assert on `cache_read_input_tokens` in an integration test**, because the minimum-prefix constant
  moves per model release and fails silently (`OVERVIEW.md` §8.2).

### 5.3 Stage 3 — rendering, and the quarantine

The instruction section is a versioned template with **no interpolation of untrusted content, ever**
(`R-33`). Tenant variation is data in sections 4–7.

Zone 3 content — the document text — appears **only** in section 8, inside a constant wrapper, in the
extraction capability, and nowhere else in the system:

```
   ┌── EXTRACTION CAPABILITY ────────────────────────────────────────────┐
   │  section 8:  <document_content id="doc_88213">                       │
   │                 …attacker-authored OCR text…                         │
   │              </document_content>                                     │
   │                                                                      │
   │  output schema:  { reasoning, fields[{name, value, span, conf}] }    │
   │                  value spaces constrained; no free-text passthrough   │
   └──────────────────────┬──────────────────────────────────────────────┘
                          │  a TYPED RECORD crosses this line — never text
   ┌──────────────────────▼──────────────────────────────────────────────┐
   │  DRAFTING CAPABILITY   receives the record. Never the document.      │
   │  Quoted spans travel as {text, offset, doc_id} — data with provenance│
   └─────────────────────────────────────────────────────────────────────┘
```

This is `B-15`, and it is the same mechanism Claude Code uses for fetched web content — "Web fetch uses a
separate context window to avoid injecting potentially malicious prompts" (`OVERVIEW.md` §6.7). The
attacker gets exactly one call, whose entire output surface is typed and value-constrained.

### 5.4 Stage 4 — the ModelGateway

One component owns every provider interaction:

| Responsibility | Detail |
|---|---|
| **Tier selection** | From the capability config, escalated by **task properties** only — a validation failure, an ambiguity count, an unmatched account, a monetary threshold — never by the model's self-reported confidence (`A-04`, `OVERVIEW.md` §8.5) |
| **Cache control** | Places up to 4 breakpoints per §5.2; chooses 5-minute vs 1-hour TTL per tenant activity profile |
| **Batch vs live** | Batchable capabilities (nightly extraction, month-end sweeps, re-embedding) go to the Batches API at 50% off, with a **1-hour** cache TTL because a 5-minute entry expires mid-batch `[DOCS]` |
| **Retry** | Bounded, jittered, on transport and rate-limit errors only. **Never** on a semantically unsatisfying answer — that is a different answer, not a retry |
| **Idempotency** | A request key so a retried invocation cannot produce two proposals for one subject; `P-12`'s partial unique index is the backstop |
| **Telemetry** | Emits the `CostRecord` for `B-16` |

### 5.5 Stages 5–6 — parse and validate

The output schema is ordered `{ reasoning, evidence, decision, confidence }` (`B-05`), with every
enumerable field constrained to a set supplied in the request (`B-06`).

On a parse failure: **one** repair attempt with the validation error appended, then give up with
`outcome = 'gave_up'`. Not a loop (`B-08`).

Validation in Zone 1 is a **pre-flight, not a substitute** for Zone 0's authority. Zone 0 re-validates
everything as untrusted input — S4-01 already specifies `AiProposalService` "validates the payload as
untrusted" `[CODE]`. Zone 1 validates only to avoid wasting a round trip and to produce a better
`violations[]` array. If the two ever disagree, **Zone 0 wins and it is a bug in Zone 1**.

### 5.6 Stage 7 — the proposal DTO

```
ProposalDTO
├── outcome            proposed | no_candidate | unavailable | over_budget | gave_up   (A-15)
├── proposal?          typed per capability — never a free-text blob
├── violations[]?      structured, aggregated (S2+A shape)
├── confidence         [0,1] — recorded, ranks, authorises nothing (R-32)
├── rationale          STRUCTURED: rules_fired[], feature_contributions[],
│                      precedents_cited[] (by primary key), spans[]        (A-10)
├── provenance         model_id, model_version, prompt_version,
│                      dossier_hash, truncated_sections[]
└── cost               input, cache_read, cache_creation, output, latency_ms  (B-16)
```

`rationale` being structured is required by `P-12`. Three things follow that `P-12` does not say: it is
cheaper (output tokens are ~5× input), it is regression-testable, and every `precedents_cited` entry is a
dereferenceable row — which is `I-12` Number Provenance arriving as a side effect rather than a project.

---

<a id="6"></a>
## 6 · Retrieval and memory

### 6.1 The three tiers

```
   QUESTION: "which account does this line belong to?"
        │
   ┌────▼──────────────────────────────────────────────────────────────────────┐
   │ TIER 1 — EXACT STRUCTURED LOOKUP                    Zone 0, SQL, no model │
   │   ai_categorization_rules (company, vendor, pattern) → account, hit_count │
   │   Sub-millisecond. Exact. Explicable. Free.                               │
   │   ── If a row exists with sufficient support, THE AI IS NEVER CALLED ──   │
   └────┬──────────────────────────────────────────────────────────────────────┘
        │ miss
   ┌────▼──────────────────────────────────────────────────────────────────────┐
   │ TIER 2 — APPLICABILITY PREDICATE                    Zone 0, SQL, no model │
   │   judgements WHERE company_id = :c                                        │
   │              AND effective_from <= :date                                  │
   │              AND superseded_by IS NULL          ◄── THE TEMPORAL FILTER   │
   │              AND applies_to(subject)                IS A WHERE CLAUSE,    │
   │                                                     NOT A PROMPT (I-05)   │
   └────┬──────────────────────────────────────────────────────────────────────┘
        │ still ambiguous
   ┌────▼──────────────────────────────────────────────────────────────────────┐
   │ TIER 3 — SEMANTIC                                   Zone 0 query, pgvector│
   │   hybrid: dense (pgvector) + lexical (tsvector/BM25), RRF-fused           │
   │   optional cross-encoder rerank over the top ~50                          │
   │   corpus: judgement text · vendor description patterns · policy prose     │
   │   NOT raw document text (§6.3)                                            │
   └────┬──────────────────────────────────────────────────────────────────────┘
        │
   ┌────▼──────────────────────────────────────────────────────────────────────┐
   │ THE MODEL   receives tiers 1–3 as cited evidence and proposes             │
   └───────────────────────────────────────────────────────────────────────────┘
```

Tier 1 is the point of the design. `docs/ai/memory/ACCOUNTING_MEMORY.md` already specifies
`ai_categorization_rules` as "a structured, non-embedding companion table built for the one thing
free-text semantic memory is comparatively slow and imprecise at" — that judgement is correct and this
architecture makes it the *first* tier rather than a companion.

The temporal filter in tier 2 is the mechanism that makes `I-05`'s stated risk ("a superseded rule still
influencing the AI is a silent, systematic error affecting hundreds of entries") structurally impossible.
Test: insert a superseded judgement, assert it never appears in any retrieval result, for every capability.

### 6.2 Memory, mapped to the three kinds that matter

| Kind | Store | Written by | Read by | Governance |
|---|---|---|---|---|
| **Precedent** — "this vendor maps here, 34/34" | `ai_categorization_rules` | The learning loop, via a Laravel Action | Tier 1 | Confidence accrual/decay; never instant overwrite |
| **Judgement** — "we decided X on date Y because Z" (`I-05`) | `judgements` | **A human**, harvested from an action they were already taking | Tier 2 | `effective_from` / `supersedes` / `superseded_by`; authorship and role displayed |
| **Correction corpus** — the labelled record (`I-09`, `S3+A`) | `ai_corrections` + proposal outcomes | The review flow | **Not at inference time.** Evals, calibration, few-shot selection | Tenant-split; never cross-tenant without R-01 answered |

`[INFERENCE]` The third row is the one most likely to be misused. The correction corpus is a *training
and evaluation* asset. Retrieving raw corrections at inference time re-introduces the feedback oscillator
described in `OVERVIEW.md` §4.3 — the system learns from its own priors. Corrections influence inference
only after being *distilled into a precedent row* by an explicit, auditable process.

### 6.3 What is embedded, and what is not

| Embedded | Not embedded — and why |
|---|---|
| Judgement text | Raw document OCR text — the extracted record supersedes it and is exactly queryable |
| Vendor/customer description patterns | Journal entry lines — they have keys, amounts and dates |
| Policy and materiality prose | The chart of accounts — it is small, exact, and belongs in the cached prefix |
| FAQ / help content | Bank narratives once parsed — the parse is the retrievable thing |

This is what keeps the vector corpus in the **low thousands per tenant** instead of tens of thousands per
tenant per year, and it is the precondition on which the R-02 answer rests (`OVERVIEW.md` §5.4). It is a
design constraint with a number attached, not a preference: if the embedded corpus starts growing with
document volume, the pgvector decision needs re-opening.

### 6.4 Where embeddings are generated and stored

```
   Zone 1 generates          Zone 0 stores                    Zone 0 queries
   ┌──────────────┐          ┌────────────────────┐           ┌──────────────┐
   │ ModelGateway │─vector──▶│ StoreEmbedding     │──────────▶│ pgvector     │
   │  embed()     │  in the  │ Action (P-01 path) │  INSERT   │ + tsvector   │
   └──────────────┘ response └────────────────────┘           │ + RLS FORCE  │
                                                              └──────────────┘
```

Consistent with §3.2: the engine computes, Laravel writes, one write path.

---

<a id="7"></a>
## 7 · The injection defence stack

Ordered by the strength of the guarantee, strongest first. This ordering **is** the design, in the same
way `P-12`'s three-layer ordering is.

```
 ┌────────────────────────────────────────────────────────────────────────────────┐
 │ L1 · PRIVILEGE          The engine holds no credential that writes.            │
 │      (Z1)               A fully compromised AI service cannot write a row.     │
 │                         ◄── THIS IS THE GUARANTEE.  (P15, P-12 layer 1)        │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L2 · CONTROL FLOW       No untrusted value selects code, tool, query or        │
 │      (Z3, B-01)         tenant. Injection can change a VALUE, never an ACTION. │
 │                         ◄── this is what CaMeL pays 7 points to manufacture.   │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L3 · EGRESS             No outbound route but the model provider. The UI       │
 │      (Z2, Z6, B-14)     renders no model-authored URL and fetches none.        │
 │                         ◄── severs leg 3 of the lethal trifecta. EchoLeak's    │
 │                             channel was an ALLOWLISTED image proxy.            │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L4 · QUARANTINE         Zone 3 text enters exactly one call, wrapped, and      │
 │      (Z4, B-15)         leaves as a typed record. Never re-injected as text.   │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L5 · VALUE SPACE        Enumerable fields are enums over sets the model did    │
 │      (Z5, B-06)         not author. An unrepresentable answer cannot be given. │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L6 · OUTPUT HANDLING    Model output is never executed, evaluated, compiled,   │
 │      (R-33)             or turned into SQL by string assembly. Selectors are   │
 │                         closed JSON compiled through an allowlist.             │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L7 · DELIMITING         Untrusted content is clearly framed as data.           │
 │                         Reduces likelihood. Creates no boundary. (R-33)        │
 ├────────────────────────────────────────────────────────────────────────────────┤
 │ L8 · CLASSIFIER         Injection detection as TELEMETRY ONLY.                 │
 │      (A-11)             Alerts that someone is trying. Never counted as a      │
 │                         control. EchoLeak bypassed Microsoft's XPIA.           │
 └────────────────────────────────────────────────────────────────────────────────┘

    NOT IN THIS STACK:  human approval.
    Approval defends against MODEL ERROR. An injected proposal is optimised to
    look correct, so review does not stop it. Counting approval as an injection
    defence causes both controls to be under-invested. (OVERVIEW §6.5)
```

### 7.1 Where each layer is verified

| Layer | Verification |
|---|---|
| L1 | CI grep asserting `apps/ai` declares no DB driver (Gap G-8, effort 1) |
| L2 | Code review + a test asserting the pipeline branches on no field of the parsed output except a validated enum |
| L3 | A test asserting an outbound request from the engine to a non-allowlisted host fails; a frontend test asserting a model-authored `http://` string renders as inert text |
| L4 | A test asserting the drafting capability's rendered prompt contains no substring of the source document beyond declared spans |
| L5 | Property test: a proposal referencing an account outside the supplied enum cannot be constructed |
| L6 | Existing: no `eval`, no string-assembled SQL. Extend to the selector compiler when it lands |
| L8 | Dashboard only. Never a gate. |

### 7.2 The red-team set

`[INFERENCE]` A standing corpus of adversarial fixtures, run in CI, containing at minimum: an invoice PDF
with instructions in white-on-white text; instructions in the OCR of an embedded image; instructions in a
filename; instructions in a bank narrative field; a document instructing the model to use a different
account; a document instructing it to emit a URL; a document instructing it to ignore the chart of
accounts; a document impersonating a system message. **The pass criterion is not "the model resists" —
it is that every layer L1–L6 holds regardless of what the model does.** Anthropic's own published
residual of 11.2% (`OVERVIEW.md` §6.3) is the reason the criterion is written that way.

---

<a id="8"></a>
## 8 · Sequence flows

### 8.1 Document → extraction → journal draft (S4-02 / S4-03)

```
 User      Laravel                    FastAPI                Model        Postgres
  │           │                          │                     │             │
  ├─upload───▶│                          │                     │             │
  │           ├─RegisterDocumentAction───────────────────────────────────────▶│
  │           │  (Storage + documents row)                                    │
  │           │                          │                     │             │
  │           ├─AiCostGovernor.check(company, capability) ─────────────────────│
  │           │   over budget → 429 + Retry-After, STOP                       │
  │           │                          │                     │             │
  │           ├─AiContextAssembler ──────────────────────────────────────────▶│
  │           │   COA(ORDER BY code) · policies · judgements(active) ·        │
  │           │   vendor precedents · document reference                      │
  │           │◀──────────────────────────────────────── dossier ─────────────┤
  │           │                          │                     │             │
  │           ├─invoke(extract_document)▶│                     │             │
  │           │                          ├─build/render────────│             │
  │           │                          │  §8 = QUARANTINED doc text (L4)   │
  │           │                          ├─call (Haiku, cached prefix)──────▶│
  │           │                          │◀──── typed fields + spans ────────┤
  │           │                          ├─parse · value-constrain (L5)      │
  │           │◀── ExtractionDTO ────────┤                     │             │
  │           │                          │                     │             │
  │           ├─invoke(draft_journal, record) ───────────────▶│             │
  │           │       ▲ THE RECORD CROSSES. THE DOCUMENT DOES NOT.           │
  │           │                          ├─call (Sonnet)──────────────────────▶│
  │           │                          │◀── {reasoning, lines[], conf} ─────┤
  │           │                          ├─validate: balances? accounts in    │
  │           │                          │  the supplied enum? within         │
  │           │                          │  document tolerance?               │
  │           │◀── ProposalDTO + CostRecord ─────────────────┤             │
  │           │                          │                     │             │
  │           ├─AiProposalService: re-validate as UNTRUSTED                   │
  │           ├─AutonomyResolver ──▶ requires_approval  (P15: always, here)   │
  │           ├─CreateAiDraftAction ────────────────────────────────────────▶│
  │           │    origin='ai_draft'  status='draft'  ai_generated=true       │
  │           │    ai_confidence set  created_by_agent set                    │
  │           │    ▲ trg_no_ai_autopost + the UPDATE trigger (IM-01) enforce  │
  │           │      that this can never reach 'posted' without approved_by   │
  │           ├─write CostRecord ───────────────────────────────────────────▶│
  │           ├─broadcast signal on private-company.{id}.ai  (S2-13 outbox)   │
  │◀─queued───┤                          │                     │             │
  │           │                          │                     │             │
  │ ── later: a HUMAN opens the review surface ──                            │
  ├─review───▶│  sees: source doc · amount · account · confidence ·           │
  │           │        structured rationale · diff vs the deterministic path  │
  ├─approve──▶│  engagement act above materiality (B-12)                      │
  │           ├─PostJournalEntryAction ─────────────────────────────────────▶│
  │           │    approved_by = a real human user id                         │
  │           │    → PostingService → ledger_entries (append-only)            │
  │           ├─record: outcome, latency_to_decision, edits[] (B-17)          │
```

**Six properties fall out.** Cost is checked before a token is spent. The document text is in exactly one
context window. The record crosses the boundary, not the document. The draft cannot become posted without
a human on the row, enforced by the database. Every artefact carries model, prompt and dossier
provenance. The approval produces a label.

### 8.2 Bank reconciliation — deterministic first, AI on the residual (S3-05 / S3-08 / S3-09)

```
 statement import
      │
      ├──▶ to SUSPENSE immediately  ── the bank balance is correct BEFORE matching,
      │                                and the suspense balance IS the backlog (S3-04)
      ▼
 ┌─────────────────────────────────────────────────────────────────────────────┐
 │ TIER 1 — DETERMINISTIC ENGINE  (Laravel + Postgres, S3-05)                   │
 │   exact reference · exact amount+date · known template                       │
 │   ~40% resolved here. NO TOKENS SPENT. Auto-commits — it is a RULE.          │
 └───────────────────────────┬─────────────────────────────────────────────────┘
                     residual │  ◄── the AI only ever sees this
 ┌───────────────────────────▼─────────────────────────────────────────────────┐
 │ TIER 2 — AI SCORING  (capability: banking.match)                             │
 │   INPUT : the residual + a CLOSED CANDIDATE SET chosen by Zone 0             │
 │   OUTPUT: ranked (candidate_id, confidence, structured rationale)            │
 │                                                                              │
 │   ▸ candidate ids come from Zone 0. The model RANKS a set;                    │
 │     it does not SEARCH for one.            ◄── this is L2 in the flow        │
 │   ▸ similarity is CAPPED — AI alone never crosses the commit threshold       │
 │     (S3-08 acceptance criterion, already specified)                          │
 └───────────────────────────┬─────────────────────────────────────────────────┘
                             ▼
 ┌─────────────────────────────────────────────────────────────────────────────┐
 │ TIER 3 — HUMAN  (S3-09 workbench)                                            │
 │   Accept → commitManualMatch(match_method='ai_suggested_accepted')            │
 │   Reject → dismissed, RETAINED as a labelled negative (P-12, S3+A)           │
 │   Unmatch → INSERT a compensating link, never DELETE (P-13)                  │
 └─────────────────────────────────────────────────────────────────────────────┘
```

`[INFERENCE]` The "ranks a set, does not search for one" property is worth naming because it is easy to
lose. If the model were given a search tool over transactions, an injected bank narrative could steer the
search. Given a closed candidate list assembled by Zone 0, the worst an injection achieves is a wrong
ranking of legitimate candidates — which a human then rejects, producing a label.

### 8.3 Copilot — the only agentic loop (S4-10)

```
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │  THE ONE PLACE THE MODEL CHOOSES.  Constraints that make it acceptable:      │
 │                                                                              │
 │   • READ-ONLY tool surface. No tool in it can write anything.                │
 │   • Tools execute in ZONE 0, under the ASKING USER's permissions —           │
 │     payroll never surfaces to a user without payroll.read (S4-10, specified) │
 │   • Closed tool set; a bare-name deny removes a tool from context entirely   │
 │   • STEP BUDGET + TOKEN BUDGET + DEADLINE; exhaustion is a reported outcome  │
 │   • No Zone 3 content in this loop. It reads STRUCTURED records only.        │
 │   • Every answer carries citations as INTERNAL IDS. No URLs. (L3)            │
 │   • Evaluated with pass^k, not pass@1  (OVERVIEW §9.2)                       │
 └──────────────────────────────────────────────────────────────────────────────┘

 Browser        Laravel (CopilotService)        FastAPI            Model
    │  question      │                             │                 │
    ├───────────────▶├─ scope tools to the user's permissions        │
    │                ├─ budget check ──────────────│                 │
    │                ├─ invoke(copilot.answer) ───▶│                 │
    │                │                             ├─ call ─────────▶│
    │                │                             │◀ tool_use ──────┤
    │                │◀─ tool request (typed) ─────┤                 │
    │                ├─ EXECUTE IN ZONE 0 under the user's RLS        │
    │                │   (the model named a tool; Laravel decides     │
    │                │    whether this user may call it)              │
    │                ├─ result ───────────────────▶│                 │
    │                │                             ├─ call ─────────▶│
    │◀─ SSE tokens ──┤◀─ stream ───────────────────┤◀ text ──────────┤
    │                ├─ persist final turn + citations → ai_messages  │
    │                │   step budget exhausted → "I could not         │
    │                │   complete this", recorded (A-15, B-08)        │
```

`[INFERENCE]` Note the asymmetry that makes this safe: the model *names* a tool; **Laravel decides
whether the tool may be called by this user for this tenant**. The model's choice is a request, not an
authorisation — which is the same structure as `P-12`'s proposal/Action split, applied to reads.

### 8.4 The Challenger (I-10) — adversarial review as a capability

```
   after a batch of entries is posted (S2-13 outbox event)
        │
        ▼
   capability: auditor.challenge      ── runs on the BATCH path, 50% off
        │  input : posted entries + active judgements + period baselines
        │  output: findings[] — NOT proposals, NOT entries
        ▼
   ai_findings  (a read model; writes nothing to the ledger)
        │
        ├─ "entry 8812 contradicts active judgement J-118"   ◄── DRIFT DETECTION,
        │                                                        which I-05 names
        │                                                        as part of the
        │                                                        feature, not a
        │                                                        follow-up
        ├─ "vendor X posted to 5210 in 34/34 priors, this one to 5130"
        └─ "period 2026-06 accrual is 3.2σ from the trailing mean"
                │
                ▼
        surfaced in the Command Center. A human investigates and, if warranted,
        REVERSES through the normal path (P-13). No autonomous correction. Ever.
```

`08_MASTER_BACKLOG.md` rates `X-04` (The Challenger) Medium/13 with the right test: *"Does it find real
errors, or generate noise? Measure precision before shipping."* `[INFERENCE]` The architecture makes that
measurable cheaply, because a finding is an object with an outcome, so precision is
`confirmed_findings / total_findings` — a query. Ship it dark first: generate findings, show nobody,
measure precision for a month, then surface it if precision clears a threshold. A noisy Challenger
trains users to ignore alerts, which is worse than not having one.

---

<a id="9"></a>
## 9 · The Laravel ↔ FastAPI contract

### 9.1 Endpoints

| Direction | Endpoint | Purpose |
|---|---|---|
| Laravel → FastAPI | `POST /internal/invoke` | The single task endpoint. Discriminated union over the capability enum. |
| Laravel → FastAPI | `POST /internal/embed` | Generate vectors; returns them. Does not store them. |
| Laravel → FastAPI | `GET /internal/readyz` | Reports `not_ready` when provider egress is down (S3-07, specified) |
| Laravel → FastAPI | `POST /internal/events` | Domain events relayed after commit (S3-07, specified) |
| FastAPI → Laravel | — | **Nothing.** The engine initiates no call into Zone 0. |

`[INFERENCE]` The last row is a deliberate simplification of S4-01's shape. If the engine never initiates,
there is no inbound authorisation surface on Laravel for the engine at all, and `AiProposalService`'s job
of "re-establishing tenant scope" becomes unnecessary because scope was never lost. Long-running work is
handled by Laravel queueing the invocation, not by the engine calling back. **This removes an entire class
of confused-deputy risk** (the class MCP's security document spends most of its length on —
`OVERVIEW.md` §6.6).

### 9.2 Transport

As S3-07 already specifies `[CODE]`: mTLS verify-full, internal bearer compared with
`hmac.compare_digest`, shared contract fixtures on both sides. Additions:

- **Contract fixtures cover responses too**, not just requests — a response shape change is as breaking.
- **The capability enum is generated from one source** consumed by both sides, so adding a capability in
  Python without adding it in PHP is a compile-time failure.
- **`request_id` propagates** into the model call metadata and the cost record, so one identifier links
  the HTTP request, the proposal, the model call, the cost and the audit row.

### 9.3 Failure semantics

| Condition | Engine returns | Laravel does |
|---|---|---|
| Provider unreachable | `outcome='unavailable'` | AI-only endpoint → `503 + Retry-After`; AI-optional → `200` with `meta.ai_suggestion: null` **and a recorded reason** (S4-11, plus `A-15`) |
| Per-request budget exhausted | `outcome='over_budget'` | `429 + Retry-After`; warn before hard-limiting (S4-11, specified) |
| Parse failed after one repair | `outcome='gave_up'` | Record; count; no proposal |
| Validation violations | `outcome='proposed'` + `violations[]` | Surface to the human as a flagged proposal, not a silent drop |
| Timeout | `outcome='gave_up'` | As above |

The discipline: **every non-success is a value with a reason, never an absence** (`A-15`).

---

<a id="10"></a>
## 10 · The evaluation harness

```
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │ CORPUS  (grows automatically — B-17, S3+A, I-09)                              │
 │   rejected proposals · edited-then-accepted (edit = the label) ·              │
 │   blind-sample agreements and disagreements · posting_attempts violations ·   │
 │   reversals with reasons                                                      │
 └────────────────┬──────────────────────────────────────────────────────────────┘
                  │  split BY TENANT, never by row
      ┌───────────┴────────────┐
      ▼                        ▼
 ┌──────────────────┐   ┌──────────────────────────────────────────────────────┐
 │ FROZEN           │   │ ROLLING                                              │
 │ regression set   │   │ last N weeks, appended continuously                  │
 │ gates CI         │   │ reported separately, incl. NEWLY-SEEN SUBJECTS       │
 │ answers:         │   │ answers:                                             │
 │ "did we break    │   │ "does this work on what is arriving now?"            │
 │  what worked?"   │   │ a widening gap between the two IS the drift signal   │
 └────────┬─────────┘   └───────────────┬──────────────────────────────────────┘
          └───────────────┬─────────────┘
                          ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │ GRADERS, in the published order of robustness  (OVERVIEW §9.3b)               │
 │                                                                               │
 │  1 CODE   ── over invariants QAYD ALREADY OWNS:                               │
 │              balanced? · account exists and is postable? · period open? ·     │
 │              amount within document tolerance? · reconciliation doesn't       │
 │              over-consume? · equals the human's corrected version?            │
 │              ◄── binary, reproducible, free, auditor-defensible.              │
 │                  Most AI products cannot do this. QAYD can.                   │
 │                                                                               │
 │  2 HUMAN  ── sampled, for what code cannot decide: is the ACCOUNT right?      │
 │              (R-32 §4 — a proposal can balance, parse, reference real         │
 │               accounts, and still book capex to repairs)                      │
 │                                                                               │
 │  3 JUDGE  ── explanation quality ONLY. Validated against human labels with    │
 │              precision and recall reported separately. Order randomised,      │
 │              identity masked. NEVER scores financial correctness.             │
 └───────────────────────────────────────────────────────────────────────────────┘
                          │
                          ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │ REPORTED PER (capability × model_version × prompt_version)                    │
 │   accuracy · accuracy on newly-seen subjects · CALIBRATION CURVE + Brier      │
 │   (B-11, over the BLIND stream only) · give-up rate · violation rate ·        │
 │   cost per proposal · pass^k for the Copilot                                  │
 └───────────────────────────────────────────────────────────────────────────────┘
                          │
                          ▼
        CI GATE: a prompt or model change is a DEPLOYMENT and must pass.
```

`[INFERENCE]` The single most valuable structural fact here: **QAYD's ground truth is in the database.**
Almost every AI product must reach for a model judge because it has no oracle. An accounting system has
an oracle for a large fraction of what matters. That makes the cheapest, most robust grader family also
the most applicable — an advantage that should be exploited rather than skipped in favour of the fashionable
approach.

---

<a id="11"></a>
## 11 · Cost controls in the architecture

Arithmetic lives in `05_FUTURE_ARCHITECTURE.md` §E. This is where the controls sit in the design.

```
  1 · DETERMINISTIC FIRST     ~40% of matching resolved with zero tokens (§8.2)
                              ── the cheapest token is the one not spent

  2 · CACHED PREFIX           sections 1–7 (§5.2), ORDER BY everywhere,
                              4 breakpoints, TTL chosen per tenant activity,
                              assert cache_read_input_tokens in an integration test

  3 · MODEL TIER              from capability config; escalate on TASK PROPERTIES,
                              never on self-reported confidence (A-04)

  4 · BATCH                   nightly extraction · month-end sweeps · re-embedding
                              50% off; use the 1-HOUR cache TTL inside batches

  5 · CONTEXT BUDGET          per-section ceilings; truncation recorded (B-03)

  6 · LOOP BUDGET             steps + tokens + deadline; give-up is an outcome (B-08)

  7 · OUTPUT DISCIPLINE       structured rationale, references not restatements —
                              output is ~5× input price (OVERVIEW §8.6)

  8 · COST RECORD             per proposal (B-16) → feeds AiCostGovernor (S4-11),
                              per-tenant margin, and the cache-regression alert
```

`[INFERENCE]` Controls 1 and 2 dominate. Control 1 is architectural and already decided. Control 2 is the
one that breaks silently, and it breaks through ordinary changes — a reordered serialisation, an added
timestamp, a tool-schema tweak (`B-18`). That is why it gets a test and an alert rather than a convention.

---

<a id="12"></a>
## 12 · Deployment, observability, failure modes

### 12.1 Deployment

The engine is stateless and horizontally scalable — it holds no session, no database connection and no
tenant state between requests. Two consequences: scale on queue depth, and **model/prompt version changes
are the only stateful thing about a deploy**, which is why they are stamped on every artefact (`B-07`).

`[INFERENCE]` Anthropic's rainbow-deployment note — gradually shifting traffic while keeping both
versions running, "to avoid disrupting running agents" (`OVERVIEW.md` §2.2) — is a good practice for
long-running agents and **mostly unnecessary here**, because the pipeline is short-lived and idempotent.
That is a benefit of the non-agentic design worth noticing: a class of deployment complexity simply does
not arise.

### 12.2 What to record per invocation

| Field | Why |
|---|---|
| `request_id` | Links HTTP → proposal → model call → cost → audit |
| `company_id`, `capability`, `subject_ref` | Attribution and tenant-scoped debugging |
| `model_id`, `model_version`, `prompt_version`, `dossier_hash` | The four things that determine the output. Without all four, a regression is unattributable. |
| `input_tokens`, `cache_read`, `cache_creation`, `output_tokens` | Cost + the cache-regression alert |
| `latency_ms`, `retries`, `outcome` | Reliability |
| `truncated_sections[]` | Silent quality loss becomes visible |
| `violations[]` | Failure clustering, and the seed of the next eval case |

⚠️ **Not recorded: raw prompt or raw model output containing tenant financial data**, beyond a retention
window and outside the normal RLS-protected tables. Microsoft's Business Central retains prompts and
responses for twenty days as support diagnostics — `07_QAYD_INNOVATION.md` **I-05** already notes that
this means "the reasoning is a transient blob that will be gone before any audit ever looks for it." The
durable artefact must be the **structured rationale on the proposal**, which lives under RLS with
everything else.

### 12.3 Failure modes and their detection

| Failure | Symptom | Detection |
|---|---|---|
| Silent cache regression | Cost per proposal steps up; quality unchanged | `cache_read / total_input` alert (`B-16`) |
| Prompt regression | Accuracy drops for one `prompt_version` | Eval gate in CI; cohort query in production |
| Model deprecation | Errors, or silent behaviour change on an alias | Pin exact versions; never call an alias in production |
| Distribution shift | Calibration breaks before accuracy does | Calibration curve on the blind stream (`B-11`) |
| Reviewer fatigue | Approval rate rises, latency falls | Time-to-approve distribution alarm (`B-12`) |
| Injection attempt | Extraction output references something outside the enum | L5 rejects; L8 classifier logs; alert on the rate |
| Runaway loop | Cost spike on one tenant | Per-request ceiling (`B-08`) + governor (S4-11) |
| Truncation creep | Quality falls with tenant age | `truncated_sections` rate alert |
| Feedback oscillation | Precedent confidence rises without independent evidence | Precedent updates sourced only from the blind stream |

---

<a id="13"></a>
## 13 · What is deliberately not built

Recorded so the absences are visible as decisions rather than gaps.

| Not built | Why | Revisit when |
|---|---|---|
| A general agent endpoint | `B-02`, `A-12` | Never on the posting path |
| Concurrent multi-agent orchestration | `A-02` — accounting is consistency-critical | A genuinely breadth-first surface appears; price it at 15× |
| An agent framework | `A-14` — cedes control of the cache key and defaults to agency | Never for prompt assembly; possibly for observability |
| Fine-tuning | `OVERVIEW.md` §4.1 — unattributable, un-supersedable, not tenant-scopable | A style requirement survives honest prompt effort |
| MCP internally | `OVERVIEW.md` §6.6 — imports an OAuth surface QAYD does not need; the internal contract is stronger | Third parties need to plug tools in |
| A separate vector store | `OVERVIEW.md` §5.4 — a second tenant-isolation implementation | The R-02 triggers fire |
| Model-authored SQL | `P15`, `R-33` | Never |
| A cross-tenant model or index | R-01 is unanswered | R-01 is answered |
| Autonomous posting at any confidence | `P15` | Never, while QAYD keeps other people's books |
| Autonomous correction by the Challenger | §8.4 | Never — findings, not fixes |

---

<a id="14"></a>
## 14 · Open questions this architecture does not settle

Stated plainly, because a design that hides its uncertainty is worse than one that names it.

| # | Question | Why it is open | Who decides |
|---|---|---|---|
| Q1 | Will reviewers tolerate `B-12`'s engagement requirement? | It trades the headline benefit (speed) for the core claim (reliability). No amount of engineering resolves it. | A design partner |
| Q2 | Is `AiContextAssembler` in Zone 0 fast enough at scale? | The dossier is 6–7 queries per invocation. Fine at Tier 1; unmeasured at Tier 3. | Measurement, then possibly `P-12`'s `qayd_ai` role as the fallback |
| Q3 | Does the embedded corpus stay small? | The R-02 answer depends on it. One well-meaning "let's embed the documents too" invalidates it. | A tracked metric with a trigger |
| Q4 | What does the Challenger's precision actually look like? | `X-04`'s own gate. Unknowable without running it dark. | A month of shadow operation |
| Q5 | Is calibration measurable at Tier-1 volumes? | Reliability curves need n per bucket. | Data |
| Q6 | Who is liable for an approved-but-wrong AI-originated posting? | **R-04 in the backlog, unanswered.** It gates every autonomy increase, including `B-13`. | Legal, not engineering |
| Q7 | Does any capability ever clear the bar for real autonomy? | `P15` forecloses posting. Everything else is small. `B-13` may be a solution in search of a problem. | Honest assessment after Sprint 4 |

`[INFERENCE]` Q6 deserves emphasis. `B-13` and `I-17` describe a mechanism for graduated autonomy, and
that mechanism is well-designed, but the question of **who signs** is not an engineering question and it
is currently unanswered in QAYD's own backlog. Building the budget machinery before the liability model
exists risks building a very good answer to a question nobody has asked yet.

# End of Document
