# Illustration System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: ILLUSTRATION_SYSTEM
---

# Purpose

This document is the design-system charter for illustration and empty-state art in QAYD — the AI-native,
bilingual (English LTR / Arabic RTL) accounting platform. It governs the one narrow place QAYD allows a
drawn graphic that is not a functional icon: the quiet line-abstraction that anchors an empty region, an
onboarding step, or an error page. It is a specialization of
[`../frontend/DESIGN_LANGUAGE.md → Imagery & Illustration stance`](../frontend/DESIGN_LANGUAGE.md) and the
binding contract for the illustration slot cross-links directly into
[`../frontend/components/EMPTY_STATES.md`](../frontend/components/EMPTY_STATES.md), which owns the
`<EmptyState>` component these graphics live inside. Where a rule here and the frontend component doc appear
to disagree, this document states the intent and the component doc is the binding implementation; the
conflict should be raised rather than silently resolved.

The governing fact is that QAYD carries **no photography and no illustration as decoration**. There is no
stock imagery of people in blazers, no isometric 3D coins and charts, no gradient-mesh blobs behind a hero,
no cartoon mascot for the AI assistant, no illustrated character reacting to an empty screen. This is a
direct extension of the platform's first design principle — *ink before color* — restated for graphics: if
the interface needs a picture to feel finished, the typography and spacing have not done their job yet. The
illustration system therefore exists mostly to say **when a graphic is allowed at all**, to keep the tiny
sanctioned set on-brand, and to make sure every graphic degrades gracefully to text for the users and
export paths that never see it. A financial product earns trust by looking precise and unhurried; a mascot
waving at an empty ledger spends that trust in the first three seconds.

# The Style: Restrained Editorial Line-Work

QAYD's one sanctioned illustration style is a single **line-based, monochrome-with-one-accent** abstraction,
drawn to look like it came from the same hand as the icon set and the wordmark — precise, weighted,
unhurried. The concrete parameters:

| Attribute | Rule |
|---|---|
| Technique | Stroke-only line drawing. No fills except the rare single solid dot already used sparingly in the icon set. |
| Stroke weight | **1.5px** at the source viewBox (matching the empty-state glyph stroke and the hero-size icon stroke) — never a variable-width "hand-drawn" stroke. |
| Color | The line renders in `ink-7` (a hairline ink tone, not full-emphasis ink) via `currentColor`; **at most one** focal element may take the brass `accent`. Never a second hue, never a gradient, never a tint fill. |
| Subject | A quiet object metaphor from the brand's own world — a stack of documents, a ruled page, an empty tray, a bound ledger. Never a person, a face, a creature, or a scene. |
| Complexity | Deliberately minimal — a suggestion of an object, not a rendered illustration. It is a visual anchor, not the message. |
| Motion | Static. Illustrations do not animate; the only motion near them is the standard mount transition of the `<EmptyState>` container. |

The brand references are the objects a serious ledger has always been made of — ruled paper, brass
fittings, a bound book of record — translated into screen-native line and space, never into skeuomorphic
texture. QAYD never literally renders a leather grain or a pen nib; it draws the *idea* of a ruled page at
1.5px in a hairline ink, and lets the copy carry the meaning. This is the same discipline the AI layer
follows: the assistant is represented by a geometric mark (a tick inside a circle, echoing a ledger
tick-mark), never a face — see [`../frontend/DESIGN_LANGUAGE.md → Imagery & Illustration stance`](../frontend/DESIGN_LANGUAGE.md).

# When Illustration Is Used — and When It Is Not

Illustration appears in exactly three contexts, and in each it is optional: a purely textual treatment is
always valid and often better.

| Context | Role of the graphic | Cross-reference |
|---|---|---|
| **Empty states** | A quiet anchor above the title/description/action of an empty region — an empty ledger, a reconciliation queue with nothing unmatched, a brand-new company's Chart of Accounts | [`../frontend/components/EMPTY_STATES.md`](../frontend/components/EMPTY_STATES.md) |
| **Onboarding** | A calm accent on a first-run/setup step, at the larger `2xl` size, never a full illustrated scene | [`../frontend/components/EMPTY_STATES.md → size="page"`](../frontend/components/EMPTY_STATES.md) |
| **Error pages** | A single line abstraction on a route-level error/404/500 or a maintenance page — reassuring, not alarming | [`../frontend/components/ERROR_STATES.md`](../frontend/components/ERROR_STATES.md) |

Where illustration is **banned**: dense data surfaces (ledgers, statements, tables, dashboards with real
figures) carry no decorative graphic at all — the numbers are the hero and a picture beside them is noise.
Marketing surfaces *outside* the authenticated app may use genuine, specific, unstaged photography (a real
Kuwaiti SME's storefront, a real ledger page) but never generic stock; **inside** the product there is no
photography whatsoever. And no context — empty, onboarding, or error — ever uses a multi-color scene, an
illustrated character, or an emoji standing in for art.

## The empty-state graphic in context

The illustration slot is the top element of an `<EmptyState>`, a vertically-stacked, centered composition;
the title (`display-sm` General Sans), description (`text-sm` Inter), and at-most-one primary action carry
the communicative weight beneath it. The graphic is optional — a first-run state benefits from a quiet
anchor, a `filtered`-to-zero state usually does not — and it is **always decorative** (`aria-hidden`),
repeating nothing the text does not already say. The four semantic empty *kinds*
(`first_run` / `filtered` / `permission_restricted` / `error_adjacent`), the copy register for each, and
the permission-gated action policy are owned by
[`../frontend/components/EMPTY_STATES.md`](../frontend/components/EMPTY_STATES.md); the illustration system
owns only what may fill the slot.

# The Sanctioned Set

The custom line abstractions are a deliberately tiny library — three at launch — so they read as a
considered set rather than a grab-bag. Each is a stroke-only SVG on the 24×24 grid, rendered at the
empty-state size (24px default, 20px inline, 40px on a route-level `page` empty), recoloring automatically
with the ink token because it is `currentColor`-driven.

| Name | Subject | Typical use |
|---|---|---|
| `documents` (`DocumentsGlyph`) | A short stack of ruled pages | Generic "no records yet" — invoices, bills, entries lists |
| `ruled-page` (`RuledPageGlyph`) | A single ruled ledger page | Ledger / journal / statement first-run empties |
| `empty-tray` (`EmptyTrayGlyph`) | An open, empty tray | Queues and inboxes — approvals, reconciliation, tasks — with nothing waiting |

The `<EmptyState>` `icon` prop accepts one of exactly three things and nothing else: a Lucide line icon
(the default), one of these named custom abstractions, or `null` (no graphic). It never accepts a raster
image, a multi-color illustration, a character, or an emoji.

```tsx
// A first-run empty ledger with the ruled-page anchor and a permission-gated CTA
<EmptyState
  kind="first_run"
  icon="ruled-page"
  title={t('generalLedger.empty.title')}        // "No posted entries yet"
  description={t('generalLedger.empty.body')}     // "Post your first journal entry to see it appear here."
  primaryAction={{
    label: t('journalEntries.new'),
    href: '/accounting/journal-entries/new',
    permission: 'accounting.journal.create',
  }}
/>
```

Each abstraction is a plain stroke-only SVG whose focal element (if any) can take the accent through a
token, never a baked hex:

```tsx
// components/shared/empty-glyphs.tsx (excerpt)
export function RuledPageGlyph({ className, ...props }: React.SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 48 48"
      fill="none"
      stroke="currentColor"        // recolors with the ink token in light and dark automatically
      strokeWidth={1.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}         // caller passes text-ink-7 (the hairline ink tone)
      aria-hidden="true"           // decorative — the title/description carry the meaning
      {...props}
    >
      <rect x="12" y="7" width="24" height="34" rx="2" />
      {/* ruled lines — a suggestion of a ledger page, not a rendered one */}
      <path d="M17 16 H31 M17 22 H31 M17 28 H31 M17 34 H26" />
    </svg>
  );
}
```

The other two abstractions follow the identical construction — stroke-only, 1.5px, `currentColor`, decorative:

```tsx
// components/shared/empty-glyphs.tsx (excerpt, cont.)
export function DocumentsGlyph({ className, ...props }: React.SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth={1.5}
      strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true" {...props}>
      {/* a short stack of ruled pages, offset — "records, none yet" */}
      <rect x="10" y="12" width="20" height="26" rx="2" />
      <path d="M18 8 h20 v26 M14 20 H26 M14 26 H26 M14 32 H22" />
    </svg>
  );
}

export function EmptyTrayGlyph({ className, ...props }: React.SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth={1.5}
      strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true" {...props}>
      {/* an open, empty tray — "queue clear, nothing waiting on you" */}
      <path d="M10 20 L14 30 H34 L38 20" />
      <path d="M10 20 H18 l2 4 h8 l2-4 H38" />
    </svg>
  );
}
```

Adding a fourth abstraction is a deliberate design-reviewed PR, not an incidental addition — the set stays
small on purpose, and a new one is drawn to the same 1.5px, stroke-only, one-accent-focal spec and merged
only after review against the internal gallery route, exactly like a custom icon
(see [`ICON_SYSTEM.md → Custom Financial Glyphs`](./ICON_SYSTEM.md)).

## Composition anatomy

An illustration is never a free-floating asset; it is the top element of a fixed composition whose spacing
and type are defined once, so every empty/onboarding/error surface reads as the same considered layout:

1. **Graphic slot** — the line abstraction (or a Lucide icon, or nothing), decorative, at the `size`-mapped
   dimension, in `ink-7`.
2. **Title** — a real `<h3>`, `display-sm` (20px, `display-lg` at `page` size) General Sans in `ink-11`, a
   short noun phrase stating what is absent ("No posted entries yet").
3. **Description** — one or two sentences, `text-sm` Inter in `ink-9`, explaining *why* it is empty or what
   populating it takes. Optional, strongly encouraged for first-run.
4. **Action row** — at most one primary `Button variant="default"` (permission-gated) and at most one
   lower-emphasis secondary, in logical order so the primary lands on the inline-end edge in both directions.

The vertical rhythm uses the `--space-lg`/`--space-xl` gaps from
[`../frontend/DESIGN_LANGUAGE.md → Spacing scale`](../frontend/DESIGN_LANGUAGE.md); the whole stack centers,
and nothing in it uses a background wash, border, or shadow — the composition is distinguished by whitespace
and centering, not a card treatment that would read as an error box. The graphic's relationship to the rest
of the composition, and the four semantic `kind`s that drive the copy and action policy, are owned by
[`../frontend/components/EMPTY_STATES.md → Anatomy`](../frontend/components/EMPTY_STATES.md).

## Per-context copy anchoring

Because a graphic is only ever an anchor, the copy beside it does the communicating — and it is authored
per module, never templated, so a generic "No data" never appears. Representative pairings:

| Context | `kind` | Graphic | Title (EN) |
|---|---|---|---|
| Empty ledger | `first_run` | `ruled-page` | "No posted entries yet" |
| No journal entries | `first_run` | `documents` | "No journal entries yet" |
| Reconciliation, nothing unmatched | `first_run` | `empty-tray` | "No statement imported yet for this account" |
| Approvals queue clear | `first_run` (inline) | `empty-tray` | "Nothing waiting on you" |
| AI insights temporarily down | `error_adjacent` | Lucide `Sparkles` | "AI insights are temporarily unavailable" |
| Filtered to zero rows | `filtered` | none (or Lucide `SlidersHorizontal`) | "No rows match your filters" |

These match, verbatim where they overlap, the copy established in the screen specs
([`../frontend/components/EMPTY_STATES.md → Per-module empty copy`](../frontend/components/EMPTY_STATES.md)),
so the same condition never reads differently on two screens.

# Governance & Testing

- **The set is versioned and reviewed like icons.** Every abstraction lives in
  `components/shared/empty-glyphs.tsx`, is registered in the internal icon/illustration gallery route, and
  merges only after explicit design sign-off against that gallery — the same enforcement mechanism as a
  custom icon (see [`ICON_SYSTEM.md → SVG pipeline`](./ICON_SYSTEM.md)). A PR adding an illustration cites
  why the existing set is insufficient; "for variety" is never a justification.
- **Storybook coverage.** `empty-state.stories.tsx` renders the custom-glyph, Lucide-icon, and no-graphic
  variants across the `inline`/`region`/`page` sizes, each inspectable in LTR/RTL × light/dark via the
  global decorator — so a reviewer eyeballs weight and alignment consistency in one place.
- **Accessibility CI.** `axe-core` runs against every empty-state story; a decorative graphic that is not
  `aria-hidden`, a missing heading, or a contrast regression on the description text in either theme fails
  the build.
- **No raster assets in the tree.** A lint check rejects any `.png`/`.jpg`/`.webp`/`.gif` imported into a
  product surface as decoration; the illustration set is SVG-only, which is what keeps light/dark and RTL
  free and the bundle image-free.

# Sizing & Placement

Illustration size follows the `<EmptyState>` `size` prop, which maps to the icon scale from
[`ICON_SYSTEM.md`](./ICON_SYSTEM.md):

| `<EmptyState>` size | Graphic size | Context |
|---|---|---|
| `inline` | 20px (`sm`) | An empty state inside a compact widget or KPI tile — tighter padding, often no description |
| `region` (default) | 24px (`lg`) | A table body or panel interior that resolved to no data |
| `page` | 40px (`2xl`) | A route-level empty screen — a first-run module hub, an onboarding step |

Placement rules:

- **Centered vertical stack.** The graphic sits at the top of the centered composition, above the title,
  with generous whitespace (`--space-lg`/`--space-xl` rhythm) between it and the text. The composition uses
  no background wash, no heavy border, and no shadow — an empty state sits *flat* on its region's own
  surface, distinguished by whitespace and centering, not by a card treatment that would read as an error
  box.
- **Fills the region, not the page.** A region-level graphic occupies exactly the area that would have held
  the data (a table body, a panel interior) while the surrounding chrome — toolbar, filters, header —
  stays rendered and interactive. Only a genuinely empty *route* uses the full-bleed `page` size.
- **One graphic per composition.** An empty state, onboarding step, or error page carries at most one line
  abstraction. Two graphics in one composition is never correct.
- **Never on dense data.** No ledger, statement, table, or figure-bearing dashboard tile carries a
  decorative graphic; the ban is absolute.

# Light & Dark Variants

QAYD's stroke-only stance is precisely what makes light/dark cost nothing here. Because every abstraction is
a `currentColor`-driven SVG with no baked fill, it recolors automatically when the ink tokens remap under
`:root[data-theme="dark"]` — the hairline `ink-7` line lightens to keep the same *relative* contrast against
the darker canvas, and an accent focal element follows the brass accent's dark-mode value — with **no
separate dark asset and no `dark:` variant** on the component. A raster illustration would need a hand-made
dark version (and would still band or halo against the wrong background); the line-drawing simply inherits
the theme. This is the same correctness property the icon system relies on
(see [`ICON_SYSTEM.md → Color & currentColor`](./ICON_SYSTEM.md)) and is documented for empty-state art
specifically in [`../frontend/components/EMPTY_STATES.md → Dark mode`](../frontend/components/EMPTY_STATES.md).

The one discipline: an illustration never hardcodes a light-mode color (`text-neutral-400`, a literal hex);
it references the `ink-7` token (and `accent` for a focal element) so both themes stay correct
automatically. A hardcoded light-only color on an illustration is treated as a bug.

# RTL Considerations

Illustration is almost entirely direction-neutral, which is by design:

- **Centered layout needs no mirroring.** The vertical stack — graphic, title, description, action row —
  centers identically in LTR and RTL; nothing in the composition flips.
- **Object abstractions never mirror.** A stack of documents, a ruled page, an empty tray depicts a static
  object with no inherent left/right meaning, so `documents`/`ruled-page`/`empty-tray` render identically
  in both directions — the same rule the icon system applies to object glyphs
  (see [`ICON_SYSTEM.md → RTL Mirroring Policy`](./ICON_SYSTEM.md)).
- **A directional element, if ever used, flips.** Were an illustration to include a directional arrow
  (rare, and generally to be avoided in favor of an object metaphor), that element would mirror via the
  `[dir="rtl"]` transform, exactly as a directional icon does — but the sanctioned abstractions have no
  such element.
- **Action order mirrors logically.** The empty state's action row uses logical flow, so the primary action
  lands on the inline-end edge in both directions.
- **Arabic copy is first-class.** The title and description render in IBM Plex Sans Arabic with the taller
  Arabic line-height, authored to the professional Gulf-business register as parallel originals — never
  machine-translated. An over-translated empty-state line undermines the exact first impression the empty
  state exists to make. Titles run longer in Arabic and wrap to two lines (`max-w`-bounded) rather than
  truncating.

# Accessibility

- **The graphic is decorative — always.** The illustration slot carries `aria-hidden="true"` and repeats
  nothing the title and description do not already state in text. There is never an illustration whose
  meaning lives only in the picture; a screen-reader user loses nothing by not "seeing" it.
- **Meaning lives in text.** The empty state's title is a real `<h3>` in the region's heading hierarchy, so
  an empty region is a navigable landmark in the document outline, not an anonymous centered `<div>`. The
  container is `role="status"` (`aria-live="polite"`), announced calmly — never `role="alert"`, which is
  reserved for genuine errors.
- **Decorative-alt convention.** Because the graphic is `aria-hidden`, it needs no descriptive `alt`; the
  correct "alt text" for a QAYD illustration is *empty by design*, and the description prose is the
  accessible content. An engineer who feels the need to write an `alt` describing the picture is a signal
  the picture is trying to carry meaning it shouldn't — the fix is to move that meaning into the visible
  description.
- **Contrast is a courtesy, not a floor.** As a decorative element the line abstraction is exempt from the
  text-contrast requirement, but it is kept legible (`ink-7`) against the region surface in both themes
  regardless; `axe-core` runs against every empty-state story and fails the build on a non-`aria-hidden`
  decorative graphic or a contrast regression on the *description text*.
- **Reduced motion.** Illustrations are static, so there is nothing to gate; the only motion near them is
  the `<EmptyState>` container's standard mount transition, which already respects
  `prefers-reduced-motion`.

# On-Brand vs. Off-Brand

The single line-abstraction style is easy to hold if the do/don't is explicit. QAYD rejects, on sight,
anything stocky, cartoonish, or emoji-adjacent.

| Do | Don't |
|---|---|
| Use one stroke-only line abstraction at 1.5px in `ink-7`, one optional accent focal element | Use a multi-color illustration, a gradient, or a filled scene |
| Draw a quiet object metaphor from the ledger's own world (documents, ruled page, tray) | Draw a person, a face, a creature, or a mascot — including for the AI assistant |
| Let the title and description carry the meaning; keep the graphic a quiet anchor | Make the graphic the message, or add copy explaining the picture |
| Keep the sanctioned set tiny and design-reviewed | Add a new illustration per screen or per feature "for variety" |
| Reserve illustration for empty states, onboarding, and error pages | Put a decorative graphic on a ledger, statement, table, or figure-bearing tile |
| Rely on `currentColor` so light/dark and RTL come free | Ship a raster asset needing a hand-made dark variant |
| Keep every graphic `aria-hidden`, meaning in text | Encode meaning only a sighted user can recover from the picture |
| Use genuine, specific photography only on *marketing* surfaces, never inside the app | Use stock photos of professionals, isometric coin art, or gradient-mesh blobs anywhere |
| Represent the AI assistant with a geometric ledger-tick mark | Give the assistant an illustrated character, a robot face, or an emoji |

The test for any proposed graphic is the platform's own: would a CFO reading this in a board pack find the
product precise and calm, or would a waving mascot beside an empty ledger make them trust it less. If the
answer is the latter, the graphic is cut and the copy does the work.

# End of Document
