# 05 — The proposed QAYD application architecture (frontend & collaboration layer)

**How the pieces fit, and why · `docs/research/architecture/`**

Version 1.0 · 2026-07-28 · Status: **research, not binding**

`OVERVIEW.md` establishes the landscape and derives the constraint. This document draws the mechanisms
that follow from it. It is deliberately an *engineering* document: it does not specify screens, layouts,
copy, tokens or components — `docs/frontend/**` (96 documents) and `docs/design-system/**` (72
documents) already own all of that, and where they have decided something this document cites them and
moves on.

**What this document adds that those do not contain:** a declared authority class on every datum; a
single action registry that the keyboard, palette, bulk bar, automations, audit log and AI tool list all
project from; a client vocabulary store; an invalidation-only realtime contract; a latency measurement
regime; and the rules that keep a data-dense financial grid honest.

---

## Contents

| § | Topic |
|---|---|
| 1 | The design problem, and the answer in one paragraph |
| 2 | The authority classification, made operational |
| 3 | The layered view — what runs where |
| 4 | **The action registry** — the folder's headline |
| 5 | The keyboard and command layer |
| 6 | Realtime as an invalidation bus |
| 7 | Data-dense rendering: the reconciliation workbench as the worked example |
| 8 | The AI surfaces |
| 9 | Search |
| 10 | Notifications |
| 11 | Bilingual / RTL as an architectural constraint |
| 12 | Latency instrumentation and budgets |
| 13 | What is deliberately not built |
| 14 | Open questions this architecture does not settle |

---

## 1 · The design problem, and the answer in one paragraph

The problem: QAYD's users are SME accountants and bookkeepers doing repetitive, high-volume, precise
work, and every product they have used is either slow (ERP) or mouse-driven (SME SaaS). The product
must feel like a tool a professional operates rather than a form a layperson fills in — while remaining
a system in which the server is the sole authority on every number, because it is a ledger.

The answer, in one paragraph:

> **Replicate the vocabulary, declare the actions, classify the authority, invalidate rather than
> predict, and measure the interaction.** The client holds a small bounded replica of the *reference*
> data (accounts, tax codes, periods, counterparties) so that the ninety per cent of interactions that
> are lookups never touch the network. Every operation the product can perform is declared once in a
> registry that the keyboard, the palette, the bulk bar, automations, the audit log and the AI tool
> surface all read from. Every datum carries an authority class that decides whether the UI may render
> it optimistically. Realtime carries facts about change, never derived amounts. And interaction
> latency is a measured number with an owner, not an aspiration.

None of the five requires a sync engine. Four of the five are cheaper to do now, before the frontend
exists, than at any later point.

---

## 2 · The authority classification, made operational

`OVERVIEW.md` §5c derives five classes. This section turns them into something a compiler and a code
review can enforce.

### 2.1 The classes, restated as a contract

| Class | Authored by | Optimistic render | Cache | Realtime treatment |
|---|---|---|---|---|
| **A** — client draft | this user, this session | **Yes, fully** | local, unshared | none |
| **B** — server fact | the server, at a chokepoint | **Never** — render *pending* | short | invalidate |
| **C** — server-derived aggregate | derived from B | **Never** — render *stale-and-dated* | short, always revalidate | invalidate |
| **D** — shared reference | any user, rarely | replicate | long, push-invalidated | **may push values** |
| **E** — local preference | this user | n/a | local, persisted | none |

### 2.2 Where the annotation lives

The natural home is `packages/types/`, which already holds Zod schemas mirroring the Laravel
FormRequests exactly `[CODE]` (`packages/types/src/accounting.ts` — the file's own header states
"Field names mirror App\\Http\\Controllers\\Accounting\\AccountController … exactly"). An authority
class is a property of the *resource type*, not of the component that renders it, and putting it
anywhere else guarantees two components will disagree.

Concretely: each exported response schema is accompanied by a class constant, and the data-access layer
refuses to build an optimistic mutation against a schema whose class is not `A`. This is a lint rule
plus a type-level tag; it is not a runtime framework.

**Why this is worth a type rather than a convention.** The failure it prevents is invisible in review.
A component that optimistically flips `status: "approved"` looks exactly like a component that
optimistically flips `dismissed: true`. The difference is not in the code — it is in who is allowed to
author the field. Only the type knows that.

### 2.3 The two rendering laws that follow

**Law 1 — a class-B or class-C value is never displayed in a state the server has not confirmed.**
The permitted intermediate states are *pending* (the write is in flight; the old value is still shown,
labelled) and *unknown* (we have no value; show nothing, not a zero). A predicted value is forbidden.
This is Notion's refusal generalised: rather than show a page that might be missing data, show that the
page is not available `[DOCS]` https://www.notion.com/blog/how-we-made-notion-available-offline.

**Law 2 — a class-C value is always rendered with its as-of moment and its filter.**
"Trial balance · as of 14:32 · periods 2026-01…2026-07" is a true statement. "Trial balance" over the
same numbers is a claim about *now* that the client cannot support. This is cheap at render time and
impossible to retrofit, because retrofitting means finding every place a figure is drawn.

Law 2 also has a product consequence worth naming: it is the rendering-layer counterpart of **I-12
Number Provenance** (`07_QAYD_INNOVATION.md`). A figure that carries an as-of moment is one step from a
figure that carries a link to the rows behind it, and the same component should carry both.

---

## 3 · The layered view — what runs where

QAYD's frontend today is Next.js 15 App Router with React Server Components and a server-side BFF; the
web app declares **no client data-fetching library at all** `[CODE]` (`apps/web/package.json` lists
`next`, `react`, `react-dom` and the four workspace packages, and nothing else). `docs/frontend/FRONTEND_ARCHITECTURE.md`
specifies TanStack Query, Zustand and Laravel Echo as the intended layers. The gap between the
specification and the code is the moment to settle the layering, and it will not recur.

```
┌──────────────────────────────────────────────────────────────────────────┐
│  L5  SURFACES        screens, grids, palette, review queues              │
│                      KNOWS: layout, interaction.  KNOWS NO AUTHORITY.   │
├──────────────────────────────────────────────────────────────────────────┤
│  L4  ACTION REGISTRY every operation, declared once (§4)                 │
│                      the single source for keyboard · palette · bulk ·  │
│                      automation · audit label · AI tool list            │
├──────────────────────────────────────────────────────────────────────────┤
│  L3  STORES          class A  draft store      (optimistic, unshared)   │
│                      class D  vocabulary store (replicated, pushed)     │
│                      class E  preference store (local, persisted)       │
│                      class B/C  query cache    (never optimistic)       │
├──────────────────────────────────────────────────────────────────────────┤
│  L2  TRANSPORT       typed SDK over the envelope · idempotency keys ·   │
│                      Reverb subscription → INVALIDATION only (§6)       │
├──────────────────────────────────────────────────────────────────────────┤
│  L1  SERVER          Laravel Actions · PostgreSQL · RLS                 │
│                      the only authority on every class-B and class-C    │
│                      value in the product                               │
└──────────────────────────────────────────────────────────────────────────┘
```

Each layer may call downward only — the same discipline `09_ENGINEERING_PLAYBOOK.md` §4.1 imposes on
the API, applied to the client for the same reason.

### 3.1 The vocabulary store (class D) — the one piece of Linear worth taking

**What it is.** A client-side store, hydrated once per company session, holding the small bounded
reference set: accounts, account types, tax codes, currencies, fiscal periods, cost centres,
counterparties, users and roles, saved views.

**Why it earns its keep.** During journal entry the client reads class D on *every keystroke* — to
resolve `4110` or `رواتب` to an account, to validate a tax code, to offer a counterparty. Every one of
those reads that becomes a network round-trip is 100–400 ms of dead time multiplied by the number of
lines. A GCC SME chart of accounts is roughly 150–400 accounts `[INFERENCE]`; the whole class-D working
set is plausibly a few hundred kilobytes — smaller than the JavaScript that renders it.

**The mechanism, and the part of Linear worth copying.** Linear's transferable idea is not the sync
engine; it is the **declared per-model load strategy** — `instant`, `lazy`, `partial`,
`explicitlyRequested`, `local` `[COMMUNITY]` https://github.com/wzhudev/reverse-linear-sync-engine.
Declaring the strategy per resource is what makes partial replication safe, because it makes "what is
on the client" a stated property rather than an emergent one. QAYD needs exactly two values —
`replicated` and `queried` — and the entire benefit comes from the fact that the list of `replicated`
resources is short, visible, and reviewable.

**What it must never contain.** Anything in class B or C. The replica is the *vocabulary*, never the
ledger. A counterparty's name and code are vocabulary; that counterparty's balance is class C and is
fetched. This line is the whole safety property and it should be enforced by the load-strategy
declaration rather than by discipline.

**Invalidation.** Class D is the one class where a realtime channel may carry values (§6), because the
value *is* the datum — there is no derivation to diverge from. A rename of account 4110 can be pushed
as `{id, code, name_en, name_ar}` without creating a second computation path for anything.

**Bounds and the failure mode.** The store must have a declared ceiling and a declared behaviour when
the ceiling is exceeded — a company with 8,000 counterparties should fall back to `queried` for that
resource, not silently ship 8 MB. The ceiling is a measurement, not a guess, and §14 Q1 records that it
is unmeasured today.

### 3.2 The draft store (class A) — where optimism is not merely safe but correct

Class A is the unposted journal draft and its lines, an in-progress import mapping, proposed
reconciliation matches, filters, selections, and wizard state. The user is the only author, so there is
no other party to contradict the optimistic render, and a rejected write costs a flicker.

`docs/frontend/FRONTEND_ARCHITECTURE.md` already places this in Zustand, explicitly not persisted, reset
on route leave, with the sharp observation that "a half-entered, unbalanced journal entry has no
business surviving a tab close." That is right and this document does not revisit it. The architectural
addition is only that the store's contents are *typed as class A*, so that the boundary between "the
draft" and "the entry" is a type boundary rather than a naming convention — because the instant the
draft is submitted, every value in it becomes class B and the optimism must stop.

### 3.3 The query cache (class B/C) — never optimistic, always dated

The existing specification's cache-tuning table is already close to the right shape, keyed on
volatility. The architectural refinement is to key it on **authority** instead, because volatility and
authority happen to correlate but only authority is load-bearing:

| Class | `staleTime` | On realtime event | Render rule |
|---|---|---|---|
| D | long (minutes) | patch with the pushed value | ordinary |
| B | short | invalidate | pending state permitted, never predicted |
| C | 0 | invalidate | **always with as-of + filter** |

The difference is not academic. Volatility says "a dashboard tile changes often, so refetch often."
Authority says "a dashboard tile is a derived financial figure, so it may never be patched from an
event payload and must always carry its as-of moment." The second rule is the one that prevents an
incident.

### 3.4 The preference store (class E)

Column order, width and visibility; density; sort; last-used account; recent items. Local, persisted,
never round-tripped, never containing a figure or a permission snapshot. This is settled in the existing
spec and needs nothing from this research except the observation in §7 that **column and density state
is a throughput feature, not a cosmetic one** — an accountant who re-arranges a reconciliation grid once
and finds it re-arranged tomorrow has been given back a minute a day.

---

## 4 · The action registry

**This is the folder's headline recommendation, and the one whose retrofit cost is highest.**

### 4.1 The finding

QAYD already specifies its operations six times, in six good documents, with no shared declaration
between them:

| Consumer | Where it is specified today | Form |
|---|---|---|
| Global keyboard shortcuts | `docs/frontend/ACCESSIBILITY.md` §Global keyboard shortcuts | a markdown table of ~15 bindings `[DOCS]` |
| Command palette actions | `docs/design-system/components/COMMAND_PALETTE.md` §Variants | "Static action list + Ask AI", permission-checked "by their key" `[DOCS]` |
| Bulk operations | `docs/frontend/JOURNAL_ENTRIES.md` §Bulk Action Bar | "Approve · Export · Clear", each re-checking its own permission `[DOCS]` |
| Automations | `docs/frontend/AUTOMATION_CENTER.md` | its own surface |
| Audit log labels | `docs/frontend/AUDIT_LOG.md` | its own vocabulary |
| AI tool surface | `docs/research/ai/` — the closed capability enum (**AIR-01**) | a discriminated union |
| Server-side truth | `apps/api/app/Actions/{Domain}/` `[CODE]` — 14 `VerbNounAction` classes today | PHP classes |
| Permission keys | `apps/api/database/seeders` `[CODE]` — `accounting.coa.manage`, `accounting.journal.read` | seeded strings |

Every one of these lists is well-designed. None of them references the others. Adding a seventh
operation today means editing six documents and hoping.

### 4.2 What an action declaration contains

A single declaration, generated into both TypeScript and PHP from one source, exactly as the ai/
folder's capability enum is specified to be (`AIR-01`):

```
id                  accounting.journal.post
label               { en, ar }              — the audit log, palette and shortcut help all read this
permission          accounting.journal.post — one key, checked server-side, mirrored client-side
target              journal_entry           — what it operates on
arity               single | bulk | none    — may it apply to a selection?
optimistic          false                   — derived from the target's authority class
confirm             required                — a money-moving action per Principle 10
shortcut            null | "P"              — page- or card-scoped binding, or none
palette             { scope: "actions", keywords: [...] }
automatable         true | false            — may an automation invoke it?
ai_invocable        false                   — may the AI *propose* it? (never execute)
idempotent          true                    — does it require an Idempotency-Key?
```

Two fields deserve comment. `optimistic` is not free-form: it is *derived* from the authority class of
the target (§2), so an engineer cannot hand-set it to `true` on a posted entry. And `ai_invocable`
being a field on the same record as `permission` is what makes the AI's tool surface a *subset* of the
human's action surface by construction, which is the property the ai/ folder's architecture rests on and
currently obtains by parallel maintenance.

### 4.3 The six consumers, as projections

```
                        ┌───────────────────────┐
                        │   ACTION REGISTRY     │
                        │  one declaration per  │
                        │      operation        │
                        └───────────┬───────────┘
        ┌───────────┬───────────┬───┴───┬───────────┬────────────┐
        ▼           ▼           ▼       ▼           ▼            ▼
   keyboard     command      bulk    automation   audit       AI tool
   bindings      palette      bar     triggers    labels       surface
   (filter        (filter     (filter  (filter     (label     (filter
    shortcut≠     palette≠     arity=   automat-    lookup)     ai_invocable
    null)         null)        bulk)    able)                   = true)
```

Each consumer is a *filter and a rendering* of the same list. Adding an operation is one declaration;
it appears in the palette, gets an audit label, and is available to bulk selection and automation if —
and only if — its declaration says so.

### 4.4 What it forbids, which is the point

- **An action that exists in the UI but not in the audit vocabulary.** Impossible: the label comes from
  the registry.
- **A shortcut bound to something the palette cannot run.** Impossible: same record.
- **An automation that writes a field directly.** The registry's actions are the same actions a human
  can take, so an automation's effect is expressible as "the system did what a permitted user could have
  done" — which is precisely the property `OVERVIEW.md` §7 identifies as the one ERP workflow engines
  lack, and the reason "the workflow did it" becomes an unanswerable audit finding in those systems.
- **An AI tool that is not a human action.** The AI's surface is a filter over the same list, so there
  is no tool the product does not otherwise expose, and no permission path the AI holds alone.

### 4.5 What it is not

It is **not** a client-side command bus, an event-sourcing layer, or a re-implementation of the Laravel
Actions. The business logic stays exactly where `09_ENGINEERING_PLAYBOOK.md` §3.6 puts it. The registry
is a **catalogue**: metadata about operations, generated from one source, consumed by six surfaces. Its
runtime cost is a constant object; its build cost is a codegen step QAYD already runs for types
(Principle 7 generates TypeScript from the OpenAPI contract).

### 4.6 Where it lives

`packages/shared/` or a new `packages/actions/`, generated from the same source that produces the
permission seed on the PHP side. The generation direction matters: **permissions and action ids should
come from one file that both languages read**, not from PHP with a TypeScript mirror, because a mirror
drifts and a generated artefact cannot.

---

## 5 · The keyboard and command layer

### 5.1 The distinction that organises this section

QAYD's existing documents specify **keyboard operability** thoroughly — `docs/frontend/ACCESSIBILITY.md`
runs 1,495 lines, Principle 11 makes "full keyboard operability" a requirement of done, and the global
shortcut table already includes Linear-style `G`-chords, `Cmd/Ctrl+K`, and grid arrow-key navigation
`[DOCS]`.

**Keyboard operability and keyboard-first throughput are different properties**, and the second is not
implied by the first:

| | Keyboard operable (a11y) | Keyboard-first (throughput) |
|---|---|---|
| Question it answers | *Can* the task be done without a mouse? | Is the keyboard the *fastest* way to do it? |
| Success criterion | every control is reachable and actuable | the median line of data entry costs a bounded number of keystrokes and **zero network waits** |
| Failure looks like | a control that can only be clicked | a flow that is reachable by Tab, but takes 40 Tabs |
| Owned by | WCAG | the data model and the vocabulary store |

The second property is won in §3.1 (the vocabulary store) and §4 (the registry), not in the shortcut
table. A `Tab` order that walks 400 grid cells is fully "operable" and unusable.

### 5.2 The grid interaction contract

The ARIA APG `grid` pattern specifies exactly the model a data-entry grid needs, and QAYD's existing
docs already name it for the journal line editor and the reconciliation matching grid `[DOCS]`
https://www.w3.org/WAI/ARIA/apg/patterns/grid/. The architectural point is that **there must be one
implementation**, because the contract is muscle memory and two grids that disagree are worse than one
grid that is wrong.

The contract, stated once:

| Concern | Rule |
|---|---|
| Focus containment | roving `tabindex` — exactly one cell in the page tab sequence; `Tab` leaves the grid, it does not walk it |
| Navigation mode | arrows move cell-to-cell; `Home`/`End` within row; `Ctrl+Home`/`Ctrl+End` to grid start/end |
| Edit mode | `Enter` or `F2` enters edit; `Escape` **restores grid navigation** without committing |
| Commit | `Enter` commits and moves down; `Tab` commits and moves right — and neither may round-trip |
| New row | committing on the last row appends, so a twelve-line entry is one uninterrupted typing run |
| Virtualisation | the APG names the failure explicitly: when a list "dynamically loads more" content, "keyboard users are effectively trapped" — the loading boundary must be keyboard-crossable |
| Direction | arrow *semantics* do not mirror in RTL; the *layout* does (§11) |

The zero-round-trip requirement in "Commit" is the one that is architectural rather than
component-level: it is satisfiable only because account resolution reads the class-D vocabulary store.
A grid whose account cell fetches on open cannot honour it at any level of component craft.

### 5.3 The command palette as a projection

The palette is already specified in detail — `cmdk`/Radix, three stable groups (Navigate → Records → AI
& Actions), a synchronous permission-filtered Navigate source, AI last and never first `[DOCS]`
(`docs/design-system/components/COMMAND_PALETTE.md`). The architecture adds two things:

1. **The Actions group is a registry projection, not a static list.** The existing spec's own words are
   "Static action list" — that is the seventh maintenance point §4.1 identifies.
2. **Scoping by prefix, GitHub-style.** GitHub's palette is one input serving several search spaces via
   prefixes — `>` commands, `#` issues, `@` users, `/` files, `!` projects, with `Tab` narrowing and
   `Backspace` widening `[DOCS]` https://docs.github.com/en/get-started/accessibility/github-command-palette.
   Accountants already think in namespaces and already know their codes, so the mapping is direct:
   `>` action · `#` entry or document number · `@` counterparty · `/` account code or name · `:` period.
   A prefix is a *scope declaration*, which is what turns one fuzzy input into five precise ones — and
   it is also what makes §9's identifier short-circuit expressible by the user rather than only guessed
   by the ranker.

### 5.4 Bulk operations: the throughput surface the market already has

Xero ships **Cash Coding**: a spreadsheet-style bulk-coding view over bank statement lines, up to **200
lines at once**, sortable by date, payee, reference or description `[DOCS]`
https://central.xero.com/0/article/Reconcile-using-cash-coding-US. It is permission-gated rather than
role-hardcoded — the cash-coding permission can be granted to a standard user `[DOCS]`
https://xero.my.site.com/s/article/Standard-user-role-US.

This is the category's existing answer to axis-2 throughput and it is worth being precise about what it
demonstrates: the fastest way to code two hundred transactions is **not** two hundred forms, and not an
AI that codes them invisibly — it is one grid where a human applies judgement in bulk and can see every
line they touched.

QAYD's existing bulk model is a **Bulk Action Bar** over a selection, with each action re-validated
per-row `[DOCS]` (`docs/frontend/JOURNAL_ENTRIES.md`). That is the right *mechanism* and it is currently
scoped to Approve/Export/Clear — verbs that act on whole records. The gap is bulk **field editing**:
select N statement lines, set the account, set the tax code, set the counterparty, apply. Architecturally
this needs three things that are cheaper to design in than to add:

- an action `arity` of `bulk` in the registry (§4.2), so bulk-ness is declared rather than special-cased;
- a **server-side batch endpoint** that applies the same Action per row inside one request, returning a
  per-row result — because N individual POSTs is correct but is also N round-trips, and at 200 rows the
  round-trips are the feature's cost;
- a **per-row outcome surface**: partial success is the normal case, and a bulk operation that reports
  only "12 succeeded" without saying which three failed converts a throughput feature into an
  investigation.

Where QAYD can go further than the category: the same grid, the same keyboard model, and the same
registry actions serve *both* bank coding and journal entry, because both are "apply a coding decision
to N rows." Xero has two separate surfaces because it grew them separately.

---

## 6 · Realtime as an invalidation bus

QAYD's stack includes Reverb, and S2-13 already specifies the correct shape: posting broadcasts "a
compact `journal.posted` projection an open ledger screen consumes to **re-fetch**" `[CODE]`
(`docs/execution/SPRINT_02.md`). The existing frontend spec generalises it as "invalidate by default,
patch only for high-frequency low-risk ticks."

The architecture makes the carve-out precise, because "low-risk" is the word doing the work and it is
not self-defining:

> **A realtime message may carry a *value* if and only if the value is class D. For class B and class C
> it carries a *fact about change* — what happened, to which company, in which period, touching which
> accounts — and the client's only permitted response is to invalidate and refetch.**

The reason is not latency, it is arithmetic provenance. If a channel carries the *value* of a
trial-balance row or a dashboard cash figure, then two code paths compute that number: the reporting
query, and whatever assembled the broadcast payload. Second computation paths diverge — silently, and
usually only for the tenant with the unusual data. Figma can accept this for a comment count; a ledger
cannot.

**The specific carve-out to re-examine.** The existing spec permits patching "a dashboard tile's live
count" and "an AI job's progress percentage" `[DOCS]`. The second is safe: job progress is not a
financial figure, and the spec's own safety net — an ordinary invalidation once the job reaches a
terminal state — closes it. The first is the one to reconsider: a dashboard *figure* (cash position, an
unreconciled total) is class C, it may never reach a terminal state, and a patched value that drifts
has no scheduled correction. A notification **badge count** is a different case again and is arguably
fine — it is a count of rows in an inbox, not a figure derived from the ledger — but the rule should be
stated in terms of the class, not in terms of "risk", so that the next tile added does not have to
re-litigate it.

**Reconnect.** The existing spec already gets the hard part right: a WebSocket has no replay, so events
during a disconnect are permanently missed and every realtime-fed key is invalidated once on reconnect.
This document only adds that class D must be re-hydrated on the same trigger, since a pushed rename
missed during a disconnect would otherwise persist in the replica indefinitely — a stale *vocabulary*
is a subtler bug than a stale figure, because nothing about the screen looks wrong.

---

## 7 · Data-dense rendering: the reconciliation workbench as the worked example

The reconciliation workbench (S3-12) and the trial balance (S2-12) are where this craft is not optional.
Four architectural rules, three of which the existing specs already reach independently.

**7.1 Virtualise on a measured threshold, not by default.** `docs/frontend/BANK_RECONCILIATION.md`
already sets the workbench's threshold at roughly 200 rows with the active density's row height as the
size estimate `[DOCS]`. That is right. The corresponding *negative* case matters just as much: a trial
balance is 60–400 rows and a GCC SME chart of accounts is 150–400 — **neither needs virtualisation**,
and virtualising them buys nothing while costing find-in-page, print and copy-to-clipboard. S2-10's
acceptance criteria currently specify "a virtualized expandable tree/flat table over accounts"
`[CODE]`; that is a threshold worth revisiting rather than inheriting (`LESSONS_FOR_QAYD.md` L-14).

**7.2 Virtualisation's three side effects are core workflow in accounting, not annoyances.** Rendering
only visible rows `[DOCS]` https://tanstack.com/virtual/latest/docs/introduction breaks browser
find-in-page, breaks naive print/PDF, and breaks select-all-then-copy. For a task manager these are
rough edges. For an accountant, `Ctrl+F` and copy-to-Excel are the job. The escape hatches — an
in-grid find that scrolls to and focuses the match, an "export this view" that goes to the server over
the full filtered set, and a copy that serialises the filtered set rather than the mounted rows — ship
*with* virtualisation, never after.

**7.3 Totals never come from the rendered rows.** With virtualisation, "the rows on screen" is a
meaningless population; with pagination it is a plausible but wrong one, which is worse. The footer
total comes from the server over the full filtered set, and the filter that produced it is echoed next
to it. This is Law 2 (§2.3) applied to the one place where a wrong total looks most authoritative.

**7.4 A dense grid does not move under the reader's hands.** Rows re-sorting while an accountant reads
a column is worse than stale data. `docs/frontend/NOTIFICATIONS.md` already specifies the correct
pattern — a small "1 new — Refresh" banner rather than a splice `[DOCS]`. Generalise it: **every dense
grid holds incoming changes behind an explicit affordance.** This also sidesteps the open problem Figma
names for LiveGraph, that pagination "remains a challenging problem in the face of real-time update"
`[DOCS]` https://www.figma.com/blog/livegraph-real-time-data-fetching-at-figma/.

---

## 8 · The AI surfaces

The ai/ research folder concluded that QAYD should build a **deterministic proposal pipeline, not an
agent** — code chooses the control flow, the model returns a typed proposal, a human confirms. That
conclusion determines the UX more than any AI-UX literature does, because it means **the AI's output is
always a proposal about a specific record**, never a conversation about the books.

### 8.1 The four surfaces and their allocation

| Surface | Fit | QAYD use |
|---|---|---|
| **Inline** — a suggested value in the field you are in | Primary | coding an imported bank line; suggesting an account; matching a counterparty |
| **Palette** — natural language resolved to a registry action | Primary | "post the rent for July"; "show 4110 last quarter" |
| **Review queue** — many proposals, each with provenance and accept/reject | Primary | S4-04 decision review; reconciliation residual |
| **Chat** | **Secondary/fallback** | open-ended questions, multi-turn refinement |

The palette row is only implementable because of §4: natural language resolves to a *declared action
with a permission and an executor*, so "what can I ask it to do" has an enumerable answer and the AI
cannot invoke something the user could not. Without the registry, a natural-language command surface is
a text-to-arbitrary-effect mapping, which is the shape the ai/ folder refuses.

### 8.2 The rendering law for proposals

> **An AI-proposed entry is rendered in the ordinary journal-entry grid, pre-filled, diff-highlighted,
> with the same validation running.**

A chat panel that renders a proposed entry as a Markdown table has moved the entry *out* of the
component that knows how to validate it, check the period, price it and show the running difference —
so the user must re-verify everything by eye. The AI changes where the values came from; it must not
change the surface that judges them. QAYD's existing specs already lean this way — the AI group sits
last in the palette "so the palette never nudges toward an AI answer before a deterministic one exists"
`[DOCS]`, and the `AiProvenanceDot` is a table-cell atom rather than a separate surface `[DOCS]`.

### 8.3 The accountability finding, which is the important one

The instructive failure in this market is not accuracy. Xero shipped AI auto-reconciliation (JAX) and
the loudest requests on its own product board are about **accountability**, not correctness `[DOCS]`
https://blog.xero.com/product-updates/automatic-bank-reconciliation-jax-beta/:

- **"Ability to Mark Auto Reconciliation transactions as reviewed"** — 38 votes, status *Gaining
  Support*. The request asks for a mark-as-reviewed control that records the person, date and time, and
  a dashboard widget filtered to unreviewed items. The stated reason is that data integrity is
  paramount and "someone must take responsibility for what is entered" `[COMMUNITY]`
  https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta/suggestions/50981083-auto-bank-rec-ability-to-mark-auto-reconciliatio
- **"The description and/or reference doesn't pull through"** — 27 votes. The submitter's reason is the
  one that matters: without the bank's own description, "we can't verify if the items that were auto
  coded are correct against what the bank description was" `[COMMUNITY]`
  https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta

Both are the same failure: **the AI removed the evidence a human needs to check it.** One removed the
*reviewed* state; the other removed the *source text*. Neither is an accuracy complaint, and neither
would be fixed by a better model.

Two architectural consequences, both nearly free before the tables exist:

1. **`reviewed_by` / `reviewed_at` are columns, and "unreviewed" is a filterable state**, on every
   record an AI can touch. This is distinct from `created_by_agent` (who authored it) and from an
   approval (a workflow decision) — it is the ordinary "a human has laid eyes on this" state that makes
   a month-end review tractable. It is also the honest version of the thing autonomy claims to give
   back: the time saved is real only if the review queue is *shorter*, not invisible.
2. **Source fields are preserved verbatim and rendered beside the coded values.** An AI-coded line shows
   the bank's original description and reference next to the account it chose. This is what makes the
   proposal checkable in one glance, and it is the frontend half of **I-12 Number Provenance**.

### 8.4 Streaming, latency, and failure

- **Never stream tokens into a field that will be read as a value.** Stream reasoning, status and prose;
  commit numbers atomically. A half-rendered amount (`1,2`) is a wrong number on screen, and
  `OVERVIEW.md` §5b applies to it exactly as it applies to an optimistic balance.
- **Determinate progress beats speed.** "Reading 42 statement lines… matching… 38 of 42 matched" at
  three seconds is experienced as fast; the same three seconds behind an indeterminate spinner is
  experienced as broken. Nielsen's 10-second attention limit is the budget `[DOCS]`
  https://www.nngroup.com/articles/response-times-3-important-limits/ and the mitigation is legibility,
  not latency.
- **Failure is a state of the surface, not a toast.** When the AI path degrades, the ordinary manual
  surface must be fully usable and must say the assistance is unavailable — the review queue with no
  proposals in it is a working screen; a review queue that silently shows nothing is a data-loss
  illusion.

---

## 9 · Search

Slack's search is the best-documented instance of workspace ranking: a learning-to-rank re-ranker over
Lucene using **work-graph** signals — searcher-to-author affinity, channel priority, pins and reactions,
plus shallow content features — trained from clicks with position-bias correction, measured at a 9%
increase in clicked searches and 27% at position 1 `[DOCS]` https://slack.engineering/search-at-slack/.
Notably it contains **no semantic features at all**.

For QAYD the transferable part is recency-plus-affinity ranking of *entities* — the accounts,
counterparties and entries this user actually touches — which is what makes a palette feel telepathic
at near-zero cost.

**And the explicit refusal:** ranking must never apply to identifiers. An accountant searching
`INV-4471` must get `INV-4471` first, always, by **short-circuit** — an exact-match lookup that returns
before any scorer runs — not by scoring it highly. The difference is that a score can be changed by a
later ranking improvement and a short-circuit cannot, and a search that "usually" finds the invoice
number is a search nobody trusts. Accounting queries are dominated by identifiers, dates and amounts;
this is the majority case, not the edge case.

---

## 10 · Notifications

GitHub's model is the reference, and its two load-bearing properties are schema-level:
every notification carries a **reason** (`mention`, `subscribed`, `review requested` — filterable as
`reason:review-requested`) and the inbox offers a small closed set of **triage verbs**: Done, Saved,
mark read/unread, Unsubscribe `[DOCS]`
https://docs.github.com/en/account-and-profile/managing-subscriptions-and-notifications-on-github/setting-up-notifications/about-notifications.
Every notification therefore answers *why am I seeing this* and *what clears it*. Those two questions
are the entire difference between an inbox and a noise source.

QAYD's notification surface is already well specified — a five-category taxonomy computed server-side, a
per-row action set derived from the same `PERMISSION_BY_KIND` map the Approval Center uses, and inline
actions deliberately restricted `[DOCS]` (`docs/frontend/NOTIFICATIONS.md`). The architectural additions
are two columns and one absence:

- **`reason`** — why this user received this row, as an enumerated value, filterable. A *category*
  ("AI Alerts") says what the notification is about; a *reason* ("you are the approver of record") says
  why it is in your inbox. They are different and only the second answers the question that stops
  people muting.
- **`triage_verb` / cleared-by** — what makes it go away, declared rather than implied.
- **A digest.** GitHub's documented gap is that notifications arrive individually and in real time with
  no native digest `[COMMUNITY]`, which is exactly the volume problem digests exist to solve. The
  close-period notification storm is QAYD's version of it, and a digest is much cheaper to design into
  the preferences matrix — which the existing spec already builds as a five-channel grid — than to add
  after users have configured expectations against it.

Both columns cost two migrations' worth of thought now and a data migration plus a UI rewrite later.

---

## 11 · Bilingual / RTL as an architectural constraint

QAYD is further along here than any other axis: full EN/AR with RTL is Sprint 1 delivered (S1-14
`[CODE]`), Principle 6 requires both directions in the same pull request that introduces a component,
and logical properties are mandated over physical ones. The money formatter already forces Latin digits
and comma grouping regardless of UI locale, renders the ISO code rather than a symbol, and knows that
KWD/BHD/OMR carry three decimals `[CODE]` (`packages/shared/src/currency.ts`).

The remaining architectural risks are the three places direction and numerals interact with the
*interaction* model rather than the layout:

1. **Grid keyboard semantics do not mirror.** In an RTL grid the *columns* mirror, but `→` should still
   move in the visual direction the user expects. Getting this wrong is a bug that only Arabic-first
   users hit and that no LTR test can catch. Pin it in the grid contract (§5.2) and test it there.
2. **Numerals inside a mirrored container.** The `AmountCell` already carries `dir="ltr"` and
   `numberingSystem: 'latn'` `[DOCS]`; the risk is every *new* numeric context — chart axes, sparklines,
   diff highlights, CSV export, the palette's inline amounts — where the rule has to be re-applied.
   This is what a single `<Amount>` primitive over `formatMoney()` exists to prevent, and the primitive
   does not exist in `packages/ui/` yet `[CODE]` (`packages/ui/src/components/` holds button, card,
   input, label, select, icons).
3. **Sign and polarity conventions.** `formatMoney` already offers `minus` for editable data and
   `parentheses` for statements. That is a genuine accounting distinction and it belongs in the
   component's props, not in each screen's judgement.

`OVERVIEW.md` §11 lists RTL as the most notorious retrofit in GCC software. QAYD has already paid that
cost; the recommendation here is only to keep it paid by routing every new numeric surface through one
primitive.

---

## 12 · Latency instrumentation and budgets

**Adopt Superhuman's method, not their targets** `[DOCS]`
https://blog.superhuman.com/performance-metrics-for-blazingly-fast-web-apps/. Three properties make it
worth copying wholesale: it is roughly eighty lines, it is framework-agnostic, and "% of interactions
under 100 ms" is a number a non-engineer can be held to, whereas "p95 INP" hides exactly the tail that
makes users angry.

The two details that make it correct rather than merely present:

- **Start at `event.timeStamp`** — the time the system logged the event — not `performance.now()` at
  handler entry. This captures time the event spent queued behind a blocked main thread, which is
  precisely the lag the user feels and precisely what naive instrumentation hides.
- **End inside `requestAnimationFrame`**, after the CPU work and just before the frame is presented.
  Discard samples where `document.hidden` is true or that began before the last `visibilitychange`.

This complements rather than replaces the existing spec's Core Web Vitals reporting, which is
route-level and page-load-shaped; INP will tell you a route is slow, it will not tell you that account
resolution in the journal grid crossed 100 ms for companies with more than 300 accounts.

**Proposed initial budgets, per interaction class** `[INFERENCE]` — these are derived from Nielsen's
limits and should be replaced by measurements as soon as measurements exist:

| Interaction | Budget | Why |
|---|---|---|
| Keystroke → account suggestion rendered | **< 50 ms**, 95% | happens on every keystroke; served from the class-D store, so no network is involved |
| Cell commit → next cell focused | **< 50 ms**, 95% | the inner loop of data entry; a network call here is a design defect, not a slow one |
| Palette open → first results painted | **< 100 ms**, 95% | Navigate is synchronous and permission-filtered; only Records is networked |
| Draft save (class A → server) | **< 1 s**, 95% | Nielsen's flow-of-thought limit |
| Post entry (confirmed, class B) | **< 1 s** to a determinate pending state | the *pending state* is what must be fast; the post itself may be slower |
| Trial balance / workbench first paint | **< 1 s** to structure, **< 3 s** to figures | figures may stream in; structure may not wait for them |
| AI proposal batch | **< 10 s** with determinate progress | Nielsen's attention limit; legibility is the mitigation |

The nonlinearity is the business case, not the milliseconds: above roughly one second the operator's
flow of thought breaks and they re-read the source document, which costs tens of seconds. 200 ms of
avoidable lag per line at 120 lines a day is 24 seconds of waiting — but one broken flow is worth more
than the whole day's 24 seconds.

---

## 13 · What is deliberately not built

| Not built | Why |
|---|---|
| A general sync engine | Solves a problem QAYD does not have, at very high cost (`OVERVIEW.md` §5) |
| A client-side ledger replica | Unbounded, confidential, and defeats the RLS the whole isolation model rests on |
| Offline authorship | Entry numbering and period gates are not client-checkable; the value at a desk is near zero |
| CRDTs anywhere | Convergence is agreement, not legality; nothing in QAYD is concurrently free-text edited |
| Multiplayer cursors / presence theatre | Presence has value only as draft-conflict prevention; the rest is decoration |
| Pushing derived values over Reverb | Creates a second computation path for money (§6) |
| An automation builder | Until the registry exists there is nothing to compose (§4) |
| Semantic / vector search over the ledger | Slack shipped a strong ranker with no semantic features; identifiers and dates dominate accounting queries (§9) |
| A second grid implementation | Keyboard semantics are muscle memory; two contracts is worse than one imperfect contract |

---

## 14 · Open questions this architecture does not settle

**Q1 — What is the real size of the class-D working set for a GCC SME?**
The estimate of a few hundred kilobytes is `[INFERENCE]`. It should be measured against a real
customer's chart of accounts and counterparty list before the vocabulary store's ceiling and fallback
behaviour are fixed. The measurement is cheap and it determines whether counterparties are `replicated`
or `queried`.

**Q2 — Does the client data layer need TanStack Query at all, given RSC + the BFF?**
The existing spec assumes it; the code does not yet contain it. Server Components plus the BFF cover
reads well; the argument for a client cache is the vocabulary store, optimistic class-A mutations, and
realtime invalidation — all three of which are real. But it is a decision worth making explicitly with
an ADR rather than by installing a dependency, because the answer changes the shape of every screen
after S2-10.

**Q3 — Should bulk field editing be a distinct surface or a mode of the existing grid?**
Xero built two surfaces because it grew them separately. One grid serving both journal entry and bank
coding is more elegant and may be less usable; this is a design-partner question, not an engineering
one.

**Q4 — Is `reviewed_by` a per-record state or a per-batch attestation?**
Per-record is honest and may be onerous at 200 lines; per-batch is efficient and weaker. The Xero board
asks for per-record. QAYD's `I-08`/`I-17` reversibility work may suggest a third answer.

**Q5 — What is the correct staleness threshold at which a class-C figure stops being shown at all?**
Law 2 says show it dated. There is presumably a horizon past which "as of 40 minutes ago" is worse than
"unavailable, refreshing." Nobody in this study appears to have published a threshold `[UNKNOWN]`.

---

# End of Document
