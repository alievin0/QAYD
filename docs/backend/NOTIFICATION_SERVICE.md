# Notification Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: NOTIFICATION_SERVICE
---

# Purpose

The Notification Service is the Laravel-side engine that turns a *fact that happened somewhere in the
platform* into *the right message reaching the right person on the channel they chose*. A journal
entry is proposed by an agent and now waits on a human; a bank reconciliation finishes and its results
are ready to review; the fiscal month is closing in three days; a colleague `@mentions` an auditor on
a flagged transaction; a security-critical setting changed. Each of these is a domain event emitted by
some owning module — Accounting, Banking, the AI Service, the Workflow engine — and none of those
modules knows, or should know, *who* wants to hear about it, *through which channel*, *in what
language*, or *whether now or batched into a quiet-hours digest*. That routing, preference resolution,
fan-out, and delivery is this service's single job.

It is deliberately a thin, high-fan-out **application service over a fixed schema**. It owns no
business rule of its own — it never decides whether an invoice is overdue or whether a proposal needs
approval; the owning module already decided that and announced it. What the Notification Service owns
is the leg between "the event is a committed fact" and "the human has been told," and the governance
that makes that leg safe in a multi-tenant, RBAC-scoped, bilingual product: a user is only ever
notified about entities they are permitted to see, a preference the user set is always honored (except
for the small, fixed set of security notifications that cannot be silenced), and every channel is
attempted with the same idempotency and retry discipline every other side effect in the platform
carries (see [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md)).

This document is the backend counterpart to the frontend [../frontend/NOTIFICATIONS.md](../frontend/NOTIFICATIONS.md)
(the Notifications Center screen and Topbar bell) and consumes the same fixed schema
(`notification_templates`, `notifications`, `notification_preferences`) that
[../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md) and the platform ERD
define. It does not extend that schema with new columns; it specifies the write discipline, the fan-out
pipeline, the preference matrix, the Reverb broadcast contract, and the `/api/v1/notifications/*`
endpoints that stand on top of it. Everything here is Laravel 12 / PHP 8.4 per
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md).

# Responsibilities

| # | Responsibility | Boundary |
|---|---|---|
| 1 | Subscribe to notification-worthy domain events and fan each out to its eligible recipients | It reacts to events; it never originates the business fact that produced them |
| 2 | Resolve *who* should receive a notification from the event's subject + each candidate's RBAC scope | A user who cannot read the underlying entity is never a recipient — visibility is a hard filter, not a preference |
| 3 | Resolve *how* each recipient is reached from `notification_preferences`, per event code and channel | The user's preference is authoritative, except for the fixed non-optout set |
| 4 | Render each notification bilingually from a `notification_templates` row | Title/body come from the template + the event payload; the service never hand-writes copy inline |
| 5 | Persist one `notifications` row per (recipient, channel) and drive delivery on each channel | The `notifications` table is the durable system of record for the in-app inbox and delivery status |
| 6 | Broadcast the in-app notification on the recipient's private Reverb channel for instant unread-count update | `private-company.{id}.notifications.{userId}` — the same channel the Topbar bell subscribes to |
| 7 | Apply batching and quiet-hours so a burst or an off-hours event becomes a digest, not a storm | A quiet-hours or batched message is deferred and coalesced, never dropped |
| 8 | Own read/unread state and its bulk transitions for the inbox | `read_at` is the single truth of read state; unread count derives from it |
| 9 | Expose the `/api/v1/notifications/*` API the web/mobile inbox and preference matrix read and write | Every row returned is `user_id`-scoped to the caller; a user reads only their own inbox |

What this service explicitly does **not** own: the *catalogue of which channels physically exist*
(the platform's Notification Engine / SMS/email/push provider integrations, per
[../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md)); the *domain events
themselves* (each owning module); the *approval decision* behind an approval notification
([AI_SERVICE.md](./AI_SERVICE.md), the Workflow engine); and the *screen* that renders the inbox
([../frontend/NOTIFICATIONS.md](../frontend/NOTIFICATIONS.md)). It is the router and the ledger of
"who was told what, when, and through which pipe."

# Domain Model

The service operates over three tables whose DDL is authoritative in the platform ERD and
[../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md). It consumes them; it does
not redefine them.

- **`notification_templates`** — the platform-wide catalogue of notifiable events. `code` (the stable
  key, e.g. `approval.requested`, `reconciliation.ready`, `period.closing_soon`, `mention.created`,
  `security.permission_changed`), `title_en`/`title_ar`, `body_en`/`body_ar` (with `{placeholder}`
  interpolation slots), `default_channels`, `category` mapping, and `severity`. Like `ai_agents`, this
  roster is the product, not a tenant choice — it has no `company_id` and no Row Level Security, because
  it holds no tenant data. `channel` across the platform is constrained
  `CHECK (channel IN ('in_app','email','sms','push'))`; WhatsApp is a named Future Expansion and is
  surfaced by the frontend as a locked "Coming soon" column, per
  [../frontend/NOTIFICATIONS.md](../frontend/NOTIFICATIONS.md).
- **`notifications`** — the delivered (or delivering) instance. `id`, `company_id`, `user_id`,
  `notification_template_id`, `channel`, `title`, `body`, `data` (JSONB — the deep-link `target`,
  the subject reference, the rendered variables, the batch/digest grouping key), `read_at`, `sent_at`,
  `delivery_status`, `created_at`. One row per (recipient, channel): a single approval event that
  reaches three approvers over in-app + email is six rows. This is the durable inbox and the delivery
  ledger.
- **`notification_preferences`** — per-user opt-in matrix. `id`, `company_id`, `user_id`, `event_code`,
  `channel`, `is_enabled`, unique per `(company_id, user_id, event_code, channel)`. A missing row falls
  back to the template's `default_channels`; a present row with `is_enabled = false` suppresses that
  (event, channel) pair for that user — **except** for the fixed non-optout set
  (`security.permission_changed`, `audit.security_event`), which is forced enabled at the application
  layer regardless of the row's content.

Two derived concepts the service computes rather than stores as columns:

- **`category`** — a presentation grouping (`Approvals Due`, `AI Alerts`, `Fraud & Risk`, `Deadlines`,
  `System`) derived server-side from the owning template's `code`, returned as a resource attribute on
  every `notifications` row exactly as `read` (`read_at IS NOT NULL`) is. The frontend filters by it
  and never re-derives it from a raw code string.
- **`read`** — the boolean `read_at IS NOT NULL`, the single source of unread state.

The lifecycle of a `notifications.delivery_status` for a non-in-app channel:

```
queued ──► sent ──► delivered            (provider accepted, and confirmed where the channel supports it)
      ├──► sent ──► bounced              (provider accepted, recipient rejected — a hard failure, no retry)
      ├──► failed ──► (retried) ──► sent (transient provider error, backoff retry succeeded)
      └──► suppressed                    (quiet-hours/batch coalesced this row into a digest instead)
```

In-app notifications skip the provider hops: they are `sent` the instant the row commits and the
Reverb broadcast fires, and become `read` when the user opens them.

# Key Classes

Namespaced under `App\Services\Notifications`, controllers under `App\Http\Controllers\Api\V1\Notifications`.
Channel transports sit behind a common interface so a channel can be faked in tests and added without
touching the fan-out core.

```php
<?php

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationEnvelope;

/**
 * The fan-out core. Given a notification-worthy domain event, resolve recipients,
 * resolve each recipient's channels, render, persist, and dispatch delivery.
 * It is the single entrypoint every event listener funnels into.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly PreferenceResolver $preferences,
        private readonly NotificationRenderer $renderer,
        private readonly NotificationRepository $notifications,
        private readonly ChannelRouter $channels,
        private readonly QuietHours $quietHours,
    ) {}

    /** @return int number of notifications rows created (across recipients × channels) */
    public function dispatch(NotifiableEvent $event): int
    {
        $template = $this->renderer->templateFor($event->code());        // notification_templates by code
        $created  = 0;

        foreach ($this->recipients->for($event) as $user) {              // RBAC-scoped recipient set
            $channels = $this->preferences->channelsFor($user, $template); // preference matrix + non-optout floor
            foreach ($channels as $channel) {
                $envelope = $this->renderer->render($template, $channel, $event, $user); // bilingual, deep-linked

                if ($this->quietHours->shouldDefer($user, $template, $channel)) {
                    $this->notifications->stageForDigest($envelope);     // coalesced later, never dropped
                    continue;
                }

                $row = $this->notifications->create($envelope);          // durable notifications row
                $this->channels->deliver($row);                          // in-app broadcast now; email/sms/push queued
                $created++;
            }
        }

        return $created;
    }
}
```

```php
<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * The load-bearing security class: it decides WHO may be told, and it filters
 * every candidate through the same permission the owning entity requires to read.
 * A user who cannot open the subject is never a recipient.
 */
final class RecipientResolver
{
    public function __construct(private readonly UserDirectory $directory) {}

    /** @return iterable<User> */
    public function for(NotifiableEvent $event): iterable
    {
        $candidates = match ($event->recipientStrategy()) {
            'explicit'        => $this->directory->byIds($event->recipientUserIds()), // mention, direct assignment
            'approvers'       => $this->directory->approversFor($event->subjectRef()), // the approval chain
            'role'            => $this->directory->withPermission($event->requiredPermission()), // e.g. all fraud reviewers
            'company_admins'  => $this->directory->admins(),                          // system/security notices
        };

        // Belt-and-braces: even an "approvers" or "role" set is re-checked against
        // read access to the concrete subject, so a stale role grant never leaks a subject.
        return array_filter(
            $candidates,
            fn (User $u) => $event->subjectRef() === null
                || $u->canReadEntity($event->subjectRef()),   // Policy check against the owning module
        );
    }
}
```

| Class | Layer | Responsibility |
|---|---|---|
| `NotificationDispatcher` | Application | The fan-out core; the single entrypoint every listener calls |
| `RecipientResolver` | Application | Resolves the RBAC-scoped recipient set for an event; the visibility hard-filter |
| `PreferenceResolver` | Domain | Merges `notification_preferences` with template defaults and the non-optout floor into a channel list |
| `NotificationRenderer` | Domain | Renders bilingual `title`/`body` and the `data.target` deep link from template + payload |
| `ChannelRouter` | Application | Dispatches a persisted row to its channel transport (broadcast now, or queue the provider send) |
| `NotificationChannel` (interface) | Infrastructure | One implementation per channel: `InAppChannel`, `MailChannel`, `SmsChannel`, `PushChannel` |
| `QuietHours` | Domain | Decides whether a (user, template, channel) send defers into a digest window |
| `DigestCompiler` | Application | Coalesces staged rows into one per-user digest on the scheduled boundary |
| `NotificationRepository` | Infrastructure | Reads/writes `notifications`; enforces `company_id` + `user_id` scoping and unread counts |
| `NotificationReadService` | Application | Owns read/unread and bulk read transitions |

`PreferenceResolver` is deliberately a pure function so the "a preference is always honored, except the
fixed floor" rule is provable in isolation and identical for every event:

```php
<?php

namespace App\Services\Notifications;

final class PreferenceResolver
{
    /** Channels that can never be silenced, whatever the preference row says. */
    private const NON_OPTOUT_EVENTS = ['security.permission_changed', 'audit.security_event'];

    /** @return list<'in_app'|'email'|'sms'|'push'> */
    public function channelsFor(User $user, NotificationTemplate $template): array
    {
        $prefs = $this->preferenceRows($user, $template->code);          // (company_id,user_id,event_code) rows

        $channels = [];
        foreach (['in_app', 'email', 'sms', 'push'] as $channel) {
            $enabled = $prefs[$channel]?->is_enabled
                ?? in_array($channel, $template->default_channels, true); // fallback to template default

            if (in_array($template->code, self::NON_OPTOUT_EVENTS, true)) {
                $enabled = $enabled || $channel === 'in_app';             // security floor: at minimum in-app
            }
            if ($enabled) {
                $channels[] = $channel;
            }
        }
        return $channels;
    }
}
```

# Endpoints Backed

All endpoints follow the platform envelope (`{success,data,message,errors,meta,request_id,timestamp}`),
require `X-Company-Id`, and return only the calling user's own rows. Per
[../frontend/NOTIFICATIONS.md](../frontend/NOTIFICATIONS.md) the inbox route itself has **no gating
permission** — every authenticated company member owns their own inbox and preferences — so
authorization here is *ownership* (`user_id = auth id`), not an RBAC key. RBAC still governed *whether a
row was ever created*: the recipient filter already guaranteed the user could see every subject a row
references.

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/notifications` | Own inbox | Cursor-paginated inbox; filter by `category`, `read`, `channel=in_app`, `template_code`, date range |
| `GET` | `/api/v1/notifications/unread-count` | Own inbox | The badge count the Topbar bell polls/subscribes; `{ total, by_category }` |
| `POST` | `/api/v1/notifications/{id}/read` | Own row | Mark one read (`read_at = now()`); idempotent |
| `POST` | `/api/v1/notifications/{id}/unread` | Own row | Restore unread (`read_at = null`) |
| `POST` | `/api/v1/notifications/read-all` | Own inbox | Bulk mark read; accepts optional `category`/`before` scoping; returns count affected |
| `GET` | `/api/v1/notifications/preferences` | Own prefs | The full per-event × per-channel matrix, merged with template defaults and locked flags |
| `PATCH` | `/api/v1/notifications/preferences` | Own prefs | Upsert `(event_code, channel, is_enabled)` rows; a locked event/channel returns `422 preference_locked` |
| `POST` | `/api/v1/notifications/preferences/quiet-hours` | Own prefs | Set the user's quiet-hours window + timezone (stored in the user's `settings` bag) |

The inbox list resource is `user_id`-scoped and returns the derived attributes the frontend renders:

```json
GET /api/v1/notifications?category=Approvals+Due&read=false&per_page=20
X-Company-Id: 4021

{
  "success": true,
  "data": [
    {
      "id": 771204,
      "category": "Approvals Due",
      "template_code": "approval.requested",
      "channel": "in_app",
      "title": "Journal entry awaiting your approval",
      "body": "General Accountant proposed JE for Gulf Prime Distribution Co. — KWD 186.500",
      "read": false,
      "read_at": null,
      "created_at": "2026-07-14T09:22:41Z",
      "data": {
        "target": "/approvals/8841",
        "subject": { "type": "ai_decision", "id": 55231 },
        "severity": "action_required",
        "ai_assisted": true
      }
    }
  ],
  "meta": { "pagination": { "next_cursor": "eyJpZCI6NzcxMTgwfQ", "unread_total": 12 } },
  "request_id": "8f2c…",
  "timestamp": "2026-07-14T09:22:44Z"
}
```

The preference `PATCH` is the one place a locked toggle is enforced server-side, so a crafted request
cannot silence a security notification the UI renders as locked:

```json
PATCH /api/v1/notifications/preferences
{ "preferences": [ { "event_code": "security.permission_changed", "channel": "in_app", "is_enabled": false } ] }

→ 422 { "success": false, "errors": [ { "code": "preference_locked",
        "field": "preferences.0", "message": "Security notifications cannot be turned off." } ] }
```

# Database Tables Owned

The Notification Service is the Laravel-side owner (reader/writer through Eloquent + Repository) of the
notification tables; ownership means write discipline, tenant scoping, and inbox correctness — not
re-declaring their columns (authoritative in [../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md)
and the ERD).

| Table | Owned write path | Notes |
|---|---|---|
| `notification_templates` | Seeded once, versioned like code | Platform-wide, no `company_id`, no RLS — the roster is the product |
| `notifications` | `NotificationRepository::create` (fan-out) + `NotificationReadService` (read state) | One row per (recipient, channel); `company_id` + `user_id` scoped; the durable inbox + delivery ledger |
| `notification_preferences` | `PATCH /notifications/preferences` (own rows only) | Unique `(company_id, user_id, event_code, channel)`; missing row ⇒ template default |

`notifications` carries `company_id BIGINT NOT NULL` and the same Row Level Security policy that
protects every tenant table, scoped to `current_setting('app.current_company_id')::bigint`; it
additionally carries `user_id` and every read path narrows to `user_id = auth()->id()` so one company
member can never read another's inbox. The table is high-insert-volume (every fan-out multiplies by
recipients × channels), so it is a candidate for the platform's line-table partitioning discipline
(`RANGE (created_at)`, monthly, `pg_partman`) described in
[../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md) — old read notifications
detach-and-archive to R2 on the statutory-agnostic operational retention window rather than being
deleted row-by-row. Composite indexes lead with `company_id` then `user_id, read_at` so the unread-count
query stays sargable.

# Multi-Tenancy Enforcement

Tenancy and per-user isolation are enforced in three layers, and this service is unusual in needing
*both* the company boundary and a per-user boundary inside it.

1. **The header ring.** Every `/api/v1/notifications/*` request carries `X-Company-Id`; the
   `EnsureCompanyScope` middleware verifies the user has a `company_users` row for it (else `403`) and
   sets `app.current_company_id` for the connection. A `company_id` in any JSON body is ignored for
   scoping.
2. **The RLS ring.** `notifications` and `notification_preferences` carry Row Level Security scoped to
   `app.current_company_id`, so a query can only ever touch the active tenant's rows even if a repository
   forgot a `where`.
3. **The ownership ring.** Inside the tenant, every read and write additionally narrows to
   `user_id = auth()->id()`. There is no permission that lets one user read another's inbox — not even
   Owner — because a notification's *content* was already RBAC-scoped at fan-out to what its recipient
   could see; letting an admin read a subordinate's inbox would re-widen a boundary the recipient filter
   deliberately narrowed.

The most load-bearing enforcement happens *before* a row exists, at fan-out. `RecipientResolver` filters
every candidate through `canReadEntity($subjectRef)` — the same Policy the owning module uses for a
direct read — so a notification is physically never created for a user who cannot open its subject. A
job that fans out runs off the request thread and therefore re-establishes tenant context via the base
`TenantAwareJob` (`SET LOCAL app.current_company_id`) before touching any model, per
[SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md). A mention of a user who was later removed from the
company, or whose role was narrowed below the subject's read permission between event and fan-out,
simply produces no row for that user — a dropped notification is always the safe failure here, never a
leak.

# Events, Queues & Realtime

**Inbound: domain events → fan-out.** The Notification Service subscribes to a curated set of domain
events on the dedicated `events-notifications` queue. A queued listener binds `afterCommit()` so a
rolled-back business write never produces a notification, and funnels every subscribed event through the
one `NotificationDispatcher::dispatch` entrypoint:

```php
<?php

namespace App\Listeners\Notifications;

use App\Jobs\Concerns\TenantAwareJob;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotifiableEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FanOutNotificationListener extends TenantAwareJob implements ShouldQueue
{
    public string $queue = 'events-notifications';
    public bool $afterCommit = true;
    public int $tries = 5;
    public array $backoff = [5, 30, 120, 300];

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(object $event): void
    {
        $this->bindTenant($event->companyId);                 // SET LOCAL app.current_company_id
        $this->dispatcher->dispatch(NotifiableEvent::fromDomainEvent($event));
        // Idempotent on (domain_event_uuid, user_id, channel): a retried listener never double-notifies.
    }
}
```

The subscribed events map to templates by category:

| Category | Example domain events | Recipient strategy |
|---|---|---|
| Approvals Due | `approval.requested`, `approval.reminder` | `approvers` — the resolved chain |
| AI Alerts | `ai.decision.proposed` (`suggest_only`), `ai.forecast.alert`, `ai.health_score.changed` | `role` (holders of `ai.analyze`) or explicit assignee |
| Fraud & Risk | `ai.risk_flag.raised`, `reconciliation.exception_aged` | `role` (fraud reviewers) |
| Deadlines | `period.closing_soon`, `tax.filing_due`, `invoice.overdue` | `role` scoped to the relevant module permission |
| Mentions | `mention.created` | `explicit` — the mentioned user id |
| System | `import.completed`, `report.generated`, `security.permission_changed` | `explicit` actor or `company_admins` |

**Idempotency.** Every notification write is keyed on `(domain_event_uuid, user_id, channel)`, so the
at-least-once `events-notifications` queue can redeliver freely without producing a duplicate inbox row
or a second email — the same check-then-act discipline every side-effecting job in the platform uses.

**Outbound: provider sends → per-channel queues.** In-app delivery is synchronous with the fan-out (the
row commits and the broadcast fires). Email/SMS/push are deferred onto per-channel queues
(`notifications-mail`, `notifications-sms`, `notifications-push`) so a slow provider never blocks fan-out
and a failed provider call retries with backoff and lands on `failed_jobs`/`delivery_status = 'failed'`
without losing the durable in-app copy.

**Realtime: Reverb.** The in-app channel broadcasts to the recipient's private per-user channel — the
exact channel the Topbar bell subscribes to:

```php
broadcast(new NotificationCreated($notification))->toOthers();  // private-company.{id}.notifications.{userId}
broadcast(new UnreadCountChanged($userId, $unreadTotal));       // same channel; drives the badge live
```

Approval-shaped and AI notifications additionally already broadcast their *source* state on
`private-company.{id}.ai` and `private-company.{id}.dashboard` from the owning services
([AI_SERVICE.md](./AI_SERVICE.md)); the Notification Service does not re-broadcast those — it owns only
the `.notifications.{userId}` per-user inbox channel, keeping one broadcaster per channel and no
double-emit.

**Batching & quiet hours.** A user may configure a quiet-hours window (stored in their `settings`
bag, per [../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md)'s `settings`
column). During quiet hours, or when an event class is marked batchable, `QuietHours::shouldDefer`
routes the send through `stageForDigest` instead of an immediate channel send: the in-app row is still
created (the inbox is always live), but the email/SMS/push copy is coalesced. A scheduled
`DigestCompiler` (via the platform scheduler) runs at each quiet-hours boundary and per configured
digest cadence, groups staged rows per user by `data.digest_group`, and emits one "N notifications" email
rather than N — a burst of thirty overdue-invoice events becomes one digest, and nothing is dropped.
Non-optout security events bypass quiet hours entirely.

# Integrations

- **The owning business modules** — Accounting, Banking, Sales, Purchasing, Payroll, Tax, the AI
  Service, and the Workflow/Approval engine — are the *sources*. They emit domain events; they never
  call this service directly, and this service never writes their tables. The coupling is one-way and
  event-mediated, per [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md)'s inter-service rules.
- **The platform Notification Engine / channel providers** — the concrete email (transactional mail
  provider), SMS, and push (APNs/FCM) integrations named in
  [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md). Each is reached through a
  `NotificationChannel` implementation behind the interface, so providers are swappable and fakeable.
  WhatsApp Business API is the named Future Expansion fifth channel: the schema's `channel` CHECK does
  not yet include it and the frontend renders it "Coming soon," so the Notification Service ships a
  `WhatsAppChannel` stub that is registered but inert until the constraint and integration land — no
  message is ever silently promised.
- **Laravel Reverb** — the WebSocket server for the per-user in-app channel; the same Reverb instance
  the rest of the platform uses.
- **Redis** — the `events-notifications` and per-channel queues, and the per-user unread-count cache
  (invalidated on every create and read transition so the badge is never stale).
- **Cloudflare R2** — not a direct integration for delivery, but archived old `notifications` partitions
  land there via the platform's detach-and-archive pipeline.

# Permissions

The inbox and preferences are intentionally **ungated by an RBAC key** — every authenticated company
member owns their own inbox and preference rows, exactly like the Dashboard and the Approval/Command
centers. Authorization is *ownership*, not a permission:

| Concern | Rule |
|---|---|
| Reading the inbox | `user_id = auth()->id()` within the active company; no key, no cross-user read (not even Owner) |
| Marking read/unread | Own rows only; a `{id}` belonging to another user 404s (never 403 — the row's existence is not disclosed) |
| Editing preferences | Own `notification_preferences` rows only |
| The non-optout floor | `security.permission_changed`, `audit.security_event` cannot be disabled by anyone, including Owner — a data policy enforced at the preference-write layer, not an RBAC rule |

The RBAC grammar (`<area>.<action>`, per
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)) governs this service in exactly
one place that matters: *recipient resolution*. A candidate is only a recipient if they hold the read
permission for the notification's subject — `RecipientResolver` calls the owning module's own Policy, so
"who can be told about a payroll event" is precisely "who can read that payroll entity," with no separate
notification-visibility list to drift. This is the mechanism that makes "a user is only notified about
what they can see" a structural guarantee rather than a convention.

# Error Handling

The service degrades per-channel and never lets a delivery failure corrupt the durable inbox.

| Condition | Behavior | Notes |
|---|---|---|
| A recipient lost read access between event and fan-out | No row created for that user | The safe failure — a dropped notification, never a leak |
| Email/SMS/push provider transient error | `delivery_status = 'failed'`, retried with backoff | The in-app copy is unaffected and already delivered |
| Provider hard bounce (invalid address/number) | `delivery_status = 'bounced'`, no retry | Surfaced to the user as a channel-health hint, not an inbox error |
| Duplicate domain-event redelivery | No-op | Keyed on `(domain_event_uuid, user_id, channel)` — idempotent |
| `PATCH` preferences for a locked event/channel | `422 preference_locked` | The one server-side enforcement of the UI's locked toggle |
| `{id}` read/unread on another user's notification | `404` | The row's existence is never disclosed cross-user |
| Reverb unreachable | In-app row still committed; badge reconciles on next poll/reconnect | The WebSocket push is an optimization over the durable row, never the source of truth |
| Template code with no `notification_templates` row | Listener fails loud to `failed_jobs`, no partial fan-out | A missing template is a deploy/seed defect, caught in CI, never silently swallowed |

Every failure path is correlated by the originating `domain_events.uuid` carried as `request_id`, so an
engineer can trace one business fact to every (recipient, channel) row it produced and each row's
delivery outcome. Typed exceptions (`PreferenceLockedException` → `422`, `NotificationNotOwnedException`
→ `404`) are mapped by the global handler; the service never returns error arrays.

# Testing

- **Recipient resolution (feature, security-critical).** For each recipient strategy, assert the RBAC
  filter: a user without read access to the subject receives *no* row, asserted per strategy
  (`approvers`, `role`, `explicit`, `company_admins`). This is the most-covered path — a regression here
  is a data-leak, so it is tested against payroll, ledger, and AI subjects independently.
- **Preference resolution (unit, exhaustive).** `PreferenceResolver` as a pure function across the truth
  table: missing row ⇒ template default; explicit `is_enabled = false` suppresses; the non-optout floor
  forces `in_app` on for security events regardless of the row.
- **Idempotency (feature).** Two redeliveries of the same domain event on `events-notifications` produce
  exactly one inbox row per (recipient, channel) and one email.
- **Per-user isolation (feature).** User A cannot `GET`, read, or unread User B's notification; a
  cross-user `{id}` returns `404`, not `403`, and never discloses existence.
- **Quiet hours & digest (feature).** An event during a user's quiet-hours window creates the in-app row
  immediately but stages the email; the scheduled `DigestCompiler` coalesces N staged rows into one
  digest; a security event bypasses quiet hours.
- **Read state (feature).** `read-all` scoped by `category` marks only that category read; the
  unread-count endpoint and the cached badge both reflect the transition immediately.
- **Channel degradation (feature).** With a faked failing SMS provider, the in-app and email copies
  still deliver, the SMS row lands `failed` and retries, and the inbox is unaffected.
- **Reverb contract (feature).** Creating a notification broadcasts `NotificationCreated` and
  `UnreadCountChanged` on `private-company.{id}.notifications.{userId}` and nowhere else.
- **Preference lock (feature).** `PATCH` disabling a security event returns `422 preference_locked`.

Tooling per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md): Pest/PHPUnit, PHPStan + Pint in
CI. Channel transports are faked; the tests assert the routing, scoping, and governance — never a real
provider send.

# End of Document
