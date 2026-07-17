# Accounting Memory — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: ACCOUNTING_MEMORY
---

# Purpose

Every proposal the General Accountant Agent makes is graded, in part, on a single question: does this look like something this specific company would actually book, or does it look like generic double-entry bookkeeping applied blindly to a document it has never seen the likes of before? `AI_FINANCE_OS.md` (§ Company Memory) answers that question at the platform level — every agent's output is specific to one company because every agent reads from a persistent, per-company substrate before it reasons. `ACCOUNTANT_AGENT.md` names that substrate as the fourth of its six input categories ("Company memory — `ai_memory` rows scoped to this company... holding preferred account mappings, materiality conventions, and previously human-corrected classifications") and leans on it directly inside its own confidence formula, where "account-mapping precedent strength (frequency × recency)" carries a full 0.35 of the total weight — the single largest term, tied with the vendor/customer match score itself. This document is the accounting-domain deep dive that operationalizes that dependency: it specifies exactly what gets remembered, in what shape, how it is retrieved fast enough to matter inside a sub-two-minute draft SLA, and — most importantly — the precise mechanical loop by which a human's approval, edit, or rejection of an AI proposal becomes a durable, numerically-scored fact the next proposal for the same vendor, account, or pattern will retrieve and cite.

Accounting Memory is not a new, parallel memory subsystem. It is the accounting module's specific usage of two tables the platform already owns — `ai_memory` and `ai_corrections`, both defined once in `AI_FINANCE_OS.md` and reused here without redefinition — plus exactly one new table this document introduces and owns: `ai_categorization_rules`, a structured, non-embedding companion table built for the one thing free-text semantic memory is comparatively slow and imprecise at: answering "which General Ledger account, tax code, cost center, and project does *this exact* vendor, memo pattern, or product category map to, right now, and how confident are we" in the tight inner loop of the General Accountant's RESOLVE step, thousands of times a day, without a vector search on the hot path. The five families of fact this document is responsible for are stated once, precisely, and never contradicted elsewhere: transaction-to-account categorization rules learned from approvals; vendor-to-expense-account (and customer-to-revenue-account) mappings; recurring journal templates and their typical amounts; typical accruals and the trailing-window estimates that back them; tax-code defaults per vendor, customer, and product category; and the raw historical-correction ledger every one of those derived facts is ultimately built from.

The stakes are concrete, not aspirational. `ACCOUNTANT_AGENT.md`'s own worked Scenario 1 cites "Historical mapping for this vendor's packaging-materials line has posted to account 5210 in 34 of the last 34 occurrences over 180 days" as the second-largest contributor to a 0.94 confidence score; its Scenario 3 estimates a month-end accrual "from the trailing 3-month average (KWD 42.000/month, low variance)." Both numbers are outputs of this document's data model, not generic model reasoning — this document specifies exactly which table stores the "34 of the last 34" count, exactly which query produces the "trailing 3-month average," and exactly what happens, mechanically, the day a human corrects one of those numbers so that the correction is never re-litigated by the agent again. A company with zero transaction history starts every General Accountant proposal at a deliberately conservative, `suggest_only`-leaning confidence; the same company, eighteen months later, sees a large share of its routine bookkeeping auto-drafted at high confidence, entirely because Accounting Memory accumulated — never because an engineer retrained a model or hand-wrote a rule for that company. That transition, and only that transition, is what this document exists to make precise, auditable, and safe.

Accounting Memory inherits every platform-wide non-negotiable stated in `AI_FINANCE_OS.md` and `ACCOUNTANT_AGENT.md` without exception: it is scoped to `company_id` with no cross-tenant query path at any layer; it is never written to directly by the FastAPI AI engine, only through permissioned Laravel `/api/v1/*` endpoints; every derived fact remains inspectable back to the specific correction or configuration event that produced it; and no single correction is allowed to instantly and irreversibly overwrite an agent's established behavior. The rest of this document specifies, precisely: what is stored (**What Is Stored**); its exact PostgreSQL and pgvector schema (**Data Model**); how tenant isolation is enforced at the database layer, not merely by convention (**Per-Company Isolation**); how an agent retrieves the right memory fast enough to matter (**Retrieval**); the exact arithmetic by which an approved correction raises — or an override lowers — a mapping's confidence (**The Learning Loop**); which component is allowed to write which row and under what permission (**Write Path & Governance**); what must never be stored here and why (**Privacy & PII**); how long a memory row lives and what makes it stale (**Retention**); three fully worked, numbered scenarios (**Worked Examples**); the metrics that judge whether this subsystem is actually working (**Metrics**); the failure modes it must degrade gracefully under (**Edge Cases**); and where it goes next (**Future Improvements**).

# What Is Stored

Accounting Memory captures seven families of fact. Each is a `memory_type` value on the shared `ai_memory` table (reused from `AI_FINANCE_OS.md` § Company Memory) except for the first, which is additionally mirrored into the dedicated `ai_categorization_rules` table (**Data Model**) specifically because it is the highest-volume, most latency-sensitive lookup in the General Accountant's inner loop and deserves a structured, non-embedding fast path in addition to its semantic record.

| Family | `ai_memory.memory_type` | Also in `ai_categorization_rules`? | Example `content` | Example `structured_value` |
|---|---|---|---|---|
| Transaction categorization rule | `vendor_note` / `correction` | **Yes — primary home** | "ACME Supplies W.L.L. packaging-materials bills post to Packaging & Consumables Expense (5210)." | `{"account_code":"5210","confidence":0.94,"hit_count":34}` |
| Vendor → expense-account mapping | `vendor_note` | Yes (rule_scope='vendor') | "Gulf Prime Distribution Co. invoices are always zero-rated for the foodstuffs product category." | `{"account_code":"5130","tax_code":"VAT_ZERO"}` |
| Customer → revenue-account mapping | `customer_note` | Yes (rule_scope='customer') | "Al-Fahad Retail Group's wholesale orders post to Wholesale Revenue (4020), not Retail Revenue (4010)." | `{"account_code":"4020"}` |
| Recurring journal template affinity | `preference` | No — lives on `journal_entry_templates` (Journal Entries module) plus a preference row here | "The NBK account-maintenance fee template has recurred with an identical KWD 3.500 amount for 14 consecutive months." | `{"template_code":"bank_fee_nbk_maintenance","streak_months":14,"amount":"3.5000"}` |
| Typical accrual (trailing estimate) | `preference` | No — derived, recomputed each period | "The internet-service accrual has averaged KWD 42.000/month over the trailing 3 months, low variance." | `{"trailing_window_months":3,"avg_amount":"42.0000","variance_pct":4.1}` |
| Tax-code default per item/vendor/category | `vendor_note` / `policy` | Yes (`tax_code_id` column) | "Office-supplies purchases from local (non-GCC) vendors default to standard-rated input VAT." | `{"tax_code":"VAT_STANDARD_5"}` |
| Historical correction (raw signal) | `correction` | Feeds rule updates, not a rule itself | "This vendor's delivery fee belongs to Facilities Expense (5210), not Office Supplies (5130) — corrected twice, same reasoning both times." | `{"from_account":"5130","to_account":"5210","occurrences":2}` |

Two facts intentionally never live here because another module already owns them, and `AI_FINANCE_OS.md` § 9 / this repository's own convention forbids duplicating a table another document owns: the Chart of Accounts tree itself (`accounts`, owned by the Chart of Accounts module) and the recurring-schedule mechanics of a template (`journal_entry_templates`, owned by Journal Entries). Accounting Memory stores *affinity and confidence about* those objects — which account a pattern maps to, how consistently a template has recurred — never the objects' own structural definitions.

# Data Model (PostgreSQL + vector/embedding schema DDL)

Accounting Memory is built on three tables. Two — `ai_memory` and `ai_corrections` — are the shared platform tables defined once in `AI_FINANCE_OS.md` (§ Company Memory, § Learning Loop) and reproduced below **verbatim, not redefined**, exactly as `ACCOUNTANT_AGENT.md` reproduces `ai_decisions` from `CFO_AGENT.md` without redefining it. The third, `ai_categorization_rules`, is introduced and owned by this document.

```sql
-- ============================================================
-- ai_memory — defined in AI_FINANCE_OS.md § Company Memory.
-- Reused verbatim here; not redefined. Accounting Memory is the
-- set of rows on this table with memory_type IN ('vendor_note',
-- 'customer_note', 'correction', 'preference', 'policy') whose
-- subject_type IN ('vendors','customers','accounts',
-- 'journal_entry_templates') or subject_type IS NULL (company-wide
-- accounting policy, e.g. materiality threshold).
-- ============================================================
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

-- ============================================================
-- ai_corrections — defined in AI_FINANCE_OS.md § Learning Loop.
-- Reused verbatim; not redefined. This is the append-only capture
-- of every accept/edit/reject outcome on an ai_decisions row.
-- ============================================================
CREATE TABLE ai_corrections (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    decision_id       BIGINT NOT NULL REFERENCES ai_decisions(id),
    original_proposal JSONB NOT NULL,
    human_action      VARCHAR(20) NOT NULL CHECK (human_action IN ('accepted','edited','rejected')),
    final_value       JSONB NULL,                -- what was actually posted, if different from the proposal
    reason_given      TEXT NULL,                 -- optional free-text from the approver
    corrected_by      BIGINT NOT NULL REFERENCES users(id),
    memory_id         BIGINT NULL REFERENCES ai_memory(id),  -- the derived memory row, once written
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ai_corrections_company_decision ON ai_corrections(company_id, decision_id);

-- Additive migration on the shared table above — a single nullable column,
-- populated by this document's write path (see Write Path & Governance),
-- never a redefinition of the table itself.
ALTER TABLE ai_corrections
    ADD COLUMN rule_id BIGINT NULL REFERENCES ai_categorization_rules(id);
CREATE INDEX idx_ai_corrections_rule ON ai_corrections(rule_id) WHERE rule_id IS NOT NULL;
```

`ai_memory` answers *"what does this company generally believe, prefer, or has been told, in natural language"* — retrieved by semantic similarity, necessarily a little soft, and well suited to policy statements, one-off institutional knowledge, and narrative citation inside an agent's `reasoning` field. It is comparatively expensive to query at the volume the General Accountant's RESOLVE step demands (thousands of candidate lookups per company per day) and imprecise for the one question asked more often than any other in accounting: *"which exact account does this exact vendor's this exact kind of line map to, right now."* `ai_categorization_rules`, introduced here, exists to answer that one question in O(1)/O(log n) time with no embedding computation on the hot path, while remaining fully cross-referenced back to the `ai_memory` row and `ai_corrections` row that justify it:

```sql
-- ============================================================
-- ai_categorization_rules — owned by this document (Accounting Memory).
-- A structured, deterministic companion to ai_memory: exact/fuzzy lookup
-- keyed on a normalized vendor name, bank-memo keyword, product category,
-- or customer, resolving directly to an account/tax-code/dimension tuple
-- plus a numerically maintained confidence score. This is the table the
-- General Accountant's RESOLVE step (ACCOUNTANT_AGENT.md § Reasoning &
-- Prompt Strategy) queries first, before falling back to ai_memory's
-- semantic search for anything the structured pass does not resolve.
-- ============================================================
CREATE TABLE ai_categorization_rules (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    rule_scope            VARCHAR(30) NOT NULL
        CHECK (rule_scope IN ('vendor','customer','keyword','product_category','bank_memo_pattern')),
    match_type            VARCHAR(20) NOT NULL DEFAULT 'exact'
        CHECK (match_type IN ('exact','fuzzy','regex')),
    subject_type          VARCHAR(60) NULL,      -- 'vendors' | 'customers' | 'products'; NULL for memo/keyword rules
    subject_id            BIGINT NULL,
    match_key             TEXT NOT NULL,          -- normalized vendor name / memo keyword / SKU pattern (human-readable)
    match_fingerprint     TEXT NOT NULL,          -- deterministic hash of the normalized match_key; O(1) lookup key
    account_id            BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id        BIGINT NULL REFERENCES cost_centers(id),
    project_id            BIGINT NULL REFERENCES projects(id),
    tax_code_id           BIGINT NULL REFERENCES tax_codes(id),
    default_memo_template TEXT NULL,
    confidence            NUMERIC(5,4) NOT NULL DEFAULT 0.5000 CHECK (confidence BETWEEN 0 AND 1),
    hit_count             INTEGER NOT NULL DEFAULT 0,
    accept_count          INTEGER NOT NULL DEFAULT 0,
    override_count        INTEGER NOT NULL DEFAULT 0,
    consecutive_overrides INTEGER NOT NULL DEFAULT 0,
    cooldown_until        TIMESTAMPTZ NULL,       -- set on override; suppresses auto-eligibility, never blocks suggest-only
    last_hit_at           TIMESTAMPTZ NULL,
    last_overridden_at    TIMESTAMPTZ NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','suspended','superseded','retracted')),
    superseded_by_id      BIGINT NULL REFERENCES ai_categorization_rules(id),
    source_memory_id      BIGINT NULL REFERENCES ai_memory(id),       -- the semantic memory row this rule was promoted from, if any
    source_correction_id  BIGINT NULL REFERENCES ai_corrections(id),  -- most recent correction that updated this rule
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    UNIQUE (company_id, rule_scope, match_fingerprint, account_id)
);

CREATE EXTENSION IF NOT EXISTS pg_trgm;  -- already assumed platform-wide; see AI_FINANCE_OS.md § Autonomous Reconciliation

CREATE INDEX idx_categorization_rules_lookup
    ON ai_categorization_rules(company_id, rule_scope, match_fingerprint)
    WHERE status = 'active';
CREATE INDEX idx_categorization_rules_subject
    ON ai_categorization_rules(company_id, subject_type, subject_id);
CREATE INDEX idx_categorization_rules_confidence
    ON ai_categorization_rules(company_id, confidence DESC);
CREATE INDEX idx_categorization_rules_fuzzy
    ON ai_categorization_rules USING gin (match_key gin_trgm_ops);
```

`match_fingerprint` is computed application-side (Laravel service layer, never the AI engine) by a deterministic normalization function applied to `match_key`: lowercase; strip common Gulf legal-entity suffixes (`W.L.L.`, `LLC`, `Co.`, `Ltd`, `Est.`, `Trading`, `Group`); collapse whitespace and punctuation to single spaces; then SHA-256 the result. Two differently-cased, differently-punctuated spellings of "ACME Supplies W.L.L." and "Acme  Supplies WLL" collapse to the same `match_fingerprint` and therefore the same rule row, while `match_key` retains the original human-readable form for display. `match_type='fuzzy'` rules skip the fingerprint index and are instead retrieved via the trigram index for a vendor whose name varies enough (transliteration drift between Arabic and English bank memos is the dominant real-world case) that exact fingerprinting under-matches.

The division of labor is deliberate and mirrors the hybrid retrieval pattern `ACCOUNTANT_AGENT.md` already establishes for its RESOLVE step: `ai_categorization_rules` is the fast, structured, high-confidence-when-it-hits path; `ai_memory` is the slower, semantic, broader-coverage fallback that also carries the narrative context an agent's `reasoning` field cites. Neither table duplicates the other's primary data — a rule row never repeats the full sentence a memory row states, and a memory row never repeats a rule row's numeric hit-count bookkeeping. Where the two disagree (a stale rule with high confidence contradicted by a newer, more specific memory note), structured facts still take precedence per the platform-wide retrieval rule, but the disagreement itself is surfaced, never silently resolved (see **Edge Cases**).

# Per-Company Isolation

`ai_memory.company_id` and `ai_categorization_rules.company_id` are both `NOT NULL`, indexed first in every composite index above, and enforced by Row Level Security identically to every other tenant table in the platform — this is not a convention the AI engine chooses to respect, it is a boundary the database enforces regardless of what the querying process asks for, exactly as `AI_FINANCE_OS.md` § Company Memory states for `ai_memory` and as `ACCOUNTANT_AGENT.md` § Data Access & Tenant Scope states for this agent's own session:

```sql
CREATE POLICY tenant_isolation_ai_memory ON ai_memory
    USING (company_id = current_setting('app.current_company_id')::bigint);

CREATE POLICY tenant_isolation_ai_categorization_rules ON ai_categorization_rules
    USING (company_id = current_setting('app.current_company_id')::bigint);

ALTER TABLE ai_memory               FORCE ROW LEVEL SECURITY;
ALTER TABLE ai_categorization_rules FORCE ROW LEVEL SECURITY;
```

Both policies are declared `NOBYPASSRLS` for every role that can reach them, including the AI service-account role, per the platform's Row-Level Security rules — an agent session for company 4821 has no query path, structured or semantic, that can resolve a row belonging to company 4822. This matters more here than almost anywhere else in the platform, because Accounting Memory is precisely the subsystem that makes an agent "smarter" over time, and it is exactly the kind of cross-company pattern-matching temptation ("this looks like how a hundred other trading companies code this vendor") that a naively-built learning system would reach for. QAYD's answer is structural, not a policy an engineer promises to honor: the `ivfflat` vector index on `ai_memory.embedding` and the B-tree/GIN indexes on `ai_categorization_rules` are both queried through the same RLS-scoped connection as every other read, so there is no separate, unscoped index partition an embedding search could accidentally reach across. `branch_id` on both tables is a dimension for branch-level reporting and rule scoping within a company (see **Edge Cases** for per-branch override behavior), never an isolation boundary in its own right — the isolation boundary is `company_id`, full stop, and a `NULL` `branch_id` row is visible company-wide, never cross-company.

Two secondary enforcement layers back the RLS policy, matching `ACCOUNTANT_AGENT.md`'s own three-layer pattern: (1) network isolation — the FastAPI subnet has no direct route to PostgreSQL, so a retrieval request is always mediated by a Laravel-issued, company-scoped bearer token; (2) a runtime assertion in the FastAPI orchestration layer that rejects any retrieved row whose `company_id` does not match the active task's `company_id`, defense-in-depth against a hypothetical bug in the RLS policy or a misconfigured connection pool that failed to set `app.current_company_id` for a given session.

# Retrieval (RAG / semantic + structured)

An agent assembling context for a categorization decision runs two retrieval passes in parallel, merges the results, and lets structured evidence win any disagreement — the identical hybrid-retrieval rule `ACCOUNTANT_AGENT.md` § Reasoning & Prompt Strategy states for its own RESOLVE step, specified here at the query level.

**Pass 1 — structured, exact/fuzzy lookup against `ai_categorization_rules`.** Given a normalized vendor name (or bank-memo keyword, or product category code) from the current document/event, the retrieval service computes its `match_fingerprint` and looks up any `active` rule for this company with that exact fingerprint; if none exists, it falls back to a trigram similarity query against `match_key` for `match_type='fuzzy'` rules above a similarity floor (default 0.75, the same floor family `AI_FINANCE_OS.md` § Autonomous Reconciliation uses for its own fuzzy bank-matching pass):

```sql
-- Exact pass (sub-millisecond on the partial index)
SELECT id, account_id, cost_center_id, project_id, tax_code_id, confidence,
       hit_count, cooldown_until
FROM ai_categorization_rules
WHERE company_id = :company_id
  AND rule_scope = 'vendor'
  AND match_fingerprint = :fingerprint
  AND status = 'active'
ORDER BY confidence DESC
LIMIT 3;

-- Fuzzy fallback, only if the exact pass returns zero rows
SELECT id, account_id, confidence, similarity(match_key, :raw_vendor_name) AS sim
FROM ai_categorization_rules
WHERE company_id = :company_id
  AND rule_scope = 'vendor'
  AND match_type = 'fuzzy'
  AND status = 'active'
  AND match_key % :raw_vendor_name        -- pg_trgm operator, uses the GIN index
ORDER BY sim DESC
LIMIT 3;
```

**Pass 2 — semantic, vector similarity against `ai_memory.embedding`.** Run unconditionally, not only as a fallback, because a structured rule can resolve *which account* while still missing softer context that changes how the proposal should be framed (a policy note that this vendor's fee is subject to a temporary dispute, a preference note about a specific cost-center split for a shared vendor). The current document/event is reduced to a short natural-language description and embedded with the same model used to write `ai_memory.embedding`, then queried with cosine distance:

```sql
SELECT id, memory_type, subject_type, subject_id, content, structured_value, confidence
FROM ai_memory
WHERE company_id = :company_id
  AND status = 'active'
  AND memory_type IN ('vendor_note','customer_note','correction','preference','policy')
ORDER BY embedding <=> :query_embedding
LIMIT 5;
```

**Merge.** Both result sets are returned to the agent's RESOLVE step as one ranked evidence list, each item tagged `source_kind: "structured"` or `"semantic"`. Structured hits are never silently dropped in favor of a semantic result that merely sounds more confident in natural language — if `ai_categorization_rules` returns an active rule at 0.94 confidence and `ai_memory`'s semantic pass surfaces a superficially related but lower-relevance note, the rule wins the account assignment and the memory note is retained only as supplementary reasoning context, exactly mirroring the platform-wide rule that "structured facts always take precedence over a memory hint when the two disagree" (`ACCOUNTANT_AGENT.md` § Reasoning & Prompt Strategy, restated from `CFO_AGENT.md`).

**Contract returned to the agent's context bundle:**

```json
{
  "categorization_evidence": [
    {
      "source_kind": "structured",
      "rule_id": 40217,
      "account_id": 5210,
      "tax_code_id": null,
      "confidence": 0.9400,
      "hit_count": 34,
      "accept_count": 33,
      "last_hit_at": "2026-07-10T11:02:00Z",
      "cooldown_until": null
    },
    {
      "source_kind": "semantic",
      "memory_id": 88431,
      "memory_type": "vendor_note",
      "content": "ACME Supplies W.L.L. packaging-materials bills post to Packaging & Consumables Expense (5210).",
      "confidence": 1.0,
      "similarity": 0.91
    }
  ],
  "retrieval_latency_ms": 41
}
```

Both `usage_count`/`last_used_at` on `ai_memory` and `hit_count`/`last_hit_at` on `ai_categorization_rules` are incremented as a side effect of a retrieval that the agent's downstream proposal actually cites — a row that is fetched but not cited (out-ranked by a better match) does not have its usage stats bumped, so staleness tracking (**Retention**) reflects genuine influence, not mere candidacy.

# The Learning Loop (approved corrections become memory)

This is the mechanism the rest of the document exists to support: the exact, code-verified arithmetic by which a human's approval, edit, or rejection of a `journal_entries`/`ai_decisions` proposal becomes a durably higher — or lower — confidence score the next relevant proposal will retrieve. It is a direct, mechanized specification of the loop `AI_FINANCE_OS.md` § Learning Loop states in prose ("every accepted, edited, or rejected AI proposal becomes a row in Company Memory... so that 'the AI got smarter' means, concretely, 'the next relevant decision retrieves this specific prior correction as evidence'").

**Trigger.** A human acts on an `ai_decisions` row created by the General Accountant (`decision_type` in `accountant_journal_draft`, `accountant_categorization`, `accountant_reclassification`, or `accountant_month_end_accrual`, per `ACCOUNTANT_AGENT.md` § Outputs) via the ordinary Journal Entries approval endpoints. That action is captured, in the same database transaction, as one `ai_corrections` row: `human_action = 'accepted'` if the draft posted unchanged; `'edited'` if any `journal_lines.account_id`, `tax_code_id`, `cost_center_id`, or `project_id` differs between `original_proposal` and `final_value`; `'rejected'` if the draft was discarded outright.

```
 Human approves / edits / rejects a journal_entries draft
 (entry_type='ai_generated'), via the Journal Entries API
              │
              ▼
   ┌─────────────────────────────┐
   │ Laravel approval service       │  writes ai_corrections row in the
   │ (NOT the FastAPI AI engine)     │  SAME transaction as the entry's
   │                                   │  own status transition
   └────────────────┬─────────────────┘
                    ▼
        does original_proposal cite a
        source ai_categorization_rules.rule_id?
       ┌────────────┴─────────────┐
      YES                          NO (first sighting / semantic-only match)
       │                            │
       ▼                            ▼
 ┌───────────────┐         ┌─────────────────────────┐
 │ UPDATE the      │         │ INSERT a new rule row at  │
 │ existing rule:   │         │ the cold-start confidence  │
 │ apply the         │         │ (0.50), then apply the      │
 │ confidence         │         │ SAME update formula as a     │
 │ update formula      │         │ first observation (n = 0)     │
 │ below                │         └─────────────┬─────────────┘
 └───────┬────────┘                             │
         └───────────────┬───────────────────────┘
                         ▼
              ┌─────────────────────────┐
              │ Write/update a paired      │
              │ ai_memory row (memory_type  │
              │ ='correction' or            │
              │ 'vendor_note'), embedded,     │
              │ source_type='correction'       │
              └─────────────┬─────────────────┘
                            ▼
                 ai_corrections.rule_id and
                 .memory_id both set — the
                 correction is now traceable
                 to both the structured and
                 semantic artifact it updated
```

**Confidence update formula.** Let `o` (the observed outcome) be `1.0` for `accepted`, `0.35` for `edited` (the agent identified a real transaction worth drafting, but coded it wrong — partial credit, not zero), and `0.0` for `rejected`. Let `n` be the rule's `hit_count` *before* this event. The learning rate `α = 1 / (n + 2)` — the standard recursive running-mean step size, with a `+2` (rather than `+1`) Laplace-style pad that keeps a rule's very first update conservative (`α = 0.5` at `n = 0`) rather than jumping straight to full confidence off a single data point:

```
new_confidence = clamp( old_confidence + α × (o − old_confidence), 0.05, 0.99 )
where α = 1 / (hit_count_before + 2)
```

This is deliberately the same "no single correction overwrites an agent's general behavior instantly and irreversibly" guardrail `AI_FINANCE_OS.md` § Learning Loop states in prose, made arithmetic: at `n = 0`, one accepted proposal moves confidence from 0.50 to 0.75, not to 1.0; at `n = 33` (`ACCOUNTANT_AGENT.md`'s Scenario 1 vendor, 34th observation), one more acceptance moves confidence by only `α ≈ 0.029`, matching the intuition that a well-established, 33-observation-deep pattern should barely move on data point 34. Every application of this formula is executed by deterministic Laravel service code — never trusted from a language model's own arithmetic — mirroring the identical discipline `ACCOUNTANT_AGENT.md` § Reasoning & Prompt Strategy applies to `SUM(debit)=SUM(credit)` verification.

Bookkeeping fields update alongside confidence on every event: `hit_count += 1` always; `accept_count += 1` only on `accepted`; on `edited` or `rejected`, `override_count += 1`, `consecutive_overrides += 1`, `last_overridden_at = now()`, and `cooldown_until = now() + INTERVAL '90 days'` — the exact 90-day figure `AI_FINANCE_OS.md`'s Autonomous Accounting threshold table already assigns to "any entry a human previously corrected for this vendor/account pair," now attached to the specific rule row it governs rather than left as an unattached policy statement. `consecutive_overrides` resets to `0` on the next `accepted` event. A rule whose `consecutive_overrides` reaches `3` is automatically set to `status = 'suspended'` — it stops being served to the structured retrieval pass entirely (falling back to semantic-only evidence, always at lower resulting confidence) until a Finance Manager or Senior Accountant explicitly reviews and reactivates it (**Write Path & Governance**), because three corrections in a row without an intervening acceptance is treated as evidence the underlying business reality changed, not as noise.

**Edited and rejected outcomes additionally branch on where the correction should land.** If the human's `final_value` names a *different* account than the one the original rule proposed, the write path does not merely lower the old rule's confidence — it also checks whether an existing rule already maps this same `match_fingerprint` to the new account. If yes, that sibling rule receives the `accepted`-equivalent positive update (the human's edit is itself evidence *for* the sibling mapping); if no, a brand-new rule is created for the new mapping at the cold-start confidence and immediately linked via `source_correction_id`. This is exactly `AI_FINANCE_OS.md`'s own worked example mechanized precisely: "the bookkeeper edits four consecutive General Accountant proposals... moving it from account 5130 to account 5210 each time... after the second consistent edit, the correction's retrieval weight is high enough that the third proposal already drafts to account 5210 directly" — under this formula, the new 5210 rule reaches confidence `0.50 → 0.75` after its first `edited`-derived creation-plus-immediate-corroboration, comfortably inside the `0.60–0.85` "pre-filled but fully editable default" band by the third occurrence, matching the platform's own stated example precisely.

# Write Path & Governance

No row in `ai_memory` or `ai_categorization_rules` is ever written by the FastAPI AI engine directly — every write is either (a) a side effect of a Laravel service processing a human's own approve/edit/reject call against the Journal Entries or Accounts API, or (b) an explicit agent *proposal* submitted through the identical permissioned endpoint every other agent output travels through. This split is intentional and mirrors `ACCOUNTANT_AGENT.md`'s own "two proposal shapes" distinction: a confidence *update* to an existing, already-governed rule is bookkeeping arithmetic triggered by a human action Laravel already owns; a brand-new candidate rule proposed from a pattern the agent noticed on its own (not yet backed by any human decision) is a first-class AI proposal that must itself carry confidence, reasoning, and sources before anything is written.

| Write | Who/what performs it | Endpoint | Permission Key | Governance |
|---|---|---|---|---|
| Confidence/hit-count update to an existing rule (accept/edit/reject outcome) | Laravel `JournalEntryApprovalService`, inside the same transaction as the entry's status transition | *(internal — not a separate API call; a side effect of)* `POST /api/v1/accounting/journal-entries/{id}/approve` | n/a (runs under the caller's own approval permission) | Fully deterministic, code-verified formula (**The Learning Loop**); never an LLM judgment call |
| New candidate rule from an agent-noticed pattern with no human decision yet (e.g. 34/34 consistency crossing a promotion threshold) | General Accountant agent, via its existing `propose_account_suggestion` tool | `POST /api/v1/ai/decisions` (`decision_type='accountant_categorization'`) | `ai.accountant.categorize` (already granted, per `ACCOUNTANT_AGENT.md` § Tools & API Access) | Lands as a *proposal*; only promoted to an `active` `ai_categorization_rules` row on human accept, via the same approval path above |
| Read rules/memory for display, review, or audit | Any authorized human role | `GET /api/v1/ai/categorization-rules`, `GET /api/v1/ai/memory` | `ai.memory.read` | Read-only; scoped by RLS as any other read |
| Manually suspend, retract, or edit a rule (override the learned mapping without waiting for 3 consecutive corrections) | Finance Manager / Senior Accountant | `PATCH /api/v1/ai/categorization-rules/{id}` | `ai.memory.manage` | Human-only; writes `audit_logs` (who, old value, new value, reason) exactly as any other mutation |
| Reactivate a `suspended` rule after review | Finance Manager | `PATCH /api/v1/ai/categorization-rules/{id}` (`status: 'active'`) | `ai.memory.manage` | Requires an explicit reason string, stored in `audit_logs` |
| Retract a memory row (privacy/redaction request, stale policy) | Finance Manager / Owner | `PATCH /api/v1/ai/memory/{id}` (`status: 'retracted'`) | `ai.memory.manage` | Does not hard-delete (**Privacy & PII**, **Retention**); tombstones content while preserving referential integrity |

Every write, whether performed by the human-triggered Laravel service or the agent's own permissioned tool call, is additionally logged to `ai_logs` (shared table, defined in `CFO_AGENT.md`) before the operation returns — a rule promotion, a confidence update, a suspension, and a manual override are all equally visible after the fact to the same audit tooling that already covers every other AI-attributed write in the platform. Concurrent writes to the same rule (two reviewers approving two different bills from the same vendor within the same second) are resolved by ordinary row-level locking inside the single Laravel transaction that performs the update — there is no window in which two concurrent `UPDATE`s can silently clobber each other's `hit_count` increment, because the increment is expressed as `hit_count = hit_count + 1` inside a transaction, not as a read-modify-write across two round trips.

The `UNIQUE (company_id, rule_scope, match_fingerprint, account_id)` constraint on `ai_categorization_rules` is itself a governance mechanism, not merely a performance index: it makes "two active rules mapping the same vendor to the same account" structurally impossible, forcing any duplicate candidate to update the existing row's statistics instead of silently forking a second, competing rule (see **Edge Cases** for the case where the same vendor legitimately maps to *two different* accounts depending on line content, which the constraint correctly allows since `account_id` differs).

# Privacy & PII

Accounting Memory stores business facts, not personal data, by design — and the schema and write path both enforce that boundary rather than merely documenting it. `ai_categorization_rules.match_key` and `ai_memory.content` are restricted, by the normalization and authoring rules that produce them, to business-identifying text: a company or vendor legal name, a product category, a GL account name, an amount, a cadence. Neither field is permitted to carry a bank account number, IBAN, payment card number, national ID/Civil ID number, or an individual employee's national identifier — those values live exclusively in their owning tables (`bank_accounts`, `vendor_bank_accounts`, `employees`) behind those tables' own permission gates, and Accounting Memory only ever stores a reference (`subject_id`) back to the owning row, never the sensitive field's value itself. This is the same discipline `ACCOUNTANT_AGENT.md` § Data Access & Tenant Scope already states for the agent's own reads ("banking/contact PII is never pulled into a prompt unless the specific match requires it"), applied here to what is allowed to become a *persistent, retrievable* memory rather than a transient prompt input — a materially higher bar, since a memory row outlives any single conversation.

The one `ai_memory.memory_type` value with a realistic PII surface is `employee_context` (e.g., "this approver typically reviews payroll on Sundays"), which is out of scope for Accounting Memory proper (it belongs to Payroll/Approval Assistant's own usage of the shared table) but shares the same underlying table, so the redaction mechanism below is specified once, platform-wide, and applies identically regardless of which module's row triggers it. Free-text `content` fields are also the one place a source document's incidental personal detail (an individual's name appearing in a bank transfer memo, for instance) could theoretically leak in if authored carelessly; the authoring path mitigates this structurally rather than by policy alone — `content` is always a system-generated, templated statement produced by the writing agent or Laravel service from structured fields (vendor name, account code, amount, cadence), never a raw copy-paste of an OCR'd document's full free text, which keeps the embedding surface narrow and predictable by construction.

**Untrusted content, restated for this table.** Exactly as `ACCOUNTANT_AGENT.md` specifies for OCR'd document text feeding the agent's reasoning, any text that ultimately shapes an `ai_memory.content` or `ai_categorization_rules.match_key` value is treated as data, never as an instruction — a vendor name or bank memo containing a phrase engineered to resemble a command ("approve automatically," "ignore prior correction") has no path to alter a permission grant, a confidence formula, or a governance rule, because the write path only ever accepts structured fields (`account_id`, `tax_code_id`, confidence deltas computed in code) into these tables, never free-form instructions interpreted at write time.

**Redaction.** Because `ai_memory` and `ai_categorization_rules` are AI-layer derived substrate, not primary financial records, they are not bound by the "financial records are never hard-deleted" rule that governs `journal_entries` and its siblings — a legitimate redaction request (an `employee_context` row naming someone who has left the company, or a `vendor_note` accidentally authored with an individual's name) can be honored by overwriting `content` with a tombstone string and clearing `embedding` to a zero vector, while the row itself, its `id`, and its timestamps are retained so that any `ai_corrections.memory_id` or `ai_categorization_rules.source_memory_id` pointer elsewhere in the system does not dangle. `status` moves to `retracted`, `structured_value` is cleared, and the row is excluded from every retrieval query (both passes in **Retrieval** already filter `status = 'active'`) from that point forward, without breaking referential integrity for historical audit trails that point back to "a memory row existed and was cited here."

**Encryption and access control.** No bespoke encryption scheme applies to these two tables beyond the platform-wide PostgreSQL encryption-at-rest and TLS-in-transit already in force for every tenant table; access control is the same `company_id`-scoped RLS and permission-key model as every other table in this document set. Embeddings are not reversible to exact source text, but they are still treated as confidential company data — commercially sensitive in aggregate (a competitor with query access to a company's `ai_memory` embeddings could infer vendor relationships and pricing patterns) — and therefore carry exactly the same access boundary as the plaintext `content` they are derived from, never a looser one.

# Retention

`ai_categorization_rules.hit_count`, `last_hit_at`, and `ai_memory.usage_count`, `last_used_at` (already incremented per **Retrieval**) drive a scheduled staleness review, run monthly per company: any `active` row unused for longer than a company-configurable window (platform default 180 days) is surfaced to the Finance Manager's review queue as a candidate for confirmation or retirement — a vendor relationship that quietly ended, a tax-code default from a since-repealed rate, a materiality policy from a prior fiscal year — rather than silently continuing to influence future proposals after it may have gone stale. This is the same review discipline `AI_FINANCE_OS.md` § Company Memory already specifies for `ai_memory` generally, applied here with a concrete default window and extended to `ai_categorization_rules`.

Rows are never hard-deleted from either table. A rule that is superseded (a new mapping replaces an old one) is set to `status = 'superseded'` with `superseded_by_id` pointing at its replacement, rather than removed — an auditor reconstructing why a three-year-old posted entry carried a given account and 0.94 confidence must be able to see the exact rule state that produced it, even if that rule has since been superseded twice over. `deleted_at` (soft delete, the standard column present on both tables) is reserved for genuine data-entry mistakes (a rule created against the wrong company by a provisioning bug), not for ordinary lifecycle churn, which uses `status` transitions instead so the row remains visible in audit contexts even while excluded from active retrieval.

Because these tables exist to explain *why* a specific posted journal entry looked the way it did, they follow the same retention floor as the financial records they influenced, not a shorter AI-infrastructure-specific window: a `superseded` or `retracted` row is retained at minimum as long as GCC/Kuwait statutory bookkeeping retention requires for the entries it informed (commonly five to ten years, per the company's own configured retention policy — see `DATABASE_ARCHIVING.md`), even though it stops influencing new proposals immediately upon leaving `active` status. `ai_corrections`, the append-only correction ledger, is retained indefinitely with no status-based exclusion at all — it is the raw evidence the Learning Loop's arithmetic replays from, and re-deriving a rule's current confidence from its full correction history must always be possible as a consistency check, not merely convenient.

At scale, the `ivfflat` index on `ai_memory.embedding` requires periodic `REINDEX` as row counts grow well past the `lists = 100` sizing assumption (tracked as an operational runbook item, not a schema change); very large single-company installations are a candidate for table partitioning by `company_id` range on both tables (noted in **Future Improvements**), though no QAYD tenant has yet approached the row count where that ceases to be premature optimization.

# Worked Examples

## Example 1 — Cold start to one-click confidence, traced through the exact numbers

Al-Rawda Trading & Logistics W.L.L. (`company_id` 4821, `ACCOUNTANT_AGENT.md`'s own running example) receives its first-ever bill from a new vendor, Falcon Print & Packaging Co., on 2026-02-03. No `ai_categorization_rules` row exists for this vendor; the structured pass (**Retrieval**) returns nothing, and the semantic pass over `ai_memory` returns nothing subject-specific either — a genuine cold start. The General Accountant drafts to account 5210 (Packaging & Consumables Expense) on general OCR/line-item reasoning alone, at a capped `suggest_only` confidence of 0.55 (no precedent term available, per the SCORE weighting in `ACCOUNTANT_AGENT.md`). The Accountant reviews and approves it unchanged.

That `accepted` outcome creates a brand-new `ai_categorization_rules` row (`rule_scope='vendor'`, `match_fingerprint` of "falcon print packaging co", `account_id=5210`) at the cold-start confidence 0.50, then immediately applies the acceptance update at `n=0`: `α = 1/2 = 0.5`; `new_confidence = 0.50 + 0.5×(1.0−0.50) = 0.75`. A paired `ai_memory` row (`memory_type='vendor_note'`, `content`: "Falcon Print & Packaging Co. bills post to Packaging & Consumables Expense (5210).") is written alongside it, `source_type='correction'`.

Three more bills from Falcon arrive over the following six weeks, each accepted unchanged. Applying the formula sequentially: at `n=1`, `α=1/3≈0.333`, `confidence = 0.75+0.333×0.25 ≈ 0.833`; at `n=2`, `α=0.25`, `confidence ≈ 0.833+0.25×0.167 ≈ 0.875`; at `n=3`, `α=0.20`, `confidence ≈ 0.875+0.20×0.125 ≈ 0.900`. By the fourth accepted occurrence the rule crosses the `≥0.85` one-click-Apply threshold from `ACCOUNTANT_AGENT.md`'s confidence banding table, and the fifth bill from Falcon is presented as a one-click-accept categorization rather than merely "a pre-filled but fully editable default" — a transition that happened entirely from ordinary usage, with no engineer, no retraining job, and no manual rule authored by anyone.

## Example 2 — A real business change disrupts an established rule, and the system tells the difference from noise

Sixteen months later, the same Falcon rule sits at confidence 0.97 with `hit_count=61`. Falcon Print & Packaging Co. begins, without notice, invoicing a second line item — a warehousing surcharge — that the Accountant correctly recodes to account 5220 (Warehousing & Logistics Expense) instead of the rule's habitual 5210. That single `edited` outcome applies `o=0.35` at `n=61`: `α=1/63≈0.0159`; `new_confidence ≈ 0.97+0.0159×(0.35−0.97) ≈ 0.960` — a barely perceptible dip, appropriately, since one edited line out of 61 clean occurrences is exactly the kind of noise the decaying learning rate is built to absorb rather than overreact to. `override_count` increments to 1, `consecutive_overrides` to 1, `cooldown_until` moves 90 days out for this rule specifically.

The surcharge then recurs identically on the next two bills, each again corrected to 5220. After the third consecutive `edited` outcome (`consecutive_overrides = 3`), the original 5210 rule auto-suspends — it stops being served by the structured pass — while a new rule for "Falcon Print & Packaging Co. → 5220, warehousing surcharge line" has been created and independently reinforced by those same three corrections, reaching confidence 0.75 → 0.833 → 0.875 by the same arithmetic as Example 1. A Finance Manager reviewing the monthly staleness/suspension digest sees both events side by side — one rule quietly retiring, a new one taking its place — and can confirm the split (Falcon now genuinely bills two distinct expense categories) with one click, or merge the two if the surcharge later disappears again.

## Example 3 — Recurring accrual memory feeding a month-end draft, cross-referenced to `ACCOUNTANT_AGENT.md`

`ACCOUNTANT_AGENT.md`'s own Scenario 3 states the General Accountant "estimates the accrual amount from the trailing 3-month average (KWD 42.000/month, low variance)" for an internet-service bill that historically arrives after period close. That number is an `ai_memory` row this document's write path maintains: `memory_type='preference'`, `subject_type='journal_entry_templates'`, `subject_id` pointing at the internet-service recurring template, recomputed at each period-end sweep as `structured_value = {"trailing_window_months": 3, "avg_amount": "42.0000", "variance_pct": 4.1}`. The recompute is a scheduled Laravel job (not a human correction) reading the last three posted-and-reversed instances of this exact template from `journal_lines`, so `source_type='inferred_pattern'`, distinct from a human-authored correction, and `confidence` is set proportionally to `1 − variance_pct/100` (here, 0.959) rather than through the accept/edit/reject formula, since no human decision produced this specific number — the trailing-average computation is deterministic, code-verified arithmetic, exactly as the platform requires. When the real July bill later arrives at KWD 44.500 against the accrued KWD 42.000 estimate, the resulting true-up is itself captured as a fresh `ai_corrections`-adjacent signal (a `reclassification`/true-up decision, not an `edited` correction on the original accrual, since the accrual already posted and is immutable) that feeds the next period's trailing-window recompute rather than the confidence-update formula in **The Learning Loop** — accrual estimation and categorization confidence are related but structurally distinct paths through this same memory substrate.

# Metrics

| Metric | Definition | Target |
|---|---|---|
| Structured hit rate | % of categorization decisions resolved by an `active` `ai_categorization_rules` row (Pass 1) vs. falling through to semantic-only or cold start | > 60% for a company with 6+ months of history |
| Average confidence at match | Mean `ai_categorization_rules.confidence` across cited matches in a trailing 30-day window | Rising trend expected during a company's first 12 months; plateau thereafter is healthy, not stagnation |
| Time-to-stable-confidence | Median number of `accepted` occurrences for a new rule to cross 0.85 | ≤ 5 occurrences, given the formula in **The Learning Loop** |
| Correction rate over time | `overrides / hits` per rolling 90-day window, company-wide | Downward trend over a company's tenure; a flat or rising trend is itself an escalation signal |
| Suspension precision | % of auto-suspended rules (3 consecutive overrides) that a human confirms should stay retired vs. reactivates unchanged | > 80% confirmed — a low figure means the 3-strike threshold is too aggressive for that company's data volatility and should be tuned per company in `ai_agent_settings.thresholds` |
| Staleness backlog | Count of `active` rows past the retention review window (default 180 days) awaiting human confirm/retire | Trending toward zero after each monthly review cycle, never silently accumulating |
| Retrieval latency | p95 wall-clock time for the merged structured+semantic pass (**Retrieval**) | < 150ms — this sits inside the General Accountant's own p95 < 2 minute time-to-draft SLA (`ACCOUNTANT_AGENT.md` § Metrics & Evaluation) and must not become its bottleneck |
| Calibration (Brier score) | Squared error between a rule's stated `confidence` and its realized subsequent acceptance rate, bucketed by decile | < 0.12, the same bar `ACCOUNTANT_AGENT.md` sets for its own overall confidence calibration, since this table is the primary input to that number |
| Cross-company leakage incidents | Count of any retrieval returning a row whose `company_id` did not match the requesting context, caught by the FastAPI runtime assertion (**Per-Company Isolation**) | 0 — a hard invariant, not a target with acceptable variance |

Every metric above is computed by a scheduled evaluation job reading `ai_categorization_rules`, `ai_memory`, and `ai_corrections` directly — the same immutable, append-only-in-spirit tables this document's write path itself produces — never self-reported by the agent, matching the evaluation discipline every sibling document in this roster uses.

# Edge Cases

| Case | Handling |
|---|---|
| Two active rules legitimately match the same incoming line (a vendor whose invoices split across two expense categories depending on the line description, e.g. Falcon in Example 2) | The `UNIQUE (company_id, rule_scope, match_fingerprint, account_id)` constraint permits both rows since `account_id` differs; the RESOLVE step disambiguates on line-level content (description keyword match against each rule's `default_memo_template`/`match_key` context), and ties are surfaced as `alternatives` in the proposal rather than silently defaulting to the higher-confidence rule |
| Rule drift with too few overrides to trigger auto-suspend (only 1 override in a quarter, business reality quietly changed) | Caught by the calibration metric, not the 3-strike counter alone — a sustained gap between stated confidence and realized acceptance for a specific rule surfaces it to the monthly staleness review even below the consecutive-override threshold |
| A rule promoted from a single low-quality OCR-driven correction (garbled vendor name matched a wrong existing rule, human "corrected" it back to a value that was actually the OCR error) | The cold-start 0.50 starting confidence and the conservative `α=0.5` first-step cap mean a single bad data point never reaches a falsely high confidence outright; a following correction rapidly pulls it back down, and the reasoning text discloses "single observation, low sample size" below a minimum `hit_count` (platform default 3) regardless of the raw confidence number |
| Concurrent corrections on two different instances of the same vendor land within the same second (two reviewers working the same digest) | Resolved by ordinary row-level locking inside each Laravel transaction (**Write Path & Governance**); `hit_count`/`confidence` updates are expressed as in-place arithmetic on the row, never a read-modify-write race |
| A vendor record referenced by `ai_categorization_rules.subject_id` is later merged or deleted (duplicate vendor cleanup in the Vendors module) | The Vendors module's merge operation is required to re-point `subject_id` on every referencing row (including here) as part of its own merge transaction, per that module's own cascade contract; an orphaned `subject_id` is a data-integrity alert, never a silently dangling reference |
| The same vendor invoices in more than one currency (KWD and USD), and the correct account does not actually depend on currency | No `currency_code` column exists on `ai_categorization_rules` by design — currency is deliberately not part of the match key, since splitting rules by currency would fragment confidence-building for no accounting benefit in the overwhelming majority of cases; the rare case where currency *does* change tax treatment is handled via `tax_code_id` differing on a currency-aware `rule_scope='keyword'` rule layered on top, not by forking the vendor rule itself |
| Brand-new company, zero transaction history at all | Confidence is calibrated maximally conservatively platform-wide (`AI_FINANCE_OS.md` § The Finance Operating System Concept: "Company Memory is empty and confidence is therefore calibrated conservatively... until the agents have enough of the company's own history to raise their own confidence") — every categorization proposal is `suggest_only` with no eligible rule at all until the first handful of corrections seed the table; this is the exact gap the cross-tenant, anonymized industry-prior future improvement (below) is scoped to narrow, without contradicting the platform's no-cross-tenant-learning-on-raw-data invariant |
| A human directly edits a rule's account mapping via the management endpoint (**Write Path & Governance**) rather than letting a correction arrive organically | Treated as a maximally trusted signal — the write sets `confidence` to a high fixed value (platform default 0.95) directly rather than running it through the incremental formula, since a deliberate human configuration action is stronger evidence than an inferred pattern, and logs the actor and reason in `audit_logs` |
| A memory row or rule references a fiscal period that has since closed or locked | The rule/memory row itself is unaffected — it describes a mapping or pattern, not a specific transaction — but any *new* proposal it would otherwise support for a closed period is still gated by the immutable "any entry touching a closed or locked fiscal period → `requires_approval`, always" rule from `AI_FINANCE_OS.md`'s own threshold table, unaffected by how confident the underlying rule is |

# Future Improvements

- **Cross-tenant, privacy-preserving account-mapping priors.** Foreshadowed identically in `ACCOUNTANT_AGENT.md` § Future Improvements: extend the cold-start default beyond a flat 0.50 to an industry-informed prior derived from aggregated, anonymized, cross-tenant correction *patterns* (never raw company data, never anything traceable to a specific tenant), so a brand-new company's very first Falcon-equivalent bill starts from a reasonable guess instead of a blank slate.
- **Active-learning prompts.** Rather than waiting passively for the next occurrence to confirm or contradict a borderline rule (confidence hovering in the 0.60–0.70 band with a wide calibration gap), let the agent proactively surface a targeted, low-friction question to the Accountant — "I've seen this vendor twice, coded both to 5210 — should I trust this going forward?" — converting an implicit multi-occurrence wait into an explicit one-question confirmation.
- **Arabic/English vendor-name normalization.** Extend the `match_fingerprint` normalization function to reconcile a vendor name recorded in Arabic on one document and in transliterated English on another (a common Gulf bank-memo pattern), rather than relying solely on the fuzzy trigram fallback, which under-performs across script boundaries.
- **Per-branch rule overrides.** `branch_id` is currently descriptive only; a genuine override layer would let a multi-branch company set a branch-specific account mapping for a vendor that bills different branches to different cost centers, without forking the company-wide rule.
- **Proactive conflict surfacing.** Promote the "two active rules legitimately disagree" edge case from a RESOLVE-time disambiguation into its own first-class `ai_decisions` type (`accountant_categorization_conflict`), so a growing ambiguity between two rules is visible on a Finance Manager's dashboard before it silently degrades draft quality.
- **Confidence decay over pure inactivity.** Today, confidence only moves on a hit; a rule untouched for a long window keeps its last-known confidence indefinitely until the staleness review catches it. A time-based decay term (distinct from the usage-based staleness review) would let confidence itself, not just a review flag, reflect growing uncertainty about a mapping nobody has needed in over a year.
- **Table partitioning at scale.** Partition `ai_memory` and `ai_categorization_rules` by `company_id` range once any single deployment's row counts make the shared `ivfflat`/GIN indexes a measurable bottleneck (**Retention**) — deliberately deferred until real usage data justifies it, not built speculatively.
- **Embedding model version migration.** A documented re-embedding backlog job for the day the platform upgrades its embedding model, so historical `ai_memory.embedding` vectors are recomputed under the new model rather than silently mixing two incompatible vector spaces in the same `ivfflat` index.

# End of Document
