# Timeline — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TIMELINE
---

# Purpose

`Timeline` is QAYD's primitive for a **vertical, chronological activity feed** — an ordered sequence of
events, each with a timestamp, an actor, an icon, and a description, joined by a connector rail. It is the
component behind a record's inline history (a journal entry moving `Draft → Submitted → Approved → Posted`),
an approval chain's progress, an AI agent's activity log, and any "what happened, by whom, and when" surface
that belongs *beside the record it describes*.

It draws one important boundary — with the full **Audit Log** screen
([`../../frontend/AUDIT_LOG.md`](../../frontend/AUDIT_LOG.md)): that is the tenant-wide, filterable,
paginated table of every mutation, built for investigation and export. `Timeline` is the **compact,
record-scoped** rendering of the same underlying `audit_logs` / activity data — the last N events on *this*
entry, shown inline on its detail view. They read the same data model; `Timeline` is the narrative
presentation, the Audit Log is the forensic table. This spec is the atomic presentation primitive; it does
not re-specify the audit data model, retention, or filtering.

Because it renders history someone may be audited on, the primitive is austere: neutral ink carries the
feed, a state-changing event may tint **only its node marker** (paired with a glyph), timestamps are precise
and bilingual, and nothing is ever conveyed by color alone.

# Anatomy

```
   ┌── group header (optional): "Today" / "14 July 2026"  (ink-9, text-xs, uppercase)
   │
   ●───┐                                                   ◄─ node marker: icon in a ringed dot
   │   │  Sara Al-Fulan  posted the entry        3:45 PM  ◄─ actor + action + timestamp
   │   │  JE-2026-00214 · KWD 1,250.000                   ◄─ optional meta / detail line
   │   │
   ●   │  Accountant Agent  suggested a reclass    2:10 PM ◄─ AI event: accent node + Sparkles
   │   │  92% confidence — review before posting
   │   │
   ○   │  Fahad Nasser  submitted for approval     9:02 AM
   │   │
   (connector rail — ink-6 — runs on the inline-start edge, behind the nodes)
```

1. **Rail** — a 1px `ink-6` vertical line on the inline-start edge, running behind the node markers, joining
   consecutive events. It is decorative (`aria-hidden`). The segment leading to a still-pending future event
   is drawn dashed; segments between past events are solid.
2. **Node marker** — a `radius-full` dot (or a small ringed circle holding a Lucide event glyph) centered on
   the rail. Neutral (`ink-6` ring / `ink-9` glyph) by default; a state-changing event tints the marker
   (`positive` posted, `negative` voided, `warning` flagged) *and* shows the matching glyph. An AI event uses
   an `accent-subtle` node + `Sparkles`.
3. **Content** — the event body: an **actor** (name, optionally an [`./AVATAR.md`](./AVATAR.md) at `2xs`), an
   **action** phrase, a **timestamp**, and an optional **meta/detail** line (a record ref, an amount, a
   short before→after diff).
4. **Group header (optional)** — a sticky-capable day divider ("Today", "Yesterday", an absolute date) when
   `groupByDay` is on.
5. **Terminal cap** — the rail stops at the last event; an in-progress flow may render a final hollow node
   ("awaiting approval") with a dashed inbound segment.

# Variants

| Variant | Node | Use |
|---|---|---|
| `default` | Icon-in-ringed-dot | Record history where each event *type* has a glyph (post, void, edit, comment) |
| `compact` | 8px plain dot | Dense sidebars where glyphs would crowd — the action text carries the type |
| `with-avatars` | Actor `Avatar` as the node | People-centric feeds (comments, mentions) where *who* leads over *what* |
| `grouped` | any of the above + day headers | Long feeds spanning multiple days |

Orthogonal options: `dense` (tighter vertical rhythm for long lists) and `condensed` event bodies (single
line, timestamp inline) vs. expanded (meta/detail on its own line).

## Event tone

Events are neutral ink by default. A tone tints **only the node marker**, never the row background or the
whole line — the same restraint as `StatusPill`'s leading dot:

| Tone | Node | When | Non-color signal |
|---|---|---|---|
| `neutral` (default) | `ink-6` ring / `ink-9` glyph | Most events (edited, commented, submitted, viewed) | The action verb |
| `positive` | `bg-positive` | A committing success (posted, reconciled, approved) | `CircleCheck` glyph + verb |
| `negative` | `bg-negative` | A reversal/removal (voided, rejected, deleted) | `CircleX` glyph + verb |
| `warning` | `bg-warning` | A flagged / pending-attention event | `TriangleAlert` glyph + verb |
| `ai` | `bg-accent-subtle` / `text-accent` | An AI agent produced the event | `Sparkles` glyph + agent name |

Tone never washes the event text — the description stays `ink-11`/`ink-9` so the feed reads as a document,
not a status board.

# Props / API

```tsx
// components/ui/timeline.tsx
interface TimelineEvent {
  id: string | number;
  actor: { name: string; avatarUrl?: string } | { agentCode: AgentCode };
  timestamp: string;          // ISO 8601 (UTC); formatted client-side per locale
  tone?: 'neutral' | 'positive' | 'negative' | 'warning' | 'ai';
  icon?: LucideIcon;          // overrides the tone-derived glyph
  title: string;              // the action phrase, already localized
  description?: string;       // optional meta/detail line
  href?: string;              // drill-through to the affected record
}

interface TimelineProps {
  events: TimelineEvent[];    // newest-first or oldest-first — set by `order`
  order?: 'asc' | 'desc';     // default 'desc' (newest first)
  variant?: 'default' | 'compact' | 'with-avatars';
  groupByDay?: boolean;
  dense?: boolean;
  locale?: 'en' | 'ar';       // defaults to the app locale
  renderEvent?: (e: TimelineEvent) => React.ReactNode; // escape hatch for a custom body
}
```

| Prop | Type | Required | Description |
|---|---|---|---|
| `events` | `TimelineEvent[]` | yes | The ordered event list; each item is one node. |
| `order` | `'asc' \| 'desc'` | no (default `desc`) | Chronological direction; the DOM order matches so screen readers read it chronologically. |
| `variant` | `'default' \| 'compact' \| 'with-avatars'` | no (default `default`) | Node style. |
| `groupByDay` | `boolean` | no | Inserts localized day headers between date changes. |
| `dense` | `boolean` | no | Tighter vertical spacing for long feeds. |
| `locale` | `'en' \| 'ar'` | no | Overrides the app locale for date formatting (rare). |
| `renderEvent` | `(e) => ReactNode` | no | Replaces the default body while keeping the rail/node chrome. |

# States

| State | Treatment |
|---|---|
| Loading | 3–5 skeleton rows (a pulsing node + two text bars), reduced-motion-safe; the rail is drawn but greyed |
| Empty | An `EMPTY_STATES` inline message ("No activity yet") with the rail absent — never a bare rail with no events |
| Live append (realtime) | A newly-arrived event fades/slides in over `motion.fast` at the feed head and is announced via a polite live region; reduced motion shows it instantly |
| Pending terminal event | A final hollow node with a dashed inbound rail segment ("Awaiting approval") — visibly *not* a completed step |
| Interactive event | An event with `href` renders its title as a real `<a>`; hover lifts the title to `ink-12`, focus shows the `accent` ring |
| Truncated feed | A trailing "View full history" link routes to the Audit Log screen filtered to this record |

The primitive never renders an error itself; a failed history fetch is handled by the screen's error state.

# Timestamps & EN/AR Date Formatting

Timestamps are the one place a bilingual activity feed most often goes wrong, so the rule is explicit.

- **Relative by default, absolute on demand.** Recent events read as a localized relative phrase — "2 hours
  ago" / "منذ ساعتين" — with the full absolute timestamp on the `<time>` element's `title` and `datetime`
  attributes. Past a threshold (~24h) the visible label switches to an absolute localized date-time.
- **Latin (western) numerals, always.** Arabic locale formatting keeps **`latn`** digits, matching the
  platform's numeral convention and the `<Amount>` primitive's `dir="ltr"` digit protection
  ([`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) Typography, `../../frontend/DESIGN_LANGUAGE.md`): an Arabic
  timestamp reads `14 يوليو 2026، 3:45 م` — Arabic month name and meridiem, Latin digits — never Eastern-
  Arabic `٤` numerals. This is a deliberate finance-product choice: an accountant cross-reads dates against
  ledgers and statements where digits are Latin.
- **One formatter, locale-driven.** All formatting goes through a single helper so no event hand-builds a
  date string:

```tsx
// lib/format/event-time.ts
export function formatEventTime(iso: string, locale: 'en' | 'ar', tz: string) {
  const d = new Date(iso);
  const abs = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: tz,
    numberingSystem: 'latn', // ← Latin digits in Arabic too
  }).format(d);
  const rel = relativeTime(d, locale); // "2 hours ago" / "منذ ساعتين", also numberingSystem: 'latn'
  return { abs, rel };
}
```

- **The `<time>` element carries the machine value.** Every visible timestamp is a
  `<time dateTime={iso} title={abs}>{rel}</time>`, so the semantic ISO value is always present regardless of
  the display format.
- **Day-group headers** use the same formatter: "Today" / "اليوم", "Yesterday" / "أمس", else a `latn`-digit
  medium date.

# Tokens Used

| Role | Token | Utility |
|---|---|---|
| Rail / connector | `ink-6` | `bg-ink-6` (dashed for pending: `border-dashed border-ink-6`) |
| Node ring (neutral) | `ink-6` | `ring-ink-6` |
| Node glyph (neutral) | `ink-9` | `text-ink-9` |
| Node fill inset | `ink-1` (canvas, so the node reads off the rail) | `bg-ink-1` |
| Actor / title text | `ink-11` | `text-ink-11` |
| Meta / timestamp text | `ink-9` | `text-ink-9` |
| Title hover (interactive) | `ink-12` | `text-ink-12` |
| Positive / negative / warning node | `positive` / `negative` / `warning` | `bg-positive` etc. |
| AI node fill / glyph | `accent-subtle` / `accent` | `bg-accent-subtle` / `text-accent` |
| Day-header text | `ink-9`, `text-xs` uppercase | `text-ink-9 text-xs uppercase tracking-wide` |
| Focus ring | `accent` | `ring-accent` |
| Node shape | `radius-full` | `rounded-full` |
| Append / entrance motion | `motion.fast` (160ms), `easeOut` | via `lib/motion.ts` |

All values resolve to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); event glyphs follow
[`../ICON_SYSTEM.md`](../ICON_SYSTEM.md) (one Lucide set, `sm`/`xs`, `1.75` stroke, `currentColor`).

# Accessibility

- **Ordered-list semantics.** The feed is an `<ol>` (or `role="list"`) and each event an `<li>`
  (`role="listitem"`), so assistive tech announces "list, N items" and reads them in order; the DOM order
  matches `order` so the reading order is genuinely chronological.
- **The rail is decorative.** The connector line and node dots are `aria-hidden`; the event's meaning lives
  entirely in its actor, action, timestamp, and glyph text — a screen reader never depends on the rail.
- **State is never color-only.** A posted/voided/flagged/AI event is distinguished by its **glyph and its
  action verb** (`CircleCheck` + "posted", `CircleX` + "voided", `Sparkles` + agent name), not by the node's
  tint (WCAG 1.4.1) — the feed is fully legible in grayscale and to colorblind users.
- **Timestamps are machine-readable.** Each is a `<time dateTime>` with the absolute value in `title`, so the
  precise instant is available even when the visible label is relative.
- **Interactive events are real links.** An event with `href` is an `<a>`, keyboard-reachable, with a visible
  `accent` focus ring at non-zero offset; the whole title is the target, not a bare icon.
- **Live updates are polite, not assertive.** Realtime appends announce via `aria-live="polite"` so an
  incoming event does not interrupt a screen-reader user mid-sentence.
- **Contrast.** `ink-11` titles and `ink-9` meta on the `ink-1`/`ink-2` canvas clear AA in both themes; node
  tints are reinforcement, and their glyphs clear the 3:1 non-text floor.
- **Reduced motion.** Entrance/append animations collapse to instant under `prefers-reduced-motion`.

# Theming, Dark Mode & RTL

**Dark mode** re-points tokens: the `ink-6` rail → `#46402F`, `ink-11` titles → `#E4DEC9`, `accent-subtle`
AI node → `#3A2E14`. The same classes render correctly in both themes with no branch; tints use the authored
dark semantic values so a `positive` node reads as `#4ADE94` without glowing.

**RTL — the rail side is the load-bearing mirror:**

- The connector rail and node markers sit on the **inline-start** edge, so in English they run down the left
  and in Arabic down the **right**, automatically — the layout uses logical `ps-*`/`ms-*` and `start-0`
  insets, never a hardcoded `left-*`. Getting this wrong (a rail stuck on the left in Arabic) is the classic
  "untranslated software" tell this policy prevents.
- The vertical reading axis (top → bottom, newest-first) is **unchanged** by direction — time flows
  downward in both languages; only the rail's horizontal side and the text alignment mirror.
- Node **glyphs are symmetric** (`CircleCheck`, `CircleX`, `TriangleAlert`, `Sparkles`) and do **not** mirror
  ([`../ICON_SYSTEM.md`](../ICON_SYSTEM.md) — status/AI glyphs never flip); a directional drill-through
  chevron on an event *does* mirror via `mirrorRtl`.
- Actor + timestamp lay out with `justify-between`, so the timestamp sits on the inline-end in both languages
  (right in English, left in Arabic).
- Timestamps keep **`latn` digits** in Arabic (see [Timestamps](#timestamps--enar-date-formatting)); the
  visible date string is bidi-isolated so the Latin digits and Arabic month name compose cleanly.

# Do / Don't

| Do | Don't |
|---|---|
| Use `Timeline` for a record-scoped, chronological history | Rebuild the tenant-wide filterable Audit Log table here |
| Keep the feed neutral ink; tint only the node marker | Wash a whole event row red/green by state |
| Distinguish event type by glyph + action verb | Rely on the node's color alone to mean "posted" vs "voided" |
| Render every timestamp as a `<time dateTime>` with an absolute `title` | Show a bare relative string with no machine value |
| Keep Arabic timestamps in `latn` digits via one formatter | Emit Eastern-Arabic `٤` numerals, or hand-build date strings per event |
| Put the rail on the inline-start edge with logical `start-*`/`ps-*` | Hardcode `left-*` so the rail stays left in Arabic |
| Mark the feed up as an ordered `<ol>` with `aria-hidden` rail | Convey order only visually with a decorative line |
| Link an actionable event as a real `<a>` and announce appends politely | Make a node icon the only clickable/announceable target |

# Usage & Composition

```tsx
// Inline history on a journal-entry detail — grouped, with a link to the full Audit Log
<Timeline
  events={history}          // mapped from audit_logs scoped to this JE
  order="desc"
  groupByDay
  variant="default"
/>
<Link href={auditLogHref({ record: 'journal_entry', id: entry.id })} className="text-sm text-accent">
  {t('history.viewFull')}
</Link>
```

```tsx
// A mixed human + AI approval feed — AI events use the `ai` tone (accent node + Sparkles)
<Timeline
  variant="with-avatars"
  events={[
    { id: 4, actor: { name: 'Sara Al-Fulan' }, tone: 'positive',
      timestamp: '2026-07-14T12:45:00Z', title: t('event.posted'), href: jeHref },
    { id: 3, actor: { agentCode: 'GENERAL_ACCOUNTANT' }, tone: 'ai',
      timestamp: '2026-07-14T11:10:00Z', title: t('event.aiSuggestedReclass'),
      description: t('ai.reviewBeforePosting') },
    { id: 2, actor: { name: 'Fahad Nasser' },
      timestamp: '2026-07-14T06:02:00Z', title: t('event.submitted') },
  ]}
/>
// An `ai`-tone event's actor resolves through AgentAvatar / AgentAttributionChip from
// ../../frontend/components/AI_WIDGETS.md, keeping AI provenance consistent with the rest of the app.
```

`Timeline` composes onto record-detail views, `ApprovalCard` chains, and AI activity panels. For the
tenant-wide forensic view use the Audit Log screen
([`../../frontend/AUDIT_LOG.md`](../../frontend/AUDIT_LOG.md)); for actor marks it composes
[`./AVATAR.md`](./AVATAR.md) (people) and `AgentAvatar`
([`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md)) (agents). Sibling
primitives: [`./TAG.md`](./TAG.md), [`./AVATAR.md`](./AVATAR.md), [`./PROGRESS.md`](./PROGRESS.md).

# End of Document
