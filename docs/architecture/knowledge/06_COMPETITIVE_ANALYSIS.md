# 06 — Competitive Analysis

**How QAYD compares to the eight systems that already keep the world's books.**
Version 1.0 · 2026-07-28 · Status: **research** (informs decisions; does not bind like `01`)

---

## What this document is

An architecture comparison of QAYD against seven incumbent accounting and ERP systems, subsystem by
subsystem, written to answer three questions honestly:

1. Where is QAYD's architecture genuinely better, and what must never regress?
2. Where is QAYD behind in a way a real buyer would notice?
3. What can an AI-native architecture do that a legacy one structurally cannot?

It is a companion to `docs/research/odoo/` (which studies Odoo alone, in depth) and does **not** repeat
that work. Odoo appears here only as one column among eight.

### Evidence tiers — read this before trusting any claim

Three of the eight systems are proprietary. For those there is no source to read, and a confident
guess about an architecture you cannot see is worse than an admission. Every non-obvious claim below
carries a tier:

| Tier | Meaning |
|---|---|
| `[CODE]` | Verified by reading source, with a file path. |
| `[DOCS]` | Vendor or official documentation, cited. |
| `[COMMUNITY]` | Widely reported, not verified against a primary source. |
| `[INFERENCE]` | Reasoning from evidence, labelled as reasoning. |
| `[UNKNOWN]` | Could not be determined. Stated openly. |

The `[UNKNOWN]` entries are the most valuable part of this document's discipline. "I could not
determine how NetSuite isolates tenants" is a fact about the state of public knowledge. A plausible
invented answer is a liability that will be quoted back at us in a design review.

**Sources.** Odoo 19.0 (`f3e407c6`), Akaunting 3.2.1 (`4aec5fc`), Dolibarr 25.0.0-alpha (`604c99f`),
ERPNext 17.0.0-dev / Frappe develop — all read as source. SAP, NetSuite, and Dynamics — public
documentation only, URLs held in the research transcripts. QAYD — this repository at the current commit.

---

# Part 1 — System profiles

## 1.1 QAYD

| | |
|---|---|
| **Origin** | 2026, Kuwait. Greenfield. Sprint 2 of an ongoing build. |
| **Licence** | Proprietary. |
| **Stack** | Laravel 12 / PHP 8.4 · PostgreSQL (single DB, RLS) · Next.js 15 · FastAPI AI engine · Redis · Reverb. |
| **Target market** | SME and mid-market, Kuwait → GCC. |
| **Demonstrated scale** | **None.** `[CODE]` 23 migrations, ~6,545 lines under `apps/api/app/`, 34 test files, a 91-line `routes/api.php`. Zero production tenants. |

**Architecture in a paragraph.** Every financial invariant is a PostgreSQL constraint, trigger, or
policy rather than an application check. `journal_entries` + `journal_lines` model every document type
behind one `entry_type` discriminator, with `CHECK (total_debit = total_credit)` and a paired base-currency
CHECK on the header and a one-sided CHECK on each line `[CODE: database/migrations/2026_07_28_000004,
_000005]`. Exactly one service, `JournalEntryPostingService`, may write a posted line; it re-derives the
balance from the lines with zero tolerance in *both* currencies, resolves the period through a
`FiscalCalendarResolver` seam, allocates a gapless number, and projects one row per line into
`ledger_entries` `[CODE: app/Services/Accounting/JournalEntryPostingService.php]`. `ledger_entries` is
append-only by database trigger — `trg_ledger_entries_append_only` raises on any UPDATE or DELETE, for
any role — and carries `signed_base_amount` with `CHECK (signed_base_amount = base_debit_amount −
base_credit_amount)`, so any balance is one `SUM()` `[CODE: _000007]`. Multi-tenancy is
`FORCE ROW LEVEL SECURITY` with a RESTRICTIVE company-boundary policy on every tenant table, enforced
for a `NOSUPERUSER NOBYPASSRLS` runtime role; there is deliberately no `sudo()`-equivalent bypass.
Money is `NUMERIC(19,4)` moved as strings and computed with bcmath.

**Defining architectural idea.** *An invariant that lives only in application code is a wish.* QAYD
pushes correctness down to the storage engine, and treats the AI as a first-class but **unprivileged**
author — `journal_entries.ai_generated`, `ai_confidence`, and `journal_lines.ai_suggested_account_id`
are columns in the ledger schema `[CODE]`, and a DB trigger refuses AI auto-posting.

---

## 1.2 Odoo

| | |
|---|---|
| **Origin** | 2005, Belgium (TinyERP → OpenERP → Odoo). |
| **Licence** | Community LGPLv3; Enterprise proprietary. Repo licence field reads `NOASSERTION` (mixed) `[CODE: GitHub API]`. |
| **Stack** | Python · custom ORM · PostgreSQL · OWL frontend. |
| **Target market** | SMB through lower mid-market, all industries, ~175 countries. |
| **Demonstrated scale** | 13M+ users claimed for 2025, higher figures circulating for 2026 `[COMMUNITY]`. 53k GitHub stars. |

**Architecture in a paragraph.** A metadata-flavoured ORM over PostgreSQL where models are Python
classes and inheritance is the extension mechanism. `account.move` + `account.move.line` model invoice,
bill, payment, and manual entry as one table with a `move_type` discriminator — a genuinely good idea.
There is **no ledger table**: journal lines *are* the general ledger, read with `parent_state='posted'`,
and there are **no stored aggregate balances anywhere**, so every trial balance is a full scan of the
largest table. Multi-tenancy is application-layer `ir.rule` domains, with `sudo()` as a transitive
ambient bypass at 552 call sites in the studied checkout. Money is float. There is not one
`CREATE TRIGGER` in the entire repository.

**Defining architectural idea.** *Everything valuable is data* — reports, taxes, dimensions, and
security predicates are all declared rather than coded. The concepts are excellent; almost none of them
are enforced by the database.

---

## 1.3 ERPNext / Frappe

| | |
|---|---|
| **Origin** | 2005–2011, India. Frappe framework first, ERPNext built on it. |
| **Licence** | Frappe **MIT**; ERPNext **GPL-3.0** `[CODE: GitHub API]`. |
| **Stack** | Python ≥3.14 · MariaDB (reference), PostgreSQL (second-class), SQLite, DuckDB (analytical) · custom metadata framework. |
| **Target market** | SMB and mid-market. Adoption most concentrated in **Saudi Arabia**, India, Philippines `[COMMUNITY]` — directly relevant to QAYD's market. |
| **Demonstrated scale** | 37k stars; 532 DocTypes; ~384k LOC Python in ERPNext plus ~213k in Frappe `[CODE]`. |

**Architecture in a paragraph.** The schema *is* data. A DocType is a row in `tabDocType` plus child
`tabDocField` rows, and saving it runs DDL: `DocType.on_update()` calls `frappe.db.updatedb()`, which
builds an `ALTER TABLE ... ADD COLUMN` and executes it in the request `[CODE:
frappe/core/doctype/doctype/doctype.py:533; frappe/database/schema.py]`. Tables are prefixed `tab` and
contain spaces (`` `tabGL Entry` ``). There are **zero foreign keys anywhere** — referential integrity
is Python, via `_validate_links()` on write and a link-scan on delete. Posting funnels through one
function, `make_gl_entries` `[CODE: erpnext/accounts/general_ledger.py:34]`, which validates balance in
Python with an explicit tolerance — `allowance = 0.5` currency units for non-journal vouchers — and
silently plugs any residual with a Round Off GL Entry. Multi-tenancy is **one database per site**
`[CODE: frappe/installer.py:39]`; there is no tenant column. The clearest self-assessment in the
codebase is the existence of a `Ledger Health Monitor` DocType that runs scheduled jobs to detect
`debit_credit_mismatch` — a system with DB-enforced invariants would not need one.

**Defining architectural idea.** *Make the schema data and you get a customisable ERP for free.* A user
can add a column to the general ledger from a web form and it appears in the REST API, report builder,
permission engine, and financial statements without a deploy. That is a real and hard-to-replicate
capability, bought by making the database a dumb store.

---

## 1.4 Akaunting

| | |
|---|---|
| **Origin** | 2017, Turkey. |
| **Licence** | **BUSL-1.1** — Business Source License, *not* OSI open source; converts to GPLv3 after four years `[CODE: composer.json, LICENSE.txt]` `[COMMUNITY: the 2023 GPL→BSL change was contested]`. |
| **Stack** | **Laravel 10 · PHP 8.1 · MySQL** — the closest stack match to QAYD in this comparison. |
| **Target market** | Micro-business and small business invoicing/bookkeeping. |
| **Demonstrated scale** | 10k stars; 85,781 LOC across 1,062 PHP files in `app/`; 13 migrations `[CODE]`. |

**Architecture in a paragraph, and the finding that matters most to QAYD.** **Akaunting is not
double-entry.** A repo-wide grep for `journal|double.?entry|ledger|posting` across `app/`, `database/`,
and `modules/` returns two irrelevant files; there is no `Journal`, `LedgerEntry`, or `Posting` class,
table, or migration `[CODE]`. `Account` is a **bank account** (`App\Models\Banking\Account`), whose
balance is `opening_balance + SUM(income) − SUM(expense)` computed in an Eloquent accessor `[CODE:
app/Models/Banking/Account.php:123]`. The closest thing to a chart of accounts is `categories`, a flat
tagging table. Double-entry ships as a **protected paid module**: `config/module.php:209` reads
`'protected' => explode(',', env('MODULE_PROTECTED', 'double-entry'))` and the `modules/` directory in
the public repo contains only `.gitkeep` `[CODE]`. Money is `double(15,4)` in every migration — zero
`decimal` columns — with PHP float arithmetic; bcmath is a hard composer requirement used in exactly
five places, all `bccomp` comparisons, never for arithmetic `[CODE]`. Tenancy is a single Eloquent
global scope with at least four documented ways to silently not apply, no FK on `company_id`, and no
RLS. There is no period close, no posting lock, no immutability, and no audit chain.

**Defining architectural idea.** *Ship a beautiful small-business cash book fast, and sell the
accounting.* Every architectural shortcut QAYD's principles forbid is present here, in QAYD's own
framework — which makes Akaunting the single most instructive negative example available.

---

## 1.5 Dolibarr

| | |
|---|---|
| **Origin** | 2001, France. ~25 years old. |
| **Licence** | GPL-3.0 `[CODE]`. |
| **Stack** | PHP ≥7.1 · MySQL/PostgreSQL/SQLite3 · no build step, no Composer at repo root (dependencies vendored). |
| **Target market** | European (esp. francophone) small business ERP/CRM. |
| **Demonstrated scale** | ~200,000 organisations / ~1M users estimated by the project `[COMMUNITY]`; 5M+ downloads. |

**Architecture in a paragraph.** 1.85M lines of PHP across 4,461 files, 410 tables, 95 modules
`[CODE]`. The defining fact: **the general ledger is a downstream export, not a primary store.**
Invoices, payments, and bank lines are the system of record; `llx_accounting_bookkeeping` is generated
on demand by 9,238 lines of per-journal page scripts (`sellsjournal.php` 1,888 lines, etc.) that read
business tables and synthesise entries, keyed back to source by `(doc_type, fk_doc, fk_docdet)`, and
deletable per year × journal `[CODE: htdocs/accountancy/journal/]`. Double-entry arrived as module
#50400 around v3.9 and is *mutually exclusive* with the original cash-basis module that still ships.
There are **zero CHECK constraints in 410 tables** and zero FKs on the bookkeeping tables; the balance
guard inside the transfer scripts is a post-hoc bug-catcher whose own error message reads "Surely a
bug", and manual journal entry persists unbalanced rows with only a warning `[CODE]`. Money is
`double(24,8)` — **zero DECIMAL/NUMERIC columns anywhere** — with native PHP float arithmetic; the four
bcmath references in the codebase are all commented out. Data access is 4,554 hand-concatenated SQL
strings and **one** prepared statement (in the driver itself) `[CODE]`.

**Defining architectural idea.** *The documents are the truth; accounting is a report you run.*
Extensibility is genuinely strong — 336 trigger names across 482 fire sites, 2,623 hook sites, modules
that inject SQL fragments into core queries — and that is precisely why core can never tighten an
invariant without breaking the ecosystem.

---

## 1.6 SAP S/4HANA

| | |
|---|---|
| **Origin** | 2015 (S/4HANA), on lineage back to R/2 (1979) and R/3 (1992). |
| **Licence** | Proprietary. |
| **Stack** | ABAP · SAP HANA in-memory column store · CDS virtual data model · Fiori. |
| **Target market** | Large enterprise and multinational groups. |
| **Demonstrated scale** | `[UNKNOWN]` — SAP publishes no ACDOCA row-count benchmarks or customer counts reachable in this research. `[COMMUNITY]` ~14,000 of ~35,000 ECC customers had moved by late 2024; ECC mainstream maintenance ends 2027, extended 2030. |

**Architecture in a paragraph.** The **Universal Journal (ACDOCA)** is one line-item table serving
FI-GL, Asset Accounting, Controlling, Material Ledger, and margin analysis. SAP's own words: *"the book
of original entry… and thus represents the single source of truth… Line items are written only once…
Totals are calculated on-the-fly when needed."* `[DOCS: help.sap.com S/4HANA Universal Journal]`
S/4HANA **deleted the aggregate and secondary-index tables** — GLT0, FAGLFLEXT, BSIS/BSAS/BSID/BSAD/
BSIK/BSAK, COSP, COSS — replacing them with read-only compatibility views that aggregate ACDOCA on the
fly and are explicitly a deprecation ramp with an expiry `[DOCS: Simplification List / Note 2270333]`.
The classic entry view (BKPF + BSEG) persists alongside the ledger view. **Document splitting** is the
crown jewel: it splits and auto-clears lines so that complete balanced financial statements exist per
segment, profit centre, or fund `[DOCS]`. Up to **10 currency fields — 2 preconfigured plus 8 freely
definable — per ledger**, verified verbatim in SAP's FAQ `[DOCS]`; BSEG stayed at 3, so the ten live in
the GL view only. Parallel accounting is native via ledgers, and **extension ledgers store only the
delta**, read as underlying + delta.

**Defining architectural idea.** *One line-item table, no materialised aggregates, compute everything
at read time on a column store.* Two corrections worth carrying: SAP publishes **no official ACDOCA
column count** (third-party counts range ~360–511, and the real number is release- and
customer-specific because SAP documents three supported ways to add columns) `[UNKNOWN]/[DOCS]`; and
the ideal has a documented escape hatch — **Deferred Summarization** moves line items out of ACDOCA
into ACDOCD *"for exceptionally large data volumes"*, at the documented cost of disabling analytics for
those postings `[DOCS]`.

---

## 1.7 Oracle NetSuite

| | |
|---|---|
| **Origin** | 1998 as NetLedger; acquired by Oracle 2016. |
| **Licence** | Proprietary SaaS. |
| **Stack** | Oracle database; proprietary application layer; SuiteScript (JavaScript) for extension. |
| **Target market** | Mid-market, especially multi-subsidiary groups. |
| **Demonstrated scale** | "43,000+ customers" is the circulating figure `[COMMUNITY]` — netsuite.com and oracle.com/news return HTTP 403 to automated fetch, so this was **not** confirmed against a primary source. |

**Architecture in a paragraph.** A three-tier transaction model: `Transaction` header →
`TransactionLine` (business lines) → `TransactionAccountingLine` (the GL lines, 1:N from a business
line) `[COMMUNITY — Oracle's own schema page was not reachable]`. **GL impact is computed, then
materialised**: it is a pipeline output of standard posting rules, keyed to the source transaction,
regenerated when the transaction is edited. The **Custom GL Lines Plug-in** is the architecturally
striking piece — a supported extension point that injects additional GL lines into a transaction's
posting, scoped per subsidiary and per accounting book, with debits and credits required to balance and
a 1,000-governance-unit budget `[DOCS]`. The GL is **dimensional rather than segment-coded**: Account ×
Subsidiary × Department × Class × Location × Custom Segment × Accounting Book, all visible per line on
the GL Impact page `[DOCS]`. **Multi-Book Accounting** produces *"multiple sets of accounting records
based on a single set of real-time financial transactions"* `[DOCS]` — N accounting standards fanned out
from one document. The **ledger is mutable**: in an open period a posted transaction can be edited and
NetSuite recomputes its GL impact, recording old→new in system notes; transactions can be deleted;
immutability comes from period locking and closing, both reversible by a permissioned user with a
justification note `[DOCS]`.

**Defining architectural idea.** *One live database for transactions and reporting, with the GL itself
as an extension point.* Tenant isolation mechanism: **`[UNKNOWN]`.** No Oracle documentation describing
it was found — not schema-per-tenant, not a discriminator column, nothing. This should be stated as
unknown rather than assumed.

---

## 1.8 Microsoft Dynamics 365 (F&O and Business Central)

Two genuinely different architectures under one brand; treating them as one product is the most common
error made about them.

| | **Finance & Operations** | **Business Central** |
|---|---|---|
| **Origin** | Axapta (1998) → AX → D365 F&O | Navision (1987) → NAV → BC |
| **Licence** | Proprietary | Proprietary, but **`microsoft/BCApps` publishes the System Application, Business Foundation, and the Base Application itself under MIT** — W1 plus 25 localization layers, built with the same public AL-Go pipelines Microsoft uses `[DOCS/CODE]` |
| **Stack** | X++, compiled to .NET/CLR; Azure SQL | AL language; Azure SQL |
| **Market** | Large / upper-mid enterprise | SMB and lower mid-market |
| **Scale** | `[UNKNOWN]` — Microsoft publishes no F&O customer count | `[COMMUNITY]` >40,000 (Jul 2024) → >45,000 (Apr 2025) → 50,000 (Nov 2025) → >55,000 (Apr 2026) |

**F&O in a paragraph.** A **source-document accounting framework**: business documents produce
accounting distributions, which produce subledger journal entries, which transfer to
`GeneralJournalAccountEntry` `[DOCS]`. Financial dimensions are not a segmented account string but a
surrogate key — `DimensionAttributeValueCombination` interns each unique combination of main account
plus dimension values, and ledger entries reference the combination `[DOCS]`. Account structures and
advanced rules validate which combinations are legal. `[INFERENCE — from the research]` the model has
partially retreated: unpivoted columns plus 50–100 mandatory indexes are a documented concession that
the pure normalised dimension model does not read fast enough, which argues for designing the wide
projection from day one. **Electronic Reporting (ER)** is a metadata-driven engine for regulatory
document formats — a format is configuration, not code — across roughly 54 localizations `[INFERENCE:
count is mine, not Microsoft's]`. Security includes **Extensible Data Security (XDS)**, genuine
app-layer row-level policies. **Overlayering was banned in platform 8.0 (announced 2017)**; extension is
by event handlers and chain-of-command only `[DOCS]`.

**BC in a paragraph.** Posting runs through the `Gen. Jnl.-Post Line` codeunit — the "posting routine"
pattern, one blessed procedure per ledger. `G/L Entry` is famously **not deletable**; corrections are
new entries. The dimension model is the design most worth stealing: **`Dimension Set Entry` /
`Dimension Set Tree Node` is a prefix trie, not a hash** — keyed `(Parent Dimension Set ID, Dimension
Value ID)`, so an arbitrary set of dimension values interns to a single `Dimension Set ID` stored on the
entry, and the write lock is taken **only at the point of divergence** in the trie rather than over a
global hash bucket `[DOCS/COMMUNITY]`. (F&O is the one that hashes — a 160-bit digest built from GUIDs.)
**Posting groups** decouple documents from accounts through a configuration matrix: General Business
Posting Group × General Product Posting Group → General Posting Setup → accounts, so "which account
does a sale to a domestic customer of a taxable item hit?" is a lookup, not code `[DOCS]`.

**Defining architectural idea.** F&O: *accounting is generated from source documents by policy, not
typed by users.* BC: *one posting routine, immutable entries, and configuration matrices between
documents and accounts.*

---

# Part 2 — Subsystem-by-subsystem comparison

Scores and claims below carry the same evidence tiers. `n/a` means the subsystem does not exist in that
system at all.

## The architecture-philosophy spectrum

Everything in Part 2 is downstream of one axis: **where does the truth about the system's shape live —
in rows, or in code?**

```
  METADATA-DRIVEN                        HYBRID                         CODE-DRIVEN
  (schema is data;                   (fixed core schema,            (schema is migrations;
   DDL at runtime)                    declarative periphery)         behaviour is compiled)
      │                                     │                                  │
      ▼                                     ▼                                  ▼
 ┌──────────┐  ┌──────────┐  ┌──────────┐ ┌──────────┐ ┌─────────┐ ┌────────┐ ┌──────────┐
 │ ERPNext  │  │ NetSuite │  │   Odoo   │ │   SAP    │ │  D365   │ │ QAYD   │ │ Dolibarr │
 │ /Frappe  │  │          │  │          │ │ S/4HANA  │ │ BC/F&O  │ │        │ │Akaunting │
 └──────────┘  └──────────┘  └──────────┘ └──────────┘ └─────────┘ └────────┘ └──────────┘
  ALTER TABLE   custom recs   models are   ACDOCA is    fixed tbls   fixed       fixed tables,
  on save;      /segments/    Python but   fixed +      + XDS/ER/    tables +    logic in
  no FKs;       fields, all   reports,     3 sanctioned dimension    DB triggers page scripts
  Python-       metadata;     taxes, dims  extension    combos as    + typed     / Eloquent
  enforced      one version   are DATA     tiers        surrogates   Actions     jobs
  integrity     for all

  ◄──────────── customer can reshape the schema ──────────── vendor/dev owns the schema ────────►
  ◄──────────── integrity enforced in application ─────────── integrity enforced in DB ─────────►
```

Two observations that shape every recommendation in this document:

1. **The two axes are not the same axis, but they correlate almost perfectly.** The more a system lets
   a customer reshape the schema, the less its database can guarantee. ERPNext cannot declare a foreign
   key because it does not know at design time what the columns will be. SAP can enforce a great deal
   because ACDOCA's shape is SAP's. `[INFERENCE]`
2. **The far right of this spectrum contains both the strongest and the weakest systems here.** QAYD and
   Dolibarr are both "fixed tables, logic in code" — the difference is entirely *which* code and
   *whether the database checks it*. Being code-driven buys nothing on its own. **QAYD's position is
   earned by the triggers and constraints, not by the absence of metadata.**

---

## 2.1 Double-entry core & posting

| | Posting path | Balance enforced by | Tolerance | Auto-plug on imbalance |
|---|---|---|---|---|
| **QAYD** | One service, no bypass `[CODE]` | **DB CHECK + service re-derivation from lines** | **Zero, in both entry and base currency** `[CODE]` | No — rejects |
| Odoo | One ORM path | Python context manager; CHECK on cached totals | Float epsilon | **Yes** — suspense line |
| ERPNext | One function, `make_gl_entries` `[CODE]` | Python only | **0.5 currency units** for non-JE vouchers `[CODE]` | **Yes** — Round Off GL Entry |
| Akaunting | n/a — **no double entry in core** `[CODE]` | n/a | n/a | n/a |
| Dolibarr | 9,238 lines of per-journal page scripts `[CODE]` | Post-hoc PHP guard; **none for manual entry** | — | No; persists unbalanced with a warning |
| SAP | Posting keys + document splitting `[DOCS]` | `[UNKNOWN]` — no SAP statement of the runtime zero-balance rule was found; `[INFERENCE]` it is enforced per ledger | — | Splitting creates **clearing** lines by design |
| NetSuite | Computed GL impact + plug-ins `[DOCS]` | Plug-in lines **must balance** `[DOCS]` | — | `[UNKNOWN]` |
| D365 F&O / BC | Subledger journalization / codeunit 12 `[DOCS]` | Application | — | `[UNKNOWN]` |

**Strongest implementation: SAP,** and not because of balance checking. Document splitting is a
capability nobody else here has: it produces *complete, balanced* financial statements at
segment/profit-centre/fund level by generating clearing lines automatically, which is a genuinely hard
problem solved as a first-class primitive `[DOCS]`.

**QAYD should outperform on strictness, and already does.** Zero tolerance in both currencies with a DB
CHECK backstop is stricter than every system studied. ERPNext tolerating **half a currency unit** and
plugging the residual is the sharpest illustration of why: that is a rule that can be relaxed, and
QAYD's cannot.

**QAYD should deliberately ignore** the auto-balancing plug as a default (Odoo, ERPNext) — an
unbalanced entry is a data-quality signal, not something to absorb. And it should ignore Dolibarr's
model entirely: a GL derived on demand from documents is a category error for a system that intends to
be the book of record.

**Where QAYD can innovate.** Aggregate every validation failure into one structured `ValidationReport`
with `violations[]` of `{code, field, actual, expected}`. Odoo has the instinct (it collects failures)
but returns a newline-joined string. For an AI drafting entries, this is the difference between one
round trip and N — and it is three points of work.

---

## 2.2 Chart of accounts

| | Structure | Hierarchy | Country templates |
|---|---|---|---|
| **QAYD** | `accounts` with `account_type_id`, `normal_balance` CHECK, `UNIQUE(company_id, code)` `[CODE]` | Real `parent_id` FK with anti-self-parent CHECK `[CODE]` | **None yet** |
| Odoo | Code in per-company JSONB; uniqueness in Python | Roots derived by **slicing the code string** | Many |
| ERPNext | `tabAccount`, `root_type`/`report_type`/`account_type` (33 roles) | **Nested set** (`lft`/`rgt`) `[CODE]` | **68 verified** + 25 unverified `[CODE]` |
| Akaunting | n/a — `Account` is a bank account; `categories` is the nearest thing `[CODE]` | `parent_id` on categories | n/a |
| Dolibarr | `llx_accounting_account` | Flat + report-category tables | French/EU-centric |
| SAP | Operating / group / country CoA; cost element **merged into** the G/L account in S/4 `[DOCS]` | Financial statement versions | Extensive |
| NetSuite | Few accounts + many segments `[DOCS]` | Account hierarchy | Per-edition |
| D365 BC | Accounts + **posting-group matrix** `[DOCS]` | G/L account categories | 25 localization layers `[DOCS]` |

**Strongest: ERPNext,** on breadth and on the three-field decomposition. Separating `root_type`
(accounting nature) from `report_type` (which statement) from `account_type` (33 behavioural roles like
Receivable, Stock, Round Off) is a clean model that lets modules *find* their accounts rather than
having them configured, and 68 verified country charts is a real moat. The nested set makes a
group-account balance a single indexed range query.

**QAYD should outperform on integrity.** `UNIQUE(company_id, code)` under RLS is both correct and
simple; Odoo's per-company JSONB codes with Python-only uniqueness is a defect QAYD structurally cannot
have. A real `parent_id` beats deriving hierarchy from string slices — grouping a report by hierarchy
should be a join.

**QAYD should deliberately ignore** the nested set for now. `lft`/`rgt` makes every insert rewrite the
tree; a recursive CTE over `parent_id` is fast enough for a chart of accounts at any realistic size,
and it does not need a `rebuild_tree` maintenance path.

**Where QAYD can innovate.** BC's **posting groups** are the most portable idea in this row and QAYD has
nothing like it: a Business Posting Group × Product Posting Group matrix resolving to accounts means a
new tax treatment or a new customer class is configuration, not a code change `[DOCS]`. Adopt it before
the first sales module hard-codes an account id. Second: QAYD's `is_control_account` /
`control_account_of` columns already exist `[CODE]` — enforce the sub-ledger rule (a line hitting AR/AP
must carry a party) in the database once `journal_lines` carries `customer_id`/`vendor_id`; TD-15
records that this is not yet enforceable.

---

## 2.3 Ledger storage & balances

| | Ledger store | Mutable? | Stored balances |
|---|---|---|---|
| **QAYD** | `ledger_entries`, 1:1 projection of posted lines, `signed_base_amount` `[CODE]` | **No — DB trigger rejects UPDATE/DELETE for every role** `[CODE]` | **None yet** (planned rollup) |
| Odoo | **No ledger table** — journal lines are the GL | Yes; written by raw SQL in ≥2 places | **None anywhere** |
| ERPNext | `tabGL Entry` + Payment Ledger + Advance Payment Ledger | Cancellation flags `is_cancelled` and inserts a mirror reversal; true immutability is an **opt-in setting** `[CODE]` | `Account Closing Balance`, materialised **only at period close** `[CODE]` |
| Akaunting | n/a | — | — |
| Dolibarr | `llx_accounting_bookkeeping`, a re-derivable projection | **Yes** — `update()`, `delete()`, `deleteByYearAndJournal()` `[CODE]` | Dead `llx_accounting_balance_snapshot` table, no code reads it `[CODE]` |
| SAP | ACDOCA | Journal entries not editable; corrections are reversals | **Deliberately none** — aggregates deleted; `[DOCS]` Deferred Summarization is the escape hatch at extreme volume |
| NetSuite | `TransactionAccountingLine` | **Yes** — editing a posted transaction recomputes GL impact `[DOCS]` | `[UNKNOWN]` |
| D365 BC | `G/L Entry` | **No** — entries not deletable `[DOCS]` | Fixed-table + index strategy |

**Strongest: a tie between SAP and BC, for opposite reasons.** SAP proved that deleting every aggregate
table and computing balances on a column store is viable at enterprise scale — and then documented its
own limit. BC proved that a genuinely non-deletable entry table with sequential entry numbers is
operable for decades across tens of thousands of customers.

**QAYD is already ahead here and must not regress.** An append-only ledger enforced by a trigger, with a
`UNIQUE(journal_line_id)` making the projection exact and idempotent, is stronger than Odoo, ERPNext,
Dolibarr, and NetSuite. The specific thing to protect: **`journal_lines.reconciled` is a boolean column
today** `[CODE: _000005]`. That is exactly the decision that forced Odoo's ledger to be mutable. It is
harmless while nothing writes it; the moment reconciliation is built, matching state must go into side
tables keyed to `ledger_entry_id`, and this column should be dropped rather than used.

**QAYD should deliberately ignore** SAP's "no stored aggregates ever" purity. SAP can afford it on a
column store and still had to add Deferred Summarization; QAYD is on row-store PostgreSQL.

**Where QAYD can innovate — the largest single win available.** `account_period_balances (company_id,
account_id, period_id, opening, debit, credit, closing)` with `CHECK (closing = opening + debit −
credit)`, maintained by an `AFTER INSERT` trigger on `ledger_entries`. The trigger is **monotonic
because the source is append-only** — it can only ever increment — so the cached aggregate is
trustworthy in a way an aggregate over a mutable table never is. ERPNext materialises balances only at
period close and computes everything else by scan; Odoo computes everything by scan. QAYD can have an
always-current rollup *only because* it chose the append-only ledger. Ship a `RebuildPeriodBalancesAction`
as a CI and scheduled drift detector.

---

## 2.4 Fiscal calendar & period close

| | Period model | Enforcement | Close is |
|---|---|---|---|
| **QAYD** | `fiscal_years` only; **`fiscal_periods` not built** `[CODE: TD-13]` | Service-level via `FiscalCalendarResolver` seam, locking the fiscal-year row | Not built |
| Odoo | **No year and no period table** — lock-date cursor, 5 axes | O(1) date comparison, no lock at all | Three button presses + a chatter note |
| ERPNext | Fiscal Year + Accounting Period + `accounts_frozen_till_date` + Period Closing Voucher | Python, at posting and on document save `[CODE]` | **A real posting** (PCV) plus a hard date gate |
| Akaunting | **None** — financial year is a reporting window only `[CODE]` | None | Does not exist |
| Dolibarr | `llx_accounting_fiscalyear` + a config switch that changes what "locked" means + row-level `date_validated` | PHP, three loosely-coupled mechanisms; reopening is a supported admin action `[CODE]` | Status flag + closing entries |
| SAP | Fiscal year variant with per-period month/day limits and year shift; special periods | Open/Close Posting Periods per legal entity | **Advanced Financial Closing** — a separate product with task lists, dependency graphs, statuses `[DOCS]` |
| NetSuite | Accounting periods; **lock is per module per subsidiary**, close is separate `[DOCS]` | Permission-gated; reopen captures a justification note `[DOCS]` | Period Close Checklist `[DOCS]` |
| D365 | Ledger calendars; period status per module `[UNKNOWN: no page confirming]` | Application | Financial period close workspace / Close Income Statement |

**Strongest: SAP,** by a distance. Advanced Financial Closing is a real product — templated task lists,
dependency graphs, statuses, automated job parametrisation `[DOCS]`. Nothing else here treats "closing
the books" as a workflow with owners and evidence. NetSuite's **lock-versus-close distinction** (lock
A/P, A/R, Payroll independently, per subsidiary, before closing) is the second-best idea in this row and
much cheaper to copy.

**QAYD is behind and this is table stakes.** Fiscal-year-granular gating is not a period close. A buyer
evaluating an accounting system will ask "how do I close a month?" in the first meeting.

**QAYD should deliberately ignore** two things: Odoo's *absence* of period records (the O(1) cursor is
superior for enforcement but leaves nowhere to record who closed what, against which trial balance, with
whose approval — disqualifying for an AI-first ledger where an agent proposes and a human disposes), and
Dolibarr's config-switch-changes-the-semantics-of-locked pattern, which is two behaviours behind one
constant.

**Where QAYD can innovate.** Take the hybrid: build `fiscal_periods` as a **dimension**, keep
**enforcement in a `fiscal_locks` cursor with a database trigger**, and make `fiscal_periods.status` a
**VIEW over the cursor** rather than an independently writable column. One source of truth, two
representations. Add NetSuite's per-module lock axis, Odoo 19's time-boxed audited `lock_exceptions`
(hardened: `reason NOT NULL`, mandatory `end_at` with a ceiling, and a DB guarantee that a *hard* lock
can never be excepted), and a `period_close_runs` table with a four-eyes CHECK — the auditable close
record Odoo structurally lacks and SAP charges for.

---

## 2.5 Reconciliation

| | Bank rec | Residual/matching state | Unmatch |
|---|---|---|---|
| **QAYD** | **Not built** | — | — |
| Odoo | Enterprise-only evaluator; Community has the model | `amount_residual` **derived** from partial rows — but stored **on the ledger row** | **DELETE** the partials |
| ERPNext | Bank rec + Payment Ledger subledger | Payment Ledger with a `delinked` flag | Flag |
| Akaunting | Tick-off worksheet; difference **displayed, not enforced**; `reconciled=1` does not lock the row `[CODE]` | Boolean | Untick |
| Dolibarr | `rappro` flag + **operator-typed** statement label `num_releve` `[CODE]` | Boolean | Guarded — reconciled bank lines genuinely cannot be deleted `[CODE]` |
| SAP | **Electronic Bank Statement with interpretation algorithms** analysing the note-to-payee, external transaction types → posting rules, country-specific algorithms `[DOCS]` | **Clearing documents** with reason codes; partial vs residual are distinct `[DOCS]` | Reset clearing |
| NetSuite | Native matching engine + pluggable connectivity (Bank Feeds / ABSI SuiteApps) `[DOCS]` | `[UNKNOWN]` | `[UNKNOWN]` |
| D365 F&O | Advanced bank reconciliation with MT940/BAI2 import and matching rules `[DOCS]` | Ledger settlements | Reverse settlement |

**Strongest: SAP.** The clearing-document model — a partial payment splits an open item into two, with
distinct partial-payment and residual-item semantics, and reason codes recording *why* a difference
exists — is the most complete treatment here, and the EBS interpretation algorithms go to a level of
specificity (a named algorithm for Polish KSeF reference numbers) that reveals how much regulatory
surface area SAP has absorbed `[DOCS]`.

**QAYD should outperform on immutability.** Odoo's decision to put `amount_residual`/`reconciled`/
`full_reconcile_id` **on the ledger row** is the single choice that forces its GL to be mutable, drives
its raw-SQL writes, and produces a documented staleness bug. QAYD's append-only trigger *forbids*
Odoo's approach outright, which turns a constraint into an advantage: matching state lives in side
tables keyed to `ledger_entry_id`, and **unreconciling is an INSERT, not a DELETE** — a compensating link
row plus, where money moved, a reversing entry through the posting service.

**QAYD should deliberately ignore** Akaunting's and Dolibarr's boolean-flag reconciliation. A tick-off
worksheet where the difference is displayed rather than enforced is not a control.

**Where QAYD can innovate — this is the clearest commercial edge.** Three strict tiers: (1) deterministic
rules — exact reference and amount, no AI; (2) **AI proposals written to a `match_proposals` table with
confidence and reasoning, never to the ledger**; (3) human confirmation promotes a proposal into a real
reconciliation. Deterministic-first keeps the AI honest — it only ever sees what rules could not settle.
Adopt Odoo's public **suspense-account invariant** (import the bank line to suspense immediately so the
bank balance is correct *before* matching, and the suspense balance is exactly the unmatched backlog).
Note the competitive landscape: Odoo's matching evaluator is **Enterprise-only and closed**, and
NetSuite's is native but opaque — this is a subsystem where an open, explainable matcher is
differentiating.

---

## 2.6 Financial reporting

| | Statements | How computed | Real-time |
|---|---|---|---|
| **QAYD** | **None built** | — | — |
| Odoo | **Engine is Enterprise-only**; Community has no TB, GL, BS, or P&L | Declarative model, regex-parsed string formulas, float money, no cycle detection | Full scan |
| ERPNext | Full set | **Fetches GL rows into Python and aggregates there** `[CODE]`; PCV closing balances used as a seek optimisation | Live, then Prepared Report → DuckDB offload `[CODE]` |
| Akaunting | 6 reports, **no trial balance, no balance sheet, no GL** `[CODE]` | Eloquent collections summed in PHP arrays | Live |
| Dolibarr | Trial balance + P&L via runtime `SUM()`; snapshot table exists but is dead code `[CODE]` | Full-table aggregation every time | Live |
| SAP | Complete, plus Group Reporting (ACDOCU) and plan data (ACDOCP) | **CDS virtual data model** over ACDOCA; compatibility views aggregate on the fly `[DOCS]` | Yes — with the caveat that legacy Report Painter reports **do not** see combined data `[DOCS]` |
| NetSuite | Financial Report Builder, saved searches, SuiteAnalytics Workbook, + NSAW warehouse | Live against the operational DB (NSAW refreshes ≥hourly) `[DOCS]` | **Yes — no ETL lag** |
| D365 | Financial Reporter + **Electronic Reporting** for regulatory formats `[DOCS]` | ER formats are metadata | Mixed |

**Strongest: SAP,** for the CDS virtual data model — a layered, naming-conventioned (`I_`/`C_`/`R_`/`P_`/
`A_`) semantic layer where authorisation is modelled *at the view layer* rather than bolted on `[DOCS]`.
NetSuite's "one live database, no warehouse reconciliation problem" is the strongest practical property
in the row and the one QAYD inherits for free by being small.

**QAYD should outperform on trustworthiness of the numbers.** Two structural advantages: money is
`NUMERIC(19,4)` with bcmath, so no statement can drift by float accumulation (ERPNext, Akaunting, and
Dolibarr all sum money in application-language floats `[CODE]`); and once `account_period_balances`
exists, a trial balance is a small indexed scan rather than a scan of the largest table.

**QAYD should deliberately ignore** building a generic report engine first. Odoo's regex formula grammar
is what you get when the engine is designed against imagined requirements. **Build Trial Balance, then
P&L, then extract the engine** — and when extracting it, use typed operand *rows*, not formula strings,
with cycle detection by recursive CTE at publish time.

**Where QAYD can innovate.** Two cheap structural guarantees nobody here has: a cash-flow bucket as a
`NOT NULL` CHECK-constrained **total, disjoint partition** on `accounts`, making the reconciliation
identity structurally impossible to violate (Odoo uses optional multi-valued tags and its own test suite
contains an account in two buckets at once); and a balance sheet that balances mid-year without a
closing ritual, via an `equity_unaffected`-style account excluded from carry-forward — which, on a signed
append-only ledger, becomes a structural theorem rather than a runtime check.

---

## 2.7 Tax engine

| | Model | Withholding | Regulatory formats |
|---|---|---|---|
| **QAYD** | `journal_lines.tax_amount` column only; **no engine** `[CODE]` | No | No |
| Odoo | **Tax repartition lines** — one tax split across lines with %, accounts, tags, separately for invoice vs refund | Via repartition | Reconstructs base↔tax at report time in ~450 lines of SQL with an explicit *"approximate"* branch |
| ERPNext | **Ordered template rows**, evaluated top-to-bottom; `charge_type` ∈ {On Net Total, On Previous Row Amount/Total, Actual, On Item Quantity}; `Actual` pro-rated across items by net share `[CODE]` | **Yes** — threshold-based Tax Withholding Category with dated rate bands `[CODE]` | Regional overrides by **monkey-patching dotted paths** keyed on country `[CODE]`; India GST was **extracted to a separate app** |
| Akaunting | 5 types (normal/fixed/inclusive/compound/withholding) in a hardcoded sequence; withholding persisted as `abs()` so the sign is lost `[CODE]` | Partially | No |
| Dolibarr | `llx_c_tva` per country + `localtax1/2` (varchar holding numbers); one 16-positional-parameter function that defends against its own callers at runtime `[CODE]` | Via localtax | French FEC export |
| SAP | Condition technique + certified **external engines** via the Tax Interface System `[DOCS]` | Yes | **Document and Reporting Compliance** — per-country e-invoicing and statutory reporting, with its own page per country `[DOCS]`; count `[UNKNOWN]` |
| NetSuite | **SuiteTax** (pluggable engine + country bundles, monthly rate updates for ~180 countries) vs **Legacy Tax**; SuiteTax is account-wide and **irreversible**, with no published sunset for Legacy `[DOCS]` | Via bundles | Via SuiteApps |
| D365 | Tax Calculation Service + **Electronic Reporting** for formats `[DOCS]` | Yes | ~54 localizations `[INFERENCE]` |

**Strongest: SAP,** and it is not close. This is the subsystem where thousands of person-years of
regulatory minutiae are the product. The evidence is structural rather than promotional: DRC ships a
per-country implementation, and bank-statement interpretation goes down to a named algorithm for Polish
KSeF reference numbers `[DOCS]`.

**QAYD should outperform on one specific thing: the audit trail of the tax itself.** Odoo *reconstructs*
the base↔tax relationship at report time in a query containing the word "approximate". For a GCC filing
that carries legal liability, that word should not appear in the implementation. **Persist
`journal_line_tax_links` and `journal_line_tax_boxes` at post time**, when the relationship is known
exactly, and the VAT return becomes a `GROUP BY` on an indexed table.

**QAYD should deliberately ignore** breadth. Do not chase 180 countries or 54 localizations. Kuwait,
Saudi (ZATCA), UAE, and Bahrain done exactly is worth more than fifty done approximately, and breadth is
the single most expensive thing on this page for a small team.

**Where QAYD can innovate.** Adopt Odoo's **repartition** shape (one tax splitting across multiple
accounting lines with percentages, target accounts, and reporting tags, separately for invoices and
refunds) — it turns reverse charge, partial deductibility, and withholding from code into configuration,
which is the difference between supporting one VAT regime and supporting the GCC's several. Then harden
it beyond Odoo: promote the ±100% invariant from an application constraint to a **deferred constraint
trigger**, and store factors as **integer ppm** rather than floats. Learn from ERPNext's ordered-row
model that ordering-as-logic is fragile, and from Akaunting that persisting a withholding amount as
`abs()` loses information you will need.

---

## 2.8 Multi-currency

| | Amounts stored per line | Missing-rate behaviour | FX revaluation |
|---|---|---|---|
| **QAYD** | Entry + base, with `exchange_rate` on both header and line, `CHECK (exchange_rate > 0)` `[CODE]` | n/a (no rate table yet) | No |
| Odoo | Company + foreign | **Falls back to the earliest rate, then to `1.0` — converts at par silently** | **Absent from Community** |
| ERPNext | **Four layers**: company, account, transaction, reporting `[CODE]` | **Throws** if no reporting-currency rate exists `[CODE]`; staleness gate; pegged-currency support; can fetch a rate over HTTP inside the posting path `[CODE]` | **Yes** — `Exchange Rate Revaluation` document |
| Akaunting | `currency_rate double(15,8)` per document and transaction; conversion failure **returns 0** `[CODE]` | Silent zero | No |
| Dolibarr | 7 multicurrency columns duplicated across 19 tables — and **zero references in any journal script**; the ledger's `multicurrency_amount` column is never populated `[CODE]` | — | **None** |
| SAP | **10 currency fields per ledger = 2 preconfigured + 8 freely definable** `[DOCS, verified verbatim]`; BSEG stayed at 3 | — | `FAGL_FCV` with delta logic and `FCV_STATUS` `[DOCS]` |
| NetSuite | Base currency per subsidiary, per book with Parallel Currencies; **Current/Average/Historical** consolidated rate types + CTA `[DOCS]` | `[UNKNOWN]` | Yes |
| D365 | 3 amounts per entry (transaction / accounting / reporting) `[DOCS]` | `[UNKNOWN]` | Yes |

**Strongest: SAP,** on the ledger-dependent freely-definable currency model — ten currency fields per
ledger, configured per company code, is a capability that maps directly onto real multinational
requirements. **NetSuite is strongest on consolidation semantics**: Current/Average/Historical rate types
with a Cumulative Translation Adjustment account existing *specifically* because mixed rate types
unbalance a consolidated balance sheet is a correct and well-explained design `[DOCS]`.

**QAYD should outperform on refusing to guess.** Odoo's silent fallback to `1.0` is the highest-severity
defect found anywhere in its currency handling; Akaunting's `return 0` on a conversion exception is
worse. QAYD must raise `RATE_MISSING_FOR_DATE` and never extrapolate — ERPNext already does this for its
reporting currency and it is the right posture.

**QAYD should deliberately ignore** Dolibarr's approach of duplicating the entire money model across
nineteen tables and then never wiring it to the ledger. And it should not fetch rates over HTTP inside a
posting transaction, as ERPNext can `[CODE]`.

**Where QAYD can innovate.** Rates as dated rows with a **GiST exclusion constraint on validity
windows**, so overlapping windows are structurally impossible; `rate_type` (spot/closing/average/customs)
and `source` provenance; **`rate_unit` (1/100/1000)** to handle weak currencies without a schema change
and to match how central banks actually publish; and **immutability once a rate is referenced by a posted
entry**. Note for anyone porting a formula: **Odoo's rate convention is the inverse of QAYD's** — two
inversions cancel, which is a trap that catches every integration exactly once.

---

## 2.9 Audit & compliance

| | Audit log | Tamper evidence | Posted-entry immutability |
|---|---|---|---|
| **QAYD** | `audit_logs`, append-only, JSONB old/new + `changed_fields TEXT[]`, `hash`/`prev_hash` columns **present but dormant** `[CODE: TD-06]` | Designed, not active | **Yes, by DB trigger** `[CODE]` |
| Odoo | Chatter tracking keyed to field ids — unreadable once a field is dropped; admin-deletable | SHA-256 chain over posted entries, **unkeyed, empty-string genesis**, enforced in Python only, with three context-keyed bypasses; allowlist omits `amount_currency` and analytic distribution | Application-level |
| ERPNext | `tabVersion` — **opt-in per DocType**, ordinary rows, ordinary permissions, no chain `[CODE]` | None | **Opt-in** (`enable_immutable_ledger`); default flags and hides `[CODE]` |
| Akaunting | `document_histories` for invoice status only; **transactions have no history table at all** `[CODE]` | **None** | None |
| Dolibarr | `llx_blockedlog` — a **genuine HMAC-SHA256 chain** with `FOR UPDATE` chain extension and a verification UI `[CODE]` | **Real** — and it covers **invoice/payment lifecycle events, never the ledger**; `llx_accounting_bookkeeping` has no signature column `[CODE]` | Inconsistent — `date_validated` guards 2 of 6 write methods `[CODE]` |
| SAP | Entry view preserved alongside GL view; clearing documents with reason codes | Extensive controls | Corrections are reversals |
| NetSuite | **System Notes** — line-level, records role, old/new, and **GL impact before/after**; stated non-editable `[DOCS]` | Strong log, weak ledger | **No** — posted transactions are editable and deletable in an open period `[DOCS]` |
| D365 BC | — | — | **Yes** — G/L entries not deletable `[DOCS]` |

**Strongest: NetSuite for the log, BC for the ledger, Dolibarr for the cryptography.** NetSuite's
system notes recording the *GL impact before and after* an edit is genuinely excellent forensics.
Dolibarr's blockedlog is the best-engineered hash chain read in this study — HMAC-SHA256 with a keyed
secret, serialised chain extension under `FOR UPDATE`, two algorithm generations, a verification UI —
built for the French *loi anti-fraude*. Its limitation is scope, not craft.

**QAYD is already ahead on the property that matters most and must not regress:** a ledger that cannot be
mutated by anyone, enforced below the application. NetSuite is the instructive contrast — a
**mutable-ledger + immutable-audit-log** design. That is materially easier to operate and materially
weaker as an integrity story, and it is the sharpest single architectural contrast in this document.

**QAYD should deliberately ignore** bypass tokens in any form. Odoo has three context-keyed escapes from
its own seal and its test suite clears one with an ordinary write; ERPNext's immutability is a setting.
An invariant a caller can switch off is not an invariant.

**Where QAYD can innovate.** Activate the dormant chain, over `ledger_entries` rather than mutable
business fields, and fix all four of Odoo's weaknesses at once: enforce it in a `BEFORE INSERT`
**trigger** (Odoo has no triggers at all); no bypasses; add **externally signed periodic anchors**,
because an unkeyed chain with an empty genesis can be rewritten end-to-end undetectably; and persist a
**canonical payload** so verification is a pure function of audit rows and a field-set change is not a
breaking migration. Version the digest in-band (`$4$<hex>`-style) so the algorithm can evolve. This is
cheap for QAYD *precisely because* `ledger_entries` is append-only — the chain can cover every column,
not an allowlist, and can never go stale. And it maps directly onto **ZATCA Phase 2, which requires
invoice hash chaining** `[COMMUNITY]` — the compliance work and the integrity work are the same work.

Add one thing nobody here has: **`posting_attempts`**, an append-only record of *rejected* postings with
violation codes, source, and AI confidence. It is simultaneously a compliance artifact ("show me every
attempt to post into a closed period") and the highest-quality training signal available.

---

## 2.10 Analytic / dimensional accounting

| | Model | Storage | Adding a dimension |
|---|---|---|---|
| **QAYD** | **Not built**; spec currently plans fixed FK columns `[CODE: TD-14]` | — | Would be a migration |
| Odoo | N-dimensional **plans** with percentage allocation | **JSONB** keyed by comma-joined id strings — no referential integrity; money **cannot be aggregated** over it; and Odoo materialises it into rows anyway, with two-way sync flags | Runtime `ALTER TABLE` |
| ERPNext | Accounting Dimensions | **Real columns, added at runtime** — creating one dimension issues ~45 `ALTER TABLE`s including one on `tabGL Entry` `[CODE]` | Online DDL on the ledger |
| Akaunting | n/a | — | — |
| Dolibarr | n/a (analytic accounting is thin) | — | — |
| SAP | Coding block + document splitting characteristics; CO-PA characteristics | Columns on ACDOCA; **customer extension is a documented, supported feature** `[DOCS]` | Sanctioned extension |
| NetSuite | Subsidiary/Department/Class/Location + **unlimited Custom Segments** reaching the GL line, with **Balancing Segments** `[DOCS]` | `[UNKNOWN]` physically | Metadata declaration |
| D365 F&O | Main account + **`DimensionAttributeValueCombination`** surrogate key; account structures and advanced rules validate combinations `[DOCS]` | Interned combination | Configuration |
| D365 BC | **`Dimension Set Entry` / `Dimension Set Tree Node` — a prefix trie** keyed `(Parent Dimension Set ID, Dimension Value ID)`, interning a whole set to one `Dimension Set ID` on the entry `[DOCS/COMMUNITY]` | Interned set | Configuration |

**Strongest: Business Central,** and this is the most valuable single design in the entire comparison.
Interning an arbitrary set of dimension values into one `Dimension Set ID` means the ledger row stays
narrow regardless of how many dimensions exist, and adding a dimension is configuration rather than DDL.
The **trie** is better than a hash for a specific reason worth internalising: the write lock is taken
only at the **point of divergence** in the tree, so concurrent posting of different dimension sets
contends only where the sets actually differ — whereas a hash-bucket approach serialises on the digest.
F&O's `DimensionAttributeValueCombination` is the same idea with a 160-bit hash instead.

**QAYD has not yet made this decision, and it is the most time-sensitive item in this document.**
Deciding today costs nothing; deciding after `journal_lines` has millions of rows costs a migration on
the largest table in the system.

**QAYD should deliberately ignore both of the obvious options.** Reject fixed FK columns (a fourth
dimension — Fund, Grant, Vessel, Store — becomes a migration and a deploy, a per-customer schema fork by
another name; and "60% Project A / 40% Project B" is inexpressible without splitting the journal line).
Reject Odoo's JSONB (no referential integrity, no CHECK can express "sums to 100%", and money cannot be
aggregated over it — the subsystem's primary analytical query is not expressible against its primary
storage). Reject ERPNext's runtime DDL outright: 45 `ALTER TABLE`s per dimension, one of them on the
general ledger, is disqualifying for a migration-driven system.

**Where QAYD can innovate.** Store allocations as **rows** in a `journal_line_dimensions` child table
with a composite FK `(member_id, dimension_id)` so "this member belongs to the declared dimension" is a
database guarantee, and a `DEFERRABLE INITIALLY DEFERRED` constraint trigger for the 100%/amount
invariants. Then borrow BC's insight on top: for the common case where a line carries a *set* of
dimension values with no percentage split, intern the set. The decisive evidence that rows are right:
**Odoo already materialises its JSONB into rows and keeps two-way sync** — it pays the row cost anyway.

---

## 2.11 Multi-tenancy & security model

| | Isolation mechanism | Enforced where | Bypass |
|---|---|---|---|
| **QAYD** | `company_id` + **`FORCE ROW LEVEL SECURITY`**, RESTRICTIVE boundary policy, `NOSUPERUSER NOBYPASSRLS` runtime role, fail-closed GUC `[CODE]` | **PostgreSQL** | **None by design** — `is_platform_admin` is deliberately not wired to any policy bypass `[CODE: TD-04]` |
| Odoo | `ir.rule` domains | Application; **writes/deletes evaluated in Python over hydrated rows; creates checked after INSERT** | `sudo()` — transitive, 552 call sites |
| ERPNext | **One database per site** `[CODE]` | Connection boundary | Strong isolation, but 567 raw-SQL sites bypass the *permission* layer `[CODE]` |
| Akaunting | One Eloquent global scope | Application | At least 4 silent non-application paths, incl. a model omitting `company_id` from `$fillable` becoming globally unscoped with no error `[CODE]` |
| Dolibarr | An `entity` column on 163 of 410 tables — but the resolver **collapses to single-tenant** unless the external commercial Multicompany module injects `$mc` `[CODE]` | Per-query discipline across 4,554 queries | Accounting explicitly opts out 20 times `[CODE]` |
| SAP | Client (`MANDT`) + delivery classes A/C/L (client-dependent) vs S (client-**independent**) `[DOCS]` | Database + ABAP layer | S/4HANA Cloud cross-customer physical isolation: `[UNKNOWN]` |
| NetSuite | `[UNKNOWN]` — **no Oracle documentation of the isolation mechanism was found** | — | — |
| D365 F&O | `DataAreaId` legal-entity discriminator + **Extensible Data Security (XDS)** row-level policies `[DOCS]` | Application | — |
| D365 BC | Per-environment tenancy; 3 TB per-environment cap `[DOCS]`; physical layout `[INFERENCE]` | — | — |

**Strongest: QAYD, genuinely — with one caveat about scale.** ERPNext's database-per-site is *stronger
isolation* in absolute terms, but it costs a full schema and a full migration per tenant, and it makes
cross-tenant platform operations nearly impossible. Within the shared-database model, DB-enforced RLS
with a `NOBYPASSRLS` role is categorically stronger than every application-layer scheme here, because it
survives raw SQL, queue jobs, console commands, BI tools, read replicas, and forgotten endpoints — none
of which Odoo's, Akaunting's, or Dolibarr's models protect.

A structural validation worth more than any borrowed code: **Odoo ANDs its global rules and ORs its
group rules, which maps exactly onto PostgreSQL's RESTRICTIVE (AND-ed) and PERMISSIVE (OR-ed) policy
semantics.** QAYD's restrictive-boundary + permissive-scope decomposition is therefore a proven pattern,
not a guess.

**Two honest gaps.** `[CODE: TD-01]` raw `DB::` calls on the *owner* connection bypass RLS — the arch
test binds Eloquent tenant models to the `pgsql_app` connection, but raw SQL on the owner connection is
not tenant-scoped. And RLS GUCs are **per-connection**: under PgBouncer transaction pooling a connection
is handed to another request mid-session, so a `SET` rather than `SET LOCAL` leaks one tenant's context
into another tenant's request. This must be verified against a real pooler with real concurrency
*before* pooling is enabled — it is invisible in single-connection testing and it is the specific failure
mode that produces a silent cross-tenant breach.

**QAYD should deliberately ignore** every form of ambient privilege. Odoo's own security models set
`_allow_sudo_commands = False`, tacitly conceding that a sudo'd nested write is a privilege-escalation
primitive. Genuine cross-tenant work should be a `PlatformOperation` action object — a distinct role, a
narrow per-table policy clause, and an audit row written **in the same transaction**, so that if the
audit write fails the operation fails.

**Where QAYD can innovate.** A **CI catalog-introspection test** querying `pg_class`/`pg_policy`/
`pg_attribute` that fails the build if any table with a `company_id` column lacks `NOT NULL`,
`relrowsecurity`, `relforcerowsecurity`, and the named restrictive policy. This is five points of work
and it converts a convention into a mechanism. **Odoo structurally cannot write this test** — it has no
company primitive, just a field name and hand-written rules, with nothing checking that a new model has
one. Add **field-level** access control (which Odoo has and QAYD lacks) using real PostgreSQL column
`REVOKE` for the handful of columns where a bug must not leak — Postgres has column privileges and none
of these systems use them. And budget for **RLS diagnostics**: RLS returns zero rows with no
explanation, where Odoo's best feature is an error that names the blocking rule and suggests which
company to switch to.

---

## 2.12 Extensibility model

| | Mechanism | Upgrade safety |
|---|---|---|
| **QAYD** | Typed Actions + after-commit domain events; no plugin system | n/a — single codebase |
| Odoo | **Model inheritance and method override**; no domain-event bus | Poor — the root cause of 20 years of coupling |
| ERPNext | Apps + `doc_events` hooks + Custom Fields + Property Setters + `regional_overrides` **monkey-patching dotted paths by country** `[CODE]` | Metadata survives; patches are fragile |
| Akaunting | Per-company module activation; report **subscribers** and `TotalCalculating`/`TotalCalculated` events let a module mutate report figures `[CODE]` | Paid modules downloaded at runtime |
| Dolibarr | 336 trigger names / 482 fire sites; **2,623 hook sites**, including hooks that inject SQL fragments into core queries `[CODE]` | Strong ecosystem, frozen core |
| SAP | **Four tiers**: classic, key-user, developer (ABAP Cloud/RAP), side-by-side (BTP); **"clean core"** doctrine restricts to released APIs, enforced by `S_ABPLNGVS` and ATC variants `[DOCS]` | Explicitly the point |
| NetSuite | SuiteScript (user event, scheduled, Map/Reduce, RESTlet, Suitelet, client, plug-ins) + SuiteFlow + SDF; **governance units** kill a script that exceeds its budget `[DOCS]` | Two forced releases/year, no skipping; Release Preview to test |
| D365 F&O | **Overlayering banned in platform 8.0**; extensions, event handlers, chain-of-command only `[DOCS]` | Enforced |
| D365 BC | AL extensions, AppSource, per-tenant extensions; **base app source published under MIT in `microsoft/BCApps`** `[DOCS]` | Strong |

**Strongest: SAP and D365 F&O jointly,** for the same reason — both *banned* the extension mechanism
that felt most powerful. SAP's clean core and Microsoft's 2017 overlayering ban are the same decision:
customer freedom traded for the vendor's ability to upgrade. NetSuite's **governance units** are the most
interesting mechanism here — an explicit two-sided budget (per script type, per API call) where
exceeding it kills the script. It makes the price of multi-tenancy visible to the developer, and it
shapes idioms productively (prefer search over load, batch via Map/Reduce).

**QAYD should outperform by keeping modules genuinely decoupled.** After-commit domain events as the
**only** cross-module integration path — no module ever writes another module's tables — is the property
Odoo most conspicuously lacks after twenty years of inheritance-based extension, and Dolibarr traded
away permanently by letting modules inject SQL into core queries.

**QAYD should deliberately ignore** building a plugin marketplace, a generic workflow engine (Odoo built
one and **deleted it**), and country-keyed monkey-patching. Keep explicit statuses, explicit lifecycle
Actions, and DB-enforced transitions.

**Where QAYD can innovate.** Make the lifecycle transition map **data** before more Actions encode it
implicitly. Odoo's `account.move` has 8 statuses whose transition rules are scattered across at least
six call sites, so every new path re-derives them; QAYD's `journal_entry_status` also has 8 values and
today has exactly one constant expressing any of it `[CODE]`. One `TRANSITIONS` map plus a PostgreSQL
trigger rejecting illegal transitions — including `posted → anything-not-terminal` — costs three points
now and grows linearly with every Action written against the implicit rules.

---

## 2.13 API surface

| | Shape | Notable |
|---|---|---|
| **QAYD** | Hand-written REST, envelope-wrapped; **91 lines of routes** `[CODE]` | Tiny: auth, companies, accounts |
| Odoo | XML-RPC / JSON-RPC over the ORM | Model-generic |
| ERPNext | **Auto-REST over metadata**: `/api/resource/{doctype}`, `/api/method/{dotted.path}`, plus a v2 **discovery API** `[CODE]` | Every `@frappe.whitelist()` function in every installed app is reachable — the surface is the union of all whitelisted functions, not a designed contract |
| Akaunting | 84-line `routes/api.php`, `apiResource` CRUD mirror; **no journal/ledger/TB endpoints because the concepts don't exist** `[CODE]` | Sanctum |
| Dolibarr | Restler 3, **auto-discovered by filesystem scan**; 51 `api_*.class.php` `[CODE]` | The accounting API exposes **exactly one operation: `exportData`** — the GL is API-visible only as a bulk export `[CODE]`. API key accepted as a **URL query parameter** `[CODE]` |
| SAP | OData v2 **and** v4 side by side; Business Accelerator Hub; Enterprise Event Enablement `[DOCS]` | Async bulk journal-entry posting API `[DOCS]` |
| NetSuite | SuiteTalk SOAP, REST, RESTlets, **SuiteQL**, SuiteAnalytics Connect (ODBC/JDBC) `[DOCS]` | **Concurrency is a purchased resource** — Service Tier 1 base 15, +10 per SuiteCloud Plus licence `[DOCS]` |
| D365 | OData data entities, custom services, Dataverse virtual entities, business events; BC API v2.0 `[DOCS]` | Metadata-driven |

**Strongest: NetSuite,** for range — a SQL-ish query API, a warehouse connector, RPC endpoints, and typed
CRUD covering genuinely different integration shapes. ERPNext is strongest on *automatic* surface: a
Custom Field is exposed through the REST API the moment it exists.

**QAYD should outperform on being a designed contract rather than an emergent one.** ERPNext's surface
is the union of every whitelisted function in every installed app; Dolibarr's is a filesystem scan.
Neither can be reasoned about as a whole.

**QAYD should deliberately ignore** auto-generating CRUD over its schema. The whole point of Actions is
that operations are named and validated; an auto-CRUD layer reintroduces every bypass the architecture
removed.

**Where QAYD can innovate — and this is the highest-leverage idea in this section.** Design the API for
an **agent** as the primary caller, not as an afterthought: structured multi-violation errors, an
explicit dry-run/preview mode on every mutating endpoint, and **predicates as portable data** — a closed,
CHECK-constrained JSONB selector compiled to SQL through an allowlist to bound parameters, never `eval`
and never string interpolation. Odoo's domain language is the structural idea that makes "the AI
proposes, it never writes" implementable: an LLM emits a predicate, a human reads and approves it, and
the backend compiles it. The alternative — an AI emitting SQL or calling mutating methods — is
unreviewable by construction. One reviewed compiler then secures report expressions, reconciliation
matching rules, and dimension distribution rules alike. Note that Dolibarr accepting an API key as a URL
query parameter is a live example of how these surfaces decay.

---

## 2.14 AI capabilities

| | What actually ships | Posture |
|---|---|---|
| **QAYD** | **Nothing.** `apps/ai/src` is a **14-line stub** `[CODE]`. But the *schema* is AI-native: `ai_generated`, `ai_confidence`, `ai_suggested_account_id` are ledger columns, and a DB trigger refuses AI auto-posting `[CODE]` | AI drafts; a human disposes; the AI cannot write accounting tables **by grant, not by good behaviour** |
| Odoo | Odoo 19 ships an AI app, configurable agents, and "Ask AI" across Accounting; AI-assisted vendor-bill digitisation `[COMMUNITY]` | Assistant + extraction |
| ERPNext | **None. Zero.** Greps for `openai\|anthropic\|claude\|llm` across both repos return only `re.fullmatch` false positives; no AI module, no AI dependency `[CODE]`. Ecosystem apps (Raven, MCP bridges) add it — and those **let an agent create DocTypes and workflows**, i.e. write the schema `[COMMUNITY]` | None in core |
| Akaunting | None | — |
| Dolibarr | None | — |
| SAP | **Cash Application** (country-specific ML models, real scheduling/training/inference apps) and **GR/IR matching ML** are genuinely productised `[DOCS]`. **Joule** finance capabilities exist, several marked *"Available in Joule preview"* on limited data centres `[DOCS]`. AI is metered in **AI Units** `[DOCS]` | Productised, metered |
| NetSuite | **Text Enhance** + Prompt Studio, backed by OCI Generative AI `[COMMUNITY]`; **LLM access as a SuiteScript 2.1 primitive** `[COMMUNITY]` — architecturally the most interesting item. **NetSuite Next** / "Ask Oracle" announced Oct 2025, NA rollout from 2026.2 `[COMMUNITY]` | Assistant + a scripting primitive |
| D365 | Copilot in BC (bank rec assist, e-document matching) and Business Performance Analytics Copilot; GA-vs-preview matrix `[UNKNOWN]` | Assistant |

**Strongest: SAP** — and the reason is a finding that rewards skepticism more than any other in this
study. SAP's **Cash Application** is a real product with configuration, training and inference jobs,
country-specific models, and monitoring. But SAP launched **Intelligent Intercompany Reconciliation**
with ML in S/4HANA 2021 and, by Cloud 2402, published a page titled *"Discontinuation of Intelligent
Intercompany Reconciliation Service"*, deprecating the intelligent scenario while the **rule-based ICMR
matching it was meant to augment remains** `[DOCS]`.

That is the most honest data point available about finance AI: **the rules engine is the durable asset;
the ML layer on top has proven replaceable.** Any QAYD roadmap that inverts this ordering is betting
against the only large-scale natural experiment we have.

**QAYD should outperform on the thing none of them have: a first-class proposal primitive.** Every
incumbent's AI is a layer *on top of* a system of record that was never designed to accept
machine-authored entries. There is no "proposed journal entry" concept in any of their ledgers — no
confidence, no rationale, no reviewer, no link from the approved entry back to the proposal that became
it. External guidance is already converging on what auditors will want: the proposal, the AI's rationale,
the human approval, and a timestamp — not just the final GL record `[COMMUNITY]`. QAYD already has
`ai_generated` and `ai_confidence` **as ledger columns**. That is a head start measured in schema, not
in models.

**QAYD should deliberately ignore** the assistant race. A chat box over the ledger is table stakes
within eighteen months and differentiates nothing. It should also emphatically ignore the ERPNext
ecosystem pattern of granting agents schema-write access — an agent that can create a DocType can
reshape the general ledger.

**Where QAYD can innovate.** Make the *audit trail of machine reasoning* the product: `ai_decisions` with
confidence, cited source rows, the compiled predicate the agent proposed, the human who approved it, and
the resulting entry id — all inside the hash chain. Add `posting_attempts` for rejected drafts. Then the
claim is not "we have AI"; it is "every number an agent touched is explainable to your auditor, and the
agent could not have written it even if it tried, because it has no grant."

---

## 2.15 Data model philosophy

| | Schema authority | Money type | Referential integrity |
|---|---|---|---|
| **QAYD** | Migrations only | **`NUMERIC(19,4)` + bcmath strings** `[CODE]` | **FKs + CHECKs + triggers** `[CODE]` |
| Odoo | Python models; **runtime `ALTER TABLE` for analytic plans** | **Float** | Partial; JSONB keys with none |
| ERPNext | **Rows in `tabDocType`**; DDL at save | `decimal(21,9)` default | **Zero foreign keys anywhere** `[CODE]` |
| Akaunting | Migrations | **`double(15,4)`, PHP floats** `[CODE]` | **Two FKs on business tables total** `[CODE]` |
| Dolibarr | Install SQL | **`double(24,8)`; zero DECIMAL/NUMERIC in 410 tables** `[CODE]` | 297 FKs elsewhere, **none on the bookkeeping tables**; **zero CHECK constraints** `[CODE]` |
| SAP | SAP owns ACDOCA; 3 sanctioned extension routes | Fixed-point | Strong, ABAP-layer |
| NetSuite | Oracle owns it; customers add metadata objects | — | Physical model `[UNKNOWN]` |
| D365 | Microsoft owns it; extensions add tables | — | Strong |

**Strongest: SAP,** for the Universal Journal thesis — write the line item once, store dimensions once
rather than per component, and let the FI↔CO reconciliation problem cease to exist. It is the most
ambitious data-model consolidation any of these systems attempted and it substantially worked.

**QAYD is already ahead on exactness and must not regress.** It is the only system in this comparison
where money is a fixed-point database type moved as strings and computed with an arbitrary-precision
library. Three of the four open-source systems store money as binary floating point, and Dolibarr has
carried commented-out `bcadd` aspirations in its source for years without acting on them `[CODE]`. This
is not a stylistic preference: it is the difference between a reconciliation that ties and one that is
off by 0.0001 and cannot be explained.

**QAYD should deliberately ignore** runtime DDL in every form. It is disqualifying for a
migration-driven system: every tenant's schema would differ, no migration would be portable,
zero-downtime deploys would be unreasonable, and a schema review could not enumerate the columns.

**Where QAYD can innovate.** Take the *outcome* of metadata-driven design without the mechanism: fixed,
constrained tables plus **declarative rows** for the things that genuinely vary per tenant — dimensions,
report definitions, tax repartition, matching rules, posting groups. Rows, with foreign keys and CHECK
constraints, are strictly better than JSONB blobs *and* strictly better than DDL. The decisive proof is
Odoo's own behaviour: it materialises its JSONB into rows anyway.

---

## 2.16 Developer experience

| | Language / tooling | Local setup | Tests |
|---|---|---|---|
| **QAYD** | PHP 8.4, Laravel 12, Pest, PHPStan, Pint; `make install/up/migrate/seed/test` mirroring CI `[CODE]` | Docker Postgres + Redis | 34 files, incl. RLS negative-path suites `[CODE]` |
| Odoo | Python, hot reload, module scaffolding | Simple | Large suite |
| ERPNext | Python, `bench`, DocType UI generates code | `bench` handles it | Large suite |
| Akaunting | Laravel — instantly familiar to any Laravel developer | Trivial | 38 files `[CODE]` |
| Dolibarr | PHP, no build step, no root Composer; **4,554 concatenated SQL strings, one prepared statement** `[CODE]` | Copy files | Thin |
| SAP | ABAP, ADT/Eclipse, transports; ABAP Cloud restricts the language itself | Weeks | — |
| NetSuite | SuiteScript, SDF, VS Code extension; **governance units** shape every idiom | Account provisioning | Sandbox |
| D365 | X++ in Visual Studio (F&O) / **AL in VS Code with public MIT base-app source** (BC) `[DOCS]` | Heavy (F&O) / light (BC) | — |

**Strongest: Business Central,** now that `microsoft/BCApps` publishes the System Application, Business
Foundation, and the Base Application itself under MIT with public build pipelines. A proprietary ERP
whose base application you can read, fork locally, and debug against is a materially better developer
experience than any of the open-source systems here offer *in practice*, because the code is also
coherent.

**QAYD should outperform on the feedback loop.** Its real advantage is that the compiler and the
database do the arguing: PHPStan on `numeric-string`, architecture tests binding tenant models to the
RLS connection, and a `make test` that mirrors CI exactly. Compare Dolibarr, where safety rests on ~3,800
hand-written `escape()` calls that a reviewer must verify individually.

**QAYD should deliberately ignore** ceremony reduction that moves logic up a layer. The four-file path
(controller → request → Action → DTO) is genuinely more work for a trivial endpoint, and that cost is
worth paying; reducing *ceremony* (a base controller, code generation) is welcome, moving *logic* is not.

**Where QAYD can innovate.** Optimise the codebase for an AI contributor, since one is writing much of
it: a uniform Action shape makes endpoints generatable by pattern; the arch tests are the regression
harness for a machine that will occasionally take a shortcut; and the `06`-style knowledge base is
context an agent can load. This is a real advantage over every system here — none of them were laid out
so that a machine could add an endpoint correctly by pattern-matching.

---

# Part 3 — Honest scorecard

**Scale.** 1 = absent or actively harmful · 2 = present but structurally weak · 3 = adequate, works in
production · 4 = strong, a real advantage · 5 = best-in-class, hard to replicate.

**How to read QAYD's column.** QAYD is at Sprint 2 with 23 migrations and no production tenants. Most of
its scores are 1 — not because the design is weak but because **the subsystem does not exist**. A
scorecard where QAYD wins everything would be worthless; the scores below are what the code supports
today, with the *designed* ceiling noted where the two differ.

| Subsystem | QAYD | Odoo | ERPNext | Akaunting | Dolibarr | SAP | NetSuite | D365 |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Double-entry core & posting | **4** | 3 | 3 | **1** | 2 | **5** | 4 | 4 |
| Chart of accounts | **2** | 3 | **4** | 1 | 3 | **5** | 4 | 4 |
| Ledger storage & balances | **3** | 2 | 3 | 1 | 2 | **5** | 3 | 4 |
| Fiscal calendar & period close | **1** | 3 | 4 | **1** | 2 | **5** | 4 | 4 |
| Reconciliation | **1** | 3 | 3 | 2 | 2 | **5** | 4 | 4 |
| Financial reporting | **1** | 2 | 3 | 1 | 2 | **5** | **5** | 4 |
| Tax engine | **1** | 4 | 4 | 2 | 2 | **5** | 4 | **5** |
| Multi-currency | **2** | 2 | 4 | 1 | 2 | **5** | **5** | 4 |
| Audit & compliance | **4** | 3 | 2 | **1** | 3 | **5** | 4 | 4 |
| Analytic / dimensional | **1** | 3 | 3 | 1 | 1 | **5** | 4 | **5** |
| Multi-tenancy & security | **5** | 2 | 4 | **1** | **1** | 4 | 4 | 4 |
| Extensibility model | **2** | 3 | **5** | 3 | 4 | **5** | **5** | **5** |
| API surface | **1** | 3 | 4 | 2 | 2 | **5** | **5** | 4 |
| AI capabilities | **1** | 3 | 1 | 1 | 1 | 4 | 3 | 3 |
| Data model philosophy | **5** | 2 | 3 | 1 | 1 | **5** | 4 | 4 |
| Developer experience | **4** | 4 | 4 | 4 | 2 | 2 | 3 | **5** |
| **Mean** | **2.4** | 2.8 | 3.4 | 1.5 | 2.0 | **4.7** | 4.1 | 4.2 |

**The mean is the point.** QAYD scores 2.4 against SAP's 4.7 and ERPNext's 3.4. It is currently the
second-weakest system in this comparison by breadth, ahead only of Akaunting. Everything in Part 4 is
written with that fact in view.

### One-line justifications for the notable scores

**QAYD**
- Double-entry **4** — zero-tolerance dual-currency balance with a DB CHECK backstop and a single posting path is best-in-class strictness; a 5 requires document splitting or an equivalent multi-dimensional balancing capability.
- Chart of accounts **2** — the table and the tree are correct, but there is no `is_postable` flag `[TD-15]`, no country template, no opening-balance action `[TD-10]`, and the posted-activity guard is ledger-blind `[TD-11]`.
- Ledger **3** — the append-only projection with `signed_base_amount` is a 5-grade design, but with **no stored balances and no fiscal-period dimension** `[TD-13, TD-14]` the subsystem as shipped is a 3.
- Period close **1** — `fiscal_periods` does not exist; the gate is fiscal-year granular.
- Reconciliation **1**, reporting **1**, tax **1**, dimensional **1**, API **1** — not built.
- Multi-currency **2** — a stored rate and a base-currency CHECK exist; there is no rate table, no revaluation, no missing-rate error path.
- Audit **4** — an append-only `audit_logs` with structured JSONB diffs plus a DB-enforced immutable ledger; the hash chain is dormant `[TD-06]` and posting writes no audit row `[TD-16]`, which caps it below 5.
- **Multi-tenancy 5** — the only system here with database-enforced isolation, a `NOBYPASSRLS` runtime role, fail-closed policies, and no bypass primitive. The score is honest but conditional on the PgBouncer `SET LOCAL` audit and closing `[TD-01]`.
- Extensibility **2** — Actions and domain events are clean, but nothing is configurable by a customer.
- AI **1** — a 14-line stub. The *schema* is AI-native; the engine does not exist.
- **Data model 5** — exact money, FKs, CHECKs, and triggers throughout; joint best with SAP.
- Developer experience **4** — strong gates and a CI-mirroring Makefile; small ecosystem.

**Others — the scores worth explaining**
- **SAP 5s** are earned on capability, not size: document splitting, 10 currencies per ledger, ACDOCA, DRC per-country compliance, and Advanced Financial Closing have no peer. Its **2 for developer experience** is equally honest — ABAP, transports, and weeks-long environment setup.
- **NetSuite 5s** for reporting (one live database, no ETL lag), multi-currency (consolidated rate types + CTA), extensibility, and API range. Its **3 for ledger** reflects a genuinely mutable ledger where a posted transaction can be edited and its GL impact recomputed.
- **D365 5s** for dimensional accounting (the BC dimension-set trie and F&O's combination surrogate), tax (Electronic Reporting as a metadata format engine), extensibility (overlayering banned, enforced), and developer experience (MIT base-app source, VS Code, AL).
- **ERPNext 5 for extensibility** — a user adds a column to the general ledger from a web form and it appears everywhere. Its **2 for audit** — `tabVersion` is opt-in per DocType, ordinary rows, no chain — and its **1 for AI** (verified absent from both repos) are the honest counterweights.
- **Odoo 2 for ledger and 2 for data model** — no ledger table, no stored balances, float money, mutable GL written by raw SQL. Its **4 for tax** reflects a genuinely good repartition model.
- **Akaunting 1s** are not a value judgement about the product, which is a competent small-business cash book. They record that double-entry, period close, immutability, and audit are **absent from the open codebase**, and that money is `double` with float arithmetic.
- **Dolibarr 1 for multi-tenancy** — the `entity` column is a schema shape whose resolver collapses to single-tenant without a commercial add-on, and accounting explicitly opts out of it. Its **4 for extensibility** is real and is precisely why its core can never be tightened.

---

# Part 4 — Strategic conclusions

## 4.1 Where QAYD is already ahead — and must not regress

Five properties. Each is cheap to hold and expensive to recover once lost.

| Property | The evidence it is genuinely ahead | The specific way it could be lost |
|---|---|---|
| **Database-enforced tenancy** | Every other shared-database system here enforces tenancy in application code. Odoo checks create-rules *after* the INSERT; Akaunting's scope silently doesn't apply in at least four situations; Dolibarr's accounting module opts out 20 times. | A raw `DB::` call on the owner connection `[TD-01]`, or a `SET` instead of `SET LOCAL` behind PgBouncer. Close both with the CI introspection test and a real-pooler concurrency test. |
| **An append-only ledger** | NetSuite recomputes GL impact on edit; Dolibarr deletes a year of the ledger in one statement; ERPNext's immutability is a setting. | Writing reconciliation state onto the ledger row. `journal_lines.reconciled` already exists as a boolean — the exact decision that made Odoo's GL mutable. Drop it rather than use it. |
| **Exact money** | Odoo, Akaunting, and Dolibarr all use binary floats; Dolibarr has zero DECIMAL columns in 410 tables. | One `(float)` cast in an aggregate. PHPStan on `numeric-string` is the mechanism; keep it in CI. |
| **One door into the ledger** | Odoo writes the GL by raw SQL in at least two places; Dolibarr's posting engine is nine thousand lines of page scripts. | A second write path added "just for the importer". |
| **No ambient privilege** | Odoo's `sudo()` at 552 call sites is transitive, unlogged, and unscoped. | Wiring `is_platform_admin` into a policy bypass "temporarily". |

Add one that is not yet a property but should be made one this sprint: **the lifecycle transition map as
data**, with a trigger rejecting illegal transitions. Three points now; it grows with every Action.

## 4.2 Where QAYD is behind and must catch up — table stakes

An ERP buyer will not evaluate architecture. They will ask whether it does the job. Ordered by how early
the question comes up in a sales conversation:

| # | Capability | Who has it | Why it is table stakes |
|---|---|---|---|
| 1 | **Fiscal periods and month-end close** | All seven | "How do I close a month?" is asked in the first meeting. Fiscal-year gating is not an answer. |
| 2 | **Trial balance, P&L, balance sheet** | All except Akaunting (and Odoo Community, whose report evaluator is Enterprise-only) | An accounting system that cannot produce a trial balance is not an accounting system. |
| 3 | **Bank reconciliation** | All seven | The single most-used daily workflow in an SME finance department. |
| 4 | **VAT/tax with GCC filing output** | All seven, at varying depth | Saudi ZATCA Phase 2 is live; Kuwait VAT is expected. Non-optional in this market. |
| 5 | **A working exchange-rate subsystem** | All seven | Kuwait businesses transact in USD, SAR, AED, and EUR routinely. |
| 6 | **Dimensions / cost centres** | Six of seven | Any business with more than one branch or project asks for this immediately. |
| 7 | **A country chart-of-accounts template** | ERPNext ships 68 verified | Onboarding friction. This is a data problem, not an engineering one — the cheapest item on the list. |
| 8 | **Opening balances from a prior system** | All seven | Nobody adopts an accounting system on day one of a fiscal year. Unblocked since the posting engine landed `[TD-10]`. |

Two dependencies are worth naming. **Dimensions (#6) must be *decided* before the ledger grows**, even
if built later — it is the only item whose cost rises with delay. And **#2 becomes cheap once
`account_period_balances` exists**, so the rollup should precede the statements.

## 4.3 Where QAYD should never compete

Each of these looks essential in a feature matrix and is a trap for a small team.

| Trap | Why it looks essential | Why it is a trap |
|---|---|---|
| **Localization breadth** | SAP ships per-country e-invoicing; NetSuite updates rates for ~180 countries; D365 has ~54 localizations. | Thousands of person-years of regulatory minutiae, permanently maintained. SAP itself ships "Localization as a Self-Service" because its own coverage is finite. Four GCC countries done exactly beats fifty done approximately. |
| **A metadata-driven schema** | ERPNext's "add a GL column from a web form" is a genuinely impressive demo. | It costs foreign keys, CHECK constraints, and the ability to reason about the schema — the exact properties that are QAYD's entire advantage. ERPNext ships a `Ledger Health Monitor` to detect the drift this causes. |
| **A generic workflow engine** | Every ERP appears to have one. | Odoo built one and **deleted it**, replacing it with explicit state fields. A free lesson. |
| **A plugin marketplace** | Dolibarr's ecosystem is its moat. | It is also why Dolibarr's core can never tighten an invariant — modules inject SQL into core queries. An ecosystem is a commitment to never fixing your foundations. |
| **Consolidation and group reporting** | SAP has ACDOCU; NetSuite has OneWorld. | Genuinely valuable for Gulf groups, and genuinely far beyond current scope. Record the requirement now only so the RLS model is not frozen in a way that makes it impossible later. |
| **Full ERP scope** (manufacturing, WMS, HR, CRM) | "All-in-one" is how Odoo and NetSuite sell. | It is how you build 1.85M lines of PHP with zero CHECK constraints. |
| **The AI assistant race** | Everyone is shipping a chat box. | Commoditised within eighteen months. SAP's ICR deprecation shows even a shipped, marketed ML feature can be withdrawn in three years while the rules engine underneath survives. |

## 4.4 The genuine white space

Four capabilities. The first three are unlocked specifically by the append-only ledger; the fourth by
the AI-native schema. In each case the incumbent's inability is **structural**, not a backlog item.

**1. A cryptographically verifiable ledger, at zero marginal cost.**
Odoo's hash chain is unkeyed, has an empty-string genesis, is enforced only in Python (its repository
contains no triggers at all), covers an allowlist that omits `amount_currency` and analytic
distribution, and has three context-keyed bypasses. Dolibarr's chain is well engineered — HMAC-SHA256,
serialised extension under `FOR UPDATE`, a verification UI — but covers invoice and payment *events*,
never the ledger. NetSuite's integrity story is an immutable *log* over a mutable *ledger*.
QAYD can chain `ledger_entries` itself, covering **every column**, enforced in a `BEFORE INSERT`
trigger, with externally signed periodic anchors — and it is cheap **only because the projection is
append-only**, so the chain can never go stale. Odoo cannot do this without giving up the mutable ledger
its reconciliation design requires. And it lands directly on ZATCA Phase 2's invoice hash-chaining
requirement `[COMMUNITY]`: the compliance work and the integrity work are the same work.

**2. An always-current period-balance rollup.**
Odoo stores no aggregates anywhere; ERPNext materialises balances only at period close and otherwise
pulls GL rows into Python to aggregate; SAP deleted its aggregate tables on principle and then had to
add Deferred Summarization at extreme volume. QAYD's `AFTER INSERT` trigger on an append-only source is
**monotonic** — it can only increment — which makes a cached aggregate trustworthy in a way an aggregate
over a mutable table never is. **This property is not available to any system whose ledger can be
updated.** It converts the trial balance from a scan of the largest table into a small indexed read.

**3. Reconciliation that never mutates history.**
Odoo unreconciles by DELETE, and for bank lines deletes and recreates journal items on *posted* moves
under `force_delete`/`skip_readonly_check`; its `account_partial_reconcile` table has zero SQL
constraints, no row locking anywhere in the path, and zero concurrency tests among 95 reconciliation
tests. QAYD's append-only trigger **forbids** that approach, which forces a better design: matching
state in side tables, unreconcile by INSERT of a compensating row, matching groups as a rebuildable read
model, and a deferred constraint trigger asserting `SUM(matched) ≤ original`. The constraint is
declarative, so it is nearly free — and it closes a hole Odoo has lived with for years.

**4. Machine-authored accounting with an auditor-grade provenance trail — the largest opportunity.**
This is where the incumbents' architecture, not their roadmap, is the obstacle. None of them has a
first-class **proposal** in the ledger: no confidence, no rationale, no cited source rows, no reviewer,
no link from the posted entry back to the proposal that became it. Their AI is a layer bolted onto a
system of record designed for humans typing. Retrofitting a proposal primitive means changing the
ledger schema of a system with tens of thousands of live tenants, which is why it will not happen
quickly.

QAYD already has `ai_generated`, `ai_confidence`, and `ai_suggested_account_id` **as columns in the
ledger tables**, and a database trigger that refuses AI auto-posting. Build outward from that:
`ai_decisions` carrying the compiled predicate the agent proposed, its cited ledger rows, its confidence,
the approving human, and the resulting entry id — all inside the hash chain. Add `posting_attempts` for
rejected drafts, which is simultaneously a compliance artifact and the best training signal available.
Enforce **predicates as portable data** (a CHECK-constrained JSONB selector compiled through an allowlist,
never `eval`), so an agent's proposal is something a human can *read and approve* rather than opaque SQL.

The resulting claim is one no incumbent can currently make: *every number an agent touched is explainable
to your auditor, and the agent could not have written it even if it tried, because it has no grant.*
Note the ordering discipline SAP's experience imposes: **build the deterministic engine first and let the
model propose only what the rules could not settle.** SAP's rule-based ICMR outlived the ML layer built
on top of it.

## 4.5 What the incumbents do better than QAYD — stated plainly

This section exists because a comparison that flatters the author is worthless, and because SAP and
NetSuite are enormously capable systems whose difficulty is easy to underestimate from outside.

**SAP S/4HANA is a more capable financial system than QAYD will be for many years, and possibly ever.**
Document splitting produces complete, balanced statements at segment and profit-centre level — a hard
problem solved as a first-class primitive that QAYD has not begun to think about. Ten currency fields
per ledger, ledger-based parallel accounting for IFRS plus local GAAP plus tax GAAP off one journal,
Asset Accounting and Material Ledger landing in the same line-item table as the G/L, a virtual data
model where authorisation is expressed at the view layer, and Advanced Financial Closing as a genuine
close-orchestration product. Its localization depth — a per-country e-invoicing implementation, a
bank-statement algorithm for Polish KSeF reference numbers — represents thousands of person-years that
cannot be shortcut. QAYD's advantages over SAP are narrow and specific: enforcement in the storage engine
rather than the application, and freedom from a thirty-year compatibility surface.

**NetSuite does multi-subsidiary consolidation at mid-market cost better than anyone.** Subsidiary as a
first-class GL dimension, per-subsidiary base currency, Current/Average/Historical consolidated rate
types with a properly reasoned CTA, automated intercompany elimination at period close, per-subsidiary
period locking, and Multi-Book producing several accounting standards from one transaction stream. Its
"one live database for transactions and reporting" property means a P&L has no ETL lag and no
warehouse-reconciliation problem. And the Custom GL Lines Plug-in — an extension point *on the general
ledger itself*, scoped per subsidiary and book, required to self-balance — is a more sophisticated idea
than anything in QAYD's design.

**ERPNext is more useful today than QAYD, by a wide margin, and it is free.** 532 DocTypes, 68 verified
country charts of accounts, four currency layers per GL line, threshold-based withholding tax, FX
revaluation, a period-closing voucher that is a real posting rather than a flag, and an auto-generated
REST API that exposes new fields the moment they exist. It is also **most concentrated in Saudi Arabia**
— QAYD's second market — which makes it the realistic competitor QAYD will meet in deals, not SAP.

**Business Central's dimension-set trie is a better design than anything QAYD has specified**, and its
posting-group matrix solves a problem QAYD will hit the first time it writes a sales module. Microsoft
also publishes the Business Central base application under MIT, which makes a proprietary product more
readable than most open-source ERPs.

**Odoo's conceptual models are better than QAYD's current specifications** in at least four places: one
document model for every financial document, reports as data, tax repartition as data, and residuals
derived from matching links rather than stored. Twenty years of production use validated them. QAYD's
contribution is not better concepts — it is putting those concepts in PostgreSQL.

**Dolibarr's blockedlog is a better hash chain than QAYD has built**, because QAYD has not built one.
**Akaunting's invoicing UX and time-to-first-invoice are better than QAYD's**, because QAYD has no
invoicing.

**And the honest summary of the gap:** every one of these systems keeps real books for real companies
today. QAYD keeps none. Architectural superiority that has never survived contact with a live tenant is
a hypothesis. The scorecard's 2.4 is the accurate number, and the work in §4.2 is what converts the
hypothesis into a product.

---

## Appendix — claims deliberately left as `[UNKNOWN]`

Recorded so nobody fills them from memory in a later revision.

| Claim | Status |
|---|---|
| NetSuite's tenant-isolation mechanism | No Oracle documentation found. Not shared-schema-with-discriminator, not schema-per-tenant — **unknown**. |
| NetSuite's physical storage for custom records/fields/segments | Query-layer shape is documented; physical representation is not. |
| NetSuite customer count ("43,000+") | Circulating figure; netsuite.com and oracle.com/news return HTTP 403 to automated fetch. Not primary-sourced. |
| SAP ACDOCA column count | **Appears not to be published.** Third-party counts range ~360–511 and are release- and customer-specific. Never cite a single number. |
| An SAP statement of the runtime zero-balance posting rule | Not found. A widely quoted sentence traces to **SAP Business One**, a different product. |
| S/4HANA Cloud public-edition cross-customer physical isolation | Not published in reachable pages. Do not transplant BTP multitenancy docs onto it. |
| SAP's published count of supported countries/localizations | Not obtained. |
| SAP S/4HANA customer count, ACDOCA row counts, largest deployments, TCO benchmarks | Not obtained; sap.com 403s to scripted fetch. |
| D365 F&O customer count | Microsoft publishes no verifiable figure. |
| F&O Copilot GA-vs-preview matrix; BC Copilot feature availability | Release-plan intent only. |
| F&O environment physical topology (dedicated Azure SQL per environment) | Widely believed; no documentation reached. |
| Literal F&O table names `SubledgerJournalEntry` / `SubledgerJournalAccountEntry` | Concept documented; exact names unverified. |
| `MANDT` as universal first key field with automatic Open SQL client injection | True to general knowledge; not verified against SAP docs in this research. |
| S/4HANA journal-entry anomaly detection | **Searched for specifically and not found** in finance documentation. Do not list it as a capability. |

---

*Sources: Odoo 19.0 `f3e407c6` (LGPL-3), Akaunting 3.2.1 `4aec5fc` (BUSL-1.1), Dolibarr 25.0.0-alpha
`604c99f` (GPL-3), ERPNext 17.0.0-dev (GPL-3) / Frappe develop (MIT) — read as source, no code
reproduced. SAP, NetSuite, and Microsoft — public documentation only. QAYD — this repository. Every
design proposed above is an original QAYD proposal targeting Laravel 12 / PHP 8.4 / PostgreSQL with RLS
and bcmath.*

