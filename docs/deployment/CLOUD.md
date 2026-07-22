# Cloud-Agnostic Managed Deployment — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: CLOUD
---

# Purpose

This document specifies how QAYD is deployed on a **managed cloud** in a provider-neutral way: how each
of the platform's components maps to a managed service equivalent, what the 12-factor posture that makes
this possible looks like, and where the trade-offs between managed and self-managed land. It is the
cloud specialization of [DEPLOYMENT.md](./DEPLOYMENT.md) — the same three deployables (Laravel 12 API,
Next.js 15 web, FastAPI AI engine) and the same data plane (PostgreSQL 15, Redis, Laravel Reverb,
object storage, queue workers) — expressed as managed building blocks rather than self-run containers.
It is deliberately **provider-neutral**: it defines the *shape* and the *contract* each managed service
must satisfy, so that the per-provider documents (`AWS.md`, `AZURE.md`, `GCP.md`) can specialize it by
naming the concrete service without re-deriving the architecture. Where a per-provider document is
silent, this document governs.

QAYD's authoritative hosting target is AWS behind Cloudflare (per
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md): "Production — AWS, Multi-region
architecture"), but nothing in the application binds to AWS. The backend reads a PostgreSQL DSN, a Redis
URL, an S3-compatible endpoint, and a set of secrets from the environment; whether those resolve to
Amazon RDS or Azure Database for PostgreSQL, to ElastiCache or Memorystore, to S3 or R2, is a
deployment concern, not a code concern. That portability is a stated technology principle
("Vendor lock-in should be minimized") and this document is where it is cashed out.

This document does not restate the production topology or the release/migration model — those are
[DEPLOYMENT.md](./DEPLOYMENT.md). It does not cover the on-premises single-node path — that is
[SELF_HOSTING.md](./SELF_HOSTING.md). It covers the managed-cloud mapping, the autoscaling posture, the
cost drivers, and the managed-vs-self-managed decision for each component.

The audience is the platform engineer choosing managed services for a new region or provider, the one
writing the Terraform that provisions them, and the finance-minded engineer reasoning about what drives
the cloud bill.

# Topology / Architecture

On a managed cloud, the three stateless application tiers run on a **managed container platform** and
the data plane is a set of **managed data services**. The topology is identical to
[DEPLOYMENT.md](./DEPLOYMENT.md); only the provider of each box changes.

```
                         Internet
                            │
                ┌───────────┴───────────┐
                │  CDN + WAF + DDoS      │  (Cloudflare / provider edge)
                └───────────┬───────────┘
                            ▼
                   ┌────────────────┐
                   │ Managed L7 LB /│
                   │ Ingress        │
                   └───┬────────┬───┘
             ┌─────────┘        └─────────┐
             ▼                            ▼
   ┌───────────────────┐        ┌───────────────────┐
   │ Managed container │/api/v1▶│ Managed container │
   │ platform: WEB     │        │ platform: API     │──┐
   │ (Next.js, Node)   │        │ (Laravel, php-fpm)│  │ HTTP + ai queue
   └───────────────────┘        └───┬───────────┬───┘  │
             ▲                       │           │      ▼
             │ browser               │           │   ┌───────────────────┐
             │ (NEXT_PUBLIC_* only)  │           │   │ Managed container │
             │                       │           │   │ platform: AI      │
   ┌─────────┴─────────┐             │           │   │ (FastAPI, Python) │
   │ Managed WS ingress│◀── Reverb ──┘           │   └─────────┬─────────┘
   └───────────────────┘   (managed container)   │             │ internal /api/v1
                                                 │             ▼
        ┌────────────────────────────────────────┴──────────────────────────┐
        │                       MANAGED DATA PLANE                            │
        │                                                                     │
        │  Managed PostgreSQL 15     Managed Redis        Managed object      │
        │  (primary + read replica,  (cache/queue/         storage (S3 API)   │
        │   automated backups, PITR)  session/locks)                          │
        │                                                                     │
        │  Managed secrets manager        Container-platform autoscaling      │
        │  (KMS-backed)                   (HPA / service scaling)             │
        └─────────────────────────────────────────────────────────────────────┘
```

The privilege gradient is unchanged: the managed load balancer is the only internet-facing entry, the
web tier calls `/api/v1` with no data-plane credentials, and only the API tier (plus workers, scheduler,
Reverb) holds the managed database, cache, and object-storage credentials — which now come from the
**managed secrets manager** rather than a file.

# Prerequisites

Before a managed deployment, the provider account must offer — and the team must have provisioned via
Terraform — a service satisfying each contract in [Components & Services](#components--services). At
minimum:

| Requirement | Contract it must meet |
|---|---|
| Managed container runtime | run OCI images, per-service scaling, health-probe integration, rolling deploys |
| Managed PostgreSQL 15 | RLS support (stock Postgres), read replica, automated backup + PITR, in-region |
| Managed Redis | persistence for queues, in-VPC, auth/TLS |
| S3-compatible object storage | versioning, cross-region replication, server-side encryption |
| Managed secrets manager | KMS-backed, IAM-scoped, injectable into containers |
| Managed load balancer + WebSocket support | L7 routing, TLS, sticky-less WS pass-through |
| CDN + WAF | edge caching for the web bundle, WAF rules, DDoS protection |
| KMS | envelope-encryption root for field-KEKs and at-rest keys |

The Terraform that provisions these lives in `infrastructure/terraform/` per
[../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md); this document defines the
resource *shapes*, the per-provider docs fill in the concrete resource types.

# Components & Services

## The mapping table

Each QAYD component maps to a managed equivalent. The application is unchanged across the row; only the
provisioned service differs.

| QAYD component | Self-managed (baseline) | Managed equivalent (provider-neutral) | AWS | Azure | GCP |
|---|---|---|---|---|---|
| Web / API / AI runtime | Kubernetes you run | managed container platform | EKS / ECS Fargate / App Runner | AKS / Container Apps | GKE / Cloud Run |
| PostgreSQL 15 | self-run Postgres | managed PostgreSQL | RDS / Aurora PostgreSQL | Database for PostgreSQL Flexible | Cloud SQL / AlloyDB |
| Redis | self-run Redis | managed Redis | ElastiCache | Azure Cache for Redis | Memorystore |
| Object storage | MinIO | S3-compatible object store | S3 | Blob Storage | Cloud Storage |
| Laravel Reverb | self-run container | container on the same platform + managed WS LB | ECS/EKS + ALB | Container Apps + AGW | Cloud Run/GKE + LB |
| Queue workers | self-run containers | container jobs, autoscaled on backlog | ECS/EKS + KEDA | Container Apps (KEDA-native) | Cloud Run jobs / GKE + KEDA |
| Scheduler | self-run singleton | single-replica container or managed cron | ECS scheduled task | Container Apps job (cron) | Cloud Scheduler → job |
| Secrets | `.env` file | managed secrets manager | Secrets Manager / SSM | Key Vault | Secret Manager |
| Encryption root | local key | managed KMS | KMS | Key Vault (keys) | Cloud KMS |
| CDN / WAF / DNS | Cloudflare | provider edge or Cloudflare | CloudFront + WAF | Front Door | Cloud CDN + Armor |
| Email / SMS | own SMTP | managed delivery | SES / Mailgun | Comm. Services | — / Mailgun |
| Metrics / errors | own Prom/Grafana | managed observability + Sentry | CloudWatch/Managed Prometheus | Monitor | Cloud Monitoring |

The rows the per-provider documents specialize are the middle "managed equivalent" column; the three
right columns are illustrative and non-exhaustive.

## Contracts each managed service must satisfy

- **Managed PostgreSQL.** Must be stock PostgreSQL 15+ (not a fork that drops RLS), expose a primary
  writer and at least one read replica endpoint, support automated backups with point-in-time recovery,
  and live in the required region for residency ([../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md)).
  The application role is `NOBYPASSRLS`; the managed superuser is used only for provisioning, never at
  runtime. Connection pooling (PgBouncer or the provider's pooler) sits in front in transaction mode.
- **Managed Redis.** Must support persistence (so queued jobs survive a failover), in-VPC private
  networking, and auth+TLS. Cache and queue may share one instance with key prefixes or use separate
  instances; queue durability is the harder requirement.
- **S3-compatible object storage.** Must support object versioning (immutable attachments),
  server-side encryption with a KMS key, and cross-region replication for DR
  ([MULTI_REGION.md](./MULTI_REGION.md)). The API tier holds scoped credentials via the secrets manager.
- **Managed secrets manager.** Must be KMS-backed and injectable into the container platform as env or
  mounted files at start, with IAM scoping so each service reads only its own secrets
  ([../security/SECRETS.md](../security/SECRETS.md)). This replaces the self-host `.env` entirely.
- **Managed container platform.** Must run the same OCI images from [DEPLOYMENT.md](./DEPLOYMENT.md),
  support per-service replica counts and autoscaling, integrate the `/health/ready` and `/health/live`
  probes into traffic gating, and run rolling deploys with `maxUnavailable: 0`.

# Configuration & Secrets

The 12-factor posture is what makes the mapping above a configuration change rather than a code change.
Every backing service is addressed by a URL/DSN and a credential read from the environment at boot;
nothing about the provider is compiled in.

```hcl
# infrastructure/terraform/modules/qayd-env/variables.tf  (provider-neutral surface)
variable "region"            { type = string }   # residency-pinned
variable "db_instance_class" { type = string }
variable "redis_node_type"   { type = string }
variable "object_bucket"     { type = string }
variable "container_min"     { type = map(number) }  # per service floor
variable "container_max"     { type = map(number) }  # per service ceiling
```

The secrets manager holds exactly the [DEPLOYMENT.md](./DEPLOYMENT.md) secret set; the container
platform injects them. The `NEXT_PUBLIC_*` boundary is unchanged and is doubly enforced in the cloud:
the web build receives only public values, and the secrets manager IAM policy does not even grant the
web service read access to any server secret, so a misconfigured build cannot pull one.

```hcl
# each service gets a narrowly-scoped read policy — web can read NONE of the data-plane secrets
resource "secret_access_binding" "web" {
  service = "qayd-web"
  secrets = []                                  # web holds no server secrets
}
resource "secret_access_binding" "api" {
  service = "qayd-api"
  secrets = ["db", "redis", "reverb", "object", "ai_service_key", "mail", "sms"]
}
resource "secret_access_binding" "ai" {
  service = "qayd-ai"
  secrets = ["llm_provider_key", "ocr_provider_key", "internal_api_url"]  # no DB
}
```

Config is cached in the API image at deploy (`php artisan config:cache`), so the container reads env
only at boot; a secret rotation is a rolling restart, which the container platform does with zero
downtime.

# Step-by-Step / Runbook

Provisioning and deploying to a managed cloud is Terraform for the data plane, then the same Helm/CD
release from [DEPLOYMENT.md](./DEPLOYMENT.md) against the managed cluster.

```bash
# 1. Provision the managed data plane (provider module chosen by backend config)
cd infrastructure/terraform/envs/production
terraform init
terraform plan  -var-file=production.tfvars
terraform apply -var-file=production.tfvars
#   creates: managed Postgres (primary+replica), managed Redis, object bucket,
#            secrets manager entries, KMS keys, container platform, LB, CDN/WAF

# 2. Load secrets into the managed secrets manager (values from the vault, never the repo)
scripts/seed-secrets.sh --env production        # writes db/redis/reverb/ai keys

# 3. Point the deploy at the managed cluster and release (migration gate runs first)
helm upgrade --install qayd ./helm/qayd \
  --namespace qayd --create-namespace \
  --values helm/qayd/values.production.yaml \
  --set image.api.tag=$SHA --set image.web.tag=$SHA --set image.ai.tag=$SHA \
  --wait --atomic --timeout 15m

# 4. Verify
kubectl -n qayd get pods
curl -fsS https://api.qayd.io/health/ready
```

The `values.production.yaml` differs from the baseline only by pointing service env at the managed
endpoints (via the secrets manager references) and by raising the autoscaling ceilings. The release
mechanics — migration gate as a `pre-upgrade` hook, rolling update with `maxUnavailable: 0`, blue-green
for risky changes — are exactly [DEPLOYMENT.md](./DEPLOYMENT.md); the managed cloud does not change how
QAYD releases, only where the data plane lives.

# Scaling

Autoscaling on a managed platform uses the same signals as [DEPLOYMENT.md](./DEPLOYMENT.md) but leans on
the provider's managed autoscaler.

| Component | Autoscale signal | Managed mechanism |
|---|---|---|
| Web / API | CPU + request latency | container platform HPA, floor `container_min`, ceiling `container_max` |
| Queue workers | backlog depth per queue | KEDA (Redis list length) scaling each queue independently |
| AI engine | request + `ai`-queue backlog | HPA; scales to zero-floor when idle if the platform supports scale-to-zero |
| Reverb | concurrent WS connections | HPA on connection count |
| Scheduler | none | pinned to 1 |
| PostgreSQL | read load | add managed read replicas; managed vertical resize for write throughput |
| Redis | memory / ops | managed vertical resize, then cluster mode |

Managed autoscaling's advantage is elasticity without capacity planning: the `ai` and `reports` queues
absorb bursts (a month-end report storm, a bulk OCR import) by scaling workers on backlog, then scale
back, so steady-state cost tracks steady-state load. The read replica absorbs reporting reads so the
primary is sized for write throughput, not report fan-out. The one component that never autoscales is
the scheduler — the managed platform pins it to a single instance (or a managed cron replaces it) so no
scheduled job fires twice.

# Networking

Managed networking places every data service in a private subnet with no public endpoint; only the
managed LB/CDN is internet-facing.

```
Internet → CDN/WAF → Managed LB (public subnet)
                          │
        ┌─────────────────┼──────────────────┐
        ▼                 ▼                   ▼
     web pods          api pods           reverb pods      (private app subnet)
        │                 │                   │
        └──── /api/v1 ─────┤                   │
                          ▼                    │
                     ai pods                   │
                          │                    │
        ┌─────────────────┴────────┬───────────┘
        ▼                          ▼
  Managed Postgres / Redis   Object storage (private endpoint)
  (private subnet, no        (VPC endpoint / private link)
   public endpoint)
```

The data-plane subnet's security group/network policy accepts connections only from the API tier,
workers, scheduler, and Reverb. Managed PostgreSQL and Redis have **no public endpoint** — they are
reachable only over the VPC/private link. Object storage is accessed over a private endpoint so
attachment traffic never traverses the public internet. TLS terminates at the CDN and again at the
managed LB; the managed secrets manager and KMS are reached over the provider's private API.

# Observability

Managed deployment uses the provider's observability plus Sentry, mapping onto the same surface as
[DEPLOYMENT.md](./DEPLOYMENT.md):

- **Metrics** flow to the provider's monitoring (or a managed Prometheus) — request latency, per-queue
  backlog, DB connections, replica lag, AI-engine latency, WS connection count. Grafana or the
  provider's dashboards visualize them.
- **Health probes** are wired into the container platform so unready pods never take traffic and
  crashed pods restart.
- **Errors** go to Sentry tagged with `request_id`, company id, and release SHA.
- **Logs** are shipped by the platform's log agent to the provider's log store; audit logs stay durable
  in PostgreSQL ([../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)).
- **Cost/usage** dashboards (below) are themselves an observability concern on managed cloud, since
  autoscaling makes spend a function of load.

# Backup & DR

Managed services do most of the backup heavy lifting, which is a primary reason to choose them:

- **PostgreSQL** — managed automated backups + point-in-time recovery, with backup copies replicated
  cross-region for DR. This is the managed counterpart to the pgBackRest posture in
  [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md); QAYD still
  validates restores on a schedule rather than trusting the provider blindly.
- **Object storage** — versioning + cross-region replication is a managed feature toggle.
- **Redis** — managed persistence/snapshots; queue jobs are idempotent so a failover re-drives safely.
- **Secrets/KMS** — managed durability and key rotation.

Cross-region failover, RTO/RPO targets, and residency-driven region choice are specified in
[MULTI_REGION.md](./MULTI_REGION.md); this document assumes single-region managed deployment as the
baseline and defers HA to that document.

# Security

Managed cloud shifts specific controls to the provider while QAYD keeps ownership of the model:

- **Secrets** live in the managed secrets manager, KMS-backed, IAM-scoped per service — the web service
  is granted read access to *no* data-plane secret ([../security/SECRETS.md](../security/SECRETS.md)).
- **Encryption at rest** is the managed service's KMS-backed disk encryption plus QAYD's own
  application-layer field encryption of PII/bank numbers ([../security/ENCRYPTION.md](../security/ENCRYPTION.md));
  the field-KEK root lives in the provider KMS, in-region for residency.
- **RLS** is unaffected — it is a stock-Postgres feature the managed database runs; the app role stays
  `NOBYPASSRLS` ([../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md)).
- **Least privilege** is enforced by IAM roles per service and by network policy, independently of
  credentials.
- **Edge** — CDN WAF + DDoS in front of the web and API hostnames.
- **Shared responsibility** — the provider secures the hypervisor, physical hosts, and managed-service
  patching; QAYD secures the application, the data model, IAM scoping, and the secret custody.

# CI/CD

CI/CD is the same GitHub Actions pipeline as [DEPLOYMENT.md](./DEPLOYMENT.md): build and test all three
images, push by immutable SHA, then `helm upgrade --atomic` against the managed cluster with the
migration gate as a pre-upgrade hook. The only managed-cloud additions are:

- **`terraform plan` on infra PRs** — data-plane changes (a bigger DB class, a new replica, a residency
  region) are code-reviewed as Terraform before apply; `terraform apply` is a gated, audited step.
- **Secret seeding** reads from the vault into the managed secrets manager; secret values never appear
  in CI logs or the repo.
- **Per-environment values files** point the release at each environment's managed endpoints.

```yaml
# .github/workflows/infra.yml  (excerpt)
jobs:
  plan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: terraform -chdir=infrastructure/terraform/envs/${{ inputs.env }} init
      - run: terraform -chdir=infrastructure/terraform/envs/${{ inputs.env }} plan -var-file=${{ inputs.env }}.tfvars
      # apply is a separate, manually-approved job gated on the plan output
```

# Managed vs self-managed — the trade-offs

The core decision this document informs is, per component, whether to consume a managed service or run
it yourself on the container platform. The default is **managed for stateful services, self-run
containers for the stateless tiers**, because the operational leverage is highest exactly where state
and durability are hardest.

| Component | Managed pros | Self-managed pros | QAYD default |
|---|---|---|---|
| PostgreSQL | automated backup/PITR, failover, patching, replicas | fine-grained tuning, no per-service premium | **managed** (durability of the ledger dominates) |
| Redis | managed failover/persistence, no ops | cheaper at scale, full control | **managed** (queue durability matters) |
| Object storage | 11-nines durability, versioning, CRR for free | none meaningful | **managed** |
| Container platform | no cluster ops, autoscaling built in | cheaper per-core, full control | **managed** (or thin self-run Kubernetes) |
| Secrets/KMS | hardware-backed keys, rotation, audit | none meaningful | **managed** |
| Web/API/AI/Reverb/workers | — | portable images, no per-service premium, identical to self-host | **self-run containers** on the managed platform |

The application tiers stay as portable OCI images (the same ones self-hosting runs) precisely so the
platform underneath them can be swapped — that is what keeps vendor lock-in confined to the data-plane
services, which are the ones with a real switching cost. Choosing managed PostgreSQL does lock a
region's ledger to a provider's backup format; QAYD mitigates this by also taking provider-independent
logical dumps ([../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md)) so a
provider migration is always possible.

# Cost drivers

On managed cloud, the bill is dominated by a small number of drivers; understanding them is how the
autoscaling ceilings and instance classes get set.

| Driver | What moves it | Lever |
|---|---|---|
| Managed PostgreSQL | instance class, storage, IOPS, replica count, backup retention | right-size the primary for writes; use the replica for reads; tune retention to the statutory floor, not more |
| Container compute | steady-state replicas × size + burst | tight `min`/`max` per service; scale-to-floor idle; scale `ai`/`reports` on backlog not always-on |
| Egress / bandwidth | data leaving the cloud, cross-AZ chatter | CDN-cache the web bundle; keep data-plane traffic in one AZ where latency allows |
| Object storage | stored GB + requests + cross-region replication | lifecycle-tier cold attachments; CRR only where residency/DR requires |
| AI provider tokens | LLM/OCR usage | cache AI results; batch OCR; per-company AI spend caps (see [../ai/AI_ARCHITECTURE.md](../ai/AI_ARCHITECTURE.md)) |
| Managed Redis | node size × count, cluster mode | one instance until memory pressure; separate queue Redis only when contention appears |
| Observability | ingested log/metric volume | sample high-volume logs; retain audit (durable) separately from ops logs (ephemeral) |

The two most controllable drivers are container compute (via autoscaling floors/ceilings) and AI
provider tokens (via caching and spend caps). PostgreSQL is the largest fixed cost and the one to
right-size deliberately — over-provisioning the primary for report reads instead of adding a replica is
the most common avoidable expense.

# Provider-neutral reference for the specialized docs

This document is the base the per-provider documents extend. Each of `AWS.md`, `AZURE.md`, and `GCP.md`
should:

1. Name the concrete managed service for each row of the [mapping table](#the-mapping-table).
2. Supply the provider's Terraform resource types for the [data plane contracts](#contracts-each-managed-service-must-satisfy).
3. Specify the provider's identity model (IAM roles/service accounts/managed identities) for the
   per-service secret scoping.
4. Specify the provider's private-networking primitives (VPC/VNet, private endpoints/links) for the
   [networking](#networking) posture.
5. Give the provider's managed backup/PITR and cross-region replication settings, deferring RTO/RPO to
   [MULTI_REGION.md](./MULTI_REGION.md).

Nothing in a per-provider document may change the application contract (`/api/v1`, the `NEXT_PUBLIC_*`
boundary, RLS enforcement, the migration gate) — those are platform invariants that hold on every
provider. A per-provider document that needs to change one of them has found an architecture problem,
not a provider quirk.

# Related Documents

- [DEPLOYMENT.md](./DEPLOYMENT.md) — the topology, containerization, and release model this specializes.
- [SELF_HOSTING.md](./SELF_HOSTING.md) — the customer-owned single-node counterpart.
- [MULTI_REGION.md](./MULTI_REGION.md) — cross-region HA, residency, and DR that this defers to.
- [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) — AWS+Cloudflare target and vendor-lock-in principle.
- [../security/SECRETS.md](../security/SECRETS.md), [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md), [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md)

# End of Document
