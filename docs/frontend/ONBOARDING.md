# Onboarding — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: ONBOARDING
---

# Purpose

Onboarding is the guided, multi-step setup flow that turns a freshly authenticated user into the owner
of a fully operational QAYD company — or, on the shorter branch, drops an invited teammate straight
into a company someone else has already set up. It is the frontend implementation of the journey
`docs/foundation/USER_ONBOARDING.md` and `docs/foundation/AUTHENTICATION.md` narrate at product level
("Create Account → Verify Email → Create/Join Company → Company Information → AI Setup → Invite
Employees → Import Existing Data → Connect Services → Dashboard") and the concrete contract this
document owns is everything from the moment a session exists and a company does not yet exist (or
exists but is not yet `onboarding_status = 'completed'`) through the moment the company's Chart of
Accounts is seeded, its numbering and approval basics are configured, its team is invited, a bank
account is at least started on its verification path, opening data has a place to land, and the owner
has been introduced to the fifteen AI agents `docs/ai/AI_FINANCE_OS.md` provisions for every company at
onboarding. Everything before this document's scope — creating the user record itself, verifying an
email address, logging in, MFA — is owned by `../foundation/AUTHENTICATION.md` and
`../api/AUTHENTICATION_API.md`, whose own `POST /auth/register` endpoint table entry states in as many
words that its "full spec [is] owned by the Users/Onboarding module; referenced here only." This
document is that module's frontend half; `docs/foundation/COMPANY_STRUCTURE.md` is its data-model half.

Onboarding renders at **`/onboarding`**, structurally outside the authenticated `(app)` shell
`FRONTEND_ARCHITECTURE.md` defines — no persistent Sidebar, no Topbar company switcher, no global AI
Rail — in the same spirit as the `(auth)` route group ("centered card layout, no sidebar, minimal
JavaScript") but as its own route group, because unlike `(auth)` it runs entirely after a session
exists and entirely before that session's company can be trusted to render the real product shell
around it. A user lands here in exactly two circumstances: immediately after registering and verifying
email with no company yet attached to their account, or deliberately, via "+ Add company" in the
`CompanySwitcher` (`../frontend/NAVIGATION_SYSTEM.md`), when an existing Owner spins up an additional
QAYD company from inside a session that already has one. Both paths converge on the same eight steps
this document specifies; the second path simply arrives with `user` already resolved and skips
straight past anything account-level.

Three constraints inherited from `FRONTEND_ARCHITECTURE.md` and restated once, precisely, for this
screen's own content, because Onboarding is the single highest-consequence data-entry surface in the
product — every field entered here becomes the base currency, the fiscal year, and the Chart of
Accounts every future journal entry in the company's life is measured against:

1. **The frontend decides nothing a template, a rule, or a permission already decides.** Onboarding
   does not compute which Chart of Accounts is IFRS-aligned for Kuwait, does not decide whether an
   industry template is a good fit, and does not compute a numbering sequence's next value — Laravel's
   `accounts/apply-template`, `accounts/opening-balances`, and the company's own
   `numbering_sequences` do. The wizard's job is to collect a confident, validated choice and submit it;
   every one of its client-side checks (a required country, a balanced numbering pattern preview) exists
   to fail fast before a round trip, never as the system of record.
2. **AI is visible and narrated, never silent, and never auto-commits anything sensitive.** The AI
   Guide Dock (`# Layout & Regions`) narrates each step in the voice of the specialist agent that owns
   that step's domain — never a single anthropomorphized "setup bot" — and every AI-suggested default
   (an industry template, a numbering pattern, an approval chain) is pre-filled as a proposal the owner
   must explicitly keep or change, exactly as `DESIGN_LANGUAGE.md`'s Principle 7 requires everywhere
   else in the product: "nothing the AI layer produces is ever styled to look like a fact the system has
   already committed."
3. **RBAC is reflected, not enforced, by this screen — and here it gates a company that barely exists
   yet.** Steps 1–2 require nothing beyond a valid session (creating a company is how a user becomes its
   Owner). Steps 3–8 require the acting user to be that company's `owner_user_id` or to hold the narrow
   delegated permission `company.onboarding.manage` (an implementation partner or accountant finishing
   setup on an Owner's behalf); the server enforces this on every onboarding endpoint regardless of what
   the wizard shows.

Success for this screen is measured exactly the way `USER_ONBOARDING.md` states it: **the median company
completes onboarding in under ten minutes.** That budget is the frame every later section's own
performance and interaction guidance is held against — a ten-minute budget across eight steps means no
single step may itself feel like a five-minute form, which is why steps 6 through 8 (connect a bank,
import opening data, meet the AI team) are explicitly optional and deferrable, never blocking the path
to a working `/dashboard`.

# Route & Access

| | |
|---|---|
| Route group | `app/onboarding/` — its own top-level segment (not a parenthesized route group folded into another layout), because `/onboarding` is a real, bookmarkable, resumable URL prefix, not a layout-only grouping the way `(marketing)`/`(auth)`/`(app)` are. |
| Root layout | `app/onboarding/layout.tsx` (Server Component) — renders `OnboardingShell`: the Progress Rail, the AI Guide Dock, and a `{children}` slot for the active step. No `Sidebar`, no `Topbar`, no `CommandPalette`, no global `AI Rail` — see `# Layout & Regions`. |
| Resume entry | `app/onboarding/page.tsx` — a Server Component with no UI of its own; it resolves the caller's current step from `GET /api/v1/onboarding/progress` and issues a `redirect()` to that step's own route. This is the only route in the tree that is never linked to directly by the UI — every "Continue" action lands on the next step's own URL. |
| Step routes | `company/`, `profile/`, `chart-of-accounts/`, `configuration/`, `team/`, `banking/`, `import/`, `ai-team/` — see the table below. |
| Shell-level `loading.tsx` / `error.tsx` | Skeleton matches `OnboardingShell`'s chrome (Progress Rail + empty step card); `error.tsx` renders a recoverable "Something interrupted setup" card with a "Reload this step" action — it never surfaces a raw stack trace, and it never discards `onboarding_progress`, since that lives server-side, not in this boundary's component state. |

## Step routes, in order

| # | Route | Step name | Required? | Prerequisite step |
|---|---|---|---|---|
| 1 | `app/onboarding/company/page.tsx` | Create or Join Company | Required | None — the entry point for any account with zero companies |
| 2 | `app/onboarding/profile/page.tsx` | Company Profile | Required (Create branch only) | `company` completed with `mode: 'create'` |
| 3 | `app/onboarding/chart-of-accounts/page.tsx` | Chart of Accounts Template | Required (Create branch only) | `profile` completed |
| 4 | `app/onboarding/configuration/page.tsx` | Numbering & Approval Basics | Required (Create branch only) | `chart-of-accounts` completed |
| 5 | `app/onboarding/team/page.tsx` | Invite Team | Skippable | `configuration` completed |
| 6 | `app/onboarding/banking/page.tsx` | Connect Bank | Skippable | `configuration` completed (independent of `team`) |
| 7 | `app/onboarding/import/page.tsx` | Import Opening Data | Skippable | `configuration` completed (independent of `team`/`banking`) |
| 8 | `app/onboarding/ai-team/page.tsx` | Meet Your AI Finance Team | Required, but non-blocking (see `# States`) | Every prior required step completed; skippable steps need only be *visited*, not completed |

The **Join Existing Company** branch, chosen at step 1, is a fork, not a shorter version of the same
eight steps: accepting an invitation (`POST /api/v1/invitations/{token}/accept`, per
`COMPANY_STRUCTURE.md → Invitations`) attaches the user to a company that is, by construction, already
past `onboarding_status = 'completed'` — a company cannot invite anyone until its own owner has finished
at least steps 2–4, per `# Edge Cases`. A joining user therefore never sees steps 2 through 8 at all;
`company/page.tsx` routes them directly to `/dashboard` the instant the invitation is accepted, with a
single confirmation toast ("You've joined **Al Diyar Trading Co.** as Senior Accountant") rather than
walking them through a setup flow that has already happened. Every step from here on describes the
**Create** branch only, since the **Join** branch simply never reaches step 2.

## Access rules

| Step | Permission required | Enforcement |
|---|---|---|
| `company` (create sub-path) | Valid session only — no company-scoped permission exists yet | `POST /api/v1/companies` succeeds for any authenticated user; the caller becomes `owner_user_id` |
| `company` (join sub-path) | Valid session + a valid, unexpired invitation token | `POST /api/v1/invitations/{token}/accept`; a wrong/expired/already-used token renders inline, never a route-level 403 |
| `profile`, `chart-of-accounts`, `configuration` | `owner_user_id = auth()->id()` OR `company.onboarding.manage` | Checked on every `PATCH /api/v1/onboarding/progress` and every step-specific write; a stale session that no longer satisfies this (ownership transferred mid-flow, see `# Edge Cases`) gets a `403` the step's own `error.tsx`-equivalent renders as "This company's setup is being finished by someone else" |
| `team` | Same as above, plus the invite action itself checks `company.users.invite` (present by default on the system Owner role) | A delegate with `company.onboarding.manage` but not `company.users.invite` sees the step, can review it, but its "Send invitations" action is disabled with the standard permission tooltip |
| `banking` | Same ownership rule, plus `bank.account.create` for the actual `POST /api/v1/banking/bank-accounts` call | Matches `BANKING.md`'s own permission for adding an account |
| `import` | Same ownership rule, plus `accounting.accounts.import` for Chart of Accounts data and `bank.reconcile` if a bank statement is included in the same import batch | Mirrors `CHART_OF_ACCOUNTS.md § 17`'s own import permission exactly — this step is a guided entry point into that same pipeline, not a parallel one |
| `ai-team` | Same ownership rule; read-only, no mutating action beyond "Finish setup" | `POST /api/v1/onboarding/complete` |

No step in this flow is gated by a *feature* permission the way `bank.transfer` or `payroll.approve` are
elsewhere in the product — the only authorization question Onboarding ever asks is "is this your company
to configure," never "is this action too sensitive for your role," because until step 8 completes there
is, by definition, exactly one human role in play: the Owner (or their explicit delegate).

## Breadcrumb & navigation chrome

Onboarding has no breadcrumb trail and no back-to-dashboard link in its own chrome — unlike Dashboard's
single-crumb IA root, Onboarding is not part of the primary navigation tree at all
(`NAVIGATION_SYSTEM.md`'s sidebar module map never lists it), and its own Progress Rail is the only
wayfinding a user needs. The one exit affordance is "Save & exit" in the shell header
(`# Interactions & Flows`), which persists progress and returns the user to `/dashboard` if any other
company on their account is already operational, or to `/login`'s post-logout state otherwise — it never
silently discards a partially completed step.

# Layout & Regions

Onboarding introduces the platform's first **Wizard Template**, a sixth entry alongside
`LAYOUT_SYSTEM.md`'s existing List, Detail, Form, Dashboard, and Report page templates. It is
deliberately not a variant of the existing Form Page Template (`journal-entries/new`'s sectioned-card
layout): a Form Page Template edits one record in place with a sticky footer duplicating header actions,
while a Wizard Template is a *sequence* of otherwise-independent screens sharing one persistent progress
affordance and one persistent guidance affordance, neither of which a single-record form needs. This
document specifies the Wizard Template in full; `LAYOUT_SYSTEM.md → Page Templates` should adopt it
verbatim the next time that document is revised, the same way `DASHBOARD.md` flagged its own Dashboard
Template split for later reconciliation rather than silently improvising a one-off layout.

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  qayd            ●───●───●───○───○───○───○───○         [Save & exit] [EN ▾ ☾] │  ← Progress Rail
│                  1   2   3   4   5   6   7   8                                 │
├───────────────────────────────────────────────────────────┬───────────────────┤
│  Step 3 of 8                                                │  ● Ask AI          │
│  Chart of Accounts                                          │                    │
│  Pick a starting point for your ledger — you can rename,    │  "Based on your    │
│  reorder, or add accounts freely afterward.                 │  industry (Retail) │
│                                                              │  and country       │
│  Country template  ·  required                              │  (Kuwait), I'd     │
│  ( ) Kuwait — KW_STANDARD        ( ) Saudi Arabia            │  suggest KW_STANDARD│
│  ( ) UAE — AE_STANDARD                                       │  + RETAIL. 92%     │
│                                                              │  confidence."       │
│  Industry template  ·  optional                              │                    │
│  ( ) Retail   ( ) Trading   ( ) Services  ( ) Manufacturing   │  [ Use this ]      │
│  ( ) Real Estate   ( ) Restaurants   ( ) None                 │  [ Ask something ] │
│                                                              │                    │
│  ┌ Preview — 214 accounts will be created ─────────────────┐ │  (AI Guide Dock,   │
│  │ 1000 Assets            1101.001 Cash on Hand · النقد …  │ │   4/12, collapsible│
│  │ 2000 Liabilities       2101.001 Accounts Payable …       │ │   to an icon rail  │
│  │ …                                                         │ │   below `lg`)      │
│  └───────────────────────────────────────────────────────────┘ │                    │
├─────────────────────────────────────────────────────────────┴───────────────────┤
│                                                        [ Back ]   [ Continue → ] │  ← Footer Action Bar
└───────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Content | Notes |
|---|---|---|
| **Progress Rail** | The `qayd` mark (no company logo yet at early steps), an 8-node `OnboardingProgressRail`, "Save & exit," the locale switcher, and the light/dark toggle | Full width, fixed to the top of the viewport (`z-sticky`); the only chrome present on every one of the eight step routes; renders no company switcher, no notifications bell, no user avatar menu — none of those have a stable target yet before step 2 completes |
| **Step Header** | "Step *N* of 8," the step's own `display-lg` title, and a one-sentence description in `text-lg` | Not a separate layout region so much as the top of the Step Body card — called out separately here because it is where the route-change focus target lands (`# Accessibility`) |
| **Step Body** | The step's own form/content — the region that varies the most across the eight routes, detailed per step in `# Components Used` and `# Interactions & Flows` | Always a single `Card` (`padding="lg"`), never a multi-card sectioned layout the way the Journal Entry Form Page Template is — a wizard step is one decision, not a record with many independent sections |
| **AI Guide Dock** | A persistent, collapsible `col-span-4` rail carrying the narrating agent's message, a confidence-scored suggestion where one applies, an "Use this" / "Keep my own choice" pair, and an "Ask something" free-text entry into a scoped chat | Present on every step but never load-bearing for progress — a user who never opens it can still complete onboarding entirely from the Step Body's own defaults and explicit fields; collapses to a `56px` icon rail below `xl` and to a bottom `Sheet` below `lg` (`# Responsive Behavior`) |
| **Footer Action Bar** | "Back" (secondary, hidden on step 1), "Skip for now" (ghost, only on steps 5–7), "Continue"/"Finish setup" (primary) | Sticky to the bottom of the viewport, mirroring the Form Page Template's sticky footer convention; "Continue" is disabled, never hidden, until the step's own required fields validate, with an inline reason rendered beside it rather than a bare disabled button |

The Progress Rail's 8 nodes render three states — completed (filled, `accent`, a checkmark glyph),
current (`accent` ring, unfilled), upcoming (`ink-6` outline, unfilled) — plus a fourth, skipped (`ink-6`
outline with a small diagonal-line glyph), reserved for steps 5–7 when the owner has explicitly moved
past them without completing them. Clicking a **completed** or **skipped** node navigates directly to
that step (a wizard, unlike a strictly linear form, must let an owner go back and connect the bank they
skipped ten minutes ago); clicking an **upcoming** node does nothing and shows a tooltip naming the
step that must be completed first — this is the one place in Onboarding's own chrome that behaves like a
disabled control with an explanation rather than a silently inert one, consistent with
`DESIGN_LANGUAGE.md`'s "never leave a user staring at a grayed-out control with no explanation" rule.

The AI Guide Dock is deliberately not the platform's global `AI Rail` (`LAYOUT_SYSTEM.md`'s `360px`
companion panel docked in the `(app)` shell) reused verbatim — it cannot be, since most of what the AI
Rail reads (insights, recommendations, risks keyed to a fully operational company) does not exist yet.
It is its own, narrower composition, built from the same `AIProposalPanel`/`ConfidenceBadge` primitives
`COMPONENT_LIBRARY.md` already defines, scoped to exactly one narrating message and one optional
suggestion per step, never a feed of many. This mirrors the same deliberate-narrowing move `DASHBOARD.md`
made for its own AI Summary Rail relative to the full AI Command Center: reuse the primitives, shrink the
scope to what this specific screen actually needs.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue, composed
into a set of new Onboarding-scoped components living in `components/onboarding/`, matching
`PROJECT_STRUCTURE.md`'s one-subfolder-per-module convention. No primitive is hand-rolled, and — per
that document's own contract — a component moves out of `components/onboarding/` into
`components/shared/` the day a second flow needs it; `OnboardingProgressRail`'s step-node visual
language, for instance, is a deliberate candidate for exactly that once a second multi-step flow
(a payroll-run wizard, an import wizard reused outside Onboarding) needs the identical affordance.

| Component | Source | Role on this screen |
|---|---|---|
| `Card`, `Button`, `Input`, `Select`, `RadioGroup`, `Checkbox`, `Textarea`, `Tooltip`, `Badge`, `Skeleton`, `Progress` | `components/ui/*` (existing shadcn primitives) | Step Body shells, form controls, disabled-with-tooltip states, loading placeholders, the Import step's upload/commit progress bar |
| `Combobox`/`Command` + `Popover` | `components/ui/command.tsx`, `components/ui/popover.tsx` (existing, the same pattern `AccountPicker` is built on) | Country, currency, timezone, and fiscal-year-start pickers in `profile`; the role picker in `team` |
| `AmountCell` | `components/accounting/amount-cell.tsx` (existing) | Any currency-formatted preview figure — the base-currency symbol preview beside the Currency picker, opening-balance totals previewed in `import` |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Invitation status (`pending`/`accepted`/`expired`) in `team`; bank account status (`pending_verification`/`active`) in `banking`; import row status (`mapped`/`skipped`/`error`) in `import` |
| `ConfidenceBadge`, `AIProposalPanel` | `components/ai/confidence-badge.tsx`, `components/ai/ai-proposal-panel.tsx` (existing, rendered in a new `compact` + `singular` presentation) | The AI Guide Dock's suggestion card on every step that has one (industry template, numbering pattern, approval chain, account-mapping suggestions during import) |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Per-step empty/error rendering — see `# States` |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` (existing) | Wraps "Send invitations" in `team` and the actual bank-connect action in `banking`, per `# Route & Access`'s permission table |
| `Sheet` | `components/ui/sheet.tsx` (existing) | The "Why do we need this?" explainer (bank connection's data-use disclosure; tax-registration-number's purpose) opened from an inline info icon, never a blocking modal |
| `Toast` (`useApiToast`) | `hooks/use-api-toast.ts` (existing) | Every step's save-and-continue result, invitation-sent confirmation, import-commit summary |
| `OnboardingShell` | `components/onboarding/onboarding-shell.tsx` (**new**) | The root layout's chrome: Progress Rail + AI Guide Dock + `{children}` slot; owns the `useOnboardingStore` Zustand instance (`# Data & State`) |
| `OnboardingProgressRail` | `components/onboarding/onboarding-progress-rail.tsx` (**new**) | The 8-node stepper described in `# Layout & Regions`; pure presentation over a `steps: OnboardingStepState[]` prop, no query of its own |
| `OnboardingStepCard` | `components/onboarding/onboarding-step-card.tsx` (**new**) | The Step Header + Step Body card shell every one of the eight routes renders inside — owns the route-change focus target (`# Accessibility`) |
| `CompanyChoiceForm` | `components/onboarding/company-choice-form.tsx` (**new**) | Step 1's Create/Join toggle plus the two divergent mini-forms (new company name, or invitation-token confirmation) |
| `CompanyProfileForm` | `components/onboarding/company-profile-form.tsx` (**new**) | Step 2 — the full `COMPANY_STRUCTURE.md → Company Information` field set, RHF + Zod |
| `ChartOfAccountsTemplatePicker` | `components/onboarding/chart-of-accounts-template-picker.tsx` (**new**) | Step 3 — country/industry template selection plus the live account-count preview, wired to `GET .../accounts/templates` |
| `NumberingApprovalConfigForm` | `components/onboarding/numbering-approval-config-form.tsx` (**new**) | Step 4 — numbering pattern editor with a live-formatted example, and a starter approval-chain picker |
| `TeamInviteTable` | `components/onboarding/team-invite-table.tsx` (**new**) | Step 5 — a small, non-virtualized, add/remove row table of `{ email, role, branch?, department? }` drafts, not a `DataTable` (this list is at most a few dozen rows entered by hand, never a server-paginated resource) |
| `BankConnectCard` | `components/onboarding/bank-connect-card.tsx` (**new**) | Step 6 — the institution picker, the Open Banking consent hand-off, and the manual-IBAN fallback path |
| `OpeningDataImportWizard` | `components/onboarding/opening-data-import-wizard.tsx` (**new**) | Step 7 — a nested three-stage mini-wizard (Source → Mapping Review → Commit) reusing `CHART_OF_ACCOUNTS.md § 17`'s own import pipeline contract |
| `AiTeamRoster` | `components/onboarding/ai-team-roster.tsx` (**new**) | Step 8 — the fifteen-agent roster grid, grouped by pillar, sourced verbatim from `AI_FINANCE_OS.md → The AI Finance Team` |
| `OnboardingAiGuideDock` | `components/onboarding/onboarding-ai-guide-dock.tsx` (**new**) | The AI Guide Dock region itself — composes `AIProposalPanel` (compact) with a scoped "Ask something" input calling `POST /api/v1/onboarding/ask` |

The fourteen "new" components above are, per `COMPONENT_LIBRARY.md`'s own stated contract, pure
composition — none introduces a new design token, a new permission key beyond the two named in
`# Route & Access`, or a new primitive; each is a documented arrangement of existing primitives and
finance components wired to this flow's own endpoints.

# Data & State

## Endpoints

Onboarding calls four endpoint families: a new, narrow `onboarding` family this document defines because
no other document yet owns it; the existing `accounting/accounts` family `CHART_OF_ACCOUNTS.md` already
specifies in full; the existing `banking` family `BANKING.md` already specifies in full; and a `companies`
family this document defines the minimal slice of, noting explicitly — the same way
`AUTHENTICATION_API.md`'s own `/register` row does — that the full CRUD and settings surface for
`companies` belongs to a future `COMPANIES_API.md` this document does not attempt to write.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Resume — read current progress | `GET /api/v1/onboarding/progress` | Session only | Returns `{ company_id, mode, current_step, steps: { [step]: { status, payload } } }`; `company_id` is `null` before step 1 completes |
| Save a step's draft/result | `PATCH /api/v1/onboarding/progress` | Ownership rule (`# Route & Access`) | Body: `{ step, status: 'in_progress' \| 'completed' \| 'skipped', payload }`; called on blur/debounce for drafts and on "Continue" for completion — see `# Interactions & Flows` |
| Finish setup | `POST /api/v1/onboarding/complete` | Ownership rule | Validates every required step is `completed`, flips `companies.onboarding_status` to `completed`, fires the `company.onboarding.completed` domain event (wakes the fifteen agents' first scheduled runs), returns the target `/dashboard` route |
| Ask the guide a question | `POST /api/v1/onboarding/ask` | Ownership rule | `{ step, question }` → `{ answer, confidence, agent }`; a thin, scoped wrapper the FastAPI AI layer answers from the step's own context only, never from another company's data |
| Create a company | `POST /api/v1/companies` | Session only | `{ name }` minimum; caller becomes `owner_user_id`; returns the new `company_id` step 2 onward is scoped to |
| Update company profile | `PATCH /api/v1/companies/{id}` | Ownership rule | The full `COMPANY_STRUCTURE.md → Company Information` field set; this is the same endpoint Settings → Company later reuses, so nothing about it is onboarding-specific |
| Accept an invitation (Join branch) | `POST /api/v1/invitations/{token}/accept` | Valid token | Per `COMPANY_STRUCTURE.md → Invitations`; short-circuits the rest of Onboarding entirely, per `# Route & Access` |
| List CoA templates | `GET /api/v1/accounting/accounts/templates` | `accounting.accounts.read` | Returns the country templates (`KW_STANDARD`, `SA_STANDARD`, `AE_STANDARD`) and industry templates (`RETAIL`, `TRADING`, `SERVICES`, `MANUFACTURING`, `REAL_ESTATE`, `RESTAURANTS`), per `CHART_OF_ACCOUNTS.md § 8.4–8.5` |
| Apply a CoA template | `POST /api/v1/accounting/accounts/apply-template` | `accounting.accounts.create` | `{ country_template, industry_template? }`; clones template rows into the new `company_id`, transactional, targets under 5 seconds per `CHART_OF_ACCOUNTS.md`'s own G1 goal |
| Enter opening balances | `POST /api/v1/accounting/accounts/opening-balances` | `accounting.accounts.set_opening_balance` | Bulk opening-balance entry; the terminal action of the `import` step's Commit stage when the source includes balances |
| Import CoA / customers / vendors / opening data | `POST /api/v1/accounting/accounts/import` (plus sibling `import` endpoints on `customers`, `vendors`, per those modules' own docs) | `accounting.accounts.import` (+ the target resource's own `.import`) | Partial-success, row-level-validated pipeline per `CHART_OF_ACCOUNTS.md § 17`; `import/page.tsx` is a guided front door onto this exact pipeline, not a parallel one |
| List company roles | `GET /api/v1/roles` | Ownership rule | Feeds `team`'s role picker with the platform's default role set (`PERMISSION_SYSTEM.md → Roles`: Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, HR Manager, Payroll Officer, Inventory Manager, Warehouse Employee, Sales Manager, Sales Employee, Purchasing Manager, Purchasing Employee, Read Only, External Auditor) |
| Send invitations | `POST /api/v1/companies/{id}/invitations` | `company.users.invite` | `{ invitations: [{ email, role_id, branch_id?, department_id? }] }`; batched, partial-success (one bad email never blocks the rest) |
| List/resend/revoke invitations | `GET /api/v1/companies/{id}/invitations`, `POST .../{invitationId}/resend`, `DELETE .../{invitationId}` | `company.users.invite` | Feeds `TeamInviteTable`'s live status column |
| Create a bank account | `POST /api/v1/banking/bank-accounts` | `bank.account.create` | Starts at `status = pending_verification`, per `BANKING.md`'s own controlled-onboarding-flow rule |
| Start Open Banking consent | `POST /api/v1/banking/bank-accounts/{id}/open-banking/connect` | `bank.account.manage` | Redirects out to the institution's consent screen; see `# Interactions & Flows` for the round-trip |
| Verify a bank account | `POST /api/v1/banking/bank-accounts/{id}/verify` | `bank.account.verify` | Moves `pending_verification → active` once micro-deposit/consent/document verification clears |

## Query keys and cache tuning

```ts
// lib/query/keys.ts (onboarding-scoped factories)
export const onboardingKeys = {
  all: ["onboarding"] as const,
  progress: () => [...onboardingKeys.all, "progress"] as const,
  coaTemplates: () => [...onboardingKeys.all, "coa-templates"] as const,
  roles: () => [...onboardingKeys.all, "roles"] as const,
  invitations: (companyId: number) => [...onboardingKeys.all, "invitations", companyId] as const,
  bankAccount: (id: number) => [...onboardingKeys.all, "bank-account", id] as const,
  importBatch: (id: string) => [...onboardingKeys.all, "import-batch", id] as const,
};
```

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Progress (this session's own state) | `onboardingKeys.progress()` | `0` (always stale), `refetchOnMount: "always"` | A second tab or a resumed session must never render a step the server no longer considers current; correctness beats avoiding a refetch here more than anywhere else in the platform, since a stale progress read could let a user re-submit a completed step |
| Reference/template data | `coaTemplates()`, `roles()` | 5 minutes, matching `FRONTEND_ARCHITECTURE.md`'s reference-data class | These change on a QAYD release cadence, not per company |
| Invitations list | `invitations(companyId)` | 10 seconds, `refetchOnWindowFocus: true` | An owner tabbing back after an invitee accepted should see the status flip without a manual refresh |
| Import batch status | `importBatch(id)` | `0`, driven primarily by realtime (below), polled as a 5-second fallback only while the realtime channel is reconnecting | Large imports run as a queued Laravel job; the UI must reflect `processing → completed \| partial \| failed` promptly |

## Realtime

Onboarding subscribes to one company-scoped Reverb channel, opened the moment step 1 produces a
`company_id` and torn down on "Finish setup" or "Save & exit," per the platform's standard private,
company-scoped channel convention:

| Channel | Purpose |
|---|---|
| `private-company.{id}.onboarding` | Invitation-accepted ticks (patches `invitations(companyId)`), import-batch progress/completion (patches `importBatch(id)`), and Open Banking consent-callback completion (patches `bankAccount(id)`) |

Every event on this channel triggers an ordinary `invalidateQueries` against the specific key above —
matching `FRONTEND_ARCHITECTURE.md`'s "invalidate by default" rule — with the single exception of the
import batch's row-count progress ticks, which patch `importBatch(id)` directly via `setQueryData` for a
smooth progress bar and are reconciled by a full invalidation the moment the batch reaches a terminal
state, the same "patch only for high-frequency, low-risk ticks" carve-out `DASHBOARD.md` uses for its own
live Cash Position figure.

## Client state — the one deliberate exception to "wizards don't persist"

`FRONTEND_ARCHITECTURE.md → State Management` states that a multi-step builder store (a journal-entry
draft, a payroll-run wizard's per-step state) is "Zustand-backed but explicitly *not* persisted to
`localStorage`, and is reset on route leave... a half-entered, unbalanced journal entry has no business
surviving a tab close." Onboarding is the platform's one deliberate, documented exception to that rule,
for the opposite reason that rule exists: a journal entry is dangerous to leave lying around
half-finished; a new company's setup is *expected* to span more than one sitting; USER_ONBOARDING.md's
own ten-minute median explicitly coexists with slower outliers who start the Team or Import step, get
interrupted, and return the next day. Onboarding therefore does not lean on `localStorage` persistence
at all — the durable copy of "where was I" lives entirely server-side, in `onboarding_progress`
(`# Route & Access`'s ownership rule reads from the same record), and `useOnboardingStore` holds only
the current step's **in-flight, not-yet-saved** field values plus the AI Guide Dock's open/collapsed
state, both reset on navigation to the next or previous step and rehydrated from the server's `payload`
on mount:

```ts
// lib/stores/onboarding-store.ts
interface OnboardingUiState {
  guideDockCollapsed: boolean;
  toggleGuideDock: () => void;
  draft: Record<string, unknown>;      // current step's in-flight field values only
  setDraft: (values: Record<string, unknown>) => void;
  resetDraft: () => void;              // called on every step transition
}

export const useOnboardingStore = create<OnboardingUiState>((set, get) => ({
  guideDockCollapsed: false,
  toggleGuideDock: () => set({ guideDockCollapsed: !get().guideDockCollapsed }),
  draft: {},
  setDraft: (values) => set({ draft: { ...get().draft, ...values } }),
  resetDraft: () => set({ draft: {} }),
}));
```

No `persist` middleware wraps this store — a browser crash mid-step loses at most the last few seconds
of unsaved keystrokes in that one field group, because every step debounce-saves its draft to
`PATCH /api/v1/onboarding/progress` with `status: 'in_progress'` every 2 seconds of inactivity, which is
the actual resume source of truth, not the Zustand store.

## AI agents feeding this screen

| Agent | Contribution to Onboarding |
|---|---|
| `GENERAL_ACCOUNTANT` | Narrates the Chart of Accounts step; proposes the industry template from the company's declared type/industry; reviews the CoA/customer/vendor rows an import batch stages before commit |
| `TAX_ADVISOR` | Narrates the tax-system field in Company Profile; explains what a chosen country template's `tax_category` placeholders mean before VAT go-live in Kuwait specifically |
| `APPROVAL_ASSISTANT` | Proposes a starter approval chain in the Numbering & Approval Basics step, drawn from the company's declared size (a Freelancer/Startup gets a single-approver default; an Enterprise/Holding gets the two-tier Finance Manager → CFO chain `PERMISSION_SYSTEM.md → Approval Chains` documents) |
| `TREASURY_MANAGER` | Narrates the Connect Bank step; explains the Open Banking consent hand-off and why manual IBAN entry still requires verification |
| `DOCUMENT_AI` / `OCR_AGENT` | Power the Import step's column/value mapping suggestions when the source is a spreadsheet or another ERP's export, per `CHART_OF_ACCOUNTS.md § 17`'s own AI-assisted mapping |
| `CFO` (agent) | Narrates the final "Meet Your AI Finance Team" step's framing — the same synthesis role it plays on Dashboard and the AI Command Center, introduced here for the first time in the company's life |

Every one of the above operates in `suggest_only` mode for the entire duration of Onboarding, with no
exception — even `GENERAL_ACCOUNTANT`'s `auto` posting authority elsewhere in the product (below a
confidence-and-amount threshold, per `AI_FINANCE_OS.md`) is not yet active, because the company has no
posted history for a threshold to be calibrated against. The first transaction any agent may act on
without suggestion is the first one posted after `POST /api/v1/onboarding/complete` returns.

# Interactions & Flows

**Arriving at `/onboarding` for the first time.** Registration and email verification
(`../foundation/AUTHENTICATION.md`) hand off to `/onboarding` the moment a session exists with zero
companies attached. `app/onboarding/page.tsx` calls `GET /api/v1/onboarding/progress`, receives
`{ company_id: null, current_step: 'company' }`, and redirects to `/onboarding/company`. The Progress
Rail renders all eight nodes in their upcoming state except node 1, current.

**Choosing Create vs. Join.** `CompanyChoiceForm` renders two large, equally weighted choice cards — "
Create a new company" and "I have an invitation" — never a pre-selected default, since defaulting either
choice would bias a first-time user who may have arrived from either kind of email. Choosing Create
reveals a single required field (company name) and a "Continue" that calls `POST /api/v1/companies` then
`PATCH /api/v1/onboarding/progress` with `{ step: 'company', status: 'completed', payload: { mode:
'create', company_id } }`, and the Progress Rail immediately relabels every remaining node from
"upcoming" to reflect the Create branch's full eight-step path. Choosing "I have an invitation" reveals a
token field (pre-filled and read-only if the route was reached via a deep-linked `?invite=` query
param from an email) and a "Join" action that calls `POST /api/v1/invitations/{token}/accept`; on success
the entire wizard is bypassed per `# Route & Access` and the user lands on `/dashboard` with a welcome
toast, never seeing node 2 onward render at all.

**Company Profile.** `CompanyProfileForm` renders the full `COMPANY_STRUCTURE.md → Company Information`
field set — Company Name (pre-filled from step 1, editable), Legal Name, Country, City, Currency,
Language, Timezone, Fiscal Year (start month), Tax System, Industry, Company Size, Logo (optional
upload), Business Registration Number, Tax Registration Number — grouped into three visually distinct
clusters inside the one Step Body card (Identity, Locale & Fiscal, Registration), never eight flat
fields in a single column. Country, Currency, and Timezone are `Combobox` pickers backed by the
platform's static reference lists (no network round trip per keystroke); selecting a Country
auto-suggests a matching Currency and Timezone (Kuwait → KWD → `Asia/Kuwait`) as a pre-fill the owner can
still override, never a locked value. "Continue" calls `PATCH /api/v1/companies/{id}` with the full
payload and only then `PATCH .../progress` — the company record itself is the durable copy; the progress
record is bookkeeping on top of it.

**Chart of Accounts Template.** `ChartOfAccountsTemplatePicker` fetches `GET .../accounts/templates` on
mount, pre-selects the Country template matching the Country chosen in step 2 (Kuwait → `KW_STANDARD`,
with `SA_STANDARD`/`AE_STANDARD` available for a company that will also book in Saudi Arabia or the
UAE), and leaves the Industry template unselected until either the owner picks one or accepts the AI
Guide Dock's suggestion (`GENERAL_ACCOUNTANT`'s proposal, derived from the `industry`/company-type field
captured in step 2 — see `# AI Integration` for the exact mapping and its documented gaps). Selecting
any template pair debounces a live preview: a `GET .../accounts/templates/{country}/{industry?}/preview`
call (a read-only, non-mutating projection of the same clone the apply-template endpoint would perform)
renders the account count and a truncated bilingual tree sample inside the Step Body, so the owner sees
what "214 accounts" concretely means before committing to it. "Continue" is the one step in this wizard
whose primary action is itself a financially consequential, not-easily-undone write —
`POST .../accounts/apply-template` clones several hundred rows transactionally — so it renders as
"Create my Chart of Accounts" rather than a bare "Continue," and a confirming inline summary ("This will
create 214 accounts under KW_STANDARD + RETAIL — you can rename or add accounts afterward") sits directly
above the button, matching the platform's "state what happened, why, and what happens next" copy
discipline even for a forward-looking confirmation.

**Numbering & Approval Basics.** `NumberingApprovalConfigForm` presents two independent sub-sections: a
numbering-pattern editor (per-document-type prefix/next-number/padding, e.g. `JE-{YYYY}-{seq:5}` for
Journal Entries, previewed live as "JE-2026-00001") and a starter approval-chain picker (a small set of
named presets — "Single approver," "Two-tier (Finance Manager → CFO)," "None yet — configure later" —
rather than a full visual workflow builder, which belongs to Settings, not Onboarding). Both sub-sections
pre-fill from `APPROVAL_ASSISTANT`'s suggestion keyed to the company size captured in step 2; both remain
fully editable inline. This step is the last of the four **required** Create-branch steps; "Continue"
here is the first point at which the Progress Rail's nodes 5–7 become individually clickable rather than
merely visible.

**Invite Team.** `TeamInviteTable` starts with one empty row (`email`, `role`, optional `branch`,
optional `department`) and an "Add another" affordance capped at 20 rows per batch (a company inviting
more than 20 people at once is directed to Settings → Users' bulk-CSV invite instead, out of this
document's scope). Each row's Role picker is populated from `GET /api/v1/roles`; Branch/Department only
appear once the company has created more than one of each (a brand-new single-branch company never shows
an empty, meaningless picker). "Send invitations" calls `POST /api/v1/companies/{id}/invitations` once
for the whole batch; a partial failure (one malformed or already-registered email) reports inline per
row via the response's `errors[]`, and the rows that succeeded are not resubmitted on retry. This step,
like the two after it, exposes "Skip for now" in the Footer Action Bar — skipping marks
`step: 'team', status: 'skipped'` and the Progress Rail node renders its skipped glyph, remaining
directly clickable from any later step or from `/dashboard` itself via a persistent "Finish setting up
your team" prompt Dashboard's own Recent Activity-adjacent chrome can surface (out of this document's
scope, noted for `DASHBOARD.md`'s own future edge-case consideration).

**Connect Bank.** `BankConnectCard` opens with an institution picker (`BANKING.md`'s own
Commercial/Digital/Petty Cash institution-type taxonomy) and, per institution, either an "Connect with
Open Banking" primary action or a manual IBAN-entry fallback for an institution without a live feed.
Choosing Open Banking calls `POST /api/v1/banking/bank-accounts` (creating the account row at
`pending_verification`) followed immediately by `POST .../{id}/open-banking/connect`, which returns a
redirect URL to the institution's own consent screen — the browser navigates fully away from `/onboarding`
for this step, and the institution's own callback returns the user to
`/onboarding/banking?bank_account_id=…&consent=granted|denied`. A granted consent triggers
`POST .../{id}/verify` server-side as part of the callback handler and the step immediately shows the
account as `active`; a denied or timed-out consent leaves the account at `pending_verification` with a
"Try again" action and a "Add manually instead" fallback that reveals the IBAN-entry form in place. Manual
entry never reaches `active` inside Onboarding itself — per `BANKING.md`'s fraud-prevention rule, a
manually entered account requires micro-deposit or document verification that can take one to two
business days, so this step's own completion criterion is "an account exists and verification has
started," not "an account is active" (`# States` details the resulting card treatment).

**Import Opening Data.** `OpeningDataImportWizard` is a three-stage nested flow inside the one Step Body
card — Source (choose Excel/CSV/PDF or a named ERP export: QuickBooks, Odoo, Zoho, SAP, Oracle, per
`USER_ONBOARDING.md`'s own supported-source list), Mapping Review (the AI-assisted column/value mapping
`CHART_OF_ACCOUNTS.md § 17` already specifies, reused verbatim rather than re-implemented), and Commit
(a real-time progress bar driven by the `private-company.{id}.onboarding` channel, ending in a partial-
success summary: "212 of 214 rows imported — 2 skipped, view details"). A file above roughly 2,000 rows
processes as a background Laravel job the owner may safely navigate away from; the Progress Rail's node 7
shows a small in-progress indicator until the job reaches a terminal state, and the owner is free to
proceed to step 8 or "Finish setup" while an import is still processing in the background — completion
of Onboarding itself never blocks on a long-running import, only on the four required steps.

**Meet Your AI Finance Team.** `AiTeamRoster` renders once every required step is complete; it needs no
input from the owner beyond acknowledgment. "Finish setup" calls `POST /api/v1/onboarding/complete`,
which validates every required step, flips `companies.onboarding_status` to `completed`, and returns the
`/dashboard` redirect target. This is the one Footer Action Bar in the flow whose primary button is
never disabled by an unmet field requirement — only by a required *step* (not this one) being incomplete,
in which case "Finish setup" is replaced by "Complete the required steps first," with the Progress Rail's
still-incomplete required node(s) visually emphasized.

**Save & exit, at any step.** The Progress Rail's "Save & exit" flushes the current step's in-flight
`draft` to `PATCH /api/v1/onboarding/progress` immediately (bypassing the normal 2-second debounce) and
navigates to `/dashboard` if the account has any other operational company, or to a dedicated
"Setup saved — come back anytime" interstitial otherwise, since there is no `/dashboard` to return to
for an account whose only company is still mid-setup.

# AI Integration

Onboarding is the one screen in the platform where the AI layer's job is explicitly pedagogical before it
is ever operational — its purpose is to lower the ten-minute median, not to draft a journal entry or flag
a risk, because neither exists yet. Every rule below is the same platform-wide AI contract
`FRONTEND_ARCHITECTURE.md` and `DESIGN_LANGUAGE.md` state everywhere else, applied to a company that has
no financial history for an agent to reason over yet.

- **No single onboarding mascot — the roster narrates itself, in character, one specialist at a time.**
  Per `DESIGN_LANGUAGE.md`'s "the AI assistant has a mark, not a face," the AI Guide Dock never invents a
  sixteenth "Setup Assistant" persona distinct from the fifteen named agents `AI_FINANCE_OS.md` provisions
  for every company at onboarding. Instead, each step's Dock message is attributed to the real specialist
  who owns that domain in production — `GENERAL_ACCOUNTANT` on the Chart of Accounts step,
  `TAX_ADVISOR` on the tax-system field, `APPROVAL_ASSISTANT` on Numbering & Approval,
  `TREASURY_MANAGER` on Connect Bank, `DOCUMENT_AI`/`OCR_AGENT` on Import, and `CFO` introducing the
  roster itself on the final step — so that the owner's very first interaction with each agent is also
  their most useful one, and "meet your AI finance team" in step 8 is a reunion with five specialists
  already met, not eight strangers plus seven more.
- **Every suggestion carries confidence and reasoning, and pre-fills without locking.** The Chart of
  Accounts step's industry-template suggestion is the canonical example: "Based on your industry (Retail)
  and country (Kuwait), I'd suggest **KW_STANDARD + RETAIL** — 92% confidence," rendered via
  `ConfidenceBadge` with the reasoning available on hover exactly as `COMPONENT_LIBRARY.md` specifies
  everywhere else, and a one-click "Use this" that pre-fills the picker rather than silently applying the
  template — the owner's own explicit "Continue"/"Create my Chart of Accounts" click is still what
  triggers `POST .../apply-template`, never the suggestion's own render.
- **The company-type → industry-template mapping has a documented gap, stated here rather than
  papered over.** `COMPANY_STRUCTURE.md → Company Types` lists a broader taxonomy (Freelancer, Startup,
  SME, Enterprise, Holding Company, Government, Non-Profit, Educational, Healthcare, Retail,
  Manufacturing, Construction, Hospitality, Custom) than `CHART_OF_ACCOUNTS.md`'s six industry templates
  (Retail, Trading, Services, Manufacturing, Real Estate, Restaurants) cover one-to-one. `GENERAL_ACCOUNTANT`'s
  suggestion logic maps Retail→`RETAIL`, Manufacturing→`MANUFACTURING`, Hospitality→`RESTAURANTS`, and
  Healthcare/Educational/Government/Non-Profit/Freelancer/Startup/SME/Enterprise/Holding/Construction all
  fall back to a confidence-capped suggestion of `SERVICES` or, below a 60% confidence floor, no
  suggestion at all — the picker simply renders with nothing pre-selected and the Dock says so plainly
  ("I don't have a confident recommendation for a Non-Profit yet — pick whichever is closest, you can
  always add accounts later") rather than forcing a low-confidence guess on a company type the template
  catalogue was not built to distinguish. This gap is a product backlog item for
  `CHART_OF_ACCOUNTS.md § 8.5` to close with new templates, not a frontend defect to work around with
  invented confidence.
- **No sensitive action is ever one click from an AI suggestion.** Applying a Chart of Accounts template,
  connecting a bank account, and sending invitations are each the owner's own explicit, separately
  labeled action — never a "Do it" button attached directly to a Dock suggestion the way the AI Command
  Center's Top Recommended Actions render for an already-authorized, `can_execute_directly` action. Every
  Dock suggestion in Onboarding is `suggest_only` by construction, matching `# Data & State`'s note that
  no agent operates in `auto` mode anywhere in this flow.
- **The final step is a roster, not a pitch.** `AiTeamRoster` renders all fifteen agents from
  `AI_FINANCE_OS.md → The AI Finance Team` grouped by pillar (Core Accounting, Assurance, Compliance,
  Payroll, Operations, Treasury, Executive, Reporting, Ingestion, Predictive, Governance), each card
  showing the agent's name, one-line mandate, and an autonomy-level chip (`auto` / `suggest_only` /
  `requires_approval`, color-coded via the same `Badge` `tone` axis `StatusPill` already uses elsewhere)
  — never a marketing restatement of "AI-powered" claims. A "Configure agent settings" link on each card
  deep-links to Settings → AI Preferences for later, deliberately deferred out of Onboarding's own
  ten-minute budget rather than turned into a ninth step.

# States

Every step has its own loading, empty, and error presentation, matching the granularity
`DASHBOARD.md` established for its own regions — no step blocks another from being reachable, and the
wizard as a whole never renders a bare spinner as a primary loading state.

| Step | Loading | Empty | Error |
|---|---|---|---|
| Shell (Progress Rail + Dock) | Skeleton rail nodes + a skeleton Dock card while `GET .../progress` resolves | Not applicable — the shell always has eight nodes to render | Shell-level `error.tsx`: "Something interrupted setup — reload this step," never a full-page crash |
| `company` | Two skeleton choice cards | Not applicable | Invalid/expired invitation token renders inline beneath the token field ("This invitation has expired — ask your company admin to resend it"), never a route-level redirect |
| `profile` | Skeleton form matching the three field clusters | Not applicable — always renders the full field set | `422` field errors map onto each field via the standard `useApiToast().fromApiError` pattern `COMPONENT_LIBRARY.md`'s `JournalEntryForm` already uses |
| `chart-of-accounts` | Skeleton template cards + skeleton preview tree while `GET .../templates` resolves | Not applicable — templates are a fixed platform catalogue that always has entries | A failed `apply-template` call (a mid-flight `409` if the company already has posted activity, per `# Edge Cases`) renders an inline retry card; the picker's own selections are preserved, not reset |
| `configuration` | Skeleton numbering-pattern preview + skeleton approval-chain cards | Not applicable | Inline field errors (an invalid numbering pattern token) surface beneath the live preview itself, not as a toast, so the correction target is obvious |
| `team` | Skeleton single row | Zero rows is the *initial* state by design, not an error — the "Add another" affordance is always present | A partially failed batch send renders per-row errors inline (`# Interactions & Flows`); a fully failed send (network loss) preserves every drafted row for retry |
| `banking` | Skeleton institution list | "No institution selected yet" is the natural first render, not an empty-state card — the picker itself is the content | Open Banking consent denied/timed-out renders a dedicated, non-alarming card ("Connection wasn't completed — try again or add the account manually"), never styled as a hard error; a `503` from the aggregator during an outage shows the same "temporarily unavailable" treatment `DASHBOARD.md`'s AI Summary Rail uses for its own upstream-down case |
| `import` | The Commit stage's own `Progress` bar, driven by realtime ticks | "No file selected yet" at the Source stage is the natural first render | A fully failed batch (malformed file, wrong source-system hint) renders `ErrorState` with a "Try a different file" action; a partial-success batch is not an error state at all — it is the expected terminal state, rendered per `# Interactions & Flows`'s summary copy |
| `ai-team` | Skeleton roster cards (15, matching the final grid) | Not applicable — the roster is static, platform-wide content | A failed `POST .../complete` (a required step silently reverted by a race, per `# Edge Cases`) renders inline, naming exactly which required step needs revisiting, with a direct link to it |

A dedicated **resume state** applies across every step the first time a returning session's
`GET .../progress` reports `current_step` other than `company`: the Step Body briefly shows a calm
banner — "Welcome back — resuming where you left off" — above the step's own rehydrated content, dismissed
on the first interaction and never shown again for that session. This is the one state in the flow with
no error/empty counterpart, since resuming is always a success case by construction (a corrupted or
unreadable progress record is treated as a fresh start at `company`, logged server-side, never surfaced
to the owner as a scary error about their own setup).

# Responsive Behavior

Onboarding follows `LAYOUT_SYSTEM.md`'s breakpoint scale exactly, with the Wizard Template's own
per-breakpoint behavior specified here for the first time (for later adoption into
`LAYOUT_SYSTEM.md → Page Templates`, per `# Layout & Regions`):

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | Progress Rail collapses to a single horizontal progress bar plus "Step 3 of 8 · Chart of Accounts" text and a tap target that opens a `Sheet` listing all eight steps by name and state — the 8-node dot rail does not attempt to render at this width. The AI Guide Dock becomes a bottom `Sheet`, collapsed by default, surfaced by a small floating "Ask AI" pill above the Footer Action Bar rather than a persistent column. Footer Action Bar buttons stack full-width, "Continue" on top. |
| `md` (768–1023px) | Progress Rail renders the full 8-node horizontal stepper (node labels hidden, numbers only, full step names in a tooltip on hover/focus); AI Guide Dock remains a bottom `Sheet`. |
| `lg` (1024–1279px) | AI Guide Dock promotes from a bottom `Sheet` to a persistent `col-span-4` side rail beside the Step Body's `col-span-8`; Progress Rail nodes gain visible short labels beneath each dot. |
| `xl`+ (≥1280px) | Full layout as drawn in `# Layout & Regions`'s wireframe; the AI Guide Dock's collapse control becomes a simple icon-rail toggle rather than a full sheet dismissal, matching the global AI Rail's own collapse behavior in the `(app)` shell for visual consistency between the two, even though they are, per `# Layout & Regions`, two structurally different components. |

The Step Body's own internal layout — `CompanyProfileForm`'s three field clusters,
`TeamInviteTable`'s rows, `ChartOfAccountsTemplatePicker`'s template-card grid — uses Tailwind container
queries (`@container`) exactly as `KpiTile` does on Dashboard, so a template card renders two-per-row
inside the narrower `col-span-8` layout at `lg` and three-per-row once the Dock collapses to an icon rail
at `xl`, without a viewport-breakpoint special case for "Dock open" vs. "Dock collapsed." Touch targets
on every step's choice cards, template cards, and Footer Action Bar buttons maintain the platform's
44×44px minimum below `md`, inherited from the shared `Button`/`Card` implementations rather than
re-specified per step.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and `LAYOUT_SYSTEM.md`'s RTL contract, applied
concretely to this flow:

- **Logical properties only, including the Progress Rail itself.** The 8-node rail is built with
  `gap-*`/`ms-*`/`me-*`, never `margin-left`/`margin-right`; under `dir="rtl"` node 1 renders at the
  visual right edge and the "current step" progression reads right-to-left, exactly the way the sidebar
  and every other directional chrome in the product mirrors, with zero Onboarding-specific RTL code.
- **Numerals, currency, and IBANs never mirror.** Every `AmountCell` preview in `chart-of-accounts` and
  `import`, the "Step 3 of 8" counter, and every IBAN entered in `banking` render inside a `dir="ltr"`
  span per `AmountCell`'s existing implementation — an IBAN in particular must never have its digit
  groups reordered by the bidi algorithm inside an Arabic sentence, since a single transposed digit is a
  wrong bank account.
- **The account-tree preview in `chart-of-accounts` follows the same bilingual convention
  `CHART_OF_ACCOUNTS.md` mandates.** Every previewed account renders `code · name_en · name_ar` regardless
  of interface language (e.g., "1101.001 · Cash on Hand · النقد في الصندوق"), never only the field
  matching the active locale, so a bilingual bookkeeper reviewing the template before committing to it
  can verify both names at once.
- **The AI Guide Dock's attributed-agent framing is authored, not translated, per language.** Consistent
  with `DESIGN_LANGUAGE.md`'s Arabic voice discipline, `GENERAL_ACCOUNTANT`'s Chart of Accounts narration
  and every other agent's onboarding-specific copy is written directly in professional Gulf-business
  Arabic by a fluent writer, not machine-translated from the English strings below and shipped as-is.

| Context | English | Arabic |
|---|---|---|
| Step counter | Step 3 of 8 | الخطوة 3 من 8 |
| CoA suggestion | Based on your industry (Retail) and country (Kuwait), I'd suggest KW_STANDARD + RETAIL — 92% confidence. | بناءً على نشاطكم التجاري (تجزئة) ودولتكم (الكويت)، أقترح دليل الحسابات KW_STANDARD مع نموذج RETAIL — بثقة 92%. |
| Primary action (CoA step) | Create my Chart of Accounts | إنشاء دليل الحسابات |
| Skip affordance | Skip for now | تخطي الآن |
| Save & exit | Save & exit | حفظ والخروج |
| Resume banner | Welcome back — resuming where you left off. | مرحبًا بعودتك — سنكمل من حيث توقفت. |
| Import summary | 212 of 214 rows imported — 2 skipped, view details. | تم استيراد 212 من أصل 214 صفًا — تم تخطي 2، عرض التفاصيل. |

# Dark Mode

Onboarding introduces no new color, elevation, or radius token — every surface resolves through the same
token remap `COMPONENT_LIBRARY.md` defines, applied here for the first time to a Wizard Template rather
than a data-dense one:

- **The Progress Rail's completed-node fill uses `accent-600`** (lifted to its dark-mode value per the
  same rule every other accent usage in the product follows), its current-node ring uses the same token
  at a lower opacity, and its upcoming/skipped nodes use `ink-150`/`ink-300` respectively — never a
  bespoke "onboarding blue" or a second accent invented for this one flow.
- **The AI Guide Dock uses the same `accent-subtle`/`border-s-2 border-s-accent` provenance treatment**
  every AI-touched surface in the product uses, so an owner who later sees that exact left-border-plus-
  badge language on the AI Command Center recognizes it as the same signal, not a coincidence of shared
  CSS.
- **Step Body cards use `bg-surface`/`bg-ink-100`-equivalent backgrounds** with elevation getting
  *lighter*, not darker, in dark mode, matching the platform's stated "physical light" strategy — a Step
  Body card sits one step lighter than the canvas behind the Progress Rail, exactly as a `KpiTile` does
  on Dashboard.
- **The Chart of Accounts preview tree and the Import mapping table** use the same hairline-`ink-150`
  row-border convention `DESIGN_LANGUAGE.md`'s Data Density section specifies for every dense table in
  the product, remapped for dark-mode contrast identically — Onboarding's two data-adjacent moments do
  not invent a lighter-weight table style just because they sit inside a wizard rather than a list page.

Every Storybook story for the fourteen new components in `# Components Used` ships the platform's
standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this route's
own Playwright suite captures the same four-way screenshot set at each of the eight step URLs, per
`FRONTEND_ARCHITECTURE.md`'s testing convention.

# Accessibility

Onboarding targets WCAG 2.1 AA as a floor, identically in both languages and both themes, with
particular weight on stepper semantics — this flow is, by construction, the first sustained keyboard-
and-screen-reader experience a new owner has with the product, and a confusing stepper here sets the
tone for the whole relationship.

- **The Progress Rail is a real navigation landmark, not a visually-implied row of dots.** It is marked
  up as `<nav aria-label="Onboarding progress">` containing an ordered list (`<ol>`) of eight `<li>`
  items, each an interactive element (a `<button>`, never a bare `<div>`) when its state is `completed`
  or `skipped`, and a non-interactive, `aria-disabled="true"` element when `upcoming` — matching
  `# Layout & Regions`'s click-behavior rule exactly, so the accessible affordance and the pointer
  affordance never disagree. The current step carries `aria-current="step"`, not merely a visual ring,
  and each item's accessible name states both its position and its state out loud — "Step 3, Chart of
  Accounts, current" / "Step 5, Invite Team, skipped" / "Step 7, Import Opening Data, upcoming, requires
  Step 4 first" — so a screen-reader user gets the same "why is this one inert" explanation a sighted
  user gets from the tooltip in `# Layout & Regions`.
  ```tsx
  <nav aria-label={t("onboarding.progressNav")}>
    <ol className="flex items-center gap-2">
      {steps.map((step, i) => (
        <li key={step.key}>
          {step.state === "upcoming" ? (
            <span aria-disabled="true" className="…">
              <VisuallyHidden>{t("onboarding.stepUpcoming", { n: i + 1, name: step.label, blocker: step.blockedBy })}</VisuallyHidden>
            </span>
          ) : (
            <button
              type="button"
              aria-current={step.state === "current" ? "step" : undefined}
              onClick={() => router.push(step.href)}
            >
              <VisuallyHidden>{t(`onboarding.step${capitalize(step.state)}`, { n: i + 1, name: step.label })}</VisuallyHidden>
            </button>
          )}
        </li>
      ))}
    </ol>
  </nav>
  ```
- **Focus management on every step transition.** Clicking "Continue," "Back," a Progress Rail node, or
  "Skip for now" navigates to a new route (never an in-place content swap hidden from assistive tech),
  and focus lands on that step's own `<h1>` (the Step Header title inside `OnboardingStepCard`) via the
  platform's standard route-change focus-reset — a screen-reader user always hears "Step 4 of 8,
  Numbering & Approval Basics" immediately after activating "Continue," never silence followed by a
  form they have to discover by tabbing.
- **The 2-second debounce-save never fires a live region announcement.** Per `# Data & State`'s draft-
  autosave behavior, an in-progress field-level save is silent — no toast, no `aria-live` chatter — because
  announcing "Saved" every two seconds while someone is still typing their company's legal name is noise,
  not information; only the step-completing save on "Continue" and the terminal states in `# States`
  (import summary, invitation batch result) use `aria-live="polite"` regions, matching
  `DASHBOARD.md`'s "realtime updates announce politely, never assertively" rule applied to Onboarding's
  own slower cadence of events.
- **Every required field states its requirement in text, not asterisk-only.** `CompanyProfileForm`'s
  labels read "Legal Name (required)" in the accessible name, with the visual asterisk as a secondary,
  sighted-only reinforcement — consistent with the platform's error-copy discipline of never relying on a
  single non-textual cue.
- **The Open Banking redirect round-trip is announced on return.** Because `banking`'s consent flow
  genuinely leaves the page (`# Interactions & Flows`), the callback return renders a clear, immediately
  focused heading state ("Connection successful — Al Ahli Bank account added" or "Connection wasn't
  completed") rather than silently restoring the pre-redirect view with no acknowledgment that a
  round-trip happened at all.
- **Color is never the only channel for step state.** The Progress Rail's completed/current/upcoming/
  skipped states are each paired with a distinct glyph (check / ring / plain outline / diagonal-line),
  never distinguished by hue alone, so a colorblind owner or a grayscale screenshot of this flow loses no
  information a sighted, color-normal user has.
- **Keyboard path.** Tab order on every step flows Progress Rail → Step Header (non-interactive) → Step
  Body's own fields in visual order → AI Guide Dock's suggestion actions and "Ask something" input →
  Footer Action Bar ("Back" → "Skip for now" if present → "Continue"/primary). No step requires a mouse to
  reach or activate any control, including the Chart of Accounts template cards (`RadioGroup`-based, full
  arrow-key navigation) and the Team step's per-row remove buttons.

# Performance

- **The ten-minute median is the budget every other number here is held against.** `USER_ONBOARDING.md`
  states it as the platform's own success criterion for this flow; this document's own obligation is that
  no individual step's network or rendering cost is what erodes that budget for a company with an
  ordinary amount of data to enter.
- **Chart of Accounts apply targets under five seconds,** per `CHART_OF_ACCOUNTS.md`'s own G1 business
  goal for `apply-template` — `ChartOfAccountsTemplatePicker`'s "Continue" button enters a `loading` state
  (per `COMPONENT_LIBRARY.md`'s `Button` `loading` prop, never a separate full-page spinner) for the
  duration of that one call, with a determinate-feeling message ("Creating your Chart of Accounts…")
  rather than an indeterminate one, since the actual bound is well understood and stated in the owning
  document.
- **Reference data prefetches ahead of need.** `GET .../accounts/templates` and `GET /api/v1/roles` are
  prefetched by the `company`/`profile` steps' own Server Components the moment `mode: 'create'` is known
  — well before the owner reaches `chart-of-accounts` or `team` — via `queryClient.prefetchQuery`, so
  those two steps' own first paint never waits on a network round trip that could have started two steps
  earlier.
- **The next step's route bundle prefetches on the current step's mount,** using Next.js's built-in
  `<Link prefetch>` behavior on the Progress Rail's own completed/current nodes, so "Continue" from
  `configuration` to `team` never shows a route-level loading flash for the bundle itself, only for that
  step's own data.
- **The Import step's mapping UI and any bundled parsing library (`xlsx`, PDF-extraction client code) are
  code-split via `next/dynamic({ ssr: false })`,** exactly as `RevenueExpenseChart`'s charting dependency
  is on Dashboard — a company that never opens the Import step (having skipped it) never downloads that
  weight at all.
- **The AI Guide Dock's "Ask something" input debounces at 400ms** before calling `POST .../ask`, and its
  own response is capped to a short, scoped answer (no open-ended chat history to stream or persist across
  steps) — this is a narrow, fast utility, not a chat surface competing with the step's own primary task
  for attention or bandwidth.
- **Web Vitals for this route are tracked against its own baseline**, distinct from `(app)` routes,
  since a brand-new session with an empty `QueryClient` and no cached session context has a structurally
  different first-load profile than a returning user's warm `/dashboard` — comparing Onboarding's LCP
  against Dashboard's would produce a false regression signal on every release.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| The browser is closed mid-step and the owner returns days later, possibly on a different device | `GET /api/v1/onboarding/progress` is the sole source of truth on every load — there is no local-only state to lose (`# Data & State`). The Resume banner (`# States`) renders once, the step's fields rehydrate from the server's last-saved `payload`, and the Progress Rail reflects exactly the state left behind, regardless of which device or browser profile returns. |
| Two admins with `company.onboarding.manage` (the Owner and a delegated implementation partner) work on the same company's setup from two tabs at once | Every `PATCH .../progress` write is last-write-wins per field group, matching the platform's general absence of field-level optimistic locking on draft data (only *posted* financial records get that rigor). The realtime `.onboarding` channel patches the other tab's view within moments of any completed step, so the two rarely diverge for long, but a genuine simultaneous edit to the same field group resolves to whichever save reaches the server last — acceptable here specifically because pre-completion onboarding data carries no financial consequence yet, unlike a posted journal entry. |
| A "Join Existing Company" invitation token points at a company whose own owner has not yet finished steps 2–4 | `POST /api/v1/invitations/{token}/accept` is rejected with `422 COMPANY_NOT_OPERATIONAL` — per `COMPANY_STRUCTURE.md`'s Invitations flow, a company cannot send invitations until it has completed its own required setup, so this state should be structurally unreachable through the normal UI; the error exists defensively for a manually crafted or replayed request, and renders as "This company isn't ready for new members yet — ask the owner to finish setup first," never a raw error code. |
| The owner returns to `chart-of-accounts` and changes the template selection after opening balances have already been entered via the `import` step | `POST .../accounts/apply-template` is rejected with `409 ACCOUNTS_ALREADY_POSTED` the moment any account in the company has a non-zero `journal_lines` reference (which an entered opening balance creates, per `CHART_OF_ACCOUNTS.md` BR-6/BR-7) — the step renders this as "Your Chart of Accounts already has activity — changing templates now could affect posted balances. Manage individual accounts from Settings instead," with a link out to the full Chart of Accounts screen rather than pretending the wizard step can still safely mutate the tree wholesale. |
| An invited email address already belongs to a user account in a *different* QAYD company | `POST .../invitations` succeeds regardless — per `COMPANY_STRUCTURE.md → Multiple Companies`, a single user may belong to more than one company, and accepting this invitation simply adds the new company to that user's existing `CompanySwitcher` list; no special-casing is needed on the frontend, since the backend's own multi-company model already expects this. |
| The Open Banking consent screen is denied, closed, or times out mid-redirect | The callback route still returns the browser to `/onboarding/banking?consent=denied`, which renders the calm "Connection wasn't completed" state from `# States` rather than a generic error page — the bank account row created at the start of the flow remains at `pending_verification` and is either retried or abandoned in favor of the manual-IBAN path, never silently deleted, so a partially-started connection is always visible and resumable rather than vanishing. |
| A CSV/Excel import file exceeds the platform's row limit for a single batch | `POST .../accounts/import` rejects the whole upload with `422 IMPORT_FILE_TOO_LARGE` before staging any rows, per `CHART_OF_ACCOUNTS.md § 17`'s partial-success model, which operates on a already-accepted staging batch, not an oversized raw file; `OpeningDataImportWizard`'s Source stage surfaces the exact row-count ceiling and offers "Split your file and import in batches" guidance rather than a bare rejection. |
| The owner skips `team`, `banking`, and `import` entirely and clicks "Finish setup" from `ai-team` | This is a fully supported, first-class path, not a degraded one — per `# Route & Access`, only steps 1–4 and the visiting-not-completing of step 8 are required. `POST .../complete` succeeds, `/dashboard` renders with its own ordinary empty states for zero bank accounts and zero teammates (per `DASHBOARD.md`'s own empty-state contract for a "brand-new company with no posted journal lines"), and Dashboard's own chrome — not Onboarding's — is responsible for any later nudge back toward finishing those optional steps. |
| The user's session expires (access + refresh token both lapse) mid-step, most plausibly during a long Import commit left running in the background overnight | The next authenticated request following expiry redirects to `/login` per `FRONTEND_ARCHITECTURE.md`'s middleware rule; on re-authentication, `middleware.ts`'s existing post-login redirect target logic sends the user back to `/onboarding` (which itself resolves the correct current step via `GET .../progress`) rather than to `/dashboard`, since `companies.onboarding_status` is still `in_progress`. A background import job is entirely server-side and unaffected by the owner's own session lapsing — it completes independently and the realtime channel simply has no listener until the owner's session resumes and re-subscribes. |
| The owner switches the interface language (EN ↔ AR) partway through a step with unsaved draft field values | The locale switcher in the Progress Rail triggers the same `<html dir>` flip every other screen uses; in-flight `draft` values in `useOnboardingStore` are preserved verbatim (a company's legal name typed in Arabic script does not need re-entry just because the interface direction changed), and every `Combobox`'s already-selected option (Country, Currency, Timezone) re-renders its bilingual label in the new language without losing the underlying selected value, matching `AccountPicker`'s existing bilingual-value/localized-label separation. |
| A second browser tab completes "Finish setup" while the first tab is still sitting on an earlier step | The first tab's next `PATCH .../progress` or `POST .../complete` call receives the now-`completed` company state back in the response envelope; rather than erroring, the step in the first tab renders a one-time interstitial — "Setup was already finished in another tab" — with a single "Go to Dashboard" action, avoiding a confusing attempt to keep configuring a company that the product already considers operational. |

# End of Document
