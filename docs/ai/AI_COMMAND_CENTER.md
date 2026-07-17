# AI Command Center — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: AI_COMMAND_CENTER
---

# Purpose

Every accounting product before QAYD ships the same home screen: a grid of static charts computed from last night's batch job, a handful of KPI tiles that update once a day, and a search bar in case you already know what you're looking for. It is a *report surface*. It waits. It answers only the question you thought to ask, and only after you have opened the app, clicked into the right module, and built the right filter.

The AI Command Center is not that. It is the home screen of an autonomous finance workforce, and it is built on a different premise: by the time a human opens QAYD in the morning, the work has already happened. Fifteen specialized agents — General Accountant, Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO Agent, Fraud Detection, Reporting Agent, Document AI, OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant, and CEO Assistant — have spent the night and the prior business day reading every posted transaction, every bank feed, every open invoice and bill, every payroll cycle and tax deadline, across every company they serve. Each has produced its findings as a confidence-scored, reasoned, cited row in `ai_decisions`. The Command Center's only job is to take that output and answer, the instant a human looks at the screen, the three questions QAYD's own design system commits every dashboard to answering: **What happened? What needs attention? What should I do next?** — and then to make each of those answers actionable in one click, one approval, or one spoken question, without the human ever having to go hunting for the number that justifies it.

This reframes the accountant's and the owner's relationship to the software. Traditional software waits for users; QAYD's AI works before the user asks. The bookkeeper does not build the trial balance — the General Accountant already posted it, and the Auditor already reviewed it overnight. The CFO does not pull last month's ratios into a slide before the board call — the CFO Agent already drafted the narrative, cited to the ledger rows that produced every number in it. The owner does not discover a fraud exposure three weeks later during a bank reconciliation — Fraud Detection held the payment before it left the building and is waiting, right now, on the home screen, for a human decision. **Accounting becomes supervised, not manual. The accountant becomes a supervisor of a workforce, not the sole operator of a ledger.**

Nothing about that reframing changes the platform's non-negotiable safety contract, and this document does not relax it anywhere:

- **Laravel 12 remains the single source of truth.** Every number, balance, and status the Command Center renders is read from data Laravel already validated, persisted, and authorized — `accounts`, `journal_entries`, `journal_lines`, `invoices`, `bills`, `bank_accounts`, `inventory_items`, `payroll_runs`, `tax_transactions`, `customers`, `vendors`, `products`, and the shared AI infrastructure tables (`ai_agents`, `ai_tasks`, `ai_memory`, `ai_logs`, `ai_decisions`, `ai_conversations`, `ai_messages`). The FastAPI AI layer never writes to PostgreSQL directly; every card on this screen that represents an AI *action* (not just an AI *observation*) is a proposal that must clear the Laravel API's validation and RBAC layer exactly like a human-initiated request would.
- **Every AI-authored element carries a confidence score, explicit reasoning, and cited sources.** No card on the Command Center shows a bare number the AI computed without showing its work. Section by section below, each panel's example includes the actual `confidence_score`, `reasoning`, and `sources` payload it renders from.
- **Sensitive actions still stop at the human gate.** Bank transfers, payroll release, tax submission, voiding or deleting financial data, and permission changes are never auto-executed by anything the Command Center surfaces, no matter how high the confidence score. The Approval Center (below) is the literal, visible embodiment of that gate — every proposal that needs a human signature routes through it, and this document names the exact permission key each gate checks.
- **Multi-tenancy is absolute.** Every widget, every AI decision, every cached briefing is scoped by `company_id`; `ai_memory` is per-company and is never blended across tenants; a user who belongs to two companies sees two entirely separate Command Centers, and switching the active company (`X-Company-Id`) swaps every card on the screen.
- **The AI obeys the same RBAC as the human looking at the screen.** The Command Center is not a superuser view. A Sales Employee and an Owner looking at the same company on the same morning see structurally different dashboards, because every widget's data endpoint enforces the same permission key a direct API call would. This is covered in depth per panel and again in User Journeys, where the External Auditor's read-only, time-boxed view is worked through explicitly.

## Running example used throughout this document

To keep thirty-two panels concrete instead of abstract, this document follows one company end to end: **Al-Noor Trading & Contracting W.L.L.** (`company_id: 4821`), a Kuwait-based building-materials trading and light-contracting business with three locations — Kuwait City HQ (`branch_id: 1`), the Al-Ahmadi yard and warehouse (`branch_id: 2`), and a VAT-registered Riyadh sales office in Saudi Arabia (`branch_id: 3`). Base currency is KWD; the Riyadh branch transacts in SAR at a maintained `exchange_rate` of 0.12220 KWD/SAR. Fiscal year 2026, calendar-aligned. Forty-seven employees (38 in Kuwait under PIFSS and Kuwait WPS, 9 in Riyadh under GOSI and Saudi WPS/Mudad).

| User | `user_id` | Role | Appears in |
|---|---|---|---|
| Fahad Al-Ostath | 101 | Owner | Morning Briefing, Approval Center, User Journeys §1 |
| Mariam Al-Sabah | 118 | CFO | Business Health Score, Approval Center, Ask AI, User Journeys §2 (same `user_id` as QAYD's `CFO_AGENT.md` reference example) |
| Khalid Marafie | 160 | Finance Manager | Approval Center (first-step approver) |
| Yousef Haidar | 124 | Senior Accountant | Approval Center, Fraud Alerts |
| Ahmad Kanaan | 131 | Payroll Officer | Payroll Alerts |
| Reem Boushehri | 140 | Inventory Manager (human role) | Inventory Alerts |
| Dana Al-Rashidi | 155 | External Auditor | User Journeys §3 (time-boxed access) |

All figures in every worked example below (cash balances, AR/AP, payroll, forecasts) belong to this one company on this one date — **2026-07-16, 06:00 +03:00** — so that a reader can trace a single number (say, the KWD 42,318.600 cash position) from the Cash Flow Status panel through the Financial Forecast, into a Simulation Panel run, into the Examples walkthrough, and out the other side into a User Journey, and see the same number behave consistently at every stop.

## Agent coverage map

Every one of the fifteen canonical agents feeds the Command Center. This table is the index the rest of the document points back to; each panel section restates only the subset relevant to it.

| Agent (`agent_code`) | Primary Command Center panels | Autonomy posture here |
|---|---|---|
| `CEO_ASSISTANT` | Morning Briefing, Ask AI (executive mode), Decision Support, Urgent Actions | Synthesizes; never executes |
| `CFO_AGENT` | Business Health Score, Financial Forecast, KPIs, Decision Support | Auto-computes ratios; suggest-only for recommendations |
| `TREASURY_MANAGER` | Cash Flow Status, Supplier Risks, Customer Risks, Approval Center (transfers) | Auto-monitors; requires approval to move money |
| `REPORTING_AGENT` | Revenue Trends, Expense Trends, KPIs, AI Insights | Auto-computes and narrates from posted data |
| `GENERAL_ACCOUNTANT` | AI Insights, Expense Trends, Ask AI | Auto-categorizes within confidence threshold; else suggests |
| `AUDITOR` | Detected Risks, Fraud Alerts, Payroll Alerts, Approval Center oversight | Suggest-only; escalates |
| `TAX_ADVISOR` | Upcoming Tax Deadlines, AI Recommendations | Prepares drafts; submission always human-gated |
| `PAYROLL_MANAGER` | Payroll Alerts, Today's Tasks | Auto-flags; release always human-gated |
| `INVENTORY_MANAGER` | Inventory Alerts | Auto-flags; auto-drafts POs; PO issuance suggest-only above threshold |
| `FRAUD_DETECTION` | Fraud Alerts, Detected Risks, Urgent Actions | Auto-holds (freeze only, reversible); never executes or deletes |
| `DOCUMENT_AI` / `OCR_AGENT` | AI Insights (data-quality signals feeding other panels) | Auto-extracts; low-confidence extractions route to human review |
| `FORECAST_AGENT` | Cash Flow Status, Financial Forecast, Simulation Panel | Auto-computes projections; scenario deltas are sandboxed, non-persistent by default |
| `COMPLIANCE_AGENT` | Upcoming Tax Deadlines, Detected Risks | Auto-flags; submission/remediation human-gated |
| `APPROVAL_ASSISTANT` | Approval Center, Automation Center, Urgent Actions | Orchestrates routing only; grants nothing itself |

## How to read this document

Sections `Morning Briefing` through `Fraud Alerts` are the **situational awareness layer** — what the AI already knows this morning. `Financial Forecast` through `Automation Center` are the **decision layer** — what the AI is proposing and where the human gate sits. `Today's Tasks` through `Decision Support` are the **interaction layer** — how a human talks to, questions, and directs the workforce. `KPIs` through `Widgets` are the **surface layer** — configuration, devices, and the concrete data contracts every widget in every panel above conforms to. `Examples` and `User Journeys` close the document by running the whole Command Center, end to end, for real people on a real morning. Every panel section follows the same skeleton: **What It Shows**, **Data Source**, **AI Behavior**, **Interaction**, **Example**.

## The Command Card envelope

Every widget on the Command Center — regardless of panel — renders a payload that conforms to one shared shape, nested inside QAYD's standard API response envelope (`success` / `data` / `message` / `errors` / `meta` / `request_id` / `timestamp`). This is the **Command Card**, and every JSON example in every section below is a `data` object of this shape:

```json
{
  "widget_id": "cash_flow_status",
  "company_id": 4821,
  "branch_id": null,
  "generated_at": "2026-07-16T06:00:00+03:00",
  "refresh_interval_seconds": 300,
  "ai_generated": true,
  "agent_code": "TREASURY_MANAGER",
  "decision_id": 991540,
  "confidence_score": 93.5,
  "status": "warning",
  "headline": "Cash dips to KWD 18,900 on Jul 29 — payroll and VAT overlap",
  "narrative": "...",
  "data": { "...widget-specific fields..." : "..." },
  "actions": [
    { "id": "view_forecast", "label": "View 90-day forecast", "type": "navigate", "target": "/ai/forecast" }
  ],
  "sources": [
    { "type": "bank_accounts", "id": 55, "label": "NBK Current Account ****4471" }
  ],
  "drill_down_endpoint": "/api/v1/banking/accounts"
}
```

`status` is always one of `ok | info | warning | critical`, and drives the card's color treatment under QAYD's design system (Success Green / Info Blue / Warning Orange / Error Red). `ai_generated: false` appears only on panels that are pure configuration surfaces with no AI narrative layer (for example, a raw KPI value before the Reporting Agent's commentary is attached). The full registry of `widget_id` values, their sizing, and their permission gates is the subject of the `Widgets` section; every panel section below names its own `widget_id` up front.

# Morning Briefing

**What it shows.** The single scrolling card at the very top of the Command Center, generated fresh once per business day before the company's configured start of business (default 06:00 in the company's local timezone) and re-offered (not regenerated) on every subsequent visit that day. It is a five-part digest — Yesterday's Close, Top Changes, Top Risks, Top Recommended Actions, and Today's Calendar — written in plain, direct language by the `CEO_ASSISTANT` agent, which does not compute anything itself but synthesizes the overnight output of every other agent into one coherent narrative a busy owner can absorb in ninety seconds.

**Data source.** `GET /api/v1/ai/briefings/{date}` (permission: `ai.chat`, readable by any role with `reports.read`). Backed by the new `ai_briefings` table (DDL below), populated by a nightly `ai_tasks` job (`task_type = 'daily_briefing_generation'`) that runs after the last business-hour transaction of the prior day is expected to have posted (configurable per company; Al-Noor Trading runs it at 05:30). The job reads: yesterday's posted `journal_lines` (close-of-day position), the last 24 hours of `ai_decisions` rows across all `agent_code`s (for Top Changes and Top Risks), open rows in the new `ai_command_tasks` and `ai_approval_requests` tables (for Today's Calendar and Top Recommended Actions), and `tax_returns` / `payroll_runs` due dates falling within the next 7 days.

```sql
CREATE TABLE ai_briefings (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    briefing_date       DATE NOT NULL,
    generated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    locale              VARCHAR(5) NOT NULL DEFAULT 'en',   -- 'en' | 'ar'
    headline            VARCHAR(200) NOT NULL,
    summary_text         TEXT NOT NULL,
    sections            JSONB NOT NULL,        -- [{ "type": "top_change", "widget_id": "...", "text": "..." }]
    confidence_score    NUMERIC(5,2) NOT NULL CHECK (confidence_score BETWEEN 0 AND 100),
    audio_available     BOOLEAN NOT NULL DEFAULT true,
    audio_url           TEXT NULL,             -- signed Cloudflare R2 URL, short TTL
    read_at             TIMESTAMPTZ NULL,
    read_by             BIGINT NULL REFERENCES users(id),
    ai_task_id          BIGINT NULL REFERENCES ai_tasks(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    UNIQUE (company_id, branch_id, briefing_date, locale)
);
CREATE INDEX idx_ai_briefings_company_date ON ai_briefings (company_id, briefing_date DESC);
```

**AI behavior.** `CEO_ASSISTANT` performs no primary analysis; it ranks and compresses. Ranking rule: a candidate item enters the briefing only if it is in the top 3 of its category by `(severity_or_impact_score DESC, confidence_score DESC)`, so the briefing structurally cannot exceed roughly 12–15 lines regardless of how much happened. The briefing's own `confidence_score` is the confidence-weighted average of every constituent `ai_decisions` row it cites, discounted 5 points if any constituent item is itself `pending_approval` (i.e., not yet human-confirmed). Every line in the briefing links back to the exact `ai_decisions.id` or `ai_risk_flags.id` it was drawn from — nothing in the Morning Briefing is prose without a citable source underneath it.

**Interaction.** Single vertical scroll on web; five swipeable cards on mobile. A "Read to me" control (speaker icon) streams the `audio_url` (see Voice Assistant) rendered in the company's configured voice and language. Tapping any line deep-links straight into the panel that owns it — tapping the cash-flow line opens Cash Flow Status already scrolled and expanded, not just a generic navigation. The briefing can be marked read (`read_at`/`read_by`) which only affects its own badge state; it never auto-dismisses anything it references elsewhere on the dashboard.

**Example.** Fahad opens QAYD at 07:12 on 2026-07-16:

```json
{
  "widget_id": "morning_briefing",
  "company_id": 4821,
  "generated_at": "2026-07-16T05:30:00+03:00",
  "confidence_score": 91.0,
  "status": "warning",
  "headline": "Good morning, Fahad. Cash is healthy today, tight in two weeks.",
  "data": {
    "yesterdays_close": "Revenue posted KWD 8,140.000 across 6 invoices. Cash position closed at KWD 42,318.600 across 3 accounts.",
    "top_changes": [
      "Gross margin improved to 31.2% this month, up from 29.8% in June — driven by a mix shift toward higher-margin contracting services.",
      "July revenue is pacing 13% ahead of June's same point (KWD 96,220 MTD vs KWD 84,900)."
    ],
    "top_risks": [
      "Diyar Real Estate's payment terms have slipped to 55 days actual vs 30 contracted; they now hold 28% of total AR.",
      "A vendor bank-detail change on Al-Fajr Cement Supplies triggered an automatic hold on a KWD 18,540.000 payment pending verification."
    ],
    "top_recommended_actions": [
      "Approve the July vendor payment run (KWD 24,180.000, 6 vendors) — waiting in Approval Center since 06:00.",
      "Decide on the Steel Rebar 12mm reorder (60 tons) before the Diyar delivery commitment of Aug 2."
    ],
    "todays_calendar": [
      "KSA VAT Q2 return due in 15 days (2026-07-31).",
      "Payroll cutoff for July changes: 2026-07-25."
    ]
  },
  "sources": [
    { "type": "ai_decisions", "id": 991540, "label": "Cash Flow Status — Treasury Manager" },
    { "type": "ai_risk_flags", "id": 55210, "label": "Diyar Real Estate concentration risk" },
    { "type": "ai_risk_flags", "id": 55214, "label": "Al-Fajr Cement bank-detail change hold" }
  ],
  "audio_url": "https://cdn.qayd.app/r2/briefings/4821/2026-07-16-en.mp3"
}
```

# Business Health Score

**What it shows.** A single 0–100 composite score (`widget_id: business_health_score`) with a trend delta against the prior period, decomposed on expansion into five weighted components so the number is never a black box. It is the one figure on the Command Center engineered to be understood in under two seconds, and defensible in under twenty.

**Data source.** `GET /api/v1/ai/health-score` (permission: `reports.read`). Computed by `CFO_AGENT` in collaboration with `REPORTING_AGENT`, reading `ledger_entries` and `financial_statement_snapshots` (liquidity and profitability components), `invoices`/`bills` aging views (working-capital component), `tax_returns`/`tax_transactions` status (compliance component), and open rows in `ai_risk_flags` (risk-exposure component). Persisted as an `ai_decisions` row, `decision_type = 'cfo_ratio_insight'` (reusing the exact type `CFO_AGENT.md` already defines for deterministic, arithmetic-only outputs), `agent_code = 'CFO_AGENT'`, recomputed every time a new day's ledger closes and cached in Redis for intraday reads.

**AI behavior — the formula.**

$$\text{Health Score} = 0.25 \times \text{Liquidity} + 0.25 \times \text{Profitability} + 0.20 \times \text{Working Capital Discipline} + 0.15 \times \text{Compliance} + 0.15 \times \text{Risk Exposure}$$

Each component is itself a 0–100 sub-score computed from a deterministic band table (for example, Liquidity maps the current ratio through `<1.0 → 20`, `1.0–1.3 → 50`, `1.3–1.8 → 75`, `1.8–2.5 → 95`, `>2.5 → 85` — a ratio too far above 2.5 is penalized slightly as idle-cash inefficiency, not rewarded indefinitely). Because every input band is deterministic arithmetic on already-posted data, the Health Score itself is **auto** (no approval needed to view) with a confidence score that reflects only data freshness, never judgment.

**Interaction.** Tapping the score expands a radial breakdown of the five components; tapping any one component deep-links to the panel that owns its detail (Working Capital Discipline → Cash Flow Status' AR/AP aging view; Risk Exposure → Detected Risks). A small "why did this change" affordance triggers a natural-language explanation generated on demand (see Natural Language Queries) rather than a pre-written one, since the delta driver differs every period.

**Example.**

| Component | Weight | Sub-score | Driver |
|---|---|---|---|
| Liquidity | 25% | 86 | Current ratio 1.8 (policy floor 1.3) |
| Profitability | 25% | 80 | Net margin 9.4%, +1.1pp MoM |
| Working Capital Discipline | 20% | 65 | DSO 42 days (target ≤35); DPO 38 days |
| Compliance & Tax | 15% | 88 | All filings current; 1 due in 15 days |
| Risk Exposure | 15% | 68 | 2 open medium-severity risk flags |
| **Composite** | | **78 / 100 ("Good"), +4 vs June (74)** | |

```json
{
  "widget_id": "business_health_score",
  "company_id": 4821,
  "confidence_score": 96.0,
  "status": "info",
  "headline": "78 / 100 — Good, up 4 points from June",
  "data": {
    "score": 78, "previous_score": 74, "trend": "up",
    "components": [
      { "name": "liquidity", "score": 86, "weight": 0.25, "driver": "Current ratio 1.8 vs floor 1.3" },
      { "name": "profitability", "score": 80, "weight": 0.25, "driver": "Net margin 9.4%, +1.1pp MoM" },
      { "name": "working_capital_discipline", "score": 65, "weight": 0.20, "driver": "DSO 42d (target ≤35d)" },
      { "name": "compliance", "score": 88, "weight": 0.15, "driver": "1 filing due in 15 days" },
      { "name": "risk_exposure", "score": 68, "weight": 0.15, "driver": "2 open medium risk flags" }
    ]
  },
  "sources": [
    { "type": "financial_statement_snapshots", "id": 91177, "label": "June 2026 close" },
    { "type": "ai_risk_flags", "id": 55210, "label": "Diyar Real Estate concentration risk" }
  ]
}
```

# Cash Flow Status

**What it shows.** Real-time consolidated cash position across every `bank_accounts` row the company holds, in base currency, with a traffic-light status and a rolling 30/60/90-day projection layered on the same chart so "cash today" and "cash trouble ahead" are never two different screens.

**Data source.** `GET /api/v1/banking/accounts` for the live reconciled balance (tables: `bank_accounts`, `bank_transactions`, `bank_reconciliations`) and `GET /api/v1/ai/cash-flow/forecast` for the projection (agent: `FORECAST_AGENT`, `decision_type = 'forecast_cash_projection'`, reading recurring `invoices`/`bills` schedules, open `sales_orders`, and historical seasonality from `ledger_entries`). `TREASURY_MANAGER` owns the live-position half and the traffic-light thresholds (company-configurable; Al-Noor's floor is KWD 20,000 = amber, KWD 10,000 = red).

**AI behavior.** The live balance is a straight reconciled sum — **auto**, no AI judgment involved. The forecast line is where `FORECAST_AGENT` earns its keep: a driver-based model combining (a) contractual certainties — recurring `bills` (rent, loan installments), scheduled `payroll_runs`, `tax_returns` due dates — with (b) probabilistic collections, weighting open `invoices` by each customer's own historical days-to-pay rather than their contractual terms. `TREASURY_MANAGER` overlays the traffic light and writes the natural-language "why" the moment the line crosses a threshold, which is exactly what makes this card actionable rather than just a chart.

**Interaction.** Default view is 30 days; a segmented control extends to 60/90. Hovering (web) or long-pressing (mobile) any point on the forecast line surfaces the specific drivers for that day (which invoice, which bill, which payroll run). A "Simulate a fix" button jumps directly into the Simulation Panel pre-loaded with this cash trough as the scenario to test against.

**Example.**

| Date | Projected cash (KWD) | Driver |
|---|---|---|
| 2026-07-16 (today) | 42,318.600 | Reconciled across NBK ****4471, Boubyan ****2290, Riyadh SAR ****7734 |
| 2026-07-25 | 31,900.000 | Payroll cutoff; supplier payment run |
| 2026-07-29 | **18,900.000 (trough)** | Kuwait payroll disbursement + KSA VAT Q2 payment overlap |
| 2026-08-15 | 27,400.000 | PIFSS contribution paid; 3 customer collections land |
| 2026-09-14 | 35,600.000 | Steady-state collections |
| 2026-10-14 | 44,100.000 | Steady-state collections + no major outflows |

```json
{
  "widget_id": "cash_flow_status",
  "company_id": 4821,
  "agent_code": "TREASURY_MANAGER",
  "confidence_score": 93.5,
  "status": "warning",
  "headline": "Cash dips to KWD 18,900 on Jul 29 — payroll and VAT overlap",
  "narrative": "Reconciled cash today is KWD 42,318.600 across 3 accounts. The forecast (Forecast Agent, decision #881640) shows a trough of KWD 18,900.000 on Jul 29 when the Kuwait payroll run and the KSA Q2 VAT payment land on the same week. This stays above your configured KWD 10,000 red floor but crosses the KWD 20,000 amber floor for 4 days.",
  "data": {
    "current_balance": 42318.600,
    "currency": "KWD",
    "accounts": [
      { "bank_account_id": 55, "name": "NBK Current ****4471", "balance": 31540.250 },
      { "bank_account_id": 56, "name": "Boubyan Current ****2290", "balance": 8120.500 },
      { "bank_account_id": 57, "name": "Riyadh SAR ****7734", "balance_native": 21750.000, "currency_native": "SAR", "exchange_rate": 0.12220, "balance_base": 2657.850 }
    ],
    "forecast_30d_trough": { "date": "2026-07-29", "amount": 18900.000, "status": "warning" }
  },
  "actions": [ { "id": "simulate_fix", "label": "Simulate a fix", "type": "navigate", "target": "/ai/simulate?scenario=cash_trough_0729" } ],
  "sources": [
    { "type": "bank_accounts", "id": 55, "label": "NBK Current Account" },
    { "type": "ai_decisions", "id": 881640, "label": "Forecast Agent 30-day cash projection" }
  ]
}
```

# Revenue Trends

**What it shows.** A month-over-month and year-over-year revenue chart (`widget_id: revenue_trends`), breakable down by product category, branch, and customer segment, with an AI narrative callout that names the specific driver of any material change rather than leaving the reader to infer it from a line going up or down.

**Data source.** `GET /api/v1/reports/revenue-trends` (permission: `reports.read`), computed from `journal_lines` filtered to revenue-classified `accounts` (via `account_types`), joined to `invoices` and `invoice_items` for the category/branch/customer breakdowns, and to `products`/`product_categories` for the category dimension. `REPORTING_AGENT` owns the chart computation itself (pure aggregation, **auto**); `GENERAL_ACCOUNTANT` supplies the narrative callout by diffing the current period's category mix against the trailing 3-month average and naming the largest contributor to the delta. Persisted as `ai_decisions`, `decision_type = 'reporting_agent_insight'`, `agent_code = 'REPORTING_AGENT'`.

**AI behavior.** The chart itself carries no judgment — it is a deterministic sum over posted lines, refreshed every time a new invoice posts (event-driven via `invoice.created` / `invoice.paid`, not on a timer). The narrative callout is where confidence matters: the agent will only assert a causal driver ("driven by X") when the category-mix shift alone explains at least 70% of the period-over-period delta; below that threshold it reports the change as a magnitude only ("Revenue is up 13% MoM; no single category explains more than 30% of the change") rather than manufacture a false story. This threshold and its effect on phrasing is itself stated in the `reasoning` field so a skeptical reader can see why the AI did or didn't commit to a driver.

**Interaction.** Toggle between MoM / YoY / custom range; toggle the breakdown dimension (category / branch / customer). Clicking any bar segment drills into the underlying `invoices` list, pre-filtered. A pin icon promotes this chart's current filter state to a saved KPI tile (see Customization).

**Example.** Al-Noor Trading, July 2026 month-to-date (through Jul 16) vs June 2026 same point:

| Dimension | Jun 1–16, 2026 | Jul 1–16, 2026 | Change |
|---|---|---|---|
| **Total revenue** | KWD 84,900.000 | KWD 96,220.000 | **+13.3%** |
| Steel & Rebar | 36% | 41% | +5pp |
| Cement & Aggregates | 29% | 27% | -2pp |
| Contracting Services | 15% | 18% | +3pp |
| Other Building Materials | 20% | 14% | -6pp |
| Kuwait City HQ | 60% | 62% | +2pp |
| Al-Ahmadi Yard | 25% | 23% | -2pp |
| Riyadh KSA | 15% | 15% | flat |

```json
{
  "widget_id": "revenue_trends",
  "company_id": 4821,
  "agent_code": "REPORTING_AGENT",
  "decision_id": 991611,
  "confidence_score": 88.0,
  "status": "ok",
  "headline": "Revenue up 13.3% MoM, paced by Steel & Rebar and Contracting Services",
  "reasoning": "Steel & Rebar's +5pp mix shift and Contracting Services' +3pp mix shift jointly explain 79% of the total revenue delta versus the trailing 3-month average category mix, clearing the 70% threshold required to assert a driver rather than report magnitude only.",
  "data": {
    "current_period": { "range": "2026-07-01/2026-07-16", "total": 96220.000 },
    "comparison_period": { "range": "2026-06-01/2026-06-16", "total": 84900.000 },
    "change_pct": 13.34,
    "by_category": [
      { "category": "Steel & Rebar", "pct": 41, "delta_pp": 5 },
      { "category": "Cement & Aggregates", "pct": 27, "delta_pp": -2 },
      { "category": "Contracting Services", "pct": 18, "delta_pp": 3 },
      { "category": "Other Building Materials", "pct": 14, "delta_pp": -6 }
    ]
  },
  "sources": [ { "type": "journal_lines", "id": null, "label": "Revenue-classified postings, Jul 1-16 2026" } ]
}
```

# Expense Trends

**What it shows.** The mirror of Revenue Trends (`widget_id: expense_trends`) — MoM/YoY expense movement broken down by category (COGS vs. Opex vs. Payroll) and by vendor, with the same AI narrative discipline, plus one behavior Revenue Trends does not need: every expense spike above a configurable threshold (default: 15% MoM at the category level) is automatically cross-checked against `FRAUD_DETECTION` before it is allowed to render as a plain "cost went up" line, so a legitimate cost increase and a potential anomaly never look identical on the screen.

**Data source.** `GET /api/v1/reports/expense-trends` (permission: `reports.read`), from `journal_lines` on expense-classified `accounts`, joined to `bills`/`bill_items` and `payroll_runs` for the vendor/category breakdown. `REPORTING_AGENT` computes the chart; `GENERAL_ACCOUNTANT` writes the narrative; any category crossing the spike threshold is passed to `FRAUD_DETECTION` for a same-second cross-check against its own signals (duplicate billing, new-vendor risk, round-number anomalies) before the card's `status` is finalized.

**AI behavior.** Three possible outcomes once a spike is detected: (1) Fraud Detection has no open concern and the underlying cause is traceable to a specific, named driver (a contract renewal, a rate change) — card renders `status: "info"` with the driver named; (2) Fraud Detection has no open concern but no single driver explains it — card renders `status: "warning"` with magnitude only, prompting a human look; (3) Fraud Detection independently already holds a related risk flag — the card renders `status: "critical"` and links directly to the Fraud Alerts panel rather than presenting the number in isolation. This routing is what keeps Expense Trends from ever being "just a chart that happened to go up."

**Interaction.** Identical control set to Revenue Trends (range toggle, dimension toggle, drill-down). A spike badge, when present, is not dismissible from this card — it can only be resolved from the Fraud Alerts or Detected Risks panel it links to, so a user cannot swipe away a cost anomaly without ever seeing why it fired.

**Example.** July logistics/fuel costs rose 18% MoM. Cross-check clears it: Al-Noor renewed its KSA branch delivery contract on Jul 1 at a new contracted rate (traceable to `bills` from the same vendor at the new unit price, no new vendor, no duplicate reference numbers) — outcome (1) above.

```json
{
  "widget_id": "expense_trends",
  "company_id": 4821,
  "agent_code": "GENERAL_ACCOUNTANT",
  "confidence_score": 90.0,
  "status": "info",
  "headline": "Logistics costs up 18% MoM — traced to the renewed KSA delivery contract, not an anomaly",
  "reasoning": "Fraud Detection cross-check (decision #772100) found no duplicate billing, no new vendor, and no round-number pattern. The full delta is explained by unit-rate bills from the existing logistics vendor at the contract rate effective 2026-07-01.",
  "data": {
    "category": "Logistics & Fuel", "change_pct": 18.0,
    "driver": "KSA branch delivery contract renewal, effective 2026-07-01",
    "fraud_cross_check": { "agent_code": "FRAUD_DETECTION", "decision_id": 772100, "result": "clear" }
  },
  "sources": [ { "type": "bills", "id": null, "label": "Logistics vendor bills, Jul 2026" } ]
}
```

# AI Insights

**What it shows.** A continuously updating feed (`widget_id: ai_insights_feed`) of atomic, single-fact observations the workforce noticed since the last time the user looked — not a summary (that is the Morning Briefing's job) but the raw stream it was assembled from, for the user who wants to see everything, not just the top 3. Anomaly detections, trend crossings, and cross-period comparisons all land here the moment any agent produces them, in reverse chronological order.

**Data source.** `GET /api/v1/ai/insights?since={timestamp}` (permission: `reports.read`, filtered to insights the requesting role is entitled to see). Every insight is an `ai_decisions` row with `decision_type` values scoped to observation-only outputs — `reporting_agent_insight`, `cfo_ratio_insight`, `document_ai_extraction_note` — explicitly excluding anything with `recommended_action` populated (those belong to AI Recommendations, next section). Any agent in the roster may write an insight; `REPORTING_AGENT` runs a de-duplication pass every 15 minutes so near-identical observations from two agents collapse into one feed entry with both `agent_code`s attributed.

**AI behavior.** An insight is pure observation: it states a fact and its confidence, and it may explain *why* something happened, but it never tells the user what to do about it — the moment an item includes a suggested action, it is emitted with `recommended_action` populated and is filtered out of this feed and into AI Recommendations instead. This is a strict, code-enforced split (a single field's presence, not a stylistic guideline) so the two panels never show duplicate cards with different framing.

**Interaction.** Infinite-scroll feed, filterable by agent and by date range. Each card supports "Explain more" (opens a Natural Language Query pre-seeded with the insight's own `sources`) and "Not useful" (a lightweight negative-feedback signal stored against `ai_memory` — see the Memory doc — that down-weights similarly-shaped insights for this company going forward, without ever deleting the underlying data it was computed from).

**Example — five most recent insights for Al-Noor Trading:**

```json
{
  "widget_id": "ai_insights_feed",
  "company_id": 4821,
  "data": {
    "insights": [
      { "id": 991611, "agent_code": "REPORTING_AGENT", "confidence_score": 88.0, "text": "Revenue is pacing 13.3% ahead of June's same point in the month.", "generated_at": "2026-07-16T05:31:00+03:00" },
      { "id": 991612, "agent_code": "GENERAL_ACCOUNTANT", "confidence_score": 90.0, "text": "Logistics costs rose 18% MoM, fully explained by the KSA delivery contract renewal.", "generated_at": "2026-07-16T05:32:00+03:00" },
      { "id": 991613, "agent_code": "CFO_AGENT", "confidence_score": 97.5, "text": "Gross margin improved to 31.2% this month from 29.8% in June.", "generated_at": "2026-07-16T05:33:00+03:00" },
      { "id": 991614, "agent_code": "DOCUMENT_AI", "confidence_score": 62.0, "text": "3 vendor bills this week were captured with a low-confidence tax-code extraction and were routed to manual review rather than auto-posted.", "generated_at": "2026-07-15T14:02:00+03:00" },
      { "id": 991615, "agent_code": "INVENTORY_MANAGER", "confidence_score": 94.0, "text": "Steel Rebar 12mm stock (28 tons) has fallen below its 40-ton reorder point.", "generated_at": "2026-07-16T05:20:00+03:00" }
    ]
  }
}
```

# AI Recommendations

**What it shows.** The action-shaped counterpart to AI Insights (`widget_id: ai_recommendations_feed`): every card here carries a `recommended_action`, an explicit set of `alternatives` with tradeoffs, and a button that either executes immediately (if the action's autonomy level is `auto` and its confidence clears the configured threshold) or opens a pre-filled request in the Approval Center (if the action is sensitive or below threshold).

**Data source.** `GET /api/v1/ai/recommendations` (permission: `reports.read` to view, the specific action's own permission key — e.g. `bank.transfer`, `inventory.adjust`, `payroll.calculate` — to execute). Same underlying table as Insights, `ai_decisions`, filtered to rows where `recommended_action IS NOT NULL`. `APPROVAL_ASSISTANT` is the agent that decides, for each recommendation, which of the two buttons ("Do it" vs. "Send for approval") a given recommendation renders, by checking the recommendation's own autonomy classification (see Automation Center) against the current `ai_automation_rules` configuration for this company.

**AI behavior.** A recommendation is never binary accept/reject only — every one ships with at least one named alternative and its tradeoff, mirroring exactly the shape `CFO_AGENT.md` establishes for `cfo_capital_recommendation` (`alternatives: [{ "action": ..., "tradeoff": ... }]`). This is a deliberate product stance: QAYD does not want users to develop reflexive trust in a single "accept" button; every recommendation is presented as a decision with visible tradeoffs, not an instruction.

**Interaction.** Three buttons per card: **Do it** (only rendered when autonomy = auto and confidence clears threshold — executes immediately via the relevant Laravel endpoint, logged to `audit_logs`), **Send for approval** (opens the Approval Center with this recommendation pre-attached), **Dismiss** (requires a one-line reason, stored against the decision row as `rejected_reason`, and feeds `ai_memory` the same way an Insight's "Not useful" does).

**Example.**

```json
{
  "widget_id": "ai_recommendations_feed",
  "company_id": 4821,
  "data": {
    "recommendations": [
      {
        "id": 991700, "agent_code": "TAX_ADVISOR", "confidence_score": 84.0,
        "text": "Draft the KSA Q2 VAT return now — all source invoices are posted and reconciled.",
        "recommended_action": "Generate VAT return draft for Q2 2026",
        "alternatives": [ { "action": "Wait until July 25", "tradeoff": "No benefit; all data is already final, waiting only compresses your review window" } ],
        "button": "send_for_approval", "target_permission": "tax.submit"
      },
      {
        "id": 991701, "agent_code": "INVENTORY_MANAGER", "confidence_score": 91.0,
        "text": "Reorder 60 tons of Steel Rebar 12mm now to cover the Diyar delivery (35t) plus 5 weeks of normal draw.",
        "recommended_action": "Create purchase order to Gulf Steel Industries for 60 tons",
        "alternatives": [
          { "action": "Order only the 35 tons needed for the Diyar order", "tradeoff": "Avoids overstock cost but reorders again within 2 weeks at a likely higher spot price" },
          { "action": "Split the order across two vendors", "tradeoff": "Reduces single-vendor concentration but forfeits the volume rate" }
        ],
        "button": "send_for_approval", "target_permission": "inventory.adjust"
      },
      {
        "id": 991702, "agent_code": "GENERAL_ACCOUNTANT", "confidence_score": 96.5,
        "text": "Auto-categorize 214 pending bank transactions matching known merchant patterns at ≥95% confidence.",
        "recommended_action": "Categorize 214 transactions",
        "alternatives": [ { "action": "Review each manually first", "tradeoff": "Adds ~40 minutes with no expected change in outcome, based on a 0.4% historical correction rate" } ],
        "button": "do_it", "target_permission": "accounting.create"
      }
    ]
  }
}
```

# Detected Risks

**What it shows.** The umbrella risk radar (`widget_id: detected_risks_radar`) — every open risk of every category, ranked by severity, in one place. Fraud Alerts, Payroll Alerts, Inventory Alerts, Supplier Risks, and Customer Risks (each documented in its own section below) are **not separate data sources**; they are category-filtered views over the exact same underlying table this panel renders in full. This is a deliberate architectural choice: a risk does not become a different kind of object depending on which screen you view it from.

**Data source.** `GET /api/v1/ai/risks?category=all` (permission: `reports.read`; category-specific permission for remediation actions). Backed by the new `ai_risk_flags` table:

```sql
CREATE TYPE ai_risk_category AS ENUM (
    'fraud', 'compliance', 'tax', 'payroll', 'inventory',
    'supplier', 'customer', 'treasury', 'data_integrity'
);
CREATE TYPE ai_risk_severity AS ENUM ('low', 'medium', 'high', 'critical');
CREATE TYPE ai_risk_status AS ENUM (
    'open', 'acknowledged', 'monitoring', 'resolved', 'dismissed', 'expired'
);

CREATE TABLE ai_risk_flags (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    category            ai_risk_category NOT NULL,
    severity            ai_risk_severity NOT NULL,
    status              ai_risk_status NOT NULL DEFAULT 'open',
    source_agent_code   VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    source_decision_id  BIGINT NULL REFERENCES ai_decisions(id),   -- the ai_decisions row (fraud_alert, compliance_flag, audit_exception, ...) that raised this
    subject_type        VARCHAR(60) NULL,     -- 'vendors' | 'customers' | 'bills' | 'employees' | 'inventory_items' ...
    subject_id          BIGINT NULL,
    title               VARCHAR(200) NOT NULL,
    description         TEXT NOT NULL,
    confidence_score    NUMERIC(5,2) NOT NULL CHECK (confidence_score BETWEEN 0 AND 100),
    financial_exposure  NUMERIC(19,4) NULL,    -- estimated KWD amount at risk, where applicable
    related_hold_type   VARCHAR(60) NULL,      -- e.g. 'payment_hold' when the flag froze a downstream action
    related_hold_id     BIGINT NULL,
    acknowledged_by     BIGINT NULL REFERENCES users(id),
    acknowledged_at     TIMESTAMPTZ NULL,
    resolved_by         BIGINT NULL REFERENCES users(id),
    resolved_at         TIMESTAMPTZ NULL,
    resolution_note     TEXT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_risk_flags_company_status_sev ON ai_risk_flags (company_id, status, severity);
CREATE INDEX idx_ai_risk_flags_category ON ai_risk_flags (company_id, category);
CREATE INDEX idx_ai_risk_flags_subject ON ai_risk_flags (subject_type, subject_id);
```

A row is created here by an event listener the instant a qualifying `ai_decisions` row is written with `decision_type IN ('fraud_alert', 'compliance_flag', 'audit_exception', 'treasury_manager_vendor_risk', 'treasury_manager_customer_risk', 'inventory_manager_stock_risk', 'payroll_manager_alert')` — the exact `fraud_alert`, `compliance_flag`, and `audit_exception` values are owned by the `FRAUD_DETECTION`, `COMPLIANCE_AGENT`, and `AUDITOR` agent documents respectively; this table only tracks their operational lifecycle (open → acknowledged → resolved), it never re-scores or contradicts the originating decision.

**AI behavior.** Severity is set once at creation by the originating agent and never inflates or decays automatically — a `critical` fraud flag stays `critical` until a human resolves it, even if 30 days pass, because time alone does not reduce fraud exposure. `status` transitions are: `open` (just raised) → `acknowledged` (a human has seen it, no action taken yet) → `monitoring` (a human has taken a partial action, e.g. requested vendor verification, and is waiting) → `resolved` (closed with a note) or `dismissed` (closed as a false positive, which — like Insight/Recommendation dismissals — feeds `ai_memory` to reduce future false positives of the same shape).

**Interaction.** Filterable by category and severity; grouped by severity by default (critical first). Each card exposes **Acknowledge**, **Resolve** (requires a `resolution_note`), and **Dismiss as false positive** (requires a reason). Critical-severity flags cannot be dismissed by any role below Finance Manager — the dismiss action itself checks a permission key (`ai.approve`), so a junior user cannot quietly wave away a fraud flag.

**Example — Al-Noor Trading's open risk radar, 2026-07-16:**

| ID | Category | Severity | Title | Exposure (KWD) | Status |
|---|---|---|---|---|---|
| 55210 | customer | medium | Diyar Real Estate concentration + slipping terms | 43,200.000 | open |
| 55211 | supplier | medium | Gulf Steel Industries: 34% COGS concentration, price +6% | — | open |
| 55212 | inventory | high | Steel Rebar 12mm below reorder point vs. committed SO | — | open |
| 55213 | fraud | high | Duplicate bill suspected: BILL-2026-01184 / BILL-2026-01179 | 6,240.000 | acknowledged |
| 55214 | fraud | critical | Vendor bank-detail change 2h before scheduled payment | 18,540.000 | monitoring |
| 55215 | payroll | low | New hire missing PIFSS civil ID — blocks WPS file | — | open |

```json
{
  "widget_id": "detected_risks_radar",
  "company_id": 4821,
  "status": "critical",
  "headline": "6 open risks — 1 critical, 2 high, 2 medium, 1 low",
  "data": {
    "counts_by_severity": { "critical": 1, "high": 2, "medium": 2, "low": 1 },
    "top_risk": { "id": 55214, "category": "fraud", "severity": "critical", "title": "Vendor bank-detail change 2h before scheduled payment" }
  }
}
```

# Upcoming Tax Deadlines

**What it shows.** A countdown-chip calendar (`widget_id: tax_deadlines`) of every open tax and statutory-contribution obligation across every jurisdiction the company operates in — for Al-Noor Trading, that means Kuwait obligations (PIFSS, Kuwait Tax Authority) and Saudi obligations (VAT) side by side, since QAYD's `tax_codes`/`tax_rates` are jurisdiction-scoped and a single company can carry multiple active registrations.

**Data source.** `GET /api/v1/tax/deadlines` (permission: `tax.read`), from `tax_codes`, `tax_rates`, `tax_transactions`, and `tax_returns`, filtered to `due_date` within a rolling 60-day window. `TAX_ADVISOR` computes readiness (whether the underlying period's transactions are fully posted and reconciled) and drafts the return itself; `COMPLIANCE_AGENT` independently verifies the draft against the jurisdiction's current filing rules and flags anything `TAX_ADVISOR`'s draft is missing (e.g., a required supporting schedule) before either agent lets a "ready to submit" status show.

**AI behavior.** Drafting a return is **suggest-only**, never further: `TAX_ADVISOR` can assemble the full VAT return dataset the moment every source invoice for the period is posted, but the return only ever reaches `status = 'draft'`. Submission is gated behind `tax.submit`, a permission held only by CFO, Finance Manager, and Owner roles by default, and every submission requires the two-step approval chain described in the Approval Center section — QAYD never transmits a tax filing on an AI's own authority regardless of confidence.

**Interaction.** Each chip shows a live countdown and a readiness percentage (share of the period's transactions posted and reconciled). Tapping a chip with 100% readiness surfaces a **Review draft** button; below 100% it instead surfaces **View blocking items** (the specific unposted or unreconciled records holding the draft back).

**Example.**

| Obligation | Jurisdiction | Due | Days left | Readiness | Status |
|---|---|---|---|---|---|
| VAT Return — Q2 2026 | KSA (Riyadh branch) | 2026-07-31 | 15 | 100% | Draft ready, pending approval |
| Interim Tax Declaration | Kuwait Tax Authority | 2026-08-01 | 16 | 92% | 2 vendor bills pending classification |
| PIFSS Contributions — July wages | Kuwait | 2026-08-15 | 30 | — | Not yet due; payroll run pending |

```json
{
  "widget_id": "tax_deadlines",
  "company_id": 4821,
  "agent_code": "TAX_ADVISOR",
  "confidence_score": 95.0,
  "status": "warning",
  "headline": "KSA VAT Q2 return is ready — 15 days to file",
  "data": {
    "deadlines": [
      { "id": "TAXRET-2026-Q2-KSA", "jurisdiction": "KSA", "type": "vat_return", "due_date": "2026-07-31", "days_left": 15, "readiness_pct": 100, "draft_status": "pending_approval" },
      { "id": "TAXRET-2026-INT-KWT", "jurisdiction": "Kuwait", "type": "interim_declaration", "due_date": "2026-08-01", "days_left": 16, "readiness_pct": 92, "blocking_items": 2 },
      { "id": "PIFSS-2026-07", "jurisdiction": "Kuwait", "type": "social_security_contribution", "due_date": "2026-08-15", "days_left": 30, "readiness_pct": null }
    ]
  },
  "sources": [ { "type": "tax_returns", "id": 6620, "label": "KSA VAT Q2 2026 draft" } ]
}
```

# Payroll Alerts

**What it shows.** A dedicated, category-filtered view (`widget_id: payroll_alerts`, `ai_risk_flags` where `category = 'payroll'`) surfacing anything that could delay or corrupt a payroll run before it happens — WPS/Mudad file-submission deadlines, salary anomalies versus the employee's own history, missing statutory identifiers, and leave/end-of-service-benefit (EOSB) accrual discrepancies.

**Data source.** `GET /api/v1/payroll/alerts` (permission: `payroll.read`), from `employees`, `payroll_runs`, `payroll_items`, `salary_components`, and `payslips`. `PAYROLL_MANAGER` owns this panel; `AUDITOR` independently cross-checks any salary change exceeding 15% against an approved `salary_components` change record before allowing the run to proceed without a flag.

**AI behavior.** Every flag here is **auto-detect, human-gated remediation** — `PAYROLL_MANAGER` may auto-prepare the payroll draft and auto-flag the anomaly, but `payroll.calculate` (finalizing amounts) and `payroll.approve` (releasing payment) both remain human actions per the platform's fixed rule that payroll release always requires approval, with no confidence threshold that overrides it.

**Interaction.** Each alert links directly to the specific `employees` record and the exact field blocking WPS/Mudad validity. A **Resolve now** action opens the employee record pre-scrolled to the missing field; a **Snooze until cutoff** option is available only for low-severity items and logs the snooze to `audit_logs` with the snoozing user's ID.

**Example.**

```json
{
  "widget_id": "payroll_alerts",
  "company_id": 4821,
  "agent_code": "PAYROLL_MANAGER",
  "confidence_score": 98.0,
  "status": "warning",
  "headline": "1 new hire is missing a PIFSS civil ID — will block the WPS file on Jul 27",
  "data": {
    "alerts": [
      {
        "id": 55215, "employee_id": 512, "employee_name": "Bilal Youssef",
        "issue": "Missing PIFSS civil ID registration number",
        "impact": "WPS file for the July run (submission deadline 2026-07-27) will reject this employee's line without it",
        "days_to_deadline": 11
      }
    ],
    "run_summary": {
      "period": "2026-07", "cutoff_date": "2026-07-25", "wps_submission_deadline": "2026-07-27",
      "kuwait_employee_count": 38, "kuwait_gross_kwd": 46200.000,
      "ksa_employee_count": 9, "ksa_gross_sar": 98600.000
    }
  },
  "sources": [ { "type": "employees", "id": 512, "label": "Bilal Youssef" } ]
}
```

# Inventory Alerts

**What it shows.** The `category = 'inventory'` view of the risk radar (`widget_id: inventory_alerts`) — reorder-point breaches, overstock/slow-moving stock, stockout risk against already-committed sales orders, and valuation discrepancies between `inventory_items` and physical `stock_counts`.

**Data source.** `GET /api/v1/inventory/alerts` (permission: `inventory.read`), from `inventory_items`, `stock_movements`, `stock_adjustments`, and open `sales_order_items` (to know committed-but-unshipped demand). `INVENTORY_MANAGER` owns detection; `FORECAST_AGENT` supplies the demand projection that turns a simple "below reorder point" fact into a dated stockout prediction.

**AI behavior.** `INVENTORY_MANAGER` may **auto-draft** a purchase order the moment a reorder-point breach is confirmed against committed demand (drafting itself touches no financial commitment), but issuing that PO to a vendor is **suggest-only** and routes through AI Recommendations / Approval Center once the draft exceeds the company's auto-issue threshold (Al-Noor's is KWD 2,000; the example below, a full truckload of rebar, exceeds it).

**Interaction.** Each card shows current stock, reorder point, days-to-stockout at current draw rate, and the specific committed order driving the urgency. A **Draft PO** button (already drafted in the example, since the threshold requires approval) links to the Approval Center entry.

**Example.**

```json
{
  "widget_id": "inventory_alerts",
  "company_id": 4821,
  "agent_code": "INVENTORY_MANAGER",
  "confidence_score": 91.0,
  "status": "critical",
  "headline": "Steel Rebar 12mm will stock out before the Diyar delivery unless reordered this week",
  "data": {
    "alerts": [
      {
        "id": 55212, "product_id": 8834, "product_name": "Steel Rebar 12mm Grade 60 (ton)", "warehouse": "Al-Ahmadi Yard",
        "current_stock_tons": 28, "reorder_point_tons": 40,
        "committed_demand": { "sales_order": "SO-2026-2290", "customer": "Diyar Real Estate Development Co.", "tons_needed": 35, "needed_by": "2026-08-02" },
        "vendor_lead_time_days": 9,
        "recommended_order_tons": 60,
        "recommended_vendor": "Gulf Steel Industries Co.",
        "draft_po_id": "PO-2026-1140", "draft_po_value_kwd": 26400.000
      }
    ]
  },
  "sources": [ { "type": "inventory_items", "id": 8834, "label": "Steel Rebar 12mm — Al-Ahmadi Yard" }, { "type": "sales_orders", "id": 2290, "label": "SO-2026-2290" } ]
}
```

# Supplier Risks

**What it shows.** The `category = 'supplier'` view (`widget_id: supplier_risks`) of vendor-side exposure: concentration (how much of total spend or COGS rides on one vendor), price-creep trends, delivery-reliability patterns, contract expiries, and — where available — third-party signals of the vendor's own financial health.

**Data source.** `GET /api/v1/ai/supplier-risks` (permission: `reports.read` to view, `inventory.adjust`/`accounting.create` for remediation actions taken from the card). From `vendors`, `vendor_contracts`, `bills`, `purchase_orders`, and `goods_receipts` (the last of these to compute on-time-delivery rate). `TREASURY_MANAGER` owns concentration and price-trend detection; `AUDITOR` cross-checks delivery-reliability claims against actual `goods_receipt_items` timestamps rather than vendor-promised dates; `FRAUD_DETECTION` watches the same `vendors` table for the bank-detail-change pattern documented in Fraud Alerts.

**AI behavior.** Concentration risk is a pure computation — **auto** — over `bills` grouped by `vendor_id` against total COGS for a trailing 12-month window; QAYD's default policy floor flags any single vendor above 25% of a spend category. Price-creep is computed by comparing average unit cost per `product_id` sourced from a given vendor across quarters. Neither of these produces a recommendation by itself; where `TREASURY_MANAGER` believes a specific action is warranted (renegotiate, diversify), that appears in AI Recommendations, keeping this panel itself observational plus a severity rating.

**Interaction.** Each card supports **View contract** (deep-links to the `vendor_contracts` record and its renewal date), **Request re-quote** (drafts an RFQ to alternate vendors via the Purchasing module, itself `suggest-only`), and standard risk-flag lifecycle actions (acknowledge/resolve/dismiss).

**Example.**

```json
{
  "widget_id": "supplier_risks",
  "company_id": 4821,
  "agent_code": "TREASURY_MANAGER",
  "confidence_score": 89.0,
  "status": "warning",
  "headline": "Gulf Steel Industries is 34% of COGS — above your 25% concentration policy — and pricing is up 6% over 2 quarters",
  "data": {
    "risks": [
      {
        "id": 55211, "vendor_id": 3301, "vendor_name": "Gulf Steel Industries Co.",
        "cogs_concentration_pct": 34, "policy_floor_pct": 25,
        "price_trend_pct_2q": 6.0,
        "contract_renewal_date": "2026-09-01",
        "on_time_delivery_rate_pct": 92
      }
    ]
  },
  "sources": [ { "type": "bills", "id": null, "label": "Gulf Steel Industries bills, trailing 12 months" }, { "type": "vendor_contracts", "id": null, "label": "Current supply contract" } ]
}
```

# Customer Risks

**What it shows.** The `category = 'customer'` view (`widget_id: customer_risks`) of receivables-side exposure: AR concentration by customer, credit-limit utilization, payment-behavior deterioration (contracted terms vs. actual days-to-pay), and early churn signals (order-frequency decline).

**Data source.** `GET /api/v1/ai/customer-risks` (permission: `reports.read`), from `customers`, `invoices`, and `receipts`. `TREASURY_MANAGER` computes concentration and credit-limit utilization; `FORECAST_AGENT` computes the actual-vs-contracted days-to-pay trend and projects forward whether the customer is likely to breach their credit limit before their next payment lands; `CFO_AGENT` folds the aggregate customer-risk signal into the Working Capital Discipline component of the Business Health Score.

**AI behavior.** A customer risk becomes a flag, not just a chart annotation, once two conditions both hold: the customer represents more than 20% of a category's exposure (AR, in this case) **and** its own payment behavior has deteriorated over the trailing two quarters. Either condition alone is shown as an insight (AI Insights) but not escalated to a risk flag — this avoids flagging every large-but-reliable customer as a "risk" purely for being large.

**Interaction.** Each card supports **Recommend credit hold** (drafts, does not apply, a hold on new orders above the customer's remaining limit — routes to AI Recommendations since it touches `customers.credit_limit` enforcement), **View aging detail**, and **Contact customer** (drafts a payment-reminder communication via the automation described in Automation Center).

**Example.**

```json
{
  "widget_id": "customer_risks",
  "company_id": 4821,
  "agent_code": "TREASURY_MANAGER",
  "confidence_score": 92.0,
  "status": "warning",
  "headline": "Diyar Real Estate is 28% of total AR and now paying in 55 days vs. 30 contracted",
  "data": {
    "risks": [
      {
        "id": 55210, "customer_id": 2207, "customer_name": "Diyar Real Estate Development Co.",
        "ar_balance_kwd": 43200.000, "credit_limit_kwd": 45000.000, "credit_utilization_pct": 96,
        "ar_share_of_total_pct": 28,
        "contracted_terms_days": 30, "actual_days_to_pay_q1": 34, "actual_days_to_pay_q2": 55,
        "overdue_90d_kwd": 16800.000
      }
    ]
  },
  "sources": [ { "type": "customers", "id": 2207, "label": "Diyar Real Estate Development Co." }, { "type": "invoices", "id": null, "label": "Open invoices, Diyar Real Estate" } ]
}
```

# Fraud Alerts

**What it shows.** The `category = 'fraud'` view (`widget_id: fraud_alerts`) — QAYD's highest-scrutiny panel, surfacing duplicate-transaction detection, vendor bank-detail changes immediately preceding a scheduled payment, segregation-of-duties violations, off-hours or off-pattern journal activity, and round-trip transaction patterns.

**Data source.** `GET /api/v1/ai/fraud-alerts` (permission: `reports.read` to view; resolution requires `ai.approve`). From `audit_logs` (the authoritative record of who did what, when, from where), `journal_entries`, `vendor_bank_accounts`, `bills`, and `invoices`. `FRAUD_DETECTION` is the sole author of `decision_type = 'fraud_alert'` rows; `AUDITOR` independently reviews every `high` or `critical` fraud flag within one business day and may upgrade, downgrade, or corroborate it, recorded as its own `audit_exception` decision cross-linked to the same `ai_risk_flags` row.

**AI behavior — the one place AI acts before a human looks.** Every irreversible or money-moving action stays human-gated with no exception. But **placing a protective hold on a not-yet-executed proposal is not one of those actions** — it is the AI declining to let something risky proceed by default, which is the safe direction to fail in. `FRAUD_DETECTION` is therefore permitted to set `ai_approval_requests.status = 'held'` (see Approval Center) on a pending payment the instant it detects a qualifying pattern, **automatically and without waiting for a human**, because the hold only prevents money from moving — it moves nothing itself, deletes nothing, and is fully reversible the moment a human clears it. This is stated explicitly here because it is the one behavior in this document that looks, at first glance, like "AI acting on its own" — it is not; it is the AI's only unilateral action being a safety brake, never a trigger.

**Interaction.** A `critical` fraud card cannot be dismissed by anyone below Finance Manager (`ai.approve` permission-gated), and resolving one always requires a `resolution_note`. The **Verify vendor** action on a bank-detail-change flag opens a scripted call-back checklist (call the vendor's *registered* phone number, not the number in the email that requested the change) rather than a free-text field, nudging the human toward the actual anti-fraud control rather than a rubber stamp.

**Example — three concurrent fraud flags for Al-Noor Trading:**

```json
{
  "widget_id": "fraud_alerts",
  "company_id": 4821,
  "agent_code": "FRAUD_DETECTION",
  "confidence_score": 96.0,
  "status": "critical",
  "headline": "Payment held: Al-Fajr Cement changed bank details 2 hours before a KWD 18,540.000 payment",
  "data": {
    "alerts": [
      {
        "id": 55214, "severity": "critical", "pattern": "vendor_bank_detail_change_pre_payment",
        "vendor_id": 3312, "vendor_name": "Al-Fajr Cement Supplies",
        "detail": "IBAN changed at 2026-07-16T04:10:00+03:00; a KWD 18,540.000 payment was scheduled to run at 2026-07-16T06:00:00+03:00 in the July vendor payment batch.",
        "action_taken": "ai_approval_requests #77310 status set to 'held' automatically; payment did not execute.",
        "resolution_path": "Call Al-Fajr's registered contact number (on file since 2023) to verbally confirm the change before releasing the hold."
      },
      {
        "id": 55213, "severity": "high", "pattern": "duplicate_bill_suspected",
        "detail": "BILL-2026-01184 (KWD 6,240.000, PO-2026-0977) matches BILL-2026-01179 (same amount, same PO) submitted 4 days apart.",
        "action_taken": "Both bills held from the payment run pending manual match confirmation."
      },
      {
        "id": 55216, "severity": "high", "pattern": "segregation_of_duties_violation",
        "detail": "user_id 172 (Sales Employee role) attempted 3 times in 10 minutes to post a manual KWD 2,000.000 credit adjustment directly to Diyar Real Estate's AR balance without a linked credit note.",
        "action_taken": "Blocked at the permission layer (Sales Employee lacks accounting.journal.post); independently flagged for the repeated-attempt pattern regardless of the block succeeding."
      }
    ]
  },
  "sources": [
    { "type": "vendor_bank_accounts", "id": 3312, "label": "Al-Fajr Cement Supplies — bank details" },
    { "type": "audit_logs", "id": null, "label": "3 blocked attempts, user_id 172, 2026-07-16" }
  ]
}
```

# Financial Forecast

**What it shows.** The 13-week rolling forecast (`widget_id: financial_forecast`) that Cash Flow Status only teases 30 days of — full revenue, expense, and cash projections with P10/P50/P90 confidence bands, driven by a named model rather than a black-box line, so a CFO can see not just *what* is projected but *how sure* the model is and *why*.

**Data source.** `GET /api/v1/ai/forecast?horizon=13w` (permission: `reports.read`). `FORECAST_AGENT` builds the model from three inputs: historical seasonality (trailing 24 months of `journal_lines`, decomposed into trend + seasonal components), contractual certainty (recurring `bills`, `payroll_runs` schedule, signed `sales_orders` pipeline with delivery dates), and customer-specific collection behavior (each customer's own historical days-to-pay, not their contracted terms — the same signal Customer Risks surfaces). Persisted as `ai_decisions`, `decision_type = 'forecast_cash_projection'` for the cash line and `'forecast_revenue'` for the revenue line, `agent_code = 'FORECAST_AGENT'`.

**AI behavior.** The model publishes three bands, not one number: **P10** (a pessimistic case a result should only fall below 10% of the time), **P50** (the median expectation — the line shown by default), and **P90** (an optimistic case). Confidence on the *forecast itself* is reported as the historical back-tested accuracy of the P50 line at this horizon for this company (Al-Noor's 13-week P50 has tracked actuals within 8% over the last 4 quarters, so the forecast's own `confidence_score` is 82). This is meaningfully different from the confidence score on a single insight — it is a track-record-based number, and its calculation method is disclosed in `reasoning` rather than asserted.

**Interaction.** Toggle between Cash / Revenue / Expense lines; toggle band visibility (P10/P50/P90); a **Run a scenario** button opens the Simulation Panel with the current P50 line loaded as the baseline to deviate from, so every simulation is anchored to the same forecast a user was just looking at rather than a separately-computed baseline.

**Example — Al-Noor Trading, weeks 1–4 of the 13-week cash forecast:**

| Week ending | P10 (KWD) | P50 (KWD) | P90 (KWD) | Driver |
|---|---|---|---|---|
| 2026-07-22 | 27,100 | 34,200 | 39,800 | Normal collections |
| 2026-07-29 | 12,400 | 18,900 | 24,600 | Payroll + KSA VAT overlap |
| 2026-08-05 | 15,900 | 22,700 | 29,300 | Recovery begins |
| 2026-08-12 | 21,000 | 29,800 | 37,100 | PIFSS payment due Aug 15 (falls in week 5) |

```json
{
  "widget_id": "financial_forecast",
  "company_id": 4821,
  "agent_code": "FORECAST_AGENT",
  "decision_id": 881640,
  "confidence_score": 82.0,
  "status": "warning",
  "headline": "13-week cash forecast: one trough (Jul 29), recovers by mid-August",
  "reasoning": "Model backtested at 8% mean absolute deviation from actuals on the trailing 4 quarters' P50 line at this horizon for this company. Bands reflect the historical spread between contracted collection terms and this company's realized days-to-pay distribution by customer.",
  "data": {
    "horizon_weeks": 13,
    "series": [
      { "week_ending": "2026-07-22", "p10": 27100.000, "p50": 34200.000, "p90": 39800.000 },
      { "week_ending": "2026-07-29", "p10": 12400.000, "p50": 18900.000, "p90": 24600.000 },
      { "week_ending": "2026-08-05", "p10": 15900.000, "p50": 22700.000, "p90": 29300.000 },
      { "week_ending": "2026-08-12", "p10": 21000.000, "p50": 29800.000, "p90": 37100.000 }
    ]
  },
  "actions": [ { "id": "run_scenario", "label": "Run a scenario", "type": "navigate", "target": "/ai/simulate?baseline=881640" } ],
  "sources": [ { "type": "journal_lines", "id": null, "label": "Trailing 24 months, seasonality decomposition" }, { "type": "sales_orders", "id": null, "label": "Open pipeline, delivery-dated" } ]
}
```

# Approval Center

**What it shows.** The literal, visible human gate (`widget_id: approval_center_queue`) — every AI proposal touching a sensitive action (bank transfers, payroll release, tax submission, voiding/deleting financial data, permission changes) or exceeding a company-configured value threshold sits here until a human with the right permission signs off. This is not a notifications list; it is a queue with state, a chain, and an SLA, and it is the single panel every other panel's "send for approval" button ultimately deposits into.

**Data source.** `GET /api/v1/approvals` (permission: `ai.approve` to act, `reports.read` to view read-only), `POST /api/v1/approvals/{id}/approve|reject|delegate`. Backed by two new tables — one for the request, one for its (potentially multi-step) chain:

```sql
CREATE TYPE ai_approval_status AS ENUM (
    'pending', 'held', 'in_review', 'approved', 'rejected', 'changes_requested', 'expired', 'cancelled'
);
CREATE TYPE ai_approval_subject_type AS ENUM (
    'bank_transfer', 'payroll_release', 'tax_submission', 'journal_void',
    'permission_change', 'purchase_order', 'credit_note', 'credit_hold', 'vendor_bank_update'
);

CREATE TABLE ai_approval_requests (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    subject_type        ai_approval_subject_type NOT NULL,
    subject_id          BIGINT NULL,             -- e.g. the draft bank_transfers.id, payroll_runs.id
    source_decision_id  BIGINT NULL REFERENCES ai_decisions(id),
    source_agent_code   VARCHAR(40) NULL REFERENCES ai_agents(code),
    title               VARCHAR(200) NOT NULL,
    amount              NUMERIC(19,4) NULL,
    currency_code       VARCHAR(3) NULL,
    status              ai_approval_status NOT NULL DEFAULT 'pending',
    required_permission VARCHAR(60) NOT NULL,     -- e.g. 'bank.transfer', 'payroll.approve', 'tax.submit'
    hold_reason         TEXT NULL,                -- populated when FRAUD_DETECTION auto-holds (see Fraud Alerts)
    sla_due_at          TIMESTAMPTZ NULL,
    decided_at          TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL
);

CREATE TABLE ai_approval_steps (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    approval_request_id BIGINT NOT NULL REFERENCES ai_approval_requests(id),
    step_order          SMALLINT NOT NULL,
    approver_role       VARCHAR(60) NOT NULL,     -- e.g. 'Senior Accountant', 'CFO'
    approver_user_id    BIGINT NULL REFERENCES users(id),
    status              ai_approval_status NOT NULL DEFAULT 'pending',
    decided_by          BIGINT NULL REFERENCES users(id),
    decided_at          TIMESTAMPTZ NULL,
    comment             TEXT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (approval_request_id, step_order)
);
CREATE INDEX idx_ai_approval_requests_company_status ON ai_approval_requests (company_id, status, sla_due_at);
CREATE INDEX idx_ai_approval_steps_request ON ai_approval_steps (approval_request_id, step_order);
```

**AI behavior.** `APPROVAL_ASSISTANT` is the only agent that writes to `ai_approval_requests`, and it never approves anything — it only (a) creates the request the moment a sensitive or threshold-crossing proposal exists, (b) resolves the correct approver chain by reading the company's `roles`/`permissions` configuration for the `required_permission`, populating one or more `ai_approval_steps` in sequence, and (c) escalates automatically if `sla_due_at` passes with no decision (default SLA: 4 business hours for `critical`-sourced requests, 2 business days otherwise), surfacing the breach in Urgent Actions. `FRAUD_DETECTION` is the only other writer, and only to set `status = 'held'` — the safety-brake behavior documented in Fraud Alerts.

**Interaction.** Approve / Reject / Request Changes / Delegate, per step. A request only reaches the next step once the current step approves; a rejection at any step terminates the whole chain (`status = 'rejected'`) rather than silently skipping to the next approver. Bulk-approve is available only for a batch of same-type, below-threshold, non-held requests (e.g., ten routine expense reclassifications) — never for anything carrying `required_permission IN ('bank.transfer', 'payroll.approve', 'tax.submit')`, which always render individually, one signature at a time.

**Example — the July vendor payment run, a two-step chain (mirrors `PERMISSION_SYSTEM.md`'s own Finance Manager → CFO example):**

```json
{
  "widget_id": "approval_center_queue",
  "company_id": 4821,
  "data": {
    "id": 77302, "subject_type": "bank_transfer", "title": "July vendor payment run (6 vendors, excludes held Al-Fajr payment)",
    "amount": 24180.000, "currency_code": "KWD", "status": "in_review", "required_permission": "bank.transfer",
    "source_agent_code": "TREASURY_MANAGER", "sla_due_at": "2026-07-16T14:00:00+03:00",
    "steps": [
      { "step_order": 1, "approver_role": "Senior Accountant", "approver_user_id": 124, "status": "approved", "decided_at": "2026-07-16T07:40:00+03:00", "comment": "Invoice matching confirmed for all 6 vendors." },
      { "step_order": 2, "approver_role": "CFO", "approver_user_id": 118, "status": "pending" }
    ]
  },
  "sources": [ { "type": "ai_decisions", "id": 991750, "label": "Treasury Manager payment-run proposal" } ]
}
```

A second, `held` example — the Al-Fajr payment `FRAUD_DETECTION` froze automatically (see Fraud Alerts) — renders distinctly in the same queue so it is never mistaken for an ordinary pending item:

```json
{
  "id": 77310, "subject_type": "vendor_bank_update", "title": "Payment to Al-Fajr Cement Supplies — held, bank details changed 2h prior",
  "amount": 18540.000, "currency_code": "KWD", "status": "held", "required_permission": "bank.transfer",
  "hold_reason": "Vendor IBAN changed 2026-07-16T04:10, 1h50m before scheduled run. Awaiting verbal vendor verification.",
  "source_agent_code": "FRAUD_DETECTION", "sla_due_at": "2026-07-16T10:00:00+03:00"
}
```

# Automation Center

**What it shows.** The configuration surface (`widget_id: automation_center_rules`) where a company graduates a repetitive, low-risk AI recommendation from "suggest every time" to "just do it" — and, symmetrically, where it can instantly demote or kill-switch an automation that starts misbehaving. This is the panel that makes the rest of the Command Center get quieter over time as trust is earned category by category, rather than staying a fixed wall of recommendations forever.

**Data source.** `GET/PUT /api/v1/ai/automations` (permission: `ai.automation`), backed by:

```sql
CREATE TYPE ai_automation_autonomy AS ENUM ('suggest_only', 'auto');

CREATE TABLE ai_automation_rules (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    rule_key                VARCHAR(80) NOT NULL,     -- e.g. 'auto_categorize_bank_transactions'
    title                   VARCHAR(200) NOT NULL,
    agent_code              VARCHAR(40) NOT NULL REFERENCES ai_agents(code),
    autonomy                ai_automation_autonomy NOT NULL DEFAULT 'suggest_only',
    confidence_threshold    NUMERIC(5,2) NOT NULL DEFAULT 90.00,
    enabled                 BOOLEAN NOT NULL DEFAULT true,
    scope                   JSONB NOT NULL DEFAULT '{}',   -- e.g. { "branch_id": null, "max_amount_kwd": 2000 }
    kill_switch_tripped     BOOLEAN NOT NULL DEFAULT false,
    kill_switch_reason      TEXT NULL,
    last_run_at             TIMESTAMPTZ NULL,
    runs_30d                INTEGER NOT NULL DEFAULT 0,
    overrides_30d           INTEGER NOT NULL DEFAULT 0,   -- human corrections in the trailing 30 days
    created_by              BIGINT NULL REFERENCES users(id),
    updated_by              BIGINT NULL REFERENCES users(id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, rule_key)
);
CREATE INDEX idx_ai_automation_rules_company ON ai_automation_rules (company_id, enabled);
```

**AI behavior.** `REPORTING_AGENT` monitors every enabled automation's `overrides_30d` / `runs_30d` ratio continuously; if the override rate exceeds 5% for two consecutive weeks, the rule is automatically flipped `kill_switch_tripped = true` (autonomy effectively reverts to `suggest_only` until a human reviews and re-enables it), and this event lands in Urgent Actions. No `ai_automation_rules` row can ever carry autonomy `auto` for a `rule_key` whose underlying action requires a sensitive permission (`bank.transfer`, `payroll.approve`, `tax.submit`, permission changes, deletions) — this is enforced at the database layer via a `CHECK` constraint style validation in the Laravel FormRequest, not merely a UI restriction, so no misconfiguration can silently turn a sensitive action fully autonomous.

**Interaction.** Each rule is a row with a single autonomy toggle, a confidence-threshold slider, a scope editor (e.g., cap auto-categorization to transactions under KWD 2,000), and a 30-day performance sparkline (runs vs. overrides). A **Promote to auto** suggestion appears automatically once a `suggest_only` rule has been manually approved unchanged for 10 consecutive occurrences — this is how `TREASURY_MANAGER`'s recurring-rent-entry rule below earns its promotion prompt.

**Example — four of Al-Noor Trading's configured rules:**

| Rule | Agent | Autonomy | Threshold | 30-day runs | 30-day overrides | Override rate |
|---|---|---|---|---|---|---|
| Auto-categorize bank transactions | `GENERAL_ACCOUNTANT` | auto | 92% | 1,204 | 5 | 0.42% |
| Auto-send payment reminders (Net+3d) | `TREASURY_MANAGER` | auto | 95% | 86 | 0 | 0% |
| Auto-match goods receipts to POs | `INVENTORY_MANAGER` | auto | 90% | 212 | 3 | 1.4% |
| Auto-post recurring rent journal entry | `GENERAL_ACCOUNTANT` | suggest_only | 98% | 1 | 0 | 0% (6/6 months approved unchanged — promotion suggested) |

```json
{
  "widget_id": "automation_center_rules",
  "company_id": 4821,
  "data": {
    "rules": [
      { "rule_key": "auto_categorize_bank_transactions", "agent_code": "GENERAL_ACCOUNTANT", "autonomy": "auto", "confidence_threshold": 92.00, "runs_30d": 1204, "overrides_30d": 5, "override_rate_pct": 0.42, "enabled": true },
      { "rule_key": "auto_post_recurring_rent_journal", "agent_code": "GENERAL_ACCOUNTANT", "autonomy": "suggest_only", "confidence_threshold": 98.00, "runs_30d": 1, "overrides_30d": 0, "enabled": true,
        "promotion_suggested": true, "promotion_reason": "Approved unchanged for 6 consecutive months" }
    ]
  }
}
```

# Today's Tasks

**What it shows.** A single, priority-ranked list (`widget_id: todays_tasks`) merging three sources that would otherwise live on three different screens: AI-originated items needing a decision (from Approval Center and AI Recommendations), operational deadlines landing today (payroll cutoffs, tax dates), and manually created reminders a human added directly. It is the "what should I do next" answer the design system commits to, made literal.

**Data source.** `GET /api/v1/ai/command-tasks?due=today` (permission: `reports.read`, scoped to tasks the requesting user is entitled to act on). Backed by a queue table populated by triggers from its upstream sources rather than being an independent source of truth for any of them:

```sql
CREATE TYPE ai_task_origin AS ENUM ('approval', 'risk_flag', 'tax_deadline', 'payroll_deadline', 'manual', 'recommendation');
CREATE TYPE ai_command_task_status AS ENUM ('open', 'in_progress', 'done', 'snoozed', 'dismissed');

CREATE TABLE ai_command_tasks (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    assigned_to         BIGINT NULL REFERENCES users(id),
    assigned_role       VARCHAR(60) NULL,
    origin              ai_task_origin NOT NULL,
    origin_id           BIGINT NULL,             -- FK to ai_approval_requests.id / ai_risk_flags.id / tax_returns.id / etc., resolved by 'origin'
    title               VARCHAR(200) NOT NULL,
    priority            SMALLINT NOT NULL DEFAULT 3,   -- 1 (urgent) .. 5 (low)
    due_at              TIMESTAMPTZ NULL,
    status              ai_command_task_status NOT NULL DEFAULT 'open',
    snoozed_until        TIMESTAMPTZ NULL,
    snooze_reason        TEXT NULL,
    completed_at         TIMESTAMPTZ NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_command_tasks_company_due ON ai_command_tasks (company_id, assigned_to, status, due_at);
```

**AI behavior.** Nothing on this list is independently authored here — every row's `origin`/`origin_id` pair points back to the panel that actually owns the underlying fact (an approval, a risk flag, a deadline). `PAYROLL_MANAGER` and `TAX_ADVISOR` are the two agents that most frequently populate `origin = 'payroll_deadline' | 'tax_deadline'` rows ahead of the actual due date (configurable lead time, default 14 days for tax, 5 business days for payroll). This design deliberately prevents Today's Tasks from becoming a fourth place a fact can silently drift out of sync with its source.

**Interaction.** Checking a task off only marks `ai_command_tasks.status = 'done'` when the underlying origin item is itself resolved (e.g., checking off "Approve the vendor payment run" without actually approving it in Approval Center does nothing — the UI redirects to the real action). Manual tasks (`origin = 'manual'`) are the only kind a user can create, edit, or delete outright.

**Example — Fahad's list for 2026-07-16:**

```json
{
  "widget_id": "todays_tasks",
  "company_id": 4821,
  "assigned_to": 101,
  "data": {
    "tasks": [
      { "id": 66010, "origin": "approval", "origin_id": 77302, "title": "Approve July vendor payment run (KWD 24,180.000)", "priority": 1, "due_at": "2026-07-16T14:00:00+03:00", "status": "open" },
      { "id": 66011, "origin": "risk_flag", "origin_id": 55214, "title": "Verify Al-Fajr Cement's changed bank details", "priority": 1, "due_at": "2026-07-16T10:00:00+03:00", "status": "open" },
      { "id": 66012, "origin": "recommendation", "origin_id": 991701, "title": "Decide on the 60-ton Steel Rebar reorder", "priority": 2, "due_at": "2026-07-18T17:00:00+03:00", "status": "open" },
      { "id": 66013, "origin": "tax_deadline", "origin_id": 6620, "title": "Review KSA Q2 VAT return draft", "priority": 3, "due_at": "2026-07-31T17:00:00+03:00", "status": "open" }
    ]
  }
}
```

# Urgent Actions

**What it shows.** A persistent banner strip (`widget_id: urgent_actions_banner`), visually distinct from Today's Tasks and pinned above every other panel, reserved exclusively for items that are both time-critical and irreversible-if-missed: a critical fraud hold, an SLA-breached approval, a same-day payroll cutoff, or a tax deadline inside 48 hours. It is deliberately hard to ignore and, unlike ordinary tasks, cannot be casually swiped away.

**Data source.** Computed, not separately stored — a real-time query unioning `ai_risk_flags WHERE severity = 'critical' AND status = 'open'`, `ai_approval_requests WHERE sla_due_at < now() + interval '4 hours' AND status IN ('pending','in_review')`, and `ai_command_tasks WHERE priority = 1 AND due_at < now() + interval '24 hours'`. Exposed at `GET /api/v1/ai/urgent-actions` (permission: `reports.read`), refreshed via Laravel Reverb push the instant any qualifying row changes state, not on a polling interval.

**AI behavior.** `APPROVAL_ASSISTANT` computes the union and rank order (critical fraud holds first, then SLA-breached approvals, then same-day operational deadlines); no agent authors new content here — every item is a pointer back to a fully-specified card elsewhere in this document. This panel's entire job is triage speed, not information.

**Interaction.** An urgent item can be dismissed only two ways: resolving the underlying item (which removes it automatically), or an explicit **Snooze** with a required reason, logged to `audit_logs` with the snoozing user, the reason text, and a maximum snooze window of 2 hours for `critical`-severity items — long enough to step out of a meeting, not long enough to defer a fraud hold until tomorrow.

**Example.**

```json
{
  "widget_id": "urgent_actions_banner",
  "company_id": 4821,
  "status": "critical",
  "data": {
    "items": [
      { "type": "risk_flag", "id": 55214, "severity": "critical", "title": "Payment held: Al-Fajr Cement bank-detail change", "target": "/ai/fraud-alerts/55214" },
      { "type": "approval", "id": 77302, "title": "Vendor payment run — SLA due in 3h20m", "target": "/approvals/77302" }
    ]
  }
}
```

# Ask AI

**What it shows.** A persistent conversational affordance (`widget_id: ask_ai_panel`) — not a separate page, a docked entry point available from every screen of the Command Center — that already knows what the user is looking at. Asking a question from inside the Cash Flow Status card implicitly scopes the question to cash; asking the same question from the top-level dashboard scopes it to the whole company.

**Data source.** `POST /api/v1/ai/chat` (permission: `ai.chat`), backed by the foundation `ai_conversations` / `ai_messages` tables. The FastAPI orchestrator does not answer the question itself — it performs LangGraph-style routing: classify the question's domain, select the one or two specialist agents best suited to answer it (this is the "long-term goal" QAYD's own AI Architecture commits to — *"the user never chooses which agent to use; QAYD automatically selects the best combination of agents"*), retrieve the relevant context (RAG over this company's own data plus `ai_memory` for prior related conversations), and have the selected agent(s) compose the answer. Every tool the orchestrator can call is declared as an MCP tool definition mapped 1:1 to a permissioned Laravel endpoint:

```json
{
  "tools": [
    { "name": "get_account_balance", "endpoint": "GET /api/v1/accounting/accounts/{id}/balance", "permission": "accounting.read" },
    { "name": "list_invoices", "endpoint": "GET /api/v1/sales/invoices", "permission": "accounting.read" },
    { "name": "list_open_bills", "endpoint": "GET /api/v1/purchases/bills?status=open", "permission": "accounting.read" },
    { "name": "get_customer_aging", "endpoint": "GET /api/v1/reports/ar-aging", "permission": "reports.read" },
    { "name": "get_forecast", "endpoint": "GET /api/v1/ai/forecast", "permission": "reports.read" },
    { "name": "draft_journal_entry", "endpoint": "POST /api/v1/accounting/journal-entries (status=draft only)", "permission": "accounting.journal.create" }
  ]
}
```

Every tool call the orchestrator makes on a user's behalf is executed with that user's own bearer token and `X-Company-Id` — never a service-account escalation — so the AI's chat answer can never surface data the asking user could not otherwise see through the ordinary UI.

**AI behavior.** Every chat answer is logged as its own `ai_decisions` row (`decision_type = 'assistant_conversational_response'`, `agent_code` = whichever specialist actually answered) with the same confidence/reasoning/sources discipline as any other panel — a chat answer is not exempt from citation just because it is conversational. If no tool call can retrieve data sufficient to answer confidently, the agent says so explicitly rather than guessing (see Natural Language Queries' query-firewall discussion for how this is enforced structurally, not just as a prompting convention).

**Interaction.** Docked chat drawer, available everywhere; suggested follow-up questions are generated from the current screen's context (e.g., viewing Customer Risks surfaces "Draft a credit-hold recommendation for Diyar?" as a one-tap suggestion). Any answer that implies an action renders the same **Do it / Send for approval / Dismiss** control set as AI Recommendations, so a conversational answer that happens to double as a recommendation never needs a second interface.

**Example — Mariam (CFO, `user_id` 118) asks a question from the Business Health Score card:**

```json
{
  "widget_id": "ask_ai_panel",
  "company_id": 4821,
  "data": {
    "conversation_id": 44012, "message_id": 220981,
    "question": "Why did our gross margin improve this month?",
    "answer": "Gross margin rose from 29.8% in June to 31.2% in July, driven almost entirely by mix, not unit pricing: Contracting Services (your highest-margin category at 46% gross margin) grew from 15% to 18% of revenue, while Other Building Materials (your lowest-margin category at 19%) fell from 20% to 14%. Unit costs on your top 5 SKUs by volume are flat versus June.",
    "agent_code": "CFO_AGENT", "confidence_score": 93.0,
    "sources": [
      { "type": "journal_lines", "id": null, "label": "Revenue and COGS postings by category, Jun-Jul 2026" },
      { "type": "ai_decisions", "id": 991613, "label": "CFO Agent margin insight, 2026-07-16" }
    ]
  }
}
```

# Voice Assistant

**What it shows.** The same conversational capability as Ask AI, voice-first (`widget_id: voice_assistant`), designed for the moment a user is not looking at a screen at all — Fahad walking to his car, Mariam between meetings. Push-to-talk on mobile (no always-listening wake word, by design, to avoid ambient capture of financial conversations in a shared office); the response plays back as speech with an optional "view detail" tap if the user wants the full card afterward.

**Data source.** Same `POST /api/v1/ai/chat` endpoint as Ask AI, with `modality: "voice"`. Audio is uploaded to Cloudflare R2, transcribed by a speech-to-text engine tuned for both English and Gulf-dialect Arabic, routed through the identical orchestrator and MCP tool layer as text chat (voice is a transport, not a different reasoning path), and the resulting answer is synthesized back to speech in the company's configured voice and language before playback.

**AI behavior.** Because voice interactions often happen away from a screen, any answer that would normally render an actionable card (e.g., a recommendation) is spoken with an explicit verbal confirmation step before anything executes — "I can categorize those 214 transactions now, say 'confirm' to proceed" — rather than acting on the first utterance, since a misheard word in a hands-free context is a much higher-cost mistake than a misclick.

**Interaction.** Hold-to-talk button (mobile) or dedicated hardware button where available; transcript always shown as it streams, so a user can visually confirm the system heard them correctly even while primarily listening. Every voice session's full transcript and audio are retained against `ai_conversations`/`ai_messages` exactly like a text chat, for audit purposes.

**Example — Fahad, driving, asks in Gulf Arabic:**

```json
{
  "widget_id": "voice_assistant",
  "company_id": 4821,
  "data": {
    "transcript_in": "شحال الكاش عندي اليوم؟",
    "transcript_in_translated": "How much cash do I have today?",
    "answer_text": "Cash today is 42,318 dinars and 600 fils across your three accounts. Heads up — it's projected to dip to about 18,900 on July 29th when payroll and the Saudi VAT payment land in the same week.",
    "answer_audio_url": "https://cdn.qayd.app/r2/voice/4821/session-88213-ar.mp3",
    "agent_code": "TREASURY_MANAGER", "confidence_score": 93.5,
    "language_detected": "ar-GULF"
  }
}
```

# Natural Language Queries

**What it shows.** The structured engine (`widget_id: nlq_engine`) underneath both Ask AI and Voice Assistant — the piece that turns "how much did we spend on subcontractors in Q2 vs Q1?" into a safe, bounded, auditable query rather than free-text SQL generation against a production financial database.

**Data source.** `POST /api/v1/ai/nlq` (permission: `reports.read`). This is a deliberate two-stage design, and the stage boundary is the actual safety mechanism, not a formality: **Stage 1** — the LLM converts the question into a structured **Query Intent** (metric, dimensions, filters, time range) and nothing else; **Stage 2** — that Query Intent is validated against a whitelist of pre-approved `report_definitions` and their allowed filter/dimension combinations before any data is touched. A Query Intent that does not match an approved `report_definitions` shape is rejected before execution, with the specific unsupported element named back to the user — this is the "query firewall" that makes NLQ safe to expose broadly without every question becoming a bespoke, unreviewed database query.

**AI behavior.** Reporting Agent executes the validated intent as an ordinary parameterized report call (identical code path to a human building the same filter by hand in the Reports module) and returns both the raw result and a one-line narrative. Confidence here reflects intent-parsing certainty, not data quality — a well-formed, unambiguous question against a supported report shape scores near 100; an ambiguous one ("how are we doing?") triggers a clarifying question instead of a guess.

**Interaction.** A visible "Interpreted as" chip always shows the resolved Query Intent above the answer, so a user can catch a misinterpretation immediately (e.g., "subcontractors" resolving to a specific `vendor_category` filter) rather than trusting an opaque answer.

**Example.**

```json
{
  "widget_id": "nlq_engine",
  "company_id": 4821,
  "data": {
    "question": "How much did we spend on subcontractors in Q2 compared to Q1?",
    "query_intent": {
      "metric": "expense_total", "dimension_filter": { "vendor_category": "subcontractors" },
      "compare_periods": [ { "label": "Q1 2026", "range": "2026-01-01/2026-03-31" }, { "label": "Q2 2026", "range": "2026-04-01/2026-06-30" } ]
    },
    "validated_against": "report_definitions#expense_by_vendor_category",
    "result": { "q1_total_kwd": 34200.000, "q2_total_kwd": 41150.000, "change_pct": 20.3 },
    "narrative": "Subcontractor spend rose from KWD 34,200.000 in Q1 to KWD 41,150.000 in Q2, up 20.3%, tracking the higher volume of Contracting Services revenue in the same period.",
    "confidence_score": 95.0
  }
}
```

# Simulation Panel

**What it shows.** A sandboxed what-if surface (`widget_id: simulation_panel`) where a user perturbs one or more assumptions — hire headcount, delay a collection, take a loan, lose a customer — and sees the projected impact on cash, margin, and the Business Health Score within seconds, without touching a single real ledger row. Every simulation is explicitly, visibly non-destructive: nothing it produces is ever posted, and nothing it computes ever silently becomes the "real" forecast.

**Data source.** `POST /api/v1/ai/simulate` (permission: `reports.read`; no write permission needed since nothing is persisted to financial tables). `FORECAST_AGENT` re-runs its own forecast model with the requested deltas applied as overrides on top of the same baseline `Financial Forecast` uses, so a simulation is always anchored to the real current forecast rather than a separately-drifting copy. `CFO_AGENT` supplies the strategic interpretation layer on top of the raw numeric output (this split mirrors exactly the "Auto (compute) / Suggest-only (interpret)" autonomy row `CFO_AGENT.md` already defines for scenario work). Results are cached in Redis for the session and only persisted to PostgreSQL if the user explicitly names and saves the scenario:

```sql
CREATE TABLE ai_simulations (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    created_by          BIGINT NOT NULL REFERENCES users(id),
    name                VARCHAR(150) NOT NULL,
    baseline_decision_id BIGINT NULL REFERENCES ai_decisions(id),
    assumptions         JSONB NOT NULL,      -- [{ "type": "hire", "count": 3, "monthly_cost_kwd": 550, "start": "2026-09-01" }]
    result_summary      JSONB NOT NULL,
    is_saved            BOOLEAN NOT NULL DEFAULT false,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_simulations_company ON ai_simulations (company_id, created_by);
```

**AI behavior.** A simulation never mutates `ai_decisions`, `journal_entries`, or any operational table — it is pure computation over a forked, in-memory copy of the forecast state. Confidence is inherited from the underlying `Financial Forecast` model's own backtested accuracy, with an explicit caveat surfaced whenever an assumption pushes more than 2 standard deviations outside this company's historical range (e.g., simulating a 10x headcount increase would trigger this caveat; simulating 3 hires does not).

**Interaction.** Assumption builder (typed inputs — hire/fire, delay/accelerate a receivable, add/remove a recurring cost, draw a loan) plus free-text ("what if Diyar pays 30 days late next quarter?", parsed via the same NLQ intent layer). Multiple scenarios render side by side for direct comparison. **Save scenario** persists it; **Discard** clears the Redis cache with no trace, by design.

**Example — three scenarios compared for Al-Noor Trading:**

| Scenario | Day-30 cash (KWD) | Day-90 cash (KWD) | Health Score impact |
|---|---|---|---|
| Baseline (no change) | 27,400 | 44,100 | 78 (unchanged) |
| Hire 3 site supervisors, KWD 550/mo each, starting Sep 1 | 27,400 | 38,850 | 76 (-2, opex ratio component) |
| Diyar's next invoice collects 30 days late | 14,200 | 41,600 | 74 (-4, working-capital component) |

```json
{
  "widget_id": "simulation_panel",
  "company_id": 4821,
  "agent_code": "FORECAST_AGENT",
  "data": {
    "scenarios": [
      { "name": "baseline", "day_30_cash": 27400.000, "day_90_cash": 44100.000, "health_score": 78 },
      { "name": "hire_3_supervisors", "assumptions": [ { "type": "hire", "count": 3, "monthly_cost_kwd": 550, "start": "2026-09-01" } ], "day_30_cash": 27400.000, "day_90_cash": 38850.000, "health_score": 76 },
      { "name": "diyar_30d_late", "assumptions": [ { "type": "delay_receivable", "customer_id": 2207, "days": 30 } ], "day_30_cash": 14200.000, "day_90_cash": 41600.000, "health_score": 74 }
    ]
  },
  "confidence_score": 79.0,
  "sources": [ { "type": "ai_decisions", "id": 881640, "label": "Baseline 13-week forecast" } ]
}
```

# Decision Support

**What it shows.** A requested, structured decision brief (`widget_id: decision_support_brief`) for the big, one-off calls a Simulation Panel run is too casual for — "should we open a branch in Salmiya?", "should we switch from Gulf Steel Industries to a second supplier?" This is the most senior deliverable the Command Center produces: a pros/cons/financial-impact/risk memo with every claim individually cited, assembled on request rather than continuously, because a decision of this weight deserves a deliberate ask, not a standing widget.

**Data source.** `POST /api/v1/ai/decision-briefs` (permission: `ai.chat` + `reports.read`). `CEO_ASSISTANT` orchestrates the brief but authors none of its substance — it delegates the financial-impact section to `FORECAST_AGENT` (via a multi-scenario simulation run, same engine as the Simulation Panel), the ratio/margin-impact section to `CFO_AGENT`, the compliance angle to `COMPLIANCE_AGENT`, and the operational-risk angle to whichever domain agent owns the relevant area (`INVENTORY_MANAGER` for a supplier switch, `TREASURY_MANAGER` for a new branch's working-capital need). `CEO_ASSISTANT`'s only original contribution is the executive summary that ties the sections together — again, synthesis, never new fact.

```sql
CREATE TABLE ai_decision_briefs (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    requested_by        BIGINT NOT NULL REFERENCES users(id),
    question            TEXT NOT NULL,
    executive_summary   TEXT NOT NULL,
    sections            JSONB NOT NULL,     -- [{ "title": "Financial Impact", "agent_code": "FORECAST_AGENT", "content": "...", "confidence_score": 81.0, "sources": [...] }]
    overall_confidence  NUMERIC(5,2) NOT NULL,
    recommendation      TEXT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',  -- 'draft' | 'finalized' | 'archived'
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_decision_briefs_company ON ai_decision_briefs (company_id, requested_by);
```

**AI behavior.** A decision brief is **always suggest-only**, with no execution path attached at all — unlike a recommendation, there is no "Do it" button on a decision brief, because there is no single API call that "opens a branch." Its `overall_confidence` is deliberately reported lower and more conservatively than a single-agent output like a ratio insight, because it compounds uncertainty across every contributing agent's own confidence; QAYD would rather under-claim certainty on a big, irreversible business decision than let a high aggregate score create false confidence.

**Interaction.** Requested via Ask AI or a dedicated **New decision brief** action; renders as a scrollable document (not a card) with each section individually expandable and individually sourced, exportable to PDF for a board pack, and shareable only via the same `reports.share` permission-gated action the CFO Agent's own board-narrative output uses.

**Example — abbreviated brief for "Should we open a branch in Salmiya?":**

```json
{
  "widget_id": "decision_support_brief",
  "company_id": 4821,
  "data": {
    "question": "Should we open a branch in Salmiya?",
    "executive_summary": "A Salmiya branch is financially supportable within 8 months at current growth, but only if the Diyar receivable concentration is resolved first — the cash buffer required to fund a new branch's working capital overlaps almost exactly with the cash currently tied up in Diyar's overdue balance.",
    "overall_confidence": 68.0,
    "sections": [
      { "title": "Financial Impact", "agent_code": "FORECAST_AGENT", "confidence_score": 81.0, "content": "Estimated setup cost KWD 42,000; break-even at month 7 assuming 60% of Al-Ahmadi's current run-rate." },
      { "title": "Working Capital Strain", "agent_code": "CFO_AGENT", "confidence_score": 74.0, "content": "Days cash on hand would fall from 63 to an estimated 41 during the 3-month ramp, below the 45-day internal comfort floor." },
      { "title": "Compliance", "agent_code": "COMPLIANCE_AGENT", "confidence_score": 92.0, "content": "No new tax registration required; Kuwait Municipality commercial license extension only, ~3 week lead time." }
    ],
    "recommendation": "Resolve the Diyar concentration (Customer Risks) before committing capital to Salmiya; re-run this brief once AR >90 days falls below KWD 10,000."
  }
}
```

# KPIs

**What it shows.** A configurable strip of ten headline metrics (`widget_id: kpi_strip`), each with a current value, a target, a trend arrow, a sparkline, and one line of AI commentary — the fast-scan companion to the deeper Business Health Score and Financial Forecast panels above.

**Data source.** `GET /api/v1/reports/kpis` (permission: `reports.read`). Each KPI is modeled as a `report_definitions` row with `type = 'kpi'`, computed from `journal_lines`/`ledger_entries`, with `REPORTING_AGENT` attaching the one-line commentary the same way it does for Revenue/Expense Trends (a named driver above the 70% explanatory threshold, magnitude-only below it).

**AI behavior.** Every KPI's commentary is regenerated only when the value itself changes materially (>2% move or a target-band crossing) — not on every page load — so the same explanation persists stably across a session rather than subtly rewording itself each refresh, which would undermine trust in the number.

**Interaction.** Drag-to-reorder (persisted per user via Customization); tap any tile to drill into the report behind it; a target-editing affordance (`reports.export` + `ai.automation` permission) lets a Finance Manager or CFO adjust the policy band a tile is measured against (e.g., tightening the DSO target from 35 to 30 days).

**Example — Al-Noor Trading's strip, 2026-07-16:**

| KPI | Value | Target | Trend | AI commentary |
|---|---|---|---|---|
| Revenue MTD | KWD 96,220 | — | ▲ +13.3% | Paced by Steel & Rebar and Contracting Services |
| Gross Margin | 31.2% | ≥30% | ▲ +1.4pp | Mix shift toward Contracting Services |
| Net Margin | 9.4% | ≥8% | ▲ +1.1pp | Follows gross margin improvement |
| Current Ratio | 1.8 | ≥1.3 | ▬ flat | Stable |
| Days Sales Outstanding | 42d | ≤35d | ▼ worse | Diyar Real Estate slippage |
| Days Payable Outstanding | 38d | 30-45d | ▬ flat | Within policy band |
| Inventory Turnover | 4.2x/yr | ≥4.0x | ▲ | Steel & Rebar demand |
| Days Cash on Hand | 63d | ≥45d | ▬ flat | Stable |
| Opex Ratio | 21.8% | ≤25% | ▬ flat | Stable |
| Cash Position | KWD 42,318.600 | ≥20,000 | ▼ (13d out) | Trough Jul 29, see Cash Flow Status |

```json
{
  "widget_id": "kpi_strip", "company_id": 4821,
  "data": {
    "kpis": [
      { "key": "revenue_mtd", "value": 96220.000, "unit": "KWD", "trend": "up", "change_pct": 13.3, "commentary": "Paced by Steel & Rebar and Contracting Services" },
      { "key": "gross_margin", "value": 31.2, "unit": "pct", "target": 30.0, "trend": "up", "commentary": "Mix shift toward Contracting Services" },
      { "key": "dso", "value": 42, "unit": "days", "target": 35, "trend": "down", "commentary": "Diyar Real Estate slippage" }
    ]
  }
}
```

# Customization

**What it shows.** The per-user, per-role personalization layer (`widget_id: dashboard_customization`) — widget order, visibility, size, alert thresholds, briefing time and voice/language, and default autonomy preferences (linking directly into Automation Center). QAYD ships sensible role-based defaults (an Owner's default layout leads with Morning Briefing and Business Health Score; an Accountant's leads with Today's Tasks and AI Insights) but nothing here is fixed.

**Data source.** `GET/PUT /api/v1/ai/dashboard-layout` (permission: `ai.chat` for read, no special permission to rearrange one's own view — customization is self-scoped and never exposes a widget the user's role could not otherwise see):

```sql
CREATE TABLE ai_dashboard_layouts (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    user_id             BIGINT NOT NULL REFERENCES users(id),
    layout_name         VARCHAR(60) NOT NULL DEFAULT 'default',
    is_active           BOOLEAN NOT NULL DEFAULT true,
    widgets             JSONB NOT NULL,   -- [{ "widget_id": "...", "position": {"col":0,"row":0}, "size": "2x1", "visible": true }]
    alert_thresholds    JSONB NOT NULL DEFAULT '{}',   -- e.g. { "cash_amber_kwd": 20000, "cash_red_kwd": 10000 }
    briefing_time       TIME NOT NULL DEFAULT '06:00',
    briefing_voice      VARCHAR(30) NOT NULL DEFAULT 'default_en',
    briefing_language   VARCHAR(5) NOT NULL DEFAULT 'en',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, user_id, layout_name)
);
```

**AI behavior.** `REPORTING_AGENT` observes usage (which widgets a user actually opens versus scrolls past) and, no more than once a quarter, offers a single non-intrusive suggestion ("You haven't opened Supplier Risks in 60 days — hide it from your default view?") — always a suggestion, never a silent rearrangement.

**Interaction.** Drag-and-drop grid editor (web), long-press-and-reorder (mobile); named layout presets (a company can pre-build an "Auditor view" preset and assign it as the default for the External Auditor role); threshold sliders for every alert-bearing panel (Cash Flow Status' amber/red floors, Automation Center's override-rate kill-switch percentage).

**Example — contrasting two saved layouts in the same company:**

```json
{
  "widget_id": "dashboard_customization", "company_id": 4821,
  "data": {
    "layouts": [
      { "user_id": 101, "layout_name": "default", "widgets": [
        { "widget_id": "morning_briefing", "position": {"col":0,"row":0}, "size": "4x1", "visible": true },
        { "widget_id": "business_health_score", "position": {"col":0,"row":1}, "size": "1x1", "visible": true },
        { "widget_id": "urgent_actions_banner", "position": {"col":0,"row":0.5}, "size": "4x1", "visible": true }
      ]},
      { "user_id": 118, "layout_name": "default", "widgets": [
        { "widget_id": "cash_flow_status", "position": {"col":0,"row":0}, "size": "2x1", "visible": true },
        { "widget_id": "financial_forecast", "position": {"col":2,"row":0}, "size": "2x1", "visible": true },
        { "widget_id": "approval_center_queue", "position": {"col":0,"row":1}, "size": "4x1", "visible": true }
      ]}
    ]
  }
}
```

# Mobile Experience

**What it shows.** The Command Center compressed to a single, priority-ranked, swipeable column (`widget_id: mobile_command_feed`) — no grid, no side-by-side widgets, because a phone screen cannot support the desktop's spatial layout without forcing a choice on what matters most. That choice is made for the user by the same severity/priority ranking Urgent Actions already computes.

**Data source.** Identical endpoints to the desktop experience, with a `X-Client: mobile` header that shifts every widget's payload to a compact variant (truncated `narrative`, smaller image assets, `data` limited to the top 3 items of any list rather than the full set — "View all" fetches the rest on demand). Push delivery for anything landing in Urgent Actions or a critical risk flag rides Laravel Reverb server-side and APNs/FCM device-side.

**AI behavior.** No agent behaves differently on mobile — the exact same `ai_decisions` rows, the exact same confidence/reasoning/sources — only the rendering density changes. The one mobile-specific behavior is Voice Assistant promotion to the primary input method: the docked text chat from Ask AI is still available, but the hold-to-talk affordance is given the prominent thumb-reachable position, since voice is the faster input on a small screen held in one hand.

**Interaction.** Vertical swipe between priority-ranked cards (Urgent Actions and any SLA-critical Approval Center item always pinned first, regardless of a saved layout preference — mobile does not honor "hide this" for anything currently critical). Approving a sensitive action from mobile (a bank transfer, a payroll release) requires a biometric re-confirmation (Face ID / fingerprint) immediately before the approval is submitted, layered on top of the existing permission check — this is a mobile-specific UX safeguard, not a relaxation of the desktop approval chain.

**Example — mobile wireframe, priority order for Fahad, 2026-07-16, 07:12:**

```
┌─────────────────────────────┐
│ ⚠ URGENT (2)                │  ← pinned, not swipeable away
│  Payment held: Al-Fajr       │
│  Approve payment run — 3h20m │
├─────────────────────────────┤
│ 🌅 Morning Briefing           │  ← swipe →
│  "Cash healthy today,        │
│   tight in 2 weeks"          │
│  [ ▶ Read to me ]            │
├─────────────────────────────┤
│ 78  Business Health Score     │  ← swipe →
│  ▲ +4 vs last month          │
├─────────────────────────────┤
│ 💰 Cash: KWD 42,318.600       │  ← swipe →
│  ⚠ dips to 18,900 on Jul 29  │
└─────────────────────────────┘
        [ 🎙 hold to talk ]
```

```json
{
  "widget_id": "mobile_command_feed", "company_id": 4821, "client": "mobile",
  "data": {
    "feed": [
      { "widget_id": "urgent_actions_banner", "pinned": true },
      { "widget_id": "morning_briefing" },
      { "widget_id": "business_health_score" },
      { "widget_id": "cash_flow_status" }
    ]
  },
  "push": { "provider": "apns_fcm_via_reverb", "critical_items_pushed": 1 }
}
```

# Widgets

**What it shows.** This section is the master reference every panel above has been pointing back to: the full widget registry. A widget in QAYD is a versioned, permissioned, independently-refreshable unit with a stable `widget_id`, a declared size envelope, a data endpoint, and an explicit list of which of the fifteen agents can write to it. Every card shown in every section of this document is one row of this registry rendered with real data.

**The manifest contract.** Distinct from the runtime **Command Card** payload (defined in Purpose, and used throughout this document as every JSON example's shape), a widget's **manifest entry** is static registry metadata — it describes the widget, it does not carry live data:

```json
{
  "widget_id": "cash_flow_status",
  "title_en": "Cash Flow Status",
  "title_ar": "الوضع النقدي",
  "category": "financial_health",
  "default_size": "2x1",
  "min_size": "1x1",
  "max_size": "2x2",
  "refresh_strategy": "event_and_interval",
  "refresh_interval_seconds": 300,
  "refresh_events": ["bank.synced", "invoice.paid", "payment.received"],
  "data_endpoint": "/api/v1/ai/cash-flow/status",
  "permission_required": "reports.read",
  "agents": ["TREASURY_MANAGER", "FORECAST_AGENT"],
  "mobile_supported": true,
  "voice_supported": true,
  "customizable": true
}
```

**The full registry.** Every widget introduced in this document, with its owning agent(s), backing endpoint, and default size token (size tokens are defined precisely in Layout, immediately below):

| `widget_id` | Title | Endpoint | Agent(s) | Default size | Permission |
|---|---|---|---|---|---|
| `morning_briefing` | Morning Briefing | `GET /api/v1/ai/briefings/{date}` | `CEO_ASSISTANT` | 4x1 | `reports.read` |
| `business_health_score` | Business Health Score | `GET /api/v1/ai/health-score` | `CFO_AGENT`, `REPORTING_AGENT` | 1x1 | `reports.read` |
| `cash_flow_status` | Cash Flow Status | `GET /api/v1/banking/accounts`, `GET /api/v1/ai/cash-flow/forecast` | `TREASURY_MANAGER`, `FORECAST_AGENT` | 2x1 | `reports.read` |
| `revenue_trends` | Revenue Trends | `GET /api/v1/reports/revenue-trends` | `REPORTING_AGENT`, `GENERAL_ACCOUNTANT` | 2x1 | `reports.read` |
| `expense_trends` | Expense Trends | `GET /api/v1/reports/expense-trends` | `REPORTING_AGENT`, `GENERAL_ACCOUNTANT`, `FRAUD_DETECTION` | 2x1 | `reports.read` |
| `ai_insights_feed` | AI Insights | `GET /api/v1/ai/insights` | all agents | 1x2 | `reports.read` |
| `ai_recommendations_feed` | AI Recommendations | `GET /api/v1/ai/recommendations` | all agents, routed by `APPROVAL_ASSISTANT` | 1x2 | `reports.read` |
| `detected_risks_radar` | Detected Risks | `GET /api/v1/ai/risks` | all risk-originating agents | 2x1 | `reports.read` |
| `tax_deadlines` | Upcoming Tax Deadlines | `GET /api/v1/tax/deadlines` | `TAX_ADVISOR`, `COMPLIANCE_AGENT` | 1x1 | `tax.read` |
| `payroll_alerts` | Payroll Alerts | `GET /api/v1/payroll/alerts` | `PAYROLL_MANAGER`, `AUDITOR` | 1x1 | `payroll.read` |
| `inventory_alerts` | Inventory Alerts | `GET /api/v1/inventory/alerts` | `INVENTORY_MANAGER`, `FORECAST_AGENT` | 1x1 | `inventory.read` |
| `supplier_risks` | Supplier Risks | `GET /api/v1/ai/supplier-risks` | `TREASURY_MANAGER`, `AUDITOR`, `FRAUD_DETECTION` | 1x1 | `reports.read` |
| `customer_risks` | Customer Risks | `GET /api/v1/ai/customer-risks` | `TREASURY_MANAGER`, `FORECAST_AGENT`, `CFO_AGENT` | 1x1 | `reports.read` |
| `fraud_alerts` | Fraud Alerts | `GET /api/v1/ai/fraud-alerts` | `FRAUD_DETECTION`, `AUDITOR` | 2x1 | `reports.read` |
| `financial_forecast` | Financial Forecast | `GET /api/v1/ai/forecast` | `FORECAST_AGENT` | 2x2 | `reports.read` |
| `approval_center_queue` | Approval Center | `GET /api/v1/approvals` | `APPROVAL_ASSISTANT`, `FRAUD_DETECTION` | 4x1 | `ai.approve` |
| `automation_center_rules` | Automation Center | `GET/PUT /api/v1/ai/automations` | `REPORTING_AGENT` (monitor), all (execute) | 4x1 | `ai.automation` |
| `todays_tasks` | Today's Tasks | `GET /api/v1/ai/command-tasks` | `APPROVAL_ASSISTANT` (aggregator) | 1x2 | `reports.read` |
| `urgent_actions_banner` | Urgent Actions | `GET /api/v1/ai/urgent-actions` | `APPROVAL_ASSISTANT` | 4x1 | `reports.read` |
| `ask_ai_panel` | Ask AI | `POST /api/v1/ai/chat` | orchestrated, any agent | docked | `ai.chat` |
| `voice_assistant` | Voice Assistant | `POST /api/v1/ai/chat` (`modality=voice`) | orchestrated, any agent | docked | `ai.chat` |
| `nlq_engine` | Natural Language Queries | `POST /api/v1/ai/nlq` | `REPORTING_AGENT` | docked | `reports.read` |
| `simulation_panel` | Simulation Panel | `POST /api/v1/ai/simulate` | `FORECAST_AGENT`, `CFO_AGENT` | 2x2 | `reports.read` |
| `decision_support_brief` | Decision Support | `POST /api/v1/ai/decision-briefs` | `CEO_ASSISTANT` + delegated agents | full document | `ai.chat` |
| `kpi_strip` | KPIs | `GET /api/v1/reports/kpis` | `REPORTING_AGENT` | 4x1 | `reports.read` |
| `dashboard_customization` | Customization | `GET/PUT /api/v1/ai/dashboard-layout` | `REPORTING_AGENT` (suggestions only) | settings page | `ai.chat` |
| `mobile_command_feed` | Mobile Experience | (aggregates all of the above) | — | single column | (inherits) |

**Widget lifecycle.** A widget is versioned independently (`MODULE_ARCHITECTURE.md`'s "every module has its own version" principle, applied at widget grain): a breaking change to a widget's `data` shape ships as a new `widget_id` suffix (e.g., `cash_flow_status_v2`) rather than mutating the old contract underneath existing saved layouts, so a user's `ai_dashboard_layouts.widgets` array never silently breaks on deploy.

# Layout

**What it shows.** The spatial system every widget above renders inside — a 12-column responsive grid on desktop, collapsing predictably down to tablet and mobile, using QAYD's design-system spacing scale (4/8/12/16/20/24/32/40/48/64) for every gutter and card padding, and its border-radius scale (12px medium, 24px cards) for every widget shell.

**Size tokens.** Every widget declares its size as columns × row-units, where one row-unit is a fixed baseline height (~120px desktop) that keeps every widget's internal vertical rhythm aligned to the same grid the design system already commits to:

| Token | Columns (of 12) | Row-units | Typical use |
|---|---|---|---|
| `1x1` | 3 | 1 | Single-number tiles (Business Health Score, Tax Deadlines) |
| `2x1` | 6 | 1 | Chart + narrative (Cash Flow Status, Revenue Trends) |
| `1x2` | 3 | 2 | Vertical feeds (AI Insights, Today's Tasks) |
| `2x2` | 6 | 2 | Dense charts with bands (Financial Forecast, Simulation Panel) |
| `4x1` | 12 | 1 | Full-width banners (Morning Briefing, Urgent Actions, Approval Center, KPI strip) |

**Desktop grid (12 columns, 1280px+):**

```
┌───────────────────────────────────────────────────────────────┐
│  urgent_actions_banner                              4x1       │
├───────────────────────────────────────────────────────────────┤
│  morning_briefing                                    4x1       │
├───────────────────┬───────────────────┬─────────────────────┤
│ business_health    │ cash_flow_status   │ ai_insights_feed     │
│ _score      1x1    │              2x1   │              1x2     │
├───────────────────┴───────────────────┤                       │
│ financial_forecast              2x2    │                       │
│                                         ├─────────────────────┤
│                                         │ ai_recommendations   │
│                                         │ _feed         1x2    │
├───────────────────┬───────────────────┴─────────────────────┤
│ revenue_trends 2x1  │ expense_trends 2x1                       │
├───────────────────┴───────────────────┬─────────────────────┤
│ detected_risks_radar             2x1    │ todays_tasks   1x2    │
├───────────────────┬───────────────────┤                       │
│ tax_deadlines 1x1   │ payroll_alerts 1x1 │                       │
├───────────────────┼───────────────────┤                       │
│ inventory_alerts 1x1│ supplier_risks 1x1 │                       │
├───────────────────┴───────────────────┴─────────────────────┤
│  kpi_strip                                           4x1       │
├───────────────────────────────────────────────────────────────┤
│  approval_center_queue                               4x1       │
└───────────────────────────────────────────────────────────────┘
                    [ 💬 Ask AI docked, bottom-right ]
```

**Tablet grid (6 columns, 768–1279px):** every `4x1` stays full-width; every `2x1`/`2x2` stays full-width (occupies all 6 columns, height unchanged); every `1x1`/`1x2` pairs two per row. Order is otherwise preserved exactly, so muscle memory transfers between devices.

**Mobile (1 column, <768px):** collapses entirely to the priority-ranked single-column feed specified in Mobile Experience — grid concepts do not apply below the tablet breakpoint at all; `urgent_actions_banner` is the only widget pinned above the fold regardless of saved layout order.

**Z-index and priority rules.** `urgent_actions_banner` always renders above the grid's document flow, never inside a scrollable region that could hide it. `approval_center_queue` items with `status = 'held'` (Fraud Detection holds) render with a distinct left-border treatment (Error Red, per the design system's color mapping) that no other queue state uses, so a hold is visually unmistakable from an ordinary pending approval even at a glance.

**Responsive image and chart rule.** Every chart-bearing widget (`revenue_trends`, `expense_trends`, `financial_forecast`, `simulation_panel`) renders via the shared charting components named in `DESIGN_SYSTEM.md` (Line, Bar, Area for trend data) and redraws — never rescales a raster image — at every breakpoint, so a forecast band chart is exactly as legible on a 375px phone as on an ultra-wide monitor.

# Examples

Every section above has shown one widget's data in isolation. This section runs the whole Command Center at once — the promise made in "How to read this document" — for one real moment: **Al-Noor Trading & Contracting W.L.L., 2026-07-16, 07:12 +03:00**, the instant Fahad's laptop screen actually paints. Three things are worth seeing at this scale that no single panel section shows on its own: how the screen loads in one request instead of twenty, how one upstream fact reaches six unrelated-looking panels without being recomputed six times, and how an approval chain scales past two steps when the action is payroll rather than a routine vendor payment.

## The bootstrap payload

The Command Center's first paint does not fire twenty separate widget requests and wait on the slowest one. `GET /api/v1/ai/command-center?layout=default` (permission: `reports.read`; every widget inside the response remains independently filtered by the requesting user's own permissions — batching reads never batches away RBAC) returns the full grid from a per-widget Redis cache in one round trip, with each entry already resolved by the time the request lands.

Cache key convention: `cc:{company_id}:{widget_id}:{branch_id|'all'}`. TTL by category: real-time widgets (`cash_flow_status`, `urgent_actions_banner`, `approval_center_queue`, `todays_tasks`) 60 seconds soft-expire, purged immediately on a relevant Reverb event regardless of TTL; computed/aggregated widgets (`business_health_score`, `kpi_strip`) 900 seconds; the daily narrative (`morning_briefing`) persists until the next day's `ai_briefings` row generates; the risk radar (`detected_risks_radar`) and every `ai_risk_flags` category-filtered view over it (`fraud_alerts`, `payroll_alerts`, `inventory_alerts`, `supplier_risks`, `customer_risks`) 300 seconds. `tax_deadlines` is the one alert-shaped panel that does *not* share that 300-second family — it is sourced independently from `tax_codes`/`tax_rates`/`tax_transactions`/`tax_returns` rather than `ai_risk_flags`, and recomputes on the twice-daily cadence its own section already implies, because a filing deadline does not move within an hour the way a risk flag might. A cache miss on a deterministic, `auto`-classified widget (a pure aggregation over posted tables — Revenue Trends, KPIs, the live half of Cash Flow Status) recomputes synchronously inline, because it is cheap SQL. A cache miss on an AI-authored widget that has not yet recomputed (waiting on an agent's own schedule or an event trigger still in flight) never blocks the page — it serves the last-cached payload with `stale: true` and a `stale_since` timestamp rather than making a human wait on an LLM call to render their home screen.

Reverb event listeners purge specific keys the instant the fact underneath them changes, rather than waiting out the TTL: `invoice.paid` purges `revenue_trends`, `cash_flow_status`, and `kpi_strip` for that company; `bank.synced` purges `cash_flow_status` alone; any new `ai_decisions` row purges every `widget_id` its `agent_code` is registered against in the Widgets registry. This is why Mariam, in User Journeys §2 below, sees her own approval land on the queue within the same second she submits it rather than up to 900 seconds later.

```json
{
  "success": true,
  "data": {
    "company_id": 4821,
    "user_id": 101,
    "layout_name": "default",
    "generated_at": "2026-07-16T07:12:00+03:00",
    "widgets": {
      "urgent_actions_banner":     { "status": "critical", "headline": "2 items need attention now", "confidence_score": null, "cache_age_seconds": 4,    "stale": false },
      "morning_briefing":          { "status": "warning",  "headline": "Good morning, Fahad. Cash is healthy today, tight in two weeks.", "confidence_score": 91.0, "cache_age_seconds": 6120, "stale": false },
      "business_health_score":     { "status": "info",     "headline": "78 / 100 — Good, up 4 points from June", "confidence_score": 96.0, "cache_age_seconds": 312,  "stale": false },
      "cash_flow_status":          { "status": "warning",  "headline": "Cash dips to KWD 18,900 on Jul 29 — payroll and VAT overlap", "confidence_score": 93.5, "cache_age_seconds": 41, "stale": false },
      "revenue_trends":            { "status": "ok",       "headline": "Revenue up 13.3% MoM, paced by Steel & Rebar and Contracting Services", "confidence_score": 88.0, "cache_age_seconds": 640, "stale": false },
      "expense_trends":            { "status": "info",     "headline": "Logistics costs up 18% MoM — traced to the renewed KSA delivery contract", "confidence_score": 90.0, "cache_age_seconds": 640, "stale": false },
      "ai_insights_feed":          { "status": "ok",       "headline": "5 new observations since your last visit", "confidence_score": null, "cache_age_seconds": 15,  "stale": false },
      "ai_recommendations_feed":   { "status": "info",     "headline": "3 recommendations open, 1 ready to execute", "confidence_score": null, "cache_age_seconds": 15,  "stale": false },
      "detected_risks_radar":      { "status": "critical", "headline": "6 open risks — 1 critical, 2 high, 2 medium, 1 low", "confidence_score": null, "cache_age_seconds": 88,  "stale": false },
      "tax_deadlines":             { "status": "warning",  "headline": "KSA VAT Q2 return is ready — 15 days to file", "confidence_score": 95.0, "cache_age_seconds": 4200, "stale": false },
      "payroll_alerts":            { "status": "warning",  "headline": "1 new hire is missing a PIFSS civil ID — will block the WPS file on Jul 27", "confidence_score": 98.0, "cache_age_seconds": 145, "stale": false },
      "inventory_alerts":          { "status": "critical", "headline": "Steel Rebar 12mm will stock out before the Diyar delivery unless reordered this week", "confidence_score": 91.0, "cache_age_seconds": 260, "stale": false },
      "supplier_risks":            { "status": "warning",  "headline": "Gulf Steel Industries is 34% of COGS — above your 25% concentration policy", "confidence_score": 89.0, "cache_age_seconds": 195, "stale": false },
      "customer_risks":            { "status": "warning",  "headline": "Diyar Real Estate is 28% of total AR and now paying in 55 days vs. 30 contracted", "confidence_score": 92.0, "cache_age_seconds": 230, "stale": false },
      "fraud_alerts":              { "status": "critical", "headline": "Payment held: Al-Fajr Cement changed bank details 2 hours before a KWD 18,540.000 payment", "confidence_score": 96.0, "cache_age_seconds": 88, "stale": false },
      "financial_forecast":        { "status": "warning",  "headline": "13-week cash forecast: one trough (Jul 29), recovers by mid-August", "confidence_score": 82.0, "cache_age_seconds": 4200, "stale": false },
      "approval_center_queue":     { "status": "warning",  "headline": "2 requests pending — 1 in review, 1 held", "confidence_score": null, "cache_age_seconds": 9,   "stale": false },
      "automation_center_rules":   { "status": "info",     "headline": "14 rules active, 1 promotion suggested", "confidence_score": null, "cache_age_seconds": 4200, "stale": false },
      "todays_tasks":              { "status": "info",     "headline": "4 open items", "confidence_score": null, "cache_age_seconds": 9,   "stale": false },
      "kpi_strip":                 { "status": "ok",       "headline": "10 KPIs tracked, 7 on target", "confidence_score": null, "cache_age_seconds": 312, "stale": false }
    }
  },
  "message": "Command Center loaded",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3fce2b1a-9d4e-4c77-8b10-6a5e2d9f7c31",
  "timestamp": "2026-07-16T07:12:00+03:00"
}
```

Twenty widgets, one request, and — per the `cache_age_seconds` values above — nothing on the screen is more than 70 minutes stale (the tax-deadline and automation-center widgets, at 4,200 seconds, are the least fresh; they recompute on a twice-daily and configuration-change cadence respectively, not on the 60–900 second cadence everything financial or risk-shaped uses).

## One fact, six panels: the Al-Fajr hold, end to end

Detected Risks stated the architectural principle: category-filtered panels are views over one table, not six sources of truth. The Al-Fajr Cement bank-detail change is the cleanest trace of that principle end to end, because it touches more of this document than any other single fact:

| Time (+03:00) | Event | System action |
|---|---|---|
| 04:10:00 | `vendor_bank_accounts` IBAN updated for vendor 3312 | Domain event `vendor.bank_account_changed` fires on Reverb |
| 04:10:04 | `FRAUD_DETECTION` evaluates the event against the scheduled 06:00 payment run | `ai_decisions` row written, `decision_type = 'fraud_alert'`, confidence 96.0 |
| 04:10:05 | Listener creates the operational record | `ai_risk_flags` id 55214, category `fraud`, severity `critical`, status `open` |
| 04:10:05 | Safety-brake action (the one unilateral AI act in this document) | `ai_approval_requests` id 77310 status set `held`; the KWD 18,540.000 payment is removed from the 06:00 batch before it runs |
| 05:30:00 | Nightly briefing job runs | `ai_briefings` for 2026-07-16 includes the flag in `top_risks` |
| 07:12:00 | Fahad opens the Command Center | The same fact renders on six panels simultaneously |

| Panel | What it shows about this one fact | Endpoint |
|---|---|---|
| Fraud Alerts | The full pattern detail, severity, and the scripted "call the registered number" resolution path | `GET /api/v1/ai/fraud-alerts` |
| Detected Risks | One row in the radar, ranked first by severity | `GET /api/v1/ai/risks` |
| Approval Center | The KWD 18,540.000 payment itself, rendered `held` with a distinct left-border treatment | `GET /api/v1/approvals` |
| Urgent Actions | Pinned first in the banner, since severity is `critical` and status is `open` | `GET /api/v1/ai/urgent-actions` |
| Today's Tasks | Task 66011, "Verify Al-Fajr Cement's changed bank details," `origin_id` pointing at risk flag 55214 | `GET /api/v1/ai/command-tasks` |
| Morning Briefing | One line under Top Risks, generated before Fahad ever opened the app | `GET /api/v1/ai/briefings/{date}` |

No agent re-derives the fact on any of the six panels — every one of them either queries `ai_risk_flags`/`ai_approval_requests` directly or reads a row that itself cites `ai_risk_flags.id: 55214`. When Khalid Marafie calls Al-Fajr's registered number at 09:40, confirms the IBAN change was genuine (the vendor moved banks), and releases the hold, exactly one write happens — `ai_approval_requests.status` moves from `held` to `approved`, `ai_risk_flags.status` moves to `resolved` with a `resolution_note` — and all six panels reflect the resolution on their next read, because all six were only ever looking at the same two rows.

## Al-Noor's approval policy, by the numbers

Approval Center described the mechanism; here is the actual policy table `APPROVAL_ASSISTANT` reads to decide how many `ai_approval_steps` a given request gets, configured by Fahad and Mariam when the company onboarded:

| Action | Amount band | Chain |
|---|---|---|
| `bank.transfer` | KWD 0 – 10,000 | Senior Accountant only (1 step) |
| `bank.transfer` | KWD 10,000 – 50,000 | Senior Accountant → CFO (2 steps) |
| `bank.transfer` | KWD 50,000+ | Senior Accountant → CFO → Owner (3 steps) |
| `payroll.approve` | any amount | Finance Manager → CFO → Owner (3 steps, always — payroll release is categorically gated, never amount-banded) |
| `tax.submit` | any amount | CFO → Owner (2 steps) |
| `inventory.adjust` (PO issuance above auto-issue threshold) | KWD 2,000+ | Inventory Manager review → Owner (2 steps) |

The July vendor payment run (KWD 24,180.000) fell in the second `bank.transfer` band, which is why its Approval Center example earlier in this document showed exactly two steps. Payroll release does not follow the amount table at all — it is the one action in Al-Noor's configuration that is always three steps regardless of size, because this platform's own Fixed Platform Facts treat payroll release as maximally sensitive on principle, not on cost.

**Worked example — the July payroll run, three steps:**

```json
{
  "widget_id": "approval_center_queue",
  "company_id": 4821,
  "data": {
    "id": 77340, "subject_type": "payroll_release", "title": "July 2026 payroll release — 47 employees, 2 jurisdictions",
    "amount": 58248.920, "currency_code": "KWD", "status": "in_review", "required_permission": "payroll.approve",
    "source_agent_code": "PAYROLL_MANAGER", "sla_due_at": "2026-07-26T17:00:00+03:00",
    "steps": [
      { "step_order": 1, "approver_role": "Finance Manager", "approver_user_id": 160, "status": "approved", "decided_at": "2026-07-25T09:10:00+03:00", "comment": "Variance vs. June run: +2.1%, within the 5% auto-clear band. No anomalies." },
      { "step_order": 2, "approver_role": "CFO", "approver_user_id": 118, "status": "approved", "decided_at": "2026-07-25T14:45:00+03:00", "comment": "Confirmed against the cash forecast — this is the outflow already modeled in the Jul 29 trough." },
      { "step_order": 3, "approver_role": "Owner", "approver_user_id": 101, "status": "pending" }
    ]
  },
  "sources": [ { "type": "payroll_runs", "id": 4410, "label": "July 2026 payroll run, draft" } ]
}
```

`58,248.920` is the sum of the two jurisdictions already established in Payroll Alerts — KWD 46,200.000 in Kuwait plus SAR 98,600.000 in Riyadh converted at the company's maintained rate of 0.12220 (KWD 12,048.920) — so the amount on this card traces back, again, to the exact same numbers the Payroll Alerts panel already showed, not a separately-maintained total.

## Exporting the Command Center

A board pack does not want a live dashboard; it wants a fixed PDF for a specific date. **Export** (`reports.export`) on any panel, or on the whole screen from the layout toolbar, creates a `report_runs` row against a `report_definitions` entry of `type = 'command_center_snapshot'`, rendering every visible widget's current Command Card — narrative, table, and chart — into a single paginated PDF stored in Cloudflare R2 and linked back to the requesting user's `notifications`. Because the snapshot is a `report_runs` row like any other scheduled report, it inherits the Reports module's existing distribution list and retention policy rather than QAYD inventing a second export pipeline specific to this one screen.

# User Journeys

Four people, one company, one morning. Each journey below only uses panels, permissions, and data already established earlier in this document — nothing here is a new fact, only a new vantage point on facts already on the page.

## §1 — Fahad Al-Ostath, Owner: the ninety-second commute

Fahad has not opened a spreadsheet before 08:00 in three years of running Al-Noor Trading, and the Command Center is built around that fact rather than fighting it. At 06:52, three minutes into the drive from Salwa to the Kuwait City HQ, he holds the button on his phone's dashboard mount and asks the question this document already recorded verbatim in Voice Assistant: *"شحال الكاش عندي اليوم؟"* The answer plays back in nine seconds, in the voice his `ai_dashboard_layouts.briefing_voice` has stored for him — cash today, and the Jul 29 dip, with no screen involved. At the next light he asks a follow-up — "and last month?" — which the orchestrator resolves using the same cash-flow context already loaded in the session rather than treating it as a fresh, contextless query.

He is at his desk by 07:10. At 07:12 — the exact instant the bootstrap payload above captures — Urgent Actions is the first thing he sees, not because he chose that layout but because it is pinned above every saved preference on every device, mobile or desktop. He does not act on the Al-Fajr hold himself (that is Khalid's job, per the resolution path Fraud Alerts specifies); he scrolls past it to AI Recommendations, where Reem's revised, two-vendor version of the Steel Rebar reorder (see §4 below) is waiting with `target_permission: inventory.adjust` — a permission only he, Reem, and Mariam hold. He taps **Send for approval**, which under Al-Noor's policy table needs only his signature after Reem's own review is attached as the step-one comment. Both purchase orders — 40 tons to Gulf Steel Industries, 20 tons to Kuwait Metal Traders Co. — clear at 07:14.

He checks Business Health Score once (78, +4, comfortably green) and does not open Cash Flow Status at all — Mariam owns that conversation, and the Morning Briefing already told him the one fact he needed from it. Door to desk-clear: nineteen minutes, most of it spent driving, none of it spent building a number from scratch.

## §2 — Mariam Al-Sabah, CFO: cash, forecast, and the Salmiya question

Mariam's default layout, shown earlier in Customization, leads with Cash Flow Status and Financial Forecast rather than the Morning Briefing Fahad's layout opens with — she has already absorbed the briefing by the time she is at her own desk, and her first movement is always into the numbers underneath it. At 10:15 she opens the Jul 29 trough, and — beyond the three scenarios Simulation Panel already documents — runs a fourth of her own: a KWD 15,000 short-term draw on Al-Noor's overdraft facility, timed to the trough and repaid within two weeks.

```json
{
  "widget_id": "simulation_panel",
  "company_id": 4821,
  "agent_code": "FORECAST_AGENT",
  "data": {
    "scenarios": [
      { "name": "overdraft_15k_2w",
        "assumptions": [ { "type": "draw_facility", "amount_kwd": 15000.000, "start": "2026-07-28", "repay_by": "2026-08-11", "facility_rate_pct_annual": 7.0 } ],
        "trough_amount_kwd": 33900.000, "trough_date": "2026-07-29",
        "day_30_cash": 27355.000, "day_90_cash": 44055.000, "health_score": 77 }
    ]
  },
  "confidence_score": 79.0,
  "sources": [ { "type": "ai_decisions", "id": 881640, "label": "Baseline 13-week forecast" } ]
}
```

The draw lifts the Jul 29 trough from KWD 18,900.000 to KWD 33,900.000 — comfortably clear of the KWD 20,000 amber floor — at a cost of roughly KWD 45 in facility interest over two weeks, which is why day-30 and day-90 cash land KWD 45 below baseline rather than unchanged, and why the Health Score ticks down one point on the Profitability component rather than staying flat. She decides against it: the trough already sits above the KWD 10,000 red floor without financing, and a facility draw for a gap this small would spend a real cost solving a problem the existing buffer already covers. This decision is not recorded as a rejected recommendation — the scenario was never a recommendation, only a question she asked and answered, exactly the distinction Simulation Panel draws against Financial Forecast and AI Recommendations.

She re-reads the Salmiya decision brief already on file, agrees with `CEO_ASSISTANT`'s recommendation to hold, and does not request a new one — Decision Support is built for exactly this, a document she can return to without re-asking the question. At 11:20 she clears her Approval Center step on the vendor payment run (77302, step 2 of 2) and, later that afternoon once Khalid's step lands, her step on the payroll release (77340, step 2 of 3) — leaving Fahad's Owner step as the only one open going into evening, consistent with the three-step chain Examples establishes above.

## §3 — Dana Al-Rashidi, External Auditor: a time-boxed, read-only two weeks

Dana's engagement runs 2026-07-14 through 2026-07-28 — Al-Noor's annual external review. Her `company_users` grant is provisioned with an end date enforced by the platform's foundation access layer; the Command Center's own contribution is narrower and more interesting: there is no separate "auditor mode" anywhere in its code. Every panel she can open renders its ordinary, permission-filtered projection, because her role — **External Auditor**, one of the default roles this platform's permission conventions already name — simply holds a narrower set of keys than anyone else in this document:

| Permission key | Held by Dana | Effect on her Command Center |
|---|---|---|
| `reports.read` | Yes | Full read access to Insights, Recommendations, Risks, Trends, KPIs, Forecast |
| `tax.read`, `payroll.read`, `inventory.read` | Yes | Full read access to the three category-specific alert panels |
| `ai.chat` | Yes | Full use of Ask AI and Voice Assistant for analytical questions |
| `ai.approve` | No | Approval Center renders every card, every step, every decision timestamp — with no Approve/Reject/Delegate control anywhere on the screen |
| `bank.transfer`, `payroll.approve`, `tax.submit`, `inventory.adjust` | No | The corresponding "Do it" buttons on AI Recommendations never render for her at all, not merely disabled |
| `ai.automation` | No | Automation Center is visible, read-only — she can see the override-rate sparklines that matter to her control-testing but cannot flip a rule |

Her actual audit procedure, not a hypothetical one: she uses Ask AI to ask "show me every fraud alert resolved in the last 90 days and how long each stayed open," which the orchestrator resolves as an NLQ-shaped query against `ai_risk_flags` filtered to `category = 'fraud' AND status = 'resolved'`, returning a table rather than a narrative because the question is structurally a report, not a conversation. For the Al-Fajr hold specifically, she cross-references its `resolution_note` (Khalid's account of the verbal callback) against `audit_logs` for a matching entry at the claimed time, confirming the control was actually performed and not merely documented after the fact — the same reconciliation a human auditor would run against a paper file, except every source she needs is one query away instead of a week of document requests. Every question Dana asks is itself logged to `ai_conversations` under her own `user_id`, visible afterward to Mariam or Yousef exactly as any other user's chat history would be — the audit trail is bidirectional, not a one-way mirror.

## §4 — Ahmad Kanaan and Reem Boushehri: closing two loops before lunch

Ahmad Kanaan, Payroll Officer, opens Payroll Alerts at 08:00 — before Approval Center, which is not his to act on until the run reaches its own step. Bilal Youssef's missing PIFSS civil ID is the one open item; Ahmad has it in hand from HR that morning, and **Resolve now** deep-links him straight to the employee record's statutory-ID field rather than a generic edit screen. He enters it at 08:04. Three things happen without Ahmad touching anything else: `ai_risk_flags` 55215 moves to `resolved`, the corresponding line disappears from Today's Tasks for everyone it was assigned to, and it will not reappear in tomorrow's `ai_briefings` Top Risks the way it appeared in today's — eleven days ahead of the actual WPS submission deadline, resolved once, propagating everywhere it was ever shown.

Reem Boushehri holds the human "Inventory Manager" role — distinct from the `INVENTORY_MANAGER` agent, whose recommendation she is here to review, not rubber-stamp. She opens Inventory Alerts alongside Supplier Risks and sees the tension the two panels do not resolve for her: the agent's 60-ton Steel Rebar reorder is entirely correct on inventory grounds and would make an already-flagged concentration problem worse on supplier grounds. She edits the draft PO before it goes anywhere near Approval Center — 40 tons from Gulf Steel Industries (KWD 17,600.000, holding the existing contracted rate) and 20 tons from Kuwait Metal Traders Co., a newly qualified second source, at KWD 460/ton (KWD 9,200.000) — a combined KWD 26,800.000 against the agent's single-vendor KWD 26,400.000, a KWD 400.000 premium she accepts deliberately in exchange for taking Gulf Steel Industries' COGS concentration from 34% back toward the 25% policy floor over the next quarter. Her comment ("holding rate on the incumbent, qualifying a second source per the concentration flag") is what Fahad reads as the step-one context when he approves both POs together in §1 above.

She does not stop at fixing this one instance. From Automation Center she configures a new rule — `rule_key: 'flag_reorder_vendor_concentration_overlap'`, `agent_code: INVENTORY_MANAGER`, `autonomy: suggest_only` — that cross-checks every future reorder recommendation's vendor against open Supplier Risks flags before it ever reaches AI Recommendations, so the next version of this tension surfaces on its own rather than depending on Reem noticing it by reading two panels side by side. This is Customization and Automation Center doing exactly what they are for: not just configuring how loud the Command Center is, but teaching it what to look for next.

# Future Ideas

Everything above this line is the Command Center as specified for build. Everything below is deliberately out of scope for v1 — named here so the roadmap is explicit rather than implied, and so each idea's actual precondition is visible rather than hand-waved.

**Proactive vendor renegotiation, inside a pre-approved envelope.** Today, `TREASURY_MANAGER` observes Gulf Steel Industries' price creep (Supplier Risks) and stops at reporting it. A future version would let a company pre-authorize a bounded negotiation mandate — "counter-offer up to 4% below any renewal quote from a vendor at more than 25% concentration" — and have the agent draft and send the counter-offer itself, still never binding the company to anything; acceptance of whatever the vendor sends back remains a human action through the existing Approval Center. The precondition is a vendor-communication channel the platform can act through on the company's behalf (email or portal integration) with its own audit trail, which does not exist yet.

**Predictive working-capital financing.** `FORECAST_AGENT` already knows, mechanically, that Al-Noor's cash troughs recur every payroll-and-tax overlap. A future version would recognize the recurring shape itself (not just this one instance) and surface a Decision Support brief comparing a standing revolving facility against Mariam's ad-hoc overdraft draws over a full fiscal year — ideally quoting real, current rates from more than one GCC bank via an open-banking integration, rather than the single configured `facility_rate_pct_annual` the Simulation Panel example above uses today. This depends on banking-side API access the platform does not have contracted yet in any Gulf market.

**Cross-tenant benchmarking, held to the same isolation standard as everything else in this document.** "Your gross margin ranks in the 61st percentile among comparable Kuwait trading companies" is a genuinely useful line the Business Health Score could one day show. It will only ship once the underlying computation is provably incapable of leaking one tenant's data to another: a separate, offline aggregation job — never the per-request FastAPI path this document specifies — computing differential-privacy-bounded percentile bands from opted-in companies only, publishing nothing more granular than a bucket no single company could be re-identified from. `ai_memory`'s per-company isolation, restated in this document's Purpose section as non-negotiable, is not weakened for this feature; the feature is designed around it or it does not ship.

**WhatsApp delivery for Urgent Actions and the Morning Briefing.** Gulf-market usage patterns favor WhatsApp over email or push notification for anything genuinely time-sensitive. A future integration would let `ai_briefings` and the Urgent Actions union push through WhatsApp Business alongside the existing APNs/FCM path, with the same read-receipt discipline `ai_briefings.read_at` already tracks for the in-app version. The precondition is a WhatsApp Business API relationship and a decision on how a two-way reply ("approve the payment run") maps back into `ai_approval_requests` without weakening the biometric-confirmation standard Mobile Experience already sets for sensitive approvals.

**Regulatory-change auto-adaptation.** `COMPLIANCE_AGENT` currently verifies a `TAX_ADVISOR` draft against filing rules assumed static within a filing period. GCC VAT and Zakat rules do change — rate adjustments, new exemption categories, revised reporting schedules. A future version would have `COMPLIANCE_AGENT` monitor the relevant tax authorities' published rule changes directly, and instead of silently updating `tax_codes`/`tax_rates`, generate a Decision Support-style brief naming every historical transaction the change would affect and the exact `tax_returns` it would touch if applied retroactively — leaving the decision to apply it, and from what date, explicitly human.

**A boardroom-scale rendering of Decision Support.** The PDF export in Examples solves "hand someone a document." It does not solve "eight board members looking at the same brief at the same time, asking it follow-up questions live." A large-display or shared-session mode — one person driving Ask AI against a Decision Support brief while a room watches the citations expand in real time — is a rendering problem more than a data problem, and depends on nothing this document doesn't already define; it is future work only because it is a UI investment not yet prioritized against everything else in this backlog.

**Embedded execution of approved bank actions.** Approval Center today ends every approved `bank.transfer` at Laravel's own transfer initiation, which still hands off to the bank's own channel (a file, a portal, a SWIFT message) outside the platform. Open-banking connectivity with Al-Noor's actual Kuwaiti and Saudi banks would let an *already-approved* transfer execute without a context switch — the approval chain, the confidence scoring, and the fraud-hold safety brake are unchanged; only the last step (leaving the platform to actually move money) collapses. This is explicitly not a loosening of the human-gate rule — the gate still sits before initiation, not after.

**Systematic early-payment-discount capture.** The 2/10-net-30 kind of arithmetic `CFO_AGENT` already applies on request could run continuously across every vendor bill Al-Noor receives, surfacing "take this discount" as a standing AI Recommendation the moment a qualifying bill posts, rather than requiring a human to notice the terms and ask for the math. The precondition is purely a monitoring job, not a new capability — every piece of this (bill terms, the annualized-cost formula, the recommendation-with-alternatives shape) already exists elsewhere in this document; it is future work because it has not yet been wired to run unprompted.

**ESG and carbon reporting for Gulf sustainability disclosure requirements.** As Saudi and UAE disclosure regimes mature, a `sustainability_metrics` layer (fuel and logistics carbon estimated from the same `bills`/`stock_movements` data already feeding Expense Trends and Inventory Alerts) would let `REPORTING_AGENT` add an ESG panel without a new data source — the inputs are already posted. This is future work because the regulatory requirement Al-Noor would need to comply with does not yet apply to a company of its size in its jurisdictions.

**Federated fraud-pattern learning across tenants, without centralizing tenant data.** `FRAUD_DETECTION`'s bank-detail-change pattern was effective the first time the platform ever saw it, at any company, only because it was hand-specified. A future version would let the *pattern-recognition model* improve from every tenant's fraud outcomes in aggregate — federated or model-only updates, never raw `vendor_bank_accounts` or `bills` rows leaving a company's boundary — so a fraud pattern novel to Al-Noor but already seen at another customer is caught sooner. Like cross-tenant benchmarking above, this ships only once the isolation guarantee is proven, not merely asserted.

**A supervised autonomy ladder, made visible as a single number.** Automation Center today shows each rule's own autonomy and override rate individually. A future "Autonomy Index" would roll every rule's autonomy level and trust record into one company-level trend — the same kind of single defensible number Business Health Score already models — so an owner can watch, quarter over quarter, how much of the routine work at their company has graduated from suggest-only to auto, and treat that trend itself as a KPI of how well the supervised-workforce model is working for them.

# End of Document
