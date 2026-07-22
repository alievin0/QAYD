# 07 — Frontend — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: FRONTEND
---

# Purpose

This chapter states what the QAYD web frontend *is as a product* — the experience it must deliver, the
shape of the system that delivers it, and the non-negotiable behaviours every screen inherits — at the
altitude a founder, product manager, or engineering lead needs before a sprint is planned. It does not
re-specify a single component, token value, route, or hook; every one of those already has an authoritative
home under [`../../frontend/`](../../frontend/README.md) and [`../../design-system/`](../../design-system/README.md),
and this chapter routes to those homes rather than restating them. Where this document names an
architectural fact, it cites the specification that owns it. The rule for reading this chapter: it fixes
the *requirements and the shape*; the linked documents fix the *implementation*.

The web client is one of four surfaces that share QAYD's single API contract (the others being the Flutter
mobile app, partner integrations, and the AI engine acting on a user's behalf). It is the only first-party
surface covered here. Its defining property is stated once, up front, so nothing downstream has to repeat
it: **the frontend is a pure presentation and interaction layer.** It holds no business rule, no monetary
calculation, and no authoritative permission decision. It renders state the backend owns and issues
mutations the backend validates. This is not a limitation to work around — it is the property that lets one
`/api/v1` contract drive four clients without forking the rules that keep the ledger correct.

# The Frontend's Job

The web client exists to make QAYD's two hard things feel effortless: **supervising an AI finance workforce**
and **operating a correct double-entry accounting core**, in two languages, for two very different kinds of
user, on the same shell. Three product constraints govern every screen and are treated as requirements, not
preferences (full statement in [`../../frontend/README.md`](../../frontend/README.md)):

1. **The backend is the source of truth; the client is unprivileged.** Every fact and every mutation goes
   through `/api/v1`. Client-side checks exist only to make the UI feel instant and to avoid a round trip
   for an action the user cannot take — the backend re-checks everything. The web tier holds no service
   credential and no database access of its own (see [`../../api/INTERNAL_API.md`](../../api/INTERNAL_API.md)).
2. **AI is visible, never silent.** Every AI-originated number, draft, suggestion, or flag carries its
   confidence and its reasoning, and every AI action touching money, tax, payroll, or posted data renders
   an explicit approve / reject / delegate affordance. The frontend never auto-commits an AI proposal; it
   auto-commits only a human's decision about one. This contract is rendered, never re-derived, per
   [`../../frontend/AI_COMMAND_CENTER.md`](../../frontend/AI_COMMAND_CENTER.md).
3. **RBAC in the UI is experience quality, not security.** Hiding or disabling a control the user lacks
   permission for is a courtesy; the action is actually blocked on the backend. Permission keys are
   imported from a generated mirror of the backend grammar, never re-typed as string literals.

Two audiences share one shell without a second design system. Dense, keyboard-first accountant workflows
(Journal Entries, Trial Balance, Bank Reconciliation) and calmer, insight-first executive workflows
(Dashboard, AI Command Center, financial statements) are built from the same primitives and the same tokens;
the difference is composition and density, never a fork.

# Architecture At A Glance

The frontend is a Next.js 15 App Router application in a single `frontend/` package, TypeScript in strict
mode, styled with Tailwind bound to CSS-variable design tokens, composed from repository-owned shadcn/ui
primitives (Radix underneath). The full architecture — route groups, the RSC/Client boundary, the
data-fetching handoff, folder structure — is owned by
[`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md). The product-level shape
worth fixing here is the small set of decisions every screen depends on:

| Concern | Decision | Owned by |
|---|---|---|
| Framework | Next.js 15 App Router only; React Server Components by default, Server Actions for simple mutations, streaming SSR on data-bearing routes | [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md) |
| RSC / Client boundary | A Server Component performs the route's first authenticated fetch (real data on first paint); a thin Client Component mounts a matching query for refetch, mutation, and realtime | [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md) |
| Transport | One fetch wrapper is the only place `fetch` hits `/api/v1`; it unwraps the platform envelope and throws one typed error, so every screen names an endpoint and a query key, never the envelope | [`../../api/REST_STANDARDS.md`](../../api/REST_STANDARDS.md) |
| Server state | TanStack Query v5 — every read is a query, every write a mutation with explicit invalidation or optimistic update; query keys are centralized factories | [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md) |
| UI / client state | Zustand for ephemeral UI state, React Context for cross-tree singletons (session, active company, permissions, locale, theme) — neither ever holds server data | [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md) |
| Realtime | Laravel Reverb + Echo over private, company-scoped channels, subscribed once in the authenticated shell | [`../../backend/BACKEND_ARCHITECTURE.md`](../../backend/BACKEND_ARCHITECTURE.md) |
| Session | Server-revocable Sanctum cookie session, not a client-held bearer token; no API secret ships to the browser | [`../../api/AUTHENTICATION_API.md`](../../api/AUTHENTICATION_API.md) |

A new screen is added by adding a route segment, a query hook, and a handful of composed components. It never
requires a new state-management pattern, a new fetch mechanism, or a new permission model — that constancy is
itself a product requirement, because it is what keeps the fifty-plus future modules shippable at pace.

# The Design System's Role

QAYD's design taste is a stated product requirement, not a decorator's preference: the editorial calm of
Linear, Stripe, and Tap Payments — near-monochrome warm-neutral ink, one disciplined brass accent rationed
to the single primary action and to AI-touched elements, a display/text type pair with true tabular figures
for money, hairline-border elevation over stacked shadows, and purposeful micro-motion. It explicitly rejects
the cartoonish, over-rounded, loudly-colourful register of generic SaaS admin templates. The canonical values
live in [`../../frontend/DESIGN_LANGUAGE.md`](../../frontend/DESIGN_LANGUAGE.md) and are catalogued for the
design system in [`../../design-system/DESIGN_TOKENS.md`](../../design-system/DESIGN_TOKENS.md); this chapter
does not repeat a single hex, rem, or px.

The load-bearing rule the whole system exists to serve is one sentence: **a component references a token,
never a literal.** No screen, component, or chart ships a colour, radius, or spacing value a token already
covers, which is exactly what lets dark mode and the narrow per-company accent white-label each be a one-line
re-point of a semantic variable rather than a re-theme of every component. Two product consequences follow
and are worth fixing at PRD altitude:

- **Financial colour is state, never brand.** Positive/negative/warning hues express polarity and status and
  never bleed into brand usage; the brass accent never carries status. Debit and credit columns render in
  plain tabular ink distinguished by position, never by hue — colour is reserved for genuinely signed net
  metrics. Rationale in [`../../design-system/DESIGN_TOKENS.md`](../../design-system/DESIGN_TOKENS.md).
- **Every state is designed in both themes and both directions.** Loading, empty, error, AI-confidence, and
  RBAC-disabled affordances are specified for light/dark and LTR/RTL; dark mode is a calibrated token remap,
  not an inverted filter (see [`../../frontend/DARK_MODE.md`](../../frontend/DARK_MODE.md) and
  [`../../frontend/THEMING.md`](../../frontend/THEMING.md)).

Accessibility and keyboard behaviour come from Radix and are preserved when primitives are restyled, never
stripped — which is what makes "editorial taste" and "WCAG-conformant" compatible rather than competing goals.

# State And Data Model

The product requirement behind QAYD's state model is that there is exactly one place to look for any given
piece of state, so a data-dense finance UI never drifts into two disagreeing copies of a balance. Ownership is
deliberately narrow per technology (full table in
[`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md)):

| State | Owner |
|---|---|
| Anything that came from `/api/v1` | TanStack Query cache (never Zustand, Context, or ad-hoc `useState`) |
| Sidebar collapsed, active drawer, command palette open, wizard step | Zustand UI store |
| Session, active company, resolved permissions, locale, theme | React Context, set once by the authenticated layout |
| A single form's in-progress values | React Hook Form internal state |
| Realtime push payloads (KPI ticks, new AI proposal, notifications) | Merged directly into the relevant query-cache entry from the Echo listener |

Pagination is uniform (cursor-based for high-volume, time-ordered lists); RBAC gates uniformly through a
single permission hook and a `PermissionGate` component whose hide-versus-disable choice is a documented
per-screen decision. Realtime updates are merged into the query cache rather than kept in a parallel store the
UI must reconcile by hand — the cache stays the one source of on-screen truth.

# Forms, Validation, And Money Input

Every form is one React Hook Form + Zod schema, and that schema mirrors the corresponding Laravel
`FormRequest` field-for-field. A frontend validation rule the backend does not also enforce is treated as a
bug, not a feature: it exists only to fail fast and friendly in the UI before the round trip. Server-side
`422` responses map back onto the exact offending fields, so the backend remains the arbiter of validity while
the user still sees inline, localized errors. Money input is never a bare number field — a currency-aware
primitive owns the KWD three-decimal (fils) default, currency-as-code display, and digit-order protection so
figures read correctly in both directions. Detail is owned by
[`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md) and the design language's
tabular-numeral contract in [`../../frontend/DESIGN_LANGUAGE.md`](../../frontend/DESIGN_LANGUAGE.md).

# Bilingual EN / AR And RTL

QAYD ships English and Arabic on day one with full RTL, and this is a product requirement of the GCC market,
not a later localization pass. The design principle that makes it sustainable: **there is exactly one
component tree, rendered in either direction — never a parallel `*.rtl.tsx` file.** Direction is a single
root-level `dir` attribute every component already respects because no component hard-codes a physical side;
spacing and alignment are logical (`ms`/`me`, `text-start`, `border-s`), and the rare asset that must visually
flip is the explicit exception, not the rule.

Two further requirements are fixed here because they are frequently mishandled elsewhere:

- **Missing translations are a build failure, not a silent runtime fallback.** English and Arabic dictionaries
  share one type, and a CI check fails any key present in one and absent in the other. Every user-facing
  string is added to both in the same commit.
- **Numbers, dates, and currency are always formatted through `Intl`, parameterized by locale and the active
  company's base currency (KWD default).** Financial figures render Western Arabic numerals even in the Arabic
  locale by default, because mixed numeral systems inside one ledger row read as an error to an accountant —
  a deliberate, per-company-configurable override, not an oversight.

# Light / Dark, Performance, And Accessibility Goals

These are product-level acceptance criteria the frontend is held to, with the mechanics owned by the linked
specs:

| Goal | Target | Owned by |
|---|---|---|
| Accessibility | WCAG 2.1 AA as a floor in both themes and both directions; keyboard-first for dense grids; focus, roles, and screen-reader patterns preserved from Radix | [`../../frontend/ACCESSIBILITY.md`](../../frontend/ACCESSIBILITY.md), [`../../design-system/DESIGN_TOKENS.md`](../../design-system/DESIGN_TOKENS.md) |
| Theming | System-aware light/dark with per-user override, no flash on first paint; narrow per-company accent white-label only | [`../../frontend/THEMING.md`](../../frontend/THEMING.md), [`../../frontend/DARK_MODE.md`](../../frontend/DARK_MODE.md) |
| First paint | Real, tenant-scoped data on initial load via the Server-Component fetch — no client-visible spinner on first load; tenant data is never statically cached | [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md) |
| Large data | Virtualized rendering for high-row tables (General Ledger, Trial Balance); dense grids degrade to a read-only summary below a stated minimum width rather than compressing past legibility | [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md), [`../../frontend/RESPONSIVE_DESIGN.md`](../../frontend/RESPONSIVE_DESIGN.md) |
| Motion | Purposeful transitions only, every one collapsing to an instant state change under `prefers-reduced-motion`; no confetti, no decorative animation | [`../../design-system/DESIGN_TOKENS.md`](../../design-system/DESIGN_TOKENS.md) |
| Quality gates | Lint, typecheck, unit, e2e, and the i18n-parity check all required before merge; Playwright captures four screenshot variants per covered screen (light/dark × LTR/RTL) | [`../../frontend/README.md`](../../frontend/README.md) |

The performance posture is desktop-first but tablet-capable, tuned for the ultra-wide monitors common on
accountants' desks and for the reality that a finance workbench is opened for hours, not seconds. Bundle
budgets, code-splitting, and Web Vitals targets are enforced in CI and owned by the architecture spec.

# What This Chapter Does Not Decide

The subscription-tier gating that governs which features and AI autonomy a plan unlocks is a product decision
still open (PRD-1 in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)); the frontend enforces whatever the
backend permission and entitlement layer returns and never hides a tool to simulate a tier. The
`X-Company-Id` identifier form the client sends is subject to an open reconcile item (ARC-3, same register).
Neither blocks frontend architecture; both are resolved server-side and surfaced to the client as ordinary
permission and identifier data.

## Related Documents

- **This chapter (07) expands and routes to:** [`../../frontend/README.md`](../../frontend/README.md),
  [`../../frontend/FRONTEND_ARCHITECTURE.md`](../../frontend/FRONTEND_ARCHITECTURE.md),
  [`../../frontend/DESIGN_LANGUAGE.md`](../../frontend/DESIGN_LANGUAGE.md),
  [`../../frontend/AI_COMMAND_CENTER.md`](../../frontend/AI_COMMAND_CENTER.md),
  [`../../frontend/ACCESSIBILITY.md`](../../frontend/ACCESSIBILITY.md),
  [`../../frontend/THEMING.md`](../../frontend/THEMING.md),
  [`../../frontend/DARK_MODE.md`](../../frontend/DARK_MODE.md),
  [`../../frontend/RESPONSIVE_DESIGN.md`](../../frontend/RESPONSIVE_DESIGN.md)
- **Design system:** [`../../design-system/DESIGN_TOKENS.md`](../../design-system/DESIGN_TOKENS.md),
  [`../../design-system/README.md`](../../design-system/README.md)
- **API contract the frontend consumes:** [`../../api/REST_STANDARDS.md`](../../api/REST_STANDARDS.md),
  [`../../api/AUTHENTICATION_API.md`](../../api/AUTHENTICATION_API.md),
  [`../../api/INTERNAL_API.md`](../../api/INTERNAL_API.md) — see also PRD chapter 08 (Backend)
- **Security:** [`../../security/AUTHENTICATION.md`](../../security/AUTHENTICATION.md),
  [`../../security/AUTHORIZATION.md`](../../security/AUTHORIZATION.md)
- **Cross-PRD:** [`./08_BACKEND.md`](./08_BACKEND.md), [`./09_DATABASE.md`](./09_DATABASE.md),
  [`../MASTER_PRD.md`](../MASTER_PRD.md), open items in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)

# End of Document
