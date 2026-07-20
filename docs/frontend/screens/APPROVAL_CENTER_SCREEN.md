# Approval Center Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Screens / APPROVAL_CENTER_SCREEN
---

# Purpose

This document is the concrete, implementation-ready screen specification for the Approval Center's landing
route, `app/(app)/approvals/page.tsx` — the single, cross-module inbox every sensitive action in QAYD routes
through before it becomes real, and the full-screen counterpart to the compact `approval_center_queue` widget
the Command Center home already renders. It is the structured, editor-beside-you companion to
`docs/frontend/APPROVAL_CENTER.md`, which specifies this exact surface in fuller prose — the argument for why
a cross-module queue exists at all, the two upstream approval systems Laravel's `ApprovalService` normalizes
into one envelope, the four human responses (Approve / Reject / Request Changes / Delegate), the `bank_transfer`
two-key chain, the bulk-safe subset, and the compliance-grade resolved history. This document does not
re-argue any of that; the two must never disagree. Where this document is silent on a fact,
`docs/frontend/APPROVAL_CENTER.md` governs first, then `docs/ai/AI_COMMAND_CENTER.md → Approval Center`,
`docs/foundation/PERMISSION_SYSTEM.md → Approval Chains`, then `FRONTEND_ARCHITECTURE.md`,
`docs/frontend/components/DRAWERS.md`, `docs/frontend/components/AI_WIDGETS.md`, `DESIGN_LANGUAGE.md`,
`COMPONENT_LIBRARY.md`, `LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md`, in
that order. Where this document appears to contradict one of them on a route, a permission key, an endpoint,
a component name, or a token, that is a defect to raise and resolve in review, never a decision an engineer
resolves unilaterally in code.

What this document adds beyond `docs/frontend/APPROVAL_CENTER.md` is the layer an engineer opens beside their
editor: the literal `page.tsx` / `layout.tsx` / `[approvalId]/page.tsx` source, the `DelegatePicker` full
implementation, the concrete wiring of the shared `ApprovalDrawer` (`docs/frontend/components/DRAWERS.md`) as
this screen's Table-view row-detail surface below `lg`, worked `GET /approvals` / approve / bulk-approve JSON
an engineer can paste into a fixture, a region-by-state matrix, concrete `grid`/`col-span` values, the exact
optimistic-vs-pessimistic mutation split, and the keyboard bindings that `docs/frontend/APPROVAL_CENTER.md`
names as decisions but does not spell out to the last detail. It introduces no new route, no new permission
key, and no new subject type or token those documents have not already defined.

Concretely, this screen composes five pieces of surface, each owned elsewhere and only rendered, grouped,
filtered, and routed here — never computed:

- **A permission-gated Page Header** — the title "Approvals," a live "14 pending · 2 breaching" count, a
  `scope` segmented toggle (My queue / All requests), and a secondary "Delegation" action opening a `Sheet` of
  the viewer's own standing/one-off delegations. There is deliberately **no primary "create" action** —
  nothing is authored here; every request originates elsewhere and arrives already formed.
- **A Filter Bar** — search, Type / Status / Urgency / Amount facets, a saved-filter chip row, and the
  Card/Table density toggle that switches the Data Region's *shape*, not just its row height.
- **The Data Region itself** — grouped `ApprovalCard` stacks by SLA-urgency bucket (Card view, default) or the
  identical rows through a `DataTable` (Table view, `lg`+ only).
- **A Bulk Action Bar** that replaces the Filter Bar's trailing controls on selection, offering the single
  bulk-safe action (Approve) over the eligible subset.
- **A companion, non-owned platform AI Rail** — the shell-level `360px` panel that may dock inline at `xl`+,
  distinct from and never a duplicate of this queue.

This screen owns no business logic and — the load-bearing structural fact — **never itself moves money.**
Approving a `tax_submission` here authorizes Tax's own "File Return" action to run; approving a `bank_transfer`'s
final step authorizes Banking's own settlement; the actual money-moving execution is always a separate,
pessimistic mutation on the owning module's own screen. Every figure rendered here was computed and persisted
by Laravel; every decision calls `/api/v1/approvals/{id}/…` guarded by the exact per-step permission the API
itself enforces — the frontend's own checks are a courtesy, never the authority (`FRONTEND_ARCHITECTURE.md`'s
three inherited constraints: the frontend computes nothing; AI is visible, labeled, and never silent; RBAC is
enforced by the API and the UI only reflects it).

# Route & Access

## App Router path

```text
app/(app)/approvals/
├── layout.tsx                        # Resolves the view gate once; renders the module-rooted breadcrumb + error boundary
├── page.tsx                          # * THIS DOCUMENT — the queue (GET /api/v1/approvals)
├── loading.tsx                       # Queue-shaped skeleton — see # States
└── [approvalId]/
    └── page.tsx                      # Single-request detail (GET /api/v1/approvals/{id}) — notification / palette / deep-link entry
```

Both routes sit inside `FRONTEND_ARCHITECTURE.md`'s App Router tree and inside the dedicated
`components/approvals/` folder its Folder Structure reserves. Per `docs/frontend/NAVIGATION_SYSTEM.md → Primary
Navigation`, this route is **not nested under `/ai`** even though `ai_approval_requests` is an AI Command
Center table, and it is **not one of the ten primary Sidebar modules** — it has no icon-rail entry of its own.
It is reached only through entry points that already know an approval is waiting: the Command Palette's "AI &
Actions" group ("3 approvals waiting" jumps straight to `/approvals`), a Notifications bell row whose `target`
resolves to `/approvals/{id}`, any module's own inline `ApprovalCard`'s "View in Approval Center" overflow
action, and a browser bookmark for the roles that work this screen daily. The breadcrumb is a single,
module-rooted segment — "Approvals" (الاعتمادات) — with no parent crumb, because a cross-module queue does not
conceptually descend from any one of the ten modules it aggregates.

## Permission gate

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible / act on eligible requests | `ai.approve` | The broad actor grant — see the scope toggle below. |
| Screen visible / view the full queue read-only | `reports.read` | Sees everything, decides nothing — every `ApprovalCard` renders without an action row. |
| Screen visible / act on only your own addressed items | step-level match on `approver_user_id` or `approver_role` | A user holding neither broad permission still sees the screen, scoped to exactly the items addressed to them. |
| `scope` toggle (My queue / All requests) | any broad `ai.approve`/`reports.read` | A narrowly-scoped viewer never sees the toggle — only their own addressed items. |
| Approve / Reject / Request Changes on a step | the step's own `stepPermission`, else the request's `requiredPermission` | Rendered disabled with an `aria-describedby` reason (never your step / not your turn / missing permission), never silently omitted — a settled or not-yet-reached step is legible, not mysterious. |
| Delegate on a step | the step's `approver_role`/permission grant + being its assigned approver | The Delegate control renders only when the viewer is the assigned approver for the currently-pending step. |
| Bulk-approve a selection | uniform `requiredPermission` across the whole selection | A row's checkbox is *disabled* (with a tooltip) when its `requiredPermission` is one of `SENSITIVE_PERMISSIONS` (`bank.transfer`, `payroll.run.approve`, `tax.submit`) or when it is `held`. |
| "Include resolved" history + its export | `reports.read` (view), `reports.export` (export) | The toggle and its `GET /approvals/export` action are omitted. |

A user matching none of the three view conditions sees the shared forbidden boundary rendered by
`layout.tsx`'s `error.tsx` — "You don't have access to this" with a link back to the Command Center — never a
silent redirect (`docs/frontend/NAVIGATION_SYSTEM.md → Server truth, client courtesy`).

## Roles

| Role | What renders on this screen |
|---|---|
| Owner, CFO | Every request across every module, every status, `scope=all` by default; Approve/Reject/Request Changes/Delegate on any step assigned to their role; bulk-approve where eligible. |
| Finance Manager, Senior Accountant | Every request, but `scope=mine` by default, toggleable to `scope=all` read-only for anything not addressed to them; full action set on their own assigned steps only. |
| Accountant, Purchasing/Sales/Inventory/Payroll module roles | Only requests where they are `requested_by` (tracking their own submission's status) or a named future-step approver they can preview; read-only everywhere except a Delegate they were the recipient of. |
| Auditor, External Auditor | Every request, every historical state, permanently read-only — no `ApprovalCard` on this screen ever renders an action row for these roles; read access and decision access are two separately-checked permissions, never implied by each other. Export (`reports.export`) remains available to Auditor. |
| AI service account | Never renders this screen as a user; structurally incapable of holding `ai.approve` or any subject-specific approval permission, regardless of role grant. |

Keyboard entry: `G` then `V` opens this screen from anywhere (the "Go to a**pprov**als" mnemonic in
`ACCESSIBILITY.md`'s registry); on the route, `/` focuses the Filter Bar search, and `⌘K`'s "AI & Actions"
group is a second always-available way back to the top of the queue.

# Layout & Regions

This screen is an instance of `LAYOUT_SYSTEM.md`'s **List Page Template**, extended rather than bypassed, per
that document's rule that "a screen never invents a sixth layout shape; if a new screen doesn't fit, the
template is extended (a new named slot), not bypassed." Two extensions are specific to this screen: the Data
Region defaults to grouped `ApprovalCard` stacks rather than a flat table, and the density toggle switches the
Data Region's *shape* — Card view (default) versus Table view. The desktop wireframe is reproduced from
`docs/frontend/APPROVAL_CENTER.md → Layout & Regions`; the tablet/mobile collapse is that document's
`# Responsive Behavior`, drawn out here.

## Desktop (`xl`+, ≥1280px)

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Approvals                                    14 pending · 2 breaching     │  Page Header
│ [My queue ▾]                                        [Delegation [gear]]        │
├─────────────────────────────────────────────────────────────────────────┤
│ [Search…] [Type ▾][Status ▾][Urgency ▾][Amount ▾]      [▤ Card|Table][[gear]] │  Filter Bar
├─────────────────────────────────────────────────────────────────────────┤
│  ── Breaching SLA ──────────────────────────────────────────────────     │  Data Region
│  ┃ Payment to Al-Fajr Cement Supplies — held               [HELD]        │  (grouped
│  ┃ KWD 18,540.000 · Fraud Detection · SLA breached 09:40                 │   ApprovalCards,
│  ┃ [View reasoning ▾]                          [Reject] [Approve]        │   held pinned first)
│  ── Due today ──────────────────────────────────────────────────────     │
│  July vendor payment run (6 vendors)             Approval 2 of 2         │
│  KWD 24,180.000 · Treasury Manager · Step: Mariam Al-Sabah, CFO          │
│  [Delegate] [Reject] [Approve]                                           │
├─────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 41 pending          [‹ Prev] [Next ›]  [Include resolved]│  Pagination Footer
└─────────────────────────────────────────────────────────────────────────┘
```

## Tablet (`md`–`lg`, 768–1279px)

Table view is available only at `lg`+; below `lg` the density toggle itself is hidden and the screen forces
Card view (a dense Amount/Confidence/Stage/SLA/Actions table has no legible tablet shape,
`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`). The Filter Bar collapses to search plus a "Filters"
`Sheet`; the scope toggle and Delegation collapse into a single overflow menu; the Bulk Action Bar becomes a
sticky bottom bar so it never scrolls out of reach on a long touch scroll. A Table-view row opened for detail
below `lg` renders through the shared **`ApprovalDrawer`** (`docs/frontend/components/DRAWERS.md`,
`width="md"`, `side="end"`), which reads the request with its full AI contract and the same four actions while
the list stays visible behind it.

## Mobile (`base`–`sm`, <768px)

Card view only, one `SwipeableApprovalCard` at a time; Page Header shows title + count only, with the scope
toggle and Delegation in a header-icon `Sheet`; the Bulk Action Bar is a sticky bottom bar. A swipe toward the
logical end approves, toward the logical start rejects (mirrored under RTL), but the swipe is always an
*accelerator* — the explicit Approve/Reject buttons remain the only path for a screen-reader, switch-control,
or `prefers-reduced-motion` user, and a swipe still opens the identical confirming `AlertDialog` a desktop
click would.

## Region table with implementation-level grid classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| Page Header | `col-span-12 border-b border-ink-4 px-6 py-5` | Title, dual count, scope toggle, Delegation | Second count clause omitted entirely when zero — never "0 breaching" |
| Filter Bar | `col-span-12 flex flex-wrap items-center gap-2 px-4 py-3` | Search + Type/Status/Urgency/Amount + saved-filter chips + Card/Table toggle | Amount filter hidden when every visible type is amount-less |
| Bulk Action Bar | replaces Filter Bar's trailing side, `flex items-center gap-3` | Count + Approve + Clear | Selection disabled at the checkbox for `SENSITIVE_PERMISSIONS`/`held` rows |
| Data Region (Card) | `col-span-12 space-y-6` (one `space-y-3` stack per urgency group) | `ApprovalCard`s grouped Breaching → Due today → Due this week → No SLA, `held` pinned first inside each bucket | Every card is `radius-lg` + hairline + `shadow-xs`; a `held` card adds a 4px `negative` left border |
| Data Region (Table) | `col-span-12` `DataTable` | Type · Title · Amount · Requester/Agent · Confidence · Stage · SLA · Actions | `lg`+ only |
| Pagination Footer | `col-span-12 border-t border-ink-4 px-6 py-3` | Page-mode (default) / cursor ("Include resolved") | The one toggle that changes the underlying query |

```tsx
// app/(app)/approvals/page.tsx — Server Component, prefetches the default queue
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { approvalKeys } from "@/lib/api/query-keys";
import { PageHeader } from "@/components/layout/page-header";
import { ApprovalQueue } from "@/components/approvals/approval-queue";
import { ApprovalScopeToggle } from "@/components/approvals/approval-scope-toggle";
import { DelegationSheetTrigger } from "@/components/approvals/delegation-sheet-trigger";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";
import type { ApprovalFilters } from "@/types/approvals";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function ApprovalCenterPage({
  searchParams,
}: {
  searchParams: Promise<{ scope?: string; status?: string; type?: string; urgency?: string; q?: string; view?: string }>;
}) {
  const sp = await searchParams;
  const filters: ApprovalFilters = {
    scope: sp.scope === "all" ? "all" : "mine",
    status: sp.status ?? "pending,held,in_review",
    subject_type: sp.type,
    urgency: sp.urgency,
    q: sp.q,
  };
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: approvalKeys.queue(filters),
    queryFn: () => apiServer.get("/approvals", { params: filters }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="space-y-4">
        <PageHeader
          breadcrumb={[{ label: "Approvals", href: "/approvals" }]}
          title="Approvals"
          slotStart={<ApprovalScopeToggle />}
          actions={<DelegationSheetTrigger />}
        />
        <Suspense fallback={<WidgetSkeleton variant="approval-queue" />}>
          <ApprovalQueue initialFilters={filters} defaultView={sp.view === "table" ? "table" : "card"} />
        </Suspense>
      </div>
    </HydrationBoundary>
  );
}
```

```tsx
// app/(app)/approvals/[approvalId]/page.tsx — single-request detail (notification / palette / deep link)
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { approvalKeys } from "@/lib/api/query-keys";
import { ApprovalDetail } from "@/components/approvals/approval-detail";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function ApprovalDetailPage({ params }: { params: Promise<{ approvalId: string }> }) {
  const { approvalId } = await params;
  const id = Number(approvalId);
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: approvalKeys.detail(id),
    queryFn: () => apiServer.get(`/approvals/${id}`),
  });
  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      {/* renders the identical ApprovalRequest shape as a single-record page: a larger ReasoningDisclosure,
          the full ApprovalStepper timeline with every step's comment, the same four actions. Approving here
          mutates the same approvalKeys.detail(id) cache the list row does — neither view can show a decision
          the other has not also reflected. */}
      <ApprovalDetail approvalId={id} />
    </HydrationBoundary>
  );
}
```

# Components Used

Every visual element is drawn from `COMPONENT_LIBRARY.md`'s catalogue, the platform's `components/ai/` set, or
the screen-scoped compositions in `components/approvals/`. This section gives the concrete prop contracts and,
for the pieces `docs/frontend/APPROVAL_CENTER.md` names without showing source, the actual implementation.

| Component | Source | New in this document |
|---|---|---|
| `ApprovalCard` | `components/shared/approval-card.tsx` (AI-relevant props in `docs/frontend/components/AI_WIDGETS.md → ApprovalCard`; `PERMISSION_BY_KIND` additions in `docs/frontend/APPROVAL_CENTER.md → Components Used`) | No — reused; `reasoning`/`sources` props render a `ReasoningDisclosure` beneath the action row |
| `ApprovalStepper` | `components/ai/approval-stepper.tsx` | No — cited; renders the `steps[]` array verbatim, no fixed step count |
| `ApprovalDrawer` | `components/ai/approval-drawer.tsx` (full source in `docs/frontend/components/DRAWERS.md → The Approval Drawer`) | No — cited; this screen's Table-view row-detail surface below `lg` and its two-key-chain gate host |
| `ConfidenceBadge`, `ReasoningDisclosure`, `StatusPill`, `DataTable`, `Checkbox`, `AlertDialog`, `Sheet`, `Popover`, `Tooltip`, `Badge`, `Skeleton`/`EmptyState`/`ErrorState`, Toast | `COMPONENT_LIBRARY.md` / `components/ai/*` (existing) | No — reused verbatim |
| `SwipeableApprovalCard` | `components/ai/swipeable-approval-card.tsx` | No — cited; mobile/tablet touch accelerator, `useReducedMotion()` disables the gesture |
| `ApprovalScopeToggle` | `components/approvals/approval-scope-toggle.tsx` | **Yes** — prop contract below |
| `DelegatePicker` | `components/approvals/delegate-picker.tsx` | **Yes** — full source below |
| `DelegationSheet` | `components/approvals/delegation-sheet.tsx` | **Yes** — prop contract below |
| `ApprovalUrgencyGroup` | `components/approvals/approval-urgency-group.tsx` | **Yes** — full source below |

## `ApprovalUrgencyGroup`

The grouping shell that renders one SLA-urgency bucket as a real `<section>` with a `VisuallyHidden` `<h2>`
(so a screen reader's heading list enumerates the same groups a sighted user scans), sorting `held` rows first
inside the bucket regardless of their own `slaDueAt` — a fraud hold is never one scroll further down than a
routine item.

```tsx
// components/approvals/approval-urgency-group.tsx
"use client";

import { VisuallyHidden } from "@/components/ui/visually-hidden";
import { ApprovalCard } from "@/components/shared/approval-card";
import { useTranslations } from "@/lib/i18n";
import type { ApprovalRequest } from "@/types/approvals";

const heldFirst = (a: ApprovalRequest, b: ApprovalRequest) =>
  (b.status === "held" ? 1 : 0) - (a.status === "held" ? 1 : 0);

export function ApprovalUrgencyGroup({
  bucket, requests, onDecide,
}: {
  bucket: "breaching" | "due_today" | "due_week" | "none";
  requests: ApprovalRequest[];
  onDecide: (id: number, verb: "approve" | "reject" | "request_changes" | "delegate") => void;
}) {
  const { t } = useTranslations("approvals");
  if (requests.length === 0) return null;
  return (
    <section aria-labelledby={`grp-${bucket}`} className="space-y-3">
      <h2 id={`grp-${bucket}`} className="text-caption font-medium text-ink-9">{t(`urgency.${bucket}`)}</h2>
      {[...requests].sort(heldFirst).map((r) => (
        <ApprovalCard
          key={r.id}
          kind={r.subjectType}
          title={r.title}
          amount={r.amount ?? undefined}
          requestedBy={r.requestedBy ?? undefined}         // omitted for pure AI rows — attribution is agent_code
          confidence={r.confidenceScore != null ? r.confidenceScore / 100 : null}
          reasoning={r.reasoning}
          sources={r.sources}
          approvalLevel={r.steps.length > 1 ? { current: r.steps.filter((s) => s.status !== "pending").length + 1, total: r.steps.length } : undefined}
          requiredPermission={r.requiredPermission}
          steps={r.steps}
          detailHref={`/approvals/${r.id}`}
          onApprove={() => onDecide(r.id, "approve")}
          onReject={() => onDecide(r.id, "reject")}
          onRequestChanges={() => onDecide(r.id, "request_changes")}
          onDelegate={() => onDecide(r.id, "delegate")}
        />
      ))}
    </section>
  );
}
```

## `DelegatePicker`

A searchable combobox scoped to users who hold the *same role or permission* the pending step requires — you
cannot delegate to someone who could not legally decide it. Built on the identical `Command`+`Popover` pattern
as `AccountPicker`; the API additionally rejects an ineligible target with a field-level `422`.

```tsx
// components/approvals/delegate-picker.tsx
"use client";

import { useState } from "react";
import { Command, CommandInput, CommandList, CommandItem, CommandEmpty } from "@/components/ui/command";
import { Popover, PopoverTrigger, PopoverContent } from "@/components/ui/popover";
import { Button } from "@/components/ui/button";
import { UserPlus } from "lucide-react";
import { Icon } from "@/components/ui/icon";
import { useEligibleDelegates } from "@/hooks/approvals/use-eligible-delegates";
import { useTranslations } from "@/lib/i18n";

export function DelegatePicker({
  stepPermission, onDelegate, triggerVariant = "ghost", disabled,
}: {
  stepPermission: string;
  onDelegate: (userId: number) => Promise<void>;
  triggerVariant?: "ghost" | "outline";
  disabled?: boolean;
}) {
  const { t } = useTranslations("approvals");
  const [open, setOpen] = useState(false);
  // Scoped server-side to holders of stepPermission; the picker also filters client-side so an ineligible
  // name is never offered in the first place.
  const { data: candidates = [] } = useEligibleDelegates(stepPermission, { enabled: open });

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant={triggerVariant} size="sm" disabled={disabled}>
          <Icon icon={UserPlus} size="sm" aria-hidden /> {t("delegate")}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-72 p-0">
        <Command>
          <CommandInput placeholder={t("delegateSearch")} />
          <CommandList>
            <CommandEmpty>{t("delegateNoneEligible")}</CommandEmpty>
            {candidates.map((u) => (
              <CommandItem key={u.id} value={u.name} onSelect={async () => { await onDelegate(u.id); setOpen(false); }}>
                {u.name} <span className="ms-auto text-caption text-ink-9">{u.roleLabel}</span>
              </CommandItem>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
```

## `ApprovalScopeToggle` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `current` | `'mine' \| 'all'` | Resolved server-side before first paint from the caller's broad grant. |

A Radix `ToggleGroup` (My queue / All requests) that rewrites `?scope=`, rendered only when the viewer holds a
broad `ai.approve`/`reports.read`; a narrowly-scoped viewer never sees it. Toggling `all` for a viewer with no
broad *act* grant renders the extra rows read-only.

## `DelegationSheet` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `open` / `onOpenChange` | `boolean` / `(o) => void` | Controlled. |

The Page Header's "Delegation" action opens this `Sheet` listing the viewer's own standing delegations (e.g.
delegating all Purchasing-tier approvals to a deputy for a fixed date range) alongside one-off delegations
made inline from a card, so a reviewer never has to remember which mechanism produced which hand-off. Lazily
fetched only when opened — never prefetched alongside the primary queue.

## The Approval Drawer, on this screen

`docs/frontend/components/DRAWERS.md → The Approval Drawer` owns `ApprovalDrawer`'s full implementation and its
five contracts (AI never silent; approve/reject/delegate never auto-commit; reject always carries a reason;
the per-step gate wins over the request-level key; delegate is scoped). This screen consumes it, unchanged, as
the Table-view row-detail surface below `lg` (`docs/frontend/APPROVAL_CENTER.md → Responsive Behavior`), and it
is the concrete host of the **`bank_transfer` two-key chain** gate: the drawer resolves
`const gate = currentStep?.stepPermission ?? request.requiredPermission` and disables the actions for a viewer
who is not the assigned approver of the *current* step — so a viewer who holds `bank.payment.approve.final` but
whose current pending step needs `bank.payment.approve` sees the actions disabled, not a false path to decide
someone else's step. Nothing about that logic is re-implemented on this screen; the drawer is the one place it
lives, and the inline card and the `[approvalId]` detail page resolve the same gate through the same fields.

# Data & State

## Types

Reproduced from `docs/frontend/APPROVAL_CENTER.md → Data & State` — the frontend renders one normalized
`ApprovalRequest` shape regardless of which upstream engine (`ai_approval_requests`/`ai_approval_steps` or the
older `approval_policies`/`approval_steps`/`approval_actions`) produced the underlying row; `ApprovalService`
merges both server-side.

```ts
// types/approvals.ts (the fields this screen reads — full definition in docs/frontend/APPROVAL_CENTER.md)
export type ApprovalStatus =
  | "pending" | "held" | "in_review" | "approved"
  | "rejected" | "changes_requested" | "expired" | "cancelled";

export type ApprovalSubjectType =
  | "bank_transfer" | "payroll_release" | "tax_submission" | "journal_void"
  | "permission_change" | "purchase_order" | "credit_note" | "credit_hold" | "vendor_bank_update"
  | "journal_entry_post" | "vendor_bill" | "expense_claim" | "record_delete";

export interface ApprovalStep {
  id: number; stepOrder: number; approverRole: string; approverUserId: number | null;
  status: ApprovalStatus; decidedBy: number | null; decidedAt: string | null;
  comment: string | null;
  stepPermission: string | null; // present only when a step's gate is finer than the request-level key
}

export interface ApprovalRequest {
  id: number; subjectType: ApprovalSubjectType; subjectId: number | null;
  title: string; amount: { value: string; currencyCode: string } | null;
  status: ApprovalStatus; requiredPermission: string; holdReason: string | null;
  slaDueAt: string | null; decidedAt: string | null;
  requestedBy: { id: number; name: string } | null;   // null when purely AI-originated
  sourceAgentCode: string | null;                     // null when purely human-submitted
  confidenceScore: number | null;                     // 0–100; null when human-submitted
  reasoning: string | null;
  sources: Array<{ type: string; id: number | null; label: string }>;
  steps: ApprovalStep[]; createdAt: string;
}
```

## Endpoints this screen calls

| Purpose | Endpoint | Permission | First-paint or on-demand |
|---|---|---|---|
| Queue | `GET /approvals?scope=&filter[status][in]=&filter[subject_type][in]=&filter[urgency]=&q=&sort=` | `ai.approve` (act) / `reports.read` (view) | First paint |
| One request | `GET /approvals/{id}` | Same, or a step-level `approver_user_id`/`approver_role` match | On-demand (`[approvalId]`, inline card, drawer) |
| Approve | `POST /approvals/{id}/approve` | the step's `stepPermission`, else `requiredPermission` | Card/drawer/detail action |
| Reject | `POST /approvals/{id}/reject` | Same | Action (mandatory `reason`) — terminates the whole chain |
| Request Changes | `POST /approvals/{id}/request-changes` | Same | Action (mandatory `note`) → `changes_requested` |
| Delegate | `POST /approvals/{id}/delegate` | Same | Action (`user_id`) — only to a holder of the step's role |
| Bulk-approve | `POST /approvals/bulk-approve` | uniform `requiredPermission` across the selection | Bulk Action Bar |
| Resolved-history export | `GET /approvals/export` | `reports.export` | "Include resolved" view action |

Sort default is `sla_due_at` ascending, nulls last, then `-created_at`. The default `pending,held,in_review`
view is page-mode (a bounded working set); "Include resolved" switches to cursor-mode (`approved`/`rejected`/
`expired`/`cancelled` make the combined set an unbounded, append-only log the same shape as `ledger-entries`).

## Worked request/response examples

```json
// GET /api/v1/approvals?scope=mine&filter[status][in]=pending,held,in_review  →  200 OK (excerpt)
{ "success": true, "data": [
    { "id": 5120, "subjectType": "bank_transfer", "subjectId": 8841,
      "title": "Payment to Al-Fajr Cement Supplies", "amount": { "value": "18540.000", "currencyCode": "KWD" },
      "status": "held", "requiredPermission": "bank.transfer", "holdReason": "Vendor IBAN changed 2 hours ago",
      "slaDueAt": "2026-07-16T09:40:00Z", "decidedAt": null,
      "requestedBy": null, "sourceAgentCode": "FRAUD_DETECTION", "confidenceScore": 88.0,
      "reasoning": "Beneficiary IBAN was updated 2h before this run; the change originated from an email not matching the vendor's on-file domain.",
      "sources": [ { "type": "vendor_bank_update", "id": 771, "label": "Vendor bank detail change #771" } ],
      "steps": [
        { "id": 9001, "stepOrder": 1, "approverRole": "Finance Manager", "approverUserId": 118, "status": "pending", "decidedBy": null, "decidedAt": null, "comment": null, "stepPermission": "bank.payment.approve" },
        { "id": 9002, "stepOrder": 2, "approverRole": "CEO", "approverUserId": 101, "status": "pending", "decidedBy": null, "decidedAt": null, "comment": null, "stepPermission": "bank.payment.approve.final" }
      ], "createdAt": "2026-07-16T07:40:00Z" },
    { "id": 5121, "subjectType": "journal_entry_post", "subjectId": 55231,
      "title": "JE-2026-07-0482 · Rent accrual", "amount": { "value": "3200.000", "currencyCode": "KWD" },
      "status": "pending", "requiredPermission": "accounting.journal.approve", "holdReason": null,
      "slaDueAt": "2026-07-16T17:00:00Z", "decidedAt": null,
      "requestedBy": { "id": 902, "name": "S. Rahman" }, "sourceAgentCode": null, "confidenceScore": null,
      "reasoning": null, "sources": [],
      "steps": [ { "id": 9010, "stepOrder": 1, "approverRole": "Finance Manager", "approverUserId": 118, "status": "pending", "decidedBy": null, "decidedAt": null, "comment": null, "stepPermission": null } ],
      "createdAt": "2026-07-16T08:10:00Z" } ],
  "message": "OK", "errors": [],
  "meta": { "pagination": { "mode": "page", "page": 1, "per_page": 25, "total": 41, "breaching_count": 2 } },
  "request_id": "d1e2…", "timestamp": "2026-07-16T09:02:00Z" }
```

The first row is a pure AI proposal (`sourceAgentCode` set, `requestedBy` null, `confidenceScore: 88.0` → the
card renders a `ConfidenceBadge` and an `AgentAttributionChip`); the second is a plain human submission
(`sourceAgentCode: null`, `confidenceScore: null` → **no** `ConfidenceBadge`, `requestedBy`'s name shown
instead). The two shapes are visually distinguishable at a glance without either looking like a degraded
version of the other.

```json
// POST /api/v1/approvals/5120/approve   (Idempotency-Key: <uuid>)   →  200 OK
{ "success": true, "data": {
    "id": 5120, "status": "in_review",
    "steps": [
      { "id": 9001, "stepOrder": 1, "status": "approved", "decidedBy": 118, "decidedAt": "2026-07-16T09:12:00Z", "comment": "IBAN confirmed by phone" },
      { "id": 9002, "stepOrder": 2, "status": "pending", "decidedBy": null, "decidedAt": null, "comment": null }
    ] },
  "message": "Step approved; advanced to the next step", "errors": [], "meta": { "pagination": null },
  "request_id": "e3f4…", "timestamp": "2026-07-16T09:12:00Z" }
```

Approving step 1 advances the chain to step 2 rather than resolving the whole request — a request only reaches
the next step once the current step approves; a rejection at *any* step terminates the whole chain.

```json
// POST /api/v1/approvals/bulk-approve   { "ids": [5130, 5131, 5132, 5133, 5134, 5135] }  →  200 OK
{ "success": true, "data": {
    "succeeded": [5130, 5131, 5132, 5133, 5135],
    "failed": [ { "id": 5134, "code": "ROW_HELD", "message": "This request was placed on hold before the batch ran." } ] },
  "message": "5 approved, 1 skipped", "errors": [], "meta": { "pagination": null },
  "request_id": "f5a6…", "timestamp": "2026-07-16T09:20:00Z" }
```

A single ineligible id fails only that id — never the whole batch — surfacing as a partial-success toast.

## Query keys, cache tuning, and hooks

```ts
// lib/api/query-keys.ts (Approval Center — reused verbatim from FRONTEND_ARCHITECTURE.md, + history)
export const approvalKeys = {
  all: ["approvals"] as const,
  queue: (filters: ApprovalFilters) => [...approvalKeys.all, "queue", filters] as const,
  detail: (id: number) => [...approvalKeys.all, "detail", id] as const,
  history: (filters: ApprovalHistoryFilters) => [...approvalKeys.all, "history", filters] as const,
};
```

The default queue is a "transactional list," but this screen tightens the default the same way the compact
widget did — `staleTime: 10_000` with `refetchOnWindowFocus: true`, corrected in the interim by realtime
invalidation, never a shorter poll — because a held fraud flag or an SLA-breaching item must not sit stale for
thirty seconds. The "Include resolved" view is a `useInfiniteQuery` with `keepPreviousData`.

```ts
// lib/api/hooks/use-approvals-queue.ts
export function useApprovalsQueue(filters: ApprovalFilters) {
  return useQuery({
    queryKey: approvalKeys.queue(filters),
    queryFn: () => api.get<ApprovalRequest[]>("/approvals", { params: filters }),
    placeholderData: keepPreviousData,
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
}
```

## Mutation strategy — optimistic vs. pessimistic

Optimism is calibrated to what the action actually does, not to who or what proposed it
(`docs/frontend/APPROVAL_CENTER.md → AI Integration`):

| Mutation | Strategy | Rationale |
|---|---|---|
| `useApproveRequest` | **Optimistic** — `onMutate` flips that step's status and, if it was the final step, the whole request's status, in the cache immediately; `Idempotency-Key` so a slow spinner + second tap can't double-approve; rolls back on any `4xx`/`5xx` | Deciding on a request is reversible-adjacent — a wrong approval is caught at the next step or reversed procedurally, and the approve click itself moves no money |
| `useRejectRequest` | **Optimistic** | Same rationale; terminates the chain in cache, reconciles on the server response |
| `useRequestChanges` | **Optimistic** | Routes the note to `ai_memory` (AI-originated) or the source document (human), server-side; the cache patch is reversible |
| `useDelegateRequest` | **Optimistic** | Reassigns the step in cache; rolls back on a `422` (ineligible delegate) |
| `useBulkApprove` | **Pessimistic — no `onMutate`** | A multi-row financial decision is never optimistic even though a single Approve is; the per-item `failed[]` result is the authoritative outcome |

```ts
// lib/api/hooks/use-bulk-approve.ts — no optimism; a multi-row financial decision awaits the server
export function useBulkApprove() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (ids: number[]) => api.post<BulkApproveResult>("/approvals/bulk-approve", { ids }, crypto.randomUUID()),
    onSuccess: (result) => {
      if (result.failed.length > 0) toast.warning(t("bulkPartial", { succeeded: result.succeeded.length, failed: result.failed.length }));
      else toast.success(t("bulkSucceeded", { count: result.succeeded.length }));
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: approvalKeys.all });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] }); // the urgent-actions badge
    },
  });
}
```

The money-moving execution the approval eventually authorizes — Banking's transfer settlement, Payroll's
disbursement, Tax's `POST /tax/returns/{id}/file` — is a separate, **pessimistic** mutation on that module's
own screen, never something this screen's Approve click performs directly (Principle 10, "optimistic where
safe, pessimistic where it moves money").

## Realtime

The screen subscribes to `private-company.{id}.approvals` through the single shared `RealtimeProvider`
connection mounted once in the `(app)` shell — never a second socket:

```ts
echo.private(`company.${companyId}.approvals`)
  .listen(".approval.status_changed", (e: ApprovalStatusChangedEvent) => {
    if (e.status === "held") {
      // Patch the one affected row in place — preserves scroll position and any in-progress selection.
      queryClient.setQueryData(approvalKeys.detail(e.id), (old: ApprovalRequest) => ({ ...old, status: "held", holdReason: e.hold_reason }));
      queryClient.invalidateQueries({ queryKey: approvalKeys.all, refetchType: "none" }); // marks stale without yanking the visible list
    } else {
      queryClient.invalidateQueries({ queryKey: approvalKeys.all });
    }
  });
```

On reconnect after a drop, `approvalKeys.all` is invalidated once as a batch — a missed `held` transition
during an outage is a correctness bug, not a cosmetic one, and a WebSocket has no replay. `APPROVAL_ASSISTANT`
authors and routes every request and drives every SLA timer; `FRAUD_DETECTION` is the only other writer, and
only to set `status = 'held'`.

# Interactions & Flows

Numbered as a concrete implementation sequence; the narrative form is `docs/frontend/APPROVAL_CENTER.md →
Interactions & Flows`'s own territory.

1. **Landing.** Default filter `scope=mine` (or `all` for a broad holder with no narrower stage today),
   `status IN (pending, held, in_review)`, grouped by urgency, `held` pinned first inside its bucket. Arriving
   from a notification/palette lands with that request's card scrolled into view and its `ReasoningDisclosure`
   pre-expanded (the `?highlight={id}` convention).
2. **Reviewing.** Expanding a card's reasoning shows the full `reasoning` and every `sources` entry as
   clickable citations. Immediately before rendering Approve, the client re-fetches the request's live target
   and diffs it against the proposal's stored payload; on divergence, Approve is replaced with "Review changed
   data," never a silent approval of stale intent.
3. **Approve.** A single click for a single-step request; for a chain, only the step matching the viewer's own
   role/user is interactive, and approving it advances the chain rather than resolving the whole request. The
   mutation is optimistic + `Idempotency-Key`'d.
4. **Reject.** Opens an `AlertDialog` collecting a mandatory reason (Confirm reject disabled until non-empty);
   rejecting at any step terminates the entire request — a rejected request is dead; a revised proposal
   re-enters the queue as a new request.
5. **Request Changes.** Distinguished carefully from Reject. Opens an `AlertDialog` collecting a mandatory
   note, transitions to `changes_requested`, and routes the note (to `ai_memory` for AI-originated, to the
   source document's Detail page for human-submitted) without the reviewer needing to know which.
6. **Delegate.** Rendered only when the viewer is the assigned approver for the current step; opens
   `DelegatePicker`, scoped to holders of the step's own role; records both original and delegate in the audit
   trail — delegation is *reassignment with attribution*, never an anonymous hand-off.
7. **Bulk-approve.** Selecting rows surfaces the Bulk Action Bar; a row's checkbox is disabled (with a
   tooltip) for a `SENSITIVE_PERMISSIONS` key or a `held` status; a valid selection must share one
   `subjectType` (the Approve button is *absent*, not disabled, for a mixed-type selection). Clicking Approve
   opens one `AlertDialog` naming the exact count and total ("Approve 6 expense reclassifications — KWD
   1,140.000 total?") before `POST /approvals/bulk-approve` fires.
8. **Switching Card/Table + "Include resolved."** Card/Table density and Include-resolved are client-side view
   prefs (`useUiStore`); only "Include resolved" changes the underlying query (page-mode → cursor-mode).
9. **Opening detail directly.** `[approvalId]/page.tsx` renders the same `ApprovalRequest` shape as a
   single-record page; approving from here and from the list row mutate the identical `approvalKeys.detail(id)`
   cache, so neither view can show a decision the other has not.

# AI Integration

This screen is the literal terminus of QAYD's "AI proposes; a human approves" rule — every "Send for approval"
button, every module's inline `ApprovalCard`, and every automatic fraud hold deposit into the same queue.
Because everything here has, by definition, already crossed from "AI could act" to "a human must decide," the
three-button pattern never applies the way it does on the Insights/Recommendations feeds — **"Do it" never
renders here, structurally, for any row, regardless of confidence.** A recommendation that clears its company's
autonomy threshold executes from its own card and never becomes an `ai_approval_requests` row at all; a row
exists in this queue precisely because it did not clear that bar, or because its `subjectType` is one QAYD
never allows to auto-execute (`bank_transfer`, `payroll_release`, `tax_submission`, `permission_change`, any
`record_delete`). This screen's only vocabulary is Approve, Reject, Request Changes, Delegate.

| Rule | Concrete effect on this screen |
|---|---|
| Confidence normalization | Every AI-originated row's `confidenceScore` (0–100) passes through `normalizeConfidence(score, 'percentage')` → 0–1 for `ConfidenceBadge`; a human-submitted row (`sourceAgentCode: null`) omits the badge entirely rather than rendering a fake or zero confidence, showing `requestedBy`'s name where an AI row shows its agent avatar. |
| A held row is never merely "urgent" | `FRAUD_DETECTION`'s unilateral `held` transition renders with the `negative`-token left border no other status uses, pinned ahead of same-bucket items regardless of `slaDueAt`. A `held` row's Approve remains present (a verified payment can still proceed once the IBAN is confirmed), but the hold itself is lifted only by `FRAUD_DETECTION` re-evaluating or an explicit "Clear hold" on the source module — never by this screen's ordinary Approve click, so a reviewer cannot accidentally wave through a frozen payment. |
| Chains are configurable per company | `ApprovalStepper` never assumes a fixed step count or role sequence — it renders exactly the `steps[]` the API returns (`APPROVAL_ASSISTANT` resolves the chain per company at request-creation time from that company's own `roles`/`permissions`). |
| The two-key `bank_transfer` chain | Resolved in `ApprovalDrawer`/`ApprovalCard` as `stepPermission ?? requiredPermission`, per step — a viewer holding only the final key sees the first step's actions disabled. |
| `sources` are real citations | Each `sources[]` entry is a clickable drill-through to the grounding `journal_lines`/`bank_transactions`/`ai_decisions` row, resolved through the same CI-checked route table every AI-surfaced target uses — never a bare summary. |

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Page Header count | `Skeleton` pill in place of "14 pending · 2 breaching" | "0 pending" plainly — an empty queue is good news, never hidden or dressed up | Omitted silently if the count fetch fails independently; never blocks the header |
| Queue (Card) | Three row-shaped `ApprovalCard` placeholders matching the loaded card's exact height/padding | "Nothing waiting on you" (`AI_COMMAND_CENTER.md`'s reassuring copy) — no illustration | Inline `ErrorState` card with retry; Filter Bar + Page Header stay interactive |
| Queue (Table) | `DataTable` skeleton rows, same row-height token | "No approvals match these filters" + "Clear filters" — distinct from the true-zero copy above | `DataTable`'s shared inline retry row |
| Include-resolved history | Five placeholder rows on first load; a slim inline spinner at the list end on "load more" | "No resolved approvals yet" for a brand-new company | A failed "load more" leaves loaded pages intact with an inline retry at the end |
| Single request (`[approvalId]`) | Full-page skeleton matching the Detail template's Main Column / Summary Rail split | N/A — a request either exists or the route 404s | `not-found.tsx` for a nonexistent/cross-tenant id (never distinguishing them); a genuine `403` names the missing permission |
| Delegation `Sheet` | `Skeleton` rows | "You have no active delegations" + "New delegation" | Inline retry within the sheet; never dismisses it on failure |
| Bulk-approve submission | The confirming `AlertDialog`'s primary button spins and disables re-submission | N/A | A total (network-level) failure rolls the dialog back with a toast; a partial failure is a *success* path with a warning toast, never this error state |

Every skeleton reuses its loaded state's exact spacing/row-height classes so no region pops or reflows. The
Card-view queue, the Table-view queue, and the history view are three independently-scoped
`WidgetErrorBoundary`-style regions — a failure fetching resolved history never takes down the still-healthy
pending queue above it.

# Responsive Behavior

Breakpoints are `LAYOUT_SYSTEM.md`'s fixed scale — this screen introduces none of its own.

| Token | Width | This screen's behavior |
|---|---|---|
| `base` | <640px | Card view only, one `SwipeableApprovalCard` at a time; title + count only; scope toggle + Delegation in a header-icon `Sheet`; Bulk Action Bar becomes a sticky bottom bar; a mis-tap approving a `bank_transfer`/`payroll_release` additionally requires biometric re-confirmation (Face ID/fingerprint) immediately before submission (`AI_COMMAND_CENTER.md → Mobile Experience`) |
| `sm` | 640px | Same, wider cards |
| `md` | 768px | Card view still forced (Table view hidden); Filter Bar → search + "Filters" `Sheet`; a row's detail opens through `ApprovalDrawer` (`width="md"`, `side="end"`) |
| `lg` | 1024px | Table view becomes available (the density toggle appears); full Filter Bar inline |
| `xl` | 1280px | Full layout as drawn; the platform-wide `360px` AI Rail may dock inline without competing |
| `2xl`/`3xl` | 1536/1920px | Same layout, wider margins |

Touch targets follow the 44px minimum with an 8px gap — material here because Approve and Reject sit side by
side on every card and a mis-tap carries real financial consequence. Realtime is scoped by viewport: desktop
keeps `private-company.{id}.approvals` bound for as long as the screen is mounted; mobile gates the binding
behind visibility so a backgrounded tab does not keep a radio alive.

# RTL & Localization

Authored and reviewed in Arabic before being considered done, not mirrored at the end (`DESIGN_LANGUAGE.md →
Design Principle 6`). `dir="rtl"` is set once on `<html>`; no component toggles direction itself, and every
gap/padding value uses logical utilities (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively —
so the Filter Bar's control order, `ApprovalStepper`'s progress direction, the Bulk Action Bar's trailing
controls, and the Delegation `Sheet`'s slide-in edge (inline-end LTR, inline-start RTL) all mirror with zero
conditional code.

- **Numerals, amounts, confidence, dates never mirror.** `KWD 24,180.000`, `93%`, and every SLA countdown
  render inside a `dir="ltr"` span with `numberingSystem: "latn"` even inside a fully Arabic sentence — a
  reviewer reading "اعتماد 2 من 2" never sees the digits switch to Eastern Arabic-Indic form.
- **Directional icons flip; meaning-bearing icons don't.** `ApprovalStepper`'s progress arrow and Filter Bar
  chevrons mirror via `rtl:rotate-180`; the `held`-row left border, a checkmark, and the AI provenance mark
  never do.
- **`SwipeableApprovalCard`'s drag-to-decide is relative to the logical end** — physically left-to-right in
  English, right-to-left in Arabic, via `dir` from `useLocale()`, never the same physical motion misapplied.
- **AI-authored `reasoning` is never machine-translated by the frontend** — it arrives already localized per
  the API's content-negotiation contract; the client localizes only chrome.
- **Bilingual master-data fields** (`name_en`/`name_ar` on any customer/vendor/account a title or reasoning
  cites) are picked at render time from `useLocale()`, since the API always returns both.

| Context | English | Arabic |
|---|---|---|
| Screen title | Approvals | الاعتمادات |
| Scope toggle | My queue / All requests | طلباتي / كل الطلبات |
| Bulk action bar | 3 selected · Approve · Clear | 3 محددة · اعتماد · إلغاء التحديد |
| Held banner | Held — vendor bank details changed 2 hours ago | معلّق — تم تغيير بيانات حساب المورد البنكي قبل ساعتين |
| Approve / Reject | Approve / Reject | اعتماد / رفض |
| Request changes / Delegate | Request changes / Delegate | طلب تعديل / تفويض |
| Empty queue | Nothing waiting on you | لا يوجد شيء بانتظارك |
| Include resolved | Include resolved | تضمين الطلبات المكتملة |
| Not-your-step tooltip | Waiting on {role} to approve | بانتظار اعتماد {role} |

Arabic copy is authored directly by a fluent professional-register writer, per `DESIGN_LANGUAGE.md → Voice &
Microcopy`.

# Dark Mode

This screen introduces no new token — every surface resolves through the `.dark`/`data-theme="dark"` remap in
`DESIGN_LANGUAGE.md → Design Tokens`. Two rules specific to this screen's AI-heavy, decision-bearing content:

- **The accent stays reserved for AI provenance and the one primary action per card — never status.** A
  `held` row is the `negative` semantic token, never the accent, even though the hold itself is AI-originated;
  conflating "the AI touched this" with "this is urgent" would collapse two independently useful signals. The
  Approve button remains the one `accent` primary action per card regardless of theme; Reject uses the quieter
  `destructive-quiet`, never a loud alarming red that would make every card feel like an error state.
- **Confidence indicators are re-tuned per theme, not linearly brightened** — a 94%-confidence fraud hold
  reads as urgent without harsh over-saturation.

`ApprovalStepper`'s progress line and step-state dots resolve through `lib/tokens.ts`'s JS-readable token
export wherever the stepper renders as SVG rather than styled `div`s (`DARK_MODE.md → Charts & Data Viz In
Dark`). No icon or badge is ever `filter: invert()`-flipped. A compliance export of the resolved history is the
one deliberate exception to "everything themes" — it renders in a fixed light/print palette regardless of the
requesting user's in-app theme, since a document an external auditor opens outside QAYD has no theme context.
Every Storybook story ships the four-way `light/LTR`/`light/RTL`/`dark/LTR`/`dark/RTL` matrix; the route's
Playwright suite captures the same four-way set.

# Accessibility

Baseline WCAG 2.2 AA, with extra weight on the three areas a financially-consequential decision surface
stresses hardest, restating `docs/frontend/APPROVAL_CENTER.md → Accessibility` with the implementation
checklist:

- **Landmarks and headings.** One `<h1>` ("Approvals"); each urgency group ("Breaching SLA," "Due today," …)
  is a real `<h2>` via `VisuallyHidden` (see `ApprovalUrgencyGroup`), so a screen-reader user's heading list
  enumerates the same groups a sighted user scans.
- **Live regions, calibrated.** A Reverb push flipping a request to `held` mid-review never yanks focus or
  re-sorts the list; it surfaces as a polite (`aria-live="polite"`, never assertive), dismissible "1 request
  now held — Refresh" banner. The Bulk Action Bar's appearance is announced once via `role="status"` ("3
  selected — Approve or Clear").
- **Every disabled control explains itself, and the reasons are never conflated.** A step's Approve disabled
  because it is not yet the viewer's turn, a checkbox disabled for a `SENSITIVE_PERMISSIONS` key, and a card
  read-only because the viewer lacks the step's `stepPermission` are three distinct conditions, each with its
  own `aria-describedby` text sourced from the permission system — never a generic "You can't do this right
  now." (The `Tooltip`/`aria-describedby` pattern is shown in `docs/frontend/APPROVAL_CENTER.md → Accessibility`.)
- **Bulk selection is a real tri-state control** tied to row identity — the Table header checkbox carries
  `aria-checked="mixed"` when some but not all rows are selected, and every row checkbox has an accessible
  name ("Select approval: July vendor payment run"), never a bare "Select."
- **Keyboard covers the full screen.** `Tab` follows reading order (Header → Filter Bar → Bulk Bar when
  present → grouped cards → Footer); `ApprovalStepper`'s steps are arrow-navigable inside a `role="list"`; the
  Reject and Request Changes `AlertDialog`s trap focus and return it to the triggering card's action row.
- **`SwipeableApprovalCard`'s gesture is additive, never exclusive** — disabled outright under
  `prefers-reduced-motion`, and the Approve/Reject buttons beneath it are always present and focusable, so an
  approval gate is never accessible through only one input modality.
- **The `ApprovalDrawer`, on this screen,** inherits its full Radix + AI contract from
  `docs/frontend/components/DRAWERS.md → Accessibility` unchanged — focus trap/return, a nested reject
  `AlertDialog` stacking above the drawer and returning focus into it, and every disabled decision button's
  `aria-describedby` reason.
- **Testing.** `/approvals` and `/approvals/{id}` are in the four-permutation automated sweep with zero
  serious/critical `axe` violations as a merge gate; the manual pass adds completing an approve end-to-end with
  only a keyboard, a screen-reader pass over a grouped queue's heading list, and an `ar`/RTL pass over the
  swipe-direction and `ApprovalStepper` mirroring.

# Performance

- **RSC and streaming.** `page.tsx` prefetches the first page of the default `pending,held,in_review` queue
  before the shell paints; the Delegation `Sheet`'s data and the "Include resolved" history are both lazily
  fetched only once their UI opens, never prefetched alongside the primary queue.
- **Query layer.** The primary queue's `staleTime: 10_000` with realtime correction means a returning tab
  never shows a queue more than moments stale; the history `useInfiniteQuery` uses `keepPreviousData` so paging
  months of history never flashes to empty, and virtualizes (`@tanstack/react-virtual`) once the loaded set
  exceeds ~200 rows.
- **Realtime cost control.** One shared `RealtimeProvider` connection, never a dedicated socket; on reconnect,
  `approvalKeys.all` is invalidated once as a batch (a missed `held` is a correctness bug).
- **`held`-patch-in-place, not list invalidation** — a Fraud Detection hold on an open card patches that one
  row's status via `setQueryData`, preserving scroll position and any in-progress selection.
- **Web Vitals.** INP is watched specifically on the Approve/Reject buttons and the bulk-approve confirmation
  flow, tagged against a company-size-band baseline — a company clearing forty items a day and a brand-new
  company with an empty queue have legitimately different tail latencies.

# Edge Cases

`docs/frontend/APPROVAL_CENTER.md → Edge Cases` already covers the scenarios specific to the approval model (a
target record changing between proposal and click, a fraud hold arriving on an open card, a two-tab step race,
a mid-session permission revocation, a bulk-batch eligibility change, a WebSocket reconnect, a compliance
export, a brand-new company, a removed delegate, and single-request printing) — this document does not repeat
them. The rows below are this implementation layer's own additions.

| Edge case | Frontend behavior |
|---|---|
| The optimistic Approve patch resolves against a step a colleague decided a second earlier (realtime race) | `useApproveRequest`'s `onError` rolls the cache back to the server's authoritative state and, for the open card/drawer, replaces the footer with a read-only "Already {approved/rejected} by {name}" banner (`DRAWERS.md`'s already-decided state); the queue invalidates so the settled item leaves the list. |
| A bulk selection spans two `subjectType`s because a realtime insert added a new-type row after selection began | The Bulk Action Bar recomputes eligibility on every selection change; the Approve button becomes *absent* the instant the selection is mixed-type (never disabled), since a single confirmation dialog naming one coherent action is the whole point of bulk. |
| The scope toggle is flipped to `all` by a viewer with `reports.read` but no broad `ai.approve` | The extra rows render read-only — every `ApprovalCard`'s action row is absent for a view-only grant — matching the Auditor treatment, never an enabled button that would `403` on click. |
| `ApprovalScopeToggle`'s `?scope=` rewrite and an in-flight approve on a now-filtered-out row overlap | The approve completes against `approvalKeys.detail(id)` regardless of the queue filter (the detail cache is scope-independent); the row simply is no longer in the visible `all`/`mine` list, and the success toast still fires. |
| A locale switch (EN → AR) while a request's card is open with reasoning expanded | The card re-renders chrome in the new locale immediately; the AI `reasoning` text does **not** re-translate client-side (it is the model's own words) — a fresh fetch under the new `Accept-Language` returns the Arabic reasoning, but the currently-cached English reasoning is not machine-translated in place. |
| The `[approvalId]` detail page is open and the same request is approved from the list in another tab | The detail page's next `refetchOnWindowFocus` (or the realtime invalidation) reconciles it to the settled state; both views share `approvalKeys.detail(id)`, so the detail page can never show a live Approve button for a request the list already resolved. |
| A delegate chosen through `DelegatePicker` loses the required permission between the picker's client filter and the `POST` | The API returns a field-level `422` (ineligible target); the picker surfaces it inline ("This user can no longer decide this step") and re-fetches `useEligibleDelegates` rather than silently completing an invalid hand-off. |

# End of Document
