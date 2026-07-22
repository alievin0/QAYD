# Topbar — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TOPBAR
---

# Purpose

This document specifies the **Topbar** as an atomic design-system component — the sticky top band of the
authenticated shell and the horizontal companion to the [Sidebar](./SIDEBAR.md). It owns the band's
anatomy, its responsive variants, its slot contract (breadcrumb, command-palette trigger, notifications,
AI status, theme toggle, user menu), its token surface, its sticky/translucency behavior, and its behavior
under theme and direction. It does **not** own breadcrumb derivation, the command-palette dialog, the
realtime notification/AI channels, or the workspace-switch flow — those are application concerns specified
in [`../../frontend/components/TOPBAR.md`](../../frontend/components/TOPBAR.md) and
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md). This file is what a
design-systems engineer opens to build or restyle the band; that file is what a product engineer opens to
wire its slots to live data.

The Topbar is built entirely from tokens in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12`
scale, the brass `accent` family, the financial-semantic `negative`/`warning` hues (for the notification
badge and AI-attention dot only), `radius-md`/`radius-full`, and the `z-sticky` band. It is chrome, not
content: every slot either *shows a small live signal* (an unread count, an AI status dot) or *hands off*
to a real destination. **Nothing on the Topbar executes a sensitive action on a click.**

Two platform rules bind it. **RBAC is a UX courtesy in the client, a hard boundary on the server** — the
Topbar hides an affordance a role cannot use rather than disabling it, but that is never why an action is
safe. And **AI is ambient and advisory in the chrome** — the AI status pill signals and navigates; it never
approves, executes, or expands into a competing panel.

> **Token reconciliation.** The app-level doc shows draft names (`bg-surface/85`, `border-ink-150`,
> `text-ink-500/700/950`, `bg-danger`, `bg-accent-600`). This spec uses the **canonical** names from
> [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the band is `bg-ink-1/85`, the hairline is `border-ink-6`,
> text steps are `ink-9`/`ink-11`, the attention dot is `bg-negative`, and the active AI dot is `bg-accent`.

# Anatomy

A single sticky `<header>` laid out as a horizontal flex row. Reading order follows the document's logical
direction (inline-start → inline-end), which is exactly what flips under `dir="rtl"` — nothing is positioned
with `left`/`right`.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ [=]   Accounting › Journal entries   [ Search or jump to…  ⌘K ]   AI●  (!)³  (D)  [#] │
└──────────────────────────────────────────────────────────────────────────────┘
  1        2 (breadcrumb)              3 (palette trigger)        4   5   6   7
```

| # | Slot | Component | Visible at | Notes |
|---|---|---|---|---|
| 1 | Mobile menu trigger | `MobileNavTrigger` | below `lg` | `Menu` icon; opens the [Sidebar](./SIDEBAR.md) as a `Sheet`. |
| 2 | Breadcrumb / page title | [`Breadcrumb`](./BREADCRUMB.md) | trail `md`+; title below `md` | Derived from route config, never hand-written. |
| 3 | Command-palette trigger | `CommandPaletteTrigger` | all; collapses to an icon below `sm` | A pill "Search or jump to…" with a trailing `⌘K` hint; `me-auto` pushes everything after it to the end edge. |
| 4 | AI status indicator | `AiStatusIndicator` | all | A pill (not a bell) with a three-state dot. |
| 5 | Notifications bell | `NotificationsBell` | all | Unread badge, `9+` cap. |
| 6 | Theme toggle | `ThemeToggle` | all | Light / dark / system, three-state. |
| 7 | User menu | `UserMenu` | all | Avatar, name, active role, "Switch company," "Profile," "Sign out." |

The band is `64px` tall on desktop, `56px` on mobile, `sticky top-0` at the `z-sticky` band, with a
translucent surface + `backdrop-blur` so dense content scrolling beneath reads through faintly without the
band losing legibility. It never scrolls away.

# Variants

One component, responsive presentation only — never a separate mobile component.

| Variant | Trigger | Differences |
|---|---|---|
| **Desktop** | `md`+ | Full breadcrumb trail; palette trigger is a labeled pill; height `64px`. |
| **Compact** | `sm`–`md` | Breadcrumb collapses to the current page title; palette trigger keeps its label; height `56px`. |
| **Mobile** | below `sm` | `Menu` trigger visible; page title only; palette trigger collapses to a bare `Search` icon that opens the identical full-screen dialog; height `56px`. |

The invariant across all three: **every affordance stays reachable.** The palette collapses to an icon but
opens the same dialog; the switcher lives redundantly in the user menu; the bell and AI pill never drop off.
Only the *chrome that presents them* branches on viewport — never permission filtering, breadcrumb
derivation, or realtime wiring.

## Density & spacing

The band is a single density. Its height (`64px` desktop / `56px` compact and mobile) is the only responsive
size change; row contents do not re-scale. Slots sit on a `gap-3` (`12px`) horizontal rhythm, `px-4` (`16px`)
band inset, and every icon-button slot (bell, theme, mobile menu) is a `36px` square target (`size="icon"`
on `Button`) so the touch target clears the platform minimum on mobile without the band growing taller. The
`me-auto` on the palette trigger is the one layout hinge: it consumes the free inline space, pinning the
breadcrumb group to the start edge and the status/menu cluster to the end edge, so the band's two visual
groups hold their edges at every width without a media query per slot.

# Props / API

The Topbar and every sub-slot are Client Components. The shell mounts the Topbar once; only `{children}`
swaps on navigation, so the breadcrumb re-renders with new props but the bell's subscription and the AI
pill's pulse never re-initialize on a link click.

## `Topbar`

| Prop | Type | Required | Description |
|---|---|---|---|
| `breadcrumb` | `BreadcrumbItem[]` | yes | The resolved trail for the current route, assembled from route config by the layout. The Topbar renders it; it does not derive it. |

Session-scoped data (active company, user, permissions) is read from `SessionProvider` context by the
sub-slots that need it, not threaded through `Topbar` props — the Topbar is a thin layout shell over
independently-subscribing children.

## `CommandPaletteTrigger`

| Prop | Type | Required | Description |
|---|---|---|---|
| `className` | `string` | no | Layout hook, typically `me-auto`. |
| `collapsed` | `boolean` | no | Forces the icon-only presentation; normally resolved from a `sm` media query. |

## Props-free sub-slots

`NotificationsBell`, `AiStatusIndicator`, `ThemeToggle`, and `UserMenu` take no props — each reads session
context and (for the bell and pill) subscribes to its own realtime channel. Their channel wiring is owned
by [`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md); this spec owns only their
token surface and states.

# States

Because the Topbar composes several independently-stateful sub-slots, the state matrix is per-affordance.
Every state ships in full light/dark and LTR/RTL parity.

| Affordance | Loading | Empty | Error |
|---|---|---|---|
| **Breadcrumb** | Static ancestor segments render immediately from config; only a final *dynamic* segment shows an inline `Skeleton` chip, never blocking the trail. | N/A — always at least the current route. | A 404 on the dynamic segment truncates one segment early rather than showing a broken label. |
| **Palette trigger** | Never loading — a static button; the dialog it opens owns its own loading. | N/A | N/A |
| **Notifications bell** | Badge shows the last-known cached count while a background refetch runs — no flash-to-zero. | Popover shows "You're all caught up" with a checkmark; badge is `unreadCount > 0`-gated, so a `0` badge never mounts. | A failed fetch keeps the last successful count with an inline retry, never resetting to an alarming `0`. |
| **AI status pill** | Renders `idle` (grey dot) as the default optimistic state — an unknown state is calm, not a spinner. | N/A | If the connection drops, the pill freezes at its last state with an offline-slash overlay rather than falsely reporting `idle`. |
| **Theme toggle** | Renders the resolved theme instantly from the cookie the Server Component already read — no flash-of-incorrect-theme. | N/A | A failed preference write is non-blocking; the theme still applies locally and reconciles on the next sync. |
| **User menu** | Trigger shows the last-known avatar/name instantly from the server-rendered session. | N/A | N/A |

## AI status dot states

| Status | Dot | Meaning | Token |
|---|---|---|---|
| Idle | Small grey dot | No agent running | `bg-ink-7` |
| Active | Pulsing dot, tooltip names the agent | An agent is mid-task | `bg-accent` + `animate-pulse` |
| Attention | Dot with a numeric badge | An urgent item needs a human | `bg-negative` |

A fetched, server-confirmed urgent count always overrides a stale local `idle`. Transitions *into* idle are
debounced (a burst of short tasks reads as one active period); transitions into active/attention are never
debounced — urgency is never delayed for smoothness.

## User-menu identity state

The `UserMenu` trigger is an avatar; its dropdown label shows the user's name **and the active role at the
active company** — an accountant is a "Senior Accountant" at one tenant and a "Read Only" at another, and the
menu labels which identity is currently in effect, re-resolving from the refreshed session after a company
switch. "Switch company" opens the same `WorkspaceSwitcher` flow the [Sidebar](./SIDEBAR.md) hosts (a heavy,
session-level, server-confirmed operation), and "Sign out" is the one Topbar affordance that mutates session
state — it is a real Radix menu item (a `<button>` underneath), never a bare link, so it is keyboard- and
screen-reader-correct.

```tsx
// components/ui/nav/topbar.tsx — the design-system band primitive (canonical tokens)
'use client';

import { Breadcrumb } from '@/components/ui/nav/breadcrumb';
import { PageTitle } from '@/components/ui/nav/page-title';
import { CommandPaletteTrigger } from './command-palette-trigger';
import { AiStatusIndicator } from './ai-status-indicator';
import { NotificationsBell } from './notifications-bell';
import { ThemeToggle } from './theme-toggle';
import { UserMenu } from './user-menu';
import { MobileNavTrigger } from './mobile-nav-trigger';
import type { BreadcrumbItem } from '@/types/nav';

export function Topbar({ breadcrumb }: { breadcrumb: BreadcrumbItem[] }) {
  return (
    <header
      className="sticky top-0 z-[var(--z-sticky)] flex h-14 items-center gap-3 border-ink-6 bg-ink-1/85
                 px-4 [border-block-end-width:1px] backdrop-blur-sm md:h-16"
    >
      <MobileNavTrigger className="lg:hidden" />
      <Breadcrumb items={breadcrumb} className="hidden md:flex" />
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

The band's `z-sticky` tier sits **below** every Radix-portalled overlay (`z-dropdown` and up), so an open
popover, the palette, a modal, a toast, or a tooltip can never be visually trapped or clipped by the sticky
band.

# Tokens Used

Every value resolves to a token in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

| Element | Token | Utility | Notes |
|---|---|---|---|
| Band surface (translucent) | `ink-1` @ 85% | `bg-ink-1/85` | Opacity modifier works because aliases are stored as HSL triplets |
| Block-end hairline | `ink-6` | `border-ink-6` | Elevation by border, not shadow |
| Breadcrumb / secondary text | `ink-9` | `text-ink-9` | Secondary text step |
| Current segment / primary text | `ink-11` | `text-ink-11` | High-emphasis |
| Palette trigger border | `ink-6` | `border-ink-6` | Rests on canvas |
| Palette `⌘K` hint chip | `ink-9` on `ink-2` | `text-ink-9 bg-ink-2` | Pinned `dir="ltr"` |
| Icon-button hover | `ink-4` | `hover:bg-ink-4` | Bell, theme, mobile menu |
| AI dot — active | `accent` | `bg-accent` | Brass, the one brand hue |
| AI dot — attention / bell badge | `negative` | `bg-negative` | Financial-semantic red, status not brand |
| AI dot — idle | `ink-7` | `bg-ink-7` | Calm grey |
| Focus ring | `accent` | `ring-[--qayd-accent]` | `focus-visible`, 2px, 2px offset |
| Sticky band | `z-sticky` (10) | `z-[var(--z-sticky)]` | Below all portalled overlays |
| Pill / badge radius | `radius-full` | `rounded-full` | AI pill, unread badge, avatar |
| Trigger radius | `radius-md` | `rounded-md` | Palette pill, icon buttons |

The bell badge and AI-attention dot use the **financial-semantic `negative`** hue, never the brass `accent`
— a count/severity is status, and status never borrows brand color. The active AI dot is the one place brass
appears in the band, matching the "accent = one primary signal per screen, plus AI-touched elements" rule.

# Accessibility

| Concern | Contract |
|---|---|
| Landmark | One `<header>`; the breadcrumb inside is its own `<nav aria-label="Breadcrumb">`, distinct from the Sidebar's "Primary navigation" landmark. |
| Tab order | Logical inline order: menu trigger → breadcrumb links → palette trigger → AI pill → bell → theme → user menu. Source order flips visually in RTL with no `tabindex` hack. |
| Real controls | Palette trigger, bell, AI pill, theme toggle, and user-menu trigger are real `<button>`s / Radix triggers, never `<div onClick>`. Icon-only controls carry an `aria-label`; the bell's label includes the live count ("Notifications, 3 unread"). |
| `Esc` | Closes whichever overlay currently has focus — palette, bell popover, user menu, mobile Sidebar `Sheet` — one layer at a time. Radix owns the focus trap and return. |
| Focus return | Closing any Topbar-opened overlay returns focus to its trigger automatically, since the trigger stays mounted (the Topbar is never remounted). |
| Color is never the only signal | The AI dot's meaning rides in its `aria-label` ("AI status: attention") and tooltip text, not dot color; the badge count is real text, not a color-coded severity. |
| Reduced motion | The AI dot's pulse and the `backdrop-blur` transition honor `prefers-reduced-motion: reduce` — the pulse becomes a static dot (state still conveyed by color + label), not a faster pulse. |

# Theming, Dark Mode & RTL

**Tokens only.** The band uses `bg-ink-1/85`, `border-ink-6`, `text-ink-9/11`, `bg-accent`/`bg-negative`,
and `z-sticky` — never a raw hex, px, or `dark:`-raw-color variant.

**Dark mode** is driven by the shell's `next-themes` provider setting `class="dark"` + `data-theme` on
`<html>`; the Topbar's own `ThemeToggle` is the control. Because the initial theme is read from a cookie
server-side, the toggle icon is correct on first paint with no flash. The `backdrop-blur` translucency reads
correctly over both light `ink-1` (`#FAFAF9`) and dark `ink-1` (`#14130F`) because the surface token itself
remaps. The AI dot's `bg-accent`/`bg-negative` and the bell badge resolve to dark-mode token values
automatically — no dark branch anywhere.

**RTL** is set once at the root. Because the Topbar uses only logical utilities (`ps-*`/`pe-*`, `me-auto`,
`ms-*`, `[border-block-end-width:1px]`, `align="end"` on popovers), the whole band's reading order reverses
with zero conditional logic: the menu trigger and breadcrumb move to the visual right edge, the status icons
and user menu to the visual left, and every `Popover`/`DropdownMenu` `align="end"` flips its anchor side.
Three things are pinned **explicitly** because they encode directional *meaning*:

- **The `⌘K` hint** stays `dir="ltr"`, un-mirrored — a keyboard chord is a physical key sequence; mirroring
  it would describe keys that do not exist.
- **Numerals in chrome** — the bell's unread count, the AI pill's urgent count — stay Western Arabic,
  LTR-shaped, exactly like the `Amount` primitive; a count reading right-to-left digit-by-digit is a
  comprehension hazard.
- **The breadcrumb separator** (`ChevronRight`) gets `rtl:rotate-180` — a chevron encoding "next item is
  forward" is visually leftward in RTL, so it is mirrored, not re-chosen. Owned by [`BREADCRUMB.md`](./BREADCRUMB.md).

# Do / Don't

| Do | Don't |
|---|---|
| Keep the AI status entry a *different shape* (pill) from the bell so the two are never confused at a glance. | Style the AI pill as a second bell, or as a red dot that reads as a notification. |
| Use `negative` for the unread badge and the attention dot. | Use brass `accent` for a count or severity — status never borrows brand color. |
| Keep the band at `z-sticky` (10), below every portalled overlay. | Raise the band above `z-dropdown` — it will clip open popovers and the palette. |
| Pin the `⌘K` hint and every count `dir="ltr"`. | Let a keyboard chord or a digit reverse in Arabic. |
| Let the palette collapse to an icon on mobile that opens the *same* dialog. | Ship a separate mobile search surface. |
| Keep every control a real `<button>`/Radix trigger with an accessible name. | Use a `<div onClick>` for the bell, pill, or toggle. |
| Read the theme from the server-rendered cookie for first paint. | Render a loading state or flash the wrong theme on hydration. |

# Usage & Composition

The design-system `Topbar` primitive is mounted once by the app shell, paired with the
[`Sidebar`](./SIDEBAR.md), and consumes the [`Breadcrumb`](./BREADCRUMB.md) primitive in its slot 2.

```tsx
// app/(app)/layout.tsx (excerpt) — the shell mounts the band once
<div className="flex min-w-0 flex-col">
  <Topbar breadcrumb={me.breadcrumb} />
  <main className="min-h-0 flex-1 overflow-auto">{children}</main>
</div>
```

Composition rules:

- **The band hosts, it does not own.** Breadcrumb derivation, the palette dialog, notification/AI channels,
  and the workspace-switch flow all live in the app layer; this primitive lays out and styles their slots.
- **Slot 2 is the `Breadcrumb` primitive.** The Topbar never hand-writes a trail; it renders
  [`Breadcrumb`](./BREADCRUMB.md) on `md`+ and a bare page title below.
- **The palette trigger owns no palette logic.** It only toggles the shared `commandPaletteOpen` flag; the
  dialog is a portalled surface at the command z-tier, above everything — conceptually not part of the band.
- **Pair the `56px`/`64px` heights with the Sidebar's `56px` workspace header** so the eye reads one
  continuous header across the sidebar/topbar seam.
- **Redundant switch entry.** The active company shows in the Sidebar's header (primary) and the Topbar's
  `UserMenu` ("Switch company") — the Topbar never mints its own switch flow.
- **Sub-slots subscribe, not the band.** The bell and AI pill each open their own realtime subscription via
  the shared connection; the `Topbar` shell passes no realtime props, so a link click that re-renders the
  breadcrumb never tears down or re-initializes a subscription.

For the app-level wiring (breadcrumb contract, command-palette grammar, realtime channels, sign-out flow),
see [`../../frontend/components/TOPBAR.md`](../../frontend/components/TOPBAR.md) and
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md). For the shared nav-item
primitive, see [`NAVIGATION.md`](./NAVIGATION.md); for the trail, [`BREADCRUMB.md`](./BREADCRUMB.md); for
tokens, [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# End of Document
