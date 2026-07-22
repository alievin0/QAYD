# Icon Grid & Geometry — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / ICON_GRID
---

# Purpose

This document is the atomic reference for **icon geometry** in QAYD — the AI-native, bilingual
(English LTR / Arabic RTL) accounting platform. Where [`../ICON_SYSTEM.md`](../ICON_SYSTEM.md) governs
*which* icons exist (one library, one glyph per concept, the no-emoji rule, RTL mirroring, accessibility),
this document specializes it down to the **drawing surface**: the Lucide 24px keyline grid, the stroke
weights (1.75 / 1.5), the rendered size steps on Tailwind's 4px grid, optical alignment, the pixel-snapping
rules, and the geometry a custom glyph must match so it is indistinguishable from a Lucide glyph beside it.

It is a specialization of, and never a contradiction to, [`../ICON_SYSTEM.md`](../ICON_SYSTEM.md). The color
tokens icons inherit are in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); the size scale here lands on the
same 4px spacing grid documented across the system. Where a rule here and a frontend doc appear to disagree,
this document states the geometric intent and [`../ICON_SYSTEM.md`](../ICON_SYSTEM.md) plus the frontend
`Icon` wrapper are the binding implementation; the conflict should be raised, not silently resolved.

Geometry is what makes a mixed icon set read as **one hand**. A finance screen is dense with glyphs — sort
indicators, status marks, row actions, nav icons — and the eye reads "drawn by the same designer" from
shared stroke weight, shared grid, and shared optical sizing long before it reads any individual meaning. Get
the geometry consistent and even a custom `ReconciliationMatch` disappears into the toolbar; get it wrong and
the whole product tips from "car/fashion brand" to "generic SaaS template."

# The Scale — Reference

## The keyline grid

Every Lucide glyph — and every QAYD custom glyph — is drawn on a **24×24 canvas** with a **2px minimum
padding** on all sides, leaving a **20×20 live area** the artwork lives inside. The padding is the safe
zone: a glyph's outermost stroke may approach but should not crowd the 24px edge, so icons of different
silhouettes optically balance at the same rendered size.

```
 0                         24   ← 24×24 viewBox
 ┌───────────────────────────┐
 │  ┌─────────────────────┐  │  ← 2px padding (safe zone)
 │  │                     │  │
 │  │     20×20 live      │  │  ← keyline shapes fit here
 │  │       area          │  │
 │  └─────────────────────┘  │
 └───────────────────────────┘
```

The standard keyline shapes an artwork snaps to inside the live area:

| Keyline | Extent (within 24×24) | Used for |
|---|---|---|
| Bounding square | 20×20, centered (2px inset) | Square-silhouette glyphs (`Square`, `Landmark` body) |
| Circle | Ø20, centered | Round glyphs (`Circle`, status marks, `CircleCheckBig`) |
| Portrait rectangle | ~16×20 | Tall glyphs (`FileText`, `Receipt`) |
| Landscape rectangle | ~20×16 | Wide glyphs (`CreditCard`, `Table`) |
| Corner radius | 2px | Rounded corners on rectangular forms |

Round line-caps and round line-joins throughout — no mitred corners, no flat caps — which is the single
strongest "same hand" signal Lucide carries and the one QAYD never breaks.

## Stroke weight

Lucide's source stroke is `2`. QAYD overrides it platform-wide in the `Icon` wrapper's default prop —
applied once, never passed ad hoc at a call site — because 2px reads slightly heavy and toy-like at
finance-screen density:

| Rendered size band | Stroke width | Why |
|---|---|---|
| `xs`–`lg` (14–24px), ~90% of usage | **1.75** | Thinnest weight that still holds cleanly at 1× (non-Retina) density and through the PDF export path |
| `xl`–`2xl` (32–40px), hero / empty-state | **1.5** | SVG stroke is a fixed physical thickness, not proportional — a 1.75px stroke correct at 20px looks bold at 40px unless thinned to preserve the same *visual* weight |

The design language summarizes this as "Lucide, 1.5px stroke, line-only"; the 1.75 / 1.5 split is that
principle made exact for the two size bands, and is binding.

## Size steps

Six tokens, **each landing exactly on Tailwind's 4px base grid**, each mapped to a `size-*` utility that
sets width and height together. The core trio **16 / 20 / 24** carries the overwhelming majority of the UI;
`14` handles dense meta, `32 / 40` handle hero moments.

| Token | px / rem | Tailwind | 4px multiple | Typical usage |
|---|---|---|---|---|
| `xs` | 14 / 0.875rem | `size-3.5` | 3.5× | Inline meta beside caption text, dense micro-badges, sort indicators |
| `sm` | 16 / 1rem | `size-4` | 4× | Form-field leading icons, inline status, dropdown-item icons, `Button size="sm"` |
| `md` | 20 / 1.25rem | `size-5` | 5× | **Default.** `Button size="default"`, table row actions, breadcrumb separators |
| `lg` | 24 / 1.5rem | `size-6` | 6× | Nav-rail module icons, `Button size="lg"`, section-header icons, palette leading icons |
| `xl` | 32 / 2rem | `size-8` | 8× | KPI/stat glyph, dialog-header icon, agent avatar glyph |
| `2xl` | 40 / 2.5rem | `size-10` | 10× | Empty-state hero icon, onboarding accents |

`md` (20px) is what the wrapper renders when no `size` is passed. **No icon usage ever specifies a raw pixel
size** (`size-[18px]`, `w-[22px]`); an in-between need is almost always a container-padding problem, not a
sizing one.

# Tokens & Naming

```
Grid + stroke  →  Icon wrapper default props  →  size-* utility on the rendered <svg>
24×24, 1.75px      <Icon size="md" />             size-5 (20px), stroke inherited
```

| Layer | Pattern | Example | Allowed value |
|---|---|---|---|
| Size token | `xs / sm / md / lg / xl / 2xl` | `<Icon size="lg" />` | One of the six tokens |
| Size utility | `size-{n}` on even/half-even steps | `size-4`, `size-5`, `size-6` | A token's mapped step only |
| Stroke | wrapper default, band-dependent | `strokeWidth={1.75}` | `1.75` (xs–lg) or `1.5` (xl–2xl) |
| Custom-glyph prop contract | identical to Lucide | `size = 24, strokeWidth = 1.75` | Matches the wrapper's expectations |

The naming is load-bearing in two ways. Sizes are **named tokens, not raw pixels**, so an engineer reaches
for `size="md"` and lands on the grid automatically; and stroke is a **wrapper default keyed to the size
band**, never a per-call `strokeWidth`, so a toolbar of icons can never drift to mixed weights.

# Usage

## Optical alignment & icon-to-text pairing

An icon beside text is sized to optically balance the text's cap-height, which for Lucide's padded 24×24
grid means ~1.0–1.15× the text's font-size in pixels:

| Text context | Font size | Paired icon token |
|---|---|---|
| Caption / meta | 12px | `xs` (14px) |
| Body / table cell | 14px | `sm` (16px) |
| Button label (default) | 14px | `md` (20px) |
| Section heading | 16–18px | `lg` (24px) |
| KPI / stat value | 28–32px | `xl` (32px) |

The gap to a paired label is **always** a `gap-*` utility on the flex container, never a manual margin on
the icon, so it stays correct as Arabic and English labels change length and direction. In lists (a
`DropdownMenu`, the Command Palette) the leading-icon gutter is a fixed-width `w-4` `shrink-0` cell so labels
across visually different-width glyphs (a wide `Landmark`, a narrow `Percent`) still start at the same
horizontal position and the glyph survives truncation.

## Pixel-snapping

Crispness at 1× density and at 100 / 125 / 150% browser zoom depends on the artwork and its container landing
on whole pixels:

- **Sizes land on the 4px grid.** Because every size token is a 4px multiple (14 is the one 2px-grid
  exception, still a whole `size-3.5` = 14px), an icon never sits half a pixel off-center when a row is
  scaled at 125%.
- **Interactive containers use even steps so padding divides evenly.** A `size-10` (40px) button around a
  `size-5` (20px) glyph centers with exactly 10px on every side. An odd-pixel container (39px) is never
  hand-built around an icon — it forces a sub-pixel glyph offset that blurs the stroke.
- **No raw-pixel one-offs.** `size-[18px]` and `w-[22px]` are banned precisely because they fall between grid
  steps; the fix for "20 is too big, 16 too small" is the surrounding padding, not an off-grid glyph.
- **Stroke stays whole-weight per band.** Mixing `strokeWidth` values within one size band (a stray `2` next
  to the default `1.75`) reads as two hands; the band default is the only permitted weight.

## Custom-glyph construction

A custom glyph (the sanctioned finance set — currency badge, `ThreeWayMatch`, `ReconciliationMatch`) is built
to the **same geometry** so it is indistinguishable from a Lucide glyph beside it:

1. Draw on a **24×24 viewBox** at **2px source stroke**, artwork inside the 20×20 live area, round caps and
   round joins, **no baked fill** (`fill="none"`, `stroke="currentColor"`).
2. Run SVGO to strip intrinsic `width`/`height` and any hardcoded color while preserving the `viewBox`.
3. Codegen a typed component whose prop contract is **identical to Lucide's** — `size` default `24`,
   `strokeWidth` default `1.75`, `aria-hidden="true"` — so the wrapper thins it to the same 1.75 / 1.5 band
   weight and treats it as a drop-in.

```tsx
// components/icons/custom/reconciliation-match.tsx — geometry matches Lucide exactly
export const ReconciliationMatch = forwardRef<SVGSVGElement, LucideProps>(
  ({ size = 24, strokeWidth = 1.75, className, ...props }, ref) => (
    <svg ref={ref} width={size} height={size} viewBox="0 0 24 24"
      fill="none" stroke="currentColor" strokeWidth={strokeWidth}
      strokeLinecap="round" strokeLinejoin="round"
      className={className} aria-hidden="true" {...props}>
      {/* two nodes on the grid, joined by a settled link + tick — inside the 20×20 live area */}
      <circle cx="6" cy="7" r="2.5" />
      <circle cx="18" cy="7" r="2.5" />
      <path d="M8.5 7 H15.5" />
      <path d="M8 16.5 l2.5 2.5 L16 13.5" />
    </svg>
  ),
);
```

Because the source stroke is 2px and the wrapper thins it identically, a custom glyph and a Lucide glyph in
the same toolbar carry identical *visual* weight — the whole point of matching the keyline and stroke.

# Do / Don't

| Do | Don't |
|---|---|
| Draw every glyph on the 24×24 grid inside the 20×20 live area | Let artwork crowd or cross the 2px safe-zone padding |
| Use round caps and round joins on every stroke | Mix in mitred corners or flat caps |
| Let the wrapper set stroke 1.75 (xs–lg) / 1.5 (xl–2xl) once | Pass a per-call `strokeWidth`, or mix weights in one toolbar |
| Size from the six-token scale, all on the 4px grid | Use a raw pixel size (`size-[18px]`, `w-[22px]`) |
| Build interactive containers on even steps so padding divides evenly | Hand-build a 39px container around an icon |
| Pair icon to text at ~1.0–1.15× the font-size, gap via `gap-*` | Add a manual margin on the icon or mismatch cap-height |
| Draw custom glyphs at 2px source stroke, no fill, `currentColor` | Bake a fill/color or ship a custom glyph off the 24×24 grid |
| Give custom glyphs the identical Lucide prop contract | Give a custom glyph a bespoke size/stroke API |

# Accessibility Notes

Geometry serves legibility and the WCAG target-size and contrast contracts owned in full by
[`../ICON_SYSTEM.md → Accessibility`](../ICON_SYSTEM.md); the grid-level obligations:

- **Target size vs. glyph size are separate concerns.** WCAG 2.2 SC 2.5.8 (Target Size, AA) sets a 24×24
  CSS-pixel floor for the *hit area*, not the glyph. A `size-4` (16px) glyph is fine inside a `size-10`
  (40px) button; the padded, even-step container — not the glyph's bounding box — is what is focusable and
  clickable, and it lands on whole pixels so the target is exactly the size it claims.
- **Crispness is legibility.** A stroke rendered off the pixel grid blurs, and a blurred glyph is harder to
  parse at a glance — the reason the size steps stay on the 4px grid and raw-pixel one-offs are banned. This
  matters most at the `xs`/`sm` sizes that carry dense table meta, where a Senior Accountant parses hundreds
  of rows.
- **Non-text contrast survives scaling.** Every icon stroke clears WCAG 1.4.11 (3:1) against its background
  in both themes; keeping the band stroke weight (1.75 / 1.5) means the glyph does not thin below a legible
  weight when the browser is zoomed or the icon rendered at 1× density.
- **Never encode state by geometry alone.** A distinct silhouette (a `CircleCheckBig` vs. a `CircleX`) helps,
  but shape is paired with text and the correct semantic color — never geometry as the sole carrier of a
  posted/void/overdue state (WCAG 1.4.1), per [`../ICON_SYSTEM.md → Color & currentColor`](../ICON_SYSTEM.md).
- **RTL flips position, not the grid.** The 24×24 grid and stroke are direction-agnostic; only *reading-order*
  glyphs mirror (via `mirrorRtl`), and spacing uses logical `ms-*`/`me-*` — the geometry contract is
  identical in English and Arabic. Value glyphs (`TrendingUp`/`TrendingDown`) never mirror; see
  [`../ICON_SYSTEM.md → RTL Mirroring Policy`](../ICON_SYSTEM.md).

# End of Document
