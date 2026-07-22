# Multi-Region & High Availability — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: MULTI_REGION
---

# Purpose

This document specifies how QAYD runs across **more than one region** for high availability, disaster
recovery, and data residency. It extends the single-region topology of [DEPLOYMENT.md](./DEPLOYMENT.md)
and the managed-cloud mapping of [CLOUD.md](./CLOUD.md) into a multi-region posture: how the three
deployables (Laravel 12 API, Next.js 15 web, FastAPI AI engine) and the data plane (PostgreSQL 15,
Redis, Laravel Reverb, object storage, queue workers) are distributed, how PostgreSQL replicates and
fails over across regions, how sessions and JWTs behave when a request lands in a different region,
where WebSocket/Reverb traffic is anchored, and what the recovery-time and recovery-point targets are
when an entire region is lost. Where [DEPLOYMENT.md](./DEPLOYMENT.md) and
[../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md) describe backup and
in-region HA, this document owns the **cross-region** story and the **failover runbook**.

Multi-region exists for two distinct reasons that must not be conflated. The first is **resilience**:
an entire cloud region can fail, and QAYD — a system of record for money — must not lose posted
financial facts and must return to service within a bounded time. The second is **data residency**: a
GCC/Kuwait customer's financial data and PII may be legally required to remain within a permitted
jurisdiction, which forces *where* a region may be and forbids replicating cleartext outside it. These
two goals sometimes pull in opposite directions — resilience wants data copied far away, residency
forbids it leaving — and this document resolves that tension explicitly, tying every residency claim to
[../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md).

The stack facts are fixed and do not relax across regions: PostgreSQL Row-Level Security remains the
tenant boundary of last resort ([../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md)), the
web tier stays unprivileged, secrets come from a per-region vault, and the `/api/v1` contract is
identical in every region. A second region is more infrastructure, never a second security model.

The audience is the SRE who must execute a regional failover under pressure, the architect choosing
active-passive vs active-active, and the platform engineer configuring residency-pinned regions.

# Topology / Architecture

QAYD's baseline multi-region posture is **active-passive with an async DR standby**, matching the
database design (per [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md):
primary region `me-south-1`, async DR standby in `eu-central-1`, Patroni-driven promotion). A primary
region serves all writes; a DR region holds a continuously-updated replica and stands ready to be
promoted. Active-active is a later evolution discussed below, gated on residency and on solving
cross-region write conflicts for a single-writer ledger.

```
        ┌──────────────────────── Global edge (Cloudflare) ───────────────────────┐
        │        latency / health-based routing → healthy region                  │
        └───────────────────────────────┬─────────────────────────────────────────┘
                        ┌────────────────┴────────────────┐
                        ▼                                  ▼
        ══════════ PRIMARY REGION (active) ══════   ═════ DR REGION (passive) ═════
        │  LB → web / api / ai / reverb        │   │  LB → web / api / ai (warm/  │
        │  workers + scheduler (ACTIVE)        │   │  minimal); scheduler OFF     │
        │                                      │   │                              │
        │  PostgreSQL PRIMARY (writer)         │   │  PostgreSQL replica          │
        │      │ sync standby (same-region HA) │   │   (async, read-only,         │
        │      │                               │──▶│    streaming from primary)   │
        │  Redis (region-local)                │   │  Redis (region-local, empty  │
        │  Object storage (primary bucket) ────┼──▶│  Object storage (CRR target) │
        │  Vault (region-local secrets)        │   │  Vault (region-local secrets)│
        ═══════════════════════════════════════   ═══════════════════════════════
                   in-region HA: Patroni sync-standby failover
                   cross-region DR: promote DR replica → new primary
```

Two failure scopes, two mechanisms:

- **In-region HA** (a node or AZ fails): Patroni promotes the **synchronous same-region standby** with
  no data loss (RPO ≈ 0). This is the common case and is owned by
  [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md); this document
  builds on it.
- **Full-region DR** (the whole primary region is lost): promote the **async cross-region replica** in
  the DR region and shift traffic there. Because the cross-region link is async, a bounded amount of
  the last in-flight WAL may be lost — the RPO target below quantifies it.

# Prerequisites

| Requirement | Why |
|---|---|
| Two (or more) regions provisioned via Terraform | one active, one DR; identical service shapes |
| Cross-region PostgreSQL streaming replication | async standby continuously receiving WAL |
| Object storage cross-region replication (CRR) | attachments available in the DR region |
| Per-region vault/secrets manager | secrets never cross a residency boundary |
| Global edge with health/latency routing | to steer clients to the healthy/nearest region |
| Region-pinned KMS keys | field-KEK roots stay in-region for residency |
| A documented, rehearsed failover runbook | DR is only real if it has been drilled |

Residency prerequisite: for a residency-bound tenant, **both** regions must be inside the permitted
jurisdiction, or the DR region must hold only encrypted-at-rest data whose keys never leave the
permitted region (see [Data residency](#data-residency-gcc--kuwait)).

# Components & Services

Each region runs a full copy of the application tiers; the data plane differs by role (writer vs
replica).

| Component | Primary region | DR region |
|---|---|---|
| Web / API / AI / Reverb | active, autoscaled | warm at minimal replicas (or scale-from-cold within RTO) |
| Queue workers | active | idle/minimal until promotion |
| Scheduler | **running (singleton)** | **stopped** — must never run in two regions at once |
| PostgreSQL | primary writer + sync standby | async streaming replica (read-only) |
| Redis | region-local, live | region-local, empty until promotion |
| Object storage | primary bucket | CRR replication target |
| Vault / KMS | region-local secrets + keys | region-local secrets + keys |

The scheduler is the sharpest multi-region hazard: exactly one scheduler may run globally, or recurring
jobs (recurring invoices, tax-deadline sweeps, approval-SLA sweeps) fire twice. The scheduler runs only
in the region holding the PostgreSQL writer; promotion of the DR region is what *enables* its scheduler,
and demotion of the old primary disables its own. A leader lock keyed on the writer's identity is the
defense in depth.

# Configuration & Secrets

Secrets are **per-region**, never shared across a residency boundary. Each region's vault holds its own
copy of the [DEPLOYMENT.md](./DEPLOYMENT.md) secret set; the database credentials differ per region
(each points at its region-local Postgres endpoint), and the field-KEK root lives in that region's KMS.

```hcl
# infrastructure/terraform/envs/production/regions.tf
module "region_primary" {
  source          = "../../modules/qayd-region"
  region          = "me-south-1"          # residency-permitted primary
  role            = "active"
  pg_role         = "primary"
  scheduler       = true
}
module "region_dr" {
  source          = "../../modules/qayd-region"
  region          = "eu-central-1"        # DR — see residency note below
  role            = "passive"
  pg_role         = "replica"
  pg_upstream     = module.region_primary.pg_writer_endpoint
  scheduler       = false                 # OFF until promotion
}
```

The application is region-agnostic: it reads `DB_HOST`, `REDIS_HOST`, `R2_ENDPOINT`, and the vault
references from its region's environment. A pod does not know or care which region it is in; the
Terraform per-region module supplies the region-local endpoints. This is what makes a failover a
data-plane promotion plus a traffic shift, not an application change.

# Step-by-Step / Runbook

## Active-passive vs active-active

| Dimension | Active-passive (baseline) | Active-active (future) |
|---|---|---|
| Writes | one region (primary) | requires conflict resolution or region-sharded tenants |
| Reads | primary; DR read-only | either region |
| Failover | promote DR, shift traffic (minutes) | traffic already in both; DR is instant |
| Complexity | moderate | high — the ledger is single-writer per tenant |
| Cost | DR warm/minimal | full capacity in every region |
| Residency fit | clean (data pinned to primary) | clean only if tenants are region-sharded |
| QAYD default | **yes** | evaluated per residency + scale need |

QAYD chooses active-passive because the ledger is a single-writer system of record: a journal entry
must post against one authoritative primary so double-entry balance and invoice-number allocation are
serialized. True active-active for the *same tenant* would require multi-master write conflict
resolution, which is unacceptable for money. The viable active-active shape is **tenant-sharded by
region** — each company's writer lives in exactly one region (its residency region), and QAYD runs
active in several regions each of which is primary for its own tenants and DR for another's. That is the
long-term direction for a globally distributed customer base; the mechanics below (promotion, routing,
runbook) are the building blocks it reuses.

## Cross-region PostgreSQL replication & failover

The DR replica streams WAL asynchronously from the primary writer. Promotion turns the replica into a
writer.

```bash
# --- Normal state: DR replica follows the primary (async streaming) ---
# On the DR replica, recovery/standby config points upstream:
#   primary_conninfo = 'host=<primary-writer> application_name=dr_eu'
#   (async: NOT in synchronous_standby_names, so primary never blocks on it)

# --- FAILOVER: primary region is lost. Promote the DR replica. ---
# 1. Confirm the primary is truly gone (avoid split-brain) — see runbook gate below.
# 2. Promote the DR replica to a writer:
pg_ctl promote -D "$PGDATA"           # or: SELECT pg_promote();  (Patroni: patronictl failover)

# 3. Point the DR region's app env at its now-writable local Postgres (already local — no change),
#    enable the DR region's scheduler, and scale its workers up.
kubectl --context dr -n qayd scale deploy/qayd-scheduler --replicas=1
kubectl --context dr -n qayd scale deploy/qayd-worker-default --replicas=6

# 4. Shift global traffic to the DR region (edge health/routing already steering, or force it):
scripts/failover-traffic.sh --to eu-central-1
```

The single most dangerous multi-region failure is **split-brain**: promoting the DR while the old
primary is actually alive, producing two writers and a forked ledger. The runbook gate below makes
promotion a fenced, one-way decision.

## Failover runbook (full-region DR)

```
PRECONDITION: primary region (me-south-1) confirmed unavailable to clients.

[G0] DECLARE. Incident commander declares a regional DR event. Only the IC authorizes promotion.

[G1] FENCE THE OLD PRIMARY (anti-split-brain).
     - Cut the old primary's traffic at the edge (remove me-south-1 from routing).
     - If any old-primary node is still reachable, demote/stop it and revoke its writer role.
     - Do NOT proceed to promotion until the old writer cannot accept writes.

[G2] CHECK REPLICATION LAG on the DR replica (bounds the data loss you are accepting):
       SELECT now() - pg_last_xact_replay_timestamp();     -- expect < RPO target
     Record the lag; it is the RPO you are realizing this event.

[G3] PROMOTE the DR replica → writer (patronictl failover / pg_promote).

[G4] ACTIVATE the DR region:
     - Enable the DR scheduler (exactly one scheduler now runs, in DR).
     - Scale DR workers and API/web/ai to serve full load.
     - Verify object storage (CRR target) is readable; attachments present.

[G5] SHIFT TRAFFIC. Point the global edge fully at the DR region; verify /health/ready and a
     canary /api/v1 write (post a test journal entry in a scratch company, then reverse it).

[G6] VERIFY INTEGRITY. Run the tenant-isolation and balance sanity checks; confirm no
     duplicate scheduled-job firing; confirm queues draining.

[G7] COMMUNICATE. Update status; record the realized RPO (from G2) and RTO (G0→G5 elapsed).

RECOVERY (later, unhurried): rebuild the failed region as a NEW async replica of the promoted
writer; once caught up, optionally fail back during a maintenance window by repeating G1–G5 in
reverse. Failback is planned, never rushed.
```

## RTO / RPO targets

These are the deployment-level targets; the database-tier decomposition is in
[../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md).

| Scenario | RPO (max data loss) | RTO (max downtime) | Mechanism |
|---|---|---|---|
| Single node / AZ failure (in-region) | ≈ 0 (sync standby) | seconds–minutes | Patroni automatic failover |
| Full primary-region loss (cross-region DR) | ≤ 60 seconds of WAL (async lag) | ≤ 30 minutes | promote DR replica + traffic shift |
| Object storage region loss | ≈ 0 (versioned + CRR) | minutes | serve from CRR target bucket |
| Redis region loss | queue jobs re-drive (idempotent) | minutes | region-local Redis; workers replay |

The cross-region RPO is bounded by replication lag, which the async design keeps small (WAL segments
ship continuously; `archive_timeout` caps the worst case). The cross-region RTO is dominated by the
human decision gate (G0–G1) plus promotion and traffic-shift time; the DR region is kept warm precisely
to keep RTO inside the 30-minute target. Money-affecting jobs are idempotent, so a re-drive after
failover never double-posts.

# Scaling

Multi-region scaling is per-region autoscaling (as in [CLOUD.md](./CLOUD.md)) plus a capacity decision
for the DR region:

- **Warm DR** (default): DR runs at a minimal replica floor and scales up during a failover. Cheaper;
  adds a few minutes of scale-up to RTO, which the 30-minute budget absorbs.
- **Hot DR** (for the tightest RTO): DR runs near primary capacity continuously. More expensive; used
  only for tenants contractually requiring near-instant failover.
- **PostgreSQL** in DR must be sized to take the full write load after promotion; it is not merely a
  read replica scaled for reporting. Provision the DR writer for primary-equivalent throughput even
  while it is only replaying.

Active-active (tenant-sharded) scaling is different again: each region autoscales for its own tenants'
load and holds reserve for the tenants it is DR for.

# Networking

Multi-region networking adds a **global routing layer** above the per-region [CLOUD.md](./CLOUD.md)
topology.

```
                 Client
                   │
      ┌────────────┴─────────────┐
      │  Global edge (Cloudflare)│  health-check both regions;
      │  latency + health route  │  steer to nearest HEALTHY region
      └──────┬──────────────┬────┘
             ▼              ▼
      Region A LB      Region B LB
        (active)         (DR)
             │              │
   per-region private topology (as in CLOUD.md): data plane private, no public endpoints
```

- **Latency routing** sends each client to the nearest healthy region for read latency; during normal
  active-passive operation, writes still land on the primary — the DR region proxies writes to the
  primary or (simpler) the edge routes all traffic to the active region and uses DR only on failover.
- **Health-based failover routing**: the edge health-checks each region's `/health/ready`; when the
  primary fails checks, routing withdraws it, which is the automatic first half of a failover (the
  promotion gate is still a human decision to avoid split-brain).
- **Cross-region data-plane traffic** (WAL streaming, CRR) travels over the providers' private
  backbone/peering, encrypted in transit; it never traverses the public internet in cleartext.
- **Residency-bound tenants** are pinned so their traffic and data never route to a region outside the
  permitted jurisdiction, even under failover — a residency tenant fails over only to a residency-valid
  region.

# Data residency (GCC / Kuwait)

Residency is the constraint that most shapes QAYD's multi-region design, and it is governed by
[../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md). The rules that bind this document:

- **Region choice is a residency decision, not just a latency one.** For a tenant whose regulatory or
  contractual posture requires GCC/Kuwait residency, every region that holds its data — primary *and*
  DR — must be inside the permitted jurisdiction, or must hold only ciphertext whose field-KEKs live in
  a permitted-region KMS ([../security/ENCRYPTION.md](../security/ENCRYPTION.md)). Residency is a
  Terraform-enforced infrastructure property, fail-closed: provisioning a plaintext store outside the
  permitted region fails the infra policy check the same way an unencrypted volume does
  ([../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md)).
- **Processing stays in-region.** Anything that touches cleartext — including the AI engine's
  per-request handling — runs in the permitted region, so PII does not transit out of the jurisdiction
  to be reasoned over. A residency tenant's AI engine and its cleartext caches are region-pinned.
- **DR must not break residency.** The default DR pairing (`me-south-1` → `eu-central-1`) is valid only
  for tenants *without* a strict-in-region requirement. For strict-residency tenants, the DR region
  must itself be residency-valid (e.g. a second GCC region), or the cross-region copy must be
  ciphertext-only with keys held exclusively in the permitted region. The residency configuration is
  per-tenant metadata that the provisioning layer resolves into which regions may hold that tenant's
  data.
- **Backups inherit residency.** Cross-region backup copies (pgBackRest repo2, object-storage CRR)
  target only residency-valid regions for a residency-bound tenant; the backup posture in
  [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md) is filtered by the
  same jurisdiction rule.

The tension between resilience (copy far away) and residency (do not leave the jurisdiction) is resolved
by preferring **a second region inside the jurisdiction** where one exists, and falling back to
**ciphertext-only cross-region replication with in-jurisdiction keys** where it does not — so the DR
copy is unreadable outside the permitted region and residency holds even though bytes physically left.

# Session & JWT considerations across regions

When a request can land in either region, session and token handling must not assume a single region.
QAYD's design makes this clean:

- **JWTs are stateless and globally verifiable.** Mobile/partner/AI callers use RS256 JWTs signed by a
  key whose **public** half is distributed to every region ([../security/SECRETS.md](../security/SECRETS.md));
  any region verifies a token without a shared session store. A failover therefore does not invalidate
  in-flight tokens — the DR region verifies them with the same public key. Key rotation (90-day, 14-day
  verify-only overlap) is coordinated so both regions accept both keys during the overlap.
- **Web sessions live in region-local Redis.** The Next.js/Sanctum web session is httpOnly-cookie-issued
  and backed by Redis. In active-passive, all web traffic is served by the active region, so sessions
  are region-local and consistent. On failover, the DR region's Redis is empty, so **active web sessions
  are not preserved across a regional failover** — users re-authenticate. This is an accepted trade:
  a regional DR event is rare and re-login is a small cost against the complexity and residency risk of
  cross-region session replication. (Tokens, used by mobile/API, *do* survive — only browser sessions
  re-auth.)
- **No cross-region sticky requirement for the API.** Any API pod in the active region serves any
  request (Redis-backed, stateless), exactly as in [DEPLOYMENT.md](./DEPLOYMENT.md); regionality adds
  nothing sticky at the HTTP layer.
- **Idempotency keys are honored per writer.** The offline/replay `Idempotency-Key` de-dupes against the
  primary writer; after promotion the new writer continues honoring them from the replicated state, so a
  client retry that spans a failover does not double-apply.

# Reverb / WebSocket regionalization

Realtime is region-anchored. Reverb holds live WebSocket connections and reads broadcast payloads from
region-local Redis; a socket is inherently tied to the region that terminates it.

- **Connections anchor to the active region.** Clients open their WebSocket to `ws.qayd.io`, which the
  edge routes to the active region's Reverb. Broadcasts originate from the active region's backend
  (the writer's region), so producer and consumer are co-located — no cross-region broadcast bus is
  needed in active-passive.
- **Failover drops and reconnects sockets.** When traffic shifts to the DR region, existing WebSocket
  connections to the lost region break; clients reconnect (with backoff) to the DR region's Reverb and
  re-subscribe to their company-scoped private channels. Channel authorization re-runs the same RBAC
  checks in the DR region ([../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)), so
  reconnection is not a bypass. Missed events during the reconnect window are recovered by the client
  re-fetching current state through the permission-checked API — realtime is a refresh hint, never the
  source of truth.
- **Active-active (tenant-sharded) regionalizes Reverb by tenant.** Each company's realtime traffic
  lives in its home region alongside its writer; a client subscribing to a company's channel is routed
  to that company's region. No global fan-out of a single company's events across regions is required,
  which keeps the private-channel isolation model intact.

The design principle throughout: realtime never carries authoritative state, so a regional realtime
interruption is a reconnect-and-refetch, never a data-loss event.

# Observability

Multi-region observability adds cross-region health and replication signals to the
[CLOUD.md](./CLOUD.md) surface:

- **Per-region health** on `/health/ready` feeds the edge's failover routing; a failing region is
  withdrawn automatically.
- **Replication lag** (`pg_last_xact_replay_timestamp()` delta) is scraped and alerted — it is the live
  RPO, and an alert fires well before it approaches the target.
- **CRR lag** on object storage is monitored so a failover never finds missing recent attachments.
- **Split-brain guard** alarms if two writers are ever observed (two regions reporting a writable
  Postgres), which must page immediately.
- **Failover drills** are themselves observable: each rehearsal records realized RPO/RTO against target,
  and a drill that misses target is a defect to fix, not a number to accept.

# Backup & DR

This document is the DR half of the deployment story; the database mechanics it rests on are
[../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md):

- Cross-region async streaming replica is the promotion target; pgBackRest repo2 in a second region is
  the independent restore path if replication itself is compromised.
- Object storage CRR gives the DR region the attachments; versioning protects against corruption.
- Redis is region-local; queue durability + idempotent jobs make its loss a re-drive, not a data loss.
- The failover runbook above is the tested procedure; **DR is only real once rehearsed**, so failover
  drills run on a schedule and their measured RPO/RTO are the source of truth for the targets table.

# Security

Multi-region preserves every single-region control and adds cross-region ones:

- **RLS everywhere.** Every region's Postgres enforces Row-Level Security; the app role is
  `NOBYPASSRLS` in the DR region exactly as in the primary
  ([../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md)). A promoted DR writer is not a
  weaker tenant boundary.
- **Per-region secrets and keys.** No secret or field-KEK crosses a residency boundary; each region's
  vault/KMS is independent ([../security/SECRETS.md](../security/SECRETS.md)).
- **Encrypted cross-region traffic.** WAL streaming and CRR run encrypted over private backbone; a
  residency tenant's cross-region copy is ciphertext-only with in-jurisdiction keys.
- **Fenced failover.** The anti-split-brain gate (runbook G1) is a security control as much as a
  correctness one — two writers would fork the ledger.
- **Least privilege on promotion.** Promotion uses the migration/admin role, not the runtime app role;
  the runtime role never gains DDL or promotion rights.

# Related Documents

- [DEPLOYMENT.md](./DEPLOYMENT.md) — single-region topology, containerization, release model.
- [CLOUD.md](./CLOUD.md) — managed-cloud mapping this extends across regions.
- [SELF_HOSTING.md](./SELF_HOSTING.md) — single-node path (no cross-region story).
- [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md) — replication, backup, in-region HA, RPO/RTO decomposition.
- [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md) — data residency (GCC/Kuwait), classification, retention.
- [../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md), [../security/SECRETS.md](../security/SECRETS.md), [../security/ENCRYPTION.md](../security/ENCRYPTION.md)
- [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md) — realtime channels, request lifecycle, health probes.

# End of Document
