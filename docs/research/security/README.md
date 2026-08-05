# QAYD Security Research

**Phase 3 of the engineering research programme. Research, not specification.**

Version 1.0 · 2026-07-28 · seven documents

---

## What this is

An evidence-graded study of the security and compliance landscape a financial system for GCC SMEs
actually operates in — standards, controls, threats, and the commercial reality of each — converted
into recommendations sequenced against QAYD's real position: **pre-launch, zero customers, single
PostgreSQL database, RLS multi-tenancy, an AI layer that will read untrusted documents.**

The organising question throughout:

> **What is the minimum security posture that is genuinely adequate for a system holding an SME's
> books — and what is the sequenced path from there to enterprise-grade?**

Both failure modes are real and this corpus refuses both. Under-engineering ships a bookkeeping
system whose tenancy boundary has never been attacked. Compliance theatre spends a year and $60,000
producing an attestation that no customer asked for, while an AI-drafted journal entry can still
reach `posted` through a path no trigger guards.

## What this is not

- **Not the security specification.** That is `docs/security/` — ten documents, ~4,700 lines, already
  written: `SECURITY_ARCHITECTURE`, `TENANT_ISOLATION`, `AUTHENTICATION`, `AUTHORIZATION`, `ENCRYPTION`,
  `SECRETS`, `AUDIT_LOGS`, `API_SECURITY`, `AI_SECURITY`, `DATA_PRIVACY`. Those say what QAYD will
  build. This corpus says what the outside world requires, what it is worth, and in what order.
- **Not a re-derivation of settled principles.** P3 (tenancy is a database property), P15 (AI drafts,
  humans dispose), P22 (no ambient privilege), R-07, R-19, R-31, R-32 and playbook §7 are decided.
  This corpus cites them and builds on them; it does not re-argue them.
- **Not a compliance checklist.** Where a control is cargo cult, this corpus says so and says why.
- **Not legal advice.** Kuwaiti and GCC data-protection questions that require counsel are marked
  `[UNKNOWN]` rather than guessed.

---

## The documents

| # | Document | What it answers |
|---|---|---|
| 1 | [`OVERVIEW.md`](OVERVIEW.md) | What the standards actually are, what a GCC SME buyer and their auditor will ask for, when each becomes a sales blocker, what it costs, and how PCI DSS scope is avoided rather than met |
| 2 | [`BEST_PRACTICES.md`](BEST_PRACTICES.md) | The practices that survive scrutiny — RLS as a security control, encryption that is required vs cargo-culted, authn/authz, secrets, immutable logs, AI security, adversarial tenancy testing |
| 3 | [`ANTI_PATTERNS.md`](ANTI_PATTERNS.md) | Compliance theatre, security by convention, ambient privilege, unaudited admin paths, secrets in env files, optimistic tenancy testing — with the mechanism of harm in each case |
| 4 | [`ARCHITECTURE.md`](ARCHITECTURE.md) | QAYD's target security architecture, the trust-boundary diagrams, and a threat model built around who actually attacks an accounting system |
| 5 | [`LESSONS_FOR_QAYD.md`](LESSONS_FOR_QAYD.md) | What QAYD already gets right and should not lose, what is weaker than it reads, and what the research changes |
| 6 | [`IMPLEMENTATION_RECOMMENDATIONS.md`](IMPLEMENTATION_RECOMMENDATIONS.md) | The sequenced plan — every item with a trigger, effort, confidence, and business impact; includes the three known gaps and the full hash-chain design for the dormant `audit_logs` columns |
| — | `README.md` | This file |

---

## Reading order

**If you have twenty minutes:** `OVERVIEW.md` §1 and §7 → `IMPLEMENTATION_RECOMMENDATIONS.md` §1 (the
Now tier). That is the decision-grade summary.

**If you are about to talk to a customer's security reviewer:** `OVERVIEW.md` §2–§4.

**If you are designing the audit hash chain:** `IMPLEMENTATION_RECOMMENDATIONS.md` §5 — it is a full
design, not a gesture at one, and it is the section the dormant `hash`/`prev_hash` columns are waiting
for.

**If you are writing a tenancy test:** `BEST_PRACTICES.md` §3 and `ANTI_PATTERNS.md` §6. Read both; the
anti-pattern is the more useful half.

**If you are wiring the AI document-ingestion path:** `BEST_PRACTICES.md` §7 and `ARCHITECTURE.md` §5.
This is the newest and least-settled threat surface in the system.

---

## Evidence grading

Every non-obvious claim carries a tag. This is the same scheme the knowledge base uses.

| Tag | Meaning |
|---|---|
| `[DOCS]` | Primary documentation or the standard itself, cited by URL and — where the claim is about a control — by control identifier or clause number |
| `[CODE]` | Verified by reading QAYD's own source or migrations; the path is named |
| `[COMMUNITY]` | Practitioner consensus, vendor pricing pages, industry surveys — useful, not authoritative |
| `[INFERENCE]` | Reasoned from the above; the reasoning is shown so it can be attacked |
| `[UNKNOWN]` | Could not be verified. Left as a question, never smoothed into an answer |

**On quoting standards.** Standards are public and their control identifiers are stable, so this corpus
cites them precisely — `ISO/IEC 27001:2022 A.8.15`, `PCI DSS v4.0.1 Req 6.4.3`, `NIST SP 800-53 Rev 5
AU-10`. Where the corpus describes what a control requires, that is a description and is written as one.
Nothing here is presented as verbatim standard text unless it is in quotation marks with a source.

---

## Cross-references into prior work

This corpus deliberately does not restate the following. Where a topic touches them, it cites and moves on.

| Prior work | What it already settles |
|---|---|
| `01_ENGINEERING_PRINCIPLES.md` P3 | Multi-tenancy is enforced by the database, never by application diligence |
| `01_ENGINEERING_PRINCIPLES.md` P5 · P6 · P7 | Append-only ledger, immutable posted journals, one way in |
| `01_ENGINEERING_PRINCIPLES.md` P15 | AI drafts, humans dispose, the database enforces it |
| `01_ENGINEERING_PRINCIPLES.md` P21 · P22 | Explainability; no ambient privilege, no `sudo()` |
| `01_ENGINEERING_PRINCIPLES.md` — gap register | **G-1, G-3, G-16, G-18, G-19, G-23** are the security-relevant open gaps. This corpus designs their resolution; it does not re-discover them |
| `04_REJECTED_PATTERNS.md` R-07 · R-14 · R-19 · R-31 · R-32 | Ambient bypass, raw SQL writes, security logic as evaluated code, AI writing to domain tables, trusting model output |
| `05_FUTURE_ARCHITECTURE.md` | The `SET LOCAL` / connection-pooler hazard and the scale tiers that trigger it |
| `09_ENGINEERING_PLAYBOOK.md` §7 | Security philosophy, the four-layer tenancy stack, the leak-response runbook |
| `TECH_DEBT.md` TD-01 · TD-04 · TD-06 · TD-07 · TD-09 | The honest open items; this corpus schedules them rather than rediscovering them |
| `docs/security/*.md` | The product security specification — ten documents this corpus supports rather than duplicates |

---

## The three known gaps

Named here so no reader has to hunt for them. They are analysed in `LESSONS_FOR_QAYD.md` §3 and
resolved in `IMPLEMENTATION_RECOMMENDATIONS.md` §2.

1. **`trg_no_ai_autopost` is `BEFORE INSERT` only** `[CODE]` —
   `apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php`. An AI-generated
   draft that is subsequently `UPDATE`d toward `posted` meets no trigger. The flagship AI-safety
   invariant is currently half-enforced. (Gap **G-3**.)
2. **`audit_logs` carries `OR app_is_platform_admin()` in a RESTRICTIVE boundary's `USING` *and*
   `WITH CHECK`** `[CODE]` — `2026_07_27_000010_create_audit_logs_table.php`. A platform-admin session
   can author audit rows attributed to any tenant, on the one table whose entire value is that it
   cannot be forged. (Gaps **G-18**, **G-23**.)
3. **`ledger_entries` retains vestigial update/delete policies and full CRUD grants** `[CODE]` —
   `2026_07_28_000007_create_ledger_entries_table.php`. Append-only rests on a single trigger while the
   privilege and policy layers describe a mutable table. (Gap **G-1**.)

---

## Keeping this true

A security document that has drifted is worse than none, because it is cited in a sales conversation.

- **Standards versions move.** PCI DSS v4.0.1, ISO/IEC 27001:2022, OWASP ASVS 5.0.0, NIST SP 800-63B-4
  and NIST CSF 2.0 are the versions studied here, with dates given at each citation. Re-verify before
  quoting any of them to a customer.
- **The GCC legal picture is actively moving.** The UAE PDPL executive regulations were unissued at the
  time of writing; Kuwait's CITRA regulation was replaced in 2024. Section `OVERVIEW.md` §6 states what
  was verifiable and marks the rest `[UNKNOWN]`. Do not let those `[UNKNOWN]`s quietly become claims.
- **Every gap closed must be struck from `IMPLEMENTATION_RECOMMENDATIONS.md`** and from the register in
  `01_ENGINEERING_PRINCIPLES.md`, in the same commit that closes it.
- **Anything marked *not built today* must become true or be removed.** The honesty is the point.
