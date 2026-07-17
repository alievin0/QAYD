# Iconography — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: ICONOGRAPHY
---

# Purpose

QAYD is a finance product before it is a software product: every screen is a ledger, a queue, an approval chain, or a number someone will eventually be audited on. Icons in that context are not decoration — they are the fastest-parsed signal on the screen, faster than a label, faster than a color, faster than a number. A misjudged icon system costs a Senior Accountant real seconds on every one of the hundreds of rows they triage in a day, and a *tasteless* icon system costs QAYD the "car/fashion brand, not generic SaaS" positioning the founder has set as non-negotiable (see `DESIGN_CONTEXT.md` and the platform Design Taste mandate). This document is the single source of truth for how iconography is chosen, sized, colored, localized, and shipped across the QAYD frontend (Next.js 15, React 19, TypeScript strict, Tailwind CSS, shadcn/ui, Framer Motion). It exists so that no two engineers ever independently invent a different stroke weight, a different "approve" glyph, or a different way of mirroring an arrow for Arabic — because the moment that happens, the editorial calm the whole product is built on starts to fray.

The scope of this document is deliberately narrow and deliberately load-bearing. It owns: the baseline icon library and its runtime configuration; the canonical mapping from QAYD's financial and AI vocabulary to specific icon glyphs; the sizing scale and the grid it sits on; how icons take color and communicate state, including how AI-originated content is distinguished from human-entered content; when and how a custom, non-Lucide glyph is allowed into the product; how directional icons behave under `dir="rtl"` for Arabic; the accessibility contract every icon usage must satisfy; and the concrete component patterns icons are used inside. It does **not** own the platform's full color-token system, typography scale, spacing scale, or motion-timing curves — those belong to a platform-wide `DESIGN_TOKENS.md` (system of record for the complete token set) and a `COMPONENT_LIBRARY.md` (system of record for component anatomy beyond icon slots); this document defines only the icon-relevant subset of tokens and must be read as a specialization of, never a contradiction to, those documents. Where this document defines a CSS variable value pending that system of record, it says so explicitly rather than presenting it as already ratified elsewhere.

Two structural facts about QAYD shape every decision below. First, the product is genuinely bilingual and bidirectional — English LTR and Arabic RTL are both first-class, not a translated afterthought, and a meaningful fraction of icons in any ERP (arrows, chevrons, pagination, sidebar toggles) carry directional meaning that must invert correctly and consistently, never per-screen improvisation. Second, QAYD's UI renders two categories of content side by side on the same screen: facts a human entered or approved, and proposals an AI agent generated. The platform's hardest rule — AI proposes, a human (or an explicit policy) approves, nothing AI-originated auto-commits — must be visible at the icon level, not just in copywriting, or a user will eventually mistake a drafted journal entry for a posted one. Iconography is one of the cheapest, fastest tools available to make that distinction unmistakable, and this document treats it that way throughout, especially in Color & State and Usage In Components.

# Icon Library

**Baseline.** QAYD standardizes on `lucide-react` as the exclusive icon library for product UI. No other icon package (Heroicons, Feather, Font Awesome, Material Symbols, a custom icon font, or any multicolor/duotone/flat-illustration icon pack) is imported into `app/`, `components/`, or any route group. This is not a preference — it is enforced at code-review level because mixing icon families is the single fastest way to make an editorial interface look like a template. Reasons for the Lucide baseline specifically:

- **Single visual voice.** Every Lucide glyph is drawn on the same 24×24 grid with the same default 2px stroke, round caps, and round joins — a stroke-only, single-weight family with no filled variants to accidentally mix in. This is the closest off-the-shelf match to the "near-monochrome ink, one accent, restrained" mandate.
- **`currentColor` by construction.** Every path renders with `stroke="currentColor"` and no hardcoded fill, so an icon is themeable (light/dark, hover/active/disabled) purely through CSS color inheritance — see Color & State.
- **Tree-shakeable, typed, dependency-light.** Each glyph is its own named ES module export and a plain functional component; unused glyphs are never bundled, and every icon ships with TypeScript types (`LucideIcon`, `LucideProps`) that QAYD's own wrapper composes against (see Examples).
- **Coverage.** At QAYD's breadth — accounting, banking, tax, payroll, inventory, purchasing, sales, AI operations — Lucide's 1,500+ glyph library covers the overwhelming majority of concepts without a custom draw. The minority it does not cover is handled by Custom/Brand Icons, deliberately kept small.
- **Server-renderable.** A Lucide icon is a plain SVG-returning component with no client-only hooks, so it renders safely inside a React Server Component; it never forces a route segment into `"use client"` by itself (only the interactive wrapper around it — a `<button onClick>`, for instance — does).

**Version discipline.** `lucide-react` is pinned to an exact version (no `^`/`~` range) in `package.json`; upgrades are a deliberate PR, never an incidental `npm install`. This matters because Lucide periodically renames glyphs to a "shape-first" convention (e.g. `AlertTriangle` → `TriangleAlert`, `AlertCircle` → `CircleAlert`, `AlertOctagon` → `OctagonAlert`, `CheckCircle` → `CircleCheck`, `CheckCircle2` → `CircleCheckBig`, `XCircle` → `CircleX`). QAYD's codebase imports **only the current shape-first names** — `TriangleAlert`, `CircleAlert`, `OctagonAlert`, `CircleCheck`, `CircleCheckBig`, `CircleX` — never the deprecated concept-first aliases, even though Lucide continues to export both for backward compatibility. A version bump PR must grep the diff of Lucide's changelog for renamed/removed exports used anywhere in `components/icons` or `lib/icon-map.ts` before merging.

**Stroke weight.** Lucide's default `strokeWidth` is `2`. QAYD overrides this platform-wide to **`1.75`** for all icons rendered at `sm`/`md`/`lg` (16–24px) — the overwhelming majority of usage — via the `Icon` wrapper's default prop, never by passing `strokeWidth` ad hoc at each call site. The reasoning is deliberate, not cosmetic: at small rendered sizes, the default 2px stroke reads slightly heavy and toy-like on a high-density finance screen; 1.75 is the thinnest value that still holds up cleanly at 1x (non-Retina) pixel density and in the PDF export path (Financial Statements and Tax Returns render to PDF through the Reports module, where any icon under ~1.5px effective stroke at 100% print zoom visibly thins to a hairline or disappears). For `xl`/`2xl` sizes (32–40px, hero/empty-state contexts), the override drops further to **`1.5`**, because SVG stroke width is a fixed physical thickness, not a proportional one — a 1.75px stroke that looks correct at 20px starts to look bold and cartoonish at 40px unless it is deliberately thinned to preserve the same *visual* weight. This is the one place QAYD's icon system departs from "use the library default everywhere," and it is exactly the kind of restrained, considered departure the editorial taste bar rewards.

**Sizing scale.** Six tokens, each mapped to a Tailwind `size-*` utility (which sets `width`/`height` together, avoiding any risk of a non-uniform, aspect-distorting scale) and a concrete usage guideline:

| Token | px / rem | Tailwind utility | Typical usage |
|---|---|---|---|
| `xs` | 14px / 0.875rem | `size-3.5` | Inline meta glyphs beside caption text (timestamps, helper hints), dense table micro-badges |
| `sm` | 16px / 1rem | `size-4` | Default body-adjacent icon: form field leading icons, inline status glyphs, dropdown item icons, `Button size="sm"` |
| `md` | 20px / 1.25rem | `size-5` | **Default token.** `Button size="default"` icon slot, table row action icons, breadcrumb separators |
| `lg` | 24px / 1.5rem | `size-6` | Sidebar/nav-rail module icons, `Button size="lg"` icon slot, section header icons, Command Palette leading icons |
| `xl` | 32px / 2rem | `size-8` | KPI/stat-card glyph, dialog header icon, agent avatar glyph |
| `2xl` | 40px / 2.5rem | `size-10` | Empty-state hero icon, onboarding illustration accents |

`md` (20px) is the platform default and is what the `Icon` wrapper renders when no `size` prop is passed — engineers must be able to drop an icon into 90% of contexts without thinking about sizing at all, and only reach for the scale when a context genuinely departs from body-text density.

**Alignment with editorial taste.** Concretely, this means: every icon is monochrome and inherits `currentColor` — never a hardcoded hex, never a second color baked into the glyph itself; no duotone, filled-solid, gradient, bevel, drop-shadow, or skeuomorphic icon ever ships (shadows are already restrained platform-wide per the "no heavy shadows" rule, and icons are held to the same bar); no literal emoji character (🎉 ✅ 💰 📊) is ever used as a UI icon or injected into AI-generated message content rendered inside the product; and icons are never scaled non-uniformly or stretched outside their native viewBox aspect ratio. An icon in QAYD should look like it was drawn by the same hand as every other icon in QAYD, on every screen, in every language, in every theme — that consistency, more than any single glyph choice, is what separates "editorial" from "generic SaaS."

# Semantic Icon Set

This is the canonical concept-to-glyph mapping. It exists to prevent the single most common icon-system failure in a large product: the same financial concept rendered with three different icons across three screens because three different engineers each made a reasonable-sounding independent choice. Any engineer introducing a new icon usage for a concept already listed here **must** reuse the listed glyph; any engineer encountering a concept not yet listed must add a row here in the same PR, not invent a private convention.

| Concept | Primary icon | Related / secondary | Notes |
|---|---|---|---|
| Chart of Accounts (`accounts`) | `ListTree` | `Layers` (account grouping) | Hierarchical tree glyph matches the CoA's parent/child structure directly |
| Journal Entries (`journal_entries`) | `NotebookPen` | `BookText` (read-only journal view) | `NotebookPen` implies "an entry is being written/drafted"; used on Create/Draft actions |
| General Ledger (`ledger_entries`) | `BookOpenCheck` | — | The checkmark motif signals "posted, reconciled record," distinct from the draft-oriented Journal glyph |
| Trial Balance | `Scale` | — | A literal balance scale for a screen whose entire purpose is proving debits = credits |
| Financial Statements (Balance Sheet, P&L, Cash Flow) | `FileBarChart2` | `FileSpreadsheet` | `FileBarChart2` for the statement itself; `FileSpreadsheet` when the action is "export to Excel" |
| Banking (`bank_accounts`, `bank_transactions`) | `Landmark` | `CreditCard` (card-funded accounts) | `Landmark`'s classical-building glyph reads unambiguously as "financial institution" across locales |
| Bank Reconciliation | `ListChecks` | `ArrowLeftRight` (transfer matching) | `ListChecks` for the matching worklist itself |
| Tax (`tax_codes`, `tax_rates`) | `Percent` | `FileCheck2` (a filed/submitted return) | `Percent` for rates/configuration; `FileCheck2` specifically for `tax_returns` once submitted |
| Payroll (`payroll_runs`, `payslips`) | `HandCoins` | `Users` (employees), `ReceiptText` (payslip) | `HandCoins` evokes disbursement to people, distinct from generic `Wallet` |
| Inventory (`inventory_items`, `stock_movements`) | `Boxes` | `PackagePlus` / `PackageMinus` / `PackageCheck` / `PackageX` | The `Package*` family maps directly to stock-in / stock-out / count-verified / voided movement types |
| Warehouses | `Warehouse` | — | Reserved exclusively for the physical-location concept, never reused for generic "storage" |
| Sales (`sales_orders`, `invoices`) | `Receipt` | `ShoppingCart` (sales orders specifically), `FileText` (quotations) | `Receipt` is the invoice/root icon; `ShoppingCart` disambiguates the order stage |
| Purchasing (`purchase_orders`, `bills`) | `ClipboardList` | `Truck` (goods receipt / delivery) | `ClipboardList` for POs/RFQs; `Truck` strictly for the physical-receipt step |
| Customers | `Users` | `UserCircle` (single customer profile) | |
| Vendors | `Building2` | `UserCircle` (single vendor contact) | Distinguishes vendors (organizations) from customers at a glance even though both may be `Users` in some ERPs — QAYD deliberately differentiates |
| Approvals / Approval Chains (`approval_requests`) | `ShieldCheck` | `CircleCheckBig` (approve action), `CircleX` (reject action) | `ShieldCheck` is the *concept* (governance), the Circle glyphs are the *actions* inside it |
| AI / Agent (general) | `Sparkles` | `Bot` (a specific agent's identity), `BotMessageSquare` (AI chat/conversation) | See the AI Agent Roster table below for the full 15-agent breakdown |
| Notifications | `Bell` | `BellRing` (unread/active state) | |
| Alerts — informational | `CircleAlert` | severity: low | |
| Alerts — warning | `TriangleAlert` | severity: medium/warning (amber) | |
| Alerts — critical | `OctagonAlert` | severity: high/critical (destructive) | Octagon (stop-sign silhouette) reserved for the platform's highest severity tier only |
| Reports | `FileBarChart2` | `BarChart3`, `LineChart`, `PieChart` (chart-type selector) | |
| Fraud Detection findings | `ShieldAlert` | — | Distinct from generic `TriangleAlert` to signal a security/integrity concern, not a data-quality one |
| Audit / Audit Trail (`audit_logs`) | `History` | `FileClock` (a specific record's change history) | |
| Settings / Company configuration | `Settings` | `Building2` (company profile specifically) | |
| Search | `Search` | — | |
| Filter | `SlidersHorizontal` | — | Never `Filter` alone once a screen also needs a generic funnel elsewhere — QAYD standardizes on `SlidersHorizontal` platform-wide for consistency |
| Export / Download | `Download` | `Upload` (import) | |
| Attachments (`attachments`) | `Paperclip` | `FileText` (a specific attached document) | |

**AI Agent Roster — icon mapping.** QAYD's AI layer is fifteen named, specialized agents (see `AI_COMMAND_CENTER.md`), each of which authors rows in the shared `ai_decisions` ledger and appears by name in conversation threads, the AI Command Center, and Approval Center cards. Each agent is represented by its **domain icon** (reused from the table above, so an agent's icon always agrees with the module it works in) plus the shared `Sparkles` AI-indicator treatment described in Color & State — QAYD does not give every agent a bespoke illustrated mascot, which would read as playful/childish and violate the taste mandate; it gives each agent a legible, restrained pairing of "which part of the business" (the domain icon) and "this came from AI" (the Sparkles treatment):

| Agent (`agent_code`) | Domain icon | Rationale |
|---|---|---|
| General Accountant | `NotebookPen` | Drafts journal entries — same glyph as the Journal Entries concept |
| Auditor | `History` | Reviews posted history and Trial-Balance findings |
| Tax Advisor | `Percent` | |
| Payroll Manager | `HandCoins` | |
| Inventory Manager | `Boxes` | |
| Treasury Manager | `Landmark` | Owns cash position and bank-side risk |
| CFO Agent | `Scale` | Synthesizes ratios and Business Health Score — the balance/judgment glyph |
| Fraud Detection | `ShieldAlert` | |
| Reporting Agent | `FileBarChart2` | |
| Document AI | `ScanLine` | Document capture/extraction, distinct from OCR's text-recognition step |
| OCR Agent | `FileSearch2` | Reads text out of a captured document |
| Forecast Agent | `TrendingUp` | The one agent whose icon is a trend arrow — forecasting is literally about directional projection |
| Compliance Agent | `ShieldCheck` | Shares the Approvals family glyph, reflecting its regulatory-gate role |
| Approval Assistant | `ClipboardCheck` | Routes and tracks approval chains, distinct from `ShieldCheck` (the chain concept itself) |
| CEO Assistant | `LayoutDashboard` | Synthesizes the Morning Briefing — the dashboard-summary glyph |

Where an agent's identity must render as a small circular avatar (chat attribution, the Command Center roster grid), the domain icon sits centered inside a 28px circle filled with a 10%-opacity tint of `--primary` in light mode / `--primary` at reduced opacity in dark mode — never a photographic avatar, never a color-per-agent scheme (that would reintroduce the "colorful, decorative" pattern the taste bar forbids and would not scale cleanly past fifteen agents anyway).

# Sizing & Grid

**Grid alignment.** QAYD's spacing system is Tailwind's default 4px base unit (`1` = 0.25rem = 4px). Every icon size token in this document was chosen specifically because it lands on that grid without remainder:

| Token | px | Tailwind step | Math |
|---|---|---|---|
| `xs` | 14 | `3.5` | 3.5 × 4px |
| `sm` | 16 | `4` | 4 × 4px |
| `md` | 20 | `5` | 5 × 4px |
| `lg` | 24 | `6` | 6 × 4px |
| `xl` | 32 | `8` | 8 × 4px |
| `2xl` | 40 | `10` | 10 × 4px |

No icon usage in QAYD ever specifies a raw pixel size (`size-[18px]`, `w-[22px]`) outside this table. An engineer who believes a context needs an in-between size is almost always solving a spacing problem, not a sizing problem — the fix is to adjust the padding of the container, not to invent a seventh icon size.

**Touch and click target minimums.** WCAG 2.2 Success Criterion 2.5.8 (Target Size — Minimum, Level AA) sets a 24×24 CSS-pixel floor for any interactive target, with a narrow exception for controls that sit inline in a sentence or a dense data row where an equivalent, larger control is available elsewhere. QAYD's internal bar is deliberately stricter than the legal floor in the contexts that matter most:

| Context | Minimum hit area | Rendered glyph size | Notes |
|---|---|---|---|
| Primary icon-only action (toolbar button, mobile nav) | 40×40px | `sm` (16px) or `md` (20px) | Meets and exceeds 2.5.8; matches shadcn `Button size="icon"` default |
| Dense desktop data-table row action (e.g. a row-level "view" icon in a 500-row Journal Entries grid) | 32×32px | `sm` (16px) | Falls back to WCAG 2.5.8's inline-control exception because the equivalent action is always also reachable via the row's own click-to-open behavior and a keyboard-accessible row menu |
| Table header sort indicator | 24×24px | `xs` (14px) | The entire header cell is the click target, not just the chevron |
| Command Palette / dropdown menu item leading icon | Non-interactive (decorative) | `sm` (16px) | The icon never carries its own hit target — the full row is the target |

**Icon-to-text ratio.** An icon placed beside text should read as optically balanced against that text's cap-height, not its full font-size box. As a working rule, QAYD sizes the icon to roughly 1.0–1.15× the adjacent text's declared font-size in pixels: 14px body text pairs with a 16px (`sm`) icon, not a 14px (`xs`) icon, because Lucide's 24×24 grid carries small internal padding that makes a literally-equal-height icon look slightly smaller than the text next to it. The concrete pairing table used throughout the component library:

| Text context | Font size | Paired icon token |
|---|---|---|
| Caption / meta text | 12px | `xs` (14px) |
| Body / table cell | 14px | `sm` (16px) |
| Button label (default) | 14px | `md` (20px) |
| Section heading | 16–18px | `lg` (24px) |
| Page title | 20–24px | `lg` (24px) |
| KPI/stat value | 28–32px | `xl` (32px) |

**Pixel-snapping.** Interactive icon containers (buttons, badges) are always sized in whole, even Tailwind steps so that the padding around a centered icon divides evenly and the glyph doesn't drift half a pixel off-center at 100%/125%/150% browser zoom. A 40px (`size-10`) button holding a 20px (`size-5`) icon centers with exactly 10px of padding on every side — clean at any zoom level. Engineers must never construct a custom odd-pixel container (e.g. 39px) around an icon; if a design comp shows an odd value, round it to the nearest even Tailwind step before implementing, and flag the discrepancy back to design rather than pixel-matching a comp that itself violates the grid.

# Color & State

**`currentColor` inheritance is the only color mechanism.** Every Lucide glyph — and every custom glyph QAYD adds (see Custom/Brand Icons) — renders `stroke="currentColor"` with no fill. An icon's color is therefore always set by its CSS `color` property, inherited from an ancestor or set directly with a Tailwind text-color utility (`text-foreground`, `text-muted-foreground`, `text-primary`, `text-destructive`, …), never by a `fill`/`stroke` prop passed directly to the `<Icon>` component and never by an inline `style`. This is what makes dark mode, hover states, and disabled states "free" — a single class change on a color already used for text repaints every icon on the screen instantly, with no separate dark-mode icon asset, no JS-driven swap, and no flash on theme toggle.

**Token table.** The tokens below are the icon-relevant subset of the platform's color system, expressed as HSL triplets in the shadcn/ui convention (`--token: H S% L%`, consumed via `hsl(var(--token))` in the Tailwind color config). These values are this document's working definition; the platform-wide `DESIGN_TOKENS.md` is the eventual system of record for the complete token set (including every non-icon-relevant token) and must stay consistent with the values below rather than silently diverging from them.

```css
:root {
  --background:        40 20% 98%;   /* warm near-white paper */
  --foreground:         165 18% 11%;  /* near-black, teal-leaning ink — the base icon color */
  --muted-foreground:   165 8% 40%;   /* secondary/meta icon color */
  --border:             165 10% 87%;
  --primary:            162 68% 24%;  /* the ONE disciplined accent — deep emerald */
  --primary-foreground: 40 20% 98%;
  --success:            152 50% 30%;
  --warning:            38 88% 42%;   /* "amber", matches the platform's own risk-threshold vocabulary */
  --destructive:        4 70% 45%;
  --info:               210 55% 42%;
  --ring:               162 68% 24%;
}
.dark {
  --background:         165 22% 7%;
  --foreground:         40 15% 94%;
  --muted-foreground:   165 8% 58%;
  --border:             165 14% 18%;
  --primary:            158 55% 48%;
  --primary-foreground: 165 30% 8%;
  --success:            152 42% 52%;
  --warning:            42 80% 58%;
  --destructive:        4 65% 60%;
  --info:               210 55% 65%;
  --ring:               158 55% 48%;
}
```

**Usage rules.**

1. **Ink hierarchy is the default for ~90% of icons.** A title-adjacent or primary-action icon takes `text-foreground`; a secondary/meta icon (a timestamp clock, a helper-text glyph, an inline attachment clip) takes `text-muted-foreground`. Neither ever competes visually with the single accent.
2. **`--primary` is reserved for interactive/selected state, not general iconography.** The active item in a nav rail, the icon inside a primary CTA button, a checked/selected row's leading icon — these earn the accent because they represent the one thing on the screen the user is currently acting on. An icon does not get colored with the accent merely because a designer wants visual interest; that is precisely the "decorative color" pattern the taste bar forbids.
3. **Semantic state colors are reserved strictly for financial/workflow state**, and always paired with matching text — never color alone (see the accessibility rule on color as sole carrier, below): `--success` (posted, reconciled, approved, on-time), `--warning` (pending, drafted, due soon, amber risk threshold), `--destructive` (voided, rejected, overdue, critical risk), `--info` (informational, neutral system message).
4. **AI-originated content is distinguished by shape and pattern, not by a second brand hue.** QAYD does not introduce a dedicated "AI purple" or "AI blue" the way many AI-era products do, because the platform's taste mandate is explicitly *one* disciplined accent — adding a second brand-level hue for AI would double the palette and undercut the entire premise. Instead, AI-proposed content is marked by three redundant, non-color cues stacked together: the `Sparkles` glyph, an explicit text label ("AI proposed" / "Suggested by {agent}"), and a 1px dashed border on the containing card — all rendered in the *same* `--primary` accent already used for interactive elements. A user never has to learn a new color to know they're looking at a machine-drafted proposal; the shape and the words tell them, and the accent simply signals "this is something you can act on," exactly as it does everywhere else in the product.

**Interactive icon states.** Every icon-only interactive element (a `Button size="icon"`, a clickable table-row icon) implements the full state set, driven by Tailwind variants on top of the color tokens above:

| State | Treatment |
|---|---|
| Default | `text-muted-foreground` (icon-only buttons default to the quieter ink tone, not full `--foreground`, so a toolbar of five icon buttons doesn't read as five equally shouty CTAs) |
| Hover | `hover:text-foreground hover:bg-accent/50` — the icon darkens toward full ink and gains a soft background tint |
| Focus-visible | `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2` — keyboard focus is never suppressed, matching the platform's keyboard-first requirement |
| Active / pressed | `active:scale-95` combined with Framer Motion's `whileTap={{ scale: 0.95 }}` when the surrounding component already uses motion — a small, fast, purposeful squash, never a bounce |
| Selected (e.g. active nav item) | `text-primary` — the one context where an otherwise-neutral icon legitimately earns the accent |
| Disabled | `disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none` — the icon dims with the rest of the control; disabled state is never communicated by icon color alone (see RBAC note in Usage In Components) |
| Loading | The icon is swapped for `Loader2` with `animate-spin` (see Accessibility for the reduced-motion behavior of this specific case) inside the same fixed-size slot, so the control does not change dimensions mid-request |

**Dark mode.** Theme switching is a single class (`.dark`) applied to `<html>`, system-aware by default with an explicit user override persisted in `S.settings`-equivalent client state. Because every icon inherits `currentColor` from tokens that are themselves redefined inside `.dark`, no icon component, wrapper, or usage site ever needs its own dark-mode branch — this is treated as a correctness property of the system, not a per-component responsibility. A code review that finds an icon usage with a hardcoded light-mode-only color class (e.g. `text-neutral-900` instead of `text-foreground`) treats it as a bug, not a style nit.

**Color is never the sole carrier of state.** Per WCAG 1.4.1 (Use of Color), any state an icon communicates through color must have a redundant, non-color cue. A posted journal entry does not merely render green — it renders a distinct `CircleCheckBig` glyph *and* the word "Posted"; a voided one renders a distinct `CircleX` glyph *and* the word "Voided." The same rule governs numeric polarity throughout the product: a negative variance or an overdue amount is never conveyed by a bare red number — it is always paired with a leading sign (`−`) or a directional glyph (`TrendingDown`, `ArrowDownRight`), because color-only encoding fails for the roughly 8% of men with some form of color-vision deficiency, and finance is exactly the domain where that failure is least acceptable.

**Non-text contrast.** Per WCAG 1.4.11 (Non-text Contrast), every icon's stroke must maintain at least a 3:1 contrast ratio against its immediate background in both themes — a materially different (lower) bar than body text's 4.5:1, but a real one that a `--muted-foreground` icon rendered at reduced opacity can fail if stacked carelessly (e.g. a disabled, muted icon inside an already-muted card background). The color-token owner verifies this ratio for every token pairing in `DESIGN_TOKENS.md`; this document's obligation is narrower but absolute: no icon usage ever applies an *additional* opacity or tint on top of the token system that would push a meaningful (non-decorative) icon below 3:1.

# Custom/Brand Icons

**When a custom icon is justified — and when it is not.** A custom, hand-drawn glyph is added to QAYD only when one of four conditions holds, and the bar is deliberately high because every custom icon is a maintenance liability the Lucide baseline does not carry (no upstream fixes, no community coverage, a permanent QAYD-owned SVG):

1. **No Lucide glyph adequately represents the concept**, and the concept is common enough in QAYD's own UI to be worth a dedicated shape rather than a generic stand-in. Example: a distinct Kuwaiti Dinar / multi-currency badge glyph — Lucide's `Coins`, `DollarSign`, and `Banknote` are all US-dollar-coded in their visual metaphor (a coin with a "$"-adjacent silhouette reads instantly as "dollar," not "currency in general") and none represent a currency *switcher* (KWD/AED/SAR) cleanly.
2. **A recurring composite concept is worth collapsing into one glyph** rather than stacking two-to-three Lucide icons at every call site. Example: the Purchasing module's three-way match (`purchase_orders` ↔ `goods_receipts` ↔ `bills`) appears constantly across Purchasing screens and AI proposal cards; a single `ThreeWayMatch` glyph (three linked nodes) reads faster and renders more consistently than composing `ClipboardList` + `Truck` + `Receipt` inline every time.
3. **Agent-identity differentiation genuinely fails with domain icons alone.** In practice this has not yet been needed — the fifteen-agent roster (Semantic Icon Set) differentiates cleanly on domain icon + name — but if two agents ever share an identical domain icon in a context where both appear side by side (e.g. a future sub-split of Treasury into two specialized agents), a custom monogram glyph is the sanctioned fallback before reaching for color-per-agent.
4. **The QAYD wordmark/logomark itself** — the "ق"-derived monogram used as the app icon, favicon, auth-screen mark, and PDF-export letterhead. This is brand identity, not inline UI iconography, and is versioned and reviewed separately from this document's scope, but its SVG pipeline (below) is shared.

**The explicit anti-goal.** A custom icon is never added merely because a designer or engineer prefers a different visual treatment of a concept Lucide already covers well. "I think a filled version would look nicer here" is not a justification — it is exactly the kind of one-off drift that turns a disciplined icon system into an inconsistent one within two product quarters. Every custom-icon PR must cite which of the four numbered conditions above it satisfies in the PR description; a reviewer rejects any custom-icon addition that cannot.

**SVG pipeline.** Every custom icon is produced and shipped through the same six steps, so that a custom glyph is visually indistinguishable in weight and construction from a Lucide glyph sitting next to it in the same toolbar:

1. **Design on grid parity.** Draw on a 24×24 viewBox, 2px stroke at the design-file level (the runtime override to 1.75/1.5 is applied identically to custom icons by the same `Icon` wrapper — the source file matches Lucide's own convention so the two families scale together predictably), round line caps and joins, no fill except the rare solid-glyph exception already used sparingly in Lucide itself (e.g. a filled dot).
2. **Optimize with SVGO.** Every custom SVG is run through SVGO before it enters the codebase, using a config that strips explicit `width`/`height` (so sizing is controlled entirely by the CSS/prop system, never the SVG's own intrinsic size), strips any hardcoded `fill`/`stroke` color, and preserves the `viewBox`:
   ```js
   // svgo.icons.config.mjs
   export default {
     plugins: [
       'preset-default',
       'removeDimensions',
       { name: 'removeAttrs', params: { attrs: '(fill|stroke)' } },
     ],
   };
   ```
3. **Codegen into a typed component.** A small script (`scripts/build-custom-icons.mjs`, built on `@svgr/core`) converts each optimized SVG into a React component under `components/icons/custom/`, with a generated interface identical in shape to Lucide's own `LucideProps` (`size`, `strokeWidth`, `className`, plus the rest of standard SVG props) — this is what makes a custom icon a drop-in replacement anywhere an `<Icon icon={...} />` call expects a Lucide-shaped component.
4. **Barrel export.** Every custom icon is re-exported from `components/icons/custom/index.ts`, so `lib/icon-map.ts` (Semantic Icon Set's implementation) imports custom and Lucide icons through the same statement shape and call sites never need to know which family a given concept resolves to.
5. **Visual QA gallery.** An internal-only route (`app/(internal)/design-system/icons/page.tsx`, excluded from production builds via an environment check, never a public route) renders every icon in the system — Lucide baseline and custom — at every size token, in both themes, in both LTR and RTL, so a reviewer can eyeball weight/alignment consistency in one place before merge.
6. **Design sign-off gate.** No custom icon merges without explicit design review against the gallery route. This is the taste bar's actual enforcement mechanism for this section — process, not just documentation.

**Worked example — `ThreeWayMatch`:**

```tsx
// components/icons/custom/three-way-match.tsx
// Generated from three-way-match.svg via scripts/build-custom-icons.mjs — do not hand-edit the <path> data.
import { forwardRef } from "react";
import type { LucideProps } from "lucide-react";

export const ThreeWayMatch = forwardRef<SVGSVGElement, LucideProps>(
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
      <circle cx="6" cy="6" r="2.5" />
      <circle cx="18" cy="6" r="2.5" />
      <circle cx="12" cy="18" r="2.5" />
      <path d="M8.2 7.4 L10.3 16.2 M15.8 7.4 L13.7 16.2 M8.5 6 H15.5" />
    </svg>
  ),
);
ThreeWayMatch.displayName = "ThreeWayMatch";
```

Note the identical prop contract (`size`, `strokeWidth`, default `1.75`, `aria-hidden="true"` by default) to every Lucide icon — this is what the `Icon` wrapper relies on to treat custom and baseline icons interchangeably (see Examples).

# RTL-Aware Icons

QAYD renders identically in English (LTR) and Arabic (RTL), with `dir="rtl"` set on `<html>` (or the nearest RTL-scoped ancestor) whenever Arabic is active. Icons split cleanly into two groups under that flip, and getting the grouping wrong in either direction is a recurring, easy-to-miss bug class: mirroring an icon that shouldn't mirror inverts its meaning (a `TrendingUp` mirrored into a visual "down" reads as the opposite of what the data says); failing to mirror an icon that should reads as sloppy, untranslated software.

**Mechanism.** QAYD does not maintain two separate icon sets or swap component references per locale. A single boolean prop on the `Icon` wrapper, `mirrorRtl`, applies Tailwind's built-in `rtl:` variant (keyed automatically off the ancestor `dir` attribute — no manual locale check inside the component):

```tsx
<Icon icon={ChevronRight} mirrorRtl className="rtl:-scale-x-100" />
```

The wrapper applies the class conditionally so call sites never repeat the Tailwind variant by hand; see Examples for the wrapper's implementation. Spacing and position around icons — margins, gaps, badge overlay position — use Tailwind's logical-property utilities (`ms-*`/`me-*` for margin-inline-start/end, `ps-*`/`pe-*` for padding, `start-*`/`end-*` for inset) instead of physical `left-*`/`right-*` utilities everywhere an icon sits near a directional edge, so the layout inverts correctly with zero locale-specific branching:

```tsx
{/* Notification bell with an unread-count badge — correct in both directions */}
<div className="relative">
  <Icon icon={Bell} size="lg" aria-hidden />
  {unreadCount > 0 && (
    <span className="absolute -end-1 -top-1 flex size-4 items-center justify-center rounded-full bg-destructive text-[10px] text-destructive-foreground">
      {unreadCount}
    </span>
  )}
</div>
```

Using `-end-1` instead of `-right-1` means this badge sits at the visual top-*end* of the bell — top-right in English, top-left in Arabic — without a single `dir === 'rtl'` conditional anywhere in the component.

**Mirrors in RTL:**

| Icon / family | Why it mirrors |
|---|---|
| `ChevronLeft` / `ChevronRight` (pagination, accordions, tree-expand carets) | Pure reading-direction navigation — "next" points toward the end of the line, which is left in Arabic |
| `ArrowLeft` / `ArrowRight` (back/forward, breadcrumb separators) | Same reading-direction logic |
| `PanelLeft` / `PanelRight` (sidebar collapse/expand toggle) | The sidebar itself relocates to the opposite physical edge in RTL, so its toggle icon must relocate and point with it |
| `IndentIncrease` / `IndentDecrease` | Indentation is text-direction-relative by definition |
| `LogOut` | The exit arrow points toward the (mirrored) edge of the screen |
| `Undo2` / `Redo2` | History navigation is treated as reading-direction-relative, consistent with established platform RTL guidance (undo "steps back" toward the start of the timeline, which flips with the reading direction) |
| `CornerUpLeft` / `CornerDownRight` (reply-style glyphs) | Directional corner arrows follow the same reading-order logic as chevrons |

**Does not mirror in RTL:**

| Icon / family | Why it stays fixed |
|---|---|
| `TrendingUp` / `TrendingDown` | These encode the *direction of a value* (revenue rising or falling), not layout direction — mirroring would falsify the data they represent |
| `ArrowUpRight` / `ArrowDownRight` (external-link glyphs, stat-delta indicators) | Screen-relative "up and out," a near-universal convention independent of reading direction |
| Status/alert glyphs (`CircleCheckBig`, `TriangleAlert`, `OctagonAlert`, `CircleX`) | Symmetric or non-directional by construction |
| Domain/object glyphs (`Boxes`, `Landmark`, `Users`, `HandCoins`, `Warehouse`, etc.) | Depict a physical object or concept with no inherent left/right orientation |
| Media transport controls (`Play`, `Pause`, `SkipForward`) | Global convention keeps these screen-relative even in fully RTL-localized products |
| Brand/logomarks, checkmarks, plus/minus | No directional semantics to invert |

The `Icon` wrapper defaults `mirrorRtl` to `false`; an engineer adding a new directional icon usage must consult this table (and extend it in the same PR if the icon is genuinely new) before deciding whether to pass `mirrorRtl`, rather than guessing per screen.

# Accessibility

**Decorative vs. meaningful — the default-safe rule.** Every icon usage is classified at the moment it's written into one of two categories, and the `Icon` wrapper is designed so the *safe* default requires no extra effort:

- **Decorative** — the icon sits beside visible text that already conveys the same information (a `Bell` next to the word "Notifications," a `Landmark` next to "Bank Accounts" in a nav rail). It carries `aria-hidden="true"` and is invisible to assistive technology. This is the wrapper's **default** behavior when no `label` prop is supplied — an engineer has to do nothing to get this right.
- **Meaningful** — the icon is the *only* conveyor of information at that spot (an icon-only button, a status glyph in a table cell with no adjacent text). It requires an explicit `label` prop on the `Icon` wrapper, which flips the rendered SVG to `role="img"` and removes `aria-hidden`, and — critically — the accessible name lives on the interactive *element* (`aria-label` on the `<button>`), not on the decorative SVG nested inside it, per standard practice for icon-only controls.

```tsx
{/* Decorative — the label already says everything */}
<Button variant="ghost"><Icon icon={Landmark} /> Bank Accounts</Button>

{/* Meaningful — the icon IS the only content; the accessible name lives on the button */}
<Button variant="ghost" size="icon" aria-label={t("actions.reconcile")}>
  <Icon icon={ListChecks} />
</Button>
```

**Enforcement, not just convention.** QAYD's ESLint configuration extends `eslint-plugin-jsx-a11y` with a project-specific rule that fails CI on any `Button` (or any component recognized as a button-like primitive from `components/ui/`) with `size="icon"` that lacks an `aria-label`, `aria-labelledby`, or a non-empty visible text child. This is deliberately a build-breaking check, not a linter warning, because icon-only-button-with-no-name is the single most common accessibility regression in icon-heavy UIs and the cheapest one to catch mechanically before a human reviewer even looks at the PR.

**Tooltips reinforce, they never replace.** An icon-only button's visible `Tooltip` (via shadcn's Radix-backed `Tooltip` primitive) should, in almost every case, use the *same string* as its `aria-label` — sighted mouse users and screen-reader users then receive identical information, and a translator only has to localize one string per action rather than risk two drifting apart across a locale update.

**Dense tables: text collapses before meaning does.** A status column in a wide table (Journal Entries, Invoices, Bills) is tempted to save horizontal space by rendering an icon with no visible label. QAYD's rule: at `≥768px` (tablet and up), the status column always pairs the icon with a short visible text label ("Posted," "Draft," "Void") — collapsing to icon-only purely to save a few characters of table width is exactly the kind of density-over-clarity trade the "crisp data density... without clutter" mandate is meant to prevent, and it silently degrades accessibility for sighted low-vision users who don't use a screen reader but also can't reliably distinguish `CircleCheckBig` from `CircleCheck` at a glance. Below `768px`, the column may legitimately collapse to icon + `aria-label` + tooltip, because the row itself becomes a card at that breakpoint and the status is restated in the card body.

**Reduced motion.** Any icon-attached animation — the `Loader2` spin during a pending request, a subtle pulse on a "live" AI-status glyph in the Command Center — respects `prefers-reduced-motion`. When reduced motion is requested, `animate-spin` is replaced with a static `Loader2` icon plus the word "Loading…" (never a bare spinning icon with no text fallback, and never motion with no static equivalent), matching the reduced-motion-gated behavior already required platform-wide.

**Non-text contrast** is enforced identically here as specified in Color & State (WCAG 1.4.11, 3:1 minimum) — repeated here only to note that accessibility review, not just visual design review, is a checkpoint for any new icon/background color pairing.

**Keyboard.** Every icon-only interactive element is a real semantic `<button>` (or `<a>` where navigation is the action) — never a bare `<svg onClick={...}>` or a `<div role="button">` retrofit. Focus is visible via the `--ring` token (Color & State) with a non-zero offset, and the entire padded hit area (Sizing & Grid) is focusable and clickable, not just the glyph's own bounding box.

# Usage In Components

This table is the primary reference engineers reach for while building a screen: given a shadcn/Radix primitive, which icon slot exists, what size/aria rule governs it, and a real QAYD example tying the pattern to an actual route and permission key.

| Component | Icon slot | Sizing / a11y rule | QAYD example |
|---|---|---|---|
| `Button` (leading) | Optional leading icon before the label | `md` inside `size="default"`, `sm` inside `size="sm"`, `lg` inside `size="lg"`; decorative (`aria-hidden`) because the button label already states the action | `<Button><Icon icon={NotebookPen}/> New Journal Entry</Button>` on `app/(app)/accounting/journal-entries/page.tsx` |
| `Button size="icon"` | Sole content | `sm`/`md`; **meaningful** — mandatory `aria-label`, ESLint-enforced (Accessibility) | The Bank Reconciliation match button (`ListChecks`, `aria-label={t('banking.reconcile.match')}`) on `app/(app)/banking/reconciliation/page.tsx`, disabled unless the session has `bank.reconcile` |
| `Badge` (status pill) | Small leading icon inside the pill | `xs`; decorative, since the pill's text already names the state | `<Badge variant="success"><Icon icon={CircleCheckBig} size="xs"/> Posted</Badge>` on the Journal Entries list |
| `DropdownMenu.Item` / Command Palette item | Leading icon, fixed-width gutter | `sm`, gutter fixed at `w-4` regardless of which glyph occupies it, so menu items align in a column even when icons vary in visual width; decorative | The ⌘K Command Palette's "Go to Trial Balance" (`Scale`), "New Bill" (`ClipboardList`) entries |
| `Tabs` / side nav rail | Module icon beside label | `lg` in the top-level rail (Dashboard, Accounting, Banking, …), `md` in in-page tabs (`familyHome`-style tab sets); decorative when paired with a visible label, which is the rail's default state | The primary nav rail: `LayoutDashboard`, `Calculator` (Accounting), `Landmark` (Banking), `HandCoins` (Payroll), `Boxes` (Inventory) |
| Toast / `Sonner` notification | Severity icon, leading | `md`; decorative, since toast copy states the outcome, but the glyph must still pass the color+shape redundancy rule (Color & State) | A failed bank sync toast: `CircleAlert` + `text-destructive` + the message "Bank sync failed — retrying in 5 minutes" |
| `DataTable` column header | Sort indicator, trailing | `xs` (`ArrowUpDown` unsorted, `ChevronUp`/`ChevronDown` once sorted); the entire header cell is the click target (Sizing & Grid) | Journal Entries grid, sortable on `entry_date`, `amount`, `status` |
| `DataTable` row — status column | Icon + text (`≥768px`), icon + `aria-label` (`<768px`) | `sm`; see Accessibility's dense-table rule | `journal_entries.status` rendered via the `StatusIcon` component (Examples) |
| `Input` — leading icon | Search fields (`Search`), currency-prefixed amount fields (`Banknote` before a KWD amount input, paired with a text "KWD" affix, never the icon alone as the currency indicator) | `sm`; decorative | The global search input in the top bar; the amount field on the manual Journal Entry line editor |
| Empty state | Large centered icon above heading/body/CTA | `2xl`; decorative, since the empty-state heading always restates the same message in text | "No accounts yet" on a brand-new company's Chart of Accounts screen — `ListTree` at `2xl`, heading "Build your Chart of Accounts," a primary CTA button |
| AI proposal / approval card | `Sparkles` badge (top corner) + agent domain icon (attribution) + `CircleCheckBig`/`CircleX` action icons | Badge `xs`, attribution icon `sm`, action icons `sm` inside `Button size="icon"` (meaningful, `aria-label`) | An `ai_decisions` row rendered on `app/(app)/ai/approvals/page.tsx`, gated by `ai.approve`; see Examples for the full component |
| `Breadcrumb` | Chevron/slash separator | `xs`, `mirrorRtl` (RTL-Aware Icons); decorative | `Accounting / Journal Entries / #JE-2026-04512` |
| KPI / stat card | Large icon top-left or top-right of the metric | `xl`; decorative, paired with a `TrendingUp`/`TrendingDown` micro-glyph beside the delta percentage (never color alone, per Color & State) | The Cash Position card on the AI Command Center, `Landmark` at `xl` plus a `TrendingUp` delta glyph |

**RBAC-gated icon state.** Any icon sitting inside a control whose underlying action requires a permission the current session lacks renders in the control's `disabled` state (Color & State's disabled treatment) rather than being hidden outright, **except** where the platform's RBAC convention for that specific screen calls for hiding entirely (QAYD hides rather than disables when even the *existence* of the action would leak information the role shouldn't have — e.g., a Read Only role never sees a "Post" button at all on Journal Entries, disabled or not). Where disabled-with-visible-icon is the right call (the more common case — the user should understand the action exists but isn't currently theirs to take), the button additionally carries a tooltip naming the missing permission in human terms ("Requires Finance Manager approval," not the raw key `payroll.approve`), sourced from the same permission-key vocabulary defined in `PERMISSION_SYSTEM.md` and `AI_COMMAND_CENTER.md` (`accounting.journal.post`, `bank.reconcile`, `bank.transfer`, `payroll.calculate`, `payroll.approve`, `inventory.adjust`, `tax.submit`, `ai.approve`, `reports.export`).

# Do & Don't

| Do | Don't |
|---|---|
| Use exactly one icon library (`lucide-react` + the small custom set) everywhere | Mix in a second icon package "just for this one screen" |
| Keep every icon monochrome, `currentColor`-driven | Hardcode a hex/rgb fill or stroke on any icon instance |
| Reserve `--primary` for interactive/selected/AI-accent state | Color an icon with the accent purely for "visual interest" |
| Use color + shape + text together for any state | Encode a state (posted/void/overdue) in color alone |
| Reuse the Semantic Icon Set mapping for a concept everywhere it appears | Let three screens each independently invent an icon for "Journal Entry" |
| Add a custom icon only against one of the four justified conditions | Draw a custom icon because "I like this style better" than the Lucide equivalent |
| Give every icon-only button a real `aria-label` | Ship an icon-only `Button size="icon"` with no accessible name and let CI catch it later |
| Mirror navigation/reading-direction icons in RTL via `mirrorRtl` | Mirror value-direction icons (`TrendingUp`/`TrendingDown`) or symmetric status glyphs |
| Use logical-property utilities (`ms-*`, `end-*`) near directional edges | Hardcode `left-*`/`right-*` on anything that sits near an icon in a bidirectional layout |
| Size interactive icon containers on even Tailwind steps | Build a 39px or otherwise odd-pixel custom container around an icon |
| Let disabled icons dim with `opacity-40` and a tooltip explaining the missing permission | Hide a control outright when disabling-with-explanation is the correct RBAC pattern for that screen |
| Respect `prefers-reduced-motion` on every spinning/pulsing icon | Ship a spinner with no static, text-labeled fallback |
| Use literal glyphs (`Sparkles`, dashed border, text label) to mark AI-originated content | Auto-commit anything AI-proposed, or hide the fact that a proposal came from AI to make the UI feel "more finished" |
| Keep icons out of AI-generated message copy entirely | Let an AI agent's response text include emoji, even informally, in the product surface |

# Examples (TSX)

**1. The `Icon` wrapper — the single entry point every icon usage goes through.**

```tsx
// components/ui/icon.tsx
"use client";

import { forwardRef } from "react";
import type { LucideProps } from "lucide-react";
import { cn } from "@/lib/utils";

const SIZE_MAP = {
  xs: 14,
  sm: 16,
  md: 20,
  lg: 24,
  xl: 32,
  "2xl": 40,
} as const;

export type IconSize = keyof typeof SIZE_MAP;

export interface IconProps extends Omit<LucideProps, "size"> {
  /** The Lucide (or QAYD custom) icon component to render. */
  icon: React.ComponentType<LucideProps>;
  /** Semantic size token — defaults to "md" (20px), the platform default. */
  size?: IconSize;
  /** Accessible name. Presence flips the icon from decorative to meaningful (see Accessibility). */
  label?: string;
  /** Apply Tailwind's rtl: mirror for directional icons (see RTL-Aware Icons). */
  mirrorRtl?: boolean;
}

export const Icon = forwardRef<SVGSVGElement, IconProps>(
  ({ icon: IconComponent, size = "md", label, mirrorRtl = false, strokeWidth, className, ...props }, ref) => {
    const px = SIZE_MAP[size];
    // xl/2xl render at a thinner stroke to preserve visual weight at larger sizes (see Icon Library).
    const resolvedStroke = strokeWidth ?? (size === "xl" || size === "2xl" ? 1.5 : 1.75);

    return (
      <IconComponent
        ref={ref}
        size={px}
        strokeWidth={resolvedStroke}
        role={label ? "img" : undefined}
        aria-hidden={label ? undefined : true}
        aria-label={label}
        className={cn("shrink-0", mirrorRtl && "rtl:-scale-x-100", className)}
        {...props}
      />
    );
  },
);
Icon.displayName = "Icon";
```

**2. Semantic status mapping — `lib/icon-map.ts` (excerpt) and the `StatusIcon` component used across Journal Entries, Invoices, and Bills.**

```tsx
// lib/icon-map.ts
import {
  FileClock, CircleCheckBig, Undo2, CircleX, type LucideIcon,
} from "lucide-react";
import type { JournalEntryStatus } from "@/types/accounting";

export const JOURNAL_STATUS_ICON: Record<
  JournalEntryStatus,
  { icon: LucideIcon; className: string; labelKey: string }
> = {
  draft:    { icon: FileClock,      className: "text-muted-foreground", labelKey: "status.draft" },
  posted:   { icon: CircleCheckBig, className: "text-success",          labelKey: "status.posted" },
  reversed: { icon: Undo2,          className: "text-warning",          labelKey: "status.reversed" },
  voided:   { icon: CircleX,        className: "text-destructive",     labelKey: "status.voided" },
};
```

```tsx
// components/accounting/status-icon.tsx
import { useTranslations } from "@/lib/i18n";
import { Icon } from "@/components/ui/icon";
import { JOURNAL_STATUS_ICON } from "@/lib/icon-map";
import type { JournalEntryStatus } from "@/types/accounting";

export function StatusIcon({ status, showLabel }: { status: JournalEntryStatus; showLabel: boolean }) {
  const t = useTranslations();
  const { icon, className, labelKey } = JOURNAL_STATUS_ICON[status];

  // Dense-table rule (Accessibility): text collapses only below md breakpoint.
  return (
    <span className={cn("inline-flex items-center gap-1.5", className)}>
      <Icon icon={icon} size="sm" label={showLabel ? undefined : t(labelKey)} />
      {showLabel && <span className="text-sm font-medium">{t(labelKey)}</span>}
    </span>
  );
}
```

**3. An AI proposal approval row — permission-gated, confidence-aware, never auto-committed.**

```tsx
// components/ai/approval-action-row.tsx
"use client";

import { Sparkles, CircleCheckBig, CircleX } from "lucide-react";
import { Icon } from "@/components/ui/icon";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { usePermission } from "@/hooks/use-permission";
import { useApproveDecision, useRejectDecision } from "@/hooks/use-ai-decisions";
import type { AiDecision } from "@/types/ai";

export function ApprovalActionRow({ decision }: { decision: AiDecision }) {
  const canApprove = usePermission("ai.approve");
  const approve = useApproveDecision(decision.id); // POST /api/v1/approvals/{id}/approve
  const reject = useRejectDecision(decision.id);    // POST /api/v1/approvals/{id}/reject

  return (
    <div className="flex items-center justify-between rounded-lg border border-dashed border-primary/40 p-3">
      <div className="flex items-center gap-2">
        <Icon icon={Sparkles} size="sm" className="text-primary" />
        <span className="text-sm">
          {decision.agentDisplayName} proposed this — {Math.round(decision.confidenceScore)}% confidence
        </span>
      </div>
      <div className="flex items-center gap-1">
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              size="icon"
              variant="ghost"
              aria-label="Approve"
              disabled={!canApprove}
              onClick={() => approve.mutate()}
            >
              <Icon icon={CircleCheckBig} size="sm" className="text-success" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{canApprove ? "Approve" : "Requires ai.approve permission"}</TooltipContent>
        </Tooltip>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              size="icon"
              variant="ghost"
              aria-label="Reject"
              disabled={!canApprove}
              onClick={() => reject.mutate()}
            >
              <Icon icon={CircleX} size="sm" className="text-destructive" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{canApprove ? "Reject" : "Requires ai.approve permission"}</TooltipContent>
        </Tooltip>
      </div>
    </div>
  );
}
```

**4. A pending-approvals bell badge, driven by TanStack Query, RTL-safe.**

```tsx
// components/layout/approvals-bell.tsx
"use client";

import { useQuery } from "@tanstack/react-query";
import { Bell } from "lucide-react";
import { Icon } from "@/components/ui/icon";
import { apiClient } from "@/lib/api-client";

function usePendingApprovalsCount() {
  return useQuery({
    queryKey: ["approvals", "pending", "count"],
    queryFn: async () => {
      // GET /api/v1/approvals?status=pending — permission: reports.read
      const res = await apiClient.get("/approvals", { params: { status: "pending", per_page: 1 } });
      return res.data.meta.pagination.total as number;
    },
    refetchInterval: 30_000, // Reverb push (Realtime) invalidates this key on ai.finished/approval.* events too
  });
}

export function ApprovalsBell() {
  const { data: count = 0 } = usePendingApprovalsCount();

  return (
    <div className="relative">
      <Icon icon={Bell} size="lg" label={`${count} pending approvals`} />
      {count > 0 && (
        <span className="absolute -end-1 -top-1 flex size-4 items-center justify-center rounded-full bg-destructive text-[10px] font-medium text-destructive-foreground">
          {count > 9 ? "9+" : count}
        </span>
      )}
    </div>
  );
}
```

**5. A sidebar nav item with an RTL-mirrored trailing chevron and an accent-colored active state.**

```tsx
// components/layout/nav-item.tsx
import Link from "next/link";
import { ChevronRight, type LucideIcon } from "lucide-react";
import { Icon } from "@/components/ui/icon";
import { cn } from "@/lib/utils";

export function NavItem({
  href, icon, label, active,
}: { href: string; icon: LucideIcon; label: string; active: boolean }) {
  return (
    <Link
      href={href}
      className={cn(
        "flex items-center gap-3 rounded-md px-3 py-2 text-sm",
        active ? "bg-primary/10 text-primary" : "text-muted-foreground hover:text-foreground",
      )}
      aria-current={active ? "page" : undefined}
    >
      <Icon icon={icon} size="lg" />
      <span className="flex-1">{label}</span>
      {active && <Icon icon={ChevronRight} size="xs" mirrorRtl />}
    </Link>
  );
}
```

# Edge Cases

- **Disabled control, legible icon.** A disabled icon-only button dims via `disabled:opacity-40`, but the surrounding row's text labels do not also dim — only the specific disabled control does, so a Read-Only user scanning a list can still read every value even though every action icon beside them is inert.
- **Icon inside a truncated menu row.** In an overflow `DropdownMenu` item whose label truncates with `text-ellipsis`, the leading icon's `w-4` gutter (Usage In Components) is `shrink-0` so the icon never compresses or disappears before the text does — the icon is the more important element to preserve since it survives truncation better than a half-cut word.
- **Locale-driven label length shifting the icon-text gap.** Arabic labels are frequently longer or shorter than their English counterparts for the same button. Because the gap between an icon and its label is always set with `gap-*` utilities on a flex container (never a manual `margin-left`/`margin-right` on the icon itself), the spacing stays correct regardless of label length or direction — this is precisely why QAYD bans manual margins around icons in favor of `gap`.
- **Loading state without layout shift.** When a leading icon is swapped for `Loader2` mid-request, the icon occupies a fixed-size slot (the same `size` token as the icon it replaces) so the button's overall width does not jump between the idle and loading states — relevant on `Button` components whose label doesn't change (e.g. "Post Entry" while a request to `POST /api/v1/accounting/journal-entries/{id}/post` is in flight).
- **Brand-new company, no data yet.** A brand-new company's Chart of Accounts, Journal Entries, or Products list has zero rows on day one. The relevant icon still renders — at `2xl` in the empty state (Usage In Components) — rather than the screen showing a blank void; "nothing here yet" is itself a state this document's icon system must represent, not an exception to it.
- **Custom company theming cannot break icon contrast.** QAYD allows a company to customize its sidebar accent tint and logo, but never its semantic state tokens (`--success`/`--warning`/`--destructive`/`--info`) or its ink tokens (`--foreground`/`--muted-foreground`) — this is a deliberate multi-tenancy guardrail (consistent with the platform's broader "every business record belongs to a company, but the platform's own integrity rules are never tenant-overridable" posture) that exists specifically so no company configuration can silently push an icon below the WCAG 1.4.11 contrast floor.
- **Server Components and the client boundary.** A Lucide (or custom) icon component is plain, server-renderable SVG with no client-only hooks, so placing an `<Icon>` inside a Server Component (e.g. a static page header) does not require a `"use client"` directive. The boundary is introduced by the *interactive wrapper* around the icon — a `<Button onClick={...}>`, a component calling `useQuery` — never by the icon itself. Engineers should not reflexively mark a component client-side just because it renders an icon.
- **Print / PDF export.** Financial Statements and Tax Returns render to PDF through the Reports module's export path. The print stylesheet forces ink tokens toward pure black-on-white for maximum print contrast and applies `print:hidden` to every icon marked `aria-hidden` (decorative), since a printed page has no hover/interactive context for them to serve — meaningful icons (a "Voided" status glyph appearing on a printed statement) remain, rendered at the `xl`/`1.5` stroke pairing so they hold up at typical print DPI.
- **No icon-font flash.** Because Lucide (and QAYD's custom icons) ship as inline SVG React components rather than an icon font or a sprite sheet fetched over the network, there is no flash-of-unstyled-content for icons, no extra network waterfall entry, and no layout shift while an icon font loads — an advantage worth preserving by never introducing an icon-font-based package alongside it "just for one feature."
- **Command Palette / dense list column alignment.** Because glyphs vary in their own visual width even at an identical `size` token (a `Landmark` glyph occupies more of its box than a `Percent` glyph), every leading-icon gutter in a list (Command Palette, DropdownMenu) is a fixed-width flex/grid cell (`w-4` at `sm`), not an auto-width inline element — so labels across rows of visually different icons still start at the same horizontal position.

# End of Document
