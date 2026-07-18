# User Profile & Preferences — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PROFILE
---

# Purpose

Every other screen this documentation set specifies belongs to the company — a Journal Entry, an
Invoice, a Trial Balance all exist because a tenant's books need them, and every permission gating
them is a company-scoped RBAC grant per `docs/foundation/PERMISSION_SYSTEM.md`. This document
specifies the one screen in QAYD that belongs to the **person** instead: `app/(app)/profile`, where a
signed-in human manages who they are (name, avatar, contact details, language and locale), how QAYD
looks and behaves for them personally (theme, density, text size), how they hear about things
(notification channels, deep-linked to their owning screen rather than duplicated here), how their
account is secured (password, multi-factor authentication, active sessions and devices), which
companies they belong to and under which role, the personal API tokens they have issued for their own
scripts, and — the one place QAYD is legally and ethically obligated to put it — how they export or
erase the personal data QAYD and its AI layer hold about them. Nothing on this screen is a financial
fact. A Finance Manager's own avatar, a CFO's own MFA factor, an Accountant's own dark-mode
preference carry zero accounting weight and never touch a `journal_entries` row, a `customers`
balance, or any other tenant ledger data — which is precisely why this screen requires no
company-scoped RBAC permission at all (see `# Route & Access`) and is reachable identically by every
authenticated user regardless of their role at the active company.

This document does not re-specify capability that already has an authoritative owner elsewhere; it
integrates with it. Three boundaries matter enough to state up front, because getting them wrong
would mean two documents quietly drifting out of sync with each other, exactly the failure mode
`docs/frontend/README.md`'s founding rule (`# Purpose`) and `COMPONENT_LIBRARY.md`'s founding
principle both exist to prevent:

1. **Notification channel and category preferences are owned, in full, by
   `docs/frontend/NOTIFICATIONS.md`.** That document already specifies the five-category ×
   five-channel preference matrix, its backing `GET`/`PATCH /api/v1/notifications/preferences`
   contract, and its canonical route, `app/(app)/notifications/preferences/page.tsx` — reachable
   today from the Topbar's `UserMenu` via a "Notification settings" item
   (`NOTIFICATIONS.md → Route & Access`). This document adds Profile's own Section Rail entry point
   to that same, unduplicated route and component; it does not introduce a second matrix. See
   `# Layout & Regions` and `# Data & State` for exactly how that composition works.
2. **Theme, density, and text-size preferences were sketched, ahead of this document, as living at
   `app/(app)/settings/appearance/page.tsx`** (`DARK_MODE.md → Toggle UX → Placement`:
   "its canonical, fuller home at `app/(app)/settings/appearance/page.tsx`"). This document
   supersedes that placeholder route the same way `docs/frontend/CUSTOMERS.md` superseded
   `FRONTEND_ARCHITECTURE.md`'s own provisional `sales/customers` sketch: Profile's **Preferences**
   section, specified in full below, is that screen's real, shipped home. `app/(app)/settings/appearance`
   is not built as a second route; every existing reference to it (`DARK_MODE.md`, and the avatar-menu
   toggle's own second, redundant placement, which is unaffected) should be read as pointing at
   `app/(app)/profile/preferences` from this document forward.
3. **Company-wide administration is a different screen with a different audience.** Managing *other*
   users' company membership, roles, or company-scoped API keys and OAuth clients is
   `app/(app)/settings/team` and `app/(app)/settings/api-keys` (`FRONTEND_ARCHITECTURE.md`'s route
   tree), gated by `auth.session.manage_others`, `auth.mfa.manage_others`, and the `auth.apikey.*`
   family respectively (`docs/api/AUTHENTICATION_API.md`). Profile never renders an "others" action —
   every mutation this screen exposes operates on the caller's own `user_id`, and where this screen's
   content overlaps with an admin capability (a personal API token, a session, a company membership),
   it is scoped to "mine," with a plain link out to the admin surface for anyone who separately holds
   the broader permission. This is the same "reuse the mutation, add a second, narrower entry point"
   discipline `NOTIFICATIONS.md` already models for `ApprovalCard` and the Approval Center.

Two further platform facts ground almost every section below and are restated here once rather than
re-derived section by section. First, `users` is QAYD's one genuinely global, non-tenant-scoped table
(`docs/database/ERD.md → users`: "intentionally not tenant-scoped, since one person may hold accounts,
roles, and sessions across multiple companies") — which is exactly why this screen's data,
unlike every other screen in the platform, is not re-fetched or invalidated on a company switch
(`# Edge Cases` #1 states this precisely). Second, several of this screen's own actions —
changing a password, removing an MFA factor, requesting account erasure — are more consequential to
a person's own security than most of what a role's day-to-day permissions gate, and are treated with
comparable weight: re-authentication step-ups, mandatory confirmations, and, for account deletion, a
reviewed request rather than an instant self-serve delete, exactly mirroring the platform's existing
posture that a sensitive action is never a single accidental click away (`AUTHENTICATION_API.md → MFA
→ Login Step-Up`; `docs/database/DATABASE_ARCHIVING.md → Right-to-erasure conflicts`).

# Route & Access

## Route tree

```text
app/(app)/profile/
├── layout.tsx                # Section Rail shell — Server Component, resolves `GET /api/v1/users/me` once
├── page.tsx                  # Personal Info — the default section, no redirect hop
├── preferences/page.tsx      # Appearance & Preferences (theme, density, text size, reduced motion)
├── security/page.tsx         # Password, MFA factors, active sessions/devices
├── companies/page.tsx        # Company memberships list + switch
├── tokens/page.tsx           # Personal API tokens ("mine" view over auth/api-keys)
└── privacy/page.tsx          # Data export request + right-to-be-forgotten actions
```

Every leaf follows the platform's standard dynamic-segment-free convention for a fixed, small set of
sections (the same flat-file pattern `NOTIFICATIONS.md` uses for its own `Inbox`/`Preferences` pair)
rather than a single `page.tsx` branching on a `?section=` query param — each section is independently
bookmarkable, back-button-safe, and deep-linkable from a toast, an email, or the Command Palette,
consistent with `FRONTEND_ARCHITECTURE.md`'s stated preference for `<Link>`-driven tabs over
client-side tab state wherever a URL can reasonably describe the view.

## Access gate

| | |
|---|---|
| Permission | **None.** Every section on this screen operates on the caller's own `user_id` and requires nothing beyond an authenticated session — matching the exact "no dedicated permission that gates the route itself" posture `DASHBOARD.md` and `AI_COMMAND_CENTER.md` (frontend) both state for their own routes, and the identical reasoning `NOTIFICATIONS.md` gives for its own Inbox/Preferences pair: this is a personal inbox over the caller's own rows, not a company resource RBAC needs to arbitrate. |
| Chrome entry point | `UserMenu`'s existing "Profile" item (`NAVIGATION_SYSTEM.md`: "avatar, name, active role, 'Switch company,' 'Profile,' 'Sign out.'") now resolves to `app/(app)/profile` rather than an unbuilt placeholder |
| Breadcrumb | `Profile` — a single, non-linked, root-level crumb, the same pattern `NOTIFICATIONS.md` and `DASHBOARD.md` use for a screen with no parent module |
| Section nav | The Section Rail (`# Layout & Regions`), rendered as real `<Link>`s exactly as every other module-level sub-navigation in the platform is (`FRONTEND_ARCHITECTURE.md → Layout nesting`) |
| Company/branch scope | **Fixed to none.** Unlike every accounting screen, Profile does not read `X-Company-Id` for its own data at all (`users`, `user_preferences`, `mfa_factors`, `sessions`, and `personal_access_tokens` are either global or the caller's own rows filtered by `user_id`); the one section that does surface company context — Company Memberships — reads it from `GET /api/v1/auth/me`'s `companies` array, which already spans every company the caller belongs to regardless of which one is currently active. |
| Keyboard entry | `G` then `P` (extending `ACCESSIBILITY.md`'s existing chord table, which reserves `D`/`A`/`L`/`B`/`R`/`I`; `P` was unused) navigates here from anywhere in the app |

A user who is a member of exactly one company and a user who juggles four see an identical Profile
shell; the only section whose content varies by membership count is Company Memberships
(`# Layout & Regions`), and even there the difference is data volume, never a different permission
check.

## Section-scoped concerns

Nothing below is a company RBAC permission; this table exists because three sections do have their
own narrow guard, and stating them once here avoids repeating the caveat in every subsection.

| Section | Guard | Reasoning |
|---|---|---|
| Personal Info | None beyond authentication | A user always may read/write their own `full_name_en`/`full_name_ar`/`phone`/`avatar_url`/`locale`/`timezone` |
| Preferences | None beyond authentication | `user_preferences` is a 1:1-per-user table with no company dimension (`THEMING.md → Density & Text-Size Preferences`: "permission-free beyond authentication — there is nothing here another user's RBAC grant could need to protect") |
| Security → Sessions | None beyond authentication for the caller's **own** sessions; `auth.session.manage_others` is never checked or referenced on this screen | Managing another user's sessions is `/settings/team`'s job, out of scope here |
| Security → MFA | None beyond authentication, **except**: removing a factor when it is the last **verified** factor and the caller's role has mandatory MFA is blocked server-side (`422 LAST_MFA_FACTOR_REQUIRED`), never a client-side-only check | `AUTHENTICATION_API.md → MFA → Removing a Factor` |
| Company Memberships | None to view; leaving a company the caller is the sole Owner of is blocked server-side (`422 SOLE_OWNER_CANNOT_LEAVE` — this document's proposed extension, `# Data & State`) | Prevents an orphaned company with no Owner, the same category of guard `LAST_MFA_FACTOR_REQUIRED` already models |
| Personal API Tokens | None to view/create/revoke **the caller's own** keys; the "Manage all company keys" out-link renders only when the caller also holds `auth.apikey.read` at the active company | Company-wide key visibility is a company-scoped grant per `AUTHENTICATION_API.md → API Keys`; a personal key the caller created is always visible to them regardless |
| Data & Privacy | None to request an export; account-erasure requests require a fresh MFA step-up (`# Interactions & Flows`) regardless of role | The single most irreversible action on this screen gets the platform's step-up treatment even though no accounting permission is technically implicated |

# Layout & Regions

Profile extends `LAYOUT_SYSTEM.md`'s existing **Detail Page Template** — "the full record view for one
entity" — rather than inventing a sixth layout shape, per that document's own binding rule and the
identical restraint `CUSTOMERS.md` and `NOTIFICATIONS.md` both exercise for their own screens. The one
entity here is the caller's own account, and the Detail Template's ordinary horizontal
`Tab/Segment Nav` slot is replaced with a **persistent vertical Section Rail** at `md`-and-above — an
explicit, named extension, not a bypass, justified by scale: Personal Info, Preferences, Security,
Company Memberships, Personal API Tokens, and Data & Privacy is six real sections (seven counting the
Notifications rail item that deep-links out), too many for a single-row tab strip to hold without
either wrapping awkwardly or truncating labels, and a vertical rail is already an established QAYD
pattern at this width (`RESPONSIVE_DESIGN.md`'s own sidebar-becomes-icon-rail precedent). The Detail
Template's `Summary Rail` slot is dropped entirely — there is no lifecycle status, amount, or
approval-chain progress to summarize for a personal settings screen — and the Section Rail takes over
that column position instead.

```
┌───────────────┬───────────────────────────────────────────────────────────┐
│  ● Sara Al-K.  │  Personal Info                                            │
│  Finance Mgr   │  ─────────────────────────────────────────────────────── │
│  ────────────  │                                                           │
│  Personal Info │   [ 👤 ]   Sara Al-Kandari                                │
│  Preferences   │            سارة الكندري                                   │
│  Notifications↗│   [Change photo]                                         │
│  Security      │                                                           │
│  Companies     │   Full name (EN)   [ Sara Al-Kandari              ]      │
│  API Tokens    │   Full name (AR)   [ سارة الكندري                  ]      │
│  Data & Privacy│   Email            sara.alkandari@qayd-demo.com  ✓       │
│                │   Phone            [ +965 5011 2233               ]      │
│                │   Language          ( ) English   (•) العربية            │
│                │   Timezone          [ Asia/Kuwait ▾ ]                    │
│                │                                          [Save changes]  │
└───────────────┴───────────────────────────────────────────────────────────┘
```

| Region | Content |
|---|---|
| Rail Header | `AvatarWithFallback`, the caller's own name, and their **role at the currently active company** only (e.g., "Finance Manager") — not a merged list across every membership, which belongs to the Companies section |
| Section Rail | Six `<Link>` items (Personal Info, Preferences, Notifications, Security, Companies, API Tokens, Data & Privacy — seven, Notifications included) with an active-state underline/fill matching `Tabs`' existing `layoutId`-animated indicator (`COMPONENT_LIBRARY.md → Tabs`), reused vertically rather than horizontally; the **Notifications** item alone carries a small external-link glyph and navigates to `/notifications/preferences` instead of a local segment (`# Purpose` #1) |
| Main Column | The active section's own form/list content, described per section below |
| Sticky Save Bar | Personal Info and Preferences' write-heavy forms surface a bottom sticky bar once the header scrolls out of view, mirroring the Form Page Template's identical sticky-footer convention (`LAYOUT_SYSTEM.md → Form Page Template`) — Preferences almost never needs it in practice, since every control there saves instantly on change (`# Interactions & Flows`), but the bar is present for the rare batched edit (e.g., toggling reduced-motion and text-size in the same visit before either round-trips) |

Below `md`, the Section Rail collapses to a horizontally-scrolling `Tabs` strip pinned under the page
header (`RESPONSIVE_DESIGN.md`'s "cards, not tables" mobile posture, applied here to navigation rather
than a data grid), and the Rail Header's avatar/name/role moves inline above it rather than beside the
content — see `# Responsive Behavior` for the full breakpoint table.

## Preferences section (Main Column detail)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  Preferences                                                               │
│  Choose how QAYD looks and feels on this device.                           │
├───────────────────────────────────────────────────────────────────────────┤
│  Theme            [ 🖥 System | ☀ Light | 🌙 Dark ]                        │
│  Density          [ Comfortable | Compact ]                                │
│  Text size        [ Small | Default | Large ]                              │
│  Reduced motion   ( ) Follow system  ( ) Always on  ( ) Always off        │
└───────────────────────────────────────────────────────────────────────────┘
```

Every control here is the exact component `DARK_MODE.md`/`THEMING.md` already specify — `ThemeToggle`'s
three-way `ToggleGroup`, and its density/text-scale siblings built on the identical primitive — composed
inside Profile's Main Column rather than redrawn. No control on this section ever waits for a network
round trip before visibly applying (`# Interactions & Flows`).

## Security section (Main Column detail)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  Security                                                                   │
├───────────────────────────────────────────────────────────────────────────┤
│  Password                                            [Change password]     │
│  Last changed 94 days ago                                                   │
├───────────────────────────────────────────────────────────────────────────┤
│  Two-factor authentication                                    [Add method] │
│  ✓ Authenticator app — verified                        [Remove]           │
│  ✓ Backup codes — 6 of 10 remaining              [Regenerate]             │
│  ○ SMS to +965 •••• 2233 — not enrolled                [Enroll]           │
├───────────────────────────────────────────────────────────────────────────┤
│  Active sessions                              [Sign out of other devices] │
│  📱 iPhone 15 Pro — Sara         Kuwait City       This device             │
│  💻 Chrome — MacBook Pro         Kuwait City       2 days ago    [Sign out]│
└───────────────────────────────────────────────────────────────────────────┘
```

## Company Memberships section (Main Column detail)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  Companies                                                                  │
├───────────────────────────────────────────────────────────────────────────┤
│  ● Al-Kandari Trading Co.        Finance Manager      Active   [—]         │
│  ○ Gulf Fresh Foods W.L.L.       Read Only                     [Switch]    │
│                                                          [Leave company]    │
└───────────────────────────────────────────────────────────────────────────┘
```

The active company's row carries no "Switch" button (it is already active) and no "Leave" affordance
if the caller is that company's sole Owner (`# Route & Access → Section-scoped concerns`); every other
membership row offers both "Switch" (`POST /api/v1/auth/switch-company`, unchanged from
`AUTHENTICATION_API.md`) and "Leave," gated only by the sole-Owner guard.

# Components Used

Every element below is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue or a documented,
narrow composition of it — no one-off control is introduced for this screen.

| Component | Source | Role on this screen |
|---|---|---|
| `AvatarWithFallback` | existing shared component, named verbatim in `EMPLOYEES.md`: "the platform's standard avatar-fallback component used across every profile screen" | Rail Header portrait; falls back to initials on a missing or failed `avatar_url` load |
| `ThemeToggle` | `components/settings/theme-toggle.tsx` (existing, `DARK_MODE.md`) | Preferences section's theme control, composed unchanged |
| `ToggleGroup` | `components/ui/toggle-group.tsx` (existing shadcn/Radix primitive) | Density and text-size controls, styled identically to `ThemeToggle`'s own three-way pattern |
| `RadioGroup` | `components/ui/radio-group.tsx` (existing) | Reduced-motion's three-way follow/on/off control; language selection in Personal Info |
| `Switch` | `components/ui/switch.tsx` (existing) | None directly on this screen's own sections — reused unmodified when the Notifications rail item is followed to `NOTIFICATIONS.md`'s matrix |
| `Card` | `components/ui/card.tsx` (existing) | Every section's grouped-content container (Security's Password/MFA/Sessions blocks, each its own `Card`) |
| `Input`, `Textarea`, `Select` | `components/ui/*` (existing) | Personal Info's name/phone/timezone fields |
| `Form`, `FormField`, `FormItem`, `FormLabel`, `FormControl`, `FormDescription`, `FormMessage` | `components/ui/form.tsx` (existing shadcn wrapper over React Hook Form) | Every form on this screen, wired exactly per `ACCESSIBILITY.md → Forms Accessibility` |
| `AlertDialog` | `components/ui/alert-dialog.tsx` (existing) | Password-change confirmation, MFA factor removal, "Leave company," account-erasure request — every irreversible or session-invalidating action on this screen |
| `Dialog` | `components/ui/dialog.tsx` (existing) | TOTP enrollment's QR-code step, backup-codes reveal, avatar crop/upload |
| `Sheet` | `components/ui/sheet.tsx` (existing) | Below `sm`, the Section Rail's mobile equivalent and the "Add method" MFA picker both render as a bottom `Sheet` rather than a `Dialog` (`RESPONSIVE_DESIGN.md → Pattern 4`) |
| `Badge` | `components/ui/badge.tsx` (existing) | Company role labels ("Finance Manager," "Read Only"), the active-company dot, "Active"/"Suspended" `company_users` state |
| `Tabs` | `components/ui/tabs.tsx` (existing) | The Section Rail's own underlying implementation (rendered vertically) and its `md`-below horizontal-scroll fallback |
| `Tooltip` | `components/ui/tooltip.tsx` (existing) | "Why can't I remove this?" on a disabled last-MFA-factor or sole-Owner-leave control, per `ACCESSIBILITY.md`'s RBAC-aware-disabled-controls rule extended to these account-level guards |
| `Skeleton` | `components/ui/skeleton.tsx` (existing) | Loading placeholders matching each section's final layout |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Company Memberships when a data fetch fails; Personal API Tokens when the caller has never created one |
| `useApiToast` | existing hook | Every mutation's success/error toast |
| `FormattedRelativeTime` | existing shared formatter | Session "last active," password "last changed," token "last used" |
| `CopyableSecret` (**new**) | `components/shared/copyable-secret.tsx` | The one-time plaintext display of a newly created personal API token and freshly generated backup codes — a monospace field with a "Copy" button and an explicit "shown once" warning, factored out because both `AUTHENTICATION_API.md`'s API Key creation and its backup-codes issuance need the identical shown-once treatment |

`CopyableSecret` is the only new component this document introduces, and it introduces no new
visual language — it composes `Input` (`readOnly`, monospace) and `Button` exactly as
`AUTHENTICATION_API.md`'s own worked examples already render a `plaintext_key`/`backup_codes` payload,
factored into one component because two unrelated sections of this same screen need it verbatim.

# Data & State

## Endpoints

| Purpose | Endpoint | Notes |
|---|---|---|
| Read personal profile | `GET /api/v1/users/me` | Returns the same `user` shape `GET /api/v1/auth/me`'s `data.user` nests (`docs/api/AUTHENTICATION_API.md → GET /me`), addressed as its own resource so the Personal Info form's `defaultValues` and this endpoint's write counterpart share one shape one-to-one |
| Update personal profile | `PATCH /api/v1/users/me` | Body: any of `full_name_en`, `full_name_ar`, `phone`, `locale`, `timezone`. Changing `email` is **not** accepted here — see below |
| Request email change | `POST /api/v1/users/me/email-change` | Body `{ "new_email": "..." }`; sends a verification link to the **new** address per `ERD.md → users → Update Rules` ("email changes require re-verification... `email_verified_at` reset to `NULL`"); `email` on the `users` row only changes once that link is followed, never on this call directly |
| Upload avatar | `POST /api/v1/users/me/avatar` | Multipart; returns `{ "avatar_url": "https://cdn.qayd.app/avatars/usr_9f21c3.webp" }`. Server-side resize/webp conversion; a failed upload leaves the previous `avatar_url` untouched and the UI falls back to `AvatarWithFallback`'s initials rendering |
| Remove avatar | `DELETE /api/v1/users/me/avatar` | Sets `avatar_url` to `NULL`; reverts to the initials fallback |
| Read preferences | `GET /api/v1/users/me/preferences` | Returns `theme`, `density`, `text_scale`, `reduced_motion_override`, plus the free-form UI-chrome preferences (`sidebar_collapsed`, `ai_rail_open`, `table_density`, `dashboard_layout`, `calendar_default_view`) already written by `LAYOUT_SYSTEM.md`, `NAVIGATION_SYSTEM.md`, `DASHBOARD.md`, and `CALENDAR.md` — one resource, read once by this screen for the four it renders controls for |
| Update preferences | `PATCH /api/v1/users/me/preferences` | Body carries only the changed key(s), e.g. `{ "theme": "dark" }` — see the storage-location reconciliation note below |
| Change password | `POST /api/v1/auth/password/change` | Body `{ "current_password", "new_password", "new_password_confirmation" }`. **Not present in `AUTHENTICATION_API.md`'s own Endpoint Inventory today** (that table lists only the logged-out `password/forgot`/`password/reset` pair); this document names the missing authenticated counterpart explicitly, following the exact `<verb-phrase>` shape the rest of `/auth` already uses, because its entire behavior is already fully specified by that document's own Revocation table (`# Interactions & Flows` restates it precisely) — this is a naming gap, not a new behavior |
| List sessions | `GET /api/v1/auth/sessions` | Unchanged from `AUTHENTICATION_API.md → Managing Active Sessions/Devices` |
| Revoke one session | `DELETE /api/v1/auth/sessions/{session_id}` | Unchanged; this screen never calls it against the caller's own `is_current` session (`# Interactions & Flows`) |
| Revoke all other sessions | `DELETE /api/v1/auth/sessions` | Unchanged; excludes the caller's current session by design |
| MFA enroll/verify/remove/backup-codes | `POST /api/v1/auth/mfa/totp/enroll`, `/mfa/totp/verify`, `/mfa/sms/enroll`, `/mfa/sms/send`, `/mfa/webauthn/register/options`, `/mfa/webauthn/register/verify`, `/mfa/backup-codes/regenerate`, `DELETE /api/v1/auth/mfa/{factor_id}` | All unchanged from `AUTHENTICATION_API.md → MFA`; this screen is simply where every one of these is triggered from in the product |
| List my API tokens | `GET /api/v1/auth/api-keys?filter[created_by]=me` | Same resource and envelope `AUTHENTICATION_API.md → API Keys` already defines; `filter[created_by]=me` is this document's proposed, narrowly-scoped addition — a personal-scope view over a company-owned table, not a new table |
| Create a personal token | `POST /api/v1/auth/api-keys` | Unchanged; `created_by` is stamped server-side from the session, never client-supplied |
| Revoke a personal token | `DELETE /api/v1/auth/api-keys/{id}` | Unchanged; this screen only ever renders this action on a row whose `created_by` matches the caller |
| List company memberships | *(no dedicated call)* | Reads `companies` off the already-fetched `GET /api/v1/auth/me` (`SessionProvider`'s existing data), never a second request for the same array |
| Switch active company | `POST /api/v1/auth/switch-company` | Unchanged from `AUTHENTICATION_API.md → Switching Company Context` |
| Leave a company | `DELETE /api/v1/users/me/companies/{company_id}` | This document's proposed extension: sets the caller's own `company_users.status` to `'left'` (a new, self-initiated sibling to the existing `'suspended'`/admin-offboarding path). Returns `422 SOLE_OWNER_CANNOT_LEAVE` if the caller is that company's only `status='active'` Owner-role member, and `409 CANNOT_LEAVE_ACTIVE_COMPANY` if `company_id` matches the caller's currently active company (switch away first) |
| Request a personal data export | `POST /api/v1/users/me/data-export` | This document's proposed, user-scoped narrowing of the company-level export job `docs/database/DATABASE_ARCHITECTURE.md` already describes ("a background job... streams every RLS-scoped table... into a per-table CSV bundle, zipped and delivered through a signed, time-limited R2 URL"); returns `{ "export_id": "exp_7a1c9e", "status": "queued" }` immediately, never a synchronous download |
| Check export status | `GET /api/v1/users/me/data-export/{export_id}` | Polled (or, if the caller has this tab focused, resolved via the same short-`staleTime` refetch pattern every other async job in the platform uses) until `status: "ready"`, at which point `data.download_url` is a signed, time-limited R2 URL |
| Erase AI personalization memory | `POST /api/v1/ai/memory/erase-all` | Unchanged from `docs/ai/memory/USER_MEMORY.md → Write Path & Governance`: "self-service... no approval chain," scoped to the currently active company; repeating it once per membership clears every company's personalization graph the caller has ever built |
| Request account deletion | `POST /api/v1/users/me/erasure-request` | This document's proposed endpoint for the account-level "right to be forgotten," deliberately **not** an instant delete — see `# Interactions & Flows` and `docs/database/DATABASE_ARCHIVING.md → Right-to-erasure conflicts` for why |

**A note on where UI preferences actually live, reconciled here because Profile is the one screen
whose entire Preferences section depends on the answer.** Three existing documents describe this
storage differently: `THEMING.md → Density & Text-Size Preferences` defines a dedicated
`user_preferences` table (typed columns, `CHECK` constraints, exposed at `/api/v1/me/preferences`);
`DARK_MODE.md → Persistence & SSR` instead illustrates `theme` living inside a `users.settings` JSONB
bag, written via `PATCH /api/v1/users/me`; and `LAYOUT_SYSTEM.md`, `NAVIGATION_SYSTEM.md`,
`DASHBOARD.md`, and `CALENDAR.md` all four independently call `PATCH /api/v1/users/me/preferences` for
their own, unrelated per-user settings (sidebar state, dashboard layout, calendar view). This document
standardizes on **`THEMING.md`'s dedicated `user_preferences` table** as the schema of record — a
fixed, small set of enumerable settings (`theme`, `density`, `text_scale`, a nullable
`reduced_motion_override`, plus the four free-form keys the majority-usage docs already write) is
exactly the case `DARK_MODE.md`'s own JSONB-column reasoning elsewhere calls for "a genuinely evolving
bag of preferences" — the opposite of what this data actually is — so the typed table is the better
fit by that document's own stated criterion. On the endpoint path, this document standardizes on
**`/api/v1/users/me/preferences`**, matching the four documents that already ship against it, over
`THEMING.md`'s lone, shorter `/api/v1/me/preferences` — the same tie-breaking rule `NOTIFICATIONS.md`
already used for its own query-key drift ("the one the already-implemented component uses rather than
an illustrative excerpt"). `THEMING.md` and `DARK_MODE.md` should be read as needing their own path/
storage snippets updated to match on next edit, exactly as `NOTIFICATIONS.md` left an identical
one-line reconciliation note for `FRONTEND_ARCHITECTURE.md`'s realtime listener.

## Response examples

`GET /api/v1/users/me`:
```json
{
  "success": true,
  "data": {
    "id": "usr_9f21c3",
    "full_name_en": "Sara Al-Kandari",
    "full_name_ar": "سارة الكندري",
    "email": "sara.alkandari@qayd-demo.com",
    "email_verified_at": "2026-06-01T08:12:00Z",
    "phone": "+96550112233",
    "phone_verified_at": null,
    "avatar_url": "https://cdn.qayd.app/avatars/usr_9f21c3.webp",
    "locale": "ar",
    "timezone": "Asia/Kuwait",
    "mfa_enabled": true,
    "created_at": "2025-11-02T06:40:00Z"
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "6d2a9c1b-4f3e-4b8a-9e1c-6b4a2f8d9c22",
  "timestamp": "2026-07-16T09:05:00Z"
}
```

`PATCH /api/v1/users/me/preferences`, toggling density only:
```json
// Request
{ "density": "compact" }
```
```json
// Response
{
  "success": true,
  "data": {
    "theme": "system", "density": "compact", "text_scale": "default",
    "reduced_motion_override": null,
    "sidebar_collapsed": false, "ai_rail_open": true, "table_density": {},
    "dashboard_layout": null, "calendar_default_view": "week"
  },
  "message": "Preferences updated.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "2f8a1c9e-3b7d-4a3c-9e1b-8a2c9f4d1e7b",
  "timestamp": "2026-07-16T09:07:12Z"
}
```

`GET /api/v1/auth/api-keys?filter[created_by]=me`:
```json
{
  "success": true,
  "data": [
    {
      "id": "pat_3a9c7e2b",
      "name": "Personal export script",
      "key_prefix": "qyd_live_51H8",
      "environment": "live",
      "abilities": ["reports.export"],
      "last_used_at": "2026-07-15T22:04:00Z",
      "expires_at": null,
      "created_at": "2026-06-02T08:25:00Z"
    }
  ],
  "message": "OK", "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "9c1a4e2b-3f8d-4b7a-9e1c-2b9a4f8d1c3e",
  "timestamp": "2026-07-16T09:09:00Z"
}
```

`POST /api/v1/users/me/data-export`:
```json
{
  "success": true,
  "data": { "export_id": "exp_7a1c9e", "status": "queued", "requested_scope": ["all_companies"] },
  "message": "We're preparing your export. This usually takes a few minutes; we'll email you when it's ready.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "1a9c4e2b-3f7d-4b8a-9e2c-4a1b9f7d2c8e",
  "timestamp": "2026-07-16T09:11:00Z"
}
```

## Query keys

```ts
// lib/api/query-keys.ts (Profile additions)
export const profileKeys = {
  me: () => ["users", "me"] as const,
  preferences: () => ["users", "me", "preferences"] as const,
  sessions: () => ["auth", "sessions"] as const,
  mfaFactors: () => ["auth", "mfa", "factors"] as const,
  myTokens: () => ["auth", "api-keys", "mine"] as const,
  dataExport: (exportId: string) => ["users", "me", "data-export", exportId] as const,
};
```

`profileKeys.me()` is deliberately a **separate** cache entry from `SessionProvider`'s own
`GET /api/v1/auth/me` bootstrap query — the two overlap in content but not in shape or refresh
cadence (`/auth/me` also carries `active_company`, `companies`, and `session`, and is refetched on
every company switch per `FRONTEND_ARCHITECTURE.md`; `profileKeys.me()` never is, since the caller's
own name and avatar do not change when they switch companies). `app/(app)/profile/page.tsx` seeds
`profileKeys.me()`'s initial value from `SessionProvider`'s already-resolved `user` object on first
paint (no duplicate network call on navigation from elsewhere in the app) and only issues a real
`GET /api/v1/users/me` on a hard refresh or direct navigation.

## Cache tuning

| Resource | `staleTime` | Rationale |
|---|---|---|
| `profileKeys.me()` | 5 minutes | Changes only when the caller edits it themselves, in this same tab, in which case the mutation's own `onSuccess` writes the cache directly — a 5-minute ceiling is purely a safety net against a stale multi-tab edit, matching the "rarely-changing reference data" tier `FRONTEND_ARCHITECTURE.md`'s own cache-tuning table already defines |
| `profileKeys.preferences()` | 5 minutes, no `refetchOnWindowFocus` | Identical reasoning and identical ceiling to `NOTIFICATIONS.md`'s own Preferences cache tier — "user-driven, rarely changed by anything but the user's own action in this same tab" |
| `profileKeys.sessions()` | 0, manual refetch on section focus | A stale "This device" / last-active list is actively misleading on a security-sensitive screen; refetched every time the Security section mounts rather than trusted across a long-lived tab |
| `profileKeys.mfaFactors()` | 0, refetched after every enroll/remove mutation | Same reasoning as sessions — a security-state list should never show yesterday's factor set |
| `profileKeys.myTokens()` | 30 seconds | Matches the platform's general "transactional list" tier; a token this screen just revoked disappears from the list within a mutation's own optimistic update regardless |
| `companies` (read off `/auth/me`, no dedicated key) | Governed entirely by `SessionProvider`'s own bootstrap cache lifetime | This screen never independently fetches or caches it |

## Realtime

**None.** Profile subscribes to no Laravel Reverb channel, and this is a deliberate omission rather
than an oversight: every channel the platform documents (`FRONTEND_ARCHITECTURE.md → Realtime`) is
`private-company.{company_id}.<feature>`-scoped, "for exactly the surfaces where staleness is either
visible or costly," and nothing on this screen is company-scoped in the first place (`# Route &
Access`). A session revoked from another device, a password changed elsewhere, or a token created by
a script all take effect immediately at the API layer regardless of what this screen has cached
(`AUTHENTICATION_API.md → Revocation`'s per-request checks, not a push), and the one place staleness
would be user-visible — the Active Sessions list — is already refetched on every section mount per the
cache-tuning table above rather than kept live. Should a future "someone signed in as you from a new
device" security alert need to interrupt this screen in real time, it would arrive as an ordinary
`notifications` row over the already-existing `private-company.{id}.notifications.{user_id}` channel
(`AUTHENTICATION_API.md → Concurrent Session Policy`: "every new device triggers a `notifications`
alert" for high-trust roles) and be handled by `NOTIFICATIONS.md`'s bell, not by a second channel this
screen would need to own.

## AI agents feeding this screen

**None.** Every value this screen renders and every mutation it issues is a plain CRUD operation over
`users`, `user_preferences`, `sessions`, `mfa_factors`, and `personal_access_tokens` — none of it is an
agent's output, a confidence score, or a proposal awaiting approval. See `# AI Integration` for the
handful of adjacent touchpoints this screen deliberately excludes or defers to another screen rather
than inventing an AI presence where none is warranted.

# Interactions & Flows

**Editing Personal Info.** Every field saves via one `PATCH /api/v1/users/me` on the section's single
"Save changes" button — not per-field autosave, because unlike a preference toggle, a half-completed
name edit is not something the platform should commit keystroke-by-keystroke. Changing `email` never
touches `email` directly (`# Data & State`); submitting a new address calls
`POST /api/v1/users/me/email-change`, and the UI immediately shows the existing address with a
"Verification sent to {new address} — check your inbox" banner rather than optimistically swapping
the displayed email, since the change is not real until the link is followed.

**Changing the avatar.** Clicking "Change photo" opens a `Dialog` with a file picker and a simple
square-crop step, then `POST /api/v1/users/me/avatar`; on success the new `avatar_url` is written
directly into `profileKeys.me()`'s cache (no refetch needed) and propagates immediately to every other
place an avatar renders in the same session (the Topbar's `UserMenu`, the Rail Header) because all of
them read the same `SessionProvider`-seeded value. A failed upload never blanks the existing photo —
the dialog reports the error inline and the previous `avatar_url` stays exactly as it was.

**Switching language.** Changing Language calls `PATCH /api/v1/users/me` with the new `locale`, and —
unlike every Preferences-section control — this one **does** trigger a full reload of the current
route (`router.refresh()`, the same mechanism `FRONTEND_ARCHITECTURE.md`'s Company Switching flow
uses) rather than an instant client-side flip, because `locale` drives `dir`, the resolved font stack,
and every `Intl.NumberFormat`/`Intl.DateTimeFormat` call across the entire shell
(`THEMING.md → RTL as a theming dimension`) — far more than a single component's CSS variable. The
Section Rail's own labels, and this screen's own copy, switch language and direction in that same
reload; nothing about Profile is exempt from the platform's normal i18n contract.

**Theme, density, text size, reduced motion.** Every control in the Preferences section applies
instantly, client-side, and persists in the background with no visible loading state on the control
itself — the identical "instant apply, no confirmation, no Save button" rule `DARK_MODE.md → Toggle UX
→ Interaction rules` states for `ThemeToggle` specifically, extended here to its three siblings. A
network failure on the background `PATCH` toasts a quiet, non-blocking error and leaves the
client-applied value in place until the user's next visit reconciles it — a lost preference sync is
never worth interrupting someone who has already moved on to whatever they opened Preferences to fix.

**Changing password.** Submitting the Change Password form (current password, new password, confirm)
opens an `AlertDialog` stating plainly what is about to happen — **"You'll be signed out of every
device, including this one, and asked to sign in again with your new password"** — because
`AUTHENTICATION_API.md → Revocation → Reasons and Cascades` is explicit that a password change revokes
"every refresh-token family except none — all, including the one that just changed the password."
Confirming calls `POST /api/v1/auth/password/change`; on success the client does not attempt to keep
the current session alive (it cannot — the server has already revoked it) and redirects straight to
`/login` with a "Password changed. Please sign in again." message, rather than a jarring, unexplained
logout.

**Enrolling an MFA factor.** "Add method" opens a `Sheet`/`Dialog` offering Authenticator App, SMS, or
(for Owner/CFO-tier roles per `AUTHENTICATION_API.md → WebAuthn`) a hardware key, and drives the exact
`POST /mfa/totp/enroll` → scan QR → `POST /mfa/totp/verify` sequence (or its SMS/WebAuthn equivalents)
`AUTHENTICATION_API.md` already specifies verbatim, including the one-time `backup_codes` reveal
through `CopyableSecret` immediately after a first factor's verification succeeds. **Removing** a
factor opens a confirming `AlertDialog`; if it is the caller's last verified factor and their role
requires MFA, the "Remove" action is disabled with a `Tooltip` explaining why rather than allowed to
fail server-side after the fact (`ACCESSIBILITY.md`'s RBAC-aware-disabled-controls pattern, applied
here to an account-security guard rather than a permission).

**Managing sessions.** Every row except the caller's own current session renders a "Sign out" button
(`DELETE /api/v1/auth/sessions/{id}`, optimistic removal from the list on click); the current session's
row instead carries a static "This device" `Badge` and no revoke action at all — revoking your own
in-use session from a list is a confusing, easy-to-regret interaction pattern, so the platform's
existing "sign out of all other devices" bulk action (`DELETE /api/v1/auth/sessions`, no id) is this
screen's only path to a wide session revocation, and it always opens a confirming `AlertDialog` first
because it is irreversible from every other device's point of view mid-session.

**Switching or leaving a company.** "Switch" on a non-active membership row calls the identical
`POST /api/v1/auth/switch-company` mutation `CompanySwitcher` uses in the Topbar
(`FRONTEND_ARCHITECTURE.md → Company switching`), including its `queryClient.clear()` +
`router.refresh()` pair — Profile does not reimplement company switching, it offers a second entry
point into the same one. "Leave company" opens a confirming `AlertDialog` naming the company by name
and warning that re-joining requires a new invitation; a sole Owner sees the action disabled with a
`Tooltip` reading "Transfer ownership before leaving" rather than a server round trip that would only
fail.

**Managing personal API tokens.** "Create token" opens the identical name/environment/abilities form
`AUTHENTICATION_API.md → API Keys → Endpoints` specifies, and the resulting `plaintext_key` renders
once, through `CopyableSecret`, with the same "copy it now — it will not be shown again" framing the
API's own response `message` already states. Revoking a token opens a confirming `AlertDialog` (an
automation script depending on a revoked key fails immediately and irrecoverably, so this is treated
with the same weight as ending a session).

**Requesting a data export.** Clicking "Export my data" opens a plain-language summary of what will be
included (profile fields, preferences, session and MFA-factor metadata — never secrets — personal API
token metadata, notification preferences, and every AI personalization row `USER_MEMORY.md` holds for
the caller across every company they belong to) before calling `POST /api/v1/users/me/data-export`.
The UI then polls `GET /api/v1/users/me/data-export/{export_id}` (or simply tells the user to check
their email, since assembly can take several minutes for someone with a long membership history) and,
once `status: "ready"`, presents the signed R2 `download_url` with its own expiry countdown, mirroring
exactly how `AUTHENTICATION_API.md`'s own API-key/secret displays state an expiry rather than implying
permanence.

**Erasing AI memory vs. requesting account deletion — two clearly separated actions, never merged into
one button.** "Forget what AI has learned about me" calls `POST /api/v1/ai/memory/erase-all` per
company membership (looping automatically across every company in the caller's own `companies` list,
with a per-company progress readout for someone with several memberships), completes instantly with no
approval chain, and is explained in the UI exactly as `USER_MEMORY.md` frames it: this clears
*personalization*, never a financial fact. "Delete my account" is a visually and procedurally distinct
action requiring a fresh MFA step-up (`POST /api/v1/auth/mfa/verify` with `purpose: "step_up"`,
re-using the exact generic verification endpoint `AUTHENTICATION_API.md` already defines) before
`POST /api/v1/users/me/erasure-request` is even reachable, and its confirming `AlertDialog` states
plainly, in the platform's own established register (`DESIGN_LANGUAGE.md → Voice & Microcopy`'s "state
what happened, why, and what to do next"): your name, email, and phone will be anonymized once this is
reviewed; financial records you created will still show your historical name for as long as bookkeeping
law requires them to (`DATABASE_ARCHIVING.md → Right-to-erasure conflicts`); this cannot be undone once
processed. The request returns `pending_review`, never an immediate `deleted` status — this is a
request into a reviewed workflow, not a self-serve delete button, for the reasons `# Purpose` states.

# AI Integration

Profile is, by a wide margin, the smallest AI surface in the platform — smaller even than
`DASHBOARD.md`'s own deliberately minimal one, and closer to `NOTIFICATIONS.md`'s flat "None" for its
own screen-level AI computation. Nothing rendered here is an agent's output; nothing mutated here is a
proposal awaiting a human's approve/reject decision; no `ConfidenceBadge`, `AiCardShell`, or
`AIProposalPanel` appears anywhere on this screen, because there is no financial or operational
judgment for an agent to have made about a person's own name, theme preference, or password. Four
specific, narrow touchpoints are nonetheless worth naming precisely, because a natural question this
screen invites ("where's the AI in all this?") deserves a grounded answer rather than silence:

- **The AI engine never has a session of its own to show up in Active Sessions.** The FastAPI AI layer
  authenticates to the API with its own service JWT under a synthetic `ai_service` role
  (`AUTHENTICATION_API.md → AI Engine Service Tokens`), not a human `sessions`/`mfa_factors` row — so a
  user will never see an "AI Agent" device in their own session list, and this screen renders none,
  by construction rather than by filtering one out.
- **The AI never appears as a company membership, either.** `company_users` rows are exclusively human
  memberships; the AI's permission scope is granted through the same synthetic role, never through a
  seat in the Company Memberships list this screen renders.
- **"AI Alerts" as a notification category is configured at `/notifications/preferences`, not here.**
  The Notifications rail item deep-links there rather than exposing a second, competing channel matrix
  (`# Purpose`, `# Layout & Regions`) — this screen's job stops at getting the user to that control, not
  duplicating it.
- **The one place AI genuinely intersects Profile is Data & Privacy**, because `USER_MEMORY.md`'s
  entire personalization graph — how a person likes the Copilot to phrase things, which report they
  open first, their personal comfort threshold inside an approval chain — is personal data about an
  identifiable individual by that document's own explicit framing, and therefore belongs in both this
  screen's export bundle and its own dedicated, self-service erasure action (`# Interactions & Flows`),
  distinct from and never merged into the heavier, reviewed account-deletion request.

No screen in this documentation set is required to manufacture an AI presence it does not have; this
section exists to state that absence as a deliberate, cross-referenced design decision rather than an
oversight a reviewer might otherwise flag.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Personal Info form | Field-shaped `Skeleton`s (label height + input height) rather than a generic spinner, matching the eventual form's exact layout | N/A — every field always has a value (at minimum, the defaults `users` writes at signup) | Inline `ErrorState` in place of the form with a retry; a failed **save** (as opposed to a failed initial load) leaves every typed value exactly as entered and surfaces the server's `422` per field via `form.setError`, never a full-form reset |
| Preferences controls | Skeleton pills matching each `ToggleGroup`'s final width | N/A — `user_preferences` always has a full row (defaulted server-side at first read, per `THEMING.md`'s own `DEFAULT` columns) | A failed background `PATCH` toasts and reverts only the one control that failed to persist, never the whole section |
| Security → Sessions | 3 skeleton rows | Never truly empty (the caller's own current session always exists) | Inline retry card; the Password and MFA cards above it remain fully usable regardless |
| Security → MFA factors | 2–3 skeleton rows | A genuinely unenrolled account (MFA-optional role, never enrolled) shows "No two-factor methods yet" with the "Add method" action prominent, not hidden behind an empty-state illustration per `DESIGN_LANGUAGE.md`'s no-illustration stance | Inline retry; enrollment actions stay reachable even if the factor list itself failed to load |
| Company Memberships | 2 skeleton rows | Never empty for an authenticated session (a user always belongs to at least the company they are currently signed into) | `EmptyState`-styled retry card; "Switch company" elsewhere in the shell still works from cached `/auth/me` data even if this screen's own re-render fails |
| Personal API Tokens | 2 skeleton rows | "You haven't created a personal API token yet" with "Create token" as the primary action, plus a one-line explainer of what a token is for (per `DESIGN_LANGUAGE.md`'s plain, non-hyped empty-state copy convention) | Inline retry; creation remains available even if the list failed to load |
| Data & Privacy → export status | An indeterminate progress affordance while `status: "queued"` or `"processing"` | N/A | If assembly fails server-side, the poll surfaces `status: "failed"` with a "Try again" action, never a silently-stuck spinner |

# Responsive Behavior

| Tier (`RESPONSIVE_DESIGN.md`) | Min-width | Behavior |
|---|---|---|
| Mobile | 0px | Section Rail collapses to a horizontally-scrolling `Tabs` strip pinned under the Rail Header (avatar, name, active-company role inline above it); every `Card`-grouped block in Security stacks full-width; dialogs (password change confirm, MFA removal confirm, account-erasure confirm) render as full-height `Sheet`s rather than centered `Dialog`s, per `RESPONSIVE_DESIGN.md → Pattern 4` |
| Tablet (`md:`) | 768px | Section Rail remains a horizontal strip but no longer needs to scroll for the common case (six items fit); two-column form grids become viable for Personal Info's shorter fields (phone + timezone side by side) |
| Laptop (`lg:`) | 1024px | Section Rail becomes the persistent vertical column described in `# Layout & Regions`; Main Column and Rail render side by side rather than stacked |
| Desktop (`xl:`/`2xl:`) | 1280px/1536px | No structural change — this screen has no data grid dense enough to want the extra columns those tiers exist for elsewhere; content stays capped at a comfortable reading width rather than stretching to fill a 1536px+ viewport, matching `RESPONSIVE_DESIGN.md`'s stated `max-w-[1440px]` centering rule |
| Ultra Wide (`3xl:`) | 1920px | Unchanged from Desktop — Profile is precisely the kind of screen `RESPONSIVE_DESIGN.md`'s own tier table anticipates needing nothing new at this width: no dashboard grid, no ultra-wide finance table, just a form and a few short lists |

Every button, `Switch`, and `ToggleGroupItem` on this screen holds the platform's 44×44px minimum
touch target with an 8px gap (`RESPONSIVE_DESIGN.md → Touch Targets & Gestures`), which matters here
specifically because Security's "Sign out" and "Remove" actions are exactly the kind of control a
mis-tap on has real consequences for. No swipe gesture is offered on this screen's rows (unlike
`NOTIFICATIONS.md`'s swipe-to-mark-read) — a session or MFA factor row is rare enough, and consequential
enough, that an explicit tap on a labeled button is the only interaction offered, never a gesture
shortcut that could be triggered by an accidental scroll.

# RTL & Localization

Every layout rule this screen renders under inherits `DESIGN_LANGUAGE.md`/`LAYOUT_SYSTEM.md`'s RTL
contract with zero screen-specific exceptions: logical properties throughout (`ms-*`/`me-*`/
`text-start`/`text-end`), the Section Rail's active-item indicator and its `Tabs`-based mobile
fallback mirroring automatically because both are built on the same primitive every other module-level
sub-navigation already uses. Two points are specific enough to this screen to state precisely:

- **Phone numbers and every timestamp/relative-time string render `dir="ltr"`**, via the same pinning
  `AmountCell`/`FormattedRelativeTime` already apply elsewhere — a Kuwaiti phone number
  (`+965 5011 2233`) reads left-to-right inside a fully Arabic Personal Info form, exactly as an amount
  does inside a fully Arabic invoice.
- **A company's `name_ar` renders in the Company Memberships list when the caller's own `locale` is
  Arabic, independent of what language that company's own books are kept in** — `companies.name_en`/
  `name_ar` are display fields the *viewer's* locale picks between, unlike a notification's frozen,
  already-rendered `title`/`body` (`NOTIFICATIONS.md → RTL & Localization`); switching Language on this
  very screen changes which of the two a membership row shows, live, without a page reload for that
  one field (the surrounding full-route reload only fires for `dir`/font/formatting reasons, `#
  Interactions & Flows` — the membership list's own re-render is a side effect of that same reload,
  not a separate mechanism).

| Context | English | Arabic |
|---|---|---|
| Section: Personal Info | Personal Info | المعلومات الشخصية |
| Section: Preferences | Preferences | التفضيلات |
| Section: Security | Security | الأمان |
| Section: Companies | Companies | الشركات |
| Section: API Tokens | API Tokens | رموز الوصول |
| Section: Data & Privacy | Data & Privacy | البيانات والخصوصية |
| Password-change warning | You'll be signed out of every device, including this one. | سيتم تسجيل خروجك من جميع الأجهزة، بما فيها هذا الجهاز. |
| Last-MFA-factor tooltip | Add another method before removing this one. | أضف طريقة أخرى قبل إزالة هذه الطريقة. |
| Sole-owner tooltip | Transfer ownership before leaving. | انقل الملكية قبل مغادرة الشركة. |
| Export request success | We're preparing your export. We'll email you when it's ready. | نجهز ملف بياناتك. سنرسل لك بريدًا إلكترونيًا عند جاهزيته. |
| Account-deletion confirm | This cannot be undone once processed. | لا يمكن التراجع عن هذا بعد معالجته. |

# Dark Mode

No new token, elevation step, radius, or motion curve is introduced for this screen. Every `Card`,
`Badge`, `ToggleGroup`, and `AlertDialog` resolves through the existing semantic-token layer exactly as
`DARK_MODE.md → Token Mapping` already specifies, which is what lets this screen — like
`DARK_MODE.md`'s own worked "Appearance settings screen" example — require zero `dark:`-prefixed
classes of its own. `CopyableSecret`'s monospace secret field uses the platform's existing `code`-role
surface treatment (a subtly recessed `ink-3`/dark-`ink-3`-equivalent background per
`DESIGN_LANGUAGE.md → Elevation & Surfaces`) rather than a bespoke "secret reveal" color in either
theme. The Section Rail's active-item state uses the same `accent`-on-`ink-2` treatment `Tabs`'
existing indicator already resolves through, re-tuned per theme exactly as every other `accent`-bearing
control in the platform already is — no separate dark-mode calibration is needed because none of this
screen's states are AI-provenance or finance-status colors, the two categories `DARK_MODE.md` singles
out for non-linear, per-theme re-tuning.

# Accessibility

This screen targets the same WCAG 2.1/2.2 AA floor as every other QAYD surface
(`ACCESSIBILITY.md → Standards & Targets`), with particular weight on forms and on the several
irreversible confirmations this screen uniquely concentrates in one place.

- **Landmarks and headings.** Each section renders inside the shared `<main>` region with one visible
  `<h1>` matching the active Section Rail item; the Rail itself is a `<nav aria-label="Profile
  sections">` with the current route's item carrying `aria-current="page"`, mirroring the Sidebar's own
  established landmark discipline (`NAVIGATION_SYSTEM.md`).
- **Forms, wired exactly per `ACCESSIBILITY.md → Forms Accessibility`.** Every field on Personal Info
  and the Change Password form uses a real `<Label>` (never placeholder-as-label), `FormControl`'s
  automatic `aria-invalid`/`aria-describedby` wiring, and a Zod schema whose messages are translation
  keys resolved through `t()` — a validation failure on the Arabic locale is fully Arabic and
  RTL-aligned, never an English string dropped into an otherwise-Arabic form. A failed `PATCH` maps its
  `422 errors[]` back onto the same RHF fields via `form.setError`, identical to the pattern
  `ACCESSIBILITY.md` already specifies for the Journal Entry form.
- **New-password fields never block paste, and one-time MFA codes use `autoComplete="one-time-code"`.**
  Both are restated directly from `ACCESSIBILITY.md → Required fields, redundant entry, and accessible
  authentication` (WCAG 2.2 SC 3.3.8) — blocking paste breaks password managers, and an OS-autofilled
  SMS/authenticator code is strictly better than requiring manual transcription, especially for a screen
  whose entire purpose is account security.
- **Every irreversible action is a real, focus-managed `AlertDialog`, never a bare confirm() or an
  inline toggle.** Password change, MFA-factor removal, leaving a company, revoking a token, and the
  account-erasure request all trap focus on open, return it to the triggering control on close or
  cancel, and require an explicit, labeled confirm button rather than an "Enter to confirm" default a
  keyboard user could trigger by accident (`ACCESSIBILITY.md → Focus Management → Dialogs, alert
  dialogs, and sheets`).
- **The data-export status is an `aria-live="polite"` region, never assertive.** A background job
  finishing while a screen-reader user is reading something else on the same page announces politely,
  matching the platform's explicit "auto-detect, never alarming" three-tier live-region model
  (`ACCESSIBILITY.md`), reused verbatim from `NOTIFICATIONS.md`'s identical treatment of its own
  realtime arrivals.
- **Color is never the only channel.** The active company's marker in the Companies list is a filled
  dot **plus** an "Active" `Badge` with real text, never a dot's color alone; an unverified email shows
  a literal "Not verified — resend" link, not merely a muted color on the address.
- **Keyboard path.** Tab order flows Rail Header → Section Rail (one stop per item, `Arrow` keys moving
  within it exactly as `Tabs`' native roving-tabindex behavior already provides) → the active section's
  form fields in visual order → its primary action. `G` then `P` reaches the screen from anywhere;
  once inside, no additional chord is introduced — every section is a plain, bookmarked route a screen-
  reader or keyboard user reaches the same way a sighted mouse user does.

# Performance

- **Route-segmented code splitting is free, not an optimization this screen adds on top.** Because
  each section is its own nested route (`# Route & Access`) rather than a client-side tab switch inside
  one `page.tsx`, Next.js already code-splits Security's MFA/WebAuthn-adjacent bundle away from
  Preferences' `ThemeToggle`/`ToggleGroup` bundle and away from Data & Privacy's export-polling logic —
  a user who only ever opens Personal Info never downloads the other five sections' JavaScript.
- **Parallel, not waterfalled, first paint.** `app/(app)/profile/layout.tsx` prefetches
  `profileKeys.me()` (seeded from `SessionProvider` where possible, `# Data & State`) and the active
  leaf route's own query concurrently via `Promise.all`, rather than resolving the Rail Header's data
  before starting the Main Column's — a Server Component that awaited them sequentially would add a
  second round trip's worth of latency to every navigation for no correctness benefit.
- **Avatar images are never unoptimized.** `AvatarWithFallback` renders through `next/image` with an
  explicit `sizes` matching its actual rendered dimensions at each tier (`RESPONSIVE_DESIGN.md → Icons
  and avatars`), so a desktop-resolution upload is never downscaled client-side on a phone.
- **No search, no virtualization, no pagination machinery on this screen.** Every list here — sessions,
  MFA factors, company memberships, personal tokens — is realistically single-digit-to-low-double-digit
  rows for any human account; none of them use `DataTable`, cursor pagination, or `TanStack Virtual`,
  because introducing that machinery for a five-row list would be complexity spent on a problem this
  screen does not have.
- **Web Vitals.** LCP is measured against the active section's primary content (the Personal Info form,
  the Preferences controls) rendering, not the Rail shell; CLS stays near zero because every `Skeleton`
  in `# States` is sized to its final content's exact height rather than a generic placeholder block.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | The active company is switched (from the Topbar, or from this screen's own Companies section) while any other Profile section is open. | Nothing on this screen refetches or resets — `users`, `user_preferences`, `sessions`, `mfa_factors`, and `personal_access_tokens` are all global, not company-scoped (`# Route & Access`), unlike every accounting screen. Only the Companies list's own "Active" marker updates, because it reads the same `SessionProvider` state the switch itself already updated. |
| 2 | A password change succeeds; another browser tab has Profile (or any other screen) open at the moment the current session is revoked. | The other tab's next request returns `401`, handled by the platform's existing global auth-redirect (`FRONTEND_ARCHITECTURE.md`'s middleware/refresh contract) exactly as it would for any expired session — Profile introduces no special-case handling for its own password-change side effect. |
| 3 | A user with mandatory-MFA-for-their-role attempts to remove their only verified factor. | The "Remove" action is disabled with an explanatory `Tooltip` before the click ever happens; if reached anyway (a stale UI state), the server's `422 LAST_MFA_FACTOR_REQUIRED` is surfaced as a toast, never a silent failure. |
| 4 | An admin holding `auth.session.manage_others` (from `/settings/team`, out of scope here) revokes a user's current session while that user is looking at their own Profile screen. | Identical to #2 — the next request 401s and the platform's existing redirect fires; this screen never polls its own session list frequently enough to notice this proactively, matching `# Data & State → Realtime`'s stated reasoning for why no channel is subscribed here. |
| 5 | An avatar upload succeeds at the API but the CDN/webp conversion has not finished propagating yet. | The UI optimistically shows the just-uploaded local file preview until the server's `avatar_url` is confirmed reachable; if the returned URL 404s on first load elsewhere in the shell within the next few seconds, `AvatarWithFallback` shows initials rather than a broken-image icon, and quietly retries once. |
| 6 | A user clicks "Export my data" twice within a short window (a slow first response, an impatient second click). | `POST /api/v1/users/me/data-export` is idempotent per caller within a rolling window server-side — a second request while one is already `queued`/`processing` returns the same `export_id` rather than queuing a duplicate assembly job, mirroring the platform's general idempotency posture (`API_ARCHITECTURE.md`'s `Idempotency-Key` contract, applied here via a natural per-user dedupe key rather than a client-supplied header, since this call has no request body to vary). |
| 7 | A user leaves every company they belong to, one at a time, ending with zero `company_users` rows. | This is allowed — `users` is a valid, authenticatable identity independent of any company membership (`ERD.md → users`: "one person may hold accounts... across multiple companies," which permits the zero case at the boundary) — but the confirming dialog on the *last* remaining membership states plainly, "You won't be able to use QAYD until you're invited to a new company," so the consequence is understood before it happens, not discovered afterward at a confusing blank company-picker screen. |
| 8 | A user with only one company membership attempts to leave it. | The "switch first" framing used when other memberships exist would be actively unhelpful here (there is nothing to switch to); the confirming dialog instead shows only the Edge Case #7 warning, and the `409 CANNOT_LEAVE_ACTIVE_COMPANY` guard from `# Data & State` does not apply in the single-membership case specifically because there is no "switch away first" for the user to perform — leaving is permitted directly from the active company when it is the only one, provided the sole-Owner guard (`# Route & Access`) also does not block it. |
| 9 | Two tabs are open: one mid-TOTP-enrollment (QR code displayed, not yet verified), the other toggling Preferences controls. | No conflict — `profileKeys.mfaFactors()` and `profileKeys.preferences()` are independent cache entries touching unrelated tables; an abandoned, unverified enrollment simply leaves an `mfa_factors` row with `verified_at IS NULL` that never becomes an active factor and is safely superseded the next time enrollment is attempted. |
| 10 | The user's `locale` is changed to Arabic while a confirmation `AlertDialog` (password change, account erasure) is open. | The route-level reload this triggers (`# Interactions & Flows`) necessarily closes any open dialog — this is treated as acceptable and expected, since a locale change is rare enough, and consequential enough to layout, that resuming an in-progress confirmation in the new language is safer than trying to hot-swap direction under an open modal. |
| 11 | An account-erasure request is submitted and is now `pending_review`. | The account remains fully functional in every respect until the request is actually processed — `pending_review` is a queued administrative workflow state, never an implicit suspension, so the user can keep working, change their mind, and (per `# Interactions & Flows`) is never left wondering whether they still have access while a human review is pending. |
| 12 | Backup codes are regenerated in one tab while the previous set is still displayed, unused, in another. | The previously displayed codes become inert the instant regeneration succeeds (`AUTHENTICATION_API.md → SMS OTP and Backup Codes`: "invalidates every previously issued code immediately... replaced wholesale"); this screen adds no special detection for the stale display — the next attempted use of an old code simply fails with the platform's ordinary invalid-code error. |
| 13 | A Read Only or External Auditor role — the narrowest roles in the platform — opens Profile. | The screen renders fully and normally, with every section and every mutation available, because none of it is gated by a company-scoped permission (`# Route & Access`) — the same "renders fully... because neither route carries a gating permission" precedent `NOTIFICATIONS.md` states for its own screen extends here without modification. |
| 14 | An Owner promotes the caller to a different role at a company, or renames that company, while the caller's Companies section is open in a second, idle tab. | The idle tab's membership row shows the stale role/name until its own next `/auth/me` refetch (on its next navigation, or the shared session bootstrap's own cache expiry) — the same accepted, bounded cross-tab lag `NOTIFICATIONS.md → Edge Cases` #11 already documents for a different screen's own realtime-adjacent staleness. |

# End of Document
