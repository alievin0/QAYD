# 12 — Business — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: BUSINESS

---

# Purpose

This chapter states QAYD's business model at product-requirements altitude: how the platform is
packaged and priced, how it is licensed, how its own subscriptions are billed, how it opens itself to a
marketplace and a partner ecosystem, what it exposes to external developers, and how it goes to market
from Kuwait outward across the GCC. It is a business specification, not an engineering one — the
technical surfaces it references (the permission layer that enforces plan entitlements, the public API,
the partner integration boundary) are owned by their respective specifications, and this chapter routes
to them rather than restating how they work. Its job is to describe the commercial machine that the
product exists to power and that the security and infrastructure chapters exist to make credible.

The commercial thesis follows directly from the product thesis in
[`../MASTER_PRD.md`](../MASTER_PRD.md) and [`../../foundation/MISSION.md`](../../foundation/MISSION.md).
The GCC is home to a large, fast-formalizing base of small and medium businesses — trading companies,
contractors, clinics, restaurants, retailers, professional-services firms — pulled into formal,
VAT-consistent, payroll-compliant, bilingual bookkeeping faster than the available tools were built to
serve. Global SMB tools (QuickBooks, Xero, Zoho Books) are competent double-entry systems retrofitted
for the Gulf, treating Arabic RTL, KWD three-decimal precision, and Kuwait payroll (PIFSS/WPS) as
afterthoughts, and all of them remain *systems of record* where a human still does the work. Local ERPs
understand the context but are heavyweight, consultant-driven, and dated. QAYD's wedge is a different
category: an AI Financial Operating System where an AI workforce does the mechanical work continuously
and a human approves the consequential decisions, on a correct KWD/GCC double-entry core, in Arabic and
English. The business model must monetize *that* difference — automation depth and correctness — rather
than merely renting a ledger.

Several of the specific commercial numbers below are deliberately framed as decisions still to be
ratified; they are tracked in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md) and are called out where
they appear. This chapter fixes the *model*; the register fixes the open *values* within it.

---

# Pricing and Packaging

QAYD packages as a small number of named subscription tiers with an explicit entitlement matrix, priced
per company, with artificial-intelligence consumption metered as an add-on on top of the base tier.
Three design commitments make this a durable model rather than a price list:

- **Entitlements are enforced server-side by the permission layer, never by hiding a feature.** A tier
  does not "turn off a button"; it withholds a permission the backend checks on every request, exactly
  as [`10_SECURITY.md`](./10_SECURITY.md) requires for every other capability. This is a security
  property as much as a commercial one — a lower-tier tenant that crafts a request for a higher-tier
  capability is denied by the same deny-by-default gate that stops any unauthorized action, so plan
  gating cannot be bypassed by calling the API directly.
- **The AI is the metered dimension, because it is the value and the variable cost.** The base tier
  buys the correct ledger, the bilingual product, the human-approval workflow, and a defined allowance
  of AI automation; heavier automation (more documents processed, more inference, more autonomous
  agents) is metered on top. Metering the thing that both delivers the differentiated value and drives
  the marginal cost aligns price with worth and protects margin.
- **The tiers ladder with company maturity, not just company size.** A freelancer or micro-company, a
  growing SME with a real finance function, and an enterprise with multiple entities and an external
  auditor need different depths of the same platform — the architecture supports the full suite without
  a rewrite, so a tenant grows through the tiers rather than migrating off the product.

A representative three-tier shape:

| Tier | Who it is for | What it entitles (illustrative) |
|---|---|---|
| **Starter** | A freelancer or micro-company keeping its first correct books | Core accounting (chart of accounts, journals, general ledger, trial balance and statements), bilingual UI, a capped AI-automation allowance, a small seat count, community/self-serve support |
| **Growth** | An SME with a genuine finance function | The full finance suite as it lands (Sales, Purchasing, Banking, Payroll, Tax preparation), scheduled AI reporting and the financial copilot, higher AI allowance, more seats, standard support and the public API |
| **Enterprise** | A larger or multi-entity business with audit and residency needs | Multi-entity/consolidation depth, additional GCC jurisdictions, SSO, guaranteed data residency and DR class, partner-API breadth, and priced support/SLA |

The exact tier names, the precise entitlement matrix, the seat model, and whether AI metering is a
pure add-on or a hybrid base-plus-metered arrangement are the substance of **PRD-1** in
[`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md) (a blocking product decision, since entitlement
enforcement touches the permission layer). The recommendation on the table — three named tiers with an
explicit matrix, enforced server-side, AI metered on top — is the starting position this section
assumes, not a ratified price sheet.

---

# Licensing and Subscriptions

QAYD is licensed as a per-company software-as-a-service subscription, not a perpetual license and not a
per-user product in the seat-first sense — the unit of account is the *company* (the tenant), with a
seat allowance inside each tier. This mirrors how the platform is built and secured: every company is a
fully isolated tenant (see [`10_SECURITY.md`](./10_SECURITY.md) and
[`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md)), so a subscription is naturally
scoped to a tenant, and an accounting firm serving several client companies holds a subscription (or a
partner arrangement) per client company rather than one blended account. Subscriptions renew on a
recurring cycle, tiers are upgradeable and downgradeable in place because the architecture supports the
full suite without migration, and entitlement changes take effect through the same permission-resolution
path that governs every other grant — an upgrade grants permissions, a downgrade withdraws them, both
audited.

Per-company feature flags provide the finer-grained control beneath the tier — enabling a capability for
a specific tenant without a deploy — and the current decision is to keep that flag mechanism as the
existing per-company database table rather than adopt a managed flagging service until targeting rules
outgrow it (**PRD-3** in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)).

---

# Billing

A distinction that must be kept crisp: QAYD's **own** SaaS billing (charging tenants for their QAYD
subscription) is a different system from the **tenant-facing payment gateways** (Tap, MyFatoorah,
KNET) that a tenant's own *business* uses to collect money from *its* customers through the Sales
module. The latter are partner integrations described under the partner program below and owned by
[`../../api/PARTNER_API.md`](../../api/PARTNER_API.md); the former is the subscription-billing engine
that runs QAYD as a business.

For QAYD's own billing, the requirement is a recurring subscription-billing capability that supports the
GCC payment reality — KNET and mada coverage for Kuwaiti and Saudi SMEs matters more than a
US-optimized checkout — handles proration on tier changes, meters AI consumption for the add-on
dimension, and issues tax-correct invoices for QAYD's own sales. The provider for this is an open
decision (**PRD-2** in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)): the recommendation is a
GCC-local recurring provider (Tap or MyFatoorah) for KNET/mada coverage of the target customer, with
Paddle (a merchant-of-record that absorbs cross-border VAT-on-SaaS) revisited if cross-border tax
handling becomes a burden as the customer base spreads beyond Kuwait. This chapter does not fix the
provider; it fixes that QAYD's billing must speak the Gulf's payment rails natively, because a platform
that cannot itself be paid for the way its customers pay for everything else has a friction problem at
its own front door.

---

# The Marketplace

The marketplace is the ecosystem of integrations that connect QAYD to the other software a GCC business
already runs — point-of-sale terminals, e-commerce storefronts, and automation tools of the
Zapier/Make class — built on QAYD's public API rather than bespoke one-off connectors. Its commercial
purpose is twofold: it makes QAYD stickier (a business wired into QAYD through its POS and its store is
far less likely to churn) and it lets third-party software vendors extend QAYD's reach into verticals
QAYD does not build itself. The canonical example carried through the API documentation is a
point-of-sale vendor that pushes retail sales into QAYD as invoices and payments and pulls the product
catalogue and customer list back — a pattern that recurs across retail, food and beverage, clinics, and
services. The marketplace is deliberately scoped to the resource families QAYD opens publicly
(invoices, customers, products, payments) and deliberately excludes the ones it does not (payroll, bank
transfers, tax submission, company and role administration), so a marketplace integration can grow a
business's revenue capture without ever touching the sensitive surfaces that the two-key controls in
[`10_SECURITY.md`](./10_SECURITY.md) guard. The public surface it is built on is owned by
[`../../api/PUBLIC_API.md`](../../api/PUBLIC_API.md).

---

# The Partner Program

Where the marketplace is about independent software *extending* QAYD, the partner program is about the
institutions QAYD must *connect to* in order to do finance at all, plus the channel partners who sell
and implement QAYD. Four categories of external counterpart define the program, all mediated through a
single hardened partner boundary so QAYD has exactly one place to reason about partner risk rather than
a bespoke HTTP client per module:

- **Banks and Open Banking aggregators** — the institutions QAYD's Banking module reads balances and
  statement lines from and initiates transfers through, under the Central Bank of Kuwait, SAMA, and
  CBUAE Open Banking/Open Finance frameworks, plus the conventional statement-feed and SWIFT channels
  that still dominate volume.
- **Payment gateways and card networks** — KNET as Kuwait's national switch, and regional gateways
  (Tap, PayTabs, MyFatoorah, Amazon Payment Services) sitting in front of the card networks and wallets,
  which the tenant's Sales receipts rely on to move customer money.
- **Government tax and e-invoicing authorities** — ZATCA (Fatoora) in Saudi Arabia, the UAE FTA, and,
  prospectively, Kuwait's Ministry of Finance once a domestic VAT e-filing channel activates. The
  jurisdiction model is already provisioned for Kuwait so activation is a data change, not a code
  release.
- **External ERP, POS, and CRM systems** — the systems of record a company already runs and must
  exchange data with on an ongoing basis, from an on-premise ERP mid-migration to a fleet of branch POS
  terminals to a CRM.

A distinct partner *tier* also matters commercially: accounting firms and implementation partners who
onboard and serve multiple client companies are QAYD's most efficient distribution channel in a market
where the accountant is often the buyer's most trusted advisor. The partner-connection, credential,
scope, sandbox, and certification machinery is owned by
[`../../api/PARTNER_API.md`](../../api/PARTNER_API.md).

---

# Public API and Developer Platform

The public API and developer platform are the commercial infrastructure beneath both the marketplace
and the ISV side of the partner program. QAYD deliberately opens a *narrow, well-chosen subset* of its
full API surface to external, third-party consumers — the invoices, customers, products, and payments
families — and just as deliberately keeps payroll, banking transfers, tax submission, AI-engine
internals, and company/role administration out of the public surface entirely. External consumers
authenticate with API keys or OAuth applications layered on the platform's existing token model, are
governed by their own rate-limit tiers and quotas, and never hold a first-party company-user session.
The developer platform around this surface is a product in its own right: a Developer Portal where
external developers register, obtain and rotate credentials, monitor usage, and manage webhook
subscriptions; four official SDKs (PHP, JavaScript/TypeScript, Python, and Flutter/Dart); a filtered
webhook event catalogue; and a persistent Sandbox environment developers build and test against before
going live. The commercial logic is that a low-friction, well-documented, safely-scoped developer
platform is what turns QAYD from a product into a platform — the difference between a tool a company
buys and an ecosystem a market builds on. The public surface, portal, SDKs, and sandbox are owned by
[`../../api/PUBLIC_API.md`](../../api/PUBLIC_API.md), with the underlying integration boundary in
[`../../api/PARTNER_API.md`](../../api/PARTNER_API.md).

---

# Growth Strategy

QAYD's growth strategy monetizes the same property that differentiates the product: automation depth
compounds into retention. The mechanics are three reinforcing motions.

- **Land on time-to-first-value, not on a feature list.** The onboarding target in
  [`../MASTER_PRD.md`](../MASTER_PRD.md) is a company reaching its first *reconciled* books within the
  onboarding session — the moment the AI visibly does work the owner expected to pay a person to do.
  That first-value moment is the wedge; everything else expands from it.
- **Expand through the accountant.** The Accountant/Bookkeeper is the highest-frequency daily user and,
  at an accounting firm, the multiplier across many client companies. Winning the accountant — who
  reviews and one-click-accepts AI drafts instead of typing them — is the most capital-efficient
  distribution path, and the partner tier for accounting firms is built to serve it.
- **Retain on trust and depth.** The pass/fail invariants — 100% of sensitive actions human-approved,
  zero cross-tenant incidents, 100% of AI proposals carrying resolvable citations — are the trust
  anchor that makes an AI keeping the books defensible to an owner and an auditor. As a tenant's history
  grows, the AI's calibration improves and its automation share rises, deepening the switching cost. Net
  revenue retention (a business KPI set per phase in the roadmap) is driven by tenants climbing the
  tiers and consuming more metered AI as they trust it with more, not by squeezing price.

---

# Go-to-Market Sequencing

QAYD's architecture is global — no feature is ever built for a single country — but its go-to-market and
localization *depth* are sequenced, Kuwait-first, then the GCC, then beyond. This is a deliberate
non-goal of a simultaneous worldwide launch (see [`../MASTER_PRD.md`](../MASTER_PRD.md)); depth in one
market beats breadth across many for a product whose credibility rests on getting Arabic, KWD, and local
compliance exactly right.

| Stage | Focus | What must be true |
|---|---|---|
| **Kuwait first** | The home market: Kuwaiti SMEs and the accounting firms that serve them | KWD three-decimal precision, full Arabic RTL, PIFSS/WPS payroll, KNET/mada billing rails; Kuwait VAT shipped dormant/out-of-scope and flippable via a country-pack data change when legislation lands (**CMP-1** in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)) |
| **GCC next** | Saudi Arabia and the UAE, where VAT and e-invoicing are already live | ZATCA (Fatoora) e-invoicing (Phase-1 generation at launch, Phase-2 clearance as a customer-tied fast-follow — **CMP-3**) and UAE FTA VAT filing; in-region residency and DR that satisfy GCC data-protection expectations per [`11_INFRASTRUCTURE.md`](./11_INFRASTRUCTURE.md) |
| **Beyond** | Additional GCC jurisdictions and, later, comparable emerging markets | The jurisdiction model, tax country-packs, and partner-API breadth carry a new market as configuration and localization work, not a rewrite — the enterprise phase in [`../MASTER_PRD.md`](../MASTER_PRD.md) |

The sequencing is also a compliance sequencing: the go-to-market cannot outrun what the residency,
tax-jurisdiction, and billing infrastructure can honestly support in a given market. A sales promise
about where data lives or how tax is filed must match the infrastructure that
[`11_INFRASTRUCTURE.md`](./11_INFRASTRUCTURE.md) and the tax module actually provide — which is why the
open decisions on residency (CMP-2), Kuwait VAT activation (CMP-1), and ZATCA readiness (CMP-3) sit on
the critical path between one market stage and the next.

---

## Related Documents

- [`../MASTER_PRD.md`](../MASTER_PRD.md) — the problem, market, personas, positioning, success metrics, and release phases this business model monetizes.
- [`../../foundation/MISSION.md`](../../foundation/MISSION.md) — the mission and long-term goal the commercial strategy serves.
- [`../../api/PUBLIC_API.md`](../../api/PUBLIC_API.md) — the public API subset, Developer Portal, SDKs, and Sandbox behind the marketplace and developer platform.
- [`../../api/PARTNER_API.md`](../../api/PARTNER_API.md) — the partner integration boundary for banks, gateways, tax authorities, and ERP/POS/CRM systems.
- [`10_SECURITY.md`](./10_SECURITY.md) — the permission layer that enforces plan entitlements server-side and the two-key controls that keep sensitive surfaces out of the public/marketplace API.
- [`11_INFRASTRUCTURE.md`](./11_INFRASTRUCTURE.md) — the residency, DR, and multi-region posture the GCC go-to-market promise must match.
- [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md) — open items PRD-1 (tiers/entitlements, blocking), PRD-2 (own billing provider), PRD-3 (feature-flag mechanism), PRD-4 (web-vs-mobile launch), CMP-1 (Kuwait VAT), CMP-3 (ZATCA readiness).

# End of Document
