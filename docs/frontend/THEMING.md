# Theming — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Theming
---

# Purpose

Theming is the layer that turns QAYD's design tokens into pixels on screen: color, typography, spacing, radius, elevation, motion, density, and text direction, for every one of the ~14 modules the Next.js frontend renders. This document is the single source of truth for that layer. Every other frontend document — the component library reference, and each screen spec (Dashboard, Journal Entries, Trial Balance, Banking, AI Command Center, and the rest) — assumes the token names, provider contracts, and RTL/dark-mode mechanics defined here rather than re-deriving them. Where a screen doc's "Dark Mode" or "RTL & Localization" section says "uses the standard tokens," this is the document that defines what "standard" means.

QAYD's frontend never contains business or financial logic; it renders state the API returns and calls the API to change it. Theming inherits that boundary strictly: a design token controls *how* a control looks, never *whether* it renders, is enabled, or is reachable — that is RBAC's job (see `docs/foundation/PERMISSION_SYSTEM.md` and `docs/api/AUTHORIZATION_API.md`), resolved server-side and expressed to the frontend as the permission array on `GET /api/v1/auth/me`. A user who lacks `bank.transfer` does not see a grayed-out button styled by a "disabled" token; the affordance is not rendered at all. Theming also carries no AI semantics: confidence scores, proposal/approval affordances, and AI status indicators are functional UI states owned by the relevant screen doc's "AI Integration" section, not by this document — this document only guarantees that whatever color or motion those states use is a token, not a hardcoded value, so they remain correct across light, dark, RTL, and white-labeled builds automatically.

The design taste this document encodes is explicit and non-negotiable: near-monochrome ink, exactly one disciplined accent hue, generous whitespace, a grotesque display face paired with a clean text face, restrained radii, and calm, purposeful motion — the register of Linear, Stripe, and Tap Payments, not a rounded, multi-color consumer app. Section-by-section, this document:

- Defines the three-tier token architecture (primitive → semantic → component) and its CSS-variable naming scheme.
- Specifies the theme provider (`next-themes`) and how it is made SSR-safe inside the Next.js 15 App Router.
- Maps every token onto the Tailwind CSS configuration consumed by shadcn/ui and Framer Motion.
- Enumerates the full light and dark semantic palettes.
- Specifies per-company white-label branding sourced from `company_settings.branding`, including the guardrails that keep a tenant's customization from breaking the editorial system.
- Specifies per-user density and text-size preferences.
- Treats text direction (LTR/RTL) as a theming dimension with the same rigor as color scheme.
- Specifies the runtime mechanics of switching theme, density, or company context without a page reload or a flash of incorrect style.
- Defines governance: who can add a token, and what a new token must prove before it ships.

This document does not define the component API surface (props, variants, composition) of individual shadcn/ui-based components — that belongs to the component library document. It does not define i18n message catalogs or translation workflow — that belongs to the platform's i18n/localization surface (Next.js `next-intl`, per `docs/database/DATABASE_SEEDING.md`); this document only defines the *direction* dimension of theming that RTL locales require. It does not define the Flutter mobile app's theme implementation, though Governance specifies the shared token package that keeps mobile and web from drifting apart visually.

# Token Architecture

QAYD's tokens are organized into three strictly ordered tiers. Every value a component renders traces back through this chain; nothing in application code ever references tier 1 directly.

```
Primitive tokens  →  Semantic tokens  →  Component tokens  →  Tailwind class / shadcn CSS var  →  pixel
   (raw scale)        (role, by name)      (component-scoped)      (utility or inline style)
```

**Primitive tokens** are raw, context-free scales: color ramps, the spacing scale, the radius scale, the type scale, shadow levels, motion durations/easings, and z-index bands. A primitive has no opinion about what it is used for. It is named `--{category}-{step}`, e.g. `--color-ink-950`, `--color-emerald-600`, `--space-6`, `--radius-lg`, `--text-2xl`, `--shadow-md`, `--duration-fast`, `--ease-out`. Primitives are the only tier allowed to contain a literal color value, a literal pixel/rem value, or a literal cubic-bezier — every other tier is a reference.

**Semantic tokens** assign a *role* to a primitive: `--color-bg-canvas`, `--color-fg-primary`, `--color-border-subtle`, `--color-accent`, `--color-danger`, `--color-financial-positive`. A semantic token's value is always `var(--primitive-name)` (or, for the branding-overridable accent family, a runtime-computed value — see Company Branding). Semantic tokens are what change between light and dark mode: `--color-bg-canvas` points at `--color-ink-0` in light mode and `--color-ink-950` in dark mode, while the *primitive* `--color-ink-0` itself never changes meaning. This indirection is the entire reason dark mode, white-labeling, and density are each a one-line re-pointing exercise rather than a re-theme of every component.

**Component tokens** are scoped to one component family and reference semantic tokens exclusively, never primitives: `--button-primary-bg: var(--color-accent)`, `--card-bg: var(--color-bg-surface)`, `--table-row-hover-bg: var(--color-bg-surface-raised)`. A component token exists when a component needs a name more specific than the semantic role — e.g. `--input-border-invalid` needs to exist as its own token even though today it simply equals `var(--color-danger)`, because a future redesign might want invalid inputs to use a different treatment (a background tint, not just a border color) without touching the global `--color-danger` semantic that a dozen other components also depend on.

## Naming convention

| Tier | Pattern | Example | Allowed value |
|---|---|---|---|
| Primitive | `--{category}-{step}` | `--color-emerald-600`, `--space-8`, `--radius-md` | Literal (hex, px/rem, ms, cubic-bezier) |
| Semantic | `--color-{role}[-{variant}]` or `--{concept}` | `--color-bg-surface-raised`, `--color-accent-hover`, `--density-scale` | `var(--primitive)` only |
| Component | `--{component}-{part}[-{state}]` | `--button-primary-bg-hover`, `--card-border`, `--table-row-selected-bg` | `var(--semantic)` only |

Category names in tier 1 are fixed: `color`, `space`, `radius`, `text` (font-size), `font` (family/weight), `leading` (line-height), `shadow`, `duration`, `ease`, `z`. Role names in tier 2 are fixed vocabulary, extended only through the Governance process: `bg`, `fg`, `border`, `accent`, `success`, `warning`, `danger`, `info`, `financial-positive`, `financial-negative`, `financial-neutral`, `chart-1`…`chart-6`.

## Primitive categories

| Category | Steps | Purpose |
|---|---|---|
| `color-ink` | 0, 25, 50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950 | The near-monochrome neutral ramp — canvas, surfaces, text, borders in both themes |
| `color-emerald` | 50–950 (11 steps) | The one disciplined brand/accent hue |
| `color-green` / `color-amber` / `color-red` / `color-blue` | 50–900 | Feedback hues (success/warning/danger/info) — deliberately distinct from `color-emerald` so "this is branded" and "this succeeded" never look identical (see Light/Dark Themes) |
| `space` | 1,2,3,4,5,6,8,10,12,16 (→ 4,8,12,16,20,24,32,40,48,64px) | Layout and padding scale, identical to Tailwind's default rem-based scale — QAYD does not override Tailwind spacing, it documents the shared steps |
| `radius` | sm(6px), md(10px), lg(14px), xl(20px) | Corner rounding, deliberately tighter than the `8/12/20/24` sketched in the foundation `DESIGN_SYSTEM.md` draft — see the note under Light/Dark Themes on why this document supersedes those values |
| `text` | xs…5xl (9 steps, 0.75rem–3rem) | Font-size scale, `0.9375rem` (15px) base rather than 16px — a deliberate density choice for a data-dense financial UI |
| `font` | sans, display, arabic; weight 400/500/600/700 | Typeface families and weights |
| `shadow` | sm, md, lg | Elevation — three levels, "no heavy shadows" per foundation taste |
| `duration` | fast(120ms), base(200ms), slow(320ms) | Motion timing |
| `ease` | out, in-out | Cubic-bezier curves |
| `z` | dropdown(20), sticky(30), overlay(40), modal(50), toast(60), tooltip(70) | Stacking bands |

Exact primitive values are enumerated in full in Light/Dark Themes (color) and Examples (everything else); this section defines shape and naming, not the final value table, so the two do not drift when one is edited.

## Semantic → component flow example

```
--color-emerald-600 (primitive, literal #157A4D)
        │
        ▼
--color-accent (semantic, light mode → var(--color-emerald-600))
        │
        ▼
--button-primary-bg (component, → var(--color-accent))
        │
        ▼
<Button variant="primary">  renders  background-color: var(--button-primary-bg)
```

Flip to dark mode and only the second arrow changes (`--color-accent` re-points to `--color-emerald-400`); the `Button` component's CSS is never touched. Apply a tenant's custom brand color and only the second arrow changes again (`--color-accent` re-points to a runtime-computed value); `Button` still never changes. This is the entire value proposition of the three-tier model and the reason component authors are prohibited (see Governance) from ever writing `background: #157A4D` or even `background: var(--color-emerald-600)` directly in component code — only `var(--color-accent)` or `var(--button-primary-bg)` are legal targets.

# Theme Provider

QAYD renders the color-scheme dimension (light/dark/system) with `next-themes`, mounted once in the frontend's provider tree. Two constraints from the App Router shape how it is wired:

1. The root `app/layout.tsx` is a **Server Component**. `next-themes`'s `ThemeProvider` uses `useState`/`useEffect`/`localStorage` internally, so it must live inside a **Client Component** boundary (`app/providers.tsx`, `"use client"`), imported into the server root layout — never the reverse.
2. A Server Component cannot read `localStorage`, so it cannot know, at render time, which theme class to put on `<html>`. Left unhandled, this produces a flash of the wrong theme (FOIT — "flash of incorrect theme"): the server (and the very first paint) renders light, then a client script repaints dark a frame later.

`next-themes` solves the general case by injecting a tiny synchronous, blocking `<script>` into `<head>` — before hydration, before any React code runs — that reads the persisted preference and sets the `class`/`data-*` attribute on `<html>` immediately. QAYD closes the remaining gap (a value visible to *that* inline script but not to the Server Component doing the actual HTML render) by also persisting the resolved theme to a `theme` cookie every time it changes, so the Server Component itself can read the cookie and render the correct attribute server-side; `next-themes`'s client script then only *confirms* the attribute rather than *changing* it, and there is no flash at all except on a device's first-ever visit with no cookie set (which falls back to the `Sec-CH-Prefers-Color-Scheme` client hint at Cloudflare's edge where available, otherwise defaults to light).

```tsx
// app/providers.tsx
"use client";

import { ThemeProvider } from "next-themes";
import type { ReactNode } from "react";

export function AppProviders({
  children,
  initialTheme,
}: {
  children: ReactNode;
  initialTheme: "light" | "dark" | "system";
}) {
  return (
    <ThemeProvider
      attribute={["class", "data-theme"]}
      defaultTheme={initialTheme}
      enableSystem
      disableTransitionOnChange
      storageKey="qayd-theme"
    >
      {children}
    </ThemeProvider>
  );
}
```

`attribute={["class", "data-theme"]}` is intentional and non-default: Tailwind's `dark:` variant needs the `class="dark"` toggle (see Tailwind Integration), but plain CSS written outside Tailwind (email-style print stylesheets, third-party embeds) reads `[data-theme="dark"]`, so `next-themes` is configured to set both simultaneously rather than picking one and forcing every consumer onto it. `disableTransitionOnChange` is mandatory, not cosmetic — see Runtime Theme Switching for why omitting it produces a visibly broken toggle.

```tsx
// app/layout.tsx  (Server Component)
import { cookies } from "next/headers";
import { getLocale, getDirection } from "@/lib/i18n"; // thin wrapper over next-intl
import { AppProviders } from "./providers";
import "./globals.css";

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const cookieTheme = cookies().get("qayd-theme")?.value;
  const initialTheme = (cookieTheme === "light" || cookieTheme === "dark" || cookieTheme === "system"
    ? cookieTheme
    : "system") as "light" | "dark" | "system";
  const locale = await getLocale();
  const dir = getDirection(locale); // "ltr" | "rtl", resolved from `languages.direction`

  return (
    <html lang={locale} dir={dir} suppressHydrationWarning>
      <body>
        <AppProviders initialTheme={initialTheme}>{children}</AppProviders>
      </body>
    </html>
  );
}
```

`suppressHydrationWarning` on `<html>` is required and scoped deliberately narrow: `next-themes`'s inline script may adjust `class`/`data-theme` between the server-rendered markup and React's hydration pass (e.g. resolving `system` to an actual `dark`), which is an *expected*, intentional mismatch on that one element. The prop is never applied at a wider scope — applying it on `<body>` or a layout wrapper would silently swallow real hydration bugs.

The provider tree composes four independent concerns in a fixed order, each documented in its own section below: theme (this section), brand (Company Branding), density/text-size (Density & Text-Size Preferences), and locale/direction (handled by `next-intl`'s `NextIntlClientProvider`, composed alongside but owned by the i18n surface, not this document). Order matters only in that `AppProviders` must sit above the router boundary (inside `app/layout.tsx`, not inside a route segment's own `layout.tsx`), so that client-side navigations between screens never remount it and never re-flash — detailed in Runtime Theme Switching.

# Tailwind Integration

QAYD's Tailwind configuration never hardcodes a color; every entry in `theme.extend` resolves to a semantic CSS variable, following the convention shadcn/ui itself expects (`hsl(var(--x))`), so that Tailwind's opacity modifiers (`bg-accent/10`, `text-danger/60`) work on every token without extra plumbing.

```ts
// tailwind.config.ts
import type { Config } from "tailwindcss";

const config: Config = {
  darkMode: ["class"],
  content: ["./app/**/*.{ts,tsx}", "./components/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        canvas: "hsl(var(--color-bg-canvas) / <alpha-value>)",
        surface: "hsl(var(--color-bg-surface) / <alpha-value>)",
        "surface-raised": "hsl(var(--color-bg-surface-raised) / <alpha-value>)",
        overlay: "hsl(var(--color-bg-overlay) / <alpha-value>)",
        foreground: "hsl(var(--color-fg-primary) / <alpha-value>)",
        "foreground-secondary": "hsl(var(--color-fg-secondary) / <alpha-value>)",
        muted: "hsl(var(--color-fg-muted) / <alpha-value>)",
        border: "hsl(var(--color-border-subtle) / <alpha-value>)",
        "border-strong": "hsl(var(--color-border-strong) / <alpha-value>)",
        accent: {
          DEFAULT: "hsl(var(--color-accent) / <alpha-value>)",
          hover: "hsl(var(--color-accent-hover) / <alpha-value>)",
          active: "hsl(var(--color-accent-active) / <alpha-value>)",
          subtle: "hsl(var(--color-accent-subtle-bg) / <alpha-value>)",
          foreground: "hsl(var(--color-fg-on-accent) / <alpha-value>)",
        },
        success: "hsl(var(--color-success) / <alpha-value>)",
        warning: "hsl(var(--color-warning) / <alpha-value>)",
        danger: "hsl(var(--color-danger) / <alpha-value>)",
        info: "hsl(var(--color-info) / <alpha-value>)",
        "financial-positive": "hsl(var(--color-financial-positive) / <alpha-value>)",
        "financial-negative": "hsl(var(--color-financial-negative) / <alpha-value>)",
        "financial-neutral": "hsl(var(--color-financial-neutral) / <alpha-value>)",
      },
      borderRadius: {
        sm: "var(--radius-sm)",
        md: "var(--radius-md)",
        lg: "var(--radius-lg)",
        xl: "var(--radius-xl)",
      },
      fontFamily: {
        sans: ["var(--font-sans)", "system-ui", "sans-serif"],
        display: ["var(--font-display)", "var(--font-sans)", "system-ui", "sans-serif"],
        arabic: ["var(--font-arabic)", "system-ui", "sans-serif"],
      },
      boxShadow: {
        sm: "var(--shadow-sm)",
        md: "var(--shadow-md)",
        lg: "var(--shadow-lg)",
      },
      transitionDuration: {
        fast: "var(--duration-fast)",
        base: "var(--duration-base)",
        slow: "var(--duration-slow)",
      },
      transitionTimingFunction: {
        out: "var(--ease-out)",
        "in-out": "var(--ease-in-out)",
      },
    },
  },
  plugins: [require("tailwindcss-animate")],
};

export default config;
```

Note the `hsl(var(--x) / <alpha-value>)` pattern requires every color primitive to be stored as an **unitless HSL triplet** (`152 68% 27%`), not a hex string — `globals.css` stores primitives that way (see Examples) and only formats to hex/rgb at the edges (JSON payloads, design tooling) where a triplet would be meaningless.

Spacing is deliberately **not** overridden in `theme.extend.spacing`: QAYD's `--space-*` primitive scale (4/8/12/16/20/24/32/40/48/64px) is, by design, identical to Tailwind's own default rem-based spacing scale (`p-1`…`p-16`), so `p-4` already means 16px without any custom configuration; the primitive scale exists so non-Tailwind contexts (Framer Motion inline styles, chart SVG padding, the Flutter token export) share the exact same steps, not to give Tailwind a second competing scale.

Two conventions from shadcn/ui carry directly into QAYD component code:

- **`cn()`** (`clsx` + `tailwind-merge`) for conditionally composing Tailwind classes without duplicate/conflicting utilities reaching the DOM.
- **`cva`** (`class-variance-authority`) for defining a component's variant matrix (`variant: "primary" | "secondary" | "ghost" | "destructive"`, `size: "sm" | "md" | "lg"`) as a typed function that maps a variant name to the Tailwind classes referencing this document's tokens — never to a raw color. See Examples for a worked `Button` definition.

Tailwind's built-in **logical-property utilities** (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `text-end`, shipped natively since Tailwind 3.3) are the default spacing/alignment utilities for all new component code; physical-direction utilities (`ml-*`, `mr-*`, `left-*`, `right-*`) are reserved for the narrow cases enumerated in RTL as a theming dimension. No separate RTL plugin is required or installed.

# Light/Dark Themes

Dark mode in QAYD is a deliberate re-design of elevation and emphasis, not a naive color inversion. Three rules govern every semantic mapping below:

1. **The canvas is near-black, never pure black** (`#0A0D0B`, not `#000000`) — pure black against near-white text produces harsher-than-necessary contrast for large text blocks and crushes shadow detail on OLED panels; near-black also carries the faintest cool-green undertone that ties the neutral ramp back to the brand hue without introducing a second accent.
2. **Elevation inverts.** In light mode, a raised surface (a card floating over the canvas) is signaled by a soft shadow on a same-or-lighter background. In dark mode, shadows barely register, so a raised surface is signaled by making the background progressively *lighter* as it elevates (canvas `950` → surface `900` → raised `800`) plus a 1px hairline border — shadows are demoted to a secondary cue, never the only one.
3. **The accent hue is held constant; lightness and chroma are not.** `--color-accent` is "the same green" in both themes perceptually, but its light-mode step (`emerald-600`, darker, for contrast against a white canvas) and dark-mode step (`emerald-400`, lighter, for contrast against a near-black canvas) are different primitive steps. The same rule applies to every feedback color.

## Semantic palette

| Semantic token | Light value | Dark value | Notes |
|---|---|---|---|
| `--color-bg-canvas` | `#FFFFFF` (`ink-0`) | `#0A0D0B` (`ink-950`) | Page background |
| `--color-bg-surface` | `#FBFCFB` (`ink-25`) | `#151A16` (`ink-900`) | Cards, panels, table body |
| `--color-bg-surface-raised` | `#FFFFFF` + `shadow-md` | `#232A24` (`ink-800`) | Popovers, dropdown menus, dialogs |
| `--color-bg-overlay` | `rgb(10 13 11 / 0.40)` | `rgb(0 0 0 / 0.60)` | Modal/drawer scrim |
| `--color-fg-primary` | `#0A0D0B` (`ink-950`) | `#F6F7F6` (`ink-50`) | Headings, primary body text |
| `--color-fg-secondary` | `#4F594F` (`ink-600`) | `#BAC1BB` (`ink-300`) | Secondary text, table cell values |
| `--color-fg-muted` | `#939C94` (`ink-400`) | `#6C766D` (`ink-500`) | Placeholders, captions, disabled text |
| `--color-fg-on-accent` | `#FFFFFF` | `#0A0D0B` | Text/icons drawn on top of `--color-accent`; see derivation rule in Company Branding |
| `--color-border-subtle` | `#D8DCD9` (`ink-200`) | `#232A24` (`ink-800`) | Card edges, table row dividers |
| `--color-border-strong` | `#939C94` (`ink-400`) | `#4F594F` (`ink-600`) | Input borders, focus-adjacent dividers |
| `--color-border-focus-ring` | `#157A4D` (`emerald-600`) | `#3CA974` (`emerald-400`) | `:focus-visible` ring, 2px, AA against both canvases |
| `--color-accent` | `#157A4D` (`emerald-600`) | `#3CA974` (`emerald-400`) | The one disciplined brand hue |
| `--color-accent-hover` | `#106140` (`emerald-700`) | `#66C094` (`emerald-300`) | |
| `--color-accent-active` | `#0D4D34` (`emerald-800`) | `#9BD8BC` (`emerald-200`) | |
| `--color-accent-subtle-bg` | `#EAF7F0` (`emerald-50`) | `#052318` (`emerald-950`) | Selected-row tint, active nav-item background |
| `--color-success` | `#2E9A4E` (`green-600`) | `#4FB86D` | Deliberately a warmer, grassier green than `--color-accent` |
| `--color-warning` | `#B4740C` (`amber-600`) | `#D99A2B` | |
| `--color-danger` | `#C4392E` (`red-600`) | `#E06256` | |
| `--color-info` | `#2464AD` (`blue-600`) | `#5B94D6` | |
| `--color-financial-positive` | = `--color-success` | = dark `--color-success` | Own token so it can diverge from generic "success" later without a system-wide change |
| `--color-financial-negative` | = `--color-danger` | = dark `--color-danger` | Never the *only* signal — always paired with a leading sign (see Edge Cases) |
| `--color-financial-neutral` | = `--color-fg-secondary` | = dark `--color-fg-secondary` | Zero/flat variance |
| `--chart-1` … `--chart-6` | 6-hue categorical set, AA against `bg-surface` | Re-lit per-hue counterparts | Used by Reports/AI Command Center chart canvases |

Hex values above are illustrative of intent; the canonical values ship from the `@qayd/tokens` package (Governance) after passing the automated WCAG contrast script. The binding requirement, independent of the exact hex: body text ≥ 4.5:1 against its background, large text (≥24px or ≥19px bold) and meaningful icon/border boundaries ≥ 3:1, in **both** themes, for every `fg`/`bg` and every feedback pairing in the table above.

Every element that toggles theme also sets the native `color-scheme` CSS property (`color-scheme: light dark` on `:root`, refined by the active `data-theme`), so browser-native chrome — scrollbars, `<select>` menus, date pickers, form-control focus outlines — matches without any bespoke styling.

**Foundation draft supersession.** The earlier `docs/foundation/DESIGN_SYSTEM.md` sketch names an `8/12/20/24px` radius scale and lists "Emerald Green" as both the primary brand color and the success color. This document intentionally refines both: radii are tightened to `6/10/14/20px` (Token Architecture) because a 24px card radius reads as a consumer app, not an editorial financial product, per the founder's explicit taste; and the brand accent (`emerald`, hue ≈160°) is kept visibly distinct from the success feedback color (`green`, hue ≈140°) so a purely decorative brand-colored element is never mistakable for a "this succeeded" state. Both refinements are binding for all new frontend work; `DESIGN_SYSTEM.md` should be read as superseded by this document and its sibling frontend specs wherever the two disagree.

# Company Branding / White-Label

QAYD is white-labelable per company, but deliberately on a **narrow, curated surface** — accent color and logo — never the full design system. This is the platform's existing `company_settings.branding` column (`JSONB NOT NULL DEFAULT '{}'`, documented in `docs/database/ERD.md` as "Colors/logo placement for generated PDFs") gaining a second consumer: it already drives Laravel's server-side PDF rendering for invoices/statements, and this document extends the same column to drive the live Next.js UI, so a tenant's brand is guaranteed identical between their dashboard and their exported documents — one field, two renderers, zero drift.

## Schema

```json
{
  "accent_color": "#157A4D",
  "accent_source": "custom",
  "logo_light_url": "https://cdn.qayd.app/companies/4471/logo-light.svg",
  "logo_dark_url": "https://cdn.qayd.app/companies/4471/logo-dark.svg",
  "favicon_url": "https://cdn.qayd.app/companies/4471/favicon.png",
  "wordmark_display": "logo_and_name",
  "updated_at": "2026-07-10T09:00:00Z",
  "updated_by": 902
}
```

`accent_source` (`"default"` | `"custom"`) lets the UI show a "Reset to QAYD default" affordance only when a tenant has actually overridden it. `wordmark_display` (`"logo_only"` | `"logo_and_name"` | `"name_only"`) controls the sidebar/header lockup without giving the tenant control over its typography or placement. An empty `{}` (the column default for every company at creation) means: render the platform default accent and the platform wordmark — there is no "unbranded" broken state.

## Delivery

Branding is not a separate endpoint call. `GET /api/v1/auth/me` (`docs/api/AUTHENTICATION_API.md`) already returns `active_company` cheaply on cold start specifically so every client can "render UI affordances without a second round trip"; this document extends that same object with a `branding` field sourced from `company_settings.branding`:

```json
{
  "active_company": {
    "id": "cmp_4471",
    "name_en": "Al-Kandari Trading Co.",
    "name_ar": "شركة الكندري التجارية",
    "base_currency": "KWD",
    "role": "finance_manager",
    "permissions": ["accounting.read", "..."],
    "branding": {
      "accent_color": "#157A4D",
      "accent_source": "custom",
      "logo_light_url": "https://cdn.qayd.app/companies/4471/logo-light.svg",
      "logo_dark_url": "https://cdn.qayd.app/companies/4471/logo-dark.svg",
      "favicon_url": "https://cdn.qayd.app/companies/4471/favicon.png",
      "wordmark_display": "logo_and_name"
    }
  }
}
```

A `useActiveCompanyBranding()` TanStack Query hook (keyed `["auth", "me"]`, the same cache entry every other `/me` consumer reads) feeds a client `BrandProvider` that, on mount and on every company switch, writes a small, fixed set of `--brand-*` custom properties onto `document.documentElement` — it never remounts the tree and never touches any token outside that fixed set.

## Derivation — one input color, a full computed ramp

A tenant supplies exactly **one** hex value. `BrandProvider` derives the hover/active/subtle-background/on-accent-foreground set the same way the platform's own `emerald` ramp was constructed, using OKLCH lightness steps for perceptually even results:

```ts
// lib/brand/deriveAccentRamp.ts
import { formatHex, oklch, parse, clampChroma } from "culori";

export function deriveAccentRamp(hex: string) {
  const base = clampChroma(oklch(parse(hex)!), "oklch");
  const step = (deltaL: number, deltaC = 0) =>
    formatHex({ ...base, l: Math.min(0.98, Math.max(0.02, base.l + deltaL)),
                c: Math.max(0, base.c + deltaC) });

  return {
    accent: formatHex(base),
    accentHover: step(-0.08),
    accentActive: step(-0.16),
    accentSubtleBg: step(0.42, -0.6 * base.c),
    // Pick the higher-contrast of near-white / near-ink-950 against the base color.
    onAccent: base.l > 0.6 ? "#0A0D0B" : "#FFFFFF",
  };
}
```

## Guardrails

The editorial system is protected by architecture, not by policy convention alone:

1. **Fixed override surface.** The `branding` JSON schema has fields for accent color and three logo/favicon URLs — nothing else. Typography, spacing, radius, motion, the neutral ink ramp, and every non-accent semantic token are structurally absent from the schema; a tenant cannot request what the API has no field to accept.
2. **Perceptual clamp on the accent.** Both the Laravel `FormRequest` validating `PATCH` and the frontend's Zod schema reject an `accent_color` whose OKLCH lightness falls outside `0.30–0.65` or whose chroma exceeds the platform's calm-palette ceiling — this is what stops a tenant from setting a neon pink or a washed-out pastel that would fail AA or clash with the near-monochrome system. A rejected value returns `422 BRAND_COLOR_OUT_OF_GAMUT` with the offending lightness/chroma reading, not a silent clamp.
3. **Automatic derivation, not manual ramps.** Because hover/active/subtle/on-accent are computed (above), a tenant cannot submit an inconsistent ramp — there is no field for one.
4. **Permission-gated, single-step approval.** Editing `company_settings.branding` requires `settings.branding.manage`. Branding sits under the platform's general company-settings sensitivity rule (`docs/api/AUTHORIZATION_API.md` marks both `settings.company.manage` and the adjacent `settings.branch.manage` as approval-required, resolved as a single step by the acting role's own seniority rather than a secondary approver):

   | Permission | Category | Approval Required | Default Roles |
   |---|---|---|---|
   | `settings.branding.manage` | Settings | Yes (single-step — Owner/CEO's own authority satisfies it) | Owner, CEO |

   Reading branding carries **no** separate permission: it is bundled into `/auth/me` for any authenticated member of the company, because withholding a company's own logo/color from its own employees serves no security purpose and would break the app shell for everyone below Owner/CEO.
5. **Preview before save.** The settings screen applies a candidate `accent_color` to `BrandProvider` in a session-local, unpersisted preview state; nothing is written to `company_settings` (and no other user in the company sees anything) until the change is explicitly saved.
6. **Fail safe, never fail broken.** If `branding` is `{}`, missing a field, or (via direct data manipulation) contains a value that fails the clamp, the frontend renders the platform default accent and wordmark rather than an empty or invalid CSS variable — a bad brand value degrades gracefully to "unbranded," never to a visibly broken screen.

There is deliberately no AI agent attached to branding: it is a cosmetic, non-financial, human/admin-configured setting, and none of the platform's AI agents (General Accountant, Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO, Fraud Detection, Reporting Agent, Document AI, OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant) has a mandate over presentation.

# Density & Text-Size Preferences

Density and text-size are **per-user**, not per-company, preferences — a Senior Accountant reviewing 200 journal lines a day and the CEO glancing at a dashboard once a week are the same tenant but want different defaults, and the same person may want compact tables on a laptop and larger text on a projector during a board review. This is the direct contrast with Company Branding (per-company, admin-gated) and is why the two live in different tables.

```sql
CREATE TABLE user_preferences (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id               BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    theme                 VARCHAR(10) NOT NULL DEFAULT 'system'
                              CHECK (theme IN ('light','dark','system')),
    density               VARCHAR(10) NOT NULL DEFAULT 'comfortable'
                              CHECK (density IN ('comfortable','compact')),
    text_scale            VARCHAR(10) NOT NULL DEFAULT 'default'
                              CHECK (text_scale IN ('small','default','large')),
    reduced_motion_override BOOLEAN NULL,  -- NULL = follow OS `prefers-reduced-motion`
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_user_preferences_user UNIQUE (user_id)
);
```

`user_preferences` deliberately omits `company_id`/`branch_id` (preferences follow the human across companies, the same reasoning `docs/database/ERD.md` gives for `users` itself carrying no tenant column) and omits `deleted_at` (this is a 1:1 upsert target with no standalone audit value — deleting the owning user cascades it away, and a preference change is not a financial fact worth a soft-delete trail). Both are documented, deliberate exceptions to the platform's standard-columns convention, consistent with how the platform already treats other non-financial, purely-presentational rows.

Exposed as `GET /api/v1/me/preferences` / `PATCH /api/v1/me/preferences`, permission-free beyond authentication (a user always may read/write their own preferences; there is nothing here another user's RBAC grant could need to protect).

## Mechanism

Density does **not** scale the whole application uniformly — page margins, card padding, and section gaps stay constant so the app never feels globally cramped. It scales only the contexts that are actually data-dense: table row height/cell padding and dense list items. This is implemented as a multiplier applied at the point of use, not a blanket transform:

```css
:root {
  --density-scale: 1;
  --row-h: calc(2.75rem * var(--density-scale));       /* 44px comfortable */
  --row-px: calc(var(--space-4) * var(--density-scale));
}
:root[data-density="compact"] {
  --density-scale: 0.82;                                /* 36px effective row height */
}
```

`data-density` is set on `<html>` alongside `dir`/`data-theme` in the same server-rendered root layout pass (sourced from the `qayd-density` cookie, mirroring the theme cookie pattern in Theme Provider) — density gets the same SSR-safe, no-flash treatment as color scheme.

Text-size swaps the root `font-size`, and because every type-scale primitive is `rem`-based (Token Architecture), the entire type system — headings, body, captions, even icon sizes defined in `em` — scales proportionally from one variable:

```css
:root[data-text-scale="small"]   { font-size: 87.5%;  }  /* 14px effective root */
:root[data-text-scale="default"] { font-size: 100%;   }  /* 15px effective root, see --text-base */
:root[data-text-scale="large"]   { font-size: 112.5%; }  /* ~16.9px effective root */
```

This composes multiplicatively, not conflictingly, with the user's own browser/OS zoom level — both simply change what `1rem` resolves to, and QAYD's layouts (min-width containers, horizontally scrollable tables per Edge Cases) are built to tolerate the combined range rather than assuming a fixed pixel viewport.

# RTL as a theming dimension

Every QAYD screen is specified for **four** combinations, not two: light/dark × LTR/RTL. Direction is treated with exactly the same architectural weight as color scheme — it is resolved server-side, has no flash, and is governed by tokens rather than by scattered `if (locale === "ar")` branches in component code.

## Resolution

Direction is derived from the user's locale, not chosen independently: `users.locale` (`docs/database/ERD.md`: "Drives RTL rendering on the frontend," exposed as `user.locale` on `GET /api/v1/auth/me`) is looked up against the platform's `languages` table (`docs/database/DATABASE_SEEDING.md`), whose `direction` column (`'ltr' | 'rtl'`) is the actual source of truth — `ar` → `rtl`, `en`/`fr` → `ltr`. The root Server Component resolves this once (via the `next-intl` `getLocale()` wrapper shown in Theme Provider) and sets `<html lang={locale} dir={dir}>` in the same server-rendered pass that sets the theme/density attributes — so, like theme, there is no client-side flash of the wrong direction.

## Logical properties over physical utilities

Component code is written exclusively with Tailwind's logical-property utilities (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `text-end`, `rounded-s-*`, `rounded-e-*`), which resolve against the browser's own understanding of `dir`, not against a locale check in JavaScript. A CI check (a `grep`-based Danger/lint step, named in Governance) flags any new usage of `ml-*`, `mr-*`, `left-*`, `right-*`, `pl-*`, `pr-*` in component source as a review blocker, with two narrow, explicit exceptions: (a) values that are provably direction-invariant (e.g. `top-*`, `bottom-*` are unaffected by `dir` and are not flagged), and (b) the icon-mirroring utility below, which is a deliberate, opt-in physical transform.

## Icon mirroring

Most Lucide icons must **not** flip with direction — a checkmark, a trash can, a gear, a company logo, a flag, and any numeral mean the same thing regardless of reading direction, and mirroring them looks broken. A curated, opt-in `.rtl-flip` utility exists for the narrow class of icons whose meaning is genuinely directional:

```css
.rtl-flip {
  transform: scaleX(var(--dir-scale-x, 1));
}
[dir="rtl"] {
  --dir-scale-x: -1;
}
```

| Mirror in RTL | Never mirror |
|---|---|
| `ChevronRight`/`ChevronLeft` used for "next/back" pagination | `Check`, `X`, `Trash2`, `Settings` (no inherent handedness) |
| `ArrowRight`/`ArrowLeft` used for flow/progression | Company/brand logos, avatars, flags |
| `Undo2`/`Redo2` | Currency symbols, numerals, QR/barcodes |
| `LogOut` (exit-arrow glyph) | Any icon depicting a real-world object (a document, a card, a building) |

## Numerals and embedded currency

Even inside a right-to-left Arabic sentence, a KWD figure must keep its digits in left-to-right order — Arabic script is RTL but Hindu-Arabic numerals are not. Every element that renders a number, amount, account code, or date is wrapped so its internal digit order is protected regardless of the surrounding paragraph direction:

```tsx
<bdi dir="ltr" className="tabular-nums">{formatCurrency(amount, "KWD", locale)}</bdi>
```

`tabular-nums` (mapped from `--font-feature-tnum` in the type primitives) additionally keeps digit columns aligned in financial tables regardless of direction, so a trial balance's debit/credit columns line up whether the surrounding UI is LTR or RTL.

## Fonts per script

`next/font` loads three variable families — `--font-sans` (Inter, Latin UI text), `--font-display` (Inter Tight, Latin headings/large numerals), `--font-arabic` (IBM Plex Sans Arabic, all Arabic text at any size, since a dedicated Arabic display grotesque would break family-mixing readability). The switch is keyed off **script**, not just `dir`, because an `en`-locale screen can still contain Arabic content (a vendor's Arabic legal name in a bilingual field):

```css
:root { --font-active: var(--font-sans); }
:lang(ar) { --font-active: var(--font-arabic); }
```

## Motion mirroring

Framer Motion slide/enter variants read a direction-aware sign rather than a hardcoded axis, so "slide in from the trailing edge" is correct in both directions from one variant definition:

```tsx
const dirSign = useDirSign(); // +1 in ltr, -1 in rtl, from a small context hook
const slideInVariants = {
  hidden: { x: 24 * dirSign, opacity: 0 },
  visible: { x: 0, opacity: 1 },
};
```

Every screen doc's "RTL & Localization" section is where the *outcome* of these mechanics is verified per screen (Playwright visual snapshots in both directions); this document defines the mechanism once so screen docs never need to re-derive it.

# Runtime Theme Switching

A user toggling light/dark, density, or text-size at runtime never triggers a server round trip for the toggle itself — only for persisting it so it follows the user to their next session.

```tsx
// components/theme-toggle.tsx
"use client";
import { useTheme } from "next-themes";
import { useUpdatePreferences } from "@/lib/hooks/use-preferences";

export function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const { mutate: persist } = useUpdatePreferences(); // TanStack Query, fire-and-forget

  function onSelect(next: "light" | "dark" | "system") {
    setTheme(next);                 // instant: flips class/data-theme + qayd-theme cookie
    persist({ theme: next });       // PATCH /api/v1/me/preferences, optimistic, no loading UI
  }
  return /* ... */;
}
```

Three mechanics keep the toggle visually clean rather than jarring:

1. **`disableTransitionOnChange`** (set in Theme Provider) injects a page-wide `* { transition: none !important; }` rule for one frame around the class swap, then removes it. Without this, every element with a `transition: background-color …` rule (buttons, cards, inputs — dozens of elements) would animate its color independently and simultaneously, reading as a visible "wave" across the screen instead of an instant switch.
2. **The cookie write is synchronous with the class change** (both happen inside `setTheme`), so the *next* full navigation or hard refresh has the correct value available to the Server Component before it renders — no second flash on the next page load.
3. **The `PATCH` to `/api/v1/me/preferences` is fire-and-forget and optimistic** — the UI never blocks on it and never shows a spinner for a preference change; a failure is retried silently by TanStack Query's default retry policy and, worst case, simply means the preference didn't sync to another device this time, not that the current session's toggle failed.

**Provider placement and navigation.** `AppProviders` (Theme Provider) lives in the root `app/layout.tsx`, above every route segment's own layout and above React's Suspense/router boundary. App Router client-side navigations (soft navigation, RSC payload streaming between screens) therefore never remount it — a user navigating from the Dashboard to Journal Entries never sees the theme, brand, density, or direction reset or re-flash, because none of those providers are inside the part of the tree that unmounts on navigation.

**Company switch re-themes without remounting.** Per `docs/foundation/COMPANY_STRUCTURE.md`, switching a user's active company already changes permissions, dashboard, reports, and financial data instantly; this document adds one more item to that list: **branding**. Switching `X-Company-Id` invalidates and re-fetches the `["auth", "me"]` TanStack Query key, and `BrandProvider` (Company Branding) reacts to the new `active_company.branding` by patching only its fixed `--brand-*` custom property set on `document.documentElement` — the route tree, any open dialog, and any in-progress form's local state all survive the switch untouched.

**Progressive enhancement.** Where supported and where `prefers-reduced-motion` is not set, a theme toggle may use the View Transitions API (`document.startViewTransition(() => setTheme(next))`) for a soft cross-fade instead of an instant cut; this is an enhancement layered on top of the mechanics above, never a replacement for `disableTransitionOnChange`, which still governs every browser that lacks View Transitions support.

Playwright coverage for this section asserts: (a) a computed-style probe (`getComputedStyle(el).backgroundColor`) changes within one animation frame of a toggle click, (b) no hydration-mismatch console error is emitted on cold load in any of the four theme/direction combinations, and (c) a company switch does not remount a marker component mounted in the route tree (verified via a stable `data-testid` node identity check, not just visual similarity).

# Governance

Tokens are the one part of the frontend where an individual component author's local judgment is deliberately constrained, because a single off-convention color or radius, copied once, tends to get copied again. Ownership and process:

**Ownership.** A small Design Systems function (not any one engineer unilaterally) owns `--color-*`, `--space-*`, `--radius-*`, `--text-*`, `--shadow-*`, `--duration-*`, `--ease-*` at the primitive and semantic tiers. Any engineer may propose a change via pull request; merging one requires a Design Systems review, the same way a schema migration requires a backend review regardless of who authored it.

**What requires review, and what doesn't.**

| Change | Requires Design Systems review | Requires only normal code review |
|---|---|---|
| New primitive (a new color ramp, a new spacing step) | Yes | — |
| New semantic token (a new `--color-{role}`) | Yes | — |
| New **component** token that resolves to an existing semantic token | — | Yes |
| A component using an existing token in a new place | — | Yes |
| Any raw hex/`rgb()`/pixel literal in component code | Blocked outright (see enforcement below) | — |

**A new semantic token's PR must include:** (a) a written justification that it cannot be composed from an existing semantic token, (b) both a light-mode and a dark-mode value — a token proposed with only one theme's value is rejected, never merged "to fix dark mode later," (c) the output of the automated contrast script (below) for every `fg`/`bg` pairing the new token participates in, and (d) an explicit note on whether it needs a logical-property (RTL) counterpart.

**Automated enforcement.**

```ts
// scripts/check-contrast.ts — run in CI on every PR touching globals.css
import { readTokenPairs, contrastRatio } from "./lib/tokens";

const REQUIRED = { body: 4.5, large: 3.0, boundary: 3.0 };

for (const pair of readTokenPairs(["light", "dark"])) {
  const ratio = contrastRatio(pair.fg, pair.bg);
  const min = REQUIRED[pair.kind];
  if (ratio < min) {
    console.error(`FAIL ${pair.name} (${pair.theme}): ${ratio.toFixed(2)}:1 < ${min}:1`);
    process.exitCode = 1;
  }
}
```

A companion Stylelint rule (`declaration-property-value-disallowed-list`) bans raw hex/`rgb()` values and bans referencing a `--color-{primitive}` variable from anywhere other than `globals.css` itself — component and screen code may only reference semantic or component tokens. A token's *name* is validated by a small regex check (`--(color|space|radius|text|font|leading|shadow|duration|ease|z)-[a-z0-9-]+`) so a stray `--brandColor` or `--Accent` never merges.

**Versioning and cross-platform parity.** Tokens ship as an internal `@qayd/tokens` package: a generated `globals.css` variable block for the Next.js web app, and a generated Dart `ThemeExtension`/const-map file for the Flutter mobile app, both built from one canonical token source so the two clients cannot silently drift apart visually. The package is semantically versioned; renaming or removing a token requires keeping the old name as an aliased, console-warning deprecation for at least one minor version before deletion, exactly the deprecation discipline `docs/api/API_VERSIONING.md` already applies to REST endpoints.

**Drift audits.** A quarterly design-system audit greps shipped screen code for inline `style={{ color: ... }}`/`style={{ background: ... }}` literals — a known failure mode where a one-off value creeps in under deadline pressure and never gets reconciled back into a token. Any hit is either promoted into a real token (if it represents a genuine recurring need) or replaced with an existing one; it is never left as a silent exception.

# Examples

## `app/globals.css` (excerpt — primitives + light/dark semantics)

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

:root {
  color-scheme: light;

  /* Primitives — color-ink (unitless HSL triplets, per the shadcn hsl(var(--x)) convention) */
  --color-ink-0:    0 0% 100%;
  --color-ink-25:   120 8% 98%;
  --color-ink-50:   135 7% 96%;
  --color-ink-100:  130 6% 92%;
  --color-ink-200:  132 6% 85%;
  --color-ink-300:  128 6% 72%;
  --color-ink-400:  130 6% 58%;
  --color-ink-500:  132 7% 44%;
  --color-ink-600:  132 8% 33%;
  --color-ink-700:  134 9% 24%;
  --color-ink-800:  132 10% 16%;
  --color-ink-900:  134 12% 10%;
  --color-ink-950:  150 14% 5%;

  /* Primitives — color-emerald (the one accent hue) */
  --color-emerald-50:  152 46% 94%;
  --color-emerald-100: 152 48% 86%;
  --color-emerald-200: 152 42% 71%;
  --color-emerald-300: 152 42% 55%;
  --color-emerald-400: 152 47% 41%;
  --color-emerald-500: 152 63% 34%;
  --color-emerald-600: 152 68% 27%;
  --color-emerald-700: 152 70% 21%;
  --color-emerald-800: 152 68% 16%;
  --color-emerald-900: 152 65% 12%;
  --color-emerald-950: 152 72% 7%;

  /* Primitives — feedback (deliberately distinct hues from emerald) */
  --color-green-600: 142 55% 38%;
  --color-amber-600: 38 87% 38%;
  --color-red-600:   5  62% 47%;
  --color-blue-600:  212 62% 42%;

  /* Primitives — spacing, radius, type, shadow, motion, z (unchanged across themes) */
  --space-1: 0.25rem; --space-2: 0.5rem;  --space-3: 0.75rem; --space-4: 1rem;
  --space-5: 1.25rem; --space-6: 1.5rem;  --space-8: 2rem;    --space-10: 2.5rem;
  --space-12: 3rem;   --space-16: 4rem;

  --radius-sm: 6px; --radius-md: 10px; --radius-lg: 14px; --radius-xl: 20px;

  --text-xs: 0.75rem;  --text-sm: 0.8125rem; --text-base: 0.9375rem; --text-lg: 1.0625rem;
  --text-xl: 1.25rem;  --text-2xl: 1.5rem;   --text-3xl: 1.875rem;   --text-4xl: 2.25rem;
  --text-5xl: 3rem;

  --font-sans: "Inter", ui-sans-serif, system-ui, sans-serif;
  --font-display: "Inter Tight", var(--font-sans);
  --font-arabic: "IBM Plex Sans Arabic", var(--font-sans);

  --shadow-sm: 0 1px 2px 0 rgb(10 13 11 / 0.04);
  --shadow-md: 0 4px 10px -2px rgb(10 13 11 / 0.06), 0 2px 4px -2px rgb(10 13 11 / 0.04);
  --shadow-lg: 0 12px 24px -6px rgb(10 13 11 / 0.08), 0 4px 8px -4px rgb(10 13 11 / 0.04);

  --duration-fast: 120ms; --duration-base: 200ms; --duration-slow: 320ms;
  --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
  --ease-in-out: cubic-bezier(0.65, 0, 0.35, 1);

  --z-dropdown: 20; --z-sticky: 30; --z-overlay: 40; --z-modal: 50; --z-toast: 60; --z-tooltip: 70;

  /* Semantic — light mode */
  --color-bg-canvas: var(--color-ink-0);
  --color-bg-surface: var(--color-ink-25);
  --color-bg-surface-raised: var(--color-ink-0);
  --color-bg-overlay: 150 14% 5% / 0.40;
  --color-fg-primary: var(--color-ink-950);
  --color-fg-secondary: var(--color-ink-600);
  --color-fg-muted: var(--color-ink-400);
  --color-fg-on-accent: 0 0% 100%;
  --color-border-subtle: var(--color-ink-200);
  --color-border-strong: var(--color-ink-400);
  --color-border-focus-ring: var(--color-emerald-600);
  --color-accent: var(--color-emerald-600);
  --color-accent-hover: var(--color-emerald-700);
  --color-accent-active: var(--color-emerald-800);
  --color-accent-subtle-bg: var(--color-emerald-50);
  --color-success: var(--color-green-600);
  --color-warning: var(--color-amber-600);
  --color-danger: var(--color-red-600);
  --color-info: var(--color-blue-600);
  --color-financial-positive: var(--color-success);
  --color-financial-negative: var(--color-danger);
  --color-financial-neutral: var(--color-fg-secondary);
}

[data-theme="dark"] {
  color-scheme: dark;
  --color-bg-canvas: var(--color-ink-950);
  --color-bg-surface: var(--color-ink-900);
  --color-bg-surface-raised: var(--color-ink-800);
  --color-bg-overlay: 0 0% 0% / 0.60;
  --color-fg-primary: var(--color-ink-50);
  --color-fg-secondary: var(--color-ink-300);
  --color-fg-muted: var(--color-ink-500);
  --color-fg-on-accent: var(--color-ink-950);
  --color-border-subtle: var(--color-ink-800);
  --color-border-strong: var(--color-ink-600);
  --color-border-focus-ring: var(--color-emerald-400);
  --color-accent: var(--color-emerald-400);
  --color-accent-hover: var(--color-emerald-300);
  --color-accent-active: var(--color-emerald-200);
  --color-accent-subtle-bg: var(--color-emerald-950);
  /* success/warning/danger/info re-lit for dark canvas at the same primitive tier */
}

/* Company-branding surface — the ONLY tokens BrandProvider may ever write */
:root {
  --brand-accent: var(--color-accent);
  --brand-accent-hover: var(--color-accent-hover);
  --brand-accent-active: var(--color-accent-active);
  --brand-accent-subtle-bg: var(--color-accent-subtle-bg);
  --brand-on-accent: var(--color-fg-on-accent);
}
```

## `app/providers.tsx` (full composition)

```tsx
"use client";
import { ThemeProvider } from "next-themes";
import { BrandProvider } from "@/lib/brand/brand-provider";
import { DensityProvider } from "@/lib/prefs/density-provider";
import type { ReactNode } from "react";
import type { Branding } from "@/lib/brand/types";

export function AppProviders({
  children, initialTheme, initialDensity, initialBranding,
}: {
  children: ReactNode;
  initialTheme: "light" | "dark" | "system";
  initialDensity: "comfortable" | "compact";
  initialBranding: Branding | null;
}) {
  return (
    <ThemeProvider attribute={["class", "data-theme"]} defaultTheme={initialTheme}
                    enableSystem disableTransitionOnChange storageKey="qayd-theme">
      <DensityProvider initialDensity={initialDensity}>
        <BrandProvider initialBranding={initialBranding}>{children}</BrandProvider>
      </DensityProvider>
    </ThemeProvider>
  );
}
```

## Component-tier usage — `Button`

```tsx
// components/ui/button.tsx
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex items-center justify-center rounded-md text-sm font-medium " +
  "transition-colors duration-fast ease-out focus-visible:ring-2 " +
  "focus-visible:ring-[var(--color-border-focus-ring)] disabled:opacity-50",
  {
    variants: {
      variant: {
        primary: "bg-[var(--brand-accent)] text-[var(--brand-on-accent)] " +
                 "hover:bg-[var(--brand-accent-hover)] active:bg-[var(--brand-accent-active)]",
        secondary: "bg-surface text-foreground border border-border hover:bg-surface-raised",
        ghost: "text-foreground-secondary hover:bg-[var(--color-accent-subtle-bg)]",
        destructive: "bg-danger text-white hover:opacity-90",
      },
      size: { sm: "h-8 px-3", md: "h-10 px-4", lg: "h-12 px-6" },
    },
    defaultVariants: { variant: "primary", size: "md" },
  }
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {}

export function Button({ className, variant, size, ...props }: ButtonProps) {
  return <button className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
```

Note `variant="primary"` resolves through `--brand-*` (Company Branding-aware) while every other variant resolves through the plain, non-brandable semantic tokens — this is the concrete mechanism by which "only the accent is white-labelable" is enforced at the component level, not just at the schema level.

# Edge Cases

- **System theme changes while the app is open.** `next-themes` attaches a `matchMedia("(prefers-color-scheme: dark)")` listener when `defaultTheme="system"`/`enableSystem` is active; QAYD does not disable this, so a user who switches their OS from light to dark mid-session sees QAYD follow, unless they have explicitly chosen `light` or `dark` (not `system`) in their own preferences, which always wins.
- **Print.** `@media print` forces light mode, full-strength ink-only text, no accent-tinted backgrounds, and no shadows — a printed trial balance or journal entry must read correctly on a black-and-white office printer, independent of whatever theme was active on screen. This is distinct from, and irrelevant to, PDF *generation* for invoices/statements, which is server-rendered by Laravel from a static snapshot, not from the browser's print CSS at all (next bullet).
- **PDF snapshot independence.** A PDF invoice is rendered by Laravel at issuance time using the `company_settings.branding` value **as of that moment**, baked into a static stylesheet for that document. If the tenant changes their accent color next month, every previously issued PDF keeps its original brand — consistent with the platform's broader rule that posted financial documents are immutable. The live dashboard, by contrast, always reflects the *current* branding. These are intentionally different behaviors for the same underlying field.
- **Forced-colors / high-contrast OS modes.** Under `forced-colors: active` (Windows High Contrast), QAYD does not fight the OS: a `forced-colors: active` media query strips custom backgrounds/shadows on interactive controls and defers to system color keywords (`ButtonText`, `Canvas`, `Highlight`), verified to keep focus rings and disabled states legible rather than invisible.
- **Color is never the only signal.** A financial delta never relies on `--color-financial-negative` alone — it is always paired with a leading `+`/`−` sign and, where space allows, a directional icon, so colorblind users (and grayscale printing) never lose the meaning.
- **Stale branding on a shared device.** On sign-out, `BrandProvider` resets every `--brand-*` variable to the platform default and clears the branding portion of the query cache *before* the login screen for the next user renders — a shared kiosk device must never flash the previous tenant's logo on the next company's login screen.
- **Long bilingual company names in a branded header.** `wordmark_display="logo_and_name"` truncates the company name with an ellipsis at a fixed max-width and exposes the full name via a native `title` attribute/tooltip; the header never wraps to a second line or pushes navigation out of place.
- **Logo aspect ratio.** Tenant logos are constrained to a fixed-height box (`object-fit: contain`), never stretched to fill it. A company with no uploaded logo (fresh `branding = {}`) falls back to a neutral initials monogram rendered on `--color-bg-surface-raised` with `--color-fg-primary` text — never a color hashed from the company name, which could coincidentally fail contrast or clash with the accent.
- **Combinatorial test scope.** Light/dark × LTR/RTL × comfortable/compact × default/custom-brand is a 16–24-way matrix per screen if taken literally. QAYD does not test every screen against the full matrix; the shared component library (`Button`, `Card`, `Table`, `Input`, etc.) is tested against the full matrix once, and individual screens are tested against a risk-based reduced set (their own default combination plus RTL, per that screen doc's own "RTL & Localization" section) on the strength of the component library's guarantee.
- **Server Components cannot call `useTheme()`.** Any server-rendered fragment that would need to *branch logic* on the active theme (not just style differently via CSS) must either defer that fragment to a client boundary or, preferably, render both a light and a dark version in CSS and let `[data-theme]` selectors choose between them — avoiding a client/server theme-detection round trip entirely.
- **Font loading.** `next/font` self-hosts Inter, Inter Tight, and IBM Plex Sans Arabic with `display: "swap"` and metric-matched fallback fonts, so text is visible immediately in a fallback face and reflows minimally once the real face loads — never a blank-text flash.
- **Embedded/iframe contexts.** Where a QAYD surface is embedded in a partner site, its stylesheet is scoped (`:where()`-wrapped root selector) so the host page's global CSS cannot leak in and QAYD's own tokens cannot leak out.

# End of Document
