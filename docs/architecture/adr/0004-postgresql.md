# ADR-0004: Use PostgreSQL as the system of record

Status: Accepted

Date: 2026-07

## Context

QAYD is a double-entry accounting core. Its data has non-negotiable requirements that the choice of database
either satisfies structurally or leaves to application code to fake: every posting must be atomic and its
debits must equal its credits ([../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md)
ADR-006); money must be exact, not floating-point ([../../foundation/TECH_STACK.md](../../foundation/TECH_STACK.md)
names PostgreSQL for ACID and complex queries); tenant isolation must be enforceable at the storage engine
([ADR-0005](./0005-multitenancy-rls.md)); and the AI layer needs vector similarity search for its memory and
retrieval ([ADR-0007](./0007-ai-orchestrator.md)). QAYD's managed PostgreSQL is provided by Supabase
([ADR-0003](./0003-supabase-as-backend.md)) as a data service, and the Laravel backend and the documented
target both build on Postgres — they agree on PostgreSQL.

## Decision

The system of record is **PostgreSQL** (15+). Every business fact — chart of accounts, journal entries and
lines, invoices, inventory, payroll, tax — lives in relational tables with real foreign keys, `CHECK`
constraints, and transactions. Monetary amounts are stored as **`NUMERIC(19,4)`**, never floats, giving exact
decimal arithmetic with headroom below the Kuwaiti dinar's three-decimal precision (per the design target's
ADR-009). Semi-structured data (AI proposal payloads, event bodies, flexible metadata) uses **`JSONB`**.
Tenant isolation is enforced by **Row-Level Security** ([ADR-0005](./0005-multitenancy-rls.md)). AI memory and
retrieval use the **pgvector** extension for embedding similarity search, keeping vectors in the same database
as the facts they describe rather than in a separate vector store for the MVP.

## Consequences

Positive:
- Referential integrity and the balance invariant can be enforced in the database with constraints and atomic transactions, so a corrupt ledger is structurally hard to write rather than merely discouraged in code.
- `NUMERIC(19,4)` eliminates floating-point money drift, so the double-entry balance check operates on exact decimals.
- One engine covers relational data, JSONB documents, full-text search (Phase 1), and pgvector similarity, so the MVP avoids running a second datastore.
- RLS ([ADR-0005](./0005-multitenancy-rls.md)) is a Postgres feature, so the strongest isolation layer is native, not bolted on.

Negative / trade-offs:
- Postgres must be operated with care — connection pooling, index tuning, vacuum, and migration hygiene are real responsibilities (largely borne by Supabase's managed Postgres, [ADR-0003](./0003-supabase-as-backend.md)).
- Rigid relational schemas make some rapid, exploratory changes heavier than a schema-less store would; migrations are mandatory and ordered.
- pgvector at very large scale may eventually warrant a dedicated vector database; co-locating it is a deliberate simplification for now.

## Alternatives considered

- **MySQL / MariaDB:** capable and ACID, but weaker JSONB, no native RLS of Postgres's strength, and no first-class pgvector — we would lose the isolation floor and the co-located AI memory that make this stack coherent.
- **MongoDB or another document store:** flexible schemas, but double-entry accounting needs relational integrity, multi-row atomic transactions, and SQL aggregates; modeling a ledger in documents fights the grain of the problem.
- **A separate vector database (Pinecone/Weaviate) for AI memory:** better at massive vector scale, but adds a second system to operate and keep consistent with the facts; pgvector in the same Postgres is the right MVP trade.

## Related

- [ADR-0003](./0003-supabase-as-backend.md), [ADR-0005](./0005-multitenancy-rls.md), [ADR-0006](./0006-event-driven.md), [ADR-0007](./0007-ai-orchestrator.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-002, ADR-006, ADR-009; [../../database/DATABASE_ARCHITECTURE.md](../../database/DATABASE_ARCHITECTURE.md)
