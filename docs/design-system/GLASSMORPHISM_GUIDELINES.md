# Glassmorphism Guidelines — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: GLASSMORPHISM_GUIDELINES
---

# Purpose

This document is the design-system charter for frosted-glass and translucency in QAYD — the AI-native,
bilingual (English LTR / Arabic RTL) accounting platform. It governs one specific, deliberately rare visual
treatment: a translucent, background-blurred surface (`surface-glass`) used to signal that a panel floats
*above* the whole application rather than belonging to the current page. It is a specialization of
[`../frontend/DESIGN_LANGUAGE.md → Elevation & Surfaces`](../frontend/DESIGN_LANGUAGE.md), which defines the
`surface-glass` token and reserves it, and of
[`../frontend/components/CARDS.md`](../frontend/components/CARDS.md), which bans glass on an ordinary card.
Where a rule here and a frontend doc appear to disagree, this document states the intent and the frontend
doc is the binding implementation; the conflict should be raised rather than silently resolved.

Glass is the one place QAYD's near-monochrome, hairline-bordered, editorial-flat surface system permits an
effect that could easily tip into gimmick. Frosted glass reads as *premium* precisely because it is
unusual: a UI that glasses every panel loses the effect entirely and gains a real performance cost, since
`backdrop-filter: blur()` is not free on the back-office desktops that make up much of QAYD's audience. The
whole of this document exists to keep glass rare, purposeful, legible, and accessible — a signal that a
surface is conceptually above the app, never a decorative sheen sprayed across the product. Legibility of
numbers is the non-negotiable constraint: QAYD is a finance product, and a translucent surface that makes a
figure even slightly harder to read has failed regardless of how good it looks in a screenshot.

# Where Glass Is Permitted — and Where It Is Banned

QAYD builds elevation primarily from **hairline borders**, not shadow depth or blur — an editorial-flat
approach closer to Linear or Stripe than to a Material stacked-shadow system. Against that baseline, glass
is the exception, allowed on exactly the surfaces that float above the entire app and nowhere else.

| Surface | Glass? | Why |
|---|---|---|
| **Command Palette (⌘K)** | **Yes** | Floats conceptually above the whole app; belongs to no single page. The canonical glass surface. |
| **AI Command Center overlay** | **Yes** | The AI layer's app-wide surface, floating above the current route. The second canonical glass surface. |
| **Landing / marketing chapter cards** | **Yes, sparingly** | On the *unauthenticated* marketing site only, a frosted "chapter card" is an editorial device, not app chrome — permitted at low frequency, never carrying live financial data. |
| Ordinary cards, panels, sidebar | **No** | Elevation is a hairline `ink-6` border + `shadow-xs`, never glass — see [`../frontend/components/CARDS.md`](../frontend/components/CARDS.md). |
| Dropdowns, popovers, date pickers | **No** | `surface-2`: opaque `ink-1`/`ink-3` + border + `shadow-sm`. A menu over a table must stay fully legible. |
| Modals & dialogs | **No** | `surface-3`: opaque surface + `shadow-lg` over an `ink-12`/50% scrim. A dialog's content, often a form with amounts, is opaque. |
| **Dense data surfaces** — ledgers, statements, tables, KPI tiles, any figure-bearing surface | **Banned, absolutely** | Legibility of numbers comes first; a translucent surface behind a column of amounts, or a blurred layer bleeding a table's own rows through it, is never acceptable. |

The single most important line: **glass never touches a financial number.** The two sanctioned in-app glass
surfaces (Command Palette, AI Command Center) are navigation/AI surfaces, not data tables, and even they
place their *own* content on a sufficiently opaque layer. A ledger, a Trial Balance, a P&L, a reconciliation
grid, or a stat tile showing a real figure is never rendered on `surface-glass`, and no glass panel is ever
laid over one such that the figures behind it show through.

# The Token & CSS Recipe

Glass is one token — `surface-glass` — defined in
[`../frontend/DESIGN_LANGUAGE.md → Elevation & Surfaces`](../frontend/DESIGN_LANGUAGE.md), never hand-rolled
per surface. Its four ingredients:

| Ingredient | Light | Dark | Note |
|---|---|---|---|
| Background | `ink-1` @ **72%** opacity | `ink-3` @ ~**80%** opacity | High enough opacity that content on top clears AA (see [Contrast](#contrast--legibility-over-translucency)) |
| Backdrop filter | `blur(24px)` | `blur(24px)` | The frost; the sole use of `backdrop-filter` in the product |
| Border | 1px `ink-6` @ **60%** | 1px `ink-6` @ 60% | A hairline still does the elevation work; the border is softened, not removed |
| Shadow | `shadow-md` | `shadow-md` @ half opacity | Lifts the floating panel off the app beneath it |

```css
/* app/globals.css — the ONE definition; components reference the utility, never these values */
.surface-glass {
  background-color: hsl(var(--qayd-ink-1) / 0.72);
  -webkit-backdrop-filter: blur(24px);
  backdrop-filter: blur(24px);
  border: 1px solid hsl(var(--qayd-ink-6) / 0.60);
  box-shadow: var(--shadow-md);
  border-radius: var(--radius-xl); /* 10px — the modal/floating-surface radius ceiling */
}

.dark .surface-glass {
  background-color: hsl(var(--qayd-ink-3) / 0.80);
  box-shadow: var(--shadow-md-dark); /* dark shadows run at half the light-mode alpha */
}

/* Graceful degradation: browsers without backdrop-filter get an opaque surface, never a see-through one */
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
  .surface-glass {
    background-color: hsl(var(--qayd-ink-1)); /* fully opaque fallback — legibility preserved */
  }
  .dark .surface-glass {
    background-color: hsl(var(--qayd-ink-3));
  }
}
```

```tsx
// components/ui/command-palette.tsx (excerpt) — glass applied via the token utility, never inline values
export function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogOverlay className="bg-ink-12/40" /> {/* a light scrim, not itself blurred */}
      <DialogContent
        className="surface-glass fixed start-1/2 top-24 z-command w-[min(640px,92vw)] -translate-x-1/2 p-0"
        aria-label={t('command.title')}
      >
        <CommandInput placeholder={t('command.placeholder')} />
        <CommandList>{/* results render on the glass, at AA-clearing opacity */}</CommandList>
      </DialogContent>
    </Dialog>
  );
}
```

Two disciplines are load-bearing. First, **the opacity floor exists for legibility, not aesthetics**: the
72% (light) / 80% (dark) background is the *minimum* opacity at which the surface's own text and any accent
element clear AA against it. A designer wanting "more glass" by dropping the opacity is trading away
legibility, and the token does not permit it. Second, **glass is a token, not a per-component recipe** —
no component sets its own `backdrop-filter`, `background` opacity, or blur radius; every glass surface
references `.surface-glass` so the two-surface budget and the fallback stay centrally enforced.

## The AI Command Center surface

The second sanctioned glass surface is the AI Command Center — the app-wide overlay through which the AI
layer's insights, risks, and pending proposals reach the user from any route. It qualifies for glass for the
same reason the Command Palette does: it belongs to no single page and floats above the whole app. It sits
at `z-command` (60), the same layer as the palette, above modals and toasts but below tooltips.

```tsx
// components/ai/command-center-overlay.tsx (excerpt)
export function CommandCenterOverlay({ open, onOpenChange }: CommandCenterProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetOverlay className="bg-ink-12/40" /> {/* plain scrim — never itself blurred */}
      <SheetContent
        side="end"                                {/* inline-end: right in LTR, left in RTL */}
        className="surface-glass z-command w-[min(420px,92vw)] p-0"
        aria-label={t('ai.commandCenter.title')}
      >
        {/* Insight/Risk/Proposal cards render on their OWN opaque AiCardShell surfaces,
            never directly on the glass — a card carrying a figure is never translucent. */}
        <CommandCenterFeed />
      </SheetContent>
    </Sheet>
  );
}
```

Note the pattern that keeps glass honest: the overlay *frame* is glass, but the AI cards inside it — which
carry confidence scores, financial exposure figures, and proposal amounts — render on their own opaque
`AiCardShell` surfaces (see
[`../frontend/components/AI_WIDGETS.md → AiCardShell`](../frontend/components/AI_WIDGETS.md)), never directly
on the translucent layer. The glass says "this floats above the app"; the opaque cards keep every number
maximally legible. This composition — glass frame, opaque data cards — is the template any future glass
surface must follow.

## Layering & z-index

Glass surfaces sit near the top of the z-stack from
[`../frontend/DESIGN_LANGUAGE.md → Z-index scale`](../frontend/DESIGN_LANGUAGE.md), and the ordering matters
for the "one blur layer per moment" rule:

| Layer | `z-` token | Value | Glass? |
|---|---|---|---|
| Dropdown / popover | `z-dropdown` | 20 | No — opaque `surface-2` |
| Modal scrim / content | `z-overlay` / `z-modal` | 30 / 40 | No — opaque `surface-3` |
| Toast | `z-toast` | 50 | No |
| **Command palette / AI Command Center** | `z-command` | **60** | **Yes — the only glass** |
| Tooltip | `z-tooltip` | 70 | No — always opaque, above everything |

Because both glass surfaces share `z-command` and are mutually exclusive (opening one closes the other),
two glass layers never coexist, so the single-blur-layer budget is structurally guaranteed rather than left
to discipline.

# Performance Cost & Fallbacks

`backdrop-filter: blur()` is one of the more expensive CSS effects: the browser must sample and blur the
composited layers behind the element on every paint, and a large or frequently-repainted blurred region can
drop frames on integrated GPUs and low-end back-office desktops — exactly QAYD's primary hardware. The cost
model shapes the rules:

- **Two in-app glass surfaces, both transient.** The Command Palette and the AI Command Center are the only
  glass in the authenticated app, and both are *ephemeral overlays* — open on demand, closed most of the
  time — so the blur is composited briefly, not held every frame of a working session. Glass is never
  applied to a persistent surface (a sidebar, a sticky header, a card), where the blur cost would be paid
  continuously.
- **Bounded size.** A glass surface is a bounded floating panel (≤640px wide command palette, a fixed-width
  AI overlay), never a full-viewport blurred layer — a full-screen `backdrop-filter` is the worst case and
  is never used.
- **`@supports` fallback to opaque.** Any browser without `backdrop-filter` gets a fully **opaque**
  `surface-glass` (the CSS above) — never a see-through, unblurred surface that would leave content
  illegibly overlapping the app beneath. Losing the frost is cosmetic; losing legibility is not, so the
  fallback trades the effect away and keeps the readability.
- **Scrim, not double-blur.** The overlay scrim beneath a glass panel is a plain semi-opaque `ink-12` wash,
  never itself blurred — one `backdrop-filter` layer per moment, not two stacked.
- **No animated blur.** The blur *radius* is never animated (animating `backdrop-filter` forces a re-blur
  every frame); a glass panel enters with the standard opacity/scale transition on an already-composited
  blur, respecting `prefers-reduced-motion` like every other surface.

# Light vs. Dark Glass

Glass is calibrated per theme, not inverted:

- **Light glass** frosts a warm near-white (`ink-1` @ 72%) so the surface reads as pale, papery, and lifted
  — consistent with the brand's ink-and-paper metaphor. The softened `ink-6` border still carries the
  elevation.
- **Dark glass** frosts a warm dark surface (`ink-3` @ ~80%, a *higher* opacity than light) because a dark
  translucent layer needs more body to keep its own content legible against the busier, lower-contrast dark
  canvas showing through, and because elevation in dark mode reads by getting *lighter* — a dark glass panel
  is lighter than the dark canvas it floats over, matching how physical light and professional dark UIs
  behave.
- **Warmth carries through both.** Neither theme's glass drifts to a cool blue-black; the same warm-neutral
  ink underlies both, so glass never reads as the "generic AI tool" blue the rest of the palette
  deliberately avoids.
- **Shadow at half alpha in dark.** `shadow-md` runs at roughly half its light-mode opacity under `.dark`,
  since a soft shadow reads as too heavy against a dark background with less ambient contrast to sit inside.

# Contrast & Legibility Over Translucency

Legibility is the constraint every other glass decision bends to.

- **Content on glass clears AA.** Any text or meaningful icon rendered *on* a glass surface must clear its
  normal contrast target (4.5:1 body text, 3:1 non-text / large) against the surface's *effective* color —
  which, because the background is only 72–80% opaque, is verified against the worst-case blend of the
  surface tint over a plausibly-busy backdrop, not against a clean opaque swatch. The opacity floor in the
  token exists precisely so this holds; a glass surface is never made *more* transparent in a way that
  drops its content below AA.
- **Glass never carries a number.** The absolute rule from
  [Where Glass Is Permitted](#where-glass-is-permitted--and-where-it-is-banned) is a contrast rule at heart:
  a financial figure demands maximum legibility, and a translucent surface can never guarantee it against an
  arbitrary backdrop — so figures live on opaque surfaces, always.
- **The border does real work.** The hairline `ink-6`/60% border is not decorative; it is what visually
  separates the glass panel's edge from whatever shows through behind it, and it must stay visible in both
  themes. Removing the border to make glass "cleaner" makes the panel's bounds ambiguous and is not allowed.
- **Focus rings stay full-strength.** The accent focus ring on a control *inside* a glass panel renders at
  full opacity (never inheriting the surface's translucency), so keyboard focus is always unmistakable on
  glass.

# Reduced-Transparency Accessibility

Some users request reduced transparency at the OS level (macOS "Reduce transparency," Windows equivalents),
exposed to the web via `@media (prefers-reduced-transparency: reduce)`. QAYD honors it: the request means
"translucency is uncomfortable or illegible for me," and the correct response is to drop the effect while
keeping the surface and its content fully intact.

```css
@media (prefers-reduced-transparency: reduce) {
  .surface-glass {
    background-color: hsl(var(--qayd-ink-1));   /* fully opaque */
    -webkit-backdrop-filter: none;
    backdrop-filter: none;                       /* no blur — the effect is removed, the panel remains */
  }
  .dark .surface-glass {
    background-color: hsl(var(--qayd-ink-3));
  }
}
```

The panel still opens, still floats (border + shadow keep the elevation), and its content is unchanged — the
state and the meaning are never removed, only the translucency, exactly as reduced-*motion* removes the
animation of a change but never the change itself. This is treated as a correctness property of the glass
token, not a per-surface responsibility: because both glass surfaces reference `.surface-glass`, honoring
the preference is centralized in one place.

# Restraint Rules — Keeping Glass Editorial, Not Gimmicky

Glass earns its premium read only by being rare. The rules that keep it that way:

- **Two in-app surfaces, full stop.** The Command Palette and the AI Command Center are the only glass in
  the authenticated product. A new glass surface is not a styling choice a screen makes on its own; it is a
  change to this document, design-reviewed, justified by the same "floats above the entire app" test the
  two canonical surfaces pass. A card, a sidebar, a header, a toast, a tooltip, a KPI tile — none are ever
  glassed.
- **Never on data.** Restated because it is the rule most likely to be quietly broken for a "nice" hero
  tile: no figure-bearing surface is ever translucent or blurred.
- **One accent still applies.** A glass surface obeys the same one-accent discipline as every other surface
  — brass marks the one primary action and AI-touched elements, nothing is tinted "for depth," and the
  glass itself introduces no new hue.
- **No stacked glass.** Two glass surfaces are never layered (a glass popover over the glass command
  palette); the second surface is opaque. One frosted layer per moment.
- **Marketing glass stays low-frequency.** On the unauthenticated marketing site, a frosted chapter card is
  an editorial accent used sparingly against the calm background — not every card, never carrying a live
  figure, and it inherits the same opacity floor and reduced-transparency handling as the app token.
- **If in doubt, opaque.** The default surface in QAYD is a hairline-bordered opaque one; glass is the
  considered exception. When a surface's "should this be glass?" is genuinely uncertain, the answer is no.

| Do | Don't |
|---|---|
| Reserve glass for the Command Palette and AI Command Center (and sparse marketing chapter cards) | Glass a card, sidebar, header, modal, toast, or KPI tile |
| Apply glass only via the `surface-glass` token | Hand-roll `backdrop-filter`/opacity per component |
| Keep the background at the 72%/80% opacity floor so content clears AA | Drop opacity "for more glass" and sink text below AA |
| Fall back to a fully opaque surface without `backdrop-filter` and under reduced-transparency | Leave a see-through, unblurred surface as the fallback |
| Keep glass on bounded, transient overlays | Blur a full-viewport or a persistent surface |
| Let a financial figure live only on an opaque surface | Render a number on, or bleed a table through, a glass layer |
| Keep the softened hairline border visible in both themes | Remove the border to make glass "cleaner" |

# Edge Cases

- **Glass over a data-dense route.** When the Command Palette or AI Command Center opens over a ledger or
  Trial Balance, the blur is *behind* the panel and the panel's own content is on its opaque layer — the
  figures behind the glass are intentionally softened into an unreadable frost, which is correct: they are
  context, not content, in that moment. This is the *only* sanctioned way a financial surface ever sits
  behind glass, and it is fine precisely because nothing on the glass depends on reading them.
- **Reduced transparency + reduced motion together.** A user requesting both gets an opaque, unblurred,
  non-animated panel that still opens and closes and still carries every card and control — both preferences
  are honored independently through the centralized token and the standard motion helper, with no special
  per-surface branching.
- **No `backdrop-filter` support.** Older or restricted engines fall to the `@supports` opaque surface;
  the panel loses its frost but stays fully legible and fully functional. Glass is always an enhancement,
  never a dependency.
- **Print / PDF export.** Glass surfaces are app-chrome overlays that never appear in the Reports export
  path; the PDF pipeline renders opaque, high-contrast surfaces only, so there is no translucent surface to
  degrade in a grayscale export.
- **White-label / company theming.** A company may customize the accent tint, but never the `surface-glass`
  opacity, blur, or the ink tokens underneath it — a multi-tenancy guardrail (matching the icon and color
  systems) that exists so no tenant configuration can push content on glass below AA or make the two glass
  surfaces inconsistent.
- **A screen "wants" glass for a hero tile.** The answer is always no — a KPI or hero tile carries a figure,
  and figures never sit on glass. The visual lift a designer is reaching for is delivered by the hairline
  border + `shadow-xs` elevation system, not by translucency. Introducing a third glass surface is a change
  to this document, design-reviewed against the "floats above the entire app" test, never a per-screen call.

# Governance

Glass has exactly one token, two sanctioned in-app surfaces, and a small marketing exception, and that
scarcity is the point. The rules are enforced, not merely documented:

- **Token-only.** A lint check flags any raw `backdrop-filter` / `backdrop-blur-*` utility or any
  `bg-*/opacity` translucent surface outside the `.surface-glass` definition, so no component can quietly
  introduce a third glass surface.
- **Design-reviewed additions.** A new glass surface is a PR against *this* document that argues the surface
  passes the "floats above the entire app" test the Command Palette and AI Command Center pass; a reviewer
  rejects one that is really just a card wanting a sheen.
- **The default is opaque.** QAYD's baseline surface is a hairline-bordered, opaque one. Glass is the
  considered exception, and when "should this be glass?" is genuinely uncertain, the answer is no — which is
  exactly how it stays rare enough to keep reading as premium rather than gimmicky.

# End of Document
