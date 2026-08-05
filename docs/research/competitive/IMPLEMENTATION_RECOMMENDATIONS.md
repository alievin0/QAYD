# Implementation Recommendations — C-01 … C-16

**Commercial recommendations in backlog-ready form.** Each carries why · benefits · tradeoffs · risks ·
scalability · performance · maintainability · complexity · effort (Fibonacci) · business impact ·
confidence · evidence.
Version 1.0 · 2026-07-28 · Derived from [`LESSONS_FOR_QAYD.md`](./LESSONS_FOR_QAYD.md)

---

## 0. How to use this document

**This folder cannot decide anything.** Per MANIFEST Law 1, an accepted recommendation becomes an ADR or a
backlog row, never a document that quietly becomes policy. Two of these require an ADR before code because
they constrain the schema: **C-05** (firm tenancy) and **C-01** (the business-model decision, because it
determines whether a meter axis exists).

**Effort** is Fibonacci points on QAYD's existing scale, and covers *engineering* effort only. Items that
are principally a decision, a conversation or a document are marked with their real cost in the field.

**Sequencing** is in §17. The order is not the numbering: several high-numbered items are cheaper and
should land first.

| Field | What it means here |
|---|---|
| **Why** | The mechanism, not the aspiration |
| **Risks** | What goes wrong if it is done, and what goes wrong if it is done badly |
| **Scalability / Performance / Maintainability** | Marked **N/A** where the item is commercial rather than technical — recorded honestly rather than padded |
| **Confidence** | High / Medium / Low, on the *recommendation*, not on the evidence |

---

# C-01 — Decide and record the business model

**Recommendation.** Adopt **per-org subscription (model 1), priced in KWD, with sell-to-the-firm (model 4)
as the distribution motion, single-plan bias, correctness never gated** — and record it as an ADR, because
it determines whether a usage meter exists, which is a schema question.

| | |
|---|---|
| **Why** | Four of the six observed models are structurally unavailable, refused, premature or turn QAYD into a services company (`LESSONS_FOR_QAYD.md` §6.2). Leaving the choice implicit means it gets made by whoever writes the first pricing page, and a meter cannot be retrofitted onto history that was never counted |
| **Benefits** | Removes a recurring re-litigation; unblocks C-02, C-03 and C-13; gives the schema a decision it currently lacks (`ARCHITECTURE.md` §5.2) |
| **Tradeoffs** | Forecloses the interchange and bundle models — both already unavailable in Kuwait, so the cost is theoretical |
| **Risks** | Deciding too specifically: the ADR should fix the *model*, not the *price*. A price recorded as architecture becomes hard to change for the wrong reason |
| **Scalability / Performance** | N/A |
| **Maintainability** | High — an ADR is cheaper to revisit than a pricing page with customers on it |
| **Complexity** | Low |
| **Effort** | **2** (the ADR; zero engineering) |
| **Business impact** | Foundational. Every other commercial recommendation depends on it |
| **Confidence** | **High** on the model; **Medium** on single-plan surviving the first ten deals |
| **Evidence** | `OVERVIEW.md` §7 (the taxonomy and preconditions); `[DOCS, via ../payments/ §4]` on why model 2 is unavailable |

---

# C-02 — Price and invoice in KWD, single plan, correctness never gated

**Recommendation.** One plan. Priced, quoted and **invoiced** in KWD to three decimals. No capability
behind a tier.

| | |
|---|---|
| **Why** | Zoho prices Kuwait, Bahrain, Oman and Qatar in **USD** while pricing the UAE and Saudi locally; Wafeq — a 2019 UAE startup — prices its Kuwait page in **KWD** `[DOCS]`. Buyers read that signal correctly. And the first document a customer receives from an accounting vendor is a demonstration of the product's competence |
| **Benefits** | A free credibility signal in the home market; zero tier-enforcement points, therefore zero places for a gate to be forgotten or bypassed; removes the scoping conversation from every sale and every renewal |
| **Tradeoffs** | Foregone expansion revenue from a tier ladder. Real, and the right trade at zero customers |
| **Risks** | A single price is indefensible at both ends of a heterogeneous base. Mitigation: when segmentation becomes necessary, segment on **scale** (entities, documents, users), never on capability — a scale gate reads a counter, a capability gate forks the code |
| **Scalability** | N/A |
| **Performance** | N/A |
| **Maintainability** | Strongly positive — one plan is one code path |
| **Complexity** | Low. The non-trivial part is QAYD's *own* invoice being arithmetically correct in KWD |
| **Effort** | **3** (billing in KWD with correct fils arithmetic) |
| **Business impact** | High and immediate — it is the cheapest home-market differentiation available |
| **Confidence** | **High** on KWD and on not gating; **Medium** on single-plan |
| **Evidence** | `[DOCS]` Zoho GCC pricing pages, Wafeq `/en-kw`, via `../accounting/` §8.7; `[DOCS, verified 2026-07]` Xero GLOBAL edition locking multi-currency to the top tier |

---

# C-03 — Decide the free-tier meter axis now; set the threshold later

**Recommendation.** Choose the meter (**documents processed** or **transaction volume**), build the
counter as a column, and **do not publish a threshold until per-document AI cost is measured**.

| | |
|---|---|
| **Why** | Puzzle's free tier runs to roughly $20k cumulative transaction volume `[DOCS]` — a meter on *business activity*, which graduates rather than annoys. But an AI-first product has a real marginal cost per document, so the threshold is unsettable without C-12's data |
| **Benefits** | The counter is nearly free now and impossible to backfill; deciding the axis prevents an accidental feature-meter; a success meter aligns vendor and customer incentives |
| **Tradeoffs** | No free tier in the interim, which costs some top-of-funnel |
| **Risks** | The meter becomes a cost-control device set below normal usage — which is `ANTI_PATTERNS.md` §6 and attacks the correction corpus directly. **Test: does a typical customer hit the meter in a typical month? If yes, it is the anti-pattern regardless of what it is called** |
| **Scalability** | Fine — a counter per tenant per period |
| **Performance** | Negligible |
| **Maintainability** | Good, provided the counter is written in the same transaction as the fact rather than after it |
| **Complexity** | Low for the counter; a real meter (idempotent, immutable history, declared period boundary) is a small financial subsystem and is **not** in scope here |
| **Effort** | **2** (counter) · **13** later if a billing meter is ever built |
| **Business impact** | Medium. Enables an evidence-based free tier instead of a guessed one |
| **Confidence** | **High** on deferring the threshold; **Medium** on which axis |
| **Evidence** | `[DOCS]` Puzzle pricing (vendor blog, not re-verified); `[DOCS]` Zoho's 200 scans/month as the counter-example |

---

# C-04 — Stand up a named practitioner design-partner cohort

**Recommendation.** Recruit 5–10 Kuwaiti bookkeepers and auditors as named design partners with direct
founder access and roadmap influence. **Do not build a certification programme.**

| | |
|---|---|
| **Why** | The professional who keeps the books chooses the software and the owner ratifies it — convergent across three independent categories (`OVERVIEW.md` §8.1). But certification's precondition is *inbound demand flowing through a directory*, and a directory with no leads is a certificate. The design-partner cohort buys the same advocacy without the precondition |
| **Benefits** | Advocacy; a real chart of accounts, a real Tally export and a real bank statement — inputs the product cannot obtain any other way; and it converts C-16's research into a standing relationship rather than a one-off |
| **Tradeoffs** | Founder time, which is the scarcest resource; and roadmap influence from a small biased sample |
| **Risks** | The cohort becomes a committee and the roadmap becomes the union of ten firms' wishes. Mitigation: influence over *sequencing*, never over *scope* |
| **Scalability** | Deliberately does not scale. That is the point (`BEST_PRACTICES.md` §5.5) |
| **Performance / Maintainability** | N/A |
| **Complexity** | Low |
| **Effort** | **5** (product-side: a way to onboard them; the rest is founder time) |
| **Business impact** | **High.** This is the distribution |
| **Confidence** | **High** on the mechanism; **conditional on C-16** for whether the community is reachable |
| **Evidence** | `[DOCS]` Zoho's certified partner programme structure; `[DOCS]/[COMMUNITY]` Basis, Pennylane, Truewind all firm-facing |

---

# C-05 — Decide firm-above-company tenancy, and build the minimum that keeps it open ⚠️

**Recommendation.** Write an ADR answering: *is `company_id` the top of the tenancy hierarchy?* If the
answer is no, add `firms` and a **temporal** `firm_engagements (firm_id, company_id, status,
effective_from, effective_to)`, make the session GUC able to carry a firm context, and write the RLS
predicate so a firm context resolves to an **enumerated set** of `company_id`s.

**This is the only recommendation in the folder that becomes impossible rather than merely expensive.**

| | |
|---|---|
| **Why** | Three reasons it is not reversible: RLS is a table feature, so a boundary cannot be re-protected after the fact; RESTRICTIVE policies combine with AND, so a firm predicate must live *inside* the company predicate rather than beside it; and **every composite unique constraint carrying `company_id` encodes the current tenancy shape** — a composite unique omitting it is a cross-tenant existence oracle, because referential-integrity checks always bypass row security `[DOCS]`. Changing the shape means revisiting every one of them under live data with a security property riding on each |
| **Benefits** | Keeps the entire channel strategy available; makes firm-scoped work queues expressible (C-07); makes the firm an accountable actor in the audit trail before that trail is designed |
| **Tradeoffs** | Two tables and a policy that nothing uses yet. Against MANIFEST Law 2 on its face — resolved in `ARCHITECTURE.md` §4.4: Law 2 governs features; this is a foundation, and *"foundations cannot be retrofitted"* (`../erp/` L-13) |
| **Risks** | **Doing it badly is worse than not doing it.** A cross-tenant read implemented as a *bypass* rather than a *scoped enumeration* is the ambient-privilege failure `../security/` §2.4 describes — and every such bypass began as a silently-empty result someone needed to fix. The firm predicate must fail **loudly** |
| **Scalability** | Neutral. The enumerated set is small (a firm has tens of clients, not thousands) |
| **Performance** | A set-membership test instead of an equality test in the RLS predicate. Measurable, small, and indexable |
| **Maintainability** | Positive — it removes the future migration entirely |
| **Complexity** | Medium, and concentrated in the RLS policy, which is the highest-scrutiny code in the system |
| **Effort** | **8** (schema + policy + the adversarial isolation test that must accompany it) · **2** for the ADR |
| **Business impact** | **Highest structural impact in the folder.** It is the difference between the channel being available and being a rewrite |
| **Confidence** | **High** that the decision must be made now; **Medium** on whether the answer is yes — that depends on C-16 |
| **Evidence** | `[DOCS]` PostgreSQL RLS + referential-integrity bypass, via `../security/` §2.1; `[COMMUNITY]` Pennylane's shared workspace at ≈4,500–6,000 firms; `[CODE]` QAYD's current RESTRICTIVE boundary |

---

# C-06 — One-field client invitation

**Recommendation.** An accountant adds a client by entering one value. The invitation is an **object with a
lifecycle** (issued → accepted → active → revoked), not a side effect of user creation.

| | |
|---|---|
| **Why** | The accountant's per-client onboarding friction is the rate limiter on the entire channel: forty clients means doing it forty times, and ten minutes instead of one costs six professional hours to adopt the product — a larger switching cost than anything in the product. Practitioners say directly that on Xero and QuickBooks it is *"you type an email address and that's it"* while the alternative is clunkier `[COMMUNITY]` |
| **Benefits** | Removes a self-inflicted switching cost; and the object lifecycle is what allows the accountant to issue an invitation *before the client exists*, which is how the real workflow runs |
| **Tradeoffs** | An invitation object is more than a token column |
| **Risks** | An invitation that grants access before verification is a tenancy hazard. The accepting side must establish identity; the issuing side establishes only intent |
| **Scalability** | Fine |
| **Performance** | N/A |
| **Maintainability** | Good — lifecycle as explicit states rather than nullable timestamps |
| **Complexity** | Low-Medium |
| **Effort** | **5** |
| **Business impact** | High — it is the channel's throughput |
| **Confidence** | **High** |
| **Evidence** | `[COMMUNITY]` practitioner complaints via `../accounting/` §6 |

---

# C-07 — Firm-scoped work queues (deferred, but designed with C-05)

**Recommendation.** A review queue, coding queue and close-status board expressible over *the firm's* work.
**Not an MVP item** — but the query shape must be possible under C-05's tenancy decision.

| | |
|---|---|
| **Why** | The firm's actual job is the cross-client work. A per-tenant queue makes the firm-level view N queries and a merge, which does not paginate, does not sort correctly and does not scale past a handful of clients — which is the concrete cost of the portal architecture (`ANTI_PATTERNS.md` §15) |
| **Benefits** | Makes the firm-and-client shared workspace a real product rather than a login convenience |
| **Tradeoffs** | Real surface area. Correctly deferred |
| **Risks** | Building it before C-05 forces a per-tenant shape that then has to be undone |
| **Scalability** | Depends entirely on C-05 being right. A queue over an enumerated `company_id` set paginates; a merge of N queries does not |
| **Performance** | The reason it must be one query |
| **Maintainability** | N/A yet |
| **Complexity** | Medium-High |
| **Effort** | **13** when built |
| **Business impact** | High, later. Zero now |
| **Confidence** | **High** on the shape; **High** on deferring |
| **Evidence** | `[COMMUNITY]` Pennylane's workspace model; `[INFERENCE]` on the query-shape argument |

---

# C-08 — Close `trg_no_ai_autopost` on `UPDATE` ⚠️

**Recommendation.** Extend the trigger to `UPDATE`, including the subtler cases: a non-AI row flipped to
`ai_generated`, and an AI row whose `approved_by` is set by the same actor that created it.

**This is a security item that this folder is escalating on commercial grounds.**

| | |
|---|---|
| **Why** | The trigger fires on `INSERT` only. An AI-generated row inserted as a draft and subsequently `UPDATE`d toward posted meets no trigger at all `[CODE, via ../security/ §3.1]`. **QAYD's central commercial claim — "the agent has no grant that would let it post" — is currently true on `INSERT` and false on `UPDATE`.** A competitive claim a competent reviewer can falsify is worse than no claim |
| **Benefits** | Makes the product's headline claim true; `../security/` calls it the **terminal control on prompt injection** — every other AI control reduces likelihood, this one bounds impact regardless of what the model was told |
| **Tradeoffs** | None identified. It is effort 2 |
| **Risks** | The trigger will be under pressure later, because it is the control that says "no" to an automation someone wants. Recording *why* it exists is part of the fix |
| **Scalability** | N/A |
| **Performance** | A trigger on `UPDATE` of `journal_entries`. Negligible |
| **Maintainability** | Improves it — the two paths stop disagreeing |
| **Complexity** | Low |
| **Effort** | **2** |
| **Business impact** | **High, and asymmetric.** It converts a falsifiable claim into a defensible one |
| **Confidence** | **High** |
| **Evidence** | `[CODE]` `2026_07_28_000004_create_journal_entries_table.php`, via `../security/` §3.1 |

---

# C-09 — Ship the reviewed state as a product surface, and instrument it

**Recommendation.** Surface reviewer identity, approval time, cited source rows and confidence as
first-class, exportable evidence — and instrument approval per `../ai/` L-09: engagement above
materiality, time-to-approve telemetry, and a **blind-sampled second-review stream**.

| | |
|---|---|
| **Why** | **Xero's JAX auto-reconcile is still Beta, gated above the entry tier, produces no auditable "reviewed by a human" state, and drops description and reference** `[COMMUNITY, verified 2026-07]`. The buyer of accounting automation is buying the ability to *sign off*, not the automation. What is a beta roadmap item for the category leader is a schema property for QAYD |
| **Benefits** | The single most specific competitive opening in this folder; and the blind stream is the only accuracy estimate **not conditioned on the reviewer having seen the model's opinion**, which makes it the only valid input to a calibration curve |
| **Tradeoffs** | The blind stream trades approval speed for approval reliability. `../ai/` L-09 flags this as a **design-partner question, not an engineering one** — which is what C-04 is for |
| **Risks** | Rubber-stamping. A reviewed state approved at 99% is the same failure with better paperwork. And **bulk approve must be recorded as bulk** and excluded from the accuracy estimate, or the metric is silently poisoned |
| **Scalability** | Fine |
| **Performance** | Telemetry on an interactive path; keep it out of the transaction |
| **Maintainability** | Good |
| **Complexity** | Medium — the blind stream is the hard part, and it is political rather than technical |
| **Effort** | **8** (surface) · **8** (instrumentation + blind stream) |
| **Business impact** | **High.** It is the worked example for the whole positioning |
| **Confidence** | **High** on the surface; **Medium** on the blind stream's acceptance by real users |
| **Evidence** | `[COMMUNITY]` Xero Product Ideas, verified 2026-07; `../ai/LESSONS_FOR_QAYD.md` L-09, L-10 |

---

# C-10 — Adopt a present-tense claims discipline, in writing

**Recommendation.** A one-page standard: marketing describes what ships today; beta is labelled beta;
roadmap is labelled roadmap. **Two named prohibitions: never call statement ingestion a "bank feed", and
never describe the AI loop as autonomous.**

| | |
|---|---|
| **Why** | The audience is accountants, whose professional value rests on not being fooled, in a community small enough that one discovered overclaim propagates. And the true claim is stronger than the overclaim: *the AI cannot post, by construction* beats *the AI is autonomous* |
| **Benefits** | Protects the only channel QAYD has; costs nothing; and it disciplines the research too — **third-party blogs claiming "Arabic is available in Xero" are false** `[DOCS]`, which is exactly the error QAYD would be committing in the other direction |
| **Tradeoffs** | Weaker-sounding copy than competitors'. This is a feature |
| **Risks** | The rule erodes under launch pressure. Mitigation: it is a written standard reviewed with the pricing page, not a value |
| **Scalability / Performance / Maintainability** | N/A |
| **Complexity** | None |
| **Effort** | **0** engineering (one document) |
| **Business impact** | Medium, and insurance-shaped: it prevents a loss rather than producing a gain |
| **Confidence** | **High** |
| **Evidence** | `[DOCS]` Zoho's auto-categorisation documented as Early Access, opt-in by email; `[DOCS]` the open unactioned Xero Arabic idea contradicting third-party blogs |

---

# C-11 — Free assisted migration from Tally and Excel, for the first cohort

**Recommendation.** A first-class importer, plus **free, QAYD-performed migration** for the first cohort of
customers. Time-limited and standardised — not billed and not bespoke.

| | |
|---|---|
| **Why** | Switching cost, not quality, protects the incumbent — and **Tally is the real incumbent in a Kuwait SME deal** `[COMMUNITY]`, not QuickBooks. Zoho's Tally path is a manual export sequence with no tool, which has spawned a paid third-party migration industry `[DOCS]/[COMMUNITY]`. That industry is a market inefficiency |
| **Benefits** | Removes the incumbent's actual moat; and it is the cheapest possible way to see a real Kuwaiti chart of accounts, a real Tally export and a real bank statement — inputs the product needs and cannot get any other way |
| **Tradeoffs** | Founder/engineer time per customer, at the exact moment it is scarcest |
| **Risks** | **This is one step from `ANTI_PATTERNS.md` §18 — becoming a services business by accident.** The boundary that keeps it safe: free and standardised, never billed and bespoke; and the second cohort gets the tool, not the service |
| **Scalability** | Deliberately unscalable, deliberately time-limited |
| **Performance** | N/A |
| **Maintainability** | The importer is maintainable; the service is not, which is why it must expire |
| **Complexity** | Medium (Tally export formats are `[UNKNOWN]` in detail and should be assumed worse than expected) |
| **Effort** | **13** (importer with opening balances and a conversion date) |
| **Business impact** | High — it is what makes the first cohort possible at all |
| **Confidence** | **High** on the need; **Medium** on the effort estimate |
| **Evidence** | `[COMMUNITY]` Tally as the Gulf SME incumbent; `[DOCS]/[COMMUNITY]` the third-party Tally→Zoho migration industry |

---

# C-12 — Per-tenant AI cost attribution

**Recommendation.** Every model call records tenant, capability, model tier, input/output tokens,
`cache_read_input_tokens` and whether it was batched. Route it through the outbox to the **advisory**
analytics tier — DuckDB over Parquet, out of process, never in the accounting database.

| | |
|---|---|
| **Why** | C-03's threshold cannot be set without cost-to-serve per tenant. And it **cannot be backfilled**: token counts exist only at the moment of the call, so a month of un-instrumented usage is a month with no cost data, permanently. This is the same class of gap `../analytics/` L-01 names — *"an alert that cannot fire is a plan, not a control"* |
| **Benefits** | Makes unit economics answerable; `cache_read_input_tokens` is already required to be asserted in tests (`../ai/` L-17), so half the instrumentation is a by-product; and cost-to-serve is one of the numbers that decides whether QAYD is a good business |
| **Tradeoffs** | An outbox consumer and an analytics job to operate |
| **Risks** | Tenant-derived data leaving the RLS boundary. Mitigation: the advisory tier is credentialed read-only against an advisory bucket, and **no tenant-facing figure is ever computed there** (`../analytics/` §7). Second risk: success breeds misuse — someone points a product feature at it |
| **Scalability** | Excellent — event volume is bounded by human business activity |
| **Performance** | Recording is a row; the analysis is out of process |
| **Maintainability** | Good — a pinned library, no service |
| **Complexity** | Low-Medium |
| **Effort** | **5** (telemetry + outbox feed) · **3** (the DuckDB job harness) |
| **Business impact** | Medium now, decisive at pricing time |
| **Confidence** | **High** |
| **Evidence** | `../analytics/LESSONS_FOR_QAYD.md` L-01, L-04; `../ai/LESSONS_FOR_QAYD.md` L-17, L-18 |

---

# C-13 — Name the never-gated set, and give it no plan-check call sites

**Recommendation.** Record the list — three-decimal precision, audit trail and its export, full data
export, immutability and reversal correctness, multi-currency when it ships, Arabic UI and Arabic
documents — and ensure **no plan-check call site exists** on any of them.

| | |
|---|---|
| **Why** | A tier boundary is a public statement about what the vendor thinks is optional; an accountant reading *"multi-currency: top tier only"* learns the vendor does not consider correct books a baseline. **Xero's GLOBAL edition — the one a Kuwaiti buyer receives — does exactly that** `[DOCS, verified 2026-07]`, which converts a QAYD principle into a stateable difference |
| **Benefits** | Zero enforcement points is zero places to forget one; and **a capability that is gateable by configuration will eventually be gated by configuration**, so an absent call site is a stronger control than a policy |
| **Tradeoffs** | Foregone upsell on genuinely expensive capabilities (multi-currency in particular) |
| **Risks** | Revenue pressure re-opens it. Mitigation: adding a gate later requires *writing code*, which is a visible decision rather than a config change |
| **Scalability / Performance** | N/A |
| **Maintainability** | Positive — fewer branches |
| **Complexity** | None. It is an absence |
| **Effort** | **1** (the list, plus a CI check that the named capabilities have no plan reference) |
| **Business impact** | Medium, and it compounds with C-02 |
| **Confidence** | **High** |
| **Evidence** | `[DOCS]` Xero GLOBAL edition packaging; `../accounting/ANTI_PATTERNS.md` §16 |

---

# C-14 — Make portability a demonstrated sales asset

**Recommendation.** A migration-grade export — chart of accounts with hierarchy, entries and lines with
**both** currency amounts at full decimal fidelity, reconciliation matches as rows, the audit trail, and
the source documents — plus the archive **manifest carrying `ledger_head_hash`**. Demonstrate it in the
sales process, unasked.

| | |
|---|---|
| **Why** | A pre-launch startup asking a business to move its general ledger is asking for a large act of trust, and **a pre-launch startup is a worse continuity risk than Intuit** — which stopped new QuickBooks signups in India in July 2022 and ended the product in April 2023 `[DOCS]`. *"You can leave with everything, and here is the export"* is the cheapest available substitute for a track record |
| **Benefits** | Most of the work is already required by the Parquet archive (`../analytics/` L-02, L-03); and the manifest's head hash supports a claim no competitor in this study can make about an export — *"this archived fiscal year is provably the one the books contained"* |
| **Tradeoffs** | It genuinely lowers QAYD's own switching cost. That is the point |
| **Risks** | An export that is incomplete or lossy is worse than none, because the claim was made. **Decimal fidelity is the specific hazard**: map `NUMERIC(19,4)` to `DECIMAL(19,4)`, never `DOUBLE` |
| **Scalability** | Good — it is the archive path |
| **Performance** | A scheduled job |
| **Maintainability** | Good, given the manifest makes the archive self-describing |
| **Complexity** | Medium |
| **Effort** | **8** (customer-facing export on top of the archive work) |
| **Business impact** | Medium-High — it answers the question `../security/` reports is asked more often than any certification question |
| **Confidence** | **High** |
| **Evidence** | `[DOCS]` Intuit's India exit; `../analytics/LESSONS_FOR_QAYD.md` L-02, L-03 |

---

# C-15 — Mine the incumbents' published ceilings and idea boards

**Recommendation.** Maintain a short register of (a) competitor published limits and (b) high-vote requests
publicly answered "not in pipeline". Use (a) as a qualified-lead filter and (b) as a requirements input —
**not as a roadmap**.

| | |
|---|---|
| **Why** | **Xero publishes 5,000 invoices/month, 5,000 bank transactions/month, 4,000 inventory items, 10,000 contacts and 500 fixed assets** `[DOCS, verified 2026-07]`. And its idea board carries **400–1,150-vote requests for basic accounting functions — bad-debt write-off, invoice subtotals, unapprove, statement ageing, scheduled reports, an audit-trail report — answered "Not in pipeline" with a nudge toward the App Store** `[COMMUNITY, verified 2026-07]`. **A 1,150-vote request publicly answered "not in pipeline" is a specification a competitor has committed not to build** |
| **Benefits** | A published incumbent ceiling is a better conversation opener than any feature comparison — a prospect who has hit 500 fixed assets has a concrete, dated, vendor-documented reason to look elsewhere. The 500-fixed-asset limit is the sharpest, because GCC SMEs are capital-heavy `[INFERENCE on relevance; the limit is `[DOCS]`]` |
| **Tradeoffs** | It is a pull toward `ANTI_PATTERNS.md` §1 — building the category's feature list |
| **Risks** | Treating the vote list as a backlog. **The filter is: build it only if QAYD's architecture makes it cheap and it serves the wedge.** Most of the list fails that filter |
| **Scalability / Performance / Maintainability** | N/A |
| **Complexity** | None |
| **Effort** | **1** (a register, re-verified quarterly) |
| **Business impact** | Medium — sharpens qualification and positioning |
| **Confidence** | **High** on the register; **Medium** on how much of the vote list is worth acting on |
| **Evidence** | `[DOCS]` Xero published limits; `[COMMUNITY]` Xero Product Ideas, verified 2026-07 |

---

# C-16 — Close the channel `[UNKNOWN]` with ten conversations ⚠️

**Recommendation.** Verify, with primary research, that Kuwait's bookkeeping and audit community is small,
concentrated and reachable — and find out what they actually run today. **Ten conversations, plus
KNFSMD / PACI / the Kuwait Association of Accountants and Auditors for counts.**

| | |
|---|---|
| **Why** | The entire channel strategy — which `LESSONS_FOR_QAYD.md` §4 argues is not a growth option but the mechanism that makes QAYD's chosen sale possible at all — rests on a single unverified `[INFERENCE]`. Counts of Kuwaiti SMEs, licensed auditors and bookkeeping firms are `[UNKNOWN]` (`OVERVIEW.md` §10 item 4), and **what Kuwaiti practitioners actually run** was never searched in Arabic-language sources (item 5) |
| **Benefits** | It is the cheapest high-value item in the entire Phase 3 programme. It also settles three other things at once: whether Tally really is the incumbent (C-11's premise), whether Wafeq has Kuwait traction (item 11), and — per `../security/` §5.1 — **whether the first attestation ask will be SOC 2 or ISO 27001, which is a $40,000 decision settled by one email** |
| **Tradeoffs** | Founder time, and the discomfort of possibly falsifying the strategy |
| **Risks** | **The real risk is confirmation.** Ten friendly conversations will produce ten encouraging answers unless the questions are designed to disconfirm. Ask what they run and why they have not switched — not whether they like the idea |
| **Scalability / Performance / Maintainability** | N/A |
| **Complexity** | None |
| **Effort** | **0** engineering. Approximately two weeks of founder time |
| **Business impact** | **Highest information value in the folder.** C-04, C-05 and the whole of §4 are conditional on it |
| **Confidence** | **High** that it should be done first |
| **Evidence** | `OVERVIEW.md` §10 items 4, 5, 10, 11; `../accounting/OVERVIEW.md` §8.7 |

---

# 17. Sequencing

Ordered by *information value per unit of cost*, not by number. The first three cost almost no engineering
and unblock most of the rest.

| Order | Item | Effort | Gate | Why here |
|---|---|---|---|---|
| 1 | **C-16** Ten practitioner conversations | 0 eng | — | Everything downstream is conditional on it, and it is two weeks |
| 2 | **C-08** Close the AI trigger on `UPDATE` | 2 | — | The headline claim is currently falsifiable |
| 3 | **C-10** Present-tense claims discipline | 0 eng | — | Free, and it protects the channel before the first marketing surface exists |
| 4 | **C-01** Business-model ADR | 2 | C-16 | Unblocks C-02, C-03, C-13 |
| 5 | **C-05** Firm-tenancy ADR + minimum schema | 8 + 2 | C-16, C-01 | **Before the first customer.** The only item that becomes impossible |
| 6 | **C-13** Name the never-gated set | 1 | C-01 | It is an absence; cheapest to establish before there is a plan check anywhere |
| 7 | **C-12** Per-tenant AI cost attribution | 5 + 3 | AI engine exists | Cannot be backfilled |
| 8 | **C-03** Meter axis + counter | 2 | C-01 | The counter is a column; the threshold waits for C-12 |
| 9 | **C-02** KWD pricing, single plan | 3 | C-01 | Needs billing to exist |
| 10 | **C-15** Competitor ceiling register | 1 | — | Standing, quarterly |
| 11 | **C-06** One-field client invitation | 5 | C-05 | The channel's throughput |
| 12 | **C-04** Design-partner cohort | 5 | C-16, C-06 | Needs somewhere to put them |
| 13 | **C-11** Tally/Excel migration | 13 | Ledger + COA | The first cohort's precondition |
| 14 | **C-09** Reviewed state + instrumentation | 8 + 8 | AI proposal flow | The positioning's worked example |
| 15 | **C-14** Portability as a sales asset | 8 | Archive work | Rides on `../analytics/` L-02/L-03 |
| 16 | **C-07** Firm-scoped queues | 13 | C-05, C-06 | Correctly deferred; the shape must survive C-05 |

**Total engineering effort across all sixteen: 89 points**, of which **13 points (C-08, C-13, C-05's ADR,
C-01, C-03's counter) are the items whose cost rises with delay.** Everything else can wait for evidence.

---

# 18. What this document deliberately does not recommend

Recorded so the absences are visible as decisions.

| Not recommended | Why |
|---|---|
| **A price** | The shape is a research conclusion; the number needs willingness-to-pay research nobody has done. C-02 fixes the currency and the structure, not the figure |
| **A certification programme** | Its precondition — inbound demand flowing through a directory — fails at zero customers, and a premature programme burns the goodwill of the exact community C-04 needs (`BEST_PRACTICES.md` §5.4) |
| **An app marketplace or public platform API** | An ecosystem is a commitment never to fix your foundations (`ANTI_PATTERNS.md` §4). A webhook and an export are the answer to an integration request |
| **A Saudi ZATCA push** | It means entering as the challenger to an accredited, in-Kingdom, SAR-60/month incumbent — the inverse of QAYD's Kuwait position. It may eventually be right; it is not a Phase-3 recommendation |
| **An attestation (SOC 2 or ISO 27001)** | `../security/` §5.1: buy none until a named deal is blocked on one, and **ask that customer which they want** rather than guessing. C-16 gets the answer for free |
| **Any integration that reads a competitor's API** | `ANTI_PATTERNS.md` §10, and now contractually hazardous under Xero's March 2026 terms `[DOCS]` |
| **A usage-billing meter** | Premature. The counter is recommended (C-03); the meter is a small financial subsystem and building one before there is anything to meter is building the future |

---

*Every recommendation above is a mechanism with QAYD-native reasoning. None is recommended on the grounds
that a competitor does it, and the ones derived from observing a competitor state the mechanism rather
than the observation. No pricing page, UI, document or code was reproduced.*

# End of Document
