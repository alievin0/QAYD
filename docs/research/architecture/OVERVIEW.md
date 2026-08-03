# OVERVIEW — how instant-feeling applications are actually built, and which parts of that a ledger may keep

**Phase 3 engineering research · application & product architecture / UX engineering domain.**
Version 1.0 · 2026-07-28 · Status: **research, not binding**

Systems studied: **Linear · Figma · Notion · Slack · GitHub · Jira · Monday · ClickUp · Asana · Superhuman.**

This document establishes the landscape and derives the one constraint everything else in the folder hangs
off. `ARCHITECTURE.md` draws the mechanisms; `BEST_PRACTICES.md` and `ANTI_PATTERNS.md` turn them into rules;
`LESSONS_FOR_QAYD.md` and `IMPLEMENTATION_RECOMMENDATIONS.md` turn the rules into sequenced work.

---

## 1. The two questions this domain answers

Every product in the study list is admired for one of two distinct achievements, and they are usually
confused with each other:

1. **"It feels instant."** — Linear, Superhuman, Figma. This is a *latency* achievement, and it is won or
   lost in the data architecture, not in the CSS.
2. **"It makes repetitive work fast."** — Linear, Superhuman, GitHub's palette, spreadsheet-style grids.
   This is a *throughput* achievement, and it is won or lost in the input model: keyboard, selection,
   bulk operation, and the absence of modal round-trips.

They are separable. A product can be instant and still slow to work in (Notion: sub-100 ms typing, but
creating fifty structured rows is painful). A product can be laggy and still high-throughput (a green-screen
terminal accounting package: 400 ms per screen, but an experienced operator posts 200 entries an hour because
every field is one keystroke away). **SAP is slow on both axes. QuickBooks and Xero are acceptable on the
first and poor on the second** `[INFERENCE]` — mouse-driven forms, one document per screen, modal dialogs
for account selection.

QAYD's users — SME accountants and bookkeepers in Kuwait and the GCC — spend the overwhelming majority of
their time on axis 2. **Axis 2 is the neglected one in this market and the cheaper one to win.** Axis 1 is
partially unavailable to QAYD for reasons derived in §5, and pretending otherwise is how a financial product
gets an integrity incident.

---

## 2. The sync-architecture spectrum, decomposed

"Local-first" is used to mean five different architectures. They are not points on one line; they are
combinations of four *separable* properties. Separating them is what makes it possible to take some of
Linear's benefits without taking Linear's risks.

### 2a. The four properties

| Property | Question it answers | Independent of the others? |
|---|---|---|
| **P1 — Read locality** | Can the client render this view without a network round-trip? | Yes |
| **P2 — Write optimism** | Does the UI show the effect of a write before the server confirms it? | Yes |
| **P3 — Offline authorship** | Can the client create durable new work with no server reachable? | Requires P1 + P2 |
| **P4 — Convergence guarantee** | If two clients write concurrently, is the outcome defined without human intervention? | Only matters if P2 or P3 |

Almost all the confusion in this field is treating P1 and P2 as one thing. **They are not, and QAYD's whole
answer lives in that gap: P1 is largely available to QAYD; P2 is available only for a specific class of data;
P3 is not worth having; P4 is mostly moot once P3 is refused.**

### 2b. The five architectures, in increasing order of client authority

```
 (i)  Request / response          P1 ✗  P2 ✗  P3 ✗   — every view is a fetch; every write is a wait
      ├─ classic ERP web UI, most SME accounting SaaS
      └─ failure mode: the spinner IS the product

 (ii) Server-authoritative +      P1 ~  P2 ✗  P3 ✗   — cached reads, pushed invalidation, honest writes
      live invalidation
      ├─ GitHub (mostly), Jira (mostly), a well-built TanStack-Query app
      └─ failure mode: cache staleness shown as if fresh

(iii) Optimistic mutation        P1 ~  P2 ✓  P3 ✗   — write renders immediately, rolls back on rejection
      ├─ Asana, Monday, ClickUp, Notion (online), most modern SaaS
      └─ failure mode: rollback that the user does not notice

 (iv) Local replica + sync       P1 ✓  P2 ✓  P3 ✓   — client holds the working set; server orders writes
      engine (server-ordered)
      ├─ Linear, Figma, Notion offline pages, Superhuman
      └─ failure mode: the replica is the whole dataset, and the dataset is confidential/unbounded

  (v) Peer CRDT / no authority   P1 ✓  P2 ✓  P3 ✓  P4 ✓ by construction
      ├─ Automerge/Yjs-style collaborative documents
      └─ failure mode: "converged" is not the same as "correct"; no place to enforce an invariant
```

Note the crucial fact hiding at the bottom: **(v) guarantees convergence and forbids invariants.** A CRDT
merges two states into a third state that is deterministic — but nothing in the construction says the third
state satisfies a business rule. Two clients can each post a balanced entry into a period; the merge is
deterministic and the period is now closed with entries in it. Convergence is a property about *agreement*,
not about *legality*. For a ledger, legality is the whole point. `04_REJECTED_PATTERNS` reasoning applies
directly. **(v) is out for QAYD, permanently, and it is out for a stronger reason than performance.**

### 2c. Who chose what, and why

**Figma chose (iv), server-authoritative, explicitly rejecting OT and true CRDTs.** Their reasoning is the
single most useful paragraph in this whole literature for QAYD, because it is an argument about *choosing the
weakest mechanism that solves the problem*: OTs were "unnecessarily complex for our problem space," causing
"a combinatorial explosion of possible states which is very difficult to reason about," and because Figma has
a central server it "can simplify our system by removing this extra overhead and benefit from a faster and
leaner implementation." Their model is last-writer-wins **per property**: "servers keep track of the latest
value that any client has sent for a given property on a given object."
`[DOCS]` https://www.figma.com/blog/how-figmas-multiplayer-technology-works/

They are candid about the cost: "simultaneous editing of the same text value doesn't work in Figma" — if one
client writes "AB" and another "BC", the result is AB or BC, never "ABC". They accepted a *known wrong answer
in a rare case* in exchange for a system they could reason about. **That trade is available to a design tool
and is not available to a ledger**, and the difference is exactly §5.

**Linear chose (iv) with a full client-side replica.** The mechanism is well documented by reverse
engineering endorsed by Linear's CTO `[COMMUNITY]`
https://github.com/wzhudev/reverse-linear-sync-engine: models live in an **Object Pool** keyed by UUID; a
`ModelRegistry` records per-property metadata via decorators (`property`, `reference`, `referenceCollection`,
`backReference`, `ephemeralProperty`); properties are MobX-observable so views update automatically. Load
strategies are declared per model — `instant` (loaded during bootstrap), `lazy`, `partial`,
`explicitlyRequested`, `local`. The client bootstraps into IndexedDB, then receives **delta packets** of sync
actions, each carrying a **sync id**; `lastSyncId` is "the version number of the database." Writes go into a
`TransactionQueue` that is itself persisted to an IndexedDB `__transactions` table and batched to the server;
transactions can be "undone, redone, and reverted on the client side, allowing smooth handling of server
rejections." Conflict resolution is last-writer-wins. Access control rides on `subscribedSyncGroups`.

Two details matter for QAYD more than the rest:
- **Linear uses a total order (incrementing sync ids), not a partial order.** They did not need CRDTs
  because they have a server that can order everything. So does QAYD.
- **The load strategy is declared per model.** Linear did not replicate everything; it replicated what a
  view needs and declared the rest lazy. That is the seed of §5's answer.

**Notion chose (iii) online and (iv) for explicitly-marked offline pages.** Notion's offline release is
instructive because of what they *refused*: "when offline, we never want to show a page that might be missing
data," so only fully-downloaded pages are reachable offline; pages marked available offline are "dynamically
migrated to our new CRDT data model for conflict-resolution"; sync is push-based on a per-page channel with a
`lastDownloadedTimestamp` vs `lastUpdatedTime` comparison on reconnect to avoid needless refetches.
`[DOCS]` https://www.notion.com/blog/how-we-made-notion-available-offline

The refusal is the lesson: **Notion would rather show nothing than show a partial truth.** For a document
tool that is a courtesy. For a ledger it is a requirement.

**Figma also built (ii) separately, for everything that is not the canvas** — LiveGraph. This is the piece
most directly transferable to QAYD's stack and the least famous. LiveGraph exists because the previous
approach made product engineers "manually craft event messages to be sent to the relevant clients everywhere
we write to the database," which "created data consistency bugs in which the client state no longer reflected
the subset of server state it was supposed to." The fix: subscribe to "the database replication stream
(write-ahead log)" rather than polling, decompose each view into "a tree of more granular subqueries" each of
the form `SELECT columns FROM table WHERE condition without any joins`, and cache/share subquery results
across subscribers. Updates arrive "in the order of milliseconds"; delivery is "guaranteed to be received in
order and exactly once"; on disconnect "the library will reconnect under the hood and refetch the data."
Stated capacity is "on the order of 10,000 writes/second"; pagination "remains a challenging problem in the
face of real-time update." `[DOCS]` https://www.figma.com/blog/livegraph-real-time-data-fetching-at-figma/

**Superhuman chose (iv) plus an obsessive measurement discipline**, and the measurement discipline is the
transferable part — see §4.

---

## 3. Perceived performance: what the numbers actually are

The budgets everyone quotes trace to two sources, and it is worth having them exactly right because the
QAYD-specific budgets in `BEST_PRACTICES.md` are derived from them.

**Nielsen's three limits** `[DOCS]` https://www.nngroup.com/articles/response-times-3-important-limits/:

- **0.1 s** — "about the limit for having the user feel that the system is **reacting instantaneously**,
  meaning that no special feedback is necessary except to display the result."
- **1.0 s** — "about the limit for the **user's flow of thought** to stay uninterrupted, even though the
  user will notice the delay."
- **10 s** — "about the limit for **keeping the user's attention** focused on the dialogue."

**Superhuman's operationalisation** `[DOCS]`
https://blog.superhuman.com/performance-metrics-for-blazingly-fast-web-apps/ is more useful than the
research, because it is a method rather than a target:

- They measure **"% of events under target"** rather than percentiles. Buckets: **<50 ms**, **<100 ms**,
  **<1000 ms**, and a "terrible" bucket above 1000 ms. 100 ms is chosen as "the perceptual threshold between
  fast and slow."
- **Start of measurement is `event.timeStamp`** — "the time the system logged the event" — *not*
  `performance.now()` at handler entry. This is the subtle and correct choice: it captures time the event
  spent queued behind a blocked main thread, which is precisely the lag a user feels and precisely what
  naive instrumentation hides.
- **End of measurement is `performance.now()` inside `requestAnimationFrame`** — "after the CPU work is
  done, and just before the frame is rendered."
- They discard samples where `document.hidden` is true or that began before the last `visibilitychange`,
  to remove sleep/throttling artefacts.
- `performance.now()` is used over `new Date()` for precision (±100 μs vs ±1 ms).

Three properties make this worth copying wholesale. It is **~80 lines of code**. It is **framework-agnostic**.
And "% under 100 ms" is a metric a non-engineer can be held to, whereas "p95 interaction latency" is a number
that hides exactly the tail that makes users angry.

**Amazon's 100 ms ≈ 1% of sales and Google's 500 ms ≈ 20% traffic figures** are widely cited `[COMMUNITY]`
and are consumer-web results. They are *not* transferable to a subscription B2B tool where the user is paid
to be there; quoting them at QAYD would be motivated reasoning. The correct B2B argument is different and
stronger: **latency in a data-entry tool multiplies by the number of entries.** 200 ms of avoidable lag per
journal line, at 120 lines a day, is 24 seconds a day of pure waiting, but the real cost is that above
roughly 1 s the operator's flow of thought breaks and they re-read the source document — which costs tens of
seconds, not hundreds of milliseconds. **The nonlinearity, not the milliseconds, is the business case.**

---

## 4. Keyboard-first UX: the most under-served axis in SME accounting

This section is short because the argument is short and the details are in `BEST_PRACTICES.md` §3–4.

**The claim:** for volume data entry, a keyboard grid beats a mouse-driven form by a factor that is not
close, and every mainstream SME accounting product ships the mouse-driven form.

**The mechanism** is not typing speed. It is that a mouse-driven form imposes three costs per field that a
grid does not: a **target acquisition** (Fitts's-law pointing time, ~0.5–1.2 s for a small dropdown
`[COMMUNITY]`), a **modality switch** (hand leaves the keyboard and returns), and — the expensive one — a
**round trip for lookup data** when the account picker fetches on open. Three of these per line, twelve lines
per entry, and the entry takes ninety seconds instead of twenty.

**The standards already exist and are unambiguous.** The ARIA `grid` pattern `[DOCS]`
https://www.w3.org/WAI/ARIA/apg/patterns/grid/ specifies exactly the interaction model a data-entry grid
needs, including the two-mode concept that most home-grown grids get wrong: arrow keys move focus cell to
cell; *Home*/*End* move within the row; *Ctrl+Home*/*Ctrl+End* jump to the first/last cell of the grid;
**Enter or F2 enters edit mode** ("Disables grid navigation, focusing the input"), and **Escape "restores
grid navigation."** A grid is a composite widget with roving tabindex — "only one focusable element is in the
page tab sequence" — which is what stops Tab from walking into 400 cells. The APG even names the failure
QAYD must avoid on a virtualised ledger: when a list "dynamically loads more" content, "keyboard users are
effectively trapped in the list."

**Command palettes are the discovery layer for the same actions.** GitHub's is the best-documented instance
`[DOCS]` https://docs.github.com/en/get-started/accessibility/github-command-palette: opened with
Ctrl/Cmd+K, and — the design decision worth stealing — it is **scoped and prefixed**, so it is one input
serving several search spaces rather than a fuzzy blob: `>` "Enter command mode", `#` issues/PRs/discussions/
projects, `@` users/organizations/repositories, `/` files within a repository scope, `!` projects. Tab
narrows scope, Backspace widens it. The stated purpose is to "run commands directly from your keyboard,
without navigating through a series of menus."

The prefix idea maps onto QAYD almost too neatly — accountants already think in namespaces (accounts,
entries, contacts, documents, periods) and already know their codes. §5 of `ARCHITECTURE.md` builds this out.

**Linear's product philosophy backs the same conclusion from the opposite direction.** The Linear Method is
explicit that "Productivity software needs to be designed for purpose" and that "Flexible software lets
everyone invent their own workflows, which eventually creates chaos" `[DOCS]`
https://linear.app/method/introduction. A keyboard-first product is only possible if the action set is small,
named, and stable — which is to say, keyboard-first and infinite configurability are **mutually exclusive
architectures**, not two features you can both have. ERP chose configurability. That choice is why no ERP
has ever felt fast, and it is the choice QAYD must consciously not repeat (`ANTI_PATTERNS.md` AP-11).

---

## 5. The constraint: why a ledger cannot simply adopt Linear's architecture

This is the section the rest of the folder depends on.

### 5a. Two structural differences, not one

Linear's sync engine works because of two properties of Linear's data that do not hold for QAYD:

**Difference 1 — Linear's screen is made of *fields*; QAYD's screen is made of *aggregates*.**

An issue's title, assignee, state and estimate are stored values. A client holding the object graph can
render any Linear view without asking anything of anyone. A trial balance, an account balance, a period P&L
and a reconciliation difference are **aggregates over the ledger**. To compute them on the client you must
replicate the ledger. The ledger is unbounded (it grows forever), it is the most confidential data in the
product, and it is precisely what row-level security exists to keep inside PostgreSQL.

**Difference 2 — Linear's invariants are local and weak; QAYD's are global and strong.**

"An issue has a state, and the state belongs to the issue's team" is checkable against a client's own
replica. "Debits equal credits", "the period is open", "this account is postable", "the entry number is the
next one in an unbroken sequence", "the fiscal calendar permits this date" are **not** checkable against a
partial replica, and two of them are not checkable against a *complete* replica either, because they are
statements about a sequence that other clients are concurrently extending.

Difference 1 says the client cannot *compute* the number. Difference 2 says the client cannot *authorise*
the write. Together they say: **the server is authoritative, and no client-side cleverness changes that.**

### 5b. The asymmetry that actually forbids optimistic balances

The naive objection to optimistic UI in finance is "the number might be wrong for 300 ms." That objection is
too weak, and answering it invites the wrong reply ("300 ms is nothing").

The real argument is about **the durability of the user's belief relative to the durability of the pixel.**

In Linear, a rejected optimistic update costs a flicker. Nothing leaves the system. In QAYD, a number
rendered for 300 ms can be:

- read aloud on a phone call with the bank,
- copied into an email to the owner,
- screenshotted,
- used as the basis for approving a payment,
- typed into a VAT/Zakat return.

**Once a human has acted on a number, correcting the pixel does not correct the world.** The half-life of a
wrong balance in a user's head is measured in days; the half-life on screen is measured in milliseconds. This
is why the harm does not scale with latency, and why "it's only 300 ms" is not a defence. The existing
knowledge base already reaches the same place from a different direction — its standing prohibitions on
trusting a cached total and on silently correcting the user are the same principle expressed as coding rules;
this document supplies the mechanism behind them and extends them to the rendering layer.

There is a second-order effect that is arguably worse: **an optimistic financial UI that is usually right
trains users to trust it.** A system that is wrong 1 in 500 times is more dangerous than one wrong 1 in 5,
because nobody develops a habit of checking. This is the same structure as alert fatigue (`ANTI_PATTERNS.md`
AP-06) with the sign flipped.

### 5c. But the conclusion is not "be SAP"

The mistake symmetric to naive local-first is fatalism: "the server is authoritative, therefore everything
waits, therefore we are slow, therefore we look like an ERP." That does not follow, and the reason it does
not follow is the most useful finding in this research:

> **The data that determines how fast QAYD *feels* is almost entirely not the data the server must be
> authoritative about.**

Sort every datum in the product by *who authors it*:

| Class | What it is | QAYD examples | Authored by | Bounded? | Optimistic render? |
|---|---|---|---|---|---|
| **A** | Client-authored draft | unposted journal draft + its lines, import mapping in progress, proposed reconciliation matches, filters, selections, wizard state | this user, this session | yes | **Yes — fully.** No other party can contradict it. |
| **B** | Server-authored fact | posted entry number, `posted_at`, ledger rows, hash-chain links, audit records | the server, at the posting chokepoint | no (grows) | **Never.** Render a *pending* state instead. |
| **C** | Server-derived aggregate | trial balance, account balances, reconciliation difference, period totals, statements | derived from B | no | **Never as a predicted value.** Stale-with-timestamp is permitted; prediction is not. |
| **D** | Shared reference data | chart of accounts, tax codes, currencies, FX rates, counterparties, users/roles, saved views, fiscal periods | any user, rarely | **yes, and small** | **Cache aggressively; replicate locally; push invalidation.** |
| **E** | Local preference | column widths and order, density, last-used account, recent items, draft autosave | this user | yes | Local only; never round-trips. |

Now count where the keystrokes go. During journal entry — the highest-volume activity in the product — the
client reads class **D** on **every single keystroke** (resolving "4110" or "رواتب" to an account, validating
a tax code, offering a counterparty) and writes class **A**. It touches **B** exactly once, at post time, and
**C** only when a report is opened.

**A GCC SME chart of accounts is roughly 150–400 accounts** `[INFERENCE]`; tax codes are single digits;
currencies a handful; counterparties typically hundreds to low thousands. The entire class-D working set for
a small company is **plausibly a few hundred kilobytes** `[INFERENCE]` — it is a rounding error next to the
JavaScript bundle that renders it.

So the honest statement of the opportunity is:

> **Replicate the vocabulary, never the ledger.**
>
> Class D gets Linear's architecture — bootstrap into a client store, push deltas, render with zero
> round-trips. Class A gets full optimism, because the user is the only author. Class B gets an honest,
> legible pending state. Class C gets a freshness timestamp and never a guess. Class E never leaves the
> device.

That is ~90% of the perceived-speed benefit of a sync engine, for a small fraction of the work, with **none**
of the correctness exposure — because nothing in class D is money, and nothing in class B or C is ever
predicted.

### 5d. The corollary for realtime: push invalidation, not values

QAYD's stack already includes Reverb. The tempting use is LiveGraph-shaped: push the new derived data to
subscribers. **For class C this is the wrong choice, and for a reason that is specific to money rather than
general.**

If a channel carries the *value* of a trial-balance row, then two things compute that value: the reporting
query, and whatever assembled the broadcast payload. That is a second computation path for a financial
number, and second computation paths diverge — silently, and usually only for the tenant with the unusual
data. Figma can accept this because a comment count that is briefly wrong is a comment count. A ledger
cannot.

The rule that follows is cheap to hold and expensive to retrofit:

> **Realtime channels for class B/C carry *facts about change* (an entry posted in company X, period Y,
> touching accounts [a, b, c]), never derived amounts. The client's response to such a message is to
> invalidate and refetch, so the number on screen has exactly one producer — the server-side query.**

Class D is the exception: reference data may be pushed as values, because it *is* the value — there is no
derivation to diverge from. This is the same split as class-D replication, arriving from a different
direction, which is a reasonable sign the split is real.

---

## 6. Profiles — what each system does, why it works, where it fails, and the verdict for QAYD

### Linear
**Does:** full client replica in IndexedDB + MobX object pool; declared per-model load strategies; delta
packets ordered by a monotonic `lastSyncId`; persisted transaction queue with client-side undo/revert;
LWW conflicts; opinionated, non-configurable workflow; keyboard-first throughout.
**Why it works:** the client can answer every question locally, so the network is off the interaction path
entirely; and the product refuses configurability, which keeps the action set small enough to be
keyboard-addressable.
**Where it fails / limits:** the model assumes the working set fits on the client and is safe to put there;
LWW quietly loses concurrent edits; the bootstrap is a real cost on large workspaces `[COMMUNITY]`; and none
of it enforces a cross-object invariant.
**Verdict for QAYD:** **adopt the pattern for class D only.** Adopt the *declared load strategy* idea in
full — it is the mechanism that makes partial replication safe, and it costs nothing to design in now. Do
not adopt a general sync engine.

### Figma
**Does:** server-authoritative multiplayer, LWW per property, parent-pointer tree with fractional indexing
for ordering, server rejection of parent updates that would form a cycle, undo modelled so that
"undo … copy … redo back to the present" leaves the document unchanged. Separately, LiveGraph for
non-canvas data.
**Why it works:** they picked the weakest mechanism that solved the problem and wrote down the cases it
loses.
**Where it fails:** concurrent text edit on one property is lost by construction; reparent cycles cause
objects to "temporarily disappear" — accepted as "a simple solution to a very rare temporary problem."
**Verdict for QAYD:** **do not adopt multiplayer.** Adopt two ideas: the *discipline* of choosing the
weakest sufficient mechanism and documenting its known-wrong cases; and the *undo model* — QAYD's reversal
semantics for posted entries need exactly this kind of stated invariant. LiveGraph's subquery-tree and
replication-tailing design is the best available reference for what QAYD's Reverb layer should **not** try
to become (see §5d).

### Notion
**Does:** block-per-row data model; optimistic local apply → server validate → broadcast; explicit
per-page offline with CRDT migration; push channels per page; refuses to open partially-downloaded pages
offline.
**Why it works:** the block granularity makes sync payloads small and conflicts rare — "using blocks instead
of documents for synchronization decreases the network traffic … and reduces conflicts" `[COMMUNITY]`
https://www.notion.com/blog/data-model-behind-notion.
**Where it fails:** offline took years precisely because reference tracking and rich-text merge are hard;
and the infinite flexibility that makes Notion beloved also makes it slow to *operate* — everyone invents a
different structure.
**Verdict for QAYD:** adopt the **refusal** ("never show a page that might be missing data") as a rendering
law. Adopt granular sync units (a journal *line*, not a whole document) if class-A drafts ever sync across
devices. Reject the flexibility model outright — it is the ERP failure in a friendlier costume.

### Slack
**Does:** two sort modes (Recent, Relevant); a learning-to-rank re-ranker over Lucene using **work-graph**
signals — searcher-to-author affinity ("propensity of that user to read the other's messages"), DM and
channel priority scores, pinned/starred/reactions, "propensity of searchers to click on other messages from
the channel", plus shallow content features (word count, line breaks, emoji, formatting). Training pairs
were built from clicks with position-bias correction by "oversampling clicks on results lower down."
Measured: **"9% increase in clicked searches"** and **"27% increase in clicks at position 1."**
`[DOCS]` https://slack.engineering/search-at-slack/
**Why it works:** relevance in a workspace is mostly a social-graph problem, not a text problem.
**Where it fails:** ranking can bury an exact match; and Slack's notification model is the canonical study
in how a good product trains users to ignore it.
**Verdict for QAYD:** adopt **recency + affinity ranking** for entities (counterparties, accounts, recent
entries) — it is cheap and it is what makes a palette feel telepathic. **Explicitly reject ranked search for
identifiers**: an accountant searching `INV-4471` must get `INV-4471` first, always, by short-circuit, not
by score (`BEST_PRACTICES.md` BP-19).

### GitHub
**Does:** scoped command palette (§4); a notification model built on **reasons** (`mention`, `subscribed`,
`review requested`, filterable as `reason:review-requested`), an inbox with four triage verbs — **Done**,
**Saved**, mark read/unread, **Unsubscribe** — and default filters "representing the most common reasons
that people need to follow-up." `[DOCS]`
https://docs.github.com/en/account-and-profile/managing-subscriptions-and-notifications-on-github/setting-up-notifications/about-notifications
**Why it works:** every notification answers *why am I seeing this* and *what clears it*. Those two
questions are the entire difference between an inbox and a noise source.
**Where it fails:** no native digest — notifications arrive individually and in real time `[COMMUNITY]`,
which is exactly the volume problem digests exist to solve.
**Verdict for QAYD:** adopt **reason + triage verb as mandatory notification metadata** — this is a schema
decision, effectively free before the notification table exists and painful after. Add the digest GitHub
lacks.

### Jira
**Does:** the reference implementation of enterprise configurability — custom fields, screens, screen
schemes, issue-type schemes, workflow schemes, permission schemes.
**Why it "works":** it can model any organisation's process, which is why it wins enterprise procurement.
**Where it fails:** the configuration surface *is* the product's complexity, and it is unbounded; no two
installations behave the same, so no keyboard model, no useful defaults, no learnable action set, and
support/onboarding cost scales with customer count. Performance complaints are chronic and structural — an
issue view is assembled from configuration at request time.
**Verdict for QAYD:** the **negative** reference. Every configuration knob QAYD ships must pass the test in
`ANTI_PATTERNS.md` AP-11: *does the law vary here, or only taste?*

### Monday / ClickUp / Asana
**Does:** optimistic mutation (architecture (iii)) over a flexible board/table model; heavy automation
builders; broad feature surface.
**Why it works:** immediate feedback on low-stakes writes, and the automation builder sells.
**Where it fails:** ClickUp in particular is the standing example of feature-surface growth outrunning
coherence `[COMMUNITY]`; automation builders in all three drift toward "if this then that" rule soups that
nobody can audit — which is precisely the ERP workflow-engine failure re-created by a startup.
**Verdict for QAYD:** the automation lesson is the valuable one and it is inverted from what people expect —
see §7.

### Superhuman
**Does:** local index + prefetch, keyboard-only operation with a command palette, and the measurement
regime in §3.
**Why it works:** it treats latency as a *product requirement with an owner and a number*, not as an
engineering aspiration.
**Where it fails:** the model depends on a bounded mailbox and a single-user data set; and the price point
that funds it is not available in SME accounting.
**Verdict for QAYD:** adopt the **measurement method** verbatim (effort ≈ 2, see `LESSONS_FOR_QAYD.md`
L-02). Adopt the keyboard-only-operability *test* — "can a competent user complete this flow without
touching the mouse?" — as a definition-of-done item rather than an aspiration.

---

## 7. Workflow and automation: what these tools got right that ERP got wrong

ERP workflow engines (SAP Workflow, Oracle, Odoo's automated actions, Jira's workflow schemes) are
general-purpose state machines with a visual builder. They are strictly more expressive than Linear's
automations. They are also, in practice, worse. The reason is not expressiveness; it is **three missing
properties**:

1. **Triggers are declared over a small, named, stable event vocabulary.** Linear/GitHub automations fire on
   a closed set of domain events. ERP builders fire on "any field change on any object", so no automation is
   ever reviewable — you cannot answer "what runs when I post this entry?" without simulating it.
2. **Actions are the same actions a human can take.** In the good tools, an automation's effect is
   expressible as "the system did what a user could have done", so the audit trail, permission model and
   undo path already exist. In ERP builders, automations write fields directly, bypassing the permission and
   validation paths — which is how "the workflow did it" becomes an unanswerable audit finding.
3. **There is a bounded blast radius and a visible history.** Good automations are per-team and leave a
   record on the object. ERP automations are global, invisible, and discovered during incidents.

**The QAYD corollary — and it is the same object as §4's keyboard argument:** if every operation in the
product is a *declared action* with an id, a permission predicate and an executor, then automations,
keyboard shortcuts, the command palette, bulk operations, the audit log and **the AI copilot's tool list**
are all *projections of one registry*. That single decision — cheap now, near-impossible later — is
developed in `ARCHITECTURE.md` §4 and is the folder's headline recommendation.

---

## 8. AI integration UX: the four surfaces, and which QAYD needs

Across Notion, Linear, GitHub and Superhuman, AI shows up in exactly four places:

| Surface | Strength | Weakness | Fit for QAYD |
|---|---|---|---|
| **Inline / in-place** (ghost text, suggested value in the field you are in) | Zero context-switch; the suggestion appears where the decision is made | Only works when the output is a single value or short span; easy to accept accidentally | **Primary** for coding an imported bank line, suggesting an account, matching a counterparty |
| **Command palette** (natural language → action) | Reuses an existing muscle memory; naturally bounded to the action registry | Discovery of what it can do is poor without examples | **Primary** for "post the rent for July", "show me account 4110 last quarter" |
| **Dedicated review surface** (a queue of proposals, each with provenance and accept/reject) | The only surface where a human can compare many machine judgements efficiently; the natural home for confidence and provenance | Feels like work; must be fast or it is abandoned | **Primary** for Sprint 4's decision review and for reconciliation |
| **Chat panel** | Handles open-ended questions and multi-turn refinement; users expect it | Produces *text about* the system instead of *changes to* it; every answer must be re-verified by the user against the real screen | **Secondary/fallback only** |

The failure mode named in `ANTI_PATTERNS.md` AP-13 is treating the fourth as the product. A chat panel that
renders a proposed journal entry as a Markdown table has moved the entry *out* of the component that knows
how to validate it, price it, check the period, and show the running difference — so the user must
re-verify everything by eye. **The correct rendering of an AI-proposed entry is the ordinary journal-entry
grid, pre-filled, diff-highlighted, with the same validation running.** The AI changes where the values came
from; it must not change the surface that judges them.

Two further rules that fall out of the money constraint rather than from general AI UX:

- **Never stream tokens into a field that will be read as a value.** Streaming is right for reasoning,
  status and prose; a half-rendered amount (`1,2`) is a *wrong number on screen*, and §5b applies to it
  exactly as it applies to an optimistic balance. Stream the explanation; commit the number atomically.
- **Latency honesty beats latency hiding.** A three-second AI proposal with a determinate progress state
  ("reading 42 statement lines… matching… 38 of 42 matched") is experienced as fast. The same three seconds
  behind an indeterminate spinner is experienced as broken. Nielsen's 10-second limit is the budget here,
  and the mitigation is *legibility*, not speed.

---

## 9. Data-dense UI: the craft the reconciliation workbench actually needs

The reconciliation workbench and the trial balance are the two screens where this domain's craft is not
optional. Four findings:

1. **Virtualisation is a rendering technique with three side effects, and every one of them matters more in
   finance than elsewhere.** Rendering only visible rows `[DOCS]`
   https://tanstack.com/virtual/latest/docs/introduction breaks browser find-in-page, breaks naive
   print/PDF, and breaks "select all then copy" unless explicitly handled. In a task manager these are
   annoyances; for an accountant, Ctrl+F and copy-to-Excel are core workflow. Virtualise, but ship the
   escape hatches with it — never after.
2. **The row count where virtualisation is required is higher than people think, and the row count where a
   *server-side* limit is required is lower.** A trial balance is ~60–400 rows and needs no virtualisation
   at all. A reconciliation workbench is hundreds to a few thousand and needs it. A general-ledger detail
   view is unbounded and needs **pagination or windowing at the query**, because the failure there is not
   frame rate, it is a query that scans a tenant's whole ledger.
3. **Totals must never be computed from the rendered rows.** This is the virtualisation-specific instance of
   the standing rule against trusting a cached total: with virtualisation, "the rows on screen" is a
   meaningless population, and with pagination it is a *plausible but wrong* one. Totals come from the
   server, over the full filtered set, with the filter echoed next to the total.
4. **Real-time update of a dense grid is a UX hazard, not a feature.** Rows moving while an accountant is
   reading a column is worse than stale data. The correct pattern is the one Slack and GitHub use for new
   items: hold the change, show a non-modal "3 new entries — refresh" affordance, apply on the user's
   command. This also sidesteps LiveGraph's stated open problem, that pagination "remains a challenging
   problem in the face of real-time update."

---

## 10. Comparison table

| System | Sync model | P1 read-local | P2 optimistic | P3 offline write | Conflict rule | Keyboard-first | Config surface | Most transferable idea for QAYD |
|---|---|---|---|---|---|---|---|---|
| Linear | Client replica + delta packets | ✓ full | ✓ | ✓ | LWW, total order via `lastSyncId` | ✓✓ | tiny | **Declared per-model load strategy** |
| Figma (canvas) | Server-authoritative multiplayer | ✓ | ✓ | ✓ | LWW **per property**; server rejects cycles | ~ | small | Choose the weakest sufficient mechanism; write down what it loses |
| Figma (LiveGraph) | Push from WAL, subquery tree | ~ | ✗ | ✗ | server-ordered, exactly-once | n/a | n/a | Live *invalidation* without hand-written event code |
| Notion | Optimistic online; CRDT for marked-offline pages | ~ | ✓ | ✓ (opt-in pages) | CRDT for offline pages | ~ | **huge** | "Never show a page that might be missing data" |
| Slack | Server-authoritative + push | ~ | ✓ | ✗ | server | ~ | medium | Work-graph ranking; the notification-fatigue cautionary tale |
| GitHub | Request/response + push | ✗ | ~ | ✗ | server | ✓ (palette) | medium | **Reason + triage verb** on every notification; scoped palette prefixes |
| Jira | Request/response | ✗ | ~ | ✗ | server | ✗ | **unbounded** | Negative reference for configurability |
| Monday/ClickUp/Asana | Optimistic mutation | ✗ | ✓ | ✗ | server | ✗ | large | Automation builders drift into unauditable rule soup |
| Superhuman | Local index + prefetch | ✓ | ✓ | ~ | server | ✓✓ | tiny | **The measurement method** (`event.timeStamp` → rAF, % under target) |

---

## 11. What is nearly free before the frontend exists — and what is not

The brief's central question is what to adopt *now*. The distinguishing test is not importance; it is
**retrofit cost**, and retrofit cost is high exactly where a decision is referenced from many places.

**Free now, expensive later (do these):**

| Decision | Why retrofit is expensive |
|---|---|
| **Action registry** (every operation declared once) | Retrofit means rewriting every button, every shortcut, every permission check and the entire AI tool layer |
| **Authority class on every data type** (A–E of §5c) | Retrofit means auditing every component to find which ones optimistically render a server-derived value |
| **Notification schema carrying reason + triage verb + target action** | Two columns now; a data migration plus a UI rewrite later |
| **Bidirectional (RTL) layout and numeral/currency direction rules** | The single most notorious retrofit in GCC software; every component, every icon, every chart axis `[INFERENCE]` |
| **Latency instrumentation module** | Cheap either way, but without it every later performance claim is an opinion |
| **Grid interaction contract** (ARIA grid, navigation vs edit mode, Escape semantics) | Retrofit means re-teaching users; keyboard semantics are muscle memory and cannot be changed after launch |
| **Search short-circuit for identifiers** | A ranking change later silently alters what users find |
| **A single "money value" render component** | Retrofit means hunting every place an amount is formatted, and formatting bugs in finance are trust-destroying |

**Premature now (do not do these):**

| Temptation | Why it is premature |
|---|---|
| A general sync engine | Solves a problem QAYD does not have (§5) at very high cost |
| Offline write | The correctness cost (numbering, period gates) is real; the user value at a desk is near zero |
| CRDTs anywhere | Convergence without legality (§2b); nothing in QAYD is concurrently free-text edited |
| Multiplayer cursors / presence theatre | Presence has value only as *draft conflict prevention*; the rest is decoration |
| Client-side ledger replica | Unbounded, confidential, and defeats RLS |
| An automation builder | Until the action registry exists there is nothing to compose; and see §7 |
| Pushing derived values over Reverb | Creates a second computation path for money (§5d) |
| Semantic/vector search | Slack shipped a strong ranker with *no semantic features at all*; identifiers and dates dominate accounting queries |

---

## 12. The one-paragraph answer to the central question

A financial product that feels like Linear rather than SAP does not require a sync engine. It requires
four things, three of which are free before the frontend exists: **(1)** a small, bounded, locally-replicated
*vocabulary* — accounts, tax codes, counterparties, periods, users — so that the ninety per cent of
interactions that are lookups never touch the network; **(2)** a single declared **action registry** that the
keyboard, the palette, bulk operations, automations, the audit log and the AI all project from, which is what
makes a product keyboard-addressable and what makes an AI copilot safe rather than novel; **(3)** an explicit
**authority class** on every datum, so that optimism is applied exactly where the user is the sole author and
nowhere else, and so that derived numbers are shown *stale-and-dated* rather than *predicted*; and **(4)** a
measured **latency budget** with an owner. What makes SAP feel like SAP is not that its server is
authoritative — QAYD's is too — it is that it made everything configurable, so nothing could be fast, nothing
could be keyboard-addressable, and no default could be good.

---

# End of Document
