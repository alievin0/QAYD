# Automation Engine — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: AUTOMATION_ENGINE
---

# Purpose

The Automation Engine is the cross-cutting service that lets a company — and QAYD's autonomous
finance workforce — turn a repetitive judgement into a standing rule: *when this happens, if these
conditions hold, do that.* It is where a Finance Manager encodes "every recurring SaaS bill from this
vendor under KWD 200 auto-categorizes to Software Expense and posts," where the platform schedules
"generate next month's recurring invoices at 01:00 on the first," and where an AI agent's learned
suggestion ("you always route this merchant to Travel — automate it?") becomes a durable, audited,
reversible rule rather than a decision re-made by hand three hundred times a month.

Its defining constraint is the same one that governs the whole platform: **automation may accelerate
the safe, never bypass the sensitive.** A rule may categorize, tag, notify, draft, and post low-risk
book entries autonomously; it may *never* auto-commit a bank payment, a payroll release, or a tax
submission, no matter how it is configured. Every action a rule proposes is filtered through the
`ai_autonomy_settings` gate — a per-permission-key setting of `auto`, `suggest_only`, or
`requires_approval` — and anything that resolves to `requires_approval` is routed into the
[WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) for a human decision exactly as if a person had proposed
it. The engine is fast and tireless, but it is not trusted with money on its own signature.

This document specifies the rules model (`automation_rules`, its versioned `automation_rule_versions`,
and the `automation_runs` execution log), the trigger → condition → action pipeline, the
`ai_autonomy_settings` autonomy gate and the `ai.automation` permission that governs who may create
automation at all, cron/time-based scheduling, the guardrails that make auto-commit of sensitive
actions structurally impossible, dry-run/simulation, rule versioning and audit, failure handling and
retries, and observability. Where [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) describes the
Actions a rule ultimately invokes, this document describes what decides to invoke them, when, and
under whose authority. A rule action is never a new code path: it reuses the module's own Action, so
an automated categorization and a hand-typed one run identical invariants.

# Responsibilities

The Automation Engine owns, and is the only component that owns, the following:

1. **Rule definition.** Persist user-defined and AI-suggested automations as
   `trigger → conditions → actions`, each rule versioned so an edit never rewrites the history of a
   run that already executed under an earlier definition.
2. **Trigger matching.** Subscribe to the platform's domain-event stream and the scheduler, and for
   each inbound trigger, find the active rules whose trigger type matches.
3. **Condition evaluation.** Evaluate each matched rule's condition tree deterministically against
   the trigger's payload and the (permission-checked) context it references — a pure, side-effect-free
   pass that either qualifies or disqualifies the rule for this trigger.
4. **Action dispatch through the autonomy gate.** For each action of a qualifying rule, consult
   `ai_autonomy_settings` for the action's permission key and either execute it immediately (`auto`),
   surface it as a non-committing suggestion (`suggest_only`), or open an approval request
   (`requires_approval`) — never deciding autonomy per-rule in a way that could override the gate.
5. **Guardrails.** Enforce the sensitivity floor: certain permission keys can never resolve to
   `auto` regardless of configuration, and certain action types are simply not offerable to the
   engine at all.
6. **Scheduling.** Register cron/time-based rules with the Laravel scheduler and fire them on time,
   tenant-scoped.
7. **Simulation.** Run any rule (or a proposed rule) in dry-run mode against real or sample triggers,
   producing the exact actions it *would* take without taking them.
8. **Execution logging & observability.** Record every evaluation and every action in
   `automation_runs`, emit metrics, and write an audit row for every committed effect.
9. **Failure handling.** Retry transient action failures with backoff, quarantine a rule that fails
   repeatedly, and never leave a partially-applied multi-action rule in an inconsistent state.

What it does not own: the autonomy *policy values* themselves (a company sets them; the engine reads
them), the approval chains a `requires_approval` action routes into (that is the Workflow Engine),
and the business meaning of any action (the owning module owns that).

# Domain Model

- **Automation rule** (`automation_rules`) — the current, active head of a rule: its name, trigger
  type, enabled flag, owner, origin (`user` or `ai_suggested`), and a pointer to its current version.
- **Automation rule version** (`automation_rule_versions`) — an immutable snapshot of the rule's
  `trigger`, `conditions`, and `actions` JSON at one point in time. Editing a rule writes a new
  version and re-points the head; a run always records which version it executed, so history is
  perfectly reconstructable.
- **Automation run** (`automation_runs`) — one execution record: which rule version fired, on which
  trigger, what the condition evaluation decided, what actions it attempted, how each action resolved
  through the autonomy gate, and the final outcome.

```
automation_rules (head) ──> automation_rule_versions (immutable snapshots)
        │  a trigger arrives
        ▼
   TriggerMatcher → ConditionEvaluator → ActionDispatcher ──> ai_autonomy_settings gate
        │                                                          │
        ▼                                          ┌──────────────┼───────────────┐
   automation_runs (log)                        auto           suggest_only   requires_approval
                                                   │               │               │
                                          module Action     AI suggestion   WORKFLOW_ENGINE
                                          (committed)        (no commit)     (202 → human)
```

A rule's `actions` array is ordered; the engine dispatches them in order and records each
independently, so a three-action rule where action 1 auto-commits and action 2 needs approval is a
normal, well-defined outcome — action 1's effect is real, action 2 is a pending approval, action 3
proceeds only if its own gate allows.

## Trigger types

| Trigger family | Examples | Source |
|---|---|---|
| `event` | `bill.created`, `invoice.posted`, `bank.transaction.imported`, `expense_claim.submitted` | the platform domain-event stream |
| `schedule` | `cron('0 1 1 * *')`, `daily`, `weekly`, `monthly` | the Laravel scheduler |
| `threshold` | "a customer's outstanding balance crosses its credit limit" | a derived-metric watcher event |
| `manual` | a user or agent explicitly runs a rule against a selection | `POST /api/v1/automation/rules/{id}/run` |

Every trigger carries its own `company_id`; the engine never infers tenant scope from anything else.

# Key Classes

## Services

`App\Services\Automation\AutomationEngine` — the entry point invoked by both the event-listener and
the scheduler. It matches, evaluates, and dispatches, wrapping the whole pass in an `automation_runs`
record.

```php
namespace App\Services\Automation;

use App\Data\Automation\TriggerContext;
use App\Models\Automation\AutomationRun;
use App\Repositories\Automation\AutomationRuleRepository;

final class AutomationEngine
{
    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly ConditionEvaluator $conditions,
        private readonly ActionDispatcher $dispatcher,
    ) {}

    /** Handle one trigger (event or schedule tick) for the active company. */
    public function handle(TriggerContext $trigger): void
    {
        foreach ($this->rules->activeFor($trigger->type, $trigger->companyId) as $rule) {
            $version = $rule->currentVersion;                 // immutable snapshot
            $run = AutomationRun::start($rule, $version, $trigger);

            try {
                if (! $this->conditions->matches($version->conditions, $trigger)) {
                    $run->finishSkipped('conditions_not_met');
                    continue;
                }

                foreach ($version->actions as $action) {
                    $outcome = $this->dispatcher->dispatch($action, $trigger, $rule);  // through autonomy gate
                    $run->recordAction($action, $outcome);
                }

                $run->finishCompleted();
            } catch (\Throwable $e) {
                $run->finishFailed($e);                        // retry/quarantine handled by the job layer
                throw $e;
            }
        }
    }
}
```

`App\Services\Automation\ConditionEvaluator` — a domain service that evaluates a rule's condition
tree (an AND/OR tree of typed comparators over trigger-payload fields and permission-checked context
lookups). It is deterministic and side-effect-free; it never mutates anything and never calls the AI
engine — a rule's *conditions* are rules, not model judgement (the same deterministic-policy stance
[../ai/workflows/EXPENSE_APPROVAL.md](../ai/workflows/EXPENSE_APPROVAL.md) takes for its policy
engine).

`App\Services\Automation\ActionDispatcher` — the guardrail chokepoint. For each action it resolves
the action's `permission_key`, consults `AutonomyGate`, and routes to `auto`, `suggest_only`, or
`requires_approval`. It is the only class that turns a rule's intent into an effect, so the gate can
never be bypassed by a rule definition.

`App\Services\Automation\AutonomyGate` — reads `ai_autonomy_settings` for a permission key in the
active company and applies the sensitivity floor before returning a resolution.

```php
namespace App\Services\Automation;

use App\Enums\Automation\AutonomyMode;

final class AutonomyGate
{
    /** Permission keys that can NEVER resolve to `auto`, whatever a company configures. */
    private const NEVER_AUTO = [
        'bank.payment.approve', 'bank.payment.approve.final', 'bank.transfer.execute',
        'payroll.release', 'tax.submit', 'accounting.journal.reverse',
    ];

    public function resolve(string $permissionKey, int $companyId): AutonomyMode
    {
        if (in_array($permissionKey, self::NEVER_AUTO, true)) {
            return AutonomyMode::RequiresApproval;         // sensitivity floor overrides configuration
        }

        return AutonomyMode::from(
            AiAutonomySetting::query()
                ->where('permission_key', $permissionKey)
                ->value('mode') ?? 'requires_approval',    // default-deny: unknown key ⇒ human-gated
        );
    }
}
```

The default for an unconfigured permission key is `requires_approval`, not `auto` — automation is
default-deny in the same spirit as the permission system.

## Actions

- `App\Actions\Automation\CreateRuleAction` / `UpdateRuleAction` — persist a rule and write a new
  immutable `automation_rule_versions` snapshot; `UpdateRuleAction` re-points the head and never
  edits a prior version.
- `App\Actions\Automation\SimulateRuleAction` — runs a rule (or an unsaved draft) in dry-run mode:
  matches, evaluates, and computes what each action *would* resolve to and produce, without
  dispatching any effect. Returns a structured preview.
- `App\Actions\Automation\ToggleRuleAction` — enable/disable/quarantine a rule.
- `App\Actions\Automation\RunRuleManuallyAction` — apply a rule to an explicit selection on demand.

The module Actions a rule ultimately invokes (`CreateJournalEntryAction`, `CategorizeBillAction`,
`SendNotificationAction`, …) are the modules' own — the engine composes them, it does not reimplement
them.

## Models

`App\Models\Automation\AutomationRule`, `AutomationRuleVersion`, `AutomationRun`, and
`App\Models\Ai\AiAutonomySetting` — all `BelongsToCompany`. `AutomationRuleVersion` and
`AutomationRun` are effectively append-only; a run is written once and finalized, never edited after
its terminal outcome.

## Jobs

- `App\Jobs\Automation\RunTimeTriggeredAutomations` (queue `default`, scheduled every minute) — the
  scheduler tick that fires `schedule`-triggered rules whose cron expression is due.
- `App\Jobs\Automation\ExecuteRuleAction` (queue `default` or `ai`) — the retryable unit that
  dispatches one heavy action off the request/tick thread; tenant-aware and idempotent per
  `(automation_run_id, action_index)`.
- `App\Jobs\Automation\QuarantineFailingRule` — disables a rule after `max_consecutive_failures` and
  alerts its owner.

## Events

`AutomationRuleCreated`, `AutomationRuleUpdated`, `AutomationRuleEnabled`, `AutomationRuleDisabled`,
`AutomationRuleQuarantined`, `AutomationRunCompleted`, `AutomationRunFailed`,
`AutomationActionProposed` (when an action resolved to `requires_approval`/`suggest_only`) — past
tense, carrying ids, broadcast where a user is watching (Events, Queues & Realtime).

## Policies

`App\Policies\Automation\AutomationRulePolicy` — `viewAny`/`view` (`ai.automation.read`), `create`
(`ai.automation`), `update`/`toggle` (`ai.automation`), `simulate` (`ai.automation.read`). Creating a
rule whose actions target a sensitive permission additionally requires that the creator personally
holds that permission — a user cannot automate an authority they do not themselves possess.

# Endpoints Backed (/api/v1)

Every response uses the platform envelope `{success, data, message, errors, meta, request_id,
timestamp}`.

| Method & path | Purpose | Permission | Success |
|---|---|---|---|
| `GET /api/v1/automation/rules` | List rules (filter by trigger, origin, enabled). | `ai.automation.read` | 200 |
| `POST /api/v1/automation/rules` | Create a rule (writes version 1). | `ai.automation` | 201 |
| `GET /api/v1/automation/rules/{id}` | Rule detail with current version and recent runs. | `ai.automation.read` | 200 |
| `PATCH /api/v1/automation/rules/{id}` | Edit (writes a new version, re-points head). | `ai.automation` | 200 |
| `POST /api/v1/automation/rules/{id}/enable` · `/disable` | Toggle a rule. | `ai.automation` | 200 |
| `POST /api/v1/automation/rules/{id}/simulate` | Dry-run against real/sample triggers; returns would-be actions. | `ai.automation.read` | 200 |
| `POST /api/v1/automation/rules/{id}/run` | Manually apply to a selection now. | `ai.automation` | 202 or 200 |
| `GET /api/v1/automation/rules/{id}/versions` | Version history of a rule. | `ai.automation.read` | 200 |
| `GET /api/v1/automation/runs` | Execution log (filter by rule, outcome, date). | `ai.automation.read` | 200 |
| `GET /api/v1/automation/runs/{id}` | One run: conditions decision, per-action outcomes, gate resolutions. | `ai.automation.read` | 200 |
| `GET /api/v1/ai/autonomy-settings` | Read the autonomy matrix (per permission key). | `ai.settings.read` | 200 |
| `PATCH /api/v1/ai/autonomy-settings` | Set a permission key to `auto`/`suggest_only`/`requires_approval`. | `ai.settings.manage` | 200 |

A `simulate` response is the trust-building surface — it shows a rule's blast radius before it is
armed:

```json
{
  "success": true,
  "data": {
    "rule_id": 204,
    "trigger": { "type": "event", "event": "bill.created", "sample_count": 37 },
    "would_match": 12,
    "actions_preview": [
      { "index": 0, "type": "categorize",  "permission_key": "accounting.entry.categorize", "gate": "auto",              "effect": "would set expense_account_id=5250 on 12 bills" },
      { "index": 1, "type": "post_journal", "permission_key": "accounting.journal.post",     "gate": "requires_approval", "effect": "would open 12 approval requests" }
    ],
    "committed": 0
  },
  "message": "Dry run only — no effects were applied.",
  "errors": [],
  "meta": {},
  "request_id": "b71e…",
  "timestamp": "2026-07-20T10:02:00Z"
}
```

# Database Tables Owned

All tables carry the platform standard columns and lead their indexes with `company_id`. The
`ai_autonomy_settings` table is co-owned with the AI layer conceptually but its authoritative
definition and read/write path live here, since this engine is its primary enforcer.

```sql
CREATE TYPE automation_origin AS ENUM ('user','ai_suggested');
CREATE TYPE automation_trigger_type AS ENUM ('event','schedule','threshold','manual');
CREATE TYPE automation_rule_status AS ENUM ('active','disabled','quarantined','draft');

-- The current head of a rule. Definition JSON lives on the versioned snapshot, not here.
CREATE TABLE automation_rules (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT       NOT NULL REFERENCES companies(id),
    branch_id           BIGINT       NULL REFERENCES branches(id),
    name_en             VARCHAR(160) NOT NULL,
    name_ar             VARCHAR(160) NOT NULL,
    description         TEXT         NULL,
    trigger_type        automation_trigger_type NOT NULL,
    trigger_key         VARCHAR(120) NOT NULL,     -- event name, cron expr, or metric key
    origin              automation_origin NOT NULL DEFAULT 'user',
    suggested_by_agent  VARCHAR(60)  NULL,         -- set when origin='ai_suggested'
    status              automation_rule_status NOT NULL DEFAULT 'active',
    current_version_id  BIGINT       NULL,         -- FK set after first version is written
    consecutive_failures SMALLINT    NOT NULL DEFAULT 0,
    last_run_at         TIMESTAMPTZ  NULL,
    created_by BIGINT NULL REFERENCES users(id), updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL
);
CREATE INDEX idx_autorules_trigger ON automation_rules (company_id, trigger_type, trigger_key)
    WHERE status = 'active' AND deleted_at IS NULL;

-- Immutable snapshot of a rule's definition. Editing a rule writes a new row and re-points the head.
CREATE TABLE automation_rule_versions (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT       NOT NULL REFERENCES companies(id),
    automation_rule_id BIGINT       NOT NULL REFERENCES automation_rules(id),
    version_no         INTEGER      NOT NULL,
    trigger            JSONB        NOT NULL,       -- {type, key, params}
    conditions         JSONB        NOT NULL,       -- AND/OR tree of typed comparators
    actions            JSONB        NOT NULL,       -- ordered [{type, permission_key, params}, ...]
    created_by         BIGINT       NULL REFERENCES users(id),
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_autover UNIQUE (automation_rule_id, version_no)
    -- no updated_at/deleted_at: versions are immutable
);
CREATE INDEX idx_autover_rule ON automation_rule_versions (automation_rule_id, version_no DESC);

CREATE TYPE automation_run_outcome AS ENUM ('completed','skipped','failed','partial');

-- One execution record. Written once, finalized once.
CREATE TABLE automation_runs (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT       NOT NULL REFERENCES companies(id),
    automation_rule_id     BIGINT       NOT NULL REFERENCES automation_rules(id),
    rule_version_id        BIGINT       NOT NULL REFERENCES automation_rule_versions(id),
    trigger_type           automation_trigger_type NOT NULL,
    trigger_ref            VARCHAR(160) NULL,       -- causing event_id / schedule tick id
    conditions_matched     BOOLEAN      NULL,
    outcome                automation_run_outcome NOT NULL,
    skip_reason            VARCHAR(120) NULL,
    action_results         JSONB        NOT NULL DEFAULT '[]',  -- per-action gate + effect + refs
    committed_count        SMALLINT     NOT NULL DEFAULT 0,
    proposed_count         SMALLINT     NOT NULL DEFAULT 0,     -- routed to approval/suggestion
    error                  TEXT         NULL,
    is_dry_run             BOOLEAN      NOT NULL DEFAULT false,
    correlation_id         UUID         NULL,
    started_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    finished_at            TIMESTAMPTZ  NULL,
    created_at             TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_autoruns_rule ON automation_runs (automation_rule_id, started_at DESC);
CREATE INDEX idx_autoruns_outcome ON automation_runs (company_id, outcome, started_at DESC);

CREATE TYPE ai_autonomy_mode AS ENUM ('auto','suggest_only','requires_approval');

-- The autonomy matrix: per company, per permission key, how autonomous the AI/automation may be.
CREATE TABLE ai_autonomy_settings (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT       NOT NULL REFERENCES companies(id),
    permission_key VARCHAR(120) NOT NULL,          -- e.g. 'accounting.entry.categorize'
    mode           ai_autonomy_mode NOT NULL DEFAULT 'requires_approval',
    max_amount     NUMERIC(19,4) NULL,             -- optional cap: auto only below this base-currency amount
    updated_by     BIGINT       NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_autonomy UNIQUE (company_id, permission_key)
);
CREATE INDEX idx_autonomy_company ON ai_autonomy_settings (company_id);
```

The `ai_autonomy_settings.max_amount` cap lets a company say "auto-post categorizations below KWD
100, but route anything larger to approval even for an `auto` key" — a second, amount-based safety
valve layered under the categorical `mode`. The `NEVER_AUTO` floor in `AutonomyGate` is not
representable as a row here on purpose: it is code, so no misconfiguration or malicious row can grant
auto-commit of a bank payment.

# Multi-Tenancy Enforcement

The engine is tenant-scoped identically to every other module:

- **Ambient scope.** All owned tables use `BelongsToCompany`; `CompanyScope` plus `SET LOCAL
  app.current_company_id` (RLS) mean a rule, a version, a run, and an autonomy setting are only ever
  visible and mutable within their company
  ([../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md),
  [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md)).
- **Triggers are matched per company.** `TriggerMatcher` selects `automation_rules` only for the
  trigger's own `company_id`; a domain event from company A can never fire company B's rule.
- **Scheduled runs re-bind tenant context.** `RunTimeTriggeredAutomations` iterates companies and,
  for each, runs inside a tenant-aware envelope that re-issues `SET LOCAL app.current_company_id`
  before evaluating any rule — a scheduler tick has no ambient request, so the tenant is set
  explicitly per company, mirroring the `TenantAwareJob` pattern in
  [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md).
- **Conditions cannot read across tenants.** A condition lookup resolves through the owning module's
  tenant-scoped read path only; there is no `withoutCompanyScope` route from a rule to another
  company's data.
- **The autonomy matrix is per company.** One company's `auto` setting for a permission key never
  affects another's; a new company inherits the platform default (`requires_approval`) until it
  configures otherwise.

# Events, Queues & Realtime

Rules react to the same canonical domain-event envelope every module emits
([../database/DATABASE_EVENTS.md](../database/DATABASE_EVENTS.md)); the engine is both a consumer (of
triggers) and a producer (of its own lifecycle events).

- **Consumed:** every `event`-type trigger a rule subscribes to (`bill.created`, `invoice.posted`,
  `bank.transaction.imported`, `expense_claim.submitted`, threshold-watcher events, …), delivered via
  a queued listener (`ShouldQueue`, queue `default`) so a slow rule never blocks the fast write that
  triggered it. Listeners are idempotent per `(rule_version_id, trigger_ref)` so an at-least-once
  redelivery cannot double-apply a rule.
- **Produced:** the lifecycle events listed under Key Classes, broadcast on
  `private-company.{id}.ai` (rule/run activity the AI console watches) and, when an action routes to
  a human, `private-company.{id}.approvals` (the resulting request) and
  `private-company.{id}.notifications` (the owner alert).
- **Queues:** `default` for evaluation and light actions; `ai` for actions that call the FastAPI
  engine (a categorization that needs a model suggestion); `realtime` for the broadcasts and
  notifications. Heavy actions dispatch as `ExecuteRuleAction` jobs so evaluation returns quickly.
- **Scheduler:** `RunTimeTriggeredAutomations` on `everyMinute()` and the housekeeping sweep on a
  slower cadence are registered in `routes/console.php` alongside the rest of the platform's
  scheduled work ([BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) `# Queues, Jobs, and the
  Scheduler`).

# Integrations

- **Workflow Engine.** The single most important integration: any action the autonomy gate resolves
  to `requires_approval` is handed to [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) via `ApprovalService`,
  which returns a `202`-style pending request. The automation records the resulting
  `approval_request_id` in its run log and stops there for that action — the human decides, and the
  Workflow Engine commits. The sensitivity floor here and the sensitivity floor there agree: a bank
  payment routed by an over-permissive rule still enters the two-key chain.
- **Owning modules.** An `auto` action invokes the module's own committing Action
  (`CreateJournalEntryAction`, `CategorizeBillAction`, `AdjustStockAction`) through the container,
  with an idempotency key of `automation:{run_id}:{action_index}` so a retried job never
  double-commits ([SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) `# Idempotency`).
- **AI engine (FastAPI).** Two directions. Inbound: the AI layer *suggests* rules — an agent that
  observes a repeated manual categorization proposes an `origin = 'ai_suggested'` rule that a human
  must review, enable, and (for any sensitive action) hold the underlying permission for. Outbound: an
  `ai`-queue action may call the engine for a model judgement (which category best fits this novel
  merchant) before applying a deterministic effect. The engine never writes the database directly;
  its suggestion becomes a proposal or a draft rule through the backend, per the AI boundary in
  [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) and
  [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md).
- **Audit Service.** Every committed effect and every rule/version/autonomy change writes an
  immutable audit row ([AUDIT_SERVICE.md](./AUDIT_SERVICE.md)); an AI-suggested rule and an autonomy
  change are among the higher-priority audit categories.

# Permissions

The gate that governs *who may automate* is the `ai.automation` permission family, in the platform
grammar `<area>.<action>` / `<area>.<entity>.<action>`
([../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)), default-deny.

| Permission | Grants |
|---|---|
| `ai.automation.read` | List/read rules, versions, runs; run simulations. |
| `ai.automation` | Create, edit, enable/disable, and manually run rules. |
| `ai.settings.read` | Read the `ai_autonomy_settings` matrix. |
| `ai.settings.manage` | Change an autonomy mode / cap for a permission key. |

Two escalation-of-privilege guards sit on top of the permission table. First, **you cannot automate
an authority you do not hold**: `CreateRuleAction`/`UpdateRuleAction` verify the creator personally
holds every `permission_key` any of the rule's actions target, so a clerk who lacks
`accounting.journal.post` cannot build a rule that auto-posts journals. Second, **you cannot raise
autonomy above your own reach**: setting a permission key to `auto` requires both `ai.settings.manage`
and personally holding that key — a company cannot delegate to automation what no configuring human
is themselves trusted to do. And beneath all of it, the `NEVER_AUTO` code floor means the most
sensitive keys cannot be set to `auto` by anyone, at any permission level.

# Error Handling

Typed exceptions map to the platform envelope through the global handler.

| Exception | Meaning | Status | `errors[].code` |
|---|---|---|---|
| `AutonomyEscalationException` | Creator lacks the action's target permission | 403 | `permission_denied` |
| `NeverAutoViolationException` | Rule attempts `auto` on a `NEVER_AUTO` key | 422 | `auto_forbidden` |
| `InvalidConditionTreeException` | Malformed/unsupported comparator | 422 | field-level codes |
| `InvalidCronExpressionException` | Bad schedule expression | 422 | `invalid_schedule` |
| `RuleQuarantinedException` | Attempt to run a quarantined rule | 409 | `rule_quarantined` |
| `ActionCommitFailedException` | An `auto` action's module Action threw | logged, run `partial` | the Action's own code |

Runtime failure handling is where the engine earns its keep:

- **Transient action failures** (a downstream service momentarily unavailable) are retried by the
  `ExecuteRuleAction` job with backoff (`[5, 30, 120]`s); the run is left `partial` until retries
  succeed or exhaust.
- **Multi-action atomicity.** Actions are dispatched in order and each is recorded independently; the
  engine does **not** wrap a whole multi-action rule in one transaction (action 1 may auto-commit
  while action 2 needs approval — they cannot share a commit boundary). Instead, each committing
  action is individually transactional and idempotent, and the run log records exactly which actions
  applied, so a failure at action 2 never rolls back action 1's legitimate effect and a retry resumes
  from the first unrecorded action.
- **Quarantine.** A rule that fails `max_consecutive_failures` times in a row is auto-disabled
  (`QuarantineFailingRule`) and its owner alerted, so a broken rule stops burning the queue rather
  than retrying forever.
- **Poison triggers** land in the event pipeline's `dead_letter_events` with the correlation id, per
  [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) `# Jobs and Queues`.

# Testing

- **Unit — `AutonomyGate`.** A `NEVER_AUTO` key resolves to `requires_approval` even when a row says
  `auto`; an unknown key defaults to `requires_approval`; `max_amount` routes an over-cap `auto`
  action to approval.
- **Unit — `ConditionEvaluator`.** AND/OR trees, each comparator type, and a condition referencing a
  field absent from the trigger payload (fails closed, does not throw).
- **Unit — versioning.** Editing a rule writes a new immutable version and re-points the head; a run
  records the exact version it executed; an old version is never mutated.
- **Feature — the gate end to end.** A rule with a `categorize` (auto) and a `post_journal`
  (requires_approval) action applies the first and opens an approval for the second in one run,
  recording both outcomes.
- **Feature — sensitivity floor.** A rule authored to auto-execute a bank payment is refused at
  creation (`auto_forbidden`) and, if configured through a back door, is still gated at dispatch.
- **Feature — dry run.** `simulate` produces the would-be actions and effects and commits nothing;
  `is_dry_run` runs never write to a business table.
- **Feature — scheduling & tenancy.** A cron rule fires once per due tick per company, re-binds
  tenant context, and never fires another company's rule.
- **Feature — idempotency.** A redelivered trigger does not double-apply a rule
  (`(rule_version_id, trigger_ref)` guard); a retried heavy action does not double-commit.
- **Feature — quarantine.** A repeatedly failing rule auto-disables and alerts its owner.
- **Audit assertions.** Every committed effect, every rule/version change, and every autonomy-matrix
  change writes an immutable audit row with the actor and correlation id.

An automation capability is not production-ready until it ships with its rule model, the autonomy
gate, simulation, permissions, audit coverage, and these tests — the MODULE_ARCHITECTURE release
checklist.

# Related Documents

- [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) — where the automation engine sits and how the scheduler drives it.
- [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) — the Actions a rule composes and the idempotency it relies on.
- [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) — where every `requires_approval` action is routed for a human decision.
- [AUDIT_SERVICE.md](./AUDIT_SERVICE.md) — the immutable trail of every automated effect and autonomy change.
- [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md), [../ai/workflows/EXPENSE_APPROVAL.md](../ai/workflows/EXPENSE_APPROVAL.md) — AI-suggested rules and the autonomy stance.
- [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md), [../api/INTERNAL_API.md](../api/INTERNAL_API.md), [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md)
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md), [../database/DATABASE_EVENTS.md](../database/DATABASE_EVENTS.md)

# End of Document
