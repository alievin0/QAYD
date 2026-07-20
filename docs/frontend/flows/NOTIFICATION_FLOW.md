# Notification Flow — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Flows / NOTIFICATION_FLOW
---

# Purpose

This document specifies the end-to-end journey of a single notification in the QAYD web client, from
event to resolution: a domain event fires (an AI proposal is ready, a reconciliation completes, a
month-end deadline approaches, a fraud hold lands, a mention), it is delivered over the
`private-company.{id}.notifications.{user_id}` Reverb channel plus an in-app toast, the bell badge, and
the full Notifications Center; the user reads it (unread → read lifecycle), deep-links from it to the
originating entity, acts on it in place (approve/acknowledge) where that is safe, and configures which
categories reach which channels — all across the in-app / email / SMS / (WhatsApp) / push boundary,
with do-not-disturb and the edge cases (stale deep-link target, revoked access) each resolved.

It is a *flow* document and defers, rather than duplicates: [`../NOTIFICATIONS.md`](../NOTIFICATIONS.md)
owns the Notifications Center screen (the bell popover body, the Inbox, the Preferences matrix);
[`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) owns the Topbar bell's trigger, badge, and
subscription; [`../components/AI_WIDGETS.md`](../components/AI_WIDGETS.md) owns the embedded
`ApprovalCard`/`AiCardShell`/`ConfidenceBadge` an actionable notification renders through; and the
underlying data model, delivery fan-out, and event catalogue are owned by `docs/database/ERD.md`,
`docs/database/DATABASE_EVENTS.md`, and the platform Notification Engine. This document is authoritative
only for how those surfaces sequence into one recipient journey.

The load-bearing rule every step below enforces: **a notification is a routing surface, never a bypass
of the human-approval gate.** An approval-request notification embeds the identical `ApprovalCard` and
calls the identical `POST /api/v1/approvals/{id}/approve` the Approval Center uses — approving from a
notification is a second *entry point* into one mutation, never a second implementation. Nothing
rendered here executes a sensitive action (`bank.transfer`, `payroll.approve`, `tax.submit`, a
permission change, a deletion) on a single click merely because it arrived as a notification.

# Actors & Preconditions

| Actor | Role in this flow |
|---|---|
| The recipient | Any authenticated company member — every seat owns its own `user_id`-scoped inbox and preferences; a Warehouse Employee and an Owner each see only their own rows. |
| The domain event producer | The module or agent whose event fans out (`invoice.overdue`, `ai_risk_flag.raised`, `bank.synced`, `approval.requested`, `permission.changed`, …). |
| The `events-notifications` queue consumer | Fans one domain event out to email/SMS/push/in-app + a Reverb broadcast, honoring the recipient's `notification_preferences` before a `notifications` row is even created. |
| The frontend | Renders and triages, computes nothing; re-surfaces each agent's already-computed confidence/reasoning unmodified; routes every in-place action through the owning surface's own authoritative mutation. |

**Preconditions.**

- The recipient is authenticated with an active `X-Company-Id`; the `(app)` shell's shared
  `RealtimeProvider` connection is open (the bell and Inbox share the one
  `private-company.{id}.notifications.{user_id}` subscription — no second socket).
- **No permission gates the route** — like Dashboard, every authenticated member reaches
  `/notifications` and `/notifications/preferences`; what varies per notification is only the actions
  its own embedded content permits.
- `notifications` and `notification_preferences` are `user_id`-scoped; the recipient's preferences were
  honored at fan-out time (a disabled channel means no row was created for it).

# Entry Points

| Entry point | Surface | Notes |
|---|---|---|
| The Topbar bell | `NotificationPopoverFeed` (up to 20 recent, `density="popover"`) | Badge, unread query, and subscription unchanged from `NAVIGATION_SYSTEM.md` |
| The bell's "View all" | `/notifications` Inbox | Carries no popover filter forward |
| A `UserMenu` → "Notification settings" | `/notifications/preferences` | The 5-category × 5-channel matrix |
| A push notification (native shell) or an emailed "View in QAYD" link | The `data.target` route | Resolves through the ordinary auth-redirect flow if signed out |
| A direct/bookmarked `/notifications` URL | Inbox | Single root-level breadcrumb |

# Flow Overview

```mermaid
flowchart TD
    A[Domain event fires] --> B[events-notifications consumer\nhonors notification_preferences]
    B --> C{Enabled channels}
    C -->|in-app| D[Reverb broadcast\n.notifications.user_id]
    C -->|email / SMS / push| E[External delivery]
    D --> F[Badge +1 patch\n+ optional toast]
    F --> G{User opens}
    G -->|Bell| H[Popover: 20 recent]
    G -->|View all| I[/notifications Inbox]
    H --> J[Read a row: PATCH read_at\n+ navigate to data.target]
    I --> K{Category of row}
    K -->|Approval| L[Embedded ApprovalCard\nApprove/Reject → real endpoint]
    K -->|AI Alert| M[AIProposalPanel or\nAiCardShell + Explain more]
    K -->|Fraud & Risk| N[Acknowledge inline\nResolve/Dismiss → Detected Risks]
    K -->|Deadline / System| O[View → link to entity]
    L --> P[Approval Center lifecycle\nsee APPROVAL_FLOW.md]
    J --> Q[Deep-link to originating entity]
    O --> Q
    M --> Q
    N --> R[/ai/risks/id]
    Q --> S[Journey ends at the entity]
```

Step map:

1. Event fires; preferences honored; fan-out.
2. Delivery — Reverb + badge + optional toast + external channels.
3. Read (unread → read lifecycle).
4. Triage by category in the Inbox.
5a–5d. Act in place / deep-link (Approval / AI Alert / Fraud & Risk / Deadline·System).
6. Preferences — configure channels, DND, locks.

# Step-by-Step

## Step 1 — Event fires; preferences honored

- **Origin.** A catalogued domain event (`approval.requested`, `ai_risk_flag.raised`, `invoice.overdue`,
  `permission.changed`, …). The `events-notifications` consumer checks the recipient's
  `notification_preferences` **before** any `notifications` row is created — a disabled channel simply
  yields no delivery on that channel. Security-critical codes (`permission.changed`,
  `audit.security_event`) are exempt from opt-out and delivered on their locked channels regardless.
- **Screen / route.** None — a server event.
- **API call.** None client-side.

## Step 2 — Delivery

- **UI state.** For in-app: a Reverb broadcast on `private-company.{id}.notifications.{user_id}` patches
  the bell badge directly (`setQueryData(notificationKeys.unread(), c => c + 1)` — a snappy,
  high-frequency, low-risk tick) and invalidates the Inbox list query. A new row arriving while the
  Inbox is open and scrolled mid-list is **never** spliced above the user's scroll position; it surfaces
  as a small, dismissible "1 new notification — Refresh" banner. A `critical` fraud hold sorts to the top
  of its category (and above every non-critical row in "All") regardless of `created_at`.
- **API call.** Realtime push only; `notificationKeys.unread()` is `staleTime: 0`, realtime-corrected,
  never polled.
- **Success branch.** The recipient sees the badge/toast; proceed to Step 3.
- **Failure branches.** A dropped WebSocket → the badge keeps its last-known cached count (never a flash
  to a possibly-wrong `0`); the next full list refetch (popover open, reconnect) reconciles the exact
  count.

## Step 3 — Read (unread → read lifecycle)

- **Screen / route.** The bell `NotificationPopoverFeed`, or `/notifications` Inbox.
- **User action.** Opens the bell (unread rows: bold title + accent dot) and clicks a row, or opens the
  Inbox.
- **UI state.** Clicking a row's body (outside its embedded action buttons) marks it read and navigates.
  Unread state is bold weight + a dot + real text ("482 unread of 3,140") — never the dot's color alone.
- **API call.** `PATCH /api/v1/notifications/{id}` `{ read_at: <ISO8601> }` (optimistic); "Mark all as
  read" → `POST /api/v1/notifications/mark-all-read` (no confirmation dialog — a reversible,
  non-financial state change; any row can be marked unread again).
- **Success branch.** Proceed to Step 4 (Inbox triage) or Step 5 (act) or the deep-link (Step 5d).
- **Failure branches.** A mark-read `PATCH` failure rolls back that one row's state and toasts. There is
  no per-row or bulk **delete** — deletion is a backend retention concern; "dismiss" is implemented as
  mark-read, which is reversible.

## Step 4 — Triage by category

- **Screen / route.** `/notifications` Inbox — an extended List Page Template with a category filter row
  (`All`, `Approvals Due`, `AI Alerts`, `Fraud & Risk`, `Deadlines`, `System`, each with its own unread
  sub-count) above the Filter Bar, and a cursor-paginated, virtualized `NotificationFeed`.
- **User action.** Clicks a category chip (writes `?category=` to the URL — mirrored, shareable),
  toggles `All`/`Unread only`, searches (`q`), or selects rows for a bulk action.
- **UI state.** Category, read-state, and search compose additively. The Bulk Action Bar appears once ≥1
  row is selected and offers **only** `Mark read`/`Mark unread`/`Clear` — never bulk-approve/reject/delete
  (bulk-approve stays exclusive to the Approval Center's own queue).
- **API call.** `GET /api/v1/notifications` with `filter[category]`, `filter[read_at]`, `q`,
  `filter[created_at][from|to]`, `cursor`; category sub-counts from a single `GET
  /api/v1/notifications/counts`; bulk → `POST /api/v1/notifications/bulk-action` `{ ids, action }`
  (read-state only).
- **Success branch.** Proceed to Step 5 per the selected row's category.

## Step 5 — Act in place / deep-link

Each category's inline affordance is a deliberate, narrow scope decision — the correct next step for the
heavier verbs is always "go look at the thing," never "resolve it from here."

### 5a — Approval-request row (`category="approval"`)

- **UI state.** Renders `ApprovalCard` in its compact presentation — the exact same component and
  mutations the Approval Center uses. Approve/Reject render but are disabled-with-tooltip if it is not
  yet this user's turn in a multi-step chain; a role with no permission for that `kind` at all sees both
  disabled.
- **API call.** Approve → `POST /api/v1/approvals/{id}/approve` (identical `Idempotency-Key`, optimistic
  status flip); Reject → `POST /api/v1/approvals/{id}/reject` (identical reason-required `AlertDialog`).
  A mobile approval of a sensitive action requires the identical biometric re-confirmation a queue row
  would.
- **Success branch.** The item advances/resolves; the full lifecycle beyond this click is owned by
  [`./APPROVAL_FLOW.md`](./APPROVAL_FLOW.md).
- **Failure branches.** The underlying request was decided elsewhere between render and click → the
  embedded mutation re-fetches the live target and diffs it; on divergence, the action is replaced with
  "This changed — review in Approval Center."

### 5b — AI Alert row (`category="ai_alert"`)

- **UI state.** A row that graduated to carrying a `recommended_action` renders `AIProposalPanel`
  directly (the three-button Do it / Send for approval / Dismiss contract, `Do it` gated by the
  API-computed `can_execute_directly`, never a client re-derivation); a pure observation renders as
  plain text inside `AiCardShell` with an "Explain more" link that opens Ask AI pre-seeded with the
  notification's own `sources`.
- **API call.** Per the three-button contract (`execute`/`send-for-approval`/`dismiss`) — see
  [`./AI_CHAT_FLOW.md`](./AI_CHAT_FLOW.md) Step 7a; "Explain more" hands off to `/assistant`.

### 5c — Fraud & Risk row (`category="fraud_risk"`)

- **UI state.** Renders inside `AiCardShell` with its `ConfidenceBadge` and a one-line description, and
  offers exactly one inline action: **Acknowledge** (a single optimistic call, no dialog — the
  `open → acknowledged` transition carries no elevated permission). **Resolve** and **Dismiss** (both
  requiring a note/reason) are deliberately never rendered here; every row carries a "Review in Detected
  Risks →" link to `/ai/risks/{id}` where those live, so two implementations of the same status-transition
  logic never have to be kept in lockstep.
- **API call.** Acknowledge → `POST /api/v1/ai/risks/{id}/acknowledge` (optimistic).

### 5d — Deadline / System row (`category="deadline"|"system"`)

- **UI state.** A plain `NotificationRow` with no embedded mutation beyond a single "View →" link to
  `data.target` — an overdue invoice → that invoice, a tax deadline → `/tax/returns/{id}`, a
  `permission.changed` → `/settings/team`. Neither corresponds to a reversible, low-stakes action a row
  can safely shortcut; the correct next step is always "go look at the thing."
- **API call.** `<Link>` navigation only; clicking the row body also marks it read.

## Step 6 — Preferences (channel/DND configuration)

- **Screen / route.** `/notifications/preferences` — a 5-category × 5-channel `Switch` grid.
- **User action.** Toggles a cell.
- **UI state.** No separate "Save" — every toggle fires its own optimistic `PATCH` immediately. The
  `System` row's In-App and Email cells render permanently **locked-on** (`[lock]` + tooltip: "Security
  notifications can't be turned off"). The **WhatsApp** column renders every cell disabled "Coming soon"
  until the channel's `CHECK` constraint and delivery integration land (a forward-compatible-without-
  lying posture: visible so it is discoverable, disabled with a stated reason so nothing is promised
  prematurely).
- **API call.** `PATCH /api/v1/notifications/preferences` `{ category, channel, is_enabled }` (the
  server expands it into the underlying per-`event_code` upserts; the client never enumerates event
  codes). Read via `GET /api/v1/notifications/preferences` (`staleTime: 300_000`, the grid pre-shaped
  server-side).
- **Failure branches.** A single failed toggle rolls back just that one cell and toasts, never blanks the
  grid. A `PATCH` with `channel: "whatsapp"` is rejected `422` until the channel ships — and the disabled
  `Switch` never lets a click reach that request in the first place.

# Happy Path

`FRAUD_DETECTION` raises a `critical` hold on a `KWD 18,540.000` vendor payment; the `ai_risk_flag.raised`
event fans out. Because Mariam's Fraud & Risk category has In-App, Email, and Push enabled, she gets a push
on her phone and, in the web app, the bell badge ticks to a new count over the `.notifications` channel. She
opens the bell: an `AiCardShell`-wrapped row, "Vendor payment held — Al-Fajr Cement · 93% · 14m," with a
one-line description and an **Acknowledge** button. She acknowledges (optimistic, no dialog), then follows
"Review in Detected Risks →" to `/ai/risks/55214`, where Resolve and Dismiss actually live — the row was
marked read on the way. Separately, an approval notification ("JE-1091 · KD 45,220 needs your approval ·
Approval 1 of 2") renders an embedded `ApprovalCard`; she approves it there, and the identical
`POST /api/v1/approvals/{id}/approve` the Approval Center would call advances the chain. Nothing about
arriving as a notification lowered the bar a queue row would clear.

# Alternate & Error Paths

| Path | Trigger | Behavior |
|---|---|---|
| Stale deep-link target | `data.target` points to a since-deleted/voided/cross-tenant record | The target route's own `not-found.tsx` renders, deliberately indistinguishable from "belongs to a different company" — a stale link never leaks whether the resource exists. |
| Revoked access before follow | Permission changed since the notification | The destination's own `403`/`error.tsx` boundary handles it; Notifications Center itself withheld nothing. |
| Approval decided elsewhere first | The underlying request was approved/rejected between render and click | The embedded `ApprovalCard` re-fetches the live target and, on divergence, replaces the action with "This changed — review in Approval Center." |
| AI engine unavailable | An AI-sourced row needs a live AI call | The re-surfaced row still renders its already-computed confidence/reasoning (Notifications Center performs no AI computation of its own); an "Explain more" hand-off to `/assistant` fails-fast there, not here. |
| Security notification with the category off | `permission.changed` for a user who toggled System channels off | Delivered anyway on the exempt channels; the matrix cells render permanently locked-on — the toggle never suppressed it. |
| Preference changed after a historical row | A channel disabled today; a three-week-old row reopened | Nothing about a historical row changes when a preference changes later — `notification_preferences` governs whether a *future* row is created, never whether an existing one is hidden. The Inbox never retroactively filters history by current preferences. |
| WhatsApp toggled before the channel exists | The column is visible pre-integration | Every cell disabled "Coming soon"; the `PATCH` `422`s and the disabled `Switch` never lets a click reach it. |
| Push/email link opened while signed out | Cold-started native deep link | Resolves through the ordinary auth-redirect flow — sign in first, then return to the requested `target`; no separate notification-link auth path. |

# Data & State

| Purpose | Endpoint | Method | Notes |
|---|---|---|---|
| Inbox feed (cursor) | `/api/v1/notifications` | GET | `filter[category]`, `filter[read_at]` (`null`/`not_null`), `q`, `filter[created_at][from|to]`, `cursor`, `per_page` |
| Unread badge | `/api/v1/notifications?filter[read_at]=null&per_page=20` | GET | The bell's existing call, unchanged; `notificationKeys.unread()`, `staleTime: 0` |
| Category sub-counts | `/api/v1/notifications/counts` | GET | One grouped call, not six list requests |
| Mark one read/unread | `/api/v1/notifications/{id}` | PATCH | `{ read_at: <ISO8601> }` or `{ read_at: null }`; optimistic |
| Bulk read/unread | `/api/v1/notifications/bulk-action` | POST | `{ ids, action: "mark_read"|"mark_unread" }` — read-state only |
| Mark all read | `/api/v1/notifications/mark-all-read` | POST | Server stamps `read_at = now()` on every row unread as of its own processing time |
| Preferences (read) | `/api/v1/notifications/preferences` | GET | Server-shaped 5×5 grid; `staleTime: 300_000`, no focus-refetch |
| Preferences (write) | `/api/v1/notifications/preferences` | PATCH | `{ category, channel, is_enabled }`; server expands to per-`event_code` upserts; optimistic |
| Approve/Reject (embedded) | `/api/v1/approvals/{id}/approve`\|`/reject` | POST | Identical to the Approval Center — not redefined |
| Acknowledge (embedded) | `/api/v1/ai/risks/{id}/acknowledge` | POST | Identical to the Detected Risks panel; Resolve/Dismiss never called from here |

**Query invalidations.** A realtime arrival patches `notificationKeys.unread()` (`+1`) directly and
invalidates `notificationKeys.list(...)`; every other realtime-triggered update is an ordinary
`invalidateQueries` (never a silent drift from server truth). An embedded approve/reject invalidates
`approvalKeys.all` and `['dashboard']` exactly as the Approval Center does. A preference `PATCH`
optimistically patches `notificationKeys.preferences()`.

**Realtime channel.** `private-company.{id}.notifications.{user_id}` (shared connection) — the same
channel the bell and `FRONTEND_ARCHITECTURE.md` name; no new channel is introduced. The badge patch is
the exception, not the rule; a new row surfaces as a "Refresh" banner rather than a focus-yanking splice.

**Server-first paint.** `/notifications/page.tsx` is a Server Component prefetching the first page
(`category=all, readState=all`) via `dehydrate`/`HydrationBoundary`; `export const dynamic =
"force-dynamic"` and `fetchCache = "default-no-store"` (tenant- and user-scoped, never statically
cached).

# AI Touchpoints

- **`AiCardShell` is the only AI-provenance treatment.** A fraud-risk notification's left border and
  "AI" badge are pixel-identical to the same risk's card on the Detected Risks panel — two renderings of
  one `ai_risk_flags` row, not two visual languages.
- **`ConfidenceBadge` always normalizes.** `ai_risk_flags.confidence_score`/`ai_decisions.confidence_score`
  arrive 0–100; every render passes through `normalizeConfidence(score, "percentage")`, never a
  screen-local calculation.
- **The three-button pattern is exact for AI Alert rows.** `can_execute_directly: false` means "Do it"
  is *absent*, not disabled — the same structural guarantee every AI surface makes.
- **A `critical` fraud hold pins to the top of its category**, regardless of `created_at` — urgency is
  never subordinate to recency.
- **Sensitive actions never terminate here.** Approving a bank transfer/payroll release/tax submission
  from a notification always routes through the identical `POST /api/v1/approvals/{id}/approve`, with the
  identical mobile biometric re-confirmation; arriving as a notification lowers no bar.
- **Notifications Center performs no AI computation of its own** — every confidence, reasoning, and
  severity it renders was computed by the owning agent and is only being re-surfaced, unmodified, as a
  triage item.

# Permissions

| Step | Permission | Enforcement |
|---|---|---|
| Reach `/notifications` and Preferences | **None** | Every authenticated member; only per-notification content varies |
| Approve/reject an embedded approval | `PERMISSION_BY_KIND[kind]` (e.g. `bank.transfer`, `payroll.approve`) + turn in the chain | The embedded `ApprovalCard`'s own `usePermission` disables non-permitted/non-turn actions with a tooltip; a permission-absent action is omitted (e.g. Reject never renders for a role with no approval permission on that `kind`) |
| Acknowledge a fraud/risk row | None (no elevated permission) | Available to any recipient |
| Edit own preferences | None (own rows only) | Every user edits only their own `notification_preferences`; security-critical codes lock on regardless of role, including Owner |
| Follow `data.target` | The destination's own permission | The destination's `403`/`404` is the boundary |

Because there is no route-level permission, the screen renders identically in structure for every role;
a maximally narrow role (zero business-module permissions) still gets a fully working Inbox, chips, and
Preferences — only individual notifications' `data.target` may `403`/`404` on click, per that
destination's own separately-owned permission.

# i18n & RTL

- **A notification's `title`/`body` is a fixed rendered snapshot, not a bilingual field** — it is
  frozen in whatever language the recipient's `locale` was at render time. A user who switches
  English → Arabic next month keeps every prior notification in English forever; only notifications
  generated *after* the switch render in Arabic. A mixed-language Inbox is a correct, expected state,
  not a bug (the same immutability the schema gives template edits). Category labels, being chrome, do
  switch immediately.
- **AI-sourced `reasoning` is never machine-translated by the frontend** — it arrives already localized
  per content negotiation.
- **`dir="rtl"` once on `<html>`**; the bell popover's `align="end"` flips to the visual left, the
  category chips and the Preferences matrix's five channel columns read start-to-end, all via logical
  properties with no conditional code.
- **Three things never mirror.** Every amount, confidence percentage, and timestamp renders `dir="ltr"`
  (`latn`) via `AmountCell`/`ConfidenceBadge`/`FormattedRelativeTime` — a KWD figure reads left-to-right
  in a fully Arabic inbox; directional icons flip, meaning-bearing ones do not.
- Arabic microcopy is authored directly: *الموافقات المستحقة*, *الاحتيال والمخاطر*, *تعليم كمقروء*, *لا
  يمكن إيقاف تنبيهات الأمان*, *قريباً* (Coming soon), *لقد أنجزت كل شيء* (You're all caught up).

# Accessibility

- **Landmarks and headings.** The Inbox is one `<main>` with one `<h1>` ("Notifications"); the
  Preferences matrix carries a `VisuallyHidden` `<h2>` per category row so a screen-reader heading list
  enumerates five sections, not one flat 25-cell grid.
- **Live-region announce policy — polite, never assertive, never a splice.** A new row over the
  `.notifications.{user_id}` channel announces via `aria-live="polite"`; a push never yanks focus or
  splices a row into what a screen reader has already indexed — it surfaces as the same dismissible
  "1 new notification — Refresh" banner. The unread count is announced as real text ("482 unread of
  3,140"), never color or a dot alone.
- **Every AI-authored control explains itself.** `ConfidenceBadge`'s percentage and label are real text;
  every Approve/Reject/Acknowledge carries `aria-describedby` pointing at the row's reasoning/requester,
  so the "why" is available before the action.
- **RBAC-disabled vs. permission-absent are distinct.** A multi-step approval's Approve disabled because
  it is not yet this user's turn is *shown with an explaining tooltip*; a permission-absent action (Reject
  for a role with no approval permission on that `kind`) is *omitted entirely* — this screen has one of
  each and never conflates them.
- **The Preferences matrix is a real, labeled grid.** Each `Switch` has an `aria-label` combining row and
  column ("Fraud & Risk, SMS"); `Arrow` keys move cell to cell in a roving order (`Tab` leaves the grid
  in one press, not 25); locked System cells carry `aria-disabled="true"` plus the same tooltip text as
  a visible `aria-describedby`.
- **Focus continuity.** `Tab` flows Page Header → category chips → Filter Bar → each row's own controls
  (checkbox → title link → embedded action buttons) → Load more, with no control unreachable by keyboard.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | Stale `data.target` (deleted/voided/cross-company) | The target's own `not-found.tsx` renders, indistinguishable from "different company" — no existence leak. |
| 2 | Approval decided elsewhere between render and click | The embedded `ApprovalCard` re-fetches live and, on divergence, replaces the action with "This changed — review in Approval Center." |
| 3 | Security notification with its category toggled off | Delivered anyway on the exempt channels; the matrix cells render permanently locked-on with an explaining tooltip. |
| 4 | Preference disabled after a historical row was created | The historical row is never retroactively hidden or altered; preferences govern only *future* rows. |
| 5 | WhatsApp column before the channel exists | Every cell disabled "Coming soon"; the `PATCH` `422`s and the disabled `Switch` blocks the request client-side. |
| 6 | Mark-unread then a concurrent push increments the badge | `setQueryData(key, c => c + 1)` composes safely regardless of ordering; the next full refetch reconciles the exact count. |
| 7 | "Mark all read" races a still-in-flight new row | The server stamps read on rows unread as of *its own* processing time; a row landing after is never marked read; the frontend re-invalidates and shows the server's actual state. |
| 8 | Scroll past the 180-day retention window | The cursor returns fewer rows and eventually an empty page → "You've reached the end," never an error (a purge is not a failure state). |
| 9 | Push/email link opened while signed out | Ordinary auth-redirect flow — sign in, then return to the `target`. |
| 10 | Company switch with the Center open | `queryClient.clear()` + `router.refresh()`; Inbox and Preferences re-mount under the new `X-Company-Id` (notifications are `company_id`-scoped — no cross-company bleed). |
| 11 | Two tabs; one marks all read, the other mid-scroll | The idle tab stays locally stale (the bulk action does not broadcast back to the actor's own tabs in v1); the next natural refetch reconciles it — accepted cross-tab lag. |
| 12 | Account `locale` changes after hundreds of notifications | Prior `title`/`body` stay in their original language forever; only post-switch notifications render in the new language; a mixed-language Inbox is expected. |
| 13 | Approval notification for a role with no permission on that `kind` | The embedded `ApprovalCard`'s own `usePermission` disables Approve/Reject with a tooltip — identical to how the Approval Center renders the same row for the same role, because it is the same component. |
| 14 | Maximally narrow role opens the Center | The screen renders fully (Inbox, chips, Preferences); only individual `data.target`s may `403`/`404` on click — the Center itself withholds nothing. |

# End of Document
