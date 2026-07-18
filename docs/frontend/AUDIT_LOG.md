# Audit Log — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: AUDIT_LOG
---

# Purpose

The Audit Log screen is the single, permissioned, human-facing window onto `audit_logs` — the
platform-wide, append-only ledger of *every* mutation, authentication event, permission change, and AI
action across every module and every tenant company, exactly as `docs/database/DATABASE_AUDIT_LOGS.md`
specifies it. That document owns what gets written, when, and under what guarantees (capture strategy,
hash-chain tamper evidence, partitioning, retention, masking); this document owns how a human — almost
always an Owner, CFO, Finance Manager, or a member of the Auditor/External Auditor roles the platform
reserves this screen for — reads it: the searchable, richly-filtered DataTable of raw events; the diff
viewer that turns a JSONB `old_values`/`new_values` pair into something a person can actually compare; the
entity history timeline that replays one record's entire life story in order; the explicit visual
distinction between something a human did and something an AI agent did; the tamper-evidence and
retention/archival affordances that make this screen usable as real audit evidence, not just a debugging
convenience; and the permissioned export path a Finance Manager or external auditor uses to get a copy of
the trail off the platform.

This screen is deliberately **not** a general activity feed and not a second Reports module. Three
existing surfaces already touch parts of this data, and this document is explicit about the boundary with
each so nothing here is duplicated or contradicted:

1. **Application/operational logs are a different, non-user-facing record.** `docs/api/API_ARCHITECTURE.md`
   states the platform rule directly: QAYD "deliberately maintains two separate, non-interchangeable
   records of what happened" — operational logs (debugging, 90 days hot / 1 year cold, infrastructure
   detail like worker hostnames and query plans) and `audit_logs` (a business record, compliance- and
   forensics-oriented, retained per the company's statutory-aligned policy, "itself exposed through a
   permissioned `GET /api/v1/audit-logs` endpoint rather than a log-aggregation tool"). This screen renders
   only the latter. A CFO or an external auditor is handed access to this screen; neither is ever handed
   access to the operational log store, which has no business meaning to their engagement.
2. **The Reports module's `audit`-category reports are a different permission and a different shape.**
   `docs/accounting/REPORTS.md` and `docs/frontend/REPORTS.md` define `reports.audit.read` as the key that
   gates pre-built, aggregated "Standard Reports" over `audit_logs`/`report_runs`/journal-entry history —
   summarized, scheduled, exportable *reports*. This screen instead renders the raw, row-level, drillable
   ledger itself, gated by the separate `audit.read` permission. The two permissions are granted to an
   overlapping but not identical set of roles (`frontend/REPORTS.md`'s own role table grants
   `reports.audit.read` broadly across senior finance and audit-facing roles while withholding it from
   every operational role, and marks External Auditor's own grant "delegated (read-only, cannot dismiss
   anomalies)" — the same asymmetry, human-approval-only posture this document repeats for its own
   anomaly-dismissal action in `# AI Integration`). A user can legitimately hold one permission without the
   other; `# Route & Access` states exactly what each combination renders. Every Standard Report in the
   `audit` category carries a "View in Audit Log" action that deep-links here with the report's own scope
   translated into this screen's filter query string, and this screen's own Export menu, symmetrically,
   never re-implements a scheduled/recurring report — that stays Reports' job.
3. **Per-record embedded audit tabs are a narrower, pre-existing convenience, not a competing screen.**
   `docs/frontend/VENDORS.md` (`VendorAuditLogTable`, `GET /purchasing/vendors/{id}/audit-log`,
   `purchasing.vendor.audit.read`) and `docs/frontend/EMPLOYEES.md` (`EmployeeAuditLogTable`, `GET
   /payroll/employees/{id}/audit-log`) already render a compact, single-entity slice of this exact data
   inside their own detail-page tabs, flattened to one row per changed field for scanability in a cramped
   tab. This document does not replace either — a Purchasing Employee who can read one vendor's own
   history does not thereby gain the broader `audit.read` this screen requires. It does add one small,
   additive link every such embedded tab can adopt without a breaking change: a "View full audit trail"
   action that deep-links to `/audit-log?entity_type=vendors&entity_id={id}&view=timeline`, handing the
   user off to this screen's richer Entity History Timeline (`# Interactions & Flows`) for the cases —
   increasingly common the longer a record has existed — where the tab's own compact table is not enough.

Three properties, mirrored from `docs/database/DATABASE_AUDIT_LOGS.md`'s own founding principles, drive
every decision below:

1. **The frontend renders facts; it never edits them.** `audit_logs` rows are immutable and hash-chained
   at the database layer (`REVOKE UPDATE, DELETE`, a `BEFORE UPDATE OR DELETE` trigger that raises on any
   attempt). This screen has no edit affordance for a row, ever — its only mutating actions are dismissing
   an anomaly *flag* with a recorded reason (itself a new, additive audit row, never a change to the row it
   is about), requesting an archival restore, and requesting an export — all three are additive, reversible,
   and themselves audited (`# AI Integration`, `# Edge Cases`).
2. **Scale is the default assumption.** `docs/database/DATABASE_AUDIT_LOGS.md → Performance` calls
   `audit_logs` "the largest table in the database" by nature and design. This screen is cursor-paginated
   and virtualized from the first line of its specification — `docs/frontend/FRONTEND_ARCHITECTURE.md`
   already names `audit-logs` twice, alongside `ledger-entries` and `stock-movements`, as one of the
   platform's fixed set of "unbounded, append-only tables" that use `useInfiniteQuery` and
   `@tanstack/react-virtual` rather than a "page 4 of 190" control the API does not even support for this
   resource (`docs/api/API_PAGINATION.md` lists `audit_logs` by name among the tables whose cursor-mode
   `total` is intentionally always `null`).
3. **Every actor is attributable, and the UI never blurs human and AI.** `docs/database/
   DATABASE_AUDIT_LOGS.md` gives every row exactly one of `actor_user_id` (a human), `actor_service` (e.g.
   `ai:reporting-agent`, or a scheduler), or neither (an unauthenticated event), plus an independent
   `acting_as_user_id` for impersonation. This screen's Actor column and its Diff Viewer are built around
   that three-way distinction as a first-class rendering concern, not a fallback label (`# Components Used`,
   `# Edge Cases`).

This document assumes `docs/frontend/FRONTEND_ARCHITECTURE.md` (App Router structure, data layer, cache
tuning, realtime), `docs/frontend/COMPONENT_LIBRARY.md` (every reused primitive and Finance Component
below), `docs/frontend/RESPONSIVE_DESIGN.md` (the finance-table responsive ladder and virtualization
rules), `docs/frontend/ACCESSIBILITY.md` (table semantics, live regions, RBAC-aware disabled controls),
`docs/frontend/DARK_MODE.md` (semantic color tokens), `docs/frontend/NAVIGATION_SYSTEM.md` and
`docs/frontend/IMPORT_EXPORT.md` (the precedent for a cross-module screen outside the fixed ten-item
Sidebar), `docs/frontend/ICONOGRAPHY.md` (this screen's icons are already reserved there by name),
`docs/api/API_PAGINATION.md` and `docs/api/API_FILTERING_SORTING.md` (cursor and filter mechanics), and the
three backend documents this screen is the UI for — `docs/database/DATABASE_AUDIT_LOGS.md`,
`docs/database/DATABASE_ARCHIVING.md`, and `docs/database/DATABASE_SOFT_DELETE.md` — open alongside it.
Nothing in this document introduces a new backend business rule, a new table, or a new tamper-evidence
mechanism those documents do not already establish; it introduces only the minimal, additive read/export/
request endpoints a UI needs to expose data and workflows those documents already define.

# Route & Access

| Field | Value |
|---|---|
| App Router path | `app/(app)/audit-log/page.tsx` → `/audit-log` |
| Route group / shell | `(app)`, no module-level `layout.tsx` sub-nav of its own — this screen is a single page with an in-page Table/Timeline toggle, not a tab set |
| Rendering mode | `export const dynamic = "force-dynamic"; export const fetchCache = "default-no-store";` — tenant-scoped, never statically cached |
| Primary permission | `audit.read` |
| Export permission | `audit.export` (this document's own granular addition — composes with, never replaces, `audit.read`, the identical relationship `docs/frontend/GENERAL_LEDGER.md` establishes between `accounting.report.read` and `accounting.report.export`) |
| Anomaly review permission | `audit.anomaly.review` (this document's own addition — `docs/accounting/REPORTS.md`'s Fraud Detection description already anticipates the need for "a human with an `audit`-category permission to record a reason" without naming one; this is that permission) |
| Chain-verification permission | `audit.chain.verify` (this document's own addition — running `audit:verify-chain` on demand touches every partition, so it is gated more tightly than plain `audit.read`) |
| Retention/restore permissions | `database.archive.search`, `database.archive.restore.request`, `database.archive.restore.approve`, `database.legal_hold.manage` — reused verbatim from `docs/database/DATABASE_ARCHIVING.md → Approval chain and permissions`. `docs/database/DATABASE_SOFT_DELETE.md`'s shorter `retention.manage`/`legal_hold.manage` spelling names the same underlying capability at its own compliance-reporting-endpoint level; this screen follows `DATABASE_ARCHIVING.md`'s longer, more recently-specified keys for every restore/legal-hold call in `# Data & State`, the identical naming-drift resolution `docs/frontend/SETTINGS.md` applies twice for its own two documented drifts |
| Read model | `audit_logs` via `GET /api/v1/audit-logs` — the foundation endpoint `docs/accounting/WAREHOUSES.md` already cites by name for exactly this pattern ("`/api/v1/audit-logs?entity_type=warehouse&entity_id={id}` (foundation endpoint, not module-specific)") |
| Realtime channel | `private-company.{company_id}.audit-logs` |
| Deep-linkable state | Query string only: `?actor_user_id=`, `?actor_service=`, `?category=`, `?action=` (repeatable/comma-joined), `?entity_type=&entity_id=`, `?date_from=&date_to=` or `?range=`, `?ip_address=`, `?ai_assisted=`, `?q=`, `?view=table\|timeline`, `?sort=`. No dynamic path segment for a single row — a specific event opens as an expandable row disclosure or a `Sheet`, never a route, mirroring `GENERAL_LEDGER.md`'s identical reasoning that a scope is state on one route, not a navigation |

**This is not one of the ten fixed Sidebar modules, by design.** `docs/frontend/NAVIGATION_SYSTEM.md`'s
module map is a deliberately fixed, platform-wide ten-item list (Dashboard through AI), and — exactly as
`docs/frontend/IMPORT_EXPORT.md` reasons for its own cross-cutting screen — this document does not extend
it to an eleventh. Instead, **Settings gains one additional sub-navigation bullet**: **Audit Log —
`/audit-log` — `audit.read`**, following the identical precedent `IMPORT_EXPORT.md` sets for "Import &
Export" and `DOCUMENT_CENTER.md` sets for its own bullet: grouped with Settings because it is a
compliance-and-governance-adjacent utility a user visits deliberately, not because Settings owns
`audit_logs`. Four additional entry points exist, none of them the primary one:

- Every module's own embedded audit tab (`VendorAuditLogTable`, `EmployeeAuditLogTable`, and any future
  sibling) gains a "View full audit trail" link, per `# Purpose`, deep-linking here pre-scoped to that
  entity.
- Every `audit`-category Standard Report in Reports carries a "View in Audit Log" action, per `# Purpose`.
- The Command Palette's Navigate group includes "Audit Log" once `filterNavByPermissions` resolves it
  visible against the viewer's `audit.read` grant — no special-casing beyond `NAVIGATION_SYSTEM.md`'s
  existing `NAV_TREE` mechanism.
- On mobile, it is reached through **More**, the same as every non-bottom-tab destination.

**Role grants.** `docs/database/DATABASE_SOFT_DELETE.md → Auditor access without mutation rights` already
publishes the authoritative role table for `audit.read` (Owner, CFO, Auditor, External Auditor: yes;
Senior Accountant, Accountant, Read Only: no). This document reuses it verbatim and extends it with CEO —
consistent with every other module's "typical roles" list in `NAVIGATION_SYSTEM.md`, which always seats
CEO directly alongside Owner for company-wide read access — and adds this screen's own three granular keys:

| Permission | Owner | CEO | CFO | Finance Manager | Senior Accountant | Accountant | Auditor | External Auditor | Read Only |
|---|---|---|---|---|---|---|---|---|---|
| `audit.read` (page shell, table, timeline, diff viewer) | Yes | Yes | Yes | Yes | No | No | Yes | Yes | No |
| `audit.export` (Export menu) | Yes | Yes | Yes | Yes | No | No | Yes | No\* | No |
| `audit.anomaly.review` (dismiss/annotate a flag) | Yes | Yes | Yes | Yes | No | No | Yes | No | No |
| `audit.chain.verify` (on-demand hash-chain verification run) | Yes | Yes | Yes | No | No | No | Yes | Yes | No |
| `database.archive.search` (retention panel — search archived tiers) | Yes | Yes | Yes | Yes | Yes | No | Yes | Yes | No |
| `database.archive.restore.request` (request a restore extract) | Yes | Yes | Yes | Yes | Yes | No | Yes | Yes | No |
| `database.archive.restore.approve` (approve a pending restore) | Yes | Yes | Yes | Yes | No | No | No | No | No |
| `database.legal_hold.manage` (create/release a legal hold) | Yes | No | Yes | No | No | No | No | No | No |

\* External Auditor can *read* and *request* everything this screen offers but never *authorizes* a
bulk export unsupervised — mirroring `docs/database/DATABASE_ARCHIVING.md`'s own explicit rule for the same
role one level down ("External Auditor is deliberately given `database.archive.search` and
`.restore.request` but never `.approve` of any kind — an external party can ask, never authorize"). An
External Auditor's Export menu instead offers **"Request export"**, which creates the identical export job
in a `pending_approval` state that a Finance Manager or CFO must approve before it runs — the same
second-approver shape `DATABASE_ARCHIVING.md` already specifies for a `full_reattach` restore, applied here
to a bulk export of the raw trail rather than invented as a new approval mechanism.

**AI agents reading this same data do not use this screen.** The Auditor agent (`agent_type: auditor`,
`suggest_only`, target permission `audit.read` per `docs/api/INTERNAL_API.md`'s agent roster) and the
Fraud Detection agent (`fraud_detection`, `requires_approval`, target permission `audit.read`) both consume
`audit_logs` by calling `GET /api/v1/audit-logs` as the authenticated `ai_agent` principal — the identical
endpoint, the identical permission gate, and the identical Laravel `FormRequest`/RBAC path a human browser
session uses, per `docs/api/INTERNAL_API.md`'s three-layer guarantee that the AI layer has no other route to
this data. The Compliance Agent's `get_audit_logs` tool (`docs/ai/agents/COMPLIANCE_AGENT.md`) does the
same. None of the three renders this screen or any screen; `# AI Integration` covers only the surfaces of
their output that *do* appear here (a narrative explanation, and passive anomaly flags).

# Layout & Regions

```
┌───────────────────────────────────────────────────────────────────────────────────────────┐
│ Settings / Audit Log                 [Verify chain ▾] [Ask Auditor Agent] [Export ▾]      │ Breadcrumb + actions
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ ⓘ Showing the last 13 months (hot tier) · ~341,208 events · Older records are archived —   │ Retention banner
│   Request access                                                    No active legal hold  │
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ [ Actor ▾ ] [ Module/Category ▾ ] [ Entity ▾ ] [ Action type ▾ ] [ Date range ▾ ]  [Filters]│ Filter bar
├───────────────────────────────────────────────────────────────────────────────────────────┤
│ [ Table ]  [ Timeline ]  ← Timeline enables once exactly one entity is scoped               │ View toggle
├─────────────────────────────────────────────────────────────────────────────────┬───────────┤
│ Time ▾        Actor                 Action                    Entity      Reason│ ┌────────┤
│ ──────────────────────────────────────────────────────────────────────────────  │ │AI panel│
│ Jul 16 09:22  👤 Dana Al-Rashidi     invoice.voided        ⚑  INV-2026-33…  Dup..│ │(collap-│
│  ⌄ expanded — Before / After diff, Reason, IP, device                          │ │sible,  │
│ Jul 16 09:15  🤖 Fraud Detection     ai_action.flag.raised    INV-2026-33…  —   │ │persist-│
│ Jul 16 08:50  👤 Fahad Al-Ostath     role.permission.granted  role: Auditor Onb.│ │ent rail│
│ …(virtualized rows, cursor-paginated — hundreds of thousands scroll smoothly)…  │ │at 3xl:)│
├─────────────────────────────────────────────────────────────────────────────────┴───────────┤
│ ~341,208 events matching your filters (approximate — see Performance)                       │
└───────────────────────────────────────────────────────────────────────────────────────────┘
```

**Breadcrumb + page actions.** `Settings / Audit Log`, collapsing to a back-chevron + title below `lg:`
per `NAVIGATION_SYSTEM.md`'s Top App Bar adaptation. Three actions sit at the trailing edge, each
independently permission-gated and each rendered only when the viewer holds its key at all (per
`DataTable`'s row-action convention in `COMPONENT_LIBRARY.md`, omitted rather than disabled when the
action's very existence is sensitive): **Verify chain** (`audit.chain.verify`, a `DropdownMenu` offering
"Run now" and "View latest result"), **Ask Auditor Agent** (`audit.read` — a read-only explanation, so it
needs no permission beyond the page's own), and **Export** (`audit.export`, a `DropdownMenu` of CSV / XLSX
/ PDF / "Signed export (with chain-verification report)").

**Retention banner.** A persistent, non-dismissible info strip (`AuditRetentionBanner`,
`components/audit/audit-retention-banner.tsx`) stating the company's current hot-tier query window — 13
months, matching `docs/database/DATABASE_AUDIT_LOGS.md → Partitioning & Retention`'s own hot/warm boundary
— the approximate total matching the active filters, whether a legal hold is currently active for this
company, and a "Request access" action that opens the Retention & Legal Holds panel (`# Interactions &
Flows`). This is the screen's rendering of the "retention/archival link" the platform's compliance model
requires: `docs/database/DATABASE_ARCHIVING.md → Company-visible retention window` states that a company's
own in-app view "queries only hot+warm... by default... Anything older surfaces as a 'records available on
request' affordance... rather than a live query" — this banner is that affordance, made concrete for this
specific screen rather than left as an unbuilt cross-reference.

**Filter bar.** Five primary controls — **Actor**, **Module/Category**, **Entity**, **Action type**, **Date
range** — plus a **Filters (n)** trigger for the remaining secondary facets (IP address, IP/device text
search, reason-contains, AI-assisted toggle), opened in a `Sheet` rather than crowding the bar, mirroring
the exact Trial Balance/General Ledger filter-bar convention. Below `md:`, the whole bar collapses to one
"Filters" button, per `RESPONSIVE_DESIGN.md`'s five-rule finance-table ladder.

**View toggle.** `Table` / `Timeline`, a two-item segmented control. `Timeline` is disabled with a tooltip
("Scope to one record to see its timeline") until the active filter set resolves to exactly one
`entity_type` + `entity_id` pair; arriving via a module's own "View full audit trail" link pre-sets
`?view=timeline` alongside the entity scope, so a user coming from a Vendor's own audit tab lands directly
on the timeline rather than the flat table.

**Main grid (Table view).** The audit-event DataTable itself: Time, Actor, Action, Entity, Reason, plus
lower-priority columns (Category, IP address, Device) visible from `xl:` up, each row expandable inline to
the Diff Viewer. This is the region with the most engineering weight and is specified in full in `#
Components Used`, `# Data & State`, and `# Responsive Behavior`.

**Main grid (Timeline view).** `AuditEntityTimeline` replaces the table entirely when active: a vertical,
chronological (oldest-first, matching a life story's natural reading order) sequence of nodes, each one
audit event, each expandable to the identical Diff Viewer used in Table view — same data, same component,
different shell, per `# Interactions & Flows`.

**AI panel.** A collapsible companion region for the Auditor agent's on-demand narrative explanation and
any open Fraud Detection/system-integrity flags touching the currently visible filter scope — an overlay
`Sheet` below `3xl:`, a persistent 360px rail at `3xl:` and above, the identical threshold
`RESPONSIVE_DESIGN.md` and `GENERAL_LEDGER.md` both use for their own AI Rail regions.

**Sticky footer.** The approximate total-matching-filters caption stays pinned to the bottom of the
scrollable region at every tier from `md:` up, labeled explicitly "approximate" per `# Performance` — it is
never presented as an exact count, since this table's `total` is, by design, always `null`.

# Components Used

| Region | Component(s) | Notes |
|---|---|---|
| Breadcrumb + actions | `Breadcrumb`, `Button`, `DropdownMenu`, `Can` | `Can permission="audit.chain.verify"` / `Can permission="audit.export"` wrap their respective triggers; "Ask Auditor Agent" needs no extra gate beyond the page's own `audit.read` |
| Retention banner | `AuditRetentionBanner` (`components/audit/audit-retention-banner.tsx`, screen-specific) | Reads `GET /audit-logs/retention-status`; renders a `Badge tone="warning"` "Legal hold active" pill when applicable |
| Actor filter | `AuditActorFilter` (`components/audit/audit-actor-filter.tsx`) | A `Combobox` merging three sources in one picker: human users (`GET /api/v1/users?filter[company_id]…`, reused from the platform's existing user picker), the fixed 15-agent AI roster (`docs/frontend/ICONOGRAPHY.md`'s AI Agent Roster table, rendered with each agent's own domain icon), and a fixed "System / Scheduler" entry for `actor_service` values with no human owner |
| Module/Category filter | `Select` (multi) | Maps to `filter[category][in]` (`data_mutation`\|`auth`\|`permission`\|`ai_action`\|`system`) crossed with a module facet derived from `entity_type`'s owning module, for the common "show me everything in Payroll" request |
| Entity filter | `AuditEntityFilter` (`components/audit/audit-entity-filter.tsx`) | Two-step: pick an `entity_type` from the fixed, published list in `docs/database/DATABASE_AUDIT_LOGS.md → What Must Be Audited` (accounts, journal_entries, invoices, customers, vendors, bank_accounts, employees, payroll_runs, tax_returns, roles, …), then optionally a specific record via that module's own record picker (e.g. `AccountPicker`, a customer/vendor combobox) — never a free-typed numeric id alone |
| Action type filter | `Select` (multi), grouped by category | Options are the closed-enough, dot-namespaced `action` values already used across the platform (`journal_entry.posted`, `invoice.voided`, `user.login_failed`, `role.permission.granted`, `ai_action.journal_entry.drafted`, …), grouped under their `category` header for scannability |
| Date range | `PeriodPicker` | Reused verbatim from `COMPONENT_LIBRARY.md`; defaults to `{ mode: 'relative', range: 'last_13_months' }`, matching the retention banner's stated hot-tier window |
| Secondary filters | `Sheet`, `Input` (IP address, reason contains), `Switch` (AI-assisted only) | Merges into the same `filter[...]` object the primary controls populate |
| View toggle | `Tabs` (underline-indicator variant, `?view=` synced) | Disabled `Timeline` trigger carries `aria-disabled` + a `Tooltip` explaining why, never a silently-missing tab |
| Main grid (Table, `md:`+) | `AuditLogTable` (`components/audit/audit-log-table.tsx`, screen-specific) | Wraps `DataTable`'s cursor mode; every row is expandable via `aria-expanded`/`aria-controls` (`# Accessibility`) rather than opening a `Sheet`, since a diff is usually small — the one exception (`# Edge Cases`) opens a `Sheet` |
| Main grid (below `md:`) | `AuditLogEntryCard` (`components/audit/audit-log-entry-card.tsx`) | Header = Actor + relative time; body = Action + Entity + Reason; footer = "View diff" opening a full-screen `Sheet` rather than an inline expand, per `# Responsive Behavior` |
| Actor cell | `AuditActorCell` (`components/audit/audit-actor-cell.tsx`) | Renders one of: human (`Avatar` + bilingual name + role `Badge`), AI agent (`AgentAvatar` + agent display name + a small `Sparkles` "AI" tag), system/scheduler (a muted chip), or "Unauthenticated" (`# Edge Cases`); renders both `actor_user_id` and `acting_as_user_id` together, never collapsed to one, when impersonation is present |
| Action cell | `AuditActionCell` (`components/audit/audit-action-cell.tsx`) | Translates the raw `action` string via a bilingual `AUDIT_ACTION_LABELS` lookup (falling back to a formatted raw string for an action not yet in the table, never a blank cell), plus a small `category` `Badge` |
| Entity cell | `AuditEntityLink` (`components/audit/audit-entity-link.tsx`) | Resolves `entity_type`/`entity_id` to a human label and a real route via a fixed mapping extending `GENERAL_LEDGER.md`'s own `source_type → route` table to the full audited-entity set; renders plain, non-linked text for an entity type outside the map or a dangling `entity_id` (`# Edge Cases`), never a broken link |
| Diff viewer | `AuditDiffViewer` (`components/audit/audit-diff-viewer.tsx`, screen-specific) | Renders `old_values`/`new_values` as a two-column Before/After comparison, `changed_fields` highlighted, masked (`MASKED:…`) values rendered as a locked, tooltipped chip, oversized diffs (`new_values.ref` pointing at an attachment) fetched and rendered from the polymorphic `attachments` table instead of the inline JSON |
| Entity timeline | `AuditEntityTimeline` (`components/audit/audit-entity-timeline.tsx`, screen-specific) | Vertical timeline, `role="list"`; each node expands to the identical `AuditDiffViewer` |
| Anomaly indicator | `ConfidenceBadge` (`showLabel={false}`, `size="sm"`), `Tooltip` | Rendered on rows with an open Fraud Detection/Auditor flag or a `system.audit_gap_detected`/`system.audit_masking_gap` finding |
| Retention panel | `AuditRetentionPanel` (`components/audit/audit-retention-panel.tsx`, `Sheet`-based) | Restore-request form + status, legal-hold indicator, link to `GET /api/v1/compliance/deletion-report` |
| Export | `AuditExportDialog` (`components/audit/audit-export-dialog.tsx`), `DropdownMenu`, `useApiToast` | Format picker + scope confirmation; async job pattern for large ranges (`# Interactions & Flows`) |
| AI panel | `AuditExplanationCard` (`components/audit/audit-explanation-card.tsx`), `ConfidenceBadge`, `Tooltip` | Deliberately not the full `AIProposalPanel` — nothing here is accepted or sent for approval, mirroring `GENERAL_LEDGER.md`'s identical reasoning for its own read-only Explain card |
| Loading | `Skeleton`, shaped to `AuditLogTable`'s exact row/column geometry | Never a generic spinner |
| Empty / error | `EmptyState`, `ErrorState` | Distinct empty states — see `# States` |

`AuditLogTable`'s column-definition shape extends the platform's `ResponsiveColumnDef<TData>` pattern with
no new mechanism, following `GENERAL_LEDGER.md`'s `LedgerTable` precedent exactly:

```tsx
// components/audit/audit-log-table.tsx
interface AuditLogTableProps {
  events: AuditLogEntry[];
  expandedRowIds: Set<string>;
  onToggleRow: (id: string) => void;
  flaggedRowIds: Set<string>;          // from GET /audit-logs/ai/flags
  isFetchingNextPage: boolean;
}

interface AuditLogEntry {
  id: number;
  companyId: number;
  category: 'data_mutation' | 'auth' | 'permission' | 'ai_action' | 'system';
  action: string;                      // dot-namespaced, e.g. "invoice.voided"
  entityType: string | null;
  entityId: number | null;
  actorUserId: number | null;
  actorUserName: string | null;        // denormalized for display; null when actorService is set
  actorService: string | null;         // e.g. "ai:reporting-agent", "scheduler:period-close"
  actingAsUserId: number | null;
  actingAsUserName: string | null;
  oldValues: Record<string, unknown> | null;
  newValues: Record<string, unknown> | null;
  changedFields: string[] | null;
  reason: string | null;
  ipAddress: string | null;
  userAgent: string | null;
  deviceId: string | null;
  createdAt: string;                   // ISO 8601
}
```

# Data & State

## Endpoints

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/api/v1/audit-logs` | `audit.read` | The primary, cursor-paginated event feed — the exact foundation endpoint `docs/accounting/WAREHOUSES.md` already names for this pattern. Powers both `AuditLogTable` (unscoped or multi-entity filters) and `AuditEntityTimeline` (scoped to one `entity_type`+`entity_id`) |
| GET | `/api/v1/audit-logs/{id}` | `audit.read` | Single-event detail, for deep-linking one row from a notification or an external reference — used to seed the inline expansion when arriving with a specific event already in mind |
| GET | `/api/v1/audit-logs/retention-status` | `audit.read` | Feeds `AuditRetentionBanner` — hot-tier window in months, approximate total-in-window, active legal-hold flag + case reference if any |
| POST | `/api/v1/audit-logs/ai/explain` | `audit.read` | Auditor agent's narrative explanation of the current filter scope — mirrors `docs/accounting/GENERAL_LEDGER.md → AI Responsibilities → Explain Ledger Activity`'s exact shape, applied here to the audit trail instead of ledger activity |
| GET | `/api/v1/audit-logs/ai/flags` | `audit.read` | Open Fraud Detection / Auditor agent flags, and any `system.audit_gap_detected`/`system.audit_masking_gap` findings, scoped to the visible filter set |
| POST | `/api/v1/audit-logs/{id}/anomaly/dismiss` | `audit.anomaly.review` | Dismisses/annotates a flagged row with a required reason; itself writes exactly one new audit row (never a change to the flagged row) |
| POST | `/api/v1/audit-logs/export` | `audit.export` | Generates the CSV/XLSX/PDF/signed export for the current filtered scope; `202 Accepted` + job id for large ranges (`# Interactions & Flows`) |
| GET | `/api/v1/audit-logs/export/{jobId}` | `audit.export` | Export job status/download link, mirroring the platform's `report_runs` async-job pattern |
| POST | `/api/v1/database/audit/verify-chain` | `audit.chain.verify` | Triggers an on-demand run of the nightly `audit:verify-chain` job (`docs/database/DATABASE_AUDIT_LOGS.md → Immutability & Tamper-Evidence`) for this company; `202 Accepted` + job id |
| GET | `/api/v1/database/audit/verify-chain/latest` | `audit.read` | The most recent verification result (nightly or on-demand) without triggering a new run — cheap, always available |
| POST | `/api/v1/database/restore-requests` | `database.archive.restore.request` | Reused verbatim from `docs/database/DATABASE_ARCHIVING.md → Restoring → API`; the Retention panel's "Request older records" action |
| GET | `/api/v1/database/restore-requests/{id}` | `database.archive.restore.request` | Poll a pending/completed restore request |
| GET | `/api/v1/compliance/deletion-report` | `audit.read` | Reused verbatim from `docs/database/DATABASE_SOFT_DELETE.md`; linked from the Retention panel as "Related: deletion & purge history," never re-implemented here |

**Filters use the platform's bracketed filter grammar**, per `docs/api/API_FILTERING_SORTING.md`, applied to
an `AuditLog` model whose filter/sort/search whitelists follow the identical declared-const pattern every
other resource uses:

```php
// app/Models/AuditLog.php
final class AuditLog extends Model
{
    public const FILTERABLE_FIELDS = [
        'category'        => ['type' => 'enum',    'operators' => ['eq', 'in', 'not_in'],
                               'allowed' => ['data_mutation', 'auth', 'permission', 'ai_action', 'system']],
        'action'          => ['type' => 'string',   'operators' => ['eq', 'in', 'like']],
        'entity_type'     => ['type' => 'string',   'operators' => ['eq', 'in']],
        'entity_id'       => ['type' => 'bigint',   'operators' => ['eq']],
        'actor_user_id'   => ['type' => 'bigint',   'operators' => ['eq', 'in', 'is_null']],
        'actor_service'   => ['type' => 'string',   'operators' => ['eq', 'in', 'like', 'is_null']],
        'ip_address'      => ['type' => 'string',   'operators' => ['eq']],
        'created_at'      => ['type' => 'datetime', 'operators' => ['gte', 'lte', 'between']],
        // JSONB convenience filter — compiles to (new_values->>'ai_assisted')::boolean = ?,
        // the exact predicate docs/database/DATABASE_AUDIT_LOGS.md's own worked query uses.
        'ai_assisted'     => ['type' => 'boolean',  'operators' => ['eq'],
                               'column' => "(new_values->>'ai_assisted')::boolean"],
    ];

    public const SORTABLE_FIELDS = ['created_at'];

    // Deliberately narrow: reason/action only. See # Performance for why old_values/new_values
    // content is NOT part of the default full-text search.
    public const SEARCHABLE_FIELDS = ['reason', 'action'];
}
```

`company_id` is never accepted from `filter[...]` — applied unconditionally from the authenticated context,
per `API_FILTERING_SORTING.md`'s enforcement rule that a client-supplied `filter[company_id]` is a
tenant-isolation probe and is rejected with `403`, not silently ignored. `entity_id` requires `entity_type`
in the same request (a bare `entity_id` with no type is rejected `422`, since ids are not unique across
tables); the `AuditEntityFilter` component enforces this in the UI before a request is ever sent.

**Sort** is fixed to `-created_at,-id` (recent-first) for Table view and `created_at,id` (chronological) for
Timeline view — there is no user-visible sort toggle on this screen, unlike General Ledger's recent/
statement-order toggle, because an audit trail has exactly one meaningful reading order per view and
offering a third would only invite a reader to misinterpret a partial, arbitrarily-ordered scroll as
complete. Switching between Table and Timeline always resets `cursor` to `null`, per
`docs/api/API_PAGINATION.md → Sorting Interaction`'s rule that a cursor is signed against the exact `sort`
string that produced it and is never replayed against a different one.

## Query keys, hooks

```ts
// lib/api/query-keys.ts — extends the exact factory FRONTEND_ARCHITECTURE.md already declares
export const auditLogKeys = {
  all: ["audit-logs"] as const,
  infinite: (filters: AuditLogFilters, view: "table" | "timeline") =>
    [...auditLogKeys.all, "infinite", view, filters] as const,
  retentionStatus: () => [...auditLogKeys.all, "retention-status"] as const,
  explanation: (filters: AuditLogFilters) => [...auditLogKeys.all, "explanation", filters] as const,
  flags: (filters: AuditLogFilters) => [...auditLogKeys.all, "flags", filters] as const,
  chainVerification: () => [...auditLogKeys.all, "chain-verification"] as const,
};
```

```ts
// lib/api/hooks/use-audit-logs.ts
export function useAuditLogs(filters: AuditLogFilters, view: "table" | "timeline") {
  return useInfiniteQuery({
    queryKey: auditLogKeys.infinite(filters, view),
    queryFn: ({ pageParam }) =>
      api.get<AuditLogPage>("/audit-logs", {
        params: {
          ...toFilterParams(filters),                 // filter[category][in]=…&filter[entity_type][eq]=…
          sort: view === "timeline" ? "created_at,id" : "-created_at,-id",
          cursor: pageParam,
          per_page: 50,                                // API_ARCHITECTURE.md's default for Audit/history
        },
      }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.pagination.next_cursor,
    staleTime: 30_000,   // append-only historical fact ledger; realtime correction handles new rows
  });
}

export function useAuditRetentionStatus() {
  return useQuery({
    queryKey: auditLogKeys.retentionStatus(),
    queryFn: () => api.get<AuditRetentionStatus>("/audit-logs/retention-status"),
    staleTime: 5 * 60_000,   // changes only when a legal hold or retention policy changes, rarely
  });
}

export function useExplainAuditTrail() {
  return useMutation({
    mutationFn: (filters: AuditLogFilters) => api.post<AiExplanation>("/audit-logs/ai/explain", filters),
  });
}

export function useAuditLogAiFlags(filters: AuditLogFilters) {
  return useQuery({
    queryKey: auditLogKeys.flags(filters),
    queryFn: () => api.get<AiFlag[]>("/audit-logs/ai/flags", { params: filters }),
    staleTime: 10_000,   // AI feeds data class, per FRONTEND_ARCHITECTURE.md's cache-tuning table
    refetchOnWindowFocus: true,
  });
}

export function useVerifyAuditChain() {
  return useMutation({
    mutationFn: () => api.post<{ jobId: string }>("/database/audit/verify-chain"),
  });
}

export function useRequestArchiveRestore() {
  return useMutation({
    mutationFn: (body: RestoreRequestInput) => api.post<RestoreRequest>("/database/restore-requests", body),
  });
}
```

`filters.entityType`/`filters.entityId` gate the Timeline view's `enabled` condition exactly as
`GENERAL_LEDGER.md`'s `filters.accountIds.length > 0` gates its own scoped queries — Timeline never fires
against an unscoped or multi-entity filter set; Table view has no such gate and is the screen's true default
(unlike General Ledger, which requires an account before firing at all — an audit trail's own default,
company-wide-but-time-bounded view is itself a meaningful, commonly-requested answer, "what happened
recently," so Table view queries immediately on load using the default `last_13_months` range).

## Realtime

`private-company.{company_id}.audit-logs` follows the platform's fixed `private-company.{id}.<feature>`
channel convention. An `audit_log.recorded` event never splices a new row into a list a user is actively
scrolling — `docs/frontend/ACCESSIBILITY.md`'s own live-regions rule, stated about `docs/frontend/
GENERAL_LEDGER.md`'s identical table, applies verbatim here: the correct behavior is a polite, dismissible
banner, not a live insertion.

```ts
// lib/api/hooks/use-audit-log-realtime.ts
"use client";
import { useEffect, useState } from "react";
import { echo } from "@/lib/realtime/echo";

export function useAuditLogRealtime(companyId: string) {
  const [hasNewActivity, setHasNewActivity] = useState(false);

  useEffect(() => {
    const channelName = `company.${companyId}.audit-logs`;
    const channel = echo.private(channelName);
    channel.listen(".audit_log.recorded", () => setHasNewActivity(true));
    channel.listen(".audit_log.chain_gap_detected", () => setHasNewActivity(true)); // see # Edge Cases
    return () => { echo.leave(channelName); };
  }, [companyId]);

  return { hasNewActivity, dismiss: () => setHasNewActivity(false) };
}
```

A `chain_gap_detected` event (the reconciliation job in `docs/database/DATABASE_AUDIT_LOGS.md → Trigger-Based
Capture` surfacing `system.audit_gap_detected`) renders as a distinctly-toned, non-dismissible banner rather
than the routine "new activity" banner — see `# Edge Cases`.

## AI agents feeding this screen

| Agent | Role on this screen | Autonomy |
|---|---|---|
| Auditor Agent | "Explain this trail" narrative (`# AI Integration`); domain icon `History` per `docs/frontend/ICONOGRAPHY.md`, sharing this screen's own module icon | Suggest-only, read/explain, no write |
| Fraud Detection Agent | Inline anomaly flags on individual rows | `requires_approval` for the underlying flagged business action elsewhere; on this screen, flags are suggest-only — dismissing one is a human action gated by `audit.anomaly.review` |
| Compliance Agent | Consumes `audit_logs` via its own `get_audit_logs` tool for segregation-of-duties and change-history evidence (`docs/ai/agents/COMPLIANCE_AGENT.md`) | Does not render on this screen; its own findings surface in the Reports module's `audit`-category cards and in Notifications |

The Reporting Agent, CFO Agent, and every other agent named against `audit_logs` elsewhere in the platform
feed the Dashboard, scheduled digests, or the Reports module rather than this screen — a raw event ledger is
not the natural home for a forward-looking synthesis, and this document does not manufacture a UI slot for
them here, mirroring `GENERAL_LEDGER.md`'s identical restraint.

# Interactions & Flows

**Default load (no filters).** The screen does not require a scope before firing, unlike General Ledger —
it queries immediately with `range=last_13_months`, recent-first, Table view. This is the screen's true
initial state: "what has happened across the company recently," a meaningful answer on its own.

**Narrowing to an actor.** `AuditActorFilter` accepts a mix of human users, AI agents, and "System /
Scheduler" in one multi-select; selecting a human sends `filter[actor_user_id][in]=…`, selecting an AI
agent sends `filter[actor_service][eq]=ai:<agent_slug>` (or `[in]` for several), and "System / Scheduler"
sends `filter[actor_service][like]=scheduler:%`. Selecting both a human and "AI agents" in the same filter
is legal and common (a Finance Manager reviewing "everything Dana did or that AI did on her behalf") — the
two clauses combine with `OR` at the query layer, not `AND`, since they describe alternative actors, not a
conjunction of conditions on one row.

**Scoping to an entity.** `AuditEntityFilter`'s two-step picker sets `filter[entity_type][eq]` and,
once a specific record is chosen, `filter[entity_id][eq]`. The moment both are present, the View toggle's
`Timeline` option enables; the screen does not auto-switch to it (a user filtering to "everything on
Invoices this month," a whole `entity_type` with no specific `entity_id`, stays productively in Table view,
since Timeline only makes sense for one record's own life story, not a whole table's).

**Arriving from a module's embedded audit tab.** `/audit-log?entity_type=vendors&entity_id=4821&view=timeline`
lands directly on the Timeline for that vendor, pre-scoped, with the breadcrumb reading `Settings / Audit Log
/ Al Zahra Trading Co.` — the one case this screen grows a second breadcrumb segment, exactly mirroring how
`GENERAL_LEDGER.md`'s own breadcrumb never grows past its base segment except when a specific scope changes
what the screen fundamentally represents.

**Arriving from an `audit`-category Standard Report.** The report's own scope (date range, category, module)
is translated into this screen's query string by a small, deterministic mapping table
(`reportFiltersToAuditLogFilters()`) rather than a shared, coupled filter object — the two screens' filter
grammars evolve independently, per `# Purpose`'s stated boundary between them.

**Expanding a row.** Clicking a row (or its chevron) toggles `aria-expanded` and reveals `AuditDiffViewer`
inline, beneath that row, showing Before/After for every key in `changed_fields`, the `reason`, `ip_address`,
and a human-readable device string derived from `user_agent`. Masked fields (`old_values.iban ===
"MASKED:9f2b7e1c4a12"`) render as a locked chip reading "Value hidden" with a `Tooltip`: "This field is
masked for privacy. The real value is visible only through the [Vendor/Employee] record's own access
control, never here" — directly reflecting `docs/database/DATABASE_AUDIT_LOGS.md → Privacy`'s masking
design. Multiple rows can be expanded simultaneously; expanding a new row never collapses another.

**Timeline node expansion.** Identical mechanics, rendered as a node's own disclosure rather than a table
row's — `AuditEntityTimeline` and `AuditLogTable` share the same `AuditDiffViewer` instance and the same
expand/collapse state shape, differing only in the surrounding chrome (a connected vertical line vs. table
rows).

**Ask Auditor Agent.** Clicking the header action calls `useExplainAuditTrail` with the current filter
scope and renders `AuditExplanationCard` inline above the grid (`# AI Integration`).

**Dismissing an anomaly.** Clicking a flagged row's `ConfidenceBadge` opens a small `Dialog` requiring a
typed reason (mirroring `AIProposalPanel`'s own dismiss-reason pattern in `COMPONENT_LIBRARY.md`); confirming
calls `POST /audit-logs/{id}/anomaly/dismiss`, which writes exactly one new audit row
(`action: 'audit_anomaly.dismissed'`) and never touches the flagged row itself. External Auditor never sees
this action at all (`audit.anomaly.review` is not in that role's grant, per `# Route & Access`), matching
`docs/frontend/REPORTS.md`'s own stated External Auditor limitation for the adjacent `reports.audit.read`
permission — "delegated (read-only, cannot dismiss anomalies)."

**Verify chain.** "Run now" calls `useVerifyAuditChain`, which returns `202` + a job id; a toast confirms
the run has started, and the result — pass, or a named list of rows whose stored hash no longer matches
its recomputed value — surfaces via Notification and via "View latest result" on completion, per
`docs/database/DATABASE_AUDIT_LOGS.md → Immutability & Tamper-Evidence`'s own nightly job, exposed here
on demand rather than reimplemented. A failing result renders as the severe, non-dismissible banner
described in `# Edge Cases`.

**Retention & Legal Holds panel.** "Request access" on the retention banner opens `AuditRetentionPanel`
(`Sheet`): the current hot/warm window, any active legal hold with its case reference (per
`docs/database/DATABASE_ARCHIVING.md → Legal holds`), a form pre-filled with the active filter scope that
calls `POST /database/restore-requests` with `restore_type: "partial_extract"` (the default and
overwhelmingly common path per that document), and a status list of the requester's own past restore
requests (`StatusPill domain="restore_request"`, extending `COMPONENT_LIBRARY.md`'s `StatusPill` with one
new domain exactly as that component's own doc invites — "invoice, bill, payroll_run… follow the identical
shape, each owned by its module's screen spec and imported here rather than redefined"). A completed
request's "View extract" link opens the resulting `restore_staging_*` rows read-only, scoped to the
requesting company only, exactly as `docs/database/DATABASE_ARCHIVING.md → Example 3` describes. The panel
also links out to `GET /compliance/deletion-report` as "Related: deletion & purge history," never
re-rendering that report's own table here.

**Export.** The `DropdownMenu` offers CSV, XLSX, PDF, and "Signed export" (bundles a CSV/PDF of the matching
rows with the `audit:verify-chain` report for the same window, so the export is independently verifiable
without trusting the application layer — per `docs/database/DATABASE_AUDIT_LOGS.md → Compliance`'s stated
goal that the hash chain "is itself an exportable artifact an external auditor can request and independently
recompute… without trusting QAYD's application layer at all"). A range whose estimated row count exceeds the
synchronous export threshold returns `202 Accepted` + a job id, exactly mirroring `GENERAL_LEDGER.md`'s own
export edge case; the Export menu's "Recent exports" list re-surfaces the finished file via Notification
rather than holding a spinner open on the button. An External Auditor's "Request export" creates the
identical job in `pending_approval`, requiring a Finance Manager or CFO's sign-off before it runs (`# Route
& Access`).

# AI Integration

This screen's AI surface is deliberately narrow: an on-demand narrative explanation, passive anomaly flags,
and — uniquely among the platform's read-only screens — a human-gated dismiss action for flags that concern
the audit trail's own integrity. There is still no accept/reject decision over the *data* itself anywhere on
this screen, because there is nothing here for a human to approve — every row is already an immutable,
hash-chained fact; the AI's job is to help a human understand and trust it faster, never to change it.

**Explain this trail.** `AuditExplanationCard` renders the Auditor agent's response from `POST
/audit-logs/ai/explain`, whose structured shape mirrors `docs/accounting/GENERAL_LEDGER.md`'s own
`explain-activity` contract applied to an event trail rather than a ledger: `{ summary,
contributing_events: [{ audit_log_id, action, weight }], confidence }`. The card renders `summary` as plain
narrative text, a `ConfidenceBadge`, and an expandable list of `contributing_events`, each a one-click jump
to that row's own inline expansion. When the requested scope spans more history than the agent's own
context window can summarize confidently, it says so explicitly in `summary` — e.g., "…this summary covers
the 200 most recent matching events; narrow your date range for full coverage" — rather than silently
truncating and presenting a partial picture as complete.

**Anomaly and integrity flags.** `GET /audit-logs/ai/flags` returns three distinct kinds of finding, each
rendered identically as a row-level `ConfidenceBadge` with its `reasoning` in a `Tooltip`, but each meaning
something different underneath: (1) a Fraud Detection flag on the *business event* the audit row describes
(e.g., a duplicate-invoice pattern flagged on the `invoice.created` row itself); (2) an Auditor agent
observation (e.g., a segregation-of-duties concern — the same person created and approved a payment); and
(3) a `system.audit_gap_detected`/`system.audit_masking_gap` finding about the *audit trail's own*
completeness, per `docs/database/DATABASE_AUDIT_LOGS.md`'s reconciliation job and masking-registry CI check.
The third kind never has a "dismiss" action available to anyone but Owner/CFO/Auditor holding
`audit.chain.verify`-adjacent trust, since dismissing a finding about the trail's own integrity is a
materially different, higher-stakes action than dismissing a business-level fraud flag — `#
Interactions & Flows`'s dismiss dialog therefore requires a longer, more specific typed reason (≥20
characters) for category-3 findings than for categories 1–2 (≥10 characters), the same graduated-friction
pattern `docs/accounting/VENDORS.md` uses for blacklist (≥20 chars) versus deactivate (no minimum) reasons.

**Unavailable AI.** If the explain or flags calls fail, time out, or AI is disabled for the company, the
header's "Ask Auditor Agent" action disables with a `Tooltip` ("AI explanation unavailable right now") and
the per-row `ConfidenceBadge`s simply do not render — every other capability (reading, filtering, the
diff viewer, the timeline, export, retention requests, chain verification) remains fully available. AI here
is strictly additive, never a gate on anything a human — least of all an auditor — needs to do their job.

# States

| State | Trigger | Rendering |
|---|---|---|
| Initial load | First paint, default `last_13_months` scope | `Skeleton` shaped to the retention banner + filter bar + `AuditLogTable`'s row/column geometry; never a generic spinner |
| Loaded, has events | Normal case | Retention banner, filter bar, table (or timeline) render; sticky approximate-total footer visible |
| Loaded, zero events in scope | A legitimately quiet window/filter combination (e.g. a brand-new company, or a narrow entity with no history yet) | `EmptyState`: "No audit events match this scope" — distinct from a filtered-to-zero result |
| Filtered to zero | Secondary filters (e.g. IP address) legitimately exclude every row | `EmptyState` variant: "No events match your filters" with a "Clear filters" action, never conflated with genuine quiet history |
| Timeline unavailable | Filter set resolves to zero or more than one entity | `Timeline` toggle stays `aria-disabled` with an explanatory `Tooltip`; Table view remains fully usable |
| Entity has a dangling reference | The underlying business row was later hard-deleted under a legal erasure request | Rows still render fully (`# Edge Cases`); `AuditEntityLink` degrades to plain, non-linked text with a small note |
| Fetching next page | Scrolling near the end of the loaded window | Trailing row-shaped `Skeleton` strip; `AuditLogTable`'s `isFetchingNextPage` prop |
| Realtime new activity available | An `audit_log.recorded` event matches the active filters | Dismissible, non-disruptive banner: "New events recorded — Refresh"; existing rows never mutate in place |
| Chain verification failing | `audit:verify-chain` (nightly or on-demand) finds a mismatched row | Persistent, **non-dismissible**, distinctly-toned banner — see `# Edge Cases` |
| Legal hold active | `retention-status` reports an active hold | Retention banner's "No active legal hold" line is replaced by "Legal hold active — case {ref}"; export/restore of held rows is blocked with an explicit message, never a generic permission error |
| Restore request pending / completed / expired | Retention panel | `StatusPill domain="restore_request"` reflects `queued`/`in_progress`/`completed`/`failed`/`expired`, matching `restore_requests.status` exactly |
| Export job running / ready / failed | Export menu → "Recent exports" | `StatusPill`-style progress row; a failed export surfaces the reason (e.g. scope too large, retry with a narrower range) rather than a silent disappearance |
| Error | `403`/`404`/`5xx`/network failure | `ErrorState` with a retry action; a `403` discovered mid-session (permission revoked while the tab was open) collapses only the affected control, never the whole page |

# Responsive Behavior

This screen follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` exactly — an audit trail is
squarely one of the "genuinely tabular... read, sort, filter, paginate, click a row" surfaces that document
already treats as the default case, alongside General Ledger and Trial Balance; this section states the
outcome for this specific screen, not a new mechanism.

**Mobile (`base`–`sm`).** The header collapses to a back-chevron + title; the five primary filter controls
stack into a horizontally-scrollable chip row; secondary filters collapse behind a single "Filters (n)"
trigger opening a `Sheet`. The canvas becomes a stack of `AuditLogEntryCard`s — Actor, relative time, Action,
Entity, and Reason (`priority: 1–2` fields), each ending in a "View diff" button that opens the full-screen
`Sheet` variant of `AuditDiffViewer` rather than an inline expand — a table row has room to expand in place;
a card, on a small screen, does not, so the mobile diff view is a dedicated overlay instead of a shape this
document invents twice. The retention banner and a collapsed realtime banner stay pinned above the card
list. Timeline view on mobile keeps its vertical-node shape (it was never a wide table to begin with) but
narrows its connecting-line indent and moves the Actor/Action line onto its own row per node.

**Tablet and up (`md:`+).** The canvas is a real `<table>` with the Time column pinned via `sticky start-0`
(the logical start — see `# RTL & Localization`) and Category/IP address/Device (`priority: 4`) columns
hiding below `xl:` and reappearing at `xl:`+; Actor/Action/Entity/Reason (`priority: 1–2`) are always
visible. Row expansion inserts a full-width diff panel beneath the expanded row, spanning every column,
rather than squeezing into one cell.

**Touch and virtualization.** Row/card action targets meet the platform's 44×44px touch minimum regardless
of desktop (40px) vs. mobile-card (56px) density. Once the loaded window exceeds roughly 200 rows —
routine for this table within minutes of company-wide activity — `AuditLogTable` mounts
`@tanstack/react-virtual`, exactly the treatment `FRONTEND_ARCHITECTURE.md → Virtualization for large
tables` already names `audit-logs` for by name, with `estimateSize` reading the active breakpoint tier and
accounting for whichever rows are currently expanded (an expanded row's diff panel has a materially taller
estimated height than a collapsed one, and the virtualizer's size cache is invalidated per-row on
expand/collapse rather than assuming a uniform row height).

**AI panel.** Overlay `Sheet` below `3xl:`, persistent 360px rail at `3xl:` and above — the identical
threshold every sibling screen with an AI Rail region uses.

# RTL & Localization

Every string on this screen ships as an EN/AR pair; the screen inherits `THEMING.md`'s RTL-as-a-theming-
dimension and `LAYOUT_SYSTEM.md`'s RTL layout mirroring with no per-component RTL branch, plus the
module-specific applications below.

- **Bilingual action labels.** `AUDIT_ACTION_LABELS` maps each dot-namespaced `action` string to an EN/AR
  pair — "Invoice voided" / "تم إلغاء الفاتورة" — falling back to a formatted raw string (`invoice.voided`
  → "Invoice · Voided") for any action not yet in the table, never a blank or untranslated technical string
  shown to a non-technical Auditor.
- **Actor names respect the same bilingual-fallback rule every other screen uses**: a human actor's
  `name_ar` renders under an Arabic session, falling back to `name_en` only when the Arabic name is
  genuinely absent, never the reverse; AI agent names use the platform's own fixed EN/AR agent-name pairs
  (`docs/frontend/ICONOGRAPHY.md`'s AI Agent Roster), never a re-translated ad hoc label.
- **Before/After in the Diff Viewer mirrors with reading direction — deliberately unlike Debit/Credit.**
  `GENERAL_LEDGER.md` pins Debit-before-Credit physically LTR in every locale because that column order is
  a universal accounting convention independent of interface language. A Before→After diff is a different
  kind of thing: it is a narrative sequence, read the same way a sentence is read, so `AuditDiffViewer`
  places "Before" at the reading-start edge and "After" at the reading-end edge in both directions (`start`/
  `end` logical columns, not `left`/`right`) — an Arabic-reading Auditor reads Before on the right and After
  on the left, exactly as they would read two consecutive paragraphs, and this document treats that as the
  correct mirroring choice rather than a Debit/Credit-style fixed exception.
- **Structured values inside a diff stay LTR-isolated.** Account codes, IBANs, entry numbers, IP addresses,
  and JSON keys embedded inside otherwise-Arabic diff rows use the shared bidi-isolation wrapper (`dir="ltr"`
  + `unicode-bidi: isolate`) `FRONTEND_ARCHITECTURE.md` already specifies for exactly this class of value —
  a diff line reading "`status: "posted"` ← `"draft"`" never visually reorders its punctuation or quotation
  marks inside an RTL sentence.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching every other
  financial/technical surface on the platform — timestamps, ids, IP addresses, and confidence percentages
  never switch to Eastern Arabic-Indic digits.
- **Masked-value chips and their tooltip explanation are fully translated**, including the Arabic framing of
  *why* a value is hidden, never left in English inside an otherwise-Arabic diff.
- **AI explanation text is generated in the viewer's session locale** and rendered as plain translated
  prose, never re-translated client-side; Arabic audit terminology is used precisely — سجل التدقيق (Audit
  Log), الجهة الفاعلة (Actor), الإجراء (Action), قبل (Before), بعد (After), السبب (Reason), سلسلة التجزئة
  (hash chain).
- **Directional chrome mirrors; content chrome does not.** The Retention panel's `Sheet` and the AI panel's
  overlay slide from the logical end edge (left in RTL); breadcrumb chevrons mirror; the timeline's own
  connecting line and node markers, and every status/anomaly glyph, do not mirror, per `ICONOGRAPHY.md`'s
  directional-icon table — a lock glyph on a masked field or a flag glyph on an anomaly encodes a meaning,
  not a reading direction.
- **The pinned Time column uses `sticky start-0`, never `sticky left-0`** — `RESPONSIVE_DESIGN.md` names
  this the single highest-risk line of code in its entire specification for exactly the reason it matters
  here: `left-0` looks correct in an English demo and silently pins the wrong physical edge the instant an
  Auditor opens this screen in Arabic.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a Tailwind palette utility; every surface, border, and status color resolves
through `DARK_MODE.md → Token Mapping`'s semantic tokens.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the retention banner, filter bar,
  and the table's own surface sit on `--surface-base`; an expanded row's diff panel sits on
  `--surface-sunken` (visually "beneath" the row it belongs to, communicating "supporting detail" rather
  than "new content"); the Retention panel's `Sheet` and the AI panel sit on `--surface-raised`/
  `--surface-overlay`. Dark mode communicates elevation by the surface getting lighter toward the viewer,
  not by shadow, so the sticky Time column and the diff panel's boundary both carry their paired
  `--border-subtle`/`--border-default` hairline in dark mode where light mode might lean on shadow alone.
- **Diff Before/After legitimately uses semantic status color — unlike Debit/Credit.**
  `docs/frontend/GENERAL_LEDGER.md → Dark Mode` states that "a debit is not bad and a credit is not good,"
  so `AmountCell` never carries a status color there. A diff is different: an *added* key (present only in
  `new_values`) renders on `--status-success-subtle` with `--status-success` text; a *removed* key (present
  only in `old_values`) renders on `--status-error-subtle` with `--status-error` text and a strikethrough on
  the old value; a *changed-in-place* key (present, differing, in both) renders on `--status-warning-subtle`.
  This is a deliberate, distinct rule from General Ledger's, made explicit here because status color is
  genuinely meaningful for "what kind of change was this" in a way it is not for "which side of a ledger
  entry is this."
- **Masked values never take a status color.** A locked/masked chip renders in neutral `--text-tertiary`
  on `--surface-sunken` regardless of whether the underlying field was added, removed, or changed — masking
  is a privacy fact, not a change-type fact, and conflating the two by tinting a masked chip red or green
  would misrepresent what happened to a reader who cannot see the actual value.
- **AI provenance uses the reserved AI accent, never a finance-semantic color.** `AuditExplanationCard`'s
  border accent, its `Sparkles` glyph, `AgentAvatar`'s fill, and every AI-agent `ConfidenceBadge` resolve
  through `--ai-accent`/`--ai-accent-subtle` — the same reserved hue `DARK_MODE.md` defines specifically so
  it is never reused for a success/warning/error meaning, keeping "this action was AI-originated" and "this
  change was an addition/removal" visually distinct even though both use color on the same row.
- **Contrast.** Every token pairing here — including the diff panel's three status-tinted backgrounds and
  the sticky Time column's dividing border — is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text)
  in both themes, per `DARK_MODE.md → Color & Contrast In Dark`.
- **Print/export independence.** A PDF or "Signed" export always renders in QAYD's fixed light/print palette
  regardless of the viewer's active theme, per `DARK_MODE.md → Exported PDFs always render light` — a trail
  handed to an external auditor is never expected to open inside a dark-mode-aware viewer, and the
  hash-chain verification report bundled into a Signed export is plain monochrome text by design, so it
  reads identically whether printed or viewed on any device.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, implemented here without deviation.

- **Plain accessible `<table>`, never the ARIA `grid` pattern.** `ACCESSIBILITY.md → Data Tables
  Accessibility` reserves the heavier `grid` pattern exclusively for genuinely spreadsheet-like editing
  surfaces (the Journal Entry line editor, Bank Reconciliation's matching grid). This screen's primary
  interaction is read, filter, sort (fixed), paginate, and expand a row to see more detail — squarely the
  plain-table case that document already names General Ledger, Trial Balance, and the Journal Entries list
  for; applying `grid` here would add keyboard-handling complexity this screen's read-only interaction never
  needs.
- **Row expansion uses `aria-expanded` on the trigger and `aria-controls` pointing at the revealed panel's
  `id`**, per `ACCESSIBILITY.md → Expandable rows and bulk selection`'s already-established pattern — the
  identical mechanism a Journal Entry row's inline line-expansion uses, applied here to a diff panel instead.
- **The horizontally-scrolling region is itself keyboard-operable**: `role="region"` + `aria-label` +
  `tabIndex={0}` on the wrapper around `AuditLogTable`'s lower-priority columns, so a keyboard user can
  focus the region and scroll it with arrow keys per the APG scrollable-region pattern without that focus
  stop trapping `Tab`.
- **Loading and streaming are announced, not silently swapped.** `<tbody aria-live="polite"
  aria-busy={isLoading}>` — a screen reader hears "Loading audit events…" and, on completion, the new row
  count, exactly the pattern `ACCESSIBILITY.md`'s own worked `<table>` example demonstrates; the same
  `aria-busy` toggling covers `isFetchingNextPage` during virtualized scroll-triggered loads.
- **Row-level accessible names are unique, always.** "View diff" (desktop expand toggle or mobile card
  button) carries `aria-label={t('a11y.viewDiff', { action: entry.actionLabel, entity: entry.entityLabel,
  time: entry.relativeTime })}` — "View diff for Invoice voided on INV-2026-3301, 2 hours ago" — never a bare
  "View diff," per `ACCESSIBILITY.md`'s `IconButton` lint rule for any icon-only control rendered inside a
  `.map()` over rows.
- **Masked values are announced meaningfully, not read as a raw token.** A screen reader encountering a
  masked chip hears "Value hidden — masked for privacy" (via `aria-label` on the chip), never the literal
  string `MASKED:9f2b7e1c4a12`, which would expose an internal representation with no meaning to the user
  and no security benefit.
- **The Timeline view uses real list semantics** — `role="list"` on the container, `role="listitem"` on each
  node — so a screen reader announces "List, 14 items" and each item's position, rather than relying on the
  connecting visual line (which itself carries no semantic information and is `aria-hidden`).
- **Realtime pushes never yank focus or silently splice rows.** A `audit_log.recorded` event affecting the
  active scope surfaces as a polite, dismissible banner, per `ACCESSIBILITY.md → Live regions`'s rule,
  stated about this exact class of screen, that a live insertion into a list a user is tabbing through is
  the wrong behavior.
- **Chain-verification failure is an assertive, not polite, live region** — the one exception to this
  screen's otherwise-polite announcement discipline, because a tamper-evidence failure is the single most
  severe finding this screen can ever surface and must interrupt a screen-reader user's current task the way
  a critical alert should, per `ACCESSIBILITY.md`'s severity-taxonomy governance for exactly this class of
  finding.
- **Every disabled control explains itself, and distinguishes its reason.** The disabled `Timeline` toggle
  ("Scope to one record to see its timeline"), the disabled "Ask Auditor Agent" (AI unavailable), and a
  permission-gated Export/Verify action each carry `aria-describedby` naming their own distinct reason —
  never collapsed into a generic "You can't do this," per `ACCESSIBILITY.md`'s RBAC-aware disabled control
  pattern.
- **Focus management on overlays.** The Retention panel `Sheet`, the mobile diff `Sheet`, and every `Dialog`
  (anomaly dismissal, restore request, export confirmation) move focus to their own heading on open and
  return it to the triggering control on close, per `ACCESSIBILITY.md → Focus Management`.

# Performance

- **Cursor pagination, not offset, by construction.** `GET /audit-logs` is cursor-only; this screen's scroll
  cost stays O(per_page) regardless of how many pages a long investigative session has already fetched,
  exactly the property `docs/api/API_PAGINATION.md → Large tables` describes for `ledger_entries` and names
  `audit_logs` alongside it for.
- **Virtualization past ~200 rows.** `AuditLogTable` and `AuditEntityTimeline` both mount
  `@tanstack/react-virtual` once the loaded row count crosses roughly 200, per
  `RESPONSIVE_DESIGN.md → Virtualization at scale` and `FRONTEND_ARCHITECTURE.md`'s explicit naming of
  `audit-logs` in its own virtualization section.
- **`total` is intentionally `null`; `total_hint` is a courtesy, not a control.** Per
  `API_PAGINATION.md → meta.pagination Response Shape`, which names `audit_logs` directly as one of the
  unbounded tables whose exact count is never computed on the request path, this screen's sticky footer
  displays a Redis-cached `total_hint` (5-minute TTL) explicitly labeled "approximate," and never uses it to
  compute a page count.
- **Default-scoped, never a truly unbounded query.** The screen's own default filter is `range=
  last_13_months` — the same hot-tier boundary `docs/database/DATABASE_AUDIT_LOGS.md → Partitioning &
  Retention` defines — so the common case never asks Postgres to plan across every partition the company
  has ever produced. A user is free to widen the range, but the retention banner's own framing ("Showing
  the last 13 months") makes the default scope visible rather than an invisible performance guardrail.
- **Partition pruning is mandatory, not incidental.** Every query this screen issues includes a
  `created_at` bound (even a generous one), so the planner prunes partitions per
  `docs/database/DATABASE_AUDIT_LOGS.md → Performance`'s own rule; the one query pattern allowed to scan
  multiple partitions is an unbounded `entity_id` lookup (Timeline view, "this record's entire history"),
  which is instead bounded by the dedicated `idx_audit_logs_entity` index that document defines.
- **Diff content is deliberately excluded from the default full-text search — a documented, honest
  trade-off, not an oversight.** `docs/database/DATABASE_AUDIT_LOGS.md → Performance` keeps the GIN index on
  `new_values` narrow (`jsonb_path_ops`, containment-only) specifically to bound its size on the platform's
  largest table, accepting that ad-hoc JSONB search is rarer and can be slower. This screen's default `q`
  search covers only `reason` and `action` (`SEARCHABLE_FIELDS` above); a separate, explicitly-labeled
  "Search inside changed data (may be slower)" advanced toggle exists for the genuinely rare case an Auditor
  needs it, sending a distinctly-named, rate-limited request rather than silently folding JSONB search into
  every keystroke of the primary search box.
- **Debounced filter inputs.** IP address and reason-contains inputs debounce 300ms before triggering a new
  query key, per `FRONTEND_ARCHITECTURE.md`'s platform-wide debounce rule, with `placeholderData:
  keepPreviousData` so the table never flashes empty between keystrokes.
- **SSR-seeded first paint.** A bookmarked or deep-linked filter state (e.g. arriving from a Vendor's audit
  tab) is resolved and prefetched server-side, so the first client-side `useInfiniteQuery` call for that
  same key resolves instantly rather than re-issuing a request the server already made.
- **Read-replica reads.** Every call this screen makes is an ordinary list/detail read and is served from a
  Postgres read replica per the platform's default read-routing, accepting the documented sub-100ms
  replica-lag trade-off rather than forcing a primary read for a screen that never itself writes financial
  data.
- **AI calls never block the table.** `useExplainAuditTrail` and `useAuditLogAiFlags` are independent
  queries/mutations that never gate `useAuditLogs`'s own fetch or render — a slow or failed AI call degrades
  only the explanation card and the anomaly badges, never the core reading experience an Auditor depends on.
- **Chain verification runs out-of-band.** `POST /database/audit/verify-chain` is a queued job, never a
  synchronous request this screen waits on — the button confirms the run has *started*, and the result
  arrives via Notification/realtime, so triggering a company-wide hash recomputation never freezes the page.

# Edge Cases

1. **A referenced entity was later hard-deleted under a legal erasure request.** Per
   `docs/database/DATABASE_AUDIT_LOGS.md`, `entity_id` is a plain `BIGINT`, not a foreign key, precisely so
   the audit row survives this case; `AuditEntityLink` degrades to plain, non-linked text with a small "This
   record no longer exists (erased under a legal basis)" note rather than a broken link or a `404`.
2. **A masked field.** Rendered as a locked chip with an explanatory `Tooltip`/`aria-label` (`#
   Interactions & Flows`, `# Accessibility`) — the raw value is never reconstructable from this screen,
   matching `docs/database/DATABASE_AUDIT_LOGS.md → Privacy`'s explicit design goal.
3. **A bulk/batch event** (e.g. `stock_count.finalized` covering 5,000 underlying `inventory_items` rows).
   One audit row represents the whole batch; the Diff Viewer shows the aggregate reference (a link to the
   `stock_counts` record) rather than attempting to render 5,000 individual field diffs inline, exactly
   matching `docs/database/DATABASE_AUDIT_LOGS.md → Performance`'s stated rule that bulk operations write one
   audit row per logical batch, not per row.
4. **A very large single-row diff** (`new_values.ref` pointing at an R2-stored attachment rather than an
   inline payload, per `docs/database/DATABASE_AUDIT_LOGS.md → Edge Cases`). `AuditDiffViewer` detects the
   `ref` shape and fetches/renders the referenced attachment content instead of the deliberately small inline
   JSON, opening in a `Sheet` rather than an inline expansion given its size.
5. **Impersonation.** Both `actor_user_id` (the real support/admin actor) and `acting_as_user_id` (the
   customer user whose session was assumed) render together in `AuditActorCell`, never collapsed to one
   identity, per `docs/database/DATABASE_AUDIT_LOGS.md → Edge Cases`'s rule that the impersonated identity
   never fully "becomes" the actor of record.
6. **Actor is genuinely unknown.** Both `actor_user_id` and `actor_service` are `null` (an unauthenticated
   event such as a failed login with an unrecognized username). `AuditActorCell` renders a muted
   "Unauthenticated" chip, never a blank cell that could be misread as a rendering bug.
7. **AI-assisted business event, human actor of record.** A row with `new_values.ai_assisted = true` still
   carries a human `actor_user_id` (the approving human) per
   `docs/database/DATABASE_AUDIT_LOGS.md → AI actions` — `AuditActorCell` renders the human normally and adds
   a small, secondary `Sparkles` "AI-assisted" tag rather than misrepresenting the row as AI-actioned; a
   fully autonomous, `auto`-autonomy AI action instead carries `actor_service = 'ai:<agent_slug>'` with no
   human actor at all, and renders as the AI-agent chip described in `# Components Used`. The two are
   visually distinct by design.
8. **Realtime new-row arrival mid-scroll.** Surfaces only as the dismissible "New events recorded" banner
   (`# States`); the client never splices a row into an already-rendered virtualized window.
9. **Chain-verification finds a mismatch.** The single most severe state this screen can show: a persistent,
   non-dismissible, assertively-announced banner naming the affected row(s) and routed to Owner/CFO/Auditor
   per `docs/database/DATABASE_AUDIT_LOGS.md → Compliance`, with a direct link to the affected row(s)'
   own position in the table. This banner does not clear on its own — it requires an explicit
   acknowledgment action from a holder of `audit.chain.verify`, itself recorded as a new audit row.
10. **A legal hold blocks a restore or export.** The Retention panel and the Export dialog both surface
    "Blocked by an active legal hold (case {ref})" as an explicit, specific message — never a generic
    permission error — mirroring `docs/database/DATABASE_ARCHIVING.md → Example 5`'s worked scenario.
11. **Export of a very large filtered range.** Returns `202 Accepted` + a job id rather than holding the
    request open; "Recent exports" re-surfaces the finished file via Notification, identical to
    `docs/frontend/GENERAL_LEDGER.md`'s own export edge case.
12. **Session permission change mid-view.** If `audit.export`, `audit.chain.verify`, or
    `audit.anomaly.review` is revoked while its menu is open, the next attempt's `403` collapses that
    specific control to its permission-denied state with an explanatory toast, rather than a stuck or
    silently-failing action — the frontend never assumes its own last-known permission snapshot is still
    valid.
13. **An `in`-operator filter (e.g. selecting many actors) exceeds the 100-value cap.**
    `docs/api/API_FILTERING_SORTING.md → Supported Operators` caps `in`/`not_in` at 100 values;
    `AuditActorFilter` warns and requires narrowing the selection rather than silently truncating the list
    and returning an incomplete result that looks complete.
14. **Switching Table/Timeline, or switching company, mid-scroll.** Both reset `cursor` to `null` and discard
    the in-flight `useInfiniteQuery` state — a cursor issued under one view or one company is never replayed
    against the other, matching the platform's company-switch cache-discard contract and
    `API_PAGINATION.md`'s own guidance to reset on any context switch.

# End of Document
