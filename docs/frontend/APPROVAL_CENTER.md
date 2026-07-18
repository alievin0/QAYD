# Approval Center — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: APPROVAL_CENTER
---

# Purpose

This document is the frontend implementation specification for QAYD's Approval Center — the single, cross-module inbox every sensitive action in the platform routes through before it becomes real. It is the full-screen, exhaustive counterpart to the compact `approval_center_queue` widget `docs/frontend/AI_COMMAND_CENTER.md → Approval Center` already renders on the Command Center home screen: the same tables (`ai_approval_requests`, `ai_approval_steps`), the same primary endpoint (`GET /api/v1/approvals`), the same realtime channel (`private-company.{id}.approvals`), and the same `ApprovalCard` component render both surfaces, so nothing about what an approval *is* is redefined here. What this document adds is everything the Command Center's four-row, top-N widget deliberately does not attempt: a filterable, sortable, paginated view of every pending, held, and recently-resolved request across every module; bulk action on the safe subset of a large backlog; delegation as a first-class, auditable action rather than a single-row affordance; a permanent, bookmarkable destination that a notification, a Command Palette result, or an inline `ApprovalCard`'s "View in Approval Center" link can always land on; and a compliance-grade resolved/expired/cancelled history a company keeps for the life of the tenant. Where `docs/ai/AI_COMMAND_CENTER.md → Approval Center` is authoritative for *what an approval means and what the AI is allowed to do*, and `docs/frontend/AI_COMMAND_CENTER.md` is authoritative for *how the compact widget renders on the dashboard*, this document is authoritative for *how the full, everyday working surface behaves* — and it inherits every cross-cutting rule `docs/frontend/FRONTEND_ARCHITECTURE.md`, `docs/frontend/DESIGN_LANGUAGE.md`, `docs/frontend/COMPONENT_LIBRARY.md`, `docs/frontend/NAVIGATION_SYSTEM.md`, and `docs/foundation/PERMISSION_SYSTEM.md` already establish rather than re-deriving any of them.

The Approval Center exists because QAYD's founding rule — "AI proposes; a human, or an explicit and audited policy, approves" (`docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 3`) — has to hold up under real operating volume, not just in a single worked example. Fahad Al-Ostath, Owner of Al-Noor Trading & Contracting W.L.L. (`company_id: 4821`), does not review one payment run a morning; on a busy week he, Mariam Al-Sabah (CFO, `user_id: 118`), and Khalid Marafie (Finance Manager) are collectively clearing a dozen or more items a day across five different modules — a vendor payment run, a payroll release, a VAT return about to be filed, a purchase order over its auto-approval threshold, a journal entry a Senior Accountant drafted that needs a peer's sign-off, a credit hold Fraud Detection placed on a customer, a bank-detail change on a vendor. Every one of those items already has its own inline `ApprovalCard` on its own record's Detail page — Banking's transfer screen, Payroll's Approvals tab, the Tax Return detail page, the Purchase Order detail page, the Journal Entry detail page — and every one of those inline cards and this screen's rows are the *same* record, read through the *same* query, decided through the *same* mutation. Nothing in QAYD ever has two independent approval paths that could silently disagree; the Approval Center is simply the one place all of them are visible together, groupable, filterable, and actionable in bulk where it is safe to be.

This document covers the full lifecycle a reviewer needs from this screen: the unified queue and its filters, reviewing a single request's proposal/confidence/reasoning/sources, the four possible human responses (Approve, Reject, Request Changes, Delegate), multi-step approval chains and how a step's own assigned approver and required permission are resolved, bulk action on the safe subset of a selection, and the resolved/expired/cancelled history a compliance review or an external auditor needs months later. It targets the same register as the Command Center document it extends: precise, implementation-ready, and free of any UI affordance that could make a sensitive action feel one click closer to auto-executing than the platform's own rules allow.

# Route & Access

The screen renders at `app/(app)/approvals/page.tsx`, with a companion detail route at `app/(app)/approvals/[approvalId]/page.tsx` for a single request opened directly from a notification, a Command Palette result, an inline `ApprovalCard`'s deep link, or a shared URL — both routes sit inside `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own App Router tree and inside the dedicated `components/approvals/` folder its Folder Structure section already reserves for this screen's own composed components, alongside `components/dashboard/` for the Command Center's widgets:

```
├── approvals/
│   ├── page.tsx                    # The Approval Center queue — GET /api/v1/approvals
│   └── [approvalId]/page.tsx       # Single-request detail — GET /api/v1/approvals/{id}
```

`docs/frontend/NAVIGATION_SYSTEM.md → Primary Navigation` is explicit that this route is **not nested under `/ai`** even though `ai_approval_requests` is an AI Command Center table: "Approval Center — `/approvals` (not nested under `/ai`; a cross-module queue every sensitive action from every module routes through) — visible to any approver on a pending item, or to `ai.approve` broadly." Consistently, Approval Center is not one of the ten primary Sidebar modules (Dashboard, Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI) enumerated in `docs/frontend/NAVIGATION_SYSTEM.md → Primary Navigation` — it has no icon-rail entry of its own. It is reached, by design, only through entry points that already know an approval is waiting: the Command Palette's "AI & Actions" group, whose live count ("3 approvals waiting" jumps straight to `/approvals`, per `docs/frontend/NAVIGATION_SYSTEM.md → Command Palette`'s own worked example); a Notifications bell row whose `target` resolves to `/approvals/{id}` for a request addressed to the current user; any module's own inline `ApprovalCard`, whose title links to the record's own Detail page and whose overflow action additionally offers "View in Approval Center"; and, for the handful of roles (Owner, CFO, Finance Manager) who work this screen as a first-class daily surface, a browser bookmark or the URL typed directly — all of which is consistent with `docs/frontend/NAVIGATION_SYSTEM.md → Permission-Aware Nav`'s stance that navigation is a UX courtesy, never the access boundary itself.

Two permissions govern the screen, exactly as `docs/ai/AI_COMMAND_CENTER.md → Approval Center`'s data-source line states: **`ai.approve`** to act on any request the viewer's role is eligible to decide, and **`reports.read`** to view the full queue read-only without being able to decide anything. A third, narrower condition sits underneath both: a user who holds neither broad permission but who is the named `approver_user_id` (or whose role matches a pending step's `approver_role`) on at least one open request still sees the screen, scoped to exactly the items addressed to them — mirroring the identical scoping `docs/frontend/PAYROLL.md`, `docs/frontend/BANKING.md`, and `docs/frontend/TAX.md` already apply to their own inline `ApprovalCard`s ("only at the viewer's currently-pending stage"). A user matching none of the three sees the shared forbidden boundary rendered by the module-layout `error.tsx` — "You don't have access to this" with a link back to the Command Center — never a silent redirect that could be mistaken for the page simply not existing, per `docs/frontend/NAVIGATION_SYSTEM.md → Server truth, client courtesy`.

| Role | What they see | What they can do |
|---|---|---|
| Owner, CFO | Every request across every module, every status, by default scoped to `scope=all` | Approve/Reject/Request Changes/Delegate on any step assigned to their own role; bulk-approve where eligible |
| Finance Manager, Senior Accountant | Every request, but `scope=mine` (their own pending steps) by default, toggleable to `scope=all` read-only for anything not addressed to them | Full action set on their own assigned steps only |
| Accountant, Purchasing/Sales/Inventory/Payroll module roles | Only requests where they are `requested_by` (tracking their own submission's status) or a named future-step approver they can preview | Read-only everywhere except an explicit Delegate they were the recipient of |
| Auditor, External Auditor | Every request, every historical state, permanently read-only | None — no `ApprovalCard` on this screen ever renders an action row for these roles, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s Principle 4 applied literally: read access and decision access are always two separately-checked permissions here, never implied by each other |
| AI service account | Never renders this screen as a "user" | Structurally incapable of holding `ai.approve` or any subject-specific approval permission, regardless of role grant — the same rule `docs/frontend/BANKING.md` and `docs/frontend/TAX.md` state for their own AI service-account rows |

Dana Al-Rashidi's time-boxed External Auditor grant (`docs/frontend/NAVIGATION_SYSTEM.md`'s own worked example, `user_id: 155`) is the canonical read-only case this table encodes: she sees the entire history — pending, held, and every resolved outcome back to the company's onboarding — but never an action row, matching the read-vs-decide split her own Sidebar already enforces one level up ("Accounting — read-only... the `PageHeader`'s 'New Journal Entry' action slot is absent"). The breadcrumb is a single, module-rooted segment — "Approvals" (لوحة الاعتمادات) — with no parent crumb, the identical treatment `docs/frontend/NAVIGATION_SYSTEM.md → Breadcrumbs & Page Headers` gives the Command Center's own home route for Fahad ("single segment — the home route never grows a longer trail"), because a cross-module queue does not conceptually descend from any one of the ten modules it aggregates.

# Layout & Regions

The screen is an instance of `docs/frontend/LAYOUT_SYSTEM.md`'s **List Page Template**, extended rather than bypassed, per that document's own rule that "a screen never invents a sixth layout shape; if a new screen doesn't fit, the template is extended (a new named slot), not bypassed." Two extensions are specific to this screen: the Data Region defaults to grouped `ApprovalCard` stacks (matching the compact widget's established SLA-urgency grouping) rather than a flat table, and the Filter Bar's density toggle additionally switches the Data Region's *shape*, not just its row height — "Card view" (default) versus "Table view" (a `DataTable` of the identical rows, for a Finance Manager triaging thirty items in one sitting).

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Approvals                                    14 pending · 2 breaching   │
│ [My queue ▾]                                        [Delegation ⚙]      │
├─────────────────────────────────────────────────────────────────────────┤
│ [Search…] [Type ▾][Status ▾][Urgency ▾][Amount ▾]      [▤ Card|Table][⚙]│
├─────────────────────────────────────────────────────────────────────────┤
│  ── Breaching SLA ──────────────────────────────────────────────────    │
│  ┃ Payment to Al-Fajr Cement Supplies — held               [HELD]       │
│  ┃ KWD 18,540.000 · Fraud Detection · SLA breached 09:40                │
│  ┃ [View reasoning]                          [Reject] [Approve]         │
│  ── Due today ──────────────────────────────────────────────────────    │
│  July vendor payment run (6 vendors)             Approval 2 of 2        │
│  KWD 24,180.000 · Treasury Manager · Approval step: Mariam Al-Sabah, CFO│
│  [Delegate] [Reject] [Approve]                                          │
│  JE-2026-07-0482 · Rent accrual                  Approval 1 of 1        │
│  KWD 3,200.000 · Submitted by S. Rahman                                 │
│  [Reject] [Request changes] [Approve]                                   │
│  ── Due this week ──────────────────────────────────────────────────    │
│  …                                                                       │
├─────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 41 pending          [‹ Prev] [Next ›]   [Include resolved]│
└─────────────────────────────────────────────────────────────────────────┘
```

Region by region:

- **Page Header.** Title, a live count badge ("14 pending · 2 breaching" — the second clause omitted entirely when zero, never shown as "0 breaching," per `docs/frontend/DESIGN_LANGUAGE.md`'s calm, non-alarming tone), a `scope` segmented toggle ("My queue" / "All requests," gated on whether the viewer holds broad `ai.approve`/`reports.read` at all — a narrowly-scoped viewer never sees the toggle, only their own addressed items), and a secondary "Delegation" action opening a `Sheet` of the viewer's own standing/one-off delegations (see Interactions & Flows). There is no primary "create" action on this Page Header — nothing on this screen is authored here; every request originates elsewhere and arrives already formed.
- **Filter Bar.** Search (matches title, requester name, or the underlying record's own number — `JE-2026-07-0482`, a PO number, a bill number); a **Type** multi-select over the extended `ApprovalSubjectType` values (see Data & State); a **Status** multi-select over all eight `ai_approval_status` values, defaulting to `pending, held, in_review`; an **Urgency** filter (Breaching, Due today, Due this week, No SLA); an **Amount** range filter (hidden entirely when every visible type is amount-less, e.g. a permission change); a saved-filter chip row ("Needs my signature," "Held only," "AI-originated only"); and the Card/Table density toggle.
- **Bulk Action Bar.** Present only once at least one row is selected, replacing the Filter Bar's trailing controls with a count and the single bulk-safe action: "3 selected · Approve · Clear" — mirroring `docs/frontend/LAYOUT_SYSTEM.md → List Page Template`'s own worked example ("3 selected · Approve · Export · Clear"), with "Export" omitted here because a partial CSV of in-flight approval decisions has no legitimate downstream use the way an invoice export does. Selection itself is disabled at the checkbox level (not merely at the bar level) for any row that fails the bulk-eligibility rule in Interactions & Flows.
- **Data Region.** Card view: `ApprovalCard`s grouped by urgency bucket (Breaching → Due today → Due this week → No SLA/Held-adjacent-but-not-urgent), matching `docs/ai/AI_COMMAND_CENTER.md → Approval Center`'s own grouping rule exactly, with `held` rows always sorting first inside whatever bucket they'd otherwise fall into — a fraud hold is never one scroll further down than a routine item just because its own `sla_due_at` happens to read later. Table view: the identical row set through `DataTable`, columns Type · Title · Amount · Requested By/Agent · Confidence · Stage · SLA · Actions.
- **Pagination Footer.** Page-mode pagination for the default `pending, held, in_review` view (`docs/frontend/FRONTEND_ARCHITECTURE.md`'s "transactional list" cache class), with a distinct "Include resolved" toggle that switches to a cursor-paginated history view once `approved`/`rejected`/`expired`/`cancelled` rows are included — because that combined set is, over a company's lifetime, an unbounded append-only log in the same shape as `ledger-entries`/`audit-logs` (`docs/frontend/FRONTEND_ARCHITECTURE.md → Pagination: page mode vs. cursor mode`), not a bounded working set.

Every panel shell in the Data Region follows the platform's shared `Card` anatomy (`docs/frontend/LAYOUT_SYSTEM.md → Containers & Cards`) — `radius-lg`, a 1px hairline border, `shadow-xs` — with exactly one documented deviation: a `held` card's left edge carries a 4px `negative`-token border, the same treatment `docs/frontend/AI_COMMAND_CENTER.md → Layout & Regions` reserves for a Fraud Detection hold on the compact widget, so the two surfaces never disagree about what "urgent and frozen" looks like.

# Components Used

Every visual element is drawn from `docs/frontend/COMPONENT_LIBRARY.md`'s shared catalogue or the platform's existing `components/ai/` set; the additions below are documented extensions or new, narrowly-scoped components placed in the dedicated `components/approvals/` folder `docs/frontend/FRONTEND_ARCHITECTURE.md → Folder Structure` already reserves for this screen, following the identical precedent `docs/frontend/PURCHASE_ORDERS.md → ApprovalCard — kind: 'purchase_order'` set for adding a new `kind` to `PERMISSION_BY_KIND` rather than forking the component.

| Component | Source | Used for |
|---|---|---|
| `ApprovalCard` | `components/shared/approval-card.tsx` | Every row in Card view — `kind` discriminates icon, detail-link target, and (for the rare row whose `required_permission` is absent) the fallback permission lookup; see below for why this screen almost always supplies `required_permission` directly instead |
| `ApprovalStepper` | `components/ai/approval-stepper.tsx` (a specialization of the generic `components/shared/stepper.tsx` primitive `docs/frontend/EMPLOYEES.md`/`docs/frontend/PAYROLL.md` reuse for lifecycle progressions) | A multi-step request's chain, naming every step's `approver_role`, its `status`, and — once decided — its `comment`; only the step that is `pending` and assigned to the viewer's own role/user renders interactive |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Any AI-originated row's normalized 0–1 confidence, with its qualitative band; entirely absent on a human-submitted row (`confidenceScore: null`) |
| `ReasoningDisclosure` | `components/ai/reasoning-disclosure.tsx` (existing, independently reused across `docs/frontend/AI_CHAT.md`, `DOCUMENT_CENTER.md`, `TAX.md`, `REPORTS.md`, `AUTOMATION_CENTER.md`) | This screen's reasoning/sources expansion — `ApprovalCard` gains two optional props this document adds, `reasoning: string \| null` and `sources: ApprovalRequest['sources']`, which render a collapsed-by-default `ReasoningDisclosure` beneath the card's action row when populated, so a reviewer never has to leave the queue to see what grounds a proposal |
| `StatusPill` | `components/shared/status-pill.tsx`, domain `approval` | All eight `ai_approval_status` values, in both Card and Table view |
| `DataTable` | `components/shared/data-table.tsx` | Table-view density mode; also the cursor-paginated "Include resolved" history view |
| `Checkbox` | `components/ui/checkbox.tsx` | Row selection; tri-state on the Table view's header, per `docs/frontend/ACCESSIBILITY.md → Expandable rows and bulk selection` |
| `AlertDialog` | `components/ui/alert-dialog.tsx` | Reject (mandatory reason) and Request Changes (mandatory note) confirmations; the bulk-approve confirmation naming the exact count and total amount |
| `Sheet` | `components/ui/sheet.tsx` | Delegation settings (Page Header action); the Table view's row detail drawer below `lg` |
| `Popover` | `components/ui/popover.tsx` | SLA countdown detail on hover/long-press |
| `Tooltip` | `components/ui/tooltip.tsx` | Every RBAC-disabled control's `aria-describedby`-linked explanation; a bulk-selection checkbox disabled for a sensitive-permission row |
| `Badge` | `components/ui/badge.tsx` | Type chips in the Filter Bar and on each card; the `"AI"` provenance badge folded into `ApprovalCard`'s own header row |
| `DelegatePicker` | `components/approvals/delegate-picker.tsx` (**new**, this document, placed in the screen's own reserved folder) | A searchable combobox scoped to users who hold the *same role or permission* the pending step requires, built on the identical `Command`+`Popover` pattern as `docs/frontend/JOURNAL_ENTRIES.md`'s `AccountPicker` |
| `SwipeableApprovalCard` | `components/ai/swipeable-approval-card.tsx` | Mobile/Tablet touch surfaces — see Responsive Behavior |
| `Skeleton`, `EmptyState`, `ErrorState` | `components/ui/skeleton.tsx`, `components/shared/empty-state.tsx`, `components/shared/error-state.tsx` | Loading, empty, and error states — see States |
| `Toast` (Sonner) | platform-wide | Mutation success/failure feedback, never for a decision confirmation itself (those are always an `AlertDialog`, never a toast a user could miss) |

`PERMISSION_BY_KIND` — `ApprovalCard`'s internal fallback map (`docs/frontend/COMPONENT_LIBRARY.md → ApprovalCard`), used only when a row does not carry its own server-supplied `required_permission` — gains the entries this screen's broader subject-type coverage needs, following the exact "added by this document" convention `docs/frontend/PURCHASE_ORDERS.md` established for its own `purchase_order` entry:

```tsx
// components/shared/approval-card.tsx (additions owned by this document)
const PERMISSION_BY_KIND: Record<ApprovalKind, string> = {
  journal_entry: 'accounting.journal.approve',
  ai_recommendation: 'ai.approve',
  bank_transfer: 'bank.transfer',                  // request-level fallback only — see the
                                                    // per-step note below for the actual gate
  payroll_release: 'payroll.run.approve',          // the precise, stage-aware key PAYROLL.md specifies —
                                                    // never the shorter 'payroll.approve' illustrative
                                                    // default this same map originally shipped with
  tax_submission: 'tax.submit',
  purchase_order: 'purchasing.order.approve',      // added by PURCHASE_ORDERS.md
  vendor_bill: 'purchasing.bill.approve',          // added by this document
  expense_claim: 'expenses.claim.approve',         // added by this document
  tax_return: 'tax.filing.approve',                // added by TAX.md — the pre-filing review step,
                                                    // distinct from tax_submission/tax.submit above
};
```

As `docs/frontend/PAYROLL.md → Permission surface on this screen` states of its own module ("this screen always resolves against the precise, stage-aware keys… never the shorthand, exactly as `docs/frontend/JOURNAL_ENTRIES.md → Route & Access` did for its own module"), this screen follows the identical discipline one level up: because `ai_approval_requests.required_permission VARCHAR(60) NOT NULL` already carries a permission for every row this screen renders, `PERMISSION_BY_KIND`'s table above is a documented fallback for the rare `ApprovalCard` instance constructed from a lighter, non-`ai_approval_requests`-backed shape — this screen's own `usePermission()` checks always read `row.required_permission` (or, where present, a step's own more precise `stepPermission`, see below) first, never the kind-keyed default, so a future new subject type never silently falls through to a stale illustrative permission.

**The `bank_transfer` two-key chain, reconciled explicitly.** `docs/ai/AI_COMMAND_CENTER.md`'s own schema comment illustrates `required_permission` with the single value `'bank.transfer'`, and that value remains correct as the *request-level* fallback and as the value the bulk-eligibility `SENSITIVE_PERMISSIONS` gate below checks. But `docs/frontend/BANKING.md → Approving or rejecting an item` is precise that a `bank_transfer`-kind `ApprovalCard`'s actual per-step decision gate is finer-grained: a first-tier step is decided by whoever holds `bank.payment.approve`, and — for the two-key chain `docs/foundation/PERMISSION_SYSTEM.md → Approval Chains`'s own canonical "Bank Transfer via Finance Manager → CEO" example describes — a second, final-tier step is decided by whoever holds `bank.payment.approve.final`; `bank.transfer` itself is the separate permission that gates *initiating* a transfer, not approving one. This document resolves the mismatch explicitly rather than silently picking one, the same discipline `docs/frontend/DOCUMENT_CENTER.md` already applied to its own cross-document `attachableType` inconsistency: `ApprovalStep` carries an optional `stepPermission` field (see Data & State) that, when present, is checked in place of the request's own `requiredPermission` for that one step's Approve/Reject/Delegate gate. A `bank_transfer` request's steps therefore carry `stepPermission: 'bank.payment.approve'` and `stepPermission: 'bank.payment.approve.final'` respectively; every other subject type in today's registry has a single-step or uniform-permission chain and simply omits `stepPermission`, falling back to the request's own `requiredPermission` with no behavioral change.

# Data & State

## The two upstream systems this screen unifies

`docs/ai/AI_COMMAND_CENTER.md → Approval Center` fully specifies the primary tables this screen reads — `ai_approval_requests` (the request: `subject_type`, `subject_id`, `source_decision_id`, `source_agent_code`, `title`, `amount`/`currency_code`, `status`, `required_permission`, `hold_reason`, `sla_due_at`, `decided_at`) and `ai_approval_steps` (one row per chain link: `step_order`, `approver_role`, `approver_user_id`, `status`, `decided_by`, `decided_at`, `comment`). `docs/ai/workflows/EXPENSE_APPROVAL.md → Data & Tables Touched` and `docs/ai/workflows/PURCHASE_TO_PAY.md` additionally name a second, older workflow engine — `approval_policies`, `approval_steps`, `approval_actions`, keyed by `document_type`/`document_id` — that a plain, non-AI-originated document approval (a Senior Accountant's journal entry needing a peer's sign-off, a Purchase Order clearing its tiered chain) can also pass through. This screen does not choose between them: Laravel's `ApprovalService` normalizes both sources into the identical `GET /api/v1/approvals` response shape before it ever reaches the browser, which is exactly why `docs/frontend/AI_COMMAND_CENTER.md → AI Integration`'s "Approval affordances" note could already state, without contradiction, that `ApprovalCard`'s "`confidence` prop is populated only for AI-originated requests — a human-submitted journal entry pending a peer's sign-off has no confidence to show." The frontend never queries the two source tables separately or attempts its own merge; it renders one envelope, one `ApprovalRequest` shape, regardless of which engine produced the underlying row.

```ts
// types/approvals.ts
export type ApprovalStatus =
  | 'pending' | 'held' | 'in_review' | 'approved'
  | 'rejected' | 'changes_requested' | 'expired' | 'cancelled';

export type ApprovalSubjectType =
  | 'bank_transfer' | 'payroll_release' | 'tax_submission' | 'journal_void'
  | 'permission_change' | 'purchase_order' | 'credit_note' | 'credit_hold'
  | 'vendor_bank_update'
  // added by this document — the subject types a full, cross-module inbox
  // must additionally surface that the compact dashboard widget's illustrative
  // examples did not enumerate:
  | 'journal_entry_post' | 'vendor_bill' | 'expense_claim' | 'record_delete';

export interface ApprovalStep {
  id: number;
  stepOrder: number;
  approverRole: string;          // e.g. 'Senior Accountant', 'Finance Manager', 'CFO', 'CEO'
  approverUserId: number | null;
  status: ApprovalStatus;
  decidedBy: number | null;
  decidedAt: string | null;
  comment: string | null;
  stepPermission: string | null; // present only when a step's own gate is more precise than the
                                  // request-level requiredPermission — see Components Used's
                                  // "bank_transfer two-key chain" note for the worked example
}

export interface ApprovalRequest {
  id: number;
  subjectType: ApprovalSubjectType;
  subjectId: number | null;
  title: string;
  amount: { value: string; currencyCode: string } | null;
  status: ApprovalStatus;
  requiredPermission: string;               // always present — see Components Used
  holdReason: string | null;
  slaDueAt: string | null;
  decidedAt: string | null;
  requestedBy: { id: number; name: string } | null;   // null when purely AI-originated
  sourceAgentCode: string | null;                     // null when purely human-submitted
  confidenceScore: number | null;                     // 0–100; null when human-submitted
  reasoning: string | null;
  sources: Array<{ type: string; id: number | null; label: string }>;
  steps: ApprovalStep[];
  createdAt: string;
}
```

## Endpoints

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/approvals` | `ai.approve` (act) or `reports.read` (view only) | `?scope=mine\|all`, `filter[status][in]=`, `filter[subject_type][in]=`, `filter[urgency]=breaching\|due_today\|due_week\|none`, `filter[amount][gte\|lte]=`, `q=`, `sort=` (default `sla_due_at` ascending, nulls last, then `-created_at`), `page`/`per_page` or `cursor` when `filter[status][in]` includes any resolved state |
| `GET` | `/api/v1/approvals/{id}` | Same, or a step-level match on `approver_user_id`/`approver_role` | Backs `[approvalId]/page.tsx` and every inline `ApprovalCard`'s detail fetch |
| `POST` | `/api/v1/approvals/{id}/approve` | The viewer's own pending step's `stepPermission` (or the request's `requiredPermission` when absent) | Body `{ comment?: string }`; `Idempotency-Key` required |
| `POST` | `/api/v1/approvals/{id}/reject` | Same as approve | Body `{ reason: string }` (mandatory, non-empty); terminates the whole chain, `status → 'rejected'` |
| `POST` | `/api/v1/approvals/{id}/request-changes` | Same as approve | Body `{ note: string }` (mandatory); `status → 'changes_requested'` — this document names the endpoint precisely because `ai_approval_status` already reserves `changes_requested` as distinct from `rejected`, completing the fourth verb `docs/frontend/FRONTEND_ARCHITECTURE.md → The Approval Center`'s own shorthand ("Approve/Reject/Request Changes/Delegate against `POST /api/v1/approvals/{id}/approve\|reject\|delegate`") named in its UI copy but not its endpoint list |
| `POST` | `/api/v1/approvals/{id}/delegate` | Same as approve | Body `{ user_id: number }`; only ever offered to a user who already holds the pending step's own `approver_role` or an equivalent permission grant |
| `POST` | `/api/v1/approvals/bulk-approve` | `requiredPermission` (uniform across the whole selection) | Body `{ ids: number[] }`; mirrors `docs/frontend/PURCHASING.md → Bulk-approve bills`'s exact contract — every id is still individually audit-logged, and a single ineligible id in the batch fails only that id (see Edge Cases), never the whole request |

## Query keys, cache tuning, and hooks

`approvalKeys` is reused verbatim from `docs/frontend/FRONTEND_ARCHITECTURE.md → Query key architecture`, extended with one factory for the cursor-paginated history view this screen adds:

```ts
// lib/api/query-keys.ts (Approval Center additions)
export const approvalKeys = {
  all: ['approvals'] as const,
  queue: (filters: ApprovalFilters) => [...approvalKeys.all, 'queue', filters] as const,
  detail: (id: number) => [...approvalKeys.all, 'detail', id] as const,
  history: (filters: ApprovalHistoryFilters) => [...approvalKeys.all, 'history', filters] as const,
};
```

The default `pending, held, in_review` queue is a "transactional list" by `docs/frontend/FRONTEND_ARCHITECTURE.md → Cache tuning by data class`'s own taxonomy — 30 second `staleTime`, `placeholderData: keepPreviousData` across a filter or page change — but this screen tightens that default the same way the compact widget did, because a held fraud flag or an SLA-breaching item is exactly the content that must not sit stale for thirty seconds: `staleTime: 10_000` with `refetchOnWindowFocus: true`, corrected in the interim by realtime invalidation, never by a shorter poll. The "Include resolved" history view uses `useInfiniteQuery` against the cursor contract, identical in shape to `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own `useLedgerEntries` example, because a company's full resolved-approval history is exactly the same "unbounded, append-only" shape as `ledger-entries`/`audit-logs`.

```ts
// lib/api/hooks/use-approvals-queue.ts
export function useApprovalsQueue(filters: ApprovalFilters) {
  return useQuery({
    queryKey: approvalKeys.queue(filters),
    queryFn: () => api.get<ApprovalRequest[]>('/approvals', { params: filters }),
    placeholderData: keepPreviousData,
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
}

export function useApprovalHistory(filters: ApprovalHistoryFilters) {
  return useInfiniteQuery({
    queryKey: approvalKeys.history(filters),
    queryFn: ({ pageParam }) =>
      api.get<ApprovalHistoryPage>('/approvals', { params: { ...filters, cursor: pageParam } }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.pagination.next_cursor,
  });
}
```

`useApproveRequest` is reused unmodified from `docs/frontend/FRONTEND_ARCHITECTURE.md → Optimistic updates` — the exact optimistic patch-then-invalidate hook shown there is this screen's own Approve action, not a re-implementation. `useRejectRequest`, `useRequestChanges`, and `useDelegateRequest` follow the identical shape (`onMutate` optimistic status patch, `onError` rollback plus a toast, `onSettled` invalidating `approvalKeys.all` and the dashboard's own urgent-actions badge query):

```ts
// lib/api/hooks/use-bulk-approve.ts
export function useBulkApprove() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (ids: number[]) =>
      api.post<BulkApproveResult>('/approvals/bulk-approve', { ids }, crypto.randomUUID()),
    // No onMutate: a multi-row financial decision is never optimistic, even though a single
    // Approve is — see AI Integration for why the two are treated with deliberately different
    // optimism under Principle 10.
    onSuccess: (result) => {
      if (result.failed.length > 0) {
        toast.warning(t('approvals.bulkPartial', { succeeded: result.succeeded.length, failed: result.failed.length }));
      } else {
        toast.success(t('approvals.bulkSucceeded', { count: result.succeeded.length }));
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: approvalKeys.all });
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
```

## Realtime

The screen subscribes to `private-company.{id}.approvals` — the exact channel `docs/frontend/FRONTEND_ARCHITECTURE.md → Realtime → Transport and channel convention` names ("New/updated `ai_approval_requests` rows," subscribed by `app/(app)/approvals`, topbar badge count) — through the single shared `RealtimeProvider` connection mounted once in the `(app)` shell, never a second connection this screen opens for itself:

```ts
echo.private(`company.${companyId}.approvals`)
  .listen('.approval.status_changed', (e: ApprovalStatusChangedEvent) => {
    if (e.status === 'held') {
      // Patch the one affected row in place rather than invalidating the whole list —
      // preserves scroll position and any in-progress selection, the identical rule
      // docs/frontend/BANKING.md and docs/frontend/AI_COMMAND_CENTER.md state for this
      // exact event ("Fraud Detection raises a hold... patches that one card's status
      // to held in place").
      queryClient.setQueryData(approvalKeys.detail(e.id), (old: ApprovalRequest) => ({ ...old, status: 'held', holdReason: e.hold_reason }));
      queryClient.invalidateQueries({ queryKey: approvalKeys.all, refetchType: 'none' }); // marks stale without yanking the visible list
    } else {
      queryClient.invalidateQueries({ queryKey: approvalKeys.all });
    }
  });
```

Which AI agent feeds which subject type is read verbatim from `docs/ai/AI_COMMAND_CENTER.md → Agent coverage map`, never inferred client-side: `APPROVAL_ASSISTANT` authors and routes every request and drives every SLA escalation timer (default SLA: 4 business hours for `critical`-sourced requests, 2 business days otherwise, per `docs/ai/AI_COMMAND_CENTER.md → Approval Center`'s own "AI behavior" note); `FRAUD_DETECTION` is the only other writer, and only to set `status = 'held'`; `TREASURY_MANAGER` is the `sourceAgentCode` behind most `bank_transfer` rows; `PAYROLL_MANAGER` behind `payroll_release`; `TAX_ADVISOR` behind `tax_submission`; `GENERAL_ACCOUNTANT` behind AI-drafted `journal_entry_post`/`vendor_bill` rows a company has configured to require sign-off even below its human-only threshold. A row with `sourceAgentCode: null` and a populated `requestedBy` is, by construction, a plain human submission that reached the approval chain without any agent involvement at all — Purchasing's own manual PO entry, a Senior Accountant's manually drafted journal entry such as S. Rahman's `JE-2026-07-0482` rent accrual in the Layout & Regions wireframe above.

# Interactions & Flows

**Landing on the screen.** The default filter is `scope=mine` (or `all` for a broad `ai.approve` holder with no narrower stage assigned today), `status IN (pending, held, in_review)`, grouped by urgency with `held` rows pinned first inside their bucket. A viewer arriving from a notification or the Command Palette's live count lands with that specific request's card already scrolled into view and its `ReasoningDisclosure` pre-expanded — the identical `?highlight={id}` convention `docs/frontend/AI_COMMAND_CENTER.md → Interactions & Flows` already established for cross-panel deep links on Morning Briefing, reused here for cross-screen ones.

**Reviewing a single request.** Expanding a card's reasoning shows the full `reasoning` text and every `sources` entry as a list of clickable citations — the exact underlying `journal_lines`/`bank_transactions`/`ai_decisions` rows the proposal is grounded in, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s AI Integration Layer contract, never a bare summary invented for this screen. Immediately before rendering the Approve action, the client re-fetches the request's live target record and diffs it against the proposal's own stored payload; on divergence — someone edited the underlying bank transfer's beneficiary, or a Purchase Order's line items changed after the PO already entered its chain — the Approve button is replaced with "Review changed data," never a silent approval of stale intent, mirroring the identical rule `docs/frontend/FRONTEND_ARCHITECTURE.md → Edge Cases` states platform-wide for exactly this situation.

**Approve.** A single click for a single-step request; for a multi-step chain, only the step matching the viewer's own role/user is interactive, and approving it advances the chain to the next step rather than resolving the whole request — `docs/frontend/FRONTEND_ARCHITECTURE.md → The Approval Center` states this precisely: "A request only reaches the next step once the current step approves; a rejection at any step terminates the whole chain rather than silently skipping to the next approver." On the vendor-payment-run example from Layout & Regions, S. Rahman's Senior Accountant step-1 approval has already resolved; Mariam Al-Sabah, CFO, is the only viewer for whom step 2's Approve button is interactive, and Khalid Marafie (Finance Manager) sees that same step rendered read-only with a tooltip naming the CFO as the outstanding approver. The mutation is optimistic (`onMutate` flips that step's status and, if it was the final step, the whole request's status, in the TanStack Query cache immediately), carries a client-generated `Idempotency-Key` so a slow spinner and a second tap can never double-approve, and rolls back with a toast on any `4xx`/`5xx`.

**Reject.** Opens an `AlertDialog` collecting a mandatory reason — the "Confirm reject" action stays disabled until the field is non-empty, identical to `docs/frontend/COMPONENT_LIBRARY.md → ApprovalCard`'s own implementation. Rejecting at any step in a chain terminates the entire request (`status → 'rejected'`), never merely that one step, so a CFO's rejection of a payment run's final step cannot be misread as "step 1's sign-off still stands for a future retry" — a rejected request is dead; a genuinely revised proposal re-enters the queue as a new request.

**Request Changes.** The one action this screen distinguishes carefully from Reject, because "send it back for revision" and "kill it" are different intents a reviewer needs to express separately. Opens an `AlertDialog` collecting a mandatory note, transitions the request to `changes_requested`, and — depending on origin — routes the note two different ways without the reviewer needing to know which: for an AI-originated proposal, the note is fed back into `ai_memory` and the originating agent's next scheduled run, the same mechanism a Dismiss reason feeds `ai_memory` elsewhere in the product (`docs/frontend/AI_COMMAND_CENTER.md → AI Integration`); for a human-submitted document, the note surfaces on that document's own Detail page (a Journal Entry, a Purchase Order) as a specific, addressed revision request the original preparer sees the next time they open it, and resubmitting from there opens a fresh request rather than mutating the `changes_requested` one in place.

**Delegate.** Rendered only when the viewer is the assigned approver for the currently pending step — never a general "reassign anyone's step" affordance. Opens `DelegatePicker`, scoped server-side to users who already hold the step's own `approver_role` or an equivalent permission grant (the API rejects a delegate target lacking it with a field-level `422`, and the picker's own search additionally filters client-side so an ineligible name is never offered in the first place). A delegated step records both the original `approver_user_id` and the delegate in its audit trail — delegation in QAYD is a *reassignment with attribution*, never an anonymous hand-off. The Page Header's "Delegation" action opens a separate `Sheet` listing the viewer's own standing delegations (e.g. Khalid Marafie delegating all Purchasing-tier approvals to a deputy while traveling for a fixed date range) alongside one-off delegations made inline from a specific card, so a reviewer never has to remember which mechanism produced which hand-off.

**Bulk-approve.** Selecting rows via checkbox (Table view) or long-press (Card view, touch) surfaces the Bulk Action Bar the instant the selection is non-empty. A row's checkbox is disabled, with a `Tooltip` explanation, whenever its `requiredPermission` is one of `bank.transfer`, `payroll.run.approve`, `tax.submit`, or whenever it is `held` — the exact `SENSITIVE_PERMISSIONS` gate `docs/frontend/FRONTEND_ARCHITECTURE.md → The Approval Center` already specifies, reused unmodified. A valid selection must additionally share one `subjectType` (the Bulk Action Bar's own "Approve" button is absent, not disabled, for a mixed-type selection, since a single confirmation dialog naming one coherent action is the whole point of offering bulk at all) and sit below that type's company-configured auto-approval-adjacent value threshold. Clicking "Approve" opens one `AlertDialog` naming the exact count and total amount ("Approve 6 expense reclassifications — KWD 1,140.000 total?") before `POST /api/v1/approvals/bulk-approve` fires; the response's `failed[]` array (a row whose eligibility changed in the seconds between selection and submission — see Edge Cases) surfaces as a partial-success toast, never a silent partial failure.

**Switching Card/Table density and toggling "Include resolved."** Both are pure client-side view preferences (`docs/frontend/DESIGN_LANGUAGE.md → Data Density`'s "per-table, user-persisted preference" rule), stored in the viewer's own settings via `useUiStore`, and neither re-issues a different *filter* — only how the identical row set renders. "Include resolved" is the one toggle that does change the underlying query, switching the Data Region from page-mode to cursor-mode pagination as described in Layout & Regions.

**Opening the Detail route directly.** `[approvalId]/page.tsx` renders the identical `ApprovalRequest` shape as a single-record page rather than a queue row — a larger `ReasoningDisclosure`, the full `ApprovalStepper` timeline with every step's `comment`, and the same four actions — for the case where a notification, a shared link, or an inline `ApprovalCard`'s "View in Approval Center" link is the entry point rather than browsing the list. Approving from here and approving from the list row mutate the identical cache entry (`approvalKeys.detail(id)`), so neither view can show a decision the other has not also reflected.

# AI Integration

This screen is the literal terminus of QAYD's "AI proposes; a human approves" rule (`docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 3`) — every recommendation's "Send for approval" button across the product, every module's own inline `ApprovalCard`, and every automatic fraud hold all deposit into the same queue this screen renders. Because everything here has, by definition, already crossed the line from "AI could act" to "a human must decide," the three-button pattern (`docs/frontend/FRONTEND_ARCHITECTURE.md → The three-button pattern`) never applies on this screen the way it does on the AI Insights/Recommendations feeds — **"Do it" never renders here, structurally, for any row, regardless of confidence.** A recommendation that clears its company's autonomy threshold executes directly from its own card on the Recommendations feed and never becomes an `ai_approval_requests` row at all; a row exists in this queue precisely because it did *not* clear that bar, or because its `subjectType` is one of the handful QAYD never allows to auto-execute regardless of confidence (`bank_transfer`, `payroll_release`, `tax_submission`, `permission_change`, any `record_delete`). This screen's only vocabulary is Approve, Reject, Request Changes, and Delegate.

Every AI-originated row carries the platform's mandatory `confidence_score`/`reasoning`/`sources` triad, rendered through `ConfidenceBadge` and `ReasoningDisclosure` exactly as `docs/frontend/FRONTEND_ARCHITECTURE.md → AI Integration Layer` fixes platform-wide — normalized to the shared 0–1 scale via `normalizeConfidence(score, 'percentage')`, never a bare, unexplained percentage. A human-submitted row (`sourceAgentCode: null`) omits `ConfidenceBadge` entirely rather than rendering a fake or zero confidence, and shows `requestedBy`'s name where an AI row would show its agent avatar — the two shapes are visually distinguishable at a glance without either ever looking like a degraded version of the other.

**Optimism is calibrated to what the action actually does, not to who or what proposed it.** Approve, Reject, Request Changes, and Delegate are all optimistic (`onMutate` patches the cache immediately) because *deciding on a request* is reversible-adjacent — a wrong Approve can still be caught and reversed procedurally at the next stage, and none of the four actions themselves moves money, releases payroll, or files a return. The action that actually does one of those things — Banking's own transfer initiation once `bank_transfer` clears its final step, Payroll's disbursement once `payroll_release` posts, Tax's `POST /api/v1/tax/returns/{id}/file` once `tax_submission` clears — is a separate, pessimistic mutation on that module's own screen, per `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 10` ("optimistic where safe, pessimistic where it moves money"), never something this screen's own Approve click performs directly. Approving a `tax_submission` request here is what authorizes Tax's own "File Return" action to actually run — the two are the same underlying `tax.submit` permission and the same terminal transition, reached from two different screens, never two independent gates that could disagree, exactly as `docs/frontend/TAX.md`'s own structurally-absent-not-disabled treatment of "File Return" already implies for anyone lacking that permission.

**Approval chains are configurable per company, not hardcoded per subject type.** `docs/foundation/PERMISSION_SYSTEM.md → Approval Chains` gives the platform's own canonical illustrations — Delete Journal Entry via Finance Manager → CFO, Bank Transfer via Finance Manager → CEO — while `docs/ai/AI_COMMAND_CENTER.md`'s own July vendor-payment-run example routes that particular request through Senior Accountant → CFO instead; both are simultaneously correct because `APPROVAL_ASSISTANT` resolves the actual chain per company from that company's own `roles`/`permissions` configuration at request-creation time, never from a value this screen bakes in. `ApprovalStepper` therefore never assumes a fixed number of steps or a fixed role sequence — it renders exactly the `steps[]` array the API returns, whether that is Payroll's fixed multi-stage Payroll Officer → Finance Manager → CEO chain (`docs/frontend/PAYROLL.md`'s own seven-stop lifecycle) or a single-step Accountant-only sign-off on a routine reclassification.

**A held row is never treated as merely "urgent."** `FRAUD_DETECTION`'s unilateral `held` transition is the one AI action in the entire platform that requires no human initiation — because it is a safety brake that prevents money from moving rather than a trigger that moves it (`docs/ai/AI_COMMAND_CENTER.md → Fraud Alerts`) — and this screen renders it with the identical `negative`-token left-border treatment no other status uses, pinned ahead of same-bucket items regardless of its own `slaDueAt`, so a hold is never one scroll further away than an ordinary pending item. Dismissing a hold is not one of this screen's four verbs: a `held` row's Approve action remains present (so a verified, legitimate payment can still proceed once the vendor's changed IBAN is confirmed by phone, as in the Al-Fajr Cement example), but the hold itself is lifted only by `FRAUD_DETECTION` re-evaluating the underlying signal or by an explicit, separately-audited "Clear hold" action on the source module's own record — never by this screen's ordinary Approve click alone, so a reviewer cannot accidentally wave through a frozen payment believing they merely approved it.

# States

Every region on this screen ships loading, empty, and error states as a matter of house style, following `docs/frontend/DARK_MODE.md → Manual QA state table`'s own required `loading/empty/error/RTL/dark` matrix rather than leaving a state to fall through to a bare blank region:

| Region | Loading | Empty | Error |
|---|---|---|---|
| Page Header count badge | A `Skeleton` pill in place of "14 pending · 2 breaching" | Renders "0 pending" plainly — a genuinely empty queue is good news and is never hidden or dressed up | Omitted silently if the count fetch fails independently of the list; never blocks the rest of the header |
| Queue (Card view) | Full-width shimmer, three row-shaped `ApprovalCard` placeholders matching the loaded card's exact height and padding | "Nothing waiting on you" (`docs/frontend/AI_COMMAND_CENTER.md → States`'s identical, deliberately reassuring copy for the compact widget) with no illustration needed — the message alone is the point | Inline `ErrorState` card with a retry button; the Filter Bar and Page Header remain fully interactive so a transient failure never locks the whole screen |
| Queue (Table view) | `DataTable`'s own skeleton rows, same row-height token as the loaded table | "No approvals match these filters" (a filtered-to-zero state, textually and visually distinct from the true-zero "Nothing waiting on you" above) with a "Clear filters" action | `DataTable`'s shared inline retry row |
| Include-resolved history (cursor) | Five placeholder rows on first load; a slim inline spinner at the list's end on "load more" | "No resolved approvals yet" for a brand-new company; never conflated with the pending queue's own empty copy | A failed "load more" leaves already-loaded pages intact with an inline retry at the list's end, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s cursor-pagination convention |
| Single request (`[approvalId]/page.tsx`) | Full-page skeleton matching the Detail template's Main Column/Summary Rail split | N/A — a request either exists or the route 404s | `not-found.tsx` for a nonexistent or cross-tenant id (never distinguishing the two, per `docs/frontend/FRONTEND_ARCHITECTURE.md → 403 vs. 404`); a genuine `403` (the id exists, but the viewer holds neither `ai.approve`/`reports.read` nor a matching step) instead names the missing permission, since existence is already established |
| Delegation `Sheet` | `Skeleton` rows for the viewer's own standing delegations | "You have no active delegations" with a "New delegation" action | Inline retry within the sheet; never dismisses the sheet itself on failure |
| Bulk-approve submission | The confirming `AlertDialog`'s primary button shows a spinner and disables re-submission for the duration of the request | N/A | A total failure (network-level, before the server responds) rolls the whole dialog back to its pre-submit state with a toast; a partial failure (`failed[]` non-empty) is a *success* path with a warning toast, never routed through this error state |

Every skeleton reuses its loaded state's exact spacing and row-height classes so no region pops or reflows between loading and loaded, matching `docs/frontend/RESPONSIVE_DESIGN.md → Loading and skeleton reflow`'s platform-wide rule. The Card-view queue, the Table-view queue, and the history view are three independently-scoped `WidgetErrorBoundary`-style regions (`docs/frontend/FRONTEND_ARCHITECTURE.md → Boundaries at three granularities`), so a failure fetching resolved history never takes down the still-healthy pending queue rendered above it, and vice versa.

# Responsive Behavior

Below the `xl` breakpoint (1280px), the screen collapses from its two-column-adjacent desktop reading (Filter Bar controls spread across one row, cards at full content width) to the platform's standard single-column reflow, per `docs/frontend/RESPONSIVE_DESIGN.md → Dashboard Reflow` and `→ Pattern 1 — Data tables become cards`. Table view is available only at `lg` and above; below `lg` the density toggle itself is hidden and the screen forces Card view, since a dense `DataTable` with an Amount/Confidence/Stage/SLA/Actions column set has no legible mobile shape — this mirrors `docs/frontend/RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`'s own worked Trial Balance example, applied here to a screen whose "table" is itself an optional density mode rather than the only view.

| Region | Desktop/Ultra Wide (`xl`+) | Tablet (`md`–`lg`) | Mobile (`base`–`sm`) |
|---|---|---|---|
| Page Header | Title, count badge, scope toggle, and Delegation action on one row | Count badge wraps beneath the title; scope toggle and Delegation collapse into a single overflow menu | Title and count badge only; scope toggle and Delegation move into a `Sheet` opened from a header icon |
| Filter Bar | Every filter controls visible inline | Search plus a "Filters" button opening a `Sheet` of the remaining controls | Search plus the same "Filters" `Sheet`; saved-filter chips scroll horizontally |
| Bulk Action Bar | Inline, replacing the Filter Bar's trailing controls | Same, full width | A sticky bottom bar instead of inline, so it never scrolls out of reach on a long touch scroll |
| Data Region | Card view or Table view, per user preference | Card view only (Table view hidden per above) | Card view only, one `SwipeableApprovalCard` at a time |
| Delegation `Sheet` | Right-anchored, `md` width | Full-height right-anchored sheet | Full-screen sheet |

Touch targets follow the platform-wide 44px minimum (`docs/frontend/RESPONSIVE_DESIGN.md → Touch Targets & Gestures`) with an 8px minimum gap between adjacent controls — material on this screen specifically because Approve and Reject sit side by side on every card, and a mis-tap here carries real financial consequences unlike a marketing-site mis-tap. On Mobile and Tablet touch surfaces, `SwipeableApprovalCard` (`components/ai/swipeable-approval-card.tsx`, per `docs/frontend/RESPONSIVE_DESIGN.md → Swipeable approval card`) layers a horizontal drag-to-decide gesture on top of the same Approve/Reject buttons — swiping toward the logical end approves, toward the logical start rejects, mirrored correctly under `dir="rtl"` by reading the resolved direction rather than assuming LTR — but the swipe is always an *accelerator*, never a replacement: the explicit buttons remain present and are the only path for a screen-reader user, a switch-control user, or anyone with `prefers-reduced-motion` set, since `useReducedMotion()` disables the `drag` gesture entirely rather than merely skipping its animation. A swipe still opens the identical confirming `AlertDialog` a desktop click would — a swipe changes the entry point into the action, never the confirmation step itself.

Approving a sensitive action (a `bank_transfer` or `payroll_release` request) from a mobile device additionally requires a biometric re-confirmation (Face ID/fingerprint) immediately before submission, layered on top of, never instead of, the ordinary permission and idempotency checks, per `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`'s platform-wide rule for exactly this class of action. Realtime subscriptions are scoped by viewport the same way the compact widget's are: desktop keeps the `private-company.{id}.approvals` channel bound for as long as the screen is mounted, while mobile gates the binding behind visibility so a backgrounded tab does not keep a radio connection alive for no visible benefit, per `docs/frontend/RESPONSIVE_DESIGN.md → Realtime subscription scoping`.

# RTL & Localization

The screen is authored and reviewed in Arabic before being considered done, not mirrored at the end, per `docs/frontend/DESIGN_LANGUAGE.md → Design Principle 6`. `dir="rtl"` is set once on `<html>` by the root layout from the active locale; no component on this screen toggles direction itself, and every layout, gap, and padding value uses Tailwind's logical utilities (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively — `ml-*`/`mr-*`/`text-left`/`text-right` are banned in this screen's component source by the same ESLint restriction `docs/frontend/COMPONENT_LIBRARY.md → Theming & RTL` enforces platform-wide, which is what lets the Filter Bar's control order, `ApprovalStepper`'s progress direction, the Bulk Action Bar's trailing controls, and the Delegation `Sheet`'s slide-in edge (inline-end in LTR, inline-start in RTL) all mirror with zero conditional code.

Three things never mirror, matching the platform's numeral and icon rules exactly:

- **Every monetary figure, percentage, confidence score, and date** on this screen renders inside a `dir="ltr"` span via the shared `AmountCell`/`Amount` primitives — `KWD 24,180.000`, `93%`, and every SLA countdown read left-to-right even inside a fully Arabic sentence, with `numberingSystem: 'latn'` forcing Western Arabic numerals throughout. A reviewer reading "اعتماد 2 من 2" ("Approval 2 of 2") never sees the digits themselves switch to Eastern Arabic-Indic form, exactly as `docs/frontend/NAVIGATION_SYSTEM.md → RTL Mirroring`'s own rule states for a badge count ("a KWD amount or an invoice number reading right-to-left digit-by-digit is not a translation, it is a comprehension hazard").
- **Directional icons flip; meaning-bearing icons don't.** `ApprovalStepper`'s progress arrow and the Filter Bar's chevrons mirror via `rtl:rotate-180`; the held-row left border, a checkmark, and the AI provenance mark never do, per `docs/frontend/RESPONSIVE_DESIGN.md → RTL + Responsive combined`'s icon rule.
- **AI-authored prose is never machine-translated by the frontend.** An Arabic `reasoning` string arrives already localized from the API per its content-negotiation contract; the client's own localization responsibility on this screen is limited to chrome — button labels, filter labels, empty-state copy, tooltips — never the model's own words, exactly as `docs/frontend/FRONTEND_ARCHITECTURE.md → Streaming AI chat` specifies for the same distinction elsewhere in the product.

One rule is specific to this screen's swipe gesture: `SwipeableApprovalCard`'s drag-to-decide direction is defined relative to the *logical* end, not a hardcoded physical direction — `docs/frontend/RESPONSIVE_DESIGN.md → Swipeable approval card`'s own implementation reads `dir` from `useLocale()` and flips its sign accordingly, so "swipe to approve" is physically left-to-right in English and physically right-to-left in Arabic, never the same physical motion misapplied across both directions.

Arabic microcopy for this screen is authored directly by a fluent professional-register writer, not translated post hoc, following `docs/frontend/DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief:

| Context | English | Arabic |
|---|---|---|
| Screen title | Approvals | الاعتمادات |
| Scope toggle | My queue / All requests | طلباتي / كل الطلبات |
| Bulk action bar | 3 selected · Approve · Clear | 3 محددة · اعتماد · إلغاء التحديد |
| Held banner | Held — vendor bank details changed 2 hours ago | معلّق — تم تغيير بيانات حساب المورد البنكي قبل ساعتين |
| Approval action | Approve | اعتماد |
| Approval action | Reject | رفض |
| Approval action | Request changes | طلب تعديل |
| Approval action | Delegate | تفويض |
| Empty queue | Nothing waiting on you | لا يوجد شيء بانتظارك |
| Include resolved toggle | Include resolved | تضمين الطلبات المكتملة |

Bilingual master-data fields (`name_en`/`name_ar` on any customer, vendor, or account a request's title or reasoning cites) are picked by the client at render time based on `useLocale()`, since the API always returns both regardless of `Accept-Language` — a request titled against a vendor's English trading name in one locale renders the same underlying request against its Arabic name in the other, never a mismatch between what the queue shows and what the target record itself displays.

# Dark Mode

Dark mode is system-aware by default, user-overridable, and persisted, driven by the platform's `class`/`data-theme="dark"` strategy (`docs/frontend/DARK_MODE.md → Strategy`) — no component on this screen implements its own theme logic or a `dark:` Tailwind variant referencing a raw color. Every token this screen's components resolve — the `ink` scale, `accent`/`accent-subtle`, `positive`/`negative`/`warning` — carries an explicit dark-mode value already defined in `docs/frontend/DESIGN_LANGUAGE.md → Dark mode strategy`; backgrounds stay warm-neutral rather than shifting to a cool blue-black, and elevation gets *lighter*, not darker, so an `ApprovalCard` sits visibly above the canvas behind it via its border and a marginally lighter fill, never a heavier shadow, per `docs/frontend/DARK_MODE.md → Surfaces & Elevation In Dark`.

Two rules specific to this screen's AI-heavy, decision-bearing content:

- **The accent stays reserved for AI provenance and the one primary action per card — never status.** A `held` row is colored with the `negative` semantic token, never the accent, even though the hold itself is AI-originated (`FRAUD_DETECTION`'s doing); conflating "the AI touched this" with "this is urgent" would collapse two independently useful signals into one, per `docs/frontend/DESIGN_LANGUAGE.md → Color & Ink`'s accent-is-AI's-signature rule and `docs/frontend/DARK_MODE.md`'s explicit accent/status-collision resolution. The Approve button remains the one `accent`-colored primary action on a card regardless of theme; Reject uses the quieter `destructive-quiet` variant `docs/frontend/COMPONENT_LIBRARY.md → ApprovalCard` already specifies, never a loud, alarming red that would make every card feel like an error state.
- **Confidence indicators are re-tuned per theme, not linearly brightened.** `ConfidenceBadge`'s dark-mode accent value uses its own calibrated dark value rather than a naive "brighten the light-mode hex," so a 94%-confidence fraud hold reads as urgent without the harsh over-saturation a mechanical brightness flip would produce, per `docs/frontend/DARK_MODE.md → Composed: an AI Command Card, rendered in dark mode`.

`ApprovalStepper`'s progress line and step-state dots resolve through `lib/tokens.ts`'s JS-readable token export rather than a CSS variable read at render time wherever the stepper renders as SVG rather than styled `div`s, the same mechanism `docs/frontend/DARK_MODE.md → Charts & Data Viz In Dark` requires for any SVG `stroke`/`fill` attribute platform-wide. No icon or badge on this screen is ever CSS-`filter: invert()`-flipped between themes. Exported artifacts are the one deliberate, permanent exception to "everything themes": a compliance export of the resolved-approval history (see Edge Cases) renders in a single, fixed light/print palette regardless of the requesting user's in-app theme, per `docs/frontend/DARK_MODE.md → Exported PDFs always render light` — a document an external auditor opens outside QAYD entirely has no theme context to honor.

# Accessibility

The Approval Center targets the same WCAG 2.2 AA floor as every other QAYD screen (`docs/frontend/ACCESSIBILITY.md → Standards & Targets`), with extra weight on three areas given that this screen is where a screen-reader user must be able to complete a financially consequential decision with full confidence, not just read about one.

**Landmarks and headings.** The page renders inside the shared `<main id="main-content" tabIndex={-1}>` region with exactly one `<h1>` ("Approvals" / الاعتمادات); each urgency group ("Breaching SLA," "Due today," "Due this week") is a real `<h2>` via `VisuallyHidden` where no visible section label exists, so a screen-reader user's heading list enumerates the same groups a sighted user scans visually rather than one undifferentiated list of cards.

**Live regions, calibrated to avoid noise.** A Reverb push that flips a request to `held` while a user is mid-review never yanks focus or silently re-sorts the list out from under them; it surfaces as a polite (`aria-live="polite"`, never `"assertive"`), dismissible "1 request now held — Refresh" banner instead, matching `docs/frontend/ACCESSIBILITY.md → Live regions`'s "auto-detect, never alarming" posture and rule 2's explicit prohibition on splicing a live update into a list a screen reader has already indexed. The Bulk Action Bar's own appearance is announced once via `role="status"` the instant a selection becomes non-empty — "3 selected — Approve or Clear" — rather than relying on a purely visual slide-in, per `docs/frontend/ACCESSIBILITY.md → Expandable rows and bulk selection`.

**Every disabled control explains itself, and the two reasons it can be disabled are never conflated.** A step's Approve button disabled because it is not yet the viewer's turn in the chain, a checkbox disabled because its row carries a `SENSITIVE_PERMISSIONS` key, and a whole card rendered read-only because the viewer lacks the step's `stepPermission` are three distinct conditions, and each carries its own `aria-describedby` text sourced from the permission system rather than a generic "You can't do this right now," per `docs/frontend/ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`:

```tsx
<Tooltip>
  <TooltipTrigger asChild>
    <span>
      <Button
        disabled={!isCurrentStep}
        aria-describedby={!isCurrentStep ? 'approve-disabled-reason' : undefined}
      >
        {t('approvals.approve')}
      </Button>
    </span>
  </TooltipTrigger>
  {!isCurrentStep && (
    <TooltipContent id="approve-disabled-reason" role="tooltip">
      {t('a11y.approval_not_your_turn', { role: currentStep.approverRole })}
    </TooltipContent>
  )}
</Tooltip>
```

**Bulk selection is a real, tri-state control tied to row identity.** The Table view's header checkbox carries `aria-checked="mixed"` when some but not all visible rows are selected, and every row checkbox has an accessible name tied to that request ("Select approval: July vendor payment run"), never a bare "Select," per `docs/frontend/ACCESSIBILITY.md → Expandable rows and bulk selection`'s exact rule for batch actions elsewhere in the product.

**Keyboard access covers the full screen without a mouse.** `Tab` order follows the visual reading order (Page Header → Filter Bar → Bulk Action Bar when present → grouped cards top to bottom → Pagination Footer); `ApprovalStepper`'s own step list is keyboard-navigable with arrow keys inside a `role="list"`; the Reject and Request Changes `AlertDialog`s trap focus per `docs/frontend/ACCESSIBILITY.md → Focus Management`'s dialog rule and return focus to the triggering card's action row on close, never to the page top. `⌘K` opens the Command Palette from anywhere on this screen exactly as it does everywhere else, and its "AI & Actions" group's live "N approvals waiting" entry is itself a second, always-available way to jump back to the top of this screen's own queue.

**`SwipeableApprovalCard`'s gesture is additive, never exclusive.** As stated in Responsive Behavior, the swipe interaction is disabled outright under `prefers-reduced-motion`, and even where enabled it never becomes the only way to decide a card — the Approve/Reject buttons beneath it are always present, always focusable, and satisfy the platform rule (restated in `docs/foundation/PERMISSION_SYSTEM.md`'s spirit and `docs/frontend/RESPONSIVE_DESIGN.md`'s own explicit statement) that an approval gate is never accessible through only one input modality.

# Performance

The Approval Center is held to the same first-load discipline as any List Page Template instance (`docs/frontend/FRONTEND_ARCHITECTURE.md → Bundle budgets`), with two considerations specific to a screen whose entire purpose is fast, confident triage of financially consequential items.

**RSC and streaming.** `app/(app)/approvals/page.tsx` is a Server Component that prefetches the first page of the default `pending, held, in_review` queue before the shell paints (`dehydrate`/`HydrationBoundary`, per `docs/frontend/FRONTEND_ARCHITECTURE.md → Data Layer`'s two-path rule); the Delegation `Sheet`'s own data and the "Include resolved" history view are both lazily fetched only once their respective UI is opened, never prefetched alongside the primary queue, since most sessions on this screen never open either.

**Query layer.** The primary queue's `staleTime: 10_000` with realtime correction (see Data & State) means a returning tab never shows a queue that is more than moments stale without an explicit reason to trust it further; the "Include resolved" `useInfiniteQuery` uses `placeholderData: keepPreviousData` so paging through months of history never flashes to an empty list between pages, and its rows — an unbounded, append-only set in the same shape as `ledger-entries`/`audit-logs` — render through `@tanstack/react-virtual` once the loaded set exceeds roughly 200 rows, per `docs/frontend/FRONTEND_ARCHITECTURE.md → Virtualization for large tables`, so a compliance reviewer scrolling a full year of resolved approvals never accumulates more mounted DOM nodes than are actually on screen.

**Realtime cost control.** This screen subscribes through the one shared `RealtimeProvider` connection already mounted in the `(app)` shell rather than opening a dedicated socket of its own, exactly as the compact widget does; on reconnect after a dropped connection, `approvalKeys.all` is invalidated once as a batch rather than trusted silently, since a missed `held` transition during an outage is a correctness bug, not a cosmetic one, per `docs/frontend/FRONTEND_ARCHITECTURE.md → Invalidate, or patch`.

**Web Vitals.** INP is watched specifically on the Approve/Reject buttons and the bulk-approve confirmation flow, since those are this screen's highest-stakes interactive surfaces and a regression here is tagged and monitored against a company-size-band baseline exactly as `docs/frontend/FRONTEND_ARCHITECTURE.md → Web Vitals` specifies platform-wide — a holding company clearing forty items a day and a brand-new company with an empty queue have legitimately different tail latencies, and neither is measured against the other's baseline.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| An approval's target record changes between proposal and the user's click | The screen re-fetches the live target immediately before rendering the Approve action and diffs it against the proposal's stored payload; on divergence, Approve is replaced with "Review changed data" rather than silently approving stale intent, per `docs/frontend/FRONTEND_ARCHITECTURE.md → Edge Cases`'s platform-wide rule for exactly this situation. |
| Fraud Detection holds a request while its card is already open on screen | The Reverb push patches that one row's `status` to `held` in place — never a full list invalidation, to avoid disrupting scroll position or an in-progress selection — and the left-border/badge treatment updates live without requiring a manual refresh. |
| Two browser tabs; one approves step 1 of a chain while the other is mid-read of the same request | The idle tab's next action against that request fails with a conflict response (the step has already advanced) and the UI re-fetches the request fresh rather than trusting its own stale local state, consistent with `docs/frontend/FRONTEND_ARCHITECTURE.md`'s general conflict-handling posture. |
| A bulk-approve batch's eligibility changes between selection and submission (a row is held, or someone else decides it, in the seconds before the button is clicked) | The batch endpoint still processes every still-eligible id; the response's `failed[]` array names exactly which ids did not go through and why, surfacing as a partial-success toast — never a silent partial failure and never a full-batch rejection over one now-ineligible row. |
| A viewer's own permission is revoked mid-session (a role change removes `ai.approve`) | The next request carrying a stale permissions claim receives the platform's standard `401 PERMS_STALE` handling (`docs/frontend/FRONTEND_ARCHITECTURE.md → Edge Cases`), forcing a session re-establishment rather than letting a stale client continue to render action buttons a fresh check would reject. |
| WebSocket disconnected for an extended period, then reconnects | `approvalKeys.all` is invalidated once as a batch on reconnect — a hold or a chain advancement missed during the outage must surface immediately once connectivity returns, never wait for a manual refresh, since a WebSocket has no replay. |
| An External Auditor or CFO needs a compliance-grade export of the resolved history | The "Include resolved" view carries its own `GET /api/v1/approvals/export` action (permission `reports.export`), producing a durable, always-light-themed CSV/PDF of the filtered historical range — distinct from, and never confused with, the Bulk Action Bar's deliberately-omitted export of in-flight rows described in Interactions & Flows, since exporting a partial snapshot of decisions still in motion has no legitimate downstream use the way a closed-period compliance record does. |
| A brand-new company, zero approval history | The queue renders "Nothing waiting on you" and the resolved history renders "No resolved approvals yet" — two distinct, genuinely reassuring empty states, never the same generic "no results" copy a filtered-to-zero search produces elsewhere in the product. |
| A company switch happens while this screen is open | `queryClient.clear()` and `router.refresh()` fire together (`docs/frontend/FRONTEND_ARCHITECTURE.md → Company switching`); the queue re-mounts and re-fetches under the new `X-Company-Id` rather than momentarily showing the previous company's pending items — a materially more sensitive failure mode here than on most screens, since a stale cross-company approval row would expose one tenant's financial decision to another. |
| A delegated approver is themselves later removed from the company, with an open delegation still pointing at them | The pending step's Delegate action is re-offered on next load rather than left silently pointing at a now-invalid user; the step's own history retains the original delegation record for audit purposes regardless of the delegate's current membership status. |
| Printing or exporting a single request | Not supported as a standalone "print this approval" action beyond the compliance export above — an individual decision's audit trail (who, when, reasoning) lives permanently in `audit_logs` and is retrievable there in full for any single record, which is the durable source of truth rather than a one-off print of this screen's own chrome. |

# End of Document
