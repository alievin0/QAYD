# Business Memory — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: BUSINESS_MEMORY
---

# Purpose

Every agent in QAYD's fifteen-agent roster can read a ledger balance, cite a posted journal line, or quote a tax rule. None of that tells an agent whether a number is *normal for this company*. `AI_FINANCE_OS.md` states the platform-wide fact that every document in this set inherits without exception — "AI memory is per-company and never crosses tenants" — and names the substrate that makes an agent's output specific to one company rather than generic accounting knowledge: **Company Memory**, the `ai_memory` table. `ACCOUNTING_MEMORY.md` specifies the narrowest, highest-volume slice of that substrate — which General Ledger account a specific vendor's bill maps to. Business Memory is the slice one level up: not a bookkeeping rule, but an understanding of the company itself — its industry, its business model, the seasonal rhythm its revenue actually follows, what its revenue and expense normally look like, how specific customers and vendors behave over time, which KPIs it has decided matter and at what target, where it says it is headed, and what concentration or exposure risk it carries. This is precisely the knowledge the **CFO Agent**, the **Forecast Agent**, and the **CEO Assistant** cannot do their jobs without, and precisely the knowledge a ledger, by itself, cannot supply: a General Ledger records what happened; it has no column for what *should* have happened, or for what happening again next June would mean.

Business Memory is not a new, parallel memory system. This document does not redefine `ai_memory` — the table is reused **verbatim** from `AI_FINANCE_OS.md` § Company Memory, exactly as written there, with one additive migration (a widened `memory_type` CHECK constraint — never a new column, never a redefined one) and no other structural change. Where this document's brief says "business-scoped `ai_memory`," that means precisely the rows on that one shared table whose `memory_type` falls in the ten categories this document owns (§ What Is Stored). This document's only owned tables are two purpose-built evidentiary stores that most business-scoped `ai_memory` rows cite as their supporting computation: `business_baselines`, the deterministic numeric substrate behind every revenue baseline, expense baseline, and KPI actual; and `business_seasonality`, the cycle-by-cycle observation log behind every seasonal pattern claim. Neither table is ever written by a language model — both are the plain output of scheduled Laravel aggregation jobs reading already-posted data, exactly as disciplined as the General Ledger itself.

The distinction between this document and its closest siblings is worth stating once, plainly, because a reader who has already read `ACCOUNTING_MEMORY.md` will otherwise wonder why a second memory document exists at all:

| | Company Memory (general `ai_memory` rows) | Accounting Memory | Business Memory (this document) |
|---|---|---|---|
| Answers | "What does this company prefer or require, operationally" | "Which account does this exact vendor/pattern map to, right now" | "What kind of business is this, and what does normal look like for it" |
| Grain | `policy` / `preference` / `approval_chain` / `faq` / `employee_context` | a per-vendor/customer categorization mapping | industry / business model / seasonality / baseline / behavior / KPI / trajectory / risk |
| Primary consumers | every agent, day to day | General Accountant | CFO Agent, Forecast Agent, CEO Assistant, AI Command Center |
| Evidentiary companion table | none (or `ai_categorization_rules` for Accounting Memory) | `ai_categorization_rules` | `business_baselines`, `business_seasonality` |
| Grain size | a sentence | a mapping tuple | a company-wide profile fact, or a per-customer/vendor behavioral note |

Two properties define Business Memory and separate it from anything the ledger, a report, or a forecast already computes for itself. First, **it originates no primary financial data.** A `revenue_baseline` row never substitutes for the General Ledger — it interprets what the ledger already shows against a company-specific expectation the ledger has no way of encoding on its own. If a Business Memory row and the ledger ever disagree about a fact the ledger owns — an amount, a balance, a posted date — the ledger wins without exception, and the disagreement itself becomes a signal that the memory row needs review, never grounds to override the primary record (§ Edge Cases). Second, **everything it asserts is falsifiable and versioned, never a silent permanent belief.** A `seasonality_pattern` row that stops matching reality is contradicted by the same evidence mechanism that keeps the Forecast Agent's own confidence honest, and a contradicted row loses confidence and is eventually superseded — never left `active` at a number nobody still believes.

Accounting becomes supervised, not manual, at the level of business understanding exactly as it does at the bookkeeping and executive layers described elsewhere in this platform: instead of a new CFO spending two months learning "how this company is different" from the last one, the CFO Agent already knows, from the first liquidity check after onboarding, that Al-Noor Trading & Contracting W.L.L.'s summer runs materially below its annual average for a specific, cited reason, that Al Bairaq Retail Group is a receivables risk worth watching, and that Gulf Prime Distribution Co. has an eleven-month record of on-time, dispute-free delivery. The rest of this document specifies exactly what is stored (§ What Is Stored); its PostgreSQL and pgvector schema (§ Data Model); how tenant isolation is enforced (§ Per-Company Isolation); how an agent retrieves the right fact fast enough to matter inside a live conversation or scheduled briefing (§ Retrieval); how a pattern, once confirmed, is promoted into durable memory (§ The Learning Loop); who may write what, under which permission (§ Write Path & Governance); what must never be stored and why (§ Privacy & PII); how long a row lives (§ Retention); three fully worked scenarios threading through Al-Noor Trading (§ Worked Examples); the metrics that judge whether this subsystem works (§ Metrics); its failure modes (§ Edge Cases); and where it goes next (§ Future Improvements).

# What Is Stored

Business Memory captures ten categories of company understanding. Two reuse `memory_type` values `AI_FINANCE_OS.md` already defines (`vendor_note`, `customer_note`), because a vendor's or customer's behavioral profile is exactly what those two values were always meant to hold, regardless of which agent authors a given row. Eight have no honest home in the base table's eight-value enum and are added by this document's one additive migration (§ Data Model).

| # | Category | `ai_memory.memory_type` | New/Reused | Typical `subject_type` | Example (Al-Noor Trading & Contracting W.L.L., `company_id: 4821`) |
|---|---|---|---|---|---|
| 1 | Industry classification | `industry_profile` | New | `NULL` (company-wide) | "Building-materials wholesale trading (ISIC 4663) with an integrated light-contracting arm (ISIC 4390)." |
| 2 | Business model | `business_model` | New | `NULL` | "B2B trade-credit sales, 30–60 day terms, plus project-based contracting revenue recognized over the contract term; inventory-heavy, import-dependent." |
| 3 | Seasonality pattern | `seasonality_pattern` | New | `NULL`, or `branches` | "Revenue runs approximately 28% below the trailing-12-month average every June–August, tied to Kuwait's statutory outdoor midday work ban on the contracting side of the business." |
| 4 | Revenue baseline | `revenue_baseline` | New | `NULL`, or `branches` | "Trailing-12-month average monthly revenue: KWD 150,000.00, drawn from `business_baselines`." |
| 5 | Expense baseline | `expense_baseline` | New | `NULL`, or `accounts`/`departments` | "Payroll expense has held at 34–36% of revenue for six consecutive quarters." |
| 6 | Customer behavior | `customer_note` | Reused | `customers` | "Al Bairaq Retail Group has averaged 47 days against 30-day terms over the last two quarters — a receivables risk, not yet a default risk." |
| 7 | Vendor behavior | `vendor_note` | Reused | `vendors` | "Gulf Prime Distribution Co.: 47 consecutive bills over the trailing 12 months, zero pricing disputes, 100% on-time delivery — a low-risk, high-reliability supplier." |
| 8 | KPI definition & target | `kpi_definition` | New | `NULL`, or `departments`/`branches` | "This company tracks current ratio (floor 1.50) and DSO (ceiling 40 days) as its two board-level liquidity KPIs." |
| 9 | Growth trajectory | `growth_trajectory` | New | `NULL` | "Evaluating a Dammam, Saudi Arabia branch: setup cost KWD 240,000, 6-month ramp at KWD 35,000/month run-rate, funded from internal cash." |
| 10 | Risk profile | `risk_profile` | New | `NULL`, or `customers`/`vendors` | "Top-3 customers represent 41% of trailing-12-month revenue; Al Bairaq Retail Group alone is 19%, above this company's own 15% single-customer concentration comfort threshold." |

Row 6 and row 7 deserve one clarifying note, because `vendor_note`/`customer_note` are shared address space with plain Company Memory, not a type this document owns exclusively. A Company Memory row about Gulf Prime Distribution Co. might state a *preference* ("the Owner decided in mid-2026 to stop taking this vendor's early-payment discount") — a single decision, captured once, from a conversation. A Business Memory row about the same vendor states a *behavioral pattern distilled from many transactions* ("47 consecutive on-time, dispute-free bills"). Both are legitimate `vendor_note` rows on the same physical table, disambiguated by `content`, by the shape of `structured_value`, and by which agent authored the row — never by a second `memory_type` value, which would fragment one coherent concept across two enums for no operational benefit.

The boundary that keeps this list from sprawling into duplicating other tables is simple: Business Memory stores **understanding distilled from primary data, never the primary data itself.** It does not store Al Bairaq Retail Group's invoices (`invoices`, owned by Sales) or its outstanding balance (derived live from `journal_lines`). It stores the AI's distilled, citable read of that customer's *behavior* — a fact no single invoice carries alone, only a pattern across many of them, computed once and remembered rather than recomputed from scratch by every agent that asks. The same discipline governs KPI rows: a `kpi_definition` row never recomputes a ratio — that arithmetic belongs to the CFO Agent, which reads the actual current-ratio observation from `business_baselines` and compares it against the *threshold* this row states. Business Memory answers "what matters, and what does normal look like"; it is never the arithmetic engine itself.

# Data Model (PostgreSQL + vector/embedding schema DDL)

Business Memory is built on three tables: `ai_memory`, reused **verbatim** from `AI_FINANCE_OS.md` § Company Memory with exactly one additive migration owned by this document; `ai_corrections`, reused verbatim and touched only as a Learning Loop input, not modified; and two new tables introduced and owned outright by this document.

```sql
-- ============================================================================
-- ai_memory — defined once, in AI_FINANCE_OS.md § Company Memory.
-- Reproduced verbatim below — no column added, removed, renamed, or
-- reinterpreted. Business Memory is the set of rows on this table whose
-- memory_type is one of the ten values named in # What Is Stored.
-- ============================================================================
CREATE TABLE ai_memory (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    memory_type      VARCHAR(30) NOT NULL
        CHECK (memory_type IN ('policy','preference','approval_chain','vendor_note',
                                'customer_note','correction','faq','employee_context')),
    subject_type     VARCHAR(60) NULL,           -- polymorphic: 'vendors','customers','accounts', NULL for company-wide
    subject_id       BIGINT NULL,
    content          TEXT NOT NULL,               -- human-readable statement of the memory
    embedding        VECTOR(1536) NOT NULL,        -- pgvector embedding of `content` for semantic retrieval
    structured_value JSONB NULL,                   -- machine-usable form, e.g. {"account_code":"5210"}
    confidence       NUMERIC(5,4) NOT NULL DEFAULT 1.0,
    source_type      VARCHAR(30) NOT NULL
        CHECK (source_type IN ('explicit_config','inferred_pattern','correction','conversation')),
    source_ref       JSONB NULL DEFAULT '{}'::jsonb,  -- e.g. {"ai_correction_id": 881}
    usage_count      INTEGER NOT NULL DEFAULT 0,
    last_used_at     TIMESTAMPTZ NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','superseded','retracted')),
    superseded_by_id BIGINT NULL REFERENCES ai_memory(id),
    expires_at       TIMESTAMPTZ NULL,
    created_by       BIGINT NULL REFERENCES users(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at       TIMESTAMPTZ NULL
);
CREATE INDEX idx_ai_memory_company_type ON ai_memory(company_id, memory_type, status);
CREATE INDEX idx_ai_memory_subject ON ai_memory(company_id, subject_type, subject_id);
CREATE INDEX idx_ai_memory_embedding ON ai_memory USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);

ALTER TABLE ai_memory ENABLE ROW LEVEL SECURITY;
ALTER TABLE ai_memory FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_ai_memory ON ai_memory
    USING (company_id = current_setting('app.current_company_id')::bigint);
-- The policy above already governs this table platform-wide; it is not
-- introduced by this document, only restated for completeness.

-- ============================================================================
-- This document's one structural change: an additive widening of the
-- memory_type CHECK constraint. No column is added, removed, renamed, or
-- reinterpreted — only eight new values this domain's content genuinely
-- has no honest home under in the original eight.
-- ============================================================================
ALTER TABLE ai_memory DROP CONSTRAINT ai_memory_memory_type_check;
ALTER TABLE ai_memory ADD CONSTRAINT ai_memory_memory_type_check
    CHECK (memory_type IN (
        -- original eight, defined in AI_FINANCE_OS.md — unchanged in meaning or use
        'policy','preference','approval_chain','vendor_note','customer_note',
        'correction','faq','employee_context',
        -- added by this document (Business Memory)
        'industry_profile','business_model','seasonality_pattern','revenue_baseline',
        'expense_baseline','kpi_definition','growth_trajectory','risk_profile'
    ));

-- ============================================================================
-- ai_corrections — defined in AI_FINANCE_OS.md § Learning Loop. Reused
-- verbatim, unmodified. The generic ai_corrections.memory_id FK is sufficient
-- to link a correction to the ai_memory row it eventually produced.
-- ============================================================================
CREATE TABLE ai_corrections (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    decision_id       BIGINT NOT NULL REFERENCES ai_decisions(id),
    original_proposal JSONB NOT NULL,
    human_action      VARCHAR(20) NOT NULL CHECK (human_action IN ('accepted','edited','rejected')),
    final_value       JSONB NULL,
    reason_given      TEXT NULL,
    corrected_by      BIGINT NOT NULL REFERENCES users(id),
    memory_id         BIGINT NULL REFERENCES ai_memory(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_corrections_company_decision ON ai_corrections(company_id, decision_id);
```

`ai_memory` carries no unique constraint on `(company_id, memory_type, subject_type, subject_id)` in its base, verbatim form, so "one live row per pattern" is not a schema guarantee this document is permitted to add — it is a write-path discipline enforced by the Laravel service layer described in § Write Path & Governance, which checks for an existing `active` row addressing the same fact before inserting a new one, and supersedes rather than duplicates.

`ai_decisions` (defined in `AI_FINANCE_OS.md` § Decision Engine) is reused exactly as shipped and is not redefined here. Two of its properties matter enough to state explicitly, because this document's promotion flow leans on both: `decision_type` is a free-text `VARCHAR(60)`, not an enumerated Postgres type, so introducing `decision_type = 'business_memory_promotion'` requires no migration of any kind — the value is simply written. `status` accepts `'proposed' | 'auto_executed' | 'accepted' | 'edited_and_accepted' | 'rejected' | 'expired' | 'pending_approval' | 'approved' | 'denied'`; every Business Memory promotion decision in this document resolves a human decline to **`'rejected'`**, never `'denied'` — `'denied'` is reserved elsewhere in the platform for a formal approval chain declining a sensitive, money-moving action (a bank transfer, a payroll release), a materially different lifecycle than a human simply declining to add a business-understanding fact to memory.

```sql
-- ============================================================================
-- business_baselines — owned by this document. The deterministic, non-
-- narrative numeric substrate behind every revenue baseline, expense
-- baseline, and KPI actual. Never authored by a language model — always the
-- output of a scheduled Laravel aggregation job reading already-posted
-- ledger/CRM data. Append-only: a new period's observation is a new row,
-- never an update to a prior one. This is the evidentiary layer most
-- business-scoped ai_memory rows cite via source_ref.
-- ============================================================================
CREATE TABLE business_baselines (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    baseline_type       VARCHAR(20) NOT NULL
        CHECK (baseline_type IN ('revenue','expense','kpi','concentration','other')),
    metric_key          VARCHAR(80) NOT NULL,
        -- e.g. 'monthly_revenue_avg_trailing12', 'payroll_expense_pct_of_revenue',
        -- 'current_ratio', 'dso_days', 'top3_customer_concentration_pct'
    period_type         VARCHAR(10) NOT NULL CHECK (period_type IN ('month','quarter','year')),
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    value               NUMERIC(19,4) NOT NULL,
    unit                VARCHAR(20) NOT NULL
        CHECK (unit IN ('KWD','SAR','AED','USD','percent','days','ratio','count')),
    prior_period_value  NUMERIC(19,4) NULL,
    yoy_value           NUMERIC(19,4) NULL,
    trend_direction     VARCHAR(10) NULL CHECK (trend_direction IN ('up','down','flat')),
    sample_size         INTEGER NOT NULL DEFAULT 1,     -- independent periods this figure is built from
    computation_method  VARCHAR(30) NOT NULL
        CHECK (computation_method IN ('sql_aggregate','statistical_model','forecast_agent_import')),
    source_query_ref    JSONB NOT NULL DEFAULT '{}'::jsonb,  -- e.g. {"gl_accounts":[4000,4010],"fiscal_period_id":3390}
    ai_memory_id        BIGINT NULL REFERENCES ai_memory(id),  -- set once a candidate/active memory row cites this row
    computed_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_business_baselines
    ON business_baselines (company_id, COALESCE(branch_id, 0), metric_key, period_type, period_start);
CREATE INDEX idx_business_baselines_lookup
    ON business_baselines (company_id, metric_key, period_start DESC);

ALTER TABLE business_baselines ENABLE ROW LEVEL SECURITY;
ALTER TABLE business_baselines FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_business_baselines ON business_baselines
    USING (company_id = current_setting('app.current_company_id')::bigint);

-- ============================================================================
-- business_seasonality — owned by this document. One row per OBSERVED CYCLE
-- of a recurring pattern (one row for "summer 2025," a second row for
-- "summer 2026," ...), never a mutable running counter. Aggregates like
-- "how many cycles have confirmed this pattern" are computed by querying
-- across rows sharing the same pattern_key (see # The Learning Loop), not
-- stored as state on any single row — the same append-only, never-hand-
-- edited discipline business_baselines applies.
-- ============================================================================
CREATE TABLE business_seasonality (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    pattern_key       VARCHAR(80) NOT NULL,       -- e.g. 'summer_slowdown','ramadan_dip','q4_inventory_build'
    metric_key        VARCHAR(80) NOT NULL,       -- the business_baselines.metric_key this cycle deviates from
    cycle_basis       VARCHAR(20) NOT NULL
        CHECK (cycle_basis IN ('gregorian_month_range','hijri_month','fiscal_quarter','custom_window')),
    window_label      VARCHAR(60) NOT NULL,       -- e.g. 'Jun-Aug', 'Ramadan', 'Q4'
    cycle_start_date  DATE NOT NULL,               -- this specific occurrence
    cycle_end_date    DATE NOT NULL,
    baseline_value    NUMERIC(19,4) NOT NULL,      -- the trailing baseline in force at the time of this cycle
    observed_value    NUMERIC(19,4) NOT NULL,
    seasonal_index    NUMERIC(6,3) NOT NULL,       -- observed_value / baseline_value; 1.000 = no deviation
    tolerance_pct     NUMERIC(5,2) NOT NULL DEFAULT 10.00,
    within_tolerance  BOOLEAN NOT NULL,            -- computed at write time against the pattern's prior confirmed index
    is_anomalous      BOOLEAN NOT NULL DEFAULT false,  -- human-flagged; excludes this cycle from the confirm/contradict count
    driver_note       TEXT NULL,                   -- cited regulatory/cultural/operational driver
    ai_memory_id      BIGINT NULL REFERENCES ai_memory(id),
    source_query_ref  JSONB NOT NULL DEFAULT '{}'::jsonb,
    computed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_business_seasonality
    ON business_seasonality (company_id, COALESCE(branch_id, 0), pattern_key, cycle_start_date);
CREATE INDEX idx_business_seasonality_lookup
    ON business_seasonality (company_id, pattern_key, cycle_start_date DESC);

ALTER TABLE business_seasonality ENABLE ROW LEVEL SECURITY;
ALTER TABLE business_seasonality FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_business_seasonality ON business_seasonality
    USING (company_id = current_setting('app.current_company_id')::bigint);
```

`business_baselines.branch_id` and `business_seasonality.branch_id` are nullable and meaningful even though the base `ai_memory` table carries no `branch_id` column of its own: Al-Noor Trading & Contracting W.L.L. (`company_id: 4821`) needs a Kuwait-HQ-scoped revenue baseline distinct from its existing Riyadh sales office's (`branch_id: 3`) SAR-denominated one, and both new tables, being wholly owned by this document, are free to add the dimension the base table's original author did not need. Where a metric is genuinely company-wide, `branch_id` is `NULL`, and `COALESCE(branch_id, 0)` in each unique index ensures a company-wide row and a branch-specific row for the same `metric_key`/period never collide, and that two company-wide rows for the same period cannot silently duplicate — a bare `UNIQUE` without the `COALESCE` would not catch that, since SQL evaluates `NULL <> NULL`.

# Per-Company Isolation

`ai_memory.company_id`, `business_baselines.company_id`, and `business_seasonality.company_id` are all `NOT NULL`, indexed first in every composite index above, and enforced by Row Level Security identically to every other tenant table in the platform — this is not a convention the AI engine chooses to respect, it is a boundary the database enforces regardless of what the querying process asks for. The two new tables' RLS policies, shown inline above, are declared `FORCE`d, and both are queried through the same RLS-scoped connection as every other read; there is no separate, unscoped index partition for the `ivfflat` vector index on `ai_memory.embedding` that a business-scope query could reach across from.

Three enforcement layers back this guarantee, matching the discipline every other AI-layer table in this platform applies: **(1)** the RLS policy itself, `FORCE`d so even the table-owner role cannot bypass it; **(2)** network isolation — the FastAPI subnet has no direct route to PostgreSQL for tenant tables, so a retrieval request is always mediated by a Laravel-issued, company-scoped bearer token that sets `app.current_company_id` for the session before any query runs; and **(3)** a runtime assertion in the FastAPI orchestration layer that rejects any retrieved row whose `company_id` does not match the active task's `company_id`, defense-in-depth against a hypothetical RLS misconfiguration or a connection-pool bug.

`subject_type`/`subject_id` on `ai_memory` carry their own, additional gate: a `customer_note` row scoped to `subject_id` = Al Bairaq Retail Group's record is further gated by whichever permission covers reading that specific customer (`customers.read`) on top of `ai.memory.read`, so a Sales Employee who cannot see Al Bairaq Retail Group's own record cannot retrieve a Business Memory note about it either, even though both rows sit in the same physical table as every other business-scope memory the company owns. Any future cross-company benchmarking capability (§ Future Improvements) is out of scope for `ai_memory`, `business_baselines`, and `business_seasonality` entirely, and would require a wholly separate, explicitly aggregated, anonymized, opt-in pipeline that never queries these tables directly — never a relaxed RLS predicate on a tenant table itself.

# Retrieval (RAG / semantic + structured)

An agent assembling Business Memory context — the CFO Agent drafting a variance narrative, the Forecast Agent conditioning a projection on a seasonality prior, the CEO Assistant answering "how does this compare to a normal summer for us," the AI Command Center rendering the Business Health Score's risk components — runs two retrieval passes in parallel and merges the results, the same hybrid-retrieval discipline every sibling memory document in this platform specifies for its own domain.

**Pass 1 — structured, exact lookup.** Cheap, exact, and always run regardless of whether a semantic query is also needed, because a structured hit is frequently the entire answer (a KPI target lookup, a specific customer's behavior note):

```sql
SELECT id, memory_type, subject_type, subject_id, content, structured_value,
       confidence, source_type, source_ref, usage_count
FROM ai_memory
WHERE company_id = :company_id
  AND status = 'active'
  AND memory_type IN ('industry_profile','business_model','seasonality_pattern','revenue_baseline',
                       'expense_baseline','customer_note','vendor_note','kpi_definition',
                       'growth_trajectory','risk_profile')
  AND (subject_type IS NULL OR (subject_type = :subject_type AND subject_id = :subject_id))
ORDER BY confidence DESC, last_used_at DESC NULLS LAST
LIMIT 10;
```

**Pass 2 — semantic, vector similarity.** Run unconditionally, not only as a fallback for the structured pass's misses, because a structured hit can answer *which* fact applies while still missing softer context that changes how a proposal should be framed:

```sql
SELECT id, memory_type, subject_type, subject_id, content, structured_value,
       confidence, source_ref,
       1 - (embedding <=> :query_embedding) AS similarity
FROM ai_memory
WHERE company_id = :company_id
  AND status = 'active'
  AND memory_type IN ('industry_profile','business_model','seasonality_pattern','revenue_baseline',
                       'expense_baseline','customer_note','vendor_note','kpi_definition',
                       'growth_trajectory','risk_profile')
ORDER BY embedding <=> :query_embedding
LIMIT 10;
```

**Merge.** Both passes' results are deduplicated by `id`, ranked by a blend of structured priority, `confidence`, and similarity, and returned as one evidence list tagged by how each item was found — the same evidence-bundle contract every sibling memory document uses, so an agent's context-assembly code does not need a second path to consume Business Memory differently:

```json
{
  "business_memory_evidence": [
    {
      "source_kind": "structured",
      "memory_id": 91205,
      "memory_type": "seasonality_pattern",
      "content": "Revenue runs approximately 28% below the trailing-12-month average every June through August, tied to Kuwait's statutory outdoor midday work ban on the contracting side of the business.",
      "structured_value": { "index": 0.720, "cycles_confirmed": 2, "window": "Jun-Aug" },
      "confidence": 0.8300,
      "source_ref": { "business_seasonality_ids": [55001, 55014] }
    },
    {
      "source_kind": "semantic",
      "memory_id": 92010,
      "memory_type": "risk_profile",
      "content": "Top-3 customers represent 41% of trailing-12-month revenue; Al Bairaq Retail Group alone is 19%, above this company's own 15% single-customer concentration comfort threshold.",
      "confidence": 0.7600,
      "similarity": 0.88
    }
  ],
  "retrieval_latency_ms": 61
}
```

**Citation discipline.** A `seasonality_pattern` row can explain *why* a variance happened, but the variance figure itself must still cite the ledger rows or `business_baselines` row that produced it; a Business Memory row is additive context in an agent's `reasoning` field and `sources` array, never the sole source for a number a primary table already owns — Business Memory is retrievable evidence, never an invisible thumb on the scale.

**Caching.** The assembled context bundle for a company's most frequently repeated task shapes (a daily CFO liquidity check, a nightly Command Center refresh) is cached in Redis, keyed on `company_id` plus a hash of the requesting task's structured parameters, with a short TTL (default 15 minutes) and explicit invalidation on any write to that company's `ai_memory`, `business_baselines`, or `business_seasonality` rows. `usage_count`/`last_used_at` on `ai_memory` are incremented only when a retrieved row is actually cited in the resulting output, not merely fetched as a candidate, so the staleness tracking in § Retention reflects genuine influence rather than mere candidacy.

# The Learning Loop (approved corrections become memory)

Business Memory's Learning Loop descends directly from the mechanism `AI_FINANCE_OS.md` § Learning Loop states for Company Memory generally, but it operates on a coarser evidentiary unit than a single accept/edit/reject decision, because a Business Memory assertion is a claim about the business as a whole, not about one bill. Its evidentiary unit is a **confirmed cycle**: an independent period whose `business_baselines`/`business_seasonality` observation is consistent with a candidate pattern within a stated tolerance, or a human's explicit statement.

**Two distinct write mechanisms.** A confidence *update* to an existing, already-`active` row is deterministic bookkeeping arithmetic requiring no new human decision — the row is already governed, and one more confirming or contradicting cycle simply moves its confidence. A *new candidate* — a pattern with no `active` row yet — is always a first-class proposal that travels through `ai_decisions` and never becomes `active` without a human confirming it, regardless of how many cycles have already confirmed it statistically. This is deliberately more conservative than a per-transaction categorization loop: a wrong Business Memory row is not cheap to correct, because it silently reshapes how every subsequent CFO narrative, forecast, and board briefing interprets this company's numbers until someone notices.

```
 business_baselines / business_seasonality accumulates a new period's
 observation (nightly/monthly Laravel aggregation job — pure computation)
              │
              ▼
   Does an active ai_memory row already exist addressing this exact
   pattern (same memory_type + subject_type/subject_id + a matching
   key inside structured_value)?
       ┌────────────┴─────────────┐
      YES                          NO
       │                            │
       ▼                            ▼
 ┌───────────────────┐    Has this candidate now accumulated >= 2
 │ Apply the confidence │    confirming cycles (within_tolerance = true,
 │ update formula below  │    is_anomalous = false), or has a human
 │ to the existing row —  │    stated it explicitly in conversation?
 │ AUTOMATIC, no new       │       ┌────────┴─────────┐
 │ human decision needed    │      NO                  YES
 └───────────┬─────────────┘       │                   │
             │                     ▼                   ▼
             │             Retained in           Create an ai_decisions row
             │             business_baselines /   (decision_type=
             │             business_seasonality    'business_memory_promotion',
             │             only; not enough        status='pending_approval'),
             │             evidence to propose      payload = the full
             │             yet                       candidate ai_memory row
             │                                             │
             │                                             ▼
             │                                  Human with ai.memory.manage
             │                                  reviews via the approve/
             │                                  reject endpoint (Write Path)
             │                                       ┌─────┴──────┐
             │                                    APPROVE      REJECT
             │                                       │             │
             │                                       ▼             ▼
             │                            INSERT ai_memory   ai_decisions.status
             │                            row, status=        = 'rejected'; NO
             │                            'active'; the         ai_memory row is
             │                            contributing           ever created
             │                            baseline/seasonality
             │                            rows' ai_memory_id
             │                            back-filled
             └───────────────────┬─────────────────────┘
                                 ▼
                    ai_memory row now retrievable (# Retrieval);
                    the next confirming/contradicting cycle
                    re-enters this diagram at "YES, already exists"
```

**Confidence formula.** A Laplace-padded running mean, the same shape the platform's Learning Loop applies elsewhere, re-parameterized for this domain's coarser evidence unit. Let `o` (the outcome of one cycle or one human action) be `1.0` for a confirming cycle within tolerance or an explicit human confirmation, `0.5` for a cycle that is directionally right but outside the tight tolerance band, and `0.0` for a human rejection or a cycle that misses materially. Let `n` be the count of prior confirming/contradicting cycles already folded into this row. The learning rate is `α = 1 / (n + 2)`:

```
new_confidence = clamp( old_confidence + α × (o − old_confidence), 0.05, 0.95 )
where α = 1 / (n + 2)
```

Every application of this formula runs as deterministic Laravel service code, never trusted from a language model's own arithmetic. The ceiling is capped at `0.95`, deliberately lower than a narrow per-transaction rule's ceiling elsewhere in the platform: a Business Memory assertion is a broader, longer-horizon claim, and QAYD's design stance is that a strategic-level pattern should never present as functionally certain, however many cycles have confirmed it, because a business can change in ways one well-confirmed pattern cannot rule out (§ Edge Cases).

**Worked arithmetic.** A candidate `seasonality_pattern` starts, at its first confirming cycle, from the platform's standard cold-start prior of `old_confidence = 0.50`. The first confirming cycle (`n=0`, `α = 1/(0+2) = 0.50`, `o=1.0`) moves it to `0.50 + 0.50×(1.0−0.50) = 0.75`. Because the minimum-recurrence threshold requires **two** independent confirming cycles before a promotion proposal is even created, the formula's second application happens at the second cycle (`n=1`, `α = 1/(1+2) ≈ 0.333`, `o=1.0`): `0.75 + 0.333×(1.0−0.75) ≈ 0.8333`, rounding to the `0.8300` confidence the promotion decision in § Retrieval's example carries at submission.

**What contradicts a row.** A cycle that falls outside tolerance (`o=0.5`) pulls confidence down gently, reflecting that one off-pattern period is weak evidence a real pattern has broken. Three consecutive out-of-tolerance cycles automatically flip the row's `status` to `superseded` — never silently left `active` at a decayed confidence — with a system-authored superseding candidate created in its place if the new data suggests a different, more current pattern, and a notification routed to whichever role originally confirmed the superseded row.

# Write Path & Governance

No row in `ai_memory`, `business_baselines`, or `business_seasonality` is ever written by the FastAPI AI engine directly. Every write is one of three kinds: a scheduled, code-only computation with no approval gate; a deterministic confidence *update* to an already-`active` `ai_memory` row; or a human-gated *promotion* of a brand-new candidate, or a human's own direct authoring — both of which travel through the identical permissioned Laravel endpoint every other agent proposal in this platform uses.

| Write | Who/what performs it | Endpoint | Permission Key | Governance |
|---|---|---|---|---|
| Compute/refresh a `business_baselines`/`business_seasonality` row | Scheduled `ai_tasks` job, typically `agent_code='REPORTING_AGENT'` or `'FORECAST_AGENT'` | *(internal — not user-invoked)* | n/a; runs under the AI service account's own read-only-on-ledger credential | Pure SQL aggregation over posted `journal_lines`/`invoices`/`bills`/CRM tables; no narrative claim, no gate |
| Direct human authoring of a business-scope `ai_memory` row (onboarding, settings) | Owner / CEO / CFO / Finance Manager | `POST /api/v1/ai/memory` | `ai.memory.manage` | Inserted directly at `status='active'`, `confidence=1.0` — an explicitly human-stated fact warrants full confidence by construction |
| Confidence update to an existing `active` row | Internal `BusinessMemoryLearningService`, triggered by the nightly job | *(internal side effect)* | n/a; deterministic service code | Fully governed by the formula in § The Learning Loop; never an LLM judgment call |
| New candidate promotion (no `active` row yet) | Whichever agent's own workflow surfaced the signal — `FORECAST_AGENT`/`CFO_AGENT` for baseline/seasonality/KPI rows; `GENERAL_ACCOUNTANT`/`SALES_AGENT`/`PURCHASING_AGENT`/`TREASURY_MANAGER` for customer/vendor behavior; `CEO_ASSISTANT` for growth trajectory surfaced in conversation | `POST /api/v1/ai/decisions` (`decision_type='business_memory_promotion'`, `status='pending_approval'`) | the proposing agent's own existing propose-level permission | Lands as a proposal only; never writes `ai_memory` directly |
| Approve a pending promotion | Owner / CEO / CFO / Finance Manager (Senior Accountant, customer/vendor-behavior categories only) | `POST /api/v1/ai/decisions/{id}/approve` | `ai.memory.manage` | Same transaction marks the decision `approved` and inserts the new `ai_memory` row at `status='active'` |
| Reject a pending promotion | Same roles as approval | `POST /api/v1/ai/decisions/{id}/reject` | `ai.memory.manage` | Marks the decision **`'rejected'`** (never `'denied'`); no `ai_memory` row is ever created |
| Read business-scope memory | Any authorized role, additionally gated by the underlying subject's own read permission for `customer_note`/`vendor_note` rows | `GET /api/v1/ai/memory`, `GET /api/v1/ai/memory/business-baselines`, `GET /api/v1/ai/memory/business-seasonality` | `ai.memory.read` | Read-only; scoped by RLS as any other read |
| Manually edit, supersede, or retract an `active` row | Owner / CFO / Finance Manager | `PATCH /api/v1/ai/memory/{id}` | `ai.memory.manage` | Human-only; writes `audit_logs` (who, old value, new value, reason) |

`ai.memory.read` and `ai.memory.manage` are the same two keys `ACCOUNTING_MEMORY.md` establishes for this same table — not re-invented per module. What differs is *scope*, not the key string: Laravel's policy layer authorizes an `ai.memory.manage` call against the specific `memory_type`/`subject_type` of the row being touched, so a Senior Accountant's grant, scoped to Accounting Memory's own categorization rows, does not by the same grant authorize editing a `risk_profile` or `growth_trajectory` row — that requires a grant explicitly covering business-understanding categories.

| Role | `ai.memory.read` (business-scope) | `ai.memory.manage` (business-scope) | Confirm a promotion |
|---|---|---|---|
| Owner | Yes | Yes | Yes |
| CEO | Yes | Yes | Yes |
| CFO | Yes | Yes | Yes |
| Finance Manager | Yes | Yes | Yes |
| Senior Accountant | Yes | No | Customer/vendor-behavior categories only |
| Accountant | Yes | No | No |
| Auditor / External Auditor | Yes (read-only) | No | No |
| Sales Manager | `customer_note` rows only | No | No |
| Purchasing Manager | `vendor_note` rows only | No | No |
| Read Only | Yes | No | No |

Every write — the automatic confidence update, the human-gated promotion, or a manual override — is additionally logged to `ai_logs` before the operation returns, so a promotion, a confidence update, a rejection, and a manual edit are all equally visible to the same audit tooling that covers every other AI-attributed write in the platform. Concurrent confidence updates to the same row are resolved by ordinary row-level locking inside the single Laravel transaction that performs the update.

# Privacy & PII

Company-level business understanding is, by nature, lower-PII-density than a per-employee or per-transaction memory, but three protections apply without exception. **Hard prohibition, enforced at the write path.** The same validator gating every write elsewhere in the platform runs a deterministic pattern scan (IBAN/Kuwait bank-account formats, GCC national-ID/Civil ID formats, card numbers) against `content` and `structured_value` before a row is persisted or embedded — a match is a hard rejection, never a soft warning, and the caller must reference the sensitive value by pointer (an `attachments` id, a `vendor_bank_accounts` id) rather than by literal value.

**Soft PII is flagged and minimized before embedding.** A `customer_note` or `vendor_note` occasionally names an individual counterpart ("their AP contact always processes payment runs on Thursdays"). Such content is embedded only after the named individual is replaced with a stable role label ("the vendor's AP contact") — semantic search retrieves the pattern without the vector itself encoding a literal personal name — while the unredacted `content` remains visible in the structured view to holders of `ai.memory.read`.

**Right to erasure cascades from the subject, not from the memory row.** When a `customers`/`vendors` subject is erased under the platform's data-erasure procedure, every `ai_memory` row with a matching `subject_type`/`subject_id` has its `content` replaced with a tombstone, its `embedding` cleared, and its `status` forced to `retracted` in the same operation — the row itself is not hard-deleted, since deleting it would also delete the `ai_corrections`/`ai_decisions` provenance chain an audit may still need, but every trace of the erased subject's actual personal content is gone. `business_baselines` and `business_seasonality` rows carry no subject-level PII by construction (they are period-level aggregates, never per-customer/vendor rows) and are unaffected by an individual subject's erasure.

**Data residency.** Every row, embeddings included, is stored in the same PostgreSQL instance and region as the rest of a company's tenant data; Business Memory introduces no new cross-border data flow.

# Retention

Business Memory is operational AI context, not itself a statutory financial record — a `business_model` or `kpi_definition` row has no independent legal retention floor the way a posted `journal_lines` row does. One deliberate exception exists: an `ai_memory` row is retained for at least as long as the underlying financial record it helped a downstream agent interpret, so an auditor reviewing an old posted entry or board narrative can still see what the AI had understood about the business at the time.

| Row class | Default retention | Mechanism | Rationale |
|---|---|---|---|
| `ai_memory`, `status = 'active'` | Indefinite while actively used, subject to the staleness sweep below | `usage_count`/`last_used_at`-driven review | A live business fact has no natural expiry |
| `ai_memory`, `expires_at` set | Auto-lapses at `expires_at` | Scheduled job moves `active` → `retracted` when `expires_at < now()` | A time-bound fact (a stated growth-trajectory milestone that has passed) must not silently outlive its intended window |
| `business_baselines`, `business_seasonality` | Matches the underlying `journal_lines` retention (10 years) | Registered as an additional archive-tier row alongside other AI tables | Evidentiary basis for a business-level claim an auditor may still need to reconstruct |
| `ai_memory`, `status = 'retracted'`/`'superseded'` | 2 years online, then cold-archived | Same partition-and-export pipeline as other AI tables | Rarely consulted but briefly reachable for "why did the AI used to think X" support queries |

A memory row unused (`last_used_at`) for more than 180 days is surfaced to a human with `ai.memory.manage` as a candidate for re-confirmation or retirement, rather than silently continuing to influence agent output after it may have gone stale — the identical staleness discipline every sibling memory document in this platform applies.

# Worked Examples

**Example 1 — a seasonality pattern, from first cycle to a CFO Agent citation.** In August 2025, Al-Noor Trading & Contracting W.L.L.'s nightly aggregation job writes a `business_seasonality` row: `pattern_key='summer_slowdown'`, `metric_key='monthly_revenue_avg'`, `window_label='Jun-Aug'`, `baseline_value=150000.0000`, `observed_value=108000.0000`, `seasonal_index=0.720`, `within_tolerance=true` (this is the first observed occurrence, so there is nothing yet to be within tolerance *of* — it is recorded as the initial reference point). No `ai_memory` row exists yet; the observation is retained with nothing to propose. In August 2026, a second row lands: `observed_value=106500.0000`, `seasonal_index=0.710`, compared against the same trailing baseline within the company's default 10% tolerance band — `within_tolerance=true`. Two confirming cycles now exist. The nightly job creates an `ai_decisions` row: `decision_type='business_memory_promotion'`, `status='pending_approval'`, `agent_code='FORECAST_AGENT'`, confidence `0.8300` (per the worked arithmetic in § The Learning Loop), payload = a candidate `ai_memory` row of `memory_type='seasonality_pattern'` with the `content` shown in § Retrieval's evidence example. Al-Noor's Finance Manager reviews it the same week and approves it — the candidate becomes `active`, and both contributing `business_seasonality` rows have their `ai_memory_id` back-filled. In September 2026, when the CFO Agent drafts a variance narrative explaining why July's revenue came in 26% below the annual average, it retrieves this row via Pass 1 of § Retrieval, and its `reasoning` field states: "July's shortfall is consistent with a confirmed two-cycle seasonal pattern (`memory_id: 91205`, confidence 0.83) rather than a new deterioration — no additional flag raised." Without this row, the same 26% dip would have read as an unexplained, alarming variance every single year.

**Example 2 — customer risk, from behavior to the Business Health Score.** Al Bairaq Retail Group's days-sales-outstanding drifts from 31 to 47 days across two consecutive quarters. The Treasury Manager's own reconciliation and aging review surfaces this as a `customer_note` candidate (`subject_type='customers'`, `subject_id` = Al Bairaq's record) at `confidence=0.78`; a human with `ai.memory.manage` in Sales confirms it after the second quarter's aging report corroborates the same drift, and it becomes `active`. Separately, the CFO Agent's own concentration computation (reading `business_baselines`, `baseline_type='concentration'`, `metric_key='top3_customer_concentration_pct'`) shows Al Bairaq alone at 19% of trailing-12-month revenue against this company's stated 15% comfort threshold (a `kpi_definition` row) — the CFO Agent proposes, and Al-Noor's Owner confirms, a `risk_profile` row stating both facts together. The next time `AI_COMMAND_CENTER.md`'s Business Health Score computes its Working Capital Discipline component, it retrieves both rows: the DSO drift explains *why* the component's trend arrow points down, and the concentration figure explains *how much* exposure a single customer's further slippage would represent — two independently-confirmed Business Memory facts, retrieved together, doing the work a single ledger query never could.

**Example 3 — growth trajectory feeding a Forecast Agent scenario.** During an unrelated conversation with the CEO Assistant, Al-Noor's Owner mentions, in passing, that the company is "seriously looking at" a Dammam, Saudi Arabia branch — a new location, distinct from the existing Riyadh sales office (`branch_id: 3`) — with a rough setup budget around KWD 240,000. The CEO Assistant writes an `ai_memory` candidate (`memory_type='growth_trajectory'`, `source_type='conversation'`, `subject_type IS NULL`) rather than letting the detail evaporate at the end of the chat turn; because it is an explicit human statement, it is promoted to `active` immediately, no recurrence threshold required. Weeks later, when the Owner asks the CFO Agent to model the expansion formally, the CFO Agent's own `run_scenario` tool delegates to the Forecast Agent with the growth-trajectory row's `structured_value` as the scenario's starting assumptions (setup cost KWD 240,000, a 6-month ramp at KWD 35,000/month run-rate, funded from internal cash) rather than asking the Owner to restate numbers already on file. The Forecast Agent's simulation — cash runway falling from 6.8 to 3.1 months, with KWD 150,000 of existing facility headroom unused — is the same scenario the CFO Agent's own specification worked through independently; here, it is Business Memory that supplied the scenario's starting assumptions from a conversation held weeks before anyone asked for a number.

# Metrics

| Metric | Definition | Target | Source |
|---|---|---|---|
| Promotion approval rate | % of `business_memory_promotion` decisions resolved `approved` vs `rejected` | ≥ 70% | `ai_decisions` |
| Business-scope coverage | % of companies active ≥ 6 months with ≥ 1 `active` row in each of `industry_profile`/`business_model`/`revenue_baseline`/`expense_baseline` | ≥ 80% | `ai_memory` |
| Citation rate | % of CFO Agent / Forecast Agent / CEO Assistant outputs citing ≥ 1 business-scope row when one exists and is relevant | ≥ 90% | `ai_decisions.sources` |
| Retrieval latency | p95 time to assemble a business-scope evidence bundle | < 150 ms | `ai_logs` |
| Staleness rate | % of `active` rows with `last_used_at` > 180 days ago | < 15% | scheduled sweep |
| Seasonality prediction accuracy | % of `seasonality_pattern` next-cycle predictions landing within stated tolerance | ≥ 75% | `ai_prediction_outcomes` (`AI_FINANCE_OS.md` § Predictive Finance) |
| Supersession rate | % of `active` rows superseded per year due to repeated contradicting cycles | < 10% | `business_baselines` / `business_seasonality` |

# Edge Cases

| Edge Case | Handling |
|---|---|
| Cold-start company, no history yet | Business-scope retrieval returns empty; agents state that explicitly and fall back to clearly-labeled, lower-confidence generic industry priors — never presented with the same weight as this company's own confirmed memory |
| Ledger and a Business Memory row disagree on a number the ledger owns | The ledger always wins; the disagreement is logged and the memory row flagged for review, never silently trusted over the primary record |
| A single anomalous cycle would otherwise break a real seasonal pattern | A human (or an agent citing a documented cause) marks the specific `business_seasonality` row `is_anomalous = true` via the ordinary edit path, excluding it from the confirm/contradict count without deleting the row |
| Fiscal-year change, or a company acquired mid-year | Baseline computation restarts its trailing window from the change date; rows preceding the change are retained but excluded from any trailing calculation that would straddle it |
| Multi-branch, multi-currency baselines | `branch_id` scopes a row to one branch; a company-wide row has `branch_id IS NULL`; cross-branch comparison always normalizes to the company's base currency, never mixes raw transaction-currency figures |
| Sample size too small to promote (one bill, one order) | The ≥ 2 confirming-cycle threshold blocks promotion outright; the single observation is retained in `business_baselines`/`business_seasonality` but never creates a promotion candidate |
| Two humans state conflicting growth-trajectory facts | Both are captured as separate candidates; the promotion service detects the overlap (same `memory_type`, overlapping `structured_value` target) and opens one `pending_approval` decision presenting both statements side by side for a human to resolve — never silently averaged or last-write-wins |
| Bilingual content (Arabic vs. English) | `content` is stored in whichever language it was captured in; the platform's shared embedding model is multilingual, so a semantic query in one language still retrieves a row authored in the other; the human-facing review surface always shows the original alongside an on-the-fly translation, never overwrites it |
| Vector index recall at small per-tenant footprint | Monitored operationally (`ivfflat` list-count tuning), never a security boundary — the RLS predicate never relaxes to improve recall; a degraded-recall retrieval fails toward fewer, still-correctly-scoped results |

# Future Improvements

- **Anonymized cross-tenant industry benchmarking.** A future, strictly opt-in, differential-privacy-bounded aggregation pipeline — never a direct query against `ai_memory`, `business_baselines`, or `business_seasonality` — publishing only bucketed percentile bands no single company could be re-identified from, matching the exact discipline `AI_COMMAND_CENTER.md` already commits to for its own Business Health Score benchmarking ambitions.
- **Automatic anomaly-period exclusion.** Today a human marks a `business_seasonality` row `is_anomalous`; a future iteration lets the Forecast Agent's own outlier detection propose the flag itself (still human-confirmed, never auto-applied), so a one-off disruption does not require a human to notice it before it can be excluded from the confirm/contradict count.
- **Natural-language Business Memory review surface.** A settings screen where an Owner reviews and edits all ten categories in plain bilingual language rather than raw structured fields, matching the platform's broader "the shell is not a bypass" principle — the edit still lands through the same `PATCH /api/v1/ai/memory/{id}` endpoint and `ai.memory.manage` gate.
- **Group/holding-company business memory.** For a multi-company parent structure, a future capability to share explicitly-designated, non-sensitive business facts (an industry classification, a shared growth trajectory) across sibling companies under one holding entity — deliberately not built today, and never by relaxing per-`company_id` isolation, only by an explicit, auditable copy-on-approval mechanism if and when it ships.
- **Confidence-weighted ensemble seasonality models.** Once a company has accumulated three or more years of `business_seasonality` history, reconcile multiple candidate drivers for the same window (a regulatory calendar effect and a religious-calendar effect that partially overlap) into one ensemble index rather than requiring a single `driver_note` to explain the whole deviation.

# End of Document
