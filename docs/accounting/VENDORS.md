# Vendor Management — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Vendor Management (Accounts Payable Master)
---

# Purpose

Vendor Management is the master-data and sub-ledger control system that governs every entity QAYD
pays money to: suppliers of goods, service providers, contractors, government bodies, and individual
payees. It is the single authoritative registry of vendor identity, tax status, banking details,
contractual terms, and risk posture, and it is the anchor that every downstream Purchase-to-Pay (P2P)
document — purchase order, goods receipt, bill, debit note, vendor payment — must reference.

Concretely, this module is responsible for:

1. **Vendor master data** — identity, legal structure, tax registration, contacts, addresses, bank
   accounts, currencies, payment terms, credit terms, attachments, certificates, contracts, notes,
   tags, and custom fields, all scoped to a single `company_id` and optionally a `branch_id`.
2. **Vendor lifecycle governance** — a controlled state machine (Prospect → Approved → Active →
   Inactive → Blacklisted → Archived) so that no vendor can receive a purchase order or a payment
   without passing through approval, and no vendor can be permanently removed once it has financial
   history (archive-not-delete).
3. **AP sub-ledger integrity** — every vendor's outstanding balance must reconcile, at all times, to
   the Accounts Payable control account in the General Ledger. Vendor Management does not post journal
   entries itself; Purchasing and Banking post them via domain events, and Vendor Management exposes
   the running balance, aging buckets, and open-item detail that finance uses to verify the
   reconciliation.
4. **Risk and compliance gatekeeping** — computing and surfacing a vendor risk score (AI-assisted),
   detecting duplicate vendor records before they are created, enforcing withholding tax rules where
   applicable, and blocking payment or PO issuance to vendors that fail compliance checks (expired
   certificate, blacklist status, sanctions match, missing tax registration).
5. **Bank-detail custody for payments** — `vendor_bank_accounts` is the single source of truth that
   `vendor_payments` (owned by the Purchasing/Payments module) reads from. Vendor Management enforces
   verification workflow and change-control on bank details specifically because bank-detail changes
   are the single highest-value fraud vector in AP (business email compromise, fake vendor updates).
6. **Vendor performance intelligence** — tracking on-time delivery, quality, responsiveness, and price
   competitiveness so that Purchasing and Finance can make sourcing decisions grounded in data rather
   than anecdote.

Vendor Management does **not** own: purchase requisitioning, RFQ/RFQ-response comparison, purchase
order approval workflow, goods receipt inspection, bill matching, or payment execution — those live in
the Purchasing/Payments module and merely reference `vendors.id`. This document specifies the vendor
master and its lifecycle, risk, and reconciliation responsibilities; it specifies the *contract*
(tables, events, API) that Purchasing/Payments consumes, but the transactional documents themselves
are out of scope here except where needed to describe integration.

# Vision

The vendor master should function the way a well-run enterprise AP department's vendor file
functions inside SAP or Oracle Fusion Procurement: a single, deduplicated, continuously verified
record per real-world supplier, that every other module trusts without re-validating. Three
principles drive the design:

**Single source of truth, zero duplication.** A vendor exists exactly once per company, regardless of
how many departments, branches, or purchasing agents deal with it. The system actively prevents
duplicate creation (fuzzy matching on tax ID, IBAN, name, phone, email) rather than relying on manual
vendor-master review, because duplicate vendor records are the leading cause of both fraud losses and
duplicate payments in AP operations.

**Nothing pays without governance.** No vendor can receive a purchase order, invoice, or payment while
in `prospect` state. Bank account changes on an `active` vendor require re-verification before the new
account can be used for payment. Blacklisted vendors are hard-blocked at the API layer, not just
hidden in the UI — the block is enforced in the Service layer so it cannot be bypassed by a
differently-shaped request.

**AI proposes, humans and policy approve.** The AI layer continuously scores vendor risk, flags
probable duplicates, watches for fraud signals (sudden bank-detail change combined with a large
pending invoice, for example), and suggests supplier consolidation — but it never auto-approves a
vendor, never auto-changes a bank account, and never auto-releases a payment. Every AI action in this
module is a suggestion with a confidence score and supporting evidence that a human with the right
permission must act on.

**Archive, never delete.** Once a vendor has any transactional history (a PO, a bill, a payment, a
goods receipt), its row is immutable at the identity level. It can be deactivated, blacklisted, or
archived, but it can never be hard-deleted, because the General Ledger, historical financial
statements, and audit trail must always be able to resolve `vendor_id` to a real record. Only vendors
with zero transactional history and zero linked documents can be hard-deleted, and even then the
deletion itself is audit-logged.

The long-term vision is a vendor master that a CFO can hand to an external auditor with full
confidence: every balance ties to the GL, every bank-detail change has a verification trail, every
approval has a named approver and timestamp, and every AI-influenced decision carries its reasoning.

# Vendor Lifecycle

A vendor's `status` column is a strict enum that drives what the vendor is allowed to do in every
other module. Transitions are one-directional except where explicitly noted, and every transition is
audit-logged with actor, timestamp, reason, and (where applicable) approval reference.

## States

| State | Meaning | Can receive PO? | Can be paid? | Can be edited (core fields)? |
|---|---|---|---|---|
| `prospect` | Registered but not yet vetted; created by a purchasing employee or via AI-suggested onboarding, or self-registered through a vendor portal. | No | No | Yes, freely |
| `approved` | Passed the approval workflow (tax ID verified, bank account verified, risk score acceptable) but has not yet transacted. | Yes (PO only) | No (no bills exist yet) | Yes, with audit log |
| `active` | Has at least one posted transaction (PO, bill, or payment) OR has been explicitly activated by Purchasing/Finance. | Yes | Yes | Yes, with audit log; bank/tax changes require re-verification |
| `inactive` | Temporarily suspended — e.g. contract lapsed, vendor requested pause, seasonal supplier out of season. Reversible. | No (blocked) | Existing open bills can still be paid; no new bills | Yes, with audit log |
| `blacklisted` | Hard block — fraud, sanctions match, repeated quality failure, contract breach, legal dispute. | No (hard block) | No (hard block, including existing open bills — requires Finance Manager override with reason) | Read-only except by Admin/Owner with justification |
| `archived` | Vendor is retired; retained for historical/audit purposes only. | No | No | Read-only |

## State Machine (ASCII)

```
                 ┌────────────┐
                 │  prospect  │  (created)
                 └─────┬──────┘
                        │ approve() [Vendor Approval Workflow]
                        ▼
                 ┌────────────┐
        ┌───────►│  approved  │
        │        └─────┬──────┘
        │               │ first PO/bill posted, or activate()
        │               ▼
        │        ┌────────────┐
   reactivate()   │   active   │◄───────────┐
        │        └─────┬──────┘             │ reactivate()
        │               │ deactivate()       │
        │               ▼                    │
        │        ┌────────────┐              │
        └────────┤  inactive  ├──────────────┘
                 └─────┬──────┘
                        │ blacklist() [from any non-archived state]
                        ▼
                 ┌────────────┐
                 │ blacklisted│─── unblacklist() [Owner/Admin only, requires reason] ──► inactive
                 └─────┬──────┘
                        │ archive() [any terminal state, requires zero open balance
                        │            or Finance Manager override]
                        ▼
                 ┌────────────┐
                 │  archived  │  (terminal; unarchive() restores to `inactive` — Admin only)
                 └────────────┘
```

## Transition Rules

- `prospect → approved`: requires the Vendor Approval Workflow to complete successfully (see
  dedicated section). Cannot be skipped, even by Owner — the workflow can be configured with a single
  auto-approving step for low-risk categories, but the state transition always passes through the
  workflow engine so it is logged.
- `approved → active`: automatic on first successful posting of a `purchase_order`, `bill`, or
  `vendor_payment` referencing this vendor (domain event `purchasing.document.posted` triggers a
  listener in Vendor Management that flips status). Can also be manually triggered by
  `purchasing.vendor.activate` permission holders for vendors that need to be "live" before their
  first document (e.g., to appear in a price list negotiation).
- `active → inactive`: manual only, `purchasing.vendor.deactivate` permission, reason required
  (free text, minimum 10 characters, stored in `vendors.status_reason` and in `audit_logs`).
- `inactive → active`: manual, `purchasing.vendor.activate` permission, reason optional.
- `* → blacklisted`: manual, `purchasing.vendor.blacklist` permission (default: Finance Manager, CFO,
  Owner only), reason **required**, minimum 20 characters, and the UI forces selection of a
  `blacklist_category` enum (`fraud`, `sanctions`, `quality`, `contract_breach`, `legal_dispute`,
  `other`). Blacklisting a vendor with open, unpaid bills triggers a notification to Finance Manager
  and Auditor roles ("N open bills totaling {amount} {currency} exist for a newly blacklisted
  vendor") but does not auto-void them — a human decides whether to pay, dispute, or write off.
- `blacklisted → inactive` ("unblacklist"): manual, `purchasing.vendor.unblacklist` permission
  (default: Owner, CFO only — deliberately narrower than the permission that blacklists), reason
  required, and the action is always double-audit-logged (once in `audit_logs`, once in a dedicated
  `vendor_status_history` row that is never purged).
- `* → archived`: manual, `purchasing.vendor.archive` permission. Blocked with a 409 Conflict if
  `vendors.balance_due <> 0` unless the actor holds `purchasing.vendor.archive.force` (Finance Manager
  and above), in which case the override reason is mandatory and surfaced to the Auditor role via
  notification.
- `archived → inactive` ("unarchive"): `purchasing.vendor.unarchive`, Admin/Owner only.
- **Hard delete** is only permitted when `vendors.status = 'prospect'` AND zero rows exist in
  `purchase_orders`, `bills`, `vendor_payments`, `goods_receipts`, `debit_notes` referencing the
  vendor. This is enforced by a Postgres trigger (`prevent_vendor_hard_delete`, see Database Design)
  in addition to the Service-layer check, so it cannot be bypassed even by a direct database client
  under an operator role that skips the application layer.

## Vendor Status History Table

Every transition writes a row to `vendor_status_history` (see Database Design for DDL) capturing
`from_status`, `to_status`, `reason`, `actor_id`, `occurred_at`, and an optional
`approval_reference_id` pointing to the `vendor_approvals` row that authorized the change, if any.
This table is append-only (no UPDATE, no DELETE at the application layer; enforced by a REVOKE on
those privileges for the application database role).

# Vendor Types

`vendors.vendor_type` is an enum that changes which profile fields are required, which tax rules
apply, and which default approval path is used. Each type is described below with its distinguishing
requirements.

| Type | Description | Required profile fields beyond baseline | Default approval path | Typical tax treatment |
|---|---|---|---|---|
| `individual` | A natural person paid for services or one-off supply (freelance consultant, sole proprietor, landlord). | National ID or passport number, single primary contact (self), single bank account in the individual's own name (name-match enforced against `vendors.legal_name`). | Fast-track (single approver, Purchasing Manager) below a configurable threshold; standard path above it. | Subject to withholding tax (WHT) in jurisdictions that apply it to professional/service payments to individuals; VAT generally not applicable unless independently VAT-registered. |
| `company` | A locally incorporated commercial entity — the most common type. | Commercial registration number, tax registration number, at least one authorized signatory contact. | Standard 2-step approval (Purchasing Manager → Finance Manager). | VAT-registered vendors must supply a valid VAT number, validated against format rules per `vendor_addresses.country_code`; VAT is claimed as input tax on `bills` where the vendor's VAT number is populated. |
| `government` | A government ministry, authority, or state-owned enterprise. | Government entity code, budget/contract reference, no bank-account verification via micro-deposit (governments settle via GIRO/treasury transfer; verification is instead a signed letter attachment). | Elevated approval: Purchasing Manager → Finance Manager → CFO, regardless of amount. | Typically WHT-exempt; VAT treatment follows local public-sector rules (often zero-rated or exempt depending on jurisdiction). |
| `international` | Any vendor whose primary address country differs from the company's home country. | IBAN + SWIFT/BIC (no local bank routing number), currency other than or in addition to base currency, country-specific compliance flag (sanctions screening is **mandatory**, not optional, for this type). | Standard 2-step approval plus a mandatory compliance/sanctions check step that cannot be skipped even for low amounts. | Cross-border WHT treaty rules apply (see Business Rules); import VAT/customs handled by Purchasing/Inventory on goods receipt, not by this module directly, but the vendor record carries a `customs_registration_number` field for use on customs documentation. |
| `service_provider` | Supplies services rather than goods (consulting, marketing agency, IT support, cleaning, maintenance). | Service category tag (from a controlled list, reused for spend analytics), SLA reference if a `vendor_contracts` row exists. | Standard. | WHT commonly applies to professional/consulting services in many jurisdictions; no goods-receipt step in the P2P flow (bills can be created directly from a PO of type `service`, skipping `goods_receipts`). |
| `manufacturer` | Produces the goods being purchased (as opposed to reselling them). | Factory/production site address (can differ from registered address; stored as an additional `vendor_addresses` row with `address_type = 'factory'`), product certifications (feeds `vendor_certificates`), lead time (`vendors.default_lead_time_days`). | Standard, plus mandatory quality/certification check if company policy flags the category as regulated (food, pharma, electronics safety). | Full goods-receipt + quality-inspection flow before bill matching (3-way match: PO, goods receipt, bill). |
| `distributor` | Resells/distributes goods from one or more manufacturers; often the actual counterparty even when the brand is a manufacturer's. | Territory/exclusivity notes (free text + `tags`), list of represented manufacturer brands (`vendor_contracts` can reference this). | Standard. | Same VAT/goods-receipt treatment as `company`, distinguished mainly for spend-analytics and negotiation-strategy purposes (concentration risk if one distributor represents many brands). |
| `contractor` | Provides labor/works, typically under a fixed-price or time-and-materials contract (construction, renovation, IT project work). | Linked `vendor_contracts` row is **mandatory** before any PO can reference the vendor (contractors are never paid off a bare PO — there must be a contract defining milestones/retention). | Standard, plus a contract-review step (Legal/Procurement) before financial approval. | WHT commonly applies; retention-withholding on payments (a percentage held back until project completion) is modeled via `vendor_payments.retention_amount` in the Purchasing module, referencing this vendor's `default_retention_pct`. |

`vendor_type` is set at creation and can be changed later only by a user holding
`purchasing.vendor.reclassify`, because reclassification can change which profile fields are mandatory
and which approval path applies retroactively triggers a re-validation job (`ValidateVendorProfile`)
that raises a blocking notification if required fields for the new type are missing.

# Vendor Profile

The vendor profile is the complete set of attributes describing a vendor. It is deliberately modeled
as one core `vendors` row plus several 1-to-many child tables, rather than one wide table, because
contacts, addresses, bank accounts, certificates, and contracts each have independent lifecycles
(added, verified, expired, superseded) that a single row cannot represent cleanly.

## Identity

Core identity fields live directly on `vendors`:

- `vendor_code` — human-readable, company-scoped unique code, auto-generated as `V-{company_id
  short}-{sequence}` (e.g. `V-0042-000317`) unless the company has a custom numbering scheme
  configured (`companies.settings->vendor_numbering_pattern`), in which case the Service layer applies
  that pattern.
- `legal_name` — the registered legal name; this is the field fuzzy-matched during duplicate
  detection and the field that must match the name on the bank account and the tax certificate.
- `display_name` — optional trading/brand name shown in UI lists if different from `legal_name`
  (e.g. legal name "Al-Salam Trading Co. W.L.L.", display name "Al-Salam Foods").
- `name_ar` — Arabic legal/display name (bilingual convention).
- `vendor_type` — enum described above.
- `status` — lifecycle enum described above.
- `national_id` / `passport_number` — for `individual` type only; nullable otherwise, but a CHECK
  constraint enforces presence when `vendor_type = 'individual'`.

## Company Information

For `company`, `government`, `manufacturer`, `distributor`, and `contractor` types:

- `commercial_registration_number` — the local commercial/trade license number.
- `commercial_registration_expiry` — date; the AI Compliance Agent flags vendors within 30 days of
  expiry and blocks new PO issuance at 0 days past expiry unless overridden.
- `legal_structure` — enum (`sole_proprietorship`, `llc`, `wll`, `kscc`, `kscp`, `partnership`,
  `branch_of_foreign_company`, `government_entity`, `other`) — Gulf-oriented but extensible.
- `industry_sector` — free-text/controlled-list tag used for spend analytics and risk benchmarking.
- `year_established` — integer, used by the AI Risk agent as one signal (very new companies score
  slightly higher risk by default, adjustable).
- `website`, `logo_attachment_id` (polymorphic attachment).
- `parent_company_vendor_id` — nullable self-referencing FK, used when a vendor is a subsidiary/branch
  of another vendor already in the system (feeds concentration-risk reporting: total exposure to a
  corporate group, not just one legal entity).

## Tax Information

- `tax_registration_number` — the general tax ID (Kuwait: no VAT currently at time of writing but the
  field is jurisdiction-agnostic and populated where applicable; Saudi/UAE: mandatory 15-digit VAT
  registration number format validated by regex per country).
- `tax_residency_country` — ISO 3166-1 alpha-2, drives withholding-tax-treaty lookups.
- `withholding_tax_applicable` — boolean, defaulted by `vendor_type` + `service_category` but
  editable.
- `default_wht_rate` — NUMERIC(5,2), percentage, used by Purchasing when generating a bill's WHT line
  if `withholding_tax_applicable = true`. Stored per-vendor because treaty rates can differ vendor by
  vendor (a vendor tax-resident in a treaty country may have a reduced rate vs. the standard
  statutory rate).
- `tax_exemption_certificate_attachment_id` — nullable, polymorphic attachment; when present and
  unexpired, `withholding_tax_applicable` is effectively overridden to false by the calculation logic
  even if the flag is true, and the AI Tax Advisor surfaces this as a note on every bill for that
  vendor.

## VAT

VAT is modeled as a first-class concern, not folded into generic "tax information", because it drives
input-tax recovery on every bill:

- `vat_registration_number` — validated format per country (e.g. Saudi Arabia: 15 digits starting and
  ending in specific check digits per ZATCA rules; UAE: 15 digits per FTA rules).
- `vat_registered` — boolean; if false, `bills` for this vendor cannot claim input VAT even if a VAT
  line is entered, and the UI/API rejects a non-zero VAT amount with a 422 validation error referencing
  `vendor.not_vat_registered`.
- `vat_registration_certificate_attachment_id` — nullable attachment; the AI Compliance Agent
  cross-checks the VAT number on this certificate (via OCR) against the typed `vat_registration_number`
  field and flags mismatches as a `suggest-only` finding.
- `place_of_supply_country` — used for cross-border VAT logic (reverse charge on international
  services) in Gulf VAT regimes that implement it.

## Registration Numbers

A vendor can hold multiple jurisdiction-specific IDs beyond commercial registration and tax
registration. These are modeled as flexible key/value rows rather than fixed columns because the set
of applicable registration types varies enormously by country and vendor type:

```
vendor_registration_numbers
  id, vendor_id, registration_type (enum: 'chamber_of_commerce','municipality_license',
     'civil_defense_license','ministry_license','import_export_code','other'),
  registration_number, issuing_authority, issue_date, expiry_date, attachment_id
```

This table is described fully in Database Design. It allows, for example, a `manufacturer` in a
regulated category to carry a civil-defense license and a ministry-of-health license simultaneously,
each independently trackable for expiry.

## Contacts

`vendor_contacts` — one-to-many. Every vendor should have at least one contact of type `primary`
before it can move past `prospect`. Fields: `full_name`, `name_ar`, `job_title`, `email`, `phone`,
`mobile`, `contact_type` (enum: `primary`, `billing`, `sales`, `technical`, `escalation`,
`signatory`), `is_primary` (boolean, exactly one per vendor enforced by a partial unique index),
`preferred_language` (en/ar), `notes`. Contacts are the recipients of vendor-portal invitations and of
automated communications (RFQ invites, PO confirmations, payment-remittance advice).

## Addresses

`vendor_addresses` — one-to-many, typed via `address_type` (`registered`, `billing`, `shipping`,
`factory`, `warehouse`, `other`). Exactly one `registered` address is required; `billing` defaults to
the registered address if not separately specified (resolved at read-time by the Service layer, not
duplicated in storage). Full structured address (`address_line1`, `address_line2`, `city`, `state_province`,
`postal_code`, `country_code`, `latitude`, `longitude` for logistics/delivery routing use by
Purchasing/Inventory).

## Bank Accounts

`vendor_bank_accounts` — one-to-many, because a vendor may be paid to different accounts for different
currencies or business lines. Each row: `account_holder_name` (must fuzzy-match `vendors.legal_name`
— mismatch is a hard validation warning, overridable only with a documented reason, since paying a
vendor's invoice into an account not in that vendor's own name is a classic fraud pattern),
`bank_name`, `bank_country`, `iban`, `swift_bic`, `account_number` (for jurisdictions without IBAN),
`currency_code`, `is_primary` (one primary per currency, enforced by partial unique index on
`(vendor_id, currency_code) WHERE is_primary`), `verification_status` (enum: `unverified`,
`pending_verification`, `verified`, `verification_failed`, `flagged`), `verified_at`, `verified_by`.

**Bank-detail change control** is the single most security-sensitive workflow in this module:

1. Any INSERT or UPDATE of IBAN/account number/SWIFT on a vendor that is `active` or `approved`
   creates the row (or a shadow "pending" copy of an update) with `verification_status =
   'pending_verification'` and does **not** immediately become payable-to.
2. A notification fires to Finance Manager and, if configured, to the vendor's registered primary
   contact via a callback-verification channel (a phone call or a call-back email to a previously
   known contact — never to a contact supplied in the same change request, to defeat business-email
   compromise where the attacker changes the contact and the bank account together).
3. Verification requires `purchasing.vendor.bank.verify` permission and is a distinct, dedicated
   action (`POST /api/v1/purchasing/vendors/{id}/bank-accounts/{bankAccountId}/verify`) — it cannot be
   done implicitly by simply editing the row.
4. Until verified, `vendor_payments` (Purchasing/Payments module) refuses to select this bank account
   as a payment destination, enforced by a foreign-key-plus-check pattern described in Database
   Design and by a Service-layer guard.
5. Every verification and every rejection is audit-logged with the reviewer identity and any evidence
   attached (call log note, signed letter scan).

## Currencies

A vendor is not limited to one currency: `vendors.default_currency_code` sets the default used when
creating a new PO/bill for this vendor, but `vendor_bank_accounts.currency_code` can list several
supported settlement currencies. `Purchases` (POs, bills) can be raised in any currency the vendor
supports; if raised in a currency the vendor has no verified bank account for, the system still allows
it but the AI Payment Prediction agent flags that manual/telegraphic transfer instructions will be
needed (`suggest-only`).

## Payment Terms

`vendors.default_payment_terms` — enum/reference of the standard net terms (`net_15`, `net_30`,
`net_45`, `net_60`, `net_90`, `due_on_receipt`, `cod`, `custom`), with `custom_payment_terms_days`
(integer) used when `default_payment_terms = 'custom'`. This value is copied onto every `bill` created
for the vendor at creation time (so later changes to the vendor default do not retroactively change
already-created bills' due dates), and is used by the `bills.due_date` computation
(`bill_date + payment_terms_days`).

Early-payment-discount terms are modeled as a nullable structured field:
`early_payment_discount_pct` NUMERIC(5,2) and `early_payment_discount_days` INTEGER (e.g., "2/10 net
30" = 2% discount if paid within 10 days, net 30 otherwise), consumed by the AI Payment Prediction
agent to recommend which open bills to pay early for maximum discount capture within available cash.

## Credit Terms

Distinct from payment terms (which describe when *we* must pay the vendor), credit terms describe
limits the vendor extends: `credit_limit_amount` NUMERIC(19,4), `credit_limit_currency_code`, and
`credit_hold` (boolean, manually set by Finance if the vendor has cut off further supply pending
payment of overdue balances). `credit_hold = true` does not block *us* from paying the vendor (that
would be counterproductive) — it is informational, surfaced prominently in the Purchasing UI as a
warning when creating a new PO with this vendor, and can optionally be configured
(`companies.settings->purchasing.block_po_on_vendor_credit_hold`) to hard-block new PO creation.

## Attachments

Generic vendor documents (trade license copy, W-9/equivalent, insurance certificate, signed NDA) use
the foundation polymorphic `attachments` table (`attachable_type = 'vendor'`, `attachable_id =
vendors.id`). Certificates and contracts that need structured expiry-tracking use the dedicated
`vendor_certificates` and `vendor_contracts` tables instead (see below and Database Design) — generic
`attachments` is for everything that doesn't need its own lifecycle tracking.

## Certificates

`vendor_certificates` — structured records for anything with an issue/expiry date that must be
monitored: ISO certifications, product safety certifications, insurance policies, tax exemption
certificates, professional licenses. Fields: `certificate_type`, `certificate_number`, `issuing_body`,
`issue_date`, `expiry_date`, `status` (`valid`, `expiring_soon`, `expired`, `revoked`), `attachment_id`.
A scheduled job (`CheckVendorCertificateExpiry`, daily) recomputes `status` for every row based on
`expiry_date` vs. today, and the AI Compliance Agent uses `expiring_soon` (default: within 30 days,
configurable per `certificate_type`) to generate proactive renewal-reminder notifications to the
vendor's primary contact and to the internal owner (`purchasing.vendor.certificates.manage`
permission holders).

## Contracts

`vendor_contracts` — master service agreements, framework agreements, or project contracts governing
the commercial relationship: `contract_number`, `contract_type` (`framework`, `project`, `nda`,
`sla`, `exclusive_supply`), `start_date`, `end_date`, `auto_renew` (boolean), `renewal_notice_days`,
`contract_value` NUMERIC(19,4), `retention_pct` NUMERIC(5,2) (for contractors, see Vendor Types),
`payment_milestones` JSONB (array of `{name, pct, due_condition}` for milestone-billed contracts),
`status` (`draft`, `active`, `expired`, `terminated`, `superseded`), `attachment_id` for the signed
document. Contracts are the mandatory prerequisite for `contractor`-type vendors before PO creation
(see Vendor Types) and are consumed by the AI Contract Analysis responsibility to extract obligations,
penalty clauses, and renewal deadlines automatically from uploaded PDFs.

## Notes

`vendors.internal_notes` — free-text, internal-only (never exposed via any vendor-portal or external
API), for qualitative context ("historically slow to respond to RFQs but best price on steel"). Notes
are versioned via the standard audit log (every edit recorded, not overwritten silently) but the field
itself always shows only the latest value; history is retrievable via `GET
/api/v1/purchasing/vendors/{id}/audit-log`.

## Tags

`vendors.tags` JSONB array of free-form strings (`["preferred", "strategic", "single-source",
"local-content"]`) used for filtering, spend-analytics grouping, and as an input signal to AI Smart
Suggestions (e.g., "preferred" vendors are suggested first for new POs in their category).

## Custom Fields

`vendors.custom_fields` JSONB object, schema-less at the database level but governed at the
application level by a company-configurable field-definition table
(`custom_field_definitions`, foundation-level, not re-specified here) so that each company can add
industry-specific attributes (e.g., a construction company adding "safety_rating"; a pharma
distributor adding "GDP_certified") without a schema migration. Custom fields participate in search
and filtering via a GIN index on the JSONB column (see Database Design).

# Procurement Integration

Vendor Management is deliberately a passive integration point for the Purchasing/Payments module: it
owns `vendors` and its child tables; Purchasing owns the transactional documents and posts domain
events that this module listens to in order to update lifecycle status, running balances, and
performance metrics. No cross-module direct database writes occur — every interaction is either (a) a
foreign key reference from a Purchasing table to `vendors.id`, or (b) a domain event exchanged via the
event bus.

## Purchase Orders

`purchase_orders.vendor_id` references `vendors.id`. Vendor Management exposes
`GET /api/v1/purchasing/vendors/{id}/purchase-orders` as a read-through convenience endpoint (it
queries the Purchasing module's tables but is surfaced under the vendor context for UI convenience).
On PO creation, Purchasing validates vendor eligibility by calling the Vendor Management Service layer
method `VendorEligibilityService::assertCanReceivePO($vendorId)`, which checks: `status IN
('approved','active')`, not blacklisted, no expired mandatory certificate for a regulated category,
and (if configured) not on credit hold. Domain event emitted by Purchasing on PO issuance:
`purchasing.po.created` — Vendor Management listens and increments `vendors.lifetime_po_count` and
`vendors.lifetime_po_value` (denormalized counters refreshed by the listener, used for fast
vendor-list sorting without a live aggregate query).

## Bills

`bills.vendor_id` references `vendors.id`. Bills are the AP sub-ledger's actual posting trigger: when
a bill is posted (`bills.status` transitions to `posted`), Purchasing emits `purchasing.bill.posted`
with `{vendor_id, bill_id, amount, currency_code, base_amount, due_date}`. Vendor Management's listener
`UpdateVendorBalance` increments `vendors.balance_due` (denormalized, base currency) and inserts/refreshes
the corresponding row in the `vendor_open_items` materialized read-model (see Database Design) used
for aging reports. This is the mechanism by which the vendor's balance stays reconciled to the AP
control account: the actual GL posting (debit expense/asset, credit AP control account) happens in the
Accounting module triggered by the same `purchasing.bill.posted` event; Vendor Management's
`balance_due` is a fast read-model of the sum of that vendor's unpaid `bills`/`debit_notes`, and a
nightly reconciliation job (`ReconcileVendorBalancesToGL`) compares
`SUM(vendors.balance_due) per company` against the AP control account balance from `ledger_entries`
and raises a `reconciliation.mismatch` alert to Finance Manager/Auditor if they diverge by more than a
configurable tolerance (default 0.01 in base currency, i.e., effectively zero, allowing only for
floating-point-adjacent rounding that should not occur with NUMERIC arithmetic — any mismatch is
treated as a real defect to investigate, not rounding noise).

## Payments

`vendor_payments.vendor_id` references `vendors.id`; `vendor_payments.vendor_bank_account_id`
references `vendor_bank_accounts.id` with a CHECK-enforced constraint (via a Postgres trigger, since
cross-table conditional constraints cannot be pure CHECK constraints) that the referenced bank account
must have `verification_status = 'verified'` at the time of payment creation. On payment posting,
Purchasing emits `purchasing.payment.posted` with `{vendor_id, payment_id, amount, currency_code,
base_amount, bill_ids[]}`; Vendor Management decrements `vendors.balance_due` and updates
`vendors.last_payment_date`, `vendors.lifetime_payment_count`, and feeds the AI Supplier Performance
agent's on-time-payment-to-vendor metric (yes, the module tracks not only vendor performance to us but
our payment punctuality to the vendor, since that is itself a vendor-relationship risk factor —
chronically late payment predicts supply disruption).

## Returns

Vendor returns are modeled via `debit_notes` (owned by Purchasing) — a debit note reduces the amount
owed to the vendor (e.g., returning defective goods after a bill was posted) or, if it exceeds the
open balance, creates a receivable-from-vendor position. `debit_notes.vendor_id` references
`vendors.id`; posting emits `purchasing.debit_note.posted`, decrementing `vendors.balance_due`
symmetrically to how a bill increments it. If a debit note would drive `balance_due` negative, Vendor
Management flags `vendors.has_credit_balance = true`, surfaced in the vendor list and in the Vendor
Aging report as a negative-aging bucket, and the AI Payment Prediction agent will suggest netting it
against the next bill rather than requesting a refund transfer where policy allows.

## Contracts

Already detailed under Vendor Profile — `vendor_contracts` is owned by this module but referenced by
Purchasing for milestone-billing validation: when a `bills` row is created against a `contractor`-type
vendor, Purchasing calls `VendorContractService::validateMilestone($vendorId, $contractId,
$milestoneName)` before allowing the bill to reference that milestone, ensuring double-billing of the
same milestone is impossible (enforced additionally by a unique constraint in Purchasing's schema on
`(contract_id, milestone_name)` for non-voided bills, which Purchasing owns but which is documented
here because it depends on `vendor_contracts.payment_milestones`).

## Recurring Purchases

For vendors with standing orders (a monthly utility, a recurring service retainer), Purchasing owns a
`purchase_order_schedules` / recurring-bill-template mechanism (detailed in the Purchasing module doc)
that references `vendors.id` and reads `vendors.default_payment_terms`,
`vendors.default_currency_code`, and the primary verified `vendor_bank_accounts` row at each
generation cycle. Vendor Management's only responsibility here is to expose
`GET /api/v1/purchasing/vendors/{id}/payment-profile` — a consolidated read of default terms, currency,
and verified bank account — as a single call Purchasing's scheduler uses rather than joining three
tables itself, keeping the module boundary clean.

## Inventory

Vendors of type `manufacturer` and `distributor` are the counterparties on `goods_receipts`
(Inventory module owns `stock_movements` triggered by goods receipt). Vendor Management contributes
`vendors.default_lead_time_days` (used by Inventory's reorder-point calculation) and the AI Supplier
Performance agent's `on_time_delivery_rate` metric (computed from
`goods_receipts.received_date` vs. `purchase_orders.expected_delivery_date`, both owned by
Purchasing/Inventory, aggregated per vendor and written back to
`vendors.performance_score_delivery` by a nightly job `RecomputeVendorPerformanceScores`).

## Projects

Where a `contractor` or `service_provider` vendor is engaged against a `projects` dimension
(cost accounting), `purchase_orders.project_id` and `bills.project_id` (both nullable FKs to
`projects`, owned by the Accounting dimensions addendum) let spend be sliced by project independently
of vendor. Vendor Management surfaces `GET /api/v1/purchasing/vendors/{id}/spend-by-project` as an
aggregation convenience endpoint over Purchasing's tables, grouped by `project_id`, useful for project
managers auditing which vendors are consuming their budget.

## Assets

Vendors that supply capital assets (equipment, machinery, vehicles) are linked at the `bills` level
via `bill_items.asset_id` (nullable FK to the Assets module's `fixed_assets` table, not detailed
here) — when a bill item is flagged as capitalizable, the Assets module creates the corresponding
fixed-asset record and stores `vendors.id` as `fixed_assets.acquired_from_vendor_id` for warranty and
maintenance-contact lookup. Vendor Management exposes
`GET /api/v1/purchasing/vendors/{id}/assets-supplied` as a read-through convenience for maintenance
teams who need the original vendor's contact details for warranty claims.

# Business Rules

1. **A vendor must have `company_id` on every row of every child table**, matching the parent
   `vendors.company_id` exactly. This is enforced by a composite foreign key
   (`(company_id, id)` on `vendors`, and `(company_id, vendor_id)` foreign keys on every child table)
   so that a child row can never silently point to a vendor in a different company even if the
   `vendor_id` value happens to be a valid ID within the whole database — cross-tenant leakage is
   made structurally impossible, not just application-logic-impossible.
2. **A vendor cannot be its own parent.** `parent_company_vendor_id` has a CHECK
   (`parent_company_vendor_id <> id`) and the Service layer additionally checks for cycles up to a
   depth of 10 when setting this field (a vendor group hierarchy should never realistically nest
   deeper than a handful of levels; a cycle indicates a data-entry error and is rejected with a 422).
3. **Exactly one primary contact, exactly one primary address per type, exactly one primary bank
   account per currency.** Enforced via partial unique indexes (see Database Design), not
   Service-layer-only checks, so bulk-import and direct-SQL paths cannot violate it either.
4. **Bank account currency must be a currency the vendor is configured to transact in.** If
   `vendor_bank_accounts.currency_code` is not already present in `vendors.supported_currencies`
   (a JSONB array), the INSERT is allowed but the API response includes a warning
   (`warnings: ["currency_not_in_supported_list"]`) and the AI layer will suggest adding it.
5. **Withholding tax defaults are computed, never silently assumed.** When
   `vendor_type IN ('individual','service_provider','contractor')` and no explicit
   `withholding_tax_applicable` value was set at creation, the system defaults it to `true` and
   requires the creating user to explicitly confirm or override it before the vendor can leave
   `prospect` status — WHT misapplication (over- or under-withholding) is a compliance risk the
   system refuses to leave to an implicit default alone.
6. **A vendor's `balance_due` is always the authoritative sum of open `bills` + open `debit_notes`
   (net) for that vendor**, and must never be manually edited directly; the only way to change it is
   through the event listeners described in Procurement Integration. The database enforces this by
   granting UPDATE on `vendors.balance_due` only to the application's service-account role via column-
   level privileges, not to any interactively-authenticated role, and even the application only
   updates it from within the specific listener classes (enforced by code review / static analysis
   rule, documented here as a system invariant even though it cannot be a pure SQL constraint).
7. **Vendors cannot be paid in a currency for which they have no verified bank account**, unless the
   payment method is `cash`, `check`, or `manual_wire` (methods that do not require a stored bank
   account) — enforced by the Purchasing module's payment-creation validation calling into
   `VendorEligibilityService::assertPayable($vendorId, $currencyCode, $method)`.
8. **Blacklisting cascades a hold to all open documents**, but never auto-voids them. On
   `* → blacklisted`, a listener flags every open `purchase_orders` row for this vendor with
   `on_hold = true` (Purchasing-owned column) via the `purchasing.vendor.blacklisted` domain event,
   requiring a Purchasing Manager to explicitly release or cancel each one — this is intentional
   friction, not an oversight, because the whole point of the hold is to force human review of
   in-flight commitments to a newly-blacklisted counterparty.
9. **Duplicate prevention runs at creation time, not just as a background report.** Every
   `POST /api/v1/purchasing/vendors` call synchronously runs the fuzzy-match check described in
   Duplicate Detection before the row is committed; if a high-confidence match is found (score ≥ 0.90
   on the configured algorithm), creation is blocked with a 409 and the matched vendor's ID returned so
   the caller can route to it instead. Medium-confidence matches (0.70–0.89) allow creation but attach
   a `potential_duplicate_of` flag reviewed asynchronously by the AI Duplicate Detection responsibility
   and surfaced to `purchasing.vendor.merge` permission holders.
10. **Archival requires zero open balance**, as stated in Vendor Lifecycle, and additionally requires
    zero rows in `purchase_orders` with `status IN ('draft','submitted','approved')` — you cannot
    archive a vendor with in-flight purchasing activity even if its AP balance happens to be zero.
11. **A `government` or `international` vendor cannot skip the compliance/sanctions-check approval
    step**, regardless of any "fast track" configuration a company may have enabled for other vendor
    types — this rule is hard-coded in the workflow engine's step definitions, not company-configurable,
    because sanctions screening is a legal obligation, not a business preference.
12. **Payment terms and currency are frozen onto the transactional document at creation.** Changing
    `vendors.default_payment_terms` or `vendors.default_currency_code` never retroactively alters
    already-created `purchase_orders` or `bills` — those documents store their own copies of the
    values they need at creation time, which is why `bills` has its own `payment_terms_days` and
    `currency_code` columns rather than joining to the live vendor row for these values.

# Vendor Approval Workflow

The approval workflow is a configurable, multi-step state machine that gates the `prospect →
approved` transition (and, for bank-account changes, gates `pending_verification → verified`
separately, though that is a narrower single-step workflow described under Vendor Profile /
Bank Accounts). It is implemented as rows in `vendor_approvals` and `vendor_approval_steps`
(see Database Design), driven by a workflow-definition JSONB stored on
`companies.settings->vendor_approval_workflow` so each company can configure its own step sequence
without a schema change.

## Default Step Sequence (used when a company has not customized it)

| Step # | Step name | Required role(s) | Automated pre-check performed | Can be parallel with other steps? |
|---|---|---|---|---|
| 1 | Data Completeness | System (automated) | Legal name, at least one primary contact, at least one registered address, tax registration number (if applicable to `vendor_type`) all present. Fails with a list of missing fields if not. | N/A — always first |
| 2 | Duplicate Check | System (automated) | Runs the Duplicate Detection algorithm (see next section); blocks on high-confidence match, warns on medium-confidence. | N/A — always second |
| 3 | Compliance / Sanctions Screening | Auditor or Compliance Agent (AI, suggest-only) + human sign-off | Mandatory for `government` and `international` vendor types (see Business Rule 11); optional (company-configurable) for others. AI Fraud Detection agent cross-checks name against a configurable sanctions/PEP watchlist source and returns a match confidence; a human with `purchasing.vendor.approve.compliance` must sign off regardless of AI result. | Yes, can run parallel with step 4 |
| 4 | Bank Account Verification | Finance Manager (`purchasing.vendor.bank.verify`) | At least one `vendor_bank_accounts` row exists and reaches `verification_status = 'verified'`. Not required if the vendor will only ever be paid by check/cash (flagged at creation). | Yes, can run parallel with step 3 |
| 5 | Commercial Approval | Purchasing Manager (`purchasing.vendor.approve`) | Reviews commercial terms (payment terms, credit terms, category); for `contractor` type, confirms a `vendor_contracts` row exists and is `active`. | No — waits for 3 and 4 |
| 6 | Financial Approval | Finance Manager (`purchasing.vendor.approve.finance`) | Required only above a configurable risk-score or credit-limit threshold (`companies.settings->vendor_approval_workflow.financial_approval_threshold_score`); reviews the AI Vendor Risk Score and its reasoning. | No — final step |

On completion of the final applicable step, the workflow engine transitions `vendors.status` from
`prospect` to `approved` atomically with the workflow's own `vendor_approvals.status = 'completed'`,
in a single database transaction, and emits `purchasing.vendor.approved`.

## Workflow Semantics

- Each step is a row in `vendor_approval_steps` with `status` (`pending`, `in_progress`, `approved`,
  `rejected`, `skipped`). A step that is company-configured as not applicable to this vendor's type is
  inserted with `status = 'skipped'` and a `skip_reason` for auditability (never silently omitted from
  the row set — every vendor's workflow has a complete, inspectable record of every step even the ones
  that did not apply).
- **Rejection at any step halts the workflow** and returns `vendors.status` to `prospect` (it never
  goes to a separate "rejected" vendor status — a rejected vendor is simply a prospect that needs
  correction and re-submission). The rejecting approver must supply a reason
  (`vendor_approval_steps.rejection_reason`, minimum 10 characters), and a notification fires to the
  vendor's `created_by` user.
- **Re-submission after rejection restarts only the rejected step and any steps after it that had not
  yet completed**; steps already approved before the rejected one are not re-run, to avoid pointless
  repeated data-completeness/duplicate checks — but if the vendor's core identity fields (legal name,
  tax ID, `vendor_type`) were edited during correction, the Data Completeness and Duplicate Check
  steps are always forced to re-run regardless, since those are the steps whose validity depends on
  exactly those fields.
- **Escalation on stall**: if any `in_progress` step has no activity for more than a configurable
  number of days (default 5 business days), a reminder notification fires to the assigned approver and
  their manager (role hierarchy resolved via the foundation `roles` table), and after a second
  configurable period (default 10 business days total) the step is escalated to the next role up
  (e.g., an unactioned Commercial Approval escalates from Purchasing Manager to CFO) so vendor
  onboarding never silently stalls indefinitely.
- **Delegation**: an approver can delegate their pending step to another user holding the same
  permission, recorded as `vendor_approval_steps.delegated_from_user_id` /
  `delegated_to_user_id`, both visible in the audit trail — delegation is not a permission escalation,
  the delegate must independently already hold the required permission.
- **AI-assisted fast track**: for `vendor_type = 'individual'` or `'service_provider'` below a
  configurable low-risk spend threshold (default: vendors expected to be paid less than 1,000 KWD per
  year, inferred from the initiating PO/contract value), company policy may configure steps 5 and 6 to
  collapse into a single combined approval by one Purchasing Manager — but steps 1–4 always run in
  full; the fast track only ever removes a chained *human sign-off*, never an automated safety check.

## Bank Account Verification Sub-Workflow

Distinct from the vendor-level workflow above (already summarized under Vendor Profile), formalized
here for completeness: `POST .../bank-accounts` → `verification_status = 'pending_verification'` →
callback verification performed out-of-band by Finance → `POST .../bank-accounts/{id}/verify` with a
required `verification_method` (`callback_call`, `signed_letter`, `bank_confirmation_letter`,
`micro_deposit`) and `verification_evidence_attachment_id` → `verification_status = 'verified'`. A
`reject` counterpart (`POST .../bank-accounts/{id}/reject`) sets `verification_status =
'verification_failed'` with a required reason and never allows that specific row to be re-submitted
for verification (a rejected bank-detail claim is corrected by creating a **new** row, not by
resurrecting a failed one, so the failure remains a permanent forensic record).

# Vendor Risk Management

Vendor risk is tracked continuously, not just at onboarding. `vendors.risk_score` (INTEGER, 0–100,
higher = riskier) and `vendors.risk_level` (`low`, `medium`, `high`, `critical`, derived from
`risk_score` via configurable thresholds, default: 0–29 low, 30–59 medium, 60–79 high, 80–100
critical) are recomputed by the AI Vendor Risk Score responsibility (see AI Responsibilities) on a
nightly schedule and immediately on any of the following trigger events: bank-account change, new
certificate expiry, blacklist-adjacent status change on a related vendor sharing the same tax ID or
IBAN, a payment dispute recorded, or a significant PO value spike versus historical average.

## Risk Factors

| Factor category | Example signals | Data source |
|---|---|---|
| Financial stability | Years in operation, credit-limit utilization, payment disputes raised by the vendor against us, sudden change in invoiced amounts | `vendors`, `bills`, `vendor_payments` |
| Compliance | Expired/missing tax registration, expired mandatory certificate, sanctions/PEP watchlist match, jurisdiction risk rating for `international` vendors | `vendor_certificates`, `vendor_registration_numbers`, external watchlist feed |
| Concentration | Percentage of total company spend concentrated in this vendor or its corporate group (`parent_company_vendor_id` chain), single-source status for a critical category | Aggregation over `bills`/`purchase_orders` |
| Operational performance | On-time delivery rate, quality-inspection failure rate, responsiveness to RFQs | `goods_receipts`, `quality_inspections` (Purchasing-owned), `rfq_responses` |
| Transactional anomaly | Bank account changed shortly before a large invoice; invoice amount deviates sharply from historical average for this vendor/category; multiple bills submitted just under an approval threshold | `vendor_bank_accounts`, `bills` |
| Relationship history | Frequency of disputes, blacklist history of related entities (shared IBAN or tax ID across different `vendors` rows even under different names — a strong duplicate/fraud signal) | `vendor_status_history`, cross-vendor matching |

## Risk Response Matrix

| Risk level | System behavior |
|---|---|
| Low | No additional friction; standard approval path. |
| Medium | Flagged in vendor list with a badge; no workflow change. |
| High | Financial Approval step (step 6) becomes mandatory even if below the value threshold; a note is surfaced on every new PO/bill for this vendor to the creating user. |
| Critical | New PO/bill creation for this vendor requires an additional real-time confirmation dialog citing the specific risk reasons; a notification fires immediately to Finance Manager and Auditor; the vendor is flagged for manual review within 48 hours (tracked as a `vendor_risk_reviews` task, not detailed further here as it reuses the foundation task/notification primitives). |

Risk scoring reasoning is always stored alongside the score
(`vendors.risk_score_reasoning` JSONB, an array of `{factor, weight, contribution, evidence_ref}`
objects) so that a Finance Manager reviewing a "High" flag can see exactly why, not just the number —
consistent with the platform-wide rule that every AI output carries confidence and reasoning.

# Duplicate Detection

Duplicate vendor records are prevented proactively (at creation) and detected retrospectively
(background job) using a multi-signal matching algorithm, since no single field is reliable alone
(legal names are typo-prone; tax IDs are sometimes mistyped; two genuinely different vendors can share
a bank if one is a payment aggregator).

## Matching Signals and Weights (default configuration, tunable per company)

| Signal | Match type | Weight | Notes |
|---|---|---|---|
| Tax registration number | Exact match (normalized: strip spaces/dashes) | 0.40 | Strongest single signal; an exact match alone yields a 0.90+ combined score. |
| IBAN / account number | Exact match (normalized) | 0.30 | Second strongest; catches "same vendor, different trading name" cases. |
| Legal name | Fuzzy match (Levenshtein distance + phonetic normalization for Arabic/English transliteration variants) | 0.15 | Threshold: similarity ≥ 0.85 contributes full weight, linearly scaled down to 0 at similarity 0.60. |
| Phone / mobile (contacts) | Exact match (normalized to E.164) | 0.08 | |
| Email domain (contacts) | Exact match | 0.05 | Personal email domains (gmail.com, hotmail.com, etc., from a configurable exclusion list) are excluded from this signal entirely, since they are not distinguishing. |
| Registered address (structured) | Fuzzy match on normalized address string | 0.02 | Weakest signal; addresses legitimately repeat (shared office buildings, business centers) far more often than tax IDs or IBANs do. |

Combined score = weighted sum, capped at 1.00. Thresholds: **≥ 0.90 blocks creation** (409, returns
candidate match), **0.70–0.89 allows creation with a flag** for async review, **< 0.70 not flagged**.

## Synchronous Check (at creation)

`POST /api/v1/purchasing/vendors` runs `DuplicateVendorDetectionService::check($payload)` before the
INSERT, querying only within the same `company_id` (cross-tenant duplicate checking is neither
possible nor desired — Company A's vendor list is invisible to Company B by design). The check uses a
trigram (`pg_trgm`) GIN index on `legal_name` for the fuzzy pass and plain B-tree lookups on normalized
`tax_registration_number` for the exact pass, keeping the synchronous check fast enough (target: under
150ms at p95) to not degrade the vendor-creation UX.

## Asynchronous Background Sweep

A nightly job (`ScanForDuplicateVendors`) re-runs the same algorithm across the entire vendor base per
company (catching cases where two vendors were created independently by different users on different
days, each individually scoring below the synchronous block threshold at creation time but which look
more clearly duplicate once, say, a bank account is added to both later). Findings are written to a
`vendor_duplicate_candidates` table (see Database Design) with the computed score and the specific
matching signals that fired, and surfaced to `purchasing.vendor.merge` permission holders as an
actionable list, never auto-merged.

## Merge Workflow

Merging two vendor records (`POST /api/v1/purchasing/vendors/merge`, see API section) is a
Finance-Manager-or-above action that: designates a `survivor_id` and one or more `merged_id`s;
re-points every FK reference (`purchase_orders.vendor_id`, `bills.vendor_id`,
`vendor_payments.vendor_id`, `goods_receipts.vendor_id`, `debit_notes.vendor_id`,
`vendor_contracts.vendor_id`) from the merged vendor(s) to the survivor inside one database
transaction; unions the child collections (contacts, addresses, bank accounts, certificates — with
duplicate child rows themselves de-duplicated using the same signal logic at a stricter threshold);
sums `balance_due` and lifetime counters onto the survivor; and sets the merged vendor's
`status = 'archived'` with `archived_reason = 'merged'` and a `merged_into_vendor_id` pointer (rather
than deleting it), so that historical reports and any external references to the old
`vendor_code` still resolve. The merge action is irreversible via the API (a mis-merge is corrected by
Finance/Engineering via a supervised data-correction procedure, not by an "unmerge" endpoint, because
unwinding a union of child records losslessly is not generally possible once further transactions may
have been posted against the survivor in the interim).

# Database Design

All tables live in PostgreSQL, follow the platform's standard tenant-table convention (`company_id`,
`branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at` present on every
table even where not re-listed for brevity below), and use `NUMERIC(19,4)` for money,
`NUMERIC(18,6)` for exchange rates. Extensions required: `pg_trgm` (fuzzy text search for duplicate
detection), `pgcrypto` (not required here but standard platform-wide), `btree_gin` (composite GIN
indexes combining JSONB and scalar columns where needed).

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;

-- ============================================================
-- ENUM TYPES
-- ============================================================
CREATE TYPE vendor_type_enum AS ENUM (
    'individual', 'company', 'government', 'international',
    'service_provider', 'manufacturer', 'distributor', 'contractor'
);

CREATE TYPE vendor_status_enum AS ENUM (
    'prospect', 'approved', 'active', 'inactive', 'blacklisted', 'archived'
);

CREATE TYPE legal_structure_enum AS ENUM (
    'sole_proprietorship', 'llc', 'wll', 'kscc', 'kscp', 'partnership',
    'branch_of_foreign_company', 'government_entity', 'other'
);

CREATE TYPE payment_terms_enum AS ENUM (
    'net_15', 'net_30', 'net_45', 'net_60', 'net_90', 'due_on_receipt', 'cod', 'custom'
);

CREATE TYPE blacklist_category_enum AS ENUM (
    'fraud', 'sanctions', 'quality', 'contract_breach', 'legal_dispute', 'other'
);

CREATE TYPE contact_type_enum AS ENUM (
    'primary', 'billing', 'sales', 'technical', 'escalation', 'signatory'
);

CREATE TYPE address_type_enum AS ENUM (
    'registered', 'billing', 'shipping', 'factory', 'warehouse', 'other'
);

CREATE TYPE bank_verification_status_enum AS ENUM (
    'unverified', 'pending_verification', 'verified', 'verification_failed', 'flagged'
);

CREATE TYPE bank_verification_method_enum AS ENUM (
    'callback_call', 'signed_letter', 'bank_confirmation_letter', 'micro_deposit'
);

CREATE TYPE certificate_status_enum AS ENUM (
    'valid', 'expiring_soon', 'expired', 'revoked'
);

CREATE TYPE contract_type_enum AS ENUM (
    'framework', 'project', 'nda', 'sla', 'exclusive_supply'
);

CREATE TYPE contract_status_enum AS ENUM (
    'draft', 'active', 'expired', 'terminated', 'superseded'
);

CREATE TYPE risk_level_enum AS ENUM ('low', 'medium', 'high', 'critical');

-- ============================================================
-- vendors  (the vendor master)
-- ============================================================
CREATE TABLE vendors (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    branch_id                   BIGINT NULL REFERENCES branches(id),

    vendor_code                 VARCHAR(40) NOT NULL,
    legal_name                  VARCHAR(255) NOT NULL,
    display_name                VARCHAR(255) NULL,
    name_ar                     VARCHAR(255) NULL,
    vendor_type                 vendor_type_enum NOT NULL,
    status                      vendor_status_enum NOT NULL DEFAULT 'prospect',
    status_reason               TEXT NULL,

    national_id                 VARCHAR(50) NULL,
    passport_number              VARCHAR(50) NULL,

    commercial_registration_number    VARCHAR(100) NULL,
    commercial_registration_expiry    DATE NULL,
    legal_structure              legal_structure_enum NULL,
    industry_sector               VARCHAR(100) NULL,
    year_established              SMALLINT NULL,
    website                       VARCHAR(255) NULL,
    logo_attachment_id             BIGINT NULL REFERENCES attachments(id),
    parent_company_vendor_id       BIGINT NULL REFERENCES vendors(id),

    tax_registration_number        VARCHAR(50) NULL,
    tax_residency_country          CHAR(2) NULL,
    withholding_tax_applicable      BOOLEAN NOT NULL DEFAULT FALSE,
    default_wht_rate                NUMERIC(5,2) NOT NULL DEFAULT 0,
    tax_exemption_certificate_attachment_id BIGINT NULL REFERENCES attachments(id),

    vat_registration_number         VARCHAR(20) NULL,
    vat_registered                  BOOLEAN NOT NULL DEFAULT FALSE,
    vat_registration_certificate_attachment_id BIGINT NULL REFERENCES attachments(id),
    place_of_supply_country         CHAR(2) NULL,

    default_currency_code           CHAR(3) NOT NULL DEFAULT 'KWD',
    supported_currencies            JSONB NOT NULL DEFAULT '[]',
    default_payment_terms           payment_terms_enum NOT NULL DEFAULT 'net_30',
    custom_payment_terms_days       SMALLINT NULL,
    early_payment_discount_pct      NUMERIC(5,2) NULL,
    early_payment_discount_days     SMALLINT NULL,

    credit_limit_amount              NUMERIC(19,4) NULL,
    credit_limit_currency_code       CHAR(3) NULL,
    credit_hold                      BOOLEAN NOT NULL DEFAULT FALSE,

    default_lead_time_days           SMALLINT NULL,
    default_retention_pct            NUMERIC(5,2) NULL,

    balance_due                      NUMERIC(19,4) NOT NULL DEFAULT 0,
    has_credit_balance                BOOLEAN NOT NULL DEFAULT FALSE,
    lifetime_po_count                 INTEGER NOT NULL DEFAULT 0,
    lifetime_po_value                 NUMERIC(19,4) NOT NULL DEFAULT 0,
    lifetime_payment_count            INTEGER NOT NULL DEFAULT 0,
    last_payment_date                 DATE NULL,

    risk_score                        SMALLINT NOT NULL DEFAULT 0 CHECK (risk_score BETWEEN 0 AND 100),
    risk_level                        risk_level_enum NOT NULL DEFAULT 'low',
    risk_score_reasoning               JSONB NOT NULL DEFAULT '[]',
    risk_score_computed_at             TIMESTAMPTZ NULL,

    performance_score_delivery          SMALLINT NULL CHECK (performance_score_delivery BETWEEN 0 AND 100),
    performance_score_quality           SMALLINT NULL CHECK (performance_score_quality BETWEEN 0 AND 100),

    internal_notes                      TEXT NULL,
    tags                                 JSONB NOT NULL DEFAULT '[]',
    custom_fields                        JSONB NOT NULL DEFAULT '{}',

    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,

    CONSTRAINT uq_vendors_company_code UNIQUE (company_id, vendor_code),
    CONSTRAINT chk_vendor_not_own_parent CHECK (parent_company_vendor_id IS NULL OR parent_company_vendor_id <> id),
    CONSTRAINT chk_individual_has_id CHECK (
        vendor_type <> 'individual' OR national_id IS NOT NULL OR passport_number IS NOT NULL
    ),
    CONSTRAINT uq_vendors_company_pk UNIQUE (company_id, id)
);

CREATE INDEX idx_vendors_company_id        ON vendors (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendors_status            ON vendors (company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendors_vendor_type       ON vendors (company_id, vendor_type) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendors_risk_level        ON vendors (company_id, risk_level) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendors_legal_name_trgm   ON vendors USING GIN (legal_name gin_trgm_ops);
CREATE INDEX idx_vendors_tax_reg_number    ON vendors (company_id, tax_registration_number) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendors_tags              ON vendors USING GIN (tags);
CREATE INDEX idx_vendors_custom_fields     ON vendors USING GIN (custom_fields);
CREATE INDEX idx_vendors_parent            ON vendors (parent_company_vendor_id) WHERE parent_company_vendor_id IS NOT NULL;

-- ============================================================
-- vendor_contacts
-- ============================================================
CREATE TABLE vendor_contacts (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    vendor_id             BIGINT NOT NULL,
    full_name             VARCHAR(255) NOT NULL,
    name_ar               VARCHAR(255) NULL,
    job_title              VARCHAR(150) NULL,
    email                  VARCHAR(255) NULL,
    phone                  VARCHAR(30) NULL,
    mobile                 VARCHAR(30) NULL,
    contact_type            contact_type_enum NOT NULL DEFAULT 'primary',
    is_primary              BOOLEAN NOT NULL DEFAULT FALSE,
    preferred_language      CHAR(2) NOT NULL DEFAULT 'en',
    notes                   TEXT NULL,
    created_by BIGINT NULL REFERENCES users(id),
    updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_contacts_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_vendor_contacts_one_primary
    ON vendor_contacts (vendor_id) WHERE is_primary AND deleted_at IS NULL;
CREATE INDEX idx_vendor_contacts_vendor ON vendor_contacts (vendor_id) WHERE deleted_at IS NULL;

-- ============================================================
-- vendor_addresses
-- ============================================================
CREATE TABLE vendor_addresses (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    vendor_id             BIGINT NOT NULL,
    address_type            address_type_enum NOT NULL DEFAULT 'registered',
    address_line1           VARCHAR(255) NOT NULL,
    address_line2           VARCHAR(255) NULL,
    city                    VARCHAR(100) NOT NULL,
    state_province          VARCHAR(100) NULL,
    postal_code              VARCHAR(20) NULL,
    country_code             CHAR(2) NOT NULL,
    latitude                 NUMERIC(9,6) NULL,
    longitude                NUMERIC(9,6) NULL,
    is_primary                BOOLEAN NOT NULL DEFAULT FALSE,
    created_by BIGINT NULL REFERENCES users(id),
    updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_addresses_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_vendor_addresses_one_primary_per_type
    ON vendor_addresses (vendor_id, address_type) WHERE is_primary AND deleted_at IS NULL;
CREATE INDEX idx_vendor_addresses_vendor ON vendor_addresses (vendor_id) WHERE deleted_at IS NULL;

-- ============================================================
-- vendor_bank_accounts
-- ============================================================
CREATE TABLE vendor_bank_accounts (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    vendor_id             BIGINT NOT NULL,
    account_holder_name     VARCHAR(255) NOT NULL,
    bank_name                VARCHAR(255) NOT NULL,
    bank_country              CHAR(2) NOT NULL,
    iban                      VARCHAR(34) NULL,
    swift_bic                 VARCHAR(11) NULL,
    account_number            VARCHAR(50) NULL,
    currency_code              CHAR(3) NOT NULL,
    is_primary                 BOOLEAN NOT NULL DEFAULT FALSE,
    verification_status         bank_verification_status_enum NOT NULL DEFAULT 'unverified',
    verification_method          bank_verification_method_enum NULL,
    verification_evidence_attachment_id BIGINT NULL REFERENCES attachments(id),
    verified_at                   TIMESTAMPTZ NULL,
    verified_by                   BIGINT NULL REFERENCES users(id),
    rejection_reason               TEXT NULL,
    created_by BIGINT NULL REFERENCES users(id),
    updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_bank_accounts_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_bank_account_identifier CHECK (iban IS NOT NULL OR account_number IS NOT NULL)
);

CREATE UNIQUE INDEX uq_vendor_bank_primary_per_currency
    ON vendor_bank_accounts (vendor_id, currency_code) WHERE is_primary AND deleted_at IS NULL;
CREATE INDEX idx_vendor_bank_accounts_vendor ON vendor_bank_accounts (vendor_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendor_bank_accounts_iban ON vendor_bank_accounts (iban) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendor_bank_accounts_verification ON vendor_bank_accounts (verification_status);

-- ============================================================
-- vendor_certificates
-- ============================================================
CREATE TABLE vendor_certificates (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    vendor_id             BIGINT NOT NULL,
    certificate_type         VARCHAR(100) NOT NULL,
    certificate_number        VARCHAR(100) NULL,
    issuing_body               VARCHAR(255) NULL,
    issue_date                 DATE NULL,
    expiry_date                 DATE NULL,
    status                       certificate_status_enum NOT NULL DEFAULT 'valid',
    attachment_id                 BIGINT NULL REFERENCES attachments(id),
    created_by BIGINT NULL REFERENCES users(id),
    updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_certificates_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE
);

CREATE INDEX idx_vendor_certificates_vendor ON vendor_certificates (vendor_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendor_certificates_expiry ON vendor_certificates (expiry_date) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendor_certificates_status ON vendor_certificates (company_id, status);

-- ============================================================
-- vendor_contracts
-- ============================================================
CREATE TABLE vendor_contracts (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    vendor_id             BIGINT NOT NULL,
    contract_number         VARCHAR(100) NOT NULL,
    contract_type             contract_type_enum NOT NULL,
    start_date                 DATE NOT NULL,
    end_date                    DATE NULL,
    auto_renew                   BOOLEAN NOT NULL DEFAULT FALSE,
    renewal_notice_days           SMALLINT NULL,
    contract_value                 NUMERIC(19,4) NULL,
    currency_code                    CHAR(3) NULL,
    retention_pct                    NUMERIC(5,2) NULL,
    payment_milestones                JSONB NOT NULL DEFAULT '[]',
    status                              contract_status_enum NOT NULL DEFAULT 'draft',
    attachment_id                        BIGINT NULL REFERENCES attachments(id),
    created_by BIGINT NULL REFERENCES users(id),
    updated_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_contracts_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE,
    CONSTRAINT uq_vendor_contracts_number UNIQUE (company_id, contract_number)
);

CREATE INDEX idx_vendor_contracts_vendor ON vendor_contracts (vendor_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendor_contracts_status ON vendor_contracts (company_id, status);
CREATE INDEX idx_vendor_contracts_end_date ON vendor_contracts (end_date) WHERE deleted_at IS NULL;

-- ============================================================
-- vendor_registration_numbers
-- ============================================================
CREATE TABLE vendor_registration_numbers (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    vendor_id         BIGINT NOT NULL,
    registration_type    VARCHAR(50) NOT NULL,
    registration_number   VARCHAR(100) NOT NULL,
    issuing_authority      VARCHAR(255) NULL,
    issue_date               DATE NULL,
    expiry_date               DATE NULL,
    attachment_id              BIGINT NULL REFERENCES attachments(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_reg_numbers_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_vendor_reg_numbers_vendor ON vendor_registration_numbers (vendor_id) WHERE deleted_at IS NULL;

-- ============================================================
-- vendor_status_history  (append-only)
-- ============================================================
CREATE TABLE vendor_status_history (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    vendor_id             BIGINT NOT NULL,
    from_status              vendor_status_enum NULL,
    to_status                 vendor_status_enum NOT NULL,
    blacklist_category         blacklist_category_enum NULL,
    reason                       TEXT NULL,
    approval_reference_id         BIGINT NULL REFERENCES vendor_approvals(id),
    actor_id                       BIGINT NULL REFERENCES users(id),
    occurred_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT fk_vendor_status_history_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_vendor_status_history_vendor ON vendor_status_history (vendor_id, occurred_at DESC);

-- ============================================================
-- vendor_approvals  and  vendor_approval_steps
-- ============================================================
CREATE TABLE vendor_approvals (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    vendor_id         BIGINT NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'in_progress'
        CHECK (status IN ('in_progress','completed','rejected')),
    initiated_by         BIGINT NULL REFERENCES users(id),
    initiated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at           TIMESTAMPTZ NULL,

    CONSTRAINT fk_vendor_approvals_vendor FOREIGN KEY (company_id, vendor_id)
        REFERENCES vendors (company_id, id) ON DELETE CASCADE
);
CREATE INDEX idx_vendor_approvals_vendor ON vendor_approvals (vendor_id, initiated_at DESC);

CREATE TABLE vendor_approval_steps (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vendor_approval_id     BIGINT NOT NULL REFERENCES vendor_approvals(id) ON DELETE CASCADE,
    step_number              SMALLINT NOT NULL,
    step_name                 VARCHAR(100) NOT NULL,
    required_permission        VARCHAR(100) NULL,
    status                       VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','in_progress','approved','rejected','skipped')),
    skip_reason                   TEXT NULL,
    rejection_reason                TEXT NULL,
    assigned_to_user_id               BIGINT NULL REFERENCES users(id),
    delegated_from_user_id             BIGINT NULL REFERENCES users(id),
    delegated_to_user_id                BIGINT NULL REFERENCES users(id),
    ai_recommendation                    JSONB NULL,
    acted_by                              BIGINT NULL REFERENCES users(id),
    acted_at                                TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_vendor_approval_step UNIQUE (vendor_approval_id, step_number)
);
CREATE INDEX idx_vendor_approval_steps_approval ON vendor_approval_steps (vendor_approval_id);
CREATE INDEX idx_vendor_approval_steps_assigned ON vendor_approval_steps (assigned_to_user_id, status);

-- ============================================================
-- vendor_duplicate_candidates
-- ============================================================
CREATE TABLE vendor_duplicate_candidates (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    vendor_id_a        BIGINT NOT NULL,
    vendor_id_b        BIGINT NOT NULL,
    match_score          NUMERIC(4,3) NOT NULL,
    matching_signals       JSONB NOT NULL DEFAULT '[]',
    status                   VARCHAR(20) NOT NULL DEFAULT 'pending_review'
        CHECK (status IN ('pending_review','confirmed_duplicate','dismissed','merged')),
    reviewed_by                BIGINT NULL REFERENCES users(id),
    reviewed_at                  TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_dup_candidates_order CHECK (vendor_id_a < vendor_id_b),
    CONSTRAINT uq_vendor_duplicate_pair UNIQUE (vendor_id_a, vendor_id_b)
);
CREATE INDEX idx_vendor_duplicate_candidates_company ON vendor_duplicate_candidates (company_id, status);

-- ============================================================
-- vendor_open_items  (materialized read-model for aging)
-- ============================================================
CREATE MATERIALIZED VIEW vendor_open_items AS
SELECT
    b.company_id,
    b.vendor_id,
    b.id            AS document_id,
    'bill'          AS document_type,
    b.bill_number   AS document_number,
    b.bill_date     AS document_date,
    b.due_date,
    b.currency_code,
    b.total_amount,
    b.paid_amount,
    (b.total_amount - b.paid_amount) AS open_amount,
    b.base_amount * (1 - (b.paid_amount / GREATEST(b.total_amount, 0.0001))) AS open_base_amount
FROM bills b
WHERE b.status = 'posted' AND b.total_amount > b.paid_amount AND b.deleted_at IS NULL
UNION ALL
SELECT
    d.company_id, d.vendor_id, d.id, 'debit_note', d.debit_note_number, d.debit_note_date,
    NULL, d.currency_code, -d.total_amount, -d.applied_amount,
    -(d.total_amount - d.applied_amount), -d.base_amount
FROM debit_notes d
WHERE d.status = 'posted' AND d.total_amount > d.applied_amount AND d.deleted_at IS NULL;

CREATE UNIQUE INDEX uq_vendor_open_items ON vendor_open_items (document_type, document_id);
CREATE INDEX idx_vendor_open_items_vendor ON vendor_open_items (vendor_id);
-- Refreshed concurrently every 15 minutes and on-demand after any bill/payment/debit-note posting event.

-- ============================================================
-- Trigger: prevent hard delete of vendors with transactional history
-- ============================================================
CREATE OR REPLACE FUNCTION prevent_vendor_hard_delete() RETURNS TRIGGER AS $$
DECLARE
    ref_count INTEGER;
BEGIN
    IF OLD.status <> 'prospect' THEN
        RAISE EXCEPTION 'Vendor % cannot be hard-deleted: status is %, only prospect vendors qualify', OLD.id, OLD.status;
    END IF;

    SELECT
        (SELECT count(*) FROM purchase_orders WHERE vendor_id = OLD.id)
      + (SELECT count(*) FROM bills WHERE vendor_id = OLD.id)
      + (SELECT count(*) FROM vendor_payments WHERE vendor_id = OLD.id)
      + (SELECT count(*) FROM goods_receipts WHERE vendor_id = OLD.id)
      + (SELECT count(*) FROM debit_notes WHERE vendor_id = OLD.id)
    INTO ref_count;

    IF ref_count > 0 THEN
        RAISE EXCEPTION 'Vendor % cannot be hard-deleted: % linked transactional records exist', OLD.id, ref_count;
    END IF;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_vendor_hard_delete
    BEFORE DELETE ON vendors
    FOR EACH ROW EXECUTE FUNCTION prevent_vendor_hard_delete();

-- ============================================================
-- Trigger: enforce verified bank account on vendor_payments
-- (defined here since it depends on this module's verification_status;
--  the vendor_payments table itself is owned by Purchasing/Payments)
-- ============================================================
CREATE OR REPLACE FUNCTION enforce_verified_bank_account() RETURNS TRIGGER AS $$
DECLARE
    v_status bank_verification_status_enum;
BEGIN
    IF NEW.vendor_bank_account_id IS NOT NULL THEN
        SELECT verification_status INTO v_status
        FROM vendor_bank_accounts WHERE id = NEW.vendor_bank_account_id;

        IF v_status IS DISTINCT FROM 'verified' THEN
            RAISE EXCEPTION 'Cannot pay vendor using bank account % — verification_status is %, must be verified',
                NEW.vendor_bank_account_id, v_status;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_enforce_verified_bank_account
    BEFORE INSERT OR UPDATE ON vendor_payments
    FOR EACH ROW EXECUTE FUNCTION enforce_verified_bank_account();

-- ============================================================
-- Trigger: maintain updated_at
-- ============================================================
CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_vendors_touch BEFORE UPDATE ON vendors
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER trg_vendor_contacts_touch BEFORE UPDATE ON vendor_contacts
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER trg_vendor_addresses_touch BEFORE UPDATE ON vendor_addresses
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER trg_vendor_bank_accounts_touch BEFORE UPDATE ON vendor_bank_accounts
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER trg_vendor_certificates_touch BEFORE UPDATE ON vendor_certificates
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER trg_vendor_contracts_touch BEFORE UPDATE ON vendor_contracts
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
```

## Relationships Summary

| Table | Relationship |
|---|---|
| `vendors` | 1-to-many with `vendor_contacts`, `vendor_addresses`, `vendor_bank_accounts`, `vendor_certificates`, `vendor_contracts`, `vendor_registration_numbers`, `vendor_status_history`, `vendor_approvals`. Self-referencing 1-to-many via `parent_company_vendor_id`. Referenced by `purchase_orders`, `bills`, `vendor_payments`, `goods_receipts`, `debit_notes` (all owned by Purchasing). |
| `vendor_approvals` | 1-to-many with `vendor_approval_steps`. |
| `vendor_bank_accounts` | Referenced by `vendor_payments.vendor_bank_account_id` (owned by Purchasing, constrained by the verification trigger above). |
| `vendor_duplicate_candidates` | Many-to-many self-reference on `vendors` via `vendor_id_a`/`vendor_id_b` (ordered pair, one row per unordered pair, enforced by the CHECK+UNIQUE combination). |

## Versioning

Column-level history for `vendors` and its children is captured through the platform-wide
`audit_logs` table (old value/new value per changed column, JSON diff) rather than a bespoke
per-table history table, with the one deliberate exception of `vendor_status_history`, which is kept
as its own dedicated append-only table because status transitions are queried and reported on far more
heavily than generic field edits (aging-vendor reports, time-in-status analytics, compliance
attestation that blacklist decisions were never silently reversed) and deserve a purpose-built,
denormalized, fast-to-query structure rather than being reconstructed from generic audit-log diffs
every time.

# AI Responsibilities

All AI behavior in this module runs through the FastAPI AI layer, which never writes to PostgreSQL
directly. Every AI output is persisted by the AI layer calling back into the Laravel API (the same
authenticated, permission-checked path a human user would use), and every AI output carries a
`confidence` (0.00–1.00), a `reasoning` narrative, and `supporting_documents`/`evidence_refs`. The
relevant agents from the platform's shared roster for this module are: **General Accountant**,
**Auditor**, **Fraud Detection**, **Compliance Agent**, **Document AI / OCR Agent**, and
**Approval Assistant**. Each responsibility below states which agent performs it, its autonomy level,
and how confidence is handled.

## Vendor Risk Score

- **Agent**: Auditor (primary), Fraud Detection (contributes the transactional-anomaly sub-signals).
- **Autonomy**: Auto-compute, suggest-only for action. The score itself is written automatically
  (nightly job + trigger events, see Vendor Risk Management), but any consequence gated on the score
  (mandatory extra approval step, blocking a PO) is enforced by the Business Rules/workflow engine —
  the AI does not itself block anything; it produces the number and reasoning that the deterministic
  workflow engine then acts on.
- **Inputs**: `vendors.*`, `vendor_certificates`, `vendor_registration_numbers`, aggregates over
  `bills`, `purchase_orders`, `vendor_payments`, `vendor_status_history`, and an external
  sanctions/PEP watchlist feed (name + tax ID fuzzy match).
- **Output**: `risk_score`, `risk_level`, `risk_score_reasoning` JSONB array of
  `{factor, weight, contribution, evidence_ref}`.
- **Confidence handling**: the overall risk score is not itself a probability, but each contributing
  factor that comes from a fuzzy/ML signal (e.g., sanctions-name-match) carries its own confidence; a
  sanctions match below 0.60 confidence is recorded but not weighted into the score at full strength
  (dampened proportionally) to avoid false-positive-driven risk inflation, while a match above 0.85
  confidence always escalates `risk_level` to at least `high` regardless of the rest of the score.

## Duplicate Detection

- **Agent**: General Accountant (runs the deterministic scoring described in Duplicate Detection) with
  Document AI assisting when OCR-extracted fields (e.g., a tax ID read off an uploaded certificate)
  are used as an additional matching signal.
- **Autonomy**: Suggest-only above the block threshold is actually a **hard block** (see Business Rule
  9 — the ≥0.90 threshold is enforced deterministically, not an AI judgment call the user can dismiss
  in the moment), but everything in the 0.70–0.89 band is genuinely suggest-only: a human with
  `purchasing.vendor.merge` reviews and decides confirm/dismiss.
- **Inputs**: candidate vendor payload vs. existing `vendors` rows (and their children) within the
  same `company_id`.
- **Output**: `vendor_duplicate_candidates` rows with `match_score` and `matching_signals`.
- **Confidence handling**: `match_score` IS the confidence; reasoning is the itemized signal list
  (e.g., `[{"signal":"tax_registration_number","match":"exact","contribution":0.40}, {"signal":
  "legal_name","match":"fuzzy","similarity":0.88,"contribution":0.132}]`).

## Fraud Detection

- **Agent**: Fraud Detection.
- **Autonomy**: Suggest-only, always. Fraud Detection never blocks a transaction by itself; it raises
  a flag that requires a human with `purchasing.vendor.risk.review` or `finance.review` to act on
  before the flagged payment proceeds (the block, if any, is implemented as a workflow hold triggered
  by the flag, not by the AI directly vetoing the payment API call).
- **Watched patterns specific to vendors**: (1) a bank-account change on an `active` vendor followed
  within 72 hours by a bill or payment request significantly above that vendor's historical average
  (a classic BEC — business email compromise — pattern); (2) two vendors sharing the same IBAN or the
  same tax registration number under different legal names (feeds directly into Duplicate Detection
  but is also raised independently as a fraud signal, since a deliberate near-duplicate created to
  siphon payments is a fraud pattern, not just a data-quality issue); (3) a vendor contact email
  domain that was registered (WHOIS lookup, cached) less than 90 days before the bank-change request —
  a hallmark of spoofed-domain BEC attacks; (4) invoice amounts clustering just under an approval
  threshold across multiple bills in a short window ("threshold shopping").
- **Output**: a `fraud_alert` record (foundation notification + a `vendor_fraud_flags` table
  scoped to this module, `{vendor_id, pattern_type, confidence, evidence_refs, status}`), surfaced
  immediately (not batched) to Finance Manager, CFO, and Auditor roles.
- **Confidence handling**: alerts below 0.50 confidence are logged but not actively notified (visible
  only in a review queue); 0.50–0.79 trigger a standard notification; ≥0.80 trigger an urgent
  notification plus an automatic workflow hold on the specific bank account
  (`verification_status → 'flagged'`, blocking its use until a human clears the flag).

## Payment Prediction

- **Agent**: General Accountant, with Treasury Manager (platform-shared agent) consuming its output
  for cash-flow forecasting outside this module's boundary.
- **Autonomy**: Suggest-only.
- **Inputs**: `vendor_open_items`, `vendors.default_payment_terms`, `early_payment_discount_pct`/
  `_days`, historical `vendor_payments` timing patterns per vendor (does this vendor's team tend to
  chase overdue invoices aggressively, meaning late payment carries relationship risk, versus a
  vendor that has never complained about a 10-day-late payment).
- **Output**: a ranked list (`GET /api/v1/purchasing/vendors/payment-recommendations`) of which open
  bills to pay this week, factoring available cash (read from the Banking module, out of scope here),
  discount capture opportunity, and relationship-risk-weighted due dates.
- **Confidence handling**: each recommendation carries a `confidence` reflecting forecast certainty
  (e.g., a discount-capture recommendation with a known fixed discount window has confidence 1.00 on
  the *arithmetic*, but the overall recommendation confidence also folds in the certainty that
  sufficient cash will actually be available, which is lower and drives the blended figure).

## Supplier Performance

- **Agent**: General Accountant / Auditor, jointly (Auditor validates the delivery/quality
  aggregates against source Purchasing/Inventory documents before they are trusted for scoring).
- **Autonomy**: Auto-compute (the score), suggest-only (any consequence — e.g., suggesting a
  vendor be moved from "preferred" to under-review).
- **Inputs**: `goods_receipts.received_date` vs. `purchase_orders.expected_delivery_date`,
  `quality_inspections` pass/fail rates, RFQ response times, dispute counts.
- **Output**: `vendors.performance_score_delivery`, `vendors.performance_score_quality`, and a
  detailed `GET /api/v1/purchasing/vendors/{id}/performance` breakdown.
- **Confidence handling**: scores below a minimum sample size (fewer than 3 completed POs) are marked
  `insufficient_data: true` rather than assigning a potentially misleading score from too few data
  points.

## Contract Analysis

- **Agent**: Document AI / OCR Agent extracts; General Accountant structures; Auditor flags
  compliance-relevant clauses.
- **Autonomy**: Suggest-only — extracted terms are proposed as structured `vendor_contracts` field
  values (start/end date, value, milestones, penalty clauses) that a human with
  `purchasing.vendor.contracts.manage` must review and confirm before they become the authoritative
  values used by the milestone-billing validation described in Procurement Integration.
- **Inputs**: the uploaded contract PDF/DOCX attachment.
- **Output**: a structured extraction proposal
  (`{start_date, end_date, contract_value, currency_code, payment_milestones[], renewal_notice_days,
  penalty_clauses[], confidence}`) attached to the `vendor_contracts` row as
  `ai_extraction_proposal` JSONB pending human confirmation.
- **Confidence handling**: per-field confidence (date extraction from a clean, native-text PDF is
  typically ≥0.95; extraction from a scanned/OCR'd document is flagged with lower confidence,
  typically 0.70–0.85, and the UI visually distinguishes low-confidence fields for extra scrutiny
  before human confirmation).

## Smart Suggestions

- **Agent**: General Accountant / Approval Assistant.
- **Autonomy**: Suggest-only, purely advisory UI surface — no data mutation.
- **Examples in this module**: suggesting the most appropriate existing vendor when a Purchasing
  Employee starts typing a new PO and a similar-enough vendor already exists (feeds off the same
  fuzzy-matching engine as Duplicate Detection, run proactively as autocomplete rather than reactively
  as a block); suggesting which `tags` to apply to a new vendor based on its `industry_sector` and
  the categories of goods/services on its first PO; suggesting supplier consolidation ("3 vendors in
  category 'office supplies' each received fewer than 2 POs this year — consider consolidating spend
  with your top-performing vendor in this category for better pricing leverage"); pre-filling
  `default_wht_rate` based on `vendor_type` + `tax_residency_country` + any applicable tax treaty
  table (a reference table maintained outside this module).
- **Confidence handling**: every suggestion surfaces its confidence inline in the UI tooltip; below
  0.50 confidence, suggestions are suppressed entirely rather than shown, to avoid suggestion fatigue
  eroding trust in the higher-confidence ones.

# Reports

All reports are company-scoped, respect `branch_id` filtering where applicable, support export to
CSV/XLSX/PDF, and are generated through the platform's shared `report_definitions` /
`report_runs` mechanism (Reports module), with this module contributing the specific report
definitions below.

## Vendor Aging

Buckets open items (from the `vendor_open_items` materialized view) by days-past-due:
Current, 1–30, 31–60, 61–90, 90+, using `document_date`/`due_date` relative to the report's
as-of date (defaults to today, can be run historically for month-end close). Grouped by vendor,
subtotaled per vendor and grand-totaled per company, in both transaction currency and base currency.
Filters: vendor status, vendor type, risk level, tag. This is the report Finance reconciles against
the AP control account balance in the GL every period-close.

## Outstanding Payables

A simpler, non-bucketed view of every vendor's current `balance_due`, sortable by amount descending,
with drill-through to the underlying open `bills`/`debit_notes`. Includes a `credit_hold` and
`blacklisted` indicator column so Finance immediately sees which large balances belong to vendors
under a hold.

## Top Vendors

Ranks vendors by `lifetime_po_value` or by a selected date-range spend total, with percentage of total
company spend and cumulative percentage (Pareto view — highlighting the classic "20% of vendors are
80% of spend" concentration), feeding directly into the AI Vendor Risk Score's concentration-risk
factor and into sourcing-strategy discussions.

## Vendor Performance

Combines `performance_score_delivery`, `performance_score_quality`, on-time-payment-to-vendor rate,
and dispute count into a single scorecard per vendor, with trend over the last 4 quarters (sparkline
in the UI, tabular in export). Supports a "compare vendors in the same category" view for sourcing
decisions.

## Vendor Spending

Time-series (monthly/quarterly/yearly) of spend per vendor, filterable by `industry_sector`, `tags`,
`vendor_type`, cost center, project, and department — the dimension columns are pulled from
`journal_lines`/source documents per the Accounting dimensions addendum, joined through
`purchase_orders`/`bills`.

## Purchase Analysis

Cross-tabulates spend by vendor × product category × period, highlighting price variance for the same
product/category across different vendors (a negotiation-support report — "we pay Vendor A 12% more
than Vendor B for functionally the same category of goods").

## Payment History

Full chronological list of `vendor_payments` for a selected vendor or vendor set, with method,
bank account used, reference number, and linked bill(s), primarily used for vendor reconciliation
calls ("can you confirm you received our payment of X on date Y") and for audit sampling.

## Vendor Risk Report

Company-wide list of vendors sorted by `risk_score` descending, with the full `risk_score_reasoning`
breakdown expandable per row, filterable to `risk_level IN ('high','critical')` for a focused
Auditor/CFO review list, and includes any open `vendor_fraud_flags` and expiring
`vendor_certificates` inline so a single report answers "which vendors need attention this month."

# API

All endpoints are under `/api/v1/purchasing/vendors` (vendors are conceptually part of the
Purchasing/AP domain even though this module owns the underlying tables), require a Sanctum/JWT
bearer token, are scoped to the company via the `X-Company-Id` header, and return the standard
response envelope defined in the platform-wide API conventions.

## Endpoint Table

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/purchasing/vendors` | `purchasing.vendor.read` | List/search vendors, paginated, filterable by status/type/risk_level/tags/custom_fields. |
| POST | `/api/v1/purchasing/vendors` | `purchasing.vendor.create` | Create a vendor (runs synchronous duplicate check). |
| GET | `/api/v1/purchasing/vendors/{id}` | `purchasing.vendor.read` | Full vendor profile including children (contacts, addresses, bank accounts, certificates, contracts). |
| PUT | `/api/v1/purchasing/vendors/{id}` | `purchasing.vendor.update` | Update core profile fields; triggers re-validation if `vendor_type` changed. |
| DELETE | `/api/v1/purchasing/vendors/{id}` | `purchasing.vendor.delete` | Hard-delete; only succeeds if `status='prospect'` and zero linked documents (else 409). |
| POST | `/api/v1/purchasing/vendors/{id}/submit-for-approval` | `purchasing.vendor.submit` | Initiates the Vendor Approval Workflow. |
| POST | `/api/v1/purchasing/vendors/{id}/approval-steps/{stepId}/approve` | per-step (`required_permission`) | Approves one workflow step. |
| POST | `/api/v1/purchasing/vendors/{id}/approval-steps/{stepId}/reject` | per-step | Rejects a step and reason; halts workflow. |
| POST | `/api/v1/purchasing/vendors/{id}/activate` | `purchasing.vendor.activate` | Manual `approved → active`. |
| POST | `/api/v1/purchasing/vendors/{id}/deactivate` | `purchasing.vendor.deactivate` | `active → inactive`, reason required. |
| POST | `/api/v1/purchasing/vendors/{id}/blacklist` | `purchasing.vendor.blacklist` | Any state → `blacklisted`, reason + category required. |
| POST | `/api/v1/purchasing/vendors/{id}/unblacklist` | `purchasing.vendor.unblacklist` | `blacklisted → inactive`, reason required. |
| POST | `/api/v1/purchasing/vendors/{id}/archive` | `purchasing.vendor.archive` | Archives; 409 if open balance/open POs unless `.force`. |
| POST | `/api/v1/purchasing/vendors/{id}/unarchive` | `purchasing.vendor.unarchive` | `archived → inactive`. |
| POST | `/api/v1/purchasing/vendors/merge` | `purchasing.vendor.merge` | Merge N vendors into a survivor. |
| GET | `/api/v1/purchasing/vendors/duplicate-candidates` | `purchasing.vendor.merge` | List pending duplicate-candidate pairs. |
| POST | `/api/v1/purchasing/vendors/duplicate-candidates/{id}/dismiss` | `purchasing.vendor.merge` | Dismiss a candidate pair as not-a-duplicate. |
| POST | `/api/v1/purchasing/vendors/{id}/contacts` | `purchasing.vendor.update` | Add a contact. |
| PUT/DELETE | `/api/v1/purchasing/vendors/{id}/contacts/{contactId}` | `purchasing.vendor.update` | Update/soft-delete a contact. |
| POST | `/api/v1/purchasing/vendors/{id}/addresses` | `purchasing.vendor.update` | Add an address. |
| POST | `/api/v1/purchasing/vendors/{id}/bank-accounts` | `purchasing.vendor.update` | Add a bank account (`pending_verification`). |
| POST | `/api/v1/purchasing/vendors/{id}/bank-accounts/{bankId}/verify` | `purchasing.vendor.bank.verify` | Verify a bank account. |
| POST | `/api/v1/purchasing/vendors/{id}/bank-accounts/{bankId}/reject` | `purchasing.vendor.bank.verify` | Reject a bank account verification. |
| POST | `/api/v1/purchasing/vendors/{id}/certificates` | `purchasing.vendor.certificates.manage` | Add a certificate. |
| POST | `/api/v1/purchasing/vendors/{id}/contracts` | `purchasing.vendor.contracts.manage` | Add a contract (triggers AI Contract Analysis extraction). |
| GET | `/api/v1/purchasing/vendors/{id}/performance` | `purchasing.vendor.read` | Performance scorecard. |
| GET | `/api/v1/purchasing/vendors/{id}/audit-log` | `purchasing.vendor.read` | Full change history. |
| GET | `/api/v1/purchasing/vendors/import/template` | `purchasing.vendor.import` | Download the bulk-import CSV template. |
| POST | `/api/v1/purchasing/vendors/import` | `purchasing.vendor.import` | Bulk import (async job, returns a job ID). |
| GET | `/api/v1/purchasing/vendors/import/{jobId}` | `purchasing.vendor.import` | Poll import job status/results. |
| GET | `/api/v1/purchasing/vendors/export` | `purchasing.vendor.export` | Bulk export (async for large datasets). |
| POST | `/api/v1/purchasing/vendors/bulk` | `purchasing.vendor.update` | Bulk operation (tag, deactivate, reassign) on a filtered set. |

## Create — Request/Response Example

```json
POST /api/v1/purchasing/vendors
{
  "vendor_type": "company",
  "legal_name": "Al-Salam Trading Co. W.L.L.",
  "name_ar": "شركة السلام التجارية ذ.م.م",
  "commercial_registration_number": "123456",
  "tax_registration_number": "TX-998877",
  "vat_registered": false,
  "default_currency_code": "KWD",
  "default_payment_terms": "net_30",
  "primary_contact": {
    "full_name": "Fahad Al-Mutairi",
    "email": "fahad@alsalamtrading.com",
    "phone": "+96522334455",
    "contact_type": "primary",
    "is_primary": true
  },
  "registered_address": {
    "address_type": "registered",
    "address_line1": "Building 12, Street 40, Sharq",
    "city": "Kuwait City",
    "country_code": "KW",
    "is_primary": true
  },
  "tags": ["preferred", "office-supplies"]
}
```

```json
{
  "success": true,
  "data": {
    "id": 4821,
    "vendor_code": "V-0042-004821",
    "legal_name": "Al-Salam Trading Co. W.L.L.",
    "vendor_type": "company",
    "status": "prospect",
    "risk_score": 12,
    "risk_level": "low",
    "created_at": "2026-07-16T09:14:02Z"
  },
  "message": "Vendor created successfully and is pending approval submission.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3f9d2a10-1e77-4b8a-9a2e-71c4f0d8ab21",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

## Duplicate-Block Error Example

```json
{
  "success": false,
  "data": null,
  "message": "A vendor matching this profile already exists.",
  "errors": [
    {
      "code": "vendor.duplicate_high_confidence",
      "match_score": 0.94,
      "matched_vendor_id": 3190,
      "matched_vendor_code": "V-0042-003190",
      "matching_signals": [
        {"signal": "tax_registration_number", "match": "exact", "contribution": 0.40},
        {"signal": "iban", "match": "exact", "contribution": 0.30},
        {"signal": "legal_name", "match": "fuzzy", "similarity": 0.91, "contribution": 0.1365}
      ]
    }
  ],
  "meta": { "pagination": null },
  "request_id": "8b1e6f44-2c3a-4e91-9d0a-5f6a1c2e9b77",
  "timestamp": "2026-07-16T09:15:40Z"
}
```

## Update — Request/Response Example

```json
PUT /api/v1/purchasing/vendors/4821
{ "default_payment_terms": "net_45", "credit_limit_amount": 15000.0000, "credit_limit_currency_code": "KWD" }
```

```json
{
  "success": true,
  "data": { "id": 4821, "default_payment_terms": "net_45", "credit_limit_amount": "15000.0000", "updated_at": "2026-07-20T11:02:15Z" },
  "message": "Vendor updated successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a2b3c4d-5e6f-7890-abcd-ef1234567890",
  "timestamp": "2026-07-20T11:02:15Z"
}
```

## Merge — Request/Response Example

```json
POST /api/v1/purchasing/vendors/merge
{ "survivor_id": 3190, "merged_ids": [4821], "reason": "Confirmed duplicate — same tax ID and IBAN, created under a trading name variant." }
```

```json
{
  "success": true,
  "data": {
    "survivor_id": 3190,
    "merged_ids": [4821],
    "reassigned_documents": { "purchase_orders": 0, "bills": 0, "vendor_payments": 0, "goods_receipts": 0, "debit_notes": 0 },
    "survivor_balance_due": "0.0000"
  },
  "message": "Vendors merged successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9c8b7a65-4321-fedc-ba98-765432109876",
  "timestamp": "2026-07-20T11:10:00Z"
}
```

## Search — Request/Response Example

```json
GET /api/v1/purchasing/vendors?search=al-salam&status=active&risk_level=low,medium&sort=-lifetime_po_value&page=1&per_page=25
```

```json
{
  "success": true,
  "data": [
    { "id": 3190, "vendor_code": "V-0042-003190", "legal_name": "Al-Salam Trading Co. W.L.L.", "status": "active", "risk_level": "low", "balance_due": "2450.5000", "lifetime_po_value": "184200.0000" }
  ],
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "de305d54-75b4-431b-adb2-eb6b9e546013",
  "timestamp": "2026-07-20T11:12:44Z"
}
```

## Import / Export / Bulk Operations

Import accepts CSV/XLSX matching the downloadable template, processed asynchronously
(`purchasing.vendor.import.completed` webhook on finish) with per-row validation results (including
per-row duplicate-check outcomes) returned in the job-status payload — no row is silently skipped;
every row's outcome (`created`, `updated`, `skipped_duplicate`, `failed_validation`) is reported.
Export supports the same filter parameters as the list endpoint and streams CSV/XLSX for datasets
under 10,000 rows synchronously, async (job + download link) above that. Bulk operations
(`POST /api/v1/purchasing/vendors/bulk`) accept a filter (or explicit ID list, capped at 500 per call)
plus an `operation` (`add_tag`, `remove_tag`, `deactivate`, `set_custom_field`) and apply it inside a
single transaction per 100-row chunk, returning a per-chunk success/failure summary rather than
failing the entire batch on one bad row.

# Permissions

| Permission key | Owner | Admin | Purchasing Manager | Purchasing Employee | Finance Manager | Accountant | Auditor | AI Agent |
|---|---|---|---|---|---|---|---|---|
| `purchasing.vendor.read` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ (read-only, scoped to active company) |
| `purchasing.vendor.create` | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | – |
| `purchasing.vendor.update` | ✓ | ✓ | ✓ | ✓ (own-created only, configurable) | ✓ | – | – | – (proposes only) |
| `purchasing.vendor.delete` | ✓ | ✓ | – | – | – | – | – | – |
| `purchasing.vendor.submit` | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | – |
| `purchasing.vendor.approve` (Commercial step) | ✓ | ✓ | ✓ | – | – | – | – | – |
| `purchasing.vendor.approve.compliance` | ✓ | ✓ | – | – | – | – | ✓ | – |
| `purchasing.vendor.approve.finance` | ✓ | ✓ | – | – | ✓ | – | – | – |
| `purchasing.vendor.activate` | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| `purchasing.vendor.deactivate` | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| `purchasing.vendor.blacklist` | ✓ | ✓ | – | – | ✓ | – | – | – |
| `purchasing.vendor.unblacklist` | ✓ | – | – | – | – | – | – | – |
| `purchasing.vendor.archive` | ✓ | ✓ | – | – | ✓ | – | – | – |
| `purchasing.vendor.archive.force` | ✓ | – | – | – | ✓ | – | – | – |
| `purchasing.vendor.unarchive` | ✓ | ✓ | – | – | – | – | – | – |
| `purchasing.vendor.merge` | ✓ | ✓ | – | – | ✓ | – | – | – |
| `purchasing.vendor.bank.verify` | ✓ | ✓ | – | – | ✓ | – | – | – |
| `purchasing.vendor.certificates.manage` | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | – |
| `purchasing.vendor.contracts.manage` | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| `purchasing.vendor.reclassify` | ✓ | ✓ | ✓ | – | ✓ | – | – | – |
| `purchasing.vendor.risk.review` | ✓ | ✓ | – | – | ✓ | – | ✓ | – |
| `purchasing.vendor.import` | ✓ | ✓ | ✓ | – | – | – | – | – |
| `purchasing.vendor.export` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| `purchasing.vendor.audit.read` | ✓ | ✓ | – | – | ✓ | – | ✓ | – |

The **Read Only** and **External Auditor** default roles referenced in the platform-wide roster map
onto `purchasing.vendor.read` and `purchasing.vendor.export` only (External Auditor additionally gets
`purchasing.vendor.audit.read`), never any mutating permission. **Senior Accountant** inherits
`Accountant`'s row above plus `purchasing.vendor.risk.review` (view, not action). Sensitive operations
— `blacklist`, `unblacklist`, `merge`, `bank.verify`, `archive.force` — are deliberately restricted to
Finance Manager and above per the platform-wide rule that sensitive financial-data operations always
require a human approval chain; none of them are ever grantable to the AI Agent identity.

# Notifications

Notifications are delivered through the foundation `notifications` table and its existing
in-app/email/push channels, with Vendor Management contributing the following domain-event-triggered
notification types (all support per-user channel preference, respecting each user's configured
delivery channels):

| Event | Recipients | Urgency | Content |
|---|---|---|---|
| `vendor.approval.step_assigned` | Assigned approver (or role if unassigned) | Normal | "Vendor {legal_name} is awaiting your {step_name} approval." |
| `vendor.approval.step_stalled` | Assigned approver + their manager | Elevated | "Approval step {step_name} for {legal_name} has been pending {N} days." |
| `vendor.approval.rejected` | Vendor's `created_by` user | Normal | "Vendor {legal_name} was rejected at step {step_name}: {rejection_reason}." |
| `vendor.approved` | `created_by`, Purchasing Manager role | Normal | "Vendor {legal_name} is now approved and can receive purchase orders." |
| `vendor.bank_account.change_requested` | Finance Manager role | Elevated | "A new bank account was submitted for {legal_name} and requires callback verification before it can be used for payment." |
| `vendor.bank_account.verified` | `created_by` of the change, Finance Manager | Normal | "Bank account for {legal_name} has been verified." |
| `vendor.bank_account.flagged` | Finance Manager, CFO, Auditor | Urgent | "Bank account for {legal_name} was automatically flagged: {fraud_alert.pattern_type} (confidence {confidence})." |
| `vendor.blacklisted` | Finance Manager, Auditor, and any Purchasing Employee with an open PO for this vendor | Urgent | "{legal_name} has been blacklisted ({blacklist_category}). {N} open purchase order(s) placed on hold." |
| `vendor.blacklisted.open_bills_exist` | Finance Manager, Auditor | Urgent | "{legal_name} was blacklisted with {N} open unpaid bill(s) totaling {amount} {currency}." |
| `vendor.certificate.expiring_soon` | Purchasing Employee/Manager assigned to the vendor, vendor's own contact (external) | Normal | "Certificate {certificate_type} for {legal_name} expires in {days_remaining} days." |
| `vendor.certificate.expired` | Same as above, plus Compliance/Auditor | Elevated | "Certificate {certificate_type} for {legal_name} has expired; new POs are blocked pending renewal." |
| `vendor.duplicate_candidate.detected` | `purchasing.vendor.merge` holders | Normal | "Possible duplicate detected: {legal_name} and {other_legal_name}, match score {score}." |
| `vendor.risk.escalated` | Finance Manager, CFO, Auditor | Elevated/Urgent (per risk level) | "{legal_name}'s risk level changed from {old} to {new}: {top_reasoning_factor}." |
| `vendor.reconciliation.mismatch` | Finance Manager, Auditor | Urgent | "Vendor balance total for company does not match AP control account by {delta} {base_currency}." |
| `vendor.credit_hold.active_on_new_po` | Purchasing Employee creating the PO | Normal (inline warning, not a push notification) | "{legal_name} is on credit hold." |

Urgent-tier notifications are delivered on every enabled channel simultaneously regardless of user
digest preferences (they bypass batching), consistent with the platform-wide treatment of
security/fraud-relevant alerts as always-immediate.

# Security

- **Tenant isolation** is enforced at three layers, not one: (1) every query in the Repository layer
  is scoped by `company_id` taken from the authenticated request context, never from client-supplied
  input; (2) the composite foreign keys (`(company_id, id)` / `(company_id, vendor_id)`) make
  cross-tenant references structurally impossible at the database level; (3) Postgres Row-Level
  Security policies are enabled on `vendors` and every child table as a defense-in-depth layer, keyed
  to a session-local `app.current_company_id` setting that Laravel sets at the start of every
  request:

```sql
ALTER TABLE vendors ENABLE ROW LEVEL SECURITY;
CREATE POLICY vendors_tenant_isolation ON vendors
    USING (company_id = current_setting('app.current_company_id')::BIGINT);
-- Equivalent policies applied to vendor_contacts, vendor_addresses, vendor_bank_accounts,
-- vendor_certificates, vendor_contracts, vendor_registration_numbers, vendor_status_history,
-- vendor_approvals, vendor_duplicate_candidates.
```

- **Bank-detail protection**: `iban`, `account_number`, and `swift_bic` are encrypted at rest using
  application-level column encryption (Laravel's encrypted casts, AES-256-GCM, keys managed outside
  the database) in addition to PostgreSQL's disk-level encryption, because bank details are the
  highest-value target in this table and deserve encryption independent of infrastructure-level
  protections. Only the last 4 characters of an IBAN/account number are ever rendered in list views
  or non-payment-context UI; full values are revealed only in the dedicated bank-account detail view
  and require re-authentication (step-up auth) if the viewing session is older than a configurable
  threshold (default 30 minutes).
- **National ID / passport number** (for `individual` vendors) is treated as PII and encrypted at rest
  identically to bank details, masked in list views, and excluded from any AI-layer prompt context
  unless the specific AI task requires it (e.g., duplicate detection needs it for matching but
  receives only a one-way hash for comparison purposes, never the plaintext value, wherever the
  matching algorithm can operate on a hash-equality basis; fuzzy matching that genuinely requires
  plaintext is restricted to the General Accountant agent's sandboxed matching function and is never
  included in any LLM prompt or logged verbatim).
- **AI never bypasses authorization.** Every AI-initiated write (contract extraction confirmation
  prompts, risk-score writes, duplicate-candidate rows) is executed via the Laravel API using a
  dedicated `ai_agent` service-account identity with its own narrowly-scoped permission set (per the
  Permissions table above — no sensitive mutating permission is ever granted to it), and every such
  request is tagged `X-Initiated-By: ai_agent` for audit-log clarity distinguishing AI-initiated calls
  from human-initiated ones even when both hit the same endpoint.
- **Sanctions/PEP data source integrity**: the external watchlist feed used by Fraud Detection and
  Compliance Agent is pulled over TLS from a licensed data provider on a signed, versioned schedule;
  the specific feed version used for any given screening result is stored alongside the result
  (`vendor_fraud_flags.watchlist_feed_version`) so that a screening decision can always be explained
  by "which list, as of when" even years later.
- **Rate limiting and abuse prevention**: vendor-creation and bulk-import endpoints are rate-limited
  per user (platform-wide 429 convention) specifically because vendor-master creation is a common
  target for automated fraud-vendor injection attempts; unusually high creation velocity from a single
  user triggers a Fraud Detection review flag on that user's session, not just on the created vendors.
- **Input validation**: every write endpoint validates through a Laravel FormRequest before reaching
  the Service layer (per Clean Architecture — no business logic in controllers), including strict
  format validation for IBAN (mod-97 checksum), SWIFT/BIC (8 or 11 alphanumeric per ISO 9362), and
  VAT numbers (country-specific regex), rejecting malformed values with a 422 before any AI or
  duplicate-check processing runs, to avoid wasting compute on structurally invalid input.

# Audit Logs

Every mutating action in this module writes to the platform-wide `audit_logs` table with the standard
shape (`actor_id`, `action`, `entity_type`, `entity_id`, `old_values` JSONB, `new_values` JSONB,
`reason`, `ip_address`, `device_info`, `occurred_at`), plus this module's dedicated
`vendor_status_history` for lifecycle transitions specifically (justified under Database Design /
Versioning). Additional module-specific audit considerations:

- **Bank-account changes are always fully logged even when rejected.** A `verification_failed` bank
  account row is never deleted — it remains as a permanent, queryable forensic record of an attempted
  (and rejected) change, distinguishable from a successfully verified one, which is essential evidence
  if the rejected attempt later turns out to have been a fraud attempt investigated by law enforcement
  or the company's insurer.
- **Merge operations log both directions.** A `vendors.merge` audit entry is written against the
  survivor (`new_values` shows the incremented balance/counters) and a separate entry against each
  merged vendor (`new_values` shows `status: 'archived', merged_into_vendor_id: {survivor_id}`), so
  querying either vendor's individual audit trail surfaces the merge event.
- **AI-driven writes are labeled distinctly.** `audit_logs.actor_id` for AI-initiated writes (e.g., the
  risk-score recompute job) references the `ai_agent` service-account user row (a genuine row in
  `users`, not a NULL/magic value), so audit queries can filter "show me only human-initiated changes"
  or "show me only AI-initiated changes" with a simple join, never a special-cased NULL check.
- **Approval-step actions log the AI recommendation alongside the human decision** where AI provided
  one (e.g., a Compliance step where Fraud Detection returned a sanctions-match confidence) —
  `vendor_approval_steps.ai_recommendation` is included in the audit snapshot, so an auditor can later
  verify whether the human approver's decision agreed or disagreed with the AI's suggestion and in
  what direction, which is itself useful data for calibrating AI trust over time.
- **Retention**: vendor audit logs are retained for a minimum of 10 years (configurable per company to
  meet local statutory record-retention requirements, e.g., Kuwait commercial law, GCC VAT regimes
  where applicable) and are never subject to the standard soft-delete-then-purge lifecycle that some
  operational logs use — financial audit trails are retained regardless of the vendor's own
  `deleted_at`/archived status.
- **Immutable storage tier**: after 90 days, audit log rows for this module are additionally mirrored
  to an append-only object-storage archive (Cloudflare R2, per the platform's object-storage
  convention) in a write-once format, providing a secondary tamper-evidence layer independent of
  direct database access even for users holding elevated database roles.

# Performance

- **Read-model materialization**: `vendor_open_items` is a materialized view refreshed concurrently
  (allowing concurrent reads during refresh) every 15 minutes on a schedule plus immediately after any
  `purchasing.bill.posted`, `purchasing.payment.posted`, or `purchasing.debit_note.posted` event, so
  the Vendor Aging and Outstanding Payables reports never need to compute a live join across `bills`,
  `debit_notes`, and `vendor_payments` at request time — they read a pre-computed, indexed table.
- **Denormalized counters** (`balance_due`, `lifetime_po_count`, `lifetime_po_value`,
  `lifetime_payment_count`) on `vendors` avoid aggregate queries for the most frequently displayed
  fields (every vendor list row shows balance due) at the cost of requiring careful, listener-driven
  consistency maintenance — the trade-off is deliberate: vendor list views are read orders of
  magnitude more often than balances change, so paying the small write-side complexity cost to make
  every list-page read O(1) rather than requiring an aggregate is the right trade for this table's
  access pattern.
- **Duplicate-detection query cost control**: the trigram index on `legal_name` combined with limiting
  the fuzzy pass to the same `company_id` keeps the synchronous check's cost bounded even for
  companies with tens of thousands of vendors; the exact-match passes (tax ID, IBAN) use plain B-tree
  indexes and are effectively O(log n). The target p95 latency for the synchronous duplicate check is
  under 150ms; if a company's vendor count grows large enough that this target is at risk, the fuzzy
  pass falls back to a pre-filtered candidate set (same first-3-characters-normalized prefix) before
  running trigram similarity, trading a small amount of theoretical recall for bounded latency.
- **Pagination and default limits**: all list endpoints default to 25 rows per page (platform
  convention) and support cursor pagination for company vendor lists exceeding roughly 10,000 rows,
  where offset pagination's performance degrades; cursor pagination is based on `(created_at, id)`
  composite ordering with a corresponding composite index.
- **Bulk operations chunking**: import and bulk-update operations process in chunks of 100 rows per
  database transaction (rather than one giant transaction or one transaction per row) to bound lock
  duration and memory footprint while still keeping the number of transaction round-trips reasonable;
  each chunk's success/failure is independently reported so a failure in chunk 40 of 100 does not
  require re-processing chunks 1–39.
- **Risk-score recompute batching**: the nightly full recompute processes vendors in batches of 500
  per company (parallelized across companies, sequential within a company to avoid contention on the
  same `vendors` rows), and skips vendors with no changed inputs since the last run (`updated_at` and
  related child-table timestamps checked against `risk_score_computed_at`) to avoid wasted computation
  on a company's dormant/`archived` vendor population.
- **Read replica routing**: report queries (Vendor Aging, Vendor Spending, Purchase Analysis) that
  scan large historical ranges are routed to a read replica where the platform's database topology
  provides one, keeping report generation from contending with the primary's write throughput during
  business hours.

# Edge Cases

- **A vendor with zero bank accounts needs to be paid.** Allowed for `cash`, `check`, or
  `manual_wire` payment methods (Business Rule 7); the UI must surface a clear warning at PO/bill
  creation time that no verified electronic payment method exists, to avoid a payment being blocked
  unexpectedly at the final step.
- **A vendor's only bank account is rejected (`verification_failed`) and no replacement exists yet.**
  The vendor remains `active` (rejection of one bank account does not itself change vendor status) but
  cannot be paid electronically until a new, verified account is added — `balance_due` continues to
  accrue normally from posted bills; only the payment step is blocked, not the purchasing/billing
  flow, since blocking billing would understate real liabilities on the books.
- **Two companies (tenants) legitimately need to pay the same real-world vendor.** This is normal and
  expected — `vendors` rows are always company-scoped, so the same supplier is represented by
  independent rows in each company's vendor table with no cross-tenant linkage or shared identity;
  QAYD does not attempt a platform-wide "canonical vendor" concept, since two companies' relationships
  with the same supplier (pricing, terms, contacts) are genuinely independent and conflating them would
  violate tenant isolation for no real benefit.
- **A vendor is merged, then a new bill arrives referencing the old (merged/archived) vendor's
  `vendor_code`** (e.g., from an OCR'd paper invoice scanned weeks later that still shows the old
  vendor's letterhead code). The bill-creation flow resolves `vendor_code` lookups through
  `merged_into_vendor_id` transparently, automatically attaching the bill to the survivor with a
  system note recording that the original code referenced a since-merged vendor, rather than
  rejecting the bill or silently creating a new duplicate vendor.
- **A blacklisted vendor is the sole source for a critical, already-committed contract** (e.g., an
  exclusive-supply agreement with no alternative on short notice). Blacklisting still hard-blocks new
  payment, but existing open bills are only held, not voided (Business Rule 8) — Finance Manager
  retains an explicit override path (`purchasing.vendor.blacklist.payment_override`, a narrowly-scoped
  permission not listed in the main Permissions table because it is intentionally rare and requires a
  written justification stored alongside the specific payment, reviewed by Auditor after the fact).
- **A duplicate-detection false positive at the ≥0.90 block threshold** (e.g., two genuinely different
  small sole-proprietor vendors who happen to share a family member's bank account used for both
  businesses). The blocked user can escalate via `purchasing.vendor.duplicate.override` (Finance
  Manager and above), which creates the vendor anyway but permanently links it to the flagged
  candidate row as `status = 'confirmed_not_duplicate'` so the same pair never re-triggers a block on
  subsequent attempts.
- **Currency mismatch between a vendor's contract value and the currency used on actual POs.**
  `vendor_contracts.currency_code` is informational for budget tracking and does not constrain
  `purchase_orders.currency_code`; a mismatch is surfaced as a non-blocking warning on the PO ("this
  PO's currency differs from the referenced contract's stated currency of {X}") since real-world
  contracts are sometimes denominated in one currency while day-to-day POs are issued in another by
  mutual arrangement.
- **A `contractor` vendor's contract expires mid-project with milestones still outstanding.** New bills
  against expired milestones are blocked at the Purchasing layer (`ValidateMilestone` check references
  `vendor_contracts.status`, and a `contract_status_enum` value of `expired` fails validation) until
  either the contract is renewed (`end_date` extended, `status` reset to `active`) or a
  `contractor.contract_override` permission holder explicitly authorizes billing against an expired
  contract with a reason — this is deliberately friction-heavy because paying against an expired
  contract without renewal is itself a compliance/legal exposure worth surfacing, not smoothing over.
- **Bulk import contains rows that would each individually trigger a medium-confidence duplicate
  flag against each other** (not against existing vendors, but within the same import batch — e.g., a
  spreadsheet with the same vendor entered twice by different regional offices). The import job runs
  the duplicate check both against existing vendors and within the batch itself before committing any
  rows, surfacing intra-batch duplicate pairs in the job's result report rather than creating both and
  relying on the nightly sweep to catch it later.
- **A vendor's `parent_company_vendor_id` chain is edited to introduce what would become a cycle**
  three or more levels deep (A → B → C → A). The Service layer's cycle check (depth-bounded traversal,
  Business Rule 2) catches this at write time and rejects with a 422 rather than allowing a
  structurally-invalid hierarchy that would later break the concentration-risk aggregation query
  (which assumes a DAG, not a cyclic graph).

# Future Improvements

- **Vendor self-service portal.** A dedicated, narrowly-scoped external-facing surface where a
  vendor's own primary contact can log in (via a separate, non-Sanctum external-identity mechanism) to
  update their own contact details, upload renewed certificates, and view their own payment history
  and open invoices — all changes still routed through the same verification/approval gates as if
  entered internally, never trusted implicitly just because they originated from the vendor's own
  portal session.
- **Continuous sanctions re-screening.** Currently, sanctions/PEP screening runs at approval time and
  opportunistically on trigger events; a future improvement is continuous, scheduled re-screening of
  the entire active vendor base against watchlist updates (rather than only screening new vendors),
  since a vendor can become sanctioned after onboarding.
- **Graph-based fraud detection.** Extending Fraud Detection beyond pairwise signals (shared IBAN,
  shared tax ID) to a full relationship-graph analysis across vendors, contacts, and bank accounts, to
  surface fraud rings that individually-innocuous pairwise matches would miss (e.g., a five-vendor ring
  where no two vendors share a signal directly but all five connect through a shared intermediate
  contact phone number).
- **Vendor scorecard benchmarking across the QAYD customer base (anonymized, opt-in).** Allowing a
  company to see how a vendor's on-time-delivery or price competitiveness compares to the same
  vendor's aggregated, anonymized performance across other QAYD companies that also transact with
  them — valuable for negotiation leverage, requires careful privacy design (no company ever sees
  another specific company's vendor terms, only anonymized aggregates) and is deliberately deferred
  until the platform has enough shared-vendor density for the aggregates to be statistically
  meaningful and non-identifying.
- **Automated procurement-card (virtual card) issuance per vendor** for low-value, high-frequency
  spend categories, reducing invoice-and-pay overhead for small recurring vendor relationships —
  requires integration with a card-issuing partner and is out of scope for the current
  bank-transfer/check-centric payment model.
- **Predictive vendor churn / supply-disruption early warning**, extending Supplier Performance from a
  backward-looking scorecard into a forward-looking prediction (e.g., flagging a vendor whose
  responsiveness and on-time rate have been degrading over the last 3 months as at elevated risk of an
  upcoming supply disruption, before a hard failure actually occurs).
- **Multi-language OCR expansion for Contract Analysis**, currently strongest on English and Arabic
  contract documents; extending high-confidence extraction to additional regional languages as the
  platform's Gulf-to-global expansion proceeds.
- **Vendor sustainability/ESG scoring** as an additional structured dimension on the vendor profile,
  anticipated as a future requirement once customers in regulated industries (public-sector suppliers,
  large multinationals with supply-chain ESG reporting obligations) require it — deferred until real
  customer demand and a defensible scoring methodology are established, rather than speculatively
  built now.

# End of Document
