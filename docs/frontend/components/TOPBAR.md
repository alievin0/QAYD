# Topbar — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / TOPBAR
---

# Purpose

The Topbar is the sticky top band of the authenticated app shell — the horizontal companion to the
[Sidebar](./SIDEBAR.md), rendered once by `(app)/layout.tsx` and never remounted on navigation. It
carries the seven affordances a user needs *from anywhere in the app, regardless of which screen is
below it*: the breadcrumb trail that says where they are, the global search / command-palette entry
that jumps them anywhere, the company-context indicator, the notifications bell, the AI/assistant
status entry, the theme toggle, and the user menu. It is chrome, not content — it never renders a
record, a form, or an AI result inline; every one of its affordances either *shows a small live
signal* (an unread count, an AI status dot) or *hands off* to a real destination (a route, a popover,
the command palette). Nothing on the Topbar executes a sensitive action on a click.

This document is the **component-level** specification for that band. It is downstream of
[`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md), which owns the *system* concerns — the Topbar's
place in the shell, the breadcrumb-derivation contract, the command-palette grammar, and the
notification/AI-status channel wiring — and of [`../LAYOUT_SYSTEM.md`](../LAYOUT_SYSTEM.md), which owns
its geometry (the `64px` desktop / `56px` mobile height, the `--z-shell-topbar` z-index, the sticky
behavior). This file specifies the Topbar *as a reusable component and its sub-components* — their
anatomy, props, states, sticky/scroll behavior, and behavior under theme and direction — citing those
documents where a value they own is a fixed input here. It cross-links the command-palette *trigger* to
[`./SEARCH_BAR.md`](./SEARCH_BAR.md), which owns the palette dialog itself; the Topbar owns only the
button that opens it.

Two platform rules bind the Topbar as they bind every surface. **RBAC is a UX courtesy in the client, a
hard boundary on the server** — the Topbar hides an affordance a role cannot use rather than disabling
it, but that is never why an action is safe. And **AI is ambient and advisory in the chrome** — the AI
status pill *signals* and *navigates*; it never approves, executes, or expands into a competing panel.

# Anatomy & Variants

The Topbar is a single sticky `<header>` laid out as a horizontal flex row. Reading order follows the
document's logical direction (inline-start → inline-end), which is exactly what flips under `dir="rtl"`
— nothing in it is positioned with `left`/`right`.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ☰   Accounting › Journal entries   [ Search or jump to…  ⌘K ]   AI●  🔔³  ☾  ▣ │
└──────────────────────────────────────────────────────────────────────────────┘
  1        2 (breadcrumb)              3 (palette trigger)        4   5   6   7
```

| # | Element | Component | Visible at | Notes |
|---|---|---|---|---|
| 1 | Mobile menu trigger | `MobileNavTrigger` | below `lg` | `Menu` icon; opens the [Sidebar](./SIDEBAR.md) as a `Sheet`. |
| 2 | Breadcrumb (desktop) / page title (mobile) | `Breadcrumbs` | breadcrumb `md`+; title below `md` | Derived from route config, never hand-written (`../NAVIGATION_SYSTEM.md → Breadcrumbs`). |
| 3 | Command-palette trigger | `CommandPaletteTrigger` | all; collapses to an icon below `sm` | A pill button "Search or jump to…" with a trailing `⌘K` hint; `me-auto` pushes everything after it to the end edge. Opens the palette owned by [`./SEARCH_BAR.md`](./SEARCH_BAR.md). |
| 4 | AI status indicator | `AiStatusIndicator` | all | A pill (not a bell) with a three-state dot; realtime over `private-company.{id}.ai`. |
| 5 | Notifications bell | `NotificationsBell` | all | Unread badge; realtime over `private-company.{id}.notifications.{user_id}`. |
| 6 | Theme toggle | `ThemeToggle` | all | Light / dark / system, three-state. |
| 7 | User menu | `UserMenu` | all | Avatar, name, active role, "Switch company," "Profile," "Sign out." |

## Variants

The Topbar has one component with responsive presentation only — never a separate mobile component:

| Variant | Trigger | Differences from desktop |
|---|---|---|
| **Desktop** | `md`+ | Full breadcrumb trail; palette trigger is a labeled pill; height `64px`. |
| **Compact** | `sm`–`md` | Breadcrumb collapses to the current page title; palette trigger keeps its label; height `56px`. |
| **Mobile** | below `sm` | `Menu` trigger visible; page title only (no trail); palette trigger collapses to a bare `Search` icon that opens the identical full-screen `CommandDialog`; height `56px`. |

The invariant across all three: **every affordance stays reachable.** The palette collapses to an icon
but opens the same dialog; the switcher lives redundantly in the user menu; the notifications bell and
AI pill never drop off. Only the *chrome that presents them* branches on viewport — never permission
filtering, breadcrumb derivation, or realtime wiring (`../NAVIGATION_SYSTEM.md → Mobile Navigation`).

# Props / API

The Topbar and every sub-component are Client Components (they read `usePathname`, Zustand, session
context, and — for the bell and AI pill — a realtime subscription). The shell mounts the Topbar once;
only `{children}` swaps on navigation, so the breadcrumb re-renders with new props but the bell's
subscription and the AI pill's pulse never re-initialize on a link click.

## `Topbar`

| Prop | Type | Required | Description |
|---|---|---|---|
| `breadcrumb` | `BreadcrumbItem[]` | yes | The resolved trail for the current route, assembled from route-segment config by `(app)/layout.tsx` (dynamic segments resolved via `generateBreadcrumb`, `../NAVIGATION_SYSTEM.md → Breadcrumbs`). The Topbar renders it; it does not derive it. |

Session-scoped data (active company, user, permissions) is read from `SessionProvider` context by the
sub-components that need it, not threaded through `Topbar` props — the Topbar is a thin layout shell
over independently-subscribing children.

## `CommandPaletteTrigger`

| Prop | Type | Required | Description |
|---|---|---|---|
| `className` | `string` | no | Layout hook, typically `me-auto`. |
| `collapsed` | `boolean` | no | Forces the icon-only presentation; normally resolved from a `sm` media query rather than passed. |

Clicking it, or pressing `Cmd/Ctrl+K` anywhere, sets `commandPaletteOpen` on `useShellStore`; the
palette dialog itself (`CommandPalette`) subscribes to that flag. The trigger owns no palette logic —
see [`./SEARCH_BAR.md`](./SEARCH_BAR.md).

## `NotificationsBell`

| Prop | Type | Required | Description |
|---|---|---|---|
| — | — | — | Takes no props; reads `user`/`activeCompany` from `SessionProvider`, subscribes to its own channel, and holds an unread `useQuery`. |

## `AiStatusIndicator`

| Prop | Type | Required | Description |
|---|---|---|---|
| — | — | — | Props-free; subscribes to `private-company.{id}.ai` and seeds an urgent-count `useQuery`; resolves to `idle | active | attention`. |

## `ThemeToggle`

| Prop | Type | Required | Description |
|---|---|---|---|
| — | — | — | Props-free; wraps `next-themes`' `useTheme()` (`../THEMING.md`) and persists the chosen theme to `PATCH /api/v1/users/me/preferences`. |

## `UserMenu`

| Prop | Type | Required | Description |
|---|---|---|---|
| — | — | — | Props-free; reads `user`, `activeCompany`, and the active `role_name` from `SessionProvider`. |

# States

Every state ships in full light/dark and LTR/RTL parity per `../ACCESSIBILITY.md` and the "never blank"
rule. Because the Topbar composes several independently-stateful sub-components, the state matrix is
per-affordance:

| Affordance | Loading | Empty | Error |
|---|---|---|---|
| **Breadcrumb** | Static ancestor segments (module, sub-section) render immediately from route config; only a final *dynamic* segment (an entry number, a customer name) waits on `generateBreadcrumb`'s fetch and shows a small inline `Skeleton` chip, never blocking the rest of the trail. | N/A — a breadcrumb always has at least the current route. | If `generateBreadcrumb`'s fetch 404s, the trail truncates one segment early rather than showing a broken label (`../NAVIGATION_SYSTEM.md → Edge Cases`). |
| **Palette trigger** | Never loading — it is a static button; the *dialog* it opens has its own loading (`./SEARCH_BAR.md`). | N/A | N/A |
| **Notifications bell** | Badge shows the last-known cached count (not zero, not a spinner) while a background refetch runs, avoiding a flash-to-zero on every mount. | Popover shows "You're all caught up" with a checkmark; no badge mounts at all (badge is `unreadCount > 0`-gated, so a `0` badge never renders). | A failed fetch keeps the last successful count with a small inline retry inside the popover, never resetting to an alarming, possibly-wrong `0`. |
| **AI status pill** | Renders `idle` (grey dot) as its default optimistic state — an unknown AI state is treated as calm, not a spinner, per the platform's calm-tone mandate. | N/A | If the Reverb connection drops, the pill freezes at its last known state and gains a small offline-slash overlay rather than falsely reporting `idle`. |
| **Theme toggle** | Renders the resolved theme instantly from the `theme` cookie the Server Component already read (`../THEMING.md`), so there is no flash-of-incorrect-theme and no loading state. | N/A | A failed preference PATCH is non-blocking — the theme still applies locally; the mismatch reconciles on the next successful sync. |
| **User menu** | Trigger shows the last-known avatar/name instantly (hydrated from the server-rendered session). | N/A | N/A |

# Behavior & Interaction

## Composition and sticky mount

```tsx
// components/layout/topbar.tsx
'use client';

import { Breadcrumbs } from '@/components/layout/breadcrumbs';
import { CommandPaletteTrigger } from '@/components/layout/command-palette-trigger';
import { AiStatusIndicator } from '@/components/layout/ai-status-indicator';
import { NotificationsBell } from '@/components/layout/notifications-bell';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { UserMenu } from '@/components/layout/user-menu';
import { MobileNavTrigger } from '@/components/layout/mobile-nav-trigger';
import type { BreadcrumbItem } from '@/types/nav';

export function Topbar({ breadcrumb }: { breadcrumb: BreadcrumbItem[] }) {
  return (
    <header
      className="sticky top-0 z-(--z-shell-topbar) flex h-16 items-center gap-3 bg-surface/85
                 px-4 [border-block-end-width:1px] border-ink-150 backdrop-blur-sm sm:h-14 md:h-16"
    >
      <MobileNavTrigger className="lg:hidden" />
      <Breadcrumbs items={breadcrumb} className="hidden md:flex" />
      <PageTitle items={breadcrumb} className="md:hidden" />   {/* mobile: current segment only */}
      <CommandPaletteTrigger className="me-auto" />
      <AiStatusIndicator />
      <NotificationsBell />
      <ThemeToggle />
      <UserMenu />
    </header>
  );
}
```

The Topbar is `sticky top-0` at `--z-shell-topbar` (20, from `../LAYOUT_SYSTEM.md`'s z-index scale),
with a translucent `bg-surface/85` and `backdrop-blur-sm` so dense content scrolling beneath it reads
through faintly without the band ever losing legibility. It never scrolls away — finance users
cross-reference the breadcrumb and search while scrolling constantly, and a top bar that hides on scroll
is a documented usability complaint the design reacts against. Its z-tier (`20`) sits below every
Radix-portaled overlay (`--z-dropdown` 30 and up), so an open popover, the palette, a modal, a toast, or
a tooltip can never be visually trapped or clipped by the sticky band.

## Breadcrumb

The breadcrumb is derived from route-segment configuration, never hand-written per page (full contract
in `../NAVIGATION_SYSTEM.md → Breadcrumbs`; not redefined here). Two Topbar-level facts: the trail is
always module-rooted — it never starts at `/dashboard` (a journal entry descended from Accounting, not
from Home) — and its last segment is never a link, carrying `aria-current="page"`. Below `md` the trail
is replaced by the current page title alone (`PageTitle`), because a multi-level trail does not fit a
phone's top band and the `+`-quick-create bottom bar covers "go create something" there instead.

## Global search / command-palette trigger

The trigger is a pill button rendering "Search or jump to…" with a right-aligned `⌘K` hint (pinned
`dir="ltr"` — a keyboard chord is a physical key combination, never mirrored in RTL). Clicking it, or
`Cmd/Ctrl+K` from anywhere in `(app)`, toggles `commandPaletteOpen` on `useShellStore`:

```tsx
// components/layout/command-palette-trigger.tsx
'use client';

import { useShellStore } from '@/stores/use-shell-store';
import { useTranslations } from 'next-intl';
import { Search } from 'lucide-react';
import { cn } from '@/lib/utils';

export function CommandPaletteTrigger({ className, collapsed }: { className?: string; collapsed?: boolean }) {
  const t = useTranslations('nav.palette');
  const setOpen = useShellStore((s) => s.setCommandPaletteOpen);

  if (collapsed) {
    return (
      <button
        onClick={() => setOpen(true)}
        aria-label={t('open')}
        className={cn('rounded-md p-2 text-ink-500 hover:bg-ink-100 hover:text-ink-950', className)}
      >
        <Search className="h-4 w-4" />
      </button>
    );
  }

  return (
    <button
      onClick={() => setOpen(true)}
      className={cn(
        'group flex h-9 items-center gap-2 rounded-md border border-ink-150 bg-canvas ps-3 pe-2 text-sm',
        'text-ink-500 hover:border-ink-300 hover:text-ink-700 max-w-xs w-full', className,
      )}
    >
      <Search className="h-4 w-4 shrink-0" />
      <span className="flex-1 text-start truncate">{t('placeholder')}</span>
      <kbd
        dir="ltr"
        className="hidden shrink-0 rounded border border-ink-150 bg-surface px-1.5 py-0.5 text-[11px] font-medium text-ink-500 sm:inline-block"
      >
        ⌘K
      </kbd>
    </button>
  );
}
```

The trigger owns *no* palette logic — the dialog, its result groups, its permission-filtered fan-out
search, and its AI handoffs are all specified in [`./SEARCH_BAR.md`](./SEARCH_BAR.md) and
`../NAVIGATION_SYSTEM.md → Command Palette`. This separation is deliberate: the Topbar hosts the
*entry point*, and the palette is a portalled `Dialog` at `--z-command-palette` (60) that renders above
everything, so it is not conceptually part of the Topbar at all.

## Company context indicator

The active company is shown in two places, redundantly: the [Sidebar](./SIDEBAR.md)'s workspace header
(primary) and the Topbar's `UserMenu` ("Switch company…"), because switching is frequent enough for a
multi-company accountant that a shortcut from wherever the eye lands is worth the redundancy. The Topbar
never mints its own switch flow — the `UserMenu` "Switch company" item opens the same
`WorkspaceSwitcher` interaction the Sidebar hosts (`../NAVIGATION_SYSTEM.md → Company & Branch
Switcher`), which is a heavy, session-level, server-confirmed operation (`queryClient.clear()` +
`router.refresh()` + navigate to `/dashboard`). The Topbar's own re-render after a switch is automatic:
`router.refresh()` re-runs the layout that supplies its `breadcrumb`.

## Notifications bell

Subscribes to `private-company.{id}.notifications.{user_id}` via the shared `RealtimeProvider` (one Echo
connection per session, not one per component) and renders an unread badge backed by the platform's
`idx_notifications_unread` partial index — which is why the count is effectively instant even for a
company with years of history. A realtime push invalidates the unread query; the badge caps its display
at `9+`. AI-generated notification rows (a recommendation surfaced as a notification) render with the
same left-border-plus-badge AI treatment used everywhere else — the bell is not exempt from "every
AI-authored element is visually distinct." Full component TSX and channel wiring live in
`../NAVIGATION_SYSTEM.md → Notifications & AI Status`; the Topbar hosts the bell, it does not own the
full notification center (a future `/notifications` history page).

## AI / assistant status entry

A compact pill — deliberately a *different shape* from the bell so the two are never confused —
subscribing to `private-company.{id}.ai` with three states (Idle grey dot / Active pulsing accent dot /
Attention amber-red dot with a numeric badge). A fetched, server-confirmed `urgentCount > 0` always
overrides a stale local `idle`, matching the platform rule that the client's cached belief is
provisional until the server's answer arrives. Clicking is always a *navigation* — to `/ai/risks` when
something is urgent, `/ai/insights` otherwise — never an in-place expansion competing with the AI Rail
or the palette for screen space. Transitions *into* `idle` are debounced 3s (a burst of short tasks
reads as one continuous "active" period, not a strobing dot); transitions into `active`/`attention` are
never debounced — urgency is never delayed for smoothness. Full TSX and the state table are in
`../NAVIGATION_SYSTEM.md → AI status indicator`.

## Theme toggle

A three-state control (light / dark / system) wrapping `next-themes`' `useTheme()`. `../THEMING.md`
specifies the full pipeline — `attribute={["class","data-theme"]}`, the `theme` cookie the Server
Component reads to render the correct attribute with no flash-of-incorrect-theme,
`disableTransitionOnChange` — and the Topbar's toggle only cycles the value and mirrors the chosen
preference to `PATCH /api/v1/users/me/preferences` so it follows the user across devices:

```tsx
// components/layout/theme-toggle.tsx
'use client';

import { useTheme } from 'next-themes';
import { useTranslations } from 'next-intl';
import { Sun, Moon, Monitor } from 'lucide-react';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { apiClient } from '@/lib/api-client';

export function ThemeToggle() {
  const t = useTranslations('nav.theme');
  const { theme, setTheme } = useTheme();

  function choose(next: 'light' | 'dark' | 'system') {
    setTheme(next);                                            // next-themes: sets class + data-theme + cookie
    void apiClient.patch('/api/v1/users/me/preferences', { theme: next }); // non-blocking cross-device sync
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t('label')}>
          {/* the resolved icon is chosen from `theme`; suppressHydrationWarning is handled at <html>, not here */}
          {theme === 'dark' ? <Moon className="h-4 w-4" /> : theme === 'light' ? <Sun className="h-4 w-4" /> : <Monitor className="h-4 w-4" />}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => choose('light')}><Sun className="me-2 h-4 w-4" /> {t('light')}</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => choose('dark')}><Moon className="me-2 h-4 w-4" /> {t('dark')}</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => choose('system')}><Monitor className="me-2 h-4 w-4" /> {t('system')}</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
```

## User menu

A Radix `DropdownMenu` anchored to the avatar, `align="end"` (flips to the visual start in RTL). It
shows the user's name and — critically for a multi-company account — the **active role at the active
company** (an accountant is a "Senior Accountant" at one tenant and a "Read Only" at another; the menu
labels which identity is currently in effect), then "Switch company," "Profile," and "Sign out."

```tsx
// components/layout/user-menu.tsx
'use client';

import { useRouter } from 'next/navigation';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslations } from 'next-intl';
import { useSession } from '@/components/providers/session-provider';
import { useShellStore } from '@/stores/use-shell-store';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem,
         DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { Avatar } from '@/components/ui/avatar';
import { Building2, User, LogOut } from 'lucide-react';
import { apiClient } from '@/lib/api-client';

export function UserMenu() {
  const t = useTranslations('nav.userMenu');
  const { user, activeCompany } = useSession();
  const router = useRouter();
  const queryClient = useQueryClient();
  const openSwitcher = useShellStore((s) => s.setCompanySwitcherOpen);

  async function signOut() {
    await apiClient.post('/api/auth/sign-out', {});
    queryClient.clear();                          // no cached tenant data may survive sign-out
    router.push('/login');
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button className="rounded-full focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2" aria-label={user.name}>
          <Avatar name={user.name} src={user.avatarUrl} size="sm" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel>
          <p className="truncate font-medium text-ink-950">{user.name}</p>
          {/* the ACTIVE role at the ACTIVE company — never a global role */}
          <p className="truncate text-caption text-ink-500">{activeCompany.role_name} · {activeCompany.name_en}</p>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => openSwitcher(true)}>
          <Building2 className="me-2 h-4 w-4" /> {t('switchCompany')}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => router.push('/settings/profile')}>
          <User className="me-2 h-4 w-4" /> {t('profile')}
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={signOut}>
          <LogOut className="me-2 h-4 w-4" /> {t('signOut')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
```

"Switch company" opens the same `WorkspaceSwitcher` interaction the [Sidebar](./SIDEBAR.md) hosts
(a heavy, session-level, server-confirmed operation — `queryClient.clear()` + `router.refresh()` +
navigate to `/dashboard`); the user menu is a redundant entry point, not a second flow. "Sign out" is
the one Topbar affordance that mutates session state; it `POST`s the sign-out route, clears the query
cache, and navigates to `/login` — it is not a destructive-data action and needs no type-to-confirm, but
it is a real Radix menu item (a `<button>` underneath), never a bare link, so it is keyboard- and
screen-reader-correct.

## Mobile menu trigger

Below `lg`, the leading affordance is `MobileNavTrigger` — a `Menu` icon button that opens the identical
[Sidebar](./SIDEBAR.md) component (unmodified, `variant="drawer"`) inside a `Sheet` sliding from the
inline-start edge:

```tsx
// components/layout/mobile-nav-trigger.tsx
'use client';

import { usePathname } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useTranslations } from 'next-intl';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';
import { Sidebar } from '@/components/layout/sidebar';
import { useSession } from '@/components/providers/session-provider';
import { Menu } from 'lucide-react';

export function MobileNavTrigger({ className }: { className?: string }) {
  const t = useTranslations('nav');
  const { activeCompany } = useSession();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);

  useEffect(() => setOpen(false), [pathname]);   // a nav overlay never lingers over its own destination

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <button className={className} aria-label={t('openMenu')}><Menu className="h-5 w-5" /></button>
      </SheetTrigger>
      <SheetContent side="start" width="sm" className="p-0">
        <Sidebar permissions={activeCompany.permissions} variant="drawer" />
      </SheetContent>
    </Sheet>
  );
}
```

The drawer closes on navigation (the `usePathname` effect), because a nav overlay that stays open over
the destination it just navigated to is a documented phone-usability regression. It reuses the exact
same `Sidebar` — there is no mobile-specific nav tree to maintain.

# Accessibility

| Concern | Contract |
|---|---|
| Landmark | One `<header>`; the breadcrumb inside it is its own `<nav aria-label="Breadcrumb">`, distinct from the Sidebar's "Primary navigation" landmark, so landmark navigation lists them separately. |
| Tab order | Logical inline order: menu trigger → breadcrumb links → palette trigger → AI pill → bell → theme → user menu. This order is the *source* order and flips visually in RTL without a `tabindex` hack. |
| Every control is a real control | The palette trigger, bell, AI pill, theme toggle, and user-menu trigger are all real `<button>`s (or Radix triggers), never `<div onClick>` — each is keyboard-activatable and exposes an accessible name. Icon-only controls (bell, theme, mobile menu) carry an `aria-label`; the bell's label includes the live count ("Notifications, 3 unread"). |
| `Esc` | Closes whichever overlay currently has focus — the palette, the bell popover, the user menu, or the mobile Sidebar `Sheet` — one layer at a time (`../ACCESSIBILITY.md`). Radix owns the focus trap and focus-return for each. |
| Focus return | Closing any Topbar-opened overlay returns focus to its trigger, automatically via Radix `FocusScope`, since the trigger stays mounted (the Topbar is never remounted). |
| Live regions | The AI pill's status and the bell's count are announced through their `aria-label` updating; a burst of AI transitions does not spam the screen reader because the debounce-into-idle also debounces the label change. |
| Color is never the only signal | The AI status dot's meaning is carried by its `aria-label` ("AI status: attention") and its tooltip text, not by dot color alone; the notification badge's count is real text, not a color-coded severity. |
| Reduced motion | The AI dot's pulse (`animate-pulse`) and the `backdrop-blur` transition are honored under `prefers-reduced-motion: reduce` — the pulse becomes a static dot (state is still conveyed by color + label), not a faster pulse. |

# Theming, Dark Mode & RTL

**Tokens only.** The band uses `bg-surface/85`, `border-ink-150`, `text-ink-500/700/950`, and
`--z-shell-topbar` — never a raw hex, px, or `dark:`-raw-color variant. The `backdrop-blur-sm`
translucency reads correctly over both light `--qayd-canvas` and dark `#0A0F0D` because the surface
token itself remaps under `data-theme="dark"`.

**Dark mode** is applied by the shell's `next-themes` provider (`../THEMING.md`) setting `class` +
`data-theme` on `<html>`; the Topbar's own `ThemeToggle` is the control that drives it. Because the
initial theme is read from a cookie server-side, the Topbar renders the correct toggle icon on first
paint with no flash. The AI dot's `bg-danger`/`bg-accent-600` and the bell badge's `tone="danger"` all
resolve to dark-mode token values automatically — no dark branch anywhere in the components.

**RTL.** Set once at the root. Because the Topbar uses only logical utilities (`ps-*`/`pe-*`, `me-auto`,
`ms-*`, `[border-block-end-width:1px]`, `align="end"` on popovers), the entire band's reading order
reverses with zero conditional logic: the menu trigger and breadcrumb move to the visual right edge, the
status icons and user menu to the visual left, and every `Popover`/`DropdownMenu` `align="end"` flips
its anchor side. Two things are pinned **explicitly** because they are directional *meaning*, not
layout:

- **The `⌘K` hint** stays `dir="ltr"`, un-mirrored — a keyboard chord is a physical key sequence;
  mirroring it would describe keys that do not exist.
- **Numerals in chrome** — the bell's unread count, the AI pill's urgent count — stay Western Arabic,
  LTR-shaped, exactly like `AmountCell`; a count reading right-to-left digit-by-digit is a comprehension
  hazard, not a translation.

The breadcrumb separator (`ChevronRight`) is the one icon that gets `rtl:rotate-180` — a chevron encodes
"next item is forward in the hierarchy," which is visually leftward in RTL, so it is a mirrored icon,
not a re-chosen one (`../NAVIGATION_SYSTEM.md → RTL Mirroring` exceptions table).

# i18n

Every Topbar string is a key in both dictionaries under the `nav` namespace, sharing one `Dictionary`
type (a one-sided key fails `npm run i18n:check` in CI). The keys the Topbar owns:

| Key | English | Arabic |
|---|---|---|
| `nav.palette.placeholder` | Search or jump to… | ابحث أو انتقل إلى… |
| `nav.palette.open` | Open search | فتح البحث |
| `nav.theme.label` | Theme | السِمة |
| `nav.theme.light` | Light | فاتح |
| `nav.theme.dark` | Dark | داكن |
| `nav.theme.system` | System | حسب النظام |
| `nav.userMenu.switchCompany` | Switch company | تبديل الشركة |
| `nav.userMenu.profile` | Profile | الملف الشخصي |
| `nav.userMenu.signOut` | Sign out | تسجيل الخروج |
| `nav.notifications.label` | Notifications, {count} unread | الإشعارات، {count} غير مقروء |
| `nav.aiStatus.label` | AI status: {status} | حالة الذكاء الاصطناعي: {status} |

Breadcrumb segment labels come from `NAV_TREE`'s `labelKey` fields or a resolved dynamic literal (an
entry number is a literal, not a translation key), owned by `../NAVIGATION_SYSTEM.md`. Locale is
resolved per-user server-side and is never in the path — the Topbar renders whatever the session's
locale is, and a link is portable across users' languages without a `[locale]` segment.

# Testing

**Storybook** (`components/layout/topbar.stories.tsx`) renders the responsive and per-affordance
permutations with the global `direction`/`theme` decorators:

```tsx
import type { Meta, StoryObj } from '@storybook/react';
import { Topbar } from './topbar';

const meta: Meta<typeof Topbar> = { component: Topbar, title: 'Layout/Topbar' };
export default meta;

const CRUMB = [
  { labelKey: 'nav.accounting.root', href: '/accounting/accounts' },
  { labelKey: 'nav.accounting.journalEntries', href: '/accounting/journal-entries' },
  { label: 'JE-2026-07-0482', href: '/accounting/journal-entries/482' },
];

export const Desktop: StoryObj<typeof Topbar> = { args: { breadcrumb: CRUMB } };
export const Mobile: StoryObj<typeof Topbar> = { args: { breadcrumb: CRUMB }, parameters: { viewport: 'mobile1' } };
export const WithUnreadAndUrgent: StoryObj<typeof Topbar> = { args: { breadcrumb: CRUMB }, parameters: { mocks: { unread: 3, urgent: 2 } } };
export const AiActive: StoryObj<typeof Topbar> = { args: { breadcrumb: CRUMB }, parameters: { mocks: { aiStatus: 'active', agentLabel: 'Forecast Agent' } } };
export const RTL: StoryObj<typeof Topbar> = { args: { breadcrumb: CRUMB }, parameters: { direction: 'rtl' } };
export const Dark: StoryObj<typeof Topbar> = { args: { breadcrumb: CRUMB }, parameters: { theme: 'dark' } };
```

**Vitest + Testing Library** covers the logic-bearing sub-components: the AI pill's `resolved`
override (a fetched `urgentCount > 0` beats a stale `idle` push), the bell's badge gating (a `0` badge
never mounts), and the theme toggle's cycle:

```tsx
// components/layout/ai-status-indicator.test.tsx
test('a fetched urgent count overrides a stale idle status', () => {
  render(<AiStatusIndicator />, { wrapper: withMocks({ urgent: 2, pushStatus: 'idle' }) });
  expect(screen.getByLabelText(/ai status: attention/i)).toBeInTheDocument();
});

// components/layout/notifications-bell.test.tsx
test('no badge renders when there are zero unread', () => {
  render(<NotificationsBell />, { wrapper: withMocks({ unread: 0 }) });
  expect(screen.queryByText('0')).toBeNull();
});
```

**Playwright** covers the flows too stateful for a component test: opening the palette from the trigger
*and* via `Cmd/Ctrl+K` and asserting the same dialog appears; the theme toggle persisting across a
reload (cookie + preferences PATCH, verified in both light and dark); and a realtime notification push
incrementing the badge without a manual refetch.

**Accessibility CI gate.** `axe-core` runs against every Topbar story; a manual keyboard pass verifies
tab order, `Esc` closing each overlay one layer at a time, and focus return to each trigger, since
automated checks catch missing ARIA but not a broken close-and-return sequence.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | `generateBreadcrumb`'s fetch for a dynamic final segment 404s (a deleted entry deep-linked). | The trail truncates one segment early rather than showing a broken `[entryId]` label; the content region's `not-found.tsx` renders the actual not-found state, deliberately indistinguishable from "belongs to another company" so a stale link never leaks a resource's existence. |
| 2 | The Reverb connection drops mid-session (network blip). | The bell keeps its last-known count with an inline retry in the popover; the AI pill freezes at its last state with an offline-slash overlay — neither resets to a false, calmer `0`/`idle`, per the "never report a wrong calmer state" rule. |
| 3 | A theme-preference PATCH fails (offline). | The theme still applies locally via `next-themes`; the failed sync is non-blocking and reconciles on the next successful write. The user never sees a blocked or reverted toggle. |
| 4 | `Cmd/Ctrl+K` collides with a browser/OS shortcut in some locales. | The listener `preventDefault()`s when the app has focus, sufficient in the overwhelming majority of cases; where a browser reserves the chord at a level the page cannot intercept, the always-visible palette trigger button is a fully equivalent fallback — the shortcut is an accelerator, never the only path. |
| 5 | A multi-company user whose role differs per company. | The user menu labels the **active** role at the **active** company, not a global role; after a company switch the label re-resolves from the refreshed session, so it never shows the previous tenant's role. |
| 6 | A burst of short AI tasks that would strobe the pill `idle`/`active`/`idle`. | Transitions into `idle` are debounced 3s from the last `active` signal; the burst reads as one continuous active period. Transitions into `active`/`attention` are never debounced — urgency is never delayed for smoothness. |
| 7 | The command palette is open and a realtime push changes the AI pill underneath it. | The pill (outside the modal) updates immediately; the palette does not re-render its own AI-actions counts mid-keystroke (that would shift result positions under the user's cursor). The two are allowed to be momentarily out of sync by design (`../NAVIGATION_SYSTEM.md → Edge Cases`). |
| 8 | A very long breadcrumb (deeply nested route) on a narrow desktop. | The trail middle-truncates with an overflow "…" that expands the collapsed ancestors on click; the current (last) segment is never truncated away, since it is the one segment that answers "where am I." |
| 9 | An RTL (Arabic) session. | The whole band's reading order reverses via logical utilities; the `⌘K` hint and every count stay LTR-pinned; the breadcrumb chevron rotates. Verified in the RTL Storybook story and a Kuwait QA pass. |
| 10 | Sign-out clicked while a mutation is in flight elsewhere. | Sign-out clears the query cache and navigates to `/login`; any in-flight mutation's response is discarded on unmount rather than applied to a torn-down session — no stale write lands after sign-out. |

# End of Document
