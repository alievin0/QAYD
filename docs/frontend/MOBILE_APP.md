# Mobile Experience — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: MOBILE_APP
---

# Purpose

QAYD ships two mobile-capable surfaces from one shared backend contract: a **native Flutter application** for iOS, Android, and tablet, per `docs/foundation/TECH_STACK.md`'s fixed Mobile row ("Flutter — Reason: Single codebase — Android — iOS — Tablet"), and the same **Next.js 15 web client** documented throughout the rest of `docs/frontend/`, rendered responsively down to a 360px viewport per `docs/frontend/RESPONSIVE_DESIGN.md`. This document is the one place that specifies the *mobile experience* as a product and as an engineering surface: the journeys a user reaches for a phone to complete, how the two surfaces divide labor, and the mobile-specific engineering — native shell navigation, offline behavior, push notifications, biometric-gated approvals, camera capture, and performance budgets — that has no equivalent in the desktop-oriented documents this folder otherwise contains.

It does not re-derive what `docs/frontend/README.md` already states about ownership: "The frontend documented here is the web client... The Flutter mobile app and the third-party/partner integrations are separate surfaces owned by their own documentation trees." That boundary still holds for implementation depth — this document does not specify Flutter's widget tree file-by-file the way `docs/frontend/FRONTEND_ARCHITECTURE.md` specifies the Next.js App Router, and a future `docs/mobile/` tree (mirroring the `mobile/lib/{widgets,screens,services,providers,models,utils,themes,tests}` structure already reserved in `docs/foundation/PROJECT_STRUCTURE.md`) is the right home for that file-by-file depth once the native codebase matures past its first slice. What this document owns, precisely, is everything a frontend engineer, a product designer, or an AI coding agent needs in order to answer "how does this behave on a phone" for any decision already made elsewhere in the platform: which of the ten primary modules ship natively today, how a push notification about a bank transfer resolves into a biometric prompt before it can be approved, why a stock count survives a dead cellular signal in a warehouse while a payroll release never even attempts to, and how the founder's editorial, near-monochrome, one-accent design language survives the trip to a 375px screen without becoming either cramped or cartoonish.

Two audiences read this document for different reasons, extending `docs/frontend/README.md`'s own "two audiences share one shell" framing onto a second axis. An **engineer building the Flutter app** needs the API contract (the identical `/api/v1` surface, the same response envelope, the same permission keys, and the `qayd_sdk` Dart package generated from the same OpenAPI 3.1 specification the web's `openapi-typescript` types are generated from, per `docs/api/API_ARCHITECTURE.md`'s SDK table), the native platform integrations a compiled app alone can offer (Keychain/Keystore token custody, `local_auth` biometric gates, FCM/APNs push, camera capture), and the offline/sync model a browser tab never has to solve. An **engineer maintaining the responsive web client** needs to know exactly where "responsive" stops and "native-only" begins — which journeys the installed PWA intentionally does not attempt, and which ones it must, because a Sales Employee standing in a client's warehouse on a work-issued Android tablet with no App Store access is still a real QAYD user today, not an edge case to defer.

Both readers share one governing constraint, restated here because it is the reason this document exists at all rather than being folded into `RESPONSIVE_DESIGN.md`: **QAYD has exactly one product, rendered on exactly one shared, versioned API, wearing two skins.** Neither skin is ever permitted to compute a business rule, hide a permission boundary the server does not also enforce, or let an approval feel one tap closer to auto-executing than `docs/frontend/FRONTEND_ARCHITECTURE.md`'s Principle 3 already allows on desktop. Where this document is silent on a visual decision, `docs/frontend/DESIGN_LANGUAGE.md`, `docs/frontend/DARK_MODE.md`, and `docs/frontend/THEMING.md` decide it, exactly as they do for the web; where it is silent on an API shape or a permission key, `docs/api/API_ARCHITECTURE.md` and `docs/foundation/PERMISSION_SYSTEM.md` decide it. This document adds the mobile-specific layer on top of both, and never contradicts either.

# Platform Strategy

## Two surfaces, one contract

| | Native (Flutter) | Responsive Web (installed PWA) |
|---|---|---|
| Distribution | Apple App Store, Google Play | `https://app.qayd.app`, installable via "Add to Home Screen" (Android/Chrome, and iOS 16.4+ Safari) |
| Bundle / package id | `com.qayd.app` (iOS bundle id and Android application id, kept identical across platforms) | N/A — same Next.js deployable `docs/frontend/README.md` already documents |
| Update model | App-store review + staged rollout; QAYD does not control the timeline a user upgrades on | Instant — every page load serves the current deployed build |
| Primary users | Owners/CFOs briefing-and-approving on the move; field roles (Sales Employee, Purchasing Employee, Warehouse Employee, Inventory Manager) capturing data away from a desk; any role wanting push notifications and offline resilience | Any authenticated user on any device, including company-managed tablets/Chromebooks without app-store access; the universal, no-install fallback for every role and every journey this document names |
| Backend contract | `qayd_sdk` (pub.dev), generated from the platform's OpenAPI 3.1 spec, calling `https://api.qayd.app/api/v1/...` directly — "no BFF layer exists for mobile today" (`docs/api/INTERNAL_API.md → The five surfaces, seen from the inside`) | The existing Next.js server-side proxy documented in `docs/frontend/FRONTEND_ARCHITECTURE.md → Data Layer` |
| Token custody | Platform secure storage — iOS Keychain, Android Keystore-backed `EncryptedSharedPreferences` — never plain `SharedPreferences` or a plist (`docs/api/API_ARCHITECTURE.md → Authentication`) | httpOnly session cookie; access token held only in server memory, never `localStorage` |

Both surfaces are equal citizens of the same `/api/v1` contract — there is no "internal" or privileged mobile API, matching `docs/api/API_ARCHITECTURE.md`'s founding rule restated for every surface: "One API, many surfaces. There is no 'internal' API with looser rules." Neither surface receives a feature, a validation shortcut, or a permission the other does not also have available to it in principle; what differs between them is which journeys each is *built* to make fast, per the policy below, not which journeys each is *permitted* to attempt.

## Why both exist

**Native wins on:** rich, actionable push notifications (Approve/Reject buttons directly on a locked screen, reliably delivered even when the app has been fully closed for days); the fastest and most natural biometric confirmation gesture (`local_auth`'s native `BiometricPrompt`/`LocalAuthentication` call, one system-owned sheet, versus a WebAuthn ceremony that is more at home authenticating a login than confirming a single approval mid-session); true offline-first behavior with a real embedded database and a background sync scheduler that runs even while the app is not in the foreground; camera-heavy capture flows (burst/multi-page receipt capture, on-device crop and compression before upload); and a permanent home-screen presence with no "add to home screen" step a user has to remember to take.

**Responsive Web wins on:** zero-install, zero-friction access — Dana Al-Rashidi's time-boxed two-week External Auditor grant (`docs/frontend/NAVIGATION_SYSTEM.md`'s own worked example) should never require installing an app for a two-week engagement, and the web is where that access always works, the first time, with nothing to approve from an MDM console first; desktop-class density on a tablet or company-managed Chromebook that is not permitted app-store installs; and being the fastest lane to every API change QAYD ships. `docs/api/API_ARCHITECTURE.md`'s **12-month minimum deprecation notice** is stated explicitly because "mobile app store review and staged rollout can add months to a customer's ability to ship an update" — the web is deliberately the fast lane a breaking change reaches first and completely, while native is the deliberately slower, more conservative lane that exact policy exists to protect.

## Feature parity policy

QAYD does not target pixel-for-pixel parity between the two surfaces; it targets a stated, three-tier policy so no screen's mobile behavior is left to whichever engineer happens to build it first.

| Tier | Definition | Examples |
|---|---|---|
| **1 — Must work everywhere, natively fast** | Any journey named in `# Priority Mobile Journeys` below. Both surfaces ship a first-class, non-degraded implementation; native additionally gets the platform capability (push, biometric, camera) the journey benefits from. | Approving a pending request, reading the dashboard feed, receiving and acting on a notification, asking the AI Assistant a question, glancing at cash position |
| **2 — Full CRUD everywhere, native gets an accelerated capture path** | The underlying record and its validation are identical on both surfaces; only the fastest way to originate one differs. | Creating an expense: native opens the camera directly into Document AI/OCR extraction (`# Camera & Document Capture`); web opens a drag-and-drop dropzone (`docs/frontend/RESPONSIVE_DESIGN.md → Forms On Mobile`) — the same `POST /api/v1/expenses` call either way |
| **3 — Desktop/web-only, deliberately** | Screens whose information density genuinely requires a keyboard and a large canvas to use safely. Present as read-only (web, per tier/viewport) or absent entirely (native), never compressed past legibility. | The Journal Entry line-grid composer past two or three lines, Trial Balance/General Ledger line-level editing, the five-stage Payroll Run wizard, Permission Studio (`docs/foundation/PERMISSION_SYSTEM.md`'s Long-Term Vision), Report Definition authoring |

Tier 3's stance is not a native limitation papered over — it is the same judgment `docs/frontend/RESPONSIVE_DESIGN.md` already makes for the responsive web client itself, which "deliberately degrade[s] to a read-only summary below a stated minimum width instead of compressing a dense grid past legibility" for Trial Balance and Reconciliation. Native mobile takes that same judgment one step further for its very smallest, most capability-constrained surface: where the web's answer at 375px is "a read-only card with a link to view the full breakdown," native's answer for the deepest editing surfaces (a multi-line journal entry composer, a payroll wizard) is "not offered as a create/edit flow at all — open the record to review it, then finish editing on a laptop or the web app," stated plainly in-product rather than silently degraded. The full per-module breakdown is `# Feature Availability Matrix`.

## Shared contract, one generated SDK per platform

```dart
// packages/qayd_sdk (pub.dev) — generated from the same OpenAPI 3.1 spec as @qayd/sdk (npm)
import 'package:qayd_sdk/qayd_sdk.dart';

final client = QaydClient(
  baseUrl: 'https://api.qayd.app',
  tokenProvider: SecureTokenProvider(), // reads/refreshes from Keychain/Keystore — see Biometric Auth & Security
  defaultHeaders: {'X-Client': 'mobile'}, // see AI Command Center's mobile_command_feed compact-payload contract
);

final entry = await client.accounting.journalEntries.get(journalEntryId: 4821482);
```

`docs/api/API_ARCHITECTURE.md → SDK` states the principle this table operationalizes for Flutter specifically: "Every SDK, including the ones consumed by QAYD's own first-party clients, is generated from the same OpenAPI 3.1 specification... QAYD's own web and mobile teams are deliberately made to feel the same friction (or lack thereof) as an external integrator." The Flutter app never hand-writes a request model, a response type, or an endpoint path — `qayd_sdk` is regenerated and version-bumped every time the OpenAPI spec changes, and a breaking response-shape change surfaces as a Dart compile error in the mobile CI pipeline before it ships as a runtime crash, the exact mobile-side mirror of `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 7`'s "a breaking API change surfaces as a TypeScript compile error in CI before it ships as a runtime bug."

Every outbound request from the Flutter app carries the identical headers a browser call carries — `Authorization: Bearer <access_token>`, `X-Company-Id`, `Accept-Language` — plus one mobile-specific addition already established by a sibling document rather than invented here: `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience` specifies "Identical endpoints to the desktop experience, with a `X-Client: mobile` header that shifts every widget's payload to a compact variant (truncated `narrative`, smaller image assets, `data` limited to the top 3 items of any list rather than the full set)." This document extends that single header as the platform-wide convention for every endpoint the mobile app calls, not only AI Command Center widgets — see `# Performance` for the full payload-shaping contract it triggers.

## Deep links resolve through one route table, not two

QAYD does not maintain a parallel "mobile deep link scheme" with its own path grammar. `docs/frontend/NAVIGATION_SYSTEM.md`'s own edge-case table already states the platform's intent generally: a push notification's `target` field is the same string an `ai_decisions.actions[].target` or a web notification's `target` would carry. Concretely for Flutter: Universal Links (iOS, `applinks:app.qayd.app`) and App Links (Android, `https://app.qayd.app/.well-known/assetlinks.json`) both resolve `https://app.qayd.app/<path>` to the identical logical route the Next.js App Router tree defines at that path (`docs/frontend/FRONTEND_ARCHITECTURE.md`'s route tree) — `/approvals/8842` opens the Approval Center detail screen on whichever surface handled the tap, and a custom `qayd://` scheme exists only as a fallback for contexts (some in-app browsers, certain OS share sheets) that do not honor Universal/App Links. A `target` string is never mobile-specific, never web-specific, and never requires this document's route table to be kept in sync with `FRONTEND_ARCHITECTURE.md`'s by hand — both are views onto the same logical map QAYD's backend already emits.

# Navigation

## Mobile app shell — no sidebar, no topbar rail

The persistent desktop shell (`docs/frontend/NAVIGATION_SYSTEM.md → App Shell`) is a Sidebar plus a Topbar; neither survives the trip to a phone as-is, and neither is naively shrunk. The Flutter app shell is instead built from three regions that have no desktop equivalent at all:

| Region | Widget | Role |
|---|---|---|
| Bottom navigation bar | `NavigationBar` (Material 3) / a `CupertinoTabBar`-styled equivalent on iOS, sharing one `GoRouter` `StatefulShellRoute` underneath | Five fixed slots — see below. Persists across every screen inside it; never disappears mid-scroll. |
| Top app bar | `AppBar` per tab root, contextual per screen | Page title (or the company/branch switcher on Home), the Notifications bell (badge-counted, opens the Notifications tab of `docs/frontend/NOTIFICATIONS.md`'s existing Inbox/Preferences pair, rendered as a full-screen route on mobile rather than a popover), and a search action that opens a full-screen search overlay — the mobile realization of the ⌘K Command Palette's **Records** search group (`docs/frontend/NAVIGATION_SYSTEM.md → Command Palette`), since a physical ⌘K shortcut has no equivalent on a touch keyboard. |
| Modal capture sheet | A `DraggableScrollableSheet` opened from the bottom bar's center action | Quick-create, permission-filtered — see below. |

Below `md:` (768px), the responsive web client already collapses to an identical shape — `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 3, Bottom navigation` — specifically so a user moving between the installed PWA and the native app on the same device (a company that has not yet rolled out the native build to every seat) experiences the same muscle memory in both. The native bar is the canonical definition; the web's `BottomNav` component is built to match it, not the reverse, because the native shell is subject to App Store/Play Store human-interface review and the web is not.

## The five slots

```
┌───────────────────────────────────────────────┐
│  🏠        ✓            ➕           💬        ☰   │
│ Home    Approvals    Capture     Assistant   More │
└───────────────────────────────────────────────┘
```

| Slot | Destination | Badge | Permission gate |
|---|---|---|---|
| Home | `mobile_command_feed` — the single, priority-ranked, swipeable column `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience` already defines | None (widgets self-filter, mirroring Dashboard's own "no single gating permission" rule) | None — every authenticated member of a company owns a Home tab |
| Approvals | The Approval Center (`docs/frontend/APPROVAL_CENTER.md`), scoped by default to `scope=mine` | Pending-count, sourced from the same `private-company.{id}.approvals` Reverb channel the desktop bell subscribes to | Visible to any user with at least one pending step assigned to them, or holding `ai.approve` broadly, exactly as `APPROVAL_CENTER.md → Route & Access` already scopes the desktop screen |
| Capture (center) | Opens a permission-filtered quick-action sheet, not a screen of its own | None | Each action inside filters independently — see below |
| Assistant | `/assistant` (`docs/frontend/AI_CHAT.md`'s canonical route, ported natively) | None | `ai.chat` — "All roles except suspended/locked accounts" |
| More | A scrollable menu of the remaining primary modules (Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports) plus Settings and Sign Out | Aggregate unread/urgent count across modules the user can see | Each module row uses the identical visibility permission `docs/frontend/NAVIGATION_SYSTEM.md → The module map` already defines (`accounting.read`, `bank.read`, `sales.read`, …) — a row is omitted, never rendered disabled, for a role lacking it |

Approvals and the Assistant earn dedicated slots — rather than living only inside "More," the way eight of the ten desktop sidebar modules do — because both are named priority journeys below and because `docs/frontend/RESPONSIVE_DESIGN.md`'s own dashboard-reflow table already promotes `approval_center_queue` to the second position on a narrow viewport ("**Second**, above the KPI row") and `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience` already promotes the Assistant's voice input to "the prominent thumb-reachable position." The bottom bar makes both a permanent one-tap reach rather than a scroll-dependent promotion within a feed.

```dart
// lib/navigation/app_shell.dart
final goRouter = GoRouter(
  routes: [
    StatefulShellRoute.indexedStack(
      builder: (context, state, shell) => AppShellScaffold(shell: shell),
      branches: [
        StatefulShellBranch(routes: [GoRoute(path: '/home', builder: (_, __) => const HomeFeedScreen())]),
        StatefulShellBranch(routes: [GoRoute(path: '/approvals', builder: (_, __) => const ApprovalCenterScreen())]),
        StatefulShellBranch(routes: [GoRoute(path: '/assistant', builder: (_, __) => const AssistantScreen())]),
        StatefulShellBranch(routes: [GoRoute(path: '/more', builder: (_, __) => const MoreMenuScreen())]),
      ],
    ),
  ],
);
```

`StatefulShellRoute.indexedStack` — not a plain `IndexedStack` hand-rolled around four `Navigator`s — is used specifically because each branch keeps its own navigation stack alive when the user switches tabs and back (opening an approval's detail, switching to Assistant to ask a clarifying question, then returning to Approvals lands exactly where the user left it, never back at the queue's top), the same state-preservation guarantee `docs/frontend/FRONTEND_ARCHITECTURE.md`'s parallel-route pattern gives the desktop shell.

## RTL mirroring in the shell

The bottom bar's five slots keep their **reading order**, not a mirrored physical order — Home stays the start-most icon and More stays the end-most icon in both directions, exactly as the web's own logical-properties rule (`docs/frontend/DESIGN_LANGUAGE.md → RTL mirroring`) already requires: "QAYD mirrors layout direction using CSS logical properties exclusively." Flutter's equivalent primitives are used identically in spirit — `Directionality.of(context)` resolves once at the shell root from the active locale, every layout uses `EdgeInsetsDirectional`/`AlignmentDirectional` rather than `EdgeInsets.only(left:, right:)`, and the platform's lint configuration (`custom_lint` with a project rule mirroring the web's `eslint-plugin-tailwindcss` restriction) flags a literal `left`/`right` constant the same way the web bans `ml-`/`mr-` outside a short, reviewed allow-list. Directional icons (a "next" chevron inside a `ListTile`, the Capture sheet's back affordance) flip via `Transform.flip(flipX: true)` gated on `Directionality`; icons that do not encode direction (a checkmark, the QAYD mark, a trash icon) never do — identical to the icon-mirroring rule `docs/frontend/DESIGN_LANGUAGE.md` states for Lucide on the web. The center Capture button visually sits in the middle regardless of direction (its position is not directional, it is centered), and the swipeable Home feed's swipe axis follows the same "logical start-to-end" convention `docs/frontend/RESPONSIVE_DESIGN.md → Gesture catalog` already specifies for the web's swipeable Approval card: a card dismissed "forward" moves physically end-to-start in RTL, not hardcoded left-to-right.

# Priority Mobile Journeys

Six journeys are why QAYD has a phone-first surface at all rather than only a responsive web page. Each is named because a person reaches for their phone specifically, not because it is merely possible on a small screen; each maps to Tier 1 of `# Platform Strategy`'s parity policy, and each is described below as the concrete flow it is, not a restatement of the screen document that already owns its data model.

## §1 — Approvals on the go

**Who, and why now.** Fahad Al-Ostath, Owner of Al-Noor Trading & Contracting W.L.L. (`company_id: 4821`), does not review a payment run at his desk — he reviews it, per `docs/ai/AI_COMMAND_CENTER.md`'s own running example, during a ninety-second commute. Mariam Al-Sabah, CFO, clears a purchase order between meetings. The Approval Center exists on desktop as `docs/frontend/APPROVAL_CENTER.md → Route & Access` already specifies; on mobile it is a first-class bottom-bar tab precisely because pending approvals do not wait for a desk.

**Flow.**
1. A push notification arrives (`# Push Notifications`) — "1 approval waiting: Payment run, Al-Fajr Cleaning Services." Tapping it, or opening the Approvals tab directly, resolves to the identical `GET /api/v1/approvals/{id}` detail `docs/frontend/APPROVAL_CENTER.md → Layout & Regions` already renders on desktop: the request's title, amount (via `AmountCell`), the requester or the originating AI agent's confidence and reasoning, and the four possible responses.
2. The card is presented full-screen, not as a compact widget preview — a sensitive decision never earns less screen real estate than the platform can give it, even on the smallest device QAYD supports.
3. Tapping **Approve** triggers the biometric re-confirmation gate specified in full in `# Biometric Auth & Security` — "layered on top of the existing permission check... a mobile-specific UX safeguard, not a relaxation of the desktop approval chain," in `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`'s own words.
4. On success, `POST /api/v1/approvals/{id}/approve` fires — the same mutation, same idempotency key discipline, same optimistic-then-reconciled cache update `docs/frontend/APPROVAL_CENTER.md → Interactions & Flows` documents for desktop. Nothing about what the mutation does changes; only the confirmation gesture in front of it does.
5. Reject and Request Changes require the same mandatory reason/note the desktop screen requires — a phone keyboard never becomes an excuse to skip a reason field.

**What mobile adds that desktop cannot.** A swipe gesture (`docs/frontend/RESPONSIVE_DESIGN.md → Swipeable approval card`, ported natively via a `Dismissible`/custom `PanGestureRecognizer`) as an *accelerator* on top of the two buttons, never a replacement for them — a screen-reader or switch-control user completes the identical approval through the buttons alone, and the swipe still routes through the same biometric gate before the underlying mutation fires; a fast swipe is a faster way to *reach* the confirmation, never a way around it.

## §2 — Receipt capture, camera → OCR → draft record

**Who, and why now.** A Purchasing Employee receives a paper delivery note at a supplier's counter; a Sales Employee is handed a fuel receipt to expense at the end of a client visit. Neither is at a desk with a scanner. The camera is the fastest, and often the only, capture device available.

**Flow.**
1. Tapping the center **Capture** action opens a permission-filtered sheet: **New Expense**, **New Bill (from delivery note)**, **New Stock Count**, **New Quotation** — each item's visibility resolves from the same server-side permission booleans `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 3` already specifies for the web's own bottom-nav "Create" sheet ("New Invoice, New Journal Entry, New Expense — each conditionally rendered by the same server-resolved permission booleans... never by role name alone").
2. Choosing **New Expense** opens the device camera directly — not a form with an attach button — because the receipt photo *is* the fastest path to a populated form, detailed in full in `# Camera & Document Capture`.
3. The captured image uploads to the polymorphic `attachments` table and is handed to Document AI/OCR (`docs/foundation/TECH_STACK.md`'s OCR row: Google Document AI or Azure Document Intelligence, Tesseract fallback), which returns extracted fields — vendor name, amount, currency, date, a suggested expense category — each carrying its own confidence.
4. The extraction pre-fills a draft expense form; nothing is created or posted from the extraction alone. Per the platform's founding AI rule, restated for this journey specifically: OCR is Document AI proposing a reading of a physical document, and a human confirms or corrects every field before `POST /api/v1/expenses` ever fires. A low-confidence field (per-field, not per-document) is visually flagged and pre-selected for the user's eye, mirroring `docs/frontend/COMPONENT_LIBRARY.md`'s "AI provenance in a table row" convention ported to a form field.
5. Submission is idempotent from the moment the camera shutter fires — the client-generated `Idempotency-Key` is created at capture time and reused across any retry a dropped warehouse Wi-Fi signal forces, per `docs/api/API_ARCHITECTURE.md → Idempotency`.

## §3 — Dashboard and health glance

**Who, and why now.** Fahad's first look at QAYD most mornings is not a decision, it is a temperature check — per `docs/ai/AI_COMMAND_CENTER.md`'s own "ninety-second commute" journey. The Home tab renders `mobile_command_feed`, the single swipeable column that document already specifies in full (`docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`), reproduced here only as the entry point this document's navigation model routes to: Urgent Actions pinned first regardless of any saved layout preference, then Morning Briefing, then the Business Health Score and Cash Flow Status KPIs, then Revenue/Expense Trends, in the exact priority order that document's own worked example lays out. This document does not re-specify that feed's data contract; it specifies that Home is where it lives, that it is reachable in one tap from anywhere in the app, and that swiping between its cards uses the same reduced-motion-aware, RTL-correct gesture contract as every other swipeable surface in the product.

## §4 — Notifications

**Who, and why now.** A notification is frequently the *reason* the phone came out of a pocket at all, ahead of any of the other five journeys. The Topbar bell's mobile equivalent — a badge-counted icon in every tab root's `AppBar` — opens the identical Inbox/Preferences pair `docs/frontend/NOTIFICATIONS.md` already specifies, rendered full-screen (never a popover, which has no natural mobile equivalent) with the same five-category taxonomy (Approvals Due, AI Alerts, Fraud & Risk, Deadlines, System) and the same rule that "approving from a notification is a second entry point into one mutation, not a second, competing implementation of approval." The full delivery mechanics — registration, payload shape, quiet hours, lock-screen masking — are `# Push Notifications`'s subject; this journey is the in-app destination that mechanics lands a tap on.

## §5 — AI chat and voice

**Who, and why now.** `docs/frontend/AI_CHAT.md` already establishes one conversational surface onto the platform's fifteen-agent workforce, fronted by the CEO Assistant orchestrator, at the canonical route `/assistant`. On mobile this is not a docked panel competing for screen space with a data table — the Assistant is one of the five permanent bottom-bar destinations, opening the identical `useChat`-equivalent streaming transcript (an SSE-consuming `EventSource`/`dio` streaming client wrapping the same `POST /api/v1/ai/chat` the web's Route Handler proxies) with the identical tool-use transparency, citation rendering, and inline three-button proposal pattern `AI_CHAT.md` specifies. The one mobile-specific behavior is stated once, precisely, in `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`: "Voice Assistant promotion to the primary input method... the hold-to-talk affordance is given the prominent thumb-reachable position, since voice is the faster input on a small screen held in one hand." The docked text composer remains available beneath the hold-to-talk button at all times — voice is promoted, never exclusive, and every voice turn is transcribed into the same message history a typed turn would produce, so a conversation started by voice on the train and continued by typing at a desk is one uninterrupted thread, not two.

## §6 — Cash position at a glance

**Who, and why now.** `docs/frontend/DARK_MODE.md`'s own opening line names this exact moment: "a founder checking the cash position from bed before a 7am flight." Cash position is the one figure QAYD treats as a permanent, un-scrollable fixture rather than a feed item competing for swipe attention — a pinned card at the very top of Home, above even Urgent Actions in visual position (though not above it in interrupt priority; a critical hold still surfaces its own banner), rendering the company's aggregate cash balance across `docs/frontend/BANKING.md`'s bank accounts (`GET /api/v1/banking/accounts` aggregate) alongside the short-horizon figure from `GET /api/v1/ai/cash-flow/forecast`, in the identical `numeral-hero`-weight treatment `docs/frontend/DESIGN_LANGUAGE.md` reserves for "one hero KPI number per dashboard tile." Tapping it opens the full Cash Flow Status widget and, from there, `docs/frontend/CASH_FLOW.md`'s statement screen (read-only on native per the Tier 2/3 split in `# Feature Availability Matrix`); the pinned card itself never requires a tap to be useful, because the entire point of this journey is that the number is visible the instant the app opens, with no navigation at all.

# Offline & Sync

## What offline means in QAYD, precisely

`docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 1` states the frontend never contains business logic as a source of truth; offline mode does not create an exception to that rule, it adds a queue in front of it. A record created offline is held, unposted, in a local encrypted store until connectivity returns, and every business rule the server would apply online (does this balance, is this account postable, is this customer within their credit limit) is re-checked, authoritatively, at sync time — never assumed to have already passed because the local form's own lightweight mirror of that rule did not object at capture time.

## Local store

The Flutter app embeds **`drift`** (a type-safe, code-generated SQLite layer for Dart) as its local database, chosen for the same reason `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 7` gives for generated TypeScript types on the web: schema and query code are generated from a single source and checked at compile time, rather than hand-maintained SQL strings drifting from the models that read them. Two categories of data live in `drift`:

| Category | Examples | Sync direction | Staleness policy |
|---|---|---|---|
| **Read cache** | Chart of Accounts, cost centers, product catalog, the active company's recent invoices/bills list | Server → device, refreshed opportunistically whenever a screen backed by that resource is opened online | Mirrors `docs/frontend/FRONTEND_ARCHITECTURE.md → Cache tuning by data class`'s `staleTime` table — reference/master data is cached for minutes, transactional lists for seconds, and is always shown with a "last synced" timestamp when the device is offline, never presented as if it were live |
| **Outbox** | Draft expenses, stock counts, stock adjustments, quotations originated while offline | Device → server, drained by a background sync worker the moment connectivity returns | Never shown to any other user or device until the sync succeeds; visible only to its own author, labeled "Pending sync" in the same neutral-ink `StatusBadge` treatment `docs/frontend/DARK_MODE.md → Finance status semantics` reserves for `draft`/`pending` states |

```sql
-- Outbox table, local SQLite only — never replicated to PostgreSQL as a table of its own
CREATE TABLE outbox_mutations (
    id                TEXT PRIMARY KEY,             -- client-generated UUID, doubles as the Idempotency-Key
    resource          TEXT NOT NULL,                -- e.g. 'expenses', 'stock_adjustments'
    method            TEXT NOT NULL CHECK (method IN ('POST','PATCH')),
    payload_json      TEXT NOT NULL,
    attachment_paths  TEXT NULL,                    -- local file paths, uploaded before the mutation on sync
    created_at        INTEGER NOT NULL,              -- epoch millis, device clock
    attempt_count     INTEGER NOT NULL DEFAULT 0,
    last_error        TEXT NULL,
    status            TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','syncing','failed','synced'))
);
```

## Background sync

A `WorkManager` job (Android) / `BGAppRefreshTask` (iOS, subject to the OS's own scheduling discretion, which QAYD does not attempt to fight) wakes periodically and whenever connectivity is regained (`connectivity_plus`'s stream firing a transition to `wifi`/`mobile`), drains `outbox_mutations` in creation order, uploads any attachment first, then replays the mutation using its stored `Idempotency-Key`. A row that returns a `422` (the server's authoritative validation rejecting it — a credit limit breached in the hours since capture, an account since deactivated) moves to `status = 'failed'` and surfaces as an actionable item in the Home feed ("1 stock count couldn't sync — review") rather than silently retrying forever or silently dropping; a row that returns `2xx` is deleted from the outbox and its now-server-confirmed record replaces the local draft everywhere it was shown.

## What is never queued offline

Every action `docs/foundation/PERMISSION_SYSTEM.md` and this platform's design context class as requiring a live human-approval chain — bank transfers, payroll calculation and release, tax submission, voiding or deleting posted financial data, permission changes, company settings changes — is refused outright when the device is offline, with an explicit in-product message ("This needs a connection — bank transfers can't be queued offline") rather than accepted into the outbox and attempted later. This is a direct, mobile-specific extension of `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 10`, "optimistic where safe, pessimistic where it moves money": a pessimistic mutation that waits for the server's `2xx` before the UI treats it as done has no honest offline-queued form at all, because the entire point of pessimism there is that the UI must not claim an outcome the server has not yet confirmed — queuing it offline would be exactly that claim, made worse by an unknown delay. Approving a request from the Approvals tab (`§1`) likewise requires connectivity for the same reason and additionally because its biometric gate (`# Biometric Auth & Security`) is itself a live, timestamped confirmation, not a cached one.

## Conflict handling

Read-cache resources are refreshed, never merged — a Chart of Accounts row fetched fresh on reconnect simply replaces the cached copy, since QAYD's accounts have one authoritative revision and no concept of a client-side edit to reconcile. Outbox resources are append-only creations (a new expense, a new stock count), which have no concurrent-edit conflict to resolve by construction — two devices cannot conflict over the *same* not-yet-created record, only over a shared downstream constraint the server, not the client, is positioned to catch (two stock adjustments for the same SKU that together would take a warehouse's stock negative resolve as a normal server-side `422` on whichever mutation lands second, exactly as it would from two simultaneous web sessions).

# Push Notifications

## Registration

```sql
CREATE TABLE user_devices (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           BIGINT NOT NULL REFERENCES users(id),
    platform          VARCHAR(16) NOT NULL CHECK (platform IN ('ios','android','web')),
    push_provider     VARCHAR(16) NOT NULL CHECK (push_provider IN ('fcm','apns_via_fcm','web_push')),
    push_token        TEXT NOT NULL,
    push_token_hash   VARCHAR(64) NOT NULL,          -- sha256(push_token); the unique index target, since tokens rotate and can exceed a comfortable btree key size
    device_name       VARCHAR(120) NULL,             -- "Fahad's iPhone 15 Pro"
    app_version       VARCHAR(20) NULL,
    os_version        VARCHAR(20) NULL,
    locale            VARCHAR(8) NOT NULL DEFAULT 'en',
    last_seen_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    is_active         BOOLEAN NOT NULL DEFAULT true,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at        TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX ux_user_devices_token_hash ON user_devices (push_token_hash) WHERE revoked_at IS NULL;
CREATE INDEX ix_user_devices_user ON user_devices (user_id) WHERE revoked_at IS NULL;
```

`docs/database/ERD.md → user_sessions`'s own **Future Expansion** note names exactly this table and defers it: "`user_devices` for push-notification token management (APNs/FCM), separate from web session tracking; see the native-app companion project for the interim client-side implementation." This document is that companion project's specification, and the table above fulfills that deferral. `user_devices` is `user_id`-scoped, not `company_id`-scoped, for the identical reason `docs/api/AUTHENTICATION_API.md` gives for `mfa_factors` and `user_sessions`: a person's phone belongs to them, not to any one company they happen to work for, and a push token registered once should keep delivering notifications across every company switch a multi-tenant user makes.

```
POST /api/v1/users/me/devices
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
```
```json
{ "platform": "ios", "push_token": "fZ8k...c3e1b", "app_version": "1.4.2", "os_version": "18.1", "locale": "ar" }
```
The Flutter app calls this once on login and again whenever `firebase_messaging`'s token-refresh stream fires (tokens rotate); `push_token_hash` deduplicates so a re-registered device updates its existing row (`last_seen_at`, `app_version`) rather than accumulating stale duplicates. On logout, the app calls `DELETE /api/v1/users/me/devices/{id}` for its own device, and the Concurrent Session Policy's "sign out of all other devices" affordance (`docs/api/AUTHENTICATION_API.md → Session Management`) additionally revokes every `user_devices` row tied to the sessions it revokes.

## Delivery architecture

QAYD sends exactly one outbound push integration from the backend — **Firebase Cloud Messaging, HTTP v1** — for both `platform = 'android'` and `platform = 'ios'` rows; Firebase itself relays iOS-bound messages to APNs under the hood, which is why the platform's own shorthand elsewhere (`docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`: `"provider": "apns_fcm_via_reverb"`) names the pair together — one backend integration, two OS-level delivery paths. `platform = 'web'` rows (an installed PWA, Android Chrome or iOS 16.4+ Safari) are delivered over standard Web Push (VAPID keys), a separate, smaller integration the same domain event fans out to in parallel. Both paths are reached through the identical abstraction `docs/api/AUTHENTICATION_API.md` already establishes for SMS OTP delivery — "abstracted behind a `NotificationChannel` interface so the underlying provider is swappable without touching this API contract" — QAYD's `push` channel implementation is one more concrete instance of that same interface, not a parallel mechanism.

```
Domain event (approval.requested, bank.transfer.held, invoice.overdue, …)
        │
        ▼
Laravel Reverb ──────► open app sockets (foreground: live feed/badge update, no OS notification)
        │
        ▼
events-notifications queue consumer
        │
        ├──► user_devices WHERE platform IN ('ios','android') ──► Firebase Admin SDK (HTTP v1) ──► APNs / FCM
        └──► user_devices WHERE platform = 'web'               ──► Web Push (VAPID)             ──► Browser push service
```

A device that is foregrounded receives the update over its already-open Reverb socket and never surfaces a redundant OS-level notification for the same event — the two paths are complementary, not duplicated, mirroring the `IntersectionObserver`-gated subscription discipline `docs/frontend/RESPONSIVE_DESIGN.md → Realtime subscription scoping` already applies to the web's own dashboard widgets.

## Payload masking

The OS-level notification payload — the title and body a locked screen renders, potentially in front of anyone glancing at the device — never carries an unmasked monetary amount or a full counterparty name by default, mirroring the exact masked-form-plus-deep-link pattern `docs/ai/tools/COMMUNICATION_TOOLS.md` already establishes: "A message needing to reference such a value uses the already-established masked form (last four digits) plus a deep link... back into an authenticated QAYD session for the full detail." A payment-run hold therefore renders as "1 approval waiting — Payment run, Al-Fajr Cleaning Services" (party name, no amount) rather than "Approve KWD 42,000.000 transfer to Al-Fajr" on the lock screen; the full figure appears only after the tap resolves into an authenticated, and for a sensitive action biometrically re-confirmed, in-app screen. `docs/frontend/NOTIFICATIONS.md`'s existing five-channel Preferences matrix gains one mobile-specific row for this document's purposes — **"Show full amounts on lock screen"** — off by default for every category touching money, always available as an explicit opt-in for a user who has decided their own lock-screen exposure is an acceptable trade for faster triage.

## Quiet hours

`docs/ai/memory/USER_MEMORY.md`'s own worked example of a `notification_preference` memory row — "No push notifications after 20:00 Kuwait time; digest, not real-time, for anything not tagged urgent" — is the product precedent this document operationalizes as a concrete setting. Quiet hours are not a new column on `notification_preferences` (`docs/frontend/NOTIFICATIONS.md` fixes that table's schema deliberately, and this document does not reopen it); they live in the same schemaless `users.settings` JSONB column `docs/frontend/DARK_MODE.md → Persistence & SSR` already uses for `ui_theme` and `notification_channels` — "the platform's established home for a genuinely evolving bag of preferences":

```json
// users.settings (JSONB)
{
  "ui_theme": "system",
  "notification_channels": ["email", "in_app", "push"],
  "quiet_hours": { "enabled": true, "start": "20:00", "end": "07:00", "timezone": "Asia/Kuwait" }
}
```

Anything tagged `urgent` (Urgent Actions, an SLA-critical approval, a fraud hold) bypasses quiet hours by design, exactly as `docs/ai/AI_COMMAND_CENTER.md`'s own mobile feed pins those items "regardless of a saved layout preference — mobile does not honor 'hide this' for anything currently critical"; everything else queues and delivers as a single digest at `quiet_hours.end`, never as a burst of individually-chimed notifications the moment the window closes.

# Biometric Auth & Security

## Two different things, never conflated

QAYD's mobile security model draws a hard line between two mechanisms that are easy to blur into one and dangerous to blur in practice: **biometric unlock** (a local, device-only gate) and **step-up MFA** (a server-verified second factor). Confusing the two — treating "Face ID unlocked the app" as equivalent to "the server has cryptographic proof of a second factor" — is precisely the kind of client-side authority `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 1` forbids: the frontend does not get to decide, on its own local say-so, that a security requirement has been satisfied.

| | Biometric unlock | Step-up MFA |
|---|---|---|
| What it proves | This physical device is being held by whoever enrolled its Face ID/fingerprint | The account holder possesses a second, registered factor the server can verify |
| Mechanism | `local_auth` (Flutter) calls the OS's native `BiometricPrompt` (Android) / `LocalAuthentication` (iOS); success releases access to a value already sitting in Keychain/Keystore | A real WebAuthn ceremony against a platform authenticator (`POST /api/v1/auth/mfa/webauthn/register/options` → `/register/verify`, then `/mfa/webauthn/*` at verification time), or TOTP/SMS/backup code, per `docs/api/AUTHENTICATION_API.md → MFA` |
| Where it happens | Entirely on-device; no network call | Server round-trip; the resulting `amr` claim (`docs/api/AUTHENTICATION_API.md → JWT`) is inspected by the API on every sensitive endpoint |
| Can it be faked by a compromised client? | In principle yes, on a jailbroken/rooted device with tooling that patches the biometric result — mitigated by `# Root & jailbreak detection` below, never assumed impossible | No — the server verifies a cryptographic assertion signed by a hardware-backed key it registered itself; a compromised client cannot fabricate a valid WebAuthn signature |
| What it gates | Resuming the app after backgrounding; releasing the refresh token from secure storage to silently refresh an access token | Any endpoint `docs/api/AUTHENTICATION_API.md → MFA`'s enforcement table marks "step-up required" — `bank.transfer`, `payroll.approve`/release, `tax.submit`, `*.void`, permission changes |

The consequence of this distinction, stated plainly: **a device whose Face ID has never been registered as a WebAuthn platform authenticator cannot use "biometric unlock" to satisfy a step-up requirement.** For a company that has enabled WebAuthn (`docs/api/AUTHENTICATION_API.md`'s registration endpoints, ported to the Flutter equivalent of the browser's `navigator.credentials` API via the platform authenticator), the *same* Face ID gesture legitimately does both jobs at once — it unlocks the app locally and, when a sensitive action needs it, produces a real WebAuthn assertion the server verifies — but the app never presents the first as proof of the second unless the registration underneath it is real.

## The mobile-specific approval safeguard

`docs/ai/AI_COMMAND_CENTER.md → Mobile Experience` already states the product rule this section implements in full: "Approving a sensitive action from mobile (a bank transfer, a payroll release) requires a biometric re-confirmation (Face ID / fingerprint) immediately before the approval is submitted, layered on top of the existing permission check — this is a mobile-specific UX safeguard, not a relaxation of the desktop approval chain." Concretely:

1. Tapping **Approve** on a request whose `requiredPermission` is one of `bank.transfer`, `payroll.approve`, `tax.submit`, or any `*.void`/`*.delete` action (the identical `SENSITIVE_PERMISSIONS` set `docs/frontend/APPROVAL_CENTER.md`'s Bulk Action Bar already gates on) triggers `local_auth.authenticate(biometricOnly: true, reason: "Confirm approval — Payment run, Al-Fajr Cleaning Services")` before the mutation is ever dispatched.
2. If the company additionally requires step-up MFA for the acting role (`docs/api/AUTHENTICATION_API.md → MFA`'s per-role table — "Mandatory... Always" for Owner/CEO/CFO/Finance Manager/Senior Accountant on sensitive ops), a successful biometric unlock that is *also* a registered WebAuthn assertion satisfies both in one gesture; where WebAuthn is not enrolled, the biometric confirmation is followed by a TOTP/SMS/backup-code step-up challenge exactly as the web presents it, never silently skipped because "the phone already asked for Face ID."
3. Only after both gates clear does `POST /api/v1/approvals/{id}/approve` fire, carrying the same request the desktop screen would send — the server has no "mobile" branch of the approval endpoint and cannot distinguish a mobile-originated approve from a web-originated one at the API layer; the extra ceremony is entirely a client-side UX safeguard riding in front of an unchanged server contract, exactly as the cited product rule states.
4. A device with no biometric hardware enrolled (an older device, or a user who has declined Face ID/fingerprint setup at the OS level) falls back to the device passcode/PIN via the same `local_auth` call — never a silent bypass — and a company may additionally require the device-level fallback be disabled entirely for its most sensitive roles, in which case that role's approvals are refused on that device with a message directing the user to a biometric-capable device or the web.

## Session and token custody

Access tokens (15-minute lifetime, per `docs/api/AUTHENTICATION_API.md → JWT`) and refresh tokens (30-day sliding window, single-use, family-revoked on reuse detection) are stored exactly as `docs/api/API_ARCHITECTURE.md → Authentication` already mandates: iOS Keychain (`kSecAttrAccessibleWhenUnlockedThisDeviceOnly`, never synced to iCloud Keychain, since a refresh token migrating silently to a second device on the same iCloud account would defeat per-device session accounting) and Android Keystore-backed `EncryptedSharedPreferences`. Biometric unlock gates *reading* the refresh token from that store, not merely a UI overlay drawn on top of an already-decrypted value in memory — `local_auth` success is a precondition the platform channel checks before the secure-storage plugin releases the value at all.

**App-lock** — requiring biometric/passcode confirmation to resume the app after backgrounding — is a separate, shorter, purely client-side timer layered on top of the server's own much longer session idle timeout (`docs/api/AUTHENTICATION_API.md → Concurrent Session Policy`, ranging from 4 hours for Auditor roles to 2 weeks for Owner/CEO/CFO). App-lock answers "can this physical person keep looking at data already on the screen," a question the server-side idle timeout is not positioned to answer at all (the server cannot see whether a phone left a pocket); the server-side timeout answers "is the credential itself still valid." QAYD sets a default app-lock window of 5 minutes backgrounded for every role, configurable stricter per company policy, and — mirroring the Concurrent Session Policy table's own differentiation — defaulting tighter (1 minute) for Payroll Officer and Auditor/External Auditor roles specifically, the same two categories that table already treats as warranting shorter server-side windows.

## Root & jailbreak detection

Because a compromised OS is the one scenario where a local biometric result could be spoofed independent of the hardware, the app runs a jailbreak/root detection check (the `freeRASP` SDK) at launch and before every biometric-gated sensitive action. A positive detection does not block the app outright — QAYD does not assume every rooted device is malicious, and a legitimate power user's rooted personal Android phone is a real, if rare, case — but it disables the biometric-gate *shortcut* for sensitive approvals on that device, falling back to full step-up MFA every time regardless of company policy, and it logs the detection to the same `audit_logs` table every other security-relevant event writes to, with the device's `user_devices.id` attached for traceability.

# Camera & Document Capture

## Why the camera is the form

`§2` in `# Priority Mobile Journeys` names the product reason; this section specifies the mechanics. The Flutter app's capture flow (`camera` package, with `image` for on-device pre-processing) opens directly into a document-shaped viewfinder — corner guides overlaid on the preview, matching the aspect ratio Document AI's ingestion pipeline expects — rather than a generic photo picker, because the fastest path from "paper receipt in hand" to "populated expense form" skips the extra tap a photo-library round-trip would add.

## Capture-to-record pipeline

1. **Edge detection and auto-crop.** The captured frame is auto-cropped to the detected document edges on-device (a standard perspective-correction pass), with a manual crop-adjustment handle always available before confirming — auto-detection is a convenience, never a silent, uneditable transform.
2. **Compression before upload.** Images are re-encoded to JPEG at a quality/size budget tuned for OCR legibility over file size (long edge capped at 2400px, quality 85) — large enough for Document AI/Tesseract to read small print reliably, small enough to upload over a throttled Gulf mobile connection inside the performance budget in `# Performance`. The *original*, uncompressed capture is never discarded before the compressed version's upload succeeds, so a failed upload can retry from the same source frame rather than re-shooting.
3. **Multi-page capture.** A delivery note or a multi-line vendor invoice supports appending additional pages to the same draft record before submission (a `+` affordance on the review screen), all pages attaching to the same `attachments` polymorphic row set (`attachable_type = 'Expense'`/`'Bill'`, `attachable_id` the draft's client-side id until the record is confirmed server-side).
4. **Upload and OCR handoff.** Each page uploads to Cloudflare R2 via the same signed-URL pattern `docs/foundation/TECH_STACK.md`'s Object Storage row and the web's own attachment flow already use, then is hint-tagged for Document AI/OCR Agent processing (`docs/ai/tools/DOCUMENT_TOOLS.md`), which returns structured fields with per-field confidence.
5. **Human confirmation, always.** Extracted fields pre-fill the draft form; nothing reaches `POST /api/v1/expenses` or `/bills` without an explicit **Save** or **Submit** tap from the capturing user, and every pre-filled field remains editable — matching the AI-proposes-human-confirms rule this document has already stated for `§2` and that `docs/frontend/FRONTEND_ARCHITECTURE.md → Principle 3` states platform-wide.

## Permissions and privacy

Camera and photo-library access are requested at the moment of first use (never at app launch, per both Apple's and Google's platform guidance and QAYD's own "ask only when the capability is about to be used" posture), with the system permission-rationale string stated in both English and Arabic ahead of the OS prompt ("QAYD uses your camera to scan receipts and bills — nothing is uploaded until you review and confirm it."). A captured image containing a document is never written to the device's general camera roll unless the user explicitly taps "Save a copy to Photos" — the default keeps a receipt photo inside QAYD's own sandboxed storage only, consistent with the platform's general stance (`docs/database/DATABASE_ARCHITECTURE.md`'s attachment-handling posture) that a financial document's custody chain should stay inside systems QAYD's own audit logging can see.

# Performance

## Cold start budget

QAYD targets a **cold start to first meaningful paint of the Home feed under 2.0 seconds** on a mid-tier Android device (the platform's Kuwait-pilot reference hardware class, roughly a 2022-era mid-range handset) on a throttled 3G-equivalent connection, and under 1.2 seconds on a comparable iOS device, where the OS's more predictable memory model and Flutter's AOT-compiled release builds both help. Three concrete techniques hold that budget:

- **Deferred, not eager, module loading.** Only Home, Approvals, and the shell chrome are compiled into the initial route graph eagerly resolved at launch; Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, and Reports (the "More" tab's contents) lazy-load their route and widget code the first time a user actually opens one, using Dart's deferred library imports — mirroring, in spirit, the web's own Server-Component-first, client-island-only-where-needed discipline (`docs/frontend/FRONTEND_ARCHITECTURE.md → Client Component boundaries`) applied to a compiled app's bundle instead of a browser's JS payload.
- **Skeleton, never spinner, for known shapes.** Every screen with a predictable layout renders `docs/frontend/DESIGN_LANGUAGE.md`'s shimmer-skeleton pattern ("a slow, low-contrast shimmer sweep... never a spinner as the primary loading state for content that has a known shape"), ported to Flutter via a `shimmer`-package widget matching the exact 1.6-second, `ink-3`→`ink-4`→`ink-3`-equivalent sweep the web specifies, so a cold-started Home tab shows the correct *shape* of the feed within the first frame even before its data has arrived.
- **The `X-Client: mobile` compact payload contract.** Every list/dashboard endpoint the app calls sends `X-Client: mobile`, the same header `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience` established for `mobile_command_feed` and which this document extends platform-wide (`# Platform Strategy`): truncated narrative text, downscaled image assets, and list payloads capped to their top 3–5 items with "View all" fetching the remainder on demand. A cold start's first network round trip is therefore reliably smaller than the equivalent desktop payload, not merely rendered smaller.

## Low-bandwidth handling

Gulf mobile networks are generally strong, but a warehouse basement, an underground parking structure, or a rural site visit are real, frequent conditions QAYD's field roles (Warehouse Employee, Inventory Manager, Sales/Purchasing Employee) work inside. `connectivity_plus` classifies the active connection, and the app degrades in three concrete stages rather than a single binary online/offline switch: **full** (no change in behavior), **constrained** (image assets request a lower-resolution variant from Cloudflare R2's image-resizing pipeline, non-critical realtime subscriptions per `docs/frontend/RESPONSIVE_DESIGN.md → Realtime subscription scoping`'s own mobile gating logic pause rather than retry aggressively, and list requests reduce their page size below the standard 25), and **offline** (`# Offline & Sync` applies in full). A request that times out is retried with exponential backoff exactly twice before surfacing an inline retry affordance — QAYD never retries a financial mutation silently and indefinitely in the background without the user's own visibility into that retry, per the idempotency contract already discussed.

## Image and asset budgets

Every image the app renders — a vendor logo, an attachment thumbnail, an avatar — is served through Cloudflare R2's resizing variants at the exact display density needed (`@1x`/`@2x`/`@3x`), never a full-resolution original scaled down client-side; thumbnail lists (an attachments gallery, a notification's inline preview) use a capped, low-resolution variant and only fetch the full-resolution original on an explicit tap-to-zoom, the same "smaller image assets" discipline `X-Client: mobile` already applies to AI Command Center widget payloads, generalized to every image the app requests.

## Memory and battery

The realtime `IntersectionObserver`-equivalent gating `docs/frontend/RESPONSIVE_DESIGN.md → Realtime subscription scoping` specifies for the web's mobile dashboard — a widget scrolled out of view unsubscribes from its Reverb channel rather than continuing to process pushes it is not rendering — applies identically to the native Home feed, using Flutter's `VisibilityDetector` package to the same effect. Background sync (`# Offline & Sync`) and location-independent push delivery are the only work the app performs while backgrounded; there is no continuous background polling of any endpoint, and the app holds no wake lock beyond what the OS's own background-task APIs grant for a bounded sync window.

# Design Adaptation

## One design language, one semantic token layer, two renderers

`docs/frontend/DESIGN_LANGUAGE.md`'s Design Principles are not a web-specific taste; they are QAYD's taste, and every one of them is stated here as binding on the Flutter app exactly as written for Next.js — "ink before color," "one accent, spent deliberately," "numbers are the hero, not the chrome around them," "density with air," "motion explains, never entertains," and "the AI stays humble in the UI, exactly as it stays humble in the API." A screen that reads as a generic rounded-SaaS mobile app, or worse, a consumer fintech app with confetti and mascots, is exactly the failure this document exists to prevent on the platform's smallest, most visually cramped surface — the temptation to "soften" an editorial system for a phone is treated as a defect, not a reasonable adaptation.

This document does not re-derive token *values* — those are owned by `docs/frontend/DESIGN_LANGUAGE.md`, `docs/frontend/THEMING.md`, and `docs/frontend/DARK_MODE.md`, and this document never contradicts them. What it specifies is how the Flutter app *consumes* the identical semantic layer those documents already define (`--surface-canvas/base/raised/overlay/sunken`, `--text-primary/secondary/tertiary/disabled`, `--accent`/`--accent-hover`/`--accent-subtle`, `--status-success/warning/error/info`, `--border-subtle/default/strong`) so the two clients never drift into two visual systems that merely resemble each other:

```dart
// lib/theme/qayd_tokens.dart — generated at build time from the platform's shared token source
// (the same globals.css custom-property definitions THEMING.md's pipeline emits for Tailwind),
// never hand-transcribed hex values that could silently drift from the web's own tokens.
class QaydTokens {
  static const surfaceCanvas = Color(0xFFFCFCFD);   // --surface-canvas (light)
  static const surfaceBase = Color(0xFFFFFFFF);     // --surface-base (light)
  static const textPrimary = Color(0xFF15130E);     // --text-primary (light)
  static const accent = Color(0xFF0E7A5F);          // --accent (light)
  static const statusWarning = Color(0xFFB45309);   // --status-warning (light)
  // ...dark-mode counterparts generated into a parallel QaydTokensDark class, resolved via
  // ThemeData.brightness exactly as `.dark`/`data-theme="dark"` resolves the web's CSS variables.
}
```

A small build script (`tool/generate_tokens.dart`, run in CI whenever the shared token source changes) is the only place a hex value is ever transcribed from the design-token source of truth into Dart — no widget file references a literal `Color(0xFF...)` any more than a web component references a raw Tailwind palette color outside `globals.css`, matching `docs/frontend/DARK_MODE.md`'s own enforcement rule ("a component file that contains the literal string `#` followed by a hex digit... outside of `globals.css` itself is a defect") ported to the Dart codebase's own CI lint.

## Corner radius, spacing, and type on a small canvas

QAYD's tightened, editorial corner-radius ceiling (rectangular surfaces never exceeding roughly 10–12px; full-round reserved for pills, avatars, and status dots) and its unmodified 4px spacing scale carry over unchanged — a phone screen is exactly where a design system's discipline is tested hardest, and QAYD's answer is smaller type and tighter row height, never bigger radii or looser "friendly" spacing to compensate for the smaller canvas. `docs/frontend/RESPONSIVE_DESIGN.md → Fluid type scale`'s `clamp()`-based Mobile-tier sizes (Display 28px, Body 15px) are the exact sizes the Flutter app's `TextTheme` is built from, so a screen title reads at the identical visual weight whether it was reached through the installed PWA or the native app on the same device.

## Motion

Framer Motion's duration/easing tokens (`docs/frontend/DESIGN_LANGUAGE.md → Motion`) have a direct Flutter counterpart in `AnimationController`/`Curves`, matched value-for-value: `motion.micro` (120ms) for a button press scale, `motion.base` (200ms) for a tab switch, `motion.moderate` (280ms) for a sheet's enter/exit. `MediaQuery.of(context).disableAnimations` — Flutter's own reflection of the OS-level reduced-motion preference — is checked by the same shared `getTransition()`-equivalent helper every animated widget calls, exactly mirroring the web's `useReducedMotion()` pattern; reduced motion never removes a state change on mobile any more than it does on the web, only the animation of that change.

## Cursor-spotlight and hover states have no mobile equivalent, and are not missed

A small number of desktop-only affordances — a card's cursor-spotlight highlight, a table row's hover-revealed action icons — are not ported to mobile at all, because they answer a question ("where is the mouse") a touch device does not ask. `docs/frontend/RESPONSIVE_DESIGN.md → Gesture catalog`'s own `@media (hover: hover) and (pointer: fine)` feature-query discipline already establishes the correct posture for the web; the Flutter app simply never implements the hover-only affordance in the first place rather than implementing and then disabling it, since a native app has no pointer-hover state to query.

# RTL & Localization

## Arabic as a first language, ported faithfully

`docs/frontend/DESIGN_LANGUAGE.md → Design Principle 6` states the platform's non-negotiable stance: "Every screen is designed and reviewed in Arabic before it is considered done, not flipped with `dir=\"rtl\"` at the end and eyeballed." The Flutter app is built and QA'd against the identical standard — `Directionality` resolves from the active locale at the shell root exactly as `<html dir="rtl">` resolves it on the web (`docs/frontend/FRONTEND_ARCHITECTURE.md → Layout nesting`), and every screen ships an Arabic review pass in the same pull request that introduces it, never a follow-up localization sweep.

```dart
// lib/main.dart (excerpt)
MaterialApp.router(
  locale: settingsProvider.locale,           // 'en' | 'ar', from the same users.settings JSONB the web reads
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: AppLocalizations.localizationsDelegates,
  builder: (context, child) => Directionality(
    textDirection: settingsProvider.locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr,
    child: child!,
  ),
);
```

Strings are generated from ARB files (`app_en.arb`/`app_ar.arb`) via Flutter's own `intl_utils` code generation, the Dart-ecosystem equivalent of the web's `lib/i18n/en.ts`/`ar.ts` pair sharing one `Dictionary` type — a key present in one ARB file and missing in the other fails the mobile CI's own `flutter gen-l10n --lint` step, mirroring `npm run i18n:check`'s "fails if any key exists in `en.ts` but not `ar.ts`" exactly. QAYD does not maintain two separate translation sources for the same string across web and mobile; both pull from the same translated-copy source of record, keyed identically, so a string reviewed and approved once for register and accuracy (`docs/frontend/DESIGN_LANGUAGE.md → Voice & Microcopy`'s "would a CFO reading this in a board pack find it precise and calm" bar) is correct everywhere it appears, not re-translated per platform.

## Numerals, currency, and dates

Financial figures render in **Western Arabic numerals even in the Arabic locale**, identically to the web's own rule (`docs/frontend/DESIGN_LANGUAGE.md → Tabular numerals`: "Kuwait and the wider Gulf write financial amounts with Western (Latin) digits even in fully Arabic sentences"). Flutter's `intl` package formats every amount via `NumberFormat.currency(locale: 'ar', name: currencyCode)` with `numberingSystem` pinned to Western digits explicitly, the direct counterpart of the web's `Intl.NumberFormat(locale, { numberingSystem: "latn" })` override — this is never left to `intl`'s Arabic-locale default, which would otherwise render Eastern Arabic-Indic digits (٠١٢٣...) that no Gulf ledger, invoice, or spreadsheet actually uses. KWD amounts render to three decimal places (fils), matching `docs/frontend/DESIGN_LANGUAGE.md`'s `minorUnitDigits` rule exactly, via the same `CURRENCY_DISPLAY_DECIMALS` lookup the web's `AmountCell` uses, ported as a Dart constant map so the two never silently disagree on how many decimals KWD gets.

## Hijri calendar option

Kuwait and the wider Gulf commonly reference the Hijri calendar alongside Gregorian for personal and some business context, though QAYD's ledger, fiscal years, and every stored date remain exclusively Gregorian/ISO 8601 at the API layer — this is a presentation-only overlay, never a second date system the backend has to reconcile. A per-user `display_calendar` preference (`gregorian` (default) | `hijri` | `system_default`), stored in the same `users.settings` JSONB column already carrying `ui_theme` and `quiet_hours`, drives which calendar the app *displays* dates in; the underlying value transmitted to and received from `/api/v1` is always the Gregorian ISO 8601 string, exactly paralleling the numerals rule above — Hijri is a rendering transform of a value whose stored, transacted, and API-transmitted form never changes. The Flutter app converts for display using the `hijri` Dart package (Umm al-Qura algorithm, matching the convention Gulf government and banking calendars use); the equivalent web/PWA rendering, where offered, uses `Intl.DateTimeFormat(locale + '-u-ca-islamic-umalqura')`. A Hijri-displayed date always carries a small Gregorian equivalent on tap or long-press — never presented as the *only* reading of a date a bank statement or an auditor will also need in Gregorian.

## Bidirectional text and mixed-script fields

A customer or vendor's Arabic trade name embedded inside an otherwise-English list, or a Latin-script partner name inside an Arabic sentence, is wrapped in Flutter's `Bidi`-aware text rendering (the `flutter_bidi`-equivalent isolation `Directionality`/`TextSpan` composition already provides) so mixed-script strings never visually reorder unpredictably — the same concern `docs/frontend/DESIGN_LANGUAGE.md`'s `dir="ltr"` numeral-span rule addresses for the web, applied to the broader class of mixed-script UI strings the mobile app renders in vendor names, customer names, and free-text memo fields.

# Accessibility

## Conformance target, restated for a compiled app

`docs/frontend/ACCESSIBILITY.md → Standards & Targets` fixes the platform's baseline at **WCAG 2.2 AA**; this document does not lower that bar for native mobile; it restates the same conformance target against Flutter's own accessibility APIs (`Semantics` widgets wrapping Android's accessibility tree and iOS's `UIAccessibility`) rather than the web's ARIA/landmark model, because the two platforms expose accessibility information through structurally different APIs even when the design intent is identical. Where `docs/frontend/ACCESSIBILITY.md`'s **Supported assistive technology matrix** already lists "VoiceOver — Safari — iOS — Secondary — mobile web usage (approvals on the go)," this document upgrades that same journey, on the native surface, from secondary to primary: VoiceOver (iOS) and TalkBack (Android) are first-class, fully-tested assistive technologies for every Tier 1 journey in `# Priority Mobile Journeys`, approvals foremost among them.

## Target size

`docs/frontend/ACCESSIBILITY.md → Target size` already states the platform's internal bar independent of WCAG 2.2's own 24×24px minimum: "QAYD's internal bar is 44×44 for primary/mobile actions." Every tappable control in the Flutter app — a bottom-bar destination, an `ApprovalCard`'s Approve/Reject buttons, a swipe-to-dismiss row, a form field's clear button — meets or exceeds a 44×44 logical-pixel hit area regardless of its visible glyph size, using `Semantics` + `GestureDetector`'s own hit-test expansion rather than a smaller visible control with invisible padding standing in for the real target, matching the same "padding, never a smaller box with a larger icon crammed into it" discipline `docs/frontend/RESPONSIVE_DESIGN.md → Touch Targets & Gestures` states for the web. Adjacent interactive elements keep the same minimum 8px gap the web enforces, for the identical reason — reducing mis-taps between an Approve and a Reject button carries real financial consequences on a phone exactly as it does on a desktop.

## Dynamic Type and font scaling

Text respects the OS-level accessibility text-size preference — iOS Dynamic Type and Android's font-scale system setting — up to and including QAYD's tested ceiling of **200%**, via Flutter's `MediaQuery.textScaler`, never a fixed, unscalable `TextStyle`. This is the direct mobile analogue of `docs/frontend/RESPONSIVE_DESIGN.md → Fluid type scale`'s own rule for the web ("every formula above includes a `rem` term... a user running their browser at 150% text size still gets a proportionally larger heading") — QAYD never hardcodes a font size in logical pixels without routing it through the platform's text-scaling API, and layouts are tested, not merely assumed, to reflow correctly at the 200% ceiling: a bottom-bar label wraps or truncates gracefully rather than clipping, and a card's numeric hero figure (`docs/frontend/DESIGN_LANGUAGE.md`'s `numeral-hero`) shrinks its surrounding chrome before it shrinks the number itself, mirroring the same "remove a decorative element before removing whitespace around a number" design principle applied to a scaling constraint instead of a viewport constraint.

## Screen reader semantics for financial data

Every `Amount`-equivalent widget carries a `Semantics` label that reads a monetary figure unambiguously — "forty-two thousand three hundred eighteen point six Kuwaiti dinars," not a raw string a screen reader would otherwise fragment digit-by-digit — mirroring `docs/frontend/ACCESSIBILITY.md → Amounts must be read unambiguously`'s own rule for the web's `AmountCell`. A debit/credit pair in a journal-line list is announced with its column role stated explicitly ("Debit, one thousand fifty dinars" rather than just the bare number), since `docs/frontend/DESIGN_LANGUAGE.md`'s debit/credit color rule ("color is reserved... never a raw debit or credit") means a screen-reader user relying on color alone would have nothing to distinguish the two columns by in the first place — the semantic label is not a nice-to-have here, it is the *only* signal for a non-sighted user in exactly the case QAYD's own color-neutrality rule creates.

## Biometric prompts and assistive technology

The native `BiometricPrompt`/`LocalAuthentication` system UI (`# Biometric Auth & Security`) is itself fully accessible by construction — it is the OS's own accessible dialog, not a custom one QAYD would need to retrofit — and its device-passcode fallback path is the accessible route for a user who cannot or does not use biometric hardware, ensuring the approval journey in `§1` never has a single point where an assistive-technology user is blocked with no alternative path to the same confirmation gesture a sighted, biometric-enrolled user takes.

## Reduced motion and vestibular safety

`prefers-reduced-motion`'s Flutter counterpart (`MediaQuery.of(context).disableAnimations`, reflecting the OS-level "Reduce Motion" toggle on both platforms) gates every animated transition exactly as specified in `# Design Adaptation → Motion`, with one addition specific to a touch surface: the Home feed's swipe-between-cards gesture and the Approval card's drag-to-decide gesture (`docs/frontend/RESPONSIVE_DESIGN.md → Swipeable approval card`) both disable the *drag itself*, not merely its animation, when reduced motion is active — "a user who has asked the OS for reduced motion is not asking for the same gesture with the polish removed... the explicit Approve/Reject buttons become the only interaction path for that user," identical to the web's own stated rationale, ported verbatim as a requirement rather than reworded as a suggestion.

# Feature Availability Matrix

This table operationalizes `# Platform Strategy`'s three-tier parity policy per primary module, so no screen's mobile treatment is left to individual judgment. "Native" reflects the compiled Flutter app; "Web" reflects the same Next.js client at Mobile/Tablet viewport widths (`docs/frontend/RESPONSIVE_DESIGN.md`'s breakpoint tiers).

| Module | Native | Web (Mobile/Tablet) | Notes |
|---|---|---|---|
| Dashboard / AI Command Center | Full — `mobile_command_feed` | Full — reflowed single column, `docs/frontend/RESPONSIVE_DESIGN.md → Dashboard Reflow` | Native adds voice-first Assistant access and push; content parity is otherwise exact |
| Approval Center | Full — dedicated tab, biometric-gated decisions | Full — same screen, step-up MFA only (no device biometric hook from a browser tab) | Tier 1. See `# Biometric Auth & Security` |
| AI Chat / Assistant | Full — dedicated tab, voice-first | Full — docked panel or `/assistant` route | Tier 1. Identical orchestration, per `docs/frontend/AI_CHAT.md` |
| Notifications | Full — Inbox + Preferences, full-screen | Full — same screens | Tier 1 |
| Accounting — Chart of Accounts, GL, Trial Balance | Read-only browse and search; no inline editing | Read-only summary/card view below `md:`, full table from `md:` up | Tier 3 — `docs/frontend/RESPONSIVE_DESIGN.md`'s own "deliberately degrade to a read-only summary" stance, taken one step further on native's smallest canvas |
| Accounting — Journal Entries | View/list/search full; compose limited to ≤2 lines via a simplified form | Full composer at every tier (`docs/frontend/RESPONSIVE_DESIGN.md → Multi-line editors`) | Tier 2/3 hybrid — a simple two-line entry ships natively; anything longer redirects to web |
| Financial Statements (Balance Sheet, P&L, Cash Flow) | Read-only, single-period view; no comparative/consolidation builder | Read-only responsive view; comparative/consolidation controls collapse to a Filters sheet | Tier 3 for authoring; Tier 1 for reading, since "cash position at a glance" (`§6`) depends on it |
| Banking — Accounts, Transactions | Full read; Transfers view-only (initiation redirects to web, always full-page per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s "sensitive mutations never render as a quick-create modal") | Full, including transfer initiation | Transfer *approval* is native-capable (Tier 1, via Approval Center); transfer *initiation* is Tier 3 |
| Bank Reconciliation | Not available | Full workbench | Tier 3 — a matching workbench needs the desktop/tablet canvas `docs/frontend/BANK_RECONCILIATION.md` assumes |
| Sales — Customers, Quotations, Orders, Invoices | Full CRUD, camera-accelerated capture for none of these (data-entry forms, not documents to photograph) | Full CRUD | Tier 2 |
| Purchasing — Vendors, Purchase Orders, Bills | Full CRUD; Bills gain camera capture (`§2`) | Full CRUD | Tier 2 |
| Inventory — Items, Movements, Stock Counts, Transfers | Full CRUD, offline-capable (`# Offline & Sync`) — this is the Warehouse Employee's primary surface | Full CRUD, online-only | Tier 1/2 — Stock Counts specifically are a native-first journey given warehouse connectivity |
| Payroll | Employees/Payslips read-only; the five-stage Run wizard not available | Employees/Payslips read-only; Run wizard full, desktop-oriented | Tier 3 for the wizard; Payroll *release approval* remains Tier 1 via the Approval Center |
| Tax | Read-only Returns/Codes; filing redirects to web | Full | Tier 3 for filing; Tax *submission approval* remains Tier 1 via the Approval Center |
| Reports | Run and view existing report definitions; authoring new definitions not available | Full, including the Report Definition authoring surface | Tier 3 for authoring |
| Settings — Profile, Notifications, Appearance | Full | Full | Tier 1 |
| Settings — Roles/Permission Studio, API Keys, Webhooks, Billing | Not available | Full | Tier 3 — administrative surfaces with no mobile-first use case |

The recurring pattern across this table is deliberate, not incidental: **reading and approving are Tier 1 everywhere; authoring dense, multi-field, or multi-step records is Tier 2 on simple documents and Tier 3 on genuinely complex ones.** A role whose job is overwhelmingly Tier 3 work in this table (a Senior Accountant closing a multi-period Trial Balance, an HR Manager running Payroll's five-stage wizard) still gets full value from the native app for the Tier 1 journeys every role shares — approvals, the dashboard glance, notifications, and the Assistant — even on the days their primary work stays on a laptop.

# Edge Cases

| Scenario | Behavior |
|---|---|
| A push notification's `target` resolves to a record in a company the user has since lost access to (offboarded mid-flight) | The deep link opens the app to a "You no longer have access to this" state identical in wording to the desktop's `not-found.tsx` treatment for a cross-company resource (`docs/frontend/FRONTEND_ARCHITECTURE.md → Dynamic segments, loading, and error conventions`) — never a raw 403/404, and never a silent redirect to Home that could be mistaken for the notification being stale rather than access having changed. |
| The device's biometric hardware is enrolled but the OS reports a lockout (too many failed attempts elsewhere on the device) | `local_auth` surfaces the OS's own lockout state; QAYD falls back to the device passcode/PIN via the same API call, never invents its own retry-counting logic on top of the OS's. |
| A user switches active company (`POST /api/v1/auth/switch-company`) while an item sits in the offline outbox | The queued mutation retains the `company_id` it was captured under (embedded in its stored payload at capture time, per `docs/api/API_ARCHITECTURE.md`'s multi-tenancy rule that every record is company-scoped from creation) and syncs against that company regardless of which company is active when connectivity returns — switching the active company on-screen never silently reassigns a pending draft's tenant. |
| A sensitive approval's biometric gate succeeds, but the subsequent `POST /api/v1/approvals/{id}/approve` returns `409` because another approver already decided the same multi-step chain | The biometric ceremony is not wasted or repeated — the app surfaces the conflict inline ("Mariam Al-Sabah already approved this") exactly as the desktop's optimistic-then-reconciled cache update would, per `docs/frontend/APPROVAL_CENTER.md`'s own handling of a mid-flight eligibility change, and does not silently re-prompt for biometrics on a request that no longer needs a decision. |
| A device is shared between shifts on a warehouse floor (the `docs/api/AUTHENTICATION_API.md → Concurrent Session Policy`'s "shared warehouse terminals" kiosk PIN mode) | The native app supports the identical kiosk PIN swap for Warehouse Employee accounts scoped to `stock_movements`/`stock_counts` writes only, reusing the same low-privilege `mfa` factor type rather than a mobile-specific parallel mechanism — the device holds one long-lived, device-bound refresh token and each worker's PIN swaps `sub`/`created_by` attribution without a fresh full login. |
| The app is force-quit mid-sync with unsynced outbox items | `outbox_mutations` persists to disk (SQLite, not in-memory) before any network call begins, so a force-quit mid-upload resumes cleanly on next launch from the last `status = 'pending'`/`'syncing'` row rather than losing or double-submitting the record — the stored `Idempotency-Key` makes a resumed-and-retried upload safe even if the original request had actually reached the server before the quit. |
| A user denies camera permission during `§2`'s capture flow | The quick-action sheet falls back to the device's photo library picker and, failing that, a manual entry form with an "Attach later" affordance — capture is the fastest path, never the only path, to originating an expense or bill. |
| App Store/Play Store review rejects or delays a release carrying a breaking API-contract change | `docs/api/API_ARCHITECTURE.md`'s 12-month minimum deprecation window is sized specifically to absorb this; the old API version continues serving the not-yet-updated installed base for the full notice period regardless of how long store review takes, per `# Platform Strategy`'s "native is deliberately the slower, more conservative lane." |
| A user's device language is neither English nor Arabic | The app falls back to English (matching `NEXT_PUBLIC_DEFAULT_LOCALE`'s web-side default, per `docs/frontend/README.md → Environment variables`), with the in-app language switcher (Settings) always available to select Arabic explicitly regardless of device locale. |

# Future

**FCM/APNs migration to a dedicated `NotificationChannel` implementation with delivery receipts.** Today's push architecture (`# Push Notifications`) confirms delivery to Firebase's API, not to the device; a planned enhancement tracks per-device delivery and open receipts back onto `user_devices.last_seen_at`-adjacent columns, closing the loop `docs/database/ERD.md`'s own "Future Expansion" note left open.

**WhatsApp as a sixth notification channel.** `docs/ai/AI_COMMAND_CENTER.md → Mobile Experience`'s own forward note — "Gulf-market usage patterns favor WhatsApp over email or push notification for anything genuinely time-sensitive" — names the precondition explicitly: a WhatsApp Business API relationship and a resolved design for how a two-way WhatsApp reply ("approve the payment run") maps back into `ai_approval_requests` **without weakening the biometric-confirmation standard this document sets** for sensitive approvals. Until a WhatsApp reply can be bound to an equivalent live confirmation gesture, WhatsApp remains read-only/notify-only, never an approval channel of its own, consistent with `docs/frontend/NOTIFICATIONS.md`'s own "Coming soon" treatment of the same channel on desktop.

**Tablet-optimized layouts beyond the phone/desktop split.** The Flutter app currently ships one adaptive layout scaling from phone to small tablet; a dedicated two-pane tablet layout (list + detail, mirroring `docs/frontend/RESPONSIVE_DESIGN.md`'s `lg:` two-panel pattern) is a natural extension once native tablet usage among Owners and CFOs justifies the investment.

**Expanding Tier 2/3 modules toward native parity.** Bank Reconciliation and the Payroll Run wizard are the two largest Tier 3 gaps in `# Feature Availability Matrix`; both are candidates for a future native "guided," single-decision-at-a-time redesign (matching an approval card's own single-focus pattern) rather than a literal port of the desktop workbench, once user research confirms the underlying journey — not just the existing desktop UI — translates to a phone.

**A dedicated `docs/mobile/` documentation tree.** As the native codebase grows past its first slice, the Flutter-specific implementation depth this document deliberately defers (widget-by-widget structure, the full Riverpod provider graph, native build and release pipeline detail beyond `mobile/README-BUILD.md`-style operational notes) belongs in its own tree, mirroring how `docs/frontend/` itself grew from a handful of foundation documents into the full screen-by-screen catalogue `docs/frontend/README.md` now indexes. This document remains the product-and-cross-cutting-engineering specification that tree would build on top of, exactly as `docs/frontend/FRONTEND_ARCHITECTURE.md` relates to each individual screen document today.

# End of Document
