# Reporting Tools — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: REPORTING_TOOLS
---

# Purpose

`TOOLS_PROMPTS.md` establishes, once and platform-wide, that every fact an agent states and every action an agent takes passes through a named, permissioned, schema-validated MCP tool call — never through a database credential, a filesystem path, or a raw HTTP request the model constructs freely — and that the per-module tool catalogs under `docs/ai/tools/*` are where each of those tools is specified "in exactly the form shown" by that document's `propose_journal_entry` example: one full JSON Schema per tool, plus the registry metadata (`endpoint_method`, `endpoint_path`, `permission_key`, `produces_type`, `max_calls_per_task`, `requires_verbal_confirm`, `mcp_server`) a human-readable "Tools & API Access" table elsewhere only ever compresses. This document is that catalog for the Reporting & Analytics module. It is authoritative over every simplified tool listing that appears elsewhere in this corpus — `AI_COMMAND_CENTER.md`'s widget registry, the Reporting Agent's own "Tools & API Access" table, the Ask AI and Natural Language Query panels — because it is the literal `ai_tool_registry` content the orchestrator hands to whichever model (`claude-sonnet-4-5` by platform default, or an alternate provider a company has configured) is reasoning on a given turn.

Reporting Tools is deliberately narrow. It publishes exactly eight tools, and every one of them wraps exactly one endpoint under `/api/v1/reports/*` or `/api/v1/dashboards/*` — the surface `REPORTS.md` specifies in full. In catalog order: **`list_report_definitions`** resolves a `report_definition_id` from a category, a search term, or "is this a Standard Report," and is the tool every other tool in this catalog depends on for that identifier. **`generate_report`** executes a report definition against the caller's own parameters, returning its payload synchronously for light reports or a `queued` run to poll for heavy ones. **`get_report_run_status`** polls that run. **`download_report`** exports a completed run to PDF/XLSX/CSV/JSON/HTML as a short-lived signed URL. **`get_kpi`** is a fast, cache-backed path to one or a handful of headline metrics without paying for a full report generation. **`get_dashboard_data`** retrieves an entire named dashboard's widget layout and live data in one call. **`schedule_report`** creates a recurring, self-triggering generation-and-delivery job. **`get_financial_ratios`** is a dedicated, cached path to the platform's standing library of fourteen financial ratios, each stamped with the exact statement snapshot it was derived from. Every one of the fifteen canonical agents may be granted some subset of this catalog, but in practice the **Reporting Agent** is the primary caller of all eight; the **CFO Agent** calls `get_kpi`, `get_dashboard_data`, and `get_financial_ratios` heavily for its own synthesis work; the **Forecast Agent**, **Fraud Detection**, and **Compliance Agent** each read narrowly from `generate_report`/`get_report_run_status` for the specific Standard Reports their own domain documents name; the **CEO Assistant** calls `get_dashboard_data` and `get_kpi` when assembling the Morning Briefing; and the **Approval Assistant** never calls any tool in this catalog directly, since routing an approval is not a reporting action.

Reporting & Analytics "owns zero primary business data" (`REPORTS.md`, Reporting Philosophy §1-2): every figure this module renders is derived, at query time or via a materialized view or a versioned snapshot, from tables owned by Accounting, Sales, Purchasing, Inventory, Payroll, Tax, and Banking. That architectural fact is what makes seven of this catalog's eight tools a `read` in the sense `TOOLS_PROMPTS.md` defines the category ("retrieves already-existing data the invoking principal is permitted to see... no state change" to any *financial* record) — even though `generate_report` writes a `report_runs` row and `download_report` may write a `report_snapshots` row, neither is a proposal that changes what the ledger says happened, neither carries `requires_approval`, and neither is evaluated by the Decision Engine's autonomy formula (`AI_FINANCE_OS.md`) at all, because `decision_type` values like `journal_entry.draft` or `compliance_gap` simply do not exist for a report execution — a report run is exactly the same class of action, with exactly the same consequence-free reversibility, as a human clicking "Generate" in the Next.js UI, run under that human's own permission grant. **`schedule_report`** is the deliberate exception, tagged `write_propose`: unlike the other seven, it creates a *standing* artifact that will keep acting — regenerating and emailing or webhook-delivering a report — on its own, into the future, without a human initiating each occurrence. That is a materially different risk shape from a single bounded read, and this document holds it to the same "the AI drafts, a human reviews and activates" discipline `REPORTS.md`'s own AI Responsibilities section already establishes for Natural Language Reports and for external distribution.

**Permission resolution is two-tiered, and every tool section below states both tiers rather than picking one.** The coarse tier is `reports.read` — the baseline grant `AI_COMMAND_CENTER.md`'s widget registry checks before rendering any AI-composed surface (the `kpi_strip`, the dashboards, the trend widgets). The fine tier is the category-specific key `REPORTS.md` defines and actually enforces server-side the moment category data is touched — `reports.financial.read`, `reports.payroll.read`, `dashboards.{code}.read`, `reports.{category}.export`, and so on. A tool call in this catalog must clear both: the orchestrator will not even offer a tool to a principal lacking `reports.read`, and the wrapped Laravel endpoint independently re-checks the specific category key regardless of what the orchestrator already filtered — the same belt-and-suspenders discipline `TOOLS_PROMPTS.md` documents for every other module's tools. Default deny extends to discoverability: a principal lacking a category's read permission is not shown that a matching report definition exists at all (`REPORTS.md`, Report Categories), and this catalog's tools honor that by omitting rather than denying.

Every tool below is published under `ai_tool_registry.mcp_server = 'reporting-tools'`, versioned `1` at first release, and every call — successful, denied, or errored — is written to `ai_logs` (`event_type='tool_called'`, `tool_name`, `permission_key_checked`, `permission_result`, `latency_ms`) before its result re-enters the reasoning loop, exactly as `TOOLS_PROMPTS.md`'s Tool-Result Handling section specifies unconditionally for every tool in the platform.

# Tool Catalog

| # | Tool | Category | Endpoint | Permission (fine-grained) | Produces | Sync/Async |
|---|---|---|---|---|---|---|
| 1 | `list_report_definitions` | `read` | `GET /api/v1/reports/definitions` | `reports.{category}.read` (per row, discoverability-filtered) | — (no new row) | Sync |
| 2 | `generate_report` | `read` | `POST /api/v1/reports/definitions/{id}/generate` | `reports.{category}.read` (+ `ai.reports.narrative` if `include_ai_summary`) | `report_runs` (+ `report_snapshots` on completion) | Sync (light) / Async via `report_runs` (heavy) |
| 3 | `get_report_run_status` | `read` | `GET /api/v1/reports/runs/{run_id}` | `reports.{category}.read` (of the underlying definition) | — (reads `report_runs`) | Sync (poll) |
| 4 | `download_report` | `read` | `GET /api/v1/reports/runs/{run_id}/export` | `reports.{category}.export` | — (reads `report_snapshots`; ephemeral signed URL) | Sync |
| 5 | `get_kpi` | `read` | `GET /api/v1/reports/kpis` | `reports.read` (+ `reports.{domain}.read` per requested KPI) | — (Redis-cached rollup) | Sync |
| 6 | `get_dashboard_data` | `read` | `GET /api/v1/dashboards/{code}` | `dashboards.{code}.read` | — (composed from cache + materialized views) | Sync |
| 7 | `schedule_report` | `write_propose` | `POST /api/v1/reports/schedules` | `reports.schedule.create` (+ `reports.{category}.read` of the target definition) | `report_schedules` (created `is_active=false`) | Sync call; async standing job once activated |
| 8 | `get_financial_ratios` | `read` | `GET /api/v1/reports/financial-ratios` | `reports.financial.read` | — (derived from the latest Balance Sheet/Income Statement snapshot) | Sync |

# Tool Definitions

## list_report_definitions

**Registry row.**

```json
{
  "name": "list_report_definitions",
  "version": 1,
  "category": "read",
  "description": "List the report definitions (the 16 seeded Standard Reports, company-authored Custom Reports, and dashboards) that exist for this company and that the invoking principal is permitted to see. Call this first whenever you need a report_definition_id to pass to generate_report, schedule_report, or get_financial_ratios, and you do not already have it from a prior tool result or from ai_memory. Never guess or fabricate a report_definition_id from a name you recall generically — resolve it through this tool every time you are not already holding one from this task's own transcript. A report definition the invoking principal's role cannot read is omitted from the result entirely, never returned with a denial marker; default deny extends to discoverability in this module.",
  "input_schema": {
    "type": "object",
    "properties": {
      "category": {
        "type": ["string", "null"],
        "enum": ["financial", "operational", "inventory", "sales", "purchasing", "payroll", "tax", "banking", "compliance", "audit", "executive", "custom", "dashboard", null],
        "description": "Filter to one report_category. Omit or null to list across every category the caller can see."
      },
      "is_system": {
        "type": ["boolean", "null"],
        "description": "true = only the 16 seeded Standard Reports (is_system=true); false = only company-authored Custom Reports; null = both."
      },
      "search": {
        "type": ["string", "null"],
        "maxLength": 160,
        "description": "Case-insensitive match against name_en/name_ar, e.g. 'balance sheet' or 'ميزانية'."
      },
      "page": { "type": "integer", "minimum": 1, "default": 1 },
      "per_page": { "type": "integer", "minimum": 1, "maximum": 200, "default": 25 }
    },
    "required": [],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `GET`. `endpoint_path`: `/api/v1/reports/definitions`. `permission_key`: none required to call the tool itself beyond the standing `reports.read` grant; each returned row is independently filtered against the requesting principal's `reports.{row.category}.read`. `produces_type`: `null`. `max_calls_per_task`: `5`. `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**When to use.** At the start of nearly any multi-step Reporting task — resolving "the balance sheet," "last quarter's sales analysis," or "that custom vendor-concentration report Khalid built" into a concrete `id` before calling `generate_report`. Also the correct tool for "what reports can I even run" style questions. **When not to use.** Do not call this repeatedly within one task once you already hold the `report_definition_id` you need from an earlier result in the same conversation — re-resolve only if the task's scope changes (a different category, a different company, a new search term).

**Output shape.** Standard envelope; `data` is an array, each element: `{ id, code, name_en, name_ar, category, output_format, is_system, is_template, status, version, required_permission }`, plus `meta.pagination` (`page`, `per_page`, `total`, `cursor: null` — this endpoint uses offset pagination since the definitions list itself is small, unlike report *run* row data).

**Errors.** `401` missing/expired bearer token. `422` an invalid `category` enum value. An empty `data: []` array is a valid, successful result (no matching definitions, or the caller can see none in that category) — never an error, and never silently retried.

**Worked example.** Mariam Al-Sabah (CFO, Al-Noor Trading, `company_id 4821`) asks the CFO Agent, "pull up the balance sheet." The agent calls:

```json
{ "type": "tool_use", "name": "list_report_definitions", "input": { "category": "financial", "search": "balance sheet", "is_system": true } }
```

```json
{ "success": true, "data": [ { "id": 42, "code": "STD_BALANCE_SHEET", "name_en": "Balance Sheet", "name_ar": "الميزانية العمومية", "category": "financial", "output_format": "document", "is_system": true, "status": "active", "version": 1, "required_permission": "reports.financial.read" } ], "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } } }
```

The agent now holds `report_definition_id: 42` and proceeds to `generate_report` without asking Mariam to repeat herself.

## generate_report

**Registry row.**

```json
{
  "name": "generate_report",
  "version": 1,
  "category": "read",
  "description": "Execute a report_definitions row and produce its data. Pass params matching the target definition's parameters_schema (period_start/period_end or as_of_date, branch_id, cost_center_id/project_id/department_id, comparison, group_by, as applicable to that report). A light report — one scoped to a small, indexed, or materialized working set — returns its full payload synchronously in this same tool result. A heavy report — a multi-year Trial Balance, a group-wide consolidation — returns status='queued' with a run_id; that is success, not failure, and you must call get_report_run_status to retrieve it once complete, never re-call this tool to 'check' on it. This tool only ever reads posted, already-permissioned data through the Reporting module's own generation pipeline; it has no path to journal_entries, invoices, bills, or any other operational table, and nothing it returns is a proposal awaiting anyone's approval.",
  "input_schema": {
    "type": "object",
    "properties": {
      "report_definition_id": { "type": "integer" },
      "params": {
        "type": "object",
        "description": "Must satisfy the target report_definitions.parameters_schema. Common fields across Standard Reports: period_start (date), period_end (date), as_of_date (date), branch_id (integer|null), cost_center_id (integer|null), project_id (integer|null), department_id (integer|null), comparison ('prior_period'|'prior_year'|'budget'|'none'), group_by (array of dimension codes, e.g. ['product_category','sales_rep']).",
        "additionalProperties": true
      },
      "output_format": { "type": "string", "enum": ["table", "chart", "widget", "document"], "default": "table" },
      "include_ai_summary": {
        "type": "boolean",
        "default": false,
        "description": "Attach a Reporting Agent Executive Summary to this run's ai_metadata. Requires the caller to also hold ai.reports.narrative, independent of the report's own read permission."
      },
      "force_refresh": {
        "type": "boolean",
        "default": false,
        "description": "Bypass the report_snapshots freshness cache (default 15 minutes for financial-category reports) and recompute now. Use sparingly — only when the user has explicitly indicated the cached figure may be stale (e.g. right after correcting a posting)."
      }
    },
    "required": ["report_definition_id", "params"],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `POST`. `endpoint_path`: `/api/v1/reports/definitions/{report_definition_id}/generate`. `permission_key`: `reports.{category}.read`, resolved dynamically from the target `report_definitions.required_permission` — never a static string, since this one tool fronts all twelve report categories. `produces_type`: `report_runs` (and, once `status` reaches `completed`, a linked `report_snapshots` row). `max_calls_per_task`: `5`. `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**Async / `report_runs` behavior.** Every call — synchronous or not — creates exactly one `report_runs` row, per `REPORTS.md`'s Database Design ("the execution ledger... whether it completes synchronously or is processed asynchronously"). `report_run_status` moves `queued → running → completed | failed | cancelled`. Whether a given call resolves synchronously is not a parameter this tool exposes — it is a platform-side classification of the *report definition and the resolved params* (Reporting Philosophy §3: "Heavy is asynchronous; light is instant," target latency under 800ms server-side for light reports) — the tool always returns immediately with either the finished payload (`200`) or a `run_id` to poll (`202`). A `force_refresh=true` call against an identical `(report_definition_id, params, as_of)` tuple that another task already has `queued`/`running` is folded into that existing `run_id` rather than starting a second, redundant execution.

**Errors.** `403 FORBIDDEN_REPORT_CATEGORY` — caller (or the AI's delegated principal) lacks the resolved category's read permission; message is the exact human-facing string `REPORTS.md` specifies ("You do not have permission to view Payroll reports"). `404` — `report_definition_id` not found, soft-deleted, or belongs to a different company than `X-Company-Id`. `422` — invalid `params` (`period_end` before `period_start`; a `group_by` field absent from the definition's `data_sources`; a required parameter from `parameters_schema` missing), `errors[]` populated per field. A completed run whose derivation independently fails an integrity check (e.g., the Balance Sheet's `Assets = Liabilities + Equity` validation) returns `status: "failed"`, `error_code: "BALANCE_SHEET_UNBALANCED"` — the platform returns a *failed run*, never a silently wrong statement. `429` — too many heavy generations already queued for this company; `Retry-After` is honored per `TOOLS_PROMPTS.md`'s error contract, never immediately reissued.

**Worked example — light report, synchronous.**

```json
{ "type": "tool_use", "name": "generate_report", "input": { "report_definition_id": 903, "params": { "period_start": "2026-04-01", "period_end": "2026-06-30", "comparison": "prior_period", "group_by": ["product_category", "sales_rep"] }, "include_ai_summary": true } }
```

```json
{
  "success": true,
  "data": {
    "run_id": 918273, "status": "completed", "as_of": "2026-07-16T09:15:00Z",
    "columns": [
      { "key": "product_category", "label": "Product Category", "type": "string" },
      { "key": "net_revenue", "label": "Net Revenue", "type": "currency", "currency": "KWD" },
      { "key": "gross_margin_pct", "label": "Gross Margin %", "type": "percentage" }
    ],
    "rows": [ { "product_category": "Steel & Rebar", "sales_rep": "Fahad Al-Mutairi", "net_revenue": 48250.5000, "gross_margin_pct": 0.3420 } ],
    "subtotals": [ { "group": "Steel & Rebar", "net_revenue": 79370.5000, "gross_margin_pct": 0.3244 } ],
    "footer": { "grand_total_net_revenue": 214870.7500, "row_count": 46 },
    "ai_summary": { "text": "Net revenue rose 8.1% quarter-over-quarter, led by Steel & Rebar.", "confidence": 0.81, "source_refs": ["report_runs.918273.rows[0]"] }
  },
  "message": "Report generated successfully"
}
```

**Worked example — heavy report, asynchronous.**

```json
{ "type": "tool_use", "name": "generate_report", "input": { "report_definition_id": 42, "params": { "as_of_date": "2026-07-16", "comparison": "prior_year" } } }
```

```json
{ "success": true, "data": { "run_id": 918274, "status": "queued", "poll_url": "/api/v1/reports/runs/918274" }, "message": "Report generation queued" }
```

## get_report_run_status

**Registry row.**

```json
{
  "name": "get_report_run_status",
  "version": 1,
  "category": "read",
  "description": "Poll a report_runs row by id. Call this only after generate_report returned status='queued', and only this tool to check on it — never re-call generate_report against the same report_definition_id and params 'to see if it's done,' which would enqueue a second, redundant run. Back off between polls: wait at least 2 seconds before the first poll, then double the wait up to a ceiling of 30 seconds between subsequent polls. If status is still 'queued' or 'running' after 5 polls, stop polling within this turn and tell the user the report is still generating rather than blocking the conversation indefinitely.",
  "input_schema": {
    "type": "object",
    "properties": { "run_id": { "type": "integer" } },
    "required": ["run_id"],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `GET`. `endpoint_path`: `/api/v1/reports/runs/{run_id}`. `permission_key`: `reports.{category}.read`, inherited from the run's own `report_definition_id` (a principal who could not generate the report cannot poll it either — checked independently on every poll, not cached from the original `generate_report` call, since a permission can change mid-poll). `produces_type`: `null` (reads an existing `report_runs` row; writes nothing). `max_calls_per_task`: `8`. `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**Async / `report_runs` behavior.** This is the *only* sanctioned way to observe a `queued`/`running` run's progress. A `completed` response carries the identical `columns`/`rows`/`subtotals`/`footer`/`ai_summary` shape `generate_report`'s synchronous path returns, so downstream reasoning (SELF-CHECK citing a specific row, `download_report` afterward) does not need to special-case which of the two tools actually produced the payload. A `failed` response carries `error_code`/`error_message` instead of a payload — this must be disclosed to the user verbatim, never retried silently, and never papered over by re-running with slightly different params without telling the user what changed and why.

**Errors.** `404` — `run_id` not found, or belongs to a different company. `403` — the run's underlying category permission was revoked since the run was queued (e.g., a role change mid-poll); the tool discloses this exactly as a fresh `403` would, not as a data problem. No `422` — a `run_id` is either a valid integer or the client-side schema rejects the call before any request is sent.

**Worked example.**

```json
{ "type": "tool_use", "name": "get_report_run_status", "input": { "run_id": 918274 } }
```

First poll, still running:

```json
{ "success": true, "data": { "run_id": 918274, "status": "running", "row_count": null, "queued_at": "2026-07-16T09:16:00Z", "started_at": "2026-07-16T09:16:04Z" } }
```

Second poll, four seconds later, complete:

```json
{ "success": true, "data": { "run_id": 918274, "status": "completed", "as_of": "2026-07-16T09:16:00Z", "row_count": 34, "duration_ms": 6210,
  "columns": [ { "key": "line_item", "label": "Line Item", "type": "string" }, { "key": "classification", "label": "Classification", "type": "string" }, { "key": "current_period_amount", "label": "This Year", "type": "currency", "currency": "KWD" }, { "key": "comparison_amount", "label": "Last Year", "type": "currency", "currency": "KWD" } ],
  "rows": [ { "line_item": "Cash and Bank", "classification": "current_asset", "current_period_amount": 42318.6000, "comparison_amount": 35110.2000 } ],
  "footer": { "assets_total": 812400.0000, "liabilities_plus_equity_total": 812400.0000, "balanced": true } } }
```

## download_report

**Registry row.**

```json
{
  "name": "download_report",
  "version": 1,
  "category": "read",
  "description": "Export a completed report_runs row to a portable file. The run's status must already be 'completed' — call get_report_run_status first if you are not certain, since exporting a run that is still queued or running returns an error rather than a partial file. Returns a short-lived signed download URL and file metadata, never the binary file content inline in this tool result — binary payloads do not belong in a model's context window. If the export would exceed this module's size ceiling (see Safety & Guardrails), the call fails with EXPORT_TOO_LARGE rather than silently truncating rows; narrow the report's params and re-generate instead of retrying the same export.",
  "input_schema": {
    "type": "object",
    "properties": {
      "run_id": { "type": "integer" },
      "format": { "type": "string", "enum": ["pdf", "xlsx", "csv", "json", "html"], "default": "pdf" }
    },
    "required": ["run_id", "format"],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `GET`. `endpoint_path`: `/api/v1/reports/runs/{run_id}/export`. `permission_key`: `reports.{category}.export` — a distinct grant from `reports.{category}.read` (a role can view a report on screen without being able to export it, e.g. External Auditor per `REPORTS.md`'s role matrix: "view snapshot only," export denied). `produces_type`: `null` for the tool's own JSON result (the export artifact itself is an ephemeral Cloudflare R2 object, not a new database row). `max_calls_per_task`: `5`. `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**Why this tool differs from the human-facing export endpoint.** A human clicking "Export" in the Next.js UI receives the binary file directly (`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, etc., streamed as the HTTP response body, per `REPORTS.md`'s API section). An MCP tool result cannot carry an arbitrary binary stream into a model's context this way — so this tool's *response* is JSON describing where the file is: a signed, time-boxed URL (default 15-minute expiry), its `content_type`, and its `file_size_bytes`, which the calling surface (chat UI, email, Voice Assistant transcript) then renders as a clickable link or attachment. The underlying Laravel endpoint and its content-type behavior are otherwise identical to the direct HTTP path.

**Errors.** `404` — `run_id` not found. `409` — the run exists but its `status` is not yet `completed` (still `queued`/`running`, or ended `failed`/`cancelled`); the model must call `get_report_run_status` and wait, or report the failure, rather than retry the export blindly. `403` — missing `reports.{category}.export`. `413`/`422` `EXPORT_TOO_LARGE` — the rendered payload exceeds the format's size ceiling (see Safety & Guardrails); the correction is to narrow `params` on a fresh `generate_report` call, not to retry the identical export.

**Worked example.**

```json
{ "type": "tool_use", "name": "download_report", "input": { "run_id": 918274, "format": "pdf" } }
```

```json
{
  "success": true,
  "data": {
    "run_id": 918274, "format": "pdf",
    "download_url": "https://cdn.qayd.app/r2/exports/4821/918274-balance-sheet.pdf?sig=&exp=1752657600",
    "content_type": "application/pdf",
    "file_size_bytes": 184320,
    "expires_at": "2026-07-16T09:46:00Z",
    "filename": "Balance_Sheet_2026-07-16.pdf"
  },
  "message": "Export ready"
}
```

## get_kpi

**Registry row.**

```json
{
  "name": "get_kpi",
  "version": 1,
  "category": "read",
  "description": "Retrieve one or more headline KPIs - revenue_mtd, gross_margin, net_margin, current_ratio, quick_ratio, dso, dpo, inventory_turnover, days_cash_on_hand, opex_ratio, cash_position, or a company-defined custom KPI code - with current value, target, trend, and the Reporting Agent's one-line commentary. Prefer this over generate_report against STD_KPI_SCORECARD when the user is asking about one or two named figures; it answers from a Redis-cached rollup in milliseconds rather than executing a full report run. Use generate_report instead when the user needs the complete scorecard as an exportable document, or a specific historical run to cite as a source.",
  "input_schema": {
    "type": "object",
    "properties": {
      "keys": {
        "type": ["array", "null"],
        "items": { "type": "string" },
        "minItems": 1,
        "maxItems": 20,
        "description": "KPI codes, e.g. ['gross_margin','dso']. Omit or null to return the company's full configured kpi_strip (up to ten tiles, per ai_dashboard_layouts)."
      },
      "period": { "type": "string", "enum": ["mtd", "qtd", "ytd", "last_12_months", "custom"], "default": "mtd" },
      "period_start": { "type": ["string", "null"], "format": "date" },
      "period_end": { "type": ["string", "null"], "format": "date" },
      "comparison": { "type": "string", "enum": ["prior_period", "prior_year", "none"], "default": "prior_period" }
    },
    "required": [],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `GET`. `endpoint_path`: `/api/v1/reports/kpis`. `permission_key`: `reports.read` as the baseline gate matching `AI_COMMAND_CENTER.md`'s `kpi_strip` widget entry, with each individual requested `key` additionally filtered to whichever fine-grained category permission its domain requires — a payroll-derived KPI (e.g., a hypothetical `payroll_cost_ratio`) is silently omitted from the response, not denied outright, for a caller lacking `reports.payroll.read`, consistent with this module's discoverability-is-default-deny posture. `produces_type`: `null`. `max_calls_per_task`: `10` (cheap and expected to be called often within a single conversational turn). `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**AI behavior note.** Per `AI_COMMAND_CENTER.md`'s KPIs panel, the `commentary` string attached to each KPI is only regenerated when the value itself moves materially (>2% or a target-band crossing) — the tool always returns the latest cached commentary rather than triggering fresh narrative generation on every call, so ten calls in a row from an exploratory conversation do not produce ten subtly different explanations of the same number.

**Errors.** `422` — an unrecognized KPI code in `keys`, or `period=custom` without both `period_start`/`period_end` supplied. A `keys` array where every requested code is permission-filtered away returns `200` with `data.kpis: []` and `message: "No KPIs available for the requested keys"` rather than a blanket `403`, since some of the requested codes might legitimately not exist for this company at all — the tool does not distinguish "denied" from "not applicable" in its response, by design, matching this module's discoverability rule.

**Worked example.**

```json
{ "type": "tool_use", "name": "get_kpi", "input": { "keys": ["gross_margin", "dso", "cash_position"], "period": "mtd", "comparison": "prior_period" } }
```

```json
{
  "success": true,
  "data": {
    "as_of": "2026-07-16T06:00:00+03:00",
    "kpis": [
      { "key": "gross_margin", "value": 31.2, "unit": "pct", "target": 30.0, "trend": "up", "change_pct": 1.4, "commentary": "Mix shift toward Contracting Services" },
      { "key": "dso", "value": 42, "unit": "days", "target": 35, "trend": "down", "commentary": "Diyar Real Estate slippage" },
      { "key": "cash_position", "value": 42318.600, "unit": "KWD", "target": 20000.000, "trend": "down", "commentary": "Trough Jul 29, see Cash Flow Status" }
    ]
  }
}
```

## get_dashboard_data

**Registry row.**

```json
{
  "name": "get_dashboard_data",
  "version": 1,
  "category": "read",
  "description": "Retrieve a named dashboard's full widget layout and current widget data in a single call - executive, finance, sales, inventory, payroll, tax, operations, or ai. Use this for broad situational questions ('how's the business doing', 'what does Finance look like today') rather than chaining several narrow tool calls; use get_kpi or generate_report instead when the user names one specific figure or report. The returned widget set is automatically scoped to the requesting principal's own dimensional access (a Sales Employee's sales widget reflects only their own pipeline unless they hold reports.sales.read.all; a Warehouse Employee's inventory widget reflects only their assigned warehouse) - you do not need to add that filtering yourself.",
  "input_schema": {
    "type": "object",
    "properties": {
      "code": { "type": "string", "enum": ["executive", "finance", "sales", "inventory", "payroll", "tax", "operations", "ai"] },
      "branch_id": { "type": ["integer", "null"] },
      "date_range": { "type": "string", "enum": ["today", "this_week", "this_month", "this_quarter", "this_year", "last_12_months"], "default": "this_month" }
    },
    "required": ["code"],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `GET`. `endpoint_path`: `/api/v1/dashboards/{code}`. `permission_key`: `dashboards.{code}.read` — per `REPORTS.md`, "each mapping to the corresponding report category permission plus any dashboard-specific composite widgets." For `code = "ai"`, this additionally resolves against `AI_COMMAND_CENTER.md`'s own composed-widget gate (`reports.read`/`ai.chat` per widget), since the AI Dashboard surfaces cross-module synthesis (pending AI-drafted actions, anomaly counts, forecast accuracy) rather than a single report category's Standard Reports. `produces_type`: `null`. `max_calls_per_task`: `5`. `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**Behavior.** Composed from the same Redis-cached rollups, materialized views (`mv_gl_monthly_balances`, `mv_sales_daily`, `mv_inventory_valuation_current`), and `report_daily_rollups` table that back the six Standard Dashboards' individual widgets (`REPORTS.md`, Dashboard Architecture) — this tool does not introduce a new computation path, it is a convenience aggregator over the same widget data a human would see tile-by-tile. If the requesting principal holds `dashboards.{code}.read` but lacks the fine-grained permission for one or more individual widgets on that dashboard (e.g., a Sales Manager viewing the Finance dashboard's Payroll-adjacent tiles, if any were ever added), those specific widgets are omitted from `data.widgets` rather than the whole call failing — partial visibility is a normal, successful result, not an error.

**Errors.** `403` — the principal holds none of the permissions any widget on the named dashboard requires at all (a fully empty dashboard, as opposed to a partially visible one, is treated as access denied and returns `403 FORBIDDEN_DASHBOARD` rather than a `200` with an empty array, so the model does not mistakenly report "everything is fine, no data" for a dashboard the user simply cannot see). `422` — an invalid `code`.

**Worked example.**

```json
{ "type": "tool_use", "name": "get_dashboard_data", "input": { "code": "finance", "date_range": "this_month" } }
```

```json
{
  "success": true,
  "data": {
    "code": "finance", "generated_at": "2026-07-16T09:40:00+03:00",
    "widgets": [
      { "widget_id": "trial_balance_check", "status": "ok", "value": 0.0000, "label": "Out-of-balance amount (should be 0.00)" },
      { "widget_id": "unposted_journal_entries", "status": "info", "count": 6, "oldest_days": 2 },
      { "widget_id": "ar_aging_summary", "status": "warning", "buckets": { "current": 61200.000, "31_60": 18400.000, "61_90": 24300.000, "91_120": 5100.000, "over_120": 1900.000 } },
      { "widget_id": "bank_reconciliation_status", "status": "ok", "reconciled_accounts": 3, "total_accounts": 3 }
    ]
  }
}
```

## schedule_report

**Registry row.**

```json
{
  "name": "schedule_report",
  "version": 1,
  "category": "write_propose",
  "description": "Create a recurring schedule that will, on its own and without further human action, regenerate a report_definitions row on a cadence and deliver it by email, shared folder, or webhook. This is the one tool in this catalog that creates a standing, self-triggering artifact rather than a single bounded action, and every schedule this tool creates is therefore staged is_active=false, status='pending_approval' regardless of your confidence - a human holding reports.schedule.create must review the recipients and cadence and explicitly activate it (PUT /api/v1/reports/schedules/{id} with is_active=true) before its first occurrence can fire. Never propose recipients you were not explicitly given or that do not already appear in this company's own user/role directory; an external email recipient you were not asked to include is exactly the kind of unreviewed external distribution this staging step exists to catch.",
  "input_schema": {
    "type": "object",
    "properties": {
      "report_definition_id": { "type": "integer" },
      "name": { "type": "string", "maxLength": 160 },
      "frequency": { "type": "string", "enum": ["once", "daily", "weekly", "monthly", "quarterly", "yearly", "cron"] },
      "cron_expression": { "type": ["string", "null"], "description": "Required when frequency='cron'; ignored otherwise." },
      "run_at_time": { "type": ["string", "null"], "description": "HH:MM:SS local time, for daily/weekly/monthly frequencies." },
      "run_on_day_of_week": { "type": ["integer", "null"], "minimum": 0, "maximum": 6 },
      "run_on_day_of_month": { "type": ["integer", "null"], "minimum": 1, "maximum": 31 },
      "timezone": { "type": "string", "default": "Asia/Kuwait" },
      "params": { "type": "object", "description": "Same shape as generate_report's params; relative date expressions (e.g. 'last_7_days') are resolved fresh at each occurrence." },
      "export_format": { "type": "string", "enum": ["pdf", "xlsx", "csv", "json", "html"], "default": "pdf" },
      "include_ai_summary": { "type": "boolean", "default": false },
      "delivery_method": { "type": "string", "enum": ["email", "shared_folder", "webhook"], "default": "email" },
      "recipients": {
        "type": "array",
        "minItems": 1,
        "items": {
          "type": "object",
          "properties": {
            "type": { "type": "string", "enum": ["user", "role", "email"] },
            "value": { "type": "string" }
          },
          "required": ["type", "value"],
          "additionalProperties": false
        }
      },
      "meta": {
        "type": "object",
        "properties": {
          "ai_generated": { "type": "boolean", "const": true },
          "confidence": { "type": "number", "minimum": 0, "maximum": 1 },
          "reasoning": { "type": "string", "minLength": 20 },
          "source_documents": {
            "type": "array", "minItems": 1,
            "items": { "type": "object", "properties": { "type": { "type": "string" }, "id": { "type": "integer" } }, "required": ["type", "id"], "additionalProperties": false }
          }
        },
        "required": ["ai_generated", "confidence", "reasoning", "source_documents"],
        "additionalProperties": false
      }
    },
    "required": ["report_definition_id", "name", "frequency", "params", "recipients", "meta"],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `POST`. `endpoint_path`: `/api/v1/reports/schedules`. `permission_key`: `reports.schedule.create` (+ `reports.{category}.read` on the target `report_definition_id`, since a caller cannot schedule delivery of a report they could not themselves generate). `produces_type`: `report_schedules`. `max_calls_per_task`: `3`. `requires_verbal_confirm`: `true` — the one tool in this catalog flagged for the Voice Assistant's stricter spoken-confirmation gate (`TOOLS_PROMPTS.md`, Read vs Write Tools & The Approval Gate: "a misheard word in a hands-free context is a costlier mistake than a misclick"), because a standing distribution list is exactly the kind of consequence a mishearing should not silently create. `mcp_server`: `reporting-tools`.

**Why `write_propose`, precisely.** Every other tool in this catalog is bounded: it runs once, for the calling principal, and produces nothing that acts again on its own. A schedule is different in kind - once activated, it fires on its cadence indefinitely, to whichever recipients were configured, with no human in the loop for any individual occurrence. That is the same qualitative jump `AI_FINANCE_OS.md`'s Reporting Agent profile already draws for external distribution ("distributing a report outside the company... is suggest_only, requiring a human to confirm the recipient"); this tool generalizes the caution to *any* AI-authored schedule, internal or external recipients alike, because the risk being staged against is an unreviewed standing job, not merely an unreviewed recipient. The `meta` block mirrors `propose_journal_entry`'s exact shape from `TOOLS_PROMPTS.md` - `ai_generated`, `confidence`, `reasoning`, `source_documents` all `required` at the JSON Schema level, so a call missing any of them fails client-side validation before it ever reaches Laravel.

**Errors.** `403` — missing `reports.schedule.create`. `404` — `report_definition_id` not found. `422` — an invalid cadence combination for the chosen `frequency` (e.g. `run_on_day_of_month` supplied with `frequency='weekly'`), or a `cron_expression` malformed or missing when `frequency='cron'`. `409` — an active schedule already exists for the identical `(report_definition_id, recipients, frequency)` tuple; the platform's `Idempotency-Key` plus this content-based dedup prevents a duplicate standing job from a retried call.

**Worked example.** The Reporting Agent, asked by Khalid Marafie (Finance Manager) to "send the sales team last week's numbers every Monday," calls:

```json
{
  "type": "tool_use", "name": "schedule_report",
  "input": {
    "report_definition_id": 903, "name": "Weekly Sales Analysis — Sales Team",
    "frequency": "weekly", "run_on_day_of_week": 1, "run_at_time": "08:00:00", "timezone": "Asia/Kuwait",
    "params": { "period_start": "relative:last_7_days", "group_by": ["product_category", "sales_rep"] },
    "export_format": "pdf", "include_ai_summary": true, "delivery_method": "email",
    "recipients": [ { "type": "role", "value": "Sales Manager" }, { "type": "user", "value": "khalid.marafie@al-noor.example.com" } ],
    "meta": { "ai_generated": true, "confidence": 0.97, "reasoning": "Khalid explicitly named the recipient group (Sales Manager role) and himself as an additional recipient, and explicitly asked for a weekly Monday cadence; no recipient or schedule detail was inferred beyond what was requested.", "source_documents": [ { "type": "ai_conversations", "id": 88551 } ] }
  }
}
```

```json
{
  "success": true,
  "data": { "id": 305, "report_definition_id": 903, "name": "Weekly Sales Analysis — Sales Team", "frequency": "weekly", "is_active": false, "status": "pending_approval", "next_run_at": null, "approval_request_id": 66201 },
  "message": "Schedule drafted — awaiting activation by a Finance Manager or above"
}
```

Note: `report_definition_id: 903` above is the real integer `list_report_definitions` (or an earlier `generate_report` call, in the Custom Reports worked example of `REPORTS.md`) resolved earlier in the same task - every real call carries a concrete integer, never a placeholder.

## get_financial_ratios

**Registry row.**

```json
{
  "name": "get_financial_ratios",
  "version": 1,
  "category": "read",
  "description": "Retrieve values from the platform's standing library of fourteen financial ratios (liquidity, leverage, profitability, efficiency) for a period, each stamped with the exact Balance Sheet or Income Statement report_runs.id it was derived from so every ratio is traceable back to an audited snapshot. Prefer this over generate_report against STD_RATIO_ANALYSIS when you only need the ratio values themselves rather than the full report table or an exportable document. If the underlying Balance Sheet fails its own balance check for the requested as_of_date, this tool refuses to compute any ratio that depends on it rather than silently returning a number derived from unbalanced inputs - report the failure to the user instead of the ratio.",
  "input_schema": {
    "type": "object",
    "properties": {
      "as_of_date": { "type": "string", "format": "date" },
      "period_start": { "type": ["string", "null"], "format": "date", "description": "Required for flow-based ratios (margins, turnover ratios); omit for point-in-time-only questions (e.g. current ratio alone)." },
      "period_end": { "type": ["string", "null"], "format": "date" },
      "branch_id": { "type": ["integer", "null"] },
      "ratios": {
        "type": ["array", "null"],
        "items": {
          "type": "string",
          "enum": ["current_ratio", "quick_ratio", "cash_ratio", "debt_to_equity", "interest_coverage", "gross_margin_pct", "operating_margin_pct", "net_margin_pct", "roa", "roe", "asset_turnover", "inventory_turnover", "receivables_turnover", "payables_turnover"]
        },
        "minItems": 1,
        "description": "Omit or null to return all fourteen."
      },
      "comparison": { "type": "string", "enum": ["prior_period", "prior_year", "none"], "default": "none" }
    },
    "required": ["as_of_date"],
    "additionalProperties": false
  }
}
```

**Registry metadata.** `endpoint_method`: `GET`. `endpoint_path`: `/api/v1/reports/financial-ratios`. `permission_key`: `reports.financial.read`. `produces_type`: `null` (derived synchronously from the latest qualifying `report_snapshots` for Balance Sheet/Income Statement; creates no row of its own). `max_calls_per_task`: `5`. `requires_verbal_confirm`: `false`. `mcp_server`: `reporting-tools`.

**Behavior.** This is a light, cached convenience path layered over the same `STD_RATIO_ANALYSIS` derivation `REPORTS.md`'s Analytics → Ratios section defines in full (the fourteen-row table of formula and category), backed by the same Redis-cached rollups the Business Health Score and KPI strip already use - it does not introduce a fifteenth computation of any ratio. Every returned ratio value carries `source_run_id`, the exact `report_runs.id` of the Balance Sheet or Income Statement snapshot it was computed from, matching `REPORTS.md`'s own closing guarantee for this analytic ("a ratio can always be traced back to the audited statement snapshot that produced it").

**Errors.** `422` — `period_start`/`period_end` missing for a flow-based ratio in the requested set (e.g. requesting `gross_margin_pct` without a period). `409 BALANCE_SHEET_UNBALANCED` — the Balance Sheet underlying the requested `as_of_date` failed its own integrity check; ratios dependent on it (all fourteen, since every one of them ultimately roots in Balance Sheet or Income Statement figures) are withheld rather than computed from a known-bad base, and the response names which upstream snapshot failed so the user can act on the actual data problem.

**Worked example.**

```json
{ "type": "tool_use", "name": "get_financial_ratios", "input": { "as_of_date": "2026-07-16", "period_start": "2026-01-01", "period_end": "2026-07-16", "ratios": ["current_ratio", "quick_ratio", "gross_margin_pct", "roe"], "comparison": "prior_year" } }
```

```json
{
  "success": true,
  "data": {
    "as_of_date": "2026-07-16",
    "ratios": [
      { "key": "current_ratio", "value": 1.80, "category": "Liquidity", "formula": "Current Assets / Current Liabilities", "source_run_id": 918274, "comparison_value": 1.65 },
      { "key": "quick_ratio", "value": 1.32, "category": "Liquidity", "formula": "(Current Assets - Inventory) / Current Liabilities", "source_run_id": 918274, "comparison_value": 1.21 },
      { "key": "gross_margin_pct", "value": 0.312, "category": "Profitability", "formula": "Gross Profit / Net Revenue", "source_run_id": 918290, "comparison_value": 0.298 },
      { "key": "roe", "value": 0.184, "category": "Profitability", "formula": "Net Profit / Average Total Equity", "source_run_id": 918290, "comparison_value": 0.171 }
    ]
  }
}
```

# Safety & Guardrails

**Read-only over derived data.** Every tool in this catalog terminates in one of two places: a `reports.*`/`dashboards.*` GET that returns an already-derived view (`list_report_definitions`, `get_report_run_status`, `download_report`, `get_kpi`, `get_dashboard_data`, `get_financial_ratios`), or a `POST` that either executes the existing, unmodified generation pipeline against already-posted data (`generate_report`) or stages a non-financial distribution record (`schedule_report`). No tool in this catalog has, or will ever be given, a path to `journal_entries`, `invoices`, `bills`, `payroll_runs`, `inventory_items`, or any other operational table - that boundary is enforced the same way `TOOLS_PROMPTS.md`'s Layer 1 enforces it platform-wide: the capability simply is not published to any agent's MCP server, so there is no runtime check to bypass. A report is a view; running one, however AI-triggered, changes nothing about what the ledger says happened.

**Permission-filtered rows, at every layer.** Three independent filters apply, in order, and none trusts the others alone: (1) the orchestrator only offers a tool this turn if the invoking principal holds at least the coarse `reports.read`/relevant `dashboards.{code}.read` gate; (2) the wrapped Laravel endpoint independently re-checks the fine-grained `reports.{category}.read`/`.export` key server-side, exactly as it would for a human's identical click; (3) within a result set, individual rows, widgets, or KPI keys the principal's role cannot see are silently omitted rather than flagged denied - a Sales Employee's `get_dashboard_data(code="sales")` reflects only their own pipeline unless `reports.sales.read.all` is also granted, a Payroll dashboard's employee-level detail is withheld from anyone lacking `reports.payroll.employee.read` even if they hold the department-aggregate `reports.payroll.read`, and `list_report_definitions` omits categories entirely rather than returning them redacted. Default deny extends to discoverability throughout this catalog, matching `REPORTS.md`'s own stated posture.

**Export size limits.** `download_report` enforces a hard ceiling per format, independent of the underlying `report_runs.row_count`: **PDF** is capped at 500 rendered pages - a report whose `document`-format layout would exceed that must be re-requested as `xlsx`/`csv` instead of rendered to PDF. **XLSX/CSV** are capped at 250,000 rows or 100 MB, whichever is reached first; a run whose result set exceeds this must be re-generated with narrower `params` (a shorter date range, an added filter, a coarser `group_by`) rather than exported as one file. **JSON** exports of result sets above 5,000 rows are always cursor-paginated via `meta.pagination.cursor`, never returned as one unbounded array, per the platform's own rule (`TOOLS_PROMPTS.md`, Edge Cases) that "a tool is never permitted to return an unbounded result directly into the model's context window." A call that would breach any of these ceilings fails with `EXPORT_TOO_LARGE` rather than silently truncating rows - a truncated financial export presented as complete is a worse failure mode than an explicit refusal.

**No sensitive-operations overlap.** None of the eight tools in this catalog appears on, or interacts with, the platform's fixed sensitive-operations list (`bank.transfer`, `payroll.release`, `tax.submit`, `accounting.journal.void`, `accounting.period.delete`, `admin.permissions.update`, `company.settings.update`, `ai.settings.update` - `AI_FINANCE_OS.md`, Human Approval). Reporting Tools is, by construction, the lowest-risk tool catalog in the platform: seven of eight tools cannot change a financial fact under any circumstance, and the eighth (`schedule_report`) can only ever create a *paused* standing job, never an active one, on its own initiative.

**Rate limiting and idempotency.** Heavy `generate_report` calls are subject to a per-company concurrent-queue ceiling (`429` beyond it, `Retry-After` honored). `schedule_report` carries the platform's standard `Idempotency-Key` (`ai-proposal-<uuid>`) plus a content-based dedup on `(report_definition_id, recipients, frequency)`, so a retried call after a timeout can never manufacture two schedules for the same intended job.

**AI narrative is additive, never authoritative.** Where `include_ai_summary=true` attaches a narrative to a `generate_report`/`get_report_run_status` result, that narrative is carried in a clearly separate `ai_summary`/`ai_metadata` field alongside - never merged into - the report's derived `columns`/`rows`/`footer`. This is the tool-layer enforcement of `REPORTS.md`'s Reporting Philosophy §7 ("AI analyzes; it never authors the audited number") and of the shared tool-use contract's rule that every factual claim in a narrative must resolve to a `source_refs`/`source_documents` entry this task's own tool calls actually produced.

# Error Handling

All eight tools return the platform's standard envelope and standard HTTP codes (`400`/`401`/`403`/`404`/`409`/`422`/`429`/`500`). The model-facing behavior on each is fixed by the shared tool-use contract (`TOOLS_PROMPTS.md`) and specialized here for Reporting's own error codes:

| Result | Required model behavior in this catalog |
|---|---|
| `403 FORBIDDEN_REPORT_CATEGORY` / `403 FORBIDDEN_DASHBOARD` | State the specific missing permission key by name (e.g. "you need reports.payroll.read"); do not retry with a different tool hoping for a looser check; do not omit the fact that data exists but is inaccessible - say plainly that it is restricted |
| `404` (definition, run, or schedule not found) | State that the referenced record does not exist or is outside the active company's scope; never substitute a similarly-named report found via a separate `list_report_definitions` call without confirming it is actually the one meant |
| `409` (run not yet completed for `download_report`; duplicate schedule for `schedule_report`; concurrent identical `generate_report`) | Re-fetch current state via `get_report_run_status` or `list_report_definitions` before deciding the next step; never blindly resubmit the identical call |
| `422` (invalid params, invalid cadence, missing required ratio inputs) | Read the specific `errors[]` entries, correct the named field, and retry the same tool once; if the second attempt also fails, stop and describe the discrepancy to the human rather than retrying again |
| `429 Too Many Requests` | Honor `Retry-After`; never immediately reissue a heavy `generate_report` call; back off exponentially if retried at all |
| `BALANCE_SHEET_UNBALANCED` / `409 BALANCE_SHEET_UNBALANCED` (from `get_financial_ratios`) | Treat as a data-integrity finding to disclose, not a transient error to retry - recommend the human investigate the underlying period rather than attempting `force_refresh` repeatedly |
| `EXPORT_TOO_LARGE` | Do not retry the identical export; either narrow `params` on a fresh `generate_report` call and export the smaller result, or switch to a cursor-paginated `json` export |
| `5xx` / timeout | The originating `ai_tasks` row is marked `failed`; because no report tool in this catalog persists a financial fact, a mid-run failure never leaves a partial or malformed ledger entry behind - at worst, an incomplete `report_runs` row that itself simply reads `failed` |

No tool in this catalog is ever permitted to fabricate a plausible-looking report row, KPI value, or ratio when its underlying call errors - per the shared contract's rule 8, an error is itself information to relay, never an invitation to guess a number that sounds right.

# Examples

**Scenario: "Generate the Q2 balance sheet for the board pack, and get me a PDF."** Mariam Al-Sabah (CFO, Al-Noor Trading & Contracting W.L.L., `company_id 4821`) asks the CFO Agent, which delegates the reporting-specific steps to the Reporting Agent's own tool access. The Reporting Agent already resolved `report_definition_id: 42` (`STD_BALANCE_SHEET`) for this company in an earlier turn this session, so it skips `list_report_definitions` and goes straight to generation.

**1. Generate.**

```json
{ "type": "tool_use", "id": "toolu_r1", "name": "generate_report", "input": { "report_definition_id": 42, "params": { "as_of_date": "2026-06-30", "comparison": "prior_year" }, "output_format": "document", "include_ai_summary": true } }
```

Balance Sheet is a `document`-format Standard Report over a full-quarter close for a three-branch, two-currency company - heavy enough to queue:

```json
{ "tool_use_id": "toolu_r1", "content": "{\"success\":true,\"data\":{\"run_id\":918290,\"status\":\"queued\",\"poll_url\":\"/api/v1/reports/runs/918290\"},\"message\":\"Report generation queued\"}" }
```

**2. Poll — first attempt, still running.**

```json
{ "type": "tool_use", "id": "toolu_r2", "name": "get_report_run_status", "input": { "run_id": 918290 } }
```

```json
{ "tool_use_id": "toolu_r2", "content": "{\"success\":true,\"data\":{\"run_id\":918290,\"status\":\"running\",\"queued_at\":\"2026-07-16T09:44:00Z\",\"started_at\":\"2026-07-16T09:44:02Z\"}}" }
```

Per this tool's own backoff instruction, the orchestrator waits 2 seconds before the next poll rather than immediately reissuing.

**3. Poll — second attempt, completed.**

```json
{ "type": "tool_use", "id": "toolu_r3", "name": "get_report_run_status", "input": { "run_id": 918290 } }
```

```json
{
  "tool_use_id": "toolu_r3",
  "content": "{\"success\":true,\"data\":{\"run_id\":918290,\"status\":\"completed\",\"as_of\":\"2026-06-30T23:59:59+03:00\",\"row_count\":34,\"duration_ms\":5480,\"footer\":{\"assets_total\":812400.0000,\"liabilities_plus_equity_total\":812400.0000,\"balanced\":true},\"ai_summary\":{\"text\":\"Total assets grew 8.4% year-over-year to KWD 812,400.000, driven by higher cash and receivables balances; liabilities held roughly flat, so the increase flows through to equity.\",\"confidence\":0.86,\"source_refs\":[\"report_runs.918290.rows[0..33]\",\"report_runs.918290.footer\"]}}}"
}
```

The agent's SELF-CHECK step confirms `footer.balanced: true` before proceeding - a `false` value here, or a `failed` status with `BALANCE_SHEET_UNBALANCED`, would have stopped the flow and surfaced the integrity finding to Mariam directly rather than continuing to export.

**4. Export.**

```json
{ "type": "tool_use", "id": "toolu_r4", "name": "download_report", "input": { "run_id": 918290, "format": "pdf" } }
```

```json
{
  "tool_use_id": "toolu_r4",
  "content": "{\"success\":true,\"data\":{\"run_id\":918290,\"format\":\"pdf\",\"download_url\":\"https://cdn.qayd.app/r2/exports/4821/918290-balance-sheet.pdf?sig=&exp=1752658200\",\"content_type\":\"application/pdf\",\"file_size_bytes\":241760,\"expires_at\":\"2026-07-16T10:04:00Z\",\"filename\":\"Balance_Sheet_2026-06-30.pdf\"},\"message\":\"Export ready\"}"
}
```

**5. Final assistant turn (not a tool call).** *"The Q2 Balance Sheet is ready - total assets of KWD 812,400.000, up 8.4% year-over-year, balanced (Assets = Liabilities + Equity to the fils). Here's the PDF: Balance_Sheet_2026-06-30.pdf, link valid for 15 minutes. Confidence on the year-over-year commentary is 0.86, based on the full set of posted lines behind this run - nothing here required anyone's approval, since generating and exporting a report never changes the books."*

Every step above is logged to `ai_logs` (four `tool_called` rows) and to this task's `ai_conversations` thread; no `ai_decisions` row was created at any point, because none of the four calls proposed a fact about the ledger - only about how to view one.

# Edge Cases

| Case | Handling |
|---|---|
| `generate_report` is called twice within the same task for the identical `(report_definition_id, params, as_of)` tuple | The second call is served from the existing `report_snapshots` freshness cache (default 15 minutes for financial-category reports) rather than re-executing the query, unless `force_refresh=true` is explicitly set |
| Two concurrent tasks call `generate_report` for the same heavy tuple at nearly the same moment | Both resolve to the same `run_id` rather than enqueuing two identical heavy jobs; the second caller's `generate_report` result carries the first caller's already-in-flight `run_id` |
| A `report_definition_id` is soft-deleted or archived between `list_report_definitions` and `generate_report` in the same task | `generate_report` returns `404`; the agent must re-resolve via `list_report_definitions` rather than assume the id is still valid from earlier in the transcript |
| A `report_schedules` row's underlying `report_definition_id` is later archived while the schedule is still active | The scheduler skips the occurrence, flips `report_schedules.is_active` to `false`, and the next `get_dashboard_data`/Today's Tasks-style surface reflects the schedule as paused with a reason, rather than failing silently on every future cadence tick |
| `get_kpi` is called with a `keys` array where every requested code is either unrecognized or fully permission-filtered away | Returns `200`, `data.kpis: []`, and a `message` distinguishing "no KPIs available for the requested keys" from a blanket denial, since some codes may simply not apply to this company (e.g. a manufacturing-only KPI for a pure trading company) |
| A report's underlying data crosses a fiscal-period lock boundary mid-generation (a period closes while a heavy `generate_report` run is still `running`) | The run completes against the `as_of` cutoff it was started with, per the platform's reproducibility guarantee; the newly closed period's figures are reflected only in the next fresh `generate_report` call, never retroactively mutated into the in-flight run |
| `download_report` is requested in a format the source `report_definitions.output_format` doesn't naturally support (e.g. `pdf` for a `widget`-format KPI tile definition) | Returns `422` naming the supported formats for that definition rather than attempting a best-effort render that would misrepresent the widget as a document |
| A Custom Report's saved `config` references a `report_data_sources` field the caller's current role can no longer see (a permission was narrowed after the report was built) | `generate_report` fails that column out of the result with a `partial_result: true` flag and a note identifying the withheld field, rather than silently omitting it indistinguishably from a column that was never selected |
| `schedule_report`'s `recipients` includes an `email`-type entry that does not match any known user or a company-approved external domain | The call still succeeds (creating the schedule `pending_approval`), but the response and the resulting `approval_requests` row flag that specific recipient for extra scrutiny, since it is exactly the unreviewed-external-distribution case the staging step exists to catch before a human activates it |
| `get_financial_ratios` is requested for a company that has never posted enough history to compute a flow-based ratio (e.g. a brand-new company with no prior-year Income Statement) | Point-in-time ratios (current ratio, quick ratio, cash ratio) are returned normally; flow-based ratios requiring a comparison period return `null` with a `reason: "insufficient_history"` per ratio, rather than a fabricated or divide-by-zero value |
| A Voice Assistant session calls `schedule_report` and the spoken confirmation is ambiguous or inaudible | Per `requires_verbal_confirm=true`, the call is not issued at all until an explicit, unambiguous "confirm" is captured; an unclear response re-prompts once rather than proceeding on a best guess |
| `download_report`'s signed URL expires before the requesting surface (e.g. a slow email digest render) actually fetches it | The consuming surface must call `download_report` again to mint a fresh URL; the platform does not extend an already-issued signed URL's expiry, by design, to keep the exposure window short and constant |
| An External Auditor role calls any tool in this catalog outside their time-boxed access window | Every call fails `403` identically to a permission the role never had at all - a time-boxed grant that has lapsed is, from this catalog's tools' point of view, indistinguishable from a grant that was never issued |

# End of Document
