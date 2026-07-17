# The AI Command Center — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: AI_COMMAND_CENTER
---

# Purpose

This document is the frontend implementation specification for QAYD's home screen — the AI Command Center — the first authenticated surface every seat lands on after login and the screen the rest of the product's navigation always offers a way back to. It is the front-end realization of `docs/ai/AI_COMMAND_CENTER.md`, which owns the product and behavioral contract this screen renders: the fifteen-agent workforce, the Command Card envelope, the widget registry, the confidence/reasoning/sources discipline, and every panel's data source and AI behavior. This document does not repeat that contract. Where the two appear to disagree, `docs/ai/AI_COMMAND_CENTER.md` is authoritative for *what the screen means and what the AI is allowed to do*, and this document is authoritative for *how the screen is built* — which Next.js route renders it, which components from `docs/frontend/COMPONENT_LIBRARY.md` compose it, how each panel's data reaches the browser, and how the screen behaves across breakpoints, direction, theme, and assistive technology. It inherits every cross-cutting rule `docs/frontend/FRONTEND_ARCHITECTURE.md` and `docs/frontend/DESIGN_LANGUAGE.md` already establish — RSC-by-default rendering, TanStack Query as the only server-state cache, the AI Integration Layer's three-button pattern, the ink/brass/semantic token system — rather than re-deriving any of them here.

The AI Command Center's product premise — fifteen specialist agents (`CEO_ASSISTANT`, `CFO_AGENT`, `TREASURY_MANAGER`, `REPORTING_AGENT`, `GENERAL_ACCOUNTANT`, `AUDITOR`, `TAX_ADVISOR`, `PAYROLL_MANAGER`, `INVENTORY_MANAGER`, `FRAUD_DETECTION`, `DOCUMENT_AI`/`OCR_AGENT`, `FORECAST_AGENT`, `COMPLIANCE_AGENT`, `APPROVAL_ASSISTANT`) have already done the night's work before a human opens the app — puts unusual pressure on the frontend specifically. A screen whose entire premise is "the work already happened" fails immediately if it is the slowest screen in the product, if it shows a blank void while a dozen independent endpoints resolve, or if an AI narrative reads as indistinguishable from a number Laravel has already posted. This document exists so that promise survives contact with a real browser: every panel streams independently, every AI-authored pixel carries its confidence and its citation, every sensitive action still runs through the same human-approval gate a direct API call would enforce, and the whole screen reads identically — in structure, in trust, in legibility — whether it is rendered in English or Arabic, light or dark, on a 27-inch monitor or a 375px phone held in one hand.

This document covers seven of the Command Center's panels in full depth — Morning Briefing, Business Health Score, Cash Flow Status, Detected Risks, Approval Center, AI Insights, and the Ask AI command surface — because these seven are the panels every role sees from day one and the panels whose data-fetching, realtime, and approval patterns every other widget in the full registry (`docs/ai/AI_COMMAND_CENTER.md → Widgets`) reuses without variation. Revenue Trends, Expense Trends, AI Recommendations, Upcoming Tax Deadlines, Payroll Alerts, Inventory Alerts, Supplier Risks, Customer Risks, Fraud Alerts, Financial Forecast, Automation Center, Today's Tasks, Urgent Actions, Voice Assistant, Natural Language Queries, Simulation Panel, Decision Support, and the KPI strip render through the identical widget contract, `AiCardShell`/`AIProposalPanel`/`ConfidenceBadge` component set, and Suspense/streaming architecture specified below — a future addendum can enumerate their individual `widget_id` wiring without touching a single rule established here.

# Route & Access

The screen renders at `app/(app)/command-center/page.tsx` and is the application's default post-login destination: `app/(auth)/login/page.tsx` and the post-MFA redirect both send an authenticated user to `/command-center` unless a deep link was pending (per `docs/frontend/NAVIGATION_SYSTEM.md`'s routing rules), and the sidebar's top-most nav item always points here. `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own App Router tree, `docs/frontend/NAVIGATION_SYSTEM.md`, `docs/frontend/RESPONSIVE_DESIGN.md`, and `docs/frontend/COMPONENT_LIBRARY.md`'s composition patterns all name this route's segment `dashboard/` from an earlier drafting pass, written before the Command Center's product identity — a workforce's briefing, not a chart wall — was locked in. `command-center` is this document's and the product's canonical segment name going forward; `app/(app)/dashboard/page.tsx` is retained only as a permanent redirect to `/command-center`, so bookmarks and any hardcoded link surviving from the earlier naming still resolve. New code, new links, and every other document should read `/command-center`.

```ts
// next.config.ts (excerpt)
async redirects() {
  return [{ source: "/dashboard", destination: "/command-center", permanent: true }];
},
```

There is no dedicated permission that gates the route itself — every authenticated user with at least one permission in their active company lands here, because the screen's whole job is to show each role a structurally different set of panels, never to be denied to a role wholesale. What differs by role is which panels the layout renders and which of a rendered panel's actions are reachable:

| Role | Panels rendered | Notes |
|---|---|---|
| Owner, CFO | All seven, full width | Only roles that see every KPI-row tile and both approval steps on a payroll or tax item by default |
| Finance Manager, Senior Accountant | All seven | Approval Center shows only the steps assigned to their own role/user; cannot act on a step ahead of their turn |
| Accountant | Morning Briefing, Business Health Score (read-only), AI Insights, Ask AI | No Detected Risks or Cash Flow Status unless `reports.read` is separately granted |
| Auditor / External Auditor | Business Health Score, Detected Risks, AI Insights — read-only | No approve/reject affordances anywhere (`ai.approve` absent); see `docs/ai/AI_COMMAND_CENTER.md → User Journeys §3` for the time-boxed variant |
| Payroll Officer, Inventory Manager, Sales/Purchasing roles | Morning Briefing, Ask AI, plus only the risk/alert widgets matching their own module's `payroll.read`/`inventory.read` grant | Never Cash Flow Status or the full Detected Risks radar unless `reports.read` is separately granted |

Each panel enforces its own permission independently at the data layer — `reports.read` for Morning Briefing, Business Health Score, Cash Flow Status, Detected Risks, and AI Insights; `ai.approve` to act inside Approval Center, `reports.read` to view it read-only; `ai.chat` for Ask AI — exactly as named in `docs/ai/AI_COMMAND_CENTER.md`'s per-panel Data Source subsections and the Widgets registry's Permission column. The page component never hand-rolls a role check; it renders every panel behind a `<Can permission="...">` guard (`docs/frontend/FRONTEND_ARCHITECTURE.md → GET /me, permissions, and the SessionProvider`) driven by the `permissions` array `GET /api/v1/auth/me` returns for the active company, and the guard hides the panel outright rather than rendering it disabled — an Accountant should never see a grayed-out Cash Flow Status tile hinting at cash figures they cannot read.

```tsx
// app/(app)/command-center/page.tsx (excerpt — permission gating)
<Can permission="reports.read">
  <Suspense fallback={<WidgetSkeleton span="2x1" />}>
    <CashFlowStatusCard />
  </Suspense>
</Can>
<Can
  permission="ai.approve"
  fallback={<Can permission="reports.read"><ApprovalCenterCard readOnly /></Can>}
>
  <ApprovalCenterCard />
</Can>
```

# Layout & Regions

The Command Center is one CSS grid with a fixed DOM source order — Urgent Actions, Morning Briefing, the KPI/health row, the two-column Insights/Risks region, the Approval/Tasks rail, then the docked Ask AI surface — matching `docs/frontend/RESPONSIVE_DESIGN.md → Dashboard Reflow`'s ordering exactly. The seven panels this document covers sit as follows on a desktop/Ultra Wide viewport (`xl`, 1280px+):

```
┌───────────────────────────────────────────────────────────────────────┐
│  morning_briefing                                      (4x1, full)     │
├────────────────────┬────────────────────┬─────────────────────────────┤
│ business_health     │ cash_flow_status    │ ai_insights_feed            │
│ _score       (1x1)  │               (2x1) │                    (1x2)    │
├────────────────────┴────────────────────┤                             │
│  detected_risks_radar             (2x1)  │  (continues below the fold) │
├─────────────────────────────────────────┴─────────────────────────────┤
│  approval_center_queue                                (4x1, full)      │
└───────────────────────────────────────────────────────────────────────┘
                      [ 💬 Ask AI — docked, bottom-inline-end ]
```

Size tokens (`1x1`, `2x1`, `1x2`, `4x1`) and their column/row-unit definitions are owned by `docs/ai/AI_COMMAND_CENTER.md → Layout`; this document only fixes where the seven panels in scope sit relative to one another and to the rest of the registry (Urgent Actions above Morning Briefing; Revenue/Expense Trends and Today's Tasks fill the remaining rows between Detected Risks and Approval Center, unchanged from the product doc's full desktop grid). Ask AI is not a grid cell — it is a persistent docked affordance rendered in `surface-glass` (`docs/frontend/DESIGN_LANGUAGE.md → Elevation & Surfaces`, one of only two places in the whole product frosted glass is permitted) as a floating trigger at every breakpoint below the `3xl` (1920px) tier, promoted to a persistent inline-end rail only at `3xl`+, per `RESPONSIVE_DESIGN.md`'s reflow table. Every panel shell is a `Card` at `radius-lg` (10px) with a 1px `ink-6` hairline border and `shadow-xs` — never the 20–24px "friendly" radius the pre-`DESIGN_LANGUAGE.md` draft used — and the grid's own gutters follow the `space-lg`/`space-xl` (24px/32px) scale between panels, `space-lg` (24px) as each panel's internal padding.

Two regions carry placement rules stronger than the general grid, because they hold the screen's highest-stakes content. `approval_center_queue`, when it contains any `status: "held"` item, renders its 4px leading-edge border in the `negative` semantic token — a treatment no other queue state on the screen uses — so a Fraud Detection hold is unmistakable at a glance even before its text is read. `detected_risks_radar` is grouped severity-first internally (critical, then high, medium, low) regardless of how the surrounding grid reflows, so severity ordering is never a function of viewport width.

# Components Used

Every visual element on this screen is drawn from `docs/frontend/COMPONENT_LIBRARY.md`'s shared catalogue; nothing here is a one-off.

| Component | Source | Used for |
|---|---|---|
| `AiCardShell` | `components/ai/ai-card-shell.tsx` (`FRONTEND_ARCHITECTURE.md → AI Integration Layer`) | The mandatory colored-border-plus-badge envelope every AI-authored payload on this screen renders through — Morning Briefing's digest, Cash Flow Status' forecast narrative, every Detected Risks card, every AI Insights feed item |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | The normalized 0–1 confidence pill with qualitative band, on Business Health Score's composite, Cash Flow Status' forecast, every risk flag, every insight, every Ask AI answer |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | Any AI Insights card that has graduated to a recommendation shape (`recommended_action` populated) — renders the three-button pattern |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Every row inside the Approval Center queue — `kind="ai_recommendation" \| "bank_transfer" \| "payroll_release" \| "tax_submission"` |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | Business Health Score's composite number and its five expandable weighted components |
| `TrendSparkline` | `components/dashboard/trend-sparkline.tsx` | Cash Flow Status' 30-day trough preview inside its `2x1` card, ahead of the full forecast drill-in |
| `StatusPill` | `components/shared/status-pill.tsx` | Approval Center's per-step and per-request status (`pending`/`held`/`in_review`/`approved`/`rejected`) |
| `DataTable` | `components/shared/data-table.tsx` | The full-page AI Insights feed (`app/(app)/ai/insights/page.tsx`) once a user leaves the dashboard's compact widget for the complete history |
| `Badge` | `components/ui/badge.tsx` | The `"AI"` provenance badge inside `AiCardShell`; `tone="danger"` for the held-approval marker |
| `Tooltip` | `components/ui/tooltip.tsx` | `ConfidenceBadge`'s reasoning disclosure; the Approval Center's disabled-checkbox and disabled-button permission explanations |
| `Popover` | `components/ui/popover.tsx` | Cash Flow Status' per-day forecast driver breakdown on hover/long-press |
| `Sheet` | `components/ui/sheet.tsx` | Ask AI's docked chat drawer below the `3xl` breakpoint; a risk card's "View detail" drill-in |
| `Skeleton` | `components/ui/skeleton.tsx` | Every panel's loading state, shaped to that panel's final layout, never a generic spinner |
| `WidgetErrorBoundary` (`react-error-boundary`) | `components/shared/widget-error-boundary.tsx` | One per panel, isolating a single widget's fetch failure from the other six |
| `useChat` (Vercel AI SDK) | `app/(app)/ai/chat/page.tsx`, reused by the docked Ask AI panel | Token-streaming chat state |

No panel introduces a bespoke card shape, a bespoke status color, or a bespoke confidence indicator — a screen-specific need (the held-approval left-border treatment, for instance) is expressed as a documented variant of an existing component (`ApprovalCard`'s `kind`/held-state styling), never a parallel implementation, per `docs/frontend/DESIGN_LANGUAGE.md → Design Principle 8` ("consistency compounds; novelty costs").

# Data & State

Every panel follows the platform's two-path data rule from `docs/frontend/FRONTEND_ARCHITECTURE.md → Data Layer`: the Server Component `app/(app)/command-center/page.tsx` prefetches each panel's first-paint query server-side and hands it to a client subtree via `dehydrate`/`HydrationBoundary`; from first paint onward, every refetch, mutation, and realtime-triggered invalidation runs through TanStack Query. No panel component calls `fetch` inline, and no panel keeps a private copy of server data in `useState`.

```tsx
// app/(app)/command-center/page.tsx
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { commandCenterKeys } from "@/lib/api/query-keys";
import { Can } from "@/components/auth/can";
import { WidgetErrorBoundary } from "@/components/shared/widget-error-boundary";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";
import { MorningBriefingCard } from "@/components/dashboard/morning-briefing-card";
import { BusinessHealthScoreCard } from "@/components/dashboard/business-health-score-card";
import { CashFlowStatusCard } from "@/components/dashboard/cash-flow-status-card";
import { DetectedRisksCard } from "@/components/dashboard/detected-risks-card";
import { ApprovalCenterCard } from "@/components/dashboard/approval-center-card";
import { AIInsightsCard } from "@/components/dashboard/ai-insights-card";
import { AskAIDock } from "@/components/ai/ask-ai-dock";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function CommandCenterPage() {
  const queryClient = getQueryClient();
  const today = new Date().toISOString().slice(0, 10);

  // Only the single most time-critical panel is awaited before the shell streams —
  // every other panel prefetches in parallel and resolves inside its own Suspense boundary.
  await queryClient.prefetchQuery({
    queryKey: commandCenterKeys.briefing(today),
    queryFn: () => apiServer.get(`/ai/briefings/${today}`),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="grid grid-cols-1 gap-6 p-6 xl:grid-cols-12">
        <div className="xl:col-span-12">
          <WidgetErrorBoundary widgetId="morning_briefing">
            <Suspense fallback={<WidgetSkeleton span="4x1" />}>
              <MorningBriefingCard date={today} />
            </Suspense>
          </WidgetErrorBoundary>
        </div>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="business_health_score">
            <Suspense fallback={<WidgetSkeleton span="1x1" />}>
              <BusinessHealthScoreCard />
            </Suspense>
          </WidgetErrorBoundary>
        </Can>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="cash_flow_status">
            <Suspense fallback={<WidgetSkeleton span="2x1" />}>
              <CashFlowStatusCard />
            </Suspense>
          </WidgetErrorBoundary>
        </Can>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="ai_insights_feed">
            <Suspense fallback={<WidgetSkeleton span="1x2" />}>
              <AIInsightsCard />
            </Suspense>
          </WidgetErrorBoundary>
        </Can>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="detected_risks_radar">
            <Suspense fallback={<WidgetSkeleton span="2x1" />}>
              <DetectedRisksCard />
            </Suspense>
          </WidgetErrorBoundary>
        </Can>
        <Can
          permission="reports.read"
          fallback={<Can permission="ai.approve"><ApprovalCenterCard /></Can>}
        >
          <WidgetErrorBoundary widgetId="approval_center_queue">
            <Suspense fallback={<WidgetSkeleton span="4x1" />}>
              <ApprovalCenterCard />
            </Suspense>
          </WidgetErrorBoundary>
        </Can>
      </div>
      <Can permission="ai.chat"><AskAIDock /></Can>
    </HydrationBoundary>
  );
}
```

Query keys are produced by one typed factory, extending `docs/frontend/FRONTEND_ARCHITECTURE.md → Query key architecture`'s pattern:

```ts
// lib/api/query-keys.ts (Command Center additions)
export const commandCenterKeys = {
  all: ["command-center"] as const,
  briefing: (date: string) => [...commandCenterKeys.all, "briefing", date] as const,
  healthScore: () => [...commandCenterKeys.all, "health-score"] as const,
  cashFlow: () => [...commandCenterKeys.all, "cash-flow"] as const,
  insights: (since?: string) => [...commandCenterKeys.all, "insights", since ?? "latest"] as const,
  risks: (category: string = "all") => [...commandCenterKeys.all, "risks", category] as const,
};
// approvalKeys is reused unchanged from FRONTEND_ARCHITECTURE.md — the Approval Center
// panel and the full-page /approvals route share one cache under approvalKeys.all.
```

Each panel's `staleTime` follows `docs/frontend/FRONTEND_ARCHITECTURE.md → Cache tuning by data class` by data character, not by panel identity — Cash Flow Status' live balance half is a "Live/derived figure" (`staleTime: 0`, corrected by realtime rather than polling) while its forecast half and Morning Briefing are "AI feeds" (`staleTime: 10_000`, `refetchOnWindowFocus: true`, since a returning user should see what accumulated overnight):

| Panel | Endpoint(s) | `staleTime` | Realtime channel |
|---|---|---|---|
| Morning Briefing | `GET /api/v1/ai/briefings/{date}` | 10s, refetch-on-focus | `private-company.{id}.ai-jobs` (regeneration status only; content itself does not change intraday) |
| Business Health Score | `GET /api/v1/ai/health-score` | 10s, refetch-on-focus | `private-company.{id}.dashboard.{dashboard_id}` |
| Cash Flow Status | `GET /api/v1/banking/accounts`, `GET /api/v1/ai/cash-flow/forecast` | 0 (balance) / 10s (forecast) | `private-company.{id}.dashboard.{dashboard_id}` (patches balance on `bank.synced`/`payment.received`) |
| Detected Risks | `GET /api/v1/ai/risks?category=all` | 10s, refetch-on-focus | `private-company.{id}.dashboard.{dashboard_id}` (a new `ai_risk_flags` row pushes an insert) |
| Approval Center | `GET /api/v1/approvals` | 10s, refetch-on-focus | `private-company.{id}.approvals` |
| AI Insights | `GET /api/v1/ai/insights?since={timestamp}` | 10s, refetch-on-focus | `private-company.{id}.dashboard.{dashboard_id}` |
| Ask AI | `POST /api/v1/ai/chat` (SSE stream, not a cached query) | n/a | `private-company.{id}.ai-jobs` (typing/thinking status) |

Each panel is its own `use<Widget>Query` hook, mirroring `docs/frontend/FRONTEND_ARCHITECTURE.md`'s `useJournalEntries`/`useLedgerEntries` pattern exactly:

```ts
// lib/api/hooks/use-cash-flow-status.ts
export function useCashFlowStatus() {
  const balances = useQuery({
    queryKey: commandCenterKeys.cashFlow(),
    queryFn: () => api.get<BankAccountsResponse>("/banking/accounts"),
    staleTime: 0,
  });
  const forecast = useQuery({
    queryKey: [...commandCenterKeys.cashFlow(), "forecast"],
    queryFn: () => api.get<CashFlowForecast>("/ai/cash-flow/forecast"),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
  return { balances, forecast };
}
```

Which AI agent feeds which panel is not a frontend decision — it is read verbatim from `docs/ai/AI_COMMAND_CENTER.md → Agent coverage map` and rendered, never inferred:

| Panel | Agent(s) | Panel's own field carrying it |
|---|---|---|
| Morning Briefing | `CEO_ASSISTANT` | `agent_code` on the Command Card envelope |
| Business Health Score | `CFO_AGENT`, `REPORTING_AGENT` | `data.components[].driver` cites the sub-score's own agent implicitly via `sources` |
| Cash Flow Status | `TREASURY_MANAGER` (live position, thresholds), `FORECAST_AGENT` (projection) | Two `agent_code`s surfaced side by side — the live balance renders with `ai_generated: false`, the forecast half with `agent_code: "FORECAST_AGENT"` |
| Detected Risks | Every risk-originating agent (`FRAUD_DETECTION`, `COMPLIANCE_AGENT`, `AUDITOR`, `TREASURY_MANAGER`, `INVENTORY_MANAGER`, `PAYROLL_MANAGER`) | `source_agent_code` per `ai_risk_flags` row |
| Approval Center | `APPROVAL_ASSISTANT` (routes every request), `FRAUD_DETECTION` (holds only) | `source_agent_code` on `ai_approval_requests` |
| AI Insights | All fifteen agents, de-duplicated by `REPORTING_AGENT` | `agent_code` per insight row |
| Ask AI | Orchestrated — the FastAPI layer selects one or two specialists per question | `agent_code` on the returned message |

# Interactions & Flows

**Morning Briefing.** A single scrolling card, five-part digest (Yesterday's Close, Top Changes, Top Risks, Top Recommended Actions, Today's Calendar). Tapping any line calls `router.push()` to the panel that owns it with a `?highlight={sourceId}` query param the target panel reads to auto-expand that exact card — clicking the cash-flow line does not just navigate to Cash Flow Status, it opens it already scrolled and expanded, matching the product doc's Interaction spec verbatim. A speaker icon streams `audio_url` through the browser's `<audio>` element; marking the briefing read (`PATCH /api/v1/ai/briefings/{id}` → `read_at`/`read_by`) only flips that card's own badge and never dismisses anything it links to elsewhere on the screen.

**Business Health Score.** `KpiTile` renders the composite 0–100 number plus trend delta; tapping it expands (Framer Motion height animation, `motion.base`, 200ms) a breakdown of the five weighted components. Each component row is itself a link — Working Capital Discipline deep-links into Cash Flow Status' AR/AP aging view, Risk Exposure into Detected Risks — via the same `?highlight=` convention. A "Why did this change?" affordance is not a pre-written string; it calls `POST /api/v1/ai/nlq` with the score's own `decision_id` as context and renders the answer inline via the Natural Language Queries contract (`docs/ai/AI_COMMAND_CENTER.md → Natural Language Queries`).

**Cash Flow Status.** The `2x1` card shows the live reconciled balance plus a compact 30-day `TrendSparkline`; a segmented control (`30 / 60 / 90`) re-queries the forecast endpoint with a `horizon` param. Hovering (desktop, via `Popover`) or long-pressing (touch) any point on the sparkline surfaces that day's specific drivers without leaving the card. A **Simulate a fix** button (rendered only when `status` is `warning`/`critical`) is a plain `<Link href="/ai/simulate?scenario={id}">` — it never opens a modal, because the Simulation Panel is a full sandboxed workspace, not a quick action.

**Detected Risks.** Grouped by severity (critical first), each `ai_risk_flags` row renders inside `AiCardShell` with **Acknowledge**, **Resolve** (opens a `Dialog` requiring `resolution_note`), and **Dismiss as false positive** (requires a reason, the same `DismissDialog` pattern `AIProposalPanel` uses). Per `docs/ai/AI_COMMAND_CENTER.md`'s stated rule, a `critical`-severity flag's dismiss action is only enabled when the caller holds `ai.approve` — the client checks this via `usePermission("ai.approve")` before even opening the dialog, and the mutation's own `403` remains the authoritative backstop if a stale permission snapshot let the click through.

**Approval Center.** Each `ai_approval_requests` row renders as an `ApprovalCard`, grouped by `sla_due_at` urgency (breaching or near-breaching first). A multi-step request renders its `ai_approval_steps` as a `<Stepper>`; only the step that is `pending` **and** assigned to the current user's role is interactive — a CFO's step-2 Approve button is disabled, with an explanatory tooltip, until the Senior Accountant's step 1 resolves. `status: "held"` rows (Fraud Detection holds) render with the `negative`-token left border and their `hold_reason` inline, ahead of ordinary `pending` items regardless of `sla_due_at`. Bulk-approve is offered only across a same-`subject_type`, below-threshold, non-`held` selection; any row whose `required_permission` is one of `bank.transfer`/`payroll.approve`/`tax.submit` renders its selection checkbox disabled with a tooltip explanation, never silently omitted.

**AI Insights.** An infinite-scroll feed (`useInfiniteQuery`, cursor mode) of atomic observations, newest first, filterable by agent and date range. Each card supports **Explain more** (opens Ask AI pre-seeded with the insight's own `sources` as context) and **Not useful** (a lightweight `POST /api/v1/ai/insights/{id}/feedback`, optimistically removing the card with an "Undo" toast for five seconds before the mutation commits). An insight that has graduated to carrying `recommended_action` renders through `AIProposalPanel` instead of the plain read-only card, per the strict, field-presence-driven split `docs/ai/AI_COMMAND_CENTER.md → AI Insights` defines.

**Ask AI.** A docked trigger, present on every panel, opens a `Sheet` (below `3xl`) or the persistent rail (`3xl`+) pre-scoped to whatever panel it was opened from — opening it from inside Cash Flow Status seeds the conversation with that panel's own `sources` as context, exactly as `docs/ai/AI_COMMAND_CENTER.md → Ask AI` specifies. Suggested follow-up chips are generated from the current screen's context and rendered above the composer; any answer carrying an implied action renders the identical three-button set (`Do it` / `Send for approval` / `Dismiss`) as `AIProposalPanel`, so a conversational answer never needs a second, competing interaction pattern.

# AI Integration

Every one of the seven panels renders AI-authored content exclusively through the contract `docs/frontend/FRONTEND_ARCHITECTURE.md → AI Integration Layer` fixes platform-wide: a `confidence_score`, a `reasoning`, and `sources` on every decision-bearing payload, wrapped in `AiCardShell`, with `ConfidenceBadge` normalizing the Command Card's `0–100` scale to the component library's canonical `0–1` range via `normalizeConfidence(score, "percentage")`. No panel on this screen computes its own summary of a confidence score, softens a low number cosmetically, or renders a bare percentage without an attached reasoning affordance.

```tsx
// components/dashboard/detected-risks-card.tsx ("use client", excerpt)
export function DetectedRisksCard() {
  const { data, isPending } = useDetectedRisks();
  if (isPending) return <WidgetSkeleton span="2x1" />;
  return (
    <Card padding="lg" aria-labelledby="risks-heading">
      <VisuallyHidden asChild><h2 id="risks-heading">{t("dashboard.detectedRisks")}</h2></VisuallyHidden>
      <RiskSeverityGroups risks={data.risks}>
        {(risk) => (
          <AiCardShell key={risk.id} decision={risk}>
            <p className="text-body text-ink-950">{risk.title}</p>
            {risk.financial_exposure && (
              <Amount value={Number(risk.financial_exposure)} currency="KWD" />
            )}
            <RiskActions risk={risk} />
          </AiCardShell>
        )}
      </RiskSeverityGroups>
    </Card>
  );
}
```

The `financial_exposure` figure above deliberately renders in plain tabular ink, not a forced `negative`-colored numeral — per `docs/frontend/DESIGN_LANGUAGE.md`'s debit/credit rule, a raw magnitude is not the same thing as a signed, already-net metric (a variance, a delta), and severity here is already carried unambiguously by `AiCardShell`'s own status border and badge. Washing the amount itself in red on top of that would be decoration, not information.

The **three-button pattern** governs every actionable surface on this screen identically: `can_execute_directly` is a field the API computes (evaluating the item's `autonomy` against the company's `ai_automation_rules` threshold) and sends — never a value the client derives from raw automation-rule data, per `FRONTEND_ARCHITECTURE.md`'s restated Principle 1. When `can_execute_directly` is `false`, "Do it" does not render disabled; it does not render. This is what makes the platform's promise — that a sensitive action has no client-side path to execute itself — structurally true on the Command Center rather than a UI convention:

```tsx
// components/ai/recommendation-card.tsx usage inside AIInsightsCard
<RecommendationCard decision={insight} />
// insight.can_execute_directly === false for anything touching bank.transfer,
// payroll.approve, tax.submit, or a confidence below the company's configured floor —
// the button set below reflects exactly that, with no local override possible.
```

**Approval affordances, specifically.** `ApprovalCard`'s `confidence` prop is populated only for AI-originated requests (a human-submitted journal entry pending a peer's sign-off has no confidence to show); `requestedBy` is correspondingly omitted for pure AI proposals, since attribution there belongs to `agent_code`, not a human name. Approve calls `POST /api/v1/approvals/{id}/approve` with a client-generated `Idempotency-Key`, optimistically flips that step to `approved` in the TanStack Query cache (`docs/frontend/FRONTEND_ARCHITECTURE.md → Optimistic updates`, the exact `useApproveRequest` hook), and rolls back on any `4xx`/`5xx`. Reject requires a non-empty reason before `Confirm reject` un-disables, mapped straight to `rejected_reason` on the underlying decision row. Neither action renders with `onMutate` omitted the way a genuinely money-moving mutation (a bank transfer's own execution, once approved) would — approving a *request* is reversible-adjacent (a wrong approval can still be caught at the next step or reversed procedurally), while the transfer it eventually authorizes is not, and the two are treated with deliberately different optimism per Principle 10.

**Ask AI's streaming surface** reuses `app/(app)/ai/chat/page.tsx`'s `useChat` wiring verbatim rather than a second implementation docked in the shell — the SSE proxy at `app/api/ai/chat/route.ts` forwards the httpOnly-cookie bearer token server-side exactly as `FRONTEND_ARCHITECTURE.md → Streaming AI chat` specifies, and the docked variant differs only in its container (`Sheet`/rail instead of a full page) and its seed context (the panel it was opened from). A response that resolves to a chat-shaped answer with no implied action renders as plain prose through `AiCardShell`, with any cited monetary figure passing through the same bidi-isolated `AmountCell`/`Amount` formatting every table on the platform uses — an amount the assistant states in an Arabic conversation is exactly as unambiguous as one in a ledger.

# States

Every panel ships four states as a matter of house style, never left to fall through to a bare blank region:

| Panel | Skeleton | Empty | Error |
|---|---|---|---|
| Morning Briefing | Full-width shimmer block matching the five-section shape | Never truly empty (the digest always has at least a "Yesterday's Close" line); a brand-new company renders "No activity yet — connect a bank account or post your first entry" with a setup CTA, distinct from a loading skeleton | Inline "Couldn't load today's briefing — retry" card; the rest of the grid renders normally |
| Business Health Score | `KpiTile`'s own `loading` prop (`Skeleton` in place of value/delta) | N/A — the score is always computable once any ledger data exists; a company with zero posted entries instead shows a "Not enough data yet" variant, not a `0` score | Same tile shows a muted dash and a retry affordance; never a partial or misleading score |
| Cash Flow Status | Sparkline-shaped shimmer plus three placeholder balance rows | A company with no `bank_accounts` connected renders "Connect a bank account to see your cash position" with a CTA into Banking setup | Balance and forecast fail independently — a Forecast Agent timeout shows the live balance normally with only the forecast half degraded |
| Detected Risks | Three placeholder severity-grouped rows | "No open risks" with a small line-drawn check-mark glyph (`ink-7`, per `DESIGN_LANGUAGE.md`'s empty-state stance) — a genuinely reassuring empty state, not a generic "no results" | Inline retry; a partial failure never blocks categories that already resolved, since `category=all` is one call, not a fan-out |
| Approval Center | Full-width shimmer, three row-shaped placeholders | "Nothing waiting on you" — explicitly reassuring copy, since an empty queue is good news here, unlike an empty ledger | Inline retry; a stale queue is never silently trusted — see Edge Cases for the reconnect-invalidation rule |
| AI Insights | Five placeholder feed rows | "No new insights since your last visit" (returning user) vs. "Insights will appear here once your first entries are posted" (new company) — two distinct empty copies for two distinct causes | Failed page load shows retry; a failed "load more" leaves existing rows intact with an inline retry at the list's end |
| Ask AI | Three pulsing dots (`docs/frontend/DESIGN_LANGUAGE.md`'s calmer, slower AI-thinking pattern, never a typical chat-app indicator) while awaiting the first token | A closed dock shows no state; an opened dock with no history shows contextual suggested-question chips only | A failed send renders the user's own message with a retry affordance, never silently drops it |

Every skeleton reuses the loaded state's exact grid/`col-span` classes (`docs/frontend/RESPONSIVE_DESIGN.md → Loading and skeleton reflow`) so no panel pops or reflows between its loading and loaded shape. Every error state is a `WidgetErrorBoundary` scoped to that one panel — a Forecast Agent timeout inside Cash Flow Status never takes down Morning Briefing or Detected Risks rendered beside it, per `FRONTEND_ARCHITECTURE.md → Boundaries at three granularities`.

# Responsive Behavior

Below the `xl` breakpoint (1280px), the seven-panel layout collapses to the platform's fixed reflow order rather than a naive shrink of the same grid, per `docs/frontend/RESPONSIVE_DESIGN.md → Dashboard Reflow`:

| Panel | Desktop/Ultra Wide | Tablet (`md`–`lg`) | Mobile (`base`–`sm`) |
|---|---|---|---|
| Morning Briefing | Full-width top row | Full-width, unchanged | Second (after Urgent Actions/held approvals), condensed to headline + 3 bullets |
| Business Health Score | 1 of the KPI-row tiles | Pairs two-up | Third, single tile per row, breakdown behind a tap |
| Cash Flow Status | `2x1`, chart + narrative | Full-width | Third/fourth, sparkline retained, forecast detail behind a tap |
| Detected Risks | `2x1`, mid-section | Full-width | Sixth, severity grouping unchanged |
| Approval Center | `4x1`, own row | Full-width | **Second**, above the KPI row, one `ApprovalCard` at a time, swipeable |
| AI Insights | `1x2`, right column | Pairs with Recommendations | Fifth, single column, priority-sorted |
| Ask AI | Persistent rail (`3xl`+ only) | Floating action button | Floating action button, opens a full-screen sheet |

Below `md` (768px), any panel's internal table-shaped content (Business Health Score's component breakdown, a risk's source list) follows `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 1`'s cards-not-tables rule rather than forcing horizontal scroll on a breakdown that only needs two fields per row on a phone. Realtime subscriptions are scoped by viewport, not just by panel visibility: desktop and Ultra Wide subscribe to every channel the seven visible panels need simultaneously, while mobile gates each panel's Echo subscription behind an `IntersectionObserver` so a panel scrolled out of view unsubscribes, per `RESPONSIVE_DESIGN.md → Realtime subscription scoping` — meaningful for battery and radio usage on the single-column mobile feed, irrelevant on a desktop where all seven panels are typically on-screen at once.

Touch targets follow the platform-wide 44px minimum (`RESPONSIVE_DESIGN.md → Touch Targets & Gestures`) with an 8px minimum gap between adjacent controls — material on this screen specifically because Approval Center's Approve/Reject sit side by side, and a mis-tap here carries real financial consequences unlike a marketing-site mis-tap. A swipeable `ApprovalCard` on mobile still requires the same explicit confirmation dialog as a desktop click — a swipe changes the entry point, never the confirmation step. Approving a sensitive action (a bank transfer or payroll release surfaced through this screen's Approval Center) from mobile additionally requires a biometric re-confirmation (Face ID/fingerprint) immediately before submission, layered on top of, never instead of, the ordinary permission check (`docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`).

# RTL & Localization

The whole screen is authored and reviewed in Arabic before being considered done, not mirrored at the end (`docs/frontend/DESIGN_LANGUAGE.md → Design Principle 6`). `dir="rtl"` is set once on `<html>` by the root layout from the active locale; no panel component toggles direction itself. Every layout, gap, and padding value across the seven panels uses Tailwind's logical utilities (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively — `ml-*`/`mr-*`/`text-left`/`text-right` are banned in this screen's component source by the same ESLint restriction `docs/frontend/COMPONENT_LIBRARY.md → Theming & RTL` enforces platform-wide, which is what lets the Cash Flow Status sparkline's padding, the Approval Center `Stepper`'s progress direction, and the Ask AI dock's slide-in edge (inline-end in LTR, inline-start in RTL) all mirror with zero conditional code.

Three things never mirror, matching the platform's numeral and icon rules exactly:

- **Every monetary figure, percentage, confidence score, and date** on this screen renders inside a `dir="ltr"` span — the Business Health Score's `78 / 100`, Cash Flow Status' `KWD 42,318.600`, and every `ConfidenceBadge`'s `93%` read left-to-right even inside a fully Arabic sentence, via the same `AmountCell`/`Amount` primitives and `numberingSystem: "latn"` forcing every other screen uses. QAYD never switches to Eastern Arabic-Indic digits for financial figures.
- **Directional icons flip; meaning-bearing icons don't.** The Morning Briefing's "read more" chevron and the Approval Center `Stepper`'s progress arrow mirror via `rtl:rotate-180`; a risk severity dot, a checkmark, or the AI provenance mark never do.
- **AI-authored prose is never machine-translated by the frontend.** Arabic answers from Ask AI, Arabic briefing text, and Arabic risk narratives arrive already localized from the API per its content-negotiation contract (`Accept-Language`); the client's own localization responsibility on this screen is limited to chrome — button labels, the composer placeholder, empty-state copy, tooltips — never the model's own words, exactly as `FRONTEND_ARCHITECTURE.md → Streaming AI chat` specifies.

Arabic microcopy for this screen is authored directly by a fluent professional-register writer, not translated post hoc, following `DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief:

| Context | English | Arabic |
|---|---|---|
| Panel title | Morning Briefing | الموجز الصباحي |
| Panel title | Cash Flow Status | الوضع النقدي |
| Panel title | Detected Risks | المخاطر المكتشفة |
| Panel title | Approval Center | مركز الاعتماد |
| AI proposal label | Suggested by the CFO Agent — 93% confidence | مقترح من وكيل المدير المالي — بثقة 93% |
| Approval action | Approve | اعتماد |
| Approval action | Reject | رفض |
| Held-payment banner | Held — vendor bank details changed 2 hours ago | معلّق — تم تغيير بيانات حساب المورد البنكي قبل ساعتين |
| Ask AI placeholder | Ask anything about your business | اسأل أي شيء عن أعمالك |
| Empty approvals | Nothing waiting on you | لا يوجد شيء بانتظارك |

Bilingual master-data fields (`name_en`/`name_ar` on any customer, vendor, or account cited by a Detected Risks card or an Ask AI answer) are picked by the client at render time based on `useLocale()`, since the API always returns both regardless of `Accept-Language` — a user reading the Arabic Command Center can still type an English vendor name into Ask AI's composer and get a correctly matched answer.

# Dark Mode

Dark mode is system-aware by default, user-overridable, and persisted, driven by the platform's `class`/`data-theme="dark"` strategy (`docs/frontend/DARK_MODE.md → Strategy`) — no panel on this screen implements its own theme logic or a `dark:` Tailwind variant referencing a raw color. Every token this screen's components resolve — the `ink` scale, `accent`/`accent-subtle`, `positive`/`negative`/`warning` — carries an explicit dark-mode value already defined in `docs/frontend/DESIGN_LANGUAGE.md → Dark mode strategy`; backgrounds stay warm-neutral rather than shifting to a cool blue-black, and elevation gets *lighter*, not darker, so an `AiCardShell` sits visibly above the canvas behind it via its border and a marginally lighter fill, never a heavier shadow (shadows are kept quiet at roughly half light-mode opacity in dark mode, per `DARK_MODE.md → Surfaces & Elevation In Dark`).

Two rules specific to this screen's AI-heavy content:

- **The accent stays reserved for AI provenance and the one primary action per card — never status.** A `critical` Detected Risks flag or a `held` Approval Center row is colored with the `negative` semantic token, never the accent, even though both are AI-originated; conflating "the AI touched this" with "this is urgent" would collapse two independently useful signals into one, per `DESIGN_LANGUAGE.md → Color & Ink`'s accent-is-AI's-signature rule and `DARK_MODE.md`'s explicit accent/status-collision resolution.
- **Confidence indicators are re-tuned per theme, not linearly brightened.** `ConfidenceBadge`'s dark-mode accent value and any confidence-ring treatment on a KPI tile use their own calibrated dark value rather than a naive "brighten the light-mode hex," so a 94%-confidence fraud hold reads as urgent without the harsh over-saturation a mechanical brightness flip would produce (`docs/frontend/DARK_MODE.md → Composed: an AI Command Card, rendered in dark mode` works through exactly this card shape).

`TrendSparkline`'s stroke color and any chart inside Cash Flow Status or Business Health Score's breakdown resolve through `lib/tokens.ts`'s JS-readable token export rather than a CSS variable read at render time, because SVG `stroke`/`fill` attributes cannot consume a Tailwind class the way a `div`'s background can — this is the same mechanism `docs/frontend/DARK_MODE.md → Charts & Data Viz In Dark` requires platform-wide, applied here to the two chart-bearing panels in scope. No image or logo on this screen is ever CSS-`filter: invert()`-flipped between themes; the QAYD mark and any agent glyph ship as theme-aware assets or inherit color via `currentColor` on an inline SVG.

# Accessibility

The Command Center targets the same WCAG 2.2 AA floor as every other QAYD screen (`docs/frontend/ACCESSIBILITY.md → Standards & Targets`), with three areas carrying extra weight given how AI- and realtime-dense this specific screen is.

**Landmarks and headings.** The page renders inside the shared `<main id="main-content" tabIndex={-1}>` region with exactly one `<h1>` ("Command Center" / لوحة القيادة الذكية); every panel that has no visible heading of its own (a `KpiTile`, a compact card) still carries a real, `VisuallyHidden` `<h2>` via `aria-labelledby`, so a screen-reader user's landmark and heading list enumerates seven distinct regions rather than one undifferentiated page.

**Live regions, calibrated to avoid noise.** New rows landing in AI Insights or a fresh Detected Risks flag announce via `aria-live="polite"` — never `"assertive"` — matching `docs/frontend/ACCESSIBILITY.md`'s three-tier model and its explicit "auto-detect, never alarming" posture. Ask AI's streaming response sets `role="status"`/`aria-busy="true"` once when a reply begins ("answering…") and announces the full text once more on completion — it never narrates token-by-token, which would make the dock unusable by voice. A Reverb push that updates the Approval Center queue while a user is mid-read never yanks focus or splices a row into what a screen reader has already indexed; it surfaces as a polite, dismissible "3 new approvals — Refresh" banner instead, per `ACCESSIBILITY.md → Live regions`, rule 2.

**Every AI-authored control explains itself.** `ConfidenceBadge`'s percentage and qualitative label are both real text nodes, never conveyed through a bare progress-bar `width`; every Approve/Reject button is a real `<button>` with `aria-describedby` pointing at the card's reasoning, so the "why" is available before the action is taken, not just visually adjacent to it. A `Do it` button withheld by `can_execute_directly: false` is simply absent (matching the platform rule that a gated action has no client path at all, accessible or otherwise); a button disabled for a *permission* reason (a Sales Employee viewing Approval Center read-only) always carries a `Tooltip`-linked `aria-describedby` naming the missing permission, per `ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves` — and this is kept textually distinct from a business-rule disablement, of which this screen has none, since nothing here is a form with a balance check.

Keyboard access covers the full screen without a mouse: `Tab` order follows the fixed DOM source order (Urgent/held items → Morning Briefing → KPI row → Insights/Risks → Approval Center → Ask AI trigger), every card's expand/collapse (Business Health Score's breakdown, an insight's "Explain more") is a real, `aria-expanded`-carrying button, and `⌘K` opens the Command Palette from anywhere on this screen with an "Ask AI" quick action that hands off free text to the dock rather than a second, competing input (`docs/frontend/NAVIGATION_SYSTEM.md → AI actions are handoffs, never executions`). `TrendSparkline` instances on this screen carry a mandatory `aria-label` (`"Cash position, last 30 days"`) and are the one place `aria-hidden` is acceptable, and only because the adjacent delta text already states the same trend in words.

# Performance

The Command Center is held to a stricter first-load budget than an ordinary list/detail screen precisely because it is the first thing every session sees: the dashboard shell (excluding each panel's individually split chunk) is budgeted under 180KB gzipped first-load JS, enforced in CI against `performance-budgets.json` (`docs/frontend/FRONTEND_ARCHITECTURE.md → Bundle budgets`), and any chart-bearing panel (Cash Flow Status' sparkline, Business Health Score's breakdown) code-splits its charting dependency via `next/dynamic` rather than shipping it in the shared shell bundle every non-chart panel would otherwise pay for too.

**RSC and streaming.** `app/(app)/command-center/page.tsx` is a Server Component that prefetches only the single most time-critical query (today's Morning Briefing) before the shell paints; the remaining six panels each resolve inside their own `<Suspense>` boundary, so a slow `FORECAST_AGENT` computation inside Cash Flow Status never blocks Detected Risks or Approval Center from appearing, per `docs/frontend/FRONTEND_ARCHITECTURE.md → Streaming with Suspense`. Because every byte on this screen is scoped by `X-Company-Id`, the route sets `export const dynamic = "force-dynamic"` and `fetchCache = "default-no-store"` unconditionally — nothing on the Command Center is a candidate for Next's Data Cache or Partial Prerendering, matching the platform's absolute tenant-isolation rule for cached responses.

**Query layer.** Each panel's hook sets its own `staleTime` by data class rather than accepting the global default (see Data & State, above), so Cash Flow Status' live balance is always considered stale (corrected by Reverb, not polling) while Morning Briefing's already-generated digest tolerates a 10-second window before a focus-triggered refetch. `placeholderData: keepPreviousData` on the AI Insights feed's cursor pagination means "load more" never flashes existing rows to empty while the next page resolves. Realtime invalidation (`FRONTEND_ARCHITECTURE.md → Invalidate, or patch`) is the default strategy for every panel on this screen except Cash Flow Status' live balance, which patches directly on a `bank.synced`/`payment.received` event to avoid a refetch round-trip on a figure that can change several times a minute, still corrected by an ordinary invalidation on reconnect.

**Realtime cost control.** All seven panels subscribe through one shared `RealtimeProvider` connection (mounted once in the `(app)` shell, not per-panel), so opening the Command Center never opens seven separate WebSocket connections; mobile additionally gates each panel's channel binding behind visibility (see Responsive Behavior, above) to control battery and radio cost on a long single-column scroll. Connection state itself renders as a small, static topbar dot, never a banner, per the platform's "never alarming" motion principle — and on reconnect, every realtime-fed query key on this screen is invalidated exactly once as a batch, since a WebSocket has no replay and a stale Approval Center silently trusted after a drop would be a correctness bug, not a cosmetic one.

**Web Vitals.** LCP on this route is measured against the Morning Briefing card's paint (the screen's actual "meaningful content," not the shell), CLS is held near zero by every skeleton sharing its loaded state's exact grid classes (see States, above), and INP is watched specifically on the Approval Center's Approve/Reject buttons and the Ask AI composer, since those are the two highest-stakes interactive surfaces on the screen — a regression here is tagged and monitored against a company-size-band baseline exactly as `FRONTEND_ARCHITECTURE.md → Web Vitals` specifies platform-wide, since a holding company's Approval Center queue and a brand-new company's empty one have legitimately different tail latencies.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A user holds permission for zero panels (an extremely narrow custom role) | The grid renders Morning Briefing (readable by any `reports.read` holder) and Ask AI only; it never renders a fully blank page — see Route & Access for the per-panel permission table this falls out of. |
| An approval's target record changes between proposal and the user's click | The Approval Center re-fetches the live target immediately before rendering the Approve action and diffs it against the proposal's stored payload; on divergence, Approve is replaced with "Review changed data" rather than silently approving stale intent, mirroring the API's own conflict posture (`FRONTEND_ARCHITECTURE.md → Edge Cases`). |
| Fraud Detection holds a payment while its Approval Center card is already open on screen | The Reverb push patches that row's `status` to `held` in place (not a full list invalidation, to avoid disrupting scroll position) and the left-border/badge treatment updates live, without requiring the user to refresh or re-open the card. |
| Two browser tabs; one approves step 1 of a chain while the other is mid-read of the same request | The idle tab's next action against that request fails with a conflict response (already advanced past its step) and the UI re-fetches the request fresh rather than trusting its own stale local state, consistent with `FRONTEND_ARCHITECTURE.md`'s general conflict-handling posture. |
| WebSocket disconnected for an extended period, then reconnects | Every realtime-fed query key on this screen (`commandCenterKeys.*`, `approvalKeys.all`) is invalidated once as a batch on reconnect — a missed Fraud Detection hold during the outage must surface immediately once connectivity returns, never wait for a manual refresh. |
| Ask AI is asked a question the orchestrator cannot answer confidently from any available tool | The agent states that explicitly ("I don't have enough posted data to answer that confidently yet") rather than guessing; this renders as plain prose with no confidence badge rather than a badge showing an artificially low number, since the failure here is "no answer," not "a low-confidence answer." |
| A brand-new company, first login, empty ledger | Every panel renders its dedicated "not yet configured" empty state with a setup CTA (connect a bank account, post a first entry) — never the same empty state a filtered-to-zero search would produce elsewhere in the product, so a new customer is never left wondering whether their data failed to load. |
| A company switch happens while the Command Center is open | `queryClient.clear()` and `router.refresh()` fire together (`FRONTEND_ARCHITECTURE.md → Company switching`); every one of the seven panels re-mounts and re-fetches under the new `X-Company-Id` rather than any panel momentarily showing the previous company's figures. |
| Printing or exporting the screen | Not supported as a "print this dashboard" action — the Command Center's AI badges, confidence scores, and chrome have no meaning on paper; a user who needs a shareable artifact is directed to the relevant financial statement or report export instead, which does carry its own dedicated print stylesheet. |

# End of Document
