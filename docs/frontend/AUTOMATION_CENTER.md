# Automation Center — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: AUTOMATION_CENTER
---

# Purpose

This document is the frontend implementation specification for QAYD's Automation Center — the screen where a company decides, rule by rule, what its AI workforce is trusted to do without being asked, and where it can see, undo, and learn from everything the workforce already did while unsupervised. It is the frontend counterpart to `docs/ai/AI_COMMAND_CENTER.md → Automation Center`, which owns the product and behavioral contract this screen renders: the `ai_automation_rules` table, the `suggest_only`/`auto` autonomy ladder, the confidence-threshold mechanism, the kill switch that trips itself when an automation starts misbehaving, and the promotion suggestion that appears once a rule has earned trust. This document does not repeat that contract. Where the two appear to disagree, `docs/ai/AI_COMMAND_CENTER.md` is authoritative for *what autonomy means and what the AI is allowed to do*, and this document is authoritative for *how a human configures and audits it* — the Next.js route, the components, the data-fetching, and the behavior across breakpoints, direction, theme, and assistive technology. It inherits every cross-cutting rule `docs/frontend/FRONTEND_ARCHITECTURE.md` and `docs/frontend/DESIGN_LANGUAGE.md` already establish rather than re-deriving them here.

Automation Center is the reason the rest of QAYD gets quieter over time. Every other AI surface in the product — AI Insights, AI Recommendations, Detected Risks, the Approval Center itself — is downstream of a decision made here: whether a given class of recommendation is trusted enough to execute the moment its confidence clears a bar, or whether it must still queue for a human's sign-off every single time. A company's first weeks on QAYD are, by design, loud — every categorization, every reconciliation match, every drafted entry arrives as a suggestion, because there is no history yet to earn anything more. Automation Center is where that changes, category by category, as trust is demonstrated in the Activity Log and never merely asserted by the AI on its own authority. It is the literal, editable home of the platform-wide claim `docs/frontend/README.md` opens with — "AI is visible, never silent" and "the frontend never auto-commits an AI proposal; it only ever auto-commits a human's decision about one" — because the human decision this screen commits is precisely the one that determines whether a future proposal needs to ask again at all.

The screen's scope spans every module's agent roster, not a single module's automations: `GENERAL_ACCOUNTANT`'s bank-transaction categorization and recurring-entry drafting, `BANKING_AGENT`'s reconciliation matching, `TREASURY_MANAGER`'s payment reminders and cash-position monitoring, `INVENTORY_MANAGER`'s purchase-order matching, `FORECAST_AGENT`'s forecast refresh cadence, `FRAUD_DETECTION`'s and `COMPLIANCE_AGENT`'s alerting, `TAX_ADVISOR`'s and `PAYROLL_MANAGER`'s deadline surfacing, and `OCR_AGENT`/`DOCUMENT_AI`'s document-to-draft extraction all configure through the identical rule shape, the identical toggle-plus-threshold-plus-scope interaction, and the identical Activity Log — a company does not learn a second configuration pattern per module it uses.

This document covers, in full depth: the automation catalog and how it is organized by module and agent; the four configurable dimensions of a single rule (whether it runs at all, its autonomy level, its confidence threshold, and the approval requirement those two together imply); scope and trigger/schedule configuration; the Activity Log of executed automations and the narrow, deliberate conditions under which a logged action can be undone; the guardrail that makes a sensitive action structurally impossible to fully automate regardless of who configures what; and the AI's own recommendations to promote, tighten, or reconsider a rule. Where this document introduces a field or endpoint `docs/ai/AI_COMMAND_CENTER.md` does not yet name explicitly — the Activity Log's `ai_automation_runs` projection, the `trigger_type`/schedule descriptor, the computed `approval_requirement` badge — it is flagged plainly as an extension built strictly on that document's existing `ai_automation_rules` shape and the platform's general "every AI action carries confidence and reasoning, every mutation is audited, nothing is a second source of truth" rules from `DESIGN_CONTEXT`, never as a contradiction of it.

# Route & Access

The screen renders at `app/(app)/automation/page.tsx`. Per `docs/frontend/NAVIGATION_SYSTEM.md`'s own stated pattern for Approval Center — "not nested under `/ai`; a cross-module queue every sensitive action from every module routes through" — Automation Center takes the identical placement and the identical justification: it is not one of the "AI" primary-nav item's own deep-dive feeds (Insights, Recommendations, Risks, Forecast, Ask AI, all scoped to *reading* what the workforce concluded), it is the cross-module *configuration* surface every module's automation rule is owned by, regardless of which agent or which module it governs.

That shared placement extends to a shared discoverability model, not merely a shared URL shape. `docs/frontend/APPROVAL_CENTER.md → Route & Access` is explicit that Approval Center "is not one of the ten primary Sidebar modules... it has no icon rail entry of its own," reached only through entry points that already know it is needed. Automation Center follows the identical discipline for the identical reason: a permanent Sidebar slot is warranted for a screen a role works *from* every day (Accounting, Banking, Sales), not for a screen a handful of roles visit *to configure something* on a cadence measured in weeks, not hours. Giving Automation Center its own icon-rail entry the moment Approval Center had deliberately been kept off one would quietly reintroduce the exact clutter that decision was made to avoid. Concretely, Automation Center is reached three ways, none of them a Sidebar or "AI" sub-nav item:

- **From the Command Center's own `automation_center_rules` widget** (`4x1`, per `docs/ai/AI_COMMAND_CENTER.md → Widgets`), whose "14 rules active, 1 promotion suggested" headline is a condensed read of the exact same `GET /api/v1/ai/automations` response this screen renders in full — clicking the widget or its "Manage automations" affordance is a plain `<Link href="/automation">`, the same relationship `ai_insights_feed` bears to the full-page `/ai/insights`, and `approval_center_queue` bears to `/approvals`. Automation Center never re-implements the widget's summary computation; the widget is this screen's own headline, condensed.
- **From Urgent Actions**, the moment a rule's kill switch trips. `docs/ai/AI_COMMAND_CENTER.md → Automation Center` states plainly that a kill-switch trip "lands in Urgent Actions" — that Urgent Actions row's own link resolves to `/automation?rule={id}`, landing directly on the tripped row with its Review & re-enable action already in view, the identical `?highlight=`-adjacent deep-link convention `docs/frontend/AI_COMMAND_CENTER.md → Morning Briefing` uses for its own cross-panel links.
- **A direct URL or browser bookmark**, for the Owner, CFO, and Finance Manager seats who treat rule-tuning as a first-class recurring task rather than a reactive one — identical to the fourth entry point `APPROVAL_CENTER.md` names for its own power users.

```ts
// lib/nav/nav-tree.ts — Automation Center is deliberately absent from this tree.
// `app/(app)/automation/page.tsx` exists and resolves `/automation` normally; it is simply
// never enumerated as a NavLeaf under any parent (Sidebar or "AI"), matching Approval
// Center's own absence from the same tree for the same stated reason.
```

**Permission model.** Viewing the catalog and the Activity Log requires `reports.read` — the same broad read gate every other Command Center-adjacent panel uses. Changing anything — flipping `enabled`, moving `autonomy` between `suggest_only` and `auto`, adjusting `confidence_threshold`, editing `scope`, resetting a tripped kill switch, acting on a promotion suggestion, or undoing a logged run — requires `ai.automation`. The two permissions are deliberately different keys, not one broad gate, because "I want to see what the workforce is configured to do and what it already did" (an auditor's need) and "I am allowed to change what it is configured to do" (an owner's or CFO's need) are different questions that different people on the same team need answered differently.

| Role | Catalog & Activity Log | Rule mutation | Kill-switch reset | Promotion / recommendation actions |
|---|---|---|---|---|
| Owner, CFO | Full | Full | Full | Full |
| Finance Manager, Senior Accountant | Full | Full, within their module's own scope | Full | Full |
| Accountant | Full (read) | None — every toggle, select, and slider renders disabled with a tooltip naming `ai.automation` | None | View only |
| Auditor, External Auditor | Full (read), including the 30-day override-rate sparkline every rule carries | None | None | View only — matches `docs/ai/AI_COMMAND_CENTER.md`'s Dana Al-Rashidi journey exactly: "Automation Center is visible, read-only — she can see the override-rate sparklines that matter to her control-testing but cannot flip a rule" |
| Payroll Officer, Inventory Manager, Sales/Purchasing roles | Read-only, filtered to rules whose `agent_code` maps to a module they hold `*.read` on | None | None | None |
| Read Only | Catalog only, no Activity Log drill-in beyond `result` (no `reasoning`/`sources` expand, matching `reports.read`'s general summary-not-drill-through posture elsewhere in the product) | None | None | None |

```tsx
// app/(app)/automation/page.tsx (excerpt — permission gating)
<Can permission="reports.read" fallback={<ForbiddenState />}>
  <AutomationCenterShell canMutate={hasPermission("ai.automation")} />
</Can>
```

`canMutate` is threaded down as a prop rather than re-derived by every child component from its own `usePermission("ai.automation")` call, for one concrete reason particular to this screen: a rule row's Switch, Select, and Slider all need to resolve their `disabled` state identically and simultaneously, and computing it once at the shell avoids three components independently reading the permission set on every render of a table that can hold a hundred-plus rows. The mutation's own endpoint re-checks `ai.automation` regardless — `canMutate` is a rendering optimization, never the security boundary, per `FRONTEND_ARCHITECTURE.md` Principle 4.

No default role in the table above can set `autonomy: auto` for a rule whose action touches `bank.transfer`, `payroll.approve`, `tax.submit`, a permission change, or a deletion/void of financial data. This is not a per-role permission distinction — it is structural, covered fully in AI Integration below — and it is why the table above does not need a separate "which roles can automate sensitive actions" row: the answer is no role, because the control to do so does not exist for anyone.

# Layout & Regions

Automation Center is a single page with two tabs — **Rules** and **Activity Log** — sharing one header band, per `docs/frontend/COMPONENT_LIBRARY.md`'s `Tabs` primitive (underline-indicator, animated position via Framer Motion `layoutId`), the same pattern a journal entry detail page uses for its `Lines / History / Attachments` switch. The two tabs are real, linkable URL states (`?tab=rules` default, `?tab=log`), not client-only state, so a link to "the Activity Log for this rule" from elsewhere in the product (a Detected Risks card's "see what else this agent did," for instance) resolves directly rather than dropping a visitor on the wrong tab.

**Desktop (`xl`, 1280px+), Rules tab:**

```
┌───────────────────────────────────────────────────────────────────────┐
│  Automation Center                          14 active · 1 tripped ⚠   │
│  Configure what the AI workforce does on its own.                     │
├─────────────────────────────────────────────────────────────────────--┤
│  [ Rules ]  [ Activity Log ]                     [ Filter ▾ ] [Search] │
├───────────────────────────────────────────────────────────────────────┤
│  ⚠ Promotion suggested — Auto-post recurring rent journal entry        │
│    Approved unchanged for 6 consecutive months — 98% confidence.       │
│                              [ Promote ]  [ Adjust threshold ]  [ Dismiss ] │
├───────────────────────────────────────────────────────────────────────┤
│  Module      Rule                              Agent          Autonomy   Threshold  Approval          30d     │
│  Accounting  Auto-categorize bank transactions  General Acct.  [Auto ⏻]    92%       Below threshold  1,204·5 │
│  Purchasing  Auto-match goods receipts to POs   Inventory Mgr. [Auto ⏻]    90%       Below threshold    212·3 │
│  Sales       Auto-send payment reminders        Treasury Mgr.  [Auto ⏻]    95%       Below threshold     86·0 │
│  Accounting  Auto-post recurring rent entry     General Acct.  [Suggest]   98%       Always                1·0 │
│  Banking     Auto-reconcile matched lines       Banking Agent  [Auto ⏻]    88%       Below threshold    340·9 │
│  Finance     Refresh 13-week cash forecast      Forecast Agent [Auto ⏻]    82%       Distributed live      ⋯  │
│  ⛔ Fraud     Detect anomalous bank changes       Fraud Detect. [Suggest]   —        Always — held           4·0 │
│  ...                                                                    │
└───────────────────────────────────────────────────────────────────────┘
                                            (row click → Rule Detail sheet, inline-end)
```

The `⛔ Fraud` row above renders with the same distinct left-border treatment (`negative` token) that `docs/frontend/AI_COMMAND_CENTER.md`'s held Approval Center rows use, because a `kill_switch_tripped: true` row is exactly that kind of unmissable-at-a-glance state — a row that used to run automatically and no longer does, pending review, must never look like an ordinary `suggest_only` row a human simply hasn't gotten around to promoting yet.

**Rule Detail** opens as a `Sheet` (inline-end edge, mirrors in RTL) rather than a route, because it is an edit surface over one already-loaded row, not a distinct resource with its own deep-linkable page — the same reasoning `COMPONENT_LIBRARY.md` gives for using `Sheet` over `Dialog` for "row detail drawer, AI reasoning drill-in":

```
┌─────────────────────────────────────┐
│ Auto-categorize bank transactions    │
│ General Accountant · Accounting      │
├─────────────────────────────────────┤
│ Enabled                    [ ⏻ On ]  │
│ Autonomy       ( Suggest only | Auto)│
│ Confidence threshold      92 [───●──] │
│ Approval requirement   Below threshold│
│ Trigger            event — bank.synced│
│ Scope         max_amount_kwd: none set│
├─────────────────────────────────────┤
│ Last 30 days                          │
│ 1,204 runs · 5 overrides · 0.42%      │
│ [ sparkline, runs vs. overrides ]     │
├─────────────────────────────────────┤
│           [ View activity log ]       │
└─────────────────────────────────────┘
```

**Activity Log tab** replaces the rules table with a single reverse-chronological, cursor-paginated table of executed runs, filterable by rule, agent, module, date range, and `result` (`applied | overridden | failed | held_for_approval | undone`), each row expandable in place (not a second `Sheet`) to its `reasoning`/`sources` via the same `ReasoningDisclosure` pattern the rest of the product's AI cards use:

```
┌───────────────────────────────────────────────────────────────────────┐
│  [ Rules ]  [ Activity Log ]                    [ Rule ▾ ] [ Result ▾ ]│
├───────────────────────────────────────────────────────────────────────┤
│  Jul 16, 07:41  Auto-categorize bank txns     KWD 340.000  Applied  ⌄  │
│  Jul 16, 06:02  Auto-match goods receipt PO-4821          Applied  ⌄  │
│  Jul 15, 22:00  Auto-send payment reminder — Al-Rayyan Co. Applied  ⌄  │
│  Jul 15, 14:12  Auto-categorize bank txns  ↺ overridden by K. Marafie  │
│  Jul 14, 05:30  Refresh cash flow forecast                 Applied  ⌄  │
└───────────────────────────────────────────────────────────────────────┘
```

Both tabs share the page's own header band (title, active/tripped summary, and the permission-aware "Manage" affordances) and inherit `DESIGN_LANGUAGE.md`'s standard `Card` anatomy for every panel — `radius-lg` (8px), a 1px `ink-6` hairline border, `shadow-xs` — with the same `space-lg`/`space-xl` gutter rhythm the rest of the product's list pages use, per `docs/frontend/LAYOUT_SYSTEM.md`'s list-page template. Nothing on this screen introduces a bespoke card shape or a bespoke severity color; the kill-switch row's left border reuses the `negative` token exactly as Approval Center's held rows do, never a new hue invented for this one screen.

# Components Used

Every visual element on this screen is drawn from `docs/frontend/COMPONENT_LIBRARY.md`'s shared catalogue.

| Component | Source | Used for |
|---|---|---|
| `Tabs` | `components/ui/tabs.tsx` (`@radix-ui/react-tabs`) | Rules / Activity Log switch, URL-synced via `?tab=` |
| `DataTable` | `components/shared/data-table.tsx` | Both the Rules table (`resource: 'ai/automations'`, page mode) and the Activity Log (`resource: 'ai/automations/runs'`, cursor mode) |
| `Switch` | `components/ui/switch.tsx` (`@radix-ui/react-switch`) | The `enabled` toggle per rule row and inside Rule Detail; restyled to QAYD tokens exactly as `COMPONENT_LIBRARY.md`'s other Radix-based primitives are — `accent` fill when on, never a green/red pair, because "on" here is a configuration state, not a financial polarity |
| `Slider` | `components/ui/slider.tsx` (`@radix-ui/react-slider`) | The `confidence_threshold` control, paired with a numeric `Input` for direct entry (see Accessibility) |
| `Select` | `components/ui/select.tsx` | The `autonomy` control (`Suggest only` / `Auto`); the `Auto` option is omitted entirely, not disabled, for any rule whose action requires a sensitive permission (see AI Integration) |
| `Sheet` | `components/ui/sheet.tsx` | Rule Detail, opened from a row click; slides from the inline-end edge |
| `AiCardShell` | `components/ai/ai-card-shell.tsx` | The promotion/reconsideration recommendation banner above the Rules table |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Every rule's own `confidence_threshold` display and every promotion recommendation's confidence |
| `ReasoningDisclosure` | `components/ai/reasoning-disclosure.tsx` | Activity Log's per-run expand, and a promotion recommendation's "why now" explanation |
| `TrendSparkline` | `components/dashboard/trend-sparkline.tsx` | Each rule's 30-day runs-vs-overrides chart, in both the table's compact column and Rule Detail's larger rendering |
| `StatusPill` | `components/shared/status-pill.tsx` | Activity Log's `result` column (`applied`/`overridden`/`failed`/`held_for_approval`/`undone`) and the Rules table's kill-switch state |
| `Badge` | `components/ui/badge.tsx` | "Approval requirement" column values (`Always`, `Below threshold`, `Distributed live`), the "Agent disabled" and "Retired" states |
| `DismissDialog` | `components/ai/dismiss-dialog.tsx` | Dismissing a promotion/reconsideration recommendation, requiring a reason exactly as every other AI-proposal dismissal on the platform does |
| `Dialog` | `components/ui/dialog.tsx` | The "move to Auto" confirmation; the kill-switch reset confirmation; the Undo confirmation; the structured scope editor for a rule whose `scope` shape needs more than one field |
| `Tooltip` | `components/ui/tooltip.tsx` | Every permission-disabled control; the "why is Auto unavailable for this rule" explanation on a sensitive `rule_key` |
| `Toast` (`sonner`) | `useApiToast()` | Every mutation result; the five-second "Undo the undo" grace window after an accepted undo |
| `EmptyState` | `components/shared/empty-state.tsx` | A filtered-to-zero catalog view and an empty Activity Log |
| `ErrorState` | `components/shared/error-state.tsx` | Tab-scoped fetch failure |
| `Skeleton` | `components/ui/skeleton.tsx` | Table and Sheet loading states, shaped to their final layout |

No component on this screen is a one-off. Even the pieces this document's data model extends beyond `docs/ai/AI_COMMAND_CENTER.md`'s literal `ai_automation_rules` shape — the `trigger_type`/schedule descriptor, the computed `approval_requirement` badge — render through components the rest of the product already uses (`Badge`, `Tooltip`, plain tabular typography), never a bespoke widget invented for this screen alone, per `DESIGN_LANGUAGE.md` Design Principle 8 ("consistency compounds; novelty costs").

# Data & State

## Endpoints

Automation Center extends `docs/ai/AI_COMMAND_CENTER.md`'s `GET/PUT /api/v1/ai/automations` (permission `ai.automation` to mutate, `reports.read` to view) with the item-scoped and sub-resource routes a full management screen needs — the same collection-plus-item-plus-action shape every other module in this API already uses (`GET /api/v1/approvals` alongside `POST /api/v1/approvals/{id}/approve`):

| Method | Path | Permission | Description |
|---|---|---|---|
| `GET` | `/api/v1/ai/automations` | `reports.read` | List every `ai_automation_rules` row for the active company, filterable by `module`, `agent_code`, `autonomy`, `enabled`, `kill_switch_tripped` |
| `GET` | `/api/v1/ai/automations/{id}` | `reports.read` | Single rule detail, including its 30-day sparkline series |
| `PUT` | `/api/v1/ai/automations/{id}` | `ai.automation` | Update `enabled`, `autonomy`, `confidence_threshold`, or `scope` for one rule; rejects (`422`) an attempt to set `autonomy: "auto"` on a rule whose action requires a sensitive permission |
| `POST` | `/api/v1/ai/automations/{id}/promote` | `ai.automation` | Accept a pending promotion suggestion (`suggest_only` → `auto`), seeding `confidence_threshold` from the suggestion's own recommended value |
| `POST` | `/api/v1/ai/automations/{id}/reset-kill-switch` | `ai.automation` | Clears `kill_switch_tripped`/`kill_switch_reason` after human review; requires a `review_note` |
| `GET` | `/api/v1/ai/automations/runs` | `reports.read` | Cursor-paginated Activity Log, filterable by `rule_id`, `agent_code`, `result`, and date range |
| `POST` | `/api/v1/ai/automations/runs/{id}/undo` | `ai.automation` | Reverses one logged run, only where `undo_supported: true` and `undo_available_until` has not elapsed |

## Query keys and hooks

```ts
// lib/api/query-keys.ts (Automation Center additions)
export const automationKeys = {
  all: ["ai", "automations"] as const,
  list: (filters: AutomationFilters) => [...automationKeys.all, "list", filters] as const,
  detail: (id: number) => [...automationKeys.all, "detail", id] as const,
  runs: (filters: AutomationRunFilters) => [...automationKeys.all, "runs", filters] as const,
};
```

```tsx
// app/(app)/automation/page.tsx
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { automationKeys } from "@/lib/api/query-keys";
import { AutomationCenterShell } from "@/components/automation/automation-center-shell";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function AutomationCenterPage() {
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: automationKeys.list({}),
    queryFn: () => apiServer.get("/ai/automations"),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <AutomationCenterShell />
    </HydrationBoundary>
  );
}
```

```ts
// lib/api/hooks/use-automation-rules.ts
export function useAutomationRules(filters: AutomationFilters) {
  return useQuery({
    queryKey: automationKeys.list(filters),
    queryFn: () => api.get<AutomationRule[]>("/ai/automations", { params: filters }),
    staleTime: 30_000,
    refetchOnWindowFocus: true, // a rule promoted or tripped while the tab was backgrounded should surface on return
  });
}

export function useUpdateAutomationRule(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (patch: AutomationRuleUpdate) => api.put<AutomationRule>(`/ai/automations/${id}`, patch),
    onMutate: async (patch) => {
      await queryClient.cancelQueries({ queryKey: automationKeys.detail(id) });
      const previous = queryClient.getQueryData<AutomationRule>(automationKeys.detail(id));
      queryClient.setQueryData(automationKeys.detail(id), (old?: AutomationRule) => old && { ...old, ...patch });
      return { previous };
    },
    onError: (_err, _patch, ctx) => {
      queryClient.setQueryData(automationKeys.detail(id), ctx?.previous);
      toast.error(t("automation.updateFailed"));
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: automationKeys.all }),
  });
}

export function useAutomationRuns(filters: AutomationRunFilters) {
  return useInfiniteQuery({
    queryKey: automationKeys.runs(filters),
    queryFn: ({ pageParam }) =>
      api.get<AutomationRunPage>("/ai/automations/runs", { params: { ...filters, cursor: pageParam } }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.cursor,
    staleTime: 0, // live feed of what the workforce is doing right now
  });
}
```

`useAutomationRules` follows the "rarely-changing reference/master data" cache class from `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` (rule configuration changes are infrequent and human-initiated) at `staleTime: 30_000`, while `useAutomationRuns` follows the "Live/derived figures" class at `staleTime: 0` — the Activity Log is, by definition, a feed of what just happened, and treating it as anything less than always-stale would mean a Finance Manager could miss a run that landed seconds before they opened the tab.

## Realtime

Automation Center subscribes to a dedicated channel, `private-company.{id}.automation`, following the exact `private-company.{company_id}.<feature>[.{sub_id}]` convention `FRONTEND_ARCHITECTURE.md → Realtime`'s channel table already establishes for `.ai-jobs`, `.approvals`, and `.dashboard`:

| Event | Payload | Client effect |
|---|---|---|
| `automation.rule.kill_switch_tripped` | `{ rule_id, kill_switch_reason }` | Patches that row's `kill_switch_tripped`/`kill_switch_reason` in place and re-sorts it to the top of its module group; also invalidates `["dashboard"]` so the Command Center's `automation_center_rules` widget updates its "N tripped" count without a full refetch |
| `automation.rule.promotion_suggested` | `{ rule_id, recommended_threshold, reason }` | Inserts or updates the promotion banner above the Rules table |
| `automation.run.completed` | `{ run_id, rule_id, result }` | Prepends the new row to the Activity Log's first page if it is currently open; otherwise patches only the affected rule's `runs_30d`/`last_run_at` in the Rules table |

Every one of these purges or patches a narrow, specific cache entry rather than invalidating `automationKeys.all` wholesale on every event — a company running dozens of rules across hundreds of categorizations an hour would otherwise refetch the entire catalog on a cadence that defeats the point of caching it at all. This mirrors the exact economy `docs/frontend/AI_COMMAND_CENTER.md`'s bootstrap-payload discussion describes for the wider Command Center: one upstream fact, patched precisely where it is shown, never recomputed by the client.

## Extending the data model this screen needs

`docs/ai/AI_COMMAND_CENTER.md`'s `ai_automation_rules` table (columns: `rule_key`, `title`, `agent_code`, `autonomy`, `confidence_threshold`, `enabled`, `scope`, `kill_switch_tripped`, `kill_switch_reason`, `last_run_at`, `runs_30d`, `overrides_30d`, plus the standard tenant columns from `DESIGN_CONTEXT`) is the row of record this screen edits; nothing here introduces a competing source of truth for it. Two additions this screen's UI needs, consistent with behavior that document already implies but does not yet name as columns:

- **`trigger_type`** (`'event' | 'schedule' | 'continuous'`) plus a human-readable `schedule_description` — the automations `docs/ai/AI_COMMAND_CENTER.md` already describes are clearly one of these three (bank-transaction categorization fires on the `bank.synced` event; the recurring rent entry and payment reminders run on a schedule; `REPORTING_AGENT`'s own override-rate monitoring is continuous). This screen is simply the first surface that needs that distinction rendered, and — where the trigger is `schedule` — edited.
- **`approval_requirement`** (`'always' | 'below_threshold' | 'distributed_live'`) — a field the API computes and sends, exactly as `can_execute_directly` is computed for an individual AI decision elsewhere in the product (`FRONTEND_ARCHITECTURE.md → The three-button pattern`). A rule's `autonomy`/`confidence_threshold` describe the *ceiling*; `approval_requirement` is the plain answer a Finance Manager actually wants without doing the arithmetic themselves: `always` for `suggest_only` rules, `below_threshold` for `auto` rules whose individual decisions still fall back to approval whenever a specific run's own confidence dips under the configured bar, and `distributed_live` for the small set of non-mutating rules (forecast refresh, alert generation) where "auto" means the output reaches every widget and notification the instant it computes rather than holding for a digest review (see AI Integration). The frontend never derives this itself from raw `autonomy`/`confidence_threshold` values — the same rule that forbids re-deriving `can_execute_directly` client-side (`FRONTEND_ARCHITECTURE.md` Principle 1) applies here without exception.

The Activity Log's own backing shape, `ai_automation_runs`, is a focused projection over data that already exists platform-wide under two general rules `DESIGN_CONTEXT` states for every AI action — "every AI output carries a confidence score, reasoning, and supporting documents" and "every mutation writes an audit log" — rather than a new primary source of truth, the same relationship `ledger_entries` bears to `journal_lines` (`DESIGN_CONTEXT`: "a projection/materialized view for speed"):

```sql
CREATE TYPE ai_automation_run_result AS ENUM ('applied', 'overridden', 'failed', 'held_for_approval', 'undone');

CREATE TABLE ai_automation_runs (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    rule_id               BIGINT NOT NULL REFERENCES ai_automation_rules(id),
    rule_key              VARCHAR(80) NOT NULL,        -- denormalized for filter/sort without a join
    agent_code            VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    decision_id           BIGINT NULL REFERENCES ai_decisions(id),
    executed_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    subject_type          VARCHAR(60) NOT NULL,        -- e.g. 'bank_transaction', 'journal_entry', 'purchase_order'
    subject_id            BIGINT NULL,
    action_summary        VARCHAR(300) NOT NULL,       -- e.g. "Categorized as Office Supplies"
    confidence_score      NUMERIC(5,2) NOT NULL,
    result                ai_automation_run_result NOT NULL DEFAULT 'applied',
    undo_supported        BOOLEAN NOT NULL DEFAULT false,
    undo_available_until  TIMESTAMPTZ NULL,
    undone_at             TIMESTAMPTZ NULL,
    undone_by             BIGINT NULL REFERENCES users(id),
    reversal_reference    JSONB NULL,                  -- e.g. { "type": "journal_entry_reversal", "id": 88213 }
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_automation_runs_company_rule ON ai_automation_runs (company_id, rule_id, executed_at DESC);
CREATE INDEX idx_ai_automation_runs_company_result ON ai_automation_runs (company_id, result);
```

`reasoning` and `sources` for a given run are read from the linked `ai_decisions.id` (`decision_id`) rather than duplicated onto `ai_automation_runs` itself, keeping the platform's single rule — every AI output's confidence/reasoning/sources live in exactly one place — intact even for this screen's own specialized log view.

# Interactions & Flows

**Filtering the catalog.** A toolbar above the Rules table offers `module`, `agent`, `autonomy`, and `enabled` filters plus a debounced (300ms, per `FRONTEND_ARCHITECTURE.md → Performance`) search over `title`/`rule_key`. Filters are URL search params (`?module=accounting&autonomy=auto`), not component state, so a link to "show me every tripped rule" is shareable between an Owner and a CFO without either having to re-click through the same three dropdowns.

**Toggling `enabled`.** The row's `Switch` fires immediately on click — an `enabled: false` flip is reversible-adjacent (it stops a rule from running; it does not undo anything already run) and updates optimistically per `FRONTEND_ARCHITECTURE.md` Principle 10, rolling back with a toast on a `4xx`/`5xx`. Disabling a rule never deletes its 30-day history or its Activity Log entries; a later re-enabled rule resumes with its prior `confidence_threshold` and `scope` intact, never reset to platform defaults.

**Moving `autonomy` from `Suggest only` to `Auto`.** This is not treated as casually as the `enabled` switch: selecting `Auto` opens a confirmation `Dialog` naming the exact scope the rule will operate within ("Auto-categorize bank transactions will apply without review whenever confidence is at or above 92%, for any transaction under KWD 2,000") before the `PUT` fires — per `DESIGN_LANGUAGE.md`'s voice rule for consequential confirms, stating what will happen in plain language rather than a bare "Are you sure?". Demoting `Auto` back to `Suggest only` fires immediately without a confirmation dialog, matching the platform-wide asymmetry (`FRONTEND_ARCHITECTURE.md` Principle 10) that tightening a guardrail never needs the same friction as loosening one.

**Adjusting the confidence threshold.** Dragging the `Slider` updates a live, client-only preview line beneath it — "At 92%, 1,199 of the last 1,204 runs would have qualified" — computed from the rule detail endpoint's own historical series, never a new round-trip per drag tick; the actual `PUT` fires only `onValueCommit` (on release), not on every intermediate value, so dragging from 85 to 95 issues one mutation, not eleven.

**Editing `scope`.** Rules whose `scope` shape carries more than a single numeric cap (a branch restriction plus an amount cap, for instance) open a `Dialog` with a Zod-validated form specific to that `rule_key`, rather than a generic JSON textarea — QAYD never asks a Finance Manager to hand-edit JSONB:

```ts
// lib/validation/automation-scope.ts
export const bankCategorizationScopeSchema = z.object({
  max_amount_kwd: z.coerce.number().positive().nullable(),
  branch_id: z.coerce.number().int().positive().nullable(),
});
export const poMatchingScopeSchema = z.object({
  max_variance_pct: z.coerce.number().min(0).max(100),
});
```

**Resetting a tripped kill switch.** A `kill_switch_tripped` row's row-level action is exclusively **Review & re-enable** — there is no direct path back to `Auto` that skips this step. The dialog requires a `review_note` (mapped to the API's own field of the same name) and shows the `kill_switch_reason` `REPORTING_AGENT` recorded ("Override rate exceeded 5% for two consecutive weeks — 8 of 142 runs corrected") alongside the specific overridden runs pulled from the Activity Log, so the human reviewing it is looking at the same evidence the automated brake acted on, never a bare "something went wrong."

**Acting on a promotion or reconsideration recommendation.** The banner above the Rules table (at most one per rule; multiple simultaneous suggestions stack as a small carousel, oldest first) renders through `AiCardShell` with three actions, deliberately different from the platform's general "Do it / Send for approval / Dismiss" pattern because a rule-configuration change is not itself a financial action requiring an approval chain — it is:

- **Promote** (`POST /api/v1/ai/automations/{id}/promote`) — accepts the suggestion outright, seeding `confidence_threshold` from the recommendation's own value.
- **Adjust threshold first** — opens Rule Detail pre-scrolled to the `Slider`, for a human who agrees with the direction but not the exact number.
- **Dismiss** (`DismissDialog`, reason required) — the same dismiss-with-reason pattern AI Insights uses, so `REPORTING_AGENT` learns not to re-suggest the identical promotion on the identical unchanged evidence.

**The Activity Log's undo.** A row's **Undo** action is present only when `undo_supported: true` and `undo_available_until` has not elapsed — never rendered disabled-with-a-countdown, simply absent once the window closes, matching the platform's "a gated action has no client-side path" posture applied here to a time gate rather than a permission gate. Clicking it opens a `Dialog` naming exactly what will revert ("Undo: transaction reclassified from Office Supplies back to Uncategorized") — never a bare confirm — and on confirmation the mutation is pessimistic (waits for the server's `2xx`, no `onMutate`), because although the underlying action is reversible-adjacent by construction (see Guardrails in AI Integration), the undo itself is still a real write the human should see land, not assume. A successful undo shows a `Toast` with its own five-second "Undo the undo" affordance for the narrow case of a mis-click, mirroring AI Insights' "Not useful" optimistic-removal pattern from `docs/frontend/AI_COMMAND_CENTER.md`.

**No bulk actions.** Automation Center deliberately does not offer "select all `suggest_only` rules and promote," a "select all and disable," or any multi-row mutation. This is a considered omission, not a gap: the entire value of the autonomy ladder is that each rule earns its own promotion on its own evidence (ten consecutive unchanged approvals, a stable override rate), and a bulk action would let a company short-circuit exactly the category-by-category trust-building the screen exists to enforce. Approval Center's own bulk-approve restriction on sensitive rows is the same instinct applied one layer up; here, the instinct applies to every row, not a sensitive subset of them.

# AI Integration

Automation Center is the screen where the platform's `can_execute_directly` computation — described throughout `FRONTEND_ARCHITECTURE.md` and `docs/frontend/AI_COMMAND_CENTER.md` as a field the API computes by evaluating an individual decision's confidence against the company's `ai_automation_rules` configuration — is actually configured. Every other AI-authored surface in the product treats a rule's `autonomy`/`confidence_threshold` as an input it reads; this screen is the one place they are written.

**The guardrail is structural, not a UI convention.** Per `docs/ai/AI_COMMAND_CENTER.md`'s own stated rule, "no `ai_automation_rules` row can ever carry autonomy `auto` for a `rule_key` whose underlying action requires a sensitive permission" — restated from `DESIGN_CONTEXT`'s platform-wide list: bank transfers, payroll release, tax submission, deleting/voiding financial data, permission changes, and company settings changes. This screen enforces it at every layer defense-in-depth demands:

1. **The `Select` for `autonomy` omits the `Auto` option entirely** — not disabled, absent — for any rule whose `sensitive_permission` field (returned by the API alongside every rule, computed from its `rule_key`'s mapped action) is non-null. A `Tooltip` on the `Select` itself explains why: "This automation touches `bank.transfer`, which always requires human approval — QAYD does not offer a fully automatic option for it."
2. **The `PUT /api/v1/ai/automations/{id}` endpoint validates the same constraint server-side** and returns `422` if a client somehow submitted `autonomy: "auto"` for such a row (a stale bundle, a direct API call) — this is the actual security boundary, per `FRONTEND_ARCHITECTURE.md` Principle 4; the missing `Select` option is a courtesy that keeps a human from ever discovering the restriction the hard way.
3. **The same constraint is enforced independently at the validation layer** — a CHECK-constraint-style rule inside the Laravel `FormRequest`, per `docs/ai/AI_COMMAND_CENTER.md`'s own stated AI behavior for this exact table — so no misconfiguration at any single layer can silently turn a sensitive action fully autonomous, even if a future client build somehow presented the option.

**Confidence and reasoning render everywhere a number appears.** A rule's `confidence_threshold` is not a bare percentage on this screen — the `ConfidenceBadge` used elsewhere in the product for a single decision's confidence is reused here for the rule's *configured bar*, and every Activity Log row's own `confidence_score` renders through the identical badge, so a Finance Manager reviewing "why did this run apply automatically" sees the same visual language as "why is AI Insights suggesting this," never a screen-specific reinterpretation of what a confidence number means.

**Kill switches are AI-initiated, human-reviewed, never AI-reset.** `REPORTING_AGENT` is the only actor that can set `kill_switch_tripped: true` (continuously monitoring every enabled `auto` rule's `overrides_30d`/`runs_30d` ratio, tripping at a sustained >5% override rate over two consecutive weeks, per `docs/ai/AI_COMMAND_CENTER.md`'s own stated threshold); only a human holding `ai.automation` can clear it, and only through the Review & re-enable flow in Interactions & Flows, which requires a `review_note`. There is no "auto-reset" path — a company that never reviews a tripped rule simply keeps that rule running in `suggest_only` mode indefinitely, which is the safe default, not a degraded state requiring urgency.

**Promotion suggestions carry the same confidence/reasoning/sources discipline as any other AI proposal**, per `FRONTEND_ARCHITECTURE.md` Principle 3 — the banner's `reasoning` ("approved unchanged for 6 consecutive months") is not paraphrased by the frontend from a raw count; it is the exact string the API returns, and its `sources` cite the specific Activity Log runs (`ai_automation_runs.id` values) the promotion is based on, openable inline via the same `ReasoningDisclosure` used everywhere else.

**Distribution autonomy vs. execution autonomy.** A handful of rules on this screen never mutate a business record at all — `refresh_cash_flow_forecast` (`FORECAST_AGENT`) and the alert-raising rules (`FRAUD_DETECTION`, `COMPLIANCE_AGENT`, `AUDITOR`) recompute and notify; they do not post, categorize, or match anything. For these, `autonomy: auto` does not mean "executes without approval" (there is nothing to execute) — it means the refreshed forecast or newly raised alert reaches Cash Flow Status, Detected Risks, and the notification stream the moment it computes; `autonomy: suggest_only` means it lands in AI Insights as a held draft pending a `REPORTING_AGENT` digest pass before wider distribution. The `approval_requirement` badge reflects this directly, rendering `Distributed live` rather than a misleading `Never`, so a Finance Manager reading the table never has to mentally translate "auto" into two different meanings depending on the row.

**AI recommendations to enable or disable, beyond promotion.** Three recommendation shapes appear on this screen, all through the identical `AiCardShell` banner:

| Recommendation | Trigger | Action offered |
|---|---|---|
| Promote to `auto` | 10 consecutive unchanged approvals of a `suggest_only` rule's proposals | Promote / Adjust threshold first / Dismiss |
| Tighten the threshold | Override rate has crept above 2% but has not yet crossed the 5% kill-switch line | Raise threshold to the recommended value / Dismiss |
| Consider disabling | An agent's `ai_agent_settings.enabled` was turned off company-wide (per `docs/ai/agents/TAX_AGENT.md`'s own kill-switch precedent: "An Owner or Admin can disable the Tax Advisor entirely for a company") while rules referencing that agent remain individually `enabled: true` | Disable the affected rules / Leave as-is — they simply will not run while the agent itself is off, and resume automatically the moment it is re-enabled |

No recommendation on this screen ever pre-selects itself into effect. Every one of the three rows above requires the same explicit human click every other AI proposal on the platform requires, regardless of how mechanical the underlying evidence looks — a rule earning its own promotion after ten identical approvals is still a human's decision to make, not a threshold the system crosses on its own.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Rules table | Full-width shimmer, eight placeholder rows shaped to the loaded table's column widths | A brand-new company's catalog is never empty in the sense of "nothing to show" — every applicable `rule_key` is seeded at provisioning, `enabled: true`, `autonomy: suggest_only` by platform default, so the first-ever render always shows a full, conservative catalog. A genuinely *filtered*-to-zero result (e.g. `module=payroll` for a company with no Payroll seats configured) renders the lighter "no rows match your filters" variant `DataTable` already defines, with a "Clear filters" action | Inline retry card; the Activity Log tab is unaffected since it is a separate query |
| Promotion banner | Skeleton-suppressed — the banner simply does not render until its own query resolves, since a shimmering "suggestion" would misleadingly imply one is definitely coming | No banner renders when there are zero pending suggestions — this is the common case and is not itself styled as a reassuring empty state (unlike, say, Approval Center's "Nothing waiting on you"), since its absence is ordinary silence, not a state worth narrating | Fails silently to an absent banner with the failure logged to Sentry, never a visible error card for a non-critical, supplementary panel |
| Rule Detail sheet | `Skeleton` matching the sheet's field layout while `GET /api/v1/ai/automations/{id}` resolves (typically instant, since the row is already in the list cache and the detail query seeds from it via `initialData`) | N/A | Inline retry within the sheet; the sheet does not close itself on a fetch failure |
| Activity Log | Five placeholder row shimmer | "No automated actions yet" (a brand-new company, distinct copy from a filtered-to-zero result) vs. "No results for these filters" (an active company with an empty page 1 under the current filter set) — two distinct empty copies for two distinct causes, the same pattern `docs/frontend/AI_COMMAND_CENTER.md`'s AI Insights panel already establishes for its own two-empty-states split | Failed initial load shows retry; a failed "load more" leaves already-loaded rows intact with an inline retry at the list's end, never discarding what already rendered |
| Kill-switch reset dialog | Submit button's own `loading` prop; the dialog does not close until the mutation resolves | N/A | A field-level `422` (e.g. a required `review_note` left blank) renders inline under the textarea, never a generic toast, per `FRONTEND_ARCHITECTURE.md`'s "`422` is never a toast" rule |

Every skeleton reuses the loaded state's exact column widths and row height for its density mode (Comfortable, per `DESIGN_LANGUAGE.md → Table density modes` — this screen is a configuration table read closely, not a thousand-line ledger scanned quickly, and Comfortable is its correct default), so neither tab pops or reflows between its loading and loaded shape.

# Responsive Behavior

| Region | Desktop / Ultra Wide (`xl`+) | Tablet (`md`–`lg`) | Mobile (`base`–`sm`) |
|---|---|---|---|
| Rules table | Full table, all columns (Module, Rule, Agent, Autonomy, Threshold, Approval, 30d) | Full-width table; `Agent` and `Approval` columns collapse into the row's expandable detail rather than keeping their own columns, per `docs/frontend/RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` | Converts to a stacked card per rule — title, module/agent subtitle, the `Enabled` switch, and a condensed `Autonomy · Threshold · Override rate` line — following `RESPONSIVE_DESIGN.md`'s cards-not-tables pattern; tapping a card opens the same Rule Detail, now full-screen rather than a side sheet |
| Rule Detail | `Sheet`, fixed 420px width, inline-end edge | `Sheet`, same edge, roughly 85% viewport width | Full-screen `Sheet` (`Dialog` `size="full"` equivalent), matching `COMPONENT_LIBRARY.md`'s stated mobile-width convention for `JournalEntryForm` |
| Confidence slider | `Slider` plus a compact numeric `Input` side by side | Same | `Slider` full-width above a numeric `Input` below it, both meeting the 44px minimum touch-target height (`RESPONSIVE_DESIGN.md → Touch Targets & Gestures`) — precise threshold entry by touch-dragging a small track is materially harder on a phone, so the numeric field is promoted to equal visual weight with the slider rather than treated as a secondary affordance |
| Promotion banner | Full-width, above the table | Full-width | Pinned above the card feed, its three actions stacked vertically rather than in a row, each meeting the 44px touch target |
| Activity Log | Full table with inline row expand | Full-width table, expand-in-place retained | Converts to a chronological card feed — date, rule title, `result` `StatusPill`, and the confidence badge — with the reasoning/sources drill-in behind a tap rather than an inline expand, since a mobile viewport has no room for both a collapsed and expanded row state to coexist legibly |
| Filter toolbar | Inline dropdowns | Inline dropdowns, wrapping to a second row before scrolling | Collapses into a single "Filter" trigger opening a full-screen `Sheet` with all filter fields stacked, matching `RESPONSIVE_DESIGN.md`'s general small-screen filter pattern |

Realtime subscription to `private-company.{id}.automation` is held open regardless of viewport for as long as the screen is mounted — unlike the Command Center's per-widget `IntersectionObserver`-gated subscriptions (`RESPONSIVE_DESIGN.md → Realtime subscription scoping`), Automation Center is a single-purpose screen with one subscription for its whole lifetime, not twenty widgets competing for battery and radio budget across a long scroll.

# RTL & Localization

Every layout, gap, and padding value on this screen uses Tailwind's logical utilities exclusively (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`), per the platform-wide ESLint restriction `DESIGN_LANGUAGE.md → RTL mirroring` and `COMPONENT_LIBRARY.md → Theming & RTL` both enforce — the Rule Detail sheet's slide edge, the confidence slider's fill direction, and the Activity Log's chevron-expand icon all mirror with zero conditional code as a result.

**What never mirrors**, matching the platform's numeral rule exactly: every `confidence_threshold`, every `runs_30d`/`overrides_30d` count, every override-rate percentage, and every `executed_at` timestamp renders inside a `dir="ltr"` span via the same `Amount`/`AmountCell`-adjacent primitives the rest of the product uses for financial figures — a 92% threshold reads left-to-right even inside a fully Arabic sentence, and the Activity Log's dates never switch to Eastern Arabic-Indic digits. The confidence `Slider`'s fill direction, by contrast, **does** mirror (filling from the inline-start edge in both directions), because a slider's fill is a spatial affordance, not a numeral — the two are governed by different rules for a reason, and this screen is where they sit closest together, so it is worth stating explicitly rather than leaving an engineer to guess by analogy.

**Rule titles are platform vocabulary, not bilingual master data.** Unlike a customer's or vendor's `name_en`/`name_ar` pair, a rule's `title` (`ai_automation_rules.title`) is a fixed, platform-defined string — the same status a company's ten sidebar modules hold under `NAVIGATION_SYSTEM.md`'s "fixed, platform-defined list... companies do not reorder or rename them" rule. The frontend therefore never renders the API's raw `title` column directly; it resolves display text from its own i18n dictionary, keyed by `rule_key`:

```ts
// messages/en.json / messages/ar.json (excerpt)
{
  "automation": {
    "rules": {
      "auto_categorize_bank_transactions": { "title": "Auto-categorize bank transactions" },
      "auto_match_goods_receipts_to_pos": { "title": "Auto-match goods receipts to POs" }
    }
  }
}
```

This keeps a new `rule_key` shipped as a platform update (per `docs/ai/AI_FINANCE_OS.md`: "new capability ships as platform updates, not customer-built automations") from ever appearing untranslated in Arabic the way a customer-entered free-text field legitimately could — the raw `title` column exists for the backend's own English-only logging and as the fallback a not-yet-localized `rule_key` degrades to, never as the primary render path.

**Arabic microcopy**, authored directly per `DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf business-register brief, not machine-translated:

| Context | English | Arabic |
|---|---|---|
| Screen title | Automation Center | مركز الأتمتة |
| Tab | Rules | القواعد |
| Tab | Activity Log | سجل النشاط |
| Autonomy option | Suggest only | اقتراح فقط |
| Autonomy option | Auto | تلقائي |
| Approval requirement | Always | دائمًا |
| Approval requirement | Below threshold | عند انخفاض الثقة عن الحد |
| Approval requirement | Distributed live | يُنشر فوريًا |
| Kill-switch banner | Automatically paused — override rate exceeded 5% | أُوقف تلقائيًا — تجاوز معدل التصحيح 5% |
| Action | Promote to auto | ترقية إلى تلقائي |
| Action | Review & re-enable | مراجعة وإعادة التفعيل |
| Action | Undo | تراجع |
| Empty Activity Log | No automated actions yet | لا توجد إجراءات آلية حتى الآن |

Every string above is held to the same bar `DESIGN_LANGUAGE.md` states for the rest of the product: would a CFO reading it in a board pack find it precise and calm, not stiff, over-translated, or too casual — a string that fails that test in either language is rewritten, not shipped.

# Dark Mode

Automation Center introduces no theme logic of its own. Dark mode is system-aware by default, user-overridable, and persisted through the platform's `class`/`data-theme="dark"` strategy (`docs/frontend/DARK_MODE.md → Strategy`); every token this screen's components resolve — the `ink` scale, `accent`, `negative`/`negative-subtle`, `ai-accent` — already carries an explicit dark-mode value, and no component here reads a raw hex or reaches for a `dark:` Tailwind variant against a literal color.

**Accent is reserved for controls, never for a configuration's meaning.** The row-level `Switch`, the `Slider`'s fill and thumb, the active `Tabs` indicator, and a focused `Input`'s ring all render in `accent` in both themes, because each is an interactive affordance, not a judgment about the rule it belongs to — an `enabled: true` rule's `Switch` is accent-colored for the same reason a `checked` checkbox anywhere else in the product is, not because "on" is a good outcome. This is the identical distinction Components Used already draws for this exact control ("`accent` fill when on, never a green/red pair, because 'on' here is a configuration state, not a financial polarity") and it is what keeps a company's largest, most-trusted `auto` rule from visually reading as "healthier" than a brand-new `suggest_only` one sitting right beside it — both are simply switches in different positions.

**The kill-switch row is the one place this screen carries real status color, and it is `negative`, not accent, in both themes.** A `kill_switch_tripped: true` row's 4px leading-edge border and its `StatusPill` both resolve to `--status-error`/`negative` — light `#B4232E`, dark `#F26B74` — the exact pairing `docs/frontend/DARK_MODE.md → Color & Contrast In Dark`'s contrast table already verifies independently in each theme (`--status-error` on `--status-error-subtle`: 5.8:1 light, 6.6:1 dark, both clearing the 4.5:1 AA floor with margin). This is never conflated with `accent` even though both are, in a loose sense, "AI-adjacent" — `REPORTING_AGENT` tripped the switch, but the row's color communicates *severity*, not *provenance*, matching the platform-wide rule that a critical flag is colored `negative`, never accent, regardless of which agent produced it.

**Confidence is `ai-accent`, never borrowed from the four finance-status tokens.** Every `ConfidenceBadge` on this screen — a rule's own `confidence_threshold` bar, a promotion recommendation's confidence, an Activity Log row's `confidence_score` — renders in `--ai-accent` (violet, hue 302°) in both themes, re-tuned per theme rather than linearly brightened, per `docs/frontend/DARK_MODE.md → AI provenance color`'s stated reasoning: a 92%-confidence `auto` categorization rule and a 92%-confidence promotion suggestion are both simply "the AI is quite sure," a statement independent of whether the underlying action is routine or consequential, and QAYD is deliberate about never letting confidence borrow red/green/amber and accidentally imply a judgment the number was never making. `TrendSparkline`'s 30-day runs-vs-overrides stroke — in both the Rules table's compact column and Rule Detail's larger rendering — resolves through `lib/tokens.ts`'s JS-readable token export rather than a CSS variable, the same mechanism required platform-wide for any SVG `stroke`/`fill` that a `dark:` class cannot reach.

**No surface-glass on this screen.** `DESIGN_LANGUAGE.md → Elevation & Surfaces` restricts `surface-glass` (frosted, `backdrop-blur`) to exactly two places platform-wide — the Command Palette and the AI Command Center's own docked Ask AI panel. Automation Center's `Sheet` (Rule Detail) and every `Dialog` (the Auto-confirmation, the kill-switch reset, the scope editor, the Undo confirmation) resolve through ordinary `surface-3` (`ink-1` light / `ink-3` dark, 1px `ink-6` border, `shadow-lg`, with the standard `ink-12`/50% scrim beneath) — the same elevation every modal and dialog in the product uses. This screen does not introduce a third glass exception; a configuration surface for automation rules has no more claim to that treatment than a Journal Entry's own detail Sheet does.

**Elevation gets lighter, not darker, in dark mode.** The Rules table's `Card`, the promotion banner's `AiCardShell`, and Rule Detail's `Sheet` all sit visibly above the canvas behind them via their `ink-6` border and a marginally lighter fill — never a heavier shadow — per `docs/frontend/DARK_MODE.md → Surfaces & Elevation In Dark`; `--shadow-xs` through `--shadow-lg` are redefined under `.dark` at roughly half their light-mode opacity, so a shimmering `Skeleton` row or a raised `Sheet` never reads as a heavier, more "3D" object simply because the theme flipped.

**Manual QA state table**, filled in against the template `docs/frontend/DARK_MODE.md → Manual QA state table` fixes for every screen doc:

| State | Light / LTR | Dark / LTR | Light / RTL | Dark / RTL |
|---|---|---|---|---|
| Rules table, loading | Shimmer rows on `bg-surface-sunken`, shape-matched to loaded columns | Same shimmer, dark `--surface-sunken` value | Shimmer mirrored, column order reversed | Same mirroring, dark tokens |
| Rules table, populated | `surface-1` rows, `ink-6` hairlines, `Switch` accent when on | Same structure, `ink-925`/`ink-1000` surfaces per `DARK_MODE.md`'s dark ink ramp | Column order mirrored (`Module` trailing-start, `Actions`-equivalent trailing-end), numerals stay Western/`dir="ltr"` | Same mirroring, dark surfaces, numerals unchanged |
| Kill-switch row | 4px `negative` leading-edge border, `StatusPill` in `negative`/`negative-subtle` | Same border/pill, dark `negative` value (`#F26B74`) — never dimmed to compensate | Border renders on the *trailing* edge in RTL (the leading edge in reading order), pill position mirrors | Same mirrored border, dark token |
| Rule Detail sheet | `surface-3`, inline-end slide-in, `ink-12` scrim | Same elevation, dark scrim opacity per `DARK_MODE.md` | Slides from inline-start, `ConfidenceBadge`/numerals stay `dir="ltr"` | Same mirrored slide, dark tokens |
| Promotion banner | `AiCardShell`, `ai-accent` left border, `ConfidenceBadge` in violet | Same shell, re-tuned dark `ai-accent` (never a naive brighten) | Border on trailing edge, actions right-to-left ordered | Same, dark `ai-accent` |
| Activity Log, empty | Line-art glyph (`currentColor` → `ink-7`), "No automated actions yet" | Same glyph, dark `ink-7` value | Glyph and copy mirrored, no layout asymmetry | Same, dark tokens |
| Error (either tab) | `ErrorState` card, retry button, `status-error` accent on text only | Same component, dark token values | Retry icon on trailing edge, message right-aligned | Same, dark token values |

Two device-level checks specific to this screen, both inherited from `DARK_MODE.md → Device and platform checks` rather than re-derived: a physical OLED pass confirms the kill-switch row's `negative` border does not exhibit banding against the darkest canvas token (`--ink-975`), and `forced-colors: active` (Windows High Contrast) is verified to defer to system colors for the `Switch`/`Slider`/`Select` focus rings rather than fighting the OS palette with QAYD's own token values — material here specifically because this screen has more native form controls in a single view (Switch, Select, Slider, numeric Input) than almost any other screen in the product.

# Accessibility

Automation Center targets the same WCAG 2.2 AA floor as every other QAYD screen (`docs/frontend/ACCESSIBILITY.md → Standards & Targets`), with the density of native form controls in the Rules table and the realtime-fed Activity Log driving four areas that carry extra weight here specifically.

**Landmarks and headings.** The page renders inside the shared `<main id="main-content" tabIndex={-1}>` region with exactly one `<h1>` ("Automation Center" / مركز الأتمتة). `Tabs` (Rules / Activity Log) uses Radix's own `tablist`/`tab`/`tabpanel` roles with `aria-selected` reflecting the URL-synced `?tab=` state, never a custom `div`-based tab implementation; each tab panel carries a `VisuallyHidden` `<h2>` ("Automation rules" / "Activity log") via `aria-labelledby`, so a screen-reader user's heading list enumerates two distinct regions even though only one renders at a time.

**Live regions, calibrated to this screen's own stated tone.** A `automation.rule.kill_switch_tripped` realtime push announces via `aria-live="polite"`, never `"assertive"` — this follows directly from the position Interactions & Flows already takes on the same event: "a company that never reviews a tripped rule simply keeps that rule running in `suggest_only` mode indefinitely, which is the safe default, not a degraded state requiring urgency." A safe, self-correcting brake is not an emergency, and a screen reader user's current task is never interrupted to announce one, matching `docs/frontend/ACCESSIBILITY.md → Live regions`'s three-tier model (Assertive reserved for blocking errors that stop the current task; this is not one). A new Activity Log row prepended by `automation.run.completed` while the tab is open is likewise `aria-live="polite"`, batched rather than announced per-row when several land in quick succession (a company running a busy `bank.synced`-triggered categorization rule can post several runs a minute), so the region reads "Several new automated actions logged" rather than narrating each one individually. A promotion or reconsideration recommendation appearing while the page is open is not itself pushed as a live announcement at all — consistent with States' own stance that the promotion banner "fails silently... never a visible error card," its *appearance* is equally unannounced, discoverable at the reader's own pace rather than interrupting them for something that, by design, requires no urgency.

**RBAC-aware disabled controls always explain themselves, and this screen is careful to distinguish two different kinds of "cannot."** A `Switch`, `Select`, or `Slider` disabled because the viewer lacks `ai.automation` (an Accountant, a Payroll Officer viewing a rule outside their own module) pairs `disabled` with a `Tooltip`-linked `aria-describedby` naming the missing permission, following `docs/frontend/ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`'s exact pattern verbatim:

```tsx
// components/automation/autonomy-select.tsx (excerpt)
<Tooltip>
  <TooltipTrigger asChild>
    <span>
      <Select disabled={!canMutate} aria-describedby={!canMutate ? 'autonomy-disabled-reason' : undefined}>
        {/* ...options... */}
      </Select>
    </span>
  </TooltipTrigger>
  {!canMutate && (
    <TooltipContent id="autonomy-disabled-reason" role="tooltip">
      {t('a11y.permission_denied', { permission: 'ai.automation' })}
    </TooltipContent>
  )}
</Tooltip>
```

This is deliberately never conflated with the *structural* absence of the `Auto` option on a rule whose action requires a sensitive permission (AI Integration, above) — that option is not disabled, it is not rendered at all, so there is nothing for a screen reader to announce as unavailable in the first place beyond the option simply not existing in the list, exactly as intended. The one control that does carry an explanatory `Tooltip` for that case is the `Select` trigger itself ("This automation touches `bank.transfer`, which always requires human approval"), read on focus regardless of which options are present, so a keyboard/screen-reader user learns *why* the list is shorter than expected without needing to already know to look for a missing item.

**The Rules table is a plain table, not an ARIA grid — a deliberate choice, stated explicitly.** Per `docs/frontend/ACCESSIBILITY.md → Data Tables Accessibility`'s "two patterns, chosen deliberately per table, never by default," Automation Center's Rules table uses real `<table>`/`<th scope="col">`/`<td>` semantics rather than the `role="grid"` pattern `docs/frontend/ACCESSIBILITY.md`'s own Custom widget roles table reserves for the Journal Entry line editor and the Bank Reconciliation matching grid — because those two are spreadsheet-like, cell-by-cell keyboard-navigable editing surfaces, while a Rules table row's own controls (`Switch`, `Select`, `Slider`) are simply cells containing ordinary interactive elements a screen reader tabs through in document order, with no need for arrow-key cell navigation between them. The Activity Log's per-run expand reuses the platform's "Sidebar collapsible section" pattern from the same Custom widget roles table — `aria-expanded` on the row's trigger, `aria-controls` pointing at the revealed `reasoning`/`sources` region — rather than a bespoke disclosure implementation.

**The confidence Slider is never drag-only.** Every `Slider` on this screen ships paired with a numeric `Input` for the identical value, as Components Used already promises ("paired with a numeric `Input` for direct entry (see Accessibility)"): the Radix `Slider` primitive supports arrow-key stepping (1 percentage point per press, `Home`/`End` to jump to 0/100) and is fully operable without a pointer, but precise entry of an exact threshold — "set this to exactly 94, not roughly 94" — is materially easier by typing than by arrow-stepping eighty-odd times, so the Input is never a secondary, harder-to-discover affordance; both controls are wired to the same underlying form value and update each other on change, and the Input itself carries a real, visible `<label>` ("Confidence threshold, percent"), never a placeholder standing in for one, per `docs/frontend/ACCESSIBILITY.md → Forms Accessibility`'s "labels, never placeholders" rule.

**Focus management.** Opening Rule Detail moves focus to the `Sheet`'s heading and traps it within the sheet per `docs/frontend/ACCESSIBILITY.md → Focus Management`'s dialogs-and-sheets pattern; closing it (via `Escape`, the close control, or a successful mutation) returns focus precisely to the row that opened it, never to the top of the page, so a keyboard user reviewing rule after rule down the table is never forced to re-navigate from scratch between each one. The kill-switch reset `Dialog`, the Auto-confirmation `Dialog`, the scope-editor `Dialog`, and the Undo confirmation `Dialog` all follow the identical trap-and-return contract; none of the four ever auto-closes on a background click while a required field (the reset flow's `review_note`, the reject-adjacent reason fields elsewhere in the product) is populated but unsubmitted, preventing an accidental pointer slip from silently discarding typed input.

**Reduced motion is honored, not merely present.** The promotion banner's carousel transition (when more than one recommendation is pending), Rule Detail's Framer Motion expand/collapse, and the confidence Slider's live-preview line all read from the shared `useReducedMotion` hook (`docs/frontend/DARK_MODE.md → Examples`) rather than each maintaining an independent `matchMedia` listener — with the preference set, the promotion carousel switches to a plain stacked list with no transition at all, and Rule Detail's sections render in their final state immediately rather than animating open.

# Performance

**Code splitting.** `docs/frontend/FRONTEND_ARCHITECTURE.md → Code splitting`'s automatic route-level boundary already isolates `app/(app)/automation`'s bundle from every other route; beyond that, `TrendSparkline`'s charting dependency and the `Sheet`/`Dialog` primitives specific to Rule Detail and the scope editor are `next/dynamic`-split so a Payroll Officer who only ever glances at this screen's read-only rows never downloads the charting library their own view never renders a chart from.

**RSC prefetch stays narrow.** `app/(app)/automation/page.tsx` prefetches only the Rules list's default, first-page query (`automationKeys.list({})`) before the shell streams — matching the Command Center's own "only the single most time-critical query is awaited" discipline (`docs/frontend/FRONTEND_ARCHITECTURE.md → Streaming with Suspense`) — while the Activity Log tab's query is not prefetched server-side at all, since `?tab=rules` is the default landing state for the overwhelming majority of visits; a user who switches to Activity Log pays one client-side fetch at that moment rather than every visitor paying an unused server round trip for a tab most sessions never open.

**Virtualization for the Activity Log.** `ai_automation_runs` is, by construction, the same "unbounded, append-only" shape as `ledger-entries`/`audit-logs`/`stock-movements` (Data & State, above), so once a company's history grows past a page or two, the Activity Log renders through `@tanstack/react-virtual` exactly as those tables do (`docs/frontend/FRONTEND_ARCHITECTURE.md → Virtualization for large tables`) — a long scroll session across many loaded pages never mounts more row DOM nodes than are actually visible in the viewport, regardless of how many cursor pages `useInfiniteQuery` has accumulated in cache.

**Debounced input, stable placeholders.** The catalog's `title`/`rule_key` search debounces at 300ms before issuing a new query key (Interactions & Flows, above), and every filtered view uses `placeholderData: keepPreviousData` so a filter change shows the previous result set, dimmed, rather than flashing to an empty table while the next result resolves — the same rule `docs/frontend/FRONTEND_ARCHITECTURE.md → Performance` applies platform-wide to every filtered list.

**Realtime cost is controlled by patch precision, not connection count.** As Data & State's Realtime subsection already establishes, all three of this screen's subscribed events patch a narrow, specific cache entry rather than invalidating `automationKeys.all` wholesale — the practical performance consequence, worth restating here explicitly, is that a company running dozens of `auto` rules across hundreds of categorizations an hour never triggers a full catalog refetch on a cadence that would otherwise make the Rules table's own `staleTime: 30_000` meaningless. The screen holds exactly one subscription (`private-company.{id}.automation`) through the platform's single shared `RealtimeProvider` connection for its entire mount lifetime (Responsive Behavior, above), never a second connection of its own.

**Web Vitals.** LCP on this route is measured against the Rules table's first meaningful paint, not the shell; CLS is held near zero because every `Skeleton` shares its loaded state's exact column widths and row height (States, above); INP is watched specifically on the confidence `Slider`'s drag interaction and the `Switch` toggle — a `Slider` is a classic INP risk surface precisely because a naive implementation re-renders and re-queries on every intermediate drag value, which is exactly why `onValueCommit` (not `onChange`) is the only event that fires a mutation (Interactions & Flows, above). Both are tagged and monitored against a company-size-band baseline per `docs/frontend/FRONTEND_ARCHITECTURE.md → Web Vitals`, since a company running 60 rules across every module and one running 8 have legitimately different tail latencies rendering the same table.

**Caching layers stay stacked, never substituted.** Of the platform's four caching layers (`docs/frontend/FRONTEND_ARCHITECTURE.md → Caching layers, stacked deliberately`), this screen's data rides entirely on TanStack Query's in-memory cache — nothing about `ai_automation_rules` or `ai_automation_runs` is eligible for the Next.js Data Cache or a CDN edge cache, both of which are reserved for the non-tenant marketing group and genuinely global reference data; every byte on this screen is scoped by `X-Company-Id`, so `app/(app)/automation/page.tsx` sets `dynamic = "force-dynamic"` and `fetchCache = "default-no-store"` unconditionally, identical to the Command Center's own route configuration.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A rule's kill switch trips while its Rule Detail sheet is already open | The `automation.rule.kill_switch_tripped` push patches that row's `kill_switch_tripped`/`kill_switch_reason` in the open sheet's own cache entry in place — the sheet does not close or reset scroll position, and its action row swaps to **Review & re-enable** live, without requiring the user to close and reopen it. |
| Two tabs; one submits the kill-switch reset while the other is mid-read of the same tripped rule | The idle tab's own reset attempt fails with a conflict response (the switch was already cleared) and re-fetches the rule fresh rather than trusting stale local state — the identical posture `docs/frontend/FRONTEND_ARCHITECTURE.md → Edge Cases` states platform-wide for a two-tab race on the same record. |
| The confidence `Slider` is mid-drag when a company switch fires | `onValueCommit`'s pending mutation is aborted rather than issued against the new `X-Company-Id`; the drag's own client-only preview line is discarded along with the rest of the screen's state per `docs/frontend/FRONTEND_ARCHITECTURE.md → Company switching`'s `queryClient.clear()` + `router.refresh()` pair — no threshold change from company A can ever land against company B's rule of the same `rule_key`. |
| An Activity Log run's `undo_available_until` elapses while its Undo confirmation `Dialog` is still open | Submitting after expiry returns a field-level `409`/`422` rendered inline in the dialog ("This action can no longer be undone — the window closed a moment ago"), never a silent failure or a generic toast; the dialog does not auto-close on this response so the user sees exactly why the action they started is no longer available. |
| A rule's `agent_code` maps to an agent a company-wide `ai_agent_settings.enabled` toggle just turned off | The affected rows keep rendering (never disappear) with an "Agent disabled" `Badge` replacing their `Switch`, which itself renders disabled with a `Tooltip` explanation distinct from a permission-caused disablement ("This agent is turned off for your company — re-enable it in AI settings to resume this rule") — exactly the "Consider disabling" recommendation's own trigger condition (AI Integration, above), now shown as a persistent row state rather than only a one-time banner. |
| A company's subscription tier or module licensing drops a module this screen has rules for (e.g., Payroll seats removed) | The now-unlicensed module's rules render a "Retired" `Badge` in place of their autonomy controls rather than vanishing from the catalog — their 30-day history and every past Activity Log entry remain visible and filterable, preserving audit continuity for a company that re-adds the module later or an Auditor reviewing a prior period. |
| WebSocket disconnected for an extended period, then reconnects | Every realtime-fed query key on this screen (`automationKeys.all`, the open Activity Log page) is invalidated once as a batch on reconnect — a kill-switch trip or a promotion suggestion generated during the outage must surface immediately once connectivity returns, never wait for a manual refresh, matching the platform's general reconnect posture. |
| A promotion recommendation's underlying evidence changes between generation and the human's click (an override lands in the seconds between) | The client re-fetches the rule's own current `runs_30d`/`overrides_30d` immediately before submitting **Promote** and diffs it against the recommendation's stored evidence; on divergence, **Promote** is replaced with "Refresh recommendation" rather than promoting a rule on evidence that has since moved — the identical stale-proposal posture `docs/frontend/APPROVAL_CENTER.md → Interactions & Flows` applies to a changed approval target. |
| A scope-editor `Dialog` submission passes client-side Zod validation but fails a server-only policy (e.g., `max_amount_kwd` exceeds a company-wide ceiling not encoded in the frontend schema) | The `422` renders inline under the specific field inside the still-open dialog, never a toast — per the platform's "a `422` is never a toast" rule — and the dialog's other, valid fields retain their entered values rather than resetting the whole form. |
| A platform release ships a new `rule_key` for an existing company | The new row is seeded server-side at `enabled: true`, `autonomy: suggest_only` — the identical conservative default a brand-new company's entire catalog receives at provisioning (States, above) — and appears in the existing company's catalog on next load carrying a small "New" `Badge` for one release cycle, never silently merged into the list indistinguishable from a rule the company has already been operating for months. |
| A user's `ai.automation` permission is revoked mid-session (a role change by an Owner) | The next mutation attempt receives `401 PERMS_STALE` (per `docs/api/AUTHENTICATION_API.md`'s `perms_ver`-checking middleware); every `Switch`/`Select`/`Slider` on screen reverts to its disabled, tooltip-explained state once the session re-establishes with current permissions, rather than continuing to render controls the user can no longer act on. |
| Printing or exporting this screen | Not supported as a dedicated action, matching `docs/frontend/AI_COMMAND_CENTER.md`'s own Command Center stance: the Rules table's autonomy controls and the Activity Log's confidence badges have no meaning on paper or in a detached CSV, and this screen deliberately offers no bulk or export affordance at all (Interactions & Flows, above) — a compliance need for automation history is a Reports module concern (`docs/frontend/REPORTS.md`), not a bespoke button bolted onto this configuration screen. |

# End of Document
