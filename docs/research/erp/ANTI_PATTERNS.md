# Anti-Patterns — what these seven systems got wrong

**Each entry names the pattern, shows it in a real system with evidence, and explains the *mechanism* of
harm** — not the aesthetic objection. A pattern belongs here only if we can describe how it produces a
wrong number, a lost invariant, or an unpayable maintenance debt.

This complements [`04_REJECTED_PATTERNS.md`](../../architecture/knowledge/04_REJECTED_PATTERNS.md)
(R-01…R-34), which covers the rejections derived from Phase 1 and 2. Where an anti-pattern here matches an
existing rejection, it is noted — **new evidence for an existing rejection is worth recording**, because a
rejection supported by three independent systems is much harder to argue away than one supported by one.

---

## AP-01 — Reimplementing the database inside the application

**The pattern.** Build your own type system, your own DDL generator, your own dialect abstraction, and
your own referential-integrity policy on top of SQL — then use the RDBMS as a dumb row store.

**Where.** Apache OFBiz's entity engine. Entities are declared in XML, typed against an abstract
vocabulary, and mapped to concrete SQL types by **twelve** per-dialect files — Postgres, Oracle, MySQL,
MSSQL, Firebird, H2, HSQL, Sybase, Daffodil, Advantage, Axion, SapDB. `[CODE] framework/entity/fieldtype/`
Datasources carry `check-on-start="true"` and `add-missing-on-start="true"`, so the engine reconciles the
live schema against the XML at boot. `[CODE] framework/entity/config/entityengine.xml:132-133`

**The mechanism of harm — three distinct failures, all mechanical:**

1. **You inherit the intersection of twelve databases, not the union.** The DDL generator emits
   `PRIMARY KEY`, `FOREIGN KEY`, `NOT NULL` and `UNIQUE`. A keyword scan of all 3,383 lines of
   `DatabaseUtil.java` finds three `FOREIGN KEY`, four `NOT NULL`, seven `PRIMARY KEY`, two `UNIQUE` and
   **zero `CHECK`**. `[CODE] framework/entity/src/main/java/org/apache/ofbiz/entity/jdbc/DatabaseUtil.java`
   **OFBiz cannot express a CHECK constraint.** Not "does not"; *cannot*. Every domain invariant it might
   have pushed into the database — a non-negative quantity, a valid status, a debit-XOR-credit rule — is
   application code by force.
2. **Money precision is frozen at the abstraction's guess.** `currency-amount` is `NUMERIC(18,2)` on
   PostgreSQL. `[CODE] framework/entity/fieldtype/fieldtypepostgres.xml:33` One decision, made once, for
   every currency and every use — no room for the four decimals a multiplied unit price needs, and no
   room for a three-decimal currency's sub-fils behaviour.
3. **Booleans become characters.** `indicator` is `CHAR(1)`.
   `[CODE] fieldtypepostgres.xml:44` So `isPosted` is `'Y'`/`'N'`/`NULL` — a three-valued field for a
   two-valued concept, where every read is a string comparison and no constraint prevents `'X'`.

**Why it persists.** Portability across twelve databases was a genuine 2001 requirement, and the
abstraction delivered it. The cost only compounds later, invisibly, as the RDBMS gains features the
abstraction can never expose.

**QAYD's inverse position.** AD-01, AD-04, AD-07, AD-21 and the "PostgreSQL-first integrity" rule are the
direct opposite: the database is the enforcement layer, not the storage layer, and PostgreSQL-specific
features are a deliberate advantage. **OFBiz is the strongest available evidence for why that is right**,
because it is not a failed project — it is a mature, successful one that permanently forfeited its
integrity layer in exchange for portability it no longer needs.

**Relates to:** the PostgreSQL-first principle in `01_ENGINEERING_PRINCIPLES.md`.

---

## AP-02 — Declaring a relationship and then explicitly declining to enforce it

**The pattern.** Document a foreign-key relationship in the model, then mark it as one the database should
not enforce.

**Where.** OFBiz's `type="one-nofk"` relation. There are **434 of them across the datamodel entity files,
64 of which are in accounting alone.** `[CODE] applications/datamodel/entitydef/*.xml` The one that
matters most: `AcctgTransEntry`'s relation to `PartyRole` on `(partyId, roleTypeId)` is `one-nofk`, as is
`GlAccountOrganization`'s relation to `RoleType` and `PartyRole`.
`[CODE] accounting-entitymodel.xml:1869+`

**The mechanism of harm.** A declared-but-unenforced relation is strictly worse than an undeclared one,
because tooling, documentation and developers all treat it as real:

- Query builders join across it and assume the row exists.
- Reports assume the join is inner-safe and silently drop or duplicate rows when it is not.
- New developers read the model, see the relation, and write code that dereferences without a null check.
- The database will happily accept an `AcctgTransEntry` naming a `roleTypeId` that does not exist, and
  nothing anywhere will notice until a report is wrong.

**The tell that it is not benign.** These are concentrated exactly where the model is most polymorphic —
party/role, status, type. That is, they appear precisely where the Universal Data Model's expressiveness
exceeds what a foreign key can express, and the resolution was to drop the key rather than to narrow the
model. **Expressiveness was bought with integrity, silently, 434 times.**

**QAYD's position.** AD-11's composite `(member_id, dimension_id)` foreign key is the deliberate opposite —
"this member belongs to the declared dimension" is a database guarantee. Keep it, and treat any proposal
for a "logical only" relationship as this anti-pattern.

---

## AP-03 — Writing the most critical financial logic in a deprecated DSL

**The pattern.** Implement the invariant the system exists to protect in a bespoke scripting language that
the project has since abandoned.

**Where.** OFBiz's posting service. `postAcctgTrans` — the routine that decides whether a transaction may
enter the general ledger — is a `<simple-method>` in **minilang**, an XML scripting DSL, at
`applications/accounting/minilang/ledger/AcctgTransServices.xml:181`. `[CODE] ` The ledger minilang files
total 3,382 lines. `[CODE]` The project's own direction has been to move to Groovy: `applications/accounting/src`
contains 71 Groovy files against 18 Java files, while the financial core stayed behind. `[CODE]`

**The mechanism of harm.**

1. **No type checking, no debugger, no IDE, no static analysis.** A control-flow bug in
   `<if-compare field="..." operator="greater-equals" value="0.01" type="BigDecimal">` is found by running
   it, in a language nobody is fluent in any more.
2. **It cannot be unit-tested the way the rest of the system is.** So the most consequential code has the
   weakest test story.
3. **The maintainer pool shrinks to zero.** Contributors who know Java or Groovy cannot safely change the
   ledger, so the ledger stops changing — including when it needs to.

**The compounding failure this produced.** Inside that minilang sits the hard-coded `0.01` balance
tolerance (see AP-04). **The two anti-patterns are causally linked**: the tolerance is wrong partly
because the language made it painful to look up currency metadata, and it stays wrong because nobody wants
to edit minilang.

**QAYD's position.** "Logic only in Actions" is the standing rule and it is right. The transferable
lesson is sharper than "use one language": **the code that enforces the system's central invariant should
be the code with the best tooling, the most tests and the most readers — not the code with a special
regime.** If `PostingService` is ever the thing that gets a DSL, that is the moment to stop.

**Relates to:** `01_ENGINEERING_PRINCIPLES.md` (logic in Actions).

---

## AP-04 — A hard-coded rounding tolerance

**The pattern.** Compare a monetary difference against a literal instead of against the currency's own
precision.

**Where.** OFBiz `postAcctgTrans` rejects a transaction when `trialBalanceResultMap.debitCreditDifference`
is `>= 0.01` or `<= -0.01`, with `0.01` written as a literal in the XML.
`[CODE] applications/accounting/minilang/ledger/AcctgTransServices.xml:181+`

**The mechanism of harm, spelled out because this is the single most QAYD-relevant defect found:**

- KWD, BHD and OMR carry **three** decimal places. The smallest meaningful unit is 0.001.
- OFBiz accepts any imbalance strictly below 0.01. **A journal off by 0.009 KWD posts successfully.**
- That is nine times the smallest representable unit of the currency — not a rounding artefact, a real
  error — and it accumulates without bound across transactions, silently.
- The trial balance still "balances" to the tolerance, so nothing downstream flags it.
- **It is undetectable in the vendor's own testing**, because in USD or EUR the tolerance is genuinely
  sub-unit and the check behaves correctly.

**Why this is the most instructive bug in the study.** It is not carelessness. It is a *correct decision
made inside an unstated assumption* — "money has two decimals" — that fails only outside the author's
jurisdiction. QAYD's market is exactly that outside.

**QAYD's position.** Money is `NUMERIC(19,4)` as bcmath strings, and balance is exact rather than
tolerant. Correct. What is missing is the *regression test that proves it*: a KWD journal off by 0.0005
must be rejected, and a three-decimal currency must be in the standard test matrix. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-06`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## AP-05 — Tenant isolation living in the query builder

**The pattern.** Multi-tenancy is a `WHERE company_id = ?` predicate that the framework promises to add.

**Where.** Acumatica: tenant-scoped tables carry a **`CompanyID`** column, and the framework injects the
predicate. A second column, **`CompanyMask`**, is "a special column that stores binary mask that defines
where this record should be visible and where you can update this record". Tables without `CompanyID` are
globally shared. `[COMMUNITY]`
<https://asiablog.acumatica.com/index.php/2015/10/company-mask-for-data-sharing-between-tenats-in-acumatica/>,
<https://community.acumatica.com/everything-else-119/same-database-for-several-tenants-11984>

Tryton's `ir.rule` is the same class of mechanism for record-level access: a PYSON `domain` decoded per
request and injected into the `WHERE` clause by `ModelSQL`.
`[CODE] trytond/trytond/ir/rule.py:162-176`, `trytond/trytond/model/modelsql.py:1193,1771`

**The mechanism of harm.** The guarantee holds only on paths that go through the injector, and every real
system grows paths that do not:

- Reporting and BI tools connecting directly.
- Data migrations and backfills.
- Support engineers with a psql prompt.
- Background jobs constructed before the tenant context is established.
- A single raw query written under deadline.
- Any bug in the injector itself — and it is now a single point of catastrophic failure.

The failure mode is the worst available: **silent cross-tenant disclosure**, discovered by a customer.

**The `CompanyMask` refinement makes it worse, not better.** A binary bitmask encoding cross-tenant
visibility is bit arithmetic standing between two customers' financial data. It is clever, it works, and
one off-by-one shift is a breach.

**Being fair to Acumatica:** this runs in production at scale and evidently works. It is not a naive
design. **It is a design whose correctness is maintained by permanent discipline** — which is precisely
what AD-01 refuses to depend on. Acumatica is the proof that the discipline is sustainable by a large,
focused engineering organisation, which is a different bet from the one QAYD can afford to make.

**QAYD's position.** AD-01 and AD-02 — PostgreSQL RLS, one database, one schema. The database refuses the
row regardless of the path. **This remains QAYD's single clearest architectural advantage over every
system in this study**, and this evidence should raise confidence in it, not lower it.

**Relates to:** AD-01, AD-02.

---

## AP-06 — Dimensions as a segmented string key

**The pattern.** Encode analytical structure into positional segments of a fixed-width string, and require
valid combinations to be enumerated in advance.

**Where.** Acumatica's Subaccounts. The GL structure is defined on a Segmented Keys screen; the subaccount
is split into segments (default 6 characters as two 3-character segments; maximum 30 characters); segments
can be validated against value lists; and **the system only allows validated combinations, so valid
combinations must be created before transactions can use them** on the Subaccounts screen. `[COMMUNITY]`
<https://www.augforums.com/acumatica-general-ledger-structure/>,
<https://openuni.acumatica.com/wp-content/uploads/2017/08/F100_FinBasic_2020R2.pdf>

**The mechanism of harm — four separate failures:**

1. **Combinatorial explosion.** 20 departments × 15 projects × 10 regions is 3,000 rows someone must
   create and maintain, of which perhaps 200 are ever used. The maintenance burden is the *product* of the
   dimensions, not the sum.
2. **A hard, arbitrary ceiling.** 30 characters is the budget for all analytical structure the business
   will ever need. Exhausting it forces re-encoding, which invalidates history.
3. **Meaning lives in string positions.** "Characters 4–6 are the project" is a fact enforced by
   documentation. Reporting parses substrings; a segment length change breaks every report simultaneously.
4. **No percentage allocation is possible.** A string has one value. Splitting a line across two projects
   requires splitting the line — which, as AD-11 already notes, corrupts the line-to-source-document
   relationship.

**Why it survives.** It is the 1980s general-ledger model, and it survives because it makes the account
code human-readable and the reports trivially sortable. Those are real benefits, and they are the reason
practitioners defend it. They are not worth the four costs above.

**The instructive contrast.** Intacct and Acumatica are direct competitors in the same market segment,
with opposite models. Intacct is the one with the reputation for analytical power, and dimensions are the
reason. **This pair is the cleanest available demonstration that dimensions-as-attributes beat
dimensions-as-segments**, and it is worth citing whenever someone proposes a "smart account code" for
QAYD.

**QAYD's position.** AD-11 rejects this by construction. Record the concrete failure modes so the
rejection has teeth.

**Relates to:** AD-11.

---

## AP-07 — Fixed dimension columns chosen once by the vendor

**The pattern.** Put a nullable FK column on the journal line for each dimension the vendor imagines
customers will need.

**Where.** OFBiz's `AcctgTransEntry`, with ten of them: `partyId`, `roleTypeId`, `theirPartyId`,
`productId`, `inventoryItemId`, `glAccountTypeId`, `organizationPartyId`, `taxId`, `groupId`,
`settlementTermId`. `[CODE] applications/datamodel/entitydef/accounting-entitymodel.xml:1869-1893`

**The mechanism of harm.** This is the alternative AD-11 rejected, observed at maturity, and every
predicted consequence is present:

1. **The set is frozen at the vendor's guess.** A customer needing "vessel", "grant", "fund" or "branch"
   cannot have one. There is no customer-facing extension point on this table.
2. **Adding one is a migration on the largest table in the system**, shipped to every customer whether
   they want the column or not.
3. **No percentage split is possible.** One column, one value.
4. **The table is sparse.** Ten nullable columns, most null on most rows, on the highest-volume table.
5. **It leaks the vendor's domain assumptions into everyone's ledger.** `settlementTermId` and
   `theirProductId` are on every journal line of every OFBiz installation, including those with no
   settlement terms and no external product mapping.

**The honest counter-argument, because it deserves one.** Fixed columns are fast, simple, indexable, and
join-free. Sage Intacct — the most-praised dimensional product — uses named fields for its standard set.
`[DOCS]` **The distinction that matters is not columns-versus-rows; it is whether the customer can add a
dimension without a migration.** Intacct can (user-defined dimensions). OFBiz cannot. That is the whole
difference, and it is the difference AD-11 correctly identified.

**QAYD's position.** AD-11 is confirmed. The specific residual risk AD-11 names — "a
`journal_lines.cost_center_id` column being added *for convenience* by someone who has not read this" — is
the exact path that produced `AcctgTransEntry`. Ten times.

**Relates to:** AD-11, TD-14.

---

## AP-08 — Optional integrity

**The pattern.** Ship the mechanism that guarantees correctness as a per-model flag that defaults to off.

**Where.** Tryton's history/audit facility. Setting `_history = True` on a model makes the ORM maintain a
parallel `<table>__history` table. `[CODE] trytond/trytond/model/modelsql.py:322,476-479` It defaults to
`False`, and it is not enabled for `account.move`.

**The mechanism of harm.**

1. **The default is what almost everyone runs.** An audit trail that must be switched on is absent in most
   deployments, and absent exactly where nobody thought to switch it on — which correlates with where
   something later goes wrong.
2. **It cannot be enabled retroactively with any value.** Turning it on in year three gives you year three
   onward. The years you need are gone.
3. **It creates a false sense of coverage.** "Tryton has history tables" is true and nearly useless as a
   compliance answer.

**The same disease elsewhere in the same system.** Tryton's immutability of posted moves is a Python hook
raising `AccessError` `[CODE] modules/account/move.py:290-301` — an invariant that holds only for writers
who go through the ORM. It is not optional by a flag; it is optional by choosing a different code path,
which is the same property.

**QAYD's position.** AD-17 (hash-chained, externally anchored audit) and AD-21 ("no invariant has an off
switch, and there is no ambient privilege bypass) are the direct answers, and this is good independent
evidence that AD-21 is load-bearing rather than decorative. **The thing to watch for is the QAYD version
of the same slip:** a config key, a feature flag, or an `if (config('audit.enabled'))` that makes an
invariant conditional. AD-21 forbids it; this is what it looks like when it happens.

**Relates to:** AD-17, AD-21.

---

## AP-09 — Posting triggered as a side effect of a commit hook

**The pattern.** The general ledger is written by a declarative rule that fires when some other service
commits, rather than by an explicit call from a known caller.

**Where.** OFBiz. `postAcctgTrans` is invoked by an SECA on the *commit* event of
`createAcctgTransAndEntries`, guarded only by `<condition field-name="acctgTransId" operator="is-not-empty"/>`.
`[CODE] applications/accounting/servicedef/secas_ledger.xml:25-28` The same file wires roughly a dozen
further business services to accounting services the same way — shipment issuance, inventory receipt, work
effort, payment application.

**The mechanism of harm.**

1. **There is no call graph.** "What writes to the ledger?" is answered by grepping XML rule files, not by
   finding callers. Static analysis cannot see it.
2. **Ordering and re-entrancy are implicit.** ECAs firing services that commit and fire further ECAs
   produce an execution order that exists only at runtime.
3. **Failure semantics are murky.** A posting failure inside a commit hook of a business transaction sits
   in an ambiguous place: has the business transaction happened, and has the accounting?
4. **The invariant "exactly one writer" becomes unverifiable.** Any module can add an ECA. Nothing
   structurally prevents a second path into the ledger.

**The genuinely good idea inside it, which should not be discarded.** *Decoupling* accounting from business
documents is right — the shipment module should not know about debits. Oracle's SLA makes the same
separation more rigorously (BP-09). **The error is not the decoupling; it is that the decoupling was
implemented as an untyped, unordered, unbounded set of hooks with no single entry point.**

**QAYD's position.** AD-04 (exactly one writer into the ledger) and AD-14 (after-commit domain events are
the only cross-module path) are correct and are the antidote *together*: events decouple, and the single
writer keeps the invariant verifiable. **The specific thing to hold onto is that AD-14's events must never
become the mechanism by which a second writer appears.** An event handler that posts is fine only if it
posts *through* `PostingService`.

**Relates to:** AD-04, AD-14.

---

## AP-10 — Runtime DDL

**The pattern.** The application alters the database schema while running, based on configuration or
model definitions.

**Where.** OFBiz: `check-on-start="true"` and `add-missing-on-start="true"` on datasources means the engine
compares the XML entity model to the live database at boot and adds what is missing.
`[CODE] framework/entity/config/entityengine.xml:132-133` Tryton is milder but related: `__register__`
performs schema reconciliation through a table handler, including column renames — `account.move`'s own
`__register__` drops a `number` column and renames `post_number` to `number` if it finds both.
`[CODE] modules/account/move.py:161-168`

(Phase 1 recorded the most acute version of this: Odoo executing `ALTER TABLE` / `CREATE INDEX` at runtime
when an analytic plan is created or renamed. Three systems, three variants of the same idea.)

**The mechanism of harm.**

1. **Schema state becomes a function of boot order and code version, not of a migration history.** There
   is no artefact recording what the schema *should* be at a given release.
2. **Rollback is undefined.** Deploying the previous version does not remove what the new version added.
3. **In multi-instance deployments, several processes race to alter the same table.**
4. **The DDL runs with whatever privileges the application holds** — so the application must hold DDL
   privileges permanently, which is a standing security exposure for the sake of a boot-time convenience.

**The nuance worth keeping.** Reconciling declared schema against actual schema is a genuinely useful
*diagnostic*. The error is doing it in `--fix` mode automatically at boot in production.

**QAYD's position.** Laravel migrations plus PostgreSQL-first design already refuse this. The transferable
idea is the diagnostic half: a CI check that the migration-produced schema matches a committed expected
schema, run in the pipeline, never in production, and with no privilege to change anything. See
[`IMPLEMENTATION_RECOMMENDATIONS.md R-08`](IMPLEMENTATION_RECOMMENDATIONS.md).

---

## AP-11 — Making the type system carry the whole ledger's semantics

**The pattern.** Rather than modelling a concept, add a `*Type` reference entity and a `typeId` column,
and let configuration data carry the meaning.

**Where.** OFBiz, at scale: **31 `*Type` entities in the accounting model alone**, plus `StatusItem` for
statuses. `[CODE] accounting-entitymodel.xml` `AcctgTrans` carries `acctgTransTypeId`, `glFiscalTypeId`,
`groupStatusId` and `roleTypeId`; `AcctgTransEntry` carries `acctgTransEntryTypeId`, `roleTypeId`,
`glAccountTypeId` and `reconcileStatusId`. `[CODE]`

**The mechanism of harm.**

1. **Behaviour becomes data without becoming a rule engine.** The code still branches on type ids —
   just on *string* type ids that no compiler or database can validate. A typo in a seed file produces a
   transaction of a type nothing handles.
2. **The valid set is unbounded.** Nothing prevents a 32nd type appearing in production data with no code
   path.
3. **Reporting must know the taxonomy.** Every report hard-codes which type ids mean what, so the
   taxonomy leaks into hundreds of places.
4. **It defers the modelling decision forever.** "Is a reversal a different type of transaction, or the
   same type with an origin link?" is never answered — both exist.

**Being fair.** This *is* the Universal Data Model pattern, and it is genuinely powerful — it is why the
OFBiz model absorbed twenty-five years of requirements without structural change (BP-10 and
[`ARCHITECTURE.md §3`](ARCHITECTURE.md) credit it properly). **The anti-pattern is not using type
entities. It is using them where a closed set with real semantics belongs.** A transaction is either
posted or not; that is not a `StatusItem`.

**QAYD's position.** AD-19 (lifecycle transitions are data, mirrored by a database trigger) is the
disciplined version: transitions are data, but the *set* is closed and the database enforces it. That
distinction — open taxonomy for classification, closed enumeration for lifecycle — is the line OFBiz does
not draw and QAYD does.

**Relates to:** AD-19.

---

## AP-12 — Sparse extension by column

**The pattern.** Extend a high-volume table with more nullable columns as new needs appear.

**Where.** `AcctgTransEntry`'s twenty-five fields, of which ten are dimension FKs and several are clearly
later additions with narrow purposes — `theirPartyId`, `theirProductId`, `settlementTermId`, `groupId`,
`isSummary`. `[CODE] accounting-entitymodel.xml:1869-1893` `AcctgTrans` shows the same growth: it carries
`fixedAssetId`, `inventoryItemId`, `physicalInventoryId`, `invoiceId`, `paymentId`, `finAccountTransId`,
`shipmentId`, `receiptId`, `workEffortId` and `theirAcctgTransId` — **ten separate nullable FK columns
that all mean "what caused this"**, one per source module. `[CODE] accounting-entitymodel.xml:1764+`

**The mechanism of harm.** The last point is the sharp one and generalises well beyond OFBiz. Ten columns
expressing one concept means:

- "What is the source of this transaction?" is a `COALESCE` across ten columns in every consumer.
- Adding an eleventh source module means altering the ledger's header table.
- Nothing prevents two of them being non-null simultaneously, and no constraint can express "exactly one
  of these ten".
- Every index decision must be made ten times.

**The alternative both other systems chose.** Tryton uses a single polymorphic `origin` `Reference` field
holding `model,id`. `[CODE] modules/account/move.py:119-120, 279-288` One column, one concept, extensible
by any module without touching the table — at the cost of no foreign key. QAYD's shape (a typed
`source_type` / `source_id` pair, or a dedicated link table) can have both.

**QAYD's position.** Not yet a problem — QAYD is young and has few source modules. **This is precisely the
anti-pattern that arrives by accretion rather than by decision**, which is why it is recorded now: the
first time someone proposes adding `invoice_id` to `journal_entries`, this entry is the argument.

---

## Summary table

| # | Anti-pattern | Seen in | Evidence | QAYD defence |
|---|---|---|---|---|
| AP-01 | Reimplementing the DB inside the app | OFBiz | `[CODE]` | PostgreSQL-first integrity |
| AP-02 | Declared but unenforced relationships (434 of them) | OFBiz | `[CODE]` | Composite FKs, AD-11 |
| AP-03 | Critical financial logic in a deprecated DSL | OFBiz | `[CODE]` | Logic in Actions |
| AP-04 | Hard-coded rounding tolerance (`0.01`) | OFBiz | `[CODE]` | `NUMERIC(19,4)`, exact balance |
| AP-05 | Tenant isolation in the query builder | Acumatica, Tryton | `[COMMUNITY]` `[CODE]` | AD-01, AD-02 (RLS) |
| AP-06 | Dimensions as a segmented string | Acumatica | `[COMMUNITY]` | AD-11 |
| AP-07 | Vendor-chosen fixed dimension columns | OFBiz | `[CODE]` | AD-11 |
| AP-08 | Optional integrity (audit off by default) | Tryton | `[CODE]` | AD-17, AD-21 |
| AP-09 | Posting fired from a commit hook | OFBiz | `[CODE]` | AD-04, AD-14 |
| AP-10 | Runtime DDL | OFBiz, Tryton, Odoo | `[CODE]` | Migrations only |
| AP-11 | Type entities carrying lifecycle semantics | OFBiz | `[CODE]` | AD-19 |
| AP-12 | Sparse extension by column (10 columns for one concept) | OFBiz | `[CODE]` | Not yet at risk — record now |
