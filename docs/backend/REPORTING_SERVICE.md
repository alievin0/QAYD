# Reporting Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: REPORTING_SERVICE
---

# Purpose

This document specifies the backend **Reporting Service** — the module that assembles financial
statements from the ledger, runs the catalogue of management and operational reports, and delivers
their output synchronously for light queries or asynchronously (queued, with progress and downloadable
artifacts) for heavy ones. It is the concrete instantiation of the shared service-layer contract in
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) for the reporting concern, and it is the backend
counterpart of the accounting-facing designs in
[../accounting/REPORTS.md](../accounting/REPORTS.md) and
[../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md).

The Reporting Service has one defining characteristic that separates it from every transactional module:
**it is read-only with respect to business data.** It never posts a journal entry, never mutates an
invoice, never moves stock. It reads the ledger that [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md)
owns and the sub-ledgers that Sales, Purchasing, Inventory, and Payroll own, and it turns those numbers
into statements, tables, charts, PDFs, and spreadsheets. The single write surface it does own is its own
bookkeeping — report definitions, schedules, execution runs, and the immutable rendered snapshots those
runs produce. This asymmetry drives every design decision below: aggressive caching is safe because the
source is immutable posted history; queued generation is safe because a report can always be re-derived;
and multi-tenancy is enforced identically to every other module even though nothing here debits or
credits an account.

The goal is that a controller, a scheduler tick, an AI Forecast/CFO agent, and a share-link viewer all
reach the same assembled figures through the same Actions and the same tenant scoping, so that a number
on a dashboard tile, a number in a scheduled PDF, and a number an AI agent reasons over are provably the
same number.

# Responsibilities

The Reporting Service owns the following, and nothing outside it:

- **Financial-statement assembly.** Balance Sheet (as-of cumulative balances), Income Statement (period
  movement), Cash Flow (indirect default, direct alternative), Statement of Changes in Equity, and
  Comprehensive Income — each derived from `journal_lines` over posted `journal_entries`, per the
  computation rules in [../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md). The
  service assembles; it never *posts* the closing entries those statements imply (year-end close and
  reserve transfers are AI-drafted, human-approved journals owned by Accounting).
- **Management and operational reports.** The seeded Standard Reports (Trial Balance, General Ledger,
  Customer/Vendor Aging, Sales/Purchase Analysis, Inventory Valuation, Payroll Summary, Cash Position,
  Profitability, KPIs) plus company-authored Custom Reports built in the Report Builder.
- **Report definitions and parameters.** The catalogue (`report_definitions`) of what a company can run,
  each with a typed parameter schema, a required permission, a data-source list, and a versioned config.
- **Asynchronous generation.** Routing a generation request to a synchronous fast path or a queued
  `GenerateReport` job, tracking progress through a `report_runs` row, and producing a durable
  `report_snapshots` artifact.
- **Export artifacts.** Rendering a completed run to PDF / XLSX / CSV / JSON / HTML and storing the
  binary in R2 for download, gated by `reports.*.export`.
- **Scheduled reports.** Cron/frequency-driven `report_schedules` that generate and deliver (email,
  shared folder, webhook) unattended.
- **Caching of expensive aggregates.** The three-tier cache (Redis rollups, materialized views, durable
  snapshots) that keeps dashboards and wide-range statements off live full scans.
- **AI report inputs.** Supplying the FastAPI AI engine — Forecast Agent and the CFO/Reporting Agent —
  with the assembled snapshot data it reasons over, and storing the returned narrative/forecast/ratio
  enrichments against the run.

Explicitly **not** its responsibility: writing to any business table, computing valuation/COGS (that is
[INVENTORY_SERVICE.md](./INVENTORY_SERVICE.md)), computing payroll (that is
[PAYROLL_SERVICE.md](./PAYROLL_SERVICE.md)), or running the double-entry posting engine (that is
[ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md)).

# Domain Model

The reporting domain is deliberately small and orthogonal to the accounting domain it reads.

- **ReportDefinition** — the catalogue entry. A system-seeded Standard Report, a dashboard, or a
  company Custom Report. Carries `category`, `output_format`, `data_sources`, a `config` (fields,
  filters, grouping, sorting, calculated fields, layout), a `default_params` schema, a
  `required_permission`, and a `version` with a `parent_definition_id` history chain. System reports are
  `is_locked` (core columns cannot be removed) and `is_system`.
- **ReportParameterSet** — the resolved, validated parameters of one generation: `period_start`/`period_end`
  or `as_of`, `branch_id`/`warehouse_id`/`department_id` filters, `comparison` mode, `group_by`,
  presentation currency, and `output_format`. Relative dates (`last_quarter`) are resolved to concrete
  dates at run time and stored on the run so the run is reproducible.
- **ReportRun** — one execution of a definition with a parameter set. The execution ledger row: it moves
  through `queued -> running -> completed` (or `failed`/`cancelled`), records `triggered_by`
  (`user`/`schedule`/`api`/`ai_agent`), `as_of` (the posting cutoff), `duration_ms`, and a link to the
  snapshot it produced.
- **ReportSnapshot** — the immutable rendered payload of a completed run (`columns`, `rows`, `subtotals`,
  `footer`), a `payload_hash` (SHA-256 for tamper detection), the `report_definition_version` it was
  generated against, and an optional R2 `storage_ref` for large bodies. This is the artifact that makes a
  report reproducible byte-for-byte months later.
- **FinancialStatementSnapshot** — the specialised, statement-shaped snapshot for the five primary
  statements: header (framework, period, currency, scope), a tree of statement lines with computed
  amounts and comparatives, and the attached Notes. Assembled by the statement pipeline rather than the
  generic tabular pipeline.
- **ReportSchedule** — a frequency/cron rule that owns a parameter set, an export format, a delivery
  method, and recipients; the scheduler enqueues a run when `next_run_at` arrives.
- **ReportShare** — a grant of a run/definition to an internal user, an internal role, or an external
  tokenised link.

The invariant that ties the domain together: **a snapshot is immutable and self-describing.** Once
written it is never updated; a re-run is a new run and a new snapshot. Because the source ledger is also
immutable (posted lines are append-only per ACCOUNTING_SERVICE), a snapshot re-derived from the same
`(definition_version, params, as_of)` tuple is guaranteed to produce an identical `payload_hash` — which
is exactly why the durable-snapshot cache tier is correct rather than merely convenient.

# Key Classes

The service follows the Action-by-default rule from
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md), promoting to a Service only where several
operations share internals (statement assembly, export rendering).

- `App\Actions\Reporting\GenerateReportAction` — the single entrypoint for "run this definition with
  these params." Decides fast-path vs. queued, creates the `report_runs` row, and either returns the
  completed snapshot or dispatches `GenerateReport`.
- `App\Services\Reporting\ReportAssemblyService` — the generic tabular pipeline (resolve data sources ->
  apply filters/grouping/sorting/calculated fields -> paginate/subtotal -> render payload).
- `App\Services\Reporting\FinancialStatementService` — the statement pipeline (resolve scope -> template ->
  period -> FX -> assemble lines from `mv_gl_monthly_balances`/`journal_lines` -> validate the balance
  identity -> build Notes).
- `App\Services\Reporting\ReportExportService` — renders a completed snapshot to PDF (headless-Chromium
  HTML render), XLSX, CSV, JSON, or HTML, and stores the artifact in R2.
- `App\Services\Reporting\ReportCacheService` — the three-tier cache resolver (Redis rollup -> materialized
  view -> durable snapshot) and its event-driven busting.
- `App\Actions\Reporting\ScheduleReportAction` / `App\Console\Commands\DispatchDueReportSchedules` — create
  a schedule and the scheduler tick that enqueues due runs.
- `App\Jobs\Reports\GenerateReport` — the queued worker (extends `TenantAwareJob`, queue `reports`).
- `App\Repositories\Reporting\ReportDefinitionRepository`, `ReportRunRepository`,
  `ReportSnapshotRepository` — persistence seams for the module's own tables.
- `App\Data\Reporting\ReportParams`, `ReportRunResult` — the DTOs crossing the layer boundary.

The generic tabular pipeline reads the `report_definitions.config` and dispatches over `report_data_sources`
(each mapping a `code` to a base table or materialized view with a `required_permission`), so a Custom
Report is data-driven — no per-report PHP class.

```php
namespace App\Actions\Reporting;

use App\Data\Reporting\ReportParams;
use App\Jobs\Reports\GenerateReport;
use App\Models\Reporting\ReportDefinition;
use App\Models\Reporting\ReportRun;
use App\Models\User;
use App\Repositories\Reporting\ReportRunRepository;
use App\Services\Reporting\ReportAssemblyService;
use App\Services\Reporting\ReportCacheService;
use App\Support\Enums\ReportRunStatus;
use App\Support\Enums\ReportRunTrigger;
use Illuminate\Support\Facades\DB;

final class GenerateReportAction
{
    /** Definitions estimated to exceed this row/scan budget run on the queue. */
    private const SYNC_ROW_BUDGET = 5_000;

    public function __construct(
        private readonly ReportRunRepository $runs,
        private readonly ReportAssemblyService $assembly,
        private readonly ReportCacheService $cache,
    ) {}

    public function execute(
        ReportDefinition $definition,
        ReportParams $params,
        User $actor,
        ReportRunTrigger $trigger = ReportRunTrigger::User,
    ): ReportRun {
        // 1. Durable-snapshot cache: identical (definition_version, params, as_of) within the
        //    category freshness window replays the prior snapshot without re-running the query.
        if (! $params->forceRefresh && $hit = $this->cache->freshSnapshot($definition, $params)) {
            return $hit->run;
        }

        // 2. Create the execution-ledger row inside a transaction so the run and its resolved
        //    params commit together; the run row is this module's ONLY write on a generate call.
        $run = DB::transaction(fn (): ReportRun => $this->runs->open([
            'report_definition_id' => $definition->id,
            'triggered_by'         => $trigger,
            'triggered_by_user_id' => $actor->id,
            'params'               => $params->resolved(),   // relative dates -> concrete dates
            'as_of'                => $params->asOf(),
            'status'               => ReportRunStatus::Queued,
            // company_id, created_by auto-filled by BelongsToCompany
        ]));

        // 3. Route: light reports render inline; heavy ones defer to the queue with progress.
        if ($this->assembly->estimateRows($definition, $params) <= self::SYNC_ROW_BUDGET) {
            $this->assembly->run($run);          // marks running -> completed, writes snapshot
        } else {
            GenerateReport::dispatch($run->company_id, $run->id)->onQueue('reports');
        }

        return $run->refresh();
    }
}
```

The statement pipeline is a Service because its stages (scope, template, period, FX, assembly, validation,
Notes) share intermediate state and helpers:

```php
namespace App\Services\Reporting;

use App\Domain\Reporting\StatementDraft;
use App\Exceptions\Reporting\BalanceIdentityViolationException;
use App\Models\Reporting\ReportRun;
use App\Repositories\Reporting\LedgerReadRepository;

final class FinancialStatementService
{
    public function __construct(private readonly LedgerReadRepository $ledger) {}

    /** Assemble a Balance Sheet as of a date, enforcing the balance identity before it is stored. */
    public function balanceSheet(ReportRun $run): StatementDraft
    {
        $scope = $this->resolveScope($run);                   // company + branch/dept/project + subs
        $tpl   = $this->resolveTemplate($run, 'balance_sheet');
        $asOf  = $run->as_of;
        $fx    = $this->resolveFx($run, spotAt: $asOf);       // period-end spot, per IAS 21

        // Cumulative posted balance per mapped account through as_of, from the read replica.
        $lines = $this->ledger->cumulativeBalances($scope, throughDate: $asOf, framework: $tpl->framework);

        $draft = StatementDraft::fromLines($tpl, $lines, $fx);

        // TOTAL ASSETS = TOTAL LIABILITIES + TOTAL EQUITY, by construction if the ledger is balanced.
        $variance = $draft->totalAssets()->minus($draft->totalLiabilities()->plus($draft->totalEquity()));
        if (! $variance->isZero()) {
            // Root cause is always an unposted/unbalanced entry; report the exact variance + accounts.
            throw new BalanceIdentityViolationException($variance, $draft->contributingAccounts());
        }

        return $draft;
    }
}
```

# Endpoints Backed

All endpoints are versioned under `/api/v1/reports/`, require a Sanctum/JWT bearer token, are scoped to
the active company via the `X-Company-Id` header, and return the standard platform envelope
(`success`, `data`, `message`, `errors`, `meta`, `request_id`, `timestamp`) per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md).

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | /api/v1/reports/definitions | reports.custom.read | List report definitions (Standard + dashboards + Custom) available to the company |
| GET | /api/v1/reports/definitions/{id} | reports.custom.read | Retrieve one definition with its parameter schema |
| POST | /api/v1/reports/definitions | reports.custom.create | Create a Custom Report definition |
| PATCH | /api/v1/reports/definitions/{id} | reports.custom.update / reports.custom.update.any | Update a Custom Report (own vs. any) |
| DELETE | /api/v1/reports/definitions/{id} | reports.custom.delete | Soft-delete a Custom Report |
| POST | /api/v1/reports/definitions/{id}/duplicate | reports.custom.create | Clone a definition (e.g., a system report into an editable Custom Report) |
| POST | /api/v1/reports/definitions/{id}/generate | (definition's `required_permission`) | Generate the report; returns 200 (sync) or 202 (queued) |
| GET | /api/v1/reports/runs/{id} | (run's definition permission) | Poll a run's status / retrieve the completed payload |
| GET | /api/v1/reports/runs/{id}/export | reports.{category}.export | Download the run rendered as PDF/XLSX/CSV/JSON/HTML |
| POST | /api/v1/reports/runs/{id}/ai-narrative | reports.narrative | Request an AI narrative/analysis enrichment for a completed run |
| GET | /api/v1/reports/schedules | reports.schedule.read | List scheduled reports |
| POST | /api/v1/reports/schedules | reports.schedule.create | Create a scheduled report |
| PATCH | /api/v1/reports/schedules/{id} | reports.schedule.update | Update a schedule |
| DELETE | /api/v1/reports/schedules/{id} | reports.schedule.delete | Delete a schedule |
| POST | /api/v1/reports/definitions/{id}/share | reports.custom.update | Share a definition with a user/role/external link |
| GET | /api/v1/reports/shared/{token} | (token-scoped) | Retrieve a report via an external share token (read-only) |
| DELETE | /api/v1/reports/shares/{id} | reports.custom.update | Revoke a share |
| GET | /api/v1/reports/data-sources | reports.custom.create | List Report Builder data sources the user may read |
| POST | /api/v1/reports/ask | reports.narrative | Natural-language question answered against report data (AI) |
| POST | /api/v1/reports/draft-from-text | reports.custom.create | AI-drafted Custom Report definition from a text prompt |
| GET/POST | /api/v1/reports/favorites | reports.custom.read | List/add favorite reports |

Generate returns **200 OK** with the inline `columns`/`rows`/`subtotals`/`footer` payload for a light
report, or **202 Accepted** with `{ run_id, status: "queued", poll_url }` for a heavy one; the client
polls `GET /runs/{id}` until `status: completed`, then reads the payload or requests an export. The
financial statements are surfaced through the same `generate` endpoint keyed on their seeded system
definitions (`STD_BALANCE_SHEET`, `STD_INCOME_STATEMENT`, `STD_CASH_FLOW`, ...).

```json
{
  "success": true,
  "data": {
    "run_id": 918273,
    "status": "completed",
    "as_of": "2026-07-16T09:15:00Z",
    "columns": [
      { "key": "account", "label": "Account", "type": "string" },
      { "key": "amount", "label": "Amount", "type": "currency", "currency": "KWD" }
    ],
    "rows": [ { "account": "Trade and Other Receivables", "amount": 128450.5000 } ],
    "subtotals": [ { "group": "Current Assets", "amount": 402118.0000 } ],
    "footer": { "total_assets": 1204880.7500, "balanced": true },
    "ai_summary": { "text": "...", "confidence": 0.81, "source_refs": ["report_runs.918273.rows[..]"] }
  },
  "message": "Report generated successfully",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 200, "total": 46, "cursor": null } },
  "request_id": "6f2f0e2a-9d3f-4b4e-8b0a-7e6a2c9f9d10",
  "timestamp": "2026-07-16T09:15:02Z"
}
```

# Database Tables Owned

The Reporting Service owns exactly these tables (full DDL in
[../accounting/REPORTS.md](../accounting/REPORTS.md) and
[../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md)); it reads, but does not
own, every business table those reports aggregate. All tables carry the platform standard columns
(`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`);
money is `NUMERIC(19,4)`, config is `JSONB`.

| Table | Role | Notes |
|---|---|---|
| `report_definitions` | The report catalogue | `code`, `category`, `config` (GIN-indexed), `version`, `parent_definition_id`, `required_permission`, `is_system`/`is_locked` |
| `report_schedules` | Frequency/cron delivery rules | `frequency`, `cron_expression`, `run_at_time`, `timezone`, `recipients`, `next_run_at` |
| `report_runs` | The execution ledger | one row per generation; `status`, `triggered_by`, `as_of`, `duration_ms`, `snapshot_id`, `ai_metadata` |
| `report_snapshots` | Immutable rendered payload | `payload` (JSONB), `payload_hash` (SHA-256), `report_definition_version`, `storage_ref` (R2), `retention_expires_at`, `legal_hold` |
| `report_data_sources` | Report Builder source registry | `code`, `base_table_or_view`, `required_permission`, `fields`, `joinable_sources` |
| `report_shares` | User/role/external-link grants | `report_share_scope`, tokenised links |
| `report_favorites`, `report_comments`, `user_dashboard_layouts` | UX metadata | non-financial |
| `report_daily_rollups` | Pre-computed trend fast path | one row per `(company, branch, day, metric, dimension)` |
| `financial_statement_templates` | Statement layout per (company, type, framework) | line mapping, `cash_flow_method`, `oci_presentation_mode` |
| `financial_statement_lines` | Line -> account(s) mapping | wildcard by `account_type_id` or explicit `account_id` |
| `financial_statement_snapshots` / `financial_statement_snapshot_lines` | Immutable statement runs | reproducible statement artifact + line detail |
| `financial_statement_notes` | Structured IAS 1 disclosures | draft -> reviewed -> approved workflow |
| `financial_statement_terminology`, `financial_statement_shares` | Localised captions, sharing | EN/AR terminology |

Read-only sources (owned elsewhere) include `journal_entries`/`journal_lines` and `accounts` (Accounting),
`invoices`/`invoice_items` (Sales), `bills` (Purchasing), `inventory_items`/`inventory_valuations`
(Inventory), and `payroll_runs`/`payroll_items` (Payroll). The service reaches these only through the
sanctioned read paths below — never with a cross-module write.

Materialized views owned for **refresh scheduling** (but reading other modules' tables):
`mv_gl_monthly_balances` (the base for Trial Balance, Balance Sheet, Income Statement),
`mv_sales_daily`, and `mv_inventory_valuation_current`. Reporting owns the refresh cadence
(`REFRESH MATERIALIZED VIEW CONCURRENTLY` incrementally every 5 min for the GL/sales views, 60 s for the
inventory snapshot, and force-refreshed after any bulk import or period close) but is never the primary
source for the figures it caches.

# Multi-Tenancy Enforcement

Reporting is company-scoped identically to every transactional module, per
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
[../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) — the fact that it only reads
does not relax isolation; a report that leaks another tenant's numbers is the worst-case breach.

- **Ambient tenant scope.** Every owned table carries `company_id NOT NULL`; the `BelongsToCompany` trait
  fills it on write and `CompanyScope` adds `WHERE company_id = app.current_company_id` on read, so a
  `ReportDefinition`/`ReportRun` query can never see another company's rows.
- **PostgreSQL RLS is the backstop.** Every report query — including the raw aggregate SQL a Custom Report
  compiles to — runs on a connection where `SET LOCAL app.current_company_id` has been issued by the
  request/job envelope, so even a hand-written data-source query that forgot a `company_id` predicate is
  still filtered by the RLS policy on the underlying business tables. This matters more here than anywhere
  else because Reporting executes composed, partly data-driven SQL.
- **Reads route to the replica, still scoped.** Heavy aggregate reads are declared read-only and routed to
  a read replica; the replica connection is subject to the same `SET LOCAL app.current_company_id` and the
  same RLS policies, so replica routing never becomes a scope-escape hatch (per MULTI_TENANCY, Reporting
  and analytical queries).
- **Materialized views are tenant-columned.** Every MV carries `company_id` in its `GROUP BY` and its
  unique index leads with `company_id`; queries against an MV always filter by the active company, and the
  MV is never exposed as an un-scoped source.
- **Snapshots are tenant-owned artifacts.** A `report_snapshots`/`financial_statement_snapshots` row and
  its R2 object key are company-scoped; a share token resolves to a specific snapshot within its owning
  company and cannot be repointed across tenants.
- **Jobs re-establish context.** `GenerateReport` extends `TenantAwareJob` and calls `bindTenant($companyId)`
  before any read, re-issuing `SET LOCAL app.current_company_id` under PgBouncer transaction pooling so a
  scheduled or queued generation is scoped exactly as the originating request was.
- **No cross-tenant path.** The only sanctioned cross-tenant read is the platform-analytics path guarded by
  `RequirePlatformAdmin` (MULTI_TENANCY, Cross-tenant queries); no company-facing report ever uses
  `withoutCompanyScope()`/`forCompany()`.

# Events, Queues & Realtime

Reporting is primarily an **event consumer** (for cache busting) and a **queue producer** (for heavy
generation); it emits a small set of its own events.

**Queues.** Per [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) and
[BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md), heavy work runs on the named `reports` queue;
delivery of scheduled outputs runs on `integrations`. `GenerateReport` is tenant-aware, idempotent, and
retried with backoff:

```php
namespace App\Jobs\Reports;

use App\Jobs\Concerns\TenantAwareJob;
use App\Services\Reporting\ReportAssemblyService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class GenerateReport extends TenantAwareJob implements ShouldQueue
{
    public string $queue = 'reports';
    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $companyId,     // required for tenant re-binding
        public readonly int $reportRunId,
    ) {}

    public function handle(ReportAssemblyService $assembly): void
    {
        $this->bindTenant($this->companyId);            // SET LOCAL app.current_company_id + container bind
        $run = \App\Models\Reporting\ReportRun::findOrFail($this->reportRunId);

        // Idempotency: a retry of an already-completed run is a no-op (snapshot already exists).
        if ($run->status->isTerminal()) {
            return;
        }

        $assembly->run($run);                            // running -> completed, writes report_snapshots,
                                                         // stores export to R2, emits ReportGenerated
    }
}
```

**Progress.** A queued run's progress is the `report_runs.status` transition (`queued -> running ->
completed`) plus an optional `meta.progress_pct` written as stages complete; the client polls
`GET /runs/{id}`, and realtime clients receive `ReportRunProgressed`/`ReportGenerated` on the
company-scoped private channel so a dashboard updates without polling.

**Events emitted** (past-tense facts, ids only): `ReportGenerated`, `ReportRunFailed`,
`ReportScheduleExecuted`, `ReportShared`. These are `ShouldBroadcast` on `private-company.{id}` (Reverb),
so the frontend receives a live "your report is ready — download" toast.

**Events consumed** (to bust caches): the `ReportCacheService` subscribes to `JournalPosted`,
`InvoicePosted`, `PaymentReceived`, `StockMovementPosted`, and `PayrollReleased`. Each dependent Redis
rollup key (`kpi:{company_id}:{code}:{period}`) is busted immediately on the relevant event in addition
to TTL expiry, so a Cash Position tile updates the instant a bank transaction posts rather than waiting
out its 60 s TTL. Materialized-view refresh is time-driven, not event-driven, because incremental refresh
of the rolling window is cheaper than per-event refresh; but a period-close event forces a synchronous
refresh so a just-closed month's statements are immediately correct.

**Scheduler.** `DispatchDueReportSchedules` runs each minute (Laravel scheduler), selects
`report_schedules WHERE is_active AND next_run_at <= now()` (using the partial index), enqueues a
`GenerateReport` for each with `triggered_by = 'schedule'`, advances `next_run_at` from the
`frequency`/`cron_expression` in the schedule's `timezone` (default `Asia/Kuwait`), and — on completion —
dispatches a `DeliverScheduledReport` job that emails the artifact, drops it in a shared folder, or POSTs
a webhook per `delivery_method`.

# Integrations

- **Accounting (read-only ledger).** The service reads posted `journal_entries`/`journal_lines` and
  `accounts` through `LedgerReadRepository` on the read replica; it never posts. All statement figures
  derive from this ledger per the computation rules in
  [../accounting/FINANCIAL_STATEMENTS.md](../accounting/FINANCIAL_STATEMENTS.md), and the posting engine
  itself is owned by [ACCOUNTING_SERVICE.md](./ACCOUNTING_SERVICE.md) — Reporting depends on it but does
  not duplicate it.
- **Sub-ledgers (read-only).** Sales, Purchasing, Inventory, and Payroll expose their figures to Reporting
  only through their materialized views / read repositories, never through a Reporting write into their
  tables. Inventory Valuation, Payroll Summary, and Aging reports read `inventory_valuations`,
  `payroll_items`, and `invoices`/`bills` respectively.
- **AI engine (Forecast Agent + CFO/Reporting Agent).** Per [../api/INTERNAL_API.md](../api/INTERNAL_API.md),
  the FastAPI engine never queries PostgreSQL or writes a statement line; it receives an assembled
  `financial_statement_snapshots`/`report_runs` payload through the Laravel API and returns structured
  enrichment (executive summary, ratio narrative, variance/trend analysis, pro-forma forecast, risk flags),
  each carrying `confidence` (0.00-1.00), `reasoning`, and `source_references` (specific
  `financial_statement_snapshot_lines.id`/`journal_lines.id`). The backend validates and stores the output
  as an `ai_conversations`/`ai_messages` pair linked to the snapshot, plus a denormalized `ai_insights`
  blob on the run for fast rendering. AI output is rendered in a visually distinct panel and never merged
  into the numeric grid; forecasts are watermarked "Forecast — Not an Audited Statement." The CFO agent's
  inputs are exactly these assembled snapshots — Reporting is the agent's data plane.
- **Object storage (R2).** Rendered PDF/XLSX artifacts and large snapshot payloads are stored in R2 and
  referenced by `report_snapshots.storage_ref`; downloads stream from R2 behind the export endpoint's
  permission check.
- **Delivery channels.** Scheduled reports deliver via email (the platform mailer), shared folder, or a
  company-registered webhook; webhook delivery is retried on the `integrations` queue.

# Permissions

Authorization follows the RBAC grammar `<area>.<action>` / `<area>.<entity>.<action>` from
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), company-scoped and default-deny,
enforced by policies invoked from FormRequests and — for the generate path — resolved dynamically from the
definition's `required_permission`.

| Permission | Grants |
|---|---|
| `reports.financial.read` | View financial statements (BS/P&L/CF/Equity/Comprehensive Income) and financial-category reports |
| `reports.financial.export` | Export financial reports to PDF/XLSX/CSV |
| `reports.operational.read` | View operational reports |
| `reports.inventory.read` | View inventory reports (Valuation, Aging, Turnover) |
| `reports.sales.read` / `reports.sales.read.all` | View own-scope vs. all sales reports |
| `reports.purchasing.read` | View purchasing reports |
| `reports.payroll.read` | View aggregate payroll reports |
| `reports.payroll.employee.read` | View employee-level payroll detail (sensitive; see PAYROLL_SERVICE) |
| `reports.tax.read` / `reports.tax.submit` | View / submit tax reports |
| `reports.banking.read` | View cash/bank reports |
| `reports.compliance.read`, `reports.audit.read` | Compliance and audit reports |
| `reports.executive.read` | Executive dashboard and cross-domain executive reports |
| `reports.custom.create` / `.update` / `.update.any` / `.delete` | Author/edit/delete Custom Reports (own vs. any) |
| `reports.schedule.read` / `.create` / `.update` / `.delete` | Manage scheduled reports |
| `reports.narrative` | Request AI narrative / ask a natural-language question against report data |

The generate endpoint checks the **definition's own** `required_permission` (e.g., generating
`STD_BALANCE_SHEET` requires `reports.financial.read`), so a Custom Report inherits the permission of the
data sources it reads. Critically, the AI engine's report calls are permission-checked **as the acting
user** — an AI Forecast run for a user without `reports.financial.read` is denied exactly as the human
would be — and the share-link path grants only read of the specific snapshot, never the ability to
re-run with different parameters or reach the underlying source tables. Sensitive employee-level payroll
figures require `reports.payroll.employee.read` even inside an otherwise-permitted report, so a Payroll
Summary a Finance user may see does not expose per-employee salary to a user lacking that scope.

# Error Handling

The service throws typed domain exceptions; the global handler maps them to the envelope and status per
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md). Reporting has no money-moving failure modes, but it
has generation, parameter, and permission failures.

| Thrown | Meaning | Rendered |
|---|---|---|
| `AuthorizationException` (policy/permission) | actor lacks the definition's `required_permission` | 403 `permission_denied` |
| `ReportDefinitionNotFoundException` | unknown or cross-tenant definition id | 404 `not_found` |
| `InvalidReportParameterException` | param fails the definition's schema (bad date range, unknown group_by) | 422 `invalid_parameter` |
| `BalanceIdentityViolationException` | `ASSETS != LIABILITIES + EQUITY` on a statement run | 422 `balance_identity_violation` (with variance + contributing accounts) |
| `InsufficientHistoryException` | AI trend/forecast requested with < 3 comparable periods | 422 `insufficient_history` |
| `ReportGenerationFailedException` | query/render error inside the pipeline | 500 `report_generation_failed` (run marked `failed`, `error_code`/`error_message` stored) |
| `ExportFormatUnsupportedException` | requested format not supported for the definition | 422 `unsupported_export_format` |
| `AiEngineUnavailableException` | FastAPI engine unreachable on an AI narrative path | 503 `ai_engine_unavailable` (the numeric report still returns; only the enrichment fails) |
| `ShareTokenExpiredException` | external share token expired/revoked | 410 `share_expired` |

A key design point: **the numeric report and its AI enrichment fail independently.** A statement always
returns its validated figures even if the AI narrative call times out — the AI layer is an enrichment,
never a dependency of the numbers. A `BalanceIdentityViolationException` is treated as a data-integrity
signal, not a rendering bug: the response names the exact variance and the accounts contributing to it so
the user can find the unposted or unbalanced journal entry, because a balanced ledger makes the identity
hold by construction. Queued-run failures land the run in `failed` with a structured `error_code`, and the
poll response surfaces it so the client shows a real error rather than an eternal spinner.

# Testing

Per the platform release checklist, a report feature is not done until it ships with its migration, API,
permissions, audit logging, AI support, docs, and tests.

- **Unit-test the assembly and statement Services** with the ledger read repository mocked: assert the
  Balance Sheet balances, the Income Statement sums only within-period movement, the indirect Cash Flow
  reconciles to the direct method, and the balance-identity guard throws on an injected imbalance.
- **Unit-test `GenerateReportAction`** with the assembly service and cache mocked: assert the sync/queued
  routing threshold, that a fresh durable snapshot replays without re-running, and that the `report_runs`
  row is created with resolved (concrete-date) params.
- **Feature-test through `/api/v1/reports`** to prove the thin controller, FormRequest param validation,
  the definition-driven permission check, and the envelope cooperate — and to prove **tenant isolation**
  (company A's generate for company B's definition id returns 404; a company A report can never sum company
  B's `journal_lines`) and **default-deny** (missing `reports.financial.read` -> 403).
- **Snapshot reproducibility tests** assert that re-running the same `(definition_version, params, as_of)`
  produces an identical `payload_hash`, and that a subsequent identical request within the freshness window
  serves the cached snapshot (no second query).
- **Cache-busting tests** assert that a `JournalPosted` event busts the dependent KPI rollup key and that a
  period-close forces a synchronous MV refresh.
- **Scheduler tests** assert `next_run_at` advances correctly across frequencies/timezones and that a due
  schedule enqueues exactly one `GenerateReport`.
- **Export tests** assert each format renders from a snapshot, the artifact lands in R2, and the download is
  gated by `reports.{category}.export`.
- **AI-independence tests** assert that a statement returns its numeric payload even when the AI engine is
  stubbed to fail, and that an AI narrative request is permission-checked as the acting user.

# End of Document
