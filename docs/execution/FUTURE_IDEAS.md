# Future Ideas & Post-MVP Backlog — QAYD

Version: 1.0
Status: Planning
Module: Execution
Submodule: FUTURE_IDEAS

---

# Purpose and Non-Commitment

This document is a backlog of ideas for QAYD **after** the MVP ships. It is explicitly **not** a
commitment, a roadmap, or a promise to build any of it. Nothing here is scheduled, staffed, or
approved. Its job is to hold the good ideas that keep surfacing during design so they are captured
honestly — with their real cost and their real dependencies — instead of either being lost or
quietly sneaking into MVP scope.

Read every entry with three disciplines in mind:

- **Honesty over enthusiasm.** Complexity and dependencies are stated plainly. An idea that reads
  "high complexity, blocked on three things we haven't built" is more useful than one dressed up
  as easy.
- **MVP protection.** If an idea would pull effort out of the core loop (ingest → draft → approve →
  post → report, supervised), it waits here until the core is real and adopted.
- **Trigger before build.** Each idea has a "revisit-when" trigger — a concrete signal (a metric, a
  customer count, a regulatory event) that would justify promoting it from backlog to roadmap. No
  trigger fired = it stays here.

Complexity is a rough T-shirt size (S / M / L / XL) reflecting engineering + design + compliance
effort combined, not a promise of duration.

Several of these already have a foothold in the existing design (the AI Finance OS vision, the
Partner/Public API docs, the multi-entity assumption in Financial Statements). Where that is true
it is noted — a foothold lowers cost but does not make the idea in-scope.

---

# Deeper AI

| ID | Idea | Value | Complexity | Dependencies | Revisit When |
|---|---|---|---|---|---|
| FUT-AI-1 | **Cash-flow & revenue forecasting, productized.** The Forecast Agent and Simulation Engine are already described in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md); turn them into a first-class, tenant-facing forecasting surface with confidence intervals and scenario sliders. | Moves QAYD from "records the past" to "predicts the shortfall three weeks out" — the core differentiator versus every incumbent. High willingness-to-pay from CFOs. | L | A clean, continuously-reconciled ledger (Autonomous Reconciliation live); ≥6–12 months of tenant history for signal; AI spend governance (OPS-3). | A cohort of tenants has 12+ months of reconciled data and asks "what happens next quarter?" |
| FUT-AI-2 | **Anomaly & fraud detection as a standalone value prop.** The Fraud Detection agent exists; expand duplicate-invoice / ghost-vendor / velocity checks into a monitored, tunable, alerting product with per-company baselines. | Catches money leaving before it leaves; strong story for larger tenants and their auditors. | M | Enough transaction volume per tenant to build a baseline; Auditor + Fraud agents live; human-review workflow (Approval Assistant). | First tenant crosses a volume where manual review is infeasible, or a real duplicate-payment near-miss is caught. |
| FUT-AI-3 | **Natural-language reporting ("ask your books").** Let a CFO ask, in Arabic or English, "why did gross margin drop in Q2?" and get a cited, drill-downable answer from the CEO Assistant / Financial Copilot. | Collapses the request-and-wait reporting cycle to seconds; the demo that sells the platform. | L | Reporting Agent + narrative commentary live; retrieval over `ledger_entries`; bilingual prompt quality; guardrails so it never fabricates a number. | Reporting Agent is stable and tenants are reading its scheduled narratives. |
| FUT-AI-4 | **Voice interface.** Voice-in / voice-out for the copilot (listed under TECH_STACK *Future Technologies* as "Voice AI"). "Read me the cash position" on a commute. | Accessibility + executive convenience; differentiates in a market where nobody else does it. | M | FUT-AI-3 (NL reporting) first — voice is a shell over it; strong Arabic ASR/TTS; mobile app (PRD-4). | NL reporting is adopted and mobile is shipped; a customer explicitly asks for hands-free. |

---

# Platform & Ecosystem

| ID | Idea | Value | Complexity | Dependencies | Revisit When |
|---|---|---|---|---|---|
| FUT-PLAT-1 | **Open API + partner marketplace.** [../api/PUBLIC_API.md](../api/PUBLIC_API.md) and [../api/PARTNER_API.md](../api/PARTNER_API.md) already exist; grow them into a published, self-serve developer platform with a partner app directory and revenue share. | Turns QAYD into a platform others build on; ecosystem lock-in; distribution via partners. | XL | Stable, versioned public API surface; OAuth scopes (already specced); SDK guidelines ([../api/API_SDK_GUIDELINES.md](../api/API_SDK_GUIDELINES.md)); developer support capacity; billing (PRD-2). | The public API has stabilized across a release cycle and inbound integration requests recur. |
| FUT-PLAT-2 | **GCC open-banking integrations.** Banking Service already ingests Open Banking feeds (`SyncOpenBankingJob`, `bank_oauth_tokens`) — expand to broad, per-country bank coverage across Kuwait, KSA, UAE, Bahrain, Oman. | Auto bank feeds are what make continuous reconciliation real; removes the biggest manual-import pain. | L | Per-bank/per-country open-banking availability and licensing (uneven across the GCC); consent/token security; Treasury Manager reconciliation live. | A target market's banks expose usable open-banking APIs, or a design-partner bank agrees to connect. |
| FUT-PLAT-3 | **Multi-entity consolidation.** Financial Statements already assumes multi-entity as "the norm, not the edge case," and General Ledger names group/consolidated reporting a "Future Improvement" via explicit elimination entries. Build the consolidation + intercompany-elimination engine. | Unlocks holding companies and groups — larger, higher-value tenants; a natural GCC structure (a parent with branches/subsidiaries across countries). | L | Rock-solid per-entity isolation first (never relax RLS to consolidate); intercompany matching; multi-currency translation at period rates (already in the ledger model). | A tenant operates two+ legal entities in QAYD and asks for a group view. |

---

# Compliance & Finance Expansion

| ID | Idea | Value | Complexity | Dependencies | Revisit When |
|---|---|---|---|---|---|
| FUT-CMP-1 | **E-invoicing / clearance compliance as it expands.** ZATCA-style QR + hash-chain generation is already modeled; extend to full clearance/integration and to new mandates as GCC countries roll them out (and Kuwait, when VAT activates — see OPEN_QUESTIONS CMP-1). | Compliance is a moat and a forcing function for adoption — mandated e-invoicing pulls customers onto a compliant platform. | L | The country-pack mechanism (already data-driven); per-authority integration work; a customer in the mandated jurisdiction to validate against. | A GCC authority sets an e-invoicing mandate date, or a KSA customer needs Phase-2 clearance. |
| FUT-CMP-2 | **Embedded lending / factoring signals.** QAYD sees receivables aging, cash position, and payment behavior continuously — surface (with explicit tenant consent) creditworthiness/factoring signals, or a referral to a financing partner. | New revenue line; genuine SME value (working-capital access is a real Gulf-SME pain). Not QAYD lending money — signals and referrals. | L | Deep, trustworthy financial data per tenant; explicit consent + data-sharing governance ([../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md)); a lending/factoring partner; **not** personalized financial advice — must stay signal/referral only. | Receivables and cash-position data are mature per tenant and a financing partner is interested. |

---

# Intelligence Across Tenants

| ID | Idea | Value | Complexity | Dependencies | Revisit When |
|---|---|---|---|---|---|
| FUT-INT-1 | **Anonymized cross-tenant benchmarking.** "Your DSO is 47 days; similar Kuwaiti trading SMEs average 38." Aggregate, k-anonymized, opt-in benchmarks — never raw cross-tenant data. | Insight no single company can compute for itself; sticky, and improves with scale (a data-network effect). | XL | **Hard** privacy engineering — the platform's non-negotiable is that one company's data never informs another's; benchmarking must be provably aggregate, opt-in, and k-anonymized. Enough tenants per segment for anonymity. Legal review. | Tenant count per segment is large enough that a benchmark is both statistically meaningful and anonymizable, and a privacy design is validated. |

---

# Assurance & Trust

| ID | Idea | Value | Complexity | Dependencies | Revisit When |
|---|---|---|---|---|---|
| FUT-TRUST-1 | **AI audit-trail assistant.** The Auditor agent + immutable `audit_logs` / `ai_logs` already record every action; build an assistant that walks an external auditor or regulator through "why was this posted, by whom/what, under which policy, citing which document" in plain language. | Turns the continuous audit trail into a self-service assurance product; shortens external audits; a trust story for enterprise and regulated tenants. | M | Continuous Audit + full audit-log coverage live; the decision/reasoning/citation contract already in [../ai/AI_FINANCE_OS.md](../ai/AI_FINANCE_OS.md); time-boxed external-auditor access role (already specced). | An external auditor engages with a QAYD tenant and needs to trace AI-posted entries, or an enterprise deal hinges on auditability. |

---

# How To Use This Backlog

- **Nothing here is scheduled.** Promotion to the roadmap requires: a fired "revisit-when" trigger,
  the MVP core being live and adopted, and an explicit product decision recorded as an ADR in
  [../developer/ARCHITECTURE_DECISIONS.md](../developer/ARCHITECTURE_DECISIONS.md).
- **Dependencies point down, not around.** Most of these sit on top of the same foundation:
  a continuously reconciled ledger, tenant isolation that is never relaxed, and the
  proposal-not-direct-write AI discipline. Building any of these by weakening those is not building
  the idea — it is breaking the platform.
- **Privacy is the ceiling, not a checkbox.** Cross-tenant intelligence (FUT-INT-1) and embedded
  lending (FUT-CMP-2) live or die on the promise that one company's data never leaks into another's.
  If an idea can only work by breaking that promise, it does not graduate from this document.
- **Keep it honest.** When an idea is picked up, move it out of here into a real spec with real
  estimates. This file holds possibilities, not plans.

# End of Document
