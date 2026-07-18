# Settings — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: SETTINGS
---

# Purpose

`FRONTEND_ARCHITECTURE.md`'s own route tree already fixes eight files under `app/(app)/settings/`:
`layout.tsx`, `company/page.tsx`, `branches/page.tsx`, `departments/page.tsx`, `users/page.tsx`,
`roles/page.tsx` ("Permission Studio (see `PERMISSION_SYSTEM.md`, Long-Term Vision)"), `api-keys/page.tsx`,
`webhooks/page.tsx`, and `billing/page.tsx`. This document is authoritative for what those eight files
render, and it extends the fixed tree with seven more the platform has needed all along but never named a
route for — `accounting/page.tsx`, `tax/page.tsx`, `numbering/page.tsx`, `approvals/page.tsx`,
`security/page.tsx`, and `ai/page.tsx` (`notifications` deliberately gets no file of its own; see below).
Nothing here contradicts the fixed tree — every one of its eight files keeps exactly the job already
implied by its name — this document only fills in what each renders and adds the sibling files a
twelve-section configuration hub genuinely requires. This is the same "extend, do not bypass" discipline
`NOTIFICATIONS.md` and `SEARCH.md` already applied to their own previously-unimplemented routes.

Settings is not one screen; it is **fifteen independently-permissioned `Form Page Template` instances**
(`LAYOUT_SYSTEM.md → Page Templates`) sharing one shell, one Section Nav, and one design language, grouped
into twelve conceptual sections a user thinks of as "Settings": **General** (company profile, logo, fiscal
year, currency, language, timezone), **Accounting** (posting/rounding/dimension defaults), **Tax** (default
tax treatment), **Numbering** (document sequence configuration), **Approval Workflows** (the visual builder
over `approval_chains`/`approval_chain_steps`), **Users & Roles** (membership, invitations, RBAC),
**Branches & Departments** (org structure), **Security** (MFA, password/session policy, active sessions,
emergency lock), **Integrations** (API keys and webhooks), **Notifications** (a link, not a page — see
below), **AI Preferences** (autonomy defaults), and **Billing/plan**. Every one of the fifteen files is a
real, independently-routable, independently-permissioned page — this is deliberate: a Sales Employee who
somehow lands on `/settings/billing` gets a `403` boundary exactly as if they had typed the URL for another
company's data, not a client-side redirect that merely hides a tab.

One fact governs more of this document than any other, and it is not implicit — `AUTHORIZATION_API.md`'s
own **Always-sensitive operations** table (`# Policies → 1`) lists `settings.company.manage` as a company
settings change requiring a mandatory, system-minimum, single-step **Owner** approval chain, in the same
row as bank transfers, payroll release, and tax submission. That means General, Accounting, Tax, and
Numbering are the one part of Settings where clicking "Save" **does not** produce an instant, optimistic
write — it produces a `draft` proposed changeset routed through the identical `approval_chains` engine
`AUTHORIZATION_API.md → Policies` already specifies for every other sensitive action in the platform. This
document resolves the one question that fact leaves implicit — what happens when the initiator already
holds the chain's sole approver role, the common case for the single-owner SME `COMPANY_STRUCTURE.md`'s own
worked examples describe — in `# Interactions & Flows`, and every downstream section (`# Data & State`,
`# States`, `# Edge Cases`) is written consistently with that resolution. `auth.roles.manage`/
`users.role.assign` (Users & Roles) and `auth.apikey.create`-with-write-scope (Integrations) are the same
kind of always-sensitive gate and are treated identically throughout.

Settings also reconciles three smaller, real facts from sibling documents rather than silently picking a
side. First, `NOTIFICATIONS.md` already built a complete, fully-specified Preferences surface at
`app/(app)/notifications/preferences/page.tsx`, reachable from the Topbar bell and the `UserMenu`; this
document adds Settings as a **third** entry point to that exact page — no new component, no new endpoint,
no `app/(app)/settings/notifications/page.tsx` file, because duplicating an already-shipped preferences
matrix would be exactly the kind of novelty `DESIGN_LANGUAGE.md`'s Principle 8 ("consistency compounds;
novelty costs") exists to prevent. Second, `roles/page.tsx` is explicitly named in the fixed route tree as
"Permission Studio", and `PERMISSION_SYSTEM.md → Long-Term Vision` describes that Studio as future work
("a visual interface where administrators can build custom roles by enabling or disabling hundreds of
permissions without writing code"); this document ships the v1 the Studio grows from — assign existing
system roles, grant a scoped subset of permissions to a new custom role from a flat checklist — and is
explicit in `# Edge Cases` about exactly where v1 stops and the full Studio begins. Third, the platform's
runtime approval-queue table is named `ai_approval_requests` by every screen document that already renders
one (`AI_COMMAND_CENTER.md`, `NOTIFICATIONS.md`, `TRIAL_BALANCE.md`, a dozen module screens, and
`ICONOGRAPHY.md`'s own Semantic Icon Set), while `AUTHORIZATION_API.md`'s backend-side prose names the same
resource `approval_requests` at `GET/PATCH /api/v1/auth/approvals`. This document treats the two as the
same table under a naming drift between an API-layer doc and its many frontend consumers, and — because a
dozen already-shipped documents depend on the shorter, unprefixed form — follows the frontend's own
convention (`ai_approval_requests`, `GET/POST /api/v1/approvals`) everywhere an approval **row** is
rendered, while citing `AUTHORIZATION_API.md`'s literal path (`/api/v1/auth/approvals`,
`/api/v1/auth/approval-chains`) for the **configuration** endpoints this screen is the first to expose,
since no sibling frontend document has cited those yet.

A second, smaller naming drift follows the identical shape and is resolved the identical way.
`AUTHENTICATION_API.md`'s own Endpoint Inventory names the API-key CRUD `auth.apikey.create`/`.read`/
`.revoke` at `POST/GET/PATCH/DELETE /api/v1/api-keys`, while `AUTHORIZATION_API.md`'s Scopes section names
the same capability `auth.api_keys.manage` at `/api/v1/auth/api-keys`. This document follows
`AUTHENTICATION_API.md`'s literal, already-fixed spelling for every API-key CRUD call in `# Data & State`
and `# Interactions & Flows` (`auth.apikey.*`, unprefixed `/api-keys`), since that document is the one
explicitly scoped to this resource's lifecycle, while citing `AUTHORIZATION_API.md`'s own always-sensitive
table for the one fact only it states — that creating a key with any write scope requires a CFO-tier
approval step — without adopting that table's differently-spelled key or path for anything else.

Two format notes before the sections proper. First, "the caller" below means whichever authenticated user
is viewing Settings for the currently active company (`X-Company-Id`); Settings carries no personal/global
scope of its own — switching company (`POST /api/v1/auth/switch-company`) always resets every Settings tab
to that company's own rows, per the platform's company-switch contract (`FRONTEND_ARCHITECTURE.md →
Impersonation banner`, `AUTHORIZATION_API.md → Tenant Isolation → 7. Company switching`). Second, every
role name below is the platform's fixed eighteen-entry roster (`PERMISSION_SYSTEM.md → Roles`: Owner, CEO,
CFO, Finance Manager, Senior Accountant, Accountant, Auditor, HR Manager, Payroll Officer, Inventory
Manager, Warehouse Employee, Sales Manager, Sales Employee, Purchasing Manager, Purchasing Employee, Read
Only, External Auditor, Custom Role) — role tables below collapse it into six representative tiers
(Owner/CEO/CFO; Finance Manager; Senior Accountant/Accountant; HR Manager/Payroll Officer; the six
operational Manager/Employee pairs; Auditor/External Auditor/Read Only) the same way `TRIAL_BALANCE.md`
already does, purely for table width — the underlying grant is always checked per literal `roles.code`.

# Route & Access

## File tree (fixed files unchanged; seven additions marked)

```
app/(app)/settings/
├── layout.tsx                # fixed — Section Nav shell (see # Layout & Regions)
├── company/page.tsx          # fixed — General
├── accounting/page.tsx       # NEW    — Accounting defaults
├── tax/page.tsx               # NEW    — Tax defaults
├── numbering/page.tsx         # NEW    — Numbering Sequences
├── approvals/page.tsx         # NEW    — Approval Workflows builder
├── users/page.tsx              # fixed — Users & Roles (Users half)
├── roles/page.tsx              # fixed — Users & Roles (Roles / Permission Studio half)
├── branches/page.tsx           # fixed — Branches & Departments (Branches half)
├── departments/page.tsx        # fixed — Branches & Departments (Departments half)
├── security/page.tsx           # NEW    — Security
├── api-keys/page.tsx           # fixed — Integrations (API Keys half)
├── webhooks/page.tsx           # fixed — Integrations (Webhooks half)
├── ai/page.tsx                  # NEW    — AI Preferences
└── billing/page.tsx             # fixed — Billing/plan
```

`/settings/approvals` is distinct from the top-level `/approvals` Approval Center queue
(`FRONTEND_ARCHITECTURE.md`'s route tree) — the queue renders live `ai_approval_requests` rows a user
decides on; `/settings/approvals` configures the `approval_chains`/`approval_chain_steps` templates that
*produce* those rows. `/settings/tax` is distinct from `/tax/codes` (the full tax-code/rate CRUD table
`TAX.md` owns) and `/settings/ai` is distinct from `/ai/*` (the AI Command Center's own panels) for the
identical reason: Settings configures, the module screen operates.

## Tab strip → route → permission

| Tab (Section Nav label) | Route(s) | View permission | Manage permission | Notes |
|---|---|---|---|---|
| General | `/settings/company` | `settings.company.read` | `settings.company.manage` | Always-sensitive (Owner chain) |
| Accounting | `/settings/accounting` | `settings.company.read` | `settings.company.manage` | Same gate as General — one configuration surface, two tabs |
| Tax | `/settings/tax` | `settings.company.read` | `settings.company.manage` | Defaults only; full tax-code CRUD links out to `/tax/codes` |
| Numbering | `/settings/numbering` | `settings.company.read` | `settings.company.manage` | |
| Approval Workflows | `/settings/approvals` | `auth.policies.read` | `auth.policies.manage` | System-minimum chains cannot be deleted, only extended |
| Users & Roles | `/settings/users`, `/settings/roles` | `users.read` | `users.invite`, `users.manage`; Roles tab additionally needs `auth.roles.manage`/`users.role.assign` | Two files, one tab, inner `Tabs` |
| Branches & Departments | `/settings/branches`, `/settings/departments` | `branches.read`, `departments.read` | `branches.manage`, `departments.manage` | Two files, one tab, inner `Tabs` |
| Security | `/settings/security` | `settings.security.read` | `settings.security.manage`; session revocation needs `auth.session.manage_others`; lock needs `settings.security.emergency_lock` | Three distinct manage-tier permissions inside one tab |
| Integrations | `/settings/api-keys`, `/settings/webhooks` | `integrations.read` | `auth.apikey.create`/`auth.apikey.revoke` (keys, always-sensitive), `integrations.webhook.manage` (webhooks) | Two files, one tab, inner `Tabs` |
| Notifications | external → `/notifications/preferences` | none (self-scoped) | none (self-scoped) | Not a Settings file — see `# Purpose` |
| AI Preferences | `/settings/ai` | `ai.autonomy.read` | `ai.autonomy.manage` | Always-sensitive operations stay pinned to `requires_approval` for AI regardless of this tab |
| Billing/plan | `/settings/billing` | `companies.billing.read` | `companies.billing.manage` | Owner-tier only in the default role grants |

Every permission above follows the `<area>.<action>`/`<area>.<entity>.<action>` grammar
`AUTHORIZATION_API.md → Permissions → 1` fixes, using the exact literal keys already cited elsewhere in the
platform wherever one exists (`settings.company.manage`, `auth.policies.manage`, `auth.roles.manage`,
`users.role.assign`, `auth.session.manage_others`, `auth.apikey.create`/`read`/`revoke`,
`ai.autonomy.manage`) and introducing only the small, clearly-scoped remainder this document is the first
to need (`settings.company.read`, `auth.policies.read`, `users.read`/`invite`/`manage`,
`branches.read`/`manage`, `departments.read`/`manage`, `settings.security.read`/`manage`,
`settings.security.emergency_lock`, `integrations.read`, `integrations.webhook.manage`/`read`,
`ai.autonomy.read`, `companies.billing.read`/`manage`) — each a `read` companion to an already-fixed
`manage` key, or a `manage` key inside one of `PERMISSION_SYSTEM.md → Permission Categories`' own eleven
named areas (Settings, Users, Branches, Departments, Integrations, Companies), never a new category.

No control on any tab is ever silently omitted the way `SEARCH.md`'s export-menu item is — a Settings tab
a caller cannot view is grayed in the Section Nav with a lock glyph and a tooltip naming the missing
permission (its *existence* is not sensitive information; every company has Billing), while a specific
field or button inside a tab the caller **can** view but not **manage** renders via
`<Can permission="…" fallback={<DisabledWithTooltip .../>}>` exactly as `COMPONENT_LIBRARY.md`'s `Can`
already does everywhere else.

## Role grants (six representative tiers; `manage` column implies `read`)

| Tab | Owner/CEO/CFO | Finance Mgr | Sr. Accountant/Accountant | HR Mgr/Payroll Officer | Inventory/Sales/Purchasing Mgr | Operational Employee tier | Auditor/Ext. Auditor/Read Only |
|---|---|---|---|---|---|---|---|
| General | manage | read | read | — | — | — | read |
| Accounting | manage | manage | read | — | — | — | read |
| Tax | manage | manage | read | — | — | — | read |
| Numbering | manage | manage | read | — | — | — | read |
| Approval Workflows | manage | read | — | — | — | — | read |
| Users & Roles | manage | read (Users), no Roles access | — | HR Mgr: read (Users) | — | — | — |
| Branches & Departments | manage | manage | read | HR Mgr: manage (Departments) | read | — | read |
| Security (own factors) | manage | manage | manage | manage | manage | manage | manage |
| Security (others' sessions, policy, lock) | manage | read-only view | — | — | — | — | — |
| Integrations | manage | read | — | — | — | — | — |
| AI Preferences | manage | read | — | — | — | — | read |
| Billing/plan | manage | — | — | — | — | — | — |

Two rows deliberately split "own" from "others'": every role manages **its own** MFA factors, sessions, and
notification preferences with no permission check at all (a Warehouse Employee enrolls their own
authenticator app the same way an Owner does) — only viewing or acting on **someone else's** security
posture, or the company-wide policy/lock controls, is gated. Finance Manager's Security row is
"read-only view" rather than blank because `PERMISSION_SYSTEM.md`'s Sensitive Operations list makes an
emergency lock and a security-policy tightening Owner-tier decisions, but a Finance Manager routinely needs
to *see* the active-sessions and recent-`security_events` panels during an incident, per the platform's
"visibility is broader than control" default.

# Layout & Regions

Twelve sections do not fit a horizontal `Tabs` row without overflow-scrolling on every viewport from tablet
upward — the platform's ordinary in-module `Tabs` convention (`COMPONENT_LIBRARY.md`'s underline-indicator
style, used for `familyHome/familySchedule/…`-style switching and for Notifications' own two-tab
`Inbox`/`Preferences` strip) genuinely does not scale to twelve destinations without becoming its own
scrollable, hard-to-scan list. Settings is therefore the first screen in the platform to extend the `Form
Page Template` with a new named region rather than reuse the horizontal tab strip — a grouped, vertical
**Section Nav** rail, exactly the kind of extension `LAYOUT_SYSTEM.md → Page Templates` explicitly
permits ("if a new screen doesn't fit, the template is extended... not bypassed"). Four groups, in this
fixed order, each a plain-text, uppercase, non-interactive group label above its own items — never a second
level of nested navigation chrome:

```
┌──────────────┬──────────────────────────────────────────────────────────────┐
│ Settings      │  General                                                     │  ← Page Header
├──────────────┤  Company profile, fiscal year, currency, language, timezone   │
│ COMPANY       │ ┌──────────────────────────────────────────────────────────┐ │
│ ▸ General     │ │ Logo        [🖼  Upload]                                  │ │
│   Accounting  │ │ Legal name (EN)  [___________________]                    │ │
│   Tax         │ │ Legal name (AR)  [___________________]                    │ │
│   Numbering   │ │ Country     [Kuwait ▾]      Base currency  [KWD — locked] │ │
├──────────────┤ │ Fiscal year starts  [January ▾]                            │ │
│ ACCESS        │ │ Default language  [English ▾]  Timezone [Asia/Kuwait ▾]   │ │
│   Users&Roles │ └──────────────────────────────────────────────────────────┘ │
│   Branches    │                                        [Cancel]  [Save]     │
│   Security    │                                                              │
├──────────────┤                                                              │
│ AUTOMATION    │                                                              │
│   Approvals   │                                                              │
│   AI Prefs    │                                                              │
├──────────────┤                                                              │
│ PLATFORM      │                                                              │
│   Integrations│                                                              │
│   Notif. ↗    │                                                              │
│   Billing     │                                                              │
└──────────────┴──────────────────────────────────────────────────────────────┘
```

**Section Nav** (`3/12` at `lg`+, per `LAYOUT_SYSTEM.md`'s 12-column grid) is a `<nav aria-label="Settings
sections">` of real `<Link>`s, one per tab — never client-side tab state — so every tab is independently
bookmarkable, back-button-safe, and deep-linkable exactly the way `NOTIFICATIONS.md`'s
`Inbox`/`Preferences` strip already is. The active item carries the platform's single accent as a
`border-s-2` rule (never a filled pill — Design Principle 2, "one accent, spent deliberately"), a locked
item (missing view permission) renders `text-ink-disabled` with a trailing `Lock` glyph, and the
"Notifications" row carries a trailing `ArrowUpRight` glyph signaling it navigates away from `/settings`
entirely rather than into a sibling tab — the one row in the rail that is not a same-shell tab.

**Content region** (`9/12`) is a plain `Form Page Template` per tab — Page Header (tab title, one-line
description, Cancel/Save or the tab's own primary actions), sectioned `Card` groups built with React Hook
Form + Zod exactly as `FRONTEND_ARCHITECTURE.md → Forms & Validation` already specifies, and — on the four
tabs gated by an always-sensitive permission (General, Accounting, Tax, Numbering, Approval Workflows,
Roles, Integrations' API Keys sub-tab) — a persistent inline notice under the Page Header reading "Changes
here require your own confirmation as Owner before they take effect" (or, for a non-Owner initiator, "…
require Owner approval before they take effect"), so the approval-gated nature of the tab is never a
surprise discovered only after clicking Save.

Two tabs (Users & Roles; Branches & Departments; Integrations) additionally nest an inner `Tabs` row
(two items each: Users/Roles, Branches/Departments, API Keys/Webhooks) between the Page Header and the Form
Body — a second level of navigation, but a shallow, two-item one that behaves exactly like every other
in-module `Tabs` instance in the platform, not a second Section Nav.

## Company Hierarchy panel (Branches & Departments tab only)

Reused verbatim from `COMPANY_STRUCTURE.md → Company Hierarchy`'s own worked example, rendered as a
collapsible tree above the Branches/Departments inner tabs so an Owner sees the whole shape of their
organization before editing one node of it:

```
ABC Holding
├── ABC Kuwait
│     ├── Finance
│     ├── HR
│     └── Sales
├── ABC Saudi
│     ├── Finance
│     └── Warehouse
└── ABC UAE
```

QAYD's own `companies`/`branches`/`departments` schema does not yet carry a `parent_company_id` for
true multi-entity holding structures (`ERD.md → companies → Future Expansion` names it explicitly as not
yet built) — for a v1 single-`companies`-row tenant, this panel renders the shallower, real two-level tree
that *does* exist today (`branches` → `departments`, per `ERD.md`'s own `branches ||--o{ departments`
cardinality), with the holding/subsidiary levels shown grayed and captioned "Multi-company grouping —
coming with holding-company support" rather than omitted outright, matching the same
visible-but-honestly-labeled treatment `SEARCH.md` gives its own not-yet-live WhatsApp channel.

# Components Used

| Component | Source | Role on this screen |
|---|---|---|
| `SettingsSectionNav` (**new**) | `components/settings/settings-section-nav.tsx` | The grouped vertical rail described above; a thin `<nav>` over the same permission-filtered pattern `NAV_TREE`/Sidebar already use |
| `PageHeader` | `components/layout/page-header.tsx` | Every tab's title, description, and primary actions |
| `Card` | `components/ui/card.tsx` | Every sectioned form group |
| `Tabs` | `components/ui/tabs.tsx` | The three inner two-item tab pairs (Users/Roles, Branches/Departments, API Keys/Webhooks) |
| `Form`, `Input`, `Select`, `Switch`, `Textarea`, `RadioGroup`/`ToggleGroup` | `components/ui/*` (existing shadcn primitives) | Every field on every tab |
| `Combobox` | `components/shared/combobox.tsx` | Country, timezone, default tax code, default cost center, department manager, approval-chain approver role pickers |
| `CurrencyInput` | `components/shared/currency-input.tsx` (existing, `FRONTEND_ARCHITECTURE.md → Forms & Validation`) | Approval-chain `threshold_amount`, seat price display |
| `FileUpload`/`Avatar` | `components/shared/file-upload.tsx`, `components/ui/avatar.tsx` | Company logo upload (General) |
| `CompanyHierarchyTree` (**new**) | `components/settings/company-hierarchy-tree.tsx` | The collapsible org tree above Branches & Departments |
| `ApprovalChainBuilder` (**new**) | `components/settings/approval-chain-builder.tsx` | The Approval Workflows tab's step list/reorder/threshold editor |
| `PermissionMatrix` (**new**) | `components/settings/permission-matrix.tsx` | Roles tab's flat permission checklist for a custom role — the v1 Permission Studio |
| `MemberTable` (**new**, wraps `DataTable`) | `components/settings/member-table.tsx` | Users tab's member list with role/branch chips and status |
| `InviteMemberDialog` (**new**) | `components/settings/invite-member-dialog.tsx` | Users tab's invite flow |
| `SessionList` (**new**) | `components/settings/session-list.tsx` | Security tab's active-devices list |
| `EmergencyLockDialog` (**new**) | `components/settings/emergency-lock-dialog.tsx` | Security tab's User/Branch/Department/Company lock confirmation |
| `MfaEnrollmentFlow` (**new**) | `components/settings/mfa-enrollment-flow.tsx` | Security tab's TOTP/SMS/WebAuthn enrollment steps |
| `PlanCard` (**new**) | `components/settings/plan-card.tsx` | Billing tab's plan comparison cards, reusing the same selectable-card interaction (tap a plan to move its highlight border) QAYD's own `(marketing)` pricing page already establishes for choosing a plan pre-signup |
| `SeatUsageBar` (**new**) | `components/settings/seat-usage-bar.tsx` | Billing tab's `seats_used`/`seats_included` bar |
| `ApprovalCard` | `components/shared/approval-card.tsx`, `kind` extended with `"company_settings"`, `"role_grant"`, `"api_key_write_scope"` (**extensions**, owned by this document) | Inline confirmation banner on every always-sensitive tab, per `PERMISSION_BY_KIND` |
| `ConfidenceBadge`, `AiCardShell`, `ReasoningDisclosure` | `components/ai/*` (existing) | Compliance Agent suggestion cards on Security and Approval Workflows — see `# AI Integration` |
| `StatusPill` | `components/shared/status-pill.tsx` | Subscription status, session `is_current`, invitation status, fiscal year/period status |
| `Badge` | `components/ui/badge.tsx` | Role chips, "Coming soon" (Notifications' WhatsApp-style locked states), plan tier labels |
| `AlertDialog` | `components/ui/alert-dialog.tsx` | Emergency lock, revoke session, delete branch/department, downgrade plan, remove member |
| `Sheet` | `components/ui/sheet.tsx` | Mobile Section Nav picker, mobile filter/detail drawers |
| `Tooltip` | `components/ui/tooltip.tsx` | Permission-denied explanations throughout |
| `Skeleton`, `EmptyState`, `ErrorState` | `components/ui/skeleton.tsx`, `components/shared/*` | Per-tab loading/empty/error — see `# States` |
| `useApiToast` | `hooks/use-api-toast.ts` | Every mutation's success/error surface |
| `Can` | `components/auth/can.tsx` | Every permission-gated field, button, and tab item |

None of the fourteen "new" components introduces a design token, an API shape, or a permission key beyond
what `# Route & Access` and `# Data & State` already name — each is a documented composition of existing
primitives and finance components, per `COMPONENT_LIBRARY.md`'s founding discipline.

# Data & State

## Endpoints by tab

**General** reads/writes the `companies` row directly plus the always-present `company_settings` row
(`ERD.md`'s strict 1:1). Fiscal-year-start and base-currency live on `companies`; language/date-format/
number-format/timezone live on `company_settings`.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/companies/{id}` | `settings.company.read` | `legal_name_en/ar`, `trade_name_en/ar`, `commercial_registration_no`, `tax_registration_no`, `country_code`, `base_currency`, `fiscal_year_start_month`, `logo_url`, `industry`, `size_band` |
| PATCH | `/api/v1/companies/{id}` | `settings.company.manage` | Always-sensitive — `202 Accepted`, returns the created `ai_approval_requests` id (`kind="company_settings"`), never a `200` with the field already changed |
| GET | `/api/v1/companies/{id}/settings` | `settings.company.read` | `default_language`, `date_format`, `number_format`, `default_timezone` (**new column**, see below), `branding` |
| PATCH | `/api/v1/companies/{id}/settings` | `settings.company.manage` | Same always-sensitive contract as above |

`default_timezone` is this document's own, explicitly-flagged addition to `company_settings` — the ERD's
existing columns cover language/date/number formatting but no company-wide timezone, even though the task
this screen serves ("General: … timezone") and every dashboard "as of" timestamp need one. It is seeded at
company creation from the head-office branch's own `branches.timezone` (`is_head_office = true`) and
free-standing thereafter — changing it never rewrites `branches.timezone` for any branch, which continues
to govern that branch's own local schedule/reporting.

**Accounting** and **Tax** read/write more of the same `company_settings` row (`default_payment_terms_days`,
`default_tax_code_id`) plus two more explicitly-flagged additions this document formalizes rather than
invents from nothing. `TRIAL_BALANCE.md` already assumes a per-company balancing tolerance exists without
naming its home ("within the company's 0.005 rounding tolerance") — this document is the first to name it:
`company_settings.rounding_tolerance NUMERIC(19,4) NOT NULL DEFAULT 0.0050`. Alongside it,
`require_cost_center_on_journal_lines BOOLEAN NOT NULL DEFAULT false` and
`require_project_on_journal_lines BOOLEAN NOT NULL DEFAULT false` turn the optional `cost_center_id`/
`project_id` dimensions `DESIGN_CONTEXT.md §9` already puts on `journal_lines` into a company-configurable
mandatory field, enforced server-side by `JournalEntryForm`'s own validation (`FRONTEND_ARCHITECTURE.md →
Forms & Validation`) reading this flag.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET/PATCH | `/api/v1/companies/{id}/settings` | `settings.company.read`/`.manage` | Same resource as General; Accounting tab reads/writes `rounding_tolerance`, `require_cost_center_on_journal_lines`, `require_project_on_journal_lines`, `default_payment_terms_days` |
| GET | `/api/v1/tax/codes?scope=company,system` | `settings.company.read` | Populates the Tax tab's default-code `Combobox`; the full CRUD table lives on `/tax/codes` (`TAX.md`), never duplicated here |
| PATCH | `/api/v1/companies/{id}/settings` | `settings.company.manage` | Tax tab writes only `default_tax_code_id` |

**Numbering** is where this document formalizes `ERD.md`'s own stated future item verbatim: "a
generalized `document_sequences(company_id, document_type, prefix, next_number)` table is the natural v2
consolidation" (`company_settings → Future Expansion`). This screen is the reason v2 arrives now — a
Numbering tab that only edits `invoice_prefix`/`bill_prefix` cannot honestly claim to cover "numbering
sequences" as the task requires it to. `company_settings.invoice_prefix`/`invoice_next_number`/
`bill_prefix`/`bill_next_number` remain as a read-only, deprecated-but-still-authoritative compatibility
shim (any code path still reading them keeps working) while `document_sequences` becomes the one editable
surface going forward, seeded from those four columns at migration time:

```sql
-- This document's formalization of company_settings's own Future Expansion note.
CREATE TABLE document_sequences (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    document_type  VARCHAR(30) NOT NULL CHECK (document_type IN
                     ('invoice','bill','sales_quotation','sales_order','purchase_order',
                      'credit_note','debit_note','receipt','journal_entry','payslip')),
    prefix         VARCHAR(10) NOT NULL,
    next_number    BIGINT NOT NULL DEFAULT 1 CHECK (next_number > 0),
    padding_digits SMALLINT NOT NULL DEFAULT 4,
    reset_cadence  VARCHAR(10) NOT NULL DEFAULT 'never' CHECK (reset_cadence IN ('never','yearly','monthly')),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, document_type)
);
```

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/companies/{id}/document-sequences` | `settings.company.read` | One row per `document_type`, ten rows for a fully-configured company |
| PATCH | `/api/v1/companies/{id}/document-sequences/{document_type}` | `settings.company.manage` | Editing `prefix`/`padding_digits`/`reset_cadence` is always-sensitive; `next_number` may only ever be *raised* (closing a gap after a manual correction), never lowered, matching the same collision-free guarantee `company_settings.invoice_next_number`'s `SELECT … FOR UPDATE` pattern already gives — generalized here to `document_sequences` |

**Approval Workflows** exposes `AUTHORIZATION_API.md → Policies → 2`'s `approval_chains`/
`approval_chain_steps` design directly; its own `# 4. Endpoints` names only `GET /approval-chains` (list)
and `PATCH /approval-chains/{id}` (adjust) — this document adds the `POST`/`DELETE` a genuine *builder*
implies, restricted to non-`is_system_minimum` chains for `DELETE`:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/auth/approval-chains` | `auth.policies.read` | The seven system-minimum chains (`# Interactions & Flows`) plus any company-defined ones |
| GET | `/api/v1/auth/approval-chains/{id}` | `auth.policies.read` | Chain header + its ordered `approval_chain_steps` |
| PATCH | `/api/v1/auth/approval-chains/{id}` | `auth.policies.manage` | Threshold/approver-role edits; `is_system_minimum=true` chains accept step **additions** but reject a request that would leave zero steps |
| POST | `/api/v1/auth/approval-chains` | `auth.policies.manage` | **New in this document** — define a company-specific chain for a `permission_key` not already in the always-sensitive table (e.g. `purchasing.order.create` above a threshold) |
| DELETE | `/api/v1/auth/approval-chains/{id}` | `auth.policies.manage` | Blocked with `409` when `is_system_minimum = true` |
| POST/DELETE | `/api/v1/auth/approval-chains/{id}/steps` | `auth.policies.manage` | Reorder/add/remove a single step without resubmitting the whole chain |

**Users & Roles** is the one tab touching five tables at once (`company_users`, `user_invitations`,
`roles`, `permissions`, `user_roles`):

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/companies/{id}/members` | `users.read` | Joins `company_users` + `user_roles` + `roles` per row |
| POST | `/api/v1/companies/{id}/invitations` | `users.invite` | Body `{ invited_email, role_id, branch_id? }`; server enforces the partial-unique "one pending invite per email" rule |
| GET | `/api/v1/companies/{id}/invitations` | `users.read` | `pending`/`accepted`/`expired`/`revoked` |
| POST | `/api/v1/companies/{id}/invitations/{id}/resend` | `users.invite` | Reissues `token_hash`, resets `expires_at` |
| DELETE | `/api/v1/companies/{id}/invitations/{id}` | `users.invite` | `status → revoked` |
| PATCH | `/api/v1/companies/{id}/members/{userId}` | `users.manage` | Status (`active`/`suspended`), home `branch_id`, `job_title` |
| DELETE | `/api/v1/companies/{id}/members/{userId}` | `users.manage` | Soft-remove; blocked while `is_owner = true` until ownership transfers |
| POST | `/api/v1/companies/{id}/ownership-transfer` | `settings.company.manage` (Owner only, self-initiated) | The `TransferCompanyOwnership` transaction `ERD.md → company_users` names |
| GET | `/api/v1/auth/permissions` | `auth.roles.manage` | Full catalog discovery, per `AUTHORIZATION_API.md §4`; feeds `PermissionMatrix` |
| GET | `/api/v1/roles` | `users.read` | System roles (`company_id IS NULL`) + this company's custom roles |
| POST | `/api/v1/roles` | `auth.roles.manage` | Always-sensitive; custom role only, `is_system=false` is server-enforced |
| PATCH | `/api/v1/roles/{id}` | `auth.roles.manage` | `name_en/ar`/`description`/permission set; blocked on system roles |
| POST | `/api/v1/user-roles` | `users.role.assign` | Always-sensitive; body `{ user_id, role_id, branch_id?, expires_at? }` |
| DELETE | `/api/v1/user-roles/{id}` | `users.role.assign` | Revocation (soft delete, retained for audit) |

**Branches & Departments** is ordinary, non-sensitive CRUD — the always-sensitive table names only
"company settings changes," not branch/department structure, so these two endpoints stay pessimistic (see
below) but need no approval chain:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET/POST | `/api/v1/branches` | `branches.read`/`.manage` | `is_head_office` reassignment always transactional, per `ERD.md` |
| PATCH/DELETE | `/api/v1/branches/{id}` | `branches.manage` | Delete blocked while active warehouses/departments/transactional rows reference it |
| GET/POST | `/api/v1/departments` | `departments.read`/`.manage` | `parent_department_id` tree; a trigger rejects cycles |
| PATCH/DELETE | `/api/v1/departments/{id}` | `departments.manage` | Delete `SET NULL`s `employees.department_id` rather than blocking |

**Security** spans `AUTHENTICATION_API.md`'s already-fixed session/MFA endpoints plus two additions —
company-wide policy overrides and the emergency lock — this document is the first frontend surface for:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/auth/sessions` | self; `auth.session.manage_others` for another user's | Device name, kind, IP, `location_guess`, `last_active_at`, `is_current`, `amr` |
| DELETE | `/api/v1/auth/sessions/{id}` | self; `auth.session.manage_others` | One session |
| DELETE | `/api/v1/auth/sessions` | self | Every session except the caller's current one |
| POST | `/api/v1/auth/revoke` | `auth.session.manage_others` | Body requires non-empty `reason`; written verbatim to `audit_logs` |
| POST | `/api/v1/auth/mfa/totp/enroll`, `/verify` | self | Returns secret + QR, then confirms |
| POST | `/api/v1/auth/mfa/sms/enroll`, `/send` | self | |
| GET/POST | `/api/v1/auth/mfa/backup-codes`, `/regenerate` | self | |
| DELETE | `/api/v1/auth/mfa/{factor_id}` | self; `auth.mfa.manage_others` | Revokes every session for that user (trust boundary changed) |
| POST | `/api/v1/auth/mfa/webauthn/register/options`, `/verify` | self | Owner/CFO-tier hardware-key factor |
| GET/PATCH | `/api/v1/companies/{id}/security-policy` | `settings.security.read`/`.manage` | `mfa_required_roles`, `session_idle_timeout_minutes`, `max_concurrent_sessions` (per role), `api_key_max_ttl_days` — tightening only, `AUTHENTICATION_API.md`'s own stated rule |
| GET | `/api/v1/companies/{id}/security-events` | `settings.security.read` | Recent `security_events` (400-day window; `critical` never pruned) |
| POST | `/api/v1/companies/{id}/emergency-lock` | `settings.security.emergency_lock` | **New in this document** — see `# Interactions & Flows` for the orchestration this one call performs |

**Integrations** reuses `AUTHENTICATION_API.md`'s literal API Key endpoints and adds a parallel Webhooks
resource in the same shape:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET/POST | `/api/v1/api-keys` | `auth.apikey.read`/`.create` | Creation with any write scope is always-sensitive (CFO single step) |
| PATCH/DELETE | `/api/v1/api-keys/{id}` | `auth.apikey.create`/`.revoke` | Rename/rescope; revoke is immediate, not approval-gated |
| GET/POST | `/api/v1/webhooks` | `integrations.read`/`.webhook.manage` | Endpoint URL, subscribed domain events (`invoice.created`, `payment.received`, …, per `DESIGN_CONTEXT.md §5`), signing secret |
| PATCH/DELETE | `/api/v1/webhooks/{id}` | `integrations.webhook.manage` | |

**AI Preferences** edits the company-wide ceiling on `company_settings` plus the granular, per-permission
overrides `AUTHORIZATION_API.md §6` already names:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET/PATCH | `/api/v1/companies/{id}/settings` | `ai.autonomy.read`/`ai.autonomy.manage` | `ai_autonomy_level` (`suggest_only`\|`approval_required`\|`auto`) — the company-wide ceiling |
| GET | `/api/v1/ai/autonomy-settings` | `ai.autonomy.read` | One row per `(permission_key, agent_code)` override, joined against the 15-agent roster (`ICONOGRAPHY.md`'s Agent Roster table) |
| PATCH | `/api/v1/ai/autonomy-settings/{permission_key}` | `ai.autonomy.manage` | Rejected with `422` if the requested level is looser than the company ceiling, or looser than `requires_approval` for a permission in the always-sensitive table (`AUTHORIZATION_API.md §6`'s system-minimum rule) |

**Billing/plan** reads/writes `company_subscriptions`:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/companies/{id}/subscription` | `companies.billing.read` | Current live row (`status IN ('trialing','active','past_due')`) |
| POST | `/api/v1/companies/{id}/subscription/change-plan` | `companies.billing.manage` | Inserts a new row per `ERD.md`'s append-mostly history rule; never mutates `plan_code` in place |
| POST | `/api/v1/companies/{id}/subscription/cancel` | `companies.billing.manage` | Sets `canceled_at`; access continues until `current_period_end` |
| GET | `/api/v1/companies/{id}/subscription/invoices` | `companies.billing.read` | Proxies the `payment_provider` (Stripe) invoice list directly in v1 — `ERD.md → company_subscriptions → Future Expansion`'s own `platform_invoices` table is the documented eventual home once QAYD issues its own SaaS invoices instead of proxying Stripe's |
| GET/PATCH | `/api/v1/companies/{id}/subscription/payment-method` | `companies.billing.manage` | Stripe Elements-hosted card form; QAYD's own frontend never touches a raw card number |

## Query keys

```ts
// lib/api/query-keys.ts (Settings additions)
export const settingsKeys = {
  all: ["settings"] as const,
  company: () => [...settingsKeys.all, "company"] as const,
  companySettings: () => [...settingsKeys.all, "company-settings"] as const,
  documentSequences: () => [...settingsKeys.all, "document-sequences"] as const,
  approvalChains: () => [...settingsKeys.all, "approval-chains"] as const,
  approvalChain: (id: number) => [...settingsKeys.approvalChains(), id] as const,
  members: (filters: MemberFilters) => [...settingsKeys.all, "members", filters] as const,
  invitations: (status: string) => [...settingsKeys.all, "invitations", status] as const,
  roles: () => [...settingsKeys.all, "roles"] as const,
  permissionCatalog: () => [...settingsKeys.all, "permission-catalog"] as const,
  branches: () => [...settingsKeys.all, "branches"] as const,
  departments: () => [...settingsKeys.all, "departments"] as const,
  sessions: () => [...settingsKeys.all, "sessions"] as const,
  securityPolicy: () => [...settingsKeys.all, "security-policy"] as const,
  securityEvents: () => [...settingsKeys.all, "security-events"] as const,
  apiKeys: () => [...settingsKeys.all, "api-keys"] as const,
  webhooks: () => [...settingsKeys.all, "webhooks"] as const,
  aiAutonomy: () => [...settingsKeys.all, "ai-autonomy"] as const,
  subscription: () => [...settingsKeys.all, "subscription"] as const,
};
```

## Cache tuning by tab

| Data class | `staleTime` | Rationale |
|---|---|---|
| `company()`, `companySettings()`, `documentSequences()` | `60_000` | Low-churn, Owner-approved-only writes; a full minute of staleness is invisible in practice |
| `approvalChains()` | `30_000` | Another Owner/CFO may be mid-edit on a second tab |
| `members()`, `invitations()` | `15_000` | Higher-churn — invitations resolve, members change status, within a normal working session |
| `roles()`, `permissionCatalog()` | `300_000` | The permission catalog changes only on a platform deploy, not per company |
| `branches()`, `departments()` | `30_000` | |
| `sessions()` | `0`, always refetched on mount | A stale "is this session still mine" list is a real security-visibility gap |
| `securityPolicy()`, `securityEvents()` | `15_000` | |
| `apiKeys()`, `webhooks()` | `30_000` | |
| `aiAutonomy()` | `60_000` | |
| `subscription()` | `60_000`, invalidated immediately on any webhook `payment.received`/`invoice.paid` (Stripe) | Seat/plan changes must never show stale after a successful upgrade |

## Form schemas

```ts
// lib/schemas/settings.ts
export const companyGeneralSchema = z.object({
  legal_name_en: z.string().min(2).max(200),
  legal_name_ar: z.string().max(200).nullable().optional(),
  trade_name_en: z.string().max(200).nullable().optional(),
  country_code: z.string().length(2),
  base_currency: z.string().regex(/^[A-Z]{3}$/),   // rendered read-only once postedActivity === true
  fiscal_year_start_month: z.number().int().min(1).max(12),
  default_language: z.enum(["en", "ar"]),
  default_timezone: z.string().min(1),             // IANA name, validated against Intl.supportedValuesOf("timeZone")
  logo_url: z.string().url().nullable().optional(),
});

export const approvalChainStepSchema = z.object({
  step_order: z.number().int().min(1),
  approver_role_id: z.number().int().positive(),
  is_parallel: z.boolean().default(false),
  min_approvals: z.number().int().min(1).default(1),
});

export const approvalChainSchema = z.object({
  name: z.string().min(3).max(120),
  permission_key: z.string().regex(/^[a-z_]+(\.[a-z_]+){1,2}$/),
  threshold_amount: z.string().regex(/^\d+(\.\d{1,4})?$/).nullable(),
  threshold_currency: z.string().length(3).nullable(),
  steps: z.array(approvalChainStepSchema).min(1, "at_least_one_step"),
});

export const inviteMemberSchema = z.object({
  invited_email: z.string().email(),
  role_id: z.number({ required_error: "role_required" }),
  branch_id: z.number().nullable().optional(),
});
```

Every other tab's schema (Accounting, Tax, Numbering, Branches, Departments, Security policy, Webhooks, AI
Preferences, Billing) follows this identical RHF + Zod shape — money as `z.string()` regex-validated never
`z.number()`, per `FRONTEND_ARCHITECTURE.md`'s money-as-decimal-string rule — and is omitted here rather
than repeated twelve times over.

## Mutations — pessimistic where sensitive, optimistic where safe

Per Design Principle 10 (`FRONTEND_ARCHITECTURE.md → Optimistic updates`): every always-sensitive tab
(General, Accounting, Tax, Numbering, Approval Workflows, Roles, API Keys) mutates pessimistically — no
`onMutate`, no optimistic field flip — because the visible result of clicking Save is never "changed," it
is "submitted for the confirmation `# Interactions & Flows` describes":

```ts
// hooks/settings/use-update-company.ts
export function useUpdateCompanyGeneral() {
  const idempotencyKey = useIdempotencyKey("settings-general");
  return useMutation({
    mutationFn: (body: CompanyGeneralFormValues) =>
      api.patch(`/companies/${activeCompanyId}`, body, { headers: { "Idempotency-Key": idempotencyKey } }),
    // No onMutate: the form stays exactly as typed, in a "Pending confirmation" banner state,
    // until the created ai_approval_requests row resolves — see # Interactions & Flows.
  });
}
```

Branches, Departments, Security's own-session revocation, MFA self-enrollment, and Webhooks are ordinary,
reversible, non-financial actions and mutate optimistically, matching `TRIAL_BALANCE.md`'s own
`useDismissFinding` pattern exactly:

```ts
// hooks/settings/use-update-branch.ts
export function useUpdateBranch(branchId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: BranchFormValues) => api.patch(`/branches/${branchId}`, body),
    onMutate: async (body) => {
      await qc.cancelQueries({ queryKey: settingsKeys.branches() });
      const previous = qc.getQueryData(settingsKeys.branches());
      qc.setQueryData(settingsKeys.branches(), (old: Branch[]) =>
        old.map((b) => (b.id === branchId ? { ...b, ...body } : b)));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(settingsKeys.branches(), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: settingsKeys.branches() }),
  });
}
```

## SSR hydration

Every one of the fifteen `page.tsx` files is a thin Server Component prefetching exactly its own tab's
data, identical in shape to `NOTIFICATIONS.md`'s and `TRIAL_BALANCE.md`'s own pattern:

```tsx
// app/(app)/settings/company/page.tsx
export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function SettingsGeneralPage() {
  const queryClient = getQueryClient();
  await Promise.all([
    queryClient.prefetchQuery({ queryKey: settingsKeys.company(), queryFn: () => apiServer.get("/companies/me") }),
    queryClient.prefetchQuery({ queryKey: settingsKeys.companySettings(), queryFn: () => apiServer.get("/companies/me/settings") }),
  ]);
  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <SettingsGeneralForm />
    </HydrationBoundary>
  );
}
```

## Realtime

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.settings` (**new**) | `company.settings.updated`, `company.settings.approval_pending`, `company.settings.approval_decided` | Invalidates `company()`/`companySettings()`/`documentSequences()`; if the viewer's own pending change was just decided by a second Owner on another device, the "Pending confirmation" banner (`# Interactions & Flows`) resolves live rather than on next poll |
| `private-company.{id}.approvals` | Any `ai_approval_requests` decision for `kind IN ("company_settings","role_grant","api_key_write_scope")` | Same effect, reused verbatim from the platform-wide channel `FRONTEND_ARCHITECTURE.md → Realtime` already documents — Settings introduces no second approvals channel |
| `private-company.{id}.notifications.{user_id}` | `security.session_revoked`, `security.emergency_lock_triggered`, `security.mfa_reset` | Topbar bell + a same-tab toast if the affected session is the viewer's own current one, per `AUTHENTICATION_API.md → Domain Event and Notification` |

## AI agents feeding this screen

Settings deliberately carries the platform's second-smallest AI surface (after `NOTIFICATIONS.md`'s "none
at all") — configuration is a place for facts and human judgment, not synthesis:

| Agent | Contribution |
|---|---|
| Compliance Agent | A single, dismissible suggestion card on the Security tab when MFA coverage or session-policy laxity looks like a real gap (e.g., "60% of Owner/CFO-tier accounts have no MFA factor enrolled — tighten `mfa_required_roles`?") — never auto-applies; only ever a link that pre-fills the Security Policy form for a human to review and Save |
| Fraud Detection | Feeds the `security_events` rows the Security tab's recent-activity panel reads (`suspicious_ip`, `rate_limited`) — a pure data source, never its own card on this screen; the full analysis lives in the AI Command Center's own Detected Risks panel |
| Approval Assistant | The routing engine behind every `ai_approval_requests` row this screen's always-sensitive tabs create — visible only as the resulting `ApprovalCard`, never a separate suggestion here |
| CFO Agent | An optional, dismissible suggestion on Approval Workflows ("2 near-miss bank transfers this quarter suggest lowering the KWD 5,000 threshold to KWD 2,000") — same three-button-adjacent pattern as every other AI card platform-wide: a link that opens the chain's edit form pre-filled, never a silent threshold change |

None of the four ever writes to `company_settings`, `approval_chains`, `roles`, or `company_subscriptions`
directly — every one of them, without exception, ends at a human opening an already-pessimistic Settings
form with its fields pre-filled, per Design Principle 7 ("the AI stays humble in the UI, exactly as it
stays humble in the API").

# Interactions & Flows

Every flow below assumes the caller has already cleared the tab-level `read` permission in `# Route &
Access`; where a flow additionally requires `manage` (or one of the finer-grained gates in that table), the
relevant control is either absent, disabled-with-tooltip, or — on the always-sensitive tabs — replaced by a
read-only "awaiting approval" rendering, never a silent no-op.

**Arriving at Settings.** The Sidebar's own "Settings" item and the Section Nav both deep-link to a specific
tab route, never to a bare `/settings` index — `layout.tsx` redirects a request for the bare segment to
whichever tab the caller both can view and last visited (a `qayd-ui-prefs`-style, per-company Zustand
preference, per `FRONTEND_ARCHITECTURE.md`'s State Management table), defaulting to General on a first-ever
visit. Clicking any Section Nav row is a real `<Link>` navigation, never a client-side tab swap, so every
one of the fifteen destinations is reachable by bookmark, shared link, or the browser back button, and a
hard refresh on `/settings/security` lands back on Security, not on General.

**Saving General, Accounting, Tax, or Numbering — the always-sensitive flow, resolved.** Clicking "Save" on
any of these four tabs runs the tab's Zod schema client-side, then fires the tab's own pessimistic mutation
(`useUpdateCompanyGeneral`-style, `# Data & State → Mutations`) with a fresh `Idempotency-Key`. The server's
`202 Accepted` carries the newly created `ai_approval_requests` row (`kind="company_settings"`, chain
resolved to the fixed Owner-single-step chain `AUTHORIZATION_API.md §Policies→1` names for
`settings.company.manage`) — the mutation's `onSuccess` neither flips the form to "saved" nor reverts it; it
replaces the persistent inline notice `# Layout & Regions` already describes with a live `ApprovalCard`
(`kind="company_settings"`, compact presentation) rendered **inline, inside the same tab**, showing exactly
the fields about to change (a small before/after diff built from the request's own `payload`), never a bare
"pending" spinner with no visible content.

This is where the single-owner-SME case — the initiator already holding the chain's one qualifying role —
is answered concretely, and it is answered the same way the rest of the platform already answers "can the
same person propose and approve": **they can, but never in the same click.** An Owner who just clicked Save
sees their own request's `ApprovalCard` render fully interactive, because they hold the current step's role
— but clicking its **Approve** button is a second, distinct UI action on a distinct component, gated by the
identical step-up re-confirmation `AUTHENTICATION_API.md`'s MFA policy table already requires of Owner/CFO
tier "before sensitive ops: Always," the same TOTP/WebAuthn/biometric re-prompt `AI_COMMAND_CENTER.md`
frontend already specifies for its own Approval Center approvals. Nothing about arriving at this approval by
proposing it themselves lowers that bar; the platform does not special-case "approver is also the initiator"
into a weaker path — it simply lets the existing two-actor mechanism run with the same actor filling both
roles, at two different moments, behind two different confirmations. A Finance Manager saving
Accounting/Tax/Numbering (permitted by the role-grants table to `manage` those tabs, since they share
`settings.company.manage`) sees the identical inline `ApprovalCard`, but rendered in its **non-interactive,
"awaiting Owner" preview state** — per `ApprovalCard`'s own `PERMISSION_BY_KIND` gate, only the assigned
current-step role renders live controls — because they are not the chain's approver; the request instead
surfaces normally in the actual Owner's own Approval Center queue and notification stream, and the Finance
Manager's own tab shows a plain `StatusPill` ("Awaiting Owner approval") until it resolves, live, over
`private-company.{id}.settings` (`# Data & State → Realtime`).

**Numbering's `next_number` guard.** Editing `prefix`/`padding_digits`/`reset_cadence` on any row in the
Numbering table follows the identical always-sensitive flow above (`resource_type="document_sequences"` on
the same `ai_approval_requests` row shape). `next_number` is excluded from that gate in one direction only:
the field's own Zod validator (`z.number().int().min(currentNextNumber)`) accepts a raise — closing a gap
left by a manual correction — with an ordinary, ungated, optimistic `PATCH`, because a raise can only ever
make a future document number skip forward, never collide with one already issued; any attempt to type a
lower value is rejected client-side before it reaches the network, and the server's own guard 422s the rare
direct-API attempt identically.

**Building an Approval Workflow.** One scope note first: `AUTHORIZATION_API.md §Policies→1`'s own literal
always-sensitive table does not yet list `auth.policies.manage` (editing the chains that govern every other
sensitive action) alongside `settings.company.manage` — this document closes that gap the same way it
already closed the `document_sequences`/`rounding_tolerance`/`default_timezone` gaps in `# Data & State`: a
screen that lets anyone reconfigure who approves a bank transfer cannot itself be a single, ungated click,
so every non-trivial edit on this tab (threshold changes, approver-role reassignment, step add/remove,
defining a brand-new chain) runs through the identical Owner-tier `ApprovalCard` flow above,
`kind="company_settings"` reused rather than a fifth kind invented for a screen this narrow. Inside that
gate, `ApprovalChainBuilder` renders each chain as an ordered step list with drag-handle reordering, an
approver-role `Combobox`, an `is_parallel` toggle (revealing a `min_approvals` stepper only when on), and —
for a chain with `threshold_amount` — a `CurrencyInput`. A system-minimum chain (`is_system_minimum = true`)
renders its own "Delete chain" control omitted entirely (never merely disabled — its non-existence for this
chain is not sensitive information), and its last remaining step's own remove button disabled with the
tooltip "A system-minimum chain always keeps at least one step." Defining a brand-new chain (the builder's
own "New chain" action, for a `permission_key` not already in the always-sensitive table — e.g.
`purchasing.order.create` above a company-chosen threshold) opens the same builder pre-seeded with a
permission-key `Combobox` sourced from `GET /api/v1/auth/permissions`, the platform's own
permission-discovery catalog.

**Inviting a member and assigning a role.** "Invite" on the Users tab opens `InviteMemberDialog` — email,
role `Combobox`, optional home-branch `Combobox`. Submitting a duplicate pending email surfaces the server's
own partial-unique violation mapped onto the email field ("An invitation is already pending for this
address — resend it instead?") rather than a generic `409` toast, with a one-click "Resend" action available
directly from that error. Accepted/expired/revoked invitations move out of the pending list into a lighter,
collapsed history row; "Resend" reissues `token_hash` and resets `expires_at` without creating a second row.
Assigning or changing an existing member's role (`POST /api/v1/user-roles`) is itself always-sensitive
(`auth.roles.manage`/`users.role.assign`, Owner single step) and follows the identical inline-`ApprovalCard`
pattern, `kind="role_grant"` — including for an Owner assigning a role to themselves on a second company
they also belong to, for the same "two clicks, not one" reason as General/Accounting/Tax/Numbering.

**Building a custom role.** The Roles tab's `PermissionMatrix` — this screen's v1 of the future Permission
Studio (`# Purpose`) — renders the full permission catalog grouped by `PERMISSION_SYSTEM.md → Permission
Categories`'s named areas as collapsible sections, each a flat checklist rather than a tree. One rule holds
regardless of who is granting: **a role-manager can only ever grant a permission they themselves currently
hold** — the checklist server-side-filters (and, redundantly, client-side-disables with a tooltip: "You do
not hold this permission yourself") any permission outside the grantor's own effective set, so Settings can
never be used to mint a role more powerful than its creator, even an Owner creating a role for a hypothetical
future hire. Saving a new or edited custom role is `auth.roles.manage`, `kind="role_grant"`, the identical
gate as individual role assignment; system roles (`is_system=true`) render the same checklist read-only,
every checkbox disabled, with a single explanatory banner rather than dozens of redundant per-row tooltips.

**Ownership transfer.** A rare, Owner-only, self-initiated action (`POST
/api/v1/companies/{id}/ownership-transfer`, gated on `settings.company.manage`) reachable from a
`MemberTable` row's overflow menu ("Transfer ownership to this member"). Its confirmation `AlertDialog`
names the successor by name, states in plain language that the caller's own role changes to CEO once the
transfer completes, and requires the same step-up re-confirmation as any other Owner-tier sensitive action
before it submits — this is the one flow on the whole screen that never renders as a pending `ApprovalCard`,
because an Owner transferring their own ownership needs no second approver by construction; the confirmation
dialog itself, plus step-up, **is** the deliberate second action.

**Branches and Departments.** Ordinary optimistic CRUD (`# Data & State`), no approval chain. Reassigning
`is_head_office` to a different branch opens a confirmation naming the outgoing and incoming head office by
name (the backend's own transactional swap, per `ERD.md`); deleting a branch or department that still has
active references (warehouses, employees, transactional rows) surfaces the server's `409` mapped onto a
specific, named blocker ("Cannot delete — 2 active warehouses reference this branch") rather than a generic
failure toast. A department's parent `Combobox` filters out the department's own descendants client-side as
a courtesy; the rare case that still reaches the server (a stale tree snapshot) surfaces the
cycle-prevention trigger's rejection on the same field.

**Enrolling and managing MFA (Security).** `MfaEnrollmentFlow` is a four-branch stepper matching
`AUTHENTICATION_API.md → Factors Supported` exactly: TOTP shows a secret and QR immediately, then blocks on
one successful 6-digit verification before the factor is considered enrolled; SMS collects an E.164 number,
sends an OTP, and verifies identically; Backup Codes generates ten single-use codes shown exactly once with
a mandatory "I have saved these" checkbox before the dialog can close; WebAuthn invokes the browser's native
credential ceremony and is offered only to Owner/CFO-tier roles, per that document's "Owner/CFO Advanced
Factor" framing. Removing any factor (`DELETE /api/v1/auth/mfa/{factor_id}`) opens a confirmation stating
plainly that every one of the user's own sessions will be revoked as a consequence — the trust boundary
genuinely changed — making this the one Security action framed as a warning rather than a neutral
confirmation, because its side effect (an immediate, total sign-out) is easy to be surprised by otherwise.

**Managing sessions.** `SessionList` renders `device_name`, a device-kind icon, `location_guess`,
`last_active_at` (relative, e.g. "2 hours ago," exact timestamp on hover), and a highlighted "This device"
row for `is_current`. Revoking any other row is a single click with no confirmation (reversible in the sense
that the device simply has to sign in again); "Sign out of all other devices" — the bulk action surfaced
after a password change or a security scare — carries a light confirmation naming the count of sessions
about to be revoked, and never touches the caller's own current row, matching `DELETE
/api/v1/auth/sessions`'s own documented contract exactly. Revoking **someone else's** session
(`auth.session.manage_others`) opens a mandatory-reason `AlertDialog` — the reason is written verbatim to
`audit_logs` per `# Data & State`, and the affected user receives an immediate `security.session_revoked`
notification (`# Data & State → Realtime`), never a silent sign-out they discover only on their next click.

**Tightening the security policy.** Every control in the Security Policy form enforces "tightening only" at
the field level, not just at submit time: a role already checked in `mfa_required_roles` and locked by an
existing company minimum renders checked-and-disabled rather than an uncheckable checkbox with a rejected
submit; `session_idle_timeout_minutes` and `api_key_max_ttl_days` are numeric steppers whose upper bound is
clamped to the current value, so a Finance Manager reviewing the form (read-only view per the role-grants
table) never even sees an interactive control that could loosen a setting, and an Owner attempting to type a
looser value simply cannot move the stepper past the current ceiling.

**Emergency Lock — the orchestration this screen is the first to specify.** `EmergencyLockDialog` opens from
a single, deliberately unmissable "Emergency Lock" button in its own bordered, `danger`-tone sub-card at the
bottom of the Security tab — visually separated from every other control on the page, matching the
platform's convention that its most powerful action never sits shoulder-to-shoulder with routine ones. The
dialog requires, in order: a scope pick (User / Branch / Department / Entire Company, per
`PERMISSION_SYSTEM.md → Emergency Lock`'s own four-level list), a mandatory free-text reason, and the same
Owner-tier step-up re-confirmation every other Security-tightening action requires. On confirm, `POST
/api/v1/companies/{id}/emergency-lock` performs, synchronously, everything the feature's own reason for
existing implies: every active session held by an account inside the chosen scope is revoked immediately
(mirroring `DELETE /api/v1/auth/sessions`'s own mechanism, applied to every affected `user_id` rather than
one); every subsequent login attempt for an account inside the scope returns a distinct, honest `423
ACCOUNT_LOCKED` response — never the ordinary invalid-credential message, so a locked employee is not left
debugging a password they typed correctly — until the lock is explicitly lifted from this same dialog; and,
for the Entire Company scope specifically, every API key not owned by the acting Owner is suspended (its
`status` flips to `suspended`, not revoked, so it resumes working the moment the lock lifts rather than
needing to be recreated). Lifting a lock is the mirror action, same permission, same reason requirement, and
is logged as its own `audit_logs` row exactly like the lock itself.

**Creating and rotating an API key.** `POST /api/v1/api-keys` with any write scope selected in its scope
checklist is always-sensitive (`kind="api_key_write_scope"`, a CFO-tier single-step chain per
`AUTHORIZATION_API.md §Policies→1`'s row for this operation); a read-only-scope key is created immediately,
no gate. Either way, the created key's plaintext value is shown exactly once, in a dedicated `Dialog` with a
copy-to-clipboard button and an explicit, bolded "You will not be able to view this key again" warning,
matching the one-time-reveal convention `AUTHENTICATION_API.md`'s own worked examples already establish for
this resource. Renaming or narrowing an existing key's scope afterward is an ordinary optimistic edit (no
gate — only *creating* a write-scoped key is sensitive, per `# Data & State`); revoking is immediate and
irreversible, confirmed with a plain `AlertDialog` naming the key by its label, never its secret.

**Configuring a webhook.** Creating or editing a `webhooks` row is ordinary CRUD — URL, an event-type
checklist grouped by domain (`invoice.*`, `payment.*`, `payroll.*`, …, per `DESIGN_CONTEXT.md §5`'s event
catalog), and a signing secret shown once at creation with the identical one-time-reveal treatment as an API
key. A "Send test ping" button on an existing webhook fires a synthetic `webhook.test` event immediately and
surfaces the endpoint's real HTTP response code and latency inline, so misconfiguration is caught before a
real domain event silently fails to deliver.

**Adjusting AI autonomy.** The company-wide ceiling `Select` (`suggest_only` / `approval_required` / `auto`)
carries a persistent inline note that it can never loosen a permission already pinned to `requires_approval`
in the always-sensitive table, regardless of what the ceiling says — choosing `auto` company-wide, for
instance, changes nothing about how a bank transfer or payroll release is gated. Each row in the
per-permission override list is its own inline `Select`; submitting a value looser than the company ceiling
or looser than the system floor for a sensitive `permission_key` fails with a `422` the row surfaces directly
beneath itself ("Cannot be looser than the company ceiling — Suggest only"), never a page-level toast
divorced from the row that caused it.

**Changing plan and payment method (Billing).** Selecting a different `PlanCard` moves its selectable
highlight border the same way QAYD's own `(marketing)` pricing page already does for pre-signup plan
selection, then opens a confirmation summarizing the proration and the new `seats_included`/price before
`POST .../change-plan` fires; a downgrade whose `seats_included` would fall below the company's current
`seats_used` is blocked client-side with a named remediation ("Remove 2 members or change branches before
downgrading to Starter"). "Cancel plan" opens a distinct, `destructive-quiet`-styled confirmation stating the
exact date access continues through (`current_period_end`) — cancellation is never framed as immediate,
because it is not. The payment-method form is a Stripe Elements-hosted iframe; QAYD's own React tree never
receives, stores, or transmits a raw card number, consistent with the platform-wide prohibition on the
frontend ever handling a card PAN directly.

# AI Integration

Settings carries the platform's second-smallest AI surface, after `NOTIFICATIONS.md`'s "none at all" — a
configuration hub is a place for stated facts and human judgment, and every AI contribution here is
calibrated to stay exactly that small. There is no dedicated "AI" tab distinct from `/settings/ai` (which
configures autonomy defaults, a fact a human sets, not an AI-authored surface itself); the four agents that
do touch this screen — Compliance Agent, Fraud Detection, Approval Assistant, CFO Agent — each contribute at
most one dismissible, link-only card, never a form field they populate directly, and none of the four ever
calls a mutation on this screen's own resources.

**Worked example — the Compliance Agent's MFA-coverage suggestion.** The Security tab is the one place an AI
card renders inline among ordinary controls, appearing above the Security Policy form only when the
Compliance Agent's own scheduled scan finds a real, stated gap:

```json
{
  "id": 88104,
  "agent_code": "compliance",
  "surfaced_on": "settings.security",
  "title": "3 of 5 Owner/CFO-tier accounts have no MFA factor enrolled",
  "confidence": 0.94,
  "reasoning": "mfa_required_roles currently includes owner and cfo, but mfa_factors has zero active rows for user_id 4471 (CFO) and two other Owner-tier accounts created before enforcement began. Enforcement is checked at login, so these accounts will be blocked on next sign-in rather than silently exempted — surfacing this now avoids a support incident at that moment instead.",
  "suggested_action": "Send an enrollment reminder to the 3 affected accounts, or temporarily relax mfa_required_roles until they enroll.",
  "affected_user_ids": [4471, 4502, 4489]
}
```

This renders through `AiCardShell` with a `ConfidenceBadge` and a `ReasoningDisclosure` exactly as every
other AI card in the platform does — never a bespoke Settings-only treatment — and offers exactly two links,
never a direct mutation: "Notify affected members" (pre-fills, but does not send, a templated email the
Owner must review and click Send on) and "Review MFA policy" (scrolls to and focuses the
`mfa_required_roles` control in the very form the card sits above). There is no third "Enroll for them"
button, because MFA enrollment is structurally a self-service, per-user action (`# Interactions & Flows`)
that no administrator, human or AI, can perform on another person's behalf.

**Worked example — the CFO Agent's threshold suggestion.** On Approval Workflows, a dismissible card can
appear above `ApprovalChainBuilder` when the CFO Agent's own periodic review of near-miss transfers finds a
live threshold that repeatedly almost triggers: *"2 transfers this quarter cleared KWD 5,000 by less than
8% — lower the `bank.transfer` threshold to KWD 2,000?"*, `confidence: 0.81`. Its single action, "Review
suggested threshold," opens the existing chain's edit form with the `CurrencyInput` pre-filled to the
suggested value and every other field untouched — the human still clicks Save, still runs the identical
always-sensitive `ApprovalCard` gate `# Interactions & Flows` describes for any other chain edit, and can
change the number before saving exactly as if they had typed it themselves.

**Fraud Detection's role here is data, not a card.** The `security_events` rows the Security tab's
recent-activity panel lists (`suspicious_ip`, `rate_limited`, and similar) are produced by Fraud Detection,
but the agent renders no card of its own on this screen — the full synthesis, scoring, and any recommended
response lives exclusively in the AI Command Center's Detected Risks panel, which links back here only when
a finding's remediation is itself a policy change (e.g., "tighten `session_idle_timeout_minutes`"). Settings
is a truthful *consumer* of that agent's output, never a second place its judgment is rendered
independently — exactly the duplication `COMPONENT_LIBRARY.md`'s founding discipline exists to prevent.

**Approval Assistant is invisible by design.** It is the routing engine that resolves every `approval_chains`
row into the specific `ai_approval_requests`/`ApprovalCard` a user sees — on Settings, on the Approval
Center, everywhere a sensitive action exists — but it authors no card of its own anywhere in the platform;
its entire visible footprint on this screen is the `ApprovalCard` instances `# Interactions & Flows` already
walks through.

**The floor that never moves.** Regardless of what `/settings/ai` sets as the company-wide ceiling, and
regardless of any suggestion any of the four agents above ever makes, every `permission_key` in
`AUTHORIZATION_API.md §Policies→1`'s always-sensitive table — plus this document's own extension of that
table to `auth.policies.manage` (`# Interactions & Flows`) — stays hard-pinned to `requires_approval` for
AI-initiated action. An AI Preferences override request attempting to loosen one of those keys is rejected
with the identical `422` `# Data & State` already documents; the frontend surfaces that rejection on the
specific override row, never lets the request silently no-op, and never offers a "force" option of any kind.
Nothing on this screen — not a suggestion, not an override, not the ceiling itself — is a path by which an AI
agent gains one click of unsupervised authority it did not already have before this document existed.

# States

| Region / tab group | Loading | Empty | Error | Special |
|---|---|---|---|---|
| Section Nav | Skeleton row per group label + item, first paint only; never reloads on tab switch | N/A — always fully populated from the fixed 15-item manifest | A single item whose permission check itself fails closed renders locked, never missing | Locked item: `text-ink-disabled` + `Lock` glyph + tooltip naming the permission |
| General / Accounting / Tax / Numbering | `Skeleton` matching the `Card` field layout exactly, never a spinner | N/A — every company always has exactly one `companies` row and one `company_settings` row; this region cannot be empty | Inline `ErrorState` with retry; Save/Cancel stay visible but disabled until the retry succeeds | **Pending confirmation** — a persistent, non-dismissible banner replaces the ordinary inline notice the instant a Save creates an `ai_approval_requests` row; resolves live via Realtime into either the plain saved state or a "Changes were not approved" banner naming the rejection reason |
| Approval Workflows | Skeleton chain-card list | "No company-defined chains yet" beneath the seven system-minimum chains, which are never themselves empty | Inline retry; a single chain's own step-list failing to save reverts just that chain's card | The identical **Pending confirmation** treatment, `kind="company_settings"`, scoped per chain being edited |
| Users & Roles | Skeleton `MemberTable` rows + skeleton invitation list | "No pending invitations" (a light, separate empty state from the member list, which can never be empty — the caller themself is always a row) | Inline retry per sub-list — members and invitations fail independently | Role-assignment/custom-role save shows **Pending confirmation**, `kind="role_grant"`, scoped to the one row changing rather than the whole tab |
| Branches & Departments | Skeleton tree + skeleton rows | "No branches yet — add your first branch" (new company) / "No departments yet" independently per inner tab | Inline retry; the Company Hierarchy panel degrades to a flat list on its own fetch failure rather than blocking the tabs below it | None — ordinary optimistic CRUD, no pending state |
| Security | Skeleton `SessionList` rows + skeleton policy form | N/A for sessions (the caller's own current session always exists); "No security events in the last 30 days" for the activity panel | Inline retry per panel — sessions, policy, events, and MFA factors all fail independently, never as one blanked screen | The Emergency Lock button never disables on a loading state elsewhere on the page — it must stay reachable even mid-fetch or mid-error in another panel |
| Integrations | Skeleton key/webhook rows | "No API keys yet" / "No webhooks yet" independently per inner tab, each with its own primary Create action | Inline retry | Key creation with a write scope shows **Pending confirmation**, `kind="api_key_write_scope"`; the one-time reveal dialog has no loading/empty/error state of its own — it only ever appears after a successful creation |
| AI Preferences | Skeleton ceiling selector + skeleton override list | "No per-permission overrides yet — every permission follows the company ceiling" | Inline retry; a single override row's failed save reverts just that row, never the whole list | A rejected override (looser than the floor/ceiling) is a `422`, not an error state — the row stays editable with an inline validation message |
| Billing | Skeleton `PlanCard` grid + skeleton `SeatUsageBar` | N/A — every company holds exactly one live `company_subscriptions` row at all times | Inline retry; the invoice list fails independently of the plan/payment-method regions | A `past_due` subscription status renders a persistent `warning`-tone banner above the plan grid, distinct from any loading/empty/error state |

The **Pending confirmation** state deserves one general rule beyond the table: it is never rendered as a
full-tab blocking overlay. Every other field on the tab, and every other tab in the Section Nav, stays fully
navigable while one request is pending — a Finance Manager can still read Tax while General's own change
awaits the Owner, because a configuration hub that froze itself every time one small change was in flight
would make routine review work impossible during the (occasionally multi-hour) approval window.

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<640px) | `SettingsSectionNav` is replaced by a `Sheet` (`side="bottom"`) triggered by a Page-Header-level "Settings: General ▾" control — a 3/12-column rail has no room on a 375px viewport, the same collapsible-navigation reasoning `RESPONSIVE_DESIGN.md`'s sidebar pattern already applies one level down. The four group labels render as sticky section headers inside that sheet's own scrollable list; picking a row closes the sheet and navigates. Every form on every tab stacks to a single column; `MemberTable`, `SessionList`, and the invitations list all become one `Card` per row, showing only priority-1 fields (name/email, role or device, status) with a "View details" affordance opening the rest in a full-height `Sheet`; `ApprovalChainBuilder`'s step list becomes vertically stacked cards with explicit "Move up"/"Move down" icon buttons replacing the drag handle (a drag gesture is unreliable on a touch scroll surface at this width); the `PlanCard` grid becomes a single-column, swipeable carousel matching the platform's own KPI-strip carousel pattern. |
| `md` (640–1023px) | The Section Nav returns as a persistent rail once there is room, but collapses to icon-only (72px, tooltip-on-hover per label) rather than the full labeled rail, mirroring the platform Sidebar's own icon-only collapsed state; `MemberTable`/`SessionList` become real `<table>`s again but with lower-priority columns (exact last-active timestamp, IP address, invited-by) hidden until `lg`; `PermissionMatrix` collapses to one `Card` per permission category, checkboxes stacked, rather than the full grid. |
| `lg` (1024–1279px) | The Section Nav's full labeled rail returns (the documented 3/12 split); every table regains its full column set; the two-item inner `Tabs` pairs (Users/Roles, Branches/Departments, API Keys/Webhooks) sit inline with the Page Header rather than wrapping to a second line. |
| `xl`+ (≥1280px) | Unchanged from `lg` in structure — Settings has no fourth, wider-only region the way the Command Center gains a persistent Ask AI rail at `3xl`; the extra width is spent on the Content region's own `max-w` cap and margin, not on a new layout region. |

Touch targets on every row action in `MemberTable`/`SessionList`/invitations, every `ApprovalChainBuilder`
step control, and the Emergency Lock button meet the platform's 44×44px minimum with an 8px gap regardless
of density; the Security tab in particular never applies a compact density mode to its own action buttons
even if a user's global density preference is "Compact," because these are consequential, non-tabular
actions rather than dense reference data.

# RTL & Localization

Every string ships as an EN/AR pair through the platform's i18n layer, and the screen inherits
`DESIGN_LANGUAGE.md`'s and `LAYOUT_SYSTEM.md → RTL Layout Mirroring`'s rules with zero screen-specific
exceptions beyond the ones already fixed platform-wide, plus the following applications specific to a
fifteen-tab configuration hub.

- **The Section Nav mirrors as a whole, not per-item.** Being a `3/12`-column rail at the logical **start**
  of the grid (`# Layout & Regions`), it relocates to the visual right under `dir="rtl"` automatically, with
  zero conditional code, exactly as the platform Sidebar does — the active item's `border-s-2` accent rule
  (never `border-l`/`border-r`) is what makes the indicator land on the correct physical edge in both
  directions without a second implementation.
- **The four group labels and every tab label are real, separately-authored Arabic strings**, not
  transliterations — "الأمان" (Security), "سير عمل الموافقة" (Approval Workflows), "قواعد الترقيم"
  (Numbering) — held to the same calm, precise professional-register bar `DESIGN_LANGUAGE.md → Voice &
  Microcopy` sets for every other screen; a CFO reading the Arabic Settings menu should find it as
  unremarkable-in-a-good-way as the English one.
- **Every session IP address, timestamp, threshold amount, seat count, and confidence percentage renders
  `dir="ltr"`**, via the same `AmountCell`/`ConfidenceBadge`/`FormattedRelativeTime` pinning used everywhere
  else in the platform — a `37.36.12.4` address or a `KWD 5,000.000` threshold reads left-to-right even
  inside a fully Arabic Security tab.
- **Bilingual master-data fields fall back one direction only.** `legal_name_ar`, `trade_name_ar`,
  branch/department `name_ar`, and a custom role's `name_ar` render the Arabic value under an Arabic session
  and fall back to the English value only when the Arabic field is genuinely empty — never the reverse,
  matching `NOTIFICATIONS.md`'s identical rule for bilingual account names; the General tab additionally
  marks `legal_name_ar` optional-but-recommended with a light inline hint, rather than silently accepting a
  blank field forever.
- **Numerals stay Western Arabic digits (`latn`) everywhere on this screen**, including inside Arabic AI
  reasoning text and audit-log entries, per the platform-wide rule that QAYD never switches a financial or
  identifying figure to Eastern Arabic-Indic digits — a session's `last_active_at` and a webhook's retry
  count are exactly as legible in Arabic as in English.
- **Icons follow `ICONOGRAPHY.md`'s fixed mirror table exactly**, with no Settings-specific exception: the
  Section Nav's `Lock` glyph on a locked tab does not mirror (a non-directional object glyph); the
  "Notifications ↗" row's `ArrowUpRight` does not mirror (a screen-relative "up and out" convention,
  identical to a stat-delta indicator); disclosure chevrons inside `ApprovalChainBuilder`'s step list and any
  breadcrumb-style back chevron do mirror, per that table's reading-direction group.
- **Money and thresholds never mirror alignment.** Every `CurrencyInput` and every rendered amount (a
  chain's `threshold_amount`, `SeatUsageBar`'s count) stays right-aligned in both directions — a fixed,
  physical rule, not a logical one, matching `DESIGN_LANGUAGE.md`'s numeral-alignment exception for financial
  figures.
- **The Company Hierarchy tree indents in reading direction** — a child node sits inset from its parent
  toward the line's own start, which is the right edge in Arabic, achieved with logical indentation
  utilities rather than a fixed physical inset that would visually invert the hierarchy under RTL.

| Context | English | Arabic |
|---|---|---|
| Tab label | Security | الأمان |
| Tab label | Approval Workflows | سير عمل الموافقة |
| Tab label | Numbering | قواعد الترقيم |
| Pending banner | Changes here require your own confirmation as Owner before they take effect | تتطلب هذه التغييرات تأكيدك كمالك قبل أن تصبح سارية |
| Pending banner (non-Owner) | Awaiting Owner approval | بانتظار موافقة المالك |
| Emergency Lock confirm | Lock this account? Every active session ends immediately. | هل تريد تجميد هذا الحساب؟ ستُنهى جميع الجلسات النشطة فورًا. |
| One-time key reveal | You will not be able to view this key again | لن تتمكن من رؤية هذا المفتاح مرة أخرى |
| Locked Section Nav tooltip | Requires `companies.billing.read` | يتطلب صلاحية `companies.billing.read` |

# Dark Mode

Dark mode on Settings is a pure token remap — nothing on this screen defines a new color, elevation step, or
radius, per the platform's binding "one token set, two themes" rule. Every `Card`, `Tabs`, `AlertDialog`, and
form primitive resolves through the same semantic tokens every other screen in the platform already uses;
this section states the outcomes specific to Settings' own components, not a second color system.

- **Section Nav.** The rail's background sits at the platform's raised-surface step; its active-item accent
  border and the locked-item's muted text both resolve through the existing accent/ink tokens and their
  dark-mode remaps — the accent gets *lighter*, never more saturated, in dark mode, and the locked item's
  `Lock` glyph and disabled text use the same reduced-opacity ink step in both themes rather than a separate
  dark-only gray.
- **Pending confirmation banners and every `ApprovalCard`** use the platform's single accent for their
  border and badge in both themes — Settings introduces no second "this is pending" color distinct from the
  AI-provenance accent every other screen already reserves for exactly that meaning.
- **Emergency Lock's sub-card is the one place on this screen that intentionally uses a prominent, saturated
  `danger` treatment in both themes** — a bordered, tinted-background card rather than the platform's usual
  "status color, never a large decorative fill" restraint — because this is precisely the one moment
  restraint would be the wrong signal: the platform's single most powerful, irreversible-feeling action
  should look different from every routine control around it, in light mode and dark mode alike.
- **`SessionList`'s current-session row** gets a subtle accent-tint background in both themes (never a
  status color — being "this device" is not a success or warning state), and elevation on its `Card` gets
  lighter, not darker, against the canvas behind it in dark mode, matching the platform's "physical light"
  dark-mode strategy used identically on `KpiTile` and `AiCardShell` elsewhere.
- **`PlanCard`'s selectable border** reuses the identical accent token as every other selection indicator
  platform-wide (the active tab, a selected table row) — Settings does not invent a Billing-specific
  "selected plan" color in either theme.
- **Debit/credit-style coloring never appears on this screen at all** — Settings has no ledger figures, so
  the platform's debit/credit-is-never-color rule is inherited vacuously rather than actively applied, and
  the only genuinely-signed figures on the page (`SeatUsageBar`'s over-limit state, a `past_due` subscription
  banner) use the same semantic tokens every other screen reserves for real signed metrics.
- **Contrast is verified per pairing, not assumed**, at the platform's ≥4.5:1 (text) / ≥3:1 (non-text,
  borders, focus rings) floor in both themes — the Section Nav's locked-item text and the Security Policy
  form's tightened-and-disabled fields are both checked explicitly rather than exempted as "merely
  disabled."
- **Billing's one exported artifact stays theme-independent.** An invoice downloaded from the Billing tab is
  a Stripe-hosted PDF and renders in its own fixed light palette regardless of the viewer's active QAYD
  theme, consistent with the platform-wide rule that anything routinely forwarded outside the app (to an
  auditor, a bookkeeper) never depends on the recipient having opened QAYD in the same theme as the sender.

# Accessibility

Settings targets the same WCAG 2.2 AA floor as every other QAYD surface, with particular weight on
RBAC-explained disabled controls and on the Section Nav's own navigation pattern, since this is the first
screen in the platform to introduce a grouped vertical rail rather than a horizontal `Tabs` strip (`# Layout
& Regions`).

- **The Section Nav is a real `<nav aria-label="Settings sections">` of `<Link>`s**, each group rendered
  under a real, visually plain, non-interactive heading rather than a styled `<div>` masquerading as one, so
  a screen-reader user's landmark and heading lists both enumerate the true four-group, fifteen-item
  structure. The active tab carries `aria-current="page"`, not merely a visual border, and a locked tab
  carries `aria-disabled="true"` plus a `Tooltip`-linked `aria-describedby` naming the exact missing
  permission ("Requires `companies.billing.read`") — never a bare grayed-out row with no stated reason.
- **Keyboard path through the rail is a single `Tab` stop, not fifteen.** Arrow keys move focus item-to-item
  within the Section Nav (roving `tabindex`, mirroring the platform Sidebar's own discipline), `Enter`/
  `Space` activates the focused item, and a single additional `Tab` press leaves the rail entirely for the
  Content region — a keyboard user is never required to press `Tab` fifteen times to reach the form.
- **Every disabled control distinguishes *why* it is disabled, in wording, not just in existence.** A field
  disabled because the caller lacks `manage` reads a permission-shaped sentence ("Requires
  `accounting.journal.void`"); a field disabled because of the platform's own tightening-only business rule
  (a stepper clamped at its current ceiling, a system-minimum chain's last step) reads a distinct,
  rule-shaped sentence ("Cannot be loosened once set" / "A system-minimum chain always keeps at least one
  step") — the two are never collapsed into one generic "You can't do this."
- **The Pending confirmation banner is a calibrated live region, not a silent repaint.** Its arrival (on
  Save) and its resolution (via Realtime) both announce `aria-live="polite"` — never `"assertive"`, since
  neither event is a blocking error — matching the platform's three-tier live-region model and the identical
  "auto-detect, never alarming" posture used for realtime updates elsewhere; it never yanks focus away from
  wherever the user's cursor already is in the form.
- **`PermissionMatrix` is a real, labeled grid, not a table repurposed for visual alignment.** Each checkbox
  carries an `aria-label` combining its category and permission ("Accounting, Post journal entries"), so a
  screen-reader user moving cell to cell never has to hold a dozen category headers in working memory; a
  permission disabled because the grantor does not hold it themselves carries `aria-disabled="true"` and the
  same tooltip text as a visible `aria-describedby`, never a bare `disabled` with no stated reason. Arrow-key
  navigation moves within the grid; `Tab` leaves it in one press.
- **`SessionList` and `MemberTable` use real `<table>` semantics** (`scope="col"`/`scope="row"`, a real
  `<caption>` on each) — a display table is never a styled `<div>` grid; each row's revoke/remove action
  carries an `aria-label` naming the specific device or member ("Revoke session on iPhone 15 Pro — Sara"),
  never a bare icon button relying on visual row position alone.
- **Focus management on every overlay** follows the platform's standard `Dialog`/`Sheet`/`AlertDialog`
  contract: `EmergencyLockDialog` and the ownership-transfer confirmation both move initial focus to their
  heading, never their default-focused destructive action, so a user cannot land on "Lock" by an accidental
  early `Enter`; closing any dialog returns focus to the control that opened it. The API-key one-time-reveal
  dialog traps focus until its "I have copied this key" acknowledgment is checked, since it cannot be
  reopened once dismissed.
- **Color is never the only channel.** A locked Section Nav item is a lock glyph plus muted text plus a
  tooltip, never dimmed color alone; the Emergency Lock sub-card's danger treatment is paired with explicit
  heading text and a warning icon, not a red border alone; a `past_due` Billing banner states "Payment
  failed" in real text beside its warning-tone styling.
- **Currency and permission-key strings stay screen-reader-legible in both scripts.** A `CurrencyInput`'s
  value is announced with its currency code as real text ("5,000.000 Kuwaiti dinar"), never a bare number; a
  permission-key tooltip's dotted, code-formatted string (`accounting.journal.post`) is wrapped so assistive
  technology reads it as a single token rather than three separate words split at each period.

# Performance

- **Fifteen independent route segments, fifteen independent bundles.** Because every tab is its own
  `page.tsx` under the App Router, Next.js code-splits each one automatically — a user who only ever opens
  General never downloads `ApprovalChainBuilder`'s drag-reorder logic, `PermissionMatrix`'s full catalog
  renderer, or the Stripe Elements bundle Billing needs; there is no shared "Settings bundle" to bloat as new
  tabs are added.
- **Heavy, rarely-opened surfaces are additionally code-split within their own tab.** `MfaEnrollmentFlow`
  (WebAuthn's browser credential APIs), the Stripe Elements payment-method form, and `CompanyHierarchyTree`
  (a rarely-expanded panel on an already-secondary tab) all load via `next/dynamic`, matching
  `NOTIFICATIONS.md`'s identical treatment of its own low-traffic Preferences matrix.
- **Parallel prefetch, never a request waterfall.** Every tab's Server Component prefetches its own one-or-
  two queries with `Promise.all` (`# Data & State → SSR hydration`) rather than sequentially — the General
  tab's `company()` and `companySettings()` fetches, for instance, resolve concurrently, not one after the
  other.
- **The permission catalog is fetched once and cached hard.** `GET /api/v1/auth/permissions` backs both
  `PermissionMatrix` and every permission-key `Combobox` on the Approval Workflows tab; per the cache-tuning
  table (`staleTime: 300_000`), it is fetched once per session in practice, not once per tab visit.
- **Realtime subscriptions are scoped to mount, not to the whole app.** `private-company.{id}.settings` is
  subscribed only while some `/settings/*` route is mounted and unsubscribed on navigating away, unlike the
  platform-wide `ai-jobs`/`notifications` channels every screen keeps open — a Settings-specific channel has
  no reason to stay live while a user is deep in Journal Entries.
- **Debounced search everywhere a list can grow large.** The permission-key `Combobox`, the default-tax-code
  picker, and `MemberTable`'s own search box all debounce at 300ms, matching the platform's standard
  `DataTable` convention rather than firing a request per keystroke.
- **No virtualization needed, by design.** Every list on this screen (members, invitations, branches,
  departments, sessions, API keys, webhooks, chains) is bounded by real-world company size — even a large
  holding company rarely exceeds a few hundred members or a few dozen branches — so none of them cross the
  platform's ~200-row virtualization threshold; introducing `@tanstack/react-virtual` here would be
  complexity spent on a scale this screen never reaches.
- **Logo upload is client-compressed before it ever reaches storage.** `FileUpload` on the General tab
  downsizes and re-encodes a company logo client-side (target ≤200KB) before the presigned-upload step, so an
  un-optimized multi-megabyte phone photo never becomes the company's `logo_url` payload weight shown on
  every authenticated page's Topbar.
- **Web Vitals.** LCP on any Settings tab is measured against its own form/list first paint, seeded by the
  SSR hydration pattern so the client's first `useQuery` resolves instantly rather than re-issuing a request
  the server already made; CLS stays near zero because every tab's skeleton matches its own eventual
  field/row count rather than one generic shape reused across all fifteen tabs.

# Edge Cases

1. **A single-Owner company's Owner both proposes and must approve a General/Accounting/Tax/Numbering
   change.** Resolved in `# Interactions & Flows` — Save and Approve are two distinct UI actions on two
   distinct components, the second gated by the identical Owner-tier step-up re-confirmation the platform
   already requires for any sensitive approval; there is no auto-approval path merely because initiator and
   approver are the same person.
2. **Two Owners edit the same General fields from two different sessions within seconds of each other.** The
   second Save still creates its own `ai_approval_requests` row against the *current* server state at
   submission time, never a client-cached snapshot; if the first change already resolved, the second Owner's
   pending diff is recomputed against the new baseline before their own approval step, and the realtime
   channel keeps both sessions' banners in sync rather than one silently overwriting the other's view.
3. **Deleting a system-minimum approval chain via a direct API call, bypassing the UI's own omitted-not-
   disabled delete button.** The server still returns `409` (`# Data & State`); the builder was never the
   only enforcement layer, it simply never offers the control that would trigger it.
4. **A custom role's creator later has their own permissions reduced below what they originally granted the
   role.** The already-created role keeps its originally-granted permission set — Settings never
   retroactively shrinks an existing role when its creator's own access changes later; the
   privilege-escalation guard applies only at the moment of granting, not as an ongoing constraint on roles
   already created.
5. **Revoking one's own current session directly, rather than through "Sign out of all other devices."**
   `SessionList` never renders a revoke control on the `is_current` row at all — the only correct affordance
   for ending your own current session is the ordinary sign-out in the `UserMenu`, and Settings does not
   duplicate that as a second, differently-worded control that could be confused with "revoke."
6. **Emergency-locking a Branch or Department whose members have an unrelated pending approval request
   in flight.** Their existing `ai_approval_requests` rows are neither cancelled nor auto-rejected by the
   lock — a lock revokes sessions and blocks new logins, it does not mutate unrelated business records — so
   a locked Finance Manager's own already-submitted, still-pending journal entry remains exactly as pending
   as before, waiting for someone else with the approver role (or the same person, once unlocked) to act on
   it.
7. **Inviting an email address that already belongs to an existing member of the same company.** The invite
   endpoint rejects this distinctly from the pending-invite case ("This person is already a member — edit
   their role instead" rather than "An invitation is already pending"), and `InviteMemberDialog` links
   directly to that member's row in `MemberTable` rather than leaving the user to find it themselves.
8. **Transferring ownership to a member who is later removed from the company.** Ownership transfer is a
   point-in-time action — once complete, the new Owner is an ordinary Owner-tier `company_users` row like
   any other; if they are later removed, the company simply has no Owner until someone with
   `settings.company.manage` initiates another transfer, the same temporarily-ownerless state
   `COMPANY_STRUCTURE.md`'s own lifecycle already allows for between an offboarding and a new appointment.
9. **A custom role is granted a permission that is later retired from the platform's own catalog entirely.**
   `PermissionMatrix` simply stops rendering the retired permission as grantable to new/edited roles, while
   any role that already held it keeps the grant inert (checked server-side against the live catalog at
   enforcement time) rather than the UI silently pretending the grant never existed — a role's checklist
   state is never rewritten out from under an administrator without their own edit.
10. **A webhook's signing secret is rotated while a domain event is already queued for delivery to the old
    secret.** The in-flight delivery completes signed with the secret live at enqueue time; only deliveries
    enqueued after the rotation use the new one — the frontend surfaces this as a plain note in the rotation
    confirmation ("Deliveries already in flight will use the previous secret") rather than implying an
    instantaneous cutover the underlying queue cannot actually guarantee.
11. **Downgrading to a plan whose `seats_included` is below the company's current `seats_used`.** Blocked
    client-side with a named remediation before the request ever fires; the rare direct-API attempt receives
    the server's own `422` naming the exact seat delta that must be resolved first.
12. **A chain's stored `threshold_currency` differs from the company's current base currency** (a chain
    configured before a base-currency change, or deliberately thresholded in a different currency for a
    multi-currency group). `ApprovalChainBuilder` renders the threshold with its own stored currency code
    explicitly shown beside the amount, never silently reinterpreted in the company's current base currency,
    so a `5,000 USD` threshold never displays as an unlabeled `5,000` a Kuwaiti reviewer would reasonably
    assume is KWD.
13. **Removing an MFA factor that is the caller's only remaining enrolled factor, while `mfa_required_roles`
    mandates MFA for their role.** The removal confirmation names this specific consequence explicitly ("You
    have no other MFA factor enrolled — you will be required to enroll a new one at your next login") rather
    than silently leaving enforcement and enrollment out of step; the account is not locked out, but the very
    next login stops at the enrollment step instead of a normal password screen.
14. **A company switch happens while an always-sensitive Settings form has an unsaved edit, or while a
    Pending confirmation banner is displayed.** The switcher's confirmation dialog names the in-progress
    state explicitly ("You have unsaved Accounting settings" / "A General settings change is awaiting
    approval") before proceeding; `queryClient.clear()` and `router.refresh()` still fire together exactly as
    they do everywhere else, and the abandoned pending request itself is untouched server-side — it simply
    stops being visible until the user switches back to that company and reopens the tab.
15. **A Warehouse Employee's shared kiosk-PIN terminal session appears in `SessionList`.** It renders as
    exactly one row — the underlying device-bound session — not one row per worker who has PIN'd into it
    during the shift; `SessionList` reflects `sessions`, not `mfa_factors`/PIN swaps, and revoking that one
    row signs out the whole terminal, which the row's own device name ("Warehouse Scanner #3") makes
    unambiguous before anyone clicks revoke.
16. **A user's account locale changes from English to Arabic mid-session while a Pending confirmation banner
    or an AI suggestion card is already on screen.** Chrome copy (tab labels, button text, the banner's own
    template strings) switches immediately on the next render, exactly like `NOTIFICATIONS.md`'s identical
    rule for its own chrome-vs-content distinction; an already-fetched AI `reasoning` string, however, was
    generated server-side in whatever locale was active at generation time and is not retroactively
    retranslated client-side — a suggestion card generated in English keeps its English reasoning until the
    next time that agent runs.

# End of Document
