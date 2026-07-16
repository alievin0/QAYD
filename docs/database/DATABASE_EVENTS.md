# Database & Domain Events — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Domain Events & Transactional Outbox
---

# Purpose

QAYD is composed of independently owned modules — Accounting, Sales, Purchasing, Banking,
Inventory, Payroll, Tax, Reports, and the AI layer — each with its own tables and its own
Laravel domain. These modules must stay decoupled at the database level while remaining
tightly consistent at the business level: a posted invoice must reliably produce a journal
entry, a completed payroll run must reliably post payroll liabilities to the General Ledger,
a reconciled bank transaction must reliably close the matching AR/AP item. This document
specifies how QAYD guarantees that cross-module consistency without any module reaching into
another module's tables directly.

The mechanism is domain events, delivered with at-least-once, exactly-once-effective semantics
through a transactional outbox, relayed onto Redis-backed Laravel queues, and consumed by
per-module listeners that are idempotent by construction. This document is the single source
of truth for: the event catalog, the outbox schema and write pattern, the relay process,
PostgreSQL `LISTEN`/`NOTIFY` usage, triggers that emit events, idempotency and ordering
guarantees, consumer implementation in Laravel, event schema versioning, replay/dead-letter
handling, and the distinction between the audit trail and the event stream. Every other module
document (Accounting, Sales, Purchasing, Banking, Inventory, Payroll, Tax) defers to this
document for how it publishes and consumes events; it does not redefine the mechanism locally.

# Event-Driven Architecture Recap

QAYD modules communicate exclusively through domain events. A module that needs to react to
something that happened in another module subscribes to that module's events; it never queries
or writes another module's tables directly, and it never calls another module's service class
in-process for a state-changing operation. This rule is absolute for financial data because it
is what keeps the General Ledger the single derived truth: Accounting does not know that
"Sales" exists as a concept — it only knows that an `invoice.created` event arrived with a
payload it knows how to turn into a balanced journal entry.

Principles:

- **Producers own their events.** The Sales module is the only writer of `invoice.*` events;
  Accounting only consumes them. This prevents circular dependencies between modules.
- **Events are facts, not commands.** `invoice.paid` states that a payment was recorded; it does
  not instruct Accounting to do anything. Each consumer decides its own reaction.
- **No synchronous cross-module writes.** A `PurchaseOrderService::approve()` call never invokes
  `InventoryService` or `AccountingService` directly inside the same request. It commits its own
  transaction, which durably records an outbox row; a separate worker fans that out.
- **The database transaction is the unit of truth.** An event only "happened" if the row that
  proves it exists on disk in the same transaction as the business data it describes. This is
  why QAYD uses a transactional outbox (below) instead of publishing to Redis/SQS directly from
  application code.
- **Consumers are idempotent and order-tolerant within a stream.** A listener may see the same
  event twice or, in rare failure modes, slightly out of order versus a sibling event on a
  different aggregate; it is written to survive both.

```
 Sales Service (invoice.paid) --> [same TX] --> events_outbox row
                                        |
                                  Outbox Relay (queue worker, every ~1s)
                                        |
                                        v
                         Redis Stream / Laravel Queue "domain-events"
                                        |
                 -----------------------------------------------
                 |                     |                       |
         AccountingListener     BankingListener         NotificationsListener
         (posts journal)     (matches to receipt)      (pushes to caregiver... N/A)
```

# Domain Events Catalog

Every event name is `<aggregate>.<past_tense_verb>`, lower snake_case, singular aggregate.
Every event payload includes the envelope fields defined in "Event Versioning & Schema" below
plus a `data` object with the fields listed here. `amount` fields are strings representing
`NUMERIC(19,4)` to avoid floating-point drift across the wire.

| Event | Producer Module | Emitted When | Key Payload Fields |
|---|---|---|---|
| `invoice.created` | Sales | A sales invoice is finalized (not draft) | `invoice_id`, `customer_id`, `total_amount`, `currency_code`, `due_date` |
| `invoice.paid` | Sales / Banking | A receipt fully settles an invoice | `invoice_id`, `receipt_id`, `paid_amount`, `paid_at` |
| `invoice.voided` | Sales | An invoice is voided (reversing entry required) | `invoice_id`, `reason`, `voided_by` |
| `bill.created` | Purchasing | A vendor bill is approved | `bill_id`, `vendor_id`, `total_amount`, `currency_code` |
| `payment.received` | Banking | Funds are matched to a customer receipt | `receipt_id`, `bank_transaction_id`, `amount` |
| `payment.sent` | Banking | A vendor payment clears the bank | `vendor_payment_id`, `bank_transaction_id`, `amount` |
| `payroll.completed` | Payroll | A payroll run is approved and finalized | `payroll_run_id`, `pay_period`, `total_net`, `total_gross`, `total_deductions` |
| `inventory.updated` | Inventory | A stock movement changes on-hand quantity | `inventory_item_id`, `product_id`, `warehouse_id`, `delta_qty`, `new_qty`, `movement_type` |
| `stock.adjusted` | Inventory | A manual stock adjustment is posted | `stock_adjustment_id`, `product_id`, `qty_delta`, `reason` |
| `bank.synced` | Banking | A bank feed import completes | `bank_account_id`, `statement_lines_count`, `synced_through` |
| `bank.reconciled` | Banking | A reconciliation is closed | `bank_reconciliation_id`, `bank_account_id`, `period_end`, `closing_balance` |
| `journal.posted` | Accounting | A journal entry transitions draft → posted | `journal_entry_id`, `total_debit`, `total_credit`, `fiscal_period_id` |
| `journal.reversed` | Accounting | A posted entry is reversed | `journal_entry_id`, `reversal_entry_id`, `reason` |
| `tax.filed` | Tax | A tax return is submitted | `tax_return_id`, `period`, `tax_code`, `amount_due` |
| `credit_note.issued` | Sales | A credit note is posted against an invoice | `credit_note_id`, `invoice_id`, `amount` |
| `ai.finished` | AI Layer | An AI agent completes analysis/drafting | `conversation_id`, `agent`, `confidence`, `result_ref` |

Every producer module document (Sales, Purchasing, Banking, Inventory, Payroll, Tax) MUST list
which of these events it emits and reference this table rather than re-defining payload shapes.

# Transactional Outbox Pattern

Publishing an event and committing the business row it describes are not atomic across two
systems (Postgres + a message broker) unless one system defers to the other. QAYD resolves this
with the transactional outbox: the event row is written to a Postgres table in the *same*
transaction as the business mutation, so either both are durable or neither is. A separate relay
process then reads unpublished outbox rows and forwards them to Redis; if the relay crashes
mid-flight, at-least-once delivery is preserved because the outbox row is only marked
`dispatched_at` after a broker ack.

```sql
CREATE TABLE events_outbox (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    event_id        UUID NOT NULL DEFAULT gen_random_uuid(),
    event_name      VARCHAR(100) NOT NULL,           -- e.g. 'invoice.paid'
    aggregate_type  VARCHAR(100) NOT NULL,           -- e.g. 'invoices'
    aggregate_id    BIGINT NOT NULL,                 -- invoices.id
    event_version   SMALLINT NOT NULL DEFAULT 1,
    payload         JSONB NOT NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    dispatched_at   TIMESTAMPTZ NULL,
    dispatch_attempts SMALLINT NOT NULL DEFAULT 0,
    last_error      TEXT NULL,
    trace_id        UUID NULL                        -- correlates to request_id in API envelope
);

CREATE INDEX idx_events_outbox_undispatched
    ON events_outbox (id)
    WHERE dispatched_at IS NULL;

CREATE INDEX idx_events_outbox_company_event
    ON events_outbox (company_id, event_name, occurred_at DESC);

CREATE INDEX idx_events_outbox_aggregate
    ON events_outbox (aggregate_type, aggregate_id);
```

Writing an event happens inside the same Eloquent transaction as the business mutation:

```php
DB::transaction(function () use ($invoice, $receipt) {
    $invoice->status = 'paid';
    $invoice->save();

    $receipt->invoice_id = $invoice->id;
    $receipt->save();

    Outbox::write(
        companyId: $invoice->company_id,
        eventName: 'invoice.paid',
        aggregateType: 'invoices',
        aggregateId: $invoice->id,
        payload: [
            'invoice_id'  => $invoice->id,
            'receipt_id'  => $receipt->id,
            'paid_amount' => (string) $receipt->amount,
            'paid_at'     => now()->toIso8601String(),
        ],
    );
});
```

`Outbox::write()` is a thin insert into `events_outbox`; it does not touch Redis. A dedicated
queue worker (`php artisan events:relay`) polls `events_outbox` for `dispatched_at IS NULL` rows
in `id` order, pushes each to the `domain-events` Redis stream via `XADD`, and only then sets
`dispatched_at = now()`. On broker failure it increments `dispatch_attempts`, backs off, and
retries; after a configurable ceiling (default 10) it is left for the dead-letter path described
below rather than retried forever.

```php
class RelayOutboxEvents extends Command
{
    protected $signature = 'events:relay';

    public function handle(): void
    {
        Outbox::undispatched()->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                try {
                    Redis::xadd('domain-events', '*', [
                        'event_id'   => $row->event_id,
                        'event_name' => $row->event_name,
                        'company_id' => $row->company_id,
                        'payload'    => $row->payload,
                        'trace_id'   => $row->trace_id,
                    ]);
                    $row->update(['dispatched_at' => now()]);
                } catch (\Throwable $e) {
                    $row->increment('dispatch_attempts');
                    $row->update(['last_error' => $e->getMessage()]);
                }
            }
        });
    }
}
```

This command runs under Laravel's scheduler at a 1-second cadence via a supervised long-running
worker (not cron), giving sub-second propagation latency without ever risking a lost event.
Rows older than 30 days with `dispatched_at IS NOT NULL` are purged nightly; undispatched rows
are never purged automatically.

# PostgreSQL LISTEN/NOTIFY

`LISTEN`/`NOTIFY` is used only as a low-latency *wake-up* signal for the outbox relay worker, not
as the event transport itself. Postgres `NOTIFY` payloads are capped at 8000 bytes, are not
durable (a disconnected listener misses notifications sent while it was down), and are
best-effort delivery — none of which is acceptable for financial events. QAYD's relay worker
therefore polls `events_outbox` on a fixed interval as the source of truth, and additionally
`LISTEN`s on a channel so it can wake up and poll immediately instead of waiting for the next
tick, cutting typical propagation latency from ~1s to tens of milliseconds.

```sql
-- Trigger-side: notify with only the minimal wake-up signal, never the payload.
NOTIFY events_outbox_channel;
```

```php
// Worker side (long-running artisan command), using a raw PDO listen loop:
$pdo = DB::connection()->getPdo();
$pdo->exec("LISTEN events_outbox_channel");

while (true) {
    $pdo->query('SELECT 1'); // keep connection warm / flush notifications
    // pg driver surfaces notifications via pg_get_notify in native pgsql,
    // wrapped here through a small polling+select loop with a short timeout.
    $this->relayBatch();
    usleep(200_000); // 200ms floor between polls even if notified rapidly
}
```

Limitations to design around:
- No delivery guarantee across a connection drop — the poll loop is what actually guarantees
  delivery; `NOTIFY` is purely an optimization.
- No back-pressure — a burst of 10,000 notifications does not mean 10,000 wake-ups are needed;
  the worker coalesces by always doing a full poll-and-drain regardless of notification count.
- Payload should stay empty or tiny; never put invoice JSON in a `NOTIFY` payload.
- Not usable across separate Postgres instances/read-replicas — `LISTEN`/`NOTIFY` is
  connection-local to one primary, which is fine since the outbox itself only exists on the
  primary.

# Triggers That Emit Events

Some events must fire even if a mutation happens outside the normal Service-layer code path
(a manual `UPDATE` in a migration console, an admin backfill script, a direct SQL fix during
incident response). For financial-integrity-critical transitions, QAYD backs the outbox write
with a database trigger as a second line of defense, so the event cannot be silently skipped by
bypassing the ORM. This does not replace the Service-layer `Outbox::write()` calls — it is a
safety net that fires only for the narrow set of state transitions where a missed event would
corrupt the ledger.

```sql
CREATE OR REPLACE FUNCTION emit_journal_posted_event() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'posted' AND OLD.status IS DISTINCT FROM 'posted' THEN
        INSERT INTO events_outbox (
            company_id, event_name, aggregate_type, aggregate_id,
            event_version, payload
        ) VALUES (
            NEW.company_id,
            'journal.posted',
            'journal_entries',
            NEW.id,
            1,
            jsonb_build_object(
                'journal_entry_id', NEW.id,
                'total_debit', NEW.total_debit,
                'total_credit', NEW.total_credit,
                'fiscal_period_id', NEW.fiscal_period_id,
                'posted_at', NEW.posted_at
            )
        );
        NOTIFY events_outbox_channel;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_journal_entries_emit_posted
    AFTER UPDATE ON journal_entries
    FOR EACH ROW
    EXECUTE FUNCTION emit_journal_posted_event();
```

The trigger and the application-level `Outbox::write()` call for the same logical transition
are made mutually exclusive in practice: the Service layer only calls `Outbox::write()` for
events that have no corresponding trigger (the majority), and triggers are reserved exclusively
for `journal_entries.status -> posted`, `journal_entries.status -> reversed`, and
`invoices.status -> voided` — the three transitions where a bypassed ORM path would otherwise
leave the ledger silently inconsistent. `NOTIFY` is called inside the trigger, using the same
channel the relay worker listens on, so trigger-sourced events get the same low-latency wake-up.

Anti-pattern: do not add triggers for every table "just in case." Triggers are invisible to
anyone reading Service-layer code, are harder to test, and slow down bulk writes; QAYD limits
them strictly to the three transitions above.

# Idempotency & Exactly-Once Handling

QAYD's transport (outbox → Redis Stream → consumer group) delivers **at-least-once**. A consumer
can receive the same `event_id` twice: after a worker crash and job retry, after a consumer-group
rebalance, or after the relay's own retry on an ambiguous broker ack. Every consumer is therefore
required to be idempotent using a dedup ledger keyed on `event_id`, checked and written inside
the same transaction as the side effect.

```sql
CREATE TABLE processed_events (
    event_id        UUID NOT NULL,
    consumer_name   VARCHAR(100) NOT NULL,   -- e.g. 'accounting.invoice_paid_listener'
    processed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (event_id, consumer_name)
);
```

```php
class PostJournalForInvoicePaid implements ShouldQueue
{
    public function handle(DomainEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $inserted = DB::table('processed_events')->insertOrIgnore([
                'event_id'      => $event->id,
                'consumer_name' => 'accounting.invoice_paid_listener',
                'processed_at'  => now(),
            ]);

            if ($inserted === 0) {
                return; // already handled — safe no-op, commits nothing new
            }

            $this->journalService->postForInvoicePayment($event->payload);
        });
    }
}
```

Because the `INSERT ... ON CONFLICT DO NOTHING` (`insertOrIgnore`) and the journal-posting side
effect happen in one transaction, either both commit or neither does — a crash between them
rolls back cleanly and the retry re-enters at the same guard. The primary key on
`(event_id, consumer_name)` scopes idempotency per consumer, since the same event may be
legitimately processed once by Accounting and once by Notifications.

For side effects that are not naturally transactional against Postgres (e.g., calling an
external SMS gateway), the same table doubles as a two-phase guard: mark "processing" before the
external call, "done" after, and treat "processing" older than a timeout as a candidate for
retry rather than as evidence of success.

# Ordering & Causality

Within a single aggregate (e.g., one `invoice_id`), QAYD guarantees ordered delivery: events for
the same aggregate are written to the same Redis Stream partition keyed by
`hash(aggregate_type, aggregate_id)`, and a single consumer group member owns a given partition
at a time, so `invoice.created` is always visible to a consumer before `invoice.paid` for the
same invoice. Across different aggregates there is no ordering guarantee, and none is needed —
Accounting posting a journal for Invoice #100 has no causal relationship to Payroll posting a
journal for pay period March, so processing them concurrently and out of relative order is safe.

Where a consumer's logic requires an event to arrive after a prerequisite it depends on (e.g., a
`payment.received` listener that must resolve the receipt row, which is itself written before the
event but by a different aggregate root), the consumer checks for the prerequisite row explicitly
and re-queues with backoff if it is missing, rather than assuming arrival order:

```php
if (! $receipt = Receipt::find($event->payload['receipt_id'])) {
    // Prerequisite not yet visible (replica lag or reordering) — retry shortly.
    $this->release(now()->addSeconds(2));
    return;
}
```

`occurred_at` (business time, set when the outbox row is written) is the field consumers use for
sequencing/audit narrative; `dispatched_at` and the Redis Stream entry ID are transport-time and
must never be used to infer business order across aggregates.

# Consuming Events (Laravel listeners, queued jobs)

A bridge process reads the `domain-events` Redis Stream via a consumer group per module
(`accounting-group`, `banking-group`, `notifications-group`, …) and dispatches a Laravel event
class, which fans out to registered listeners exactly like a normal Laravel event:

```php
// app/Events/DomainEventReceived.php
class DomainEventReceived
{
    public function __construct(
        public string $id,
        public string $name,
        public int $companyId,
        public array $payload,
        public ?string $traceId,
    ) {}
}

// app/Providers/EventServiceProvider.php
protected $listen = [
    DomainEventReceived::class => [
        RouteDomainEventToModuleListeners::class,
    ],
];
```

`RouteDomainEventToModuleListeners` maps `event->name` to concrete queued listeners via a static
table, so adding a new consumer is a one-line registration, not a code change to the bridge:

```php
class RouteDomainEventToModuleListeners
{
    private const MAP = [
        'invoice.paid'       => [PostJournalForInvoicePaid::class],
        'invoice.voided'     => [PostReversingJournalForVoid::class],
        'bill.created'       => [PostJournalForBill::class],
        'payroll.completed'  => [PostJournalForPayrollRun::class],
        'inventory.updated'  => [RecalculateInventoryValuation::class],
        'bank.reconciled'    => [CloseMatchedReceiptsAndPayments::class],
    ];

    public function handle(DomainEventReceived $event): void
    {
        foreach (self::MAP[$event->name] ?? [] as $listenerClass) {
            dispatch(new $listenerClass($event))->onQueue('domain-events');
        }
    }
}
```

Each listener implements `ShouldQueue`, sets `$tries` and `backoff()` explicitly, and never
assumes the payload's referenced rows exist yet without checking (see Ordering & Causality).
Queue workers for `domain-events` run with `--tries=10 --backoff=5,15,60,300` and route
permanently failed jobs to `failed_jobs`, which is the input to the dead-letter workflow below.

# Event Versioning & Schema

Every event on the wire carries an envelope; `event_version` is bumped whenever the `data` shape
changes in a way older consumers cannot safely ignore (field removed, type changed, semantics
changed). Purely additive optional fields do not require a version bump.

```json
{
  "event_id": "b8f2a6d0-6e4e-4a1c-9e2a-7e0e1f9b2c11",
  "event_name": "invoice.paid",
  "event_version": 1,
  "company_id": 4821,
  "occurred_at": "2026-07-16T09:14:02Z",
  "trace_id": "b1a3c9d2-1111-4a2b-9c3d-0e1f2a3b4c5d",
  "data": {
    "invoice_id": 91234,
    "receipt_id": 55021,
    "paid_amount": "1250.0000",
    "paid_at": "2026-07-16T09:14:00Z"
  }
}
```

Rules:
- Consumers switch on `event_version` and keep the previous handler branch alive until every
  known consumer has deployed support for the new version (a "dual-write window").
- A breaking change ships as `event_version: 2` alongside the still-emitted `version: 1` payload
  for one full deprecation cycle (minimum 30 days), not as an in-place mutation of version 1's
  shape.
- `event_name` itself is never renamed; a genuinely new concept gets a new name, never a repoint
  of an old one, so historical `events_outbox` rows stay meaningful to future readers.
- Schemas are documented and enforced with a JSON Schema per `(event_name, event_version)` pair,
  validated in the outbox write path in non-production environments to catch payload drift before
  it reaches a consumer.

# Replay & Dead-Letter Handling

Because `events_outbox` is durable and append-only, replay is always possible directly from
Postgres — it is the permanent log, not just a staging table for the relay. To replay a range for
a specific consumer (e.g., after fixing a bug in `PostJournalForInvoicePaid`):

```sql
SELECT event_id, event_name, payload
FROM events_outbox
WHERE event_name = 'invoice.paid'
  AND occurred_at BETWEEN '2026-07-01' AND '2026-07-16'
ORDER BY id;
```

```php
Artisan::call('events:replay', [
    '--event-name' => 'invoice.paid',
    '--from'       => '2026-07-01',
    '--to'         => '2026-07-16',
    '--consumer'   => 'accounting.invoice_paid_listener',
]);
```

The replay command re-dispatches each matching row through the same
`RouteDomainEventToModuleListeners` path, relying on the `processed_events` idempotency guard so
already-correctly-processed events are skipped and only genuinely missed or newly-fixed handling
takes effect. Jobs that exhaust `--tries` land in Laravel's `failed_jobs` table, which functions
as QAYD's dead-letter queue; a scheduled alert notifies the owning module's on-call channel
whenever `failed_jobs` gains a row tagged `queue = domain-events`, and `php artisan queue:retry
{id}` re-injects it after the underlying issue (bad payload, bug, downstream outage) is fixed. A
`failed_jobs` row is never auto-deleted; it is resolved explicitly (retried or annotated as
intentionally discarded) so financial event handling is never silently lossy.

# Audit vs Events

Domain events and `audit_logs` answer different questions and are never conflated into one
table. `audit_logs` answers "who changed what, when, from what value, to what value, why, from
where" for every mutation, including ones with no cross-module consequence (a user editing a
contact's phone number). Domain events answer "what happened that another module must react to,"
and only exist for state transitions with cross-module consequence — most `audit_logs` rows have
no corresponding event, and no event exists without a corresponding audit trail entry for the
mutation that caused it.

| | `audit_logs` | `events_outbox` |
|---|---|---|
| Purpose | Compliance/forensic trail | Cross-module reaction trigger |
| Scope | Every mutation | Only cross-module-relevant transitions |
| Consumers | Auditors, compliance, support | Other QAYD modules |
| Retention | Indefinite (financial regulation) | 30 days post-dispatch, indefinite pre-dispatch |
| Contains old/new field diff | Yes | No — only the derived business fact |
| Failure mode if missed | Compliance/audit gap | Ledger/inventory/payroll inconsistency |

In practice, the Service method that writes `events_outbox` also writes (or triggers a listener
that writes) the corresponding `audit_logs` row in the same transaction, so the two are always
consistent with each other even though they serve different consumers.

# Edge Cases

- **Duplicate outbox rows from a retried request.** A client that retries a timed-out POST
  `/invoices/{id}/pay` after the server actually committed must not double-post a journal. The
  API layer requires an `Idempotency-Key` header on money-moving endpoints, stored on the
  business row itself (e.g., `receipts.idempotency_key UNIQUE`), so the second request is
  rejected with 409 before a second outbox row is ever written.
- **Outbox row written, relay crashes before dispatch, and the server restarts.** No data is
  lost — the row's `dispatched_at IS NULL` and the next relay poll picks it up. This is the
  entire reason the outbox exists instead of publishing directly from the request thread.
- **Consumer processes an event, then its own transaction rolls back for an unrelated reason
  later in the same request.** Never structure a listener so unrelated work shares its
  transaction with the idempotency-guard insert; the guard insert and its side effect must be
  the *entire* transaction, so a rollback always un-does both together, never just one.
- **Two events for the same invoice processed by two different queue workers concurrently.**
  Partition-key routing (aggregate hash) into Redis Stream avoids this in the common case; as a
  second line of defense, `journal_entries` posting takes a `SELECT ... FOR UPDATE` on the
  source `invoices` row so concurrent postings serialize rather than double-post.
- **A module is down for an extended period.** Its consumer group's unacknowledged entries stay
  in the Redis Stream (bounded by a retention policy of 7 days / `MAXLEN` cap); on recovery it
  resumes from its last acknowledged offset. If the outage exceeds the stream retention window,
  the recovery procedure falls back to the `events_outbox` replay command instead, since Postgres
  retains dispatched rows for 30 days — longer than the stream's own retention.
- **Schema drift where a consumer is upgraded before its producer.** The consumer must tolerate
  unknown/missing optional fields gracefully (default to null/skip enrichment) rather than
  throwing, since additive fields are not synchronized to deploy atomically across modules.
- **Multi-currency payload precision.** Amount fields are always transmitted as strings (not
  JSON numbers) to prevent silent float rounding in transport/consumer JSON parsers; consumers
  parse them back into `NUMERIC`/`BigDecimal`-equivalent types, never native floats.
- **Company deletion/offboarding mid-stream.** Outbox rows for a company being fully offboarded
  are still dispatched and processed normally until the company's `deleted_at` is set; consumers
  check `company_id` scoping on the referenced aggregate before acting, so a race where the
  company is deleted between event emission and consumption fails closed (no-op) rather than
  writing orphaned data.

# End of Document
