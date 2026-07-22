# ADR-0009: Build the UI on Tailwind + shadcn/ui bound to the QAYD brass design tokens

Status: Accepted

Date: 2026-07

## Context

QAYD's design taste is a stated product requirement, not a preference: the editorial calm of Linear, Stripe,
and Tap Payments — near-monochrome ink, one disciplined **brass** accent, a grotesque display face, and
purposeful micro-motion — explicitly rejecting the over-rounded, loudly-colorful register of generic SaaS admin
templates ([../../foundation/DESIGN_SYSTEM.md](../../foundation/DESIGN_SYSTEM.md);
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) ADR-010). The UI is also
bilingual with full RTL Arabic and must render in four variants (light/dark × LTR/RTL). The official stack
([../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md)) already names Tailwind and shadcn/ui. The
build decision is how to hit the editorial bar without either fighting an opaque component library's defaults on
every screen or re-implementing accessibility (focus trapping, ARIA roles, keyboard navigation) from scratch.

## Decision

The UI layer in **`packages/ui`** ([ADR-0001](./0001-monorepo-structure.md)) is **Tailwind CSS** for styling
plus **shadcn/ui** — Radix UI primitives generated **into the repository** and owned as source, not consumed as
an opaque npm dependency. Radix provides accessibility (preserved, never stripped, when restyling); shadcn's
generate-into-repo model means every primitive is QAYD's own code, freely restyled. All styling is bound to the
**QAYD brass design tokens** — colors, the grotesque type scale, spacing, radii, motion — defined once as CSS
variables and Tailwind theme values; components **never hard-code** a color, font, or spacing value a token
already covers, and never import from `radix-ui` directly (always through `packages/ui`). Light/dark and LTR/RTL
are token- and logical-property-driven so a component works in all four variants without per-variant forks.

## Consequences

Positive:
- Full control over appearance to hit the editorial design bar, with Radix accessibility as a floor that restyling cannot accidentally remove.
- One token source drives light/dark and LTR/RTL, so the brass accent and typographic system stay consistent across every screen and both languages.
- Owning primitives in-repo means no upstream-library version churn dictating QAYD's UI; components evolve on QAYD's schedule and are shared by every app in the monorepo.
- Tailwind's utility model keeps styling colocated and fast to iterate for a small team.

Negative / trade-offs:
- QAYD owns the maintenance of its primitives — a Radix upgrade or an accessibility fix is a deliberate in-repo change, not an `npm update`.
- The disciplines "no hard-coded values, always tokens" and "never import radix directly" are conventions enforced in review, not by a type error.
- Generating and restyling every needed primitive is up-front work versus dropping in a pre-styled kit.

## Alternatives considered

- **A pre-styled component library (MUI, Ant, Chakra):** fast to start, but their opinionated look fights the editorial requirement on every component, and deep restyling means forking anyway — the cost without the control.
- **Fully bespoke primitives from scratch:** total design control, but re-implementing accessibility correctly is genuinely hard and easy to get subtly wrong; Radix gives that floor for free.
- **Tailwind alone, no primitive layer:** flexible, but every dialog, popover, and menu would re-solve focus management and ARIA — exactly what shadcn/Radix already solves.

## Related

- [ADR-0001](./0001-monorepo-structure.md), [ADR-0002](./0002-nextjs-for-web.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-010; [../../foundation/DESIGN_SYSTEM.md](../../foundation/DESIGN_SYSTEM.md); [../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md)
