# Integrations — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: INTEGRATIONS
---

# Purpose

This document specifies the Integrations screen: the marketplace, connection-management, and
developer-surface hub where a company configures every wire QAYD runs between itself and the outside
world. It is the frontend counterpart to `docs/api/PARTNER_API.md` (which owns `partner_providers`,
`partner_connections`, certification, sandbox, and monitoring for everything QAYD calls *out* to — a
bank, a payment gateway, a government e-filing authority, an ERP/POS/CRM) and to `docs/api/PUBLIC_API.md`
(which owns API keys, OAuth applications, and the Developer Portal for everything an external caller
uses to call QAYD *in*), and it conforms to the cross-cutting rules in `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `LAYOUT_SYSTEM.md`, `NAVIGATION_SYSTEM.md`, `SETTINGS.md`,
and `ICONOGRAPHY.md`. Where this document is silent, those documents govern; where this document appears
to contradict one of them, that is a defect to raise, not a decision to resolve unilaterally in code.

`SETTINGS.md → Route & Access` already fixes an "Integrations" row in the Settings Section Nav, gated on
`integrations.read`, pointing at two flat files — `api-keys/page.tsx` and `webhooks/page.tsx` — described
there as "two files, one tab, inner `Tabs`." That treatment covers exactly one of the two directions this
screen must serve (QAYD's own API surface, exposed outward to a company's own external consumers) and
says nothing about the direction the task at hand is actually named for: browsing a catalog of banks,
payment gateways, government e-filing authorities, and ERP/POS/CRM systems, connecting to one, and
managing the connections that result. Rather than bolt a second, incompatible meaning onto
`SETTINGS.md`'s existing two files, this document does what `SETTINGS.md`'s own opening paragraph
pre-authorizes for exactly this situation — it "extends the fixed tree with … more the platform has
needed all along but never named a route for." Concretely: this document introduces
`app/(app)/settings/integrations/` as a new, richer landing segment for the same Section Nav row, with
its own inner tab strip of five destinations — **Marketplace**, **Connected**, **API Keys**, **Webhooks**,
and **Sandbox** — where the first, second, and fifth are new pages this document owns outright, and the
third and fourth are the exact, unchanged `/settings/api-keys` and `/settings/webhooks` files
`SETTINGS.md` already fixed, reached from here by a real `<Link>` carrying a trailing `ArrowUpRight`
glyph — precisely the "Notifications ↗" pattern `SETTINGS.md` itself uses for a tab whose content lives
at an already-fixed URL outside the current shell. Nothing here relocates, renames, or reimplements
`api-keys/page.tsx` or `webhooks/page.tsx`; this document only adds the two pages that were missing (a
marketplace to connect *from*, a management surface for what is already connected) and a sandbox that
serves both directions at once.

The screen answers five distinct questions a Finance Manager, an Owner, or a company's own integration
engineer needs answered from one place, never several:

- **What can I connect QAYD to?** — the **Marketplace**, a permission-gated, filterable catalog of every
  `partner_providers` row across six categories: Banks & Open Banking, Payment Gateways, Government &
  Tax E-Filing, ERP/POS/CRM, Email, and Storage (the last two are this document's own, explicitly-flagged
  additions to `PARTNER_API.md`'s `partner_category` enum — see `# Data & State`).
- **How do I connect to one, concretely?** — **Connect/configure flows**: an OAuth2 authorization-code
  redirect for a bank, aggregator, or an OAuth-native ERP/CRM; an API-key/secret-pair form for a payment
  gateway or a simpler ERP/POS; a certificate-upload form for a government e-filing authority requiring
  mTLS or a signed cryptographic stamp — three flows, one underlying `partner_connections` row, chosen by
  the selected provider's own `partner_auth_type`.
- **What is already connected, and is it healthy?** — the **Connected** tab: every `partner_connections`
  row the company holds, its status, its rolling health telemetry, its certification level, and — for the
  category that needs it — its field-mapping configuration, disconnect, and credential-rotation actions.
- **How do I let something call QAYD, or have QAYD call something I built?** — the **API Keys** and
  **Webhooks** tabs, unchanged destinations owned by `SETTINGS.md`/`AUTHENTICATION_API.md`/
  `API_WEBHOOKS.md`, concretized by `PUBLIC_API.md`'s key format, scope catalog, and Developer Portal.
- **How do I try any of this without touching real data or real money?** — the **Sandbox** tab: a reset
  action, a webhook-delivery simulator, and the certification status that gates a promotion from sandbox
  to production, serving both the "connect-out" and the "expose-out" directions from one shared panel.

Like every screen in QAYD, this one **owns no business logic**. It never decides whether a scope may be
elevated, never mints a bearer token, never verifies a webhook signature, and never lets an AI agent
approve its own gateway charge. Every number, status, and health figure rendered here was computed and
validated by Laravel; every mutation this screen triggers is a call to `/api/v1/partners/...` or
`/api/v1/auth/api-keys` / `/api/v1/webhooks/...`, guarded by the exact permission key the API itself
enforces (`FRONTEND_ARCHITECTURE.md`, Principle 1 and Principle 4).

# Route & Access

## File tree

```
app/(app)/settings/
├── layout.tsx                          # fixed (SETTINGS.md) — Section Nav shell
├── integrations/                       # NEW — this document
│   ├── layout.tsx                      # Inner tab strip: Marketplace | Connected | API Keys ↗ | Webhooks ↗ | Sandbox
│   ├── page.tsx                        # Redirects to ./marketplace (no standalone content of its own)
│   ├── marketplace/
│   │   ├── page.tsx                    # Catalog grid, grouped by category, filterable, searchable
│   │   └── [providerCode]/page.tsx     # Provider detail + Connect wizard entry (Sheet-first, deep-linkable)
│   ├── connected/
│   │   ├── page.tsx                    # DataTable of the company's own partner_connections
│   │   └── [connectionId]/page.tsx     # Connection detail: health, scopes, certification, mappings, logs
│   └── sandbox/
│       └── page.tsx                    # Reset, webhook simulator, certification status, Developer Portal links
├── api-keys/page.tsx                   # fixed (SETTINGS.md) — reached from Integrations' inner tab strip, not owned here
└── webhooks/page.tsx                   # fixed (SETTINGS.md) — reached from Integrations' inner tab strip, not owned here
```

`integrations/page.tsx` performs a server-side redirect to `integrations/marketplace` rather than
rendering its own content, mirroring the platform's existing convention for a tab-group index that has no
meaning of its own (`accounting/page.tsx` redirects to `accounting/accounts` the same way, per
`FRONTEND_ARCHITECTURE.md`'s route tree). `[providerCode]` and `[connectionId]` are both real, bookmarkable,
back-button-safe routes rather than client-only dialog state — a provider's detail page and a connection's
health/log detail are both substantial enough content (a certification history, a field-mapping table, a
90-day health chart) to deserve a URL, exactly the reasoning `BANKING.md` gives for its own
`[accountId]/page.tsx`. On viewports below `lg`, `[providerCode]` and `[connectionId]` still render as a
full page rather than a `Sheet`-over-list, per `LAYOUT_SYSTEM.md → Responsive Layout Rules`'s Detail Page
row ("single column; Summary Rail moves below Main Column"); at `lg`+, opening either from its parent list
also opens the identical route inside a `Sheet` via the same interception pattern
`FRONTEND_ARCHITECTURE.md → Parallel and intercepting routes` documents for quick-create, so a click from
the grid or the table feels instant while a direct link or a refresh still lands on the full page.

## Tab strip → route → permission

| Tab (inner strip label) | Route | View permission | Manage permission | Notes |
|---|---|---|---|---|
| Marketplace | `/settings/integrations/marketplace` | `integrations.read` | `integrations.connect` (per-provider "Connect" button) | New in this document |
| Connected | `/settings/integrations/connected` | `integrations.read` | `integrations.manage`; finer-grained actions below | New in this document |
| API Keys ↗ | external → `/settings/api-keys` | `auth.apikey.read` | `auth.apikey.create` / `.revoke` | Not a file this document owns — see `SETTINGS.md` |
| Webhooks ↗ | external → `/settings/webhooks` | `webhooks.read` | `webhooks.create` / `.update` / `.delete` / `.manage` | Not a file this document owns — see `API_WEBHOOKS.md` |
| Sandbox | `/settings/integrations/sandbox` | `integrations.read` | `integrations.sandbox.reset`, `integrations.webhook.simulate` | New in this document |

Every permission key above reuses `PARTNER_API.md → Permissions`, `AUTHENTICATION_API.md`'s literal
API-key spelling, and `API_WEBHOOKS.md`'s literal webhook spelling verbatim — this document introduces no
new permission key for the Marketplace/Connected/Sandbox tabs beyond what `PARTNER_API.md` already names,
and it deliberately does **not** adopt `SETTINGS.md`'s own shorthand `integrations.webhook.manage` for the
Webhooks half of its Integrations row: `API_WEBHOOKS.md` is the document explicitly scoped to that
resource's lifecycle, and — per the exact reconciliation discipline `SETTINGS.md` itself applies to the
`auth.apikey.*`/`auth.api_keys.manage` drift — the literal spelling of the owning document wins for any
call this document documents, while `SETTINGS.md`'s own prose is left undisturbed for the Section Nav
row it already describes.

Finer-grained actions on the Connected tab, all from `PARTNER_API.md → Permissions` verbatim:

| Action | Permission | Notes |
|---|---|---|
| View a connection's detail, health, scopes | `integrations.read` | |
| Edit label/scopes/environment; approve a pending scope elevation | `integrations.manage` | |
| Disconnect (soft-delete) a connection | `integrations.disconnect` | |
| Rotate a connection's secret/certificate | `integrations.credentials.rotate` | |
| Submit a connection for certification | `integrations.certify.submit` | |
| Approve a Certified Financial Partner-level certification | `integrations.certify.approve` | Human-approval-chain action — see `# AI Integration` |
| Create/edit ERP/POS/CRM field mappings | `integrations.mapping.manage` | |
| Reset sandbox fixtures | `integrations.sandbox.reset` | Sandbox tab only |
| Fire a simulated inbound webhook | `integrations.webhook.simulate` | Sandbox tab only |

No control on this screen is ever silently omitted the way a plan-tier upsell might be. A tab the caller
cannot view at all is grayed in the outer Settings Section Nav (per `SETTINGS.md`'s own rule); a specific
action inside a tab the caller **can** view but not perform renders through `<Can permission="…"
fallback={<DisabledWithTooltip .../>}>` exactly as `COMPONENT_LIBRARY.md`'s `Can` does everywhere else —
except for `integrations.certify.approve` and `integrations.disconnect`, which are additionally
structurally withheld from the **AI Agent** principal type regardless of any permission grant an
administrator might mistakenly assign, matching `PARTNER_API.md → Permissions`'s own defense-in-depth rule
that a sensitive capability is gated on the authenticated principal's *type*, not solely its permission
set.

## Role grants

Collapsed into the same six representative tiers `SETTINGS.md` and `TRIAL_BALANCE.md` already use for
table width; the underlying grant is always checked per literal `roles.code`, never per this collapsed
label:

| Tab / action | Owner/CEO/CFO | Finance Manager | Sr. Accountant/Accountant | Sales/Purchasing/Inventory Mgr | Operational Employee tier | Auditor/Ext. Auditor/Read Only |
|---|---|---|---|---|---|---|
| Marketplace — view | Yes | Yes | Yes | Yes | No | Yes |
| Marketplace — Connect | Yes | Yes | No | No | No | No |
| Connected — view | Yes | Yes | Yes | Yes (own domain's category only, e.g. Inventory Mgr sees ERP/POS) | No | Yes |
| Connected — manage/rotate | Yes | Yes | No | No | No | No |
| Connected — disconnect | Yes | Yes | No | No | No | No |
| Certification submit | Yes | Yes | No | No | No | No |
| Certification approve (Certified Financial Partner) | Yes (Owner/CFO only, per `PARTNER_API.md`) | No | No | No | No | No |
| Field mapping manage | Yes | Yes | No | Yes (own domain) | No | Read-only |
| API Keys / Webhooks | Yes | Yes | No | No | No | Read-only |
| Sandbox reset / simulate | Yes | Yes | No | No | No | No |

`Finance Manager`'s "Connect" and "manage/rotate" access matches `PARTNER_API.md → Permissions`'s own
role table exactly (Finance Manager holds `integrations.connect`/`.manage`/`.credentials.rotate` but not
`.disconnect` or `.certify.approve`) — this frontend table is a rendering of that permission grant, never
an independent policy decision layered on top of it.

# Layout & Regions

## Inner tab strip inside the Settings shell

`SETTINGS.md → Layout & Regions` already establishes the outer frame this screen renders inside: a
grouped, vertical **Section Nav** rail (`3/12` at `lg`+) beside a `9/12` content region. This document's
contribution is everything inside that `9/12` content region once "Integrations" is the active Section
Nav item — a second, shallow, five-item horizontal `Tabs` row (Marketplace · Connected · API Keys ↗ ·
Webhooks ↗ · Sandbox) directly under the Page Header, exactly the same "second level, but a shallow,
two-item one" pattern `SETTINGS.md` already uses for Users/Roles and Branches/Departments — extended here
to five items because Integrations is a genuinely wider surface than a two-file tab pair, not because this
document is inventing a new nav idiom:

```
┌──────────────┬──────────────────────────────────────────────────────────────┐
│ Settings      │  Integrations                                                │  ← Page Header
├──────────────┤  Connect QAYD to your bank, payment gateway, tax authority,   │
│ COMPANY       │  and business systems — or let your own systems call QAYD.   │
│   General     ├──────────────────────────────────────────────────────────────┤
│   Accounting  │  [Marketplace] [Connected] [API Keys ↗] [Webhooks ↗] [Sandbox]│ ← inner Tabs
│   Tax         ├──────────────────────────────────────────────────────────────┤
│   Numbering   │                                                                │
├──────────────┤                    (active tab's content)                     │
│ ACCESS        │                                                                │
│   Users&Roles │                                                                │
│   Branches    │                                                                │
│   Security    │                                                                │
├──────────────┤                                                                │
│ AUTOMATION    │                                                                │
│   Approvals   │                                                                │
│   AI Prefs    │                                                                │
├──────────────┤                                                                │
│ PLATFORM      │                                                                │
│ ▸ Integrations│                                                                │
│   Notif. ↗    │                                                                │
│   Billing     │                                                                │
└──────────────┴──────────────────────────────────────────────────────────────┘
```

`API Keys ↗` and `Webhooks ↗` carry the identical trailing `ArrowUpRight` glyph the outer Section Nav's
own "Notifications ↗" row uses, signaling the same fact at a shallower level: clicking either navigates
fully away from `/settings/integrations/*` to the fixed `/settings/api-keys` or `/settings/webhooks` route,
not into a same-shell inner tab panel — a real `<Link>`, never client tab state, so a user who bookmarks
`/settings/api-keys` directly still lands exactly where this tab strip would have sent them.

## Marketplace tab

**Purpose:** browse and filter the `partner_providers` catalog, initiate a connection. Structurally a
**List Page Template** variant (`LAYOUT_SYSTEM.md`) with cards instead of table rows, because a provider
catalog is scanned visually by logo and category, not by sortable columns:

```
┌────────────────────────────────────────────────────────────────────────────┐
│ [Search providers…]           [All categories ▾]        [Connected only ⬜]│
├────────────────────────────────────────────────────────────────────────────┤
│ Banks & Open Banking                                                        │
│ ┌────────────┐ ┌────────────┐ ┌────────────┐                               │
│ │ [NBK logo] │ │ [KFH logo] │ │ SAMA Open  │                               │
│ │ NBK Open   │ │ KFH Open   │ │ Banking    │                               │
│ │ Banking    │ │ Banking    │ │ (KSA)      │                               │
│ │ ●Connected │ │ [Connect]  │ │ [Connect]  │                               │
│ └────────────┘ └────────────┘ └────────────┘                               │
│ Payment Gateways                                                            │
│ ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐                │
│ │ Tap        │ │ PayTabs    │ │ MyFatoorah │ │ KNET        │                │
│ │ Payments   │ │            │ │            │ │             │                │
│ │ ●Connected │ │ [Connect]  │ │ [Connect]  │ │ [Connect]   │                │
│ └────────────┘ └────────────┘ └────────────┘ └────────────┘                │
│ Government & Tax E-Filing                                                    │
│ ┌────────────┐ ┌────────────┐                                              │
│ │ ZATCA      │ │ FTA (UAE)  │                                              │
│ │ (Fatoora)  │ │            │                                              │
│ │ [Connect]  │ │ [Connect]  │                                              │
│ └────────────┘ └────────────┘                                              │
│ ERP · POS · CRM        Email        Storage                                 │
│ ┌──────────┐┌──────────┐  ┌──────────┐  ┌──────────┐┌──────────┐           │
│ │ Foodics  ││Salesforce│  │ Resend   │  │Google    ││Microsoft │           │
│ │ [Connect]││[Connect] │  │[Connect] │  │Drive     ││OneDrive  │           │
│ └──────────┘└──────────┘  └──────────┘  │[Connect] ││[Connect] │           │
│                                          └──────────┘└──────────┘           │
└────────────────────────────────────────────────────────────────────────────┘
```

Region inventory:

| Region | Contents | Streaming boundary |
|---|---|---|
| Filter bar | Search input (`q`), category `Select` (seven values — All + the six categories below), a "Connected only" toggle | Renders immediately with the page shell |
| Category sections | One heading + card grid per non-empty category, in the fixed order Banks & Open Banking → Payment Gateways → Government & Tax E-Filing → ERP/POS/CRM → Email → Storage | Own `<Suspense>` boundary — `GET /api/v1/partners/providers` |
| `ConnectorCard` | Logo, name, one-line description, category badge, `partner_provider_status` badge (`beta`/`deprecated`, omitted for `active`), and either a `Connect` button or a `Connected` `StatusPill` + `Manage →` link if a non-revoked `partner_connections` row already exists for that provider | Part of the category-sections boundary |

A provider already connected still appears in the Marketplace (it is not removed from the catalog), badge
swapped from `Connect` to a `StatusPill` reading the connection's own `partner_connection_status`, because
a company legitimately holds more than one connection to the same provider in different environments or
branches (`partner_connections`' own unique index is `(company_id, partner_provider_id, environment)`, not
a hard one-per-company ceiling) — "Manage →" opens the Connected tab pre-filtered to that provider rather
than starting a second, redundant connect flow.

## Connected tab

**Purpose:** manage the company's own `partner_connections`. A conventional **List Page Template**:

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Connected integrations                                    [+ Browse marketplace]│
│ 7 connections · 1 needs attention                                          │
├────────────────────────────────────────────────────────────────────────────┤
│ [Search…]  [Category ▾][Status ▾][Environment ▾]        [▤ Density][⚙]     │
├────────────────────────────────────────────────────────────────────────────┤
│ Provider          Category    Env         Status      Health      Cert.  ⋯ │
│ Tap Payments      Gateway     production  ●Connected  ●Healthy    Standard⋯│
│ NBK Open Banking  Bank        production  ●Connected  ◐Degraded   CFP    ⋯│
│ Foodics           POS         sandbox     ●Connected  ●Healthy    Basic  ⋯│
│ ZATCA (Fatoora)   Gov e-file  production  ⚠Error      ○Down       CFP    ⋯│
│ Salesforce        CRM         sandbox     ⋯Awaiting   —           —      ⋯│
│ …                                                                          │
└────────────────────────────────────────────────────────────────────────────┘
```

Region inventory:

| Region | Contents | Streaming boundary |
|---|---|---|
| Page Header | Title, one-line summary with a live "N needs attention" count (any `error`/`awaiting_consent`-stuck/`degraded` row), secondary "Browse marketplace" action | Renders immediately |
| Filter Bar | Search, Category/Status/Environment filters, density toggle | Renders immediately |
| Data Region | `DataTable` of `partner_connections`, one row per connection | Own `<Suspense>` boundary — `GET /api/v1/partners/connections` |
| Row click | Opens `[connectionId]` — full page below `lg`, intercepted `Sheet` at `lg`+ | n/a |

Opening a row's detail (`[connectionId]/page.tsx`) is a **Detail Page Template**:

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Connected   NBK Open Banking      [●Connected]   │  Summary       │
│                          [Rotate ▾][Disconnect][⋯] │  ─────────     │
├─────────────────────────────────────────────────────  Environment: production│
│  Health                                              │  Certified: Financial │
│  Status: Degraded · 94% success (24h) · p95 1,120ms │  Partner (expires  │
│  1 open incident                                     │  2027-07-16)       │
├─────────────────────────────────────────────────────┤  Scopes:           │
│  Scopes                                              │  bank.accounts.read│
│  ● bank.accounts.read       ● bank.transactions.read │  bank.transactions.│
│  ○ bank.transfers.write  [Request elevation]         │  read granted;     │
├─────────────────────────────────────────────────────┤  bank.transfers.   │
│  Recent activity                                     │  write pending     │
│  ● Jul 16 09:30 — health check: degraded             │  approval          │
│  ● Jul 15 22:04 — statement sync: 214 lines           ├───────────────┤
│  ● Jul 14 — consent renewed (89 days remaining)      │  [View field       │
├─────────────────────────────────────────────────────┤   mappings →]  │
│  Certification                                       │               │
│  Standard passed Jul 2 · CFP passed Jul 9, exp. 2027 │               │
└───────────────────────────────────────────────────┴───────────────┘
```

Region inventory for the detail page: Page Header (breadcrumb, provider name, `partner_connection_status`
badge, Rotate/Disconnect/overflow actions gated per `# Route & Access`), a **Health** card (`health_status`,
`rolling_24h_success_rate`, `rolling_24h_p95_latency_ms`, `open_incidents`, from
`GET /connections/{id}/health`), a **Scopes** card (granted vs. `scopes_pending`, with a "Request
elevation"/"Approve elevation" affordance gated on `integrations.manage` **and** the scope's owning-module
permission, per `PARTNER_API.md → Scopes`' double-gate), a **Recent activity** timeline (health-check
transitions plus the connection's own `partner_webhook_events`, newest first — the same "first-class
content region, never a footnote" treatment `LAYOUT_SYSTEM.md`'s Detail Page Template gives an Activity
Timeline elsewhere), and a **Certification** card. ERP/POS/CRM-category connections additionally render a
"View field mappings →" link in the Summary Rail into a nested `Sheet` table editor (see `# Interactions &
Flows`); other categories omit it entirely rather than showing an empty mappings panel.

## Sandbox tab

**Purpose:** a persistent-environment control panel, not a wizard — a single page, no sub-navigation:

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Sandbox                                                                     │
│ Test connections and your own API surface without touching real data.      │
├────────────────────────────────────────────────────────────────────────────┤
│ Sandbox connections: 4        Certifications in progress: 1                │
│                                                    [Reset sandbox fixtures] │
├────────────────────────────────────────────────────────────────────────────┤
│ Simulate an inbound webhook                                                 │
│ Connection [Tap Payments — sandbox ▾]  Event [gateway.charge.succeeded ▾]  │
│ Payload  ┌──────────────────────────────────────────────┐                  │
│          │ { "gateway_reference": "TAP-SANDBOX-88213",   │  [Send simulated │
│          │   "amount": "45.5000", "currency_code": "KWD"}│   webhook]       │
│          └──────────────────────────────────────────────┘                  │
├────────────────────────────────────────────────────────────────────────────┤
│ Developer resources                                                        │
│ [Developer Portal ↗]   [OpenAPI reference ↗]   [Postman collection ↗]      │
└────────────────────────────────────────────────────────────────────────────┘
```

Region inventory: a summary strip (counts, from `GET /partners/connections?environment=sandbox` and
`GET /partners/certifications?status=in_progress`), the reset action (`integrations.sandbox.reset`, an
`AlertDialog`-confirmed destructive-adjacent action since it deletes `is_sandbox_fixture=true` rows), the
webhook simulator form (`integrations.webhook.simulate`), and a Developer Resources card of external links
into the Developer Portal (`https://developers.qayd.dev`), the rendered OpenAPI reference, and the Postman
workspace — all three specified in full by `PUBLIC_API.md → Developer Portal`, linked here rather than
reimplemented, matching this document's own "extend, do not duplicate" discipline for the API Keys/
Webhooks tabs.

# Components Used

| Component | Source | Role on this screen |
|---|---|---|
| `SettingsSectionNav` | `components/settings/settings-section-nav.tsx` (existing, `SETTINGS.md`) | The outer rail; unmodified — this screen is one of its destinations |
| `Tabs` | `components/ui/tabs.tsx` (primitive) | The five-item inner strip (Marketplace/Connected/API Keys ↗/Webhooks ↗/Sandbox); underline-indicator style, `layoutId`-animated per `COMPONENT_LIBRARY.md` |
| `PageHeader` | `components/layout/page-header.tsx` | Title, description, and primary actions on every tab |
| `ConnectorCard` (**new**) | `components/integrations/connector-card.tsx` | One `partner_providers` catalog entry in the Marketplace grid |
| `CategorySection` (**new**) | `components/integrations/category-section.tsx` | Groups the Marketplace grid by `partner_category` (extended, see `# Data & State`) with a heading and empty-category suppression |
| `DataTable` | `components/shared/data-table.tsx` (existing) | The Connected tab's connection list — server-paginated, filterable, sortable, reused unmodified |
| `StatusPill` | `components/shared/status-pill.tsx`, domains extended with `partner_connection`, `partner_certification`, `partner_provider` (**extensions owned by this document**) | Connection status, certification status, provider `beta`/`deprecated` badges |
| `HealthDot` (**new**, wraps `Badge`) | `components/integrations/health-dot.tsx` | `health_status` (`healthy`/`degraded`/`down`/`unknown`) as an icon + label pairing, never color alone |
| `ConnectWizard` (**new**) | `components/integrations/connect-wizard.tsx` | The `Dialog`/`Sheet` flow launched from a Marketplace card's "Connect" button; branches on `partner_auth_type` — see `# Interactions & Flows` |
| `ScopeChecklist` (**new**) | `components/integrations/scope-checklist.tsx` | Elevated-vs-normal scope selection inside `ConnectWizard` and the connection detail's Scopes card |
| `FieldMappingTable` (**new**) | `components/integrations/field-mapping-table.tsx` | The ERP/POS/CRM field-mapping editor, a `Sheet`-hosted small `DataTable` over `partner_field_mappings` |
| `WebhookSimulatorForm` (**new**) | `components/integrations/webhook-simulator-form.tsx` | The Sandbox tab's connection/event/payload form |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | Not used for confidence scores on this screen (Integrations carries no AI-authored figures), reused only inside the AI Agent's suggest-only proposal cards described in `# AI Integration` |
| `ApprovalCard` | `components/shared/approval-card.tsx`, `kind` extended with `"partner_scope_elevation"` and `"partner_certification"` (**extensions owned by this document**) | The scope-elevation approval and the Certified Financial Partner approval step |
| `Card` | `components/ui/card.tsx` | Health, Scopes, Certification, Recent Activity cards on the connection detail page |
| `Sheet` | `components/ui/sheet.tsx` | Intercepted provider/connection detail at `lg`+; field-mapping editor; mobile filter drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Connect wizard (non-OAuth branches); disconnect confirmation; rotate confirmation; sandbox reset confirmation |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Per-row actions on the Connected table (View, Rotate, Disconnect, Submit for certification) |
| `Tooltip` | `components/ui/tooltip.tsx` | Permission-denied explanations; health-metric definitions ("p95 latency over the last 24 hours") |
| `Combobox` | `components/shared/combobox.tsx` | Category filter, connection picker in the webhook simulator |
| `Badge` | `components/ui/badge.tsx` | Category chips, environment (`sandbox`/`production`) tags, `beta`/`deprecated` labels |
| `Skeleton`, `EmptyState`, `ErrorState` | `components/ui/skeleton.tsx`, `components/shared/*` | Per-region loading/empty/error — see `# States` |
| `useApiToast` | `hooks/use-api-toast.ts` | Every mutation's success/error surface |
| `Can` | `components/auth/can.tsx` | Every permission-gated action across all five tabs |

## `ConnectorCard`

```tsx
// components/integrations/connector-card.tsx
'use client';

import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { StatusPill } from '@/components/shared/status-pill';
import { usePermission } from '@/hooks/use-permission';
import { useRouter } from 'next/navigation';
import type { PartnerProvider, PartnerConnectionSummary } from '@/types/integrations';

interface ConnectorCardProps {
  provider: PartnerProvider;
  existingConnection?: PartnerConnectionSummary | null;
}

export function ConnectorCard({ provider, existingConnection }: ConnectorCardProps) {
  const canConnect = usePermission('integrations.connect');
  const router = useRouter();

  return (
    <Card padding="md" className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        {/* Logos render on a fixed near-white plate in both themes — see # Dark Mode */}
        <div className="flex h-10 w-10 items-center justify-center rounded-md bg-white p-1.5 ring-1 ring-ink-150">
          <img src={provider.logo_url} alt="" className="h-full w-full object-contain" />
        </div>
        {provider.status !== 'active' && (
          <Badge tone={provider.status === 'beta' ? 'accent' : 'neutral'}>{provider.status}</Badge>
        )}
      </div>
      <div>
        <p className="text-subtitle font-medium text-ink-950">{provider.name_en}</p>
        <p className="text-caption text-ink-500">{provider.category_label}</p>
      </div>
      {existingConnection ? (
        <div className="mt-auto flex items-center justify-between">
          <StatusPill domain="partner_connection" status={existingConnection.status} size="sm" />
          <Button
            variant="link"
            size="sm"
            onClick={() => router.push(`/settings/integrations/connected/${existingConnection.id}`)}
          >
            Manage →
          </Button>
        </div>
      ) : (
        <Button
          className="mt-auto"
          size="sm"
          disabled={!canConnect}
          onClick={() => router.push(`/settings/integrations/marketplace/${provider.code}`)}
        >
          Connect
        </Button>
      )}
    </Card>
  );
}
```

A card's "Connect" button is disabled-with-tooltip rather than omitted when `integrations.connect` is
absent — unlike a Banking or Sales row action, whose *existence* would reveal a capability the RBAC model
wants hidden, a provider's presence in the catalog is not itself sensitive information (every company can
see that Tap Payments exists as an option), so this screen follows `BANKING.md`'s own narrow "disabled,
not omitted" exception for exactly this reason, not the platform's default "hide, don't disable" rule.

## `StatusPill` domain extensions

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const PARTNER_CONNECTION_STATUS: Record<string, { label: string; tone: Tone }> = {
  pending:           { label: 'Pending',            tone: 'neutral' },
  awaiting_consent:  { label: 'Awaiting consent',   tone: 'warning' },
  connected:         { label: 'Connected',          tone: 'success' },
  error:             { label: 'Error',               tone: 'danger'  },
  suspended:         { label: 'Suspended',           tone: 'warning' },
  revoked:           { label: 'Revoked',             tone: 'neutral' },
};

const PARTNER_CERTIFICATION_STATUS: Record<string, { label: string; tone: Tone }> = {
  not_started: { label: 'Not started', tone: 'neutral' },
  in_progress: { label: 'In progress', tone: 'warning' },
  passed:      { label: 'Passed',      tone: 'success' },
  failed:      { label: 'Failed',      tone: 'danger'  },
  expired:     { label: 'Expired',     tone: 'danger'  },
};

const PARTNER_PROVIDER_STATUS: Record<string, { label: string; tone: Tone }> = {
  active:     { label: 'Active',     tone: 'neutral' }, // no badge rendered — see ConnectorCard
  beta:       { label: 'Beta',       tone: 'accent'  },
  deprecated: { label: 'Deprecated', tone: 'warning' },
};

Object.assign(STATUS_TABLES, {
  partner_connection: PARTNER_CONNECTION_STATUS,
  partner_certification: PARTNER_CERTIFICATION_STATUS,
  partner_provider: PARTNER_PROVIDER_STATUS,
});
```

`error` and `suspended` are deliberately different tones from `revoked`: `error` and `suspended`
(`danger`/`warning`) both describe a connection a human should look at, while `revoked` (`neutral`) is an
intentional, already-actioned end state — the same "distinguish a bad-outcome-in-progress from a
deliberately-closed loop" logic `BANKING.md` applies to `cleared` vs. `reconciled`.

## `HealthDot`

```tsx
// components/integrations/health-dot.tsx
import { CheckCircle2, AlertTriangle, XCircle, HelpCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

const HEALTH_CONFIG = {
  healthy:   { icon: CheckCircle2, label: 'Healthy',   className: 'text-success' },
  degraded:  { icon: AlertTriangle, label: 'Degraded',  className: 'text-warning' },
  down:      { icon: XCircle,      label: 'Down',       className: 'text-danger'  },
  unknown:   { icon: HelpCircle,   label: 'Unknown',    className: 'text-ink-500' },
} as const;

export function HealthDot({ status, size = 'default' }: { status: keyof typeof HEALTH_CONFIG; size?: 'sm' | 'default' }) {
  const { icon: Icon, label, className } = HEALTH_CONFIG[status];
  return (
    <span className={cn('inline-flex items-center gap-1.5 text-sm', className)}>
      <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'} aria-hidden />
      {label}
    </span>
  );
}
```

`HealthDot` never renders the icon alone — the label ships by default and is only dropped (`showLabel`
analog to `ConfidenceBadge`'s own prop) inside the Connected table's dense row context, where the same
information repeats in an adjacent, always-visible column, matching `# Accessibility`'s "never color
alone" rule.

# Data & State

## Extending `partner_category` for Email and Storage

`PARTNER_API.md → Partner Data Model` fixes `partner_category` as `'bank', 'open_banking_aggregator',
'payment_gateway', 'card_network', 'gov_efiling', 'erp', 'pos', 'crm'` — eight values, none of which is
Email or Storage. Both are genuine, additive gaps rather than a reason to invent a parallel catalog
mechanism: `partner_providers` is explicitly modeled as a "platform-level catalog," the same kind of
system-level classification table the platform already extends without incident (`account_types`,
`tax_codes`). This document adds exactly two enum values and nothing else — no new table, no new
connection lifecycle, no new auth mechanism beyond the four `partner_auth_type` already supports:

```sql
-- Additive migration owned by this document; PARTNER_API.md's own table/column set is unchanged.
ALTER TYPE partner_category ADD VALUE 'email_provider';
ALTER TYPE partner_category ADD VALUE 'storage_provider';
```

| New category | Represents | Example `partner_providers` rows | `partner_auth_type` |
|---|---|---|---|
| `email_provider` | A company's own outbound-sending identity for invoice/payslip/statement email, distinct from QAYD's own platform-default sender | Resend, SendGrid, Mailgun, Microsoft 365 (SMTP AUTH), Google Workspace (SMTP AUTH) | `api_key` (Resend/SendGrid/Mailgun); `oauth2_authorization_code` (Microsoft 365/Google Workspace "send as this mailbox") |
| `storage_provider` | An external destination a company can additionally export reports, statements, or backups to | Google Drive, Microsoft OneDrive/SharePoint, Dropbox | `oauth2_authorization_code` |

`storage_provider` is deliberately **not** how QAYD's own object storage works — `DESIGN_CONTEXT.md §1`
already fixes Cloudflare R2 as QAYD's platform infrastructure for attachments and dose-proof-equivalent
media across every module; that is never a company-facing "integration" and never appears in this
Marketplace, because a company has no credential of its own to manage for it. A `storage_provider`
connection is exclusively an **additional, opt-in export destination** a company connects on top of that
fixed platform storage — "also copy my monthly Trial Balance PDF to our own Google Drive" — never a
replacement for it, and Reports' own export flow (`REPORTS.md`, out of this document's scope) is the only
other screen that reads a `storage_provider` connection, to offer "Save a copy to…" alongside its existing
download action.

## Endpoints

Every endpoint below is `PARTNER_API.md`'s own, reused verbatim — this document introduces no new
`/api/v1/partners/*` route:

| Method | Path | Permission | Used by |
|---|---|---|---|
| GET | `/api/v1/partners/providers` | `integrations.read` | Marketplace grid; supports `?category=`, `?country=`, `?q=` |
| GET | `/api/v1/partners/providers/{code}` | `integrations.read` | Provider detail page (`[providerCode]`) |
| GET | `/api/v1/partners/connections` | `integrations.read` | Connected tab's `DataTable`; also queried unfiltered-but-thin from the Marketplace to resolve each card's "already connected" state in one round trip |
| POST | `/api/v1/partners/connections` | `integrations.connect` | `ConnectWizard`'s final submit |
| GET | `/api/v1/partners/connections/{id}` | `integrations.read` | Connection detail page |
| GET | `/api/v1/partners/connections/{id}/health` | `integrations.read` | Health card; also polled for the Connected table's `HealthDot` column, batched (see `# Performance`) |
| PATCH | `/api/v1/partners/connections/{id}` | `integrations.manage` | Rename, edit scopes/environment; production promotion (`{"environment":"production"}`) |
| POST | `/api/v1/partners/connections/{id}/scopes/approve` | `integrations.manage` **and** the scope's owning-module permission | Scopes card's "Approve elevation" |
| DELETE | `/api/v1/partners/connections/{id}` | `integrations.disconnect` | Disconnect action |
| POST | `/api/v1/partners/connections/{id}/rotate-credentials` | `integrations.credentials.rotate` | Rotate action |
| GET/POST | `/api/v1/partners/certifications` | `integrations.read` / `.certify.submit` | Certification card; Sandbox tab's in-progress count |
| POST | `/api/v1/partners/certifications/{id}/submit` | `integrations.certify.submit` | "Submit for certification" |
| POST | `/api/v1/partners/certifications/{id}/approve` \| `/reject` | `integrations.certify.approve` | Certified Financial Partner approval — always via `ApprovalCard`, never a bare button (see `# AI Integration`) |
| GET/POST/PATCH | `/api/v1/partners/field-mappings` (per `partner_field_mappings`, scoped by `?connection_id=`) | `integrations.mapping.manage` (read: `integrations.read`) | `FieldMappingTable` |
| POST | `/api/v1/partners/sandbox/reset` | `integrations.sandbox.reset` | Sandbox tab |
| POST | `/api/v1/partners/webhooks/simulate` | `integrations.webhook.simulate` | Sandbox tab's simulator form |

Cross-linked, not re-documented, for the API Keys / Webhooks tabs — the literal endpoint tables live in
`AUTHENTICATION_API.md`, `PUBLIC_API.md`, and `API_WEBHOOKS.md`:

| Method | Path | Permission | Owning document |
|---|---|---|---|
| GET/POST | `/api/v1/auth/api-keys` | `auth.apikey.read` / `.create` | `AUTHENTICATION_API.md`, format fixed by `PUBLIC_API.md` (`qk_live_…`/`qk_test_…`) |
| PATCH/DELETE | `/api/v1/auth/api-keys/{id}` | `auth.apikey.create` / `.revoke` | Same |
| GET/POST | `/api/v1/webhooks/endpoints` | `webhooks.read` / `.create` | `API_WEBHOOKS.md` |
| PATCH/DELETE | `/api/v1/webhooks/endpoints/{id}` | `webhooks.update` / `.delete` | Same |
| POST | `/api/v1/webhooks/endpoints/{id}/rotate-secret` | `webhooks.manage` | Same |
| POST | `/api/v1/webhooks/endpoints/{id}/test` | `webhooks.update` | Same |
| GET | `/api/v1/webhooks/deliveries` | `webhooks.read` | Same |

## Query keys

```ts
// lib/api/query-keys.ts (integrations-scoped factories, additive to the platform's shared factories)
export const integrationsKeys = {
  all: ["integrations"] as const,
  providers: (filters?: ProviderFilters) => [...integrationsKeys.all, "providers", filters ?? {}] as const,
  provider: (code: string) => [...integrationsKeys.all, "providers", code] as const,
  connections: (filters?: ConnectionFilters) => [...integrationsKeys.all, "connections", filters ?? {}] as const,
  connection: (id: number) => [...integrationsKeys.all, "connections", id] as const,
  connectionHealth: (id: number) => [...integrationsKeys.all, "connections", id, "health"] as const,
  certifications: (filters?: CertFilters) => [...integrationsKeys.all, "certifications", filters ?? {}] as const,
  fieldMappings: (connectionId: number) => [...integrationsKeys.all, "field-mappings", connectionId] as const,
};
```

## Cache tuning

Reusing `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` verbatim rather than inventing a bespoke
rule per resource:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Rarely-changing reference data | `providers()`, `provider()` | 5 minutes | The platform catalog changes only on a QAYD deploy, matching `permissionCatalog()`'s own class in `SETTINGS.md` |
| Transactional lists | `connections()`, `certifications()` | 30 seconds | Frequent enough state changes (a certification passing, a connection being created) that sub-minute staleness would be a visible annoyance |
| Live/derived figures | `connectionHealth()` | 0 (always stale) | Correctness over avoiding a refetch; kept fresh primarily via Realtime invalidation and a 5-minute background poll matching `PARTNER_API.md → Monitoring`'s own health-check cadence, never faster than the source recomputes |
| Rarely-changing, user-edited | `fieldMappings()` | 60 seconds | Edited occasionally by an Inventory/Sales/Purchasing Manager setting up a sync, not a high-churn resource |

## Mutations — pessimistic where sensitive, optimistic where safe

Per `FRONTEND_ARCHITECTURE.md` Principle 10: connecting, rotating, disconnecting, and approving a
certification all mutate **pessimistically** — none of these five actions render as "done" until the
server's `2xx` returns, because each is either irreversible-in-effect (a rotated secret invalidates the
old one) or trust-sensitive (a certification decision):

```ts
// hooks/integrations/use-connect-provider.ts
export function useConnectProvider() {
  const idempotencyKey = useIdempotencyKey("integrations-connect");
  return useMutation({
    mutationFn: (body: ConnectProviderFormValues) =>
      api.post("/partners/connections", body, idempotencyKey),
    // No onMutate: the wizard stays open, showing a pending state, until the server
    // actually creates the partner_connections row and returns its id/status.
  });
}

// hooks/integrations/use-rotate-credentials.ts
export function useRotateCredentials(connectionId: number) {
  return useMutation({
    mutationFn: () => api.post(`/partners/connections/${connectionId}/rotate-credentials`),
    onSuccess: () => toast.warning(t("integrations.rotateSuccessCopyNow")), // secret shown once, never again
  });
}
```

Editing a display name, toggling a filter, or dismissing the Marketplace's "Connected only" switch are
ordinary, reversible, non-sensitive interactions and mutate optimistically or are pure client state,
matching `SETTINGS.md`'s own Branches/Departments treatment:

```ts
// hooks/integrations/use-rename-connection.ts
export function useRenameConnection(connectionId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (display_name: string) => api.patch(`/partners/connections/${connectionId}`, { display_name }),
    onMutate: async (display_name) => {
      await qc.cancelQueries({ queryKey: integrationsKeys.connection(connectionId) });
      const previous = qc.getQueryData(integrationsKeys.connection(connectionId));
      qc.setQueryData(integrationsKeys.connection(connectionId), (old: PartnerConnection) => ({ ...old, display_name }));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(integrationsKeys.connection(connectionId), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: integrationsKeys.connection(connectionId) }),
  });
}
```

## SSR hydration

`marketplace/page.tsx` and `connected/page.tsx` are both thin Server Components prefetching exactly their
own tab's list query, identical in shape to `SETTINGS.md`'s and `BANKING.md`'s own pattern:

```tsx
// app/(app)/settings/integrations/connected/page.tsx
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { integrationsKeys } from "@/lib/api/query-keys";
import { ConnectedIntegrationsTable } from "@/components/integrations/connected-integrations-table";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped — never statically cached

export default async function ConnectedIntegrationsPage() {
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: integrationsKeys.connections(),
    queryFn: () => apiServer.get("/partners/connections"),
  });
  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <ConnectedIntegrationsTable />
    </HydrationBoundary>
  );
}
```

## Realtime

One company-scoped private channel, mirroring the existing `private-company.{id}.banking` /
`private-company.{id}.approvals` naming convention rather than minting one channel per connection:

| Channel | Events carried | Effect |
|---|---|---|
| `private-company.{id}.integrations` (**new**) | `partner.connection.status_changed`, `partner.connection.health_changed`, `partner.certification.passed`\|`.failed`, `partner.scope.elevation_requested`\|`.approved`, `partner.circuit_breaker.opened`\|`.closed` | Invalidates/patches `connections()`, `connection()`, `connectionHealth()`, `certifications()` — see below |
| `private-company.{id}.approvals` | Any `ai_approval_requests` decision for `kind IN ("partner_scope_elevation","partner_certification")` | Reused verbatim from the platform-wide channel `FRONTEND_ARCHITECTURE.md → Realtime` already documents; this screen introduces no second approvals channel |
| `private-company.{id}.notifications.{user_id}` | `partner.certificate_expiring` (the 7-day statutory-expiry notice `PARTNER_API.md → Credential Storage and Rotation` already defines) | Topbar bell only — this screen's own regions do not duplicate the stream, matching `BANKING.md`'s identical rule for its own notification-only events |

Per `FRONTEND_ARCHITECTURE.md → Invalidate, or patch — chosen per event, not by default`:
`partner.connection.health_changed` and `partner.circuit_breaker.*` **patch** the affected row's
`HealthDot`/`StatusPill` in place via `setQueryData` (a health figure is cheap to trust from a single
push and a full refetch would only add latency to what is already a live signal); `partner.connection.
status_changed` and `partner.certification.*` **invalidate** the connection/certification query outright,
since a status or certification transition is exactly the kind of event `FRONTEND_ARCHITECTURE.md`
reserves a full `invalidateQueries` for — never patched from a push whose payload might not reflect a
concurrent write landing in the same instant. On WebSocket reconnect after any extended drop, every one of
this screen's realtime-fed query keys is invalidated once as a single batch, exactly as `BANKING.md`
specifies for its own realtime-fed panels — a missed `partner.circuit_breaker.opened` event during an
outage must surface the moment connectivity returns, never wait for a manual refresh.

# Interactions & Flows

Every flow below assumes the caller has already cleared the relevant tab's `read` permission; where a
flow additionally requires a `manage`/`connect`/`disconnect`/`credentials.rotate`/`certify.*` gate, the
control is either absent (Marketplace's "Connect" — hidden per role-structural gap, per `# Route &
Access`) or disabled-with-tooltip, never a silent no-op.

## Connecting — three flows, one wizard shell

Clicking "Connect" on a `ConnectorCard`, or opening `[providerCode]/page.tsx` directly, opens
`ConnectWizard`. The wizard's first step is always identical — display name, environment (defaulted to
`sandbox`, matching `PARTNER_API.md → Sandbox`'s "every connection is created in `environment='sandbox'`
by default" rule; promoting to production happens later, from the connection detail page, never at
creation time) — after which it branches on the selected provider's `partner_auth_type`:

**API key / secret pair** (most Payment Gateways, Foodics-class POS, Resend/SendGrid-class Email):

```
Step 1: Display name, environment          Step 2: Credentials                  Step 3: Scopes
┌─────────────────────────────┐  ┌─────────────────────────────┐  ┌─────────────────────────────┐
│ Connect Tap Payments          │  │ Merchant API key   [______] │  │ ☑ gateway.charge.write        │
│ Display name [Tap — KWD    ] │→│ Merchant secret     [______] │→│ ☐ gateway.refund.write         │
│ Environment  (●Sandbox ○Prod)│  │ [Test connection]            │  │   requires elevation approval │
└─────────────────────────────┘  └─────────────────────────────┘  └─────────────────────────────┘
```

"Test connection" calls the provider's own lightweight health check through
`PartnerTokenBroker`-equivalent server logic before the wizard ever creates the `partner_connections` row
— a credential typo is caught inside the wizard, not discovered later as a `degraded` health status on the
Connected tab. `ScopeChecklist` renders every scope the provider's category can request (per
`PARTNER_API.md → Scopes → Scope Catalog`); an **Elevated** scope (`gateway.refund.write`,
`bank.transfers.write`, `gov.efiling.submit`) is selectable but visibly annotated "requires elevation
approval" and, per `PARTNER_API.md`'s own double-gate, lands in `scopes_pending` rather than `scopes` the
moment the connection is created — the wizard never implies an elevated capability is live immediately.

**OAuth2 authorization code** (Banks/Open Banking aggregators, Salesforce/HubSpot-class CRM, Google
Drive/Microsoft OneDrive-class Storage, Microsoft 365/Google Workspace Email):

```
Company user               QAYD (this wizard)              Bank / provider's own consent UI
     |                             |                                    |
     |-- click "Connect" --------->|                                    |
     |                             |-- POST /partners/connections ----->|
     |                             |   (status: awaiting_consent)       |
     |<-- redirect to provider's consent screen ------------------------|
     |-- (browser) approves consent on the provider's own domain ------>|
     |                             |<-- callback: code, state ----------|
     |                             |-- exchange for tokens (server-side)|
     |                             |-- status: connected                |
     |<-- back on /settings/integrations/connected, success toast ------|
```

This is `PARTNER_API.md → Open Banking`'s own consent sequence, generalized to every OAuth-authorization-
code provider, not a bank-specific special case. While `status = 'awaiting_consent'`, the connection
already appears on the Connected tab (not hidden until the redirect completes) so an abandoned or slow
consent flow is visible and re-openable rather than silently lost — see `# Edge Cases`.

**Certificate / mTLS** (Government e-filing — ZATCA's CSID, FTA's API credential):

```
Step 1: Display name, environment          Step 2: Certificate                     Step 3: Confirm
┌─────────────────────────────┐  ┌─────────────────────────────────┐  ┌─────────────────────────┐
│ Connect ZATCA (Fatoora)       │  │ Upload CSID certificate [Browse]│  │ Review & create           │
│ Display name [ZATCA — main] │→│ Registration number [__________]│→│ [Create connection]      │
│ Environment (●Sandbox ○Prod)│  │ Tax registration     [Al Rawda…]│  │                           │
└─────────────────────────────┘  └─────────────────────────────────┘  └─────────────────────────┘
```

The certificate never round-trips back to the browser after upload — the wizard's own client-side state
holds it only transiently in memory for the single multipart `POST /partners/connections` call, matching
`PARTNER_API.md → Credential Storage and Rotation`'s "no secret is ever included in an API response body"
rule; a re-opened wizard on the same provider always starts from an empty file input, never a pre-filled
one. `related_tax_registration_id` is resolved from the company's own `tax_registrations` (`TAX.md`) via a
`Combobox`, not free-typed, so a ZATCA connection can never be created against a registration number that
does not already exist in QAYD.

Every branch ends the same way: a success toast, the wizard closes, and the caller lands on
`/settings/integrations/connected/{new id}` — never back on the Marketplace — so the very next thing a
user sees after connecting is the connection they just created, not a catalog grid they have to search
again to find it in.

## Managing a connection

**Promoting to production.** The connection detail page's environment is read-only prose ("Environment:
sandbox") until the connection's certification (see below) has `status = 'passed'` at a level matching
the highest scope it holds — `PATCH /connections/{id}` with `{"environment":"production"}` before that is
rejected with `422`/`PARTNER_CERTIFICATION_REQUIRED`, and the UI never lets the request fire in the first
place: the "Promote to production" button itself is disabled-with-tooltip ("Complete certification
first — see below") rather than present-but-failing, exactly the same "explain a disabled control" pattern
`ACCESSIBILITY.md` requires platform-wide.

**Requesting/approving a scope elevation.** The Scopes card's unchecked, annotated scopes each carry a
"Request elevation" button (`integrations.manage`). Clicking it calls
`POST /connections/{id}/scopes/approve`'s counterpart request endpoint and creates an `ai_approval_requests`
row (`kind="partner_scope_elevation"`) rather than granting the scope directly — per
`PARTNER_API.md → Scopes`, elevation additionally requires the scope's own owning-module permission (e.g.
`bank.transfer` for `bank.transfers.write`), so the resulting `ApprovalCard` is addressed to whichever
role holds both `integrations.manage` and that permission, and is rendered inline on this same card rather
than requiring a trip to the separate Approval Center — mirroring `BANKING.md`'s own choice to surface a
transfer's two-key approval inline on the Banking screen itself rather than only in the queue.

**Rotating credentials.** "Rotate" opens an `AlertDialog` ("This immediately invalidates the current
secret/certificate. Any in-flight calls using the old credential will start failing.") before calling
`POST /connections/{id}/rotate-credentials`; on success, a **new** secret/certificate reference is shown
exactly once in a copy-to-clipboard field inside the same dialog, matching the "shown once" convention
`PUBLIC_API.md`'s own API-key creation and `API_WEBHOOKS.md`'s own endpoint-secret creation both already
use — the dialog does not close until the user explicitly dismisses it, so a rotated value is never lost
to an accidental click-away.

**Disconnecting.** "Disconnect" opens an `AlertDialog` naming the concrete consequence per category — for
a bank, "Statement sync will stop; your existing transactions are not affected"; for a payment gateway,
"New charges through this connection will fail; issued receipts and invoices are not affected" — never a
generic "Are you sure?" — then calls `DELETE /connections/{id}` (soft delete, per `PARTNER_API.md`'s
platform-wide immutable-financial-record convention extended to connections). A connection with any
`partner_gateway_attempts` row still `requires_action`/`authorized` (not yet captured/declined/expired) is
blocked from disconnecting outright (`409`, `errors[].code = "PARTNER_CONNECTION_HAS_PENDING_ATTEMPTS"`)
rather than silently orphaning an in-flight charge — the dialog surfaces this as a named blocker with a
link to the pending attempt, not a bare error toast.

**Certification.** "Submit for certification" (`integrations.certify.submit`) opens a `Dialog` picking the
target `partner_certification_level` (Basic/Standard/Certified Financial Partner, per `PARTNER_API.md →
Certification Levels`); submitting creates the `partner_certifications` row and the card switches to a
`in_progress` `StatusPill` with a live-updating "N of M scenarios passed" line, invalidated by the
`partner.certification.passed`/`.failed` realtime event rather than polled. A **Certified Financial
Partner** pass additionally requires a human `integrations.certify.approve` decision — rendered as an
`ApprovalCard` (`kind="partner_certification"`), never an auto-flip to `passed`, per `# Route & Access`'s
AI-Agent-ineligibility rule and `PARTNER_API.md → Certification → Workflow`'s own "Certified Financial
Partner requires `integrations.certify.approve` from a human" step.

## Field mapping (ERP/POS/CRM only)

"View field mappings →" on an ERP/POS/CRM connection's Summary Rail opens a `Sheet` hosting
`FieldMappingTable`: a small, non-virtualized `DataTable` over that connection's `partner_field_mappings`
rows (`qayd_entity`, `qayd_field`, `partner_entity`, `partner_field`, `direction`, `is_required`). Adding a
row opens an inline form with two `Combobox`es — a fixed list of QAYD entities the connection's category
can sync (`customers`, `products`, `invoices`, `vendors` for CRM/ERP; a narrower POS-specific set for POS)
and a free-text partner-field name, since QAYD has no way to introspect an arbitrary external system's own
schema — plus a `direction` `Select` (`inbound`/`outbound`/`bidirectional`) and an optional
`transform_rule` JSON editor for value maps/unit conversions, gated behind an "Advanced" disclosure so the
common case (a straight 1:1 field copy) never shows raw JSON by default. Every mutation here is
optimistic (a mapping row is low-stakes, reversible configuration, not a financial record), matching
`SETTINGS.md`'s own Branches/Departments treatment.

## Sandbox actions

**Resetting fixtures.** "Reset sandbox fixtures" opens an `AlertDialog` stating the exact scope
(`"Resets N sandbox connections and ~M fixture rows. Your production data is never affected."`, the two
numbers sourced live from a lightweight pre-check, not guessed) before calling `POST
/partners/sandbox/reset`; per `PARTNER_API.md → Sandbox`, this only ever touches rows flagged
`is_sandbox_fixture = true`, so the dialog's own reassurance is not just UX comfort — it is describing a
real, server-enforced boundary.

**Simulating a webhook.** The simulator form's Connection `Combobox` is pre-filtered to
`environment = 'sandbox'` connections only (a simulated event against a production connection is refused
server-side with `422`, and the form does not even offer one as an option); Event is a `Select` populated
from the connection's own provider category's cataloged event types (`PARTNER_API.md → Testing → Webhook
Simulation`); Payload is a JSON `Textarea` pre-filled with a realistic example for the chosen event and
editable before sending. "Send simulated webhook" calls `POST /partners/webhooks/simulate` and appends the
resulting `partner_webhook_events` row to a small, session-local "Recent simulations" list below the form
— not persisted as its own page, since `PARTNER_API.md`'s own sandbox reset already clears these rows
along with everything else flagged as a fixture.

# AI Integration

Integrations carries one of the platform's smallest AI surfaces, by design and for the same reason
`SETTINGS.md` gives its own configuration screens: this is a place for facts, credentials, and human
judgment, never synthesis. `PARTNER_API.md → Permissions`'s own role table already fixes the ceiling —
the **AI Agent** principal type holds only scoped read access on this screen plus a narrow, suggest-only
write capability elsewhere in the product — and this screen renders that ceiling rather than reinterpreting
it:

| Agent | Contribution on this screen |
|---|---|
| Compliance Agent | A single, dismissible suggestion card on the Connected tab when a certificate or Open Banking consent is inside its statutory renewal window — reusing the exact 7-day notice `PARTNER_API.md → Credential Storage and Rotation` already fires as a notification, surfaced here additionally as an inline card ("ZATCA certificate expires in 6 days — rotate now?") that deep-links straight into the Rotate dialog pre-selected on that connection; it never rotates anything itself |
| Approval Assistant | The routing engine behind every `ai_approval_requests` row this screen's scope-elevation and certification-approval actions create — visible only as the resulting `ApprovalCard`, never a separate suggestion here |
| CFO Agent | An optional, dismissible note on the Connected tab ("3 of your 4 payment-gateway connections are still in sandbox — connect production to start collecting KNET/card payments") — a link that opens the relevant connection's "Promote to production" state, never an autonomous promotion |

No agent on this screen ever creates a `partner_connections` row, approves its own scope elevation,
rotates a credential, or disconnects anything — `PARTNER_API.md`'s permission table structurally excludes
the AI Agent principal type from `gateway.refund.create`, `integrations.disconnect`,
`integrations.credentials.rotate`, and `integrations.certify.approve` regardless of any grant an
administrator might mistakenly assign, and this screen's own action buttons enforce the identical
type-check, not merely a permission-string check, matching `PARTNER_API.md → Permissions`'s explicit
defense-in-depth rationale. The one place an AI agent's own output does reach this screen at all is
indirect: a **suggest-only** gateway-charge or ERP/POS/CRM sync proposal an agent drafts elsewhere (Sales,
Purchasing, Inventory) is *fed by* a `partner_connections` row this screen manages, but the proposal itself
renders as an `ApprovalCard` on the module screen that owns it, never duplicated here — Integrations is
where a connection's plumbing is configured, not where its downstream proposals are reviewed.

# States

| State | Region | Treatment |
|---|---|---|
| Loading (first paint) | Marketplace grid | `ConnectorCard`-shaped `Skeleton` grid, grouped headers shown immediately (static copy, no fetch needed) with skeleton cards beneath each |
| Loading | Connected table | `DataTable`'s standard 8-row `Skeleton` body, per `COMPONENT_LIBRARY.md` |
| Loading | Connection detail's Health card | A skeleton matching the card's exact three-line shape, never a generic spinner, per `DESIGN_LANGUAGE.md`'s "never blank" rule |
| Empty | Connected tab, zero connections | `EmptyState`: "No integrations connected yet" + "Browse marketplace" primary action — never a bare empty table |
| Empty (filtered) | Connected tab, filters active | Lighter "No connections match your filters" variant, `DataTable`'s automatic distinction from the true-empty state |
| Empty | Marketplace, search with no matches | "No providers match “{query}”" with a "Clear search" action; category sections with zero matches are omitted entirely rather than shown with a "0 results" heading |
| Empty | Field mapping table, connection just created | "No field mappings yet — QAYD will not sync any fields until you add at least one" (an ERP/POS/CRM connection with zero mappings is a valid but inert state, and the copy says so rather than implying a fault) |
| Error | Any one region (Marketplace grid, Connected table, Health card) | Its own `ErrorState` with a "Retry" action; a Health-card fetch failure never blanks the rest of the connection detail page, per the same per-region `<Suspense>`+error-boundary discipline `BANKING.md` uses |
| Error | Connect wizard's "Test connection" step | Inline `errors[].message` from the API envelope surfaced under the credential fields via `useApiToast`'s form-error mapping — the wizard stays open and editable, never closes on a failed test |
| Awaiting | Connection mid-OAuth-consent (`awaiting_consent`) | Row/card renders a `warning`-tone `StatusPill` with a "Resume" action (re-opens the provider's consent URL) rather than looking identical to a fresh, never-started connection — see `# Edge Cases` for its 24-hour auto-expiry |
| Circuit open | Connection with an open circuit breaker (`PARTNER_API.md → Monitoring`) | `HealthDot` shows `down` with a tooltip "Temporarily paused after repeated failures — retrying automatically," and any action that would call out through this connection (e.g., a Sales screen's "Charge via Tap Payments") is disabled with the same explanation, sourced from this screen's own `health_status` field so no second lookup is needed |
| Disabled (permission) | Any gated button across all five tabs | `disabled` + `Tooltip` naming the missing permission, per `# Route & Access`; Marketplace's "Connect" is the one deliberate "disabled, not omitted" exception, matching `BANKING.md`'s own precedent for a control whose *existence* is not sensitive |
| Pending mutation | Rotate / Disconnect / Certification submit | Button shows `loading` state (`Button`'s built-in spinner, width-stable) and the triggering `AlertDialog`/`Dialog` cannot be dismissed mid-flight, preventing a double-submit exactly as `FRONTEND_ARCHITECTURE.md` Principle 9 requires |

# Responsive Behavior

Per `LAYOUT_SYSTEM.md → Responsive Layout Rules`, each region degrades on the documented path for its own
template, never an ad-hoc one:

| Region | `base`–`sm` | `md` | `lg` | `xl`+ |
|---|---|---|---|---|
| Inner tab strip | Becomes a `Select`-style tab picker (five items don't fit a thumb-width strip below `sm`) | Horizontal `Tabs`, scrollable if needed | Full horizontal `Tabs`, no scroll | Same, generous gutters |
| Marketplace grid | 1 card per row, category headers collapse to a sticky mini-nav for jumping between categories | 2 cards per row | 3 cards per row | 4 cards per row |
| Connected `DataTable` | Rows become stacked `Card`s (provider, status, health only; "View" opens detail) | Table reappears with horizontal scroll, priority columns only (Provider, Status, Health) | Full table, all columns | Full table, generous gutters |
| Connection detail | Single column; Summary Rail moves below the main column | Same as `base` | 8/4 split activates | 8/4 split, more Summary Rail whitespace |
| Connect wizard | Full-screen `Dialog` (`size="full"`) | Same | Centered `Dialog`, `size="lg"` | Same |
| Field mapping `Sheet` | Full-screen | Same | Slides from the inline-end edge, `60%` viewport width | Same, capped at `640px` |
| Sandbox tab | Single column, simulator form fields stack | Same | Simulator form fields go 2-column (Connection/Event side by side, Payload full width) | Same |

Touch targets follow `LAYOUT_SYSTEM.md`'s platform-wide minimum: every `ConnectorCard`'s "Connect" button,
every row's `DropdownMenu` trigger, and the tab picker's options all maintain a 44×44px hit area at `md`
and below regardless of visual size, implemented once in the shared `IconButton`/`Button` components
rather than per-instance on this screen.

# RTL & Localization

Every rule below is `LAYOUT_SYSTEM.md → RTL Layout Mirroring`'s existing contract applied to this screen's
own content — this document introduces no screen-specific RTL exception:

- **Logical properties only.** The Marketplace grid's category-heading-to-cards gap, the Connected table's
  frozen "Provider" column (see below), and every card's internal padding use `ms-*`/`me-*`/`ps-*`/`pe-*`,
  never `ml-*`/`mr-*`, enforced by the same CI-blocking ESLint rule platform-wide.
- **The "Provider" column stays frozen at the reading-start edge** (`inset-inline-start: 0` in LTR, which
  the browser resolves to the *right* edge automatically under `dir="rtl"`) as the Connected table scrolls
  horizontally on narrower viewports — the identical frozen-identifier-column strategy `LAYOUT_SYSTEM.md →
  Table responsive strategy` already specifies for every wide QAYD table.
- **Health percentages and latency figures are numeral-isolated.** `rolling_24h_success_rate` (`94%`) and
  `rolling_24h_p95_latency_ms` (`1,120ms`) are wrapped in the shared `<Bidi>` component
  (`LAYOUT_SYSTEM.md → Rule 3`) so they never suffer bidi reordering inside an Arabic sentence like
  "معدل النجاح خلال ٢٤ ساعة ‎94%‎" — rendered with Western Arabic numerals (`numberingSystem: "latn"`)
  even under the Arabic locale, matching the platform's finance-wide numeral convention.
- **Icons.** `ArrowUpRight` on the API Keys/Webhooks cross-link tabs is non-directional (an external-link
  glyph, not a spatial one) and never flips; the wizard's step-forward chevron uses the shared
  `ChevronStart`/`ChevronEnd` wrapper (`LAYOUT_SYSTEM.md → Rule 4`) so "Next" points visually toward the
  reading-end edge in both directions.
- **Provider logos never mirror.** A bank or payment-gateway logo is brand artwork, not a directional UI
  glyph — `ConnectorCard`'s logo plate renders the image unmodified regardless of `dir`, the same "brand
  imagery is exempt from layout mirroring" treatment `ICONOGRAPHY.md → Custom/Brand Icons` already applies
  to QAYD's own logo lockup.
- **The OAuth consent screen itself is out of QAYD's RTL control.** Once a bank or aggregator's own
  hosted consent page opens (`# Interactions & Flows`), its language and direction are that provider's own
  responsibility — QAYD passes a `locale` hint on the authorization request where the provider's own API
  supports one, but does not guarantee RTL correctness on a page QAYD does not render; this is called out
  explicitly in `# Edge Cases` rather than silently assumed away.
- **Field-mapping "Advanced" JSON editor** for `transform_rule` stays `dir="ltr"` unconditionally even in
  an Arabic session — JSON syntax itself has no RTL reading order, and forcing bidi handling onto a code
  editor would make brackets and colons harder to scan, the same reasoning `LAYOUT_SYSTEM.md → Rule 5`
  applies to keeping charts LTR-internal regardless of page direction.

All UI copy on this screen — button labels, `AlertDialog` bodies, `EmptyState`/`ErrorState` text, the
Compliance Agent's suggestion strings — is added as new keys across all four `TR` languages (`en`/`ar`/
`es`/`fr`, per the platform's i18n convention) rather than hardcoded, with English as the resolution
fallback for any key temporarily missing a translation.

# Dark Mode

Pure token swap, per `COMPONENT_LIBRARY.md → Design tokens` — no screen-specific dark-mode branch exists
anywhere in this document's component code. Two details specific to this screen's own content, not new
tokens:

- **Provider logo plate.** Most third-party SVG/PNG logos (banks, gateways, ERPs) are drawn assuming a
  light background and read poorly or invisibly against QAYD's dark-mode canvas. `ConnectorCard` therefore
  renders every logo inside a fixed near-white plate (`bg-white`, a 1px `ring-ink-150`) **regardless of
  theme** — the one place on this screen a surface is deliberately *not* a token-driven light/dark swap,
  documented here explicitly as the exception rather than left to be "discovered" as an inconsistency in
  dark-mode QA. This mirrors how payment/e-commerce products (Stripe's own connected-apps grid among them)
  universally solve the same third-party-logo-on-dark problem.
- **Health/status color mapping is identical across themes** — `success`/`warning`/`danger`/`neutral`
  resolve through the same semantic tokens `COMPONENT_LIBRARY.md` already lifts for dark mode (e.g.
  `--qayd-warning-600` shifts from `#9C6B15` to `#C99A3F` for sufficient contrast on a dark surface), so a
  `HealthDot` or `StatusPill` never needs a screen-specific override.

Every screen and dialog on this page is verified in both `data-theme="light"` and `data-theme="dark"` in
the same pull request that introduces it, per `DESIGN_LANGUAGE.md` Principle 6 — never a follow-up pass.

# Accessibility

- **Keyboard.** The Marketplace grid exposes a `grid` ARIA role with roving `tabindex` (arrow-key
  navigation between cards, `Enter`/`Space` activates the focused card's primary action), matching the
  pattern any card-grid finance component in `COMPONENT_LIBRARY.md` already follows rather than inventing
  a bespoke grid-navigation model for this screen alone. `ConnectWizard` and every `Dialog`/`AlertDialog`
  inherit Radix's full focus-trap and `Escape`-to-close contract unmodified.
- **Never color alone.** `HealthDot` and every `StatusPill` domain this document adds pair an icon and a
  text label with color, never color by itself — a `degraded` connection reads correctly in a monochrome
  screenshot or to a colorblind user exactly as `COMPONENT_LIBRARY.md`'s `AmountCell` debit/credit
  convention already establishes platform-wide.
- **Disabled controls explain themselves.** Every `disabled` button on this screen (missing permission,
  certification-gated production promotion, sandbox-only webhook simulator) carries `aria-describedby`
  pointing at the `Tooltip` content naming the exact reason, per `ACCESSIBILITY.md → RBAC-aware disabled
  controls must explain themselves` — a screen-reader user encounters the same explanation a sighted user
  gets from hovering, never silence.
- **Secret-reveal fields.** The one-time secret/certificate shown after Connect or Rotate renders in a
  `readonly` `Input` with an adjacent "Copy" `IconButton`; a successful copy announces via an
  `aria-live="polite"` region ("Copied to clipboard") rather than relying on a purely visual checkmark
  flash, so the confirmation reaches assistive technology too.
- **Destructive-adjacent confirmations.** Disconnect and Rotate `AlertDialog`s both title their primary
  button with the concrete verb ("Disconnect", "Rotate credentials"), never a bare "Confirm," and both use
  `variant="destructive-quiet"` rather than the louder `destructive` reserved for permanent-deletion
  contexts (`COMPONENT_LIBRARY.md`'s own tone distinction) — a disconnect is reversible by reconnecting, a
  rotation is a routine security action, and neither should read with the alarm register of, say, voiding
  a posted financial record.
- **Contrast.** Every state's text/icon pairing meets AA (4.5:1 for body text, 3:1 for the `HealthDot`
  icon against its surface) in both themes, verified against the same token set `DESIGN_LANGUAGE.md →
  Contrast targets` already audits platform-wide — this screen introduces no new color that would need a
  separate contrast pass.
- **Reduced motion.** The inner `Tabs` strip's underline-indicator animation, the wizard's step transition,
  and the Marketplace grid's skeleton-to-content fade all honor `prefers-reduced-motion` by collapsing to
  an instant state change, per `DESIGN_LANGUAGE.md → Reduced motion` and `FRONTEND_ARCHITECTURE.md`
  Principle 11 — no exception is carved out for this screen.

# Performance

- **The Marketplace catalog is small, cheap, rarely-changing reference data** — realistically dozens of
  `partner_providers` rows platform-wide, not thousands — so `GET /partners/providers` is fetched once per
  5-minute `staleTime` window with no pagination and no virtualization; `DataTable`/`TanStack Virtual`'s
  machinery, load-bearing for Journal Entries or Bank Transactions, is deliberately not reached for here.
- **The Connected tab scales with a company's own integration count**, realistically single or low-double
  digits, not the tens of thousands a Ledger view contends with — ordinary `page`/`per_page` pagination
  (`API_PAGINATION.md`'s page mode) is sufficient, and this screen never adopts cursor-mode/virtualized
  scrolling the way `BANKING.md`'s transactions table or `GENERAL_LEDGER.md`'s own view must.
- **Health checks are batched, never N+1.** `GET /partners/connections` itself includes each row's last
  known `health_status`/`last_health_check_at` inline (per `PARTNER_API.md`'s own `partner_connections`
  columns) — the Connected table never issues one `GET .../health` call per row to paint its `HealthDot`
  column; the dedicated `GET /connections/{id}/health` endpoint (with its richer
  `rolling_24h_success_rate`/`p95_latency_ms` figures) is called lazily, only when a single connection's
  detail page or `Sheet` actually opens.
- **Server-first paint, Suspense per region**, mirroring `BANKING.md`'s and `AI_COMMAND_CENTER.md`'s
  identical pattern: `connected/page.tsx` prefetches the connections list server-side (the one query every
  other region benefits from having warm) and streams the Health card, Scopes card, and Recent Activity
  timeline on the detail page independently behind their own `<Suspense>` boundaries, so a slow
  `rolling_24h_p95_latency_ms` computation never delays the Scopes card from painting.
- **The wizard's "Test connection" step has its own debounced, cancelable request** — re-clicking before a
  prior test resolves cancels the in-flight one via `AbortController` rather than racing two responses
  against the same form state, the same pattern `AccountPicker`'s debounced search already establishes in
  `COMPONENT_LIBRARY.md`.
- **Provider logos are served at the exact display size** (40×40 logical pixels, 2x/3x raster variants via
  `srcset` or a shared SVG) from QAYD's own asset pipeline, never hot-linked from a partner's own domain —
  avoiding both a third-party performance dependency and the mixed-content/availability risk of relying on
  an external logo host staying up.

# Edge Cases

- **A connection stuck `awaiting_consent`.** A user abandons the OAuth tab mid-flow (closes it, loses
  network, or simply changes their mind). The connection is not silently deleted — it remains visible on
  the Connected tab in its `warning`-tone `awaiting_consent` state with a "Resume" action that re-opens the
  same authorization URL. A background job auto-expires any connection still `awaiting_consent` after 24
  hours (soft-deleting it and freeing the `(company_id, partner_provider_id, environment)` unique slot so
  a fresh attempt is not blocked by a dead one), rather than leaving an indefinite half-created row a user
  has to notice and clean up manually.
- **Two tabs rotate the same connection's credentials concurrently.** The second `POST
  .../rotate-credentials` call still succeeds server-side (rotation has no optimistic-concurrency
  precondition), but the first tab's now-stale "copy this secret" dialog is showing a value the server has
  already superseded. On `onSettled`, this screen invalidates `connectionHealth()`/`connection()` and, if
  the mutation's own response `id`/timestamp no longer matches the latest server state, replaces the
  dialog's content with a warning ("This secret was rotated again from another session — showing the
  newest value") rather than silently displaying a dead credential as if it were current.
- **Certification expires mid-session.** A `Certified Financial Partner` certification's `expires_at`
  passes while a user has the connection detail page open. The realtime `partner.certification.failed`-
  equivalent transition (certification flips to `expired`) patches the Certification card live, and any
  elevated scope that certification was gating for production immediately shows its "requires elevation
  approval" annotation again — the UI never continues showing an elevated capability as usable past the
  server's own enforcement point, matching `PARTNER_API.md → Certification`'s "production calls begin
  failing closed... rather than silently continuing on a stale review" rule.
- **Disconnecting a connection with pending gateway attempts.** Covered in `# Interactions & Flows` — the
  `409`/`PARTNER_CONNECTION_HAS_PENDING_ATTEMPTS` block is surfaced as a named, actionable blocker inside
  the `AlertDialog` itself, not a bare toast the user has to correlate back to "why didn't this work."
- **A category with no catalog entries yet.** Kuwait's own government e-filing today has no API-based
  authority (`PARTNER_API.md → Government E-Filing`'s `ManualFilingAdapter` row) — the Government & Tax
  E-Filing category section still renders (so a Kuwaiti company understands the category exists) with a
  single informational card explaining that filing in Kuwait is currently a downloadable-package workflow
  reached from the Tax module directly, rather than a "Connect" button that would fail or mislead.
- **A `storage_provider`/`email_provider` connection is disconnected while Reports or the email-sending
  flow has it selected as an active destination.** The owning screen (`REPORTS.md`'s export flow; the
  platform's own outbound-email sender selection, out of this document's scope) falls back to QAYD's own
  default (Cloudflare R2 for storage exports; the platform default sender for email) the next time it is
  used, and this screen's own Disconnect confirmation for these two categories names that fallback
  explicitly, the same "name the concrete consequence" discipline the Bank/Gateway disconnect copy uses.
- **Company switching.** Exactly `FRONTEND_ARCHITECTURE.md → Company switching`'s platform-wide contract —
  `queryClient.clear()` plus `router.refresh()` on switch guarantees this screen can never show one
  company's connections, health, or in-progress wizard state after switching to another; an open
  `ConnectWizard` mid-flow is treated the same as any other unsaved-draft builder and prompts a
  confirmation before discarding, per that document's own rule for a half-typed form at switch time.
- **Rate-limited sandbox actions.** A user mashing "Reset sandbox fixtures" or the webhook simulator faster
  than the platform's Redis-backed limiter allows receives the standard `429`/`RATE_LIMITED` envelope
  (`API_ERROR_HANDLING.md`) surfaced as a toast with the exact `retry_after_seconds`, never a silently
  swallowed click — `useApiToast`'s existing envelope-to-toast mapping handles this without any
  screen-specific error branch.

# End of Document
