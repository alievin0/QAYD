# 03 — Best practices for QAYD's application & UX layer

**Thirty-two practices worth adopting, each argued and costed · `docs/research/architecture/`**

Version 1.0 · 2026-07-28

Evidence for every external claim lives in `OVERVIEW.md`; this document cites the section rather than
repeating it. The practices worth refusing are in `ANTI_PATTERNS.md`.

**Effort is Fibonacci. Confidence is High/Medium/Low with a stated reason.** Where a practice confirms
something `docs/frontend/**` or `docs/design-system/**` has already decided, that is said plainly —
several of these are confirmations rather than proposals, and pretending otherwise would waste a
reviewer's time. Where a practice *conflicts* with an existing decision, the conflict is named.

The eight sections are ordered by how expensive they are to retrofit, not by importance.

---

## Index

| § | ID | Practice | Effort | Priority | Confidence |
|---|---|---|---|---|---|
| **1 — Authority & rendering honesty** |
| | **BP-01** | Give every resource type an authority class (A–E) | 3 | P0 | High |
| | **BP-02** | Optimism only where the user is the sole author | 2 | P0 | High |
| | **BP-03** | Render derived figures stale-and-dated, never predicted | 3 | P0 | High |
| | **BP-04** | One money primitive over one formatter | 3 | P0 | High |
| | **BP-05** | Show *pending*, not a guess, for in-flight server facts | 2 | P0 | High |
| **2 — Perceived performance** |
| | **BP-06** | Replicate the vocabulary (class D) into a client store | 8 | P0 | Medium-High |
| | **BP-07** | Measure interaction latency the Superhuman way | 2 | P0 | High |
| | **BP-08** | Per-interaction-class budgets with a named owner | 2 | P1 | Medium-High |
| | **BP-09** | Prefetch on declared intent, not on everything | 3 | P2 | Medium |
| **3 — Keyboard-first throughput** |
| | **BP-10** | Declare every operation once, in an action registry | 8 | P0 | High |
| | **BP-11** | One grid contract: navigation mode vs edit mode | 8 | P0 | High |
| | **BP-12** | Zero network on a keystroke in the entry path | 3 | P0 | High |
| | **BP-13** | Bulk field editing with a batch endpoint and per-row outcomes | 8 | P1 | Medium-High |
| | **BP-14** | "Completable without the mouse" is a definition-of-done item | 2 | P1 | High |
| **4 — Data-dense surfaces** |
| | **BP-15** | Virtualise on a measured threshold, and ship the escape hatches with it | 5 | P1 | High |
| | **BP-16** | Totals come from the server over the full filtered set | 3 | P0 | High |
| | **BP-17** | Column, density and sort state is persisted per user per view | 2 | P2 | High |
| | **BP-18** | Hold incoming changes behind an explicit affordance | 2 | P1 | High |
| **5 — Search & navigation** |
| | **BP-19** | **Identifier short-circuit: exact match returns before any scorer runs** | 2 | P0 | High |
| | **BP-20** | One palette input, several scopes, declared by prefix | 3 | P1 | Medium-High |
| | **BP-21** | Rank entities by recency and affinity, not by text similarity | 5 | P2 | Medium |
| **6 — AI surfaces** |
| | **BP-22** | Render a proposal in the surface that validates it | 5 | P0 | High |
| | **BP-23** | Stream the reasoning; commit the number atomically | 2 | P0 | High |
| | **BP-24** | Determinate progress for anything over one second | 3 | P1 | High |
| | **BP-25** | `reviewed_by`/`reviewed_at` as a first-class, filterable state | 3 | P0 | High |
| | **BP-26** | Preserve and display the source text the AI coded from | 2 | P0 | High |
| **7 — Notification, collaboration, workflow** |
| | **BP-27** | Every notification carries a reason and a clearing verb | 3 | P1 | High |
| | **BP-28** | Digest by default; realtime by exception | 5 | P2 | Medium |
| | **BP-29** | Presence only as draft-conflict prevention | 3 | P3 | Medium |
| | **BP-30** | Automations are registry actions with a bounded blast radius | 5 | P2 | High |
| **8 — Bilingual, numerals, direction** |
| | **BP-31** | Every numeric surface goes through the one primitive | 2 | P1 | High |
| | **BP-32** | Layout mirrors; keyboard semantics do not | 2 | P1 | Medium-High |

---

# §1 — Authority and rendering honesty

## BP-01 — Give every resource type an authority class

**Practice.** Every response type in `packages/types/` carries a declared authority class — A (client
draft), B (server fact), C (server-derived aggregate), D (shared reference), E (local preference) — and
the data layer refuses to construct an optimistic mutation against anything that is not class A.

**Why.** The failure this prevents is invisible in code review: a component that optimistically flips
`status: "approved"` is syntactically identical to one that flips `dismissed: true`. The difference is
*who is allowed to author the field*, and only the type knows that. `OVERVIEW.md` §5c derives the
classes; `ARCHITECTURE.md` §2 turns them into a contract.

**Benefits.** Turns "be careful with optimism around money" from a review heuristic into a mechanical
check. Makes cache policy derivable rather than per-resource guesswork. Gives realtime a rule (§6 of
`ARCHITECTURE.md`) instead of a judgement call about "risk".

**Tradeoffs.** One more annotation per schema, and a small number of genuinely ambiguous cases that have
to be argued once (is a notification badge count class C or class E? — see AP-04).

**Risks.** The classes get applied mechanically to new types without thought, producing a false sense of
safety. Mitigate by requiring the class in the same PR as the schema and reviewing it as a decision.

**Scalability / performance.** Neutral — it is metadata. **Maintainability.** Substantially better.
**Complexity.** Lower than the alternative, which is per-component judgement.
**Effort: 3** — mostly a decision plus a lint rule.
**Business impact.** This is the practice that makes "QAYD never shows you a number that is not true"
defensible rather than aspirational.
**Confidence: High** — the taxonomy is derived from a structural property of the data, not from a
vendor's preference.

---

## BP-02 — Optimism only where the user is the sole author

**Practice.** Optimistic rendering is permitted for class A only: unposted drafts and their lines,
import mappings in progress, proposed matches, filters, selections, wizard state, local preferences.
Everything else waits for the server.

**Why.** Not because latency is dangerous, but because **the durability of a user's belief exceeds the
durability of the pixel** (`OVERVIEW.md` §5b). A number rendered for 300 ms can be read aloud to a bank,
pasted into an email, screenshotted, or used to approve a payment. Correcting the pixel does not correct
the world.

**Benefits.** Removes an entire class of incident. Makes the rollback path shallow, because the only
things that roll back are things nobody else could have contradicted.

**Tradeoffs.** Some interactions feel slower than a competitor's. This is the trade and it should be
made consciously rather than apologised for — the mitigation is BP-05 (an honest pending state) and
BP-06 (removing the network from the interactions that dominate).

**Risks.** The rule erodes one exception at a time, each individually reasonable. Mitigate with BP-01,
which makes the exception a type error rather than a debate.

**Conflict to name.** `docs/frontend/FRONTEND_ARCHITECTURE.md` Principle 10 states the rule correctly —
"optimistic where safe, pessimistic where it moves money" — but its worked example optimistically flips
an Approval Center card to `approved`. An approval is a server decision subject to permission,
segregation of duties (S2-06 requires that the entry creator is not its sole approver) and step
ordering, so the client cannot know it will succeed. See `ANTI_PATTERNS.md` **AP-01** and
`LESSONS_FOR_QAYD.md` **L-05**.

**Scalability / performance.** Slightly worse on paper, unchanged in practice once BP-06 lands.
**Maintainability.** Better. **Complexity.** Lower.
**Effort: 2** (it is a rule; BP-01 is the enforcement).
**Business impact.** The single most trust-relevant decision in the frontend.
**Confidence: High.**

---

## BP-03 — Render derived figures stale-and-dated, never predicted

**Practice.** Every class-C figure — trial balance, account balance, reconciliation difference, period
total, dashboard tile — is rendered together with its **as-of moment** and the **filter that produced
it**. "Trial balance · as of 14:32 · periods 2026-01…07" is a true statement; "Trial balance" over the
same numbers is a claim about *now* the client cannot support.

**Why.** `staleTime: 0` is a refetch policy, not a rendering rule. Refetching aggressively makes the
number fresher; it does not make the screen honest about the moment it describes. And a dated figure is
one step from a *dereferenceable* figure — the same component that shows "as of" should show "from
these rows" (`07_QAYD_INNOVATION.md` **I-12**).

**Benefits.** Removes the ambiguity that makes users screenshot a figure and argue about it later.
Makes a slow report acceptable instead of suspicious. Creates the natural surface for provenance.

**Tradeoffs.** More chrome per figure, and a design problem (this must not become visual noise on a
dashboard of twelve tiles). Solve it once in the component, not per screen.

**Risks.** The timestamp is rendered from the client's clock rather than the server's response, which
would be wrong in exactly the situation where it matters. The as-of value must come from the payload.

**Scalability / performance.** Neutral. **Maintainability.** Better — one component.
**Complexity.** Slightly higher in the component, much lower across screens.
**Effort: 3.**
**Business impact.** This is the practice that lets QAYD be *honest* about latency instead of hiding it,
which is the only defensible position for a ledger.
**Confidence: High.**

---

## BP-04 — One money primitive over one formatter

**Practice.** Exactly one React primitive renders an amount, layered over the existing
`formatMoney()`, and nothing else formats a number that represents money.

**Why.** The numeric core already exists and is good `[CODE]` (`packages/shared/src/currency.ts`):
KWD/BHD/OMR get three decimals, Latin digits and comma grouping are forced regardless of UI locale, the
ISO code is shown rather than a symbol, and negatives can be minus (editable data) or parentheses
(statements). The component that consumes it does not exist yet in `packages/ui/`, and the design system
already specifies exactly what it should be — `AmountCell`: `font-mono tabular-nums`, `dir="ltr"`,
`text-end`, emphasis variants `[DOCS]` (`docs/design-system/components/TABLE.md`). Building it now costs
nothing; retrofitting means hunting every place an amount is drawn, and formatting bugs in finance are
trust-destroying in a way that formatting bugs elsewhere are not.

**Benefits.** Direction, numeral system, decimal count, alignment, sign convention and tabular figures
become impossible to get wrong per-screen. New numeric surfaces (chart axes, palette results, CSV
export, diff highlights) inherit correctness.

**Tradeoffs.** Every amount goes through one component, which is a constraint on unusual layouts. That
constraint is the feature.

**Risks.** The primitive is bypassed "just this once" for a chart label or an export. Mitigate with a
lint rule against raw `toLocaleString`/`Intl.NumberFormat` outside the primitive.

**Scalability / performance.** Neutral. **Maintainability.** Substantially better.
**Complexity.** Lower. **Effort: 3.**
**Business impact.** A wrong-looking KWD amount costs more credibility than a slow screen.
**Confidence: High** — the formatter and the token spec both already exist; this is assembly.

---

## BP-05 — Show *pending*, not a guess, for in-flight server facts

**Practice.** While a class-B write is in flight, the UI shows the **previous** value plus an explicit
pending affordance. It does not show the predicted new value, and it does not show a blank.

**Why.** Pessimism does not require a spinner over the whole screen. The honest state is "this is what
it says now; a change is being applied," which is both truthful and calm. A blank is worse than stale
because it reads as zero.

**Benefits.** Retains context during the wait. Makes failure legible — the value simply never changed,
and the error explains why.

**Tradeoffs.** Two visual states to design rather than one.

**Risks.** "Pending" becomes a permanent state when a request is lost. Every pending state needs a
timeout that resolves to an explicit failure, never to silence.

**Scalability / performance.** Neutral. **Maintainability.** Better. **Complexity.** Slightly higher.
**Effort: 2.**
**Business impact.** This is what makes BP-02 tolerable to users.
**Confidence: High.**

---

# §2 — Perceived performance

## BP-06 — Replicate the vocabulary (class D) into a client store

**Practice.** Accounts, account types, tax codes, currencies, fiscal periods, cost centres,
counterparties, users/roles and saved views are hydrated once per company session into a client store,
push-invalidated, and read synchronously by every lookup.

**Why.** During journal entry the client reads class D on *every keystroke* and writes class A; it
touches class B once, at post time. Removing the network from that read is where nearly all of the
perceived-speed benefit of a sync engine lives, at a fraction of the cost and with none of the
correctness exposure — nothing in class D is money (`OVERVIEW.md` §5c). Linear's transferable idea is
the **declared per-model load strategy** `[COMMUNITY]`, because declaring what is on the client is what
makes partial replication safe.

**Benefits.** Account resolution, tax-code validation and counterparty suggestion become sub-50 ms
operations. Makes BP-11's zero-round-trip commit rule achievable at all. Makes the palette's Navigate
and Records-by-code paths instant.

**Tradeoffs.** A hydration cost at session start, a cache-coherence problem, and a store to keep bounded.

**Risks.** Scope creep into class B — someone adds "and the last 50 entries" and the replica becomes the
ledger. Mitigate by making the replicated set a short, explicitly declared list reviewed as a whole.
Second risk: an unusually large tenant blows the bound; the store needs a declared ceiling and a
`queried` fallback per resource.

**Scalability.** Bounded by the *reference* data, which grows slowly, not by the ledger, which does not.
**Performance.** Substantially better on the hot path; a measurable cost at session start.
**Maintainability.** Moderate — invalidation is the hard part. **Complexity.** Real but contained.
**Effort: 8.**
**Business impact.** This is the single largest contributor to "it feels like Linear."
**Confidence: Medium-High** — the mechanism is sound; the *size* of a real GCC SME's class-D set is
`[INFERENCE]` and should be measured (`ARCHITECTURE.md` §14 Q1).

---

## BP-07 — Measure interaction latency the Superhuman way

**Practice.** Instrument interactions with start = `event.timeStamp`, end = `performance.now()` inside
`requestAnimationFrame`, bucketed as % under 50 ms / 100 ms / 1000 ms, discarding samples where
`document.hidden` or which began before the last `visibilitychange` `[DOCS]`
https://blog.superhuman.com/performance-metrics-for-blazingly-fast-web-apps/.

**Why.** Starting at `event.timeStamp` rather than at handler entry is the subtle and correct choice: it
captures time the event spent queued behind a blocked main thread, which is exactly the lag a user feels
and exactly what naive instrumentation hides. And "% under 100 ms" is a number a non-engineer can be
held to, where "p95 INP" hides the tail that makes users angry.

**Benefits.** Roughly eighty lines, framework-agnostic, and it turns every later performance claim from
an opinion into a measurement. Complements the existing Core Web Vitals reporting, which is route-shaped
and will not tell you that account resolution crossed 100 ms above 300 accounts.

**Tradeoffs.** Another telemetry stream to route and retain. Interaction naming needs a convention or
the buckets are unattributable — the action registry (BP-10) supplies it for free.

**Risks.** It gets built and never looked at. Mitigate with BP-08: a budget with an owner.

**Scalability / performance.** Negligible overhead. **Maintainability.** Trivial. **Complexity.** Low.
**Effort: 2.**
**Business impact.** Without it, "fast" is a claim; with it, it is a number in a review.
**Confidence: High** — the method is published in full and the code is small.

---

## BP-08 — Per-interaction-class budgets with a named owner

**Practice.** Each interaction class carries a budget and a percentage target
(`ARCHITECTURE.md` §12), reviewed like a bundle budget, owned by a person.

**Why.** Latency in a data-entry tool multiplies by the number of entries — but the real cost is
nonlinear: above roughly one second the operator's flow of thought breaks and they re-read the source
document, which costs tens of seconds `[DOCS]` (Nielsen). The budget's job is to catch the crossing,
not to shave milliseconds.

**Benefits.** Regressions get caught by class rather than by route, which is where they actually occur.
**Tradeoffs.** Initial numbers are `[INFERENCE]` and will be wrong; they must be replaced by measurement
rather than defended.
**Risks.** Budgets set so loose they never fire. Set them from the first real measurement, tightened.
**Scalability / performance / maintainability / complexity.** Neutral, neutral, better, low.
**Effort: 2.**
**Business impact.** Makes speed a commitment rather than a value.
**Confidence: Medium-High** — the method is proven; QAYD's specific numbers are not yet.

---

## BP-09 — Prefetch on declared intent, not on everything

**Practice.** Prefetch a route or a record when the user has *declared* intent — focus on a row, palette
selection highlighted, pointer held on a link past a threshold — not on every hover and not
speculatively across a whole list.

**Why.** Indiscriminate prefetch on a tenant-scoped API converts a UI nicety into a load multiplier and
an RLS-scoped query storm. Declared intent captures most of the benefit at a fraction of the requests.

**Benefits.** Detail views feel instant from a list. Costs are proportional to attention.
**Tradeoffs.** Some misses. **Risks.** Prefetching a *class-C* figure and then rendering it as if fresh
— prefetching must not bypass BP-03.
**Scalability.** Must be bounded per session or it is an attack on your own API.
**Performance.** Better perceived, worse absolute. **Maintainability.** Neutral. **Complexity.** Low.
**Effort: 3.**
**Business impact.** Moderate. **Confidence: Medium** — thresholds are taste until measured.

---

# §3 — Keyboard-first throughput

## BP-10 — Declare every operation once, in an action registry

**Practice.** One declaration per operation — id, bilingual label, permission, target, arity,
optimistic (derived), confirm, shortcut, palette scope, automatable, ai_invocable, idempotent —
generated into both TypeScript and PHP, projected by the keyboard layer, the palette, the bulk bar,
automations, the audit log and the AI tool surface. Full design in `ARCHITECTURE.md` §4.

**Why.** QAYD already specifies its operations in six independent, individually good places: the global
shortcut table in `ACCESSIBILITY.md`, the palette's "static action list" in `COMMAND_PALETTE.md`, the
Bulk Action Bar in `JOURNAL_ENTRIES.md`, `AUTOMATION_CENTER.md`, `AUDIT_LOG.md`, and the ai/ folder's
closed capability enum `[DOCS]`. None references the others. Adding an operation today means editing six
documents and hoping.

**Benefits.** A keyboard-addressable product becomes structurally possible rather than heroically
maintained. Automations become "the system did what a permitted user could have done", which is the
property ERP workflow engines lack and the reason "the workflow did it" is an unanswerable audit finding
there (`OVERVIEW.md` §7). The AI's tool surface becomes a *subset* of the human action surface by
construction rather than by parallel maintenance — the property the ai/ folder's architecture rests on.

**Tradeoffs.** A codegen step and a small ceremony per new operation. QAYD already runs codegen for
types (Principle 7), so the machinery exists.

**Risks.** It grows into a client-side command bus that duplicates the Laravel Actions. It must stay a
**catalogue** — metadata about operations, not an execution layer.

**Scalability.** Better: the marginal cost of the seventh consumer is zero. **Performance.** Neutral.
**Maintainability.** Substantially better. **Complexity.** Higher on day one, much lower by operation
twenty. **Effort: 8.**
**Business impact.** The folder's headline. Free before the frontend exists; near-impossible after.
**Confidence: High** on the value; **Medium** on the exact field set, which will change on contact.

---

## BP-11 — One grid contract: navigation mode vs edit mode

**Practice.** A single grid implementation, honouring the ARIA APG `grid` pattern: roving `tabindex`
(one cell in the tab sequence), arrows to move, `Home`/`End` in-row, `Ctrl+Home`/`Ctrl+End` to grid
bounds, `Enter`/`F2` to enter edit mode, **`Escape` restores navigation without committing**, `Enter`
commits and moves down, `Tab` commits and moves right, committing on the last row appends
`[DOCS]` https://www.w3.org/WAI/ARIA/apg/patterns/grid/.

**Why.** The two-mode concept is what home-grown grids get wrong, and it is the difference between a
grid an accountant can live in and a table with inputs in it. QAYD already names the ARIA grid pattern
for the journal line editor and the reconciliation matching grid `[DOCS]`; the practice is that there is
**one** implementation, because keyboard semantics are muscle memory and two grids that disagree are
worse than one grid that is imperfect.

**Benefits.** A twelve-line entry becomes one uninterrupted typing run. Accessibility and throughput are
satisfied by the same mechanism rather than traded against each other.

**Tradeoffs.** A shared grid is a heavier component with more props than two bespoke ones. Worth it at
the second consumer.

**Risks.** The APG names the specific failure to avoid: when a list "dynamically loads more" content,
"keyboard users are effectively trapped." Any virtualised or infinite grid must make the loading
boundary keyboard-crossable, and that must be tested rather than assumed.

**Scalability.** One contract across N screens. **Performance.** Neutral to better.
**Maintainability.** Substantially better. **Complexity.** Concentrated, which is the right shape.
**Effort: 8.**
**Business impact.** This is the component the highest-volume activity in the product runs on.
**Confidence: High** — the standard is unambiguous and QAYD has already chosen it.

---

## BP-12 — Zero network on a keystroke in the entry path

**Practice.** In the journal grid and the coding grid, no keystroke may initiate a request. Account
resolution, tax-code validation, counterparty suggestion and postability checks are answered from the
class-D store.

**Why.** A mouse-driven form imposes three costs per field that a grid does not — target acquisition,
modality switch, and the expensive one, a **round trip for lookup data** when the picker fetches on open
(`OVERVIEW.md` §4). Three of these per line at twelve lines is the difference between a ninety-second
entry and a twenty-second one. Note that this is not a component-craft property: a grid whose account
cell fetches on open cannot honour it at any level of polish.

**Benefits.** Makes the inner loop feel like a spreadsheet rather than a web form. Removes the
dominant source of tail latency on the hot path.

**Tradeoffs.** Requires BP-06, which is the expensive prerequisite. **Risks.** A "just this one lookup"
exception in an edge case (an unusual dimension, a rarely used field) reintroduces the round trip on a
path users hit daily. Enforce as a test: assert zero fetches during a scripted twelve-line entry.

**Scalability / performance.** Substantially better. **Maintainability.** Neutral.
**Complexity.** Moved into the store rather than added. **Effort: 3** given BP-06.
**Business impact.** High and directly measurable in entries per hour.
**Confidence: High.**

---

## BP-13 — Bulk field editing with a batch endpoint and per-row outcomes

**Practice.** A selection-based grid mode that sets a field across N rows — account, tax code,
counterparty — backed by a **server-side batch endpoint** that applies the same Action per row inside
one request and returns a **per-row result**, rendered as a per-row outcome rather than a count.

**Why.** Xero's Cash Coding is the category's existing answer: a spreadsheet-style bulk-coding view over
statement lines, up to **200 lines at once**, sortable by date, payee, reference or description
`[DOCS]` https://central.xero.com/0/article/Reconcile-using-cash-coding-US, permission-gated rather than
role-hardcoded `[DOCS]` https://xero.my.site.com/s/article/Standard-user-role-US. What it demonstrates is
that the fastest way to code two hundred transactions is neither two hundred forms nor an invisible AI —
it is one grid where a human applies judgement in bulk and can see every line they touched.

QAYD's existing Bulk Action Bar is the right mechanism and is currently scoped to whole-record verbs
(Approve/Export/Clear) with each action a separate POST `[DOCS]`. Per-row POSTs are correct and are also
N round-trips; at 200 rows the round-trips are the feature's cost.

**Benefits.** Turns the highest-volume, lowest-judgement work in bookkeeping from hours into minutes.
The same grid and the same registry actions serve both bank coding and journal entry, where Xero grew
two separate surfaces.

**Tradeoffs.** A batch endpoint is a new API shape with partial-failure semantics, and partial failure
is the *normal* case rather than the exception.

**Risks.** The big one: a bulk operation that reports "197 succeeded" without saying which three failed
converts a throughput feature into an investigation. Per-row outcomes are not a nicety. Second risk:
bulk apply becomes a way to bypass a per-row validation — it must call the same Action, not a shortcut.

**Scalability.** Better — one request rather than N. **Performance.** Substantially better at N > 20.
**Maintainability.** Moderate. **Complexity.** Real, in the partial-failure contract.
**Effort: 8.**
**Business impact.** This is the feature an accountant switches products for.
**Confidence: Medium-High** — the pattern is proven in-market; QAYD's exact scope is a design-partner
question (`ARCHITECTURE.md` §14 Q3).

---

## BP-14 — "Completable without the mouse" is a definition-of-done item

**Practice.** Every flow carries an explicit test: can a competent user complete this without touching
the pointer? Asserted in the E2E suite for the entry, coding and review flows, not left to intent.

**Why.** Keyboard *operability* (a11y) and keyboard-*first* throughput are different properties
(`ARCHITECTURE.md` §5.1). QAYD's Principle 11 already requires the first. The second is what makes the
product fast, and it decays silently — one dialog whose confirm button is not reachable, one picker that
traps focus, and the flow is broken for the users who care most.

**Benefits.** Catches regressions that no visual test sees. Costs almost nothing once the harness
exists. **Tradeoffs.** Keyboard E2E tests are more brittle than click-based ones.
**Risks.** It becomes a checkbox rather than a real traversal. Write the test as a *task*, not as a
sequence of key assertions.
**Scalability / performance.** n/a. **Maintainability.** Neutral. **Complexity.** Low.
**Effort: 2** per flow.
**Business impact.** Protects the thing the product is differentiated on.
**Confidence: High.**

---

# §4 — Data-dense surfaces

## BP-15 — Virtualise on a measured threshold, and ship the escape hatches with it

**Practice.** Virtualise when a *measured* row count justifies it — QAYD's reconciliation workbench
already sets this at roughly 200 rows `[DOCS]` — and never below. Where virtualisation is used, the
escape hatches ship in the same change: an in-grid find that scrolls to and focuses the match, an
"export this view" that goes to the server over the full filtered set, and a copy that serialises the
filtered set rather than the mounted rows.

**Why.** Rendering only visible rows `[DOCS]` https://tanstack.com/virtual/latest/docs/introduction has
three side effects — broken find-in-page, broken naive print/PDF, broken select-all-copy. In a task
manager these are annoyances. For an accountant, `Ctrl+F` and copy-to-Excel are the job.

The negative case matters equally: a trial balance is 60–400 rows and a GCC SME chart of accounts is
150–400 `[INFERENCE]` — **neither needs virtualisation**, and virtualising them costs the three side
effects for no gain. S2-10 currently specifies a virtualized COA tree `[CODE]`; that is worth revisiting
(`LESSONS_FOR_QAYD.md` **L-14**).

**Benefits.** Smooth scroll where it is needed; full browser affordances where it is not.
**Tradeoffs.** Two rendering paths in the table component, gated on a threshold.
**Risks.** The escape hatches are deferred "to the next sprint" and never arrive, at which point users
have already learned the screen is hostile.
**Scalability.** Required above a few thousand rows. **Performance.** Substantially better where used.
**Maintainability.** Moderate. **Complexity.** Moderate. **Effort: 5** for the hatches.
**Business impact.** High for the workbench; negative if applied where unnecessary.
**Confidence: High.**

---

## BP-16 — Totals come from the server over the full filtered set

**Practice.** A footer total, a subtotal, a count and a difference are computed server-side over the
full filtered set and returned with the page. The filter that produced them is echoed next to them.

**Why.** With virtualisation, "the rows on screen" is a meaningless population. With pagination it is a
*plausible but wrong* one, which is more dangerous. This is the standing rule against trusting a cached
total, instantiated at the rendering layer.

**Benefits.** A total is always a statement about a defined set. Makes the filter-echo (BP-03) natural
rather than bolted on.
**Tradeoffs.** Every dense list endpoint returns an aggregate alongside its page, which is a real query
cost at scale — one that the analytics/ folder's index work is the right place to absorb.
**Risks.** A client-side "quick sum" is added for responsiveness and diverges. Forbid it in the grid
component rather than in review.
**Scalability.** Needs an index; it is a `SUM()` over a filtered, RLS-scoped set. **Performance.** A
real cost, correctly placed. **Maintainability.** Better. **Complexity.** Low on the client.
**Effort: 3** per list contract.
**Business impact.** A wrong total on a reconciliation screen is the worst single bug this product could
ship. **Confidence: High.**

---

## BP-17 — Column, density and sort state is persisted per user per view

**Practice.** Column order, width and visibility, density, sort and saved filters are class E: stored
locally per user per view, restored on return, never round-tripped.

**Why.** Density is already specified as "a per-table, user-persisted toggle, not a global setting" with
the reasoning that the same accountant reconciles four thousand lines in Compact and reviews twelve
vendors in Comfortable `[DOCS]`. That reasoning generalises: an accountant who arranges a workbench once
and finds it arranged tomorrow gets a minute a day back and, more importantly, stops re-orienting.

**Benefits.** Cheap throughput. Makes dense screens feel owned rather than imposed.
**Tradeoffs.** Per-view state multiplies; needs a namespacing scheme and a reset.
**Risks.** Anything company-scoped leaking into a persisted store across a company switch. The existing
spec is already explicit that only inert preferences are persisted — hold that line.
**Scalability / performance.** Negligible. **Maintainability.** Neutral. **Complexity.** Low.
**Effort: 2.**
**Business impact.** Small individually, compounding daily. **Confidence: High.**

---

## BP-18 — Hold incoming changes behind an explicit affordance

**Practice.** A dense grid never splices, re-sorts or re-flows under a reading user. Incoming changes
are held and announced — "3 new entries · Refresh" — and applied on the user's command.

**Why.** Rows moving while an accountant reads a column is worse than stale data. QAYD already
specifies exactly this for the notifications feed `[DOCS]`; the practice is to generalise it to every
dense grid. It also sidesteps the open problem Figma names for LiveGraph — pagination "remains a
challenging problem in the face of real-time update" `[DOCS]`.

**Benefits.** Live data without a moving target. Makes realtime a benefit rather than a hazard.
**Tradeoffs.** The screen is knowingly stale between refreshes — which is fine, because BP-03 says so
on the screen.
**Risks.** The affordance is missed and the user works on stale data for an hour. Pair it with the
as-of timestamp so staleness is visible in two places.
**Scalability / performance.** Better — fewer re-renders. **Maintainability.** Better.
**Complexity.** Low. **Effort: 2.**
**Business impact.** Moderate; prevents a category of "the screen jumped and I clicked the wrong row".
**Confidence: High.**

---

# §5 — Search and navigation

## BP-19 — Identifier short-circuit: exact match returns before any scorer runs

**Practice.** A query that matches an identifier — entry number, invoice number, account code, document
reference, a well-formed amount, a date — short-circuits to that record. The result is returned
**before** any ranking runs, not scored highly by it.

**Why.** Accounting queries are dominated by identifiers, dates and amounts; this is the majority case,
not the edge case. And the distinction between "ranked first" and "short-circuited" is not pedantry: a
score can be silently changed by a later ranking improvement, and a search that *usually* finds
`INV-4471` first is a search nobody trusts. Slack's own ranker is the cautionary half of the same story —
it demonstrably improves relevance (9% more clicked searches, 27% more clicks at position 1 `[DOCS]`
https://slack.engineering/search-at-slack/) and it can also bury an exact match.

**Benefits.** The single most common query in the product becomes deterministic. Makes the palette
usable as a jump-to-record tool, which is what accountants will actually use it for.
**Tradeoffs.** Identifier patterns are per-entity and must be maintained as new document types appear.
**Risks.** Two entities share an identifier shape (an invoice number and a reference); the short-circuit
must then present both deterministically rather than pick one. BP-20's prefixes let the user
disambiguate explicitly.
**Scalability.** Better — an exact lookup avoids the scorer entirely. **Performance.** Substantially
better. **Maintainability.** Neutral. **Complexity.** Low. **Effort: 2.**
**Business impact.** High, and it is the kind of thing users notice in a demo.
**Confidence: High.**

---

## BP-20 — One palette input, several scopes, declared by prefix

**Practice.** The palette accepts scope prefixes — `>` action, `#` entry/document number, `@`
counterparty, `/` account, `:` period — with `Tab` narrowing and `Backspace` widening.

**Why.** GitHub's palette is one input serving several search spaces via prefixes, with the stated
purpose of running commands "without navigating through a series of menus" `[DOCS]`
https://docs.github.com/en/get-started/accessibility/github-command-palette. Accountants already think
in namespaces and already know their codes, so the mapping is unusually direct. A prefix is a *scope
declaration*, which turns one fuzzy input into five precise ones — and it lets the user express BP-19's
intent explicitly instead of relying on the ranker to guess.

**Benefits.** Precision without a second input. Discoverability via the empty state, which QAYD's
palette spec already designs as "never a dead end" `[DOCS]`.
**Tradeoffs.** Prefixes are learned, not discovered; the idle state must teach them.
**Risks.** Prefix collision with Arabic input or with account codes that begin with a symbol. Test with
Arabic IME before fixing the set.
**Scalability / performance.** Better — a scoped query is cheaper. **Maintainability.** Better with
BP-10. **Complexity.** Low. **Effort: 3.**
**Business impact.** Moderate-high; this is the surface power users live in.
**Confidence: Medium-High** — the pattern is proven; the specific prefix set is a guess until tested.

---

## BP-21 — Rank entities by recency and affinity, not by text similarity

**Practice.** Beyond the short-circuit, rank accounts, counterparties and entries by this user's recent
and repeated interaction with them, not by string similarity.

**Why.** Slack's finding is that relevance in a workspace is mostly a **social-graph** problem, not a
text problem — its ranker's strongest features are searcher-to-author affinity, channel priority and
click propensity, with only shallow content features `[DOCS]`. The accounting analogue is direct: the
accounts a bookkeeper used yesterday are overwhelmingly the accounts they want today.

**Benefits.** Makes the palette feel telepathic at very low cost. Requires no ML — a decayed interaction
count is most of the value.
**Tradeoffs.** Personalised ordering makes support harder ("it's third for me, first for you").
**Risks.** Ranking creeping onto identifiers — see BP-19 and `ANTI_PATTERNS.md` **AP-12**. Also
cold-start: a new user gets no benefit and must not get a *worse* result than alphabetical.
**Scalability.** Fine — per-user counters. **Performance.** Neutral. **Maintainability.** Moderate.
**Complexity.** Low if it stays a decayed counter, high the moment it becomes a model.
**Effort: 5.**
**Business impact.** Moderate. **Confidence: Medium** — the mechanism transfers; the magnitude does not.

---

# §6 — AI surfaces

## BP-22 — Render a proposal in the surface that validates it

**Practice.** An AI-proposed journal entry renders in the ordinary journal-entry grid, pre-filled,
diff-highlighted, with the same validation running. An AI-proposed coding renders in the coding grid.
Chat is a fallback, never the primary rendering of a proposal.

**Why.** A chat panel that renders a proposed entry as a Markdown table has moved the entry *out* of the
component that knows how to validate it, check the period, price it and show the running difference — so
the user must re-verify everything by eye. The AI changes where the values came from; it must not change
the surface that judges them. QAYD's existing specs already lean this way: the AI group sits last in the
palette "so the palette never nudges toward an AI answer before a deterministic one exists" `[DOCS]`,
and `AiProvenanceDot` is a table-cell atom rather than a separate surface `[DOCS]`.

**Benefits.** Review becomes a diff rather than a re-derivation. The proposal inherits every guard the
manual path has. One less surface to build and maintain.
**Tradeoffs.** The grid gains a proposal mode with diff affordances.
**Risks.** The diff highlights *changes* but not *omissions* — a dropped line is the hard case and must
be rendered explicitly.
**Scalability / performance.** Neutral. **Maintainability.** Better — one surface.
**Complexity.** Moderate in the grid. **Effort: 5.**
**Business impact.** This is the difference between an AI that saves time and one that moves the work.
**Confidence: High.**

---

## BP-23 — Stream the reasoning; commit the number atomically

**Practice.** Streaming is permitted for explanation, status and prose. A field that will be read as a
value is populated in one commit.

**Why.** A half-rendered amount (`1,2`) is a wrong number on screen, and the argument in `OVERVIEW.md`
§5b applies to it exactly as it applies to an optimistic balance — a user can act on it, and the
correction does not reach the world.
**Benefits.** Keeps streaming's perceived-speed benefit without its correctness cost.
**Tradeoffs.** Slightly less "alive". **Risks.** A generic streaming renderer is applied uniformly
because it is convenient. Make the value/prose distinction a property of the field, not of the renderer.
**Scalability / performance / maintainability / complexity.** Neutral, neutral, better, low.
**Effort: 2.**
**Business impact.** Prevents a small, avoidable, embarrassing class of incident.
**Confidence: High.**

---

## BP-24 — Determinate progress for anything over one second

**Practice.** Multi-item work — a statement import, an AI matching pass, a bulk apply — reports what it
is doing and how far through it is: "reading 42 statement lines… matching… 38 of 42 matched."

**Why.** Nielsen's limits put 1 s at the flow-of-thought boundary and 10 s at the attention boundary
`[DOCS]` https://www.nngroup.com/articles/response-times-3-important-limits/. Within that window the
mitigation is **legibility, not speed**: three seconds with determinate progress reads as fast; three
seconds behind an indeterminate spinner reads as broken.

**Benefits.** Buys real time budget for AI and import work. Turns a failure into a *located* failure —
"38 of 42 matched, 4 need attention" is a result, not an error.
**Tradeoffs.** The backend must emit progress, which is a contract, not a UI flourish.
**Risks.** Fake progress. A bar that is not driven by real counts is worse than a spinner because it
lies.
**Scalability / performance.** Neutral. **Maintainability.** Moderate. **Complexity.** Moderate.
**Effort: 3.**
**Business impact.** High for import and reconciliation, which are the first-run experience.
**Confidence: High.**

---

## BP-25 — `reviewed_by` / `reviewed_at` as a first-class, filterable state

**Practice.** Every record an AI can touch carries a reviewed-by-human state — actor and timestamp —
distinct from `created_by_agent` (who authored it) and from an approval (a workflow decision). The
default list view can filter to unreviewed.

**Why.** This is the clearest lesson available in this market and it is an **accountability** finding,
not an accuracy one. On Xero's own product board for its automatic-reconciliation beta, the
second-highest-voted request is "Ability to Mark Auto Reconciliation transactions as reviewed" (38
votes, *Gaining Support*), asking for a control that records the person, date and time, plus a dashboard
widget filtered to unreviewed items — the stated reason being that someone must take responsibility for
what is entered `[COMMUNITY]`
https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta/suggestions/50981083-auto-bank-rec-ability-to-mark-auto-reconciliatio.
Xero's own announcement of the feature is `[DOCS]`
https://blog.xero.com/product-updates/automatic-bank-reconciliation-jax-beta/.

**Benefits.** Makes automation's benefit *honest*: time is saved only if the review queue is shorter,
not invisible. Gives month-end a tractable worklist. Two columns now versus a data migration and a UI
rewrite later.
**Tradeoffs.** Per-record review may be onerous at 200 lines; per-batch attestation is efficient and
weaker (`ARCHITECTURE.md` §14 Q4).
**Risks.** It becomes a rubber stamp — a "mark all reviewed" button reproduces the failure it was built
to prevent. Bulk review must be a deliberate, bounded, audited action, not a convenience.
**Scalability / performance.** Negligible. **Maintainability.** Better. **Complexity.** Low.
**Effort: 3.**
**Business impact.** Directly addresses the loudest complaint against the market leader's AI feature.
**Confidence: High** — the evidence is a real, voted, public request against a shipped product.

---

## BP-26 — Preserve and display the source text the AI coded from

**Practice.** The bank's original description and reference are preserved verbatim through AI coding and
rendered beside the values chosen. No AI path may drop a source field.

**Why.** The third-most-voted request on the same Xero board is "The description and/or reference
doesn't pull through" (27 votes), and the submitter's reason is the one that matters: without the bank's
description, "we can't verify if the items that were auto coded are correct against what the bank
description was" `[COMMUNITY]`
https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta. Together with BP-25 this
is one failure with two faces: **the AI removed the evidence a human needs to check it.** Neither would
be fixed by a better model.

**Benefits.** Makes a proposal checkable in one glance. Is the frontend half of **I-12 Number
Provenance**.
**Tradeoffs.** More columns on a dense row; a display problem, not an architectural one.
**Risks.** The source is stored but not *shown*, which is the same failure with an audit trail.
**Scalability / performance.** Negligible. **Maintainability.** Better. **Complexity.** Low.
**Effort: 2.**
**Business impact.** High and cheap. **Confidence: High.**

---

# §7 — Notification, collaboration, workflow

## BP-27 — Every notification carries a reason and a clearing verb

**Practice.** The notification schema carries an enumerated **reason** (why *this user* received *this*
row) and a declared **triage verb** (what clears it), both filterable, alongside the existing
server-computed category.

**Why.** GitHub's model is built on reasons — `mention`, `subscribed`, `review requested`, filterable as
`reason:review-requested` — and a closed set of triage verbs: Done, Saved, mark read/unread, Unsubscribe
`[DOCS]`
https://docs.github.com/en/account-and-profile/managing-subscriptions-and-notifications-on-github/setting-up-notifications/about-notifications.
Every notification answers *why am I seeing this* and *what clears it*, which is the entire difference
between an inbox and a noise source. QAYD's category taxonomy answers "what is this about"; a reason
answers "why is it mine", and only the second stops people muting.

**Benefits.** Makes per-reason preferences possible, which is the granularity users actually want.
Turns the inbox into a worklist with a defined empty state.
**Tradeoffs.** Two columns and a taxonomy decision.
**Risks.** The reason enum grows unbounded and becomes a second category. Keep it small and about
*relationship*, not about *topic*.
**Scalability / performance.** Negligible. **Maintainability.** Better. **Complexity.** Low.
**Effort: 3** now; a data migration plus a UI rewrite later.
**Business impact.** Notification fatigue is how good products get muted; see **AP-06**.
**Confidence: High.**

---

## BP-28 — Digest by default; realtime by exception

**Practice.** Non-urgent notifications aggregate into a digest at a user-chosen cadence. Realtime
delivery is reserved for the small set that is genuinely time-critical (an approval blocking a payment
run, a fraud hold).

**Why.** GitHub's documented gap is precisely this — notifications arrive individually and in real time
with no native digest `[COMMUNITY]`, which is exactly the volume problem digests exist to solve. QAYD's
version is the close-period storm, where one action generates dozens of notifications for the same
people.
**Benefits.** Volume becomes a user-controlled property. Reduces the pressure to mute a channel
entirely.
**Tradeoffs.** A scheduled aggregation job and a second rendering of every notification type.
**Risks.** Something urgent lands in a digest. The urgency flag must be a property of the *template*,
not a per-send judgement.
**Scalability.** Better — fewer sends. **Performance.** Better. **Maintainability.** Moderate.
**Complexity.** Moderate. **Effort: 5.**
**Business impact.** Moderate now, high at scale.
**Confidence: Medium** — the need is clear; QAYD's real volume is unmeasured.

---

## BP-29 — Presence only as draft-conflict prevention

**Practice.** Show presence exactly where two people editing the same thing would cause a real problem —
"Fatima is editing this draft", "this bank line is staged in someone else's match tray" — and nowhere
else. No avatars on lists, no cursors, no live-typing indicators.

**Why.** Presence has value as a *conflict-prevention* signal and is otherwise decoration
(`OVERVIEW.md` §11). QAYD has no concurrently free-text-edited document, so multiplayer's core use case
does not exist here. Draft collision does.
**Benefits.** Prevents a genuine class of duplicated work at month-end without building a multiplayer
layer.
**Tradeoffs.** Requires a presence channel, which is real infrastructure for a narrow benefit.
**Risks.** Scope creep into presence-as-feature.
**Scalability.** Presence channels are chatty; scope them to the specific record.
**Performance.** Minor cost. **Maintainability.** Neutral. **Complexity.** Moderate.
**Effort: 3.**
**Business impact.** Low-moderate, real at month-end. **Confidence: Medium.**

---

## BP-30 — Automations are registry actions with a bounded blast radius

**Practice.** An automation may only invoke a declared action whose `automatable` flag is true, executes
under a real permission, leaves a record on the affected object, and is scoped to a company and a stated
set of targets.

**Why.** ERP workflow engines are strictly *more* expressive than Linear's automations and are worse in
practice, for three specific reasons (`OVERVIEW.md` §7): their triggers fire on "any field change on any
object" so no automation is reviewable; their actions write fields directly, bypassing the permission and
validation paths — which is how "the workflow did it" becomes an unanswerable audit finding; and their
blast radius is global and invisible.
**Benefits.** An automation's effect is expressible as "the system did what a permitted user could have
done", so the audit trail, permission model and reversal path already exist. "What runs when I post
this?" becomes answerable by reading a list.
**Tradeoffs.** Less expressive than a general rule engine. That is the point.
**Risks.** Pressure to add a general "set any field" action. Refuse it; it is the ERP failure in one
step.
**Scalability / performance.** Neutral. **Maintainability.** Substantially better.
**Complexity.** Much lower than a rule engine. **Effort: 5**, and only after BP-10.
**Business impact.** Protects auditability, which is the product's core claim.
**Confidence: High.**

---

# §8 — Bilingual, numerals, direction

## BP-31 — Every numeric surface goes through the one primitive

**Practice.** Chart axes, sparklines, palette result amounts, diff highlights, CSV/PDF export and
tooltips all render amounts through the same primitive as the table (BP-04).

**Why.** RTL correctness is not a stylesheet property; it is a per-context property that has to be
re-applied every time a new numeric context appears. The existing `AmountCell` spec already carries
`dir="ltr"`, `numberingSystem: 'latn'` and three-decimal handling `[DOCS]`; the risk is entirely in the
*next* context, and there will be many.
**Benefits.** Correctness by construction in the surfaces most likely to be built quickly and reviewed
lightly.
**Tradeoffs.** Charting libraries resist custom formatters; some plumbing.
**Risks.** An export path formats server-side with different rules than the screen. Share the formatter
across the boundary or accept that the CSV and the screen will disagree.
**Scalability / performance.** Neutral. **Maintainability.** Substantially better. **Complexity.** Low.
**Effort: 2.**
**Business impact.** Moderate; formatting inconsistency reads as sloppiness in a finance tool.
**Confidence: High.**

---

## BP-32 — Layout mirrors; keyboard semantics do not

**Practice.** In RTL, columns, panes and iconography mirror. Grid arrow keys keep their *visual*
meaning, `Ctrl+Home` still goes to the grid's first cell in reading order, and shortcut chords are
unchanged. This is pinned in the grid contract (BP-11) and tested in RTL.

**Why.** This is the one place where the usual "just use logical properties" advice does not settle the
question, because arrow keys are about *visual* movement and logical properties are about *layout*. It
is a bug only Arabic-first users hit and that no LTR test catches — which is the worst combination in a
GCC-first product.
**Benefits.** Arabic-first power users get the same throughput as English-first ones, which is the whole
premise of an Arabic-first accounting product.
**Tradeoffs.** RTL keyboard tests are a second test matrix for the grid.
**Risks.** A third-party grid or picker makes the decision for you, wrongly, and inconsistently with
your own.
**Scalability / performance.** n/a. **Maintainability.** Better once pinned. **Complexity.** Low.
**Effort: 2.**
**Business impact.** Moderate, and disproportionately visible to the target market.
**Confidence: Medium-High** — the rule is right; the exact expectations of Arabic-first spreadsheet
users deserve one round of user testing `[UNKNOWN]`.

---

## How these interlock

Four of them are load-bearing for the rest:

- **BP-01** (authority class) is what makes BP-02, BP-03, BP-05 and the realtime rule mechanical rather
  than cultural.
- **BP-06** (vocabulary store) is the prerequisite for BP-12, and therefore for BP-11 being fast rather
  than merely correct.
- **BP-10** (action registry) is the prerequisite for BP-13, BP-20, BP-27's target action, BP-30, and the
  AI's palette surface. It is also what supplies BP-07 with interaction names.
- **BP-04** (money primitive) is the prerequisite for BP-31 and for BP-03's rendering to be consistent.

Everything else is independently valuable and can be sequenced freely.
`IMPLEMENTATION_RECOMMENDATIONS.md` turns this into an order.

# End of Document
