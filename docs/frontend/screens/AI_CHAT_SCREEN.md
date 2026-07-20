# AI Chat Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Screens / AI_CHAT_SCREEN
---

# Purpose

This document is the concrete, implementation-ready screen specification for QAYD's conversational AI
assistant — the single message thread onto the entire fifteen-agent AI workforce, fronted end to end by the
CEO Assistant orchestrator. It is the structured, editor-beside-you companion to `docs/frontend/AI_CHAT.md`,
which specifies this surface in fuller prose — the argument that a human never selects a specialist agent, the
orchestration contract it renders (never re-derives), the two renderings of one surface (full page + docked),
the streaming/citation/proposal machinery, conversation persistence, and the behavior across breakpoints,
direction, theme, and assistive technology. This document does not re-argue any of that; the two must never
disagree. Where this document is silent on a fact, `docs/frontend/AI_CHAT.md` governs first, then
`docs/ai/agents/CEO_AGENT.md` (authoritative for *what the AI is allowed to think and do*),
`docs/frontend/AI_COMMAND_CENTER.md`, then `FRONTEND_ARCHITECTURE.md`,
`docs/frontend/components/AI_WIDGETS.md`, `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `LAYOUT_SYSTEM.md`,
`RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md`, in that order. Where this document appears to
contradict one of them on a route, a permission key, an endpoint, a component name, or a token, that is a
defect to raise and resolve in review, never a decision an engineer resolves unilaterally in code.

**A note on the route.** The task that commissioned this screen names its path `app/(app)/ai/chat`. That path
is retained, but only as a **permanent redirect** to the product's canonical route, `app/(app)/assistant`,
exactly as `docs/frontend/AI_CHAT.md → A note on the route` fixes it — the single, always-reachable front door
to the whole AI workforce is `/assistant`, and `/ai/chat` is a leaf-name predating that decision. New code,
new links, and this document read `/assistant`; a hit on `/ai/chat` lands correctly via the redirect below.
This is the same precedent `docs/frontend/AI_COMMAND_CENTER.md` set for `/dashboard` → `/command-center`.

```ts
// next.config.ts (excerpt) — reproduced verbatim from docs/frontend/AI_CHAT.md
async redirects() {
  return [
    { source: "/ai/chat", destination: "/assistant", permanent: true },
    { source: "/dashboard", destination: "/command-center", permanent: true },
  ];
},
```

What this document adds beyond `docs/frontend/AI_CHAT.md` is the layer an engineer opens beside their editor:
the literal `layout.tsx` history-rail chrome, the full prop contracts and TSX for the ten chat-scoped
components `docs/frontend/AI_CHAT.md` names as **(new)** but does not show source for (`ChatMessageBubble`,
`ToolCallTrace`, `CitationChip`, `ChatComposer`, `SuggestedPromptChips`, `VoiceInputButton`,
`ConversationHistoryList`, `AgentAttributionRow`, `ChatMessageList`), worked SSE-frame and assistant-payload
JSON an engineer can paste into a fixture, the fixed `ChatMessageBubble` slot order in code, a region-by-state
matrix, concrete `grid`/`max-w` values, the conversation-management mutation strategy, and the streaming
announce policy and keyboard bindings that `docs/frontend/AI_CHAT.md` names as decisions but does not spell out
to the last detail. `docs/frontend/AI_CHAT.md` already shows `app/(app)/assistant/[conversationId]/page.tsx`,
`assistant-view.tsx`, and the SSE proxy `route.ts` in full — this document cites those, never re-derives them.

Concretely, this screen composes four pieces of surface, each owned elsewhere and only rendered here — never
computed:

- **A collapsible conversation-history rail** — the signed-in user's own past threads, date-grouped,
  cursor-paginated. Personal, never a company-wide shared inbox.
- **The active transcript** — a virtualized, auto-scrolling message list where the human's own turns are plain
  and every assistant turn renders through the mandatory `AiCardShell` envelope, streamed token-by-token over
  SSE.
- **The composer** — an auto-growing textarea, send, stop, and a mic button; pinned to the transcript bottom.
- **Nothing else.** The AI Rail that appears on every *other* screen is redundant here — the entire page is
  the AI surface — so this screen is a deliberate two-region layout, never a three-column one.

This screen computes nothing. It never selects a specialist agent (routing is the orchestrator's job at
request time), never averages a confidence, never auto-commits a proposal, and never surfaces data the user
lacks permission for (bounded entirely by the intersection of their role's permissions against whatever tool
calls the orchestrator would need). Every reply's `content`, `confidence`, `reasoning`, and `sources` are the
FastAPI layer's own output, rendered under the platform's fixed AI contract.

# Route & Access

## App Router path

```text
app/(app)/assistant/
├── layout.tsx                        # * Shared history-rail chrome for both children (this document)
├── page.tsx                          # Fresh/unsaved composer — no conversation_id yet (docs/frontend/AI_CHAT.md)
└── [conversationId]/
    └── page.tsx                      # Resumes a saved thread — prefetches its message page (docs/frontend/AI_CHAT.md)
```

`docs/frontend/AI_CHAT.md → Route & Access` is authoritative for `page.tsx` and `[conversationId]/page.tsx`
(both Server Components resolving the session and the history rail's first page server-side, with the live
transcript owned by a client subtree once hydrated); this document owns the shared `layout.tsx` and the new
component source below. There is **no per-agent sub-route** and never will be —
`app/(app)/assistant/[agentCode]/page.tsx` is not a route this product defines, by design, because routing to
a specialist is the CEO Assistant's job at request time, never a URL a human constructs. A `?highlight=` or
`?context=` query parameter carries seed context from a deep link; it is never a way to address a different
agent directly.

```tsx
// app/(app)/assistant/layout.tsx — shared history-rail chrome for the fresh and resumed children
import { Suspense } from "react";
import { ConversationHistoryList } from "@/components/ai/conversation-history-list";
import { ChatSkeleton } from "@/components/ai/chat-skeleton";

export default function AssistantLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid h-dvh grid-cols-1 lg:grid-cols-[280px_1fr]">
      {/* history rail: its own scroll container; collapses to 0 below lg (a Sheet overlay on md/mobile) */}
      <aside aria-label="Conversation history" className="hidden border-e border-ink-4 lg:block">
        <Suspense fallback={<ChatSkeleton part="history" />}>
          <ConversationHistoryList />
        </Suspense>
      </aside>
      {children}
    </div>
  );
}
```

## Access gate

The screen's own gate is **`ai.chat` alone**, held by every non-suspended account per `CEO_AGENT.md`'s
permission table — deliberately distinct from the coarser `reports.read` gate `docs/frontend/NAVIGATION_SYSTEM.md`'s
nav tree applies to the "AI" sidebar *group* (which bundles the deeper analytical leaves — Insights,
Recommendations, Risks, Forecast — that a Sales Employee or Warehouse Employee legitimately should not see).
The Ask AI / AI Chat leaf is pinned visible to any `ai.chat` holder regardless of whether its sibling leaves
are hidden, because `CEO_AGENT.md`'s foundational premise — "a Sales Employee, a Warehouse Employee, and the
Owner can all open the same conversational surface" — would otherwise be silently violated.

| Capability on this screen | Permission | Client-side check? |
|---|---|---|
| Open `/assistant`, send messages, read replies | `ai.chat` | Yes — the route gate |
| See a reply that required multi-agent, cross-domain synthesis | `ai.ceo.query` | **No** — checked server-side per underlying tool call, never by the client |
| Request the morning briefing from within a conversation | `ai.ceo.brief` | No |
| Request a what-if simulation through chat | `ai.ceo.simulate` | No |
| Act on an inline-embedded pending approval (Approve/Reject) | `ai.approve` | **Yes** — the one and only permission this screen's components check client-side |
| Use voice input/output | `ai.chat` (device-capability-gated, not role-gated) | No |

The client never branches its rendering on `ai.ceo.query`/`ai.ceo.simulate`/`ai.ceo.brief` — those are inputs
to the orchestrator's own routing decision inside FastAPI, invisible to the composer, which looks and behaves
identically for a Warehouse Employee asking about their own stock counts and a CFO asking a company-wide cash
question. What a user can *ask about* is never separately restricted by this screen; it is bounded entirely by
the intersection of their role's permissions against whatever tool calls the orchestrator would make on their
behalf. **The assistant can never surface data the user lacks permission for** — a tool call the user's role
cannot make is simply not made, and the reply says so in plain prose rather than leaking a value.

**Personal, not shared, history.** `ai_conversations.user_id` is `NOT NULL` and scoped per user, so the
history rail shows only the signed-in user's own past threads — never a company-wide shared inbox. Mariam's
conversation about Q2 margin and Fahad's about warehouse headcount are fully independent rows, even though both
draw on the same company's ledger underneath. The CEO Assistant is a personal advisor to every seat, not a
group channel.

**Docked entry points.** From any other screen, three paths reach this same surface without a full navigation:
the AI Rail's persistent panel (`3xl`+), the AI Rail's `Sheet` overlay (below `3xl`), and the ⌘K Command
Palette's "Ask AI" action (handing off free text as `/assistant?q=…`). None is a second implementation — each
mounts the identical `AssistantView` client component (`docs/frontend/AI_CHAT.md → assistant-view.tsx`),
differing only in container and seed context.

## Roles

| Role | What renders on this screen |
|---|---|
| Every non-suspended role (Owner … Warehouse Employee, Read Only) | The identical composer, transcript, and history rail — the one screen in the product whose surface is genuinely uniform across every seat. What differs is only what the orchestrator can *answer*, bounded by that role's own tool-call permissions server-side. |
| Any role holding `ai.approve` | Additionally sees enabled Approve/Reject controls on an inline-embedded *existing* pending approval a reply surfaces (the embedded `ApprovalCard` re-checks live before rendering them). |
| A role lacking `ai.approve` | Sees the same embedded `ApprovalCard` in its read-only presentation — never an enabled button that would `403` on click. |
| AI service account | Never renders this screen as a user; it is a producer of `ai_messages`, never a consumer of the composer. |

Keyboard entry: `G` then `I` opens this screen (the "Go to AI / assistant" mnemonic); on the route, `Enter`
sends, `Shift+Enter` inserts a newline, `↑` with an empty composer edits the just-sent message, and `Esc`
closes the docked `Sheet` variant without navigating away.

# Layout & Regions

The full-page rendering is a **two-region layout** — a collapsible conversation-history rail and the active
transcript — never three columns, because the AI Rail on every other screen would be redundant here. The
desktop wireframe is reproduced from `docs/frontend/AI_CHAT.md → Layout & Regions`; the docked and mobile
forms are drawn out below.

## Desktop (`xl`+, ≥1280px), history rail expanded

```
┌───────────┬─────────────────────────────────────────────────────────────┐
│  History  │  ⌘K [Search or jump to…]              AI● [bell]  ▾ Mariam       │
│  ┌─────┐  ├─────────────────────────────────────────────────────────────┤
│  │+ New│  │   ┌──────────────────────────────────────────────────┐       │
│  └─────┘  │   │  You                                              │      │
│  ─────    │   │  Why did our KWD balance drop so much this week?  │      │  user bubble:
│  Today    │   └──────────────────────────────────────────────────┘       │  plain ink-3, no accent
│  ▸ Cash…  │   ┌──────────────────────────────────────────────────┐       │
│  ▸ Q2 mar │   │ [check] CEO Assistant · 96% confidence         [AI]     │      │  assistant bubble:
│  Yesterday│   │  The main draw was the payroll run on July 15…    │      │  AiCardShell envelope
│  ▸ Payroll│   │  ⌄ Checked Treasury Manager, Payroll Manager      │      │  (ToolCallTrace)
│  ▸ Reorder│   │  [1] bank_accounts #12   [2] payroll_runs #204    │      │  (CitationChips)
│           │   │  [ Review Payroll Run #204 → ]                    │      │  (suggested_action)
│           │   └──────────────────────────────────────────────────┘       │
│           │   Suggested: "Break this down by branch" · "Show June too"  │  SuggestedPromptChips
│           ├─────────────────────────────────────────────────────────────┤
│           │  [[mic]]  Ask anything about your business…            [Send]  │  ChatComposer, pinned
└───────────┴─────────────────────────────────────────────────────────────┘
```

## Docked (`Sheet`, below `3xl`) — opened from a panel

```
┌──────────────────────────────────────┐
│  Ask AI                          [×] │
│  Scoped to: Cash Flow Status         │  context strip (only when opened from a panel)
├───────────────────────────────────────┤
│  (identical message list, condensed  │
│   padding, no history rail — a       │
│   "View full conversation" link      │
│   opens /assistant/[conversationId]) │
├───────────────────────────────────────┤
│  [[mic]] Ask a follow-up…        [Send] │
└──────────────────────────────────────┘
```

## Region table with implementation-level classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| History rail | `hidden border-e border-ink-4 lg:block` (`w-[280px]`); a `Sheet` below `lg` | `ConversationHistoryList` — fixed "New conversation" header + date-grouped scroll list | Own scroll container; no bottom utility row (Settings live in the app-shell Sidebar) |
| Transcript | `mx-auto flex h-full w-full max-w-3xl flex-col` | `ChatMessageList` | `max-w-3xl` centered — a transcript is read top-to-bottom like a document, not scanned like a table; an overlong line length actively hurts that |
| Composer | pinned to the transcript region's bottom (not the viewport), `border-t border-ink-6 bg-surface-1 p-3` | `SuggestedPromptChips` above `ChatComposer` | Flat, hairline-bordered; no floating card |
| Docked context strip | `border-b border-ink-6 px-4 py-2 text-caption text-ink-9` | "Scoped to: {panel}" | Present only in the docked variant opened from a panel |

Every message bubble is a `Card`-derived shape at `radius-lg` with a `1px` `ink-6` border. An assistant
message additionally carries the `AiCardShell` colored-left-border-plus-badge envelope; a **user's** message
renders in plain `ink-3` fill with no border accent at all, because only AI-authored content earns the accent
border (`DESIGN_LANGUAGE.md → Design Principle 7`) — so a user scrolling a long transcript distinguishes "mine"
from "the AI's" by shape alone before reading a word.

# Components Used

Every visual element is drawn from `COMPONENT_LIBRARY.md`/`docs/frontend/AI_COMMAND_CENTER.md`'s shared AI set,
or is one of the chat-scoped **(new)** components `docs/frontend/AI_CHAT.md → Components Used` names. This
section gives the concrete prop contracts and, for the ten new components that document names without showing
source, the actual implementation. No component introduces a bespoke confidence indicator, a bespoke approval
affordance, or a bespoke citation color.

| Component | Source | New in this document |
|---|---|---|
| `AiCardShell`, `ConfidenceBadge`, `ReasoningDisclosure`, `RecommendationCard` (`AIProposalCard`), `ApprovalCard`, `Badge`, `Tooltip`, `Popover`, `Sheet`, `Skeleton`, `Avatar` | `COMPONENT_LIBRARY.md` / `components/ai/*` / `components/shared/*` (existing) | No — reused verbatim |
| `useChat` (Vercel AI SDK) | `app/(app)/assistant/*`, reused verbatim by the dock | No — the one client-side source of truth for the live transcript |
| `AssistantView` | `components/ai/assistant-view.tsx` — full source in `docs/frontend/AI_CHAT.md → Data & State` | No — cited, not reproduced |
| `ChatMessageList` | `components/ai/chat-message-list.tsx` | **Yes** — prop contract + `role="log"` policy below |
| `ChatMessageBubble` | `components/ai/chat-message-bubble.tsx` | **Yes** — full source (the fixed slot order) below |
| `ChatComposer` | `components/ai/chat-composer.tsx` | **Yes** — prop contract below |
| `ToolCallTrace` | `components/ai/tool-call-trace.tsx` | **Yes** — full source below |
| `CitationChip` | `components/ai/citation-chip.tsx` | **Yes** — full source below |
| `ConversationHistoryList` | `components/ai/conversation-history-list.tsx` | **Yes** — prop contract below |
| `SuggestedPromptChips` | `components/ai/suggested-prompt-chips.tsx` | **Yes** — prop contract below |
| `VoiceInputButton` | `components/ai/voice-input-button.tsx` | **Yes** — prop contract below |
| `AgentAttributionRow` | `components/ai/agent-attribution-row.tsx` | **Yes** — full source below |

## `ChatMessageBubble` — the fixed slot order

Renders one turn. A user turn is plain; an assistant turn dispatches to `AiCardShell` and renders a **fixed
slot order** so a dense, multi-agent answer never reads as a wall of undifferentiated text — each layer of
trust (what it says, who said it, why, what you can do) is visually and structurally distinct.

```tsx
// components/ai/chat-message-bubble.tsx
"use client";

import { memo } from "react";
import { AiCardShell } from "@/components/ai/ai-card-shell";
import { AgentAttributionRow } from "@/components/ai/agent-attribution-row";
import { ReasoningDisclosure } from "@/components/ai/reasoning-disclosure";
import { ToolCallTrace } from "@/components/ai/tool-call-trace";
import { RecommendationCard } from "@/components/ai/recommendation-card";
import { ApprovalCard } from "@/components/shared/approval-card";
import { renderWithCitations } from "@/lib/ai/render-with-citations"; // weaves CitationChips into prose at sources[] positions
import type { AiMessage } from "@/types/ai";

function ChatMessageBubbleImpl({ message, streaming }: { message: AiMessage; streaming?: boolean }) {
  if (message.role === "user") {
    return (
      <article aria-label={`Your message: ${message.content.slice(0, 48)}`} className="ms-auto max-w-[85%] rounded-lg bg-ink-3 px-4 py-3">
        <p dir="auto" className="text-body text-ink-12">{message.content}</p>
      </article>
    );
  }

  // assistant turn — the fixed slot order:
  return (
    <article aria-label={`CEO Assistant's reply: ${message.content.slice(0, 48)}`} className="max-w-[85%]">
      <AiCardShell decision={message as never} severity="none">
        {/* 1) prose, with CitationChips woven in at each sources[] position */}
        <div dir="auto" className="text-body text-ink-12">{renderWithCitations(message.content, message.sources)}</div>
        {/* 2) agent attribution byline — only when a fan-out actually happened */}
        {message.routed_agents.length > 0 && <AgentAttributionRow routedAgents={message.routed_agents} />}
        {/* 3) the tool-use trace — collapsed by default, auto-expanded on disagreement (# AI Integration) */}
        {message.routed_agents.length > 0 && <ToolCallTrace routedAgents={message.routed_agents} disagreement={message.disagreement ?? null} />}
        {/* 4) reasoning disclosure — collapsed; ConfidenceBadge + AI badge already live in AiCardShell's header */}
        {(message.reasoning || message.sources.length > 0) && <ReasoningDisclosure reasoning={message.reasoning} sources={message.sources} />}
        {/* 5) actions — a graduated recommendation, or an inline existing approval; only once streaming settles */}
        {!streaming && message.suggested_actions?.some((a) => a.action !== "navigate") && (
          <RecommendationCard decision={message as never} {...message.actionHandlers} />
        )}
        {!streaming && message.embedded_approval_id != null && (
          <ApprovalCard kind="ai_recommendation" approvalId={message.embedded_approval_id} refetchBeforeAction />
        )}
      </AiCardShell>
    </article>
  );
}

// memo'd so a 40-message conversation never re-evaluates 39 settled bubbles when the 40th receives a token.
export const ChatMessageBubble = memo(ChatMessageBubbleImpl);
```

The confidence badge, citations, and any actions do **not** render until the stream's terminal frame arrives
(`streaming` is `false`) — a message is not "final" mid-stream. When the model genuinely cannot answer
confidently, the bubble renders as plain prose with **no** `ConfidenceBadge` at all (the failure is "no
answer," not "a low-confidence answer") plus a plain-language caveat.

## `ToolCallTrace`

The collapsible "Checked Treasury Manager, Payroll Manager" disclosure — tool-use transparency. Collapsed by
default; **auto-expanded (not collapsed) on disagreement**, showing a compact agent/claim/confidence
comparison, per `# AI Integration`.

```tsx
// components/ai/tool-call-trace.tsx
"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { Icon } from "@/components/ui/icon";
import { AgentAttributionChip } from "@/components/ai/agent-attribution-chip";
import { normalizeConfidence } from "@/components/ai/confidence-badge";
import { useTranslations } from "@/lib/i18n";
import type { RoutedAgent, Disagreement } from "@/types/ai";

export function ToolCallTrace({ routedAgents, disagreement }: { routedAgents: RoutedAgent[]; disagreement: Disagreement | null }) {
  const { t } = useTranslations("ai");
  const [open, setOpen] = useState(disagreement != null); // conflict opens the trace by default
  return (
    <div className="mt-2">
      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open}
              className="flex items-center gap-1 text-caption text-ink-9 hover:text-ink-11">
        <Icon icon={ChevronDown} size="xs" className={open ? "rotate-180" : ""} aria-hidden />
        {t("assistant.showWork")}
      </button>
      {open && (
        <div className="mt-2 space-y-2">
          {routedAgents.map((a) => (
            <div key={a.task_id} className="flex items-center justify-between">
              <AgentAttributionChip agentCode={a.agent_code} confidence={normalizeConfidence(a.confidence, "percentage")} />
              {a.toolDescription && <span className="text-caption text-ink-9">{a.toolDescription}</span>}
            </div>
          ))}
          {disagreement && (
            // a two-row compare: agent · claim · confidence — the disagreement is named, never averaged away
            <table className="w-full text-caption">
              <tbody>
                {disagreement.claims.map((c) => (
                  <tr key={c.agent_code}>
                    <th scope="row" className="text-start font-normal text-ink-9">{t(`agent.${c.agent_code}`)}</th>
                    <td className="text-ink-11">{c.claim}</td>
                    <td dir="ltr" className="text-end font-mono tabular-nums text-ink-9">{Math.round(c.confidence)}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
```

## `CitationChip`

A numbered inline marker (`[1]`, `[2]`) inserted into the assistant's prose at the position `sources[]`
indicates, each a `Popover` trigger (hover on desktop, tap on touch) showing the source's `type`, `label`, and
a "View" link. A `type: "document"` opens the document's own viewer in a `Sheet` rather than navigating away.
Citations **never** point at an `ai_memory` row — remembered context is disclosed in prose, never as a
clickable citation.

```tsx
// components/ai/citation-chip.tsx
"use client";

import Link from "next/link";
import { memo } from "react";
import { Popover, PopoverTrigger, PopoverContent } from "@/components/ui/popover";
import { sourceHref } from "@/lib/ai/source-href";
import { useTranslations } from "@/lib/i18n";
import type { AiSource } from "@/types/ai";

function CitationChipImpl({ index, source }: { index: number; source: AiSource }) {
  const { t } = useTranslations("ai");
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button type="button" aria-label={t("citation.aria", { index, label: source.label })}
                className="mx-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded bg-accent-subtle px-1 align-super text-[10px] font-medium text-accent">
          <span dir="ltr" className="tabular-nums">{index}</span>
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-72 space-y-1 text-caption">
        <p className="text-ink-9">{t(`source.type.${source.type}`)}</p>
        <p dir="auto" className="font-medium text-ink-12">{source.label}</p>
        {source.id != null && (
          <Link href={sourceHref(source)} className="text-accent hover:underline">{t("citation.view")}</Link>
        )}
      </PopoverContent>
    </Popover>
  );
}

export const CitationChip = memo(CitationChipImpl);
```

## `AgentAttributionRow`

The small "CEO Assistant · via Treasury Manager, Payroll Manager" byline under a synthesized reply, sourced
from `routed_agents[]`. A single-domain, no-fan-out reply renders **no** byline — the trace only appears when
there is genuinely something to disclose (provenance is informative, not decorative).

```tsx
// components/ai/agent-attribution-row.tsx
import { AGENT_NAME_KEY } from "@/lib/ai/agent-roster";
import { useTranslations } from "@/lib/i18n";
import type { RoutedAgent } from "@/types/ai";

export function AgentAttributionRow({ routedAgents }: { routedAgents: RoutedAgent[] }) {
  const { t } = useTranslations("ai");
  const via = routedAgents.map((a) => t(AGENT_NAME_KEY[a.agent_code])).join(t("assistant.viaJoin"));
  return <p className="mt-1 text-caption text-ink-9">{t("assistant.via", { agents: via })}</p>;
}
```

## Prop contracts (the remaining new components)

| Component | Key props | Behavior |
|---|---|---|
| `ChatMessageList` | `messages`, `status`, `streamingData`, `onRetry` | Virtualized (`@tanstack/react-virtual`, measured heights) above ~60 messages; owns the `role="log"` live region (`# Accessibility`); only the actively-streaming bubble re-renders per token. |
| `ChatComposer` | `value`, `onChange`, `onSubmit`, `onStop`, `isStreaming` | Auto-growing textarea (up to 6 lines desktop / 4 mobile); `Enter` sends, `Shift+Enter` newline; `Send` becomes `Stop` while streaming; the mic (`VoiceInputButton`) and text field are always both present and functional. |
| `SuggestedPromptChips` | `lastMessage`, `seedContext`, `onSelect` | Regenerates after every completed turn from that turn's content (never a static list); a chip submits exactly as if typed, through the same `handleSubmit` path. On the fresh screen, seeded from time-of-day + role only. |
| `ConversationHistoryList` | `activeConversationId?` | The user's own threads, `useInfiniteQuery` (cursor, newest-first), date-grouped (Today/Yesterday/This week/Older); per-row overflow: Rename (inline, `PATCH` on blur/Enter), Archive, and (docked only) "Open in full page." |
| `VoiceInputButton` | `onTranscript`, `onSubmit` | Tap-to-start/tap-to-stop; streams audio to R2; shows the live transcript-while-speaking beneath the mic so the user confirms before it submits; a voice turn implying an action renders `RecommendationCard`'s `Do it` in a "say or tap confirm" pending sub-state. Loaded via `next/dynamic` on first mic interaction only. |

# Data & State

This screen's live transcript is deliberately **not** a TanStack Query cache entry — it is the Vercel AI SDK's
`useChat` state, matching `docs/frontend/AI_COMMAND_CENTER.md`'s classification of `POST /api/v1/ai/chat` as
"SSE stream, not a cached query." Everything *around* the live stream — the conversation list, a resumed
thread's prior messages, renaming/archiving — is ordinary TanStack Query. `docs/frontend/AI_CHAT.md → Data &
State` shows `assistant-view.tsx`, the query keys, and the SSE proxy `route.ts` in full; this document cites
them and adds the worked frames below.

## Endpoints this screen calls

| Purpose | Endpoint | Method | Permission | Cache behavior |
|---|---|---|---|---|
| Send a message, stream the reply | `/api/v1/ai/chat` (proxied via `app/api/ai/chat/route.ts`) | POST (SSE) | `ai.chat` | Not cached — `useChat` owns it |
| List the caller's own conversations | `/api/v1/ai/conversations` | GET (cursor) | `ai.chat` | `staleTime: 10_000`, refetch-on-focus |
| Load a conversation's messages | `/api/v1/ai/conversations/{id}/messages` | GET (cursor, newest-first) | `ai.chat` | `staleTime: Infinity` once loaded — a past turn never changes; only appended-to by `useChat` |
| Rename a conversation | `/api/v1/ai/conversations/{id}` | PATCH `{ title }` | `ai.chat` (must own the row) | Optimistic; rolls back on failure |
| Archive a conversation | `/api/v1/ai/conversations/{id}` | PATCH `{ status: "archived" }` | `ai.chat` (must own the row) | Optimistic remove from active list; reversible from an "Archived" filter |
| Re-check a surfaced pending approval before acting | `/api/v1/approvals/{id}` | GET | `ai.approve` | `staleTime: 0` — always re-fetched immediately before rendering Approve/Reject |
| Approve / reject an inline-embedded approval | `/api/v1/approvals/{id}/approve`\|`/reject` | POST | `ai.approve` | Reused verbatim from the Approval Center; optimistic |
| Act on a synthesized recommendation | `/api/v1/ai/recommendations/{id}/execute`\|`/send-for-approval`\|`/dismiss` | POST | the action's own key (`execute`); `ai.chat` (others) | Optimistic for `dismiss`; pessimistic for `execute` (Principle 10) |

There is deliberately **no `DELETE /api/v1/ai/conversations/{id}`** — `ai_conversations` carries no
`deleted_at`, and its `status` enum is exactly `active`/`archived`; a conversation is never hard-deleted, only
archived (`docs/api/API_LOGGING.md` retains conversation content for 180 days and a hashed/summarized form for
three years). The history rail's "Delete conversation" affordance is precisely the archive mutation with a
confirmation copy that says so plainly, rather than implying data destruction that does not occur.

## Worked frames

The assistant-role payload shape, reused verbatim from `CEO_AGENT.md`'s own contract — one `ChatMessageBubble`
renders this through the fixed slot order above:

```json
{
  "message_id": 55210, "conversation_id": 8831, "role": "assistant", "agent_code": "CEO_ASSISTANT",
  "content": "Cash is sufficient to cover this payroll cycle. The Al Ahli current account holds KWD 184,220.0000 available; Payroll Run #204 (July 2026) totals KWD 61,340.0000 net pay, due 2026-07-31. That leaves roughly KWD 122,880 of buffer after the run clears.",
  "confidence": 96.0,
  "reasoning": "Treasury Manager returned the reconciled cash position (source: bank_accounts #12, as of 2026-07-16). Payroll Manager returned Run #204's total_net_pay and due date (source: payroll_runs #204, status='calculated'). No conflicting figures; both source agents reported confidence >= 95.",
  "sources": [
    { "type": "table_row", "table": "bank_accounts", "id": 12, "label": "Al Ahli Current — KWD" },
    { "type": "table_row", "table": "payroll_runs", "id": 204, "label": "Payroll Run — July 2026" }
  ],
  "routed_agents": [
    { "agent_code": "TREASURY_MANAGER", "task_id": 990110, "confidence": 98.0 },
    { "agent_code": "PAYROLL_MANAGER", "task_id": 990111, "confidence": 99.0 }
  ],
  "suggested_actions": [ { "label": "Review Payroll Run #204", "action": "navigate", "target": "/payroll/payroll-runs/204" } ],
  "requires_approval": false, "decision_id": null
}
```

`confidence` here is `96.0` (a 0–100 field), passed through `normalizeConfidence(score, 'percentage')` before
`ConfidenceBadge` — this screen is the one where `ai_messages.confidence` (`NUMERIC(5,4)` in one DDL, displayed
as `96.0` in `CEO_AGENT.md`'s examples) and `ai_decisions.confidence_score` (`0–100`) most often coexist, so
the normalizer's `sourceField` argument is the single most likely bug to guard against.

The SSE proxy `app/api/ai/chat/route.ts` is unchanged from `FRONTEND_ARCHITECTURE.md`/`docs/frontend/AI_CHAT.md`
— it forwards the visitor's own httpOnly bearer server-side and streams `text/event-stream` back; a partial
frame carries growing `content`, and only the terminal frame carries `confidence`, `sources`, `routed_agents`,
and any `suggested_actions`.

## Query keys, and conversation-management mutation strategy

```ts
// lib/api/query-keys.ts (AI Chat — reused verbatim from docs/frontend/AI_CHAT.md)
export const aiChatKeys = {
  all: ["ai-chat"] as const,
  conversations: () => [...aiChatKeys.all, "conversations"] as const,
  messages: (conversationId: string) => [...aiChatKeys.all, "messages", conversationId] as const,
};
```

| Mutation | Strategy | Rationale |
|---|---|---|
| Sending a message (`useChat.handleSubmit`) | **Optimistic append of the user bubble only** | A user's own words are never rolled back (Principle 10); the assistant reply is streamed, not optimistic |
| `useRenameConversation` | **Optimistic** — patches the rail's title on blur/Enter, rolls back on failure; rejects an empty string client-side before ever issuing the `PATCH` | Reversible cosmetic edit; `ai_conversations.title` accepts `NULL` but the affordance should never round-trip an empty string as a "successful" rename |
| `useArchiveConversation` | **Optimistic** — removes from the active list immediately, reversible from the "Archived" filter | Reversible; not a hard delete |
| Inline `Do it` on a recommendation (`execute`) | **Pessimistic** — awaits `2xx` | Executes a real action (Principle 10) |
| Inline `Dismiss` | **Optimistic** — removes the card with a 5s Undo | Reversible; stores `rejected_reason` into `ai_memory` |
| Inline approve/reject (embedded `ApprovalCard`) | **Optimistic** — reused verbatim from the Approval Center | Deciding is reversible-adjacent; the money-moving execution is a separate pessimistic mutation elsewhere |

## Realtime

The SSE stream carries the answer, so this screen does **not** subscribe to Reverb for message content — but
it does subscribe to `private-company.{id}.ai-jobs` (the identical channel `docs/frontend/AI_COMMAND_CENTER.md`
names for "typing/thinking status") to render the pre-first-token "thinking" state and, for a long multi-agent
fan-out, which specialist is currently executing. This is the same shared `RealtimeProvider` connection every
other screen reuses — opening `/assistant` never opens a second WebSocket. An in-flight stream itself uses
SSE's own reconnect, not Reverb, so a Reverb drop never interrupts an already-streaming reply.

**Conversation titling.** `ai_conversations.title` is nullable; until the CEO Assistant's fast-tier
intent-classifier produces a concise title after the first exchange and `PATCH`es it server-side, the rail
displays a client-truncated preview of the first user message as a placeholder — never "Untitled," a bare
timestamp, or a client-invented title that could drift from what the server persists.

# Interactions & Flows

Numbered as an implementation sequence; the narrative form is `docs/frontend/AI_CHAT.md → Interactions &
Flows`'s own territory.

1. **Starting.** Landing on `/assistant` (no `conversationId`) shows an empty transcript, a short greeting, and
   role-aware `SuggestedPromptChips`. No `ai_conversations` row is created by opening the page — the first row
   is created server-side as a side effect of the first `POST /ai/chat` that omits a `conversation_id`, so the
   rail is never littered with empty abandoned threads.
2. **Sending.** Submitting optimistically appends the user's bubble (always safe) and shows the thinking
   indicator (three `accent-subtle` dots) while `useChat` awaits the first token. As the orchestrator fans
   out, the indicator is replaced by short status lines from `ai-jobs` ("Checking Treasury Manager…"), which
   collapse into `ToolCallTrace` once the answer streams. Tokens render progressively; the message is not
   "final" (badge, citations, actions withheld) until the terminal frame.
3. **Tool-use transparency.** An assistant turn with a fan-out renders `AgentAttributionRow` + a collapsed
   `ToolCallTrace`; a single-domain reply renders neither.
4. **Citations.** `CitationChip` markers weave into the prose at each `sources[]` position; a `table_row`/
   `ai_decision` citation navigates to the record, a `document` citation opens a `Sheet` — never a navigation
   to an `ai_memory` row.
5. **Suggested prompts.** Regenerate after every completed turn from that turn's content; selecting one submits
   it through the identical `handleSubmit` path.
6. **Voice.** `VoiceInputButton` is tap-to-start/tap-to-stop; the live transcript-while-speaking lets the user
   confirm before submitting; the text field and mic are always both functional — voice is a second input
   path, never a mode that disables typing. A voice reply with an implied action requires an explicit spoken
   or tapped confirmation before `Do it` becomes actionable (a misheard word is a higher-cost mistake than a
   misclick).
7. **Proposals + inline approve/reject.** A freshly synthesized recommendation renders through
   `RecommendationCard`'s three-button pattern (`Do it` only when `can_execute_directly` and never for
   `bank.transfer`/`payroll.approve`/`tax.submit`/permission-change/delete-void); a surfaced *existing*
   pending approval embeds the real `ApprovalCard`, which re-fetches `GET /approvals/{id}` live before
   rendering its buttons — the CEO Assistant narrates the pending action and links to it; it is the
   *component*, not the *agent*, that lets a permissioned human act.
8. **Managing history.** Per-row overflow: Rename (inline), Archive, and (docked only) "Open in full page."
   "New conversation" always starts a genuinely empty composer — it never carries the previous thread's
   context forward.

# AI Integration

This screen is the single densest concentration of the platform's AI-integration contract in the product,
because every one of its messages *is* an AI-authored surface. Four rules govern it beyond what any other
screen additionally observes (`docs/frontend/AI_CHAT.md → AI Integration`):

- **Confidence is the minimum of what it depends on, never an average.** A synthesized reply's confidence is
  the lowest confidence among every specialist whose output was load-bearing — a cash-runway answer blending a
  98-confidence Treasury Manager balance with a 62-confidence Forecast projection is reported at 62, and
  `ToolCallTrace`'s expanded view names which half is weaker. No component recomputes a blended figure
  independently from the API's own `confidence`.
- **Disagreement is disclosed, never silently resolved.** If two routed specialists return conflicting figures
  for the same fact, `ToolCallTrace` **auto-expands** (not collapsed) and shows a compact agent/claim/
  confidence comparison, and the reasoning text names the conflict ("Treasury Manager and the bank feed
  disagree on yesterday's closing balance by KWD 340 — the bank feed's figure is 12 hours fresher"). A user is
  never shown a single number with the disagreement averaged away underneath it.
- **A low-confidence or unanswerable question is stated as such, never dressed up.** A reply the orchestrator
  cannot answer confidently renders as plain prose with **no** `ConfidenceBadge` at all (the failure is "no
  answer," categorically different from "a low-confidence answer"). Below a blended confidence of 70, the
  bubble carries a plain-language caveat line ("I'm not fully confident in this — want me to have the Auditor
  take a closer look?") rather than generic low-confidence chrome.
- **Voice replies with an implied action require an explicit spoken confirmation.** A voice-originated turn
  that would otherwise render `Do it` instead renders it in a "say or tap confirm" pending state until the
  confirmation arrives — a misheard word in a hands-free moment is a materially higher-cost mistake than a
  misclick, and this screen's mic makes hands-free interaction routine.

Two structural guarantees the screen enforces in pixels: the assistant **never auto-commits** — `Do it` fires
only a human's click, and only when the server said `can_execute_directly`; and the assistant **can never
surface data the user lacks permission for** — a tool call the user's role cannot make is not made, and the
reply says so rather than leaking a value (`# Route & Access`).

# States

| State | Trigger | Rendering |
|---|---|---|
| Empty / fresh | `/assistant`, no messages | Greeting line, role-aware `SuggestedPromptChips`, composer focused |
| Thinking | Message submitted, no token yet | Three `accent-subtle` pulsing dots; composer disabled except **Stop** |
| Routing / fan-out | `ai-jobs` reports specialist(s) executing | Dots replaced by short status lines ("Checking Treasury Manager…"), still pre-token |
| Streaming | Tokens arriving | Prose grows progressively; confidence badge, citations, actions withheld until the terminal frame |
| Complete | Terminal SSE frame | Full `ChatMessageBubble` per the slot order; composer re-enabled; `SuggestedPromptChips` regenerate |
| Low confidence / no answer | Blended < 70, or no tool could answer | Plain prose, explicit caveat line, no confidence badge, an offered hand-off to a human/specialist |
| Disagreement | Two specialists conflict | `ToolCallTrace` auto-expanded with the two-row compare; the reasoning names the conflict |
| Voice recording | Mic tapped | Waveform + live partial transcript beneath the composer; tap again or silence-timeout submits |
| Voice pending confirmation | A voice turn implies an action | `RecommendationCard`'s `Do it` in a "say or tap confirm" pending sub-state |
| Error — send failed | Network drop / upstream 5xx mid-stream | The user's own message is preserved (never dropped); an inline "Couldn't get a response — retry" replaces the assistant bubble |
| Error — tool timeout | One routed specialist times out in its own budget | The reply still renders from whichever specialists responded, with a plain note ("Inventory Manager didn't respond in time — this answer excludes stock data") |
| History loading | Rail's first page in flight | Five placeholder row-shaped shimmer blocks, date-grouped shape preserved |
| History empty | Brand-new user, zero past conversations | "No conversations yet — ask your first question below," no illustrated character |
| Reconnecting | `ai-jobs` channel drops | A small static topbar dot only (never a banner); an in-flight stream uses SSE's own reconnect, so a Reverb drop never interrupts it |

Every skeleton reuses the loaded state's exact layout classes so the transcript never pops or reflows between
loading and loaded.

# Responsive Behavior

| Region | Desktop / Ultra Wide (`xl`+) | Tablet (`md`–`lg`) | Mobile (`base`–`sm`) |
|---|---|---|---|
| History rail | `280px`, always visible | Collapses to a toggle; opens as a `Sheet` overlay | Behind a hamburger in the page header; full-screen `Sheet` |
| Transcript | Centered, `max-w-3xl` | Full width minus rail toggle | Full width, edge-to-edge padding |
| Composer | Pinned to transcript bottom, auto-grow up to 6 lines | Same | Sticky above the safe-area inset; auto-grow capped at 4 lines before internal scroll |
| Mic button | Inline, left of the text field | Same | Enlarged to the 44px touch floor, prominent given voice's higher value on mobile |
| Suggested prompts | Full chip row | Same, may wrap to two rows | Horizontally scrollable single row, not wrapped, to preserve composer visibility |
| `ToolCallTrace` / citation detail | `Popover` on hover | `Popover` on tap | `Sheet` instead of `Popover` — a hover-shaped popover is the wrong touch affordance |
| Docked variant | Persistent AI Rail, `360px`, `3xl`+ only | Floating trigger → `Sheet` | Floating trigger → full-screen `Sheet` |

Touch targets across the composer (mic, send, stop) and every message action (Approve/Reject, `Do it`/`Send
for approval`/`Dismiss`) follow the 44px minimum with an 8px gap — a mis-tap approving a surfaced payroll
release from a phone carries the same real consequence as one on desktop. The transcript's scroll container
uses `overscroll-behavior: contain` so an aggressive mobile scroll-up never triggers native pull-to-refresh.
Virtualization activates once a resumed conversation exceeds ~60 messages, using each message's own *measured*
(not estimated) height, since chat bubbles vary far more in height than a table row.

# RTL & Localization

Authored and reviewed in Arabic before being considered done, not mirrored at the end (`DESIGN_LANGUAGE.md →
Design Principle 6`). `dir="rtl"` is set once on `<html>`; no chat component toggles direction. Every gap,
padding, and alignment value — the composer's mic-then-input-then-send order, `CitationChip`'s inline position
within RTL prose, `ToolCallTrace`'s chevron, the history rail's slide-in edge — uses logical utilities
(`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively.

Three things never mirror:

- **Every monetary figure, percentage, confidence score, and citation-adjacent number renders inside a
  `dir="ltr"` span.** `KWD 184,220.0000` and its `96%` badge read left-to-right even mid-sentence in a fully
  Arabic reply, via the same `Amount`/`AmountCell` primitives, `numberingSystem: "latn"` forced. This matters
  more here than almost anywhere, because a chat reply is exactly where a monetary figure most often sits
  embedded inside a natural-language sentence rather than isolated in a table cell.
- **Directional icons flip; meaning-bearing icons don't.** `ToolCallTrace`'s chevron and the rail's "open"
  arrow mirror via `rtl:rotate-180`; the confidence badge's icon, a citation marker, and the CEO Assistant's
  geometric mark never do.
- **AI-authored prose is never machine-translated by the frontend** — a reply's `content` arrives already
  localized per the API's `Accept-Language` content-negotiation; the client localizes only chrome (composer
  placeholder, chip labels, empty-state copy, "Show work" toggle, history group labels).

Voice input is tuned for both English and Gulf-dialect Arabic speech-to-text, and the live transcript-while-
speaking renders in whichever script the engine detects, independent of the UI's active locale — a bilingual
user can speak Gulf Arabic into an English-locale interface and see an accurate Arabic transcript before it
submits.

| Context | English | Arabic |
|---|---|---|
| Composer placeholder | Ask anything about your business | اسأل أي شيء عن أعمالك |
| New conversation | New conversation | محادثة جديدة |
| Thinking (screen-reader text) | Thinking… | جارٍ التفكير… |
| Show work | Show work | عرض التفاصيل |
| Low-confidence caveat | I'm not fully confident in this | لست واثقًا تمامًا من هذه الإجابة |
| Voice confirmation | Say "confirm" to proceed | قل "تأكيد" للمتابعة |
| History groups | Today / Yesterday / This week | اليوم / أمس / هذا الأسبوع |
| Archive confirmation | Archived conversations are hidden from your history but kept for audit | المحادثات المؤرشفة تختفي من سجلك لكن تُحفظ لأغراض التدقيق |
| Empty history | No conversations yet — ask your first question below | لا توجد محادثات بعد — اطرح سؤالك الأول أدناه |

Arabic copy is authored directly by a fluent professional-register writer, per `DESIGN_LANGUAGE.md → Voice &
Microcopy`.

# Dark Mode

System-aware, user-overridable, and persisted, driven by the platform's `class`/`data-theme="dark"` strategy —
no component here implements its own theme logic. `AiCardShell`'s border and badge, `ConfidenceBadge`'s accent,
and `CitationChip`'s marker all resolve through the same token set every other AI surface uses, recalibrated
for dark mode rather than mechanically brightened (`DARK_MODE.md`'s "the accent gets lighter, not more
saturated" rule) — a 96%-confidence reply's badge reads with the same calm authority in both themes rather than
glowing against a dark canvas. Two rules specific to a chat surface's density of AI content:

- **The user's own message never borrows AI styling.** A user bubble is `ink-3` fill (dark: the calibrated
  dark `ink-3`, which is *lighter* than the dark canvas beneath it) with no accent border in either theme —
  only assistant turns earn `AiCardShell`, so "mine" vs "the AI's" is distinguishable by shape alone in both
  themes.
- **The thinking indicator and streaming caret are toned down, not inverted** — the three pulsing dots use
  `accent-subtle` at roughly half light-mode opacity in dark mode; a naive flip that brightened a "thinking"
  indicator would read as more urgent than the calm, never-alarming motion principle permits.

The mic's recording-state pulse is re-tuned per theme rather than color-inverted, and no icon or the CEO
Assistant's geometric mark is ever `filter: invert()`-flipped — both ship as theme-aware assets resolving
through `currentColor`. Every Storybook story ships the four-way `light/LTR`/`light/RTL`/`dark/LTR`/`dark/RTL`
matrix; the route's Playwright suite captures the same four-way set.

# Accessibility

This screen targets the same WCAG 2.2 AA floor, with particular weight on the three areas a streaming,
voice-capable chat surface stresses hardest: live regions, keyboard operability, and unambiguous number
reading (restating `docs/frontend/AI_CHAT.md → Accessibility`):

- **Landmarks and roles.** The transcript is a single `<section aria-label="Conversation">` with
  `role="log"` — the correct pattern for a chat history, distinct from `role="feed"` — so AT announces new
  content as it arrives without re-reading the entire history. Each turn is an `<article>` with an accessible
  name combining role + a short excerpt ("Your message: Why did our KWD balance…", "CEO Assistant's reply: Cash
  is sufficient…"), so a screen-reader user can jump turn-to-turn without linearly reading every prior message.
- **Live regions, calibrated.** The send action sets `role="status"`/`aria-busy="true"` once when a reply
  begins ("Thinking…" then "Answering…"), and the full, complete text is announced once via
  `aria-live="polite"` when streaming finishes — **never token-by-token**, which would make the screen unusable
  by voice. A tool-use status update ("Checking Treasury Manager…") is announced at most once per specialist,
  not on every internal state tick.
- **Keyboard.** The composer is focused by default on load; `Enter` sends, `Shift+Enter` newlines, `↑` with an
  empty composer edits the just-sent message, `Esc` closes the docked `Sheet`. Every citation marker,
  `ToolCallTrace` toggle, and message action is a real `Tab`-reachable `<button>`, never a `<div>` with a
  click handler, with `aria-expanded` on every disclosure and `aria-describedby` linking a reasoning-dependent
  action to the reasoning that justifies it. `⌘K`'s "Ask AI," triggered from within `/assistant`, focuses the
  existing composer rather than opening a redundant second instance.
- **Amounts and confidence read unambiguously.** `KWD 184,220.0000` is announced as a single unit with its
  currency code (the same `Amount` primitive every financial table uses), not a bare number followed by an
  unrelated "KWD" three words later; `ConfidenceBadge`'s percentage and band ("high confidence") are both real
  text nodes, never a bare progress-bar `width`.
- **Voice has a full, equally capable non-voice path.** The mic is an enhancement — every capability voice
  offers (asking, confirming an implied action) has an identical typed/tapped equivalent, and a user who never
  touches the mic experiences no degraded functionality, only a longer path to the same answer.
- **Testing.** `/assistant` and `/assistant/{id}` are in the four-permutation automated sweep with zero
  serious/critical `axe` violations as a merge gate; the manual pass adds a screen-reader pass over a streaming
  reply (confirming announce-once, not token-by-token), a keyboard-only pass completing a voice-free question
  and an inline approve, and an `ar`/RTL pass over `CitationChip` position and composer control order.

# Performance

`/assistant` is held to a stricter *interaction* budget than most list/detail screens — the meaningful metric
is time-to-first-token and per-token render smoothness, not raw LCP.

- **Streaming without re-render thrash.** `useChat`'s token updates are batched at the React level; only the
  actively-streaming `ChatMessageBubble` re-renders per token — every prior, already-complete message stays
  referentially stable. `ChatMessageBubble`, `ToolCallTrace`, and `CitationChip` are all `memo`-wrapped, so a
  40-message resumed conversation never re-evaluates 39 settled bubbles when the 40th receives a token.
- **Code splitting.** The voice-recording module (audio capture, waveform, R2 upload) loads via `next/dynamic`
  only on the first mic interaction — a user who never touches voice pays zero bytes for it. The history rail's
  virtualization dependency similarly loads only once a resumed conversation crosses the ~60-message threshold.
- **RSC and caching.** Both route segments set `dynamic = "force-dynamic"` and `fetchCache =
  "default-no-store"` unconditionally (every byte is scoped by `user_id` + `X-Company-Id`); nothing here is a
  Data-Cache or PPR candidate. The history rail's `staleTime: 10_000` with `refetchOnWindowFocus` shows a fresh
  list on return without a full-page reload.
- **Realtime cost control.** The `ai-jobs` subscription is the same shared `RealtimeProvider` connection —
  visiting `/assistant` opens no second WebSocket, and the docked variant reuses that same connection.
- **Web Vitals.** INP is watched specifically on the composer's send action and the mic's start/stop toggle —
  this screen's two highest-frequency interactive surfaces — tagged against a company-size-band baseline.

# Edge Cases

`docs/frontend/AI_CHAT.md → Edge Cases` already covers the scenarios specific to the conversational model (a
second message sent mid-stream, the browser Back button mid-stream, a company switch abandoning the active
conversation, a mid-conversation permission change, two tabs on the same conversation, garbled voice
transcription, an SSE drop mid-stream, a deleted/voided cited record, a hundreds-of-turns conversation,
renaming to an empty string, archiving the open conversation, and opening the dock while the full page is open
in another tab) — this document does not repeat them. The rows below are this implementation layer's own
additions.

| Edge case | Frontend behavior |
|---|---|
| A token frame carries a `sources[]` position index past the end of the streamed prose so far | `renderWithCitations` defers inserting a `CitationChip` until the terminal frame (citations, badge, and actions only render once `streaming` is false), so a marker never lands at a position the prose has not yet reached mid-stream — it appears with the settled message, in one pass, never flickering into place token by token. |
| `ToolCallTrace` receives a `disagreement` payload but the user has already manually collapsed it | The auto-expand is applied only on first mount (`useState(disagreement != null)`); a subsequent token frame does not re-open a trace the user deliberately collapsed — the disagreement remains disclosed in the reasoning prose regardless, so collapsing the trace never hides the conflict itself. |
| The embedded `ApprovalCard`'s live `GET /approvals/{id}` re-check resolves after the surrounding message finished streaming | The card renders its own skeleton action row until the re-check resolves, then swaps to the real Approve/Reject (or the read-only presentation) — the message's prose and citations are never blocked on the approval re-check, since they come from the stream, not the approvals query. |
| A `memo`'d `ChatMessageBubble` receives a new `actionHandlers` identity every parent render | The handlers are stabilized with `useCallback` in `AssistantView` so `memo` is not defeated — a settled bubble must not re-render merely because its parent re-rendered on the streaming bubble's token; this is the concrete reason the handlers are hoisted and memoized rather than inlined per-render. |
| A locale switch (EN → AR) with a resumed conversation open | Chrome (composer placeholder, "Show work," group labels) re-renders in the new locale immediately; the already-rendered reply `content` is **not** machine-translated in place (it is the model's own words) — a fresh turn under the new `Accept-Language` returns Arabic prose, but prior turns keep the language they were generated in, exactly as the transcript recorded them. |
| The fresh `/assistant` composer is submitted, but the first `POST /ai/chat` (which creates the row) fails before any token | No `ai_conversations` row was created (creation is a server-side side effect of a *successful* first call), so the history rail stays clean; the user's optimistic bubble is preserved with an inline "Couldn't get a response — retry" that re-sends the same turn (reusing the idempotency key) rather than orphaning a half-created conversation. |
| Virtualization activates mid-conversation as the 61st message lands | `ChatMessageList` measures each bubble's real height before windowing (chat bubbles vary far more than table rows), so the scroll position and the auto-scroll-to-latest behavior are preserved across the un-virtualized→virtualized transition — the transcript never jumps when the threshold is crossed. |

# End of Document
