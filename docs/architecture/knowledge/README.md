# QAYD Engineering Knowledge Base

**The permanent engineering reference. Consult this before designing anything.**

Version 1.0 · 2026-07-28 · 19,751 lines across nine documents

---

## Why this exists

QAYD's architecture should be decided **from first principles**, not by re-researching other ERPs every
time a question comes up. This knowledge base is the output of that research, converted into standing
guidance: what we believe, what we decided and why, which patterns to reuse, which to refuse, how the
system scales, where we stand against the market, what we intend to invent, in what order, and how to
work here.

It was written after a deep study of Odoo 19 (`docs/research/odoo/`) and a comparative study of ERPNext,
SAP S/4HANA, Oracle NetSuite, Microsoft Dynamics 365, Akaunting and Dolibarr. **No code from any of them
is used or reproduced.** Where an incumbent is better than QAYD, these documents say so.

---

## Which document answers your question

| If you are asking… | Read |
|---|---|
| *Why does QAYD do things this way?* | **01 — Engineering Principles** |
| *Why did we choose X over Y, and when should we revisit it?* | **02 — Architecture Decisions** |
| *How do I build a new financial subsystem correctly?* | **03 — Design Patterns** |
| *Is this approach forbidden, and why?* | **04 — Rejected Patterns** |
| *Will this survive 10,000 customers? What breaks first?* | **05 — Future Architecture** |
| *How do we compare to Odoo / SAP / NetSuite / ERPNext?* | **06 — Competitive Analysis** |
| *What should we build that nobody else has?* | **07 — QAYD Innovation** |
| *What comes next, and what does it depend on?* | **08 — Master Backlog** |
| *I'm new here. Where do I start?* | **09 — Engineering Playbook** |

---

## The documents

| # | Document | Lines | What it is |
|---|---|---|---|
| 01 | `01_ENGINEERING_PRINCIPLES.md` | 3,159 | 23 principles, each with its enforcement mechanism — plus a register of the places where enforcement is currently missing |
| 02 | `02_ARCHITECTURE_DECISIONS.md` | 2,104 | 21 decisions (AD-01…AD-21) with alternatives, tradeoffs, lifespan and confidence; plus 10 decisions deliberately still open |
| 03 | `03_DESIGN_PATTERNS.md` | 3,056 | 19 reusable patterns (P-01…P-19) with reference implementations from the real codebase |
| 04 | `04_REJECTED_PATTERNS.md` | 2,734 | 34 rejections (R-01…R-34) with the *mechanism* of harm, a symptom→rejection lookup, and an amendment process |
| 05 | `05_FUTURE_ARCHITECTURE.md` | 2,452 | Five scale tiers (100 → 1M customers) with derived load, what breaks first, and trigger metrics |
| 06 | `06_COMPETITIVE_ANALYSIS.md` | 1,236 | Eight systems compared per subsystem, every claim evidence-graded, including 20 open `[UNKNOWN]`s |
| 07 | `07_QAYD_INNOVATION.md` | 2,642 | 20 invented capabilities (I-01…I-20), market-checked, with a moat analysis and an honesty section |
| 08 | `08_MASTER_BACKLOG.md` | 451 | Everything triaged into tiers and mapped onto the **real** sprint plan |
| 09 | `09_ENGINEERING_PLAYBOOK.md` | 1,917 | Mandatory onboarding: coding, architecture, database, AI, security, testing, review, release |

---

## Reading order

**New engineer (day one):** 09 → 01 → 03. About three hours; enough to contribute safely.

**Designing a new subsystem:** 03 (pick your patterns) → 04 (check you are not about to violate a
rejection) → 02 (check the decision context) → 08 (check sequencing and dependencies).

**Planning a sprint:** 08 → 05 (have any trigger metrics fired?) → 07 (is anything ready to promote?).

**Strategy / positioning:** 06 → 07.

---

## Precedence

When documents disagree, this is the order:

1. `MANIFEST.md` — the vision and the laws
2. `docs/architecture/FINAL_TECH_STACK.md` and accepted ADRs in `docs/architecture/adr/`
3. **This knowledge base**
4. Sprint plans in `docs/execution/`

**This knowledge base cannot overturn a frozen architecture decision.** Where it recommends something that
contradicts a frozen spec, that requires a real ADR — *new ADR → update the doc → continue* (MANIFEST
Law 1). Those cases are flagged inside the documents; the live one today is **AD-11** (analytic dimensions
as rows), which contradicts the fixed-column design recorded at TD-14.

---

## How to keep it true

A stale reference document is worse than none, because people trust it.

- **02** — add an entry when a real architectural decision is made; mark superseded ones, never delete them.
- **04** — add a rejection when a pattern is refused; use the amendment process to overturn one.
- **08** — update when a story completes, a trigger metric fires, or an experiment concludes.
- **01 / 03** — update when a principle gains an enforcement mechanism it previously lacked.
- **All** — anything marked *aspirational* or *not enforced today* must either become true or be removed.

Every document states plainly where QAYD is currently weaker than it should be. **Keep that honesty.** The
gap registers are the most useful content in here, and they stay useful only while they are accurate.

---

## Provenance

Built from `docs/research/odoo/` (Odoo 19.0 @ `f3e407c6`, LGPL-3 — studied, never copied) plus
documentation research on SAP S/4HANA, Oracle NetSuite and Microsoft Dynamics 365, and source study of
ERPNext, Akaunting and Dolibarr.

Claims about proprietary systems are evidence-graded (`[DOCS]` / `[COMMUNITY]` / `[INFERENCE]` /
`[UNKNOWN]`). Where something could not be determined, the documents say so rather than guessing.
