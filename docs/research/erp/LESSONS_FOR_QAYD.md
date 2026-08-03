# Lessons for QAYD

**What this research changes.** Each lesson states what we learned, which QAYD decision it touches,
whether it *confirms*, *challenges* or *extends* that decision, and what follows.

A lesson earns a place here only if it would change a decision, a priority, or a test. Interesting
observations that change nothing are in [`OVERVIEW.md`](OVERVIEW.md) and [`ARCHITECTURE.md`](ARCHITECTURE.md).

| Verdict | Meaning |
|---|---|
| **Confirms** | An existing decision is right, and now has stronger evidence |
| **Challenges** | An existing decision has a detail worth revisiting |
| **Extends** | A gap the decisions do not currently address |

---

## L-01 — AD-11's core choice is confirmed by the system that took the other road

**Verdict: Confirms AD-11.** **Confidence: High.**

AD-11 rejected fixed dimension columns partly on predicted consequences. OFBiz supplies the observed ones.
`AcctgTransEntry` carries **ten** nullable dimension FK columns — `partyId`, `roleTypeId`, `theirPartyId`,
`productId`, `inventoryItemId`, `glAccountTypeId`, `organizationPartyId`, `taxId`, `groupId`,
`settlementTermId` `[CODE] applications/datamodel/entitydef/accounting-entitymodel.xml:1869-1893` — with no
customer extension point and no possibility of a percentage split.

Every prediction holds: the dimension set is frozen at the vendor's guess; adding one is a migration on
the largest table shipped to every customer; splitting a line across two projects is impossible; the table
is sparse; and OFBiz's own domain assumptions (`settlementTermId`, `theirProductId`) sit on every journal
line of every installation forever.

**What follows.** TD-14 stays superseded. When the formal ADR for AD-11 is written, cite OFBiz — it turns
an argument into an observation, and it is a mature, successful project rather than a cautionary tale,
which makes it much harder to dismiss.

---

## L-02 — The allocation should be a named rule, not a per-line percentage

**Verdict: Challenges AD-11 (detail, not direction).** **Confidence: High.**

Two systems with no shared lineage independently made the allocation policy a first-class named object:

- Sage Intacct's **Transaction Allocation** — `ALLOCATIONID`, `STATUS`, `TYPE`, and `ALLOCATIONENTRIES`
  each carrying dimension ids plus `VALUE` and `VALUETYPE` ∈ {`Amount`, `Percent`}, with percentages that
  "must always total 100%". `[DOCS]`
  <https://developer.intacct.com/api/general-ledger/transaction-allocations/>
- Tryton's **distribution account** — `type='distribution'` owning `(account, ratio)` children, validated
  to sum to exactly 1 when the account is saved.
  `[CODE] modules/analytic_account/account.py:331-348, 122-132`

**Why this matters mechanically.** AD-11 stores the allocation percentage on each dimension row and
enforces the 100% rule with a `DEFERRABLE INITIALLY DEFERRED` constraint trigger. That is a good mechanism
for a hard problem — but the problem is hard *because* per-line percentages create a fresh opportunity to
violate the invariant on every line. Tryton validates once, when the rule is saved; a thousand lines
referencing it inherit the guarantee.

It also answers a question AD-11 leaves open: **how do you change an allocation policy?** "Marketing
overhead now splits 50/30/20 instead of 60/40" is one row with a named rule. With per-line percentages
there is no clean answer, and none at all for posted history.

**What follows.** Add a `dimension_allocation_rules` concept. The per-line rows remain (L-03), but they
reference a rule where one applies. See [`IMPLEMENTATION_RECOMMENDATIONS.md R-02`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## L-03 — Store resolved money on the allocation row, not a percentage

**Verdict: Challenges AD-11 (the row's contents).** **Confidence: High.**

Three systems, three unrelated architectures, one agreement:

- Tryton's `analytic_account.line` carries `debit` and `credit` `Monetary` columns and **no ratio field**.
  `[CODE] modules/analytic_account/line.py:19-28`
- Intacct's inline `SPLIT` carries `AMOUNT`, constrained so that "all SPLIT element's AMOUNT values must
  sum up to equal GLENTRY element's TRX_AMOUNT". `[DOCS]`
- Odoo's materialised `account.analytic.line` rows carry amounts.

**Why.** Two mechanical reasons and one that is specific to QAYD.

1. **Aggregation stops recomputing money.** With amounts, a dimensional report is `SUM() GROUP BY member`.
   With percentages, every report multiplies and re-rounds — and any later change to rounding logic
   silently rewrites history.
2. **Rounding is decided once and recorded.** Tryton's `distribute()` allocates by ratio, tracks the
   remainder, and walks the results adding one rounding unit at a time until the remainder is zero,
   asserting `sum(a for _, a in result) == amount`. `[CODE] account.py:279-302` **60/40 of 0.05 KWD has
   exactly one right answer and it should be decided by `PostingService`, once, not by whichever report
   runs.** In three-decimal currencies this is not a corner case.
3. **It makes `journal_line_dimensions` independently aggregable.** AD-11 already anticipates that this
   table needs its own partitioning strategy by `(company_id, period)`. Amount rows let a dimensional
   report hit it without joining `journal_lines` — which is precisely the property that makes the
   partitioning worth having. Percentage rows force the join every time.

**What follows.** The row becomes (line, dimension, member, **signed_amount**), optionally with a
`rule_id` and the ratio retained as provenance. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-01`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## L-04 — Tryton solves an open AD-11 tradeoff, and the solution is proven

**Verdict: Extends AD-11.** **Confidence: High.**

AD-11 lists as an unresolved cost: *"Enforcing 'every line must be allocated' where required is a
separate, harder rule than 'this column is NOT NULL'."*

Tryton's answer: a derived `analytic_state` on `account.move.line`, recomputed by `set_analytic_state`
whenever analytic lines change `[CODE] modules/analytic_account/line.py:107-128`, "valid only if all the
Analytic Account axes are completely filled", plus a standing menu item — *Financial → Processing →
Analytic Lines to Complete* — backed by the domain
`[["analytic_state","=","draft"],["move_state","=","posted"]]`.
`[CODE] modules/analytic_account/doc/design.rst`

**Why it is right.** It refuses the false choice. Blocking posting on dimension completeness makes
month-end close hostage to a data-entry backlog; allowing gaps silently makes dimensional reports quietly
wrong. A named, derived state makes the gap visible, finite, and assignable — and gives auditors a number
("how much posted value is un-dimensioned?").

Note the state is **derived, not asserted** — recomputed from the actual rows. That is AD-20's
append-only-source rule applied to a boolean, so it cannot drift.

**What follows.** Add a derived dimensional-completeness state and a worklist. Cheap, and it removes the
main practical objection to mandatory dimensions.

---

## L-05 — QAYD's integrity model is its moat, and no system in this study contests it

**Verdict: Confirms AD-01, AD-02, AD-04, AD-07, AD-17, AD-21.** **Confidence: High.**

Across all seven platforms, **not one puts a financial invariant in the database where it cannot be
bypassed.**

- Acumatica's tenant isolation is a `CompanyID` predicate the framework injects, with a `CompanyMask`
  binary bitmask governing cross-tenant visibility. `[COMMUNITY]`
- Tryton's record rules are PYSON domains decoded per request and injected into `WHERE` by the ORM.
  `[CODE] trytond/ir/rule.py:162-176`, `modelsql.py:1193,1771`
- Tryton's immutability of posted moves is a Python hook raising `AccessError`
  `[CODE] modules/account/move.py:290-301` — a direct `UPDATE` succeeds.
- Tryton's audit history is **opt-in per model and off for `account.move`**.
  `[CODE] modelsql.py:322,476-479`
- OFBiz's entity engine **cannot emit a CHECK constraint at any point** — a keyword scan of all 3,383
  lines of `DatabaseUtil.java` finds zero. `[CODE]`

**The important nuance.** Tryton is not naive — it uses real CHECK constraints where the invariant is
per-row (`credit * debit = 0`). `[CODE] move.py:1087` It uses exactly five across the whole accounting
domain, and skips precisely the multi-row invariants that need triggers. **The gap between Tryton and
QAYD is not awareness. It is willingness to write triggers.**

**What follows.** Two things. First, hold the line — AD-21 ("no invariant has an off switch") is
load-bearing, and Tryton's opt-in audit is what the alternative looks like in a system with good
engineers. Second, **say this out loud in positioning**: database-enforced isolation, immutability and
balance is a claim no competitor in this study can make.

---

## L-06 — Three-decimal currencies are where mature systems ship real bugs

**Verdict: Confirms the `NUMERIC(19,4)` decision; extends the test strategy.** **Confidence: High.**

OFBiz's `postAcctgTrans` rejects a transaction when `debitCreditDifference >= 0.01` or `<= -0.01` — a
literal in XML. `[CODE] applications/accounting/minilang/ledger/AcctgTransServices.xml:181+` Its
`currency-amount` type is `NUMERIC(18,2)` on PostgreSQL.
`[CODE] framework/entity/fieldtype/fieldtypepostgres.xml:33`

**In KWD, BHD and OMR — QAYD's home currencies — an imbalance of 0.009 posts successfully.** Nine times
the smallest unit of the currency, accumulating without bound, and the trial balance still "balances" to
the tolerance so nothing downstream flags it.

Tryton gets this right for the opposite reason: its tolerance is
`ABS(ROUND(SUM(debit-credit), currency.digits)) >= ABS(currency.rounding)`, derived from
`company.currency`. `[CODE] modules/account/move.py:479-493`

**Why this is the most instructive defect in the study.** It is not carelessness. It is a correct decision
made inside an unstated assumption — *money has two decimals* — that fails only outside the author's
jurisdiction, and is therefore invisible to the vendor's own testing. QAYD's entire market is that
outside.

**What follows.** QAYD's money model is already right. What is missing is the proof: a three-decimal
currency must be in the standard test matrix, and there must be a named regression test asserting that a
KWD journal off by 0.0005 is rejected. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-06`](IMPLEMENTATION_RECOMMENDATIONS.md).

**The wider lesson worth internalising:** every ERP we studied encodes its author's jurisdiction
somewhere. QAYD's advantage is that its author's jurisdiction is the one everyone else gets wrong — but
that only pays off if it is tested rather than assumed.

---

## L-07 — QAYD's dimensional gap is ergonomic, not structural

**Verdict: Extends — a gap no decision currently owns.** **Confidence: High.**

Sage Intacct is the market's reference implementation of dimensional accounting. Its storage is named
fields on `GLENTRY`. `[DOCS]` **Its reputation comes entirely from what sits above the storage:**

- hierarchical members with parent/child rollups (Sage's own example: a West Coast Division containing
  California, Nevada, Utah); `[DOCS]`
- relationships *between* dimensions — "you can create a relationship between the Customer and Location
  dimensions — this relationship sets the Location value to autofill every time you create a transaction
  for that customer"; `[DOCS]`
- named, reusable transaction allocations; `[DOCS]`
- dynamic allocations that "automatically create journal entries with verifiable calculations attached".
  `[DOCS]`

Tryton has the same insight in another form: `analytic.rule` defines criteria (account, journal) that
auto-populate missing analytic lines on posted moves. `[CODE] modules/analytic_account/rule.py`

**Why it matters.** Dimensional accounting fails in practice because humans will not enter the data
consistently, not because the schema cannot hold it. **A dimension nobody fills in is worth nothing
regardless of how elegantly it is stored.** QAYD is currently winning the argument nobody is having and
losing the one that decides adoption.

**What follows — and this is the largest strategic finding in the research.** Dimension ergonomics is the
place where QAYD's AI engine has an unusually clean, defensible job: suggest the dimension from the
transaction's context, show the confidence, let a human accept or override. `dimension_suggestions.proposed_payload`
already exists as the home for it. **Intacct autofills from a static configured relationship; QAYD can
infer.** That is a genuine differentiator against the best product in the category, and it is not on the
roadmap. See [`IMPLEMENTATION_RECOMMENDATIONS.md R-03`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## L-08 — Record the counterparty's *capacity*, not just their identity

**Verdict: Extends.** **Confidence: Medium.**

OFBiz records `(partyId, roleTypeId)` **together** on both `AcctgTrans` and `AcctgTransEntry`.
`[CODE] accounting-entitymodel.xml:1764+, 1869+` A journal line records not only *who* but *in what
capacity*. Tryton records `party`; Odoo records `partner_id`; neither records the capacity.

**Why it matters for QAYD specifically.** In GCC family-owned business structures, the same legal entity
is routinely customer, supplier, lessor and shareholder. "What did we buy from customers?", related-party
disclosure, and intercompany elimination all depend on capacity, and reconstructing it later from the
account used is guesswork.

**Confidence is Medium, not High**, for an honest reason: OFBiz weakened its own pattern by making the
`PartyRole` relation `one-nofk` `[CODE]`, so the pairing is unenforced there. The *idea* is right; the
execution we observed is not the one to copy. QAYD would enforce it with a composite FK to a
`party_roles` table — the same shape AD-11 already uses for `(member_id, dimension_id)`.

**What follows.** Cheap now, expensive later. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-04`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## L-09 — Classification relationships must be temporal

**Verdict: Extends.** **Confidence: Medium-High.**

OFBiz puts `fromDate` **inside the primary key** of `GlAccountCategoryMember`:
`(glAccountId, glAccountCategoryId, fromDate)`, and gives `GlAccountOrganization` a
`fromDate`/`thruDate` activation window over `(glAccountId, organizationPartyId)`.
`[CODE] accounting-entitymodel.xml` Tryton does a narrower version: `account.account` has
`start_date`/`end_date`, and the journal line's account domain filters on them so a closed account cannot
be selected for a date outside its window. `[CODE] modules/account/move.py:926-934`

**Why it matters.** Financial questions are *as-of* questions. "Which accounts were in Operating Expenses
in FY2024?" is unanswerable if category membership is a mutable FK — and it is exactly the question asked
after a reorganisation, which is exactly when the FK was mutated.

**The cost is real and the recommendation is therefore narrow.** Temporal keys mean every join needs a
date predicate and every forgotten predicate is a duplicate row. OFBiz pays this on hundreds of entities
and it is a genuine tax. **Apply it only to the classification relationships that reporting slices by** —
account↔category, member↔axis, entity↔group — and to nothing else.

**What follows.** See [`IMPLEMENTATION_RECOMMENDATIONS.md R-05`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## L-10 — Name the expensive variant so its cost is visible at every call site

**Verdict: Confirms AD-09; adds a mechanism.** **Confidence: High.**

Tryton's gapless sequence is a separate model class whose entire body is *"call the parent with
`_lock=True`"*. `[CODE] trytond/ir/sequence.py:445-451` And `account.period.move_sequence` is typed
`Many2One('ir.sequence.strict', ...)`, so a non-gapless sequence **cannot** be wired to a journal by
mistake. `[CODE] modules/account/period.py:44-49`

**Why it works.** AD-09 pays a real cost — serialised allocation, a contention point under concurrency —
deliberately. A boolean `gapless` column would make that cost invisible and forgettable. A distinct type
makes every use site declare that it accepted the cost, and makes the wrong choice unrepresentable.

**What follows.** Wherever QAYD has a deliberate expensive path — gapless numbering, pessimistic locks
(AD-08), synchronous posting — express it as a distinct type or a distinct named service, not as a flag.

---

## L-11 — Customisation must be a listable artefact, or upgrades become projects

**Verdict: Extends AD-13.** **Confidence: Medium-High.**

AD-13 says every subsystem we expect to replace sits behind a named seam. Acumatica shows what turns a
named seam into a load-bearing one: customers extend via `PXGraphExtension<>` and `PXCacheExtension<>`,
bound by naming convention, **packaged into versioned Customization Projects published onto an instance**.
`[DOCS]` <https://www.acumatica.com/media/2020/09/AcumaticaFramework_DevelopmentGuide.pdf>

**The property that matters is not the extension classes. It is that "what is non-standard about this
tenant?" has an answer.** That single question determines whether an upgrade is a deploy or a project, and
most ERPs cannot answer it.

**The warning that comes with it.** Convention-based binding is invisible to static analysis; a handler
named wrongly silently does nothing, and silence is the worst failure mode in financial software. If QAYD
builds this, the "what is extending what" report is part of the mechanism, not a later tool.

**What follows.** Not urgent pre-launch. Record it now so AD-13 gains a mechanism rather than staying a
principle.

---

## L-12 — Keep the posting seam; do not build a rules engine

**Verdict: Confirms AD-04 and AD-14; informs open decision O7.** **Confidence: Medium.**

Oracle Fusion's Subledger Accounting is, in Oracle's words, an "accounting engine that combines
transaction and reference information from source systems with accounting rules to create detailed
journals stored in an accounting repository", invoked by a "Create Accounting process" against "accounting
events". `[DOCS]` <https://docs.oracle.com/cd/E25054_01/fusionapps.1111/e20374/F484243AN100CE.htm>

**The idea is right and the implementation is a trap.** Jurisdictional variation is genuinely rules, not
code paths — Kuwait, Saudi and a future IFRS-17 client differ in treatment, not in control flow. But a
rules engine is a programming language you now maintain, debug and secure, usually without a debugger, and
Fusion's SLA is widely regarded as one of the harder things to configure in enterprise software.

OFBiz shows the cheap version of the same idea failing differently: posting fired by SECAs on commit
events `[CODE] secas_ledger.xml:25-28`, which decouples correctly but leaves no call graph, no ordering,
and no bound on who may write to the ledger.

**What follows.** Keep the seam — `PostingService` takes an *event* and consults a *policy* — so a rules
engine can be introduced later for exactly the subsystems that need it. **Scope any such engine to tax
first** (open decision O7), where the variation is real and bounded, and never to the double-entry core.
And note the load-bearing pairing: AD-14's events decouple; AD-04's single writer keeps the invariant
verifiable. **Neither is safe alone** — OFBiz has the first without the second.

---

## L-13 — Feature breadth is QAYD's real gap, and it is the right gap to have right now

**Verdict: Confirms strategy; sets an expiry date on it.** **Confidence: High.**

Tryton ships roughly 60 accounting-related modules — statements in five bank formats, dunning, deferrals,
consolidation, asset accounting, anglo-saxon and continental stock accounting, cash rounding, EU and
country-specific tax packs — in a core account module of **14,309 lines**. `[CODE] modules/`

For comparison, our Odoo *study notes* alone ran to 14,150 lines. **Tryton's entire general ledger is
smaller than our description of Odoo's.**

**Two conclusions.**

1. **Feature breadth does not require code bulk.** What makes ERP codebases enormous is accumulated
   compatibility, not capability. QAYD's advantage is that it is young; keeping it means staying closer to
   Tryton's size discipline than to Odoo's, deliberately, as a standing constraint.
2. **QAYD is behind on features and ahead on foundations, and that is the correct trade *today*.**
   Foundations cannot be retrofitted — an invariant added later must first prove the existing data
   satisfies it, and it never does. Features can be added at any time.

**What follows.** The trade expires. Once the integrity core is complete and proven, breadth becomes the
binding constraint, and the systems in this study are a good map of what "complete" looks like: bank
statement import, dunning, deferrals, asset accounting, consolidation. Sequence them by GCC relevance, not
by what Tryton happens to have.

---

## Summary

| # | Lesson | Verdict | Touches | Confidence |
|---|---|---|---|---|
| L-01 | Fixed dimension columns fail exactly as predicted | Confirms | AD-11, TD-14 | High |
| L-02 | Allocation should be a named reusable rule | **Challenges** | AD-11 | High |
| L-03 | Store money on the allocation row, not percentage | **Challenges** | AD-11 | High |
| L-04 | Derived completeness state + worklist | Extends | AD-11 | High |
| L-05 | DB-enforced integrity is uncontested | Confirms | AD-01/02/04/07/17/21 | High |
| L-06 | Three-decimal currencies break mature systems | Confirms + test gap | Money model | High |
| L-07 | The dimensional gap is ergonomic, not structural | **Extends** | — (unowned) | High |
| L-08 | Record counterparty capacity, not just identity | Extends | — | Medium |
| L-09 | Classification relationships must be temporal | Extends | — | Medium-High |
| L-10 | Name the expensive variant | Confirms + mechanism | AD-09 | High |
| L-11 | Customisation must be listable | Extends | AD-13 | Medium-High |
| L-12 | Keep the posting seam, don't build the engine | Confirms | AD-04, AD-14, O7 | Medium |
| L-13 | Breadth is the real gap, and the trade expires | Confirms | Strategy | High |

**The three that matter most:** L-03 (money not percentage — free to fix now, expensive later, and it
touches a decision already flagged as needing an ADR), L-07 (the dimensional product does not exist, and
the AI engine is the answer), and L-05 (the moat is real — defend it and say so).
