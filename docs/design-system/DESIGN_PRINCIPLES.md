# Design Principles — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: DESIGN_PRINCIPLES
---

# Purpose

This document states the product-design principles that govern every QAYD surface. Where
[`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) decides *what* a value resolves to, this document decides *why*
the interface behaves the way it does — the reasoning an engineer or an AI coding agent applies when a
screen spec is silent, when two reasonable layouts compete, or when someone proposes a new pattern.
Principles outrank convenience: a decision that violates one of these to save an hour of implementation
is a defect, not a shortcut, and is raised rather than merged.

QAYD is an AI Financial Operating System — multi-tenant, double-entry accounting (Accounting, Sales,
Purchasing, Banking, Inventory, Payroll, Tax) wrapped in an AI layer that drafts, reconciles, forecasts,
and flags, with a human always in the loop for anything sensitive. Two facts about that product shape
every principle below. First, the interface is the only place the product's trustworthiness becomes
visible: a dashboard that looks like a consumer game undermines credibility before a number is read, and
a dashboard that looks like a generic admin panel fails to distinguish QAYD from the ten other "AI
accounting" pitches a CFO ignored this year. Second, the audience is bilingual and Gulf-primary: for a
Kuwait-first product, Arabic is not the secondary half.

Each principle below is stated, justified, and closed with a do/don't table. The canonical values these
principles reference — the ink scale, the brass accent, the type and motion scales — live in
[`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md), mirrored from
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md).

# Principle 1 — Editorial calm over decoration

**Statement.** QAYD is built first in near-monochrome — twelve steps of one warm-neutral ink scale — and
color, illustration, and ornament are added only where they earn their place. Hierarchy is carried by
type weight, size, and whitespace; a screen must remain legible and correctly ranked with the accent
temporarily removed.

**Rationale.** If a screen only reads correctly because half of it is tinted, the hierarchy is being
done by color instead of by structure, and it will collapse the moment the palette changes (dark mode,
a tenant's white-label accent, a grayscale print of a statement). Editorial calm — the register of
Linear, Stripe, and Tap Payments — is also what signals "trust this with a company's books." Decoration
reads as a product compensating for weak information design; restraint reads as confidence. The visual
references are the objects a serious ledger has always been made of — ruled paper, brass fittings, a
bound book of record — translated into screen-native type and space, never into skeuomorphic texture.

| Do | Don't |
|---|---|
| Build the screen in ink first; add accent only as punctuation | Reach for a second color to create a hierarchy type and space should carry |
| Let a page read correctly with the accent removed | Depend on a tint to make a section feel important |
| Use hairline `ink-6`/`ink-7` borders and whitespace for structure | Add gradient-mesh backgrounds, "AI glow," or neumorphic bevels |
| Trust generous whitespace to separate ideas | Fill empty space with decorative graphics because it "looks bare" |

# Principle 2 — One accent, spent deliberately

**Statement.** QAYD has exactly one brand color — a restrained brass (`accent` `#9C7A34` light /
`#D9B96C` dark) — and it is rationed. It marks the single primary action on a screen, the focus ring, the
active tab/nav item, links, and anything the AI layer has touched. Financial state (positive, negative,
warning) uses its own semantic set and never borrows the accent; the accent never carries status.

**Rationale.** A restrained brass was chosen precisely because the bright SaaS indigo/violet is now the
default "AI startup" signal — the opposite of distinctive — and because brass carries the brand's own
ledger metaphor without any literal texture. Rationing it to one thing keeps the palette to one hue as
promised, and lets a user *learn* the color: brass on a QAYD screen always means either "this is the
button to press" or "this is something AI produced, not something committed" — never a generic
highlight. If more than one saturated brass element competes for attention on a screen, the hierarchy is
wrong, not the token.

| Do | Don't |
|---|---|
| Use `accent` for the one primary action per screen and for AI-touched elements | Use the accent as a generic "highlight anywhere convenient" |
| Pair every filled `accent` surface with `accent-on` (near-black, not white) | Put white text on brass (fails AA at the accent value) |
| Keep `warning` (orange) visibly distinct from the brass accent | Let a second saturated color creep in "for visual interest" |
| Reserve `accent-subtle` for AI-proposal surfaces and selected rows | Tint every card a different color to differentiate them |

# Principle 3 — Numbers are the hero, not the chrome around them

**Statement.** QAYD is a financial product; the interface exists to make a number trustworthy and fast to
read. Every figure representing money, a quantity, a percentage, or an account code is set in tabular
(fixed-width) figures, right-aligned in columns, at generous row height, separated by hairline dividers.
When in doubt, remove a decorative element before removing whitespace around a number.

**Rationale.** A column of amounts whose digits don't align vertically is an immediate "this wasn't built
by people who use spreadsheets" signal. Tabular figures, right-alignment, and ruled-paper row grouping do
more for a Trial Balance than any card, gradient, or icon. This is enforced structurally, not by
call-site discipline: a dedicated `<Amount>`/`AmountCell` primitive owns tabular numerals, the KWD
three-decimal (fils) default, currency-as-code, and the `dir="ltr"` digit-order protection so a grouped
figure never reorders inside an Arabic row.

One rule inside this principle is non-negotiable because it is the most common accounting-UI mistake: **a
debit is not "bad" and a credit is not "good."** Raw debit/credit columns render in plain `ink-12`
tabular numerals, distinguished only by column position — never red/green — because revenue postings are
credits and are unambiguously good news. Color is reserved for the layer above the raw ledger (Net
Income, a variance, a period-over-period delta) where a number is genuinely signed.

| Do | Don't |
|---|---|
| Render every amount in tabular figures via the `<Amount>` primitive | Hand-format a money string at the call site |
| Default KWD/BHD/OMR to three decimals (fils); currency as a code (`KD 1,204.500`) | Round KWD to two decimals or invent a currency symbol glyph |
| Keep raw debit/credit columns in neutral ink | Color raw debits red and credits green |
| Color only genuinely signed, net metrics (Net Income, variance) | Wash an entire numeral in red/green — annotate with a small `+/−` chip instead |

# Principle 4 — Two audiences, one system

**Statement.** The same component library, layout system, and tokens serve two very different workflows:
dense, keyboard-first accountant work (Journal Entries, Trial Balance, Reconciliation — high data
density, no hand-holding) and lighter, insight-first executive views (Dashboard, AI Command Center,
statements at a glance — calmer density, narrative framing, drill-down on demand). The difference between
them is **composition and density**, never a second design system.

**Rationale.** Two design systems drift apart within a year and double every future change. One system at
two densities stays coherent: an accountant reconciling 4,000 bank lines and an owner glancing at a
12-row vendor list are the same tenant and deserve the same primitives, tuned differently. Density is a
per-table, user-persisted preference (Compact 32px / Comfortable 40px / Relaxed 48px rows), not a global
mode, because the same person may want compact tables on a laptop and larger text on a projector the same
day. Density scales the data-dense contexts (row height, cell padding) — not page margins, card padding,
or section gaps — so the app never feels globally cramped.

| Do | Don't |
|---|---|
| Compose the same primitives at different densities per audience | Fork a "simple mode" component tree for executives |
| Expose density as a per-table, user-persisted toggle | Ship one global density that serves neither audience well |
| Scale row height/cell padding for density | Shrink page margins and section gaps to fit more in |
| Give executive views narrative framing and drill-down | Dump an accountant-grade 40-column grid on a dashboard |

# Principle 5 — AI is visible, never silent

**Statement.** Nothing the AI layer produces is ever styled to look like a fact the system has already
committed. Every AI-originated number, suggestion, draft, or flag carries its **confidence** and its
**reasoning**, and every AI action touching money, tax, payroll, or posted data renders an explicit
approve/reject/delegate affordance. The frontend never auto-commits an AI proposal; it only auto-commits
a human's decision about one.

**Rationale.** The product's core promise is an AI that drafts and explains but never overrides human
authority — and the UI must make that promise legible, or the promise is worthless. A proposed journal
entry, a forecast, and an anomaly flag are visually distinct — `accent`/`accent-subtle`, "Suggested"
language, a confidence affordance — from anything a human approved or the system posted. In a table, AI
provenance is a single 6px `accent` dot in the leading gutter plus a "Suggested" micro-label in the
detail view; the confidence score lives on hover (`"92% confidence — Accountant Agent"`) rather than as a
permanent percentage competing with the financial figures. Once a human approves or the system posts, the
marker disappears — provenance is visible until a decision is made, not forever. The AI's copy is always
proposing language (*suggest, propose, recommend, flag*), never completed language, even at high
confidence: "I've suggested a match" is correct; "I've matched this" is not.

| Do | Don't |
|---|---|
| Style AI drafts distinctly (accent, "Suggested") from committed fact | Let a user mistake an AI draft for ground truth by accident of styling |
| Surface confidence on hover and reasoning in the detail view | Auto-commit an AI proposal without a human decision |
| Give the AI assistant a geometric mark (a ledger tick) | Give the AI a cartoon face, mascot, robot icon, or emoji |
| Write AI copy in proposing language | Write "I've matched/posted this" for a pending, unapproved action |

# Principle 6 — Bilingual EN/AR and RTL as first-class

**Statement.** Every screen is designed and reviewed in Arabic before it is considered done — not flipped
with `dir="rtl"` at the end and eyeballed. There is one component tree rendered in either direction;
mirroring is achieved with CSS logical properties (`ms-*`, `me-*`, `ps-*`, `pe-*`, `border-s`,
`text-start`), never physical `left`/`right` values. Arabic uses the same named type steps and rem sizes
as Latin, with two deliberate differences: taller line-height (+0.15–0.2) for connecting strokes and
diacritics, and zero letter-spacing (negative tracking breaks letter connections and is incorrect
typesetting).

**Rationale.** For a Kuwait-first, Gulf-primary product, Arabic is not the secondary half of the
audience. Logical properties make a component written once behave correctly in both directions with zero
conditional code, enforced by a lint rule that blocks physical utilities outside a short allow-list.
Numerals never mirror: even inside an RTL Arabic sentence, a KWD figure keeps Western (Latin) digits in
left-to-right order (`<bdi dir="ltr">`), because Gulf ledgers, invoices, and spreadsheets are written
that way and mixed numeral systems read as an error to an accountant. Icons mirror only when their meaning
is directional (a "next" chevron, a flow arrow); a checkmark, a gear, a logo, a numeral never flips.
Arabic copy is authored directly by a fluent professional-register writer as a parallel original, not
machine-translated from English and adjusted.

| Do | Don't |
|---|---|
| Use logical properties (`ms/me/ps/pe`, `text-start`) everywhere | Hard-code `ml/mr/pl/pr`, `text-left`/`text-right` |
| Keep numerals `dir="ltr"` and Western even in Arabic rows | Switch financial figures to Eastern Arabic-Indic digits |
| Author Arabic as a parallel original in Gulf business register | Machine-translate English strings and ship them as Arabic |
| Mirror only genuinely directional icons | Flip checkmarks, gears, logos, or numerals in RTL |

# Principle 7 — Accessibility is a requirement, not an add-on

**Statement.** QAYD targets **WCAG 2.1 AA** as a floor across both themes and both directions. Body text
clears 4.5:1, large text and meaningful boundaries clear 3:1, the focus ring clears 3:1 on every surface
step. Color is never the only signal. Every dense table is keyboard-navigable (arrow keys, `Enter` to
open a row, a table-scoped `Cmd/Ctrl+F`), and Radix's focus-trapping and ARIA wiring is preserved, never
stripped, when a primitive is restyled.

**Rationale.** Accessibility is treated as a build gate, not a later pass, because it is cheapest to hold
when it is designed in and effectively impossible to retrofit across 40 screens. Contrast is verified in
CI by an automated script on every PR touching the palette; a new semantic token cannot merge without
passing it in both themes. A financial delta never relies on `--negative` alone — it is always paired with
a leading `+`/`−` sign and, where space allows, a directional glyph — so colorblind users and grayscale
printing never lose meaning. A permission-gated control that is disabled rather than hidden always carries
a tooltip naming the required permission (e.g. "Requires `accounting.journal.post`"); QAYD never leaves a
user staring at a grayed-out button with no explanation.

| Do | Don't |
|---|---|
| Verify AA contrast in both themes and both directions, in CI | Ship a token pair "to fix contrast later" |
| Pair every color signal with a sign, icon, or text | Rely on red/green alone to convey polarity |
| Preserve Radix focus-trap/ARIA when restyling a primitive | Strip accessibility semantics to simplify markup |
| Give a disabled control a tooltip naming the missing permission | Silently hide or gray out a control with no explanation |

# Principle 8 — Restraint: reject the cartoonish SaaS register

**Statement.** QAYD explicitly rejects the loud, rounded, emoji-decorated register of generic SaaS admin
templates. No cartoon mascots or illustrated characters (including for the AI, which is a mark, never a
face); no emoji anywhere in product chrome, errors, or system copy; no gradient-mesh or "AI-glow"
backgrounds; no drop-shadow-heavy neumorphism; no confetti or spring-wobble on financial actions; no
illustrated empty-state characters; no rainbow dashboards where every KPI tile is a different color.
Corner radius is capped at 10px on any rectangular surface; only avatars, status dots, and pill badges
are fully rounded.

**Rationale.** Restraint is the brand. Large radii read as consumer/playful (Notion, Duolingo,
mobile-first social); a tight 4–10px range reads as engineered and precise — a Bentley's console does not
have rounded-off corners, and neither should a Trial Balance. Every rejected element is a specific,
common failure mode of "AI accounting" UIs, and each is banned by name so it never arrives one deadline
at a time. Restraint also compounds with Principle 1: what a mascot or an illustration would say, QAYD
says with type, space, and precise copy.

| Do | Don't |
|---|---|
| Keep rectangular radii ≤10px; fully round only avatars/dots/pills | Use 20px+ "friendly" rounded cards |
| Represent the AI with a geometric ledger-tick mark | Add a mascot, robot, or illustrated character |
| Write plain, precise copy in both languages | Use exclamation marks, emoji, or hype copy ("Awesome!", a party emoji) |
| Use Lucide line icons, one color, restrained | Use filled/colorful icon packs or isometric 3D illustration |

# Principle 9 — Motion explains, never entertains

**Statement.** Every animation answers a question — where did this panel come from, what changed, is the
system still working — in under ~300ms, and collapses to an instant state change under
`prefers-reduced-motion`. Motion is implemented centrally (Framer Motion + `lib/motion.ts`), not as
ad-hoc CSS transitions scattered across components. QAYD never bounces, confetti-bursts, or spring-wobbles
a financial state change.

**Rationale.** Posting a journal entry is not a game mechanic; the correct celebration for a balanced
ledger is quiet certainty — a single checkmark that fades in, holds ~900ms, and fades out — not
fireworks. Durations are calibrated (micro 120ms → slow 360ms) to feel like a well-made physical switch:
a short, certain click. Reduced motion never removes a *state change* (a panel still opens, a toast still
appears) — it removes only the *animation* of that change, the correct reading of the media query. Row
entrance stagger is capped (30ms/row, ≤12 rows) so a 200-row ledger never takes four seconds to "animate
in." Performance is part of motion discipline: `backdrop-blur` glass is reserved for two moments (the
command palette and the AI Command Center), because it is not free on the back-office desktops that make
up much of QAYD's audience.

| Do | Don't |
|---|---|
| Keep animations ≤~300ms and answer a specific question | Animate to delight — bounce, confetti, spring-wobble |
| Confirm a posted entry with a brief, quiet checkmark | Celebrate a financial action with a reward animation |
| Route all motion through `lib/motion.ts` and honor reduced-motion | Scatter ad-hoc CSS transitions across components |
| Cap list-stagger and reserve glass for two overlays | Stagger 200 rows or glass every panel |

# Principle 10 — Consistency compounds; novelty costs

**Statement.** A new pattern is a withdrawal against the user's trust in every other screen. Before
introducing a new card shape, a new shadow, a new motion curve, or a new shade of gray, the token
catalogue and the component library are checked first. Genuine gaps are proposed as additions to the
system — a reviewed token or a shared component — not buried as one-off exceptions in a single screen's
code.

**Rationale.** Forty screens built from one disciplined vocabulary read as one expert product; forty
screens each with a slightly different table, shadow, or gray read as a template someone bought. Tokens
are the one place local judgment is deliberately constrained, because a single off-convention value
copied once gets copied again — so raw hex/`rgb()`/px literals in component code are blocked outright, a
new semantic token requires a Design-Systems review with both light and dark values and a contrast proof,
and a quarterly audit greps shipped code for inline color literals to promote or remove. A component that
would otherwise be duplicated across two modules moves to `components/shared/` the moment the second
module needs it — it is never copied in place.

| Do | Don't |
|---|---|
| Check the token catalogue and component library before inventing | Add a new gray/shadow/curve inline "just this once" |
| Propose a genuine gap as a reviewed token or shared component | Bury a one-off exception in a single screen's code |
| Reference a token, never a literal | Write a raw hex/`rgb()`/px value a token already covers |
| Promote a twice-needed component into `shared/` | Copy-paste a component between two module folders |

# How These Principles Are Enforced

Principles are not aspirational prose; each has a concrete backstop, mostly in CI, described in full in
[`../frontend/THEMING.md`](../frontend/THEMING.md) and [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md):

| Principle | Primary backstop |
|---|---|
| 1, 2, 3, 8, 10 | Stylelint bans raw hex/`rgb()`/px literals; components may reference only tokens |
| 6 | ESLint blocks physical `ml/mr/pl/pr`/`text-left`/`text-right` outside a reviewed allow-list |
| 7 | `scripts/check-contrast.ts` runs on every PR touching the palette; fails the build below AA |
| 5 | AI provenance/confidence affordances specified per screen in each screen doc's "AI Integration" |
| 9 | Motion centralized in `lib/motion.ts`; `getTransition()` reads `useReducedMotion()` everywhere |
| 4 | Density is a per-table persisted preference, tested across the density matrix once at the library level |
| 10 | A new semantic token needs Design-Systems review + both-theme values + a contrast proof; quarterly drift audit |

A principle without a backstop tends to erode under deadline pressure; a principle with one holds. When a
new principle is genuinely needed, it is added here with its own rationale, do/don't table, and backstop —
never left as unwritten "taste" that only its author can apply.

# End of Document
