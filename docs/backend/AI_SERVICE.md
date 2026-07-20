# AI Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: AI_SERVICE
---

# Purpose

The AI Service is the Laravel-side boundary between QAYD's business kernel and the separate FastAPI AI
engine. It is not the AI. The intelligence — the fifteen-agent LangGraph orchestration, the OCR
pipeline, the vector retrieval, the model calls — lives in a Python deployable that has **no database
credentials to any tenant schema** (see [../foundation/AI_ARCHITECTURE.md](../foundation/AI_ARCHITECTURE.md)
and the security-boundary diagram in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)). This service is
the Laravel code that stands between them: it invokes the engine, relays its streaming output, receives
the engine's proposals back, and — critically — is the *only* path by which anything the AI produces
becomes a fact in PostgreSQL.

That single sentence governs every design decision below. The AI engine can reason, retrieve, draft, and
recommend, but it cannot write. When an agent decides a journal entry should be posted, it does not
`INSERT` — it calls back into Laravel through `POST /api/v1/ai/proposals`, which lands in this service,
which validates the payload through the identical FormRequest + RBAC + Service pipeline a human clicking
the same button would traverse, and then either executes it (`auto`), surfaces it for one-click
acceptance (`suggest_only`), or files it into the Workflow/Approval engine and stops
(`requires_approval`). The AI never auto-commits a sensitive action, and it never skips validation. This
is enforced structurally, not by the agent's good behavior.

The AI Service therefore owns four responsibilities that no other module owns: (1) the **outbound proxy**
that carries triggers and context to the FastAPI engine (fire-and-forget event notifications, queued
async jobs, and a synchronous streaming relay for the Financial Copilot); (2) the **inbound proposal
gateway** (`POST /api/v1/ai/proposals`) that turns an agent's `proposed_action` into a governed write;
(3) the **agent catalogue and per-company tuning** surface (`ai_agents`, `ai_agent_settings`); and (4)
the **cost, rate, and degraded-mode governance** that keeps a runaway or unreachable engine from
harming the tenant. It cross-links [../security/AI_SECURITY.md](../security/AI_SECURITY.md) for the
service-identity and injection-defense model, and [../backend/WORKFLOW_ENGINE.md](../backend/WORKFLOW_ENGINE.md)
for what happens to a proposal once it is filed for approval.

# Responsibilities

| # | Responsibility | Boundary |
|---|---|---|
| 1 | Relay triggers (domain events, schedules, user requests) to the FastAPI engine | This service formats and signs the call; it never runs inference itself |
| 2 | Assemble the RBAC-scoped context an agent is allowed to see, and never more | Context is scoped to the *initiating principal's* permission grant, per [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) |
| 3 | Receive agent proposals and run them through the full Laravel write pipeline | FormRequest → Policy/RBAC → Service → Repository → Model, identical to a human write |
| 4 | Compute `autonomy_applied` and route: execute, suggest, or file-for-approval | Never auto-commits a `requires_approval`-class action, regardless of confidence |
| 5 | Persist confidence, reasoning, and sources onto `ai_decisions`/`ai_logs` | Every AI-caused mutation carries `ai_assisted=true` + `ai_confidence` into the audit trail |
| 6 | Stream Copilot turns back to the browser over SSE, and broadcast state on `.ai` | The browser never calls FastAPI directly — Laravel is the only reachable surface |
| 7 | Expose the agent catalogue and gate per-company autonomy tuning | Tuning `ai_agent_settings` is itself a `ai.settings.update` approval-gated action |
| 8 | Govern cost and rate; degrade gracefully when the engine is slow or unreachable | AI-only endpoints return `503`; AI-optional endpoints degrade to `meta.ai_suggestion: null` |

What this service explicitly does **not** own: the agent reasoning (FastAPI), the tenant database writes
of business modules (each owning module's Service), the notification fan-out of an AI alert
([NOTIFICATION_SERVICE.md](NOTIFICATION_SERVICE.md)), the document extraction pipeline
([FILE_SERVICE.md](FILE_SERVICE.md)), and the immutable recording of what happened
([AUDIT_SERVICE.md](AUDIT_SERVICE.md)). It orchestrates all of them; it reimplements none of them.

# Domain Model

The AI Service is the Laravel-side reader and writer of the AI tables defined in
[../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md). It does not redefine their schema — it consumes it.
The load-bearing entities:

- **`ai_agents`** — the platform-wide catalogue of the fifteen specialist agents (`code`, `name_en`,
  `name_ar`, `category`, `mandate`, `default_autonomy`, `model_profile`, `escalates_to`). No
  `company_id`: the roster is the product, not a tenant choice. This is the one AI table without Row
  Level Security, because it holds no tenant data.
- **`ai_agent_settings`** — per-company tuning of each agent: `enabled`, `autonomy_override`,
  `thresholds` (JSONB — `auto_post_confidence`, `auto_post_max_amount`), `escalation_role_id`. Unique
  per `(company_id, agent_code)`. Editing a row is a sensitive operation.
- **`ai_tasks`** — the unit of work. One row per agent invocation (`task_type`, `status`,
  `trigger_type`, `subject_type`/`subject_id`, `input`, `output`, `confidence`, `latency_ms`).
- **`ai_decisions`** — the structured proposal object. Carries `decision_type`, `decision_class`,
  `confidence`, `reversibility`, `expected_impact`, `reasoning`, `sources`, `proposed_action`,
  `risk_score`, `autonomy_applied`, `status`, `approval_request_id`, and, once executed,
  `executed_reference_type`/`executed_reference_id`. This is the central artifact this service reads
  when a proposal arrives and writes when it resolves.
- **`ai_conversations`** / **`ai_messages`** — the Copilot thread and its turns (`role`, `agent_code`,
  `content`, `tool_calls`, `confidence`, `citations`, `decision_id`).
- **`ai_memory`** — per-company retrieval substrate (`memory_type`, `content`, `embedding VECTOR(1536)`,
  `structured_value`). RLS-scoped identically to every tenant table; there is no cross-company retrieval
  path in the schema at all.
- **`ai_corrections`** — the learning signal (`original_proposal`, `human_action`, `final_value`).
- **`ai_logs`** — the append-only, partitioned action log (`event_type`, `payload`, `tokens_input`,
  `tokens_output`, `latency_ms`). More granular than `ai_tasks`: one task fans out to many tool calls,
  each captured here.
- **`approval_requests`** / **`approval_request_approvers`** — the approval chain the AI Service files a
  `requires_approval` decision into; owned operationally by [WORKFLOW_ENGINE.md](WORKFLOW_ENGINE.md).

The lifecycle of an `ai_decisions.status` is the spine of this service:

```
proposed ──► auto_executed                (autonomy_applied = 'auto', validation passed, written)
        ├──► accepted / edited_and_accepted  (suggest_only, a human clicked accept)
        ├──► rejected                        (suggest_only, a human declined)
        ├──► pending_approval ──► approved ──► (executed)   (requires_approval, chain resolved yes)
        │                    └──► denied                      (requires_approval, chain resolved no)
        └──► expired                          (suggest_only/pending, SLA lapsed unactioned)
```

Every transition writes an `audit_logs` row via [AUDIT_SERVICE.md](AUDIT_SERVICE.md) with the approving
human (or the AI service-user for autonomous low-risk actions) as `actor`, and every terminal execution
carries `new_values.ai_assisted = true` and `new_values.ai_confidence` so the audit trail distinguishes
AI-originated changes from purely human ones.

# Key Classes

Namespaced under `App\Services\Ai`, with controllers under `App\Http\Controllers\Api\V1\Ai`. The engine
transport is abstracted behind an interface so tests and local dev can stub it.

```php
<?php

namespace App\Services\Ai;

/**
 * The transport boundary to the FastAPI engine. The only class in the platform
 * that holds the internal_token and speaks to ai-engine.internal.qayd.app.
 * Everything above it deals in typed DTOs, never raw HTTP.
 */
interface AiEngineClient
{
    /** Fire-and-forget: notify the engine a subscribed domain event was relayed. */
    public function notifyEvent(AiEventEnvelope $envelope): void;

    /** Synchronous request/response for short, bounded work (intent classify, single tool). */
    public function invoke(AiInvocation $invocation): AiEngineResponse;

    /** Streaming turn for the Copilot; yields SSE frames as the engine produces them. */
    public function stream(AiInvocation $invocation): \Generator;
}
```

```php
<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

final class HttpAiEngineClient implements AiEngineClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $internalToken) {}

    public function notifyEvent(AiEventEnvelope $envelope): void
    {
        // Fire-and-forget. Never blocks the domain transaction — dispatched from a queued listener.
        Http::baseUrl($this->baseUrl)
            ->withToken($this->internalToken)     // config('services.ai_layer.internal_token')
            ->withOptions(['verify' => config('services.ai_layer.ca_bundle')]) // mTLS verify-full
            ->timeout(3)
            ->post('/internal/events', $envelope->toArray())
            ->throw();
    }

    public function stream(AiInvocation $invocation): \Generator
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->internalToken)
            ->withOptions(['stream' => true, 'verify' => config('services.ai_layer.ca_bundle')])
            ->timeout(120)
            ->post('/internal/copilot/stream', $invocation->toArray());

        $body = $response->toPsrResponse()->getBody();
        while (! $body->eof()) {
            yield $body->read(1024); // relayed verbatim to the client SSE connection
        }
    }
}
```

| Class | Layer | Responsibility |
|---|---|---|
| `AiEngineClient` (interface) | Infrastructure | The sole transport to FastAPI; swappable for a fake in tests |
| `HttpAiEngineClient` | Infrastructure | Real HTTP + internal bearer + mTLS implementation |
| `AiProposalService` | Application | Receives `POST /api/v1/ai/proposals`, computes autonomy, routes to execute/suggest/approve |
| `AutonomyResolver` | Domain | Pure function computing `autonomy_applied` from the sensitive-op list, reversibility, confidence, thresholds |
| `AgentContextAssembler` | Application | Builds the RBAC-scoped context an agent may see for a given principal + subject |
| `AiInvocationDispatcher` | Application | Turns a trigger (event/schedule/user) into an `ai_tasks` row + engine call (sync, queued, or stream) |
| `CopilotService` | Application | Owns `ai_conversations`/`ai_messages`; drives the SSE relay and persists the final turn |
| `AiDecisionRepository` | Infrastructure | Reads/writes `ai_decisions`; enforces `company_id` scoping |
| `AiCostGovernor` | Domain | Per-company token/spend budget checks and the Redis rate limiter for `ai.*` route classes |
| `AiChannelBroadcaster` | Application | Broadcasts `ai.*` events onto `private-company.{id}.ai` |
| `RelayDomainEventToAiListener` | Application (queued) | The `events-ai` queue listener that calls `AiEngineClient::notifyEvent` after commit |

`AutonomyResolver` is deliberately a standalone, side-effect-free class so it is provable in isolation
and identical for all fifteen agents — this is the mechanism that guarantees "Payroll Manager cannot
have a laxer autonomy rule than General Accountant for equivalent risk":

```php
<?php

namespace App\Services\Ai;

final class AutonomyResolver
{
    /** @return 'auto'|'suggest_only'|'requires_approval' */
    public function resolve(AiDecisionDraft $d, AgentSettings $settings): string
    {
        if ($this->isSensitiveOperation($d->decisionType, $d->subjectType)) {
            return 'requires_approval';                 // confidence is irrelevant
        }
        if ($d->reversibility === 'irreversible') {
            return 'requires_approval';
        }
        if ($d->confidence >= $settings->autoPostConfidence
            && $d->expectedImpactAmount <= $settings->autoPostMaxAmount) {
            return 'auto';
        }
        return 'suggest_only';
    }

    private function isSensitiveOperation(string $type, ?string $subject): bool
    {
        // The fixed, platform-wide list — no company config can remove an entry.
        return in_array($type, config('ai.sensitive_operations'), true);
    }
}
```

# Endpoints Backed (/api/v1)

All endpoints follow the platform envelope (`{success,data,message,errors,meta,request_id,timestamp}`),
require `X-Company-Id`, and enforce `<area>.<action>` RBAC per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md). Two classes exist: **human-facing** (`api.qayd.app`,
Sanctum/JWT bearer, browser or mobile) and the single **service-principal** callback the FastAPI engine
uses (`ai_agent`-scoped Sanctum token + mTLS, per [INTERNAL_API](../api/INTERNAL_API.md)).

| Method | Path | Permission | Caller | Description |
|---|---|---|---|---|
| `POST` | `/api/v1/ai/chat` | `ai.chat` | Human (SSE) | Opens/continues a Copilot turn; relays the engine's stream back as `text/event-stream` |
| `GET` | `/api/v1/ai/conversations` | `ai.chat` | Human | List the caller's own `ai_conversations` (cursor) |
| `GET` | `/api/v1/ai/conversations/{id}/messages` | `ai.chat` | Human | Thread history |
| `POST` | `/api/v1/ai/proposals` | `ai_agent` service scope | **FastAPI → Laravel** | The sole write path from the engine; creates/updates an `ai_decisions` row and routes it |
| `GET` | `/api/v1/ai/decisions` | `ai.analyze` | Human | List decisions (filter by `status`, `agent_code`, `decision_type`) |
| `GET` | `/api/v1/ai/decisions/{id}` | `ai.analyze` | Human | One decision with full `reasoning`/`sources` |
| `POST` | `/api/v1/ai/decisions/{id}/accept` | `ai.approve` (or the action's own key) | Human | Accept a `suggest_only` draft; executes the `proposed_action` |
| `POST` | `/api/v1/ai/decisions/{id}/reject` | `ai.approve` | Human | Decline a `suggest_only` draft (records an `ai_corrections` row) |
| `GET` | `/api/v1/ai/agents` | `ai.analyze` | Human | The platform catalogue merged with this company's `ai_agent_settings` |
| `PATCH` | `/api/v1/ai/agents/{code}/settings` | `ai.settings.update` | Human (approval-gated) | Tune `enabled`/`autonomy_override`/`thresholds`; files an `approval_requests` row |
| `POST` | `/api/v1/ai/risks/{id}/acknowledge` | `ai.analyze` | Human | Acknowledge a risk flag (no elevated permission) |
| `GET` | `/api/v1/ai/knowledge/search` | `reports.read` | Human | Search `ai_decisions`/risk narratives (see [SEARCH_SERVICE.md](SEARCH_SERVICE.md)) |
| `GET` | `/api/v1/ai/logs` | `audit.read` | Human/Auditor | Read `ai_logs` for a decision — the "exactly how did it get here" trail |

The `POST /api/v1/ai/proposals` request body is the engine's `proposed_action` wrapped with its
decision metadata. It is validated by `StoreAiProposalRequest` exactly as any other write:

```json
POST /api/v1/ai/proposals
Authorization: Bearer <ai_agent service token>
X-Company-Id: 4021
X-Request-Id: 6f19c2f0-2a41-4e2b-9b7e-2b9a6a2b9d41

{
  "agent_code": "general_accountant",
  "task_id": 99120,
  "decision_type": "journal_entry.draft",
  "decision_class": "operational",
  "confidence": "0.9400",
  "reversibility": "reversible",
  "expected_impact": { "financial_amount": "186.5000", "currency_code": "KWD" },
  "reasoning": "Vendor 'Gulf Prime Distribution Co.' matched against 47 prior bills posted to 5130 with 100% consistency over 12 months. Extracted total matches goods_receipt #GR-2291.",
  "sources": [
    { "type": "attachment", "id": 88123, "label": "bill_scan_2291.pdf", "page": 1 },
    { "type": "bill", "id": 5521 },
    { "type": "historical_pattern", "sample_size": 47, "consistency": 1.0, "window_months": 12 }
  ],
  "proposed_action": {
    "type": "journal_entry.create",
    "endpoint": "POST /api/v1/accounting/journal-entries",
    "permission_required": "accounting.journal.create",
    "payload": {
      "entry_date": "2026-07-14",
      "source_type": "bill", "source_id": 5521,
      "currency_code": "KWD",
      "lines": [
        { "account_code": "5130", "debit": "186.5000", "credit": "0.0000", "cost_center_id": 12 },
        { "account_code": "2110", "debit": "0.0000",   "credit": "186.5000" }
      ]
    }
  }
}
```

Response `201` returns the created `ai_decisions` row with its computed `autonomy_applied` and resulting
`status`; when `auto`, `executed_reference_type`/`executed_reference_id` point at the posted
`journal_entries` row. When `requires_approval`, `data.approval_request_id` is populated and `status` is
`pending_approval` — the engine's proposal has NOT executed and will not until the chain resolves.

# Database Tables Owned

The AI Service is the Laravel-side owner (reader/writer through Eloquent + Repository) of the AI tables,
whose DDL is authoritative in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md). Ownership here means: this
service is responsible for their write discipline, their RLS scoping in the app layer, and their audit
coverage — not for re-declaring their columns.

| Table | Owned write path | Notes |
|---|---|---|
| `ai_agents` | Seeded once, versioned like code | Platform-wide, no `company_id`, no RLS — the sole AI table exempt |
| `ai_agent_settings` | `PATCH /ai/agents/{code}/settings` (approval-gated) | Per-company tuning; unique `(company_id, agent_code)` |
| `ai_tasks` | `AiInvocationDispatcher` on every engine call | The unit of work; `latency_ms` populated on completion |
| `ai_decisions` | `AiProposalService` | The central proposal object and its status machine |
| `ai_conversations` / `ai_messages` | `CopilotService` | Thread + turns; `tool_calls`/`citations` JSONB |
| `ai_memory` | Written on correction/config/conversation capture | RLS `company_id = current_setting('app.current_company_id')`; `ivfflat` vector index |
| `ai_corrections` | On accept-with-edit / reject | Feeds the Learning Loop; never hard-deleted |
| `ai_logs` | `AiEngineClient` + orchestrator callbacks | Append-only, partitioned monthly; full explainability |
| `ai_recommendations` | CFO-agent proposals of `decision_type='recommendation'` | Always `suggest_only`; ranked, deduped |
| `approval_requests` / `approval_request_approvers` | Written when a decision is `requires_approval` | Operationally owned by [WORKFLOW_ENGINE.md](WORKFLOW_ENGINE.md) |

Every one of these carries `company_id BIGINT NOT NULL` and is protected by the identical RLS policy that
protects `journal_entries` — a boundary that protected the ledger but not `ai_memory` would leave the
exact channel most able to leak Company A's data into Company B's agent context unguarded.

# Multi-Tenancy Enforcement

Tenancy is enforced in three concentric rings, and the AI engine sits *outside* all three by design.

1. **The header ring.** Every human-facing `ai.*` request carries `X-Company-Id`; the `EnsureCompanyScope`
   middleware verifies the authenticated user has a `company_users` row for it (else `403`) and sets the
   Postgres session variable `app.current_company_id` for the request's DB connection. A `company_id`
   inside any JSON body is ignored for scoping (or must match the header, else `422`).

2. **The RLS ring.** Every AI table has a Row Level Security policy scoping reads and writes to
   `current_setting('app.current_company_id')::bigint`. The FastAPI engine's retrieval never queries
   Postgres directly — it retrieves *through* Laravel tools that carry the same session scope — so
   `ai_memory` vector search is physically incapable of returning another tenant's embeddings.

3. **The credential ring.** The FastAPI process holds no tenant database connection string at all. Its
   only reach into data is the `ai_agent`-scoped Sanctum bearer to `POST /api/v1/ai/proposals`, and its
   only push of triggers is `POST /internal/events`. A bug or refactor cannot turn "the AI should not
   write to the DB" into a violation, because there is no driver for it to violate through.

The proposal gateway is where tenancy is most load-bearing. When FastAPI calls back, `X-Company-Id`
identifies the tenant, and `AiProposalService` re-establishes the same scope before invoking the owning
module's Service. The proposal's `proposed_action.payload` is treated as untrusted: any `company_id`,
`branch_id`, or foreign key it names is validated to belong to the header's company, and a mismatch is a
`403 COMPANY_MISMATCH` — logged as a `system` audit event because a cross-tenant proposal is a security
signal, not a bookkeeping error. The engine authenticates as the *initiating user's* scoped context for
Copilot work (so an agent can never surface data the asking human cannot see) and as a narrowly-scoped
service token only for scheduled/event-triggered autonomous work.

# Events, Queues & Realtime

**Inbound triggers → engine.** Domain events that should wake an agent (`document.uploaded`,
`bank.synced`, `invoice.overdue`, `journal.posted`, schedule ticks) are relayed to FastAPI by a queued
listener on the dedicated `events-ai` queue. The listener binds `afterCommit()` so the engine is never
told about a change that rolled back:

```php
<?php

namespace App\Listeners\Ai;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RelayDomainEventToAiListener implements ShouldQueue
{
    public $queue = 'events-ai';
    public $afterCommit = true;

    public function __construct(private readonly AiEngineClient $engine) {}

    public function handle(object $event): void
    {
        $this->engine->notifyEvent(AiEventEnvelope::fromDomainEvent($event));
        // Fire-and-forget: a dropped notify is recovered by the events-ai retry
        // and the /api/v1/ai/proposals/{id}/retry-notify path (INTERNAL_API).
    }
}
```

**Async agent work → queue.** Long-running agent tasks (a full month-end close check, a batch
reconciliation pass, a large report narrative) are enqueued as Laravel Jobs on the `ai` queue, each
writing an `ai_tasks` row and returning `202 Accepted` with a task reference the client polls or awaits
via realtime. Heavy work never blocks an HTTP request.

**Streaming → SSE relay.** `POST /api/v1/ai/chat` is synchronous but streamed: `CopilotService` opens a
`\Symfony\Component\HttpFoundation\StreamedResponse` (`text/event-stream`), consumes the generator from
`AiEngineClient::stream()`, and forwards each frame to the browser as it arrives. The browser holds one
SSE connection to Laravel; Laravel holds one streaming connection to FastAPI. The final assembled turn is
persisted to `ai_messages` (with `confidence`, `citations`, and any `decision_id`) after the stream
closes.

**Outbound state → Reverb.** Agent progress and decisions broadcast on the private channel
`private-company.{id}.ai`:

```php
broadcast(new AiDecisionProposed($decision))->toOthers();   // private-company.{id}.ai
broadcast(new AiTaskStatusChanged($task));                  // running → completed → escalated
```

Approval-shaped decisions additionally raise the `approval.requested` domain event, which fans out to
`private-company.{id}.notifications.{userId}` via [NOTIFICATION_SERVICE.md](NOTIFICATION_SERVICE.md), and
dashboard-affecting outputs (health score, forecast alert) broadcast on `private-company.{id}.dashboard`.
The AI Service raises these events; the owning services consume them.

# Integrations

- **FastAPI AI engine** — the primary integration. Two hops, both over the private VPC with mTLS: Laravel
  → FastAPI (`POST /internal/events`, `POST /internal/copilot/stream`, `/internal/invoke`) authenticated
  with the shared `internal_token` (`hmac.compare_digest` on the Python side, never `==`); FastAPI →
  Laravel (`POST /api/v1/ai/proposals`) authenticated with the `ai_agent` Sanctum service account. Health
  is probed via `/internal/readyz`, which reports `not_ready` when the engine's model-provider egress is
  unreachable — this drives degraded mode (see Error Handling).
- **Model providers** — OpenAI (primary), Anthropic Claude (secondary), future local LLM — are reached
  *only by FastAPI*, never by Laravel. The AI Service has no model API keys and issues no model calls; it
  only reads `ai_logs.tokens_input`/`tokens_output` that the engine reports back, for cost governance.
- **Redis** — the `ai` and `events-ai` queues, the per-company cost/rate counters, and the engine's
  short-term working-memory cache (written by FastAPI, not this service).
- **The owning business modules** — Accounting, Sales, Purchasing, Banking, Payroll, Tax — whose Services
  the proposal gateway invokes to actually execute a `proposed_action`. The AI Service never bypasses
  them with a direct model write.
- **Cloudflare R2** — source documents that every `sources` citation ultimately resolves to, reached via
  signed URLs from [FILE_SERVICE.md](FILE_SERVICE.md), never a public path.

# Permissions

The `ai.*` category from [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), plus the
per-action keys a proposal's `proposed_action` demands.

| Permission | Grants |
|---|---|
| `ai.chat` | Open the Copilot, read one's own conversations |
| `ai.generate` | Trigger generative tasks (narratives, drafts) |
| `ai.analyze` | Read decisions, agent catalogue, acknowledge risks |
| `ai.approve` | Accept/reject a `suggest_only` AI draft, approve an `ai_recommendation` |
| `ai.automation` | Configure automations that let agents act on a schedule |
| `ai.settings.update` | Tune `ai_agent_settings` — **always approval-gated**, on the fixed sensitive-operations list |

Two invariants govern how permissions interact with the AI:

1. **The AI never exceeds the human's grant.** An agent invoked in a user's context receives only the
   data that user's permissions permit — if a user cannot read payroll, the Copilot cannot reveal
   payroll, because the agent's tools carry the user's own scope. This is enforced in Laravel's RBAC
   layer, not the agent's restraint.
2. **A proposal's own permission still applies.** When the gateway executes a `proposed_action`, it
   checks the `permission_required` key (`accounting.journal.create`, `bank.transfer`, …) against the
   *acting principal* — the approving human for gated actions, the AI service account (granted only the
   narrow keys the company's policy allows) for autonomous ones. Default-deny throughout: a proposal for
   an action the principal lacks the key for is `403`, and the decision is marked `denied`.

Widening an agent's autonomy ceiling (`auto_post_confidence` down, `auto_post_max_amount` up) is
`ai.settings.update`, which routes through the approval engine — a company cannot silently make its AI
bolder without a human sign-off recorded in the audit trail.

# Error Handling

The AI Service degrades along one clean axis: **is the AI the whole point of this request, or an
enhancement to it?**

- **AI-only endpoints** (`/ai/chat`, an OCR extraction, a forecast) return `503 Service Unavailable` with
  `Retry-After` when the engine's `/internal/readyz` fails or a call times out — never a hung request
  waiting on a gateway timeout. The envelope's `message` states the engine is temporarily unreachable and
  the `request_id` is the correlation key.
- **AI-optional endpoints** (a categorization *suggestion* on a manual expense, a next-best-account hint)
  degrade gracefully: they return `200` with `meta.ai_suggestion: null` and a note in `message`, and the
  human proceeds unblocked. The absence of a suggestion never blocks a financial write.

Other cases:

| Condition | Response | Notes |
|---|---|---|
| Engine timeout on a proposal callback | The proposal is retried via `retry-notify`; no partial write | The write pipeline is transactional — a timeout after commit is reconciled, never doubled |
| Proposal validation fails (`422`) | `ai_decisions.status = 'rejected'`, reason recorded | The engine's payload failed the same FormRequest a human would; the agent is told why via the response |
| Proposal for a sensitive op with `auto` requested | Ignored — forced to `requires_approval` | `AutonomyResolver` overrides the engine; confidence cannot buy autonomy on the fixed list |
| Cross-tenant payload | `403 COMPANY_MISMATCH` + `system` audit event | A security signal, escalated, not a routine rejection |
| Cost/rate budget exceeded | `429 Too Many Requests` + `Retry-After` | Per-company `AiCostGovernor`; protects the tenant from a runaway agent |
| Duplicate proposal (retry with same `Idempotency-Key`) | Original `ai_decisions` row returned verbatim | Network retries never double-post; keyed on `(company_id, endpoint, idempotency_key)` |
| Engine returns low per-field confidence (e.g. cropped OCR field) | Field flagged, not guessed; surfaced to the human digest | Confidence gates apply per-field even when the overall decision clears its bar |

Every error path writes an `ai_logs` row (`event_type` = `api_response` with the status), so an engineer
or auditor can always reconstruct not just what the AI decided but exactly how a failure unfolded.

Cost/rate governance is a first-class error surface, not an afterthought: `AiCostGovernor` enforces a
per-company monthly token/spend budget and a Redis-backed rate limit on the `ai.*` route classes (with
tighter limits on the billable `ai.documents.extract` class). A company approaching its budget is warned
via a `system` notification before hard-limiting; a hard limit degrades AI-optional features first and
AI-only features last, so the ledger keeps working even when the intelligence is throttled.

# Testing

Testing the AI Service means testing the *boundary*, never the model. The engine is stubbed via a fake
`AiEngineClient`; the tests assert Laravel's governance, not the LLM's answer.

- **Autonomy resolution (unit, exhaustive).** `AutonomyResolver` is tested as a pure function across the
  full truth table: every sensitive operation forced to `requires_approval` regardless of confidence;
  every `irreversible` decision forced to `requires_approval`; the confidence-and-ceiling gate for
  `auto`; the `suggest_only` fallback. This is the single most-covered class in the module — a regression
  here is a governance failure, so it is asserted per-agent to prove no agent gets a laxer path.
- **Proposal gateway (feature).** `POST /api/v1/ai/proposals` with a valid journal-entry proposal under a
  high-confidence, in-ceiling, reversible operation → asserts the `journal_entries` row was written by the
  owning Service (not a raw insert), `ai_decisions.status = 'auto_executed'`, and an `audit_logs` row with
  `ai_assisted = true`. The same proposal above the amount ceiling → asserts `suggest_only` and no write.
  A `bank.transfer` proposal at 0.99 confidence → asserts `requires_approval`, an `approval_requests` row,
  and no funds moved.
- **Tenant isolation (feature).** A proposal whose payload references another company's `account_id`
  under `X-Company-Id: A` → asserts `403 COMPANY_MISMATCH` and a `system` audit event. A Copilot query by
  a user without `payroll.read` → asserts payroll data never appears in the assembled context.
- **Degraded mode (feature).** With the fake engine set to unreachable: `/ai/chat` returns `503 +
  Retry-After`; an AI-optional categorization endpoint returns `200` with `meta.ai_suggestion: null` and
  the underlying write still succeeds.
- **Idempotency (feature).** Two identical proposal callbacks with the same `Idempotency-Key` produce one
  `ai_decisions` row and one business write.
- **Cost governance (feature).** Exceeding the per-company budget returns `429`; AI-optional features
  degrade before AI-only ones.
- **SSE relay (feature).** The fake engine yields three frames; the test asserts the SSE response streams
  all three and the final `ai_messages` row is persisted with citations after the stream closes.
- **Contract tests (Pest + schema).** The `POST /api/v1/ai/proposals` request/response is validated
  against its JSON Schema so the FastAPI engine and Laravel cannot drift — a shared fixture lives in the
  repo and both sides test against it.

Tooling per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md): Pest/PHPUnit for the Laravel side,
PHPStan + Pint in CI. The Python engine's own agent-quality evals live in the FastAPI repo and are out of
scope here — this module's tests prove the boundary is safe, not that the model is smart.

# End of Document
