# Onboarding Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / ONBOARDING
---

# Purpose

This document is the design-system contract for QAYD's **onboarding UX patterns** — the recurring,
composed arrangements a first-run user meets between the moment a session exists and the moment their
company is operational: the guided setup checklist, the multi-step wizard, progress persistence and
resume, the empty-state-to-first-action nudge, coach-marks and tooltips used sparingly, the first-run
AI-assistant introduction, and the skippable-vs-required step distinction. A pattern here is a
*composition* built from atomic primitives — `Card`, `Button`, `Progress`, `Badge`, `Tooltip`, the
`ConfidenceBadge`/`AIProposalPanel` AI pair — not a new primitive; when a pattern needs a control, it
defers to that control's own spec rather than redrawing it.

The one behavioral authority these patterns implement against is the flow-and-screen pair that owns the
`/onboarding` route group: [`../../frontend/flows/ONBOARDING_FLOW.md`](../../frontend/flows/ONBOARDING_FLOW.md)
owns the journey (the arrows between steps, the fork, the sub-flow hand-offs, resume), and
[`../../frontend/ONBOARDING.md`](../../frontend/ONBOARDING.md) owns the screen (the eight step routes,
their components, endpoints, and states). This document does not re-derive that behavior; it names the
reusable *pattern shapes* both compose and the token, motion, and accessibility rules beneath them, and
cross-links back. Where this document is silent on a route, endpoint, or exact geometry, those two govern.

Two platform constraints bind every pattern below, restated once. First, **the frontend decides nothing a
template, rule, or permission already decides** — onboarding collects a validated choice and submits it;
Laravel owns which Chart of Accounts is IFRS-aligned, whether a template fits, and a numbering sequence's
next value. Second, **AI is visible, narrated, and never auto-commits** — every AI default is a proposal
the owner explicitly keeps or changes, rendered with its confidence and reasoning, never styled as a fact
already committed ([`../DESIGN_TOKENS.md → Color — Accent`](../DESIGN_TOKENS.md), the accent-marks-AI rule).

Every value below references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale, the single
brass `accent` family, `accent-subtle` for AI provenance, `radius-*`, the `motion.*` durations, and the
`z-sticky` band.

> **Token reconciliation.** [`../../frontend/ONBOARDING.md`](../../frontend/ONBOARDING.md) and
> [`../../frontend/flows/ONBOARDING_FLOW.md`](../../frontend/flows/ONBOARDING_FLOW.md) are pre-canonical
> app drafts that name tokens in the older `accent-600` / `ink-150` / `ink-300` / `bg-surface` scheme.
> This document is canonical and uses the [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) names: the draft's
> `accent-600` is this system's `accent`, its `ink-150` is `ink-6`, its `ink-300`/`ink-500`/`ink-700` map
> to `ink-7`/`ink-9`/`ink-10`, and `bg-surface` is an `ink-1`/`ink-3` surface. Where a screen doc's
> literal class disagrees with a token here, the token here governs and the conflict is raised in review.

# When to Use

| Pattern | Use when | Do not use when |
|---|---|---|
| Guided setup checklist | A finite, ordered set of setup tasks must be visible, resumable, and partially skippable at a glance | The work is a single record edited in one view (use a form) |
| Multi-step wizard | One long record or configuration is split into reviewable stages sharing one progress affordance | Two-or-three independent quick settings (put them on one page) |
| Progress persistence & resume | The task is *expected* to span more than one sitting and must survive a tab close or device change | A transient draft that is dangerous to leave lying around (a journal entry — that resets on route leave) |
| Empty-state-to-first-action nudge | A brand-new surface has zero rows and exactly one obvious next step | A populated surface, or one with several equally-weighted actions |
| Coach-mark / tooltip | A single non-obvious affordance needs a one-time pointer, sparingly | Explaining more than one thing, or anything a good empty state already says |
| First-run AI-assistant intro | The AI layer is introduced for the first time and must read as pedagogical, not a sales pitch | Every subsequent visit (the intro is once, then it recedes) |
| Skippable vs required steps | A wizard mixes must-complete steps with defer-for-later ones | A flow where every step is mandatory (no "Skip" affordance at all) |

The governing budget is [`../../frontend/ONBOARDING.md`](../../frontend/ONBOARDING.md)'s **ten-minute median
completion** — it is why steps beyond the required four are skippable, and why no pattern here may itself
feel like a five-minute form.

# Anatomy

The onboarding surface is the platform's **Wizard Template**: a top progress affordance, a single-decision
step body, a persistent-but-optional guidance rail, and a sticky action bar — the four regions
[`../../frontend/ONBOARDING.md → Layout & Regions`](../../frontend/ONBOARDING.md) draws in full.

```
┌──────────────────────────────────────────────────────────────────────┐
│  qayd    ●──●──●──○──○──○──○──○      [Save & exit]  [EN ▾]  [[moon]]        │ ← Progress Rail (z-sticky)
│          1  2  3  4  5  6  7  8                                        │
├─────────────────────────────────────────────┬────────────────────────┤
│  Step 3 of 8                                 │  ● Ask AI               │
│  Chart of Accounts            (display-lg)   │  "Based on your         │ ← AI Guide Dock
│  Pick a starting point…       (text-lg)      │   industry… I'd         │   (accent-subtle,
│                                              │   suggest KW_STANDARD   │    border-inline-start
│  ┌ Step Body — one Card, padding-lg ───────┐ │   + RETAIL. 92%."       │    accent, collapsible)
│  │  the step's own decision                │ │   [ Use this ]          │
│  └──────────────────────────────────────────┘ │   [ Ask something ]     │
├─────────────────────────────────────────────┴────────────────────────┤
│                                        [ Back ]  [ Skip ]  [ Continue ]│ ← Footer Action Bar (sticky)
└──────────────────────────────────────────────────────────────────────┘
```

| Part | Element | Token / rule |
|---|---|---|
| Progress Rail | `<nav>` + 8-node stepper | `z-sticky`; completed node fills `accent` with a check glyph; current is an `accent` ring; upcoming is an `ink-6` outline; skipped is `ink-6` with a diagonal glyph |
| Step Header | `display-lg` title + `text-lg` description | The route-change focus target (`# Accessibility`) |
| Step Body | one `Card` `padding-lg`, `ink-3` surface, `ink-6` hairline | Never a multi-card sectioned layout — a wizard step is one decision |
| AI Guide Dock | `AIProposalPanel` (compact) + scoped ask input | `accent-subtle` background + `border-inline-start` `accent` — the platform's AI-provenance signal |
| Footer Action Bar | Back (secondary) · Skip (ghost, steps 5–7) · Continue/Finish (primary) | Sticky bottom; primary is `disabled` never hidden, with an inline reason beside it |

The step-node glyph set — check / ring / plain outline / diagonal — is the pattern's load-bearing detail:
state is never carried by hue alone, so a grayscale render loses no information.

# Variants

## The guided setup checklist

The checklist is the Progress Rail's semantic core: an ordered list of steps, each carrying a state, a
label, and (for a blocked step) the prerequisite that blocks it. It is pure presentation over a
`steps: OnboardingStepState[]` prop and holds no query of its own.

```tsx
// components/onboarding/onboarding-progress-rail.tsx (design-system shape)
type StepState = 'completed' | 'current' | 'upcoming' | 'skipped';

interface OnboardingStep {
  key: string;
  label: string;                // i18n key
  state: StepState;
  href: string;
  blockedBy?: string;           // the prerequisite step's label, for upcoming nodes
}

export function OnboardingProgressRail({ steps }: { steps: OnboardingStep[] }) {
  const router = useRouter();
  const { t } = useTranslations('onboarding');
  return (
    <nav aria-label={t('progressNav')}>
      <ol className="flex items-center gap-2">
        {steps.map((step, i) => {
          const interactive = step.state === 'completed' || step.state === 'skipped';
          return (
            <li key={step.key} className="flex items-center gap-2">
              {interactive ? (
                <button type="button" onClick={() => router.push(step.href)}
                        className="flex items-center gap-2 rounded-full focus-visible:outline-none
                                   focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2">
                  <StepNode state={step.state} n={i + 1} />
                  <span className="hidden text-sm text-ink-9 lg:inline">{t(step.label)}</span>
                </button>
              ) : (
                <span aria-disabled={step.state === 'upcoming' || undefined}
                      aria-current={step.state === 'current' ? 'step' : undefined}
                      className="flex items-center gap-2">
                  <StepNode state={step.state} n={i + 1} />
                  <span className="hidden text-sm text-ink-9 lg:inline">{t(step.label)}</span>
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
```

A **completed** or **skipped** node is a real `<button>` that navigates directly to its step — a wizard,
unlike a strictly linear form, must let an owner return to the bank they skipped ten minutes ago. An
**upcoming** node is inert and, on hover/focus, shows a `Tooltip` naming the step that must complete first
— the one place in onboarding's own chrome that behaves like an explained disabled control, never a
silently inert one.

## The multi-step wizard

The wizard is one record, one schema, one form instance, split into steps that each validate only their
own slice before advancing — the shape [`../../frontend/components/FORMS.md → Multi-step wizard forms`](../../frontend/components/FORMS.md)
specifies. It is not several independent forms.

```tsx
// components/onboarding/company-wizard.tsx (shape — full form system in FORMS.md)
const STEPS = ['profile', 'chartOfAccounts', 'configuration', 'review'] as const;
const STEP_FIELDS: Record<(typeof STEPS)[number], Path<CompanyFormValues>[]> = {
  profile: ['legal_name', 'name_ar', 'country_code', 'currency_code'],
  chartOfAccounts: ['country_template'],
  configuration: ['numbering_pattern', 'approval_preset'],
  review: [],
};

async function next() {
  const ok = await form.trigger(STEP_FIELDS[STEPS[step]]);   // validate only this step's slice
  if (ok) setStep((s) => s + 1);
}
```

- **Step transitions** cross-fade at `motion.slow` with `easeOut`, gated by `prefers-reduced-motion` — no
  spatial slide, matching the app's "no spatial navigation metaphor" rule.
- **A step is complete only when its fields validate**, read live from `form.getFieldState` — a
  back-navigated step edited into an invalid state re-marks itself incomplete on the rail.
- **The final step is read-only review** — the owner sees exactly what will be submitted, then one primary
  action fires the single create call.

## Progress persistence & resume

Onboarding is the platform's single documented exception to "wizards do not persist." The durable copy of
"where was I" lives **server-side** in `onboarding_progress`; the client store holds only the current
step's in-flight, not-yet-saved values plus the Guide Dock's collapsed state, both reset on step
transition and rehydrated from the server `payload` on mount — with no `persist` middleware.

```tsx
// The resume pattern: server is the source of truth, the store is scratch.
// app/onboarding/page.tsx resolves current_step from GET /onboarding/progress and redirect()s to it.
// Every step debounce-saves its draft (status: 'in_progress') every 2s of inactivity.
export function ResumeBanner({ visible }: { visible: boolean }) {
  const { t } = useTranslations('onboarding');
  if (!visible) return null;
  return (
    <div role="status" className="mb-4 rounded-lg border border-ink-6 bg-ink-2 px-4 py-2 text-sm text-ink-10">
      {t('resumeBanner') /* "Welcome back — resuming where you left off." */}
    </div>
  );
}
```

The 2-second debounce-save **never announces** — announcing "Saved" while someone still types their legal
name is noise. Only the step-completing save and terminal states (import summary, invitation batch result)
use `aria-live="polite"`.

## Empty-state-to-first-action nudge

The bridge from onboarding into the operational product is the empty state: a company that skipped
optional steps lands on a `/dashboard` whose own zero-account/zero-teammate empty states carry the single
obvious next action, and Dashboard's chrome — not onboarding's — owns the later nudge.

```tsx
// The nudge pattern: one heading, one line, one primary action, nothing else.
export function FirstActionNudge({ icon: Icon, title, body, actionLabel, href }: FirstActionNudgeProps) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-ink-6 p-8 text-center">
      <Icon className="h-6 w-6 text-ink-9" aria-hidden />
      <div>
        <h3 className="font-display text-lg text-ink-11">{title}</h3>
        <p className="mt-1 text-sm text-ink-9">{body}</p>
      </div>
      <Button asChild><a href={href}>{actionLabel}</a></Button>
    </div>
  );
}
```

A nudge offers exactly **one** action. Two competing calls-to-action in an empty state is the anti-pattern
this shape exists to prevent.

## Coach-marks and tooltips, used sparingly

QAYD's default teaching surface is a good empty state and a good label, not a tour. A coach-mark is a
one-time, single-affordance pointer, dismissible, never chained into a multi-stop tour, and never shown
twice. Most steps need none — the AI Guide Dock's per-step narration replaces what a tour would carry.

```tsx
// Coach-mark: one target, one message, dismissed once and remembered server-side.
export function CoachMark({ targetRef, message, seenKey }: CoachMarkProps) {
  const [seen, markSeen] = usePreferenceFlag(seenKey);   // per-user, server-persisted, not localStorage
  if (seen) return null;
  return (
    <Popover open>
      <PopoverContentAnchoredTo ref={targetRef} className="max-w-xs bg-ink-3 border-ink-6">
        <p className="text-sm text-ink-10">{message}</p>
        <Button size="sm" variant="ghost" onClick={markSeen}>Got it</Button>
      </PopoverContentAnchoredTo>
    </Popover>
  );
}
```

## First-run AI-assistant intro

There is **no onboarding mascot.** The roster narrates itself, one specialist at a time: each step's Guide
Dock message is attributed to the real agent who owns that domain in production — `GENERAL_ACCOUNTANT` on
Chart of Accounts, `TAX_ADVISOR` on the tax field, `APPROVAL_ASSISTANT` on Numbering & Approval,
`TREASURY_MANAGER` on Connect Bank, `DOCUMENT_AI`/`OCR_AGENT` on Import, and `CFO` introducing the roster
on the final step — so "meet your AI team" is a reunion with five specialists already met, not eight
strangers plus seven more.

```tsx
// The intro pattern: an attributed message + a confidence-scored, pre-filling (never committing) suggestion.
export function AiGuideDock({ agentCode, message, suggestion }: AiGuideDockProps) {
  return (
    <aside className="rounded-lg border-s-2 border-s-accent bg-accent-subtle/40 p-4">
      <div className="flex items-start gap-3">
        <AgentAvatar agentCode={agentCode} />
        <div className="min-w-0 flex-1 space-y-2">
          <p className="text-sm text-ink-11">{message}</p>
          {suggestion && (
            <div className="space-y-2">
              <ConfidenceBadge confidence={suggestion.confidence} reasoning={suggestion.reasoning} size="sm" />
              <div className="flex gap-2">
                <Button size="sm" onClick={suggestion.onPrefill}>Use this</Button>
                <Button size="sm" variant="ghost" onClick={suggestion.onDismiss}>Keep my own choice</Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </aside>
  );
}
```

The Dock is present on every step but **never load-bearing** — a user who never opens it completes
onboarding entirely from the step body's own defaults. "Use this" *pre-fills* the picker; the owner's own
explicit action (Continue / "Create my Chart of Accounts") is what commits.

## Skippable vs required steps

| Axis | Required step | Skippable step |
|---|---|---|
| Examples | company, profile, chart-of-accounts, configuration, ai-team (visit-only) | team, banking, import |
| Footer | Back · Continue | Back · **Skip for now** · Continue |
| Rail glyph on pass | check (completed) | diagonal line (skipped) |
| Blocks Finish? | Yes — `POST /onboarding/complete` validates every required step | No — Finish succeeds with them skipped |
| Reachable later | Yes | Yes — from any later step or from `/dashboard` |

"Skip for now" writes `status: 'skipped'`, not a silent omission — the skipped state is a first-class,
visible, resumable state, never a dead end.

# Composition

## The Wizard Template shell

The shell composes the four regions once, in `app/onboarding/layout.tsx`, so every step route renders
inside the same progress + guidance + action chrome.

```tsx
// components/onboarding/onboarding-shell.tsx (shape)
export function OnboardingShell({ steps, children }: OnboardingShellProps) {
  return (
    <div className="flex min-h-dvh flex-col">
      <header className="sticky top-0 z-sticky flex items-center gap-6 border-b border-ink-6 bg-ink-1/90 px-6 py-3 backdrop-blur">
        <QaydMark />
        <OnboardingProgressRail steps={steps} />
        <div className="ms-auto flex items-center gap-2">
          <SaveExitButton />
          <LocaleSwitcher />
          <ThemeToggle />
        </div>
      </header>
      <div className="mx-auto grid w-full max-w-6xl flex-1 gap-6 px-6 py-8
                      lg:grid-cols-[1fr_360px]">
        <main className="@container">{children}</main>
        <OnboardingAiGuideDock />
      </div>
      <FooterActionBar />
    </div>
  );
}
```

The Step Body uses container queries (`@container`) so a template-card grid renders two-per-row inside the
narrower body at `lg` and three-per-row once the Dock collapses at `xl`, with no viewport-breakpoint
special case for "Dock open" vs "Dock collapsed."

## A step composed end to end

Each step is a `Card` header + body + the shared footer, wired to the wizard's one form. Neither the rail,
the Dock, nor the footer knows the step's fields directly — they read shared progress and form state.

```tsx
// app/onboarding/chart-of-accounts/page.tsx (client shell — shape)
export function ChartOfAccountsStep() {
  return (
    <OnboardingStepCard
      step={3} total={8}
      title={t('coaTitle')}
      description={t('coaDescription')}
    >
      <ChartOfAccountsTemplatePicker />   {/* the step's one decision; preview + apply owned by ONBOARDING.md */}
    </OnboardingStepCard>
  );
}
```

# States & Behavior

| State | Rail | Step body | Footer |
|---|---|---|---|
| Resume | Reflects last-saved states | Resume banner above rehydrated content, dismissed on first interaction | Continue enabled per validity |
| Step in progress | Current node is an `accent` ring | Draft debounce-saves every 2s, silently | Continue `disabled` with inline reason until required fields validate |
| Step completed | Node fills `accent` + check | — | — |
| Skippable step, skipped | Node shows the diagonal glyph | — | Skip writes `status: 'skipped'` |
| Blocked step tapped | Upcoming node shows a `Tooltip` naming the blocker | No navigation occurs | — |
| Consequential write (apply-template) | — | Determinate "Creating your Chart of Accounts…" (`Button` `loading`), targets <5s | Primary shows `loading`, other buttons disable |
| Finish with a required step incomplete | Incomplete required node emphasized | — | "Finish setup" replaced by "Complete the required steps first" |
| Error | — | Inline retry card; the picker's own selections preserved, never reset | — |

Behavioral rules that hold across every step:

- **No sensitive action is ever one click from a suggestion.** Applying a template, connecting a bank, and
  sending invitations are each the owner's own explicit, separately-labeled action — never a "Do it" button
  attached to a Dock suggestion. Every agent runs `suggest_only` for the whole flow.
- **A documented confidence floor, stated not papered over.** Below a 60% confidence floor,
  `GENERAL_ACCOUNTANT` offers no template suggestion and the Dock says so plainly ("I don't have a
  confident recommendation for a Non-Profit yet — pick whichever is closest") rather than forcing a
  low-confidence guess.
- **Save & exit never discards a partial step** — it flushes the draft immediately (bypassing the debounce)
  and returns to `/dashboard` if another company is operational, or a "Setup saved — come back anytime"
  interstitial otherwise.

# Content & Copy Guidance

| Surface | Guidance | Example |
|---|---|---|
| Step title | A noun phrase naming the decision, not a command | "Chart of Accounts" — not "Set up your accounts now" |
| Step description | One sentence, calm, stating what is reversible | "Pick a starting point — you can rename, reorder, or add accounts freely afterward." |
| Consequential primary action | Name the outcome, not "Continue" | "Create my Chart of Accounts" |
| Confirmation above a write | State what happens and that it is recoverable | "This will create 214 accounts under KW_STANDARD + RETAIL — you can rename or add accounts afterward." |
| Skip affordance | "Skip for now" — the "for now" signals resumability | Never "Skip" alone, which reads as permanent |
| Resume banner | Warm, brief, one-time | "Welcome back — resuming where you left off." |
| AI suggestion | First person, attributed, confidence stated | "Based on your industry (Retail) and country (Kuwait), I'd suggest KW_STANDARD + RETAIL — 92% confidence." |
| Blocked-step tooltip | Name the prerequisite | "Complete Numbering & Approval first." |
| Error | State what happened and the recovery, never a code | "Your Chart of Accounts already has activity — manage individual accounts from Settings instead." |

The AI intro is a **roster, not a pitch** — step 8 shows each agent's name, one-line mandate, and an
autonomy chip, never a marketing restatement of "AI-powered." Arabic copy is authored in professional
Gulf-business register by a fluent writer, not machine-translated ([`../../frontend/ONBOARDING.md → RTL & Localization`](../../frontend/ONBOARDING.md)
carries the full EN/AR table).

# Accessibility

The through-line is **progress legibility and focus continuity across eight route changes** — this is the
first sustained keyboard-and-screen-reader experience a new owner has.

| Concern | Contract |
|---|---|
| Progress Rail as a landmark | `<nav aria-label="Onboarding progress">` wrapping an `<ol>`; a `<button>` when completed/skipped, an `aria-disabled="true"` element when upcoming — the accessible affordance and the pointer affordance never disagree |
| Current step | Carries `aria-current="step"`, not merely a visual ring |
| Step-state names | Each item's accessible name states position *and* state: "Step 3, Chart of Accounts, current" / "Step 7, Import, upcoming, requires Step 4 first" |
| Focus on transition | Continue / Back / a rail node / Skip all navigate to a real route (never an in-place swap); focus lands on the step's `<h1>` |
| Debounce-save silence | The 2s draft save never announces; only step-completing saves and terminal states use `aria-live="polite"` |
| Redirect round-trip | The Open Banking return is announced with an immediately-focused heading ("Connection successful — {bank} account added" / "Connection wasn't completed"), never a silent restore |
| Color independence | Completed/current/upcoming/skipped each pair with a distinct glyph (check / ring / outline / diagonal), so a grayscale screenshot loses nothing |
| Required fields | Stated in text ("Legal Name (required)") in the accessible name, the asterisk a sighted-only reinforcement |
| Keyboard path | Rail → Step Header → step fields in visual order → Dock suggestion actions and ask input → footer (Back → Skip → Continue); no control is mouse-only |

All of the above sit on the WCAG 2.1 AA floor. Under `prefers-reduced-motion`, step cross-fades and the
Dock's "thinking" dots collapse to instant state changes.

# Theming, Dark Mode & RTL

- **Onboarding introduces no new token.** Every surface resolves through the same
  [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) remap: the completed-node fill is `accent` (lighter, not more
  saturated, in dark mode), the current-node ring is `accent` at lower opacity, and upcoming/skipped nodes
  are `ink-6`/`ink-7` — never a bespoke "onboarding blue."
- **The AI Guide Dock uses the standard AI-provenance treatment** — `accent-subtle` background plus a
  `border-inline-start` `accent` mark — so an owner who later sees that exact language on the AI Command
  Center recognizes it as the same signal.
- **Elevation lightens.** A Step Body card sits one step lighter than the canvas behind the rail
  (`ink-3` on `ink-1`), matching the platform's physical-light dark strategy.
- **The Progress Rail mirrors for free** under `dir="rtl"` — built with `gap-*`/`ms-*`/`me-*`, node 1
  renders at the visual right edge and the progression reads right-to-left, with zero onboarding-specific
  RTL code, the same way the sidebar mirrors.
- **Numerals, currency, and IBANs never mirror.** Every `AmountCell` preview, the "Step 3 of 8" counter,
  and every IBAN render inside a `dir="ltr"` span with `latn` numerals — a transposed IBAN digit is a wrong
  bank account, not a cosmetic bug.
- **The account-tree preview is always bilingual** — `code · name_en · name_ar` regardless of interface
  language, so a bilingual bookkeeper verifies both names before committing.

# Do / Don't

| Do | Don't |
|---|---|
| Make the checklist a real `<nav>`/`<ol>` landmark | Render a row of decorative dots with no semantics |
| Persist resume state server-side; keep the store scratch | Rely on `localStorage` for "where was I" |
| Attribute each Dock message to the real specialist agent | Invent a sixteenth "Setup Assistant" mascot |
| Pre-fill an AI suggestion; let the owner's click commit | Auto-apply a template on the suggestion's render |
| Label a consequential action by its outcome | Ship a bare "Continue" over a several-hundred-row write |
| Keep "Skip for now" visible and the skipped state resumable | Hide skippable steps or make skip silent |
| Use a coach-mark once, for one affordance, dismissed and remembered | Chain coach-marks into a multi-stop product tour |
| Announce the Open Banking round-trip on return | Silently restore the pre-redirect view |
| Pair every step-state with a distinct glyph | Distinguish rail states by hue alone |
| Keep `accent` to the primary action, the AI Dock, and completed nodes | Use accent as decoration on an ordinary step card |

# End of Document
