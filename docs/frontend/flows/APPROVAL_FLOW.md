# Approval Flow — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Flows / APPROVAL_FLOW
---

# Purpose

This document specifies the end-to-end journey of a single AI-or-human approval proposal in the QAYD
web client, from the moment it comes into existence to the moment it is resolved — a proposal appears
(realtime over the `.approvals` channel plus a notification), the assigned reviewer opens it (in the
Approval Center, its detail route, an embedded `ApprovalCard`, or the `ApprovalDrawer`), reviews its
confidence, reasoning, and source citations, then approves, rejects, requests changes, or delegates it;
the two-key chain a sensitive subject type (a `bank_transfer`) requires; delegation to another eligible
approver; what commits on approve (the underlying module endpoint), what is recorded on reject; the
bulk-approval limits; stale/superseded handling; and the compliance-grade audit trail a company keeps
for the life of the tenant.

It is a *flow* document and defers, rather than duplicates: [`../APPROVAL_CENTER.md`](../APPROVAL_CENTER.md)
owns the full Approval Center screen (the unified queue, its filters, its detail route);
[`../components/DRAWERS.md`](../components/DRAWERS.md) owns the `ApprovalDrawer` — the in-place
approve/reject/delegate surface — and the drawer-vs-modal-vs-route decision;
[`../components/AI_WIDGETS.md`](../components/AI_WIDGETS.md) owns `ApprovalCard`, `ApprovalActionBar`,
`ConfidenceBadge`, and `ReasoningPanel`; and [`../../ai/workflows/EXPENSE_APPROVAL.md`](../../ai/workflows/EXPENSE_APPROVAL.md)
and the platform's [`../../foundation/PERMISSION_SYSTEM.md`](../../foundation/PERMISSION_SYSTEM.md)
`# Approval Chains` own the *server-side* chain resolution this flow renders. This document is
authoritative only for how those surfaces sequence into one reviewer journey and where it forks.

The founding rule every step below enforces: **AI proposes; a human, or an explicit and audited
policy, approves** ([`../README.md`](../README.md) `# Overview`, constraint 2). A proposal exists in
the approval queue *precisely because* it did not clear its company's autonomy threshold, or because
its subject type is one QAYD never auto-executes regardless of confidence. On this journey the only
vocabulary is Approve, Reject, Request Changes, and Delegate — **"Do it" never renders here**, for any
row, at any confidence ([`../APPROVAL_CENTER.md`](../APPROVAL_CENTER.md) `# AI Integration`).

# Actors & Preconditions

| Actor | Role in this flow |
|---|---|
| The reviewer | The user assigned the currently-pending step — by `approver_user_id` or a role match on `approver_role`. Holds `ai.approve` (and the step's own permission) to decide, or `reports.read` to view read-only. |
| The requester / originating agent | A human submitter (`requestedBy`) for a plain document, or an AI agent (`sourceAgentCode` — `TREASURY_MANAGER`, `PAYROLL_MANAGER`, `TAX_ADVISOR`, `GENERAL_ACCOUNTANT`, …) for an AI-originated request. |
| `APPROVAL_ASSISTANT` | Authors and routes every request, resolves the per-company chain at creation time, drives the SLA-escalation timers. Never approves anything itself. |
| `FRAUD_DETECTION` | The only other writer — and only to set `status = 'held'`, a safety brake that requires no human initiation. |
| The frontend | Renders the chain the API returns; re-checks the live target before every Approve; routes every decision through the same authoritative mutation the owning module uses. |

**Preconditions.**

- The reviewer is authenticated with an active `X-Company-Id`; the `(app)` shell's shared
  `RealtimeProvider` connection is open (this flow subscribes to `private-company.{id}.approvals`
  through it, never a second socket).
- The reviewer holds one of: `ai.approve` (act), `reports.read` (view read-only), or a step-level
  match on `approver_user_id`/`approver_role` for at least one open request
  ([`../APPROVAL_CENTER.md`](../APPROVAL_CENTER.md) `# Route & Access`).
- The proposal has been resolved server-side into the unified `ApprovalRequest` shape — the frontend
  never merges the two upstream engines (`ai_approval_requests`/`ai_approval_steps` and the older
  `approval_policies`/`approval_steps`/`approval_actions`); Laravel's `ApprovalService` normalizes both
  into one `GET /api/v1/approvals` envelope.

# Entry Points

Every entry point renders the same `ApprovalRequest` shape and mutates the same cache entry
(`approvalKeys.detail(id)`), so no two surfaces can disagree about a decision:

| Entry point | Surface | Notes |
|---|---|---|
| A notification (bell row or `/notifications`) | Embedded `ApprovalCard` inline, or deep-links to `/approvals/{id}` | The notification is a routing surface, never a bypass — see [`./NOTIFICATION_FLOW.md`](./NOTIFICATION_FLOW.md) |
| The Command Palette's live "N approvals waiting" | `/approvals` (queue) | Jumps straight to the top of the queue ([`../components/SEARCH_BAR.md`](../components/SEARCH_BAR.md)) |
| The Approval Center queue | `ApprovalCard` row (Card view) or `DataTable` row (Table view) | The everyday working surface for Owner/CFO/Finance Manager |
| A module's own record Detail page | Inline `ApprovalCard`, or the `ApprovalDrawer` opened over the list | Banking transfer, Payroll Approvals tab, Tax Return, Purchase Order, Journal Entry — same record, same query, same mutation |
| A shared/bookmarked URL | `/approvals/{id}` detail route | The full `ApprovalStepper` timeline |
| An assistant reply surfacing a pending approval | Embedded `ApprovalCard`, re-fetched live | See [`./AI_CHAT_FLOW.md`](./AI_CHAT_FLOW.md) Step 7b |

# Flow Overview

```mermaid
flowchart TD
    A[Proposal created\nAPPROVAL_ASSISTANT resolves chain] --> B[Reverb .approvals event\n+ notification fan-out]
    B --> C{Reviewer opens it}
    C -->|Queue| D[Approval Center row]
    C -->|Notification / deep link| E[/approvals/id detail]
    C -->|Module record| F[Inline ApprovalCard / ApprovalDrawer]
    D --> G[Review: confidence + reasoning + sources]
    E --> G
    F --> G
    G --> H[Re-fetch live target\ndiff vs proposal payload]
    H -->|Diverged| I[Approve replaced by\n'Review changed data']
    H -->|Unchanged| J{Decision}
    J -->|Approve| K{Multi-step chain?}
    J -->|Reject| L[Reason required\nchain terminates → rejected]
    J -->|Request Changes| M[Note required\n→ changes_requested]
    J -->|Delegate| N[DelegatePicker → reassign step]
    K -->|Final step| O[Request approved]
    K -->|More steps| P[Advance to next approver's step]
    O --> Q[Module's own pessimistic mutation\ncommits the money-moving action]
    L --> R[audit_logs entry recorded]
    M --> R
    N --> R
    O --> R
    P --> C
    Q --> R
```

Step map:

1. Proposal created; chain resolved server-side.
2. Delivered — realtime over `.approvals` + a notification.
3. Reviewer opens the request.
4. Review confidence, reasoning, source citations, the chain.
5. Live-target re-check before Approve.
6a–6d. Decide: Approve / Reject / Request Changes / Delegate.
7. Two-key chain advancement (sensitive subject types).
8. Commit — the module's own pessimistic mutation runs the money-moving action.
9. Audit trail recorded.

# Step-by-Step

## Step 1 — Proposal created

- **Origin.** An AI recommendation that did *not* clear its autonomy threshold, a fraud hold, or a
  human-submitted document needing sign-off. `APPROVAL_ASSISTANT` resolves the actual chain per company
  from that company's own `roles`/`permissions` at creation time (never hardcoded per subject type) and
  sets the SLA (default 4 business hours for `critical`-sourced, 2 business days otherwise).
- **Screen / route.** None yet — this is a server event.
- **API call.** The originating module's own mutation (e.g. an AI recommendation's `send-for-approval`)
  deposits an `ai_approval_requests` row; the frontend does not participate.

## Step 2 — Delivered

- **UI state.** The Approval Center's queue receives a `.approval.status_changed` (or created) event
  over `private-company.{id}.approvals`; a new `pending`/`held` row is patched into the cache in place
  (never a blunt full-list invalidation that would disrupt scroll or an in-progress selection). A
  notification fans out to the addressed reviewer's bell/inbox.
- **API call.** Realtime push only; the queue's `useApprovalsQueue` (`staleTime: 10_000`,
  `refetchOnWindowFocus`) reconciles.
- **Success branch.** The reviewer sees the item; proceed to Step 3.
- **Failure branches.** A dropped WebSocket → on reconnect, `approvalKeys.all` is invalidated once as a
  batch (a missed `held` transition is a correctness bug, never left to a manual refresh).

## Step 3 — Reviewer opens the request

- **Screen / route.** Per `# Entry Points`. A deep link with `?highlight={id}` scrolls the card into
  view and pre-expands its reasoning.
- **User action.** Clicks the row, the notification, the "Review in Approval Center" link, or opens the
  `ApprovalDrawer` over a module list.
- **UI state.** The `ApprovalCard`/detail renders: title, amount (plain ink, never washed `negative`
  for a raw magnitude), the requester or agent attribution, the SLA/urgency, and — for AI-originated
  rows — the `ConfidenceBadge`. A `held` row carries the `negative`-token left border and pins ahead of
  same-bucket items.
- **API call.** `GET /api/v1/approvals/{id}` (or the queue list already holds it).
- **Success/failure.** A `403` (id exists, viewer lacks access) names the missing permission; a
  nonexistent/cross-tenant id renders `not-found.tsx`, indistinguishable from the other.

## Step 4 — Review confidence, reasoning, sources, chain

- **UI state.** Expanding the `ReasoningDisclosure`/`ReasoningPanel` shows the full `reasoning` text and
  every `sources` entry as clickable citations to the exact underlying records (`journal_lines`,
  `bank_transactions`, `ai_decisions`) — never a bare summary invented for this screen. The
  `ApprovalStepper` renders the chain: each step's `approver_role`, its `status`, and (once decided)
  its `comment`; only the step that is `pending` and assigned to *this* reviewer renders interactive.
- **API call.** Citations are `<Link>` navigations; no additional fetch for the review itself.
- **Success branch.** Proceed to Step 5.
- **Failure branches.** A human-submitted row (`sourceAgentCode: null`) simply omits `ConfidenceBadge`
  rather than showing a fake/zero confidence.

## Step 5 — Live-target re-check before Approve

- **UI state.** Immediately before rendering the Approve action, the client re-fetches the request's
  live target record and diffs it against the proposal's stored payload.
- **API call.** `GET /api/v1/approvals/{id}` (`staleTime: 0`) plus the target's own live read.
- **Success branch.** Unchanged → the Approve action renders; proceed to Step 6.
- **Failure branches.** Diverged (someone edited the beneficiary IBAN, a PO's lines changed after it
  entered the chain) → Approve is **replaced** with "Review changed data," never a silent approval of
  stale intent.

## Step 6 — Decide

### 6a — Approve

- **UI state.** A single click for a single-step request; for a chain, only the reviewer's own step is
  interactive (others are read-only with a tooltip naming the outstanding approver).
- **API call.** `POST /api/v1/approvals/{id}/approve`, body `{ comment? }`, mandatory `Idempotency-Key`
  (a slow spinner + second tap can never double-approve). Optimistic: `onMutate` flips that step's
  status — and, if it was the final step, the whole request's status — in the cache immediately.
- **Success branch.** Final step → the request resolves (`approved`); non-final → the chain advances to
  the next approver's step (Step 7). Deciding is optimistic because it is *reversible-adjacent* — a
  wrong approval can still be caught at the next stage — and none of the four verbs itself moves money.
- **Failure branches.** `4xx/5xx` → optimistic rollback + toast. A conflict (another tab/user advanced
  the step) → re-fetch fresh rather than trusting stale local state.

### 6b — Reject

- **UI state.** An `AlertDialog` collecting a **mandatory reason**; "Confirm reject" stays disabled
  until the field is non-empty.
- **API call.** `POST /api/v1/approvals/{id}/reject`, body `{ reason }`. Rejecting at *any* step
  **terminates the entire chain** (`status → 'rejected'`), never merely that step — a rejected request
  is dead; a revised proposal re-enters as a new request. The reason is recorded (and, for an
  AI-originated request, fed to `ai_memory`).
- **Success/failure.** As Approve's optimistic pattern.

### 6c — Request Changes

- **UI state.** The verb QAYD distinguishes carefully from Reject ("send back for revision" ≠ "kill
  it"). An `AlertDialog` collects a **mandatory note**.
- **API call.** `POST /api/v1/approvals/{id}/request-changes`, body `{ note }`, `status →
  changes_requested`. For an AI-originated proposal the note feeds `ai_memory` and the originating
  agent's next run; for a human-submitted document it surfaces on that document's own Detail page as an
  addressed revision request, and resubmitting opens a *fresh* request rather than mutating the
  `changes_requested` one in place.

### 6d — Delegate

- **UI state.** Rendered only when the reviewer is the assigned approver for the currently-pending step
  — never a general "reassign anyone's step" affordance. Opens `DelegatePicker`, scoped to users who
  already hold the step's `approver_role` or an equivalent grant.
- **API call.** `POST /api/v1/approvals/{id}/delegate`, body `{ user_id }`. The API rejects an
  ineligible target with a field-level `422`; the picker also filters client-side so an ineligible name
  is never offered. A delegated step records **both** the original `approver_user_id` and the delegate
  — delegation is a *reassignment with attribution*, never an anonymous hand-off.
- The Page Header's "Delegation" action opens a `Sheet` of the reviewer's own standing delegations
  (e.g. a Finance Manager delegating all Purchasing-tier approvals to a deputy for a fixed date range)
  alongside one-off inline delegations.

## Step 7 — Two-key chain advancement (sensitive subject types)

- **Context.** A `bank_transfer` request's decision gate is finer-grained than its request-level
  `requiredPermission` fallback. `ApprovalStep` carries an optional `stepPermission` checked *in place
  of* the request's own permission for that one step: a first-tier step is decided by
  `bank.payment.approve`, the second/final tier by `bank.payment.approve.final` (the canonical "Bank
  Transfer via Finance Manager → CEO" chain of
  [`../../foundation/PERMISSION_SYSTEM.md`](../../foundation/PERMISSION_SYSTEM.md) `# Approval Chains`).
  `bank.transfer` itself gates *initiating* a transfer, not approving one.
- **UI state.** The Finance Manager approving step 1 advances the chain; the CEO is the only reviewer
  for whom step 2's Approve is interactive; everyone else sees step 2 read-only with a tooltip naming
  the outstanding approver. `ApprovalStepper` renders exactly the `steps[]` the API returns — it never
  assumes a fixed step count or role sequence.
- **API call.** Each step is its own `POST /api/v1/approvals/{id}/approve`, gated by that step's
  `stepPermission`.
- **Success branch.** The final step's approval resolves the request → Step 8.

## Step 8 — Commit (the money-moving action)

- **Key rule.** Approving here **authorizes** but does not itself perform the money-moving action.
  Optimism is calibrated to what the action does, not who proposed it: the four decision verbs are
  optimistic; the action that actually moves money — Banking's transfer initiation once `bank_transfer`
  clears its final step, Payroll's disbursement once `payroll_release` posts, Tax's `POST
  /api/v1/tax/returns/{id}/file` once `tax_submission` clears — is a **separate, pessimistic** mutation
  on that module's own screen ([`../README.md`](../README.md); "optimistic where safe, pessimistic
  where it moves money"). Approving a `tax_submission` here is what authorizes Tax's own "File Return"
  action to run — the same `tax.submit` permission and the same terminal transition, reached from two
  screens, never two gates that could disagree.
- **API call.** The module's own endpoint, pessimistic (awaits `2xx`), with its own `Idempotency-Key`.

## Step 9 — Audit trail

- Every decision (who, when, reasoning, delegation attribution) is written to `audit_logs` and is
  retrievable there in full for any single record — the durable source of truth, not a one-off print of
  this screen's chrome. A compliance-grade export of the resolved history is a separate `GET
  /api/v1/approvals/export` (permission `reports.export`), always rendered in a fixed light/print
  palette regardless of the requester's in-app theme.

# Happy Path

`FRAUD_DETECTION` places a `held` on a `KWD 18,540.000` payment to Al-Fajr Cement Supplies — a vendor
bank-detail change two hours before the scheduled run. The held row arrives over `.approvals`, patches into
Mariam's (CFO) queue in place with a `negative` left border pinned to the top of "Breaching SLA," and a
bell notification fires. She opens it, expands the reasoning (a `96% · High confidence` fraud narrative with
a `bank_transactions` citation), phones the vendor, and confirms the new IBAN is legitimate. The live-target
re-check shows the beneficiary unchanged since the hold, so Approve renders. She approves step 1
(`bank.payment.approve`); the chain advances to the CEO for `bank.payment.approve.final`. Fahad (Owner/CEO)
approves the final step from his phone — biometric re-confirmation first — and the request resolves to
`approved`. That authorization lets Banking's own pessimistic "Release payment" action run on the Banking
screen; the transfer settles, `audit_logs` records both signatures and the fraud-hold context, and the
notification threads close.

# Alternate & Error Paths

| Path | Trigger | Behavior |
|---|---|---|
| Reject | Reviewer kills the request | Mandatory reason; whole chain terminates → `rejected`; reason recorded (and fed to `ai_memory` for AI-originated rows); a revised proposal re-enters as a new request. |
| Request Changes | Reviewer sends back for revision | Mandatory note; `changes_requested`; note routes to `ai_memory`+agent (AI) or the document's Detail page (human); resubmit opens a fresh request. |
| Delegate | Reviewer reassigns their step | `DelegatePicker` scoped to eligible holders; `422` on an ineligible target; both original and delegate recorded for audit. |
| Held row | `FRAUD_DETECTION` sets `held` | `negative` left border, pinned ahead of same-bucket items; Approve stays present (a verified payment can still proceed), but the hold is lifted only by `FRAUD_DETECTION` re-evaluating or an explicit "Clear hold" on the source record — never by an ordinary Approve click alone. |
| Stale / superseded target | Underlying record edited after entering the chain | Approve replaced by "Review changed data" (Step 5). |
| Stale via another tab/user | The step advanced elsewhere before this click | The idle action fails with a conflict; the UI re-fetches fresh rather than trusting local state. |
| Bulk-approve partial failure | A selected row's eligibility changes before submit | The batch processes every still-eligible id; `failed[]` names which did not go through and why → partial-success warning toast, never a silent partial failure or a full-batch rejection over one row. |
| Permission revoked mid-session | Role change removes `ai.approve` | The next request carrying a stale claim gets `401 PERMS_STALE`, forcing a session re-establishment rather than rendering action buttons a fresh check would reject. |
| WebSocket drop | Extended disconnect | On reconnect, `approvalKeys.all` invalidated once as a batch — a missed hold/advancement surfaces immediately. |
| Delegated approver later removed | Open delegation points at a now-invalid user | The step's Delegate action is re-offered on next load; the original delegation record is retained for audit regardless of the delegate's current membership. |

# Bulk approval

Selecting rows (checkbox in Table view, long-press in Card view) surfaces the Bulk Action Bar with the
single bulk-safe action ("3 selected · Approve · Clear"). A row's checkbox is **disabled** (with a
tooltip) whenever its `requiredPermission` is one of `bank.transfer`, `payroll.run.approve`,
`tax.submit`, or whenever it is `held` — the `SENSITIVE_PERMISSIONS` gate. A valid selection must
additionally share one `subjectType` (the "Approve" button is *absent*, not disabled, for a mixed-type
selection) and sit below that type's configured value threshold. "Approve" opens one `AlertDialog`
naming the exact count and total ("Approve 6 expense reclassifications — KWD 1,140.000 total?") before
`POST /api/v1/approvals/bulk-approve` `{ ids }` fires. Bulk approve is **never optimistic** (a
multi-row financial decision), even though a single Approve is; every id is still individually
audit-logged. There is deliberately no bulk export of in-flight rows (a partial CSV of decisions still
in motion has no legitimate downstream use).

# Data & State

```ts
// types/approvals.ts (shape reviewers act on — from APPROVAL_CENTER.md)
export type ApprovalStatus =
  | 'pending' | 'held' | 'in_review' | 'approved'
  | 'rejected' | 'changes_requested' | 'expired' | 'cancelled';
```

| Purpose | Endpoint | Method | Permission | Cache / mutation |
|---|---|---|---|---|
| Queue | `/api/v1/approvals` | GET | `ai.approve` (act) / `reports.read` (view) | `approvalKeys.queue(filters)`, `staleTime: 10_000`, `keepPreviousData` |
| Detail / inline re-check | `/api/v1/approvals/{id}` | GET | Same or a step match | `approvalKeys.detail(id)`, `staleTime: 0` before Approve |
| Approve | `/api/v1/approvals/{id}/approve` | POST | Step `stepPermission` or request `requiredPermission` | Optimistic; `Idempotency-Key` |
| Reject | `/api/v1/approvals/{id}/reject` | POST | Same | Body `{ reason }` mandatory; terminates chain |
| Request Changes | `/api/v1/approvals/{id}/request-changes` | POST | Same | Body `{ note }` mandatory; `→ changes_requested` |
| Delegate | `/api/v1/approvals/{id}/delegate` | POST | Same | Body `{ user_id }`; eligibility-checked |
| Bulk approve | `/api/v1/approvals/bulk-approve` | POST | Uniform `requiredPermission` | Body `{ ids }`; **not** optimistic |
| Resolved history | `/api/v1/approvals` (cursor) | GET | Same | `approvalKeys.history(filters)`, `useInfiniteQuery` |
| Compliance export | `/api/v1/approvals/export` | GET | `reports.export` | Always light/print palette |
| Commit (per module) | e.g. `/api/v1/tax/returns/{id}/file` | POST | The module's own key | **Pessimistic**, separate screen |

**Query invalidations.** `useApproveRequest`/`useRejectRequest`/`useRequestChanges`/`useDelegateRequest`
each `onSettled`-invalidate `approvalKeys.all` and the dashboard's urgent-actions badge (`['dashboard']`).
`useBulkApprove` invalidates the same on settle. Approving from the detail route and from a queue row
mutate the identical `approvalKeys.detail(id)`, so neither view can show a decision the other has not.

**Realtime.** `private-company.{id}.approvals` (shared connection). A `held` transition patches the one
affected row in place (`setQueryData` + `invalidateQueries({ refetchType: 'none' })`) rather than
yanking the visible list; any other status change invalidates normally. A push that lands mid-review
surfaces as a polite "1 request now held — Refresh" banner, never a focus-yanking splice.

# AI Touchpoints

- **Confidence + reasoning + sources on every AI-originated row**, via `ConfidenceBadge` (normalized
  0–1 through `normalizeConfidence(score, 'percentage')`) and `ReasoningDisclosure` — never a bare
  percentage. A human-submitted row omits confidence entirely rather than faking it.
- **"Do it" never renders here.** Everything on this journey has already crossed from "AI could act" to
  "a human must decide"; the three-button pattern does not apply. The only verbs are Approve, Reject,
  Request Changes, Delegate.
- **A held row is a safety brake, not merely "urgent."** `FRAUD_DETECTION`'s `held` is the one AI
  action requiring no human initiation (it *prevents* money from moving). It is `negative`-token,
  pinned, and lifted only by re-evaluation or an audited "Clear hold" — never accidentally waved
  through by an ordinary Approve.
- **The accent stays reserved for AI provenance and the one primary action** — never status. A held row
  is `negative`, not accent, even though the hold is AI-originated: "the AI touched this" and "this is
  urgent" stay two independent signals.
- **Disagreement/chains are configurable per company, not baked in.** `ApprovalStepper` renders the
  `steps[]` the API returns, whether a single Accountant sign-off or Payroll's Payroll Officer →
  Finance Manager → CEO chain.

# Permissions

| Step | Permission | Enforcement |
|---|---|---|
| View the queue read-only | `reports.read` | Client renders read-only; Auditor/External Auditor never see an action row |
| Act on a step | `ai.approve` **and** the step's `stepPermission`/`requiredPermission` | UI disables non-turn/non-permitted steps with a naming tooltip; server `403` is final |
| Approve a `bank_transfer` step 1 / final | `bank.payment.approve` / `bank.payment.approve.final` | Two-key chain; each step checks its own `stepPermission` |
| Delegate | Reviewer is the assigned approver + target holds the step's role/grant | UI offers only when assigned; `422` on ineligible target |
| Bulk approve | Uniform `requiredPermission`; sensitive keys and `held` rows excluded | Checkbox-level disable, not merely bar-level |
| Commit the money-moving action | The module's own key (`bank.transfer`, `payroll` disbursement, `tax.submit`) | Separate pessimistic mutation on the module screen |
| Compliance export | `reports.export` | On the resolved-history view only |

An action **withheld** for a business-state reason (not yet your turn) is disabled-with-tooltip; a
whole card **read-only** for a permission reason names the missing permission; the two are never
conflated ([`../APPROVAL_CENTER.md`](../APPROVAL_CENTER.md) `# Accessibility`).

# i18n & RTL

- **AI-authored `reasoning` is never machine-translated by the frontend** — it arrives already
  localized per the API's content-negotiation contract; the client localizes only chrome (button
  labels, filter labels, empty-state copy, tooltips).
- **`dir="rtl"` once on `<html>`**; the Filter Bar order, `ApprovalStepper` progress direction, the
  Bulk Action Bar's trailing controls, and the Delegation `Sheet`'s slide-in edge all mirror via
  logical utilities with zero conditional code.
- **Three things never mirror.** Every monetary figure, percentage, confidence score, and SLA
  countdown renders inside a `dir="ltr"` span with `numberingSystem: 'latn'` ("اعتماد 2 من 2" keeps
  Latin digits); directional icons flip (`ApprovalStepper`'s arrow, Filter Bar chevrons) while the
  held-row border, a checkmark, and the AI mark do not; the swipe-to-decide gesture on touch is defined
  relative to the *logical* end (physically left-to-right in English, right-to-left in Arabic).
- Bilingual master-data (`name_en`/`name_ar` on a cited vendor/account) is picked at render time by
  `useLocale()`, so the queue and the target record never show mismatched names.
- Arabic microcopy is authored directly: *الاعتمادات*, *طلباتي / كل الطلبات*, *اعتماد / رفض / طلب تعديل
  / تفويض*, *لا يوجد شيء بانتظارك*.

# Accessibility

- **Landmarks and headings.** One `<h1>` ("Approvals"); each urgency group is a real `<h2>` (via
  `VisuallyHidden` where no visible label exists), so a screen-reader heading list enumerates the same
  groups a sighted user scans.
- **Live regions — auto-detect, never alarming.** A Reverb push flipping a row to `held` mid-review
  never yanks focus or re-sorts under the reader; it surfaces as a polite (`aria-live="polite"`)
  dismissible "1 request now held — Refresh" banner. The Bulk Action Bar's appearance announces once via
  `role="status"` ("3 selected — Approve or Clear").
- **Every disabled control explains itself, and the two reasons are never conflated.** A step's Approve
  disabled because it is not yet your turn, a checkbox disabled for a `SENSITIVE_PERMISSIONS` key, and a
  whole card read-only for a missing `stepPermission` are three distinct conditions, each with its own
  `aria-describedby` sourced from the permission system.
- **Bulk selection is real, tri-state, identity-tied.** The header checkbox is `aria-checked="mixed"`
  for a partial selection; each row checkbox has an accessible name tied to the request ("Select
  approval: July vendor payment run").
- **Focus continuity.** `Tab` follows visual reading order; `ApprovalStepper` is arrow-key navigable
  inside a `role="list"`; the Reject/Request-Changes `AlertDialog`s trap focus and return it to the
  triggering card's action row on close, never to the page top. The swipe gesture is additive — the
  Approve/Reject buttons are always present and are the only path for a screen-reader, switch-control,
  or `prefers-reduced-motion` user.

# Edge Cases

| Edge case | Behavior |
|---|---|
| Abandonment | Leaving a partially-reviewed request persists nothing; the queue re-fetches fresh on return. |
| Resume / back button | The detail route and queue row mutate the same cache entry; returning shows the current server state, not a stale mount snapshot. |
| Stale / superseded proposal | Live re-check before Approve replaces it with "Review changed data" on divergence. |
| Double-action | Every decision carries a client-generated `Idempotency-Key`; a slow spinner + second tap cannot double-approve. |
| Bulk-approve eligibility change | The batch processes still-eligible ids; `failed[]` names the rest → partial-success toast. |
| Two tabs, one advances step 1 | The idle tab's next action fails with a conflict; the UI re-fetches fresh. |
| Permission revoked mid-session | `401 PERMS_STALE` forces a session re-establishment before stale action buttons can be trusted. |
| WebSocket drop then reconnect | `approvalKeys.all` invalidated once as a batch — a missed hold surfaces immediately. |
| Company switch with the screen open | `queryClient.clear()` + `router.refresh()`; the queue re-mounts under the new `X-Company-Id` — a stale cross-company approval row would leak one tenant's decision to another and is prevented. |
| Held row's Approve | Present so a verified payment can proceed, but the hold is lifted only by re-evaluation/"Clear hold", never by the Approve click alone. |
| Delegated approver removed | Delegate re-offered on next load; the original delegation retained for audit. |
| Compliance export | Separate `reports.export` action on the resolved history, always light/print palette; never the deliberately-omitted in-flight bulk export. |

# End of Document
