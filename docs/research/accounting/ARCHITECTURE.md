# Architecture — What Can Legitimately Be Inferred

**Scope:** QuickBooks Online · Xero · Zoho Books · FreshBooks · Wave · FreeAgent · KashFlow
**Companion to:** [`OVERVIEW.md`](./OVERVIEW.md) (the facts) · [`BEST_PRACTICES.md`](./BEST_PRACTICES.md) (what works)
**Prior work:** internal architecture of *open* systems is covered in
`docs/architecture/knowledge/06_COMPETITIVE_ANALYSIS.md` §2.1–2.16 and `docs/research/odoo/`. This file
does not repeat it.

---

## 0. The epistemic contract for this file

This is the file most likely to be misread, so the rules are stated first and enforced throughout.

**All seven subjects are closed-source commercial SaaS.** There is no repository to read. Therefore:

| We can verify | We cannot verify |
|---|---|
| The **API contract**: object names, field names, cardinality, validation errors, required/optional, versioning policy, rate limits, webhook events | The **schema**: table layout, keys, constraints, indexes, whether a column exists at all |
| **Observable behaviour**: what an operation does to subsequent reads, what is reversible, what error a bad input produces, what is eventually consistent | **Implementation**: services, languages, storage engines, queues, caches, sharding |
| **Documented workflow**: the steps a user must take, what state names appear in the UI | **Internal state machines**: whether those UI states correspond to stored states |
| **Published limits**: row caps, list caps, file-size caps, report limits | **Why** those limits exist |

**The legitimacy test for an inference.** Inferring an internal design from an API surface is
legitimate **only** when the inference is about what the observable contract *requires or forbids*,
not about how the vendor chose to satisfy it. Two examples:

- ✅ *Legitimate:* "Xero's API exposes `BankTransaction` and `Payment` as distinct objects, and an
  invoice's `AmountDue` changes when a `Payment` is created against it. The contract therefore requires
  a link object between a payment and the document it settles, and requires the residual to be derivable
  from those links." — This is a statement about the contract. Tagged `[INFERENCE]`.
- ❌ *Illegitimate:* "Xero stores residuals in a materialised column on the invoice table." — This is a
  guess about storage. It would be tagged `[UNKNOWN]`, and it is: nobody outside Xero knows.

Everything below obeys that line. Where the line is close, the reasoning is shown so it can be attacked.

**What this file is actually for.** Not to reverse-engineer competitors — that would be both
unreliable and, per the programme's absolute rules, useless. It is to extract the **workflow and
data-model shapes that this category's contracts imply**, because those shapes are what QAYD's banking
and matching subsystems must be compatible with — not because the competitors chose them, but because
the *problem* imposes them.

---

# Part 1 — The shapes the category implies

These are conclusions about the **problem domain**, evidenced by the fact that seven independently
built products converged on them. Convergence across independent teams is weak evidence about
implementation and strong evidence about **necessity**.

## 1.1 The imported bank line is a first-class, durable, pre-accounting object

**Observation `[DOCS]`.** Every product in this category has a user-visible noun for "a transaction
that came from the bank and has not yet become bookkeeping". QuickBooks calls it a transaction in the
**For Review** tab; Xero surfaces it as a **bank statement line** on the Reconcile tab; Wave, Zoho
Books and FreeAgent all have an equivalent inbox. The QuickBooks Online API exposes no way to post a
"for review" item as a ledger transaction without an explicit accept action `[INFERENCE]` — the review
step is a contract, not a UI convenience.

**Inference `[INFERENCE]`.** The domain requires **two distinct persistent objects**, not one:

1. An **observation** — "the bank says −45.500 KWD moved on 3 March with the narrative `TAP*PAYMENTS`".
   This is external evidence. It is immutable in the sense that the business did not author it and
   cannot legitimately change it. It exists before anyone has decided what it means.
2. An **assertion** — the journal entry that says what that movement *was*.

Products that collapse these into one object cannot answer "what did the bank actually say?" after a
user edits the categorisation. Products that keep them separate can always re-derive.

**Why the reasoning holds.** The two objects have different truth conditions and different lifecycles.
The observation's truth is "the bank sent this"; it is falsifiable only by re-fetching from the bank.
The assertion's truth is "this is the correct accounting treatment"; it is revisable by a human at any
time. Anything with two different truth conditions is two things. `[INFERENCE]`

**Consequence for QAYD.** `bank_statement_lines` (or whatever it is named) is **not** a staging table
to be truncated after import. It is a permanent record of external evidence, retained for the life of
the company, and it is the citation target for every AI reconciliation proposal. This aligns with
`07_QAYD_INNOVATION.md` I-12 (number provenance): a ledger figure should dereference to its source
rows, and for bank-derived entries the source row is the statement line.

**What is genuinely unknown.** Whether any of the seven actually persists statement lines
indefinitely, and what their retention policy is. `[UNKNOWN]`

---

## 1.2 Matching is a link, and the residual is derived — not stored

**Observation `[DOCS]`.** In every one of these products, part-paying an invoice reduces the amount due
without the user editing the invoice. Applying a credit note to an invoice does the same. Removing the
payment restores the original amount. The invoice document itself is not rewritten.

**Inference `[INFERENCE]`.** The contract requires an **allocation/link object** carrying (source
document, target document, amount, date) and a rule that the outstanding balance of a document is
`original − Σ(allocations)`. It is possible to *also* cache that sum, but the contract requires the
link to be the authority, because removing a link must restore the prior state exactly — which a
stored-and-mutated residual cannot guarantee under concurrency.

**Cross-reference, not restatement.** This is the same conclusion `docs/research/odoo/ODOO_TO_QAYD.md`
§3.1 reached from Odoo's *source*, and §4.4 of the competitive analysis reached from Odoo's defects.
The value of seeing it again here is that it is confirmed **behaviourally, from outside, in seven
products that share no code**. A design principle that survives both a source reading and a black-box
convergence check across seven independent implementations is about as well-evidenced as software
design gets.

**Consequence for QAYD.** QAYD's append-only `ledger_entries` projection *forbids* the mutate-residual
approach outright — there is no UPDATE available. The category's convergent design and QAYD's hard
constraint point the same direction, which is a rare and welcome alignment. Matching state belongs in
side tables; unmatching is an INSERT of a compensating link, never a DELETE.

---

## 1.3 Reconciliation has two meanings, and conflating them is a design error

This is the single most important structural distinction in the category, and it is invisible to
anyone who has only used one of these products.

| | **Line-clearing** ("agreeing the feed") | **Statement reconciliation** ("agreeing the balance") |
|---|---|---|
| The question it answers | "Has every line the bank sent been given an accounting meaning?" | "Does my ledger's cash balance equal the bank's closing balance on this statement, and if not, what explains the difference?" |
| Unit of work | One statement line | One statement period |
| Output | A journal entry (or a match to an existing one) | An attestation with a date, a closing balance, and a difference of zero |
| Failure mode | Unactioned lines pile up | A discrepancy that must be explained |
| Xero's emphasis `[DOCS]` | This is Xero's primary daily surface — the Reconcile tab | Present, as a separate reconciliation report/attestation |
| QuickBooks' emphasis `[DOCS]` | Present, as the "For Review" / Banking tab | This is QuickBooks' formal `Reconcile` workflow, driven by entering a statement ending balance and date |

**Inference `[INFERENCE]`.** These are not two implementations of one feature; they are two features
with different data. Line-clearing needs *lines*. Statement reconciliation needs a **statement header**
— an opening balance, a closing balance, a period — which is a different object that a raw transaction
feed does not necessarily provide.

**Why this matters more in the GCC than anywhere else.** A live bank feed supplies lines continuously
and often supplies no statement boundary at all, which is why feed-first products push line-clearing to
the front. A **PDF or CSV bank statement — which is what a Kuwaiti SME actually has (see
`OVERVIEW.md` §8) — supplies the opposite: it is natively a statement, with an opening balance, a
closing balance and a hard period boundary, and the lines are inside it.** `[INFERENCE]`

**Consequence for QAYD — and it is a significant one.** The input format QAYD is forced to accept is
*structurally better suited to the stronger of the two reconciliation concepts*. QAYD should model the
**statement as the primary object** — `bank_statements` (account, period, opening balance, closing
balance, source document) owning `bank_statement_lines` — and derive line-clearing from it. That
produces a control the feed-first products get only as a secondary feature: **the imported lines must
sum from the opening balance to the closing balance, or the import is rejected as incomplete.**

That single check catches the most common and most damaging error in statement-driven bookkeeping — a
partial import, a missing page of a PDF, a truncated CSV — at the door, before a single entry is
posted. A feed-first architecture cannot perform it, because it has no closing balance to check
against. This is a case where the constraint QAYD is under produces a *better* design than the one the
market leaders have, and it should be treated as an asset rather than a workaround.

---

## 1.4 Rules are a small, closed predicate language over a narrow field set

**Observation `[DOCS]`.** QuickBooks bank rules and Xero bank rules both operate on a bounded set of
statement-line attributes — description/payee/reference text, amount, and direction (money in / money
out) — combined with `all`/`any` semantics and a handful of string operators (contains, equals, starts
with, does not contain). Actions are similarly bounded: set the account, set the contact/payee, set
tax, optionally split by percentage or amount, and optionally auto-apply.

**Inference `[INFERENCE]`.** Nobody in this category built a general expression language, and the
absence is not an oversight — it is the correct answer arrived at independently seven times. The
reasons the contract implies:

1. **Rules must be explainable to a non-accountant.** "If the description contains TAP, code it to
   Merchant Fees" is auditable by the person who wrote it a year later. A scripting expression is not.
2. **Rules must be safely evaluable at import time over thousands of lines**, which forbids anything
   with unbounded execution.
3. **Rules must be reorderable and their conflicts resolvable**, which requires a total order —
   hence the run-order / priority concept that appears in both QuickBooks and Xero `[DOCS]`.

**Consequence for QAYD, and its connection to existing QAYD design.** This validates
`07_QAYD_INNOVATION.md` I-16 (natural-language accounting as **reviewable predicates**) from the market
side. The category has already proven that a *small, closed, ordered predicate language* is the right
shape; QAYD's contribution is not inventing a different shape but **letting an agent author predicates
in that shape from natural language, and rendering them back for human approval**. The predicate
remains data — a CHECK-constrained JSONB selector compiled through an allowlist, never `eval` — exactly
as §4.4 of the competitive analysis specified.

The asymmetry worth naming: in QuickBooks and Xero a human writes the rule and the machine executes it.
In QAYD the machine should *propose* the rule — having noticed that the last nine `TAP*` lines were all
coded the same way — and the human approves it once. Same artefact, inverted authorship. That is a
genuine product difference that costs almost nothing extra to build once the predicate language exists.

**What is unknown.** Whether QuickBooks or Xero derive rule suggestions from user behaviour internally,
and with what technique. Both surface "suggested" categorisations `[DOCS]`; the mechanism is
`[UNKNOWN]`.

---

## 1.5 The API surface is document-shaped, not ledger-shaped — and that is a deliberate boundary

**Observation `[DOCS]`.** The public APIs of QuickBooks Online and Xero expose business documents —
invoices, bills, payments, credit notes, bank transactions, contacts, items, accounts — and expose
journals/ledger data in a **read-oriented** form. Writing arbitrary journal lines is either restricted,
discouraged, or absent from the mainstream integration path.

**Inference `[INFERENCE]`.** The vendors are protecting the same invariant QAYD protects with a
database trigger: **there is one door into the ledger, and it is the posting engine, not the API.**
They enforce it by *not exposing* a general ledger-write verb; QAYD enforces it in the storage engine.
The vendors' approach is weaker (an internal service can still bypass it; an undocumented endpoint may
exist) but the intent is identical and it is the right intent.

**The corollary that matters for QAYD's AI engine.** QAYD's MVP already specifies that the AI proposes
through `/api/v1` and never writes the ledger directly (`docs/execution/MVP_SCOPE.md`, step 3
invariant). This category's API design is independent confirmation that a **document-shaped write
surface with a ledger-shaped read surface** is the correct API boundary, for a reason beyond safety:
document-shaped writes carry business meaning, so the posting rules stay in one place and can be
changed without rewriting every integrator.

**Explicitly `[UNKNOWN]`:** exact object lists, field names, minor-version policies, rate limits and
sandbox behaviour for each API were not re-verified in this pass and should not be quoted from memory.
Where `OVERVIEW.md` states API specifics, they carry their own citations.

---

## 1.6 Multi-currency is a packaging boundary, which reveals it is a distinct subsystem

**Observation `[DOCS]`.** Multi-currency is gated to higher tiers in this category — most visibly in
Xero, where it sits on the top plan. Products can and do sell a fully functional single-currency
edition.

**Inference `[INFERENCE]`.** A feature that can be cleanly withheld is a feature that is cleanly
separable. Multi-currency in these products is therefore very likely an **additive layer over a
single-currency core** — transaction currency plus rate plus base amounts — rather than a property
woven through the ledger. That is the same shape QAYD already has: `ledger_entries` carries
`base_debit_amount` / `base_credit_amount` and a `signed_base_amount` derived by CHECK constraint, so
base-currency reporting is well-defined before transaction currency is introduced.

**Consequence for QAYD.** Deferring multi-currency to post-MVP (as `MVP_SCOPE.md` does) is
**structurally safe**, on the condition that the ledger row already carries base amounts as the
authority — which it does. The thing that must *not* be deferred is the decision about **where the rate
lives and what it is a rate for**, because retrofitting rate provenance onto historical rows is the
expensive part, not adding a currency column. Record the decision now; build the subsystem later.

**Caution `[INFERENCE]`.** The GCC makes this less deferrable than a Western market would. A Kuwaiti
trading SME routinely holds USD and AED alongside KWD. Single-currency is defensible for an MVP whose
purpose is proving the AI loop; it is not defensible for a first paying cohort of importers. See
`IMPLEMENTATION_RECOMMENDATIONS.md` §3.

---

## 1.7 Attachments are first-class and universally linkable

**Observation `[DOCS]`.** Every product in the category lets a source document (photo, PDF, emailed
receipt) be attached to a transaction, and several make emailing documents into the product a primary
capture path.

**Inference `[INFERENCE]`.** The contract implies a **polymorphic attachment association** — one
storage object linkable to many record types — plus an ingestion path independent of the UI (email
address, mobile capture, folder sync). Nothing about the storage design is inferable. `[UNKNOWN]`

**Consequence for QAYD.** Already scoped (Supabase Storage; documents linked to bills and used as AI
citations). The category-derived refinement is that **the ingestion path matters more than the
attachment model**: the products with the strongest capture stories give the document its own inbound
channel so a user never has to be logged in to file something. A forward-to-QAYD email address is a
small piece of engineering with outsized behavioural effect.

---

# Part 2 — Per-product inference notes

Short by design. Each entry records only what the *observable contract* supports, and is explicit about
what it does not.

## 2.1 QuickBooks Online

- **Contract shape `[DOCS]`:** document-centric API with an accounting-object model (customers,
  vendors, items, invoices, bills, payments, journal entries, accounts) and reporting endpoints.
- **Now more than inference — the user-visible contract confirms it.** Un-reconciling a single
  transaction means cycling a **register checkbox through R → C → blank** `[DOCS]`
  (https://quickbooks.intuit.com/learn-support/en-us/help-article/accounting-bookkeeping/undo-remove-transactions-reconciliations-online/L6ERlEXxn_US_en_US).
  A three-valued status toggled *on the transaction row* is reconciliation state stored on the
  transaction, exposed directly to the user. `[INFERENCE]` only in that the storage mechanism behind the
  UI is unverifiable; the *model* is not in doubt.
- **And the documented consequence is exactly the predicted one.** Intuit warns that removing a cleared
  transaction **changes the beginning balance for the next reconciliation** `[DOCS]`, so corrections
  cascade forward and practitioners undo in reverse chronological order `[COMMUNITY]`. That cascade is
  the signature of derived-from-mutable-state rather than of an immutable attestation: if each period's
  reconciliation were a recorded event with its own opening and closing balances, altering an earlier
  period would *contradict* a stored attestation rather than silently redefine the next one.
- **Why that matters to QAYD:** it is precisely the design `06_COMPETITIVE_ANALYSIS.md` §4.1 warns
  against importing — writing reconciliation state onto the ledger row. QAYD's `journal_lines.reconciled`
  boolean is the same hazard and should be dropped in favour of side-table matching state. **The market
  leader having done it this way is not a reason to copy it**; it is the reason reconciliation recovery
  is among the most-complained-about mechanics in the product `[COMMUNITY]`, and the reason the one-click
  period undo had to be restricted to a privileged role (`ANTI_PATTERNS.md` §24). *The role gate is a
  workaround for a data-model choice.* An attestation-as-event model needs no such gate, because undo is
  additive and therefore safe.
- **Also inferable from published caps `[INFERENCE]`:** a hard **250-account chart-of-accounts limit
  below the top tier** and **40 combined classes+locations** on Plus `[DOCS]` are presented as licensing
  limits. Whether they are also technical ones is `[UNKNOWN]` — but a system that can offer "unlimited"
  one tier up is evidently not bound by the schema, which makes these commercial constraints on the
  customer's data model. QAYD should never ship an equivalent (`ANTI_PATTERNS.md` §16).
- **Unknown:** everything about storage, scale architecture, the bank aggregator used, and how the
  review queue is persisted.

## 2.2 Xero

- **Contract shape `[DOCS]`:** OAuth 2.0 API over documents and contacts, plus bank-feed-oriented
  objects; a separate Bank Feeds API for financial institutions to push statement data in.
- **Legitimate inference `[INFERENCE]`:** the existence of a **dedicated institution-facing feed API**
  — distinct from the customer-facing accounting API — implies feed ingestion is an independent
  bounded context with its own contract, its own idempotency requirements, and its own duplicate
  handling. That is a strong architectural signal: **statement ingestion should not be a function
  inside the accounting service.**
- **Why that matters to QAYD:** QAYD's equivalent of that boundary is statement *parsing* (PDF/CSV/
  MT940), and the same conclusion applies — it belongs behind its own interface, producing a normalised
  statement + lines artefact, with the accounting core consuming that artefact and knowing nothing about
  file formats. This also makes the eventual switch from "parse a PDF" to "call a Kuwaiti open-banking
  AISP" a change of one adapter rather than a rewrite. Given CBK's framework is still draft
  (`OVERVIEW.md` §8), designing for that swap now is cheap insurance rather than speculation.
- **Unknown:** internal matching implementation, whether suggested matches are heuristic or learned,
  and Xero's decimal-place handling for 3-decimal currencies from a primary source. `[UNKNOWN]`

## 2.3 Zoho Books

- **Contract shape `[DOCS]`:** REST API plus a first-party scripting layer (Deluge custom functions)
  and workflow rules — the only product of the seven that ships **user-authored server-side logic** as
  a mainstream feature.
- **Legitimate inference `[INFERENCE]`:** exposing customer-authored server-side code implies a
  sandboxed execution boundary and a stable internal event model to hook into. It also implies a
  permanent compatibility commitment — the same commitment `06_COMPETITIVE_ANALYSIS.md` §4.3 identifies
  as the reason Dolibarr can never tighten a core invariant.
- **Judgement for QAYD:** this is a **capability to refuse**, for the reason already established in
  prior work — user-authored code in the tenant's execution path permanently freezes internal
  interfaces. QAYD's equivalent expressive power should be delivered as **declarative predicates**
  (§1.4), which are inspectable, statically analysable, and safe to evaluate, and which do not
  constrain internal refactoring.
- **Configurable decimal places per currency `[DOCS]`** — see `OVERVIEW.md` §8.3; this is the one
  product of the seven with published evidence of per-currency decimal configuration.

## 2.4 FreshBooks

- **Observable history `[DOCS]`:** chart of accounts, general ledger and trial balance were introduced
  as **additions** to an existing invoicing product, and are gated to Plus/Premium/Select plans.
- **Legitimate inference `[INFERENCE]`:** a ledger introduced after the documents means the documents
  were, for years, the system of record and the ledger is a **projection derived from them**. That is
  architecturally the inverse of QAYD, where the ledger is primary and documents are sources.
- **Why it matters — this is the most instructive case in the study.** The retrofit direction
  determines what the product can never cleanly do. If documents are primary, an adjustment that has no
  document has no natural home; opening balances are awkward; and a correction has to be expressed as a
  document rather than as an entry. This is the concrete, observable cost of "invoicing tool grows a
  ledger", and it is the trajectory a GCC startup is most tempted to take because invoicing is what
  sells first. **QAYD deliberately did the hard thing first** (ledger, immutability, one posting door)
  and should treat that as a durable advantage rather than a delay.
- **Unknown:** completeness of the ledger, journal-entry capability, and reconciliation depth were not
  fully verified. `[UNKNOWN]`

## 2.5 Wave

- **Observable `[DOCS]`:** a free Starter tier exists, and automatic bank-transaction import was moved
  behind the paid Pro tier in early 2024.
- **Legitimate inference `[INFERENCE]`:** the vendor's own pricing decision identifies **the bank feed
  as the highest-willingness-to-pay component of the entire product**. When a company that built its
  brand on "free" chooses one feature to put behind the paywall first, that feature is the value.
- **Direct consequence for QAYD's packaging** — see `IMPLEMENTATION_RECOMMENDATIONS.md` §7. In a market
  where the automated feed does not exist, the equivalent value carrier is **automated statement
  ingestion plus AI matching**: the thing that removes typing. Price accordingly.

## 2.6 FreeAgent

- **Observable `[DOCS]`:** direct filing to the UK tax authority and a forward-looking tax timeline;
  distributed free to business-current-account holders of its owning bank group.
- **Legitimate inference `[INFERENCE]`:** direct statutory filing implies the product maintains a
  **compliance calendar as data** — obligation types, due dates, computed liabilities, filing state —
  which is a subsystem distinct from the ledger and distinct from reporting. Most products in this
  category produce a *report a human then files*; a filing-capable product must model the obligation
  itself, including its lifecycle and its evidence.
- **Why this is the most transferable idea in the study.** It generalises far beyond tax: it is the
  difference between *"here is a number, you deal with it"* and *"here is what you owe, when, and I can
  discharge it for you"*. For the GCC that maps onto ZATCA clearance in Saudi and, when Kuwait
  eventually acts, onto whatever Kuwait mandates. **The obligation model is worth building before there
  is an obligation to model**, because it is the spine that makes later compliance work additive rather
  than structural. See `LESSONS_FOR_QAYD.md` §4.
- **Also `[INFERENCE]`:** bank-as-distributor is a *go-to-market* architecture, not a product one, and
  it is the single most efficient acquisition mechanism observed in the category. Its GCC analogue is
  examined in `LESSONS_FOR_QAYD.md` §6.

## 2.7 KashFlow

- **Observable `[DOCS]`/`[COMMUNITY]`:** the payroll product reached end-of-life in April 2026; the
  accounting product's current commercial status within its owner's portfolio was not fully verified.
  `[UNKNOWN]`
- **Legitimate inference `[INFERENCE]`:** the useful content here is not architectural. It is that an
  acquired SME accounting product inside a larger vendor portfolio is subject to **portfolio
  consolidation risk** — the buyer's roadmap, not the product's merit, decides its future.
- **Why it is in this study at all:** it is the category's clearest reminder that **an SME's accounting
  system is infrastructure with a decade-long horizon, and vendors abandon markets and products.** The
  same logic explains QuickBooks' withdrawal from India (`OVERVIEW.md` §8.5). For QAYD this is a
  *sales* asset, not a technical one: data portability and a credible export story answer a fear the
  market has learned the hard way.

---

# Part 3 — Where the category's implied shape and QAYD's built architecture disagree

The point of this section is to be explicit about the places QAYD is deliberately different, so the
difference is a decision rather than an accident.

| Dimension | Category's implied shape | QAYD as built | Verdict |
|---|---|---|---|
| **Ledger mutability** | Mutable enough to support edit-in-place and undo-reconcile `[INFERENCE]` | `ledger_entries` append-only, enforced by trigger `[CODE]` | **QAYD is right.** The category's mutability is the root of its reconciliation fragility. Hold the line. |
| **Reconciliation state** | Stored on the transaction `[INFERENCE]` | `journal_lines.reconciled` boolean exists — the same hazard | **Change QAYD.** Drop the boolean; matching state to side tables. Already flagged in `06_COMPETITIVE_ANALYSIS.md` §4.1. |
| **Primary bank object** | The line (feed-first) | Undecided; MVP does statement upload | **Diverge deliberately.** Model the **statement** as primary (§1.3). It is both what the GCC provides and the stronger control. |
| **Rules authorship** | Human writes, machine executes | Not yet built | **Invert it.** Machine proposes, human approves; predicate stays data (§1.4, I-16). |
| **Extensibility** | Zoho: user-authored server-side code; Xero/QBO: app ecosystem | None yet | **Refuse the code path.** Declarative predicates only. Ecosystem is a later, separate decision. |
| **Ledger write surface** | Not exposed as a general verb `[INFERENCE]` | One `PostingService` door, trigger-enforced | **Agreement, QAYD stronger.** Their convention vs QAYD's constraint. |
| **Currency precision** | 2-decimal assumptions are pervasive; at least one major product cannot represent 3 `[DOCS]` | Base amounts as exact decimals | **QAYD is right, and it is a marketable difference in Kuwait.** See `ANTI_PATTERNS.md` §1. |
| **Compliance output** | A report the human files (except FreeAgent) | Not yet built | **Follow FreeAgent, not the majority.** Model obligations, not just reports (§2.6). |

---

# Part 4 — What was deliberately not inferred

Recorded so a later revision does not quietly fill these from memory or from a vendor's marketing page.

- The database technology, schema, or storage engine of any of the seven. `[UNKNOWN]`
- Whether any of them materialise account balances, and if so at what granularity. `[UNKNOWN]`
- How suggested matches and auto-categorisation are computed — heuristic, statistical, or learned —
  in any product. `[UNKNOWN]`
- Service decomposition, deployment topology, region strategy, or tenancy model. `[UNKNOWN]`
- Whether any product's audit trail is tamper-evident in a cryptographic sense. `[UNKNOWN]`
- Exact API object lists, field names, rate limits and versioning policies were not re-verified in this
  pass; do not quote them without a citation. `[UNKNOWN]`
- Xero's precise handling of 3-decimal currencies, from a primary source. `[UNKNOWN]` — this one is
  worth closing, because it is directly load-bearing for the Kuwait argument. See `OVERVIEW.md` §10.
