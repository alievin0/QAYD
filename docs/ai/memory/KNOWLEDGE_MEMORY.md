# Knowledge Memory — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: KNOWLEDGE_MEMORY
---

# Purpose

Knowledge Memory is the shared external knowledge base that every agent in QAYD's autonomous finance
workforce reasons against whenever a decision touches a standard, a statute, a regulator's rule, or a
"how do I do this in QAYD" question. It stores IFRS standards and interpretations, GCC VAT frameworks
(ZATCA's Saudi regime first, with the UAE, Bahrain, Oman, and a dormant Kuwait/Qatar posture tracked
alongside it), Kuwait labor law, PIFSS social-security and WPS payroll obligations, ZATCA e-invoicing
technical and business rules, general accounting-standard interpretive guidance, and platform how-to
content — each item versioned, dated, and traceable to a primary source.

Company Memory (`ai_memory`, specified in `AI_FINANCE_OS.md` under **Company Memory** and **Learning
Loop`) answers "what does *this* company do." Knowledge Memory answers "what does the *law, the
standard, or the platform* say" — a question whose correct answer is identical for every tenant asking
it (subject only to jurisdiction), and that does not change because one company's bookkeeper made an
edit. This is the load-bearing distinction that shapes every design decision in this document:

| | Company Memory (`ai_memory`) | Knowledge Memory (this document) |
|---|---|---|
| Ownership | One row per company; `company_id NOT NULL` | Core content is platform-wide; no `company_id` on the base tables |
| Who can change it | Any authorized user's correction, retrieved back for that company only | Only a named platform curator, through a versioned review pipeline |
| How it changes | **Learning Loop** — accepted/edited/rejected `ai_decisions` become memory automatically | **Curation pipeline** — regulation-feed ingestion and human review, never automatic from a single company's action |
| Isolation concern | Keep Company A's data out of Company B's retrieval | Keep company data from leaking **into** the shared base at all, and keep per-company overlays from leaking **across** companies |
| Failure mode it prevents | The AI forgets a company's own quirks and repeats a corrected mistake | The AI cites a repealed rule, misattributes a jurisdiction, or asserts a rate/law with no traceable source |

Every agent that cites Knowledge Memory does so through the same `sources` contract used everywhere
else in the platform (see `AI_FINANCE_OS.md`, **Decision Engine**): a citation is a concrete, fetchable
`knowledge_chunks` row — a jurisdiction, an effective-date range, a review status, and a link back to
the primary source document — never a paraphrase of "general knowledge" with nothing behind it. This is
the citation contract referenced throughout the platform's fixed facts: **every AI output carries a
confidence score, explicit reasoning, and supporting documents/citations** — and for anything regulatory
or standards-based, "supporting documents" means a row in this document's schema.

Two forward references already exist in the agent roster and are honored exactly as written: the Tax
Advisor's "ingested regulation feed" (`TAX_AGENT.md`) and the Compliance Agent's "regulation knowledge
base (vector store)" with `kb_chunk_id` citations, a `draft | ingested | human_reviewed | superseded`
status lifecycle, and a confidence cap on unreviewed content (`COMPLIANCE_AGENT.md`, **Reasoning &
Prompt Strategy**). This document is the authoritative specification of that knowledge base — the
schema, governance, retrieval mechanics, and edge cases those two agent documents assumed.

# What Is Stored

Knowledge Memory stores **reference content with no tenant-specific fact in it** — content that is true
(or was true, for a given effective-date range) regardless of which QAYD company is asking. Six content
domains are in scope at launch, each a `domain` value on `knowledge_documents`:

| Domain | Examples | Primary issuing authorities |
|---|---|---|
| `ifrs_reporting` | IFRS 1–18, IAS 1–41 (as not superseded), IFRIC/SIC interpretations, IFRS for SMEs | IASB, IFRS Foundation |
| `gcc_vat` | ZATCA VAT Implementing Regulations, e-invoicing (Fatoora) technical/business rules, UAE FTA VAT Law + Executive Regulations, Bahrain NBR VAT Law, Oman VAT Law, GCC VAT Framework Agreement | ZATCA, FTA, NBR, Oman Tax Authority, GCC Secretariat |
| `kuwait_labor` | Kuwait Private Sector Labor Law No. 6 of 2010 and amendments, Ministry of Social Affairs and Labor (MSAL) circulars | Kuwait MSAL, Kuwait National Assembly |
| `pifss_wps` | PIFSS Law No. 61 of 1976 and amendments, contribution-rate and salary-cap schedules, WPS file-format and submission circulars | PIFSS, Kuwait Central Bank (WPS infrastructure) |
| `tax_kuwait` | Kuwait corporate income tax on foreign bodies (Amiri Decree 3/1955 as amended), Zakat Law 46/2006, dormant Kuwait VAT readiness notes | Kuwait Ministry of Finance |
| `platform_howto` | "How closing a fiscal period works," "How to read your close-readiness score," glossary entries (e.g., "what is a reversing entry") | QAYD platform content team |

`jurisdiction` is `ISO 3166-1 alpha-2` (`SA`, `AE`, `BH`, `OM`, `KW`, `QA`) for anything jurisdiction-
specific, or the literal value `*` for content that applies across jurisdictions regardless of country
— IFRS standards and platform how-to content are almost always `*`; GCC VAT and labor/payroll content
are almost always a specific country code. A single `knowledge_documents` row never mixes jurisdictions;
a GCC-wide framework agreement that individual states implement differently is stored once as the
framework (`jurisdiction = '*'`, `domain = 'gcc_vat'`) and once per state's own implementing regulation
(`jurisdiction = 'SA' | 'AE' | 'BH' | 'OM'`), so retrieval can request "everything for Saudi Arabia" and
get both the country-specific implementing text and the cross-border framework it implements.

Content types, tracked as `document_type` on `knowledge_documents`:

| `document_type` | Nature | Typical review bar before `published` |
|---|---|---|
| `statute` | Primary law (an Act, a Royal Decree, an Amiri Decree) | Highest — legal review required |
| `regulation` | Implementing regulation issued under a statute (ZATCA's VAT Implementing Regulations) | High — legal/tax review required |
| `standard` | IFRS/IAS/IFRIC/SIC text and Basis for Conclusions excerpts | High — technical accounting review required |
| `circular` | An authority's bulletin, FAQ, or clarification (a PIFSS rate notice, a ZATCA e-invoicing FAQ) | Medium — subject-matter reviewer sign-off |
| `interpretive_guidance` | QAYD's own platform-authored explanation of how a standard or rule applies in practice | Medium — internal technical reviewer sign-off |
| `howto_guide` | In-app help content, no legal weight | Light — content editor sign-off |
| `glossary_entry` | A single defined term ("accrual," "reversing entry," "input VAT") | Light — content editor sign-off |

What is explicitly **never** stored here: a company's own transactions, documents, vendor/customer
names, employee data, financial figures, or anything that originated inside a tenant's `company_id`
scope. That data lives in the operational tables and in Company Memory (`ai_memory`). The one bridge
between the two — a company's own annotation *about* a piece of shared knowledge — is the narrow,
explicitly tenant-scoped `knowledge_company_overlays` table (see **Per-Company Isolation**), which never
mutates the shared content itself.

# Data Model (PostgreSQL + vector/embedding schema DDL)

Knowledge Memory is five tables: the document catalog, the retrievable chunk/embedding table, the
versioned change log that makes curation auditable, the feedback queue that lets anyone flag a stale or
wrong citation without being able to edit it, and the narrow per-company overlay table.

```sql
-- ============================================================================
-- 1. knowledge_documents — one row per titled source, versioned over time
-- ============================================================================
CREATE TABLE knowledge_documents (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                  VARCHAR(80)  NOT NULL,          -- stable slug, e.g. 'ifrs-16-leases', 'zatca-vat-impl-regs'
    domain                VARCHAR(30)  NOT NULL
        CHECK (domain IN ('ifrs_reporting','gcc_vat','kuwait_labor','pifss_wps',
                           'tax_kuwait','platform_howto')),
    document_type         VARCHAR(30)  NOT NULL
        CHECK (document_type IN ('statute','regulation','standard','circular',
                                  'interpretive_guidance','howto_guide','glossary_entry')),
    jurisdiction           VARCHAR(2)   NOT NULL DEFAULT '*',   -- ISO 3166-1 alpha-2, or '*' cross-border
    title_en                VARCHAR(300) NOT NULL,
    title_ar                VARCHAR(300) NULL,
    official_reference       VARCHAR(160) NOT NULL,       -- 'IFRS 16', 'Law No. 6 of 2010', 'ZATCA Resolution 5-3-2021'
    issuing_authority         VARCHAR(120) NOT NULL,       -- 'IASB', 'ZATCA', 'Kuwait MSAL', 'PIFSS', 'QAYD Platform'
    source_url                 TEXT NULL,                  -- link to the authoritative primary source
    source_license              VARCHAR(30) NOT NULL DEFAULT 'summary_only'
        CHECK (source_license IN ('public_domain','licensed_excerpt','summary_only','platform_authored')),
    language                     VARCHAR(10) NOT NULL DEFAULT 'en'
        CHECK (language IN ('en','ar','bilingual')),
    version_number                INTEGER NOT NULL DEFAULT 1,
    status                         VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','ingested','human_reviewed','published','superseded','retracted')),
    effective_from                  DATE NOT NULL,
    effective_to                    DATE NULL,             -- NULL = still in force
    superseded_by_document_id         BIGINT NULL REFERENCES knowledge_documents(id),
    source_document_hash                VARCHAR(64) NULL,  -- SHA-256 of ingested raw source, for de-dup
    ingested_by_connector                VARCHAR(60) NULL,  -- 'ifrs_foundation_feed', 'zatca_circular_feed', 'manual'
    reviewed_by                            BIGINT NULL REFERENCES users(id),
    reviewed_at                            TIMESTAMPTZ NULL,
    published_by                           BIGINT NULL REFERENCES users(id),
    published_at                           TIMESTAMPTZ NULL,
    retracted_reason                       TEXT NULL,
    created_at                             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                             TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, version_number)
);
CREATE INDEX idx_knowledge_documents_domain_jur ON knowledge_documents(domain, jurisdiction, status);
CREATE INDEX idx_knowledge_documents_effective ON knowledge_documents(effective_from, effective_to);
CREATE UNIQUE INDEX uq_knowledge_documents_hash ON knowledge_documents(source_document_hash)
    WHERE source_document_hash IS NOT NULL;
-- No company_id: this table is platform-wide by design (see Per-Company Isolation).

-- ============================================================================
-- 2. knowledge_chunks — the retrievable, embedded passage-level unit
-- ============================================================================
CREATE TABLE knowledge_chunks (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    document_id           BIGINT NOT NULL REFERENCES knowledge_documents(id),
    chunk_index            INTEGER NOT NULL,             -- ordering within the document
    section_label            VARCHAR(80) NULL,            -- '§63', 'Article 12', 'Box 4', 'Table 2'
    content                    TEXT NOT NULL,             -- passage text, in content_language
    content_language            VARCHAR(2)  NOT NULL DEFAULT 'en' CHECK (content_language IN ('en','ar')),
    english_gloss                 TEXT NULL,              -- machine-assisted English rendering when content_language='ar'
    citation_text                  VARCHAR(300) NOT NULL, -- human-readable citation string shown to users
    token_count                     INTEGER NOT NULL,
    embedding                        VECTOR(1536) NOT NULL,
    embedding_model                   VARCHAR(60) NOT NULL,   -- e.g. 'voyage-3-large', versioned for re-embed migrations
    content_tsv                        tsvector GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED,
    -- denormalized from the parent document for fast, index-friendly filtering without a join on the hot path:
    domain                              VARCHAR(30) NOT NULL,
    jurisdiction                         VARCHAR(2)  NOT NULL DEFAULT '*',
    status                                VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','ingested','human_reviewed','published','superseded','retracted')),
    effective_from                        DATE NOT NULL,
    effective_to                          DATE NULL,
    superseded_by_chunk_id                  BIGINT NULL REFERENCES knowledge_chunks(id),
    usage_count                              INTEGER NOT NULL DEFAULT 0,
    last_used_at                              TIMESTAMPTZ NULL,
    created_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (document_id, chunk_index)
);
CREATE INDEX idx_knowledge_chunks_embedding ON knowledge_chunks
    USING ivfflat (embedding vector_cosine_ops) WITH (lists = 200);
CREATE INDEX idx_knowledge_chunks_tsv ON knowledge_chunks USING GIN (content_tsv);
CREATE INDEX idx_knowledge_chunks_filter ON knowledge_chunks(domain, jurisdiction, status, effective_from);
CREATE INDEX idx_knowledge_chunks_document ON knowledge_chunks(document_id, chunk_index);
-- No company_id: retrievable by every tenant's agents identically (see Per-Company Isolation).

-- ============================================================================
-- 3. knowledge_document_versions — the append-only, versioned change log
-- ============================================================================
CREATE TABLE knowledge_document_versions (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    document_id          BIGINT NOT NULL REFERENCES knowledge_documents(id),
    version_number         INTEGER NOT NULL,
    change_type              VARCHAR(30) NOT NULL
        CHECK (change_type IN ('new_document','content_update','effective_date_change',
                                'correction','retraction','republish')),
    change_summary             TEXT NOT NULL,            -- human-readable, e.g. "ZATCA raised the e-invoicing
                                                           -- threshold; effective_from updated to 2027-01-01"
    previous_status              VARCHAR(20) NULL,
    new_status                    VARCHAR(20) NOT NULL,
    diff_ref                       TEXT NULL,             -- pointer to a stored textual diff (Cloudflare R2 object key)
    changed_by                       BIGINT NOT NULL REFERENCES users(id),
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_knowledge_doc_versions_document ON knowledge_document_versions(document_id, version_number);
-- This is the knowledge base's own audit trail — distinct from the foundation `audit_logs` table,
-- which records tenant-facing mutations. knowledge_document_versions is platform-wide and append-only;
-- a row is never edited or deleted once written.

-- ============================================================================
-- 4. knowledge_citation_feedback — the "something looks wrong" signal queue
-- ============================================================================
CREATE TABLE knowledge_citation_feedback (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    knowledge_chunk_id     BIGINT NOT NULL REFERENCES knowledge_chunks(id),
    reported_by_type         VARCHAR(20) NOT NULL
        CHECK (reported_by_type IN ('agent','company_user','platform_reviewer')),
    reported_by_company_id     BIGINT NULL REFERENCES companies(id),  -- set only if a company user reported it
    reported_by_user_id           BIGINT NULL REFERENCES users(id),
    reported_by_agent_code          VARCHAR(40) NULL REFERENCES ai_agents(code),
    reason                            VARCHAR(30) NOT NULL
        CHECK (reason IN ('outdated','incorrect','ambiguous','jurisdiction_mismatch','duplicate','other')),
    detail                             TEXT NULL,
    status                              VARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open','under_review','actioned','dismissed')),
    resolved_by                          BIGINT NULL REFERENCES users(id),
    resolved_at                           TIMESTAMPTZ NULL,
    resulting_version_id                    BIGINT NULL REFERENCES knowledge_document_versions(id),
    created_at                               TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_knowledge_feedback_status ON knowledge_citation_feedback(status, created_at);
CREATE INDEX idx_knowledge_feedback_chunk ON knowledge_citation_feedback(knowledge_chunk_id);
-- A feedback row never edits knowledge_chunks itself. It is a suggestion; see The Learning Loop.

-- ============================================================================
-- 5. knowledge_company_overlays — the ONLY tenant-scoped table in this document
-- ============================================================================
CREATE TABLE knowledge_company_overlays (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),     -- indexed; RLS-scoped
    branch_id             BIGINT NULL REFERENCES branches(id),
    knowledge_document_id   BIGINT NULL REFERENCES knowledge_documents(id),
    knowledge_chunk_id       BIGINT NULL REFERENCES knowledge_chunks(id),
    overlay_type               VARCHAR(30) NOT NULL
        CHECK (overlay_type IN ('internal_policy_note','applicability_clarification',
                                 'disagreement_flag','external_advisor_note')),
    content                       TEXT NOT NULL,
    status                          VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','retracted')),
    created_by                       BIGINT NULL REFERENCES users(id),
    updated_by                       BIGINT NULL REFERENCES users(id),
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                        TIMESTAMPTZ NULL,
    CHECK (knowledge_document_id IS NOT NULL OR knowledge_chunk_id IS NOT NULL)
);
CREATE INDEX idx_knowledge_overlays_company ON knowledge_company_overlays(company_id);
CREATE INDEX idx_knowledge_overlays_chunk ON knowledge_company_overlays(knowledge_chunk_id);
```

Two design choices deserve explicit justification because they depart from the simplest possible
schema. First, `knowledge_chunks` denormalizes `domain`, `jurisdiction`, `status`, `effective_from`, and
`effective_to` from its parent `knowledge_documents` row rather than requiring a join on every retrieval
query. Retrieval is the hottest path in this entire document — every agent task that touches tax,
compliance, payroll, or reporting calls it — and a vector similarity scan combined with a `WHERE`-clause
join against a second table on every one of potentially millions of chunks is a needless cost when the
filtering columns change only when a document is re-versioned (rare, human-gated, and already the moment
`knowledge_document_versions` writes a row that can trivially re-stamp its children). Second, the table
stores both a `pgvector` embedding column and a generated `tsvector` column: semantic retrieval alone
under-performs on exact regulatory terms of art (a query for "Box 4" or "PIFSS salary cap" benefits from
literal keyword matching that embeddings alone can blur), so retrieval combines both (see **Retrieval**).

# Per-Company Isolation

Every other memory document in this platform (Company Memory, and any conversational/session memory)
exists to keep one company's data from ever being visible to another. Knowledge Memory inverts the
concern: its base content is *supposed* to be identically visible to every company, so "isolation" here
means three narrower, still non-negotiable guarantees:

**1. No tenant data ever leaks into the shared base.** `knowledge_documents` and `knowledge_chunks` carry
no `company_id` column at all, and no code path that writes to them ever reads from a tenant-scoped
table. The only inputs to the ingestion pipeline (see **Write Path & Governance**) are regulation-feed
connectors and a platform curator's own authoring tools — never a company's `bills`, `invoices`,
`ai_memory`, or any other tenant table. This is enforced structurally, not by convention: the FastAPI
ingestion service's database role for these two tables has no `SELECT` grant on any tenant table, so
even a compromised or misconfigured ingestion job cannot read one company's data and accidentally fold a
fragment of it into shared content.

**2. Read access to the shared content is universal, not tenant-gated.** There is deliberately no Row
Level Security predicate on `knowledge_documents` or `knowledge_chunks` restricting which company can
read which row — every company's agents (and, through the search endpoint, every company's human users)
see the same `published` content for a given jurisdiction and domain. This mirrors how `ai_agents` (the
platform-wide agent catalog specified in `AI_FINANCE_OS.md`) has no RLS predicate either: a platform
catalog that is supposed to be identical for every tenant is not "more secure" for having artificial
per-tenant partitioning bolted onto it — it is simply shared, and the guarantees that matter are (1)
above and (3) below.

**3. Overlays are fully tenant-isolated, exactly like Company Memory.** `knowledge_company_overlays` is
the one table in this document that behaves like every other tenant table in the platform: `company_id`
is `NOT NULL`, indexed, and enforced by Row Level Security identical in shape to `ai_memory`'s:

```sql
CREATE POLICY tenant_isolation_knowledge_overlays ON knowledge_company_overlays
    USING (company_id = current_setting('app.current_company_id')::bigint);
```

A note Company A's Finance Manager writes on an IFRS 16 chunk ("our external auditor requires an
additional disclosure here") is retrievable only when an agent is reasoning on Company A's behalf. It is
never visible to Company B, never aggregated into the shared chunk's `citation_text`, and never mutates
`knowledge_chunks.content` — it is always presented as a clearly labeled, additional, non-authoritative
annotation layered on top of the shared citation (see **Retrieval**), the same way Company Memory's
`ai_memory` rows are retrievable evidence rather than an invisible thumb on the scale.

# Retrieval (RAG / semantic + structured)

Retrieval is a **hybrid** of four narrowing steps, run for every agent task that needs regulatory or
how-to grounding — matching the "hybrid RAG" mechanism already named in `COMPLIANCE_AGENT.md`:

```
 Agent needs grounding for: {free-text situation, as_of_date, company's jurisdiction(s), domain}
              │
              ▼
   ┌─────────────────────────────┐
   │ 1. Structured pre-filter       │  domain = requested; jurisdiction IN (company's jurisdictions, '*');
   │    (SQL WHERE, index-backed)    │  effective_from <= as_of_date AND (effective_to IS NULL OR effective_to >= as_of_date);
   │                                   │  status IN ('published') by default, ('human_reviewed','published') if the
   │                                   │  caller explicitly allows pre-publication content for a low-stakes preview
   └──────────────┬───────────────────┘
                  ▼
   ┌─────────────────────────────┐
   │ 2a. Vector similarity search    │  cosine distance over knowledge_chunks.embedding (ivfflat), top ~40 candidates
   │ 2b. Keyword search (parallel)    │  ts_rank over content_tsv (GIN), top ~40 candidates, for exact terms of art
   └──────────────┬───────────────────┘
                  ▼
   ┌─────────────────────────────┐
   │ 3. Re-rank & merge               │  score = 0.7 × normalized_cosine_similarity + 0.3 × normalized_ts_rank,
   │                                     │  + jurisdiction-exact-match boost, + status-authority boost
   │                                     │  (published > human_reviewed > ingested > draft)
   └──────────────┬───────────────────────┘
                  ▼
   ┌─────────────────────────────┐
   │ 4. Overlay merge (tenant-scoped)  │  LEFT JOIN knowledge_company_overlays for this company_id only,
   │                                       │  attached as supplementary, explicitly-labeled context — never
   │                                       │  replacing or re-ranking the base result
   └──────────────┬───────────────────────┘
                  ▼
        top_k chunks + any attached overlays returned to the agent's context assembly step,
        each one individually eligible to appear in the eventual ai_decisions.sources array
```

The effective-date filter is not optional and is the single most important predicate in this pipeline —
retrieval must answer "what was true as of the date this transaction/period concerns," not "what is true
today":

```sql
-- Core retrieval candidate query (structured pre-filter + keyword pass; vector pass runs alongside via
-- the application layer's ANN query against the same WHERE-filtered set through a combined index scan)
SELECT kc.id, kc.document_id, kc.citation_text, kc.content, kc.english_gloss, kc.status,
       kc.effective_from, kc.effective_to, kc.jurisdiction,
       ts_rank(kc.content_tsv, plainto_tsquery('simple', :query_text)) AS keyword_score
FROM knowledge_chunks kc
WHERE kc.domain = :domain
  AND kc.jurisdiction IN (:company_jurisdictions, '*')
  AND kc.effective_from <= :as_of_date
  AND (kc.effective_to IS NULL OR kc.effective_to >= :as_of_date)
  AND kc.status IN ('published')
ORDER BY keyword_score DESC
LIMIT 40;
```

**Tool contract.** Retrieval is exposed to every agent as one MCP tool, backed by a Laravel-fronted read
endpoint so that a single access-control chokepoint governs who can query the base — including the AI
service account itself:

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/knowledge/search` | `knowledge.read` | Hybrid semantic + keyword search, structured filters, returns ranked chunks + citation metadata |
| GET | `/api/v1/knowledge/documents/{id}` | `knowledge.read` | Fetch a document's metadata and full chunk list (for a human wanting to read the whole source) |
| POST | `/api/v1/knowledge/overlays` | `knowledge.overlay.manage` | Create a company-scoped overlay note on a document or chunk |
| GET | `/api/v1/knowledge/overlays` | `knowledge.overlay.manage` | List this company's own overlays |
| POST | `/api/v1/knowledge/citation-feedback` | `knowledge.read` | Flag a chunk as outdated/incorrect/ambiguous (see **The Learning Loop**) |

One narrow, deliberate exception to "every AI action goes through Laravel" applies here, and it is stated
precisely so it is never mistaken for a broader loophole: the **read-only vector similarity step itself**
(step 2a above) is executed by the FastAPI AI layer querying the shared Postgres `knowledge_chunks` table
directly, the same way an agent's retrieval step reads `ai_memory` embeddings directly during Company
Memory retrieval. This is consistent with the platform rule because that rule governs *writes* and
*business actions* — a non-tenant, no-financial-consequence reference-data read is not a proposal against
the ledger and carries nothing for Laravel's `FormRequest`/RBAC layer to validate beyond the `knowledge.read`
grant already checked when the agent's session was established. Every *write* to this schema — ingest,
review, publish, retract, and every tenant-scoped overlay mutation — goes exclusively through the Laravel
endpoints above, with no exception.

**Sample response** (`GET /api/v1/knowledge/search?domain=gcc_vat&jurisdiction=SA&as_of_date=2026-07-16&q=e-invoicing%20QR%20code%20requirement`):

```json
{
  "success": true,
  "data": {
    "results": [
      {
        "knowledge_chunk_id": 48213,
        "document_id": 210,
        "citation_text": "ZATCA E-Invoicing Business Rules v3, Simplified Tax Invoice §4.2 (QR code)",
        "jurisdiction": "SA",
        "domain": "gcc_vat",
        "status": "published",
        "effective_from": "2023-01-01",
        "effective_to": null,
        "content_language": "ar",
        "content": "...",
        "english_gloss": "A simplified tax invoice issued to a non-VAT-registered buyer must contain a TLV-encoded QR code with seller name, VAT number, timestamp, invoice total, and VAT total.",
        "relevance_score": 0.94,
        "source_url": "https://zatca.gov.sa/..."
      }
    ],
    "overlays": []
  },
  "message": "5 results",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 5, "cursor": null } },
  "request_id": "b3f0b6b0-2f0e-4a1a-9a1a-6b6b0b3f0b6b",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

# The Learning Loop (approved corrections become memory)

This section exists in every memory document in the platform, and in every other one it describes the
same mechanism: an accepted or edited AI proposal becomes durable, per-company behavior change (see
`AI_FINANCE_OS.md`, **Learning Loop**, which drives **Company Memory**). Knowledge Memory's version of
this section states, deliberately and explicitly, why that mechanism does **not** apply here, and what
runs in its place.

## Why this is not a per-company learning loop

A single company's bookkeeper editing a General Accountant proposal is good evidence about *that
company's* preference — it should never be treated as evidence about what IFRS 16 says, what ZATCA's
e-invoicing rule requires, or what PIFSS's contribution cap is this year. If Knowledge Memory updated
itself from individual company interactions the way `ai_memory` does, two failure modes follow
immediately: (1) a confidently wrong correction from one company's inexperienced user could silently
degrade the citation every other tenant in the same jurisdiction relies on, and (2) the platform would
have no defensible answer to "who approved this change to how QAYD represents the law" when a regulator
or an external auditor asks — an answer this document's entire governance model exists to guarantee (see
**Write Path & Governance**). The platform fact this document honors is stated in `DESIGN_CONTEXT.md`
itself: this is "versioned, not a user learning loop."

## The two signals that do feed the pipeline

Nothing about ruling out a per-company loop means Knowledge Memory is static. Two signal sources feed a
**human-gated curation pipeline** instead:

**1. Regulation-feed ingestion.** Scheduled or webhook-triggered connectors (`ingested_by_connector` on
`knowledge_documents`) pull primary-source changes — the IASB/IFRS Foundation's publication feed, ZATCA's
circular and technical-specification releases, GCC national gazette feeds, Kuwait MSAL and PIFSS
bulletins — and create a new `knowledge_documents` row (or a new `version_number` of an existing one) at
`status = 'ingested'`. This is identical in spirit to the "regulation-feed connector" already named in
`COMPLIANCE_AGENT.md`'s IFRS 18 worked scenario; this document is the schema that scenario writes into.

**2. Citation feedback.** Any agent that retrieves a chunk and finds its guidance inconsistent with
something else it trusts more (a newer chunk, a structured `tax_rules` entry that disagrees), and any
human user or platform reviewer who spots a citation that looks outdated, ambiguous, or simply wrong,
can write a `knowledge_citation_feedback` row (`POST /api/v1/knowledge/citation-feedback`, permission
`knowledge.read` — flagging requires no special privilege, by design, so the signal is cheap to raise).
**A feedback row never edits `knowledge_chunks` itself.** It only ever creates a queue entry a platform
curator triages.

## The curation pipeline, end to end

```
 Regulation-feed ingestion               Citation feedback (agent or human)
        │                                          │
        ▼                                          ▼
 knowledge_documents.status='ingested'    knowledge_citation_feedback.status='open'
 (+ knowledge_chunks drafted,                       │
    embedded, status='ingested')                     │
        │                                             │
        └───────────────────┬─────────────────────────┘
                             ▼
                ┌─────────────────────────────┐
                │ Platform curator triages      │  (Platform Content Curator role — see
                │ (reviews source, drafts/edits  │   Write Path & Governance; NOT a tenant role)
                │ chunk text + citation_text)      │
                └──────────────┬───────────────────┘
                               ▼
                     status → 'human_reviewed'
                     (a knowledge_document_versions row is written:
                      change_type, change_summary, changed_by)
                               │
                               ▼
                ┌─────────────────────────────┐
                │ Platform Compliance Reviewer   │  second sign-off required for domain IN
                │ (dual control for high-impact)  │  ('gcc_vat','kuwait_labor','pifss_wps','tax_kuwait')
                │                                   │  and for document_type IN ('statute','regulation','standard')
                └──────────────┬───────────────────────┘
                               ▼
                     status → 'published'; published_at, published_by set;
                     PREVIOUS version's effective_to is set and its status → 'superseded'
                     (never deleted — see Retention)
                               │
                               ▼
                agents now retrieve the new version by default for any as_of_date
                on/after its effective_from; historical as_of_date queries still
                resolve to whichever version was authoritative on that date
```

Two properties make this a genuine *curation* pipeline rather than a slow-motion version of a learning
loop. First, promotion is never automatic on volume or confidence — a chunk does not become
`human_reviewed` because ten agents independently retrieved it without complaint, the way a Company
Memory correction gains retrieval weight through repetition. It becomes `human_reviewed` because a named
platform curator reviewed it, full stop. Second, every promotion is versioned rather than edited in
place: `knowledge_document_versions` accumulates an append-only history, and a superseded chunk is never
overwritten — it is marked `superseded` and kept, because a transaction dated under the old rule must
remain explainable against the exact text that was authoritative on that date (see **Retention**,
**Edge Cases**).

## Confidence implications for consuming agents

Every agent's autonomy calculation (see `AI_FINANCE_OS.md`, **Decision Engine**) treats a citation's
`status` as a hard input, not a soft signal — this is the same rule `COMPLIANCE_AGENT.md` already states
for its own output: a decision resting on a `draft` or `ingested` (not yet `human_reviewed`) chunk is
capped at `confidence ≤ 0.60` and forced to `advisory`/`suggest_only`; only a `published` chunk (and, for
lower-stakes domains, a `human_reviewed` one) can support a decision that clears an `auto` threshold or
carries a `blocking` compliance flag. This cap is enforced once, centrally, in the Decision Engine's
autonomy formula — not re-implemented per agent — exactly as `AI_FINANCE_OS.md` describes for every other
input to that formula.

# Write Path & Governance

Nothing in this schema is writable by a tenant user or by an AI agent acting on a tenant's behalf, with
the sole exception of a company's own `knowledge_company_overlays` rows. Every other write is gated by a
platform-operator permission that exists outside the tenant RBAC roster entirely — no `Owner`, `CFO`, or
any other role from `DESIGN_CONTEXT.md`'s default-roles list can ever hold it, because these permissions
are not assignable within a company's own role management screen at all.

## Platform roles (not tenant roles)

| Platform role | Can do | Cannot do |
|---|---|---|
| **Platform Content Curator** | Ingest/edit `draft` and `ingested` documents and chunks; draft `citation_text`; triage `knowledge_citation_feedback` | Promote to `published`; act on `statute`/`regulation`/`standard` document types without a Compliance Reviewer's second sign-off |
| **Platform Compliance Reviewer** | Promote `human_reviewed` → `published`; retract a document; approve dual-control publications | Ingest raw content themselves (separation of drafting and approving) |
| **Platform Engineering (Knowledge Ops)** | Operate the embedding pipeline, re-embedding migrations, connector configuration | Author or approve content changes |

## Permission keys

| Permission key | Grants | Held by |
|---|---|---|
| `platform.knowledge.curate` | Ingest, edit `draft`/`ingested` rows | Platform Content Curator |
| `platform.knowledge.review` | Promote to `human_reviewed`; edit review notes | Platform Content Curator, Platform Compliance Reviewer |
| `platform.knowledge.publish` | Promote to `published`; required as the *second* sign-off for `statute`/`regulation`/`standard` document types | Platform Compliance Reviewer |
| `platform.knowledge.retract` | Set `status = 'retracted'`, record `retracted_reason` | Platform Compliance Reviewer |
| `knowledge.read` | Query `/api/v1/knowledge/search` and `/api/v1/knowledge/documents/{id}`; raise citation feedback | Every tenant role (Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, Read Only, External Auditor, …) and the AI service account |
| `knowledge.overlay.manage` | Create/edit/retract this company's own `knowledge_company_overlays` rows | Owner, CFO, Finance Manager, Senior Accountant, Auditor |

`knowledge.read` is deliberately close to universal — restricting *read* access to regulatory text and
how-to content protects nothing and would actively harm the platform's own "AI cites its sources"
promise, since a human must be able to click through and read the same passage the AI cited. What is
tightly restricted is the *write* path, and specifically the ability to make shared content
authoritative for every tenant at once.

## Dual control for high-impact content

`domain IN ('gcc_vat','kuwait_labor','pifss_wps','tax_kuwait')` or `document_type IN ('statute',
'regulation','standard')` requires two distinct human accounts to move a document from `ingested` to
`published` — the curator who drafted/edited the content (`platform.knowledge.review`) can never also be
the reviewer who publishes it (`platform.knowledge.publish`) for these classes, enforced at the service
layer by comparing `reviewed_by` against the acting user on the publish call and rejecting a match.
`platform_howto` and `glossary_entry` content, carrying no legal or financial consequence, may be
published by a single curator holding both permissions.

## Endpoints (platform-admin surface)

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/api/v1/platform/knowledge/documents` | `platform.knowledge.curate` | Create a new document (status starts `draft`) |
| POST | `/api/v1/platform/knowledge/documents/{id}/chunks` | `platform.knowledge.curate` | Add/edit chunks, triggers re-embedding |
| POST | `/api/v1/platform/knowledge/documents/{id}/review` | `platform.knowledge.review` | Promote to `human_reviewed`; writes a `knowledge_document_versions` row |
| POST | `/api/v1/platform/knowledge/documents/{id}/publish` | `platform.knowledge.publish` | Promote to `published`; supersedes the prior version |
| POST | `/api/v1/platform/knowledge/documents/{id}/retract` | `platform.knowledge.retract` | Set `retracted`, require `retracted_reason` |
| GET | `/api/v1/platform/knowledge/feedback` | `platform.knowledge.review` | List open `knowledge_citation_feedback` for triage |

**Sample request — publishing a reviewed document:**

```json
POST /api/v1/platform/knowledge/documents/210/publish
{
  "reviewer_confirmation": true,
  "change_summary": "ZATCA Circular 12/2026 raises the simplified-invoice QR code data fields; adds
                     mandatory buyer VAT number field for B2B simplified invoices effective 2027-01-01."
}
```

```json
{
  "success": true,
  "data": {
    "document_id": 210,
    "version_number": 4,
    "status": "published",
    "published_at": "2026-07-16T10:03:11Z",
    "superseded_document_version": 3,
    "superseded_effective_to": "2026-12-31"
  },
  "message": "Document published; version 3 superseded effective 2026-12-31.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a2b3c4d-5e6f-7089-90ab-cdef01234567",
  "timestamp": "2026-07-16T10:03:11Z"
}
```

## Ingestion pipeline mechanics

```
Primary source (IASB / ZATCA / MSAL / PIFSS / gazette) or curator upload
              │
              ▼
   Connector fetch/parse → raw text + source_document_hash computed
              │
              ▼
   Duplicate check: does source_document_hash already exist? ──yes──▶ discard (already ingested)
              │ no
              ▼
   knowledge_documents row created, status='ingested'
              │
              ▼
   Chunking (semantic/section-aware splitter, target 200-500 tokens per chunk)
              │
              ▼
   PII redaction pass (see Privacy & PII) on each chunk
              │
              ▼
   Embedding generation (embedding_model recorded per chunk)
              │
              ▼
   knowledge_chunks rows created, status='ingested', inherits parent's effective dates
              │
              ▼
   Queued for curator triage (see The Learning Loop)
```

Every ingestion run is itself logged to `ai_logs` (token counts, connector name, latency, chunk count) —
the same platform-wide append-only log every other AI process writes to, per `AI_FINANCE_OS.md`'s
**Enterprise Architecture** — so a curator or an engineer can always answer "when did this content enter
the system, from which connector, and what did the raw source look like before curation touched it."

# Privacy & PII

Knowledge Memory's core content is, by construction, the lowest-PII-risk data in the entire platform:
public statutes, published standards, and platform-authored guidance are not personal data. Three
realistic risks remain, and each has a specific, named mitigation rather than a blanket "we don't store
PII here" assertion.

**1. Incidental PII in scanned or copied source material.** A government circular ingested as a scanned
PDF can carry an official's signature block, a direct phone/email contact line, or a stamp with a named
signatory — content that is real personal data even though it arrived inside a regulatory document. Every
ingested chunk passes a PII-detection pass (the same detection model class the OCR Agent uses for
tenant documents, per `AI_FINANCE_OS.md`) before embedding; any detected name/contact/signature block is
redacted from `content` and `english_gloss` and, where it is load-bearing for the citation itself (e.g., a
named accountable authority the rule requires citing), retained only as a role/title, never a personal
name, with a note in `knowledge_document_versions.change_summary` explaining the redaction.

**2. Overlay content is company-confidential, not public.** `knowledge_company_overlays.content` is
free text a human at a specific company writes, and it can legitimately reference that company's own
context ("our external auditor, [Firm], requires X"). This column is treated at the same privacy tier as
`ai_memory.content` — covered by the company's own data-processing agreement, included in that company's
data export and right-to-erasure workflows, and never surfaced to platform curators as part of the
shared curation pipeline. A platform curator reviewing `knowledge_citation_feedback` sees the feedback's
`reason`/`detail` fields (which a reporting user should keep general) but never a company's overlay
`content` directly, unless that company explicitly attaches it to a support ticket outside this schema.

**3. Third-party copyright and licensing.** IFRS standards text is copyrighted by the IFRS Foundation;
GCC regulatory text carries its own government-source terms. `knowledge_documents.source_license` records
which regime applies (`public_domain`, `licensed_excerpt`, `summary_only`, `platform_authored`), and the
ingestion and curation pipeline enforces it structurally: content flagged `summary_only` may store only a
paraphrased summary plus `source_url` back to the primary text, never a verbatim excerpt beyond short
quotation; `licensed_excerpt` requires an active, recorded license reference (tracked outside this schema
in the platform's commercial-agreements system) before a curator can attach verbatim excerpt text to a
chunk. This is not a data-privacy rule in the personal-data sense, but it is a governance rule this
document's write path enforces alongside privacy, because the same publish gate is the only chokepoint
capable of enforcing it.

# Retention

Knowledge Memory's retention posture is the mirror image of a typical PII-minimization policy: the
platform's bias is to **keep**, not delete, because the single most important guarantee this document
provides — that a transaction can always be explained against the rule that was authoritative on the
date it happened — depends on old content remaining queryable indefinitely.

| Record | Retention rule |
|---|---|
| `knowledge_documents` / `knowledge_chunks` at `status = 'superseded'` | Retained indefinitely. Never hard-deleted, never soft-deleted (`deleted_at` does not even exist on these tables — see **Data Model**). Remains queryable by any `as_of_date` that falls within its `effective_from`/`effective_to` range. |
| `knowledge_documents` / `knowledge_chunks` at `status = 'retracted'` | Retained indefinitely with `retracted_reason` populated. Excluded from default retrieval (see **Retrieval**'s `status IN ('published')` filter) but remains fetchable by document id for audit purposes — "this used to be cited; here is why it no longer is" must always be answerable. |
| `knowledge_document_versions` | Append-only, retained indefinitely. This is the platform's own regulatory-content audit trail. |
| `knowledge_citation_feedback` | Retained indefinitely once `actioned`/`dismissed`, for curation-quality metrics (see **Metrics**). |
| `knowledge_company_overlays` | Standard tenant-table convention: soft-deleted via `deleted_at`, retained per the company's own configured data-retention window, purged on a verified company-erasure request like any other tenant data. |
| Embeddings after a model upgrade | The prior `embedding_model` generation is retained, not deleted, until a full re-embedding backfill under the new model completes platform-wide and a rollback window (default 30 days) has passed — see **Edge Cases**. |

The practical consequence: `knowledge_documents`/`knowledge_chunks` grow monotonically. This is an
accepted, deliberate cost (storage is cheap; an unexplainable five-year-old journal entry is not), and it
is the same trade-off the platform already makes for `journal_entries` and every other posted financial
record — "financial records are never hard-deleted," restated here for regulatory content instead of
transactional content.

# Worked Examples

## Example 1 — Tax Advisor cites the ZATCA rule behind a 15% VAT line

Al-Noor Trading's Saudi-registered sister entity, Al-Noor KSA, raises a standard tax invoice to a
VAT-registered buyer. The deterministic `TaxCalculationService` resolves `tax_rule_id = 8` and applies
15% (see `TAX_AGENT.md`, **Worked Example**, which this scenario extends). When the Tax Advisor explains
the line to the Finance Manager in the Financial Copilot ("why 15%, and why does this invoice need a QR
code but the last one to a registered business didn't?"), it issues two calls: a structured lookup
against `tax_rules`/`tax_rates` for the rate itself, and a Knowledge Memory search
(`domain=gcc_vat, jurisdiction=SA, as_of_date=<invoice_date>, q="standard tax invoice QR code
requirement registered buyer"`). Retrieval returns a `published` chunk of ZATCA's E-Invoicing Business
Rules distinguishing "simplified" (B2C, QR-code-only) from "standard" (B2B, full invoice with buyer VAT
number) tax invoices. The Tax Advisor's response cites both: `{"type": "tax_rule", "id": 8}` for the
rate, and `{"type": "knowledge_chunk", "kb_chunk_id": 48219, "citation_text": "ZATCA E-Invoicing Business
Rules v3 §3.1 (Standard Tax Invoice fields)", "status": "published", "effective_from": "2023-01-01"}` for
why this invoice's format differs from a simplified one — a single natural-language answer, two
independently verifiable sources, one from structured platform data and one from Knowledge Memory.

## Example 2 — IFRS 18 issuance: from ingestion to a confidence jump

This example is the Knowledge Memory side of the exact scenario `COMPLIANCE_AGENT.md` specifies from the
Compliance Agent's point of view. On the day the IASB issues IFRS 18 *Presentation and Disclosure in
Financial Statements*, the `ifrs_foundation_feed` connector ingests the issuance text within hours:
`knowledge_documents` gains a new row (`code='ifrs-18-presentation-disclosure'`, `domain='ifrs_reporting'`,
`jurisdiction='*'`, `status='ingested'`, `effective_from` set to the standard's stated mandatory-adoption
date), with its chunks embedded and queued for triage. Until a Platform Content Curator and a Platform
Compliance Reviewer both act, any Compliance Agent assessment resting on this content is capped at
`confidence ≤ 0.60` and forced `advisory` — exactly the cap `COMPLIANCE_AGENT.md`'s own edge-case table
specifies. Two weeks later, once a curator has reviewed the ingested text against the actual IASB release
and a compliance reviewer has published it (dual control applies — `document_type = 'standard'`), the
same retrieval query now returns a `published` chunk, and the next Compliance Agent run against the same
company re-evaluates its "single-statement income statement without IFRS 18 categorization"
finding at full confidence, with the `control_attestations` row (`control_code =
'IFRS18_READINESS_FY27'`) now resting on citable, `published` grounding rather than a provisional flag.

## Example 3 — PIFSS cap change, effective-dated correctly for payroll already run

PIFSS raises its monthly salary cap for contribution purposes effective 2027-01-01, published to
Knowledge Memory on 2026-11-15 (`knowledge_documents` version 5 of the PIFSS contribution schedule;
version 4's `effective_to` is set to 2026-12-31 and its status becomes `superseded`). Payroll Manager
calculates Al-Noor Trading's December 2026 payroll run on 2026-12-28: its Knowledge Memory query passes
`as_of_date = 2026-12-31` (the pay period's own date, not "today"), and the effective-date filter in
**Retrieval** correctly resolves to version 4 — the cap in force for that period — even though version 5
already exists and is `published` in the system by then. The January 2027 run, calculated with
`as_of_date = 2027-01-31`, resolves to version 5. No agent, and no human reviewing either run later, ever
has to wonder which cap applied to which month; the historical chunk is retained exactly as **Retention**
specifies, and the `sources` array on each period's payroll `ai_decisions` rows cites the version that was
actually authoritative for that period, permanently.

## Example 4 — A company overlay, clearly non-authoritative

Al-Noor Trading's external auditor tells the CFO that, in addition to what IFRS 16 strictly requires,
they want a specific supplementary lease-maturity disclosure in the notes every year. The CFO (holding
`knowledge.overlay.manage`) adds a `knowledge_company_overlays` row against the IFRS 16 chunk covering
lease disclosure requirements: `overlay_type='external_advisor_note'`, `content="Our external auditor,
[Firm], requests an additional lease-maturity ladder disclosure beyond the standard's minimum, every
FY."`. The next time Reporting Agent assembles the annual notes and retrieves that IFRS 16 chunk, the
overlay is attached as supplementary context — visibly labeled "company-specific note, not part of the
authoritative standard" in the assembled prompt and in the notes-drafting UI — and Reporting Agent
includes the extra disclosure in its draft, while the underlying citation to IFRS 16 itself remains
exactly what it was before the overlay existed. Company B's Reporting Agent, drafting its own notes from
the same IFRS 16 chunk, never sees this overlay at all.

# Metrics

Knowledge Memory is measured on two axes that matter for a shared, cited knowledge base: whether the
corpus is *current and reviewed enough* to be trusted, and whether *retrieval* is actually surfacing the
right chunk when an agent needs it.

| Metric | Definition | Why it matters |
|---|---|---|
| **Coverage** | Fraction of (active jurisdiction × active domain) pairs with at least one `published` chunk with `effective_to IS NULL` | A gap here means an agent in that jurisdiction/domain is retrieving nothing and must fall back to low-confidence, uncited reasoning |
| **Review latency** | Time from `status='ingested'` to `status='published'`, by `domain` | A regulation change that sits `ingested` for weeks leaves every affected agent capped at `confidence ≤ 0.60` in the interim (see **The Learning Loop**) |
| **Citation precision** | Sample-audited fraction of `ai_decisions.sources` entries citing a `knowledge_chunk` that a human reviewer confirms is actually relevant and correctly applied | Directly measures whether "the AI cites its sources" is producing *good* citations, not just citations |
| **Retrieval latency** | p50/p95 wall-clock time for `/api/v1/knowledge/search`, split into vector-search and keyword-search legs | A slow retrieval step delays every agent task gated on grounding (Autonomous Accounting's loop, Compliance's Evaluate node) |
| **Hit rate** | Fraction of queries returning at least one chunk above the similarity/rank threshold configured per domain | A persistently low hit rate for a domain is itself a coverage-gap signal, feeding curator prioritization |
| **Feedback backlog age** | Age distribution of `knowledge_citation_feedback` rows still `open`/`under_review` | An aging backlog means citation-quality signals are piling up unactioned — a leading indicator of citation precision decline |
| **Staleness** | Days since a `published` chunk's document was last reviewed, by domain, versus that domain's configured review cadence (e.g., GCC VAT reviewed at least quarterly, IFRS reviewed on every IASB release) | Detects silent drift — content nobody has revisited even though nothing forced a re-ingestion |
| **Blocking-eligible ratio** | Fraction of chunks, by domain, at `status='published'` versus `human_reviewed`-or-below | A proxy for how much of the corpus can support a `blocking` compliance flag versus `advisory`-only output |
| **Overlay adoption** | Count of active `knowledge_company_overlays`, by domain and by company, and average age | Signals which shared content most often needs company-specific supplementation — a candidate list for the curation team to consider clarifying in the shared content itself |
| **Re-embedding freshness** | Fraction of `knowledge_chunks` on the current platform `embedding_model` version versus a prior one | Tracks progress of a model-upgrade backfill (see **Edge Cases**) |

These roll up from the same underlying tables already specified — `knowledge_chunks.usage_count`/
`last_used_at`, `knowledge_document_versions`, `knowledge_citation_feedback`, and `ai_logs`/`ai_decisions`
entries whose `sources` array contains a `knowledge_chunk` reference — rather than a bespoke metrics
table, consistent with the platform convention of deriving reporting views from primary data instead of
maintaining a parallel store of truth (see `AI_FINANCE_OS.md`'s double-entry rule that the General
Ledger is always derived, never separately authored). A lightweight scheduled `report_runs` entry (see
`docs/accounting/REPORTS.md`) computes and caches the daily coverage/staleness/backlog figures for the
platform team's own dashboard — this is Reporting Agent's ordinary `auto` scheduled-report mandate,
pointed at the platform's own knowledge-base health rather than a tenant's financial statements.

# Edge Cases

| # | Edge case | Handling |
|---|---|---|
| 1 | **Superseded regulation still needed for a historical transaction.** An auditor reviews a 2025 invoice under 2027 tax rules that have since changed. | Retrieval is always `as_of_date`-scoped (see **Retrieval**); the historical `superseded` chunk remains queryable by explicitly requesting that date or by following `superseded_by_document_id`/`superseded_by_chunk_id` backward from the current version. Never deleted (see **Retention**). |
| 2 | **Jurisdiction mismatch.** A Kuwait-only company's agent semantically retrieves a highly similar Saudi ZATCA e-invoicing chunk because the free-text query happens to be topically close. | The structured pre-filter (`jurisdiction IN (company's jurisdictions, '*')`) excludes it before the vector pass ever runs — jurisdiction is a hard filter, never merely a ranking boost, precisely because a topically-similar but legally-inapplicable citation is worse than no citation. |
| 3 | **Conflicting sources.** A ZATCA circular appears, on a literal reading, to contradict the VAT Implementing Regulations it clarifies. | Precedence order is fixed and applied automatically during re-rank: `statute` > `regulation` > `standard` > `circular` > `interpretive_guidance` > `howto_guide`/`glossary_entry`; within the same `document_type`, `published` > `human_reviewed` > `ingested`; within the same rank, more specific `jurisdiction` beats `'*'`. A genuine, unresolved conflict a curator cannot reconcile is itself logged as a `knowledge_citation_feedback` row and the lower-precedence chunk is annotated (`citation_text` suffixed with a conflict note) rather than silently preferred. |
| 4 | **A cited rule is later found to have been wrong at ingestion (not merely superseded by new law).** | `status → 'retracted'` with `retracted_reason` populated. Past `ai_decisions.sources` entries that already cited it are never retroactively rewritten (immutable history), but any *open* fiscal period or *pending* decision still referencing it is surfaced to the Compliance Agent as a re-evaluation task. |
| 5 | **Arabic-source/English-gloss mismatch.** A curator later determines the machine-assisted `english_gloss` subtly misstates the Arabic `content` it was derived from. | Any decision whose supporting citation relied solely on a gloss not yet confirmed by a bilingual human reviewer is held at the same `confidence ≤ 0.60` / advisory-only cap as an unreviewed chunk (mirroring `COMPLIANCE_AGENT.md`'s identical rule) until a reviewer confirms or corrects the gloss, recorded as a `content_update` in `knowledge_document_versions`. |
| 6 | **Embedding model upgrade.** The platform moves to a new embedding model with a different (or same) dimensionality. | Re-embedding runs as a background backfill: new embeddings are written to a parallel column/table generation tagged with the new `embedding_model` value; retrieval queries both generations during the transition and prefers the new one once its coverage matches the old; the old generation is retained for a 30-day rollback window (see **Retention**) before being archived. A dimension change (pgvector columns are fixed-width) requires a new `embedding` column via migration, never an in-place resize. |
| 7 | **No matching content at all for a novel scenario.** An agent asks about a fact pattern Knowledge Memory has nothing on (a genuinely new cross-border structure, a jurisdiction QAYD has not yet built coverage for). | Retrieval returns an empty or below-threshold result set; the consuming agent is contractually required (per its own prompt discipline, e.g. `COMPLIANCE_AGENT.md`'s "you NEVER invent a rate, deadline, or legal conclusion not present in the retrieved context") to return low confidence / `inconclusive` and name the missing coverage explicitly, which itself is logged and becomes a coverage-gap signal for curators (see **Metrics**). |
| 8 | **Multi-jurisdiction company.** A company operates branches in both Kuwait and Saudi Arabia. | The company's jurisdiction list (from `companies`/`branches` configuration) is passed as a set, not a single value, to every retrieval call; a branch-scoped task (via `branch_id`) narrows to that branch's own jurisdiction plus `'*'`, so a Kuwait branch's payroll task never retrieves Saudi VAT content and vice versa. |
| 9 | **Duplicate ingestion.** Two connectors (or a connector re-run) fetch the same circular. | `source_document_hash` carries a unique index (`uq_knowledge_documents_hash`); a duplicate hash is discarded at ingestion before a second `draft` row is ever created — see **Write Path & Governance**'s ingestion diagram. |
| 10 | **Licensing/copyright renegotiation.** A standards body changes its terms for redistributing excerpt text. | `source_license` is re-evaluated per affected document; a downgrade from `licensed_excerpt` to `summary_only` triggers a `content_update` version that replaces verbatim excerpt text with a compliant paraphrase plus a strengthened `source_url` pointer — a governance edge case as much as a technical one, tracked as a platform operations task, not an engineering bug. |
| 11 | **A company overlay conflicts with binding published content.** A company's internal policy note asserts an exemption the published rule does not actually grant. | Overlays are additive annotations only (see **Per-Company Isolation**, **Retrieval**); they are never permitted to suppress or outrank a `published` authoritative chunk in an agent's reasoning. Compliance Agent specifically checks for this pattern (an overlay whose claim contradicts the chunk it is attached to) and raises it as an `advisory` flag to the company rather than silently deferring to either side. |

# Future Improvements

- **Structured regulatory diffing.** Move beyond LLM-summarized `change_summary` text toward a
  clause-level structured diff between consecutive `knowledge_document_versions` (which sections added,
  removed, or reworded), enabling an automatic, machine-checkable change-impact map against every
  tenant-facing rule (`tax_rules`, `compliance_requirement_templates`) that cites the changed document —
  extending the same direction `COMPLIANCE_AGENT.md`'s own roadmap already names from the consumer side.
- **Domain-tuned multilingual embedding model.** Evaluate a smaller model fine-tuned specifically on GCC
  statutory Arabic/English text structure in place of a general-purpose multilingual embedding model,
  improving both retrieval precision on legal terms of art and cost per embedding at the platform's scale.
- **Direct regulator feed partnerships.** Move from public-bulletin scraping/parsing connectors to
  licensed, direct feed integrations with ZATCA, PIFSS, and the IFRS Foundation where available, reducing
  ingestion latency and formalizing the `source_license` chain of custody.
- **Confidence-calibrated auto-publish for zero-risk content classes.** Allow a narrow, explicitly
  configured class of change (a typo fix, a broken `source_url`, a formatting correction with no
  substantive text change) to bypass dual control under a tightly scoped `platform.knowledge.autopublish`
  permission, while every substantive content change continues to require full human review — mirroring
  the platform's general principle that autonomy is earned per action-class, never granted globally.
- **Jurisdiction expansion tooling.** As QAYD expands beyond its initial Gulf footprint, build a
  jurisdiction-onboarding checklist/template (which `domain`s need day-one coverage, which connectors to
  stand up, minimum coverage bar before a new jurisdiction's tenants can rely on `auto`-eligible citations)
  so expanding coverage is a repeatable operating procedure, not a bespoke project each time.
- **Anonymized overlay analytics feeding shared-content prioritization.** Analogous to Company Memory's
  "platform-wide, aggregated, anonymized" correction-pattern review described in `AI_FINANCE_OS.md`'s
  **Learning Loop**, aggregate (never per-company-identifiable) overlay themes to prioritize which shared
  chunks most often need a clarifying platform update, closing the loop between "companies keep annotating
  this" and "curators should just fix the base content."

# End of Document
