# Accounting Tools — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: ACCOUNTING_TOOLS
---

# Purpose

QAYD's AI layer is not a chatbot with a search plugin bolted on top of an accounting system. It is an autonomous finance workforce, and a workforce needs hands before it needs judgment. `TOOLS_PROMPTS.md` specifies the mechanism that gives every agent its hands: the MCP tool-calling contract, the shared system-prompt block that governs when a tool may be called, and the `ai_tool_registry` table that is the single source of truth for what a tool *is*. This document is the per-module instantiation of that mechanism for the Accounting Engine specifically — the Chart of Accounts, Journal Entries, General Ledger, Trial Balance, and Financial Statements submodules. It enumerates every tool the FastAPI AI layer's `accounting-tools` MCP server publishes, in the exact form a model receives it (`name`, `description`, `input_schema`), together with the one Laravel `/api/v1` endpoint each tool wraps, the RBAC permission key that endpoint requires, whether the tool's effect is immediate, a proposal, or a human-gated execution, and a fully worked call-and-response example for each.

Per `TOOLS_PROMPTS.md`'s own words: "Per-module tool catalogs — `docs/ai/tools/ACCOUNTING_TOOLS.md`, `docs/ai/tools/BANKING_TOOLS.md`, `docs/ai/tools/PAYROLL_TOOLS.md`, `docs/ai/tools/AI_PLATFORM_TOOLS.md`, and siblings — enumerate every `ai_tool_registry` row belonging to that module in exactly the form shown above, one full JSON Schema per tool." This document is that enumeration for the Accounting module. Every other document that references an accounting tool — `ACCOUNTANT_AGENT.md`'s "Tools & API Access" table, `CFO_AGENT.md`'s read-only analysis toolbox, `AUDITOR_AGENT.md`'s Trial-Balance finding generation, the workflow documents under `docs/ai/workflows/*` — is a deliberately compressed, human-readable projection of the rows defined here. Where a compressed table elsewhere in the corpus and this document appear to disagree on a field name or a permission key, this document is authoritative, because it is the literal JSON a model's tool-calling turn is validated against before any network call is made.

Ten tools are catalogued: `search_accounts`, `get_account`, and `get_account_balance` (Chart of Accounts reads); `create_journal_entry`, `post_journal_entry`, and `reverse_journal_entry` (the Journal Entries write surface); `get_general_ledger`, `get_trial_balance`, and `get_financial_statement` (the three canonical report reads); and `list_journal_entries` (transactional search). Together they are the complete accounting toolbox available to the General Accountant, Auditor, CFO, Tax Advisor, Reporting Agent, Forecast Agent, and Compliance Agent — every agent whose mandate touches posted or proposed financial data reaches it exclusively through one of these ten names, never through a database credential, a raw SQL string, or a filesystem path. What is deliberately **not** in this catalog: anything that moves money (bank transfers live in `BANKING_TOOLS.md`), releases payroll (`PAYROLL_TOOLS.md`), submits a tax filing (`TAX_TOOLS.md`), or changes a permission or company setting (no tool anywhere in the platform does this) — these remain hard-forbidden to AI at every autonomy tier, platform-wide, regardless of confidence, exactly as `AI_FINANCE_OS.md` and every agent document state.

**A note on the autonomy column.** The Tool Catalog below tags every tool `auto`, `proposal`, or `approval`. These three values correspond one-to-one to the tiers each agent document's own "Autonomy Level" section already uses (for example `ACCOUNTANT_AGENT.md`'s "auto / suggest-only / requires-approval"): `auto` = a read executes immediately, no artifact is created and no human step is required; `proposal` = suggest-only, the tool writes a `draft` or an `ai_decisions` row that a human may act on or ignore; `approval` = requires-approval, meaning the underlying action is never reachable by an autonomous agent's own service-account credentials under any configuration, and the tool is documented here only because a model must still understand what happens after it hands a proposal to a human, and because the same MCP tool definition is the one a human-driven surface (a UI action, or the Approval Assistant relaying an explicit, synchronous human confirmation) calls under the human's own session. This is one naming convention describing one underlying mechanism, not two competing ones — see Safety & Guardrails for the precise mechanics of the `approval` tier.

**A note on schema provenance.** Every `input_schema` below is the literal JSON Schema (2020-12) an `ai_tool_registry` row carries in its `input_schema` column, reproduced exactly as a model would receive it inside the `tools` array of an Anthropic Messages API request (or the equivalent `function.parameters` of an OpenAI-style request, per the provider-projection rule in `TOOLS_PROMPTS.md`). The paired `output_schema` shown for each tool is this catalog's own documentation addition — MCP's `tools/list` result shape does not itself carry a response schema, so a tool's actual response shape is enforced downstream by the wrapped Laravel endpoint's own contract (`docs/api/API_OPENAPI_SPEC.md`), not by a client-side schema the model validates against before acting on a result. Documenting it here regardless gives an implementing engineer — and a model reasoning about what a call will return — one place to see both sides of the contract for every accounting tool without cross-referencing five separate module documents.

# Tool Catalog

| Tool | Description | Endpoint | Permission | Autonomy |
|---|---|---|---|---|
| `search_accounts` | Search/list the Chart of Accounts, filterable by nature, type, status, postability, and free text; tree or flat shape | `GET /api/v1/accounting/accounts` | `accounting.accounts.read` | auto |
| `get_account` | Fetch one account's full classification metadata by id | `GET /api/v1/accounting/accounts/{id}` | `accounting.accounts.read` | auto |
| `get_account_balance` | Compute an account's opening/period-activity/closing balance as of a date or fiscal period, optionally sliced by dimension | `GET /api/v1/accounting/accounts/{id}/balance` | `accounting.accounts.read` | auto |
| `create_journal_entry` | Draft a balanced `ai_generated` journal entry proposal from a document, event, or precedent — never posts | `POST /api/v1/accounting/journal-entries` | `accounting.journal.create` | proposal |
| `post_journal_entry` | Execute the Posting Engine transition on an already-approved (or approval-exempt) entry | `POST /api/v1/accounting/journal-entries/{id}/post` | `accounting.journal.post` | approval |
| `reverse_journal_entry` | Create and post a fully offsetting reversing entry against a posted entry | `POST /api/v1/accounting/journal-entries/{id}/reverse` | `accounting.journal.reverse` | approval |
| `get_general_ledger` | Fetch the formatted General Ledger report (opening balance, posted lines, running balance, closing balance) for one or more accounts over a period | `GET /api/v1/accounting/reports/general-ledger` | `accounting.report.read` | auto |
| `get_trial_balance` | Fetch the current (or a specific) Trial Balance snapshot — Unadjusted, Adjusted, or Post-Closing — for a scope and period | `GET /api/v1/accounting/trial-balance` | `accounting.trial_balance.read` | auto |
| `get_financial_statement` | Fetch an already-generated financial statement snapshot (Balance Sheet, Income Statement, Cash Flow, Changes in Equity, Comprehensive Income) | `GET /api/v1/financial-statements/{snapshot}` (or `GET /api/v1/financial-statements` to search) | `reports.financial_statements.read` | auto |
| `list_journal_entries` | Search/list journal entries by status, type, date range, account, counterparty, or source document | `GET /api/v1/accounting/journal-entries` | `accounting.journal.read` | auto |

# Tool Definitions

## search_accounts

**Description.** Searches the active company's Chart of Accounts. This is the entry point for almost every accounting task a model performs: before proposing a journal line, checking a balance, or explaining an anomaly, the agent must first resolve a free-text or partial concept ("the bank account," "utilities," "the AP control account") into a concrete `account_id`. `search_accounts` wraps the identical list endpoint the Next.js Chart of Accounts screen calls, filtered to whatever the requesting principal's RBAC context already permits — it never returns an account outside the active `company_id`, enforced by the same global model scope every human-facing query uses, per `CHART_OF_ACCOUNTS.md` §13.

**When to use.** Call this tool whenever a task references an account by name, code prefix, nature, or type rather than by a known numeric id; whenever validating that a candidate `account_id` a document or a prior tool result suggested actually exists, is active, and is postable before including it in a `create_journal_entry` line; or when building precedent for a categorization suggestion (e.g., "what accounts has this company already created under Operating Expenses"). Do not call it merely to re-confirm an `account_id` already returned in this same conversation turn by `get_account` — that is a wasted round trip; re-use the earlier result.

**Endpoint mapping.** `GET /api/v1/accounting/accounts` → `accounting.accounts.read` (per `CHART_OF_ACCOUNTS.md` §14.1, row 1). Registered in `ai_tool_registry` as:

```json
{
  "name": "search_accounts",
  "version": 1,
  "category": "read",
  "mcp_server": "accounting-tools",
  "endpoint_method": "GET",
  "endpoint_path": "/api/v1/accounting/accounts",
  "permission_key": "accounting.accounts.read",
  "produces_type": null,
  "max_calls_per_task": 20,
  "requires_verbal_confirm": false
}
```

**Input schema.**

```json
{
  "name": "search_accounts",
  "description": "Search the active company's Chart of Accounts. Returns accounts the caller's permissions allow, scoped to the active company only. Use `search` for a bilingual free-text match against name_en/name_ar/code; use `nature`, `account_type_code`, `status`, and `allow_posting` to narrow by classification; use `parent_id` to restrict to a subtree. Results are paginated; a caller expecting a specific single account should prefer get_account with a known id once one is resolved from a search result.",
  "input_schema": {
    "type": "object",
    "properties": {
      "search": { "type": "string", "maxLength": 120, "description": "Free-text match against code, name_en, and name_ar." },
      "nature": { "type": "string", "enum": ["asset", "liability", "equity", "revenue", "expense", "other_income", "other_expense"] },
      "account_type_code": { "type": "string", "maxLength": 50, "description": "e.g. 'bank', 'accounts_receivable', 'cost_of_goods_sold'." },
      "status": { "type": "string", "enum": ["active", "inactive", "archived"], "default": "active" },
      "allow_posting": { "type": "boolean", "description": "true = only leaf/postable accounts eligible for a journal line; false = only group/header accounts." },
      "is_control_account": { "type": "boolean" },
      "parent_id": { "type": "integer", "description": "Restrict results to descendants of this account." },
      "shape": { "type": "string", "enum": ["flat", "tree"], "default": "flat" },
      "page": { "type": "integer", "minimum": 1, "default": 1 },
      "per_page": { "type": "integer", "minimum": 1, "maximum": 100, "default": 25 }
    },
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" },
          "code": { "type": "string" },
          "name_en": { "type": "string" },
          "name_ar": { "type": "string" },
          "nature": { "type": "string" },
          "account_type": {
            "type": "object",
            "properties": {
              "code": { "type": "string" }, "name_en": { "type": "string" }, "name_ar": { "type": "string" }
            }
          },
          "normal_balance": { "type": "string", "enum": ["debit", "credit"] },
          "parent_id": { "type": ["integer", "null"] },
          "depth": { "type": "integer" },
          "allow_posting": { "type": "boolean" },
          "is_control_account": { "type": "boolean" },
          "status": { "type": "string" },
          "currency_code": { "type": ["string", "null"] },
          "tags": { "type": "array", "items": { "type": "string" } },
          "children": { "type": "array", "description": "Present only when shape='tree'." }
        }
      }
    },
    "message": { "type": "string" },
    "errors": { "type": "array" },
    "meta": { "type": "object", "properties": { "pagination": { "type": "object" } } },
    "request_id": { "type": "string" },
    "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.accounts.read`. **Human-approval flag.** No — read tool, executes immediately (`auto`).

**Errors.** `401 UNAUTHENTICATED` (missing/expired bearer token); `403 PERMISSION_DENIED` (principal lacks `accounting.accounts.read` — e.g. an Employee-scoped human session with no accounting role, per `CHART_OF_ACCOUNTS.md` §15.1); `422` on an invalid `nature`/`status` enum value; `429 RATE_LIMITED` if the calling principal exceeds the per-user/per-company throttle (see Error Handling).

**Worked example.**

```
GET /api/v1/accounting/accounts?search=utilities&nature=expense&allow_posting=true&status=active
X-Company-Id: 4821
Authorization: Bearer <AI service-account token, role: ai_general_accountant>
```

```json
{
  "success": true,
  "data": [
    {
      "id": 5340, "code": "5240.002", "name_en": "Utilities Expense", "name_ar": "مصاريف المرافق",
      "nature": "expense",
      "account_type": { "code": "operating_expense", "name_en": "Operating Expense", "name_ar": "مصروف تشغيلي" },
      "normal_balance": "debit", "parent_id": 5240, "depth": 3,
      "allow_posting": true, "is_control_account": false, "status": "active",
      "currency_code": null, "tags": ["recurring-monthly"]
    }
  ],
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "a1e6c9b0-1f2d-4a3e-9c11-8b7d6f5a4c30",
  "timestamp": "2026-06-28T07:41:02Z"
}
```

## get_account

**Description.** Retrieves one account's complete classification record by numeric id: bilingual names, `account_type`, `nature`, `normal_balance`, tree position, postability, control-account flag, currency restriction, tax category, and dimension defaults. Unlike the account-detail screen in the Next.js app, which shows metadata and a current balance together in one view, this catalog deliberately keeps `get_account` (identity/classification only) and `get_account_balance` (a specific as-of-date/period-scoped derived figure) as two single-purpose tools. A model that only needs to confirm "does account 55231 exist, is it active, and is it postable" should not have to pay for — or wait on — a balance-aggregation query it never asked for; a model that already knows the id and only wants the current balance should not re-fetch classification metadata it already holds from an earlier turn.

**When to use.** Call this after `search_accounts` has narrowed a concept to exactly one candidate id, or whenever a document, event payload, or a prior journal entry names an `account_id` the agent must validate before citing or proposing against it. Do not call it to discover an account by name — use `search_accounts` first.

**Endpoint mapping.** `GET /api/v1/accounting/accounts/{id}` → `accounting.accounts.read` (`CHART_OF_ACCOUNTS.md` §14.1).

**Input schema.**

```json
{
  "name": "get_account",
  "description": "Fetch one account's full classification record by id. Returns 404 ACCOUNT_NOT_FOUND if the id does not exist or does not belong to the active company — never leaks the existence of another tenant's account.",
  "input_schema": {
    "type": "object",
    "properties": {
      "account_id": { "type": "integer", "description": "The accounts.id to fetch." }
    },
    "required": ["account_id"],
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "id": { "type": "integer" }, "company_id": { "type": "integer" },
        "code": { "type": "string" }, "name_en": { "type": "string" }, "name_ar": { "type": "string" },
        "description": { "type": ["string", "null"] },
        "account_type_id": { "type": "integer" },
        "account_type": { "type": "object", "properties": { "code": {"type":"string"}, "name_en": {"type":"string"}, "name_ar": {"type":"string"} } },
        "nature": { "type": "string" }, "normal_balance": { "type": "string", "enum": ["debit", "credit"] },
        "parent_id": { "type": ["integer", "null"] }, "depth": { "type": "integer" }, "path": { "type": "string" },
        "allow_posting": { "type": "boolean" }, "is_control_account": { "type": "boolean" }, "is_system": { "type": "boolean" },
        "currency_code": { "type": ["string", "null"] }, "opening_balance": { "type": "string" },
        "status": { "type": "string", "enum": ["active", "inactive", "archived"] },
        "default_cost_center_id": { "type": ["integer", "null"] }, "default_department_id": { "type": ["integer", "null"] },
        "branch_id": { "type": ["integer", "null"] }, "tax_category": { "type": "string" },
        "tags": { "type": "array", "items": { "type": "string" } },
        "created_by": { "type": "integer" }, "created_at": { "type": "string" }, "updated_at": { "type": "string" }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.accounts.read`. **Human-approval flag.** No (`auto`).

**Errors.** `404 ACCOUNT_NOT_FOUND` — id does not exist, or belongs to a different `company_id` than the caller's active context (per `CHART_OF_ACCOUNTS.md` §14.2's error reference table, the platform returns the identical 404 for both cases specifically so a cross-tenant probe cannot distinguish "wrong id" from "exists, not yours"); `401`; `403`.

**Worked example.**

```
GET /api/v1/accounting/accounts/55231
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": {
    "id": 55231, "company_id": 4821, "code": "1101.003",
    "name_en": "Bank Accounts – NBK Current", "name_ar": "حسابات بنكية – البنك الوطني الجاري",
    "description": null, "account_type_id": 118,
    "account_type": { "code": "bank", "name_en": "Bank", "name_ar": "بنك" },
    "nature": "asset", "normal_balance": "debit",
    "parent_id": 1042, "depth": 3, "path": "1.11.1101.55231",
    "allow_posting": true, "is_control_account": false, "is_system": false,
    "currency_code": "KWD", "opening_balance": "12500.0000",
    "status": "active", "default_cost_center_id": null, "default_department_id": null, "branch_id": null,
    "tax_category": "none", "tags": ["operational-bank"],
    "created_by": 902, "created_at": "2026-01-11T08:03:22Z", "updated_at": "2026-06-02T11:47:09Z"
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "c7d2e310-9a44-4b81-9e2f-3a0d6c8b1f52", "timestamp": "2026-06-28T07:41:19Z"
}
```

## get_account_balance

**Description.** Computes a single account's derived balance as of a date or fiscal period, expressed as Balance Forward (opening), Period Activity (debit/credit movement within the window), and Closing Balance — the exact computation `GENERAL_LEDGER.md`'s "Accounting Concepts" section defines: `closing_balance(account, period) = balance_forward(account, period) + period_activity(account, period)`. Nothing here is a stored, independently-editable number; it is always derived from posted `journal_lines` at query time (or served from the `mv_account_balances` materialized cache for a closed period), so a balance this tool returns for a closed period is guaranteed reproducible byte-for-byte from the same postings, however many times it is asked.

**When to use.** Call this to answer "what is the current/period-end balance of account X" — the single most common grounding query before drafting a journal entry that touches that account, before flagging an abnormal-balance anomaly, or before answering a CFO's natural-language question about a specific account's trajectory. For a whole-ledger view across many accounts, prefer `get_general_ledger` or `get_trial_balance` instead of calling this tool in a loop over every account id.

**Endpoint mapping.** `GET /api/v1/accounting/accounts/{id}/balance` → `accounting.accounts.read` (`CHART_OF_ACCOUNTS.md` §14.1, row 5).

**Input schema.**

```json
{
  "name": "get_account_balance",
  "description": "Compute an account's balance as of a date or fiscal period: opening (balance-forward), period activity, and closing balance, in the account's normal-balance terms. Optionally filter by branch/cost-center/project/department dimension. Returns is_abnormal_balance=true when the computed closing side differs from the account's normal_balance side, which itself does not indicate an error but is a signal worth surfacing.",
  "input_schema": {
    "type": "object",
    "properties": {
      "account_id": { "type": "integer" },
      "as_of_date": { "type": "string", "format": "date", "description": "Mutually exclusive with fiscal_period_id; defaults to today if neither is supplied." },
      "fiscal_period_id": { "type": "integer" },
      "branch_id": { "type": ["integer", "null"] },
      "cost_center_id": { "type": ["integer", "null"] },
      "project_id": { "type": ["integer", "null"] },
      "department_id": { "type": ["integer", "null"] },
      "include_currency_breakdown": { "type": "boolean", "default": false, "description": "Include a per-transaction-currency subtotal array alongside the base-currency figure." }
    },
    "required": ["account_id"],
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "account_id": { "type": "integer" }, "code": { "type": "string" },
        "name_en": { "type": "string" }, "name_ar": { "type": "string" },
        "normal_balance": { "type": "string", "enum": ["debit", "credit"] },
        "as_of_date": { "type": "string" }, "fiscal_period_id": { "type": ["integer", "null"] },
        "opening_balance": { "type": "object", "properties": { "amount": {"type":"string"}, "side": {"type":"string"} } },
        "period_activity": { "type": "object", "properties": { "debit": {"type":"string"}, "credit": {"type":"string"} } },
        "closing_balance": { "type": "object", "properties": { "amount": {"type":"string"}, "side": {"type":"string"} } },
        "is_abnormal_balance": { "type": "boolean" },
        "currency_code": { "type": "string" },
        "currency_breakdown": { "type": ["array", "null"] },
        "dimension_filters_applied": { "type": "object" }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.accounts.read`. **Human-approval flag.** No (`auto`).

**Errors.** `404 ACCOUNT_NOT_FOUND`; `422 DIMENSION_INVALID` (a supplied `cost_center_id`/`project_id`/`department_id`/`branch_id` does not exist or does not belong to the active company, mirroring `CHART_OF_ACCOUNTS.md` VR-8); `422 PERIOD_UNRESOLVABLE` if `fiscal_period_id` does not exist for this company; `403`.

**Worked example.**

```
GET /api/v1/accounting/accounts/1131/balance?fiscal_period_id=812
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": {
    "account_id": 1131, "code": "1130.001", "name_en": "Trade Receivables", "name_ar": "ذمم مدينة تجارية",
    "normal_balance": "debit", "as_of_date": "2026-06-30", "fiscal_period_id": 812,
    "opening_balance": { "amount": "68420.0000", "side": "debit" },
    "period_activity": { "debit": "24150.0000", "credit": "19875.5000" },
    "closing_balance": { "amount": "72694.5000", "side": "debit" },
    "is_abnormal_balance": false, "currency_code": "KWD", "currency_breakdown": null,
    "dimension_filters_applied": {}
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "e4a90c11-6d3b-49a2-8c70-2f1e5d9b6a44", "timestamp": "2026-06-28T07:41:37Z"
}
```

## create_journal_entry

**Description.** Drafts a balanced journal entry proposal. This is the single write endpoint that turns the AI layer's read-and-reason work into a concrete accounting artifact — but the artifact is always `journal_entries.status = 'draft'`, `entry_type = 'ai_generated'`, never anything closer to posted. It is registered in `ai_tool_registry` under the name `propose_journal_entry` (the exact row `TOOLS_PROMPTS.md` §"Tool Definition Format" specifies in full); this catalog labels the row `create_journal_entry` to match the verb the task that produced this document requested, and to read naturally alongside its sibling `post_journal_entry`/`reverse_journal_entry`. The two names refer to the identical `ai_tool_registry` row, endpoint, schema, and permission key — there is no second tool. A database `CHECK` constraint (`trg_no_ai_autopost` on `journal_entries`, specified in `GENERAL_LEDGER.md` Business Rule 14 and restated as `chk_ai_draft_requires_review` in `ACCOUNTANT_AGENT.md` §"Autonomy Level") refuses at the PostgreSQL engine level to let any row where `created_by_agent IS NOT NULL` land in a status other than `draft` directly — this is the third of three independent layers (network isolation, RBAC, database constraint) that make "AI never posts" true by construction rather than by convention.

**When to use.** Call this only after retrieving and citing at least one source document, domain event, or historical pattern via a read tool earlier in the same task — never speculatively, and never to "fill a gap" in a line item with an invented amount or account. Typical triggers: an OCR'd bill or receipt with no matching Purchasing record (`document.ocr_completed`); an unmatched bank statement line (`bank.synced`); a recurring accrual whose due date has arrived (`journal_entry_templates`); or a Trial-Balance/Auditor finding that resolves to a specific correcting or reclassifying entry. Before calling it, the agent should have already used `search_accounts`/`get_account` to confirm every `account_id` it intends to reference exists, is `active`, and is `allow_posting = true`, since the tool call itself will fail validation otherwise.

**Endpoint mapping.** `POST /api/v1/accounting/journal-entries` → `accounting.journal.create` (`JOURNAL_ENTRIES.md` "API" table, row 1). This is the exact endpoint a human accountant's own "New Journal Entry" screen calls; the only distinguishing factor for an AI-authored call is `entry_type: "ai_generated"` and the required `meta` block below — there is no separate, privileged "AI-only" endpoint.

**Input schema.** Reproduced from `TOOLS_PROMPTS.md` §"Tool Definition Format" without modification, since that document is this tool's canonical worked example and this catalog must not diverge from it:

```json
{
  "name": "propose_journal_entry",
  "description": "Create a draft journal entry proposal (entry_type='ai_generated', status='draft') in the Accounting Engine. This tool never posts, approves, or submits an entry — it stages a proposal for human review only. The sum of all `debit` values must equal the sum of all `credit` values exactly; the platform independently re-verifies this arithmetic before persisting and rejects any call where it does not hold. Call this only after retrieving and citing at least one source document or transaction via a read tool this turn — do not call it speculatively.",
  "input_schema": {
    "type": "object",
    "properties": {
      "entry_type": { "type": "string", "enum": ["ai_generated"] },
      "journal_date": { "type": "string", "format": "date", "description": "ISO-8601 date within the currently open fiscal period." },
      "memo": { "type": "string", "maxLength": 240 },
      "lines": {
        "type": "array",
        "minItems": 2,
        "items": {
          "type": "object",
          "properties": {
            "account_id": { "type": "integer" },
            "debit": { "type": "string", "pattern": "^\\d+(\\.\\d{1,4})?$" },
            "credit": { "type": "string", "pattern": "^\\d+(\\.\\d{1,4})?$" },
            "customer_id": { "type": ["integer", "null"] },
            "vendor_id": { "type": ["integer", "null"] },
            "cost_center_id": { "type": ["integer", "null"] },
            "project_id": { "type": ["integer", "null"] },
            "description": { "type": "string", "maxLength": 160 }
          },
          "required": ["account_id", "description"],
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
            "type": "array",
            "minItems": 1,
            "items": {
              "type": "object",
              "properties": { "type": { "type": "string" }, "id": { "type": "integer" } },
              "required": ["type", "id"],
              "additionalProperties": false
            }
          }
        },
        "required": ["ai_generated", "confidence", "reasoning", "source_documents"],
        "additionalProperties": false
      }
    },
    "required": ["entry_type", "journal_date", "lines", "meta"],
    "additionalProperties": false
  }
}
```

`meta.confidence`, `meta.reasoning`, and `meta.source_documents` are `required` in the schema itself, not merely encouraged in prose — a call missing any of them fails client-side schema validation inside the MCP tool server before a request ever reaches Laravel (see Error Handling). This is the JSON-Schema-level enforcement of the identical invariant Laravel's own `FormRequest` enforces one layer downstream (`422 ai_reasoning_required`) — two independent layers checking the same rule, neither trusting the other alone.

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "id": { "type": "integer" }, "company_id": { "type": "integer" },
        "journal_number": { "type": "string", "description": "Provisional at draft time; the real sequence number is assigned only at posting." },
        "journal_date": { "type": "string" }, "entry_type": { "type": "string", "const": "ai_generated" },
        "status": { "type": "string", "const": "draft" },
        "total_debit": { "type": "string" }, "total_credit": { "type": "string" },
        "currency_code": { "type": "string" },
        "ai_confidence": { "type": "string" }, "ai_conversation_id": { "type": "integer" },
        "created_by": { "type": "integer", "description": "The AI service-account's own users.id — never the human who configured the agent." },
        "created_at": { "type": "string" }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.journal.create`. **Human-approval flag.** Yes, downstream: every `entry_type = 'ai_generated'` draft requires a minimum of one approval level before it can post, at any amount, with no exception — `JOURNAL_ENTRIES.md`'s Approval Workflow table states this as the one entry-type category that is never approval-exempt regardless of value. The tool call itself does not require approval to execute (drafting has zero financial effect), but nothing it produces can become a posted fact without a human passing it through `submit` → `approve` → the Posting Engine.

**Errors.** `422 ai_reasoning_required` — `meta` block absent or incomplete; `422` — `SUM(debit) ≠ SUM(credit)` (re-verified server-side regardless of client arithmetic, per the Posting Engine's own re-derivation rule); `422 CONTROL_ACCOUNT_DIRECT_POST_FORBIDDEN` — a line targets a control account (e.g. Trade Receivables, Trade Payables) without a `customer_id`/`vendor_id`, per `JOURNAL_ENTRIES.md` Business Rule 5; `422` — a referenced `account_id` is not `allow_posting = true` or not `status = active`; `403 PERMISSION_DENIED`; `409` — an `Idempotency-Key` collision with a different payload than the original call (see Error Handling).

**Worked example.** MEW (Kuwait's Ministry of Electricity, Water & Renewable Energy) June electricity bill for company 4821, OCR'd with no matching Purchasing record:

```
POST /api/v1/accounting/journal-entries
X-Company-Id: 4821
Idempotency-Key: ai-proposal-3f9c2a10-7e4b-4c9d-8a1f-6b2e0d5c7f19
Authorization: Bearer <AI service-account token, role: ai_general_accountant>

{
  "entry_type": "ai_generated",
  "journal_date": "2026-06-28",
  "memo": "MEW electricity — June 2026, unmatched to any open Purchasing record",
  "lines": [
    { "account_id": 5340, "debit": "214.5000", "description": "MEW electricity, invoice MEW-2026-06-114477, June 2026" },
    { "account_id": 2110, "credit": "214.5000", "vendor_id": 9010, "description": "AP accrual — Ministry of Electricity, Water & Renewable Energy" }
  ],
  "meta": {
    "ai_generated": true, "confidence": 0.91,
    "reasoning": "OCR'd invoice MEW-2026-06-114477 (KWD 214.500, dated 2026-06-25) has no corresponding bill in Purchasing. Vendor 'Ministry of Electricity, Water & Renewable Energy' (vendor_id 9010) matched at similarity 0.99 against the OCR'd payee field. This vendor's utility invoices have posted to account 5340 (Utilities Expense) in 11 of the last 11 occurrences over the past 12 months, with no exceptions.",
    "source_documents": [{ "type": "attachment", "id": 61190 }, { "type": "vendor", "id": 9010 }]
  }
}
```

```json
{
  "success": true,
  "data": {
    "id": 903581, "company_id": 4821, "journal_number": "JE-2026-DRAFT-903581",
    "journal_date": "2026-06-28", "entry_type": "ai_generated", "status": "draft",
    "total_debit": "214.5000", "total_credit": "214.5000", "currency_code": "KWD",
    "ai_confidence": "0.9100", "ai_conversation_id": 88602,
    "created_by": 21, "created_at": "2026-06-28T07:42:05Z"
  },
  "message": "Journal entry draft created successfully", "errors": [], "meta": { "pagination": null },
  "request_id": "9c1a0e40-2b7d-4f5a-8e91-7d3c6b0a2e91", "timestamp": "2026-06-28T07:42:05Z"
}
```

## post_journal_entry

**Description.** Executes the Posting Engine's `approved` → `posted` (or approval-exempt `draft` → `posted`) transition: re-verifies the balance invariant from the `journal_lines` table itself (never trusting cached header totals), re-checks that the target fiscal period is still `open`, assigns the entry's real `journal_number`, writes one `ledger_entries` row per line, and locks the entry against all further mutation. This is the one action in the entire Journal Entries lifecycle that makes a number real — before this call, an entry is a draft or an approved-but-inert record; after it, the entry is permanent financial history reflected in every balance, Trial Balance, and financial statement in the company. Per `ACCOUNTANT_AGENT.md` §"Tools & API Access," this tool is listed there with "Granted? **No — human-only**" for the General Accountant Agent, and every other agent document in the roster grants it identically: no AI service-account, at any company, under any autonomy configuration, is ever issued the `accounting.journal.post` permission.

**When to use.** This tool is documented here for schema completeness — so a model reasoning about the lifecycle of its own `create_journal_entry` proposal understands precisely what happens next, and so any client (human-driven UI, or the Approval Assistant relaying an explicit, synchronous human confirmation) that legitimately calls the underlying Laravel endpoint through the same MCP contract has one authoritative schema to call against. No autonomous agent should ever include this tool in a self-initiated plan; if a task requires an entry to be posted, the correct action is to leave the draft in the queue (visible via `list_journal_entries`) and tell the human reviewer it is ready for submission, never to attempt this call itself.

**Endpoint mapping.** `POST /api/v1/accounting/journal-entries/{id}/post` → `accounting.journal.post` (`JOURNAL_ENTRIES.md` "API" table).

**Input schema.** The schema requires an explicit, machine-checkable confirmation object rather than a bare id, so that "a human approved this" is a structural property of the call, not an assumption:

```json
{
  "name": "post_journal_entry",
  "description": "Post an approved (or approval-exempt draft) journal entry, transitioning it to status='posted' and writing its ledger_entries projection. This tool is never included in the tool list assembled for an autonomous agent's model context (see Tool Selection & Routing, TOOLS_PROMPTS.md); it is only invoked under a human's own authenticated session as the direct execution of an explicit, in-conversation confirmation, never as a step a model chooses on its own initiative.",
  "input_schema": {
    "type": "object",
    "properties": {
      "journal_entry_id": { "type": "integer" },
      "confirmation": {
        "type": "object",
        "properties": {
          "confirmed_by_user_id": { "type": "integer", "description": "The human principal's own users.id — never an AI service-account id." },
          "confirmation_statement": { "type": "string", "const": "POST" }
        },
        "required": ["confirmed_by_user_id", "confirmation_statement"],
        "additionalProperties": false
      }
    },
    "required": ["journal_entry_id", "confirmation"],
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "id": { "type": "integer" }, "journal_number": { "type": "string" },
        "status": { "type": "string", "const": "posted" },
        "posted_by": { "type": "integer" }, "posted_at": { "type": "string" },
        "locked": { "type": "boolean", "const": true },
        "ledger_entries_created": { "type": "integer" }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.journal.post`. **Human-approval flag.** Yes — structurally: this action IS the approval-gated execution itself, not merely a step that requires a prior approval. It carries `autonomy: approval` in the Tool Catalog, meaning no autonomous agent identity is ever granted the permission key that gates it, at any company, under any configuration.

**Errors.** `404 ACCOUNT_NOT_FOUND`-equivalent (`JOURNAL_ENTRY_NOT_FOUND`) if the id does not exist or belongs to another company; `409` — `status` is not `approved` or approval-exempt `draft` (e.g., already `posted`, `pending_approval`, or `rejected`); `409` — the resolved `fiscal_period_id` is no longer `open` (a draft can sit for days; if its period locks before posting, the caller must re-date it into the current open period rather than force it through); `422` — the re-verified `SUM(debit) ≠ SUM(credit)` from the lines table (should be structurally impossible past `create_journal_entry`'s own guard, but re-checked as defense in depth); `403 PERMISSION_DENIED` — the overwhelmingly common outcome for any AI-service-account caller, by design.

**Worked example.** A Senior Accountant reviews and approves draft `903581` from the prior example in the Next.js app; the UI's "Post" button calls this same endpoint under the accountant's own session:

```
POST /api/v1/accounting/journal-entries/903581/post
X-Company-Id: 4821
Authorization: Bearer <human user token, role: senior_accountant>

{ "journal_entry_id": 903581, "confirmation": { "confirmed_by_user_id": 1187, "confirmation_statement": "POST" } }
```

```json
{
  "success": true,
  "data": {
    "id": 903581, "journal_number": "JE-2026-06-004821",
    "status": "posted", "posted_by": 1187, "posted_at": "2026-06-28T14:03:51Z",
    "locked": true, "ledger_entries_created": 2
  },
  "message": "Journal entry posted successfully", "errors": [], "meta": { "pagination": null },
  "request_id": "5b8f1c20-4e6a-4d2b-9f01-1a3c8e7b6d40", "timestamp": "2026-06-28T14:03:51Z"
}
```

## reverse_journal_entry

**Description.** Creates and **posts**, in one atomic action, a new journal entry that is the exact debit/credit mirror image of an already-posted entry, then flips the original's `status` to `reversed` (or, for a same-day, same-period reversal, `voided` — a cosmetic distinction only; the original fiscal period is unaffected either way). Unlike `create_journal_entry`, which only ever stages an inert `draft`, calling this tool has an immediate, irreversible financial effect: the reversing entry it creates is born `posted`, not `draft`, because a reversal's entire purpose is to take financial effect immediately alongside the original it offsets. That is precisely why it sits in the same `approval` tier as `post_journal_entry` rather than the `proposal` tier `create_journal_entry` occupies, even though on the surface both "create a journal entry." `ACCOUNTANT_AGENT.md` §"Tools & API Access" documents this exact asymmetry: `reverse_journal_entry` is listed "Granted? **No — human-only**... Agent may only emit a `accountant_reclassification` recommendation" — the agent's actual contribution to a correction is a `create_journal_entry` draft of a fresh adjusting entry (or an `ai_decisions` recommendation naming the entry that needs reversing), never a direct call to this endpoint.

**When to use.** As with `post_journal_entry`, this tool exists in the catalog for schema completeness and for the human-driven surfaces that legitimately call it (a Finance Manager or CFO's own session, since `JOURNAL_ENTRIES.md`'s Approval Workflow table requires "Any manual reversal of a posted entry" to itself pass one further approval level regardless of the original entry's amount). An autonomous agent that identifies a posted entry as wrong should draft a *fresh* correcting or reclassifying entry via `create_journal_entry` and/or write an `accountant_reclassification` decision — never attempt this call.

**Endpoint mapping.** `POST /api/v1/accounting/journal-entries/{id}/reverse` → `accounting.journal.reverse` (`JOURNAL_ENTRIES.md` "API" table).

**Input schema.**

```json
{
  "name": "reverse_journal_entry",
  "description": "Reverse a posted journal entry: creates a new, fully offsetting posted entry and flips the original to status='reversed' (or 'voided' for a same-period, same-day reversal). Never available to an autonomous agent's own service-account role. An entry that is itself a system-generated reversal (is_reversal=true) cannot be reversed again by this tool — correcting a bad reversal requires a fresh adjusting entry via create_journal_entry, never a chain of reversals-of-reversals.",
  "input_schema": {
    "type": "object",
    "properties": {
      "journal_entry_id": { "type": "integer", "description": "The posted entry to reverse." },
      "reason": { "type": "string", "minLength": 10, "maxLength": 240 },
      "same_period": { "type": "boolean", "default": false, "description": "true = same-day, same-period reversal (original becomes 'voided'); false = cross-period reversal (original becomes 'reversed')." },
      "reversal_date": { "type": "string", "format": "date", "description": "Required when same_period=false; must be on or after the original journal_date and within an open fiscal period." },
      "confirmation": {
        "type": "object",
        "properties": {
          "confirmed_by_user_id": { "type": "integer" },
          "confirmation_statement": { "type": "string", "const": "REVERSE" }
        },
        "required": ["confirmed_by_user_id", "confirmation_statement"],
        "additionalProperties": false
      }
    },
    "required": ["journal_entry_id", "reason", "confirmation"],
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "original_entry_id": { "type": "integer" },
        "original_status": { "type": "string", "enum": ["reversed", "voided"] },
        "reversal_entry_id": { "type": "integer" },
        "reversal_status": { "type": "string", "const": "posted" },
        "lines_reversed": { "type": "integer" }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.journal.reverse`. **Human-approval flag.** Yes — same structural meaning as `post_journal_entry`: this call itself is the gated, human-only execution, and per the Approval Workflow table it additionally requires its own approval level (Finance Manager or CFO) beyond the original entry's own approval history, regardless of amount.

**Errors.** `404`; `409 REVERSAL_OF_REVERSAL_FORBIDDEN` — the target entry has `is_reversal = true` (`JOURNAL_ENTRIES.md` Locking Rule 7); `422` — `reversal_date` precedes the original `journal_date`, or falls in a `closed`/`locked` period; `403 PERMISSION_DENIED`.

**Worked example.** The Finance Manager discovers the MEW entry above was accidentally double-posted from a duplicate OCR job, and reverses the duplicate (entry `903582`) in the following period:

```
POST /api/v1/accounting/journal-entries/903582/reverse
X-Company-Id: 4821
Authorization: Bearer <human user token, role: finance_manager>

{
  "journal_entry_id": 903582,
  "reason": "Duplicate OCR ingestion of invoice MEW-2026-06-114477; original correctly posted as JE-2026-06-004821.",
  "same_period": false,
  "reversal_date": "2026-07-02",
  "confirmation": { "confirmed_by_user_id": 1204, "confirmation_statement": "REVERSE" }
}
```

```json
{
  "success": true,
  "data": {
    "original_entry_id": 903582, "original_status": "reversed",
    "reversal_entry_id": 903701, "reversal_status": "posted", "lines_reversed": 2
  },
  "message": "Journal entry reversed successfully", "errors": [], "meta": { "pagination": null },
  "request_id": "0d6a2f31-8c5e-4b90-9a72-4f1e6b8c3d05", "timestamp": "2026-07-02T09:12:40Z"
}
```

## get_general_ledger

**Description.** Fetches the formatted General Ledger report for one or more accounts over a period: opening balance, every contributing posted line in chronological order with a running balance, and a closing balance — the report view of the same `ledger_entries` projection `get_account_balance` aggregates into a single figure. Where `get_account_balance` answers "what is the number," `get_general_ledger` answers "show me every posting that produced it," which is the natural next call when a human or another agent asks "why" rather than "what." This is distinct from the `search_ledger_entries` tool documented in `ACCOUNTANT_AGENT.md` §"Tools & API Access" (`GET /api/v1/accounting/ledger-entries/search`, permission `accounting.read`): that tool returns raw, unformatted `ledger_entries` rows clustered by source or counterparty for pattern-matching and duplicate detection; `get_general_ledger` returns the same underlying rows pre-shaped into the opening/lines/closing report structure a human account statement expects, with running balances computed server-side rather than left for the model to sum.

**When to use.** Call this to explain movement in a specific account over a period ("why did Trade Payables jump in May"), to gather posting precedent before drafting a categorization ("what account has this vendor's invoices historically posted to, and at what amounts"), or to assemble the Notes-level detail behind a Financial Statement line a human has asked to drill into. For a single point-in-time balance with no need to see the individual lines, prefer `get_account_balance`; for a whole-company balance-check across every account, prefer `get_trial_balance`.

**Endpoint mapping.** `GET /api/v1/accounting/reports/general-ledger` → `accounting.report.read` (`GENERAL_LEDGER.md` "API" table: "General Ledger report for one/many accounts").

**Input schema.**

```json
{
  "name": "get_general_ledger",
  "description": "Fetch the General Ledger report for one or more accounts over a period or date range: opening balance, chronologically ordered posted lines with running balance, and closing balance. Only posted journal_lines are included — draft, pending_approval, rejected entries are excluded by construction, not by a filter the caller must remember to apply.",
  "input_schema": {
    "type": "object",
    "properties": {
      "account_ids": { "type": "array", "items": { "type": "integer" }, "minItems": 1, "maxItems": 50 },
      "period_start": { "type": "string", "format": "date" },
      "period_end": { "type": "string", "format": "date" },
      "fiscal_period_id": { "type": "integer", "description": "Alternative to period_start/period_end." },
      "branch_id": { "type": ["integer", "null"] },
      "cost_center_id": { "type": ["integer", "null"] },
      "project_id": { "type": ["integer", "null"] },
      "department_id": { "type": ["integer", "null"] },
      "include_zero_activity": { "type": "boolean", "default": false },
      "presentation_currency": { "type": "string", "description": "ISO 4217; defaults to company base currency." }
    },
    "required": ["account_ids"],
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "period_start": { "type": "string" }, "period_end": { "type": "string" },
        "accounts": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "account_id": { "type": "integer" }, "code": { "type": "string" },
              "name_en": { "type": "string" }, "name_ar": { "type": "string" },
              "opening_balance": { "type": "object", "properties": { "amount": {"type":"string"}, "side": {"type":"string"} } },
              "lines": {
                "type": "array",
                "items": {
                  "type": "object",
                  "properties": {
                    "journal_entry_id": { "type": "integer" }, "journal_number": { "type": "string" },
                    "entry_date": { "type": "string" }, "description": { "type": "string" },
                    "debit": { "type": "string" }, "credit": { "type": "string" },
                    "running_balance": { "type": "string" },
                    "source_type": { "type": ["string", "null"] }, "source_id": { "type": ["integer", "null"] }
                  }
                }
              },
              "period_debit_total": { "type": "string" }, "period_credit_total": { "type": "string" },
              "closing_balance": { "type": "object", "properties": { "amount": {"type":"string"}, "side": {"type":"string"} } }
            }
          }
        }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.report.read`. **Human-approval flag.** No (`auto`).

**Errors.** `404` — one or more `account_ids` do not exist or do not belong to the active company (the whole call fails rather than silently dropping the unknown id, so the caller never mistakes a partial result for a complete one); `422` — `period_end` before `period_start`, or an unresolvable `fiscal_period_id`; `403`.

**Worked example.**

```
GET /api/v1/accounting/reports/general-ledger?account_ids[]=1131&period_start=2026-06-01&period_end=2026-06-30
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": {
    "period_start": "2026-06-01", "period_end": "2026-06-30",
    "accounts": [
      {
        "account_id": 1131, "code": "1130.001", "name_en": "Trade Receivables", "name_ar": "ذمم مدينة تجارية",
        "opening_balance": { "amount": "68420.0000", "side": "debit" },
        "lines": [
          { "journal_entry_id": 902114, "journal_number": "JE-2026-06-004790", "entry_date": "2026-06-03",
            "description": "Invoice INV-2026-3301 — Al Fanar Trading Co.", "debit": "4200.0000", "credit": "0.0000",
            "running_balance": "72620.0000", "source_type": "invoice", "source_id": 3301 },
          { "journal_entry_id": 902377, "journal_number": "JE-2026-06-004802", "entry_date": "2026-06-14",
            "description": "Receipt RC-2026-1187 — Al Fanar Trading Co.", "debit": "0.0000", "credit": "4200.0000",
            "running_balance": "68420.0000", "source_type": "receipt", "source_id": 1187 }
        ],
        "period_debit_total": "24150.0000", "period_credit_total": "19875.5000",
        "closing_balance": { "amount": "72694.5000", "side": "debit" }
      }
    ]
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "2f7b9e11-5a4c-4e8d-9b02-6d3a1c8e0f57", "timestamp": "2026-06-28T07:43:12Z"
}
```

## get_trial_balance

**Description.** Fetches an existing Trial Balance snapshot — the header totals and, optionally, the full per-account line set — for a scope (company, or a branch/department/project/cost-center filter) and a period or as-of date, defaulting to the current, continuously-refreshed Unadjusted Trial Balance (`type = 'unadjusted'`, `is_current = true`). `TRIAL_BALANCE.md`'s Generate workflow notes that generation is triggered by "(a) a user action, (b) a scheduled job (e.g., nightly generation of the current month's live UTB for dashboarding), or (c) an automatic trigger at fiscal-period close" — the nightly job means a reasonably fresh `is_current` snapshot ordinarily already exists for the open period by the time an agent asks for one, without the agent itself ever triggering generation.

**When to use.** Call this to answer "is the ledger balanced right now," to retrieve the Accountant's or Auditor's starting reference for period-close review, or to check for open `trial_balance_ai_findings` (imbalances, suspense-account non-zero balances, control-account mismatches) before recommending a correcting entry. This tool never calls `POST /trial-balance/generate` itself — `accounting.trial_balance.generate` is never granted to an AI service-account (`TRIAL_BALANCE.md` §"Permissions" role matrix: "No" for every AI Agent row except `.read` and `.history.read`) — so if no snapshot exists yet for the requested scope/period, the tool returns a structured not-yet-generated result rather than attempting to create one (see Edge Cases).

**Endpoint mapping.** `GET /api/v1/accounting/trial-balance` → `accounting.trial_balance.read` (`TRIAL_BALANCE.md` "API" table: base path stated as "All endpoints are under `/api/v1/accounting/trial-balance`"). Fetching a specific snapshot by id uses the same permission against `GET /api/v1/accounting/trial-balance/{id}`.

**Input schema.**

```json
{
  "name": "get_trial_balance",
  "description": "Fetch a Trial Balance snapshot (Unadjusted, Adjusted, or Post-Closing) for a scope and period. Defaults to the current snapshot (is_current=true) of type='unadjusted' for the requested scope/period if type is omitted. This tool only reads existing snapshots — it never triggers generation; accounting.trial_balance.generate is never granted to an AI agent identity.",
  "input_schema": {
    "type": "object",
    "properties": {
      "snapshot_id": { "type": "integer", "description": "Fetch one exact snapshot by id, bypassing all other filters." },
      "type": { "type": "string", "enum": ["unadjusted", "adjusted", "post_closing"], "default": "unadjusted" },
      "fiscal_period_id": { "type": "integer" },
      "as_of_date": { "type": "string", "format": "date" },
      "branch_id": { "type": ["integer", "null"] },
      "department_id": { "type": ["integer", "null"] },
      "project_id": { "type": ["integer", "null"] },
      "cost_center_id": { "type": ["integer", "null"] },
      "include_lines": { "type": "boolean", "default": false, "description": "Include the full per-account line array; false returns header totals only." }
    },
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "id": { "type": "integer" }, "type": { "type": "string" }, "status": { "type": "string" },
        "as_of_date": { "type": "string" }, "fiscal_period_id": { "type": ["integer", "null"] },
        "is_current": { "type": "boolean" }, "version": { "type": "integer" },
        "total_debit": { "type": "string" }, "total_credit": { "type": "string" }, "variance": { "type": "string" },
        "has_warnings": { "type": "boolean" }, "account_count": { "type": "integer" },
        "generated_at": { "type": "string" },
        "lines": {
          "type": ["array", "null"],
          "items": {
            "type": "object",
            "properties": {
              "account_id": { "type": "integer" }, "account_code": { "type": "string" },
              "account_name_en": { "type": "string" }, "account_name_ar": { "type": "string" },
              "normal_balance": { "type": "string" },
              "opening_debit": { "type": "string" }, "opening_credit": { "type": "string" },
              "period_debit": { "type": "string" }, "period_credit": { "type": "string" },
              "closing_debit": { "type": "string" }, "closing_credit": { "type": "string" },
              "is_abnormal_balance": { "type": "boolean" }
            }
          }
        }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.trial_balance.read`. **Human-approval flag.** No (`auto`) — reading an existing snapshot has no financial effect; note that generating a *new* snapshot is a separate action this tool never performs.

**Errors.** `404 TRIAL_BALANCE_NOT_YET_GENERATED` — no `is_current` snapshot exists for the requested scope/period/type combination (see Edge Cases for the recommended agent behavior); `422` — an invalid scope combination (e.g. a `department_id` that does not belong to the active company); `403`.

**Worked example.**

```
GET /api/v1/accounting/trial-balance?fiscal_period_id=812&type=unadjusted&include_lines=false
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": {
    "id": 44127, "type": "unadjusted", "status": "generated", "as_of_date": "2026-06-30",
    "fiscal_period_id": 812, "is_current": true, "version": 3,
    "total_debit": "418902.4500", "total_credit": "418902.4500", "variance": "0.0000",
    "has_warnings": false, "account_count": 96,
    "generated_at": "2026-06-29T02:00:11Z", "lines": null
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "7a3c9d21-1e6b-4f80-9c3a-5e2d0b7a4f61", "timestamp": "2026-06-29T06:15:03Z"
}
```

## get_financial_statement

**Description.** Fetches an already-generated financial statement snapshot: Balance Sheet, Income Statement, Cash Flow Statement, Statement of Changes in Equity, or Statement of Comprehensive Income, with its full line array and (when the request included a comparison) variance columns. `FINANCIAL_STATEMENTS.md`'s Report Generation Engine draws a hard line between `.generate`/`.preview` (which run the aggregation pipeline and, for a closed period, persist a new `financial_statement_snapshots` row) and `.read` (which returns a snapshot that already exists — whether persisted moments ago by a human's live dashboard view, materialized by the nightly schedule, or generated once and cached for a closed historical period). Per `FINANCIAL_STATEMENTS.md`'s own role matrix, `reports.financial_statements.generate` is "No (never initiates; only enriches on request)" for the AI Agent row, at every autonomy tier — so `get_financial_statement`, like `get_trial_balance`, is deliberately read-only: it never calls `POST /api/v1/financial-statements/generate` or `GET /api/v1/financial-statements/preview` on its own initiative.

**When to use.** Call this to answer a question about a company's financial position or performance that a human or another agent (the Reporting Agent producing ratio/variance/narrative analysis, the Tax Advisor computing a provision, the CFO synthesizing a board update) needs grounded in an actual generated statement, never in a number recalled from general reasoning. If no matching snapshot exists for the requested `statement_type`/period/scope, the tool returns a structured not-generated result — the correct next step is to tell the human that generating one requires a role holding `reports.financial_statements.generate` (Finance Manager, CFO, or Owner), never to fabricate figures from the underlying Trial Balance without going through the statement template mapping.

**Endpoint mapping.** `GET /api/v1/financial-statements/{snapshot}` (fetch one) or `GET /api/v1/financial-statements` (list/search) → `reports.financial_statements.read` (`FINANCIAL_STATEMENTS.md` "API" → "Endpoints" table). Note this is one of the few endpoint families in the platform not nested under `/accounting/` — it is versioned directly under `/api/v1/financial-statements/`, exactly as that document specifies.

**Input schema.**

```json
{
  "name": "get_financial_statement",
  "description": "Fetch an existing financial statement snapshot by id, or search for the latest matching snapshot by statement_type, scope, and period. Never triggers a new generation or preview — reports.financial_statements.generate is never granted to an AI agent identity. Returns a structured not-generated result if no matching snapshot exists.",
  "input_schema": {
    "type": "object",
    "properties": {
      "snapshot_id": { "type": "integer", "description": "Fetch one exact snapshot, bypassing all other filters." },
      "statement_type": { "type": "string", "enum": ["balance_sheet", "income_statement", "cash_flow_statement", "statement_of_changes_in_equity", "statement_of_comprehensive_income"] },
      "scope_type": { "type": "string", "enum": ["company", "branch", "department", "project", "consolidated_group"], "default": "company" },
      "scope_id": { "type": ["integer", "null"] },
      "as_of_date": { "type": "string", "format": "date", "description": "Balance Sheet only." },
      "period_start": { "type": "string", "format": "date", "description": "Income Statement / Cash Flow only." },
      "period_end": { "type": "string", "format": "date" },
      "framework": { "type": "string", "enum": ["IFRS", "GAAP"], "description": "Defaults to the company's configured framework." },
      "presentation_currency": { "type": "string" },
      "comparison": { "type": "string", "enum": ["none", "prior_period", "prior_year_same_period", "budget"], "default": "none" }
    },
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "object",
      "properties": {
        "snapshot_id": { "type": "integer" }, "statement_type": { "type": "string" },
        "status": { "type": "string", "enum": ["draft", "under_review", "final", "superseded"] },
        "as_of_date": { "type": ["string", "null"] }, "period_start": { "type": ["string", "null"] }, "period_end": { "type": ["string", "null"] },
        "presentation_currency": { "type": "string" }, "framework": { "type": "string" },
        "is_balanced": { "type": ["boolean", "null"], "description": "Balance Sheet only: TOTAL ASSETS = TOTAL LIABILITIES + TOTAL EQUITY." },
        "lines": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "line_code": { "type": "string" }, "label_en": { "type": "string" }, "label_ar": { "type": "string" },
              "classification": { "type": "string" }, "kind": { "type": "string", "enum": ["account_mapped", "subtotal", "total", "formula", "note_reference"] },
              "current_value": { "type": "string" },
              "comparison_value": { "type": ["string", "null"] },
              "variance_amount": { "type": ["string", "null"] }, "variance_percent": { "type": ["string", "null"] }
            }
          }
        },
        "generated_at": { "type": "string" }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object" }, "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `reports.financial_statements.read`. **Human-approval flag.** No (`auto`) for reading; note generation/approval-to-`final` are separate, `reports.financial_statements.generate`/`.approve`-gated actions this tool never performs.

**Errors.** `404 FINANCIAL_STATEMENT_NOT_GENERATED` — no snapshot matches the requested `statement_type`/scope/period; `422 exchange_rate_missing`-equivalent if a requested `presentation_currency` translation cannot be resolved (mirrors `FINANCIAL_STATEMENTS.md` VR-08); `422 comparison_period_invalid` — the requested `comparison` overlaps the primary period or crosses companies (VR-06); `403`.

**Worked example.**

```
GET /api/v1/financial-statements?statement_type=income_statement&period_start=2026-06-01&period_end=2026-06-30&comparison=prior_period
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": {
    "snapshot_id": 91401, "statement_type": "income_statement", "status": "final",
    "as_of_date": null, "period_start": "2026-06-01", "period_end": "2026-06-30",
    "presentation_currency": "KWD", "framework": "IFRS", "is_balanced": null,
    "lines": [
      { "line_code": "net_revenue", "label_en": "Net Revenue", "label_ar": "صافي الإيرادات", "classification": "revenue", "kind": "subtotal",
        "current_value": "182430.0000", "comparison_value": "176910.0000", "variance_amount": "5520.0000", "variance_percent": "3.12" },
      { "line_code": "gross_profit", "label_en": "Gross Profit", "label_ar": "مجمل الربح", "classification": "revenue", "kind": "subtotal",
        "current_value": "97210.5000", "comparison_value": "93440.0000", "variance_amount": "3770.5000", "variance_percent": "4.03" },
      { "line_code": "operating_profit", "label_en": "Operating Profit (EBIT)", "label_ar": "الربح التشغيلي", "classification": "operating_expense", "kind": "subtotal",
        "current_value": "41880.0000", "comparison_value": "39215.0000", "variance_amount": "2665.0000", "variance_percent": "6.79" },
      { "line_code": "profit_for_period", "label_en": "Profit for the Period", "label_ar": "ربح الفترة", "classification": "revenue", "kind": "total",
        "current_value": "37102.0000", "comparison_value": "34860.0000", "variance_amount": "2242.0000", "variance_percent": "6.43" }
    ],
    "generated_at": "2026-07-01T03:00:00Z"
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "3e0a7c51-9d4b-4f2a-8e60-2c1b5d9a7f38", "timestamp": "2026-07-01T09:20:44Z"
}
```

## list_journal_entries

**Description.** Searches and lists journal entries by status, type, date range, referenced account, counterparty, source document, or AI-authorship, paginated. This is the tool an agent calls to see the queue: every draft it (or a colleague agent) has proposed and not yet had submitted, every entry `pending_approval` awaiting a human, or the full posted history for a reconciliation or precedent check. It is the same underlying endpoint `ACCOUNTANT_AGENT.md` §"Tools & API Access" registers under the name `search_journal_entries` ("Pattern search for duplicate detection and precedent retrieval") — this catalog uses the verb `list_journal_entries` for the general-purpose queue/filter use case; both names resolve to the identical `GET /api/v1/accounting/journal-entries` call and are interchangeable.

**When to use.** Call this before drafting a new entry from a bank line or bill, to check whether an equivalent draft or posted entry already exists for the same source document (avoiding a duplicate proposal — see Edge Cases); to retrieve a specific entry_type's recent history for pattern-based accrual detection; or simply to show a human "here is everything currently sitting in your approval queue." For a single already-known id, `GET /api/v1/accounting/journal-entries/{id}` (not separately catalogued here, since it is a trivial single-record fetch under the identical `accounting.journal.read` permission) is more direct.

**Endpoint mapping.** `GET /api/v1/accounting/journal-entries` → `accounting.journal.read` (`JOURNAL_ENTRIES.md` "API" table, row 2).

**Input schema.**

```json
{
  "name": "list_journal_entries",
  "description": "Search/list journal entries by status, entry_type, date range, referenced account, counterparty, source document, or AI-authorship. Equivalent to the endpoint registered elsewhere in this corpus as search_journal_entries — same endpoint, same permission, same result shape.",
  "input_schema": {
    "type": "object",
    "properties": {
      "status": { "type": "array", "items": { "type": "string", "enum": ["draft", "pending_approval", "approved", "rejected", "posted", "reversed", "voided", "archived"] } },
      "entry_type": { "type": "array", "items": { "type": "string", "enum": ["manual", "invoice", "bill", "payment", "receipt", "inventory", "payroll", "depreciation", "loan", "asset", "tax", "revaluation", "year_closing", "opening_balance", "ai_generated", "reversal", "adjustment"] } },
      "date_from": { "type": "string", "format": "date" },
      "date_to": { "type": "string", "format": "date" },
      "account_id": { "type": "integer", "description": "Only entries with at least one line referencing this account." },
      "customer_id": { "type": ["integer", "null"] },
      "vendor_id": { "type": ["integer", "null"] },
      "source_type": { "type": "string" },
      "source_id": { "type": "integer" },
      "reference": { "type": "string", "maxLength": 100 },
      "ai_generated": { "type": "boolean" },
      "created_by": { "type": "integer", "description": "Filter to entries authored by a specific user or service-account id." },
      "page": { "type": "integer", "minimum": 1, "default": 1 },
      "per_page": { "type": "integer", "minimum": 1, "maximum": 100, "default": 25 }
    },
    "additionalProperties": false
  }
}
```

**Output schema.**

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "data": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" }, "journal_number": { "type": "string" }, "journal_date": { "type": "string" },
          "entry_type": { "type": "string" }, "status": { "type": "string" },
          "total_debit": { "type": "string" }, "total_credit": { "type": "string" }, "currency_code": { "type": "string" },
          "memo": { "type": ["string", "null"] }, "reference": { "type": ["string", "null"] },
          "source_type": { "type": ["string", "null"] }, "source_id": { "type": ["integer", "null"] },
          "ai_generated": { "type": "boolean" }, "ai_confidence": { "type": ["string", "null"] },
          "created_by": { "type": "integer" }, "created_at": { "type": "string" }
        }
      }
    },
    "message": { "type": "string" }, "errors": { "type": "array" },
    "meta": { "type": "object", "properties": { "pagination": { "type": "object" } } },
    "request_id": { "type": "string" }, "timestamp": { "type": "string" }
  }
}
```

**Permission key.** `accounting.journal.read`. **Human-approval flag.** No (`auto`).

**Errors.** `422` — `date_to` before `date_from`, or an unknown `status`/`entry_type` enum value; `403`.

**Worked example.**

```
GET /api/v1/accounting/journal-entries?status[]=draft&ai_generated=true&date_from=2026-06-01&date_to=2026-06-30
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": [
    {
      "id": 903581, "journal_number": "JE-2026-DRAFT-903581", "journal_date": "2026-06-28",
      "entry_type": "ai_generated", "status": "draft", "total_debit": "214.5000", "total_credit": "214.5000",
      "currency_code": "KWD", "memo": "MEW electricity — June 2026, unmatched to any open Purchasing record",
      "reference": null, "source_type": "attachment", "source_id": 61190,
      "ai_generated": true, "ai_confidence": "0.9100", "created_by": 21, "created_at": "2026-06-28T07:42:05Z"
    }
  ],
  "message": "OK", "errors": [], "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "6f2e8b90-3a5c-4d1e-9b70-8e4c2a1f6d90", "timestamp": "2026-06-28T07:44:00Z"
}
```

# Safety & Guardrails

**Tools are the only channel, and this catalog changes nothing about that.** Every one of the ten tools above is, per `TOOLS_PROMPTS.md`, a thin wrapper around exactly one Laravel `/api/v1` endpoint, behind the identical RBAC a human hits clicking the equivalent button in the Next.js app. No tool in this catalog contains business logic of its own, no tool has a privileged bypass, and no tool reaches PostgreSQL directly — the FastAPI subnet the AI layer runs in has no network route to the PostgreSQL subnet at all, so this is a topology fact, not a policy an agent could be prompted around.

**Autonomy tiers, recapped against this catalog specifically:**

| Tier | Tools | What it means here |
|---|---|---|
| `auto` | `search_accounts`, `get_account`, `get_account_balance`, `get_general_ledger`, `get_trial_balance`, `get_financial_statement`, `list_journal_entries` | Executes immediately on any call from a principal (human or AI service-account) holding the read permission. No artifact is created; no human step is required; the only "gate" is ordinary RBAC and, for the three report/statement reads, that a matching snapshot already exists. |
| `proposal` | `create_journal_entry` | Creates a `journal_entries` row with `status = 'draft'`, `entry_type = 'ai_generated'` — a real row in the module's own table, but one with zero financial effect until a human submits and approves it. Always requires ≥ 1 approval level downstream (`JOURNAL_ENTRIES.md` Approval Workflow), with no amount-based exemption for `ai_generated` entries, ever. |
| `approval` | `post_journal_entry`, `reverse_journal_entry` | The permission key that gates the call (`accounting.journal.post`, `accounting.journal.reverse`) is never granted to any AI service-account, at any company, under any autonomy configuration. These two tools are documented for schema completeness and for the human-driven surfaces (UI actions, the Approval Assistant relaying a synchronous human confirmation) that legitimately call the same endpoint — never for an autonomous agent's own initiative. |

**The token model behind the `approval` tier, precisely.** `post_journal_entry` and `reverse_journal_entry` are registered in `ai_tool_registry` under the same `accounting-tools` MCP server as the other eight tools — there is one server per functional domain, not a separate privileged server for approval actions. What actually prevents an autonomous agent from posting is not a different server or a different transport; it is that every call the FastAPI orchestrator makes carries a bearer token, and the token for a scheduled job, an event-triggered draft, or an ad hoc agent-initiated chat turn is always one of the fixed per-agent service-account tokens (`ai_general_accountant`, `ai_auditor`, `ai_cfo`, and so on), whose roles structurally never include `accounting.journal.post`, `.approve`, `.submit`, or `.reverse` — not merely unused grants, but keys the role assignment never contains, at any company. When a human converses with the Approval Assistant and says, in effect, "approve and post entry 903581," the Assistant's tool-calling loop forwards **that human's own already-authenticated session token**, not any AI identity's token — architecturally identical to the human clicking "Post" in the Next.js UI, just reached through a conversational surface instead of a button. This is the concrete mechanism behind the platform-wide promise "AI obeys the same permissions as the user; it never bypasses authorization": there is no code path, anywhere, that lets an AI-authored decision execute with more authority than the human whose session carried it.

**Confidence, reasoning, and sources are structural, not stylistic.** `create_journal_entry`'s `meta.confidence`, `meta.reasoning`, and `meta.source_documents` fields are declared `required` inside the tool's own JSON Schema — a call omitting any of them fails validation inside the MCP tool server before a request ever reaches Laravel, and is returned to the model as a correctable error, never silently coerced or defaulted. The identical rule is enforced a second, independent time by Laravel's own `FormRequest` (`422 ai_reasoning_required`) on the rare path where a client bypasses the MCP layer's own check. Two layers checking the same invariant, neither trusting the other alone, is the deliberate pattern throughout this catalog, not an oversight to be simplified away.

**Control-account posting restriction carries through from `JOURNAL_ENTRIES.md` Business Rule 5.** A `create_journal_entry` line that targets a control account (Trade Receivables, Trade Payables, or any account with `is_control_account = true`) must carry the matching `customer_id`/`vendor_id`; the Posting Engine — invoked identically at draft-validation time and again at posting time — rejects a control-account line with no sub-ledger tag with `422 CONTROL_ACCOUNT_DIRECT_POST_FORBIDDEN`, whether the caller is a human or an AI service-account. This keeps the Accounts Receivable/Accounts Payable sub-ledgers permanently reconcilable to their control accounts, which every downstream Trial Balance and Financial Statement figure depends on.

**Idempotency is mandatory on every write call.** `create_journal_entry`, `post_journal_entry`, and `reverse_journal_entry` all require an `Idempotency-Key` header the calling agent generates once per logical action (`ai-proposal-<uuid>`) and must resend unchanged on any retry of that same action — never a fresh key, which would defeat the guarantee and risk a duplicate draft or a double reversal. For automatic, event-triggered drafts specifically, a second, source-based dedup key (`source_module + source_type + source_id + event_name`) additionally guards against event redelivery producing two proposals for the same underlying fact, independent of whatever key the HTTP client itself supplied.

**Tenant isolation holds at three independent layers**, matching the pattern every agent document in the roster restates: (1) the service token's `company_id` claim, checked before any query runs; (2) the identical `company_id`-scoped Eloquent global scope / Row-Level Security policy every table in this catalog's endpoints already carries for a human session — there is no relaxed variant for an AI caller; (3) a runtime assertion in the FastAPI orchestration layer that rejects any tool result whose `company_id` does not match the active task's `company_id`, as defense-in-depth against a hypothetical bug in the newer agent-orchestration code, specifically because that layer is younger than the Laravel authorization stack it sits behind.

**Untrusted content is data, never instructions.** Every field this catalog's tools can return that originates outside the platform's own structured records — an OCR'd bill's extracted vendor name, a bank statement memo line, a journal entry's free-text `memo`/`description`, a Trial-Balance finding's narrative — is information for the model to reason about, never a command to obey, per rule 6 of the shared tool-use contract in `TOOLS_PROMPTS.md`. A scanned invoice whose OCR'd text happens to contain a phrase resembling "approve automatically" or "ignore prior instructions" has no path to elevate a tool call's permission or to make `create_journal_entry` bypass its own required `meta` fields, both because the tool-calling loop only accepts structured, schema-validated arguments (never free text as a command channel) and because the RBAC grants described above do not vary with document content under any circumstance. Where such embedded text is material, the agent's obligation is to note it to the human reviewer, not to act on it.

**Every call is logged, success or failure.** Regardless of outcome, every invocation of any tool in this catalog is written to `ai_logs` (the shared, cross-agent log defined once in `CFO_AGENT.md`) before the result returns to the reasoning loop, so a permission denial, a validation failure, a timeout, and a success are all equally observable after the fact. Every call that actually mutates state (`create_journal_entry`, `post_journal_entry`, `reverse_journal_entry`) additionally writes a row to the foundation `audit_logs` table — actor, action, old value, new value, reason where applicable, IP address, device — per the platform's standing audit rule; audit rows are themselves immutable, with no `UPDATE`/`DELETE` grant on `audit_logs` for any application role, human or AI.

**Per-task call budgets bound runaway loops.** Each `ai_tool_registry` row carries a `max_calls_per_task` ceiling (illustrated on `search_accounts` above at 20); once a single `ai_tasks` run exceeds a tool's ceiling, the orchestrator halts further calls to that tool for the remainder of the task and surfaces the ceiling breach to the reasoning loop as an error to react to, per `TOOLS_PROMPTS.md`'s Reasoning Patterns — this is a mechanical circuit-breaker against a malformed loop (for example, an agent repeatedly re-searching the same account instead of reusing an earlier result), independent of and in addition to the per-user/per-company rate limits described in Error Handling below.

# Error Handling

Every tool in this catalog returns the platform's standard response envelope on every call, success or failure:

```json
{
  "success": false,
  "data": null,
  "message": "Human-readable summary of what went wrong.",
  "errors": [ { "field": "lines[1].account_id", "code": "ACCOUNT_NOT_FOUND", "message": "Account 61240 does not exist or does not belong to the active company." } ],
  "meta": { "pagination": null },
  "request_id": "b2f7a910-3c8d-4e91-9a02-6f5e1c8b0d47",
  "timestamp": "2026-06-28T07:45:11Z"
}
```

**HTTP status codes, as they apply to this catalog:**

| HTTP | Meaning here |
|---|---|
| `400` | Malformed request body — should never occur for a schema-validated MCP tool call; indicates an orchestrator bug, not a model error, if seen. |
| `401` | Missing, expired, or revoked bearer token — for `post_journal_entry`/`reverse_journal_entry` specifically, this is also what results if a human's confirming session expired between the confirmation prompt and the tool call actually firing. |
| `403` | Authenticated, but the calling principal's role lacks the endpoint's required permission key — the expected, by-design outcome for any AI service-account attempting `post_journal_entry` or `reverse_journal_entry`. |
| `404` | The referenced resource does not exist, or exists in a different company than the caller's active `X-Company-Id` context — both cases return the identical 404 deliberately, so a cross-tenant probe cannot distinguish "wrong id" from "exists, not yours." |
| `409` | A state-machine conflict: the entry is not in the status the action requires (`post_journal_entry` on an already-posted entry), the target fiscal period is no longer open, the target is itself a reversal (`reverse_journal_entry`), or an `Idempotency-Key` was reused with a materially different payload than its original call. |
| `422` | Field-level or business-rule validation failure — an unbalanced entry, a missing `meta` block, a control-account line with no counterparty tag, an invalid enum value, an unresolvable period, a missing exchange rate for a requested presentation currency. |
| `429` | The calling principal has exceeded its rate limit — see below. |
| `500` | An unexpected server fault; `request_id` should be preserved and included in any escalation, exactly as `CHART_OF_ACCOUNTS.md` §14.2's error reference table specifies platform-wide. |

**Tool-specific error codes introduced or reused across this catalog:** `ACCOUNT_NOT_FOUND`, `DIMENSION_INVALID`, `PERIOD_UNRESOLVABLE`, `ai_reasoning_required`, `CONTROL_ACCOUNT_DIRECT_POST_FORBIDDEN`, `JOURNAL_ENTRY_NOT_FOUND`, `REVERSAL_OF_REVERSAL_FORBIDDEN`, `TRIAL_BALANCE_NOT_YET_GENERATED`, `FINANCIAL_STATEMENT_NOT_GENERATED`, `comparison_period_invalid`, `exchange_rate_missing`, `PERMISSION_DENIED`, `UNAUTHENTICATED`, `RATE_LIMITED`, `INTERNAL_ERROR`. Every one of these resolves to a specific, actionable next step rather than a generic failure — the platform's stated design goal ("the API returns a structured 422/409 error identifying exactly which line or which rule failed — never a generic failure," `JOURNAL_ENTRIES.md` Posting Engine) applies identically whether the caller is a human's browser or an agent's tool call.

**Two validation layers, two different moments.** A `create_journal_entry` call missing `meta.reasoning` never reaches Laravel at all — the MCP tool server validates the call's arguments against the tool's own `input_schema` immediately, client-side, before any network request is made (`TOOLS_PROMPTS.md`, the diagram under "Tool-Use Philosophy"). A call that passes schema validation but still violates a business rule Laravel enforces (an unbalanced entry, a closed fiscal period, a control-account line with no counterparty) fails only at the second layer, as an ordinary HTTP `422`/`409`. Both are returned to the model in the same envelope shape and must be treated identically: as information to correct and, at most, retry once with a fixed payload — never as a signal to route around the check some other way.

**Retry discipline.** Read tools (`auto` tier) are naturally safe to retry on a timeout or `5xx` with no special handling. Write tools (`create_journal_entry`, and — for the human-driven callers that legitimately reach them — `post_journal_entry`/`reverse_journal_entry`) must retry, if at all, with the **same** `Idempotency-Key` as the original attempt; the platform guarantees exactly-once semantics for a repeated key with an identical payload, and returns `409` for a repeated key with a *different* payload, which is itself a signal that something upstream (a changed draft, a stale cache) needs investigation before blindly resubmitting. A synchronous posting attempt that fails validation is never auto-retried by the platform — per `JOURNAL_ENTRIES.md`'s Posting Engine notes, "blind retry of a financial write is unsafe without human judgment on why it failed," and the same discipline applies to an agent's own retry logic: correct the specific field the error names, or stop and ask, never loop.

**Reacting to `403` specifically.** Per rule 2 of the shared tool-use contract (`TOOLS_PROMPTS.md`), a tool call rejected with `403` means the invoking principal's own tool list already reflected an insufficient grant, or (for `post_journal_entry`/`reverse_journal_entry`, which are never included in an autonomous agent's own filtered tool list in the first place) that a call was attempted against the platform's own architectural boundary. In either case, the correct response is to report the missing capability plainly — "posting requires `accounting.journal.post`, which I do not hold; the entry remains a draft awaiting your review" — never to search for an alternate tool or a different account/entity that might sidestep the same permission check.

**Never fabricate a result.** If any tool call in this catalog fails, times out, or is denied, that outcome is itself the information to respond with. No agent may construct a plausible-looking `get_account_balance` figure, a plausible Trial Balance total, or a plausible statement line and continue as though the call had succeeded — this is rule 8 of the shared contract, restated here because a fabricated accounting figure is categorically worse than an honest "I could not retrieve this."

**Rate limiting.** Report-shaped reads (`get_general_ledger`, `get_trial_balance`, `get_financial_statement`) are throttled per the same policy `FINANCIAL_STATEMENTS.md`'s Security section defines for its own `.generate`/`.export` endpoints — a default of 60 requests/minute per calling principal and 300 requests/minute per company — to prevent a malformed reasoning loop or a runaway batch task from placing excessive load on ledger-aggregation queries; a breach returns `429 RATE_LIMITED` with a `Retry-After` hint, which the agent should honor with a single deferred retry, not a tight loop.

# Examples

**Multi-tool sequence: draft → validate → propose journal entry.** This continues the MEW electricity-bill thread introduced under `create_journal_entry`, showing the full reasoning arc a General Accountant Agent run performs before that tool is ever called — not just the write itself in isolation.

**Step 1 — resolve the accounts.** The OCR pipeline (Document AI/OCR Agent, out of scope for this catalog) has already extracted a vendor name and amount from attachment `61190`. The agent first confirms the expense account it believes applies actually exists, is active, and is postable:

```
GET /api/v1/accounting/accounts?search=utilities&nature=expense&allow_posting=true&status=active
X-Company-Id: 4821
```

Returns account `5340` exactly as shown under `search_accounts` above. The agent separately resolves the AP control account by code prefix:

```
GET /api/v1/accounting/accounts?search=payable&is_control_account=true&status=active
X-Company-Id: 4821
```

```json
{
  "success": true,
  "data": [
    { "id": 2110, "code": "2110", "name_en": "Accounts Payable – Trade", "name_ar": "ذمم دائنة تجارية",
      "nature": "liability", "account_type": { "code": "accounts_payable", "name_en": "Accounts Payable", "name_ar": "ذمم دائنة" },
      "normal_balance": "credit", "parent_id": 2100, "depth": 2,
      "allow_posting": true, "is_control_account": true, "status": "active", "currency_code": null, "tags": [] }
  ],
  "message": "OK", "errors": [], "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "1a9c3e70-5b2d-4f81-9c04-3e7a6b0d2f18", "timestamp": "2026-06-28T07:41:50Z"
}
```

**Step 2 — validate against precedent and check for a duplicate proposal.** Before drafting, the agent checks its own posting history for this account/vendor pattern (grounding the confidence it will state) and, separately, checks that no entry already exists for this exact source document — a defensive duplicate check independent of the platform's own idempotency guarantees, since a second OCR pass on the same attachment is a plausible failure mode this agent must not compound into a second draft:

```
GET /api/v1/accounting/reports/general-ledger?account_ids[]=5340&period_start=2025-07-01&period_end=2026-06-30
X-Company-Id: 4821
```

The response (omitted here for brevity — same shape as the `get_general_ledger` worked example above) shows eleven prior MEW postings to account `5340` over the trailing twelve months, all uncontested. The agent then checks the queue for anything already referencing this attachment:

```
GET /api/v1/accounting/journal-entries?source_type=attachment&source_id=61190
X-Company-Id: 4821
```

```json
{ "success": true, "data": [], "message": "OK", "errors": [], "meta": { "pagination": { "page": 1, "per_page": 25, "total": 0, "cursor": null } },
  "request_id": "8d4b0f21-6c3a-4e90-8b71-2d5e9a6c1f04", "timestamp": "2026-06-28T07:42:00Z" }
```

An empty result confirms no prior draft or posted entry references this attachment — safe to proceed.

**Step 3 — propose the entry.** With both accounts validated and eleven prior occurrences as precedent, the agent calls `create_journal_entry` exactly as shown in that tool's own worked example above, producing draft `903581` at confidence `0.91`, citing attachment `61190` and vendor `9010` as its sources.

**Step 4 — confirm the draft landed in the queue.** The agent (or the human accountant's own dashboard, moments later) calls:

```
GET /api/v1/accounting/journal-entries?status[]=draft&ai_generated=true&date_from=2026-06-01&date_to=2026-06-30
X-Company-Id: 4821
```

— returning exactly the `list_journal_entries` worked example shown above, with draft `903581` now visible and awaiting human submission.

**Step 5 — human review, submission, approval, and posting (outside this catalog's autonomous scope).** A Senior Accountant reviews the draft in the Next.js queue, edits nothing (the categorization and amount are correct), submits it, and — since `entry_type = 'ai_generated'` always requires at least one approval level regardless of amount — a second reviewer (or the same Senior Accountant, if their role is the sole configured approval level for this company) approves it, at which point `post_journal_entry` fires under that human's own session exactly as shown in that tool's worked example, and the entry becomes `JE-2026-06-004821`, posted.

**Step 6 — close the loop.** At period end, the CFO Agent (or a human) confirms the posting is reflected in the period's figures by calling `get_trial_balance` for fiscal period `812` — the same call shown under that tool's worked example, whose `total_debit = total_credit = "418902.4500"` includes this entry's KWD 214.500 — and, once the month's Income Statement is generated and marked `final`, `get_financial_statement` for `income_statement`/June 2026 reflects the same KWD 214.500 inside the period's Operating Expenses roll-up, fully traceable back through `get_general_ledger` to journal entry `JE-2026-06-004821` and, ultimately, to attachment `61190`. Every number a human or another agent sees at the end of this chain resolves, in at most a few tool calls, back to the original scanned invoice — the "every number is drillable" principle `FINANCIAL_STATEMENTS.md` states as non-negotiable, made concrete as an actual sequence of tool calls rather than an abstract promise.

# Edge Cases

| Edge case | Resolution |
|---|---|
| No `is_current` Trial Balance snapshot exists yet for the requested scope/period (a brand-new period, or the nightly job has not yet run). | `get_trial_balance` returns `404 TRIAL_BALANCE_NOT_YET_GENERATED` rather than the tool attempting to call `POST /trial-balance/generate` itself — `accounting.trial_balance.generate` is never granted to an AI identity. The agent's correct response is to tell the requester a fresh snapshot requires a role holding that permission (Finance Manager, CFO, Owner, Admin), never to approximate a Trial Balance from partial data. |
| No Financial Statement snapshot exists for the requested `statement_type`/period/scope, or the only existing one is `draft`/`superseded`. | `get_financial_statement` returns `404 FINANCIAL_STATEMENT_NOT_GENERATED`. Same discipline as above: `reports.financial_statements.generate` is never granted to an AI identity, so the agent reports the gap rather than reconstructing statement-shaped figures from a raw Trial Balance without going through the company's actual `financial_statement_lines` mapping (which can differ materially from a naive account-type rollup — see `FINANCIAL_STATEMENTS.md` §"Financial Reporting Philosophy," point 4). |
| A `create_journal_entry` line targets a control account (e.g. Trade Receivables) without a `customer_id`/`vendor_id`. | Rejected `422 CONTROL_ACCOUNT_DIRECT_POST_FORBIDDEN` before any row is written. The agent must re-resolve the correct sub-ledger party (via the Customers/Vendors modules' own read tools, outside this catalog) and resubmit with the tag present — it must never substitute a non-control account just to avoid the check, since that would misclassify the transaction. |
| The target fiscal period closes (or locks) between `create_journal_entry` and a human eventually calling `post_journal_entry`. | `post_journal_entry` returns `409` naming the locked period. The system never silently re-dates the draft forward. The correct remediation — carried out by a human, since it requires judgment about which period the correction belongs in — is either to re-date the draft into the current open period with an explanatory memo, or to invoke the elevated period-reopening procedure (`accounting.period.reopen`), never available to this catalog's tools. |
| An `Idempotency-Key` is retried after a client-side timeout whose server-side call actually succeeded. | The platform returns the original result (the same draft, or the same posted entry) rather than creating a second one — this is the entire purpose of the key, and the agent should treat a retried call's response identically to a first-attempt success, not assume something new was created. |
| A duplicate proposal risk — the same source document is OCR'd twice, or the same bank line is matched by two independent task runs. | Defended twice: the agent's own `list_journal_entries` check against `source_type`/`source_id` before calling `create_journal_entry` (Examples, Step 2), and, independently, the platform's own source-based dedup key (`source_module + source_type + source_id + event_name`) for event-triggered drafts specifically, which makes a second automatic Listener invocation for the same event a no-op regardless of whether the agent's own defensive check ran. |
| `reverse_journal_entry` is attempted against an entry that is itself a system-generated reversal (`is_reversal = true`). | Rejected `409 REVERSAL_OF_REVERSAL_FORBIDDEN`. Per `JOURNAL_ENTRIES.md` Locking Rule 7, correcting a bad reversal requires a fresh, freely-worded adjusting entry via `create_journal_entry` with an explicit memo, never a further reversal — this keeps the audit trail from becoming an unbounded, hard-to-follow chain of reversals-of-reversals. |
| `create_journal_entry` lines are denominated in a non-base currency. | `debit`/`credit` on each line are always in the line's stated transaction currency; `base_debit`/`base_credit` are computed server-side from the prevailing `exchange_rate` and are never client-submitted fields the model can set directly — the schema deliberately has no such property. A multi-currency entry whose rounding residual exceeds the company's configured tolerance is rejected `422`, not silently plugged; a residual within tolerance requires an explicit plug line against the dedicated Rounding Differences account, which is a further `create_journal_entry` line the agent must include, not an implicit platform behavior. |
| `get_account_balance` is requested for an `as_of_date` before the company's books-open date. | Returns a valid, zeroed result (`opening_balance`, `period_activity`, and `closing_balance` all `"0.0000"`) rather than an error — correctly reflecting that no ledger history exists yet, distinct from a `404`, which is reserved for an account id that does not exist at all. |
| A subtree requested via `search_accounts` with `shape=tree` is very large (a group company with cost-center-per-branch sub-accounting). | The tool paginates at the flat level by default; `shape=tree` should be reserved for a `parent_id`-scoped subtree the caller already knows is reasonably small (a handful of levels), not for the whole Chart of Accounts of a large tenant, to keep the response bounded and the model's own context usage predictable. |
| A tool result's `company_id` does not match the active task's `company_id` (should be structurally impossible given the three isolation layers in Safety & Guardrails, but checked anyway). | The FastAPI orchestration layer's runtime assertion discards the result and raises an internal error rather than passing a cross-tenant figure to the model under any circumstance — a defense against a hypothetical bug in the orchestration layer itself, not a scenario expected to occur in normal operation. |
| An account this agent itself previously suggested via the accounts `ai-suggestions` feed was later overridden by a human to a different `account_type_id` (visible via `ai_suggested_type_id` still present on the account row, distinct from the current `account_type_id`). | `get_account` always returns the current, human-confirmed classification as authoritative. The agent must reason from the account's present state, never attempt to "correct" it back toward its own earlier suggestion — a human's override is a terminal decision until a further human action changes it again. |
| A human's confirmation session (behind a `post_journal_entry`/`reverse_journal_entry` call routed through the Approval Assistant) expires between the confirmation prompt and the tool call actually firing. | The call fails `401` upstream of any accounting-specific validation, exactly as it would for an expired session clicking a UI button — the Approval Assistant must re-prompt for confirmation under a fresh, valid session rather than replaying a stale `confirmed_by_user_id`. |
| A batch reconciliation or month-end task would otherwise call `search_accounts`/`list_journal_entries` at high frequency in a tight loop. | The agent should paginate deliberately and respect the per-task `max_calls_per_task` ceiling and the platform's per-user/per-company rate limits rather than attempting to parallelize around them; a task that would require more calls than its budget allows should request a higher budget through the task-configuration mechanism (`ai_tasks`, per `TOOLS_PROMPTS.md`'s Reasoning Patterns), not attempt a workaround. |

# End of Document
