# Payments Research — Phase 3

**What payment infrastructure companies solved that QAYD is about to hit**

Version 1.0 · 2026-07-28 · `docs/research/payments/`
Phase 3 of the engineering research programme. Phase 1 was `docs/research/odoo/`; the standing guidance
distilled from it is `docs/architecture/knowledge/`.

---

## Why this study exists

Sprint 03 builds banking and reconciliation. Sprint 02 story S2-13 builds idempotency and the posted-event
broadcast. Both are problems that payment companies solved a decade ago, at a scale QAYD will not reach for
years, and **several of them published how.** Reading that work is cheaper than rediscovering it against a
customer's books.

This is not a market survey. The question throughout is narrow and engineering-shaped:

> *A system whose entire job is to move money without losing any — what did it decide, and why?*

The twelve companies in scope are **Stripe · Adyen · Square · PayPal · Wise · Plaid · Mercury · Brex ·
Ramp · Airwallex · Checkout.com · Mollie**. Two more were added during the work because they publish
better engineering material than most of the original twelve: **Modern Treasury** (ledger-as-a-product,
with an unusually good public writing series) and **TigerBeetle** (an open-source financial accounting
database whose data model is the most precisely specified in the corpus).

Depth was prioritised over coverage, per the brief. The companies with real public engineering content got
real attention; the ones that publish only sales pages are recorded as publishing only sales pages, which
is itself a finding.

---

## The six files, and what each answers

| If you are asking… | Read |
|---|---|
| *What is the landscape, and where do these systems fundamentally disagree?* | **`OVERVIEW.md`** |
| *What works, and what is the mechanism that makes it work?* | **`BEST_PRACTICES.md`** |
| *What fails, how does it fail, and how do I spot it in a diff?* | **`ANTI_PATTERNS.md`** |
| *Show me the actual data models, state machines, and algorithms.* | **`ARCHITECTURE.md`** |
| *What of this applies to QAYD, and what emphatically does not?* | **`LESSONS_FOR_QAYD.md`** |
| *What do I do, in what order, at what cost?* | **`IMPLEMENTATION_RECOMMENDATIONS.md`** |

**Reading order.** `OVERVIEW` → `BEST_PRACTICES` → `ANTI_PATTERNS` gives you the shape of the field in
about an hour. `ARCHITECTURE` is a reference, not a narrative — go to the section you need.
`LESSONS_FOR_QAYD` and `IMPLEMENTATION_RECOMMENDATIONS` are the only two files that make claims about
QAYD, and they are the two to read before starting S2-13 or any Sprint 03 story.

**If you have twenty minutes and a sprint to plan:** read `LESSONS_FOR_QAYD.md` §1 (where QAYD is already
ahead), §3 (the three real gaps), then `IMPLEMENTATION_RECOMMENDATIONS.md` §1 (R-01, R-02, R-03).

---

## Scope — what is covered

- **Ledger design.** How money movement is modelled as double entry: immutability, pending vs posted,
  holds and authorisations, multi-currency balances, bitemporality, concurrency control.
- **Idempotency.** Keys, replay windows, exactly-once semantics over an at-least-once network, and the
  precise relationship between an idempotency key and a database transaction.
- **Webhook design.** Signature schemes, delivery and ordering guarantees, retries, replay protection, and
  the case for treating a webhook as a notification rather than as truth.
- **Reconciliation and settlement.** Payout batching, T+N timing, fee accounting, and refunds, chargebacks
  and reversals as ledger events.
- **Event architecture.** The transactional outbox, the idempotent consumer, ordering guarantees, and the
  published costs of event sourcing.
- **API design.** Versioning, expansion, pagination, error taxonomy — with an attempt to say *why*
  Stripe's is considered best-in-class beyond "it is nice."
- **GCC relevance.** Which of these systems operate in Kuwait, what a Kuwaiti SME's payment rails actually
  are, and what bank-data access exists. This section contains the study's most consequential finding.

## Scope — what is deliberately not covered

- **Fraud and risk ML.** QAYD is not a payment service provider and will never underwrite card risk. Fraud
  appears only where it shapes a design QAYD *does* need (why settlement is delayed; why a new bank
  account must be verified before it can receive money).
- **PSP-scale infrastructure.** Adyen's sharding strategy and TigerBeetle's million-transactions-per-second
  target are recorded for what they teach about *contention*, not as things to build. QAYD's scale plan is
  `05_FUTURE_ARCHITECTURE.md`, and nothing here supersedes it.
- **Card scheme mechanics.** Interchange, scheme fees, EMV, 3-D Secure. Out of scope except where they
  appear as line items a merchant must book.
- **Anything that would require copying.** No code from any system studied is reproduced, ported, or
  imitated. Where a company's data model is described, it is described as a shape and a rationale, in
  order to argue about it.
- **Building a payment processor.** QAYD accounts for money movement; it does not acquire, authorise, or
  settle it. Every recommendation is filtered through that distinction.

---

## Evidence grading

Every factual claim carries one of four labels. This is not decoration — much of this domain is
proprietary, and the value of the study depends on the reader being able to tell a documented fact from a
plausible inference.

| Label | Meaning |
|---|---|
| `[DOCS]` | Stated in official documentation or a company's own engineering blog. A URL is cited. |
| `[CODE]` | Read from source. Used for QAYD's own code and for open-source systems. |
| `[COMMUNITY]` | Widely reported and consistent across sources, but not verified against a primary source. |
| `[INFERENCE]` | Reasoning from documented facts. Always labelled, never smuggled in as a fact. |
| `[UNKNOWN]` | Could not be determined. Stated rather than guessed. |

**`[UNKNOWN]` is used freely and on purpose.** The study contains substantial `[UNKNOWN]` sections —
Wise's ledger internals, KNET's public API surface, PayPal's engineering practice — and those gaps are
more useful than confident guesses would have been. A named gap can be closed later; a fabricated fact
propagates.

---

## Relationship to the existing knowledge base

**This document set is research input, not standing guidance.** It sits below
`docs/architecture/knowledge/` in precedence, exactly as `docs/research/odoo/` does:

```
MANIFEST.md                            vision and laws
  └── FINAL_TECH_STACK.md + ADRs       frozen architecture
        └── docs/architecture/knowledge/   standing guidance (01–09)
              └── docs/research/           ← this study (input, not authority)
                    └── docs/execution/    sprint plans
```

Where this study recommends something that contradicts a frozen decision, that requires a real ADR
(MANIFEST Law 1). One such case exists and is flagged explicitly: the idempotency storage design in
`docs/api/API_ARCHITECTURE.md` has a dual-write hole, argued in `LESSONS_FOR_QAYD.md` §3.1 and costed in
`IMPLEMENTATION_RECOMMENDATIONS.md` R-01.

**Cross-referencing, not restating.** The knowledge base already contains 19,857 lines on QAYD's ledger,
events, concurrency and immutability. This study points at those entries rather than repeating them. Where
you see `AD-14`, `P-11`, `R-24` or `I-01`, that is a pointer into
`docs/architecture/knowledge/`, and the pointer is the content. The most heavily referenced are:

- **`AD-03`, `AD-04`** — the ledger as append-only projection; exactly one writer.
- **`AD-14`, `P-11`** — after-commit domain events and the declared-but-unbuilt transactional outbox.
- **`AD-07`, `P-13`** — immutability and correction by reversal.
- **`AD-08`, `P-06`, `P-08`** — concurrency and locking.
- **`R-24`** — amount equality used as identity. The single most relevant rejection to Sprint 03.
- **`I-01`, `I-17`, `I-19`** — bitemporality, bounded autonomous reconciliation, the provisional ledger.

---

## Provenance and honesty notes

Research conducted 2026-07-28 against official documentation, company engineering blogs, and published
specifications. Primary sources are cited inline throughout. Four areas came back materially thinner than
intended, and are marked as such rather than padded:

1. **Wise publishes no substantive engineering writing on its ledger.** Multiple searches returned only
   third-party vendor content. Everything about Wise's internal model is `[UNKNOWN]`.
2. **PayPal likewise.** An engineering blog exists; no verified ledger content was found.
3. **KNET's own site was unreachable** (HTTP 403 on every attempt). What is known about Kuwait's national
   debit network here is assembled from the PSPs that front it, and the gaps are enumerated.
4. **Saudi and Kuwaiti regulatory detail** is partial — several central-bank pages 404'd. What *is*
   well-evidenced is the negative finding, which is the one that matters: Kuwait has no open banking
   regime, no account-information-service licence category, and no API standard.

The single most important finding in the study is in `LESSONS_FOR_QAYD.md` §3.1. It is not about payments
at all — it is that QAYD's specified idempotency layer stores its key in Redis after the database
transaction commits, which is the same dual-write bug the transactional outbox exists to prevent, sitting
in the one place designed to stop double-posting money.
