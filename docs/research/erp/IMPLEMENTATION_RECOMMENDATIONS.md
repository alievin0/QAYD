# Implementation Recommendations

**What to do, in what order, and what it costs.** Every recommendation carries the full assessment:
why · benefits · tradeoffs · risks · scalability · performance · maintainability · complexity · effort
(Fibonacci) · business impact · confidence · evidence.

**These are recommendations, not decisions.** This folder cannot decide anything (see
[`README.md`](README.md) and `docs/architecture/knowledge/README.md` on precedence). R-01 and R-02 touch
AD-11, which is already flagged as requiring a formal ADR before TD-14 is implemented — **they must go
into that ADR, not around it.**

---

## Priority summary

| # | Recommendation | Effort | Impact | Do it |
|---|---|---|---|---|
| **R-01** | Store resolved money on the allocation row | **3** | **High** | **Before any allocation data exists** |
| **R-02** | Allocation rules as named first-class objects | **8** | High | Same ADR as R-01 |
| **R-03** | Dimension ergonomics + AI suggestion loop | **21** | **Highest** | Next major product bet |
| R-04 | Record counterparty capacity, not just identity | 5 | Medium-High | Before counterparty data grows |
| R-05 | Temporal classification relationships | 5 | Medium | With the dimension schema |
| **R-06** | Three-decimal currency regression suite | **2** | **High** | **This sprint** |
| R-07 | Build-time schema/report contract check | 3 | Medium | When reports exceed ~10 |
| R-08 | Committed schema snapshot, diffed in CI | 3 | Medium | Anytime — cheap |
| R-09 | Axis assignment immutability once allocated | 2 | Medium | With the dimension schema |
| R-10 | Derived dimensional-completeness state + worklist | 5 | Medium-High | After R-01/R-02 |
| R-11 | Distinct types for deliberately expensive paths | 3 | Medium | Opportunistic |
| R-12 | Keep the posting policy seam; scope rules to tax | 2 | Medium | Design-only, now |

**The one-line version:** do R-06 this sprint (two points, protects the home market), settle R-01 and R-02
in the AD-11 ADR before any allocation data exists, and treat R-03 as the strategic bet.

---

## R-01 — Store resolved money on the allocation row, not a percentage

**Recommendation.** `journal_line_dimensions` carries a **signed amount** in base currency as its
value-bearing column. Retain the ratio, if any, as provenance alongside it — never as the thing reporting
reads.

Shape: `(journal_line_id, dimension_id, member_id, signed_base_amount, allocation_rule_id?, ratio?)`.

### Why

Three unrelated systems agree. Tryton's `analytic_account.line` carries `debit`/`credit` and has no ratio
field `[CODE] modules/analytic_account/line.py:19-28`. Intacct's `SPLIT` carries `AMOUNT`, constrained so
all splits sum to the entry's `TRX_AMOUNT` `[DOCS]`. Odoo's materialised `account.analytic.line` carries
amounts. **QAYD's AD-11 is the only design in the comparison that stores a percentage as the durable
value.**

The mechanism is rounding. Tryton's `distribute()` allocates by ratio, tracks the remainder, then walks
the results adding one rounding unit at a time until the remainder is zero, and asserts
`sum(a for _, a in result) == amount` `[CODE] modules/analytic_account/account.py:279-302`. **60/40 of
0.05 KWD has exactly one correct answer.** With stored amounts, `PostingService` decides it once and
records it. With stored percentages, it is decided by whichever report runs, with whatever rounding logic
is deployed that day.

### Benefits

- Dimensional reporting becomes `SUM(signed_base_amount) GROUP BY member_id` — no multiplication, no
  re-rounding, no possibility of a report disagreeing with the ledger.
- The allocation total is checkable against the parent line by a plain aggregate, which is a far simpler
  constraint than "these percentages sum to 100".
- `journal_line_dimensions` becomes **independently aggregable**: a dimensional report never joins
  `journal_lines`. This is exactly what makes AD-11's planned `(company_id, period)` partitioning pay off.
- History is immune to changes in rounding policy.

### Tradeoffs

- The row is no longer self-describing: seeing "60%" requires the rule (which is why R-02 pairs with this).
- Amounts must be recomputed if a source line's amount changes — **moot under AD-07**, since posted lines
  are immutable and a correction is a new entry with new allocations.
- Slightly wider rows.

### Risks

- **Someone stores both and lets them disagree.** Mitigate by making `ratio` explicitly non-authoritative
  in the column comment and by never reading it in a report.
- **Rounding logic becomes a single point of correctness.** It already is; this makes it visible. It needs
  property-based tests: for any amount and any ratio set summing to 1, the allocated amounts must sum
  exactly to the amount.

### Scalability

Neutral to positive. Row count is unchanged. Query cost drops — the dominant dimensional query loses a
join and a multiplication.

### Performance

Better. `SUM` on an indexed `NUMERIC` column versus `SUM(amount * ratio)` across a join. On the table
AD-11 already expects to be one of the largest in the database, this is the difference between an
index-only aggregate and a nested loop.

### Maintainability

Better. The hardest logic (remainder distribution) moves to one place, at write time, under the single
writer (AD-04), where it can be tested exhaustively.

### Complexity

**Lower than the alternative.** It removes the need to enforce "sums to 100%" per line as a deferred
constraint trigger, replacing it with "sums to the parent line amount" — an ordinary aggregate check, and
the same shape as the balance check the system already performs.

### Effort

**3.** Schema is unbuilt. This is a column-definition decision plus the rounding routine and its tests.

### Business impact

**High, and time-limited.** AD-11 already says the decision is free exactly once. This is the same window,
and it closes the moment the first allocation row is written.

### Confidence

**High.** Three independent implementations agree, and the mechanism (rounding decided once versus
recomputed forever) is arithmetic rather than opinion.

### Evidence

`[CODE] modules/analytic_account/line.py:19-28`, `account.py:279-302` ·
`[DOCS]` <https://developer.intacct.com/api/general-ledger/journal-entries/> · Phase 1 (Odoo
materialisation)

---

## R-02 — Make allocation rules named, first-class, reusable objects

**Recommendation.** Add `dimension_allocation_rules` (id, company, code, name, status) and
`dimension_allocation_rule_lines` (rule, dimension, member, ratio), with the sum-to-1 invariant validated
on the **rule**. Journal line allocations reference a rule where one applies; ad-hoc splits remain
possible.

### Why

Sage Intacct's Transaction Allocation is `ALLOCATIONID` + `STATUS` + `TYPE` + `ALLOCATIONENTRIES`, each
entry carrying `VALUE` and `VALUETYPE` ∈ {`Amount`, `Percent`}, with percentages that "must always total
100%" `[DOCS]`. Tryton's is an analytic account of `type='distribution'` owning `(account, ratio)`
children, validated to sum to exactly 1 when saved `[CODE] account.py:122-132, 331-348`. **No shared
lineage; identical conclusion.**

The mechanical benefit: **the 100% invariant is validated once per rule instead of once per line.** A
thousand lines referencing a rule inherit a guarantee established once. Per-line percentages make every
line a fresh opportunity to violate it — which is why Odoo needed a context-gated Python constraint that
production code then disables for exchange-difference moves.

It also answers a question AD-11 leaves open: how do you change an allocation policy? One row.

### Benefits

- One invariant check per rule, not per line.
- Policy changes are a single write; posted history correctly keeps its resolved amounts (R-01).
- "Why is 40% of this on Project B?" resolves to a named, versioned, status-bearing policy.
- "Show me everything allocated under policy X" is a join, not a scan.
- A natural home for approval: an allocation policy is exactly the kind of thing a controller signs off.

### Tradeoffs

- Indirection at data entry. Intacct provides the escape hatch of inline `SPLIT` elements `[DOCS]` and
  **QAYD must too** — a system offering only named rules gets worked around, and workarounds land in
  spreadsheets.
- A second configuration surface to build, document and secure.

### Risks

- **Rule proliferation** — hundreds of near-identical rules. Mitigate with a status field (Intacct has
  one) and a usage count.
- **Editing a rule that posted history references.** Under R-01 the history holds amounts, so it is safe;
  but the UI must say so, or users will believe an edit rewrote the past.

### Scalability

Positive. Rules are few and hot; they cache trivially. Applying a rule is a lookup, not a computation.

### Performance

Neutral at write time, better at analysis time (allocation-policy reporting becomes a join).

### Maintainability

Better. Allocation policy stops being distributed across millions of rows and becomes a small,
inspectable, diffable table.

### Complexity

Medium. Two tables, one validation, one application path, plus the ad-hoc escape hatch.

### Effort

**8.** Two tables, validation, application logic in `PostingService`, a configuration UI, and the ad-hoc
path.

### Business impact

High. This is a visible, demonstrable feature — "define your allocation policies once" is a sales
sentence. It also removes the strongest practical objection to using dimensions at all.

### Confidence

**High** on the design; **Medium** on the schema shape, which should be settled in the ADR alongside R-01.

### Evidence

`[DOCS]` <https://developer.intacct.com/api/general-ledger/transaction-allocations/> ·
`[CODE] modules/analytic_account/account.py:122-132, 331-348`

---

## R-03 — Build the dimensional ergonomics layer, with AI suggestion as the differentiator

**Recommendation.** Treat dimensional *usability* as a product workstream, not a schema concern. In order:
(a) hierarchical members with rollups; (b) dimension defaults per account and per counterparty;
(c) AI-suggested dimensions surfaced at entry with visible confidence and one-click accept; (d) named
allocation rules (R-02) exposed at entry; (e) a dimension-completeness worklist (R-10).

### Why

**This is the largest strategic finding in the research.** Sage Intacct is the market's reference for
dimensional accounting and its storage is unremarkable — named fields on `GLENTRY` `[DOCS]`. Its
reputation rests entirely on the layer above: hierarchical members with rollups, relationships between
dimensions that autofill (Customer → Location), named reusable allocations, and dynamic allocations that
emit auditable journal entries `[DOCS]`.

Tryton reaches for the same thing with `analytic.rule` — criteria that auto-populate missing analytic
lines on posted moves `[CODE] modules/analytic_account/rule.py`.

Dimensional accounting fails in practice because humans will not enter the data consistently. **A
dimension nobody fills in is worth nothing regardless of how elegantly it is stored.** QAYD's storage is
more correct than Intacct's and QAYD's dimensional product does not exist.

### Benefits

- Turns a correct schema into a usable capability.
- **The AI angle is a genuine differentiator against the category leader.** Intacct autofills from a
  static configured relationship; QAYD can *infer* from transaction context, description, counterparty
  and history. `dimension_suggestions.proposed_payload` already exists as the home for it (AD-11).
- Directly raises the completeness of dimensional data, which raises the value of every dimensional
  report — a compounding effect.
- It is demonstrable in a sales meeting, which storage correctness is not.

### Tradeoffs

- Significant product and UI work, not a database change.
- AI suggestions are inferred financial data and must be **visibly inferred, always overridable, and never
  silently posted**. That constraint is non-negotiable and it costs UI complexity.
- Requires the AI engine to be reliable enough to help rather than annoy. A suggestion accepted 40% of the
  time is worse than no suggestion.

### Risks

- **Autofill sets the wrong value and nobody notices.** Mitigate: suggestions require an explicit accept;
  accepted suggestions are marked as AI-originated in the audit trail (which AD-17's chain already
  supports); acceptance rate is monitored.
- **Scope creep into a general "AI bookkeeping" promise.** Keep it bounded: this is dimension suggestion
  on entry, nothing else.
- **Cost.** AI inference per line has a spend profile; the existing AI spend cap applies.

### Scalability

Suggestion is per-entry and cacheable by (counterparty, account, description-shape). Hierarchies need
recursive queries or a materialised closure table — a solved problem, and cheap at dimension-member
cardinality.

### Performance

Suggestion must be asynchronous or sub-200ms; it must never sit on the posting path. AD-15 already forbids
the AI engine holding a database credential, so this is an inference call against context supplied by
Laravel — the right shape already.

### Maintainability

Medium. The ergonomics layer sits above stable storage, so it can be iterated without schema churn. That
is precisely why the storage decisions (R-01, R-02) should be settled first.

### Complexity

**High** — the highest in this document. Several surfaces: hierarchy, defaults, suggestion, allocation
application, worklist.

### Effort

**21.** A workstream, not a story. Decomposes into roughly 5 (hierarchy) + 3 (defaults) + 8 (AI
suggestion loop) + 5 (entry UX).

### Business impact

**Highest in this document.** This is where QAYD competes with the best product in the category, on the
axis that category actually competes on, with a capability that category does not have.

### Confidence

**High** that the gap is real and correctly diagnosed. **Medium** on sequencing — this is a large bet and
should follow, not precede, the integrity core reaching completeness.

### Evidence

`[DOCS]` <https://www.intacct.com/ia/docs/en_US/help_action/Intacct_basics/Dimensions/basics-dimensions-overview.htm>,
<https://developer.intacct.com/api/general-ledger/transaction-allocations/> ·
`[CODE] modules/analytic_account/rule.py`, `doc/design.rst`

---

## R-04 — Record the counterparty's capacity, not just their identity

**Recommendation.** Where a journal line references a counterparty, record `(party_id, party_role_id)`
together, with a composite foreign key to a `party_roles` table guaranteeing the party actually holds that
role.

### Why

OFBiz records `(partyId, roleTypeId)` on both `AcctgTrans` and `AcctgTransEntry`
`[CODE] accounting-entitymodel.xml:1764+, 1869+`. Tryton records `party`; Odoo records `partner_id`.
Neither records capacity.

In GCC family-owned structures the same legal entity is routinely customer, supplier, lessor and
shareholder. Without capacity, related-party disclosure, intercompany elimination, and "what did we buy
from customers?" are reconstructed by inferring from the account used — which is guesswork that becomes
undiscoverable once the data is large.

### Benefits

- Related-party disclosure becomes a query.
- Intercompany elimination has the information it needs.
- Counterparty analysis stops depending on account-code conventions.
- Enforced by the same composite-FK pattern AD-11 already uses for `(member_id, dimension_id)` — one
  pattern, two uses.

### Tradeoffs

- One more required-ish field at entry. Should default from context (the source document already knows
  whether this is a purchase or a sale), never be a free choice.
- Party role management becomes a maintained surface.

### Risks

- **Over-modelling.** OFBiz has 31 `*Type` entities and this is the tradition that produced them
  (`ANTI_PATTERNS.md AP-11`). Keep the role set small and closed.
- **Adoption failure** if it is a manual field. It must be derived by default.

### Scalability

Negligible. One FK on the line, one small reference table.

### Performance

Negligible write cost; a composite index enables counterparty-by-capacity reporting cheaply.

### Maintainability

Good — provided the role vocabulary stays closed. An open taxonomy here becomes AP-11.

### Complexity

Low.

### Effort

**5.** Schema, defaulting logic, the composite FK, and backfill (trivial while data is small).

### Business impact

Medium-High in the GCC specifically. Related-party disclosure is a live audit requirement in the region,
and being able to answer it structurally is a credibility marker with auditors.

### Confidence

**Medium.** The idea is well-evidenced; the execution we observed weakened itself by making the
`PartyRole` relation `one-nofk` `[CODE]`, so we are recommending the concept with a stronger enforcement
than its source had.

### Evidence

`[CODE] applications/datamodel/entitydef/accounting-entitymodel.xml:1764+, 1869+`

---

## R-05 — Make classification relationships temporal

**Recommendation.** Relationships that reporting *slices by* — account↔category, member↔axis, entity↔group
— carry `valid_from` / `valid_to`, with `valid_from` in the primary key. **Nothing else.**

### Why

OFBiz puts `fromDate` inside the PK of `GlAccountCategoryMember`:
`(glAccountId, glAccountCategoryId, fromDate)`, and gives `GlAccountOrganization` an activation window
over `(glAccountId, organizationPartyId)` `[CODE] accounting-entitymodel.xml`. Tryton does a narrower
version: `account.account` has `start_date`/`end_date`, and the journal line's account domain filters on
them so a closed account cannot be used outside its window `[CODE] modules/account/move.py:926-934`.

Financial questions are *as-of* questions. "Which accounts were in Operating Expenses in FY2024?" is
unanswerable if membership is a mutable FK — and that is exactly the question asked after a
reorganisation, which is exactly when the FK was mutated.

### Benefits

- Comparative reporting across a reorganisation stays correct without an audit-log reconstruction.
- Restatements have the data they need.
- Removes a whole class of "the prior-year comparison changed and nobody knows why" incidents.

### Tradeoffs

- **Every join needs a date predicate, and every forgotten predicate is a duplicate row.** This is a real
  and permanent tax, which is why the recommendation is deliberately narrow.
- Ordinary queries get harder to write.

### Risks

- **Spreading.** OFBiz applies this to hundreds of entities and pays for it everywhere. The scope must be
  written into the ADR as a closed list, not a principle.
- **Forgotten predicates producing silently duplicated money in a report.** Mitigate with a helper scope
  (`->asOf($date)`) that is the only sanctioned access path, and a review rule that raw joins against
  these tables are flagged.

### Scalability

Small row-count increase. Indexes must lead with the temporal column for range queries.

### Performance

Slightly worse for point queries, dramatically better than the alternative (reconstruction from audit
logs) for as-of queries.

### Maintainability

Medium. The helper scope is essential; without it this decays.

### Complexity

Medium — the concept is simple, the discipline is not.

### Effort

**5**, if done alongside the dimension schema. Considerably more later.

### Business impact

Medium. Invisible until the first reorganisation, then decisive.

### Confidence

**Medium-High.** The pattern is proven; the risk is over-application, and the mitigation is a written
scope limit.

### Evidence

`[CODE] applications/datamodel/entitydef/accounting-entitymodel.xml` · `[CODE] modules/account/move.py:926-934`

---

## R-06 — Add a three-decimal-currency regression suite

**Recommendation.** Put KWD (3 decimals) in the standard test matrix beside a 2-decimal currency, and add
named regression tests: a KWD journal off by 0.0005 must be rejected; an allocation of 0.05 KWD split
60/40 must produce amounts summing exactly to 0.05; a KWD amount must never be rounded to 2 decimals
anywhere in the pipeline.

### Why

**This is the most QAYD-specific finding in the research.** OFBiz's `postAcctgTrans` rejects a transaction
only when `debitCreditDifference >= 0.01` or `<= -0.01` — a literal in XML
`[CODE] applications/accounting/minilang/ledger/AcctgTransServices.xml:181+` — and its `currency-amount`
type is `NUMERIC(18,2)` `[CODE] fieldtypepostgres.xml:33`. **In KWD, BHD and OMR, an imbalance of 0.009
posts successfully**, nine times the smallest unit of the currency, accumulating without bound, with the
trial balance still "balancing" to the tolerance.

Tryton gets it right by deriving the tolerance from `company.currency.digits` and `.rounding`
`[CODE] modules/account/move.py:479-493`.

This is not carelessness. It is a correct decision inside an unstated assumption — *money has two
decimals* — that fails only outside the author's jurisdiction, and is therefore **invisible to the
vendor's own testing**. QAYD's entire market is that outside.

### Benefits

- Proves the property QAYD already believes it has.
- Catches any future contributor's two-decimal assumption at CI rather than at a client's year-end.
- Directly protects the home market's correctness, which is the product's whole premise.

### Tradeoffs

None. This is test code.

### Risks

The only risk is not doing it. **Every ERP in this study encodes its author's jurisdiction somewhere;**
QAYD's advantage is that its author's jurisdiction is the one everyone else gets wrong, and that advantage
pays off only if it is tested rather than assumed.

### Scalability / Performance

N/A.

### Maintainability

Improves it — the assumption becomes executable rather than tribal.

### Complexity

Trivial.

### Effort

**2.**

### Business impact

**High relative to effort.** "We test three-decimal currencies as a first-class case" is a credible,
verifiable differentiator against every incumbent named in this research.

### Confidence

**High.** The defect is read directly in a mature production codebase.

### Evidence

`[CODE] AcctgTransServices.xml:181+`, `fieldtypepostgres.xml:33`, `modules/account/move.py:479-493`

---

## R-07 — Add a build-time schema/report contract check

**Recommendation.** A CI step that parses every report/statement definition and asserts that every column,
account role and dimension it names exists in the schema. Fail the build on a miss.

### Why

Acumatica's BQL expresses queries as types, and Acumatica's stated benefit is "compile-time syntax
validation, which helps to prevent SQL errors" `[DOCS]`. PHP cannot give QAYD that, but the *property*
that matters is narrower and portable: **report definitions are numerous, long-lived, and rarely all
exercised by tests**, so a renamed column breaks them silently and the break surfaces at a quarterly
close.

AD-16 already makes financial statements declarative data — which means they are parseable, which means
this check is cheap.

### Benefits

- A schema rename that breaks 40 report definitions fails at build, not at close.
- Cheap precisely because AD-16 already chose declarative statements.

### Tradeoffs

- Only covers what the definitions declare; hand-written SQL escapes it.
- Another CI step to keep green.

### Risks

Low. Worst case it is noisy and gets tuned.

### Scalability / Performance

N/A — build-time only.

### Maintainability

Improves it. Report definitions become verified artefacts.

### Complexity

Low.

### Effort

**3.**

### Business impact

Medium. Prevents a category of embarrassing, badly-timed failures.

### Confidence

**High** on value, **High** on feasibility given AD-16.

### Evidence

`[DOCS]` <https://www.acumatica.com/media/2020/09/AcumaticaFramework_DevelopmentGuide.pdf>

---

## R-08 — Commit a generated schema snapshot and diff it in CI

**Recommendation.** Generate a complete schema description (tables, columns, types, constraints, indexes,
triggers, RLS policies) from the live database, commit it, and fail CI when migrations produce a schema
that differs from the committed file without the file being updated in the same change.

### Why

OFBiz has one genuinely excellent property QAYD lacks: **the entire data model is a single greppable,
diffable, reviewable artefact** — 733 entities and 160 view-entities in 21,503 lines of XML
`[CODE] applications/datamodel/entitydef/`. "Which entities reference `partyId`?" has a complete answer in
one command.

QAYD's migrations *produce* a schema; nothing *describes* it. The benefit is obtainable without OFBiz's
cost (AP-01) by generating the artefact rather than generating the database from it.

The same practice already exists in a sibling codebase — `docs/DATABASE_STRUCTURE.md` in PillPal is
maintained as the schema source of truth — so the pattern is familiar in this organisation.

### Benefits

- Schema changes become reviewable in a pull request as *schema*, not as migration code.
- A dropped constraint or a disabled RLS policy shows up as a diff a reviewer will notice — which, given
  that database-enforced integrity is QAYD's moat (L-05), is the highest-value thing to make visible.
- Answers whole-schema questions in one command.

### Tradeoffs

- One more artefact to keep current; annoying when it drifts.

### Risks

- **It becomes noise and gets ignored.** Mitigate by making the diff fail the build rather than merely
  reporting, so it cannot be ignored.

### Scalability / Performance

N/A.

### Maintainability

Improves it substantially.

### Complexity

Low — a `pg_dump --schema-only` derivative plus a normalisation pass for stable ordering.

### Effort

**3.**

### Business impact

Medium — internal, but it protects the moat by making its erosion visible.

### Confidence

**High.**

### Evidence

`[CODE] applications/datamodel/entitydef/` (the benefit) · `DatabaseUtil.java` (the cost to avoid)

---

## R-09 — Make an axis assignment immutable once allocations exist

**Recommendation.** Once any allocation references a dimension member, that member's parent dimension
(axis) cannot be changed. Enforce with a trigger, not a validation.

### Why

Tryton refuses to change an analytic account's `root` once entries exist, raising `AccessError`
`[CODE] modules/analytic_account/account.py:304-322`. **Re-parenting a member to a different axis silently
reinterprets every historical allocation that used it** — the ledger does not change, but every
dimensional report over history changes meaning, with no record of why.

It is the dimensional analogue of AD-07: the past is not editable.

### Benefits

- Closes a silent-history-rewrite path.
- Consistent with AD-07's philosophy applied to the dimensional layer.
- Forces the correct operation (deprecate the member, create a new one under the right axis) which
  preserves history.

### Tradeoffs

- A genuine misconfiguration caught late requires deprecate-and-recreate rather than a fix. Correct, and
  it must be explained in the error message.

### Risks

Low. Worst case is user frustration, which good error text handles.

### Scalability / Performance

Negligible — a trigger with an `EXISTS` check on write to a low-volume table.

### Maintainability

Good — one trigger, one rule.

### Complexity

Low.

### Effort

**2**, if built with the dimension schema.

### Business impact

Medium — prevents a class of "the numbers changed and nobody touched anything" incidents, which are
disproportionately damaging to trust.

### Confidence

**High.**

### Evidence

`[CODE] modules/analytic_account/account.py:304-322`

---

## R-10 — Add a derived dimensional-completeness state and a worklist

**Recommendation.** A derived `dimension_state` on the journal line, recomputed whenever its allocations
change, valid only when every *required* axis is fully allocated. Plus a standing worklist of posted lines
whose state is incomplete. **Posting is not blocked by it.**

### Why

AD-11 lists as an open cost: *"Enforcing 'every line must be allocated' where required is a separate,
harder rule than 'this column is NOT NULL'."* Tryton has shipped the answer: an `analytic_state` on the
move line, updated when the move is posted, "valid only if all the Analytic Account axes are completely
filled", with a standing menu item *Analytic Lines to Complete* over
`[["analytic_state","=","draft"],["move_state","=","posted"]]`.
`[CODE] modules/analytic_account/line.py:107-128`, `doc/design.rst`

It refuses the false choice: blocking posting makes close hostage to a data-entry backlog; silent gaps
make dimensional reports quietly wrong.

### Benefits

- Month-end close is never blocked by dimensional data entry.
- Incompleteness is visible, finite, assignable, and countable — auditors can be told exactly how much
  posted value is un-dimensioned.
- Dimensional reports can state their own completeness, which is a feature rather than an admission.

### Tradeoffs

- Reports must carry a completeness figure. Arguably an improvement over the industry norm of not knowing.

### Risks

- **The state drifts from reality.** Mitigate exactly as Tryton does: derive it, never assert it —
  recompute from the actual allocation rows on every change (AD-20's rule applied to a boolean), and ship
  a rebuilder.
- **The worklist is never worked.** Mitigate by reporting the number, not just the list.

### Scalability

A boolean/enum column plus a partial index on the incomplete subset. Cheap.

### Performance

Recomputation is per-line on allocation change, not a scan.

### Maintainability

Good. Derived state with a rebuilder is a pattern AD-20 already sanctions.

### Complexity

Low-Medium.

### Effort

**5.**

### Business impact

Medium-High. It removes the main practical objection to mandatory dimensions, which is what makes
dimensional data actually get entered.

### Confidence

**High** — this is a working production solution to a problem AD-11 explicitly leaves open.

### Evidence

`[CODE] modules/analytic_account/line.py:107-128`, `modules/analytic_account/doc/design.rst`

---

## R-11 — Give deliberately expensive paths distinct types, not flags

**Recommendation.** Where QAYD accepts a known cost on purpose — gapless numbering (AD-09), pessimistic
locking (AD-08), synchronous posting — express it as a distinct named type or service, so every call site
declares that it accepted the cost and the cheap variant cannot leak in.

### Why

Tryton's `ir.sequence.strict` is a separate model whose entire body is *"call the parent with
`_lock=True`"* `[CODE] trytond/ir/sequence.py:445-451`, and `account.period.move_sequence` is typed to it,
so a non-gapless sequence **cannot** be wired to a journal by mistake
`[CODE] modules/account/period.py:44-49`. A boolean column would give neither the visibility nor the
guarantee.

### Benefits

- The cost is visible at every use site rather than buried in configuration.
- The wrong choice becomes unrepresentable instead of merely discouraged.
- It documents AD-08 and AD-09 in the type system, where documentation cannot rot.

### Tradeoffs

- More types. In PHP, weaker enforcement than in a static ORM — the value is mostly in naming and review.

### Risks

Low.

### Scalability / Performance

Neutral.

### Maintainability

Better — intent is legible at the call site.

### Complexity

Low.

### Effort

**3**, opportunistically as these subsystems are touched.

### Business impact

Low directly; **meaningful indirectly**, because AD-09's serialisation is exactly the kind of cost that
gets accidentally applied where it was not needed, and then blamed on the decision rather than the misuse.

### Confidence

**High** on the principle, **Medium** on how much PHP's type system will actually enforce.

### Evidence

`[CODE] trytond/trytond/ir/sequence.py:445-451`, `modules/account/period.py:44-49`

---

## R-12 — Keep the posting policy seam; do not build a rules engine

**Recommendation.** Design-only, now: ensure `PostingService` is shaped as *(accounting event) + (policy)
→ (journal entry)*, with the policy resolvable per company and per jurisdiction. **Do not build a rules
engine.** When one is needed, scope it to tax first (open decision O7) and never to the double-entry core.

### Why

Oracle Fusion's SLA is, in Oracle's own words, an "accounting engine that combines transaction and
reference information from source systems with accounting rules to create detailed journals stored in an
accounting repository", invoked by a "Create Accounting process" against "accounting events" `[DOCS]`. The
idea is right: jurisdictional variation is genuinely rules, not code paths.

The implementation is a trap. A rules engine is a programming language you now maintain, debug and secure,
usually without a debugger, and SLA is widely regarded as one of the harder things to configure in
enterprise software.

OFBiz shows the cheap version failing differently: posting fired by SECAs on commit events
`[CODE] applications/accounting/servicedef/secas_ledger.xml:25-28` — correct decoupling, but no call
graph, no ordering, and no bound on who may write to the ledger.

### Benefits

- Keeps the option open at near-zero cost.
- Makes the eventual jurisdictional split a data change rather than a fork.
- Names the trap explicitly, so "let's make posting configurable" arrives with its cost attached.

### Tradeoffs

- Slightly more abstraction in `PostingService` than today's single use case requires. Small, and AD-13
  already sanctions named seams.

### Risks

- **The seam becomes a second writer.** AD-04 is the guard: an event handler may *decide* to post, but it
  must post *through* `PostingService`. AD-14 decouples; AD-04 keeps the invariant verifiable. **OFBiz has
  the first without the second, and that is precisely its failure.**

### Scalability

Neutral now; enabling later.

### Performance

Neutral — a policy lookup, cached.

### Maintainability

Better. Jurisdictional variation stops being `if (country === 'KW')` scattered through Actions.

### Complexity

Low now; deliberately deferred.

### Effort

**2** — a design review of `PostingService`'s signature and a note in the ADR.

### Business impact

Medium, and it compounds with expansion. The GCC is six jurisdictions with diverging e-invoicing and VAT
regimes; the difference between a data change and a fork per market is the difference between expansion
and rebuild.

### Confidence

**Medium.** The direction is well-evidenced; the timing judgement (defer the engine) is ours, and it
should be revisited when the second jurisdiction ships.

### Evidence

`[DOCS]` <https://docs.oracle.com/cd/E25054_01/fusionapps.1111/e20374/F484243AN100CE.htm> ·
`[CODE] applications/accounting/servicedef/secas_ledger.xml:25-28`

---

## Sequencing

**This sprint** — R-06 (2 points, protects the home market's correctness), R-08 (3 points, protects the
moat by making its erosion visible).

**In the AD-11 ADR, before any allocation data exists** — R-01, R-02, R-09. AD-11 already states that the
decision is free exactly once; R-01 in particular is free now and a migration on the largest table later.

**With the dimension schema** — R-05, R-10.

**Before counterparty data grows** — R-04.

**Opportunistic** — R-07 (when report definitions exceed ~10), R-11 (as subsystems are touched), R-12
(design review only).

**The strategic bet, after the integrity core is complete** — R-03.

---

## What we are deliberately NOT recommending

Recorded because the reasoning matters as much as the recommendations.

| Not recommended | Why |
|---|---|
| A declarative XML/YAML schema layer (OFBiz-style) | AP-01. The generator becomes the ceiling. R-08 gets the benefit without the cost. |
| A general workflow/ECA engine | AP-09. AD-14 plus AD-04 already give decoupling *with* a verifiable single writer. |
| A rules engine for posting | R-12. Right idea, wrong decade for QAYD. Tax first, if ever. |
| Typed/BQL-style query DSL | BP-08. Not portable to PHP; R-07 captures the affordable part. |
| Fixed dimension columns on `journal_lines` | AP-07, L-01. The exact failure AD-11 exists to prevent. |
| Segmented account codes | AP-06. Four independent failure modes; Acumatica-vs-Intacct is the demonstration. |
| JSONB as ledger storage | Already rejected in AD-11; nothing here weakens it. |
| Optional audit or any invariant behind a flag | AP-08, AD-21. Tryton's opt-in history is what this looks like in a well-engineered system. |
