# Icon System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: ICON_SYSTEM
---

# Purpose

This document is the design-system-level charter for iconography in QAYD — the AI-native, bilingual
(English LTR / Arabic RTL) accounting platform. It exists one level above the frontend implementation
reference in [`../frontend/ICONOGRAPHY.md`](../frontend/ICONOGRAPHY.md): where that document fixes the
exact prop surface of the `Icon` wrapper, the concept-to-glyph map, and the per-component slot table for
the Next.js client, this document states the *system rules* every surface of QAYD obeys — the web client,
the PDF export path, the marketing site, and any future first-party surface — so that "an icon in QAYD"
means the same thing everywhere it appears. It is a specialization of, and never a contradiction to,
[`../frontend/ICONOGRAPHY.md`](../frontend/ICONOGRAPHY.md) and the platform design language in
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md); where an implementation detail and a
system rule appear to disagree, the system rule here is the intent and the frontend doc is the binding
implementation, and the conflict should be raised rather than silently resolved.

QAYD is a finance product before it is a software product. Every screen is a ledger, an approval queue, a
reconciliation worklist, or a number someone will eventually be audited on. In that context an icon is not
decoration — it is the fastest-parsed signal on the screen, faster than a label, faster than a color,
faster than a number. A misjudged icon system costs a Senior Accountant real seconds on every one of the
hundreds of rows they triage in a day; a *tasteless* icon system costs QAYD the "car/fashion brand, not
generic SaaS" positioning that is a stated product requirement. The whole of this document exists to make
one promise enforceable: an icon in QAYD should look like it was drawn by the same hand as every other icon
in QAYD, on every screen, in every language, in every theme.

# The One Icon Set

QAYD uses exactly **one** icon library: [`lucide-react`](https://lucide.dev). No second icon package
(Heroicons, Feather, Font Awesome, Material Symbols, a custom icon font, or any multicolor / duotone /
flat-illustration pack) is ever imported into the product, and **no emoji is ever used as an icon** — not
in chrome, not in status pills, not injected into AI-generated message copy. This is a hard, code-review-
enforced rule, not a preference: mixing icon families, or dropping an emoji in "just for this one screen,"
is the single fastest way to make an editorial interface read as a template.

Why Lucide specifically, restated as system properties:

- **One visual voice.** Every Lucide glyph is drawn on the same 24×24 grid with round caps and round
  joins, a single stroke weight, no filled variants to accidentally mix in — the closest off-the-shelf
  match to QAYD's "near-monochrome ink, one accent, restrained" mandate.
- **`currentColor` by construction.** Every path renders `stroke="currentColor"` with no baked-in fill, so
  color, theme, hover, and disabled states are all inherited from CSS — see [Color](#color--currentcolor).
- **Tree-shakeable, typed, server-renderable.** Each glyph is its own named ES-module export and a plain
  SVG-returning function component; unused glyphs never bundle, every icon ships `LucideIcon` /
  `LucideProps` types, and an icon never forces a route into `"use client"` by itself.
- **Coverage.** Across accounting, banking, tax, payroll, inventory, purchasing, sales, and AI operations,
  Lucide's 1,500+ glyphs cover the overwhelming majority of concepts. The small minority it does not is
  handled by the deliberately-tiny custom set in [Custom Financial Glyphs](#custom-financial-glyphs).

**Version discipline.** `lucide-react` is pinned to an exact version (no `^`/`~`), and QAYD imports only
Lucide's current *shape-first* names (`TriangleAlert`, `CircleAlert`, `OctagonAlert`, `CircleCheck`,
`CircleCheckBig`, `CircleX`), never the deprecated concept-first aliases (`AlertTriangle`, `CheckCircle`,
…) that Lucide still exports for back-compat. A version bump is a deliberate PR that greps the changelog
for renamed or removed exports used anywhere in `components/icons` or `lib/icon-map.ts`.

The concept-to-glyph mapping (which glyph means "Journal Entry," "Bank Reconciliation," "Approval," and so
on across all fifteen AI agents) is owned in full by
[`../frontend/ICONOGRAPHY.md → Semantic Icon Set`](../frontend/ICONOGRAPHY.md) and is not duplicated here.
The system rule is only this: **a concept has exactly one glyph**, reused everywhere it appears; any
engineer introducing an icon for a concept not yet mapped adds the row in the same PR rather than inventing
a private convention.

# Stroke Weight & Sizing

## Stroke weight

Lucide's default `strokeWidth` is `2`. QAYD overrides this platform-wide, applied once in the `Icon`
wrapper's default prop, never passed ad hoc at each call site:

| Rendered size | Stroke width | Rationale |
|---|---|---|
| `xs`–`lg` (14–24px), ~90% of usage | **1.75** | The thinnest weight that still holds cleanly at 1× (non-Retina) density and in the PDF export path; the default 2px reads slightly heavy and toy-like at finance-screen density. |
| `xl`–`2xl` (32–40px), hero / empty-state | **1.5** | SVG stroke is a fixed physical thickness, not proportional — a 1.75px stroke correct at 20px looks bold and cartoonish at 40px unless thinned to preserve the same *visual* weight. |

At the design-principle level, [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md)
summarizes this as "Lucide, 1.5px stroke, line-only"; the 1.75/1.5 split above is that principle made
exact for the two size bands, and is the binding rule. Custom glyphs are drawn at a 2px source stroke and
thinned by the *same* wrapper to the same values, so a custom glyph and a Lucide glyph in the same toolbar
carry identical visual weight (see [Custom Financial Glyphs](#custom-financial-glyphs)).

## Size scale

Six tokens, each landing exactly on Tailwind's 4px base grid (so an icon never sits half a pixel
off-center at 100/125/150% zoom), each mapped to a `size-*` utility that sets width and height together:

| Token | px / rem | Tailwind | Typical usage |
|---|---|---|---|
| `xs` | 14 / 0.875rem | `size-3.5` | Inline meta glyphs beside caption text, dense table micro-badges, sort indicators |
| `sm` | 16 / 1rem | `size-4` | Form-field leading icons, inline status glyphs, dropdown-item icons, `Button size="sm"` |
| `md` | 20 / 1.25rem | `size-5` | **Default.** `Button size="default"`, table row actions, breadcrumb separators |
| `lg` | 24 / 1.5rem | `size-6` | Nav-rail module icons, `Button size="lg"`, section-header icons, Command Palette leading icons |
| `xl` | 32 / 2rem | `size-8` | KPI/stat-card glyph, dialog-header icon, agent avatar glyph |
| `2xl` | 40 / 2.5rem | `size-10` | Empty-state hero icon, onboarding accents |

`md` (20px) is the platform default and is what the wrapper renders when no `size` is passed — an engineer
drops an icon into 90% of contexts without thinking about size, and reaches for the scale only when a
context genuinely departs from body-text density. **No icon usage ever specifies a raw pixel size**
(`size-[18px]`, `w-[22px]`); an in-between need is almost always a container-padding problem, not a sizing
problem.

## Icon-to-text pairing

An icon beside text is sized to optically balance the text's cap-height, which for Lucide's padded 24×24
grid means ~1.0–1.15× the text's font-size in pixels:

| Text context | Font size | Paired icon token |
|---|---|---|
| Caption / meta | 12px | `xs` (14px) |
| Body / table cell | 14px | `sm` (16px) |
| Button label (default) | 14px | `md` (20px) |
| Section heading | 16–18px | `lg` (24px) |
| KPI / stat value | 28–32px | `xl` (32px) |

## Touch and click targets

The glyph and its hit area are separate concerns. WCAG 2.2 SC 2.5.8 (Target Size, Level AA) sets a 24×24
CSS-pixel floor; QAYD's internal bar is stricter where it matters:

| Context | Minimum hit area | Glyph size |
|---|---|---|
| Primary icon-only action (toolbar, mobile nav) | 40×40px | `sm`/`md` |
| Dense desktop table row action | 32×32px | `sm` (falls back to 2.5.8's inline-control exception; the row is also click-to-open + keyboard-reachable) |
| Table header sort indicator | 24×24px | `xs` (the whole header cell is the target) |
| Menu / Command Palette item icon | non-interactive | `sm` (the full row is the target) |

Interactive icon containers are sized on even Tailwind steps so padding divides evenly — a `size-10` (40px)
button around a `size-5` (20px) icon centers with exactly 10px on every side. An odd-pixel container (39px)
is never hand-built around an icon.

# Inline vs. Standalone

An icon in QAYD is used in one of two modes, and the mode decides both its accessibility contract
([Accessibility](#accessibility)) and its color default:

- **Inline (paired with text).** The icon sits beside a visible label that already carries the meaning — a
  `Landmark` before "Bank Accounts," a `NotebookPen` before "New Journal Entry." It is decorative
  (`aria-hidden`), and it takes the *quieter* ink tone by default so a toolbar of five doesn't read as five
  equally shouty CTAs. The gap to the label is **always** a `gap-*` utility on the flex container, never a
  manual margin on the icon, so it stays correct as Arabic and English labels change length and direction.
- **Standalone (the sole content).** The icon *is* the control or the status — an icon-only button, a
  status glyph in a label-less cell. It is meaningful and carries an accessible name on the interactive
  element (see [Accessibility](#accessibility)), and it may legitimately take the accent when it represents
  the one primary action on its surface.

## Pairing icons with text

Three rules keep paired icon+text combinations tidy across both languages:

1. **Fixed gutter in lists.** In a `DropdownMenu` or Command Palette, the leading-icon gutter is a
   fixed-width `w-4` (`shrink-0`) cell regardless of which glyph occupies it, so labels across rows of
   visually different-width glyphs (a wide `Landmark`, a narrow `Percent`) still start at the same
   horizontal position.
2. **Icon survives truncation.** In a truncating menu row (`text-ellipsis`), the leading icon's gutter is
   `shrink-0` so the glyph never compresses or disappears before the text does.
3. **Never icon-only where text fits.** In a `≥768px` status column, the icon is always paired with a
   short visible label ("Posted," "Void") — collapsing to icon-only purely to save a few characters is the
   density-over-clarity trade QAYD's "crisp density without clutter" mandate exists to prevent.

# Color & currentColor

**`currentColor` inheritance is the only coloring mechanism.** Every Lucide glyph and every QAYD custom
glyph renders `stroke="currentColor"` with no fill, so an icon's color is set entirely by its CSS `color`
— inherited from an ancestor or set with a Tailwind text-color utility — never by a `fill`/`stroke` prop
or an inline `style`. This is what makes dark mode, hover, and disabled states "free": a single class
change on a color already used for text repaints every icon instantly, with no separate dark-mode asset and
no flash on theme toggle. A hardcoded light-mode-only class on an icon (`text-neutral-900` instead of
`text-ink-11`) is treated as a bug, not a style nit.

Icons inherit the platform ink and accent tokens defined in
[`../frontend/DESIGN_LANGUAGE.md → Color & Ink`](../frontend/DESIGN_LANGUAGE.md) — a warm-neutral twelve-
step ink scale (`ink-1`…`ink-12`), one disciplined brass accent (`accent`, `accent-subtle`,
`accent-strong`), and four semantic financial states (`positive`, `negative`, `warning`, plus the
ink-only "info"). The icon-relevant usage rules:

| Role | Token | When |
|---|---|---|
| Default icon ink | `text-ink-9` / `text-ink-10` | ~90% of icons — secondary/meta at `ink-9`, title-adjacent/primary at `ink-10` |
| Interactive / selected / AI-touched | `text-accent` | The active nav item, the icon inside the one primary CTA, an AI-provenance mark — never "for visual interest" |
| Financial / workflow state | `text-positive` / `text-negative` / `text-warning` | Only when the icon *is* the state (a posted check, an overdue triangle), always paired with matching text — never color alone |
| Disabled | `opacity-40` | The icon dims with its control; state is never carried by icon color alone |

Two disciplines follow. First, **the accent is rationed** — brass marks exactly two things: the single
primary action on a screen, and anything the AI layer has touched. QAYD introduces **no second "AI" hue**
(no AI-purple, no AI-blue); AI provenance is carried by shape and words (`Sparkles` glyph + "AI"/"Suggested
by {agent}" label + a dashed accent border), all in the *same* brass, so a user never learns a new color to
recognize a machine draft — see [`../frontend/components/AI_WIDGETS.md → AiCardShell`](../frontend/components/AI_WIDGETS.md).
Second, **color is never the sole carrier of state** (WCAG 1.4.1): a posted entry renders a distinct
`CircleCheckBig` glyph *and* the word "Posted," a voided one a distinct `CircleX` glyph *and* "Voided"; a
negative or overdue amount pairs a leading sign or a `TrendingDown` glyph with the number, never a bare red
figure. Every icon stroke also clears WCAG 1.4.11 non-text contrast (3:1) against its background in both
themes; no icon usage applies an *extra* opacity or tint that would push a meaningful glyph below 3:1.

# Custom Financial Glyphs

A custom, hand-drawn glyph enters QAYD only when Lucide genuinely cannot represent a concept the product
uses often, and the bar is deliberately high because every custom icon is a permanent QAYD-owned SVG with
no upstream fixes. "I prefer a different treatment of something Lucide already covers" is never a
justification; every custom-icon PR cites which condition it satisfies, and a reviewer rejects one that
cannot. The sanctioned custom set is small and finance-specific:

| Glyph | Why Lucide is insufficient | Construction |
|---|---|---|
| **Currency / multi-currency badge** | Lucide's `Coins`/`DollarSign`/`Banknote` are US-dollar-coded and none render a KWD/AED/SAR *switcher* | A neutral currency-ring mark; the active ISO code (`KWD`) is real text beside it, never baked into the glyph |
| **`ThreeWayMatch`** | The Purchasing three-way match (`purchase_orders` ↔ `goods_receipts` ↔ `bills`) recurs constantly; stacking `ClipboardList` + `Truck` + `Receipt` inline reads slower and drifts | Three linked nodes on the 24×24 grid |
| **`ReconciliationMatch`** | Bank reconciliation's "this statement line is matched to this ledger entry" is a two-node link with no clean Lucide equivalent (`ListChecks` names the worklist, `ArrowLeftRight` names a transfer) | Two nodes joined by a settled link + tick |
| **Confidence meter** | A *segmented fill* that encodes an AI confidence band by shape, not a single pictograph | Not a Lucide-style glyph — a four-segment track component; owned by [`../frontend/components/AI_WIDGETS.md → ConfidenceMeter`](../frontend/components/AI_WIDGETS.md) |
| **Agent avatars** | Each of the fifteen AI agents needs an identity mark | *Not* a bespoke mascot — a Lucide **domain icon** (the agent's module glyph) centered in a 28px tinted circle; owned by [`../frontend/components/AI_WIDGETS.md → AgentAvatar`](../frontend/components/AI_WIDGETS.md) |

Two of these — the confidence meter and the agent avatars — are the AI layer's own visual vocabulary and
are specified in full in [`../frontend/components/AI_WIDGETS.md`](../frontend/components/AI_WIDGETS.md); the
icon system's only obligation to them is that the agent avatar always reuses a *domain* glyph from the one
Lucide set (never a per-agent illustrated character, never a color-per-agent scheme, both of which the
taste bar forbids and neither of which scales past fifteen agents), and that the confidence meter encodes
its band by fill-level and shape (solid vs. hollow/dashed track), never by a traffic-light hue.

## SVG pipeline

Every custom glyph ships through the same steps so it is indistinguishable in weight from a Lucide glyph
beside it: draw on a 24×24 viewBox at 2px source stroke with round caps/joins and no baked fill; run SVGO
to strip intrinsic `width`/`height` and any hardcoded color while preserving the `viewBox`; codegen a typed
component whose prop contract is identical to Lucide's (`size`, `strokeWidth` default `1.75`,
`aria-hidden="true"` by default) so it is a drop-in anywhere the `Icon` wrapper expects a Lucide-shaped
component; barrel-export it so `lib/icon-map.ts` imports custom and Lucide glyphs through one statement
shape; and gate merge on design review against the internal icon-gallery route. The full pipeline (SVGO
config, codegen script, gallery) is specified in
[`../frontend/ICONOGRAPHY.md → Custom/Brand Icons`](../frontend/ICONOGRAPHY.md); the load-bearing shape:

```tsx
// components/icons/custom/reconciliation-match.tsx
// Generated from reconciliation-match.svg — do not hand-edit the <path> data.
import { forwardRef } from "react";
import type { LucideProps } from "lucide-react";

export const ReconciliationMatch = forwardRef<SVGSVGElement, LucideProps>(
  ({ size = 24, strokeWidth = 1.75, className, ...props }, ref) => (
    <svg
      ref={ref}
      xmlns="http://www.w3.org/2000/svg"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
      {...props}
    >
      {/* statement-line node ↔ ledger-entry node, joined by a settled link + tick */}
      <circle cx="6" cy="7" r="2.5" />
      <circle cx="18" cy="7" r="2.5" />
      <path d="M8.5 7 H15.5" />
      <path d="M8 16.5 l2.5 2.5 L16 13.5" />
    </svg>
  ),
);
ReconciliationMatch.displayName = "ReconciliationMatch";
```

Note the prop contract identical to every Lucide icon — `size`, `strokeWidth` default `1.75`, decorative
`aria-hidden` — which is exactly what lets the `Icon` wrapper treat custom and baseline glyphs
interchangeably.

# RTL Mirroring Policy

QAYD renders as a true bilingual product with `dir="rtl"` set whenever Arabic is active — Arabic is a
first language, not a mirrored afterthought. Icons split into two groups under that flip, and getting the
grouping wrong is a recurring, easy-to-miss bug class in either direction: mirroring a glyph that shouldn't
inverts its meaning (a `TrendingUp` mirrored into a visual "down" reads as the *opposite* of the data);
failing to mirror one that should reads as sloppy, untranslated software.

**Mechanism.** QAYD keeps one icon set — never two locale-specific sets — and flips only the directional
glyphs via a single `mirrorRtl` prop on the wrapper that applies Tailwind's `rtl:-scale-x-100` (keyed off
the ancestor `dir`, no manual locale check). Spacing around icons uses logical-property utilities
(`ms-*`/`me-*`, `ps-*`/`pe-*`, `start-*`/`end-*`) exclusively, so a badge at `-end-1 -top-1` sits top-right
in English and top-left in Arabic with zero `dir === 'rtl'` conditionals.

**The default is not to mirror.** `mirrorRtl` defaults to `false`; an engineer adding a directional icon
consults the policy table (and extends it in the same PR if the glyph is genuinely new) rather than
guessing per screen.

| Mirrors in RTL (directional / reading-order) | Does **not** mirror in RTL (value / object / symmetric) |
|---|---|
| `ChevronLeft` / `ChevronRight`, `ArrowLeft` / `ArrowRight` (pagination, back/forward, breadcrumb) | `TrendingUp` / `TrendingDown` — encode a value's direction, mirroring would falsify data |
| `PanelLeft` / `PanelRight` (sidebar toggle — the sidebar itself relocates edges in RTL) | `ArrowUpRight` / `ArrowDownRight` (external-link, stat-delta) — screen-relative "up and out" |
| `IndentIncrease` / `IndentDecrease` (text-direction-relative by definition) | Status/alert glyphs (`CircleCheckBig`, `TriangleAlert`, `OctagonAlert`, `CircleX`) — symmetric |
| `Undo2` / `Redo2`, `CornerUpLeft` / reply glyphs, `LogOut` | Domain/object glyphs (`Boxes`, `Landmark`, `Users`, `HandCoins`, `Warehouse`, currency badge) |
| — | Media transport (`Play`, `Pause`, `SkipForward`); brand/logomark; checkmark; plus/minus |

The full mechanism, the `mirrorRtl` prop, and the logical-property enforcement are owned by
[`../frontend/ICONOGRAPHY.md → RTL-Aware Icons`](../frontend/ICONOGRAPHY.md) and
[`../frontend/DESIGN_LANGUAGE.md → Spacing & Grid`](../frontend/DESIGN_LANGUAGE.md); this table is the
system-level policy those implement.

# Accessibility

**Decorative vs. meaningful is decided at write time, and the safe default is free.** Every icon usage is
one of two categories, and the `Icon` wrapper is built so the safe choice requires no extra effort:

- **Decorative** — the icon sits beside text that already says the same thing. It carries
  `aria-hidden="true"` and is invisible to assistive technology. This is the wrapper's **default** when no
  `label` is supplied; an engineer does nothing to get it right.
- **Meaningful** — the icon is the *only* conveyor of information (an icon-only button, a label-less status
  glyph). It requires an explicit `label`, which flips the SVG to `role="img"` and removes `aria-hidden`;
  for an icon-only control, the accessible name lives on the interactive **element** (`aria-label` on the
  `<button>`), not on the nested SVG.

```tsx
{/* Decorative — the label already says everything */}
<Button variant="ghost"><Icon icon={Landmark} /> Bank Accounts</Button>

{/* Meaningful — the icon IS the content; the accessible name is on the button */}
<Button variant="ghost" size="icon" aria-label={t("banking.reconcile.match")}>
  <Icon icon={ReconciliationMatch} />
</Button>
```

**Enforcement, not just convention.** An ESLint rule (`eslint-plugin-jsx-a11y` extended) fails CI on any
button-like primitive with `size="icon"` that lacks an `aria-label`, `aria-labelledby`, or a non-empty
visible text child — a build-breaking check, because an unnamed icon-only button is the most common
accessibility regression in icon-heavy UIs and the cheapest to catch mechanically.

Three further rules:

- **Tooltips reinforce, never replace.** An icon-only button's tooltip uses the *same string* as its
  `aria-label`, so sighted and screen-reader users get identical information and a translator localizes one
  string per action.
- **Keyboard-first.** Every icon-only interactive element is a real `<button>` or `<a>`, never a bare
  `<svg onClick>` or a `<div role="button">`; focus is visible via the accent ring with a non-zero offset,
  and the entire padded hit area — not just the glyph's bounding box — is focusable and clickable.
- **Reduced motion.** Any icon-attached animation (a `Loader2` spin, a "live" AI-status pulse) respects
  `prefers-reduced-motion`: `animate-spin` is replaced with a static `Loader2` plus the word "Loading…,"
  never a bare spinning icon and never motion with no static equivalent.

# The No-Emoji Rule

QAYD uses **no emoji as iconography, anywhere** — no celebration, check-mark, money-bag, or bar-chart
emoji as UI icons (use the Lucide `PartyPopper`, `CircleCheckBig`, `Banknote`, `BarChart3` glyphs where a
mark is genuinely needed), not in success
toasts, not in empty states, and never injected into AI-generated message content rendered inside the
product. An emoji is a second, uncontrolled, platform-rendered icon set with its own color, weight, and
cultural connotation; it is the antithesis of the single-hand, near-monochrome, editorial voice QAYD
commits to, and it reads as consumer-app casual in a product trusted with a company's books. The AI layer
is held to this too: an agent's response text never includes emoji, even informally, in any product
surface. Where a product wants "warmth," QAYD gets it from precise copy and calm motion, not from a
decorative glyph.

# Do & Don't

| Do | Don't |
|---|---|
| Use exactly one library (`lucide-react` + the tiny custom set) everywhere | Mix in a second icon package, or use emoji as an icon, "just for this screen" |
| Keep every icon monochrome and `currentColor`-driven | Hardcode a hex/rgb `fill`/`stroke` on any icon instance |
| Default icons to `ink-9`/`ink-10`; spend `accent` on the one primary action and AI-touched marks | Color an icon with the accent for "visual interest" |
| Pair color with shape and text for any state | Encode posted/void/overdue in color alone |
| Reuse one glyph per concept everywhere | Let three screens each invent an icon for "Journal Entry" |
| Add a custom glyph only against a justified, cited condition | Draw a custom glyph because "I like this style better" |
| Give every icon-only button a real `aria-label` and let CI enforce it | Ship an icon-only button with no accessible name |
| Mirror only reading-direction glyphs via `mirrorRtl`; use logical `ms-*`/`end-*` spacing | Mirror `TrendingUp`/`TrendingDown` or symmetric status glyphs; hardcode `left-*`/`right-*` |
| Size on even Tailwind steps from the six-token scale | Build a 39px container or a `size-[18px]` one-off |
| Represent each AI agent with a Lucide domain glyph in a tinted circle | Give an agent a mascot face, a photo, or a color-per-agent scheme |

# End of Document
