# AI Home Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: AI_HOME_SCREEN
---

# Purpose

"AI Home" is not a third screen. It is the name for a routing decision: whether the Command Center — fully specified as a product surface in `docs/ai/AI_COMMAND_CENTER.md` and as a frontend implementation in `docs/frontend/AI_COMMAND_CENTER.md` — or the conventional overview specified in `docs/frontend/DASHBOARD.md` is what a given user's session resolves to the instant they open QAYD, switch companies, finish onboarding, or click the first item in the Sidebar. This document is the concrete route realization of that decision: the stored preference that drives it, the redirect logic that reads it, the toggle a user flips to change it, and the one small piece of shared chrome — a Home-mode switcher — that both Home-capable screens carry so neither is ever more than one tap away from the other. It does not re-derive a single panel, agent behavior, or interaction already specified for the Command Center at `app/(app)/command-center/page.tsx`; where this document names Morning Briefing, Ask AI, AI Insights, Detected Risks, Approval Center, or Business Health Score, it is pointing at the exact widgets `docs/ai/AI_COMMAND_CENTER.md` and `docs/frontend/AI_COMMAND_CENTER.md` already own, never re-specifying them.

When a user's resolved mode is **AI Home**, `/command-center` is what "Home" means for them: the destination `app/(auth)/login/page.tsx` and the post-MFA flow send them to, what the Sidebar's first item points at, and what a company switch returns them to. Six of that screen's panels are what make AI Home recognizably itself the instant it paints — the Morning Briefing at the top, Ask AI docked and reachable from anywhere on the screen, the AI Insights feed, the Detected Risks radar, the Approval Center queue, and the Business Health Score — and this document treats those six, collectively, as AI Home's signature, because every role that lands here by default sees at minimum the first and the last of them (see `# Route & Access`). When a user's resolved mode is **conventional**, `/dashboard` is Home instead: KPI tiles, a revenue/expense chart, aging summaries, recent activity, and quick actions, with AI narration present only as a condensed rail, exactly as `docs/frontend/DASHBOARD.md` specifies. Both routes exist, for every user, at all times, regardless of which one is currently Home — AI Home is a default, never a wall, and neither screen is ever hidden or deleted because of the other's preference state.

This document exists because two earlier documents left the exact redirect target underspecified once read together. `docs/ai/AI_COMMAND_CENTER.md` and `docs/frontend/AI_COMMAND_CENTER.md` both state, correctly at the time each was written, that login and post-MFA "send an authenticated user to `/command-center`" unconditionally, and that `/dashboard` survives only "as a permanent redirect to `/command-center`." `docs/frontend/DASHBOARD.md`, written after, correctly restores `/dashboard` as its own real, non-redirecting route — but in doing so it does not restate what a fresh login now resolves to, since that answer depends on a preference `docs/frontend/DASHBOARD.md` had no reason to define. This document is that reconciliation, and the answer is: **neither earlier claim was wrong; both describe one branch of a decision that is now per-user.** Post-login and post-MFA redirect to whichever of the two routes the user's own `dashboard_layout` preference resolves to — `/command-center` for `ai_home`, `/dashboard` for `conventional` — and both source documents remain accurate descriptions of their own branch. `docs/frontend/NAVIGATION_SYSTEM.md`'s company-switch handler, which still hardcodes `router.push('/dashboard')`, is amended by this document on that one point, exactly as `docs/frontend/DASHBOARD.md` itself amended `docs/ai/AI_COMMAND_CENTER.md`'s redirect clause before it — see `# Route & Access` and `# Edge Cases`.

The preference this document defines is not a column invented from nothing. `docs/frontend/PROFILE.md`'s `user_preferences` table already reserves a `dashboard_layout` key among its "free-form UI-chrome preferences," attributing it to `docs/frontend/DASHBOARD.md` without `docs/frontend/DASHBOARD.md` ever having given it a shape — that document's own personalization feature (hiding/reordering KPI tiles) persists to a different table entirely (`user_dashboard_layouts.layout_overrides`, via its own dedicated endpoint). This document is the first to give `user_preferences.dashboard_layout` a concrete schema: a two-value enum, `'ai_home' | 'conventional'`, `NULL` in storage until a user chooses explicitly. Every other key already living beside it in that table (`theme`, `density`, `text_scale`, `sidebar_collapsed`, `ai_rail_open`, `table_density`, `calendar_default_view`) is untouched by anything in this document.

Three constraints this document inherits without re-arguing them: the frontend still computes nothing (see `# Route & Access` for exactly which party resolves a `NULL` preference, and it is not the browser); AI is visible, labeled, and never silent on whichever screen is Home, per each destination's own `# AI Integration`; and RBAC is enforced by the API on every panel regardless of how a user arrived at the screen — being Home changes navigation, never permission.

# Route & Access

| | |
|---|---|
| Route rendered for AI Home | `app/(app)/command-center/page.tsx` — unchanged; see `docs/frontend/AI_COMMAND_CENTER.md → Route & Access` for its Server Component, permission-gated panels, and prefetch strategy |
| Route rendered for conventional Home | `app/(app)/dashboard/page.tsx` — unchanged; see `docs/frontend/DASHBOARD.md → Route & Access` |
| Route this document adds | `app/(app)/page.tsx` — the bare authenticated-group index; a Server Component with no UI of its own, mirroring the exact no-UI-resolver-then-`redirect()` pattern `docs/frontend/ONBOARDING.md`'s own resume entry (`app/onboarding/page.tsx`) already establishes for exactly the same kind of "which URL does a bare segment actually mean" question |
| Gating permission | None — identical to both destinations, neither of which carries a single visibility permission (`docs/frontend/AI_COMMAND_CENTER.md`, `docs/frontend/DASHBOARD.md`). Choosing *which* Home to land on is itself permission-free for every authenticated user, matching `docs/frontend/PROFILE.md`'s stated posture for the sibling `theme`/`density` keys in the same table: "there is nothing here another user's RBAC grant could need to protect." |
| Nav item | Sidebar position 1 (`docs/frontend/NAVIGATION_SYSTEM.md`'s module map). Its `id` stays `dashboard` for continuity with any existing active-state matching keyed on it; its `href` and `labelKey` become functions of the resolved mode — see below |
| Preference key | `user_preferences.dashboard_layout` — `'ai_home' | 'conventional'` as read back by the API (never `NULL` on the wire; see resolution order below), `NULL` only in storage before any explicit choice has been made |

## Resolution order

A user's effective Home is resolved in this fixed order, and — consistent with `docs/frontend/DASHBOARD.md`'s constraint that "the frontend computes nothing" — every step below except the final client redirect happens server-side, in Laravel, not in a Next.js Server Component and never in the browser:

1. **Explicit choice.** `user_preferences.dashboard_layout` is non-`NULL` because the user (or an admin acting on their behalf) has set it via `HomeModeToggle` (see `# Components Used`) or the Profile Preferences page. This always wins, permanently, until changed again.
2. **Role-based default.** If the column is `NULL`, the API resolves it at read time against the caller's primary role for the active company, using the table below, and returns the *effective* value from `GET /api/v1/users/me/preferences` — the endpoint never actually emits `dashboard_layout: null` to a client. The stored column stays `NULL` so a later platform-wide default change still benefits every user who never explicitly chose.
3. **Platform fallback.** A role with no entry in the table below (a fully custom role with no natural analogue) resolves to `conventional` — the deterministic, numbers-first surface — never a fabricated AI-forward default for a role the platform cannot characterize. This mirrors the exact "no fabricated preference… falls back to role-based defaults… until real behavior accrues" posture `docs/ai/memory/USER_MEMORY.md` already establishes for a different but structurally identical problem.

| Role | Default Home | Why |
|---|---|---|
| Owner | `ai_home` | Sees all seven Command Center panels; the platform's flagship "workforce already did the work" experience is built for this seat first |
| CFO | `ai_home` | Same panel breadth as Owner per `docs/frontend/AI_COMMAND_CENTER.md`'s per-role table |
| Finance Manager | `ai_home` | All seven panels; Approval Center scoped to their own assigned steps |
| Senior Accountant | `ai_home` | Same as Finance Manager |
| Accountant | `conventional` | Sees only four of seven Command Center panels day-to-day; a domain-scoped `/dashboard` KPI strip serves a ledger-keeping role better than a partial workforce briefing |
| Auditor | `conventional` | An audit workflow starts from the numbers, not from a decision briefing; AI Home is one tap away whenever a risk needs the fuller radar |
| External Auditor | `conventional` | Time-boxed, read-only access (`docs/ai/AI_COMMAND_CENTER.md → User Journeys §3`) defaults to the more conservative surface |
| Payroll Officer, Inventory Manager | `conventional` | Narrow, single-module permission slice; `docs/frontend/DASHBOARD.md`'s own role-scoped KPI Strip already serves this case by design |
| Sales Manager / Sales Employee, Purchasing Manager / Purchasing Employee | `conventional` | Same reasoning — `docs/frontend/DASHBOARD.md → Route & Access` already documents a Sales-scoped KPI subset for exactly this seat |
| Warehouse Employee | `conventional` | `docs/frontend/DASHBOARD.md` already describes this role's Home as "Quick Actions… an empty-but-not-broken KPI Strip"; nothing about that changes here |
| Read Only | `conventional` | No action surface to approve or dismiss; a numbers overview is the more honest default |

Every row above is a **default**, never an enforcement. An Owner who prefers the numbers-first view, or a Warehouse Employee who wants the workforce briefing anyway, sets `dashboard_layout` explicitly and the table above never applies to them again.

## Resolving and redirecting

```ts
// lib/navigation/home.ts
export type HomeLayoutMode = "ai_home" | "conventional";

export function homeRouteFor(mode: HomeLayoutMode): "/command-center" | "/dashboard" {
  return mode === "ai_home" ? "/command-center" : "/dashboard";
}
```

```tsx
// app/(app)/page.tsx — the bare authenticated index; never linked to directly by the UI,
// exactly like docs/frontend/ONBOARDING.md's app/onboarding/page.tsx resume entry
import { redirect } from "next/navigation";
import { cookies } from "next/headers";
import { apiServer } from "@/lib/api/server-client";
import { homeRouteFor, type HomeLayoutMode } from "@/lib/navigation/home";

export default async function AppIndexPage() {
  const cookieHint = (await cookies()).get("qayd_home_pref")?.value;
  if (cookieHint === "ai_home" || cookieHint === "conventional") {
    redirect(homeRouteFor(cookieHint)); // zero API calls on the common path — see # Performance
  }
  // Cold path: no cookie yet (new device, cleared cookies, or first-ever visit).
  // dashboard_layout is never null on the wire — see # Route & Access → Resolution order.
  const { data } = await apiServer.get<{ dashboard_layout: HomeLayoutMode }>("/users/me/preferences");
  redirect(homeRouteFor(data.dashboard_layout));
}
```

`app/(auth)/login/page.tsx` and the post-MFA handler, which `docs/frontend/AI_COMMAND_CENTER.md` describes as sending an authenticated user to a hardcoded `/command-center`, now redirect to `'/'` instead and let the resolver above make the call — the one exception being `docs/frontend/ONBOARDING.md`'s existing, unchanged rule that a company whose `onboarding_status` is still `in_progress` redirects to `/onboarding` first, ahead of any Home resolution. `docs/frontend/NAVIGATION_SYSTEM.md`'s company-switch handler is amended identically — its documented three-step sequence (`queryClient.clear()`, `router.refresh()`, `router.push('/dashboard')`) becomes `router.push('/')` on the third step, so a company switch and a fresh login resolve Home through the exact same single code path rather than two independently-maintained ones.

```ts
// lib/navigation/nav-items.ts — amends docs/frontend/NAVIGATION_SYSTEM.md's static entry
{
  id: "dashboard", // unchanged, for continuity with existing active-state matching
  labelKey: (mode: HomeLayoutMode) => (mode === "ai_home" ? "nav.aiHome" : "nav.dashboard"),
  icon: "LayoutDashboard",
  href: (mode: HomeLayoutMode) => homeRouteFor(mode),
  permission: null,
}
```

Because the nav item's `href` now depends on `dashboard_layout`, `<Sidebar>` reads it from the same `SessionProvider` context that already carries `me.active_company.permissions` (`docs/frontend/FRONTEND_ARCHITECTURE.md → GET /me, permissions, and the SessionProvider`) — one more field on an object the Sidebar already consumes, not a new fetch.

# Layout & Regions

Neither destination screen's own panel grid changes. This document adds exactly one new region to each, in the way each already accommodates that kind of control — it introduces no new `widget_id`, no new grid cell, and no new data-fetching component:

- `/dashboard`'s existing Filter Bar row (`docs/frontend/DASHBOARD.md → Layout & Regions`, full width, sticky below the Topbar) gains `HomeModeToggle` at its inline-end, after `BranchSelect`.
- `/command-center`'s own wireframe carries no header row above `morning_briefing` by design — the screen's greeting is Morning Briefing's own first line ("Good morning, Fahad…"), not a page title. This document adds a single new full-width, low-height utility strip above it, for this one control alone, thin enough to read as chrome rather than as an eighth panel.

```
AI Home (/command-center):
┌───────────────────────────────────────────────────────────────────────┐
│                                              [ AI Home ● | Dashboard ○ ]│  ← NEW: Home-mode strip, chrome-only
├───────────────────────────────────────────────────────────────────────┤
│  morning_briefing                                      (4x1, full)     │  ← "front and center" #1 — unchanged
├────────────────────┬────────────────────┬─────────────────────────────┤
│ business_health     │ cash_flow_status    │ ai_insights_feed            │  ← Health #2 · Insights #3
│ _score       (1x1)  │               (2x1) │                    (1x2)    │
├────────────────────┴────────────────────┤                             │
│  detected_risks_radar             (2x1)  │  (continues below the fold) │  ← Risks #4
├─────────────────────────────────────────┴─────────────────────────────┤
│  approval_center_queue                                (4x1, full)      │  ← Approvals #5
└───────────────────────────────────────────────────────────────────────┘
                      [ 💬 Ask AI — docked, bottom-inline-end ]            ← Ask AI #6, front and center

Dashboard (/dashboard), unchanged rows per docs/frontend/DASHBOARD.md, toggle appended:
┌─────────────────────────────────────────────────────────────────────────────┐
│  Dashboard   [ This fiscal period ▾ ] [Branch ▾]     [ AI Home ○ | Dashboard ●]│ ← Filter Bar, toggle at inline-end
├─────────────────────────────────────────────────────────────────────────────┤
│  ...KPI Strip / Chart Region / AI Summary Rail / Aging / Recent Activity...  │ ← unchanged
└─────────────────────────────────────────────────────────────────────────────┘
```

Full region sizing, gutters, and card treatment for the seven-panel grid are exactly `docs/frontend/AI_COMMAND_CENTER.md → Layout & Regions`; Dashboard's own five regions are exactly `docs/frontend/DASHBOARD.md → Layout & Regions`. Rendering is identical regardless of entry path — a user who reaches `/command-center` as their resolved Home, from Dashboard's "View all in AI Command Center" link, from the Sidebar, or from `⌘K` always sees the same seven-panel grid; *being* Home changes navigation and which utility strip renders above it, never the panel tree itself.

**The always-visible six.** Morning Briefing, Ask AI, AI Insights, Detected Risks, Approval Center, and Business Health Score are what this document treats as AI Home's signature set, because they are the panels present across the widest span of roles. Even at the thinnest permission slice documented in `docs/frontend/AI_COMMAND_CENTER.md`'s Edge Cases ("a user holds permission for zero panels… the grid renders Morning Briefing… and Ask AI only"), AI Home never degenerates to a blank landing — a guarantee that matters specifically here, because Home is the one screen every session is certain to load.

# Components Used

Every panel-level component on this screen is exactly `docs/frontend/AI_COMMAND_CENTER.md`'s catalogue (`AiCardShell`, `ConfidenceBadge`, `AIProposalPanel`, `ApprovalCard`, `KpiTile`, `TrendSparkline`, `StatusPill`, `DataTable`, `Badge`, `Tooltip`, `Popover`, `Sheet`, `Skeleton`, `WidgetErrorBoundary`, the Vercel AI SDK's `useChat`) when the resolved mode is `ai_home`, and exactly `docs/frontend/DASHBOARD.md`'s catalogue (`KpiTile`, `AmountCell`, `AgingBar`, `PeriodPicker`, `RevenueExpenseChart`, `AiSummaryRail`, and the rest) when it is `conventional`. This document introduces exactly one new component, shared by both:

| Component | Source | Used for |
|---|---|---|
| `HomeModeToggle` | `components/dashboard/home-mode-toggle.tsx` (**new**) | The two-option AI Home / Dashboard switcher rendered in `/command-center`'s new utility strip and `/dashboard`'s existing Filter Bar; built on Radix `ToggleGroup` (`@radix-ui/react-toggle-group`), the same primitive family `Tooltip`/`Tabs` already draw from |

```tsx
// components/dashboard/home-mode-toggle.tsx
"use client";
import { useRouter } from "next/navigation";
import * as ToggleGroupPrimitive from "@radix-ui/react-toggle-group";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api/client";
import { profileKeys } from "@/lib/api/query-keys";
import { homeRouteFor, type HomeLayoutMode } from "@/lib/navigation/home";
import { useTranslations } from "@/lib/i18n/use-translations";
import { announce } from "@/lib/a11y/live-region"; // see # Accessibility

export function HomeModeToggle({ current }: { current: HomeLayoutMode }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const t = useTranslations("home");

  const { mutate, isPending } = useMutation({
    mutationFn: (mode: HomeLayoutMode) => api.patch("/users/me/preferences", { dashboard_layout: mode }),
    onMutate: (mode) => {
      document.cookie = `qayd_home_pref=${mode}; path=/; max-age=31536000; samesite=lax`;
      queryClient.setQueryData(profileKeys.preferences(), (old: any) => ({ ...old, dashboard_layout: mode }));
    },
    onError: (_err, mode) => {
      // Roll the cookie and cache back; navigation already happened and is not reverted — see # States
      queryClient.invalidateQueries({ queryKey: profileKeys.preferences() });
    },
  });

  function handleChange(mode: HomeLayoutMode | "") {
    if (!mode || mode === current) return;
    mutate(mode);
    announce(mode === "ai_home" ? t("switchedToAiHome") : t("switchedToDashboard"));
    router.push(homeRouteFor(mode));
  }

  return (
    <ToggleGroupPrimitive.Root
      type="single"
      value={current}
      onValueChange={handleChange}
      aria-label={t("modeSwitcherLabel")}
      disabled={isPending}
      className="inline-flex items-center gap-1 rounded-md border border-ink-6 p-1"
    >
      <ToggleGroupPrimitive.Item
        value="ai_home"
        className="rounded-sm px-3 py-1.5 text-label data-[state=on]:bg-accent-subtle data-[state=on]:text-accent"
      >
        {t("aiHome")}
      </ToggleGroupPrimitive.Item>
      <ToggleGroupPrimitive.Item
        value="conventional"
        className="rounded-sm px-3 py-1.5 text-label data-[state=on]:bg-ink-3"
      >
        {t("dashboard")}
      </ToggleGroupPrimitive.Item>
    </ToggleGroupPrimitive.Root>
  );
}
```

The identical component, wrapped in a labeled settings row rather than a compact pill, also renders inside `app/(app)/profile/preferences/page.tsx` beside `ThemeToggle` (`docs/frontend/PROFILE.md → Preferences section`) — same mutation, same query key, different chrome, because a deliberate Settings visit and an in-context flip are different interactions over one persisted value. No panel component on either destination screen is altered, wrapped, or re-themed by this document.

# Data & State

| Purpose | Endpoint | Notes |
|---|---|---|
| Read Home preference | `GET /api/v1/users/me/preferences` | `docs/frontend/PROFILE.md`'s existing endpoint; `dashboard_layout` is now typed `'ai_home' \| 'conventional'`, never `null` on the wire (see Resolution order) |
| Write Home preference | `PATCH /api/v1/users/me/preferences` | Body `{ "dashboard_layout": "ai_home" \| "conventional" }` — the identical mutation shape `ThemeToggle` already uses for `theme` |
| Every panel on `/command-center` | Unchanged | Exactly `docs/frontend/AI_COMMAND_CENTER.md → Data & State`'s table — `GET /api/v1/ai/briefings/{date}`, `GET /api/v1/ai/health-score`, `GET /api/v1/banking/accounts` + `GET /api/v1/ai/cash-flow/forecast`, `GET /api/v1/ai/risks?category=all`, `GET /api/v1/approvals`, `GET /api/v1/ai/insights?since=`, `POST /api/v1/ai/chat` |
| Every panel on `/dashboard` | Unchanged | Exactly `docs/frontend/DASHBOARD.md → Data & State`'s table |

No new query-key factory is introduced — `dashboard_layout` is a field on the existing preferences resource, not a new resource:

```ts
// lib/api/query-keys.ts — profileKeys, unchanged from docs/frontend/PROFILE.md
export const profileKeys = {
  me: () => ["users", "me"] as const,
  preferences: () => ["users", "me", "preferences"] as const, // dashboard_layout lives here
  // …sessions, mfaFactors, myTokens, dataExport unchanged
};
```

`profileKeys.preferences()` keeps the exact 5-minute `staleTime`, no `refetchOnWindowFocus`, `docs/frontend/PROFILE.md` already assigns the whole resource — "user-driven, rarely changed by anything but the user's own action in this same tab" applies to `dashboard_layout` precisely as it does to `theme`.

**Realtime.** `RealtimeProvider` mounts exactly once, in `(app)/layout.tsx`, regardless of which Home is resolved (`docs/frontend/FRONTEND_ARCHITECTURE.md → Layout nesting and composition`) — the shared WebSocket connection's existence is a shell-level fact, not a Home-mode fact, so a user on `/dashboard` and a user on `/command-center` have identical realtime coverage the instant either screen mounts. AI Home's own channels are exactly `docs/frontend/AI_COMMAND_CENTER.md → Data & State`'s table: `private-company.{id}.ai-jobs` (Morning Briefing regeneration status, Ask AI typing/thinking status), `private-company.{id}.dashboard.{dashboard_id}` (Business Health Score, Cash Flow Status, Detected Risks, AI Insights), and `private-company.{id}.approvals` (Approval Center). `HomeModeToggle` subscribes to nothing.

**AI agents feeding this screen**, restated compactly from `docs/ai/AI_COMMAND_CENTER.md → Agent coverage map`: `CEO_ASSISTANT` synthesizes Morning Briefing and orchestrates every Ask AI turn (see `# Interactions & Flows`); `CFO_AGENT` and `REPORTING_AGENT` compute Business Health Score; `TREASURY_MANAGER` and `FORECAST_AGENT` own Cash Flow Status where it is in view; every risk-originating agent (`FRAUD_DETECTION`, `COMPLIANCE_AGENT`, `AUDITOR`, `TREASURY_MANAGER`, `INVENTORY_MANAGER`, `PAYROLL_MANAGER`) feeds Detected Risks; `APPROVAL_ASSISTANT` routes and `FRAUD_DETECTION` holds Approval Center rows; and all fifteen agents, de-duplicated by `REPORTING_AGENT`, feed AI Insights. None of this changes because the screen happens to be Home rather than a secondary destination.

# Interactions & Flows

**Flipping the toggle.** Clicking the inactive option in `HomeModeToggle` is instant and requires no confirmation dialog — unlike a Reject action or a Fraud Detection dismissal, changing which screen greets you next session is low-stakes and fully reversible with the same click in reverse, so it is treated with the platform's lightest interaction weight rather than its heaviest. The click does two things in the same handler, not two separate steps: it writes `qayd_home_pref` and calls `PATCH /api/v1/users/me/preferences` (optimistic, per the snippet in `# Components Used`), and it navigates via `router.push`. Navigation is never blocked on the mutation's response — see `# Performance`.

**First login and onboarding completion.** `docs/frontend/ONBOARDING.md`'s final step already redirects to a resolved destination rather than a hardcoded one once `companies.onboarding_status` flips to `complete`; that redirect now targets `'/'` and lets `app/(app)/page.tsx` apply the same resolution order as every other entry point, so a first-time user lands on their role's default Home exactly as a returning user would, with no separate "welcome, choose your Home" step needed.

**Company switch.** `docs/frontend/NAVIGATION_SYSTEM.md`'s documented sequence — `queryClient.clear()` (every cached query belonged to the previous company), `router.refresh()` (re-runs `(app)/layout.tsx`, re-resolving `active_company` and the permissions array), then a push — is unchanged in its first two steps and amended only in its third, per `# Route & Access`, to push to `'/'` rather than a hardcoded `/dashboard`. Switching from a company where a user is Owner to one where they are a Sales Employee can therefore land them on a structurally different Home, correctly, in the same two clicks it already took.

**How the CEO Assistant orchestrates Ask AI and Morning Briefing here.** Every conversational turn on this screen — a typed Ask AI question, the nightly Morning Briefing generation job, a "Why did this change?" tap on Business Health Score — enters the same LangGraph-style state machine `docs/ai/agents/CEO_AGENT.md → Reasoning & Prompt Strategy` specifies in full: a **Context Loader** pulls the caller's role, permissions, active company/branch, recent turns, and relevant `ai_memory`; a fast-tier **Intent Classifier** resolves a single-domain read directly (embedding-similarity match, no LLM routing pass) when confidence clears 0.75; anything ambiguous, cross-domain, or simulation-shaped instead goes to a frontier-tier **Router/Planner** that decomposes the request into one or more sub-tasks, each bound to exactly one of the fourteen specialist agents; a **Parallel Dispatch** stage runs the bound agents concurrently under their own timeout budgets (default 8 seconds for a chat turn); an **Aggregator/Synthesizer** merges their outputs and computes blended confidence as the *minimum* of every load-bearing contributor's own confidence, never an average — a claim resting on a 62-confidence Forecast Agent projection and a 98-confidence Treasury Manager balance is reported at 62, with the reasoning trace naming which half is weaker; a **Guardrail Gate** checks whether the answer implies a sensitive action and, if so, sets `requires_approval: true` pointing at the *owning* agent's `ai_decisions` row, never a CEO-Assistant-authored one; and a **Response Formatter** persists the `ai_messages` turn and streams it back. This is why Ask AI on this screen can answer "why did revenue change" by dispatching to `REPORTING_AGENT` alone in single-digit milliseconds, but "should I take the Diyar order at these terms" fans out to `TREASURY_MANAGER`, `CFO_AGENT`, and `FORECAST_AGENT` and takes a full planning pass — the same orchestrator, two different paths through it, chosen by the classifier rather than by the frontend.

**Morning Briefing deep links.** Tapping a line in the briefing calls `router.push()` to the panel that owns it with a `?highlight={sourceId}` param the target panel reads to auto-expand that exact card, exactly as `docs/frontend/AI_COMMAND_CENTER.md → Interactions & Flows` specifies. On AI Home specifically, any `status: "held"` Approval Center row (a Fraud Detection hold) and any open Urgent Action render above Morning Briefing in DOM source order — a placement that carries more weight here than it would if the Command Center were reached as a secondary destination, because Home is the one screen every session is guaranteed to paint first.

**Ask AI, front and center.** Beyond the always-present docked trigger `docs/frontend/AI_COMMAND_CENTER.md` already specifies, AI Home's closed trigger carries a rotating ghost-text example tied to today's own briefing — "Ask: why did cash dip on Jul 29?" for the running example in `docs/ai/AI_COMMAND_CENTER.md` — generated from the same `sources` the briefing itself cites, so the very first thing a user notices about the AI surface is a question worth asking, not an empty input waiting to be filled. Opening it seeds the conversation with whichever panel's context the user was last looking at, per the existing contract; this document adds only the closed-state tease, not a new conversation mechanic.

# AI Integration

Every AI-authored element on this screen, in either mode, carries the platform's fixed contract unchanged: a `confidence_score`, a `reasoning`, and `sources`, wrapped in `AiCardShell`, normalized through `ConfidenceBadge`'s `normalizeConfidence(raw, sourceField)` to a canonical 0–1 range (`docs/frontend/COMPONENT_LIBRARY.md → ConfidenceBadge`). This document adds no new confidence indicator and no new card shape.

**Approval affordances.** `ApprovalCard`'s `confidence` prop (`number | null`) is populated only for AI-originated requests inside the Approval Center panel — a human-submitted journal entry pending a peer's sign-off has no confidence to show, and `requestedBy` is correspondingly omitted for pure AI proposals since attribution there belongs to `agent_code`. Approve calls `POST /api/v1/approvals/{id}/approve` with a client-generated `Idempotency-Key`, optimistically flips that step to `approved`, and rolls back on any `4xx`/`5xx`; Reject requires a non-empty reason before `Confirm reject` un-disables. `status: "held"` rows — Fraud Detection freezes, never deletions — render the `negative`-token left border ahead of ordinary `pending` items regardless of SLA proximity, so a held payment is unmistakable before its text is even read. This is the identical widget the full-page Approval Center queue at `app/(app)/approvals/page.tsx` renders (per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s route tree), condensed here to whatever fits the panel's own row budget — the two are one data source, never two implementations.

**The three-button pattern and the guardrail it encodes.** Any AI Insights card that has graduated to carrying a `recommended_action` renders through `AIProposalPanel`'s three buttons — **Do it**, **Send for approval**, **Dismiss** — where `can_execute_directly` is a field the API computes from the item's autonomy level against the company's configured `ai_automation_rules` threshold, never a value the client derives itself. When `can_execute_directly` is `false`, "Do it" does not render disabled; it does not render at all, which is what makes the CEO Assistant's own system-prompt rule — "You NEVER execute, approve, or authorize a sensitive action yourself. You may only surface it, with `requires_approval=true`" (`docs/ai/agents/CEO_AGENT.md → Reasoning & Prompt Strategy`) — structurally true on this screen rather than a convention a future change could quietly erode. Below 60% confidence, `AIProposalPanel` additionally disables "Do it" regardless of the automation-rule outcome (`docs/frontend/COMPONENT_LIBRARY.md → ConfidenceBadge`), so a technically-permitted but weakly-evidenced action still stops at a human.

**Business Health Score and Detected Risks** carry their confidence exactly as `docs/frontend/AI_COMMAND_CENTER.md → AI Integration` specifies — a raw `financial_exposure` figure renders in plain tabular ink, never forced into a `negative`-colored numeral, because severity is already carried by `AiCardShell`'s own border and badge and colorizing the amount too would be decoration layered on top of information, not new information.

# States

Every panel on either destination screen ships its own four states exactly as `docs/frontend/AI_COMMAND_CENTER.md → States` and `docs/frontend/DASHBOARD.md → States` already specify — this document changes none of them. It adds two states specific to the machinery it introduces:

| Surface | Loading | Empty | Error |
|---|---|---|---|
| `HomeModeToggle` | Renders with `current` already resolved server-side before first paint (see `# Route & Access`) — it never shows its own loading spinner or skeleton, since the value driving it is known before the component exists | Not applicable — always exactly one of two states; a `NULL` stored preference is resolved to a role default before the toggle ever mounts, so it never renders a third, "unset" appearance | A failed `PATCH` rolls the cookie and the TanStack Query cache back to the last confirmed server value (`onError` in the snippet above) and shows a toast — "Couldn't save — still showing {mode} next time." The navigation itself is never rolled back: a user who clicked meant to see the other screen right now, whether or not the choice persists for their next login |
| Root resolver (`app/(app)/page.tsx`) | Not applicable — a server redirect, never a rendered page; a slow resolution shows the browser's own native pending-navigation indicator, not a QAYD skeleton | Not applicable | If `GET /api/v1/users/me/preferences` itself fails (network error, `5xx`) during the cold path, the resolver does not surface a 500 at the front door of the entire application — it falls back to `redirect("/dashboard")`, the more conservative, fully deterministic surface, logs the failure server-side, and lets the user reach AI Home manually via `HomeModeToggle` once any screen has painted |

# Responsive Behavior

Each destination screen's own reflow is untouched and remains fully owned by its own document: the seven-panel grid's per-breakpoint table (`docs/frontend/AI_COMMAND_CENTER.md → Responsive Behavior`) and Dashboard's own region collapse (`docs/frontend/DASHBOARD.md → Responsive Behavior`) apply exactly as written. This document adds only `HomeModeToggle`'s own behavior:

- At `lg` (1024px) and above, the toggle renders inline in its host row (the new utility strip on AI Home, the Filter Bar's inline-end on Dashboard) exactly as drawn in `# Layout & Regions`.
- Below `md` (768px), where both host rows themselves compress (Dashboard's Filter Bar stacks its own controls per `docs/frontend/DASHBOARD.md`), the toggle relocates into the shared header's overflow affordance rather than shrinking its two labels past legibility or being dropped — matching `docs/frontend/RESPONSIVE_DESIGN.md`'s general rule that a capability is never removed at a narrower breakpoint, only relocated, and its explicit P1-defect treatment of any control that disappears below a given width for a role that otherwise holds it.
- The toggle's two segments each meet the platform's 44px minimum touch target with an 8px gap between them (`docs/frontend/RESPONSIVE_DESIGN.md → Touch Targets & Gestures`), identical to the rule `docs/frontend/AI_COMMAND_CENTER.md` already applies to Approval Center's Approve/Reject pair.

# RTL & Localization

`HomeModeToggle` mirrors automatically through Tailwind's logical utilities (`ms-*`/`me-*`, `gap-*`) with zero conditional code, per the same ESLint-enforced platform rule `docs/frontend/AI_COMMAND_CENTER.md → RTL & Localization` cites for the rest of the screen. Nothing about this document changes the platform's numeral, date, or AI-prose rules already fixed for either destination — a `ConfidenceBadge` percentage or a briefing's cited amount reads exactly as those documents specify regardless of which screen is Home.

Arabic microcopy for the pieces this document introduces, authored directly rather than machine-translated, per `docs/frontend/DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief:

| Context | English | Arabic |
|---|---|---|
| Toggle option | AI Home | الرئيسية الذكية |
| Toggle option | Dashboard | لوحة المعلومات |
| Toggle `aria-label` | Switch home screen mode | تبديل نمط الشاشة الرئيسية |
| Live-region announcement | Switched to AI Home | تم التبديل إلى الرئيسية الذكية |
| Live-region announcement | Switched to Dashboard | تم التبديل إلى لوحة المعلومات |
| Settings row label | Home screen | الشاشة الرئيسية |
| Settings row description | Choose what greets you first — the AI workforce's briefing, or the classic numbers overview | اختر ما يستقبلك أولاً — موجز فريق الذكاء الاصطناعي، أو نظرة الأرقام التقليدية |
| Save-failure toast | Couldn't save — still showing {mode} next time | تعذر الحفظ — سنعرض {mode} في المرة القادمة |

# Dark Mode

`HomeModeToggle` follows `docs/frontend/DARK_MODE.md`'s `class`/`data-theme="dark"` strategy with no bespoke theme logic of its own. Its active "AI Home" state uses `accent`/`accent-subtle` deliberately — `docs/frontend/DESIGN_LANGUAGE.md`'s rule that the accent is reserved for "AI provenance and primary actions only" applies cleanly here, since selecting the AI-forward Home mode *is* an AI-provenance signal in exactly the sense that rule protects. Its active "Dashboard" state uses the neutral `ink-3` fill, deliberately without accent, so the two states stay visually distinct in the same way the platform already insists AI-authored content stay visually distinct from deterministic content elsewhere on both screens. Both values carry their own calibrated dark-mode definition already present in `docs/frontend/DESIGN_LANGUAGE.md`'s token table — this document introduces no new token and no new chart or SVG surface, so `docs/frontend/DARK_MODE.md → Charts & Data Viz In Dark` does not apply to anything this document adds.

# Accessibility

`HomeModeToggle` is a real Radix `ToggleGroup` — roving `tabIndex`, arrow-key navigation between its two options, `Space`/`Enter` to activate — reachable exactly as `Tabs` already is elsewhere in the component family (`docs/frontend/COMPONENT_LIBRARY.md`). Because flipping it is a full navigation rather than a cosmetic change, it announces itself: an `aria-live="polite"` confirmation fires once, immediately before the `router.push` (see the `announce()` call in `# Components Used`), matching the Command Center's own "calibrated to avoid noise" live-region posture (`docs/frontend/AI_COMMAND_CENTER.md → Accessibility`) rather than introducing a louder pattern for a change the user just explicitly requested. The toggle's two options are always both present and both enabled for every authenticated user regardless of role or permission — the one control on either Home screen that is never permission-gated, since choosing which Home to land on carries no RBAC weight of its own, exactly as `docs/frontend/PROFILE.md` already treats `theme` and `density`.

Everything else on whichever screen is Home inherits that screen's own accessibility contract unchanged: `docs/frontend/AI_COMMAND_CENTER.md → Accessibility`'s single `<h1>`, its `VisuallyHidden` per-panel headings, its three-tier live-region calibration, and its keyboard order for `/command-center`; `docs/frontend/DASHBOARD.md`'s own equivalent for `/dashboard`. This document's toggle takes the first position in that existing tab order, before Urgent Actions, matching its visual placement at the very top of the screen.

# Performance

The redirect this document introduces must not cost a visible round trip on the common path. The `qayd_home_pref` cookie — written on every successful `PATCH`, mirroring the exact precedent `docs/frontend/RESPONSIVE_DESIGN.md` already sets for `qayd_sidebar` (`document.cookie = …; path=/; max-age=31536000; samesite=lax`) — lets `app/(app)/page.tsx` redirect from the cookie alone, with zero API calls, on every login after the first. The `GET /api/v1/users/me/preferences` round trip on the cold path is paid once per device, ever, never once per session.

The redirect itself is a standard server-side `redirect()` from a Server Component with no client JavaScript and no rendered payload — a cost class identical to the `/dashboard` → `/command-center` permanent redirect this same product already shipped and accepted before `docs/frontend/DASHBOARD.md` restored `/dashboard` as a real route, so this is a known, already-accepted quantity in QAYD's own history, not a new tradeoff being introduced for the first time.

Whichever screen resolves as Home inherits that screen's own performance budget unchanged: the 180KB gzipped first-load shell budget and Suspense-per-panel streaming discipline `docs/frontend/AI_COMMAND_CENTER.md → Performance` fixes for `/command-center`, or Dashboard's own equivalent for `/dashboard`. This document adds no bundle weight to either beyond `HomeModeToggle` itself — a small, code-split, `"use client"` leaf mounted once, never duplicated per panel.

The preferences `PATCH` is fire-and-forget from the user's point of view: `router.push` is never blocked awaiting the mutation's response (see the snippet in `# Components Used`), so a slow or offline network never delays the one thing the user actually asked for — seeing the other screen — even when saving the choice for next time lags a beat behind or fails outright (see `# States`).

# Edge Cases

| Edge case | Behavior |
|---|---|
| A user's role has no entry in the role-default table (a fully custom role) | Resolves to `conventional` — the deterministic surface is always the fallback of last resort, never a fabricated AI-forward default for a role the platform cannot characterize |
| A user holds permission for zero Command Center panels but has `dashboard_layout: "ai_home"` set explicitly | Still honored in full — AI Home renders Morning Briefing and Ask AI only, exactly per `docs/frontend/AI_COMMAND_CENTER.md`'s own equivalent Edge Case row; an explicit user choice is never second-guessed or silently overridden to Dashboard on the platform's own initiative |
| `qayd_home_pref` cookie disagrees with the server-stored preference (set on one device, stale on another) | The cookie is only ever a same-device shortcut for the redirect's cold-start cost, never the system of record; the next `GET /api/v1/users/me/preferences` fetch on that device (any cache miss, any Settings visit) corrects the cookie to match the server. The stale device lands on its old default one extra time at most — never a data-loss or cross-tenant concern, since both destinations sit inside the same authenticated session |
| The `PATCH` in `HomeModeToggle` fails at the exact moment the user clicks | Navigation proceeds regardless (`# Performance`); the toggle reverts to the last confirmed server state on its next successful fetch rather than silently showing a preference that never actually took |
| The AI layer (FastAPI) is unavailable while a user's Home is `ai_home` | AI Home does not redirect itself away or silently fall back to Dashboard — Morning Briefing and Ask AI degrade exactly per `docs/frontend/AI_COMMAND_CENTER.md → States`'s own error rows (inline retry; the rest of the grid is unaffected). A user who wants Dashboard during the outage still reaches it in one click via `HomeModeToggle`, which has no dependency on the AI layer at all |
| Two browser tabs open; one flips the toggle | The idle tab keeps rendering its current screen until its own next navigation or the next `profileKeys.preferences()` refetch (5-minute `staleTime`); no cross-tab broadcast channel is introduced for a preference this low-stakes |
| A brand-new company, first login, onboarding just completed | `dashboard_layout` is still `NULL`; the onboarding-completion redirect (`# Interactions & Flows`) resolves it via the role just assigned during setup, exactly as `docs/frontend/ONBOARDING.md`'s own final step already redirects to a resolved destination rather than a hardcoded one |
| Session expires mid-navigation between the two Home screens | `middleware.ts`'s existing token-refresh/redirect-to-`/login` behavior (`docs/frontend/FRONTEND_ARCHITECTURE.md → Middleware`) runs first and is entirely unaffected by which Home mode was in play; on re-authentication, the resolver in `# Route & Access` runs exactly once more, from the same cookie or API call as any other fresh login |
| Printing or exporting either Home screen | Not supported from either mode, for the identical reason `docs/frontend/AI_COMMAND_CENTER.md → Edge Cases` already gives for `/command-center` — a shareable artifact is a financial-statement or report export, never a "print this dashboard" action, and that holds regardless of which screen is currently Home |

# End of Document
