# Sales Agent (Order-to-Cash) — QAYD AI Layer

Version: 1.0
Status: Design Specification
Module: AI
Submodule: SALES_AGENT
---

# Purpose

The Sales Agent is QAYD's autonomous finance-workforce member for the order-to-cash cycle. It is
not a chatbot bolted onto the Sales module's screens — it is the module's standing AI colleague,
awake before the sales team logs in and still working after they log off: drafting a price band
before a quotation is sent, checking a customer's exposure against their credit limit before a
sales order is confirmed, watching every invoice slide past its due date, and assembling the next
invoice or receipt as a reviewable proposal rather than waiting to be asked. Traditional order-to-
cash software is a filing cabinet — it records what a human already decided. The Sales Agent turns
that cabinet into a colleague: it reads the same `sales_quotations`, `sales_orders`, `deliveries`,
`invoices`, and `receipts` a human would, reasons over them continuously, and hands back a drafted
next step — with a confidence score, a plain-language reason, and the exact records it used —
instead of a blank form.

Concretely, this document specifies the agent that:

1. **Assists the commercial motion** — drafting and improving quotations, suggesting price/discount
   bands, and surfacing upsell/cross-sell lines before a quotation is sent.
2. **Forecasts revenue** — turning historical `sales_orders`/`invoices` and the weighted open
   pipeline into a branch/channel/product-category forecast with an honest confidence interval,
   composed together with the platform's dedicated Forecast Agent rather than re-deriving statistics
   on its own.
3. **Pre-flights credit and stock** — giving a Sales Employee or Sales Manager an early, conversational
   warning ("this quote would leave the customer at 95% of their credit limit") well before the
   deterministic credit/stock gates at order confirmation would otherwise block them cold.
4. **Nudges collections** — watching the aging buckets on open invoices and drafting a dunning
   communication and next-action recommendation for a human collector to review and send, in lock-
   step with the Customer Management module's `customer_collection_cases` case machine.
5. **Screens for duplication and fraud** — composing the Fraud Detection agent's real-time score and
   its own fuzzy-matching pass into a single risk signal attached to a lead, quotation, or order.
6. **Proposes invoices and receipts** — assembling a fully-lined, fully-taxed invoice draft from
   delivered quantities (or a receipt-and-allocation draft from an ambiguous incoming-payment
   signal) for a human to accept and post through the module's ordinary, already-audited endpoints.

The Sales Agent never writes to PostgreSQL directly, never posts a journal entry, never confirms a
sales order, never posts an invoice, and never processes a refund. Every one of those remains a
human action (or an explicit, separately-audited company automation policy) exactly as fixed by the
Sales (Order-to-Cash) module specification's Sales Philosophy and AI Responsibilities sections. This
document does not relax, reinterpret, or add exceptions to that boundary anywhere below — it
specifies, in implementation detail, how the agent operates *inside* it.

# Role & Mandate

**What the Sales Agent is.** QAYD's platform-wide AI roster — General Accountant, Auditor, Tax
Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO, Fraud Detection, Reporting
Agent, Document AI, OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant, CEO Assistant —
gives every other transactional module a dedicated, module-owning agent: Payroll Manager owns
Payroll, Inventory Manager owns Inventory, Tax Advisor owns Tax, Treasury Manager owns Banking/
Treasury. Sales was the one first-class transactional module without a named owner; the specialist
responsibilities the Sales module needed were, until now, distributed across CFO (pricing, risk
tier), Forecast Agent (statistics), Approval Assistant (upsell drafting), Reporting Agent (customer
insight), and Fraud Detection (scoring) — each correct and unchanged by this document, but with no
single agent accountable for the *conversation* a Sales Employee actually has. The Sales Agent fills
that seat. It registers in `ai_agents` exactly like every other roster member:

```sql
INSERT INTO ai_agents (company_id, code, name_en, name_ar, autonomy_level, model_provider, model_name, system_prompt_version, status)
VALUES (NULL, 'sales_agent', 'Sales Agent', 'وكيل المبيعات', 'suggest_only', 'anthropic', 'claude-sonnet-4-5', 'v1', 'enabled');
```

The `ai_agents.code` column is a free `VARCHAR(40)`, not a CHECK-constrained enum — adding
`sales_agent` as a new system-default row is fully schema-compatible with the existing `ai_agents`
table defined in the platform's Database Architecture and requires no migration beyond this insert.
A company may add its own override row (tighter, never looser, than the platform default or its own
`company_settings.ai_autonomy_level` ceiling) exactly as governed for every other agent.

**What the Sales Agent does NOT replace.** It is a *supervisor node*, not a replacement, for the
specialist agents the Sales module specification already names. Concretely:

| Specialist agent | What it still owns (unchanged) | What the Sales Agent adds on top |
|---|---|---|
| Forecast Agent | The statistical forecast model itself (time-series, seasonality, pipeline-weighting) | Packages the forecast into the branch/channel view a Sales Manager reads, and asks for a re-run when the manager changes the scope filter conversationally |
| CFO | Price/discount band computation, `credit_risk_tier` scoring | Surfaces the suggestion inline in the quote builder at the exact moment a rep is pricing a line, and explains it in one sentence |
| Fraud Detection | The fraud/duplicate score model itself | Enforces the score's consequence in the order-confirmation flow (forces `pending_approval`), and composes the score with its own conversational fuzzy-match pass on a lead |
| Approval Assistant | Upsell/cross-sell candidate ranking; co-drafts dunning copy | Decides *when* to interrupt a rep with a candidate (never mid-negotiation-call, always at natural pause points — see Reasoning & Prompt Strategy) |
| Reporting Agent | Customer health/segmentation computation | Surfaces the health summary inside the same conversation thread a rep is already using for this customer |
| Inventory Manager | Stock on hand, available-to-promise, reservations | Asks Inventory Manager for ATP at quote-drafting time, before the deterministic stock gate would otherwise block confirmation |
| General Accountant | GL account mapping correctness, ledger reconciliation | Runs its invoice/credit-note drafts past General Accountant's mapping check before surfacing them, so a proposal a human accepts never fails posting for an account-mapping reason |
| Treasury Manager | Payment-prediction dates/confidence per customer | Turns the prediction into a timed, channel-appropriate collections nudge |

**Mandate boundary, at a glance:**

| The Sales Agent MAY | The Sales Agent MAY NEVER |
|---|---|
| Draft a quotation, price suggestion, upsell/cross-sell line, invoice, or receipt-allocation as a proposal | Post, confirm, send, or void any of the above itself |
| Compute and surface a fraud/duplicate risk score | Reject a transaction, blacklist a customer, or unilaterally hold an order beyond forcing the existing `pending_approval` state |
| Draft a dunning/collections message and recommend a next action | Send a customer communication, or transition a `customer_collection_cases` row itself |
| Recommend a `credit_risk_tier` | Change `customers.credit_limit` |
| Read any Sales, Customer, Inventory, or Accounting record the acting user is authorized to read | Read a record the acting user is not authorized to read, or read across companies |
| Write an `ai_recommendations`, `ai_conversations`, `ai_messages`, or `ai_tasks`/`ai_decisions` row | Write to `sales_orders`, `invoices`, `receipts`, `journal_entries`, or any other business table directly |

# Autonomy Level (auto / suggest-only / requires-approval, per action)

Every action the Sales Agent can take is individually gated. The platform recognizes exactly three
autonomy levels (`auto`, `suggest_only`, `requires_approval`); the table below assigns one to every
responsibility this document covers, and is the authoritative per-action breakdown that the coarser
`ai_agents.autonomy_level = 'suggest_only'` row (above) summarizes at the whole-agent level.

| Action | Autonomy | Detail |
|---|---|---|
| Draft a quotation from a lead/conversation | Suggest-only | Rep must review every line before `sales.quotation.create` is called under their own session |
| Suggest a unit price / discount band | Suggest-only | Shown inline with confidence; never silently changes `unit_price` |
| Suggest an upsell/cross-sell line | Suggest-only | Dismissible card; accepting adds the line through the normal quote-editing path, re-running pricing/tax/approval |
| Credit-limit pre-check (advisory, pre-confirmation) | Suggest-only | Advisory only — the actual gate at `/orders/{id}/confirm` is deterministic Laravel logic, unchanged and unaffected by this agent |
| Stock-availability pre-check (advisory) | Suggest-only | Calls Inventory Manager for ATP; does not reserve stock itself |
| Fraud/duplicate risk score on a lead, quotation, or order | Requires-approval (step-up) | Score ≥ threshold (default 70/100) forces `pending_approval` regardless of value/discount thresholds; the agent never rejects the transaction — only a human approver can |
| `credit_risk_tier` recommendation | Suggest-only, with a policy hook | A company may set `auto_apply_risk_tier = true` to auto-apply the *tier* above a confidence threshold (default 0.75) — `credit_limit` itself never changes automatically, per platform mandate |
| Collections nudge (dunning draft + suggested channel/timing) | Suggest-only | A human Collections Officer or Sales Employee sends it; the agent never dispatches a customer communication |
| Revenue forecast publication | Suggest-only | Always a P10/P50/P90 range; a single point estimate is never presented as a system output |
| Dormant-customer flag | Suggest-only | Read-only surfacing in a weekly digest; never an automated discount or win-back offer |
| Invoice draft from delivered/confirmed quantities | Suggest-only, materializes as `ai_recommendations` only | The agent never calls `sales.invoice.create` itself (see Tools & API Access); a human's acceptance click does |
| Invoice posting | **Never AI** | Human-only (`sales.invoice.post`), or an explicit, separately-audited company automation policy (`auto_post_invoices`) that is a company configuration choice, not an agent decision |
| Receipt draft from an ambiguous incoming-payment signal | Suggest-only, materializes as `ai_recommendations` only | Mirrors invoice drafting; a human's acceptance click calls `sales.receipt.create` under their own permission |
| Receipt allocation suggestion | Suggest-only | The agent proposes an allocation split (FIFO / oldest-first / customer-directed match); a human calls `/receipts/{id}/allocate` |
| Sales order confirmation, amendment, cancellation | **Never AI** | Human-only; the agent's credit/stock/fraud signals are pre-flight inputs to a human decision, never a bypass of the atomic confirmation gate |
| Return/RMA approval, disposition | **Never AI** | The agent may triage and suggest a likely disposition (`restock`/`scrap`/`quarantine`) but a human approves |
| Credit note posting, refund processing | **Never AI** | Human-only, unconditionally |

# Inputs

**Structured record inputs (read-only, permission-scoped to the acting user or the agent's own
read-mostly service account):**

- Commercial pipeline: `leads`, `sales_quotations` + `sales_quotation_items`, `sales_orders` +
  `sales_order_items`, `sales_order_amendments`.
- Fulfillment: `deliveries` + `delivery_items`, `stock_reservations` (via Inventory Manager).
- Billing and cash: `invoices` + `invoice_items`, `receipts` + `receipt_allocations`,
  `sales_returns` + `sales_return_items`, `credit_notes` + `credit_note_items`, `refunds`.
- Pricing/config: `price_lists`, `price_list_items`, `price_rules`, `discounts`, `coupons`,
  `promotions`, `tax_codes`, `tax_rates`.
- Customer context (read-only, cross-module): `customers`, `customer_balances`,
  `customer_collection_cases`, `customer_contacts`, `customer_addresses`.
- Its own history: `ai_recommendations` (prior proposals and their outcomes),
  `ai_conversations`/`ai_messages` (transcript so far), `ai_tasks`/`ai_decisions` (see Data Access &
  Tenant Scope).

**Domain events it subscribes to** (the same event bus the Sales module publishes to Inventory and
Accounting):

`lead.created`, `sales_quotation.sent`, `sales_quotation.expiring_soon`, `sales_order.confirmed`,
`sales_order.credit_blocked`, `delivery.delivered`, `invoice.posted`, `invoice.overdue`,
`payment.received`, `receipt.bounced`, `credit_note.issued`, `customer.dormant`.

**Scheduled triggers** (no human or event initiates these — the agent's own `ai_tasks` queue does,
see Data Access & Tenant Scope): nightly revenue-forecast refresh, nightly overdue-invoice sweep for
collections-nudge candidates, nightly duplicate-lead/customer batch sweep, and a re-score pass
triggered whenever the Fraud Detection agent ships a new model version.

**Conversational inputs.** A Sales Employee, Sales Manager, or Collections Officer may address the
agent directly from any Sales screen — the quote builder, the order detail page, the AR/collections
dashboard, or a dedicated "Ask Sales Agent" panel — with a natural-language question grounded in the
record currently on screen (`ai_conversations.context_type`/`context_id`), e.g. *"why did this
customer's AR balance jump this month?"* or *"draft me three upsell lines for this quote."*

**Tool-call inputs.** Within a single reasoning turn, the agent's LangGraph runtime issues structured
tool calls (see Tools & API Access) to fetch exactly the records a step needs rather than being
handed a bulk data dump — e.g. `get_customer_exposure(customer_id)` before drafting a quote for a
repeat customer, or `get_stock_availability(product_id, warehouse_id)` before recommending a
quantity.

# Outputs (confidence + reasoning + sources)

Every output the Sales Agent produces — proposal or plain answer — carries three things without
exception: a `confidence` score (`NUMERIC(4,3)`, 0.000–1.000), a `reasoning` string in the
requesting user's language (Arabic or English, matching the platform's bilingual mandate), and an
array of `supporting_document_ids` pointing at the specific quotations/orders/invoices/historical
records the output was derived from. There is no code path that emits a recommendation or an
assistant message without all three populated; a response the model produces without a resolvable
`supporting_document_ids` array is rejected by a post-generation validation pass and regenerated
(see Reasoning & Prompt Strategy → Grounding).

**Two output shapes**, matching the platform's two AI-output primitives:

**1. A proposal, persisted as a row in the Sales module's `ai_recommendations` table** (already
defined by the Sales module specification; reused verbatim, not redefined, here):

```sql
-- ai_recommendations (owned by Sales module's Database Design; reused by this agent)
CREATE TABLE ai_recommendations (
  id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  recommendable_type       VARCHAR(40) NOT NULL,   -- 'sales_quotations' | 'sales_orders' | 'invoices' | 'receipts' | 'customers' | ...
  recommendable_id         BIGINT NOT NULL,
  agent_name               VARCHAR(60) NOT NULL,   -- 'sales_agent'
  recommendation_type      VARCHAR(60) NOT NULL,   -- see table below
  payload                  JSONB NOT NULL,
  confidence               NUMERIC(4,3) NOT NULL,
  reasoning                TEXT NOT NULL,
  supporting_document_ids  JSONB NOT NULL DEFAULT '[]',
  status                   VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending | accepted | dismissed | expired
  acted_by                 BIGINT NULL REFERENCES users(id),
  acted_at                 TIMESTAMPTZ,
  created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT chk_ai_confidence CHECK (confidence >= 0 AND confidence <= 1)
);
```

`recommendation_type` values the Sales Agent writes: `price_suggestion`, `upsell_line`,
`cross_sell_line`, `credit_precheck_warning`, `stock_precheck_warning`, `fraud_score`,
`duplicate_lead`, `duplicate_order`, `collections_nudge`, `invoice_draft`, `receipt_draft`,
`receipt_allocation_suggestion`, `dormant_customer_flag`.

**2. A conversational reply, persisted as a row in `ai_messages`** (platform foundation table, reused
verbatim):

```json
{
  "id": 88213,
  "ai_conversation_id": 4471,
  "role": "assistant",
  "content": "This quote would bring Al Rayyan Trading's outstanding AR to KWD 5,940.000 against their KWD 6,000.000 limit — 99%. I'd suggest either trimming the order or flagging it for a credit-limit review before you send it.",
  "confidence_score": 0.910,
  "reasoning": "Current outstanding_ar_balance KWD 4,105.000 (3 open invoices) + this quotation's total_amount KWD 1,835.400.",
  "supporting_document_ids": [9931, 71180, 71204, 71299],
  "tokens_input": 2140,
  "tokens_output": 96,
  "latency_ms": 1380
}
```

**Worked output — a price suggestion payload** (`recommendation_type = 'price_suggestion'`):

```json
{
  "recommendable_type": "sales_quotation_items",
  "recommendable_id": 220981,
  "agent_name": "sales_agent",
  "recommendation_type": "price_suggestion",
  "payload": {
    "product_id": 205,
    "suggested_unit_price": "23.500",
    "suggested_discount_pct": "2.0",
    "price_list_item_id": 8842,
    "acceptance_rate_at_price": 0.78,
    "comparable_quotations": [55011, 54820, 54690]
  },
  "confidence": 0.780,
  "reasoning": "Similar wholesale customers (same price tier, same product category) accepted this unit price 78% of the time over the trailing 12 months (14 of 18 comparable quotations).",
  "supporting_document_ids": [55011, 54820, 54690],
  "status": "pending"
}
```

**Confidence banding.** The Sales Agent follows the same three-band convention used across every
other QAYD agent: below 0.50 the output is either suppressed or explicitly labeled "insufficient
history" rather than shown as a precise-looking number; 0.50–0.85 is shown as an editable suggestion
requiring a deliberate accept click; above 0.85 is shown as a one-click "Apply" affordance that
*still requires the click* — no responsibility in this document ever crosses from "one click" to
"zero clicks" purely on the strength of confidence. The one exception, `credit_risk_tier` auto-apply,
is not a confidence exception at all — it is a company-level policy opt-in (`auto_apply_risk_tier`),
identical in kind to `auto_post_invoices`, and is documented as such under Autonomy Level and
Guardrails & Human Approval.

# Tools & API Access (endpoint + permission-key table)

**Primary tool surface: `/api/v1/sales/*`.** The Sales Agent's LangGraph runtime is bound to the same
REST surface the Sales module exposes to the Next.js frontend — it calls no private, AI-only
endpoint for reading data. Every read tool below maps 1:1 onto an existing Sales module endpoint, at
the acting user's own permission scope:

| Tool (function name) | Maps to | Permission required |
|---|---|---|
| `list_quotations(filters)` | `GET /api/v1/sales/quotations` | `sales.quotation.read` |
| `get_order(order_id)` | `GET /api/v1/sales/orders/{id}` | `sales.order.read` |
| `get_customer_exposure(customer_id)` | `GET /api/v1/customers/{id}` (`outstanding_ar_balance`, `credit_limit`) | `customers.read` |
| `get_stock_availability(product_id, warehouse_id)` | `GET /api/v1/inventory/items` (ATP) | `inventory.read` (delegated via Inventory Manager) |
| `list_open_invoices(customer_id)` | `GET /api/v1/sales/invoices?filter[customer_id]=&filter[status]=overdue,partially_paid` | `sales.invoice.read` |
| `get_collection_case(customer_id)` | `GET /api/v1/customers/{id}/collection-cases` | `customers.collections.read` |
| `get_forecast(scope)` | delegates to Forecast Agent's own tool contract | `sales.ai.read` |
| `get_fraud_score(order_id)` | delegates to Fraud Detection agent's own tool contract | `sales.ai.read` |

**New endpoints this document introduces**, all under `/api/v1/sales/ai/*`, following the exact
`GET .../ai-suggestions`, `POST .../ai/proposals/{id}/approve`-style convention already established
by the Chart of Accounts, General Ledger, and Tax modules:

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/sales/ai/recommendations` | `sales.ai.read` | List this agent's pending/actioned recommendations, filterable by `recommendable_type`/`recommendation_type`/`status` |
| GET | `/api/v1/sales/ai/recommendations/{id}` | `sales.ai.read` | Retrieve one recommendation with full reasoning and sources |
| POST | `/api/v1/sales/ai/recommendations/{id}/accept` | `sales.ai.approve_draft` **+** the underlying resource permission (below) | Materialize the recommendation into a real Sales record through the module's normal validated path |
| POST | `/api/v1/sales/ai/recommendations/{id}/dismiss` | `sales.ai.read` | Dismiss with an optional reason; feeds the learning loop |
| GET | `/api/v1/sales/ai/quotations/{id}/price-suggestion` | `sales.ai.read` | On-demand price/discount band for a quotation line |
| GET | `/api/v1/sales/ai/quotations/{id}/upsell-candidates` | `sales.ai.read` | Ranked upsell/cross-sell candidates |
| GET | `/api/v1/sales/ai/orders/{id}/risk-score` | `sales.ai.read` | Current fraud/duplicate risk score and its drivers |
| GET | `/api/v1/sales/ai/forecast` | `sales.ai.read` | Revenue forecast by branch/channel/product category, P10/P50/P90 |
| GET | `/api/v1/sales/ai/customers/{id}/collections-nudge` | `sales.ai.read` | Draft dunning nudge and suggested channel/timing |
| POST | `/api/v1/sales/ai/conversations` | `sales.ai.read` | Start or continue a grounded chat thread |
| GET | `/api/v1/sales/ai/conversations/{id}/messages` | `sales.ai.read` | Retrieve the conversation transcript |

**The one new permission this document introduces is `sales.ai.approve_draft`.** Every other
permission referenced above (`sales.quotation.read`, `sales.order.read`, `sales.invoice.read`,
`sales.ai.read`, and so on) already exists in the Sales module's Permissions table and is reused
verbatim. Accepting a recommendation is a two-gate check, not a single generic override: the
accepting user must hold `sales.ai.approve_draft` **and** the specific permission the resulting
record would normally require — `sales.invoice.create` to accept an `invoice_draft`,
`sales.receipt.create` to accept a `receipt_draft`, `sales.quotation.update` to accept a
`price_suggestion`. The accept endpoint re-checks the second permission server-side at the moment of
acceptance; a user who holds `sales.ai.approve_draft` but not, say, `sales.receipt.create` cannot
accept a `receipt_draft` — the recommendation stays `pending` and the endpoint returns `403`.

**Consistent with the Sales module's existing Permissions table**, the `AI Agent` service-account
role's grant is unchanged by this document: read-mostly across `sales.*`, `customers.read`, and
`inventory.read`, plus write access solely to `ai_recommendations`, `ai_conversations`, `ai_messages`,
`ai_tasks`, and `ai_decisions`. The Sales Agent itself never holds `sales.ai.approve_draft`,
`sales.invoice.create`, `sales.invoice.post`, `sales.receipt.create`, `sales.order.confirm`, or any
other money-moving permission — that is the permission-layer enforcement of "AI never bypasses
authorization," applied identically to this agent as to every other.

**Example tool definition (function-calling schema)** exposed to the agent's own reasoning loop for
one of the higher-stakes tools:

```json
{
  "name": "get_customer_exposure",
  "description": "Return a customer's current AR exposure against their credit limit, for a pre-confirmation heads-up. Read-only; never modifies credit_limit.",
  "parameters": {
    "type": "object",
    "properties": {
      "customer_id": { "type": "integer", "description": "customers.id, scoped to the active company" }
    },
    "required": ["customer_id"]
  },
  "maps_to_endpoint": "GET /api/v1/customers/{customer_id}",
  "required_permission": "customers.read",
  "returns": {
    "outstanding_ar_balance": "NUMERIC(19,4)",
    "credit_limit": "NUMERIC(19,4)",
    "credit_risk_tier": "low | medium | high",
    "open_invoice_ids": "BIGINT[]"
  }
}
```

```json
{
  "name": "propose_recommendation",
  "description": "The agent's only write capability. Persists a proposal to ai_recommendations. Never creates, updates, or posts a Sales business record.",
  "parameters": {
    "type": "object",
    "properties": {
      "recommendable_type": { "type": "string" },
      "recommendable_id": { "type": "integer" },
      "recommendation_type": { "type": "string" },
      "payload": { "type": "object" },
      "confidence": { "type": "number", "minimum": 0, "maximum": 1 },
      "reasoning": { "type": "string" },
      "supporting_document_ids": { "type": "array", "items": { "type": "integer" } }
    },
    "required": ["recommendable_type", "recommendable_id", "recommendation_type", "payload", "confidence", "reasoning", "supporting_document_ids"]
  },
  "maps_to_endpoint": "internal — persisted directly to ai_recommendations by the FastAPI layer, not exposed as a Laravel write endpoint the agent calls",
  "required_permission": "ai_recommendations.create (granted to the AI Agent service-account role only)"
}
```

# Data Access & Tenant Scope

**Tenant isolation is inherited, not reimplemented.** The Sales Agent never opens a direct database
connection. Every read and every write it performs travels through the same Laravel API a human
user calls, which means every query is automatically scoped by `company_id` via the platform's
global model scope, bound to the `X-Company-Id` header the FastAPI layer forwards from the
originating request (or, for scheduled/background tasks with no originating request, from the
`ai_tasks.company_id` the task was queued against — see below). There is no code path by which the
Sales Agent can read or reason over two companies' data in the same turn; a query that omitted the
scope would fail the platform's static-analysis CI check before it could ever reach production,
identically to a human-facing endpoint.

**Read footprint.** The agent reads, at the acting user's own permission ceiling: every table listed
under Inputs. It never receives a table it is not scoped to — if the acting user's role lacks
`customers.read`, `get_customer_exposure` simply is not offered as an available tool for that turn,
and the agent degrades gracefully (see Failure Modes & Edge Cases) rather than erroring the whole
interaction.

**Write footprint — deliberately narrow.** The Sales Agent's only direct writes are to four tables,
all governed by the AI Agent service-account role's grant (read-mostly + these four):

- `ai_recommendations` (Sales module's own table, reused — see Outputs).
- `ai_conversations` / `ai_messages` (platform foundation tables, reused — see Outputs).
- `ai_tasks` and `ai_decisions` (introduced by this document; shared platform tables named but not
  yet given DDL by the AI foundation specification's list of "AI Tables — `ai_agents`, `ai_tasks`,
  `ai_memory`, `ai_logs`, `ai_decisions`." This document is the first to formalize `ai_tasks` and
  `ai_decisions`; they are shared across every agent in the roster, not exclusive to Sales, and any
  future agent document may extend their `task_type`/`decision_point` vocabularies without a schema
  change).

```sql
CREATE TABLE ai_tasks (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  ai_agent_id       BIGINT NOT NULL REFERENCES ai_agents(id),
  task_type         VARCHAR(60) NOT NULL,   -- 'forecast_refresh' | 'collections_sweep' | 'duplicate_sweep' | 'fraud_rescan' | ...
  status            VARCHAR(20) NOT NULL DEFAULT 'queued',  -- queued | running | completed | failed | cancelled
  scheduled_for     TIMESTAMPTZ NOT NULL,
  started_at        TIMESTAMPTZ,
  completed_at      TIMESTAMPTZ,
  input_payload     JSONB NOT NULL DEFAULT '{}',
  result_summary    JSONB,
  error             TEXT,
  retry_count       SMALLINT NOT NULL DEFAULT 0,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT chk_ai_task_status CHECK (status IN ('queued','running','completed','failed','cancelled'))
);
CREATE INDEX idx_ai_tasks_company_status ON ai_tasks (company_id, status, scheduled_for);
CREATE INDEX idx_ai_tasks_agent ON ai_tasks (ai_agent_id, task_type);

CREATE TABLE ai_decisions (
  id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id           BIGINT NOT NULL REFERENCES companies(id),
  ai_agent_id          BIGINT NOT NULL REFERENCES ai_agents(id),
  ai_conversation_id   BIGINT NULL REFERENCES ai_conversations(id),
  ai_task_id           BIGINT NULL REFERENCES ai_tasks(id),
  decision_point       VARCHAR(60) NOT NULL,  -- 'fraud_step_up' | 'invoice_draft_propose' | 'credit_tier_auto_apply' | 'collections_nudge_propose' | ...
  gate_evaluated       VARCHAR(20) NOT NULL,  -- auto | suggest_only | requires_approval
  triggered            BOOLEAN NOT NULL,
  confidence           NUMERIC(4,3) NULL,
  recommendation_id    BIGINT NULL REFERENCES ai_recommendations(id),
  outcome              VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending | accepted | dismissed | auto_applied | overridden
  decided_by           BIGINT NULL REFERENCES users(id),
  decided_at           TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT chk_ai_decision_gate CHECK (gate_evaluated IN ('auto','suggest_only','requires_approval'))
);
CREATE INDEX idx_ai_decisions_company_agent ON ai_decisions (company_id, ai_agent_id, decision_point);
CREATE INDEX idx_ai_decisions_recommendation ON ai_decisions (recommendation_id);
```

`ai_tasks` is the agent's autonomous background work queue — the concrete mechanism behind every
row in Inputs → Scheduled triggers. `ai_decisions` is the immutable record of every autonomy-gate
evaluation the agent's orchestration made, whether or not it produced a visible recommendation; it
is the evidence base for the Metrics & Evaluation section below and for any audit question of the
shape "did the AI ever have the chance to force step-up approval on this order, and did it?" Neither
table stores `ai_memory` — the per-company learned preferences (frequently accepted discount bands,
a company's own collections tone, preferred allocation method) are out of scope for this document
and owned by the dedicated AI Memory specification (`docs/ai/memory/`); the Sales Agent is a
*consumer* of that memory layer (see Reasoning & Prompt Strategy) but does not define its schema
here.

**PII minimization in transit to the FastAPI layer.** Exactly as specified for every other Sales AI
responsibility: when a Sales record crosses from the Laravel boundary into the FastAPI AI layer for
reasoning, customer PII (name, phone, email, address) is tokenized to a stable pseudonymous
identifier wherever the specific step does not need the raw value — a forecast or a fraud-scoring
pass needs `customer_id` and transaction-shape features, not the customer's legal name. The agent
re-hydrates display values only when composing the final response that Laravel renders back to an
authorized user's screen. Card/KNET payment references are never sent to the AI layer at all;
`receipts.reference_number` is a gateway token, and the agent's receipt-drafting tool receives only
the tokenized reference, never a PAN.

**Per-company memory isolation.** Any learned pattern the agent draws on (via the AI Memory layer) is
partitioned by `company_id` at the storage layer, identically to every business table — there is no
shared embedding index or fine-tuned weight set across companies; only the base model and its
system prompt are shared platform-wide, and even the system prompt's *variables* (a company's
approval thresholds, its configured collections cadence) are company-scoped inputs, not baked into a
shared prompt.

# Reasoning & Prompt Strategy

**Orchestration shape: a LangGraph supervisor graph.** The Sales Agent is implemented as a
supervisor node in a LangGraph state machine, not a single monolithic prompt. Each turn — whether
triggered by a human message, a domain event, or a scheduled `ai_tasks` row — enters the graph at an
**Intake** node that classifies intent into one of a fixed set of responsibility routes (price
suggestion, upsell/cross-sell, credit/stock pre-check, fraud/duplicate score, collections nudge,
forecast, invoice/receipt draft, or a free-form question), then fans out to the specific worker
node(s) that route needs:

```
                      ┌───────────────┐
   human msg /  ──►   │    INTAKE     │  classify intent, resolve context_type/context_id
   event / task       │  (Sales Agent)│  load acting-user permission scope
                      └───────┬───────┘
                              │
             ┌────────────────┼────────────────────┬───────────────┬────────────────┐
             ▼                ▼                     ▼               ▼                ▼
      ┌────────────┐   ┌────────────┐        ┌────────────┐  ┌────────────┐   ┌────────────┐
      │  CFO tool   │   │ Forecast   │        │   Fraud     │  │ Inventory  │   │  General    │
      │(price band, │   │  Agent     │        │ Detection   │  │  Manager   │   │ Accountant  │
      │ risk tier)  │   │ (forecast) │        │ (score)     │  │  (ATP)     │   │ (GL sanity) │
      └──────┬──────┘   └──────┬─────┘        └──────┬──────┘  └──────┬─────┘   └──────┬──────┘
             └────────────────┴─────────────────────┴────────────────┴────────────────┘
                                             │
                                             ▼
                                     ┌───────────────┐
                                     │    ASSEMBLE    │  merge specialist outputs into one
                                     │  (Sales Agent) │  draft payload + one reasoning narrative
                                     └───────┬───────┘
                                             ▼
                                     ┌───────────────┐
                                     │   GROUND &     │  every numeric claim must resolve to a
                                     │   VALIDATE     │  supporting_document_id; else regenerate
                                     └───────┬───────┘
                                             ▼
                                     ┌───────────────┐
                                     │ propose_       │  writes ai_recommendations / ai_messages;
                                     │ recommendation │  never writes a business table
                                     └───────────────┘
```

Each worker node is itself a tool call against the owning specialist agent's own contract (Forecast
Agent's forecasting model, Fraud Detection's scoring model, CFO's pricing model) — the Sales Agent
never re-implements their statistics. Its own reasoning is concentrated in **Intake** (deciding what
this moment needs), **Assemble** (turning two or three specialist outputs into one coherent,
non-contradictory narrative a busy Sales Employee can act on in five seconds), and **Ground &
Validate** (see below).

**Structured generation for payloads, free text only for reasoning and chat.** Every
`ai_recommendations.payload` and every tool-call argument is generated against a JSON Schema (the
platform's Anthropic tool-use contract enforces this at the model layer); the model is never asked
to free-hand a JSON blob it might malform. Natural language is reserved for exactly two fields:
`reasoning` (always required, always cites specific record IDs by number, never "several
invoices" or "recent orders" without naming them) and the `content` of an `ai_messages` row when the
interaction is conversational.

**Grounding — the model may not invent a number.** A dedicated validation pass runs after generation
and before persistence: every quantity, amount, date, or percentage appearing in `reasoning` or in a
recommendation's `payload` must be traceable to a field on one of the records listed in
`supporting_document_ids`, or to a value returned by a tool call in this turn's trace. A response
that fails this check is not shown to the user; it is regenerated once with the specific failing
claim flagged back to the model, and if it fails a second time the turn falls back to a lower-
confidence, more conservative answer ("I can see this customer has open invoices but can't confidently
summarize the total right now — here's the list") rather than ever surfacing an ungrounded figure.
This is the concrete, code-level enforcement of the platform's "no hallucinated accounting entries"
safety rule, applied to Sales' own numbers with the same rigor Accounting applies to journal entries.

**Retrieval: structured lookups first, semantic search second.** The large majority of the Sales
Agent's context needs (a customer's exposure, an order's line items, a product's price-list entry)
are exact, structured lookups via the tool table above — there is no reason to embed a `SELECT` when
a foreign key resolves it. Vector/semantic search (pgvector-backed, per the platform's AI Memory
layer) is reserved for the genuinely fuzzy tasks: finding *comparable* historical quotations for a
price suggestion (same product category, same customer tier, same channel, ranked by embedding
similarity over a feature vector of quantity/price/customer-segment, not by exact match), and fuzzy
duplicate-lead/customer matching (name/phone/email similarity) alongside the deterministic exact-
identifier check that already lives in the database layer.

**Interruption discipline.** Approval Assistant's upsell/cross-sell candidates and the Sales Agent's
own price suggestions are surfaced only at natural pause points the frontend already exposes to the
agent as state (a quotation saved as `draft`, a line just added, a rep opening the "review before
send" panel) — never mid-keystroke and never while a `recorded_call_reference` session is flagged
active for a Phone-channel sale, so the agent does not talk over a rep who is mid-negotiation on a
live call.

**System-prompt versioning.** `ai_agents.system_prompt_version` (`'v1'` at this document's writing)
is bumped on any material change to the Intake classification rules, the Assemble merge logic, or
the Grounding validation strictness; `ai_conversations` rows keep the prompt version active when they
started (mirroring the platform's `autonomy_level` in-flight rule), so a support investigation into
"why did the agent say X" can always reproduce the exact prompt that produced it.

# Collaboration With Other Agents

The Sales Agent is a conductor, not a soloist. Every specialized computation it surfaces is owned,
computed, and versioned by the specialist agent named in the Sales module's own AI Responsibilities
table; this section specifies exactly what the Sales Agent asks each collaborator for, in what
shape, and when.

| Collaborator | Sales Agent asks for | Returns | Triggered by |
|---|---|---|---|
| **General Accountant** | GL account-mapping sanity check on a drafted invoice/credit-note payload before it is surfaced; joint answer to "why did this customer's AR balance move" questions | Pass/fail + suggested account corrections; a narrative contribution merged into the Sales Agent's reply | Every `invoice_draft`/`receipt_draft` proposal, pre-surface; ad hoc on a customer-balance question |
| **Forecast Agent** | Statistical revenue forecast (time-series + seasonality + pipeline-weighting) for a branch/channel/category scope; per-customer next-order/renewal date prediction | A P10/P50/P90 range with model confidence; a predicted date + confidence for a specific customer | Nightly `ai_tasks` sweep; on-demand via `GET /api/v1/sales/ai/forecast`; on `sales_order.confirmed` for renewal-prediction refresh |
| **Fraud Detection** | Real-time fraud/duplicate risk score for a lead, quotation, or order; fuzzy duplicate-customer/duplicate-order candidates | A 0–100 score with named drivers; a ranked candidate list with similarity confidence | Every quotation-acceptance and order-confirmation attempt; nightly duplicate sweep |
| **Inventory Manager** | Available-to-promise quantity for a product/warehouse; current reservation status for a confirmed order's lines | ATP quantity; reservation/backorder state | Quote-drafting time (advisory), pre-confirmation heads-up, delivery-readiness nudges |
| **CFO** | Price/discount band suggestion for a quotation line; `credit_risk_tier` recommendation for a customer | Suggested price + acceptance-rate confidence; `low\|medium\|high` tier + reasoning | Every quotation line as it is priced; on new-customer creation and periodically thereafter |
| **Approval Assistant** | Ranked upsell/cross-sell candidates for the current cart/quotation; co-drafted dunning message copy for a collections nudge | 1–3 candidate products with affinity rationale; a draft message body in the customer's language | At quote review; when a collections-nudge recommendation is assembled |
| **Reporting Agent** | Customer health/segmentation summary (LTV trend, churn risk, recommended next action); dormant-customer batch flag | A structured summary the Sales Agent renders inline on the customer screen | Opening a customer detail screen from Sales; weekly dormant-customer digest |
| **Treasury Manager** | Predicted next-payment date and confidence for a specific overdue customer (feeds nudge timing) | `ai_predicted_next_payment_date`, `ai_predicted_payment_confidence` | Nightly overdue-invoice sweep, before drafting a collections nudge |
| **Document AI / OCR Agent** | Extraction of line items from an inbound remittance advice, marketplace payout statement, or scanned cheque | Structured `{amount, reference, matched_customer_candidates}` | An attachment lands on an unmatched incoming-payment signal |
| **Compliance Agent** | Verification that a proposed return-window override, cross-border promo, or discount pattern does not violate a configured GCC consumer-protection or export rule | Pass/fail + citation to the specific configured rule | Return-window-override drafting; cross-border quotation drafting |

**Sequence — quotation to price suggestion (the most frequent single interaction):**

```
Sales Employee opens quote builder, adds product 205, qty 50
        │
        ▼
Sales Agent (Intake) — classifies: "line just added" → price_suggestion route
        │
        ├──► CFO tool: suggest price/discount for (product 205, customer 1042, qty 50, channel wholesale)
        │        └─ returns: unit_price 23.500, discount 2.0%, confidence 0.78, comparable IDs [55011,54820,54690]
        ├──► Inventory Manager tool: ATP(product 205, warehouse 3)
        │        └─ returns: 340 on hand, 60 reserved, ATP 280 — comfortably covers qty 50
        └──► General Accountant tool: (skipped — no invoice/credit-note payload at this stage)
        │
        ▼
Sales Agent (Assemble) — one suggestion card: price + one-line reasoning + ATP confirmation
        │
        ▼
propose_recommendation() → ai_recommendations (recommendation_type='price_suggestion', status='pending')
        │
        ▼
Sales Employee reviews inline in the quote builder → accepts → sales.quotation.update called
        under the Employee's own session, exactly as if they had typed the price themselves
```

No specialist agent's output reaches the Sales Employee unfiltered; the Sales Agent is always the
last node before `propose_recommendation`, which is precisely why it — and not any specialist
individually — is the accountable owner of the module's proposal surface.

# Guardrails & Human Approval

**The non-negotiable list — restated, not relaxed.** Per the Sales module's Sales Philosophy and AI
Responsibilities sections, no agent in this module — the Sales Agent included — is ever authorized
to: change `customers.credit_limit`; post a `journal_entries` row; confirm a `sales_orders` row;
post an `invoices` row; process a `refunds` row; approve or disposition a `sales_returns` row; void
an invoice; or post a `credit_notes` row. Every one of these remains a human action, or an explicit,
separately-audited company automation policy (`auto_post_invoices`, `pos_combined_checkout`,
`auto_apply_risk_tier`) that is itself a company configuration choice made by a human with the
appropriate permission — never an inference this agent makes turn-to-turn.

**Permission-layer enforcement, not just prompt instructions.** The guardrail above is not "the
model has been told not to." The AI Agent service-account role, as fixed in the Sales module's
Permissions table, structurally cannot call `sales.order.confirm`, `sales.invoice.post`,
`sales.receipt.create`, `sales.refund.create`, `sales.credit_note.post`, or `sales.return.approve` —
those permission grants simply do not exist on that role. Even a compromised or misbehaving model
running inside this agent cannot cross the boundary, because the boundary is enforced by Laravel's
permission middleware on every request, independent of what the model decided to attempt.

**The accept action is a human action wearing the agent's paperwork.** When a Sales Employee accepts
an `invoice_draft` or `receipt_draft` recommendation, the record that gets created is created by
Laravel's ordinary `sales.invoice.create`/`sales.receipt.create` service path, executed under the
*accepting human's* session and permission grant — the agent's role never executes it. This is why
`sales.ai.approve_draft` is deliberately a *second*, additive gate rather than a bypass: it answers
"is this user allowed to rubber-stamp an AI draft at all" while the underlying `sales.invoice.create`
check still separately answers "is this user allowed to create this specific kind of record,"
exactly as it would for a document the human typed by hand.

**Step-up, never rejection, on fraud signal.** A fraud/duplicate score at or above the configurable
threshold (default 70/100) forces the affected quotation or order into `pending_approval` — it
raises the bar, it never lowers the door. The Sales Agent has no code path to reject a transaction,
blacklist a customer, or cancel an order on its own initiative; the strongest unilateral action
available to it anywhere in this document is *requiring a second pair of human eyes*.

**Reason is mandatory on acceptance of high-stakes recommendation types.** Consistent with the Sales
module's audit-log requirement that credit overrides, discount overrides, and void/cancel actions
always carry a `reason`, accepting a `credit_precheck_warning` override, a `fraud_score`-triggered
step-up override, or a `collections_nudge` that deviates from the suggested channel/timing requires
a non-blank `reason` on the accept call; the endpoint rejects with `422` if it is blank. This is
enforced in the accept endpoint's FormRequest validation, not left to a UI placeholder.

**Every gate evaluation is logged, whether or not it produced a visible recommendation.** Every pass
through the LangGraph's Assemble/Ground nodes writes an `ai_decisions` row recording which gate
(`auto`/`suggest_only`/`requires_approval`) applied and whether it triggered — including the (rare,
`auto`-tier) cases where nothing was shown to a human at all, such as a deterministic
comparable-quotation lookup that fed a price suggestion but was not itself a judgment call. This
closes a gap a pure `ai_recommendations`-only trail would leave: `ai_recommendations` proves what the
agent *proposed*; `ai_decisions` proves what the agent's orchestration *considered*, satisfying an
auditor's question of the form "could this order have been auto-approved, and why wasn't it."

**Kill switch, at two levels.** A company can disable the Sales Agent entirely, or force it to
`suggest_only` regardless of any individual action's table above, by setting a company-level
`ai_agents` override row with `status = 'disabled'` or a stricter `autonomy_level` — the platform-
wide trigger already defined on `ai_agents` rejects any company override that is *looser* than the
company's own `company_settings.ai_autonomy_level` ceiling, so a company can only ever tighten, never
loosen, the agent below the platform default. In-flight `ai_conversations` retain the autonomy level
that applied when they started, so a mid-conversation policy change never silently changes what an
already-open thread is permitted to do.

**Idempotency is untouched.** The Sales Agent's fuzzy duplicate-detection pass (on leads, customers,
and orders) is explicitly advisory and layers on top of, never replaces, the deterministic
`Idempotency-Key`-based duplicate-order block already enforced at the API layer — a retried POS
request is blocked by the idempotency key regardless of whether the agent's fuzzy pass ever runs.

**PCI and payment-data boundary.** As specified under Data Access & Tenant Scope, the agent never
receives a raw card number or CVV in any tool result — `receipts.reference_number` is always a
gateway-issued token by the time it reaches the AI layer, consistent with the platform's PCI-DSS
SAQ-A scope-minimization posture.

**Rate limiting on the `/ai/*` surface.** The `/api/v1/sales/ai/*` endpoints inherit the same
per-account and per-IP rate limiting as the rest of the Sales API; a scripted attempt to enumerate
`GET /recommendations/{id}` across IDs to probe another company's data fails on the tenant-scope
check long before rate limiting would even be the relevant control, but the rate limit still applies
as defense in depth.

# Worked Scenarios (2-3 end-to-end)

## Scenario 1 — Wholesale quotation through to an accepted invoice draft

**Setup.** Al Rayyan Trading Co. W.L.L., a Kuwait City wholesale customer (`customer_id = 1042`,
`credit_limit = KWD 6,000.000`, `outstanding_ar_balance = KWD 3,120.000`), calls in a repeat order.
Sales Employee Fahad opens the quote builder and adds two lines: product 205 (electrical connectors)
× 50, product 318 (cable ties, bulk) × 20.

1. As Fahad adds the product 205 line, the Sales Agent's Intake node classifies `line_added` and
   routes to CFO (price suggestion) and Inventory Manager (ATP) in parallel, per the Collaboration
   sequence diagram. CFO returns `unit_price 23.500`, `discount 2.0%`, confidence `0.78`, citing three
   comparable wholesale quotations. Inventory Manager returns ATP `280` against a requested `50` —
   no stock concern. The Sales Agent assembles and writes a `price_suggestion` recommendation; Fahad
   accepts it with one click, and `sales.quotation.update` runs under his own session.
2. Fahad repeats for product 318; CFO suggests `unit_price 32.000`, confidence `0.83`. Accepted.
3. Before Fahad sends the quotation, the Sales Agent's Assemble node also runs the credit pre-check
   tool: `get_customer_exposure(1042)` returns `outstanding_ar_balance 3,120.000`,
   `credit_limit 6,000.000`. This quotation's `total_amount` (after the two accepted lines, tax
   included) is `KWD 1,835.400`. `3,120.000 + 1,835.400 = 4,955.400`, which is 82.6% of the limit —
   below the company's configured 90% advisory threshold, so no `credit_precheck_warning` is raised;
   the agent stays silent on credit, which is itself a deliberate design choice (see Reasoning &
   Prompt Strategy → Interruption discipline) — it does not manufacture a warning nobody needs.
4. Fahad sends the quotation (`sales.quotation.send`, human action, unaffected by the agent).
   Al Rayyan accepts by phone; a rep records `accepted_via = 'verbal'`. `sales_orders` row 9931 is
   created and confirmed — the deterministic credit/stock/pricing gates at `/orders/9931/confirm` run
   exactly as specified in the Sales module's own Sales Orders section, independent of anything the
   agent did above; they pass (`credit_check_status: 'passed'`).
5. `sales_order.confirmed` fires. The Sales Agent's Fraud Detection collaboration call returns a
   score of `8/100` (well below the 70 threshold) — no step-up.
6. Delivery 20441 is picked, packed, shipped, and marked `delivered`, emitting `delivery.delivered`.
   Because `billing_policy = 'on_delivery'`, the Sales Agent's Intake node classifies this event as
   an `invoice_draft` opportunity. It calls General Accountant's mapping-sanity tool against the
   line items' revenue/COGS account mapping (pass, no anomaly), then assembles and writes:

```json
{
  "recommendable_type": "invoices",
  "recommendable_id": null,
  "agent_name": "sales_agent",
  "recommendation_type": "invoice_draft",
  "payload": {
    "sales_order_id": 9931,
    "delivery_id": 20441,
    "customer_id": 1042,
    "currency_code": "KWD",
    "items": [
      { "delivery_item_id": 55021, "product_id": 205, "quantity": "50.0000", "unit_price": "23.5000" },
      { "delivery_item_id": 55022, "product_id": 318, "quantity": "20.0000", "unit_price": "32.0000" }
    ],
    "subtotal_amount": "1815.0000",
    "tax_amount": "90.7500",
    "total_amount": "1905.7500"
  },
  "confidence": 0.960,
  "reasoning": "Delivery 20441 fully delivered against confirmed order 9931; quantities and prices match the order lines exactly; General Accountant's account-mapping check passed with no anomaly.",
  "supporting_document_ids": [9931, 20441, 55021, 55022],
  "status": "pending"
}
```

7. Finance user Sara, holding both `sales.ai.approve_draft` and `sales.invoice.create`, opens the
   recommendation, reviews it against the delivery note, and accepts. The accept endpoint verifies
   both permissions, then calls the ordinary `POST /api/v1/sales/invoices` path under Sara's session,
   creating `invoices` row 71310 in `status = 'draft'` — precisely the same state a hand-typed
   invoice would land in. Sara separately calls `sales.invoice.post` to post it; the Accounting
   Engine's posting service creates the balanced journal entry exactly as specified under Accounting
   Integration. The Sales Agent never called `sales.invoice.create` or `sales.invoice.post` itself.

## Scenario 2 — Collections nudge on an aging invoice

**Setup.** Invoice `INV-KWC-2026-13850` for customer 2210 (a wholesale account) is `KWD 2,240.000`,
`due_date` 2026-06-16, now 34 days overdue (`status = 'overdue'`, 31–60 aging bucket).

1. The nightly `ai_tasks` sweep (`task_type = 'collections_sweep'`) picks up every company's overdue
   invoices. For customer 2210, the Sales Agent calls Treasury Manager's payment-prediction tool,
   which returns `ai_predicted_next_payment_date = 2026-07-29`, `ai_predicted_payment_confidence =
   0.62`, drawing on the customer's historical days-to-pay distribution — this is the same number
   Customer Management's `customer_balances` row already carries; the Sales Agent does not
   recompute it.
2. It checks `customer_collection_cases` for an open case on this customer: none exists yet (this
   invoice just crossed into the 31–60 bucket; the company's configured auto-open trigger is the
   61–90 bucket, so no case has auto-opened). The agent still surfaces a proactive nudge ahead of
   that auto-open point, because the predicted payment date (July 29) is itself 13 days past the
   nudge's own review date — a signal worth a human's attention now rather than waiting three more
   weeks for the case to auto-open.
3. It requests a draft dunning message from Approval Assistant, in Arabic (the customer's recorded
   language preference), and assembles:

```json
{
  "recommendable_type": "invoices",
  "recommendable_id": 68850,
  "agent_name": "sales_agent",
  "recommendation_type": "collections_nudge",
  "payload": {
    "customer_id": 2210,
    "suggested_channel": "email",
    "suggested_tier": "day_7_gentle_with_rep_cc",
    "draft_message_ar": "تحية طيبة، نود تذكيركم بأن الفاتورة INV-KWC-2026-13850 بمبلغ 2,240.000 د.ك مستحقة منذ 34 يوماً. يرجى التواصل معنا لترتيب السداد.",
    "predicted_payment_date": "2026-07-29",
    "predicted_payment_confidence": 0.62
  },
  "confidence": 0.710,
  "reasoning": "Invoice 68850 is 34 days overdue (31-60 bucket); Treasury Manager's payment prediction (0.62 confidence) suggests payment is unlikely before day 47 without contact; no open collection case exists yet.",
  "supporting_document_ids": [68850, 2210],
  "status": "pending"
}
```

4. Collections Officer Nour reviews the draft on the AR/collections dashboard, edits one sentence,
   and accepts. Accepting a `collections_nudge` does not itself send anything — it is scoped
   (per Autonomy Level) to drafting only; Nour's acceptance triggers the platform's notification
   service to actually send the (human-approved) message, and — because this is the first contact —
   the Customers module's own logic opens a `customer_collection_cases` row in state `contacted`,
   `last_contacted_at = now()`, `contact_method = 'email'`. The Sales Agent never wrote to
   `customer_collection_cases` directly; it only proposed, and the acceptance path's downstream
   effect on that table belongs to the Customer Management module exactly as specified there.

## Scenario 3 — Fraud step-up on a suspicious rapid order sequence

**Setup.** A new POS-channel customer record was created eleven minutes ago. Three orders are placed
in the next four minutes, each just under the company's KWD 500 no-approval retail threshold, each
paying by a different card.

1. On the third order's confirmation attempt, the Sales Agent's Fraud Detection collaboration call
   returns a score of `82/100`, drivers: `["3 orders from a customer created <15 min ago", "each order individually just under the approval threshold", "3 distinct payment cards on one new customer within 4 minutes"]`.
2. Because `82 ≥ 70`, the order is forced into `pending_approval` regardless of its individual value
   being under the retail auto-approval threshold — the Sales Agent's `ai_decisions` row records
   `decision_point = 'fraud_step_up'`, `gate_evaluated = 'requires_approval'`, `triggered = true`,
   `confidence = 0.820`.
3. A `sales_order.credit_blocked`-equivalent notification (structurally identical to the existing
   `ai_recommendations.created` high-confidence notification path) fires to the Sales Manager role,
   in-app, since this is a `requires-approval` autonomy-level recommendation.
4. The Sales Manager reviews the three orders side by side, the customer's ten-minute-old profile,
   and the score's stated drivers, and — this being a legitimate same-day multi-card corporate buyer
   in this instance — approves all three with `credit_override_reason: "Verified by phone with buyer; legitimate multi-card corporate purchase, confirmed with customer's finance office"`.
   The Sales Agent never rejected anything; it only ever raised the bar, exactly as specified under
   Guardrails & Human Approval.
5. This outcome (`overridden`, approved) is written back as the `ai_decisions.outcome` and feeds the
   Fraud Detection agent's own model-quality metrics (see Metrics & Evaluation) as a labeled
   false-positive-leaning example for future calibration — the score was not "wrong" (the pattern was
   genuinely unusual and worth a human look), but the eventual human judgment that it was legitimate
   is retained as a training signal, exactly as the Sales module's own Payments section describes for
   a bounced-cheque retraining signal.

# Metrics & Evaluation

Every metric below is computed from `ai_recommendations`, `ai_decisions`, `ai_tasks`, and
`ai_messages` — the agent's entire footprint is instrumented by construction, not bolted on
afterward.

**Suggestion quality, per `recommendation_type` and per company:**

| Metric | Definition | Target / alert threshold |
|---|---|---|
| Acceptance rate | `accepted / (accepted + dismissed)`, excluding `pending`/`expired` | Below 30% sustained over 4 weeks for any `recommendation_type` triggers a prompt/model review |
| Time-to-decision | `acted_at - created_at` | Price suggestions: p50 < 2 minutes (in-flow); collections nudges: p50 < 1 business day |
| Confidence calibration | Actual acceptance rate within each confidence decile bucket (0.50–0.59, 0.60–0.69, …) plotted against the bucket's midpoint | A well-calibrated agent's 0.80–0.89 bucket accepts ~80–89% of the time; systematic over/under-confidence beyond 10 points triggers recalibration |
| Edit-before-accept rate | Share of accepted recommendations where `payload` differs from what was ultimately created (e.g. Nour's edited dunning sentence in Scenario 2) | High rates on a specific type flag a template/prompt needing improvement, not a failure — tracked as a leading indicator, not penalized |

**Business-outcome metrics:**

| Metric | Definition |
|---|---|
| Price-suggestion win rate | Share of accepted price suggestions on quotations that convert to a confirmed sales order, vs. the company's baseline win rate on rep-only pricing |
| Upsell/cross-sell attach rate & incremental revenue | Share of quotations with an accepted upsell/cross-sell line; `Σ line_total` of those lines specifically |
| Forecast accuracy | WAPE (weighted absolute percentage error) of Forecast Agent's P50 against realized `invoices.total_amount` for the same scope/period, tracked monthly per branch/channel |
| Collections-nudge effectiveness | Share of accepted nudges followed by a payment or a `promise_to_pay` case-state transition within 14 days, vs. a randomized/held-out control group of un-nudged equally-overdue invoices |
| Fraud precision/recall | Precision: share of `requires-approval` step-ups later confirmed as genuinely fraudulent (chargeback, confirmed stolen-card, confirmed identity mismatch) vs. approved-as-legitimate (Scenario 3's outcome). Recall: share of eventual confirmed-fraud cases that had been scored ≥ threshold before the loss occurred |
| Duplicate-detection precision | Share of surfaced `duplicate_lead`/`duplicate_order` flags a human confirms as a true duplicate (merges or blocks) vs. dismisses as unrelated |
| Credit pre-check value | Count of `credit_precheck_warning` recommendations that preceded an order which, absent the warning, would have failed the deterministic confirmation gate — measuring rework avoided, not just recommendations shown |

**Operational metrics:**

- Tool round-trip latency, p50/p95, per collaborator (a Fraud Detection call consistently at p95 >
  2s degrades the in-flow price-suggestion experience and is paged).
- Token cost per recommendation and per conversation turn, tracked against `system_prompt_version`
  cohorts to catch a prompt change that silently balloons cost without improving acceptance.
- `ai_tasks` failure rate and retry count, per `task_type` — a `collections_sweep` failing silently
  for a week is a materially worse outcome than a single price suggestion misfiring, and is alerted
  distinctly.

**Example query — acceptance rate by recommendation type, trailing 30 days:**

```sql
SELECT
  recommendation_type,
  COUNT(*) FILTER (WHERE status = 'accepted') AS accepted,
  COUNT(*) FILTER (WHERE status = 'dismissed') AS dismissed,
  ROUND(
    COUNT(*) FILTER (WHERE status = 'accepted')::NUMERIC
    / NULLIF(COUNT(*) FILTER (WHERE status IN ('accepted','dismissed')), 0),
    3
  ) AS acceptance_rate
FROM ai_recommendations
WHERE agent_name = 'sales_agent'
  AND company_id = :company_id
  AND created_at >= now() - INTERVAL '30 days'
GROUP BY recommendation_type
ORDER BY acceptance_rate DESC NULLS LAST;
```

**Model/prompt cohort comparison.** Because `ai_conversations`/`ai_recommendations` do not
themselves carry `system_prompt_version`, it is resolved via the owning `ai_agents` row at the time
of creation for cohort analysis — a prompt change is evaluated by comparing acceptance rate and
calibration for recommendations created before vs. after the version bump, holding
`recommendation_type` and company segment constant, before it is rolled out platform-wide.

# Failure Modes & Edge Cases

**Cold start / thin history.** A brand-new company, or a customer with fewer than three transactions,
has no comparable quotations for CFO's price-band model and no meaningful days-to-pay distribution
for Treasury Manager's prediction. The Sales Agent surfaces "insufficient history" rather than a
falsely precise number (identical to the confidence-banding convention specified under Outputs), and
suppresses the dashboard alert entirely below 0.50 confidence rather than showing a low-confidence
badge that would train users to distrust every badge.

**A collaborator tool times out or errors.** If Inventory Manager's ATP call fails mid-turn, the
Sales Agent does not fail the entire interaction — it assembles the response without the stock
angle, and its `reasoning` explicitly states the omission ("stock availability could not be checked
right now — please verify manually before confirming") rather than silently proceeding as if stock
had been confirmed available. The corresponding `ai_decisions` row records `gate_evaluated` as
attempted with a `result_summary` noting the degraded path, so a pattern of repeated Inventory
Manager timeouts is itself visible as an operational metric (see Metrics & Evaluation).

**Specialist agents disagree.** Forecast Agent's pipeline-weighted forecast shows rising demand from
a customer segment in the same period Fraud Detection flags one specific customer in that segment
for a sudden order-size jump. The Sales Agent does not silently resolve this in either direction — it
surfaces both signals distinctly in the Assemble step ("demand from this segment is trending up
overall; this specific customer's jump is also flagged as an outlier worth verifying") rather than
either suppressing the fraud flag because the trend is favorable, or suppressing the positive trend
because one flagged case exists. Reconciling a genuine conflict between specialist signals is, by
definition, a judgment call above the agent's mandate — it is handed to the human unresolved.

**A recommendation goes stale.** If a quotation's lines are edited (a new discount applied, a line
removed) after a `price_suggestion` or `upsell_line` recommendation was written but before it is
acted on, the recommendation's `recommendable_id` context no longer matches current state. A
lightweight freshness check at accept-time (comparing the quotation's `updated_at` against the
recommendation's `created_at`) invalidates a stale recommendation to `status = 'expired'` and
prompts a fresh suggestion rather than applying a suggestion computed against data that no longer
exists — mirroring the platform's general optimistic-locking philosophy for concurrent edits.

**Concurrent acceptance race.** Two users with overlapping permissions both open the same
`invoice_draft` recommendation and click accept within the same second. The accept endpoint's
underlying `sales.invoice.create` call inherits the Sales module's standard idempotency and
optimistic-locking behavior; the second request's re-fetch of the recommendation finds
`status = 'accepted'` already set (a `SELECT ... FOR UPDATE`-guarded transition) and returns `409
Conflict` rather than creating a duplicate invoice — this is the same mechanism, not a bespoke one,
as the platform's general concurrent-edit handling.

**Company disables or tightens the agent mid-conversation.** Per Guardrails & Human Approval, an
in-flight `ai_conversations` row retains the autonomy level active when it started; a company that
tightens `ai_agents.autonomy_level` mid-day does not retroactively alter an open conversation's
already-established behavior, only conversations and tasks starting after the change.

**Cross-tenant leakage attempt.** A test (and a CI-enforced static-analysis check, reused from the
Sales module's own Security section) specifically targets whether any tool the Sales Agent can call
could be parameterized to return another company's row; because every tool is a thin wrapper over an
already-scoped Laravel endpoint, there is no code path in this agent's own implementation that could
introduce such a leak independent of the underlying endpoint's own scope enforcement — the agent adds
no new attack surface here, which is itself a design goal, not an incidental property.

**Sanctioned or high-risk customer interplay.** If Fraud Detection's customer-level sanctions/
watchlist match (owned by Customer Management, per that module's Fraud Detection section) is active
on a customer, the Sales Agent reflects that state (a visible flag on the customer context) but does
not itself block, cancel, or refuse to draft a quotation for that customer — blacklisting remains
exclusively the Finance Manager/Auditor-reviewed workflow specified there; the Sales Agent's role is
limited to making the existing flag impossible to miss in the sales flow.

**Return-abuse pattern short of automatic action.** A customer's return-to-purchase ratio crossing an
abnormal threshold is a Fraud Detection signal the Sales Agent surfaces on the customer record (per
the Sales module's Returns section) — it never auto-rejects a return request or auto-flags the
customer as blacklisted; the human return-approval workflow is entirely unaffected in its own
authority.

**Marketplace/multi-party receipt ambiguity.** An incoming marketplace payout batch nets several
orders' worth of commission-adjusted proceeds in one bank line. The Sales Agent's `receipt_draft`
proposal for such a case explicitly lists each candidate order/invoice its allocation-suggestion tool
matched, with a per-line confidence, rather than proposing one opaque lump-sum allocation — a human
reconciler can accept the high-confidence lines and manually resolve only the ambiguous remainder,
instead of an all-or-nothing accept.

**Currency/FX edge cases in forecasting.** A forecast scope spanning multiple `currency_code`s
(a company selling in KWD, SAR, and USD) is always computed and displayed in base currency
(`base_currency_total_amount`), with the exchange-rate-conversion date range noted in the
`reasoning`, so a forecast is never silently distorted by FX movement within the historical window
being read.

# Future Improvements

- **Third-party credit bureau integration.** Customer Management's own Risk Analysis notes this gap
  explicitly for v1; once available, the Sales Agent's credit pre-check gains an external data point
  alongside the internal AR-exposure calculation, still surfaced as advisory only.
- **Fully automated low-risk collections sequences.** Customer Management's Collections section
  already anticipates this: a company may eventually opt a specifically low-risk customer segment
  into template-based, fully automated dunning at the earliest (day-1, gentle-reminder) tier only,
  as an explicit company policy decision — never as a confidence-based agent decision — with every
  later, firmer tier remaining human-drafted exactly as today.
- **Real-time competitor price signal ingestion.** CFO's Price Suggestions currently reason from
  internal comparable-quotation history only; external market-price feeds (where a company opts in
  and a data source exists) would sharpen the suggested band, particularly for commodity-like product
  categories with thin internal comparables.
- **Channel-native collections nudges.** WhatsApp Business and native SMS gateways, in addition to
  the current email-centric dunning draft, matching how customers in this region actually prefer to
  be reached — still human-sent, per the unchanged autonomy level.
- **Negotiation-aware quote optimization.** For multi-round B2B quotation negotiations
  (`sales_quotations.version` increments), a future iteration could model the *negotiation trajectory*
  itself (which concessions a given customer segment historically responds to, in what order) rather
  than pricing each version independently — still suggest-only, surfaced to the rep drafting the next
  version.
- **Expanding the auto-tier, conservatively, with measured precision.** `auto_post_invoices` and
  `pos_combined_checkout` are existing company policies, not agent decisions; a future candidate
  (explicitly out of scope for this version) is an equivalently narrow, opt-in, measured-precision
  auto-tier for receipt *allocation* (not creation) on a company's designated low-ambiguity payment
  channel — gated on a sustained, measured allocation-accuracy track record per Metrics & Evaluation,
  and reversible via the same kill switch specified under Guardrails & Human Approval.
- **Multi-modal remittance capture.** Deeper collaboration with Document AI/OCR Agent to parse
  photographed cheques and handwritten remittance notes directly into `receipt_draft` proposals,
  reducing the manual data-entry step that currently precedes the agent's involvement in some
  cash-heavy retail segments.

# End of Document
