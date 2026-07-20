# Audit Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: AUDIT_SERVICE
---

# Purpose

The Audit Service is the backend service that makes every consequential change in QAYD provable after
the fact: who did it, when, from where, on what device, why, and what the data looked like before and
after. It is the application-layer half of the platform's audit trail — the Services, Actions,
observers, and jobs that write to the immutable `audit_logs` ledger, plus the read/export surface an
auditor uses to interrogate it. Where [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)
specifies the `audit_logs` table, its partitioning, its hash-chain triggers, and its
tamper-evidence at the storage layer, and [../security/AUDIT_LOGS.md](../security/AUDIT_LOGS.md)
frames the security and compliance obligations, this document specifies the *backend service* that
sits above them: how a mutation becomes an audit row inside the same transaction that made it, what
the Audit Service guarantees to callers, and how the trail is read and exported under RBAC without
ever being writable.

The guiding principle is inherited verbatim from the database layer and is non-negotiable at the
service layer too: **audit logging is not optional instrumentation bolted on later — it is a
first-class write path. A mutation that is not audited did not happen, from a compliance standpoint.**
The Audit Service exists so that no module has to reimplement this: a module emits its state change,
and the trail is written for it, immutably, inside the same commit boundary, with full actor and
request context attached.

QAYD is an AI Financial Operating System, and its audit trail carries a burden most accounting
systems do not: it must distinguish a purely human change from an AI-assisted one, attribute every
AI-proposed write to the accountable human who approved it, and record every approval, rejection,
export, and cross-tenant-denied attempt with the same rigor as a posted journal entry. This document
specifies exactly what is captured for each of those, how, and how it is read back.

# Responsibilities

The Audit Service owns, and is the only component that owns, the following:

1. **Capture.** Turn every audited mutation — financial and master-data writes, auth events,
   permission changes, AI approvals/rejections, exports, and cross-tenant-denied attempts — into an
   `audit_logs` row with the complete required payload, inside the same transaction as the change it
   records.
2. **Enrichment.** Attach the actor (human, or named AI service plus approving human), the
   `request_id` correlation, IP/user-agent/device, the before/after snapshot or narrowed diff, the
   `reason` where the action requires one, and the AI metadata (`ai_assisted`, `ai_confidence`,
   agent, source ids) where relevant.
3. **Masking.** Redact sensitive fields at write time so plaintext salaries, IBANs, and national IDs
   never enter the trail, while preserving change-detection via stable per-value tokens.
4. **Integrity guarantee.** Ensure the row is written durably even under Redis unavailability (the
   outbox path) and only if the business transaction committed (the `afterCommit` path), and never
   permit an update or delete of an audit row.
5. **Read & reconstruction.** Serve the trail to authorized readers, reconstruct a row's state at any
   past timestamp, and answer the standard investigative questions (who changed this, who touched
   permissions, what did AI originate).
6. **Export.** Produce compliance-grade, hash-chain-verified exports for external auditors and
   tax-authority requests, honoring legal holds and retention.
7. **RBAC on reading.** Enforce that reading the trail requires an explicit audit permission and that
   no reader — including the Auditor role — can ever obtain write access to `audit_logs`.

What it does not own: the physical storage, partitioning, and hash-chain triggers (the database layer
owns those); the retention *policy values* (a company configures them); and the business meaning of
any audited change (the owning module owns that).

# Domain Model

The service is deliberately thin over one durable structure. Three concepts matter at the service
layer:

- **Audit event** — the in-flight fact a module produces (a mutation, an auth event, an AI decision),
  before it becomes a row. It carries the actor context, the diff, and the action name.
- **Audit log row** (`audit_logs`) — the immutable, append-only, hash-chained record, owned by the
  database layer and specified in [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md).
  The service writes it and reads it; it never updates or deletes it.
- **Audit outbox row** (`audit_outbox`) — the same-transaction staging record used for the highest-
  stakes actions so the audit row is guaranteed-eventually-written even if the async path is briefly
  unavailable, drained into `audit_logs` by a poller.

```
module state change (inside DB::transaction)
        │  emits an audit event
        ▼
  AuditService::record()
        ├─ high-stakes ─▶ audit_outbox (same transaction) ─▶ drain poller ─▶ audit_logs
        └─ routine ─────▶ AuditLogListener (afterCommit, queue 'audit') ─▶ audit_logs
                                                                              │  BEFORE INSERT trigger
                                                                              ▼
                                                                     hash chain (per company)
```

The service reads back through the same `audit_logs` table (RLS-scoped) for every query,
reconstruction, and export. `audit_logs` is always the dependent, never a dependency — nothing in the
hot financial write path ever joins *into* it.

# Key Classes

## Services

`App\Services\Audit\AuditService` — the façade every module calls (usually indirectly, via the
`Auditable` trait; sometimes directly, for rich state-transition events that are not plain CRUD). It
composes the actor context, applies masking, and routes the write to the outbox or async path
depending on the action's stakes.

```php
namespace App\Services\Audit;

use App\Enums\Audit\AuditCategory;
use App\Models\Audit\AuditLog;
use App\Support\Audit\ActorContext;
use App\Support\Audit\FieldMasker;

final class AuditService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly FieldMasker $masker,
        private readonly AuditOutbox $outbox,
    ) {}

    /**
     * Record one audited event. Called inside the business transaction. High-stakes actions
     * go through the outbox (same ACID boundary); routine ones queue afterCommit.
     */
    public function record(
        string $action,                 // dot-namespaced, e.g. 'journal_entry.posted'
        AuditCategory $category,        // data_mutation | auth | permission | ai_action | system
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        array $aiMeta = [],             // {ai_assisted, ai_confidence, agent, source_ids}
    ): void {
        $payload = [
            'company_id'     => $this->actor->companyId(),
            'branch_id'      => $this->actor->branchId(),
            'category'       => $category->value,
            'action'         => $action,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'actor_user_id'  => $this->actor->userId(),          // the accountable human (or approver)
            'actor_service'  => $this->actor->serviceName(),      // e.g. 'ai:reporting-agent', 'scheduler:...'
            'acting_as_user_id' => $this->actor->impersonatedUserId(),
            'old_values'     => $old ? $this->masker->mask($entityType, $old) : null,
            'new_values'     => $this->composeNewValues($entityType, $new, $aiMeta),
            'changed_fields' => $new ? array_keys($new) : null,
            'reason'         => $reason,
            'request_id'     => $this->actor->requestId(),
            'ip_address'     => $this->actor->ip(),
            'user_agent'     => $this->actor->userAgent(),
            'device_id'      => $this->actor->deviceId(),
        ];

        if ($this->isHighStakes($action, $category)) {
            $this->outbox->stage($payload);      // INSERT into audit_outbox in THIS transaction
        } else {
            AuditLog::queueAfterCommit($payload); // ShouldQueue listener on the 'audit' queue
        }
    }

    private function composeNewValues(?string $entityType, ?array $new, array $aiMeta): ?array
    {
        $masked = $new ? $this->masker->mask($entityType, $new) : [];
        // For an AI-assisted business mutation, the trail carries ai_assisted/ai_confidence
        // so a reader can distinguish AI-originated changes from purely human ones.
        return array_merge($masked, array_filter([
            'ai_assisted'   => $aiMeta['ai_assisted']   ?? null,
            'ai_confidence' => $aiMeta['ai_confidence'] ?? null,
            'ai_agent'      => $aiMeta['agent']         ?? null,
            'ai_source_ids' => $aiMeta['source_ids']    ?? null,
        ]));
    }
}
```

`App\Services\Audit\AuditReader` — the read/reconstruction service. It serves the RBAC-gated queries
(who changed this entity, everyone who touched permissions, AI-originated changes this week) and the
point-in-time reconstruction fold, always with a `created_at` bound so the partition planner prunes
([../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) `# Performance`).

```php
namespace App\Services\Audit;

use App\Models\Audit\AuditLog;
use Carbon\Carbon;

final class AuditReader
{
    /** Reconstruct an entity's state as of a timestamp by folding diffs in commit order. */
    public function reconstruct(string $entityType, int $entityId, Carbon $asOf): array
    {
        $state = [];
        AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('created_at', '<=', $asOf)
            ->orderBy('created_at')->orderBy('id')
            ->each(function (AuditLog $log) use (&$state) {
                $state = array_merge($state, $log->new_values ?? []);
            });
        return $state;   // masked fields remain masked — the trail never re-exposes plaintext
    }
}
```

`App\Services\Audit\AuditExporter` — produces a signed, hash-chain-verified export (CSV/JSON/Parquet)
for a company and window, refusing to proceed if a legal hold or open tax audit would be violated, and
attaching the per-company chain-verification report so an external auditor can independently recompute
integrity without trusting the application.

## Actions

- `App\Actions\Audit\ExportAuditTrailAction` — validates the request window and permission, runs
  `AuditExporter`, stores the artifact to R2, and — because an export is itself an audited event —
  records `audit.exported` with the window, row count, and requester.
- `App\Actions\Audit\VerifyChainAction` — recomputes a company's hash chain and returns a
  pass/fail report; invoked on demand and by the nightly job.
- `App\Actions\Audit\RecordPiiErasureAction` — for a legally-compelled erasure, masks the business
  row's PII in place and writes an `*.pii_erased` audit row citing the legal basis, leaving prior
  audit rows untouched (the chain guarantees they cannot be edited).

## Models

- `App\Models\Audit\AuditLog` — read/insert only; the model has no `update`/`delete` path and its
  table privileges are revoked at the database ([../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)
  `# Immutability & Tamper-Evidence`).
- `App\Models\Audit\AuditOutboxEntry` — the same-transaction staging row.

## Jobs

- `App\Jobs\Audit\DrainAuditOutbox` (scheduled every ~2 seconds) — moves staged rows from
  `audit_outbox` into `audit_logs`.
- `App\Jobs\Audit\AuditLogListener` (queue `audit`, `ShouldQueue`, `afterCommit`) — the async path
  for routine events; fires only after the business transaction commits, so a rolled-back change
  never produces an orphan audit row.
- `App\Jobs\Audit\ReconcileTriggerShadow` (hourly) — compares the database-trigger shadow table
  against `audit_logs` and raises `system.audit_gap_detected` for any bypass write with no app-layer
  row.
- `App\Jobs\Audit\VerifyChainNightly` — per-company chain verification; flags any row whose stored
  hash does not match its recomputation.

## Events

`AuditGapDetected`, `AuditChainVerificationFailed`, `AuditMaskingGapDetected`, `AuditExported` — each
broadcast to the security/compliance surface on `private-company.{id}.notifications`, and each itself
audited (a `system`-category row), so the audit system's own health is auditable.

## Policies

`App\Policies\Audit\AuditLogPolicy` — `viewAny`/`view` require `audit.read` (company-wide) or
`audit.read.own_company` (narrower); `export` requires `audit.export`; **no `create`, `update`, or
`delete` ability exists on this policy at all** — the trail is written only by the framework's own
capture path, never by a user action, and is never mutable by anyone.

# Endpoints Backed (/api/v1)

Every response uses the platform envelope `{success, data, message, errors, meta, request_id,
timestamp}`. The audit surface is read-and-export only — there is no write endpoint.

| Method & path | Purpose | Permission | Success |
|---|---|---|---|
| `GET /api/v1/audit/logs` | Query the trail (filter by `category`, `action`, `entity_type`, `entity_id`, `actor_user_id`, date range — a `created_at` bound is required). | `audit.read` or `audit.read.own_company` | 200 |
| `GET /api/v1/audit/logs/{id}` | One audit row with full (masked) old/new values and correlation ids. | `audit.read` | 200 |
| `GET /api/v1/audit/entities/{type}/{id}/history` | The ordered change history of one business entity. | `audit.read` | 200 |
| `GET /api/v1/audit/entities/{type}/{id}/reconstruct?as_of=…` | Point-in-time reconstructed state. | `audit.read` | 200 |
| `GET /api/v1/audit/permissions-activity` | Every permission/role change in a window (highest-priority review surface). | `audit.read` | 200 |
| `GET /api/v1/audit/ai-activity` | AI-originated/assisted changes in a window, with confidence and approving human. | `audit.read` | 200 |
| `POST /api/v1/audit/export` | Produce a signed, chain-verified export for a window. | `audit.export` | 202 (async) |
| `GET /api/v1/audit/chain-verification` | The per-company hash-chain verification report. | `audit.read` | 200 |

A query response is a normal paginated envelope; note the required date bound in `meta`:

```json
{
  "success": true,
  "data": [
    {
      "id": 90231,
      "category": "ai_action",
      "action": "journal_entry.posted",
      "entity_type": "journal_entries",
      "entity_id": 55120,
      "actor_user_id": 317,
      "actor_service": null,
      "new_values": { "status": "posted", "ai_assisted": true, "ai_confidence": 0.91, "ai_agent": "GENERAL_ACCOUNTANT" },
      "reason": null,
      "request_id": "0f3c…",
      "created_at": "2026-07-20T09:14:03Z"
    }
  ],
  "message": null,
  "errors": [],
  "meta": { "page": 1, "per_page": 50, "date_from": "2026-07-01", "date_to": "2026-07-20" },
  "request_id": "aa19…",
  "timestamp": "2026-07-20T11:00:00Z"
}
```

# Database Tables Owned

The Audit Service does not *define* the audit tables — their authoritative DDL, partitioning,
indexes, and integrity triggers live in
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md). It *owns the write and read
path* to them at the service layer. For completeness, the tables it depends on and the shape it writes
are:

- **`audit_logs`** — the immutable, range-partitioned, per-company hash-chained ledger. Append-only:
  `UPDATE`/`DELETE` are revoked from every application-facing role and a `BEFORE UPDATE OR DELETE`
  trigger raises an exception even for a superuser connection. The service `INSERT`s and `SELECT`s
  only. The minimum payload the service always supplies is `company_id` (for a tenant event),
  `category`, `action`, `actor_user_id` or `actor_service`, and — for a mutation — `old_values`/
  `new_values`/`changed_fields`; `reason` is enforced NOT NULL by the service's own validation for the
  high-risk action allowlist (void, delete, permission change, journal reversal, payroll override).
- **`audit_outbox`** — the same-transaction staging table for high-stakes actions, drained into
  `audit_logs` by `DrainAuditOutbox`. Its purpose is to give posting a journal, voiding an invoice,
  and changing a permission a guaranteed audit row even if Redis is momentarily unavailable, inside
  the same ACID boundary as the business mutation.
- **`audit_logs_db_trigger_shadow`** — the defense-in-depth table the database trigger writes to when
  a high-risk row is mutated outside Eloquent; `ReconcileTriggerShadow` matches it against the
  app-layer rows and raises a gap alert for any bypass write.

The one field the service is responsible for that the database layer cannot compute is actor
identity: the database has no notion of an HTTP actor, so the service supplies `actor_user_id` (the
accountable human — for an AI-approved write, the *approving* human, never a synthetic AI id),
`actor_service` (for autonomous low-risk system actions, e.g. `ai:reporting-agent` or
`scheduler:period-close`), and `acting_as_user_id` (during impersonation, both the real support actor
and the assumed user are recorded).

# What Is Audited

The service captures four categories, matching the database specification, each with a minimum
payload it never writes less than:

1. **Financial and master-data mutations** — every `INSERT`/`UPDATE`/soft-`DELETE` on a
   tenant-scoped business table (accounting, sales/AR, purchasing/AP, banking, inventory, payroll,
   tax, and master data). A *state transition* is always audited even when a naive diff would show
   only `updated_at` (e.g. `journal_entries.status: draft → posted`). Bulk operations write **one**
   row per logical batch (a 5,000-row stock count finalization is one `stock_count.finalized` row),
   not one per row.
2. **Authentication and session events** — `user.login_success`/`login_failed`/`logout`,
   `password_changed`, `mfa_enabled`/`mfa_challenge_failed`, `session_revoked`,
   `impersonation_started` (always with both actor and subject ids).
3. **Permission and access-control changes** — any change to roles, permissions, role-permission
   links, company membership, Sanctum token issuance/revocation, and security-posture settings.
   These are the highest-priority rows for the Auditor role and the primary segregation-of-duties
   evidence.
4. **AI actions** — every write the AI layer causes, autonomous or human-approved, with
   `actor_user_id` set to the approving human (or the AI service user for fully autonomous low-risk
   actions), plus `new_values.ai_assisted = true`, `ai_confidence`, agent name, reasoning summary,
   and source document ids, and the downstream entity actually mutated. This is the trail that lets an
   auditor see exactly which changes originated with AI and who signed for them — and, joined with the
   Workflow Engine's decisions ([WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md)), that every AI proposal was
   approved or rejected by a human distinct from its initiator.

Two categories the Audit Service specifically captures at the backend layer beyond routine mutations:

- **Exports** — an `audit.exported` row for every trail export (window, row count, requester,
  artifact reference), so reading the trail is itself in the trail.
- **Cross-tenant-denied attempts** — when RLS or route-model binding resolves a cross-company access
  to `404`, the service records a `security.cross_tenant_denied` row (actor, attempted
  `entity_type`/`entity_id`, request_id) so a probing pattern is visible to the security team, without
  ever confirming to the caller that the target existed.

Explicitly **not** audited at row granularity: read-only operations (report views, dashboard loads),
full-text searches, and mechanical `ledger_entries` projection rows whose causing `journal_lines`
post is already audited — the cause is captured, the derivable effect is not, to avoid doubling audit
volume.

# Multi-Tenancy Enforcement

The audit trail is company-partitioned and read-isolated exactly like every tenant table, with the
sole, deliberate exception that platform-level events (a super-admin login before selecting a company)
may carry a NULL `company_id`:

- **Write scope.** Every tenant mutation's audit row carries the mutating row's `company_id`, sourced
  from the actor context bound by `ResolveTenantCompany`; a mutation with no company context is a
  platform event and is the only case allowed a NULL `company_id`.
- **Read isolation via RLS.** `audit_logs` has row-level security enabled; the policy admits a row
  only when `company_id = current_setting('app.current_company_id')` or the reader is a platform
  admin. An Auditor in company A physically cannot read company B's trail
  ([../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md)).
- **Hash chains are per company.** Each company's rows form an independently verifiable chain, so a
  chain verification or an export is a single-tenant operation and partition pruning still works
  ([../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) `# Immutability &
  Tamper-Evidence`).
- **Exports are single-tenant.** `AuditExporter` scopes to the active company; there is no
  cross-tenant export path, and a platform-admin cross-tenant read is itself audited.
- **The cross-tenant-denied capture is the audit trail watching the tenant boundary hold** — it
  records the attempt on the *attempting* actor's own company context, never leaking the target
  company's existence.

# Events, Queues & Realtime

- **Consumed:** the Audit Service subscribes to no business events for *capture* — capture is
  synchronous with the mutation via the `Auditable` trait and the outbox, not an after-the-fact
  reaction, precisely so a mutation and its audit row share a commit boundary. It does consume its own
  scheduler ticks for verification, drain, and reconciliation.
- **Produced:** `AuditGapDetected`, `AuditChainVerificationFailed`, `AuditMaskingGapDetected`, and
  `AuditExported`, broadcast on `private-company.{id}.notifications` to the security/compliance
  surface. These are themselves audited (`system` category), so the audit system's health is part of
  the record.
- **Queues:** a dedicated `audit` Redis queue carries the async `AuditLogListener` so the HTTP
  response is never blocked on an audit insert; the listener's `afterCommit()` binding guarantees it
  fires only if the business transaction committed. The outbox path bypasses Redis entirely for
  high-stakes actions (same-transaction INSERT, drained every ~2s), so a brief Redis outage cannot
  drop a journal-post or permission-change audit row.
- **Correlation:** every audit row carries the `request_id` from the API envelope, so one id threads
  a change across the request, its queued jobs, its AI-engine calls, and its audit row — the same
  end-to-end correlation model in [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) `#
  Observability`.

# Integrations

- **Every mutating module.** Accounting, Sales, Purchasing, Banking, Inventory, Payroll, and Tax all
  audit through the `Auditable` trait (routine CRUD) and direct `AuditService::record()` calls (rich
  state transitions like `journal_entry.posted`). The service is the shared write path so no module
  reimplements capture.
- **Workflow Engine.** Every approval decision writes an audit row inside the deciding transaction;
  the audit trail plus the Workflow Engine's `approval_request_actions` together are the
  segregation-of-duties evidence that maker ≠ checker ([WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md)).
- **Automation Engine.** Every automated effect, rule/version change, and autonomy-matrix change is
  audited — an AI-suggested rule and a change from `requires_approval` to `auto` are among the higher-
  priority rows an auditor reviews ([AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md)).
- **AI engine (FastAPI).** The AI engine never writes the database and therefore never writes
  `audit_logs` directly; the audit row for an AI-originated change is written by the backend when the
  resulting business mutation commits, carrying `ai_assisted`/`ai_confidence`/agent/source-ids
  ([BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) `# The AI-Engine Boundary`,
  [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md)).
- **Database layer.** The service depends on the storage, partitioning, hash-chain triggers, masking
  registry, and trigger-shadow reconciliation specified in
  [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) and the security framing in
  [../security/AUDIT_LOGS.md](../security/AUDIT_LOGS.md) and
  [../security/SECURITY_ARCHITECTURE.md](../security/SECURITY_ARCHITECTURE.md).
- **Object storage (R2).** Exports and over-large single-row diffs are stored in R2 (the latter via
  the polymorphic `attachments` table, keeping the hot audit row small while remaining fully
  reconstructable).

# Permissions

Reading the trail is a privilege in the platform grammar `<area>.<action>`
([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)), and — the defining
property of this service — **no permission grants write access to `audit_logs`.**

| Permission | Grants |
|---|---|
| `audit.read` | Read the whole company's trail; run entity history, reconstruction, permission-activity, and AI-activity queries. |
| `audit.read.own_company` | The same, narrowed — the standard grant for the Auditor role. |
| `audit.export` | Produce a signed, chain-verified export. |
| `audit.verify` | Run/on-demand a chain-verification report. |

The Auditor and External Auditor roles receive `audit.read` (or `audit.read.own_company`) and
`audit.export` but **never** any write permission on any table, including `audit_logs`. This is
enforced three ways in concert: the policy exposes no create/update/delete ability, the database
revokes `UPDATE`/`DELETE` from every application role, and the immutability trigger raises even
against a superuser connection — so "read-only" is a structural guarantee, not a UI convention. An
external auditor can be handed an `audit.read` credential and independently verify that no posted
journal entry lacks a trail, and recompute the hash chain against a raw export without trusting
QAYD's application layer at all.

# Error Handling

Typed exceptions map to the platform envelope through the global handler; the audit surface is
read/export only, so most error paths concern access and integrity rather than mutation.

| Exception | Meaning | Status | `errors[].code` |
|---|---|---|---|
| `AuthorizationException` (no `audit.read`) | Reader lacks audit permission | 403 | `permission_denied` |
| `MissingDateBoundException` | A trail query omitted the required `created_at` bound | 422 | `date_bound_required` |
| `AuditWriteAttemptException` | Any attempt to write/mutate the trail via the API | 405/403 | `audit_immutable` |
| `LegalHoldViolationException` | Export/retention op would violate a hold or open tax audit | 409 | `legal_hold_active` |
| `ChainVerificationFailedException` | A row's stored hash does not match recomputation | 200 (report) / alert | `chain_broken` (in report) |
| `ExportWindowTooLargeException` | Requested export exceeds the synchronous cap | 202 (async) | routed to a job |

Two failure behaviors are load-bearing for compliance. First, **capture never blocks the business
write path but is never silently dropped**: the outbox path guarantees a high-stakes row inside the
business transaction (a rollback removes the audit row too — there is no "audited-but-didn't-happen"
row), and the trigger-shadow reconciliation surfaces any gap where a high-risk row changed without an
app-layer audit row. Second, **integrity failures are findings, not exceptions swallowed**: a broken
hash chain or a masking gap raises a security finding (`AuditChainVerificationFailed`,
`AuditMaskingGapDetected`) rather than failing quietly, because the whole point of the trail is that
tampering is detectable.

# Testing

- **Feature — capture completeness.** Every posted journal entry, voided invoice, and permission
  change produces exactly one `audit_logs` row with the required minimum payload; a state transition
  that only touches `updated_at` is still audited; a `draft → posted` transition is captured.
- **Feature — same-transaction guarantee.** A rolled-back business transaction produces no audit row
  (outbox path); an `afterCommit` async row fires only after commit.
- **Feature — immutability.** An attempt to `UPDATE`/`DELETE` an audit row via any path fails; the
  policy exposes no write ability; the database trigger raises even for a privileged connection.
- **Feature — masking.** A payroll change stores masked salary/IBAN tokens, never plaintext, and the
  stable token still lets a reader detect that a value changed.
- **Feature — AI attribution.** An AI-approved journal post records the approving human as
  `actor_user_id`, `ai_assisted = true`, the confidence, and the agent — and, cross-referenced with
  the Workflow Engine, that the approver was not the initiator.
- **Feature — read RBAC & tenancy.** A reader without `audit.read` gets `403`; a reader in company A
  gets `404`/empty for company B's trail; every trail query requires a `created_at` bound.
- **Feature — cross-tenant-denied capture.** A cross-company access attempt that `404`s writes a
  `security.cross_tenant_denied` row on the attacker's own company context without leaking the
  target's existence.
- **Feature — export & holds.** An export is signed, chain-verified, and itself audited; an export
  that would violate a legal hold is refused.
- **Integrity jobs.** `VerifyChainNightly` flags a deliberately tampered row; `ReconcileTriggerShadow`
  raises `system.audit_gap_detected` for a simulated bypass write.

Audit coverage is itself part of the MODULE_ARCHITECTURE release checklist — a feature is not done
until every sensitive mutation it introduces is captured, masked, and covered by these tests.

# Related Documents

- [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) — the `audit_logs` table, partitioning, hash-chain triggers, masking registry, and storage-layer immutability.
- [../security/AUDIT_LOGS.md](../security/AUDIT_LOGS.md), [../security/SECURITY_ARCHITECTURE.md](../security/SECURITY_ARCHITECTURE.md) — the security and compliance framing of the trail.
- [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) — the correlation model and the audit-vs-logs distinction.
- [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) — the transaction boundary the audit write shares with the business mutation.
- [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) — approval decisions as segregation-of-duties evidence.
- [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md) — automated effects and autonomy changes as audited events.
- [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md), [../database/DATABASE_EVENTS.md](../database/DATABASE_EVENTS.md)
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md)

# End of Document
