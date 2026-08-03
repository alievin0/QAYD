# Application & Product Architecture — Research Index

**Phase 3 of the QAYD engineering research programme · `docs/research/architecture/`**

Version 1.0 · 2026-07-28 · Documentation only — no application code, schema, migration or test was
modified in producing this.

---

## What this is

This is the eighth and final folder of Phase 3, and the only one that is about the **frontend and
collaboration layer** rather than an accounting domain. The seven that came before it —
`erp/`, `accounting/`, `payments/`, `analytics/`, `ai/`, `banking/`, `security/` — settled what the
system must be true about. This one asks a different question:

> **What does a financial product that feels like Linear rather than SAP actually require,
> architecturally — and specifically what should QAYD adopt now, before the frontend exists, when it is
> nearly free, versus what would be premature?**

Systems studied: **Linear · Notion · Slack · Figma · GitHub · Jira · Monday · ClickUp · Asana ·
Superhuman**, with Xero as the in-category reference for both the best and the worst of what SME
accounting software currently does.

**The short answer**, stated here so nobody has to read four thousand lines to find it:

> **A financial product that feels like Linear does not require a sync engine.** It requires four
> things, three of which are nearly free before the frontend exists: a small, bounded, locally
> replicated **vocabulary** so the ninety per cent of interactions that are lookups never touch the
> network; a single declared **action registry** that the keyboard, the palette, bulk operations,
> automations, the audit log and the AI all project from; an explicit **authority class** on every datum
> so that optimism is applied exactly where the user is the sole author and derived numbers are shown
> *stale-and-dated* rather than *predicted*; and a measured **latency budget** with an owner. What makes
> SAP feel like SAP is not that its server is authoritative — QAYD's is too. It is that SAP made
> everything configurable, so nothing could be fast, nothing could be keyboard-addressable, and no
> default could be good.

**The timing matters unusually much here.** QAYD's frontend is specified in depth and built almost not
at all: Sprint 01 delivered auth, the app shell and full EN/AR RTL, and Sprint 02 is about to build the
first three product screens. Every convention those three establish is inherited by the twenty after
them. That window closes when S2-11 merges.

---

## The seven documents

| # | File | What it answers | Lines |
|---|---|---|---|
| 1 | **`README.md`** *(this file)* | How to read this, what it does and does not cover, headline findings | — |
| 2 | **`OVERVIEW.md`** | The landscape: the sync-architecture spectrum decomposed, perceived-performance numbers, keyboard-first economics, the constraint a ledger imposes, ten system profiles, and what is free now versus premature | 618 |
| 3 | **`BEST_PRACTICES.md`** | Thirty-two practices (BP-01…BP-32) across eight sections, each with tradeoffs, risk, effort and confidence | 919 |
| 4 | **`ANTI_PATTERNS.md`** | Eighteen named anti-patterns (AP-01…AP-18), why each is tempting, and the mechanism by which it fails | 598 |
| 5 | **`ARCHITECTURE.md`** | The proposed layering in depth — authority classes, the four stores, the action registry, the keyboard layer, realtime, data-dense rendering, AI surfaces, latency instrumentation | 739 |
| 6 | **`LESSONS_FOR_QAYD.md`** | Eighteen lessons (L-01…L-18) mapped onto QAYD's existing specs and code, each marked confirms / extends / corrects / contradicts | 528 |
| 7 | **`IMPLEMENTATION_RECOMMENDATIONS.md`** | Twenty-three items (UXR-01…UXR-23) sequenced against the real stories S2-10/11/12/13, S3-10/11/12, S4-02/03/04/09/12, with effort and confidence | 724 |

Read in that order once. After that, `ARCHITECTURE.md` and `IMPLEMENTATION_RECOMMENDATIONS.md` are the
two that get re-opened.

---

## Evidence grades

Every substantive claim carries one:

| Grade | Meaning |
|---|---|
| **`[DOCS]`** | Vendor documentation or engineering blog, URL cited. Describes shipped behaviour. Also used for QAYD's own product specifications. |
| **`[CODE]`** | Verified by reading QAYD's repository. File given. |
| **`[COMMUNITY]`** | Practitioner writing or user-authored content, credible but unrefereed. |
| **`[INFERENCE]`** | Our reasoning from the above. Labelled so it can be argued with. |
| **`[UNKNOWN]`** | We could not verify it. Stated rather than guessed. |

**A standing warning about this field.** Frontend architecture writing is dominated by advocacy — every
technique has a blog post claiming it is transformative and another claiming it is harmful, and almost
none of either is measured. This folder therefore prefers **the engineering writing of the companies
that shipped the thing** (Figma on multiplayer and LiveGraph, Notion on offline, Slack on search,
Superhuman on measurement) over commentary about them, and marks its own reasoning `[INFERENCE]`
wherever it goes beyond what those sources state. Two widely-quoted numbers are explicitly *not* used:
Amazon's "100 ms ≈ 1% of sales" and Google's "500 ms ≈ 20% traffic" are consumer-web results and do not
transfer to a subscription B2B tool where the user is paid to be there. The correct argument for
latency in QAYD is different and stronger, and it is made in `OVERVIEW.md` §3.

---

## Relationship to prior work — read this before adding anything

These documents **cross-reference and never restate**. If a topic is settled elsewhere, the correct move
is a pointer, not a paragraph. QAYD's frontend is already specified in **96 documents** under
`docs/frontend/**` and **72** under `docs/design-system/**`, and that specification is good.

| Already settled — do not re-litigate | Where |
|---|---|
| The frontend never holds business or financial logic | `FRONTEND_ARCHITECTURE.md` Principle 1 |
| One cache for server state; Zustand and Context for client state | Principle 8, §State Management |
| Client-generated idempotency keys per logical submission | Principle 9 |
| AI proposes, a human approves; confidence + reasoning + sources on every AI element | Principle 3 |
| Full EN/AR + RTL parity in the same PR as the component | Principle 6, S1-14 |
| Tokens, density, elevation, the `AmountCell`/`StatusPill`/`AiProvenanceDot` cell atoms | `docs/design-system/**` |
| The palette's anatomy, groups, cmdk contract and RTL rules | `design-system/components/COMMAND_PALETTE.md` |
| Channel naming, reconnect-invalidation, connection-state display | `FRONTEND_ARCHITECTURE.md` §Realtime |
| The Action pattern, the layer rules, where logic goes | `09_ENGINEERING_PLAYBOOK.md` §3.6, §4 |
| Twenty product inventions, including **I-12 Number Provenance** | `07_QAYD_INNOVATION.md` |
| That QAYD builds a deterministic proposal pipeline, not an agent | `docs/research/ai/` |
| Sprint sequencing and per-story acceptance criteria | `docs/execution/SPRINT_0*.md` |

**What this folder adds that none of those contain:** the authority classification as a *type-level*
property; a single action registry unifying six independently-specified lists; a class-D vocabulary
store; an invalidation-only realtime contract stated in terms of class rather than risk; an interaction
latency measurement regime; the throughput discipline that separates keyboard-*operable* from
keyboard-*first*; and the accountability requirements that the market leader's AI feature is currently
failing.

---

## Headline findings

Ten things this research changes or settles.

**1 · Local-first is five separable properties, not one, and QAYD can take exactly two of them.**
Read locality, write optimism, offline authorship and convergence are independent. QAYD gets read
locality for its *vocabulary* and write optimism for *drafts*; it gets neither for the ledger, and it
does not want offline authorship. The confusion that makes this look like an all-or-nothing choice is
treating read locality and write optimism as one thing. → `OVERVIEW.md` §2, §5.

**2 · Optimistic UI on a financial value is a harm, not a UX nicety — and the usual objection is too
weak.** "The number might be wrong for 300 ms" invites the reply "300 ms is nothing." The real argument
is the asymmetry between the durability of the pixel and the durability of the user's belief: a number
shown for 300 ms can be read to a bank, pasted into an email, or typed into a VAT return, and correcting
the pixel does not correct the world. A system wrong 1 in 500 times is *more* dangerous than one wrong 1
in 5, because nobody learns to check. → `ANTI_PATTERNS.md` **AP-01**, `OVERVIEW.md` §5b.

**3 · The one contradiction with the existing plan is a worked example, not a principle.** Principle 10
is right. Its illustration — the Approval Center optimistically flipping a card to `approved` — is class
B rendered as class A: an approval is a server decision subject to permission, step order, a version
check, and segregation of duties that S2-06 explicitly enforces. The fix is small: render *submitting*,
not *approved*. → `LESSONS_FOR_QAYD.md` **L-05**, `IMPLEMENTATION_RECOMMENDATIONS.md` **UXR-09**.

**4 · Replicate the vocabulary, never the ledger — and that is ~90% of the benefit.** During journal
entry the client reads reference data on every keystroke and touches the ledger once, at post time. A
GCC SME's accounts, tax codes, currencies, periods and counterparties are plausibly a few hundred
kilobytes `[INFERENCE]` — smaller than the JavaScript that renders them. Linear's transferable idea is
not the sync engine; it is the **declared per-model load strategy**, because declaring what is on the
client is what makes partial replication safe. → `ARCHITECTURE.md` §3.1, **BP-06**.

**5 · QAYD specifies its operations six times, in six good documents that do not reference each
other.** The global shortcut table, the palette's "static action list", the Bulk Action Bar,
`AUTOMATION_CENTER.md`, `AUDIT_LOG.md`, and the ai/ folder's capability enum — plus fourteen Laravel
Actions and a permission seed. Each is well designed; none is the source. **One declared action registry
that all six project from** is this folder's headline recommendation and the item with the highest
retrofit cost in it. It is also what makes the AI's tool surface a subset of the human action surface
*by construction*. → `ARCHITECTURE.md` §4, **BP-10**, **L-03**, **UXR-01**.

**6 · Keyboard-*operable* and keyboard-*first* are different properties, and QAYD currently has the
first.** `ACCESSIBILITY.md` runs 1,495 lines and Principle 11 makes full keyboard operability a
requirement of done — that is real and valuable and it is not throughput. Throughput asks whether the
keyboard is the *fastest* path, and it is won in the data model (no network on a keystroke) and the
registry (a small stable action set), not in the shortcut table. A `Tab` order walking 400 grid cells is
fully operable and unusable. → `ARCHITECTURE.md` §5.1, **L-08**.

**7 · The category's existing answer to volume is Xero's Cash Coding, and QAYD's bulk bar stops one
step short.** Cash Coding is a spreadsheet-grid bulk-coding view over statement lines, 200 at a time,
permission-gated rather than role-hardcoded `[DOCS]`. What it demonstrates: the fastest way to code two
hundred transactions is neither two hundred forms nor an invisible AI — it is one grid where a human
applies judgement in bulk and can see every line they touched. QAYD's Bulk Action Bar has the right
mechanism scoped to whole-record verbs; the missing step is bulk **field editing** with a batch endpoint
and per-row outcomes. → **BP-13**, **L-09**, **UXR-16**.

**8 · The AI UX failure in this market is an accountability failure, not an accuracy one — and there is
public evidence.** On Xero's own product board for its automatic-reconciliation beta, the loudest
requests are for a **reviewed-by-a-human state** recording person, date and time (38 votes, *Gaining
Support*) and for the bank's **description and reference** to survive coding (27 votes) — the latter
because without it "we can't verify if the items that were auto coded are correct" `[COMMUNITY]`. Both
are one failure: the automation removed the evidence a human needs to check it. Neither is fixed by a
better model, and both are two columns before the tables exist. → `ANTI_PATTERNS.md` **AP-14**,
**UXR-19**, **UXR-20**.

**9 · Reverb should carry facts about change, never derived amounts — and the rule should be stated as a
class, not as a risk judgement.** A channel carrying a trial-balance figure creates a second computation
path for a financial number, and second computation paths diverge silently for exactly the tenant with
the unusual data. S2-13 already gets this right. The carve-out to revisit is patching a **dashboard
financial tile**: it is class C, it may never reach a terminal state, and a drifted patch has no
scheduled correction. → `ARCHITECTURE.md` §6, **AP-04**, **L-06**.

**10 · The measurement method is eighty lines and it is the best value-to-cost item in the folder.**
Superhuman measures "% of interactions under target", starting at `event.timeStamp` — which captures the
time an event spent queued behind a blocked main thread, the lag users actually feel and the one naive
instrumentation hides — and ending inside `requestAnimationFrame` `[DOCS]`. It complements Core Web
Vitals rather than replacing them: INP will tell you a route is slow; it will not tell you that account
resolution crossed 100 ms above 300 accounts. Effort 2. → **BP-07**, **L-02**, **UXR-06**.

---

## What this research deliberately does not do

- **It does not copy code, imitate architecture, or reproduce UI.** Principles, interaction models and
  engineering techniques only. Where a vendor's design is described, it is described to extract the
  reason it works or fails.
- **It does not design screens.** `docs/frontend/**` owns screens, flows and components;
  `docs/design-system/**` owns tokens and atoms. This folder cites them and stops.
- **It does not re-litigate the AI boundary.** The proposal/confirmation boundary, the posting
  chokepoint and the no-autonomy-over-the-ledger position are settled and untouched.
- **It does not propose a design language.** Nothing here argues with the hairline elevation, the single
  accent, the absence of zebra striping, or any other taste decision already made.
- **It does not settle product questions.** Whether bulk field editing is its own surface or a grid mode,
  and whether review is per-record or per-batch, are design-partner questions and are flagged as such in
  `ARCHITECTURE.md` §14.

---

## Maintenance

This document set is **research, not specification**. It becomes specification only when a
recommendation is promoted into `08_MASTER_BACKLOG.md` with a tier, a value, a dependency list and a
sprint — the intake rule, unchanged. Three items additionally need a decision record rather than a
backlog entry, because they refine something already written: **UXR-07** (client data layer),
**UXR-09** (no optimistic approve) and **UXR-10** (COA virtualisation).

Re-verify before relying on any of it:

| What | Why it moves | How to check |
|---|---|---|
| Competitor product-board complaints | Vendors ship fixes; vote counts change | Re-read the board; the *shape* of the complaint outlasts the ticket |
| The class-D size estimate | It is `[INFERENCE]` and load-bearing for UXR-05 | Measure a real customer's COA + counterparty list |
| The latency budgets in `ARCHITECTURE.md` §12 | They are derived, not measured | Replace with UXR-06's first real distribution |
| "Shipped vs announced" for any competitor AI feature | The gap is months and sometimes permanent | Product documentation and support boards, not press releases |
| The state of `apps/web` | This folder's timing argument depends on the frontend being unbuilt | `git log apps/web` |

Anything found to be wrong should be corrected **in place, with the date**, not appended to.

# End of Document
