# 06 — Lessons for QAYD

**What the research changes, confirms, or contradicts in QAYD's existing plan · `docs/research/architecture/`**

Version 1.0 · 2026-07-28

Eighteen lessons. Each states the external finding, what it means for QAYD specifically, which existing
document / principle / story it touches, and whether it **confirms**, **extends**, **corrects**, or
**contradicts** what is already written.

**The distribution has to be stated up front, because it is unusual and it changes how this folder
should be read.** QAYD's frontend is not designed — but it is *specified*, in depth: 96 documents in
`docs/frontend/**` and 72 in `docs/design-system/**`. That specification independently reaches a
striking number of this research's conclusions. Principle 1 (the frontend never holds financial logic),
Principle 8 (one cache for server state), Principle 10 (optimistic where safe, pessimistic where it
moves money), the ARIA grid for the journal line editor, "invalidate by default", the "1 new — Refresh"
banner instead of a splice, virtualisation at ~200 rows in the workbench, AI last in the palette, an
`AmountCell` with `dir="ltr"` and three-decimal KWD — all already there.

So the honest summary is: **eleven confirmations, four extensions, two corrections, one contradiction.**
The four extensions and the three corrections/contradictions are the ones to read. The confirmations
matter too, but as evidence that the plan is sound rather than as new work.

---

## Index

| ID | Lesson | Verdict | Touches |
|---|---|---|---|
| **L-01** | Replicate the vocabulary, never the ledger | **Extends** | FRONTEND_ARCHITECTURE cache table, S2-11 |
| **L-02** | Adopt Superhuman's measurement method verbatim — effort 2 | **Extends** | Web Vitals reporting |
| **L-03** | The action registry: six lists that should be one | **Extends** | ACCESSIBILITY, COMMAND_PALETTE, JOURNAL_ENTRIES, AUTOMATION_CENTER, AUDIT_LOG, ai/AIR-01 |
| **L-04** | Authority belongs on the type, not in the component | **Extends** | Principle 10, `packages/types` |
| **L-05** | The Approval Center's optimistic approve is class B rendered as class A | **Contradicts** | Principle 10's own example |
| **L-06** | Reverb carrying change facts is already the plan; the carve-out needs a class, not a risk judgement | **Corrects** | "patch only for low-risk ticks" |
| **L-07** | S2-11's client-side `deriveBalance` is a deliberate, correct second computation path | **Confirms** | S2-11, Principle 1 |
| **L-08** | Keyboard-operable and keyboard-first are different properties | **Extends** | Principle 11, ACCESSIBILITY |
| **L-09** | Cash Coding is the category's answer; QAYD's bulk bar stops one step short | **Extends** | JOURNAL_ENTRIES Bulk Action Bar, S3-12 |
| **L-10** | The AI failure to avoid is accountability, not accuracy | **Extends** | S4-02/03/04, Principle 3 |
| **L-11** | The proposal pipeline determines the copilot's UX | **Confirms** | research/ai, S4-10 |
| **L-12** | Notification reason + clearing verb is two columns now | **Extends** | NOTIFICATIONS, ERD |
| **L-13** | Slack's ranker had no semantic features; identifiers dominate here | **Confirms** | SEARCH, COMMAND_PALETTE |
| **L-14** | S2-10's virtualised chart of accounts pays four costs for no gain | **Corrects** | S2-10 acceptance criteria |
| **L-15** | RTL is paid for; the residual risk is numerals and grid keys | **Confirms** | Principle 6, S1-14 |
| **L-16** | The client data layer is specified but not installed — decide it now | **Extends** | `apps/web/package.json` vs FRONTEND_ARCHITECTURE |
| **L-17** | The reconciliation workbench is a throughput screen, not an AI screen | **Extends** | S3-12, BANK_RECONCILIATION |
| **L-18** | The money primitive is half-built and the other half is specified | **Confirms** | `packages/shared/currency.ts`, TABLE.md |

---

## L-01 — Replicate the vocabulary, never the ledger

**Finding.** Linear's speed comes from a client replica plus **declared per-model load strategies** —
`instant`, `lazy`, `partial`, `explicitlyRequested`, `local` `[COMMUNITY]`. But Linear's screens are made
of *fields* and QAYD's are made of *aggregates*, and Linear's invariants are local where QAYD's are
global (`OVERVIEW.md` §5a). The transferable part is not the sync engine; it is the declaration.

**For QAYD.** Sort the data by author, not by volatility. During journal entry the client reads
reference data on *every keystroke* and writes a draft; it touches the ledger once, at post time. So
replicate accounts, tax codes, currencies, periods, counterparties, users and saved views — a few
hundred kilobytes `[INFERENCE]` — and nothing else. That is roughly 90% of a sync engine's perceived
benefit with none of its correctness exposure, because nothing in the replicated set is money.

**Verdict: extends.** The existing cache table already tunes by data class, and its "rarely-changing
reference/master data → 5 minutes" row is the same set. The extension is (a) that these should be
*replicated and push-invalidated*, not merely cached with a long stale time, and (b) that the
replicated set should be a short, declared, reviewable list rather than an emergent property of which
hooks happen to exist. → `ARCHITECTURE.md` §3.1, `BEST_PRACTICES.md` **BP-06**.

---

## L-02 — Adopt Superhuman's measurement method verbatim

**Finding.** Superhuman measures interaction latency as **% of events under target** (buckets at 50 ms,
100 ms, 1000 ms, plus a "terrible" bucket), starting at **`event.timeStamp`** — "the time the system
logged the event" — and ending at `performance.now()` inside `requestAnimationFrame`, discarding samples
where `document.hidden` or that began before the last `visibilitychange` `[DOCS]`
https://blog.superhuman.com/performance-metrics-for-blazingly-fast-web-apps/.

**For QAYD.** The `event.timeStamp` start is the whole trick: it captures the time an event spent queued
behind a blocked main thread, which is exactly the lag the user feels and exactly what handler-entry
instrumentation hides. And "% under 100 ms" is a metric a non-engineer can own, where "p95 INP" hides
the tail.

This does not replace the existing `useReportWebVitals` pipeline — that is route- and page-load-shaped,
and it is right for what it does. INP will tell you a route is slow. It will not tell you that account
resolution in the journal grid crosses 100 ms above 300 accounts, which is the regression that actually
threatens the product's differentiator.

**Verdict: extends.** Roughly eighty lines, framework-agnostic, no dependency. **Effort: 2.** It is the
highest value-to-cost ratio item in the folder, and it should exist before S2-11 so the first grid has a
baseline. → `BEST_PRACTICES.md` **BP-07**, `ARCHITECTURE.md` §12.

---

## L-03 — The action registry: six lists that should be one

**Finding.** Every keyboard-first product in the study derives its palette, shortcuts and automations
from one declared action set. `OVERVIEW.md` §7 shows why this is also what separates Linear-style
automations from ERP workflow engines: an automation whose effect is "the system did what a permitted
user could have done" inherits the audit trail, the permission model and the reversal path for free.

**For QAYD.** QAYD already specifies its operations six times, in six good documents that do not
reference each other `[DOCS]`/`[CODE]`:

| Consumer | Where |
|---|---|
| Global shortcuts | `docs/frontend/ACCESSIBILITY.md` — a table of ~15 bindings |
| Palette actions | `docs/design-system/components/COMMAND_PALETTE.md` — "Static action list", permissions "by their key" |
| Bulk operations | `docs/frontend/JOURNAL_ENTRIES.md` — Bulk Action Bar, each re-checking its own permission |
| Automations | `docs/frontend/AUTOMATION_CENTER.md` |
| Audit labels | `docs/frontend/AUDIT_LOG.md` |
| AI tool surface | `docs/research/ai/` — the closed capability enum (**AIR-01**) |

Plus the backend truth in `apps/api/app/Actions/{Domain}/` (14 `VerbNounAction` classes today) and the
permission strings in the seeders. Adding a seventh operation means editing six documents and hoping.

Two consequences are worth being explicit about. First, `ACCESSIBILITY.md` already states the right rule
— "the shortcut and the button share one permission check, never two" — which is precisely what a
registry mechanises and what parallel lists cannot guarantee. Second, an `ai_invocable` flag on the same
record as `permission` makes the AI's tool surface a **subset of the human action surface by
construction**, which is the property the ai/ folder's architecture depends on and currently obtains by
parallel maintenance.

**Verdict: extends** — nothing here is wrong; it is six correct things that should be one thing. This is
the folder's headline and its retrofit cost is the highest of anything in it, because retrofit means
rewriting every button, every shortcut, every permission check and the AI tool layer.
→ `ARCHITECTURE.md` §4, `BEST_PRACTICES.md` **BP-10**.

---

## L-04 — Authority belongs on the type, not in the component

**Finding.** `OVERVIEW.md` §5c's A–E classification separates data by *who authors it*, which turns out
to determine cache policy, optimism, realtime treatment and rendering rules simultaneously.

**For QAYD.** The existing cache table keys on **volatility** ("how often does this change?"). Volatility
and authority correlate, but only authority is load-bearing. Volatility says "a dashboard tile changes
often, refetch often." Authority says "a dashboard tile is a derived financial figure, so it may never be
patched from an event payload and must always carry its as-of moment." The second rule prevents an
incident; the first does not.

The annotation belongs in `packages/types/`, which already holds Zod schemas mirroring the Laravel
FormRequests exactly `[CODE]`. Putting it there means the data layer can *refuse* to build an optimistic
mutation against a non-class-A schema — a lint rule, not a framework.

**Verdict: extends.** → `ARCHITECTURE.md` §2, `BEST_PRACTICES.md` **BP-01**.

---

## L-05 — The Approval Center's optimistic approve is class B rendered as class A

**Finding.** Optimism is safe exactly where the user is the only party who could contradict the value
(`OVERVIEW.md` §5b). The harm from a wrong financial pixel does not scale with how briefly it is shown,
because the user's belief outlives it.

**For QAYD.** `FRONTEND_ARCHITECTURE.md`'s Principle 10 states the rule correctly and then illustrates
it with the Approval Center's Approve action flipping a card to `status: "approved"` in `onMutate`,
justified as "reversible, non-financial" and "sensitive-adjacent" `[DOCS]`.

An approval is a **server decision**. It is subject to a permission, to step ordering in a multi-step
chain, to a version check, to someone else having approved first, and to segregation of duties — S2-06's
acceptance criteria explicitly require that the entry creator is not its sole approver `[CODE]`. The
client cannot know it will succeed. And unlike a dismissal, an approval is socially durable: the user
says "I approved it," closes the tab, and the rollback reaches a screen nobody is looking at.

**The fix is small and loses almost nothing.** Move the card to an explicit **submitting** state rather
than to `approved`. The interaction still feels instant; the system stops asserting a decision it has
not received.

**Verdict: contradicts** — one worked example, not the principle. The principle is right and this
research strengthens it. → `ANTI_PATTERNS.md` **AP-01**, `BEST_PRACTICES.md` **BP-02**, **BP-05**.

---

## L-06 — Reverb carrying change facts is already the plan; the carve-out needs a class, not a risk judgement

**Finding.** A realtime channel that carries a *derived* value creates a second computation path for that
number — the reporting query and the broadcast assembler — and second computation paths diverge silently,
usually only for the tenant with the unusual data (`OVERVIEW.md` §5d).

**For QAYD.** The plan is already right in the important place. S2-13 broadcasts "a compact
`journal.posted` projection an open ledger screen consumes to **re-fetch**" `[CODE]`, and the frontend
spec generalises it to "invalidate by default, patch only for high-frequency low-risk ticks" `[DOCS]`.

The correction is to the *carve-out*, because "low-risk" is doing work that it cannot do consistently.
Of the three named exceptions:

- **AI job progress** — safe. Not a financial figure, and the spec's own safety net (an ordinary
  invalidation at the terminal state) closes it.
- **A notification badge count** — probably safe. It counts inbox rows, not ledger rows.
- **A dashboard financial tile** — this is the one. It is class C, it may never reach a terminal state,
  and a drifted patch has no scheduled correction.

Restating the rule in terms of the class rather than the risk means the *next* tile does not have to
re-argue it: **values may be pushed for class D; class B and C get facts about change and the client
refetches.**

**Verdict: corrects** — a carve-out's boundary, not the rule. Also add: class D must be re-hydrated on
reconnect alongside the invalidation sweep the spec already does, since a missed rename would otherwise
persist in the replica indefinitely and nothing on screen would look wrong.
→ `ARCHITECTURE.md` §6, `ANTI_PATTERNS.md` **AP-04**.

---

## L-07 — S2-11's client-side `deriveBalance` is a deliberate, correct second computation path

**Finding.** L-06 warns against second computation paths for money. S2-11 specifies one: "a client-side
`deriveBalance` that flags imbalance **the same way the backend will**", with the acceptance criterion
that the grid shows a live debit/credit difference and disables Post until balanced `[CODE]`.

**For QAYD.** These do not conflict, and the reason is exactly the A–E classification. An unposted draft
is **class A**: the user is its only author, the number is about data the user is currently typing, and
nothing downstream consumes it. The derived difference is a *drafting aid*, not a reported figure. The
backend remains the sole authority on whether the entry may post — which S2-11 and Sprint 02's Epic E
preamble both already state.

Two conditions keep it correct, and both are already implied rather than stated:

1. **It must use the same money semantics** — three decimals for KWD, the same rounding — or it will
   disagree with the server at the fourth decimal and the disagreement will be blamed on the server.
   `formatMoney()`'s per-currency minor units are the shared source `[CODE]`.
2. **It must gate only the button, never the meaning.** A `422 balance_mismatch` from the server is
   surfaced, never hidden — which S2-11's acceptance criteria already require.

**Verdict: confirms**, and it is worth confirming loudly, because a reader of L-06 could otherwise
conclude that S2-11's client-side derivation is the anti-pattern. It is not: it is the textbook case of
class A, and it is why the classification is worth having rather than a blanket rule.

---

## L-08 — Keyboard-operable and keyboard-first are different properties

**Finding.** For volume data entry a keyboard grid beats a mouse-driven form by a factor that is not
close, and the mechanism is not typing speed — it is three costs per field: target acquisition, modality
switch, and a network round trip for lookup data (`OVERVIEW.md` §4).

**For QAYD.** QAYD's keyboard *accessibility* work is genuinely strong: `ACCESSIBILITY.md` is 1,495
lines, Principle 11 makes full keyboard operability a requirement of done, and the global shortcut table
already has Linear-style `G`-chords, `Cmd/Ctrl+K`, `N`, card-scoped `A`/`X`, and grid arrows `[DOCS]`.

But these are different properties:

| | Keyboard operable | Keyboard-first |
|---|---|---|
| Question | *Can* it be done without a mouse? | Is the keyboard the *fastest* way? |
| Criterion | every control reachable and actuable | the median line costs bounded keystrokes and **zero network waits** |
| Failure | a control that can only be clicked | a flow reachable by Tab that takes 40 Tabs |
| Owned by | WCAG | the data model and the vocabulary store |

The second is won in L-01 (vocabulary store) and L-03 (registry), not in the shortcut table. A `Tab`
order walking 400 grid cells is fully operable and unusable.

**Verdict: extends.** The a11y work is not the throughput work; QAYD should not conclude from the
strength of the first that it has the second. → `ARCHITECTURE.md` §5.1, `BEST_PRACTICES.md` **BP-11**,
**BP-12**, **BP-14**.

---

## L-09 — Cash Coding is the category's answer; QAYD's bulk bar stops one step short

**Finding.** Xero ships **Cash Coding**: a spreadsheet-grid bulk-coding view over bank statement lines,
**up to 200 lines at once**, sortable by date, payee, reference or description `[DOCS]`
https://central.xero.com/0/article/Reconcile-using-cash-coding-US, and permission-gated rather than
role-hardcoded — the permission can be granted to a standard user `[DOCS]`
https://xero.my.site.com/s/article/Standard-user-role-US.

**For QAYD.** What it demonstrates is worth stating precisely: the fastest way to code two hundred
transactions is neither two hundred forms nor an AI that codes them invisibly. It is **one grid where a
human applies judgement in bulk and can see every line they touched** — which is also, not
coincidentally, the shape that satisfies L-10's accountability requirement.

QAYD's Bulk Action Bar is the right mechanism and is currently scoped to whole-record verbs —
"Approve · Export · Clear", each a separate per-row POST `[DOCS]`. The missing step is bulk **field
editing**: select N rows, set account / tax code / counterparty, apply. Three things make it work and
all three are cheaper to design in than to add: an `arity: bulk` flag in the registry, a server-side
batch endpoint applying the same Action per row in one request, and a **per-row outcome** surface —
because partial success is the normal case and "197 succeeded" without naming the three failures turns a
throughput feature into an investigation.

**Where QAYD can beat the category:** one grid, one keyboard contract and one registry serving both bank
coding and journal entry. Xero has two surfaces because it grew them separately.

**Verdict: extends.** → `BEST_PRACTICES.md` **BP-13**, `ARCHITECTURE.md` §5.4.

---

## L-10 — The AI failure to avoid is accountability, not accuracy

**Finding.** Xero shipped automatic bank reconciliation `[DOCS]`
https://blog.xero.com/product-updates/automatic-bank-reconciliation-jax-beta/, and the loudest requests
on its own product board are not about the model being wrong `[COMMUNITY]`:

- **"Ability to Mark Auto Reconciliation transactions as reviewed"** — 38 votes, *Gaining Support*;
  asks for a reviewed state recording person, date and time, plus a widget filtered to unreviewed items,
  on the grounds that someone must take responsibility for what is entered.
- **"The description and/or reference doesn't pull through"** — 27 votes; the submitter's reason being
  that without the bank's description "we can't verify if the items that were auto coded are correct
  against what the bank description was".

**For QAYD.** These are one failure with two faces: **the automation removed the evidence a human needs
in order to check it.** Neither is fixed by a better model, and both make the headline benefit
unclaimable — time is saved only if the review is *shorter*, not if it is *impossible*.

Two consequences, both nearly free before the tables exist and both painful afterwards:

1. **`reviewed_by` / `reviewed_at` on every record an AI can touch**, with "unreviewed" as a default
   filter. Distinct from `created_by_agent` (already in `journal_entries` per S2-03 `[CODE]`) and
   distinct from an approval. It is the ordinary "a human has laid eyes on this" state that makes month
   end tractable.
2. **Source fields preserved verbatim and rendered beside the coded values**, so a proposal is checkable
   in one glance. This is the frontend half of **I-12 Number Provenance**.

**Verdict: extends.** Principle 3 already requires confidence, reasoning and sources on every AI-authored
element, and `AiProvenanceDot` already marks AI-touched rows `[DOCS]` — this adds the *reviewed* axis,
which is about the human rather than about the machine, and the *source-text* axis, which is about
verifiability rather than explanation. → `BEST_PRACTICES.md` **BP-25**, **BP-26**,
`ANTI_PATTERNS.md` **AP-14**.

---

## L-11 — The proposal pipeline determines the copilot's UX

**Finding.** The ai/ folder concluded QAYD should build a **deterministic proposal pipeline, not an
agent**: code chooses the control flow, the model is a pure function returning a typed proposal, and one
bounded read-only loop exists for the copilot.

**For QAYD.** This settles the AI UX more decisively than any AI-UX literature does. If the model's
output is always **a typed proposal about a specific record**, then it has an obvious home: the surface
that renders that record. The journal grid, pre-filled and diff-highlighted, with the same validation
running. Not a Markdown table in a chat panel, which moves the entry out of the only component that
knows how to judge it.

It also makes the natural-language palette surface *safe* rather than novel, but only in combination with
L-03: language resolves to a **declared action with a permission and an executor**, so "what can I ask it
to do" is enumerable and the AI cannot reach anything the user could not.

**Verdict: confirms**, and it is the strongest cross-folder confirmation in this research. The existing
frontend spec already leans the same way — the AI group is last in the palette "so the palette never
nudges toward an AI answer before a deterministic one exists" `[DOCS]`.
→ `ARCHITECTURE.md` §8, `ANTI_PATTERNS.md` **AP-13**.

---

## L-12 — Notification reason + clearing verb is two columns now

**Finding.** GitHub's notification model rests on two schema properties: a **reason** (`mention`,
`subscribed`, `review requested`, filterable as `reason:review-requested`) and a closed set of **triage
verbs** — Done, Saved, read/unread, Unsubscribe `[DOCS]`. Every notification answers *why am I seeing
this* and *what clears it*. Its documented gap is the absence of a digest `[COMMUNITY]`.

**For QAYD.** The existing notification spec is strong — a five-category taxonomy computed server-side,
per-row actions derived from the same `PERMISSION_BY_KIND` map the Approval Center uses, deliberate
restrictions on which actions appear inline `[DOCS]`. A *category* answers "what is this about". A
*reason* answers "why is it mine", and only the second stops people muting, because muting a category is
the only tool a user has when they cannot tune.

QAYD's specific volume risk is the close-period storm: one action generating dozens of notifications for
the same people at the moment they are busiest. That is what a digest exists for, and the preferences
matrix is already being built as a five-channel grid — adding a cadence axis now is cheap and adding it
after users have configured expectations is not.

**Verdict: extends.** Two columns and a taxonomy decision now; a data migration plus a UI rewrite later.
→ `BEST_PRACTICES.md` **BP-27**, **BP-28**, `ANTI_PATTERNS.md` **AP-06**.

---

## L-13 — Slack's ranker had no semantic features; identifiers dominate here

**Finding.** Slack's search re-ranker is a learning-to-rank model over Lucene using **work-graph**
signals — searcher-to-author affinity, channel priority, pins/reactions, click propensity — plus shallow
content features, trained from clicks with position-bias correction. Measured: 9% more clicked searches,
27% more clicks at position 1 `[DOCS]` https://slack.engineering/search-at-slack/. It contains **no
semantic features at all**.

**For QAYD.** Two conclusions, and the second is the important one.

The transferable half: rank *entities* — accounts, counterparties, recent entries — by recency and
affinity. A decayed interaction counter is most of the value and requires no ML. It is what makes a
palette feel telepathic.

The refusal: **identifiers must never be ranked.** `INV-4471` must return `INV-4471` by short-circuit,
before any scorer runs — not scored highly by one. The difference is that a score can be silently
changed by a later ranking improvement and a short-circuit cannot, and a search that *usually* finds the
invoice is a search nobody trusts. Accounting queries are dominated by identifiers, dates and amounts;
this is the majority case.

**Verdict: confirms** — QAYD's existing palette design already puts a synchronous, permission-filtered
Navigate source above the networked Records group precisely because "you typed a page name" is the
fastest and most certain answer `[DOCS]`. This extends that same instinct to identifiers.
→ `BEST_PRACTICES.md` **BP-19**, **BP-21**, `ANTI_PATTERNS.md` **AP-12**.

---

## L-14 — S2-10's virtualised chart of accounts pays four costs for no gain

**Finding.** Virtualisation has three well-known side effects — broken find-in-page, broken naive
print/PDF, broken select-all-copy `[DOCS]` https://tanstack.com/virtual/latest/docs/introduction — plus a
fourth the ARIA APG names directly: when a list "dynamically loads more" content, "keyboard users are
effectively trapped in the list" `[DOCS]`.

**For QAYD.** S2-10's acceptance criteria specify "a **virtualized** expandable tree/flat table over
accounts" `[CODE]`. A GCC SME chart of accounts is roughly 150–400 accounts `[INFERENCE]`. At that size
virtualisation buys nothing measurable and costs all four — and for an accountant, `Ctrl+F` and
copy-to-Excel are core workflow, not conveniences.

The contrast with the workbench is instructive and shows the existing specs are already reasoning
correctly elsewhere: `BANK_RECONCILIATION.md` sets its threshold at roughly 200 rows *with a stated
reason* — a first-ever import can land thousands of statement lines `[DOCS]`. That is the right
analysis. The COA is the case where the same reflex was applied without the same analysis.

**Verdict: corrects** — one acceptance criterion. Render the tree plainly, keep the threshold rule, and
put the effort into the escape hatches where virtualisation is genuinely required (the workbench, the
general ledger, audit logs). → `BEST_PRACTICES.md` **BP-15**, `ANTI_PATTERNS.md` **AP-09**.

---

## L-15 — RTL is paid for; the residual risk is numerals and grid keys

**Finding.** `OVERVIEW.md` §11 lists bidirectional layout as the most notorious retrofit in GCC
software.

**For QAYD.** This cost is already paid. S1-14 delivered EN/AR with full RTL `[CODE]`; Principle 6
requires both directions in the same PR that introduces a component; logical properties are mandated;
and the money formatter already forces Latin digits and comma grouping regardless of UI locale, renders
the ISO code rather than a symbol, and knows KWD/BHD/OMR carry three decimals `[CODE]`.

The residual risk is not layout. It is the two places direction interacts with *interaction* rather than
with CSS:

1. **Grid keyboard semantics do not mirror.** Columns mirror; `→` should keep its visual meaning. This
   is a bug only Arabic-first users hit and no LTR test catches — the worst combination in a GCC-first
   product.
2. **Numerals in every new numeric context** — chart axes, sparklines, palette amounts, diff highlights,
   CSV export. `AmountCell` already carries `dir="ltr"` and `numberingSystem: 'latn'` `[DOCS]`; the risk
   is entirely in the next context, and there will be many.

**Verdict: confirms**, with two specific things to pin. → `BEST_PRACTICES.md` **BP-31**, **BP-32**.

---

## L-16 — The client data layer is specified but not installed — decide it now

**Finding.** Not external. This is a `[CODE]` observation with an architectural consequence.

**For QAYD.** `apps/web/package.json` declares `next`, `react`, `react-dom` and four workspace packages,
and **no client data-fetching library at all** `[CODE]`. Reads currently flow through Server Components
and a server-side BFF (`apps/web/lib/server/bff.ts`, `sdk.ts`). `FRONTEND_ARCHITECTURE.md` specifies
TanStack Query, Zustand and Laravel Echo in detail — query-key factories, per-class cache tuning,
infinite queries feeding a virtualiser `[DOCS]`.

The gap between specification and code is the moment to settle the layering, and it will not recur:
after S2-10 and S2-11 the shape of every subsequent screen inherits whatever is chosen.

The honest case for a client cache is not "the spec says so." It is three concrete needs: the vocabulary
store (L-01), optimistic class-A mutations (L-07), and realtime invalidation (L-06). All three are real
and none is served by RSC alone. But it should be an ADR, not a dependency install — because the
alternative (RSC + server actions + targeted revalidation, with a small purpose-built vocabulary store)
is genuinely viable and is what the code does today.

**Verdict: extends.** → `IMPLEMENTATION_RECOMMENDATIONS.md` **UXR-07**, `ARCHITECTURE.md` §14 Q2.

---

## L-17 — The reconciliation workbench is a throughput screen, not an AI screen

**Finding.** The two axes of this domain are separable: "it feels instant" (latency) and "it makes
repetitive work fast" (throughput), and axis 2 is the neglected, cheaper one in SME accounting
(`OVERVIEW.md` §1).

**For QAYD.** S3-12's workbench is where axis 2 is won or lost, and the risk is that it gets designed
as a showcase for the Banking Agent's suggestions rather than as a bulk-coding surface that happens to
have suggestions pre-staged.

The existing spec is already thoughtful on the hard parts — panes virtualising past ~200 rows,
`Cmd/Ctrl+Enter` committing the Match Tray's primary action from anywhere on the page, fraud holds as
blocking banners rather than dismissible toasts, a POS settlement's many:1 split pre-staged as a
suggestion `[DOCS]`. Those are good instincts.

What the throughput framing adds: the workbench's success metric is **lines cleared per minute by a
human**, not match acceptance rate. That metric favours bulk field editing (L-09), a keyboard contract
shared with the journal grid (L-08), server-side totals on the difference (BP-16), and held-not-spliced
updates (BP-18) — and it is indifferent to how impressive the suggestions look.

**Verdict: extends.** → `ARCHITECTURE.md` §7, `BEST_PRACTICES.md` **BP-13**.

---

## L-18 — The money primitive is half-built and the other half is specified

**Finding.** `OVERVIEW.md` §11 lists "a single money value render component" among the things that are
free now and expensive later, because retrofit means hunting every place an amount is formatted and
formatting bugs in finance are trust-destroying.

**For QAYD.** Both halves already exist as artefacts; they are just not joined. The numeric core is
built and good `[CODE]` (`packages/shared/src/currency.ts` — KWD/BHD/OMR three decimals, forced Latin
digits and comma grouping, ISO code not symbol, minus for editable data and parentheses for statements).
The component's token treatment is fully specified `[DOCS]` (`docs/design-system/components/TABLE.md`'s
`AmountCell`: `font-mono tabular-nums`, `dir="ltr"`, `text-end`, emphasis variants). The React primitive
itself does not exist in `packages/ui/src/components/`, which currently holds button, card, input,
label, select and icons `[CODE]`.

The file's own header even names the intended shape: a framework-free numeric core with the app's
`<Amount>` primitive layered on top.

**Verdict: confirms.** This is assembly, not design, and it should happen before the first screen that
renders an amount — which is S2-12, the trial balance.
→ `BEST_PRACTICES.md` **BP-04**, **BP-31**.

---

## What the research did *not* change

Worth recording, because a research folder that changes everything is usually wrong:

- **Principle 1** (the frontend never holds financial logic) — confirmed, and L-07 shows the boundary is
  drawn in exactly the right place.
- **Principle 8** (one cache for server state) — confirmed; the A–E classification refines *how* it is
  tuned, not whether it is single.
- **Principle 9** (client-generated idempotency keys per logical submission) — confirmed and untouched.
- **Principle 3** (AI proposes, a human approves) — confirmed; L-10 adds the reviewed axis without
  weakening it.
- **The design system's restraint** — hairline elevation, no zebra, one accent, `AiProvenanceDot` as the
  only filled status dot. Nothing in this research argues with any of it.
- **The channel naming convention and the reconnect-invalidation sweep** — confirmed; L-06 only adds
  class D to the sweep.

# End of Document
