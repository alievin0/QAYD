# Overview — Seven ERP Platforms

**Profiles of Tryton, Apache OFBiz, Sage Intacct, Acumatica, Oracle Fusion ERP, Infor CloudSuite and
Epicor.** Evidence-graded throughout. The two open-source systems carry `[CODE]` evidence; the rest carry
`[DOCS]`, `[COMMUNITY]`, or an honest `[UNKNOWN]`.

For the eight systems already profiled (Odoo, ERPNext, SAP, NetSuite, Dynamics 365, Akaunting, Dolibarr,
QAYD itself), see [`06_COMPETITIVE_ANALYSIS.md`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md).

---

# Part 1 — Evidence reality, stated up front

Before the profiles, the honest accounting of what could actually be learned.

| Platform | Source readable | Docs quality | Section length here | Justified because |
|---|---|---|---|---|
| Tryton | **Yes** — GPL-3, small, readable | Good (in-repo design docs) | Long | We read the accounting engine line by line |
| Apache OFBiz | **Yes** — Apache-2.0 | Mixed (asciidoc, partly stale) | Long | The entity model and posting service are fully visible |
| Sage Intacct | No | **Good** — real API schemas published | Medium | Field-level GL schema is public; that answers the dimension question |
| Acumatica | No | **Good** — a real framework guide is published | Medium | Framework and multi-tenancy are documented; the ledger is not |
| Oracle Fusion | No | Thin at the architecture layer | Short | Oracle documents *configuration*, not *storage* |
| Infor CloudSuite | No | Marketing only | **Very short** | Nothing verifiable. Padding it would be fabrication |
| Epicor | No | Marketing only | **Very short** | Same |

**This asymmetry is the point, not a defect of the research.** The systems we can read teach the most, and
they happen to be the ones nobody writes competitive analyses about.

---

# Part 2 — System profiles

## 2.1 Tryton 8.1 — the restrained one

**What it is.** A Python ERP whose lineage traces to TinyERP (2008), the same ancestor as Odoo. Same
problem, same era, same language, deliberately opposite philosophy. GPL-3. 217 modules in the monorepo,
of which ~60 are accounting-related. `[CODE]` — `modules/`, commit `54183ea`.

**Scale of the codebase.** The server core (`trytond`) is **76,939 lines of Python**; the entire `account`
module is **14,309 lines**, of which the double-entry engine (`move.py`) is 3,325 and the chart of
accounts (`account.py`) is 3,444. `[CODE]` For comparison, our Odoo study alone ran to 14,150 lines of
*notes*. **Tryton's whole general ledger is smaller than our description of Odoo's.** That is the single
most important fact about it.

**Architecture.** A declarative ORM (`ModelSQL` / `ModelView` / `ModelStorage`) over SQL, with a module
system that composes by mixin (`PoolMeta`) rather than by monkey-patching. Business logic lives in
classmethods on the model — Tryton has no service layer and no Actions equivalent; the model *is* the
service. `[CODE]`

**Journal engine.** `account.move` (header) + `account.move.line` (lines). Two states only: `draft`,
`posted` — there is no third "validated" limbo. `[CODE] modules/account/move.py:121-124`

**Money and sign convention.** Two separate `NUMERIC` columns, `debit` and `credit`, both `required`, with
a database CHECK enforcing `credit * debit = 0`. `[CODE] move.py:1087` **Tryton never stores a signed
amount.** Sign is structural: which column the number is in.

**Balance enforcement.** Not a database constraint — it cannot be, since balance is a property of a set of
rows. `Move.post()` runs a single SQL aggregate per company (`GROUP BY move HAVING
ABS(ROUND(SUM(debit-credit), digits)) >= currency.rounding`) and raises `PostError` on the first offender.
`[CODE] move.py:479-493` Note the rounding is **currency-aware**, derived from `company.currency.digits`
and `.rounding`, not a hard-coded epsilon.

**Immutability.** Application-level, via a `check_modification` hook that raises `AccessError` when a
posted move is written or deleted. `[CODE] move.py:290-301` **There is no database trigger.** A direct
`UPDATE` against the table succeeds. This is the single biggest architectural gap versus QAYD.

**Correction model.** `Move.cancel()` copies the move with `debit`/`credit` swapped (or negated), links the
copy back via `origin`, and — where the accounts are reconcilable — reconciles original against reversal.
`[CODE] move.py:397-443` Same philosophy as QAYD's AD-07, implemented in ~45 lines.

**Gapless numbering.** A dedicated model class, `ir.sequence.strict`, whose entire body is *"call the
parent with `_lock=True`"*. `[CODE] trytond/ir/sequence.py:445-451` Gaplessness is a row lock on the
sequence record, deliberately serialising allocation — exactly QAYD's AD-09, and Tryton makes the cost
visible by giving the strict variant its own class name.

**Periods.** `account.period` is a `Workflow` with three states: `open`, `closed`, `locked`.
`[CODE] modules/account/period.py:27,39-43` No `draft`. A period holds its own `move_sequence` (a
`ir.sequence.strict`) with a `UNIQUE` constraint ensuring no two periods share one. `[CODE] period.py:78-82`
This is AD-10's model — periods as a dimension, locks as a cursor — arrived at independently.

**Analytic / dimensional accounting.** The most interesting subsystem, and covered in depth in
[`ARCHITECTURE.md §4`](ARCHITECTURE.md). Summary: an **axis is a root account in a tree**; a dimension
member is a node under it; an allocation is a **separate money-carrying row** (`analytic_account.line`)
FK'd to the move line with `ON DELETE CASCADE`, carrying its own `debit`/`credit` and its own CHECK
constraint. `[CODE] modules/analytic_account/line.py:17-56` Percentage splits are a **named reusable
object** — an account of `type='distribution'` with child ratios that must sum to 1
`[CODE] analytic_account/account.py:122-132` — not a per-line payload.

**Multi-tenancy.** None. Tryton is multi-*company* (a `company` FK on every financial record), not
multi-tenant. Isolation between customers is one database per customer. `[CODE]` — no `ROW LEVEL SECURITY`
and no `CREATE TRIGGER` anywhere in `trytond`.

**Access control.** `ir.rule` — record rules whose `domain` is a PYSON expression, decoded per request and
injected into the `WHERE` clause by `ModelSQL`. `[CODE] trytond/ir/rule.py:162-176`,
`trytond/model/modelsql.py:1193,1771` **Application-enforced, like Odoo's.** If you reach the database
by any other path, the rules do not exist.

**Audit.** Opt-in per model: setting `_history = True` makes the ORM maintain a parallel
`<table>__history` table. `[CODE] trytond/model/modelsql.py:322,476-479` Not hash-chained, not anchored,
and off by default for `account.move`.

**Strengths.** Extraordinary restraint. Small, legible, internally consistent. Correct on gapless
sequences, period workflow, reversal-based correction, and — this is the finding — on analytic
architecture, where it chose the design Odoo reached only after a rewrite. Its in-repo design
documentation (`doc/design/*.rst`) explains *intent*, not just API.

**Weaknesses.** Zero database-level enforcement of the invariants it cares most about. No multi-tenancy.
No AI story. A small ecosystem and a small labour market. The ORM's PYSON domain language is powerful and
almost entirely unreadable to newcomers.

**Score against QAYD's concerns.** Better than QAYD today on: breadth of accounting features, period
workflow maturity, analytic modelling. Worse than QAYD on: integrity enforcement, tenancy, audit.

---

## 2.2 Apache OFBiz — the data model that outlived its database

**What it is.** A Java ERP framework begun in 2001, an Apache top-level project, whose defining artefact is
not its code but its **entity model**: a declarative XML description of the entire enterprise data model,
descended from the Universal Data Model literature (Silverston, Hay). Apache-2.0. `[CODE]` — commit
`cefbdb2`.

**Scale, quantified.** In `applications/datamodel/entitydef/` alone: **733 entities and 160 view-entities
across 21,503 lines of XML** in ten files. `[CODE]` The accounting model is 147 entities in 4,041 lines.
The accounting *implementation* is 71 Groovy files, 18 Java files, and 3,382 lines of minilang XML.
`[CODE] applications/accounting/`

**The entity engine.** Entities are declared in XML (`<entity>`, `<field>`, `<prim-key>`, `<relation>`,
`<view-entity>`, `<extend-entity>`), typed against an abstract type vocabulary, and mapped to SQL types
per database dialect by twelve `fieldtype*.xml` files (Postgres, Oracle, MySQL, MSSQL, Firebird, H2,
HSQL, Sybase, Daffodil, Advantage, Axion, SapDB). `[CODE] framework/entity/fieldtype/` The engine
**generates and reconciles DDL at runtime**: datasources carry `check-on-start="true"` and
`add-missing-on-start="true"`, so on boot the engine compares the XML model to the live database and adds
what is missing. `[CODE] framework/entity/config/entityengine.xml:132-133`

**The type vocabulary is the tell.** On PostgreSQL, `currency-amount` maps to `NUMERIC(18,2)` and
`indicator` maps to `CHAR(1)`. `[CODE] framework/entity/fieldtype/fieldtypepostgres.xml:33,44` Two decimal
places for all money everywhere, and booleans as `'Y'`/`'N'` characters.

**Universal Data Model heritage, concretely.** `Party` is the supertype of person and organisation;
`PartyRole` assigns roles; `RoleType` is a reference entity; associations are temporal by primary key.
`GlAccountOrganization` has PK `(glAccountId, organizationPartyId)` plus `fromDate`/`thruDate` — a global
chart of accounts activated per organisation for a date window. `[CODE] accounting-entitymodel.xml`
`GlAccountCategoryMember` puts `fromDate` **inside the primary key**: `(glAccountId, glAccountCategoryId,
fromDate)`. `[CODE]` The accounting model contains **31 `*Type` entities** — the supertype/subtype pattern
applied consistently. `[CODE]`

**Journal engine.** `AcctgTrans` (header) + `AcctgTransEntry` (lines), PK `(acctgTransId,
acctgTransEntrySeqId)`. `[CODE] accounting-entitymodel.xml:1764, 1869`

**Sign convention.** A third variant: `amount` is unsigned and `debitCreditFlag` is an `indicator` —
`CHAR(1)`, `'D'` or `'C'`. `[CODE]` So across four systems we now have four conventions: Tryton's two
columns, OFBiz's flag plus magnitude, Intacct's `TR_TYPE` of `1`/`-1` plus magnitude, and QAYD's single
`signed_base_amount`.

**Dimensions.** Fixed columns on the entry, and there are ten of them: `partyId`, `roleTypeId`,
`theirPartyId`, `productId`, `inventoryItemId`, `glAccountTypeId`, `organizationPartyId`, `taxId`,
`groupId`, `settlementTermId`. `[CODE] accounting-entitymodel.xml:1869-1893` **This is exactly the
fixed-column design AD-11 rejected, at full maturity — and it shows the failure mode clearly:** the
dimension set is frozen at the vendor's guess, every column is nullable, none can be added by a customer
without a schema change, and no percentage split is expressible.

**Posting.** An SECA (Service Event Condition Action) rule fires `postAcctgTrans` on the *commit* event of
`createAcctgTransAndEntries`. `[CODE] applications/accounting/servicedef/secas_ledger.xml:25-28` The
posting service itself is **written in minilang, a deprecated XML scripting DSL**, at
`applications/accounting/minilang/ledger/AcctgTransServices.xml:181`. `[CODE]` It checks `isPosted='Y'`,
calls `calculateAcctgTransTrialBalance`, and rejects the transaction when `debitCreditDifference` is
`>= 0.01` or `<= -0.01`.

**That epsilon is a real defect worth naming.** `0.01` is a hard-coded absolute literal in an XML file.
It is not currency-aware. It is not scale-aware. In a currency with three decimal places — KWD, BHD, OMR,
**QAYD's home currencies** — a 0.009 imbalance posts silently. Contrast Tryton, which derives the
tolerance from `company.currency.rounding` at `move.py:483-485`.

**Integrity.** The DDL generator emits `PRIMARY KEY`, `FOREIGN KEY`, `NOT NULL` and `UNIQUE` — and
**nothing else. There is no CHECK constraint support in the entity engine at all.**
`[CODE] framework/entity/src/main/java/org/apache/ofbiz/entity/jdbc/DatabaseUtil.java` — a keyword scan of
all 3,383 lines finds 3 `FOREIGN KEY`, 4 `NOT NULL`, 7 `PRIMARY KEY`, 2 `UNIQUE`, and zero `CHECK`.
Worse: the model declares **434 relations of type `one-nofk`** across the datamodel — documented
relationships explicitly marked as *do not create a foreign key* — of which **64 are in accounting alone**.
`[CODE]` `AcctgTransEntry`'s link to `PartyRole` is one of them.

**Multi-tenancy.** Database-per-tenant. The framework ships `Tenant`, `TenantDataSource`,
`TenantKeyEncryptingKey`, `TenantUserLogin`, `TenantComponent` and `TenantDomainName` entities; the
delegator carries a tenant id that selects a datasource. `[CODE] framework/entity/entitydef/entitymodel.xml:71-160`
This is the model QAYD rejected in AD-02, and OFBiz shows the cost honestly: tenant provisioning is
infrastructure work, and cross-tenant anything is impossible by construction.

**Strengths.** The data model is genuinely excellent and genuinely durable — party/role, temporal
associations, supertype/subtype, status as data. It is the best public worked example of enterprise data
modelling that exists in open source. The declarative entity layer makes the whole schema greppable and
diffable.

**Weaknesses.** It reimplemented the database inside the application: its own type system, its own DDL,
its own referential decisions, its own dialect abstraction across twelve backends — and in doing so gave
up CHECK constraints, expressive types, and any hope of database-enforced invariants. The financial core
is written in a deprecated XML DSL. Money is `NUMERIC(18,2)`. The most important number in the posting
service is a hard-coded `0.01`.

**Score against QAYD's concerns.** Better than QAYD today on: breadth and quality of the conceptual data
model, temporal modelling, declarative schema tooling. Far worse on: integrity, money precision, tenancy
isolation model, implementation language.

---

## 2.3 Sage Intacct — the dimension model everyone cites

**What it is.** A cloud financial-management suite, mid-market, widely regarded as the best commercial
implementation of dimensional accounting. Proprietary. Section is `[DOCS]` throughout.

**The dimension model — the thing we came for.** Intacct publishes **13 standard dimensions**: Affiliate
entity, Asset, Class, Contract, Cost type, Customer, Department, Employee, Item, Location, Project, Task,
Vendor, Warehouse (some subscription-gated). `[DOCS]`
<https://www.intacct.com/ia/docs/en_US/help_action/Intacct_basics/Dimensions/basics-dimensions-overview.htm>

**Storage shape, from the API schema.** The `GLENTRY` object exposes these dimension fields by name:
`ACCOUNTNO`, `DEPARTMENT`, `LOCATION`, `PROJECTID`, `TASKID`, `COSTTYPEID`, `CUSTOMERID`, `VENDORID`,
`EMPLOYEEID`, `ITEMID`, `CLASSID`, `CONTRACTID`, `WAREHOUSEID`. `[DOCS]`
<https://developer.intacct.com/api/general-ledger/journal-entries/>

**This is the decisive evidence, and it does not say what the folklore says.** The most-praised
dimensional model in the industry is **a fixed set of named fields on the journal line**, one per standard
dimension. Not rows. Not JSONB. Named columns — with an extension mechanism bolted alongside for
user-defined dimensions.

**Sign convention.** `TR_TYPE` carries `1` for debit and `-1` for credit; `AMOUNT` is the absolute value.
`[DOCS]` A batch (`GLBATCH`) holds at least two `GLENTRY` records that must balance. `[DOCS]`

**User-defined dimensions.** Customers can create additional dimensions, which "become available
throughout the company wherever dimensions are displayed and can be used in the same way as standard
dimensions". `[DOCS]` The internal naming convention prefixes them `gldim` (e.g. `gldimpromo`, surfaced in
the UI as "Promo"), and API access uses the configured *Integration Name*.
`[COMMUNITY]` <https://help.velixo.com/doc/main/intacct/using-dimensions>,
<https://onlinehelp.nectari.com/Latest/en/Templates/SageIntacct/SageIntacct-map-UDD.htm>
**UDDs carry an additional fee.** `[DOCS]` No published cap on their number was found. `[UNKNOWN]`

**The `gldim` prefix is a strong hint and nothing more.** A per-dimension prefixed field name is what you
would expect from a system that adds a real column (or a slot in a wide extension table) per user-defined
dimension. We did not find published confirmation of the physical storage. `[UNKNOWN]` — flagged rather
than guessed.

**Hierarchies and relationships.** Dimension members are hierarchical with parent/child rollups (Sage's
own example: a West Coast Division containing California, Nevada, Utah). Dimensions can also be *related*
to one another — a Customer↔Location relationship makes Location autofill on that customer's transactions.
`[DOCS]`

**Allocations — the second decisive finding.** Two distinct mechanisms:

1. **Transaction allocations** — a *named, reusable* definition (`ALLOCATIONID`) containing
   `ALLOCATIONENTRIES`. Each `ALLOCATIONENTRY` targets up to ten dimension id fields (`DEPARTMENTID`,
   `LOCATIONID`, `PROJECTID`, `CUSTOMERID`, `VENDORID`, `EMPLOYEEID`, `ITEMID`, `CLASSID`, `CONTRACTID`,
   `WAREHOUSEID`) and carries a `VALUE` plus a `VALUETYPE` of `Amount` or `Percent`. Three modes:
   percentages (which "must always total 100%"), fixed amounts, or a combination where exact amounts are
   taken first and the remainder is distributed by percentage. `[DOCS]`
   <https://developer.intacct.com/api/general-ledger/transaction-allocations/>
2. **Inline custom splits** — set `ALLOCATION` to `"Custom"` on a `GLENTRY` and supply multiple `SPLIT`
   elements, each with its own dimension values and its own `AMOUNT`, where "all SPLIT element's AMOUNT
   values must sum up to equal GLENTRY element's TRX_AMOUNT". `[DOCS]`
3. **Dynamic allocations** — periodic, after-the-fact distributions computed from source balances against
   a basis, which "automatically create journal entries with verifiable calculations attached".
   `[DOCS]` <https://www.intacct.com/ia/docs/en_US/help_action/General_Ledger/Allocations/allocations-overview.htm>

**Read (2) carefully: the split carries an `AMOUNT`, not a percentage.** By the time it is stored, the
allocation has been resolved into money. And read (3) carefully too: a dynamic allocation's output is *a
journal entry*, not a magic view — the analysis lands in the ledger where it can be audited.

**Reporting.** Dimension-driven, via the Financial Report Writer / Interactive Custom Report Writer.
Structural details `[UNKNOWN]`.

**Strengths.** The clearest, most usable dimensional model in the market, and it earns its reputation on
*ergonomics* — hierarchies, cross-dimension relationships, autofill, reusable named allocations — rather
than on storage cleverness. The lesson is that dimensional excellence is a UX and semantics problem more
than a schema problem.

**Weaknesses (from QAYD's standpoint).** The standard set is Sage's guess about your business, and
extending it costs money. Nothing about tenancy, integrity, or the physical model is published. Ten
dimensions per allocation entry is a real ceiling.

---

## 2.4 Acumatica — the framework is the product

**What it is.** A mid-market cloud ERP whose distinguishing feature is that its *development platform*
(the xRP / PX Framework) is documented and sold as a first-class artefact. Proprietary; `[DOCS]`.

**Framework model.** Three primitives. **DACs** (Data Access Classes) declare tables and fields as C#
classes with attributes. **Graphs** (`PXGraph`) are business-logic controllers, one per screen/process,
holding data views and event handlers. **BQL** (Business Query Language) expresses queries as generic type
parameters — `PXSelect<...>` — which the framework compiles to SQL. `[DOCS]`
<https://www.acumatica.com/media/2020/09/AcumaticaFramework_DevelopmentGuide.pdf>

**Why BQL matters.** Acumatica's stated benefit is "compile-time syntax validation, which helps to prevent
SQL errors". `[DOCS]` The queries are types, so a rename that breaks a query breaks the build. That is a
genuinely good idea and the opposite of string-built SQL.

**Customisation model — the strongest thing here.** Customers do not fork. They write **extension
classes** — `PXGraphExtension<>` to add or override behaviour on a base graph, `PXCacheExtension<>` to add
fields to a base DAC — packaged into versioned **Customization Projects** that are published onto an
instance. `[DOCS]` Event handlers bind to tables and fields *by naming convention*, so an extension hooks
into base logic without editing it. `[DOCS]` **This is the answer to the upgrade problem that most ERPs
fail:** customisation is additive and separately versioned, so the base can move.

**Multi-tenancy.** Shared database, discriminated by a **`CompanyID` column** on tenant-scoped tables; the
framework injects the predicate. Cross-tenant sharing is governed by a second column, **`CompanyMask`**, a
binary mask defining where a record is visible and updatable. Tables with no `CompanyID` are global.
Per-table sharing modes are `Split`, `Shared`, or `Separate`.
`[COMMUNITY]` <https://asiablog.acumatica.com/index.php/2015/10/company-mask-for-data-sharing-between-tenats-in-acumatica/>,
<https://community.acumatica.com/everything-else-119/same-database-for-several-tenants-11984>

**This is the shape QAYD rejected in AD-01, running in production at scale.** Isolation depends on the
framework remembering to add the predicate. Any query path that bypasses `PXSelect` — a report, a
migration, an integration, a bug — sees every tenant. QAYD's position is that this belongs in PostgreSQL
RLS, and Acumatica is the working counter-example that shows the approach *can* be made to work with
enough discipline, and exactly what it costs to sustain that discipline forever.

**Dimensions — the contrast with Intacct.** Acumatica does not have Intacct-style dimensions. It has
**Subaccounts**: a *segmented string key*. The GL structure is defined on a Segmented Keys screen; the
subaccount is divided into segments of fixed lengths (default 6 characters as two 3-character segments,
maximum 30 characters); segments may be validated against value lists; and **valid combinations must be
created before use** on a Subaccounts screen. `[COMMUNITY]`
<https://www.augforums.com/acumatica-general-ledger-structure/>,
<https://openuni.acumatica.com/wp-content/uploads/2017/08/F100_FinBasic_2020R2.pdf>

**That is the 1990s segmented-account model, and its failure modes are well known:** combinatorial
explosion of valid combinations, a hard character budget, meaning encoded in string positions, and
reporting that must parse substrings. Placing Acumatica and Intacct side by side is the clearest
available demonstration of why dimensions-as-attributes beat dimensions-as-segments.

**Ledger internals.** `GLTran` and `Batch` are the commonly cited table names, but no authoritative
published schema was found. `[UNKNOWN]` — deliberately not guessed.

**Strengths.** The customisation/extension model is best-in-class and directly relevant to QAYD's AD-13
(named seams). Typed queries are a real safety property. The framework is documented well enough that
third parties build serious products on it.

**Weaknesses.** Application-enforced tenancy. Segmented subaccounts instead of true dimensions. The
framework is heavy, proprietary, and .NET-bound — its ideas travel, its implementation does not.

---

## 2.5 Oracle Fusion ERP — documented at the configuration layer only

**Honest framing: Oracle documents how to configure Fusion, not how Fusion is built.** What follows is
what Oracle itself states, plus explicit gaps.

**What is documented `[DOCS]`.** Fusion's accounting core is **Subledger Accounting (SLA)**, packaged for
external sources as **Accounting Hub**. Oracle's own description: the product provides "registration of
your external systems, indicating what types of transactions or activities require accounting from those
systems"; "a library of transaction and reference information that will be used for defining accounting
treatments"; "configurable accounting rules to define accounting treatments for transactions"; and an
"accounting engine that combines transaction and reference information from source systems with
accounting rules to create detailed journals stored in an accounting repository".
<https://docs.oracle.com/cd/E25054_01/fusionapps.1111/e20374/F484243AN100CE.htm>

Oracle further states that "subledger and general ledger applications that generate accounting events
invoke the Create Accounting process to create journals that can be posted to the Oracle Fusion General
Ledger". `[DOCS]`

**The architectural idea, which is genuinely valuable.** Posting is not code attached to each business
document. It is a **rules engine over accounting events**: a source system raises an event, the engine
applies a versioned rule set, journals fall out, and the rules are configuration data rather than
deployed logic. The components are named in the ecosystem as Accounting Methods, Journal Line Rules,
Account Rules, Mapping Sets, Description Rules and Supporting References. `[COMMUNITY]`
<https://hackernoon.com/configuring-subledger-accounting-sla-rules-in-oracle-fusion-cloud-financials>

**What we could not verify — stated plainly.**

- The physical storage of journals and balances. `[UNKNOWN]`
- Whether the Create Accounting engine is set-based SQL, PL/SQL, or Java. `[UNKNOWN]`
- How multiple accounting representations (multi-GAAP) of the same event are stored. `[UNKNOWN]`
- Any integrity mechanism — constraints, triggers, immutability. `[UNKNOWN]`
- The tenancy model. `[UNKNOWN]`
- Performance characteristics of the rules engine at volume. `[UNKNOWN]`
- The Accounting Flexfield / chart-of-accounts segment structure in Fusion specifically (as opposed to
  E-Business Suite folklore). `[UNKNOWN]` — a search was attempted and not completed within budget.

**Why the section stops here.** Everything further that could be written about Fusion's internals would be
recollection or inference dressed as fact. The one transferable idea — *posting rules as versioned data
over a stream of accounting events, with the rule set as the auditable artefact* — is recorded in
[`BEST_PRACTICES.md BP-09`](BEST_PRACTICES.md) and does not require knowing the schema.

---

## 2.6 Infor CloudSuite — `[UNKNOWN]`

**What can be said with confidence:** Infor CloudSuite is a family of industry-specialised ERP products
(healthcare, distribution, manufacturing, public sector) delivered on AWS, assembled substantially from
acquired product lines (Lawson, Baan, SyteLine and others) rather than built as one system.

**What could not be determined, and is therefore not asserted:**

- The accounting engine's architecture. `[UNKNOWN]`
- Journal/ledger storage. `[UNKNOWN]`
- Whether the acquired product lines share a financial core at all, or merely a brand and a UI shell.
  `[UNKNOWN]` — this is the question that would matter most, and there is no public answer.
- Dimensional model, posting mechanism, integrity approach, tenancy, extensibility. `[UNKNOWN]`

**Assessment.** No published architectural material of research value was found. **This section is short
because the evidence is absent, and a longer section would be invention.** Infor's relevance to QAYD is
commercial, not architectural: it is a reminder that ERP suites are frequently portfolios rather than
systems, and that "one platform" is often a marketing layer over several ledgers. That observation is
worth exactly one sentence, which it has now had.

---

## 2.7 Epicor — `[UNKNOWN]`

**What can be said with confidence:** Epicor is a manufacturing- and distribution-focused ERP vendor whose
flagship (Kinetic) is a .NET application with a REST API and a low-code customisation layer, available
both on-premises and cloud-hosted.

**What could not be determined:**

- Accounting engine, posting, ledger storage, dimensional model, integrity, tenancy. `[UNKNOWN]`
- Whether cloud Epicor is genuinely multi-tenant or single-tenant hosting. `[UNKNOWN]` — an important
  distinction that vendors routinely blur, and one we will not resolve by guessing.

**Assessment.** Same as Infor. No architectural evidence of research value. **Not padded.**

---

# Part 3 — Cross-platform comparison

## 3.1 Journal line: sign convention

| System | Representation | Enforced by | Evidence |
|---|---|---|---|
| **QAYD** | Single `signed_base_amount` | DB CHECK + triggers | existing |
| Tryton | Two columns, `debit` + `credit` | DB CHECK `credit * debit = 0` | `[CODE] move.py:1087` |
| OFBiz | `amount` unsigned + `debitCreditFlag CHAR(1)` | Nothing | `[CODE] accounting-entitymodel.xml:1869` |
| Sage Intacct | `AMOUNT` unsigned + `TR_TYPE` ∈ {1, −1} | `[UNKNOWN]` | `[DOCS]` |
| Acumatica | `[UNKNOWN]` | `[UNKNOWN]` | — |

**Reading.** Three of four systems separate magnitude from direction; QAYD does not. QAYD's single signed
column is the minority position and, we think, the right one — `SUM()` is the operation the ledger
performs constantly, and a signed column makes it free while a flag makes it a `CASE`. But it is a
minority position, and this table is why it deserves to be defended rather than assumed.

## 3.2 Balance enforcement

| System | Mechanism | Tolerance | Evidence |
|---|---|---|---|
| **QAYD** | DB CHECK + `PostingService` | exact, `NUMERIC(19,4)` | existing |
| Tryton | SQL aggregate in `post()` | `company.currency.rounding` — currency-aware | `[CODE] move.py:479-493` |
| OFBiz | minilang service via SECA | **hard-coded `0.01`** | `[CODE] AcctgTransServices.xml:181+` |
| Sage Intacct | Stated requirement of ≥2 balanced entries | `[UNKNOWN]` | `[DOCS]` |

**Reading.** OFBiz's hard-coded epsilon is a genuine bug for three-decimal currencies, which is to say
**for QAYD's entire home market**. It is the clearest example in this research of a defect that only
appears when you leave the author's assumed jurisdiction.

## 3.3 Dimensional model — the central comparison

| System | Shape | Extensible by customer | % splits | Split stored as | Evidence |
|---|---|---|---|---|---|
| **QAYD (AD-11)** | Rows in `journal_line_dimensions` | Yes, one INSERT | Yes | **percentage** | existing |
| Odoo | JSONB `{account: pct}` + materialised rows | Yes | Yes | both, synced | Phase 1 |
| **Tryton** | Axis = root of an account tree; allocation = money-carrying row | Yes, new root | Yes, via named `distribution` account | **amount** | `[CODE]` |
| **Sage Intacct** | 13 named fields on `GLENTRY` + `gldim*` extension | Yes, paid | Yes, via named `ALLOCATIONID` | **amount** (`SPLIT.AMOUNT`) | `[DOCS]` |
| OFBiz | 10 fixed nullable FK columns | **No** | **No** | — | `[CODE]` |
| Acumatica | Segmented string subaccount | Segments, at setup | **No** | — | `[COMMUNITY]` |

**Reading — and this is the most important paragraph in the document.** Two systems built the dimensional
model that practitioners actually praise (Intacct commercially, Tryton architecturally), independently,
without contact, and they agree on two things QAYD's AD-11 currently does differently:

1. **The allocation rule is a named, reusable, first-class object** — Intacct's `ALLOCATIONID`, Tryton's
   `type='distribution'` account — not an anonymous percentage payload attached to one line.
2. **What gets stored on the line is resolved money, not a percentage.** Intacct's `SPLIT` carries
   `AMOUNT`; Tryton's `analytic_account.line` carries `debit`/`credit`.

AD-11's core choice — rows, not columns, not JSONB — is **confirmed** by this evidence, including by the
fixed-column system (OFBiz) that demonstrates the failure mode. What is challenged is the *contents* of
the row. See [`ARCHITECTURE.md §4`](ARCHITECTURE.md) and
[`IMPLEMENTATION_RECOMMENDATIONS.md R-01, R-02`](IMPLEMENTATION_RECOMMENDATIONS.md).

## 3.4 Integrity: where invariants live

| System | Tenant isolation | Immutability | CHECK constraints | Evidence |
|---|---|---|---|---|
| **QAYD** | PostgreSQL RLS | DB triggers | Yes | existing |
| Tryton | N/A (DB per customer) | Python hook | 5, in the whole account+analytic modules | `[CODE]` |
| OFBiz | DB per tenant | `isPosted` flag, app-checked | **Zero — engine cannot emit them** | `[CODE]` |
| Acumatica | `CompanyID` predicate, app-injected | `[UNKNOWN]` | `[UNKNOWN]` | `[COMMUNITY]` |
| Sage Intacct | `[UNKNOWN]` | `[UNKNOWN]` | `[UNKNOWN]` | — |

**Reading. This is where QAYD is genuinely ahead of every system in this study, and it is not close.**
None of the five puts a financial invariant in the database where it cannot be bypassed. Two of them
(OFBiz, Acumatica) have architectures that structurally *prevent* doing so — OFBiz because its DDL
generator has no CHECK support, Acumatica because tenancy lives in the query builder. That is a moat, and
AD-01/AD-04/AD-07/AD-21 are the reason it exists.

## 3.5 Codebase scale

| System | Core LOC | Accounting LOC | Note | Evidence |
|---|---|---|---|---|
| Tryton | 76,939 (Python, `trytond`) | 14,309 | 217 modules | `[CODE]` |
| OFBiz | — | 4,041 lines XML model + 3,382 minilang + 89 Groovy/Java files | 733 entities total | `[CODE]` |
| Odoo | — | — | our *notes* alone ran 14,150 lines | Phase 1 |

**Reading.** Tryton's entire general ledger is smaller than our description of Odoo's. Feature parity is
not what makes ERP code large; accumulated compatibility is. QAYD's advantage over Odoo is that it is
young, and the way to keep that advantage is to stay closer to Tryton's size discipline than to Odoo's.

---

# Part 4 — Where each system beats QAYD today

Stated plainly, in the register `06_COMPETITIVE_ANALYSIS.md` established.

| System | Beats QAYD at | Why it matters |
|---|---|---|
| **Tryton** | Accounting breadth (60 finance modules vs our core); period workflow maturity; analytic modelling — it has shipped the design we are still debating | We are behind on *features*, not on *foundations* |
| **OFBiz** | Conceptual data modelling; temporal associations; a declarative, greppable, diffable schema | Our party/counterparty and effective-dating models are thinner than they should be |
| **Sage Intacct** | Dimensional UX — hierarchies, cross-dimension autofill, reusable allocations, dynamic allocations that emit auditable journals | Our dimension story is storage-correct and ergonomically empty |
| **Acumatica** | Customisation without forking; typed, compile-checked queries | Our extension seams (AD-13) are named but not yet mechanised |
| **Oracle Fusion** | Posting as a versioned rules engine over accounting events | Our posting is one good service; theirs is configurable per jurisdiction and per source |
| **Infor / Epicor** | Nothing we can verify | — |

**And where QAYD is ahead of all seven:** database-enforced tenant isolation, database-enforced
immutability, database-enforced balance, a single writer into an append-only ledger, hash-chained audit,
and money at `NUMERIC(19,4)` with three-decimal currencies as a first-class case rather than an
afterthought. Not one of the seven has more than two of those.
