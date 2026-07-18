# Notifications Center — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: NOTIFICATIONS
---

# Purpose

`components/layout/notifications-bell.tsx`, as shipped in `NAVIGATION_SYSTEM.md`, has carried the same code comment since that document was written:

```tsx
<PopoverContent className="w-80 p-0" align="end">
  {/* scrollable list of the 20 most recent, each row's onClick marks-read + navigates to its own `target`;
      capped at 20 with infinite scroll inside the popover — a full-history /notifications page is a
      natural future extension, not a route this tree defines today */}
</PopoverContent>
```

This document is that extension. It does two things, precisely. First, it specifies the body of that popover in full — `NotificationPopoverFeed`, the component the comment above describes but never implements. Second, and primarily, it specifies the full-page surface the comment names but declines to build: `app/(app)/notifications`, the Notifications Center — a permanent inbox over every notification a user has ever received, with categorized filtering, bulk read/unread management, inline approve/reject for approval-shaped notifications, and a per-category, per-channel preference matrix. Once this document ships, `/notifications` is a real, canonical route exactly as `NAVIGATION_SYSTEM.md → Routing & Deep Links` requires of every AI- or notification-surfaced `target` — the comment above should be read as superseded by the concrete implementation below, the same way `DASHBOARD.md` formally superseded a stale redirect clause in `AI_COMMAND_CENTER.md`'s own route table. Nothing about the Topbar bell's existing behavior — its channel, its query key, its 20-row cap — changes; this document extends it, it does not replace it.

This document does not own the underlying notification system's data model, its delivery channels, or its domain-event fan-out — those are owned, respectively, by `docs/database/ERD.md → Notifications` (the `notification_templates` / `notifications` / `notification_preferences` tables), `docs/database/DATABASE_EVENTS.md` (the domain-event catalogue and the `events-notifications` queue consumer that fans a domain event out to email/SMS/push/Reverb broadcast), and `docs/foundation/SYSTEM_ARCHITECTURE.md`'s Notification Engine (which channels exist at all). This document is authoritative only for how a user sees, triages, acts on, and configures notifications inside the QAYD web application — the screen, its components, its data-fetching, and its behavior across breakpoints, direction, theme, and assistive technology, in the same relationship `DASHBOARD.md` and `AI_COMMAND_CENTER.md` (frontend) already hold to their own backing product/data documents.

Three platform facts, restated here because this screen is where a user is most likely to act on them without opening the record they describe:

1. **A notification is a routing surface, never a bypass of the human-approval gate.** An approval-request notification embeds the identical `ApprovalCard` component and calls the identical `POST /api/v1/approvals/{id}/approve` mutation the Approval Center itself uses (`AI_COMMAND_CENTER.md` frontend, `AI Integration → Approval affordances, specifically`) — approving from a notification is a second entry point into one mutation, not a second, competing implementation of approval. Nothing rendered on this screen executes a sensitive action (`bank.transfer`, `payroll.approve`, `tax.submit`, a permission change, a deletion) on a single click merely because it arrived as a notification rather than a queue row.
2. **The backing schema is fixed and this document does not extend it with new columns.** `notifications` carries `id, company_id, user_id, notification_template_id, channel, title, body, data (JSONB), read_at, sent_at, delivery_status, created_at` (`ERD.md → notifications`); `notification_preferences` carries `id, company_id, user_id, event_code, channel, is_enabled` (`ERD.md → notification_preferences`), unique per `(company_id, user_id, event_code, channel)`. Every field this document renders, filters by, or writes to is one of these columns, or a value nested inside the already-JSONB `data` column — no new table, no new persisted column.
3. **Five channels are named in this document's brief — in-app, email, SMS, WhatsApp, push — and the platform's own foundation documents do not yet agree on whether that is four channels or five.** `SYSTEM_ARCHITECTURE.md`'s Notification Engine lists Email, SMS, WhatsApp, Push Notifications, and In-App Notifications as what it supports. `ERD.md → notification_templates` constrains `channel` to `CHECK (channel IN ('in_app','email','sms','push'))` today and names WhatsApp explicitly under its own **Future Expansion** note: "WhatsApp Business API as a fifth channel, following the same template shape." This document does not resolve that inconsistency by picking a side — it builds the Preferences matrix as a five-channel grid from day one (because the UI is what a user configures their expectations against, and re-adding a column later is a worse experience than shipping it inert), with the WhatsApp column rendered in a locked, "Coming soon" state until the `channel` CHECK constraint and its delivery integration land, per `# Edge Cases`. This is the same forward-compatible-without-lying posture `NAVIGATION_SYSTEM.md` takes toward a plan-gated feature: visible so the capability is discoverable, disabled with a stated reason so nothing is promised prematurely.

The category taxonomy this document renders — **Approvals Due, AI Alerts, Fraud & Risk, Deadlines, System** — is a presentation grouping over `notification_templates.code`, not a new database column and not frontend business logic. `GET /api/v1/notifications` returns each row with a server-computed `category` attribute (derived from the owning template's `code`, the same way an invoice resource's derived `is_overdue` boolean is computed server-side rather than by the client comparing dates); this document treats `category` exactly like it treats `read` (`read_at IS NOT NULL`) — a resource attribute the frontend renders and filters by, never one it re-derives from a raw event-code string. The reference mapping below (`# Data & State → Category taxonomy`) exists so an engineer implementing either side of this contract can reason about which of the platform's already-catalogued domain events (`DATABASE_EVENTS.md`) and which `ai_risk_flags`/`ai_decisions` shapes (`docs/ai/AI_COMMAND_CENTER.md`) land in which tab — it documents an intended server-side mapping, it does not assign the frontend the job of computing one.

# Route & Access

Notifications Center is reached through the same two doors `NAVIGATION_SYSTEM.md` already names for the full-history surface: the Topbar bell's "View all" link, and a direct/bookmarked URL. It is not an eleventh entry in the Sidebar's fixed ten-module map (`NAVIGATION_SYSTEM.md → Primary Navigation`) — like `/approvals`, it is a cross-cutting, chrome-adjacent utility screen that belongs to every role's own inbox rather than to one of the platform's ten business modules, and the Sidebar's module list is not touched by this document.

| | |
|---|---|
| Routes | `app/(app)/notifications/page.tsx` (Inbox), `app/(app)/notifications/preferences/page.tsx` (Preferences), sharing `app/(app)/notifications/layout.tsx` |
| Chrome entry points | Topbar `NotificationsBell` → "View all" (opens Inbox, filtered to whatever category the popover was scoped to, if any); `UserMenu` gains one new item, "Notification settings" (opens Preferences directly) |
| Gating permission | **None.** Exactly like Dashboard (`DASHBOARD.md → Route & Access`) and the Command Center (`AI_COMMAND_CENTER.md` frontend, `Route & Access`: "no dedicated permission that gates the route itself"), every authenticated member of a company owns their own notification inbox and their own preferences row-set. `notifications` and `notification_preferences` are `user_id`-scoped, not role-scoped — a Warehouse Employee and an Owner each see only their own rows, and each can always reach this screen. |
| Breadcrumb | `Notifications` — a single, non-linked, root-level crumb, the same pattern Dashboard and the Approval Center use for a screen with no parent module |
| Tab/segment nav | Two tabs, `Inbox` (default) and `Preferences`, rendered as real `<Link>`s in the shared layout per `FRONTEND_ARCHITECTURE.md`'s module-layout convention ("the tabs are `<Link>`s, not client-side tab state, so each is a real navigable, bookmarkable, back-button-safe URL") |
| Company/branch scope | Fixed to the active `X-Company-Id`; there is no branch filter — a notification belongs to a user within a company, not to a branch, so the Filter Bar carries no `BranchSelect` |

Because there is no route-level permission, this screen renders identically in structure for every role; what varies per notification is only the actions its own embedded content permits. An approval-request notification's embedded `ApprovalCard` checks the same `PERMISSION_BY_KIND` map the Approval Center uses (`COMPONENT_LIBRARY.md → ApprovalCard`: `journal_entry → accounting.journal.approve`, `ai_recommendation → ai.approve`, `bank_transfer → bank.transfer`, `payroll_release → payroll.approve`, `tax_submission → tax.submit`) — a Senior Accountant sees the identical notification row a CFO sees, with Approve/Reject rendered but disabled-with-tooltip if it is not yet their turn in a multi-step chain, exactly as `AI_COMMAND_CENTER.md` frontend's Approval Center Interaction spec describes. A fraud/risk-sourced notification's inline **Acknowledge** action is available to any recipient (acknowledging carries no elevated permission, per `ai/AI_COMMAND_CENTER.md → Detected Risks`: "`acknowledged` (a human has seen it, no action taken yet)"), while **Resolve** and **Dismiss** (both requiring a note/reason) are never rendered inline on this screen at all — see `# Interactions & Flows` for why that is a deliberate scope boundary rather than an oversight.

Preferences carries no permission gate either: every user edits only their own `notification_preferences` rows. The one universal exception, applying to every role including the Owner, is that security-critical event codes (`permission.changed`, `audit.security_event`) render their channel toggles locked on, per `ERD.md → notification_preferences`'s own stated rule: "security-critical event codes... are exempted from opt-out at the application layer regardless of this table's content." This is a data policy, not an RBAC rule, and this document's job is only to render the lock truthfully — see `# Edge Cases`.

# Layout & Regions

## Topbar popover (extends `NAVIGATION_SYSTEM.md`, does not redefine it)

The popover's trigger, badge, unread-count query, and Reverb subscription are exactly as `NAVIGATION_SYSTEM.md → Notifications bell` already specifies; this document supplies only the previously-unimplemented body:

```
┌───────────────────────────────────────┐
│  Notifications              [Mark all read] │  ← header row, 40px
├───────────────────────────────────────┤
│ ● JE-1091 needs your approval    2m   │  ← unread: bold title, accent dot
│   Requested by S. Rahman              │
├───────────────────────────────────────┤
│ ● Vendor payment held — Al-Fajr  14m  │  ← AI/fraud: AiCardShell left border
│   Bank details changed 2h before send │
├───────────────────────────────────────┤
│   Invoice INV-2026-000248 overdue  1h │  ← read: regular weight, no dot
│   KWD 525.000 was due Aug 15          │
├───────────────────────────────────────┤
│  … up to 20 rows, capped …            │
├───────────────────────────────────────┤
│         [ View all in Notifications → ]│  ← footer, always present
└───────────────────────────────────────┘
```

`NotificationPopoverFeed` renders each of the (up to 20) most-recent rows via `NotificationRow` in its compact `density="popover"` presentation — single-line title, one line of truncated body, a relative timestamp, no category chips, no bulk-selection checkboxes. The footer's "View all in Notifications" link is always present and always the same target regardless of scroll position, mirroring `KpiTile`'s and `RecentActivityFeed`'s own "View all" precedent (`DASHBOARD.md → Layout & Regions`).

## Inbox (`app/(app)/notifications/page.tsx`) — an extended List Page Template

`LAYOUT_SYSTEM.md → Page Templates` states plainly that a screen never invents a sixth layout shape — "if a new screen doesn't fit, the template is extended (a new named slot), not bypassed." Notifications Center's Inbox is the **List Page Template** with one addition: a category filter row above the ordinary Filter Bar, and a feed region in place of a dense `DataTable`, because a notification is a heterogeneous card (an approval, a risk flag, a plain reminder), not a uniform row of typed columns — the same reasoning `AI_COMMAND_CENTER.md` frontend already gives for rendering AI Insights as a feed rather than a `DataTable` ("An infinite-scroll feed... newest first").

```
┌───────────────────────────────────────────────────────────────────┐
│  Notifications                                    [Mark all read] │  ← Page Header
│  482 unread of 3,140                    Inbox │ Preferences        │  ← count + tab strip
├───────────────────────────────────────────────────────────────────┤
│ [All] [Approvals Due 3] [AI Alerts] [Fraud & Risk 1] [Deadlines] [System] │ ← category chips
│ ( ) All   (•) Unread only            [Search…]        [Date range ▾]     │ ← Filter Bar
├───────────────────────────────────────────────────────────────────┤
│ ☐  ● JE-1091 · KD 45,220 needs your approval          2m   [Approve][Reject] │ ← approval row
│    Requested by S. Rahman · Approval 1 of 2
├───────────────────────────────────────────────────────────────────┤
│ ☐  ▌● Vendor payment held — Al-Fajr Cement          93%  14m  [Acknowledge] │ ← fraud/risk row (AiCardShell)
│    Bank details changed 2 hours before scheduled payment · Review in Detected Risks →
├───────────────────────────────────────────────────────────────────┤
│ ☐    Invoice INV-2026-000248 is overdue                    1h  [View invoice →] │ ← deadline row
│    KWD 525.000 was due 2026-08-15
├───────────────────────────────────────────────────────────────────┤
│  … cursor-paginated, "Load more" / infinite scroll …               │
└───────────────────────────────────────────────────────────────────┘
```

| Region | Content |
|---|---|
| Page Header | Title, live unread/total count ("482 unread of 3,140"), "Mark all as read" secondary action (disabled when `unread === 0`), the `Inbox`/`Preferences` tab strip |
| Category Filter Row | Six chips — `All`, `Approvals Due`, `AI Alerts`, `Fraud & Risk`, `Deadlines`, `System` — each carrying its own unread sub-count badge; exactly one is active at a time |
| Filter Bar | `All`/`Unread only` segmented toggle, `Input` search (`q`, matches `title`/`body`), a `PeriodPicker` in `mode="date_range"` |
| Bulk Action Bar | Appears only when ≥1 row is selected, replacing the Filter Bar's end side with "`{n}` selected · Mark read · Mark unread · Clear", per `LAYOUT_SYSTEM.md`'s List Template convention verbatim |
| Feed Region | `NotificationFeed` — cursor-paginated, virtualized past 200 rows (`RESPONSIVE_DESIGN.md`/`COMPONENT_LIBRARY.md`'s shared virtualization threshold), one `NotificationRow` per notification |
| Load-more Footer | "Load more" button plus automatic infinite-scroll trigger; never numbered pages, since `paginationMode="cursor"` per `COMPONENT_LIBRARY.md → DataTable`'s own cursor convention, reused here even though the feed is not a `DataTable` |

## Preferences (`app/(app)/notifications/preferences/page.tsx`)

```
┌───────────────────────────────────────────────────────────────────┐
│  Notifications                                                     │
│                                          Inbox │ Preferences        │
├───────────────────────────────────────────────────────────────────┤
│  Choose how you want to hear about each kind of update.             │
├───────────────────────┬────────┬────────┬────────┬───────────┬─────┤
│                        │ In-App │ Email  │  SMS   │ WhatsApp  │Push │
├───────────────────────┼────────┼────────┼────────┼───────────┼─────┤
│ Approvals Due          │  [on]  │  [on]  │  [off] │  Coming   │[on] │
│ AI Alerts              │  [on]  │  [off] │  [off] │  soon     │[on] │
│ Fraud & Risk           │  [on]  │  [on]  │  [on]  │  (locked) │[on] │
│ Deadlines              │  [on]  │  [on]  │  [off] │           │[off]│
│ System                 │  [on]🔒│  [on]🔒│  [off] │           │[off]│
└───────────────────────┴────────┴────────┴────────┴───────────┴─────┘
   🔒 Security notifications can't be turned off
```

| Region | Content |
|---|---|
| Page Header | Title, `Inbox`/`Preferences` tab strip, one-line explainer copy |
| Preference Matrix | Five category rows × five channel columns; each cell is a `Switch`; the `System` row's In-App and Email cells render permanently locked-on (`🔒` icon + `Tooltip`); the WhatsApp column renders every cell as a disabled "Coming soon" state across all five rows |
| Save behavior | No separate "Save" button — every `Switch` toggle fires its own `PATCH` immediately (optimistic), matching the platform's stated posture that a reversible, low-stakes preference toggle updates instantly rather than waiting on an explicit save step (`FRONTEND_ARCHITECTURE.md`'s Principle 10, "optimistic where safe") |

At `lg`+ the matrix renders as the literal grid above. Below `md` it collapses to one `Card` per category with five stacked, labeled `Switch` rows — `RESPONSIVE_DESIGN.md`'s "cards, not tables" rule for narrow finance-adjacent grids, applied here to a 5×5 preference matrix exactly as it is applied to a `DataTable`'s own priority-column collapse.

# Components Used

Nothing on this screen is a one-off. Every element is drawn from `COMPONENT_LIBRARY.md`'s catalogue, or is a documented, thin composition of it — the same discipline `DASHBOARD.md` and `AI_COMMAND_CENTER.md` (frontend) hold themselves to.

| Component | Source | Role on this screen |
|---|---|---|
| `NotificationsBell` | `components/layout/notifications-bell.tsx` (existing, `NAVIGATION_SYSTEM.md`) | Unchanged trigger, badge, and unread query; this document only fills its popover body |
| `NotificationPopoverFeed` (**new**) | `components/layout/notification-popover-feed.tsx` | The bell popover's list body — up to 20 `NotificationRow`s in `density="popover"`, plus the "View all" footer |
| `NotificationFeed` (**new**) | `components/notifications/notification-feed.tsx` | The Inbox's cursor-paginated, virtualized list of `NotificationRow`s |
| `NotificationRow` (**new**) | `components/notifications/notification-row.tsx` | One notification, in `density="popover" \| "comfortable"`; branches its inner content by `category` — see `# Data & State` |
| `NotificationCategoryTabs` (**new**) | `components/notifications/notification-category-tabs.tsx` | The six category chips with sub-counts, built on `Tabs`/`Badge` |
| `NotificationBulkActionBar` (**new**) | `components/notifications/notification-bulk-action-bar.tsx` | Selection count + Mark read/Mark unread/Clear, mirrors `DataTable`'s own Bulk Action Bar contract |
| `NotificationPreferencesMatrix` (**new**) | `components/notifications/notification-preferences-matrix.tsx` | The 5-category × 5-channel `Switch` grid |
| `ApprovalCard` | `components/shared/approval-card.tsx` (existing) | Embedded, compact presentation, inside every `category="approval"` `NotificationRow` — the exact same component and mutations the Approval Center uses |
| `AiCardShell` | `components/ai/ai-card-shell.tsx` (existing, `FRONTEND_ARCHITECTURE.md → AI Integration Layer`) | Wraps every `category="ai_alert"` and `category="fraud_risk"` row, carrying the mandatory colored-border-plus-badge AI provenance treatment |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | Confidence pill on every AI-sourced row, normalized via `normalizeConfidence()` exactly as every other screen does |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Domain-specific status on an approval row (`pending_approval`, `held`) reusing each domain's own lookup table — never a new one |
| `Badge` | `components/ui/badge.tsx` (existing) | Category sub-count badges, unread dot's `tone="danger"` counterpart on the bell |
| `Switch` | `components/ui/switch.tsx` (existing shadcn primitive, `@radix-ui/react-switch`) | Every Preferences matrix cell |
| `Tabs` | `components/ui/tabs.tsx` (existing) | The `Inbox`/`Preferences` segment nav, and — internally — `NotificationCategoryTabs`'s chip strip |
| `Checkbox` | `components/ui/checkbox.tsx` (existing) | Per-row bulk-selection checkbox in the Inbox feed |
| `Tooltip` | `components/ui/tooltip.tsx` (existing) | `ConfidenceBadge`'s reasoning disclosure; the locked System-row toggle's explanation; the disabled WhatsApp column's "Coming soon" explanation |
| `Sheet` | `components/ui/sheet.tsx` (existing) | Below `sm`, the bell's popover renders as a full-height `Sheet` instead — see `# Responsive Behavior` |
| `PeriodPicker` | `components/accounting/period-picker.tsx` (existing), `mode="date_range"` | Inbox Filter Bar's date-range control |
| `AmountCell` | `components/accounting/amount-cell.tsx` (existing) | Any monetary figure inside a notification row (an approval's amount, an overdue invoice's balance) |
| `FormattedRelativeTime` | existing shared formatter (used identically in `ApprovalCard`) | Every row's timestamp ("2m", "14m", "1h") |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Feed- and matrix-level empty/error rendering — see `# States` |
| `Skeleton` | `components/ui/skeleton.tsx` (existing) | Loading placeholders, shaped to the feed's and matrix's final layout |
| `useApiToast` | existing hook | Wraps every mutation's success/error toast (mark read, bulk action, preference update) |

`NotificationRow` deliberately does not fork into a separate `NotificationApprovalRow`/`NotificationRiskRow` component per category — `category` is a `switch` inside one component's render body, the same pattern `StatusPill` already uses for five different status domains from one lookup table rather than five components. A new category added later (a sixth tab) is a new `case` in that switch and, if warranted, a new row in the category-taxonomy reference table below — never a sixth React component.

# Data & State

## Endpoints

| Purpose | Endpoint | Notes |
|---|---|---|
| List (Inbox feed, cursor) | `GET /api/v1/notifications` | Params: `filter[category]`, `filter[read_at]` (`null` / `not_null`), `q`, `filter[created_at][from\|to]`, `cursor`, `per_page` (default 25) |
| Unread badge (bell) | `GET /api/v1/notifications` | Params: `filter[read_at]=null`, `per_page=20` — the exact call `NotificationsBell` already makes; unchanged by this document |
| Mark one read/unread | `PATCH /api/v1/notifications/{id}` | Body `{ "read_at": "<ISO8601>" }` to mark read, `{ "read_at": null }` to mark unread |
| Bulk mark read/unread | `POST /api/v1/notifications/bulk-action` | Body `{ "ids": [9931, 9930, ...], "action": "mark_read" \| "mark_unread" }` — scoped to read-state only; see `# Interactions & Flows` for why this endpoint never accepts an `approve`/`reject`/`delete` action |
| Mark all as read | `POST /api/v1/notifications/mark-all-read` | No body; server stamps `read_at = now()` on every currently-unread row for the caller as of the request's own server time — see `# Edge Cases` for the race this framing resolves |
| Preferences (read) | `GET /api/v1/notifications/preferences` | Returns the 5-category × 5-channel grid pre-shaped server-side (see below) — the client never groups raw `event_code` rows into categories itself |
| Preferences (write) | `PATCH /api/v1/notifications/preferences` | Body `{ "category": "fraud_risk", "channel": "email", "is_enabled": false }` — the server expands this into the underlying per-`event_code` `notification_preferences` upserts; the client never enumerates event codes |
| Approve (embedded) | `POST /api/v1/approvals/{id}/approve` | Identical endpoint and `Idempotency-Key` contract the Approval Center uses (`AI_COMMAND_CENTER.md` frontend) — not redefined here |
| Reject (embedded) | `POST /api/v1/approvals/{id}/reject` | Body `{ "reason": "<non-empty>" }`, identical to the Approval Center |
| Acknowledge (embedded) | `POST /api/v1/ai/risks/{id}/acknowledge` | Identical endpoint the Detected Risks panel uses (`ai/AI_COMMAND_CENTER.md → Detected Risks`); Resolve/Dismiss are never called from this screen — see `# Interactions & Flows` |

**List response** (`GET /api/v1/notifications?filter[category]=fraud_risk&per_page=25`):

```json
{
  "success": true,
  "data": [
    {
      "id": 88231,
      "channel": "in_app",
      "category": "fraud_risk",
      "title": "Vendor payment held — Al-Fajr Cement Supplies",
      "body": "A vendor bank-detail change 2 hours before the scheduled KWD 18,540.000 payment triggered an automatic hold.",
      "data": {
        "type": "ai_risk_flags",
        "id": 55214,
        "severity": "critical",
        "status": "monitoring",
        "financial_exposure": "18540.0000",
        "agent_code": "FRAUD_DETECTION",
        "confidence_score": 96.0,
        "reasoning": "Vendor bank details changed via an unverified channel 2 hours before a scheduled payment run — this pattern matches 94% of confirmed business-email-compromise attempts in the training set.",
        "target": "/ai/risks/55214"
      },
      "read_at": null,
      "sent_at": "2026-07-16T05:42:00+03:00",
      "delivery_status": "delivered",
      "created_at": "2026-07-16T05:42:00+03:00"
    }
  ],
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": null, "per_page": 25, "total": null, "total_hint": 6, "cursor": "eyJpZCI6ODgyMzF9" } },
  "request_id": "5e3a1e6a-...",
  "timestamp": "2026-07-16T07:12:00+03:00"
}
```

`category` and `data.target` are the two fields this document adds meaning to beyond the bare `ERD.md` column list: `category` is the server-computed grouping described below, and `data.target` is a real, resolvable route string, produced and validated the same way `NAVIGATION_SYSTEM.md → Routing & Deep Links` already requires of every AI-surfaced `target` — "checked in CI: an integration test resolves every `target` value... against the actual Next.js route manifest at build time." Neither is a new database column; both live inside the already-`JSONB` `data` field or are computed at read time from `notification_template_id`'s owning `code`, exactly as `read` is computed from `read_at IS NOT NULL` rather than stored twice.

**Preferences response** (`GET /api/v1/notifications/preferences`):

```json
{
  "success": true,
  "data": {
    "categories": [
      {
        "category": "approval",
        "label_en": "Approvals Due", "label_ar": "الموافقات المستحقة",
        "channels": { "in_app": true, "email": true, "sms": false, "whatsapp": null, "push": true }
      },
      {
        "category": "ai_alert",
        "label_en": "AI Alerts", "label_ar": "تنبيهات الذكاء الاصطناعي",
        "channels": { "in_app": true, "email": false, "sms": false, "whatsapp": null, "push": true }
      },
      {
        "category": "fraud_risk",
        "label_en": "Fraud & Risk", "label_ar": "الاحتيال والمخاطر",
        "channels": { "in_app": true, "email": true, "sms": true, "whatsapp": null, "push": true }
      },
      {
        "category": "deadline",
        "label_en": "Deadlines", "label_ar": "المواعيد النهائية",
        "channels": { "in_app": true, "email": true, "sms": false, "whatsapp": null, "push": false }
      },
      {
        "category": "system",
        "label_en": "System", "label_ar": "النظام",
        "channels": { "in_app": true, "email": true, "sms": false, "whatsapp": null, "push": false },
        "locked_channels": ["in_app", "email"]
      }
    ],
    "whatsapp_available": false
  },
  "message": "OK", "errors": [], "meta": {}, "request_id": "...", "timestamp": "2026-07-16T07:12:00+03:00"
}
```

`channels.whatsapp: null` (rather than `true`/`false`) is this document's chosen signal that the channel is not yet a real opt-in/opt-out — a boolean would claim a preference is being honored when no WhatsApp delivery integration exists yet to honor it (see the reconciliation note in `# Purpose`); `whatsapp_available: false` at the payload's root is what `NotificationPreferencesMatrix` reads to render the whole column locked, rather than the client inferring "not yet available" from five `null`s scattered across five rows.

## Category taxonomy (reference mapping, server-authoritative)

The table below documents the intended `notification_templates.code` → UI `category` mapping. It is not frontend logic — no component in this document contains this table as executable code — it exists so the mapping is legible to whoever implements the corresponding server-side `category` computation, and so this document's own worked examples are traceable back to real, already-catalogued domain events (`DATABASE_EVENTS.md`) and AI structures (`docs/ai/AI_COMMAND_CENTER.md`).

| UI category | Representative `event_code`s | Backing source |
|---|---|---|
| **Approvals Due** (`approval`) | `approval.requested` (the literal example code `ERD.md → notification_templates` already names) | Any `ai_approval_requests` row entering `pending`, for any `kind` (`journal_entry`, `bank_transfer`, `payroll_release`, `tax_submission`, `ai_recommendation`) — `data.type`/`data.kind` disambiguates which `ApprovalCard` `kind` to render |
| **AI Alerts** (`ai_alert`) | `ai.finished`, `ai.proposal.created` (both literal codes, `DATABASE_EVENTS.md`) | A completed agent run or a graduated recommendation (`recommended_action` populated) surfaced as `AIProposalPanel`'s three-button pattern inside the row |
| **Fraud & Risk** (`fraud_risk`) | `ai_risk_flag.raised` (this document's own name for the notification fanned out when `ai_risk_flags` — already `ai/AI_COMMAND_CENTER.md`'s table — inserts a `high`/`critical` row) | `ai_risk_flags.category IN ('fraud','treasury','data_integrity','payroll','inventory','supplier','customer')` — i.e. every anomaly/concentration risk, regardless of that table's own finer 9-value category enum, collapses to this one user-facing tab |
| **Deadlines** (`deadline`) | `invoice.overdue` (literal, `DATABASE_EVENTS.md`), `tax_deadline.approaching`, `payroll_cutoff.approaching` (this document's names for the Upcoming Tax Deadlines and Payroll Alerts panels' own countdown items, `ai/AI_COMMAND_CENTER.md`) | Anything with a calendar due-date, including `ai_risk_flags.category IN ('compliance','tax')` when the flag is itself a filing-deadline reminder rather than an anomaly |
| **System** (`system`) | `permission.changed`, `audit.security_event` (both literal, `DATABASE_EVENTS.md`), `system.maintenance` (this document's name for platform-level announcements) | Security- and account-level events; the two literal codes are exactly the two `ERD.md`'s opt-out-exemption note calls "security-critical event codes" |

Every non-literal code above (`ai_risk_flag.raised`, `tax_deadline.approaching`, `payroll_cutoff.approaching`, `system.maintenance`) follows the exact `<noun>.<verb_past_tense>` naming convention `DATABASE_EVENTS.md`'s own catalogue already uses (`invoice.overdue`, `payroll.completed`, `bank.synced`) — they are proposed here, not invented from a different scheme, and require no new infrastructure: each already has a natural producer (an `ai_risk_flags` insert, a due-date sweep the Upcoming Tax Deadlines/Payroll Alerts panels already run) and fans out through the existing `events-notifications` queue consumer `DATABASE_EVENTS.md` already documents ("Fan-out to email/SMS/push/Reverb broadcast").

## Query keys

```ts
// lib/api/query-keys.ts (Notifications additions)
export const notificationKeys = {
  all: ["notifications"] as const,
  unread: () => [...notificationKeys.all, "unread"] as const,
  list: (filters: NotificationFilters) => [...notificationKeys.all, "list", filters] as const,
  preferences: () => [...notificationKeys.all, "preferences"] as const,
};

interface NotificationFilters {
  category: "all" | "approval" | "ai_alert" | "fraud_risk" | "deadline" | "system";
  readState: "all" | "unread";
  q?: string;
  dateRange?: { from?: string; to?: string };
}
```

One naming note for whoever wires this up next, in the same spirit as `DASHBOARD.md`'s reconciliation of `COMPONENT_LIBRARY.md`'s and `DESIGN_LANGUAGE.md`'s two ink-token numbering schemes: `FRONTEND_ARCHITECTURE.md`'s own Realtime section shows the bell's badge query key as `["notifications", "unread-count"]`,

```ts
echo.private(`company.${companyId}.notifications.${userId}`)
  .notification((n: NotificationPayload) => {
    queryClient.setQueryData(["notifications", "unread-count"], (c: number) => c + 1);
    queryClient.invalidateQueries({ queryKey: ["notifications", "list"] });
  });
```

while `NAVIGATION_SYSTEM.md`'s own shipped `NotificationsBell` component reads the shorter `["notifications", "unread"]`. Both name the same cache entry — the badge count backed by `idx_notifications_unread`. This document standardizes on the shorter form, `notificationKeys.unread()` → `["notifications", "unread"]`, because it is the one the already-implemented component uses rather than an illustrative excerpt, and treats `FRONTEND_ARCHITECTURE.md`'s realtime listener as the one needing its literal key updated to match on next edit — the same one-line reconciliation direction `DASHBOARD.md` modeled for its own cross-document drift.

## Realtime

```tsx
// components/layout/notification-popover-feed.tsx / notification-feed.tsx (shared subscription)
useRealtimeChannel(`private-company.${activeCompany.id}.notifications.${user.id}`, {
  onEvent: (n: NotificationPayload) => {
    queryClient.setQueryData(notificationKeys.unread(), (c: number = 0) => c + 1); // instant badge tick
    queryClient.invalidateQueries({ queryKey: notificationKeys.list({ category: "all", readState: "all" }) });
    if (n.category !== "all") {
      queryClient.invalidateQueries({ queryKey: notificationKeys.list({ category: n.category, readState: "unread" }) });
    }
  },
});
```

This is the identical `private-company.{id}.notifications.{user_id}` channel `NAVIGATION_SYSTEM.md` and `FRONTEND_ARCHITECTURE.md`'s channel table both already name — this document introduces no new channel. The badge count is patched directly (`+1`) for the same reason `DASHBOARD.md`'s Cash Position tile patches during an active bank sync — a snappy, high-frequency, low-risk tick — and is fully reconciled by an ordinary invalidation the next time the popover opens or the connection reconnects, per the platform's general "invalidate by default, patch only for high-frequency low-risk ticks" rule. A new row arriving while the Inbox feed is open and scrolled mid-list is never spliced in above the user's current scroll position; per `ACCESSIBILITY.md`'s live-region discipline (`# Accessibility`, below) and the identical pattern `AI_COMMAND_CENTER.md` frontend uses for its own Approval Center queue, it surfaces as a small, dismissible "1 new notification — Refresh" banner pinned above the feed instead.

## Server-first paint

`app/(app)/notifications/page.tsx` is a Server Component prefetching the first page (`category=all, readState=all`) via `dehydrate`/`HydrationBoundary`, identical in shape to `DASHBOARD.md`'s and `AI_COMMAND_CENTER.md`'s (frontend) own pattern:

```tsx
// app/(app)/notifications/page.tsx
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { notificationKeys } from "@/lib/api/query-keys";
import { NotificationCategoryTabs } from "@/components/notifications/notification-category-tabs";
import { NotificationFeed } from "@/components/notifications/notification-feed";
import { FeedSkeleton } from "@/components/notifications/feed-skeleton";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant- and user-scoped — never statically cached

export default async function NotificationsPage() {
  const queryClient = getQueryClient();
  const defaultFilters = { category: "all" as const, readState: "all" as const };

  await queryClient.prefetchQuery({
    queryKey: notificationKeys.list(defaultFilters),
    queryFn: () => apiServer.get("/notifications", { params: { per_page: 25 } }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <NotificationCategoryTabs />
      <Suspense fallback={<FeedSkeleton rows={8} />}>
        <NotificationFeed initialFilters={defaultFilters} />
      </Suspense>
    </HydrationBoundary>
  );
}
```

## Cache tuning

| Data class | Resource | `staleTime` | Rationale |
|---|---|---|---|
| Unread badge | `notificationKeys.unread()` | `0`, realtime-corrected | Matches the bell's own existing behavior; never polled |
| Inbox list | `notificationKeys.list(filters)` | `30_000` | Frequent enough writes to want freshness, not worth sub-minute polling — the exact tier `DASHBOARD.md`'s Recent Activity feed uses |
| Preferences | `notificationKeys.preferences()` | `300_000` (5 min), no `refetchOnWindowFocus` | User-driven, rarely changed by anything but the user's own action in this same tab; a background change from another session is rare enough that a 5-minute ceiling, reconciled by the mutation's own optimistic write, is sufficient |

## AI agents feeding this screen

None. Notifications Center performs no AI computation of its own — every confidence score, every `reasoning` string, every risk severity it renders was computed by the owning agent documented elsewhere (`FRAUD_DETECTION`, `GENERAL_ACCOUNTANT`, `TAX_ADVISOR`, and the rest of the fifteen-agent roster, `ai/AI_COMMAND_CENTER.md → Agent coverage map`) and is only being re-surfaced, unmodified, as a triage item. This mirrors `DASHBOARD.md`'s own deliberate restraint ("Dashboard's AI surface is intentionally the smallest of any screen... because this screen's job is to answer 'where do the numbers stand,' not 'what has the workforce found'") — Notifications Center's job is narrower still: "what is waiting on me, across every module, in one list," never a second analysis surface competing with the Command Center's own panels.

# Interactions & Flows

**Opening the bell popover.** Unchanged from `NAVIGATION_SYSTEM.md`. Clicking a row inside it marks that one notification read (`PATCH .../{id}`, optimistic) and navigates to `data.target`; clicking "View all in Notifications" navigates to `/notifications` with no filter carried over — the popover's 20-row cap is a chrome convenience, not a persisted user choice the full page should inherit.

**Filtering by category.** Clicking a `NotificationCategoryTabs` chip updates `?category=` in the URL (mirrored, shareable, exactly like `NAVIGATION_SYSTEM.md`'s branch-filter convention) and refetches the feed; the `All`/`Unread only` toggle and the search input compose additively with whichever category chip is active. Each chip's own sub-count badge is sourced from a single `GET /api/v1/notifications/counts` companion call (grouped `category` → unread count), not six separate list requests fired just to populate badges.

**Selecting rows and bulk actions.** Checking one or more rows replaces the Filter Bar's end side with the Bulk Action Bar ("3 selected · Mark read · Mark unread · Clear"), identical in contract to `LAYOUT_SYSTEM.md`'s List Template convention. This is a deliberate, narrow scope: **the bulk action bar on this screen offers only `mark_read`/`mark_unread`, never bulk-approve, bulk-reject, or bulk-delete.** Bulk-approve remains exclusive to the Approval Center's own queue (`AI_COMMAND_CENTER.md` frontend: "Bulk-approve is offered only across a same-`subject_type`, below-threshold, non-`held` selection") so that a second, differently-scoped bulk-financial-action surface never has to be kept in lockstep with the first; a user who wants to approve several requests at once is one click away via any approval row's own "Review in Approval Center" link. There is likewise no bulk or per-row **delete** — per `ERD.md → notifications`'s own Delete Rules ("Soft/hard delete via a rolling retention job... unread notifications are retained until read"), deletion is a backend retention concern, never a user-facing action; the closest analogue, dismissing a notification, is implemented as marking it read, which is reversible (a user can always mark it unread again) in a way a delete is not.

**Marking all as read.** The Page Header's "Mark all as read" button opens no confirmation dialog — this is a reversible, non-financial, purely-cosmetic state change (any row can be individually marked unread again afterward), so it follows the platform's "optimistic where safe" posture (`FRONTEND_ARCHITECTURE.md` Principle 10) rather than the confirmation dialog a genuinely destructive bulk action would require.

**Approval-request rows.** A `category="approval"` `NotificationRow` renders `ApprovalCard` in its already-documented compact presentation (`DASHBOARD.md → Components Used`: "rendered in a `compact` presentation... same three-button contract... condensed copy" — applied here to `ApprovalCard` exactly as that precedent applies it to `AIProposalPanel`). Clicking **Approve** calls the identical `useApproveRequest` mutation the Approval Center uses, with the identical client-generated `Idempotency-Key` and identical optimistic status flip; clicking **Reject** opens the identical reason-required `AlertDialog`. Nothing about the approval flow is re-implemented for this screen — a notification is a second entry point into one mutation, matching `# Purpose`'s stated non-negotiable.

**Fraud/risk rows.** A `category="fraud_risk"` row renders inside `AiCardShell`, shows its `ConfidenceBadge` and a one-line description, and offers exactly one inline action: **Acknowledge** (a single optimistic `POST .../acknowledge` call, no dialog, mirroring `ai_risk_flags.status`'s own `open → acknowledged` transition, which `ai/AI_COMMAND_CENTER.md` explicitly documents as requiring "no action taken yet"). **Resolve** and **Dismiss** are deliberately never rendered on this screen — both require a note/reason and both mutate the same `ai_risk_flags` row the Detected Risks panel owns; offering them a second time here would mean keeping two implementations of the same status-transition logic synchronized, which is exactly the duplication `COMPONENT_LIBRARY.md`'s founding principle exists to prevent ("a screen never hand-rolls... an approval affordance... that duplicates something already specified"). Instead, every fraud/risk row carries a "Review in Detected Risks →" link to `/ai/risks/{id}`, where Resolve and Dismiss already live.

**AI Alert rows.** A `category="ai_alert"` row that has graduated to carrying a `recommended_action` renders `AIProposalPanel` directly (identical three-button contract — Do it / Send for approval / Dismiss — gated by the API-computed `can_execute_directly` field, never a client-side re-derivation, per `FRONTEND_ARCHITECTURE.md`'s restated Principle 1); a pure observation (no `recommended_action`) renders as plain text inside `AiCardShell` with an "Explain more" link that opens Ask AI pre-seeded with the notification's own `sources`, identical to `AI_COMMAND_CENTER.md` frontend's AI Insights feed.

**Deadline and System rows.** Both render as plain `NotificationRow`s with no embedded action beyond a single "View →" link to `data.target` — an overdue invoice's row links to that invoice, a tax-deadline row links to `/tax/returns/{id}`, a `permission.changed` row links to `/settings/team`. Neither category offers an inline mutation, because neither corresponds to a reversible, low-stakes action a notification row can safely shortcut — the correct next step is always "go look at the thing," never "resolve it from here."

**Deep-linking.** Every `data.target` is a real route from the same finite manifest `NAVIGATION_SYSTEM.md → Routing & Deep Links` already checks in CI ("an integration test resolves every `target` value... against the actual Next.js route manifest at build time"); this document introduces no second target-resolution scheme. Clicking any row's body (outside its embedded action buttons) marks it read and navigates, exactly matching the bell popover's own existing click contract.

**Preferences edits.** Toggling a `Switch` in the matrix fires `PATCH /api/v1/notifications/preferences` immediately with an optimistic flip (`onMutate` sets the cell, rolls back on error, toast on failure) — no batched "Save" step, consistent with `# Layout & Regions`'s stated rationale.

# AI Integration

Every AI-sourced row on this screen — `ai_alert` and `fraud_risk` categories — carries its confidence score, its reasoning, and its source citation exactly as the platform's AI Integration Layer requires everywhere else (`FRONTEND_ARCHITECTURE.md`, restated in `DASHBOARD.md` and `AI_COMMAND_CENTER.md` frontend alike): no card on this screen shows a bare number or a bare claim the AI computed without a visible way to see why.

- **`AiCardShell` is the only AI-provenance treatment used.** No new colored border, no new badge shape, is introduced for this screen — a fraud-risk notification's left border and "AI" badge are pixel-identical to the same risk's card on the Detected Risks panel, because they are two renderings of the same `ai_risk_flags` row, not two different visual languages for the same fact.
- **`ConfidenceBadge` always normalizes.** `ai_risk_flags.confidence_score` and `ai_decisions.confidence_score` both arrive on a 0–100 scale; every render passes through `normalizeConfidence(score, "percentage")` exactly as every other screen does, never a screen-local percentage calculation.
- **The three-button pattern is exact, not approximated, for AI Alert rows.** `can_execute_directly` is a field the API computes; when it is `false`, "Do it" is absent, not disabled — the same structural guarantee `AI_COMMAND_CENTER.md` frontend states verbatim: "a gated action has no client-side path to execute itself."
- **A `critical`-severity fraud hold pins to the top of its category, regardless of `created_at`.** Mirroring `AI_COMMAND_CENTER.md` frontend's Approval Center rule for `status: "held"` rows ("render... ahead of ordinary `pending` items regardless of `sla_due_at`"), a `fraud_risk` notification whose underlying `ai_risk_flags.severity = 'critical'` sorts to the top of the Fraud & Risk tab and, in the `All` tab, above every non-critical row irrespective of arrival time — urgency here is never subordinate to recency.
- **Sensitive actions never terminate on this screen.** Approving a bank transfer, a payroll release, or a tax submission surfaced through a notification always routes through the identical `POST /api/v1/approvals/{id}/approve` the Approval Center uses, and — per `AI_COMMAND_CENTER.md` frontend's Mobile Experience rule — a mobile approval from this screen requires the identical biometric re-confirmation layered on top of the ordinary permission check. Nothing about arriving as a notification lowers the bar a queue row would otherwise clear.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Bell popover | Badge shows the last-known cached count, never a flash to zero, while the background refetch runs (`NAVIGATION_SYSTEM.md`'s existing rule, unchanged) | "You're all caught up" with a checkmark, no badge mounted (`unreadCount > 0`-gated, never a `0` badge) | Keeps showing the last successful count with an inline retry, never resets to an alarming, possibly-wrong `0` |
| Inbox feed (first load) | 6–8 `NotificationRow`-shaped skeletons matching each category's eventual card height | **Two distinct copies**: "You're all caught up — nothing new" (returning user, filtered to `Unread only`) vs. "No notifications yet — you'll see approvals, alerts, and reminders here as they happen" (a genuinely new account, `All` filter, zero rows ever) | Inline "Couldn't load your notifications — Retry" card; the category chips and Preferences tab remain fully usable |
| Inbox feed (category-filtered, zero matches) | N/A | A third, lighter copy: "No {category} notifications right now" — visually distinct from the two above so a user never confuses "this category is empty" with "everything failed to load" |
| Load-more (pagination) | A single trailing skeleton row, not a full-feed reset | The "Load more" control simply disappears once `cursor` is exhausted, replaced by "You've reached the end" | A failed "load more" leaves every already-loaded row intact with an inline retry at the list's own end, per `AI_COMMAND_CENTER.md` frontend's identical rule for its own AI Insights feed |
| Preferences matrix | Five skeleton rows matching the real grid's row height | N/A — the matrix always renders (every user has a full 5×5 preference set, defaulted server-side); "empty" is not a state this region can be in | Inline retry card in place of the whole matrix; a single failed toggle write rolls back just that one cell and toasts, never blanks the grid |

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<640px) | The bell's popover is replaced entirely by a full-height `Sheet` (`side="bottom"`) rather than a `Popover` — a 320px popover anchored to a Topbar icon is not usable on a 375px viewport; the Inbox's category chips become a horizontally-scrolling, snap-aligned strip (`RESPONSIVE_DESIGN.md`'s carousel pattern, reused verbatim from `DASHBOARD.md`'s own KPI Strip); the Bulk Action Bar becomes a bottom-sticky bar rather than replacing the Filter Bar in place, so selection controls stay thumb-reachable |
| `md` (640–1023px) | Popover returns (there is room); category chips still scroll horizontally rather than wrapping, keeping row height predictable; the Preferences matrix collapses to one `Card` per category, five stacked `Switch` rows each, per `RESPONSIVE_DESIGN.md`'s "cards, not tables" rule |
| `lg` (1024–1279px) | Category chips fit on one line without scrolling for most locales; the Preferences matrix returns to its full 5×5 grid |
| `xl`+ (≥1280px) | Unchanged from `lg` — this screen has no fourth, wider-only region the way the Command Center gains a persistent Ask AI rail at `3xl`; a notification inbox does not benefit from more columns past this point, only from more visible rows, which virtualization already provides |

Touch targets on every row's embedded Approve/Reject, Acknowledge, and bulk-selection checkbox hold the platform's 44×44px minimum with an 8px gap (`AI_COMMAND_CENTER.md` frontend's identical rule for its own Approval Center, reused here because the actions and their consequences are the same ones). A swipe-to-mark-read gesture on mobile rows is a convenience alias for the same `PATCH` a tap on an explicit control would fire — it never adds a distinct code path.

# RTL & Localization

Every layout rule inherited from `DESIGN_LANGUAGE.md`/`LAYOUT_SYSTEM.md`'s RTL contract applies with zero screen-specific exceptions beyond the ones every other screen already documents: logical properties throughout (`ms-*`/`me-*`/`text-start`/`text-end`), the bell popover's `align="end"` flipping to the visual left under `dir="rtl"` exactly as `NAVIGATION_SYSTEM.md` already states, and the Preferences matrix's five channel columns reading start-to-end in the mirrored order with no conditional code.

Three things do not mirror, matching the platform's fixed numeral/icon rules exactly:

- **Every amount, confidence percentage, and timestamp renders `dir="ltr"`**, via `AmountCell`/`ConfidenceBadge`/`FormattedRelativeTime`'s existing pinning — a KWD figure inside a fraud-hold notification reads left-to-right even in a fully Arabic inbox.
- **A notification's `title`/`body` text is not a bilingual field the client picks between at render time — it is a fixed, already-rendered snapshot in whatever language the recipient's `locale` was at the moment the template rendered.** This is a meaningful difference from `name_en`/`name_ar`-style master-data fields elsewhere in the platform, and worth stating precisely: per `ERD.md → notifications`'s own Normalization note ("`title`/`body` are a deliberate snapshot... so a later template edit never rewrites history a user already read"), the same reasoning extends to a locale change — if a user reads QAYD in English today and switches their account to Arabic next month, every notification received before the switch keeps displaying in English forever; only notifications generated after the switch render in Arabic. This is not a bug to fix at the frontend layer; it is the same immutability guarantee the schema already gives template edits, applied to a second kind of "the world changed after this was recorded" scenario. `# Edge Cases` restates this as a concrete scenario.
- **`ai_risk_flag`/`ai_decisions`-sourced `reasoning` strings are never machine-translated by the frontend** — they arrive already localized from the API's own content-negotiation contract, identical to `AI_COMMAND_CENTER.md` frontend's rule for Ask AI's prose.

| Context | English | Arabic |
|---|---|---|
| Category chip | Approvals Due | الموافقات المستحقة |
| Category chip | Fraud & Risk | الاحتيال والمخاطر |
| Bulk action | Mark read | تعليم كمقروء |
| Locked toggle tooltip | Security notifications can't be turned off | لا يمكن إيقاف تنبيهات الأمان |
| WhatsApp column | Coming soon | قريباً |
| Empty (all caught up) | You're all caught up | لقد أنجزت كل شيء |
| Empty (new account) | No notifications yet | لا توجد إشعارات بعد |

# Dark Mode

No new token, elevation step, or radius is introduced. `NotificationRow`'s unread state (bold title + accent dot) resolves through the existing `--qayd-ink-950`/`--qayd-accent-600` pair and their dark-mode remaps exactly as `COMPONENT_LIBRARY.md` defines them; `AiCardShell`-wrapped rows use the same dark-mode-recalibrated accent/danger values `DARK_MODE.md` specifies for AI-authored content elsewhere ("Confidence indicators are re-tuned per theme, not linearly brightened") rather than a naive brightness flip. The Preferences matrix's locked-cell and "Coming soon" treatments use `--qayd-ink-300`/`ink-500` at reduced opacity in both themes, never a separate dark-only muted color. Elevation on the bell popover and the Inbox feed's `Card` rows gets *lighter*, not darker, against the canvas behind it, per the platform's stated "physical light" dark-mode strategy — identical to how `DASHBOARD.md`'s `KpiTile` and `AI_COMMAND_CENTER.md` frontend's `AiCardShell` already behave.

# Accessibility

This screen targets the same WCAG 2.1/2.2 AA floor as every other QAYD surface (`ACCESSIBILITY.md → Standards & Targets`), with particular weight on live regions, since a notification inbox is, by definition, the screen most likely to receive content while a screen-reader user is mid-read of something else.

- **Landmarks and headings.** The Inbox renders inside the shared `<main>` region with one visible `<h1>` ("Notifications"); the Preferences matrix carries a real, `VisuallyHidden` `<h2>` per category row so a screen-reader user's heading list enumerates five distinct sections rather than one flat 25-cell grid.
- **Live regions, calibrated exactly like every other AI- and realtime-dense screen in the platform.** A new row arriving over the `private-company.{id}.notifications.{user_id}` channel announces via `aria-live="polite"` — never `"assertive"` — matching `ACCESSIBILITY.md`'s explicit three-tier model and its "auto-detect, never alarming" posture, restated verbatim from `AI_COMMAND_CENTER.md` frontend's identical rule for its own Approval Center: a push never yanks focus or splices a row into what a screen reader has already indexed; it surfaces as the same polite, dismissible "1 new notification — Refresh" banner named in `# Data & State → Realtime`.
- **The unread badge count is announced as real text, not conveyed through color or a dot alone.** `NotificationsBell`'s existing `aria-label={\`Notifications, ${unreadCount} unread\`}` (`NAVIGATION_SYSTEM.md`) is reused unchanged; on the Inbox, the Page Header's "482 unread of 3,140" is a real text node a screen reader announces as part of the page's heading region, not a badge sitting silently beside a heading.
- **Every AI-authored control explains itself.** `ConfidenceBadge`'s percentage and label are real text, never a bare progress-bar width; every Approve/Reject/Acknowledge button carries `aria-describedby` pointing at the row's own reasoning or requester text, so the "why" is available before the action is taken.
- **RBAC-aware disabled controls explain themselves.** A multi-step approval's Approve button, disabled because it is not yet this user's turn, carries a `Tooltip`-linked `aria-describedby` naming the reason (`ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`), distinct from the platform's separate rule that a *permission*-absent action is omitted rather than shown disabled — this screen has one of each: a step-ordering disablement (shown, explained) and a permission-absent action (omitted entirely, e.g. Reject never renders for a role with no approval permission on that `kind` at all).
- **The Preferences matrix is a real, labeled grid, not a table repurposed for visual alignment only.** Each `Switch` carries an `aria-label` combining its row and column ("Fraud & Risk, SMS"), so a screen-reader user tabbing cell to cell never has to hold five column headers in working memory to know what they are about to toggle. The locked System-row cells carry `aria-disabled="true"` plus the same tooltip text as a visible `aria-describedby`, never a bare `disabled` with no stated reason.
- **Color is never the only channel.** Unread state is bold weight plus a dot plus (on the bell and the count header) real text, never the dot's color alone; a `critical` fraud-hold row's pinned-to-top position and left-border color are paired with its `severity: "critical"` label rendered as real text inside the card.
- **Keyboard path.** Tab order flows Page Header (Mark all read) → category chips → Filter Bar → each feed row's own controls (checkbox → title link → embedded action buttons) → Load more, with no control unreachable without a mouse; the Preferences matrix's roving order moves cell to cell in reading order, mirroring the Sidebar's own roving-tabindex discipline (`NAVIGATION_SYSTEM.md → Keyboard Navigation`) rather than requiring 25 sequential `Tab` presses to reach the last cell — `Arrow` keys move within the grid, `Tab` leaves it in one press.

# Performance

- **Cursor pagination and virtualization, never a full-history client-side load.** The Inbox feed uses `paginationMode="cursor"` and switches to `TanStack Virtual` past 200 loaded rows, matching `COMPONENT_LIBRARY.md → DataTable`'s and `LAYOUT_SYSTEM.md`'s List Template's shared threshold — a company with years of notification history (`ERD.md`'s own 180-day retention note implies exactly this scale) never forces the browser to hold an unbounded DOM list.
- **The popover stays capped at 20 and never virtualizes** — at that size a plain scrollable list is cheaper to implement and just as fast as virtualization would be, and the cap itself is what keeps the payload light regardless of how much history exists.
- **Realtime patches are the exception, not the rule.** Only the unread badge count patches directly (`+1`) on arrival; every other realtime-triggered update on this screen is an ordinary `invalidateQueries` call, which can never drift silently from what the server actually holds — the same discipline `DASHBOARD.md`'s Cash Position tile and `AI_COMMAND_CENTER.md` frontend's Approval Center both already apply.
- **Preferences is code-split.** `NotificationPreferencesMatrix` and its `Switch`-heavy tree load via `next/dynamic`, since it is a rarely-visited tab relative to the Inbox — a user opening the bell fifty times a day should never pay for the Preferences bundle on any of those visits.
- **Debounced search.** The Inbox's `q` search input debounces at 300ms, matching every other searchable list in the platform (`DataTable`'s own convention).
- **Web Vitals.** LCP on `/notifications` is measured against the feed's first page of rows painting, not the shell; CLS stays near zero because every skeleton row shares the loaded state's exact height per category (an approval row's skeleton is taller than a plain deadline row's, matching each category's own final layout rather than one generic row height for all five).

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | A notification's `data.target` points to a record since deleted, voided, or moved to a company the user no longer has access to. | The target route's own `not-found.tsx` renders, deliberately indistinguishable from "belongs to a different company," per `NAVIGATION_SYSTEM.md → Edge Cases` #4, reused verbatim — a stale notification link never leaks whether the underlying resource exists. |
| 2 | An approval-request notification's underlying `ai_approval_requests` row is approved or rejected by someone else (or from the Approval Center directly) between the notification rendering and this screen's own Approve click. | The embedded `ApprovalCard`'s mutation re-fetches the live target immediately before submitting and diffs it against what the card displayed; on divergence, the action is replaced with "This changed — review in Approval Center" rather than silently acting on stale intent — the identical conflict posture `DASHBOARD.md` and `AI_COMMAND_CENTER.md` frontend both already specify for the same race. |
| 3 | A security-critical notification (`permission.changed`, `audit.security_event`) arrives for a user who has toggled the System category's channels off in Preferences. | It is delivered anyway, on every channel `ERD.md`'s exemption rule covers, and the Preferences matrix renders those specific cells permanently locked-on with an explanatory tooltip — the toggle was never able to suppress it in the first place, so there is nothing to reconcile after the fact. |
| 4 | A user disables the Fraud & Risk category for Email today; a fraud-risk notification from three weeks ago, sent while that channel was still enabled, is reopened from the Inbox. | Nothing about a historical notification changes when a preference changes later — `notification_preferences` governs whether a *future* row is created, never whether an already-created row is hidden or altered, per `ERD.md`'s own stated scope ("respected before any `notifications` row is even created"). The Inbox never retroactively filters history by the current preference state. |
| 5 | The WhatsApp column is visible in Preferences before the channel exists in `ERD.md`'s `channel` CHECK constraint or has a delivery integration. | Every cell in that column renders `disabled` with a `Tooltip` reading "Coming soon"; `PATCH /api/v1/notifications/preferences` rejects a `channel: "whatsapp"` body with a `422` until the constraint and integration ship, and the matrix never lets a click reach that request in the first place (the `Switch` itself is disabled, not merely styled to look inert). |
| 6 | A user marks a specific notification unread, then a second, unrelated push increments the unread badge a moment later. | The badge patch is a plain increment on the query's current cached value, not a value the two events could stomp on each other's way to — `setQueryData(key, (c) => c + 1)` composes safely regardless of ordering; the next full list refetch (on popover open, on reconnect) reconciles the exact count regardless. |
| 7 | "Mark all as read" is clicked while a brand-new, still-unread notification is mid-flight over the realtime channel (arrives a few hundred milliseconds after the request was sent). | The server stamps `read_at = now()` on every row unread as of its own request-processing time, not a client-supplied snapshot — a genuinely-new row that lands after the server has already begun processing the bulk request is never marked read by that request; the frontend simply re-invalidates `notificationKeys.list`/`unread()` after the mutation settles and shows whatever the server's own state actually is, including that one still-unread arrival. |
| 8 | A user scrolls far enough back in the Inbox to reach notifications older than the 180-day retention window for already-read rows (`ERD.md`'s rolling purge job). | The cursor simply returns fewer rows than requested and, eventually, an empty page; the feed renders its "You've reached the end" footer exactly as it would for any natural end-of-history, never an error — a purge is not a failure state from this screen's point of view. |
| 9 | A push notification (native mobile shell) or an emailed "View in QAYD" link is opened while the user is signed out. | The link resolves through the platform's ordinary auth-redirect flow — sign-in first, then a return to the originally-requested `target` — per `NAVIGATION_SYSTEM.md → Edge Cases` #11's identical statement for a cold-started native deep link; there is no separate "notification link" auth path to maintain. |
| 10 | A company switch happens while Notifications Center is open. | `queryClient.clear()` and `router.refresh()` fire together exactly as `FRONTEND_ARCHITECTURE.md → Company switching` specifies for every other screen; the Inbox and Preferences both re-mount and re-fetch under the new `X-Company-Id` — notifications are also `company_id`-scoped (per `ERD.md`'s standard columns), so a stale notification from the previous company is never shown mixed in with the new one's. |
| 11 | Two browser tabs are open; one clicks "Mark all as read," the other is mid-scroll through the same unread list. | The idle tab's own cached list is stale but not wrong-looking — its rows still show as unread locally until its next fetch or the next realtime event forces an invalidation (the bulk action does not itself broadcast a Reverb event back to the acting user's other tabs in v1); the next natural refetch (a filter change, a reconnect, a popover reopen) reconciles it. This is the same accepted cross-tab lag `NAVIGATION_SYSTEM.md → Edge Cases` #12 describes for the AI status pill and the Command Palette. |
| 12 | A user's account `locale` changes from English to Arabic after receiving hundreds of English-language notifications. | Every already-received `title`/`body` continues rendering in English forever — they are a rendered snapshot, not a bilingual field the client re-picks — while every notification generated after the switch renders in Arabic; both a mixed-language Inbox and a mixed-language Preferences matrix (whose category labels, being chrome copy rather than notification content, do switch immediately) are correct, expected states, not a bug. See `# RTL & Localization`. |
| 13 | An approval-request notification's `ApprovalCard` is rendered for a role that holds no permission for that `kind` at all (e.g., a Sales Employee somehow receiving a `bank_transfer` approval notification through a misconfigured custom role). | `ApprovalCard`'s own `usePermission(PERMISSION_BY_KIND[kind])` check disables both Approve and Reject with an explanatory tooltip, identical to how the Approval Center itself renders the same row for the same role — this screen never grants an action the owning component's own permission gate would deny elsewhere, because it is the same component. |
| 14 | A user with the narrowest possible role (authenticated, zero business-module permissions) opens Notifications Center. | The screen renders fully and normally — Inbox, category chips, Preferences — because neither route carries a gating permission (`# Route & Access`); only the *content* of individual notifications is affected, and only insofar as their `data.target` routes 403/404 on click per that destination's own, separately-owned permission, never because Notifications Center itself withheld anything. |

# End of Document
