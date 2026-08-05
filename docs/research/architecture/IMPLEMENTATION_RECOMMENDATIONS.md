# 07 — Implementation recommendations

**Sequenced against the real sprint plan · `docs/research/architecture/`**

Version 1.0 · 2026-07-28

Every recommendation is bound to a story that already exists in `docs/execution/SPRINT_02.md`,
`SPRINT_03.md` or `SPRINT_04.md`, or is named as a new item for triage into `08_MASTER_BACKLOG.md`.
Nothing here is a parallel roadmap — the sprints are planned and the plans are good. Most of this is
**acceptance criteria and design constraints on stories that already exist**, which is the same posture
the master backlog takes.

**Effort** is Fibonacci. **Confidence** is High / Medium / Low with a reason. **Value** uses the master
backlog's scale (Critical / High / Medium / Low).

**The timing argument in one sentence:** Sprint 02 builds the first three real product screens (S2-10
chart of accounts, S2-11 journal editor, S2-12 trial balance) and every convention those three establish
is inherited by the twenty screens after them — so the cheap window for anything cross-cutting is now,
and it closes when S2-11 merges.

---

## The one-page answer

If only six things from this research happen, these six:

| # | Item | Effort | Why it cannot wait |
|---|---|---|---|
| 1 | **UXR-06** — latency instrumentation (~80 lines) | **2** | Without it, every later performance claim is an opinion, and S2-11 is the baseline worth having |
| 2 | **UXR-02** — authority class on every response type | **3** | Retrofit means auditing every component to find which ones optimistically render a server value |
| 3 | **UXR-03** — the `<Amount>` primitive over the existing formatter | **3** | The formatter and the token spec both exist; S2-12 is the first screen full of money |
| 4 | **UXR-01** — the action registry | **8** | Retrofit means rewriting every button, shortcut, permission check and the AI tool layer |
| 5 | **UXR-07** — decide the client data layer (**ADR**) | **0 to decide** | S2-10/S2-11 set the shape of every screen after them |
| 6 | **UXR-04** — one grid contract, specified then built | **8** | Keyboard semantics are muscle memory; they cannot be revised after launch |

**24 points of build, plus one decision.** Everything else in this document can slip a sprint. These
cannot, because each either establishes a convention that twenty screens will copy, or destroys a
baseline by waiting.

---

## Index

| ID | Recommendation | Story | Effort | Value | Pri |
|---|---|---|---|---|---|
| **UXR-01** | The action registry, declared before the first button | S2-10 / S2-11 | 8 | Critical | P0 |
| **UXR-02** | Authority class (A–E) on every response type | S2-02 types | 3 | Critical | P0 |
| **UXR-03** | `<Amount>` primitive over `formatMoney()` | S2-12 | 3 | High | P0 |
| **UXR-04** | One grid contract: spec, then component | S2-11 | 8 | Critical | P0 |
| **UXR-05** | Class-D vocabulary store | S2-11 | 8 | High | P1 |
| **UXR-06** | Latency instrumentation + first budgets | S2-11 | 2 | High | P0 |
| **UXR-07** | Decide the client data layer (**ADR**) | S2-10 | 0 / 5 | Critical | P0 |
| **UXR-08** | Reverb consumption rule: invalidate, never apply values | S2-13 | 2 | High | P0 |
| **UXR-09** | Approval/post interactions render *submitting*, not the outcome | S2-11, S4-04 | 2 | High | P0 |
| **UXR-10** | Drop virtualisation from the COA; keep the threshold rule | S2-10 | 1 | Medium | P1 |
| **UXR-11** | As-of + filter on every derived figure | S2-12 | 3 | High | P1 |
| **UXR-12** | Command palette actions become a registry projection | S2-10+ | 3 | High | P1 |
| **UXR-13** | Server-side totals contract for every dense list | S2-12, S3-11 | 3 | High | P1 |
| **UXR-14** | Identifier short-circuit before any ranking | S3-11 / search | 2 | High | P1 |
| **UXR-15** | Determinate, per-item import progress | S3-10 | 3 | Medium | P1 |
| **UXR-16** | Bulk field editing + batch endpoint + per-row outcomes | S3-12 | 8 | High | P1 |
| **UXR-17** | Hold-and-announce for live updates in dense grids | S3-12 | 2 | Medium | P2 |
| **UXR-18** | Virtualisation escape hatches ship with the virtualiser | S3-12 | 5 | High | P1 |
| **UXR-19** | `reviewed_by` / `reviewed_at` + unreviewed filter | S4-02 / S4-04 | 3 | High | P0 |
| **UXR-20** | Preserve and render AI source fields | S4-02 / S4-03 | 2 | High | P0 |
| **UXR-21** | Notification schema: reason + clearing verb | S4-09 | 3 | High | P1 |
| **UXR-22** | Command Center as a queue with triage verbs | S4-09 | 5 | Medium | P2 |
| **UXR-23** | Keyboard-only completion in the E2E suite | S4-12 | 3 | Medium | P2 |

---

# TIER A — Sprint 02, or the window closes

These land in stories being written now. Deferring them means rewriting rather than adding.

## UXR-01 — The action registry, declared before the first button

**Stories: S2-10, S2-11** (and every screen after).
**What.** One declaration per operation — id, `{en, ar}` label, permission key, target, arity
(`single`/`bulk`/`none`), `optimistic` (derived from the target's authority class), `confirm`,
`shortcut`, palette scope + keywords, `automatable`, `ai_invocable`, `idempotent` — generated into both
TypeScript and PHP from a single source. Design in `ARCHITECTURE.md` §4.

**Why now.** QAYD already specifies its operations six times, in six good documents that do not
reference each other: the shortcut table in `ACCESSIBILITY.md`, the palette's "static action list" in
`COMMAND_PALETTE.md`, the Bulk Action Bar in `JOURNAL_ENTRIES.md`, `AUTOMATION_CENTER.md`,
`AUDIT_LOG.md`, and the ai/ folder's capability enum `[DOCS]`. S2-10 and S2-11 are where the first real
operations get wired to real buttons; every one added after that is another entry in six lists.

**Acceptance criteria to add to S2-10.**
- Action ids and permission keys are generated from one source consumed by both PHP and TypeScript;
  adding an action on one side without the other fails the build.
- The palette's Actions group and the global shortcut map are both built by *filtering the registry*,
  not by reading a literal list.
- The audit log's human-readable label for an operation comes from the registry.
- `optimistic` cannot be hand-set: it is derived from the target's authority class (UXR-02).
- An arch test asserts that no component binds a keyboard handler to an operation absent from the
  registry.

**Tradeoffs.** A codegen step and a small ceremony per new operation. QAYD already runs codegen for
types (Principle 7), so the machinery exists.
**Risk.** It grows into a client-side command bus duplicating the Laravel Actions. Mitigate by stating in
the ADR that it is a **catalogue** — metadata only, no execution — and by asserting the registry module
imports nothing from the API client.
**Effort: 8 · Value: Critical · Priority: P0 · Confidence: High** on the value, **Medium** on the exact
field set, which will change on contact.
→ `BEST_PRACTICES.md` **BP-10**, `LESSONS_FOR_QAYD.md` **L-03**, `ARCHITECTURE.md` §4.

---

## UXR-02 — Authority class (A–E) on every response type

**Story: S2-02** (COA API + types) **and every types PR after it.**
**What.** Each exported schema in `packages/types/` carries a declared authority class; the data layer
refuses to construct an optimistic mutation against a schema that is not class A.

**Why now.** `packages/types/src/accounting.ts` already exists and already mirrors the Laravel
FormRequests exactly `[CODE]`. Adding a class constant to a schema that is being written this sprint is
free. Retrofitting means auditing every component to find which ones optimistically render a
server-authored value — and the failure is invisible in review, because a component that flips
`status: "approved"` looks exactly like one that flips `dismissed: true`.

**Acceptance criteria.**
- Every exported response schema declares one of `A | B | C | D | E`.
- A lint rule fails a mutation configured with an optimistic handler whose target schema is not class A.
- The classes are documented once, with the two rendering laws (`ARCHITECTURE.md` §2.3), and referenced
  rather than re-explained per screen.

**Tradeoffs.** A handful of genuinely ambiguous cases have to be argued once (a notification badge count
is the known one).
**Risk.** Mechanical application without thought. Require the class in the same PR as the schema and
review it as a decision, not as boilerplate.
**Effort: 3 · Value: Critical · Priority: P0 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-01**, `LESSONS_FOR_QAYD.md` **L-04**.

---

## UXR-03 — `<Amount>` primitive over the existing formatter

**Story: S2-12** (trial balance — the first screen that is mostly money).
**What.** Add one React primitive to `packages/ui/` layered over `formatMoney()`, implementing the
already-specified `AmountCell` token treatment: `font-mono tabular-nums`, `dir="ltr"`, `text-end`,
`emphasis` variants, `numberingSystem: 'latn'`, per-currency minor units, and `negativeStyle`
(`minus` for editable data, `parentheses` for statements).

**Why now.** Both halves already exist and are not joined. The numeric core is built and good `[CODE]`
(`packages/shared/src/currency.ts`); the token treatment is fully specified `[DOCS]`
(`docs/design-system/components/TABLE.md`); the component is absent from `packages/ui/src/components/`,
which today holds button, card, input, label, select and icons `[CODE]`. The `currency.ts` header even
names the intended shape. This is assembly.

**Acceptance criteria.**
- One primitive; a lint rule forbids `Intl.NumberFormat` / `toLocaleString` on money outside it.
- Renders correctly in LTR and RTL, light and dark, at all three densities.
- KWD/BHD/OMR render three decimals; the ISO code is shown, never a symbol.
- Zero and negative presentations are covered by a snapshot test in both `negativeStyle` modes.

**Tradeoffs.** Chart libraries and export paths resist a custom formatter; plumbing required (UXR — see
**BP-31**).
**Risk.** A server-side export formats with different rules than the screen. Share the rules or accept
divergence knowingly.
**Effort: 3 · Value: High · Priority: P0 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-04**, `LESSONS_FOR_QAYD.md` **L-18**.

---

## UXR-04 — One grid contract: specify it, then build it

**Story: S2-11** (journal-entry editor).
**What.** Write the grid interaction contract down as a short spec, then build **one** component against
it, used by the journal line editor now and by the reconciliation workbench in S3-12.

The contract (`ARCHITECTURE.md` §5.2): roving `tabindex`; arrows move; `Home`/`End` in-row;
`Ctrl+Home`/`Ctrl+End` to grid bounds; `Enter`/`F2` enters edit; **`Escape` restores navigation without
committing**; `Enter` commits and moves down; `Tab` commits and moves right; committing on the last row
appends; no commit may round-trip; the loading boundary of a virtualised grid must be keyboard-crossable;
arrow *semantics* do not mirror in RTL, layout does.

**Why now.** `JOURNAL_ENTRIES.md` already names `JournalLineGrid` as a screen-specific component
implementing the ARIA `grid` pattern, and `BANK_RECONCILIATION.md` describes a second grid `[DOCS]`.
Building the second one against the first one's implicit behaviour is how two grids come to disagree —
and keyboard semantics are muscle memory that cannot be revised after launch.

**Acceptance criteria to add to S2-11.**
- The contract exists as a document before the component merges.
- A keyboard-only test enters a twelve-line balanced entry with **zero fetches** during entry (this is
  the assertion that makes UXR-05 load-bearing rather than optional).
- `Escape` from edit mode restores navigation and does not commit — asserted.
- RTL: arrow keys keep their visual meaning — asserted.
- The component is exported for reuse; S3-12 consumes it rather than forking it.

**Tradeoffs.** A shared grid is heavier than two bespoke ones and worth it at the second consumer.
**Risk.** S3-12 forks it under schedule pressure. Make reuse an explicit S3-12 acceptance criterion.
**Effort: 8 · Value: Critical · Priority: P0 · Confidence: High** — the ARIA standard is unambiguous and
QAYD has already chosen it.
→ `BEST_PRACTICES.md` **BP-11**, `LESSONS_FOR_QAYD.md` **L-08**.

---

## UXR-05 — Class-D vocabulary store

**Story: S2-11** (it is what makes the grid fast) **, extended in S3-11.**
**What.** A client store hydrated once per company session holding accounts, account types, tax codes,
currencies, fiscal periods, cost centres and (subject to size) counterparties; push-invalidated; read
synchronously by every lookup. Each resource declares a load strategy — `replicated` or `queried` — and
the replicated list is short and reviewed as a whole.

**Why now.** It is the prerequisite for UXR-04's zero-fetch assertion. Without it, the account picker
fetches on open and the grid cannot be fast at any level of component craft.

**Acceptance criteria.**
- The replicated resource list is declared in one file and reviewed as a unit.
- Account resolution from a code or a bilingual name prefix is synchronous, measured under 50 ms at the
  95th percentile (UXR-06 supplies the measurement).
- A declared ceiling per resource, with an automatic fallback to `queried` when exceeded, and a log line
  when the fallback triggers.
- On Reverb reconnect, class D is re-hydrated alongside the existing invalidation sweep.
- Nothing in class B or C is present in the store — asserted by the load-strategy declaration, not by
  review.

**Tradeoffs.** A session-start hydration cost and a cache-coherence problem.
**Risk.** Scope creep into class B ("and the last 50 entries"). The declared list is the guard.
Second risk: the size estimate is `[INFERENCE]`; measure against a real customer's COA and counterparty
list before fixing the ceiling (`ARCHITECTURE.md` §14 Q1).
**Effort: 8 · Value: High · Priority: P1 · Confidence: Medium-High** — mechanism sound, size unmeasured.
→ `BEST_PRACTICES.md` **BP-06**, **BP-12**, `LESSONS_FOR_QAYD.md` **L-01**.

---

## UXR-06 — Latency instrumentation and the first budgets

**Story: S2-11.**
**What.** ~80 lines: start at `event.timeStamp`, end at `performance.now()` inside
`requestAnimationFrame`, bucket as % under 50 / 100 / 1000 ms plus a "terrible" bucket, discard samples
where `document.hidden` or that began before the last `visibilitychange`. Tag each sample with the
registry action id (UXR-01) so buckets are attributable.

**Why now.** This is the highest value-to-cost item in the folder and it is the one whose *signal* is
destroyed by waiting: S2-11 is the first high-volume interaction surface, and a baseline captured then
is what makes every later regression detectable. It complements rather than replaces the existing
`useReportWebVitals` pipeline, which is route- and page-load-shaped and will not see an interaction-class
regression.

**Acceptance criteria.**
- The module ships with S2-11 and reports to the same observability pipeline as Web Vitals.
- The initial budgets in `ARCHITECTURE.md` §12 are recorded, marked `[INFERENCE]`, and replaced with
  measured values within one sprint of real usage.
- A named owner for the budget, reviewed like the existing bundle budgets.

**Tradeoffs.** Another telemetry stream to route and retain.
**Risk.** Built and never looked at. The budget with an owner is the mitigation.
**Effort: 2 · Value: High · Priority: P0 · Confidence: High** — the method is published in full.
→ `BEST_PRACTICES.md` **BP-07**, **BP-08**, `LESSONS_FOR_QAYD.md` **L-02**.

---

## UXR-07 — Decide the client data layer, and write the ADR

**Story: S2-10.** ⚠️ **This is a decision, not code — and it is the highest-value item here relative to
its cost.**

**The situation.** `apps/web/package.json` declares no client data-fetching library at all; reads flow
through Server Components and a server-side BFF `[CODE]`. `FRONTEND_ARCHITECTURE.md` specifies TanStack
Query, Zustand and Echo in detail — query-key factories, per-class cache tuning, infinite queries feeding
a virtualiser `[DOCS]`. Both are coherent; only one can be the shape of S2-10 onward.

**Recommendation: adopt the specified client layer**, on the strength of three concrete needs rather
than on the strength of the spec — the vocabulary store (UXR-05), optimistic class-A drafts, and
realtime invalidation (UXR-08). None of the three is served by RSC alone.

**But write the ADR**, because the alternative is genuinely viable: RSC plus server actions plus targeted
revalidation, with a small purpose-built vocabulary store and no general cache. That alternative ships
less JavaScript and has fewer ways to be wrong, and the reason to reject it should be recorded rather
than assumed.

**Acceptance criteria.**
- ADR merged before S2-10's data access is written.
- The ADR records: the decision, the three needs it serves, the rejected alternative, and the
  cache-tuning table re-keyed on **authority class** rather than volatility (UXR-02).
**Effort: 0 to decide, ~5 to implement · Value: Critical · Priority: P0 · Confidence: High** on the
analysis; **the decision is the Architecture Owner's.**
→ `LESSONS_FOR_QAYD.md` **L-16**, `ARCHITECTURE.md` §3, §14 Q2.

---

## UXR-08 — Reverb consumption rule: invalidate, never apply values

**Story: S2-13** (posted-event broadcast).
**What.** State the rule in terms of authority class rather than risk: **a realtime message may carry a
value only for class D; class B and class C carry facts about change and the client's only response is
to invalidate and refetch.**

**Why now.** S2-13 is where the first broadcast consumer is written, and it is already right — the story
specifies "a compact `journal.posted` projection an open ledger screen consumes to **re-fetch**"
`[CODE]`. This recommendation is about the *rule*, so the next tile does not re-argue the carve-out. The
existing frontend spec's "patch only for high-frequency low-risk ticks" permits patching a dashboard
tile's live count `[DOCS]`; a dashboard financial tile is class C, may never reach a terminal state, and
a drifted patch has no scheduled correction (`ANTI_PATTERNS.md` **AP-04**).

**Acceptance criteria to add to S2-13.**
- The Echo event handler for `journal.posted` invalidates; it does not `setQueryData` from the payload.
- A test asserts that no class-B or class-C query key is ever written from an event payload.
- The reconnect sweep re-hydrates class D as well as invalidating class B/C.
- The rule is written down once, referenced from the realtime docs rather than restated.

**Tradeoffs.** One extra round trip per event, on a path that is not latency-critical.
**Risk.** A future high-frequency figure makes patching tempting again. The class rule is the answer.
**Effort: 2 · Value: High · Priority: P0 · Confidence: High.**
→ `ARCHITECTURE.md` §6, `LESSONS_FOR_QAYD.md` **L-06**.

---

## UXR-09 — Approval and post interactions render *submitting*, not the outcome

**Stories: S2-11** (post) **and S4-04** (decision review).
**What.** A money-moving or decision-bearing mutation moves its target to an explicit **submitting**
state — the previous value still visible, an unambiguous in-flight affordance — never to the predicted
outcome. Rejection returns the object to a visible in-place error state with the reason and a retry, and
it persists until dismissed; a toast may accompany it but may not be it.

**Why now.** `FRONTEND_ARCHITECTURE.md`'s Principle 10 is right and its worked example optimistically
flips an Approval Center card to `approved` `[DOCS]`. An approval is a server decision subject to
permission, step ordering, a version check, another approver having acted first, and segregation of
duties — S2-06 explicitly requires that the entry creator is not its sole approver `[CODE]`. The client
cannot know it will succeed, and an approval is socially durable in a way a dismissal is not.

**Acceptance criteria.**
- No mutation on a class-B target configures an optimistic handler (enforced by UXR-02's lint rule).
- The submitting state is a design-system state, specified once, used by both S2-11's Post and S4-04's
  Approve.
- A rejected mutation leaves a persistent, in-place error naming the reason; asserted in a test that
  simulates a `409` and a `422`.
**Tradeoffs.** Two visual states rather than one. The interaction still feels instant.
**Risk.** "Submitting" becomes permanent when a request is lost. Every submitting state needs a timeout
resolving to an explicit failure, never to silence.
**Effort: 2 · Value: High · Priority: P0 · Confidence: High.**
→ `ANTI_PATTERNS.md` **AP-01**, **AP-05**, `LESSONS_FOR_QAYD.md` **L-05**.

---

## UXR-10 — Drop virtualisation from the chart of accounts; keep the threshold rule

**Story: S2-10.**
**What.** Amend S2-10's acceptance criteria: render the account tree plainly. Adopt the threshold rule
platform-wide — virtualise above a measured row count, not by default.

**Why now.** S2-10 currently specifies "a **virtualized** expandable tree/flat table over accounts"
`[CODE]`. A GCC SME chart of accounts is roughly 150–400 accounts `[INFERENCE]`; virtualising it buys
nothing measurable and costs browser find-in-page, naive print/PDF, select-all-copy, and — per the ARIA
APG — a keyboard trap at the dynamic-loading boundary. For an accountant `Ctrl+F` and copy-to-Excel are
core workflow.

Note that `BANK_RECONCILIATION.md` already reasons correctly about the *positive* case: ~200 rows, with
the stated reason that a first-ever import can land thousands of lines `[DOCS]`. The COA is where the
same reflex was applied without the same analysis.

**Acceptance criteria.**
- S2-10 renders the tree without a virtualiser; `Ctrl+F` finds an account not currently scrolled into
  view; select-all-copy yields every account.
- The threshold rule is documented once and referenced by S2-12, S3-11 and S3-12.
**Tradeoffs.** A pathological tenant with thousands of accounts scrolls less smoothly. Acceptable, and
detectable by UXR-06.
**Risk.** None material.
**Effort: 1 (a removal) · Value: Medium · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-15**, `LESSONS_FOR_QAYD.md` **L-14**.

---

## UXR-11 — As-of moment and filter on every derived figure

**Story: S2-12** (trial balance), then every report and tile.
**What.** Every class-C figure renders with its server-supplied as-of moment and the filter that
produced it. "Trial balance · as of 14:32 · periods 2026-01…07."

**Why now.** S2-12 is the first derived-figure screen and it sets the convention. `staleTime: 0` is a
refetch policy, not a rendering rule — refetching makes a number fresher without making the screen honest
about the moment it describes. And a dated figure is one step from a dereferenceable one, which is the
rendering half of **I-12 Number Provenance**.

**Acceptance criteria.**
- The as-of value comes from the response payload, never from the client clock — asserted.
- The filter echo names the periods/dimensions actually applied server-side, not the UI's filter state.
- Designed once in the design system so twelve dashboard tiles do not become twelve timestamps of noise.
**Tradeoffs.** More chrome per figure; a real design problem on dense dashboards.
**Risk.** It gets applied to the trial balance and forgotten on the dashboard. Make it a property of the
figure component, not of the screen.
**Effort: 3 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-03**, `ARCHITECTURE.md` §2.3.

---

## UXR-12 — The palette's Actions group becomes a registry projection

**Story: S2-10 onward** (the palette is a shell singleton already specified).
**What.** Replace the palette's "static action list" with a filter over the registry, and add scope
prefixes: `>` action, `#` entry/document number, `@` counterparty, `/` account, `:` period, with `Tab`
narrowing and `Backspace` widening.

**Why now.** The palette spec is otherwise complete and good — grouped Navigate → Records → AI &
Actions, synchronous permission-filtered Navigate, AI last and never first, never a dead end `[DOCS]`.
The static list is the seventh maintenance point UXR-01 exists to remove. GitHub's prefix model is the
reference `[DOCS]`, and it maps unusually well because accountants already think in namespaces.

**Acceptance criteria.**
- The Actions group is derived from the registry, filtered by `palette !== null` and by permission.
- Prefixes are tested with an Arabic IME before the set is fixed.
- The idle state teaches the prefixes (they are learned, not discovered).
**Tradeoffs.** Prefix collision risk with account codes beginning in punctuation.
**Risk.** Low. **Effort: 3 · Value: High · Priority: P1 · Confidence: Medium-High** — the pattern is
proven; the specific prefix set is a guess until tested.
→ `BEST_PRACTICES.md` **BP-20**.

---

# TIER B — Sprint 03: import, lists, workbench

## UXR-13 — Server-side totals contract for every dense list

**Stories: S2-12, S3-11, S3-12.**
**What.** Every paginated or virtualised list endpoint returns its aggregate (total, subtotal, count,
difference) computed server-side over the **full filtered set**, and the client renders it beside the
filter that produced it. No client-side summation of rendered rows, ever.

**Why.** With virtualisation "the rows on screen" is a meaningless population; with pagination it is a
plausible-but-wrong one, which is worse. A wrong total on a reconciliation screen is the worst single
bug this product could ship.

**Acceptance criteria.**
- The list envelope carries aggregates; the grid component has no summation path.
- A test asserts the footer total is unchanged when the viewport is resized or a page is turned.
- The filter echo matches the server's applied filter, not the client's pending filter state.
**Tradeoffs.** A real query cost — a `SUM()` over a filtered, RLS-scoped set — correctly placed. The
analytics/ folder's index work is where that cost is absorbed.
**Risk.** A "quick client sum" added later for responsiveness. Forbid it in the component.
**Effort: 3 per list contract · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-16**, `ANTI_PATTERNS.md` **AP-08**.

---

## UXR-14 — Identifier short-circuit before any ranking

**Story: S3-11** (accounts & transactions lists) **and the aggregate search endpoint.**
**What.** A query matching an identifier pattern — entry number, invoice/document reference, account
code, a well-formed amount, a date — returns that record by exact lookup **before** any scorer runs.

**Why.** Accounting queries are dominated by identifiers, dates and amounts. "Ranked first" and
"short-circuited" are not the same guarantee: a score can be silently changed by a later ranking
improvement, and a search that *usually* finds `INV-4471` first is a search nobody trusts.

**Acceptance criteria.**
- Identifier patterns are declared per entity in one place, extended when a document type is added.
- A test asserts an exact identifier match returns first, and that the assertion is independent of any
  ranking configuration.
- Ambiguity (two entities sharing a shape) presents both deterministically rather than picking one;
  UXR-12's prefixes let the user disambiguate.
**Tradeoffs.** Pattern maintenance per entity.
**Risk.** Low. **Effort: 2 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-19**, `ANTI_PATTERNS.md` **AP-12**.

---

## UXR-15 — Determinate, per-item import progress

**Story: S3-10** (statement import UI).
**What.** The import reports what it is doing and how far through: "reading 412 lines… parsed 412…
matched 380… 32 need attention." Never an indeterminate spinner for a multi-item operation.

**Why.** Between Nielsen's 1 s and 10 s boundaries, tolerability is determined by legibility rather than
duration. And import is the first-run experience: the impression it leaves is the product's.
`BANK_RECONCILIATION.md` already anticipates this — "the parsed-line count ticking up if the import
endpoint streams progress" `[DOCS]`. The recommendation is to make the endpoint stream it rather than
leave it conditional.

**Acceptance criteria.**
- Progress counts are real, emitted by the backend; no bar is driven by an estimate.
- Terminal state is a *located* result — "380 matched, 32 need attention" is a result, not an error.
- The existing behaviour of not blanking the panes during import is retained.
**Tradeoffs.** The backend must emit progress: a contract, not a UI flourish.
**Risk.** A fake progress bar, which is worse than a spinner.
**Effort: 3 · Value: Medium · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-24**, `ANTI_PATTERNS.md` **AP-07**.

---

## UXR-16 — Bulk field editing, a batch endpoint, and per-row outcomes

**Story: S3-12** (reconciliation workbench shell) — likely a new backlog item beside it.
**What.** A selection mode over the workbench grid that sets a field across N rows (account, tax code,
counterparty), backed by a server-side batch endpoint applying the same Action per row inside one
request and returning a per-row result, rendered per row.

**Why.** Xero's Cash Coding is the category's existing answer: a spreadsheet-grid bulk-coding view over
statement lines, **200 lines at a time**, sortable by date, payee, reference or description `[DOCS]`
https://central.xero.com/0/article/Reconcile-using-cash-coding-US, permission-gated rather than
role-hardcoded `[DOCS]` https://xero.my.site.com/s/article/Standard-user-role-US. QAYD's Bulk Action Bar
is the right mechanism scoped to whole-record verbs; this extends it to field editing. The workbench's
success metric is **lines cleared per minute by a human**, not match acceptance rate.

**Acceptance criteria.**
- Registry actions carry `arity: bulk`; bulk-ness is declared, not special-cased per screen.
- The batch endpoint calls the same Action per row — no shortcut path that skips a validation.
- Partial success is the normal case: the response is per-row and the UI renders per-row outcomes.
  "197 succeeded" without naming the three failures is a defect, not a summary.
- The grid is the UXR-04 component, not a fork.
- Permission-gated as its own registry permission, grantable independently of role.
**Tradeoffs.** A new API shape with partial-failure semantics.
**Risk.** Bulk apply used to bypass a per-row rule. The same-Action criterion is the guard.
**Effort: 8 · Value: High · Priority: P1 · Confidence: Medium-High** — proven in market; QAYD's exact
scope is a design-partner question (`ARCHITECTURE.md` §14 Q3).
→ `BEST_PRACTICES.md` **BP-13**, `LESSONS_FOR_QAYD.md` **L-09**, **L-17**.

---

## UXR-17 — Hold-and-announce for live updates in dense grids

**Story: S3-12**, generalising an existing pattern.
**What.** Dense grids never splice, re-sort or re-flow under a reading user. Incoming changes are held
and announced — "3 new lines · Refresh" — applied on command.

**Why.** `NOTIFICATIONS.md` already specifies exactly this for the notification feed `[DOCS]`; this
generalises it to the workbench and the ledger, where the consequence of a row moving under a click is
worse. It also sidesteps the open problem Figma names for LiveGraph — pagination "remains a challenging
problem in the face of real-time update" `[DOCS]`.

**Acceptance criteria.** The affordance is non-modal, pinned, and pairs with UXR-11's as-of stamp so
staleness is visible in two places.
**Tradeoffs.** The grid is knowingly stale between refreshes, which UXR-11 states on screen.
**Risk.** The affordance is missed for an hour; the as-of stamp is the second signal.
**Effort: 2 · Value: Medium · Priority: P2 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-18**.

---

## UXR-18 — Virtualisation escape hatches ship with the virtualiser

**Story: S3-12** (and the general ledger / audit log views).
**What.** Wherever virtualisation is used, three affordances ship in the same change: an in-grid find
that scrolls to and focuses the match; "export this view" that goes to the server over the full filtered
set; and a copy that serialises the filtered set rather than the mounted rows. Plus a keyboard-crossable
loading boundary.

**Why.** Virtualisation breaks browser find-in-page, naive print/PDF and select-all-copy `[DOCS]`
https://tanstack.com/virtual/latest/docs/introduction, and the ARIA APG names the fourth cost: at a
dynamic-loading boundary "keyboard users are effectively trapped in the list" `[DOCS]`. For an
accountant, two of those three are the job.

**Acceptance criteria.**
- In-grid find, server-side export and full-set copy are S3-12 acceptance criteria, not follow-ups.
- A keyboard test crosses the loading boundary in both directions.
**Tradeoffs.** Real work, in the same sprint as the screen.
**Risk.** Deferred and never delivered, by which point users have learned the screen is hostile.
**Effort: 5 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-15**, `ANTI_PATTERNS.md` **AP-09**.

---

# TIER C — Sprint 04: the AI surfaces

## UXR-19 — `reviewed_by` / `reviewed_at`, and an unreviewed filter

**Stories: S4-02** (extract pipeline) **and S4-04** (decision review UI).
**What.** Every record an AI can touch carries a reviewed-by-human state — actor and timestamp —
distinct from `created_by_agent` (already on `journal_entries` per S2-03 `[CODE]`) and distinct from an
approval. "Unreviewed" is a first-class filter and the default view of the review surface.

**Why now.** This is the clearest evidence available in this market and it is an accountability finding,
not an accuracy one. On Xero's own product board for its automatic-reconciliation beta, the
second-highest-voted request is "Ability to Mark Auto Reconciliation transactions as reviewed" — 38
votes, *Gaining Support* — asking for a control recording the person, date and time plus a widget
filtered to unreviewed items, on the grounds that someone must take responsibility for what is entered
`[COMMUNITY]`
https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta/suggestions/50981083-auto-bank-rec-ability-to-mark-auto-reconciliatio.
Two columns before the tables exist; a data migration and a UI rewrite after.

**Acceptance criteria.**
- `reviewed_by` / `reviewed_at` on AI-touchable records; "unreviewed" filterable and the review
  surface's default.
- A "mark all reviewed" affordance, if it exists at all, is a bounded, audited, deliberately-friction'd
  action — not a convenience button, which reproduces the failure it exists to prevent.
- The reviewed state is populated by a registry action, so it lands in the audit trail like anything
  else.
**Tradeoffs.** Per-record review may be onerous at 200 lines; per-batch attestation is efficient and
weaker (`ARCHITECTURE.md` §14 Q4). The Xero board asks for per-record.
**Risk.** It becomes a rubber stamp.
**Effort: 3 · Value: High · Priority: P0 · Confidence: High** — the evidence is a real, voted, public
request against a shipped competitor feature.
→ `BEST_PRACTICES.md` **BP-25**, `ANTI_PATTERNS.md` **AP-14**, `LESSONS_FOR_QAYD.md` **L-10**.

---

## UXR-20 — Preserve and render the source fields the AI coded from

**Stories: S4-02, S4-03** (ai_draft intake).
**What.** The bank's original description and reference survive AI coding verbatim and render beside the
values chosen. No AI path may drop a source field.

**Why now.** The third-most-voted request on the same Xero board is "The description and/or reference
doesn't pull through" — 27 votes — and the submitter's stated reason is the mechanism: without the bank's
description "we can't verify if the items that were auto coded are correct against what the bank
description was" `[COMMUNITY]`
https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta. Together with UXR-19
this is one failure with two faces — the automation removed the evidence a human needs to check it.

**Acceptance criteria.**
- Source fields are carried through the proposal DTO and persisted on the resulting draft.
- The review surface renders source beside coded value, in one glance, without an expand.
- A test asserts a round trip through the extract pipeline preserves description and reference byte-for-byte.
**Tradeoffs.** More columns on a dense row — a display problem, not an architectural one.
**Risk.** Stored but not shown, which is the same failure with an audit trail.
**Effort: 2 · Value: High · Priority: P0 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-26**.

---

## UXR-21 — Notification schema: reason and clearing verb

**Story: S4-09** (Command Center) — schema work that should precede it.
**What.** Add an enumerated **reason** (why this user received this row) and a declared **clearing verb**
to the notification schema, both filterable, alongside the existing server-computed category. Add a
digest cadence to the preferences matrix.

**Why now.** Two columns before the table carries production rows; a data migration plus a UI rewrite
after. A category answers "what is this about"; a reason answers "why is it mine", and only the second
lets a user tune instead of muting. GitHub's model is the reference, and its documented gap — no native
digest `[COMMUNITY]` — is exactly QAYD's close-period storm.

**Acceptance criteria.**
- `reason` is a small enum about *relationship*, not about topic; it does not duplicate `category`.
- Every template declares what clears it.
- The preferences matrix, already built as a five-channel grid, gains a cadence axis (immediate /
  digest), with urgency a property of the template rather than a per-send judgement.
**Tradeoffs.** A taxonomy decision now.
**Risk.** The reason enum grows into a second category. Keep it small.
**Effort: 3 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-27**, **BP-28**, `ANTI_PATTERNS.md` **AP-06**.

---

## UXR-22 — Command Center as a queue with triage verbs, not a dashboard

**Story: S4-09.**
**What.** The AI Command Center's primary surface is a **worklist with an empty state** — items, each
with a reason, a provenance, and a triage verb that clears it — rather than a set of panels that are
never done.

**Why.** A dashboard has no completion state, so it is skimmed; a queue has one, so it is worked. This
is GitHub's inbox insight applied to the AI surface, and it composes with UXR-19: "unreviewed" is
precisely the queue's default filter, which makes the Command Center the place where automation's benefit
is *demonstrated* rather than asserted.
**Acceptance criteria.** Every item declares its reason and its clearing verb (UXR-21); the empty state
is a designed, reachable state; the queue's default filter is unreviewed.
**Tradeoffs.** Less impressive as a demo surface than a panel of insights.
**Risk.** The queue and the panels both ship and compete.
**Effort: 5 · Value: Medium · Priority: P2 · Confidence: Medium** — this is partly a product judgement.
→ `ARCHITECTURE.md` §8, §10.

---

## UXR-23 — Keyboard-only completion in the E2E suite

**Story: S4-12** (MVP end-to-end).
**What.** Three E2E journeys completed without the pointer: create and post a twelve-line journal entry;
code and clear twenty statement lines; review and accept an AI proposal. Written as *tasks*, not as key
assertions.

**Why.** Keyboard-first is the product's differentiator and it decays silently — one dialog whose confirm
is unreachable, one picker that traps focus. `BEST_PRACTICES.md` **BP-14** makes this a definition-of-done
item; S4-12 is where it becomes a gate.

**Acceptance criteria.** The three journeys pass with pointer events disabled; a failure names the step
that required the mouse.
**Tradeoffs.** Keyboard E2E is more brittle than click-based E2E.
**Risk.** It becomes a checkbox. Write it as a task traversal.
**Effort: 3 · Value: Medium · Priority: P2 · Confidence: High.**
→ `BEST_PRACTICES.md` **BP-14**, `ANTI_PATTERNS.md` **AP-18**.

---

# Sequencing

```
S2 week 1   UXR-07 (decide) ─┬─► UXR-02 ──► UXR-09
                             ├─► UXR-01 ──┬─► UXR-12
                             │            └─► (UXR-16, UXR-19 later depend on it)
                             └─► UXR-06

S2 week 2   UXR-03 ──► UXR-11        UXR-10 (a removal)
            UXR-05 ──► UXR-04 ──► (S3-12 reuses the grid)
            UXR-08

S3          UXR-13 · UXR-14 · UXR-15 · UXR-18 · UXR-16 · UXR-17

S4          UXR-19 · UXR-20 · UXR-21 · UXR-22 · UXR-23
```

**Points added:** Tier A 38 · Tier B 21 · Tier C 16 — **75 points**, of which about half are
acceptance criteria on stories that already exist rather than net-new work. The two genuinely new
builds are UXR-01 (registry, 8) and UXR-16 (bulk coding, 8); UXR-04 and UXR-05 are re-shapings of work
S2-11 already contains.

**What to cut if the sprint will not hold it.** UXR-05 and UXR-04 can be sequenced across S2 and S3 —
the grid can ship with a fetching picker and gain the vocabulary store in S3, provided the contract
(UXR-04's spec) is written first so the keyboard model does not change under users. UXR-16 can slip to
S4. **UXR-01, UXR-02, UXR-06 and UXR-07 cannot slip**, because each sets a convention that twenty later
screens inherit or destroys a baseline by waiting.

---

# Intake

This document is **research, not specification**. An item becomes specification only when it is promoted
into `08_MASTER_BACKLOG.md` with a tier, a value, a dependency list and a sprint — the intake rule,
unchanged.

Three items additionally require a decision record rather than a backlog entry, because they refine or
contradict something already written:

| Item | Governance |
|---|---|
| **UXR-07** — client data layer | ADR; it settles a gap between `FRONTEND_ARCHITECTURE.md` and the code |
| **UXR-09** — no optimistic approve | Amends a worked example in `FRONTEND_ARCHITECTURE.md` Principle 10; the principle itself is unchanged |
| **UXR-10** — COA virtualisation | Amends an S2-10 acceptance criterion |

# End of Document
