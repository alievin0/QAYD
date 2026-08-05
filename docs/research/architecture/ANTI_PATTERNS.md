# 04 — Application & UX anti-patterns

**Eighteen ways to build this layer wrong, and the mechanism by which each fails · `docs/research/architecture/`**

Version 1.0 · 2026-07-28

`04_REJECTED_PATTERNS.md` already refuses a set of architectural patterns at the *system* level. This
document is about the **application and rendering layer** — the ways a product that respects every
backend boundary can still show a user something untrue, or make a fast system feel slow, or make a
keyboard-first product impossible to keep keyboard-first.

Format follows the house style: what it is · why it is tempting · why it fails, mechanically · the
transferable form · what to do instead. Each names where in QAYD it would first appear, so it is caught
in review rather than in production.

**The first four are one family**, and it is the family this folder exists for: they are all ways of
letting the client speak with an authority it does not have. AP-01 is the important one.

---

## Index

| ID | Anti-pattern | First appears at |
|---|---|---|
| **AP-01** | Optimistic money | S2-11, S4-04 |
| **AP-02** | The client-side ledger replica | any "make it feel like Linear" push |
| **AP-03** | Convergence mistaken for correctness | any CRDT proposal |
| **AP-04** | Pushing derived values over the realtime channel | S2-13, dashboard tiles |
| **AP-05** | The silent rollback | anywhere optimism is permitted |
| **AP-06** | Alert fatigue: the notification with no reason and no clearing verb | S4-09, notifications |
| **AP-07** | The indeterminate spinner as the default wait | S3-10, S4-02 |
| **AP-08** | Totals computed from the rendered rows | S2-12, S3-12 |
| **AP-09** | Virtualisation without its escape hatches | S2-10, S3-12 |
| **AP-10** | The modal round-trip | S2-11 account picker |
| **AP-11** | The configuration surface as the product | any "make it configurable" request |
| **AP-12** | Ranked search for identifiers | S4-09, palette |
| **AP-13** | Chat as the product | S4-10 |
| **AP-14** | AI that erases the evidence of its own work | S4-02, S4-03 |
| **AP-15** | Presence theatre | any collaboration push |
| **AP-16** | The automation rule soup | automation centre |
| **AP-17** | RTL as a final stylesheet | any new component |
| **AP-18** | Shortcuts bolted on after the components exist | S2-11 onward |

---

## AP-01 — Optimistic money

**What it is.** Rendering a value the server has not confirmed, where that value is a balance, a total,
a posted state, an approval state, an entry number, or anything a user might act on. The archetype is
the optimistic mutation pattern applied uniformly because it is the framework's idiom.

**Why it is tempting.** It is the single highest-leverage perceived-performance technique in modern web
development, it is one option object away in every data library, and the products QAYD wants to feel
like all use it heavily. The naive objection — "the number might be wrong for 300 ms" — is weak enough
that it invites the wrong reply: *300 ms is nothing.*

**Why it fails.** Not because of the latency. Because of the **asymmetry between the durability of the
pixel and the durability of the user's belief** (`OVERVIEW.md` §5b). A number rendered for 300 ms can be
read aloud on a call with the bank, pasted into an email to the owner, screenshotted, used to authorise
a payment, or typed into a VAT return. Once a human has acted on a number, correcting the pixel does not
correct the world. The harm does not scale with latency, so "it's only 300 ms" is not a defence.

There is a second-order effect that is arguably worse. **An optimistic financial UI that is usually
right trains users to trust it.** A system wrong 1 in 500 times is more dangerous than one wrong 1 in 5,
because nobody develops the habit of checking. This is AP-06's structure with the sign flipped.

**The specific instance to look at.** `docs/frontend/FRONTEND_ARCHITECTURE.md` states the rule correctly
in Principle 10 — optimistic where safe, pessimistic where it moves money — and then gives as its
canonical optimistic example the Approval Center's Approve action, flipping a card to `status:
"approved"` on mutate `[DOCS]`. The reasoning offered is that it is "reversible, non-financial" and
"sensitive-adjacent". But an approval is a *server decision*: it is subject to a permission, to step
ordering in a multi-step chain, to a stale-version check, to someone else having approved first, and to
segregation of duties — S2-06's acceptance criteria explicitly require that the entry creator is not its
sole approver `[CODE]`. The client cannot know it will succeed. And an approval, unlike a dismissal, is
socially durable: the user says "I approved it", closes the tab, and moves on. This is class B rendered
as though it were class A.

The fix is small and does not lose much: keep the interaction instant by moving the card to an explicit
**submitting** state (BP-05) rather than to `approved`. The user gets immediate feedback; the system
does not assert a decision it has not received.

**Transferable form.** *Optimism is safe exactly where the user is the only party who could contradict
the value, and nowhere else.*

**Instead.** `BEST_PRACTICES.md` **BP-01**, **BP-02**, **BP-05**. Class the type; let the type forbid
the mutation; render *pending*.

---

## AP-02 — The client-side ledger replica

**What it is.** "Linear replicates the workspace into IndexedDB and that is why it feels instant —
let's replicate the ledger."

**Why it is tempting.** It is the correct diagnosis of Linear's speed and it works spectacularly for
Linear.

**Why it fails.** Three independent reasons (`OVERVIEW.md` §5a):

1. **Linear's screen is made of fields; QAYD's is made of aggregates.** An issue's assignee is a stored
   value. A trial balance, an account balance and a reconciliation difference are aggregates over the
   ledger. To compute them on the client you must replicate the ledger.
2. **The ledger is unbounded.** It grows forever. There is no session at which "the working set" is a
   bounded thing.
3. **The ledger is the most confidential data in the product, and RLS is the mechanism that keeps it
   inside PostgreSQL.** A client replica is a deliberate exfiltration of exactly the data the entire
   isolation architecture exists to contain.

**Transferable form.** *Replicate the vocabulary, never the ledger.*

**Instead.** `BEST_PRACTICES.md` **BP-06**. The class-D store delivers most of the perceived-speed
benefit because the hot path is lookups, not aggregates — the client reads reference data on every
keystroke and touches the ledger once, at post time.

---

## AP-03 — Convergence mistaken for correctness

**What it is.** Reaching for a CRDT because "it guarantees consistency."

**Why it is tempting.** The guarantee is real and it is stated in strong language. Automerge and Yjs are
excellent. And it removes an entire category of conflict-handling code.

**Why it fails.** **A CRDT guarantees agreement, not legality.** It merges two states into a third that
is deterministic; nothing in the construction says the third state satisfies a business rule. Two
clients can each post a balanced entry into a period; the merge is deterministic and the period is now
closed with entries in it. For a ledger, legality is the entire point — and the interesting part is that
the stronger the convergence guarantee, the *less* room there is to enforce an invariant, because there
is no single place that gets to say no.

Note that Figma — the most successful multiplayer system in this study — explicitly rejected both OT and
true CRDTs, reasoning that OTs were "unnecessarily complex for our problem space" and that having a
central server let them "simplify our system by removing this extra overhead" `[DOCS]`
https://www.figma.com/blog/how-figmas-multiplayer-technology-works/. They chose the weakest mechanism
that solved the problem. QAYD's problem is weaker still: nothing in it is concurrently free-text edited.

**Transferable form.** *Convergence is a property about agreement; correctness is a property about
invariants. A system that needs invariants needs an authority.*

**Instead.** The server orders writes. It already does.

---

## AP-04 — Pushing derived values over the realtime channel

**What it is.** Reverb carries the new trial-balance figure, the new cash position, the new
unreconciled total — and the client patches it into the cache to avoid a refetch.

**Why it is tempting.** It removes a round trip on exactly the figures users watch. The payload is
already being assembled. And it is faster than invalidate-then-refetch by definition.

**Why it fails.** It creates a **second computation path for a financial number**. Two things now
compute that figure: the reporting query, and whatever assembled the broadcast payload. Second
computation paths diverge — silently, and usually only for the tenant with the unusual data (the one
with a mid-period reclassification, the multi-currency one, the one with a control account). Figma can
accept this for a comment count. A ledger cannot.

The subtle part is that this does not fail loudly. The pushed figure and the queried figure differ by a
small amount for one customer, nobody notices for months, and when they do, there is no way to say which
was right.

**The specific instance to watch.** The existing spec's rule is "invalidate by default, patch only for
high-frequency low-risk ticks", with a dashboard tile's live count and an AI job's progress percentage as
the named exceptions `[DOCS]`. AI job progress is genuinely safe and the spec's own safety net — an
ordinary invalidation at the terminal state — closes it. A **dashboard financial tile** is the one to
reconsider: it is class C, it may never reach a terminal state, and a drifted patch has no scheduled
correction. A notification badge count is a third case and is probably fine — it counts inbox rows, not
ledger rows — but that should be decided by its class, not by an assessment of "risk", so that the next
tile does not have to re-argue it.

**Transferable form.** *A realtime message may carry a value only where the value is the datum. Where
the value is derived, it carries the fact of change and the client refetches.*

**Instead.** `ARCHITECTURE.md` §6. Facts about change for class B/C; values permitted for class D.

---

## AP-05 — The silent rollback

**What it is.** An optimistic update is rejected, the UI quietly reverts, and a toast appears in a
corner for four seconds.

**Why it is tempting.** It is the default in every library's example. Loud failure feels
unprofessional. And most rejections are transient.

**Why it fails.** The user's attention was on the *thing they did*, not on the corner of the screen. They
saw the change take effect. If the revert happens while they are reading the next row, they never learn
it did not happen — and the toast is gone before they look up. In a task manager this loses a label
change. In an accounting product this loses an entry the user believes exists, and the discovery point
is month-end.

It is also the failure mode that makes AP-01 worse: even a correctly-classified optimistic update needs
a *loud* rejection path, or the classification is the only thing standing between the product and a lost
write.

**Transferable form.** *A reverted action must be at least as visible as the action was.*

**Instead.** Rejection returns the affected object to a visible error state *in place*, with the reason
and a retry, and it stays until dismissed. A toast may accompany it; a toast may not be it.

---

## AP-06 — Alert fatigue: the notification with no reason and no clearing verb

**What it is.** Notifications that say *what happened* but not *why you are seeing this* and not *what
makes it go away*. The end state is a user who mutes the channel, which is a permanent loss of a
delivery path.

**Why it is tempting.** Every domain event is arguably interesting to someone, the fan-out is easy, and
the volume problem only appears at scale — by which point the schema is set and the users are trained.

**Why it fails.** Two mechanisms, and they compound.

1. **Without a reason, the user cannot tune.** They can only mute a whole category, so they mute the
   category that contains the one thing they needed. GitHub's model exists precisely to prevent this:
   every notification carries a `reason` (`mention`, `subscribed`, `review requested`) and is filterable
   by it `[DOCS]`
   https://docs.github.com/en/account-and-profile/managing-subscriptions-and-notifications-on-github/setting-up-notifications/about-notifications.
2. **Without a clearing verb, the inbox has no empty state.** An inbox that cannot be emptied stops
   being a worklist and becomes a feed, and a feed is something you skim. GitHub's four triage verbs —
   Done, Saved, read/unread, Unsubscribe — exist to make "cleared" a real state.

Slack is the standing cautionary study here: a genuinely good notification product that nonetheless
trained a generation of users to ignore it, because volume outran controllability.

QAYD's specific risk is the close-period storm — one action generating dozens of notifications for the
same people, at exactly the moment those people are busiest.

**Transferable form.** *A notification that cannot answer "why me?" and "what clears this?" is a
subscription the user will eventually cancel.*

**Instead.** `BEST_PRACTICES.md` **BP-27** (reason + clearing verb in the schema, before the table
exists) and **BP-28** (digest by default). Two columns now; a data migration and a UI rewrite later.

---

## AP-07 — The indeterminate spinner as the default wait

**What it is.** Every wait over ~200 ms is a spinner. Long waits are a bigger spinner.

**Why it is tempting.** It is one component, it works everywhere, and it requires nothing of the
backend.

**Why it fails.** Nielsen's boundaries are 0.1 s (instantaneous), 1 s (flow of thought preserved) and
10 s (attention retained) `[DOCS]`
https://www.nngroup.com/articles/response-times-3-important-limits/. Between 1 s and 10 s the variable
that determines whether the wait is tolerable is **legibility, not duration**. Three seconds with
determinate progress reads as fast; three seconds behind an indeterminate spinner reads as broken,
because the user cannot distinguish "working" from "hung" and has no basis for deciding whether to wait.

For QAYD this matters most exactly where the AI and the imports live — the surfaces that will
legitimately take seconds, and the surfaces that form the first-run impression.

**A specific sub-failure:** a progress bar not driven by real counts is worse than a spinner, because it
makes a promise it cannot keep.

**Transferable form.** *Above one second, buy time with information rather than with animation.*

**Instead.** `BEST_PRACTICES.md` **BP-24**. "Reading 42 statement lines… matching… 38 of 42 matched."

---

## AP-08 — Totals computed from the rendered rows

**What it is.** The footer sums the array the table is rendering.

**Why it is tempting.** It is one line, it is always consistent with what is on screen, and it needs no
API change.

**Why it fails.** "The rows on screen" is not a defined population. Under virtualisation it is
meaningless — it is whatever is mounted. Under pagination it is *plausible but wrong*, which is the
dangerous case, because the number looks like a total and behaves like a page subtotal. Under a filter
that the server applied but the client partially re-applied, it is wrong in a way nobody can reproduce.

This is the rendering-layer instance of the standing rule against trusting a cached total, and it is the
most likely place for that rule to be breached by accident, because the breach looks like ordinary
component code.

**Transferable form.** *A total is a statement about a set; if the client cannot name the set, it cannot
compute the total.*

**Instead.** `BEST_PRACTICES.md` **BP-16**. Server-side over the full filtered set, with the filter
echoed beside it.

---

## AP-09 — Virtualisation without its escape hatches

**What it is.** Adding a virtualiser to any table that "might get big", and treating the loss of
find-in-page, print and select-all-copy as acceptable collateral.

**Why it is tempting.** It is a strict improvement on the frame-rate axis, it is a small change, and the
three costs are invisible in development because developers scroll, they do not `Ctrl+F` a general
ledger.

**Why it fails.** For an accountant, `Ctrl+F` and copy-to-Excel are not conveniences; they are the job.
A virtualised grid silently removes two of the three tools they use to check a screen. And the ARIA APG
names a fourth cost explicitly: when a list "dynamically loads more" content, "keyboard users are
effectively trapped in the list" `[DOCS]` https://www.w3.org/WAI/ARIA/apg/patterns/grid/.

**The inverse failure is equally real and more common:** virtualising things that do not need it. A
trial balance is 60–400 rows; a GCC SME chart of accounts is 150–400 `[INFERENCE]`. S2-10's acceptance
criteria specify a virtualized COA tree `[CODE]` — that pays all four costs for no measurable gain.

**Transferable form.** *Virtualisation is a trade, not an upgrade. Make it above a measured threshold,
and pay for it in the same change.*

**Instead.** `BEST_PRACTICES.md` **BP-15**.

---

## AP-10 — The modal round-trip

**What it is.** The account picker opens a dialog, which fetches accounts, which the user searches,
selects, and confirms — per line.

**Why it is tempting.** It is the standard component in every UI kit, it handles a large list neatly, it
gives room for rich account metadata, and it is what every competitor does.

**Why it fails.** It imposes three costs per field that a keyboard grid does not: a **target
acquisition** (Fitts's-law pointing time, ~0.5–1.2 s for a small target `[COMMUNITY]`), a **modality
switch** as the hand leaves the keyboard and returns, and — the expensive one — a **network round trip
for lookup data**. Three of these per line at twelve lines is a ninety-second entry instead of a
twenty-second one (`OVERVIEW.md` §4).

Note that the fix is not a better dialog. It is architectural: the round trip is only removable because
the accounts are already on the client (BP-06). A picker that fetches on open cannot be made fast by any
amount of component craft.

**Transferable form.** *Every modal in a repetitive flow is a tax charged per repetition.*

**Instead.** `BEST_PRACTICES.md` **BP-12**: in-cell type-ahead resolved synchronously from the class-D
store, with the dialog retained as a discovery path for the rare case, not as the primary one.

---

## AP-11 — The configuration surface as the product

**What it is.** Answering "our workflow is different" with a setting. Then a screen scheme. Then a field
scheme. Then a permission scheme.

**Why it is tempting.** Every individual request is reasonable, cheap, and comes from a real customer.
Configurability wins enterprise procurement — it is why Jira wins evaluations. And saying no to a
paying customer's workflow feels like arrogance.

**Why it fails.** The configuration surface *is* the product's complexity, and it is unbounded. Jira is
the reference implementation: custom fields, screens, screen schemes, issue-type schemes, workflow
schemes, permission schemes. No two installations behave the same, which means there is no learnable
action set, no useful default, no keyboard model, and no support answer that generalises. Its
performance complaints are chronic and structural — an issue view is assembled from configuration at
request time.

The deeper point, and the reason this anti-pattern sits in *this* folder: **keyboard-first and infinite
configurability are mutually exclusive architectures, not two features you can both have.** A
keyboard-first product requires a small, named, stable action set. Linear's own method says so directly:
"Flexible software lets everyone invent their own workflows, which eventually creates chaos" `[DOCS]`
https://linear.app/method/introduction. ERP chose configurability, which is why no ERP has ever felt
fast.

**The test, which is the useful part.** For every proposed knob:

> **Does the law vary here, or only taste?**

The law varies: fiscal year start, tax registration status, base currency, chart-of-accounts structure,
approval thresholds mandated by the company's own policy, GCC jurisdiction. Ship those as configuration.
Taste varies: which columns appear, in what order, at what density, in what colour. Ship those as class-E
preferences, which cost nothing. Everything else — the shape of a journal entry, what "posted" means, the
sequence of a reconciliation — is neither, and it is a default.

**Transferable form.** *Configurability is not free; it is paid for in speed, learnability and support,
by every customer, forever.*

**Instead.** Configuration where the law varies; preferences where taste varies; defaults everywhere
else.

---

## AP-12 — Ranked search for identifiers

**What it is.** One relevance pipeline for everything, so `INV-4471` is scored against all candidates
like any other query.

**Why it is tempting.** One pipeline is simpler than two. Ranking demonstrably improves results — Slack
measured 9% more clicked searches and 27% more clicks at position 1 from its learning-to-rank re-ranker
`[DOCS]` https://slack.engineering/search-at-slack/. And in the common case the exact match *does* rank
first.

**Why it fails.** "Usually first" is not a property an accountant can build a habit on. Worse, it is
*silently* not first: a ranking improvement six months later — new features, retrained weights, a
different click distribution — can demote an exact identifier match with nobody intending it and no test
catching it. The failure is invisible at deploy time and appears as a slow erosion of trust in search.

Accounting queries are dominated by identifiers, dates and amounts. This is the majority case.

**Transferable form.** *Where an exact answer exists, ranking is a downgrade. Short-circuit; do not
score.*

**Instead.** `BEST_PRACTICES.md` **BP-19** (short-circuit before any scorer runs) and **BP-20** (prefixes
so the user can declare the scope explicitly).

---

## AP-13 — Chat as the product

**What it is.** The AI's primary surface is a chat panel. Proposals are rendered as text or Markdown
tables inside it. "Ask the assistant" becomes the answer to every product question.

**Why it is tempting.** It is the surface users now expect, it is the fastest to build, it demos
extremely well, it accommodates every capability without a new screen, and it is the format the model
natively produces.

**Why it fails.** A chat panel that renders a proposed journal entry as a table has moved the entry
**out of the component that knows how to judge it**. The grid knows whether the entry balances, whether
the period is open, whether the account is postable, what the running difference is, and how to render
KWD to three decimals. The chat panel knows none of that. So the user must re-verify by eye everything
the product already knows how to check — which is strictly more work than entering it manually, done
under the impression that it is less.

There is a second failure that is specific to accounting: chat produces *text about* the system rather
than *changes to* it. Every answer must be re-checked against the real screen, and the checking is
exactly the labour the feature claimed to remove.

This is also the surface where QAYD's own AI architecture makes the alternative available. The ai/
folder concluded QAYD should build a **deterministic proposal pipeline, not an agent** — the model
returns a typed proposal about a specific record. A typed proposal about a record has an obvious home:
the surface that renders that record.

**Transferable form.** *AI changes where the values came from; it must not change the surface that
judges them.*

**Instead.** `BEST_PRACTICES.md` **BP-22**, `ARCHITECTURE.md` §8. Inline, palette and review queue are
primary; chat is the fallback for open-ended questions.

---

## AP-14 — AI that erases the evidence of its own work

**What it is.** The AI codes a transaction and the resulting record shows the *result* — an account, an
amount, a match — without the source text it worked from and without any record of whether a human has
looked at it.

**Why it is tempting.** The clean record is the point of the feature. Carrying the raw bank narration
alongside the tidy coded values feels like clutter, and "reviewed" feels like a state the automation was
supposed to make unnecessary.

**Why it fails, with evidence.** This is the most instructive failure available in this market, because
it happened to the market leader in public and the complaints are **not about accuracy**. On Xero's own
product-ideas board for its automatic bank reconciliation beta `[DOCS]`
https://blog.xero.com/product-updates/automatic-bank-reconciliation-jax-beta/:

- **"Ability to Mark Auto Reconciliation transactions as reviewed"** — 38 votes, *Gaining Support*.
  The request asks for a reviewed state recording the person, date and time, plus a widget filtered to
  unreviewed items, on the grounds that data integrity is paramount and someone must take
  responsibility for what is entered `[COMMUNITY]`
  https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta/suggestions/50981083-auto-bank-rec-ability-to-mark-auto-reconciliatio
- **"The description and/or reference doesn't pull through"** — 27 votes. The submitter's reason is the
  mechanism: without the bank's description, "we can't verify if the items that were auto coded are
  correct against what the bank description was" `[COMMUNITY]`
  https://productideas.xero.com/forums/966939-automatic-bank-reconciliation-beta

Both are the same failure wearing two hats: **the automation removed the evidence a human needs in order
to check it.** One removed the reviewed state; the other removed the source text. Neither would be
improved by a better model, and both make the feature's headline benefit unclaimable — the time is only
saved if the review is *shorter*, not if it is *impossible*.

**Transferable form.** *An AI feature's usefulness is bounded by the cost of verifying it, and every
piece of evidence it discards raises that cost.*

**Instead.** `BEST_PRACTICES.md` **BP-25** (`reviewed_by`/`reviewed_at`, filterable) and **BP-26**
(source fields preserved and displayed). Both are near-free before the tables exist.

---

## AP-15 — Presence theatre

**What it is.** Avatars on every list, live cursors, "someone is typing", a presence bar in the header.

**Why it is tempting.** It is the visual signature of a modern collaborative product, it demos well, and
Figma and Notion have made it feel like table stakes.

**Why it fails.** Presence has value as a **conflict-prevention** signal — knowing someone else is
editing the thing you are about to edit. Everything beyond that is decoration that costs a presence
channel, a subscription per screen, and a steady trickle of WebSocket traffic per user.

QAYD has no concurrently free-text-edited document, so the case that justifies multiplayer elsewhere
does not exist here. Draft collision at month-end does, and it is narrow.

**Transferable form.** *Build presence where two people would otherwise duplicate or destroy each
other's work; nowhere else.*

**Instead.** `BEST_PRACTICES.md` **BP-29**. Scope presence to the specific draft or the specific staged
match.

---

## AP-16 — The automation rule soup

**What it is.** A general "when X changes, do Y" builder, growing to hundreds of rules nobody can audit,
firing on field changes, writing fields directly.

**Why it is tempting.** Automation builders sell. Every customer wants one. Monday, ClickUp and Asana
all ship them, and they are a visible answer to "can it do our process?".

**Why it fails.** ERP workflow engines are *more* expressive than Linear's automations and are worse in
practice, for three specific reasons (`OVERVIEW.md` §7): triggers over "any field change on any object"
make no automation reviewable — you cannot answer "what runs when I post this entry?" without simulating
it; actions that write fields directly bypass the permission and validation paths, which is how "the
workflow did it" becomes an unanswerable audit finding; and the blast radius is global and invisible,
discovered during incidents.

For an accounting product the audit consequence is the disqualifying one. An entry whose provenance is
"a rule, edited by someone, at some point, that wrote a field directly" is an entry with no answer to
the only question an auditor asks.

**Transferable form.** *An automation must be describable as "the system did what a permitted user could
have done", or it is a hole in the audit trail.*

**Instead.** `BEST_PRACTICES.md` **BP-30**, built on the action registry (**BP-10**) — closed trigger
vocabulary, actions that are the same actions a human can take, bounded scope, a record on the object.
And not before the registry exists, because until then there is nothing to compose.

---

## AP-17 — RTL as a final stylesheet

**What it is.** Build in English, add an Arabic pass later; mirror with a direction stylesheet and fix
what breaks.

**Why it is tempting.** It sequences the work in the order the team can move fastest, and the first
90% of mirroring genuinely is a stylesheet.

**Why it fails.** The last 10% is not layout — it is numerals inside mirrored containers, icon
directionality, chart axes, the sign and grouping of amounts, sort-order arrows, and **keyboard
semantics in a grid** (BP-32), which no LTR test can catch and which only Arabic-first users hit. It is
named in `OVERVIEW.md` §11 as the most notorious retrofit in GCC software.

**QAYD is the counter-example and should stay one.** Full EN/AR with RTL shipped in Sprint 1 (S1-14
`[CODE]`), Principle 6 requires both directions in the same PR as the component, and the money formatter
already forces Latin digits and knows KWD carries three decimals `[CODE]`. The anti-pattern is listed
here not as a warning about the past but as the pressure that will appear when the schedule tightens
around S3-12 and S4-09.

**Transferable form.** *Direction is a property of every component, decided when the component is
written, or it is a rewrite.*

**Instead.** Hold the existing rule. Route every new numeric surface through one primitive
(**BP-31**), and pin grid key semantics in the contract (**BP-32**).

---

## AP-18 — Shortcuts bolted on after the components exist

**What it is.** Build the screens; add a shortcuts pass in a later sprint; maintain a hotkey map beside
the components.

**Why it is tempting.** Shortcuts feel additive — a thin layer over working screens — and there is
always something more urgent.

**Why it fails.** Mechanically, in three ways.

1. **A shortcut needs a target that is addressable.** If the operation exists only as an `onClick` in a
   component, the shortcut must reach into that component, so the hotkey layer accumulates references to
   internals and every refactor breaks a binding silently.
2. **The permission is then checked twice, in two places, and they drift.** QAYD's existing spec already
   anticipates this and states the rule — "the shortcut and the button share one permission check, never
   two" `[DOCS]` — which is exactly the registry's job (**BP-10**).
3. **Keyboard semantics are muscle memory and cannot be revised after launch.** A binding shipped wrong
   is either kept wrong or breaks the users who adopted it fastest.

The deeper failure is that a bolted-on shortcut layer only ever covers the operations someone remembered
to add, so coverage is permanently partial — and partial coverage is what makes users go back to the
mouse, which is the entire loss.

**Transferable form.** *A keyboard model is a projection of a declared action set. Without the
declaration it is a hand-maintained list that decays.*

**Instead.** `BEST_PRACTICES.md` **BP-10**, then **BP-14** as the standing check.

---

## Cross-cutting: how these compound

Three compounds are worth naming, because each is more than the sum of its parts.

**AP-01 + AP-05 = the invisible lost write.** An optimistic financial update that is rejected and
silently reverted is a write the user believes happened. Neither anti-pattern alone produces that;
together they do, and the discovery point is month-end.

**AP-04 + AP-08 = two wrong totals that disagree.** A pushed derived value and a client-summed footer
are two independent divergences from the server's answer, on the same screen, with no way to tell which
is closer.

**AP-11 + AP-18 = a product that cannot be made fast later.** Once the action set is unbounded by
configuration, there is nothing stable for a keyboard model to project from, and the keyboard-first
property becomes permanently unavailable. This is the compound that turns a startup into an ERP, and it
happens one reasonable customer request at a time.

# End of Document
