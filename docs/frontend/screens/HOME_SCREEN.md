# Home Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: HOME_SCREEN
---

# Purpose

This document is the frontend implementation specification for QAYD's Home screen — the literal `app/(app)` index route, the first pixel a browser paints inside the authenticated shell before any specific module URL has been chosen. It is the concrete route realization the product has been missing a name for: `docs/frontend/DASHBOARD.md` specifies, in full depth, the conventional numbers-first overview that renders at `/dashboard`; `docs/frontend/AI_COMMAND_CENTER.md` (and its product-layer counterpart `docs/ai/AI_COMMAND_CENTER.md`) specifies, in equal depth, the seven-panel AI-forward workforce briefing that renders at `/command-center`. Neither document — nor `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own App Router tree — actually names what sits at the bare root, `app/(app)/page.tsx`, the one URL every authenticated session necessarily visits before it visits either of those two by name. This document is that missing leaf. It does not re-derive a single KPI tile, chart, or Command Card — every figure and every AI panel Home can show is owned, specified, and endpoint-documented by Dashboard or by the Command Center, and Home composes their existing components without modification. What Home owns, and what did not exist anywhere in the platform before this document, is the layer that sits in front of both: the decision of *which* of the two registers a specific person sees the instant they arrive, how that default is set the first time (an explicit choice always outranks a role-based guess, which always outranks nothing at all), a role-personalized worklist that belongs to neither Dashboard nor the Command Center alone, and the always-visible affordance to flip between the two without leaving the page.

**A note on this route, and on five documents this one narrows a single, explicit case in — never their broader statements.** `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own App Router tree jumps directly from `(app)/layout.tsx`'s shell to `dashboard/page.tsx`; it never lists a bare `page.tsx` leaf under `(app)/` at all, an omission rather than a decision. `docs/frontend/NAVIGATION_SYSTEM.md`'s module map names the Sidebar's first slot "Dashboard," points it at `/dashboard`, and states outright that "Dashboard leads because it is home" — written before Dashboard and the Command Center were two separate screens, a fact `docs/frontend/DASHBOARD.md` itself already flagged as stale on the narrower point of the redirect. `docs/frontend/AI_COMMAND_CENTER.md` states the Command Center "is the application's default post-login destination" and encodes a permanent `/dashboard → /command-center` redirect in `next.config.ts`; `docs/frontend/DASHBOARD.md` already superseded that redirect on the one point that `/dashboard` itself is non-redirecting. `docs/frontend/screens/LOGIN_SCREEN.md`'s `resolvePostAuthDestination` currently names `/command-center` as its default (non-deep-link) case. `docs/frontend/ONBOARDING.md`'s Join-branch and its "Save & exit" affordance both currently land a user on `/dashboard`. Every one of those five statements about *which named screen* is reachable, what it contains, and how its own URL behaves is left completely intact by this document — `/dashboard` is exactly what `DASHBOARD.md` says it is, `/command-center` is exactly what `AI_COMMAND_CENTER.md` says it is, and nothing about MFA, company selection, or session cookies in `LOGIN_SCREEN.md` changes. What this document narrows, in each of the five places above and only there, is the single default-case *destination value* — from `/dashboard` or `/command-center` to `/` — because a destination that is itself a considered, personalized choice between the two is a better answer to "where does a freshly authenticated person land" than a value already permanently fixed for the whole company regardless of who is signing in. `docs/frontend/NAVIGATION_SYSTEM.md`'s Sidebar module map position 1 and its mobile bottom-tab-bar first slot are amended the same narrow way: label becomes **Home**, `href` becomes `/`; position, icon (`LayoutDashboard`), and "no gating permission" are unchanged.

Three constraints inherited directly from `FRONTEND_ARCHITECTURE.md` and restated by both of Home's sibling screens bind this document identically:

1. **The frontend computes nothing new.** Every figure, chart, risk flag, and task on this screen is a value Laravel or the FastAPI AI layer already validated and persisted; Home's own logic is limited to resolving which of two already-specified compositions to render, fetching a small worklist through an endpoint the Command Center's own product document already defines, and routing a click.
2. **AI is visible, labeled, and never silent, and it is never rendered by a code path Home wrote itself.** Wherever Home shows AI-authored content — the condensed AI Summary Rail in Classic mode, the full Command Card set in AI mode, a task row whose origin is `recommendation` — it is the identical component, the identical confidence/reasoning contract, and the identical three-button pattern `DASHBOARD.md` and `AI_COMMAND_CENTER.md` already specify, imported, not reimplemented.
3. **RBAC is enforced by the API; Home only reflects it, twice over.** A widget whose backing endpoint the current role cannot read is never rendered inside whichever mode is active, exactly as each parent document already requires; and, new to this document, a *mode itself* that would render as an almost-empty page for the caller's actual permission set is never the one Home silently serves, regardless of what is stored as that user's preference — see `# Edge Cases`.

# Route & Access

| | |
|---|---|
| Route | `app/(app)/page.tsx` (Server Component) — reuses `app/(app)/loading.tsx` and `app/(app)/error.tsx`, the shell-level boundaries `FRONTEND_ARCHITECTURE.md` already defines; Home introduces no route-specific `loading.tsx`/`error.tsx` of its own, because the mode is resolved before the first byte streams (see `# Data & State`) and each region inside whichever mode renders keeps its own `Suspense`/`WidgetErrorBoundary`, exactly as `DASHBOARD.md` and `AI_COMMAND_CENTER.md` already specify for those regions |
| Nav item | **Home** — `LayoutDashboard` icon, position 1 of 10 in the primary Sidebar (amended from "Dashboard" per the note above); mobile bottom-tab-bar first slot, amended the same way |
| Gating permission | **None.** Identical posture to both of the screens it composes: every authenticated member of a company can open `/`, and every region inside whichever mode renders enforces its own permission independently — a role that fails every permission Classic or AI mode's content needs still sees the shell, the Mode Switcher, and whichever Quick Actions or Tasks it holds, never a wall |
| Breadcrumb | **Home** only — no parent segment, and no link back to it from anywhere else in the trail (`NAVIGATION_SYSTEM.md`'s own rule that "the trail is always module-rooted... it never starts at `/dashboard`" is inherited unchanged: it never starts at `/` either, and `/dashboard`'s/`/command-center`'s own single-crumb breadcrumbs are unaffected) |
| Company/branch scope | Fixed to the active `X-Company-Id`; branch is the same optional, URL-mirrored `?branch=` filter Dashboard already defines, applied identically once Home resolves which mode's regions to render |

## How Home decides what to render

Home's Server Component performs exactly one decision before it streams anything: which of `ClassicHome` or `AiHome` to mount. That decision reads a single field, `home_mode`, off the already-established `user_preferences` resource `docs/frontend/PROFILE.md` owns (`GET /api/v1/users/me/preferences`) — this document's own, explicitly flagged addition to that resource's schema, alongside the `theme`/`density`/`text_scale`/`reduced_motion_override`/`sidebar_collapsed`/`ai_rail_open`/`table_density`/`dashboard_layout`/`calendar_default_view` fields `PROFILE.md` already documents:

```ts
// Extends the `UserPreferences` type PROFILE.md already generates from the OpenAPI contract
interface UserPreferences {
  theme: "light" | "dark" | "system";
  density: "comfortable" | "compact";
  text_scale: "default" | "large" | "larger";
  reduced_motion_override: boolean | null;
  sidebar_collapsed: boolean;
  ai_rail_open: boolean;
  table_density: Record<string, "comfortable" | "compact">;
  dashboard_layout: unknown | null;
  calendar_default_view: "day" | "week" | "month";
  home_mode: "classic" | "ai" | null;   // this document's own addition — null until the user's first explicit choice
}
```

`home_mode` is deliberately a single, small, nullable enum rather than a new table, matching the exact criterion `PROFILE.md` itself used to standardize the rest of this resource on typed columns over a JSONB bag — a fixed, small set of enumerable settings, not a genuinely evolving bag of preferences. It is also, deliberately, a *different* concern from `dashboard_layout` (Dashboard's own KPI Strip ordering, per `DASHBOARD.md`'s "Customize" sheet) and from the Command Center's own `ai_dashboard_layouts` table (per-widget position/size/visibility inside the Command Center specifically, per `docs/ai/AI_COMMAND_CENTER.md`'s `dashboard_customization` widget) — a user's Classic KPI Strip order and a user's AI Home widget arrangement can each be independently customized while `home_mode` decides, one level above both, which of the two screens they are arranging in the first place. Reading `home_mode` never requires a second network round trip: `(app)/layout.tsx` already resolves `GET /api/v1/auth/me` for every route in the shell, and `app/(app)/page.tsx` fetches `GET /api/v1/users/me/preferences` alongside it, in parallel, both server-side, before either compositional branch is chosen.

```tsx
// app/(app)/page.tsx
import { apiServer } from "@/lib/api/server-client";
import { resolveHomeMode } from "@/lib/home/resolve-home-mode";
import { ClassicHome } from "@/components/home/classic-home";
import { AiHome } from "@/components/home/ai-home";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function HomePage({
  searchParams,
}: { searchParams: { branch?: string; range?: string } }) {
  const [me, preferences] = await Promise.all([
    apiServer.get("/auth/me"),
    apiServer.get("/users/me/preferences"),
  ]);

  const mode = resolveHomeMode({
    explicit: preferences.data.home_mode,           // 'classic' | 'ai' | null
    role: me.data.active_company.role_key,
    permissions: me.data.active_company.permissions,
  });

  return mode === "ai"
    ? <AiHome searchParams={searchParams} />
    : <ClassicHome searchParams={searchParams} />;
}
```

`resolveHomeMode` is a small, pure, fully documented function — never a black box a designer or a support engineer has to reverse-engineer from behavior:

```ts
// lib/home/resolve-home-mode.ts
const AI_DEFAULT_ROLES = new Set([
  "owner", "ceo", "cfo", "finance_manager", "senior_accountant",
]);

export function resolveHomeMode({ explicit, role, permissions }: {
  explicit: "classic" | "ai" | null;
  role: string;
  permissions: string[];
}): "classic" | "ai" {
  const requested = explicit ?? (AI_DEFAULT_ROLES.has(role) ? "ai" : "classic");
  // Never serve a mode that would render as an all-but-empty page for this permission
  // set, regardless of what is stored or defaulted — see # Edge Cases.
  if (requested === "ai" && !permissions.includes("reports.read") && !permissions.includes("ai.chat")) {
    return "classic";
  }
  return requested;
}
```

## Role-based default, when no explicit choice exists yet

| Default mode | Roles |
|---|---|
| **AI Home** | Owner, CEO, CFO, Finance Manager, Senior Accountant |
| **Classic Home** | Accountant, Auditor, External Auditor, HR Manager, Payroll Officer, Inventory Manager, Warehouse Employee, Sales Manager, Sales Employee, Purchasing Manager, Purchasing Employee, Read Only |

This grouping is this document's own, new contribution — it did not exist anywhere in the platform before this screen needed it — but it is not an arbitrary split: it mirrors, role for role, the exact grouping `docs/frontend/AI_COMMAND_CENTER.md`'s own Route & Access table already uses to decide how much of the seven-panel surface each role sees ("Owner, CFO... All seven, full width"; "Finance Manager, Senior Accountant... All seven"; "Accountant... Morning Briefing, Business Health Score (read-only), AI Insights, Ask AI"; narrower still for Payroll/Inventory/Sales/Purchasing roles). A role that already sees only a thin slice of the Command Center's own registry is, by the same logic, better served landing on Classic Home's numbers first and one click from the Command Center, not the reverse. The table is a default, in the same spirit `docs/ai/AI_COMMAND_CENTER.md`'s own `dashboard_customization` widget already commits to for within-screen layout ("QAYD ships sensible role-based defaults... but nothing here is fixed") — never a permission, never enforced after the caller's first explicit interaction with the Mode Switcher, and never silently re-applied if a role changes later while an explicit `home_mode` is already on file.

| Permission checked | Region |
|---|---|
| None | Mode Switcher itself — self-scoped preference, no permission gates it |
| `reports.read` | My Tasks panel (Classic only), scoped further to tasks the caller is entitled to act on, per `docs/ai/AI_COMMAND_CENTER.md`'s own Today's Tasks section |
| `reports.read` | Urgent Actions banner (both modes) |
| Whichever Classic-mode region is rendered | Exactly the permission `DASHBOARD.md`'s own Route & Access table already names for that region — unchanged |
| Whichever AI-mode panel is rendered | Exactly the permission `AI_COMMAND_CENTER.md`'s own Route & Access table already names for that panel — unchanged |

# Layout & Regions

Home instantiates a new, minimal template of its own — call it the **Landing Template** — layered on top of whichever of `LAYOUT_SYSTEM.md`'s existing **Dashboard Template** (Classic) or the Command Center's own bento grid (AI) actually renders beneath it. The Landing Template itself contributes exactly three things no other document owns: the pinned Urgent Actions banner, the Mode Switcher, and — in Classic mode only — the Tasks panel; everything below that chrome is Dashboard's or the Command Center's own regions, imported wholesale and unmodified.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ● Urgent — Vendor bank details changed 2h ago · SLA breach in 40m [Review]  │  ← Urgent Actions, full width, both modes
├─────────────────────────────────────────────────────────────────────────────┤
│  Home                                          ( Classic ● ─ ○ AI Home )    │  ← Landing header + Mode Switcher
├─────────────────────────────────────────────────────────────────────────────┤
│                          — Classic Home selected —                          │
│ [Cash 42,318.600] [Revenue 96,220 ▲13.3%] [Expenses 61,040 ▲9.1%] [AR/AP]    │  ← Dashboard's own KPI Strip, reused
├───────────────────────────────────────────────┬─────────────────────────────┤
│         Revenue vs. Expenses — 90 days         │  AI Summary                │
│         (Dashboard's own Chart Region)         │  (Dashboard's own rail)    │
├───────────────────────────┬─────────────────────┴─────────────────────────┤
│  AR Aging  │  AP Aging     │  My Tasks                                     │  ← NEW — Home-only
│  (Dashboard's AgingBar x2) │  ▸ Approve July vendor run — due 14:00   ●     │
│                            │  ▸ Verify Al-Fajr's changed bank details  ●    │
│                            │  ▸ Decide on the steel rebar reorder      ○    │
├───────────────────────────┴─────────────────────┬─────────────────────────┤
│  Recent Activity (Dashboard's own feed)          │  Quick Actions (reused)  │
└─────────────────────────────────────────────────┴─────────────────────────┘
```

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ● Urgent — same banner, same content, mode-independent                     │
├─────────────────────────────────────────────────────────────────────────────┤
│  Home                                          ( ○ Classic ─ ● AI Home )    │
├─────────────────────────────────────────────────────────────────────────────┤
│                             — AI Home selected —                            │
│  morning_briefing                                        (Command Center's) │
│  business_health_score │ cash_flow_status │ ai_insights_feed  (own seven     │
│  detected_risks_radar                                     panels,           │
│  approval_center_queue                                     unmodified)      │
│                          [ 💬 Ask AI ]                                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

| Region | Owner | Notes |
|---|---|---|
| **Urgent Actions banner** | `docs/ai/AI_COMMAND_CENTER.md`'s `urgent_actions_banner` widget, given its first frontend component name by this document | Full width, pinned above the Mode Switcher itself in both modes — "reserved exclusively for items that are both time-critical and irreversible-if-missed," per the product document, and this document does not weaken that: a critical fraud hold or an SLA-breached approval is exactly as visible to a Classic-default Owner as to an AI-default one |
| **Landing header + Mode Switcher** | New to this document | A single-row, `Card`-less chrome strip: the page's `<h1>` ("Home"), and `HomeModeSwitcher`, a two-option `ToggleGroup` (`Classic` / `AI Home`) — see `# Components Used` |
| **Classic Home's regions** | `docs/frontend/DASHBOARD.md`, imported unmodified | Filter Bar, KPI Strip, Chart Region, AI Summary Rail, Aging Summary, Recent Activity, Quick Actions — every prop, endpoint, and permission exactly as that document specifies; Home changes none of it |
| **My Tasks panel** | New to this document, reusing `docs/ai/AI_COMMAND_CENTER.md`'s existing `todays_tasks` widget/endpoint | Classic-only. AI Home does not repeat it as a separate region, because Today's Tasks is already a selectable widget inside that user's own `ai_dashboard_layouts` per the Command Center's own customization model — duplicating it in the AI composition as well would be the same fact rendered from two different code paths, which `docs/ai/AI_COMMAND_CENTER.md`'s own design (`origin`/`origin_id` pointing at exactly one owning table) exists specifically to prevent |
| **AI Home's panels** | `docs/frontend/AI_COMMAND_CENTER.md`, imported unmodified | Morning Briefing, Business Health Score, Cash Flow Status, Detected Risks, Approval Center, AI Insights, the docked Ask AI surface — every prop, endpoint, and permission exactly as that document specifies |

The **My Tasks** panel sits where Dashboard's own region grid has no equivalent — `col-span-4` at `lg`+, stacked beside the Aging Summary rather than displacing it, full width below `md` — populated from the identical `ai_command_tasks` rows `docs/ai/AI_COMMAND_CENTER.md`'s Today's Tasks section already defines, filtered to `assigned_to = <the caller>` and `status IN ('open', 'in_progress')`, capped to five rows with a "View all" link into `/ai/tasks` (a full-page destination this document does not further specify, mirroring how `DASHBOARD.md`'s own "View all activity" link points at a module's own list page rather than a second implementation).

# Components Used

Nothing on this screen is a one-off; every visual element is either imported unmodified from `DASHBOARD.md`'s/`AI_COMMAND_CENTER.md`'s own component set or a small, new, pure-composition wrapper this document introduces.

| Component | Source | Role on this screen |
|---|---|---|
| `KpiTile`, `TrendSparkline`, `AmountCell`, `CurrencyTag`, `StatusPill`, `AgingBar`, `PeriodPicker`, `RevenueExpenseChart`, `AgingSummaryCard`, `RecentActivityFeed`, `QuickActionsBar`, `DashboardFilterBar`, `AiSummaryRail` | `components/dashboard/*` (existing, `DASHBOARD.md`) | Classic Home's entire region set, imported and rendered exactly as `DASHBOARD.md` specifies — no prop shape, endpoint, or permission is changed for use on this screen |
| `AiCardShell`, `ConfidenceBadge`, `AIProposalPanel`, `ApprovalCard`, `DataTable`, `WidgetErrorBoundary`, `AskAIDock`, `MorningBriefingCard`, `BusinessHealthScoreCard`, `CashFlowStatusCard`, `DetectedRisksCard`, `ApprovalCenterCard`, `AIInsightsCard` | `components/ai/*`, `components/dashboard/*` (existing, `AI_COMMAND_CENTER.md`) | AI Home's entire panel set, imported and rendered exactly as `AI_COMMAND_CENTER.md` specifies |
| `Card`, `Badge`, `Button`, `Tooltip`, `ToggleGroup`, `Skeleton`, `VisuallyHidden` | `components/ui/*` (existing shadcn primitives) | Landing header chrome, Mode Switcher, region shells, loading placeholders |
| `EmptyState`, `ErrorState`, `PermissionGate` / `Can` | `components/shared/*`, `components/auth/can.tsx` (existing) | Per-region empty/error rendering and permission gates, identical usage to both parent screens |
| `UrgentActionsBanner` | `components/dashboard/urgent-actions-banner.tsx` (**new to the frontend layer** — the `urgent_actions_banner` widget itself is already specified product-side in `docs/ai/AI_COMMAND_CENTER.md`; this document is the first to give it a concrete component and file path) | The pinned, non-dismissible banner strip shared by both modes |
| `HomeModeSwitcher` | `components/home/home-mode-switcher.tsx` (**new**) | The two-option `ToggleGroup` that reads and writes `home_mode`; see `# Interactions & Flows` |
| `TasksPanel` | `components/home/tasks-panel.tsx` (**new**) | Classic-only; thin, presentation-only wrapper around the existing `todays_tasks` contract — introduces no new endpoint, table, or permission of its own |
| `ClassicHome`, `AiHome` | `components/home/classic-home.tsx`, `components/home/ai-home.tsx` (**new**) | Pure composition roots — each imports and arranges existing region/panel components; neither contains a data-fetching call of its own beyond what its constituent components already make |
| `FirstRunModeNudge` | `components/home/first-run-mode-nudge.tsx` (**new**) | The one-time, dismissible callout shown only on a role-defaulted (never explicitly chosen) render; see `# Interactions & Flows` |

The six "new" components above introduce no new design token, no new API shape beyond the one flagged `home_mode` field, and no new permission key — each is a documented arrangement of existing primitives and existing finance/AI components, matching `COMPONENT_LIBRARY.md`'s stated contract that a screen never hand-rolls a table, a money cell, a confidence indicator, or an AI card shell that already exists in the shared library.

# Data & State

## Endpoints

Home reads from exactly three families of endpoint: the mode-resolution resource (new field, existing endpoint), Classic Home's entire endpoint set (unchanged, owned by `DASHBOARD.md`), and AI Home's entire endpoint set (unchanged, owned by `AI_COMMAND_CENTER.md`), plus the one endpoint the Tasks panel introduces to this screen (also unchanged — owned by `docs/ai/AI_COMMAND_CENTER.md`'s Today's Tasks section).

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Resolve/persist Home mode | `GET /api/v1/users/me/preferences`, `PATCH /api/v1/users/me/preferences` | Self-scoped, authentication only | `home_mode` field, this document's own addition to `docs/frontend/PROFILE.md`'s schema — see `# Route & Access` |
| Classic Home's entire content | `GET /api/v1/dashboard`, `/api/v1/reports/kpis`, `/api/v1/reports/revenue-trends`, `/api/v1/reports/expense-trends`, `/api/v1/banking/bank-accounts`, `/api/v1/reports/ar-aging`, `/api/v1/reports/ap-aging`, `/api/v1/ai/recommendations?limit=3`, `/api/v1/ai/risks?category=all&limit=3`, `/api/v1/ai/health-score` | Per-endpoint, exactly as `docs/frontend/DASHBOARD.md → Data & State` specifies | Unchanged; Home does not re-request, re-shape, or re-cache any of these under its own key — see Query keys below |
| AI Home's entire content | `GET /api/v1/ai/briefings/{date}`, `/api/v1/ai/health-score`, `/api/v1/banking/accounts`, `/api/v1/ai/cash-flow/forecast`, `/api/v1/ai/risks?category=all`, `/api/v1/approvals`, `/api/v1/ai/insights?since={timestamp}`, `POST /api/v1/ai/chat` | Per-endpoint, exactly as `docs/frontend/AI_COMMAND_CENTER.md → Data & State` specifies | Unchanged |
| My Tasks (Classic only) | `GET /api/v1/ai/command-tasks?due=today` | `reports.read`, scoped to tasks the caller is entitled to act on | Unchanged from `docs/ai/AI_COMMAND_CENTER.md`'s own Today's Tasks section; this is the one endpoint Home's own new component (`TasksPanel`) calls that neither Dashboard nor a Classic-mode KPI Strip already called |
| Urgent Actions (both modes) | `GET /api/v1/ai/urgent-actions` | `reports.read` | Named, but not endpoint-detailed, by `docs/ai/AI_COMMAND_CENTER.md`'s own Urgent Actions section, which defers its full data contract to "a future addendum"; this document's own `UrgentActionsBanner` component consumes it under that same deferred contract, unchanged |

## Query keys

```ts
// lib/api/query-keys.ts (Home additions)
export const homeKeys = {
  all: ["home"] as const,
  mode: () => [...homeKeys.all, "mode"] as const,        // mirrors profileKeys.preferences(), read once per navigation root
  tasks: (due: "today" | "overdue" = "today") => [...homeKeys.all, "tasks", due] as const,
};
// Classic mode reuses dashboardKeys.* verbatim (DASHBOARD.md); AI mode reuses
// commandCenterKeys.* verbatim (AI_COMMAND_CENTER.md). Neither is re-derived or
// re-namespaced under homeKeys — the same server response is the same cache entry
// whether the user reaches it via /, /dashboard, or /command-center directly.
```

This last point is load-bearing: because `ClassicHome` imports Dashboard's own `KpiStrip`/`RevenueExpenseChart`/etc. components rather than reimplementing them, and those components' own hooks already call `dashboardKeys.*`, a user who has `/` open in one tab and clicks through to `/dashboard` in another sees an already-warm cache, not a second fetch — TanStack Query's cache is keyed by data identity, not by which route happened to mount the component.

## Cache tuning

| Data class | Resource | `staleTime` | Rationale |
|---|---|---|---|
| Mode preference | `homeKeys.mode()` | 5 minutes, no `refetchOnWindowFocus` | Identical tier to `docs/frontend/PROFILE.md`'s own `profileKeys.preferences()` — "user-driven, rarely changed by anything but the user's own action in this same tab" |
| Tasks | `homeKeys.tasks()` | 15 seconds, `refetchOnWindowFocus: true` | Actionable and time-sensitive (a task can be resolved from its own owning screen while Home is open in another tab) but not a raw live figure; sits between Dashboard's "Live/derived" tier (`0`) and its "AI feeds" tier (`10_000`) because a task list changing every few seconds would be unusual, while a stale one for minutes would risk acting on a payment run already approved elsewhere |
| Everything else | `dashboardKeys.*` / `commandCenterKeys.*` | Unchanged | Home introduces no new tuning for content it did not introduce |

## Server-resolved mode, then Suspense per region

The single most consequential performance and correctness decision on this screen is that **mode is resolved on the server, before the first byte streams** — never client-side, and never behind its own loading state. A client-resolved mode would mean every visit to `/` painted a generic shell, then popped into Classic or AI content a beat later once a `useQuery` for `home_mode` settled — a flash-of-wrong-content bug on the single highest-traffic route in the product. `app/(app)/page.tsx`'s own `Promise.all([getMe(), getPreferences()])` (shown in full in `# Route & Access`) resolves before either `<ClassicHome>` or `<AiHome>` is even referenced in the returned tree, so the very first HTML the browser receives already contains the correct composition; from that point on, every region inside it streams independently behind its own `<Suspense>`/`<WidgetErrorBoundary>` pair exactly as `DASHBOARD.md`'s and `AI_COMMAND_CENTER.md`'s own Server Components already do — Home does not introduce a second streaming strategy, it inherits both, mutually exclusively, per request.

## Realtime

Home subscribes to exactly the realtime channels the active mode's own document already names — never both simultaneously, which is a deliberate cost-control decision new to this document:

| Mode | Channels subscribed |
|---|---|
| Classic | `private-company.{id}.dashboard.{dashboard_id}`, `private-company.{id}.ai-jobs` — exactly `docs/frontend/DASHBOARD.md`'s own Realtime table, unchanged |
| AI | `private-company.{id}.dashboard.{dashboard_id}`, `private-company.{id}.ai-jobs`, `private-company.{id}.approvals` — exactly `docs/frontend/AI_COMMAND_CENTER.md`'s own Realtime table, unchanged |
| Both | `private-company.{id}.notifications.{user_id}` (Topbar bell, global, unrelated to which mode Home is in) |

`RealtimeProvider` is still mounted exactly once in `(app)/layout.tsx` regardless of mode (`FRONTEND_ARCHITECTURE.md`'s existing convention); what changes per mode is only which channel *bindings* `ClassicHome`'s/`AiHome`'s own constituent components register on mount and tear down on unmount — switching mode inline (`# Interactions & Flows`) unbinds the outgoing mode's channels and binds the incoming mode's in the same tick a Classic/AI component swap happens, mirroring the exact `IntersectionObserver`-gated unsubscribe pattern `AI_COMMAND_CENTER.md`'s own Responsive Behavior section already uses for scrolled-out-of-view mobile panels, applied here to a mode swap instead of a scroll position.

## AI agents feeding this screen

| Agent | Contribution |
|---|---|
| `REPORTING_AGENT` | Computes Classic Home's Revenue/Expense trend series and headline commentary (unchanged from `DASHBOARD.md`); observes `home_mode`-switch frequency for the suggestion described in `# AI Integration` |
| `CEO_ASSISTANT`, `CFO_AGENT`, `TREASURY_MANAGER`, `FRAUD_DETECTION`, `APPROVAL_ASSISTANT` | Feed AI Home's panels exactly as `docs/frontend/AI_COMMAND_CENTER.md → Data & State` already documents; unchanged |
| `PAYROLL_MANAGER`, `TAX_ADVISOR` | Populate `origin = 'payroll_deadline' \| 'tax_deadline'` rows in `ai_command_tasks` ahead of the actual due date, per `docs/ai/AI_COMMAND_CENTER.md`'s own Today's Tasks AI Behavior paragraph — the two agents most frequently responsible for what a Classic-mode user sees in My Tasks without ever having opened Approval Center or the Tax module directly |

# Interactions & Flows

**Arriving at Home for the first time.** A brand-new Owner completing onboarding's `ai-team` step (`docs/frontend/ONBOARDING.md`'s step 8, `POST /api/v1/onboarding/complete`) lands on `/` — amended from that document's own stated `/dashboard` target, per `# Route & Access` — with no `home_mode` on file yet. `resolveHomeMode` computes `ai` for an Owner, so `AiHome` renders on the very first paint; a single, dismissible `FirstRunModeNudge` appears beside the Mode Switcher for that one session only ("You're seeing your role's default view, AI Home. Prefer numbers first? Switch to Classic any time.") and never reappears once either (a) the user interacts with the switcher at all, in either direction, or (b) seven days pass, whichever comes first — it is calibrated copy, not a blocking tour, and it never renders as a `Dialog` that must be dismissed before the page beneath it is usable.

**Signing in on an ordinary morning.** `LOGIN_SCREEN.md`'s `resolvePostAuthDestination` — its `next`-deep-link and `/select-company` branches entirely unchanged — lands a returning user with no pending deep link on `/`; `home_mode` is by now almost always an explicit, previously-set value, so the mode resolution is a single cache-hit-shaped read with no role table involved at all.

**Switching mode inline.** Clicking the other segment of `HomeModeSwitcher` does not navigate — there is no `router.push`, and the URL stays `/` throughout. It fires a `PATCH /api/v1/users/me/preferences` mutation (`{ "home_mode": "classic" }`) that, on success, swaps `useHomeMode()`'s local resolved value and triggers a Framer Motion crossfade (`motion.base`, 200ms, `prefers-reduced-motion`-gated to an instant swap) between `<ClassicHome>` and `<AiHome>`, matching the platform's existing `template.tsx` page-transition convention applied to an in-place composition swap rather than a route change. The mutation is optimistic — the swap happens immediately, before the server confirms — because a mode preference is exactly the kind of reversible, low-stakes, non-financial action `FRONTEND_ARCHITECTURE.md`'s Principle 10 already describes ("dismissing an insight, reordering a dashboard widget"); a failed `PATCH` reverts the switch and toasts, per the same pattern `DASHBOARD.md`'s own KPI-layout "Customize" sheet already uses.

```ts
// lib/api/hooks/use-set-home-mode.ts
export function useSetHomeMode() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (mode: "classic" | "ai") =>
      api.patch("/users/me/preferences", { home_mode: mode }),
    onMutate: async (mode) => {
      await queryClient.cancelQueries({ queryKey: homeKeys.mode() });
      const previous = queryClient.getQueryData(homeKeys.mode());
      queryClient.setQueryData(homeKeys.mode(), (old: UserPreferences) => ({ ...old, home_mode: mode }));
      return { previous };
    },
    onError: (_err, _mode, context) => {
      queryClient.setQueryData(homeKeys.mode(), context?.previous);
      toast.error(t("home.modeSwitchFailed"));
    },
  });
}
```

**Interacting with a task.** Checking a row in **My Tasks** never marks it done directly — exactly the rule `docs/ai/AI_COMMAND_CENTER.md`'s own Today's Tasks Interaction paragraph already states ("checking off 'Approve the vendor payment run' without actually approving it in Approval Center does nothing — the UI redirects to the real action"). `TasksPanel` reuses that behavior verbatim: clicking a row whose `origin` is `approval` navigates to `/approvals/{origin_id}`; `risk_flag` navigates to the owning Detected Risks record; `tax_deadline`/`payroll_deadline` navigate into Tax/Payroll; only `origin: 'manual'` rows are editable or completable in place, via `PATCH /api/v1/ai/command-tasks/{id}`.

**Drilling into Classic Home's own content.** Every click Dashboard's regions already define — a KPI tile into its record set, a chart segment or aging bucket into a filtered list, a Quick Action into its create route — behaves identically whether reached from `/` or from `/dashboard` directly, because it is the same component making the same `router.push`. This document does not restate `DASHBOARD.md`'s own click-by-click table; see that document's `# Interactions & Flows` for the full destination list.

**Drilling into AI Home's own content.** Same principle: Morning Briefing's `?highlight=` deep-link convention, Business Health Score's expandable breakdown, Cash Flow Status' simulate-a-fix link, Approval Center's stepper, and Ask AI's docked drawer all behave identically whether reached from `/` or from `/command-center` directly; see `docs/frontend/AI_COMMAND_CENTER.md → Interactions & Flows` for the full list.

**Forcing the other register regardless of default.** A user whose stored `home_mode` is `classic` who specifically wants the full Command Center experience for one session does not need to touch the switcher at all — `/command-center` remains directly reachable, unchanged, exactly as `AI_COMMAND_CENTER.md` fixes it; the same is true of `/dashboard` for an AI-default user who wants Dashboard's own full-width Chart Region without the Tasks panel's column stealing space. Home's Mode Switcher changes the *default* for next time; the two underlying routes remain the explicit, always-available override.

# AI Integration

Home introduces exactly one new AI behavior — a suggestion about the mode default itself — and otherwise inherits the entirety of its AI surface, unmodified, from whichever of Dashboard's or the Command Center's own AI Integration contract governs the content currently on screen.

- **Confidence and reasoning are never hidden, in either mode.** Classic Home's AI Summary Rail and My Tasks' `recommendation`-origin rows carry the identical `ConfidenceBadge` contract `DASHBOARD.md` already specifies; AI Home's full Command Card set carries the identical contract `AI_COMMAND_CENTER.md` already specifies. Home's own code contributes zero new confidence-rendering logic.
- **Not every task is an AI proposal, and My Tasks never implies otherwise.** `ai_command_tasks.origin` is one of `approval | risk_flag | tax_deadline | payroll_deadline | manual | recommendation`; only the last of these carries a meaningful confidence score, because only a `recommendation`-origin row is itself an AI-authored suggestion — the other five are either a human-created reminder or a fact (a filed deadline, an already-existing approval or risk record) an agent surfaced early, not proposed. `TasksPanel` renders a `ConfidenceBadge` exclusively on `origin: 'recommendation'` rows and a small, plain origin icon (calendar, checkmark, person) on every other row — attaching a confidence percentage to a tax filing deadline would fabricate a signal that does not exist and would visually flatten a real distinction the platform elsewhere takes care to preserve (`docs/ai/AI_COMMAND_CENTER.md`'s own `ai_generated: false` convention for "panels that are pure configuration surfaces with no AI narrative layer").
- **The three-button pattern is exact wherever it appears, never approximated for space.** A `recommendation`-origin task row that is compact enough to fit My Tasks' five-row cap still routes its action through the identical `can_execute_directly`-gated component `AIProposalPanel` already renders elsewhere — Home never ships a shortened, home-grown version of that pattern just because the row is narrower.
- **Sensitive actions never terminate on this screen, in either mode.** Approving a bank transfer, a payroll release, or a tax submission surfaced anywhere on Home — a Top Recommended Action in Classic mode's AI Summary Rail, an `ApprovalCard` in AI mode's Approval Center panel, or a `recommendation`-origin task — always routes to `/approvals`, exactly as both parent documents already require; Home adds no new one-click execution path for anything on the sensitive-action list.
- **The mode default itself may earn a suggestion, never a silent change.** Extending `docs/ai/AI_COMMAND_CENTER.md`'s own `dashboard_customization` AI behavior verbatim — "`REPORTING_AGENT` observes usage... and, no more than once a quarter, offers a single non-intrusive suggestion... always a suggestion, never a silent rearrangement" — this document applies the identical mechanism, the identical quarterly cadence ceiling, and the identical agent to `home_mode`: if a `classic`-default user's own navigation pattern shows them manually opening `/command-center` on most sessions over a rolling 30-day window, `REPORTING_AGENT` may surface one dismissible suggestion chip beside the Mode Switcher ("You open AI Home often — make it your default?"). Accepting it fires the identical `PATCH /api/v1/users/me/preferences` mutation the manual switcher uses; declining it suppresses the suggestion for that same quarter. No usage signal, however strong, ever flips `home_mode` on its own.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Mode resolution | None visible — resolved server-side before first paint (`# Data & State`); the very first HTML already contains the correct composition | N/A | If `GET /api/v1/users/me/preferences` itself fails server-side, `resolveHomeMode` falls back to the role-based default table only, treating the failure identically to "never set" rather than blocking the route — Home never fails to render because a preferences read failed |
| Urgent Actions banner | Not shown loading — renders only once data resolves; absent, not skeletonized, while pending | Collapses to zero height — "no truly urgent item" is the common case and is not itself an empty state worth announcing | Silently omitted on failure rather than an inline retry card at the very top of the screen — a broken urgent-actions fetch must never itself become the most alarming thing on the page; it is retried on the next realtime tick |
| Classic Home's own regions | Exactly `docs/frontend/DASHBOARD.md → States`, unchanged | Exactly `DASHBOARD.md → States`, unchanged, including the dedicated "chart of accounts isn't set up yet" first-run card for a brand-new company | Exactly `DASHBOARD.md → States`, unchanged |
| AI Home's own panels | Exactly `docs/frontend/AI_COMMAND_CENTER.md → States`, unchanged | Exactly `AI_COMMAND_CENTER.md → States`, unchanged, including Morning Briefing's own new-company setup CTA | Exactly `AI_COMMAND_CENTER.md → States`, unchanged |
| My Tasks | Three skeleton rows, same height as a resolved `TasksPanel` row | "Nothing on your plate today" — explicitly reassuring, matching the same register `AI_COMMAND_CENTER.md`'s own Approval Center empty state ("Nothing waiting on you") already establishes for an empty, good-news queue | Inline retry within the panel only; the rest of Home is unaffected |
| Mode Switcher | Renders synchronously from the resolved server-side value; never shows its own loading state | N/A | A failed mode-read (see Mode resolution row above) never blocks the switcher itself — it renders the role-defaulted side already selected and functions normally |

A brand-new company's very first user — arriving via Onboarding's `ai-team` completion with an empty ledger and no bank accounts connected — sees every region's dedicated first-run state simultaneously, in whichever mode their role defaults to: Classic Home's KPI Strip shows its setup card, My Tasks shows its empty state (there is nothing to do yet, which is accurate, not a failure), and Quick Actions is the one region already fully interactive, exactly mirroring the "a legitimate, minimal-but-not-broken rendering" posture `DASHBOARD.md`'s own Edge Cases table already commits to.

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | Urgent Actions banner remains full width, pinned; the Mode Switcher collapses to a compact two-icon segmented control directly beneath it; whichever mode is active reflows exactly per that mode's own document — Classic Home per `DASHBOARD.md`'s carousel-KPI-Strip/stacked-region table, AI Home per `AI_COMMAND_CENTER.md`'s fixed mobile reflow order (Urgent/held items, Morning Briefing, KPI row, and so on); My Tasks (Classic only) stacks as its own full-width card list, one row swipeable at a time, styled consistently with `ApprovalCard`'s own mobile swipe pattern for a task whose origin is an approval |
| `md` (768–1023px) | Mode Switcher returns to its full two-label form; both modes reflow per their own document's tablet row |
| `lg` (1024–1279px) | Full desktop composition for whichever mode is active; My Tasks sits at `col-span-4` beside the Aging Summary in Classic mode |
| `xl`+ (≥1280px) | Unchanged from `lg` in structure; AI Home gains the Command Center's own `3xl`-only persistent Ask AI rail exactly as that document specifies — Home does not gate or duplicate that behavior |
| Mobile bottom tab bar | First slot, amended alongside the Sidebar per `# Route & Access`, from "Dashboard" → `/dashboard` to **Home** → `/`; the remaining four fixed slots (Accounting, the raised `+` quick-create action, Banking, More) are entirely unchanged from `docs/frontend/NAVIGATION_SYSTEM.md`'s own mobile navigation table |

Touch targets on the Mode Switcher, every My Tasks row, and every Urgent Actions action button maintain the platform's 44×44px minimum hit area with an 8px minimum gap between adjacent controls, identical to the standard `RESPONSIVE_DESIGN.md → Touch Targets & Gestures` rule both parent screens already cite — material here specifically because the Mode Switcher's two segments sit close together and a mis-tap would silently change a user's default landing experience, a low-stakes but genuinely annoying mistake to make by accident. Realtime subscription scoping below `md` follows the identical `IntersectionObserver`-gated pattern `AI_COMMAND_CENTER.md`'s own Responsive Behavior section already specifies for its seven panels, applied here per-mode as well as per-panel: a Classic-mode mobile session never opens the Command Center's own channel bindings at all, and vice versa.

# RTL & Localization

Every rule inherited from `DESIGN_LANGUAGE.md` and applied concretely by `DASHBOARD.md` and `AI_COMMAND_CENTER.md` to their own regions applies unchanged to those same regions when Home renders them — logical properties only, numerals/currency/dates always `dir="ltr"`-isolated and never Eastern Arabic-Indic, charts never mirrored, bilingual `name_en`/`name_ar` fields picked client-side by locale. Home's own, new chrome follows the identical discipline:

- **The Mode Switcher mirrors as a whole; its two options never reorder relative to reading direction.** In RTL, `HomeModeSwitcher` sits at the inline-start of its row (mirrored via `ms-*`/`me-*`, never a hard-coded side), and "Classic" remains the first (inline-start) option and "AI Home" the second in both directions — matching the platform convention that a fixed, small option set (like a `Tabs` strip) keeps a consistent internal order rather than reversing it, the same choice `NAVIGATION_SYSTEM.md`'s own RTL Mirroring table already makes for other small, ordered control sets.
- **My Tasks' priority dots and origin icons never mirror**, matching the platform's general rule that meaning-bearing glyphs (a severity dot, a checkmark) do not flip, only directional chevrons do.
- **The Urgent Actions banner's action affordance sits at the logical end of the strip in both directions** (`me-*`), consistent with how a banner's primary action is positioned across the rest of the product.

| Context | English | Arabic |
|---|---|---|
| Page title / `<h1>` | Home | الرئيسية |
| Mode Switcher — Classic | Classic | كلاسيكي |
| Mode Switcher — AI Home | AI Home | الرئيسية الذكية |
| First-run nudge | You're seeing your role's default view, AI Home. Prefer numbers first? | أنت تشاهد العرض الافتراضي لدورك، الرئيسية الذكية. تفضّل الأرقام أولاً؟ |
| My Tasks — empty | Nothing on your plate today. | لا يوجد شيء بانتظارك اليوم. |
| My Tasks panel title | My Tasks | مهامي |
| Suggestion chip | You open AI Home often — make it your default? | تفتح الرئيسية الذكية كثيرًا — هل نجعلها الافتراضية؟ |

Arabic copy for every new string this document introduces is authored directly by a fluent, professional-register writer, matching `DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief — none of the table above is a machine-translated pass over the English column.

# Dark Mode

Home introduces no new color, elevation, radius, or motion token. Every surface it renders resolves through the identical `docs/frontend/DARK_MODE.md` strategy both parent screens already implement — physical-light elevation (a card sits a step lighter than the canvas behind it, never darker), warm-neutral backgrounds rather than a cool blue-black shift, and the accent reserved exclusively for AI provenance and the single primary action per card, never for status. Two of Home's own new pieces of chrome are worth naming explicitly because they did not exist as dark-mode-verified components before this document:

- **The Mode Switcher's active-segment fill** uses the platform's standard `ToggleGroup` active-state treatment (`DESIGN_LANGUAGE.md`'s accent-at-low-opacity selection fill, the same one a theme or density control elsewhere in Settings/Profile already uses in dark mode) — it never introduces a bespoke selected-state color.
- **My Tasks' priority indicators** (the filled/hollow dots in the wireframe above) resolve through the identical `danger`/`warning`/`ink` semantic ramp `AgingBar` and Detected Risks already use in both themes, per `docs/ai/AI_COMMAND_CENTER.md`'s own `status: ok | info | warning | critical` convention — Home does not invent a fifth priority color scale distinct from severity ramps used everywhere else in the product.

Every Storybook story for the four new composed components (`ClassicHome`, `AiHome`, `HomeModeSwitcher`, `TasksPanel`) ships the platform's standard four-way parameter matrix (light/LTR, light/RTL, dark/LTR, dark/RTL), and Home's own Playwright suite captures the same four-way screenshot set at the route level, per `FRONTEND_ARCHITECTURE.md`'s testing convention, for both `home_mode` values — eight total baseline screenshots for one route, reflecting that this single URL is genuinely two different screens depending on who is looking at it.

# Accessibility

Home targets the same WCAG 2.1 AA floor as every other QAYD screen, identically across both languages, both themes, and both modes.

- **Exactly one `<h1>`, regardless of mode.** The page renders inside the shared `<main id="main-content" tabIndex={-1}>` landmark with a single `<h1>` reading "Home" (`الرئيسية`) — not "Dashboard" and not "Command Center" — even though the content beneath it is, structurally, one of those two screens' own regions; every region without a visible heading of its own still carries a real, `VisuallyHidden` `<h2>` via `aria-labelledby`, exactly as `DASHBOARD.md` and `AI_COMMAND_CENTER.md` already require for their own regions.
- **The Mode Switcher is a real, labeled radio group, not a styled div pair.** `HomeModeSwitcher` renders as `role="radiogroup"` with `aria-label={t("home.modeSwitcher")}`, each option a real `role="radio"`/`aria-checked` element with a visible, unambiguous label — never an icon-only toggle at any breakpoint, including the mobile-collapsed form, where each icon still carries its own `aria-label` naming the mode in full.
- **Keyboard path covers the full screen without a mouse.** Tab order flows Urgent Actions' own action button (if present) → the Mode Switcher (arrow-key-navigable within the radio group, `Tab` to leave it) → whichever mode's own region order `DASHBOARD.md` or `AI_COMMAND_CENTER.md` already fixes → My Tasks' rows (Classic only), each a real link or button. Switching mode via keyboard (an arrow key, per standard `radiogroup` semantics) triggers the identical mutation and crossfade a pointer click does; focus remains on the Mode Switcher itself after a mode change rather than being thrown to the top of the newly rendered content, so a keyboard user is never disoriented by an unannounced jump.
- **Realtime and the first-run nudge both announce politely, never assertively.** A new Urgent Actions item, a Task list update, or the appearance of `FirstRunModeNudge` all use `aria-live="polite"` exclusively, matching the platform-wide rule both parent documents already state; the nudge itself is a real, dismissible, focusable element (`aria-label` naming both the message and the dismiss action) rather than a toast that vanishes before an assistive-technology user can act on it.
- **A task's origin is stated in text, not only conveyed by icon.** Every `TasksPanel` row's origin glyph is paired with a real text equivalent available to screen readers (`aria-label` or adjacent visually-hidden text: "Approval," "Risk," "Tax deadline," "Payroll deadline," "Reminder," "Recommendation") — color and iconography are never the only channel, matching `DASHBOARD.md`'s own "color is never the only channel" rule applied here to origin rather than to a threshold state.
- **Permission-gated content is legible, never mysterious, in whichever mode renders.** Exactly as both parent documents already specify for their own regions: a region whose permission the caller lacks is omitted, not shown disabled-and-unexplained; a region that is visible but empty for a business-state reason always names why, in text.

# Performance

- **Zero client-side mode flash.** Per `# Data & State`, `home_mode` is resolved server-side before either compositional branch is referenced in the returned tree, so there is no Cumulative-Layout-Shift or flash-of-wrong-content cost for the single highest-traffic route in the product — a cost a naive client-resolved implementation would otherwise pay on every visit.
- **Neither mode's bundle is paid for by a session that never uses it.** `ClassicHome` and `AiHome` are each `next/dynamic`-split at the component level (not merely their individual chart/AI-dock dependencies, which `DASHBOARD.md` and `AI_COMMAND_CENTER.md` already split on their own terms) — a user whose resolved mode is `classic` never downloads `AiHome`'s own chunk (Ask AI's streaming client, the Command Card shell set) until and unless they actually switch, and vice versa. This is a Home-specific optimization neither parent screen needed to make on its own, because neither one previously had to coexist with the other inside a single route.
- **Budgets are inherited, not loosened.** Classic Home's first-meaningful-paint target is `DASHBOARD.md`'s own stated "under 1.5 seconds," sourced from the same Redis-cached bootstrap bundle; AI Home's first-load JS budget is `AI_COMMAND_CENTER.md`'s own stated "under 180KB gzipped," measured against the same `performance-budgets.json` CI gate — Home does not get a separate, softer budget just because it is a new route; it must clear whichever of the two budgets applies to the mode actually rendered.
- **The Tasks panel and the Mode Switcher add a bounded, small cost.** `TasksPanel`'s query (`homeKeys.tasks()`) is capped to five rows server-side and paginated on "View all," never an unbounded fetch; the Mode Switcher's own mutation is a single small `PATCH` with no cascading invalidation beyond `homeKeys.mode()` itself — switching mode does not invalidate or refetch anything Dashboard's or the Command Center's own components already cache under `dashboardKeys.*`/`commandCenterKeys.*`, since that data was never scoped to a mode in the first place.
- **Web Vitals are tracked per mode, not pooled.** Because `/` is genuinely two different screens depending on `home_mode`, LCP/CLS/INP for this route are tagged with the resolved mode in addition to the company-size band both parent documents already tag by — pooling an AI-mode session's necessarily-richer LCP target against a Classic-mode session's leaner one would produce a meaningless average that flags regressions in neither direction reliably.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A role's `home_mode` has never been set, and the role itself changes before any explicit choice is made (a Warehouse Employee promoted to Finance Manager) | `resolveHomeMode` re-evaluates the role table on every render while `explicit` is `null` — the very next visit to `/` reflects the new role's default with no migration step and nothing to invalidate, since nothing was ever persisted for the "never chosen" case |
| A user explicitly set `home_mode`, then their role later changes | The explicit value is never overwritten by a new role-based default — an Accountant who deliberately chose AI Home and is later promoted to Finance Manager keeps AI Home; a CFO who deliberately chose Classic and is later moved to a narrower custom role keeps Classic. Explicit always outranks role-based, permanently, matching the same "override always wins" precedent `DASHBOARD.md`'s own KPI layout-override edge case already establishes |
| A role/permission set defaults to (or was explicitly set to) `ai`, but the caller actually holds neither `reports.read` nor `ai.chat` — a narrow custom role with an `ai` default that no longer fits it | `resolveHomeMode` serves `classic` for that render only, per the guard already shown in `# Route & Access` — the stored preference itself is left untouched, so a later permission grant restores the AI default automatically with no user action needed; Home never leaves a caller staring at a Command Center composed entirely of omitted, permission-gated panels |
| Symmetric case: a `classic`-defaulted or explicitly-`classic` user holds none of Classic Home's own permissions (`reports.read`, `bank.read`, etc. all absent) | Classic Home already handles this per `DASHBOARD.md`'s own Edge Cases table — the KPI Strip, Chart Region, and Aging Summary render zero tiles rather than a wall, and Quick Actions/My Tasks remain the only regions with content if the caller holds any create permission or has any assigned task; Home does not need a second fallback for this direction, since Classic Home's own degraded-but-not-broken rendering already covers it |
| A company switch fires while Home is open | Identical double mechanism to both parent screens: `queryClient.clear()` then `router.refresh()`, then `router.push('/')` — not `/dashboard`, not `/command-center` — so the newly active company's own permission set is what Home re-resolves against on landing; `home_mode` itself is a user-level, not company-level, preference and does not change across companies |
| Two browser tabs open to `/`; the user switches mode in one | The second tab does not update live — `home_mode` is a server-persisted, per-user preference, not a URL-mirrored filter the way `?branch=` is, and this document does not add a `BroadcastChannel`/cross-tab sync mechanism for it, a deliberate, documented limitation rather than an oversight, consistent with the platform generally not engineering real-time cross-tab sync for a low-stakes preference. The second tab reflects the change on its own next full navigation or refresh |
| A brand-new company, first user, zero posted `journal_lines`, zero bank accounts, onboarding just completed | Whichever mode the new Owner's role defaults to (`ai`) renders its own dedicated first-run empty states throughout — Morning Briefing's "connect a bank account or post your first entry" card, Business Health Score's "not enough data yet" variant, My Tasks is not shown at all in this mode (Classic-only region) — exactly `AI_COMMAND_CENTER.md`'s own new-company posture, unchanged by Home |
| The Mode Switcher's `PATCH` succeeds server-side but the client's optimistic swap was interrupted (a route change fired mid-mutation, e.g., the user clicked a KPI tile a beat after toggling the switch) | The in-flight navigation is not blocked or queued behind the preference write — the mutation completes in the background and the next visit to `/` reflects it regardless of whether the crossfade was ever seen to finish; a preference write is never treated as sensitive enough to gate an unrelated navigation |
| A task's underlying origin record (an approval, a risk flag) is resolved or deleted from its owning screen while its row is still visible in My Tasks | The next `homeKeys.tasks()` refetch (15-second `staleTime`, or an immediate invalidation where the owning mutation's own `onSettled` also purges `homeKeys.tasks()` — a cross-cutting invalidation this document adds alongside `approvalKeys.all`/`dashboardKeys.tiles` in the owning screen's own mutation) removes the row; clicking a row whose origin resolved a moment before the click re-validates immediately and shows "This was already handled" rather than navigating to a now-stale destination, mirroring the identical conflict posture `DASHBOARD.md`'s own Top Recommended Action edge case and `AI_COMMAND_CENTER.md`'s own Approval Center edge case already establish |
| A user wants a printed or exported copy of Home | Not supported, for the identical reason both parent screens already decline it: whichever mode's content is on screen carries AI provenance styling and independently time-stamped, independently cached regions that have no defensible meaning as a single-instant paper record. A user needing a shareable artifact is directed to the relevant financial statement or report export exactly as `DASHBOARD.md` and `AI_COMMAND_CENTER.md` already specify |

# End of Document
