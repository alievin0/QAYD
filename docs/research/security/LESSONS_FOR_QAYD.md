# Lessons for QAYD

**What the research changes, what it confirms, and what it contradicts.**

Written as findings, not as a summary. Each lesson states what QAYD does today, what the research says
about it, and what — if anything — should change. Where the answer is "nothing, keep doing it", that is
said briefly, because confirming a good decision is cheaper than re-litigating it.

---

## Contents

1. [What QAYD already gets right — and must not lose](#1-what-qayd-already-gets-right--and-must-not-lose)
2. [Where the research sharpens an existing decision](#2-where-the-research-sharpens-an-existing-decision)
3. [The three known gaps, re-read in light of the research](#3-the-three-known-gaps-re-read-in-light-of-the-research)
4. [Where QAYD is weaker than it reads](#4-where-qayd-is-weaker-than-it-reads)
5. [What the research contradicts](#5-what-the-research-contradicts)
6. [New findings the prior work does not contain](#6-new-findings-the-prior-work-does-not-contain)
7. [The commercial lessons](#7-the-commercial-lessons)
8. [The ten sentences](#8-the-ten-sentences)

---

## 1. What QAYD already gets right — and must not lose

Stated compactly. These are settled; the value of listing them is that each is a *decision that will come
under pressure later*, and knowing why it was made is what survives the pressure.

| Decision | Why the research confirms it |
|---|---|
| **Tenancy enforced by the database, not the application** (P3) | The decisive argument is not that RLS is more secure in the ideal case — a perfect application filter is equally secure. It is that the *failure modes are opposite*: a forgotten `where` returns everything; a missing policy returns nothing `[DOCS]`. Security properties must be judged against typical execution, not ideal execution |
| **Runtime role `NOSUPERUSER NOBYPASSRLS`** `[CODE]` | This one line is what converts SQL injection from total compromise into a tenant-scoped bug. It is the highest-leverage line in the codebase |
| **RESTRICTIVE company boundary** | RESTRICTIVE policies combine with AND `[DOCS]`, so no future permissive policy can widen the boundary. Most teams use PERMISSIVE and inherit a boundary that any later feature can loosen |
| **No ambient privilege** (P22, R-07) | The purest Zero Trust position available, arrived at without buying anything. Nearly every mature SaaS has a `sudo()` it regrets |
| **Append-only ledger, immutable posted journals** (P5, P6) | This is not only a correctness property. It is a **fraud control the owner buys against their own staff** — the T1 threat, which is the highest-expected-loss threat for an accounting system (`ARCHITECTURE.md` §4). It is the strongest product-security claim QAYD has |
| **Audit immutability enforced by trigger, not privilege alone** `[CODE]` | Tier 2 of 5 on the tamper-evidence hierarchy (`BEST_PRACTICES.md` §8.1) — above where most accounting SaaS sits, and defensible in a review today |
| **The AI service holds no database credentials** `[CODE]` | The only prompt-injection control that is not probabilistic. Everything else reduces likelihood; this bounds impact |
| **`SET LOCAL`, not `SET`** | Correct, and for a reason that is invisible until it is catastrophic |
| **A written enforcement-gap register** | The most unusual thing in the whole knowledge base. An organisation that records where its principles lack mechanisms is doing something almost nobody does, and it is what made this research possible in three days rather than three weeks |

---

## 2. Where the research sharpens an existing decision

Not corrections — refinements that give an existing decision a mechanism, a limit, or an argument it did
not have.

### 2.1 Composite uniques carrying `company_id` are a **security control**, not a modelling convention

`docs/security/TENANT_ISOLATION.md` requires composite unique constraints to include `company_id`. The
research supplies the reason, and the reason is stronger than the convention implies.

**Referential-integrity checks — unique constraints, primary keys and foreign-key references — always
bypass row security**, by design, and the PostgreSQL documentation notes this creates a covert channel
`[DOCS]` <https://www.postgresql.org/docs/current/ddl-rowsecurity.html>.

Consequently a unique constraint that omits `company_id` is a **cross-tenant existence oracle**: tenant B
inserts a value, receives a constraint violation, and has learned that tenant A holds it. No RLS policy
prevents this, and no conventional tenancy test detects it.

**What changes:** this moves from a convention to an introspection test (`BEST_PRACTICES.md` §1.4 item 5),
and to an adversarial test that actively attempts the oracle (§3.2). `[INFERENCE]` — high confidence; the
bypass is documented, the consequence follows directly.

### 2.2 `perms_ver` already solves TD-09, and better than a denylist would

TD-09 records that logout does not revoke the outstanding access JWT, with a planned resolution of a Redis
`jti` denylist.

`[INFERENCE]` QAYD already carries **`perms_ver` on `company_users`** for permission-cache invalidation
`[CODE]`. A token embedding the `perms_ver` it was minted under can be rejected on mismatch — no denylist,
no Redis dependency, no second mechanism to keep consistent. Bumping the version kills every outstanding
token for that membership instantly, which is *also* what must happen on permission change, role removal
and offboarding.

**What changes:** TD-09's planned resolution should be reconsidered. One mechanism serving two purposes is
better than two mechanisms that must agree. Confidence medium — the interaction with token minting needs
design review, and there is a real cost (a token check now depends on a lookup, which is what the denylist
also costs).

### 2.3 The `SET LOCAL` hazard needs a test, not more documentation

Playbook §7.4 documents the hazard exceptionally well and says testing against a real pooler is
outstanding. The research adds *why documentation cannot be the control here*: the hazard has three
properties that jointly defeat discipline `[INFERENCE]` — invisible in single-connection testing, silent
and correct-looking in failure, and **introduced by an infrastructure change rather than a code change**.

That third property is the one that matters. Someone will enable PgBouncer in transaction mode for a good
performance reason, on a busy day, in a config file that is not reviewed as security-relevant. No amount of
documentation in the engineering playbook is in that person's path.

**What changes:** the specific test design at `BEST_PRACTICES.md` §2.2 — PgBouncer in transaction mode with
`default_pool_size = 1`, alternating tenants, plus the inverse test proving the test can fail. Effort 5.

### 2.4 Fail-closed is only safe if the failure is **loud**

RLS fail-closed is correct and is stated as a strength throughout the prior work. The research adds the
failure mode of the strength `[INFERENCE]`: a path with no tenant context returns *zero rows*, which
presents as a bug rather than a security event. The fastest fix available to a developer under pressure is
to add a bypass.

**Every ambient-privilege bypass in every system began as a silently-empty result someone needed to fix.**

**What changes:** a missing tenant context must **raise**, not return empty. That is a small change to the
tenant-context helper and it removes the single most reliable path by which A-3 gets introduced.

---

## 3. The three known gaps, re-read in light of the research

The gaps are known and registered. The research changes their **priority ordering** and, in one case, the
argument for the fix.

### 3.1 G-3 — `trg_no_ai_autopost` is `BEFORE INSERT` only

`[CODE]` — `apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php`. The trigger
fires on `INSERT` and refuses `NEW.ai_generated AND NEW.status <> 'draft'`. An AI-generated row inserted as
a draft and subsequently `UPDATE`d toward `posted` meets no trigger at all.

**The register scores this at effort 2 and priority Now.** The research says it is **the single most
important item in this corpus**, and the reason is not the one in the register.

`[INFERENCE]` The register frames it as "the flagship principle is enforced on INSERT only" — a consistency
argument. The threat-model framing is stronger: **this trigger is the terminal control on prompt injection**
(`ARCHITECTURE.md` §5). Every other AI-security control — extraction/reasoning separation, output
validation, provenance display, hidden-content scanning — reduces the *likelihood* that a malicious
document produces a bad proposal. This trigger is the only control that bounds the *impact* regardless of
what the model was told. And it is the control that will be under most pressure as the AI features ship,
because it is the one that says "no" to an automation someone wants.

The `UPDATE` variant must also handle the subtler cases: a non-AI row being flipped to `ai_generated`, and
an AI row whose `approved_by` is set by the same actor that created it.

**Verdict: do this first. Effort 2, confidence high, and the business impact is that QAYD's central AI
safety claim becomes true.**

### 3.2 G-18 — the `audit_logs` platform-admin write hatch

`[CODE]` — `2026_07_27_000010_create_audit_logs_table.php`. The RESTRICTIVE boundary carries
`OR app_is_platform_admin()` in `USING` **and** `WITH CHECK`.

The register pairs this with G-23 and scores 4 points combined. The research adds three things:

1. **`USING` and `WITH CHECK` are not the same defect.** A read hatch for platform diagnostics is a
   defensible design decision — TD-04 explicitly defers exactly that. **A write hatch on the audit table is
   not defensible under any framing**, because it means a platform session can author audit rows attributed
   to any tenant. Splitting the two is the first move: remove `WITH CHECK` unconditionally, and treat
   `USING` as a separate, deliberate design question.
2. **Its position in a RESTRICTIVE policy makes it maximally bad.** RESTRICTIVE policies exist to be the
   floor nothing can widen `[DOCS]`. An `OR` inside one is a hole in the mechanism whose entire purpose is
   to have no holes — and no later policy can close it, because AND/OR composition does not work that way.
3. **It must be closed *before* the hash chain is built, not merely alongside it.** A hash chain over
   forgeable entries is a cryptographic guarantee of the integrity of a lie (`ANTI_PATTERNS.md` §A-7). This
   turns a loose priority pairing into a hard sequencing constraint: **G-18 → G-16.**

**Verdict: second. Effort 3 including G-23. The structural fix (G-23) matters as much as the fix, because
the defect merged for the ordinary reason that nothing said it could not.**

### 3.3 G-1 — `ledger_entries` vestigial policies and grants

`[CODE]` — `2026_07_28_000007_create_ledger_entries_table.php` creates `ledger_entries_tenant_update` and
`ledger_entries_tenant_delete` policies while `trg_ledger_entries_append_only` refuses both operations.

The register scores this 1 point and calls it alignment. The research agrees on the effort and adds the
argument for why a 1-point cosmetic change is worth doing before larger work `[INFERENCE]`:

**Three mechanisms currently describe two different tables.** The trigger says append-only; the policies and
grants say mutable. Any engineer reading the schema — or any auditor, or any future contributor deciding
whether an update path exists — gets a contradictory answer. Defence in depth requires the layers to
*agree*; layers that disagree are not depth, they are a latent regression waiting for someone to drop the
trigger during a migration and discover the other two layers were never load-bearing.

**Verdict: third, and trivially cheap. `REVOKE UPDATE, DELETE` from the app role and drop the two
unreachable policies.**

---

## 4. Where QAYD is weaker than it reads

`[INFERENCE]` The prior work is unusually honest, so this section is short. What it contains is the gap
between *architectural* strength and *operational* strength — a gap that documentation naturally hides,
because architecture is what gets documented.

| Reads as | Actually |
|---|---|
| "Defence in depth, all four layers live" | Correct for reads under HTTP. **Untested** under a connection pooler, and **unaddressed** for queue jobs and console commands — the paths where context is remembered rather than enforced |
| "The audit trail records everything" | Posting writes **no** audit row (TD-16). Draft mutations write none (TD-12 #2). The trail begins later than the sentence implies |
| "AI cannot post" | True on `INSERT`, false on `UPDATE` (G-3) |
| "Append-only ledger" | True by trigger; contradicted by grants and policies (G-1) |
| "No ambient privilege" | True for reads; **false for audit writes** (G-18) |
| "Tenant isolation is tested" | Tested optimistically. The generated, adversarial suite does not exist (`ANTI_PATTERNS.md` §A-6) |
| "Hash chain columns are present so it can be added without a rewrite" | Accurate — but a schema reader sees `hash`/`prev_hash` and concludes the log is chained. The columns are a **claim**, currently unbacked |
| Strong security posture overall | Strong **architecture**; near-absent **detection** and **response**. When prevention fails, nothing notices — and the CITRA 24-hour clock starts at *awareness* |

**The pattern is consistent and worth stating once:** QAYD answers the hard security questions better than
it answers the easy ones. MFA, an incident runbook, a tested restore and a security page are all cheap, all
asked in every customer review, and all missing.

---

## 5. What the research contradicts

Two items where the research disagrees with a reasonable prior position.

### 5.1 Do not buy an attestation. Not yet, and possibly not the one you expect

`[INFERENCE]` There is a natural instinct, entering a market with enterprise ambitions, to treat SOC 2 as
table stakes. The research says otherwise on two counts:

- **Timing.** A SOC 2 Type II opines on operating effectiveness *over a period* `[DOCS]`. With zero
  customers there is nothing to observe. All-in first-year cost is $30–60k plus significant engineer time
  `[COMMUNITY]` — which is more than the entire remaining security backlog in this corpus costs to close.
  The rational trigger is a **named deal blocked on it, worth more than the audit** (`OVERVIEW.md` §7).
- **Which one.** `[UNKNOWN]` but consequential: ISO/IEC 27001 is plausibly the more recognised instrument
  in GCC procurement, whereas SOC 2 is a US CPA product. If QAYD's first attestation-demanding customer is
  a Kuwaiti or Saudi enterprise, ISO may well be the ask. **This question is resolvable by asking three
  prospects and is not resolvable by research.** One email settles a $40,000 decision.

### 5.2 "Encrypt sensitive fields" is the wrong default

`[INFERENCE]` Broad field-level encryption defeats no realistic adversary in a single-service architecture —
the application holds the key — while destroying indexing, sorting and aggregation. For QAYD it would
directly conflict with P-10, which requires money aggregation in the database precisely because PHP-side
arithmetic is a correctness hazard.

The correct scope is narrow: third-party credentials, bank tokens, national identifiers — plus the
crypto-shredding set. Everything else gets TLS and volume encryption, and an honest statement of what that
buys. `BEST_PRACTICES.md` §4.

---

## 6. New findings the prior work does not contain

The six things this research produced that are not anywhere in the knowledge base or the security specs.

### 6.1 The referential-integrity covert channel

§2.1 above. `[DOCS]` A constraint-based cross-tenant existence oracle that no RLS policy prevents and no
conventional test detects. It converts a modelling convention into a security control and generates a
specific test.

### 6.2 Crypto-shredding resolves §T5 (erasure vs immutability) without weakening either side

The principles record the tension between immutable history and a right to erasure (§T5) and leave it open.
`[INFERENCE]` The resolution: encrypt erasable *personal* data under a per-subject key; erasure destroys the
key, not the row. Ciphertext stays, references stay, totals stay, the chain stays. Financial facts — amounts,
dates, accounts, structure — are never in the encrypted set because they are not personal data and must
survive statutory retention.

**The decision that must be made now** is the *boundary*: which columns are personal-and-erasable. Made at
table-creation time it costs nothing. Retrofitted it means re-encrypting a live corpus and discovering a
name inside a JSONB blob. This is the one item in this corpus whose cost of delay is genuinely
superlinear, and no regulator has asked yet — which is exactly why it will be forgotten.

### 6.3 A hash chain over forgeable entries is worse than no chain

`[INFERENCE]` `ANTI_PATTERNS.md` §A-7. Chaining guarantees the log was not *altered*; it says nothing about
whether entries were *truthful when written*. This produces a hard sequencing constraint (G-18 before G-16)
that neither the gap register nor `docs/security/AUDIT_LOGS.md` states.

### 6.4 The tamper-evidence hierarchy, and where QAYD actually sits

`BEST_PRACTICES.md` §8.1 — five tiers, each defeating a strictly stronger adversary. QAYD is at **Tier 2**.
The dormant columns promise Tier 3. Tier 4 (a signed periodic anchor) is where the guarantee becomes
meaningful against a competent attacker, and it is reachable cheaply.

**The finding inside the finding:** the *verification job is the control*; the chain is only the data
structure that makes it possible. An unverified chain provides exactly the assurance of no chain, while
being described as though it provides more.

### 6.5 Extraction/reasoning separation is the highest-leverage AI security control

`[INFERENCE]` `ARCHITECTURE.md` §5. If extraction emits a **typed structure** and the proposing step sees
only that structure, prose from an attacker-authored document can never reach the step that decides
accounting treatment. One extra model call eliminates most of the practical attack surface — far more than
any filter, and unlike a filter it cannot be evaded.

Paired with it: the **historical-departure check** — a deterministic comparison against how this tenant has
coded this vendor before. It catches the realistic attack, requires no model, cannot be prompted around, and
is a good product feature independent of security.

### 6.6 The Kuwait 24-hour breach clock

`[DOCS]` The CITRA regulation requires notification to CITRA **and** to affected individuals **within 24
hours** of becoming aware — materially tighter than GDPR's 72. Notification to individuals may be waived
where appropriate technical and organisational measures were effectively applied.

`[UNKNOWN]` whether a B2B accounting SaaS without a CITRA licence is in scope. The regulation is framed
around telecom-sector service providers, but the definition reportedly extends to anyone operating a
website, application or cloud service processing personal data. **This is a question for Kuwaiti counsel and
it is cheap to ask.**

`[INFERENCE]` The operational consequence holds either way: a 24-hour clock cannot be met by improvisation.
It requires a pre-written runbook, a pre-drafted notification, and the ability to determine blast radius
quickly — which is what `audit_logs` is *for*. **This is an argument for finishing the audit trail before
the first customer that is entirely independent of any attestation.**

---

## 7. The commercial lessons

`[INFERENCE]` Security work that also sells is worth more than security work that only protects.

**QAYD's architecture produces claims most competitors cannot make.** Database-enforced tenant isolation
under a role that cannot bypass it; an append-only ledger; posted journals that are immutable in the
storage engine; an audit log the table owner cannot rewrite. These are specific, verifiable and unusual.
A technical reviewer finds them more persuasive than a badge — and specificity is precisely what a
questionnaire response is usually missing.

**Three of them are also product features.** Immutability is a fraud control the owner buys against their
own staff. A daily signed chain head delivered to the customer is "your books are sealed daily and you hold
the seal" — a claim no SME accounting competitor makes. The historical-departure check on AI proposals is a
feature a user would want with no adversary at all. `[INFERENCE]` Security controls that are also features
get built, and stay built, because someone other than the security-minded engineer wants them.

**The cheapest commercial wins are operational, not architectural.** MFA, a written incident runbook, a
tested restore and an honest security page are the things asked in every customer review, cost days rather
than months, and are all currently missing (`OVERVIEW.md` §8).

**And the discipline that protects all of it:** the security page must describe what is built, in the present
tense, and nothing else. Diluting genuinely exceptional true claims with ordinary false ones is a bad trade
in both directions (`ANTI_PATTERNS.md` §A-10).

---

## 8. The ten sentences

If nothing else from this corpus survives:

1. **Close G-3 first** — the `INSERT`-only AI trigger is the terminal control on prompt injection, not a
   consistency nit.
2. **Close G-18 before building the hash chain** — a chain over forgeable entries is worse than no chain.
3. **RLS is an excellent containment control and a poor authorization control**; know which job it is doing.
4. **A composite unique without `company_id` is a cross-tenant oracle**, because referential-integrity
   checks always bypass row security.
5. **Fail-closed must be loud** — a silently-empty result is how every ambient-privilege bypass begins.
6. **Test the tenancy boundary adversarially and generatively**, or you have documented it rather than
   enforced it.
7. **Separate document extraction from accounting reasoning** so that attacker prose never reaches the step
   that proposes an entry.
8. **The chain's verification job is the control**; the chain is only the data structure.
9. **Decide the crypto-shredding boundary at schema-design time** — it is the only item here whose cost of
   delay is superlinear.
10. **Buy no attestation until a named deal is blocked on one** — and ask that customer whether they want
    SOC 2 or ISO 27001 rather than guessing.
