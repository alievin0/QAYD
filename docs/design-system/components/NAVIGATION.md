# Navigation — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / NAVIGATION
---

# Purpose

This document specifies the **navigation primitives** shared across QAYD's chrome — chiefly the `NavItem`,
the single row component the [Sidebar](./SIDEBAR.md), the [Topbar](./TOPBAR.md)'s mobile drawer, and the
command palette all render, plus the `isNavItemActive` active-route matcher and the roving-tabindex keyboard
contract that binds a list of them into one accessible tree. It is the atomic layer beneath the two shell
surfaces: rather than each surface hand-rolling a row, they compose one `NavItem` whose anatomy, states,
tokens, and keyboard model are fixed here.

It does **not** own the module map, the permission-key grammar, the `NAV_TREE` data, or the command-palette
search grammar — those are application concerns in
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md). This spec owns the *row and its
matching/keyboard behavior as reusable primitives*; that document owns the *system* they plug into.

Every primitive here is built from tokens in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12`
scale, the brass `accent` family (`accent-subtle` tint + `accent` text/bar), `radius-md`, and the motion
durations. **A row references a token, never a literal.**

Two platform rules bind these primitives. **RBAC is a UX courtesy in the client, a hard boundary on the
server** — a `NavItem` never filters; it trusts that the caller removed anything the user cannot see, and
the server re-checks every request regardless. And **the AI entry is a navigation, never an execution** — an
AI nav item hands off to a real page; it never runs a sensitive action on a click.

> **Token reconciliation.** App-level docs show draft names (`bg-accent-100`, `text-accent-700`,
> `ring-accent-600`, `text-ink-500`). This spec uses the **canonical** names from
> [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): active is `bg-accent-subtle` + `text-accent`, focus is
> `ring-[--qayd-accent]`, secondary text is `text-ink-9`.

# Anatomy

A `NavItem` is a horizontal flex row: a `16px` leading icon, a `truncate` label, and — for a module with
children — a trailing disclosure chevron. It renders one of two node shapes, a `NavModule` (top-level, with
an optional `children` array) or a `NavLeaf` (a plain destination).

```
Module with children (expanded):        Leaf:
┌──────────────────────────────┐        ┌──────────────────────────────┐
│ [icon]  Accounting        ▾ │        │ [icon]  Dashboard           │
├──────────────────────────────┤        └──────────────────────────────┘
│     Chart of Accounts       │  ← child NavItem, depth=1, ps-9 logical indent
│     Journal Entries         │
└──────────────────────────────┘
    ↑         ↑              ↑
  ink-9    ink-11        chevron ink-9   (active: accent-subtle bg + accent text + inline-start bar)
```

Parts and their fixed roles:

| Part | Element | Role |
|---|---|---|
| Leading icon | `lucide-react` glyph, `16px`, `shrink-0` | Module/leaf identity; the sole identity in the collapsed rail (so it always keeps a `title`/`aria-label`) |
| Label | `<span class="truncate text-start">` | The localized `next-intl` label; truncates rather than wraps |
| Disclosure chevron | `ChevronDown`, `rotate-180` when expanded | Present only on a module with children; `aria-hidden` |
| Active bar | `2px` inline-start bar | Redundant, non-color-only active signal beside `aria-current` |

# Variants

`NavItem` is one component with presentation variants driven by props, not forks.

| Variant | Prop | Behavior |
|---|---|---|
| **Module (expandable)** | `node` is a `NavModule` with `children` | Renders the disclosure chevron and `aria-expanded`; expanded, indents its children beneath it |
| **Module (leaf)** | `node` is a `NavModule` with no `children` (Dashboard, AI) | A plain row that is itself the destination |
| **Leaf child** | `depth={1}` | Rendered under an expanded parent; logical `ps-*` indent, no icon column shift |
| **Collapsed — with children** | `collapsed` + has children | Row is a `Popover` trigger; children fly out in a flyout that renders the *same* `NavItem` recursively with `collapsed={false}` |
| **Collapsed — leaf** | `collapsed` + no children | Row gets a `Tooltip` label + native `title`, so an icon-only row is never unnamed |

The discipline: **one implementation.** The collapsed-rail flyout renders the *same* `NavItem` recursively —
there is no second "flyout child" component to keep in sync. A child row in the flyout looks and behaves
identically to a child row in the expanded rail.

# Props / API

## `NavItem`

| Prop | Type | Required | Description |
|---|---|---|---|
| `node` | `NavModule \| NavLeaf` | yes | The already-permission-filtered node. A `NavItem` never filters. |
| `collapsed` | `boolean` | yes | Drives icon-only rendering + `Tooltip`/`Popover` flyout. |
| `activePath` | `string` | yes | The current `usePathname()`; passed down (not read per-item) so all rows compute activeness against one value in one render. |
| `depth` | `0 \| 1` | no, default `0` | `1` for a child leaf; controls the logical `ps-*` indent step. |

## Node shapes (consumed, not owned)

```ts
// lib/nav/nav-tree.ts — shapes owned by NAVIGATION_SYSTEM.md; shown here for the NavItem contract
export interface NavLeaf {
  id: string;
  labelKey: string;          // next-intl key, e.g. 'nav.accounting.journalEntries'
  href: string;
  permission: string | null; // null = always visible once the parent is visible
}
export interface NavModule extends Omit<NavLeaf, 'permission'> {
  icon: string;              // lucide-react icon name
  permission: string | null;
  children?: NavLeaf[];
}
```

## `isNavItemActive` / `isModuleActive`

The active-match helpers are part of the primitive's contract — a row's active state is *derived* from
these, never stored.

```ts
// lib/nav/active-match.ts
/** True when `href` is the current route or an ANCESTOR SEGMENT of it — never a bare string prefix. */
export function isNavItemActive(href: string, activePath: string): boolean {
  if (href === activePath) return true;
  // segment boundary: the char after `href` must be '/', so '/x' never spuriously matches '/x-archive'.
  return activePath.startsWith(href) && activePath.charAt(href.length) === '/';
}

/** A module is active if it OR any child is active; used to auto-expand + highlight. */
export function isModuleActive(module: NavModule, activePath: string): boolean {
  if (isNavItemActive(module.href, activePath)) return true;
  return module.children?.some((c) => isNavItemActive(c.href, activePath)) ?? false;
}
```

# States

Every state ships in full light/dark and LTR/RTL parity.

| State | Treatment | Token |
|---|---|---|
| **Rest** | Label `text-ink-11`, icon/chevron `text-ink-9`, transparent bg | `text-ink-11`, `text-ink-9` |
| **Hover** | Background fills to the hover step | `hover:bg-ink-4` |
| **Active** | `bg-accent-subtle`, label `text-accent`, `font-medium`, `2px` inline-start `accent` bar, `aria-current="page"` | `bg-accent-subtle`, `text-accent` |
| **Focus (keyboard)** | `focus-visible` 2px ring at `accent`, 2px offset — never bare `:focus` | `ring-[--qayd-accent]` |
| **Expanded (module)** | Chevron `rotate-180`; children region mounts with a `motion.base` height/opacity transition | `text-ink-9` |
| **Disabled (plan/integration gate)** | `text-ink-8`, `cursor-not-allowed`, `aria-disabled`, `Lock` glyph, tooltip reason | `text-ink-8` |

Active-state derivation and auto-expand:

- A module with children **auto-expands** on mount when active, so the current page's siblings are visible.
- A user may manually collapse it; that choice is respected for the session, but navigating *into* a
  different module's child re-expands that module.
- The active highlight **never flickers on navigation** because the row is not remounted — only `activePath`
  changes.

```tsx
// components/ui/nav/nav-item.tsx — the shared row primitive (canonical tokens)
'use client';

import { useState, type KeyboardEvent } from 'react';
import Link from 'next/link';
import * as Icons from 'lucide-react';
import { useTranslations, useLocale } from 'next-intl';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { isNavItemActive, isModuleActive } from '@/lib/nav/active-match';
import type { NavModule, NavLeaf } from '@/lib/nav/nav-tree';
import { cn } from '@/lib/utils';

interface NavItemProps {
  node: NavModule | NavLeaf;
  collapsed: boolean;
  activePath: string;
  depth?: 0 | 1;
}

export function NavItem({ node, collapsed, activePath, depth = 0 }: NavItemProps) {
  const t = useTranslations();
  const locale = useLocale();
  const Icon = Icons[(node as NavModule).icon as keyof typeof Icons] as Icons.LucideIcon;
  const hasChildren = 'children' in node && (node as NavModule).children?.length;
  const active = hasChildren
    ? isModuleActive(node as NavModule, activePath)
    : isNavItemActive(node.href, activePath);
  const [expanded, setExpanded] = useState(active && Boolean(hasChildren));
  const label = t(node.labelKey);

  // Direction-aware expand/collapse keys; ↑/↓ roving movement lives in the <ul> via useRovingIndex.
  function onKeyDown(e: KeyboardEvent) {
    const openKey = locale === 'ar' ? 'ArrowLeft' : 'ArrowRight';
    const closeKey = locale === 'ar' ? 'ArrowRight' : 'ArrowLeft';
    if (e.key === openKey && hasChildren) setExpanded(true);
    if (e.key === closeKey && expanded) setExpanded(false);
  }

  const row = (
    <Link
      href={node.href}
      tabIndex={active ? 0 : -1}
      aria-current={active && !hasChildren ? 'page' : undefined}
      aria-expanded={hasChildren ? expanded : undefined}
      title={collapsed ? label : undefined}
      onKeyDown={onKeyDown}
      className={cn(
        'flex items-center gap-2.5 rounded-md py-2 text-sm transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[--qayd-accent] focus-visible:ring-offset-2',
        collapsed ? 'mx-2 justify-center px-0' : 'mx-2 ps-3 pe-2',
        depth === 1 && !collapsed && 'ps-9',                         // logical child indent
        active ? 'bg-accent-subtle font-medium text-accent' : 'text-ink-11 hover:bg-ink-4',
      )}
    >
      <Icon className="h-4 w-4 shrink-0" />
      {!collapsed && <span className="flex-1 truncate text-start">{label}</span>}
      {!collapsed && hasChildren && (
        <Icons.ChevronDown className={cn('h-3.5 w-3.5 shrink-0 text-ink-9 transition-transform', expanded && 'rotate-180')} aria-hidden />
      )}
    </Link>
  );

  if (collapsed && hasChildren) {
    return (
      <li>
        <Popover>
          <PopoverTrigger asChild>{row}</PopoverTrigger>
          <PopoverContent side="inline-end" align="start" className="w-56 rounded-lg p-1">
            <p className="px-2 py-1.5 text-xs font-medium text-ink-9">{label}</p>
            <ul role="list">
              {(node as NavModule).children!.map((child) => (
                <NavItem key={child.id} node={child} collapsed={false} activePath={activePath} depth={1} />
              ))}
            </ul>
          </PopoverContent>
        </Popover>
      </li>
    );
  }
  if (collapsed) {
    return (
      <li>
        <Tooltip>
          <TooltipTrigger asChild>{row}</TooltipTrigger>
          <TooltipContent side="inline-end">{label}</TooltipContent>
        </Tooltip>
      </li>
    );
  }

  return (
    <li>
      {row}
      {hasChildren && expanded && (
        <ul role="list" className="mt-0.5 space-y-0.5">
          {(node as NavModule).children!.map((child) => (
            <NavItem key={child.id} node={child} collapsed={false} activePath={activePath} depth={1} />
          ))}
        </ul>
      )}
    </li>
  );
}
```

# Tokens Used

| Element | Token | Utility |
|---|---|---|
| Label (rest) | `ink-11` | `text-ink-11` |
| Icon / chevron / secondary | `ink-9` | `text-ink-9` |
| Hover background | `ink-4` | `hover:bg-ink-4` |
| Active background | `accent-subtle` | `bg-accent-subtle` |
| Active label + inline-start bar | `accent` | `text-accent`, `bg-accent` |
| Focus ring | `accent` | `ring-[--qayd-accent]` |
| Disabled row | `ink-8` | `text-ink-8` |
| Flyout / tooltip surface | `ink-1`/`ink-3` + `ink-6` border | `bg-popover border-ink-6` |
| Row radius | `radius-md` | `rounded-md` |
| Flyout radius | `radius-lg` | `rounded-lg` |
| Expand transition | `motion.base` (200ms) | Framer Motion, `easeInOut` |
| Row vertical padding | `--space-xs` (8px) | `py-2` |
| Icon-to-label gap | 10px | `gap-2.5` |

# Accessibility

The nav-item primitives carry the keyboard and ARIA contract so the composing surfaces inherit it for free.

| Concern | Contract |
|---|---|
| Real links | Every row is an `<a>`/`Link` — a nav item *is* a navigation, never a `<div onClick>`. A module with children carries `aria-expanded`. |
| Active item | `aria-current="page"` — real ARIA state, not color alone — plus the accent bar, so a colorblind/grayscale user still perceives active. |
| Roving tabindex | A list of `NavItem`s implements a **single roving tabindex**: only the active row has `tabIndex={0}`; siblings are `-1`. One `Tab` lands on exactly one row; `Shift+Tab` leaves in one press. `↓`/`↑` move the roving position between visible rows; `→`/`←` (direction-aware, see the TSX) expand into a module's first child and collapse back. `↑`/`↓` movement is owned by the `<ul>` container via a shared `useRovingIndex` hook. |
| Focus indicator | `focus-visible` only, 2px `accent` ring, 2px offset — the exact treatment `Button`'s `cva` uses. |
| Collapsed identity | Every icon-only row keeps a native `title` + full `aria-label`; icon shape is never the sole identity. A bare leaf gets a `Tooltip`, a module a `Popover` — the collapsed rail never leaves a row unnamed. |
| Flyout / tooltip focus | The collapsed-rail `Popover` and the `Tooltip` are Radix primitives — focus management, `Esc`, and dismissal are inherited, not re-implemented. |
| Reduced motion | The chevron rotation and the children-region height transition collapse to an instant state change under `prefers-reduced-motion: reduce`. |

# Theming, Dark Mode & RTL

**Tokens only.** No `dark:`-raw-color variant appears in `NavItem`; every color is a semantic utility that
remaps at the variable layer.

**Dark mode.** The rest label (`ink-11` → `#E4DEC9`), icon (`ink-9` → `#A39878`), hover (`ink-4` →
`#2D2921`), and active tint (`accent-subtle` → `#3A2E14`, `accent` → `#D9B96C`) all remap automatically. The
active tint is a **re-tuned** dark value, not an inverted one, so it holds AA contrast on the dark canvas.

**RTL.** Because the row uses only logical properties (`ps-*`, `gap`, `text-start`, `PopoverContent
side="inline-end"`), the following flip with zero conditional logic: the child indent moves to the correct
edge; the icon-to-label gap stays on the correct side of the label; the collapsed-rail flyout flies out
toward content on the correct side; the label truncates from the correct end. One thing is handled
**explicitly**: the **arrow-key expand/collapse mapping** is direction-aware (`ArrowLeft`/`ArrowRight` are
swapped when `locale === 'ar'`, shown in the TSX), because "open the child" is inline-forward, which is a
different physical key in RTL. The disclosure chevron itself does **not** flip — it points *down* when
expanded regardless of direction, since it encodes vertical disclosure, not horizontal hierarchy (contrast
the breadcrumb separator in [`BREADCRUMB.md`](./BREADCRUMB.md), which does mirror).

# Do / Don't

| Do | Don't |
|---|---|
| Derive active state from `isNavItemActive` (segment-anchored). | Use a bare `activePath.startsWith(href)` — it falsely matches `/x-archive` against `/x`. |
| Render active with `bg-accent-subtle` + `text-accent` + inline-start bar **and** `aria-current`. | Rely on color alone, or use a full `bg-accent` fill (that is a button treatment, not a nav row). |
| Reuse the same `NavItem` recursively for the collapsed-rail flyout. | Fork a separate "flyout child" component. |
| Trust the caller to have permission-filtered the node; render what you are given. | Re-filter permissions inside `NavItem` (double filters drift out of sync). |
| Map arrow keys to expand/collapse direction-aware. | Hardcode `ArrowRight = expand` — it opens the wrong way in Arabic. |
| Keep every row a real `<a>`/`Link`. | Use a `<div onClick>` or a `<button>` that navigates via `router.push`. |
| Give every collapsed row a `Tooltip`/`Popover` label. | Leave an icon-only row without a `title`/`aria-label`. |

# Usage & Composition

`NavItem` is the shared row for three surfaces; each supplies the same permission-filtered tree and differs
only in the chrome around the list.

```tsx
// The Sidebar (expanded or icon rail) — SIDEBAR.md
<ul role="list">
  {visibleTree.map((node) => (
    <NavItem key={node.id} node={node} collapsed={collapsed} activePath={pathname} />
  ))}
</ul>
```

```tsx
// The mobile drawer — the SAME Sidebar component, variant="drawer", renders the SAME NavItem rows.
<SheetContent side="start" className="p-0">
  <Sidebar permissions={activeCompany.permissions} variant="drawer" />
</SheetContent>
```

```tsx
// The command palette's Navigate group renders flattened Nav destinations as command rows,
// reusing the same active-match + label resolution (grammar owned by NAVIGATION_SYSTEM.md).
<CommandGroup heading={t('groupNavigate')}>
  {navResults.map((item) => (
    <CommandItem key={item.id} onSelect={() => select(item.href)}>{item.breadcrumbLabel}</CommandItem>
  ))}
</CommandGroup>
```

Composition rules:

- **One data source, one filter.** Every surface renders `filterNavByPermissions(NAV_TREE, permissions)` and
  passes filtered nodes to `NavItem`. Never keep parallel filter passes.
- **`NavItem` is presentation, not policy.** Permission filtering, the route map, and realtime permission
  invalidation live in the app layer; `NavItem` only styles and lays out a node it is handed.
- **Active matching is centralized.** Both the Sidebar highlight and the palette's Navigate ranking derive
  from `isNavItemActive`/`isModuleActive` — no surface re-implements the segment-boundary rule.
- **Keyboard is a list contract.** A single `NavItem` participates in roving tabindex; the `<ul>` that hosts
  a set of them owns the `useRovingIndex` wiring.

For the module map, permission keys, command-palette grammar, and global shortcuts, see
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md). For the rail that hosts these
rows, see [`SIDEBAR.md`](./SIDEBAR.md); for the top band, [`TOPBAR.md`](./TOPBAR.md); for the trail,
[`BREADCRUMB.md`](./BREADCRUMB.md); for tokens, [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# End of Document
