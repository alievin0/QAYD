# Sidebar — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / SIDEBAR
---

# Purpose

The Sidebar is the persistent primary-navigation surface of the authenticated app shell — the one
component present on every route under `app/(app)/`, rendered once by `(app)/layout.tsx` and never
remounted on navigation. It owns three jobs and only three: it renders the permission-filtered module
tree (Dashboard, AI Command Center, the Accounting sub-tree, Sales, Purchasing, Banking, Inventory,
Payroll, Tax, Reports, Settings); it reflects the active route back to the user through an
`aria-current` highlight that never flickers between page loads; and it hosts the workspace
(company/branch) switcher and the collapse/expand control at its top and bottom edges. Everything else
a user might reach — a record, a filtered list, an AI proposal — is reached *through* one of those
module destinations, never through a Sidebar affordance that duplicates it.

This document is the **component-level** specification for that surface. It is deliberately downstream
of [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md), which owns the *system* concerns — the exact
module map and per-item permission keys, the `NAV_TREE` data shape, the company/branch switch
semantics, and the deep-linking contract — and of [`../LAYOUT_SYSTEM.md`](../LAYOUT_SYSTEM.md), which
owns the shell's pixel geometry (the `272px` / `72px` / `0` width track, the z-index scale, the
breakpoint table). This file does not re-derive either; it specifies the Sidebar *as a reusable
component* — its anatomy, its props, its collapsed/expanded/drawer states and how each persists, its
active-route matching algorithm, its keyboard model, and its behavior under theme and direction —
citing those two documents wherever a value they own is a fixed input here. Where `NAVIGATION_SYSTEM.md`
shows the Sidebar in the context of the whole nav system, this document is what an engineer opens to
build or modify the `Sidebar`, `NavItem`, and `NavRail` components themselves.

Two platform rules bind the Sidebar exactly as they bind every other surface, and are restated because
navigation is where a user first meets both. **RBAC is a UX courtesy in the client, a hard boundary on
the server** — a hidden module spares a user a confusing `403`, but the same permission check runs
again, authoritatively, on every API request, so the Sidebar filtering logic is never the reason an
action is safe. And **the AI section is ambient and advisory** — the "AI Command Center" entry
navigates to a full-page surface; it never executes a sensitive action on a click, and no confidence
score changes what the nav renders.

# Anatomy & Variants

The Sidebar is a single `<nav>` landmark holding three vertically stacked regions inside one scroll
container. Only the middle region scrolls; the header and footer are pinned.

```
┌──────────────────────────┐  ← <nav aria-label="Primary">, bg-surface, border-inline-end
│  ▣  Al-Noor Trading   ⇅  │  ← 1. Workspace header (56px, fixed) — WorkspaceSwitcher
├──────────────────────────┤
│  ▢  Dashboard            │  ← 2. Primary nav list (flex-1, scrolls)
│  ✦  AI Command Center    │
│  ▤  Accounting        ▸  │     · a module with children shows a disclosure chevron
│     Chart of Accounts    │     · expanded in place; the active branch auto-expands
│     Journal Entries      │
│     General Ledger       │
│  ▤  Sales                │
│  ▤  Purchasing           │
│  ▤  Banking              │
│  ▤  Inventory            │
│  ▤  Payroll              │
│  ▤  Tax                  │
│  ▤  Reports              │
├──────────────────────────┤
│  ⚙  Settings             │  ← 3. Bottom utility row (fixed)
│  ⟨  Collapse             │     · collapse/expand toggle (Cmd/Ctrl+B)
└──────────────────────────┘
```

## Variants

The Sidebar has one component with three presentation variants, resolved from viewport width and one
persisted preference — never three separate components:

| Variant | Trigger | Width | Behavior |
|---|---|---|---|
| **Expanded** | `lg`+ and `sidebarCollapsed === false` | `272px` | Icon + label per item; module children render inline under an expanded parent; workspace switcher shows the full company name. The default on desktop. |
| **Icon rail (collapsed)** | `lg`+ and `sidebarCollapsed === true` (via `Cmd/Ctrl+B` or the footer toggle) | `72px` | Icon-only; every item's label moves into a `Tooltip` on hover/focus and a native `title`; module children are unreachable inline and instead open in a `Popover` flyout anchored to the module's icon. Workspace switcher collapses to the company avatar/glyph alone. |
| **Drawer (overlay)** | below `lg` | `0` inline, `288px` as an overlay | The Sidebar is removed from the grid entirely; the identical component is rendered inside a Radix `Sheet` (`side` = inline-start) opened by the Topbar's `Menu` trigger. Same tree, same filter, same keyboard model — only the container changes. See `# Behavior & Interaction → Responsive collapse`. |

The rule these three share: **the Sidebar is one component and one data source across all of them.**
The drawer is not a mobile-specific nav tree, and the icon rail is not a second component — both render
`filterNavByPermissions(NAV_TREE, permissions)` (`../NAVIGATION_SYSTEM.md → Permission-Aware Nav`)
through the same `NavItem`, differing only in the chrome that wraps it. This is what guarantees a module
a role cannot see is absent in all three presentations without three independent filter passes to keep
in sync.

## Module-tree composition

The primary nav list renders the fixed, platform-defined module order from
`../NAVIGATION_SYSTEM.md → Primary Navigation` (Dashboard → AI Command Center → Accounting → Sales →
Purchasing → Banking → Inventory → Payroll → Tax → Reports, with Settings pinned in the footer utility
row rather than the scrolling list). This document does not redefine that map or its permission keys; it
consumes `NAV_TREE` as its single source of destinations. What the Sidebar component adds on top of the
tree is purely presentational: the disclosure/expansion of a module with `children`, the active-route
highlight, and the collapsed-rail flyout.

A **module** node (`NavModule`) renders as a top-level row with an icon; if it declares `children`
(`NavLeaf[]`), it also renders a disclosure chevron and, when expanded, its permission-surviving
children indented beneath it. A **leaf** node renders as a plain row. The Accounting sub-tree is the
canonical multi-child module (Chart of Accounts, Journal Entries, General Ledger, Trial Balance,
Financial Statements, Fiscal Periods); Dashboard and AI Command Center are canonical leaves (the AI
entry's own deep sub-surfaces — Insights, Recommendations, Risks, Forecast, Ask AI — are presented on
the AI landing page's own in-page navigation, not as Sidebar children, per `../AI_COMMAND_CENTER.md`).

# Props / API

The Sidebar is a Client Component (it reads `usePathname`, the Zustand shell store, and — indirectly,
via the switcher — session context). It is mounted once by the Server Component `(app)/layout.tsx`,
which has already resolved `GET /api/v1/auth/me` server-side and passes the active company's permission
array down. **The Sidebar never fetches its own permissions**; it is a pure function of the permission
array and the current path.

## `Sidebar`

| Prop | Type | Required | Description |
|---|---|---|---|
| `permissions` | `string[]` | yes | `me.active_company.permissions` from `GET /api/v1/auth/me`, forwarded through `SessionProvider`. The only input to `filterNavByPermissions`. |
| `variant` | `'inline' \| 'drawer'` | no, default `'inline'` | `'drawer'` is passed only by the mobile `Sheet` host so the component can drop its own outer border and height management (the `Sheet` owns those); `'inline'` is the shell-grid presentation. Collapsed-vs-expanded is **not** a prop — it is read from the store, so `Cmd/Ctrl+B` toggles it without the parent re-rendering. |

## `NavItem`

Renders one `NavModule` (with optional children) or one `NavLeaf`. Owns its own expansion state and its
roving-tabindex wiring (`../NAVIGATION_SYSTEM.md → Keyboard Navigation`).

| Prop | Type | Required | Description |
|---|---|---|---|
| `node` | `NavModule \| NavLeaf` | yes | The already-permission-filtered node. A `NavItem` never filters — it trusts that `filterNavByPermissions` removed anything the user cannot see, including a parent whose every child was dropped. |
| `collapsed` | `boolean` | yes | Drives icon-only rendering + `Tooltip`/`Popover` flyout for children. |
| `activePath` | `string` | yes | The current `usePathname()` value; used by the active-match algorithm below. Passed down rather than read per-`NavItem` so all items compute activeness against one consistent value in a single render. |
| `depth` | `0 \| 1` | no, default `0` | `0` for a top-level module, `1` for a leaf rendered under an expanded parent (controls inline-start indentation via a logical `ps-*` step, never a physical one). |

## `useSidebar` (store selectors)

The Sidebar's persisted state lives in the shared `useShellStore` (`../NAVIGATION_SYSTEM.md → Company &
Branch Switcher` defines the store; this document only consumes the sidebar slice). The relevant fields:

| Selector | Type | Persisted? | Notes |
|---|---|---|---|
| `sidebarCollapsed` | `boolean` | yes (`localStorage`, key `qayd.shell`) — **and** reconciled to `PATCH /api/v1/users/me/preferences` so it survives a device change (`../LAYOUT_SYSTEM.md`) | The expanded/collapsed rail preference. |
| `toggleSidebar` | `() => void` | — | Bound to `Cmd/Ctrl+B` (desktop only) and the footer toggle button. |

The store's `partialize` deliberately persists `sidebarCollapsed` (a genuine chrome preference) but not
transient state like the mobile drawer's open flag, which is owned by the `Sheet`'s own controlled
`open` and reset on every route change (`# Behavior & Interaction`).

# States

Every state below is a first-class, reviewed state shipped in the same PR as the component, in full
light/dark and LTR/RTL parity, per `../ACCESSIBILITY.md` and the platform's "never blank" rule.

| State | Behavior |
|---|---|
| **Loading** | While `(app)/layout.tsx` resolves `GET /api/v1/auth/me` server-side, the Sidebar renders ten module-shaped grey `Skeleton` bars. In practice this is sub-100ms (the fetch runs before first paint), but the skeleton exists for the slow-network case so the rail is never a blank column. |
| **Ready** | The filtered module tree. The active module is highlighted and, if it has children, auto-expanded (see below). |
| **Empty** | Structurally cannot occur for a *module with children showing zero children* — `filterNavByPermissions` Rule 1 drops such a module entirely, so there is no "Accounting, no items" open-accordion state to design. The only genuine empty case is a **misconfigured role with zero visible modules** (no `*.read` keys at all): the Sidebar renders its chrome (workspace header, footer row) with a middle-region message — "No sections available — contact your company administrator" — never a blank gap that reads as a load failure. Dashboard is always reachable regardless (it has no gate), so the shell is never fully dead. |
| **Error** | If `/me` itself fails (session expired, network down), the *entire shell* falls back to a minimal error shell — logo, "Something went wrong, retry" — not a Sidebar with a red banner floating inside it. The Sidebar has no independent error state because it has no independent fetch. |
| **Collapsed (icon rail)** | Icon-only; labels move to `Tooltip` + `title`; children open in a `Popover` flyout. Persisted. |
| **Active-item highlight** | The matched module (and matched child) carry `aria-current="page"`, an accent-tinted background (`bg-accent-100`), and an inline-start accent bar. This never flickers on navigation because the Sidebar is not remounted — only `activePath` changes. |

## Active-route matching

Active state is derived, never stored. A module is "active" when the current path is within its
subtree; a leaf is "active" when the path matches its `href` (allowing a trailing detail segment). The
match is **inline-start-anchored and segment-aware**, so `/accounting/journal-entries/482` activates
both the Accounting module *and* its Journal Entries child, but `/accounting/journal-entries-archive`
(a hypothetical sibling) does not falsely activate Journal Entries via a bare `startsWith`:

```ts
// lib/nav/active-match.ts
/** True when `href` is the current route or an ancestor segment of it — never a mere string prefix. */
export function isNavItemActive(href: string, activePath: string): boolean {
  if (href === activePath) return true;
  // segment boundary: the char after `href` in `activePath` must be '/' (a real descendant),
  // never another path character (which would make '/x' spuriously match '/x-archive').
  return activePath.startsWith(href) && activePath.charAt(href.length) === '/';
}

/** A module is active if it OR any of its children is active; used to auto-expand + highlight. */
export function isModuleActive(module: NavModule, activePath: string): boolean {
  if (isNavItemActive(module.href, activePath)) return true;
  return module.children?.some((c) => isNavItemActive(c.href, activePath)) ?? false;
}
```

An active module with children **auto-expands** on mount so the current page's sibling routes are
immediately visible; a user may then manually collapse it, and that manual choice is respected for the
remainder of the session (local component state), but a subsequent navigation *into* a different
module's child re-expands that module. This "the section you're in is open" default is why a Sidebar
never strands a user on a page whose siblings are hidden one collapsed accordion away.

# Behavior & Interaction

## Composition and mount lifetime

`Sidebar` and `Topbar` are the two Client Components the Server Component `(app)/layout.tsx` mounts
once, wrapping `{children}`. Only `{children}` swaps on a link click; the Sidebar is never torn down and
rebuilt. This is the mechanism behind the flicker-free active highlight and the preserved scroll
position of the nav list — React re-renders the Sidebar with a new `activePath` prop but does not
remount it.

```tsx
// components/layout/sidebar.tsx
'use client';

import { usePathname } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { useShellStore } from '@/stores/use-shell-store';
import { NAV_TREE } from '@/lib/nav/nav-tree';
import { filterNavByPermissions } from '@/lib/nav/filter-nav';
import { WorkspaceSwitcher } from '@/components/layout/workspace-switcher';
import { NavItem } from '@/components/layout/nav-item';
import { Button } from '@/components/ui/button';
import { PanelLeftClose, PanelLeftOpen, PanelRightClose, PanelRightOpen, Settings } from 'lucide-react';
import { useLocale } from 'next-intl';
import { cn } from '@/lib/utils';

interface SidebarProps {
  permissions: string[];              // me.active_company.permissions
  variant?: 'inline' | 'drawer';
}

export function Sidebar({ permissions, variant = 'inline' }: SidebarProps) {
  const t = useTranslations('nav');
  const locale = useLocale();
  const pathname = usePathname();
  const collapsed = useShellStore((s) => s.sidebarCollapsed) && variant === 'inline';
  const toggle = useShellStore((s) => s.toggleSidebar);

  const visibleTree = filterNavByPermissions(NAV_TREE, permissions);

  // Collapse icon must depict "toward the edge the sidebar is actually on" — flip the icon CHOICE in RTL,
  // not merely its transform (COMPONENT_LIBRARY.md → RTL rule 3; NAVIGATION_SYSTEM.md exceptions table).
  const CollapseIcon = locale === 'ar'
    ? (collapsed ? PanelRightOpen : PanelRightClose)
    : (collapsed ? PanelLeftOpen : PanelLeftClose);

  return (
    <nav
      aria-label={t('primaryNavigation')}
      data-state={collapsed ? 'collapsed' : 'expanded'}
      className={cn(
        'flex h-dvh flex-col bg-surface',
        variant === 'inline' && 'border-ink-150 [border-inline-end-width:1px]',
      )}
    >
      <WorkspaceSwitcher collapsed={collapsed} />

      <ul className="flex-1 overflow-y-auto py-2" role="list">
        {visibleTree.length === 0 ? (
          <li className="px-4 py-6 text-caption text-ink-500">{t('noSectionsAvailable')}</li>
        ) : (
          visibleTree.map((node) => (
            <NavItem key={node.id} node={node} collapsed={collapsed} activePath={pathname} />
          ))
        )}
      </ul>

      <div className="border-ink-150 flex flex-col gap-1 p-2 [border-block-start-width:1px]">
        <Button variant="ghost" size={collapsed ? 'icon' : 'default'} className="justify-start gap-2" asChild>
          <a href="/settings/company" title={collapsed ? t('settings') : undefined}>
            <Settings className="h-4 w-4 shrink-0" />
            {!collapsed && t('settings')}
          </a>
        </Button>
        {variant === 'inline' && (
          <Button
            variant="ghost" size="icon" onClick={toggle}
            aria-label={t(collapsed ? 'expandSidebar' : 'collapseSidebar')}
          >
            <CollapseIcon className="h-4 w-4" />
          </Button>
        )}
      </div>
    </nav>
  );
}
```

## Collapse / expand and persistence

The collapse toggle is bound both to the footer button and to `Cmd/Ctrl+B` globally (registered once by
the shell, `../NAVIGATION_SYSTEM.md → Keyboard Navigation`; the binding is desktop-only — below `lg` the
Sidebar is a drawer and has nothing to collapse *to*). Toggling writes `sidebarCollapsed` to the Zustand
store, which `persist` mirrors to `localStorage` immediately and `../LAYOUT_SYSTEM.md`'s reconciliation
layer additionally `PATCH`es to `users/me/preferences` (debounced) so the preference follows the user to
a new device. On next load the shell reads the preference server-side and applies the correct grid track
width before first paint, so the rail does not visibly jump from expanded to collapsed after hydration.

## NavItem — the per-item component

`NavItem` renders one module row (icon + label), its disclosure state, and — in the collapsed rail —
its children flyout. It owns its own expansion state (seeded from the active match so the current
section opens on mount) and participates in the list's roving-tabindex contract. The full component
demonstrates the three presentations converging on one implementation:

```tsx
// components/layout/nav-item.tsx
'use client';

import { useState, type KeyboardEvent } from 'react';
import Link from 'next/link';
import * as Icons from 'lucide-react';
import { useTranslations, useLocale } from 'next-intl';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { isNavItemActive, isModuleActive } from '@/lib/nav/active-match';
import type { NavModule } from '@/lib/nav/nav-tree';
import { cn } from '@/lib/utils';

export function NavItem({ node, collapsed, activePath, depth = 0 }: NavItemProps) {
  const t = useTranslations();
  const locale = useLocale();
  const Icon = Icons[node.icon as keyof typeof Icons] as Icons.LucideIcon;
  const hasChildren = 'children' in node && (node as NavModule).children?.length;
  const active = hasChildren ? isModuleActive(node as NavModule, activePath) : isNavItemActive(node.href, activePath);
  const [expanded, setExpanded] = useState(active && Boolean(hasChildren));
  const label = t(node.labelKey);

  // Arrow-key expansion; ↑/↓ roving movement is owned by the <ul> via a shared useRovingIndex hook.
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
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2',
        collapsed ? 'mx-2 justify-center px-0' : 'mx-2 ps-3 pe-2',
        depth === 1 && !collapsed && 'ps-9',                                  // logical indent for a child leaf
        active ? 'bg-accent-100 font-medium text-accent-700' : 'text-ink-700 hover:bg-ink-100',
      )}
    >
      <Icon className="h-4 w-4 shrink-0" />
      {!collapsed && <span className="flex-1 truncate text-start">{label}</span>}
      {!collapsed && hasChildren && (
        <Icons.ChevronDown className={cn('h-3.5 w-3.5 shrink-0 transition-transform', expanded && 'rotate-180')} />
      )}
    </Link>
  );

  // Collapsed rail: a module with children flies its children out in a Popover; a bare leaf gets a Tooltip label.
  if (collapsed && hasChildren) {
    return (
      <li>
        <Popover>
          <PopoverTrigger asChild>{row}</PopoverTrigger>
          <PopoverContent side="inline-end" align="start" className="w-56 p-1">
            <p className="px-2 py-1.5 text-caption font-medium text-ink-500">{label}</p>
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

  // Expanded: inline children under the expanded parent.
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

Note the single-implementation discipline: the collapsed-rail flyout renders the *same* `NavItem`
recursively with `collapsed={false}` inside the `Popover`, so a child row in the flyout looks and
behaves identically to a child row in the expanded rail — there is no second "flyout child" component to
keep in sync.

## Collapsed-rail flyouts

In the `72px` icon rail, a module with children cannot render them inline. Hovering or focusing the
module's icon opens a Radix `Popover` (`side` = inline-end, so it flies out *away* from the rail toward
content, mirrored automatically in RTL) listing the same permission-surviving children the expanded
state would have shown inline — the `NavItem` code above is the implementation. This keeps every
destination reachable in the collapsed rail without the rail widening; the flyout is transient and does
not change the persisted collapsed preference. A bare leaf module (Dashboard, AI Command Center) gets a
`Tooltip` label instead of a flyout, so the collapsed rail never leaves an icon-only row unnamed.

## The AI Command Center entry

The AI Command Center is a **leaf** in the Sidebar (icon `Sparkles`, route `/ai/insights`), not an
expandable module — its own deep surfaces (Insights, Recommendations, Risks, Forecast, Ask AI) are
presented on the AI landing page's in-page navigation, not as Sidebar children (`../AI_COMMAND_CENTER.md`).
This is deliberate: the Sidebar's job is to get the user *to* the AI surface; working the backlog within
it is that surface's own concern. The entry is also distinct from the ambient **AI Rail** (the `360px`
companion panel, `../LAYOUT_SYSTEM.md`) — the Rail is one keystroke away from *any* section and shows the
condensed proposal queue for the current page, whereas the Sidebar's AI entry navigates to the full,
company-wide AI workspace. A user can have the Rail open while browsing Journal Entries and never touch
the Sidebar's AI entry, or vice versa; they are complementary, and the Sidebar item never expands the
Rail — clicking it is always a navigation, consistent with the "AI is ambient and advisory in the
chrome" rule.

## Company / branch switching from the header

The workspace header hosts `WorkspaceSwitcher` (fully specified in `../NAVIGATION_SYSTEM.md → Company &
Branch Switcher`; not redefined here). The one Sidebar-level fact that matters: a **company** switch is
a heavy, session-level, server-confirmed operation that clears the entire query cache and navigates to
`/dashboard`, whereas a **branch** switch is a light, client-side, URL-mirrored filter. The Sidebar
itself does not participate in either beyond hosting the trigger; it re-filters automatically after a
company switch because `router.refresh()` re-runs the layout that supplies its `permissions` prop.

## Responsive collapse — rail then drawer

Two thresholds, from `../LAYOUT_SYSTEM.md`'s breakpoint table:

1. **`lg`+ → inline**, expanded (`272px`) or icon rail (`72px`) per the persisted preference. The
   `Cmd/Ctrl+B` toggle moves between the two.
2. **below `lg` → drawer.** The Sidebar's grid track collapses to `0` and the identical component is
   rendered with `variant="drawer"` inside a Radix `Sheet` (`side` = inline-start), opened by the
   Topbar's `Menu` trigger. Selecting any nav item closes the drawer (the `Sheet`'s `onOpenChange`
   fires on navigation via a `usePathname` effect), because a nav overlay that stays open over the
   destination it just navigated to is a phone-usability regression. The drawer never persists its own
   open state — it is transient UI, reset on every route change.

On tablet (`md`) the Sidebar is already a drawer, not a compressed inline rail; QAYD deliberately does
not attempt a third "narrow inline" tablet variant, because a `272px` rail on a `768px` viewport eats a
third of the width a dense finance workbench needs, and the icon rail's flyouts are awkward under touch.
The bottom tab bar + hamburger pattern (`../NAVIGATION_SYSTEM.md → Mobile Navigation`) covers everything
below `lg`.

# Accessibility

The Sidebar is a `<nav>` landmark with an `aria-label` (localized "Primary navigation"), so a
screen-reader user can jump straight to it via landmark navigation and distinguish it from the Topbar's
own breadcrumb `<nav>`. Its interactive contract:

| Concern | Contract |
|---|---|
| Landmark & structure | One `<nav aria-label="Primary navigation">` wrapping a `<ul role="list">`; each item is an `<li>` with an `<a>` (a link, because every nav item *is* a navigation, never a `<div onClick>`). A module with children is an `<a>` carrying `aria-expanded`. |
| Active item | The matched item carries `aria-current="page"` — real ARIA state, not color alone — so assistive tech announces "current page" and a colorblind or grayscale user gets the accent bar plus the ARIA state, never color as the only signal. |
| Roving tabindex | The nav list implements a **single roving tabindex** (`../NAVIGATION_SYSTEM.md → Keyboard Navigation`): only the active item has `tabIndex={0}`; all siblings have `tabIndex={-1}`. One `Tab` press lands on exactly one Sidebar item; `Shift+Tab` leaves in one press — never thirty. `↓`/`↑` move the roving position between visible modules; `→` (or `←` in RTL — direction-aware) expands a module and moves into its first child; the reverse collapses back. |
| Focus indicator | `focus-visible` only (never bare `:focus`, which would ring on mouse click), 2px ring at `--qayd-accent-600` with a 2px offset — the exact token and treatment `Button`'s `cva` uses, so a keyboard user's focus ring never looks bolted-on. |
| Collapsed rail identity | Every icon-only item retains a native `title` and a full `aria-label` with the module's text name regardless of collapsed state. The collapsed rail is a density optimization for sighted, icon-literate users; it is never the sole source of an item's identity for assistive tech or hover disambiguation (`../NAVIGATION_SYSTEM.md → Edge Cases`, colorblind-shape case). |
| Reduced motion | The expand/collapse of a module's children and the rail width transition are Framer-Motion-wrapped behind `useReducedMotion()` and collapse to an instant state change under `prefers-reduced-motion: reduce` — "no motion," not "quick motion," per `../ACCESSIBILITY.md → Motion`. |
| Drawer focus | The mobile drawer is a Radix `Sheet` (Dialog underneath); Radix traps focus inside it while open and returns focus to the `Menu` trigger on close, automatically. `Esc` closes it. Content behind is `aria-hidden` and inert. |

# Theming, Dark Mode & RTL

**Tokens only.** Every color, border, radius, and shadow the Sidebar uses resolves to a `--qayd-*` CSS
variable via a Tailwind semantic utility (`bg-surface`, `border-ink-150`, `bg-accent-100`,
`text-ink-950`) — never a raw hex or a `dark:` variant referencing a raw palette value. The
active-item accent tint is `bg-accent-100`, which is re-tuned (not merely inverted) under
`:root[data-theme="dark"]` for AA contrast on the dark surface `#0F1613`, exactly as
`COMPONENT_LIBRARY.md`'s dark token block defines. There is no Sidebar-specific dark-mode asset and no
`dark:` class anywhere in its source.

**Dark mode** is applied by the shell's `next-themes` provider setting `class` + `data-theme` on
`<html>` (`../THEMING.md`); the Sidebar reads those tokens transparently. Its border,
`[border-inline-end-width:1px] border-ink-150`, and its surface both remap automatically.

**RTL** is set once at the root (`dir="rtl"` on `<html>`, resolved server-side from `user.locale`,
`../NAVIGATION_SYSTEM.md → Routing & Deep Links`), never toggled per-component. Because the Sidebar uses
only logical properties, the following flip with zero conditional logic: the whole rail moves to the
visual right; `border-inline-end` becomes a right-side border; the child-indent `ps-*` step indents
from the correct edge; the collapsed-rail `Popover` flyout (`side` = inline-end) flies out toward
content on the correct side; and every `me-*`/`ms-*` icon-to-label gap stays on the correct side of its
label. Two things are handled **explicitly** because they encode directional *meaning*, not layout:

- **The collapse icon's choice, not just its transform.** `PanelLeftClose`/`PanelLeftOpen` are drawn
  with a rail on one literal side; in RTL the component swaps to `PanelRightClose`/`PanelRightOpen`
  (shown in the TSX above) so the icon still depicts "collapse toward the edge the sidebar is on."
- **Numerals in nav chrome** (a count badge on a module, if present) stay Western Arabic, LTR-shaped,
  per `AmountCell`'s pinned `dir="ltr"` rule — a nav badge never reverses digit order in Arabic.

**Bilingual labels.** Module and leaf labels are `next-intl` keys (`nav.accounting.journalEntries`),
resolved per the active locale; any label sourced from master data (never the case for the fixed module
tree, but relevant if a future tenant-named section is added) would pick `name_ar`/`name_en` via
`useLocale()`, matching `AccountPicker`'s convention.

# i18n

Every Sidebar string is a key in both `lib/i18n/en.ts` and `lib/i18n/ar.ts` under the `nav` namespace,
sharing one `Dictionary` type — a key present in one and missing in the other fails `npm run i18n:check`
in CI, not silently at runtime. The keys the Sidebar owns:

| Key | English | Arabic |
|---|---|---|
| `nav.primaryNavigation` | Primary navigation | التنقّل الرئيسي |
| `nav.settings` | Settings | الإعدادات |
| `nav.collapseSidebar` | Collapse sidebar | طيّ الشريط الجانبي |
| `nav.expandSidebar` | Expand sidebar | توسيع الشريط الجانبي |
| `nav.noSectionsAvailable` | No sections available — contact your company administrator | لا توجد أقسام متاحة — تواصل مع مسؤول الشركة |

Module and leaf labels (`nav.dashboard`, `nav.accounting.root`, `nav.accounting.journalEntries`, …) are
owned by `NAV_TREE`'s `labelKey` fields, defined in `../NAVIGATION_SYSTEM.md → Primary Navigation`, not
duplicated here. Direction is a root attribute, not a per-string concern — the Sidebar never composes an
RTL-aware string by hand.

# Testing

Per `COMPONENT_LIBRARY.md → Testing`, the Sidebar ships a Storybook story and Vitest coverage for its
logic-bearing pieces; Playwright covers the flows too stateful for a component test.

**Storybook** (`components/layout/sidebar.stories.tsx`) renders the permutation matrix the component
actually varies along, with the global `direction`/`theme` decorators so each is inspectable in all four
LTR/RTL × light/dark combinations without four story files:

```tsx
import type { Meta, StoryObj } from '@storybook/react';
import { Sidebar } from './sidebar';

const meta: Meta<typeof Sidebar> = { component: Sidebar, title: 'Layout/Sidebar' };
export default meta;

const OWNER = ['accounting.read', 'accounting.journal.read', 'bank.read', 'sales.read',
  'purchasing.read', 'inventory.read', 'payroll.read', 'tax.read', 'reports.read'];

export const OwnerExpanded: StoryObj<typeof Sidebar> = { args: { permissions: OWNER } };
export const AccountantOnly: StoryObj<typeof Sidebar> = { args: { permissions: ['accounting.read', 'accounting.journal.read', 'reports.read'] } };
export const NoSections: StoryObj<typeof Sidebar> = { args: { permissions: [] } };          // empty-state message
export const Collapsed: StoryObj<typeof Sidebar> = { args: { permissions: OWNER }, parameters: { shellStore: { sidebarCollapsed: true } } };
export const RTL: StoryObj<typeof Sidebar> = { args: { permissions: OWNER }, parameters: { direction: 'rtl' } };
export const Drawer: StoryObj<typeof Sidebar> = { args: { permissions: OWNER, variant: 'drawer' } };
```

**Vitest + Testing Library** covers the active-match algorithm and the permission filter's interaction
with rendering — the two places a subtle regression would silently break navigation:

```tsx
// lib/nav/active-match.test.ts
import { isNavItemActive } from './active-match';

test('a detail route activates its list ancestor', () => {
  expect(isNavItemActive('/accounting/journal-entries', '/accounting/journal-entries/482')).toBe(true);
});
test('a sibling with a shared prefix is NOT falsely active', () => {
  expect(isNavItemActive('/accounting/journal-entries', '/accounting/journal-entries-archive')).toBe(false);
});
test('an exact match is active', () => {
  expect(isNavItemActive('/dashboard', '/dashboard')).toBe(true);
});
```

```tsx
// components/layout/sidebar.test.tsx
test('a module whose every child is permission-filtered away does not render', () => {
  render(<Sidebar permissions={['sales.read']} />);      // sales.read but no accounting.*
  expect(screen.queryByRole('link', { name: /accounting/i })).toBeNull();
});
test('an authenticated role always sees Dashboard even with no module read keys', () => {
  render(<Sidebar permissions={[]} />);
  expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
});
```

**Playwright** covers the persistence and responsive flows: toggling collapse with `Cmd/Ctrl+B` and
asserting the preference survives a reload (localStorage + the `users/me/preferences` PATCH); and the
sub-`lg` drawer opening from the `Menu` trigger, trapping focus, and closing on nav-item selection.

**Accessibility CI gate.** `axe-core` runs against every Sidebar story; a keyboard-only pass
(roving-tabindex traversal, `Esc` closing the mobile drawer, focus return to the trigger) is part of the
manual pre-release checklist, since automated checks catch missing ARIA but not a broken roving order.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | A role's filtered tree has **zero** visible modules (misconfigured custom role, no `*.read` keys). | The Sidebar renders chrome (workspace header + footer) with a middle-region "No sections available — contact your company administrator" message, never a blank gap. Dashboard remains reachable (no gate), so the shell is never fully locked. |
| 2 | A module has the parent `read` permission but **every child** is filtered away. | `filterNavByPermissions` Rule 1 removes the whole module — an accordion that opens to nothing is worse than no accordion. The Sidebar never renders a childless expandable module. |
| 3 | Permissions change **mid-session** (an admin revokes a role). | The permission array is not polled; it is invalidated when a `permission_changes` event arrives on the user's notification channel, triggering a `queryClient.invalidateQueries(['auth','me'])` + a Sidebar re-filter with no navigation and no loss of the current screen. A now-hidden module disappears within one push-latency window; the actual access removal for a screen the user is *on* is enforced by that screen's `error.tsx`, not the nav. |
| 4 | The active route is a child of a module the user just lost access to (revoked mid-view). | The Sidebar simply stops rendering that module on the next re-filter; the content region's `error.tsx` boundary renders "You don't have access to this" on the next request. The nav never tries to redirect the user away — that would be indistinguishable from the page not existing. |
| 5 | Collapsed rail + an ambiguous icon (Accounting `BookOpenCheck` vs. Reports `BarChart3` for a colorblind user relying on shape). | Every icon-only item keeps a `title` + `aria-label` with the module's text name; hover/focus surfaces the label via `Tooltip`. Icon shape is never the sole identity. |
| 6 | A very deep or very tall tree on a short viewport. | Only the middle nav list scrolls (its own `overflow-y-auto`); the workspace header and footer utility row stay pinned, so the collapse toggle and switcher are always reachable without scrolling the rail. |
| 7 | User toggles collapse on desktop, then resizes below `lg`. | The persisted `sidebarCollapsed` preference is ignored below `lg` (the drawer variant has no collapsed state); resizing back above `lg` restores the persisted expanded/collapsed choice exactly. |
| 8 | Rapid route changes (a user click-spamming nav items). | Because the Sidebar is not remounted, only `activePath` updates; the active highlight follows the latest committed route with no flicker or stale highlight, and the auto-expand logic re-runs cheaply per render against the current path. |
| 9 | An RTL (Arabic) session. | Rail moves to the visual right, border and indentation flip via logical properties, and the collapse icon swaps to the `PanelRight*` family (not just a transform). Verified in the RTL Storybook story and a Kuwait-based QA pass, since an errant physical `ml-*` only surfaces as a rail flush against the wrong edge in Arabic. |

# End of Document
