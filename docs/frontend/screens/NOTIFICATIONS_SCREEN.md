# Notifications Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: NOTIFICATIONS_SCREEN
---

# Purpose

This document is the concrete, implementation-ready screen specification for the Notifications Center
route, `app/(app)/notifications`, written against the platform's `SCREEN DOC STRUCTURE` template so it can
be read, reviewed, and diffed section-by-section alongside every other screen document in this series —
Dashboard, Accounting, Journal Entries, Trial Balance, the Financial Statements, Bank Reconciliation, the
AI Command Center, Home, Settings, and Profile. It is the same kind of companion to
`docs/frontend/NOTIFICATIONS.md` that `ACCOUNTING_SCREEN.md` already is to `docs/frontend/ACCOUNTING.md`:
`NOTIFICATIONS.md` argues, at full policy depth, why the Notifications Center is the extension of the
Topbar bell's own long-standing "a full-history `/notifications` page is a natural future extension" code
comment, resolves the four-vs-five-channel drift between `SYSTEM_ARCHITECTURE.md` and `ERD.md`, fixes the
`notificationKeys.unread()` key-name drift against `FRONTEND_ARCHITECTURE.md`'s realtime listener,
establishes the server-authoritative `category` taxonomy (Approvals Due · AI Alerts · Fraud & Risk ·
Deadlines · System), and names every endpoint, permission boundary, and edge case for both the Inbox and
the Preferences matrix. This document does not re-argue, re-derive, or re-decide a single one of those
facts. What it adds is the layer an engineer opens beside their editor while wiring the two pages those
tabs share: the literal `layout.tsx` and the `useInfiniteQuery`-backed `NotificationFeed`, the
`category`-branching `NotificationRow` the sibling document names but shows only in ASCII, the
`NotificationCategoryTabs`/`NotificationBulkActionBar`/`NotificationPreferencesMatrix` source, the concrete
optimistic-mutation hooks (mark read, bulk action, mark-all-read, preference toggle) with their exact
`onMutate`/`onError` shapes, and pixel-level state/responsive/dark-mode/accessibility detail in place of a
reference to "the shared token set."

Where this document is silent on a fact, `docs/frontend/NOTIFICATIONS.md` governs first, then
`FRONTEND_ARCHITECTURE.md`, `docs/frontend/AI_COMMAND_CENTER.md` (for anything touching the embedded
`ApprovalCard`/`AIProposalPanel`/`AiCardShell` contract), `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`,
`NAVIGATION_SYSTEM.md`, `LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md`,
in that order — and, for the backing data model, `docs/database/ERD.md → Notifications` and
`docs/database/DATABASE_EVENTS.md`. Where this document appears to contradict one of them on a fact — a
route, a permission key, an endpoint path, a component name, a channel — that is a defect to raise in
review, never a decision an engineer resolves unilaterally in code.

Three constraints inherited unchanged from `FRONTEND_ARCHITECTURE.md` and restated by every sibling
screen document bind this one identically. **The frontend computes nothing** — every `category`, every
`read` state (`read_at IS NOT NULL`), every confidence score, every `reasoning` string, and every
`data.target` route this screen renders was computed and persisted server-side; this screen renders and
filters those attributes, it never re-derives one (it never maps a raw `event_code` to a category, never
recomputes a confidence percentage, never decides whether an approval is this user's turn). **AI is
visible, labeled, and never silent** — every `ai_alert` and `fraud_risk` row renders inside `AiCardShell`
with its `ConfidenceBadge` and reasoning, and an approval-shaped notification embeds the identical
`ApprovalCard` and calls the identical `POST /api/v1/approvals/{id}/approve` mutation the Approval Center
uses; approving from a notification is a **second entry point into one mutation, never a bypass** of the
human-approval gate. **RBAC is enforced by the API; the UI only reflects it** — the route itself carries
**no gating permission** (every authenticated user owns their own `user_id`-scoped inbox and preferences),
and what varies per role is only the actions an individual notification's own embedded content permits,
resolved by that component's own permission gate (`ApprovalCard`'s `PERMISSION_BY_KIND`), never by this
screen.

Concretely, this screen composes five pieces of surface, each owned elsewhere and only rendered, filtered,
or routed here:

- **The shared shell** — `app/(app)/notifications/layout.tsx`, the `Inbox`/`Preferences` two-tab strip
  plus the one realtime subscription both pages share, mounted once and never re-bound per tab navigation.
- **The Inbox** — `app/(app)/notifications/page.tsx`, an extended List Page Template: a category filter
  row, a read-state/search/date Filter Bar, a bulk-action bar, and a cursor-paginated,
  `useInfiniteQuery`-driven, virtualized-past-200 feed of heterogeneous `NotificationRow`s.
- **The Preferences matrix** — `app/(app)/notifications/preferences/page.tsx`, the 5-category × 5-channel
  `Switch` grid drawing the in-app-vs-email/SMS/WhatsApp/push channel boundary, with the WhatsApp column
  locked "Coming soon" and the System row's In-App/Email cells locked-on.
- **The bell popover body** — `NotificationPopoverFeed`, the previously-unimplemented body of the Topbar
  bell's `PopoverContent`, sharing `NotificationRow` in its compact `density="popover"` presentation.
- **The embedded action surfaces** — `ApprovalCard`, `AIProposalPanel`, and the `Acknowledge` control,
  each reused verbatim from the components/screens that own them, never reimplemented here.

# Route & Access

## App Router path

```text
app/(app)/notifications/
├── layout.tsx                # * THIS DOCUMENT — Inbox/Preferences tab strip + the shared realtime binder
├── page.tsx                  # * THIS DOCUMENT (primary) — the Inbox feed (default tab)
├── loading.tsx               # Inbox-shaped skeleton — see # States
└── preferences/
    └── page.tsx              # The 5×5 preference matrix — docs/frontend/NOTIFICATIONS.md
```

Notifications Center is **not** an eleventh entry in the Sidebar's fixed ten-module map — like
`/approvals`, it is a cross-cutting, chrome-adjacent utility screen belonging to every role's own inbox
rather than to one of the platform's ten business modules, and `NAVIGATION_SYSTEM.md`'s module list is not
touched by this document. It is reached through the same two doors `NAVIGATION_SYSTEM.md` names for the
full-history surface: the Topbar bell's "View all in Notifications" link, and a direct/bookmarked URL; the
`UserMenu` additionally gains a "Notification settings" item opening `/notifications/preferences` directly.

## Permission gate

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all (Inbox + Preferences) | **None** — authenticated session only | There is no "absent" case for an authenticated user; `notifications` and `notification_preferences` are `user_id`-scoped, so a Warehouse Employee and an Owner each reach this screen identically |
| An approval row's **Approve** / **Reject** | The `kind`'s own key via `ApprovalCard`'s `PERMISSION_BY_KIND` (`journal_entry → accounting.journal.approve`, `bank_transfer → bank.transfer`, `payroll_release → payroll.approve`, `tax_submission → tax.submit`, `ai_recommendation → ai.approve`) | Rendered **disabled-with-tooltip** when it is not yet this user's turn in a multi-step chain; **omitted entirely** when the role holds no approval permission for that `kind` at all — exactly the two-way split `AI_COMMAND_CENTER.md` states for the Approval Center |
| A fraud/risk row's **Acknowledge** | None — acknowledging carries no elevated permission (`ai/AI_COMMAND_CENTER.md → Detected Risks`) | Always available to any recipient |
| **Resolve** / **Dismiss** on a fraud/risk row | N/A — never rendered on this screen | Both require a note/reason and mutate the same `ai_risk_flags` row the Detected Risks panel owns; this screen offers only a "Review in Detected Risks →" link (`# Interactions & Flows`) |
| A System-category channel toggle (`in_app`/`email` cells of the System row) | Not an RBAC permission — a data-policy exemption (`ERD.md → notification_preferences`) | Rendered permanently **locked-on** with a `[lock]` + `Tooltip` ("Security notifications can't be turned off"); the `PATCH` to disable it is rejected server-side regardless |
| The WhatsApp preference column (all five rows) | N/A — channel not yet live | Rendered **disabled "Coming soon"**; the `Switch` itself is disabled (not merely styled inert), and `PATCH` rejects `channel: "whatsapp"` with a `422` |

Because there is no route-level permission, this screen renders identically in structure for every role;
what varies per notification is only the actions its own embedded content permits, checked by that
component's own gate — a Senior Accountant sees the identical notification row a CFO sees, with Approve/
Reject rendered-but-disabled-with-tooltip if it is not yet their turn.

## Roles

| Role | What renders on this screen |
|---|---|
| Every human role — Owner through Read Only and every module self-service role | The **identical** Inbox and Preferences structure; the narrowest role (authenticated, zero business-module permissions) renders the screen fully and normally, because neither route carries a gating permission (`NOTIFICATIONS.md → Edge Cases` #14). Only the *content* of individual notifications differs — their embedded actions' permission state, and whether their `data.target` 403/404s on click per that destination's own separately-owned permission |
| AI service account | Never renders this screen — it produces the AI-sourced notifications (`FRAUD_DETECTION`, `GENERAL_ACCOUNTANT`, and the rest of the fifteen-agent roster fan out through the `events-notifications` queue consumer), but has no human inbox of its own and appears in no user's notification list |

Company/branch scope: fixed to the active `X-Company-Id` — `notifications` are `company_id`-scoped per
`ERD.md`'s standard columns — but there is **no branch filter**, since a notification belongs to a user
within a company, not to a branch. Keyboard entry uses the existing Topbar bell focus path
(`NAVIGATION_SYSTEM.md`); no new "Go to" chord is introduced for `/notifications` in this batch, matching
`NOTIFICATIONS.md`'s own silence on a mnemonic.

# Layout & Regions

The Inbox is `LAYOUT_SYSTEM.md`'s **List Page Template** with one named extension `NOTIFICATIONS.md →
Layout & Regions` already justifies — a category filter row above the ordinary Filter Bar, and a feed
region in place of a dense `DataTable`, because a notification is a heterogeneous card (an approval, a risk
flag, a plain reminder), not a uniform row of typed columns. The desktop wireframes below are reproduced
from `NOTIFICATIONS.md` for a single source of truth on the arrangement; the tablet and mobile collapse is
this document's own drawn addition.

## Desktop Inbox (`lg`+, ≥1024px)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  Notifications                                          [Mark all read]        │  Page Header
│  482 unread of 3,140                          Inbox │ Preferences              │  count + tab strip
├──────────────────────────────────────────────────────────────────────────────┤
│ [All] [Approvals Due 3] [AI Alerts] [Fraud & Risk 1] [Deadlines] [System]      │  Category Filter Row
│ ( ) All   (•) Unread only            [Search…]            [Date range ▾]        │  Filter Bar
├──────────────────────────────────────────────────────────────────────────────┤
│ [ ]  ● JE-1091 · KD 45,220 needs your approval        2m   [Approve][Reject]     │  approval row
│    Requested by S. Rahman · Approval 1 of 2                                     │
├──────────────────────────────────────────────────────────────────────────────┤
│ [ ]  ▌● Vendor payment held — Al-Fajr Cement        93%  14m  [Acknowledge]      │  fraud/risk row (AiCardShell)
│    Bank details changed 2 hours before payment · Review in Detected Risks →     │
├──────────────────────────────────────────────────────────────────────────────┤
│ [ ]    Invoice INV-2026-000248 is overdue                 1h  [View invoice →]    │  deadline row
│    KWD 525.000 was due 2026-08-15                                               │
├──────────────────────────────────────────────────────────────────────────────┤
│  … cursor-paginated, "Load more" / infinite scroll …                           │  Feed / Load-more footer
└──────────────────────────────────────────────────────────────────────────────┘
```

When ≥1 row is selected, the Filter Bar's end side is replaced by the **Bulk Action Bar** —
"`{n}` selected · Mark read · Mark unread · Clear" — per `LAYOUT_SYSTEM.md`'s List Template convention;
critically, it offers **only** read-state actions, never bulk-approve/reject/delete (`# Interactions &
Flows`).

## Desktop Preferences (`lg`+)

```
┌───────────────────────┬────────┬────────┬────────┬───────────┬─────┐
│                        │ In-App │ Email  │  SMS   │ WhatsApp  │Push │
├───────────────────────┼────────┼────────┼────────┼───────────┼─────┤
│ Approvals Due          │  [on]  │  [on]  │  [off] │  Coming   │[on] │
│ AI Alerts              │  [on]  │  [off] │  [off] │  soon     │[on] │
│ Fraud & Risk           │  [on]  │  [on]  │  [on]  │  (locked) │[on] │
│ Deadlines              │  [on]  │  [on]  │  [off] │           │[off]│
│ System                 │  [on][lock]│  [on][lock]│  [off] │           │[off]│
└───────────────────────┴────────┴────────┴────────┴───────────┴─────┘
   [lock] Security notifications can't be turned off
```

## Tablet & Mobile

At `md` (640–1023px) the category chips scroll horizontally rather than wrapping (keeping row height
predictable), and the Preferences matrix collapses to one `Card` per category with five stacked, labeled
`Switch` rows (`RESPONSIVE_DESIGN.md`'s "cards, not tables"). Below `sm` (<640px) the bell's popover is
replaced entirely by a full-height bottom `Sheet`, the category chips become a snap-aligned carousel, and
the Bulk Action Bar becomes a bottom-sticky bar so selection controls stay thumb-reachable. Full table in
`# Responsive Behavior`.

## Region table with implementation-level grid classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| Tab strip | rendered by `layout.tsx`, `sticky top-(--topbar-h) z-(--z-sticky)` | `Inbox` (active) · `Preferences` | `<Link>`s, not client tab state — each bookmarkable |
| Page Header | `col-span-12 border-b border-ink-4 px-6 py-5` | Title, "{n} unread of {total}", "Mark all read" | "Mark all read" disabled when `unread === 0` |
| Category Filter Row | `flex gap-2 overflow-x-auto` | Six chips with unread sub-counts | Exactly one active at a time |
| Filter Bar | `flex flex-wrap items-center gap-3` | `All`/`Unread only` toggle, search `Input`, `PeriodPicker` date-range | Bulk Action Bar replaces its end side when rows are selected |
| Feed Region | `col-span-12` | `NotificationFeed` — one `NotificationRow` per row | Cursor-paginated, virtualized past 200 rows |
| Load-more Footer | `flex justify-center py-4` | "Load more" + auto infinite-scroll trigger | Never numbered pages (`paginationMode="cursor"`) |

```tsx
// app/(app)/notifications/layout.tsx — Server Component shell + client realtime binder
import { NotificationsTabStrip } from "@/components/notifications/notifications-tab-strip";
import { NotificationsRealtimeBinder } from "@/components/notifications/notifications-realtime-binder";
import { getSessionUser } from "@/lib/auth/session-server";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant- and user-scoped — never statically cached

export default async function NotificationsLayout({ children }: { children: React.ReactNode }) {
  const session = await getSessionUser();
  return (
    <NotificationsRealtimeBinder companyId={session.activeCompany.id} userId={session.user.id}>
      <div className="space-y-6">
        <NotificationsTabStrip />
        {children}
      </div>
    </NotificationsRealtimeBinder>
  );
}
```

```tsx
// components/notifications/notifications-realtime-binder.tsx
"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useRealtimeChannel } from "@/lib/realtime/use-realtime-channel";
import { notificationKeys } from "@/lib/api/query-keys";
import type { NotificationPayload } from "@/types/notifications";

// The identical private-company.{id}.notifications.{user_id} channel NAVIGATION_SYSTEM.md and
// FRONTEND_ARCHITECTURE.md both already name — this document introduces no new channel. Bound ONCE
// at the shell level, for the lifetime of any /notifications/* route, never re-bound per tab.
export function NotificationsRealtimeBinder({
  companyId, userId, children,
}: { companyId: number; userId: string; children: React.ReactNode }) {
  const qc = useQueryClient();
  useRealtimeChannel(`private-company.${companyId}.notifications.${userId}`, {
    onEvent: (n: NotificationPayload) => {
      // Badge count: patch directly (+1) — a snappy, high-frequency, low-risk tick, reconciled by an
      // ordinary invalidation on the next popover open / reconnect (NOTIFICATIONS.md → Realtime).
      qc.setQueryData(notificationKeys.unread(), (c: number = 0) => c + 1);
      // Feed: invalidate, never splice above the user's scroll position — a "1 new · Refresh" banner
      // surfaces instead (see NotificationFeed below and # Accessibility's live-region policy).
      qc.invalidateQueries({ queryKey: notificationKeys.list({ category: "all", readState: "all" }) });
      if (n.category !== "all") {
        qc.invalidateQueries({ queryKey: notificationKeys.list({ category: n.category, readState: "unread" }) });
      }
    },
  });
  return <>{children}</>;
}
```

Binding the channel once at the shell (dependency array `[companyId, userId]`, not `[pathname]`) means
navigating between Inbox and Preferences never tears down and re-establishes the WebSocket subscription —
only an actual company switch or sign-out does, the identical once-per-shell discipline
`SETTINGS_SCREEN.md`'s `SettingsRealtimeBinder` applies to its own module channel.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue or is one of
the Notifications-scoped compositions `docs/frontend/NOTIFICATIONS.md → Components Used` already names and
files under `components/notifications/`. This section gives the concrete prop contracts and, for the
compositions that document shows only in ASCII, the actual implementation.

| Component | Source | New in this document |
|---|---|---|
| `ApprovalCard`, `AIProposalPanel`, `AiCardShell`, `ConfidenceBadge`, `StatusPill`, `Badge`, `Switch`, `Tabs`, `Checkbox`, `Tooltip`, `Sheet`, `PeriodPicker`, `AmountCell`, `FormattedRelativeTime`, `EmptyState`/`ErrorState`, `Skeleton`, `useApiToast` | `COMPONENT_LIBRARY.md` / `AI_WIDGETS.md` (existing) | No — reused verbatim, props unchanged |
| `NotificationsBell` | `components/layout/notifications-bell.tsx` (existing, `NAVIGATION_SYSTEM.md`) | No — unchanged trigger/badge/unread query; this doc only fills its popover body |
| `NotificationsTabStrip` | `components/notifications/notifications-tab-strip.tsx` | **Yes** — full source below |
| `NotificationRow` | `components/notifications/notification-row.tsx` | **Yes** — full source below; the `category`-branching row `NOTIFICATIONS.md` shows only in ASCII |
| `NotificationFeed` | `components/notifications/notification-feed.tsx` | **Yes** — full `useInfiniteQuery` source below |
| `NotificationPopoverFeed` | `components/layout/notification-popover-feed.tsx` | **Yes** — prop contract below (reuses `NotificationRow` in `density="popover"`) |
| `NotificationCategoryTabs` | `components/notifications/notification-category-tabs.tsx` | **Yes** — full source below |
| `NotificationBulkActionBar` | `components/notifications/notification-bulk-action-bar.tsx` | **Yes** — prop contract below |
| `NotificationPreferencesMatrix` | `components/notifications/notification-preferences-matrix.tsx` | **Yes** — full source below |
| `FeedSkeleton` | `components/notifications/feed-skeleton.tsx` | **Yes** — full source in `# States` |

`NotificationRow` deliberately does not fork into `NotificationApprovalRow`/`NotificationRiskRow` per
category — `category` is a `switch` inside one component's render body, the same pattern `StatusPill` uses
for five status domains from one lookup table rather than five components (`NOTIFICATIONS.md → Components
Used`); a sixth category later is a new `case`, never a sixth React component.

## `NotificationsTabStrip`

```tsx
// components/notifications/notifications-tab-strip.tsx
"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useTranslations } from "@/lib/i18n/use-translations";

const TABS = [
  { href: "/notifications", labelKey: "notifications.tab.inbox" },
  { href: "/notifications/preferences", labelKey: "notifications.tab.preferences" },
] as const;

export function NotificationsTabStrip() {
  const pathname = usePathname();
  const t = useTranslations();
  return (
    <nav
      aria-label={t("notifications.tabStrip.label")}
      className="sticky top-(--topbar-h) z-(--z-sticky) flex gap-1 border-b border-ink-4 bg-surface px-4"
    >
      {TABS.map((tab) => {
        const active = tab.href === "/notifications" ? pathname === "/notifications" : pathname.startsWith(tab.href);
        return (
          <Link
            key={tab.href}
            href={tab.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "shrink-0 border-b-2 px-3 py-2.5 text-caption font-medium",
              active ? "border-accent text-ink-12" : "border-transparent text-ink-9 hover:text-ink-11",
            )}
          >
            {t(tab.labelKey)}
          </Link>
        );
      })}
    </nav>
  );
}
```

## `NotificationRow` — the `category`-branching row

```tsx
// components/notifications/notification-row.tsx
"use client";

import Link from "next/link";
import { Checkbox } from "@/components/ui/checkbox";
import { AiCardShell } from "@/components/ai/ai-card-shell";
import { ApprovalCard } from "@/components/shared/approval-card";
import { AIProposalPanel } from "@/components/ai/ai-proposal-panel";
import { ConfidenceBadge } from "@/components/ai/confidence-badge";
import { FormattedRelativeTime } from "@/components/shared/formatted-relative-time";
import { cn } from "@/lib/utils";
import { useMarkRead } from "@/hooks/notifications/use-mark-read";
import type { Notification } from "@/types/notifications";

export function NotificationRow({
  n, density = "comfortable", selectable = false, selected = false, onSelect,
}: {
  n: Notification;
  density?: "popover" | "comfortable";
  selectable?: boolean;
  selected?: boolean;
  onSelect?: (id: number, checked: boolean) => void;
}) {
  const markRead = useMarkRead();
  const unread = n.read_at === null;

  // Clicking the row body (outside embedded action buttons) marks read + navigates to data.target,
  // exactly matching the bell popover's own existing click contract (NOTIFICATIONS.md → Deep-linking).
  const onBodyClick = () => { if (unread) markRead.mutate({ id: n.id, read: true }); };

  const body = (
    <div className="min-w-0 flex-1">
      <div className="flex items-center gap-2">
        {unread && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-accent" aria-hidden />}
        <Link href={n.data.target} onClick={onBodyClick} className={cn("truncate", unread ? "font-medium text-ink-12" : "text-ink-11")}>
          {n.title}
        </Link>
        <span className="ms-auto shrink-0 text-caption text-ink-8"><FormattedRelativeTime value={n.created_at} /></span>
      </div>
      {density === "comfortable" && <p className="mt-0.5 truncate text-caption text-ink-9">{n.body}</p>}
      {renderAction(n)}
    </div>
  );

  const inner = (
    <div className="flex items-start gap-3 px-4 py-3">
      {selectable && (
        <Checkbox checked={selected} onCheckedChange={(c) => onSelect?.(n.id, Boolean(c))} aria-label={`Select: ${n.title}`} className="mt-0.5" />
      )}
      {body}
    </div>
  );

  // AI-sourced categories always render inside AiCardShell — the mandatory colored-border-plus-badge
  // provenance treatment, pixel-identical to the same risk's card on the Detected Risks panel.
  return n.category === "ai_alert" || n.category === "fraud_risk"
    ? <AiCardShell agentCode={n.data.agent_code} severity={n.data.severity}>{inner}</AiCardShell>
    : inner;
}

function renderAction(n: Notification) {
  switch (n.category) {
    case "approval":
      // The identical ApprovalCard + POST /approvals/{id}/approve|reject the Approval Center uses,
      // in its compact presentation — a second entry point, never a reimplementation.
      return <ApprovalCard compact kind={n.data.kind} approvalId={n.data.id} amount={n.data.amount}
                requestedBy={n.data.requested_by} step={n.data.step_label} />;
    case "ai_alert":
      // Graduated (recommended_action populated) → AIProposalPanel's three-button pattern, gated by
      // the API-computed can_execute_directly; a pure observation → text + "Explain more".
      return n.data.recommended_action
        ? <AIProposalPanel decision={n.data} autonomyLevel={n.data.autonomy_level} />
        : <ExplainMoreLink sources={n.data.sources} />;
    case "fraud_risk":
      return (
        <div className="mt-1 flex items-center gap-3">
          <ConfidenceBadge confidence={n.data.confidence_score} source="percentage" reasoning={n.data.reasoning} size="sm" />
          <AcknowledgeButton riskId={n.data.id} />
          <Link href={n.data.target} className="text-caption text-accent hover:underline">Review in Detected Risks →</Link>
        </div>
      );
    case "deadline":
    case "system":
      return <Link href={n.data.target} className="mt-1 inline-block text-caption text-accent hover:underline">View →</Link>;
    default:
      return null;
  }
}
```

`AcknowledgeButton` fires a single optimistic `POST /api/v1/ai/risks/{id}/acknowledge` (no dialog),
mirroring `ai_risk_flags.status`'s own `open → acknowledged` transition; **Resolve** and **Dismiss** are
deliberately never rendered here (both need a note/reason and mutate the same row the Detected Risks panel
owns), which is why every fraud/risk row carries the "Review in Detected Risks →" link instead
(`NOTIFICATIONS.md → Interactions & Flows`).

## `NotificationFeed` — the `useInfiniteQuery` implementation

```tsx
// components/notifications/notification-feed.tsx
"use client";

import { useState } from "react";
import { useInfiniteQuery } from "@tanstack/react-query";
import { useVirtualizer } from "@tanstack/react-virtual";
import { NotificationRow } from "@/components/notifications/notification-row";
import { NotificationBulkActionBar } from "@/components/notifications/notification-bulk-action-bar";
import { NewArrivalsBanner } from "@/components/notifications/new-arrivals-banner";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FeedSkeleton } from "@/components/notifications/feed-skeleton";
import { notificationKeys } from "@/lib/api/query-keys";
import { fetchNotifications } from "@/lib/api/notifications";
import type { NotificationFilters } from "@/types/notifications";

export function NotificationFeed({ filters }: { filters: NotificationFilters }) {
  const [selected, setSelected] = useState<Set<number>>(new Set());

  const query = useInfiniteQuery({
    queryKey: notificationKeys.list(filters),
    queryFn: ({ pageParam }) => fetchNotifications({ ...filters, cursor: pageParam, per_page: 25 }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.meta.pagination.cursor ?? undefined, // uniform cursor contract (README)
    staleTime: 30_000, // the DASHBOARD Recent Activity tier (NOTIFICATIONS.md → Cache tuning)
  });

  if (query.isPending) return <FeedSkeleton rows={8} />;
  if (query.isError) return <ErrorState title="Couldn't load your notifications" onRetry={() => query.refetch()} />;

  const rows = query.data.pages.flatMap((p) => p.data);
  if (rows.length === 0) return <FeedEmpty filters={filters} />; // three distinct copies — see # States

  return (
    <div>
      <NewArrivalsBanner queryKey={notificationKeys.list(filters)} onRefresh={() => query.refetch()} />
      {selected.size > 0 && (
        <NotificationBulkActionBar
          ids={[...selected]}
          onCleared={() => setSelected(new Set())}
        />
      )}
      <VirtualizedRows
        rows={rows}
        selected={selected}
        onSelect={(id, checked) => setSelected((s) => { const n = new Set(s); checked ? n.add(id) : n.delete(id); return n; })}
        onEndReached={() => { if (query.hasNextPage && !query.isFetchingNextPage) query.fetchNextPage(); }}
      />
      {query.isFetchingNextPage && <FeedSkeleton rows={1} />}
      {!query.hasNextPage && rows.length > 0 && (
        <p className="py-4 text-center text-caption text-ink-8">You've reached the end</p>
      )}
    </div>
  );
}

// VirtualizedRows switches to @tanstack/react-virtual past 200 loaded rows (the shared threshold,
// COMPONENT_LIBRARY.md); below that it renders a plain list — virtualization is cost, not decoration.
```

Each `data.target` is a real route from the same finite manifest `NAVIGATION_SYSTEM.md → Routing & Deep
Links` checks in CI ("an integration test resolves every `target` value... against the actual Next.js
route manifest at build time") — this feed introduces no second target-resolution scheme, and a stale
target pointing at a deleted/moved record renders that route's own `not-found.tsx`, deliberately
indistinguishable from "belongs to a different company" so a link never leaks existence
(`NOTIFICATIONS.md → Edge Cases` #1).

## `NotificationCategoryTabs`

```tsx
// components/notifications/notification-category-tabs.tsx
"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { notificationKeys } from "@/lib/api/query-keys";
import { fetchNotificationCounts } from "@/lib/api/notifications";

const CATEGORIES = [
  { key: "all", labelKey: "notifications.category.all" },
  { key: "approval", labelKey: "notifications.category.approvals" },
  { key: "ai_alert", labelKey: "notifications.category.aiAlerts" },
  { key: "fraud_risk", labelKey: "notifications.category.fraudRisk" },
  { key: "deadline", labelKey: "notifications.category.deadlines" },
  { key: "system", labelKey: "notifications.category.system" },
] as const;

export function NotificationCategoryTabs() {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const active = params.get("category") ?? "all";
  // One GET /notifications/counts companion call (grouped category → unread), NOT six list requests.
  const { data: counts } = useQuery({ queryKey: notificationKeys.counts(), queryFn: fetchNotificationCounts, staleTime: 30_000 });

  return (
    <div role="tablist" aria-label="Notification categories" className="flex gap-2 overflow-x-auto px-4 py-2">
      {CATEGORIES.map((c) => {
        const isActive = c.key === active;
        const sub = c.key === "all" ? undefined : counts?.[c.key];
        return (
          <button
            key={c.key}
            role="tab"
            aria-selected={isActive}
            onClick={() => { const p = new URLSearchParams(params); c.key === "all" ? p.delete("category") : p.set("category", c.key); router.replace(`${pathname}?${p}`); }}
            className={cn(
              "flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-caption",
              isActive ? "border-accent bg-accent-subtle/40 text-ink-12" : "border-ink-6 text-ink-9 hover:text-ink-11",
            )}
          >
            {/* label via t(c.labelKey) at call site */}
            {c.key}
            {sub ? <Badge size="sm" tone="neutral">{sub}</Badge> : null}
          </button>
        );
      })}
    </div>
  );
}
```

## `NotificationBulkActionBar` & `NotificationPopoverFeed` (prop contracts)

| Component | Key props | Notes |
|---|---|---|
| `NotificationBulkActionBar` | `ids: number[]`; `onCleared()` | Renders "`{n}` selected · Mark read · Mark unread · Clear"; calls `useBulkAction` (`POST /notifications/bulk-action`) with `action: "mark_read" \| "mark_unread"` **only** — never approve/reject/delete (`# Interactions & Flows`) |
| `NotificationPopoverFeed` | *(none — reads `notificationKeys.unread()`-adjacent list internally)* | Up to 20 `NotificationRow`s in `density="popover"` (single-line, no chips, no checkboxes) + a permanent "View all in Notifications →" footer; never virtualized (the 20-cap keeps it cheap) |

## `NotificationPreferencesMatrix`

```tsx
// components/notifications/notification-preferences-matrix.tsx
"use client";

import { useQuery } from "@tanstack/react-query";
import { Lock } from "lucide-react";
import { Switch } from "@/components/ui/switch";
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";
import { notificationKeys } from "@/lib/api/query-keys";
import { fetchNotificationPreferences } from "@/lib/api/notifications";
import { useUpdatePreference } from "@/hooks/notifications/use-update-preference";
import { useTranslations } from "@/lib/i18n/use-translations";

const CHANNELS = ["in_app", "email", "sms", "whatsapp", "push"] as const;

export function NotificationPreferencesMatrix() {
  const t = useTranslations();
  const { data } = useQuery({
    queryKey: notificationKeys.preferences(),
    queryFn: fetchNotificationPreferences,
    staleTime: 300_000, // user-driven, rarely changed elsewhere (NOTIFICATIONS.md → Cache tuning)
  });
  const update = useUpdatePreference(); // optimistic, cell-scoped rollback

  if (!data) return null; // FeedSkeleton-equivalent handled by the page's Suspense boundary

  return (
    <table className="w-full">
      <thead>
        <tr>
          <th className="text-start" />
          {CHANNELS.map((ch) => <th key={ch} className="px-2 text-caption font-medium text-ink-9">{t(`notifications.channel.${ch}`)}</th>)}
        </tr>
      </thead>
      <tbody>
        {data.categories.map((cat) => (
          <tr key={cat.category} className="border-t border-ink-4">
            <th scope="row" className="py-2 text-start text-body text-ink-11">{t(`notifications.category.${cat.category}`)}</th>
            {CHANNELS.map((ch) => {
              const value = cat.channels[ch];                         // true | false | null (whatsapp)
              const whatsappUnavailable = ch === "whatsapp" && !data.whatsapp_available;
              const locked = cat.locked_channels?.includes(ch) ?? false; // System row's in_app/email
              const disabled = whatsappUnavailable || locked;
              const reason = whatsappUnavailable ? t("notifications.channel.comingSoon")
                            : locked ? t("notifications.channel.securityLocked") : "";
              return (
                <td key={ch} className="px-2 py-2 text-center">
                  {disabled ? (
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="inline-flex items-center gap-1">
                          <Switch checked={locked ? true : false} disabled aria-label={`${cat.category}, ${ch}`} aria-disabled />
                          {locked && <Lock className="h-3 w-3 text-ink-8" aria-hidden />}
                        </span>
                      </TooltipTrigger>
                      <TooltipContent>{reason}</TooltipContent>
                    </Tooltip>
                  ) : (
                    <Switch
                      checked={Boolean(value)}
                      aria-label={`${cat.category}, ${ch}`}
                      onCheckedChange={(checked) => update.mutate({ category: cat.category, channel: ch, is_enabled: checked })}
                    />
                  )}
                </td>
              );
            })}
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

The matrix reads `channels.whatsapp: null` (not `true`/`false`) plus a root `whatsapp_available: false` to
render the whole column locked — `NOTIFICATIONS.md → Data & State` chooses `null` deliberately so a boolean
never claims a preference is being honored when no WhatsApp delivery integration exists to honor it. Below
`md` this exact `<table>` collapses to one `Card` per category with five stacked labeled `Switch` rows
(`# Responsive Behavior`).

# Data & State

## Endpoints this screen calls

Reproduced from `NOTIFICATIONS.md → Data & State` in condensed form, showing only the calls this screen's
two pages issue on first paint or from a control they render:

| Purpose | Endpoint | First-paint or on-demand |
|---|---|---|
| Inbox feed (cursor) | `GET /api/v1/notifications?filter[category]=&filter[read_at]=&q=&filter[created_at][from\|to]=&cursor=&per_page=25` | First paint (Inbox) |
| Category sub-counts | `GET /api/v1/notifications/counts` (grouped `category` → unread) | First paint (category chips) |
| Unread badge (bell) | `GET /api/v1/notifications?filter[read_at]=null&per_page=20` | Chrome — unchanged, `NotificationsBell` |
| Mark one read/unread | `PATCH /api/v1/notifications/{id}` (`{ "read_at": "<ISO>" }` / `{ "read_at": null }`) | Row click / row action |
| Bulk mark read/unread | `POST /api/v1/notifications/bulk-action` (`{ ids, action: "mark_read" \| "mark_unread" }`) | Bulk Action Bar |
| Mark all as read | `POST /api/v1/notifications/mark-all-read` (no body) | Page Header action |
| Preferences read/write | `GET` / `PATCH /api/v1/notifications/preferences` | Preferences tab |
| Approve / Reject (embedded) | `POST /api/v1/approvals/{id}/approve` / `/reject` | Approval row |
| Acknowledge (embedded) | `POST /api/v1/ai/risks/{id}/acknowledge` | Fraud/risk row |

## Worked request/response examples

Inbox list response (`GET /api/v1/notifications?filter[category]=fraud_risk&per_page=25`), reproduced from
`NOTIFICATIONS.md → Data & State` so an engineer wiring `NotificationRow`'s fraud/risk branch has a real
fixture:

```json
{
  "success": true,
  "data": [
    {
      "id": 88231, "channel": "in_app", "category": "fraud_risk",
      "title": "Vendor payment held — Al-Fajr Cement Supplies",
      "body": "A vendor bank-detail change 2 hours before the scheduled KWD 18,540.000 payment triggered an automatic hold.",
      "data": {
        "type": "ai_risk_flags", "id": 55214, "severity": "critical", "status": "monitoring",
        "financial_exposure": "18540.0000", "agent_code": "FRAUD_DETECTION", "confidence_score": 96.0,
        "reasoning": "Vendor bank details changed via an unverified channel 2 hours before a scheduled payment run — this pattern matches 94% of confirmed business-email-compromise attempts in the training set.",
        "target": "/ai/risks/55214"
      },
      "read_at": null, "sent_at": "2026-07-16T05:42:00+03:00",
      "delivery_status": "delivered", "created_at": "2026-07-16T05:42:00+03:00"
    }
  ],
  "message": "OK", "errors": [],
  "meta": { "pagination": { "page": null, "per_page": 25, "total": null, "total_hint": 6, "cursor": "eyJpZCI6ODgyMzF9" } },
  "request_id": "5e3a1e6a-...", "timestamp": "2026-07-16T07:12:00+03:00"
}
```

Preferences write (`PATCH /api/v1/notifications/preferences`) — a single cell toggle; the server expands
`category` + `channel` into the underlying per-`event_code` `notification_preferences` upserts, the client
never enumerating event codes:

```json
// Request
{ "category": "fraud_risk", "channel": "email", "is_enabled": false }
```
```json
// 200 OK — returns the full re-shaped grid so the matrix reconciles from one payload
{
  "success": true,
  "data": {
    "categories": [
      { "category": "approval",   "label_en": "Approvals Due", "label_ar": "الموافقات المستحقة", "channels": { "in_app": true, "email": true,  "sms": false, "whatsapp": null, "push": true } },
      { "category": "ai_alert",   "label_en": "AI Alerts",     "label_ar": "تنبيهات الذكاء الاصطناعي", "channels": { "in_app": true, "email": false, "sms": false, "whatsapp": null, "push": true } },
      { "category": "fraud_risk", "label_en": "Fraud & Risk",  "label_ar": "الاحتيال والمخاطر", "channels": { "in_app": true, "email": false, "sms": true,  "whatsapp": null, "push": true } },
      { "category": "deadline",   "label_en": "Deadlines",     "label_ar": "المواعيد النهائية", "channels": { "in_app": true, "email": true,  "sms": false, "whatsapp": null, "push": false } },
      { "category": "system",     "label_en": "System",        "label_ar": "النظام", "channels": { "in_app": true, "email": true, "sms": false, "whatsapp": null, "push": false }, "locked_channels": ["in_app", "email"] }
    ],
    "whatsapp_available": false
  },
  "message": "Preferences updated.", "errors": [], "meta": {},
  "request_id": "a71c...", "timestamp": "2026-07-16T07:13:00+03:00"
}
```

A `422` rejecting `channel: "whatsapp"` (attempted before the channel is live) is caught by the matrix's
own mutation and never reached in the first place — the `Switch` for that column is `disabled`, not merely
styled inert (`NOTIFICATIONS.md → Edge Cases` #5).

## Query keys

```ts
// lib/api/query-keys.ts (Notifications factories — cited from NOTIFICATIONS.md → Query keys)
export const notificationKeys = {
  all: ["notifications"] as const,
  unread: () => [...notificationKeys.all, "unread"] as const,        // the shorter form the shipped bell uses
  counts: () => [...notificationKeys.all, "counts"] as const,        // category → unread sub-count map
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

This document standardizes on `notificationKeys.unread()` → `["notifications", "unread"]` (the shorter
form the already-shipped `NotificationsBell` uses), treating `FRONTEND_ARCHITECTURE.md`'s realtime
listener's `["notifications", "unread-count"]` as the one needing its literal key updated to match on next
edit — the exact one-line reconciliation `NOTIFICATIONS.md → Query keys` already directs.

## Cache tuning

| Data class | Resource | `staleTime` | Rationale |
|---|---|---|---|
| Unread badge | `notificationKeys.unread()` | `0`, realtime-corrected | Matches the bell's own behavior; never polled |
| Category sub-counts | `notificationKeys.counts()` | `30_000` | One companion call, re-invalidated with the list on a realtime arrival |
| Inbox list | `notificationKeys.list(filters)` | `30_000` | Frequent enough writes to want freshness, not sub-minute polling — the `DASHBOARD` Recent Activity tier |
| Preferences | `notificationKeys.preferences()` | `300_000` (5 min), no `refetchOnWindowFocus` | User-driven, rarely changed elsewhere; reconciled by the mutation's own optimistic write |

## Mutation strategy — optimistic vs. pessimistic

Every mutation on this screen is either optimistic (reversible, non-financial read-state or preference
changes) or a re-entry into an already-pessimistic financial mutation owned elsewhere. This screen owns no
financial mutation of its own — it is a triage surface, per `NOTIFICATIONS.md → AI agents feeding this
screen`.

| Mutation | Strategy | Rationale |
|---|---|---|
| `useMarkRead` (one) | **Optimistic** — flips `read_at` in the cached list, rolls back on error | Reversible (mark unread again); a stale badge is corrected by realtime |
| `useBulkAction` (mark read/unread, N) | **Optimistic** — flips every selected id, rolls back the whole batch on error | Read-state only; never approve/reject/delete |
| `useMarkAllRead` | **Optimistic**, then `invalidate` `list`+`unread` | Reversible, non-financial, purely cosmetic — no confirmation dialog (`FRONTEND_ARCHITECTURE.md` Principle 10) |
| `useUpdatePreference` (matrix cell) | **Optimistic**, cell-scoped rollback on `422` | One reversible toggle; a failed write rolls back just that cell and toasts, never blanks the grid |
| `useApproveRequest` / `useRejectRequest` (embedded) | **Pessimistic** — re-fetches the live target and diffs before submitting (`NOTIFICATIONS.md → Edge Cases` #2) | Identical to the Approval Center; the same `Idempotency-Key`, the same conflict posture — never a second implementation |
| `useAcknowledgeRisk` (embedded) | **Optimistic** — single `POST`, no dialog | Mirrors `ai_risk_flags` `open → acknowledged`; carries no elevated permission |

```ts
// hooks/notifications/use-mark-read.ts — the optimistic, list-scoped pattern
export function useMarkRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, read }: { id: number; read: boolean }) =>
      api.patch(`/notifications/${id}`, { read_at: read ? new Date().toISOString() : null }),
    onMutate: async ({ id, read }) => {
      await qc.cancelQueries({ queryKey: notificationKeys.all });
      const previous = qc.getQueriesData({ queryKey: notificationKeys.all });
      // Flip read_at across every cached list page that contains this id, and tick the badge.
      qc.setQueriesData({ queryKey: notificationKeys.all }, (old: any) => flipReadAt(old, id, read));
      qc.setQueryData(notificationKeys.unread(), (c: number = 0) => Math.max(0, read ? c - 1 : c + 1));
      return { previous };
    },
    onError: (_e, _v, ctx) => ctx?.previous.forEach(([key, data]: any) => qc.setQueryData(key, data)),
    onSettled: () => qc.invalidateQueries({ queryKey: notificationKeys.all }),
  });
}
```

## Realtime

The one channel — `private-company.{id}.notifications.{user_id}` — is bound once by
`NotificationsRealtimeBinder` in `layout.tsx` (source in `# Layout & Regions`), the identical channel
`NAVIGATION_SYSTEM.md` and `FRONTEND_ARCHITECTURE.md` already name; this document introduces no new channel.
The badge count patches directly (`+1`) on arrival — a snappy, high-frequency, low-risk tick reconciled by
an ordinary invalidation on the next popover open or reconnect. A new row arriving while the Inbox is open
and scrolled mid-list is **never** spliced in above the user's scroll position; per `# Accessibility`'s
live-region policy it surfaces as a small, dismissible `NewArrivalsBanner` ("1 new notification — Refresh")
pinned above the feed, the identical pattern `AI_COMMAND_CENTER.md` uses for its own Approval Center queue.

# Interactions & Flows

Numbered as a concrete implementation sequence; the narrative form is `NOTIFICATIONS.md → Interactions &
Flows`'s own territory and is not repeated here.

1. **Opening the bell popover.** Unchanged from `NAVIGATION_SYSTEM.md`. Clicking a row marks that one read
   (`PATCH .../{id}`, optimistic) and navigates to `data.target`; "View all in Notifications" navigates to
   `/notifications` with **no** filter carried over — the popover's 20-row cap is a chrome convenience, not
   a persisted choice the full page inherits.
2. **Filtering by category.** A `NotificationCategoryTabs` chip updates `?category=` in the URL (mirrored,
   shareable) and re-keys `notificationKeys.list`; the `All`/`Unread only` toggle and the search input
   compose additively with the active chip. Each chip's sub-count comes from the single
   `GET /notifications/counts` call, never six list requests.
3. **Selecting rows and bulk actions.** Checking ≥1 row replaces the Filter Bar's end side with the Bulk
   Action Bar. It offers **only** `mark_read`/`mark_unread` — never bulk-approve, bulk-reject, or
   bulk-delete: bulk-approve stays exclusive to the Approval Center's own queue, and there is no delete at
   all (deletion is a backend retention concern; the user-facing analogue is marking read, which is
   reversible in a way a delete is not).
4. **Marking all as read.** The Page Header action opens no confirmation (reversible, non-financial,
   cosmetic) and follows the "optimistic where safe" posture; the server stamps `read_at = now()` on every
   row unread as of its own request-processing time, so a genuinely-new realtime arrival that lands
   mid-request is never swept up by it (`NOTIFICATIONS.md → Edge Cases` #7).
5. **Approval rows.** `NotificationRow`'s `approval` branch renders `ApprovalCard` compact; **Approve**
   calls the identical `useApproveRequest` (same `Idempotency-Key`, same optimistic status flip) the
   Approval Center uses, and **Reject** opens the identical reason-required `AlertDialog`. Nothing is
   re-implemented — a notification is a second entry point into one mutation.
6. **Fraud/risk rows.** Render inside `AiCardShell` with `ConfidenceBadge` and exactly one inline action —
   **Acknowledge** (single optimistic `POST .../acknowledge`, no dialog) — plus a "Review in Detected Risks
   →" link; Resolve/Dismiss are never rendered here.
7. **AI Alert rows.** A graduated row (`recommended_action` populated) renders `AIProposalPanel`'s
   three-button pattern (Do it / Send for approval / Dismiss), with "Do it" **absent** — not disabled —
   when the API-computed `can_execute_directly` is `false`; a pure observation renders as text with an
   "Explain more" link opening Ask AI pre-seeded with the row's own `sources`.
8. **Deadline & System rows.** Render a single "View →" link to `data.target` (an overdue invoice → that
   invoice, a `permission.changed` → `/settings/users`), no inline mutation — the correct next step is
   always "go look at the thing."
9. **Deep-linking & row-body click.** Clicking any row's body (outside its embedded action buttons) marks
   it read and navigates; every `data.target` resolves against the CI-checked route manifest.
10. **Preferences edits.** Toggling a matrix `Switch` fires `PATCH /notifications/preferences` immediately
    with an optimistic flip (cell-scoped rollback on error, toast on failure) — no batched "Save" step; a
    locked System cell and the WhatsApp column never reach a request in the first place (the `Switch` is
    `disabled`).

# AI Integration

Every AI-sourced row — the `ai_alert` and `fraud_risk` categories — carries its confidence score, its
reasoning, and its source citation exactly as the platform's AI Integration Layer requires everywhere else;
no card here shows a bare number or a bare claim without a visible way to see why. This screen performs no
AI computation of its own — every value it renders was computed by the owning agent and is only re-surfaced
unmodified as a triage item (`NOTIFICATIONS.md → AI agents feeding this screen`). The concrete numeric and
structural gates an implementer needs:

| Gate | Value / rule | Effect on this screen |
|---|---|---|
| Provenance treatment | `AiCardShell` only — no new border or badge shape | A fraud-risk row's left border and "AI" badge are pixel-identical to the same risk's card on the Detected Risks panel — two renderings of one `ai_risk_flags` row, not two visual languages |
| Confidence normalization | `ai_risk_flags.confidence_score` and `ai_decisions.confidence_score` arrive `0–100`; render via `normalizeConfidence(score, "percentage")` | Never a screen-local percentage calculation; `ConfidenceBadge`'s `source="percentage"` in `NotificationRow`'s fraud/risk branch |
| Server-authoritative execute gate | `can_execute_directly: boolean` on an `ai_alert` decision payload | "Do it" is **absent**, not disabled, when `false` — the same structural guarantee `AI_COMMAND_CENTER.md` states: "a gated action has no client-side path to execute itself" |
| Critical-severity ordering | `ai_risk_flags.severity === "critical"` pins to the top of its category and above every non-critical row in `All`, regardless of `created_at` | Urgency is never subordinate to recency — mirroring the Approval Center's `held`-pinning rule |
| Sensitive-action re-entry | Approving a bank transfer / payroll release / tax submission always routes through `POST /approvals/{id}/approve`; a mobile approval requires the identical biometric re-confirmation | Nothing about arriving as a notification lowers the bar a queue row would clear |

The category taxonomy (`approval` · `ai_alert` · `fraud_risk` · `deadline` · `system`) is a
server-computed `category` attribute this screen renders and filters by, exactly like it treats `read`
(`read_at IS NOT NULL`) — **it is never re-derived client-side** from a raw `event_code` string. The
reference `notification_templates.code → category` mapping lives in `NOTIFICATIONS.md → Data & State →
Category taxonomy` as documentation of an intended server-side mapping, and no component in this document
contains it as executable code.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Bell popover | Badge shows the last-known cached count, never a flash to `0`, while the background refetch runs | "You're all caught up" with a checkmark, no badge mounted (`unreadCount > 0`-gated) | Keeps the last successful count with an inline retry, never resets to an alarming `0` |
| Inbox feed (first load) | 6–8 `NotificationRow`-shaped skeletons matching each category's eventual card height (`FeedSkeleton`) | **Two copies**: "You're all caught up — nothing new" (returning user, `Unread only`) vs. "No notifications yet — you'll see approvals, alerts, and reminders here as they happen" (a genuinely new account, `All`, zero rows ever) | Inline "Couldn't load your notifications — Retry" card; category chips and the Preferences tab stay usable |
| Inbox feed (category-filtered, zero matches) | N/A | A third, lighter copy: "No {category} notifications right now" — visually distinct so a user never confuses "this category is empty" with "everything failed" | Same inline retry |
| Load-more (pagination) | A single trailing skeleton row, not a full-feed reset | The "Load more" control disappears once `cursor` is exhausted → "You've reached the end" | A failed "load more" leaves every already-loaded row intact with an inline retry at the list's end |
| Preferences matrix | Five skeleton rows matching the real grid's row height | N/A — the matrix always renders (every user has a full 5×5 set, defaulted server-side) | Inline retry card in place of the whole matrix; a single failed toggle rolls back just that one cell and toasts, never blanks the grid |

```tsx
// components/notifications/feed-skeleton.tsx
import { Skeleton } from "@/components/ui/skeleton";

// Row heights differ by category (an approval row is taller than a deadline row) so CLS stays near
// zero — the skeleton reflects that variance rather than one generic row height (# Performance).
const ROW_HEIGHTS = ["h-16", "h-20", "h-12", "h-16", "h-12", "h-20", "h-12", "h-16"] as const;

export function FeedSkeleton({ rows = 8 }: { rows?: number }) {
  return (
    <div className="divide-y divide-ink-4" aria-hidden>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className={`flex items-start gap-3 px-4 py-3 ${ROW_HEIGHTS[i % ROW_HEIGHTS.length]}`}>
          <Skeleton className="mt-0.5 h-4 w-4 shrink-0 rounded-sm" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-3.5 w-2/3" style={{ animationDelay: `${i * 40}ms` }} />
            <Skeleton className="h-3 w-1/2" style={{ animationDelay: `${i * 40}ms` }} />
          </div>
          <Skeleton className="h-3 w-8 shrink-0" style={{ animationDelay: `${i * 40}ms` }} />
        </div>
      ))}
    </div>
  );
}
```

The staggered `animationDelay` produces the platform's standard 1.6s single-wave shimmer
(`DESIGN_LANGUAGE.md → Motion → Named patterns`), the identical mechanic `ACCOUNTING_SCREEN.md`'s
`TreeSkeleton` establishes. `loading.tsx` renders the tab strip's shape plus a `FeedSkeleton rows={8}`, so
the shell paints instantly while the Server Component's prefetch resolves behind `Suspense`.

# Responsive Behavior

Breakpoints are `RESPONSIVE_DESIGN.md`'s fixed scale — this screen introduces no breakpoint of its own —
refining `NOTIFICATIONS.md → Responsive Behavior`'s coarser labels at implementation precision.

| Token | Width | This screen's behavior |
|---|---|---|
| `base` | <640px | The bell's popover becomes a full-height bottom `Sheet` (a 320px popover on a 375px viewport is unusable); the Inbox category chips become a snap-aligned horizontal carousel; the Bulk Action Bar becomes a bottom-sticky bar rather than replacing the Filter Bar in place, so selection controls stay thumb-reachable |
| `sm` | 640px | Still single-column feed; the date-range `PeriodPicker` opens as a sheet |
| `md` | 768px | Popover returns (there is room); category chips still scroll horizontally rather than wrapping (predictable row height); the Preferences matrix collapses to one `Card` per category, five stacked `Switch` rows each |
| `lg` | 1024px | Category chips fit on one line for most locales; the Preferences matrix returns to its full 5×5 `<table>` grid |
| `xl` | 1280px | Unchanged from `lg` — a notification inbox does not benefit from more columns past this point, only from more visible rows, which virtualization already provides |
| `2xl` / `3xl` | 1536 / 1920px | Unchanged — extra width becomes outer margin, never a fourth region |

Touch targets on every embedded Approve/Reject/Acknowledge button and every bulk-selection `Checkbox` hold
the platform's 44×44px minimum with an 8px gap (`RESPONSIVE_DESIGN.md → Touch Targets`), material here
because the actions and their consequences are the same ones the Approval Center gates. A
swipe-to-mark-read gesture on mobile rows is a convenience alias for the same `PATCH` a tap on an explicit
control fires — it never adds a distinct code path.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md → RTL mirroring` and `LAYOUT_SYSTEM.md`'s RTL
contract; `NOTIFICATIONS.md → RTL & Localization` states the same rules and this section adds the
component-level application.

- **Logical properties only.** `NotificationsTabStrip`'s active-tab `border-b-2`, the category chips'
  `overflow-x-auto`, `NotificationRow`'s `ms-auto` timestamp, and the Preferences matrix's five channel
  columns all flip under `dir="rtl"` with zero screen-specific RTL code; the bell popover's `align="end"`
  flips to the visual left, exactly as `NAVIGATION_SYSTEM.md` already states.
- **Amounts, confidence percentages, and timestamps never mirror.** `AmountCell`/`ConfidenceBadge`/
  `FormattedRelativeTime` pin `dir="ltr"` — a KWD figure inside a fraud-hold notification reads
  left-to-right even in a fully Arabic inbox, with Western Arabic numerals (`numberingSystem: "latn"`).
- **A notification's `title`/`body` is a frozen snapshot, not a bilingual field the client picks between.**
  Per `ERD.md → notifications`'s Normalization note, `title`/`body` are rendered at the moment the template
  fired in whatever language the recipient's `locale` then was — so a user who reads QAYD in English today
  and switches to Arabic next month keeps every pre-switch notification in English forever; only
  post-switch notifications render in Arabic. `NotificationRow` renders `n.title`/`n.body` verbatim and
  never re-localizes them (`# Edge Cases`).
- **`reasoning` strings are never machine-translated by the frontend** — they arrive already localized from
  the API's content-negotiation contract; `NotificationRow`'s `ConfidenceBadge` renders `n.data.reasoning`
  as-received.
- **Category chip labels, the matrix's category/channel headers, and every empty-state copy are chrome, not
  content, and switch immediately** on a locale change (`# Edge Cases` #12 in the sibling doc) — a
  mixed-language Inbox (frozen content, live chrome) is a correct, expected state, not a bug.

| Context | English | Arabic |
|---|---|---|
| Category chip | Approvals Due | الموافقات المستحقة |
| Category chip | Fraud & Risk | الاحتيال والمخاطر |
| Bulk action | Mark read | تعليم كمقروء |
| Locked toggle tooltip | Security notifications can't be turned off | لا يمكن إيقاف تنبيهات الأمان |
| WhatsApp column | Coming soon | قريباً |
| Empty (all caught up) | You're all caught up | لقد أنجزت كل شيء |
| Empty (new account) | No notifications yet | لا توجد إشعارات بعد |
| Header count | {n} unread of {total} | {n} غير مقروء من {total} |

Arabic copy is authored directly by a fluent professional-register writer, never machine-translated,
matching `DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief.

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the
platform's `.dark` class remap defined once in `DESIGN_LANGUAGE.md`. The concrete pairs this screen's
components read most often:

| Token | Light | Dark | Used on this screen for |
|---|---|---|---|
| `--qayd-ink-1` / `--qayd-ink-12` | `#FAFAF9` / `#15130E` | `#14130F` / `#F8F6EF` | Feed canvas / unread-row title text |
| `--qayd-ink-3` / `--qayd-ink-4` | `#EBE9E6` / `#E0DEDA` | `#24211A` / `#2D2921` | Row hover; row dividers (`divide-ink-4`); `FeedSkeleton` base |
| `--qayd-accent` | `#9C7A34` | `#D9B96C` | Unread-row leading dot; active category-chip + tab border; the "View →"/"Review in Detected Risks →" link color |
| `--qayd-accent-subtle` | `#EADFBF` | `#3A2E14` | Active category-chip fill; `NewArrivalsBanner` background |
| `--qayd-negative` / `--qayd-warning` | `#B4232E` / `#B45309` | `#F26B74` / `#F5A855` | A `critical` fraud row's `AiCardShell` border tint; `StatusPill` "held" tone |
| `ink-300`/`ink-500` at reduced opacity | — | — | The matrix's locked-cell and "Coming soon" treatments, in both themes — never a dark-only muted color |

`AiCardShell`-wrapped rows use the same dark-mode-recalibrated accent/danger values `DARK_MODE.md`
specifies for AI-authored content ("Confidence indicators are re-tuned per theme, not linearly
brightened"), never a naive brightness flip. Elevation gets *lighter*, not darker, toward the viewer in
dark mode — the bell popover and the Inbox feed's rows sit a step lighter than the canvas behind them in
both themes, identical to how `DASHBOARD.md`'s `KpiTile` and `AI_COMMAND_CENTER.md`'s `AiCardShell` behave.
Every Storybook story for `NotificationRow` (one per category), `NotificationCategoryTabs`,
`NotificationBulkActionBar`, `NotificationPreferencesMatrix`, and `FeedSkeleton` ships the platform's
standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this route's
Playwright suite captures the same four-way set at the route level.

# Accessibility

Baseline WCAG 2.2 AA, identically in both languages and both themes, with particular weight on live
regions since a notification inbox is, by definition, the screen most likely to receive content while a
screen-reader user is mid-read of something else — restating `NOTIFICATIONS.md → Accessibility`'s rules as
the concrete implementation checklist.

- **Landmarks and headings.** The Inbox renders inside the shared `<main>` with one visible `<h1>`
  ("Notifications"); the Preferences matrix carries a real `VisuallyHidden` `<h2>` per category row (via
  the `<th scope="row">` in the source above) so a screen-reader heading list enumerates five distinct
  sections rather than one flat 25-cell grid.
- **Live regions, calibrated `aria-live="polite"` — never `"assertive"`.** A new row over the
  `private-company.{id}.notifications.{user_id}` channel announces politely; a push never yanks focus or
  splices a row into what a screen reader has already indexed — it surfaces as the same polite, dismissible
  `NewArrivalsBanner` ("1 new notification — Refresh") named in `# Data & State → Realtime`, matching
  `ACCESSIBILITY.md`'s "auto-detect, never alarming" three-tier model verbatim.
- **The unread count is announced as real text, not color.** `NotificationsBell`'s existing
  `aria-label={\`Notifications, ${unreadCount} unread\`}` is reused unchanged; the Inbox Page Header's
  "482 unread of 3,140" is a real text node in the heading region, not a silent badge.
- **Every AI-authored control explains itself.** `ConfidenceBadge`'s percentage and label are real text,
  never a bare progress-bar width; every Approve/Reject/Acknowledge button carries `aria-describedby`
  pointing at the row's own reasoning or requester text, so the "why" is available before the action.
- **RBAC-aware disabled controls explain themselves, and permission-absent ones are omitted.** A multi-step
  approval's Approve button disabled because it is not yet this user's turn carries a `Tooltip`-linked
  `aria-describedby` naming the reason; a role with no approval permission for that `kind` at all sees
  Reject omitted entirely — one of each on this screen, the exact distinction `ACCESSIBILITY.md` draws.
- **The Preferences matrix is a real, labeled grid.** Each `Switch` carries an `aria-label` combining row
  and column ("Fraud & Risk, SMS"); locked System cells carry `aria-disabled="true"` plus the same tooltip
  text as a visible `aria-describedby`, never a bare `disabled` with no stated reason.
- **Color is never the only channel.** Unread state is bold weight **plus** a dot **plus** (on the bell and
  the count header) real text; a `critical` fraud-hold row's pinned-to-top position and left-border color
  are paired with its `severity: "critical"` label rendered as real text inside the card.
- **Keyboard path.** `Tab` flows Page Header (Mark all read) → category chips → Filter Bar → each feed
  row's own controls (checkbox → title link → embedded action buttons) → Load more, with no control
  unreachable without a mouse; the Preferences matrix's roving order moves cell to cell in reading order —
  `Arrow` keys within the grid, `Tab` leaves it in one press, rather than 25 sequential `Tab` presses.

# Performance

- **Cursor pagination and virtualization, never a full-history client load.** `NotificationFeed`'s
  `useInfiniteQuery` pages via `getNextPageParam: (last) => last.meta.pagination.cursor` and switches to
  `@tanstack/react-virtual` past 200 loaded rows (`COMPONENT_LIBRARY.md`'s shared threshold) — a company
  with years of history (`ERD.md`'s 180-day retention implies exactly this scale) never holds an unbounded
  DOM list.
- **The popover stays capped at 20 and never virtualizes** — at that size a plain scrollable list is as
  fast as virtualization and simpler; the cap keeps the payload light regardless of history depth.
- **Realtime patches are the exception, not the rule.** Only the unread badge patches directly (`+1`);
  every other realtime-triggered update is an ordinary `invalidateQueries` that can never drift silently
  from the server — the discipline `DASHBOARD.md`'s Cash Position tile and the Approval Center both apply.
- **Preferences is code-split.** `NotificationPreferencesMatrix` and its `Switch`-heavy tree load via
  `next/dynamic` — a user opening the bell fifty times a day never pays for the Preferences bundle on any
  of those visits.
- **Debounced search.** The Inbox `q` input debounces at 300ms, matching every other searchable list.
- **Web Vitals.** LCP on `/notifications` is measured against the feed's first page of rows painting, not
  the shell; CLS stays near zero because `FeedSkeleton`'s per-row heights match each category's final
  layout (an approval row's skeleton is taller than a deadline row's) rather than one generic height.

# Edge Cases

`NOTIFICATIONS.md → Edge Cases` already covers the fourteen scenarios specific to the notification model
(stale `data.target`, an approval decided elsewhere mid-render, a security-critical delivery bypassing a
disabled preference, historical rows unaffected by a later preference change, WhatsApp pre-launch,
concurrent badge patches, mark-all-read racing a fresh arrival, retention-purge end-of-history, a signed-
out deep link, a company switch, cross-tab mark-all lag, a post-locale-switch mixed-language inbox, an
approval row for a role with no permission for that `kind`, and the narrowest role opening the screen) —
this document does not repeat them. The rows below are this implementation layer's own additions: races and
environment conditions that surface specifically once `NotificationFeed`, its mutation hooks, and its
optimistic-update logic are actually written.

| Edge case | Frontend behavior |
|---|---|
| A `useMarkRead` optimistic flip is applied to a row that is simultaneously invalidated by a realtime arrival on the same list key | `onMutate` cancels in-flight queries for `notificationKeys.all` before writing, and captures `previous`; the realtime `invalidateQueries` re-fetches on `onSettled` regardless, so the final state is the server's — the optimistic flip is never left stranded against a concurrent refetch |
| A row is checked for a bulk action, then a realtime `invalidate` re-fetches the list and that row's position (or presence) changes before the user clicks "Mark read" | Selection is tracked by `id` in a `Set`, not by list index, so `useBulkAction` submits the selected `ids` regardless of any re-ordering; an id that vanished from the current page (e.g. filtered out by a category change) is simply a no-op server-side, never an error |
| `useInfiniteQuery` is mid-`fetchNextPage` when the user changes the category chip (re-keying `notificationKeys.list`) | The in-flight next-page fetch resolves against the *old* query key and is discarded by TanStack Query's own key-change semantics; the new key starts a fresh first page from `initialPageParam: undefined` — a category switch never appends the old category's next page onto the new category's feed |
| The `NewArrivalsBanner` is showing "3 new" and the user scrolls to the top manually before clicking Refresh | Clicking Refresh (or the banner auto-clearing on the next successful `refetch`) reconciles the list to the server's actual head; the banner's count is a lightweight local tally of realtime events since the last fetch, not an authoritative number, so a manual scroll-to-top that predates the refetch simply shows the not-yet-merged rows until Refresh runs |
| A virtualized feed (>200 rows loaded) has a row whose embedded `ApprovalCard` opens its Reject `AlertDialog`, and the virtualizer would recycle that row's DOM node as it scrolls out of view | The open `AlertDialog` is portalled (Radix) outside the virtualized scroll container, so scrolling the feed never unmounts the dialog mid-interaction; its focus trap and the typed reason survive a scroll that recycles the underlying row |
| `mark-all-read` is clicked while the feed is filtered to a single category, and the user expects only that category cleared | `POST /notifications/mark-all-read` marks **every** currently-unread row for the caller, not just the filtered view — the button lives in the Page Header (screen-scoped), not the category row (category-scoped); the copy and its placement make "all" unambiguous, and the feed re-invalidates to show the now-read state across whatever filter is active |
| `useUpdatePreference`'s optimistic cell flip is applied, then the `PATCH` returns the full re-shaped grid which differs from the optimistic guess (e.g. the server also flipped a dependent cell) | `onSuccess` writes the server's returned full grid into `notificationKeys.preferences()`, superseding the optimistic single-cell guess — the matrix always ends on the server's authoritative shape, so a server-side dependency between cells (were one to exist) is reflected without the client modeling it |
| A `data.target` for an `ai_alert` row points at Ask AI (`"Explain more"`) but the AI chat surface is code-split and not yet loaded | The "Explain more" link triggers the same `next/dynamic` import Ask AI uses elsewhere; a brief inline pending state on the link (never a full-screen spinner) covers the chunk load, after which Ask AI opens pre-seeded with the notification's own `sources`, identical to `AI_COMMAND_CENTER.md`'s AI Insights feed |

# End of Document
