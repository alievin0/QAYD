# Customer Management — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Customers (Accounts Receivable Master Data)
---

# Purpose

The Customer Management submodule is the system of record for every party to whom QAYD's tenant
companies extend goods, services, or credit. It owns the `customers`, `customer_contacts`,
`customer_addresses`, and `customer_documents` tables, and it owns the derived `customer_balances`
projection that gives Sales, Finance, Collections, and the AI layer a single, fast, authoritative
answer to the question "what does this customer owe, and how risky are they." Every sales
quotation, sales order, invoice, credit note, and receipt in the platform references a `customer_id`
from this submodule; every customer-facing dimension used in reporting (industry, segment, region,
sales owner, price list, payment terms) is defined here.

Concretely, Customer Management is responsible for:

1. **Identity and classification** — capturing who the customer is (individual, business,
   government, non-profit, or international entity), their legal identifiers (commercial
   registration number, national ID, tax registration number), and their classification for
   pricing, tax, and reporting purposes.
2. **Relationship data** — contacts, addresses (billing and shipping, potentially multiple of
   each), documents (commercial registration, tax certificate, signed contracts, KYC evidence),
   notes, tags, and custom fields that let each tenant extend the model without a schema change.
3. **Financial terms** — the commercial agreement that governs how the customer transacts:
   payment terms, credit limit, credit rating, preferred currency, preferred language, and the
   price list or discount structure that Sales must respect.
4. **Sub-ledger of the General Ledger** — every customer carries a running balance that must, in
   aggregate, reconcile at all times to the Accounts Receivable control account in the Chart of
   Accounts. Customer Management does not do double-entry bookkeeping itself (that is Accounting's
   job via `journal_entries`/`journal_lines`), but it is the sub-ledger that Accounting's AR
   control account rolls up from, and it must never silently drift out of reconciliation with the
   GL.
5. **Credit governance** — enforcing credit limits, overdue rules, and blocking rules that protect
   the company from extending further exposure to a customer who has stopped paying, and driving
   the Collections workflow that recovers what is owed.
6. **Lifecycle and governance** — moving a prospective relationship through Lead → Customer →
   Inactive → Archived → Blacklisted states, with approval gates, merge/dedupe tooling, and
   archive-not-delete semantics once financial history exists.
7. **AI-assisted operations** — risk scoring, payment-date prediction, fraud and duplicate
   detection, semantic ("smart") search, next-best-action recommendations, behavioral analysis, and
   revenue forecasting, all produced by AI agents that write proposals through the same Laravel API
   any human user would use, never directly to the database.

Customer Management is deliberately scoped to **master data plus its own sub-ledger balance
projection**. It does not own quotations, orders, invoices, deliveries, receipts, or contracts —
those belong to the Sales and Banking modules — but it is the anchor every one of those documents
points back to, and it is the module that answers "can this customer buy more" before Sales is
allowed to confirm a new order.

# Vision

QAYD's Customer Management exists to make the customer master the most trustworthy, most current,
and most intelligent record in the system — the place where a Finance Manager, a Sales Employee,
and an AI Collections Agent all look at the exact same number and agree on what it means.

The long-term vision has four pillars:

**One customer, one truth.** In legacy ERPs a "customer" is duplicated across Sales, Finance, and
CRM systems, each with its own address book and its own idea of the outstanding balance. QAYD
collapses this into a single `customers` row per legal entity per company, with every downstream
document (`sales_orders`, `invoices`, `receipts`, `credit_notes`) pointing to that one row. The
`customer_balances` projection is rebuilt from posted `journal_lines` and is never a second source
of truth — it is a materialized, indexed view of the first.

**Credit risk visible before the sale, not after.** Traditional accounting software enforces
credit limits at invoice time, when the goods have often already shipped. QAYD enforces credit
policy at the point of sales-order confirmation (see Business Rules and Credit Management), and
the AI Risk Scoring agent recomputes a customer's risk band continuously, not just at month-end
close, so blocking rules trigger on the current picture of the customer, not last month's.

**AI as an analyst, never as a signatory.** Every AI-produced number attached to a customer — a
risk score, a predicted payment date, a duplicate-match confidence, a churn probability — is
advisory. It is stored with its confidence and its reasoning, surfaced to the human roles that own
the decision (Finance Manager approves a credit-limit change; Collections Officer approves a
dunning escalation), and it never mutates a customer's credit limit, blocks a customer, or writes
off a balance without a human or a pre-approved policy in the loop.

**Global-ready from a Gulf base.** Kuwait is the default market — Arabic/English bilingual master
data, KWD base currency, GCC-aware tax rules (VAT is not yet mandatory in Kuwait but is live in
Saudi Arabia and the UAE, and the schema must not need a migration the day a tenant expands there)
— but the data model treats "international customer" as a first-class type from day one, not a
retrofit.

The measure of success for this submodule is operational: a Finance Manager should be able to
answer, in under two seconds, "who owes us money, how much, how overdue, and how likely are they to
pay" for any customer, any segment, or the whole portfolio, without waiting on a month-end
reconciliation run.

# Customer Lifecycle

Every customer record moves through exactly one of five lifecycle states, stored in
`customers.lifecycle_state`. The state machine is linear with two escape/return paths (Inactive can
return to Customer; Blacklisted is terminal except for a Finance-Manager-and-above override that
routes through the same approval workflow as initial credit-limit approval).

```
                 ┌────────────────────────────────────────────────────────┐
                 │                                                        │
   ┌──────┐   convert   ┌──────────┐  90+ days no   ┌──────────┐  archive │  reactivate
   │ LEAD  ├────────────▶ CUSTOMER ├───activity─────▶ INACTIVE ├──────────┼──────────────▶ CUSTOMER
   └──┬───┘             └────┬─────┘                └────┬─────┘         │
      │ disqualify           │ severe delinquency /       │ no balance,  │
      │                      │ fraud flag / policy breach  │ no open docs│
      ▼                      ▼                             ▼             │
  (soft-deleted,        ┌──────────────┐              ┌──────────┐       │
   deleted_at set)      │ BLACKLISTED  │              │ ARCHIVED │◀──────┘
                        └──────┬───────┘              └──────────┘
                               │ Finance Manager + Owner override
                               ▼
                          CUSTOMER (reinstated)
```

## Lead

A **Lead** is a prospective customer captured by Sales before any commercial transaction exists.
Leads live in the shared `leads` table (owned by the Sales module — see Related Modules in the
Sales specification) but Customer Management defines the **conversion contract**: a lead becomes a
`customers` row only when it passes the mandatory-field gate (legal name, customer type, at least
one contact with a valid email or phone, at least one address) and, for Business/Government/
Non-Profit/International types, at least one legal identifier (commercial registration number, tax
registration number, or country-specific equivalent).

Conversion is a one-way, auditable operation: `POST /api/v1/sales/leads/{id}/convert` creates the
`customers` row, copies the lead's contact and address data into `customer_contacts` and
`customer_addresses`, sets `customers.lifecycle_state = 'customer'`, `customers.source_lead_id`
to the originating lead, and marks the lead `status = 'converted'`. A lead that is disqualified
(no fit, no budget, no response) is soft-deleted, never hard-deleted, so that Marketing/Sales
attribution and AI lead-scoring training data survive.

Leads never carry a credit limit, a payment-terms row, or a `customer_balances` projection — those
concepts do not exist until conversion, because a lead cannot yet transact.

## Customer

**Customer** is the active, transacting state. A row in this state:

- Can be referenced by `sales_quotations`, `sales_orders`, `invoices`, `credit_notes`, `receipts`,
  `contracts` (procurement/sales), and `subscriptions`.
- Is subject to credit-limit enforcement (see Credit Management) on every new order/invoice.
- Is included by default in all customer-facing reports (Aging, Outstanding Balances, Top
  Customers) unless explicitly filtered out.
- Is visible to the AI Risk Scoring, Payment Prediction, and Behavior Analysis agents, which
  recompute their outputs on a schedule and on every relevant domain event (invoice posted,
  payment received, invoice overdue).

A Customer automatically transitions to **Inactive** when a scheduled job
(`customers.recalculate-lifecycle`, run nightly) finds: no posted sales document
(`sales_orders`, `invoices`) in the trailing 90 days AND no open (unpaid) invoice balance AND
no active `subscriptions` row. The threshold (90 days) is a company-level setting
(`companies.settings->>'customer_inactivity_days'`), defaulting to 90, editable by an Admin.

A Customer transitions to **Blacklisted** only through an explicit action (never automatically by
the nightly job) taken by a Finance Manager or above, typically triggered by: three or more
invoices reaching the "severe" overdue bucket (see Overdue Rules) simultaneously, a confirmed fraud
flag from the AI Fraud Detection agent that a human has reviewed and accepted, a returned/bounced
payment instrument flagged twice within 12 months, or a manual policy decision (e.g., legal
dispute, regulatory sanction list match). Blacklisting is itself a workflow with a mandatory reason
code and free-text justification (see Business Rules).

## Inactive

**Inactive** customers keep their full financial history, balance, credit limit, and documents —
nothing is hidden — but are excluded from default list views, default reports, and default AI
prioritization (Collections and Risk Scoring de-prioritize but do not ignore an Inactive customer
that still has an open balance; "inactive" describes transactional activity, not payment status).

An Inactive customer with **zero balance and zero open documents** (no un-invoiced sales orders,
no undelivered deliveries, no open contracts) becomes a candidate for **Archived** status, surfaced
to Finance as a monthly cleanup report rather than auto-archived, because archiving is a workflow
step with its own approval (see Workflow).

An Inactive customer that places a new order, receives a new invoice, or makes a payment
immediately reverts to **Customer** — this transition IS automatic, triggered by the relevant
domain event (`sales.order.created`, `invoice.posted`, `payment.received`) for that customer.

## Archived

**Archived** is the terminal "no longer active, but never delete" state for a customer with zero
outstanding balance and zero open commercial documents. Archiving is soft: `customers.deleted_at`
remains NULL (soft-delete is reserved for the destructive "Delete" workflow below); instead
`customers.lifecycle_state = 'archived'` and `customers.archived_at` / `customers.archived_by` are
set. Archived customers:

- Are excluded from every default query (repositories apply
  `WHERE lifecycle_state NOT IN ('archived','blacklisted')` by default; callers must explicitly
  opt in with `include_archived=true`).
- Cannot be selected as the customer on a new sales quotation, order, or invoice via the normal UI
  — an Admin can still do so via an explicit "reactivate first" prompt, which flips the state back
  to Customer.
- Remain fully queryable for historical reporting (Revenue by Customer, Customer Lifetime Value)
  because their invoices and payments are immutable financial history that must never disappear
  from the GL reconciliation.
- Are retained indefinitely by default; a company-level data-retention policy MAY schedule
  anonymization of PII fields (name on individuals, national ID) after a jurisdiction-specific
  period, executed by a scheduled job that never touches financial amounts, only PII columns, and
  writes an audit log entry for the anonymization event itself.

## Blacklisted

**Blacklisted** is the state that blocks all new commercial activity for a customer regardless of
credit limit or balance: no new quotation, no new order, no new invoice, no new subscription
renewal can be created against a blacklisted `customer_id` — the API returns `403 Forbidden` with
error code `CUSTOMER_BLACKLISTED` on any attempt. Existing open invoices remain payable and
Collections workflows remain active (blacklisting a customer should not accidentally stop QAYD from
trying to collect money already owed).

Blacklisting requires: `reason_code` (enum: `fraud`, `non_payment`, `legal_dispute`,
`sanctions_match`, `policy_violation`, `other`), free-text `reason_notes` (mandatory, minimum 20
characters), and the acting user's `blacklisted_by`/`blacklisted_at`. Un-blacklisting
(reinstatement) requires a second, different approver at Finance Manager level or above (four-eyes
principle) plus a fresh credit-limit review — reinstatement never simply restores the old credit
limit; it defaults the customer to zero credit limit and prepaid-only terms until a Finance Manager
explicitly sets a new limit.

## Lifecycle State Reference Table

| State | Can Transact | Credit-Limit Enforced | Visible in Default Reports | Reversible By |
|---|---|---|---|---|
| Lead | No | N/A | N/A (leads reporting only) | Convert (Sales) / Disqualify |
| Customer | Yes | Yes | Yes | Auto → Inactive; Manual → Blacklisted |
| Inactive | Yes (reverts on activity) | Yes | No (opt-in) | Auto on new activity; Manual → Archived |
| Archived | No (reactivation required) | N/A | No (opt-in, history only) | Manual reactivation → Customer |
| Blacklisted | No | N/A (blocked entirely) | Yes (flagged red) | Manual reinstatement (dual approval) |

# Customer Types

`customers.customer_type` is a NOT NULL enum that drives which identifier fields are mandatory,
which default tax treatment applies, and which document checklist the onboarding workflow enforces.
QAYD supports five types.

## Individual

A natural person. Legal name is `first_name` + `last_name` (plus optional `middle_name`); the
`legal_name` computed/stored column concatenates these for display and search. Identifier is a
national ID or (for International individuals) a passport number, stored in
`customers.tax_registration_number` is NOT used for individuals without a business registration —
instead `customers.national_id` and `customers.national_id_country` capture identity. Individuals
default to `payment_terms = 'due_on_receipt'` and typically transact on a lower default credit
limit (company-configurable default, e.g., KWD 500) unless manually raised. Individuals are the
only type for which `contact_person` fields on the customer itself are meaningful without a
separate `customer_contacts` row (a `customer_contacts` primary contact is still created for
consistency, defaulted to the individual's own details).

## Business

A commercial legal entity (LLC, WLL, corporation, partnership, sole proprietorship trade license).
Mandatory identifiers: `commercial_registration_number` and, where the company's tax jurisdiction
requires it, `tax_registration_number` (e.g., Kuwait Tax Registration Number for withholding /
Saudi/UAE VAT registration number for VAT-registered businesses). Business customers require at
least one `customer_contacts` row flagged `is_primary = true` with a business email, and typically
carry multiple `customer_addresses` rows (headquarters billing address plus one or more delivery
addresses per branch/site). Business is the default type for B2B relationships and is the type
most commonly extended a material credit limit and net payment terms (`net_15`, `net_30`,
`net_60`).

## Government

A government ministry, authority, or state-owned entity. Distinguished from Business because:
government customers in Kuwait/GCC typically (a) are exempt from certain tax treatments, (b)
require a purchase-order/tender reference on every invoice (`invoices.po_reference` becomes
mandatory when `customer.customer_type = 'government'`, enforced by a Business Rule below), (c)
have materially longer, often statutory, payment cycles (net 60–120 is common and the platform must
not treat this as automatically "overdue-risky" the way it would for a Business customer at the
same day count — see Overdue Rules bucket overrides), and (d) frequently forbid early-payment
discounts or interest/penalty terms that are otherwise standard commercial practice. Government
customers require a `government_entity_code` (ministry/authority registry code) instead of a
commercial registration number, and credit-limit blocking rules are, by default, configurable to
"warn only, never hard-block" for this type, because refusing a shipment to a ministry over a credit
hold is frequently not a decision Sales is authorized to make unilaterally — it escalates instead.

## Non-Profit

A charitable, religious, or civil-society organization. Mandatory identifier is the
`nonprofit_registration_number` issued by the relevant ministry (e.g., Ministry of Social Affairs
in Kuwait). Non-Profits often qualify for tax-exempt treatment (`customers.tax_exempt = true` with
a mandatory `tax_exemption_certificate` document) and commonly transact on donation-linked or
grant-linked payment terms rather than standard commercial terms; the schema supports this via the
generic `payment_terms_id` foreign key rather than a special-cased field, keeping the model
uniform across types.

## International

Any customer type above (Individual, Business, Government, Non-Profit) whose registered country
differs from the operating company's home country, OR whose transaction currency differs from the
company's base currency as a matter of course. International is modeled as an **orthogonal flag**
(`customers.is_international boolean generated as (billing_country_code <> company_home_country)
` is not stored generated in Postgres across a cross-table lookup, so in practice it is maintained
by application logic and stored as a plain column `customers.is_international BOOLEAN NOT NULL
DEFAULT false`, recomputed whenever the billing address or preferred currency changes) rather than
a mutually exclusive fifth bucket, because an International customer is still fundamentally an
Individual, Business, Government, or Non-Profit and must keep that type's identifier rules.
International customers additionally require: `preferred_currency` different handling in payment
allocation (see Customer Profile), potential withholding-tax handling in the Tax module, and
address validation against international postal formats rather than the GCC-specific address
template (see Addresses).

## Customer Type Reference Table

| Type | Mandatory Identifier | Default Payment Terms | Default Credit Limit Behavior | Tax Default |
|---|---|---|---|---|
| Individual | `national_id` (+ country) | Due on receipt | Low, manual raise required | Standard |
| Business | `commercial_registration_number` | Net 30 | Moderate, risk-scored | Standard / VAT if registered |
| Government | `government_entity_code` | Net 60–120 (statutory) | Warn-only blocking | Often exempt/zero-rated |
| Non-Profit | `nonprofit_registration_number` | Per grant/donation terms | Low, case-by-case | Often exempt (certificate required) |
| International | (inherits base type's identifier) | Per contract, multi-currency | Risk-scored, FX-aware | Per destination-country rules |

# Customer Profile

The customer profile is the complete set of attributes describing a single `customers` row and its
directly owned child records. Each sub-topic below maps to specific columns or child tables.

## Identity

Core identity fields live directly on `customers`:

- `customer_code` — a company-scoped, human-readable, immutable identifier (e.g., `CUST-000482`),
  generated on creation by a per-company sequence, never reused even after archive/delete, and used
  in every printed document (invoice, statement) as the customer reference.
- `customer_type` — the enum discussed above.
- `legal_name` — the full legal name (business registration name, or individual full name).
- `display_name` — an optional shorter trading/"doing business as" name shown in UI lists; falls
  back to `legal_name` when null.
- `name_ar` / `name_en` — bilingual name fields per the platform's bilingual-master-data
  convention, independent of `legal_name` (which is the name as it appears on legal documents and
  may itself be in either script).
- `industry_code` — free-text-backed-by-lookup classification (e.g., ISIC-derived) used for
  segmentation, AI behavior analysis, and Gulf market reporting.
- `segment` — a company-defined customer segment (`enterprise`, `smb`, `retail`, `vip`, etc.),
  editable via custom lookup, used for price-list defaulting and reporting grouping.
- `sales_owner_id` — FK to `users`, the account owner/relationship manager, defaulting Sales
  Employee visibility and commission attribution.
- `lifecycle_state`, `status` — see Customer Lifecycle above (`status` is a simpler
  active/on-hold/closed operational flag used for quick UI badges, independent of but consistent
  with `lifecycle_state`).

## Contact Information

Contacts are modeled as a **separate child table**, `customer_contacts`, because a customer
(especially Business/Government/Non-Profit) legitimately has many people: an accounts-payable
contact, a procurement contact, a technical contact, a decision-maker. Each row carries:
`full_name`, `job_title`, `department`, `email`, `phone`, `mobile`, `preferred_contact_method`
(enum: `email`, `phone`, `sms`, `whatsapp`), `is_primary` (exactly one primary contact enforced per
customer via a partial unique index), `is_billing_contact`, `is_technical_contact`, `language`
(defaults to the customer's `preferred_language`), and `notes`. Contacts are soft-deleted, never
hard-deleted, because historical documents (an old invoice sent to a since-departed AP clerk) may
still reference the contact's name/email for record-keeping even after they leave the organization
— this is handled by keeping the contact row and marking it `deleted_at` rather than removing the
historical reference.

## Addresses

Addresses are a separate child table, `customer_addresses`, supporting an arbitrary number of
addresses per customer, each typed via `address_type` (enum: `billing`, `shipping`, `both`,
`registered_office`, `other`) and each carrying: `line1`, `line2`, `city`, `state_province`,
`postal_code`, `country_code` (ISO 3166-1 alpha-2), `is_default_billing`, `is_default_shipping`,
`latitude`/`longitude` (nullable, populated by geocoding for delivery-route optimization in
Inventory/Sales), and `formatted_address` (a denormalized single-line rendering used for print/PDF
performance so documents do not need to reassemble the address at render time). Exactly one address
per customer can be `is_default_billing = true` and one `is_default_shipping = true`, each enforced
by a partial unique index (`WHERE is_default_billing` / `WHERE is_default_shipping`).

### Billing Address

The billing address is the address used on the invoice's remit-to/bill-to header, for tax
jurisdiction determination (which tax rules apply to the transaction), and for statement mailing.
A customer's `is_default_billing` address is auto-selected on every new invoice/order but can be
overridden per document (a Business customer might bill centrally from HQ while shipping to ten
branches; each `invoices` row still stores its own snapshotted `billing_address_snapshot JSONB` at
creation time so that a later address edit never rewrites history on an already-posted invoice —
this snapshot-on-post pattern is used consistently across the platform for anything address- or
identity-related that feeds a posted financial document).

### Shipping Address

The shipping (delivery) address feeds `deliveries`/`delivery_items` in the Sales/Inventory modules.
A customer can have many shipping addresses (multiple branches, multiple job sites); each sales
order selects one at order-entry time, defaulting to `is_default_shipping`. Like the billing
address, the selected shipping address is snapshotted onto the `sales_orders`/`deliveries` row at
creation so that correcting a typo in the customer master does not retroactively alter a
document already in transit or delivered.

## Tax Information

Tax fields on `customers`: `tax_registration_number` (nullable — mandatory only for
Business/International customers registered for VAT/tax in a jurisdiction that requires it),
`tax_registration_country`, `tax_exempt BOOLEAN NOT NULL DEFAULT false`, `tax_exemption_reason`
(free text, mandatory when `tax_exempt = true`), and `default_tax_code_id` (FK to `tax_codes`,
owned by the Tax module) which pre-populates the tax code on new invoice lines for this customer
unless a line-level override applies (e.g., a specific product is zero-rated regardless of
customer). Government and Non-Profit customers frequently carry `tax_exempt = true` with a
`customer_documents` row of `document_type = 'tax_exemption_certificate'` as the supporting
evidence; the Business Rules section defines the validation that blocks marking a customer
tax-exempt without an attached, non-expired certificate.

## Payment Terms

`customers.payment_terms_id` is a FK to a company-level `payment_terms` lookup (net_0/due_on_receipt,
net_15, net_30, net_45, net_60, net_90, or a custom installment schedule) that determines the
default `due_date` calculation on new invoices (`invoice_date + terms.days`) and drives the Aging
Report bucket definitions. Payment terms can carry an `early_payment_discount_percent` and
`early_payment_discount_days` (e.g., 2/10 net 30) and a `late_payment_penalty_percent` (subject to
Government-customer restrictions noted above). A customer-level override of terms takes precedence
over the segment/company default; a document-level override (rare, requires Finance approval) takes
precedence over the customer-level setting and is recorded on the document itself, not the master.

## Credit Limit

`customers.credit_limit NUMERIC(19,4) NOT NULL DEFAULT 0` expressed in the customer's
`preferred_currency`. A value of `0` means "no credit — cash/prepaid only," not "unlimited"; QAYD
has no "unlimited credit" sentinel value by design, because unlimited credit is not a real business
policy and would defeat AI risk scoring. Full mechanics are in Credit Management below.

## Credit Rating

`customers.credit_rating` is a company-defined enum (`A`, `B`, `C`, `D`, `watch`, `unrated`) that
is distinct from — and typically an input to, alongside external factors a human considers — the
AI-generated numeric `ai_risk_score` (0–100) stored in `customer_balances` (see AI Responsibilities
and Database Design). The rating is the human-owned, policy-facing classification (used to decide
default credit-limit tiers and payment-terms tiers for new customers of a given profile); the AI
risk score is the continuously-recomputed, transaction-driven signal. Both are shown side by side
in the UI; they are allowed to disagree, and a disagreement (e.g., rating `A` but AI risk score
above 70) is itself surfaced as a flag for Finance review.

## Preferred Currency

`customers.preferred_currency CHAR(3) NOT NULL` (ISO 4217) is the currency in which the customer is
invoiced by default and in which `credit_limit` and the customer-facing balance are expressed.
Every transactional document still stores both the transaction-currency amount and the
company-base-currency amount plus the `exchange_rate` used (per the platform's multi-currency
convention), so `customer_balances` maintains both a `balance_customer_currency` and a
`balance_base_currency` column, the latter being what actually reconciles to the AR control
account in the GL (a single company's GL is always expressed in its base currency; the customer's
preferred currency is a presentation/invoicing convenience, never the ledger of record).

## Preferred Language

`customers.preferred_language CHAR(2) NOT NULL DEFAULT 'en'` (`en` or `ar`, extensible) drives which
localized template is used for invoices, statements, dunning letters, and AI-generated
communications (Pip/Clover-style assistants elsewhere in the platform reuse this field), and
defaults each new `customer_contacts.language` value.

## Documents

`customer_documents` stores metadata for compliance/legal documents scoped to the customer:
commercial registration certificate, tax registration certificate, tax exemption certificate, KYC
identification, signed master service agreement, credit application form, NDA, and similar. Each
row carries `document_type` (enum), `document_number`, `issue_date`, `expiry_date` (nullable —
many document types do not expire; some, like tax exemption certificates, do and are checked by a
scheduled job that flags/removes `tax_exempt` status on expiry), `status`
(`pending_review`/`verified`/`expired`/`rejected`), `verified_by`, `verified_at`, and an
`attachment_id` pointing at the platform's polymorphic `attachments` table where the actual file
lives in Cloudflare R2. `customer_documents` is metadata-plus-pointer; it never stores the binary.

## Attachments

Beyond the structured `customer_documents` metadata table, a customer can have ad-hoc attachments
(a scanned business card, a photo from a site visit, an email thread PDF) via the shared
polymorphic `attachments` table (`attachable_type = 'customer'`, `attachable_id = customers.id`).
`customer_documents` is for compliance-grade, typed documents with expiry/verification tracking;
generic `attachments` is for everything else. Both ultimately store objects in Cloudflare R2 with
access mediated by signed URLs — customer documents/attachments are never served as public URLs.

## Notes

Freeform, timestamped, user-attributed notes are stored in a lightweight `customer_notes` table
(`customer_id`, `body`, `is_pinned`, `visibility` enum `internal`/`shared_with_customer` — the
latter reserved for future customer-portal use, not yet exposed) rather than as a single text
column on `customers`, so that history is preserved (notes are append-only; editing a note creates
a new version rather than overwriting, mirroring the audit-log pattern) and multiple team members
can contribute without overwriting each other.

## Tags

`customers.tags JSONB NOT NULL DEFAULT '[]'` stores an array of free-text or company-controlled tag
strings (e.g., `["key-account","renewal-q3","referred-by-partner-x"]`) used for filtering, smart
search, and AI behavior-analysis feature inputs. Tags are indexed with a GIN index for
containment queries (`tags @> '["key-account"]'`).

## Custom Fields

`customers.custom_fields JSONB NOT NULL DEFAULT '{}'` gives each tenant company a schema-free
extension point (e.g., an industry-specific field like `"fleet_size": 42` for a logistics customer)
without requiring a migration. Custom field **definitions** (name, type, validation rule, which
customer types they apply to) are themselves stored in a company-level `custom_field_definitions`
table (owned by the platform's shared settings module) so the frontend can render the right input
control and the backend `FormRequest` can validate against the declared type before persisting into
the JSONB column. Custom fields are indexed with a GIN index for ad-hoc querying and are exposed in
Smart Search.

# Customer Relationships

The `customers` row is the hub of a star of relationships to nearly every commercial and financial
document type in QAYD. Customer Management owns the hub and the two immediate satellites
(Contacts, Addresses already covered above); it does not own the spokes, but it defines exactly how
each spoke must reference the hub.

## Companies

Every `customers` row belongs to exactly one tenant `companies` row via the mandatory, indexed
`company_id`. A single real-world business entity that transacts with two different QAYD tenant
companies (e.g., a shared customer of two sister companies operating on the same QAYD instance)
is represented as **two separate `customers` rows**, one per company, deliberately — QAYD's
multi-tenancy guarantee is that Company A can never read Company B's data, and a cross-company
"global customer" concept would violate that isolation. Where a group of sister companies wants a
consolidated view of a shared customer's total exposure across entities, that is a reporting-layer
concern (a cross-company report available only to a user with access to both companies) built on
top of, never inside, the isolated per-company `customers` tables.

## Branches

`customers.branch_id` (nullable) optionally scopes a customer to a specific branch of the *selling*
company when the tenant uses branch-level isolation (a retail company where each physical branch
manages its own local customer book). When set, the customer is visible only to users scoped to
that branch (plus company-wide roles); when null, the customer is company-wide. Branch scoping is
independent of the customer's own addresses, which describe where the *customer* is located, not
which branch of the seller owns the relationship.

## Contacts

Covered in Customer Profile → Contact Information. Relationship note: `customer_contacts` rows are
also referenced from `sales_quotations.contact_id` / `sales_orders.contact_id` /
`invoices.contact_id` (nullable) when a specific document needs to record which named individual
requested or received it, distinct from the customer-level default primary contact.

## Projects

`projects.customer_id` (nullable FK) links a project dimension to the customer it is being executed
for. This lets QAYD report project profitability by customer and lets journal lines carry
`project_id` as a dimension while still resolving back to the paying customer for AR purposes. A
customer can have any number of concurrent or historical projects; a project has at most one
customer (internal/overhead projects have `customer_id IS NULL`).

## Sales

`sales_quotations.customer_id`, `sales_orders.customer_id` are mandatory FKs. Customer Management
is consulted at quotation time (to pre-fill price list, currency, tax defaults) and, critically, at
order-confirmation time (to run the credit-limit check described in Credit Management — a
quotation can be issued to a customer over their credit limit as a courtesy/negotiation tool, but
converting that quotation into a confirmed sales order is where the credit block, if any, is
enforced).

## Invoices

`invoices.customer_id` is mandatory. Posting an invoice (`draft → posted`) is the event that
increases the customer's outstanding balance in `customer_balances` and is the trigger for the
`invoice.posted` domain event that the AI Payment Prediction agent consumes to (re)estimate a
predicted payment date. Every invoice snapshots the billing address, the tax code, and the payment
terms **as they were at posting time** onto the invoice itself (see Addresses), so a later edit to
the customer master is never retroactive to already-posted financial documents — corrections to a
posted invoice happen via credit notes, never via master-data edits reaching backward in time.

## Payments

`receipts.customer_id` (a receipt is money received against one or more invoices, via
`receipt_allocations`) reduces the customer's outstanding balance. A receipt can be received before
being allocated (on-account payment sitting as an unapplied credit against the customer) —
Customer Management's `customer_balances` distinguishes `balance_open_invoices`,
`balance_unapplied_credits`, and `balance_net` (open invoices minus unapplied credits minus
available credit notes) so that Finance never double-counts a payment that has been received but
not yet matched to a specific invoice.

## Returns

Returns are represented as `credit_notes` (see Sales module) with `credit_notes.customer_id`
mandatory. A credit note reduces the customer's outstanding balance identically to a payment for
balance-computation purposes, but is tracked separately in `customer_balances` as
`balance_credit_notes_available` because an unapplied credit note can either be refunded (cash out)
or applied against a future invoice, and Collections/Finance need to see the two paths distinctly
before the customer's net exposure is finalized.

## Contracts

`procurement_contracts` (Purchasing) has no customer relevance; the relevant table here is a
sales-side contract concept modeled generically as `contracts` with `party_type = 'customer'`,
`party_id = customers.id` (this generic contracts table, shared between Sales and Purchasing
contract use-cases, lives in the Sales module's canonical table list and is referenced, not owned,
here). A contract typically governs pricing (linking to a specific `price_lists` row), payment
terms overriding the customer default for the contract's duration, and sometimes a committed
volume/spend that feeds Revenue Forecasting.

## Subscriptions

`subscriptions.customer_id` (Sales/Billing) drives recurring invoicing. Customer Management's
relevance: a customer with an active subscription is protected from the automatic Customer →
Inactive transition (an active subscription counts as "activity" even in a quiet period between
renewal invoices), and subscription cancellation/non-renewal is one of the strongest negative
features fed into the AI Behavior Analysis and Revenue Forecasting agents.

## Relationship Cardinality Summary

| Related Entity | Cardinality (Customer : Entity) | Owning Module | FK Column |
|---|---|---|---|
| `customer_contacts` | 1 : many | Customers (this doc) | `customer_contacts.customer_id` |
| `customer_addresses` | 1 : many | Customers (this doc) | `customer_addresses.customer_id` |
| `customer_documents` | 1 : many | Customers (this doc) | `customer_documents.customer_id` |
| `projects` | 1 : many | Sales/Projects | `projects.customer_id` (nullable) |
| `sales_quotations` | 1 : many | Sales | `sales_quotations.customer_id` |
| `sales_orders` | 1 : many | Sales | `sales_orders.customer_id` |
| `invoices` | 1 : many | Sales | `invoices.customer_id` |
| `receipts` | 1 : many | Sales/Banking | `receipts.customer_id` |
| `credit_notes` | 1 : many | Sales | `credit_notes.customer_id` |
| `contracts` | 1 : many | Sales | `contracts.party_id` where `party_type='customer'` |
| `subscriptions` | 1 : many | Sales/Billing | `subscriptions.customer_id` |

# Business Rules

1. **Mandatory identifier by type.** A `customers` row cannot transition from Lead conversion (or
   be created directly as a Customer) without the type-appropriate legal identifier populated
   (see Customer Types table). Enforced in the `StoreCustomerRequest`/`UpdateCustomerRequest`
   FormRequest with a type-conditional validation rule, and again at the database level with a
   `CHECK` constraint (see Database Design) as defense in depth.
2. **One primary contact.** Exactly one `customer_contacts` row per customer may have
   `is_primary = true`, enforced by a partial unique index. Creating a new primary contact
   automatically un-sets the previous one inside the same transaction.
3. **One default billing / one default shipping address.** Enforced analogously via partial unique
   indexes on `customer_addresses`.
4. **Tax exemption requires evidence.** `customers.tax_exempt` cannot be set `true` unless a
   `customer_documents` row of type `tax_exemption_certificate` exists with `status = 'verified'`
   and (`expiry_date IS NULL OR expiry_date > now()`). A scheduled job flips `tax_exempt` back to
   `false` and raises a `notifications` entry to Finance when the certificate expires.
5. **Government PO reference mandatory.** When `customers.customer_type = 'government'`, any
   `invoices` row for that customer requires a non-null `po_reference`; the Sales module's
   `InvoiceService` calls Customer Management's `CustomerPolicyService::requiresPoReference()`
   before allowing posting.
6. **Credit-limit check on order confirmation, not quotation.** See Credit Management.
7. **No hard delete once financial history exists.** A customer with any posted `invoices`,
   `receipts`, `credit_notes`, or non-zero `customer_balances.balance_net` can never be hard
   (destructively) deleted. The only destructive path available is Archive (soft, reversible) or,
   for a customer with truly zero history, a genuine row delete gated by an extra confirmation and
   restricted to Admin/Owner (see Workflow → Delete).
8. **Currency consistency.** An invoice's transaction currency must equal either the customer's
   `preferred_currency` or the company's base currency; any other currency requires an explicit
   Finance override flag on the document (rare, logged).
9. **Blacklist blocks all new commercial documents.** Enforced at the Service layer (not just the
   UI) for `sales_quotations`, `sales_orders`, `invoices`, `subscriptions` creation — every
   creation path calls `CustomerPolicyService::assertCanTransact($customer)`.
10. **Merge is one-directional and irreversible in effect (but audited and reversible via restore
    from audit log within 30 days).** See Workflow → Merge.
11. **Every balance-affecting event recalculates `customer_balances` synchronously within the same
    DB transaction that posted the source document** (invoice posted, payment allocated, credit
    note applied), never via an eventually-consistent async job for the core balance figure — AI
    risk scoring and reporting aggregates may be async, but the customer's own balance figure must
    be immediately correct because Credit Management checks it synchronously.
12. **A customer's `preferred_currency` cannot be changed while an open (unpaid) invoice in a
    different currency exists** without Finance approval, because it would change how the Aging
    Report and Credit Management present the customer's exposure mid-flight.
13. **Duplicate prevention.** Creating a customer whose normalized `legal_name` +
    `tax_registration_number`/`national_id`/`commercial_registration_number` matches an existing,
    non-archived customer in the same company is blocked with `409 Conflict` and a pointer to the
    existing record, backed by both a database-level partial unique index on the identifier and the
    AI Duplicate Detection agent's fuzzy pre-check on `legal_name` alone (see AI Responsibilities).
14. **International customers must set `preferred_currency`.** It may not simply inherit the
    company base currency by default the way a domestic customer's does, to force an explicit
    invoicing-currency decision at onboarding.
15. **Rating/limit changes above a threshold require approval.** Any change to `credit_limit` that
    increases it by more than a company-configured percentage (default 50%) or above a
    company-configured absolute ceiling (default KWD 10,000) requires Finance Manager approval
    before taking effect (see Workflow → Approval and Credit Management).

# Credit Management

Credit Management is the enforcement layer that sits between the commercial intent captured in
Sales (a quotation, an order) and the financial exposure recorded in Customer Management's
`customer_balances`. Its job is to make sure a company never extends more unsecured credit to a
customer than policy allows, without becoming a bottleneck that blocks legitimate, low-risk sales.

## Credit Limit

`customers.credit_limit` (NUMERIC(19,4), in `preferred_currency`) is the ceiling on
**`balance_net`** (open invoices + un-invoiced confirmed orders − unapplied credits − available
credit notes) that Customer Management will allow before blocking new commercial documents.

Credit exposure is computed, not stored redundantly, by `CustomerCreditService::getExposure
($customerId)`:

```
exposure = SUM(open invoice amounts, base currency)
         + SUM(confirmed but not yet invoiced sales order amounts, base currency)
         - SUM(unapplied receipt credits, base currency)
         - SUM(available (unapplied) credit note amounts, base currency)
```

converted to the customer's `preferred_currency` at the current exchange rate for display, but
**the blocking comparison itself is always performed in base currency** to avoid FX-rate gaming
(a customer's exposure must not appear artificially low because of a stale exchange-rate snapshot
on the credit-limit field).

Credit-limit checks fire at two points:

1. **Sales order confirmation** (`draft`/`quotation` → `confirmed`): if
   `exposure + this_order_amount > credit_limit`, the order is held in a `credit_hold` sub-status
   rather than confirmed, and a `notifications` entry is raised to the assigned Sales Manager and
   the account's Finance approver. Government customers are, by default, **warn-only** at this
   gate (see Customer Types) — the order proceeds but the hold event is still logged and notified.
2. **Invoice posting**, as a second, defense-in-depth check, in case the exposure changed between
   order confirmation and invoicing (e.g., another order was confirmed in the interim) — invoices
   are never silently blocked at posting without the same hold/notify mechanism; posting is denied
   with `422` and error code `CREDIT_LIMIT_EXCEEDED` unless an authorized override is attached.

A **credit-limit override** is a first-class, audited action: `Finance Manager` (or above) may
approve a specific document to proceed over the limit via
`POST /api/v1/accounting/customers/{id}/credit-overrides` with a mandatory reason and an optional
expiry (e.g., "approved for this one order only" vs. "approved for the next 30 days while a formal
limit increase is processed"). Overrides are logged in `customer_credit_overrides` and surfaced on
the customer's timeline.

## Overdue Rules

An invoice is **overdue** when `current_date > invoices.due_date` and `invoices.status IN
('posted','partially_paid')`. Overdue severity is bucketed for Aging Report and blocking-rule
purposes using a company-configurable bucket table, defaulting to:

| Bucket | Days Overdue | Default Behavior |
|---|---|---|
| Current | ≤ 0 (not yet due) | No action |
| 1–30 | 1–30 | Informational; included in Aging |
| 31–60 | 31–60 | AI Payment Prediction re-run; soft reminder notification |
| 61–90 | 61–90 | Escalation notification to Sales Owner + Collections; new orders flagged for review |
| 90+ (severe) | 90+ | Triggers Blocking Rules (below); Collections workflow mandatory |

Government customers use a separately configured bucket table with wider thresholds (statutory
payment cycles), and Non-Profit customers on grant-linked terms can be configured with buckets
anchored to the grant's disbursement schedule rather than the invoice date, via a
`payment_terms.due_date_basis` setting (`invoice_date` default, or `custom_schedule` referencing an
installment plan).

## Blocking Rules

Blocking is layered, from softest to hardest:

1. **Hold (soft)** — new sales order confirmation is paused pending Finance review; existing orders
   and invoicing continue unaffected. Triggered by credit-limit breach (see above).
2. **New-order block (medium)** — no new `sales_orders` may be confirmed for the customer at all,
   regardless of amount, once any invoice reaches the "severe" (90+) bucket. Existing confirmed
   orders may still be invoiced and delivered (the company already committed the goods/service).
   Configurable per company: some tenants prefer to also block delivery of already-confirmed
   orders at this stage — exposed as `companies.settings->>'block_delivery_on_severe_overdue'`.
3. **Full block (hard)** — equivalent to, and in practice implemented via, transition to
   **Blacklisted** lifecycle state (see Customer Lifecycle). No new quotations, orders, invoices,
   or subscription renewals of any kind. This is never automatic; it requires a Finance Manager
   decision even when the nightly job flags the customer as a full-block candidate (the job creates
   a `notifications`-driven task, not an automatic state change).

Blocking rules are evaluated by a scheduled nightly job (`credit.evaluate-blocking-rules`) and
re-evaluated synchronously on every `invoice.posted`, `payment.received`, and
`sales.order.confirmed` domain event for the affected customer, so a customer is never left in a
stale blocking state for more than the time between events.

## Collections

Collections is the workflow that recovers overdue balances before they require write-off. It is
modeled as a state machine on top of overdue invoices, tracked in a `customer_collection_cases`
table (owned by this submodule, referencing `customer_id` and an array/junction of the specific
overdue `invoice_id`s in scope):

```
open → contacted → promise_to_pay → broken_promise → escalated → legal_referral
                                  ↘ paid_in_full (closed) ↗
```

- **open** — case auto-created when an invoice enters the 61–90 bucket (configurable trigger
  bucket).
- **contacted** — a Collections Officer (or the AI Collections/Reporting assistant drafting, human
  sending) has sent a dunning communication; `last_contacted_at` and `contact_method` recorded.
- **promise_to_pay** — customer has committed to a date; `promised_pay_date` recorded; the AI
  Payment Prediction agent's estimate is shown alongside the customer's own promise so Collections
  can see whether the promise is realistic.
- **broken_promise** — `promised_pay_date` passed with no payment; auto-transitions from
  `promise_to_pay`, raises priority, and typically triggers the next dunning tier.
- **escalated** — case handed to a Finance Manager/Collections lead; commonly paired with a
  blocking-rule tightening.
- **legal_referral** — outside the scope of QAYD's workflow itself beyond recording the referral
  date and referencing an uploaded `customer_documents` legal-notice document; QAYD does not manage
  litigation.
- **paid_in_full** — closes the case; triggered automatically when the referenced invoices' balance
  reaches zero.

Every dunning communication sent is itself logged (channel, template used, sender, timestamp) and
surfaces on the customer timeline, giving Finance a full paper trail for any customer that
ultimately requires write-off or legal action. Write-off itself is executed in Accounting via a
journal entry (bad-debt expense debit, AR credit) referencing the customer and the specific
invoice(s); Customer Management's role is limited to exposing the aged, uncollected balance that
justifies the write-off and to closing the `customer_collection_cases` row when the write-off
journal entry posts (`journal.posted` event with a `write_off_customer_id` metadata field).

# Database Design

## Tables

Customer Management owns five tables: `customers`, `customer_contacts`, `customer_addresses`,
`customer_documents`, and `customer_balances`. It also owns two small supporting tables introduced
above, `customer_notes` and `customer_credit_overrides`, plus `customer_collection_cases`. All
tables carry the platform's standard columns (`id`, `company_id`, `branch_id`, `created_by`,
`updated_by`, `created_at`, `updated_at`, `deleted_at`) in addition to the columns listed explicitly
below; the standard columns are omitted from the DDL comments for brevity but ARE part of every
`CREATE TABLE` statement.

```sql
-- ============================================================
-- ENUM TYPES
-- ============================================================

CREATE TYPE customer_type_enum AS ENUM (
    'individual', 'business', 'government', 'non_profit'
);
-- NOTE: 'international' is NOT a member of this enum — see Customer Types section.
-- International is the boolean customers.is_international flag layered on top of one
-- of the four base types.

CREATE TYPE customer_lifecycle_state_enum AS ENUM (
    'lead', 'customer', 'inactive', 'archived', 'blacklisted'
);
-- 'lead' is included for completeness/history but a converted customer never reverts to it;
-- pre-conversion leads live in the Sales module's leads table, not here.

CREATE TYPE customer_status_enum AS ENUM (
    'active', 'on_hold', 'closed'
);

CREATE TYPE credit_rating_enum AS ENUM (
    'a', 'b', 'c', 'd', 'watch', 'unrated'
);

CREATE TYPE address_type_enum AS ENUM (
    'billing', 'shipping', 'both', 'registered_office', 'other'
);

CREATE TYPE contact_method_enum AS ENUM (
    'email', 'phone', 'sms', 'whatsapp'
);

CREATE TYPE customer_document_type_enum AS ENUM (
    'commercial_registration', 'tax_registration_certificate', 'tax_exemption_certificate',
    'national_id', 'passport', 'government_entity_certificate', 'nonprofit_registration',
    'master_service_agreement', 'nda', 'credit_application', 'bank_reference', 'other'
);

CREATE TYPE customer_document_status_enum AS ENUM (
    'pending_review', 'verified', 'expired', 'rejected'
);

CREATE TYPE blacklist_reason_enum AS ENUM (
    'fraud', 'non_payment', 'legal_dispute', 'sanctions_match', 'policy_violation', 'other'
);

CREATE TYPE collection_case_state_enum AS ENUM (
    'open', 'contacted', 'promise_to_pay', 'broken_promise', 'escalated',
    'legal_referral', 'paid_in_full', 'closed_unrecoverable'
);
```

```sql
-- ============================================================
-- customers
-- ============================================================
CREATE TABLE customers (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    branch_id                   BIGINT NULL REFERENCES branches(id),

    customer_code               VARCHAR(32) NOT NULL,
    customer_type                customer_type_enum NOT NULL,
    is_international             BOOLEAN NOT NULL DEFAULT false,

    legal_name                  VARCHAR(255) NOT NULL,
    display_name                VARCHAR(255) NULL,
    name_en                     VARCHAR(255) NULL,
    name_ar                     VARCHAR(255) NULL,
    first_name                  VARCHAR(120) NULL,
    last_name                   VARCHAR(120) NULL,
    middle_name                 VARCHAR(120) NULL,

    national_id                 VARCHAR(64) NULL,
    national_id_country         CHAR(2) NULL,
    commercial_registration_number VARCHAR(64) NULL,
    government_entity_code       VARCHAR(64) NULL,
    nonprofit_registration_number VARCHAR(64) NULL,
    tax_registration_number      VARCHAR(64) NULL,
    tax_registration_country     CHAR(2) NULL,
    tax_exempt                   BOOLEAN NOT NULL DEFAULT false,
    tax_exemption_reason         TEXT NULL,
    default_tax_code_id          BIGINT NULL REFERENCES tax_codes(id),

    industry_code                VARCHAR(32) NULL,
    segment                      VARCHAR(64) NULL,
    sales_owner_id                BIGINT NULL REFERENCES users(id),
    source_lead_id                BIGINT NULL REFERENCES leads(id),

    payment_terms_id             BIGINT NULL REFERENCES payment_terms(id),
    credit_limit                 NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit_rating                 credit_rating_enum NOT NULL DEFAULT 'unrated',
    preferred_currency            CHAR(3) NOT NULL DEFAULT 'KWD',
    preferred_language            CHAR(2) NOT NULL DEFAULT 'en',
    price_list_id                 BIGINT NULL REFERENCES price_lists(id),

    lifecycle_state               customer_lifecycle_state_enum NOT NULL DEFAULT 'customer',
    status                        customer_status_enum NOT NULL DEFAULT 'active',
    inactive_since                 TIMESTAMPTZ NULL,
    archived_at                    TIMESTAMPTZ NULL,
    archived_by                    BIGINT NULL REFERENCES users(id),
    blacklisted_at                 TIMESTAMPTZ NULL,
    blacklisted_by                 BIGINT NULL REFERENCES users(id),
    blacklist_reason_code          blacklist_reason_enum NULL,
    blacklist_reason_notes         TEXT NULL,
    reinstated_at                  TIMESTAMPTZ NULL,
    reinstated_by                  BIGINT NULL REFERENCES users(id),

    tags                           JSONB NOT NULL DEFAULT '[]',
    custom_fields                  JSONB NOT NULL DEFAULT '{}',

    merged_into_customer_id        BIGINT NULL REFERENCES customers(id),

    created_by                    BIGINT NULL REFERENCES users(id),
    updated_by                    BIGINT NULL REFERENCES users(id),
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                    TIMESTAMPTZ NULL,

    CONSTRAINT uq_customers_company_code UNIQUE (company_id, customer_code),

    CONSTRAINT chk_customers_identifier_by_type CHECK (
        (customer_type = 'individual' AND national_id IS NOT NULL)
        OR (customer_type = 'business' AND commercial_registration_number IS NOT NULL)
        OR (customer_type = 'government' AND government_entity_code IS NOT NULL)
        OR (customer_type = 'non_profit' AND nonprofit_registration_number IS NOT NULL)
    ),
    CONSTRAINT chk_customers_tax_exempt_reason CHECK (
        tax_exempt = false OR tax_exemption_reason IS NOT NULL
    ),
    CONSTRAINT chk_customers_blacklist_fields CHECK (
        lifecycle_state <> 'blacklisted'
        OR (blacklisted_at IS NOT NULL AND blacklist_reason_code IS NOT NULL
            AND length(blacklist_reason_notes) >= 20)
    ),
    CONSTRAINT chk_customers_credit_limit_nonnegative CHECK (credit_limit >= 0)
);

CREATE INDEX idx_customers_company_id ON customers (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_customers_branch_id ON customers (branch_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_customers_lifecycle_state ON customers (company_id, lifecycle_state);
CREATE INDEX idx_customers_sales_owner ON customers (sales_owner_id);
CREATE INDEX idx_customers_legal_name_trgm ON customers USING GIN (legal_name gin_trgm_ops);
CREATE INDEX idx_customers_tags_gin ON customers USING GIN (tags);
CREATE INDEX idx_customers_custom_fields_gin ON customers USING GIN (custom_fields);
CREATE UNIQUE INDEX uq_customers_tax_reg_active
    ON customers (company_id, tax_registration_number)
    WHERE deleted_at IS NULL AND tax_registration_number IS NOT NULL;
CREATE UNIQUE INDEX uq_customers_commercial_reg_active
    ON customers (company_id, commercial_registration_number)
    WHERE deleted_at IS NULL AND commercial_registration_number IS NOT NULL;
CREATE UNIQUE INDEX uq_customers_national_id_active
    ON customers (company_id, national_id, national_id_country)
    WHERE deleted_at IS NULL AND national_id IS NOT NULL;

-- ============================================================
-- customer_contacts
-- ============================================================
CREATE TABLE customer_contacts (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    customer_id           BIGINT NOT NULL REFERENCES customers(id),

    full_name             VARCHAR(255) NOT NULL,
    job_title              VARCHAR(120) NULL,
    department             VARCHAR(120) NULL,
    email                  VARCHAR(255) NULL,
    phone                  VARCHAR(32) NULL,
    mobile                 VARCHAR(32) NULL,
    preferred_contact_method contact_method_enum NOT NULL DEFAULT 'email',
    language               CHAR(2) NOT NULL DEFAULT 'en',

    is_primary             BOOLEAN NOT NULL DEFAULT false,
    is_billing_contact      BOOLEAN NOT NULL DEFAULT false,
    is_technical_contact    BOOLEAN NOT NULL DEFAULT false,
    notes                  TEXT NULL,

    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL,

    CONSTRAINT chk_contacts_email_or_phone CHECK (email IS NOT NULL OR phone IS NOT NULL OR mobile IS NOT NULL)
);

CREATE INDEX idx_customer_contacts_customer_id ON customer_contacts (customer_id) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_customer_contacts_primary
    ON customer_contacts (customer_id) WHERE is_primary = true AND deleted_at IS NULL;
CREATE INDEX idx_customer_contacts_email ON customer_contacts (email) WHERE deleted_at IS NULL;

-- ============================================================
-- customer_addresses
-- ============================================================
CREATE TABLE customer_addresses (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    customer_id           BIGINT NOT NULL REFERENCES customers(id),

    address_type           address_type_enum NOT NULL DEFAULT 'both',
    line1                  VARCHAR(255) NOT NULL,
    line2                  VARCHAR(255) NULL,
    city                   VARCHAR(120) NOT NULL,
    state_province          VARCHAR(120) NULL,
    postal_code             VARCHAR(32) NULL,
    country_code            CHAR(2) NOT NULL,
    latitude                NUMERIC(9,6) NULL,
    longitude               NUMERIC(9,6) NULL,
    formatted_address        TEXT NULL,

    is_default_billing       BOOLEAN NOT NULL DEFAULT false,
    is_default_shipping       BOOLEAN NOT NULL DEFAULT false,

    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL
);

CREATE INDEX idx_customer_addresses_customer_id ON customer_addresses (customer_id) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_customer_addresses_default_billing
    ON customer_addresses (customer_id) WHERE is_default_billing = true AND deleted_at IS NULL;
CREATE UNIQUE INDEX uq_customer_addresses_default_shipping
    ON customer_addresses (customer_id) WHERE is_default_shipping = true AND deleted_at IS NULL;
CREATE INDEX idx_customer_addresses_country ON customer_addresses (country_code);

-- ============================================================
-- customer_documents
-- ============================================================
CREATE TABLE customer_documents (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    customer_id           BIGINT NOT NULL REFERENCES customers(id),

    document_type          customer_document_type_enum NOT NULL,
    document_number         VARCHAR(120) NULL,
    issue_date              DATE NULL,
    expiry_date              DATE NULL,
    status                  customer_document_status_enum NOT NULL DEFAULT 'pending_review',
    verified_by              BIGINT NULL REFERENCES users(id),
    verified_at              TIMESTAMPTZ NULL,
    rejection_reason          TEXT NULL,
    attachment_id             BIGINT NULL REFERENCES attachments(id),

    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL,

    CONSTRAINT chk_customer_documents_rejection CHECK (
        status <> 'rejected' OR rejection_reason IS NOT NULL
    )
);

CREATE INDEX idx_customer_documents_customer_id ON customer_documents (customer_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_customer_documents_expiry ON customer_documents (expiry_date) WHERE expiry_date IS NOT NULL;
CREATE INDEX idx_customer_documents_type ON customer_documents (customer_id, document_type);

-- ============================================================
-- customer_balances  (materialized sub-ledger projection)
-- ============================================================
CREATE TABLE customer_balances (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    customer_id                 BIGINT NOT NULL REFERENCES customers(id),

    balance_open_invoices_base    NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_open_invoices_ccy     NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_unapplied_credits_base NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_credit_notes_available_base NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_unbilled_orders_base    NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_net_base                NUMERIC(19,4) NOT NULL DEFAULT 0,
    balance_net_ccy                 NUMERIC(19,4) NOT NULL DEFAULT 0,
    exchange_rate_used              NUMERIC(18,6) NOT NULL DEFAULT 1,

    days_sales_outstanding           NUMERIC(6,2) NULL,
    oldest_overdue_days               INTEGER NOT NULL DEFAULT 0,
    overdue_bucket_1_30_base          NUMERIC(19,4) NOT NULL DEFAULT 0,
    overdue_bucket_31_60_base         NUMERIC(19,4) NOT NULL DEFAULT 0,
    overdue_bucket_61_90_base         NUMERIC(19,4) NOT NULL DEFAULT 0,
    overdue_bucket_90_plus_base        NUMERIC(19,4) NOT NULL DEFAULT 0,

    ai_risk_score                    SMALLINT NULL,
    ai_risk_score_updated_at          TIMESTAMPTZ NULL,
    ai_predicted_next_payment_date      DATE NULL,
    ai_predicted_payment_confidence    NUMERIC(5,4) NULL,
    ai_churn_probability               NUMERIC(5,4) NULL,
    lifetime_revenue_base               NUMERIC(19,4) NOT NULL DEFAULT 0,
    lifetime_invoice_count               INTEGER NOT NULL DEFAULT 0,
    last_invoice_date                    DATE NULL,
    last_payment_date                     DATE NULL,

    recalculated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_customer_balances_customer UNIQUE (customer_id),
    CONSTRAINT chk_customer_balances_risk_score CHECK (
        ai_risk_score IS NULL OR (ai_risk_score BETWEEN 0 AND 100)
    )
);

CREATE INDEX idx_customer_balances_company_id ON customer_balances (company_id);
CREATE INDEX idx_customer_balances_net_desc ON customer_balances (company_id, balance_net_base DESC);
CREATE INDEX idx_customer_balances_overdue90 ON customer_balances (company_id, overdue_bucket_90_plus_base)
    WHERE overdue_bucket_90_plus_base > 0;
CREATE INDEX idx_customer_balances_risk_score ON customer_balances (company_id, ai_risk_score DESC NULLS LAST);
```

```sql
-- ============================================================
-- Supporting tables: notes, credit overrides, collection cases
-- ============================================================
CREATE TABLE customer_notes (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    customer_id       BIGINT NOT NULL REFERENCES customers(id),
    body              TEXT NOT NULL,
    is_pinned          BOOLEAN NOT NULL DEFAULT false,
    visibility          VARCHAR(24) NOT NULL DEFAULT 'internal',
    supersedes_note_id  BIGINT NULL REFERENCES customer_notes(id),
    created_by         BIGINT NULL REFERENCES users(id),
    updated_by         BIGINT NULL REFERENCES users(id),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at         TIMESTAMPTZ NULL
);
CREATE INDEX idx_customer_notes_customer_id ON customer_notes (customer_id) WHERE deleted_at IS NULL;

CREATE TABLE customer_credit_overrides (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    customer_id       BIGINT NOT NULL REFERENCES customers(id),
    document_type      VARCHAR(32) NOT NULL,   -- 'sales_order' | 'invoice'
    document_id         BIGINT NOT NULL,
    approved_by          BIGINT NOT NULL REFERENCES users(id),
    reason               TEXT NOT NULL,
    expires_at            TIMESTAMPTZ NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_customer_credit_overrides_customer ON customer_credit_overrides (customer_id);
CREATE INDEX idx_customer_credit_overrides_document ON customer_credit_overrides (document_type, document_id);

CREATE TABLE customer_collection_cases (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    customer_id       BIGINT NOT NULL REFERENCES customers(id),
    state              collection_case_state_enum NOT NULL DEFAULT 'open',
    invoice_ids          BIGINT[] NOT NULL,
    total_amount_base     NUMERIC(19,4) NOT NULL,
    assigned_to           BIGINT NULL REFERENCES users(id),
    last_contacted_at      TIMESTAMPTZ NULL,
    contact_method          contact_method_enum NULL,
    promised_pay_date        DATE NULL,
    escalated_at             TIMESTAMPTZ NULL,
    legal_referral_at         TIMESTAMPTZ NULL,
    closed_at                 TIMESTAMPTZ NULL,
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_collection_cases_customer ON customer_collection_cases (customer_id);
CREATE INDEX idx_collection_cases_state ON customer_collection_cases (company_id, state)
    WHERE state NOT IN ('paid_in_full','closed_unrecoverable');
```

## Relationships

- `customers.company_id → companies.id` — mandatory, every row scoped to exactly one tenant.
- `customers.branch_id → branches.id` — optional branch scoping of the seller side.
- `customers.sales_owner_id → users.id` — the relationship-owning user.
- `customers.source_lead_id → leads.id` — provenance back to the originating Sales lead, nullable
  because a customer can also be created directly (e.g., a walk-in retail sale) without a lead.
- `customers.default_tax_code_id → tax_codes.id` — default tax treatment, owned by Tax module.
- `customers.payment_terms_id → payment_terms.id` — default terms, owned by a shared lookup table.
- `customers.price_list_id → price_lists.id` — default pricing, owned by Products/Sales module.
- `customers.merged_into_customer_id → customers.id` — self-referencing FK populated only when a
  duplicate customer is merged away (see Workflow → Merge); a non-null value here means "do not
  transact against this row, redirect to the target."
- `customer_contacts.customer_id`, `customer_addresses.customer_id`,
  `customer_documents.customer_id`, `customer_notes.customer_id`,
  `customer_credit_overrides.customer_id`, `customer_collection_cases.customer_id` — all
  one-to-many child relationships cascading in application logic (never `ON DELETE CASCADE` at the
  DB level, because customers are never hard-deleted while children exist — see Business Rules).
- `customer_balances.customer_id` — strict one-to-one (`UNIQUE` constraint), rebuilt/upserted, never
  multiplied.
- Outward relationships from other modules: `sales_quotations.customer_id`,
  `sales_orders.customer_id`, `invoices.customer_id`, `receipts.customer_id`,
  `credit_notes.customer_id`, `subscriptions.customer_id`, `contracts.party_id` (where
  `party_type='customer'`), `projects.customer_id` — all documented in Customer Relationships above.

## Indexes

Beyond the indexes declared inline with each `CREATE TABLE` above, the following composite and
partial indexes are required for the query patterns this module must serve at low latency even at
tens of millions of rows:

- `idx_customers_company_id` and `idx_customers_lifecycle_state` are partial/composite specifically
  because the overwhelming majority of queries filter to a single company and to non-archived,
  non-deleted rows — Postgres partial indexes keep these small and fast even as archived history
  accumulates.
- `idx_customers_legal_name_trgm` (GIN + `pg_trgm`) backs both the Smart Search feature and
  human-typed customer lookups that need fuzzy/typo-tolerant matching (`legal_name ILIKE`
  and trigram similarity queries), which a plain B-tree index cannot serve efficiently.
- `idx_customer_balances_overdue90` is a partial index (`WHERE overdue_bucket_90_plus_base > 0`)
  so the nightly blocking-rule job and the Collections dashboard can scan only at-risk customers
  instead of the full table.
- `idx_customer_balances_net_desc` supports the Top Customers and Credit Exposure reports, which
  always sort by outstanding/net balance descending.
- All foreign-key columns receive a plain B-tree index even where not shown explicitly above,
  per the platform-wide convention that every FK is indexed (enforced by a migration-review
  checklist, not by the database itself).

## Constraints

- `CHECK` constraints enforce, at the database level as defense-in-depth behind FormRequest
  validation: type-conditional mandatory identifiers (`chk_customers_identifier_by_type`),
  tax-exemption evidence (`chk_customers_tax_exempt_reason`), blacklist field completeness
  (`chk_customers_blacklist_fields`), non-negative credit limits
  (`chk_customers_credit_limit_nonnegative`), contact reachability
  (`chk_contacts_email_or_phone`), and rejection-reason completeness on documents
  (`chk_customer_documents_rejection`).
- Partial unique indexes enforce "at most one" invariants that a plain `UNIQUE` constraint cannot
  express because they must ignore soft-deleted rows: one active tax registration number per
  company, one active commercial registration number per company, one active national ID per
  company, one primary contact per customer, one default billing address per customer, one default
  shipping address per customer.
- `customer_balances.customer_id UNIQUE` guarantees the one-to-one sub-ledger projection invariant.
- All FKs use `ON UPDATE CASCADE` (surrogate integer PKs never change, so this is inert in practice
  but kept for consistency with the platform's `ON UPDATE CASCADE` convention) and explicitly
  **do not** use `ON DELETE CASCADE`; the application layer (Service classes) is responsible for
  the archive-not-delete semantics and for blocking any hard delete while dependents exist.

## History

Customer Management does not implement a separate row-versioning ("history") table for the
`customers` row itself; instead, history is captured through three complementary mechanisms that
together give a complete, queryable timeline without doubling storage of every column on every
change:

1. **Audit logs** (`audit_logs`, foundation table) capture every field-level change
   (old value, new value, who, when, IP, device, reason where applicable) for `customers`,
   `customer_contacts`, `customer_addresses`, and `customer_documents` — see the Audit Logs
   section for the exact envelope.
2. **Append-only children** — `customer_notes` (superseding rather than overwriting) and
   `customer_credit_overrides` are themselves an immutable history of decisions, never edited in
   place.
3. **Document snapshots** — the *effects* of customer master data on financial documents
   (billing address, tax code, payment terms at the moment of invoicing) are snapshotted onto the
   `invoices`/`sales_orders` rows themselves (see Customer Profile → Addresses), so the true
   "what did this customer look like when this invoice was raised" question is answered by the
   invoice's own snapshot columns, not by reconstructing customer state from the audit log — the
   audit log remains the compliance/forensic trail, the snapshot remains the fast, indexed,
   query-friendly answer for reporting.

`customer_balances` is a rebuildable projection: a scheduled and on-demand
`customers:rebuild-balances {--company_id=}` Artisan command recomputes every row from the
authoritative `journal_lines`/`invoices`/`receipts`/`credit_notes` data, so `customer_balances` can
always be thrown away and regenerated — it is never the sole record of any financial fact.

# AI Responsibilities

Every AI agent described below operates through the same Laravel API surface a human user would
call, authenticated as a system-level `ai_agent` principal (see Permissions), obeying that
principal's granted permissions, and writing every output with a `confidence` score (0.0–1.0), a
`reasoning` string or structured explanation, and, where applicable, references to the
`supporting_documents` (specific invoices, payments, or prior customer records) that informed the
output. No agent below is authorized to change `customers.credit_limit`, `lifecycle_state`,
`blacklisted_at`, or delete/merge a customer directly — those remain human (or explicit
policy-engine) actions that may be *informed* by an agent's output but are never *executed* by it.

## Customer Risk Scoring

**Agent:** General Accountant / CFO agent family, specialized as the Risk Scoring function.
**Inputs:** the customer's full transaction history (`invoices`, `receipts`, `credit_notes`),
`customer_balances` overdue buckets, industry/segment, tenure (days since first invoice), payment
behavior trend (accelerating vs. decelerating days-to-pay), external is unavailable in v1 (no
third-party credit bureau integration yet — see Future Improvements), and any AI Fraud Detection
flags on file.
**Output:** `customer_balances.ai_risk_score` (0–100, higher = riskier), recomputed nightly for all
customers and synchronously on `invoice.posted`, `payment.received`, and `invoice.overdue` domain
events for the specific customer affected. Output includes a reasoning payload, e.g.
`{"score": 72, "drivers": ["3 invoices in 61-90 bucket","DSO increased 40% QoQ","1 broken promise-to-pay in last 90 days"]}`.
**Autonomy:** **suggest-only**. The score is displayed prominently on the customer profile and
feeds the nightly Blocking Rules evaluation job as an *input signal* (a high score raises priority
for human review; it does not, by itself, block anything — only the deterministic overdue-bucket
and credit-limit rules in Credit Management can block transactions automatically). A Finance
Manager may manually adjust `credit_rating` in response to a high AI risk score, and that manual
action is what actually changes enforcement behavior.
**Confidence handling:** confidence below 0.5 (e.g., a brand-new customer with under 3 transactions)
suppresses the score from driving any dashboard alert — it is shown as "insufficient history"
rather than a numeric score, to avoid false precision on thin data.

## Payment Prediction

**Agent:** Treasury Manager / Forecast Agent, specialized here as Payment Prediction.
**Inputs:** the customer's historical days-to-pay distribution per invoice, current open invoices
and their due dates, payment-terms structure, any active `promise_to_pay` in
`customer_collection_cases`, and seasonality (e.g., government customers' typical fiscal-year-end
payment surges).
**Output:** `customer_balances.ai_predicted_next_payment_date` and
`ai_predicted_payment_confidence`, recomputed weekly and on every `invoice.posted` event. Also feeds
a company-level "expected cash-in next 30/60/90 days" figure consumed by the Reports and Treasury
modules.
**Autonomy:** **suggest-only**. Never triggers automated communications by itself; a Collections
Officer or the Approval Assistant surfaces the prediction alongside a *draft* dunning message that
a human sends (see Notifications).
**Confidence handling:** predictions with confidence below 0.4 are hidden from the customer-level
UI (again, "insufficient history" state) but still contribute anonymized to the company-level cash
forecast with a wider uncertainty band.

## Fraud Detection

**Agent:** Fraud Detection agent.
**Inputs:** identity-document consistency (does the uploaded commercial registration name match
`legal_name` exactly), velocity anomalies (a new customer immediately requesting a large credit
limit or placing an unusually large first order relative to segment norms), duplicate-payment-
instrument reuse across multiple, seemingly-unrelated customer records, address/IP mismatch signals
captured from the web/app session that created the customer, and known sanctions/watchlist name
matching (fuzzy match against a company-configurable denylist).
**Output:** a `fraud_flag` record (not a column on `customers` directly, but a row in a shared
`ai_flags` table with `flaggable_type='customer'`) with `flag_type`, `confidence`, `reasoning`, and
`evidence` (references to the specific documents/transactions that triggered it).
**Autonomy:** **requires-approval**. A fraud flag never blacklists or blocks a customer
automatically; it raises a high-priority `notifications` entry to the Finance Manager and Auditor
roles, who review the evidence and, if they agree, execute the Blacklist workflow themselves (see
Customer Lifecycle → Blacklisted). This is a hard rule: sanctions-list matching in particular
carries real legal/reputational consequences if wrong, and QAYD will never let an AI agent
unilaterally sever a business relationship.
**Confidence handling:** flags below 0.6 confidence are logged but not surfaced as an active alert
(available only via an explicit "show low-confidence flags" audit view); flags at or above 0.85 are
escalated with a stronger notification tier (see Notifications) and additionally copy the Owner
role, not just Finance Manager.

## Duplicate Detection

**Agent:** General Accountant agent, specialized as Duplicate Detection, invoked synchronously on
every customer create/update and as a nightly batch sweep.
**Inputs:** normalized `legal_name` (case/diacritic/whitespace-insensitive, transliteration-aware
for Arabic/English name pairs), `tax_registration_number`/`commercial_registration_number`/
`national_id` (exact match, which is also DB-enforced — see Business Rules), phone/email overlap
across `customer_contacts`, and address overlap.
**Output:** a `duplicate_candidate` suggestion with a `confidence` score and the candidate
`customer_id` it believes is a duplicate of. Exact identifier matches (confidence 1.0) are blocked
synchronously at creation time by the database constraint itself (`409 Conflict`, not an AI
suggestion at all — that is deterministic, not AI). Fuzzy name-only matches (confidence 0.6–0.99)
surface as a **non-blocking warning** at creation time ("this looks similar to CUST-000412 — Al
Salam Trading Co. W.L.L. — continue anyway?") and as a queued item in a **Merge Candidates** review
queue for Admin/Finance Manager to act on later.
**Autonomy:** **suggest-only** for fuzzy matches; the exact-match block is a deterministic Business
Rule, not an AI decision, and is not gated by confidence.
**Confidence handling:** the Merge Candidates queue is sorted by confidence descending; candidates
below 0.5 are not queued at all to keep the review queue actionable rather than noisy.

## Smart Search

**Agent:** Document AI / Reporting Agent, specialized as Smart Search, powering the customer search
box across the frontend.
**Inputs:** free-text query (name fragments, phone/email fragments, tag text, custom-field values,
even natural-language queries like "customers over their credit limit in Hawalli").
**Output:** a ranked list of matching `customers` rows with a per-result relevance score, combining
full-text search (Postgres `tsvector` over `legal_name`, `display_name`, `name_ar`, `name_en`, and
`customer_code`) with trigram similarity for typo tolerance, tag/custom-field containment matches,
and, for natural-language structured queries, a translation of the query into the same filter
parameters the REST Search endpoint accepts (see API → Search), so "over their credit limit in
Hawalli" becomes `filter[credit_status]=over_limit&filter[city]=Hawalli` under the hood, never a
raw, unvalidated SQL string.
**Autonomy:** **auto** — search is read-only by definition, so there is no approval gate; the
"action" is retrieval, not mutation, and normal RBAC (see Permissions) still scopes what a given
user's search can return.
**Confidence handling:** relevance scores are used for ranking/highlighting only; there is no
concept of a "wrong" search result requiring a confidence gate, though ambiguous natural-language
queries that cannot be confidently translated into filters fall back to a plain full-text search
with a note ("showing text matches for 'X' — could not fully parse your filter request").

## Recommendations

**Agent:** Reporting Agent / CFO agent, specialized as Recommendations, generating next-best-action
suggestions surfaced on the customer profile and on a company-wide "AI Suggestions" inbox.
**Inputs:** all of the above (risk score, payment prediction, behavior analysis, revenue trend),
plus company policy thresholds.
**Output:** discrete, actionable suggestions such as "raise credit limit for CUST-000482 from KWD
5,000 to KWD 8,000 — 18 consecutive on-time payments, DSO improved 22%" or "flag CUST-000559 for
Collections escalation — 2 broken promises in 60 days" or "CUST-000317 has not ordered in 75 days
and is trending toward Inactive — consider a retention outreach." Each suggestion carries a
recommended action, the confidence, the reasoning, and a single-click "create approval task" affordance
that routes into the relevant human workflow (credit-limit change approval, collection case
escalation, etc.) — the AI never performs the action itself.
**Autonomy:** **suggest-only**, always.
**Confidence handling:** suggestions below 0.5 confidence are not surfaced in the inbox; the
threshold is company-configurable for teams that want a noisier/quieter inbox.

## Behavior Analysis

**Agent:** CFO / Reporting Agent, specialized as Behavior Analysis.
**Inputs:** order frequency and size trend, seasonality, product-mix trend, payment-timing trend,
support/communication sentiment where integrated (future), and subscription renewal/cancellation
signals.
**Output:** a `behavior_profile` summary (not stored as a rigid schema but as a versioned JSONB
snapshot referenced from `customer_balances` metadata) classifying the customer into behavioral
archetypes (e.g., `growing`, `stable`, `at_risk_of_churn`, `declining_engagement`) with the
supporting metrics, refreshed monthly and on major events (subscription cancellation, 90+ day
silence).
**Autonomy:** **suggest-only**; feeds Recommendations and Revenue Forecasting rather than acting
independently.
**Confidence handling:** archetypes require a minimum of 6 months of transaction history to be
assigned; customers below that threshold are labeled `insufficient_history` rather than forced into
a potentially misleading archetype.

## Revenue Forecasting

**Agent:** CFO / Forecast Agent, specialized as Revenue Forecasting, operating at both the
per-customer and portfolio level.
**Inputs:** open sales orders, active subscriptions and their renewal dates/probabilities (informed
by Behavior Analysis' churn signal), historical revenue-by-customer trend, and pipeline data from
Sales' `sales_quotations` (weighted by a win-probability the Sales module itself tracks).
**Output:** a per-customer expected-revenue-next-period figure and a portfolio roll-up, exposed via
the Reports module's Revenue by Customer report (see Reports) with a "forecast" column alongside
"actual," each carrying confidence bands (e.g., "KWD 12,400 ± KWD 1,800, 80% confidence interval").
**Autonomy:** **suggest-only** — forecasts inform planning (budgets, staffing, cash-flow planning
in Treasury) and are never used to auto-adjust a customer's credit limit or terms; any such
adjustment still routes through the standard Credit Management approval path.
**Confidence handling:** the forecast always reports its confidence interval rather than a bare
point estimate, specifically so Finance never mistakes an AI point forecast for a guaranteed number
— this is treated as a hard product requirement, not a nice-to-have.

## AI Agent Autonomy Summary

| Agent Function | Reads | Writes (via API, human-equivalent permission) | Autonomy | Min. Confidence to Surface |
|---|---|---|---|---|
| Risk Scoring | Full transaction history | `customer_balances.ai_risk_score` | Suggest-only | 0.5 |
| Payment Prediction | Invoices, terms, collection cases | `customer_balances.ai_predicted_*` | Suggest-only | 0.4 |
| Fraud Detection | Identity docs, velocity, sanctions lists | `ai_flags` row | Requires-approval | 0.6 |
| Duplicate Detection | Name/identifier/contact overlap | `duplicate_candidate` suggestion | Suggest-only | 0.5 |
| Smart Search | Full-text + structured filters | (read-only) | Auto | N/A |
| Recommendations | All other agents' outputs | Suggestion + approval-task link | Suggest-only | 0.5 |
| Behavior Analysis | Order/payment/subscription trend | `behavior_profile` snapshot | Suggest-only | N/A (min. history gate) |
| Revenue Forecasting | Orders, subscriptions, pipeline | Forecast figures (Reports) | Suggest-only | N/A (always shows interval) |

# Workflow

## Customer Creation

Two entry points create a `customers` row: (a) direct creation by a Sales/Admin user via
`POST /api/v1/accounting/customers`, and (b) lead conversion via
`POST /api/v1/sales/leads/{id}/convert` (see Customer Lifecycle → Lead). Both paths converge on the
same `CustomerService::create()` method, guaranteeing identical validation regardless of entry
point. Step-by-step for direct creation:

1. User submits the customer form (or the AI drafts one from an uploaded business card/document via
   the Document AI/OCR agent, which the user then reviews and submits — the AI never submits on the
   user's behalf).
2. `StoreCustomerRequest` validates: required fields present, type-conditional identifier present,
   `preferred_currency` is a valid ISO 4217 code, `payment_terms_id`/`price_list_id` (if provided)
   belong to the same `company_id`, at least one contact and one address are provided.
3. `CustomerService::create()` runs the exact-match Duplicate Detection check (DB constraint) and
   the fuzzy Duplicate Detection AI check; a fuzzy match surfaces a non-blocking warning (see AI
   Responsibilities → Duplicate Detection) that the user can dismiss and proceed past.
4. A DB transaction opens: `customers` row inserted, `customer_code` generated from the
   per-company sequence, initial `customer_contacts` and `customer_addresses` rows inserted, an
   empty `customer_balances` row inserted (`balance_net_base = 0`), and an `audit_logs` entry
   written for the creation event. Transaction commits.
5. If `credit_limit` was submitted above the auto-approval threshold (see Approval below), the
   customer is created with `credit_limit = 0` and a pending `credit_limit_change_requests` entry
   is created instead — the requested limit takes effect only on approval, never provisionally.
6. `customer.created` domain event fires, consumed by: AI Risk Scoring (initializes a baseline
   score once transaction history exists), Smart Search (indexes the new row), and Notifications
   (informs the Sales Owner's manager for new accounts above a configurable size).

## Approval

Approval gates exist at three points in the customer lifecycle, each modeled as a row in a shared
`approval_requests` table (`approvable_type='customer'`, `approvable_id`, `action_type`,
`requested_changes JSONB`, `status`, `requested_by`, `approved_by`/`rejected_by`,
`decision_notes`):

1. **Credit-limit increase above threshold** (see Business Rules #15) — requires Finance Manager
   approval. The requested new limit is stored in `requested_changes` and only applied to
   `customers.credit_limit` on approval; rejection leaves the existing limit untouched and notifies
   the requester with the rejection reason.
2. **Blacklist / Reinstate** — requires Finance Manager (blacklist) or dual Finance-Manager-plus-
   Owner approval (reinstate), per Customer Lifecycle → Blacklisted.
3. **Merge** — requires Admin approval (see Merge below); Duplicate Detection-sourced merge
   suggestions always land in this approval queue rather than executing automatically, regardless
   of AI confidence.

Approval requests appear in a role-scoped "Pending Approvals" queue (also reachable via
`GET /api/v1/accounting/customers/approval-requests`) and generate a `notifications` entry to every
user holding the required permission. An approval request that sits unresolved for more than a
company-configured SLA (default 3 business days) escalates to the next role up (Finance Manager →
CFO/Owner) via a second notification tier, but never auto-approves — there is no "approve by
default on timeout" path anywhere in Customer Management, by design, because every gated action
here has real financial or reputational consequence.

## Editing

Routine field edits (contact details, addresses, tags, notes, custom fields, non-threshold credit
rating changes) go through `PUT/PATCH /api/v1/accounting/customers/{id}` with standard permission
checks (`accounting.customer.update`) and no approval gate — they are logged to `audit_logs` like
every mutation but take effect immediately. Edits to fields that feed already-posted documents
(legal name, tax registration number, default billing address) do not retroactively alter posted
invoices (see Database Design → History, the snapshot pattern) but DO prompt a UI confirmation
("this will not change the 14 invoices already issued under the old name — new documents will use
the updated name") so users understand the non-retroactive behavior rather than assuming it.

## Merge

Merge consolidates two `customers` rows believed to represent the same real-world entity into one
surviving row, redirecting all history.

1. Triggered either by a user selecting two customers and choosing "Merge," or by accepting an AI
   Duplicate Detection suggestion from the Merge Candidates queue.
2. The user (or the merge screen, pre-filled with AI-suggested field choices and each choice's
   confidence) selects, field by field, which of the two records' values survive on the merged
   record where they differ (name spelling, preferred contact, address currency of the truth).
3. `POST /api/v1/accounting/customers/{survivor_id}/merge` with `{"merged_id": ..., "field_choices": {...}}`
   creates an `approval_requests` row (Admin-gated) rather than executing immediately.
4. On Admin approval, `CustomerMergeService::execute()` runs inside a single DB transaction:
   - All child rows (`customer_contacts`, `customer_addresses`, `customer_documents`,
     `customer_notes`) belonging to the merged-away customer are re-pointed (`customer_id` updated)
     to the survivor, with a `merged_from_customer_id` audit column added on each moved row so the
     provenance is never lost.
   - Every foreign-key reference from other modules (`sales_quotations.customer_id`,
     `sales_orders.customer_id`, `invoices.customer_id`, `receipts.customer_id`,
     `credit_notes.customer_id`, `subscriptions.customer_id`, `contracts.party_id`,
     `projects.customer_id`) is updated to point at the survivor. This is the one case in the
     platform where a bulk cross-module UPDATE across financial documents is permitted, precisely
     because it is re-pointing a foreign key to a corrected identity, not altering any amount, date,
     or posted status of the documents themselves.
   - The merged-away row is **not deleted**. It is retained with `deleted_at` set AND
     `merged_into_customer_id` set to the survivor's id, so any stale external reference (a bookmark,
     an old report export, an external integration) that still cites the old `customer_id` resolves
     (via `GET /api/v1/accounting/customers/{id}`) with a `301`-equivalent redirect payload pointing
     to the survivor rather than a bare `404`.
   - `customer_balances` for the survivor is recalculated from scratch (never simply summed from
     the two prior rows, to avoid double-counting any unapplied credits/receipts that referenced
     both).
   - An `audit_logs` entry captures the complete merge operation (both customer IDs, every
     field-choice decision, every re-pointed document ID) as a single, reviewable event.
5. **Reversal window:** because a merge is destructive to the merged-away row's independent
   existence, QAYD retains enough information in the audit log (the full set of re-pointed
   document IDs and their prior `customer_id` values) to fully reverse a merge within 30 days via a
   dedicated `POST /api/v1/accounting/customers/{id}/unmerge` Admin-only action; after 30 days the
   reversal window closes (configurable) and any correction must be a fresh manual data-entry
   correction rather than an automated unmerge, to bound the complexity/risk of unwinding a merge
   against which new documents may since have been created.

## Archive

1. Eligibility: `lifecycle_state = 'inactive'` (or `'customer'`, for a direct archive of a genuinely
   closed-out relationship) AND `customer_balances.balance_net_base = 0` AND no open
   `sales_orders`/undelivered `deliveries`/open `contracts`/active `subscriptions`.
2. `POST /api/v1/accounting/customers/{id}/archive` checks eligibility server-side (never trusts a
   client-computed eligibility flag) and requires `accounting.customer.archive` permission
   (Finance Manager and above, or Admin).
3. Sets `lifecycle_state = 'archived'`, `archived_at`, `archived_by`. No child rows are touched;
   everything remains queryable.
4. A monthly scheduled report (`customers.archive-candidates`) surfaces Inactive customers meeting
   the eligibility criteria to Finance as a batch-review list rather than auto-archiving, so a human
   always makes the final call.

## Delete

True, destructive delete is available **only** when a customer has zero financial history of any
kind: no `invoices`, no `receipts`, no `credit_notes`, no `sales_orders`, no `journal_lines`
referencing it directly or indirectly, and `customer_balances.balance_net_base = 0` with
`lifetime_invoice_count = 0`. This is intentionally a narrow window — realistically, only customers
created in error and never transacted against.

1. `DELETE /api/v1/accounting/customers/{id}` requires `accounting.customer.delete`, restricted by
   default to Admin/Owner only.
2. Server re-validates zero-history eligibility (never trusts client state) and returns
   `409 Conflict` with error code `CUSTOMER_HAS_FINANCIAL_HISTORY` if not eligible — directing the
   caller to Archive instead.
3. If eligible, the row is soft-deleted (`deleted_at` set) — QAYD's delete is *always* a soft
   delete at the storage layer, consistent with the platform-wide never-hard-delete convention, but
   is functionally equivalent to a real delete from the user's perspective: the record disappears
   from all views (including history views) and its `customer_code` is never reused. A genuine
   `DELETE FROM customers` SQL statement is never issued by the application; only a scheduled,
   separately-authorized data-retention purge job (outside this module's normal operation, requiring
   explicit legal/compliance sign-off) can physically remove rows, and only for records past a
   jurisdiction-mandated retention period with confirmed zero financial relevance.

## Recovery

A soft-deleted customer (via Delete above) can be recovered by an Admin within a
company-configured recovery window (default 90 days) via
`POST /api/v1/accounting/customers/{id}/restore`, which clears `deleted_at` and restores
`lifecycle_state` to whatever it was immediately before deletion (captured in the audit log entry
for the delete event). After the recovery window, the row remains in the database (soft-deleted
rows are never purged automatically) but is no longer restorable through the normal API — restoring
it at that point requires a direct, logged database intervention by an engineer, treated as an
exceptional support action, not a normal workflow.

An Archived customer is "recovered" simply by reactivation (`lifecycle_state` back to `'customer'`),
which is not a Delete/Recovery pair at all but the ordinary Archive-reversal path already described
under Customer Lifecycle → Archived.

# Reports

All reports below are backed by `report_definitions`/`report_runs` (the shared Reports module) with
a Customer-Management-owned SQL/query definition, are exportable to PDF/XLSX/CSV, are
company-scoped and RBAC-filtered (a Sales Employee sees only their own accounts unless granted
broader visibility), and support the platform's standard filter/sort/pagination envelope. Each
report additionally supports scheduling (`report_schedules`) for automatic recurring delivery
(e.g., "Aging Report every Monday 8am to Finance Manager's email").

## Customer List

The base directory view: `customer_code`, `legal_name`, `customer_type`, `lifecycle_state`,
`credit_rating`, `ai_risk_score`, `balance_net`, `sales_owner`, `segment`, filterable by every
column above plus tags and custom fields, sortable, with saved-view support per user.

## Aging Report

Buckets every open invoice by days overdue (Current, 1–30, 31–60, 61–90, 90+, matching the buckets
in Credit Management → Overdue Rules) per customer, with a grand total row and per-bucket
subtotals. Supports "as of date" (historical aging, not just today) by recomputing bucket
membership against `journal_lines`/`invoices` posted as-of the requested date rather than relying
solely on the live `customer_balances` projection, which only reflects the current moment.

```
Customer          Current    1-30      31-60     61-90     90+       Total
CUST-000482        1,200.00    450.00      0.00      0.00      0.00   1,650.00
CUST-000559            0.00      0.00    980.00    620.00  3,400.00   5,000.00
------------------------------------------------------------------------------
Total               1,200.00    450.00    980.00    620.00  3,400.00   6,650.00
```

## Outstanding Balances

A simpler, single-figure-per-customer view (`balance_net_base`, `balance_open_invoices_base`,
`balance_unapplied_credits_base`, `balance_credit_notes_available_base`) intended for a quick
"who owes us what, right now" scan, distinct from Aging in that it does not break the figure down
by age — it is the live `customer_balances` row set, essentially exposed directly (with RBAC and
formatting), making it the platform's fastest customer financial report (no as-of-date
recomputation needed).

## Revenue by Customer

Revenue recognized (from posted `invoice_items`, net of `credit_notes`) per customer per period,
with period-over-period comparison and an optional AI Revenue Forecasting overlay column (see AI
Responsibilities → Revenue Forecasting) showing the forecast for the current/next period alongside
actuals for prior periods.

## Top Customers

Revenue by Customer, ranked, limited (top 10/25/50/100, configurable), for a chosen period,
optionally weighted by margin rather than raw revenue where `products.cost_price` data is
available, letting Finance distinguish "biggest customer by volume" from "most profitable
customer."

## Inactive Customers

Every customer in `lifecycle_state = 'inactive'`, with days since last activity, last invoice date,
last payment date, and remaining `balance_net_base` (an inactive customer can still owe money — see
Customer Lifecycle → Inactive), sortable by staleness, intended as the direct feed for a
win-back/retention motion (paired with the AI Behavior Analysis `at_risk_of_churn`/
`declining_engagement` archetypes).

## Credit Exposure

Every non-archived, non-blacklisted customer's `credit_limit`, current `balance_net_base`
(exposure), exposure as a percentage of limit, and `ai_risk_score`, sorted by exposure percentage
descending, giving Finance a single view of "who is closest to or over their limit, and how risky
are they." Includes a portfolio total exposure figure at the top, which is the number that should
tie to the AR control account balance in the GL (a discrepancy here is the fastest way to catch a
`customer_balances` drift — see Performance → reconciliation job).

## Collection Status

Every open `customer_collection_cases` row, its state, assigned Collections Officer, days in
current state, `promised_pay_date` vs. today, and the case's `total_amount_base`, grouped by state
for a Collections team's daily worklist.

## Customer Lifetime Value

`customer_balances.lifetime_revenue_base` and `lifetime_invoice_count`, combined with tenure
(days/months since first invoice) to compute average revenue per period, and an AI-projected
forward LTV (from Revenue Forecasting + Behavior Analysis' churn probability, computed as
`projected_ltv = current_lifetime_revenue + (forecasted_revenue_per_period / churn_probability)`
using the standard simplified LTV formula, clearly labeled as a projection with its confidence
interval, never presented as a certainty). Useful for prioritizing account-management attention and
for CAC/LTV analysis fed to Sales leadership.

## Report Permission Summary

| Report | Minimum Role | Notes |
|---|---|---|
| Customer List | Sales Employee (own accounts) / Sales Manager (all) | Segment/tag filters available to all |
| Aging Report | Accountant | Finance-facing; Sales sees own accounts only |
| Outstanding Balances | Accountant | |
| Revenue by Customer | Sales Manager / Finance Manager | Margin view requires `reports.cost_visibility` |
| Top Customers | Sales Manager | |
| Inactive Customers | Sales Employee (own) / Sales Manager (all) | |
| Credit Exposure | Finance Manager | Sensitive — full portfolio risk view |
| Collection Status | Accountant / Finance Manager | |
| Customer Lifetime Value | Sales Manager / Finance Manager | |

# API

All endpoints are under `/api/v1/accounting/customers`, require a Sanctum/JWT bearer token, require
the `X-Company-Id` header, and return the standard response envelope described in the platform
conventions. Every list endpoint supports `page`, `per_page` (default 25, max 200), `sort`,
`filter[...]`, and `search` query parameters.

## Endpoint Table

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/customers` | `accounting.customer.read` | List/search/filter customers |
| POST | `/customers` | `accounting.customer.create` | Create a customer |
| GET | `/customers/{id}` | `accounting.customer.read` | Get one customer (with children) |
| PUT | `/customers/{id}` | `accounting.customer.update` | Full update |
| PATCH | `/customers/{id}` | `accounting.customer.update` | Partial update |
| DELETE | `/customers/{id}` | `accounting.customer.delete` | Soft delete (zero-history only) |
| POST | `/customers/{id}/restore` | `accounting.customer.delete` | Restore a soft-deleted customer |
| POST | `/customers/{id}/archive` | `accounting.customer.archive` | Archive (zero-balance only) |
| POST | `/customers/{id}/reactivate` | `accounting.customer.archive` | Reactivate archived/inactive |
| POST | `/customers/{id}/blacklist` | `accounting.customer.blacklist` | Blacklist (reason required) |
| POST | `/customers/{id}/reinstate` | `accounting.customer.blacklist` | Reinstate (dual approval) |
| POST | `/customers/{survivor_id}/merge` | `accounting.customer.merge` | Request merge (approval-gated) |
| POST | `/customers/{id}/unmerge` | `accounting.customer.merge` | Reverse a merge (≤30 days) |
| POST | `/customers/import` | `accounting.customer.import` | Bulk import (CSV/XLSX) |
| GET | `/customers/export` | `accounting.customer.export` | Bulk export (CSV/XLSX/PDF) |
| GET | `/customers/search` | `accounting.customer.read` | Smart Search (AI-assisted) |
| POST | `/customers/bulk` | `accounting.customer.update` | Bulk operations (tag, reassign owner, etc.) |
| GET | `/customers/{id}/balance` | `accounting.customer.read` | Live `customer_balances` row |
| GET | `/customers/{id}/timeline` | `accounting.customer.read` | Merged notes/audit/document timeline |
| POST | `/customers/{id}/credit-overrides` | `accounting.customer.credit_override` | Create a credit-limit override |
| GET | `/customers/approval-requests` | `accounting.customer.approve` | Pending approval queue |
| POST | `/customers/approval-requests/{id}/decide` | `accounting.customer.approve` | Approve/reject a pending request |
| GET | `/customers/{id}/contacts` | `accounting.customer.read` | List contacts |
| POST | `/customers/{id}/contacts` | `accounting.customer.update` | Add contact |
| GET | `/customers/{id}/addresses` | `accounting.customer.read` | List addresses |
| POST | `/customers/{id}/addresses` | `accounting.customer.update` | Add address |
| GET | `/customers/{id}/documents` | `accounting.customer.read` | List documents |
| POST | `/customers/{id}/documents` | `accounting.customer.update` | Upload/attach document |

## Create — Request / Response

`POST /api/v1/accounting/customers`

```json
{
  "customer_type": "business",
  "legal_name": "Al Salam Trading Co. W.L.L.",
  "name_ar": "شركة السلام التجارية ذ.م.م",
  "commercial_registration_number": "CR-2019-004821",
  "tax_registration_number": "KW-TRN-771234",
  "preferred_currency": "KWD",
  "preferred_language": "ar",
  "payment_terms_id": 4,
  "price_list_id": 2,
  "credit_limit": 5000.0000,
  "segment": "smb",
  "sales_owner_id": 118,
  "tags": ["referred-by-partner-x"],
  "contacts": [
    {
      "full_name": "Fahad Al-Mutairi",
      "job_title": "Finance Manager",
      "email": "fahad@alsalamtrading.com.kw",
      "mobile": "+96599112233",
      "is_primary": true,
      "is_billing_contact": true
    }
  ],
  "addresses": [
    {
      "address_type": "both",
      "line1": "Building 12, Street 40, Block 3",
      "city": "Hawalli",
      "country_code": "KW",
      "is_default_billing": true,
      "is_default_shipping": true
    }
  ]
}
```

```json
{
  "success": true,
  "data": {
    "id": 4821,
    "customer_code": "CUST-004821",
    "customer_type": "business",
    "legal_name": "Al Salam Trading Co. W.L.L.",
    "lifecycle_state": "customer",
    "status": "active",
    "credit_limit": "5000.0000",
    "credit_rating": "unrated",
    "preferred_currency": "KWD",
    "created_at": "2026-07-16T09:14:02Z"
  },
  "message": "Customer created successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "6e1e3b2a-7c2b-4a6a-9d0e-1e9f6b2a7c2b",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

## Update — Request / Response

`PATCH /api/v1/accounting/customers/4821`

```json
{ "segment": "enterprise", "sales_owner_id": 204, "tags": ["referred-by-partner-x", "key-account"] }
```

```json
{
  "success": true,
  "data": {
    "id": 4821,
    "customer_code": "CUST-004821",
    "segment": "enterprise",
    "sales_owner_id": 204,
    "tags": ["referred-by-partner-x", "key-account"],
    "updated_at": "2026-08-02T11:02:44Z"
  },
  "message": "Customer updated successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9a2d4e51-0b3c-4f8e-8a11-2c9d6e4b7f10",
  "timestamp": "2026-08-02T11:02:44Z"
}
```

## Delete — Error Response Example (blocked by financial history)

`DELETE /api/v1/accounting/customers/4821`

```json
{
  "success": false,
  "data": null,
  "message": "This customer cannot be deleted because it has financial history.",
  "errors": [
    {
      "code": "CUSTOMER_HAS_FINANCIAL_HISTORY",
      "detail": "Customer CUST-004821 has 14 posted invoices and a net balance of 1,650.0000 KWD. Use archive instead.",
      "field": null
    }
  ],
  "meta": { "pagination": null },
  "request_id": "12ab34cd-56ef-78gh-90ij-klmnopqrstuv",
  "timestamp": "2026-08-02T11:05:10Z"
}
```

## Merge — Request / Response

`POST /api/v1/accounting/customers/4821/merge`

```json
{
  "merged_id": 5190,
  "field_choices": {
    "legal_name": "survivor",
    "preferred_language": "merged",
    "sales_owner_id": "survivor"
  },
  "reason": "Duplicate created by walk-in POS entry; confirmed same CR number as CUST-004821."
}
```

```json
{
  "success": true,
  "data": {
    "approval_request_id": 9931,
    "status": "pending",
    "survivor_id": 4821,
    "merged_id": 5190
  },
  "message": "Merge request submitted for approval.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "timestamp": "2026-08-02T11:10:00Z"
}
```

## Import — Request / Response

`POST /api/v1/accounting/customers/import` (multipart/form-data: `file`, `mapping`)

```json
{
  "success": true,
  "data": {
    "import_job_id": 771,
    "status": "processing",
    "total_rows": 1240,
    "estimated_completion_seconds": 90
  },
  "message": "Import started. You will be notified when it completes.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "c0ffee00-dead-beef-cafe-1234567890ab",
  "timestamp": "2026-08-02T11:12:00Z"
}
```

Import processes asynchronously (queued job), row-validates each record with the same
`StoreCustomerRequest` rules used for a single create, runs Duplicate Detection per row, and
produces a downloadable results file (`import_job_id` → `GET /customers/import/{job_id}`) listing
per-row success/failure/duplicate-warning with the specific validation error for failures — no row
is silently dropped or silently overwritten.

## Search — Request / Response

`GET /api/v1/accounting/customers/search?q=over+their+credit+limit+in+hawalli`

```json
{
  "success": true,
  "data": {
    "interpreted_filters": { "credit_status": "over_limit", "city": "Hawalli" },
    "results": [
      {
        "id": 5190,
        "customer_code": "CUST-005190",
        "legal_name": "Gulf Fresh Foods Co.",
        "balance_net_base": "8420.0000",
        "credit_limit": "7500.0000",
        "relevance_score": 0.94
      }
    ]
  },
  "message": "1 customer found matching your query.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "5f5f5f5f-6e6e-7d7d-8c8c-9b9b0a0a1a1a",
  "timestamp": "2026-08-02T11:15:00Z"
}
```

## Bulk Operations — Request / Response

`POST /api/v1/accounting/customers/bulk`

```json
{
  "customer_ids": [4821, 5190, 6002],
  "action": "reassign_owner",
  "params": { "sales_owner_id": 204 }
}
```

```json
{
  "success": true,
  "data": { "updated": 3, "failed": 0, "details": [] },
  "message": "3 customers updated successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "bulk0001-2222-3333-4444-555566667777",
  "timestamp": "2026-08-02T11:20:00Z"
}
```

Supported `action` values for bulk operations: `reassign_owner`, `add_tags`, `remove_tags`,
`change_price_list`, `change_segment`, `archive` (each row still individually eligibility-checked;
ineligible rows land in `details[]` with a per-row reason rather than failing the whole batch).

## Validation Error Example (422)

```json
{
  "success": false,
  "data": null,
  "message": "The given data was invalid.",
  "errors": [
    { "code": "REQUIRED_IDENTIFIER_MISSING", "detail": "commercial_registration_number is required for customer_type=business.", "field": "commercial_registration_number" },
    { "code": "INVALID_CURRENCY_CODE", "detail": "preferred_currency must be a valid ISO 4217 code.", "field": "preferred_currency" }
  ],
  "meta": { "pagination": null },
  "request_id": "err00000-1111-2222-3333-444455556666",
  "timestamp": "2026-08-02T11:22:00Z"
}
```

# Permissions

Permission keys follow the platform convention `<area>.<entity>.<action>`. Customer Management
defines the following keys, all default-deny:

| Permission Key | Description |
|---|---|
| `accounting.customer.read` | View customer list, profile, balance, timeline |
| `accounting.customer.create` | Create new customers |
| `accounting.customer.update` | Edit customer fields, contacts, addresses, documents, notes |
| `accounting.customer.delete` | Soft-delete a zero-history customer; restore a deleted customer |
| `accounting.customer.archive` | Archive / reactivate a customer |
| `accounting.customer.blacklist` | Blacklist / reinstate a customer |
| `accounting.customer.merge` | Request a merge or unmerge |
| `accounting.customer.approve` | Decide pending approval requests (credit, blacklist, merge) |
| `accounting.customer.credit_override` | Approve a one-off credit-limit override on a document |
| `accounting.customer.credit_limit.set` | Set/raise a customer's credit limit directly (below auto-approval threshold) |
| `accounting.customer.import` | Bulk import customers |
| `accounting.customer.export` | Bulk export customers (subject to Security → data-export controls) |
| `reports.customer.aging` | View Aging Report |
| `reports.customer.credit_exposure` | View Credit Exposure Report (sensitive) |
| `ai.customer.override` | Dismiss/accept AI suggestions (Duplicate Detection, Recommendations) |

## Role Table

| Permission | Owner | Admin | Sales Manager | Sales Employee | Finance Manager | Accountant | Customer Service | Auditor | AI Agent |
|---|---|---|---|---|---|---|---|---|---|
| `accounting.customer.read` | ✅ | ✅ | ✅ | ✅ (own accounts) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `accounting.customer.create` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ (drafts only, via approval) |
| `accounting.customer.update` | ✅ | ✅ | ✅ | ✅ (own accounts) | ✅ | ✅ (financial fields) | ✅ (contact/address only) | ❌ | ❌ (suggestions only) |
| `accounting.customer.delete` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `accounting.customer.archive` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.customer.blacklist` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.customer.merge` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ (suggests only) |
| `accounting.customer.approve` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.customer.credit_override` | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.customer.credit_limit.set` | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.customer.import` | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `accounting.customer.export` | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ✅ | ❌ |
| `reports.customer.aging` | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ❌ | ✅ | ✅ (read) |
| `reports.customer.credit_exposure` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ✅ (read) |
| `ai.customer.override` | ✅ | ✅ | ✅ | ✅ (own accounts) | ✅ | ✅ | ❌ | ❌ | ❌ |

The **AI Agent** row is the system principal every AI agent described in AI Responsibilities
authenticates as. It is granted `read` broadly and narrow, specific `write`-adjacent permissions
only where the agent produces a suggestion object (`duplicate_candidate`, `ai_flags`,
`approval_requests` drafts) rather than a direct mutation of `customers`/`customer_balances`
protected fields — the AI Agent principal is never granted `accounting.customer.delete`,
`.archive`, `.blacklist`, `.merge`, `.approve`, `.credit_override`, or `.credit_limit.set` under
any circumstance, enforced by seeding these as permanently excluded from the `ai_agent` role
definition rather than merely "not currently granted" (a defense against a future misconfiguration
accidentally widening AI autonomy on sensitive financial actions).

# Notifications

Customer Management raises `notifications` (foundation table, delivered via in-app, email, and
Reverb-pushed real-time toast depending on user preference) for the following events:

| Event | Recipients | Channel Default | Urgency |
|---|---|---|---|
| `customer.created` (above configurable size) | Sales Owner's manager | In-app | Low |
| `customer.credit_limit.approval_requested` | Finance Manager(s) | In-app + email | Medium |
| `customer.credit_limit.approval_decided` | Requester | In-app | Medium |
| `customer.credit_hold.triggered` | Sales Owner, Finance Manager | In-app + email | Medium |
| `customer.new_order_blocked` | Sales Owner, Sales Manager | In-app + email | High |
| `customer.blacklist.requested` | Finance Manager(s), Owner | In-app + email | High |
| `customer.blacklist.applied` | Sales Owner, all users with open docs for that customer | In-app | High |
| `customer.fraud_flag.raised` (confidence ≥ 0.6) | Finance Manager(s), Auditor | In-app + email | High |
| `customer.fraud_flag.raised` (confidence ≥ 0.85) | + Owner | In-app + email + SMS (if configured) | Critical |
| `customer.duplicate_candidate.found` | Requester (at create time); batch sweep → Admin | In-app | Low |
| `customer.merge.approval_requested` | Admin(s) | In-app + email | Medium |
| `customer.document.expiring` (30/7/0 days out) | Sales Owner, Finance | In-app + email | Medium |
| `customer.tax_exemption.expired` | Finance Manager(s) | In-app + email | Medium |
| `customer.inactive.transitioned` | Sales Owner | In-app | Low |
| `customer.archive_candidate.available` (monthly digest) | Finance Manager(s) | Email digest | Low |
| `customer.collection_case.opened` | Assigned Collections Officer | In-app + email | Medium |
| `customer.collection_case.broken_promise` | Assigned Collections Officer, Finance Manager | In-app + email | High |
| `customer.import.completed` | Import requester | In-app + email | Low |
| `customer.recommendation.available` | Relevant Sales Owner / Finance Manager | In-app | Low |

Every notification payload includes the `customer_id`, `customer_code`, and `legal_name` so a
recipient can act without an additional lookup, plus a deep link to the relevant screen (customer
profile, approval queue, collection case). Urgency tiers map to delivery channel escalation — Low
stays in-app only; Medium adds email; High adds email with a shorter digest-batching window (near-
immediate rather than hourly-batched); Critical (currently only the highest-confidence fraud flag)
additionally uses SMS where the recipient has a verified mobile number and SMS notifications
enabled, because a suspected-fraud event is one of the very few cases in this module where minutes,
not hours, matter.

# Security

- **Tenant isolation.** Every query in `CustomerRepository` is scoped by `company_id` derived from
  the authenticated request's `X-Company-Id` header cross-checked against the user's
  `company_users` membership — never from a client-supplied `company_id` in the request body. A
  request whose header company does not match a row the resolved token has access to returns
  `403 Forbidden`, not a filtered/empty result set (an empty result would leak the fact that the ID
  space is shared; a `403` does not).
- **PII minimization in AI prompts.** When an AI agent (e.g., Duplicate Detection, Smart Search)
  constructs a prompt to the FastAPI AI layer, it passes only the fields relevant to that specific
  function (e.g., Duplicate Detection needs `legal_name`/identifiers/contact info; it does not need
  `credit_limit` or `custom_fields`), following the principle that AI context should be the minimum
  necessary, not "everything about the customer," reducing both leakage surface and prompt-injection
  blast radius from any untrusted text fields (notes, custom fields) that happen to be included.
- **Document access via signed URLs only.** `customer_documents.attachment_id` and generic
  `attachments` are served exclusively through short-lived (default 15-minute) signed R2 URLs
  generated per-request by the Laravel backend after a permission check; there is no public bucket,
  no permanent public URL, and no client-side ability to enumerate or guess another customer's
  document URL.
- **Field-level sensitivity.** `national_id`, `tax_registration_number`, and
  `commercial_registration_number` are treated as sensitive PII/identifiers: they are excluded from
  default list-view API responses (only returned on the single-record `GET /customers/{id}` detail
  call to a caller holding `accounting.customer.read`), excluded from Smart Search's returned
  payload (searchable but not echoed back in result snippets), and redacted (`***last4`) in any
  export unless the exporting user explicitly holds a `accounting.customer.export.unredacted`
  permission — a narrower permission than plain `export`, granted sparingly.
- **Export throttling and watermarking.** Bulk export (`GET /customers/export`) is rate-limited per
  user (default 5 exports per hour) and every exported file embeds a footer with the exporting
  user's identity and timestamp, so a leaked export file is traceable to its source.
- **Blacklist/fraud data is Auditor-visible but never customer-visible.** No blacklist reason,
  fraud flag, or credit-hold detail is ever exposed through any customer-facing surface (there is no
  customer portal in v1, but the rule is codified now so it is not accidentally violated when one
  ships).
- **Injection defense.** All Smart Search natural-language-to-filter translation happens through a
  constrained, allow-listed filter grammar (see AI Responsibilities → Smart Search) — the AI layer
  never constructs or executes raw SQL, and the Laravel API never interpolates AI-produced strings
  directly into a query; every AI-derived filter is validated against the exact same
  `FormRequest`/enum rules a human-typed filter would be.
- **Approval actions require re-authentication context.** Sensitive approval decisions
  (credit-limit approval, blacklist, merge, reinstate) require the request to carry a fresh
  (< 15 minutes old) authentication context; a stale session token that has not re-verified
  recently is rejected with `401` and a step-up-auth prompt, consistent with the platform-wide rule
  that sensitive operations always require a human approval chain and, here, a *freshly
  authenticated* human.

# Audit Logs

Every mutation to `customers`, `customer_contacts`, `customer_addresses`, `customer_documents`,
`customer_notes`, `customer_credit_overrides`, and lifecycle transitions writes an `audit_logs` row
with the platform-standard envelope:

```json
{
  "id": 88231,
  "company_id": 12,
  "actor_type": "user",
  "actor_id": 204,
  "actor_role": "finance_manager",
  "action": "customer.credit_limit.changed",
  "auditable_type": "customer",
  "auditable_id": 4821,
  "old_values": { "credit_limit": "5000.0000" },
  "new_values": { "credit_limit": "8000.0000" },
  "reason": "18 consecutive on-time payments; AI recommendation accepted (confidence 0.81).",
  "ip_address": "83.98.xxx.xxx",
  "device": "Chrome 128 / macOS",
  "request_id": "9a2d4e51-0b3c-4f8e-8a11-2c9d6e4b7f10",
  "created_at": "2026-08-02T11:02:44Z"
}
```

Distinct `action` values tracked include (non-exhaustive): `customer.created`, `customer.updated`,
`customer.contact.added`, `customer.contact.removed`, `customer.address.added`,
`customer.address.updated`, `customer.document.uploaded`, `customer.document.verified`,
`customer.credit_limit.requested`, `customer.credit_limit.changed`,
`customer.credit_limit.rejected`, `customer.credit_override.granted`, `customer.blacklisted`,
`customer.reinstated`, `customer.archived`, `customer.reactivated`, `customer.deleted`,
`customer.restored`, `customer.merged` (with the full field-choice + re-pointed-document-id payload
described in Workflow → Merge), `customer.unmerged`, `customer.tag.added`, `customer.tag.removed`.
AI-actor events (e.g., a risk score recompute, a duplicate suggestion raised) are also written to
`audit_logs` with `actor_type = 'ai_agent'` and the agent's `confidence`/`reasoning` embedded in
`new_values`, so the audit trail captures AI decisions with the same rigor as human ones, even
though AI actions here are limited to suggestions and score updates rather than approvals.

Audit logs are immutable (no `UPDATE`/`DELETE` grants on the table for the application's database
role — only `INSERT`) and retained for a minimum of 7 years by default (configurable upward per
jurisdiction, e.g., some GCC tax authorities require longer retention for AR-related records),
consistent with financial-record retention expectations even though `audit_logs` itself is not a
financial-amount table.

# Performance

- **`customer_balances` is a synchronously maintained projection, not a nightly batch.** As stated
  in Business Rules #11, every balance-affecting posting recalculates the affected customer's
  `customer_balances` row within the same DB transaction as the posting itself, using a targeted,
  single-customer recalculation function (`recalculate_customer_balance(customer_id)`), not a
  full-table rebuild — this keeps the hot path (posting an invoice, receiving a payment) O(1) per
  customer rather than O(n) over the whole ledger.
- **Full-table rebuild is available but rare.** `customers:rebuild-balances` exists for disaster
  recovery / drift correction (see Edge Cases) and is designed to run in company-scoped batches with
  a progress checkpoint, safe to resume after interruption, so it never needs to be run as a single
  multi-hour uninterruptible transaction against a large tenant.
- **Reconciliation job.** A nightly job sums `customer_balances.balance_net_base` across a company
  and compares it to the AR control account's balance as derived from posted `journal_lines`; any
  discrepancy above a small floating-point tolerance raises a `notifications` entry to Finance and
  an internal ops alert — this is the primary safety net catching any bug that could let the
  sub-ledger and GL drift apart.
- **Indexing strategy** (see Database Design → Indexes) keeps the two highest-traffic query
  patterns — "customers for company X, active, matching filter Y" and "customer balances for
  company X sorted by exposure/overdue" — served by partial, composite indexes sized to the active
  working set rather than the full historical table, so p99 latency does not degrade as archived
  history accumulates over years.
- **Search latency.** Smart Search's trigram + full-text index keeps typo-tolerant name search
  under ~50ms at the target scale (low millions of customer rows per company is the realistic
  ceiling for any single tenant); the AI natural-language-to-filter translation step runs
  asynchronously relative to a fallback plain-text search that returns immediately, so a slow AI
  round-trip never blocks the user from seeing *some* results (progressive enhancement: plain
  results first, AI-refined results replace them in place if they arrive within a short timeout,
  default 800ms).
- **Bulk import/export are queued, not request-synchronous.** Both run as Laravel queued jobs on a
  dedicated queue (`customers-bulk`) with a worker concurrency cap, so a very large import (tens of
  thousands of rows) cannot starve the web-request queue or block other tenants' unrelated jobs; the
  requester is notified on completion rather than holding an HTTP connection open.
- **AI agent calls are rate-limited per company** to protect both cost and latency budgets — e.g.,
  Risk Scoring recompute is deduplicated so that ten domain events for the same customer within a
  short window collapse into a single recompute rather than ten redundant AI calls.
- **Pagination is cursor-capable** for the Customer List and Aging Report specifically because
  Finance/Sales users routinely paginate deep into large portfolios; offset pagination degrades
  for high page numbers on large tables, so the repository layer supports keyset/cursor pagination
  as the platform-standard `meta.pagination.cursor` alongside classic page numbers for UI
  compatibility.

# Edge Cases

- **Customer changes preferred currency while an open invoice exists in the old currency.** Blocked
  by Business Rule #12 without Finance approval; when approved, the existing invoice keeps its
  original transaction currency (never retroactively converted) and only new documents use the new
  preferred currency — `customer_balances` computes `balance_net_ccy` per the *current* preferred
  currency for display but this is always secondary to `balance_net_base`, which is unaffected by
  the preference change.
- **Two customers are merged, and one of them has an *open* (unposted draft) sales order at merge
  time.** The draft order's `customer_id` is re-pointed to the survivor like any other document; if
  the draft references a price list or contact specific to the merged-away customer that no longer
  makes sense for the survivor (e.g., a contact that was not carried over), the merge screen
  surfaces this as a warning requiring the approver to also resolve draft-document conflicts before
  approving, rather than silently leaving a draft order pointing at a now-invalid contact.
- **A blacklisted customer has an open, already-confirmed sales order.** Existing confirmed orders
  are not automatically cancelled by blacklisting (see Blocking Rules — full block affects *new*
  documents); Sales must explicitly decide, per company policy, whether to fulfill or cancel
  in-flight orders for a newly blacklisted customer, and that cancellation, if chosen, goes through
  the normal order-cancellation workflow (Sales module), not a Customer Management action.
- **A customer's only identifying document (e.g., sole tax registration certificate) expires and
  the tax-exemption-dependent invoice needs to be raised before a renewed certificate arrives.** The
  system auto-reverts `tax_exempt = false` on expiry (Business Rule #4) and the invoice is raised
  taxable; if the certificate is later renewed retroactively, a Finance-approved credit note
  corrects the difference — QAYD never blocks invoicing to wait on a document renewal, because
  blocking revenue recognition over a paperwork gap is worse than issuing a correctable, slightly
  wrong invoice.
- **Duplicate Detection flags a false positive between two genuinely distinct customers who happen
  to share a common, generic legal name** (e.g., two unrelated companies both named "Gulf Trading
  Co." in different cities). The non-blocking warning at creation and the Merge Candidates queue
  both allow explicit dismissal (`dismissed_by`, `dismissed_reason`), and a dismissed pairing is
  remembered (`customer_duplicate_dismissals` table, `customer_id_a`, `customer_id_b`) so the same
  pair is never re-flagged by the nightly sweep, preventing alert fatigue from a known non-duplicate
  pair.
- **A customer is deleted (soft) and, within the recovery window, a new customer is created with the
  exact same `commercial_registration_number`.** The partial unique index
  (`uq_customers_commercial_reg_active`) is scoped `WHERE deleted_at IS NULL`, so this is
  permitted — Postgres does not see a conflict against the soft-deleted row. If the original is
  later restored within the recovery window, the restore operation itself re-checks the unique
  constraint and fails with a clear `409` if a conflicting active row now exists, directing the user
  to resolve the conflict (typically: merge the new one into the restored one, or vice versa)
  rather than silently creating two active rows with the same legal identifier.
- **An International customer's billing country changes** (a legitimate international customer
  relocates its registered office to the operating company's home country). `is_international`
  application logic recomputes on address change and flips to `false`; this does not change
  `customer_type` (still Business/Government/etc.) but does change default tax treatment
  eligibility going forward — handled by the same non-retroactive snapshot pattern as any other
  master-data change.
- **A payment is received with no invoice reference at all (a cheque simply arrives)** and the
  payer cannot yet be matched to an existing customer with certainty. The receipt is recorded
  against a placeholder "Unidentified Receipts" holding customer (a company-level
  `customers.is_system_placeholder = true` row seeded per company, excluded from all
  customer-facing reports and from Duplicate Detection/Merge eligibility) until Finance identifies
  and reallocates it to the correct real customer — this avoids ever inventing a low-confidence,
  possibly-wrong real customer record just to park an unmatched receipt.
- **Concurrent credit-limit-affecting events** (two sales orders for the same customer confirmed
  within milliseconds of each other, both individually within the limit but jointly over it). The
  exposure calculation and the limit check happen inside a `SELECT ... FOR UPDATE`-guarded
  transaction on the customer's `customer_balances` row, serializing concurrent confirmations for
  the same customer so the second confirmation sees the first's already-applied exposure and is
  correctly held if it would breach the limit — this is the one place in the module where a
  row-level lock is deliberately used to trade a small amount of concurrency for correctness on a
  financial control.
- **A Government customer's warn-only credit block is bypassed repeatedly, masking a genuine
  collection problem.** Because warn-only still logs and notifies on every occurrence (Blocking
  Rules), a monthly report surfaces "Government customers with repeated warn-only overrides" so this
  policy choice cannot quietly become a blind spot; a company can tighten a specific Government
  customer to hard-block via a per-customer override flag if repeated overrides indicate the
  statutory-payment-cycle assumption does not actually hold for that account.

# Future Improvements

- **Third-party credit bureau / commercial registry integration** (e.g., a Kuwait Chamber of
  Commerce or regional credit-bureau API) to enrich AI Risk Scoring with external data rather than
  transaction history alone, particularly valuable for scoring brand-new customers with no internal
  history yet.
- **Customer self-service portal** allowing a customer's own primary contact to view their
  statement, download invoices, and initiate a payment — architecturally anticipated by keeping
  customer-visible vs. internal-only fields already distinguished (e.g., `customer_notes.visibility`,
  the Security section's explicit "never customer-visible" list) so the portal can be added as a new
  read-scoped API surface without a data-model rework.
- **Automated dunning sequences** — currently Collections drafts communications for human review
  and send (see Notifications, AI Responsibilities → Payment Prediction); a future phase could allow
  a company to opt a specific, low-risk segment into fully automated, template-based dunning
  sequences for the earliest, softest bucket only (1–30 days), with human review remaining mandatory
  for every later bucket.
- **Multi-entity consolidated customer view** for corporate groups operating several QAYD tenant
  companies, as a read-only, permission-gated cross-company report (never a shared/merged data
  model — see Customer Relationships → Companies for why isolation is preserved) for groups that
  want to see a shared customer's aggregate exposure across sister entities.
- **Real-time collaborative editing indicators** on the customer profile (via the existing Laravel
  Reverb realtime layer) so two Sales users do not unknowingly overwrite each other's edits to the
  same customer within seconds of each other — currently handled only by optimistic-concurrency
  `updated_at` version checking on write, which is correct but not as ergonomic as a live "someone
  else is editing this" indicator.
- **Configurable, per-segment overdue-bucket and blocking-rule templates**, beyond today's
  company-wide default plus the Government-specific override, so a company could, for example, give
  its "key account" segment a more lenient bucket table without a one-off per-customer override for
  every account in that segment.
- **Native e-KYC / document OCR auto-verification** for `customer_documents` (commercial
  registration, tax certificates) using the platform's Document AI/OCR agent to pre-fill and
  cross-check document fields against submitted master data automatically, reducing the manual
  `verified_by`/`verified_at` review burden for high-volume onboarding.
- **Sanctions/watchlist screening as a continuously refreshed external feed** rather than a
  company-configured denylist snapshot, with automatic re-screening of the full active customer
  base whenever the feed updates, surfaced through the same Fraud Detection notification path
  already defined.
- **Predictive credit-limit auto-adjustment recommendations expanded to a full "credit policy
  simulator"** letting a Finance Manager model the portfolio-wide exposure impact of a proposed
  blanket policy change (e.g., "what happens to total exposure if we raise the SMB segment default
  limit by 20%") before approving it for real, using the same Revenue Forecasting/Risk Scoring
  models in a what-if mode.

# End of Document

