# Open Questions & Decisions Register — QAYD

Version: 1.0
Status: Planning
Module: Execution
Submodule: OPEN_QUESTIONS

---

# Purpose

This is QAYD's live decisions register: the single place where open questions, unresolved
divergences, and pending policy calls are tracked until they are decided and folded back into
the authoritative docs. It exists because the documentation set was written module-by-module,
and a handful of load-bearing choices were made more than once with slightly different answers.
Those divergences are not bugs in the design — they are decisions that were never formally
closed. Each one below is a genuine tension observed across the repository, with the specific
source files that disagree, the options on the table, and a recommendation where one is clearly
defensible.

Rules for this register:

- An entry stays `Open` until the decision is made **and** every source doc is reconciled to it.
- `Blocking?` = `Yes` means at least one of database schema, first integration, or a security
  boundary cannot be finalized until this is closed. Blocking items are resolved first.
- Owner is a role, not a person. The owner drives the decision; they do not decide alone.
- A recommendation is a starting position for the decision, never the decision itself.

Status legend: `Open` (undecided) · `Proposed` (recommendation on the table, awaiting sign-off) ·
`Decided` (closed; docs reconciled).

---

# Product

| ID | Question | Options | Recommendation | Owner | Status | Blocking? |
|---|---|---|---|---|---|---|
| PRD-1 | What are QAYD's subscription plan tiers, and what does each entitle? Plan-tier gating is already referenced (per-plan tool entitlement in [../ai/prompts/SYSTEM_PROMPTS.md](../ai/prompts/SYSTEM_PROMPTS.md); Stripe-Connect-style tenancy analogy in [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)) but no tier model, seat model, or Starter-plan constraint set is defined anywhere. | (a) Starter / Growth / Enterprise with a documented entitlement matrix; (b) usage-metered (AI inference / documents / seats); (c) hybrid base tier + metered AI. | Define three named tiers with an explicit entitlement matrix; enforce entitlements **server-side via the permission layer** (never by hiding tools — SYSTEM_PROMPTS already mandates a stable tool list). Meter AI inference on top as an add-on. | Product Lead | Open | Yes |
| PRD-2 | What powers QAYD's **own** SaaS billing (as opposed to the tenant-facing gateways Tap/MyFatoorah/KNET that tenants use for *their* businesses)? Only a Stripe-Connect *analogy* appears in the docs; no subscription-billing engine is chosen. | (a) Stripe Billing; (b) Tap/MyFatoorah recurring (GCC-local, KNET-friendly); (c) Paddle (merchant-of-record, handles tax); (d) in-house on the existing `subscriptions` model. | GCC-local recurring (Tap or MyFatoorah) for KNET/mada coverage of Kuwaiti SMEs; revisit Paddle if cross-border VAT-on-SaaS becomes a burden. | Product Lead | Open | No |
| PRD-3 | Is the per-company `feature_flags` / `feature_flag_overrides` table ([../database/DATABASE_VERSIONING.md](../database/DATABASE_VERSIONING.md)) the canonical flag mechanism, or do we adopt a managed service (LaunchDarkly / Unleash / Flagsmith)? | (a) DB table only; (b) managed service; (c) DB table now, service later if flag count explodes. | Keep the DB table for MVP — it is per-company, toggle-without-deploy, and already specified. Revisit a managed service only if targeting rules outgrow a single boolean+override. | Platform Architect | Proposed | No |
| PRD-4 | Web-first vs. web+mobile at launch. The Flutter app is fully specified ([../frontend/MOBILE_APP.md](../frontend/MOBILE_APP.md)) but launch sequencing relative to the Next.js web app is unstated. | (a) Web MVP, mobile fast-follow; (b) parallel; (c) mobile-first for approvals only. | Web-first MVP; ship the mobile app as a fast-follow scoped initially to notifications + the approvals inbox (the two things a supervisor needs on a phone). | Product Lead | Proposed | No |
| PRD-5 | How much AI autonomy ships **on by default** at launch (auto-post confidence floors and amount ceilings in `ai_agent_settings.thresholds`)? | (a) Conservative — nearly everything `suggest_only` day one; (b) ship the platform defaults in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md) (e.g. auto-post recurring bills ≥0.90 confidence, ≤KWD 500); (c) auto for reconciliation matching only. | Ship conservative: reconciliation auto-match on, journal auto-post `suggest_only` until a company has enough history for the Learning Loop to earn a higher default. Let each company opt into wider autonomy. | AI Lead | Open | No |

---

# Architecture

| ID | Question | Options | Recommendation | Owner | Status | Blocking? |
|---|---|---|---|---|---|---|
| ARC-1 | **RLS GUC name is divergent.** The tenant session variable is `app.company_id` in [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md), [../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md), [../security/SECURITY_ARCHITECTURE.md](../security/SECURITY_ARCHITECTURE.md) and [../database/DATABASE_EVENTS.md](../database/DATABASE_EVENTS.md), but `app.current_company_id` in [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), the ERD, migrations, and ~30 backend/AI docs. RLS policies read a GUC by exact name — two names means one is silently NULL and the policy fails closed (or open) unpredictably. | (a) Standardize on `app.current_company_id`; (b) standardize on `app.company_id`. | Standardize on **`app.current_company_id`** — it is the majority usage and it reads unambiguously as "the active company for this session." Fix the four security/RLS foundation docs and grep the codebase for the old name in CI. | Platform Architect | Proposed | Yes |
| ARC-2 | **First-party access-token TTL is divergent.** 15 min / 900s in [../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md), [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md), [../security/AUTHENTICATION.md](../security/AUTHENTICATION.md), [../api/API_SECURITY.md](../api/API_SECURITY.md), [../frontend/FRONTEND_ARCHITECTURE.md](../frontend/FRONTEND_ARCHITECTURE.md); but 1 hour / 3600s in [../api/API_ARCHITECTURE.md](../api/API_ARCHITECTURE.md) and [../api/AUTHORIZATION_API.md](../api/AUTHORIZATION_API.md), and "max 24-hour lifetime" in [../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md). | (a) 15 min first-party session JWT; (b) 1 hour. | Pin **15 min** for the first-party session JWT (the majority and the security-doc position); keep **1 hour** *only* for OAuth/partner tokens (a separate class — [../api/PARTNER_API.md](../api/PARTNER_API.md)). Correct API_ARCHITECTURE and GENERAL_LEDGER to 15 min and stop conflating the two token classes. | Security Lead | Proposed | Yes |
| ARC-3 | **`X-Company-Id` identifier type is divergent.** The header/company id is a BIGINT integer in the ERD (`company_id BIGINT`), the OpenAPI schema (`type: integer`), and most examples (`42`, `102`, `4821`); but an opaque prefixed string `cmp_4471` in [../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md), [../frontend/flows/LOGIN_FLOW.md](../frontend/flows/LOGIN_FLOW.md), and [../frontend/THEMING.md](../frontend/THEMING.md). The `companies` table also carries a `uuid`. CREATE_COMPANY_FLOW even notes the two forms are "the same identifier at two layers" — but nothing pins which one crosses the wire. | (a) BIGINT everywhere; (b) opaque `cmp_` public id externally, BIGINT internal; (c) UUID on the wire. | Adopt (b): expose an **opaque `cmp_` external identifier** (backed by the existing `companies.uuid`) in all API payloads and the `X-Company-Id` header; keep BIGINT strictly internal. Update the OpenAPI schema from `integer` to `string` and normalize the numeric examples. Never expose the raw auto-increment id (matches the MULTI_TENANCY "never expose raw sequential IDs" stance). | Platform Architect | Open | Yes |
| ARC-4 | **Two color-token systems coexist.** A scale-based `--qayd-*` set (`--qayd-ink-1..12`, `--qayd-accent`, `--qayd-positive/negative/warning`) across [../design-system/](../design-system/README.md), and a semantic `--color-*` set (`--color-fg-primary`, `--color-bg-canvas`, `--color-border-subtle`, `--color-accent`, `--color-danger`) in the same design-system docs and [../frontend/THEMING.md](../frontend/THEMING.md). Components reference both. | (a) Semantic `--color-*` canonical, `--qayd-ink-*` become raw primitives feeding it; (b) `--qayd-*` canonical; (c) keep both, document the mapping. | Adopt (a): **semantic `--color-*` roles are the API components consume**; the `--qayd-ink-N` ramp becomes the primitive palette that the semantic tokens are defined *from*. This is the only layering that survives a theme change without touching components. Publish one mapping table. | Design Lead | Proposed | No |
| ARC-5 | **Color space is divergent.** Design-system color docs specify `hsl()` ([../design-system/COLOR_SYSTEM.md](../design-system/COLOR_SYSTEM.md), [../design-system/DESIGN_TOKENS.md](../design-system/DESIGN_TOKENS.md)); [../frontend/THEMING.md](../frontend/THEMING.md) specifies `oklch()`. | (a) OKLCH source of truth; (b) HSL source of truth; (c) OKLCH authored, HSL fallback emitted. | Author tokens in **OKLCH** (perceptually uniform ramps, better dark-mode contrast control) and emit an HSL/hex fallback layer for any target lacking OKLCH support. Reconcile the design-system docs to match. | Design Lead | Proposed | No |
| ARC-6 | **Vector store: committed or "future"?** All AI-memory docs commit to `pgvector` in PostgreSQL (`VECTOR(1536)`), yet [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) lists "Vector Database" under *Future Technologies* as if undecided. There is also an index-type split — HNSW in most docs vs. an "earlier ivfflat sketch" retained in [../ai/memory/COMPANY_MEMORY.md](../ai/memory/COMPANY_MEMORY.md). | (a) Ratify pgvector-in-Postgres now, HNSW standard; (b) external vector DB (Qdrant/Pinecone); (c) pgvector now, revisit at scale. | Ratify **pgvector-in-Postgres** as the decided store (keeps tenant data under one RLS boundary — a hard requirement for a finance AI) and standardize on **HNSW**. Remove it from "future/undecided" in TECH_STACK. Document the 1536-dim embedding-model upgrade path (parallel-column backfill, already sketched in [../ai/memory/KNOWLEDGE_MEMORY.md](../ai/memory/KNOWLEDGE_MEMORY.md)). | Platform Architect | Proposed | No |
| ARC-7 | **Search infrastructure staging.** TECH_STACK's stack summary names Meilisearch (Phase 2) and ElasticSearch (Phase 3), but every search surface in [../ai/tools/SEARCH_TOOLS.md](../ai/tools/SEARCH_TOOLS.md) and the module docs is built Postgres-native (`tsvector` + `pg_trgm` + `pgvector`). | (a) Stay Postgres-native indefinitely; (b) introduce Meilisearch when latency/relevance demands; (c) commit to the three-phase plan. | Treat Postgres-native as the **decided MVP search stack**; reframe Meilisearch/Elastic as *scale-triggered* options (a documented latency/relevance threshold), not a planned migration. Avoids standing up a second data store prematurely. | Backend Lead | Proposed | No |

---

# AI

| ID | Question | Options | Recommendation | Owner | Status | Blocking? |
|---|---|---|---|---|---|---|
| AI-1 | **Default LLM provider and per-tenant selection.** TECH_STACK sets Primary = OpenAI, Secondary = Anthropic, "companies may choose their provider." How is provider selection modeled, and what is the platform default? | (a) OpenAI default, per-company override; (b) Anthropic default; (c) router that picks per task class (OCR vs reasoning vs narrative). | Ship a **single platform-default provider** with a per-company override stored in `ai_agent_settings`; abstract the provider behind the FastAPI layer so a router (option c) can be added later without changing agent code. Do not expose provider choice at launch beyond an enterprise setting. | AI Lead | Open | No |
| AI-2 | **In-region inference for residency-bound tenants.** [../security/AI_SECURITY.md](../security/AI_SECURITY.md) states provider processing is "pinned in-region" for residency-bound tenants, but frontier OpenAI/Anthropic models may not offer GCC-region inference. | (a) Azure OpenAI / Bedrock in a permitted region where available; (b) a local/open model in-region for residency-bound tenants (reduced capability); (c) contractual no-retention + nearest permitted region. | Use **Azure OpenAI or Bedrock in the nearest permitted region** where the model is offered; fall back to a self-hosted in-region open model only for tenants who contractually forbid any egress. Make the capability/residency tradeoff explicit in the tenant's residency config. Tightly coupled to CMP-2. | Security Lead | Open | Yes |
| AI-3 | **Embedding model + dimensionality lock-in.** `VECTOR(1536)` is hard-coded across the memory schema; a model change with a different dimension needs a new column, not a resize. What is the embedding model of record and its upgrade cadence? | (a) Pin one model + documented backfill migration for upgrades; (b) store `embedding_model` per row and support two generations concurrently (already sketched in KNOWLEDGE_MEMORY). | Pin one **embedding model of record**, persist `embedding_model` per row, and adopt the dual-generation backfill already described in [../ai/memory/KNOWLEDGE_MEMORY.md](../ai/memory/KNOWLEDGE_MEMORY.md) so an upgrade is a background re-embed, not an outage. | AI Lead | Proposed | No |
| AI-4 | **Autonomy escalation policy per module.** The fifteen-agent roster mixes `auto` / `suggest_only` / `requires_approval` defaults. Beyond the platform-wide sensitive-action list (transfers, payroll release, tax submission, deletes, permission changes — always human), which module actions may a company *promote* to `auto`, and by what evidence? | (a) Fixed per-agent ceilings the company can tighten but never loosen past; (b) Learning-Loop-earned promotion; (c) both. | Adopt (c): a **fixed platform ceiling per action class** (the sensitive list can never be loosened) plus **Learning-Loop-earned** widening within the ceiling. Document the exact evidence (clean-history window, correction-free streak) that raises a default. | AI Lead | Open | No |

---

# Compliance

| ID | Question | Options | Recommendation | Owner | Status | Blocking? |
|---|---|---|---|---|---|---|
| CMP-1 | **Kuwait VAT activation posture.** Kuwait VAT is "provisioned but dormant" (0% / out-of-scope default) while KSA (ZATCA e-invoicing) and UAE (FTA VAT 201) are live — see [../accounting/TAX.md](../accounting/TAX.md) and [../backend/TAX_SERVICE.md](../backend/TAX_SERVICE.md). What is the default tax treatment for a new Kuwaiti tenant, and how fast can Kuwait VAT be switched on when legislation lands? | (a) Ship Kuwait dormant/out-of-scope, flip via a versioned country pack when law passes; (b) pre-stage a 5% Kuwait pack behind a feature flag. | Ship **Kuwait dormant / out-of-scope** as the default treatment; keep KSA/UAE packs fully live. Because rates are data (`tax:install-country-pack`), activation is a rate/rule data change, not a code release — pre-author the Kuwait pack so go-live is a same-day config flip. | Compliance / Tax Lead | Proposed | No |
| CMP-2 | **Data-residency vs. DR region.** Primary region is `me-south-1` (Bahrain, GCC), but the DR standby is `eu-central-1` (Frankfurt, outside GCC) — flagged "see residency note" in [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md). Strict GCC/Kuwait residency claims in [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md) conflict with an out-of-region DR copy. | (a) GCC-only DR (`me-central-1`, UAE) for residency-bound tenants; (b) eu-central-1 DR for non-residency-bound tenants only; (c) crypto-shredded, region-pinned backups only, no warm DR out of region. | Split by tenant class: **residency-bound tenants get GCC-only DR** (me-central-1); non-residency-bound tenants may use eu-central-1. Document the RPO/RTO and cost difference per class so the sales/legal promise matches the infra. | Security Lead | Open | Yes |
| CMP-3 | **ZATCA e-invoicing readiness scope at launch.** KSA tax invoices require QR + mandatory fields + previous-invoice hash chain (`compliance_profile`). Is full ZATCA Phase-2 (integration/clearance) in scope for launch, or Phase-1 (generation) only? | (a) Phase-1 generation only at launch; (b) full Phase-2 clearance integration. | Ship **Phase-1 generation** (QR, fields, hash chain) at launch; scope Phase-2 clearance as a fast-follow tied to a specific KSA go-live customer, since clearance integration is heavy and gated by ZATCA onboarding. | Compliance / Tax Lead | Open | No |

---

# Ops

| ID | Question | Options | Recommendation | Owner | Status | Blocking? |
|---|---|---|---|---|---|---|
| OPS-1 | **Single-region vs. multi-region at launch.** [../deployment/MULTI_REGION.md](../deployment/MULTI_REGION.md) specifies a full active/passive cross-region topology. Do we stand up DR at launch or run single-region first? | (a) Single-region + region-pinned backups at launch, DR added post-PMF; (b) full active/passive from day one. | Launch **single-region (me-south-1) with region-pinned, tested backups**; stand up the passive DR region once revenue and a residency-bound enterprise customer justify the second-region cost. Keep the failover runbook ready so DR is a provisioning step, not a redesign. Depends on CMP-2 for the DR region choice. | DevOps / SRE Lead | Open | No |
| OPS-2 | **Dev-on-Hetzner, prod-on-AWS split.** TECH_STACK names Hetzner for development and AWS for production. Is the divergence deliberate (cost) or should staging mirror prod on AWS to catch environment drift? | (a) Keep Hetzner dev / AWS prod; (b) AWS staging that mirrors prod, Hetzner for local/CI only. | Keep Hetzner for local/CI cost savings, but run a **prod-mirroring AWS staging** environment so residency, RLS, and managed-service behavior are validated where they actually ship. | DevOps / SRE Lead | Proposed | No |
| OPS-3 | **AI spend governance.** Metered AI (PRD-1) and continuous autonomous agents create an open-ended inference bill. Where is the per-company spend cap enforced, and what happens on breach? | (a) Hard per-company monthly cap, agents degrade to suggest-only on breach; (b) soft cap + alert; (c) prepaid credits. | Enforce a **hard per-company inference budget** in the FastAPI layer; on breach, agents degrade to `suggest_only` (never silently stop) and notify the owner. Prevents a runaway loop from becoming a runaway invoice. | AI Lead | Open | No |

---

# How This Register Is Worked

1. Blocking items (`ARC-1`, `ARC-2`, `ARC-3`, `AI-2`, `CMP-2`) are resolved before the schema,
   the first end-to-end integration, and the security boundary are frozen. They gate everything
   downstream that assumes a single canonical answer.
2. Each decision is recorded as an Architecture Decision Record under
   [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md), then the
   divergent source docs are edited to the decided answer in the same change.
3. An entry moves to `Decided` only when every source file named in its row has been reconciled —
   a decision that leaves two docs disagreeing is not done.
4. New tensions surfaced during build are appended here rather than resolved silently in a single
   module, so the platform keeps one memory of why each choice was made.

# End of Document
