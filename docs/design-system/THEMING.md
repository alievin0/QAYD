# Theming Architecture — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: THEMING
---

# Purpose

This document specializes [`../frontend/THEMING.md`](../frontend/THEMING.md) for the Design System
module. Where the frontend Theming doc defines theming as one layer of the Next.js web client, this
document defines theming as an **architecture** the whole design system is built on — the pipeline that
carries a value from the design-language decision that authored it, through CSS custom properties, into
Tailwind and shadcn/ui, and out onto a pixel — and the contract that keeps that pipeline coherent across
light and dark themes, LTR and RTL directions, per-user preferences, and per-company white-label builds.

It does not re-derive what the sibling and frontend docs already fix. It assumes and cross-references them:

- [`./DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) and [`./COLOR_SYSTEM.md`](./COLOR_SYSTEM.md) — the **canonical
  token catalogue and color depth** for this module: the `--qayd-ink-1…12` scale, the single brass accent,
  the financial-semantic set, and the shadcn/ui semantic-alias layer that consumes them. These mirror
  [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md), which remains the literal value source;
  nothing here restates a full value table, it references them. [`./DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md)
  states the taste rules (ink-before-color, one accent) this document implements.
- [`../frontend/THEMING.md`](../frontend/THEMING.md) — the **pipeline and provider mechanics** (three-tier
  tokens, `next-themes`, SSR cookie, `BrandProvider`, density/text-size, RTL-as-a-dimension).
- [`./DARK_MODE.md`](./DARK_MODE.md) and [`./LIGHT_MODE.md`](./LIGHT_MODE.md) — the two themes as
  first-class peers, each specifying every state in its own theme; this document defines the machinery that
  makes a theme a **remap** rather than a re-skin, and defers the per-theme palettes to those two docs.
- [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) — WCAG 2.2 AA, the floor every token
  pairing in every theme is re-verified against.

The house rules this document exists to protect, stated once:

1. **Light and dark are both first-class.** Dark mode is a token remap, never `filter: invert()`.
2. **System-aware with a persisted manual override.** `next-themes` resolves `system` by default; an
   explicit user choice is persisted (cookie + server) and never silently reverted.
3. **One accent, near-monochrome ink.** The accent is the only white-label-overridable hue; structure,
   spacing, and type are not overridable.
4. **Direction is orthogonal to theme.** LTR/RTL and light/dark are four independent, all-tested
   combinations, governed by tokens, not by `if (locale === "ar")` branches.
5. **Components are theme-agnostic.** A component references semantic or component tokens only; it never
   knows which theme, direction, or tenant it is rendering into.

## A note on canonical values (reconciliation)

The three source docs were authored at different moments and do not use one identical token vocabulary:
`DESIGN_LANGUAGE.md` names the scale `--qayd-ink-1…12` with a **brass** accent (`#9C7A34` light /
`#D9B96C` dark); `../frontend/THEMING.md` illustrates the same pipeline with role-named semantics
(`--color-bg-canvas`, `--color-accent`) and an emerald default; `../frontend/DARK_MODE.md` uses
`--surface-*` / `--ink-*` OKLCH primitives. These are three notations for **one** architecture, not three
architectures. This document treats the sibling [`./DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) /
[`./COLOR_SYSTEM.md`](./COLOR_SYSTEM.md) values as canonical (they mirror `DESIGN_LANGUAGE.md`, the literal
source), and `../frontend/THEMING.md`'s three-tier role layer as the canonical pipeline shape. Concrete
values below are the brass/ink set. **The semantic tier's concrete implementation is the shadcn/ui alias
layer defined in [`./COLOR_SYSTEM.md`](./COLOR_SYSTEM.md)** — `--background`, `--foreground`, `--card`,
`--primary`/`--primary-foreground` (= `--qayd-accent`/`--qayd-accent-on`), `--border`, `--input`, `--ring`,
`--destructive` (= `--qayd-negative`), plus the important trap that shadcn's own neutral `--accent` is a
hover tint, **not** the brand brass. Where this document writes a `--color-{role}` name it is role
shorthand for that alias layer, used to keep the theming-architecture discussion notation-neutral; the
concrete variable a component compiles against is the shadcn alias in `COLOR_SYSTEM.md`. Reconciling the
source vocabularies into the single `@qayd/tokens` package (see Governance) is tracked as design-system
debt, not re-opened here.

# The Token Pipeline

Every value the UI renders travels one path, and application code only ever touches the last two stops:

```
DESIGN_LANGUAGE decision
        │  (a taste call: "one brass accent", "ink is warm-neutral", "radius ≤ 10px")
        ▼
Primitive token            --qayd-ink-7, --qayd-accent, --radius-lg, --space-lg
        │  (raw, context-free scale value — the ONLY tier holding a literal)
        ▼
Semantic token             --color-fg-secondary, --color-accent, --color-border-subtle
        │  (a role, = var(--primitive); THIS is the tier that changes between light and dark)
        ▼
Component token            --button-primary-bg, --card-bg, --table-row-hover-bg
        │  (component-scoped, = var(--semantic); never references a primitive)
        ▼
Tailwind class / shadcn CSS var   bg-accent, text-foreground-secondary, border-border
        │
        ▼
pixel
```

The indirection is the entire point. Flipping to dark, applying a tenant's brand, bumping density, or
mirroring for RTL each re-points **one** arrow in this chain; no component's CSS is edited. This is why
[`./DARK_MODE.md`](./DARK_MODE.md) can specify a whole theme as a table of semantic re-points, and why a
white-label build (below) is a five-variable patch, not a re-theme.

## Tier rules

| Tier | Names | May contain | Changes across theme? | Referenced by |
|---|---|---|---|---|
| Primitive | `--qayd-ink-{1..12}`, `--qayd-accent*`, `--space-*`, `--radius-*`, `--text-*`, `--shadow-*` | Literal hex / rem / px / ms / cubic-bezier | Only the ink & accent & shadow families are re-authored per theme; scale/type/radius are theme-invariant | Semantic tier only (never components) |
| Semantic | `--color-{role}` (`bg-canvas`, `fg-primary`, `border-subtle`, `accent`, `positive`, `negative`, `warning`) | `var(--primitive)` only | **Yes — this is the remap layer** | Component tier and Tailwind config |
| Component | `--{component}-{part}[-{state}]` | `var(--semantic)` only | No — inherits the semantic remap for free | Component source only |

A component author writing `background: #9C7A34`, `background: var(--qayd-accent)`, or even a raw Tailwind
palette utility (`bg-amber-500`) is a defect caught in CI (see Governance and
[`./DARK_MODE.md`](./DARK_MODE.md) → the token-drift lint). Only semantic/component tokens
(`bg-accent`, `text-foreground`, `var(--card-bg)`) are legal targets in application code.

## Worked flow

```
--qayd-accent (primitive, literal #9C7A34 in light / #D9B96C in dark)
        ▼
--color-accent (semantic → var(--qayd-accent))
        ▼
--button-primary-bg (component → var(--color-accent))   ── or ── --brand-accent (white-label)
        ▼
<Button variant="primary">  →  background: var(--button-primary-bg)
```

Between light and dark, only the primitive→semantic segment is re-authored (the primitive literal itself
carries a light and a dark value; the semantic role points at the active one). Between the platform brand
and a tenant brand, only the accent semantic re-points at a runtime-computed value. The `Button` file is
untouched in every case.

# Theme Provider (`next-themes`)

QAYD renders the light/dark/system dimension with `next-themes`, mounted once, above the router boundary,
inside a Client Component (`app/providers.tsx`) imported into the Server-Component root layout. The
mechanics are specified in full in [`../frontend/THEMING.md`](../frontend/THEMING.md) → *Theme Provider*
and [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) → *Persistence & SSR*; the design-system
contract is the four configuration decisions that every downstream doc depends on:

```tsx
// app/providers.tsx  ("use client")
<ThemeProvider
  attribute={["class", "data-theme"]}  // (1) dual attribute
  defaultTheme={initialTheme}          // (2) seeded from the cookie by the server layout
  enableSystem                         // (3) system-aware
  disableTransitionOnChange            // (4) no color-wave on toggle
  storageKey="qayd-theme"
>
```

1. **Dual attribute.** `next-themes` sets both `class="dark"` (Tailwind's `dark:` variant selector) and
   `data-theme="dark"` (read by anything outside Tailwind's compiled pipeline — a `<canvas>` chart, an SVG
   `fill` set from JS, a print stylesheet, a partner iframe). The two are kept in lockstep and are never
   permitted to disagree; both are authoritative for their own consumer class.
2. **Server-seeded default.** The root `app/layout.tsx` (a Server Component) reads the `qayd-theme` cookie
   and renders `<html>` with the correct attribute **already present**, so a returning user never flashes
   the wrong theme. `system` is the only value the server cannot resolve (HTTP carries no
   `prefers-color-scheme`); that case is closed by the pre-hydration inline script, below.
3. **System-aware.** With `enableSystem`, a `matchMedia("(prefers-color-scheme: dark)")` listener stays
   attached for the session, so an OS auto-switch at sunset is followed live — unless the user has picked
   an explicit `light`/`dark`, which always wins.
4. **`disableTransitionOnChange`.** Mandatory, not cosmetic: it injects a one-frame
   `* { transition: none !important }` around the class swap so dozens of `transition-colors` elements do
   not animate independently into a visible "wave" across the screen. See Runtime Switching.

## SSR-safe, no-flash hydration

Three layers, strongest first, guarantee a page is never visibly painted in the wrong theme:

| Layer | Covers | Mechanism |
|---|---|---|
| 1. Server class from cookie | Returning user with an explicit `light`/`dark` | `app/layout.tsx` reads `cookies().get("qayd-theme")` and renders the `class`/`data-theme` before the first byte |
| 2. Pre-hydration inline script | `system` preference, first-ever visit | `next-themes` injects a synchronous `<head>` script that reads `localStorage` / `matchMedia` and sets the attribute before first paint |
| 3. Pure-CSS fallback | JS blocked/disabled (login, marketing) | `<meta name="color-scheme" content="light dark">` + a critical `@media (prefers-color-scheme: dark)` block |

`<html>` carries `suppressHydrationWarning` — scoped to that one element only — because the inline script
may legitimately change the attribute between the server render and hydration (resolving `system` to
`dark`); applying it any wider would swallow real hydration bugs.

## Persistence

Theme is a **per-user** preference (it follows the human, not the tenant — a company switch never
re-resolves theme). It persists in two places for two reasons: the `qayd-theme` **cookie** exists so the
Server Component can read it pre-render (no-flash), and `users.settings.ui_theme` (JSONB) exists so the
choice follows the user to a new device. The write is fire-and-forget and optimistic — the toggle never
blocks on the network (see Runtime Switching). An organization MAY seed a one-time default for new members
via `companies.settings->'default_ui_theme'` (gated by `company.settings.update`, hidden entirely for
users without it); a personal override always wins the instant it is set. Full schema and endpoints are in
[`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) → *Persistence & SSR* and
[`../frontend/THEMING.md`](../frontend/THEMING.md) → *Density & Text-Size Preferences*.

# Tailwind & shadcn/ui Binding

Tailwind never hardcodes a color; every `theme.extend.colors` entry resolves to a semantic CSS variable so
that Tailwind's opacity modifiers (`bg-accent/10`, `text-negative/60`) and shadcn/ui's own
`hsl(var(--x))` convention work on every token with no extra plumbing.

```ts
// tailwind.config.ts (shape — full listing in ../frontend/THEMING.md)
const config: Config = {
  darkMode: ["class"],                       // pairs with next-themes attribute="class"
  content: ["./app/**/*.{ts,tsx}", "./components/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        canvas: "var(--color-bg-canvas)",
        surface: "var(--color-bg-surface)",
        foreground: "var(--color-fg-primary)",
        "foreground-secondary": "var(--color-fg-secondary)",
        border: "var(--color-border-subtle)",
        accent: {
          DEFAULT: "var(--color-accent)",
          hover: "var(--color-accent-hover)",
          subtle: "var(--color-accent-subtle)",
          foreground: "var(--color-fg-on-accent)",
        },
        positive: "var(--color-positive)",
        negative: "var(--color-negative)",
        warning: "var(--color-warning)",
      },
      borderRadius: { sm: "var(--radius-sm)", md: "var(--radius-md)", lg: "var(--radius-lg)", xl: "var(--radius-xl)" },
      fontFamily: {
        display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif"],
        text: ["var(--font-text)", "var(--font-arabic)", "ui-sans-serif"],
        arabic: ["var(--font-arabic)", "ui-sans-serif"],
        mono: ["var(--font-mono)", "ui-monospace"],
      },
      boxShadow: { xs: "var(--shadow-xs)", sm: "var(--shadow-sm)", md: "var(--shadow-md)", lg: "var(--shadow-lg)" },
    },
  },
  plugins: [require("tailwindcss-animate")],
};
```

Because a semantic variable resolves to a *different* value under `[data-theme="dark"]` / `.dark`, a
utility like `bg-surface` is **already theme-correct with no `dark:` prefix** — the `dark:` variant is
reserved for the rare call site that mixes a token color with a genuinely one-off physical treatment
(e.g. `shadow-sm dark:shadow-none`, the elevation exception in [`./DARK_MODE.md`](./DARK_MODE.md)).

Two shadcn/ui conventions carry into every component: `cn()` (`clsx` + `tailwind-merge`) for conflict-free
class composition, and `cva` for the typed variant matrix that maps a variant name to token-referencing
classes — never to a raw color. Tailwind's **logical-property utilities** (`ms-*`, `pe-*`, `start-*`,
`text-start`, `rounded-s-*`) are the default for all spacing/alignment so RTL mirrors for free; physical
utilities (`ml-*`, `left-*`) are a CI-flagged review blocker outside a short allow-list (see RTL below).

# Theme = Remap, Not Re-skin

The single most important architectural claim of this document, and the reason
[`./LIGHT_MODE.md`](./LIGHT_MODE.md) and [`./DARK_MODE.md`](./DARK_MODE.md) can each be a compact table
rather than a component-by-component rewrite: **a theme is a one-block re-point of the semantic tier.**

```css
/* app/globals.css (shape — canonical values in ../frontend/DESIGN_LANGUAGE.md) */
:root {                         /* primitives carry BOTH light and dark literals via the two blocks below */
  --color-bg-canvas: var(--qayd-ink-1);
  --color-fg-primary: var(--qayd-ink-12);
  --color-fg-secondary: var(--qayd-ink-9);
  --color-border-subtle: var(--qayd-ink-6);
  --color-accent: var(--qayd-accent);
  --color-fg-on-accent: var(--qayd-accent-on);
  --color-positive: var(--qayd-positive);
  --color-negative: var(--qayd-negative);
  --color-warning: var(--qayd-warning);
  color-scheme: light;
}
[data-theme="dark"], .dark {
  /* the SAME semantic names re-point; the primitive literals are the dark column of
     DESIGN_LANGUAGE.md's ink/accent/semantic tables — nothing below is a runtime inversion */
  --qayd-ink-1: #14130F;  --qayd-ink-12: #F8F6EF; /* …the full dark ramp… */
  --qayd-accent: #D9B96C; --qayd-accent-on: #14130F;
  --qayd-positive: #4ADE94; --qayd-negative: #F26B74; --qayd-warning: #F5A855;
  color-scheme: dark;
}
```

`color-scheme` is set in both blocks so browser-native chrome — scrollbars, `<select>` panels, date
pickers, autofill highlight, focus outlines — matches the theme without bespoke styling, and so
heuristic OS "force dark" does not double-process an already-dark page. Three remap invariants bind every
per-theme value in the sibling docs:

1. **Elevation inverts direction.** Light communicates "on top" primarily by shadow; dark communicates it
   by making surfaces progressively **lighter** plus a hairline border. (Detailed per theme in the two
   sibling docs.)
2. **The accent holds its hue, not its lightness.** Brass in both themes, but lighter in dark
   (`#9C7A34` → `#D9B96C`) so it keeps AA against `accent-on` and the darker canvas — never brighter for
   "pop," which would break the on-accent pairing.
3. **AA is re-verified per theme.** A pairing that passes on white and fails on near-black is a defect,
   not an acceptable dark-mode trade-off; the CI contrast script runs both themes independently.

# Company Branding / White-Label

QAYD is white-labelable per company on a **deliberately narrow, curated surface — accent color and logo
only** — never the full design system. This reuses the existing `company_settings.branding` JSONB column
(already the source for server-side PDF branding), so a tenant's brand is identical between their live
dashboard and their exported documents: one field, two renderers, zero drift. The full schema, delivery
via `GET /api/v1/auth/me`, and the `deriveAccentRamp()` OKLCH derivation are specified in
[`../frontend/THEMING.md`](../frontend/THEMING.md) → *Company Branding / White-Label*; the design-system
contract is **what is overridable and what is structurally not.**

## The override surface

| Overridable (per company) | Mechanism | NOT overridable (structural) |
|---|---|---|
| Accent color (one hex in) | Derived to hover/active/subtle/on-accent by `deriveAccentRamp()` (OKLCH) | The `ink-1…12` neutral ramp |
| Logo (light + dark variant) | Two pre-authored asset URLs, swapped by resolved theme | Spacing scale, radius scale, type scale/families |
| Favicon, wordmark lockup mode | `favicon_url`, `wordmark_display` | Motion curves/durations, elevation model, financial-semantic colors |

Components stay theme- **and** tenant-agnostic through one indirection: only the `primary` button variant
(and the handful of genuinely brand-carrying affordances) resolves through the `--brand-*` custom-property
set that `BrandProvider` writes onto `document.documentElement`; every other variant resolves through the
plain, non-brandable semantic tokens. This is how "only the accent is white-labelable" is enforced at the
component level, not merely by schema:

```css
/* the ONLY five custom properties BrandProvider may ever write */
:root {
  --brand-accent:            var(--color-accent);
  --brand-accent-hover:      var(--color-accent-hover);
  --brand-accent-active:     var(--color-accent-active);
  --brand-accent-subtle-bg:  var(--color-accent-subtle);
  --brand-on-accent:         var(--color-fg-on-accent);
}
```

## Guardrails (architecture, not policy)

1. **Fixed schema.** The `branding` JSON has fields for accent + logo/favicon and nothing else — a tenant
   cannot request typography or spacing the API has no field to accept.
2. **Perceptual clamp.** Both the Laravel `FormRequest` and the frontend Zod schema reject an accent whose
   OKLCH lightness falls outside a calm band or whose chroma exceeds the palette ceiling, returning
   `422 BRAND_COLOR_OUT_OF_GAMUT` — this is what stops a neon or a washed-out pastel that would fail AA or
   clash with the near-monochrome system.
3. **Derived, not hand-authored ramps.** Hover/active/subtle/on-accent are computed, so a tenant cannot
   submit an inconsistent ramp — there is no field for one; `on-accent` is picked as the higher-contrast of
   near-white / near-ink automatically.
4. **Permission-gated + preview-before-save.** Editing requires `settings.branding.manage` (Owner/CEO,
   single-step approval). A candidate accent is applied to `BrandProvider` in session-local, unpersisted
   preview state; nothing is written, and no other user sees anything, until saved.
5. **Fail safe.** `branding = {}`, a missing field, or a clamp-failing value degrades gracefully to the
   platform default accent and wordmark — never an empty or invalid CSS variable, never a broken screen. A
   logo-less company falls back to a neutral initials monogram, never a name-hashed color that could fail
   contrast.
6. **Reserved-token separation.** A tenant accent is scoped to `--accent-*`/`--brand-*` and can never
   touch `--positive`/`--negative`/`--warning` or the AI-provenance treatment — so a customer's brand
   color can never collide with the finance-success green or an AI affordance by construction.

Branding carries no AI agent and no separate read permission (it is bundled into `/auth/me` for every
authenticated member — withholding a company's own logo from its own staff serves no purpose).

# Density, Text-Size & Direction as Theming Dimensions

Theming in QAYD is more than light/dark. Three further dimensions ride the same SSR-safe, cookie-seeded,
no-flash machinery, each set on `<html>` in the same server render pass as `data-theme`:

- **Density** (`data-density="comfortable|compact"`, per-user) scales only data-dense contexts — table row
  height and cell padding — via a `--density-scale` multiplier applied at the point of use, never a blanket
  transform; page margins and card padding stay constant so the app never feels globally cramped.
- **Text size** (`data-text-scale="small|default|large"`, per-user) swaps the root `font-size`; because
  every type primitive is `rem`-based, the whole hierarchy scales from one variable, composing
  multiplicatively with browser/OS zoom.
- **Direction** (`dir="ltr|rtl"`, derived from `users.locale` → `languages.direction`) is treated with the
  same architectural weight as color scheme: resolved server-side, no flash, governed by logical-property
  tokens rather than scattered locale branches.

Direction is **orthogonal** to theme — four first-class, all-tested combinations
(`{ltr,rtl} × {light,dark}`), never three tested plus one assumed:

```html
<html lang="ar" dir="rtl" data-theme="dark" class="dark" data-density="compact" data-text-scale="default">
```

RTL correctness is a token concern, not a color concern: logical-property utilities mirror automatically
under `dir`; a curated `.rtl-flip` (`scaleX(-1)`) opt-in mirrors only genuinely directional icons
(navigational chevrons/arrows), never checkmarks, logos, currency symbols, or numerals; and every amount
is wrapped `dir="ltr"` + `tabular-nums` so Gulf-convention Western digits keep their order inside an
Arabic sentence. Full mechanics — icon-mirror table, per-script fonts, motion mirroring via a direction
sign — are in [`../frontend/THEMING.md`](../frontend/THEMING.md) → *RTL as a theming dimension*; the
design-system point is only that direction is a **theming axis with the same rigor as color**, so a
component that is theme-agnostic is direction-agnostic by the same discipline.

# Runtime Switching

A user toggling theme, density, or text-size at runtime triggers no server round trip for the toggle
itself — only a background persist so it follows them to the next session.

```tsx
// components/theme-toggle.tsx ("use client") — three-way segmented control (System / Light / Dark)
function onSelect(next: "system" | "light" | "dark") {
  setTheme(next);        // instant: flips class + data-theme + qayd-theme cookie, same frame
  persist({ theme: next }); // PATCH /users/me — optimistic, fire-and-forget, no spinner
}
```

Three mechanics keep the switch clean rather than jarring:

1. **`disableTransitionOnChange`** suppresses per-element color transitions for one frame around the class
   swap, so the switch is instant everywhere except the toggle's own icon (which gets a deliberate short
   cross-fade — that one animation is the point).
2. **Cookie write is synchronous with the class change**, so the next full navigation/refresh has the
   correct value server-side before it renders — no second-page flash.
3. **The persist is optimistic** — the UI never blocks on it; a failure is retried silently and, worst
   case, means the preference didn't sync to another device this time, not that the toggle failed.

`AppProviders` lives above the router boundary, so App Router client navigations never remount it and never
re-flash theme/brand/density/direction. A **company switch re-themes without remounting**: switching
`X-Company-Id` invalidates the `["auth","me"]` query and `BrandProvider` patches only its five `--brand-*`
variables — the route tree, any open dialog, and any in-progress form's local state survive untouched.
Theme is unaffected by a company switch (it lives on the user, not the tenant). Where supported and where
`prefers-reduced-motion` is unset, a toggle may layer the View Transitions API cross-fade on top, never as
a replacement for `disableTransitionOnChange`.

# Governance

Tokens are the one place a component author's local judgment is deliberately constrained, because a single
off-convention color copied once tends to get copied again.

**Ownership.** A Design Systems function owns the primitive and semantic tiers of `--color-*`, `--space-*`,
`--radius-*`, `--text-*`, `--shadow-*`, `--duration-*`, `--ease-*`. Any engineer may propose a change by
PR; merging requires Design Systems review, as a schema migration requires backend review.

| Change | Review required |
|---|---|
| New primitive (new ramp/scale step) | Design Systems |
| New semantic token (new `--color-{role}`) | Design Systems |
| New component token resolving to an existing semantic | Normal code review |
| Existing token used in a new place | Normal code review |
| Any raw hex / `rgb()` / palette-utility / pixel literal in component code | Blocked in CI |

**A new semantic token's PR must ship both a light and a dark value** (never "one theme now, dark later"),
the contrast-script output for every `fg`/`bg` pairing it participates in, and a note on whether it needs a
logical-property (RTL) counterpart.

**Automated enforcement** (all in the same CI job as `tsc --noEmit` + Vitest):

- `scripts/check-contrast.ts` — computes every `fg`/`bg` pairing in **both** themes; fails under 4.5:1
  body / 3:1 large / 3:1 boundary. See [`./LIGHT_MODE.md`](./LIGHT_MODE.md) and
  [`./DARK_MODE.md`](./DARK_MODE.md) for the per-theme pass tables it guards.
- `scripts/check-no-raw-colors.sh` — greps `components/`/`app/` for raw palette utilities and hex literals
  outside `globals.css` and fails the build.
- A Stylelint rule bans referencing a `--qayd-{primitive}` from anywhere but `globals.css`, and a name
  regex rejects a stray `--brandColor` / `--Accent`.

**Cross-platform parity.** Tokens ship as an internal, semver'd `@qayd/tokens` package — a generated
`globals.css` block for web and a generated Dart `ThemeExtension` for the Flutter app — built from one
canonical source so web and mobile cannot silently drift. Renaming a token keeps the old name as an
aliased, console-warning deprecation for at least one minor version. A quarterly drift audit greps shipped
code for inline `style={{ color }}` literals and either promotes each into a real token or replaces it —
never leaves it as a silent exception. Reconciling the three source vocabularies (see Purpose) into this
one package is the first item on that audit's backlog.

# Edge Cases

- **Server Components cannot call `useTheme()`.** A server fragment that would *branch its JSX* on theme
  must defer to a client boundary or, preferably, render both variants in CSS and let `[data-theme]`
  choose — theme changes how something is painted, never which DOM nodes exist. The narrow exception is a
  component that must pick between two literally different assets (the logo's light/dark SVG), which uses a
  `mounted` client guard.
- **Print.** `@media print` forces light, ink-only, no accent tints, no shadows — a printed trial balance
  must read on a black-and-white office printer regardless of on-screen theme. This is distinct from PDF
  *generation*, which Laravel renders server-side from a static branding snapshot (below).
- **PDF snapshot immutability.** A PDF invoice bakes in `company_settings.branding` as of issuance; a later
  accent change never alters previously issued documents (consistent with posted-document immutability),
  while the live dashboard always reflects current branding — intentionally different behaviors for one
  field.
- **Forced-colors / high-contrast.** Under `forced-colors: active`, QAYD defers to system color keywords
  for borders/focus/disabled rather than fighting the OS with its own tokens.
- **Stale branding on a shared device.** On sign-out, `BrandProvider` resets every `--brand-*` variable to
  the platform default and clears the branding query cache *before* the next login screen renders — a kiosk
  never flashes the previous tenant's logo.
- **Embedded/iframe contexts.** A QAYD surface embedded in a partner site scopes its root selector
  (`:where()`-wrapped) so host CSS cannot leak in and QAYD tokens cannot leak out.
- **Color is never the only signal.** A financial delta always pairs its `--negative`/`--positive` hue with
  a leading sign (or parentheses in rendered statements) and, where space allows, a directional glyph — so
  colorblind users and grayscale printing never lose meaning. This is a token *usage* rule the semantic
  colors are governed by, cross-referenced from both sibling docs.

# End of Document
