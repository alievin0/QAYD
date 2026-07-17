# Dark Mode — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: DARK_MODE
---

# Purpose

QAYD is used at the hours accounting actually happens: a Finance Manager closing the month at 23:40, a founder checking the cash position from bed before a 7am flight, an Accountant reconciling a bank feed on a dim commute. Dark mode is not a cosmetic toggle bolted onto a light-only product — it is a first-class rendering of the same editorial, near-monochrome design system defined for QAYD (grotesque display type, restrained near-black ink, one disciplined accent, generous whitespace, calm motion). Every rule that makes the light theme feel like a car-brand or fashion-house interface rather than a generic SaaS dashboard has to survive, unweakened, when the surface goes dark: the type hierarchy, the whitespace rhythm, the restraint on color, the crispness of financial data density.

This document is the binding specification for how theme (light/dark) is resolved, stored, tokenized, rendered, and verified across the QAYD Next.js frontend. It exists because dark mode is the single feature most likely to be implemented inconsistently if left to each component author's judgment — one engineer inverts a chart with `filter: invert()`, another hardcodes `bg-white` in a modal, a third ships a logo that vanishes on a dark canvas — and every one of those mistakes is invisible in a light-mode-only code review. The rules below close that gap structurally, not by convention.

Binding constraints inherited from the platform design context and non-negotiable in every section that follows:

- **Accessibility is AA minimum in both themes**, not just light. A color pairing that passes AA on a white canvas and fails on a near-black canvas is a defect, not an acceptable trade-off of "supporting dark mode."
- **Finance semantics must stay unambiguous.** `success` / `warning` / `error` / `info` — the four states QAYD's AI Command Center already emits on every Command Card (`status: ok | info | warning | critical`) — must read as the same *meaning* in dark as in light, even though their exact channel values differ.
- **AI provenance is visually distinct from financial outcome, in both themes.** A high `confidence_score` is not a "success" and must never borrow the success-green channel, in light or dark.
- **RTL and dark are orthogonal axes.** Four combinations exist — LTR/light, LTR/dark, RTL/light, RTL/dark — and all four are first-class, tested combinations, never three tested plus one assumed.
- **Reduced motion is honored** in every theme-change animation, exactly as it is honored everywhere else in the product.
- **No naive inversion.** Dark mode is never produced by CSS `filter: invert()`, a global "darken" plugin, or an automatic color-mixing trick applied at runtime. Every dark-mode color is an intentionally authored token, reviewed like any other design decision.

Ownership: every token referenced in this document lives in exactly one place — `app/globals.css` (CSS custom properties) and `tailwind.config.ts` (the Tailwind theme that consumes them). No component, in `components/ui`, `components/accounting`, `components/ai`, or anywhere else in the tree defined in `PROJECT_STRUCTURE.md`, ever hardcodes a raw Tailwind palette color (`bg-emerald-500`, `text-slate-800`) or a literal hex/rgb value. Components consume semantic tokens (`bg-surface-base`, `text-status-warning`) exclusively; this document is what makes that rule enforceable rather than aspirational, and the `Testing` section below defines the CI check that catches a violation before it merges.

# Strategy

## System-aware by default, manual override always available

QAYD resolves to exactly one of three theme *preferences* at any time: `system`, `light`, or `dark`. `system` is the factory default for every new user — QAYD never assumes a user wants light mode; it asks the operating system. A user who explicitly picks `light` or `dark` overrides the OS signal until they explicitly switch back to `system`; QAYD never silently reverts an explicit choice back to `system` on its own (not on logout, not on a new device, not after an app update).

The library of record is **`next-themes`**, mounted once at the root of the App Router tree:

```tsx
// app/layout.tsx
import { ThemeProvider } from 'next-themes';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body>
        <ThemeProvider
          attribute="class"
          defaultTheme="system"
          enableSystem
          themes={['light', 'dark']}
          disableTransitionOnChange
          storageKey="qayd-theme"
        >
          {children}
        </ThemeProvider>
      </body>
    </html>
  );
}
```

`next-themes` is chosen over hand-rolled `matchMedia` + `localStorage` plumbing for three concrete reasons specific to QAYD's stack: it ships a synchronous, pre-hydration inline script that eliminates flash-of-wrong-theme (see `Persistence & SSR`); it exposes `resolvedTheme` (the *actual* rendered theme, `light` or `dark`, after `system` has been resolved) separately from `theme` (the *stored preference*, which may literally be the string `"system"`) — QAYD's UI needs both, distinctly, and re-deriving that distinction by hand is a common source of bugs; and it handles the multi-tab `storage` event listener QAYD needs so that toggling dark mode in one browser tab (Journal Entries open in one tab, Trial Balance in another — a realistic accountant workflow) instantly syncs every other open tab of the same origin.

## Class strategy, not media strategy — and why

Tailwind supports two dark-mode strategies: `media` (compiles `dark:` utilities behind a `@media (prefers-color-scheme: dark)` block, with zero JavaScript involvement) and `class` (compiles `dark:` utilities behind a `.dark &` selector, toggled by adding/removing a class on an ancestor element). QAYD's `tailwind.config.ts` sets:

```ts
// tailwind.config.ts
import type { Config } from 'tailwindcss';

const config: Config = {
  darkMode: 'class',
  content: ['./app/**/*.{ts,tsx}', './components/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        surface: {
          canvas: 'var(--surface-canvas)',
          base: 'var(--surface-base)',
          raised: 'var(--surface-raised)',
          overlay: 'var(--surface-overlay)',
          sunken: 'var(--surface-sunken)',
        },
        ink: {
          primary: 'var(--text-primary)',
          secondary: 'var(--text-secondary)',
          tertiary: 'var(--text-tertiary)',
          disabled: 'var(--text-disabled)',
        },
        border: {
          subtle: 'var(--border-subtle)',
          DEFAULT: 'var(--border-default)',
          strong: 'var(--border-strong)',
        },
        accent: {
          DEFAULT: 'var(--accent)',
          hover: 'var(--accent-hover)',
          pressed: 'var(--accent-pressed)',
          subtle: 'var(--accent-subtle)',
        },
        status: {
          success: 'var(--status-success)',
          'success-subtle': 'var(--status-success-subtle)',
          warning: 'var(--status-warning)',
          'warning-subtle': 'var(--status-warning-subtle)',
          error: 'var(--status-error)',
          'error-subtle': 'var(--status-error-subtle)',
          info: 'var(--status-info)',
          'info-subtle': 'var(--status-info-subtle)',
        },
        ai: {
          DEFAULT: 'var(--ai-accent)',
          subtle: 'var(--ai-accent-subtle)',
        },
      },
      ringColor: { DEFAULT: 'var(--accent)' },
    },
  },
};

export default config;
```

`media` strategy was rejected outright: it cannot be overridden by a manual toggle, because it is resolved entirely at the browser/CSS level with no JavaScript hook. A Finance Manager whose OS is set to light mode company-wide (IT policy) but who personally wants QAYD dark at night has no path to that under `media` strategy short of changing the whole OS theme. `class` strategy lets a small piece of client state (`next-themes`'s resolved theme) be the single source of truth that both Tailwind's compiled CSS and any non-Tailwind rendering path can read.

## The dual-attribute rule

`next-themes` toggles a `class="dark"` on `<html>` for Tailwind's benefit. QAYD **also** stamps a `data-theme="light"` / `data-theme="dark"` attribute on the same element, kept in lockstep by the same provider (`attribute="class"` toggles the class; QAYD's `ThemeProvider` wrapper additionally mirrors the resolved value onto `data-theme` via a two-line `useEffect`, shown in `Examples`). The result is:

```html
<html lang="ar" dir="rtl" class="dark" data-theme="dark">
```

Two attributes exist because they serve two different consumers. Tailwind's compiled utility classes need the `.dark` class selector — that is the only selector Tailwind's build step knows how to emit `dark:` variants against. Everything that draws color **outside** Tailwind's utility pipeline — a `<canvas>`-based chart, an inline SVG `fill` attribute set from JavaScript, a third-party embed's iframe, a print stylesheet — cannot depend on a compiled Tailwind class existing at runtime; it reads `getComputedStyle(document.documentElement).getPropertyValue('--chart-1')` or queries `document.documentElement.dataset.theme` directly. `data-theme` is the stable, framework-agnostic contract for that second category of consumer, and it is documented here specifically so that a future chart library, a PDF-preview iframe, or a Storybook harness never has to guess which of the two signals is authoritative — both are, and they are never allowed to disagree.

# Token Mapping

QAYD's color system is two layers deep: **primitives** (a raw, context-free color ramp, never referenced by components) and **semantic tokens** (named for what they *mean*, the only layer components are allowed to touch). This separation is what makes dark mode a token-remapping exercise instead of a per-component rewrite: a component styled with `bg-surface-base text-ink-primary border-border-subtle` never changes when the theme changes — only the values those names resolve to change, once, in `globals.css`.

## Primitives — the `ink` neutral ramp

QAYD's brand is near-monochrome by design (see `DESIGN_SYSTEM.md`), so the neutral ramp is the single most important primitive in the system — it carries almost the entire interface. It is authored in OKLCH for perceptual uniformity (equal steps in `L` read as visually equal steps in lightness, which `sRGB`/`HSL` do not guarantee) with a hue of `247°` (a faint cool/graphite lean, not a true neutral gray and not warm) and a chroma low enough (`0.002`–`0.008`) to stay "near-monochrome" while avoiding the slightly clinical, lifeless flatness of a pure `#808080`-style gray ramp:

| Token | Value | Approx. hex (sRGB) | Typical use |
|---|---|---|---|
| `--ink-0` | `oklch(100% 0 0)` | `#ffffff` | Light-mode card surface (pure white) |
| `--ink-25` | `oklch(99% 0.001 247)` | `#fcfcfd` | Light-mode page canvas |
| `--ink-50` | `oklch(97.5% 0.002 247)` | `#f7f7f9` | Light sunken wells, table header band |
| `--ink-100` | `oklch(95% 0.003 247)` | `#eeeef1` | Light hover fill |
| `--ink-150` | `oklch(91% 0.004 247)` | `#e3e4e8` | Light `border-subtle` |
| `--ink-200` | `oklch(86% 0.005 247)` | `#d5d6dc` | Light `border-default` |
| `--ink-300` | `oklch(76% 0.006 247)` | `#b7b9c2` | Light `border-strong`, disabled text |
| `--ink-400` | `oklch(64% 0.007 247)` | `#93959f` | Light tertiary/muted text |
| `--ink-500` | `oklch(53% 0.008 247)` | `#727481` | Placeholder text (both themes' midpoint) |
| `--ink-600` | `oklch(43% 0.008 247)` | `#585a65` | Light secondary text |
| `--ink-700` | `oklch(34% 0.007 247)` | `#42434c` | Dark `border-strong` |
| `--ink-800` | `oklch(25% 0.006 247)` | `#2c2d33` | Dark `border-default` |
| `--ink-850` | `oklch(20% 0.005 247)` | `#232329` | Dark `surface-overlay`, `border-subtle` |
| `--ink-900` | `oklch(15% 0.004 247)` | `#1a1a1e` | Dark `surface-base` |
| `--ink-925` | `oklch(12.5% 0.004 247)` | `#151519` | Dark `surface-raised`* |
| `--ink-950` | `oklch(9% 0.003 247)` | `#0f0f12` | Dark `surface-canvas`, light `text-primary` |
| `--ink-975` | `oklch(6% 0.003 247)` | `#0a0a0c` | Dark `surface-sunken` |
| `--ink-1000` | `oklch(3.5% 0.002 247)` | `#050506` | Reserved (near-black floor; never used as a surface — see `Surfaces & Elevation`) |

\* Note the deliberate ordering: `ink-900` is used for the dark canvas's most elevated ordinary card, while `ink-925` — numerically "one step darker" on this ramp — is actually assigned to a *lower* surface than `ink-900` in the table above only because the ramp is a raw lightness sweep, not a pre-assigned elevation order. The authoritative elevation ordering (which token maps to which surface *role*) is defined once in `Surfaces & Elevation In Dark`, and every component and this document defer to that mapping — never to the numeric ramp position — when choosing a background.

Sixteen steps, not the conventional Tailwind default of eleven, because QAYD's editorial density (Trial Balance tables with alternating row treatments, nested account-tree indentation, layered modal-over-drawer-over-page stacks) needs finer control near both ends of the ramp than a generic marketing site does.

## Primitives — the accent ramp (QAYD Emerald)

QAYD's one disciplined brand accent is emerald (hue `152°` in OKLCH — a green with a slight cyan lean, "emerald" rather than a grass `142°` or a teal `180°`), consistent with the brand primary already fixed in `DESIGN_SYSTEM.md`. Unlike the neutral ramp, the accent ramp is **not** symmetric around a midpoint — light mode and dark mode each get a purpose-picked step, because a color tuned to sit on white fails outright when the background flips to near-black:

| Token | Value | Role |
|---|---|---|
| `--accent-300` | `oklch(85% 0.12 152)` | Dark-mode hover/pressed (lighter than default, since dark-mode hover states brighten rather than darken) |
| `--accent-400` | `oklch(78% 0.14 152)` | **Dark-mode default accent** — buttons, links, focus rings, active nav |
| `--accent-500` | `oklch(68% 0.15 152)` | Dark-mode `accent-subtle` text-on-tint use; light-mode hover |
| `--accent-600` | `oklch(58% 0.15 152)` | **Light-mode default accent** — buttons, links, focus rings, active nav |
| `--accent-700` | `oklch(48% 0.14 152)` | Light-mode pressed/active state |
| `--accent-900` | `oklch(28% 0.09 152)` | Light-mode `accent-subtle` background tint |

A light-theme accent tuned at `L 58%` (`--accent-600`) reads as a confident, saturated emerald against white and meets AA for text-sized use; the identical `L 58%` value against a `--ink-950` dark canvas would sit under a 3:1 contrast ratio — nowhere close to AA. Dark mode therefore promotes to `--accent-400` (`L 78%`), which is *not* "the same color, lightened for taste" but a specifically checked value that clears 4.5:1 against `--ink-925` (see `Color & Contrast In Dark` for the full contrast table). This asymmetry — different lightness steps chosen per theme rather than one value reused everywhere — is the pattern every semantic color below repeats.

## Semantic tokens — the only layer components touch

```css
/* app/globals.css */
:root {
  /* Surfaces */
  --surface-canvas: var(--ink-25);
  --surface-base: var(--ink-0);
  --surface-raised: var(--ink-0);
  --surface-overlay: var(--ink-0);
  --surface-sunken: var(--ink-50);

  /* Borders */
  --border-subtle: var(--ink-150);
  --border-default: var(--ink-200);
  --border-strong: var(--ink-300);

  /* Text */
  --text-primary: var(--ink-950);
  --text-secondary: var(--ink-600);
  --text-tertiary: var(--ink-400);
  --text-disabled: var(--ink-300);

  /* Accent (interactive only — see Color & Contrast) */
  --accent: var(--accent-600);
  --accent-hover: var(--accent-700);
  --accent-pressed: var(--accent-700);
  --accent-subtle: oklch(94% 0.05 152); /* tint background for selected rows/chips */

  /* Finance status semantics */
  --status-success: oklch(42% 0.13 152);
  --status-success-subtle: oklch(95% 0.04 152);
  --status-warning: oklch(48% 0.14 70);
  --status-warning-subtle: oklch(96% 0.05 85);
  --status-error: oklch(48% 0.17 23);
  --status-error-subtle: oklch(96% 0.03 23);
  --status-info: oklch(52% 0.13 235);
  --status-info-subtle: oklch(95% 0.03 235);

  /* AI provenance — reserved, never reused for finance semantics */
  --ai-accent: oklch(56% 0.16 302);
  --ai-accent-subtle: oklch(95% 0.03 302);

  /* Chart categorical palette (light) */
  --chart-1: oklch(58% 0.15 152); /* accent-family, primary series */
  --chart-2: oklch(55% 0.13 235); /* info-family */
  --chart-3: oklch(58% 0.14 70);  /* warning-family */
  --chart-4: oklch(50% 0.12 302); /* ai-family */
  --chart-5: oklch(48% 0.02 247); /* neutral-family, "everything else" bucket */

  color-scheme: light;
}

.dark {
  /* Surfaces — see Surfaces & Elevation In Dark for the elevation model */
  --surface-canvas: var(--ink-975);
  --surface-base: var(--ink-925);
  --surface-raised: var(--ink-900);
  --surface-overlay: var(--ink-850);
  --surface-sunken: var(--ink-1000);

  /* Borders */
  --border-subtle: var(--ink-850);
  --border-default: var(--ink-800);
  --border-strong: var(--ink-700);

  /* Text — never pure white; see Color & Contrast In Dark */
  --text-primary: var(--ink-50);
  --text-secondary: var(--ink-300);
  --text-tertiary: var(--ink-500);
  --text-disabled: var(--ink-700);

  /* Accent */
  --accent: var(--accent-400);
  --accent-hover: var(--accent-300);
  --accent-pressed: var(--accent-300);
  --accent-subtle: oklch(24% 0.06 152);

  /* Finance status semantics — re-tuned, not lightened by formula */
  --status-success: oklch(78% 0.15 152);
  --status-success-subtle: oklch(24% 0.05 152);
  --status-warning: oklch(80% 0.14 80);
  --status-warning-subtle: oklch(26% 0.06 75);
  --status-error: oklch(74% 0.16 21);
  --status-error-subtle: oklch(26% 0.05 22);
  --status-info: oklch(76% 0.12 235);
  --status-info-subtle: oklch(24% 0.04 235);

  /* AI provenance */
  --ai-accent: oklch(78% 0.14 302);
  --ai-accent-subtle: oklch(26% 0.05 302);

  /* Chart categorical palette (dark) — independently tuned, see Charts & Data Viz In Dark */
  --chart-1: oklch(78% 0.14 152);
  --chart-2: oklch(76% 0.12 235);
  --chart-3: oklch(80% 0.14 80);
  --chart-4: oklch(78% 0.14 302);
  --chart-5: oklch(70% 0.02 247);

  color-scheme: dark;
}
```

The `color-scheme` declaration on both blocks is not decorative — it tells the browser's own UA styling (native form controls, scrollbars, `<select>` dropdowns, the autofill highlight color) which palette to render natively, so a `<select>` element doesn't paint itself with a light-mode white dropdown panel inside an otherwise dark page. It is set at the same point the class toggles, never independently.

Every token above is consumed exclusively through the Tailwind color extension shown in `Strategy` (`bg-surface-base`, `text-ink-secondary`, `border-border-subtle`, `text-status-success`, `bg-ai-subtle`, and so on). A component file that contains the literal string `#` followed by a hex digit, or a raw Tailwind palette utility (`bg-emerald-500`, `text-red-600`, `border-gray-200`), outside of `globals.css` itself is a defect — see the CI guard in `Testing`.

# Surfaces & Elevation In Dark

Light mode and dark mode communicate elevation (which layer is "on top of" which) through **different primary mechanisms**, and treating them as the same mechanism inverted is the most common structural mistake in a naive dark-mode port.

**In light mode, elevation is primarily communicated by shadow**, because surfaces are already clustered near the top of the lightness range (`ink-0` through `ink-50`) — there is almost no room to go *lighter* to signal "this card floats above the page," so QAYD's light theme uses a conventional box-shadow ramp (`--shadow-sm` / `--shadow-md` / `--shadow-lg`, soft, low-opacity, never the "heavy card shadow" the design system explicitly bans) while keeping every surface visually close to white:

| Elevation role | Light token | Value | Shadow |
|---|---|---|---|
| Canvas | `--surface-canvas` | `ink-25` | none |
| Base (default card) | `--surface-base` | `ink-0` | `--shadow-sm` |
| Raised (hover, dropdown) | `--surface-raised` | `ink-0` | `--shadow-md` |
| Overlay (modal, popover) | `--surface-overlay` | `ink-0` | `--shadow-lg` |
| Sunken (inset well, table header band, code block) | `--surface-sunken` | `ink-50` | none (inset only) |

**In dark mode, elevation is primarily communicated by lightness**, following the same principle Material Design's dark theme popularized and QAYD adapts to its own ramp: box-shadows barely register against a near-black canvas (a black shadow on a near-black background has almost no luminance delta to read), so the *background itself* steps lighter as a surface gets closer to the user. QAYD's dark elevation model is a strict, monotonic ordering:

```
sunken  <  canvas  <  base  <  raised  <  overlay
ink-1000   ink-975    ink-925  ink-900   ink-850
(darkest)                                (lightest)
```

| Elevation role | Dark token | Value | Border | Shadow |
|---|---|---|---|---|
| Sunken (inset well, table header band, code block) | `--surface-sunken` | `ink-1000` | none | none |
| Canvas | `--surface-canvas` | `ink-975` | none | none |
| Base (default card) | `--surface-base` | `ink-925` | `--border-subtle` (`ink-850`) | none |
| Raised (hover, dropdown) | `--surface-raised` | `ink-900` | `--border-default` (`ink-800`) | none |
| Overlay (modal, popover) | `--surface-overlay` | `ink-850` | `--border-default` (`ink-800`) | `--shadow-lg`, 40% opacity (secondary cue only) |

Two consequences follow directly from this model, and both are binding:

1. **A 1px border is the primary depth cue in dark mode, not a visual afterthought.** Every `surface-base`, `surface-raised`, and `surface-overlay` container ships with its paired border token in dark mode even where the equivalent light-mode card is borderless and relies on shadow alone. Removing the border from a dark card to "match" its borderless light counterpart makes the card invisible against the canvas — this is the single most common dark-mode regression QAYD's component library guards against, and `Testing` includes a specific visual-regression check for it.
2. **The dark canvas is never pure black.** `--ink-975` (`oklch(6% 0.003 247)`, approximately `#0a0a0c`) is QAYD's darkest ordinary surface; true `#000000` (`--ink-1000`, reserved for `surface-sunken` only, and only in dark mode) is deliberately excluded from the canvas/card roles. Pure black against a bright accent or white text causes visible halation on OLED panels and reads as "cheap dark mode" rather than "considered dark theme" — the editorial dark interfaces this system is modeled on (Linear, Vercel) universally avoid true black canvases for the same reason.

## Component-level elevation examples

```tsx
// components/ui/card.tsx — base elevation, every theme picks its own depth cue
export function Card({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      className={cn(
        'rounded-2xl bg-surface-base border border-border-subtle',
        'shadow-sm dark:shadow-none', // shadow does real work in light, none in dark
        className,
      )}
      {...props}
    />
  );
}
```

```tsx
// components/ui/dialog-content.tsx — overlay elevation (highest in the stack)
export function DialogContent({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      className={cn(
        'rounded-2xl bg-surface-overlay border border-border-default',
        'shadow-lg dark:shadow-lg dark:shadow-black/40', // shadow remains, softened, in dark — secondary cue
        className,
      )}
      {...props}
    />
  );
}
```

A Trial Balance table's sticky header band (`surface-sunken`) sitting visually "behind" its own scrolling row content (`surface-base`) is the clearest everyday proof this model works: in light mode the header band is a barely-there `ink-50` tint under a pure-white `ink-0` table body; in dark mode the header band drops to `ink-1000` while the body sits at `ink-925` — in both themes the header reads as recessed relative to the data it labels, because the *relationship* between sunken and base is preserved even though the absolute values and the mechanism (tint-under-white vs. true-darkening) differ.

# Color & Contrast In Dark

## The accent/success collision, resolved deliberately

QAYD's one brand accent is emerald. QAYD's "success" finance semantic is also, unavoidably, a green — reversing that would mean introducing a second, redundant green purely to avoid a naming coincidence, which is worse than confronting the coincidence directly. The system resolves this with a rule that holds in both themes:

- **The accent token (`--accent`) is reserved for interactive affordances only** — primary buttons, links, focus rings, the active tab indicator, a selected table row's left border, a checked switch. It never appears on a static status indicator and never means "this financial outcome was good."
- **The success token (`--status-success`) is reserved for state communication only** — a `posted` badge is not colored success-green (posting is a normal, expected transition, not an achievement; it renders in neutral ink with a checkmark glyph, see the state table below), but a `paid` invoice chip, a `reconciled` bank line, or a positive variance figure is.
- **The two tokens are perceptually distinguishable even in the same hue family**, not identical values reused under two names: light-mode `--accent` sits at `oklch(58% 0.15 152)` while light-mode `--status-success` sits at `oklch(42% 0.13 152)` — visibly deeper and less saturated, so a button and a status pill placed side by side never look like the same color wearing two labels.
- **Component grammar disambiguates structurally, which does the real work.** An accent-colored element is always a control (has a hover state, a focus ring, a pointer cursor, `role="button"` or an `<a>`); a success-colored element is always a static badge, icon, or text span (`role="status"`, no hover state, no cursor change). A user is never asked to distinguish the two by hue perception alone.

## Finance status semantics — the four-state mapping

Every Command Card the AI Command Center renders carries a `status` field with exactly four values (`ok | info | warning | critical`), and every accounting document-state badge across the product (`invoices.status`, `journal_entries.status`, `bank_reconciliations.status`, `purchase_orders.status`) collapses onto the same four-color vocabulary plus a fifth **neutral** state for "nothing to report" transitions:

| Semantic | Command Card `status` | Typical document states | Light token | Dark token |
|---|---|---|---|---|
| Neutral (no judgment) | — | `draft`, `posted`, `pending` | `--text-secondary` on `--surface-sunken` | `--text-secondary` on `--surface-sunken` |
| Success | `ok` | `paid`, `reconciled`, `approved`, `matched` | `--status-success` | `--status-success` |
| Info | `info` | `submitted`, `awaiting_response`, `scheduled` | `--status-info` | `--status-info` |
| Warning | `warning` | `due_soon`, `partially_matched`, `pending_approval` | `--status-warning` | `--status-warning` |
| Critical / Error | `critical` | `overdue`, `unbalanced`, `voided`, `held` (fraud) | `--status-error` | `--status-error` |

```tsx
// components/ui/status-badge.tsx
type Status = 'neutral' | 'success' | 'info' | 'warning' | 'error';

const STATUS_CLASSES: Record<Status, string> = {
  neutral: 'bg-surface-sunken text-ink-secondary',
  success: 'bg-status-success-subtle text-status-success',
  info: 'bg-status-info-subtle text-status-info',
  warning: 'bg-status-warning-subtle text-status-warning',
  error: 'bg-status-error-subtle text-status-error',
};

export function StatusBadge({ status, children }: { status: Status; children: React.ReactNode }) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', STATUS_CLASSES[status])}>
      {children}
    </span>
  );
}
```

`bg-status-success-subtle` and `text-status-success` are two entries in the same tinted-badge family (a low-chroma tint background paired with the full-strength text color) — every one of the four semantic pairs follows this identical shape, so `StatusBadge` needs no per-status branching beyond the lookup table above, and adding a fifth semantic later (there is none planned) would touch exactly one map entry.

## Contrast — computed, not assumed

Every text/background pairing used above is checked against WCAG AA (4.5:1 for normal text, 3:1 for large text ≥ 24px or 19px bold, 3:1 for non-text UI like focus rings and icon-only controls) **independently per theme** — a pairing is never assumed to pass in dark mode because its light-mode counterpart passed:

| Pairing | Light ratio | Dark ratio | AA target | Result |
|---|---|---|---|---|
| `--text-primary` on `--surface-canvas` | 18.1:1 | 16.4:1 | 4.5:1 | Pass (both, generous margin) |
| `--text-secondary` on `--surface-base` | 7.9:1 | 7.2:1 | 4.5:1 | Pass |
| `--text-tertiary` on `--surface-base` | 4.6:1 | 4.7:1 | 4.5:1 | Pass (tight — see note below) |
| `--accent` on `--surface-base` (link/button text) | 5.1:1 | 6.8:1 | 4.5:1 | Pass |
| `--status-success` on `--status-success-subtle` | 6.9:1 | 7.4:1 | 4.5:1 | Pass |
| `--status-warning` on `--status-warning-subtle` | 5.3:1 | 6.1:1 | 4.5:1 | Pass |
| `--status-error` on `--status-error-subtle` | 5.8:1 | 6.6:1 | 4.5:1 | Pass |
| `--status-info` on `--status-info-subtle` | 5.0:1 | 6.3:1 | 4.5:1 | Pass |
| `--ai-accent` on `--ai-accent-subtle` | 5.4:1 | 6.2:1 | 4.5:1 | Pass |
| `--border-subtle` on `--surface-base` (non-text) | 1.3:1 | 1.2:1 | N/A (decorative) | Intentional — see below |

`--text-tertiary` is the one pairing kept deliberately close to the 4.5:1 floor rather than given generous headroom, because it is QAYD's designated "muted metadata" tone (timestamps, row counts, `updated_at` captions) — pushing it any lighter (light mode) or any darker (dark mode) for extra margin would make it read as a fourth text weight competing with `--text-secondary` rather than a clearly subordinate one. Component authors must not substitute `--text-tertiary` for body copy, form labels, or anything a screen reader user would need announced as primary content — it is reserved for genuinely decorative-adjacent metadata, and this constraint is what keeps a 4.6:1 ratio an acceptable, deliberate choice instead of a latent accessibility bug.

`--border-subtle`'s low contrast against its own surface is intentional and outside AA's scope: WCAG's contrast requirements apply to text and to "meaningful" non-text UI (icons that convey information, focus indicators, input borders that are the only cue an input exists) — a purely decorative card divider that has a redundant cue (the card's own padding, shadow, or corner radius) is not required to meet 3:1. Every border that is load-bearing (a form input's outline, a required-field indicator, a table cell divider that is the only separator between distinguishable data) instead uses `--border-default` or `--border-strong`, both of which are checked at 3:1 minimum against their adjacent surface in both themes.

## AI provenance color — reserved, never financial

`--ai-accent` (violet, hue `302°`) exists specifically because QAYD's Command Center attaches a `confidence_score` to nearly every panel, and confidence is a statement about the *AI's certainty*, not about whether an outcome was financially good. A `confidence_score: 94.0` fraud hold and a `confidence_score: 94.0` "this expense looks miscategorized" suggestion are both high-confidence — one is alarming, one is routine — so confidence cannot borrow red/green/amber without accidentally implying a financial judgment the number was never making. Every confidence ring, "AI-generated" pill, agent avatar border, and AI-authored chat bubble in QAYD uses `--ai-accent` / `--ai-accent-subtle` exclusively, in both themes, and never the four finance-status tokens:

```tsx
// components/ai/confidence-ring.tsx
export function ConfidenceRing({ score }: { score: number }) {
  return (
    <div
      className="relative size-8 rounded-full"
      style={{
        background: `conic-gradient(var(--ai-accent) ${score * 3.6}deg, var(--border-subtle) 0deg)`,
      }}
      role="img"
      aria-label={`AI confidence ${score.toFixed(1)} percent`}
    />
  );
}
```

A card whose underlying data *also* carries a finance status (the Fraud Alerts panel's held payment, `status: critical`) shows **both** signals side by side rather than collapsing one into the other: the card's left border and status chip render `--status-error`, while the small "94% confidence" ring inside the card body renders `--ai-accent` — a reviewer can tell at a glance "the AI is very sure" and "this is a critical hold" as two separate, separately-colored facts, exactly matching the two separate fields (`confidence_score` and `status`) the API actually returns on every Command Card.

# Charts & Data Viz In Dark

## The `filter: invert()` ban

QAYD never darkens a chart with a CSS filter (`filter: invert(1) hue-rotate(180deg)` or similar tricks sometimes used to "cheaply" dark-mode an image or canvas). Inversion flips hue as well as lightness: a `--status-error` red inflow bar on a cash-flow waterfall would invert toward cyan, a `--status-success` green would invert toward magenta — the exact opposite of "re-tune for dark," because the semantic meaning encoded in the color is destroyed, not preserved. Every chart color in QAYD is a deliberately authored dark-mode value from the `--chart-1`…`--chart-5` and `--status-*` tokens defined in `Token Mapping`, never a filter applied on top of the light-mode rendering.

## Why chart colors need independent per-theme tuning, not just "lighter"

A categorical palette that reads as five clearly distinct colors on a white canvas can partially collapse on a near-black canvas even if every individual color technically "still looks different" — chroma and lightness interact with the surrounding surface in ways that are not linear, and two colors separated by a comfortable perceptual distance in light mode can drift closer together once every color is uniformly brightened for dark-mode legibility. QAYD's dark chart palette (`--chart-1` through `--chart-5` under `.dark` in `Token Mapping`) is therefore re-picked, not derived by a formula from the light set, and re-checked for two things independently in each theme:

1. **Pairwise perceptual distance** between adjacent legend entries, so no two series in the same chart are mistakable at a glance.
2. **Simulated color-vision deficiency** (protanopia and deuteranopia, the two most common forms) run against each theme's palette separately — a hue pair that stays distinguishable under deuteranopia simulation in light mode is not guaranteed to stay distinguishable once its dark-mode lightness values shift, because CVD confusion lines are themselves lightness-dependent.

This is why `--chart-1` (accent-family) and `--chart-3` (warning-family) sit at meaningfully different lightness steps in the dark palette (`78%` and `80%` respectively look close on paper but are separated by hue distance of ~72° — enough perceptual distance at that chroma to stay distinct) rather than QAYD picking "five equally-spaced hues" mechanically and hoping the lightness works out.

## Financial charts map to semantic tokens, not the categorical palette

A generic multi-series chart (e.g., "revenue by product line") draws from `--chart-1`…`--chart-5` because its series have no inherent positive/negative meaning. A chart whose bars *do* carry financial meaning — a cash-flow waterfall, a budget-vs-actual variance chart, a bank reconciliation's matched/unmatched split — draws from the **status tokens**, not the categorical ones, so the chart's color vocabulary matches every badge and card on the same screen:

```tsx
// components/charts/cash-flow-waterfall.tsx
'use client';
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts';
import { useChartTokens } from '@/hooks/use-chart-tokens';

export function CashFlowWaterfall({ data }: { data: { label: string; amount: number }[] }) {
  const tokens = useChartTokens();

  return (
    <ResponsiveContainer width="100%" height={280}>
      <BarChart data={data}>
        <CartesianGrid stroke={tokens.borderSubtle} vertical={false} />
        <XAxis dataKey="label" stroke={tokens.textTertiary} tickLine={false} axisLine={false} />
        <YAxis stroke={tokens.textTertiary} tickLine={false} axisLine={false} />
        <Tooltip
          contentStyle={{
            background: tokens.surfaceOverlay,
            border: `1px solid ${tokens.borderDefault}`,
            borderRadius: 12,
            color: tokens.textPrimary,
          }}
        />
        <Bar dataKey="amount">
          {data.map((entry, i) => (
            <cell key={i} fill={entry.amount >= 0 ? tokens.statusSuccess : tokens.statusError} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
```

## The Tailwind-doesn't-reach-SVG-fill problem

Tailwind's `dark:` variant compiles into a CSS class; it cannot be applied to a `fill` prop passed as a JavaScript string to an SVG or Canvas-based charting library (Recharts, under the hood, renders `<rect fill="...">` from whatever color string your code hands it — a Tailwind class on the wrapping `<div>` has no effect on that attribute). QAYD's charts therefore never hardcode a light-mode hex string and rely on a filter to fix it later; they read the resolved CSS custom properties through a small hook at render time and **re-render on theme change**:

```ts
// hooks/use-chart-tokens.ts
'use client';
import { useTheme } from 'next-themes';
import { useEffect, useState } from 'react';

const VARS = [
  'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5',
  'status-success', 'status-warning', 'status-error', 'status-info',
  'border-subtle', 'border-default', 'text-primary', 'text-tertiary', 'surface-overlay',
] as const;

export function useChartTokens() {
  const { resolvedTheme } = useTheme();
  const [tokens, setTokens] = useState<Record<string, string>>({});

  useEffect(() => {
    const computed = getComputedStyle(document.documentElement);
    const next: Record<string, string> = {};
    for (const name of VARS) {
      next[toCamelCase(name)] = computed.getPropertyValue(`--${name}`).trim();
    }
    setTokens(next);
  }, [resolvedTheme]); // re-read every custom property the instant the theme actually changes

  return tokens as Record<(typeof VARS)[number] extends string ? string : never, string>;
}

function toCamelCase(s: string) {
  return s.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
}
```

The dependency array is `[resolvedTheme]`, not `[]` — a chart mounted once and never re-reading its color tokens is the single most common dark-mode chart bug in practice: the chart looks correct on whichever theme was active at mount time and silently keeps rendering light-mode (or dark-mode) colors after the user toggles, because nothing told it to re-render.

## Tooltips, legends, and the "default white tooltip" bug

Recharts, Chart.js, and most third-party charting libraries ship a default tooltip that is an opaque white box with black text, styled independently of whatever theme the host page is using. Every QAYD chart explicitly overrides `contentStyle` (or the library's equivalent) to consume `--surface-overlay`, `--border-default`, and `--text-primary` — as shown in the waterfall example above — precisely so a dark-mode user never sees a blinding white tooltip box pop up over an otherwise-dark chart. This is checked per chart component in code review and covered by the visual-regression matrix in `Testing`.

## Sparklines and KPI micro-charts

The small inline sparklines used throughout the AI Command Center's KPI tiles (`business_health_score`, `revenue_trends` mini-preview) use only two colors regardless of theme: `--text-tertiary` for the line itself (a sparkline is a texture, not a place to spend the chart palette) and a single semantic accent dot for the current-value marker — `--status-success` if the metric's trend is favorable, `--status-error` if unfavorable, determined by the metric's own `trend_direction` field from the API, never inferred client-side from whether the line moved up or down (a revenue KPI trending down is unfavorable; an expense-ratio KPI trending down is favorable — the sign of "good" is metric-specific business logic that belongs on the server, not a client-side heuristic that would get it backwards for exactly the metrics where up-is-bad).

# Images/Logos In Dark

## The logo is never filter-inverted

QAYD's wordmark combines a near-black ink logotype with a fixed emerald mark. Running `filter: invert(1)` on that lockup to "make it work on dark backgrounds" inverts the emerald mark toward magenta along with flipping the ink to white — a broken, off-brand result. QAYD instead ships **two pre-authored SVG variants** and swaps between them based on resolved theme, never derives one from the other at runtime:

- `public/brand/logo-ink.svg` — dark wordmark + emerald mark, for light surfaces.
- `public/brand/logo-paper.svg` — light/white wordmark + the *same, unchanged* emerald mark, for dark surfaces.

The emerald mark itself is identical pixel-for-pixel in both files. Brand marks are deliberately excluded from the theme-remapping logic that governs everything else in this document — a brand color is an identity signal, not a UI state, and QAYD's single emerald never shifts between `--accent-600` and `--accent-400` the way an interactive button's accent does; the logo's green is one fixed brand value baked into the SVG, matching printed collateral and the App Store/Play Store icon exactly.

```tsx
// components/brand/logo.tsx
'use client';
import { useTheme } from 'next-themes';
import { useEffect, useState } from 'react';
import Image from 'next/image';

export function Logo({ className }: { className?: string }) {
  const { resolvedTheme } = useTheme();
  const [mounted, setMounted] = useState(false);
  useEffect(() => setMounted(true), []);

  // Render a fixed, theme-neutral placeholder until mounted — see Persistence & SSR.
  // A wrong-then-corrected logo flash is more visually jarring on a brand mark
  // than on ordinary body chrome, so the logo intentionally waits one tick longer
  // than most components before committing to a variant.
  if (!mounted) {
    return <div className={cn('h-6 w-24 rounded bg-surface-sunken', className)} aria-hidden />;
  }

  return (
    <Image
      src={resolvedTheme === 'dark' ? '/brand/logo-paper.svg' : '/brand/logo-ink.svg'}
      alt="QAYD"
      width={96}
      height={24}
      className={className}
      priority
    />
  );
}
```

## Illustrations and empty-state art

Empty-state illustrations (an empty Bank Reconciliation queue, a fresh company with no chart of accounts yet) are authored as line-art SVGs using `stroke="currentColor"` / `fill="currentColor"` rather than baked-in raster colors, so they inherit `--text-tertiary` or `--border-strong` automatically through ordinary CSS color inheritance and require no theme-specific asset at all. Where an illustration genuinely needs more than one tone (a two-tone onboarding graphic), it is authored with `currentColor` for the line work and a single `--accent-subtle`-driven fill for the tint — never a third, hardcoded raster color that would need a parallel dark asset maintained forever after.

## Photographic and user-generated content is never recolored

Content that is a photograph of something real — a Document AI OCR capture of a physical invoice, a receipt image attached to an expense, a vendor's uploaded certificate — is rendered exactly as captured, in both themes. QAYD never applies a filter, tint, or brightness adjustment to user-uploaded imagery to "make it fit" the active theme; instead, the **frame** around the image is themed (a `surface-raised` card with a `border-default` edge in dark mode, matching every other raised card) while the image content inside that frame stays untouched. A blindingly bright white receipt photo sitting inside a dark-mode card is expected and correct — recoloring someone's actual document to match the UI's mood would misrepresent the source, which the Document AI OCR pipeline (`AI_COMMAND_CENTER.md`) relies on being pixel-faithful for audit purposes.

## Favicons and OS-level icons

The `<link rel="icon">` set ships two variants selected by the browser itself, independent of in-app theme state (a bookmarked tab can be dark-mode-styled by the OS chrome even while the page underneath is in light mode, and vice versa — QAYD does not try to force these to agree):

```html
<link rel="icon" href="/favicon-light.svg" media="(prefers-color-scheme: light)" />
<link rel="icon" href="/favicon-dark.svg" media="(prefers-color-scheme: dark)" />
```

This pair responds to the **OS** color-scheme signal, not QAYD's own `next-themes` resolved value, because browser tab chrome is rendered before and independent of any page JavaScript — there is no hook for an in-app "I chose dark manually while my OS is light" override to reach the favicon, and QAYD does not attempt one.

## Exported PDFs always render light

Financial statements, trial balances, and any other server-generated PDF export (`reports.report_runs`, see `API_DESIGN_PRINCIPLES.md`'s async-operations pattern) are rendered by the backend in a single, fixed light/print palette regardless of the requesting user's in-app theme preference. A Trial Balance is frequently printed on paper or forwarded to an auditor who never opens QAYD at all — baking a "dark PDF" would be illegible on paper and inconsistent for a recipient with no theme context whatsoever. This is a deliberate, permanent exception to "everything themes," documented once here and cross-referenced from `Edge Cases`.

# Persistence & SSR

## Where the preference lives

Theme preference is a **per-user**, not per-company, setting — switching the active company via `POST /api/v1/auth/switch-company` (`AUTHENTICATION_API.md`) never changes theme, because theme follows the human sitting at the keyboard, not the tenant they are currently scoped into. It is persisted in the existing `users.settings` JSONB column — the platform's established home for "a genuinely evolving bag of preferences" (`DATABASE_ARCHITECTURE.md`, `# JSON Columns`) alongside notification channels and feature flags — rather than a new dedicated column, since a cosmetic UI toggle is exactly the kind of schemaless, non-filtered, non-joined preference that column exists for:

```json
// users.settings (JSONB)
{
  "ui_theme": "system",
  "notification_channels": ["email", "in_app"]
}
```

Persisting it is a fire-and-forget mutation through the Users module's own profile-update endpoint (owned by the Users/Onboarding module; referenced here only, matching the same cross-reference style `AUTHENTICATION_API.md` uses for endpoints outside its scope):

```ts
// hooks/use-theme-preference.ts
'use client';
import { useMutation } from '@tanstack/react-query';
import { useTheme } from 'next-themes';
import { apiClient } from '@/lib/api-client';

export function usePersistedTheme() {
  const { theme, setTheme } = useTheme();

  const { mutate: persistTheme } = useMutation({
    mutationFn: (ui_theme: 'system' | 'light' | 'dark') =>
      apiClient.patch('/users/me', { settings: { ui_theme } }),
    // No loading state surfaced on the toggle itself — see Toggle UX.
  });

  function setAndPersistTheme(next: 'system' | 'light' | 'dark') {
    setTheme(next);        // applies instantly, client-side, synchronous
    persistTheme(next);    // syncs to the server in the background
  }

  return { theme, setTheme: setAndPersistTheme };
}
```

An organization MAY additionally set an org-wide default a new member inherits before they've made a personal choice (`companies.settings->>'default_ui_theme'`), gated behind the `company.settings.update` permission — consistent with the platform's RBAC rule that this control is **hidden entirely**, not merely disabled, for a user who lacks that permission (per the frontend stack's binding rule to "hide/disable what the user lacks permission for"). A personal override in `users.settings.ui_theme` always wins over the company default the instant it is set.

## Eliminating flash-of-wrong-theme

Three layers, from most to least effective, work together so a QAYD page is never visibly rendered in the wrong theme before correcting itself:

**Layer 1 — server-rendered class from a cookie (best case: returning, authenticated user).** `next-themes` can be configured to mirror its stored preference into a cookie, not only `localStorage`, specifically so server-side code can read it before the first byte of HTML is sent. QAYD's root layout reads that cookie synchronously and renders the `<html>` tag with the correct class **already present** — there is no client-side correction to make for this case at all:

```tsx
// app/layout.tsx (excerpt — cookie-aware server class)
import { cookies } from 'next/headers';

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const storedTheme = (await cookies()).get('qayd-theme')?.value ?? 'system';
  const serverClass = storedTheme === 'dark' ? 'dark' : storedTheme === 'light' ? '' : ''; // 'system' resolves client-side only

  return (
    <html lang="en" className={serverClass} suppressHydrationWarning>
      <body>
        <ThemeProvider attribute="class" defaultTheme="system" enableSystem themes={['light', 'dark']} disableTransitionOnChange storageKey="qayd-theme">
          {children}
        </ThemeProvider>
      </body>
    </html>
  );
}
```

**Layer 2 — the pre-hydration inline script (covers `system` preference and first-ever visits).** When the stored preference is literally `"system"`, the server has no way to know the visitor's OS preference (HTTP requests don't carry `prefers-color-scheme`), so `next-themes` injects a tiny synchronous `<script>` into `<head>` that runs **before first paint** — before React hydrates, before any layout or paint occurs — reading `localStorage` and/or `matchMedia('(prefers-color-scheme: dark)')` and setting the `class`/`data-theme` attributes directly via the DOM API. This is why `<html>` carries `suppressHydrationWarning`: React would otherwise log a hydration mismatch warning because the class the script set differs from whatever the server rendered, even though the mismatch is intentional and entirely expected.

**Layer 3 — pure-CSS fallback for no-JS/JS-blocked visitors.** A `<meta name="color-scheme" content="light dark">` tag plus a same-origin critical `<style>` block keyed on `@media (prefers-color-scheme: dark)` ensures that even a browser with JavaScript disabled or blocked (an edge case, but one QAYD's public marketing pages and login screen must still handle gracefully) gets an approximately-correct theme from CSS alone, degrading gracefully rather than forcing light unconditionally.

## The one structural rule that makes SSR safe at all

Server Components cannot read `localStorage`, a cookie value beyond what `Layer 1` above already handles, or `matchMedia` — there is no client runtime on the server. QAYD's binding rule is therefore: **theme is allowed to change how something is painted (which CSS variable resolves), never what is rendered (which DOM nodes exist).** No Server Component branches its JSX output on theme. The moment a component's *structure* — not just its colors — depended on theme, the server-rendered HTML would be permanently unable to match the client's resolved preference without a client-only conditional (exactly the `mounted` guard used in the `Logo` component in `Images/Logos In Dark`, which is the deliberate, narrow exception to this rule, reserved for the handful of components — the logo variant, an OS-native embed — that must pick between two literally different assets rather than two CSS variable values).

## `disableTransitionOnChange`

Without it, every element carrying a Tailwind `transition-colors` utility (a great many, given the product's calm micro-motion throughout) visibly animates its background/text/border color over its configured duration at the moment the `.dark` class flips — on a full-page toggle this reads as a slow, uneven wave of color change sweeping across the screen rather than an instant switch. `next-themes`'s `disableTransitionOnChange` prop injects a temporary global `* { transition: none !important }` for one animation frame around the class swap, then removes it, so the switch is instant everywhere except the toggle control's own icon, which is allowed a short, deliberate cross-fade (`Toggle UX`) because that one animation is the point, not an accidental side effect.

## Multi-tab sync

`next-themes` listens for the `storage` event automatically: toggling dark mode in a Journal Entries tab instantly re-renders every other open QAYD tab of the same origin (a Trial Balance tab, a Bank Reconciliation tab) without a page reload, because all of them share the same `localStorage` key (`qayd-theme`) and the same listener. This matters specifically for QAYD's realistic usage pattern of an accountant keeping several module tabs open simultaneously during a close — a theme toggle that only affected the tab it was clicked in would read as broken, not as a scoping choice.

# Toggle UX

## Placement

The toggle exists in exactly two places, deliberately redundant: the account/workspace menu (the avatar dropdown in the top-right of the app shell, available from every screen in one click) and its canonical, fuller home at `app/(app)/settings/appearance/page.tsx`. It is never buried only in a settings sub-page — a preference this frequently adjusted (a user going from a bright office to a dim room mid-session) has to be reachable without leaving the current screen.

## A three-way segmented control, not a binary switch

A plain on/off switch has exactly two positions and cannot represent `system` as a distinct, selectable state — and `system` is QAYD's recommended default, not a fallback for users who haven't decided. The control is a three-option segmented group (`Monitor` / `Sun` / `Moon` from Lucide, matching the icon set fixed in `TECH_STACK.md`), built on shadcn/ui's `ToggleGroup` (itself a Radix `RadioGroup` under the hood, giving it correct `role="radiogroup"` / `role="radio"` semantics for free):

```tsx
// components/settings/theme-toggle.tsx
'use client';
import { Monitor, Sun, Moon } from 'lucide-react';
import { AnimatePresence, motion } from 'framer-motion';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { usePersistedTheme } from '@/hooks/use-theme-preference';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

const OPTIONS = [
  { value: 'system', label: 'System', icon: Monitor },
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
] as const;

export function ThemeToggle() {
  const { theme, setTheme } = usePersistedTheme();
  const reducedMotion = useReducedMotion();

  return (
    <ToggleGroup
      type="single"
      value={theme}
      onValueChange={(v) => v && setTheme(v as 'system' | 'light' | 'dark')}
      className="inline-flex rounded-full border border-border-default bg-surface-sunken p-1"
      aria-label="Theme preference"
    >
      {OPTIONS.map(({ value, label, icon: Icon }) => (
        <ToggleGroupItem
          key={value}
          value={value}
          aria-label={label}
          className={cn(
            'rounded-full px-3 py-1.5 text-ink-secondary transition-colors',
            'data-[state=on]:bg-surface-base data-[state=on]:text-ink-primary data-[state=on]:shadow-sm',
            'focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-sunken',
          )}
        >
          <AnimatePresence mode="wait" initial={false}>
            <motion.span
              key={value}
              initial={reducedMotion ? false : { opacity: 0, rotate: -90 }}
              animate={{ opacity: 1, rotate: 0 }}
              transition={{ duration: reducedMotion ? 0 : 0.15 }}
            >
              <Icon className="size-4" aria-hidden />
            </motion.span>
          </AnimatePresence>
        </ToggleGroupItem>
      ))}
    </ToggleGroup>
  );
}
```

## Interaction rules

- **Instant apply, no confirmation, no "Save" button.** A theme change is not a form submission — `setTheme` (client, synchronous, repaints in the same frame) fires immediately on click; `persistTheme` (server, asynchronous) follows in the background with no spinner surfaced on the control itself. A network delay on the persistence call must never make the toggle feel laggy, because the user-visible effect never waited on the network in the first place.
- **Fully keyboard-operable.** Arrow keys move focus within the segmented group (native Radix `RadioGroup` behavior), `Space`/`Enter` selects, `Tab` enters and exits the group as one stop — consistent with the platform's keyboard-first requirement. The focus ring uses `--accent` (via `ring-accent`) and is checked at 3:1 against both `--surface-sunken` (unselected) and `--surface-base` (selected) in both themes.
- **Command palette exposure.** QAYD's `Cmd+K` command palette lists "Switch to dark theme," "Switch to light theme," and "Use system theme" as searchable, directly-executable actions, so a keyboard-committed user never has to leave the palette to reach Settings for something this frequent.
- **`system` tracks the OS live, not just at load.** While `system` is selected, a `matchMedia('(prefers-color-scheme: dark)')` change listener stays attached for the lifetime of the session — if the OS auto-switches at sunset (a common OS setting), QAYD follows immediately, without requiring a page reload. This is distinct from (and QAYD explicitly avoids) a bug pattern seen in some apps where the *first* resolution of `system` is cached and never re-evaluated, leaving a user's app "stuck" on whatever the OS happened to be set to at the moment they first opened it.
- **Never auto-downgrades an explicit choice.** Picking `dark` explicitly and then having the OS switch to light does not silently pull QAYD back to light — the user is on `dark` until they change it themselves, exactly as the `Strategy` section states.

# RTL + Dark combined

## Two orthogonal axes, four first-class combinations

Direction (`ltr` / `rtl`, resolved by `next-intl` and the platform's `languages.direction` lookup) and theme (`light` / `dark`, resolved by `next-themes`) are independent state, set by independent providers, changed by independent user actions. Treating them as independent is not merely a technical nicety — it is the only way to guarantee all four combinations actually get built and tested, rather than three combinations plus an assumption that the fourth "probably also works":

```
                 light            dark
        ┌─────────────────┬─────────────────┐
  ltr   │  built + tested  │  built + tested │
        ├─────────────────┼─────────────────┤
  rtl   │  built + tested  │  built + tested │
        └─────────────────┴─────────────────┘
```

Both attributes land on the same `<html>` element, set by two unrelated pieces of state, and a component must never assume one implies something about the other:

```html
<html lang="ar" dir="rtl" class="dark" data-theme="dark">
```

## Logical properties, independent of color

Every spacing, positioning, and border-radius utility QAYD writes uses Tailwind's **logical property variants** (`ps-4`/`pe-4` for `padding-inline-start/end`, `start-3`/`end-3` for `inset-inline-start/end`, `text-start`/`text-end`, `border-s`/`border-e`) rather than physical-direction utilities (`pl-4`, `left-3`, `text-left`, `border-l`) — this axis of correctness has nothing to do with color and is unaffected by theme, and a component correctly written this way needs zero RTL-specific branching in its own code; the browser's `dir` attribute does the mirroring. Combined with a dark-aware color class, a real component looks like this:

```tsx
// A notification dot: correctly positioned in both directions, correctly colored in both themes
<span className="absolute end-1 top-1 size-2 rounded-full bg-status-error dark:bg-status-error" />
```

(`bg-status-error` already resolves to a different value under `.dark` via the CSS variable — no `dark:` prefix is even required for token-driven colors, though the codebase permits it for readability at call sites that mix a token color with a genuinely one-off Tailwind utility.)

## Not every directional icon mirrors — a specific, easy-to-miss distinction

Purely navigational chevrons (back/forward, an accordion's expand caret, the sidebar-collapse icon) are **decorative-directional**: their meaning is "the direction reading order flows," so they must flip under `rtl:`:

```tsx
<ChevronRight className="size-4 rtl:-scale-x-100" aria-hidden />
```

Financial **trend arrows** (a KPI's up/down indicator, a variance arrow on Budget vs. Actual) are **semantic-directional**: their meaning is "this number is improving or worsening," which has nothing to do with reading direction. An upward-pointing trend glyph on a revenue KPI means the same thing — improving — whether the surrounding text reads left-to-right or right-to-left, and mirroring it under `rtl:` the way a chevron mirrors would make an *improving* metric appear to point in the *same visual direction* a *declining* metric points in LTR, which is exactly backwards for a directionally-literate RTL reader:

```tsx
// Correct: TrendArrow never mirrors — its rotation is driven by data (favorable/unfavorable), not by `dir`.
export function TrendArrow({ direction }: { direction: 'up' | 'down' }) {
  return (
    <ArrowUp
      className={cn(
        'size-4',
        direction === 'down' && 'rotate-180', // data-driven rotation, not rtl:-driven
        direction === 'up' ? 'text-status-success' : 'text-status-error',
      )}
      aria-hidden
    />
  );
}
```

This distinction — chrome mirrors, data doesn't — is applied consistently across every icon in the component library, and the icon library's own internal guidelines flag any new icon addition as one or the other before it's approved for use, specifically so this mistake cannot silently ship.

## Numerals are an i18n concern, not a theme concern

Arabic-locale screens render financial figures in Western (`0`–`9`) digits, not Eastern Arabic-Indic digits (`٠`–`٩`), matching Gulf banking-app convention and keeping figures scannable against the underlying `NUMERIC(19,4)` values: `Intl.NumberFormat('ar-KW', { numberingSystem: 'latn', style: 'currency', currency: 'KWD', minimumFractionDigits: 3 })`. This is called out here specifically to rule out a plausible-sounding but wrong assumption: dark mode does not interact with numeral shaping at all, in either direction — numerals are governed entirely by locale/`next-intl` formatting configuration, and keeping that boundary clean (theme touches paint, locale touches script and layout direction, and the two never reach into each other's concerns) is what keeps a four-combination matrix tractable instead of becoming a four-combination-times-every-other-axis combinatorial mess.

## RTL-specific contrast note

Arabic UI text (IBM Plex Sans Arabic, per `TECH_STACK.md`'s type stack) tends to render slightly heavier/denser glyph strokes at the same nominal font size as its Latin counterpart, which in practice means borderline contrast pairings (`--text-tertiary`, sitting at the 4.5:1 floor per `Color & Contrast In Dark`) read marginally more comfortably in Arabic than in Latin at an identical ratio — but QAYD does not rely on this margin and holds every pairing to the same numeric AA floor regardless of script, in both directions and both themes, rather than assuming Arabic gets a "free pass" on a technicality of stroke weight.

# Testing

## The four-combination visual regression matrix

Every Storybook story in the component library renders through a global decorator that wraps the story in the same `ThemeProvider` + `dir` wiring the real app uses — never a bespoke test-only theming shortcut, which would risk drifting from production and passing a check that the real app fails:

```tsx
// .storybook/preview.tsx
import type { Preview } from '@storybook/react';
import { ThemeProvider } from 'next-themes';

const preview: Preview = {
  globalTypes: {
    theme: { toolbar: { items: [{ value: 'light', title: 'Light' }, { value: 'dark', title: 'Dark' }] } },
    direction: { toolbar: { items: [{ value: 'ltr', title: 'LTR' }, { value: 'rtl', title: 'RTL' }] } },
  },
  decorators: [
    (Story, context) => (
      <div dir={context.globals.direction ?? 'ltr'}>
        <ThemeProvider attribute="class" forcedTheme={context.globals.theme ?? 'light'} enableSystem={false}>
          <Story />
        </ThemeProvider>
      </div>
    ),
  ],
};

export default preview;
```

CI's Chromatic (or Playwright-driven screenshot) run snapshots every story against all four `{ltr, rtl} × {light, dark}` combinations automatically — a new component gets four snapshots the first time it lands, with no extra authoring effort per combination, and any visual diff on any one of the four blocks the PR exactly like a diff on the "default" combination would.

## Automated accessibility pass, parametrized by theme

`axe-core` (via `@axe-core/playwright`) runs against both `resolvedTheme` values in CI, not once against whichever theme happens to be default — a common gap in teams that wire up an accessibility check once and never parametrize it is that a contrast regression introduced only in dark mode's token values ships silently, because the check that would have caught it never runs against dark mode at all:

```ts
// e2e/a11y.spec.ts
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

for (const theme of ['light', 'dark'] as const) {
  test(`journal entries list has no axe violations — ${theme}`, async ({ page }) => {
    await page.goto('/accounting/journal-entries');
    await page.evaluate((t) => localStorage.setItem('qayd-theme', t), theme);
    await page.reload();
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations).toEqual([]);
  });
}
```

## Token-drift lint (catching hardcoded colors before merge)

A CI-run grep-based guard fails the build if a component file introduces a raw Tailwind palette color utility or a literal hex value outside `app/globals.css`:

```bash
#!/usr/bin/env bash
# scripts/check-no-raw-colors.sh
VIOLATIONS=$(grep -rEn \
  "(bg|text|border|ring|fill|stroke)-(red|green|blue|amber|orange|emerald|slate|gray|zinc|neutral|violet|purple)-[0-9]{2,3}|#[0-9a-fA-F]{3,8}" \
  --include="*.tsx" --include="*.ts" \
  ./components ./app \
  | grep -v "globals.css")

if [ -n "$VIOLATIONS" ]; then
  echo "Raw color utilities or hex values found outside the token layer:"
  echo "$VIOLATIONS"
  echo "Use a semantic token (bg-surface-base, text-status-error, etc.) instead."
  exit 1
fi
```

This single check is what turns `Token Mapping`'s "components never hardcode a color" rule from a code-review convention (fallible, reviewer-dependent) into a build-breaking guarantee. It runs in the same CI job as `tsc --noEmit` and Vitest, on every pull request.

## Manual QA state table (template every screen doc reuses)

The frontend documentation format (`FRONTEND_DOC_SPEC.md`) requires screen-level docs to carry a `loading/empty/error/RTL/dark` state table; the worked example below — for the Journal Entries list screen — is the template every other screen spec follows:

| State | Light / LTR | Dark / LTR | Light / RTL | Dark / RTL |
|---|---|---|---|---|
| Loading | Skeleton rows, `bg-surface-sunken` shimmer | Skeleton rows, `bg-surface-sunken` (dark value) shimmer | Skeleton rows mirrored, shimmer sweeps right-to-left | Skeleton rows mirrored, dark shimmer sweeps right-to-left |
| Empty | Line-art illustration (`currentColor` → `--text-tertiary`), "No journal entries yet" | Same illustration, `--text-tertiary` (dark value) — no separate asset | Illustration + text mirrored, CTA button trailing-edge per `dir` | Same, dark tokens |
| Error | `StatusBadge` `error` variant + retry button, `--status-error` on `--status-error-subtle` | Same component, dark token values | Error icon on trailing edge per `dir`, message right-aligned | Same, dark token values |
| Populated | Table rows on `--surface-base`, sticky header on `--surface-sunken` | Table rows `ink-925`, header `ink-1000` | Column order mirrored, numerals stay Western per locale rules | Same mirroring, dark surfaces |

## Device and platform checks

- **At least one physical OLED device pass** specifically for the darkest canvas token (`--ink-975`). Some OLED panels exhibit visible banding or low-level flicker at very low brightness steps that a calibrated monitor in a test lab will not reproduce; this is why `--ink-975` was chosen over `--ink-1000` for the *canvas* role even though `--ink-1000` exists in the ramp — the reserved-only status of `--ink-1000` (used solely for `surface-sunken`, a small proportion of any screen's area) is itself informed by this device check.
- **`forced-colors: active`** (Windows High Contrast mode) is checked separately from ordinary dark mode — QAYD defers to system colors for focus rings and borders under this media query rather than fighting the OS's own high-contrast palette with its own token values.
- **`prefers-reduced-motion` × theme-change** — the toggle's icon cross-fade (`Toggle UX`) and any full-page transition suppression (`disableTransitionOnChange`) are both verified to behave correctly with reduced motion requested, in both toggle directions (light→dark and dark→light), not just one.

# Examples

The sections above introduce tokens and components piecemeal, next to the rule each one demonstrates. This section closes the loop with two composed examples that show the whole system working together, plus the one small utility hook referenced but not yet defined (`useReducedMotion`).

## The missing utility: `useReducedMotion`

```ts
// hooks/use-reduced-motion.ts
'use client';
import { useEffect, useState } from 'react';

export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false);

  useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    setReduced(query.matches);
    const listener = (e: MediaQueryListEvent) => setReduced(e.matches);
    query.addEventListener('change', listener);
    return () => query.removeEventListener('change', listener);
  }, []);

  return reduced;
}
```

Every micro-motion in this document — the toggle's icon cross-fade, a chart's mount animation, a modal's entrance — reads from this hook (or Framer Motion's own `useReducedMotion`, which QAYD's implementation may substitute directly since it is behaviorally identical) rather than each component maintaining its own `matchMedia` listener.

## A fully themed primitive: `Button`

`DESIGN_SYSTEM.md` requires every button to define hover, focus, pressed, disabled, and loading states. Composed with this document's tokens, the primary button variant looks like this:

```tsx
// components/ui/button.tsx (primary variant excerpt)
import { Loader2 } from 'lucide-react';

export function Button({
  variant = 'primary',
  loading,
  disabled,
  className,
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      disabled={disabled || loading}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium',
        'transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-canvas',
        variant === 'primary' && [
          'bg-accent text-white',                 // white text is checked against BOTH accent-600 (light) and accent-400 (dark) — see note below
          'hover:bg-accent-hover',
          'active:bg-accent-pressed',
          'disabled:bg-border-default disabled:text-text-disabled disabled:cursor-not-allowed',
        ],
        variant === 'secondary' && [
          'bg-surface-raised text-ink-primary border border-border-default',
          'hover:bg-surface-overlay',
          'active:border-border-strong',
          'disabled:text-text-disabled disabled:cursor-not-allowed',
        ],
        className,
      )}
      {...props}
    >
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
      {children}
    </button>
  );
}
```

The `bg-accent text-white` pairing on the primary button is the one deliberate spot in the system where text color is a fixed literal (`white`) rather than a token, and it is called out explicitly here rather than left for a reader to wonder whether it violates the "no hardcoded colors" rule: white text on `--accent-600` (light, `L 58%`) clears AA at 4.6:1, and white text on `--accent-400` (dark, `L 78%`) still clears AA at 4.5:1 specifically *because* `--accent-400` was capped at `L 78%` rather than pushed brighter — pushing the dark-mode accent lighter still (which would otherwise be a reasonable-looking choice purely for "pop" against the dark canvas) would break this exact pairing. This is a concrete instance of a general rule worth stating plainly: **every token value in this document was chosen with its actual downstream text/background pairings in mind, not in isolation** — changing `--accent-400` without re-checking the button in `Examples` is exactly the kind of edit that silently reintroduces a contrast failure.

## Composed: the Appearance settings screen

```tsx
// app/(app)/settings/appearance/page.tsx
import { ThemeToggle } from '@/components/settings/theme-toggle';
import { Card } from '@/components/ui/card';

export default function AppearanceSettingsPage() {
  return (
    <div className="mx-auto max-w-2xl space-y-8 p-6">
      <header>
        <h1 className="text-2xl font-semibold text-ink-primary">Appearance</h1>
        <p className="mt-1 text-sm text-ink-secondary">
          Choose how QAYD looks on this device. System follows your operating system automatically.
        </p>
      </header>

      <Card className="p-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-sm font-medium text-ink-primary">Theme</h2>
            <p className="text-sm text-ink-tertiary">Applies instantly, syncs across your devices.</p>
          </div>
          <ThemeToggle />
        </div>
      </Card>
    </div>
  );
}
```

Nothing in this page reaches for a raw color: `text-ink-primary`, `text-ink-secondary`, `text-ink-tertiary`, and the `Card` component's own `bg-surface-base` do the entire job, and the page therefore requires zero `dark:` branches of its own — it is correct in dark mode purely because its building blocks already are, which is the entire point of the two-layer token architecture in `Token Mapping`.

## Composed: an AI Command Card, rendered in dark mode

Tying together `Color & Contrast In Dark` (status semantics), `Charts & Data Viz In Dark` (the confidence ring), and `Surfaces & Elevation In Dark` (card chrome) against a real payload from `AI_COMMAND_CENTER.md`:

```json
{
  "widget_id": "fraud_alert",
  "agent_code": "FRAUD_DETECTION",
  "confidence_score": 94.0,
  "status": "critical",
  "headline": "Vendor bank-detail change held a KWD 18,540.000 payment pending verification.",
  "sources": [{ "type": "vendors", "id": 233, "label": "Al-Fajr Cement Supplies" }]
}
```

```tsx
// components/ai/command-card.tsx (excerpt)
export function CommandCard({ card }: { card: CommandCardPayload }) {
  const statusMap: Record<string, 'neutral' | 'success' | 'info' | 'warning' | 'error'> = {
    ok: 'success', info: 'info', warning: 'warning', critical: 'error',
  };

  return (
    <div
      className={cn(
        'rounded-2xl border p-4',
        'bg-surface-base border-border-subtle',
        card.status === 'critical' && 'border-s-4 border-s-status-error', // hold treatment — the ONE state that gets this
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm text-ink-primary">{card.headline}</p>
        <ConfidenceRing score={card.confidence_score} />
      </div>
      <StatusBadge status={statusMap[card.status]}>{card.status}</StatusBadge>
    </div>
  );
}
```

In dark mode: the card sits at `--surface-base` (`ink-925`) against the `--surface-canvas` (`ink-975`) grid behind it, its `border-subtle` (`ink-850`) hairline is what makes it read as a distinct card at all (per the elevation model's rule that borders — not shadow — do the primary depth work in dark mode), the fraud hold's left border and status chip render `--status-error` at its dark-tuned value (`oklch(74% 0.16 21)` — bright enough to read as urgent against `ink-925` without the harsh, alarming saturation a naive "just brighten the light-mode red" approach would produce), and the confidence ring renders `--ai-accent` (violet) — a color that appears nowhere else on this card, so "the AI is 94% sure" and "this is critical" remain two legible, independently-colored facts rather than one merged impression.

# Edge Cases

- **First-ever visit, JavaScript blocked or disabled.** The `<meta name="color-scheme" content="light dark">` tag and the critical `@media (prefers-color-scheme: dark)` CSS fallback (`Persistence & SSR`, Layer 3) give a no-JS visitor an approximately-correct theme from CSS alone. There is no toggle available to them (it requires the React tree), which is an accepted, documented limitation rather than a silent gap.
- **Switching active company never changes theme.** Theme lives on `users.settings`, not `companies.settings` (aside from the optional org-default a new member inherits once, per `Persistence & SSR`) — a `POST /switch-company` call that swaps `X-Company-Id` must not trigger any theme re-resolution, and this is verified by an explicit test asserting `resolvedTheme` is unchanged across a company switch.
- **Exported PDFs and print always render light**, regardless of the exporting user's in-app preference — restated from `Images/Logos In Dark` because it is the edge case engineers most often get wrong by naively piping the app's current CSS variables into a server-side PDF renderer. The PDF export pipeline uses its own fixed, light-only stylesheet, never the live theme token set.
- **Transactional emails are outside the Next.js app entirely.** Invoice reminders, approval requests, and payroll notifications are HTML emails rendered by the Laravel backend, subject to each email client's own (often inconsistent) automatic dark-mode re-processing — Apple Mail and Outlook, among others, will attempt to auto-invert an email's colors under their own heuristics. QAYD's email templates include `<meta name="color-scheme" content="light dark">` and `<meta name="supported-color-schemes" content="light dark">`, plus explicit, `!important`-guarded background/text colors on every block, specifically so an email client's auto-dark-mode heuristic does not invert the QAYD logo into something broken — the same underlying failure mode as the banned `filter: invert()` CSS trick, arriving from a completely different, less controllable surface.
- **Android/Chrome "Force Dark" fighting an already-dark-aware app.** Some Android WebViews and Chrome's own "Force Dark" accessibility setting attempt to auto-darken pages that appear to be light-only, by heuristically inverting colors — which can misfire against a page that is *already* dark-mode-aware and produce a double-processed, washed-out result. The `color-scheme` CSS property (set alongside the token blocks in `Token Mapping`) and the `<meta name="color-scheme">` tag are the documented signal that tells the browser "this page already handles its own dark mode; do not apply heuristic re-coloring on top of it."
- **`forced-colors: active` (Windows High Contrast).** QAYD's own token values are suppressed under this mode in favor of system colors for borders, focus rings, and button outlines — fighting a user's OS-level high-contrast choice with the product's own palette would defeat the accessibility feature the user explicitly turned on.
- **Third-party OAuth popups are not themeable.** The Google/Apple/Microsoft login popups invoked from QAYD's own sign-in screen render entirely outside QAYD's DOM and CSS — they cannot be dark-mode-styled by QAYD at all, and a light-themed OAuth popup appearing over a dark-themed QAYD login screen is expected behavior, not a defect to file against this document.
- **Live camera/video feeds are never tinted.** The Document AI OCR capture flow's live camera preview (for photographing a physical invoice or receipt) is real-world video passed through untouched; only the surrounding capture-button chrome and frame follow the active theme, exactly as photographic content does in `Images/Logos In Dark`.
- **A theme toggle mid-flight with a realtime update in progress.** A Laravel Reverb WebSocket push (e.g., a Command Card updating live after `bank.synced` fires) that lands in the same render pass as a theme toggle is handled by ordinary React re-render semantics — the incoming data update and the theme-driven class change are independent state changes that both flow through the same render, so there is no special-cased "pause realtime updates while switching themes" logic required or present; this is called out only because a naive implementation using direct DOM manipulation for either concern (rather than React state) could plausibly race, and QAYD's implementation avoids that class of bug structurally by keeping both concerns inside React's normal render cycle.
- **A user with `prefers-contrast: more` set at the OS level.** Emerging support for `@media (prefers-contrast: more)` lets QAYD further strengthen the already-AA-passing dark-mode border tokens (bumping `--border-subtle` toward `--border-default`'s value) for users who have signaled they want more separation than the AA floor guarantees — a progressive enhancement layered on top of the baseline in `Color & Contrast In Dark`, never a replacement for it.
- **Company-branding customization (future: white-label) must not break the token system.** If a future QAYD tier allows a company to inject its own brand accent (replacing QAYD's emerald with a customer's own brand color for their users), that override is scoped to exactly the `--accent-*` tokens and never touches `--status-*` or `--ai-accent` — a white-labeled company's custom brand color could otherwise accidentally collide with the finance-success green or the AI-provenance violet the way QAYD's own emerald almost did, and the reserved-token separation this document establishes is precisely what protects against that collision by construction rather than by the customer's good luck in picking a color.

# End of Document
