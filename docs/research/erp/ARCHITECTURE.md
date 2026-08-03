# Architecture — how these systems are actually built

**The structural analysis.** Sections 4 and 5 are the substance of this research; sections 1–3 are the
groundwork they rest on.

- §1 — Two ways to describe a schema
- §2 — Four sign conventions, and what each buys
- §3 — The Universal Data Model, credited and criticised
- §4 — **The dimension question: does Sage Intacct change AD-11?**
- §5 — **Tryton vs OFBiz vs Odoo: the controlled comparison**
- §6 — Where invariants live, across all seven
- §7 — Extension architecture

---

# §1 — Two ways to describe a schema

Every system in this study answers one question first: *where does the schema live?*

| System | Schema is declared in | DDL produced by | Migration model |
|---|---|---|---|
| **QAYD** | SQL migrations | Written by hand | Laravel migrations, forward-only |
| Tryton | Python field declarations on models | ORM, at module registration | `__register__` reconciliation |
| OFBiz | **XML entity files** | Entity engine, at boot | `add-missing-on-start` |
| Acumatica | C# DACs with attributes | Framework | `[UNKNOWN]` |
| Intacct / Fusion / Infor / Epicor | `[UNKNOWN]` | `[UNKNOWN]` | `[UNKNOWN]` |

**OFBiz's answer is the most extreme and the most interesting.** The schema is 733 entities and 160
view-entities across 21,503 lines of XML `[CODE] applications/datamodel/entitydef/`, and the database is
generated from it. This has one genuinely excellent property that QAYD does not have: **the entire
enterprise data model is a single greppable, diffable, reviewable artefact.** You can ask "which entities
reference `partyId`?" and get a complete answer in one command. In QAYD, that question requires reading
a migration history and hoping.

The price is AP-01: the XML can only express what the generator can emit across twelve database dialects,
which turns out to be primary keys, foreign keys, `NOT NULL` and `UNIQUE`. **Zero CHECK constraints.**
`[CODE] framework/entity/src/main/java/org/apache/ofbiz/entity/jdbc/DatabaseUtil.java`

**Tryton sits in between and is instructive about the middle ground.** Fields are declared in Python, and
the ORM creates tables — but Tryton *also* declares real SQL constructs when it wants them:
`_sql_constraints` for `Check` and `Unique`, `_sql_indexes` for indexes. `[CODE] move.py:1085-1095,
152-159` So the ORM is not a wall between the model and PostgreSQL; it is a thin declarative surface over
it. That is the right instinct, and the fact that Tryton uses it only **five times** across the whole of
`account` and `analytic_account` `[CODE]` says something about how easy it is to have the mechanism and
not use it.

**The transferable idea for QAYD** is not to move the schema into a declarative file. It is that *the
schema should be answerable as a whole*. QAYD's migrations produce a schema; nothing describes it. A
generated, committed schema document — derived from the live database, diffed in CI — gets OFBiz's
benefit without OFBiz's cost. (`docs/DATABASE_STRUCTURE.md` is the equivalent artefact in the PillPal
codebase; QAYD has no counterpart.)

---

# §2 — Four sign conventions

Every double-entry system must represent direction. There are exactly four approaches in use here, and
each is a different bet.

### A. Two columns — Tryton

`debit` and `credit` are separate `Monetary` columns, both `required`, with a database CHECK enforcing
`credit * debit = 0`. `[CODE] modules/account/move.py:907-920, 1087`

- **Buys:** direction is structural and unforgettable; a "negative debit" is representable (and Tryton
  uses it — `cancel()` negates rather than swaps by default, `[CODE] move.py:424-425`); the CHECK is a
  simple arithmetic expression the database can enforce.
- **Costs:** every aggregate is `SUM(debit) - SUM(credit)`; every write picks a column; twice the columns
  and twice the indexes on the largest table.

### B. Magnitude + flag — OFBiz

`amount` unsigned, `debitCreditFlag` an `indicator` (`CHAR(1)`). `[CODE] accounting-entitymodel.xml:1869`

- **Buys:** one amount column; the flag reads naturally in a UI.
- **Costs:** every aggregate is a `CASE`; the flag is a character with no constraint, so `'X'` is storable;
  and it composes badly with a second currency amount, which needs the same flag applied consistently.

### C. Magnitude + numeric sign — Sage Intacct

`TR_TYPE` ∈ {`1`, `−1`}, `AMOUNT` absolute. `[DOCS]`
<https://developer.intacct.com/api/general-ledger/journal-entries/>

- **Buys:** aggregation is `SUM(AMOUNT * TR_TYPE)` — arithmetic, not branching. A genuine improvement on
  B for the same conceptual model.
- **Costs:** two fields still, and the multiplication is easy to forget in a hand-written query.

### D. One signed column — QAYD

`signed_base_amount`. Existing.

- **Buys:** `SUM()` is the answer. Directly. That is the operation the ledger performs more than any
  other, and every other convention taxes it.
- **Costs:** direction is a property of a value rather than of a structure, so a sign error is a data
  error rather than a shape error; and "show me the debits" is `WHERE signed_base_amount > 0`, which is
  only correct if zero-amount lines are handled deliberately.

**Assessment.** QAYD is in a minority of one, and it is right — but the reason it is right is narrower
than it may appear. The single signed column wins because **the ledger is append-only and read by
aggregation** (AD-03). In a system where journal lines are mutable and read individually, Tryton's two
columns would be the better bet, because the invariant is checkable per row. QAYD's convention is correct
*because of* AD-03, not independently of it. Worth recording, because if AD-03 were ever revisited this
would need revisiting too.

**One thing to steal from Tryton regardless.** Its zero-amount handling is deliberate: `post()` collects
lines where `debit = 0 AND credit = 0 AND reconciliation IS NULL AND (amount_second_currency IS NULL OR =
0)` and auto-reconciles them on reconcilable accounts. `[CODE] move.py:495-504` Zero-value lines are a real
category (fully-allocated write-offs, memo lines) and they need a defined behaviour rather than an
accident.

---

# §3 — The Universal Data Model, credited and criticised

OFBiz's entity model descends from the Universal Data Model literature, and it is the best public worked
example of that tradition. It deserves a fair hearing before the criticism.

### What it gets genuinely right

**Party/Role.** There is no `Customer` table and no `Vendor` table. There is `Party`, `PartyRole`, and
`RoleType`; a party plays roles, and the same organisation can be customer, supplier and employer without
duplication. Transactions carry `(partyId, roleTypeId)` **together** — `AcctgTransEntry` and `AcctgTrans`
both do `[CODE] accounting-entitymodel.xml:1764+, 1869+` — so a journal line records not just *who* but
*in what capacity*. That second half is what most systems lose, and it is exactly what makes questions
like "what did we buy from customers?" answerable.

**Temporal association by primary key.** `GlAccountOrganization` is `(glAccountId, organizationPartyId)`
with `fromDate`/`thruDate`; `GlAccountCategoryMember` puts `fromDate` **inside** the PK:
`(glAccountId, glAccountCategoryId, fromDate)`. `[CODE] accounting-entitymodel.xml` So "which accounts
were in this category in FY2024" is a `WHERE` clause, not an audit-log reconstruction. Most systems make
classification a mutable FK and lose history silently on every reorganisation.

**Global master, local activation.** One chart of accounts exists; `GlAccountOrganization` activates
accounts per organisation for a date window. `[CODE]` This is a better answer than either "one chart per
company" (duplication, drift, no consolidation) or "one shared chart" (no local control).

**Supertype/subtype consistency.** 31 `*Type` entities in accounting alone `[CODE]`, applied uniformly.
Whatever else is true, the model is *predictable* — a new reader can guess the shape of an unfamiliar area
and be right.

### Where it fails, and why the failure is structural

The model's expressiveness exceeds what the storage layer can enforce, and the gap was closed by dropping
enforcement: **434 `one-nofk` relations** — documented relationships explicitly marked as not to be
enforced by a foreign key — of which **64 are in accounting**. `[CODE]` They cluster precisely on the
polymorphic joins (`PartyRole`, `RoleType`, `StatusItem`) that make the model powerful.

**This is the central tension of the Universal Data Model tradition, and it is worth stating as a general
law:** a data model general enough to describe any enterprise is, by construction, too general for a
relational database to constrain. Every additional degree of expressive freedom is a constraint you can no
longer write.

### What QAYD should take, and what it should refuse

| Take | Refuse |
|---|---|
| Party + role recorded together on financial records | 31 open type taxonomies where a closed enum belongs |
| `fromDate`/`thruDate` on **classification** relationships | Temporal keys on everything |
| Global master with per-entity activation windows | Polymorphic joins that force `one-nofk` |
| A single, greppable model artefact | Generality as a goal in itself |

**The discriminating rule:** be universal about *what things are* (party, role, classification, period) and
be specific about *what the ledger does* (post, balance, lock, reverse). OFBiz applies universality to
both, and the ledger is where it costs the most.

---

# §4 — The dimension question: does Sage Intacct change AD-11?

**This is the question the research existed to answer.** AD-11 is settled, supersedes the frozen
specification's fixed-column design (TD-14), and requires a formal ADR before TD-14 is implemented. New
evidence therefore matters.

## §4.1 What AD-11 decided

Rows in `journal_line_dimensions`: one row per **(journal line, dimension, member, allocation)**.
Dimensions and members are ordinary tables. A composite FK `(member_id, dimension_id)` makes membership a
database guarantee. A `DEFERRABLE INITIALLY DEFERRED` constraint trigger enforces the 100%-and-amount
invariants. JSONB is legitimate as transport, never as ledger storage.

Rejected: fixed FK columns; JSONB `{member: percentage}`; EAV.

The decisive evidence was Odoo: it chose JSONB, hit every named limitation (no referential integrity, no
expressible 100% CHECK, money not aggregable by the JSONB key, runtime `ALTER TABLE` on plan creation),
and **then materialised the same data into `account.analytic.line` rows anyway**, maintaining two-way sync
with `skip_analytic_sync` flags in six places.

## §4.2 What the new evidence shows

### Sage Intacct — fixed named fields, plus an extension tail

The `GLENTRY` object carries a named field per standard dimension: `DEPARTMENT`, `LOCATION`, `PROJECTID`,
`TASKID`, `COSTTYPEID`, `CUSTOMERID`, `VENDORID`, `EMPLOYEEID`, `ITEMID`, `CLASSID`, `CONTRACTID`,
`WAREHOUSEID`. `[DOCS]` <https://developer.intacct.com/api/general-ledger/journal-entries/> Thirteen
standard dimensions are documented in the help centre. `[DOCS]`
<https://www.intacct.com/ia/docs/en_US/help_action/Intacct_basics/Dimensions/basics-dimensions-overview.htm>

User-defined dimensions extend the set, "used in the same way as standard dimensions", carrying an
additional fee, and named internally with a `gldim` prefix (`gldimpromo` → "Promo"). `[DOCS]`
`[COMMUNITY]` <https://help.velixo.com/doc/main/intacct/using-dimensions>

**The physical storage of UDDs is `[UNKNOWN]`.** A per-dimension prefixed field name is consistent with a
real column per UDD or a slot in a wide extension table `[INFERENCE]`, but Sage does not publish this and
we do not assert it.

### Sage Intacct — allocations are named rules, and splits store money

Two mechanisms, both important:

1. A **Transaction Allocation** is a first-class object: `ALLOCATIONID`, `DESCRIPTION`, `STATUS`, `TYPE`,
   and `ALLOCATIONENTRIES`. Each entry targets up to ten dimension id fields and carries `VALUE` plus
   `VALUETYPE` ∈ {`Amount`, `Percent`}. Percentages "must always total 100%". `[DOCS]`
   <https://developer.intacct.com/api/general-ledger/transaction-allocations/>
2. An inline **custom split**: set `ALLOCATION="Custom"` on a `GLENTRY` and supply multiple `SPLIT`
   elements, each with dimension values and an **`AMOUNT`**, where "all SPLIT element's AMOUNT values must
   sum up to equal GLENTRY element's TRX_AMOUNT". `[DOCS]`

**Note what the split stores: an amount.** Not a percentage.

### Tryton — the same two conclusions, reached independently

- An **axis** is the root of an account tree; each root defines one axis.
  `[CODE] modules/analytic_account/doc/design.rst`, `account.py:35-51`
- An **allocation** is `analytic_account.line`: a row per (move line, analytic account) carrying its own
  `debit` and `credit` `Monetary` columns, FK to the move line `ON DELETE CASCADE`, with its own database
  CHECK `credit * debit = 0`. `[CODE] modules/analytic_account/line.py:17-56` **Money, not percentage.**
- A **percentage split** is a named reusable object: an account of `type='distribution'` owning
  `(account, ratio)` children, validated to sum to exactly 1.
  `[CODE] modules/analytic_account/account.py:331-348, 122-132`
- **One member per axis per subject** is a database `UNIQUE (origin, root)`.
  `[CODE] account.py:395-398`
- Re-parenting a member to a different axis is **refused** once entries exist. `[CODE] account.py:304-322`
- Rounding of a split is resolved once, by `distribute()`, which allocates by ratio, tracks the remainder,
  and walks the results adding one rounding unit at a time until the remainder is zero, asserting
  `sum(a for _, a in result) == amount`. `[CODE] account.py:279-302`

### OFBiz — the fixed-column alternative at maturity

Ten nullable dimension FK columns on `AcctgTransEntry`, no customer extension point, no percentage split
possible. `[CODE] accounting-entitymodel.xml:1869-1893` See [`ANTI_PATTERNS.md AP-07`](ANTI_PATTERNS.md).

### Acumatica — the segmented-string alternative

Subaccounts as fixed-width segmented strings with pre-enumerated valid combinations. `[COMMUNITY]` See
[`ANTI_PATTERNS.md AP-06`](ANTI_PATTERNS.md).

## §4.3 The five-system table

| System | Axis definition | Line-level storage | Customer-extensible? | % split | Split resolved to |
|---|---|---|---|---|---|
| **QAYD (AD-11)** | Row in `dimensions` | Rows in `journal_line_dimensions` | Yes, one INSERT | Yes | **percentage** |
| Odoo | `analytic.plan` | JSONB **and** materialised rows | Yes (runtime DDL) | Yes | both, synced |
| **Tryton** | Root of an account tree | Rows in `analytic_account.line` | Yes, new root | Yes, via named rule | **amount** |
| **Sage Intacct** | Standard field / UDD | Named fields on `GLENTRY` | Yes, paid | Yes, via named rule | **amount** |
| OFBiz | Nothing — hard-coded | 10 nullable FK columns | **No** | **No** | — |
| Acumatica | Segment of a string key | One segmented string | Segments, at setup | **No** | — |

## §4.4 The answer

**AD-11's central choice is confirmed. Two of its details are challenged, and the challenge is
well-evidenced enough to act on.**

### Confirmed — rows over columns, and rows over JSONB

The two systems that cannot be extended by the customer (OFBiz, Acumatica) are the two with the worst
dimensional stories, and their limitations are exactly the ones AD-11 predicted. The system that chose
JSONB (Odoo) pays the row cost anyway. **Nothing in the new evidence supports fixed columns or JSONB, and
OFBiz supplies a mature, concrete demonstration of the fixed-column failure mode that AD-11 previously had
to argue for in the abstract.** TD-14 should remain superseded.

### Challenged (1) — the allocation should be a named rule, not a per-line number

Two independent systems, no shared lineage, both made the allocation policy a first-class named object
with a status and a lifecycle: Intacct's `ALLOCATIONID`, Tryton's `type='distribution'` account. Neither
stores an anonymous percentage per line.

The mechanical benefit is that **the 100% invariant is validated once per rule instead of once per line.**
AD-11's `DEFERRABLE INITIALLY DEFERRED` constraint trigger is a good mechanism for a hard problem —
but the reason the problem is hard is that per-line percentages create a fresh opportunity to violate the
invariant on every single line. A named rule makes most of that problem disappear rather than solving it.

It also answers a question AD-11 does not: *how do you change an allocation policy?* With per-line
percentages there is no good answer. With a named rule it is one row, and posted history correctly retains
the resolved amounts. See [`BEST_PRACTICES.md BP-01`](BEST_PRACTICES.md).

### Challenged (2) — the stored row should carry money, not percentage

Tryton's `analytic_account.line` carries `debit`/`credit`. Intacct's `SPLIT` carries `AMOUNT`. Odoo's
materialised `account.analytic.line` carries amounts too.

**Three systems, three architectures, one agreement.** The reason is mechanical: with amounts, dimensional
reporting is `SUM(...) GROUP BY member` and nothing recomputes. With percentages, every report multiplies
and re-rounds, and the rounding rule in force at report time silently rewrites history. Tryton's
`distribute()` shows what the alternative costs — remainder redistribution is fiddly, order-dependent, and
must produce an exact total `[CODE] account.py:279-302`. **That logic should run once, at write time,
under `PostingService`, and its output should be what is stored.**

There is a second benefit that matters specifically for QAYD: AD-11 lists among its risks that
`journal_line_dimensions` will need its own partitioning strategy and that reads pay a join. Amount rows
make that table **independently aggregable** — a dimensional report can hit it without joining
`journal_lines` at all, which is exactly the property that makes partitioning by `(company_id, period)`
worth having.

### Not challenged — the integrity design

The composite `(member_id, dimension_id)` FK is corroborated by Tryton's `UNIQUE (origin, root)` plus
axis-scoped member domain. Two systems, same guarantee, arrived at independently. Keep it, and add
Tryton's refinement: **an axis assignment must be immutable once allocations exist**, because re-parenting
a member silently reinterprets every historical row. `[CODE] account.py:304-322`

### The bigger finding

**The dimensional model everyone praises has an unremarkable storage design.** Intacct is the market's
reference implementation of dimensional accounting and it stores dimensions as named fields on a line.
What it does exceptionally is everything above the storage: hierarchical members with rollups,
relationships between dimensions that autofill, named reusable allocations, and dynamic allocations that
emit real auditable journal entries. `[DOCS]`

**QAYD is currently winning the argument nobody is having and losing the one that decides adoption.** Our
storage is more correct than Intacct's. Our dimensional *product* does not exist. See
[`BEST_PRACTICES.md BP-11`](BEST_PRACTICES.md) and
[`IMPLEMENTATION_RECOMMENDATIONS.md R-03`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

# §5 — Tryton vs OFBiz vs Odoo: the controlled comparison

Three systems, one problem — an open-source ERP with a real general ledger — and three philosophies. The
comparison is unusually clean because **Tryton and Odoo share an ancestor**: both descend from TinyERP,
diverging in 2008. Same problem, same era, same language, opposite choices. OFBiz solved the same problem
from a data-model-first starting point in a different language entirely.

## §5.1 The three bets

| | **Tryton** | **Odoo** | **OFBiz** |
|---|---|---|---|
| Bet | Correctness through **restraint** | Adoption through **flexibility** | Durability through **modelling** |
| Schema | Python declarations | Python declarations + runtime DDL | 21,503 lines of XML |
| Composition | Mixins (`PoolMeta`) | Monkey-patching / inheritance | Entity extension + ECAs |
| Logic lives in | Model classmethods | Model methods | Minilang, Groovy, Java, ECAs |
| Analytic model | Rows, axis = tree root | JSONB + materialised rows | 10 fixed columns |
| Tenancy | DB per customer | DB per customer | DB per tenant |
| Ecosystem | Small | **Very large** | Small |
| Accounting core size | 14,309 lines | Larger than our 14,150-line description of it | 4,041 XML + 3,382 minilang |

## §5.2 What Tryton got right that Odoo got wrong

**1. The analytic model — Odoo's second design is Tryton's first.** Odoo's original design (one analytic
account plus tags) proved too rigid and was replaced by N-dimensional plans with JSONB distribution. That
replacement pays four documented costs and then materialises rows anyway. Tryton had axes-as-tree-roots
plus money-carrying allocation rows from the start, and has not needed to change it. **Restraint produced
the design that flexibility took two attempts and a permanent sync layer to approximate.**

**2. No runtime DDL for dimensions.** Odoo creates or renames an analytic plan by executing `ALTER TABLE`
/ `CREATE INDEX` at runtime, per inheriting model. In Tryton, a new axis is a new *row* — a root account.
`[CODE] analytic_account/account.py:35-51` Same capability, no schema change. AP-10.

**3. Composition without patching.** `PoolMeta` mixins compose modules by inheritance. Odoo's culture of
reaching into other modules' methods makes every upgrade a negotiation with every installed module.

**4. The strict sequence has a name.** `ir.sequence.strict` is a distinct model, and
`account.period.move_sequence` is typed to it, so a non-gapless sequence cannot be wired to a journal by
accident. `[CODE] ir/sequence.py:445-451`, `period.py:44-49` A boolean flag would not give that.

**5. Size discipline.** 14,309 lines for the whole account module. Legibility is a correctness property in
financial software: an invariant nobody can find is an invariant nobody maintains.

## §5.3 What Odoo got right that Tryton got wrong

**1. Adoption is a technical property.** Odoo has an ecosystem, a labour market, localisations for
jurisdictions Tryton will never reach, and a partner network. A correct ERP nobody deploys teaches
nothing and protects no one. Tryton's restraint bought elegance at the cost of relevance, and that is a
real loss, not a purity dividend.

**2. Extensibility as a product decision.** Odoo's monkey-patching is architecturally poor and
commercially decisive: a partner can change anything, so a partner will sell it. Tryton's cleaner
composition is harder to bend to a customer's odd requirement, and odd requirements are what ERP sales
consist of.

**3. Neither has an answer to multi-tenancy.** Both are one-database-per-customer. Odoo at least has
the operational tooling to make that survivable at thousands of instances.

## §5.4 What OFBiz got right that both got wrong

**1. The data model as an artefact.** OFBiz is the only one of the three where "what is the shape of this
system's data?" has a single, complete, reviewable answer. Party/role, temporal association, global master
with local activation — all of it visible in one place. Neither Tryton nor Odoo has anything comparable.

**2. Modelling *in what capacity*.** `(partyId, roleTypeId)` on a journal line. Tryton records `party`;
Odoo records `partner_id`. Neither records the capacity, and the capacity is where consolidation,
related-party disclosure and intercompany elimination live.

**3. Temporal classification as a primary key.** `fromDate` inside the PK of
`GlAccountCategoryMember`. `[CODE]` Both Python systems make classification a mutable FK and lose history
on every reorganisation.

## §5.5 What OFBiz got catastrophically wrong that both Python systems got right

**It reimplemented the database.** Tryton and Odoo both declare real SQL constraints when they want them —
Tryton's `_sql_constraints` produce genuine `CHECK` and `UNIQUE` clauses. `[CODE] move.py:1085-1095`
OFBiz's entity engine **cannot emit a CHECK at all**, so `AcctgTransEntry` has no arithmetic invariant of
any kind, and money is `NUMERIC(18,2)` everywhere forever.

And it wrote the ledger in a DSL it abandoned. `postAcctgTrans` is minilang.
`[CODE] AcctgTransServices.xml:181` Tryton's equivalent is 85 lines of readable Python with a
currency-aware SQL aggregate. `[CODE] move.py:445-531` **The comparison is not close, and it is the
clearest single demonstration in this study that the implementation language of the financial core is an
architectural decision, not a preference.**

## §5.6 The three-line summary

- **Tryton got restraint right and ambition wrong.** The best small design; too small to matter.
- **OFBiz got the data model right and the database wrong.** The best conceptual model; it forfeited the
  engine that could have enforced it.
- **Odoo got adoption right and integrity wrong.** The largest ecosystem; the weakest guarantees.

**QAYD's stated strategy is the fourth corner: Tryton's restraint, OFBiz's modelling discipline, Odoo's
extensibility ambition, and integrity in PostgreSQL where none of the three put it.** That is coherent —
and the honest observation is that all three of these projects had coherent strategies too, and each
sacrificed something. QAYD's sacrifice, on current evidence, is **breadth**: Tryton has ~60 finance
modules; QAYD has a posting service. That is the right sacrifice at this stage and it will not stay right
forever.

---

# §6 — Where invariants live

The single comparison that matters most, because it is where QAYD's actual moat is.

| Invariant | QAYD | Tryton | OFBiz | Acumatica |
|---|---|---|---|---|
| Tenant isolation | **PostgreSQL RLS** | N/A (DB per customer) | DB per tenant | `CompanyID` predicate in framework |
| Debits = credits | **DB CHECK + service** | SQL aggregate in `post()` | minilang, ±0.01 literal | `[UNKNOWN]` |
| Debit XOR credit | N/A (signed column) | **DB CHECK** `credit*debit=0` | Nothing | `[UNKNOWN]` |
| Posted entry immutable | **DB trigger** | Python `check_modification` | `isPosted` flag, app-checked | `[UNKNOWN]` |
| Member belongs to axis | **Composite FK** | **DB UNIQUE + domain** | N/A | N/A |
| Allocation sums to 100% | Deferred constraint trigger | Python, on the **rule** | N/A | `[UNKNOWN]` |
| Audit trail | **Hash-chained, anchored, always on** | Optional `__history` table | `createdDate` columns | `[UNKNOWN]` |
| Gapless numbering | Deliberate serialisation | `ir.sequence.strict` row lock | `[UNKNOWN]` | `[UNKNOWN]` |
| Period locked | Trigger + status view | Workflow states | Checked in minilang | `[UNKNOWN]` |

**Reading.** QAYD enforces in the database what every other system enforces in application code. That is
the differentiator, and it is worth more than any feature on the roadmap, because it is the one property
that cannot be added later — an invariant retrofitted to existing data must first prove the existing data
satisfies it, and it never does.

**Two honest qualifications.**

First, Tryton is not naive here: it *does* use CHECK constraints where the invariant is per-row
(`credit * debit = 0`, and the same shape on `analytic_account.line`). `[CODE] move.py:1087`,
`analytic_account/line.py:48-52` It uses only five across the whole accounting domain, and the ones it
skips are the multi-row invariants that need triggers. **The gap between Tryton and QAYD is not
awareness; it is willingness to write triggers.**

Second, database enforcement has a cost QAYD is already paying and should keep naming: deferred constraint
triggers fail at `COMMIT` rather than at `INSERT`, which AD-11 already flags as unintuitive to debug.
Every system in this study chose application enforcement partly because the error messages are better.
That is a real tradeoff and the answer is investment in diagnostics, not retreat.

---

# §7 — Extension architecture

| System | Mechanism | Upgrade safety | Inventory of customisations |
|---|---|---|---|
| Acumatica | `PXGraphExtension`, `PXCacheExtension`, Customization Projects | **Good** — additive, versioned | **Yes** — a project is an artefact |
| Tryton | `PoolMeta` mixins, module dependency graph | Good — composition, not patching | Partial — the module list |
| OFBiz | `<extend-entity>`, SECAs, EECAs, component overrides | Poor — ECAs are unordered and unbounded | No |
| Odoo | Inheritance + monkey-patching | Poor | No |
| QAYD | Named seams (AD-13) | Principle stated, mechanism unbuilt | No |

**Acumatica is the model to learn from, and the specific thing to learn is not the extension classes — it
is that a customisation is a listable, versionable artefact.** "What is non-standard about this tenant?"
is a question most ERPs cannot answer, and it is the question that determines whether an upgrade is a
deploy or a project.

**The warning that comes with it.** Convention-based binding — handlers attached by naming rather than by
registration — is invisible to static analysis. A handler named wrongly silently does nothing, and silence
is the worst possible failure mode in financial software. Any QAYD version of this needs the "what is
extending what" report built as part of the mechanism, on day one, not as a later tool.

**OFBiz is the counter-example on ordering.** `secas_ledger.xml` wires roughly a dozen business services
to accounting services via commit-event rules `[CODE]`, with no declared ordering and no bound on who may
add another. AD-14 gives QAYD the same decoupling benefit; AD-04 is what keeps it from becoming the same
problem. **The two decisions are load-bearing together and neither is safe alone.**
