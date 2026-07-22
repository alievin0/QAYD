# Sidebar — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / SIDEBAR
---

# Purpose

This document specifies the **Sidebar** as an atomic design-system component — the visual and interaction
primitive from which QAYD's persistent primary-navigation rail is built. It owns the rail's anatomy, its
three presentation variants (expanded, icon rail, drawer), its token surface, its active/hover/focus
states, and its behavior under theme and direction. It does **not** own the module map, the permission-key
grammar, the `NAV_TREE` data shape, or the workspace-switch semantics — those are application concerns,
specified once in [`../../frontend/components/SIDEBAR.md`](../../frontend/components/SIDEBAR.md) (the app
component that consumes this primitive) and [`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md)
(the navigation system). This file is what a design-systems engineer opens to build or restyle the rail's
chrome; that file is what a product engineer opens to wire it to routes and permissions.

The Sidebar is built entirely from tokens catalogued in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the
warm-neutral `ink-1…12` graphite scale, the single disciplined brass `accent` family, `radius-md`/`radius-lg`,
and the elevation surfaces. **A row references a token, never a literal** — there is no hex, rgb, or px in
Sidebar source that a token does not already name, and there is no `dark:`-prefixed raw color anywhere in it.

Two platform rules bind the primitive because navigation is where a user first meets both. **RBAC is a
UX courtesy in the client, a hard boundary on the server** — a hidden module spares a confusing `403`, but
the same check re-runs authoritatively on every request; the Sidebar's filtering is never why an action is
safe. And **the AI entry is ambient and advisory** — it navigates to a full-page surface and never executes
a sensitive action on a click.

> **Token reconciliation.** The app-level component doc shows draft utility names (`bg-accent-100`,
> `text-accent-700`, `border-ink-150`, `ring-accent-600`). This design-system spec uses the **canonical**
> names from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the active tint is `bg-accent-subtle` with
> `text-accent`, the hairline is `border-ink-6`, the focus ring is `ring-[--qayd-accent]`. Where the two
> disagree, this catalogue governs, per DESIGN_TOKENS' Reconciliation section.

# Anatomy

The Sidebar is a single `<nav>` landmark holding three vertically stacked regions inside one scroll
container. Only the middle region scrolls; the header and footer are pinned to the rail's edges.

```
┌──────────────────────────┐  ← <nav aria-label="Primary">, bg-ink-2, border-inline-end 1px ink-6
│  [#]  Al-Noor Trading   ^v  │  ← 1. Workspace header (56px, fixed)
├──────────────────────────┤
│  []  Dashboard           │  ← 2. Primary nav list (flex-1, overflow-y-auto)
│  []  AI Command Center   │
│  []  Accounting       >  │     · a module with children shows a disclosure chevron
│     Chart of Accounts    │     · active branch auto-expands; child rows indent via ps-*
│     Journal Entries      │
│  [=]  Sales                │
│  [=]  Banking              │
│  …                       │
├──────────────────────────┤
│  [=]  Settings             │  ← 3. Bottom utility row (fixed)
│  ⟨  Collapse             │     · collapse/expand toggle (Cmd/Ctrl+B)
└──────────────────────────┘
```

| # | Region | Fixed? | Contents | Height / sizing |
|---|---|---|---|---|
| 1 | Workspace header | fixed, never scrolls | Company/branch switcher trigger | `56px`, matching the Topbar band |
| 2 | Primary nav list | scrolls (`overflow-y-auto`) | The permission-filtered module tree of `NavItem` rows | `flex-1` |
| 3 | Bottom utility row | fixed, never scrolls | Settings link + collapse toggle | `border-block-start` 1px `ink-6` |

## Row anatomy (`NavItem`)

A single row is a horizontal flex line: a `16px` leading icon, a `truncate` label, and — for a module with
children — a trailing disclosure chevron. Indentation of a child leaf uses a logical `ps-*` step, never a
physical `pl-*`.

```
┌────────────────────────────────────┐
│ [icon]  Label                    v │   ← module row, gap 10px, py-2, rounded-md
└────────────────────────────────────┘
    ↑          ↑                     ↑
  ink-9    text-ink-11         chevron ink-9
  (active: text-accent on bg-accent-subtle, inline-start accent bar)
```

# Variants

One component, three presentation variants resolved from viewport width and one persisted preference —
never three separate components. The width values are owned by
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md) and the shell layout; this
spec consumes them as fixed inputs.

| Variant | Trigger | Width | Row rendering |
|---|---|---|---|
| **Expanded** | `lg`+ and `sidebarCollapsed === false` | `272px` | Icon + label; children render inline under an expanded parent; switcher shows full company name. Desktop default. |
| **Icon rail (collapsed)** | `lg`+ and `sidebarCollapsed === true` | `72px` | Icon-only; label moves to a `Tooltip` + native `title`; a module's children open in a `Popover` flyout anchored to its icon; switcher collapses to the company glyph. |
| **Drawer (overlay)** | below `lg` | `0` inline, `288px` overlay | Same tree rendered inside a Radix `Sheet` (`side` = inline-start), opened by the Topbar `Menu` trigger. Only the container changes. |

The invariant across all three: **the Sidebar is one component and one data source.** The drawer is not a
mobile-specific nav tree; the icon rail is not a second component. All three render the same
permission-filtered tree through the same `NavItem`, differing only in the chrome that wraps it.

## Density / size

The rail is a single density. Row height derives from `py-2` (`--space-xs`) plus the `text-sm` line box and
a `16px` icon, yielding a `~36px` target — comfortable for a back-office pointer workflow without wasting the
vertical budget a ten-module tree needs. There is no compact/comfortable toggle; the icon rail is the only
density reduction and it is a width change, not a row-height change.

# Props / API

The Sidebar is a Client Component (it reads `usePathname` and the shell store). It is mounted once by the
Server Component `(app)/layout.tsx`, which has already resolved the session server-side and passes the
active company's permission array down. **The Sidebar never fetches its own permissions.**

## `Sidebar`

| Prop | Type | Required | Description |
|---|---|---|---|
| `permissions` | `string[]` | yes | The permission array from the session, the only input to `filterNavByPermissions`. |
| `variant` | `'inline' \| 'drawer'` | no, default `'inline'` | `'drawer'` is passed by the mobile `Sheet` host so the component drops its own outer border and height management. Collapsed-vs-expanded is **not** a prop — it is read from the store so `Cmd/Ctrl+B` toggles without a parent re-render. |

## `NavItem`

| Prop | Type | Required | Description |
|---|---|---|---|
| `node` | `NavModule \| NavLeaf` | yes | The already-permission-filtered node. A `NavItem` never filters. |
| `collapsed` | `boolean` | yes | Drives icon-only rendering + `Tooltip`/`Popover` flyout. |
| `activePath` | `string` | yes | The current `usePathname()`, used by the active-match algorithm. Passed down, not read per-item, so all rows compute activeness against one value in one render. |
| `depth` | `0 \| 1` | no, default `0` | `1` for a child leaf; controls the logical `ps-*` indent step, never a physical one. |

## Store selectors (`useShellStore`)

| Selector | Type | Persisted? | Notes |
|---|---|---|---|
| `sidebarCollapsed` | `boolean` | yes (`localStorage` + reconciled to user preferences) | Expanded/collapsed rail preference. |
| `toggleSidebar` | `() => void` | — | Bound to `Cmd/Ctrl+B` (desktop only) and the footer toggle. |

# States

Every state ships in the same PR as the component, in full light/dark and LTR/RTL parity.

| State | Row treatment | Tokens |
|---|---|---|
| **Rest** | Label `text-ink-11`, icon `text-ink-9`, transparent background | `text-ink-11`, `text-ink-9` |
| **Hover** | Background fills to the hover step | `hover:bg-ink-4` |
| **Active** | `bg-accent-subtle`, label `text-accent`, `font-medium`, plus a `2px` inline-start accent bar; carries `aria-current="page"` | `bg-accent-subtle`, `text-accent` |
| **Focus (keyboard)** | `focus-visible` 2px ring at `accent` with 2px offset — never bare `:focus` | `ring-[--qayd-accent]` |
| **Disabled (plan/integration gate)** | `text-ink-8`, `cursor-not-allowed`, `aria-disabled`, a `Lock` glyph, and a tooltip reason. RBAC-by-role items are hidden, never disabled | `text-ink-8` |
| **Loading** | Ten module-shaped grey `Skeleton` bars while the session resolves server-side | `bg-ink-3` |
| **Empty (zero visible modules)** | A misconfigured role with no `*.read` keys renders chrome + a middle-region message, never a blank gap. Dashboard is always reachable | `text-ink-9` |

The active highlight **never flickers on navigation** because the Sidebar is not remounted — only
`activePath` changes. Active-route matching is segment-aware (owned by the app component): `/accounting/journal-entries/482`
activates both Accounting and its Journal Entries child, but `/accounting/journal-entries-archive` does not
falsely match via a bare `startsWith`.

```tsx
// components/ui/nav/sidebar.tsx — the design-system rail primitive (canonical tokens)
'use client';

import { usePathname } from 'next/navigation';
import { useTranslations, useLocale } from 'next-intl';
import { useShellStore } from '@/stores/use-shell-store';
import { NAV_TREE } from '@/lib/nav/nav-tree';
import { filterNavByPermissions } from '@/lib/nav/filter-nav';
import { WorkspaceSwitcher } from './workspace-switcher';
import { NavItem } from './nav-item';
import { Button } from '@/components/ui/button';
import { PanelLeftClose, PanelLeftOpen, PanelRightClose, PanelRightOpen, Settings } from 'lucide-react';
import { cn } from '@/lib/utils';

interface SidebarProps {
  permissions: string[];
  variant?: 'inline' | 'drawer';
}

export function Sidebar({ permissions, variant = 'inline' }: SidebarProps) {
  const t = useTranslations('nav');
  const locale = useLocale();
  const pathname = usePathname();
  const collapsed = useShellStore((s) => s.sidebarCollapsed) && variant === 'inline';
  const toggle = useShellStore((s) => s.toggleSidebar);

  const visibleTree = filterNavByPermissions(NAV_TREE, permissions);

  // The collapse icon depicts "toward the edge the rail is on" — flip the icon CHOICE in RTL, not a transform.
  const CollapseIcon = locale === 'ar'
    ? (collapsed ? PanelRightOpen : PanelRightClose)
    : (collapsed ? PanelLeftOpen : PanelLeftClose);

  return (
    <nav
      aria-label={t('primaryNavigation')}
      data-state={collapsed ? 'collapsed' : 'expanded'}
      className={cn(
        'flex h-dvh flex-col bg-ink-2',
        variant === 'inline' && 'border-ink-6 [border-inline-end-width:1px]',
      )}
    >
      <WorkspaceSwitcher collapsed={collapsed} />

      <ul className="flex-1 overflow-y-auto py-2" role="list">
        {visibleTree.length === 0 ? (
          <li className="px-4 py-6 text-xs text-ink-9">{t('noSectionsAvailable')}</li>
        ) : (
          visibleTree.map((node) => (
            <NavItem key={node.id} node={node} collapsed={collapsed} activePath={pathname} />
          ))
        )}
      </ul>

      <div className="flex flex-col gap-1 border-ink-6 p-2 [border-block-start-width:1px]">
        <Button variant="ghost" size={collapsed ? 'icon' : 'default'} className="justify-start gap-2" asChild>
          <a href="/settings/company" title={collapsed ? t('settings') : undefined}>
            <Settings className="h-4 w-4 shrink-0" />
            {!collapsed && t('settings')}
          </a>
        </Button>
        {variant === 'inline' && (
          <Button variant="ghost" size="icon" onClick={toggle}
            aria-label={t(collapsed ? 'expandSidebar' : 'collapseSidebar')}>
            <CollapseIcon className="h-4 w-4" />
          </Button>
        )}
      </div>
    </nav>
  );
}
```

# Tokens Used

Every value resolves to a token in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md). No raw literals.

| Element | Token | Utility | Notes |
|---|---|---|---|
| Rail surface | `ink-2` | `bg-ink-2` | Sidebar / subtle background per the surface table |
| Inline-end / block-start border | `ink-6` | `border-ink-6` | Hairline, elevation-by-border, not shadow |
| Row label (rest) | `ink-11` | `text-ink-11` | High-emphasis text |
| Row icon / chevron (rest) | `ink-9` | `text-ink-9` | Default icon, secondary text |
| Row hover background | `ink-4` | `hover:bg-ink-4` | Hover step |
| Active row background | `accent-subtle` | `bg-accent-subtle` | Selected-row brass tint |
| Active row label + bar | `accent` | `text-accent`, `bg-accent` | Brass as text/icon clears 4.5:1 on `ink-1/2` |
| Focus ring | `accent` | `ring-[--qayd-accent]` | `focus-visible`, 2px, 2px offset |
| Disabled row | `ink-8` | `text-ink-8` | Legible-but-muted, reinforced by `aria-disabled` |
| Empty-state text | `ink-9` | `text-ink-9` | — |
| Row radius | `radius-md` | `rounded-md` | 6px — inputs/buttons/rows |
| Flyout / popover radius | `radius-lg` | `rounded-lg` | 8px — the workhorse surface radius |
| Row vertical padding | `--space-xs` | `py-2` | 8px |
| Icon-to-label gap | `--space-sm`-ish | `gap-2.5` | 10px |

The active tint `accent-subtle` is a **re-tuned** value under `.dark` (`#EADFBF` → `#3A2E14`), not an
inverted one, so it holds AA contrast against the dark canvas — exactly as DESIGN_TOKENS' remap rules require.

# Accessibility

| Concern | Contract |
|---|---|
| Landmark | One `<nav aria-label="Primary navigation">` wrapping a `<ul role="list">`; distinct from the Topbar's Breadcrumb `<nav>`, so landmark navigation lists them separately. |
| Real links | Every row is an `<a>` (a nav item *is* a navigation), never a `<div onClick>`. A module with children carries `aria-expanded`. |
| Active item | `aria-current="page"` — real ARIA state, not color alone — so a colorblind or grayscale user gets the accent bar plus the announced state. |
| Roving tabindex | The list is a single roving tabindex: only the active item has `tabIndex={0}`; siblings are `-1`. One `Tab` lands on exactly one item; `Shift+Tab` leaves in one press. `↓`/`↑` move between modules; `→`/`←` (direction-aware) expand/collapse. |
| Focus indicator | `focus-visible` only, 2px `accent` ring, 2px offset — the exact treatment `Button`'s `cva` uses, so the ring never looks bolted on. |
| Collapsed identity | Every icon-only row keeps a native `title` + full `aria-label` with the module name; icon shape is never the sole identity. |
| Reduced motion | Child expand/collapse and rail-width transition collapse to an instant state change under `prefers-reduced-motion: reduce` — "no motion," not "quick motion." |
| Drawer focus | The mobile `Sheet` (Radix Dialog underneath) traps focus, returns it to the `Menu` trigger on close, and `Esc` closes it; content behind is inert. |

Contrast: active `text-accent` on `bg-accent-subtle` and rest `text-ink-11` on `bg-ink-2` both clear WCAG
2.1 AA in light and dark; disabled `text-ink-8` is the sole documented exception, reinforced by `aria-disabled`.

# Theming, Dark Mode & RTL

**Tokens only.** No `dark:` variant referencing a raw palette value appears in Sidebar source; every color
is a semantic utility that remaps at the CSS-variable layer.

**Dark mode** is applied by the shell's `next-themes` provider setting `class="dark"` + `data-theme` on
`<html>`. The rail surface (`ink-2` → `#1B1914`), hairline (`ink-6` → `#46402F`), and active tint
(`accent-subtle` → `#3A2E14`) all remap automatically; elevation gets *lighter* as it raises, per the token
catalogue's dark rules. There is no Sidebar-specific dark asset.

**RTL** is set once at the root (`dir="rtl"` on `<html>`, resolved server-side from the user's locale),
never toggled per component. Because the Sidebar uses only logical properties, these flip with zero
conditional logic: the rail moves to the visual right; `border-inline-end` becomes a right-side border; the
child-indent `ps-*` step indents from the correct edge; the collapsed-rail `Popover` (`side` = inline-end)
flies out toward content on the correct side; every `gap`/`ms-*`/`me-*` stays on the correct side of its
label. Two things are handled **explicitly** because they encode directional *meaning*, not layout:

- **The collapse icon's choice, not just its transform.** In RTL the component swaps to the `PanelRight*`
  family (shown in the TSX) so the icon still depicts "collapse toward the edge the rail is on."
- **Numerals in nav chrome** (a count badge on a module, if present) stay Western Arabic, LTR-shaped, per
  the `Amount` primitive's pinned `dir="ltr"` rule — a nav badge never reverses digit order in Arabic.

# Do / Don't

| Do | Don't |
|---|---|
| Render the active row with `bg-accent-subtle` + `text-accent` + an inline-start accent bar **and** `aria-current="page"`. | Signal active state with color alone (no `aria-current`), or with a full-saturation `bg-accent` fill (that is a button, not a nav row). |
| Hide a module a role cannot access. | Render it disabled/greyed for an RBAC-by-role gate — that advertises a capability the role will never receive (noise + a support ticket). |
| Reserve disabled-with-a-reason for **plan-tier** and **unconfigured-integration** gates, with a `Lock` glyph and tooltip. | Disable a row for a missing role permission. |
| Use logical properties (`ps-*`, `border-inline-end`, `gap`) throughout. | Use physical `pl-*`/`ml-*`/`left-*` — it only surfaces as a rail flush against the wrong edge in Arabic, weeks after an English review passed. |
| Keep the rail a single component across expanded/rail/drawer. | Fork a second "mobile nav tree" or a separate "flyout child" component to keep in sync. |
| Reference `ink-*`/`accent-*` tokens for every color. | Write a raw hex, `rgb()`, or a `dark:#…` value in Sidebar source. |
| Let only the middle region scroll; pin header + footer. | Let the whole rail scroll so the collapse toggle or switcher scrolls out of reach. |

# Usage & Composition

The design-system `Sidebar` primitive is composed by the app shell exactly once, alongside the
[`TOPBAR`](./TOPBAR.md), and is fed by the shared [`NAVIGATION`](./NAVIGATION.md) `NavItem` primitive.

```tsx
// app/(app)/layout.tsx (excerpt) — the shell mounts the rail once
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const me = await getSession();                 // server-side; Sidebar never fetches its own permissions
  return (
    <div className="grid h-dvh grid-cols-[auto_1fr]">
      <Sidebar permissions={me.active_company.permissions} />
      <div className="flex min-w-0 flex-col">
        <Topbar breadcrumb={me.breadcrumb} />
        <main className="min-h-0 flex-1 overflow-auto">{children}</main>
      </div>
    </div>
  );
}
```

```tsx
// The same component, unmodified, hosted in the mobile drawer by the Topbar's Menu trigger.
<SheetContent side="start" width="sm" className="p-0">
  <Sidebar permissions={activeCompany.permissions} variant="drawer" />
</SheetContent>
```

Composition rules:

- **One data source.** Expanded, icon rail, and drawer all render `filterNavByPermissions(NAV_TREE, permissions)`
  through the same `NavItem`. Never keep three filter passes in sync.
- **The rail hosts, it does not own.** The workspace switcher, the route map, and the realtime permission
  invalidation live in the app layer ([`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md));
  this primitive only styles and lays out what they supply.
- **Row = `NavItem`.** The Sidebar never hand-rolls a row; every row, in every variant and inside the
  collapsed-rail flyout, is the shared `NavItem` primitive documented in [`NAVIGATION.md`](./NAVIGATION.md).
- **Pair with the Topbar.** The `56px` workspace header is sized to match the Topbar band so the eye reads
  one continuous header across the sidebar/topbar seam.

For the app-level wiring (module map, workspace-switch weights, RBAC decision tree, keyboard shortcut
registration), see [`../../frontend/components/SIDEBAR.md`](../../frontend/components/SIDEBAR.md) and
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md). For the shared nav-item and
active-route matching, see [`NAVIGATION.md`](./NAVIGATION.md). For tokens, see
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# End of Document
