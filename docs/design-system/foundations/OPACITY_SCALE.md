# Opacity Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / OPACITY_SCALE
---

# Purpose

This document is the atomic reference for **opacity and alpha** in QAYD — the AI-native, bilingual
(English LTR / Arabic RTL) accounting platform. It fixes the small, closed ladder of alpha values the
product is allowed to use, names the semantic roles that consume each rung (disabled controls, muted
inline text, overlay scrims, glass-surface background floors, hover/pressed tint washes, softened
hairlines), and states the one constraint every translucency decision bends to: **legibility of numbers
first — content never drops below WCAG AA because a surface was made translucent.**

It is a specialization of, and never a contradiction to,
[`../GLASSMORPHISM_GUIDELINES.md`](../GLASSMORPHISM_GUIDELINES.md), which owns the `surface-glass` token
and the 72% / 80% background floors, and it draws its shadow-alpha rungs from
[`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md). The color tokens the alpha is applied *to* — the
warm-neutral `ink-1…12` scale, the brass `accent`, and the financial semantics — are defined in
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md). Where a rule here and a frontend doc appear to disagree,
this document states the intent and the frontend implementation is binding; the conflict should be raised,
not silently resolved.

Two disciplines motivate having an opacity *scale* at all. First, alpha is where a disciplined palette
quietly leaks: a stray `bg-black/30` scrim, a `text-ink-9/45` label, a `surface/0.6` glass floor invented
per screen, and the near-monochrome system fractures into a dozen almost-alike translucencies. Second,
alpha is the single most common way to accidentally sink text below AA — a figure that reads at full
opacity fails at 60%. A closed ladder plus semantic tokens keeps both in check.

# The Scale — Reference

QAYD uses one closed **alpha ladder**. Every translucency in the product resolves to a rung on this ladder;
no component invents an intermediate value (`/35`, `/45`, `0.55`). The rungs map directly onto Tailwind's
opacity-modifier syntax (`bg-primary/10`, `text-ink-9/60`, `bg-ink-12/50`) and onto `hsl(var(--x) / <a>)`
in raw CSS.

| Rung | Alpha | Tailwind modifier | Primary role in QAYD |
|---|---|---|---|
| `alpha-0` | `0` | `/0` | Fully transparent — enter/exit start/end state only |
| `alpha-04` | `0.04` | — | `shadow-xs` light-mode ink alpha (owned by elevation) |
| `alpha-06` | `0.06` | — | `shadow-sm` light-mode ink alpha (owned by elevation) |
| `alpha-08` | `0.08` | `/8` | Hover tint wash over content that can't take a solid ink step |
| `alpha-10` | `0.10` | `/10` | Selected/accent tint wash (`bg-primary/10`, AI-proposal ground) |
| `alpha-12` | `0.12` | `/12` | Pressed/active tint wash (one rung darker than hover) |
| `alpha-16` | `0.16` | — | `shadow-lg` light-mode ink alpha (owned by elevation) |
| `alpha-40` | `0.40` | `/40` | **Disabled** content; light command-palette / glass overlay scrim |
| `alpha-50` | `0.50` | `/50` | **Modal / drawer scrim** (`ink-12` @ 50%) |
| `alpha-60` | `0.60` | `/60` | Muted inline text via alpha; softened glass hairline border |
| `alpha-72` | `0.72` | `/72` | **Glass surface background floor — light** |
| `alpha-80` | `0.80` | `/80` | **Glass surface background floor — dark** |
| `alpha-100` | `1` | `/100` | Fully opaque — the default for every data surface and figure |

The dark-mode shadow rungs (`0.20 / 0.24 / 0.32 / 0.40`, `rgba(0,0,0,…)`) are the same ladder read against
black and are owned entirely by [`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md); they are listed there,
not re-declared here, so there is one source of truth per shadow token.

Everything at or below `alpha-16` in the table above that carries a "(owned by elevation)" note is a shadow
ingredient, not a surface alpha you apply directly — application code never writes `rgba(21,19,14,0.08)`; it
references `shadow-md`. The rungs an application surface applies directly are `0.08 / 0.10 / 0.12` (tint
washes), `0.40 / 0.50` (disabled, scrims), `0.60` (muted text, glass border), and `0.72 / 0.80` (glass
floors).

# Tokens & Naming

Alpha in QAYD is expressed two ways, and the naming keeps them distinct:

```
Ladder rung          →   applied as an opacity modifier on an existing color token
alpha-40 (0.40)          text-ink-8 opacity-40  |  bg-ink-12/40  |  hsl(var(--qayd-ink-6) / 0.60)
```

| Layer | Pattern | Example | Allowed value |
|---|---|---|---|
| Opacity utility (whole element) | `opacity-{rung}` | `opacity-40` (disabled) | A ladder rung only |
| Color + alpha modifier (Tailwind) | `{util}-{token}/{rung}` | `bg-ink-12/50`, `text-ink-9/60`, `bg-primary/10` | Token × ladder rung |
| Color + alpha (raw CSS) | `hsl(var(--x) / <a>)` | `hsl(var(--qayd-ink-1) / 0.72)` | Token × ladder rung |
| Glass surface floor | the `.surface-glass` token | never per-component | Owned by the glass token |

Two rules make the naming load-bearing. First, **alpha is always applied to a semantic color token, never
to a raw hex** — `bg-ink-12/50`, never `bg-[#15130E]/50` — so a scrim recolors correctly in dark mode with
no second asset. This is why the shadcn aliases are stored as unitless HSL triplets: the
`hsl(var(--x) / <alpha-value>)` form is exactly what lets `/10`, `/50`, `/60` work on every token without
extra plumbing (see [`../DESIGN_TOKENS.md → Token Tiers & Naming`](../DESIGN_TOKENS.md)). Second, **the glass
floors are never spelled out per component** — no screen writes `bg-ink-1/72`; it references `.surface-glass`,
whose definition owns the 72% / 80% floor centrally so the opacity floor and the reduced-transparency
fallback stay in one place.

# Usage

Each semantic role maps to exactly one rung. Reach for the role, not the number.

## Disabled — `alpha-40`

A disabled control or icon renders at `opacity-40`, dimming the whole element (glyph *and* label) together
so its state is unmistakable. Disabled is the **one** place QAYD accepts sub-AA contrast, and only because
the state is reinforced by `aria-disabled`, `cursor-not-allowed`, and usually a tooltip — never by the dim
alone (see [Accessibility Notes](#accessibility-notes)). Icon color is never the sole carrier of the
disabled state; the alpha rides on top of the normal ink token.

```tsx
<Button disabled className="opacity-40 cursor-not-allowed" aria-disabled="true">
  <Icon icon={NotebookPen} /> {t("journal.new")}
</Button>
```

## Muted inline text — `alpha-60`

Where a piece of inline text should read as *secondary to its neighbor* but the layout can't switch to a
lighter ink step (e.g. a caption trailing a value in the same run), apply `/60` to the text token:
`text-ink-9/60`. Prefer stepping down the **ink scale** (`ink-11` → `ink-9`) over adding alpha wherever the
background is known and opaque; the alpha form is for the cases where a solid step would over- or
under-shoot against a variable neighbor. `alpha-60` is the floor for meaningful muted text — never mute
readable copy below it.

## Overlay scrims — `alpha-50` (modal) and `alpha-40` (command / glass)

A scrim is a semi-opaque `ink-12` wash beneath a floating surface; it is **never blurred** (blur belongs to
the glass panel, not its scrim — one `backdrop-filter` layer per moment).

| Scrim | Token | Rung | Behind |
|---|---|---|---|
| Modal / drawer scrim | `bg-ink-12/50` | `alpha-50` | Dialog, Sheet content (`surface-3`) |
| Command palette / AI Command Center scrim | `bg-ink-12/40` | `alpha-40` | The two glass surfaces (`surface-glass`) |

The glass overlays sit above a *lighter* scrim (`/40`) than modals (`/50`) because the frosted panel itself
already separates strongly from the app; a full `/50` under glass would read as double-darkening. One scrim
per visible overlay depth — a dialog opened from a sheet shows only the dialog's scrim.

## Tint washes — `alpha-08` / `alpha-10` / `alpha-12`

Reserved for a translucent brand/ink tint laid *over content that cannot take a solid ink step* — a hovered
row already carrying a background image, an accent-selected state on a colored surface, an AI-proposal
ground. On an ordinary opaque surface, QAYD prefers a solid ink-step swap (`hover:bg-ink-4`,
`active:bg-ink-5`, `bg-accent-subtle` for a selected row) over an alpha wash, because a solid step is
cheaper and never compounds unpredictably against whatever shows through. When an alpha wash is genuinely
needed, the ladder is hover `0.08`, selected `0.10`, pressed `0.12` — one rung apart, always drawn from
`accent` or `ink-12`, never a new hue.

## Glass floors & border — `alpha-72` / `alpha-80` / `alpha-60`

The glass surface background is `ink-1 @ 72%` (light) / `ink-3 @ 80%` (dark), with a softened `ink-6 @ 60%`
hairline. These are **floors, not dials**: 72% / 80% is the *minimum* opacity at which the surface's own
content clears AA. They live only inside the `.surface-glass` token — application code never writes them.
Full recipe, fallbacks, and the two-surface budget are in
[`../GLASSMORPHISM_GUIDELINES.md → The Token & CSS Recipe`](../GLASSMORPHISM_GUIDELINES.md).

```css
/* the ONLY place the glass floors appear — never per component */
.surface-glass       { background-color: hsl(var(--qayd-ink-1) / 0.72); border: 1px solid hsl(var(--qayd-ink-6) / 0.60); }
.dark .surface-glass { background-color: hsl(var(--qayd-ink-3) / 0.80); }
```

# Do / Don't

| Do | Don't |
|---|---|
| Resolve every translucency to a rung on the closed ladder | Invent an intermediate alpha (`/35`, `/45`, `0.55`) per screen |
| Apply alpha to a semantic color token (`bg-ink-12/50`) | Apply alpha to a raw hex (`bg-[#15130E]/50`) |
| Dim disabled controls at `opacity-40` with `aria-disabled` reinforcing | Rely on the dim alone to signal disabled |
| Prefer a solid ink-step swap on opaque surfaces; use tint washes only over content | Reach for a `/10` wash where `bg-ink-4`/`bg-accent-subtle` would do |
| Keep glass floors (72% / 80%) inside the `.surface-glass` token | Hand-roll `bg-ink-1/72` on a component |
| Scrim with `ink-12/50` (modal) or `ink-12/40` (glass), never blurred | Blur a scrim, or stack two scrims for one interaction |
| Verify content on a translucent surface clears AA against the worst-case blend | Drop opacity "for more glass" and sink text below AA |
| Keep the softened `ink-6/60` hairline visible in both themes | Remove the glass border to look "cleaner" |
| Let the dark-shadow alpha rungs live in `ELEVATION_SHADOWS.md` | Re-declare shadow alphas here or inline `rgba()` in a component |

# Accessibility Notes

Legibility is the constraint every opacity decision bends to; QAYD targets **WCAG 2.1 AA** as a floor in
both themes and both `dir` values.

- **AA over translucency.** Any text or meaningful icon rendered *on* a translucent surface must clear its
  normal target — 4.5:1 for body text, 3:1 for non-text/large — against the surface's **effective** color,
  i.e. the worst-case blend of the surface tint over a plausibly-busy backdrop, not against a clean opaque
  swatch. The 72% / 80% floors exist precisely so this holds; lowering them to "add glass" is trading away
  legibility and the token forbids it. Full treatment in
  [`../GLASSMORPHISM_GUIDELINES.md → Contrast & Legibility Over Translucency`](../GLASSMORPHISM_GUIDELINES.md).
- **Alpha never carries a number.** A financial figure demands maximum legibility and a translucent surface
  can never guarantee it against an arbitrary backdrop, so figures live on **fully opaque** surfaces
  (`alpha-100`) — always. This is the debit/credit-legibility rule restated as an alpha rule.
- **Disabled is the only sub-AA exception.** `opacity-40` on a disabled control may fall below 4.5:1; it is
  accepted *only* because the state is also conveyed by `aria-disabled`, `cursor-not-allowed`, and a
  tooltip. State is never carried by dim alone (WCAG 1.4.1).
- **Focus rings stay full-strength.** The accent focus ring on a control inside a glass or tinted surface
  renders at `alpha-100`, never inheriting the surface's translucency, so keyboard focus is always
  unmistakable (WCAG 2.4.7 / 1.4.11).
- **Muted text has a floor.** `alpha-60` (or a step down the ink scale) is the limit for meaningful muted
  text; copy a user must read is never taken below it.
- **Reduced transparency.** When a user requests OS-level reduced transparency
  (`@media (prefers-reduced-transparency: reduce)`), the glass floors resolve to `alpha-100` (fully opaque)
  and the blur is dropped — the surface and its content remain intact, only the translucency is removed.
  Because both glass surfaces reference `.surface-glass`, honoring the preference is centralized in one
  place; see [`../GLASSMORPHISM_GUIDELINES.md → Reduced-Transparency Accessibility`](../GLASSMORPHISM_GUIDELINES.md).

# End of Document
