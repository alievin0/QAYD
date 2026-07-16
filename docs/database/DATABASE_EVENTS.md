# Database Events & Event-Driven Architecture — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_EVENTS
---

# Purpose

QAYD is a multi-module AI Financial Operating System built on Laravel 12 (PHP 8.4+) over PostgreSQL
15+, with a FastAPI/Python AI layer and a Next.js 15 frontend. The Accounting, Sales, Purchasing,
Banking, Inventory, Payroll, and Tax modules never write directly into one another's tables. Every
cross-module interaction — Sales notifying Accounting that an invoice exists, Banking marking an
invoice paid, Payroll releasing a salary journal entry, the AI layer proposing a categorization —
happens through **domain events**, produced and consumed asynchronously through the database-backed
event pipeline this document specifies.

This document is the single authoritative specification for how domain events are generated at the
database layer, how they are guaranteed not to be lost or duplicated in transit, and how Laravel and
the FastAPI AI layer consume them. It resolves, and is the tie-breaking source of truth for, every
event-related table name and shape referenced informally in other Database submodule documents:
`domain_events` (the transactional outbox itself, defined here with the exact column set introduced
in **Database Architecture — Event Driven Database**), `event_type_registry` (the event governance
table — named distinctly from `domain_events` precisely because a growing, partitioned, multi-hundred-
million-row event log and a static ~30-row catalog of event *definitions* are different tables with
different lifecycles, and collapsing them under one name in earlier drafts of this document was a
defect this revision corrects), `event_inbox` (consumer-side dedup ledger), `idempotency_keys` (the
API/external-boundary dedup table defined in **Database Standards**, reused verbatim here), and
`dead_letter_events` (unified dead-letter storage across outbox, inbox, and queue-job failures).

The core engineering problem this document solves is the **dual-write problem**: a service that writes
a business row (e.g. `invoices`) and then separately tries to publish an event (e.g. `invoice.created`)
to Redis or a queue can fail between the two operations, leaving the database and the event stream out
of sync — the row exists but no one downstream ever hears about it, or an event fires for a write that
later rolled back. QAYD solves this with the **Transactional Outbox Pattern**: the business write and
the event row commit in the *same* PostgreSQL transaction, so they are atomic by construction. A relay
process reads the outbox and republishes to Redis Streams, and downstream consumers apply the **Inbox
Pattern** plus idempotency keys so that at-least-once delivery collapses to effectively-once processing
of every side effect that matters — a payment is never posted twice, a payslip is never emailed twice,
a purchase order is never auto-generated twice from the same low-stock signal.

This document is scoped to the **database and event-plumbing layer only**. It does not redefine the
business rules of the Accounting, Sales, Payroll, Banking, or Inventory modules — those live in their
own module specifications, which this document's event catalog names but does not govern. What this
document does define: the canonical domain event catalog and payload schemas; the `domain_events`,
`event_type_registry`, `event_inbox`, and `dead_letter_events` tables and their exact DDL; the
PostgreSQL trigger functions and `LISTEN`/`NOTIFY` wiring that populate and announce the outbox
transactionally; the Laravel/Redis queue topology, Horizon configuration, and consumer-group listener
classes that consume events; the idempotency, retry, and dead-letter strategy; how AI-originated and
audit-originated activity rides the same bus under the same guarantees; and the monitoring signals that
keep the whole pipeline observable.

Every table in this document follows the platform's standard columns (`id`, `company_id`, `branch_id`,
`created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) wherever it represents tenant
master data. The event-infrastructure tables introduced here deliberately deviate from that template
where the deviation is load-bearing: `domain_events`, `event_inbox`, and `dead_letter_events` are
append-mostly logs, not soft-deletable business entities, so they carry `company_id` (tenant isolation
is non-negotiable — a company's events must never leak into another company's consumers, dead-letter
tooling, or webhook deliveries) but intentionally omit `deleted_at`; they are retired by partition-drop
or archival job, never by `UPDATE ... SET deleted_at`, because row-by-row soft deletion of a
high-volume append-only log defeats the point of partitioning it in the first place. Every monetary
field inside an event payload uses the same `NUMERIC(19,4)` precision as its source table and is
serialized as a decimal **string**, never a JSON number/float, to avoid floating-point drift across the
Laravel/Redis/FastAPI service boundaries — `"total_amount": "1250.5000"`, never `"total_amount": 1250.5`.

# Domain Events

A **domain event** is an immutable fact that something happened in the business domain. Domain events
are named `<entity>.<past_tense_verb>` (dot case), are versioned, and carry a normalized envelope. They
are the ONLY mechanism by which modules communicate — Sales never calls an `AccountingService::post()`
method across a module boundary; it causes `invoice.created` to be emitted, and the Accounting module's
own listener decides how, and whether, to post a journal entry, according to Accounting's own business
rules. This is the same architectural rule stated in **Database Architecture** ("QAYD's modules
communicate exclusively through domain events, never through direct cross-module database writes"); this
document is where that rule becomes concrete schema, triggers, queues, and code.

## Canonical Event Envelope

Every domain event, regardless of producer, is serialized identically wherever it is observed —
inside a Redis Stream entry, in a webhook delivery body, in the FastAPI callback payload:

```json
{
  "event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10",
  "event_name": "invoice.created",
  "event_version": 1,
  "company_id": 4821,
  "branch_id": 12,
  "aggregate_type": "invoice",
  "aggregate_id": 55231,
  "occurred_at": "2026-07-16T09:14:22.451Z",
  "produced_by": "sales-service",
  "causation_id": "018f2e6a-1120-7000-8b1e-3a2c5e6f7a01",
  "correlation_id": "018f2e6a-1120-7000-8b1e-3a2c5e6f7a00",
  "actor": { "type": "user", "user_id": 9931 },
  "payload": { "...": "event-specific fields, see catalog below" },
  "metadata": { "ip": "10.20.3.4", "source": "api", "request_id": "req_9f3a1c" }
}
```

- `event_id` — the row's `domain_events.uuid` (UUIDv4, generated at insert time). The primary
  cross-service dedup key; every consumer's inbox and every webhook delivery record key off this value.
- `causation_id` — the `event_id` of the event that directly caused this one to be produced (e.g. a
  `payment.received` event that causes `invoice.paid`); `null` for events originated directly by a
  human action rather than by another event.
- `correlation_id` — the root id tying an entire business-transaction chain together, unchanged across
  every event in the chain, used for end-to-end tracing (see **Monitoring**).
- `event_version` — the schema version of `payload`; consumers switch on this to handle upgrades without
  breaking older in-flight messages. Payload changes are additive-only within a version; a breaking
  change increments `event_version` and the old shape keeps being emitted, tagged with the old version
  number, until every registered consumer has migrated (tracked per consumer in `event_type_registry`).
- `aggregate_type` / `aggregate_id` — identifies the row that changed. Combined with `company_id`, any
  consumer can re-fetch authoritative current state via the REST API if the payload is insufficient for
  its purposes — events are an enrichment/notification mechanism, never the sole source of truth; the
  database and its REST projection always win on conflict with a stale or partially-applied event.

**Mapping the logical envelope onto the physical row.** The `domain_events` table (defined in
**Database Architecture** and reproduced in **Outbox Pattern** below) intentionally keeps a minimal,
fixed column set — `id`, `uuid`, `company_id`, `event_name`, `aggregate_type`, `aggregate_id`, `payload`,
`metadata`, `status`, `attempts`, `last_error`, `occurred_at`, `dispatched_at` — rather than one column
per envelope field, because most envelope fields beyond the aggregate identity are cross-cutting
metadata, not business payload. `event_version`, `causation_id`, `correlation_id`, `actor`,
`produced_by`, and `branch_id` are therefore all carried inside the `metadata` JSONB column; `payload`
carries only the event-specific business fields listed per event in the catalog below. The relay worker
(**Event Bus**) flattens `metadata` back onto the top level of the envelope it publishes to Redis Streams
and to webhooks, so every consumer-facing view of an event matches the JSON example above exactly, while
the table itself stays narrow, indexable, and identical in shape for every event name it ever stores.

## Domain Event Catalog

Every producer module registers its events in `event_type_registry` (DDL below) before shipping; an
`event_name` absent from the registry, or marked `is_active = false`, is rejected by the shared emission
guard (`fn_emit_domain_event()`, see **Triggers**) — this prevents silent, undocumented proliferation of
event names that no consumer or on-call engineer knows to expect. The seven events below are QAYD's
highest-volume, highest-criticality events and receive full worked treatment across this document
(DDL-level trigger, queue routing, and an end-to-end example each); the remaining cataloged events follow
the identical envelope/outbox/consumer machinery and are listed here for completeness and cross-module
consistency.

| Event Name | Producer Module | Trigger Condition | Key Payload Fields | Primary Consumers |
|---|---|---|---|---|
| **`invoice.created`** | Sales | New row in `invoices`, `status` = `draft` or `posted` | `invoice_id`, `customer_id`, `total_amount`, `currency_code`, `due_date`, `line_items[]` | Accounting (AR posting), AI Fraud Detection, Notifications |
| `invoice.sent` | Sales | `invoices.status` → `sent` | `invoice_id`, `sent_at`, `sent_to_email` | Customers (portal), Notifications |
| **`invoice.paid`** | Sales / Banking | `invoices.status` → `paid` (fully allocated via `receipts` / `receipt_allocations`) | `invoice_id`, `receipt_id`, `amount_paid`, `paid_at`, `remaining_balance` | Accounting, Customers (AR aging), AI Forecast Agent |
| `invoice.partially_paid` | Sales / Banking | `invoices.status` → `partially_paid` | `invoice_id`, `receipt_id`, `amount_paid`, `remaining_balance` | Accounting, Customers (AR aging) |
| `invoice.overdue` | Sales (scheduler) | Scheduled sweep finds `due_date < today` and `status NOT IN ('paid','void')` | `invoice_id`, `days_overdue`, `remaining_balance` | Customers (AR aging), Notifications, AI Collections |
| `invoice.voided` | Sales | `invoices.status` → `void`, reversing journal created | `invoice_id`, `reversal_journal_entry_id`, `reason` | Accounting, Audit |
| `credit_note.issued` | Sales | New row in `credit_notes`, posted | `credit_note_id`, `invoice_id`, `amount`, `reason` | Accounting, Customers |
| **`payment.received`** | Banking | New `bank_transactions` row matched to a customer or vendor | `bank_transaction_id`, `amount`, `bank_account_id`, `matched_entity_type`, `matched_entity_id` | Accounting, Sales (invoice allocation), Purchasing (bill allocation), AI Fraud Detection |
| `payment.failed` | Banking | Payment gateway callback reports failure/decline | `payment_attempt_id`, `reason_code`, `amount` | Sales, Notifications |
| `payment.refunded` | Banking | `refunds` row posted against a `receipt` | `refund_id`, `receipt_id`, `amount`, `reason` | Accounting, Customers |
| `payment.sent` | Purchasing / Banking | `vendor_payments` row posted | `vendor_payment_id`, `vendor_id`, `amount`, `bill_ids[]` | Accounting, Vendors (AP aging) |
| `bill.created` | Purchasing | New row in `bills` | `bill_id`, `vendor_id`, `total_amount`, `due_date` | Accounting (AP posting) |
| `bill.paid` | Purchasing / Banking | `bills.status` → `paid` | `bill_id`, `vendor_payment_id`, `amount_paid` | Accounting, Vendors |
| **`journal.posted`** | Accounting | `journal_entries.status` `draft` → `posted` | `journal_entry_id`, `lines[]`, `total_debit`, `total_credit`, `fiscal_period_id` | Reports, Tax, Ledger projection builder, Audit |
| `journal.reversed` | Accounting | Reversing entry created against a posted entry | `original_journal_entry_id`, `reversal_journal_entry_id` | Reports, Audit |
| `journal.voided` | Accounting | Draft journal entry voided before posting | `journal_entry_id`, `reason` | Audit |
| **`payroll.completed`** | Payroll | `payroll_runs.status` → `completed` (all `payslips` finalized) | `payroll_run_id`, `period_start`, `period_end`, `total_gross`, `total_net`, `total_deductions`, `employee_count`, `journal_entry_id` | Accounting, Banking (bulk transfer batch), Notifications |
| `payroll.approved` | Payroll | `payroll_runs.status` → `approved` | `payroll_run_id`, `approved_by`, `approved_at` | Payroll (release step), Audit |
| `payslip.issued` | Payroll | `payslips` row finalized and delivered | `payslip_id`, `employee_id`, `payroll_run_id`, `net_pay` | Notifications |
| **`inventory.updated`** | Inventory | New `stock_movements` row (receipt, issue, transfer, or adjustment) | `stock_movement_id`, `product_id`, `warehouse_id`, `quantity_delta`, `movement_type`, `unit_cost`, `resulting_on_hand` | Accounting (COGS/valuation posting), Sales (availability), AI Forecast Agent |
| `inventory.low_stock` | Inventory | `resulting_on_hand` crosses below `products.reorder_point` | `product_id`, `warehouse_id`, `on_hand`, `reorder_point`, `reorder_quantity` | Purchasing, AI Inventory Manager |
| `inventory.valuation_recalculated` | Inventory | New `inventory_valuations` row after a costing run | `product_id`, `warehouse_id`, `valuation_method`, `unit_cost`, `total_value` | Accounting, Reports |
| `stock_count.finalized` | Inventory | `stock_counts.status` → `finalized`, adjustment lines generated | `stock_count_id`, `warehouse_id`, `variance_lines[]` | Inventory, Accounting |
| **`bank.synced`** | Banking | Bank feed/import batch completes (`bank_transactions` bulk-inserted) | `bank_account_id`, `sync_batch_id`, `transactions_imported`, `transactions_matched`, `transactions_unmatched` | Banking (reconciliation), AI Fraud Detection |
| `bank.reconciled` | Banking | `bank_reconciliations.status` → `completed` | `bank_reconciliation_id`, `bank_account_id`, `statement_balance`, `book_balance`, `variance` | Accounting, Audit |
| `bank.transfer.completed` | Banking | Internal `transfers` row settles on both legs | `transfer_id`, `from_account_id`, `to_account_id`, `amount` | Accounting |
| `tax.return_filed` | Tax | `tax_returns.status` → `filed` | `tax_return_id`, `period`, `total_tax_due`, `filed_at` | Accounting, Audit, AI Compliance Agent |
| `ai.finished` | AI Layer (via Laravel) | Async agent run's callback commits its result | `ai_conversation_id`, `agent_type`, `confidence_score`, `proposed_action`, `requires_approval` | Notifications, the module the agent operates on |
| `ai.proposal.created` | AI Layer (via Laravel) | Agent proposes a draft (e.g. auto-categorized transaction, draft PO) | `proposal_id`, `target_type`, `target_id`, `confidence_score`, `reasoning` | Approval Assistant, the owning module |
| `audit.security_event` | Foundation | Security-sensitive audit action (permission change, financial void, new-device login) | `user_id`, `action`, `auditable_type`, `auditable_id`, `ip_address` | AI Fraud Detection, Notifications, on-call |
| `permission.changed` | Foundation | Row change in `roles` / `permissions` / `company_users` | `company_id`, `user_id`, `changed_permissions[]`, `changed_by` | Audit, Notifications |

## Event Governance: `event_type_registry`

Before any table's trigger or service can call `fn_emit_domain_event()` for a given `event_name`, that
name must exist in `event_type_registry` — a small, slow-changing reference table that doubles as living
documentation, a runtime guard, and the tie-breaker for which architectural layer is allowed to produce
each event (see **Triggers** for why this matters: a table's `AFTER` trigger and its owning Service class
must never both be wired to emit the same `event_name`, or the same business transition double-fires).

```sql
CREATE TABLE event_type_registry (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_name       VARCHAR(100) NOT NULL UNIQUE,
    producer_module  VARCHAR(50)  NOT NULL,
    producer_layer   VARCHAR(20)  NOT NULL
                     CHECK (producer_layer IN ('trigger', 'service', 'scheduler', 'ai_callback')),
    description      TEXT NOT NULL,
    payload_schema   JSONB NOT NULL,          -- JSON Schema, used for app-layer payload validation
    current_version  SMALLINT NOT NULL DEFAULT 1,
    is_active        BOOLEAN NOT NULL DEFAULT true,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_event_type_registry_producer ON event_type_registry (producer_module);
CREATE INDEX idx_event_type_registry_layer ON event_type_registry (producer_layer);

COMMENT ON TABLE event_type_registry IS
  'Registry of every domain event QAYD is allowed to emit, and which architectural layer (DB trigger vs.
   Laravel service vs. scheduled job vs. AI callback) is the single authorized producer for it. This
   table is distinct from domain_events: domain_events is the high-volume, partitioned event LOG
   (millions of rows, one per emitted instance); event_type_registry is a static ~30-row CATALOG of
   event DEFINITIONS. Confusing the two — or worse, using the same table name for both, as an earlier
   draft of this document did — is the single most common documentation defect in an event-sourced
   system this size, which is why this document calls it out explicitly.';
```

A Laravel migration seeds one row per cataloged event, e.g.:

```php
DB::table('event_type_registry')->insert([
    'event_name'      => 'invoice.created',
    'producer_module' => 'sales',
    'producer_layer'  => 'service',
    'description'     => 'A new invoice row was created in draft or posted state.',
    'payload_schema'  => json_encode([
        'type' => 'object',
        'required' => ['invoice_id', 'customer_id', 'total_amount', 'currency_code'],
        'properties' => [
            'invoice_id'    => ['type' => 'integer'],
            'customer_id'   => ['type' => 'integer'],
            'total_amount'  => ['type' => 'string'],
            'currency_code' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
            'due_date'      => ['type' => 'string', 'format' => 'date'],
            'line_items'    => ['type' => 'array'],
        ],
    ]),
    'current_version' => 1,
]);

DB::table('event_type_registry')->insert([
    'event_name'      => 'journal.posted',
    'producer_module' => 'accounting',
    'producer_layer'  => 'trigger',              // aggregation over journal_lines belongs at the DB
    'description'     => 'A journal entry transitioned from draft to posted and is now immutable.',
    'payload_schema'  => json_encode(['type' => 'object', 'required' => ['journal_entry_id', 'lines']]),
    'current_version' => 1,
]);
```

A CI static-analysis check (a Composer script run in the pipeline that already lints for missing
transactions, see **Outbox Pattern**) cross-references `producer_layer` against the actual codebase: if
`producer_layer = 'trigger'`, the check asserts a matching `AFTER INSERT/UPDATE` trigger exists on the
expected table and that no Service class also calls `fn_emit_domain_event()` with that `event_name`; if
`producer_layer = 'service'`, it asserts the inverse. This turns "don't double-emit an event" from a
code-review convention into a build-breaking, mechanically checked rule.
# Database Events

"Database events," as distinct from the domain events cataloged above, refers to the PostgreSQL-native
mechanisms by which a row-level change becomes visible to the rest of the system without polling.
Domain events are the *business-level* facts; database events are the *plumbing* that reliably turns a
row change into one of those facts. QAYD combines two complementary techniques rather than picking one:

1. **Synchronous trigger-to-outbox** (primary mechanism for aggregation-heavy or safety-net events —
   see `producer_layer = 'trigger'` in `event_type_registry`). An `AFTER INSERT`/`AFTER UPDATE` trigger
   on the source table calls the shared `fn_emit_domain_event()` function, which inserts a row into
   `domain_events` in the *same transaction* as the business write. This guarantees the event can never
   be lost even if the application process crashes immediately after commit, because by the time commit
   succeeds, the outbox row is already durable on disk.
2. **`LISTEN`/`NOTIFY` for low-latency wake-up** (secondary, best-effort, never the source of truth).
   Used so the relay worker (**Event Bus**) reacts within milliseconds instead of waiting out a polling
   interval. `NOTIFY` payloads are capped at 8000 bytes by PostgreSQL and are fire-and-forget — a
   listener that is momentarily disconnected simply never receives that notification — so the payload
   carries only a lightweight pointer (`domain_events.id`, `event_name`, `company_id`), never the full
   event body, and the relay always re-reads the durable outbox row rather than trusting the notify
   payload as data.

```sql
-- Generic NOTIFY trigger, attached in addition to the emission trigger below, so the relay worker
-- (a long-lived Laravel Artisan command holding a persistent LISTEN connection) wakes up immediately
-- instead of relying solely on its fallback poll interval.
CREATE OR REPLACE FUNCTION fn_notify_domain_event() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify(
        'domain_events_channel',
        json_build_object('id', NEW.id, 'event_name', NEW.event_name, 'company_id', NEW.company_id)::text
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_notify_domain_event
    AFTER INSERT ON domain_events
    FOR EACH ROW EXECUTE FUNCTION fn_notify_domain_event();
```

This is the identical `domain_events_channel` NOTIFY channel introduced in **Database Architecture**;
this document does not introduce a second, competing channel — the relay worker described in **Event
Bus** below is the concrete consumer of that channel.

QAYD deliberately does **not** rely on PostgreSQL logical replication / WAL decoding (e.g. a Debezium
connector streaming the write-ahead log) as the *primary* event source, for three reasons. First, it
requires `wal_level = logical` and a dedicated replication slot per consumer, which is materially heavier
operational surface than a managed Postgres tier needs to carry at QAYD's current scale. Second,
WAL-derived events describe row *changes*, not business *facts*: a single `UPDATE` on `invoices` might
correspond to zero domain events (a cosmetic column change), exactly one (`status` moved to `paid`), or
in principle more than one, and deciding which is business logic that belongs in the trigger/service
layer where the business rule is legible, not inferred generically from a before/after row diff. Third,
the explicit `domain_events` outbox gives a queryable, indexable, per-company emission audit trail for
free — support engineers and the AI Compliance Agent can `SELECT * FROM domain_events WHERE company_id =
? AND event_name = 'invoice.paid'` directly, which a WAL stream alone does not offer without standing up
separate consumer infrastructure first. Logical replication remains available as a *secondary*, optional
export path (streaming `domain_events` itself to a data warehouse via `wal2json` or a managed CDC
connector) but is out of scope for the primary bus this document specifies.

# Triggers

Every trigger that populates the outbox funnels through one shared guard function, so the validation
logic — event name registered and active, payload non-empty, tenant scoping present — lives in exactly
one place instead of being copy-pasted into every table's trigger function and drifting out of sync.

```sql
-- Shared guard + insert: validates the event is registered and active, builds the envelope's
-- cross-cutting metadata, and inserts the outbox row. Every table-specific trigger function in this
-- section calls this via `PERFORM fn_emit_domain_event(...)`.
CREATE OR REPLACE FUNCTION fn_emit_domain_event(
    p_event_name     VARCHAR,
    p_company_id     BIGINT,
    p_branch_id      BIGINT,
    p_aggregate_type VARCHAR,
    p_aggregate_id   BIGINT,
    p_payload        JSONB,
    p_actor_user_id  BIGINT DEFAULT NULL,
    p_causation_id   UUID DEFAULT NULL,
    p_correlation_id UUID DEFAULT NULL
) RETURNS UUID AS $$
DECLARE
    v_is_active  BOOLEAN;
    v_version    SMALLINT;
    v_uuid       UUID;
    v_metadata   JSONB;
BEGIN
    SELECT is_active, current_version INTO v_is_active, v_version
      FROM event_type_registry WHERE event_name = p_event_name;

    IF v_is_active IS NULL THEN
        RAISE EXCEPTION 'Unregistered domain event: % (add it to event_type_registry first)', p_event_name;
    END IF;
    IF v_is_active = false THEN
        RAISE EXCEPTION 'Domain event % is deactivated and cannot be emitted', p_event_name;
    END IF;

    v_metadata := jsonb_build_object(
        'event_version', v_version,
        'branch_id', p_branch_id,
        'actor', jsonb_build_object('type', CASE WHEN p_actor_user_id IS NULL THEN 'system' ELSE 'user' END,
                                     'user_id', p_actor_user_id),
        'causation_id', p_causation_id,
        'produced_by', 'db-trigger'
    );

    INSERT INTO domain_events (
        company_id, event_name, aggregate_type, aggregate_id, payload, metadata, status, occurred_at
    ) VALUES (
        p_company_id, p_event_name, p_aggregate_type, p_aggregate_id, p_payload,
        v_metadata || jsonb_build_object('correlation_id', COALESCE(p_correlation_id, gen_random_uuid())),
        'pending', now()
    )
    RETURNING uuid INTO v_uuid;

    RETURN v_uuid;
END;
$$ LANGUAGE plpgsql;
```

All trigger functions in this document are `SECURITY INVOKER` (PostgreSQL's default) — they execute with
the privileges of the connecting role (the Laravel application's Postgres user), never with elevated
rights, so Row-Level Security on `domain_events` (see **Outbox Pattern**) applies to the `INSERT` they
perform exactly as if the application had issued it directly. Trigger names across this document are
prefixed with a two-digit order tag (`00_`, `10_`, `20_`…) because PostgreSQL fires same-timing triggers
on a table in alphabetical order by trigger name, not declaration order — `00_set_updated_at` always runs
before `10_audit_log`, which always runs before `20_emit_domain_event`, so the audit row and the outbox
row both see the fully-updated `NEW` record.

## `invoice.created` / `invoice.paid` trigger

```sql
CREATE OR REPLACE FUNCTION trg_fn_invoices_events() RETURNS trigger AS $$
DECLARE
    v_payload JSONB;
BEGIN
    IF TG_OP = 'INSERT' THEN
        v_payload := jsonb_build_object(
            'invoice_id', NEW.id, 'customer_id', NEW.customer_id,
            'total_amount', NEW.total_amount::text, 'currency_code', NEW.currency_code,
            'due_date', NEW.due_date, 'status', NEW.status,
            'line_items', (SELECT jsonb_agg(jsonb_build_object('product_id', ii.product_id,
                              'quantity', ii.quantity::text, 'unit_price', ii.unit_price::text))
                           FROM invoice_items ii WHERE ii.invoice_id = NEW.id)
        );
        PERFORM fn_emit_domain_event('invoice.created', NEW.company_id, NEW.branch_id,
            'invoice', NEW.id, v_payload, NEW.created_by);

    ELSIF TG_OP = 'UPDATE' AND OLD.status IS DISTINCT FROM NEW.status THEN
        IF NEW.status = 'paid' THEN
            PERFORM fn_emit_domain_event('invoice.paid', NEW.company_id, NEW.branch_id,
                'invoice', NEW.id,
                jsonb_build_object('invoice_id', NEW.id, 'amount_paid', NEW.amount_paid::text,
                    'paid_at', NEW.paid_at, 'remaining_balance', (NEW.total_amount - NEW.amount_paid)::text),
                NEW.updated_by);

        ELSIF NEW.status = 'partially_paid' THEN
            PERFORM fn_emit_domain_event('invoice.partially_paid', NEW.company_id, NEW.branch_id,
                'invoice', NEW.id,
                jsonb_build_object('invoice_id', NEW.id, 'amount_paid', NEW.amount_paid::text,
                    'remaining_balance', (NEW.total_amount - NEW.amount_paid)::text),
                NEW.updated_by);

        ELSIF NEW.status = 'void' THEN
            PERFORM fn_emit_domain_event('invoice.voided', NEW.company_id, NEW.branch_id,
                'invoice', NEW.id,
                jsonb_build_object('invoice_id', NEW.id,
                    'reversal_journal_entry_id', NEW.reversal_journal_entry_id, 'reason', NEW.void_reason),
                NEW.updated_by);
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER "20_emit_domain_event"
    AFTER INSERT OR UPDATE ON invoices
    FOR EACH ROW EXECUTE FUNCTION trg_fn_invoices_events();
```

## `journal.posted` trigger (enforces immutability and computes totals in the same statement)

```sql
CREATE OR REPLACE FUNCTION trg_fn_journal_entries_events() RETURNS trigger AS $$
DECLARE
    v_lines JSONB;
    v_debit_total NUMERIC(19,4);
    v_credit_total NUMERIC(19,4);
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.status = 'posted' AND NEW.status NOT IN ('posted', 'reversed') THEN
        RAISE EXCEPTION 'Posted journal entries are immutable (entry %). Create a reversing entry instead.', OLD.id;
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.status = 'draft' AND NEW.status = 'posted' THEN
        SELECT jsonb_agg(jsonb_build_object('account_id', jl.account_id, 'debit', jl.debit_amount::text,
                            'credit', jl.credit_amount::text, 'cost_center_id', jl.cost_center_id,
                            'project_id', jl.project_id)),
               SUM(jl.debit_amount), SUM(jl.credit_amount)
          INTO v_lines, v_debit_total, v_credit_total
          FROM journal_lines jl WHERE jl.journal_entry_id = NEW.id;

        IF v_debit_total IS DISTINCT FROM v_credit_total THEN
            RAISE EXCEPTION 'Cannot post unbalanced journal entry % (debit % <> credit %)',
                NEW.id, v_debit_total, v_credit_total;
        END IF;

        PERFORM fn_emit_domain_event('journal.posted', NEW.company_id, NEW.branch_id,
            'journal_entry', NEW.id,
            jsonb_build_object('journal_entry_id', NEW.id, 'fiscal_period_id', NEW.fiscal_period_id,
                'total_debit', v_debit_total::text, 'total_credit', v_credit_total::text, 'lines', v_lines),
            NEW.updated_by);

    ELSIF TG_OP = 'UPDATE' AND NEW.status = 'reversed' THEN
        PERFORM fn_emit_domain_event('journal.reversed', NEW.company_id, NEW.branch_id,
            'journal_entry', NEW.id,
            jsonb_build_object('original_journal_entry_id', OLD.id,
                'reversal_journal_entry_id', NEW.reversal_journal_entry_id),
            NEW.updated_by);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER "20_emit_domain_event"
    AFTER UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION trg_fn_journal_entries_events();
```

This trigger doubles as a **constraint enforcement point**: the balance check (`v_debit_total IS
DISTINCT FROM v_credit_total`) and the immutability check both `RAISE EXCEPTION`, which aborts the
transaction and, by extension, prevents an invalid `domain_events` row from ever being written — an
event for an unbalanced or edited-after-posting journal entry is architecturally impossible, not merely
discouraged by application validation (see **Database Constraints** for the equivalent `CHECK`-level
treatment of this same invariant).

## `inventory.updated` / `inventory.low_stock` trigger

```sql
CREATE OR REPLACE FUNCTION trg_fn_stock_movements_events() RETURNS trigger AS $$
DECLARE
    v_reorder_point NUMERIC(18,4);
    v_reorder_qty   NUMERIC(18,4);
BEGIN
    PERFORM fn_emit_domain_event('inventory.updated', NEW.company_id, NEW.branch_id,
        'stock_movement', NEW.id,
        jsonb_build_object('product_id', NEW.product_id, 'warehouse_id', NEW.warehouse_id,
            'quantity_delta', NEW.quantity_delta::text, 'movement_type', NEW.movement_type,
            'unit_cost', NEW.unit_cost::text, 'resulting_on_hand', NEW.resulting_on_hand::text),
        NEW.created_by);

    SELECT reorder_point, reorder_quantity INTO v_reorder_point, v_reorder_qty
      FROM products WHERE id = NEW.product_id;

    IF v_reorder_point IS NOT NULL AND NEW.resulting_on_hand <= v_reorder_point
       AND (TG_OP = 'INSERT' OR OLD.resulting_on_hand > v_reorder_point) THEN
        -- Fires only on the crossing, not on every subsequent movement while already below threshold,
        -- so Purchasing and the AI Inventory Manager see exactly one signal per stock-out episode.
        PERFORM fn_emit_domain_event('inventory.low_stock', NEW.company_id, NEW.branch_id,
            'product', NEW.product_id,
            jsonb_build_object('product_id', NEW.product_id, 'warehouse_id', NEW.warehouse_id,
                'on_hand', NEW.resulting_on_hand::text, 'reorder_point', v_reorder_point::text,
                'reorder_quantity', v_reorder_qty::text),
            NEW.created_by);
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER "20_emit_domain_event"
    AFTER INSERT ON stock_movements
    FOR EACH ROW EXECUTE FUNCTION trg_fn_stock_movements_events();
```

The threshold-crossing guard (`OLD.resulting_on_hand > v_reorder_point` on the previous row) is
deliberate: `stock_movements` is one of the highest-frequency tables in the schema (every pick, receipt,
and adjustment inserts a row), and without the crossing check a product sitting below its reorder point
for a week of continued small issues would emit `inventory.low_stock` on every single movement, flooding
Purchasing and the AI Inventory Manager with duplicate signals for the same stock-out episode.

## `payroll.completed` trigger

```sql
CREATE OR REPLACE FUNCTION trg_fn_payroll_runs_events() RETURNS trigger AS $$
DECLARE
    v_totals RECORD;
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.status = 'approved' AND NEW.status = 'completed' THEN
        SELECT SUM(gross_pay) AS total_gross, SUM(net_pay) AS total_net,
               SUM(gross_pay - net_pay) AS total_deductions, COUNT(*) AS employee_count
          INTO v_totals
          FROM payroll_items WHERE payroll_run_id = NEW.id;

        PERFORM fn_emit_domain_event('payroll.completed', NEW.company_id, NEW.branch_id,
            'payroll_run', NEW.id,
            jsonb_build_object('payroll_run_id', NEW.id, 'period_start', NEW.period_start,
                'period_end', NEW.period_end, 'total_gross', v_totals.total_gross::text,
                'total_net', v_totals.total_net::text, 'total_deductions', v_totals.total_deductions::text,
                'employee_count', v_totals.employee_count, 'journal_entry_id', NEW.journal_entry_id),
            NEW.updated_by);

    ELSIF TG_OP = 'UPDATE' AND OLD.status = 'draft' AND NEW.status = 'approved' THEN
        PERFORM fn_emit_domain_event('payroll.approved', NEW.company_id, NEW.branch_id,
            'payroll_run', NEW.id,
            jsonb_build_object('payroll_run_id', NEW.id, 'approved_by', NEW.updated_by, 'approved_at', now()),
            NEW.updated_by);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER "20_emit_domain_event"
    AFTER UPDATE ON payroll_runs
    FOR EACH ROW EXECUTE FUNCTION trg_fn_payroll_runs_events();
```

## `bank.synced` trigger — statement-level, using a transition table

`bank_transactions` rows arrive in bulk (a single feed sync can insert hundreds of rows in one
statement), so a row-level `AFTER INSERT` trigger would emit one `bank.synced` event per row — an event
storm for what is, from the business's perspective, a single occurrence. PostgreSQL 10+ transition
tables (`REFERENCING NEW TABLE AS`) let a **statement-level** trigger see every row the statement just
inserted at once, collapsing the whole batch into exactly one event:

```sql
CREATE OR REPLACE FUNCTION trg_fn_bank_transactions_batch_events() RETURNS trigger AS $$
DECLARE
    v_company_id BIGINT;
    v_branch_id  BIGINT;
    v_account_id BIGINT;
    v_total      INTEGER;
    v_matched    INTEGER;
BEGIN
    SELECT company_id, branch_id, bank_account_id, COUNT(*),
           COUNT(*) FILTER (WHERE matched_entity_id IS NOT NULL)
      INTO v_company_id, v_branch_id, v_account_id, v_total, v_matched
      FROM new_rows
      GROUP BY company_id, branch_id, bank_account_id
      LIMIT 1;                       -- one sync batch always targets exactly one bank_account_id

    IF v_total IS NULL OR v_total = 0 THEN
        RETURN NULL;
    END IF;

    PERFORM fn_emit_domain_event('bank.synced', v_company_id, v_branch_id,
        'bank_account', v_account_id,
        jsonb_build_object('bank_account_id', v_account_id, 'sync_batch_id', gen_random_uuid(),
            'transactions_imported', v_total, 'transactions_matched', v_matched,
            'transactions_unmatched', v_total - v_matched),
        NULL);   -- system-initiated (scheduled sync job), not a human actor

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER "20_emit_domain_event"
    AFTER INSERT ON bank_transactions
    REFERENCING NEW TABLE AS new_rows
    FOR EACH STATEMENT EXECUTE FUNCTION trg_fn_bank_transactions_batch_events();
```

`FOR EACH STATEMENT` (rather than `FOR EACH ROW`) is what makes the transition table available and is
the mechanism that guarantees one event per import statement regardless of batch size; the sync job
itself is written to always insert an entire batch in a single `INSERT ... SELECT` or multi-row `INSERT`
so that "one statement" and "one sync batch" coincide.

## `payment.received` trigger

```sql
CREATE OR REPLACE FUNCTION trg_fn_bank_transactions_payment_events() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.matched_entity_id IS NULL AND NEW.matched_entity_id IS NOT NULL
       AND NEW.direction = 'credit' THEN
        PERFORM fn_emit_domain_event('payment.received', NEW.company_id, NEW.branch_id,
            'bank_transaction', NEW.id,
            jsonb_build_object('bank_transaction_id', NEW.id, 'amount', NEW.amount::text,
                'bank_account_id', NEW.bank_account_id, 'matched_entity_type', NEW.matched_entity_type,
                'matched_entity_id', NEW.matched_entity_id),
            NEW.updated_by);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER "21_emit_payment_received"
    AFTER UPDATE ON bank_transactions
    FOR EACH ROW EXECUTE FUNCTION trg_fn_bank_transactions_payment_events();
```

This is a second, independently-numbered row-level trigger on `bank_transactions` alongside the
statement-level batch-sync trigger above — PostgreSQL supports multiple triggers per table and per
timing, and separating "a batch finished importing" (`bank.synced`, statement-level, fires once per
import) from "this specific transaction got matched to a customer" (`payment.received`, row-level, fires
per matched row, typically from the reconciliation engine's `UPDATE` rather than from the import itself)
keeps each trigger function small, single-purpose, and independently testable, per the performance note
below.

**Performance note.** Trigger functions in this document are intentionally thin: they compute only what
is needed to populate the payload (a `SUM`/`COUNT` over a handful of already-indexed child rows, never a
full-table scan) and delegate everything else — sending notifications, calling the AI layer, recomputing
downstream projections like `ledger_entries` — to asynchronous consumers on the event bus. A trigger that
did that heavier work synchronously would extend the duration of the business write's own transaction and
its lock hold time, which on a hot table like `stock_movements` or `bank_transactions` would directly
throttle transaction throughput; the outbox row is the trigger's only durable side effect, by design.
# Queues

QAYD uses Redis for two related but distinct roles in the event pipeline: **Laravel's queue driver**
(Horizon-supervised worker processes executing PHP jobs — the consumer-side execution mechanism) and
**Redis Streams as the event bus transport** (the durable, replayable log that carries relayed outbox
rows to every subscribing module). **Database Caching** already establishes that QAYD splits Redis usage
across separate logical databases so that an incident in one concern cannot evict or starve another
(`REDIS_CACHE_DB=1`, `REDIS_SESSION_DB=2`, `REDIS_QUEUE_DB=3`, `REDIS_BROADCAST_DB=4`); this document
extends that same table with the one addition the event pipeline needs:

| Redis connection name | Purpose | Laravel config key | Persistence |
|---|---|---|---|
| `events` | Event bus transport: the `domain-events` Redis Stream and its per-module consumer groups | `REDIS_EVENTS_DB=5` | AOF `appendfsync everysec` (a stream entry must survive a Redis restart until every consumer group has `XACK`'d it) |

It is kept separate from `REDIS_QUEUE_DB=3` deliberately: the Stream is a durable log that many
independent consumer groups read at their own pace and that is trimmed only after every group has
acknowledged an entry, whereas Horizon's queue lists are transient work items removed the moment a job
completes — mixing the two on one logical database would mean a slow consumer group holding the Stream's
memory footprint high could pressure eviction of unrelated, unprocessed Horizon jobs.

## Queue topology

Laravel/Horizon queues are the *execution* layer: a thin per-module adapter (**Event Bus**, below) reads
the Redis Stream and re-dispatches each entry as an ordinary Laravel job onto the queue that module owns,
so that everywhere else in the codebase a "domain event" looks exactly like any other queued job/listener.

| Queue Name | Purpose | Concurrency | Typical Job Runtime |
|---|---|---|---|
| `events-relay` | Singleton leader-elected worker: reads `domain_events`, publishes to the `domain-events` Stream | 1 | continuous |
| `events-accounting` | Consumers owned by Accounting (journal posting listeners, ledger projection) | 10 | 50-300ms |
| `events-sales` | Consumers owned by Sales (invoice allocation, AR aging refresh) | 10 | 50-300ms |
| `events-purchasing` | Consumers owned by Purchasing (bill matching, PO auto-draft) | 6 | 50-300ms |
| `events-inventory` | Consumers owned by Inventory (valuation recompute, low-stock routing) | 8 | 100-500ms |
| `events-payroll` | Consumers owned by Payroll (bank batch-file generation, payslip email) | 4 | 200ms-2s |
| `events-banking` | Consumers owned by Banking (reconciliation matching) | 6 | 100ms-1s |
| `events-notifications` | Fan-out to email/SMS/push/Reverb broadcast | 20 | <100ms |
| `events-ai` | Dispatch to the FastAPI AI layer and handle `ai.finished`/`ai.proposal.created` callbacks | 6 | 1-30s (network-bound) |
| `events-audit` | Persist every event into `audit_logs` (append-only, must never silently fail) | 4 | <50ms |
| `events-webhooks` | Outbound webhook delivery to tenant-configured endpoints (see **Event Bus**) | 15 | 200ms-10s (network-bound, external) |
| `events-dlq-sweep` | Periodic sweep of `dead_letter_events` for alerting/retry-eligibility | 1 | scheduled every 5 min |

```php
// config/horizon.php (excerpt) — supervisors bound to the queues above
'environments' => [
    'production' => [
        'supervisor-events-relay' => [
            'connection'  => 'redis',
            'queue'       => ['events-relay'],
            'balance'     => 'simple',
            'maxProcesses'=> 1,        // singleton: domain_events dispatch order must be preserved
            'tries'       => 1,        // the relay is idempotent by row id; see Retries
        ],
        'supervisor-domain-consumers' => [
            'connection'   => 'redis',
            'queue'        => ['events-accounting', 'events-sales', 'events-purchasing',
                                'events-inventory', 'events-payroll', 'events-banking'],
            'balance'      => 'auto',
            'minProcesses' => 3,
            'maxProcesses' => 40,
            'tries'        => 5,
            'backoff'      => [5, 15, 60, 300, 900],   // seconds; reused verbatim in Retries
        ],
        'supervisor-notifications-ai-webhooks' => [
            'connection'   => 'redis',
            'queue'        => ['events-notifications', 'events-ai', 'events-webhooks'],
            'balance'      => 'auto',
            'maxProcesses' => 25,
            'tries'        => 3,
        ],
        'supervisor-audit' => [
            'connection'   => 'redis',
            'queue'        => ['events-audit'],
            'balance'      => 'simple',
            'maxProcesses' => 4,
            'tries'        => 10,      // audit writes must not be dropped; retry hard before DLQ
            'backoff'      => [2, 5, 10, 30, 60, 120, 300, 600, 900, 1800],
        ],
        'supervisor-dlq-sweep' => [
            'connection'   => 'redis',
            'queue'        => ['events-dlq-sweep'],
            'balance'      => 'simple',
            'maxProcesses' => 1,
            'tries'        => 3,
        ],
    ],
],
```

Redis key namespacing (within `REDIS_EVENTS_DB=5` unless noted) keeps the stream, consumer-group
cursors, idempotency locks, and leader-election locks from colliding:

```
qayd:{env}:stream:domain-events                          -- the Redis Stream itself (Event Bus)
qayd:{env}:stream:domain-events:group:{module}            -- consumer group name per subscribing module
qayd:{env}:idem:{event_id}:{listener}                      -- short-TTL in-process dedup guard (Idempotency)
qayd:{env}:relay:cursor                                    -- last domain_events.id successfully relayed
qayd:{env}:lock:relay-leader                               -- leader-election lock for the singleton relay
qayd:{env}:queue:events-accounting                         -- Laravel queue list (REDIS_QUEUE_DB=3, Horizon-managed)
```

Per-company rate limiting on `events-ai` and `events-webhooks` prevents one tenant's AI backlog or a slow
webhook endpoint from starving other tenants sharing the same worker pool:

```php
RateLimiter::for('ai-events', function (JobPayload $job) {
    return Limit::perMinute(60)->by('company:' . $job->companyId);
});

RateLimiter::for('webhook-deliveries', function (JobPayload $job) {
    return Limit::perMinute(120)->by('endpoint:' . $job->webhookEndpointId);
});
```

# Event Bus

The event bus is the transport carrying relayed outbox rows to every interested consumer, with
**at-least-once delivery** and **per-aggregate ordering**. QAYD implements it as a single Redis Stream
(`domain-events`) with one **consumer group per subscribing module**, giving Kafka-like semantics —
durable log, multiple independent consumer groups, replay-from-offset, per-entry acknowledgment — without
operating a JVM cluster or a separate broker product.

## Why one stream, not one stream per event type

A single stream preserves the *emission order* of events for a given aggregate, which matters:
`invoice.created` must be visible to a consumer before `invoice.paid` for that same invoice. Ordering
across *different* aggregates is not guaranteed and is not required by any consumer. Because the relay
reads `domain_events` in strict `id` order (a monotonically increasing `BIGINT IDENTITY`, not a UUID —
see **Outbox Pattern** for why the primary key is a bigint rather than the time-ordered UUID some
event-sourced designs default to) and publishes in that same order with a single-writer relay, per-
aggregate ordering falls out naturally without any explicit partitioning scheme. Consumers that need to
defend against the rare reordering window (a consumer group briefly processing entries out of commit
order during a rebalance) compare `causation_id`/`occurred_at` and buffer out-of-order arrivals for at
most two seconds before applying them in logical order.

```
Producer (Postgres trigger or Laravel service) --> domain_events  (durable, transactional, status=pending)
        |
        v
Relay worker (Laravel command, singleton leader) --> XADD domain-events * event_id ... payload ...
        |
        v
Redis Stream "domain-events"  (append-only log, capped at 500k entries / 7-day TTL via XTRIM)
        |
        +---> Consumer Group "accounting"        --> events-accounting queue jobs
        +---> Consumer Group "sales"              --> events-sales queue jobs
        +---> Consumer Group "purchasing"         --> events-purchasing queue jobs
        +---> Consumer Group "inventory"          --> events-inventory queue jobs
        +---> Consumer Group "payroll"            --> events-payroll queue jobs
        +---> Consumer Group "banking"            --> events-banking queue jobs
        +---> Consumer Group "notifications"      --> events-notifications queue jobs
        +---> Consumer Group "ai"                 --> events-ai queue jobs
        +---> Consumer Group "webhooks"           --> events-webhooks queue jobs
        +---> Consumer Group "audit"              --> events-audit queue jobs   (subscribes to everything)
```

## Relay worker

```php
// app/Console/Commands/RelayDomainEvents.php
final class RelayDomainEvents extends Command
{
    protected $signature = 'events:relay {--batch=200}';

    public function handle(): int
    {
        if (! Cache::lock('relay-leader', 30)->get()) {
            $this->warn('Another relay instance holds the leader lock. Exiting.');
            return self::SUCCESS;
        }

        while (! $this->shouldStop()) {
            $batch = DB::table('domain_events')
                ->where('status', 'pending')
                ->orderBy('id')                          // BIGINT identity => strictly insertion-ordered
                ->limit((int) $this->option('batch'))
                ->lockForUpdate()->skipLocks()
                ->get();

            if ($batch->isEmpty()) {
                usleep(200_000);                          // 200ms idle poll; NOTIFY short-circuits this
                continue;
            }

            DB::table('domain_events')->whereIn('id', $batch->pluck('id'))
                ->update(['status' => 'processing']);

            foreach ($batch as $row) {
                try {
                    Redis::connection('events')->xadd('domain-events', '*', [
                        'event_id'    => $row->uuid,
                        'event_name'  => $row->event_name,
                        'company_id'  => $row->company_id,
                        'occurred_at' => $row->occurred_at,
                        'payload'     => $row->payload,     // already JSON text from Postgres jsonb
                        'metadata'    => $row->metadata,
                    ]);
                    DB::table('domain_events')->where('id', $row->id)
                        ->update(['status' => 'dispatched', 'dispatched_at' => now()]);
                } catch (\Throwable $e) {
                    DB::table('domain_events')->where('id', $row->id)
                        ->update(['status' => 'failed', 'attempts' => DB::raw('attempts + 1'),
                                   'last_error' => $e->getMessage()]);
                }
            }
        }
        return self::SUCCESS;
    }
}
```

Rows are moved to `processing` before the `XADD` and to `dispatched` after it succeeds so that a relay
crash mid-batch leaves affected rows visibly stuck in `processing` rather than silently `pending` (a
scheduled sweep, shown in **Dead Letter Queue**, requeues any row still `processing` after 60 seconds —
longer than any single `XADD` round-trip should ever take — back to `pending`). The relay additionally
`LISTEN`s on `domain_events_channel` (**Database Events**) so that under normal load it reacts within
milliseconds instead of waiting out the 200ms poll; the poll remains the correctness backstop because
PostgreSQL does not queue `NOTIFY` payloads for a listener that is momentarily busy or briefly
disconnected.

## Consumer-group adapter

Consumer registration uses Redis Streams consumer groups directly rather than a Laravel-queue-only
design, because a consumer group gives each module an independent, replayable read cursor: if the
`inventory` consumer group falls behind or crashes for an hour, it resumes from exactly where it left
off (`XREADGROUP ... '>'`) without affecting `accounting` or `notifications`. A thin adapter re-dispatches
each Stream entry into the correct Laravel queue, so the rest of the codebase experiences ordinary
Laravel jobs and listeners — the Stream/consumer-group plumbing stays isolated in this one class:

```php
final class ConsumeDomainEventStream extends Command
{
    protected $signature = 'events:consume {module}';

    private const QUEUE_MAP = [
        'accounting' => 'events-accounting', 'sales' => 'events-sales',
        'purchasing' => 'events-purchasing', 'inventory' => 'events-inventory',
        'payroll' => 'events-payroll', 'banking' => 'events-banking',
        'notifications' => 'events-notifications', 'ai' => 'events-ai',
        'webhooks' => 'events-webhooks', 'audit' => 'events-audit',
    ];

    public function handle(): int
    {
        $module = $this->argument('module');
        $group  = "cg-{$module}";
        $redis  = Redis::connection('events');
        $redis->xgroup('CREATE', 'domain-events', $group, '0', true);   // idempotent create

        while (! $this->shouldStop()) {
            $entries = $redis->xreadgroup($group, gethostname(), ['domain-events' => '>'], 50, 5000);
            foreach ($entries['domain-events'] ?? [] as $id => $fields) {
                DispatchRelayedEvent::dispatch($fields)->onQueue(self::QUEUE_MAP[$module]);
                $redis->xack('domain-events', $group, $id);
            }
            // Claim entries other consumers in this group left pending too long (worker crash mid-read).
            $pending = $redis->xautoclaim('domain-events', $group, gethostname(), 30000, '0-0', 20);
            foreach ($pending[1] ?? [] as $id => $fields) {
                DispatchRelayedEvent::dispatch($fields)->onQueue(self::QUEUE_MAP[$module]);
                $redis->xack('domain-events', $group, $id);
            }
        }
        return self::SUCCESS;
    }
}
```

`XACK` happens only after the entry has been successfully handed to the Laravel queue (not after the
Laravel job itself finishes) — the Redis Stream's job is to guarantee the entry reached the *durable
Horizon queue* at least once; from there, Horizon's own retry/backoff/`failed_jobs` machinery (**Retries**,
**Dead Letter Queue**) takes over responsibility for the job actually completing. `XAUTOCLAIM` is the
Stream-level defense-in-depth partner to Horizon's job retries: it reclaims Stream entries whose original
reader died before it could even enqueue the Horizon job, which Horizon's own retry logic cannot see
because, in that failure mode, the Horizon job was never created in the first place.
# Outbox Pattern

The **transactional outbox** is the mechanism that makes event emission atomic with the business write
that caused it, solving the dual-write problem introduced in **Purpose**: without it, a service (or
trigger) that writes a business row and then separately publishes to Redis has an unavoidable window,
however small, in which the two can disagree after a crash, a deploy, or a network blip between the two
non-transactional operations. QAYD closes that window by making the second "operation" just another row
in the same PostgreSQL transaction.

## `domain_events` DDL

This is the authoritative, single definition of the outbox table — the same table introduced in
**Database Architecture — Event Driven Database**, reproduced here in full because this document owns
its detailed operational treatment (partitioning, RLS, retention, relay/consumer contract):

```sql
CREATE TABLE domain_events (
    id              BIGINT GENERATED ALWAYS AS IDENTITY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    event_name      VARCHAR(150) NOT NULL REFERENCES event_type_registry(event_name) ON UPDATE CASCADE,
    aggregate_type  VARCHAR(100) NOT NULL,               -- e.g. 'invoice', 'journal_entry'
    aggregate_id    BIGINT NOT NULL,                     -- internal id of the source row
    payload         JSONB NOT NULL,
    metadata        JSONB NOT NULL DEFAULT '{}',         -- event_version, actor, causation_id, correlation_id...
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'processing', 'dispatched', 'failed')),
    attempts        SMALLINT NOT NULL DEFAULT 0,
    last_error      TEXT NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    dispatched_at   TIMESTAMPTZ NULL,
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

CREATE INDEX domain_events_status_idx ON domain_events (status) WHERE status IN ('pending', 'processing');
CREATE INDEX domain_events_company_event_idx ON domain_events (company_id, event_name, occurred_at DESC);
CREATE INDEX domain_events_aggregate_idx ON domain_events (aggregate_type, aggregate_id);
CREATE UNIQUE INDEX domain_events_uuid_idx ON domain_events (uuid);

ALTER TABLE domain_events ENABLE ROW LEVEL SECURITY;
CREATE POLICY domain_events_tenant_select ON domain_events
    FOR SELECT USING (company_id = current_setting('app.company_id')::bigint);
CREATE POLICY domain_events_tenant_insert ON domain_events
    FOR INSERT WITH CHECK (company_id = current_setting('app.company_id')::bigint);
-- No UPDATE/DELETE policy is granted to the application role at all: only the relay worker's dedicated
-- 'events_relay' role (SET ROLE'd, never the request-serving app role) may transition status/attempts/
-- dispatched_at, and only the partition-archival job may ever remove rows — see Retention below.
```

The primary key is a bigint identity, not a UUID, for a deliberate reason: the relay worker's hottest
query is `ORDER BY id ... FOR UPDATE SKIP LOCKED` against the `pending`-partial index, and a monotonically
increasing bigint gives a smaller, denser B-tree and a query planner that trivially proves "ascending id
order" without needing to reason about UUIDv7's time-embedded-but-not-strictly-monotonic ordering under
concurrent inserts. `uuid` is the column every external-facing surface (webhooks, the FastAPI callback
contract, the Redis Stream's `event_id` field, `event_inbox.event_id`) actually references — it is what
`event_id` means in the **Canonical Event Envelope** — precisely so that no consumer outside this table's
own relay logic needs to know or depend on the internal bigint sequence.

Table name reconciliation, stated once more explicitly because it is the single most likely point of
confusion when reading this document set: **`domain_events` is the outbox** (this table). It is **not**
the same as `event_type_registry` (the ~30-row catalog of event *definitions*). It is **not** the same as
the generic `outbox_events` example table shown in **Database Standards**' discussion of the outbox
*pattern* in the abstract — that section illustrates the general technique with placeholder column names
before this document's concrete schema existed; `domain_events` is QAYD's one and only concrete
implementation of that pattern, and no second, separately-named outbox table exists anywhere in the
schema.

## Two authorized producer paths, never both for the same event

`fn_emit_domain_event()` (**Triggers**) is one of exactly two code paths permitted to insert into
`domain_events`; the other is a direct Eloquent write from a Laravel Service class, used where the
service already holds every field the payload needs in memory right after its own write and where an
`AFTER` trigger's inability to see request-scoped context (the authenticated actor, the inbound
`X-Request-Id`) would otherwise force an awkward round-trip:

```php
DB::transaction(function () use ($invoiceData) {
    $invoice = Invoice::create($invoiceData);        // does NOT fire a trigger-based emission for
                                                        // 'invoice.sent' — only 'invoice.created' is
                                                        // trigger-owned (see event_type_registry seed)
    DomainEvent::create([
        'company_id'     => $invoice->company_id,
        'event_name'     => 'invoice.sent',
        'aggregate_type' => 'invoice',
        'aggregate_id'   => $invoice->id,
        'payload'        => $invoice->toEventPayload(),
        'metadata'       => [
            'event_version' => 1,
            'actor'         => ['type' => 'user', 'user_id' => auth()->id()],
            'correlation_id'=> (string) Str::orderedUuid(),
            'produced_by'   => 'sales-service',
        ],
    ]);
});
```

Which layer owns which event is not a matter of developer preference: `event_type_registry.producer_layer`
is the single source of truth, enforced by the CI check described in **Domain Events**. As a rule of
thumb codified in that check: events whose payload requires aggregating child rows that only the database
can cheaply and correctly total at commit time (`journal.posted`'s `SUM(debit)`/`SUM(credit)`,
`payroll.completed`'s `SUM(gross_pay)`, `inventory.low_stock`'s threshold-crossing comparison against the
*previous* row) are `producer_layer = 'trigger'`; events that are a simple, already-in-memory state
transition enriched with request-scoped actor/correlation context are `producer_layer = 'service'`.
Because both paths insert into the same table through the same constraints (the `event_name` foreign key
to `event_type_registry`, the RLS `WITH CHECK`), they are equally durable and equally subject to
everything else in this document — the choice of producer layer is purely about where the relevant data
and context are cheapest to gather, never about differing guarantees.

## Retention and partitioning

`domain_events` is one of the highest-insert-volume tables in the system — every meaningful state change
across every module produces a row — so it is partitioned by `RANGE (occurred_at)`, monthly, managed by
`pg_partman` (consistent with **Database Partitioning**'s treatment of `audit_logs`, the table's closest
sibling by volume and shape):

```sql
CREATE TABLE domain_events_2026_07 PARTITION OF domain_events
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE domain_events_2026_08 PARTITION OF domain_events
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

```php
// app/Console/Kernel.php (scheduled section)
$schedule->command('events:create-next-partition')->monthlyOn(24, '02:00');
$schedule->command('events:archive-old-partitions --older-than=90')->dailyAt('03:30');
```

Dispatched rows older than 90 days are archived to Cloudflare R2 as compressed newline-delimited JSON and
their partition is **detached and dropped**, never deleted row-by-row — at this table's volume, a
`DELETE` sweep would generate a bloat-inducing wave of dead tuples and a correspondingly expensive
autovacuum pass, whereas `ALTER TABLE ... DETACH PARTITION` followed by `DROP TABLE` is near-instant
regardless of the partition's row count. Rows in a `failed` status are never auto-archived even past 90
days until a human resolves them via **Dead Letter Queue** — losing a `pending`/`failed` event to a
retention job before it is ever successfully dispatched would silently and permanently drop a business
fact, which no retention policy is permitted to do.

## Ensuring the trigger/service write and the outbox write are truly atomic

Because `fn_emit_domain_event()` runs inside the same `AFTER` trigger invocation as the row-level
statement Laravel's Eloquent issues, and because every Service-layer emission (the `DomainEvent::create()`
path above) is written inside an explicit `DB::transaction()` closure that also contains the business
write it describes, the outbox insert and the business insert share one Postgres transaction by
construction — no additional application-level two-phase-commit logic, saga coordinator, or
distributed-transaction manager is needed anywhere in this pipeline:

```php
DB::transaction(function () use ($data) {
    $invoice = Invoice::create($data);   // fires "20_emit_domain_event" -> domain_events row, same txn
    $this->inventoryService->reserveLineItems($invoice);   // any other same-transaction work goes here
});
// COMMIT: the invoices row and the domain_events row are durable together, or neither is.
```

If a service cannot express its write as a single Eloquent statement (a raw multi-statement import, a
bulk data-migration backfill), it must wrap both the raw SQL and the emission inside the same explicit
`DB::transaction()` closure. QAYD's CI pipeline includes a static-analysis rule (a PHPStan custom rule
run in the same job as the `event_type_registry` cross-reference check from **Domain Events**) that flags
any migration or Service method touching a table named in the Domain Event Catalog without a surrounding
transaction — a missing transaction here is treated as a build-breaking defect, not a code-review nit,
because it silently reintroduces the exact dual-write hazard this entire pattern exists to eliminate.

# Inbox Pattern

The outbox guarantees an event is never lost between the business write and the bus. The **inbox
pattern** guarantees the mirror property on the consuming side: a consumer that processes the same event
twice — which *will* happen under at-least-once delivery (a Redis Stream consumer-group re-delivery after
a worker crash, a Horizon job retry after a transient failure, the relay re-publishing after a restart
before its cursor advanced) — must not apply its business effect twice.

## `event_inbox` DDL

```sql
CREATE TABLE event_inbox (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id        UUID NOT NULL,                   -- matches domain_events.uuid from the producer
    consumer_module VARCHAR(50) NOT NULL,             -- 'accounting', 'inventory', 'notifications', ...
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    status          VARCHAR(20) NOT NULL DEFAULT 'processing'
                    CHECK (status IN ('processing', 'processed', 'failed')),
    received_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at    TIMESTAMPTZ NULL,
    attempt_count   INTEGER NOT NULL DEFAULT 1,
    last_error      TEXT NULL,

    -- The core guarantee: one (event_id, consumer_module) pair can only ever be claimed once.
    CONSTRAINT uq_event_inbox_event_consumer UNIQUE (event_id, consumer_module)
);

CREATE INDEX idx_event_inbox_company ON event_inbox (company_id, received_at DESC);
CREATE INDEX idx_event_inbox_status ON event_inbox (status) WHERE status != 'processed';

ALTER TABLE event_inbox ENABLE ROW LEVEL SECURITY;
CREATE POLICY event_inbox_tenant_select ON event_inbox
    FOR SELECT USING (company_id = current_setting('app.company_id')::bigint);
CREATE POLICY event_inbox_tenant_insert ON event_inbox
    FOR INSERT WITH CHECK (company_id = current_setting('app.company_id')::bigint);

COMMENT ON TABLE event_inbox IS
  'Per-consumer dedup ledger. A listener MUST insert here (or hit the unique violation) before running
   any business side effect for an event. Distinct modules have independent rows for the same event_id
   because each consumer processes independently and must be individually idempotent — Accounting having
   already processed invoice.created must never suppress Notifications processing that same event.';
```

## Consumer-side claim-and-process pattern

```php
final class PostInvoiceToAccountingListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(RelayedDomainEvent $event): void
    {
        $claimed = DB::table('event_inbox')->insertOrIgnore([
            'event_id' => $event->eventId, 'consumer_module' => 'accounting',
            'company_id' => $event->companyId, 'status' => 'processing', 'received_at' => now(),
        ]);

        if (! $claimed) {
            $existing = DB::table('event_inbox')
                ->where('event_id', $event->eventId)->where('consumer_module', 'accounting')->first();

            if ($existing->status === 'processed') {
                Log::info('Duplicate delivery ignored (already processed)', ['event_id' => $event->eventId]);
                return;
            }
            if ($existing->status === 'processing'
                && now()->diffInSeconds($existing->received_at) < 120) {
                $this->release(10);   // another worker is actively handling it; retry shortly
                return;
            }
            // status = 'processing' but stale (> 120s: the prior worker almost certainly crashed
            // mid-flight) — fall through and reprocess; AccountingService::postInvoiceJournal() below
            // is independently idempotent, so a legitimate concurrent processor and a stale-lock retry
            // can never double-post even in this edge case.
        }

        DB::transaction(function () use ($event) {
            AccountingService::postInvoiceJournal($event->payload);   // idempotent by natural key, see Idempotency

            DB::table('event_inbox')->where('event_id', $event->eventId)->where('consumer_module', 'accounting')
                ->update(['status' => 'processed', 'processed_at' => now()]);
        });
    }
}
```

Note the defense-in-depth: `event_inbox` prevents the *listener* from double-running, and
`AccountingService::postInvoiceJournal()` is independently idempotent (**Idempotency**) so that even a
bug in the inbox bookkeeping itself cannot double-post a journal entry — QAYD never relies on a single
layer of deduplication for a money-moving operation.

## External inbound events: webhooks and callbacks

The `event_inbox` table above deduplicates QAYD's *internal* consumers reading off its own Stream. A
related but distinct boundary is **external** inbound events QAYD does not control the delivery semantics
of: payment-gateway webhooks, bank-aggregator callbacks, and the FastAPI AI layer's asynchronous result
callbacks can all redeliver the identical notification more than once (a webhook provider retrying on a
timeout it itself couldn't distinguish from a real failure). This boundary is handled by the
`idempotency_keys` table (**Database Standards**), reused here verbatim rather than redefined:

```sql
-- Reused exactly as defined in Database Standards; not redeclared with different columns here.
-- CREATE TABLE idempotency_keys (
--     id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, company_id BIGINT NOT NULL REFERENCES companies(id),
--     scope TEXT NOT NULL, key TEXT NOT NULL, response_hash TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
--     UNIQUE (company_id, scope, key)
-- );
```

```php
final class BankWebhookController
{
    public function handle(Request $request, int $bankAccountId): JsonResponse
    {
        $this->verifySignature($request);   // HMAC over raw body, reject before touching the DB otherwise

        $externalId = $request->input('event_id');   // the bank provider's own idempotency id
        $inserted = DB::transaction(function () use ($request, $externalId, $bankAccountId) {
            try {
                DB::table('idempotency_keys')->insert([
                    'company_id' => $request->attributes->get('company_id'),
                    'scope' => 'bank.webhook', 'key' => $externalId, 'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;   // already received this exact provider event_id — do not reprocess
            }
            ProcessBankTransactionJob::dispatch($request->all(), $bankAccountId)->onQueue('events-banking');
            return true;
        });

        // Always 200 OK, whether newly accepted or a duplicate: returning a non-2xx for a duplicate
        // would make most providers retry it again, forever.
        return response()->json(['received' => true, 'deduplicated' => ! $inserted]);
    }
}
```

`ProcessBankTransactionJob` performs the actual insert into `bank_transactions`, which is what the
statement-level `bank.synced` trigger (**Triggers**) fires on — so the full path for an inbound bank
webhook is: external dedup at `idempotency_keys` (this section) → durable business write into
`bank_transactions` → internal domain event emission into `domain_events` (**Outbox Pattern**) →
internal consumer dedup at `event_inbox` (this section) on the way out to Accounting, Banking
reconciliation, and the AI Fraud Detection agent. The two dedup tables guard two different trust
boundaries and neither substitutes for the other: `idempotency_keys` protects against an external
system redelivering the *same* physical webhook; `event_inbox` protects against QAYD's *own* Stream
redelivering the *same* internally-generated event to a slow or crashed consumer.

## Inbox retention

`event_inbox` rows are retained for 30 days — comfortably longer than any conceivable re-delivery window,
including a full Redis Stream consumer-group cursor reset — and then purged by the same scheduled command
that archives `domain_events` partitions, keeping the unique index small and the claim-lookup query fast
indefinitely regardless of total historical event volume.
# Idempotency

QAYD does not attempt true exactly-once delivery anywhere in this pipeline — exactly-once delivery across
a network boundary is not achievable without exactly the mechanism described here, so the platform is
explicit about targeting **effectively-once processing**: at-least-once delivery at every hop (outbox →
Stream → consumer group → Horizon job) composed with idempotent handling at every hop, such that the
*observable business effect* of processing an event twice is identical to processing it once. Three
independent, complementary layers implement this, each guarding a different failure mode:

| Layer | Table | Guards against | Scope |
|---|---|---|---|
| External-boundary dedup | `idempotency_keys` | An external system (bank provider, payment gateway, an API client retrying a POST) redelivering the identical request | One row per `(company_id, scope, key)` |
| Internal consumer dedup | `event_inbox` | QAYD's own Stream/queue redelivering the identical internal event to a listener that already handled it | One row per `(event_id, consumer_module)` |
| Natural idempotency | Business tables themselves | Both of the above failing, or a bug in either ledger — the last line of defense | Unique constraints / natural keys on the target table |

## API-level idempotency keys

Every mutating endpoint that creates a financial side effect (`POST /api/v1/sales/invoices`,
`POST /api/v1/banking/vendor-payments`, `POST /api/v1/payroll/runs/{id}/complete`) accepts an optional
client-generated `Idempotency-Key` header. The same pattern and table from **Database Standards**
applies, scoped per endpoint:

```php
final class CreateInvoiceController
{
    public function __invoke(CreateInvoiceRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key) {
            $existingResponse = DB::table('idempotency_keys')
                ->where(['company_id' => $request->companyId(), 'scope' => 'api.invoices.create', 'key' => $key])
                ->value('response_hash');
            if ($existingResponse) {
                return response()->json(json_decode(Cache::get("idem_response:{$key}"), true));
            }
        }

        $invoice = DB::transaction(function () use ($request, $key) {
            $invoice = InvoiceService::create($request->validated());   // fires domain_events row internally
            if ($key) {
                DB::table('idempotency_keys')->insert([
                    'company_id' => $request->companyId(), 'scope' => 'api.invoices.create',
                    'key' => $key, 'response_hash' => hash('sha256', (string) $invoice->id),
                    'created_at' => now(),
                ]);
                Cache::put("idem_response:{$key}", (new InvoiceResource($invoice))->toJson(), now()->addDay());
            }
            return $invoice;
        });

        return response()->json(new InvoiceResource($invoice), 201);
    }
}
```

A replayed request with the same key inside the 24-hour TTL window returns the cached original response
without re-running `InvoiceService::create()` at all — this is stronger than "processing twice has the
same effect," it is "processing only ever happens once," which matters for endpoints where even a
harmless-looking second execution would emit a second, real `invoice.created` event that downstream
consumers would treat as a second invoice.

## Idempotent event listeners

Every event listener registered against the bus must tolerate seeing the same event twice without
double-applying its effect, independent of and in addition to the `event_inbox` claim shown in
**Inbox Pattern**. QAYD uses the identical short-TTL guard pattern shown in **Database Architecture**'s
`SendInvoiceNotificationListener` example for cheap, non-transactional side effects (sending a
notification, warming a cache), and natural-key uniqueness for anything that writes a row:

```php
final class ProjectLedgerEntryListener implements ShouldQueue
{
    public function handle(RelayedDomainEvent $event): void
    {
        // journal.posted payload includes one lines[] entry per journal_line; ledger_entries is a
        // projection with a UNIQUE(journal_line_id), so re-processing the exact same journal.posted
        // event a second time hits a unique-violation no-op rather than a duplicate ledger row.
        foreach ($event->payload['lines'] as $line) {
            DB::table('ledger_entries')->insertOrIgnore([
                'company_id' => $event->companyId, 'journal_line_id' => $line['id'],
                'account_id' => $line['account_id'], 'debit' => $line['debit'], 'credit' => $line['credit'],
                'posted_at' => $event->occurredAt,
            ]);
        }
    }
}
```

```sql
-- The constraint that makes the listener above safe to run any number of times for the same event:
ALTER TABLE ledger_entries ADD CONSTRAINT uq_ledger_entries_journal_line UNIQUE (journal_line_id);
```

The same principle applies to `inventory_items` projections (`INSERT ... ON CONFLICT (company_id,
warehouse_id, product_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand` rather than a blind
`UPDATE ... SET quantity_on_hand = quantity_on_hand + delta`, since the latter is *not* idempotent — running
it twice for the same `stock_movements` row double-counts the delta, while an upsert keyed on the
movement's resulting absolute quantity is) and to any other read-model/projection table a consumer
maintains. As a platform rule: **a listener may only use a blind, non-idempotent mutation (a relative
`+=`/`-=` update) if it is provably impossible for that listener to ever see the same event twice** — which
in an at-least-once system is never provably true, so in practice every projection-maintaining listener
in QAYD is written against a natural key with `ON CONFLICT`, never a relative update.

# Retries

QAYD distinguishes **transient** failures (a network timeout calling a tenant's webhook URL, a Postgres
deadlock, a momentary Redis connection blip, the FastAPI AI service returning a 503 while scaling) — which
are retried automatically with backoff — from **permanent** failures (a `422` validation error, an
`event_name` absent from `event_type_registry`, a malformed JSON payload) — which are never retried and
go straight to **Dead Letter Queue** on their first occurrence, since retrying a deterministic failure
only delays the operator's awareness of it while consuming worker capacity.

## Job-level retry configuration

Every `ShouldQueue` listener declares its own retry ceiling and backoff schedule, reusing the exact
backoff arrays already configured per-supervisor in **Queues**:

```php
final class PostInvoiceToAccountingListener implements ShouldQueue
{
    public int $tries = 5;

    public function backoff(): array
    {
        return [5, 15, 60, 300, 900];   // seconds; matches supervisor-domain-consumers in config/horizon.php
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);     // hard ceiling: stop retrying a financial event after 24h regardless
                                          // of $tries, and move to Dead Letter Queue instead of retrying forever
    }

    public function failed(\Throwable $exception): void
    {
        DB::table('event_inbox')
            ->where('event_id', $this->event->eventId)->where('consumer_module', 'accounting')
            ->update(['status' => 'failed', 'last_error' => $exception->getMessage(),
                       'attempt_count' => DB::raw('attempt_count + 1')]);
        MoveToDeadLetterListener::dispatch($this->event, 'accounting', $exception)->onQueue('events-dlq-sweep');
    }
}
```

Non-financial, best-effort listeners (`events-notifications`, `events-ai`) use a shorter ceiling —
`retryUntil(now()->addHour())` — because a push notification or an AI suggestion that is still retrying a
day later is no longer useful to anyone even if it eventually succeeds; `events-audit` uses the longest
backoff schedule of all (`[2, 5, 10, 30, 60, 120, 300, 600, 900, 1800]`, ten attempts spanning roughly 50
minutes) because an audit write silently failing is a compliance gap, not a UX inconvenience, and is worth
retrying hard before giving up.

## Webhook delivery retries

Outbound webhooks to tenant-configured endpoints use the same exponential backoff schedule as the domain
consumers, plus endpoint-level health tracking:

```sql
CREATE TABLE webhook_endpoints (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    url                   TEXT NOT NULL,
    secret                TEXT NOT NULL,              -- HMAC signing secret, encrypted at rest
    subscribed_events     TEXT[] NOT NULL,             -- e.g. '{invoice.paid,payment.received}', '{*}' for all
    is_active             BOOLEAN NOT NULL DEFAULT true,
    consecutive_failures  SMALLINT NOT NULL DEFAULT 0,
    paused_at             TIMESTAMPTZ NULL,            -- circuit breaker: set after 10 consecutive failures
    created_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL
);

CREATE TABLE webhook_deliveries (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    webhook_endpoint_id BIGINT NOT NULL REFERENCES webhook_endpoints(id),
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    event_id          UUID NOT NULL,                   -- domain_events.uuid being delivered
    idempotency_key   TEXT NOT NULL,                    -- sha256(event_id || webhook_endpoint_id)
    http_status       SMALLINT NULL,
    attempt_count     SMALLINT NOT NULL DEFAULT 0,
    status            VARCHAR(20) NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending', 'delivered', 'failed')),
    last_attempted_at TIMESTAMPTZ NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (webhook_endpoint_id, event_id)
);
CREATE INDEX idx_webhook_deliveries_idempotency_hash ON webhook_deliveries USING HASH (idempotency_key);
```

```php
final class DeliverWebhookJob implements ShouldQueue
{
    public int $tries = 6;
    public function backoff(): array { return [10, 60, 300, 1800, 7200, 21600]; }   // 10s .. 6h

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::findOrFail($this->webhookEndpointId);
        if ($endpoint->paused_at !== null) { $this->delete(); return; }   // circuit open, do not attempt

        $body = json_encode($this->envelope);
        $signature = hash_hmac('sha256', $body, decrypt($endpoint->secret));

        try {
            $response = Http::timeout(10)->withHeaders(['X-Qayd-Signature' => $signature])
                ->post($endpoint->url, $this->envelope);
            if ($response->successful()) {
                $endpoint->update(['consecutive_failures' => 0]);
                $this->recordDelivery('delivered', $response->status());
                return;
            }
            throw new \RuntimeException("Webhook endpoint returned {$response->status()}");
        } catch (\Throwable $e) {
            $endpoint->increment('consecutive_failures');
            if ($endpoint->consecutive_failures >= 10) {
                $endpoint->update(['paused_at' => now(), 'is_active' => false]);
                NotifyTenantAdminOfPausedWebhook::dispatch($endpoint);   // tenant-facing alert
            }
            throw $e;   // re-throw so Horizon applies backoff/tries as configured above
        }
    }
}
```

Ten consecutive failures pausing the endpoint (a **circuit breaker**) protects both QAYD's own worker
capacity — retrying a permanently-broken tenant URL forever wastes `events-webhooks` throughput other
tenants share — and the tenant's own operational visibility, since a silently-failing webhook that no one
ever notices is worse than one that visibly pauses and asks to be re-verified.

## Deadlock and serialization-failure retries

Distinct from job-level retries, a transaction that fails with Postgres `SQLSTATE 40P01` (deadlock
detected) or `40001` (serialization failure, relevant to the `SERIALIZABLE` blocks described in
**Database Standards**) is retried at the *transaction* level, not the job level, since the fix is simply
to run the same logic again immediately rather than to wait out a backoff schedule meant for external
failures:

```php
DB::transaction(function () use ($event) {
    AccountingService::postInvoiceJournal($event->payload);
}, attempts: 3);   // Laravel re-executes the closure on 40P01/40001, with a small jittered delay, before
                    // surfacing the exception to the surrounding job's own $tries/backoff() handling
```

Idempotency and retries are inseparable: a retry is only safe because the handler is idempotent
(**Idempotency**), and idempotency is only worth having because retries (as opposed to giving up on the
first transient error) are the platform's default behavior for anything short of a permanent failure.

# Dead Letter Queue

A dead letter is an event, inbox claim, or queued job that has exhausted its retry budget (or hit a
permanent failure immediately) without ever completing successfully. Laravel's built-in `failed_jobs`
table captures the queue-job half of this automatically; QAYD adds `dead_letter_events` as the unified,
cross-cutting view that also covers `domain_events` rows stuck in `failed` and `event_inbox` rows stuck
in `failed`, so on-call engineers have exactly one place to look regardless of which layer of the
pipeline broke.

## `dead_letter_events` DDL

```sql
CREATE TABLE dead_letter_events (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    source_table    VARCHAR(30) NOT NULL
                    CHECK (source_table IN ('domain_events', 'event_inbox', 'failed_jobs', 'webhook_deliveries')),
    source_id       BIGINT NOT NULL,               -- the id in the referenced source_table
    event_name      VARCHAR(150) NULL,
    payload         JSONB NOT NULL,
    failure_reason  TEXT NOT NULL,
    stack_trace     TEXT NULL,
    retry_count     INTEGER NOT NULL DEFAULT 0,
    severity        VARCHAR(10) NOT NULL DEFAULT 'warning'
                    CHECK (severity IN ('critical', 'warning', 'info')),
    status          VARCHAR(20) NOT NULL DEFAULT 'needs_review'
                    CHECK (status IN ('needs_review', 'requeued', 'discarded', 'resolved')),
    failed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at     TIMESTAMPTZ NULL,
    resolved_by     BIGINT NULL REFERENCES users(id)
);

CREATE INDEX idx_dead_letter_events_status ON dead_letter_events (status) WHERE status = 'needs_review';
CREATE INDEX idx_dead_letter_events_company ON dead_letter_events (company_id, failed_at DESC);
CREATE INDEX idx_dead_letter_events_severity ON dead_letter_events (severity) WHERE status = 'needs_review';

ALTER TABLE dead_letter_events ENABLE ROW LEVEL SECURITY;
CREATE POLICY dead_letter_events_tenant_select ON dead_letter_events
    FOR SELECT USING (company_id = current_setting('app.company_id')::bigint);
```

`severity` is set at insertion time from a static map keyed by `event_name`'s producer module: events
touching money movement or statutory compliance (`journal.posted`, `payment.received`, `payroll.completed`,
`tax.return_filed`) are always `critical`; everything else defaults to `warning` unless the failure
recurs more than 3 times for the same `event_name` within an hour, which is auto-escalated to `critical`
regardless of the event's own default.

## Routing to the dead letter table

```php
final class MoveToDeadLetterListener
{
    public function handle(RelayedDomainEvent $event, string $consumerModule, \Throwable $exception): void
    {
        DB::table('dead_letter_events')->insert([
            'company_id'     => $event->companyId,
            'source_table'   => 'event_inbox',
            'source_id'      => $event->inboxRowId,
            'event_name'     => $event->eventName,
            'payload'        => json_encode($event->payload),
            'failure_reason' => $exception->getMessage(),
            'stack_trace'    => $exception->getTraceAsString(),
            'severity'       => CriticalEventNames::contains($event->eventName) ? 'critical' : 'warning',
            'failed_at'      => now(),
        ]);

        if (CriticalEventNames::contains($event->eventName)) {
            PageOnCall::dispatch("DLQ: {$event->eventName} failed for consumer {$consumerModule}", $event);
        } else {
            Log::warning('Event moved to dead letter queue', ['event_name' => $event->eventName]);
        }
    }
}
```

## Scheduled sweep (`events-dlq-sweep` queue)

The `events-dlq-sweep` queue declared in **Queues** runs a command every 5 minutes with three
responsibilities: requeuing `domain_events` rows stuck in `processing` past a sane relay round-trip time
(the crash-recovery case introduced in **Event Bus**), surfacing newly `failed` rows into
`dead_letter_events` if a caller bypassed the listener-level `failed()` hook, and re-checking whether any
`webhook_endpoints.paused_at` circuit breakers are eligible for an automatic health-check probe:

```php
final class SweepDeadLetterCandidates extends Command
{
    protected $signature = 'events:dlq-sweep';

    public function handle(): void
    {
        // 1. Requeue domain_events stuck "processing" for over 60s — the relay almost certainly crashed
        //    between marking processing and confirming XADD succeeded.
        DB::table('domain_events')
            ->where('status', 'processing')
            ->where('occurred_at', '<', now()->subSeconds(60))
            ->update(['status' => 'pending']);

        // 2. Any domain_events row that reached 'failed' (relay-level XADD failures, not consumer
        //    failures) and has not yet been mirrored into dead_letter_events.
        DomainEvent::where('status', 'failed')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('dead_letter_events')
                ->whereColumn('source_id', 'domain_events.id')->where('source_table', 'domain_events'))
            ->each(fn ($row) => MoveToDeadLetterListener::handleRelayFailure($row));

        // 3. Health-check probe: a paused webhook endpoint whose URL now responds 2xx to a lightweight
        //    ping is automatically un-paused rather than requiring manual intervention for a transient
        //    tenant-side outage that has since resolved.
        WebhookEndpoint::where('paused_at', '<', now()->subHours(1))->each(function ($endpoint) {
            if (Http::timeout(5)->get($endpoint->url . '/health')->successful()) {
                $endpoint->update(['paused_at' => null, 'is_active' => true, 'consecutive_failures' => 0]);
            }
        });
    }
}
```

## Alerting and review SLA

`critical`-severity dead letters (any event touching `journal.posted`, `payment.received`,
`payroll.completed`, `bank.synced`, `tax.return_filed`) page on-call immediately via the same
notification channel **Monitoring** wires into PagerDuty/Slack; `warning`-severity entries are logged and
surfaced on the ops dashboard without paging. As a platform business rule, every `critical` dead letter
must be triaged — not necessarily resolved, but at minimum acknowledged with a documented next step —
within **one business hour**; `warning`-severity entries carry a 24-hour triage SLA. This SLA is itself
monitored (**Monitoring**) by alerting on `dead_letter_events` rows where `status = 'needs_review'` and
`failed_at` has aged past the relevant SLA window.

## Manual replay

```php
// php artisan events:dlq-replay {id}
final class ReplayDeadLetterEvent extends Command
{
    protected $signature = 'events:dlq-replay {id}';

    public function handle(): int
    {
        Gate::authorize('system.dlq.replay');   // Owner / CFO / platform-admin only, see Database Standards RBAC

        $dlq = DB::table('dead_letter_events')->findOrFail($this->argument('id'));
        DB::transaction(function () use ($dlq) {
            DB::table('domain_events')->insert([
                'company_id' => $dlq->company_id, 'event_name' => $dlq->event_name,
                'aggregate_type' => json_decode($dlq->payload, true)['aggregate_type'] ?? 'unknown',
                'aggregate_id' => json_decode($dlq->payload, true)['aggregate_id'] ?? 0,
                'payload' => $dlq->payload, 'status' => 'pending', 'occurred_at' => now(),
            ]);
            DB::table('dead_letter_events')->where('id', $dlq->id)
                ->update(['status' => 'requeued', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
        });
        $this->info("Re-queued dead letter {$dlq->id} as a fresh domain_events row.");
        return self::SUCCESS;
    }
}
```

Replaying inserts a **new** `domain_events` row with a fresh `id`/`uuid` rather than resurrecting the
original — the original stays in `dead_letter_events` with `status = 'requeued'` as a permanent audit
trail of "this failed once, a human reviewed it, and it was deliberately resubmitted on `<date>`."
# Audit Events

`audit_logs` and `domain_events` are complementary, not redundant: `audit_logs` is the **superset** —
every mutation to a business record (create, update, soft-delete, restore, every state transition)
writes an immutable audit row, whether or not that mutation is business-meaningful enough to warrant a
cross-module domain event — while `domain_events` exists specifically for module-to-module communication
and covers only the subset of mutations another module or the AI layer actually needs to react to. A
column rename on an internal note field, for instance, is audited but never emitted as a domain event; a
journal entry posting is both.

## `audit_logs` schema (reused verbatim from Database Architecture)

```sql
CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    user_id         BIGINT NULL REFERENCES users(id),        -- NULL for system/AI-driven changes
    actor_type      VARCHAR(20) NOT NULL DEFAULT 'user'
                    CHECK (actor_type IN ('user', 'ai_agent', 'system', 'api_integration')),
    ai_agent_name   VARCHAR(100) NULL,                        -- populated when actor_type = 'ai_agent'
    action          VARCHAR(50) NOT NULL,                     -- created, updated, deleted, restored, posted, voided...
    auditable_type  VARCHAR(100) NOT NULL,                    -- 'Invoice', 'JournalEntry', ...
    auditable_id    BIGINT NOT NULL,
    old_values      JSONB NULL,
    new_values      JSONB NULL,
    reason          TEXT NULL,                                -- required for voids/reversals/permission changes
    ip_address      INET NULL,
    user_agent      TEXT NULL,
    device_id       VARCHAR(100) NULL,
    request_id      UUID NULL,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (occurred_at);
```

This document does not redeclare `audit_logs`' indexes or partitioning cadence — those are owned by
**Database Architecture** and **Database Partitioning** — but does own the trigger that populates it for
every table this document's event catalog touches, and the interaction between that trigger and the
event-emission trigger on the same tables.

## The generic audit trigger

```sql
CREATE OR REPLACE FUNCTION trg_fn_audit_log() RETURNS trigger AS $$
DECLARE
    v_action VARCHAR(50);
BEGIN
    v_action := CASE
        WHEN TG_OP = 'INSERT' THEN 'created'
        WHEN TG_OP = 'DELETE' THEN 'deleted'
        WHEN TG_OP = 'UPDATE' AND OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN 'soft_deleted'
        WHEN TG_OP = 'UPDATE' AND OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN 'restored'
        ELSE 'updated'
    END;

    INSERT INTO audit_logs (company_id, user_id, actor_type, ai_agent_name, action, auditable_type,
                             auditable_id, old_values, new_values, ip_address, request_id, occurred_at)
    VALUES (
        COALESCE(NEW.company_id, OLD.company_id),
        NULLIF(current_setting('app.actor_user_id', true), '')::bigint,
        COALESCE(NULLIF(current_setting('app.actor_type', true), ''), 'user'),
        NULLIF(current_setting('app.ai_agent_name', true), ''),
        v_action, TG_TABLE_NAME,
        COALESCE(NEW.id, OLD.id),
        CASE WHEN TG_OP != 'INSERT' THEN to_jsonb(OLD) END,
        CASE WHEN TG_OP != 'DELETE' THEN to_jsonb(NEW) END,
        NULLIF(current_setting('app.request_ip', true), '')::inet,
        NULLIF(current_setting('app.request_id', true), '')::uuid,
        now()
    );
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

-- Attached, per the trigger-ordering convention from Triggers, BEFORE the event-emission trigger:
CREATE TRIGGER "10_audit_log"
    AFTER INSERT OR UPDATE OR DELETE ON invoices
    FOR EACH ROW EXECUTE FUNCTION trg_fn_audit_log();
-- ...and identically on journal_entries, payroll_runs, stock_movements, bank_transactions, and every
-- other table carrying a "20_emit_domain_event" trigger from the Triggers section.
```

Laravel's request middleware sets the `app.actor_user_id` / `app.actor_type` / `app.ai_agent_name` /
`app.request_ip` / `app.request_id` session-local `SET LOCAL` variables at the start of every request
(and every queued-job execution restores them from the job's serialized context), so the trigger — which
has no visibility into the HTTP request itself — can still populate a fully attributed audit row using
only `current_setting()`. This is the same technique **Database Architecture** shows applied at the
Eloquent-observer layer; here it is pushed one level lower, into the trigger itself, specifically so that
a raw SQL statement or an admin console query against these tables is *still* audited even if it bypasses
Eloquent entirely.

## Immutability

`audit_logs` rows are insert-only. No application role is ever granted `UPDATE` or `DELETE` on the table:

```sql
REVOKE UPDATE, DELETE ON audit_logs FROM app_role;
GRANT INSERT, SELECT ON audit_logs TO app_role;
GRANT ALL ON audit_logs TO archival_role;   -- used exclusively by the partition-archival job

-- Belt-and-suspenders: even a role that somehow retained the grant cannot use it.
CREATE OR REPLACE FUNCTION fn_audit_logs_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs rows are immutable; % is not permitted', TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_logs_immutable
    BEFORE UPDATE OR DELETE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION fn_audit_logs_immutable();
```

## Audit events on the bus

Most `audit_logs` inserts stay local — at the volume of every field-level update across the whole
platform, publishing every one as a domain event would flood the bus for no consumer's benefit. A narrow
subset of security-sensitive actions is **also** published as `audit.security_event` (already in the
**Domain Event Catalog**) specifically for real-time consumption by the AI Fraud Detection agent and
on-call notification, distinct from the routine per-row audit trail: permission/role changes, a financial
document void or reversal, a login from a previously unseen device or IP range, and a change to a
company's own security settings (2FA requirement, IP allowlist). This keeps `audit_logs` as the complete,
high-volume, query-on-demand forensic record, and `domain_events`/`audit.security_event` as the
low-volume, real-time security signal — the same "superset vs. subset for cross-cutting reaction" shape
that distinguishes `audit_logs` from `domain_events` in general.

## Retention

`audit_logs` is retained far longer than `domain_events`' 90-day window: consistent with **Database
Partitioning**'s treatment of history/audit tables, partitions are yearly, never dropped outright, and are
moved to a cheaper storage tablespace (and eventually archived to Cloudflare R2) after a configurable
number of years — 10 by default, adjustable per company to match its jurisdiction's statutory
recordkeeping requirement (GCC financial recordkeeping norms generally expect a minimum of 5 years; QAYD
defaults higher to avoid a customer having to request a policy change the one time it turns out to
matter).

# AI Events

The platform rule that governs every AI interaction with this pipeline is stated once, precisely, and
never contradicted anywhere in this document: **the FastAPI AI layer never writes to PostgreSQL
directly, has no database credentials at all, and participates in the event pipeline exclusively as a
consumer of domain events and, symmetrically, as a caller of the same authenticated Laravel REST API any
human user would use.** This is not a convenience choice — it is what makes "AI obeys the same
permissions as the user" (the platform-wide AI rule from the shared design context) mechanically true
rather than aspirational: there is no code path by which an AI agent's output reaches a table without
passing through the identical `FormRequest` validation and policy/permission gate a human-submitted
request would.

## Consumption: how AI agents receive events

The `events-ai` queue and its consumer group (**Queues**, **Event Bus**) is the only path by which the AI
layer learns anything happened. A thin bridge job forwards the relevant subset of each event to FastAPI:

```php
final class NotifyAiLayerListener implements ShouldQueue
{
    private const AGENT_SUBSCRIPTIONS = [
        'fraud_detection'  => ['payment.received', 'bank.synced', 'audit.security_event'],
        'forecast_agent'   => ['journal.posted', 'invoice.paid'],
        'inventory_manager'=> ['inventory.updated', 'inventory.low_stock'],
        'payroll_manager'  => ['payroll.completed'],
        'compliance_agent' => ['tax.return_filed', 'bank.reconciled'],
    ];

    public function handle(RelayedDomainEvent $event): void
    {
        $interestedAgents = collect(self::AGENT_SUBSCRIPTIONS)
            ->filter(fn ($events) => in_array($event->eventName, $events, true))->keys();

        foreach ($interestedAgents as $agent) {
            Http::withToken(config('services.ai_layer.internal_token'))
                ->timeout(5)
                ->post(config('services.ai_layer.url') . '/internal/events', [
                    'agent' => $agent, 'event' => $event->toArray(),
                ]);
        }
    }
}
```

The internal HTTP call is fire-and-forget from Laravel's perspective (a 5-second timeout, no retry loop
here — the `events-ai` queue's own job-level retry, per **Retries**, handles transient failures) because
the AI layer's own processing of the event is asynchronous on its side too; FastAPI queues the analysis
internally and reports back later via the callback path below, it does not block this HTTP response on
finishing the analysis.

## Production: how AI proposals reach the database

An AI agent never inserts a row. Instead, once FastAPI has produced a result, it calls back into
Laravel's ordinary authenticated REST API:

```
POST /api/v1/ai/proposals
Authorization: Bearer <service-account token, scoped to ai_agent role>
{
  "agent_type": "inventory_manager",
  "target_type": "purchase_order",
  "confidence_score": 0.87,
  "reasoning": "product_id 4471 crossed reorder_point 3x in 14 days; supplier lead time 5 days; proposing reorder_quantity 200 units from vendor_id 88 (last PO price basis).",
  "proposed_payload": { "vendor_id": 88, "warehouse_id": 3, "lines": [{"product_id": 4471, "quantity": 200}] },
  "source_event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10"
}
```

This request runs through the exact same `CreatePurchaseOrderRequest` `FormRequest` and
`purchasing.order.create` permission gate a human Purchasing Employee's request would — the only
difference is that the authenticated principal is a reserved `ai_agent` service-account role, which
Laravel's audit middleware recognizes and sets `app.actor_type = 'ai_agent'` / `app.ai_agent_name` for
(flowing straight into the `audit_logs` row per **Audit Events**). The resulting row is created with
`status = 'ai_draft'`, `confidence_score`, and `reasoning` columns (present on every table an AI agent can
propose into — `purchase_orders`, `journal_entries`, `tax_transactions`, etc. — per that table's own
module document) rather than a normal `draft`/`posted` status, so it is unambiguous in every view of the
data that a human has not yet reviewed it.

```sql
-- A database-level guardrail, not just an application-level one: even if a Laravel policy bug let an
-- ai_agent-authenticated request through with the wrong status, the database itself refuses to let an
-- AI-attributed row land directly in a terminal, money-moving state on the tables where that matters most.
ALTER TABLE journal_entries ADD CONSTRAINT chk_ai_draft_requires_review
    CHECK (NOT (created_by IS NULL AND source = 'ai' AND status IN ('posted', 'approved')));

ALTER TABLE purchase_orders ADD CONSTRAINT chk_ai_draft_requires_review
    CHECK (NOT (source = 'ai' AND status IN ('approved', 'sent')));
```

## `ai.finished` and autonomy metadata

`ai.finished` (**Domain Event Catalog**) is emitted by Laravel, not by FastAPI directly — `producer_layer
= 'ai_callback'` in `event_type_registry` — the moment the `/api/v1/ai/proposals` callback endpoint's own
transaction commits, exactly mirroring every other producer path in this document: the AI layer is
symmetric with every other event source, never a special case that bypasses the outbox.

```json
{
  "event_name": "ai.finished",
  "payload": {
    "ai_conversation_id": 88213,
    "agent_type": "inventory_manager",
    "confidence_score": 0.87,
    "proposed_action": "create_purchase_order",
    "requires_approval": true
  },
  "metadata": {
    "ai_meta": {
      "agent": "inventory_manager",
      "confidence": 0.87,
      "reasoning_summary": "Reorder threshold crossed 3x in 14 days; proposing 200-unit PO.",
      "autonomy": "requires_approval"
    }
  }
}
```

`autonomy` (`auto` | `suggest_only` | `requires_approval`) drives how downstream consumers treat the
event: `events-notifications` only interrupts a human with a push/email alert for `suggest_only` and
`requires_approval`; `auto`-autonomy actions (currently limited to low-risk, reversible operations like
auto-categorizing an already-matched bank transaction) complete without a notification at all, logged
only. No autonomy level, including `auto`, ever applies to the sensitive-operation list from **Database
Standards**' RBAC section (bank transfers, payroll release, tax submission, voiding financial data,
permission changes) — those are hard-coded to `requires_approval` regardless of what confidence score the
agent reports, enforced by the same `event_type_registry`/CI mechanism and the `CHECK` constraints shown
above, not by the AI agent's own self-reported autonomy field being trusted at face value.
# Monitoring

The event pipeline is only as trustworthy as its observability — a silently-growing outbox backlog or a
stalled consumer group is, from a tenant's perspective, indistinguishable from data loss until someone
notices. Every signal below is exported as a Prometheus metric (scraped from a `/metrics` endpoint a
scheduled Laravel command refreshes every 15 seconds) and mirrored into Laravel Telescope for
development-time debugging.

## Key metrics

| Metric | Type | Labels | What it means |
|---|---|---|---|
| `domain_events_pending_count` | Gauge | `company_id` (top offenders only) | Rows in `domain_events` with `status IN ('pending','processing')`; should hover near zero |
| `domain_events_dispatch_latency_seconds` | Histogram | `event_name` | Time between `occurred_at` and `dispatched_at`; the outbox's own "time to publish" |
| `event_consumer_lag` | Gauge | `consumer_group` | Redis `XINFO GROUPS` lag (entries not yet delivered) per module's consumer group |
| `event_consumer_pending` | Gauge | `consumer_group` | Entries delivered but not yet `XACK`'d (`XPENDING`); a rising trend indicates a stuck consumer |
| `dead_letter_events_depth` | Gauge | `severity`, `company_id` | Rows with `status = 'needs_review'`; the DLQ backlog |
| `webhook_delivery_success_rate` | Gauge (ratio) | `webhook_endpoint_id` | `delivered / (delivered + failed)` over a rolling 1-hour window |
| `queue_job_failure_rate` | Gauge (ratio) | `queue` | Horizon's own per-queue failure rate, re-exported for a single dashboard |
| `idempotency_key_replay_rate` | Counter | `scope` | How often a replayed `Idempotency-Key` short-circuits real processing — a useful signal of client retry behavior, not itself a problem |

## Alert thresholds

| Condition | Threshold | Action |
|---|---|---|
| `domain_events_pending_count` | > 500 for > 5 minutes | Page on-call — the relay is falling behind or down |
| `event_consumer_lag{consumer_group="accounting"}` (or any financial-module group) | > 5,000 | Page on-call |
| `event_consumer_lag` (non-financial group) | > 20,000 | Warn (Slack), do not page |
| `dead_letter_events_depth{severity="critical"}` | > 0 and increasing | Page on-call immediately (see **Dead Letter Queue** SLA) |
| `webhook_delivery_success_rate` | < 80% over 1h for a given endpoint | Tenant-facing alert + internal warn |
| `events:relay` process heartbeat | absent for > 60s | Page on-call — singleton relay is down, `domain_events` is not draining at all |

## Dashboards

A single Grafana folder, "Event Pipeline," holds five panels that together answer "is the bus healthy"
without needing to query Postgres or Redis directly during an incident: **Outbox Health** (pending count
+ dispatch latency percentiles, p50/p95/p99), **Consumer Lag by Group** (one line per module, log scale),
**Dead Letter Trend** (7-day rolling count by severity, annotated with deploys), **Webhook Delivery
Success by Tenant** (top 20 endpoints by failure count), and **Event Volume by Type** (stacked area,
`event_name` over time, the fastest way to visually spot an unexpected spike — e.g. a runaway loop
re-emitting `inventory.updated` — before it becomes a `pending_count` incident).

## Distributed tracing

`correlation_id` (constant across an entire business-transaction chain) and `causation_id` (the direct
parent event) from the **Canonical Event Envelope**, together with the `X-Request-Id` HTTP header
propagated end to end, join structured JSON logs (Laravel's log context, automatically attached to every
log line for the duration of a request or job) and OpenTelemetry spans across the async boundary: a
single trace can show `HTTP POST /invoices` → `domain_events INSERT` → `relay XADD` → `accounting
consumer processing journal.posted` → `ledger_entries projection write`, all joined on the one
`correlation_id`, even though three of those five steps ran in different processes seconds apart. This is
the mechanism that turns "why did this invoice's ledger entry take 40 seconds to appear" from a
multi-system archaeology exercise into a single trace lookup.

## Health checks

```php
// GET /health/events — used by the load balancer's/orchestrator's liveness probe and by on-call tooling
Route::get('/health/events', function () {
    $oldestPending = DB::table('domain_events')->where('status', 'pending')->min('occurred_at');
    $relayHeartbeat = Cache::get('relay-leader-heartbeat');
    $streamInfo = Redis::connection('events')->xinfo('STREAM', 'domain-events');

    $healthy = $oldestPending === null || now()->diffInSeconds($oldestPending) < 30;

    return response()->json([
        'healthy'               => $healthy,
        'oldest_pending_age_s'  => $oldestPending ? now()->diffInSeconds($oldestPending) : 0,
        'relay_heartbeat_age_s' => $relayHeartbeat ? now()->diffInSeconds($relayHeartbeat) : null,
        'stream_length'         => $streamInfo['length'] ?? null,
    ], $healthy ? 200 : 503);
});
```

## On-call debugging queries

```sql
-- What's stuck, right now, and for how long?
SELECT id, event_name, company_id, status, attempts, occurred_at,
       EXTRACT(EPOCH FROM (now() - occurred_at)) AS age_seconds
FROM domain_events
WHERE status IN ('pending', 'processing', 'failed')
ORDER BY occurred_at ASC
LIMIT 50;

-- Follow one business transaction end-to-end by correlation_id:
SELECT event_name, status, occurred_at, dispatched_at, metadata->>'causation_id' AS causation_id
FROM domain_events
WHERE metadata->>'correlation_id' = '018f2e6a-1120-7000-8b1e-3a2c5e6f7a00'
ORDER BY occurred_at;

-- Which consumer groups are behind, right now, without leaving psql:
-- (run from a Laravel tinker session or a small wrapper command against Redis)
-- XINFO GROUPS domain-events

-- Today's dead-letter volume by severity, for the morning on-call standup:
SELECT severity, count(*) FROM dead_letter_events
WHERE failed_at >= current_date AND status = 'needs_review'
GROUP BY severity ORDER BY severity;
```

# Examples

Five fully worked, end-to-end traces through the pipeline, each showing the database rows, the relayed
Stream entry, consumer handling, and the final artifact a consumer or tenant would actually see.

## Example 1 — Invoice created, then paid

```sql
INSERT INTO invoices (company_id, branch_id, customer_id, status, total_amount, currency_code, due_date)
VALUES (4821, 12, 991, 'posted', 1250.5000, 'KWD', '2026-08-15')
RETURNING id;  -- 55231
```

Trigger `"20_emit_domain_event"` on `invoices` fires, inserting into `domain_events`:

```json
{
  "id": 918273, "uuid": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10",
  "company_id": 4821, "event_name": "invoice.created",
  "aggregate_type": "invoice", "aggregate_id": 55231,
  "payload": {
    "invoice_id": 55231, "customer_id": 991, "total_amount": "1250.5000",
    "currency_code": "KWD", "due_date": "2026-08-15", "status": "posted",
    "line_items": [
      {"product_id": 2280, "quantity": "5.0000", "unit_price": "200.1000"},
      {"product_id": 2281, "quantity": "2.0000", "unit_price": "125.0000"}
    ]
  },
  "status": "pending", "occurred_at": "2026-07-16T09:14:22.451Z"
}
```

The relay `XADD`s it to `domain-events`; the `sales`, `accounting`, `ai`, and `audit` consumer groups
each independently pick it up. Three weeks later, a bank webhook triggers a `receipts` allocation that
fully settles the invoice:

```sql
UPDATE invoices SET status = 'paid', amount_paid = 1250.5000, paid_at = now()
WHERE id = 55231;
```

which emits `invoice.paid` with `payload.remaining_balance = "0.0000"`. The Accounting consumer group's
listener allocates the receipt against the AR control account (its own module-owned logic, out of this
document's scope); the webhook consumer group delivers both events, in order, to any tenant-configured
endpoint subscribed to `invoice.*`.

## Example 2 — Payment received, reconciled, and posted

A bank sync batch inserts 40 `bank_transactions` rows in one statement → one `bank.synced` event
(`transactions_imported: 40, transactions_matched: 31, transactions_unmatched: 9`) via the
statement-level trigger. The reconciliation engine (Banking module, consuming its own queue) matches nine
of the remaining unmatched rows to open invoices over the next few minutes; each match is a row-level
`UPDATE ... SET matched_entity_id = ...`, firing the separate row-level `payment.received` trigger once
per matched row:

```json
{
  "event_name": "payment.received",
  "payload": {"bank_transaction_id": 771234, "amount": "500.0000", "bank_account_id": 12,
              "matched_entity_type": "customer", "matched_entity_id": 991}
}
```

Sales' listener applies the `500.0000` against invoice 55231's remaining balance (crossing zero triggers
`invoice.paid`, per Example 1); Accounting's listener independently posts the AR-clearing journal entry,
which itself posts through the `journal.posted` trigger once its own lines balance — a `causation_id`
chain three events deep (`bank.synced` → `payment.received` → `journal.posted`), all sharing one
`correlation_id` rooted at the original sync batch, exactly the chain **Monitoring**'s tracing example
follows.

## Example 3 — Payroll run completion

```sql
UPDATE payroll_runs SET status = 'completed' WHERE id = 771 AND status = 'approved';
```

fires `trg_fn_payroll_runs_events()`, which sums `payroll_items` and emits:

```json
{
  "event_name": "payroll.completed",
  "payload": {"payroll_run_id": 771, "period_start": "2026-07-01", "period_end": "2026-07-31",
              "total_gross": "48200.0000", "total_net": "41850.0000", "total_deductions": "6350.0000",
              "employee_count": 34, "journal_entry_id": 90441}
}
```

Three consumer groups act on it concurrently: Accounting confirms the referenced `journal_entry_id` is
posted (or posts it, if payroll's own service created it in `draft` and defers posting to this listener);
Banking generates the bulk salary-transfer batch file for the sponsor bank; Notifications emails 34
individual payslip-ready notices, each looked up by `employee_id` from `payroll_items`, and — separately
— `payslip.issued` fires per payslip as its own finer-grained event for any consumer that only cares about
one employee at a time rather than the whole run.

## Example 4 — Inventory low stock triggers an AI purchase-order proposal

A stock issue drops `product_id 4471` at `warehouse_id 3` from 42 to 8 units, crossing its
`reorder_point` of 10:

```json
[
  {
    "event_name": "inventory.updated",
    "payload": {"product_id": 4471, "warehouse_id": 3, "quantity_delta": "-34.0000",
                "movement_type": "issue", "resulting_on_hand": "8.0000"}
  },
  {
    "event_name": "inventory.low_stock",
    "payload": {"product_id": 4471, "warehouse_id": 3, "on_hand": "8.0000",
                "reorder_point": "10.0000", "reorder_quantity": "200.0000"}
  }
]
```

Both events reach the `ai` consumer group; `NotifyAiLayerListener` forwards `inventory.low_stock` to the
`inventory_manager` agent (per its subscription list in **AI Events**). FastAPI analyzes recent lead times
and calls back `POST /api/v1/ai/proposals`, which creates a `purchase_orders` row with `status =
'ai_draft'`, `source = 'ai'`, `confidence_score = 0.87`, blocked by the `chk_ai_draft_requires_review`
constraint from ever reaching `approved`/`sent` without a human's explicit action — and whose insert, in
turn, emits `ai.finished` with `requires_approval: true`, landing in a Purchasing Manager's approval
queue via `events-notifications`.

## Example 5 — Bank sync batch reconciliation

Extending Example 2: of the original 40-transaction sync batch, 31 auto-matched immediately and 9 were
left for the reconciliation engine, which resolved all but one within the hour (the last one requires a
human — an unidentified counterparty). The finance team completes the monthly reconciliation:

```sql
UPDATE bank_reconciliations
SET status = 'completed', statement_balance = 128450.00, book_balance = 128450.00, variance = 0.00
WHERE id = 552;
```

emitting `bank.reconciled` with `variance: "0.0000"` — consumed by Accounting (closes the reconciliation
period) and Audit (a compliance-relevant milestone, cross-referenced against the `audit_logs` row the
same `UPDATE` also produced via the generic audit trigger). Had `variance` been non-zero, the same event
payload would carry the discrepancy amount, and the AI Fraud Detection agent's subscription to
`bank.synced` (not `bank.reconciled` itself, since the agent's job is to flag *unmatched or unusual*
activity as it arrives, not to react to the reconciliation's own closing) would already have had a chance
to flag any individual transaction worth a human's attention well before this monthly close.

# End of Document
