# 11 — Infrastructure — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: INFRASTRUCTURE

---

# Purpose

This chapter states QAYD's infrastructure strategy at product-requirements altitude: how the platform
is deployed, how it stays available and scales, how a release reaches production safely, how the data
is protected against loss, and how the one genuinely hard structural tension — keeping GCC-resident
data inside its jurisdiction while still holding a disaster-recovery copy somewhere safe — is resolved.
It is not a deployment runbook. Every provisioning step, Dockerfile, Helm value, Terraform module,
failover procedure, and RTO/RPO decomposition is owned in depth by the
[`docs/deployment/`](../../deployment/DEPLOYMENT.md) folder, and this chapter routes to those documents
rather than restating them. Where this chapter and a deployment document appear to differ, the
deployment document is authoritative on the detail and this chapter is authoritative only on the
strategy.

The infrastructure exists to serve a specific product: an AI-native, multi-tenant financial operating
system for the GCC on a fixed stack — a Next.js 15 web client, a Laravel 12 (PHP 8.4+) backend that is
the single source of truth, a FastAPI/Python AI engine that never writes the database directly,
PostgreSQL, Redis, object storage, and Laravel Reverb for realtime, with a Flutter mobile client. The
stack is fixed by [`../../foundation/TECH_STACK.md`](../../foundation/TECH_STACK.md) and not
substitutable without an architecture decision record. Two facts about that product shape everything
below: it moves and records money, so data durability and auditability are non-negotiable; and it
serves a region with real data-residency expectations, so *where* the bytes live is a first-class
design constraint rather than an afterthought.

---

# Cloud Topology

QAYD deploys as three stateless, horizontally-scalable application tiers in front of a shared, stateful
data plane. The single most important structural fact is a privilege gradient: traffic flows
`client → web → API → data`, and **only the API tier (and its workers, scheduler, and Reverb) holds
credentials to the database, Redis, and object storage**. The web tier is an unprivileged renderer that
calls the API exactly as a browser would; the AI engine is a peer the backend calls, with no database
access of its own. This is the deployment-time expression of the "one API, many surfaces" rule and of
the security posture in [`10_SECURITY.md`](./10_SECURITY.md): if the web process or the AI engine is
compromised, it holds no data-plane credentials and can only make the same permission-checked API calls
a user could.

| Tier / role | Service | Holds DB creds? | Scales on |
|---|---|---|---|
| Edge | Cloudflare (CDN, WAF, DDoS, TLS) | no | managed |
| Web | Next.js 15 (Node SSR/BFF) | **no** | request latency, CPU |
| API | Laravel 12 (php-fpm) | yes | request latency, CPU |
| Realtime | Laravel Reverb (WebSockets) | Redis only | concurrent connections |
| Workers | Laravel queue workers (per queue) | yes | backlog depth per queue |
| Scheduler | Laravel `schedule:run` | yes | fixed at 1 (singleton) |
| AI | FastAPI (Python) | **no** (calls the API) | request + queue backlog |
| Data | PostgreSQL 15, Redis, object storage (Supabase Storage) | — | vertical + read replica / managed |

The network enforces the same gradient independently of credentials: only the load balancer is
internet-facing, the data subnet accepts connections only from the API tier, workers, scheduler, and
Reverb, and the AI pod may reach only the API tier and its model/OCR provider egress. Development runs
on Hetzner for cost; production runs on AWS; the full topology, Dockerfiles, and network policy are
owned by [`../../deployment/DEPLOYMENT.md`](../../deployment/DEPLOYMENT.md) and the AWS specifics by
[`../../deployment/AWS.md`](../../deployment/AWS.md).

---

# Cloud-Agnostic Posture

QAYD is provider-portable by design. The application is strictly twelve-factor — every backing service
is addressed by a URL or DSN and a credential read from the environment at boot, and nothing about a
provider is compiled in — so moving from AWS to Azure or GCP, or to a self-managed Kubernetes cluster,
is a configuration and provisioning change rather than a code change. The abstract contract each managed
service must satisfy is fixed (stock PostgreSQL 15+ that does not drop row-level security, a
primary-plus-read-replica shape, point-in-time recovery, a KMS-backed secrets manager, persistent
Redis for queue durability, versioned object storage with cross-region replication), and each provider
document maps that contract onto concrete services. The default production target is AWS —
Fargate/ECS for the tiers, RDS for PostgreSQL, ElastiCache for Redis, S3 for storage, Secrets Manager
and KMS for secrets and keys, CloudFront/WAF at the edge — but the portability is deliberate insurance
against vendor lock-in, a stated technology principle. The provider-neutral mapping is owned by
[`../../deployment/CLOUD.md`](../../deployment/CLOUD.md).

---

# Containers, Kubernetes, and CI/CD

Each of the three deployables ships as its own OCI image built from its own Dockerfile; they share
nothing at build time and communicate only over the network. The API image is notable for a discipline
that prevents drift: the *same image* runs as web dyno, queue worker, and scheduler, with only the
container command differing, guaranteeing all three roles run identical code and configuration. Images
run as non-root, are built in CI from pinned bases, are scanned for vulnerabilities, and are referenced
by immutable digest in production. Secrets are injected at deploy time from a vault or secrets manager
as environment or mounted files — never baked into an image, never in the repository, and never in the
browser bundle, which carries only explicitly-public values.

Production orchestration is Kubernetes packaged as a Helm release; the reference single-node target is
docker-compose. The release discipline that matters at PRD altitude is the **migration gate combined
with expand-only migrations**. Every release runs its database migrations as a pre-upgrade gate, and
those migrations must be backward-compatible — add a column, add a nullable field, backfill
asynchronously — so the currently-running old code keeps working against the new schema during a
rolling update, and a rollback is safe without a down-migration because the old code is still
schema-compatible. Destructive "contract" changes happen as a separate later step, after the new code
is fully rolled out. For the common case a rolling update with zero unavailable pods and expand-only
migrations is zero-downtime; for high-risk releases a blue-green cut runs the new color alongside the
old, warms and smoke-tests it, and flips the load-balancer weight as an instantly-reversible change.
CI/CD is GitHub Actions: it builds and tests all three deployables, tags images with the immutable
commit SHA, and deploys via Helm through the migration gate. The Dockerfiles, Helm chart, and pipeline
YAML are owned by [`../../deployment/DEPLOYMENT.md`](../../deployment/DEPLOYMENT.md).

---

# Scaling and Availability

The tiers scale independently because they are stateless and Redis-backed; the data plane scales by a
different discipline. Web and API autoscale on request latency and CPU, with any pod serving any
request because sessions live in Redis. Queue workers autoscale on backlog depth *per queue*, so bulk
AI and reporting work never starves the latency-sensitive realtime queue — the `realtime` queue is
scaled aggressively and kept near-empty, while `ai` and `reports` scale on their own signal. PostgreSQL
scales reads by adding replicas (reporting and the heavy read path route to them) and writes by vertical
capacity on the single primary writer, which the single-writer-ledger design requires. The one
component that must **never** scale beyond a single replica is the scheduler: two schedulers means every
recurring job — recurring invoices, tax-deadline sweeps, approval-SLA sweeps — fires twice, so the chart
hard-pins it to one replica with a leader lock as defense in depth. This scaling model is owned by
[`../../deployment/DEPLOYMENT.md`](../../deployment/DEPLOYMENT.md) and
[`../../deployment/AWS.md`](../../deployment/AWS.md).

---

# Observability

Every deployment target ships the same observability surface, because an operator debugging a
money-affecting incident cannot have a different toolset per environment. Health probes (`live` and
`ready` on the API, a health route on the AI engine and the web tier) gate readiness and drive restarts.
Prometheus scrapes request latency, per-queue backlog, job runtime, database-pool usage, AI-engine call
latency and error rate, Reverb connection count, and web SSR latency; Grafana visualizes them and alerts
fire on queue backlog, error-rate spikes, and probe failures. Sentry receives unhandled exceptions from
all three services, tagged with the request id, the company id (never PII), and the release SHA, so an
error is tied to the exact deployed version. A single `X-Request-Id` propagates client → web → API → AI
engine → queues, so one identifier traces a request across every deployable. Release health is judged on
the four golden signals plus queue backlog during a bake window, and a regression aborts the rollout.
The full observability configuration is owned by
[`../../deployment/DEPLOYMENT.md`](../../deployment/DEPLOYMENT.md).

---

# Backup and Disaster Recovery

Application tiers are cattle — a redeploy from a tagged image plus injected secrets fully reconstitutes
web, API, workers, scheduler, Reverb, and AI — so disaster-recovery planning centers entirely on the
data plane. PostgreSQL uses continuous WAL archiving plus full and differential backups (pgBackRest), a
synchronous same-region standby, and an asynchronous cross-region DR standby, with point-in-time
recovery as the primary restore path. Object storage uses versioning and cross-region replication, and
attachments are immutable once written. Redis holds the queues, and the design leans on idempotency
rather than Redis durability alone: workers' jobs are idempotent and money-affecting jobs carry natural
idempotency keys, so a Redis loss re-drives safely rather than double-posting.

The recovery targets distinguish two failure scopes. An in-region node or availability-zone failure is
the common case and is handled by automatic promotion of the synchronous same-region standby with
effectively zero data loss. A full-region loss is the rare case handled by promoting the asynchronous
cross-region replica; because that link is asynchronous, a bounded amount of the last in-flight
write-ahead log may be lost.

| Scenario | RPO (max data loss) | RTO (max downtime) | Mechanism |
|---|---|---|---|
| Single node / AZ failure (in-region) | ≈ 0 (sync standby) | seconds–minutes | automatic failover |
| Full primary-region loss (cross-region DR) | ≤ 60 seconds of WAL | ≤ 30 minutes | promote DR replica + traffic shift |
| Object storage region loss | ≈ 0 (versioned + replicated) | minutes | serve from the replication target |
| Redis region loss | queue jobs re-drive (idempotent) | minutes | region-local Redis; workers replay |

The full-region RTO is dominated by a deliberate human decision gate before promotion (to avoid
split-brain) plus promotion and traffic-shift time; the DR region is kept warm to keep RTO inside the
30-minute budget. The backup topology, the failover runbook, and the RTO/RPO decomposition are owned by
[`../../deployment/MULTI_REGION.md`](../../deployment/MULTI_REGION.md) and
[`../../database/DATABASE_BACKUP_RECOVERY.md`](../../database/DATABASE_BACKUP_RECOVERY.md).

---

# Multi-Region Topology

QAYD's baseline multi-region posture is active-passive with an asynchronous DR standby: one primary
region serves all writes, and a DR region holds a continuously-updated replica ready to be promoted.
Active-active is a later evolution, gated on residency and on solving cross-region write conflicts for a
single-writer ledger. A global edge health-checks both regions and steers each client to the nearest
healthy one; withdrawing an unhealthy region is the automatic first half of a failover, while promotion
stays a human decision.

Two design choices keep failover clean across regions and are worth stating at product level. Bearer
JWTs are stateless and globally verifiable — every region holds the public verification key, so a
failover does not invalidate in-flight mobile or partner tokens. Web sessions, by contrast, live in
region-local Redis, so a regional failover requires browser users to re-authenticate — an accepted
trade against the complexity and residency risk of replicating sessions across regions, and a rare
event. The scheduler remains the sharpest hazard: exactly one may run globally, so it runs only in the
region holding the PostgreSQL writer, and promotion of the DR region is what enables its scheduler. This
topology is owned by [`../../deployment/MULTI_REGION.md`](../../deployment/MULTI_REGION.md).

---

# The GCC-Residency vs. DR-Region Tension

This is the one infrastructure decision where two hard requirements pull in opposite directions, and it
deserves to be named rather than buried in a runbook. Resilience wants the disaster-recovery copy *far
away* — a second region unlikely to share a failure with the primary. Residency wants the data to
*never leave the jurisdiction* — for a residency-bound GCC or Kuwait tenant, every region that holds its
data must be inside the permitted jurisdiction. The concrete tension is that the specified primary
region is `me-south-1` (Bahrain, inside the GCC) while the default DR standby is `eu-central-1`
(Frankfurt, outside it): a Frankfurt DR copy is excellent for resilience and, for a residency-bound
tenant, a direct violation of the residency promise the security model makes in
[`10_SECURITY.md`](./10_SECURITY.md) and [`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md).

QAYD resolves this not with a single global answer but by splitting on tenant class, and the resolution
is tracked as the blocking decision **CMP-2** in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md):

- **Residency-bound tenants** get GCC-only disaster recovery — a second permitted region such as
  `me-central-1` (UAE) — *or*, where a second in-jurisdiction region is unavailable, a
  **ciphertext-only cross-region copy whose field-encryption keys live exclusively in a permitted-region
  KMS**. In the second case the DR bytes may physically sit outside the jurisdiction but are
  unreadable there, because the keys never leave the permitted region — so residency holds even though
  bytes left. This is exactly what the per-tenant key hierarchy in
  [`10_SECURITY.md`](./10_SECURITY.md) is designed to enable.
- **Non-residency-bound tenants** may use the cheaper `eu-central-1` DR pairing directly.

The remaining decisions this tension touches are also open and tracked in
[`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md): whether to stand up cross-region DR at launch at all or
run single-region with region-pinned, tested backups first (**OPS-1**, recommendation: launch
single-region in `me-south-1` and add the passive DR region once a residency-bound enterprise customer
justifies the second-region cost, keeping the failover runbook ready so DR is a provisioning step, not
a redesign); whether staging should mirror production on AWS rather than reusing the Hetzner
development environment, so residency and managed-service behavior are validated where they ship
(**OPS-2**); and where in-region AI inference is available for residency-bound tenants when frontier
model providers may not offer a GCC endpoint (**AI-2**, coupled to CMP-2). The residency rules
themselves — that residency is a fail-closed Terraform-enforced property, that backups inherit the
jurisdiction filter, and that cleartext processing including the AI engine runs in-region — are owned by
[`../../deployment/MULTI_REGION.md`](../../deployment/MULTI_REGION.md) and
[`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md). The sales and legal promise must
match the infrastructure; the per-class split exists so that it does.

---

## Related Documents

- [`../../deployment/DEPLOYMENT.md`](../../deployment/DEPLOYMENT.md) — topology, containers, the migration gate, scaling, observability, and CI/CD in depth.
- [`../../deployment/CLOUD.md`](../../deployment/CLOUD.md) — the cloud-agnostic managed-service mapping and contracts.
- [`../../deployment/AWS.md`](../../deployment/AWS.md) — the default production target (ECS/Fargate, RDS, ElastiCache, S3, Secrets Manager/KMS, CloudFront/WAF).
- [`../../deployment/MULTI_REGION.md`](../../deployment/MULTI_REGION.md) — active-passive topology, failover runbook, RTO/RPO, and the residency-vs-DR resolution.
- [`../../database/DATABASE_BACKUP_RECOVERY.md`](../../database/DATABASE_BACKUP_RECOVERY.md) — the database-tier backup and recovery decomposition.
- [`../../foundation/TECH_STACK.md`](../../foundation/TECH_STACK.md), [`../../foundation/SYSTEM_ARCHITECTURE.md`](../../foundation/SYSTEM_ARCHITECTURE.md) — the fixed stack and system architecture the infrastructure serves.
- [`10_SECURITY.md`](./10_SECURITY.md) — the security posture, residency requirement, and per-tenant key hierarchy this chapter builds on.
- [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md) — open items CMP-2 (residency vs DR region, blocking), OPS-1 (single- vs multi-region at launch), OPS-2 (Hetzner dev / AWS staging), AI-2 (in-region inference).

# End of Document
