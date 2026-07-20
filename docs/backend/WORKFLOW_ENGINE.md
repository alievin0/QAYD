# Workflow Engine — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: WORKFLOW_ENGINE
---

# Purpose

The Workflow Engine is the cross-cutting service that stands between a sensitive action being
*requested* and that action being *committed*. It is the single place in the QAYD backend where the
platform's founding rule — **AI proposes; a human, or an explicit and audited policy, approves** —
is mechanically enforced for every module. When a bank transfer, a payroll release, a tax
submission, a journal reversal, or an AI-drafted adjustment needs sign-off before it takes effect,
the originating module does not implement its own approval logic; it hands the intended action to
this engine, which resolves the correct chain of human approvers, holds the action in an
inert-but-durable state, drives the SLA and escalation timers, and — only after the last required
human decision lands — invokes the module's own Action to commit the effect.

This document specifies the *inside* of that deferral: the four tables it owns
(`approval_chains`, `approval_chain_steps`, `approval_requests`, `approval_request_actions`), the
Services and Actions that resolve and advance a chain, the `202 Accepted` contract returned when an
action enters a chain instead of committing inline, the two-key sensitive-action pattern (a bank
transfer requiring `bank.payment.approve` then `bank.payment.approve.final`), threshold-amount
routing, parallel-versus-sequential step semantics, the self-approval prohibition, delegation, the
72-hour default expiry, and — most importantly — how an AI proposal
(`initiated_by_type = 'ai_agent'`, but always with a real human `initiated_by_id` behind it) enters
the same chain a human-initiated request enters and commits through the identical Action.

Where [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) describes the Action/Service pattern a
module uses to commit an effect, this document describes how that same Action is *deferred* and
*replayed later under a human's authority*. Where [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md)
names `workflow` as a cross-cutting engine in its module map, this is that engine's full
specification. The engine never contains accounting logic of its own: it does not know how to post a
journal, move money, or file a return — it knows only how to route a decision to the right humans,
record what they decided, and call back into the module that does know, once and idempotently.

This engine unifies what earlier sibling documents referred to under ad-hoc names. The frontend
[../frontend/flows/APPROVAL_FLOW.md](../frontend/flows/APPROVAL_FLOW.md) notes that Laravel's
`ApprovalService` normalizes two historical shapes — the AI-request pair and the older
`approval_policies`/`approval_steps`/`approval_actions` used by
[../ai/workflows/EXPENSE_APPROVAL.md](../ai/workflows/EXPENSE_APPROVAL.md) — into one
`GET /api/v1/approvals` envelope. The tables specified here are that unified system of record; the
normalization surface reads from them.

# Responsibilities

The Workflow Engine owns, and is the only component that owns, the following:

1. **Chain resolution.** Given a subject type (`bank_transfer`, `payroll_run`, `tax_return`,
   `journal_reversal`, `expense_claim`, `ai_proposal`, …), a company, and the subject's own scalar
   context (most importantly its base-currency `threshold_amount`), resolve the ordered set of
   approval steps that must clear before the underlying action may commit.
2. **Deferral.** Accept an *intended* action from a module, persist it as an `approval_request`
   whose `proposed_payload` is the exact, validated input the committing Action will later receive,
   and return `202 Accepted` to the caller — the action has not run and will not run until approved.
3. **Advancement.** Record each human decision as an `approval_request_actions` row, evaluate
   whether the current step is satisfied (respecting parallel-versus-sequential and quorum rules),
   and either advance to the next step or mark the request terminally `approved`/`rejected`.
4. **Commit hand-off.** On the final approving decision, invoke the owning module's committing
   Action exactly once, under the approving human's authority, with an idempotency key derived from
   the request — never posting the effect itself.
5. **Guardrails.** Enforce the self-approval prohibition (an approver may never be the initiator or
   the maker of the underlying record), the sensitivity floor (subject types QAYD never
   auto-executes regardless of AI confidence), and the fraud-hold brake (a held request cannot
   advance).
6. **Timing.** Drive per-request SLA timers, escalate an unattended step to the next tier, and expire
   a request that no one acts on within its `expires_at` (default 72 hours).
7. **Emission.** Broadcast every state transition to the company-scoped `.approvals` realtime
   channel and fan a notification out to each eligible approver, and write an immutable audit row for
   every decision (see [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) and
   [AUDIT_SERVICE.md](./AUDIT_SERVICE.md)).

What it explicitly does **not** own: the business meaning of any subject (the module owns that), the
autonomy thresholds that decide whether an AI action needs approval at all (that is the Automation
Engine's `ai_autonomy_settings` — see [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md)), and the
frontend rendering of a proposal (that is the Approval Center and its flow doc).

# Domain Model

Four concepts, two of them configuration and two of them runtime:

- **Approval chain** (`approval_chains`) — configuration. A named, versioned policy for one subject
  type in one company: "a `bank_transfer` in company 42 requires this sequence of steps." A chain is
  matched to a request at creation time; once matched, the request snapshots the chain so a later
  edit to the chain never rewrites the history of an in-flight request.
- **Approval chain step** (`approval_chain_steps`) — configuration. One ordered link in a chain,
  carrying the permission an approver must hold (`required_permission`, e.g.
  `bank.payment.approve.final`), the approver resolution rule (a fixed role, a fixed user, or a
  dynamic resolver such as "the initiator's manager"), the step mode (`sequential` or `parallel`),
  the quorum (`min_approvals`, default 1), and the optional `threshold_amount` below which the step
  is skipped entirely.
- **Approval request** (`approval_requests`) — runtime. One live instance: this specific bank
  transfer, in this state, at this step, expiring at this time, carrying the `proposed_payload` that
  will be replayed on commit and the `initiated_by_type`/`initiated_by_id` that record who (human or
  AI-on-behalf-of-human) asked for it.
- **Approval request action** (`approval_request_actions`) — runtime, append-only. One decision:
  who decided, on which step, what they decided (`approved`/`rejected`/`changes_requested`/
  `delegated`), when, and why.

```
approval_chains (1) ───< approval_chain_steps (N)      [configuration, versioned]
       │  matched + snapshotted at creation
       ▼
approval_requests (1) ──< approval_request_actions (N) [runtime, append-only decisions]
       │  on final approval
       ▼
owning module's committing Action (e.g. Banking\ReleaseTransferAction) — invoked once, idempotent
```

The subject is referenced polymorphically (`subject_type` + `subject_id`) so the engine never holds
a foreign key into another module's tables — consistent with the module-independence rule in
[../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md). The engine reads the
subject only through the owning module's read path when it needs to render context; it mutates the
subject only by calling that module's Action.

## Subject types and their canonical chains

| Subject type | Sensitivity | Canonical chain (default thresholds, base currency KWD) |
|---|---|---|
| `bank_transfer` / `bank_payment` | Always human, two-key | Step 1 `bank.payment.approve` (Finance Manager) → Step 2 `bank.payment.approve.final` (CFO / second authorized signer). Two distinct humans, always, at any amount. |
| `payroll_run` | Always human | Step 1 `payroll.release` (Payroll Manager) → Step 2 `payroll.release` (Finance Manager) above `manager_approval_ceiling`. |
| `tax_return` | Always human | Step 1 `tax.submit` (Tax Advisor / Accountant) → Step 2 `tax.submit` (CFO) for a return whose net payable exceeds the configured tier. |
| `journal_reversal` | Human above threshold | Step 1 `accounting.journal.reverse` (Senior Accountant); +Finance Manager above tier. |
| `expense_claim` | Tiered | Direct Manager → +Finance Manager > 500 → +CFO > 2,000 (see EXPENSE_APPROVAL). |
| `ai_proposal` | Always human | A single `ai.approve` step, plus the underlying subject's own chain if the committed effect is itself sensitive (a proposal to release a transfer inherits the transfer's two-key chain). |

The two-key `bank_transfer` chain is the archetype: **no bank payment ever leaves QAYD on one
signature, regardless of amount, regardless of whether a human or an AI initiated it.** This is a
sensitivity-floor rule, not a threshold rule — `threshold_amount` can *add* steps, never remove the
mandated ones.

# Key Classes

## Services

`App\Services\Workflow\ApprovalService` — the façade the rest of the platform calls. It resolves and
snapshots a chain, opens a request, and exposes the unified read model the
`GET /api/v1/approvals` envelope serializes. It never commits an underlying action itself; commit is
delegated to the owning module's Action via a resolver.

```php
namespace App\Services\Workflow;

use App\Data\Workflow\ApprovalRequestData;
use App\Enums\Workflow\ApprovalStatus;
use App\Models\Workflow\ApprovalRequest;
use App\Repositories\Workflow\ApprovalChainRepository;
use App\Repositories\Workflow\ApprovalRequestRepository;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApprovalService
{
    public function __construct(
        private readonly ApprovalChainRepository $chains,
        private readonly ApprovalRequestRepository $requests,
        private readonly ChainResolver $resolver,
    ) {}

    /**
     * Open a request for a deferred action. Returns the persisted request; the caller
     * (a module Action) then returns 202 Accepted with the request in the envelope.
     */
    public function open(ApprovalRequestData $data, User $initiator): ApprovalRequest
    {
        return DB::transaction(function () use ($data, $initiator): ApprovalRequest {
            $chain = $this->resolver->match(
                subjectType: $data->subjectType,
                thresholdAmount: $data->thresholdAmount,   // base-currency scalar used for routing
            );

            // Snapshot the chain + its applicable steps onto the request so a later
            // chain edit cannot rewrite an in-flight request's required approvals.
            $request = $this->requests->create([
                'subject_type'      => $data->subjectType,
                'subject_id'        => $data->subjectId,
                'chain_id'          => $chain->id,
                'chain_version'     => $chain->version,
                'status'            => ApprovalStatus::Pending,
                'current_step_no'   => 1,
                'threshold_amount'  => $data->thresholdAmount,
                'proposed_payload'  => $data->proposedPayload,   // replayed verbatim on commit
                'commit_action'     => $data->commitAction,      // e.g. Banking\ReleaseTransferAction
                'initiated_by_type' => $data->initiatedByType,   // 'user' | 'ai_agent'
                'initiated_by_id'   => $initiator->id,           // ALWAYS a real human user id
                'source_agent_code' => $data->sourceAgentCode,   // null for human-initiated
                'expires_at'        => now()->addHours(
                    config('workflow.default_sla_hours', 72),
                ),
                // company_id, created_by auto-filled by BelongsToCompany trait
            ]);

            $this->resolver->materializeSteps($request, $chain);  // copies applicable steps
            event(new \App\Events\Workflow\ApprovalRequested($request));

            return $request;
        });
    }
}
```

`App\Services\Workflow\ChainResolver` — a domain service. Given subject type, company, and
`threshold_amount`, it selects the matching `approval_chains` row and computes which of its
`approval_chain_steps` apply (skipping any step whose `threshold_amount` exceeds the request's
amount, but never skipping a step flagged `is_mandatory`). It is pure with respect to HTTP and is
the home of the routing invariants.

`App\Services\Workflow\StepEvaluator` — a domain service that answers a single question: given a
step's mode, quorum, and the decisions recorded against it so far, is the step satisfied, still
open, or failed? Parallel steps are satisfied when `min_approvals` distinct eligible approvers have
approved; sequential steps are satisfied by a single approval and advance immediately; any rejection
on any step fails the whole request.

## Actions

`App\Actions\Workflow\RecordDecisionAction` — the write path for a human decision. It validates that
the actor is eligible for the current step and is not the initiator/maker (self-approval guard),
appends the `approval_request_actions` row, asks `StepEvaluator` whether the step is now satisfied,
and either advances or terminates. On the final approving decision it resolves and invokes the
`commit_action`.

```php
namespace App\Actions\Workflow;

use App\Data\Workflow\DecisionData;
use App\Enums\Workflow\ApprovalStatus;
use App\Enums\Workflow\DecisionType;
use App\Exceptions\Workflow\SelfApprovalException;
use App\Models\Workflow\ApprovalRequest;
use App\Models\User;
use App\Services\Workflow\StepEvaluator;
use Illuminate\Support\Facades\DB;

final class RecordDecisionAction
{
    public function __construct(
        private readonly StepEvaluator $evaluator,
        private readonly CommitDeferredAction $commit,
    ) {}

    public function execute(ApprovalRequest $request, DecisionData $data, User $actor): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $data, $actor): ApprovalRequest {
            $request = $request->lockForUpdate()->first();   // serialize concurrent approvers

            $this->assertNotHeldOrExpired($request);
            $this->assertActorEligibleForCurrentStep($request, $actor);
            $this->assertNotSelfApproval($request, $actor);  // → 403 maker_checker_violation

            $request->actions()->create([
                'step_no'        => $request->current_step_no,
                'actor_id'       => $actor->id,
                'decision'       => $data->decision,          // approved|rejected|changes_requested|delegated
                'reason'         => $data->reason,
                'decided_at'     => now(),
            ]);

            if ($data->decision === DecisionType::Rejected) {
                $request->update(['status' => ApprovalStatus::Rejected, 'resolved_at' => now()]);
                event(new \App\Events\Workflow\ApprovalRejected($request, $actor->id));
                return $request;
            }

            if (! $this->evaluator->stepSatisfied($request)) {
                event(new \App\Events\Workflow\ApprovalStepDecided($request, $actor->id));
                return $request;                              // quorum not yet met on a parallel step
            }

            if ($this->evaluator->hasNextStep($request)) {
                $request->increment('current_step_no');
                event(new \App\Events\Workflow\ApprovalStepAdvanced($request));
                return $request;                              // next signer's turn (two-key: final signer)
            }

            // Final required approval reached — commit the underlying action, once, idempotently.
            $request->update(['status' => ApprovalStatus::Approved, 'resolved_at' => now()]);
            $this->commit->execute($request, $actor);
            event(new \App\Events\Workflow\ApprovalApproved($request, $actor->id));

            return $request;
        });
    }

    private function assertNotSelfApproval(ApprovalRequest $request, User $actor): void
    {
        if ($request->initiated_by_id === $actor->id || $request->subjectMakerId() === $actor->id) {
            throw new SelfApprovalException($request->id, $actor->id);
        }
    }
}
```

`App\Actions\Workflow\CommitDeferredAction` — resolves the request's `commit_action` class from the
container and invokes it with a DTO reconstructed from `proposed_payload`, carrying an idempotency
key of `approval:{request_id}:commit`. Because the committing Action is the module's own Action
(the same one a direct human `POST` would call), an AI-proposed transfer and a human-initiated
transfer run byte-for-byte the same code path and invariants — the property SERVICE_ARCHITECTURE
calls "one implementation, three callers, one set of rules."

`App\Actions\Workflow\DelegateStepAction` — reassigns the current step to another eligible approver
(who must independently satisfy the step's `required_permission` and the self-approval guard),
recording a `delegated` decision row and re-fanning the notification.

`App\Actions\Workflow\ExpireStaleRequestsAction` — invoked by the scheduler; transitions every
`pending` request past its `expires_at` to `expired`, emits `ApprovalExpired`, and never commits.

## Models

- `App\Models\Workflow\ApprovalChain`, `ApprovalChainStep`, `ApprovalRequest`,
  `ApprovalRequestAction` — all use the `BelongsToCompany` trait; `ApprovalRequestAction` is
  append-only (no `updated_at` semantics beyond creation, mirroring the audit posture).
- `ApprovalRequest` exposes `subjectMakerId()` and `subject()` accessors that resolve the polymorphic
  subject through the owning module's read model only.

## Jobs

- `App\Jobs\Workflow\SweepApprovalSlas` (queue `default`, scheduled every five minutes) — escalates
  steps whose per-step SLA has elapsed to the next tier and expires requests past `expires_at`.
- `App\Jobs\Workflow\FanApprovalNotifications` (queue `realtime`) — resolves each eligible approver
  for the current step and dispatches their notification.

## Events

`ApprovalRequested`, `ApprovalStepDecided`, `ApprovalStepAdvanced`, `ApprovalApproved`,
`ApprovalRejected`, `ApprovalDelegated`, `ApprovalEscalated`, `ApprovalExpired`, `ApprovalHeld` —
all past-tense facts carrying ids plus a compact projection, all implementing `ShouldBroadcast` onto
the `.approvals` channel (Events, Queues & Realtime, below).

## Policies

`App\Policies\Workflow\ApprovalRequestPolicy` — `view` (`ai.approve` or `reports.read`), `decide`
(`ai.approve` **and** the current step's `required_permission` **and** not the maker), `delegate`
(same as decide plus `workflow.delegate`). The policy answers only "may this actor act in the active
company"; tenant isolation is enforced earlier by RLS and route-model binding.

# Endpoints Backed (/api/v1)

The engine is fronted by a small, uniform surface under `/api/v1/approvals`. Every response uses the
platform envelope `{success, data, message, errors, meta, request_id, timestamp}`
([../api/REST_STANDARDS.md](../api/REST_STANDARDS.md)).

| Method & path | Purpose | Permission | Success |
|---|---|---|---|
| `GET /api/v1/approvals` | The unified reviewer queue (filter by `status`, `subject_type`, `assigned_to_me`). | `ai.approve` or `reports.read` | 200 |
| `GET /api/v1/approvals/{id}` | One request: chain, current step, decisions, `proposed_payload` summary, source citations. | `ai.approve` or step match | 200 |
| `POST /api/v1/approvals/{id}/approve` | Record an approval on the current step. | `ai.approve` + step permission | 200 (advanced/approved) |
| `POST /api/v1/approvals/{id}/reject` | Reject; terminates the chain. `reason` required. | `ai.approve` + step permission | 200 |
| `POST /api/v1/approvals/{id}/request-changes` | Send back to the requester with a note. | `ai.approve` + step permission | 200 |
| `POST /api/v1/approvals/{id}/delegate` | Reassign the current step to another eligible approver. | `workflow.delegate` | 200 |
| `POST /api/v1/approvals/bulk-approve` | Approve several low-risk requests at once (capped, never sensitive subject types). | `ai.approve` | 200 with per-id results |
| `GET /api/v1/workflow/chains` | List configured chains for the company. | `workflow.chain.read` | 200 |
| `POST /api/v1/workflow/chains` | Create/version a chain and its steps. | `workflow.chain.manage` | 201 |
| `PATCH /api/v1/workflow/chains/{id}` | Amend a chain (creates a new `version`; in-flight requests keep their snapshot). | `workflow.chain.manage` | 200 |

The engine is not usually called by clients to *create* a request — modules do that internally. When
a module endpoint (for example `POST /api/v1/banking/transfers/{id}/release`) determines the action
must be approved rather than executed inline, it opens a request through `ApprovalService` and
returns:

```
HTTP/1.1 202 Accepted
Location: /api/v1/approvals/8842
```
```json
{
  "success": true,
  "data": {
    "approval_request_id": 8842,
    "status": "pending",
    "subject_type": "bank_transfer",
    "subject_id": 5501,
    "chain": {
      "current_step_no": 1,
      "steps": [
        { "step_no": 1, "required_permission": "bank.payment.approve",       "mode": "sequential", "state": "open" },
        { "step_no": 2, "required_permission": "bank.payment.approve.final", "mode": "sequential", "state": "blocked" }
      ]
    },
    "initiated_by_type": "ai_agent",
    "initiated_by_id": 317,
    "source_agent_code": "TREASURY_MANAGER",
    "expires_at": "2026-07-23T09:14:00Z"
  },
  "message": "Action requires approval and has entered the workflow.",
  "errors": [],
  "meta": { "sla_hours": 72 },
  "request_id": "0f3c…",
  "timestamp": "2026-07-20T09:14:00Z"
}
```

`202 Accepted` is the contract that tells every caller — web, mobile, partner, or the AI engine —
"this did not commit; watch the approval." It is the same shape whether the initiator was a human
clicking Release or the Treasury Manager agent proposing one.

# Database Tables Owned

All four tables carry the platform standard columns (`company_id`, `branch_id`,
`created_by`/`updated_by`, timestamps, `deleted_at` where soft-delete applies) and every index leads
with `company_id`. DDL is shown in full because no other document defines these tables.

```sql
-- Configuration: a versioned approval policy for one subject type in one company.
CREATE TABLE approval_chains (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT       NOT NULL REFERENCES companies(id),
    subject_type  VARCHAR(60)  NOT NULL,     -- 'bank_transfer','payroll_run','tax_return',...
    name_en       VARCHAR(160) NOT NULL,
    name_ar       VARCHAR(160) NOT NULL,
    version       INTEGER      NOT NULL DEFAULT 1,
    is_active     BOOLEAN      NOT NULL DEFAULT true,
    created_by BIGINT NULL REFERENCES users(id), updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,
    CONSTRAINT uq_chain_active UNIQUE (company_id, subject_type, version)
);
CREATE INDEX idx_chains_lookup ON approval_chains (company_id, subject_type)
    WHERE is_active AND deleted_at IS NULL;

CREATE TYPE approval_step_mode AS ENUM ('sequential','parallel');
CREATE TYPE approval_approver_kind AS ENUM ('role','user','dynamic');

-- Configuration: one ordered step within a chain.
CREATE TABLE approval_chain_steps (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT       NOT NULL REFERENCES companies(id),
    chain_id            BIGINT       NOT NULL REFERENCES approval_chains(id),
    step_no             SMALLINT     NOT NULL,
    required_permission VARCHAR(120) NOT NULL,       -- e.g. 'bank.payment.approve.final'
    approver_kind       approval_approver_kind NOT NULL DEFAULT 'role',
    approver_role       VARCHAR(80)  NULL,           -- when kind='role'
    approver_user_id    BIGINT       NULL REFERENCES users(id),  -- when kind='user'
    dynamic_resolver    VARCHAR(80)  NULL,           -- when kind='dynamic', e.g. 'initiator.manager'
    mode                approval_step_mode NOT NULL DEFAULT 'sequential',
    min_approvals       SMALLINT     NOT NULL DEFAULT 1,
    threshold_amount    NUMERIC(19,4) NULL,          -- step applies only when request amount >= this
    is_mandatory        BOOLEAN      NOT NULL DEFAULT false,  -- never skipped by threshold (two-key rule)
    sla_hours           SMALLINT     NULL,           -- per-step SLA; falls back to chain/global default
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_step_no UNIQUE (chain_id, step_no),
    CONSTRAINT chk_step_quorum CHECK (min_approvals >= 1)
);
CREATE INDEX idx_steps_chain ON approval_chain_steps (chain_id, step_no);

CREATE TYPE approval_request_status AS ENUM (
    'pending','approved','rejected','changes_requested','held','expired','void'
);
CREATE TYPE approval_initiator_type AS ENUM ('user','ai_agent');

-- Runtime: one live approval instance. proposed_payload is replayed verbatim on commit.
CREATE TABLE approval_requests (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT       NOT NULL REFERENCES companies(id),
    branch_id          BIGINT       NULL REFERENCES branches(id),
    subject_type       VARCHAR(60)  NOT NULL,
    subject_id         BIGINT       NOT NULL,
    chain_id           BIGINT       NOT NULL REFERENCES approval_chains(id),
    chain_version      INTEGER      NOT NULL,        -- snapshot: the version in force at creation
    status             approval_request_status NOT NULL DEFAULT 'pending',
    current_step_no    SMALLINT     NOT NULL DEFAULT 1,
    threshold_amount   NUMERIC(19,4) NULL,           -- base-currency scalar used for routing
    commit_action      VARCHAR(160) NOT NULL,        -- FQCN of the module Action to invoke on approve
    proposed_payload   JSONB        NOT NULL,        -- validated DTO input for the commit Action
    initiated_by_type  approval_initiator_type NOT NULL DEFAULT 'user',
    initiated_by_id    BIGINT       NOT NULL REFERENCES users(id),  -- ALWAYS a real human
    source_agent_code  VARCHAR(60)  NULL,            -- e.g. 'TREASURY_MANAGER' when ai_agent
    ai_confidence      NUMERIC(5,4) NULL,
    fraud_hold_id      BIGINT       NULL,            -- set when Fraud Detection brakes the request
    expires_at         TIMESTAMPTZ  NOT NULL,        -- default now()+72h
    resolved_at        TIMESTAMPTZ  NULL,
    committed_at       TIMESTAMPTZ  NULL,
    version            INTEGER      NOT NULL DEFAULT 1,   -- optimistic lock
    created_by BIGINT NULL REFERENCES users(id), updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,
    CONSTRAINT chk_req_step CHECK (current_step_no >= 1)
);
CREATE INDEX idx_reqs_company_status ON approval_requests (company_id, status)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_reqs_pending ON approval_requests (company_id, expires_at)
    WHERE status = 'pending';
CREATE INDEX idx_reqs_subject ON approval_requests (subject_type, subject_id);
CREATE INDEX idx_reqs_initiator ON approval_requests (initiated_by_id, status);

CREATE TYPE approval_decision AS ENUM ('approved','rejected','changes_requested','delegated');

-- Runtime, append-only: one human decision on one step.
CREATE TABLE approval_request_actions (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT       NOT NULL REFERENCES companies(id),
    approval_request_id  BIGINT       NOT NULL REFERENCES approval_requests(id),
    step_no              SMALLINT     NOT NULL,
    actor_id             BIGINT       NOT NULL REFERENCES users(id),
    delegated_to_id      BIGINT       NULL REFERENCES users(id),  -- set when decision='delegated'
    decision             approval_decision NOT NULL,
    reason               TEXT         NULL,          -- required by app validation for reject/changes
    request_id           UUID         NULL,          -- correlation to the API envelope request_id
    ip_address           INET         NULL,
    decided_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT now()
    -- no updated_at / deleted_at: decisions are immutable facts
);
CREATE INDEX idx_reqactions_request ON approval_request_actions (approval_request_id, step_no);
CREATE INDEX idx_reqactions_actor ON approval_request_actions (actor_id, decided_at DESC);
```

The `proposed_payload` column is the load-bearing design decision: it stores the *fully validated
input* to the committing Action, not a rendered document. On commit, the engine reconstructs the
module's DTO from this payload, so the deferred action executes through the identical validation and
invariants the inline path uses. A schema change to the module's DTO is handled the same way an
in-flight-request chain edit is: the payload is versioned by the request and re-validated at commit
time, so a payload that no longer validates fails commit loudly rather than posting a malformed
effect.

# Multi-Tenancy Enforcement

The Workflow Engine is tenant-scoped by exactly the same mechanism as every other module, with no
exceptions carved out for it:

- **Ambient scope.** `X-Company-Id` is bound once by `ResolveTenantCompany`; all four tables use the
  `BelongsToCompany` trait, so `CompanyScope` filters every query and `SET LOCAL
  app.current_company_id` drives PostgreSQL row-level security
  ([../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md),
  [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md)). An approver in company A
  physically cannot load, list, or decide a request belonging to company B — a cross-tenant id
  resolves to `404`, never `403`, so existence never leaks.
- **Chain matching is company-local.** `ChainResolver` selects only from `approval_chains` for the
  active company; there is no global/default chain that spans tenants. A company with no configured
  chain for a sensitive subject type falls back to the platform-mandated minimum chain (e.g. the
  two-key `bank_transfer` chain) rather than to another company's configuration.
- **The subject is re-scoped on commit.** When `CommitDeferredAction` reconstructs the module DTO and
  invokes the committing Action, that Action runs inside a fresh transaction that re-establishes the
  request's `company_id` via the tenant-aware job/action envelope, so the commit cannot post into a
  different tenant even though it runs asynchronously from the original request.
- **Realtime is company-partitioned.** Broadcasts go only to `private-company.{companyId}.approvals`;
  the channel-authorization callback in `routes/channels.php` re-runs the same RBAC check as the HTTP
  layer, so a socket for one company can never receive another's approval traffic.
- **Delegation stays in-tenant.** A step may only be delegated to a user who is a member of the same
  company (`company_users`) and independently holds the step's permission — delegation can never
  route a decision to an outside identity.

# Events, Queues & Realtime

Every state transition emits a past-tense domain event carrying the canonical envelope
`{event_id, event_name, company_id, aggregate_type, aggregate_id, occurred_at, causation_id,
correlation_id, payload}` ([../database/DATABASE_EVENTS.md](../database/DATABASE_EVENTS.md)). The
`correlation_id` threads from the originating request through the whole approval so an auditor can
follow one id from "AI proposed" to "human approved" to "money moved."

| Event | Emitted when | Broadcast channel | Notification |
|---|---|---|---|
| `ApprovalRequested` | A request opens (`202` returned) | `private-company.{id}.approvals` | each eligible step-1 approver |
| `ApprovalStepDecided` | A decision recorded, quorum not yet met | `.approvals` | — |
| `ApprovalStepAdvanced` | Step satisfied, next step opens | `.approvals` | next step's approvers (e.g. the final signer) |
| `ApprovalApproved` | Final approval; underlying action committed | `.approvals` + `private-company.{id}.notifications` | initiator |
| `ApprovalRejected` | Any rejection; chain terminates | `.approvals` + `.notifications` | initiator |
| `ApprovalDelegated` | Step reassigned | `.approvals` | new assignee |
| `ApprovalEscalated` | Per-step SLA elapsed | `.approvals` + `.notifications` | next tier |
| `ApprovalExpired` | `expires_at` passed with no decision | `.notifications` | initiator |
| `ApprovalHeld` | Fraud Detection brakes the request | `.approvals` + `.ai` | Auditor, Finance Manager |

Queue usage follows the named-queue discipline in
[BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md):

- **`realtime`** — broadcast dispatch and `FanApprovalNotifications`; must stay near-empty so an
  approver sees a new request instantly.
- **`default`** — `SweepApprovalSlas` (scheduled every five minutes) and the queued cross-module
  listener that reacts to `ApprovalApproved` when a downstream module needs to observe the commit.
- The **commit hand-off itself is synchronous** inside the deciding request's transaction when the
  committing Action is cheap (marking a transfer released); when the committed effect is heavy (a
  large payroll disbursement), `CommitDeferredAction` dispatches the module's own tenant-aware job
  rather than running it inline, and the request moves to `approved` immediately while
  `committed_at` is set when the job confirms.

The AI subset of these events is additionally fanned to the FastAPI engine on the `events-ai` queue
via the platform's `NotifyAiLayerListener`, so an agent that proposed a request learns its proposal
was approved, rejected, or expired ([../api/INTERNAL_API.md](../api/INTERNAL_API.md)).

# Integrations

The engine integrates *inward* (modules defer to it) and *outward* (it commits back into modules and
notifies the AI layer). It never reaches into another module's tables.

- **Owning modules (Banking, Payroll, Tax, Accounting, Sales, Purchasing, Expenses).** Each module,
  at the point where a sensitive action would otherwise commit inline, calls `ApprovalService::open`
  with the committing Action's FQCN and a validated `proposed_payload`, then returns `202`. On the
  final approval the engine calls that Action back. The module owns the effect; the engine owns the
  decision. The two-key `bank_transfer` chain is Banking's own release chain
  ([../accounting/BANKING.md](../accounting/BANKING.md)) surfaced through this engine; `payroll.release`
  is Payroll's ([../accounting/PAYROLL.md](../accounting/PAYROLL.md)); `tax.submit` is Tax's.
- **AI engine (FastAPI, out of process).** When an agent (Treasury Manager, Payroll Manager, Tax
  Advisor, General Accountant) reaches a conclusion that would move money or file a return, it does
  not act — it calls the backend's internal API `POST /api/v1/ai/proposals`, which opens an
  `approval_request` with `initiated_by_type = 'ai_agent'`, `source_agent_code` set, and — critically
  — `initiated_by_id` set to the **real human** on whose behalf and permission the agent is acting
  (never a synthetic AI user id, so the self-approval guard and the audit trail always attribute the
  request to an accountable person). The agent never receives a commit path; the human decision does.
  See [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) and
  [../ai/workflows/EXPENSE_APPROVAL.md](../ai/workflows/EXPENSE_APPROVAL.md).
- **Automation Engine.** [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md) decides *whether* an
  AI-suggested or rule-driven action needs approval at all (its `ai_autonomy_settings` per permission
  key). When it decides an action is `requires_approval`, it routes through this engine exactly as a
  module would; when it decides `auto`, this engine is not involved — but the sensitivity floor here
  still overrides: a subject type on the never-auto list (bank payment, payroll release, tax
  submission) enters a chain even if an over-permissive automation rule marked it `auto`.
- **Fraud Detection.** May set a request to `held` (via `fraud_hold_id`); a held request cannot
  advance until the hold is cleared through the fraud case's own resolution
  ([../ai/agents/FRAUD_AGENT.md](../ai/agents/FRAUD_AGENT.md)).
- **Audit Service.** Every decision writes an immutable audit row inside the deciding transaction
  ([AUDIT_SERVICE.md](./AUDIT_SERVICE.md)).

# Permissions

Authorization uses the platform grammar `<area>.<action>` / `<area>.<entity>.<action>`, always
company-scoped and default-deny ([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)).
Deciding a step requires **two** grants simultaneously: the generic `ai.approve` (the right to act in
the Approval Center at all) **and** the step's own `required_permission` (the specific authority that
step demands). Holding `ai.approve` alone lets a user open the queue but not clear a bank payment's
final step.

| Permission | Grants |
|---|---|
| `ai.approve` | Act on approval requests in the Approval Center (necessary, not sufficient). |
| `bank.payment.approve` | Satisfy the first key of a bank-transfer chain. |
| `bank.payment.approve.final` | Satisfy the second, final key — distinct permission, must be a distinct human. |
| `payroll.release` | Satisfy a payroll-release step. |
| `tax.submit` | Satisfy a tax-return submission step. |
| `accounting.journal.reverse` | Satisfy a journal-reversal step. |
| `workflow.delegate` | Reassign a step to another eligible approver. |
| `workflow.chain.read` | List/read chain configuration. |
| `workflow.chain.manage` | Create/version/amend chains and steps. |
| `reports.read` | View a request read-only (no decision rights). |
| `audit.read` | Read the decision trail (via Audit Service). |

The **self-approval prohibition** is enforced in `RecordDecisionAction` in addition to the
permission check: even a user who holds every permission above cannot decide a request they
initiated or whose underlying record they created — the maker and the checker are always distinct
humans. This is the segregation-of-duties evidence an external auditor verifies by cross-referencing
`approval_requests.initiated_by_id` against `approval_request_actions.actor_id`
([../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) `# Compliance`).

# Error Handling

Typed exceptions map to the platform envelope and status codes through the global handler; the engine
never assembles error responses by hand.

| Exception | Meaning | Status | `errors[].code` |
|---|---|---|---|
| `SelfApprovalException` | Actor is the initiator or the subject's maker | 403 | `maker_checker_violation` |
| `StepPermissionMissingException` | Actor lacks the current step's `required_permission` | 403 | `permission_denied` |
| `NotCurrentStepException` | Decision aimed at a step that is not open | 409 | `invalid_step` |
| `RequestHeldException` | A fraud hold blocks advancement | 409 | `approval_held` |
| `RequestExpiredException` | Decision arrives after `expires_at` | 409 | `approval_expired` |
| `RequestAlreadyResolvedException` | Request already approved/rejected/void | 409 | `already_resolved` |
| `OptimisticLockException` | Two approvers raced on the same request | 409 | `version_conflict` |
| `ChainNotResolvableException` | No chain matches and no mandatory floor applies | 422 | `chain_unresolved` |
| `PayloadRevalidationException` | `proposed_payload` no longer validates at commit | 422 | field-level codes |
| `CommitActionFailedException` | The committing module Action threw | propagated | the Action's own code |

Two error paths deserve explicit note. First, **concurrent approvers**: the deciding transaction
takes a `lockForUpdate` on the request row, so two people approving the same parallel step at once
serialize; the second sees the first's decision and either satisfies quorum or is told the step
already advanced — never a double-commit. Second, **commit failure after final approval**: if the
committing Action throws (a transfer's bank account was frozen between proposal and approval), the
approval decision is *not* rolled back — the human genuinely approved — but the request moves to a
`approved` status with a recorded `commit_failed` audit note and a `.notifications` alert to the
initiator, and the underlying module surfaces its own domain error; the money never moves on a
failed commit because the committing Action is itself transactional and idempotent
([SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) `# Transactions`).

# Testing

Testing follows the layering in [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) and
[../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md):

- **Unit — `ChainResolver`.** A `bank_transfer` always resolves the two mandatory keys regardless of
  amount; a `threshold_amount` above a tier *adds* a step; a step below its `threshold_amount` is
  skipped unless `is_mandatory`; an unconfigured company falls back to the platform floor chain.
- **Unit — `StepEvaluator`.** A sequential step advances on one approval; a parallel step advances
  only at `min_approvals`; any rejection fails the request; a step cannot be satisfied by the same
  actor twice.
- **Unit — `RecordDecisionAction` self-approval guard.** The initiator cannot approve; the subject's
  maker cannot approve; a delegate who is the maker is rejected.
- **Feature — the `202` contract.** A module endpoint that must defer returns `202` with the request
  envelope and does not mutate the subject; a subsequent final approval commits it exactly once.
- **Feature — two-key end to end.** Signer A clears `bank.payment.approve`; the request advances but
  the transfer has not moved; signer B (a distinct human) clears `bank.payment.approve.final`; the
  transfer commits; a second attempt by signer B on the first key is refused.
- **Feature — AI proposal parity.** A request opened with `initiated_by_type = 'ai_agent'` commits
  through the identical module Action as a human-initiated one, and its `initiated_by_id` is a real
  human who is then barred by the self-approval guard from approving it.
- **Feature — tenant isolation.** A token for company A receives `404` for company B's request and
  cannot appear as an eligible approver on it.
- **Feature — expiry & escalation.** `SweepApprovalSlas` escalates an unattended step and expires a
  request past `expires_at` without committing.
- **Audit assertions.** Every decision writes exactly one immutable `audit_logs` row with the actor,
  the step, and the correlation `request_id`.

A workflow subject type is not considered production-ready until it ships with its chain
configuration, its `202` deferral path, its committing Action, permissions, audit coverage, and these
tests — the release checklist from MODULE_ARCHITECTURE.

# Related Documents

- [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) — where the workflow engine sits in the module map.
- [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) — the Action/DTO/idempotency pattern the commit hand-off reuses.
- [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md) — decides whether an action needs approval before this engine routes it.
- [AUDIT_SERVICE.md](./AUDIT_SERVICE.md) — the immutable trail every decision writes.
- [../ai/workflows/EXPENSE_APPROVAL.md](../ai/workflows/EXPENSE_APPROVAL.md), [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) — AI proposals entering the chain.
- [../frontend/flows/APPROVAL_FLOW.md](../frontend/flows/APPROVAL_FLOW.md) — the reviewer journey this engine backs.
- [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md), [../api/INTERNAL_API.md](../api/INTERNAL_API.md), [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md)
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md), [../database/DATABASE_EVENTS.md](../database/DATABASE_EVENTS.md), [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)

# End of Document
