# Security & Compliance Overview

**The landscape a GCC SME financial system actually operates in.**

Companion to `docs/security/SECURITY_ARCHITECTURE.md`, which specifies what QAYD builds. This document
covers what the outside world asks for, when it asks, what it costs, and which of it is worth buying.

---

## Contents

1. [The thesis: minimum adequate, then sequenced](#1-the-thesis-minimum-adequate-then-sequenced)
2. [SOC 2 — what it is, and when it becomes a sales blocker](#2-soc-2--what-it-is-and-when-it-becomes-a-sales-blocker)
3. [ISO/IEC 27001 — the GCC's preferred badge](#3-isoiec-27001--the-gccs-preferred-badge)
4. [PCI DSS — the scope you want is *none*](#4-pci-dss--the-scope-you-want-is-none)
5. [The technical standards worth actually reading](#5-the-technical-standards-worth-actually-reading)
6. [Kuwait and GCC data-protection reality](#6-kuwait-and-gcc-data-protection-reality)
7. [The compliance calendar, keyed to customer size](#7-the-compliance-calendar-keyed-to-customer-size)
8. [What a customer's security review actually contains](#8-what-a-customers-security-review-actually-contains)

---

## 1. The thesis: minimum adequate, then sequenced

QAYD is pre-launch with zero customers. That fact should drive every security decision on this page,
and it cuts in two directions that are easy to confuse.

**It does not reduce the engineering bar.** The tenancy boundary, the append-only ledger, the audit
trail and the AI confirmation boundary are *architecture*. They are cheap now and ruinous later —
retrofitting RLS onto a system with production tenants means a migration under load with a correctness
proof nobody can produce. QAYD already made the expensive-now/cheap-later trade correctly (P3, P5, P15,
P22). That is not compliance work; it is the product being correct.

**It does reduce the attestation bar to zero.** A SOC 2 Type II audit requires an observation period
over which controls operated `[DOCS]`. With no customers there is nothing to observe, no access reviews
to evidence, no incidents to have responded to. An organisation that buys a SOC 2 before it has users is
buying a document about a company that does not yet exist. Same for ISO 27001, whose ISMS is a
management system — it certifies that you *run* a process, and there is no process to run yet.

**The distinction that resolves it:**

| Category | Definition | Timing |
|---|---|---|
| **Architecture** | Controls whose cost rises superlinearly with time — tenancy model, append-only guarantees, audit design, key management shape, AI trust boundary | **Now.** Retrofit cost is the argument |
| **Hygiene** | Controls that are cheap at any time but embarrassing to lack — MFA, dependency scanning, secret scanning, backup restore tests, an incident runbook | **Before first customer.** Low cost, high signal |
| **Attestation** | Third-party evidence *that* you do the above — SOC 2, ISO 27001, pen-test reports | **When a deal requires it.** Never speculatively |
| **Theatre** | Controls bought for the appearance of security, whose threat model nobody can state | **Never.** See `ANTI_PATTERNS.md` §1 |

The failure this framing prevents is the common one: a startup that has an ISO 27001 certificate and a
tenancy boundary that has never been attacked by anyone competent. The certificate audits the *process*
around the boundary, not the boundary.

> **The honest position for QAYD today:** finish the architecture (the three known gaps and the hash
> chain), do the hygiene, and buy no attestation until a named deal is blocked on one.

---

## 2. SOC 2 — what it is, and when it becomes a sales blocker

### 2.1 What it actually is

SOC 2 is a **report by a CPA firm**, produced under AICPA attestation standards, on controls at a service
organisation. It is not a certification, there is no pass mark published, and no two SOC 2 reports cover
the same controls — the service organisation writes its own control descriptions and the auditor opines
on whether they were suitably designed and (for Type II) operated effectively. `[DOCS]`

The criteria are the **AICPA Trust Services Criteria**, published as **TSP Section 100, *2017 Trust
Services Criteria for Security, Availability, Processing Integrity, Confidentiality, and Privacy (With
Revised Points of Focus — 2022)***. The 2022 revision changed the *points of focus* — the illustrative
guidance — and explicitly did not alter the criteria themselves. `[DOCS]`
<https://www.aicpa-cima.com/resources/download/2017-trust-services-criteria-with-revised-points-of-focus-2022>

**Five categories** `[DOCS]`:

| Category | Included by | Relevance to QAYD |
|---|---|---|
| **Security** | Always — this is the *common criteria*, mandatory in every SOC 2 | Required |
| **Availability** | Optional | Add when an SLA is contractual |
| **Processing Integrity** | Optional | **Unusually relevant.** For an accounting engine, "processing is complete, valid, accurate, timely and authorised" is close to a restatement of the product's core claim |
| **Confidentiality** | Optional | Add when customers contractually designate data confidential |
| **Privacy** | Optional | Only if QAYD processes personal data as a controller — mostly it is a processor of its customers' data |

The Security category is organised as **common criteria series CC1–CC9**: control environment,
communication and information, risk assessment, monitoring activities, control activities, logical and
physical access controls, system operations, change management, and risk mitigation. CC1–CC5 map to the
COSO internal-control framework; CC6–CC9 are the technical series that engineering work actually lands
against. `[DOCS]` — series titles as published in TSP Section 100; verify point-of-focus detail against
the AICPA document itself before quoting to an auditor.

### 2.2 Type I vs Type II

| | Type I | Type II |
|---|---|---|
| Opines on | Design suitability **at a point in time** | Design **and operating effectiveness over a period** |
| Evidence | Configuration as observed on one date | Sampled evidence across the whole window |
| Observation window | None | Typically 3–12 months; 3 months is the common first window `[COMMUNITY]` |
| Buyer's view | "They have thought about it" | "It worked" |
| What it proves about a startup | Little | Meaningfully more |

**Type I is a milestone, not a product.** Its honest use is to unblock a deal while the Type II window
runs. Sophisticated buyers know this and increasingly ask for Type II directly `[COMMUNITY]`.

### 2.3 Cost and timeline, realistically

Figures are 2025 US-market and vary widely with scope and auditor. `[COMMUNITY]` — vendor pricing pages
and practitioner surveys, not audited data.

| Line item | Range (USD) |
|---|---|
| Readiness / gap assessment | $3,000 – $15,000 |
| Type I audit | $10,000 – $25,000 |
| Type II audit (Security only, 10–50 employees, one cloud environment) | $20,000 – $50,000 |
| Compliance-automation platform (Vanta / Drata / Secureframe), annual | $3,000 – $10,000 at startup scale |
| Remediation, tooling and internal time | $20,000 – $80,000 — routinely the largest and least-budgeted line |
| **All-in first year, Security-only Type II** | **$30,000 – $60,000** |

Timeline: roughly **3–4 months** from a clean start including a 3-month observation window, assuming
controls are already largely in place. If they are not, remediation dominates and 6–9 months is more
honest. `[COMMUNITY]` <https://drata.com/learn/soc-2/cost> · <https://www.thoropass.com/blog/soc-2-audit-cost-a-guide>

Sources: <https://www.trycomp.ai/hub/soc-2-cost-breakdown> · <https://www.startupdefense.io/soc-2-costs-for-startups-complete-breakdown-and-budget-guide>

### 2.4 When it becomes a hard blocker

`[INFERENCE]` from the pattern in vendor-security practice `[COMMUNITY]`. State it as a prediction, not a fact:

| Customer profile | Does SOC 2 block the deal? |
|---|---|
| Kuwaiti SME, 5–50 staff, owner-operated, buying accounting software | **No.** They will ask where data is hosted and whether it is backed up. A clear security page answers it |
| SME with an external auditor who reviews IT controls | **Rarely.** The auditor asks about access control and audit trail, not about SOC 2 |
| Mid-market / regional group, 200+ staff, has a procurement function | **Often.** A vendor security questionnaire arrives; SOC 2 collapses 200 questions into one attachment |
| Enterprise, regulated, or any customer whose own auditor scopes QAYD as a sub-service organisation | **Yes, effectively.** They cannot rely on your controls without evidence |
| Any customer where QAYD would be named in *their* SOC 2 report | **Yes.** This is the mechanism by which SOC 2 propagates down the supply chain |

**The practical trigger for QAYD:** the first deal where a procurement or infosec function — not the
finance buyer — is a decision-maker. That is the signal to start, not before. Before that point the
same money buys more security spent on the tenancy boundary and the audit chain.

**A cheaper intermediate step that works surprisingly well `[COMMUNITY]`:** publish a security page and
maintain a completed CAIQ or SIG-Lite questionnaire. Many mid-market reviews are satisfied by a
well-written, specific document — and QAYD's architecture gives unusually strong answers (database-enforced
tenancy, non-bypassing runtime role, append-only audit). Specificity beats a badge for a technical reviewer.

---

## 3. ISO/IEC 27001 — the GCC's preferred badge

### 3.1 What it is

**ISO/IEC 27001:2022** certifies an **Information Security Management System** — a management process:
scope, risk assessment, risk treatment, Statement of Applicability, internal audit, management review,
continual improvement. The clauses 4–10 are the ISMS. **Annex A** is the control set you select *from*,
justified by risk, and every inclusion or exclusion is recorded in the Statement of Applicability. `[DOCS]`

The 2022 revision restructured Annex A into **93 controls across four themes** `[DOCS]`:

| Theme | Range | Count |
|---|---|---|
| Organizational | A.5.1 – A.5.37 | 37 |
| People | A.6.1 – A.6.8 | 8 |
| Physical | A.7.1 – A.7.14 | 14 |
| Technological | A.8.1 – A.8.34 | 34 |

**ISO/IEC 27002:2022** is the implementation guidance for those controls — not certifiable, but the
document engineers should actually read.

Controls most load-bearing for a system like QAYD `[DOCS]` — identifiers and titles as published in the
2022 Annex A:

| Control | Title | QAYD surface |
|---|---|---|
| A.5.15 | Access control | RBAC, `PermissionResolver`, deny-by-default |
| A.5.18 | Access rights | Provisioning / de-provisioning, `company_users` lifecycle |
| A.5.19 | Information security in supplier relationships | Anthropic/OpenAI, hosting, payment gateway |
| A.5.34 | Privacy and protection of PII | Customer contact data inside tenant books |
| A.8.2 | Privileged access rights | The platform-admin path — QAYD's live weak point |
| A.8.5 | Secure authentication | MFA, session management |
| A.8.9 | Configuration management | Migration and RLS-policy drift |
| A.8.10 | Information deletion | Tenant offboarding vs immutable ledger — the real tension (see §T5 in P-principles) |
| A.8.15 | Logging | `audit_logs` |
| A.8.16 | Monitoring activities | Detection, which QAYD has almost none of today |
| A.8.24 | Use of cryptography | TLS, at-rest, the hash chain |
| A.8.25 | Secure development life cycle | CI gates, review discipline |
| A.8.28 | Secure coding | Static checks, the architecture tests |

### 3.2 Cost, cycle, and the GCC question

Certification runs a **three-year cycle**: Stage 1 (documentation review) and Stage 2 (implementation
audit) in year one, **annual surveillance audits** in years two and three, then recertification. `[DOCS]`

Cost for a small company is broadly comparable to SOC 2 — audit fees plus consultancy plus the internal
cost of running an ISMS, with the ISMS being the part organisations underestimate, because unlike SOC 2
it imposes a permanent management ritual. `[COMMUNITY]`

**Is ISO 27001 preferred over SOC 2 in the GCC?** `[INFERENCE]`, and worth stating as a hypothesis
rather than a finding. ISO certification is the more familiar instrument in Gulf procurement — it is an
ISO standard in a region whose public-sector and large-enterprise procurement is heavily ISO-literate,
whereas SOC 2 is a US CPA instrument with less recognition outside North American software buying. The
practical implication if true: **if QAYD's first attestation-demanding customer is a Kuwaiti or Saudi
enterprise or government-adjacent buyer, ISO 27001 is more likely the ask than SOC 2.** `[UNKNOWN]` —
this was not verifiable with confidence and should be settled by asking the first three enterprise
prospects directly rather than by research. That question costs one email and resolves a $40,000 decision.

### 3.3 The adjacent standards

| Standard | Covers | Worth it for QAYD? |
|---|---|---|
| ISO/IEC 27017 | Cloud-specific controls, guidance for cloud service providers and customers | Not yet. An extension once 27001 exists |
| ISO/IEC 27018 | PII protection in public clouds, for processors | Only if a customer specifically asks |
| ISO/IEC 27701 | Privacy Information Management System, extending 27001/27002 | Only if privacy regulation becomes a live obligation (see §6) |

None of these are standalone; all extend a 27001 ISMS. Buying them before 27001 is not possible and
buying them shortly after is usually a signal of a compliance function with a budget rather than a
customer requirement.

---

## 4. PCI DSS — the scope you want is *none*

This is the section where the useful answer is a negative one. **Scope avoidance is worth more than
scope compliance**, and for an accounting SaaS it is entirely achievable.

### 4.1 The current standard

**PCI DSS v4.0.1** is current, published mid-2024 as an errata revision of v4.0. v3.2.1 retired
**31 March 2024**, and the "future-dated" v4 requirements became mandatory **31 March 2025**. `[DOCS]`
<https://www.pcisecuritystandards.org/wp-content/uploads/2024/10/SAQs_for_PCI_DSS_v4.0.1_Bulletin.pdf>

### 4.2 What actually pulls a system into scope

The **Cardholder Data Environment (CDE)** is the people, processes and technology that store, process or
transmit account data — *plus anything connected to or that could affect the security of* that
environment. The second clause is what surprises people: a system that never touches a card number can
be in scope by connectivity. `[DOCS]`

Account data splits in two `[DOCS]`:

| Class | Elements | Storage rule |
|---|---|---|
| **Cardholder Data (CHD)** | Primary Account Number (PAN), cardholder name, expiration date, service code | PAN may be stored **only** if rendered unreadable — Requirement 3 |
| **Sensitive Authentication Data (SAD)** | Full track data, CAV2/CVC2/CVV2/CID, PIN and PIN block | **Never stored after authorisation.** Not encrypted-and-stored. Not stored |

**Requirement 3 — Protect Stored Account Data** — is the requirement family that governs both. `[DOCS]`

### 4.3 How to stay out of scope

The integration pattern determines the SAQ, and the SAQ determines the size of the problem. `[DOCS]`

| Pattern | What the browser does | SAQ | Scope |
|---|---|---|---|
| **Full redirect** to the processor's hosted page | Leaves your domain entirely | **SAQ A** | Minimal |
| **Iframe** hosting the processor's payment form | Card fields render in the processor's document | **SAQ A** | Minimal |
| **Direct post / hosted fields** (e.g. JS-mounted fields served by the processor into your page) | Card data goes to the processor, but *your page* controls the payment page's integrity | **SAQ A-EP** | Materially larger |
| Any page where your server or your JavaScript can read the PAN | You are handling card data | **SAQ D** | Full |

**The rule for QAYD:** use a redirect or a processor-hosted iframe. Never a self-built card form, never
"just for the enterprise plan", never a temporary one during a demo. The moment your own JavaScript is
in a position to observe a PAN, you have moved from SAQ A to SAQ A-EP or D and acquired a scoping problem
that survives the code being deleted.

### 4.4 The v4 change that matters — 6.4.3 and 11.6.1

These two requirements address browser-side skimming (Magecart-class attacks): **6.4.3** requires
management, authorisation and integrity assurance for scripts loaded into the payment page, and
**11.6.1** requires a mechanism to detect unauthorised change to the payment page as received by the
browser. Both were future-dated to **31 March 2025**. `[DOCS]`

The v4.0.1 SAQ revision (and PCI SSC FAQ 1588) changed how these land on the smallest merchants: SAQ A
merchants who meet the revised eligibility criteria — which require attesting that the merchant's site
is protected against script-based attacks — are not required to implement 6.4.3 and 11.6.1, while
SAQ A-EP and above must. `[DOCS]` <https://www.pcisecuritystandards.org/wp-content/uploads/2024/10/SAQs_for_PCI_DSS_v4.0.1_Bulletin.pdf> ·
<https://www.humansecurity.com/learn/blog/pci-dss-4-update-unpacking-the-changes-to-saq-a/>

The practical reading: **the gap between SAQ A and SAQ A-EP just widened.** The architectural choice made
once, at the start, is worth more than it was.

### 4.5 The questions people actually ask

| Question | Answer |
|---|---|
| Does storing a **gateway-issued token** put us in scope? | The token itself is not CHD, and tokenisation is the standard scope-reduction technique. But scope is determined by the whole environment, not one field — a system that can *exchange* a token for a PAN is in the CDE. `[INFERENCE]` from the CDE definition; confirm with the acquirer/QSA before relying on it |
| Does storing **last 4 digits** put us in scope? | Truncated PAN is not full PAN and is the standard displayable form. It does not by itself create a CDE. `[INFERENCE]` |
| Does storing a **gateway API key** put us in scope? | It is not account data. It is, however, a credential whose compromise enables fraud, and belongs in the secrets regime (§`BEST_PRACTICES.md` §5) regardless of PCI |
| Are we a **service provider**? | If QAYD stores, processes or transmits account data on behalf of customers, or could affect the security of their CDE, yes — with materially heavier obligations. A design that never touches account data avoids the question entirely `[INFERENCE]` |
| What **merchant level** are we? | Levels are set by the card brands by annual transaction volume; Level 1 requires an on-site assessment producing a Report on Compliance, the lower levels a self-assessment questionnaire and attestation. At QAYD's volume this is Level 4 and self-assessed. `[DOCS]` — exact thresholds differ per brand; take them from your acquirer, not from a blog |

> **QAYD's position, stated plainly for the security page:** QAYD does not store, process or transmit
> cardholder data. Subscription payments are handled by a PCI DSS validated third-party processor via
> a hosted payment page. QAYD's PCI obligation is SAQ A. **This sentence is worth protecting; it is one
> pull request away from being false.**

---

## 5. The technical standards worth actually reading

Unlike §2–§4, these are not badges. They are the documents an engineer gets value from directly.

### 5.1 OWASP ASVS 5.0.0

Released **May 2025**; roughly 350 requirements, each tagged **L1 / L2 / L3**, cumulative. The V1
Architecture chapter from 4.x was removed and its content redistributed into the topic chapters. `[DOCS]`
<https://github.com/OWASP/ASVS>

`[UNKNOWN]` — the chapter count is reported inconsistently in secondary sources (14 vs 17). Take the
chapter list from the released document, not from a summary.

**Why it is the most useful single document here:** it is a *verification* standard. Every requirement is
phrased as something a tester can confirm, which makes it usable as a definition of done and as a
pen-test scope. **ASVS L2 is the right target for QAYD** — L1 is the floor for any application, L3 is for
systems where a breach is a life-safety or national-security event. An accounting system for SMEs is
squarely L2.

### 5.2 NIST

| Publication | Version | Use |
|---|---|---|
| **Cybersecurity Framework 2.0** (NIST CSWP 29) | Published **26 Feb 2024** `[DOCS]` <https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf> | Organising vocabulary. Six Functions: **GOVERN** (new in 2.0), IDENTIFY, PROTECT, DETECT, RESPOND, RECOVER. Useful for structuring a security programme and for talking to non-engineers. Not a control set |
| **SP 800-53 Rev 5** | Current | The exhaustive control catalogue. Not for adoption wholesale — for *precision*. `AU-9` protection of audit information, `AU-10` non-repudiation, `AC-3` access enforcement, `AC-6` least privilege, `IA-2` identification and authentication, `SC-13` cryptographic protection, `SC-28` protection of information at rest, `SI-7` information integrity `[DOCS]` |
| **SP 800-63B-4** | Final **31 July 2025**, superseding the 2017 SP 800-63B `[DOCS]` <https://csrc.nist.gov/pubs/sp/800/63/b/4/final> | Authentication. Three Authenticator Assurance Levels, AAL1–AAL3 |

**SP 800-63B-4's password guidance is worth internalising because it contradicts what most SME customers
expect** `[DOCS]`: a verifier minimum of 8 characters with 15 recommended, a maximum of at least 64, **no
composition rules** (no forced upper/lower/digit/symbol), **no periodic rotation** absent evidence of
compromise, and mandatory screening against a blocklist of known-compromised passwords.

`[INFERENCE]` The friction this creates is a *sales* problem, not a security one: an SME's auditor may
still ask for 90-day expiry because that is what they have always asked for. The answer is to point at
SP 800-63B-4 and offer the setting as a per-company policy — QAYD should be able to *satisfy* the request
without believing in it. Designing password policy as tenant-configurable data (never as a hardcoded
constant) is the cheap move that avoids the argument.

### 5.3 OWASP Top 10 for LLM Applications (2025)

**LLM01: Prompt Injection**, and specifically *indirect* prompt injection, is the entry that matters for
QAYD. Malicious instructions are embedded in content the model later ingests — a document, an invoice, a
bank statement — rather than typed by a user. The attack is zero-click: the attacker plants content; a
normal user action activates it. `[DOCS]`
<https://owasp.org/www-project-top-10-for-large-language-model-applications/assets/PDF/OWASP-Top-10-for-LLMs-v2025.pdf>

The point made in that document that most affects QAYD's design: **a guardrail that inspects only the
direct user message runs before external content is retrieved**, so instructions entering via the
retrieval path bypass it entirely. Input filtering placed at the chat boundary does nothing about a
poisoned PDF. Treated fully in `BEST_PRACTICES.md` §7 and `ARCHITECTURE.md` §5.

### 5.4 Zero Trust

`[INFERENCE]` — the most over-marketed term on this page, and the one with a genuinely useful core.

Stripped of vendor framing, Zero Trust says: **do not grant authority on the basis of network position,
and re-verify at every request.** For QAYD, whose deployment has no corporate network, no VPN and no
internal/external distinction to abolish, most of the Zero Trust literature is inapplicable — it is
written for enterprises dismantling a perimeter QAYD never built.

What survives translation, and is exactly what QAYD already does:

- The runtime database role is `NOSUPERUSER NOBYPASSRLS`. Being *inside* the application confers no data
  authority `[CODE]` — `2026_07_27_000008_create_app_database_role.php`.
- Tenant context is re-established per transaction, not per session.
- There is no ambient privilege (P22) — the purest Zero Trust position available, arrived at without the
  vocabulary.

`ARCHITECTURE.md` §2 states this as a property rather than a purchase.

---

## 6. Kuwait and GCC data-protection reality

**This section marks its limits explicitly. Several questions here need Kuwaiti counsel, not research.**

### 6.1 Kuwait

Kuwait has **no comprehensive general personal-data-protection law**. Data protection is addressed
through sectoral instruments `[DOCS]` <https://www.dlapiperdataprotection.com/?t=law&c=KW>:

| Instrument | Relevance |
|---|---|
| **Law No. 20 of 2014** (Electronic Transactions) | Electronic transactions across private and public bodies |
| **Law No. 63 of 2015** (Cybercrime) | Penalties for unauthorised access, disclosure and alteration of data |
| **CITRA Data Privacy Protection Regulation** — Resolution No. 42 of 2021, replaced by **Administrative Decision No. 26 of 2024** | The nearest thing to a data-protection regulation |

The **scope question is the whole question, and it is genuinely ambiguous.** The CITRA regulation is
framed as applying to service providers in the telecommunications sector holding CITRA licences — but the
definition is reported as extending to anyone operating a website, smart application or cloud computing
service that collects or processes personal data. `[DOCS]` Whether a B2B accounting SaaS with no CITRA
licence falls inside that definition is **`[UNKNOWN]`** and is a question for a Kuwaiti lawyer. It is
also not an expensive question to ask.

What the regulation requires, if in scope `[DOCS]`:

- **Breach notification to CITRA *and* to affected individuals within 24 hours** of becoming aware.
  Notification to individuals may be waived where appropriate technical and organisational protection
  measures were effectively applied.
- Explicit consent before collecting or processing personal data; guardian consent under 18; a
  facilitated right to withdraw.
- Collection for specific lawful purposes; accuracy; protection against loss, damage, unauthorised access
  and misuse.
- **Disclosure of cross-border transfers, naming the destination countries.** No explicit localisation
  requirement was identified.

Penalties under CITRA's establishing law are reported as **one to five years' imprisonment and fines of
KWD 500 – 20,000** `[DOCS]`.

> **The 24-hour clock is the operationally significant fact on this page.** It is materially tighter than
> GDPR's 72 hours, and it is not achievable by improvisation. If QAYD is in scope, the *only* way to meet
> it is a pre-written incident runbook with named roles, a pre-drafted notification template, and a way to
> determine blast radius quickly — which is exactly what the `audit_logs` design is for. This is a strong
> argument for finishing the audit trail *before* the first customer, independent of any attestation.

### 6.2 Saudi Arabia and the UAE — the expansion markets

| | Saudi Arabia | UAE |
|---|---|---|
| Instrument | **PDPL**, amended 23 March 2023 `[DOCS]` | **Federal Decree-Law No. 45 of 2021** `[DOCS]` |
| In force | 14 September 2023 | 2 January 2022 |
| Enforcement | After a one-year grace period, from **13 September 2024** `[DOCS]` | Executive regulations **were still unissued as of early 2025**; organisations get a further six months from their issuance `[DOCS]` |
| Regulator | SDAIA | UAE Data Office |
| Extraterritorial | Yes — applies to processing of residents' data from outside the country `[DOCS]` | Yes |

Sources: <https://www.dlapiperdataprotection.com/?c=SA> · <https://www.dlapiperdataprotection.com/countries/uae-general/law.html>

`[INFERENCE]` — the implication for architecture is smaller than it looks and larger than it feels.
Saudi PDPL is live and extraterritorial, so a Saudi customer brings real obligations the day they sign,
not the day QAYD opens an office. But the *architectural* requirements these laws generate — data
inventory, subject-access response, deletion capability, breach detection, transfer disclosure — are the
same small set in every jurisdiction. **Build the capability once; the jurisdictions are configuration.**

The one architectural decision that is expensive to reverse is **data residency**. If a Saudi enterprise
customer requires in-country hosting, that is a deployment-topology change, not a feature. `[UNKNOWN]`
whether Saudi PDPL imposes a hard localisation requirement in the general case — this was not verified and
must not be assumed either way.

### 6.3 GDPR

QAYD is not obviously in GDPR scope, but it is one customer away from being so — Article 3(2) extends the
Regulation to processing of data subjects in the Union. `[INFERENCE]` The realistic trigger is a GCC
customer with EU employees or EU customers in their books, which is not exotic.

The practical stance: **do not build for GDPR, but do not build anything that makes GDPR impossible.**
Concretely, the capabilities that matter — export, deletion, purpose records, processor agreements — are
the same ones §6.2 already argues for, and the one genuine conflict (erasure vs an immutable ledger) is
already identified as a live tension in the principles (§T5) and is addressed by crypto-shredding in
`BEST_PRACTICES.md` §4.5.

---

## 7. The compliance calendar, keyed to customer size

`[INFERENCE]` — a sequencing recommendation, not an observation. Triggers are events, not dates, so this
does not go stale.

| Trigger | Do this | Do **not** do this yet |
|---|---|---|
| **Today** — pre-launch, zero customers | Close the three known gaps. Build the hash chain. Write the incident runbook. Verify backup restore. Turn on MFA. Wire dependency + secret scanning in CI | Anything with an auditor in it |
| **Before customer #1** | Security page stating the real architecture. Data-processing description. Named breach-response owner. Kuwaiti counsel's answer on CITRA scope | SOC 2. ISO 27001. A CISO |
| **First customer holding real books** | Adversarial tenancy test suite (`BEST_PRACTICES.md` §3). Restore-from-backup drill with a stopwatch. Log retention decided | Penetration test — too early to be worth the money |
| **~10 paying customers** | Third-party penetration test against ASVS L2. Fix findings. Keep the report — it answers most questionnaires | Attestation |
| **First vendor security questionnaire** | Answer it properly and keep the answers as a maintained artefact (CAIQ / SIG-Lite) | Panic-buy SOC 2 because one prospect asked |
| **First deal where infosec or procurement is a decision-maker** | Start readiness. Decide SOC 2 vs ISO 27001 **by asking that customer which they want** | Buy both |
| **Deal value exceeds the cost of the audit, and is blocked on it** | Buy the attestation. This is the only rational trigger | — |
| **First customer requiring in-country data residency** | Treat as an architecture decision requiring an ADR, not a feature request | Promise it in a sales call |
| **AI proposes entries against ingested third-party documents** | The AI security controls in `BEST_PRACTICES.md` §7 become mandatory, not optional | Ship the ingestion path first and secure it later |

**The single rule underneath the table:** buy attestation when a deal is blocked on it and the deal is
worth more than the audit. Every other trigger is someone else's anxiety.

---

## 8. What a customer's security review actually contains

`[COMMUNITY]` — the recurring content of SME and mid-market vendor reviews. Useful because it shows how
little of §2–§4 is usually asked, and how much of it QAYD can already answer well.

| They ask | QAYD's answer today | Strength |
|---|---|---|
| Where is our data stored? | Single PostgreSQL instance, region stated | Fine, once written down |
| Can another customer see our data? | Enforced by PostgreSQL RLS with a `NOBYPASSRLS` runtime role, not by application code `[CODE]` | **Unusually strong.** Most competitors cannot say this |
| Who at your company can see our data? | **This is the weak answer today.** `is_platform_admin` is deliberately unwired for reads (TD-04), but the `audit_logs` boundary does honour it for writes `[CODE]` | Needs the G-18/G-19 work before it is a clean answer |
| Is everything we do logged? | `audit_logs`, append-only, trigger-enforced against even the table owner `[CODE]` | Strong; stronger once the hash chain is live |
| Can your staff change our numbers? | Posted journals are immutable; the ledger is append-only, enforced in the database | **Very strong.** This is a better answer than most accounting SaaS can give |
| Do you use AI on our data? What does it do? | Drafts only; a human approves; the database refuses AI-authored postings | Strong claim — **and currently only half-true on the `UPDATE` path** (gap 1) |
| Do you have MFA / SSO? | Not yet (TD-08) | Weak. Cheap to fix, and asked every time |
| What happens if you are breached? | No runbook | Weak, and free to fix |
| Are you SOC 2 / ISO certified? | No | Fine, until it is not — see §7 |
| Can we export our data if we leave? | — | Asked more often than any certification question, and answering it well wins trust disproportionately |

`[INFERENCE]` The pattern is consistent: **QAYD's architecture answers the hard questions better than its
operations answer the easy ones.** The cheapest security wins available today are MFA, a written incident
runbook, and a security page — none of which are architecture, all of which are asked in every review.
