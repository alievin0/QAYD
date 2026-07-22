# Sizing Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / SIZING
---

# Purpose

This document is the atomic sizing reference for QAYD: the fixed set of **control heights**, **icon
sizes**, **avatar sizes**, **minimum pointer-target sizes**, and **width constraints** (the reading
measure and content caps) that every interactive element and content region is built from. It is the
"how big" companion to the "how far apart" spacing scale — where [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md)
governs the gaps *between* things and the padding *inside* them, this document governs the intrinsic
dimensions of the things themselves.

It draws its values from, and must agree exactly with, three upstream documents: the density row heights
and app-shell metrics in [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md), the icon size steps in
[../ICON_SYSTEM.md](../ICON_SYSTEM.md), and the pointer-target floors in
[../ACCESSIBILITY.md](../ACCESSIBILITY.md). The visual source of truth upstream of all three is
[../../frontend/DESIGN_LANGUAGE.md](../../frontend/DESIGN_LANGUAGE.md). Where this document and any of
those state a value, they must match; if they drift, the upstream document wins and this one is corrected.

Sizing in QAYD is a density decision, not an incidental one. A finance workbench is judged on how much
signal fits on one screen without reading as cramped, so control heights are deliberately tighter than a
consumer app's — a 40px default control, not the 48–56px common in mobile-first SaaS — while pointer
targets never fall below the accessibility floor. The whole scale is a small, fixed vocabulary: a control
is one of a handful of heights, an icon is one of six sizes, and an author never types a raw pixel
dimension a token already covers.

# The Scale

QAYD's intrinsic dimensions live on the same **4px grid** as the spacing scale, so a 40px control and a
`gap-4` (16px) gutter are multiples of one base unit and always align. Four sub-scales cover the field:
control heights, icon sizes, avatar sizes, and the pointer-target floors. Width constraints (the reading
measure and content caps) are covered in [Width Constraints](#width-constraints) below.

## Control heights

The height of any interactive control — button, input, select trigger, table-row action, segmented cell.
The **default is 40px** (`h-10`), which is also the comfortable table row height and the icon-button hit
target, so a control dropped into a comfortable-density row lines up with it exactly.

| Token | Value | Tailwind | Assigned to |
|---|---|---|---|
| `size-control-sm` | 32px | `h-8` | `Button size="sm"`, controls inside a Compact-density table row (32px), dense toolbar |
| `size-control-md` | 40px | `h-10` | **Default.** `Button size="default"`, input / textarea (single-line) / select trigger, controls in a Comfortable row |
| `size-control-lg` | 48px | `h-12` | `Button size="lg"`, primary marketing/empty-state CTA, controls in a Relaxed row, any primary action on a touch surface |

The three heights map one-to-one onto the three table density modes (Compact 32 / Comfortable 40 /
Relaxed 48) in [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md#table-density-modes), so a control's height and
the row it sits in are the same token decision. There is deliberately no `size-control-xs` below 32px: a
control shorter than 32px cannot hold a 32px pointer-target hit area without negative padding tricks, and
32px is already the floor for a keyboard-and-mouse workbench.

```css
/* app/globals.css — control-height primitives on the 4px grid */
:root {
  --size-control-sm: 32px; /* h-8  — compact row, small button */
  --size-control-md: 40px; /* h-10 — default control height */
  --size-control-lg: 48px; /* h-12 — large button, relaxed row, touch-primary */
}
```

## Icon sizes

Mirrored exactly from [../ICON_SYSTEM.md](../ICON_SYSTEM.md#icon-sizes); restated here so the sizing
reference is self-contained. `md` (20px) is the platform default the `Icon` wrapper renders when no
`size` is passed.

| Token | Value | Tailwind | Usage |
|---|---|---|---|
| `xs` | 14px / 0.875rem | `size-3.5` | Inline meta glyphs beside caption text, dense-table micro-badges, sort indicators |
| `sm` | 16px / 1rem | `size-4` | Form-field leading icons, inline status glyphs, dropdown-item icons, `Button size="sm"` |
| `md` | 20px / 1.25rem | `size-5` | **Default.** `Button size="default"`, table row actions, breadcrumb separators |
| `lg` | 24px / 1.5rem | `size-6` | Nav-rail module icons, `Button size="lg"`, section-header icons, ⌘K leading icons |
| `xl` | 32px / 2rem | `size-8` | KPI/stat-card glyph, dialog-header icon, agent avatar glyph |
| `2xl` | 40px / 2.5rem | `size-10` | Empty-state hero icon, onboarding accents |

An icon is centered inside an interactive container two steps larger on the even scale so its padding
divides evenly — a `size-5` (20px) icon inside a `size-10` (40px) icon-button leaves exactly 10px on
every side. Odd-pixel containers (39px) that push an icon a half-pixel off center are banned; see
[../ICON_SYSTEM.md](../ICON_SYSTEM.md).

## Avatar and status-mark sizes

Circular marks (user/company avatars, agent glyphs, status dots) are `radius-full` — the only fully-round
shapes in the system, per [../RADIUS_SCALE.md](./RADIUS_SCALE.md) — and their diameters step on the same
even grid as icon containers.

| Token | Value | Tailwind | Usage |
|---|---|---|---|
| `size-avatar-xs` | 20px | `size-5` | Inline mention chip, dense-table assignee, activity-feed row |
| `size-avatar-sm` | 24px | `size-6` | Comment author, list-row owner, topbar user menu trigger |
| `size-avatar-md` | 32px | `size-8` | **Default.** Card header author, table cell person, agent avatar |
| `size-avatar-lg` | 40px | `size-10` | Detail-panel header, company switcher entry |
| `size-avatar-xl` | 64px | `size-16` | Profile page, onboarding, empty-state persona |
| `status-dot` | 8px | `size-2` | Presence / status dot; the 6px AI-provenance dot is a distinct table gutter mark (see [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md#ai-provenance-gutter)) |

## Pointer-target floors

Two floors, straight from [../ACCESSIBILITY.md](../ACCESSIBILITY.md). These size the **hit area**, not
the visible glyph — a 14px sort-indicator icon still carries a ≥24px target because the whole header cell
is interactive.

| Token | Value | Basis | Applies to |
|---|---|---|---|
| `--tap-target-min` | 24px | WCAG 2.2 SC 2.5.8 floor | Every icon-only control, including dense-table row actions |
| `--tap-target-primary` | 44px | QAYD internal bar | Primary actions, and **any** interactive surface on mobile/tablet |

```css
/* app/globals.css — pointer-target floors, mirrors ACCESSIBILITY.md */
:root {
  --tap-target-min: 24px;      /* WCAG 2.2 SC 2.5.8 — dense-table icon controls */
  --tap-target-primary: 44px;  /* primary actions and all touch surfaces */
}
```

The 32px `size-control-sm` is the smallest *visible* control, and it comfortably contains the 24px hit
floor. Where a control's box is smaller than 24px (a bare inline icon toggle in a compact row), the hit
area is expanded with padding to the floor without growing the visible glyph — `min-h`/`min-w` on the
`<button>`, not a larger icon.

# Tokens & Naming

Sizing tokens follow the same tier chain as every other QAYD primitive
([../DESIGN_TOKENS.md](../DESIGN_TOKENS.md#token-tiers--naming-convention)): a raw primitive is declared
once in `globals.css`, mapped to a Tailwind utility, and consumed by name — never as a literal pixel value
in component code.

| Layer | Pattern | Example | Value |
|---|---|---|---|
| Control-height primitive | `--size-control-{step}` | `--size-control-md` | `40px` |
| Avatar primitive | `--size-avatar-{step}` | `--size-avatar-md` | `32px` |
| Pointer-target primitive | `--tap-target-{level}` | `--tap-target-primary` | `44px` |
| Icon size | Tailwind `size-*` step | `size-5` | `20px` (the `md` icon) |
| Tailwind utility (height) | `h-8` / `h-10` / `h-12` | `h-10` | resolves to `40px` |

Two conventions hold this together. First, the control-height and avatar primitives resolve onto the same
even Tailwind steps the icon scale uses (`h-8`/`size-8` = 32px, `h-10`/`size-10` = 40px), so a control, an
icon container, and an avatar of "the same size" are literally the same number. Second, because heights
are plain Tailwind height utilities (`h-10`), a component usually writes `h-10` directly rather than
reading the `--size-control-md` variable — the variable exists for the non-Tailwind contexts (a Framer
Motion `style` prop, a measured layout calc) that need the raw value, exactly as the spacing aliases do.

```tsx
// Default button: 40px height (h-10), 16px icon (size-4 at size="sm" → size-5 here),
// px-4 block padding, radius-md — every dimension a token, none a literal.
<button className="inline-flex h-10 items-center gap-2 rounded-md bg-primary px-4
                   text-text-sm font-medium text-accent-on">
  <Save className="size-5" />   {/* md icon = default control glyph */}
  Post entry
</button>

// Icon-only row action: 24px visible box, hit area padded to the 24px floor.
<button aria-label="Edit row"
        className="inline-flex size-6 min-h-[var(--tap-target-min)]
                   min-w-[var(--tap-target-min)] items-center justify-center rounded-md">
  <Pencil className="size-4" />
</button>
```

# Usage

**One default height.** A control is 40px (`h-10`) unless it has a specific reason to be `sm` (a dense
toolbar or Compact row) or `lg` (a primary CTA, a Relaxed row, a touch surface). Do not invent a 36px or
44px control height to split the difference — 44px is the *hit-target* bar, applied via padding, not a
new visible height.

**Height follows density.** A control's height is chosen to match the density of the region it sits in:
32px in a Compact table row, 40px in Comfortable, 48px in Relaxed. A 40px button in a 32px Compact row
breaks the row rhythm; switch the control to `sm`, do not re-pad the row.

**Icons come from the six-step scale.** Use `xs`–`2xl` (14/16/20/24/32/40); never a `size-[18px]`
one-off. The default is `md` (20px). Pair a button size with its icon size: `sm` button → `sm` (16px)
icon, `default` → `md` (20px), `lg` → `lg` (24px).

**Avatars step on the even grid.** 20/24/32/40/64; the default is 32px (`size-avatar-md`). An avatar is
always `radius-full`.

**Inputs match buttons.** A single-line input, select trigger, and default button share the 40px height
and the `radius-md` corner so an input-plus-button row reads as one control language. A multi-line
textarea grows from a 40px minimum.

**Hit area, not glyph, meets the floor.** Every icon-only control carries a ≥24px hit area (44px on
touch/primary), expanded with padding where the visible box is smaller — never by inflating the icon.

**Reference the token, never the literal.** Component code writes `h-10`, `size-5`, `size-avatar-md`, or
reads `--size-control-md`; it never ships a raw `height: 40px` or `width: 20px` a token already covers.

# Width Constraints

Height is only half of intrinsic sizing; QAYD also constrains **width** in two distinct jobs — the reading
measure for prose, and the content caps for the whole page shell. These come from the app-shell metrics in
[../SPACING_SYSTEM.md](../SPACING_SYSTEM.md#app-shell-metrics) and the responsive caps in
[../../frontend/DESIGN_LANGUAGE.md](../../frontend/DESIGN_LANGUAGE.md).

## Reading measure

Long-form text — an empty-state explanation, an onboarding paragraph, an AI-insight narrative, a help
panel — is capped at a comfortable **line length (measure)** so the eye does not have to track a full-width
line. QAYD caps prose at roughly **65–75 characters**, using the `ch` unit so the cap tracks the font.

| Token | Value | Applies to |
|---|---|---|
| `measure-prose` | `65ch` (≈ `max-w-prose`) | Body paragraphs, empty-state body, onboarding copy, AI narrative |
| `measure-narrow` | `48ch` | Tooltip body, inline hint, a single form field's help text |
| `field-max` | `--size-field-max` = `28rem` (448px) | A single-column form field's max width, so a text input never stretches across a wide page |

```css
:root {
  --measure-prose:  65ch;
  --measure-narrow: 48ch;
  --size-field-max: 28rem; /* 448px — single form field cap on wide screens */
}
```

A dense table is the deliberate exception: it is *meant* to use full available width (that is the point of
the 3xl ultra-wide breakpoint), so tables are never measure-capped — only running prose is.

## Content caps and shell metrics

The page shell itself has fixed dimensions, restated from
[../SPACING_SYSTEM.md](../SPACING_SYSTEM.md#app-shell-metrics):

| Shell element | Size | Notes |
|---|---|---|
| Topbar height | 64px | Constant ≥ `lg` |
| Sidebar width (expanded) | 264px | `lg`+ |
| Sidebar width (collapsed) | 72px | Icon-only rail |
| Content max-width (`2xl`) | 1440px | Centers; dense tables gain margin, not stretch |
| Content max-width (`3xl`) | 1600px | General Ledger / Trial Balance, for the extra columns |

The content cap keeps a dense report from stretching edge-to-edge on a 27" monitor (which would break the
decimal-alignment scan); beyond the cap the extra space becomes margin, not wider rows.

# Do / Don't

| Do | Don't |
|---|---|
| Make the default control 40px (`h-10`) | Invent a 36px or 44px *visible* control height |
| Match control height to the row density (32 / 40 / 48) | Drop a 40px button into a 32px Compact row |
| Use the six icon steps (14/16/20/24/32/40) | Ship a `size-[18px]` one-off icon |
| Pair button size with its icon size (`sm`→16, `default`→20, `lg`→24) | Mix a 24px icon into a small button |
| Step avatars on the even grid (20/24/32/40/64), always `radius-full` | Give an avatar a rectangular radius or an odd diameter |
| Expand an icon-only control's hit area with padding to ≥24px (44px touch) | Enlarge the icon glyph to reach the target size |
| Cap running prose at the 65ch measure | Let an empty-state paragraph run the full page width |
| Cap a single form field at `field-max` (448px) | Stretch a text input edge-to-edge on a wide screen |
| Let dense tables use full width inside the content cap | Measure-cap a General Ledger like body prose |
| Reference `h-10` / `size-5` / `size-avatar-md` | Hardcode `height: 40px` or `width: 20px` |

# Accessibility Notes

Sizing is where accessibility and density most directly negotiate, and QAYD resolves that negotiation in
favor of a guaranteed floor:

- **Every icon-only control meets WCAG 2.2 SC 2.5.8 (24×24 CSS px).** The dense-table default of a 24px
  hit area is the *minimum*; it is delivered as `min-h`/`min-w` on the real `<button>`, so the target
  survives even when the visible glyph is 14px. This is checked centrally, not per component — see the
  target checklist in [../ACCESSIBILITY.md](../ACCESSIBILITY.md).
- **Touch and primary actions clear 44px.** On mobile/tablet surfaces, and for any primary action on any
  surface, the `--tap-target-primary` 44px bar applies. A 48px (`size-control-lg`) control satisfies this
  natively; a 40px default control on a touch surface is padded up to 44px.
- **Height is never traded below the floor for density.** Density in QAYD comes from type size and row
  height *within* the accessible range (32px is the floor), never from shrinking a control below what a
  24px hit area can contain. To fit more on screen, switch density mode; do not shrink the control.
- **Control size does not encode meaning alone.** A larger control may signal primacy, but primacy is also
  carried by the single `accent` fill (one primary action per screen) and placement — size is reinforcement,
  never the sole cue, so the hierarchy survives a user's OS zoom or a forced larger text size.
- **Sizes are relative where text is involved.** Icon sizes are expressed in `rem` (`size-5` = 1.25rem)
  and prose measure in `ch`, so both scale with the user's root font size and browser zoom rather than
  pinning to device pixels; the reading measure stays comfortable at 200% zoom.
- **Focus targets are full-size.** The accent focus ring is drawn around the control's full hit box with a
  non-zero offset, so an expanded-hit-area control shows a ring that matches where a pointer or key press
  actually lands, not just the visible glyph.

# End of Document
