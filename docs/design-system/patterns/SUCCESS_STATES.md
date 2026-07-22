# Success-State Pattern — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / SUCCESS_STATES
---

# Purpose

This document specifies the **success-state pattern** — the composed way QAYD confirms *"the thing you did
worked."* It is the positive counterpart to the three non-happy patterns: loading
([`./LOADING_STATES.md`](./LOADING_STATES.md)) says "we don't know yet," empty
([`./EMPTY_STATES.md`](./EMPTY_STATES.md)) says "there's nothing," error
([`./ERROR_STATES.md`](./ERROR_STATES.md)) says "we failed," and this pattern says "we succeeded." Together
the four partition every outcome a data-bearing action can have, and keeping the fourth as *restrained* as
the other three are calm is the whole point of this document.

It is a pattern doc, not a primitive: it does not re-specify the toast, the checkmark glyph, or the
`Stepper`, but the arrangement of four confirmation surfaces — an inline success mark, a success toast, a
post-action confirmation, and a completion screen — and the one rule that governs all of them: **confirm
with certainty, never celebrate.** QAYD is an AI Financial Operating System. Posting a journal entry,
closing a period, issuing an invoice, and balancing a reconciliation are legally-consequential acts, not
game mechanics. The correct feedback for a balanced, posted entry is a single quiet checkmark that fades in,
holds, and fades out — never confetti, never a particle burst, never a sound, never a bounce. A celebratory
animation on a posted entry would frame a consequential action as a reward; the pattern is calibrated to
feel like a well-made physical switch (a short, certain click), per
[`../MOTION_SYSTEM.md → Motion Principles`](../MOTION_SYSTEM.md).

This pattern draws its surfaces from existing atoms — the `Toast` `success` variant
([`../components/TOAST.md`](../components/TOAST.md)), the `Progress`/`Stepper` completion state
([`../components/PROGRESS.md`](../components/PROGRESS.md)), and the `Card`
([`../components/CARD.md`](../components/CARD.md)) — and its motion from the success-checkmark named pattern
in [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md). It introduces no new primitive; it is the *arrangement and
restraint* that make a QAYD success read as confidence rather than applause.

Token-reconciliation note. Where an application-level surface this pattern touches was written against the
earlier numeric palette, the **canonical** tokens per [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md)
are `ink-1 … ink-12`, `accent`/`accent-subtle`, and the financial-semantic `positive`/`positive-subtle`.
Success uses `positive` on the checkmark glyph and `positive-subtle` at most as a brief wash — never a
saturated green fill, exactly as status color is desaturated and label-paired everywhere else.

# When to Use

Reach for the success pattern when a mutation has **settled successfully** — the fourth branch of the
outcome partition every action runs:

| Condition | Truth | Pattern |
|---|---|---|
| Mutation in flight | "We don't know yet" | Loading ([`./LOADING_STATES.md`](./LOADING_STATES.md)) |
| Mutation settled `isError` | "We tried and failed" | Error ([`./ERROR_STATES.md`](./ERROR_STATES.md)) |
| Mutation settled `isSuccess` | **"It worked"** | **Success (this doc)** |

Choose the surface by the *weight and finality* of the action:

| Action weight | Surface | Example |
|---|---|---|
| Routine, reversible, low-stakes | **Inline success mark** (or no feedback if optimistic) | A field auto-saved, a setting toggled, an insight marked read |
| A discrete action with a confirmable outcome | **Success toast** | "Entry posted," "Statement imported," "Draft saved" |
| A consequential action the user watched complete | **Post-action confirmation** (checkmark + settled state) | Posting a journal entry, committing a reconciliation |
| A multi-step process reaching its end | **Completion screen** | Period closed, onboarding finished, a bulk import fully reconciled |

Use it when:

- A discrete mutation succeeds and the user should know the outcome without leaving their context (toast).
- A consequential, watched action completes and its *result* should be visible and durable (confirmation).
- A bounded process crosses its finish line and there is a genuine "you're done here / what's next" moment
  (completion screen).

Do **not** use it when:

- The action was optimistic and low-stakes — the UI already shows the end state; a success confirmation on
  top is redundant noise (see [`./LOADING_STATES.md → Optimistic UI`](./LOADING_STATES.md)).
- The "success" is merely a page finishing loading — that is not an action outcome and gets no confirmation.
- The action failed or partially failed — that is the error pattern, or a mixed-result summary, never a
  success dressed over a problem.

# Anatomy

Four surfaces, each with a fixed, restrained anatomy.

## 1. Inline success mark

A single `Check`/`CircleCheck` Lucide glyph in `positive`, appearing beside the control it confirms, holding
briefly, then fading. No text, no container — the smallest possible confirmation for a reversible action.

```
   [ Auto-saved [check] ]      ← a copy-confirm or save-confirm: glyph fades in (motion.base),
                            holds ~900ms, fades out (motion.fast); positive tone, aria-live polite
```

## 2. Success toast

The overwhelmingly common case. A `Toast` `success` variant — a quiet `surface-2` card, a `CheckCircle2`
glyph in `positive`, a one-line outcome, an optional description, and at most one action ("View," "Undo").

```
┌──────────────────────────────────────────────┐
│ ▎ [check]  Entry posted.                        x  │  ← border-s-2 positive, glyph, title, dismiss
│      JE-2026-07-0482 · Rent accrual   [View] │  ← description + one action
└──────────────────────────────────────────────┘
```

| Slot | Token | Rule |
|---|---|---|
| Container | `bg-ink-1` / `dark:bg-ink-3`, 1px `ink-6`, `radius-lg`, `shadow-sm` | Never a full-bleed green fill |
| Leading glyph | `CheckCircle2`, `positive`, `aria-hidden` | Color is the second signal; the title carries meaning |
| Tone stripe | 2px `border-inline-start` in `positive` | Leads the content on the reading-start edge |
| Title | `text-sm` `ink-12` | The localized outcome ("Entry posted.") |
| Description | `text-sm` `ink-9` | Optional; the journal number + reference |
| Action | `accent` → `accent-strong` | At most one ("View," "Undo") |

## 3. Post-action confirmation

For a consequential, watched action, the confirmation is the *checkmark motion plus the settled result* — the
posted entry now shows its `StatusPill` as Posted, its journal number assigned, its actions transitioned from
"Post" to "View / Reverse." The single-checkmark animation marks the transition; the durable confirmation is
the record's own changed state, not an ephemeral banner.

## 4. Completion screen

A route- or drawer-level `size="page"` surface for a process reaching its end, built on `Card` with a
centered stack echoing the empty-state anatomy but toned to `positive`:

```
        ┌─────────────────────────────────────────────┐
        │              [ CircleCheck ]        ← positive glyph, 24px, aria-hidden
        │                                             │
        │           Period closed             ← title (display-lg, ink-11, real <h2>)
        │                                             │
        │   June 2026 is locked. 482 entries,   ← description (text-md, ink-9)
        │        KD 1,204,500.000 posted.             │
        │                                             │
        │   [ View Trial Balance ]  [ Close another ] ← primary + secondary (Button)
        └─────────────────────────────────────────────┘
```

# Variants

| Variant | Surface | Tone | Motion | When |
|---|---|---|---|---|
| Inline mark | A glyph beside a control | `positive` | Checkmark fade-in, ~900ms hold, fade-out | A reversible save/copy/toggle confirm |
| Success toast | `Toast` `success` | `positive` glyph + 2px stripe | Block-end slide + fade (`moderate` in / `fast` out) | A discrete mutation's outcome — the common case |
| Confirmation | The record's settled state + one checkmark | `positive` glyph, then the record's own `StatusPill` | Single checkmark, then a calm state swap | A consequential, watched action (post, commit) |
| Completion screen | `Card` `size="page"` | `positive` glyph; `positive-subtle` wash at most | Content `fadeUp`; one checkmark; **no** count-up | A bounded process reaching its end |

Two hard exclusions cut across every variant:

- **No decorative celebration.** No confetti, particles, fireworks, bounce, spring, or sound — anywhere,
  ever, in the authenticated product.
- **No count-up on a resulting figure.** A completion screen's totals appear with the container's `fadeUp`;
  they never odometer-roll or count up from zero, which would imply the number is still settling when it is
  in fact final — actively misleading in an accounting product
  ([`../MOTION_SYSTEM.md → Where Motion Is Allowed`](../MOTION_SYSTEM.md)).

# Composition

| Concern | Atom / token source |
|---|---|
| Inline mark / confirmation glyph | Lucide `Check`/`CircleCheck`, `text-positive`, `aria-hidden` |
| Success toast | `Toast` `success` via `useApiToast().success` ([`../components/TOAST.md`](../components/TOAST.md)) |
| Settled record state | `StatusPill` badge ([`../components/BADGE.md`](../components/BADGE.md)) — the durable confirmation |
| Completion screen | `Card` ([`../components/CARD.md`](../components/CARD.md)) + `Button` ([`../components/BUTTON.md`](../components/BUTTON.md)) |
| Multi-step completion | `Stepper` completed state — filled node + `CircleCheck` ([`../components/PROGRESS.md`](../components/PROGRESS.md)) |
| Amounts in copy | The `Amount` formatter — Western digits, KWD three-decimal, `dir="ltr"` |
| Colors | `positive` / `positive-subtle` / `ink-9` / `ink-11` from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) |

## The success-checkmark motion

The one sanctioned success animation is a single quiet checkmark, expressed as one Framer Motion variant with
`times`/`delay` — never a multi-step chain, and kept end-to-end under the `motion.slow` (360ms) ceiling for
its animated segments:

```tsx
// The posted-entry confirmation checkmark: fades in over motion.base, holds ~900ms, fades out over
// motion.fast. Under reduced motion it appears at its final state instantly and still holds — the
// state change (confirmed) is preserved; only the tween is removed. See ../MOTION_SYSTEM.md.
import { motion, useReducedMotion } from 'framer-motion';
import { CircleCheck } from 'lucide-react';
import { duration, easeOut } from '@/lib/motion';

export function PostConfirmCheck({ show }: { show: boolean }) {
  const reduced = useReducedMotion();
  if (!show) return null;
  return (
    <motion.span
      role="status"                                   // polite: announces "Posted" once, does not interrupt
      aria-label="Posted"
      initial={reduced ? { opacity: 1 } : { opacity: 0, scale: 0.9 }}
      animate={
        reduced
          ? { opacity: 1 }
          : { opacity: [0, 1, 1, 0], scale: [0.9, 1, 1, 1] }
      }
      transition={reduced ? { duration: 0 } : { duration: 1.32, times: [0, 0.15, 0.83, 1], ease: easeOut }}
    >
      <CircleCheck className="h-5 w-5 text-positive" aria-hidden strokeWidth={1.5} />
    </motion.span>
  );
}
```

The checkmark scales from `0.9 → 1` (a restrained settle, never an overshoot past `1`), holds fully opaque
for the middle of its timeline, then fades — a single beat, not a chain. It animates only `opacity`/`scale`
(compositor-friendly) and never a layout property. A completion screen composes the content `fadeUp` with, at
most, this one checkmark; the two are sequenced as a single timeline, never chained A-then-B-then-C.

## Success toast composition

```tsx
// A mutation's success — one line, the common case. Failure routes through fromApiError (error pattern).
const toast = useApiToast();
const post = usePostJournalEntry();

await post.mutateAsync(entry.id, {
  onSuccess: (e) =>
    toast.success(t('toast.posted'), {                 // "Entry posted." — positive tone, polite live region
      description: `${e.journal_number} · ${e.reference}`,
      action: { label: t('view'), onClick: () => router.push(`/accounting/journal-entries/${e.id}`) },
    }),
  onError: (err) => toast.fromApiError(err, { setError: form.setError }),
});
```

An **undoable** action puts its undo *in* the toast (the toast is the safety net) and extends the duration
so the undo is reachable:

```tsx
toast.success(t('toast.draftDeleted'), {
  duration: 8000,
  action: { label: t('undo'), onClick: () => restoreDraft(draftId) },
});
```

# States & Behavior

| State | Trigger | Rendering / behavior |
|---|---|---|
| Inline confirm | A reversible mutation settles `isSuccess` | Checkmark fades in, holds ~900ms, fades out; the control returns to rest |
| Toast confirm | A discrete mutation settles `isSuccess` | `success` toast slides in, auto-dismisses (4000ms floor; 6000ms+ if it carries an action) |
| Watched confirm | A consequential action settles `isSuccess` | One checkmark marks the transition; the record's `StatusPill` and actions swap to the settled state |
| Completion | A bounded process reaches its end | The completion screen mounts with `fadeUp`; totals appear settled (no count-up); primary "what's next" action offered |
| Partial success | A bulk action where some items failed | **Not** a plain success — a mixed-result summary listing succeeded vs. failed, with the failed items routed to the error pattern |
| Reduced motion | `prefers-reduced-motion` set | The checkmark appears at its final state instantly and still holds; the toast cross-fades in place; the *confirmation* is preserved, only the tween removed |

## Behavioral rules

- **Calm by default; celebrate never.** The heavier the action, the *quieter* the feedback — a posted
  journal entry gets a single checkmark and a changed status, not more fanfare than a saved draft. Editorial
  restraint scales inversely with consequence.
- **The durable confirmation is state, not an ephemeral banner.** For a consequential action, the lasting
  proof is the record's own changed state (Posted `StatusPill`, assigned journal number, transitioned
  actions); the checkmark and toast are the transient marker of the moment, not the record of it.
- **One action per toast.** A success toast carries at most one follow-up ("View" or "Undo," never both) — if
  two follow-ups matter, the outcome belongs on a surface the user can dwell on, not an auto-dismissing
  toast.
- **Success never hides a partial failure.** A bulk import where 3 of 500 rows failed is a mixed-result
  summary — "497 imported, 3 need attention" with the 3 routed to inline errors — never a green "Import
  complete" that buries the failures.
- **A completion screen offers a real next step.** "Period closed" leads to "View Trial Balance" — the
  screen is a navigational hinge, not a dead-end trophy.

# Content & Copy Guidance

Success copy is short, factual, and past-tense — it states what is now true, not how impressive it is.

- **State the outcome as a completed fact.** "Entry posted." "Reconciliation committed." "Period closed."
  Never "Great job!", "Success!", "Woohoo — your entry is live!", or an exclamation-laden cheer.
- **Include the durable reference** where one exists — the journal number, the statement name, the closed
  period — so the confirmation doubles as a receipt: "Entry posted. JE-2026-07-0482 · Rent accrual."
- **Completion screens summarize the result plainly** — "June 2026 is locked. 482 entries,
  KD 1,204,500.000 posted." — a factual close-out, not a congratulation.
- **Every string is an i18n key** in both `en.ts` and `ar.ts`, checked by `i18n:check`.
- **Arabic success copy is a parallel original** in the same calm, professional register — "Entry posted."
  → "تم ترحيل القيد." — never machine-translated and never more effusive than the English (an over-warm
  Arabic success undercuts the product's composure exactly as an over-warm English one would).
- **Amounts and references render `dir="ltr"`** via the `Amount` formatter, so "KD 1,204,500.000" and
  "JE-2026-07-0482" read correctly inside an Arabic success sentence.

# Accessibility

- **Announced politely, never assertively.** Success surfaces use `role="status"` (`aria-live="polite"`), so
  a screen-reader user hears the confirmation after their current utterance finishes — the polite half of the
  polite/assertive split whose assertive half belongs to the error pattern. A success is never `role="alert"`.
- **The glyph is decorative.** The checkmark is `aria-hidden`; the confirmation lives in the text ("Entry
  posted," or the surface's `aria-label`), so a colorblind or grayscale user reads the same success. Color
  (`positive` green) is never the sole carrier of "it worked."
- **The confirmation survives reduced motion.** Under `prefers-reduced-motion` the checkmark appears at its
  final state and still holds, the toast cross-fades in place, and the completion screen appears without
  `fadeUp` — the *state change* (confirmed) is always preserved; only its animation is removed.
- **Toasts and inline marks never steal focus.** They announce via the live region while focus stays on the
  user's task; a success that genuinely warrants a focus move (a completion screen the user navigates to) is
  a real route with the App Router's own post-navigation focus handling, not a toast grabbing focus.
- **A watched confirmation is announced through the changed state.** When a record flips to Posted, its
  `StatusPill` change is announced via the region's live area, so assistive tech perceives the outcome, not
  just a vanished spinner.
- **Contrast holds in both themes.** `positive` on the toast/completion surface clears the 3:1 non-text
  floor for the glyph, and `ink-11`/`ink-9` title/description clear AA, in light and dark.

# Theming, Dark Mode & RTL

## Theming

Success surfaces reference only semantic tokens — `text-positive`, `bg-positive-subtle` (sparingly),
`text-ink-11`, `text-ink-9`, and `accent` on any action via `Button`. No raw green hex, no Tailwind palette
value. A white-label accent override touches only the action's fill, never the `positive` confirmation color
— financial status color is never brand color.

## Dark mode

No `dark:` variants. `positive` remaps under `.dark` from `#17794A` to the lifted `#4ADE94`, and
`positive-subtle` from `#E3F3EA` to `#12301F`, so the checkmark keeps its meaning and contrast against the
dark canvas without glowing. The completion-screen surface, like every elevated surface in dark, is a step
*lighter* than the canvas rather than darker.

## RTL

- **Centered stacks are direction-neutral** — the inline mark, completion screen, and confirmation need no
  mirroring; glyph, title, description, and action row center identically in both directions.
- **The checkmark glyph never flips** — `Check`/`CircleCheck` are meaning-glyphs, not directional ones.
- **The toast anchors to the inline-end corner and its stripe leads the reading edge** — `border-s-2` (never
  `border-l-2`), so the `positive` stripe sits on the reading-start edge in Arabic exactly as in English; the
  slide-in direction is derived from `dir`.
- **Action order mirrors logically** — "secondary then primary" lands with the primary on the inline-end
  edge in both directions.
- **Arabic success copy is first-class**, in IBM Plex Sans Arabic with the taller line-height; amounts and
  references inside it stay `dir="ltr"`.

# Do / Don't

| Do | Don't |
|---|---|
| Confirm with a single quiet checkmark or a calm toast | Fire confetti, particles, a bounce, or a sound on any success |
| Let the heavier action get the *quieter* feedback | Escalate fanfare with consequence — a posted entry is not a level-up |
| Make the record's changed state the durable confirmation | Rely on an ephemeral banner as the only proof an action succeeded |
| Show a completion screen's totals settled, via `fadeUp` | Count-up or odometer-roll a resulting figure as if still settling |
| Route a partial failure to a mixed-result summary | Paint a green "Import complete" over rows that failed |
| Carry at most one action ("View" *or* "Undo") in a toast | Put two competing follow-ups in one success toast |
| Announce politely (`role="status"`), glyph `aria-hidden` | Use `role="alert"`, or let green color be the only success signal |
| Offer a real next step on a completion screen | End a completed process on a dead-end trophy screen |
| Preserve the confirmation instantly under reduced motion | Drop the confirmation itself when motion is reduced |
| Write success copy as a past-tense fact ("Entry posted.") | Cheer ("Great job!", "Woohoo!") in either language |

# End of Document
