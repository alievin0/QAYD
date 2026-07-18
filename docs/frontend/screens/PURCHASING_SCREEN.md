# Purchasing Landing Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PURCHASING_SCREEN
---

# Purpose

This document is the concrete, build-ready screen specification for exactly one route —
`app/(app)/purchasing/page.tsx`, the landing surface of the Purchasing module — written to the
platform's Screen Doc structure (`# Route & Access` through `# Edge Cases`). It is the
implementation-level companion to `docs/frontend/PURCHASING.md` (the "Purchasing Hub" module
document), which already establishes this screen's place in the platform's information
architecture: its reconciliation of `NAVIGATION_SYSTEM.md`'s and `FRONTEND_ARCHITECTURE.md`'s stale
route-tree comments, its six-tab sub-nav shape, its component inventory, and the narrative reasoning
behind every one of those calls, down to why this module gets a bare `purchasing/page.tsx` landing
at all when its sibling Banking module deliberately does not. Where `PURCHASING.md` argues *why*
this screen exists in the shape it does, this document specifies *exactly how it is built*: the
wire-level request/response payloads behind every region, the query-key and hook wiring an engineer
copies into `components/purchasing/`, and a fully worked, concrete implementation of the three
AI-sourced signal kinds a Purchasing Manager, Finance Manager, or CFO most needs condensed in one
place — **Duplicate Bill / Fraud detection**, **Price Comparison anomalies**, and **Vendor
Recommendation shortlists**. Every fact `PURCHASING.md` (frontend) or `docs/accounting/PURCHASING.md`
(backend) already fixed — routes, permission keys, status enums, table names, endpoint paths,
component names, the exact wireframe figures — is reused verbatim here, never re-derived or quietly
changed; where this document adds detail those two left implicit (chiefly the three condensed AI
card components, and the `ThreeWayMatchStatusCard`'s internal implementation, which the frontend Hub
document specifies only by its props interface), it is additive to their design, never a
contradiction of it.

Concretely, `app/(app)/purchasing/page.tsx` composes six things, in the order a Purchasing Manager,
Purchasing Employee, Finance Manager, Warehouse Employee, CFO, or Owner encounters them on open:

1. **A module sub-navigation** — Overview (this screen) · Requests · RFQs · Orders · Receipts · Bills
   · Payments — the same seven-item bar (six document-type tabs plus this Overview tab)
   `PURCHASING.md → Route & Access` defines.
2. **A filter bar and spend KPI strip** — a shared period/branch/reporting-currency filter feeding
   four `KpiTile`s: Open Purchase Requests, Open Purchase Orders (committed spend), Bills Due This
   Week, and YTD Spend vs. Budget.
3. **A Pending Approvals region** — up to five `ApprovalCard`s addressed to the caller's own pending
   approval step, scoped to this module's four approvable document types.
4. **A Three-Way Match Status panel** — the hub's own dedicated, always-visible rendering of
   `bills.match_status`, a control surface into the Bills tab, not a KPI.
5. **An AI Purchasing Insights rail** — up to three condensed cards drawn from Fraud Detection's
   Duplicate Bill Detection, the General Accountant's Price Comparison overlay, and the Vendor
   Recommendation shortlist agent.
6. **Recent activity and Outstanding-by-tab** — a capped cross-document activity feed and a compact
   six-row list naming the single most actionable open count per tab.
7. Nothing else. This screen renders no business logic of its own — it never approves a Purchase
   Order, matches a Bill, releases a Vendor Payment, or lets an AI recommendation execute itself.
   Every figure here was computed, aggregated, and — where expensive — Redis-cached by Laravel; every
   mutation this screen triggers is a call to `/api/v1/purchasing/...` or `/api/v1/approvals/...`
   guarded by the exact permission key the API itself enforces, per `FRONTEND_ARCHITECTURE.md`'s
   Principle 1 ("the frontend never contains business or financial logic") and Principle 4 ("respect
   RBAC by hiding and disabling, never by trusting the client").

The six document-type list screens this hub's sub-nav links to (Purchase Requests, RFQs, Purchase
Orders, Goods Receipts, Bills, Vendor Payments) are each their own screen, following
`LAYOUT_SYSTEM.md`'s List Page Template per `PURCHASING.md`'s own tab-filling table (columns, default
sort, primary/bulk actions) — this document does not redefine any of them, nor a Bill's own
three-way-match detail view, a Purchase Order's own multi-step creation form, or a Vendor Payment's
own allocation grid, each substantial enough to be its own screen document in this same series. It
also does not redefine the backend module's business rules, lifecycle states, or database schema
(`docs/accounting/PURCHASING.md` owns all of that in full) or the AI agents' own reasoning, autonomy
levels, or prompt strategy — this screen only renders what those two documents already compute and
validate. Vendors (`/purchasing/vendors`) remains out of this document's scope for the identical
reason `PURCHASING.md` gives: it is owned by Accounting/AP (`accounting.vendor.read`) and merely
grouped under the Purchasing sidebar section for IA convenience.

# Route & Access

## App Router path

This screen occupies exactly the routes `PURCHASING.md → Route & Access` already fixes; reproduced
here so this document is self-contained for an engineer building only this page:

```
app/(app)/purchasing/
├── layout.tsx                       # Sub-nav: Overview | Requests | RFQs | Orders | Receipts | Bills | Payments (purchasing.read gate)
├── page.tsx                         # ★ THIS DOCUMENT — Purchasing Overview (the hub/landing)
├── requests/{page.tsx, new/page.tsx, [id]/page.tsx}
├── rfqs/{page.tsx, [id]/page.tsx}
├── purchase-orders/{page.tsx, new/page.tsx, [id]/page.tsx}
├── goods-receipts/{page.tsx, new/page.tsx, [id]/page.tsx}
├── bills/{page.tsx, new/page.tsx, [id]/page.tsx}
└── vendor-payments/{page.tsx, new/page.tsx, [id]/page.tsx}
```

`page.tsx` at the module root is this document's own subject, and it exists for the same reason
`PURCHASING.md → Route & Access` gives: Purchasing fronts six structurally distinct document types
with no single dominant entity the way Banking's `bank_accounts` dominates that module, so a hub
landing page answers "what across all six queues needs me right now" once, cheaply, before a user
picks a queue. This document inherits `PURCHASING.md`'s own reconciliation of `NAVIGATION_SYSTEM.md`'s
and `FRONTEND_ARCHITECTURE.md`'s stale route-tree comments rather than re-arguing it: Purchase Orders
approve via `purchasing.order.approve`, not a Bill-family key; Vendor Payments approve and release via
`purchasing.payment.approve`/`.release` (maker≠checker enforced server-side), not `bank.transfer`,
which remains Banking's own permission for a bank-to-bank transfer.

## Permission gate

Every gate below reuses the exact permission key `docs/accounting/PURCHASING.md → User Permissions`
or `docs/frontend/PURCHASING.md → Route & Access` already defines — this screen introduces no new
permission.

| Gate | Permission | Effect if absent |
|---|---|---|
| Screen visible at all | `purchasing.read` | Sidebar's Purchasing entry and every route under `/purchasing/*` do not render; a direct hit renders the shell-level `error.tsx`, never a silent redirect. |
| KPI strip — Open Purchase Requests tile | `purchasing.request.read` | Tile omitted, not shown as zero. |
| KPI strip — Open Purchase Orders tile | `purchasing.order.read` | Tile omitted. |
| KPI strip — Bills Due This Week tile, Three-Way Match Status panel | `purchasing.bill.read` | Tile and panel both omitted — the panel's existence implies bill data the caller cannot see. |
| KPI strip — YTD Spend vs. Budget tile | `reports.read` | Tile omitted. |
| Pending Approvals region | `ai.approve` to act, `reports.read` to view | A caller with neither sees the region omitted rather than an empty shell; a caller with only `reports.read` sees every card but every action disabled with a tooltip. |
| AI Purchasing Insights rail | `reports.read` | Rail omitted, wrapped in `<Can permission="reports.read">`. |
| Recent activity, Outstanding by tab | `purchasing.read` (already required for the shell) | Rows for a specific tab the caller cannot read are simply absent from both regions. |
| Create a Purchase Request | `purchasing.request.create` | Quick action omitted. |
| Approve/reject a Purchase Request | `purchasing.request.approve` | Row/card action omitted; a step addressed to another approver still renders read-only. |
| Create a Purchase Order | `purchasing.order.create` | Quick action omitted. |
| Approve / send a Purchase Order | `purchasing.order.approve` / `.send` | Actions omitted. |
| Post / reverse a Goods Receipt | `warehouse.receiving.create` / `.reverse` | Actions omitted — reachable from the Receipts tab, not this hub. |
| Create / override-variance / approve / post a Bill | `purchasing.bill.create` / `.override_variance` / `.approve` / `.post` | Quick action and Three-Way Match Status drill-through remain visible (read-only); mutating actions omitted on the destination tab. |
| Create / approve / release a Vendor Payment | `purchasing.payment.create` / `.approve` / `.release` | Actions omitted; `.release` additionally enforces maker≠checker server-side (`# Edge Cases`). |
| Place or lift a payment hold | `purchasing.payment.hold` | Action omitted. Never held by the AI service account under any role configuration. |
| Export a Purchasing report | `reports.export` | Export menu item omitted. |

## Roles

Reproduced from `docs/accounting/PURCHASING.md`'s Permissions table and `docs/frontend/PURCHASING.md`'s
own Roles table, translated into what each role concretely sees on this one screen:

| Role | What renders here |
|---|---|
| Owner, CEO, CFO | Full hub: every KPI tile, the full Pending Approvals queue at their own tier, full Three-Way Match Status, full AI Insights rail, every quick action their own approval tier allows. |
| Purchasing Manager | Full hub except Bill/Payment approve-and-post/release actions (Finance-only past the match stage); sees Three-Way Match Status and the AI Insights rail in full. |
| Purchasing Employee | Sees their own department's Purchase Requests/RFQs; Purchase Order creation only below their auto-approval threshold; no Bill or Vendor Payment actions; no Three-Way Match Status panel unless `purchasing.bill.read` is separately granted. |
| Finance Manager | Sees Bills and Vendor Payments in full, plus the Three-Way Match Status panel as a primary surface; Purchase Requests/RFQs/Purchase Orders render read-only for approval-tier visibility. |
| Warehouse Employee | A narrow, mostly-empty-but-not-broken hub: only the Goods Receipts outstanding row and its own tab; every other KPI tile and region omitted. |
| Auditor / External Auditor | Full read visibility everywhere on the hub; zero mutating controls. |
| AI service account | Never renders this screen as a user; structurally incapable of holding `purchasing.payment.release` or `purchasing.payment.hold` regardless of role configuration. |

# Layout & Regions

`app/(app)/purchasing/page.tsx` instantiates a hybrid of `LAYOUT_SYSTEM.md`'s **Dashboard Template**
(KPI Strip + Insights Feed, for the KPI strip and the AI Insights rail) and its own dedicated
**control-surface region** (the Three-Way Match Status panel, which `PURCHASING.md` is explicit is "a
control surface, not a KPI tile") — the same blend `docs/frontend/DASHBOARD.md` and
`docs/frontend/BANKING.md` use for their own hub screens, never a third, bespoke template.

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Overview  Requests  RFQs  Orders  Receipts  Bills  Payments              [sub-nav tabs]   │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ Purchasing                                    [ This fiscal period ▾ ] [Branch ▾] [KWD ▾] │
│ Procure-to-pay overview                                    [+ New PR] [+ New PO] [+ Bill] │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [Open PRs: 14, KD 6,204] [Open POs: 37, KD 84,210] [Bills due: 9, KD 21,340] [YTD vs budget: 71%]│
├──────────────────────────────────────────────────────────┬─────────────────────────────────┤
│  Pending Approvals (you)                                  │  AI · Purchasing Insights        │
│  ┌ PO-2026-004417 — Gulf Paper Trading ─────────────────┐ │  ⚠ Possible duplicate — BILL-2026-000789 │
│  │ KD 1,388.360 · step 2 of 3                [Approve] │ │    matches BILL-2026-000317, 92% conf. │
│  └───────────────────────────────────────────────────────┘ │  ▸ Price outlier — RFQ-2026-0091      │
│  ┌ VP-2026-000902 — release pending ────────────────────┐ │    "FastTrade" 2.3σ below the mean     │
│  │ KD 1,240.000 · maker≠checker                    [⋯] │ │  ▸ Vendor shortlist — RFQ-2026-0102     │
│  └───────────────────────────────────────────────────────┘ │    4 vendors ranked, 88% conf. [Review]│
│  [ View all in Approval Center → ]                         │  [ View all in AI Command Center → ]  │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│  Three-Way Match Status                                                                    │
│  Not yet matched  ██████░░░░  12    Matched (auto)  ████████████ 58    Variance flagged  ███ 5│
│  [ Review flagged bills → ]                                                                 │
├──────────────────────────────────────────────────────────┬─────────────────────────────────┤
│  Recent activity                                          │  Outstanding by tab              │
│  ● GR-2026-000512 posted — 2h ago                          │  Requests    3 pending           │
│  ● BILL-2026-000789 flagged possible duplicate — 3h ago    │  RFQs        1 awaiting quotes    │
│  ● PO-2026-004417 sent to Gulf Paper Trading — yesterday   │  Orders      37 open              │
│  [ View all activity → ]                                   │  Receipts    2 pending inspection │
│                                                             │  Bills       5 variance flagged   │
│                                                             │  Payments    3 pending release    │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

```
Mobile (base–sm, <640px)
┌───────────────────────────┐
│ ≡  Purchasing        🔔 👤│
├───────────────────────────┤
│ ⇤ Overview Requests RFQs ⇥│  ← horizontal-scroll chip row
├───────────────────────────┤
│ Purchasing            [+ ▾]│
│ Procure-to-pay overview    │
├───────────────────────────┤
│ ⇤ [Open PRs 14  KD 6,204]⇥│  ← snap-scroll KPI carousel
├───────────────────────────┤
│ Pending Approvals (you)    │
│ PO-2026-004417  step 2/3   │
│ KD 1,388.360      [Approve]│
│ …                          │
├───────────────────────────┤
│ AI · Purchasing Insights   │
│ ⚠ Possible duplicate (92%) │
│ ▸ Price outlier (RFQ-0091) │
│ ▸ Vendor shortlist (88%)   │
├───────────────────────────┤
│ Three-Way Match Status     │
│ Not matched 12 · Matched 58│
│ Variance 5      [Review →]│
├───────────────────────────┤
│ Recent activity            │
│ ● GR-000512 posted · 2h    │
├───────────────────────────┤
│ Outstanding by tab         │
│ Requests 3 · RFQs 1 …      │
├───────────────────────────┤
│ 🏠  💰  📄  🔔  ☰          │  ← bottom tab bar
└───────────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| Sub-nav | Full width, part of `purchasing/layout.tsx`'s shell | `PurchasingSubNav` — Overview (active) · Requests · RFQs · Orders · Receipts · Bills · Payments | Server Component; each tab a real route, never client-only tab state. |
| Filter bar | Full width, sticky below sub-nav | `PurchasingFilterBar` — `PeriodPicker`, `BranchSelect`, `CurrencySelect` | Mirrors into `?range=&branch=&currency=`; read as the destination tab's default filter seed on navigation. |
| Page header | Full width | `<h1>Purchasing</h1>`, one-line subtitle, up to three permission-filtered Quick Actions (`+ New Purchase Request`, `+ New Purchase Order`, `+ Enter Bill`) | A fourth candidate (e.g. `+ New RFQ`) lives in an overflow `DropdownMenu`. |
| KPI strip | `col-span-12`, one row | Four `KpiTile`s | Own `<Suspense>` — `GET /api/v1/purchasing/reports/purchase-dashboard`. |
| Pending Approvals | `col-span-7` at `lg:`+ | Up to 5 `ApprovalCard`s | Own `<Suspense>` — scoped `GET /api/v1/approvals`. |
| AI Purchasing Insights | `col-span-5` at `lg:`+ | Up to 3 condensed cards | Own `<Suspense>` — `GET /api/v1/ai/risks` + `GET /api/v1/ai/recommendations`. |
| Three-Way Match Status | `col-span-12` | `ThreeWayMatchStatusCard` | Own `<Suspense>` — `GET /api/v1/purchasing/bills?group_by=match_status`. |
| Recent activity | `col-span-7` (full width below `lg:`) | `PurchasingActivityFeed` | Pre-aggregated inside the bootstrap bundle. |
| Outstanding by tab | `col-span-5` (full width below `lg:`) | `OutstandingByTab` | Reads the same six counts the KPI strip and Three-Way Match Status card already fetched — no query of its own. |

Each streamed region pairs with its own `<ErrorBoundary>` one level inside its `Suspense` fallback, per
`FRONTEND_ARCHITECTURE.md`'s three-granularity error model — a Fraud Detection timeout on the AI
Insights rail never touches the KPI strip or the Three-Way Match Status card beside it, exactly as
`PURCHASING.md → Data & State` establishes for this same route.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue,
composed into Purchasing-scoped components living in `components/purchasing/`, per
`PROJECT_STRUCTURE.md`'s module-folder convention and per `PURCHASING.md → Components Used`'s own
inventory, reproduced here with the additional concrete implementations this document introduces.

| Component | Source | Role on this screen |
|---|---|---|
| `PurchasingSubNav` | `components/purchasing/purchasing-sub-nav.tsx` (per `PURCHASING.md`) | Sub-nav tab bar |
| `PurchasingFilterBar` | `components/purchasing/purchasing-filter-bar.tsx` (per `PURCHASING.md`) | `PeriodPicker` + `BranchSelect` + `CurrencySelect` |
| `PageHeader`, `Button`, `DropdownMenu`, `Can` | Shared primitives | Page header + Quick Actions |
| `KpiTile`, `TrendSparkline` | Finance (`components/dashboard/`) | KPI strip |
| `ApprovalCard`, `Stepper` | Shared (`components/shared/approval-card.tsx`) | Pending Approvals |
| `ThreeWayMatchStatusCard` | `components/purchasing/three-way-match-status-card.tsx` (props per `PURCHASING.md`; implementation below) | Three-Way Match Status panel |
| `PurchasingAiInsightsRail` | `components/purchasing/purchasing-ai-insights-rail.tsx` (per `PURCHASING.md`; implementation below) | AI Purchasing Insights |
| `PurchasingDuplicateBillCard`, `PurchasingPriceAnomalyCard`, `PurchasingVendorRecommendationCard` | **New** — `components/purchasing/` (this document) | The rail's three condensed card kinds |
| `AIProposalPanel`, `ConfidenceBadge` | AI (`components/ai/`) | Underlying shells the three new cards compose |
| `PurchasingActivityFeed` | `components/purchasing/purchasing-activity-feed.tsx` (per `PURCHASING.md`) | Recent activity |
| `OutstandingByTab` | `components/purchasing/outstanding-by-tab.tsx` (per `PURCHASING.md`) | Outstanding by tab |
| `DataTable`, `StatusPill`, `AmountCell`, `CurrencyTag` | Finance | Each tab's own list (not this screen, referenced for the drill-through links) |
| `Skeleton`, `EmptyState`, `ErrorState` | Shared | Per-region loading/empty/error — `# States` |
| `PermissionGate` / `Can` | Shared | Wraps every gated region and action |

## `StatusPill` domain extension (reused verbatim)

`PURCHASING.md → Components Used` is the canonical owner of the `PurchasingDomain` union and its six
`STATUS_TABLES` entries (`purchase_request`, `rfq`, `purchase_order`, `goods_receipt`, `bill`,
`vendor_payment`); this screen consumes that union but does not redefine it. The one entry this
screen's own drill-through links depend on directly is `BILL_STATUS`'s `variance_flagged` tone
(`danger`) and `matched`/`override_approved` tones, since the Three-Way Match Status panel's three
segments below map one-to-one onto three of `bills.match_status`'s four values.

## `ThreeWayMatchStatusCard` — concrete implementation

`PURCHASING.md → Components Used` fixes this component's props (`buckets`, `currencyCode`,
`onBucketClick`, `loading`) and its design lineage (mirroring `AgingBar`'s shape, "a labeled,
segmented, clickable count bar"), but stops short of the component body. This document supplies it:

```tsx
// components/purchasing/three-way-match-status-card.tsx
'use client';

import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { AmountCell } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';

interface MatchStatusBucket {
  matchStatus: 'not_matched' | 'matched' | 'variance' | 'override_approved';
  label: string;
  count: number;
  baseCurrencyAmount: string;
}

interface ThreeWayMatchStatusCardProps {
  buckets: MatchStatusBucket[];
  currencyCode: string;
  onBucketClick: (matchStatus: MatchStatusBucket['matchStatus']) => void;
  loading?: boolean;
}

const BUCKET_TONE: Record<MatchStatusBucket['matchStatus'], string> = {
  not_matched: 'bg-ink-300 dark:bg-ink-600',
  matched: 'bg-accent-500',
  variance: 'bg-status-error',
  override_approved: 'bg-status-warning',
};

export function ThreeWayMatchStatusCard({ buckets, currencyCode, onBucketClick, loading }: ThreeWayMatchStatusCardProps) {
  if (loading) return <Skeleton className="h-24 w-full rounded-lg" />;
  const total = buckets.reduce((sum, b) => sum + b.count, 0);

  return (
    <Card padding="md" className="space-y-3">
      <h2 className="text-sm font-medium text-text-secondary">Three-Way Match Status</h2>
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
        {buckets.map((bucket) => (
          <button
            key={bucket.matchStatus}
            type="button"
            onClick={() => onBucketClick(bucket.matchStatus)}
            aria-label={`${bucket.label}, ${bucket.count} bills — view bills`}
            className="group flex flex-1 flex-col gap-1 rounded-md p-2 text-start ring-offset-2 focus-visible:ring-2 focus-visible:ring-accent-500"
          >
            <span className="flex items-baseline justify-between gap-2 text-xs text-text-secondary">
              <span>{bucket.label}</span>
              <span className="font-medium text-text-primary">{bucket.count}</span>
            </span>
            <span
              className="h-2 w-full overflow-hidden rounded-full bg-surface-sunken"
              role="img"
              aria-hidden="true"
            >
              <span
                className={cn('block h-full rounded-full transition-all', BUCKET_TONE[bucket.matchStatus])}
                style={{ width: `${total === 0 ? 0 : (bucket.count / total) * 100}%` }}
              />
            </span>
            <AmountCell amount={bucket.baseCurrencyAmount} currencyCode={currencyCode} emphasis="muted" />
          </button>
        ))}
      </div>
    </Card>
  );
}
```

Consumed from the page as:

```tsx
// app/(app)/purchasing/page.tsx (excerpt)
const { data, isLoading } = useThreeWayMatchStatus(filters);
<ThreeWayMatchStatusCard
  buckets={data ?? []}
  currencyCode={filters.currencyCode}
  loading={isLoading}
  onBucketClick={(status) => router.push(`/purchasing/bills?filter[match_status][in]=${status}`)}
/>
```

Each segment is a real `<button>`, never a colored `<div>` with a click handler — see `# Accessibility`.

## `PurchasingAiInsightsRail` and its three condensed card kinds — concrete implementation

`PURCHASING.md → AI Integration` specifies, in prose, exactly three card kinds this rail renders and
the confidence thresholds and reasoning strings each one carries; this document supplies the
component contracts and bodies those paragraphs presuppose. All three share one discriminated union
so the rail can render a heterogeneous list from two endpoints (`GET /api/v1/ai/risks?module=
purchasing` and `GET /api/v1/ai/recommendations?module=purchasing`) without a `switch` at every call
site:

```tsx
// types/purchasing-ai.ts
export type PurchasingAiInsight =
  | {
      kind: 'duplicate_bill';
      id: number;
      confidence: number;                 // 0–1
      reasoning: string;
      billId: number;
      billNumber: string;
      matchedBillNumber: string;
    }
  | {
      kind: 'price_anomaly';
      id: number;
      confidence: number;
      reasoning: string;
      rfqId: number;
      rfqNumber: string;
      vendorName: string;
      stdDeviations: number;               // signed — negative = below mean
      lineId: number;
    }
  | {
      kind: 'vendor_recommendation';
      id: number;
      confidence: number;
      reasoning: string;
      rfqId: number;
      rfqNumber: string;
      vendorsRanked: number;
      lowHistory: boolean;                 // true when confidence < 0.55
    };
```

```tsx
// components/purchasing/purchasing-ai-insights-rail.tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { purchasingAiKeys } from '@/lib/api/query-keys';
import { PurchasingDuplicateBillCard } from './purchasing-duplicate-bill-card';
import { PurchasingPriceAnomalyCard } from './purchasing-price-anomaly-card';
import { PurchasingVendorRecommendationCard } from './purchasing-vendor-recommendation-card';
import { RailSectionSkeleton } from '@/components/ai/rail-section-skeleton';
import { EmptyState } from '@/components/shared/empty-state';
import { AiUnavailableState } from '@/components/ai/ai-unavailable-state';
import type { PurchasingAiInsight } from '@/types/purchasing-ai';
import type { PurchasingFilters } from '@/types/purchasing';

export function PurchasingAiInsightsRail({ filters }: { filters: PurchasingFilters }) {
  const risks = useQuery({
    queryKey: purchasingAiKeys.risks(3),
    queryFn: () => apiClient.get('/api/v1/ai/risks', { params: { module: 'purchasing', limit: 3, sort: '-severity' } }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
  const recommendations = useQuery({
    queryKey: purchasingAiKeys.recommendations(3),
    queryFn: () => apiClient.get('/api/v1/ai/recommendations', { params: { module: 'purchasing', limit: 3 } }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });

  if (risks.isPending || recommendations.isPending) return <RailSectionSkeleton rows={3} />;
  if (risks.isError && recommendations.isError) return <AiUnavailableState />;

  const insights: PurchasingAiInsight[] = [...(risks.data?.data ?? []), ...(recommendations.data?.data ?? [])]
    .slice(0, 3); // rail cap — condensed, capped at 3, per PURCHASING.md's condensation rule

  if (insights.length === 0) {
    return (
      <EmptyState
        title="No open insights right now"
        description="Duplicate checks, price comparisons, and vendor recommendations will appear here."
      />
    );
  }

  return (
    <div className="space-y-3" aria-labelledby="ai-purchasing-insights-heading">
      <h2 id="ai-purchasing-insights-heading" className="sr-only">AI Purchasing Insights</h2>
      {insights.map((insight) => {
        switch (insight.kind) {
          case 'duplicate_bill':
            return <PurchasingDuplicateBillCard key={insight.id} insight={insight} />;
          case 'price_anomaly':
            return <PurchasingPriceAnomalyCard key={insight.id} insight={insight} />;
          case 'vendor_recommendation':
            return <PurchasingVendorRecommendationCard key={insight.id} insight={insight} />;
        }
      })}
      <a href="/ai#purchasing" className="text-sm text-accent-600 hover:underline">
        View all in AI Command Center →
      </a>
    </div>
  );
}
```

```tsx
// components/purchasing/purchasing-duplicate-bill-card.tsx
'use client';

import Link from 'next/link';
import { Card } from '@/components/ui/card';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { AlertTriangle } from 'lucide-react';
import type { PurchasingAiInsight } from '@/types/purchasing-ai';

type DuplicateBillInsight = Extract<PurchasingAiInsight, { kind: 'duplicate_bill' }>;

export function PurchasingDuplicateBillCard({ insight }: { insight: DuplicateBillInsight }) {
  return (
    <Card padding="sm" className="border-s-2 border-s-status-error">
      <Link href={`/purchasing/bills/${insight.billId}#match-status`} className="flex items-start gap-2">
        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-error" aria-hidden="true" />
        <span className="flex-1 text-sm text-text-primary">
          Possible duplicate — <span dir="ltr" className="[unicode-bidi:isolate]">{insight.billNumber}</span> matches{' '}
          <span dir="ltr" className="[unicode-bidi:isolate]">{insight.matchedBillNumber}</span>
        </span>
        <ConfidenceBadge confidence={insight.confidence} size="sm" reasoning={insight.reasoning} />
      </Link>
    </Card>
  );
}
```

```tsx
// components/purchasing/purchasing-price-anomaly-card.tsx
'use client';

import Link from 'next/link';
import { Card } from '@/components/ui/card';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { TrendingDown, TrendingUp } from 'lucide-react';
import type { PurchasingAiInsight } from '@/types/purchasing-ai';

type PriceAnomalyInsight = Extract<PurchasingAiInsight, { kind: 'price_anomaly' }>;

export function PurchasingPriceAnomalyCard({ insight }: { insight: PriceAnomalyInsight }) {
  const Icon = insight.stdDeviations < 0 ? TrendingDown : TrendingUp;
  return (
    <Card padding="sm" className="border-s-2 border-s-ai">
      <Link href={`/purchasing/rfqs/${insight.rfqId}?highlight=${insight.lineId}`} className="flex items-start gap-2">
        <Icon className="mt-0.5 h-4 w-4 shrink-0 text-ai" aria-hidden="true" />
        <span className="flex-1 text-sm text-text-primary">
          Price outlier on <span dir="ltr" className="[unicode-bidi:isolate]">{insight.rfqNumber}</span> — vendor
          &ldquo;{insight.vendorName}&rdquo; {Math.abs(insight.stdDeviations)}σ {insight.stdDeviations < 0 ? 'below' : 'above'} the mean
        </span>
        <ConfidenceBadge confidence={insight.confidence} size="sm" reasoning={insight.reasoning} />
      </Link>
    </Card>
  );
}
```

```tsx
// components/purchasing/purchasing-vendor-recommendation-card.tsx
'use client';

import Link from 'next/link';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { Users } from 'lucide-react';
import type { PurchasingAiInsight } from '@/types/purchasing-ai';

type VendorRecommendationInsight = Extract<PurchasingAiInsight, { kind: 'vendor_recommendation' }>;

export function PurchasingVendorRecommendationCard({ insight }: { insight: VendorRecommendationInsight }) {
  return (
    <Card padding="sm" className="border-s-2 border-s-ai">
      <div className="flex items-start gap-2">
        <Users className="mt-0.5 h-4 w-4 shrink-0 text-ai" aria-hidden="true" />
        <div className="flex-1 space-y-1">
          <p className="text-sm text-text-primary">
            Vendor shortlist ready — <span dir="ltr" className="[unicode-bidi:isolate]">{insight.rfqNumber}</span>,{' '}
            {insight.vendorsRanked} vendors ranked
          </p>
          {insight.lowHistory && (
            <p className="text-xs text-text-secondary">Low history — verify manually</p>
          )}
        </div>
        <ConfidenceBadge confidence={insight.confidence} size="sm" reasoning={insight.reasoning} />
      </div>
      <Button asChild variant="secondary" size="sm" className="mt-2">
        <Link href={`/purchasing/rfqs/${insight.rfqId}?tab=invite-draft`}>Review</Link>
      </Button>
    </Card>
  );
}
```

Two design decisions run through all three cards and are worth stating once rather than per
component. First, **no card offers a one-click "Do it."** Per `PURCHASING.md → AI Integration`'s
"three-button contract" section, every Purchasing AI capability is either suggest-only,
draft-creation-only, or a deterministic computation — none reaches `can_execute_directly: true` — so
`PurchasingDuplicateBillCard` links to the flagged Bill's own confirmation flow,
`PurchasingPriceAnomalyCard` links to the RFQ comparison grid, and `PurchasingVendorRecommendationCard`
offers only a **Review** button into a draft invite list, never an inline accept. Second, **document
numbers are bidi-isolated inline** (`dir="ltr"` + `[unicode-bidi:isolate]`) inside every card's
sentence-form copy, since these render identically under an Arabic session (`# RTL & Localization`).

# Data & State

## Endpoints

Every endpoint below is reused verbatim from `PURCHASING.md → Data & State` and, transitively,
`docs/accounting/PURCHASING.md → API Endpoints` — this document introduces no new path, no new verb,
and no new response shape.

| Purpose | Endpoint | Permission |
|---|---|---|
| Hub bootstrap bundle (KPI strip + Outstanding by tab) | `GET /api/v1/purchasing/reports/purchase-dashboard` | `purchasing.read` + `reports.read` |
| Three-Way Match Status counts | `GET /api/v1/purchasing/bills?filter[match_status][in]=not_matched,matched,variance,override_approved&group_by=match_status` | `purchasing.bill.read` |
| Pending Approvals (scoped) | `GET /api/v1/approvals?subject_type[in]=purchase_request,purchase_order,bill,vendor_payment&assigned_to=me` | `ai.approve` to act, `reports.read` to view |
| Approve / reject a step | `POST /api/v1/approvals/{id}/approve`, `POST /api/v1/approvals/{id}/reject` | `ai.approve` |
| AI risks (Duplicate Bill / Fraud) | `GET /api/v1/ai/risks?module=purchasing&limit=3&sort=-severity` | `reports.read` |
| AI recommendations (Price Comparison, Vendor Recommendation) | `GET /api/v1/ai/recommendations?module=purchasing&limit=3` | `reports.read` |
| Recent activity | Pre-aggregated inside the bootstrap bundle | `purchasing.read` |

Worked examples follow for the five calls this screen issues directly; every response uses the
standard envelope `docs/api/API_DESIGN_PRINCIPLES.md` and the platform-wide contract fix (`success`,
`data`, `message`, `errors`, `meta.pagination`, `request_id`, `timestamp`).

**Worked example — hub bootstrap bundle:**

```json
GET /api/v1/purchasing/reports/purchase-dashboard?range=this_fiscal_period&branch=all&currency=KWD
X-Company-Id: 41

{
  "success": true,
  "data": {
    "open_purchase_requests": { "count": 14, "base_currency_amount": "6204.0000" },
    "open_purchase_orders": { "count": 37, "base_currency_amount": "84210.0000" },
    "bills_due_this_week": { "count": 9, "base_currency_amount": "21340.0000", "overdue_count": 2 },
    "ytd_spend_vs_budget_pct": 71.0,
    "outstanding_by_tab": {
      "requests": { "count": 3, "label": "pending" },
      "rfqs": { "count": 1, "label": "awaiting quotes" },
      "orders": { "count": 37, "label": "open" },
      "receipts": { "count": 2, "label": "pending inspection" },
      "bills": { "count": 5, "label": "variance flagged" },
      "payments": { "count": 3, "label": "pending release" }
    },
    "recent_activity": [
      { "event": "goods_receipt.posted", "document_number": "GR-2026-000512", "occurred_at": "2026-07-16T07:00:00Z" },
      { "event": "bill.flagged_duplicate", "document_number": "BILL-2026-000789", "occurred_at": "2026-07-16T06:00:00Z" },
      { "event": "purchase_order.sent", "document_number": "PO-2026-004417", "vendor_name_en": "Gulf Paper Trading", "occurred_at": "2026-07-15T14:00:00Z" }
    ],
    "as_of": "2026-07-16T09:00:00Z"
  },
  "message": "Purchase dashboard for company 41.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8f3a1b2c-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Worked example — Three-Way Match Status counts:**

```json
GET /api/v1/purchasing/bills?filter[match_status][in]=not_matched,matched,variance,override_approved&group_by=match_status
X-Company-Id: 41

{
  "success": true,
  "data": [
    { "match_status": "not_matched", "label": "Not yet matched", "count": 12, "base_currency_amount": "18420.0000" },
    { "match_status": "matched", "label": "Matched (auto)", "count": 58, "base_currency_amount": "142980.0000" },
    { "match_status": "variance", "label": "Variance flagged", "count": 5, "base_currency_amount": "9130.0000" },
    { "match_status": "override_approved", "label": "Override approved", "count": 1, "base_currency_amount": "640.0000" }
  ],
  "message": "Three-way match status for company 41.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1c2d3e4f-5a6b-7c8d-9e0f-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Worked example — Pending Approvals (scoped):**

```json
GET /api/v1/approvals?subject_type[in]=purchase_request,purchase_order,bill,vendor_payment&assigned_to=me
X-Company-Id: 41

{
  "success": true,
  "data": [
    {
      "id": 550231,
      "subject_type": "purchase_order",
      "subject_id": 4417,
      "document_number": "PO-2026-004417",
      "vendor_name_en": "Gulf Paper Trading",
      "total_amount": "1388.3600",
      "currency_code": "KWD",
      "step_order": 2,
      "step_count": 3,
      "sla_due_at": "2026-07-17T09:00:00Z",
      "status": "pending"
    },
    {
      "id": 550298,
      "subject_type": "vendor_payment",
      "subject_id": 902,
      "document_number": "VP-2026-000902",
      "vendor_name_en": "Al Zahra Office Supplies",
      "total_amount": "1240.0000",
      "currency_code": "KWD",
      "step_order": 1,
      "step_count": 1,
      "requires_different_actor_than": 118,
      "sla_due_at": "2026-07-16T18:00:00Z",
      "status": "pending"
    }
  ],
  "message": "Pending approvals assigned to the current user.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 2, "cursor": null } },
  "request_id": "2d3e4f5a-6b7c-8d9e-0f1a-2b3c4d5e6f7a",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Worked example — AI risks (Duplicate Bill / Fraud), the exact `BILL-2026-000789`/`BILL-2026-000317`
pair `PURCHASING.md → AI Integration` narrates:**

```json
GET /api/v1/ai/risks?module=purchasing&limit=3&sort=-severity
X-Company-Id: 41

{
  "success": true,
  "data": [
    {
      "kind": "duplicate_bill",
      "id": 771102,
      "agent_name": "fraud_detection",
      "confidence": 0.920,
      "reasoning": "Same vendor, amount within 0.5%, adjacent bill date, different vendor_bill_reference.",
      "bill_id": 20789,
      "bill_number": "BILL-2026-000789",
      "matched_bill_number": "BILL-2026-000317",
      "supporting_document_ids": [20789, 20317],
      "severity": "high"
    }
  ],
  "message": "Purchasing risk flags for company 41.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 3, "total": 1, "cursor": null } },
  "request_id": "3e4f5a6b-7c8d-9e0f-1a2b-3c4d5e6f7a8b",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Worked example — AI recommendations (Price Comparison + Vendor Recommendation), the exact
`RFQ-2026-0091`/`RFQ-2026-0102` examples `PURCHASING.md` narrates:**

```json
GET /api/v1/ai/recommendations?module=purchasing&limit=3
X-Company-Id: 41

{
  "success": true,
  "data": [
    {
      "kind": "price_anomaly",
      "id": 771205,
      "agent_name": "general_accountant",
      "confidence": 0.810,
      "reasoning": "Vendor 'FastTrade' quoted 2.3 standard deviations below RFQ-2026-0091's response-set mean unit price.",
      "rfq_id": 91,
      "rfq_number": "RFQ-2026-0091",
      "vendor_name": "FastTrade",
      "std_deviations": -2.3,
      "line_id": 4
    },
    {
      "kind": "vendor_recommendation",
      "id": 771260,
      "agent_name": "general_accountant",
      "confidence": 0.880,
      "reasoning": "Ranked on historical on_time_delivery_rate, quality_score, average_lead_time_days, and prior rfq_responses for the same product category.",
      "rfq_id": 102,
      "rfq_number": "RFQ-2026-0102",
      "vendors_ranked": 4,
      "low_history": false
    }
  ],
  "message": "Purchasing recommendations for company 41.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 3, "total": 2, "cursor": null } },
  "request_id": "4f5a6b7c-8d9e-0f1a-2b3c-4d5e6f7a8b9c",
  "timestamp": "2026-07-16T09:00:00Z"
}
```

**Worked example — approving a Pending Approvals step**, reproducing `PURCHASING.md → Interactions &
Flows`'s `useApproveRequest` contract concretely:

```json
POST /api/v1/approvals/550231/approve
X-Company-Id: 41
Idempotency-Key: 9c7f9e9a-9b1a-4b0a-8b3a-2f6b7c8d9e0f
Content-Type: application/json

{ "comment": null }
```
```json
{
  "success": true,
  "data": { "id": 550231, "status": "approved", "step_order": 2, "next_step_order": 3, "acted_at": "2026-07-16T09:04:00Z" },
  "message": "Approval step recorded.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5a6b7c8d-9e0f-1a2b-3c4d-5e6f7a8b9c0d",
  "timestamp": "2026-07-16T09:04:00Z"
}
```

## Query keys, hooks

Extends the exact `purchasingKeys`/`purchasingAiKeys` factories `PURCHASING.md → Data & State`
already defines:

```ts
// lib/api/query-keys.ts (additive to the factories PURCHASING.md already defines)
export const purchasingKeys = {
  all: ['purchasing'] as const,
  dashboard: (filters: PurchasingFilters) => [...purchasingKeys.all, 'dashboard', filters] as const,
  matchStatus: (filters: PurchasingFilters) => [...purchasingKeys.all, 'match-status', filters] as const,
  recentActivity: () => [...purchasingKeys.all, 'recent-activity'] as const,
};

export const purchasingAiKeys = {
  recommendations: (limit: number) => ['ai', 'recommendations', { module: 'purchasing', limit }] as const,
  risks: (limit: number) => ['ai', 'risks', { module: 'purchasing', limit }] as const,
};
```

```ts
// hooks/use-purchasing-overview.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { purchasingKeys } from '@/lib/api/query-keys';
import { toFilterParams } from '@/lib/api/filters';
import type { PurchasingFilters } from '@/types/purchasing';

export function usePurchasingDashboard(filters: PurchasingFilters) {
  return useQuery({
    queryKey: purchasingKeys.dashboard(filters),
    queryFn: () => apiClient.get('/api/v1/purchasing/reports/purchase-dashboard', { params: toFilterParams(filters) }),
    staleTime: 0,
  });
}

export function useThreeWayMatchStatus(filters: PurchasingFilters) {
  return useQuery({
    queryKey: purchasingKeys.matchStatus(filters),
    queryFn: () =>
      apiClient.get('/api/v1/purchasing/bills', {
        params: { ...toFilterParams(filters), 'filter[match_status][in]': 'not_matched,matched,variance,override_approved', group_by: 'match_status' },
      }),
    staleTime: 0,
  });
}

export function usePendingApprovals() {
  return useQuery({
    queryKey: ['approvals', { subjectTypes: ['purchase_request', 'purchase_order', 'bill', 'vendor_payment'], assignedTo: 'me' }],
    queryFn: () =>
      apiClient.get('/api/v1/approvals', {
        params: { 'subject_type[in]': 'purchase_request,purchase_order,bill,vendor_payment', assigned_to: 'me' },
      }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
}

export function useApprovePurchasingStep() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
      apiClient.post(`/api/v1/approvals/${id}/approve`, {}, { headers: { 'Idempotency-Key': idempotencyKey } }),
    onMutate: async ({ id }) => {
      await queryClient.cancelQueries({ queryKey: ['approvals'] });
      const previous = queryClient.getQueryData(['approvals']);
      queryClient.setQueryData(['approvals'], (old: any) => ({
        ...old,
        data: old.data.map((a: any) => (a.id === id ? { ...a, status: 'approved' } : a)),
      }));
      return { previous };
    },
    onError: (_err, _vars, context) => queryClient.setQueryData(['approvals'], context?.previous),
  });
}
```

## Cache tuning

Reuses `PURCHASING.md → Data & State`'s exact tiers:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `dashboard`, `matchStatus` | `0` | Correctness over avoiding a refetch; kept fresh via realtime invalidation. |
| Recent activity | `recentActivity` | `30_000` | Matches Dashboard's own tuning for the identical pattern. |
| AI feeds | `purchasingAiKeys.recommendations`, `.risks` | `10_000`, `refetchOnWindowFocus: true` | Matches `FRONTEND_ARCHITECTURE.md`'s AI-feed row verbatim. |
| Pending Approvals | scoped `approvals` query | `10_000`, `refetchOnWindowFocus: true` | Identical tuning to the AI Command Center's own Approval Center panel. |

## Realtime

Reuses the exact channels `PURCHASING.md → Data & State → Realtime` already defines:

| Channel | Purpose |
|---|---|
| `private-company.{id}.purchasing` | Domain events: `purchase_request.approved`, `purchase_order.issued`/`.sent`, `goods_receipt.posted`, `quality_inspection.completed`, `bill.matched`/`.approved`/`.posted`, `vendor_payment.released` |
| `private-company.{id}.approvals` | Refreshes Pending Approvals the moment a new step lands or resolves. |
| `private-company.{id}.ai-jobs` | Refreshes the AI Purchasing Insights rail on any Fraud Detection, General Accountant, or Vendor Recommendation run completion. |

`bill.matched`/`.approved` purges `purchasingKeys.matchStatus`; `purchase_order.issued`/`.sent` purges
`purchasingKeys.dashboard`; `vendor_payment.released` purges `purchasingKeys.dashboard`. A reconnect
after a dropped WebSocket invalidates every realtime-fed query key on this screen once, as a single
batch:

```ts
// lib/realtime/on-reconnect.ts (excerpt, purchasing-scoped keys)
echo.connector.pusher.connection.bind('connected', () => {
  if (wasDisconnected) {
    queryClient.invalidateQueries({ queryKey: purchasingKeys.all });
    queryClient.invalidateQueries({ queryKey: ['approvals'] });
    queryClient.invalidateQueries({ queryKey: purchasingAiKeys.risks(3) });
    queryClient.invalidateQueries({ queryKey: purchasingAiKeys.recommendations(3) });
  }
});
```

## AI agents feeding this screen

Reuses `PURCHASING.md → Data & State → AI agents feeding this screen` verbatim:

| Agent | Contribution | Autonomy |
|---|---|---|
| Reporting Agent | KPI strip's YTD-vs-budget figure | Auto (pure aggregation) |
| General Accountant (procurement-tuned) | Price Comparison anomaly overlay | Suggest-only |
| General Accountant / Treasury Manager (procurement-tuned) | Vendor Recommendation shortlists | Suggest-only |
| Fraud Detection / Auditor | Duplicate Bill Detection, Fraud Detection alerts | Suggest/alert-only; can request a hold, never impose one |
| Forecast Agent | `source = 'ai_forecast'` PR drafts (row-level badge, not a hub card) | Draft-creation only |
| CFO / Treasury Manager (advisory) | Purchase Optimization notes | Fully advisory |

# Interactions & Flows

**Opening the screen.** The shell (sub-nav, header, filter bar) paints immediately; the KPI strip
resolves within one Redis-cache round trip, since the bootstrap bundle is prefetched server-side in
`page.tsx`. Pending Approvals, the AI Insights rail, the Three-Way Match Status card, Recent activity,
and Outstanding by tab each stream in independently behind their own `Suspense` boundary — no region
blocks another, per `# Data & State` and `# Performance`.

**Changing the period, branch, or reporting currency.** `PurchasingFilterBar` writes `{ range,
branchId, currencyCode }` to the URL; the KPI strip, the Three-Way Match Status card, and Recent
activity all refetch. The AI Insights rail's recommendations and risks do not refetch on a period
change — a duplicate-bill flag is not "for last month" — only on a branch change where the underlying
document set genuinely differs, mirroring `docs/frontend/DASHBOARD.md`'s identical rule for its own
AI Summary Rail. `placeholderData: keepPreviousData` keeps every affected region showing its previous
values, dimmed, rather than flashing empty.

**Switching tabs.** Each sub-nav item is a real navigation, not a client-side tab switch; this hub's
`?range=&branch=&currency=` query parameters are read as the destination tab's default filter seed,
but each tab's own `DataTable` state (search, sort, page/cursor) resets fresh on navigation.

**Clicking a KPI tile.** Open Purchase Requests → `/purchasing/requests?filter[status][in]=
pending_approval`; Open Purchase Orders → `/purchasing/purchase-orders?filter[status][in]=sent,
partially_received`; Bills Due This Week → `/purchasing/bills?filter[due_date][lte]=<end_of_week>&
filter[status][nin]=paid,void`; YTD Spend vs. Budget → `/purchasing/reports/spend-analysis?
range=ytd`. A KPI click never opens a modal — it always means "go look at the source."

**Acting on a Pending Approval.** Clicking **Approve** calls `POST /api/v1/approvals/{id}/approve`
with a client-generated `Idempotency-Key`, optimistically flips that step in the cache via
`useApprovePurchasingStep` (above), and rolls back on any `4xx`/`5xx`. **Reject** requires a
non-empty reason before its confirm button un-disables. A multi-step chain renders its `Stepper`;
only the step assigned to the current user's role is interactive.

**Reviewing the Three-Way Match Status card.** Clicking any segment navigates to
`/purchasing/bills?filter[match_status][in]=<segment>` via `ThreeWayMatchStatusCard`'s
`onBucketClick` prop (`# Components Used`).

**Acting on an AI Purchasing Insight.** `PurchasingDuplicateBillCard` opens the flagged Bill's own
detail page scrolled to its match-status section; `PurchasingPriceAnomalyCard` opens the closed RFQ's
comparison grid with the outlier line pre-highlighted (`?highlight={lineId}`);
`PurchasingVendorRecommendationCard`'s **Review** button opens the target RFQ's invite-list draft
(`?tab=invite-draft`) for confirmation before issue. Every one of these is a hand-off to the owning
tab's own screen, never an inline action completed on this hub.

**Using a Quick Action.** Each page-header button navigates to its target's canonical full-page route
(`/purchasing/requests/new`, `/purchasing/purchase-orders/new`, `/purchasing/bills/new`) — never an
inline modal, since none of Purchasing's creation flows are on the platform's list of interactions
safe to intercept as a `Dialog` the way Sales' quick-create drafts are; a Purchase Order or a Bill is
consequential enough, and its own form substantial enough, that it always gets a full page.

# AI Integration

This hub is one of three places QAYD's Purchasing AI capabilities become visible to a human (the Bill
and RFQ detail pages each capability's own action ultimately opens being the other two), and it is the
first place a Purchasing Manager, Finance Manager, or CFO sees them, since every card here is capped
to three and stripped of its alternatives-expansion affordance — the same condensation
`docs/frontend/DASHBOARD.md` applies to its own Top Recommended Actions rail. Every AI-sourced element
obeys the platform-wide contract in full: `confidence` (0.00–1.00) and `reasoning` are never hidden,
and nothing here ever auto-commits a financial write.

**Duplicate Bill / Fraud** (`PurchasingDuplicateBillCard`). *Agent: Fraud Detection / Auditor.* A Bill
scoring above the duplicate threshold (default 0.75 confidence) surfaces as: "Possible duplicate —
BILL-2026-000789 matches BILL-2026-000317, 92% confidence," with `reasoning` naming the specific
near-match signal (same vendor, amount within 0.5%, adjacent date, different `vendor_bill_reference`).
Clicking it opens the flagged Bill, held at `pending_match` with the same banner inline; a Finance
user must explicitly confirm "Not a duplicate" (logged) before the Bill can proceed — the agent never
auto-rejects it. Broader Fraud Detection alerts (a vendor's bank account changing shortly before a
large payment, a segregation-of-duties violation, threshold-gaming just under
`non_po_bill_max_amount`) render with the same card shape but route to the Auditor and Finance
Manager roles; the highest-severity pattern can *request* a payment hold, but the hold is only
actually placed if a user holding `purchasing.payment.hold` acts on it — there is no client code path
that calls that endpoint from an AI-authored card without that human action in between.

**Price Comparison** (`PurchasingPriceAnomalyCard`). *Agent: General Accountant.* The comparison grid
itself, computed for every closed RFQ, is deterministic — this card surfaces only the AI overlay on
top of it: an anomaly note when one vendor's line price sits more than two standard deviations from
that RFQ's response-set mean, in either direction. "Price outlier on RFQ-2026-0091 — vendor
'FastTrade' 2.3σ below the mean, 81% confidence" links straight into that RFQ's comparison grid with
the outlier line pre-highlighted, so a Purchasing Manager evaluating an award decision sees the
anomaly in the same view as the numbers it is about.

**Vendor Recommendation** (`PurchasingVendorRecommendationCard`). *Agent: General Accountant /
Treasury Manager, procurement-tuned.* For an approved Purchase Request not yet routed to an RFQ, or a
new RFQ being drafted, the agent pre-populates a ranked vendor shortlist — "Vendor shortlist ready —
RFQ-2026-0102, 4 vendors ranked, 88% confidence" — scored on historical `on_time_delivery_rate`,
`quality_score`, `average_lead_time_days`, and prior `rfq_responses` for the same product. Clicking
**Review** opens the target RFQ's own invite-list draft, pre-filled but requiring explicit
confirmation before issue. A recommendation scoring below 0.55 confidence still renders but is
visually de-emphasized with a "low history — verify manually" label (the `lowHistory` field on the
type union, `# Components Used`) rather than omitted.

**The three-button contract, unaltered.** None of Purchasing's AI capabilities reach
`can_execute_directly: true` today (`PURCHASING.md → AI Integration`), so "Do it" is never shown on
this hub — only a navigation link (Duplicate Bill, Price Anomaly) or **Review** (Vendor
Recommendation), consistent with the platform's structural guarantee that a gated action has no
client-side path to execute itself from this screen.

**Unavailable AI.** If both the recommendations and risks calls fail, time out, or the AI engine is
disabled for the company, `PurchasingAiInsightsRail` renders `AiUnavailableState` — "AI insights are
temporarily unavailable" — and every other capability on this hub remains fully available. If only one
of the two calls fails, the rail renders whatever the other returned rather than falling back
wholesale, since a risks-endpoint outage says nothing about whether recommendations are trustworthy.

# States

Every region on this hub carries its own loading, empty, and error presentation — no region blocks
another, and none ever renders a bare spinner as its primary loading state.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI strip | Four `Skeleton` tiles matching `KpiTile`'s geometry | Brand-new company: "No purchasing activity yet" with a "Create your first Purchase Request" CTA | Per-tile inline "Couldn't load this figure — Retry"; other tiles stay interactive |
| Pending Approvals | Three skeleton `ApprovalCard` shapes | "Nothing waiting on you" — reassuring, not apologetic | Inline retry card; "View all in Approval Center" link stays functional even if this condensed query fails |
| AI Purchasing Insights | Three skeleton cards | "No open insights right now — duplicate checks, price comparisons, and vendor recommendations will appear here" | `AiUnavailableState`, respecting any `Retry-After` header on a `503` |
| Three-Way Match Status | Skeleton bar at the real component's height | "No bills yet" (never a zero-count bar) — distinct from "everything happens to be Matched," which renders normally, all-green | Retry card; omitted entirely (not erroring) for a caller lacking `purchasing.bill.read` |
| Recent activity | 5–6 skeleton rows | "No activity yet — create your first Purchase Request or Purchase Order," CTA into Quick Actions | Inline retry row; "View all activity" remains functional independently |
| Outstanding by tab | Six skeleton rows | Each row independently shows "0" — a healthy queue of zero is itself useful information | Row-level dash with a tooltip on the specific failed count |
| Realtime new-activity available | Dismissible, non-disruptive banner ("New activity — Refresh") above the affected region | — | — |

The distinction between "empty" and "error" is enforced in code, not left to a developer's judgment
call at each call site: every hook above surfaces `isPending` / `isError` / `data.length === 0` as
three separate branches, and `PurchasingAiInsightsRail`'s own test suite explicitly asserts the
loading→error transition never renders the same markup as loading→empty, mirroring the identical
discipline `docs/frontend/screens/SALES_SCREEN.md → States` documents for its own rail sections.

# Responsive Behavior

Follows `RESPONSIVE_DESIGN.md`'s five-tier breakpoint system exactly — **Mobile** (`base`, <640px),
**Tablet** (`sm:`/`md:`, 640–1023px), **Laptop** (`lg:`, 1024px), **Desktop** (`xl:`/`2xl:`, 1280px/
1536px), **Ultra Wide** (`3xl:`, 1920px) — identically to `PURCHASING.md → Responsive Behavior`,
applied concretely to this route:

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<640px) | Sub-nav collapses to a horizontal-scroll chip row; Filter Bar collapses to a "Filters" trigger opening a `Sheet`; KPI strip becomes a snap-scroll carousel, one tile per screen width; Pending Approvals, AI Insights rail, Three-Way Match Status, Recent activity, and Outstanding by tab stack full-width in that order. |
| `md:` (768px+) | KPI strip becomes two tiles per row; persistent icon-rail Sidebar replaces the mobile bottom-tab-bar pattern. |
| `lg:` (1024px+) | KPI strip becomes a full row; Pending Approvals and the AI Insights rail activate their 7/5 split; Three-Way Match Status's three segments sit inline in one row. |
| `xl:`/`2xl:` (1280px/1536px+) | Same layout, generous margins; content capped at `max-w-[1440px]` above `2xl:`. |
| `3xl:` (1920px+) | The global 360px AI Rail companion panel may dock inline without competing for the same column as this screen's own AI Insights rail. |

Touch targets on every clickable KPI tile, `ApprovalCard` action, and Three-Way Match Status segment
maintain the platform's 44×44px minimum hit area at every tier below `lg:`, implemented once in the
shared `Button`/`IconButton` components rather than per instance on this hub. Each of the six tabs'
own `DataTable` instances this hub links into follows `RESPONSIVE_DESIGN.md`'s Finance Tables On
Small Screens rules verbatim (card-per-row below `md:`, virtualization past ~200 rows), consistent
with `PURCHASING.md`'s identical rule for the same tabs.

# RTL & Localization

Inherited from `LAYOUT_SYSTEM.md`'s RTL contract and `PURCHASING.md → RTL & Localization`, applied
concretely to this screen's own regions:

- **Logical properties only.** Sub-nav order, Filter Bar order, KPI tile order, and Outstanding-by-tab
  all use `ms-*`/`me-*`/`text-start`/`text-end` exclusively; flipping `dir="rtl"` mirrors the whole
  hub — including the Pending Approvals/AI Insights 7/5 split — with zero screen-specific RTL code.
- **Document numbers stay LTR-isolated.** `PO-2026-004417`, `BILL-2026-000789`, `RFQ-2026-0091` —
  every reference renders inside a `dir="ltr"` + `unicode-bidi: isolate` span, concretely shown in
  each new card's TSX (`# Components Used`).
- **Numeric alignment is physically fixed.** Every `AmountCell` on this hub — the KPI strip's spend
  figures, the Three-Way Match Status card's per-segment amounts — stays `text-end` in both
  directions.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention.
- **Bilingual names throughout.** Every vendor name renders `vendor_name_ar` under an Arabic session,
  falling back to `vendor_name_en` only if genuinely absent.
- **Directional icons flip; meaning-bearing icons don't.** The sub-nav's active-tab indicator mirrors;
  the Three-Way Match Status segment order and the `TrendingDown`/`TrendingUp` glyph in
  `PurchasingPriceAnomalyCard` do not, since they encode a financial/statistical meaning, not a
  reading direction.
- **AI reasoning text is generated in the viewer's session locale**, rendered as plain translated
  prose, never re-translated client-side; Arabic procurement terminology is used precisely — طلب شراء
  (Purchase Request), أمر شراء (Purchase Order), إيصال استلام (Goods Receipt), فاتورة مورد (Vendor
  Bill), مطابقة ثلاثية (Three-Way Match).

| Context | English | Arabic |
|---|---|---|
| KPI tile | Open Purchase Orders | أوامر الشراء المفتوحة |
| Match status segment | Variance flagged | مطابقة بها فرق |
| AI insight | Possible duplicate — 92% confidence. | احتمال تكرار الفاتورة — بثقة 92%. |
| AI insight | Price outlier — vendor "FastTrade" 2.3σ below the mean. | انحراف في السعر — المورد "FastTrade" أقل من المتوسط بـ 2.3 انحراف معياري. |
| AI insight | Vendor shortlist ready — 4 vendors ranked. | قائمة موردين مرشحة — 4 موردين مرتبين. |
| Empty state | Nothing waiting on you. | لا يوجد ما ينتظر إجراءك. |
| Quick action | New Purchase Request | طلب شراء جديد |

Arabic copy on this hub is authored directly by a fluent professional-register writer, not
machine-translated from the English strings above, matching `PURCHASING.md`'s stated voice
discipline.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md` and identically to
`PURCHASING.md → Dark Mode`. Nothing on this screen references a raw hex value or a Tailwind palette
utility; every surface, border, and status color resolves through the semantic tokens `DARK_MODE.md`
defines.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the KPI tiles, the Pending
  Approvals and AI Insights cards, the Three-Way Match Status card, and every tab's own table surface
  sit on `--surface-base`; any overlay (the mobile Filters `Sheet`) sits on `--surface-raised`/
  `--surface-overlay`. Elevation communicates by getting *lighter* toward the viewer in dark mode,
  never by shadow alone — every card on this hub carries its paired `--border-subtle` hairline in
  dark mode even where the light-mode equivalent relies on shadow alone.
- **Money never takes a status color.** Every `AmountCell` on this hub renders in `--text-primary`
  regardless of theme; only `StatusPill`s, the Three-Way Match Status segments' fill, and the AI risk
  cards' left borders ever carry `--status-success`/`--status-warning`/`--status-error` (with their
  paired `-subtle` background tokens).
- **AI provenance uses the reserved AI accent, never a finance-semantic color.**
  `PurchasingPriceAnomalyCard` and `PurchasingVendorRecommendationCard`'s `border-s-ai` and
  `ConfidenceBadge` fill resolve through `--ai-accent`/`--ai-accent-subtle` exclusively in both
  themes. `PurchasingDuplicateBillCard`'s left border is the one deliberate exception on this rail,
  using `--status-error` rather than the AI accent, since a confirmed high-confidence duplicate is
  framed as a risk needing action, not merely an AI-sourced observation — matching `PURCHASING.md`'s
  own treatment of the same card kind (a duplicate-flagged Bill that is also overdue shows a
  `--status-error` due-date pill and would need its own separate provenance marker to stay AI-legible,
  which this card supplies via its `ConfidenceBadge` rather than a second border color).
- **Contrast.** Every token pairing on this hub is independently verified at ≥4.5:1 (text) / ≥3:1
  (non-text, including the Three-Way Match Status card's segment dividers) in both themes.
- **Print/export independence.** Any exported Spend Analysis or Purchase Dashboard PDF always renders
  in QAYD's fixed light/print palette regardless of the viewer's active theme.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented without deviation and identically to
`PURCHASING.md → Accessibility`, applied concretely below.

- **Landmark structure.** Each region (KPI strip, Pending Approvals, AI Purchasing Insights,
  Three-Way Match Status, Recent activity, Outstanding by tab) is a real `<section>` with a
  visually-hidden `<h2>` labeling it for screen-reader navigation, even where the sighted design
  omits a visible heading — concretely shown in `PurchasingAiInsightsRail`'s own
  `id="ai-purchasing-insights-heading"` (`# Components Used`).
- **Keyboard path.** Tab order flows sub-nav tabs → Filter Bar (`PeriodPicker` → `BranchSelect` →
  `CurrencySelect`) → page-header Quick Actions → KPI strip (each tile a single focusable stop when
  it has an `onClick`) → Pending Approvals' cards and their action buttons → AI Insights rail's cards
  → the Three-Way Match Status card's four clickable segments → Recent activity's row links →
  Outstanding-by-tab's row links. No region requires a mouse to reach or activate any control.
- **The Three-Way Match Status card's segments are real `<button>`s**, each with a full accessible
  name ("Variance flagged, 5 bills, 2,140.000 KWD — view bills"), never a bare colored `<div>` with a
  click handler — shown concretely in the component body above (`# Components Used`).
- **Sortable headers on every tab's `DataTable`** are real `<button>`s with `aria-sort` on the parent
  `<th>`, per `COMPONENT_LIBRARY.md`'s `DataTable` accessibility contract, unaffected by this hub.
- **Realtime updates announce politely, never assertively.** A new Pending Approval landing, a
  Three-Way Match Status count changing, or a fresh AI Insights card arriving all use
  `aria-live="polite"` exclusively — never `"assertive"`.
- **Confidence and reasoning are always real text**, never conveyed through a bare progress-bar width
  alone: `ConfidenceBadge`'s percentage and qualitative label are both text nodes, and its `Tooltip`
  carrying `reasoning` is reachable and dismissible via focus, not hover-only.
- **RBAC-aware disabled controls explain themselves.** A Pending Approval step disabled because it
  isn't yet the caller's turn in a multi-step chain carries `aria-describedby` naming that reason,
  worded distinctly from a control disabled for lack of permission ("Requires
  `purchasing.payment.release`") — the two causes are never collapsed into a generic "You can't do
  this."
- **Every icon-only row action carries a unique accessible name** — "Approve Purchase Order
  PO-2026-004417," never a bare "Approve" repeated identically across every row in a `.map()`.
- **Amounts carry meaning beyond color.** Every `AmountCell` on this hub pairs its value with a
  leading sign glyph and, where relevant, a text label — a colorblind user or a grayscale print of
  this hub's KPI strip loses no information a sighted, color-normal user has.
- **Focus management on navigation and overlays.** Clicking a KPI tile, a Three-Way Match Status
  segment, or a Recent activity row navigates to a new route rather than mutating this page in
  place, so focus lands on the destination's own heading via the platform's standard route-change
  focus-reset; opening the mobile Filters `Sheet` moves focus to its heading and returns it to the
  trigger on close.

# Performance

- **Streamed, not blocking.** Per `# Data & State`, every region is its own `Suspense` boundary; the
  slowest widget on this hub (typically the AI Purchasing Insights rail, if an agent run is still in
  flight) never delays the KPI strip's first paint, and a failure in one region never takes down
  another (`react-error-boundary` per region).
- **Cached at the source.** `GET /purchasing/reports/purchase-dashboard` is Redis-cached server-side;
  a cache miss on a deterministic figure (open PR/PO counts, bills due this week) recomputes
  synchronously inline since it is cheap SQL, while a cache miss on an AI-authored element never
  blocks the page — it serves the last-cached payload with an explicit `stale: true` marker rather
  than making the user wait on an agent run to render their hub.
- **Cursor pagination on every tab, not offset.** Each of the six tabs' `DataTable` instances use
  `paginationMode="cursor"` against their own endpoint, so scroll cost on the Bills or Purchase
  Orders tab stays `O(per_page)` regardless of how many pages a long session has already fetched.
- **Virtualization past ~200 rows.** Any tab's `DataTable` mounts `@tanstack/react-virtual` once its
  loaded row count crosses roughly 200, most relevant to the Bills and Purchase Orders tabs for a
  company with meaningful transaction volume.
- **Debounced, stable filter changes.** The Filter Bar's branch/currency selectors debounce at
  300ms, with `placeholderData: keepPreviousData` on every affected query so a filter change dims
  the previous result rather than flashing empty.
- **Realtime patches are the exception, not the rule.** Every realtime event described in
  `# Data & State → Realtime` goes through an ordinary `invalidateQueries` call, which can never
  drift from what the server actually holds.
- **Web Vitals tracked against a realistic baseline.** LCP/CLS/INP for this route are tagged with
  company-size band, since a company with thousands of open Purchase Orders and a brand-new company
  with none have legitimately different tail latencies.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while one of this hub's own widget fetches is still in flight | `queryClient.clear()` runs before `router.refresh()`; the in-flight request's eventual response is discarded on arrival since its query key no longer exists in the cleared cache. |
| A role holds `purchasing.read` but none of the finer-grained keys any individual region needs (a narrow custom role) | The KPI strip, Pending Approvals, AI Insights rail, and Three-Way Match Status card each render their own empty-region fallback independently rather than a "you don't have access" wall for the whole hub. |
| A Vendor Payment approaching release is attempted by the same user who created it, above the two-person threshold (`purchasing.payment.release`'s maker≠checker rule) | The API returns `403` with `code: "maker_checker_violation"`; the row's Release action surfaces an inline explanation ("This payment must be released by someone other than its creator") rather than a generic permission-denied toast. |
| A three-way match variance is detected on Bill submission (quantity and/or price outside tolerance) | The API returns `409` with a structured `errors[]` array naming each violated tolerance; the Bill lands in `variance_flagged` and its Three-Way Match Status bucket updates to Variance flagged on the next fetch/realtime push. |
| A Goods Receipt line requires Quality Inspection before its quantity is usable | The received quantity does not yet count toward the receiving PO's "fully received" rollup on this hub's KPI strip until inspection resolves. |
| The AI engine is down or returns `503` for an extended period | The KPI strip, Pending Approvals, Three-Way Match Status, and every tab remain entirely unaffected, since none of them call the AI engine — only the AI Purchasing Insights rail degrades to its "temporarily unavailable" state. |
| An AI Vendor Recommendation card's underlying RFQ is awarded (or cancelled) by another user between the card rendering and this user clicking **Review** | The mutation re-fetches the target RFQ immediately before opening it and compares state; on a mismatch, the card is replaced with "This changed — open RFQ-2026-0102 to review" rather than opening a stale invite-list draft. |
| A duplicate-bill card's underlying Bill is confirmed "Not a duplicate" by another user before this user clicks the card | The Bill detail page's own fetch reflects the current state on open; the rail's own copy of the card is cleared on the next `ai-jobs` invalidation rather than trusting a stale render — no client-side comparison is attempted before navigation, since the destination page is always the authoritative source. |
| Two browser tabs have this hub open; the user changes the branch filter in one | A URL-mirrored, client-scoped filter, not a session-level change — the second tab's own filter state is untouched and the two can legitimately show two different branches at once with no synchronization bug. |
| Laravel Reverb's WebSocket connection drops and later reconnects | Every realtime-fed query key on this hub is invalidated once, as a single batch, on reconnect (`# Data & State`) — a `bill.approved` event that fired during the outage must still be reflected the moment connectivity returns. |
| A brand-new company's first user opens this hub before creating any purchasing document | Every region renders its dedicated brand-new-company empty state (`# States`) rather than a filtered-to-zero-results empty state. |
| A company holds Purchase Orders and Bills across more than one currency (KWD, USD, AED vendors) | Every KPI strip figure and the Three-Way Match Status card's per-segment amounts are always the sum converted to the company's base currency (or the Filter Bar's chosen reporting currency); a `CurrencyTag` in muted emphasis annotates a tab's row only when that specific document's transaction currency differs from the currently displayed reporting currency. |

# End of Document
