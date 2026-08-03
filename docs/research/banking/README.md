# Core Banking Research — `docs/research/banking/`

**Phase 3 of the QAYD engineering research programme.**
Domain: **core banking ledgers** — Thought Machine Vault, Mambu, Temenos, Finastra, FIS, Fiserv/Finxact,
Banking-as-a-Service platforms, and the openly-documented ledger engines (TigerBeetle, Formance,
Modern Treasury, Increase, Column).

Version 1.0 · 2026-07-28 · Research artifact. **Not a specification. Not a commitment.**
Commissioned by: Ali S (Architecture Owner)

---

## Why this category exists

Every other system QAYD has studied — Odoo, SAP, NetSuite, ERPNext — treats ledger integrity as *a
feature of the accounting module*. Core banking treats it as **the product**. A bank that loses a
posting is not shipping a bug; it is committing a regulatory offence. That difference in stakes has
produced thirty years of engineering that accounting software never had to do, and most of it is
published or inferable from openly distributed SDKs.

The question this research answers is **not** "how do we build a bank". It is:

> Core banking treats ledger integrity as a solved, non-negotiable engineering problem in a way
> accounting software does not. **What specifically should QAYD adopt from that posture, and what is
> genuinely unnecessary for an SME accounting product?**

`LESSONS_FOR_QAYD.md` answers it directly, and draws the line explicitly. **QAYD is not a bank.**
Importing bank-grade complexity wholesale would be a serious mistake, and several sections here exist
specifically to say *do not do this*.

---

## Rules of engagement

Identical to `docs/research/odoo/` and the knowledge base:

1. **No code copied, ported, or adapted.** Principles only. Where a type name or field name is cited,
   it grounds a claim about a *design idea* — it is not a template to reimplement.
2. **Evidence-graded, every claim.** `[DOCS]` (with URL) · `[CODE]` (with repo path) ·
   `[COMMUNITY]` (with URL) · `[INFERENCE]` (clearly labelled reasoning) · `[UNKNOWN]`.
3. **Core banking is heavily proprietary.** Temenos, Finastra, FIS and most of Fiserv publish almost
   nothing at engineering depth. Those sections are **short and honest**. A three-paragraph accurate
   section beats a thirty-paragraph invented one, and there is no architecture in this document that
   was reconstructed from a marketing page and presented as fact.
4. **Depth follows evidence.** Thought Machine and TigerBeetle get the most space because they publish
   the most. That is a deliberate weighting, not an endorsement.

---

## The documents

| # | Document | What it is |
|---|---|---|
| 1 | `OVERVIEW.md` | The landscape: who these vendors are, what they actually publish, evidence-availability matrix, and the seven ideas core banking has that accounting software lacks |
| 2 | `ARCHITECTURE.md` | **The deep one.** Data models, algorithms, state machines and ASCII diagrams — Vault's posting/balance model, TigerBeetle's transfer engine, tri-temporal dating, hash-chain and Merkle-proof designs, hot-account contention |
| 3 | `BEST_PRACTICES.md` | What these systems do that works, and the *mechanism* by which it works |
| 4 | `ANTI_PATTERNS.md` | What fails, and the mechanism of failure — including bank patterns that would be actively harmful in QAYD |
| 5 | `LESSONS_FOR_QAYD.md` | The discriminating document: adopt / adapt / reject, each with a stated reason. Where the line is |
| 6 | `IMPLEMENTATION_RECOMMENDATIONS.md` | Sequenced recommendations (BR-01…) with effort (Fibonacci), confidence, and explicit wiring into the dormant `audit_logs.hash`/`prev_hash` columns and the **S2-14** nightly integrity job |

Read `OVERVIEW.md` → `LESSONS_FOR_QAYD.md` if you have twenty minutes.
Read `ARCHITECTURE.md` before designing anything that touches postings.

---

## Relationship to prior work

This research **cross-references and never restates** the existing knowledge base:

| Prior work | How banking research relates |
|---|---|
| `knowledge/01_ENGINEERING_PRINCIPLES.md` | Banking supplies enforcement *mechanisms* for principles QAYD already holds (immutability, exact arithmetic, one write path). It does not introduce new principles. |
| `knowledge/02_ARCHITECTURE_DECISIONS.md` | Touches **AD-10** (fiscal periods) and the still-open decisions around idempotency and event delivery. Proposes no ADR reversal. |
| `knowledge/03_DESIGN_PATTERNS.md` | Extends **P-11** (transactional outbox), **P-13** (reversal), **P-15** (derived balances). Adds banking-grade variants, does not replace them. |
| `knowledge/04_REJECTED_PATTERNS.md` | Confirms several rejections independently (mutable balances, floats, multiple write paths) and proposes **no** amendments. Adds new candidate rejections in `ANTI_PATTERNS.md`. |
| `knowledge/05_FUTURE_ARCHITECTURE.md` | The hot-account contention analysis is directly relevant to the scale tiers; banking's answer (batching, not sharding) is recorded here. |
| `knowledge/07_QAYD_INNOVATION.md` | I-01…I-20 already reference TigerBeetle/Modern Treasury/Twisp at lines 185 and 969 as *infrastructure ledgers that are not accounting systems*. This research substantiates that claim with detail rather than contradicting it. |
| `knowledge/08_MASTER_BACKLOG.md` | Feeds **S2-13** (idempotency), **S2-14** (nightly integrity job) and **S4+A** (hash-chain activation) with concrete design. |
| `docs/research/odoo/` | Odoo is the *accounting* reference. Banking is the *integrity* reference. They answer different questions and are not in tension. |

**Precedence is unchanged.** This is a research artifact at tier 4 — below `MANIFEST.md`, below frozen
ADRs, below the knowledge base. Nothing here overturns a frozen decision; anything that would need a
real ADR, and `IMPLEMENTATION_RECOMMENDATIONS.md` says so where it applies.

---

## The honest summary of evidence quality

| System | Evidence available | Depth achieved here |
|---|---|---|
| **Thought Machine Vault** | Openly-distributed Contracts SDK (mirrored publicly), published performance whitepaper; reference docs gated | **High** — the posting/balance model is recovered from primary sources |
| **TigerBeetle** | Fully open source, extensive published design rationale | **High** |
| **Modern Treasury / Increase / Column** | Public API documentation of real depth | **High** |
| **Formance** | Open source | **Medium-High** |
| **Mambu** | Public API reference; architecture described commercially | **Medium** |
| **Fiserv / Finxact** | Partially public API material | **Low-Medium** |
| **Temenos** | Almost entirely gated behind partner login | **Low — stated plainly** |
| **Finastra** | Developer portal exists; ledger internals undisclosed | **Low — stated plainly** |
| **FIS** | Effectively closed | **Very low — stated plainly** |

The `[UNKNOWN]` markers in these documents are **content, not gaps in the work.** Knowing precisely
what a competitor does *not* publish is itself useful: it tells you where you cannot be beaten by
copying, and where you must reason from first principles.
