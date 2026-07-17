# Responsive Design — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: RESPONSIVE_DESIGN

---

# Purpose

This document is the canonical, implementation-ready specification for how QAYD's Next.js 15 frontend behaves across every viewport it is required to run on — from a 360px Android handset a warehouse employee is holding while counting stock, to a 34" ultra-wide monitor a CFO has open alongside a spreadsheet and the AI Command Center. It defines the breakpoint system, the mobile-first authoring discipline, the concrete adaptive patterns the component library implements (tables that become cards, sidebars that collapse into rails and then into sheets, dashboards that reflow and reorder), and the specific, non-negotiable rules for combining responsive layout with QAYD's other two hard constraints: full Arabic RTL mirroring and default-deny RBAC. It also defines how the platform tests responsiveness — the viewport matrix, the Playwright project configuration, and the sampling strategy that keeps visual-regression cost bounded as the component library grows.

QAYD is a finance product before it is a consumer product. That framing changes what "responsive" means here relative to a marketing site or a social app. A Trial Balance has eight to fourteen columns of `NUMERIC(19,4)` figures that cannot simply be dropped to fit a phone; a journal entry needs the same debit/credit validation on a 375px screen as on a 27" monitor; an AI-authored recommendation card needs its confidence score, its reasoning, and its Approve/Reject affordances visible and tappable at every size, because hiding an approval gate behind a breakpoint is a compliance problem, not a layout inconvenience. Every rule in this document is written against that reality: **responsiveness changes density, grouping, and input mechanics — it never changes what capability a user has, and it never changes a validation rule.**

This document is authoritative for the shared mechanics of responsive layout across the whole frontend. Individual screen specifications (Dashboard, Journal Entries, Trial Balance, Balance Sheet, Bank Reconciliation, AI Command Center, and the rest of the screen catalog) each carry their own `# Responsive Behavior` section describing how *that specific screen* applies the mechanics defined here; a screen document's responsive section must never contradict this one, and where a screen has a genuinely novel responsive requirement not covered here, this document is the one that gets amended. Visual design tokens (color, radius, shadow, motion) are owned by `DESIGN_SYSTEM.md`; component anatomy is owned by the (forthcoming) `COMPONENT_LIBRARY.md`; this document owns the *sizing, breakpoint, and reflow* behavior that sits on top of both. RTL mirroring's non-layout concerns (bidi text handling, locale-aware number/date/currency formatting, translation keys) are owned by `I18N_RTL.md`; this document owns only where RTL and viewport interact — because that intersection is where the actual bugs live.

# Breakpoints

QAYD extends Tailwind CSS v4's default breakpoint scale with one custom tier and maps all six resulting stops onto five semantic device tiers, because `DESIGN_SYSTEM.md`'s Responsive Design section names exactly five: **Mobile, Tablet, Laptop, Desktop, Ultra Wide**. Every other frontend document, every component prop, and every Playwright project name uses these five tier names, never a raw pixel number, so that a design conversation about "the tablet layout" and an engineering conversation about `md:` are unambiguously the same conversation.

## Tailwind stops

Tailwind's breakpoints are **min-width** media queries — a `md:` utility applies at 768px and above, not "only at tablet size" — which is the foundation of the mobile-first cascade described in the next section. QAYD defines the custom `3xl` stop in `globals.css` using Tailwind v4's CSS-first theme configuration rather than a JavaScript config file, consistent with the rest of the design-token system in `DESIGN_SYSTEM.md`:

```css
/* app/globals.css */
@import "tailwindcss";

@theme {
  /* Tailwind defaults, restated explicitly so the full scale lives in one file */
  --breakpoint-sm: 40rem;   /* 640px */
  --breakpoint-md: 48rem;   /* 768px */
  --breakpoint-lg: 64rem;   /* 1024px */
  --breakpoint-xl: 80rem;   /* 1280px */
  --breakpoint-2xl: 96rem;  /* 1536px */

  /* QAYD custom tier — ultra-wide and dual-monitor CFO/Owner desks */
  --breakpoint-3xl: 120rem; /* 1920px */
}
```

## Tier mapping and rationale

| Tier (`DESIGN_SYSTEM.md`) | Tailwind prefixes | Min-width | Representative hardware | Why the line sits here |
|---|---|---|---|---|
| **Mobile** | *(unprefixed)*, `sm:` | 0px / 640px | iPhone SE (375), iPhone 15/16 (390–393), Pixel 8 (412), Galaxy S24 (360–412), small Android tablets in portrait | The unprefixed base is the entire design surface for the narrowest supported device (360px, see **Edge Cases**). `sm:` (640px) is not a device category so much as a *behavioral* line: below it, a two-field row (e.g., date + amount) cannot fit without truncation at the 16px body size mandated in **Responsive Typography & Spacing**; above it, inline label+input pairs and two-up form grids become viable even though the device is still handheld. |
| **Tablet** | `md:` | 768px | iPad mini/Air portrait (744–834 CSS px), Android tablets, phones in landscape | 768px is where QAYD's persistent-chrome threshold sits: below it the primary navigation is an off-canvas `Sheet` and there is a bottom tab bar (**Adaptive Patterns**); at and above it, the sidebar becomes a permanent icon rail and the bottom tab bar disappears entirely. It is also the first tier where a data table gains a second and third priority column instead of collapsing fully to cards (**Finance Tables On Small Screens**). |
| **Laptop** | `lg:` | 1024px | iPad landscape (1024–1194), Windows laptops running 125–150% OS display scaling (physically 1366–1920px but an effective CSS width of 1024–1279px — display scaling is precisely why QAYD targets CSS-pixel behavioral tiers rather than named devices; a "1366×768 laptop" and a "1024px iPad" can render an identical layout and QAYD wants that) | This is where the sidebar regains its text labels (the icon rail becomes a full 264px labeled sidebar) and where list+detail two-pane layouts (e.g., invoice list beside invoice preview) become viable, because there is finally room for both panes without either dropping below its own minimum usable width. |
| **Desktop** | `xl:`, `2xl:` | 1280px / 1536px | 13"–16" MacBook/Windows laptops at 100% scaling (1280–1512 CSS px), standard 1080p/1440p external monitors | `xl:` is where dashboard grids reach their full column count (the AI Command Center's KPI row goes to four across, see **Dashboard Reflow**) and finance tables show every priority-1-through-4 column with no horizontal scroll. `2xl:` does not add density — QAYD's content is capped at a `max-w-[1440px]` centered container above this point (**Responsive Typography & Spacing**), because a Trial Balance grid stretched to fill a 1536px+ window is harder to read, not easier; the extra width becomes margin. |
| **Ultra Wide** | `3xl:` | 1920px | 32"+ 4K/5K monitors, dual-monitor Owner/CFO desks, 21:9 ultra-wide panels | The one tier where QAYD adds a genuinely new region rather than more columns of the same thing: the AI Command Center's **Ask AI** panel becomes a persistent right-hand rail (`3xl:flex`) instead of an overlay sheet, because at this width it no longer competes with the main grid for space. |

## What breakpoints are not used for

Breakpoints in QAYD never gate *capability*. A permission check (`can('bank.transfer')`, resolved server-side per `PERMISSION_SYSTEM.md`) and a viewport check (`md:hidden`) are answering two unrelated questions, and the codebase keeps them syntactically distinct for exactly that reason — see the RBAC/viewport contrast in **Mobile-First Strategy** below. Breakpoints also never encode locale: an `md:` class applies identically whether `dir="ltr"` or `dir="rtl"`; only its physical resolution (which logical edge is "start") changes, per **RTL + Responsive combined**.

# Mobile-First Strategy

QAYD writes every component's base class list for the **Mobile** tier with zero breakpoint prefixes, then layers `sm:`/`md:`/`lg:`/`xl:`/`2xl:`/`3xl:` utilities only where a specific property must change at a specific width. This is a direct consequence of Tailwind's min-width cascade, but the reason QAYD commits to it as a *process* rule (not just a CSS default) is specific to a finance product: data entry on a phone — a sales employee drafting a quotation at a client's site, a warehouse employee keying a stock adjustment on a handset — is the hardest version of every input problem QAYD has (thumb reach, small hit targets, a software keyboard eating half the viewport, unreliable connectivity). If a form, a table, or a dashboard card is solved well at 375px first, generalizing upward to a 27" monitor is a matter of adding whitespace and columns. Solving it desktop-first and then trying to cram a 14-column grid and a hover-revealed action row down to a phone as an afterthought is how touch targets end up at 28px and RTL mirroring breaks — the industry-standard failure mode this document exists to prevent.

## The authoring rule

1. Implement and visually verify the component at 360–390px first, with no breakpoint prefix on any class that does not need one.
2. Add `sm:`/`md:`/`lg:`/`xl:`/`2xl:`/`3xl:` overrides only where the design genuinely diverges at that tier — a component with no `md:` override at all is not a bug, it means the mobile layout already works at tablet size, which is common and desirable.
3. Prefer min-width (unprefixed → `md:` → `lg:`, additive) over max-width overrides (`max-md:hidden`), because a codebase mixing both directions on the same element is materially harder to reason about ("what does this look like at 900px — which of these three rules wins?"). QAYD's one sanctioned use of a `max-*`/`lg:hidden` style rule is a binary show/hide switch between two entirely different components for the same slot (the sidebar rail versus the bottom tab bar, **Adaptive Patterns**), never a progressive property change.
4. Never introduce a client-side `isMobile` boolean to drive layout that Tailwind's responsive variants can express. Tailwind classes are static strings evaluated by the browser's own media queries; a Next.js 15 Server Component can render markup carrying `hidden md:flex` with no knowledge of the requesting device, and the correct variant simply becomes visible once CSS parses. This is what keeps QAYD's RSC-first pages free of a hydration-mismatch flash between "the server guessed mobile" and "the client discovered it's actually a tablet" — there is no guess, because layout is decided by CSS after paint, not by JavaScript before it.

## When a client hook is legitimate

Rule 4 has one carve-out: **behavioral** differences that CSS cannot express — a different row-virtualization height, a swipe gesture that only makes sense on a touch surface, a WebSocket subscription that should only run for widgets currently on-screen (**Dashboard Reflow**). For those cases QAYD uses a single shared hook, safe for SSR because it never assumes a viewport exists before the client has mounted:

```tsx
// hooks/use-breakpoint.ts
'use client';

import { useSyncExternalStore } from 'react';

const BREAKPOINTS = { sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536, '3xl': 1920 } as const;
type Breakpoint = keyof typeof BREAKPOINTS;

function subscribe(callback: () => void) {
  const mql = window.matchMedia('(min-width: 0px)'); // resize-driven re-check
  window.addEventListener('resize', callback);
  return () => window.removeEventListener('resize', callback);
}

function getActiveTier(): Breakpoint | 'base' {
  if (typeof window === 'undefined') return 'base'; // SSR snapshot — never guess a tier server-side
  const width = window.innerWidth;
  let active: Breakpoint | 'base' = 'base';
  for (const tier of Object.keys(BREAKPOINTS) as Breakpoint[]) {
    if (width >= BREAKPOINTS[tier]) active = tier;
  }
  return active;
}

/** Returns the active breakpoint tier for BEHAVIORAL branching only — never for layout, which is CSS's job. */
export function useBreakpoint() {
  return useSyncExternalStore(subscribe, getActiveTier, () => 'base' as const);
}
```

`useSyncExternalStore`'s server snapshot (`'base'`) guarantees the server-rendered HTML and the first client render agree, so there is still no hydration warning; the tier only updates after mount, which is correct, because a *behavioral* decision (should this list subscribe to realtime updates yet, should this row respond to a swipe gesture) is allowed to activate a frame after paint in a way a *layout* decision is not.

## Responsive is not a permission system

Because both mechanisms can hide an element, QAYD enforces a strict syntactic separation so a code reviewer can tell which is which without reading surrounding logic:

```tsx
// CORRECT — two independent gates, visually stacked, never merged into one condition
export function TransferActionBar({ canTransfer }: { canTransfer: boolean }) {
  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:gap-3">
      {/* Viewport concern: stacked on mobile, inline from sm: up. Renders for EVERY user. */}
      <Button variant="outline" className="w-full sm:w-auto">
        {t('banking.transfer.viewDetails')}
      </Button>

      {/* Permission concern: server-resolved, never a CSS class, never viewport-conditional. */}
      {canTransfer && (
        <Button className="w-full sm:w-auto">{t('banking.transfer.initiate')}</Button>
      )}
    </div>
  );
}
```

`canTransfer` is resolved server-side from the user's live permission set (`bank.transfer`, per `PERMISSION_SYSTEM.md`) and passed down as a boolean prop — it is never expressed as `hidden md:block`, and a `hidden`/viewport utility is never used as a substitute for an authorization check. The reverse mistake — wrapping a control a user *does* have permission for in a breakpoint class that removes it below a certain width — is treated as a P1 defect: every capability a role holds must be reachable at every tier, even if it takes an extra tap (an overflow menu, a second sheet) to get there.

# Adaptive Patterns

QAYD's component library implements four adaptive patterns that recur across nearly every screen: a data table becomes a card list, a sidebar collapses through two intermediate states before becoming an off-canvas sheet, primary navigation gains a bottom tab bar below `md:`, and a centered dialog becomes a full-height sheet. Each is a single shared component with responsive branching inside it — never two parallel, hand-maintained implementations — so a fix or a design change applies everywhere at once.

## Pattern 1 — Data tables become cards

Every list screen in QAYD (invoices, bills, journal entries, customers, vendors, stock movements) is backed by one `<ResponsiveDataView>` that wraps a single `ColumnDef[]` array — extended with a QAYD-specific `priority` and `mobileRender` — and renders either a TanStack `<DataTable>` (at `md:` and up) or a `<DataCardList>` (below `md:`) from the *same* column definitions, so a column added to the table is automatically available to the card renderer instead of requiring a second, easily-forgotten edit.

```tsx
// components/shared/data-table/types.ts
import type { ColumnDef } from '@tanstack/react-table';

export type ResponsiveColumnDef<TData> = ColumnDef<TData> & {
  /** 1 = always visible (mobile card title/subtitle), 5 = xl:+ only. */
  priority: 1 | 2 | 3 | 4 | 5;
  /** How this field renders inside a mobile card; omit to reuse the table cell renderer. */
  mobileRender?: (row: TData) => React.ReactNode;
  /** Marks the field that becomes the card's tappable title (usually a document number). */
  isCardTitle?: boolean;
};
```

```tsx
// components/shared/data-table/responsive-data-view.tsx
'use client';

export function ResponsiveDataView<TData>({
  data,
  columns,
  onRowClick,
}: {
  data: TData[];
  columns: ResponsiveColumnDef<TData>[];
  onRowClick: (row: TData) => void;
}) {
  return (
    <>
      <div className="hidden md:block">
        <DataTable data={data} columns={columns} onRowClick={onRowClick} />
      </div>
      <div className="grid gap-3 md:hidden">
        {data.map((row) => (
          <DataCard key={String((row as { id: unknown }).id)} row={row} columns={columns} onClick={onRowClick} />
        ))}
      </div>
    </>
  );
}
```

Both branches render in the same server-delivered payload; CSS's `hidden md:block` / `md:hidden` pair decides which one paints, consistent with the mobile-first rule against client-side `isMobile` branching. The full worked example against a real resource (the Trial Balance) is in **Finance Tables On Small Screens**.

## Pattern 2 — Collapsible sidebar

The primary navigation sidebar has three states, controlled by one Zustand store persisted through a cookie so the server can render the correct width on first paint with no flash of the wrong layout:

```tsx
// stores/sidebar-store.ts
import { create } from 'zustand';

type SidebarState = 'expanded' | 'collapsed'; // desktop/laptop only; mobile never uses this store
interface SidebarStore {
  state: SidebarState;
  toggle: () => void;
}

export const useSidebarStore = create<SidebarStore>((set, get) => ({
  state: 'expanded',
  toggle: () => {
    const next = get().state === 'expanded' ? 'collapsed' : 'expanded';
    document.cookie = `qayd_sidebar=${next}; path=/; max-age=31536000; samesite=lax`;
    set({ state: next });
  },
}));
```

```tsx
// app/(app)/layout.tsx — Server Component reads the cookie to avoid a hydration flash
import { cookies } from 'next/headers';

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const sidebarState = (await cookies()).get('qayd_sidebar')?.value ?? 'expanded';

  return (
    <div className="flex min-h-dvh">
      {/* Laptop and up: persistent rail, width driven by the cookie-resolved class, no client JS needed for first paint */}
      <Sidebar
        className={cn(
          'hidden lg:flex lg:flex-col lg:border-e lg:border-border',
          sidebarState === 'collapsed' ? 'lg:w-18' : 'lg:w-64',
        )}
      />
      <div className="flex flex-1 flex-col">
        <TopAppBar />
        <main className="flex-1 pb-20 md:pb-0">{children}</main>
        {/* Mobile and tablet: bottom tab bar instead of a rail */}
        <BottomNav className="md:hidden" />
      </div>
    </div>
  );
}
```

Below `lg:`, the rail is not merely narrowed — it is entirely absent (`hidden lg:flex`), replaced by a hamburger trigger in `TopAppBar` that opens the same navigation tree inside a shadcn `Sheet` (Radix `Dialog` under the hood) sliding from the logical start edge, closing on route change, backdrop tap, or `Escape`. QAYD deliberately does not attempt a "narrow rail" intermediate state on tablets — user testing on the Kuwait pilot cohort found a 72px icon-only rail on a 768–1023px tablet left too little room for the two-pane list/detail layouts that tier is otherwise wide enough for, so tablet gets the sheet, and the labeled or icon-only rail choice (`expanded` vs `collapsed`) only exists from `lg:` up.

## Pattern 3 — Bottom navigation

Below `md:`, `BottomNav` renders a fixed, role-filtered tab bar for the four or five most-used destinations, plus a center "Create" action that opens a `Sheet` of permission-filtered quick actions (New Invoice, New Journal Entry, New Expense — each conditionally rendered by the same server-resolved permission booleans described in **Mobile-First Strategy**, never by role name alone):

```tsx
// components/shared/navigation/bottom-nav.tsx
'use client';

export function BottomNav({ className }: { className?: string }) {
  const pathname = usePathname();
  const items = useRoleFilteredNavItems(); // server-fed permission booleans, resolved once per session

  return (
    <nav
      className={cn(
        'fixed inset-x-0 bottom-0 z-40 flex items-stretch justify-around border-t border-border bg-background',
        className,
      )}
      style={{ paddingBottom: 'max(0.5rem, env(safe-area-inset-bottom))' }}
    >
      {items.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          className={cn(
            'flex min-h-11 flex-1 flex-col items-center justify-center gap-1 text-xs',
            pathname.startsWith(item.href) ? 'text-primary' : 'text-muted-foreground',
          )}
        >
          <item.icon className="size-6" aria-hidden />
          <span>{t(item.labelKey)}</span>
          {item.badgeCount ? <Badge className="absolute top-1 ms-6">{item.badgeCount}</Badge> : null}
        </Link>
      ))}
    </nav>
  );
}
```

`env(safe-area-inset-bottom)` is applied exactly once, on the nav's own container, specifically so it is never double-counted against a child element's own padding (see **Edge Cases**). Badge counts (pending approvals, unread notifications) arrive over the same Laravel Reverb channel the desktop header's notification bell subscribes to — the transport is identical across tiers, only the presentation differs. Tab order and the visual position of "back" affordances mirror under `dir="rtl"`, detailed in **RTL + Responsive combined**.

## Pattern 4 — Modals become sheets

Any overlay whose content exceeds roughly one screenful on mobile (a full invoice form, a multi-line journal entry) renders as a bottom/end-edge `Sheet` on Mobile and Tablet and a centered `Dialog` from `lg:` up, from one shared wrapper so a feature author never chooses the wrong primitive by hand:

```tsx
// components/shared/overlay/responsive-overlay.tsx
'use client';

export function ResponsiveOverlay({
  open,
  onOpenChange,
  title,
  children,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  children: React.ReactNode;
}) {
  const tier = useBreakpoint();
  const useSheet = tier === 'base' || tier === 'sm' || tier === 'md';

  if (useSheet) {
    return (
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent side="bottom" className="h-[92dvh] rounded-t-2xl">
          <SheetHeader><SheetTitle>{title}</SheetTitle></SheetHeader>
          <div className="overflow-y-auto px-4 pb-[env(safe-area-inset-bottom)]">{children}</div>
        </SheetContent>
      </Sheet>
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader><DialogTitle>{title}</DialogTitle></DialogHeader>
        {children}
      </DialogContent>
    </Dialog>
  );
}
```

This is one of the few components that legitimately needs `useBreakpoint()` rather than pure CSS, because a `Dialog` and a `Sheet` are two different Radix primitives with different focus-trap and dismiss semantics — this is a genuine behavioral fork, not a styling one, and it is exactly the carve-out **Mobile-First Strategy** describes.

## Top app bar adaptation

`TopAppBar` itself reflows rather than swapping components: Desktop and up show a full breadcrumb trail, a global `⌘K` search input, the company switcher, the notification bell, and the user menu, all inline. Below `lg:`, the breadcrumb collapses to a single back chevron plus the current page title, the search input collapses to an icon that expands into a full-screen search overlay on tap, and the company switcher moves inside the sidebar `Sheet`'s header rather than living in the top bar at all — there is no width on a 375px screen where five independent controls plus a page title can coexist legibly, so the bar's *content*, not just its class list, differs by tier.

# Finance Tables On Small Screens

A Trial Balance row carries account code, bilingual account name, opening debit, opening credit, period debit, period credit, closing debit, closing credit, currency breakdown, and an abnormal-balance flag — eight to eleven columns of `NUMERIC(19,4)` data per `accounting.trial_balance_snapshot_lines` (`TRIAL_BALANCE.md`). A General Ledger view adds running balance, source document reference, cost center, and project. None of that is negotiable away on a phone; an accountant reviewing a Trial Balance from a client site still needs every figure, to the fils. QAYD resolves the tension with a five-rule ladder applied in order, per table, rather than one universal trick:

1. **Priority columns with progressive disclosure**, for document-like collections (invoices, bills, journal entries, customers) where a row genuinely has a "headline" and "detail" split. Every `ResponsiveColumnDef` carries a `priority` (1 = always shown, 5 = `xl:`-only per **Adaptive Patterns**); `useResponsiveColumns` filters the array against the active tier before it reaches TanStack Table, so a table never renders a column its own container is too narrow to show responsibly.
2. **Card transformation**, for the same document-like collections below `md:` — the row becomes a card whose header is the document number and status `Badge`, whose body is a two-column definition list of the three or four `priority: 1–2` fields (customer/vendor, date, amount due), and whose remaining fields are reachable only by tapping through to the detail route, never rendered smaller-and-smaller until illegible.
3. **Sticky-first-column horizontal scroll**, for genuinely tabular numeric grids that do not decompose into a card at all — Trial Balance, General Ledger, Balance Sheet, and any multi-period comparison. The account code/name column is pinned; the debit/credit/currency columns scroll horizontally inside their own `overflow-x-auto` region. This is the pattern worked in full below.
4. **Numeric emphasis**, applied identically at every tier: right-aligned, `tabular-nums`, debit black/credit in the account's normal-balance-aware color plus an explicit sign — never color alone (**Responsive Typography & Spacing**, **Images/Charts responsiveness**).
5. **Filters, sort, and export collapse into overflow affordances** below `md:` — an inline filter bar and a row of export buttons become a single "Filters" trigger (badge-counted) opening a `Sheet`, and a kebab `DropdownMenu` respectively, freeing the full row width for data.

## Worked example: Trial Balance at three tiers

```tsx
// app/(app)/accounting/trial-balance/columns.tsx
import type { ResponsiveColumnDef } from '@/components/shared/data-table/types';
import type { TrialBalanceLine } from '@/types/accounting';

export const trialBalanceColumns: ResponsiveColumnDef<TrialBalanceLine>[] = [
  { accessorKey: 'account_code', header: 'Code', priority: 1, isCardTitle: false, size: 88 },
  { accessorKey: 'account_name_en', header: 'Account', priority: 1, isCardTitle: true, size: 240 },
  { accessorKey: 'opening_debit', header: 'Opening Dr', priority: 4, cell: MoneyCell },
  { accessorKey: 'opening_credit', header: 'Opening Cr', priority: 4, cell: MoneyCell },
  { accessorKey: 'period_debit', header: 'Period Dr', priority: 2, cell: MoneyCell },
  { accessorKey: 'period_credit', header: 'Period Cr', priority: 2, cell: MoneyCell },
  { accessorKey: 'closing_debit', header: 'Closing Dr', priority: 1, cell: MoneyCell },
  { accessorKey: 'closing_credit', header: 'Closing Cr', priority: 1, cell: MoneyCell },
  {
    accessorKey: 'is_abnormal_balance',
    header: '',
    priority: 3,
    cell: ({ row }) =>
      row.original.is_abnormal_balance ? (
        <Badge variant="warning">{t('accounting.trialBalance.abnormal')}</Badge>
      ) : null,
  },
];
```

At **Mobile** (priority ≤ 2 rendered as a card: code, account name, closing debit, closing credit, period debit/credit visible, abnormal-balance badge shown), the grid becomes:

```tsx
// components/accounting/trial-balance-card.tsx — one card per trial_balance_snapshot_lines row
<article className="rounded-2xl border border-border bg-card p-4">
  <header className="flex items-start justify-between gap-2">
    <div>
      <p className="text-xs text-muted-foreground">{line.account_code}</p>
      <h3 className="font-medium">{isRtl ? line.account_name_ar ?? line.account_name_en : line.account_name_en}</h3>
    </div>
    {line.is_abnormal_balance && <Badge variant="warning">{t('accounting.trialBalance.abnormal')}</Badge>}
  </header>
  <dl className="mt-3 grid grid-cols-2 gap-y-2 text-sm tabular-nums">
    <dt className="text-muted-foreground">{t('accounting.trialBalance.closingDebit')}</dt>
    <dd className="text-end">{formatMoney(line.closing_debit, companyCurrency)}</dd>
    <dt className="text-muted-foreground">{t('accounting.trialBalance.closingCredit')}</dt>
    <dd className="text-end">{formatMoney(line.closing_credit, companyCurrency)}</dd>
  </dl>
  <Button variant="ghost" size="sm" className="mt-2 w-full min-h-11" onClick={() => openLineDetail(line)}>
    {t('common.viewFullBreakdown')} <ChevronRight className="size-4 rtl:rotate-180" />
  </Button>
</article>
```

At **Tablet** and up, the same data renders as a real table with the account column pinned and a persistent totals row:

```tsx
// components/shared/data-table/sticky-finance-table.tsx
<div className="relative overflow-x-auto rounded-xl border border-border">
  <table className="w-full text-sm">
    <thead className="sticky top-0 bg-muted/50">
      <tr>
        <th className="sticky start-0 z-10 min-w-[220px] bg-muted/50 text-start">Account</th>
        {visibleMoneyColumns.map((col) => (
          <th key={col.id} className="min-w-[120px] text-end tabular-nums">{col.header}</th>
        ))}
      </tr>
    </thead>
    <tbody>
      {rows.map((row) => (
        <tr key={row.id} className="border-t border-border">
          <td className="sticky start-0 z-10 bg-background font-medium">{row.account_name}</td>
          {visibleMoneyColumns.map((col) => (
            <td key={col.id} className="text-end tabular-nums">{formatMoney(row[col.accessor])}</td>
          ))}
        </tr>
      ))}
    </tbody>
    <tfoot className="sticky bottom-0 border-t-2 border-foreground bg-background font-semibold">
      <tr>
        <td className="sticky start-0 bg-background">{t('common.total')}</td>
        {visibleMoneyColumns.map((col) => (
          <td key={col.id} className="text-end tabular-nums">{formatMoney(totals[col.accessor])}</td>
        ))}
      </tr>
    </tfoot>
  </table>
  {/* Trailing-edge scroll hint, dismissed once per device via localStorage, mirrors for RTL automatically since it is positioned with `end-0` */}
  <div className="pointer-events-none absolute inset-y-0 end-0 w-8 bg-gradient-to-l from-background rtl:bg-gradient-to-r" />
</div>
```

`sticky start-0` — the **logical** start, not `left-0` — is the single highest-risk line in this pattern; see **RTL + Responsive combined** for why a physical `left-0` here is a silent, easy-to-miss defect in Arabic. The footer's `sticky bottom-0` keeps the summed Debit/Credit/Balance visible while an accountant scrolls through hundreds of ledger lines on a tablet in the field — a finance table that lets its totals scroll out of view while the user is scrolling *through the data those totals summarize* is treated as a usability defect, not a stylistic choice, because it is precisely the moment trust in the number on screen matters most.

## Virtualization at scale

General Ledger and journal-line views backed by the API's cursor-paginated endpoints (`GET /api/v1/accounting/ledger-entries`, per `API_ARCHITECTURE.md`'s pagination rules) render through `@tanstack/react-virtual` once a result set exceeds roughly 200 rows, regardless of tier — but the estimated row height fed to the virtualizer differs by tier (40px dense desktop rows with hover states versus 56px mobile card rows sized for a 44px minimum touch target plus padding, per **Touch Targets & Gestures**), so `useVirtualizer`'s `estimateSize` callback reads the active breakpoint tier rather than assuming one fixed height across the whole app.

# Forms On Mobile

Every QAYD form — regardless of which tier renders it — validates against exactly one Zod schema, enforced through React Hook Form's resolver. A mobile form is never a "lighter" version of a desktop form; the same journal entry that must balance to the cent on a 27" monitor must balance to the cent on a phone, and the schema that enforces that lives in one file imported by both:

```ts
// lib/schemas/journal-entry.ts
export const journalLineSchema = z.object({
  account_id: z.number().int().positive(),
  description: z.string().max(500).optional(),
  debit: z.string().regex(/^\d+(\.\d{1,4})?$/).default('0.0000'),
  credit: z.string().regex(/^\d+(\.\d{1,4})?$/).default('0.0000'),
  cost_center_id: z.number().int().positive().nullable(),
});

export const journalEntrySchema = z
  .object({
    entry_date: z.string().date(),
    memo: z.string().min(1).max(255),
    lines: z.array(journalLineSchema).min(2), // double-entry: at least two lines, always
  })
  .refine(
    (entry) => {
      const debits = entry.lines.reduce((sum, l) => sum.plus(l.debit), new Decimal(0));
      const credits = entry.lines.reduce((sum, l) => sum.plus(l.credit), new Decimal(0));
      return debits.equals(credits);
    },
    { message: 'validation.journalEntry.mustBalance', path: ['lines'] },
  );
```

## Layout and input mechanics

Fields stack in a single column on Mobile (`grid grid-cols-1 gap-4`) and move to a two- or three-column grid from `md:`/`lg:` up (`md:grid-cols-2 lg:grid-cols-3`), but every input keeps the same `min-h-11` (44px) touch target at every tier, because a mouse-precise desktop user is never the reason to shrink a hit target — only a genuinely different interaction model would justify that, and QAYD does not have one. Numeric and currency fields set `inputMode="decimal"` (not `type="number"`, which suppresses thousand separators and mishandles Arabic-locale decimal display) so mobile keyboards surface a numeric pad without the browser's unreliable spinner UI; date fields use QAYD's shared `<DateField>` wrapping a native `<input type="date">` on touch (to get the platform's own picker, which handles RTL and locale correctly better than a hand-rolled calendar on a small screen) and a custom popover calendar from `lg:` up where a mouse-driven grid is more efficient than the OS picker's full-screen takeover.

Currency inputs format thousand separators on blur, never on keystroke, specifically because reformatting text mid-type moves the caret unpredictably on mobile software keyboards (a well-known source of "phantom" digit insertion bugs); the transmitted and stored value is always the unformatted ASCII-digit string regardless of the active locale's numeral preference, per `I18N_RTL.md`.

## Multi-line editors: journal lines and invoice items

A journal entry's line grid or an invoice's item list is itself a table-to-card transformation, following the same rule as **Finance Tables On Small Screens** but with editable, not read-only, cells:

```tsx
// components/accounting/journal-line-editor.tsx
function JournalLineRow({ index, control }: { index: number; control: Control<JournalEntryForm> }) {
  const tier = useBreakpoint();
  const isCompact = tier === 'base' || tier === 'sm';

  if (isCompact) {
    return (
      <div className="rounded-xl border border-border p-3">
        <div className="grid grid-cols-1 gap-3">
          <AccountCombobox control={control} name={`lines.${index}.account_id`} />
          <div className="grid grid-cols-2 gap-3">
            <MoneyField control={control} name={`lines.${index}.debit`} label={t('accounting.debit')} />
            <MoneyField control={control} name={`lines.${index}.credit`} label={t('accounting.credit')} />
          </div>
          <Button variant="ghost" size="sm" className="min-h-11 text-destructive" onClick={() => remove(index)}>
            <Trash2 className="size-4" /> {t('common.removeLine')}
          </Button>
        </div>
      </div>
    );
  }

  // lg: and up — a dense, inline editable grid row; add-row and delete controls sit at row end (logical, not physical)
  return (
    <div className="grid grid-cols-[2fr_1fr_1fr_1fr_2.5rem] items-center gap-3 border-b border-border py-2">
      <AccountCombobox control={control} name={`lines.${index}.account_id`} />
      <MoneyField control={control} name={`lines.${index}.debit`} />
      <MoneyField control={control} name={`lines.${index}.credit`} />
      <CostCenterSelect control={control} name={`lines.${index}.cost_center_id`} />
      <Button variant="ghost" size="icon" className="justify-self-end" onClick={() => remove(index)}>
        <Trash2 className="size-4" />
      </Button>
    </div>
  );
}
```

## Sticky submission and error handling

The Save/Submit action bar is `sticky bottom-0` (offset above the safe area and, where present, the bottom nav) on Mobile and Tablet so a long journal entry never hides its own submit button below the fold; from `lg:` up the same actions sit inline at the top-right of the form header, where there is enough vertical room that "below the fold" is not a concern and a fixed bottom bar would just consume space unnecessarily. Validation errors render inline under each field at every tier; Mobile additionally auto-scrolls to the first invalid field on a failed submit attempt (`fieldRef.current?.scrollIntoView({ block: 'center' })`), because a small viewport makes a top-of-form error summary easy to miss once the user has scrolled deep into a long line-item list — Desktop keeps the same inline errors and adds a collapsible top-of-form summary listing every failing field as a set of jump links, which is worth the extra affordance once there is room for it.

Forms that can plausibly be interrupted mid-entry on mobile — a warehouse employee's stock count, a sales employee's quotation drafted between client meetings — autosave a debounced draft to IndexedDB every few seconds independent of network state, and reconcile against the server draft (`journal_entries` in `status = 'draft'`, or the equivalent per-module draft state) on reconnect using a client-generated `Idempotency-Key` created at form-open time and reused across retries, so a dropped connection during submission never produces a duplicate financial document (`API_ARCHITECTURE.md`, **Idempotency**). Attachment fields (a photo of a vendor bill for Document AI/OCR ingestion against the polymorphic `attachments` table) render a camera-capable file input (`<input type="file" accept="image/*" capture="environment">`) on Mobile and a drag-and-drop dropzone on Tablet and up — both call the same upload mutation, so the only difference is how the file enters the form, never what happens to it afterward.

# Dashboard Reflow

The AI Command Center (`AI_COMMAND_CENTER.md`) is QAYD's densest screen: Morning Briefing, Business Health Score, Cash Flow Status, Revenue Trends, Expense Trends, AI Insights, AI Recommendations, Detected Risks, Upcoming Tax Deadlines, Payroll Alerts, Inventory Alerts, Supplier Risks, Customer Risks, Fraud Alerts, Financial Forecast, Approval Center, Automation Center, Today's Tasks, Urgent Actions, and Ask AI are all, in principle, on the same dashboard. Desktop and Ultra Wide have room to lay most of that out simultaneously; Mobile has room for one widget at a time. Reflow here is not only a resize problem — it is a **reordering** problem, because the widget a Sales Employee needs first on a 375px screen (their own Today's Tasks) is not the widget a CFO needs first on a 27" monitor (the full KPI row), even though both are looking at conceptually "the same" dashboard filtered by their own permissions.

## Grid and ordering rules

The dashboard is one CSS grid with a fixed source order in the DOM (accessibility and SSR streaming order — Morning Briefing first, then the KPI row, then the two-column Insights/Risks region, then the Approval/Tasks/Urgent rail, then Ask AI) and per-widget `order` utilities that visually promote the highest-urgency content to the top on narrow viewports, where there is no peripheral "rail" a user's eye can fall on the way there is on a wide screen:

```tsx
// app/(app)/dashboard/page.tsx
export default async function DashboardPage() {
  return (
    <div className="grid grid-cols-1 gap-4 p-4 md:gap-6 md:p-6 xl:grid-cols-12 xl:gap-6">
      {/* Urgent Actions: last in the desktop rail, FIRST on mobile — order-first is the point */}
      <UrgentActionsCard className="order-first xl:order-none xl:col-span-4 xl:col-start-9 xl:row-start-1" />

      <MorningBriefingCard className="order-1 xl:order-none xl:col-span-8 xl:row-start-1" />

      {/* KPI row: 1-up mobile, 2-up tablet, 4-up desktop+ — see table below */}
      <BusinessHealthScoreCard className="order-2 xl:order-none xl:col-span-2 xl:row-start-2" />
      <CashFlowStatusCard className="order-2 xl:order-none xl:col-span-2 xl:row-start-2" />
      <RevenueTrendsCard className="order-3 xl:order-none xl:col-span-4 xl:row-start-2" />
      <ExpenseTrendsCard className="order-3 xl:order-none xl:col-span-4 xl:row-start-2" />

      <ApprovalCenterCard className="order-first xl:order-none xl:col-span-4 xl:col-start-9 xl:row-start-2 xl:row-span-2" />

      <AIInsightsCard className="order-4 xl:order-none xl:col-span-4 xl:row-start-3" />
      <AIRecommendationsCard className="order-4 xl:order-none xl:col-span-4 xl:row-start-3" />
      <DetectedRisksCard className="order-5 xl:order-none xl:col-span-4 xl:row-start-3" />

      <TodaysTasksCard className="order-1 xl:order-none xl:col-span-4 xl:col-start-9 xl:row-start-4" />
      <FinancialForecastCard className="order-6 xl:order-none xl:col-span-8 xl:row-start-4" />

      {/* Ask AI: overlay sheet on everything below 3xl, persistent rail only at Ultra Wide */}
      <AskAIPanel className="hidden 3xl:col-span-3 3xl:row-span-4 3xl:flex" />
      <AskAIFloatingButton className="3xl:hidden" />
    </div>
  );
}
```

## Reflow table

| Widget (`widget_id`) | Desktop / Ultra Wide position | Mobile position | Mobile presentation |
|---|---|---|---|
| `urgent_actions` | Top of right rail | **First**, above Morning Briefing | Full-width alert list, no card chrome |
| `morning_briefing_*` | Full-width top row | Second | Condensed to headline + 3 bullets, "Read full briefing" expands |
| `cfo_ratio_insight` (Business Health Score) | 1 of 4 in KPI row | Third, single KPI per row | Score + trend arrow only; ratio breakdown behind a tap |
| Cash Flow Status | 1 of 4 in KPI row | Third, single KPI per row | Live balance + traffic-light color, forecast sparkline hidden |
| Revenue / Expense Trends | 2 of 4 in KPI row, full chart | Fourth, stacked | Reduced tick count, tap-for-tooltip instead of hover (**Images/Charts responsiveness**) |
| `approval_center_queue` | Right rail, spans two rows | **Second**, above the KPI row | One approval card at a time, swipeable (**Touch Targets & Gestures**) |
| AI Insights / Recommendations | Two-column mid section | Fifth | Single column, priority-sorted |
| `ai_risk_flags` (Detected Risks) | Two-column mid section | Sixth | Grouped by severity, critical first, unchanged from desktop grouping logic |
| Today's Tasks | Right rail | **Second**, tied with Approval Center by due time | Checklist, swipe-to-complete |
| Financial Forecast | Full-width bottom row | Seventh | 13-week chart reduced to a 6-week sparkline plus headline number |
| Ask AI | Persistent rail (Ultra Wide only) / overlay elsewhere | Floating action button, opens full-screen chat sheet | — |

## Realtime subscription scoping

Every widget above is fed by Laravel Reverb over the company's private channel (`AI_COMMAND_CENTER.md`'s `GET` endpoints establish the initial TanStack Query cache; Echo pushes keep it live). Desktop and Ultra Wide subscribe to every channel the dashboard's visible widgets need at once, because most of them are on-screen simultaneously anyway. Mobile instead gates each widget's Echo subscription behind an `IntersectionObserver` so a widget scrolled out of view unsubscribes rather than continuing to process pushes it isn't rendering — meaningful for battery and radio usage on a phone reviewing a long, single-column dashboard, and irrelevant enough on desktop (where nearly everything is already visible) that QAYD does not bother implementing the same gating there.

## Loading and skeleton reflow

Skeleton placeholders mirror the real grid's `order`/`col-span` classes exactly — a skeleton grid that shows four KPI skeletons in a row on mobile and then pops to the real single-column card is a worse experience than no content shift at all, so the loading state and the loaded state share one layout component and only the inner content (`<Skeleton>` versus real data) differs.

# Touch Targets & Gestures

## Minimum sizes

QAYD standardizes on a **44px** (CSS px) minimum interactive hit area at every tier, matching the higher of Apple's Human Interface Guidelines (44pt) and WCAG 2.5.5's AA target size guidance, expressed as Tailwind's `min-h-11`/`min-w-11` (2.75rem = 44px at the mandatory 16px root). This applies even where the visible glyph is smaller — a 20px Lucide icon inside a 44px button uses padding to reach the full hit area, never a smaller box with a larger icon crammed into it. Adjacent interactive elements keep a minimum 8px gap (`gap-2`, the smallest step on the `DESIGN_SYSTEM.md` spacing scale) specifically to reduce mis-taps between, for example, an invoice row's "Send" and "Void" actions — a mistake with real financial consequences, unlike a mis-tap on a marketing site.

Table row height is the one place QAYD deliberately varies "the same" control's size by tier: 40px dense rows with hover affordances at `lg:` and up, where a mouse offers pixel precision; 56px rows on Mobile and Tablet, sized to comfortably clear the 44px minimum with margin for finger imprecision. This is encoded once in the shared row-height token consumed by both the DOM table and the `@tanstack/react-virtual` estimator referenced in **Finance Tables On Small Screens**, never hand-tuned per screen.

## Gesture catalog

| Gesture | Where it applies | Tier | RTL behavior |
|---|---|---|---|
| Swipe (horizontal) | Approve/Reject on an Approval Center card, complete/snooze on a Today's Tasks item | Mobile, Tablet (touch) | Direction fully mirrors: swipe-to-approve is a "start-to-end" drag regardless of `dir`, i.e. physically left-to-right in LTR and physically right-to-left in RTL |
| Pull-to-refresh | Any list view (invoices, ledger entries) | Mobile, Tablet only | N/A — vertical gesture, unaffected by `dir` |
| Long-press | Enter multi-select mode on a card list | Mobile, Tablet (touch) | N/A |
| Ctrl/Cmd-click, Shift-click | Multi-select on a `DataTable` | Laptop, Desktop, Ultra Wide | N/A |
| Drag-and-drop reorder | Reordering report widgets, price-list priority | All tiers, via `dnd-kit`'s pointer + touch sensors | Drag-axis constraints flip their sign in RTL (see below) |
| Edge swipe back | Navigate to the previous route | Mobile (native shell / PWA) | Activates from the **logical start** edge — physically the right edge in RTL, not hardcoded to the physical left |
| Double-tap | Explicitly disabled as a zoom trigger on interactive controls (`touch-action: manipulation`) | Mobile, Tablet | N/A |

Hover-revealed affordances — a table row's action icons that only appear "on hover" in a desktop-first design — are never the *only* way to reach that action, because there is no hover on a touch surface. QAYD detects real hover capability with a feature query rather than user-agent sniffing (`@media (hover: hover) and (pointer: fine)`), and any control gated behind it has a touch-visible fallback (usually a persistent kebab `DropdownMenu`) rather than becoming unreachable:

```css
/* globals.css */
.row-actions {
  opacity: 0; /* desktop default: revealed on hover/focus */
}
@media (hover: hover) and (pointer: fine) {
  tr:hover .row-actions,
  .row-actions:focus-within {
    opacity: 1;
  }
}
@media (hover: none) {
  .row-actions {
    opacity: 1; /* touch: always visible, no hover to reveal it */
  }
}
```

## Swipeable approval card

The Approval Center's swipe-to-decide interaction (`AI_COMMAND_CENTER.md`'s Approval Center panel) uses Framer Motion's drag gestures, respecting both reduced-motion preference and RTL directionality — the latter by reading the resolved `dir` rather than assuming LTR:

```tsx
// components/ai/swipeable-approval-card.tsx
'use client';

export function SwipeableApprovalCard({ request, onApprove, onReject }: ApprovalCardProps) {
  const shouldReduceMotion = useReducedMotion();
  const { dir } = useLocale(); // 'ltr' | 'rtl', from I18N_RTL.md's locale context
  const sign = dir === 'rtl' ? -1 : 1; // drag-to-approve is toward the LOGICAL end in both directions

  const x = useMotionValue(0);
  const background = useTransform(x, [-120 * sign, 0, 120 * sign], ['#fee2e2', '#ffffff', '#dcfce7']);

  return (
    <motion.div
      role="group"
      aria-label={t('ai.approvalCenter.cardLabel', { title: request.title })}
      style={{ x: shouldReduceMotion ? 0 : x, background, touchAction: 'pan-y' }}
      drag={shouldReduceMotion ? false : 'x'}
      dragConstraints={{ left: -160, right: 160 }}
      dragElastic={0.15}
      onDragEnd={(_, info) => {
        if (info.offset.x * sign > 100) onApprove(request.id);
        else if (info.offset.x * sign < -100) onReject(request.id);
      }}
      className="rounded-2xl border border-border p-4"
    >
      <ApprovalCardContent request={request} />
      {/* Buttons remain the primary, always-available action — the swipe is an accelerator, never the only path */}
      <div className="mt-3 flex gap-2">
        <Button variant="outline" className="min-h-11 flex-1" onClick={() => onReject(request.id)}>
          {t('common.reject')}
        </Button>
        <Button className="min-h-11 flex-1" onClick={() => onApprove(request.id)}>
          {t('common.approve')}
        </Button>
      </div>
    </motion.div>
  );
}
```

Two details carry the real weight here. First, `shouldReduceMotion` disables `drag` entirely rather than merely skipping the animation — a user who has asked the OS for reduced motion is not asking for the same gesture with the polish removed, they are frequently asking because the gesture itself (a moving, draggable card) is the discomfort, so the explicit Approve/Reject buttons become the only interaction path for that user, and they are already present for every user regardless. Second, the swipe is always an *accelerator* on top of the two buttons, never a replacement for them — a screen-reader user or a switch-control user completes the exact same approval through the buttons, satisfying the platform rule (`DESIGN_CONTEXT.md`) that AI approval gates are never accessible through only one input modality.

# Responsive Typography & Spacing

## Fluid type scale

The root font size is fixed at **16px** (`1rem`) at every tier and is never rescaled by a `html { font-size: 62.5% }`-style trick, because those tricks are a common way responsive designs accidentally break a user's OS-level "larger text" accessibility preference (**Edge Cases**). Instead, each type role in `DESIGN_SYSTEM.md`'s typography hierarchy (Display, Heading, Title, Subtitle, Body, Caption, Label) is a `clamp()` expression whose minimum and maximum are both `rem`-anchored, so the whole scale grows and shrinks with the viewport *and* still respects a user's browser-level text-size preference at both ends:

| Role | Mobile (360px) | Desktop (1280px+) | `clamp()` (applied via CSS variable) |
|---|---|---|---|
| Display | 28px | 40px | `clamp(1.75rem, 1.55rem + 1.2vw, 2.5rem)` |
| Heading | 22px | 28px | `clamp(1.375rem, 1.25rem + 0.7vw, 1.75rem)` |
| Title | 18px | 22px | `clamp(1.125rem, 1.05rem + 0.4vw, 1.375rem)` |
| Subtitle | 16px | 18px | `clamp(1rem, 0.95rem + 0.25vw, 1.125rem)` |
| Body | 15px | 16px | `clamp(0.9375rem, 0.92rem + 0.1vw, 1rem)` |
| Caption | 13px | 13px | `0.8125rem` (fixed — captions do not scale; see below) |
| Label | 13px | 14px | `clamp(0.8125rem, 0.8rem + 0.1vw, 0.875rem)` |

```css
/* app/globals.css */
@theme {
  --text-display: clamp(1.75rem, 1.55rem + 1.2vw, 2.5rem);
  --text-heading: clamp(1.375rem, 1.25rem + 0.7vw, 1.75rem);
  --text-title: clamp(1.125rem, 1.05rem + 0.4vw, 1.375rem);
  --text-subtitle: clamp(1rem, 0.95rem + 0.25vw, 1.125rem);
  --text-body: clamp(0.9375rem, 0.92rem + 0.1vw, 1rem);
  --text-caption: 0.8125rem;
  --text-label: clamp(0.8125rem, 0.8rem + 0.1vw, 0.875rem);
}
```

Every formula above includes a `rem` term added to the `vw` term (never a bare `vw` value), which is what keeps the scale honoring the user's own font-size preference — a `vw`-only clamp tracks the viewport but silently ignores a browser zoom or an OS-level "larger text" setting, since neither changes the viewport's pixel width; the `rem` component is anchored to the root font size that those settings *do* change, so a user running their browser at 150% text size still gets a proportionally larger Display heading on the exact same physical screen. Caption is deliberately fixed rather than fluid: at 13px, further fluid shrinking below the mobile value would cross AA's practical legibility floor, and further fluid growth above it on a wide screen adds no value to a caption's job (a timestamp, a field hint) — not every role benefits from fluidity, and QAYD does not force one onto roles where it has no payoff.

## Container widths and spacing

Horizontal page padding follows the `DESIGN_SYSTEM.md` spacing scale (4/8/12/16/20/24/32/40/48/64) exactly — a responsive gap is never an arbitrary value outside that scale, including at a specific breakpoint:

```tsx
// components/shared/layout/container.tsx
export function Container({ className, children }: { className?: string; children: React.ReactNode }) {
  return (
    <div
      className={cn(
        'mx-auto w-full px-4 sm:px-6 lg:px-8 xl:px-10',
        'max-w-full 2xl:max-w-[1440px]', // per Breakpoints: 2xl adds margin, not more density
        className,
      )}
    >
      {children}
    </div>
  );
}
```

| Tier | Horizontal padding | Section vertical rhythm (`py-`) |
|---|---|---|
| Mobile | 16px (`px-4`) | 32px (`py-8`) |
| Tablet | 24px (`px-6`) | 32px (`py-8`) |
| Laptop | 32px (`px-8`) | 48px (`py-12`) |
| Desktop / Ultra Wide | 40px (`px-10`), content capped at 1440px from `2xl:` | 64px (`py-16`) |

## Arabic typography adjustment

IBM Plex Sans Arabic reads slightly smaller than Inter at an identical pixel size for an equivalent perceived weight and x-height. Rather than maintaining a second, hand-tuned set of `clamp()` values for `dir="rtl"`, QAYD applies a single multiplier custom property that scales every type-role variable together:

```css
:root { --font-scale: 1; }
[dir='rtl'] { --font-scale: 1.0625; }

.text-body { font-size: calc(var(--text-body) * var(--font-scale)); }
```

This keeps the *ratios* between Display/Heading/Title/Body identical across languages (so the hierarchy reads the same) while nudging absolute size up just enough for Arabic's optical characteristics, at every breakpoint simultaneously, from one line changed in one place.

## Measure and numeric alignment

Long-form AI narrative text (an Ask AI answer, a CFO Agent board narrative, a Morning Briefing paragraph) caps its line length at `max-w-prose` (≈65ch) regardless of how wide its grid column is on Ultra Wide, because an unbounded line length is measurably harder to read regardless of screen size — this is one of the few places QAYD intentionally does *not* let content stretch to fill available width. Every numeric column — money, quantities, percentages — carries `tabular-nums` globally (`font-variant-numeric: tabular-nums`) so digits occupy fixed-width cells and a column of amounts never visually jitters as values change on a realtime push; both Inter and IBM Plex Sans Arabic ship the `tnum` OpenType feature, verified at font-subsetting build time, with a fixed-width utility class fallback (`font-mono` restricted to numerals via `unicode-range`) wired in for the rare custom font override a white-label deployment might introduce.

# Images/Charts responsiveness

## Icons and avatars

Lucide icons (`DESIGN_SYSTEM.md`: 24px default) scale in **discrete steps** at breakpoints (`size-5 md:size-6`), never fluidly — an icon rendered at a non-integer pixel size loses crispness on standard-density and low-DPI displays alike, so icon sizing is a small, fixed set of Tailwind `size-*` utilities chosen per context (16px inline-with-text, 20px in buttons, 24px standalone/nav), not a `clamp()` participant. Company logos and avatars render through `next/image` with an explicit `sizes` attribute matching the actual rendered width at each tier, so the browser fetches an appropriately sized asset rather than downscaling a desktop-resolution image on a phone:

```tsx
// components/shared/company-logo.tsx
'use client';

export function CompanyLogo({ light, dark, name }: { light: string; dark: string; name: string }) {
  const { resolvedTheme } = useTheme(); // next-themes; see DARK_MODE.md
  return (
    <Image
      src={resolvedTheme === 'dark' ? dark : light}
      alt={name}
      width={120}
      height={32}
      sizes="(min-width: 1024px) 140px, 100px"
      className="h-6 w-auto md:h-8"
      priority
    />
  );
}
```

`next/image` has no native "swap source on a dark-mode media query" primitive, so the swap is resolved client-side against `next-themes`' already-hydrated theme value rather than attempted through CSS alone — a brief single-render delay on first paint is preferred over shipping both logo variants and hiding one with CSS, which would defeat the point of responsive, size-appropriate image loading.

## Charts

QAYD's charts (shadcn/ui's chart primitives over Recharts) universally wrap `ResponsiveContainer` inside a parent with an **explicit height**, never a bare percentage height inside a flex or grid parent — a `ResponsiveContainer` whose ancestor chain resolves to `height: auto` collapses to zero height, a well-known Recharts failure mode that QAYD avoids structurally rather than by convention:

```tsx
// components/shared/charts/chart-frame.tsx
export function ChartFrame({ children }: { children: React.ReactNode }) {
  return (
    <div className="aspect-[16/9] w-full md:aspect-[21/9]">
      <ResponsiveContainer width="100%" height="100%">
        {children}
      </ResponsiveContainer>
    </div>
  );
}
```

Chart *density*, not just chart size, adapts by tier — Revenue Trends and Expense Trends (`AI_COMMAND_CENTER.md`) show a full 12–24 month range with a multi-series legend on Desktop and up, and a reduced 6-month window with tap-to-reveal tooltips (never hover-dependent, since there is no hover on the touch devices this density targets) on Mobile and Tablet:

```tsx
// hooks/use-chart-density.ts
export function useChartDensity() {
  const tier = useBreakpoint();
  const compact = tier === 'base' || tier === 'sm' || tier === 'md';
  return {
    monthsShown: compact ? 6 : 24,
    showLegend: !compact,
    showGridLines: !compact,
    tooltipTrigger: compact ? ('click' as const) : ('hover' as const),
  };
}
```

Sparklines embedded inside KPI cards (the trend indicator on Business Health Score, the mini cash-flow projection on Cash Flow Status) are the deliberate exception: they hold a fixed small size at every tier, because they are a decorative trend signal, not a data-reading surface — a sparkline that "reflows" to show more detail would be solving a problem the widget doesn't have; a user who needs the detail taps through to the full Financial Forecast chart instead. Color is never the only channel encoding meaning in a chart (colorblind-safe palette per `DESIGN_SYSTEM.md`'s accessibility commitments), and shape/pattern redundancy (distinct line-dash styles, distinct marker shapes) matters proportionally more at small mobile chart sizes, where a color swatch may render at only a few pixels wide. Exported/printed financial statements (Balance Sheet, P&L, Trial Balance PDF exports) are rendered against a fixed document width (A4/Letter, ≈794 CSS px at 96dpi) entirely independent of the viewing device's breakpoint — an exported document is not a responsive surface, and this document's rules stop applying the moment content crosses into a print/export render path (**Edge Cases**).

# RTL + Responsive combined

QAYD's Arabic-first commitment (`DESIGN_CONTEXT.md`: "Everything must work identically LTR/RTL") is only meaningful if it holds at every breakpoint simultaneously. A layout that mirrors correctly on a 1440px desktop screenshot but silently reverts to physical-direction assumptions at 375px is, from a Kuwait-market Arabic-first user's perspective, simply broken on their phone — and QAYD treats a mobile-only RTL regression exactly as seriously as a desktop one, never as a lesser variant of the same bug.

## Logical properties, not physical ones

Every component in the shared library is written with Tailwind's logical-direction utilities — `ms-*`/`me-*` (margin-start/end), `ps-*`/`pe-*` (padding-start/end), `start-*`/`end-*` (inset), `text-start`/`text-end`, `border-s`/`border-e` — and the physical equivalents (`ml-*`, `mr-*`, `left-*`, `right-*`, `text-left`, `text-right`) are banned from the component library by a repository ESLint rule, not merely a code-review convention, because a physical-direction class that happens to look correct in an English screenshot fails identically at every breakpoint the instant `dir="rtl"` is set, and that failure mode is invisible to anyone testing only in English. Three narrow, deliberate exceptions are enumerated rather than left to individual judgment:

1. **Numerals and currency figures** are always rendered left-to-right even inside an RTL sentence (`4,102.500 KWD` reads the same embedded in an Arabic paragraph as standalone), isolated with `unicode-bidi: isolate` around the numeric span so the surrounding bidi Arabic text does not reorder the digits themselves.
2. **Chart time axes** always progress left-to-right regardless of `dir` — a revenue trend line reads chronologically L-to-R for Arabic-first users exactly as it does for English-first users, a documented, co-signed design decision (`DESIGN_SYSTEM.md`/`I18N_RTL.md`), not an overlooked mirroring gap.
3. **Code, JSON, and SQL fragments** surfaced in the Ask AI chat or a developer-facing log viewer are always LTR, since source syntax has its own, non-negotiable directionality independent of the surrounding UI language.

## Breakpoint-specific RTL risk points

These are the specific places in this document's own patterns where a physical-direction assumption is most likely to survive unnoticed, because it happens to render correctly in the common English-desktop QA pass and only breaks in a combination (Arabic **and** a specific breakpoint) that a rushed test pass skips:

- **Bottom navigation** (**Adaptive Patterns**, Pattern 3): tab order visually mirrors under `dir="rtl"` — the rightmost tab in LTR is the leftmost in RTL — and the native-shell edge-swipe-back gesture activates from the **logical start** edge, which is the physical *right* edge in RTL, never hardcoded to the physical left.
- **Sticky-first-column finance tables** (**Finance Tables On Small Screens**): the pinned account column uses `sticky start-0`, never `sticky left-0`. This is, deliberately, called out twice in this document, because `left-0` is syntactically valid, visually correct in an English demo, and silently pins the *wrong* physical edge the instant a Trial Balance is viewed in Arabic — the single highest-risk line of code this entire specification describes.
- **Horizontal-scroll containers** default their scroll position to the logical start on mount. Browsers do not uniformly auto-flip the initial `scrollLeft` for an RTL container, so QAYD sets it explicitly:

```tsx
// hooks/use-rtl-aware-scroll-reset.ts
'use client';

export function useRtlAwareScrollReset(ref: React.RefObject<HTMLElement>) {
  const { dir } = useLocale();
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    // In RTL, "resting at the start" is the physical right edge, i.e. the maximum scrollLeft in most engines.
    el.scrollLeft = dir === 'rtl' ? el.scrollWidth : 0;
  }, [dir, ref]);
}
```

- **Off-canvas sidebar and Sheet-based overlays** (**Adaptive Patterns**, Patterns 2 and 4) slide in from the logical start edge — shadcn's `Sheet` accepts a `side` prop that must be resolved against the active `dir` (`side={dir === 'rtl' ? 'right' : 'left'}` for a "start" sheet), never left hardcoded to `'left'`.
- **Swipe gestures** (**Touch Targets & Gestures**): the approval-card swipe direction's sign flips with `dir`, exactly as shown in `SwipeableApprovalCard` above — a drag threshold check that ignores `dir` will approve on a swipe that a native Arabic-reading user experiences as "reject," which is as severe a defect class as a functional bug, not merely a cosmetic one.

Every one of these is, per **Testing** below, verified with an automated assertion at every viewport in the matrix under both `dir` values — "we spot-checked RTL on desktop" is explicitly not sufficient sign-off for merging a change to any of the patterns in this document.

# Testing

## Viewport matrix

QAYD's automated responsive test suite runs against a fixed viewport matrix rather than an arbitrary sampling of widths, so that "does this break on mobile" always resolves to the same, reproducible set of checks:

| Name | Size (CSS px) | DPR | Tier | Purpose |
|---|---|---|---|---|
| `mobile-floor` | 320×568 | 2x | Mobile | Absolute minimum — must not visually break (horizontal scroll, clipped text, overlapping controls); not required to be equally polished, per **Edge Cases** |
| `mobile-se` | 375×667 | 2x | Mobile | Smallest actively-supported, fully-polished target |
| `mobile-standard` | 393×852 | 3x | Mobile | Modern flagship phone (iPhone 15/16, Pixel 8 class); the primary mobile QA device |
| `tablet-portrait` | 744×1133 | 2x | Tablet | iPad mini/Air portrait; validates the sidebar-to-sheet and table-to-card thresholds right at `md:` |
| `tablet-landscape` | 1133×744 | 2x | Laptop (by width) | Confirms a tablet in landscape correctly receives the Laptop-tier layout, not a stretched Tablet one |
| `laptop` | 1280×800 | 2x | Desktop | MacBook Air-class logical resolution; validates the `xl:` KPI-row and full-column-table thresholds |
| `desktop` | 1440×900 | 2x | Desktop | Common external monitor; validates the `2xl:` max-width cap engaging correctly |
| `desktop-fhd` | 1920×1080 | 1x | Ultra Wide | Validates the `3xl:` Ask AI persistent rail |
| `ultrawide` | 2560×1080 | 1x | Ultra Wide | 21:9 panel; validates that extra width becomes margin/rail, never table over-stretch |

Every viewport in this matrix runs at both `locale: 'en'` (`dir="ltr"`) and `locale: 'ar-KW'` (`dir="rtl"`), and at both `color-scheme: light` and `dark`, per the platform's dark-mode commitment (`DESIGN_SYSTEM.md`, `DARK_MODE.md`).

## Playwright configuration

```ts
// playwright.config.ts
import { defineConfig, devices } from '@playwright/test';

const viewports = {
  'mobile-se': { width: 375, height: 667 },
  'mobile-standard': { width: 393, height: 852 },
  'tablet-portrait': { width: 744, height: 1133 },
  laptop: { width: 1280, height: 800 },
  desktop: { width: 1440, height: 900 },
  ultrawide: { width: 2560, height: 1080 },
} as const;

const locales = ['en', 'ar-KW'] as const;

export default defineConfig({
  projects: locales.flatMap((locale) =>
    Object.entries(viewports).map(([name, viewport]) => ({
      name: `${name}-${locale}`,
      use: {
        viewport,
        locale,
        colorScheme: 'light' as const,
        hasTouch: name.startsWith('mobile') || name.startsWith('tablet'),
        ...(name.startsWith('mobile') ? { ...devices['iPhone 14'], viewport } : {}),
      },
    })),
  ),
});
```

Each project name (`mobile-se-ar-KW`, `desktop-en`, and so on) appears directly in CI output and in Playwright's HTML report, so a failure is immediately legible as "which breakpoint, which language" without opening the trace.

## Visual regression sampling

Snapshotting the full viewport × locale × theme matrix (6 viewports × 2 locales × 2 themes = 24 combinations) against *every* screen in the product is combinatorially prohibitive to maintain; QAYD splits the obligation by layer:

| Layer | Coverage | Rationale |
|---|---|---|
| Shared components (`Button`, `DataTable`/`DataCardList`, `Sheet`, `Sidebar`, `BottomNav`, KPI card, `ChartFrame`) | Full matrix — all 6 viewports × 2 locales × 2 themes | These primitives are reused on every screen; a regression here is a regression everywhere, so the cost is paid once centrally |
| Screen-level integration tests (Dashboard, Trial Balance, Journal Entries, Invoices, Bank Reconciliation) | Reduced sample: `mobile-standard-en`, `mobile-standard-ar-KW`, `desktop-en`, `desktop-ar-KW` (light theme only) | Confirms the screen assembles shared components correctly at the two extremes in both directions; theme and the remaining viewports are already covered structurally by the component-level matrix |
| New/novel responsive patterns not yet covered by an existing shared component | Full matrix, once, at introduction | A genuinely new pattern (e.g., the first screen to introduce a new overlay type) has no prior coverage to lean on |

## Interaction and gesture tests

Gesture-dependent behavior (swipe-to-approve, pull-to-refresh, the RTL scroll-reset hook) is verified with Playwright's touch emulation rather than left to manual QA alone:

```ts
// e2e/approval-center.spec.ts
test('swipe right approves a pending request in LTR', async ({ page }) => {
  await page.goto('/en/dashboard');
  const card = page.getByRole('group', { name: /pending transfer/i });
  const box = await card.boundingBox();
  await page.touchscreen.tap(box!.x + 20, box!.y + 20);
  await page.mouse.move(box!.x + 20, box!.y + 20);
  await page.mouse.down();
  await page.mouse.move(box!.x + 160, box!.y + 20, { steps: 10 });
  await page.mouse.up();
  await expect(page.getByText(/approved/i)).toBeVisible();
});

test('sticky account column pins to the physical right edge in RTL', async ({ page }) => {
  await page.goto('/ar/accounting/trial-balance');
  const scrollRegion = page.getByTestId('trial-balance-scroll');
  const scrollLeft = await scrollRegion.evaluate((el) => el.scrollLeft);
  const scrollWidth = await scrollRegion.evaluate((el) => el.scrollWidth);
  const clientWidth = await scrollRegion.evaluate((el) => el.clientWidth);
  expect(scrollLeft).toBeGreaterThanOrEqual(scrollWidth - clientWidth - 2); // resting at logical start ⇒ max scrollLeft in RTL
});
```

Accessibility is checked at every viewport in the same run, not as a separate pass, since reflow and reordering are themselves accessibility-relevant (a screen reader user's tab order must match the visually-promoted mobile order, not the desktop DOM order alone — enforced by the `order-first` pattern in **Dashboard Reflow** operating on real DOM order, not a CSS-only illusion):

```ts
import AxeBuilder from '@axe-core/playwright';

test('dashboard has no automated a11y violations at mobile', async ({ page }) => {
  await page.goto('/en/dashboard');
  const results = await new AxeBuilder({ page }).include('main').analyze();
  expect(results.violations).toEqual([]);
});
```

## Manual and real-device QA

Headless Chromium under-tests two categories of bug this document cares about: Safari-specific viewport quirks (the dynamic toolbar that resizes on scroll, and `100vh`'s resulting inconsistency — every full-height mobile container in QAYD uses `100dvh`, not `100vh`, specifically because of this) and real touch/VoiceOver/TalkBack behavior. Every release therefore includes a manual pass on at minimum one physical mid-range Android device (Reverb WebSocket behavior under real network conditions, battery impact of the realtime dashboard) and one physical iOS device on Safari (safe-area insets, momentum scrolling, VoiceOver rotor navigation through a reflowed mobile dashboard), in addition to a BrowserStack sweep across the viewport matrix. A failure discovered only on real hardware is written back into the Playwright suite as a regression test before the release ships, so the automated matrix's coverage strictly grows over time rather than re-relying on manual discovery for the same class of bug twice.

## CI gating

| Check | Blocks merge? |
|---|---|
| Shared-component visual regression (full matrix) | Yes — a diff must be explicitly approved (baseline updated) or reverted |
| Screen-level visual regression (reduced sample) | Yes |
| Gesture/interaction Playwright specs | Yes |
| `axe-core` violations at any matrix viewport | Yes, for any violation at or above "serious" |
| BrowserStack real-device sweep | Advisory on every PR; blocking on the release-branch pipeline only |
| Manual physical-device pass | Required sign-off before any production release, tracked as a release checklist item, not a per-PR gate |

# Examples

The patterns above are demonstrated in isolation; this section shows them composed together on one real screen, plus a reference table of the Tailwind utility combinations QAYD's component library reuses often enough to standardize rather than reinvent per screen.

## Full example: Invoices list

```tsx
// app/(app)/sales/invoices/page.tsx
import { Container } from '@/components/shared/layout/container';
import { ResponsiveDataView } from '@/components/shared/data-table/responsive-data-view';
import { invoiceColumns } from './columns';

export default function InvoicesPage() {
  return (
    <Container>
      <div className="flex flex-col gap-4 py-6 md:flex-row md:items-center md:justify-between">
        <h1 className="text-[length:var(--text-heading)] font-semibold">{t('sales.invoices.title')}</h1>
        <div className="flex gap-2">
          <FiltersTrigger /> {/* below md: opens a Sheet; md and up: renders the filter bar inline */}
          <ExportMenu />     {/* below md: kebab DropdownMenu; md and up: two inline buttons */}
        </div>
      </div>
      <InvoicesTable />
      {/* Mobile-only floating action button; md and up, "New Invoice" lives inline in the header instead */}
      <CreateInvoiceFab className="md:hidden" />
    </Container>
  );
}
```

```tsx
// app/(app)/sales/invoices/invoices-table.tsx
'use client';

const invoicesQueryKeys = {
  list: (filters: InvoiceFilters) => ['sales', 'invoices', 'list', filters] as const,
};

function useInvoices(filters: InvoiceFilters) {
  return useQuery({
    queryKey: invoicesQueryKeys.list(filters),
    queryFn: () => api.get('/api/v1/sales/invoices', { params: toQueryParams(filters) }),
  });
}

export function InvoicesTable() {
  const [filters, setFilters] = useFiltersState();
  const { data, isPending } = useInvoices(filters);
  const canCreate = usePermission('sales.invoice.create'); // server-resolved boolean, never a viewport check

  if (isPending) return <InvoiceListSkeleton />;
  if (!data?.data.length) {
    return <EmptyState title={t('sales.invoices.empty.title')} action={canCreate && <CreateInvoiceButton />} />;
  }

  return (
    <ResponsiveDataView
      data={data.data}
      columns={invoiceColumns}
      onRowClick={(invoice) => router.push(`/sales/invoices/${invoice.id}`)}
    />
  );
}
```

`FiltersTrigger`, `ExportMenu`, and `CreateInvoiceFab`/`CreateInvoiceButton` are each a single component with the responsive branch inside them (per **Adaptive Patterns**), so this page composes four already-solved responsive problems (list-to-card, filter-bar-to-sheet, buttons-to-kebab, header-button-to-FAB) without a single ad hoc breakpoint check inside the page itself — the page only decides *what* to render, never *how* it reflows.

## Responsive Tailwind utility reference

| Purpose | Utility pattern | Used in |
|---|---|---|
| Table-to-card switch | `hidden md:block` / `md:hidden` | `ResponsiveDataView` (**Adaptive Patterns**) |
| Sidebar rail, laptop and up only | `hidden lg:flex` | `Sidebar` (**Adaptive Patterns**) |
| Bottom nav, mobile/tablet only | `md:hidden` | `BottomNav` (**Adaptive Patterns**) |
| 1 → 2 → 3 column form grid | `grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3` | Form layouts (**Forms On Mobile**) |
| 1 → 2 → 4 KPI row | `grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4` | Dashboard KPI row (**Dashboard Reflow**) |
| Mobile-first reorder | `order-first xl:order-none` | Urgent Actions, Approval Center (**Dashboard Reflow**) |
| Logical-start sticky column | `sticky start-0 z-10 bg-background` | Finance tables (**Finance Tables On Small Screens**) |
| Logical-start/end spacing | `ms-2 me-4`, `ps-4 pe-2` | Everywhere; physical `ml-*`/`mr-*` banned (**RTL + Responsive combined**) |
| Safe-area-aware fixed footer | `pb-[max(0.5rem,env(safe-area-inset-bottom))]` | `BottomNav`, sticky form action bar |
| Content max-width from 2xl | `max-w-full 2xl:max-w-[1440px] mx-auto` | `Container` (**Responsive Typography & Spacing**) |
| Touch-safe minimum target | `min-h-11 min-w-11` | Every interactive element (**Touch Targets & Gestures**) |
| Full-height mobile sheet, Safari-safe | `h-[92dvh]` (never `h-[92vh]`) | `ResponsiveOverlay` (**Adaptive Patterns**, **Edge Cases**) |
| RTL-mirrored directional icon | `rtl:rotate-180` | Chevrons, back arrows (**RTL + Responsive combined**) |
| Hover-only reveal, touch-safe fallback | `opacity-0 [@media(hover:hover)]:group-hover:opacity-100` (or the `hover:hover`/`hover:none` media pair shown in **Touch Targets & Gestures**) | Table row actions |

## Compact KPI card

A final composed example showing reflow (`col-span`), RTL-safe iconography, and tabular numerals together in one small, frequently-reused component:

```tsx
// components/dashboard/kpi-card.tsx
export function KpiCard({ label, value, trend, className }: KpiCardProps) {
  const isPositive = trend >= 0;
  return (
    <div className={cn('rounded-2xl border border-border bg-card p-4 md:p-5', className)}>
      <p className="text-[length:var(--text-caption)] text-muted-foreground">{label}</p>
      <p className="mt-1 text-[length:var(--text-heading)] font-semibold tabular-nums">{value}</p>
      <div className={cn('mt-2 flex items-center gap-1 text-[length:var(--text-label)]',
        isPositive ? 'text-success' : 'text-destructive')}>
        {/* TrendingUp always points toward positive growth visually; it is not a directional/RTL icon and must NOT be flipped */}
        {isPositive ? <TrendingUp className="size-4" /> : <TrendingDown className="size-4" />}
        <span className="tabular-nums">{formatPercent(Math.abs(trend))}</span>
      </div>
    </div>
  );
}
```

`TrendingUp`/`TrendingDown` are explicitly called out as icons that must **not** receive `rtl:rotate-180` — unlike a back-chevron or a breadcrumb separator, an up-trend arrow means "growth" in every writing direction, and mirroring it would misreport the data it represents. This is the same discipline as the chart time-axis exception in **RTL + Responsive combined**: not every visual element mirrors, and the ones that do not are documented explicitly rather than left to a blanket rule that would get this case wrong.

# Edge Cases

| Edge case | Risk if unhandled | QAYD resolution |
|---|---|---|
| Extremely long Arabic/English company or customer names on a mobile card title | Card layout breaks or text silently clips with no way to read the rest | `truncate` on the title with the full string always available via a `title` attribute and a long-press/tap-to-expand; never clip with no recovery path |
| Foldable/dual-screen devices (Galaxy Fold, Surface Duo) reporting a viewport spanning the seam | A component sized for the full unfolded width clips or misrenders in the folded-in-half state | No bespoke foldable/spanning-aware layout is built; every component is verified to remain usable down to a ~280–320px single-pane width, which structurally covers the folded state without dedicated code |
| OS/browser text-size boosting up to 200% | A `vw`-only fluid type scale silently ignores the user's accessibility preference because it tracks viewport width, not root font size | Every `clamp()` in **Responsive Typography & Spacing** includes a `rem` term, which is what makes boosted text actually grow the layout instead of being overridden by a viewport-locked maximum |
| On-screen keyboard consuming up to half the viewport during data entry | The focused amount/description field ends up hidden behind the keyboard, especially in landscape | Every form field's focus handler calls `scrollIntoView({ block: 'center' })`; full-height containers use `100dvh`, and keyboard height on iOS is read via the `visualViewport` API rather than trusted to `window.innerHeight`, which does not reliably shrink when the keyboard opens in Safari |
| Flaky/offline connectivity mid-form (warehouse basement, weak site signal) | A half-completed stock count or journal entry is lost, or a retried submission double-posts | Debounced local draft persistence to IndexedDB, TanStack Query mutation retry, and a client-generated `Idempotency-Key` reused across retries (**Forms On Mobile**) so a dropped connection loses no work and creates no duplicate |
| Very large monetary figures (`NUMERIC(19,4)`) overflowing a narrow card's numeric column | A large company's revenue figure wraps, truncates, or forces the card wider than its grid cell | Read-only summary displays may abbreviate (`KD 1.2M`) with the full-precision value in a tooltip/`aria-label`; any *editable* or *exported* value always shows full precision — abbreviation is a display-only affordance, never applied to a value a user is actively entering or to anything transmitted to the API |
| Multi-currency rows in one consolidated table (a multi-branch Trial Balance mixing KWD/SAR/AED via `currency_breakdown`) | Three currency columns per row is unworkable below `lg:`, and picking one currency to show silently hides real information | Collapses to the company's base currency by default at every tier below `lg:`, with an explicit "View by currency" toggle that expands the `currency_breakdown` JSONB into its own drill-down view rather than trying to fit it into the row |
| Sidebar-collapsed preference open in two browser tabs simultaneously | One tab shows a collapsed rail, the other an expanded one, for the same user session, which reads as a bug | The SSR cookie plus a `storage` event listener keeps `useSidebarStore` synchronized across tabs; realtime dashboard widget *data* is explicitly not synchronized the same way, since TanStack Query's cache and Reverb's own push already keep each tab's data correct independently |
| Notch/Dynamic Island/gesture-nav safe areas counted more than once | Double safe-area padding (once on the bottom nav container, again on a child) creates an oversized, obviously-wrong gap | `env(safe-area-inset-bottom)` is applied exactly once, at the outermost fixed container (`BottomNav`, the sticky form action bar), never additionally by a child |
| Old/low-end Android WebView (present on some GCC enterprise-locked devices) lacking `dvh` or modern CSS support | A layout relying on `100dvh` or container queries as load-bearing renders incorrectly with no fallback | An `@supports` feature query falls back to `100vh` where `dvh` is unsupported; container queries are used only as progressive enhancement on top of an already viewport-responsive layout, never as the sole mechanism for a pattern that must remain usable on older engines |
| Print/PDF export of a financial statement | Exporting "whatever the current breakpoint shows" produces an inconsistent, breakpoint-dependent document | Export/print rendering targets a fixed document width (A4/Letter) entirely independent of viewport, per **Images/Charts responsiveness** — the export path does not reuse the on-screen responsive components at all, it renders a dedicated print layout |
| RTL initial scroll position on a horizontally-scrolling finance table | Browsers do not uniformly rest an RTL scroll container at the visually-correct logical start on mount | `useRtlAwareScrollReset` explicitly sets `scrollLeft` based on `dir` (**RTL + Responsive combined**) rather than relying on default browser behavior, which is inconsistent across engines |

# End of Document

