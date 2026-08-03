# Best Practices — what these seven systems got right

**Practices worth adopting, each with the evidence that produced it and the reason it works.** A practice
earns a place here only if we can say *why* it is good in mechanical terms — not because it is common, and
not because a respected system does it.

Format for each: what it is · where we saw it · why it works · what it costs · QAYD's position today.

Cross-references: existing decisions in
[`02_ARCHITECTURE_DECISIONS.md`](../../architecture/knowledge/02_ARCHITECTURE_DECISIONS.md), patterns in
[`03_DESIGN_PATTERNS.md`](../../architecture/knowledge/03_DESIGN_PATTERNS.md).

---

## BP-01 — Make the allocation rule a named, reusable object, not an anonymous payload

**What it is.** When one journal line must be split across dimension members, the split is defined once as
a named entity with an identity and a lifecycle, then *referenced* by lines — rather than each line
carrying its own private percentage map.

**Where we saw it.** Two independent implementations that agree:

- **Sage Intacct**: a Transaction Allocation is an object with `ALLOCATIONID`, `DESCRIPTION`, `STATUS`
  (active/inactive), `TYPE`, and a collection of `ALLOCATIONENTRIES`, each with dimension ids, a `VALUE`,
  and a `VALUETYPE` of `Amount` or `Percent`. Percentages "must always total 100%". `[DOCS]`
  <https://developer.intacct.com/api/general-ledger/transaction-allocations/>
- **Tryton**: an analytic account of `type='distribution'` owns child `analytic_account.account.distribution`
  rows, each `(account, ratio)`, with `ratio` domain-constrained to `[0, 1]` and a validation that the
  ratios sum to exactly 1. `[CODE] modules/analytic_account/account.py:331-348, 122-132`

**Why it works.** Four mechanical reasons, in order of importance:

1. **The 100% invariant is checked once per rule, not once per line.** Tryton validates
   `sum(d.ratio for d in account.distributions) != 1` when the *account* is saved
   `[CODE] account.py:129`. A thousand journal lines referencing that account inherit a guarantee that was
   established once. Per-line percentage storage inverts this: every line is an independent opportunity to
   violate the invariant, which is why Odoo needed a deferred Python constraint that production code then
   had to disable in specific cases.
2. **Changing an allocation policy is an update to one row.** "Marketing overhead now splits 50/30/20
   instead of 60/40" is a single write. With per-line percentages it is a data migration, and there is no
   safe answer for already-posted lines.
3. **The rule is auditable as a thing.** "Why is 40% of this on Project B?" resolves to a named policy
   with a status and a history, not to a number someone typed.
4. **It is a natural join key.** Reporting "everything allocated under policy X" is a query, not a scan.

**What it costs.** A level of indirection at data entry, and a genuine ergonomic problem for genuinely
one-off splits — for which Intacct provides the escape hatch of inline `SPLIT` elements `[DOCS]`. A
system that offers *only* named rules will be worked around.

**QAYD's position.** AD-11 currently specifies per-(line, dimension, member, allocation) rows with the
allocation on the row. That satisfies the storage argument but not this one. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-02`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## BP-02 — Resolve allocations to money before storing them

**What it is.** The persisted allocation row carries an **amount**, not a percentage. The percentage is an
input to a calculation performed once, at write time; what lands in the database is settled money.

**Where we saw it.**

- **Tryton**: `analytic_account.line` has `debit` and `credit` `Monetary` columns and no ratio field at
  all. `[CODE] modules/analytic_account/line.py:19-28` The ratio lives on the *rule*; the line stores the
  result.
- **Sage Intacct**: each inline `SPLIT` element carries an `AMOUNT`, and the documented constraint is that
  "all SPLIT element's AMOUNT values must sum up to equal GLENTRY element's TRX_AMOUNT". `[DOCS]`

**Why it works.**

1. **Aggregation is a plain `SUM`.** Reporting by dimension never multiplies, never re-rounds, and never
   has to know the rounding rules that were in force when the entry was made. With percentages, every
   report recomputes money, and any change to rounding logic silently rewrites history.
2. **Rounding is decided once, by the writer, and is auditable.** Tryton's `distribute()` is worth reading
   for this alone: it allocates by ratio, tracks the remainder, then walks the results distributing the
   leftover one rounding unit at a time until it is exhausted, and asserts
   `sum(a for _, a in result) == amount`. `[CODE] modules/analytic_account/account.py:279-302`
   **60/40 of 0.05 KWD is decided by that code, once, and recorded.** With stored percentages, it is
   decided by whichever report happens to run.
3. **It matches how the ledger already thinks.** Money in, money out, `SUM` to verify.

**What it costs.** The stored allocation is no longer self-describing — you cannot see "60%" without
joining to the rule. If the source line's amount is corrected, allocations must be recomputed rather than
implicitly following. (In QAYD this is moot: AD-07 makes posted lines immutable, so a correction produces
a new entry with new allocations.)

**QAYD's position.** AD-11 stores the allocation percentage. This is the substantive challenge to it. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-01`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## BP-03 — Make the axis a first-class object, and constrain members to it in the database

**What it is.** A dimension (axis) is a real entity. Members belong to exactly one axis, and the database
enforces both "this member belongs to this axis" and "at most one member per axis per subject".

**Where we saw it.** Tryton, via a structure worth describing precisely. An axis is an
`analytic_account.account` of `type='root'`; members are nodes beneath it; every account carries a `root`
FK. `[CODE] modules/analytic_account/account.py:35-51` Then `analytic.account.entry` — the join between a
subject and its axis assignments — declares
`('root_origin_uniq', Unique(t, t.origin, t.root))`. `[CODE] account.py:395-398`

**Why it works.** That one `UNIQUE (origin, root)` makes *"you cannot assign two cost centres to the same
thing"* a database fact rather than a code convention. It is the same class of guarantee QAYD gets from
its composite `(member_id, dimension_id)` foreign key, arrived at independently — which is good
corroboration for AD-11's integrity design.

Tryton adds a second guard: once entries exist, an account's `root` cannot be changed.
`[CODE] account.py:304-322` **Re-parenting a member to a different axis would silently reinterpret every
historical allocation**, so the operation is refused rather than cascaded. That is exactly the right
instinct and QAYD should copy it.

**What it costs.** Axes become schema-ish objects with migration-like ceremony around changes. Correct.

**QAYD's position.** Already aligned (AD-11's composite FK). The `root` immutability rule is the gap.

---

## BP-04 — Give the "incomplete but posted" state a name and a worklist

**What it is.** Requiring every line to be dimensioned conflicts with letting people post on time. Rather
than choosing, make incompleteness an explicit, queryable state and put the work in a queue.

**Where we saw it.** Tryton adds an `analytic_state` to `account.move.line`, updated when the move is
posted, "valid only if all the Analytic Account axes are completely filled", and ships a standing menu
item — *Financial → Processing → Analytic Lines to Complete* — backed by the domain
`[["analytic_state","=","draft"],["move_state","=","posted"]]`.
`[CODE] modules/analytic_account/doc/design.rst`, `line.py:107-128`

**Why it works.**

1. **It refuses the false choice.** Blocking posting on dimension completeness makes month-end close
   hostage to a data-entry backlog. Silently allowing gaps makes dimensional reports quietly wrong. A
   named state makes the gap *visible and finite*.
2. **It is derived, not asserted.** `set_analytic_state` recomputes from the actual analytic lines
   whenever they change `[CODE] line.py:108-128`, so the flag cannot drift from reality. This is the
   append-only-source rule of AD-20 applied to a boolean.
3. **It gives auditors a number.** "How much posted value is un-dimensioned?" is one query.

**What it costs.** Dimensional reports must state their completeness. Arguably an improvement.

**QAYD's position.** AD-11 lists "enforcing that every line must be allocated where required is a
separate, harder rule" as an unresolved tradeoff. **This is the answer, and it is proven in production.**
See [`LESSONS_FOR_QAYD.md L-04`](LESSONS_FOR_QAYD.md).

---

## BP-05 — Derive rounding tolerance from the currency, never from a literal

**What it is.** The balance check's epsilon comes from currency metadata, not from a constant.

**Where we saw it.** Tryton, positively: the posting balance test is
`ABS(ROUND(SUM(debit - credit), currency.digits)) >= ABS(currency.rounding)`, with both `digits` and
`rounding` read from `company.currency`. `[CODE] modules/account/move.py:479-493`

And OFBiz, negatively: `postAcctgTrans` rejects when `debitCreditDifference >= 0.01` or `<= -0.01` — an
XML literal. `[CODE] applications/accounting/minilang/ledger/AcctgTransServices.xml:181+`

**Why it works, and why the negative case matters so much here.** KWD, BHD and OMR are three-decimal
currencies. **Under OFBiz's rule, an imbalance of 0.009 KWD posts.** Compounded across a year of
transactions that is a real, quiet, unbounded misstatement — in QAYD's home market specifically. The bug
is invisible to anyone testing in USD or EUR, which is the entire reason it survives in a mature project.

**What it costs.** Nothing. It is strictly less code than a magic number plus the comment explaining it.

**QAYD's position.** Aligned — money is `NUMERIC(19,4)` and balance is exact. Worth recording as an
explicit test case (a KWD entry off by 0.0005 must be rejected) because this is the failure mode that
mature systems ship.

---

## BP-06 — Give the expensive variant of a primitive its own name

**What it is.** When a facility has a cheap version and an expensive version, do not make it a boolean
flag. Make the expensive one a distinct type, so choosing it is a visible decision.

**Where we saw it.** Tryton's gapless sequence. The entire class body is:

> `class SequenceStrict(Sequence): __name__ = 'ir.sequence.strict'; _strict = True;`
> `def get_many(self, n=1, _lock=True): yield from super().get_many(n=n, _lock=True)`
>
> `[CODE] trytond/trytond/ir/sequence.py:445-451`

Two lines of behaviour: take the lock. But it is a *separate model*, referenced by a separate field type,
and `account.period.move_sequence` is typed `Many2One('ir.sequence.strict', ...)` — so a journal
**cannot** be wired to a non-gapless sequence by mistake. `[CODE] modules/account/period.py:44-49`

**Why it works.** The cost — serialised allocation, a contention point under concurrency — is real and is
exactly what AD-09 says QAYD pays deliberately. Naming the type means every use site declares that it
accepted the cost, and the type system prevents the cheap variant leaking into a place that requires the
expensive one. A `gapless: bool` column would provide neither property.

**What it costs.** One more class. Nothing else.

**QAYD's position.** AD-09 makes the right choice; this is a mechanism for making it unmissable at every
call site.

---

## BP-07 — Extend by additive, separately-versioned extension objects

**What it is.** Customers customise by *adding* declarative extension artefacts that hook into base
behaviour by convention, packaged as versioned units — never by editing or forking base code.

**Where we saw it.** Acumatica, whose whole platform is organised around this: `PXGraphExtension<>` to
extend a business-logic graph, `PXCacheExtension<>` to add fields to a base data class, event handlers
bound to tables and fields by naming convention, all packaged into **Customization Projects** published
onto an instance. `[DOCS]`
<https://www.acumatica.com/media/2020/09/AcumaticaFramework_DevelopmentGuide.pdf>

Tryton reaches the same place differently, with `PoolMeta` mixins that compose modules by inheritance
rather than by patching. `[CODE]`

**Why it works.**

1. **The base can move.** An upgrade that does not touch the extension points is safe by construction.
   The alternative — Odoo-style monkey-patching, where a module reaches into another module's methods —
   makes every upgrade a negotiation with every installed module.
2. **Customisation is inventory.** A Customization Project is a listable, versionable, diffable artefact.
   You can answer "what is non-standard about this tenant?" — a question most ERPs cannot answer.
3. **It is the enforcement mechanism AD-13 currently lacks.** AD-13 says every replaceable subsystem sits
   behind a named seam. Acumatica shows what turns a named seam into a *load-bearing* one: extensions
   attach only at seams, so the seam is exercised constantly rather than aspirationally.

**What it costs.** Convention-based binding is invisible to static analysis and to newcomers — a handler
named wrongly silently does nothing. Any system adopting this needs a "what is extending what" report as
part of the mechanism, not as an afterthought.

**QAYD's position.** AD-13 has the principle. The mechanism and the inventory are not built.

---

## BP-08 — Make queries typed and compile-checked

**What it is.** Queries are expressed in a typed language checked at build time, so a schema rename breaks
the build rather than production.

**Where we saw it.** Acumatica's BQL, where a query is a generic type — `PXSelect<Table, Where<...>>` —
and Acumatica's stated benefit is "compile-time syntax validation, which helps to prevent SQL errors".
`[DOCS]`

**Why it works.** Financial reporting queries are long-lived, numerous, and rarely all exercised by tests.
A renamed column that breaks 40 report queries should fail at build, not in a quarterly close.

**What it costs.** Real expressiveness. Type-level query languages become unreadable at the complexity of
a consolidation query, and every such system has an escape hatch to raw SQL that then loses the guarantee.
The honest version of this practice is: **typed for the queries that must never silently break, raw SQL
for the ones that must be readable** — and be deliberate about which is which.

**QAYD's position.** Laravel's query builder gives none of this; PHP has no equivalent. The transferable
part is narrower and cheap: a build-time check that every column named in a report definition exists in
the schema. See [`IMPLEMENTATION_RECOMMENDATIONS.md R-07`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## BP-09 — Treat posting rules as versioned data over accounting events

**What it is.** Business documents do not contain their accounting. They *raise events*. A separately
versioned rule set transforms events into journals. The rule set is the auditable artefact.

**Where we saw it.** Oracle Fusion's Subledger Accounting, in Oracle's own words: an "accounting engine
that combines transaction and reference information from source systems with accounting rules to create
detailed journals stored in an accounting repository", invoked by a "Create Accounting process" against
"accounting events". `[DOCS]`
<https://docs.oracle.com/cd/E25054_01/fusionapps.1111/e20374/F484243AN100CE.htm>

OFBiz reaches something similar from a different direction: posting is triggered by a declarative SECA
rule on the *commit* event of the creating service, rather than being called inline.
`[CODE] applications/accounting/servicedef/secas_ledger.xml:25-28`

**Why it works.**

1. **Jurisdictional variation becomes data.** Kuwait, Saudi and a future IFRS-17 client differ in *rules*,
   not in *code paths*. If the rules are data, the difference is a row set. If they are code, it is a
   fork.
2. **"Why was this posted this way?" has an answer with a version number.**
3. **It separates *when* something is accountable from *how*.** The event is a fact; the treatment is a
   policy. Policies change and must be replayable against old facts.

**What it costs — and this is not a small caveat.** A rules engine is a programming language you now
maintain, debug, and secure, usually without a debugger. Fusion's SLA is widely regarded as one of the
harder things to configure in enterprise software. **The right move is not to build one**; it is to keep
the seam — `PostingService` takes an *event* and consults a *policy* — so that a rules engine can be
introduced later for exactly the subsystems that need it (tax, revenue recognition), and never for the
ones that do not.

**QAYD's position.** AD-14 (after-commit domain events) and AD-04 (one writer) already give the shape.
Open decision O7 (tax rules-as-data per jurisdiction) is the same question. This evidence supports keeping
O7 open and scoping any rules engine to tax first.

---

## BP-10 — Model associations temporally by default

**What it is.** The relationship between two entities carries `fromDate` / `thruDate`, and the date is
often *part of the primary key*, so history is structural rather than reconstructed.

**Where we saw it.** OFBiz, pervasively, and this is the best-executed thing in it.
`GlAccountOrganization` is `(glAccountId, organizationPartyId)` with `fromDate`/`thruDate` — a global chart
of accounts activated per organisation for a window. `GlAccountCategoryMember` puts `fromDate` **inside
the PK**: `(glAccountId, glAccountCategoryId, fromDate)`, so an account's membership of a category is
inherently a time series. `[CODE] applications/datamodel/entitydef/accounting-entitymodel.xml`

Tryton does the same thing more narrowly: `account.account` carries `start_date`/`end_date`, and the
journal line's account domain filters on them so a closed account cannot be selected for a date outside
its window. `[CODE] modules/account/move.py:926-934`

**Why it works.** Financial questions are almost always *as-of* questions. "Which accounts were in the
Operating Expenses category in FY2024?" is unanswerable in a system where category membership is a
mutable FK. Making it temporal by default means the answer is a `WHERE` clause instead of an audit-log
reconstruction.

**What it costs — and it is a real cost.** Every join needs a date predicate, and every forgotten date
predicate is a duplicate row. OFBiz pays this on hundreds of entities. **The practice worth adopting is
the discriminating one:** temporal keys for the *classification* relationships that reporting slices by —
account↔category, member↔axis, entity↔group — and plain FKs everywhere else.

**QAYD's position.** Not modelled. Dimension member hierarchies and account classifications are the places
this will hurt first, and reorganisations are exactly when it will be noticed. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-05`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## BP-11 — Make dimensional data entry ergonomic, because that is where dimensions actually fail

**What it is.** Treat the dimension *experience* as the product: hierarchies with rollups, relationships
that autofill one dimension from another, defaults, and reusable allocations.

**Where we saw it.** Sage Intacct, which is praised for dimensions and whose published dimensional
advantages are all ergonomic rather than structural: hierarchical members with parent/child rollups
(Sage's own example is a West Coast Division containing California, Nevada and Utah); relationships
between dimensions such that "you can create a relationship between the Customer and Location dimensions
— this relationship sets the Location value to autofill every time you create a transaction for that
customer"; and named reusable allocations. `[DOCS]`
<https://www.intacct.com/ia/docs/en_US/help_action/Intacct_basics/Dimensions/basics-dimensions-overview.htm>

**Why it works.** This is the finding that reframes the whole dimension question. **The most-admired
dimensional model in the industry has an unremarkable storage design — named fields on a line — and wins
on everything above the storage.** Dimensional accounting fails in practice not because the schema cannot
hold the data but because humans will not enter it consistently. Autofill, defaults, hierarchy and
reusable policies are what make the data exist at all; and a dimension nobody fills in is worth exactly
nothing regardless of how elegantly it is stored.

Tryton's `analytic.rule` is the same insight in a different form: criteria (account, journal) that
automatically populate missing analytic lines on posted moves. `[CODE] modules/analytic_account/rule.py`,
`doc/design.rst`

**What it costs.** Autofill is inferred data, and inferred financial data must be visibly inferred and
overridable. A relationship that silently sets Location will eventually set it wrongly.

**QAYD's position.** This is QAYD's largest dimensional gap and it is **not** a storage gap. It is also
the place where the AI engine has an unusually clean job: suggest the dimension, show the confidence, let
a human accept — which QAYD already has a home for in `dimension_suggestions.proposed_payload`.

---

## BP-12 — Publish the design rationale next to the code

**What it is.** Ship prose that explains what a subsystem *means*, versioned in the same repository as the
implementation, distinct from API reference.

**Where we saw it.** Tryton. Every module has `doc/design.rst`, and it explains concepts rather than
signatures — "The Analytic Account is used to represent the various analytical axes. The Analytic Accounts
are organized in a tree structure, with each root defining an axis."
`[CODE] modules/analytic_account/doc/design.rst` That one sentence explains the entire model faster than
reading `account.py` does, and it is what let this research understand Tryton's analytic design in
minutes rather than hours.

**Why it works.** Code states the *what*. Design docs state the *why* and the *vocabulary*. Without the
vocabulary, every reader independently invents names for the same concepts, and those names leak into
column names, variable names and conversations, permanently.

**What it costs.** It rots if not maintained — and a stale design document is worse than none, which is
the same warning `docs/architecture/knowledge/README.md` already gives about the knowledge base.

**QAYD's position.** The knowledge base is strong on decisions and patterns. What Tryton has that QAYD
does not is *per-subsystem conceptual documentation living next to the subsystem*. When `PostingService`
acquires its second and third caller, a `docs/design/posting.md` will be worth more than another ADR.

---

## Summary table

| # | Practice | Source | Evidence | QAYD status |
|---|---|---|---|---|
| BP-01 | Allocation rule as a named reusable object | Intacct, Tryton | `[DOCS]` `[CODE]` | **Gap** — challenges AD-11 |
| BP-02 | Store resolved money, not percentages | Intacct, Tryton | `[DOCS]` `[CODE]` | **Gap** — challenges AD-11 |
| BP-03 | Axis as first-class; member↔axis enforced in DB | Tryton | `[CODE]` | Aligned; add axis immutability |
| BP-04 | Name the "posted but un-dimensioned" state + worklist | Tryton | `[CODE]` | **Gap** — solves an open AD-11 tradeoff |
| BP-05 | Currency-derived rounding tolerance | Tryton (+ OFBiz as counter-example) | `[CODE]` | Aligned; add a KWD test |
| BP-06 | Name the expensive variant as its own type | Tryton | `[CODE]` | Mechanism for AD-09 |
| BP-07 | Additive, versioned extension objects | Acumatica | `[DOCS]` | AD-13 principle exists, mechanism does not |
| BP-08 | Typed, compile-checked queries | Acumatica | `[DOCS]` | Not portable; narrow version is cheap |
| BP-09 | Posting rules as versioned data over events | Oracle Fusion | `[DOCS]` | Keep the seam; do not build the engine |
| BP-10 | Temporal associations for classifications | OFBiz | `[CODE]` | **Gap** |
| BP-11 | Dimensional ergonomics are the product | Intacct, Tryton | `[DOCS]` `[CODE]` | **Largest gap** |
| BP-12 | Design rationale beside the code | Tryton | `[CODE]` | Partial |
