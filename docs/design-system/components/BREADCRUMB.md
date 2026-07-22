# Breadcrumb — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / BREADCRUMB
---

# Purpose

This document specifies the **Breadcrumb** as an atomic design-system component — the trail of ancestor
links that answers "where am I" from the [Topbar](./TOPBAR.md) band (compact, `md`+) and from the top of
every content page (`PageHeader`, all breakpoints). It owns the trail's anatomy, its separator, its
truncation and overflow-collapse behavior, its token surface, its ARIA `breadcrumb` contract, and its
direction handling. It does **not** own where the trail's *data* comes from — the route-segment
configuration and the `generateBreadcrumb(params, data)` derivation for dynamic segments are application
concerns in [`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md). This spec renders a
`BreadcrumbItem[]`; that document produces it.

The Breadcrumb is built from tokens in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale
(`ink-9` for ancestor links, `ink-11` for the current segment), the `text-sm` type step, and `radius-md`
for the overflow control. **A crumb references a token, never a literal.**

One structural rule anchors the component: **the trail is module-rooted and its last segment is never a
link.** A journal entry descended from Accounting, not from the home screen, so the trail never starts at
`/dashboard`; and the final segment carries `aria-current="page"` and renders as plain text, because it
names the page you are already on.

> **Token reconciliation.** The app-level `Breadcrumbs` component shows draft names (`text-ink-500`,
> `text-ink-950`). This spec uses the **canonical** names from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md):
> ancestor links are `text-ink-9`, the current segment is `text-ink-11`.

# Anatomy

An ordered list of segments joined by a chevron separator, wrapped in its own `<nav aria-label="Breadcrumb">`
landmark so assistive tech lists it separately from the Sidebar's "Primary navigation."

```
┌────────────────────────────────────────────────────────────┐
│  Accounting  ›  Journal entries  ›  JE-2026-07-0482         │
└────────────────────────────────────────────────────────────┘
   ↑ link         ↑ link              ↑ current (plain text, aria-current="page")
   ink-9          ink-9   sep ink-7   ink-11, font-medium
```

Parts and their fixed roles:

| Part | Element | Role |
|---|---|---|
| Landmark | `<nav aria-label="Breadcrumb">` | Distinct from the Sidebar landmark |
| List | `<ol>` | Ordered — position carries meaning (hierarchy depth) |
| Ancestor segment | `<li><a>` | A real link to that ancestor route; `text-ink-9`, underline on hover |
| Separator | `ChevronRight`, `aria-hidden` | Between segments only, never leading or trailing; `text-ink-7` |
| Current segment | `<li><span aria-current="page">` | Plain text, `text-ink-11`, `font-medium`; never a link |
| Overflow control | `<li><button>…</button>` | Appears only when the trail is collapsed; expands the hidden ancestors |

A `BreadcrumbItem` is either a translation-keyed segment (`labelKey`) or a resolved literal (`label`, e.g. a
journal number that is data, not a translation), each with an `href`:

```ts
// types/nav.ts — shape owned by NAVIGATION_SYSTEM.md; rendered by this primitive
export interface BreadcrumbItem {
  labelKey?: string; // next-intl key for a static segment (module, sub-section)
  label?: string;    // resolved literal for a dynamic segment (an entry number, a customer name)
  href: string;
}
```

# Variants

One component with density and overflow variants — never a separate mobile trail.

| Variant | Where | Behavior |
|---|---|---|
| **Full trail** | `PageHeader` (all breakpoints), Topbar (`md`+) | Every ancestor segment plus the current segment, separated by chevrons |
| **Collapsed (overflow)** | Any host when the trail exceeds the available inline width | Middle ancestors fold behind a single `…` overflow control; the **first** segment (module root) and the **last** (current) always stay visible |
| **Page title (degrade)** | Topbar below `md` | The trail is replaced by the current segment alone as a page title — a multi-level trail does not fit a phone band. Rendered by a sibling `PageTitle`, fed the same `items`, not a second trail |

The invariant: **the current segment is never truncated away.** It is the one segment that answers "where am
I," so overflow collapse always sacrifices middle ancestors first, never the head or the tail.

## Truncation vs. collapse — two distinct mechanisms

The trail defends its width with two independent, layered mechanisms, applied in order:

1. **Per-segment truncation.** Every segment is `min-w-0 truncate`, so a single very long label (a 60-character
   legal-entity name, a long customer name) ellipsizes *within its own segment* without pushing siblings off
   the band. The full text stays available via the link's own hover title.
2. **Whole-segment collapse.** When the *number* of segments — not their individual length — exceeds
   `maxItems`, the middle ancestors fold behind a single `…` overflow control (`MoreHorizontal`), keeping the
   first (module root) and the last two segments. Clicking `…` expands the hidden ancestors in place.

The two never fight: truncation handles "one label is long," collapse handles "the trail is deep." A trail
that is both long-labelled *and* deep applies both — collapsed to head + tail, each of those still ellipsizing
if needed — and the current segment survives both passes intact.

# Props / API

## `Breadcrumb`

| Prop | Type | Required | Description |
|---|---|---|---|
| `items` | `BreadcrumbItem[]` | yes | The resolved trail, module-rooted, current segment last. The component renders it; it does not derive it. |
| `className` | `string` | no | Layout hook (e.g. `hidden md:flex` when Topbar-hosted). |
| `maxItems` | `number` | no, default `4` | Threshold above which middle ancestors collapse behind the overflow control. First and last are always kept. |

The component is intentionally presentational and side-effect-free — no data fetching, no `usePathname`. A
dynamic final segment that is still resolving is passed as an `items` entry whose `label` is a `Skeleton`
placeholder by the host, so the trail renders progressively without the Breadcrumb owning any async state.

# States

Every state ships in full light/dark and LTR/RTL parity.

| State | Treatment | Token |
|---|---|---|
| **Rest — ancestor link** | `text-ink-9`, no underline | `text-ink-9` |
| **Hover — ancestor link** | `text-ink-11` + underline | `hover:text-ink-11 hover:underline` |
| **Focus (keyboard)** | `focus-visible` 2px `accent` ring, 2px offset | `ring-[--qayd-accent]` |
| **Current segment** | `text-ink-11`, `font-medium`, `aria-current="page"`, not focusable | `text-ink-11` |
| **Separator** | `ChevronRight`, `text-ink-7`, `aria-hidden` | `text-ink-7` |
| **Loading (dynamic final segment)** | The last segment shows an inline `Skeleton` chip while `generateBreadcrumb`'s fetch runs; static ancestors render immediately, never blocked | `bg-ink-3` |
| **Error (dynamic segment 404)** | The trail truncates one segment early rather than showing a broken `[entryId]` label; the content region owns the not-found state | — |
| **Overflow collapsed** | Middle ancestors fold behind a `…` button; clicking expands them inline | `text-ink-9` |

A breadcrumb has no genuine empty state — it always carries at least the current route.

```tsx
// components/ui/nav/breadcrumb.tsx — the design-system trail primitive (canonical tokens)
import Link from 'next/link';
import { ChevronRight, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { useTranslations } from 'next-intl';
import type { BreadcrumbItem } from '@/types/nav';
import { cn } from '@/lib/utils';

interface BreadcrumbProps {
  items: BreadcrumbItem[];
  className?: string;
  maxItems?: number;
}

export function Breadcrumb({ items, className, maxItems = 4 }: BreadcrumbProps) {
  const t = useTranslations();
  const [expanded, setExpanded] = useState(false);

  // Collapse middle ancestors when the trail is long: always keep the first (module root) and the last two.
  const collapsed = !expanded && items.length > maxItems;
  const shown = collapsed ? [items[0], ...items.slice(-2)] : items;
  const overflowAfterFirst = collapsed;

  return (
    <nav aria-label={t('nav.breadcrumb')} className={cn('flex min-w-0 items-center text-sm text-ink-9', className)}>
      <ol className="flex min-w-0 items-center gap-1.5">
        {shown.map((item, i) => {
          const isLast = i === shown.length - 1;
          const label = item.labelKey ? t(item.labelKey) : item.label;
          return (
            <li key={item.href} className="flex min-w-0 items-center gap-1.5">
              {i > 0 && (
                <ChevronRight className="h-3.5 w-3.5 shrink-0 text-ink-7 rtl:rotate-180" aria-hidden />
              )}
              {/* the '…' overflow control sits between the first segment and the tail */}
              {overflowAfterFirst && i === 1 && (
                <>
                  <button
                    type="button"
                    onClick={() => setExpanded(true)}
                    aria-label={t('nav.showFullTrail')}
                    className="rounded-md px-1 text-ink-9 hover:text-ink-11 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[--qayd-accent] focus-visible:ring-offset-2"
                  >
                    <MoreHorizontal className="h-3.5 w-3.5" />
                  </button>
                  <ChevronRight className="h-3.5 w-3.5 shrink-0 text-ink-7 rtl:rotate-180" aria-hidden />
                </>
              )}
              {isLast ? (
                <span className="truncate font-medium text-ink-11" aria-current="page">{label}</span>
              ) : (
                <Link
                  href={item.href}
                  className="truncate hover:text-ink-11 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[--qayd-accent] focus-visible:ring-offset-2"
                >
                  {label}
                </Link>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
```

# Tokens Used

| Element | Token | Utility |
|---|---|---|
| Ancestor link (rest) | `ink-9` | `text-ink-9` |
| Ancestor link (hover) | `ink-11` | `hover:text-ink-11` |
| Current segment | `ink-11` | `text-ink-11 font-medium` |
| Separator chevron | `ink-7` | `text-ink-7` |
| Overflow `…` control | `ink-9` → `ink-11` | `text-ink-9 hover:text-ink-11` |
| Focus ring | `accent` | `ring-[--qayd-accent]` |
| Loading chip | `ink-3` | `bg-ink-3` |
| Overflow control radius | `radius-md` | `rounded-md` |
| Type step | `text-sm` (14px) | `text-sm` |
| Segment gap | 6px | `gap-1.5` |

The separator uses `ink-7` (one step lighter than the ancestor-link `ink-9`) so it reads as connective
punctuation, never competing with the segments themselves. No brass `accent` appears in the resting trail —
a breadcrumb is orientation, not a primary action; `accent` shows only as the focus ring.

# Accessibility

| Concern | Contract |
|---|---|
| Landmark | `<nav aria-label="Breadcrumb">` — its own landmark, distinct from the Sidebar's "Primary navigation," so a screen-reader user lists them separately. |
| Ordered structure | An `<ol>` (not a `<ul>`) — segment order encodes hierarchy depth, which is ordered information. Each segment is an `<li>`. |
| Current page | The last segment is a `<span aria-current="page">`, not a link — assistive tech announces "current page," and it is correctly skipped as a navigation target. |
| Separator is decorative | The `ChevronRight` is `aria-hidden`, so a screen reader reads "Accounting, Journal entries, JE-2026-07-0482," not "Accounting chevron Journal entries chevron…". |
| Overflow control | The `…` collapse is a real `<button>` with an `aria-label` ("Show full trail"), keyboard-activatable; expanding reveals the hidden `<li>`s in place. |
| Focus indicator | `focus-visible` only, 2px `accent` ring, 2px offset — the platform's standard, shared with `Button` and `NavItem`. |
| Keyboard | Each ancestor link is a native `<a>` in the tab order; the current segment is not focusable (nothing to activate). |

# Theming, Dark Mode & RTL

**Tokens only.** No `dark:`-raw-color variant appears; every color is a semantic utility that remaps at the
variable layer.

**Dark mode.** Ancestor links (`ink-9` → `#A39878`), the current segment (`ink-11` → `#E4DEC9`), and the
separator (`ink-7` → `#58503A`) all remap automatically; the hover-to-`ink-11` treatment stays legible in
both themes because both endpoints are token steps calibrated for their canvas.

**RTL.** The trail is the canonical case for the design system's **one mirrored icon**. Because the trail
uses only logical layout (`flex`, `gap-1.5`, `text-start` implied), the reading order reverses with zero
conditional logic: the module root sits at the visual right, the current segment at the visual left, and
segments flow right-to-left. The **separator is handled explicitly** — `ChevronRight` gets `rtl:rotate-180`
(shown in the TSX), because a chevron encodes "the next item is *forward* in the hierarchy," which is
visually leftward in RTL. It is a *mirrored* icon, not a *re-chosen* one: the same glyph, rotated, because
the meaning is directional. (Contrast the disclosure chevron in [`NAVIGATION.md`](./NAVIGATION.md), which
points *down* and does **not** mirror, because it encodes vertical disclosure, not horizontal hierarchy.)

Two more RTL rules the trail inherits from platform convention:

- **Numerals in a segment** — a journal number like `JE-2026-07-0482`, an invoice id — stay Western Arabic,
  LTR-shaped, exactly like the `Amount` primitive; a number reading right-to-left digit-by-digit is a
  comprehension hazard, not a translation.
- **A Latin-script literal in an Arabic shell** (a customer name, an entity id) is wrapped in `dir="auto"`
  so the browser resolves its direction per-string, rather than inheriting the ambient `dir="rtl"`.

# Do / Don't

| Do | Don't |
|---|---|
| Root the trail at the module (Accounting), never at Dashboard. | Start every trail at `/dashboard` — a journal entry did not descend from the home screen. |
| Render the last segment as plain text with `aria-current="page"`. | Make the current segment a link, or omit `aria-current`. |
| Mark the separator `aria-hidden` and mirror it with `rtl:rotate-180`. | Read the separator to screen readers, or leave the chevron un-mirrored in Arabic. |
| Collapse middle ancestors behind a `…` control; keep first and last. | Truncate the current segment away — it is the one that answers "where am I." |
| Use `<ol>` for the list. | Use `<ul>` — segment order is meaningful, not a set. |
| Pin numerals and Latin literals `dir="ltr"` / `dir="auto"`. | Let a journal number reverse digit order in an RTL trail. |
| Keep the resting trail token-neutral (`ink-9`/`ink-11`/`ink-7`). | Color a segment with brass `accent` — the trail is orientation, not a primary action. |

# Usage & Composition

The `Breadcrumb` primitive is consumed in two hosts, both fed the same `items` produced by the app layer.

```tsx
// Topbar slot 2 (md+) — compact trail; a bare page title below md — TOPBAR.md
<Breadcrumb items={breadcrumb} className="hidden md:flex" />
<PageTitle items={breadcrumb} className="md:hidden" />
```

```tsx
// PageHeader — the trail plus the page H1 at the top of every content route — NAVIGATION_SYSTEM.md
export function PageHeader({ breadcrumb, title, description, actions }: PageHeaderProps) {
  return (
    <div className="flex flex-col gap-3 border-ink-6 px-6 py-5 [border-block-end-width:1px] md:flex-row md:items-center md:justify-between">
      <div className="space-y-1">
        <Breadcrumb items={breadcrumb} className="md:hidden" />
        <h1 className="font-display text-2xl font-semibold text-ink-11">{title}</h1>
        {description && <p className="text-sm text-ink-9">{description}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}
```

Composition rules:

- **The primitive renders, the app derives.** `generateBreadcrumb(params, data)` and the route-segment
  config live in the app layer; `Breadcrumb` never fetches or computes a trail.
- **Title matches the tail.** A `PageHeader`'s `title` always textually matches the trail's last segment —
  no page titles itself something the trail did not already say.
- **The action slot is permission-gated by omission.** A `PageHeader`'s primary action (e.g. "New Journal
  Entry") is *omitted*, not disabled, when the viewer lacks the create permission — consistent with the nav
  system's hide-don't-disable rule; the Breadcrumb primitive itself carries no actions.
- **Same data, two densities.** The Topbar trail and the `PageHeader` trail are the same component fed the
  same `items`; only `className` and the `md` breakpoint differ.

For the breadcrumb-derivation contract, the `generateBreadcrumb` pattern, and the deep-link rules that make
each segment's `href` shareable, see [`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md).
For the band that hosts the compact trail, see [`TOPBAR.md`](./TOPBAR.md); for the shared nav-item and its
non-mirroring disclosure chevron, [`NAVIGATION.md`](./NAVIGATION.md); for tokens,
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# End of Document
