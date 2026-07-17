# User Memory — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: USER_MEMORY
---

# Purpose

QAYD's AI layer is not a chatbot bolted onto an accounting system; it is an autonomous finance workforce — fifteen specialized agents (General Accountant, Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO, Fraud Detection, Reporting Agent, Document AI, OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant, CEO Assistant) that execute, review, and coordinate financial operations continuously, while a human supervises and approves the decisions that carry real financial, legal, or reputational weight. Traditional software waits for a user to log in and ask; QAYD's AI works before the user asks, and Company Memory (specified in `AI_FINANCE_OS.md` → **Company Memory**) is the substrate that makes that work specific to one company instead of generic — it is what lets the General Accountant draft an entry the way *this* company has always coded it, and what lets the CFO Agent brief the board in the register *this* company's board expects.

User Memory is the orthogonal, narrower dimension layered on top of Company Memory. It is what lets the same workforce feel like it knows the *specific human* it is working with inside that company — not because it holds different financial facts (the ledger is the ledger, identical regardless of who is asking, and User Memory never stores a second version of a business fact), but because it remembers how *this* person prefers to work: which language they think in when they type a question, whether they want a two-line answer or a paragraph, which report they open first every Monday, whether a push notification at 11pm helps them or just annoys them, and where their own personal comfort zone sits inside the approval chain the company has actually configured. Two accountants at Al-Rawda Trading & Logistics W.L.L. can use identical AI agents, reading identical company policy, and still experience QAYD differently — one gets terse bullet points in English at 7am, the other gets a fuller Arabic narrative pushed only when something crosses a threshold she cares about — because the personalization layer, not the financial substance, differs.

This document specifies that personalization layer precisely: what QAYD is allowed to remember about a person, where it lives in the database, how it stays isolated from every other company that same person might also work with, how it is retrieved into a live agent interaction, how it learns from what a person actually does (not just what they say once), who is allowed to write it and under what governance, why it is personal data requiring its own privacy discipline distinct from Company Memory's, how long QAYD keeps it, and what happens when a person asks QAYD to forget them. Four rules govern every design decision in this document and are never contradicted anywhere below:

1. **User Memory personalizes presentation and workflow — never financial substance.** A user's preference can change the language, tone, verbosity, channel, and timing of what QAYD tells them. It can never change an account mapping, a tax treatment, a balance, or any other fact a company's books depend on — those come from Company Memory and the ledger itself, identically for every user who has permission to see them.
2. **User Memory never grants access a user's role does not already grant.** Personalization is retrieved and applied *after* RBAC has already determined what this session may see, never as a parallel channel that could leak a fact the requesting user's permissions would otherwise block (see **Privacy & PII**).
3. **User Memory is scoped to `(user_id, company_id)`, never to `user_id` alone.** The same human can work at more than one QAYD company (an External Auditor serving several clients is the canonical case) and must get a fully separate personalization graph at each one — a preference learned at Company A must never surface, leak, or bias a session at Company B (see **Per-Company Isolation**).
4. **Nothing here is learned silently and irreversibly.** Every inferred preference is disclosed, every self-reported preference is editable, and every row — without exception — is visible to the person it describes and erasable by them, because this is personal data about an identifiable individual, not an anonymous usage statistic (see **Privacy & PII**, **Retention**).

The rest of this document specifies, in order: exactly what QAYD stores about a person (**What Is Stored**); the concrete PostgreSQL and vector schema (**Data Model**); how a memory graph belonging to one person at one company can never bleed into another company or another person (**Per-Company Isolation**); how an agent actually pulls this context into a live interaction alongside Company Memory (**Retrieval**); how a stated preference or an observed habit becomes a durable, weighted memory (**The Learning Loop**); who may write a row and through which governed path (**Write Path & Governance**); why this is a materially different privacy problem than Company Memory and how QAYD treats it as personal data (**Privacy & PII**); how long a row survives and what "right to be forgotten" means concretely against a system that also must never silently corrupt an audit trail (**Retention**); three fully worked scenarios; the metrics QAYD uses to judge whether personalization is actually working; the edge cases it must handle without drama; and where this capability goes next.

# What Is Stored

User Memory holds eight categories of personal-but-low-stakes information. None of them is a financial fact, a permission grant, or an approval outcome — all of those remain exactly where the rest of the platform already puts them (`journal_entries`, `roles`, `company_users`, `ai_decisions`). What User Memory adds is a record of *how this person likes to interact with the workforce that produces those facts*.

| Memory type | What it captures | Example, in this person's own words or inferred from behavior | Typical source |
|---|---|---|---|
| `role_context` | A soft, descriptive summary of what this person actually does day to day — distinct from the hard RBAC `role_id` on `company_users`, which grants permissions, not behavior | "Primarily reviews Treasury and payroll; rarely opens Inventory screens" | Inferred from navigation/usage pattern, confirmed periodically |
| `language_preference` | Which language this person actually wants to converse in with the AI, per context | "Types questions in Arabic; prefers the Copilot's replies in Arabic even though her dashboard UI is set to English" | Explicit statement in chat, or conversation-language detection over several turns |
| `presentation_preference` | How this person likes information framed in conversational and narrative AI output — not the explicit widget grid, which is a different, already-specified table (see note below) | "Numbers-first, minimal prose; always show KWD to three decimals, never round in speech" | Explicit statement, or inferred from repeated "just give me the number" style follow-ups |
| `communication_style` | Tone and register the AI should default to for this person | "Direct, no pleasantries, bullet points over paragraphs; comfortable with technical accounting terms, skip the plain-language gloss" | Inferred from engagement (skips long narratives, never asks for a simpler rephrase) and explicit feedback |
| `notification_preference` | Channel, cadence, and quiet hours for *how and when* this person wants to be told something — distinct from the business-value alert thresholds already configured in `ai_dashboard_layouts.alert_thresholds` | "No push notifications after 20:00 Kuwait time; digest, not real-time, for anything not tagged urgent" | Explicit settings statement, or inferred from a consistent dismiss-without-opening pattern in that window |
| `personal_approval_calibration` | A personal comfort threshold this individual applies on top of — never instead of — the company's actual, configured approval-chain policy | "Personally wants to eyeball any vendor payment over KWD 1,000 herself before it clears her queue, even though company policy auto-advances anything under KWD 5,000 with two prior approvals" | Explicit statement, or inferred from this person consistently opening the full detail view on amounts above a stable threshold before approving |
| `frequent_action` | A behavior pattern specific enough to power a shortcut or a default | "Opens Cash Flow Status first in 4 of 5 sessions; approves the recurring rent accrual within two minutes of it appearing, unedited, every month" | Inferred from `ai_dashboard_layouts` interaction telemetry and `ai_decisions` approval-latency history |
| `recent_topic` | A rolling, short summary of what this person has been discussing with the AI recently, for continuity across sessions | "Last three sessions concerned the Diyar Real Estate receivable slippage; has not yet resolved whether to place them on credit hold" | Derived from `ai_conversations` / `ai_messages` (see **Retrieval**) |

**What this table deliberately does not include.** Explicit dashboard layout — widget position, size, visibility, alert threshold values, and the daily briefing's scheduled time/voice/language — is already fully specified as `ai_dashboard_layouts` in `AI_COMMAND_CENTER.md` → **Customization**, keyed on `(company_id, user_id, layout_name)`. That table is not duplicated here; it is the record of what a person has *explicitly configured about their screen*. User Memory is the record of what the AI has *learned or been told about the person themselves*, retrieved into conversational and narrative generation rather than into layout rendering. The two are related — a `notification_preference` memory row and `ai_dashboard_layouts.alert_thresholds` both affect what a person is told — but they answer different questions: the dashboard table answers "what does this business condition need to look like to count as urgent," and User Memory answers "how, when, and in what tone does *this specific person* want to hear about it." Where the two could conflict (see **Edge Cases**), the explicit configuration in `ai_dashboard_layouts` always wins, because a person who has explicitly set a value has already answered the question a soft inference exists only to guess at.

Nothing in this table ever includes a national ID, a bank account or IBAN, a salary figure, a password, a token, or any other Restricted-class identifier as defined in `API_SECURITY.md` → **PII & Financial Data Protection**. Those fields have their own dedicated, encrypted columns elsewhere in the schema (`employees.national_id`, `vendor_bank_accounts.iban`, and equivalents) and are structurally prevented from ever landing inside a `ai_memory` content field — see **Privacy & PII** for the exact filter that enforces this at write time.

# Data Model

User Memory does not introduce a new table. It extends the platform's single, canonical `ai_memory` table — already specified in full in `AI_FINANCE_OS.md` → **Company Memory** as the durable substrate for everything an agent remembers about a company — with an additive `scope` dimension and a nullable `user_id` foreign key. This mirrors the exact convention `ACCOUNTANT_AGENT.md` uses when it appends new `ai_decision_type` enum values to the shared `ai_decisions` ledger rather than redefining it: **extend, never fork.** A company's policy memory and an individual's language preference are the same kind of object — a confidence-scored, embedded, sourced statement that an agent retrieves at inference time — differing only in whose behavior they describe and who else is allowed to see them.

## Extending the canonical table

```sql
-- ai_memory is defined once, canonically, in AI_FINANCE_OS.md -> Company Memory.
-- Everything below is additive: no existing column, index, or constraint is redefined.

ALTER TABLE ai_memory
    ADD COLUMN scope   VARCHAR(10) NOT NULL DEFAULT 'company',
    ADD COLUMN user_id BIGINT NULL REFERENCES users(id);

ALTER TABLE ai_memory
    ADD CONSTRAINT ai_memory_scope_check CHECK (scope IN ('company', 'user'));

-- A user-scoped row must carry a user; a company-scoped row must not pretend to be personal.
ALTER TABLE ai_memory
    ADD CONSTRAINT chk_ai_memory_user_scope CHECK (
        (scope = 'user'    AND user_id IS NOT NULL) OR
        (scope = 'company' AND user_id IS NULL)
    );

-- Widen the existing memory_type vocabulary (Company Memory's original eight values are
-- untouched; eight User Memory values are appended in the same enum-like CHECK).
ALTER TABLE ai_memory
    DROP CONSTRAINT ai_memory_memory_type_check,
    ADD  CONSTRAINT ai_memory_memory_type_check CHECK (memory_type IN (
        -- Company Memory (AI_FINANCE_OS.md), unchanged:
        'policy', 'preference', 'approval_chain', 'vendor_note',
        'customer_note', 'correction', 'faq', 'employee_context',
        -- User Memory (this document), appended:
        'role_context', 'language_preference', 'presentation_preference',
        'communication_style', 'notification_preference',
        'personal_approval_calibration', 'frequent_action', 'recent_topic'
    ));

-- Widen status to allow an inferred-but-unconfirmed row to exist before it is trusted
-- (see The Learning Loop). 'active' and 'superseded' and 'retracted' are unchanged.
ALTER TABLE ai_memory
    DROP CONSTRAINT ai_memory_status_check,
    ADD  CONSTRAINT ai_memory_status_check
    CHECK (status IN ('proposed', 'active', 'superseded', 'retracted'));

-- Fast path: "give me everything this user has active right now, by type."
CREATE INDEX idx_ai_memory_user_scope
    ON ai_memory (company_id, user_id, memory_type, status)
    WHERE scope = 'user';

-- At most one active personalization row per (user, type, subject) — a new statement
-- must supersede the old one (superseded_by_id), never sit beside it ambiguously.
CREATE UNIQUE INDEX uq_ai_memory_user_active
    ON ai_memory (company_id, user_id, memory_type, COALESCE(subject_type, ''), COALESCE(subject_id, 0))
    WHERE scope = 'user' AND status = 'active';
```

The full, resulting column set a User Memory row carries — every column below already exists on `ai_memory` except `scope` and `user_id`, added here — is:

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT` identity | |
| `company_id` | `BIGINT NOT NULL` | Unchanged from Company Memory; every row, user-scoped or not, still belongs to exactly one company |
| `scope` | `VARCHAR(10)` | `'company'` (existing rows, default) or `'user'` (this document) |
| `user_id` | `BIGINT NULL` | Populated if and only if `scope = 'user'` |
| `memory_type` | `VARCHAR(30)` | One of the sixteen values above |
| `subject_type` / `subject_id` | `VARCHAR(60)` / `BIGINT`, both nullable | Polymorphic pointer when a memory is about something specific (e.g. `subject_type='customers'` for a recent-topic note about one receivable) rather than about the person in general |
| `content` | `TEXT NOT NULL` | Human-readable statement, exactly as Company Memory uses it — e.g. "Prefers Arabic replies in chat; English is fine for scheduled briefings." |
| `embedding` | `VECTOR(1536) NOT NULL` | Same pgvector column, same model, same index type as Company Memory — a semantic query does not care whether the row it matches is company- or user-scoped until retrieval applies the scope filter (see **Retrieval**) |
| `structured_value` | `JSONB NULL` | Machine-usable form, e.g. `{"language": "ar", "context": "chat_replies"}` or `{"channel": "push", "quiet_hours": ["20:00","07:00"], "tz": "Asia/Kuwait"}` |
| `confidence` | `NUMERIC(5,4) NOT NULL DEFAULT 1.0` | `1.0` for anything the person stated directly; a computed value below `1.0` for anything inferred — see **The Learning Loop** for how it is calculated and raised |
| `source_type` | `VARCHAR(30)` | Reused unchanged: `'explicit_config'`, `'inferred_pattern'`, `'correction'`, `'conversation'` |
| `source_ref` | `JSONB NULL` | e.g. `{"ai_conversation_id": 88431, "message_id": 991204}` |
| `usage_count` / `last_used_at` | `INTEGER` / `TIMESTAMPTZ` | Drives the retention review in **Retention**, identically to Company Memory |
| `status` | `VARCHAR(20)` | `'proposed'` (this document's addition), `'active'`, `'superseded'`, `'retracted'` |
| `superseded_by_id` | `BIGINT NULL REFERENCES ai_memory(id)` | A changed preference always supersedes, never overwrites in place |
| `expires_at` | `TIMESTAMPTZ NULL` | E.g. a personal calibration note tied to covering someone else's approvals while they are on leave |
| `created_by` / `updated_by` | `BIGINT NULL REFERENCES users(id)` | The human, or `NULL` when a scheduled agent run originated the (necessarily `'proposed'`) row |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMPTZ` | Standard columns |

## Row-level security: a second, narrower boundary inside the first

Company Memory's existing tenant-isolation policy scopes every row by `company_id`:

```sql
-- Defined once, in AI_FINANCE_OS.md -> Company Memory. Reused, not redefined, here.
CREATE POLICY tenant_isolation_ai_memory ON ai_memory
    USING (company_id = app_current_company_id());
```

`app_current_company_id()` and its sibling `app_current_user_id()` are the platform's standard RLS helper functions, defined once in `ROW_LEVEL_SECURITY.md` → **Session Context**, wrapping the session GUCs `app.company_id` and `app.user_id` that every request-serving code path sets with `SET LOCAL` at the start of the transaction. User Memory needs a second policy layered on top of — never in place of — the one above, because `company_id` scoping alone is not sufficient once a row is also personal to one specific human working inside that company:

```sql
CREATE POLICY user_scope_isolation_ai_memory ON ai_memory
    USING (
        scope = 'company'
        OR user_id = app_current_user_id()
        OR current_setting('app.ai_memory_team_access', true) = 'true'
    );
```

`app.ai_memory_team_access` is a third, narrow, transaction-scoped GUC, deliberately modeled on the platform's existing `app.acting_as_support` pattern from `ROW_LEVEL_SECURITY.md`: it defaults to `'false'`, it is set to `'true'` only by the specific, permissioned controller action described in **Write Path & Governance** (never by a general session), and every read taken while it is `'true'` is independently and unconditionally recorded to `audit_logs` as a cross-user personal-data access — the RLS relaxation and the audit record are not two separate controls a bug could pull apart, they are the same code path. Postgres evaluates both policies as `AND`-combined with `tenant_isolation_ai_memory` (a query must satisfy the company boundary and the user boundary simultaneously), so a session scoped to Company A can never see a `scope='user'` row belonging to Company B regardless of what `app.ai_memory_team_access` is set to — the outer tenant boundary is never something a user-level flag can widen.

## Confidence scale

`ai_memory.confidence`, for both scopes, remains on the platform's `NUMERIC(5,4)` 0–1 scale exactly as Company Memory defines it — this document introduces no second scale. A row a person stated directly in a settings screen or in plain conversational language ("reply to me in Arabic") is written at `confidence = 1.0000`: there is no inference to be uncertain about, the person said it. A row an agent proposes from behavior (**The Learning Loop**) starts below `1.0` and is computed, not guessed, from named sub-signals (consistency of the observed pattern, sample size, recency), the same discipline `ACCOUNTANT_AGENT.md` applies to its own journal-entry confidence score.

# Per-Company Isolation

Company Memory's isolation guarantee — "an embedding computed from Company A's vendor notes is never in the same searchable index partition as Company B's" — is necessary for User Memory but not, on its own, sufficient. A person is not a single memory graph that happens to touch several companies; QAYD treats each of a person's company relationships as fully independent, exactly as it treats their financial data. `users` (per `MULTI_TENANCY.md`) is deliberately a thin, region-wide identity record — `id`, `global_user_uuid`, `display_name`, nothing else — precisely so that no personal profile data accumulates at the identity layer where it could leak across tenants; `company_users` is what links one `user_id` to one `company_id` with its own role, status, and membership lifecycle. User Memory follows that same split: every row is keyed on `(company_id, user_id)` jointly, never on `user_id` alone, and both RLS policies in **Data Model** must pass together for a row to be visible at all.

Concretely: Khalid Al-Fahad is an External Auditor with active `company_users` memberships at both Al-Rawda Trading & Logistics W.L.L. (`company_id` 4821) and a second client, a Salmiya-based retail group (`company_id` 5140). Over several months, Al-Rawda's engagement teaches QAYD that Khalid wants terse, English, numbers-first replies and reviews the Auditor's continuous-control findings every Sunday morning. None of that crosses into his session at company 5140: the moment his session's `app.company_id` GUC is set to `5140` for that engagement, `tenant_isolation_ai_memory` alone already excludes every row where `company_id = 4821`, before the user-scope policy is even evaluated. His personalization at the retail group starts cold, exactly as a brand-new user's would (see **Edge Cases**), and stays that way until his own behavior at that company teaches it something — a preference learned serving one client audit is never used to guess at, or worse, silently pre-fill, his preferences at another. This is the concrete, falsifiable meaning of "respects permissions, never remembers data the user can't access" applied to the multi-company case specifically: the boundary is the same `company_id` boundary that protects every ledger row in the platform, not a separate, weaker one invented for personalization.

Departure from a company is handled the same way membership itself is: when `company_users.status` moves to `'revoked'` for a given `(company_id, user_id)` pair, that pair's `scope='user'` rows do not vanish instantly (an Owner reviewing a departing Senior Accountant's workload in the following weeks may still legitimately need the Approval Assistant to behave predictably during handover), but they stop being written to or refreshed, and they enter the same review/erasure path described in **Retention** on the same schedule every other dormant personalization graph does — revocation is a trigger for review, not an instant purge, and never a reason to keep learning about someone who no longer has a relationship with the company.

# Retrieval

An agent assembling context for a live interaction — the CEO Assistant composing a chat reply, the Reporting Agent drafting a scheduled briefing, any specialist agent deciding how to phrase a proposal it is about to surface — retrieves along the same two parallel paths Company Memory already defines, with the scope filter applied identically to both:

**Structured retrieval** answers exact questions with a `WHERE` clause: "what is this user's stated `language_preference` right now," "does this user have an active `personal_approval_calibration` row for vendor payments." **Semantic retrieval** runs a vector similarity search over `ai_memory.embedding` for the current situation's free-text description, surfacing a relevant row that would not match on any structured key — a `recent_topic` memory about the Diyar receivable surfaces when the current conversation drifts near accounts-receivable risk even though the user never typed the word "Diyar" this session. Both paths query the same table with the same two RLS policies in force; retrieval code never has to remember to add its own scope filter, because the database enforces it regardless of who is asking.

The two scopes are then merged with an explicit precedence rule, not a blend: **Company Memory always wins on financial substance; User Memory only ever wins on presentation.** If a company policy row states the fiscal year starts April 1, no personal memory row — however confidently stated — is permitted to override that fact in an agent's reasoning; a person cannot "prefer" a different fiscal year any more than they can prefer a different tax rate. What a `scope='user'` row *can* do is change how the true answer is delivered: which language it is phrased in, how long the narrative is, whether it opens with the number or with the context, and whether it arrives as a push notification now or waits for tomorrow's digest.

```json
{
  "request_id": "b6e2f9a0-1c4d-4a7e-9b2f-3d5e6a7b8c9d",
  "company_id": 4821,
  "user_id": 118,
  "assembled_context": {
    "company_memory_hits": [
      { "memory_id": 88301, "memory_type": "policy", "content": "Fiscal year starts April 1, not January 1." }
    ],
    "user_memory_hits": [
      { "memory_id": 99042, "memory_type": "language_preference", "confidence": 1.0000, "content": "Prefers Arabic for conversational chat replies; English for the scheduled morning briefing." },
      { "memory_id": 99051, "memory_type": "communication_style", "confidence": 0.9100, "content": "Direct tone, numbers first, minimal narrative; comfortable with technical terms." },
      { "memory_id": 99067, "memory_type": "recent_topic", "confidence": 0.8800, "content": "Last discussed the Diyar Real Estate receivable slippage; undecided on a credit hold." }
    ]
  },
  "reply_language": "ar",
  "reply_register": "direct_numbers_first"
}
```

Every row that materially shaped the final reply — company or user scoped — is listed the same way Company Memory already requires: retrievable evidence for why the AI said what it said, in what language, in what tone, never an invisible thumb on the scale.

# The Learning Loop

Company Memory's Learning Loop turns an accepted, edited, or rejected `ai_decisions` row into durable, company-wide behavior change. User Memory's loop is the same idea applied to a person's own habits rather than to a company's accounting patterns, and it draws on three distinct sources, each with its own trust posture:

**1. Stated directly (`source_type = 'explicit_config'` or `'conversation'`).** A person opens Settings and sets a language, or says so in passing to the Copilot — "by the way, just reply to me in Arabic from now on." Either path writes a row at `confidence = 1.0000` and `status = 'active'` immediately: there is nothing to infer, and nothing to hold back for confirmation. This is the exact mechanism Company Memory's own worked example uses for the Owner's early-payment-discount instruction, narrowed here to one individual rather than the whole company.

**2. Inferred from repeated behavior (`source_type = 'inferred_pattern'`).** The Reporting Agent already observes dashboard usage for the Customization surface in `AI_COMMAND_CENTER.md` — which widgets a person actually opens versus scrolls past — on, at most, a quarterly non-intrusive-suggestion cadence. This document extends that same observation pipeline: when a behavior pattern (a consistently dismissed notification class, a consistently fast, unedited approval above a stable amount, a consistent language choice across many conversation turns) clears a minimum sample size and consistency bar, the agent writes a `scope='user'` row at `status = 'proposed'`, never `'active'` — an inferred personalization is a hypothesis about a person, not yet a fact, and it is never applied to change what that person experiences until it has been surfaced and not rejected, exactly the same discipline the platform applies to a low-confidence financial draft.

**3. Corrected (`source_type = 'correction'`).** A proposed or already-active row can be wrong. When a person edits or dismisses what QAYD inferred about them — "no, I don't want a digest, I want real-time for anything payroll-related" — the correction supersedes the prior row (`superseded_by_id`) rather than silently overwriting it, and the correction itself becomes the new evidence the next inference weighs, at higher confidence than a first-pass guess, mirroring the recency-based trust rule Company Memory's own Learning Loop already applies to a company-wide correction.

```
 Behavior pattern crosses the sample/consistency bar
              │
              ▼
   ai_memory row written: scope='user', status='proposed',
   confidence computed from (consistency × sample_size × recency)
              │
              ▼
   Surfaced to the person: "It looks like you prefer X — is that right?"
              │
       ┌──────┴───────┐
      YES              NO / edited
       │                │
       ▼                ▼
 status='active'   status='retracted', or a new row
 confidence raised  written at the corrected value,
 toward the pattern  status='active', source_type='correction'
 actually observed
```

A single quarter's worth of one consistent pattern is not enough on its own to promote a `personal_approval_calibration` or `notification_preference` row to `'active'` without confirmation — both are the two memory types most likely to be *wrong in a costly way* if mis-inferred (silently going quiet on the wrong notification class, or silently loosening the scrutiny a person actually wants on their own review queue), so this document fixes their promotion path at "propose, then require an explicit confirm" regardless of how clean the observed pattern looks; `frequent_action` and `recent_topic`, by contrast, are low-stakes and fully reversible, and may promote to `'active'` automatically once the bar is cleared, always visible and always one click from being dismissed. This is the same auto/suggest-only autonomy split the rest of the platform applies to financial actions, applied here to the act of remembering something about a person rather than to a ledger entry.

# Write Path & Governance

Every write to a `scope='user'` row travels through the identical Laravel `/api/v1/*` surface, `FormRequest` validation, and RBAC permission check that any other AI-attributed write in the platform does — there is no separate, lighter-weight path for personalization data just because the stakes are lower than a journal entry. Four new permission keys, following the platform's `<area>.<action>` convention and reusing the `ai.memory.*` prefix already attested elsewhere in the platform (`ai.memory.write`), govern the surface:

| Method | Path | Permission | Description |
|---|---|---|---|
| `GET` | `/api/v1/ai/memory` | `ai.memory.read` | List the caller's own `scope='user'` rows (optionally filtered by `memory_type`), merged read-only with the `scope='company'` rows visible to their role |
| `GET` | `/api/v1/ai/memory/me` | `ai.memory.read` | A curated, human-readable "what QAYD's AI knows about you" view — the self-service transparency surface required by **Privacy & PII** |
| `POST` | `/api/v1/ai/memory` | `ai.memory.write` | State a preference explicitly; always `scope='user'`, `user_id` forced server-side to the caller, `status='active'`, `confidence=1.0000` |
| `PATCH` | `/api/v1/ai/memory/{id}` | `ai.memory.write` | Edit a row the caller owns; writes a new row and sets `superseded_by_id` rather than mutating history in place |
| `POST` | `/api/v1/ai/memory/{id}/confirm` | `ai.memory.write` | Promote a `status='proposed'` row the caller owns to `'active'` |
| `POST` | `/api/v1/ai/memory/{id}/reject` | `ai.memory.write` | Move a `status='proposed'` row the caller owns to `'retracted'` and record the rejection as negative evidence against re-proposing the same pattern too soon |
| `DELETE` | `/api/v1/ai/memory/{id}` | `ai.memory.erase` | Erase one row — physically deleted or anonymized in place, per the branch defined in **Retention** |
| `POST` | `/api/v1/ai/memory/erase-all` | `ai.memory.erase` | Erase every `scope='user'` row the caller owns at the active company — the "forget me at this company" action |
| `GET` | `/api/v1/ai/memory/team/{user_id}` | `ai.memory.manage_team` | The narrow, heavily logged cross-user read described below; sets `app.ai_memory_team_access='true'` for the duration of the request only |

Two distinct writers exist, and they are never granted the same trust by default. A **human, writing about themselves**, is authoritative — `POST`/`PATCH` from the owning `user_id` always lands at `confidence=1.0000`, `status='active'`, no review step, because self-report about one's own preference needs no second opinion. An **agent, proposing an inference about a user**, is not authoritative — the FastAPI orchestration layer's `propose_user_memory` tool calls the same `POST /api/v1/ai/memory` endpoint under the AI service account's own scoped token, but the `FormRequest` layer rejects any AI-service-account write that arrives with `status` other than `'proposed'` for the memory types this document restricts to confirm-first promotion, with a hard `422`, not a soft warning — an agent's own confidence, however high, cannot self-upgrade a proposed personalization to active any more than the General Accountant agent can self-approve its own journal entry. This is enforced by the same three independent layers `ACCOUNTANT_AGENT.md` uses for its own guardrails: network isolation (the AI engine has no database credentials at all), application-layer RBAC (the AI service-account role is never granted a path that sets `status='active'` on a restricted memory type), and a database constraint that a `created_by IS NULL` row (agent-originated, no human session) can never carry `status='active'` for those types.

**The narrow cross-user case.** An Owner, CFO, or a permissioned HR/security role occasionally has a legitimate reason to see what the AI has learned about a specific team member — investigating why the assistant behaves a certain way for someone, or reviewing a departing employee's personalization ahead of the retention review in the next section. `ai.memory.manage_team` is granted by default only to Owner and (scoped to their own direct reports) a company's designated HR administrator role; the controller action behind it is the only code path in the platform permitted to set `app.ai_memory_team_access='true'`, and doing so is inseparable from writing an `audit_logs` row recording who viewed whose personal AI memory and why — the same "unmasked-view is its own audited event, distinct from the ordinary read" discipline `API_SECURITY.md` applies to a Restricted-class financial field, applied here to a Restricted-class personal one. This permission is never granted to an AI agent under any configuration; only a human, acting through their own authenticated session, can invoke it.

**Idempotency.** Every write carries the platform-standard `Idempotency-Key` header; a retried, timed-out `POST /api/v1/ai/memory` produces exactly one row, never a duplicate personalization statement competing with itself at retrieval time.

# Privacy & PII

Company Memory describes institutional knowledge — how a company runs its books. User Memory describes an identifiable individual — how they think, when they are awake, what they trust the AI to decide without them, and, inescapably, patterns of behavior that reveal something about how a specific person works. That is personal data in the plain sense of the term, and QAYD's platform-wide "GDPR Ready" compliance goal (`SECURITY_ARCHITECTURE.md` → **Compliance Goals**) is made concrete for AI-held personalization specifically by the rules in this section, not assumed by inheritance from the platform's general posture.

**Classification.** Most `scope='user'` content is `Internal` under the four-class model `API_SECURITY.md` defines (Public / Internal / Confidential / Restricted) — ordinary preference data, confidential to the company's own use of it but not independently damaging if an authorized colleague saw it. Two memory types run hotter and are treated as `Confidential`: `personal_approval_calibration` (a number revealing how much individual scrutiny a specific person privately applies, which is a judgment about that person's own risk tolerance) and `recent_topic` when its `subject_type` resolves to a Restricted-class record (a receivable dispute involving a named customer, for instance) — in that case the memory row's *visibility* follows the same masking rule the underlying subject already carries, not a looser one just because it is being recalled by an AI rather than looked up directly.

**A hard filter, not a policy reminder.** `ai_memory.content` and `structured_value` are a far less access-controlled surface than the dedicated, individually encrypted columns (`employees.national_id`, `vendor_bank_accounts.iban`) that hold genuinely Restricted identifiers elsewhere in the schema. If a person pastes or states something resembling a national ID, an IBAN, a card number, or a password inside a conversation that is about to be distilled into a memory row, a pattern-matching filter — the same sensitive-field allow-list `API_SECURITY.md` already uses for log redaction — runs before persistence and strips or blocks the write rather than embedding and storing the raw value; the conversation transcript itself (`ai_messages.content`) may still hold it under its own retention rules, but User Memory is structurally prevented from ever becoming a second, less-guarded home for the same Restricted data.

**Permission-checked twice, not once.** RBAC determines what a session may read at write time and, independently, at every retrieval. If a Sales Employee's role is later narrowed so they no longer have visibility into customer credit limits, and an older `recent_topic` memory row happens to reference one, the retrieval step re-resolves the subject's current permission before surfacing it — a memory row is never a way to keep seeing, indirectly, something a permission change was meant to take away directly. This is the literal mechanism behind "never remember data the user can't access": the check is not a promise made once when the row was written, it is enforced again every time the row is about to be read.

**Cross-border inference.** Where embedding generation for `ai_memory.embedding` runs through a model hosted outside the company's configured data-residency region, only the de-identified `content` text is sent — never a `user_id`, a `company_id`, or any directly identifying field — and the fact of that cross-border processing is recorded in the company's data-processing record, exactly as `API_SECURITY.md` already requires for AI inference generally.

**Transparency by default, not by request.** `GET /api/v1/ai/memory/me` is not a support-ticket process; it is a standing, self-service page every user can open at any time, listing every active and proposed row about them in plain language with its confidence and its source — "you told the Copilot this on July 3," "QAYD noticed this pattern and is 82% confident; confirm or dismiss." A person should never be surprised, months later, by a preference they do not remember agreeing to.

# Retention

User Memory is registered in the platform's archive/retention system the same way every other archivable table is — as a row in `archive_tier_defaults` (`DATABASE_ARCHIVING.md`), not as a bespoke policy invented for this document alone — but at a deliberately shorter floor than financial data, because it is operational personalization, not evidence a regulator or auditor will ever need to reconstruct:

```sql
INSERT INTO archive_tier_defaults (table_name, domain, cold_retention_years, legal_basis, auto_purge_eligible) VALUES
    ('ai_memory_user_scope', 'ai', 2, 'Behavioral/preference data, not financial evidence; short floor reflects PII sensitivity, not statutory retention', true);
-- Distinct row from the existing ai_messages entry: personalization decays faster than
-- conversation transcripts, which retain some value for support/debugging longer than a
-- stale personal preference retains value for the person it describes.
```

Concretely, this means: a `scope='user'` row whose `last_used_at` has not advanced in a rolling, company-configurable window (platform default 12 months) is surfaced to the person on their `GET /api/v1/ai/memory/me` view as a stale candidate for confirmation or automatic retirement — the same `usage_count`/`last_used_at`-driven review Company Memory already runs, narrowed to a shorter default window given the higher privacy sensitivity of personal rows. Absent any activity or explicit renewal, an inactive row auto-expires at the 24-month `cold_retention_years` floor above.

**Right to be forgotten, concretely.** `DELETE /api/v1/ai/memory/{id}` and `POST /api/v1/ai/memory/erase-all` are available to every user, at any time, for any reason, with no approval chain — erasing a preference about oneself is never a sensitive financial operation and is never gated the way a bank transfer or payroll release is. What happens physically on erasure follows the same "distinguish the person from the transaction" rule `API_SECURITY.md` already establishes for erasure generally:

- **Not cited anywhere.** If a `scope='user'` row has never appeared in any `ai_decisions.sources` array — true for the overwhelming majority of personalization rows, which influence tone and channel, not a specific financial decision — it is genuinely, physically deleted (`DELETE FROM`, via the same whitelisted-purgeable path `DATABASE_SOFT_DELETE.md` defines for master-data rows with no transactional footprint), not merely soft-deleted and left to decay.
- **Cited as evidence.** If a specific row was listed as a source that materially shaped an already-approved `ai_decisions` row (a `personal_approval_calibration` row that explains, in an audit trail, why a particular payment was flagged for extra personal review before it posted), it is anonymized in place — `content` replaced with a tombstone marker, `embedding` and `structured_value` nulled, the row's `id` preserved so the historical decision's citation still resolves — exactly the same pattern `DATABASE_SOFT_DELETE.md` requires for a Customer record with historical invoices: "cannot be purged, anonymize instead."

Both branches are service-mediated, never a raw manual `UPDATE`/`DELETE`, and both are captured in `audit_logs` as their own event — the erasure is auditable even though the personal data it acted on is gone, the same guarantee `API_SECURITY.md` states for erasure generally. **Offboarding** triggers the identical review early rather than waiting for the 24-month floor: the scheduled job that already walks `company_users.status='revoked'` pairs for access cleanup additionally queues that pair's `scope='user'` rows for the same purge-or-anonymize decision within 30 days, unless a legal hold recorded against the company (the same `archive_policies` legal-hold mechanism `DATABASE_ARCHIVING.md` defines) explicitly blocks disposal.

# Worked Examples

## Example 1 — A stated preference changes how, not what, the Copilot answers

Mariam Al-Sabah (CFO, `user_id` 118, Al-Rawda Trading & Logistics W.L.L., `company_id` 4821) tells the Copilot mid-conversation, "من الحين خلك تردين علي بالعربي بالمحادثة، بس خلي البريفنق الصبحي بالإنجليزي" ("from now on, reply to me in Arabic in chat, but keep the morning briefing in English"). The CEO Assistant writes two `ai_memory` rows immediately, both `scope='user'`, `user_id=118`, `source_type='conversation'`, `confidence=1.0000`, `status='active'`: one `language_preference` scoped `structured_value={"context":"chat_replies","language":"ar"}`, one scoped `{"context":"scheduled_briefing","language":"en"}`. The next time Mariam asks a question in the chat panel, retrieval surfaces both rows; the reply comes back in Arabic. The next scheduled 06:00 briefing — generated by the Reporting Agent, retrieving the same two rows — renders in English. No financial number, account mapping, or company policy changed; only the language and the channel the true answer travels through did.

## Example 2 — An inferred habit is proposed, confirmed, and promoted

Over six weeks, the Reporting Agent's usage-observation pipeline (the same one already specified for `ai_dashboard_layouts` suggestions in `AI_COMMAND_CENTER.md`) notices that Senior Accountant Fahad Al-Otaibi (`user_id` 205, same company) opens Cash Flow Status first in 27 of his last 30 sessions, and separately, that he approves the recurring warehouse-lease accrual proposal within two minutes of it appearing in his queue, unedited, in 11 of the last 11 months. Both cross this document's sample-size-and-consistency bar. Two rows are written: a `frequent_action` row at `confidence=0.93`, `status='active'` immediately (low-stakes, reversible, no confirmation required), and — because the pattern also touches how quickly he clears a recurring item without scrutiny, which is adjacent to `personal_approval_calibration` territory — a second row proposing that Fahad be shown this specific recurring item at the very top of his queue each month, at `confidence=0.81`, `status='proposed'`. His next `GET /api/v1/ai/memory/me` view shows: "You tend to open Cash Flow Status first — pin it to the top of your view?" (already applied, one-click undo) and "You approve the warehouse-lease accrual quickly and consistently every month — always show it at the top of your queue when it appears?" (awaiting his yes/no). He confirms the second; it promotes to `status='active'`, `confidence` raised to `0.96` to reflect the added evidence of his explicit confirmation, and `source_type` remains `'inferred_pattern'` with the confirmation itself logged in `source_ref`.

## Example 3 — Offboarding and the purge-versus-anonymize branch

Nadia Al-Sharif (`user_id` 217), a Finance Manager at the same company, resigns; her `company_users` row moves to `status='revoked'` on her last day. Thirty days later, the scheduled offboarding review examines her nine active `scope='user'` rows: seven — `language_preference`, `presentation_preference`, two `frequent_action` rows, a `communication_style` row, and two stale `recent_topic` rows — were never cited in any `ai_decisions.sources` array and are physically deleted outright. Two `personal_approval_calibration` rows, however, were cited as supporting evidence in `ai_decisions` rows tied to journal entries she personally reviewed and approved above her own stricter personal threshold — those two rows are anonymized in place: `content` becomes a tombstone ("personalization data erased per offboarding retention policy, 2026-08-15"), `embedding` and `structured_value` are nulled, and the row `id` survives so that the historical `ai_decisions.sources` citation still resolves to a real row rather than a dangling reference, exactly mirroring the platform's Customer-anonymization precedent. Both the seven deletions and the two anonymizations are captured as a single `audit_logs` entry: "AI personalization data for user_id 217, offboarded 2026-07-16, disposed per retention policy on 2026-08-15."

# Metrics

| Metric | Definition | Target |
|---|---|---|
| Personalization coverage | % of active users at a company with at least one active `scope='user'` row | > 60% within 90 days of first login |
| Proposed→confirmed conversion rate | Of `status='proposed'` rows surfaced, % a person confirms rather than rejects or ignores | 50–80% (too low signals noisy inference; too high with low volume signals QAYD is being too conservative about proposing at all) |
| Retrieval influence rate | % of conversation turns and scheduled outputs where a `scope='user'` row was retrieved and materially changed language, tone, or channel | Tracked per company, not targeted — a rising rate as tenure grows is the expected, healthy shape |
| Confidence calibration (Brier score) | Squared error between an inferred row's stated confidence and whether it was later confirmed or rejected | < 0.15 |
| Cross-user leakage incidents | Rows returned to a session whose `(company_id, user_id)` does not match the row's own — evaluated by a periodic synthetic canary query, the same technique `AI_FINANCE_OS.md` → **Continuous Audit** uses for cross-tenant leakage | Zero, always — any non-zero result is a platform-level security incident, not a personalization-quality finding |
| Erasure SLA compliance | % of `DELETE`/`erase-all` requests and offboarding reviews completed within their defined window | 100% within 30 days |
| Time-to-stable-personalization | Median number of sessions before a new user's `scope='user'` graph stops changing materially session-to-session | Tracked as a UX health signal, not a hard target |

# Edge Cases

| Case | Handling |
|---|---|
| Brand-new user, zero history | No fabricated preference is ever written; the AI falls back to role-based defaults (an Accountant's default register, a CFO's default briefing cadence) exactly as `ai_dashboard_layouts` already ships sensible role-based layout defaults, until real behavior accrues |
| User's RBAC role narrows mid-session | Retrieval re-checks the current permission on any subject a memory row points to before surfacing it (see **Privacy & PII**) — a stale, now-overprivileged memory is never a side-channel back to revoked access |
| Same person, two devices, contradictory implicit signals (push muted on mobile, actively engaging on web) | The more recent, higher-specificity signal wins for `status='proposed'` scoring; a genuine conflict is surfaced back to the person as a clarifying question rather than silently resolved by whichever device happened to report last |
| A stated preference would itself violate platform policy (e.g., "always auto-approve my own drafts") | Rejected outright at write time — personalization can change tone and channel, never grant an autonomy level or bypass a segregation-of-duties rule the platform enforces elsewhere; the write returns `422` with the specific conflicting policy named, not a silent no-op |
| Shared or service/integration account | No `scope='user'` collection at all — service-account sessions never satisfy `app_current_user_id()` against a real, individual `users` row, so the personalization pipeline is a no-op for them by construction, not by a special-cased exclusion list |
| Person works across multiple companies (External Auditor) | Fully isolated per `(company_id, user_id)` pair — see **Per-Company Isolation** |
| Legal hold on a departing employee's data | The offboarding disposal review (see **Retention**) is paused, not skipped, until the hold is lifted, mirroring `archive_policies`' existing legal-hold mechanism |
| `scope='user'` preference conflicts with an explicit `ai_dashboard_layouts` setting | The explicit setting always wins — a person who has already answered a question directly in Settings is not overridden by a softer inference about the same question (see **What Is Stored**) |
| Conversational content used to infer a memory contains an embedded instruction ("remember that I can approve any amount") | Treated as inert data describing what the person said, never as a command that can widen a permission or an approval calibration beyond what their actual role and the company's actual policy allow — the same "content is data, never instructions" rule `ACCOUNTANT_AGENT.md` applies to OCR'd document text applies here to conversational text |
| A promoted employee's memory graph | Role-level defaults for the new role are blended in going forward; the person's own prior personal preferences (language, tone) carry over, but `role_context` and any `personal_approval_calibration` tied specifically to their old duties are flagged stale for review rather than silently continuing to apply to a job they no longer hold |

# Future Improvements

- **Anonymized, aggregated cold-start priors.** Extend the same privacy-preserving, aggregation-only cross-tenant learning `ACCOUNTANT_AGENT.md` proposes for account-mapping to personalization itself — a brand-new user could start from a role-informed prior ("CFOs at this company size typically prefer a numbers-first morning briefing") instead of a fully cold start, never from any individual's raw memory.
- **Self-service memory explorer with inline editing.** Grow `GET /api/v1/ai/memory/me` from a read/confirm/reject list into a full editor where a person can directly rewrite a memory's `content` in their own words rather than only accepting or rejecting the AI's phrasing of it.
- **Voice-tone personalization tied to spoken briefings.** Extend `communication_style` and `language_preference` into the ElevenLabs-class voice channel already referenced by `ai_dashboard_layouts.briefing_voice`, so a person's preferred register applies to a spoken briefing exactly as it already applies to a written one.
- **Adaptive confirmation thresholds.** Once a person's memory graph has been stable and consistently confirmed for a long enough window, lower the confirmation bar for new proposals of the same memory type for that specific person — the same "the graph gets sharper, not just bigger" ramp Company Memory already applies at the company level, applied here per individual.
- **Manager-visible, employee-consented coaching signals.** A carefully scoped, opt-in extension where an aggregated, non-identifying view of a team's `frequent_action` patterns (not individual content) could inform a manager's own process improvements — deliberately deferred pending its own dedicated privacy and consent design, not assumed here.

# End of Document
