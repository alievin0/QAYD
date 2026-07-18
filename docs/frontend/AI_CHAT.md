# AI Chat — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: AI_CHAT
---

# Purpose

This document is the frontend implementation specification for QAYD's AI Chat screen — the single conversational surface onto the entire fifteen-agent AI workforce, fronted end to end by the CEO Assistant orchestrator (`docs/ai/agents/CEO_AGENT.md`). It is the frontend realization of that document's product and behavioral contract: a human never selects a specialist agent from a menu, never sees "General Accountant" or "Treasury Manager" as a mode to switch into, and never addresses a domain expert directly. There is exactly one composer, exactly one send action, and exactly one place a reply appears. Behind that single box, the CEO Assistant classifies the request, decides which of the fourteen specialists (General Accountant, Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO Agent, Fraud Detection, Reporting Agent, Document AI, OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant) the question actually needs, invokes them in parallel, reconciles disagreement rather than hiding it, and returns one coherent, cited, confidence-scored answer. This document does not re-derive that orchestration contract — where the two disagree, `CEO_AGENT.md` is authoritative for *what the AI is allowed to think and do*, and this document is authoritative for *how a human sees and operates it*: the Next.js route, the component tree, the SSE streaming wiring, the tool-use and citation rendering, the inline proposal/approval affordances, conversation persistence, and the screen's behavior across breakpoints, direction, theme, and assistive technology.

AI Chat exists in two renderings of one underlying surface, never two implementations. The **full-page** experience is a dedicated destination a user navigates to when a conversation is the primary task — reviewing yesterday's overnight activity in depth, working through a multi-turn financial question, or resuming a saved thread from conversation history. The **docked** experience is the same transcript, composer, and message contract, rendered in a `Sheet` or the persistent AI Rail from wherever else in the product a user happens to be, seeded with that screen's own context exactly as `docs/frontend/AI_COMMAND_CENTER.md` (frontend) already specifies for its "Ask AI" panel. That document explicitly commits to this non-duplication — "Ask AI's streaming surface reuses `app/(app)/ai/chat/page.tsx`'s `useChat` wiring verbatim rather than a second implementation docked in the shell" — and this document is the specification that statement points to. Nothing about tool-use transparency, citation rendering, the three-button proposal pattern, or conversation persistence differs between the two renderings; only the container (full route vs. `Sheet`/rail) and the seed context differ, per Layout & Regions below.

**A note on the route.** `docs/frontend/NAVIGATION_SYSTEM.md`'s nav tree and `docs/frontend/FRONTEND_ARCHITECTURE.md`'s own App Router tree currently list this screen's path as `app/(app)/ai/chat/page.tsx`, written before this screen's product identity — the single, always-reachable front door to the whole AI workforce, not one leaf nested under an "AI" analytics section alongside Insights, Recommendations, Risks, and Forecast — was locked in. `app/(app)/assistant/page.tsx` is this document's and the product's canonical route going forward, exactly the precedent `docs/frontend/AI_COMMAND_CENTER.md` (frontend) already set for `/dashboard` → `/command-center`: `app/(app)/ai/chat/page.tsx` is retained as a permanent redirect to `/assistant`, the Command Palette's "Ask AI" hand-off target (`docs/frontend/NAVIGATION_SYSTEM.md`'s `select(\`/ai/chat?q=…\`)`) is updated to `/assistant?q=…`, and every other document's mention of "Ask AI" or "the chat panel" should be read as referring to this specification. New code, new links, and every other document should read `/assistant`.

```ts
// next.config.ts (excerpt)
async redirects() {
  return [
    { source: "/ai/chat", destination: "/assistant", permanent: true },
    { source: "/dashboard", destination: "/command-center", permanent: true },
  ];
},
```

A second reconciliation this document makes explicit, because two sibling documents state it two different ways: `ai_messages.confidence` is persisted as `NUMERIC(5,4)` — a 0–1 fraction, per `docs/ai/AI_FINANCE_OS.md`'s DDL — while `docs/ai/agents/CEO_AGENT.md`'s own worked response examples display `"confidence": 96.0` on what is structurally the same field, and `ai_decisions.confidence_score` is separately defined as `NUMERIC(5,2) CHECK (confidence_score BETWEEN 0 AND 100)`. The frontend does not need to resolve which storage convention is "correct" — it already owns the mechanism that makes the distinction irrelevant to a component author: `ConfidenceBadge` accepts a raw score plus its unit and calls the shared `normalizeConfidence(score, unit)` helper (`docs/frontend/AI_COMMAND_CENTER.md` (frontend) → AI Integration), the same helper every other AI-authored surface in the product already uses to reconcile a 0–100 API figure against the component library's canonical 0–1 internal range. Every confidence value this screen renders — a message's own confidence, a routed agent's sub-confidence, a proposal's confidence — passes through that one normalizer; no component on this screen compares or formats a raw confidence number itself.

# Route & Access

`app/(app)/assistant/page.tsx` renders a fresh, unsaved conversation composer; `app/(app)/assistant/[conversationId]/page.tsx` renders a specific saved thread loaded from history. Both are Server Components that resolve the session and the conversation-history sidebar's first page server-side, matching `docs/frontend/FRONTEND_ARCHITECTURE.md`'s RSC-by-default rule, with the live transcript itself owned by a client subtree once hydrated.

```
app/(app)/assistant/
├── layout.tsx              # Shared history-rail chrome for both children below
├── page.tsx                # Fresh/unsaved composer — no conversation_id yet
└── [conversationId]/
    └── page.tsx             # Resumes a saved thread — prefetches its message page
```

There is no per-agent sub-route and never will be — `app/(app)/assistant/[agentCode]/page.tsx` is not a route this product defines, by design: routing to a specialist is the CEO Assistant's job at request time, never a URL a human constructs, matching `CEO_AGENT.md`'s Role & Mandate ("there is no 'switch to the Payroll agent' menu"). A `?highlight=` or `?context=` query parameter (see Interactions & Flows) carries seed context from a deep link; it is never a way to address a different agent directly.

**Access gate.** The screen's own gate is `ai.chat` alone, held by every non-suspended account per `CEO_AGENT.md`'s explicit permission table — this is deliberate and screen-specific, distinct from the coarser `reports.read` gate `docs/frontend/NAVIGATION_SYSTEM.md`'s nav tree applies to the "AI" sidebar group as a whole (which bundles the deeper analytical leaves — Insights, Recommendations, Risks, Forecast — that a Sales Employee or Warehouse Employee legitimately should not see). Per `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s own stated rule that "each panel enforces its own permission independently at the data layer," the Ask AI/AI Chat leaf is pinned visible to any `ai.chat` holder regardless of whether its sibling leaves are hidden, because `CEO_AGENT.md`'s foundational premise — "a Sales Employee, a Warehouse Employee, and the Owner can all open the same conversational surface" — would otherwise be silently violated by a nav-tree gate written for its neighbors. What a given user can *ask about* is never separately restricted by this screen; it is bounded entirely by the intersection of their own role's permissions against whatever tool calls the orchestrator would need to make on their behalf, exactly as `CEO_AGENT.md`'s Data Access & Tenant Scope specifies.

| Capability on this screen | Permission | Default holders |
|---|---|---|
| Open `/assistant`, send messages, read replies | `ai.chat` | All roles except suspended/locked accounts |
| See a reply that required multi-agent, cross-domain synthesis | `ai.ceo.query` (checked server-side per underlying tool call, never by the client) | Owner, CEO, CFO, Finance Manager, Senior Accountant, department managers for their own scope |
| Receive/request the scheduled morning briefing from within a conversation | `ai.ceo.brief` | Owner, CEO, CFO, Finance Manager |
| Request a what-if simulation through chat | `ai.ceo.simulate` | Owner, CEO, CFO, Finance Manager |
| Act on an inline-embedded pending approval (Approve/Reject) | `ai.approve` | Owner, CEO, CFO, Finance Manager (per decision type) |
| Use voice input/output | `ai.chat` (no separate gate; device-capability-gated, not role-gated) | Same as chat itself |

The client never branches its rendering on `ai.ceo.query`/`ai.ceo.simulate`/`ai.ceo.brief` directly — those are inputs to the orchestrator's own routing decision inside the FastAPI layer, invisible to the composer, which looks and behaves identically for a Warehouse Employee asking about their own stock counts and a CFO asking a company-wide cash question. The only permission this screen's components check client-side is `ai.approve`, and only to decide whether to render Approve/Reject controls on an inline-embedded pending item (see AI Integration).

**Personal, not shared, history.** `ai_conversations.user_id` is `NOT NULL` and scoped per user (`docs/ai/AI_FINANCE_OS.md`'s DDL), so the conversation-history rail this screen renders shows only the signed-in user's own past threads with the CEO Assistant, never a company-wide shared inbox — Mariam's conversation about Q2 margin and Fahad's conversation about warehouse headcount are fully independent rows, even though both draw on the same company's ledger data underneath. This is a deliberate product distinction from an internal team-chat tool: the CEO Assistant is a personal advisor to every seat, not a group channel.

**Docked entry points.** From any other screen in the product, three paths reach this same surface without a full navigation: the AI Rail's persistent panel (`3xl`+, 1920px), the AI Rail's `Sheet` overlay (below `3xl`), and the ⌘K Command Palette's "Ask AI" action, which hands off free text as `/assistant?q=…` (see `docs/frontend/NAVIGATION_SYSTEM.md → Command Palette`, updated per the redirect note above). None of these is a second implementation — each mounts the identical `AssistantView` client component this document specifies, differing only in container and seed context.

# Layout & Regions

The full-page rendering is a two-region layout — a collapsible conversation-history rail and the active transcript — never a three-column layout, because the AI Rail that appears on every *other* screen would be redundant here: the entire page already is the AI surface.

```
Desktop / xl+ (1280px), history rail expanded
┌───────────┬─────────────────────────────────────────────────────────────┐
│  History  │  ⌘K [Search or jump to…]         AI● 🔔  ▾ Mariam            │
│  ┌─────┐  ├─────────────────────────────────────────────────────────────┤
│  │+ New│  │                                                              │
│  └─────┘  │   ┌──────────────────────────────────────────────────┐       │
│  ─────    │   │  You                                              │      │
│  Today    │   │  Why did our KWD balance drop so much this week?  │      │
│  ▸ Cash…  │   └──────────────────────────────────────────────────┘       │
│  ▸ Q2 mar │                                                              │
│  Yesterday│   ┌──────────────────────────────────────────────────┐       │
│  ▸ Payroll│   │ ✓ CEO Assistant · 96% confidence         [AI]     │      │
│  ▸ Reorder│   │  The main draw was the payroll run on July 15…    │      │
│           │   │  ⌄ Checked Treasury Manager, Payroll Manager      │      │
│           │   │  [1] bank_accounts #12   [2] payroll_runs #204    │      │
│           │   │  ─────────────────────────────────────────────    │      │
│           │   │  [ Review Payroll Run #204 → ]                    │      │
│           │   └──────────────────────────────────────────────────┘       │
│           │                                                              │
│           │   Suggested: "Break this down by branch" · "Show June too"  │
│           ├─────────────────────────────────────────────────────────────┤
│           │  [🎙]  Ask anything about your business…            [Send]  │
└───────────┴─────────────────────────────────────────────────────────────┘
```

```
Docked (Sheet, below 3xl) — opened from Cash Flow Status on the Command Center
┌──────────────────────────────────────┐
│  Ask AI                          [×] │
│  Scoped to: Cash Flow Status         │
├───────────────────────────────────────┤
│  (identical message list, condensed  │
│   padding, no history rail — a       │
│   "View full conversation" link      │
│   opens /assistant/[conversationId]) │
├───────────────────────────────────────┤
│  [🎙] Ask a follow-up…        [Send] │
└──────────────────────────────────────┘
```

The history rail (`280px` expanded, collapses to `0` below `lg`) is its own scroll container, structurally identical to the app shell's Sidebar pattern from `docs/frontend/LAYOUT_SYSTEM.md`: a fixed header (**New conversation** button) that never scrolls, a scrolling list of the user's own threads grouped by relative date (Today, Yesterday, This week, Older — never absolute dates for recent groups, per `docs/frontend/DESIGN_LANGUAGE.md`'s calm, plain-language voice), and no bottom utility row (Settings/collapse live in the app shell's own Sidebar, not duplicated here). The transcript region is a single scrolling column, `max-w-3xl` centered on wide viewports — prose and message bubbles do not stretch edge-to-edge even on an ultra-wide monitor, because a chat transcript is read top-to-bottom like a document, not scanned like a financial table, and an overlong line length actively hurts that. The composer is pinned to the bottom of the transcript region (not the viewport), `surface-1` background, `1px` `ink-6` top border, `radius-lg` input — no full-bleed "floating" composer card, matching the platform's flat, hairline-bordered elevation model (`docs/frontend/DESIGN_LANGUAGE.md → Elevation & Surfaces`). The docked `Sheet` variant drops the history rail entirely and adds a single-line "Scoped to: {panel name}" context strip beneath its header, present only when opened from another screen's contextual trigger — opening it fresh from the sidebar's "AI" entry or the ⌘K palette omits the strip.

Every message bubble is a `Card`-derived shape at `radius-lg` with a `1px` `ink-6` border — an assistant message additionally carries the same colored-left-border-plus-badge `AiCardShell` envelope every other AI-authored surface in the product uses, never a chat-app-style colored speech-bubble fill. A user's own message renders in plain `ink-3` fill with no border accent at all, because only AI-authored content earns the accent border, per `docs/frontend/DESIGN_LANGUAGE.md → Design Principle 7` ("nothing the AI produces is styled to look like a fact the system has already committed" — the inverse is equally true here: nothing the *human* wrote is styled to look AI-authored).

# Components Used

Nothing on this screen is a one-off; every visual element is either drawn unchanged from `docs/frontend/COMPONENT_LIBRARY.md`/`docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s shared AI component set, or is a new, reusable addition this document introduces because no existing component covers a chat-specific need (a streaming message list, a tool-call trace, an inline citation marker).

| Component | Source | Used for |
|---|---|---|
| `AiCardShell` | `components/ai/ai-card-shell.tsx` | The mandatory colored-border-plus-badge envelope every assistant message renders through |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Per-message confidence, and per-routed-agent sub-confidence inside the tool-call trace |
| `ReasoningDisclosure` | `components/ai/reasoning-disclosure.tsx` | The collapsed-by-default synthesis trace beneath a message's prose |
| `RecommendationCard` | `components/ai/recommendation-card.tsx` | The three-button (`Do it` / `Send for approval` / `Dismiss`) pattern when a reply's `suggested_actions`/`recommended_action` graduates to an actionable proposal |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Embedded inline, read live, when a reply surfaces an *existing* pending approval the caller may act on (see AI Integration) |
| `Badge` | `components/ui/badge.tsx` | The `"AI"` provenance badge; `tone="danger"` on a held/blocked item surfaced inline |
| `Tooltip` | `components/ui/tooltip.tsx` | Citation hover previews; disabled-mic and disabled-action explanations |
| `Popover` | `components/ui/popover.tsx` | A citation's full detail card on click/long-press (desktop hover, touch tap) |
| `Sheet` | `components/ui/sheet.tsx` | The docked variant's container below `3xl`; the mobile history-rail overlay |
| `Skeleton` | `components/ui/skeleton.tsx` | Message-list and history-rail loading shapes |
| `Avatar` | `components/ui/avatar.tsx` | The user's own avatar on their message; the CEO Assistant's geometric mark (never a face/mascot, per `docs/frontend/DESIGN_LANGUAGE.md`) on assistant messages |
| `useChat` (Vercel AI SDK) | `app/(app)/assistant/*`, reused verbatim by the docked dock | Token-streaming message state, the one and only client-side source of truth for the live transcript |
| `ChatMessageList` **(new)** | `components/ai/chat-message-list.tsx` | Virtualized, auto-scrolling message list; owns the `role="log"` live region (see Accessibility) |
| `ChatMessageBubble` **(new)** | `components/ai/chat-message-bubble.tsx` | Renders one turn — user or assistant — dispatching to `AiCardShell` for assistant turns |
| `ChatComposer` **(new)** | `components/ai/chat-composer.tsx` | Auto-growing textarea, send button, mic button, attach-nothing-by-default (see Edge Cases) |
| `ToolCallTrace` **(new)** | `components/ai/tool-call-trace.tsx` | The collapsible "Checked Treasury Manager, Payroll Manager" disclosure — tool-use transparency |
| `CitationChip` **(new)** | `components/ai/citation-chip.tsx` | Numbered inline citation marker `[1]`/`[2]` plus its `Popover`/`Tooltip` detail |
| `ConversationHistoryList` **(new)** | `components/ai/conversation-history-list.tsx` | The history rail's date-grouped, cursor-paginated thread list |
| `SuggestedPromptChips` **(new)** | `components/ai/suggested-prompt-chips.tsx` | Contextual follow-up chips rendered above the composer |
| `VoiceInputButton` **(new)** | `components/ai/voice-input-button.tsx` | Mic affordance, recording state, live transcript-while-speaking |
| `AgentAttributionRow` **(new)** | `components/ai/agent-attribution-row.tsx` | The small "CEO Assistant · via Treasury Manager, Payroll Manager" byline under a synthesized reply |

No component here introduces a bespoke confidence indicator, a bespoke approval affordance, or a bespoke citation color — a screen-specific need (the numbered inline citation marker, for instance) is a new, narrowly-scoped component built from existing primitives (`Popover`, `Badge`), never a parallel reimplementation of `ReasoningDisclosure` or `ApprovalCard`, per `docs/frontend/DESIGN_LANGUAGE.md → Design Principle 8`.

# Data & State

This screen's live transcript is deliberately **not** a TanStack Query cache entry — it is the Vercel AI SDK's `useChat` state, matching `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s own explicit classification of `POST /api/v1/ai/chat` as "SSE stream, not a cached query." Everything *around* the live stream — the conversation list, a resumed conversation's prior messages, renaming/archiving — is ordinary TanStack Query, following `docs/frontend/FRONTEND_ARCHITECTURE.md`'s two-fetching-paths rule exactly: the Server Component prefetches the history rail's first page and, on a resumed thread, that thread's most recent message page; everything after first paint is TanStack Query's job.

| Purpose | Endpoint | Method | Permission | Cache behavior |
|---|---|---|---|---|
| Send a message, stream the reply | `/api/v1/ai/chat` (proxied via `app/api/ai/chat/route.ts`) | POST (SSE) | `ai.chat` | Not cached — `useChat` owns it |
| List the caller's own conversations | `/api/v1/ai/conversations` | GET (cursor) | `ai.chat` | `staleTime: 10_000`, refetch-on-focus |
| Load a conversation's message history | `/api/v1/ai/conversations/{id}/messages` | GET (cursor, newest-first, "load more" scrolls upward) | `ai.chat` | `staleTime: Infinity` once loaded — a past turn never changes; only appended-to by `useChat` |
| Rename a conversation | `/api/v1/ai/conversations/{id}` | PATCH `{ title }` | `ai.chat` (must own the row) | Optimistic; rolls back on failure |
| Archive a conversation | `/api/v1/ai/conversations/{id}` | PATCH `{ status: "archived" }` | `ai.chat` (must own the row) | Optimistic remove from the active list; reversible from an "Archived" filter |
| Re-check a surfaced pending approval before acting | `/api/v1/approvals/{id}` | GET | `ai.approve` | `staleTime: 0` — always re-fetched immediately before rendering Approve/Reject, never trusted from the chat payload |
| Approve / reject an inline-embedded approval | `/api/v1/approvals/{id}/approve`\|`/reject` | POST | `ai.approve` | Reused verbatim from the Approval Center; optimistic per `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s `useApproveRequest` |
| Act on a synthesized recommendation | `/api/v1/ai/recommendations/{id}/execute`\|`/send-for-approval`\|`/dismiss` | POST | The action's own permission key (`execute`); `ai.chat` (`send-for-approval`, `dismiss`) | Optimistic for `dismiss`; pessimistic (awaits `2xx`) for `execute` per Principle 10 |

There is deliberately **no `DELETE /api/v1/ai/conversations/{id}`.** `ai_conversations` carries no `deleted_at` column and its `status` enum is exactly `active`/`archived` — a conversation is never hard-deleted, only archived, because `docs/api/API_LOGGING.md` retains full AI conversation content for 180 days and a hashed/summarized form for three years for AI-decision dispute resolution. The screen's own "Delete conversation" affordance in the history rail's row menu is, precisely, the archive mutation above with a confirmation copy that says so plainly ("Archived conversations are hidden from your history but kept for audit. This cannot be fully undone from here.") rather than implying data destruction that does not actually occur.

A second naming reconciliation worth stating plainly: the `ai_messages` table persists an assistant turn's citations in a column named `citations` (`docs/ai/AI_FINANCE_OS.md`'s DDL), while the API response and every worked example in `docs/ai/agents/CEO_AGENT.md`/`docs/ai/AI_COMMAND_CENTER.md` serialize the identical data under the key `sources`. The frontend's types follow the API response shape, not the raw column name — `AiMessage.sources`, never `AiMessage.citations` — because a Laravel API Resource is exactly the layer responsible for presenting a friendlier field name than its backing column, and this screen's TypeScript types are generated from the OpenAPI contract (`docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 7`), not hand-copied from the DDL.

```tsx
// app/(app)/assistant/[conversationId]/page.tsx
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { aiChatKeys } from "@/lib/api/query-keys";
import { AssistantView } from "@/components/ai/assistant-view";
import { ConversationHistoryList } from "@/components/ai/conversation-history-list";
import { ChatSkeleton } from "@/components/ai/chat-skeleton";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // company- and user-scoped; never a candidate for the Data Cache

export default async function ResumedAssistantPage({ params }: { params: { conversationId: string } }) {
  const queryClient = getQueryClient();

  await Promise.all([
    queryClient.prefetchInfiniteQuery({
      queryKey: aiChatKeys.conversations(),
      queryFn: ({ pageParam }) => apiServer.get("/ai/conversations", { params: { cursor: pageParam, sort: "-last_message_at" } }),
      initialPageParam: null,
    }),
    queryClient.prefetchInfiniteQuery({
      queryKey: aiChatKeys.messages(params.conversationId),
      queryFn: ({ pageParam }) => apiServer.get(`/ai/conversations/${params.conversationId}/messages`, { params: { cursor: pageParam } }),
      initialPageParam: null,
    }),
  ]);

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="grid h-dvh grid-cols-[280px_1fr]">
        <Suspense fallback={<ChatSkeleton part="history" />}>
          <ConversationHistoryList activeConversationId={params.conversationId} />
        </Suspense>
        <Suspense fallback={<ChatSkeleton part="transcript" />}>
          <AssistantView conversationId={params.conversationId} />
        </Suspense>
      </div>
    </HydrationBoundary>
  );
}
```

```ts
// lib/api/query-keys.ts (AI Chat additions)
export const aiChatKeys = {
  all: ["ai-chat"] as const,
  conversations: () => [...aiChatKeys.all, "conversations"] as const,
  messages: (conversationId: string) => [...aiChatKeys.all, "messages", conversationId] as const,
};
```

```tsx
// components/ai/assistant-view.tsx ("use client")
import { useChat } from "ai/react";
import { useMemo } from "react";
import { useInfiniteQuery } from "@tanstack/react-query";
import { aiChatKeys } from "@/lib/api/query-keys";
import { apiClient } from "@/lib/api-client";
import { ChatMessageList } from "@/components/ai/chat-message-list";
import { ChatComposer } from "@/components/ai/chat-composer";
import { SuggestedPromptChips } from "@/components/ai/suggested-prompt-chips";

interface AssistantViewProps {
  conversationId?: string;      // absent on the fresh/unsaved composer
  seedContext?: { panel: string; sources?: AiSource[] }; // set by the docked variant only
}

export function AssistantView({ conversationId, seedContext }: AssistantViewProps) {
  const history = useInfiniteQuery({
    queryKey: aiChatKeys.messages(conversationId ?? "draft"),
    queryFn: ({ pageParam }) =>
      apiClient.get(`/api/v1/ai/conversations/${conversationId}/messages`, { params: { cursor: pageParam } }),
    enabled: Boolean(conversationId),
    initialPageParam: null,
    staleTime: Infinity, // past turns are immutable; useChat appends new ones locally
  });

  const initialMessages = useMemo(
    () => history.data?.pages.flatMap((p) => p.data).reverse() ?? [],
    [history.data],
  );

  const { messages, input, handleInputChange, handleSubmit, status, data, stop, reload } = useChat({
    api: "/api/ai/chat",
    id: conversationId,
    initialMessages,
    body: { conversation_id: conversationId ?? null, context: seedContext ?? null },
    sendExtraMessageFields: true, // carries agent_code, confidence, sources, decision_id on each assistant turn
  });

  return (
    <div className="flex h-full flex-col">
      <ChatMessageList messages={messages} status={status} streamingData={data} onRetry={reload} />
      <SuggestedPromptChips lastMessage={messages.at(-1)} seedContext={seedContext} onSelect={(q) => handleSubmit(undefined, { data: { prefill: q } })} />
      <ChatComposer
        value={input}
        onChange={handleInputChange}
        onSubmit={handleSubmit}
        onStop={stop}
        isStreaming={status === "streaming" || status === "submitted"}
      />
    </div>
  );
}
```

```ts
// app/api/ai/chat/route.ts — unchanged proxy shape from FRONTEND_ARCHITECTURE.md, reused verbatim
export async function POST(req: Request) {
  const accessToken = req.cookies.get("qayd_at")?.value;
  const upstream = await fetch(`${process.env.QAYD_API_URL}/api/v1/ai/chat`, {
    method: "POST",
    headers: { Authorization: `Bearer ${accessToken}`, "Content-Type": "application/json" },
    body: await req.text(),
  });
  return new Response(upstream.body, { headers: { "Content-Type": "text/event-stream" } });
}
```

**Realtime.** The stream itself carries the answer, so this screen does not subscribe to Reverb for message content — but it does subscribe to `private-company.{id}.ai-jobs`, the identical channel `docs/frontend/AI_COMMAND_CENTER.md` (frontend) names for "typing/thinking status," to render the pre-first-token "thinking" state and, for a long-running multi-agent fan-out, which specialist is currently executing (see States). This is the same shared `RealtimeProvider` connection every other screen reuses (`docs/frontend/AI_COMMAND_CENTER.md` (frontend) → Performance's "one shared connection" rule) — opening `/assistant` never opens a second WebSocket.

**Conversation titling.** `ai_conversations.title` is nullable. Until the CEO Assistant's own low-cost intent-classification tier (`CEO_AGENT.md → Reasoning & Prompt Strategy`'s "fast tier") produces a concise title after the first exchange and `PATCH`es it server-side, the history rail displays a client-truncated preview of the first user message as a placeholder label — never "Untitled" or a bare timestamp, and never a client-invented title that could drift from what the server eventually persists.

# Interactions & Flows

**Starting a conversation.** Landing on `/assistant` (no `conversationId`) shows an empty transcript, a short greeting line, and `SuggestedPromptChips` seeded from nothing more than time-of-day and the caller's role (a CFO sees "How's cash looking this week?"; a Warehouse Employee sees "Which SKUs are low?"). No `ai_conversations` row is created by simply opening the page — the first row is created server-side as a side effect of the first `POST /api/v1/ai/chat` call that omits a `conversation_id`, exactly mirroring how a journal entry draft is never persisted until a human actually saves it. This keeps the history rail free of empty, abandoned threads from a user who opened the screen and left.

**Sending a message.** Submitting the composer optimistically appends the user's bubble (this is always safe, per Principle 10 — a user's own words are never rolled back) and immediately shows the thinking indicator (three `accent-subtle` dots, the platform's calm AI-thinking pattern, never a chat-app-style ellipsis) while `useChat` awaits the first token. As the orchestrator fans out — per `CEO_AGENT.md`'s Reasoning & Prompt Strategy graph — the thinking indicator is replaced, turn by turn, by short status lines sourced from the `ai-jobs` channel ("Checking Treasury Manager…", "Checking Payroll Manager…"), which then collapse into the `ToolCallTrace` disclosure once the answer begins streaming. Tokens render progressively into the assistant bubble; the message is not "final" (confidence badge, citations, and any actions do not render) until the stream's terminal frame arrives.

**Tool-use transparency.** Every assistant turn that involved a specialist fan-out renders an `AgentAttributionRow` byline ("CEO Assistant · via Treasury Manager, Payroll Manager") directly under the reply, sourced from that turn's `routed_agents[]` (`CEO_AGENT.md`'s Outputs contract). A collapsed-by-default `ToolCallTrace` beneath it — opened via a small "Show work" toggle — lists each routed agent with its own sub-confidence (via `ConfidenceBadge`) and, where the underlying `ai_messages.tool_calls` payload names a specific tool invocation, the tool's plain-language description ("Checked the reconciled cash position as of today", not the raw `get_cash_position` function name) rather than a developer-facing function signature. A single-domain, no-fan-out reply (autonomy row 1 in `CEO_AGENT.md`) renders no `AgentAttributionRow` at all — the trace only appears when there is genuinely something to disclose, matching the platform's stance that provenance is informative, not decorative.

**Citations.** Numbered `CitationChip` markers (`[1]`, `[2]`, …) are inserted into the assistant's rendered prose at the position the API's `sources[]` array indicates, each one a small `Popover` trigger (hover on desktop, tap on touch) showing the source's `type`, `label`, and — where `type` is `table_row` or `ai_decision` — a "View" link that navigates to the underlying record (`/sales/invoices/3402`, `/payroll/payroll-runs/204`). A citation of `type: "document"` opens the document's own viewer in a `Sheet` rather than navigating away from the conversation. Citations never point at an `ai_memory` row — per `CEO_AGENT.md`'s explicit rule, "retrieved memory is always context, never a citable source in its own right" — so a reply that drew on a remembered policy threshold or a recognized seasonal pattern discloses that fact only in its `reasoning` prose ("this matches the Q4 inventory pattern from the last two years"), never as a numbered citation a user could click through to a memory record.

**Suggested prompts.** `SuggestedPromptChips` regenerates after every completed assistant turn, derived from that turn's own content (a reply about payroll surfaces "Show me the full run breakdown"), never a static, screen-independent list. Selecting a chip submits it exactly as if typed, through the identical `handleSubmit` path — a chip is a shortcut into the same composer, not a separate action type.

**Voice input.** `VoiceInputButton` is a tap-to-start/tap-to-stop toggle on this dedicated chat screen (not the strict hold-to-talk the AI_COMMAND_CENTER's ambient Voice Assistant panel uses for a hands-off moment — a user already looking at `/assistant` is, by definition, looking at a screen). Recording streams audio to Cloudflare R2 and shows the live transcript-while-speaking beneath the mic button so the user can visually confirm what will be sent before it submits, per `docs/ai/AI_COMMAND_CENTER.md`'s Voice Assistant transcript-confirmation rule; the composer's text field and the mic are always both present and both fully functional — voice is a second input path, never a mode that disables typing. A reply generated from a voice turn renders identically to a text turn (same `AiCardShell`, same citations, same actions) with an additional inline "▶ Play" control that synthesizes the reply back to speech on demand — playback is opt-in per message, never automatic, so a shared office does not hear every answer read aloud by default.

**Proposals and inline approve/reject.** Two distinct shapes exist on this screen, and neither one lets the CEO Assistant itself approve or execute anything, matching `CEO_AGENT.md`'s Autonomy Level rows 8 and 9 exactly:

1. **A freshly synthesized recommendation** (the reply's own `suggested_actions`/a graduated `recommended_action`, e.g. "I'd suggest categorizing these 40 transactions as Office Supplies — 91% confidence") renders through `RecommendationCard`'s three-button pattern (`Do it` / `Send for approval` / `Dismiss`), identical to `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s Ask AI panel rule that "any answer carrying an implied action renders the identical three-button set." `Do it` renders only when the API's `can_execute_directly` is `true` — never for anything touching `bank.transfer`, `payroll.approve`, `tax.submit`, a permission change, or a delete/void, which structurally always carry `can_execute_directly: false` regardless of confidence.
2. **A surfaced *existing* pending approval** ("what's waiting on my approval today?") embeds the real `ApprovalCard` component inline in the transcript — not a chat-native lookalike — because the underlying action (Approve/Reject) is exactly the same authoritative, `ai.approve`-gated mutation the Approval Center itself calls. Before rendering its buttons, the embedded card re-fetches `GET /api/v1/approvals/{id}` live (never trusting the payload the chat stream returned, which may already be stale by the time a human reads it), exactly mirroring `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s Edge Cases rule for its own Approval Center panel. A user without `ai.approve`, or who is not the assigned approver for the item's current step, sees the same card in its read-only presentation — never an enabled button that would 403 on click.

In both shapes, the CEO Assistant's own guardrail holds exactly as `CEO_AGENT.md` states it: the assistant "narrates the existence of the pending action and links to it" — it is the *component*, not the *agent*, that lets a properly-permissioned human act, and that component is the same one every other screen in the product already uses.

**Multi-turn context and company memory.** Every reply within a resumed conversation is generated with that thread's own prior turns as context (`CEO_AGENT.md`'s "conversation history… windowed to a bounded number of recent turns plus a rolling summary of older ones") — the frontend does no windowing itself; it renders whatever the API returns and never truncates history from the user's own view, only from what it sends as model context. A reply that drew on `ai_memory` (a stored preference, a recognized pattern, a policy threshold) discloses that plainly in its prose, in the same voice a competent colleague would use ("recalling your Q4 seasonal pattern from the last two years…"), never as a separate "Memory used" badge competing with the confidence badge for attention — memory is disclosed through language, confidence is disclosed through the badge, and the two are not conflated.

**Managing history.** The history rail's per-row overflow menu offers **Rename** (inline edit, `PATCH .../conversations/{id}` on blur/Enter), **Archive** (see Data & State), and **Open in full page** (only shown in the docked variant, navigating to `/assistant/{id}`). **New conversation** always starts a genuinely empty composer — it never carries the previous thread's context forward, matching `CEO_AGENT.md`'s Autonomy row 13 ("always creates a new `ai_conversations` row; never carries context across the switch").

# AI Integration

This screen is the single densest concentration of the platform's AI-integration contract (`docs/frontend/FRONTEND_ARCHITECTURE.md → AI Integration Layer`) in the entire product, because every one of its messages *is* an AI-authored surface. Four rules govern it beyond what any other screen needs to additionally observe.

**Confidence is the minimum of what it depends on, never an average.** Per `CEO_AGENT.md`'s Reasoning & Prompt Strategy, a synthesized reply's confidence is the lowest confidence among every specialist whose output was load-bearing to the claim — a cash-runway answer blending a 98-confidence Treasury Manager balance with a 62-confidence Forecast Agent projection is reported at 62, and `ToolCallTrace`'s expanded view names which half is the weaker one. `ConfidenceBadge` never averages, rounds up, or otherwise manufactures a higher number than the weakest contributor; this is a product guarantee, not a display nicety, and no component on this screen may recompute a blended figure independently from the API's own `confidence`.

**Disagreement is disclosed, never silently resolved.** If two routed specialists return conflicting figures for the same fact, `CEO_AGENT.md`'s Aggregator "detects and preserves disagreement rather than resolving it silently." The frontend's obligation is to render both views rather than collapse them: `ToolCallTrace`, in this case, expands automatically (not collapsed-by-default) and shows a compact two-row comparison — agent, claim, confidence — directly beneath the prose, and the message's own reasoning text explicitly names the conflict ("Treasury Manager and the bank feed disagree on yesterday's closing balance by KWD 340 — the bank feed's figure is 12 hours fresher"). A user is never shown a single number with the disagreement quietly averaged away underneath it.

**A low-confidence or unanswerable question is stated as such, never dressed up.** Matching `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s Edge Cases rule verbatim, a reply the orchestrator cannot answer confidently from any available tool renders as plain prose with **no confidence badge at all** — not a badge showing an artificially low number, because the failure mode here is "no answer," categorically different from "a low-confidence answer." Below a blended confidence of 70, `CEO_AGENT.md`'s system-prompt skeleton requires the assistant to say so explicitly and offer to route to a human or specialist for deeper analysis; the frontend renders this as a plain-language caveat line directly in the bubble ("I'm not fully confident in this — want me to have the Auditor take a closer look?") rather than a generic low-confidence chrome treatment.

**Voice replies with an implied action require an explicit spoken confirmation step**, per `docs/ai/AI_COMMAND_CENTER.md`'s Voice Assistant behavior — "I can categorize those 214 transactions now, say 'confirm' to proceed" — rather than acting on the first utterance. On this screen specifically, a voice-originated turn that would otherwise render `RecommendationCard`'s `Do it` button instead renders it in a pending "say or tap confirm" state until the follow-up confirmation arrives (spoken or tapped), because a misheard word in a hands-free moment is a materially higher-cost mistake than a misclick, and this screen's mic button makes hands-free interaction routine rather than exceptional.

```json
// A synthesized reply's payload shape — reused verbatim from CEO_AGENT.md's own contract,
// the assistant-role row this screen renders as one ChatMessageBubble
{
  "message_id": 55210,
  "conversation_id": 8831,
  "role": "assistant",
  "agent_code": "CEO_ASSISTANT",
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
  "suggested_actions": [
    { "label": "Review Payroll Run #204", "action": "navigate", "target": "/payroll/payroll-runs/204" }
  ],
  "requires_approval": false,
  "decision_id": null
}
```

`ChatMessageBubble` renders this payload through a fixed slot order — prose, then `AgentAttributionRow` (only when `routed_agents.length > 0`), then `ReasoningDisclosure` (collapsed), then inline `CitationChip`s woven into the prose at each `sources[]` position, then `RecommendationCard`'s action row (only when `requires_approval` or `suggested_actions` is populated) — so a dense, multi-agent answer never reads as a wall of undifferentiated text; each layer of trust (what it says, who said it, why, and what you can do about it) is visually and structurally distinct, exactly the AI Command Center's own "colored border plus badge... never indistinguishable from a number Laravel has already posted" standard.

# States

| State | Trigger | Rendering |
|---|---|---|
| Empty / fresh | `/assistant` with no messages yet | Greeting line, role-aware `SuggestedPromptChips`, composer focused |
| Thinking | Message submitted, no token yet | Three `accent-subtle` pulsing dots (`docs/frontend/DESIGN_LANGUAGE.md`'s calibrated AI-thinking pattern), composer disabled except **Stop** |
| Routing / fan-out | `ai-jobs` channel reports specialist(s) executing | Thinking dots replaced by short status lines ("Checking Treasury Manager…"), still pre-token |
| Streaming | Tokens arriving | Prose grows progressively with a blinking-caret-free trailing indicator; confidence badge, citations, and actions withheld until the terminal frame |
| Complete | Terminal SSE frame received | Full `ChatMessageBubble` per the AI Integration slot order; composer re-enabled, `SuggestedPromptChips` regenerate |
| Low confidence / no answer | Blended confidence < 70, or no tool could answer | Plain prose, explicit caveat line, no confidence badge, an offered hand-off to a human/specialist |
| Voice recording | Mic tapped | Waveform + live partial transcript beneath the composer; tap again or silence-timeout submits |
| Voice pending confirmation | A voice turn implies an action | `RecommendationCard`'s `Do it` renders in a "say or tap confirm" pending sub-state, not yet actionable |
| Error — send failed | Network drop, upstream 5xx mid-stream | The user's own message is preserved (never silently dropped); an inline "Couldn't get a response — retry" affordance replaces the assistant bubble |
| Error — tool timeout | One routed specialist times out inside its own budget | The reply still renders from whichever specialists *did* respond, with a plain note ("Inventory Manager didn't respond in time — this answer excludes stock data") rather than failing the whole turn |
| History loading | History rail's first page in flight | Five placeholder row-shaped shimmer blocks, date-grouped shape preserved |
| History empty | A brand-new user, zero past conversations | "No conversations yet — ask your first question below," no illustrated character, matching `docs/frontend/DESIGN_LANGUAGE.md`'s empty-state stance |
| Reconnecting | Realtime `ai-jobs` channel drops | A small, static topbar dot only (never a banner) per the platform's "never alarming" rule; an in-flight stream itself uses SSE's own reconnect, not Reverb, so a Reverb drop never interrupts an already-streaming reply |

Every skeleton reuses the loaded state's exact layout classes so the transcript never pops or reflows between loading and loaded, per `docs/frontend/RESPONSIVE_DESIGN.md → Loading and skeleton reflow`.

# Responsive Behavior

| Region | Desktop / Ultra Wide (`xl`+) | Tablet (`md`–`lg`) | Mobile (`base`–`sm`) |
|---|---|---|---|
| History rail | `280px`, always visible, expanded | Collapses to a toggle button; opens as a `Sheet` overlay | Behind a hamburger in the page header; full-screen `Sheet` overlay |
| Transcript | Centered, `max-w-3xl` | Full width minus rail toggle | Full width, edge-to-edge padding `space-md` |
| Composer | Pinned to transcript bottom, `radius-lg`, multi-line auto-grow up to 6 lines | Same | Sticky above the safe-area inset; auto-grow capped at 4 lines before internal scroll |
| Mic button | Inline, left of the text field | Same | Enlarged to the platform's 44px touch-target floor, prominent given voice's higher value on mobile |
| Suggested prompts | Full chip row above composer | Same, may wrap to two rows | Horizontally scrollable single row, not wrapped, to preserve composer visibility |
| `ToolCallTrace` / citation detail | `Popover` on hover | `Popover` on tap | `Sheet` instead of `Popover` — a hover-shaped popover is the wrong affordance on touch, per `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 4` |
| Docked variant | Persistent AI Rail, `360px`, `3xl`+ (1920px) only | Floating trigger, opens a `Sheet` | Floating trigger, opens a full-screen `Sheet` |

Touch targets across the composer (mic, send, stop) and every message-level action (Approve/Reject, `Do it`/`Send for approval`/`Dismiss`) follow the platform-wide 44px minimum with an 8px gap, identical to `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s stated rule for its own Approval Center — a mis-tap approving a surfaced payroll release from a phone carries the same real consequence as one on desktop. The transcript's own scroll container uses `overscroll-behavior: contain` so an aggressive mobile scroll-to-refresh gesture never accidentally triggers the browser's native pull-to-refresh while scrolling up through history. Virtualization (`@tanstack/react-virtual`, per `docs/frontend/DESIGN_LANGUAGE.md → Virtualization & interaction`) activates once a resumed conversation exceeds roughly 60 messages, using each message's own measured (not estimated) height, since chat bubbles vary far more in height than a table row.

# RTL & Localization

This screen is authored and reviewed in Arabic before it is considered done, not mirrored at the end, per `docs/frontend/DESIGN_LANGUAGE.md → Design Principle 6`. `dir="rtl"` is set once on `<html>` by the root layout; no chat component toggles direction itself. Every gap, padding, and alignment value — the composer's mic-then-input-then-send order, `CitationChip`'s inline position within right-to-left prose, `ToolCallTrace`'s disclosure chevron, the history rail's slide-in edge — uses Tailwind's logical utilities (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively, per the platform-wide ESLint restriction `docs/frontend/DESIGN_LANGUAGE.md → RTL mirroring` enforces.

Three things never mirror, identical to every other AI-authored surface in the product:

- **Every monetary figure, percentage, confidence score, and citation-adjacent number renders inside a `dir="ltr"` span.** A reply's `KWD 184,220.0000` and its `96%` confidence badge read left-to-right even mid-sentence in a fully Arabic reply, via the same `Amount`/`AmountCell` primitives every table in the product uses, with `numberingSystem: "latn"` forced regardless of locale. This matters more on this screen than almost anywhere else in the product, because a chat reply is exactly where a monetary figure most often sits embedded inside a full natural-language sentence rather than isolated in its own table cell.
- **Directional icons flip; meaning-bearing icons don't.** `ToolCallTrace`'s expand chevron and the history rail's "open" arrow mirror via `rtl:rotate-180`; the confidence badge's icon, a citation's marker, and the CEO Assistant's geometric mark never do.
- **AI-authored prose is never machine-translated by the frontend.** A reply's `content` arrives already localized per the API's `Accept-Language` content-negotiation contract (`docs/frontend/FRONTEND_ARCHITECTURE.md → Streaming AI chat`); the client's own localization responsibility on this screen is limited to chrome — composer placeholder, suggested-prompt chip labels, empty-state copy, the "Show work" toggle, history-rail group labels ("Today"/"اليوم") — never the model's own words.

Voice input specifically is tuned for both English and Gulf-dialect Arabic speech-to-text (`docs/ai/AI_COMMAND_CENTER.md`'s Voice Assistant), and the live transcript-while-speaking preview renders in whichever script the speech engine detects, independent of the UI's own active locale — a bilingual user can speak Gulf Arabic into an English-locale interface and see an accurate Arabic transcript before it submits.

Arabic microcopy for this screen, authored directly rather than translated post hoc, per `docs/frontend/DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief:

| Context | English | Arabic |
|---|---|---|
| Composer placeholder | Ask anything about your business | اسأل أي شيء عن أعمالك |
| New conversation | New conversation | محادثة جديدة |
| Thinking indicator (screen-reader text) | Thinking… | جارٍ التفكير… |
| Tool-use disclosure toggle | Show work | عرض التفاصيل |
| Low-confidence caveat | I'm not fully confident in this | لست واثقًا تمامًا من هذه الإجابة |
| Voice confirmation prompt | Say "confirm" to proceed | قل "تأكيد" للمتابعة |
| History group label | Today / Yesterday / This week | اليوم / أمس / هذا الأسبوع |
| Archive confirmation | Archived conversations are hidden from your history but kept for audit | المحادثات المؤرشفة تختفي من سجلك لكن تُحفظ لأغراض التدقيق |
| Empty history | No conversations yet — ask your first question below | لا توجد محادثات بعد — اطرح سؤالك الأول أدناه |

# Dark Mode

Dark mode is system-aware, user-overridable, and persisted, driven by the platform's shared `class`/`data-theme="dark"` strategy — no component on this screen implements its own theme logic. `AiCardShell`'s border and badge, `ConfidenceBadge`'s accent, and `CitationChip`'s marker all resolve through the same token set every other AI surface uses, recalibrated for dark mode rather than mechanically brightened, per `docs/frontend/DARK_MODE.md`'s "the accent gets lighter, not more saturated" rule — a 96%-confidence reply's badge reads with the same calm authority in both themes rather than glowing unnaturally against a dark canvas.

Two rules specific to a chat surface's density of AI content:

- **The user's own message never borrows AI styling.** A user bubble is `ink-3` fill (dark: the calibrated dark `ink-3`, which is *lighter* than the dark canvas beneath it, per the platform's "elevation gets lighter, not darker in dark mode" rule) with no accent border in either theme — only assistant turns earn the `AiCardShell` treatment, so a user scrolling a long transcript can distinguish "mine" from "the AI's" by shape alone before reading a word, in both themes.
- **The thinking indicator and streaming caret are toned down, not inverted.** The three pulsing dots use `accent-subtle` at roughly half light-mode opacity in dark mode, matching `docs/frontend/DARK_MODE.md → Surfaces & Elevation In Dark`'s general shadow/glow de-intensification rule — a naive dark-mode flip that brightens a "thinking" indicator would read as more urgent than the platform's calm, never-alarming motion principle permits.

The mic button's recording-state pulse (a soft ring animation while listening) is likewise re-tuned per theme rather than color-inverted, and no icon or the CEO Assistant's geometric mark is ever `filter: invert()`-flipped between themes — both ship as theme-aware assets resolving through `currentColor`, per `docs/frontend/DARK_MODE.md → Images/Logos In Dark`.

# Accessibility

This screen targets the same WCAG 2.2 AA floor as every other QAYD screen, with particular weight on the three areas a streaming, voice-capable chat surface stresses hardest: live regions, keyboard operability, and unambiguous number reading.

**Landmarks and roles.** The transcript renders inside a single `<section aria-label="Conversation">` with `role="log"` — the correct ARIA pattern for a chat history, distinct from `role="feed"` (which implies independently-scrollable, loadable items rather than an append-only conversation) — so assistive technology correctly announces new content as it arrives without re-reading the entire history. Each turn is a `<article>` with an accessible name combining role and a short excerpt ("Your message: Why did our KWD balance…", "CEO Assistant's reply: Cash is sufficient…"), so a screen-reader user browsing by landmark or by heading can jump turn-to-turn without linearly reading every prior message.

**Live regions, calibrated to avoid noise.** Matching `docs/frontend/AI_COMMAND_CENTER.md` (frontend)'s explicit rule for its own Ask AI panel: the composer's send action sets `role="status"`/`aria-busy="true"` once when a reply begins ("Thinking…" then "Answering…"), and the full, complete text is announced once via `aria-live="polite"` when streaming finishes — **never token-by-token**, which would make the screen unusable by voice. A tool-use status update ("Checking Treasury Manager…") during a long fan-out is similarly announced at most once per specialist invoked, not on every internal state tick.

**Keyboard.** The composer is reachable and focused by default on page load; `Enter` sends, `Shift+Enter` inserts a newline, `↑` with an empty composer edits the just-sent message (standard, low-risk chat-composer convention), and `Esc` closes the docked `Sheet` variant without navigating away. Every citation marker, `ToolCallTrace` toggle, and message-level action (`Do it`/`Send for approval`/`Dismiss`, embedded Approve/Reject) is a real, `Tab`-reachable `<button>` — never a `<div>` with a click handler — with `aria-expanded` on every disclosure and `aria-describedby` linking a reasoning-dependent action to the reasoning text that justifies it, so the "why" is available before the action is taken, matching `docs/frontend/ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`. `⌘K` opens the Command Palette from this screen exactly as from any other; its "Ask AI" action, when triggered from within `/assistant` itself, focuses the existing composer rather than opening a redundant second instance.

**Amounts and confidence must be read unambiguously.** Per `docs/frontend/ACCESSIBILITY.md → Screen Readers`'s amount-reading rules, `KWD 184,220.0000` is announced as a single unit with its currency code, not as a bare number followed by an unrelated "KWD" three words later — the same `Amount` primitive every financial table uses guarantees this here too. `ConfidenceBadge`'s percentage and qualitative band ("high confidence"/"low confidence") are both real text nodes read by assistive technology, never conveyed through a bare progress-bar `width` alone.

**Voice input has a full, equally capable non-voice path.** The mic button is an enhancement, never a requirement — every capability voice offers (asking a question, confirming an implied action) has an identical typed/tapped equivalent, and a user who has never touched the mic experiences no degraded functionality, only a longer path to the same answer.

# Performance

`/assistant` is held to a stricter *interaction* budget than most list/detail screens, because its entire value proposition is a fast, uninterrupted conversational loop rather than a fast initial paint of static content — the meaningful metric here is time-to-first-token and per-token render smoothness, not raw LCP.

**Streaming without re-render thrash.** `useChat`'s token-by-token updates are batched at the React level (the Vercel AI SDK's own internal scheduling) rather than triggering a full `ChatMessageList` re-render per token; only the actively-streaming `ChatMessageBubble` re-renders on each chunk, every prior, already-complete message stays referentially stable. `ToolCallTrace` and `CitationChip` are both `memo`-wrapped for exactly this reason — a 40-message resumed conversation must not re-evaluate 39 already-settled bubbles every time the 40th receives a new token.

**Code splitting.** The voice-recording module (audio capture, waveform rendering, the upload-to-R2 client) is loaded via `next/dynamic` only on the first mic interaction, never in the screen's base bundle — a user who never touches voice pays zero bytes for it, consistent with `docs/frontend/FRONTEND_ARCHITECTURE.md → Code splitting`'s per-feature chunking rule. The history rail's virtualization dependency similarly loads only once a resumed conversation crosses the ~60-message threshold that activates it.

**RSC and caching.** Because every byte on this screen is scoped by the caller's own `user_id` and `X-Company-Id`, both route segments set `export const dynamic = "force-dynamic"` and `fetchCache = "default-no-store"` unconditionally, identical to the Command Center's own rule — nothing here is a candidate for the Data Cache or Partial Prerendering. The history rail's `staleTime: 10_000` with `refetchOnWindowFocus` (see Data & State) means returning to `/assistant` after time away shows a fresh list without a jarring full-page reload.

**Realtime cost control.** The `ai-jobs` subscription this screen uses is the same shared `RealtimeProvider` connection every other screen already holds open — no additional WebSocket is opened by visiting `/assistant`, and the docked variant's `Sheet`/rail reuses that same connection rather than opening its own.

**Web Vitals.** INP is watched specifically on the composer's send action and the mic's start/stop toggle, since those are this screen's two highest-frequency interactive surfaces; a regression here is tracked against a company-size-band baseline exactly as `docs/frontend/FRONTEND_ARCHITECTURE.md → Web Vitals` specifies platform-wide.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| User sends a second message while the first is still streaming | The composer disables `Send` (still allows typing) until the in-flight stream's terminal frame arrives, or the user taps **Stop** to cancel it first — never two concurrent streams into the same conversation, which would interleave tokens from two turns unpredictably |
| Browser back button mid-stream | The stream continues server-side and is not lost — navigating away and returning to the same conversation re-fetches its message history, which now includes the completed turn, rather than replaying the stream from where it left off |
| Company switch while `/assistant` is open | `queryClient.clear()` and `router.refresh()` fire together per `docs/frontend/FRONTEND_ARCHITECTURE.md → Company switching`; per `CEO_AGENT.md`'s Autonomy row 13, the active conversation is abandoned (never carried across the tenant boundary) and the screen lands on a fresh, empty `/assistant` under the new company |
| A role/permission change lands mid-conversation (e.g. `ai.approve` revoked between page load and a later turn) | The next inline `ApprovalCard` embed re-checks the live permission via its own `GET /api/v1/approvals/{id}` re-fetch before rendering actionable buttons — a stale client-side permission snapshot is never trusted for an approval affordance, matching the platform's standard RBAC-courtesy-in-client/hard-boundary-on-server posture |
| Two devices/tabs open the same conversation | Both stream independently against the same `conversation_id`; a message sent from one appears in the other only on its next `messages` re-fetch (there is no live cross-tab message push on this screen — the history query's `staleTime`/focus-refetch, not a Reverb broadcast, is what reconciles them), so a user should expect a brief lag rather than instant mirroring between tabs |
| Voice transcription is wrong or garbled | The live transcript-while-speaking preview lets the user catch this before submitting; after submission, a visibly wrong transcript is corrected the same way a typo would be — by typing a follow-up correction — never by a "was this right?" post-hoc confirmation dialog that would slow every voice turn to protect against an occasional bad one |
| An SSE connection drops mid-stream (network blip, not a server error) | The client attempts a bounded automatic reconnect using the stream's own resumption; if reconnection fails, the partial reply is kept visible with a "Response was interrupted — retry" affordance rather than discarded, and retrying re-sends the same user turn rather than duplicating it (idempotency key reused per `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 9`) |
| A citation's underlying record has since been deleted/voided/reversed | The `CitationChip`'s "View" link still opens, showing that record's own terminal/void state rather than a broken 404 — a citation is a historical reference to what was true when the reply was generated, and the record's own detail page is responsible for showing its current status |
| An extremely long-running resumed conversation (hundreds of turns) | Older turns are windowed out of the model's own context (per `CEO_AGENT.md`) but never out of what the user can scroll back to see — the frontend's virtualized history remains fully browsable even for turns the orchestrator itself no longer "remembers" in full detail, and a reply that needs older context the window dropped says so rather than fabricating a memory of it |
| Renaming a conversation to an empty string | The rename input rejects an empty title client-side (falls back to the placeholder-truncation behavior from Data & State) before ever issuing the `PATCH`, since `ai_conversations.title` accepts `NULL` but the rename affordance itself should never round-trip an empty string as a "successful" rename |
| Archiving the conversation currently open in the transcript | The transcript stays fully readable and interactive after archiving — archiving only removes the thread from the active history list, per its `status` semantics, never truncates or locks a conversation a user is mid-read of |
| Opening the docked variant from a screen while `/assistant` is already open in another tab | Both operate as independent conversations by default (the dock does not "attach" to whatever full-page conversation happens to be open elsewhere) unless the dock was explicitly opened via that resumed conversation's own "Open in full page" round trip, which is the one path that shares a `conversation_id` between the two renderings |

# End of Document
