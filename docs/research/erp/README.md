# ERP Platform Research — Phase 3

**Scope: the seven ERP platforms that Phase 1 and Phase 2 did *not* cover, plus a consolidation index
pointing at the platforms that were already covered exhaustively.**

Version 1.0 · 2026-07-28 · Seven documents

---

## ⚠️ Read this first: what is NOT here

**Most of the ERP competitive landscape was already researched.** This folder does not repeat it. If you
are looking for any of the following, you are in the wrong directory:

| System | Already covered in | Depth |
|---|---|---|
| **Odoo 19** | [`docs/research/odoo/ODOO_LEARNING.md`](../odoo/ODOO_LEARNING.md) | 14,150 lines — exhaustive source study |
| Odoo → QAYD translation | [`docs/research/odoo/ODOO_TO_QAYD.md`](../odoo/ODOO_TO_QAYD.md) | Per-subsystem mapping |
| Odoo-derived backlog | [`docs/research/odoo/ODOO_BACKLOG.md`](../odoo/ODOO_BACKLOG.md) | Triaged stories |
| **ERPNext / Frappe** | [`06_COMPETITIVE_ANALYSIS.md §1.3`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | Source study |
| **SAP S/4HANA** | [`06_COMPETITIVE_ANALYSIS.md §1.6`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | Documentation research |
| **Oracle NetSuite** | [`06_COMPETITIVE_ANALYSIS.md §1.7`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | Documentation research |
| **Microsoft Dynamics 365** (F&O + Business Central) | [`06_COMPETITIVE_ANALYSIS.md §1.8`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | Documentation research |
| **Akaunting** | [`06_COMPETITIVE_ANALYSIS.md §1.4`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | Source study |
| **Dolibarr** | [`06_COMPETITIVE_ANALYSIS.md §1.5`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | Source study |

The scorecard that ranks all eight of those systems (QAYD 2.4 mean vs SAP 4.7, ERPNext 3.4) is
[`06_COMPETITIVE_ANALYSIS.md Part 3`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md). The
decisions those studies produced are [`02_ARCHITECTURE_DECISIONS.md`](../../architecture/knowledge/02_ARCHITECTURE_DECISIONS.md),
the patterns are [`03_DESIGN_PATTERNS.md`](../../architecture/knowledge/03_DESIGN_PATTERNS.md), and the
refusals are [`04_REJECTED_PATTERNS.md`](../../architecture/knowledge/04_REJECTED_PATTERNS.md).

**Nothing in this folder overturns any of that.** Where this research strengthens, weakens, or
complicates an existing decision, it says so explicitly and names the decision.

---

## What IS here

Seven platforms not previously studied:

| Platform | Evidence available | What we actually did | Grade of the resulting section |
|---|---|---|---|
| **Tryton 8.1** | Open source (GPL-3) | Read the accounting + analytic modules and the ORM core | `[CODE]` — high confidence |
| **Apache OFBiz** (trunk) | Open source (Apache-2.0) | Read the entity engine, the accounting entity model, and the posting service | `[CODE]` — high confidence |
| **Sage Intacct** | Substantial public developer + help docs | Fetched the GL object schema, dimension docs, allocation docs | `[DOCS]` — good on the dimension model, thin elsewhere |
| **Acumatica** | Substantial public framework docs | Read the framework and multi-tenancy documentation | `[DOCS]` — good on framework, thin on the ledger |
| **Oracle Fusion ERP** | Partially documented (Accounting Hub / SLA) | Fetched Oracle's own implementation guide language | `[DOCS]` thin + `[UNKNOWN]` heavy |
| **Infor CloudSuite** | Opaque | Almost nothing verifiable | `[UNKNOWN]` — deliberately short |
| **Epicor** | Opaque | Almost nothing verifiable | `[UNKNOWN]` — deliberately short |

**On the last three: a short honest section is the correct output.** We did not pad them, and we did not
reconstruct plausible-sounding architectures from marketing material. A confident paragraph about the
internals of Infor CloudSuite would be fabrication, and fabrication in a reference document is worse than
a gap, because the gap is visible and the fabrication is not.

---

## The two questions this research existed to answer

### 1. Does Sage Intacct's dimensional model change AD-11?

**Yes — it complicates it, and the complication is worth acting on.** AD-11 chose rows over fixed columns
partly on the strength of Odoo's failure with JSONB. Intacct — the product most praised for dimensional
accounting — uses **named columns for a fixed standard set** plus an extension mechanism for the rest, and
materialises percentage splits into amount-carrying rows rather than storing percentages on the line.
Tryton, independently, does something structurally similar. The row decision survives; the *shape* of the
row is what should change. See [`ARCHITECTURE.md §4`](ARCHITECTURE.md) and
[`IMPLEMENTATION_RECOMMENDATIONS.md R-01, R-02`](IMPLEMENTATION_RECOMMENDATIONS.md).

### 2. Tryton and OFBiz vs Odoo — the controlled comparison

Tryton is a 2008 fork lineage from TinyERP, Odoo's ancestor: same problem, same era, same language,
deliberately opposite choices. OFBiz solved the same problem from a completely different starting point
(a data-model-first design descended from the Universal Data Model literature). Comparing three systems
that share a problem but not a philosophy is the most informative thing in this folder. See
[`ARCHITECTURE.md §5`](ARCHITECTURE.md).

Short version: **Tryton got restraint right and ambition wrong. OFBiz got the data model right and the
database wrong. Odoo got adoption right and integrity wrong.** All three lessons are actionable for QAYD.

---

## Which document answers your question

| If you are asking… | Read |
|---|---|
| *What are these seven systems, and how good are they?* | **`OVERVIEW.md`** |
| *What did they do that we should copy?* | **`BEST_PRACTICES.md`** |
| *What did they do that we must never do?* | **`ANTI_PATTERNS.md`** |
| *How are they actually built, structurally?* | **`ARCHITECTURE.md`** |
| *What does this change about QAYD?* | **`LESSONS_FOR_QAYD.md`** |
| *What do I do on Monday, and what does it cost?* | **`IMPLEMENTATION_RECOMMENDATIONS.md`** |

**Reading order for the impatient:** this README → `LESSONS_FOR_QAYD.md` → `IMPLEMENTATION_RECOMMENDATIONS.md`.
The other three are the supporting evidence.

---

## Evidence tiers

Every substantive claim in these documents carries a tier. Trust them accordingly.

| Tier | Meaning | How much to trust it |
|---|---|---|
| `[CODE]` | Read in the actual source, with file path and line number | High. Verifiable — go look. |
| `[DOCS]` | Stated by the vendor in published documentation, with a URL | Good for *what they claim*. Vendors document behaviour, not internals. |
| `[COMMUNITY]` | Consultant blogs, partner documentation, forums | Directionally useful, individually unreliable. |
| `[INFERENCE]` | Our reasoning from `[CODE]` or `[DOCS]` evidence | Only as good as the argument, which is always shown. |
| `[UNKNOWN]` | Could not be determined | **Says so.** Never silently filled in. |

**The rule that governs the proprietary sections:** we never invent an architecture. If Oracle does not say
how Fusion stores a journal line, this research does not say either. `[INFERENCE]` is permitted only where
the reasoning is stated and the reader can reject it.

---

## The rule that governs every recommendation

**Never copy code. Never imitate architecture. Principles only.**

Reading a GPL-3 module tells you what problems exist and which solutions collapse under load. It does not
tell you what to type. Every recommendation in `IMPLEMENTATION_RECOMMENDATIONS.md` is expressed as a
property QAYD should have, derived from an observed consequence — not as a structure to reproduce.

Where an incumbent is better than QAYD, these documents say so plainly, in the same register that
`06_COMPETITIVE_ANALYSIS.md` established. That honesty is the reason the knowledge base is useful.

---

## Provenance

| Source | Version | Commit / URL | Licence | How obtained |
|---|---|---|---|---|
| Tryton | 8.1.0 | `54183ea8183d7ff242c87f8a3c052a1bbf478b6e` (2026-07-22) | GPL-3 | Shallow clone, read, deleted |
| Apache OFBiz | trunk | `cefbdb21eabb4afd56a7aa0a9d77ebcc55738fff` (2026-07-27) | Apache-2.0 | Sparse clone, read, deleted |
| Sage Intacct | current | `developer.intacct.com`, `intacct.com/ia/docs` | Proprietary | Public documentation |
| Acumatica | 2019 R1 – 2022 | `acumatica.com/media/*`, `help.acumatica.com`, community | Proprietary | Public documentation |
| Oracle Fusion | 11.1.2 guide | `docs.oracle.com/cd/E25054_01/...` | Proprietary | Public documentation |
| Infor CloudSuite | — | — | Proprietary | **Nothing verifiable found** |
| Epicor | — | — | Proprietary | **Nothing verifiable found** |

Both source trees were read on disk and removed afterwards. **No code from any system was copied into
QAYD, and no QAYD file was modified by this research.** This folder is documentation only.

---

## Keeping this true

This is research, not a standing reference — it has a shelf life, and the parts with the shortest shelf
life are the `[DOCS]` sections, because vendors change their products without changing their marketing.

- **`[CODE]` claims** are pinned to commits and stay true for those commits forever. If you re-study
  Tryton or OFBiz at a newer commit, add a new section rather than editing the old one.
- **`[DOCS]` claims** about Intacct, Acumatica and Oracle should be re-verified before any decision
  depends on them. The URLs are in the documents.
- **`[UNKNOWN]`s are inventory, not failure.** If someone gets credible information about Infor or Epicor
  internals — a former implementer, a published schema, a leaked data dictionary — promote it with the
  right tier and date it.
- **When a recommendation here is accepted, it must become an ADR.** This folder cannot decide anything.
  The recommendations most likely to need one are R-01 and R-02, because they touch AD-11, which is
  already flagged as needing a formal ADR before TD-14 is implemented.
