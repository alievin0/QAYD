# Implementation Recommendations

**Sequenced, with triggers. Every item carries effort, confidence and business impact.**

Ordered so that each item's prerequisite is above it. Nothing here is scheduled by date — every tier has an
**entry trigger**, so the plan does not go stale when the roadmap moves.

**Effort** is Fibonacci story points on QAYD's existing scale (`08_MASTER_BACKLOG.md`).
**Confidence** is in the recommendation, not in the estimate.
Items that already exist in the enforcement-gap register keep their **G-** identifier; new items are **S-**.

---

## Contents

1. [Tier 0 — Now, before anything else](#tier-0--now-before-anything-else)
2. [Tier 1 — The three known gaps](#tier-1--the-three-known-gaps)
3. [Tier 2 — Before the first customer](#tier-2--before-the-first-customer)
4. [Tier 3 — Before real books](#tier-3--before-real-books)
5. [The hash-chain design](#5-the-hash-chain-design)
6. [Tier 4 — Before the AI ingestion path ships](#tier-4--before-the-ai-ingestion-path-ships)
7. [Tier 5 — Before pooling, and before the first support tool](#tier-5--before-pooling-and-before-the-first-support-tool)
8. [Tier 6 — Attestation, when a deal requires it](#tier-6--attestation-when-a-deal-requires-it)
9. [Summary table](#9-summary-table)

---

## Tier 0 — Now, before anything else

**Trigger:** none. These are open today and each costs under a day.

### S-1 · Ask Kuwaiti counsel whether QAYD is in CITRA scope

| | |
|---|---|
| **Why** | The CITRA Data Privacy Protection Regulation (Administrative Decision No. 26 of 2024) imposes a **24-hour breach-notification obligation** to CITRA and to affected individuals `[DOCS]`. Whether a B2B accounting SaaS without a CITRA licence is in scope is `[UNKNOWN]` — the regulation is framed around telecom-sector service providers, but the definition reportedly extends to anyone operating a website, application or cloud service processing personal data |
| **Benefit** | Converts the largest `[UNKNOWN]` in this corpus into a fact. Determines whether the incident runbook needs a 24-hour or a best-effort clock |
| **Tradeoff** | A lawyer's fee, and the possibility of an inconvenient answer |
| **Risk of not doing it** | Discovering the obligation during an incident, which is the worst possible time |
| **Effort** | **1** (one email) · **Confidence** high · **Impact** high — it is the cheapest item in this document and gates S-6 |

### S-2 · Verify backup restore, with a stopwatch

| | |
|---|---|
| **Why** | Ransomware (T2) is the highest tail-risk threat and the least-defended (`ARCHITECTURE.md` §4). An untested backup is a hypothesis. For a system holding statutory books, unrecoverable loss is existential for the customer as well as QAYD |
| **Do** | Restore production to a scratch environment. Time it. Verify the trial balance reconciles. Confirm backups are encrypted, that the key is held separately from the backup store, and that production credentials cannot delete or overwrite backup history |
| **Tradeoff** | Half a day, and the scratch environment is itself a full copy of tenant data in a weaker environment — destroy it afterwards (`ANTI_PATTERNS.md` §A-4) |
| **Effort** | **3** · **Confidence** high · **Impact** existential-risk reduction. Best value/effort ratio in this document |

### S-3 · Write the incident runbook

| | |
|---|---|
| **Why** | Playbook §7.6 covers a suspected tenant leak well. There is no general runbook: named owner, contact path, evidence preservation, blast-radius determination, customer-notification decision criteria, a pre-drafted notification. If S-1 returns "in scope", the 24-hour clock makes this mandatory rather than prudent |
| **Two properties that decide whether it works** | It must be written before it is needed, because it will be executed by someone tired and frightened. It must be rehearsed once, because an unrehearsed runbook has a step that does not work and nobody knows which |
| **Effort** | **3** · **Confidence** high · **Impact** high — it changes the outcome of a bad day more than any preventive control at this price |

### S-4 · Make a missing tenant context raise, not return empty

| | |
|---|---|
| **Why** | Fail-closed is only safe if the failure is loud. A path with no tenant GUC returns zero rows, which presents as a bug; the fastest available fix is a bypass. **Every ambient-privilege bypass in every system began as a silently-empty result someone needed to fix** `[INFERENCE]` |
| **Do** | The tenant-context helper asserts the GUC is set and throws a typed error if not. Applies to HTTP, jobs and console commands |
| **Tradeoff** | Some genuinely context-free paths must now declare themselves — which is the point |
| **Effort** | **2** · **Confidence** high · **Impact** removes the most reliable route by which `ANTI_PATTERNS.md` §A-3 gets introduced |

---

## Tier 1 — The three known gaps

**Trigger:** none. These are open defects in shipped code, and their internal order is a hard dependency,
not a preference.

### G-3 · Extend `trg_no_ai_autopost` to `UPDATE` — **do this first**

| | |
|---|---|
| **Defect** | `[CODE]` `apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php` — the trigger is `BEFORE INSERT` only. An AI-generated row inserted as a draft and then `UPDATE`d toward `posted` meets no database control at all |
| **Why first** | The gap register frames this as a consistency issue. It is not. **This trigger is the terminal control on prompt injection** (`ARCHITECTURE.md` §5). Every other AI-security control reduces the *likelihood* that a poisoned document yields a bad proposal; this one bounds the *impact* regardless of what the model was told. It is also the control that will be under most pressure as AI features ship, because it is the one that refuses an automation someone wants |
| **Do** | A `BEFORE UPDATE` trigger enforcing three things, not one: (a) an `ai_generated` entry may not reach `posted` unless `approved_by IS NOT NULL`; (b) `ai_generated` may not be flipped from true to false — an AI origin is not erasable; (c) `approved_by` may not equal the entry's creator when `ai_generated` is true, so approval is a second pair of eyes rather than a self-attestation |
| **Tradeoff** | Clause (c) is a policy decision with a workflow cost. If a single-user tenant is a supported case it needs an explicit, audited exemption — not a silent one |
| **Scalability / performance** | Row-level trigger on a low-frequency table. Negligible |
| **Maintainability** | Adds one function and one trigger next to an existing pair. The `UPDATE` variant must be tested for each clause independently |
| **Risk of not doing it** | QAYD's central AI-safety claim — publicly stated, architecturally load-bearing — is currently false on one path |
| **Effort** | **2** · **Confidence** high · **Impact** **highest in this document** |

### G-18 + G-23 · Remove the `audit_logs` platform write hatch, and make its return impossible

| | |
|---|---|
| **Defect** | `[CODE]` `2026_07_27_000010_create_audit_logs_table.php` — the RESTRICTIVE boundary carries `OR app_is_platform_admin()` in `USING` **and** `WITH CHECK`. A platform-admin session can author audit rows attributed to any tenant, on the table whose entire value is that it cannot be forged |
| **Split the defect in two** | **`WITH CHECK` — remove unconditionally.** There is no framing in which a cross-tenant *write* hatch on the audit table is defensible. **`USING` — a separate, deliberate decision.** A read hatch for platform diagnostics is a legitimate design question that TD-04 explicitly defers; resolve it as part of G-19 rather than by leaving an `OR` in a RESTRICTIVE policy |
| **Why here in the order** | **G-18 must precede G-16.** A hash chain over forgeable entries is a cryptographic guarantee of the integrity of a lie (`ANTI_PATTERNS.md` §A-7). This is a sequencing constraint, not a preference |
| **Why the position matters** | RESTRICTIVE policies exist to be the floor nothing can widen `[DOCS]`. An `OR` inside one is a hole in the mechanism whose purpose is to have none — and no later policy can close it, because AND/OR composition does not work that way |
| **G-23 — the structural half** | Extend the catalog-introspection test: **no RESTRICTIVE boundary policy may reference a session GUC other than the tenant id.** This is what would have caught the defect at review. Without it the fix is a point repair of a class of mistake |
| **Tradeoff** | Platform events legitimately carry `company_id IS NULL` `[CODE]`. The replacement predicate must permit those without permitting cross-tenant attribution — write them under a distinct, narrow policy rather than a general admin `OR` |
| **Effort** | **3** (G-18 2 + G-23 1) · **Confidence** high · **Impact** removes the one live P22 violation and unblocks the hash chain |

### G-1 · Align `ledger_entries` grants and policies with the append-only trigger

| | |
|---|---|
| **Defect** | `[CODE]` `2026_07_28_000007_create_ledger_entries_table.php` creates `ledger_entries_tenant_update` and `ledger_entries_tenant_delete` policies, and the role retains full CRUD, while `trg_ledger_entries_append_only` refuses both |
| **Why it matters at 1 point** | **Three mechanisms currently describe two different tables.** Defence in depth requires the layers to agree; layers that disagree are not depth. A migration that drops the trigger would reveal that the other two layers were never load-bearing |
| **Do** | `REVOKE UPDATE, DELETE ON ledger_entries` from the app role; drop the two unreachable policies. Add the introspection assertion so the state is verified rather than remembered |
| **Effort** | **1** · **Confidence** high · **Impact** makes the strongest product claim structurally true |

---

## Tier 2 — Before the first customer

**Trigger:** a real customer's data is about to enter the system.

### S-5 · MFA

| | |
|---|---|
| **Why** | Credential compromise (T3) is the **most likely** threat and QAYD's weakest chain — prevention absent, detection absent, response delayed. TD-08 records it as unimplemented. A single compromised owner account grants complete access to an SME's books |
| **Do** | TOTP as the default. SMS as an explicit fallback where GCC SME users expect it, documented as weaker `[INFERENCE]`. WebAuthn/passkeys as the later phishing-resistant target — the only factor that defeats adversary-in-the-middle kits |
| **Alongside it** | Adopt NIST SP 800-63B-4 password rules `[DOCS]`: 8-character floor (15 recommended), 64 accepted, **no composition rules**, **no periodic expiry**, blocklist screening. Make policy **tenant-configurable data**, not a constant — an SME's auditor may demand 90-day expiry regardless, and QAYD should be able to satisfy the request without believing in it |
| **Tradeoff** | Recovery flows are the hard part and the part that gets rushed. A weak reset path makes MFA decorative |
| **Effort** | **8** · **Confidence** high · **Impact** highest-value unbuilt preventive control |

### S-6 · Security page and questionnaire pack

| | |
|---|---|
| **Why** | Asked in every customer review; answers the mid-market questionnaire that would otherwise trigger a premature SOC 2 purchase (`OVERVIEW.md` §7) |
| **Do** | Present tense for what is built, explicit roadmap framing for what is not. Prefer specific claims over categorical ones — *"tenant isolation is enforced by PostgreSQL row-level security under a database role that cannot bypass it"* beats *"bank-grade security"* with any technical reviewer, and is defensible. Maintain a completed CAIQ or SIG-Lite alongside it |
| **Risk** | `ANTI_PATTERNS.md` §A-10. Diluting genuinely exceptional true claims with ordinary false ones is a bad trade in both directions |
| **Effort** | **3** · **Confidence** high · **Impact** direct sales value |

### S-7 · CI security gates

| | |
|---|---|
| **Do** | Dependency vulnerability scanning; secret scanning (already present via GitGuardian with a correctly narrow exclusion `[CODE]`); **G-8** — a grep asserting the AI service declares no database driver, converting today's strongest AI boundary from an absence into a guarantee (**effort 1**); **G-20** — a grep banning `withoutGlobalScopes` and unscoped raw queries on tenant tables (**effort 2**) |
| **Why G-8 is worth naming separately** | The AI service's credential-lessness is currently a fact about what nobody added. One `composer require` undoes it silently |
| **Effort** | **3** total · **Confidence** high · **Impact** converts three conventions into controls |

### S-8 · Decide the crypto-shredding boundary

| | |
|---|---|
| **Why** | **The only item in this corpus whose cost of delay is genuinely superlinear.** Deciding which columns are personal-and-erasable costs nothing at table-creation time; retrofitting means re-encrypting a live corpus and discovering a name embedded in a JSONB blob |
| **Do** | Not the implementation — the **decision and the convention**. Classify every column that will hold personal data as erasable; establish that erasable data is encrypted under a per-subject key wrapped by a KMS master key; establish that financial facts (amounts, dates, account references, journal structure) are **never** in the encrypted set. Record it as an ADR and add it to the table-creation checklist |
| **Why it matters** | Resolves §T5 — immutable history versus a right to erasure — without weakening either. Erasure destroys the key, not the row; ciphertext, references, totals and the hash chain are untouched (`BEST_PRACTICES.md` §4.5) |
| **Tradeoff** | A discipline imposed on every future table for a regulator who has not yet asked. That is the trade, and it is a good one |
| **Effort** | **3** for the decision; the implementation arrives with the first personal-data table · **Confidence** high · **Impact** avoids a migration nobody wants to perform |

---

## Tier 3 — Before real books

**Trigger:** a customer's actual accounting data is in the system.

### S-9 · The adversarial tenancy suite

| | |
|---|---|
| **Why** | `ANTI_PATTERNS.md` §A-6 — the current suite tests the control, not the system, and is static while the system grows |
| **The defining property** | **Most tests are generated, not written** — from the route table, from `pg_class`, from `pg_policies`, from the model registry. A hand-written suite covers what its author remembered; a generated suite fails when someone adds the thing that would have broken it |
| **Contents** | Direct object reference (404 not 403) across every route; context absence; context confusion; **constraint oracles**; connection escape (TD-01); privilege introspection including G-23; async paths |
| **The measurable standard** | A tenant table without `FORCE`, a route without tenant scoping, or a composite unique without `company_id` **must fail CI** |
| **The test nothing else finds** | The constraint oracle. Referential-integrity checks always bypass row security `[DOCS]`, so a unique constraint omitting `company_id` leaks cross-tenant existence. No amount of "B cannot see A's rows" testing detects it |
| **Effort** | **8** · **Confidence** high · **Impact** converts the strongest architectural claim from asserted to verified |

### S-10 · Complete the audit trail before chaining it

| | |
|---|---|
| **Why** | TD-16: posting writes no audit row. TD-12 #2: draft mutations write none. Chaining an incomplete log produces integrity over a partial record — and completeness is what the 24-hour blast-radius question depends on |
| **Do** | `AuditLogger::record` inside the posting transaction; audit draft create/update/submit; populate `actor_service` for console commands and scheduled jobs |
| **Effort** | **5** · **Confidence** high · **Impact** prerequisite for §5 |

### S-11 · Minimum detection

| | |
|---|---|
| **Why** | `ARCHITECTURE.md` §7. Preventive controls are strong; when they fail, nothing notices — and the CITRA clock starts at *awareness* |
| **Do, in order of value** | Auth anomalies (impossible travel, failure bursts, new country); **RLS/grant drift** — a scheduled introspection diff against an expected baseline, which is the only control that catches the realistic degradation path of a migration quietly weakening the model; bulk-export volume; AI spend anomaly |
| **Design rules** | Alert only on things that should be **rare**; write the response before enabling the alert; **dead-man's-switch every job**, because a job that stops running emits no alerts and that is indistinguishable from success (`ANTI_PATTERNS.md` §A-12) |
| **Effort** | **5** · **Confidence** high · **Impact** the missing layer |

---

## 5. The hash-chain design

**Gap G-16. This is the section the dormant `hash`/`prev_hash` columns are waiting for.**

**Trigger:** after G-18 and S-10. Not before — the sequencing is a correctness constraint, not a preference
(`ANTI_PATTERNS.md` §A-7).

### 5.1 What it is for, stated honestly

The chain moves QAYD from **Tier 2 to Tier 3** on the tamper-evidence hierarchy (`BEST_PRACTICES.md` §8.1):
an attacker's cost for altering the log rises from one `UPDATE` to rewriting the entire tail of that
tenant's chain. The **signed anchor** (§5.7) is what reaches **Tier 4**, where a competent attacker with
database access can no longer rewrite history undetectably.

It does **not** prevent tampering. "Tamper-evident" is the correct word; "tamper-proof" is a lie. And it
provides exactly the assurance of no chain if nothing verifies it — **the verification job is the control;
the chain is only the data structure that makes the control possible.**

### 5.2 Chain scope: per tenant

One chain per `company_id`. `[INFERENCE]`

- A global chain leaks cross-tenant information — row counts and activity timing become observable to
  anyone verifying.
- A global chain couples verification: one tenant's corruption invalidates everyone's proof.
- Per-tenant chains can be handed to that tenant for independent verification, which is the feature
  (§5.7).

`company_id IS NULL` platform rows form **one additional chain** under a reserved sentinel. They must be
chained — platform events are exactly what an attacker covering their tracks would edit.

### 5.3 Canonical serialisation — where naive implementations fail

The hashed payload must be **byte-identical on every re-derivation, forever, across PostgreSQL versions
and across any language a verifier is written in.** This is the requirement that breaks implementations,
and it breaks them *later* — during an audit, when the chain will not verify and nobody knows why.

Rules:

| Rule | Reason |
|---|---|
| Fixed field list, fixed order, defined in a versioned document | Adding a column must not change historical hashes |
| Explicit field separator that cannot occur in a field value | Otherwise `("ab","c")` and `("a","bc")` hash identically |
| `NULL` encoded distinctly from empty string | They are different facts |
| JSONB (`old_values`, `new_values`) serialised with **sorted keys and no insignificant whitespace** | PostgreSQL's `jsonb` does not preserve key order; `::text` output is not a stable contract |
| Timestamps in a fixed format at a fixed precision, UTC | Locale and precision drift silently |
| Numerics in a canonical decimal form | `1.50` and `1.5` must not differ |
| `TEXT[]` (`changed_fields`) serialised with defined ordering and escaping | Array text output is not a hashing contract |
| **A version tag inside the hashed payload** | When the format must change, old rows verify under the old rule and new rows under the new. Without this, any format change invalidates all history |

**Include in the payload:** the version tag, `id`, `company_id`, `category`, `action`, `entity_type`,
`entity_id`, `actor_user_id`, `actor_service`, `acting_as_user_id`, `old_values`, `new_values`,
`changed_fields`, `reason`, `request_id`, `created_at`, and `prev_hash`.

**Exclude:** `hash` itself, and anything mutable. Nothing in `audit_logs` is mutable — the trigger
guarantees it `[CODE]` — which is precisely what makes the table chainable.

`hash = SHA-256(canonical_payload)`, hex, 64 characters — matching the existing `CHAR(64)` columns `[CODE]`.

### 5.4 Computed by a trigger, never by the application

A `BEFORE INSERT` trigger on `audit_logs`:

1. Take the chain lock for this `company_id` (§5.5).
2. Read `hash` from the most recent row for this `company_id`; use the defined genesis constant if none.
3. Set `NEW.prev_hash` to that value.
4. Compute `NEW.hash` over the canonical payload including `prev_hash`.
5. Return `NEW`.

`[INFERENCE]` **A chain the application computes is a chain the application can skip** — and the
application is the component most likely to be compromised. Placing it in the trigger means every write
path is chained: `AuditLogger`, a future outbox, a shadow-capture trigger (G-15), a migration, and a human
at `psql`. **That last one is the point.**

Use `pgcrypto`'s `digest()`. `[INFERENCE]` The trigger must have a defined behaviour if `pgcrypto` is
absent: **fail the insert.** Silently writing an unchained row would be the worst available outcome.

### 5.5 Concurrency

Two concurrent inserts for the same tenant must not read the same `prev_hash` and fork the chain.

- **Primary:** `pg_advisory_xact_lock(hashtext('audit_chain'), company_id)` at the top of the trigger.
  Transaction-scoped, released at commit, serialising only same-tenant appends.
- **Backstop:** a `UNIQUE` index on `(company_id, prev_hash)`. If the lock is ever bypassed, a fork
  **fails loudly** instead of producing two rows claiming the same predecessor. `[INFERENCE]` This is the
  more important of the two, because a lock can be removed by someone optimising and a constraint cannot be
  removed accidentally.

**Performance:** appends per tenant serialise. `[INFERENCE]` Acceptable — audit writes are already inside
the business transaction, and per-tenant audit volume at SME scale is low. **Trigger metric:** if
same-tenant audit-write contention becomes measurable, batch within the transaction and chain the batch,
rather than removing the lock.

### 5.6 Verification — this is the control

Two jobs. The chain is worthless without them.

**Incremental (frequent).** For each tenant, verify from the last known-good sequence to the head:
`prev_hash` links correctly and each `hash` re-derives. Cheap, bounded, runs often.

**Full (periodic).** Verify each chain from genesis. Catches a rewrite that also updated the last-known-good
marker.

Both must:

- **Alert on divergence**, naming tenant and row id — and treat it as a live incident, not a job failure.
- **Record that they ran**, to a location outside `audit_logs`.
- **Be monitored for not running.** A verification job that silently stops emits no alerts, which is
  indistinguishable from success. Dead-man's-switch alerting is the only form that catches it
  (`ANTI_PATTERNS.md` §A-12).

**Third-party verifiability is a hard requirement.** An auditor with read access and the published
serialisation rule must be able to verify the chain **without QAYD's application.** That property is what
makes the chain worth anything in an audit, and it is why §5.3 must be a versioned public document rather
than a comment in a migration.

### 5.7 Anchoring — Tier 3 → Tier 4

The chain alone defeats a careless attacker. Someone with database access and the hash function can
regenerate a perfectly consistent chain from any point. An **anchor** is a chain head recorded somewhere
QAYD cannot retroactively change.

In ascending cost `[INFERENCE]`:

1. **Sign the periodic head** with a key held outside the database, ideally in a KMS with its own use log.
   An attacker who rewrites the chain cannot produce a valid signature over the new head. **Highest value
   per unit of effort by a wide margin — do this one.**
2. **Deliver the daily head to the customer**, in-app and by email. Cheap, and it makes the customer an
   unwitting notary. **It is also the best security marketing available to QAYD:** *your books are sealed
   daily and you hold the seal* is a claim no SME accounting competitor makes.
3. **RFC 3161 trusted timestamping** `[DOCS]` — a time-stamping authority signs the hash with a trusted
   time. Standard, understood by auditors, modest cost. Add when a regulated customer asks.
4. **Merkle-tree structure with consistency proofs**, in the manner of RFC 6962 `[DOCS]`
   <https://www.rfc-editor.org/rfc/rfc6962.html> — proves that today's log *extends* yesterday's rather
   than replacing it. `[INFERENCE]` Overkill at QAYD's volume; the idea worth borrowing is the consistency
   proof, not the implementation.
5. **Blockchain anchoring.** `[INFERENCE]` Fails cost/benefit at SME scale. Option 1 plus option 2
   achieves the operative property — an attacker cannot silently rewrite history — with no external
   dependency, no ongoing cost, and no conversation about cryptocurrency with a Kuwaiti finance director.

### 5.8 Migration and open questions

**Existing rows.** Rows written before the chain have `NULL` hashes and cannot be retroactively chained
with any honesty. Two acceptable options: (a) begin the chain at the first post-deployment row with an
explicit genesis marker recording that earlier rows are unchained; (b) compute hashes for existing rows and
**record explicitly that they were chained retroactively and therefore carry no tamper-evidence for the
period before deployment**. `[INFERENCE]` Prefer (a). Option (b) creates a chain that *looks* like it
covers a period it does not, which is the §A-7 failure in a different costume.

**Interaction with partitioning.** TD-06 defers monthly range partitioning. `[INFERENCE]` The chain must be
designed so partitioning does not break it: chains are per tenant and ordered by `id`, so a partition
boundary is not a chain boundary — but the "last row for this tenant" lookup in §5.4 must remain efficient
across partitions. **A partial index on `(company_id, id DESC)` is the mechanism, and it should be added
with the chain rather than discovered later.**

**Interaction with crypto-shredding (S-8).** `[UNKNOWN]` and important. If personal data inside
`old_values`/`new_values` is encrypted and its key is later destroyed, does the hash still verify? **It
does — the ciphertext is what was hashed and the ciphertext remains.** But this only holds if encryption
happens **before** the audit row is written, never after. **This must be settled when S-8 is decided, not
when erasure is first requested.**

### 5.9 Effort and honest assessment

| Component | Effort |
|---|---|
| Canonical serialisation spec (versioned, published) | 3 |
| `BEFORE INSERT` trigger + advisory lock + unique backstop | 5 |
| Genesis handling + migration of existing rows | 2 |
| Verification jobs (incremental + full) + alerting + dead-man's switch | 5 |
| Signed periodic anchor | 3 |
| Customer-facing head delivery | 3 |
| **Total** | **21** — matching the register's estimate for G-16 |

`[INFERENCE]` **Honest assessment:** the chain is worth building, and it is worth building *after* G-18,
S-10 and a working verification job — because the chain is the least valuable component of the system it
belongs to. **Ranked by actual security value: (1) the audit log being complete and unforgeable, (2) the
verification job existing and being monitored, (3) the anchor, (4) the chain itself.** Building them in
that order produces a real guarantee at every step. Building the chain first produces a claim.

---

## Tier 4 — Before the AI ingestion path ships

**Trigger:** the first feature that feeds an externally-authored document to a model.

### G-9 · Proposal tables and the `qayd_ai` role

Read-model `SELECT`, `INSERT` on `*_proposals`, nothing else `[CODE]` intent. Effort **5**. Confidence high.
Makes R-31 structural rather than architectural.

### S-12 · Two-stage extraction

| | |
|---|---|
| **Why** | **The highest-leverage AI security control available** `[INFERENCE]`. Stage 1 extracts to a **typed structure**; stage 2 proposes accounting treatment from that structure only. Prose from an attacker-authored document can never reach the step that decides an entry, because that step is never given prose |
| **Contrast** | Unlike filtering, it cannot be evaded — there is no channel to evade through. Unlike prompt hygiene, it is a boundary rather than a request (`ANTI_PATTERNS.md` §A-9) |
| **Cost** | One additional model call per document |
| **Effort** | **5** · **Confidence** high · **Impact** eliminates most of the practical attack surface |

### S-13 · Constrained output resolution

The model may *suggest* an account; the application resolves it against this tenant's chart and refuses
anything outside it. **Model output is data to be validated, never a name to be resolved.** Effort **3**.
Confidence high.

### S-14 · The historical-departure check

| | |
|---|---|
| **Why** | **The control that catches the realistic attack.** Hidden text redirecting a vendor's payments is not caught by any filter; it is caught by a deterministic comparison — *this vendor has been coded to 2100 for eleven months; this proposal says 6100* |
| **Three reasons it is the right control** | It requires no model. It cannot be prompted around. It is a genuinely good product feature a user would want with no adversary at all — and security controls that are also features get built and stay built |
| **Effort** | **5** · **Confidence** high · **Impact** high |

### S-15 · AI audit events, spend caps, and hidden-content scanning

Log every interaction as an `ai_action` audit row — prompt version, model version, input hash, output,
confidence, human decision `[CODE]` (the category exists). Hard spend cap at the provider plus per-tenant
rate limits — an LLM key is a **metered spend** credential whose compromise is financial and silent
(`BEST_PRACTICES.md` §5.3). Scan extracted text for invisible text, off-canvas positioning, zero-size fonts
and suspicious metadata — **flag, do not block; this is a heuristic and its value is signal, not
prevention.** Effort **5** combined. Confidence: high for the first two, medium for scanning.

### G-17 · `posting_attempts`

Append-only record of **refused** postings with violation codes and AI confidence. Effort **3**.
`[INFERENCE]` Doubles as a detection signal — a rising refusal rate is the earliest available indication of
either an integration bug or someone probing the invariants.

---

## Tier 5 — Before pooling, and before the first support tool

### S-16 · The pooler concurrency test — **before any pooling is enabled**

| | |
|---|---|
| **Why** | The hazard is documented (playbook §7.4). What documentation cannot address is that it is **introduced by an infrastructure change, not a code change** `[INFERENCE]` — someone enables PgBouncer in transaction mode for a good performance reason, in a config file nobody reviews as security-relevant. No playbook section is in that person's path |
| **Do** | PgBouncer in `transaction` mode, `default_pool_size = 1`. N concurrent requests alternating between two tenants with different row counts. Assert every response matches its own tenant. **Then add the inverse test** — use the session form and assert the test *fails*; a test that cannot fail proves nothing |
| **Extend to** | Queue jobs and console commands. The structural answer is that establishing tenant context is something the **base class** does, not something job code remembers — so a job written without it cannot read tenant data at all |
| **Effort** | **5** · **Confidence** high · **Impact** the highest-severity untested property in the system |

### G-19 · `PlatformOperation`

**Trigger:** before the first cross-tenant support tool — and building it *before* it is needed is the
point, because the dangerous moment is a customer locked out on a Friday with no legitimate path.

Distinct database role; narrow per-table policy clauses; a mandatory written reason; **an audit row in the
same transaction, so that if the audit write fails the operation fails**; time-boxed rather than standing;
**visible to the affected tenant**; and never able to write to financial tables. Full specification at
`BEST_PRACTICES.md` §6.4. Effort **8**. Confidence high.

`[INFERENCE]` The tenant-visibility property is the one that changes behaviour, because it makes the action
socially costly as well as logged — and it is a genuine differentiator to advertise.

### S-17 · Token revocation via `perms_ver`

**Trigger:** with G-19, or on the first offboarding.

TD-09's planned resolution is a Redis `jti` denylist. `[INFERENCE]` QAYD already carries `perms_ver` on
`company_users` `[CODE]`; a token embedding the version it was minted under can be rejected on mismatch —
no denylist, no second mechanism to keep consistent, and bumping the version instantly kills every
outstanding token for that membership, which is *also* what must happen on permission change and
offboarding. Effort **5**. **Confidence medium** — the interaction with token minting needs design review,
and there is a real cost: the check now depends on a lookup, which is what a denylist also costs.

### Also in this tier

**TD-07** — CSRF on the Sanctum cookie flow, before the SPA cookie login ships. Effort 3.
**TD-01 / G-20** — static check for raw `DB::` on tenant tables. Effort 2.
**G-15** — shadow-capture trigger reconciled against Action-sourced audit rows; the only mechanism that
would *detect* writes occurring outside the audited path (`ANTI_PATTERNS.md` §A-4). Effort 8.

---

## Tier 6 — Attestation, when a deal requires it

**Trigger:** a **named deal**, blocked on an attestation, worth more than the audit. Not before
(`OVERVIEW.md` §7).

### S-18 · Penetration test against OWASP ASVS L2

**Trigger:** roughly ten paying customers, or before the first attestation.

ASVS 5.0.0 (May 2025) `[DOCS]` is a *verification* standard — every requirement is phrased as something a
tester can confirm, which makes it usable as both a definition of done and a pen-test scope. **L2 is the
right target**: L1 is the floor for any application, L3 is for systems where a breach is a life-safety
event. Effort **8** including remediation. `[INFERENCE]` Cheaper than an attestation and answers most
questionnaires — and it produces findings, which an attestation does not.

### S-19 · SOC 2 or ISO 27001 — **ask the customer which**

`[UNKNOWN]` and consequential: ISO/IEC 27001 is plausibly the more recognised instrument in GCC
procurement, while SOC 2 is a US CPA product (`OVERVIEW.md` §3.2). **This is resolvable by asking three
prospects and is not resolvable by research.**

| | SOC 2 Type II | ISO/IEC 27001:2022 |
|---|---|---|
| Nature | CPA report on your own control descriptions | Certification of a management system |
| First-year cost, small SaaS | $30–60k all-in `[COMMUNITY]` | Broadly comparable |
| Timeline | ~3–4 months with a 3-month window, if controls exist | Longer; the ISMS is the work |
| Ongoing | Annual re-examination | Annual surveillance, 3-year recertification |
| Hidden cost | Remediation and internal time — routinely the largest line | The permanent ISMS ritual |

Effort **21+**. **Confidence high in the sequencing; low in the choice**, which is why S-19 begins with a
question rather than a purchase.

---

## 9. Summary table

| Tier | Trigger | Items | Effort |
|---|---|---|---|
| **0** | None — open today | S-1 counsel · S-2 restore drill · S-3 runbook · S-4 loud fail-closed | **9** |
| **1** | None — open defects | **G-3** · **G-18 + G-23** · **G-1** | **6** |
| **2** | Before customer #1 | S-5 MFA · S-6 security page · S-7 CI gates (incl. G-8, G-20) · S-8 shredding boundary | **17** |
| **3** | Before real books | S-9 adversarial tenancy suite · S-10 complete the audit trail · S-11 detection | **18** |
| **§5** | After G-18 + S-10 | **G-16 hash chain + anchor** | **21** |
| **4** | Before AI ingestion ships | G-9 · S-12 · S-13 · S-14 · S-15 · G-17 | **26** |
| **5** | Before pooling / first support tool | S-16 pooler test · G-19 · S-17 · TD-07 · TD-01/G-20 · G-15 | **31** |
| **6** | A named deal, blocked, worth more than the audit | S-18 pen test · S-19 attestation | **29+** |

**Tiers 0 and 1 together are 15 points** and close every open security defect in shipped code. That is the
whole immediate security backlog, and it is smaller than most teams' next sprint.

### If only five things are done — 12 points

| # | Item | Points | Why |
|---|---|---|---|
| 1 | **G-3** — AI cannot reach `posted` on the `UPDATE` path | 2 | The terminal control on prompt injection; QAYD's central AI-safety claim is currently false on one path |
| 2 | **G-18 + G-23** — remove the audit write hatch, make its return impossible | 3 | The one live P22 violation, on the table whose whole value is being unforgeable — and it blocks the hash chain |
| 3 | **S-2** — restore drill with a stopwatch | 3 | Highest tail risk, cheapest control, currently a hypothesis |
| 4 | **S-1 + S-3** — counsel on CITRA scope, and the runbook | 3 | A 24-hour notification clock cannot be met by improvisation |
| 5 | **G-1** — align ledger grants and policies with the trigger | 1 | Three mechanisms currently describe two different tables |

**Everything else in this document can wait for its trigger. These five cannot.**
