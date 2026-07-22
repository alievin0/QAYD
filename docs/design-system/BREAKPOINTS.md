# Breakpoints — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: BREAKPOINTS
---

# Purpose

This document is the canonical breakpoint system for QAYD's Next.js 15 frontend: the six named
Tailwind stops and their pixel values, the five semantic device tiers they map onto, the
desktop-first-but-tablet-capable posture that shapes how each tier composes, the specific rule for how
the densest finance screens (Trial Balance, Reconciliation, General Ledger) degrade to read-only
summaries below a minimum usable width, the mobile/tablet/desktop composition differences, and where
container queries replace viewport queries.

It consolidates the breakpoint rules from
[../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md) and
[../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md) into one design-system reference. The
pixel values here are mirrored verbatim from those documents. For how the 12-column grid reflows at
each stop, see [GRID_SYSTEM.md](GRID_SYSTEM.md#responsive-column-reflow); for the full catalog of
adaptive patterns (table-to-card, sidebar-to-sheet, modal-to-sheet), see
[../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md#adaptive-patterns).

QAYD is a finance product before it is a consumer product, and that reframes "responsive." A Trial
Balance has eight to fourteen columns of `NUMERIC(19,4)` figures that cannot simply be dropped to fit
a phone; a journal entry needs the same balance validation at 375px as at 2560px. The governing
principle across every rule below: **responsiveness changes density, grouping, and input mechanics —
it never changes what capability a user has, and it never changes a validation rule.**

# The Breakpoint Scale

Tailwind's breakpoints are **min-width** media queries — a `md:` utility applies at 768px and above,
not "only at tablet size" — which is the foundation of the mobile-first cascade. QAYD extends the
default scale with one custom `3xl` tier for the ultra-wide monitors common on Finance/Treasury desks,
declared in Tailwind v4's CSS-first theme config rather than a JS config file:

```css
/* app/globals.css */
@import "tailwindcss";

@theme {
  /* Tailwind defaults, restated explicitly so the full scale lives in one file */
  --breakpoint-sm: 40rem;   /* 640px  */
  --breakpoint-md: 48rem;   /* 768px  */
  --breakpoint-lg: 64rem;   /* 1024px */
  --breakpoint-xl: 80rem;   /* 1280px */
  --breakpoint-2xl: 96rem;  /* 1536px */

  /* QAYD custom tier — ultra-wide and dual-monitor CFO/Owner desks */
  --breakpoint-3xl: 120rem; /* 1920px */
}
```

| Token | Min width | rem | Tailwind prefix |
|---|---|---|---|
| `base` | 0px | 0 | *(unprefixed)* |
| `sm` | 640px | 40rem | `sm:` |
| `md` | 768px | 48rem | `md:` |
| `lg` | 1024px | 64rem | `lg:` |
| `xl` | 1280px | 80rem | `xl:` |
| `2xl` | 1536px | 96rem | `2xl:` |
| `3xl` | 1920px | 120rem | `3xl:` |

Because these are `rem`-anchored and the root font size is fixed at 16px, a user's OS/browser text-size
preference does not silently break the scale; see Container Queries & Fluid Scaling below.

# The Five Device Tiers

Every design conversation about "the tablet layout" and every engineering conversation about `md:`
must be the same conversation. QAYD maps the six raw stops onto five named tiers, and every frontend
document, component prop, and Playwright project name uses these tier names.

| Tier | Tailwind prefixes | Min-width | Representative hardware | Why the line sits here |
|---|---|---|---|---|
| **Mobile** | *(base)*, `sm:` | 0 / 640px | iPhone SE (375), iPhone 15/16 (390–393), Pixel 8 (412), Galaxy S24 (360–412) | The unprefixed base is the entire surface for the narrowest supported device (360px). `sm:` is a *behavioral* line, not a device: below it a two-field row (date + amount) cannot fit at 16px body without truncation; above it, inline label+input pairs and two-up form grids become viable. |
| **Tablet** | `md:` | 768px | iPad mini/Air portrait (744–834), Android tablets, phones in landscape | QAYD's persistent-chrome threshold: below it, primary nav is an off-canvas `Sheet` plus a bottom tab bar; at and above it the sidebar becomes a permanent rail and the tab bar disappears. It is also the first tier a data table gains a second/third priority column instead of collapsing to cards. |
| **Laptop** | `lg:` | 1024px | iPad landscape, Windows laptops at 125–150% scaling (effective 1024–1279 CSS px) | The sidebar regains text labels (icon rail → 264px labeled sidebar), and list+detail two-pane layouts become viable because there is finally room for both panes above their own minimum widths. |
| **Desktop** | `xl:`, `2xl:` | 1280 / 1536px | 13"–16" laptops at 100%, 1080p/1440p external monitors | `xl:` reaches full dashboard column count (KPI row goes four across) and full priority-1–4 table columns with no horizontal scroll. `2xl:` adds no density: content caps at 1440px centered, because a Trial Balance stretched to 1536px+ is harder to read, not easier — the extra width becomes margin. |
| **Ultra Wide** | `3xl:` | 1920px | 32"+ 4K/5K monitors, dual-monitor desks, 21:9 panels | The one tier that adds a genuinely new region rather than more of the same: the AI Command Center's **Ask AI** panel becomes a persistent right-hand rail (`3xl:flex`) instead of an overlay sheet, because at this width it no longer competes with the main grid. |

# Desktop-First-but-Tablet-Capable Posture

QAYD's primary audience is at a desk: owners, CFOs, controllers, accountants, and auditors doing dense
financial work on laptops and external monitors. The product is *composed* for that audience — the
full sidebar, the multi-pane detail views, the four-across KPI row, the persistent AI rail are the
"home" layout, and the richest information density lives at `lg:`+.

At the same time, tablets are a real, first-class use case, not a courtesy: Finance Managers reviewing
reports on an iPad, warehouse employees doing stock counts on a shop-floor tablet. "Tablet-capable"
means the `md:`–`lg:` band is a designed, tested layout — sidebar becomes a sheet, tables keep their
tabular structure with a frozen identifier column, touch targets hold at 44px — not a scaled-down
desktop that happens to fit. The posture is therefore a *composition bias*, not an authoring order.

## Authoring is still mobile-first (min-width cascade)

The composition bias toward desktop does not change how classes are *authored*. QAYD writes every
component's base class list for the narrowest tier with no breakpoint prefix, then layers
`sm:`/`md:`/`lg:`/`xl:`/`2xl:`/`3xl:` overrides only where a property must change at a specific width.
This is a direct consequence of Tailwind's min-width cascade, and it is why a component with no `md:`
override at all is not a bug — it means the base layout already works at tablet size.

Rules:

1. Implement and verify at 360–390px first; add wider overrides additively.
2. Prefer additive min-width (`unprefixed → md: → lg:`) over max-width overrides (`max-md:hidden`).
   The one sanctioned `max-*`/`lg:hidden`-style use is a binary show/hide swap between two entirely
   different components for one slot (sidebar rail vs. bottom tab bar), never a progressive change.
3. Never introduce a client-side `isMobile` boolean to drive layout Tailwind can express — a Server
   Component renders `hidden md:flex` with no knowledge of the device, and the correct variant becomes
   visible once CSS parses, avoiding a hydration-mismatch flash.

## When a client hook is legitimate

Only **behavioral** differences CSS cannot express justify reading the tier in JS: a different
virtualizer row-height estimate, a swipe gesture, a `Dialog`-vs-`Sheet` primitive fork, an
`IntersectionObserver`-gated realtime subscription. One shared, SSR-safe hook covers all of them:

```tsx
// hooks/use-breakpoint.ts
'use client';
import { useSyncExternalStore } from 'react';

const BREAKPOINTS = { sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536, '3xl': 1920 } as const;
type Breakpoint = keyof typeof BREAKPOINTS;

function getActiveTier(): Breakpoint | 'base' {
  if (typeof window === 'undefined') return 'base'; // SSR snapshot — never guess a tier server-side
  const width = window.innerWidth;
  let active: Breakpoint | 'base' = 'base';
  for (const tier of Object.keys(BREAKPOINTS) as Breakpoint[]) {
    if (width >= BREAKPOINTS[tier]) active = tier;
  }
  return active;
}

/** For BEHAVIORAL branching only — never for layout, which is CSS's job. */
export function useBreakpoint() {
  return useSyncExternalStore(
    (cb) => { window.addEventListener('resize', cb); return () => window.removeEventListener('resize', cb); },
    getActiveTier,
    () => 'base' as const,
  );
}
```

The server snapshot (`'base'`) guarantees the server HTML and first client render agree, so there is
no hydration warning; the tier updates a frame after mount, which is correct for a behavioral decision
and forbidden for a layout one.

# Dense Screens Degrade to Read-Only Summaries

QAYD's densest numeric grids — Trial Balance, Bank Reconciliation, General Ledger, Balance Sheet, and
any multi-period comparison — cannot decompose into a card list without losing information an
accountant needs. Below a minimum usable width (the **Mobile** tier, `< md`/768px), these screens
degrade along a specific ladder rather than one universal trick, and the degraded state is explicitly
**read-only summary first, full grid on demand**.

## The degradation ladder (applied in order, per table)

1. **Sticky-first-column horizontal scroll** is the primary strategy at `md:`+ for genuinely tabular
   numeric grids: the account code/name column pins to the logical start edge; the money columns
   scroll horizontally inside their own `overflow-x-auto` region; the totals row stays sticky at the
   bottom. This keeps every figure to the fils.
2. **Below `md:`, a read-only summary card replaces the grid.** Each row becomes a card showing only
   the two or three highest-priority figures (for a Trial Balance: account code + name, closing
   debit, closing credit) as a right-aligned, `tabular-nums` definition list, with a
   "View full breakdown" affordance that opens the complete row (all periods, currency breakdown, the
   abnormal-balance flag) in a detail sheet. The narrow tier never renders every column smaller and
   smaller until illegible.
3. **Editing is not offered in the degraded state for reconciliation-class screens.** A Bank
   Reconciliation match grid below `md:` presents its matched/unmatched summary read-only; the actual
   match/unmatch actions route to a focused single-transaction sheet rather than attempting the
   full match grid on a phone. The *capability* is never removed — every action a role holds stays
   reachable, per the posture principle — but the multi-column workbench interaction is deferred to a
   width that can host it honestly.

```tsx
// components/accounting/trial-balance-card.tsx — the read-only summary below md:
<article className="rounded-md border border-ink-6 bg-card p-4">
  <header className="flex items-start justify-between gap-2">
    <div>
      <p className="text-xs text-ink-9">{line.account_code}</p>
      <h3 className="font-medium">{isRtl ? line.account_name_ar ?? line.account_name_en : line.account_name_en}</h3>
    </div>
    {line.is_abnormal_balance && <Badge variant="warning">{t('accounting.trialBalance.abnormal')}</Badge>}
  </header>
  <dl className="mt-3 grid grid-cols-2 gap-y-2 text-sm tabular-nums">
    <dt className="text-ink-9">{t('accounting.trialBalance.closingDebit')}</dt>
    <dd className="text-end">{formatMoney(line.closing_debit, companyCurrency)}</dd>
    <dt className="text-ink-9">{t('accounting.trialBalance.closingCredit')}</dt>
    <dd className="text-end">{formatMoney(line.closing_credit, companyCurrency)}</dd>
  </dl>
  <Button variant="ghost" size="sm" className="mt-2 w-full min-h-11" onClick={() => openLineDetail(line)}>
    {t('common.viewFullBreakdown')} <ChevronStart className="size-4" />
  </Button>
</article>
```

## What breakpoints are never used for

- **Capability.** A permission check (`can('bank.transfer')`, resolved server-side) and a viewport
  check (`md:hidden`) answer unrelated questions and are kept syntactically distinct. Wrapping a
  control a user *has* permission for in a breakpoint class that removes it below a width is a P1
  defect — every capability a role holds must be reachable at every tier, even if it takes an extra
  tap through an overflow menu or sheet.
- **Locale.** An `md:` class applies identically under `dir="ltr"` and `dir="rtl"`; only which logical
  edge is "start" changes. See
  [../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md#rtl--responsive-combined).
- **Validation.** The same Zod schema validates a journal entry at 375px and 2560px; a mobile form is
  never a "lighter" version with looser rules.

# Mobile vs. Tablet vs. Desktop Composition

| Concern | Mobile (base–sm) | Tablet (md) | Laptop → Ultra Wide (lg → 3xl) |
|---|---|---|---|
| Primary nav | Bottom tab bar (4–5 destinations) + hamburger opening a full-nav `Sheet` | Same bottom tab bar + `Sheet`; **no** narrow icon rail | Persistent sidebar: labeled 264px (`expanded`) or 72px icon rail (`collapsed`), user-toggled with `Cmd/Ctrl+B` |
| Top app bar | Back chevron + page title; search collapses to an icon → full-screen overlay; company switcher moves into the nav sheet | Same, less collapsed | Full breadcrumb + inline `⌘K` search + company switcher + notifications + user menu |
| List screens | Priority-1 fields as stacked cards; "View" opens detail | Table returns with horizontal scroll, priority columns | Full table, all default columns, generous gutters |
| Dense numeric grids | Read-only summary cards (see ladder above) | Sticky-first-column horizontal scroll, totals pinned | Full grid, every priority-1–4 column, no horizontal scroll |
| Dashboard | 1 widget per row; KPI strip is a snap carousel; urgent content promoted `order-first` | KPI 2-up; bento begins | Bento grid; KPI 4-up at `xl:`; Ask AI persistent rail at `3xl:` |
| Overlays | Full-height bottom/end `Sheet` (`h-[92dvh]`) | Same | Centered `Dialog` from `lg:` up |
| Forms | Single column, `min-h-11` targets, sticky bottom action bar | 1→2 column grid | 2→3 column grid; actions inline in the form header |
| Touch targets | 44px minimum everywhere; 56px table rows | 44px minimum; 56px rows | 40px dense rows with hover affordances (mouse precision) |

The tablet column deliberately does **not** attempt a "narrow rail" intermediate on 768–1023px — pilot
testing found a 72px icon rail left too little room for the two-pane list/detail layouts that band is
otherwise wide enough for, so tablet gets the sheet, and the rail (expanded vs. collapsed) only exists
from `lg:` up.

# Container Queries & Fluid Scaling

Viewport breakpoints decide page-level layout; **container queries** (`@container`) decide
component-level layout, because a widget's correct form depends on its own slot width, not the
viewport. A KPI card is narrower in a 3-column grid than full-width on a detail page than dropped into
the AI rail — all three are the same component responding to `@container`, not three viewport variants.
Container queries are used only as progressive enhancement over an already viewport-responsive layout,
never as the sole mechanism for a pattern that must remain usable on older engines (an `@supports`
fallback covers legacy GCC enterprise WebViews lacking `dvh` or `@container`).

Type is fluid *within* the fixed breakpoint frame. The root font size is fixed at 16px at every tier;
each type role is a `clamp()` whose min and max are both `rem`-anchored, so the scale grows with the
viewport **and** still honors a user's browser text-size preference — every formula adds a `rem` term
to its `vw` term, because a `vw`-only clamp silently ignores OS-level "larger text." Full type scale is
in [../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md#responsive-typography--spacing).

# Testing Posture

Responsiveness is verified against a fixed viewport matrix, not arbitrary widths, and every viewport
runs at both locales and both themes so a mobile-only RTL regression is caught with the same severity
as a desktop one:

| Name | Size (CSS px) | Tier | Purpose |
|---|---|---|---|
| `mobile-floor` | 320×568 | Mobile | Absolute minimum — must not visually break (no horizontal scroll, no clipped text) |
| `mobile-se` | 375×667 | Mobile | Smallest fully-polished target |
| `mobile-standard` | 393×852 | Mobile | Primary mobile QA device |
| `tablet-portrait` | 744×1133 | Tablet | Validates sidebar-to-sheet and grid-to-summary thresholds at `md:` |
| `tablet-landscape` | 1133×744 | Laptop (by width) | Confirms a landscape tablet gets the Laptop layout, not a stretched Tablet one |
| `laptop` | 1280×800 | Desktop | Validates `xl:` KPI row and full-column tables |
| `desktop` | 1440×900 | Desktop | Validates the `2xl:` max-width cap |
| `desktop-fhd` | 1920×1080 | Ultra Wide | Validates the `3xl:` Ask AI persistent rail |
| `ultrawide` | 2560×1080 | Ultra Wide | Validates extra width becoming margin, never table over-stretch |

The minimum supported width is **320px**; below 360px the layout must remain unbroken (padding drops to
16px, dense grids fall back to horizontal scroll) though it is not required to be equally polished.
Full-height mobile containers use `100dvh`, never `100vh`, for Safari's dynamic-toolbar behavior.

# Do & Don't

| Do | Don't |
|---|---|
| Author mobile-first (base classes, additive `md:`/`lg:` overrides) | Write desktop-first and cram a 14-column grid down to a phone afterward |
| Name the tier (Mobile/Tablet/Desktop/Ultra Wide) in design + code | Refer to a raw pixel number in a design conversation |
| Degrade dense grids to read-only summary cards below `md:` | Shrink financial figures column-by-column until illegible |
| Keep permission checks separate from viewport checks | Use `hidden md:block` as a substitute for an authorization check |
| Reach for `useBreakpoint()` only for behavioral forks (Dialog vs. Sheet) | Drive layout with a client-side `isMobile` boolean |
| Use `@container` for widget-level reflow | Assume a widget knows the viewport width |
| Give tablet the nav `Sheet`, not a 72px rail | Add a narrow icon rail on 768–1023px |
| Anchor every fluid `clamp()` with a `rem` term | Ship a `vw`-only type scale that ignores OS text-size |

# End of Document
