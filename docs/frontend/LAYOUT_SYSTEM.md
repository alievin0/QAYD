# Layout System — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: LAYOUT_SYSTEM
---

# Purpose

This document is the structural skeleton every QAYD screen is built from. Where `DESIGN_TOKENS`
(color, elevation semantics), `TYPOGRAPHY`, and `COMPONENT_LIBRARY` define *what things look like*,
`LAYOUT_SYSTEM` defines *where things sit and how the frame behaves*: the 12-column grid, the
persistent app shell (sidebar/topbar/content/AI rail), the five reusable page templates every screen
maps onto, the spacing scale that produces vertical rhythm, the Comfortable/Compact density modes
finance tables require, container/card composition rules, responsive breakpoint behavior, scroll and
sticky mechanics, and — because QAYD ships English and Arabic as first-class, not translated-after,
languages — the exact rules for mirroring layout under `dir="rtl"` without breaking numeral or chart
legibility.

QAYD's design taste is explicit and non-negotiable: near-monochrome ink, one disciplined accent,
generous whitespace, restrained radii, hairline borders over heavy shadows, calm micro-motion. A layout
system is where that taste either survives contact with forty screens of real financial data or
collapses into generic-SaaS clutter. Every rule below exists to keep forty screens feeling like one
product — built by Vercel/Linear/Stripe-caliber engineers, reviewed the way a car or fashion brand
reviews a showroom floor plan, not the way a dashboard template is skinned.

Three properties this document optimizes for, in order:

1. **Consistency.** A `PageHeader`, a `Card`, a sticky table header, a 24px section gap behave
   identically whether the screen is Journal Entries or the AI Command Center. Screens differ in
   content, never in frame.
2. **Density without clutter.** Finance software lives or dies on how much correct information fits
   on one screen. Every spacing and density decision here is calibrated for accountants scanning
   hundreds of rows, not for a marketing site's breathing room — while still respecting the
   editorial-calm mandate (density comes from tighter rhythm, never from smaller type or removed
   whitespace between unrelated groups).
3. **LTR/RTL and light/dark parity.** No screen may look "translated." Arabic is a native reading
   mode of the same layout, not a mirrored afterthought; dark mode is a token swap, not a second
   implementation.

All CSS in this document uses **logical properties** (`margin-inline-start`, `padding-inline`,
`inset-inline-end`, `text-align: start`) and Tailwind's logical utilities (`ms-*`, `me-*`, `ps-*`,
`pe-*`, `start-*`, `end-*`, `text-start`, `text-end`) instead of physical ones (`ml-*`, `pr-*`,
`left-*`, `text-left`). This single discipline is what makes the RTL section below possible; it is
enforced by an ESLint rule (`eslint-plugin-tailwindcss` custom restriction list, see `# RTL Layout
Mirroring`) that fails the build on any physical-direction utility class outside the two documented
exceptions (numeric alignment, debit/credit column order).

# Grid System

QAYD uses a 12-column grid for content composition, layered inside a fixed macro grid for the app
shell (sidebar/topbar/content — see `# App Shell Layout`). The two grids are independent: the shell
grid never has 12 columns, the content grid never contains the sidebar. Screens compose the 12-column
grid *inside* the content region only.

## Breakpoints

QAYD extends Tailwind's default scale with one additional tier for the ultra-wide monitors common on
Finance/Treasury desks (32"+ displays used for Trial Balance / reconciliation work):

| Token | Min width | Typical device | Notes |
|---|---|---|---|
| `base` | `0px` | Small phone | Single column, bottom-nav shell |
| `sm` | `640px` | Large phone / phablet | Still single column; forms go full width |
| `md` | `768px` | Tablet portrait | Sidebar becomes an overlay drawer, not inline |
| `lg` | `1024px` | Tablet landscape / small laptop | Sidebar goes inline (collapsed by default) |
| `xl` | `1280px` | Laptop | Sidebar inline, expanded by default; 12-col grid activates fully |
| `2xl` | `1536px` | Desktop monitor | Right AI rail can sit inline instead of as an overlay sheet |
| `3xl` (custom) | `1920px` | Ultra-wide / 4K desk monitor | Content container width caps; extra column reserved for AI rail + margin, never for stretching tables edge-to-edge |

Defined once as Tailwind v4 theme tokens (`app/globals.css`), consumed everywhere else as `@theme`
values rather than re-declared per component:

```css
/* app/globals.css */
@theme {
  --breakpoint-3xl: 120rem; /* 1920px */

  --container-max: 90rem;       /* 1440px — reading/content container ceiling */
  --container-max-wide: 100rem; /* 1600px — dashboard/report full-bleed ceiling on 3xl */

  --grid-margin-base: 1rem;     /* 16px, <sm */
  --grid-margin-sm: 1.5rem;     /* 24px, sm–md */
  --grid-margin-md: 2rem;       /* 32px, md–xl */
  --grid-margin-lg: 2rem;       /* 32px, xl+ (margin stays, container caps instead) */

  --grid-gutter-base: 1rem;     /* 16px, <md */
  --grid-gutter-md: 1.5rem;     /* 24px, md–xl */
  --grid-gutter-xl: 2rem;       /* 32px, xl+ */
}
```

## Container widths & gutters

| Breakpoint | Outer margin (inline) | Column gutter | Container behavior |
|---|---|---|---|
| base–sm | 16px | 16px | Fluid 100%, no cap |
| md | 32px | 24px | Fluid 100%, no cap |
| lg | 32px | 24px | Fluid 100%, no cap |
| xl | 32px | 32px | Caps at `--container-max` (1440px) for **reading** templates (form pages, detail pages); dashboards/reports stay fluid |
| 2xl | 32px | 32px | Same cap; extra space becomes side margin, centered |
| 3xl | 32px | 32px | Reading templates cap at 1440px; full-bleed templates (dashboard, report canvas) cap at `--container-max-wide` (1600px) — never truly edge-to-edge, per Edge Cases |

The distinction between a **reading container** (caps at 1440px so line lengths and form widths stay
scannable) and a **full-bleed container** (caps at 1600px, used only for `DashboardGrid` and
`ReportPageTemplate` canvases so multi-column financial tables get real room) is a deliberate,
per-template choice — not a global setting. `Container` takes a `variant` prop for this:

```tsx
// components/layout/Container.tsx
import { cn } from "@/lib/utils";

type ContainerProps = React.ComponentProps<"div"> & {
  variant?: "reading" | "full-bleed";
};

export function Container({ variant = "reading", className, ...props }: ContainerProps) {
  return (
    <div
      className={cn(
        "mx-auto w-full px-4 sm:px-6 md:px-8",
        variant === "reading" ? "max-w-(--container-max)" : "max-w-(--container-max-wide)",
        className,
      )}
      {...props}
    />
  );
}
```

## The 12-column content grid

Inside a `Container`, screens lay out with CSS Grid's native `grid-cols-12`, never with ad-hoc
percentage widths:

```tsx
<Container variant="reading">
  <div className="grid grid-cols-12 gap-x-4 md:gap-x-6 xl:gap-x-8">
    <section className="col-span-12 lg:col-span-8">{/* main column */}</section>
    <aside className="col-span-12 lg:col-span-4">{/* summary rail */}</aside>
  </div>
</Container>
```

Standard column splits used across QAYD templates (documented so every screen reuses the same three
ratios instead of inventing new ones):

| Split | Usage |
|---|---|
| `12 / 12` | List pages, report canvases, dashboard full-width widgets |
| `8 / 4` | Detail pages (main content / summary-and-activity rail), form pages with a live preview rail |
| `9 / 3` | Form pages with a slim contextual-help rail instead of a preview |
| `4 / 4 / 4` or `3×4` | Dashboard KPI strip (3 or 4 equal stat cards) |

Dashboards additionally use CSS Grid **template areas** for their bento layout, because KPI/chart/
insight widgets have unequal, content-driven heights that a strict 12-column row would force into
awkward equal-height cells:

```css
/* DashboardGrid.module.css — illustrative; in practice expressed as Tailwind arbitrary values */
.dashboard {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  grid-template-rows: auto auto auto;
  gap: var(--space-6);
  grid-template-areas:
    "kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi"
    "chart chart chart chart chart chart chart chart insights insights insights insights"
    "cash  cash  cash  cash  ai    ai    ai    ai    ai    ai    ai    ai";
}
```

## Grid debug overlay

Because a 12-column grid is only as good as engineers' ability to verify against it, every non-
production environment ships a togglable overlay (bound to `Cmd/Ctrl+Shift+G`, gated behind
`NODE_ENV !== "production"`):

```tsx
// components/dev/GridDebugOverlay.tsx
"use client";
import { useEffect, useState } from "react";

export function GridDebugOverlay() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.shiftKey && (e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "g") {
        setVisible((v) => !v);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  if (!visible) return null;
  return (
    <div className="pointer-events-none fixed inset-0 z-(--z-tooltip) mx-auto grid max-w-(--container-max) grid-cols-12 gap-x-4 px-4 sm:px-6 md:px-8 md:gap-x-6 xl:gap-x-8">
      {Array.from({ length: 12 }).map((_, i) => (
        <div key={i} className="h-full bg-[color-mix(in_oklab,var(--accent)_8%,transparent)]" />
      ))}
    </div>
  );
}
```

# App Shell Layout

The app shell is the persistent frame every authenticated screen renders inside
(`app/(app)/layout.tsx`). It has four regions — **Sidebar**, **Topbar**, **Content**, and an optional
**AI Rail** — plus two overlay layers (**Command Palette**, **Sheets/Modals/Toasts**) that render above
all four via React portals.

## Region dimensions

| Region | Expanded | Collapsed / alternate state | Behavior |
|---|---|---|---|
| Sidebar | `272px` | `72px` (icon rail) or `0` (mobile, becomes overlay drawer `288px`) | Own scroll container; full viewport height; fixed, never scrolls with content |
| Topbar | `64px` height | `56px` on `base`–`sm` | Sticky to top of the content column only (not full-bleed over sidebar) |
| Content | `1fr` (remaining space) | — | Own scroll container; hosts the active Page Template |
| AI Rail | `360px` | `0` (becomes a Radix `Sheet` overlay below `2xl`) | Own scroll container; toggled per-screen, persisted in Zustand |

## Shell composition (CSS Grid)

```css
/* app-shell.css */
.app-shell {
  display: grid;
  grid-template-columns: var(--sidebar-w, 272px) minmax(0, 1fr);
  height: 100dvh;
  overflow: hidden; /* regions own their own scroll; the shell itself never scrolls */
}

.app-shell[data-sidebar="collapsed"] { --sidebar-w: 72px; }
.app-shell[data-sidebar="hidden"]    { --sidebar-w: 0px; }

.app-shell__sidebar {
  grid-column: 1;
  height: 100dvh;
  overflow-y: auto;
  border-inline-end: 1px solid var(--border-subtle);
}

.app-shell__main {
  grid-column: 2;
  display: grid;
  grid-template-rows: var(--topbar-h, 64px) minmax(0, 1fr);
  min-width: 0; /* prevents table content from blowing out the grid track */
}

.app-shell__topbar {
  grid-row: 1;
  position: sticky;
  top: 0;
  z-index: var(--z-shell-topbar);
  border-block-end: 1px solid var(--border-subtle);
  backdrop-filter: blur(8px); /* frosted, matches marketing-site "silk" surface language, restrained */
}

.app-shell__content {
  grid-row: 2;
  overflow-y: auto;
  min-height: 0;
}
```

In `dir="rtl"`, `grid-template-columns` order is left untouched — the browser's `writing-mode`/`dir`
handling flips which physical side column 1 renders on automatically because the grid is defined with
no explicit `direction: ltr` override anywhere in the shell. Column 1 is the **start** edge (right in
RTL, left in LTR) by definition; see `# RTL Layout Mirroring` for the full mirroring contract.

## Wireframe — desktop, `xl`+, AI Rail open

```
┌────────────┬──────────────────────────────────────────────────┬─────────────┐
│            │ Topbar: ‹breadcrumb›   [⌘K search]  AI● 🔔  ▾User │             │
│  Sidebar   ├──────────────────────────────────────────────────┤   AI Rail   │
│ (own       │                                                    │  (own      │
│  scroll)   │                Content (own scroll)                │   scroll)  │
│            │        renders the active Page Template            │            │
│ ┌────────┐ │                                                    │  Insight   │
│ │ Org ▾  │ │                                                    │  cards,    │
│ └────────┘ │                                                    │  proposal  │
│  Dashboard │                                                    │  queue     │
│  Accounting│                                                    │            │
│  ▸ Journal │                                                    │            │
│  ▸ Ledger  │                                                    │            │
│  Banking   │                                                    │            │
│  Inventory │                                                    │            │
│  Payroll   │                                                    │            │
│  Tax       │                                                    │            │
│  Reports   │                                                    │            │
│ ┌────────┐ │                                                    │            │
│ │ Ali ▾  │ │                                                    │            │
│ └────────┘ │                                                    │            │
└────────────┴──────────────────────────────────────────────────┴─────────────┘
```

## Wireframe — mobile, `base`–`sm`

```
┌───────────────────────────────┐
│ ☰   QAYD          🔔  ▾        │  ← Topbar, 56px, sidebar collapses to hamburger
├───────────────────────────────┤
│                                 │
│        Content (full width)    │
│      Page Template renders     │
│      full-bleed, single col    │
│                                 │
├───────────────────────────────┤
│  🏠     📒     🏦     🔍     ⋯  │  ← bottom tab bar replaces sidebar entirely
└───────────────────────────────┘
```

Below `md`, Sidebar is not a narrow rail — it is fully hidden and replaced by (a) a bottom tab bar for
the five most-used top-level destinations (Dashboard, Accounting, Banking, Search, More) and (b) the
hamburger in the Topbar opens the full sidebar as a `Sheet` overlay (Radix Dialog, slide from the
start edge) for the long tail of navigation (Inventory, Payroll, Tax, Settings). This mirrors how
Linear and Stripe Dashboard degrade on mobile: primary nav becomes thumb-reachable, secondary nav
becomes an overlay rather than a second layout system.

## Z-index scale

One scale, defined once, referenced everywhere — no component invents its own z-index:

```css
@theme {
  --z-base: 0;
  --z-sticky: 10;          /* sticky table headers/columns, sticky filter bar, inside content scroll */
  --z-shell-sidebar: 20;
  --z-shell-topbar: 20;
  --z-dropdown: 30;        /* Radix DropdownMenu, Select, Popover, Combobox */
  --z-drawer-scrim: 40;
  --z-drawer: 41;          /* AI Rail overlay mode, mobile Sidebar Sheet, right-side detail Sheets */
  --z-modal-scrim: 50;
  --z-modal: 51;           /* Dialog: create/edit forms opened as modals, confirmation dialogs */
  --z-command-palette: 60; /* ⌘K — must sit above any open modal */
  --z-toast: 70;           /* Sonner toast stack — must sit above everything except... */
  --z-tooltip: 80;         /* ...tooltips, which must never be occluded by anything */
}
```

## Shell components (signatures)

```tsx
// components/layout/AppShell.tsx
type AppShellProps = {
  children: React.ReactNode;
};
export function AppShell({ children }: AppShellProps) { /* renders grid, Sidebar, Topbar, children, AI Rail */ }

// components/layout/Sidebar.tsx
type SidebarState = "expanded" | "collapsed" | "hidden";
export function Sidebar({ state, onStateChange }: {
  state: SidebarState;
  onStateChange: (next: SidebarState) => void;
}) { /* nav groups filtered by RBAC permission keys, see Edge Cases for empty-section collapse */ }

// components/layout/Topbar.tsx
export function Topbar({ breadcrumb }: { breadcrumb: BreadcrumbItem[] }) {
  /* renders breadcrumb (from route segment config), CommandPaletteTrigger, AiStatusIndicator
     (subscribes to Reverb channel `private-company.{id}.ai`), NotificationsBell, UserMenu */
}

// components/layout/AiRail.tsx
export function AiRail({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolen) => void }) {
  /* below 2xl renders as Radix Sheet (side="end"); at 2xl+ renders inline in the shell grid */
}
```

Sidebar and AI Rail state persist per-user via a Zustand store (`useShellStore`) with `localStorage`
persistence for instant paint on reload, reconciled against the server-side user preference
(`PATCH /api/v1/users/me/preferences`) on change so the state survives across devices. The keyboard
shortcut `Cmd/Ctrl+B` toggles Sidebar between expanded/collapsed at `lg`+; there is no keyboard shortcut
to fully hide it at `lg`+ — only mobile hides it completely, by breakpoint, not by user choice.

The Command Palette (`⌘K`) is not part of the shell grid — it is a `Dialog` rendered at the document
root via portal, `z-(--z-command-palette)`, available from any screen, and is how QAYD keeps the
Sidebar's information density low: rarely-used destinations and actions (e.g. "Reverse journal entry
#88231", "Export Trial Balance as PDF") are reachable by search rather than by growing the nav tree.

# Page Templates

Every screen in QAYD is an instance of exactly one of five page templates. A screen never invents a
sixth layout shape; if a new screen doesn't fit, the template is extended (a new named slot), not
bypassed. Templates are implemented as layout components in `components/templates/`, not as
per-route copy-paste — a route's `page.tsx` is a thin server component that fetches/streams data and
hands it to the template.

## List Page Template

**Purpose:** browse, filter, and bulk-act on a collection (Journal Entries, Invoices, Bills, Customers,
Vendors, Products, Bank Transactions).

| Region | Content |
|---|---|
| Page Header | Title, record count badge, primary action button (e.g. "New Journal Entry"), secondary actions menu (Import, Export) |
| Filter Bar | Search input, saved-filter chips, filter popover (status, date range, amount range, account), density toggle, column-visibility menu |
| Bulk Action Bar | Appears only when ≥1 row selected; replaces Filter Bar's right side with "3 selected · Approve · Export · Clear" |
| Data Region | `DataTable` (TanStack Table + TanStack Virtual for >200 rows) |
| Pagination Footer | Cursor pagination controls, page-size selector, "Showing 1–25 of 1,842" |

```
┌─────────────────────────────────────────────────────────────────┐
│ Journal Entries                                    [+ New Entry] │
│ 1,842 entries                              [Import ▾] [Export ▾] │
├─────────────────────────────────────────────────────────────────┤
│ [Search…]  [Status ▾][Period ▾][Account ▾]      [▤ Density][⚙] │
├─────────────────────────────────────────────────────────────────┤
│ ☐ │ Date     │ Ref     │ Description        │ Debit │ Credit │ ⋯│
│ ☐ │ Jul 14   │ JE-1091 │ AR write-off        │ 1,200 │      – │ ⋯│
│ ☐ │ Jul 14   │ JE-1090 │ Payroll accrual     │     – │ 44,020 │ ⋯│
│ … │ …        │ …       │ …                   │ …     │ …      │ ⋯│
├─────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 1,842                    [‹ Prev]  [Next ›]     │
└─────────────────────────────────────────────────────────────────┘
```

Route: `app/(app)/accounting/journal-entries/page.tsx`. Reads `accounting.journal.read`; primary
action gated on `accounting.journal.create`; bulk-export gated on `accounting.journal.export`.

## Detail Page Template

**Purpose:** the full record view for one entity (a Journal Entry, an Invoice, a Bank Reconciliation),
with its lifecycle status, actions, and history.

| Region | Content |
|---|---|
| Page Header | Back-to-list link, entity title/number, status badge (draft/submitted/approved/posted), primary lifecycle action (Approve/Post/Reverse), overflow menu |
| Tab/Segment Nav | Only when the entity has sub-views (e.g. Invoice: Details / Payments / Activity) |
| Main Column (8/12) | The record's core data — journal lines table, invoice line items, reconciliation match grid |
| Summary Rail (4/12) | Key facts (amounts, dates, linked entities), AI reasoning card if AI-drafted, approval-chain progress |
| Activity Timeline | Chronological audit trail — always at the bottom of the main column, never in the rail (it's a first-class content region, not a footnote) |

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Journal Entries    JE-1091          [Posted ✓]   │  Summary       │
│                                    [Reverse ▾][⋯]  │  ─────────     │
├─────────────────────────────────────────────────────  Total 45,220 │
│  Account          │ Debit   │ Credit  │ Memo         │  Period Jul '26│
│  1200 · AR         │ 1,200   │    –    │ write-off    │  Created by AI │
│  6400 · Bad Debt   │    –    │ 1,200   │ write-off    │  Confidence 92%│
├─────────────────────────────────────────────────────┤  [View reasoning]│
│  Activity                                            ├───────────────┤
│  ● Posted by S. Rahman — Jul 14, 09:02               │               │
│  ● Approved by Finance Manager — Jul 14, 08:58        │               │
│  ● AI drafted from vendor bill anomaly — Jul 13       │               │
└───────────────────────────────────────────────────┴───────────────┘
```

Route: `app/(app)/accounting/journal-entries/[id]/page.tsx`. Reads `accounting.journal.read`; the
lifecycle action button swaps between `approve`/`post`/`reverse` based on entry `status` and the
viewer's granted permission for that transition — never shown disabled-and-greyed when the permission
is simply absent (removed from the DOM entirely, per RBAC convention in `COMPONENT_LIBRARY`).

## Form Page Template

**Purpose:** create or edit a record (New Journal Entry, New Invoice, Edit Vendor).

| Region | Content |
|---|---|
| Page Header | Title ("New Journal Entry"), Cancel (secondary), Save Draft (secondary), Submit (primary) — the last two duplicated in the Sticky Footer once the header scrolls out of view |
| Form Body | Sectioned `Card` groups (React Hook Form + Zod), each section a discrete concern: Header Info → Lines → Attachments → Notes |
| Live Preview / Help Rail (optional, 3–4/12) | Running debit/credit balance indicator, AI suggestion inline ("This looks like a recurring accrual — apply template?"), field-level help |
| Sticky Footer Action Bar | Reappears once the header actions scroll out of the viewport; never both are visible with duplicate focus targets — the header's buttons get `aria-hidden` while the footer bar is in view |

```
┌───────────────────────────────────────────┬─────────────┐
│ New Journal Entry     [Cancel][Save Draft][Submit] │Live balance│
├───────────────────────────────────────────┤ Dr 1,200   │
│  Header Info                               │ Cr 1,200   │
│  Date [__]  Period [Jul 2026 ▾]  Ref [__]  │ Balanced ✓ │
├───────────────────────────────────────────┤             │
│  Lines                                      │ AI suggests │
│  Account ▾  Debit  Credit  Memo   [+ Row]  │ template:   │
├───────────────────────────────────────────┤ "AR write-  │
│  Attachments                    [Upload]   │  off" 92%   │
├───────────────────────────────────────────┤             │
├─── sticky footer, appears on scroll ──────┤             │
│      [Cancel]   [Save Draft]   [Submit]    │             │
└───────────────────────────────────────────┴─────────────┘
```

Route: `app/(app)/accounting/journal-entries/new/page.tsx` and
`app/(app)/accounting/journal-entries/[id]/edit/page.tsx`. Submit gated on
`accounting.journal.create`/`accounting.journal.update`; the AI suggestion rail only renders if the
company has AI drafting enabled and never auto-fills without an explicit "Apply" click (no silent
autocomplete of financial values — see `AI_INTEGRATION` conventions).

## Dashboard Template

**Purpose:** at-a-glance company state (Home Dashboard, AI Command Center).

| Region | Content |
|---|---|
| KPI Strip | 3–4 equal stat cards (Cash Position, AR Aging, AP Due, MTD Revenue), full width |
| Chart Region | 8/12 — trend charts (cash flow, revenue vs. expense) |
| Insights Feed | 4/12 — AI findings/proposals queue, scrollable independently |
| Secondary Widgets | Lower bento row — recent activity, upcoming payables, reconciliation status |

```
┌────────────────────────────────────────────────────────────┐
│ [Cash: 128,400 KWD] [AR: 44,020] [AP: 19,880] [MTD Rev: +12%]│
├───────────────────────────────────────┬─────────────────────┤
│                                         │ AI Insights          │
│         Cash Flow (90d) chart          │ ● 3 anomalies found  │
│                                         │ ● Draft: accrual JE  │
│                                         │ ● Vendor bill flagged│
├─────────────────────┬───────────────────┴─────────────────────┤
│ Recent Activity      │ Upcoming Payables                       │
└─────────────────────┴─────────────────────────────────────────┘
```

Route: `app/(app)/dashboard/page.tsx` and `app/(app)/ai/command-center/page.tsx`. Widgets are
permission-filtered independently (a Sales Employee's dashboard never renders the Cash Position KPI
card even as a skeleton — see `# Edge Cases`).

## Report Page Template

**Purpose:** generate, review, and export a formal financial report (Trial Balance, Balance Sheet,
P&L, Cash Flow Statement).

| Region | Content |
|---|---|
| Report Header | Report name, as-of/period selector, comparison-period toggle, status badge (draft/reviewed/approved/archived), Generate/Refresh action, Export menu |
| Report Canvas | The statement itself — a dense, print-faithful table/section layout, full width, no AI rail competing for space by default |
| Drill-down Panel | Slides in from the end edge (`Sheet`) when a line is clicked — shows contributing journal lines, never navigates away from the report |
| Findings Bar | Collapsible strip above the canvas listing AI-flagged variances/imbalances, dismissible per finding |

```
┌───────────────────────────────────────────────────────────┐
│ Trial Balance — Jul 2026        [Approved]   [Generate ▾]  │
│ Unadjusted ▾   vs. Jun 2026 ▾                  [Export ▾]  │
├───────────────────────────────────────────────────────────┤
│ ⚠ 2 findings: rounding variance, dormant account activity ▾│
├───────────────────────────────────────────────────────────┤
│ Account              │ Debit      │ Credit     │           │
│ 1000 · Cash           │ 128,400.000│          – │           │
│ 1200 · AR             │  44,020.500│          – │           │
│ …                     │            │            │           │
│ TOTAL                 │ 512,880.220│ 512,880.220│  ✓ balanced│
└───────────────────────────────────────────────────────────┘
```

Route: `app/(app)/accounting/reports/trial-balance/page.tsx`. Reads
`accounting.trial_balance.read`; Generate gated on `accounting.trial_balance.generate`; Export gated
on `accounting.trial_balance.export` — exactly the permission keys defined in `TRIAL_BALANCE.md`,
reused verbatim rather than re-invented at the frontend layer.

# Spacing Scale & Rhythm

QAYD's spacing scale is a 4px base unit, expressed in `rem` (never raw `px` in component code, so the
whole layout reflows correctly under OS/browser text-size scaling up to 200% — see `# Edge Cases`).

```css
@theme {
  --space-0: 0px;
  --space-1: 0.25rem;  /* 4px  — icon-to-label gap, tight inline spacing */
  --space-2: 0.5rem;   /* 8px  — compact-density cell padding, chip padding */
  --space-3: 0.75rem;  /* 12px — compact-density row padding, input vertical padding */
  --space-4: 1rem;     /* 16px — default component padding, form field gap */
  --space-5: 1.25rem;  /* 20px — comfortable-density cell padding */
  --space-6: 1.5rem;   /* 24px — card padding (desktop), grid gutter (md+) */
  --space-8: 2rem;     /* 32px — section gap within a page, grid gutter (xl+) */
  --space-10: 2.5rem;  /* 40px — gap between distinct form sections */
  --space-12: 3rem;    /* 48px — gap between major page regions */
  --space-16: 4rem;    /* 64px — gap above/below a page's outermost content, marketing-adjacent screens only */
  --space-20: 5rem;    /* 80px — rarely used; empty-state vertical centering offset */
  --space-24: 6rem;    /* 96px — rarely used; large empty-state illustrations */
}
```

## Usage guidance

| Token | Use for |
|---|---|
| `--space-1` (4px) | Icon–label gap, badge internal padding, tight inline groups |
| `--space-2` (8px) | Compact table cell padding-block, tag/chip padding, `gap` between inline buttons |
| `--space-3` (12px) | Compact table cell padding-inline, input padding-block, small `Card` padding on mobile |
| `--space-4` (16px) | Default control padding, gap between form fields within a section, mobile page margin |
| `--space-5` (20px) | Comfortable table cell padding-block |
| `--space-6` (24px) | `Card` padding (desktop), gap between cards in a grid, grid gutter at md+ |
| `--space-8` (32px) | Gap between page regions (Filter Bar → Data Region), grid gutter at xl+, page margin at xl+ |
| `--space-10` (40px) | Gap between form sections (Header Info → Lines → Attachments) |
| `--space-12` (48px) | Gap between a Page Header and the first content region |
| `--space-16`+ | Empty states, onboarding screens — never inside dense data screens |

## Vertical rhythm rules

- **Page Header → first region:** always `--space-6` (24px), regardless of template.
- **Between sibling regions on the same page** (Filter Bar → Data Region, KPI Strip → Chart Region):
  `--space-6` (24px) desktop, `--space-4` (16px) mobile.
- **Between form sections:** `--space-10` (40px) — visually louder than the 24px used between
  unrelated page regions, because form sections are read top-to-bottom as one continuous task while
  page regions are scanned independently.
- **Within a section** (label → input, input → helper text): `--space-1` to `--space-2` (4–8px) — the
  tightest gaps in the scale, because these pairs must read as one unit.
- **Table row height** is governed by Density Mode (`# Density Modes`), not by this scale directly —
  but the *padding inside* a row uses `--space-2`/`--space-3` (compact) or `--space-4`/`--space-5`
  (comfortable), so density modes are implemented as different token values, not a parallel spacing
  system.
- **Baseline text rhythm:** body text uses a 1.5 line-height against a 4px grid, so every line of
  14px/16px text lands on an 8px multiple (`14px × 1.5 = 21px`, rounded to the nearest 4px step by the
  type scale defined in `TYPOGRAPHY`) — this document does not own type tokens, but layout spacing
  above is chosen to stay compatible with that baseline rather than fight it.

# Density Modes

Finance tables are the one place QAYD deliberately trades whitespace for row count — but never by
shrinking type. **Density changes padding and row height only; font-size is constant across
Comfortable and Compact.** This is a hard rule, not a default: shrinking financial figures to fit more
rows is how mis-keyed decimals get missed, and it contradicts the strong-typographic-hierarchy mandate
this whole system is built on.

## Tokens

```css
@theme {
  --row-h-comfortable: 3rem;      /* 48px */
  --row-h-compact: 2rem;          /* 32px */
  --row-h-header-comfortable: 3.25rem; /* 52px, sticky header slightly taller than body rows */
  --row-h-header-compact: 2.25rem;     /* 36px */

  --cell-py-comfortable: 0.75rem; /* 12px */
  --cell-py-compact: 0.375rem;    /* 6px */
  --cell-px-comfortable: 1rem;    /* 16px */
  --cell-px-compact: 0.75rem;     /* 12px */
}
```

| | Comfortable | Compact |
|---|---|---|
| Row height | 48px | 32px |
| Header row height | 52px | 36px |
| Cell padding-block | 12px | 6px |
| Cell padding-inline | 16px | 12px |
| Font size | 14px (unchanged) | 14px (unchanged) |
| Minimum interactive target | 48px (row itself) | 32px row + 44px touch-equivalent hit-slop on row-level actions via `::before` expansion, satisfying WCAG 2.2 SC 2.5.8 (24×24 minimum, exceeded) |

## Where density applies

Density is a **table-level** setting, not a global theme setting — a user can run Journal Entries in
Compact and Customers in Comfortable simultaneously, because the right density depends on how many
columns and how repetitive the rows are, which varies by screen. It is exposed as a segmented control
(`Comfortable | Compact`, icon-only) in the Filter Bar of every List Page Template and in the toolbar
of any embedded table (e.g. the Journal Lines table on a Detail/Form page).

State lives in a Zustand slice keyed by table identity, persisted both locally and server-side:

```ts
// stores/useDensityStore.ts
type Density = "comfortable" | "compact";

type DensityState = {
  byTable: Record<string, Density>;
  defaultDensity: Density;
  setDensity: (tableKey: string, density: Density) => void;
};

export const useDensityStore = create<DensityState>()(
  persist(
    (set) => ({
      byTable: {},
      defaultDensity: "comfortable",
      setDensity: (tableKey, density) => {
        set((s) => ({ byTable: { ...s.byTable, [tableKey]: density } }));
        // fire-and-forget sync; TanStack Query mutation, optimistic, no loading UI
        api.patch("/api/v1/users/me/preferences", { table_density: { [tableKey]: density } });
      },
    }),
    { name: "qayd-density" },
  ),
);
```

Row-height tokens feed directly into `TanStack Virtual`'s `estimateSize` for any table over ~200 rows
(Journal Entries, Ledger Entries, Bank Transactions routinely run into the tens of thousands), so
switching density live must call the virtualizer's `measure()`/re-estimate path rather than only
changing CSS — a pure-CSS density switch would desync virtualized row positions from actual rendered
height and cause scroll-jitter. This is implemented once in the shared `DataTable` component, never
per-screen.

Compact is the default for: Journal Entries, Ledger Entries, Bank Transactions, Stock Movements (high
row-count, homogeneous-row screens). Comfortable is the default for: Customers, Vendors, Products,
Invoices (lower row-count, more varied per-row content, more likely to be scanned rather than
audited-line-by-line). The default is a per-table constant, overridden per-user the moment they touch
the toggle once.

# Containers & Cards

## Container

`Container` (defined in `# Grid System`) is the only component permitted to set outer page margin and
max-width. No page template, card, or feature component sets its own `max-width` or `mx-auto` — doing
so is a review-blocking violation, because it is exactly how two screens silently drift out of
alignment with each other over time.

## Card anatomy

`Card` is the base surface for grouping related content — a form section, a KPI stat, an AI insight,
a report finding. It is a composed `shadcn/ui` primitive with three optional slots:

```tsx
// components/ui/card.tsx (shadcn/ui base, QAYD tokens applied)
<Card>
  <CardHeader>
    <CardTitle>Journal Lines</CardTitle>
    <CardDescription>4 lines · balanced</CardDescription>
    <CardAction><Button variant="ghost" size="sm">Add line</Button></CardAction>
  </CardHeader>
  <CardContent>{/* table, form fields, chart */}</CardContent>
  <CardFooter>{/* pagination, secondary actions, timestamp */}</CardFooter>
</Card>
```

## Surface tokens

Restrained by design — QAYD's taste explicitly rejects heavy shadows and oversized radii as
"generic SaaS." Borders do the separating; shadow is reserved for genuinely elevated, temporary
surfaces (menus, popovers, modals), never for static page content.

```css
@theme {
  --radius-xs: 0.25rem;   /* 4px  — tags, chips, small badges */
  --radius-sm: 0.375rem;  /* 6px  — inputs, buttons */
  --radius-md: 0.5rem;    /* 8px  — Card, Popover, Select content */
  --radius-lg: 0.75rem;   /* 12px — Dialog, Sheet panels */
  --radius-full: 9999px;  /* avatars, status dots, pill badges — used sparingly, never for buttons */

  --border-subtle: color-mix(in oklab, var(--ink) 10%, transparent);
  --border-default: color-mix(in oklab, var(--ink) 16%, transparent);

  --shadow-none: none;
  --shadow-sm: 0 1px 2px color-mix(in oklab, var(--ink) 6%, transparent);   /* dropdown, popover */
  --shadow-md: 0 4px 16px color-mix(in oklab, var(--ink) 10%, transparent); /* modal, sheet */
}
```

| Surface | Border | Shadow | Radius |
|---|---|---|---|
| Static `Card` on a page | `--border-subtle`, 1px | none | `--radius-md` |
| Interactive `Card` (hoverable, clickable row-as-card on mobile) | `--border-subtle` → `--border-default` on hover | none | `--radius-md` |
| `DropdownMenu` / `Popover` / `Select` content | `--border-subtle` | `--shadow-sm` | `--radius-md` |
| `Dialog` / `Sheet` panel | none (scrim provides separation) | `--shadow-md` | `--radius-lg` |
| AI insight card | `--border-subtle` + 2px accent `border-inline-start` (the one place a colored border is standard, marking "AI-originated" content at a glance) | none | `--radius-md` |
| Danger/destructive confirmation card | `--border-subtle` + 2px `border-inline-start` in the danger token | none | `--radius-md` |

## Card variants

```tsx
type CardVariant = "default" | "interactive" | "ai-insight" | "danger";

// components/ui/card.tsx — variant applied via cva()
const cardVariants = cva(
  "rounded-(--radius-md) border border-(--border-subtle) bg-(--surface)",
  {
    variants: {
      variant: {
        default: "",
        interactive: "transition-colors hover:border-(--border-default) cursor-pointer",
        "ai-insight": "border-s-2 border-s-(--accent)",
        danger: "border-s-2 border-s-(--danger)",
      },
    },
    defaultVariants: { variant: "default" },
  },
);
```

Note `border-s-2` (logical "border start") rather than `border-l-2` — the accent/danger marker stays
on the reading-start edge in both LTR and RTL automatically; see `# RTL Layout Mirroring`.

## Nesting rule

Cards nest **at most two levels deep** (a page-level `Card` may contain a smaller `Card` for a
sub-grouping, e.g. a "Suggested by AI" callout inside a form section `Card`). A third level is always
a sign the content should be its own page region or its own `Sheet`, not deeper nesting — deep card
nesting is the single most common way a "premium, editorial" screen degrades into cluttered generic
SaaS, and is treated as a design-review-blocking issue. Where a visual separation is needed without a
new Card, use a `Separator` (a 1px `--border-subtle` rule) inside the existing Card instead of
introducing a nested surface — this keeps the "restrained, one accent, hairline borders" language
intact even in dense forms.

# Responsive Layout Rules

Each template degrades on a documented path, not an ad-hoc one. The guiding rule: **content
reflows and reprioritizes; it never gets silently clipped or forced into a horizontal scroll the user
didn't cause themselves** (user-caused horizontal scroll — e.g. a wide table — is fine and documented
below; layout-caused horizontal scroll, where the shell itself overflows, is always a bug).

## Per-template breakpoint behavior

| Template | `base`–`sm` | `md` | `lg` | `xl`+ |
|---|---|---|---|---|
| List Page | Table rows become stacked `Card`s (key fields only, "View" opens Detail) | Table reappears with horizontal scroll, priority columns only | Full table, all default-visible columns | Full table, generous gutters |
| Detail Page | Single column; Summary Rail moves below Main Column | Same as `base` | 8/4 split activates | 8/4 split, more Summary Rail whitespace |
| Form Page | Single column; footer action bar always sticky | Same | 9/3 or 8/4 split activates for preview rail | Full split |
| Dashboard | KPI Strip becomes horizontal-scroll snap carousel; widgets stack 1-per-row | KPI Strip 2-per-row | KPI Strip full row, bento grid activates | Bento grid, AI rail can dock inline |
| Report Page | Report canvas scrolls horizontally within its own container (page itself never scrolls sideways); Findings Bar collapsed by default | Same, less aggressive column hiding | Full canvas, Drill-down as inline expand instead of Sheet on very wide screens | Full canvas, generous margins |

## Container queries for widget-level responsiveness

Dashboard widgets and `Card`-based components resize independently of the viewport (a KPI card in a
3-column grid is narrower than the same component full-width on a detail page), so QAYD uses Tailwind
v4 container queries (`@container`) at the component level rather than only viewport media queries:

```tsx
<div className="@container">
  <Card className="@sm:flex @sm:items-center @sm:justify-between grid gap-2">
    <CardTitle className="@sm:text-2xl text-xl">128,400 KWD</CardTitle>
    <Sparkline className="@sm:block hidden" />
  </Card>
</div>
```

This means the same `KpiCard` component looks correct whether it's rendered 1-per-row on mobile,
3-per-row on a laptop, or dropped into a narrower AI Rail widget slot — it responds to *its own*
available width, not the document's.

## Table responsive strategy

QAYD's chosen strategy for wide financial tables — stated once here so no screen improvises a
different one:

1. **Horizontal scroll inside the table region, with the identifier column frozen** (sticky
   `inset-inline-start: 0`, see `# Scroll & Sticky Behavior`) — the primary strategy for `md`+.
2. **Column priority hiding** below `md`: every table column declares a `priority` (`1` = always
   visible, `2` = hidden below `lg`, `3` = hidden below `xl`). Hidden columns remain reachable via the
   column-visibility menu and via the row's expand affordance — never permanently lost.
3. **Card transform** only below `sm`, and only for List Pages (never for Report Pages, where a
   statement's tabular structure is the content, not a display choice — a Trial Balance never becomes
   a stack of cards; it gets narrower fonts-stay-fixed horizontal scroll instead).

## Touch targets

Tablets are a real QAYD use case (Finance Managers reviewing reports, Warehouse Employees doing stock
counts on a shop floor). All interactive elements maintain a **minimum 44×44px** hit area at `md` and
below regardless of visual size — icon buttons that render at 32px visually still carry 6px of
invisible padding to reach 44px, implemented once in the shared `IconButton` component rather than
per-instance.

# Scroll & Sticky Behavior

## Independent scroll regions

The shell (`# App Shell Layout`) defines exactly three independently scrolling regions on desktop —
Sidebar, Content, AI Rail — plus, within Content, page templates may introduce their own nested
scroll regions (a Report Canvas scrolling horizontally while the page scrolls vertically; a Drill-down
`Sheet` scrolling independently of the page behind it). The **document body itself never scrolls** —
`html, body { overflow: hidden; height: 100dvh; }` — every scrollable area is an explicit, styled
region so scrollbar theming (below) and scroll-restoration are both controllable.

## Sticky elements

| Element | Sticks to | Offset | z-index |
|---|---|---|---|
| Topbar | Top of Content region | `0` | `--z-shell-topbar` (20) |
| Filter Bar (List Page) | Top of Content, below Topbar | `var(--topbar-h)` | `--z-sticky` (10) |
| Table header row | Top of its scroll container | `0` (or below Filter Bar if both stick in the same container) | `--z-sticky` (10) |
| Frozen identifier column | Start edge (`inset-inline-start: 0`) of table scroll container | `0` | `--z-sticky` (10), above other cells, below header |
| Table totals row (Trial Balance, Ledger) | Bottom of its scroll container | `0` | `--z-sticky` (10) |
| Form footer action bar | Bottom of viewport | `0` | `--z-shell-topbar` (20) — same tier as Topbar, they never overlap |
| Report Findings Bar | Top of Report Canvas | `0` | `--z-sticky` (10) |

Sticky totals rows use `position: sticky; bottom: 0` on the `<tfoot>` inside a scroll container with
`overflow-y: auto` — a plain HTML/CSS mechanism, deliberately not reimplemented as a second
absolutely-positioned "fake footer" element, so accessibility tree order and copy/paste of table data
stay correct.

```css
.data-table-scroll { overflow: auto; max-height: 100%; }
.data-table thead th { position: sticky; top: 0; z-index: var(--z-sticky); background: var(--surface); }
.data-table tfoot td  { position: sticky; bottom: 0; z-index: var(--z-sticky); background: var(--surface); font-weight: 600; }
.data-table td.is-frozen, .data-table th.is-frozen {
  position: sticky;
  inset-inline-start: 0;
  z-index: var(--z-sticky);
  background: var(--surface);
}
```

## Overlay stacking vs. sticky layers

Sticky elements live at `--z-sticky`/`--z-shell-topbar` (10/20); every Radix-portaled overlay
(dropdown, drawer, modal, command palette, toast, tooltip) renders in `#portal-root`, outside the
shell's DOM subtree entirely, at `--z-dropdown` (30) or higher — so no sticky table header or topbar
can ever visually trap or clip an open menu, regardless of scroll position. This is why the z-index
scale in `# App Shell Layout` groups "shell chrome" (10/20) distinctly from "overlays" (30+).

## Scroll restoration

Next.js App Router's default scroll-to-top on navigation is overridden for List Page → Detail Page →
back-to-List flows: returning to a list page restores both scroll position and open filter/density
state via the `useDensityStore`/filter-state persistence described earlier, so an accountant scanning
row 340 of Journal Entries who opens an entry and comes back lands on row 340, not row 1. This uses
Next's `router.back()` combined with a `sessionStorage`-cached scroll offset keyed by route + query
string, restored in a `useLayoutEffect` before paint to avoid a visible jump.

## Scrollbar styling

Scrollbars are themed, not default-browser-chrome, to match the restrained aesthetic — thin, low-
contrast, and only visible on hover/scroll (not permanently rendered as a visual element):

```css
.scroll-region {
  scrollbar-width: thin;
  scrollbar-color: var(--border-default) transparent;
}
.scroll-region::-webkit-scrollbar { width: 8px; height: 8px; }
.scroll-region::-webkit-scrollbar-thumb {
  background: var(--border-default);
  border-radius: var(--radius-full);
}
.scroll-region::-webkit-scrollbar-track { background: transparent; }
```

This rule applies uniformly in light and dark mode via the `--border-default` token, and is unaffected
by `dir` — scrollbar gutter position follows the browser's native RTL handling (left-side scrollbar in
RTL), which is intentionally left to the platform rather than fought.

# RTL Layout Mirroring

Arabic is not a translated skin over the English layout — `dir="rtl"` on `<html>` (set by the locale
segment in `app/[locale]/layout.tsx`, driven by the user's `locale` preference) must produce a layout
that reads correctly right-to-left with zero screen-specific RTL overrides. This is achievable only
because every rule in this document above is expressed in logical terms. This section makes that
contract explicit and documents the handful of deliberate, opinionated exceptions.

## Rule 1 — Logical properties only

No component, page, or utility class in QAYD uses a physical-direction CSS property or Tailwind
utility. The mapping enforced by a custom `eslint-plugin-tailwindcss` restricted-list rule (CI-blocking):

| Forbidden (physical) | Required (logical) | Meaning |
|---|---|---|
| `ml-*`, `mr-*` | `ms-*` (margin-inline-start), `me-*` (margin-inline-end) | Margin |
| `pl-*`, `pr-*` | `ps-*`, `pe-*` | Padding |
| `left-*`, `right-*` | `start-*`, `end-*` | Positioning (`inset-inline-*`) |
| `text-left`, `text-right` | `text-start`, `text-end` | Text alignment |
| `border-l-*`, `border-r-*` | `border-s-*`, `border-e-*` | Border side |
| `rounded-l-*`, `rounded-r-*` | `rounded-s-*`, `rounded-e-*` | Corner radius side |
| `float-left`, `float-right` | (avoided entirely; use flex/grid with logical `justify-*`) | Float |

With this in place, flipping `dir` on `<html>` is sufficient for the sidebar to move to the opposite
edge, breadcrumbs to reverse, form field icons to swap sides, and card accent borders to stay on the
reading-start edge — with no per-component RTL branch.

## Rule 2 — Two documented exceptions to pure mirroring

**Exception A — numeric/currency cell alignment is physically fixed, not logical.** Every numeric or
currency table cell (amounts, quantities, percentages, account codes) uses `text-align: right` as a
hard-coded physical value — `text-right`, not `text-end` — in both `dir="ltr"` and `dir="rtl"`.
Accounting convention scans magnitude and decimal alignment by a fixed right edge; a bilingual Finance
team switching the UI language mid-review must see amounts land on the same edge every time, or column
scanning breaks. This is intentional and must not be "corrected" to `text-end` in a future refactor.

**Exception B — debit/credit column order is physically fixed.** In every ledger-style table (Journal
Lines, General Ledger, Trial Balance, Payslip earnings/deductions), the Debit column always renders
before the Credit column in physical left-to-right order, in both `dir="ltr"` and `dir="rtl"` — the
*label* column and every other column mirror normally, but this specific pair does not swap. Rationale:
Debit-then-Credit left-to-right is a universal accounting notation independent of interface language;
reversing it would misalign on-screen review against printed statements, PDF exports, and auditor
expectations that are themselves LTR-fixed regardless of company locale. Implemented via an explicit
`dir="ltr"` wrapper around just the Debit/Credit column pair, not by disabling mirroring for the whole
table.

```tsx
// components/accounting/JournalLinesTable.tsx (excerpt)
<TableCell className="text-end">{line.accountName}</TableCell>
<TableCell dir="ltr" className="text-right tabular-nums">{formatMoney(line.debit)}</TableCell>
<TableCell dir="ltr" className="text-right tabular-nums">{formatMoney(line.credit)}</TableCell>
```

## Rule 3 — Numerals and codes stay LTR-embedded

Amounts, dates, currency codes, account codes, and IDs are wrapped so they never suffer bidi
reordering inside an RTL paragraph (a real Unicode bidi-algorithm hazard with mixed digit/punctuation
runs, not a hypothetical one):

```tsx
// components/ui/bidi.tsx
export function Bidi({ children }: { children: React.ReactNode }) {
  return <span dir="ltr" className="unicode-bidi-isolate">{children}</span>;
}
// usage
<p>{t("invoice.due", { amount: <Bidi>{formatMoney(1250.5, "KWD")}</Bidi> })}</p>
// renders correctly inside an Arabic sentence: "...المبلغ المستحق KWD 1,250.500 خلال 30 يومًا"
```

`unicode-bidi: isolate` (applied via a utility class) plus `dir="ltr"` on the span is belt-and-suspenders:
the CSS property prevents the run from affecting surrounding bidi context even before React commits;
the `dir` attribute guarantees correct behavior in contexts (copy/paste, PDF export via the same
markup) where the CSS property might not apply. Numbers use Western Arabic numerals (`0-9`) in both
locales, per QAYD's i18n convention (`Intl.NumberFormat` with `numberingSystem: "latn"` forced) — Gulf
finance and banking contexts read Western numerals almost universally even in Arabic UI, and mixing
numbering systems inside financial documents is avoided entirely rather than made configurable.

## Rule 4 — Icons

Directional icons are swapped based on `dir`, non-directional icons never change. A single
`DirectionalIcon` wrapper resolves this once instead of per-usage `if (dir === "rtl")` branches
scattered across the codebase:

| Directional (flips) | Non-directional (never flips) |
|---|---|
| `ChevronLeft` ↔ `ChevronRight` | `Search`, `Bell`, `Settings`, `User`, `Home`, `Calendar`, `Filter` |
| `ArrowLeft` ↔ `ArrowRight` | `Plus`, `X`, `Check`, `MoreHorizontal`, `MoreVertical` |
| `ChevronsLeft` ↔ `ChevronsRight` | `Trash2`, `Pencil`, `Eye`, `EyeOff`, `Download`, `Upload` |
| `Undo2` ↔ `Redo2` | `Clock`, `Info`, `AlertTriangle`, `CheckCircle2` |
| `PanelLeftClose` ↔ `PanelRightClose` (sidebar toggle) | Any icon whose meaning is vertical or non-spatial |

```tsx
// components/ui/directional-icon.tsx
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useDirection } from "@/hooks/use-direction";

export function ChevronStart(props: LucideProps) {
  const dir = useDirection(); // reads from the locale layout's dir attribute via context
  const Icon = dir === "rtl" ? ChevronRight : ChevronLeft;
  return <Icon {...props} />;
}
```

## Rule 5 — Charts never mirror

Data visualizations (cash-flow trend, revenue chart, sparklines) keep a fixed left-to-right internal
axis and legend order regardless of page `dir`. Only the chart's *container position* within the
surrounding grid mirrors (an 8/4 split still puts the chart in the visually-start column). This
matches the near-universal convention that time-series charts read left-to-right (earliest to latest)
independent of prose direction — flipping chart internals under RTL is a common but confusing mistake
this system explicitly avoids. Implemented by rendering the charting library (whatever `DATA_VIZ`
specifies) inside a `dir="ltr"` wrapper unconditionally.

## Rule 6 — Motion direction follows `dir`

Framer Motion slide variants read the direction from context rather than hard-coding `x` sign:

```tsx
// hooks/use-slide-variants.ts
export function useSlideVariants() {
  const dir = useDirection();
  const sign = dir === "rtl" ? -1 : 1;
  return {
    hidden: { x: 24 * sign, opacity: 0 },
    visible: { x: 0, opacity: 1 },
  };
}
```

A `Sheet` sliding "in from the end edge" therefore slides from the left in RTL and the right in LTR —
consistent with the logical-properties rule, and consistent with `prefers-reduced-motion`, which zeroes
the `x` offset entirely regardless of `dir` (see motion conventions in `COMPONENT_LIBRARY`).

## Testing checklist

| Check | Method |
|---|---|
| Every screen renders correctly at `dir="rtl"` with no clipped/overlapping text | Playwright visual-regression matrix: `{locale: en, ar} × {dir: ltr, rtl} × {theme: light, dark} × {density: comfortable, compact}` on the five templates |
| No physical-direction Tailwind class shipped | CI lint rule (`eslint-plugin-tailwindcss` restricted list), build-blocking |
| Numeric columns stay right-aligned in both directions | Snapshot test on `JournalLinesTable`, `LedgerTable`, `TrialBalanceTable` |
| Debit/Credit column order stays LTR-fixed in both directions | Same snapshot suite, explicit assertion on DOM order + computed `dir` |
| Directional icons flip; non-directional icons don't | Component-level unit test iterating the icon map above |
| Charts never mirror internally | Visual regression on `CashFlowChart` at `dir="rtl"` diffed against `dir="ltr"` mirrored-back |

# Examples

## Route tree (App Router)

```
app/
  [locale]/                          # en | ar — sets dir="rtl"/"ltr" on <html>
    layout.tsx                       # root providers: TanStack Query, Zustand hydration, Echo/Reverb
    (auth)/
      login/page.tsx
    (app)/
      layout.tsx                     # <AppShell> — Sidebar, Topbar, AiRail, Content outlet
      dashboard/page.tsx
      accounting/
        journal-entries/
          page.tsx                   # List Page Template
          new/page.tsx                # Form Page Template
          [id]/
            page.tsx                  # Detail Page Template
            edit/page.tsx              # Form Page Template
        reports/
          trial-balance/page.tsx      # Report Page Template
          balance-sheet/page.tsx      # Report Page Template
          profit-and-loss/page.tsx    # Report Page Template
          cash-flow/page.tsx          # Report Page Template
      banking/
        accounts/page.tsx             # List Page Template
        reconciliation/[id]/page.tsx  # Detail Page Template (specialized match-grid main column)
      ai/
        command-center/page.tsx       # Dashboard Template
```

## `app/[locale]/(app)/layout.tsx`

```tsx
import { AppShell } from "@/components/layout/AppShell";
import { getDirection } from "@/lib/i18n";

export default async function AppLayout({
  children,
  params,
}: { children: React.ReactNode; params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  const dir = getDirection(locale); // "rtl" for ar, "ltr" otherwise

  return (
    <div dir={dir} className="app-shell" data-sidebar="expanded">
      <AppShell>{children}</AppShell>
    </div>
  );
}
```

## `ListPageTemplate`

```tsx
// components/templates/ListPageTemplate.tsx
type ListPageTemplateProps = {
  title: string;
  count: number;
  primaryAction?: React.ReactNode;
  secondaryActions?: React.ReactNode;
  filterBar: React.ReactNode;
  bulkActionBar?: React.ReactNode;
  selectedCount?: number;
  children: React.ReactNode; // DataTable
  footer: React.ReactNode;   // pagination
};

export function ListPageTemplate({
  title, count, primaryAction, secondaryActions,
  filterBar, bulkActionBar, selectedCount = 0, children, footer,
}: ListPageTemplateProps) {
  return (
    <Container variant="full-bleed">
      <PageHeader
        title={title}
        subtitle={`${count.toLocaleString()} entries`}
        primaryAction={primaryAction}
        secondaryActions={secondaryActions}
      />
      <div className="mt-6 sticky top-(--topbar-h) z-(--z-sticky) bg-(--surface)">
        {selectedCount > 0 ? bulkActionBar : filterBar}
      </div>
      <div className="mt-4 min-h-0 flex-1">{children}</div>
      <div className="mt-4">{footer}</div>
    </Container>
  );
}

// app/[locale]/(app)/accounting/journal-entries/page.tsx
export default function JournalEntriesPage() {
  const density = useDensityStore((s) => s.byTable["journal-entries"] ?? "compact");
  const { data, isLoading } = useJournalEntriesQuery({ page: 1, perPage: 25 });

  return (
    <ListPageTemplate
      title="Journal Entries"
      count={data?.meta.pagination.total ?? 0}
      primaryAction={<Button permission="accounting.journal.create">New Journal Entry</Button>}
      secondaryActions={<ExportMenu permission="accounting.journal.export" />}
      filterBar={<JournalEntriesFilterBar />}
      footer={<PaginationFooter meta={data?.meta.pagination} />}
    >
      <DataTable
        columns={journalEntryColumns}
        data={data?.data ?? []}
        density={density}
        loading={isLoading}
        frozenColumnId="reference"
      />
    </ListPageTemplate>
  );
}
```

## `DetailPageTemplate`

```tsx
// components/templates/DetailPageTemplate.tsx
type DetailPageTemplateProps = {
  backHref: string;
  title: string;
  statusBadge: React.ReactNode;
  primaryAction?: React.ReactNode;
  overflowMenu?: React.ReactNode;
  tabs?: React.ReactNode;
  main: React.ReactNode;
  summary: React.ReactNode;
  activity: React.ReactNode;
};

export function DetailPageTemplate({
  backHref, title, statusBadge, primaryAction, overflowMenu, tabs, main, summary, activity,
}: DetailPageTemplateProps) {
  return (
    <Container variant="reading">
      <PageHeader
        backHref={backHref}
        title={title}
        statusBadge={statusBadge}
        primaryAction={primaryAction}
        overflowMenu={overflowMenu}
      />
      {tabs && <div className="mt-4">{tabs}</div>}
      <div className="mt-6 grid grid-cols-12 gap-x-6 gap-y-8">
        <div className="col-span-12 lg:col-span-8 space-y-8">
          {main}
          <Separator />
          {activity}
        </div>
        <aside className="col-span-12 lg:col-span-4">{summary}</aside>
      </div>
    </Container>
  );
}
```

## `ReportPageTemplate`

```tsx
// components/templates/ReportPageTemplate.tsx
type ReportPageTemplateProps = {
  title: string;
  periodSelector: React.ReactNode;
  statusBadge: React.ReactNode;
  actions: React.ReactNode;
  findings?: React.ReactNode;
  canvas: React.ReactNode;
  drilldown?: { open: boolean; onOpenChange: (v: boolean) => void; content: React.ReactNode };
};

export function ReportPageTemplate({
  title, periodSelector, statusBadge, actions, findings, canvas, drilldown,
}: ReportPageTemplateProps) {
  return (
    <Container variant="full-bleed">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold flex items-center gap-3">{title} {statusBadge}</h1>
          <div className="mt-2">{periodSelector}</div>
        </div>
        <div className="flex gap-2">{actions}</div>
      </div>
      {findings && <div className="mt-4">{findings}</div>}
      <div className="mt-6 overflow-x-auto rounded-(--radius-md) border border-(--border-subtle)">
        {canvas}
      </div>
      {drilldown && (
        <Sheet open={drilldown.open} onOpenChange={drilldown.onOpenChange}>
          <SheetContent side="end" className="w-full sm:max-w-md">
            {drilldown.content}
          </SheetContent>
        </Sheet>
      )}
    </Container>
  );
}

// app/[locale]/(app)/accounting/reports/trial-balance/page.tsx
export default function TrialBalancePage() {
  const [drilldownOpen, setDrilldownOpen] = useState(false);
  const { data } = useQuery({
    queryKey: ["trial-balance", { type: "unadjusted", fiscalYearId: 11 }],
    queryFn: () => api.get("/api/v1/accounting/trial-balance", {
      params: { type: "unadjusted", status: "approved", fiscal_year_id: 11, page: 1, per_page: 25 },
    }),
  });

  return (
    <ReportPageTemplate
      title="Trial Balance"
      statusBadge={<StatusBadge status={data?.data.status} />}
      periodSelector={<PeriodSelector />}
      actions={
        <>
          <Button permission="accounting.trial_balance.generate" variant="secondary">Generate</Button>
          <ExportMenu permission="accounting.trial_balance.export" />
        </>
      }
      findings={<FindingsBar findings={data?.data.findings} />}
      canvas={<TrialBalanceTable rows={data?.data.lines} onRowClick={() => setDrilldownOpen(true)} />}
      drilldown={{ open: drilldownOpen, onOpenChange: setDrilldownOpen, content: <DrilldownPanel /> }}
    />
  );
}
```

## Condensed wireframe reference sheet

```
List:    [Header+action] → [Filter/Bulk bar, sticky] → [Table, own scroll] → [Pagination]
Detail:  [Header+status+action] → [Tabs?] → [8/12 Main + 4/12 Summary] → [Activity]
Form:    [Header+CTA] → [Section cards, 40px gaps] → [Sticky footer CTA on scroll]
Dash:    [KPI strip] → [8/12 Chart + 4/12 Insights] → [Bento secondary widgets]
Report:  [Header+period+status] → [Findings bar] → [Canvas, h-scroll] → [Drilldown sheet]
```

# Edge Cases

- **Overflowing breadcrumb/title text.** Company names, journal descriptions, and vendor names are
  user-entered and unbounded. `PageHeader` titles truncate with `text-ellipsis` at one line and expose
  the full value via native `title` attribute + a `Tooltip` on focus/hover — never wrap to a second
  line, which would push the primary action button and destabilize the header's fixed height that
  sticky-footer visibility logic depends on.
- **Ultra-wide monitors (`3xl`, 1920px+).** Reading-container templates (Detail, Form) still cap at
  1440px and center — they do not stretch. Full-bleed templates (Dashboard, Report) cap at 1600px
  rather than truly filling a 1920px+ viewport; the remaining space becomes margin, not new columns of
  content, so a Trial Balance with 6 columns doesn't get stretched into unreadable double-width cells.
- **Very narrow viewports (<360px).** Not a supported breakpoint tier, but the layout does not break:
  `Container` padding drops to `--space-4` minimum and tables fall back to horizontal scroll rather
  than clipping. QAYD's minimum supported width is 320px (smallest current-generation phone).
- **Long/negative numeric values.** Numbers never wrap. Negative amounts render in parentheses per
  accounting convention (`(1,200.000)`, not `-1,200.000`), which is visually wider than the positive
  form — column widths are computed from the widest expected value (using `Intl.NumberFormat` with
  the `accounting` sign display where supported, falling back to manual parenthesization), not
  auto-sized from the first row, so a later row with a larger/negative value never causes column
  reflow after initial paint.
- **Nested overlay depth.** A `Sheet` may open a `Dialog` (e.g. approving a journal entry from within
  the Drill-down panel opens a confirmation Dialog) — that is the maximum permitted depth. A Dialog may
  not open a second Sheet or Dialog; any workflow that seems to need a third layer is restructured as a
  full page navigation instead (e.g. "edit the source invoice" from inside a two-deep overlay navigates
  to the Invoice Detail page rather than opening a third overlay).
- **Print / PDF export of reports.** Trial Balance, Balance Sheet, P&L, and Cash Flow support a print
  stylesheet (`@media print`) and a server-rendered PDF export path that share the same table markup.
  Print mode hides Sidebar/Topbar/AI Rail/Findings Bar entirely, forces the report canvas to full page
  width, sets `page-break-inside: avoid` on each table row so a row never splits across a page boundary,
  and repeats the header row via `thead { display: table-header-group }` on every printed page. Arabic
  exports print fully mirrored (RTL page layout, right-aligned page numbers), driven by the same `dir`
  contract as the screen — there is no separate "print RTL" implementation.
- **Realtime disconnect.** If the Laravel Reverb WebSocket drops, a persistent inline banner renders at
  the top of the Content region (`role="status"`, non-dismissible until reconnected) reading
  "Live updates paused — reconnecting…". It reserves its height in the layout flow (never an absolutely
  positioned overlay) so it doesn't cause a content jump when it appears/disappears; AI status
  indicators in the Topbar simultaneously switch to a static "offline" state rather than a stale
  "online" one.
- **RTL + dark mode + Compact density, combined.** All three are independent token axes (`dir`,
  `color-scheme`, `data-density`) and are covered as a full matrix in the visual-regression suite (see
  `# RTL Layout Mirroring` testing checklist) rather than assumed to compose correctly — sticky-column
  shadow direction, in particular, is the one place these three axes have historically interacted
  (a "frozen column" drop-shadow indicating "more content this way" must point start-to-end, so it
  flips under `dir` and must remain visible, not washed out, in dark mode).
- **Browser/OS text-size scaling to 200%.** Because all spacing tokens are `rem`-based and no component
  sets a fixed pixel height that clips text (row heights are minimums via `min-height`, not `height`),
  200% OS text scaling reflows rows taller rather than truncating their content — verified as part of
  the AA accessibility pass, not merely assumed from using `rem`.
- **RBAC hides entire nav sections.** If a company has no Payroll or Tax module licensed, or a user's
  role grants no permission under an area at all, that entire Sidebar group is omitted from the DOM
  (not rendered-disabled) and the remaining groups re-flow to fill the vertical space naturally — the
  Sidebar never shows a gap or a greyed-out ghost entry for something the viewer categorically cannot
  access. Contrast with a single action within a visible area (e.g. "Post" on one Journal Entry): that
  is omitted per-instance, per the Detail Page Template note above, while the surrounding Journal
  Entries nav item itself remains, because the user does have some access to the area.
- **Short viewport height (laptop with browser devtools open, or a landscape phone).** The Form Page
  Template's sticky footer action bar has a `min-height` fallback: if the viewport is short enough that
  the sticky footer would overlap more than 40% of the visible form body, it becomes a normal
  (non-sticky) footer at the end of the form instead — avoiding a state where a user can't see enough
  of the form to know what they're submitting.
- **Empty List Page (zero records, e.g. a brand-new company with no Journal Entries yet).** The Data
  Region renders a dedicated empty state (icon, one sentence, primary action) in place of the table,
  not a table with a literal "no rows" message — the Filter Bar still renders above it if filters other
  than the default are active ("no results for these filters" is a different, still-actionable state
  from "nothing exists yet").
- **Simultaneous AI Rail + Drill-down Sheet.** The AI Rail (dockable, persistent) and a Drill-down
  `Sheet` (transient, per-interaction) can both want the end edge of the screen at `2xl`+. When a
  Drill-down opens while the AI Rail is docked inline, the Sheet renders as an overlay above the AI
  Rail (both at `--z-drawer`, Sheet mounted after in DOM order so it visually wins) rather than the two
  competing for the same grid column — the AI Rail is never pushed further off-screen or resized to
  accommodate a transient panel.

# End of Document
