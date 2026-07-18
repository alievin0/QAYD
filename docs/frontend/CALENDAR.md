# Financial Calendar — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: CALENDAR
---

# Purpose

The Financial Calendar is QAYD's single, unified surface for every dated financial obligation and scheduled event a company must track — tax filing deadlines and VAT periods, payroll run and release dates, month-end and year-end close targets, approval due dates, recurring bills and invoices, and vendor contract renewals — rendered as a real month/week/agenda calendar rather than as five separate lists a Finance Manager has to mentally merge. It answers one question no other screen in the platform answers directly: **what is due, on what day, across the whole business, this week and this quarter.** Dashboard answers "where do the numbers stand"; the AI Command Center answers "what has the autonomous workforce found and proposed"; the Financial Calendar answers "what happens, and when" — and it is the one place a company's entire compliance and cash-obligation rhythm is visible on a single axis: time.

This document is the binding frontend specification for that screen: its route and access model, its layout as a specialization of `LAYOUT_SYSTEM.md`'s List Page Template, the components it composes, the endpoints and cache keys it reads, its interactions, its AI surface, its state matrix, its responsive collapse to an agenda list, its RTL/Hijri behavior, its dark-mode treatment, its accessibility grid semantics, its performance budget, and its edge cases.

**Calendar composes; it does not own.** Every date the Calendar plots — a `tax_returns.due_date`, a `payroll_periods.pay_date`, a `fiscal_periods.end_date`, an `ai_approval_requests.sla_due_at`, a `procurement_contracts.end_date` — was computed, validated, and persisted by the module that actually owns that record. The Calendar never recomputes a due date, a readiness percentage, or a renewal window from first principles; it asks each owning module's own endpoint for its own dated rows (reusing, wherever one already exists, the exact endpoint another screen already reads — `GET /api/v1/tax/deadlines`, `GET /api/v1/approvals`, `GET /api/v1/ai/command-tasks` — rather than re-deriving a parallel and potentially divergent source of truth) and projects the union onto a grid. This is the same discipline `REPORTS.md` states for its own engine — "every module owns its own numbers; Reporting only composes them" — applied here to time instead of to money.

**Calendar is not one of the platform's ten fixed Sidebar modules.** `NAVIGATION_SYSTEM.md` is explicit that "the Sidebar's ten entries are a fixed, platform-defined list" (Dashboard, Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI) and this document does not contradict that. The Financial Calendar is a cross-module utility screen, positioned in the navigation system the same way `NAVIGATION_SYSTEM.md` itself positions the Approval Center — "not nested under [any single module]... a cross-module queue every sensitive action from every module routes through" — reachable by the mechanisms `# Route & Access` below defines (Command Palette, a Topbar quick-access icon, and deep-links from the screens that already surface a narrower slice of the same underlying dates), never by growing the fixed module map to eleven entries.

**Relationship to existing, narrower calendar-shaped surfaces.** Three AI Command Center widgets already surface a fragment of what the Financial Calendar shows in full, and this document is careful to extend rather than duplicate each: the Morning Briefing's `todays_calendar` section (`ai/AI_COMMAND_CENTER.md`, prose lines, today only, non-interactive); the `tax_deadlines` widget (a tax-only countdown-chip list, 60-day rolling window, `1x1` size token); and the `todays_tasks` widget (`GET /api/v1/ai/command-tasks`, a vertical feed of `ai_command_tasks` rows, today-scoped). The Financial Calendar is the full superset — every category, any month, past or future, in a real grid — and it is the two-way hinge between these fragments and the whole: every one of those widgets gains a "View in Calendar" affordance that deep-links into `/calendar?date={date}&highlight={sourceId}`, and the Calendar itself never re-implements their narrower logic, it reads the same backing rows.

# Route & Access

| | |
|---|---|
| Route | `app/(app)/calendar/page.tsx` (Server Component) + `app/(app)/calendar/loading.tsx` (shell-level skeleton) |
| Reachability | **Not a primary Sidebar module.** Reachable via: (1) the ⌘K Command Palette (`"Open Financial Calendar"`, indexed under `NAVIGATION_SYSTEM.md`'s "rarely-used destinations... reachable by search rather than by growing the nav tree"); (2) a new Topbar quick-access icon (`CalendarDays`, placed between `AiStatusIndicator` and `NotificationsBell`) — this document's own addition to `LAYOUT_SYSTEM.md`'s `Topbar({ breadcrumb })` signature, noted explicitly here the same way `DASHBOARD.md` reconciled an earlier route-drift against `FRONTEND_ARCHITECTURE.md`: the Topbar's composition as stated in `LAYOUT_SYSTEM.md` (breadcrumb, `CommandPaletteTrigger`, `AiStatusIndicator`, `NotificationsBell`, `UserMenu`) should be read as extended by one icon-button slot for Calendar, not contradicted; (3) deep-links from the Morning Briefing, the `tax_deadlines`/`payroll_alerts`/`todays_tasks` widgets, Dashboard's AI Summary Rail, and any per-record expiry notification (`vendor.certificate.expiring_soon`, `fiscal_period.closing_reminder`, `quotation.expiring_soon`). |
| Gating permission | **None at the route level** — identical in spirit to Dashboard's own rule ("Dashboard carries no single visibility permission... widgets self-filter"). Every authenticated member of a company can open `/calendar`; each event **category** independently enforces its own owning module's read permission server-side, exactly as `# Data & State` details. A role with zero permission across every category still sees the shell, the toolbar, and an explanatory empty grid — never a "you don't have access" wall. |
| Breadcrumb | `Calendar` only, a single non-linked crumb — Calendar is IA-root-adjacent (a cross-module utility), matching the Approval Center's own breadcrumb precedent rather than nesting under any of the ten modules. |
| Company/branch scope | Fixed to the active `X-Company-Id`; branch is an optional, URL-mirrored filter (`?branch={id}`), identical in mechanism to Dashboard's Filter Bar — a branch-filtered Calendar view is a shareable link. |

Because no single permission gates the route, the same URL renders structurally different content per role — exactly the pattern Dashboard already establishes for this style of screen. The permission keys each category's own backing endpoint checks, reused verbatim from the module that owns the underlying record (never re-derived):

| Category | Permission checked | Owning module |
|---|---|---|
| Tax & Statutory Filings | `tax.read` | Tax (`tax_deadlines` widget's own gate, reused) |
| Payroll Run & Release Dates | `payroll.read` | Payroll |
| Accounting Close (fiscal period / year-end) | `accounting.read` (view), `accounting.period.close` / `accounting.fiscal_year.close` (act) | Accounting |
| Approval Due Dates | `reports.read` (view), `ai.approve` (act) | Cross-module (Approval Center's own gate, reused) |
| Contract Renewals & Certificate Expiries | `purchasing.read`, `accounting.vendor.read` | Purchasing / Vendors |
| Recurring Bills | `purchasing.bill.read` | Purchasing |
| Recurring Invoices | `sales.read` | Sales |
| AI-Surfaced Risks & Recommendations | `reports.read` | AI Command Center (`ai_command_tasks`, reused) |

No control on this page is ever rendered disabled-and-unexplained: a category whose read permission is absent for the current role is omitted from the Category Filter entirely (its own toolbar chip does not render, matching the existing-precedent rule in `COMPONENT_LIBRARY.md`'s row-action convention that a control's *existence* can itself be sensitive information), while a category the role *can* see but which is simply empty this month (no obligations happen to fall in the visible range) renders its own dedicated empty state rather than being hidden — see `# States`.

# Layout & Regions

The Financial Calendar does not introduce a sixth page template. Per `LAYOUT_SYSTEM.md`'s own rule — "every screen in QAYD is an instance of exactly one of five page templates... if a new screen doesn't fit, the template is extended (a new named slot), not bypassed" — the Financial Calendar **specializes the List Page Template**, the same way `FRONTEND_ARCHITECTURE.md`'s route tree already notes Bank Reconciliation specializes the Detail Page Template "with a specialized match-grid main column." Concretely: the List Page Template's Page Header, Filter Bar, and Bulk Action Bar regions are retained as-is in spirit (title, a record-ish count, a filter bar); its Data Region — ordinarily a `DataTable` — is replaced by a **Calendar Grid** (or, below `md:`, an **Agenda List** — see `# Responsive Behavior`); and its Pagination Footer is replaced by a **period navigator** (Today / ‹ Prev / Next › plus a "jump to date" popover), because a calendar is paginated by time, not by row offset.

At `lg:` and above, the Calendar Grid additionally borrows the Dashboard Template's 8/4 split: the grid occupies the 8/12 "Chart Region" analog and a right-hand **Upcoming Risks & Deadlines** rail occupies the 4/12 "Insights Feed" analog — the identical region math `DASHBOARD.md` already establishes for its own Chart Region / AI Summary Rail pair, reused here rather than invented fresh, because both screens share the same underlying shape: a large deterministic visualization beside a narrow, independently-scrolling AI-authored feed.

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│  Calendar                              [Today] [‹ July 2026 ›] [📅 Jump to date] │  ← Page Header
├─────────────────────────────────────────────────────────────────────────────────┤
│ [Month|Week|Agenda]  ● Tax ● Payroll ● Close ● Approvals ● Purchasing [Branch ▾] [⇩ Export ▾] │ ← Filter Bar
├───────────────────────────────────────────────────────────┬─────────────────────┤
│  Sun    Mon    Tue    Wed    Thu    Fri    Sat             │ Upcoming Risks &     │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐                │ Deadlines            │
│  │ 28│ │ 29│ │ 30│ │ 1 │ │ 2 │ │ 3 │ │ 4 │                │ ─────────────────    │
│  └───┘ └───┘ └───┘ │●JE│ └───┘ └───┘ └───┘                │ ▸ KSA VAT Q2 — 15d   │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐                │   92% AI 95%         │
│  │ 5 │ │ 6 │ │ 7 │ │ 8 │ │ 9 │ │10 │ │11 │                │ ▸ Payroll cutoff 25th │
│  └───┘ └───┘ └───┘ └───┘ └───┘ └───┘ └───┘                │ ▸ Al-Fajr contract    │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐                │   renews in 12d      │
│  │12 │ │13 │ │14 │ │15 │ │16 │●│17 │ │18 │  ← Today ring   │                     │
│  └───┘ └───┘ └───┘ └───┘ └───┘ └───┘ └───┘                │ [View all →]         │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐●┌───┐ ┌───┐ ┌───┐                │ (rail, 4/12, scrolls │
│  │19 │ │20 │ │21 │ │22 │VAT│23│ │24 │ │25 │●Payroll        │  independently)     │
│  └───┘ └───┘ └───┘ └───┘ └───┘ └───┘ └───┘                │                     │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐                │                     │
│  │26 │ │27 │ │28 │●│29 │●│30 │ │31 │●│ 1 │                │                     │
│  └───┘ └───┘ └───┘Al-Fajr│Payroll│ │VAT │ └───┘             │                     │
│                    renewal│ trough│ │due │                 │                     │
└───────────────────────────────────────────────────────────┴─────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| **Page Header** | Full width | Title "Calendar", `Today` button, `‹ Month Year ›` navigator, "Jump to date" popover (reuses `DateRangeCalendar` from `COMPONENT_LIBRARY.md`'s `PeriodPicker`) | The navigator's label reads the visible unit — a month name in Month view, a date range in Week view, "Upcoming" in Agenda view |
| **Filter Bar** | Full width, sticky below Topbar (`--z-sticky`) | View switch (`Month`/`Week`/`Agenda` segmented control), Category Filter (multi-select chips, one per category in `# Route & Access`'s table, permission-filtered), `BranchSelect`, Export menu (`Subscribe` / `Download .ics`) | View + category selection is mirrored to the URL (`?view=month&types=tax,payroll&branch=2`) exactly as Dashboard's `DashboardFilterBar` mirrors its own filters — a filtered calendar view is a shareable, bookmarkable link |
| **Calendar Grid** | `col-span-8` at `lg:`+, full width below | The month or week grid; each day cell shows up to 3 event chips plus a "+N more" affordance opening a day popover | Never mirrors its date-column order under a language change independent of the region's week-start convention — see `# RTL & Localization` |
| **Upcoming Risks & Deadlines rail** | `col-span-4` at `lg:`+, hidden below `lg:` (folded into the grid/agenda itself as inline AI-flagged chips instead) | Composite feed: top AI-surfaced items from `ai_command_tasks` plus any category whose nearest instance is inside a 14-day horizon, ranked by `(days_left ASC, severity DESC)`, capped at 5 | The *only* AI-forward, confidence-scored content region on this screen — identical framing to Dashboard's AI Summary Rail: "the smallest AI surface... because this screen's job is X, not Y" |
| **Agenda List** (replaces Calendar Grid below `md:`) | Full width | A single vertically-scrolling, date-grouped list with sticky date headers | See `# Responsive Behavior` — this is not a fallback bolted on for mobile, it is the Calendar's second first-class rendering of the identical `CalendarEvent[]` data, per `RESPONSIVE_DESIGN.md`'s "same column definitions, two renderers" rule (Pattern 1) applied to calendar data instead of table rows |

# Components Used

Every visual element is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue plus a small set of new, Calendar-scoped compositions living in `components/calendar/` per `PROJECT_STRUCTURE.md`'s module-folder convention. No primitive is hand-rolled a second time.

| Component | Source | Role on this screen |
|---|---|---|
| `Card` | `components/ui/card.tsx` (existing) | Day-popover shell, event detail Sheet body, Upcoming Risks rail item shells |
| `Badge` | `components/ui/badge.tsx` (existing), `tone` axis reused for category color | Category swatches, jurisdiction tags (`KSA`, `Kuwait`), overdue label |
| `Tooltip` | `components/ui/tooltip.tsx` (existing) | Category-icon disambiguation on a collapsed/dense day cell; "why 95%?" on an AI confidence badge |
| `Popover` | `@radix-ui/react-popover` via `components/ui/popover.tsx` (existing) | Day-cell "+N more" overflow list; the toolbar's "Jump to date" `DateRangeCalendar` |
| `Sheet` | `components/ui/sheet.tsx` (existing) | `CalendarEventDetailSheet` — slides from the inline-end edge exactly as `COMPONENT_LIBRARY.md` specifies (`right` in LTR, `left` in RTL) |
| `Dialog` | `components/ui/dialog.tsx` (existing) | Export dialog (`Subscribe` vs `Download .ics`) |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` (existing) | Export menu trigger, per-event overflow actions (Open in module / Copy link / Remind me) |
| `AmountCell` | `components/accounting/amount-cell.tsx` (existing) | Any money-bearing event (a payroll run's `total_net`, a contract's `total_committed_value`) rendered inside the detail Sheet |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` (existing) | Beside a non-base-currency amount (the KSA VAT return's SAR figures) |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Event status (`upcoming`/`due_soon`/`overdue`/`blocked`/`draft_ready`), reusing each domain's own status/tone lookup rather than inventing a Calendar-specific one |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | Rendered only on AI-surfaced chips/rail items — never on a deterministic due-date chip, see `# AI Integration` |
| `AIProposalPanel` (`compact`) | `components/ai/ai-proposal-panel.tsx` (existing) | The Upcoming Risks & Deadlines rail's actionable cards — identical three-button contract (Do it / Send for approval / Dismiss) Dashboard's own condensed rail uses |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` (existing) | Wraps every category filter chip and every deep-link action button |
| `DateRangeCalendar` | `components/accounting/date-range-calendar.tsx` (existing, underlies `PeriodPicker`'s `'date_range'` mode) | The Page Header's "Jump to date" popover — reused directly rather than building a second date-picker primitive |
| `Skeleton`, `EmptyState`, `ErrorState` | `components/ui/skeleton.tsx`, `components/shared/*` (existing) | Loading/empty/error rendering per `# States` |
| `CalendarToolbar` | `components/calendar/calendar-toolbar.tsx` (**new**) | Composes the Page Header's navigator and the Filter Bar's view switch, Category Filter, `BranchSelect`, and Export menu; writes all of it to the URL |
| `CalendarGrid` | `components/calendar/calendar-grid.tsx` (**new**) | The month/week grid itself — a `role="grid"` composition of `CalendarDayCell`s, see `# Accessibility` |
| `CalendarDayCell` | `components/calendar/calendar-day-cell.tsx` (**new**) | One day's cell: date number (Gregorian, optional Hijri sub-label), up to 3 `CalendarEventChip`s, "+N more" |
| `CalendarEventChip` | `components/calendar/calendar-event-chip.tsx` (**new**) | A single event's compact representation — category icon + swatch, truncated title, optional `ConfidenceBadge` dot |
| `CalendarEventDetailSheet` | `components/calendar/calendar-event-detail-sheet.tsx` (**new**) | Full event detail on click — dates, amount, readiness, AI reasoning if present, deep-link button, "Remind me" toggle |
| `AgendaList` | `components/calendar/agenda-list.tsx` (**new**) | The mobile/narrow-viewport renderer over the identical `CalendarEvent[]` data — date-grouped, virtualized, infinite-scroll in both directions |
| `UpcomingRisksRail` | `components/calendar/upcoming-risks-rail.tsx` (**new**) | Composes up to five condensed `AIProposalPanel`/risk rows, ranked by urgency |
| `IcsExportDialog` | `components/calendar/ics-export-dialog.tsx` (**new**) | The Subscribe/Download choice, reusing `REPORTS.md`'s existing signed-link share mechanism rather than inventing a second one |

The eight "new" components above are, as with Dashboard's own four, pure composition and a small amount of grid-specific layout logic — none introduces a new design token, a new API shape outside the `CalendarEvent` projection defined in `# Data & State`, or a new permission key.

# Data & State

## The unified `CalendarEvent` shape

Every category the Calendar plots is projected, server-side, into one discriminated shape so the client never branches its rendering logic per source table — the `CalendarGrid`, `AgendaList`, and `UpcomingRisksRail` all consume the identical `CalendarEvent[]`, never a category-specific payload:

```ts
// types/calendar.ts
export type CalendarCategory =
  | 'tax' | 'payroll' | 'accounting_close' | 'approval'
  | 'contract_renewal' | 'recurring_bill' | 'recurring_invoice' | 'ai_risk';

export type CalendarEventStatus =
  | 'upcoming' | 'due_soon' | 'overdue' | 'completed' | 'draft_ready' | 'blocked';

export interface CalendarEvent {
  id: string;                     // stable across regenerations, e.g. "tax_return-6620", "approval-88231"
  category: CalendarCategory;
  title: string;                  // resolved server-side to the caller's Accept-Language; never re-localized client-side
  date: string;                   // "YYYY-MM-DD" — the day the event plots on
  end_date: string | null;        // for span events: a VAT period, a payroll cutoff window
  all_day: boolean;
  status: CalendarEventStatus;
  days_left: number | null;       // negative once overdue; null for a completed/historical event
  readiness_pct: number | null;   // tax/payroll readiness — null when the concept doesn't apply (e.g. a contract renewal)
  amount: { value: string; currency_code: string } | null;   // NUMERIC(19,4) string per REST_STANDARDS.md
  branch_id: number | null;
  jurisdiction: string | null;    // e.g. "KSA", "Kuwait" — populated for tax/statutory events only
  ai_surfaced: boolean;           // true only for rows sourced from ai_command_tasks / ai_risk_flags
  confidence_score: number | null;
  agent_code: string | null;
  reasoning: string | null;
  source: { type: string; id: number };   // e.g. { type: 'tax_returns', id: 6620 }
  deep_link: string;              // e.g. "/tax/returns/6620"
  action_permission: string;      // the permission required to act (not merely view) this event
  recurrence: { rule: string; next_run_date: string } | null;
}
```

`amount`, `readiness_pct`, `confidence_score`, and `reasoning` are `null` rather than omitted for categories that do not carry that concept — the client's `CalendarEventChip`/`CalendarEventDetailSheet` render conditionally on `!= null`, never on a category-specific type guard, which is what lets a single component render all eight categories without an eight-way switch statement.

## Category sourcing — composition, not duplication

The Calendar's own backend introduces exactly one new composing endpoint. Every category it aggregates is sourced from an endpoint another screen already reads wherever one exists; only two genuinely new, narrow list endpoints are introduced, and both are natural missing counterparts to POST-only mutations that already exist.

| Category | Source table(s) | Endpoint reused/introduced | Permission | Notes |
|---|---|---|---|---|
| Tax & Statutory Filings | `tax_returns`, `tax_codes` | `GET /api/v1/tax/deadlines` (**reused**, unchanged — the `tax_deadlines` widget's own endpoint) | `tax.read` | Rolling 60-day window server-side is widened to the Calendar's own requested `from`/`to` via an added, backward-compatible `window_days` override param |
| Payroll Run & Release Dates | `payroll_periods.pay_date`, `payroll_runs` stage timestamps, WPS submission deadline | `GET /api/v1/payroll/periods` (**reused**) + `GET /api/v1/payroll/alerts` (**reused**, for the WPS-deadline flavor) | `payroll.read` | A `payroll_runs` row contributes up to four dated milestones when relevant (`submitted_at` target, `approved_at` target, cutoff, `pay_date`) — never a single ambiguous "payroll" dot |
| Accounting Close | `fiscal_periods.end_date`/`status`, `fiscal_years.end_date` | `GET /api/v1/accounting/fiscal-periods`, `GET /api/v1/accounting/fiscal-years` (**reused**) | `accounting.read` (view), `accounting.period.close` / `accounting.fiscal_year.close` (act) | The Calendar's own addition here is purely presentational — projecting each open period's `end_date` as a "close target" event with `days_left` computed client-side from already-returned dates, never from a recomputed business rule |
| Approval Due Dates | `ai_approval_requests.sla_due_at` | `GET /api/v1/approvals` (**reused**, the exact Approval Center endpoint, filtered `sla_due_at[between]=from,to`) | `reports.read` (view), `ai.approve` (act) | Every sensitive `subject_type` (`bank_transfer`, `payroll_release`, `tax_submission`) plots identically to a routine journal-entry approval — the Calendar never distinguishes "sensitive" visually beyond the same `StatusPill` tone the Approval Center itself already uses |
| Contract Renewals & Certificate Expiries | `procurement_contracts.end_date`/`renewal_notice_days`/`auto_renew`, `vendor_certificates.expiry_date` | `GET /api/v1/purchasing/procurement-contracts?filter[status][in]=active,expiring_soon` (**new** — the natural GET counterpart `PURCHASING.md`'s existing `POST .../renew` action was missing) + `GET /api/v1/purchasing/vendors/{id}` sub-resource (**reused**) | `purchasing.read`, `accounting.vendor.read` | A contract plots twice when both dates are inside the visible range: once at `end_date − renewal_notice_days` ("renewal notice window opens") and once at `end_date` itself ("contract expires") — never collapsed into one ambiguous marker |
| Recurring Bills | Purchasing's recurring-bill-template mechanism (`VENDORS.md`'s referenced schedule, owned by Purchasing) | `GET /api/v1/purchasing/bill-schedules` (**new**, analogous shape to `report_schedules`' own `next_run_at` pattern) | `purchasing.bill.read` | Only the *next* scheduled instance plots per template inside the visible window — a monthly template contributes one dot per month, not a single dot repeated arbitrarily far into the future |
| Recurring Invoices | `invoices.is_recurring`, `invoices.recurrence_rule` (JSONB: interval, `next_run_date`) | `GET /api/v1/sales/invoices?filter[is_recurring]=true` (**reused** resource, new filter combination already supported by `SALES.md`'s existing `recurrence_rule.next_run_date` field) | `sales.read` | Editing a recurring template never rewrites already-generated instances (per `SALES.md`'s own stated rule) — the Calendar's future-dated chips reflect the template's *current* rule; past instances keep the values they were generated with |
| AI-Surfaced Risks & Recommendations | `ai_command_tasks.due_at` where `origin IN ('risk_flag','recommendation','tax_deadline','payroll_deadline')` | `GET /api/v1/ai/command-tasks` (**reused**, the `todays_tasks` widget's own endpoint, windowed beyond "today" via the same `from`/`to` params) | `reports.read` | This is the Calendar's catch-all for anything AI-surfaced that doesn't already have a deterministic home in one of the other seven rows — an `ai_risk_flags` row never plots directly; it plots only once `APPROVAL_ASSISTANT` has attached a corresponding `ai_command_tasks.due_at`, avoiding two independent, potentially-inconsistent AI-dated sources for the same underlying flag |

The new composing endpoint itself:

```
GET /api/v1/calendar/events?from=2026-07-01&to=2026-07-31&types[tax,payroll,accounting_close,approval,contract_renewal,recurring_bill,recurring_invoice,ai_risk]&branch_id=2
```

Its Laravel controller performs no business logic of its own — it fans out to each category's own existing (or newly-added, equally thin) endpoint in parallel, applies the requesting user's permission set to silently omit categories they cannot read (never a `403` for the aggregate call itself, matching Dashboard's own "the whole page never denies; each widget's own data does"), and returns the union sorted by `date`. A category's own endpoint remains independently callable and independently correct — the aggregate is convenience, not a new authority.

## Query keys and cache tuning

```ts
// lib/query/keys.ts (calendar-scoped factories)
export const calendarKeys = {
  all: ['calendar'] as const,
  range: (from: string, to: string, filters: CalendarFilters) =>
    [...calendarKeys.all, 'range', from, to, filters] as const,
  event: (id: string) => [...calendarKeys.all, 'event', id] as const,
  upcomingRisks: (limit: number) => [...calendarKeys.all, 'upcoming-risks', limit] as const,
};
```

`CalendarFilters` is `{ branchId: number | null; types: CalendarCategory[] }`, produced once by `CalendarToolbar` and threaded down as props — the same single-source-of-filter discipline `DASHBOARD.md`'s `DashboardFilters` establishes, avoiding the class of bug where the grid and the rail disagree about which categories are active.

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Deterministic due-dates (tax, payroll, close, contracts) | `calendarKeys.range(...)` for those categories | `60_000` (60s) | These change only when a human or a scheduled job writes to their owning table — a 60s ceiling is generous, not a correctness risk |
| Approval due-dates | `calendarKeys.range(...)` for the `approval` category | `0` (always stale) | `sla_due_at` and approval status are exactly the kind of fast-moving figure Dashboard's own "live/derived figures" row treats as always-stale; kept fresh primarily via realtime invalidation |
| AI-surfaced risks | `calendarKeys.range(...)` for the `ai_risk` category, `calendarKeys.upcomingRisks` | `10_000`, `refetchOnWindowFocus: true` | Identical to Dashboard's own AI-feed row — a returning user should see what accumulated while away |
| Single event detail | `calendarKeys.event(id)` | `30_000` | Opened from an already-fetched chip; a short stale window avoids a network round trip on every Sheet open while still catching a concurrent edit within half a minute |

## Realtime

The Financial Calendar introduces **no new Reverb channel**. It subscribes to nothing beyond what `RealtimeProvider` already mounts globally (`private-company.{id}.ai-jobs`, `private-company.{id}.notifications.{user_id}`), and it extends the existing per-event invalidation tables `JOURNAL_ENTRIES.md`, `PAYROLL.md`, `TAX.md`, and `PURCHASING.md` already define with one additional purge target apiece — the same pattern `DASHBOARD.md` uses rather than a parallel bus:

| Domain event (already fired today) | Additional invalidation this document adds |
|---|---|
| `tax_return.filed` / `.status_changed` | `calendarKeys.range(...)` for the `tax` category |
| `payroll_run.posted` / `.disbursed` | `calendarKeys.range(...)` for the `payroll` category |
| `fiscal_period.closed` / `.reopened` | `calendarKeys.range(...)` for the `accounting_close` category |
| `approval.decided` (any `ai_approval_requests` status change) | `calendarKeys.range(...)` for the `approval` category |
| `procurement_contract.renewed` / `.terminated`, `vendor.certificate.updated` | `calendarKeys.range(...)` for the `contract_renewal` category |
| Any new `ai_command_tasks` row or `due_at` change | `calendarKeys.range(...)` for the `ai_risk` category, `calendarKeys.upcomingRisks` |

A reconnect after a dropped WebSocket invalidates every `calendarKeys.*` entry once, as a single batch — the identical reconnect rule `AI_COMMAND_CENTER.md` and `DASHBOARD.md` both already state.

## AI agents feeding this screen

| Agent | Contribution to the Financial Calendar |
|---|---|
| `TAX_ADVISOR` | Supplies each tax event's `readiness_pct` and drafts the underlying return — identical role to the `tax_deadlines` widget, reused rather than re-run |
| `COMPLIANCE_AGENT` | Independently verifies a tax draft's completeness before its readiness is allowed to show 100%; flags a vendor certificate or contract nearing expiry via `ai_command_tasks` |
| `PAYROLL_MANAGER` | Supplies WPS/Mudad submission-deadline flavoring on payroll events, identical to `payroll_alerts` |
| `APPROVAL_ASSISTANT` | Computes `can_execute_directly` for every approval-category chip's action button, and is the sole author of every `ai_command_tasks` row the `ai_risk` category reads |
| `FORECAST_AGENT` | Where a cash-flow trough (`cash_flow_status` widget) coincides with a payroll or tax due-date, supplies the narrative that ties the two together inside the event detail Sheet's "why this matters" line — never a separate widget of its own on this screen |
| `CEO_ASSISTANT` | Reads the Calendar's own `from`/`to`-windowed data as one of the Morning Briefing's inputs (`todays_calendar`), in the opposite direction of every other row in this table — this is the one agent that *consumes* Calendar data rather than supplying it |

## Personal reminders — a thin, additive preference layer

A "Remind me" toggle on any event's detail Sheet does not create a second notification pipeline. It writes a per-user lead-time override that layers on top of the owning module's own existing default notification (`fiscal_period.closing_reminder`, `journal.approval_overdue`, `vendor.certificate.expiring_soon`, and so on, all already defined in their respective module docs) — delivered through the identical `NotificationsBell` and `private-company.{id}.notifications.{user_id}` channel every other notification in the platform already uses:

```sql
CREATE TABLE user_calendar_preferences (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    user_id            BIGINT NOT NULL REFERENCES users(id),
    source_type        VARCHAR(60) NOT NULL,   -- e.g. 'tax_returns', 'procurement_contracts'
    source_id          BIGINT NOT NULL,
    lead_time_days     SMALLINT NOT NULL DEFAULT 3,
    channel            VARCHAR(16) NOT NULL DEFAULT 'in_app' CHECK (channel IN ('in_app','email','both')),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (user_id, source_type, source_id)
);
```

This mirrors `user_dashboard_layouts`' own established pattern exactly (a small, per-user, additive override table that never mutates the shared record it annotates) — `PATCH /api/v1/users/me/calendar-preferences` writes it, and it is read once, alongside the owning module's own default SLA, at the moment that module's existing notification job runs; it never spawns a second, competing reminder job.

## iCal export

`GET /api/v1/calendar/events.ics?from=&to=&types[]=` streams `text/calendar`, permission-filtered server-side identically to the JSON endpoint (an external calendar app never receives an event the requesting user's role could not otherwise see), one `VEVENT` per `CalendarEvent`. `UID` is `{category}-{source_id}@qayd.app` — stable across regenerations, so a subscribed external calendar (Outlook, Google Calendar, Apple Calendar) updates an existing entry in place rather than duplicating it on every re-sync. `DTSTAMP` is always UTC per `REST_STANDARDS.md`'s timestamp rule; `SUMMARY`/`DESCRIPTION` are localized to the requesting user's locale at generation time, matching the exact static-snapshot posture `REPORTS.md`'s PDF/Excel export already commits to ("exporting never re-triggers a fresh query with different filters"). The Export menu offers two distinct actions rather than one ambiguous button: **Download .ics** (a one-shot file for a chosen range) and **Subscribe** (a signed, rolling `webcal://` URL an external calendar polls periodically), reusing the exact signed, time-limited link mechanism `REPORTS.md`'s external Share already defines rather than inventing a second signing scheme. A requested export window wider than two years is generated asynchronously as a `report_runs`-style background job rather than blocking the request, matching `REPORTS.md`'s own "heavy is asynchronous" threshold rule applied to this narrower case.

# Interactions & Flows

**Opening the screen.** First paint resolves to Month view, the current calendar month, with Today ringed (`accent-600` outline wash, never a filled block, so a day's chips remain fully legible). `CalendarGrid` and `UpcomingRisksRail` stream in behind independent `<Suspense>` boundaries exactly as Dashboard's own regions do — a slow `ai_command_tasks` fetch never delays the grid's first paint, and a failure in the rail never takes the grid down with it.

**Navigating months.** `‹ Prev` / `Next ›` and `Today` write the new visible range to the URL (`?date=2026-08-01`, the first day of the new visible month) via `router.push`, triggering a scoped refetch of `calendarKeys.range(...)` for the new window; the previous month's data is kept as `placeholderData: keepPreviousData` so the grid dims rather than flashes empty during the transition. Adjacent months are prefetched on hover/focus of the `‹`/`›` controls, so the common "click Next twice in a row" path resolves from cache on the second click. `PageUp`/`PageDown` perform the identical navigation from the keyboard (see `# Accessibility`); `Ctrl+Home` (`Cmd+Home` on macOS) jumps straight back to the month containing Today regardless of how far the user has navigated.

**Switching Month / Week / Agenda.** The view switch is a URL param (`?view=week`) and a per-user default persisted via `PATCH /api/v1/users/me/preferences` — the identical mechanism `LAYOUT_SYSTEM.md` already uses for Sidebar collapsed-state persistence, reused here rather than introducing a second preferences endpoint. Switching view never refetches data; all three renderers consume the same already-cached `CalendarEvent[]` window, only their layout differs.

**Filtering by category.** Category chips in the Filter Bar are a multi-select, mirrored to `?types=tax,payroll` — unchecking a category removes its chips from the grid instantly (a client-side filter over already-fetched data, not a new network call) but the *next* month navigation's fetch requests only the still-checked categories, so a user who has permanently hidden "Recurring Invoices" never pays for that category's query at all. A branch change, unlike a category change, does trigger a fresh fetch, since branch scoping happens server-side.

**Opening a day.** A day cell showing 1–3 events renders its chips directly; a day with more renders the first 2 chips plus a "+N more" affordance that opens a `Popover` listing every event for that day, sorted by category then by `days_left`. Clicking any chip — whether inline or inside the overflow popover — opens `CalendarEventDetailSheet`.

**The event detail Sheet.** Renders: title, category badge, the exact date(s) (a span event shows both `date` and `end_date`), `StatusPill`, `AmountCell` where `amount != null`, `readiness_pct` as a small progress indicator where non-null, an AI reasoning block with `ConfidenceBadge` where `ai_surfaced` is true, a "Remind me" toggle (writes `user_calendar_preferences`, see `# Data & State`), and exactly one primary action: **Open in {module}**, a plain navigation to `deep_link` — never a modal, never an inline edit. The deep-link target per category:

| Category | `deep_link` target |
|---|---|
| Tax | `/tax/returns/{returnId}` |
| Payroll | `/payroll/runs/{runId}` |
| Accounting Close | `/accounting/fiscal-periods?highlight={periodId}` |
| Approval | `/approvals/{approvalId}` |
| Contract Renewal | `/purchasing/vendors/{vendorId}?tab=contracts&highlight={contractId}` |
| Recurring Bill | `/purchasing/bills/new?template={scheduleId}` |
| Recurring Invoice | `/sales/invoices/{invoiceId}` |
| AI-Surfaced Risk | Whatever the underlying `ai_command_tasks.origin_id` resolves to — usually `/ai/risks?highlight={flagId}` or `/approvals/{approvalId}` |

**Acting on an approval-category or AI-risk-category event directly from its chip.** Where `action_permission` is held and `can_execute_directly` is true (the same server-computed field `AI_COMMAND_CENTER.md` and `DASHBOARD.md` both already rely on), the detail Sheet exposes the identical three-button `AIProposalPanel` contract — Do it / Send for approval / Dismiss — never a bespoke fourth affordance invented for this screen. A sensitive action (`bank.transfer`, `payroll.approve`, `tax.submit`) never renders a one-click "Do it" here regardless of confidence, exactly as the platform-wide rule requires; its Sheet instead always shows **Review in Approval Center** as the sole action.

**Exporting.** The Export menu's **Download .ics** immediately streams a file for the currently-filtered category set and a user-chosen range (defaulting to the visible month); **Subscribe** opens `IcsExportDialog`, which surfaces the signed `webcal://` URL with a Copy button and a short explanation that it updates automatically as events change, without ever re-prompting for the same permission decision a page reload would otherwise require.

**Toggling the Hijri overlay.** A single per-user, per-locale display toggle (`calendar_system: 'gregorian' | 'hijri'`, part of the same preferences object the view-switch default lives in) adds a small secondary date line under each day's Gregorian number (e.g. "١٤ ذو الحجة") when enabled — it never replaces the Gregorian date as the primary, authoritative value, and it changes nothing about what is sent to or read from the API: every `date`/`end_date` the client ever transmits remains the Gregorian `YYYY-MM-DD` string `REST_STANDARDS.md` fixes platform-wide. The Hijri string itself is produced entirely client-side via `Intl.DateTimeFormat(locale, { calendar: 'islamic-umalqura' })` — no new backend field, no new table — and is a display-only convenience consistent with the `hijri_month` value QAYD's own AI memory schema already anticipates as a legitimate recurrence basis (`ai/memory/BUSINESS_MEMORY.md`'s `cycle_basis` enum includes `'hijri_month'` alongside `'gregorian_month_range'`), so this toggle is an extension of an already-anticipated need, not a new one.

# AI Integration

The Financial Calendar's AI surface follows the exact posture `DASHBOARD.md` establishes for its own screen — deliberately the smallest AI surface a screen can carry while still being honest about what is and is not AI-authored — because this screen's job is "what is due, and when," not "what has the workforce found." Every AI-sourced element still obeys the platform-wide contract in full, with one distinction specific to this screen stated up front:

**Deterministic dates never carry a confidence badge; AI-surfaced dates always do.** A `tax_returns.due_date`, a `payroll_periods.pay_date`, a `fiscal_periods.end_date`, and an `ai_approval_requests.sla_due_at` are all facts already validated and persisted by their owning module — no agent is guessing when they fall, so their `CalendarEventChip` renders as a plain, category-colored chip with no `ConfidenceBadge` and no accent left-edge. Only genuinely AI-surfaced rows — an `ai_command_tasks` row of `origin = 'risk_flag'` or `'recommendation'`, or a `TAX_ADVISOR`-computed `readiness_pct` — carry the platform's standard AI provenance treatment: `border-s-2 border-s-accent`, the small "AI" badge, and a `ConfidenceBadge` whose tooltip surfaces `reasoning`. Mixing the two without this visual distinction would be the single most misleading thing this screen could do — a user must never wonder whether "the 31st" is a government-set deadline or an agent's best guess.

- **Confidence and reasoning are never hidden.** Every AI-surfaced chip and every Upcoming Risks & Deadlines rail card carries a `ConfidenceBadge`, with the underlying `reasoning` available on hover/focus via the badge's own tooltip slot — no bare percentage, ever, matching `COMPONENT_LIBRARY.md`'s stated contract.
- **The three-button pattern is exact, not approximated.** `can_execute_directly` is a field the API computes and returns per event; the Calendar's own `CalendarEventDetailSheet` never independently re-evaluates a recommendation's autonomy level or confidence threshold.
- **Sensitive actions never terminate on this screen.** Approving a payroll release, a bank transfer, or a tax submission surfaced from a Calendar event always routes through the Approval Center (`/approvals`), exactly as every other entry point into that flow does across the platform.
- **A readiness percentage is not a promise.** `readiness_pct` (tax, and by extension payroll cutoff readiness) reflects how much of the underlying period's source data is posted/reconciled *as of the last computation*, not a guarantee the filing itself is error-free — the detail Sheet's copy is careful to say "92% of source transactions posted," never "92% likely correct," a distinction `TAX.md`'s own `tax_deadlines` widget documentation already draws and this screen inherits verbatim.
- **The Upcoming Risks & Deadlines rail ranks, it does not decide.** Its ordering (`days_left ASC, severity DESC`) is a deterministic sort over already-computed fields; no agent decides what belongs at the top beyond what `ai_command_tasks.priority`/`ai_risk_flags.severity` already encode.

# States

Every region has its own loading, empty, and error presentation, matching Dashboard's own "no region blocks another, none ever shows a bare spinner" rule.

| Region | Loading | Empty | Error |
|---|---|---|---|
| Calendar Grid (Month/Week) | Skeleton day cells at the grid's final dimensions (a 6×7 or 7×1 shimmer sweep, 1.6s, `ink-3` → `ink-4` → `ink-3`) | A genuinely quiet range (every checked category returns zero rows) renders a calm "Nothing due in {month} — you're all caught up" message, explicitly not styled as an error; a brand-new company with no tax registrations, payroll, or vendors configured yet instead renders a distinct "Set up Tax, Payroll, or Purchasing to see deadlines here" card with links into each module's own onboarding, so the two very different reasons for "empty" are never conflated | Widget-level `<ErrorBoundary>` renders an inline "Couldn't load this month — Retry" card; a category-specific failure (e.g. the Tax category's own endpoint times out while Payroll and Approvals succeed) degrades to showing every other category's chips normally with a small "Tax deadlines unavailable" inline note on affected days, never a whole-grid failure for one category's outage |
| Agenda List (mobile) | 6–8 skeleton rows grouped under a skeleton date header | Same two-way empty distinction as the grid, rendered as a single centered message rather than a grid overlay | Inline retry row; infinite-scroll simply stops offering further pages rather than erroring the whole list |
| Upcoming Risks & Deadlines rail | Three skeleton cards | "No AI-surfaced risks or deadlines in the next 14 days" — calm, not alarming, matching `DASHBOARD.md`'s identical AI-rail empty-state posture | A distinct "AI insights are temporarily unavailable" state (not a generic error) when the FastAPI layer itself returns `503`, honoring any `Retry-After` header — the grid beside it is entirely unaffected since it never calls the AI engine for its deterministic categories |
| Category Filter chips | Renders synchronously from session permissions; never shows a loading state | A role with zero permitted categories renders the toolbar with no chips at all and the grid's own empty state explains why | Not applicable — no network call of its own |
| Export dialog | Button shows a `loading` spinner state (per `Button`'s own `loading` prop) while a large `.ics` generation is queued asynchronously | Not applicable | An async export job that fails surfaces via the standard Notifications Bell (`report.heavy_run.failed`-equivalent), the same channel `REPORTS.md` already uses for a failed heavy report |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (e.g. the session itself becoming invalid mid-render); per the platform's three-granularity error model this should essentially never fire for an individual category's ordinary `4xx`/`5xx`.

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | **The Calendar Grid does not render at all.** The screen renders `AgendaList` exclusively — a single date-grouped, vertically-scrolling list with sticky date headers, virtualized and bidirectionally infinite (scrolling up loads earlier dates, scrolling down loads later ones), per the task's own explicit requirement and consistent with `RESPONSIVE_DESIGN.md`'s founding rationale for every adaptive pattern in the platform: a 7-column grid at 360px would force sub-40px day cells and sub-24px event chips, well under the platform's 44×44px touch-target floor, and a data-entry-hostile transformation is exactly the failure mode `RESPONSIVE_DESIGN.md` exists to prevent. `AgendaList` and `CalendarGrid` are two renderers over the identical `CalendarEvent[]` data, per `RESPONSIVE_DESIGN.md`'s Pattern 1 ("same column definitions, two renderers") applied to calendar data instead of table rows — a category or field added to one is automatically available to the other. The bottom tab bar replaces the Sidebar per the platform-wide `md:` threshold; Calendar is reached from it only via the Topbar icon carried into the mobile header, not as one of the five thumb-reachable tab-bar destinations (which remain Dashboard, Accounting, Sales/Purchasing, Approvals, and AI, per `NAVIGATION_SYSTEM.md`'s own mobile tab-bar list). |
| `md` (768–1023px) | Week view becomes viable (7 columns of a taller single row fit comfortably); Month view remains available from the view switch but is cramped in portrait — the toolbar's default suggestion at this tier is Week, not Month, though the user's own last-chosen view (persisted per `# Interactions & Flows`) always wins over any tier-based suggestion. The Upcoming Risks & Deadlines rail folds beneath the grid rather than beside it. |
| `lg` (1024–1279px) | Full Month grid becomes the comfortable default; the 8/4 Grid/Rail split activates, identical in mechanism to Dashboard's Chart Region / AI Summary Rail pair. |
| `xl`+ (≥1280px) | Same layout with generous margins; the global AI Rail companion panel may additionally dock inline without competing for the Upcoming Risks rail's own column, exactly as `DASHBOARD.md` describes for its own AI Summary Rail. |

`CalendarDayCell` and `CalendarEventChip` use Tailwind v4 container queries (`@container`), not only viewport breakpoints, so the same chip degrades gracefully from "icon + truncated title + status dot" at a comfortable desktop cell width down to "icon only, full label on tooltip/focus" as the cell narrows in Week view on a smaller `lg:` window — never truncating a category's identity down to an unlabeled colored square, which would fail `# Accessibility`'s "color is never the only channel" rule on its own.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md`/`LAYOUT_SYSTEM.md`'s RTL contract, applied concretely to this screen's specific content.

- **The grid mirrors as a whole; day numerals never do.** Flipping `dir="rtl"` reverses the Filter Bar's control order, the rail's position relative to the grid, and the reading direction of the week itself — but every day-of-month numeral renders inside a `dir="ltr"` / `unicode-bidi: isolate` span with `numberingSystem: 'latn'`, identically to how `AmountCell` already isolates every monetary figure — a "١٤" is never shown in place of "14" for the day number itself (Hijri sub-labels, which *are* Arabic-Indic by convention, are the one deliberate exception, and only render as a secondary line, never the primary numeral).
- **Week-start is a region setting, not a language setting.** Kuwait and the wider Gulf run a Sunday–Thursday work week; the Calendar's week-start defaults to Sunday for `region ∈ {KW, SA, AE, QA, BH, OM}` regardless of interface language — an English-language user working in Kuwait still sees a Sunday-first grid, and an Arabic-language user in a region with a Monday-start convention sees Monday-first. This mirrors the platform's existing region-vs-language separation (`S.settings.region` driving business behavior independent of `S.settings.lang` driving display language) rather than conflating the two.
- **The `Calendar` icon itself never mirrors.** `LAYOUT_SYSTEM.md`'s RTL icon-mirroring exception table already lists `Calendar` (alongside `Search`, `Bell`, `Settings`, `User`, `Home`, `Filter`) among icons that keep their orientation under `dir="rtl"` — the Topbar's `CalendarDays` trigger and every category-filter icon in this screen are drawn from that same non-mirroring set, not re-decided here.
- **Month and day names are fully localized**, not merely translated labels bolted onto an English grid structure — `Intl.DateTimeFormat('ar', { month: 'long', weekday: 'long' })` drives every header, so "يوليو ٢٠٢٦" / "الثلاثاء" render as a fluent Arabic professional would write them, matching the platform's stated voice discipline that Arabic copy is authored/verified by a fluent writer, never machine-translated in place.
- **The Hijri overlay is additive, never authoritative** — see `# Interactions & Flows`; it is the one place this screen shows two calendar systems on the same cell, and it is always the Gregorian numeral that remains primary and load-bearing.

| Context | English | Arabic |
|---|---|---|
| Page title | Calendar | التقويم المالي |
| Category — Tax | Tax & Statutory | الضرائب والالتزامات |
| Category — Approval | Approval Due Dates | مواعيد الاعتماد |
| Empty state | Nothing due in July 2026 — you're all caught up. | لا توجد التزامات مستحقة في يوليو ٢٠٢٦ — كل شيء تحت السيطرة. |
| Readiness copy | 92% of source transactions posted | تم ترحيل ٩٢٪ من المعاملات المصدرية |
| Remind me | Remind me 3 days before | ذكّرني قبل ٣ أيام |

# Dark Mode

The Financial Calendar introduces no new color, elevation, or radius token — every surface resolves through the same `:root[data-theme="dark"]` remap `COMPONENT_LIBRARY.md` already defines. The one screen-specific application worth stating explicitly is the category-swatch legend, since a calendar is unusually color-dependent as a genre and this platform is unusually restrained about color:

| Category | Token (light) | Token (dark, already remapped by `COMPONENT_LIBRARY.md`) | Icon |
|---|---|---|---|
| Tax & Statutory | `--qayd-warning-600` | `--qayd-warning-600` (dark value) | `Percent` |
| Payroll | `--qayd-info-600` | `--qayd-info-600` (dark value) | `Users` |
| Accounting Close | `--qayd-ink-700` | `--qayd-ink-700` (dark value) | `BookOpenCheck` |
| Approval Due Dates | `--qayd-accent-600` | `--qayd-accent-600` (dark value, already lifted for contrast) | `CheckCircle2` |
| Purchasing (contracts, recurring bills/invoices) | `--qayd-success-600` | `--qayd-success-600` (dark value) | `ShoppingCart` |
| **Overdue** (structural override, any category) | `--qayd-danger-600` | `--qayd-danger-600` (dark value) | Category icon retained; only the swatch color overrides |

This is a closed, fixed six-swatch legend — five category identities plus one structural overdue override that takes precedence over category color the instant `days_left < 0`, regardless of which category the event belongs to. Calendar events are never user-recolorable; the deliberate absence of a "pick your own calendar color" affordance (the default pattern in most consumer calendar apps) is a direct application of the founder's stated taste rejecting a loud, customizable-color, generic-SaaS register in favor of one disciplined, closed palette. Today's date ring uses `accent-600` at reduced opacity as a background wash in both themes — a token-only combination, not a new hex value, identical in spirit to how `KpiTile`'s threshold states already reuse existing semantic tokens rather than inventing new ones per screen.

# Accessibility

The Financial Calendar targets WCAG 2.1 AA as a floor, identically across both languages and both themes, and its grid is the platform's second genuinely spreadsheet-like `role="grid"` implementation after the Journal Entry line editor — it reuses that exact skeleton rather than inventing a parallel one.

- **`role="grid"` with a calendar-specific roving tabindex.** `CalendarGrid` renders `role="grid"` on its container (`aria-rowcount={weekCount}`, `aria-colcount={7}`), `role="row"` per week, `role="gridcell"` per day — identical structural roles to `ACCESSIBILITY.md`'s Journal Entry line editor pattern, with the same one-cell-at-a-time roving tabindex (`tabIndex={0}` on exactly the active day, `-1` on every other):

  ```tsx
  function useRovingCalendarGrid(weekCount: number) {
    const [active, setActive] = useState({ week: 0, day: 0 });
    function onKeyDown(e: React.KeyboardEvent) {
      const deltas: Record<string, [number, number]> = {
        ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1],
      };
      if (e.key === 'PageUp') { e.preventDefault(); return navigateMonth(-1); }
      if (e.key === 'PageDown') { e.preventDefault(); return navigateMonth(1); }
      if (e.key === 'Home') { e.preventDefault(); return setActive((a) => ({ ...a, day: 0 })); }
      if (e.key === 'End') { e.preventDefault(); return setActive((a) => ({ ...a, day: 6 })); }
      if ((e.key === 'Home' && e.ctrlKey) || (e.metaKey && e.key === 'Home')) { e.preventDefault(); return jumpToToday(); }
      const delta = deltas[e.key];
      if (!delta) return;
      e.preventDefault();
      setActive(({ week, day }) => ({
        week: clamp(week + delta[0], 0, weekCount - 1),
        day: clamp(day + delta[1], 0, 6),
      }));
    }
    return { active, onKeyDown };
  }
  ```

  `←`/`→` respect the mirrored visual order automatically under `dir="rtl"` for the identical reason `ACCESSIBILITY.md` states for the Journal Entry grid: the DOM's row/column indices, not the visual paint direction, drive the mapping, so the hook needs no RTL-specific branch. `PageUp`/`PageDown` move by month, `Home`/`End` jump to the first/last day of the active week, and `Ctrl/Cmd+Home` jumps to Today — a calendar-specific extension of the base pattern, matching the ARIA Authoring Practices' Date Picker Dialog keyboard model.
- **Every day cell's accessible name states date and load.** `aria-label="Tuesday, July 28, 2026 — 2 events, 1 overdue"`, generated from the cell's own `CalendarEvent[]`, never a bare date with the event count left to be inferred visually from the chips.
- **Every event chip is a real, labeled button.** `aria-label="Tax — KSA VAT Q2 return, due July 31, 15 days left"`, never an icon-only unlabeled colored dot; the overflow "+N more" affordance carries `aria-label="2 more events on July 28"` and opens a `Popover` whose first item receives focus.
- **Month navigation announces politely.** Changing the visible month updates an `aria-live="polite"` region ("Now showing July 2026") — assertive announcement is reserved for nothing on this screen, matching the platform's "realtime updates announce politely, never assertively" rule from `DASHBOARD.md`.
- **Color is never the only channel.** Every category swatch is paired with a distinct Lucide icon (see `# Dark Mode`'s legend table), and the overdue override additionally carries the word "Overdue" in the chip's accessible name and in its visible `StatusPill` text, not merely a red color shift.
- **Focus management on drill-down.** Opening `CalendarEventDetailSheet` traps focus within it (`Dialog`/`Sheet`'s existing Radix focus-trap behavior, untouched); clicking "Open in {module}" navigates to a new route and focus lands on that destination's own heading via the platform's standard route-change focus reset, introducing no bespoke focus logic of its own.

# Performance

- **Windowed, not paginated.** The Calendar fetches exactly one month (or week, or an agenda page) at a time via `from`/`to`, never the whole year — the same "small, indexed working set renders synchronously" principle `REPORTS.md` states for light reports, since a single month's due-dates across four-to-eight source tables is a cheap, well-indexed query set.
- **Adjacent-range prefetch.** Hovering or focusing `‹`/`›` prefetches the neighboring month's `calendarKeys.range(...)` query, so the common back-and-forth browsing pattern resolves instantly from cache.
- **Virtualized agenda.** `AgendaList` uses the same TanStack Virtual approach `DataTable` already uses for >200-row tables, applied to a potentially unbounded bidirectional date scroll rather than a bounded row count.
- **Streamed, not blocking.** Per `# Data & State` and `# States`, `CalendarGrid` and `UpcomingRisksRail` are independent `Suspense` boundaries; a slow AI-engine round trip for the rail never delays the grid's first paint.
- **Export is async above a size threshold.** A requested `.ics` range beyond two years queues as a background job rather than holding the HTTP connection open, per `REPORTS.md`'s async-if-heavy rule.
- **Bundle budget.** The route's shell (excluding the `next/dynamic`-split month/week grid rendering logic itself, which a role with zero calendar-relevant permissions never downloads beyond the empty-state shell) is held to the platform's stated first-load JS budget, enforced in CI against `performance-budgets.json`.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| The same day carries events from four different categories at once (a realistic month-end: a tax deadline, a payroll cutoff, a period close target, and a contract renewal notice all landing on the 31st) | All four chips render, sorted by category then by `days_left`; if the day cell's fixed height cannot fit all of them the "+N more" affordance activates rather than silently dropping the least-recent one — no event is ever hidden without an explicit, clickable "more" affordance naming how many are hidden. |
| An event's underlying record is deleted, cancelled, or reversed after the Calendar's own query already cached it (a draft tax return deleted, a contract renewal terminated) | The next realtime invalidation or the next natural refetch removes it; if a user clicks a chip in the brief window before that invalidation lands, `CalendarEventDetailSheet`'s own fetch of `calendarKeys.event(id)` returns a 404 and the Sheet shows "This no longer exists — it may have been deleted or reversed" rather than a blank or crashed panel. |
| A company has not yet configured Tax registrations, Payroll, or any Vendors | The corresponding category chip(s) either do not render in the Filter Bar (if the role also lacks the read permission) or render but are permanently empty with the dedicated "Set up X to see deadlines here" empty state from `# States`, never indistinguishable from a month that simply has nothing due. |
| A recurring bill or invoice template is edited mid-cycle | Per `PURCHASING.md`'s and `SALES.md`'s own stated rule that editing a recurring template does not alter already-generated instances, the Calendar's already-plotted past instances keep the values they were generated with; only future-dated chips (beyond the template's own `next_run_date`) reflect the edited rule. The Calendar never retroactively "corrects" a historical chip to match a template that has since changed. |
| The company operates multiple branches/jurisdictions (a Kuwait HQ and a Saudi sales office, each with its own VAT registration and payroll cycle) | Every jurisdiction's obligations plot side by side on the same grid, each carrying its own `jurisdiction` tag (`KSA`, `Kuwait`) exactly as the `tax_deadlines` widget already does for the identical company shape; the `BranchSelect` filter narrows the view but the unfiltered default always shows every branch a role holds permission across, never silently defaulting to only one. |
| The company's fiscal year is not calendar-aligned (e.g. April–March) | Accounting Close events plot on the company's actual `fiscal_years.end_date`/`fiscal_periods.end_date`, never on December 31 — a hard-coded calendar-year assumption is exactly the kind of bug this document's own reliance on `fiscal_periods`/`fiscal_years` (rather than a client-side date-math shortcut) is designed to prevent. |
| A `DATE`-only field (`due_date`, `pay_date`, `end_date`) sits at a month boundary | Per `REST_STANDARDS.md`, these fields carry no time component and no timezone — they are calendar dates in the company's own fiscal calendar, not instants — so no timezone-conversion pass is ever applied to them, and no midnight-boundary ambiguity can arise. Only a genuinely `TIMESTAMPTZ`-based field (`ai_approval_requests.sla_due_at`) is converted to the company's configured timezone before being bucketed onto a specific day cell. |
| A role holds zero read permission across every category (a narrow custom role, e.g. a Warehouse Employee) | The Calendar Grid still renders — every day empty — with a single explanatory message rather than a page-level "you don't have access" wall, mirroring Dashboard's identical "minimal but never broken" precedent for a maximally narrow role. |
| The AI engine (FastAPI layer) is down or returns `503` for an extended period | The Calendar Grid and Agenda List are entirely unaffected, since none of their seven deterministic categories call the AI engine — only the Upcoming Risks & Deadlines rail and any `ai_surfaced` chip's `ConfidenceBadge` show the "temporarily unavailable" treatment from `# States`, the same direct payoff `DASHBOARD.md` describes for its own AI-outage edge case. |
| A user wants a printed or PDF copy of a month's obligations | Not supported as a literal "print this page" action, for the identical reason `DASHBOARD.md` declines it: a screenshot of independently-cached, independently-timestamped, confidence-scored content is not a defensible single-instant record. The Calendar instead offers two real substitutes with actual provenance — the iCal export (`# Data & State`) and, for a formatted document, `REPORTS.md`'s own export pipeline generating a genuine `report_runs` snapshot rather than a raw render of this screen. |
| Two browsing tabs have the Calendar open on different months, and a realtime domain event fires (e.g. `tax_return.filed`) | Per `# Data & State`'s realtime table, the invalidation targets `calendarKeys.range(...)` for whatever window each tab currently has open — a tab looking at August is untouched by an invalidation whose affected date falls in July, since the query key itself encodes the visible range; there is no cross-tab synchronization requirement beyond what TanStack Query's own per-key invalidation already provides. |

# End of Document
