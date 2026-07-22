# Google Cloud Deployment — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: GCP
---

# Purpose

This document specifies how QAYD is deployed on **Google Cloud Platform**: how each runtime component
maps onto a GCP managed service, how the VPC is drawn and locked down, how the estate is provisioned as
code, and how it is released, scaled, observed, and recovered. It is the GCP-specific realization of the
deployment topology in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md#deployment-topology) and the
system decomposition in [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md).
Where this document and a foundation document disagree on *what a component is*, the foundation document
governs; this document governs only *how that component runs on GCP*. It is the sibling of
[AWS.md](../deployment/AWS.md) and [AZURE.md](../deployment/AZURE.md) and keeps the same
component→service structure so the three can be compared line for line.

QAYD is three independently deployable services — the Laravel 12 (PHP 8.4) **API**, the Next.js 15
**web** tier, and the FastAPI/Python **AI engine** — plus the stateful and supporting infrastructure
they depend on: a PostgreSQL 15 primary database, a Redis cache/queue/lock tier, Laravel Reverb
WebSocket nodes, object storage, a per-queue worker fleet, and one scheduler. The authoritative stack is
fixed by [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); this document only chooses the GCP
services that host it.

Three platform facts drive every decision and are load-bearing:

1. **The web tier is unprivileged.** The Next.js client bundle is public and may carry only
   `NEXT_PUBLIC_*` values. No GCP secret, database credential, or signing key is ever placed in the web
   tier's client bundle; the web server runtime holds only what it needs to call the API. See
   [../security/SECRETS.md](../security/SECRETS.md).
2. **Multi-tenancy is single-database with row-level security.** All tenants share one PostgreSQL
   instance; isolation is enforced by RLS (`SET LOCAL app.current_company_id`) inside the API, per
   [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
   [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md). The deployment protects the
   one database rather than sharding tenants across databases.
3. **Data residency is a first-class constraint.** QAYD serves GCC customers; the default home region is
   **me-central2 (Dammam, Saudi Arabia)**, with **me-central1 (Doha, Qatar)** as the alternate GCC
   region. Customer financial data — the Cloud SQL instance, the Cloud Storage buckets holding
   documents, and all backups — must remain in the chosen GCC region unless a signed data-processing
   agreement says otherwise. Region selection is a per-tenant-cohort deployment decision, not a runtime
   toggle. (Both GCC regions are single-zone at launch for some resources; where a service is not yet
   multi-zone in-region, [Scaling & HA](#scaling--ha) and [Backup & DR](#backup--dr) note the
   consequence.)

This document does not restate business rules, the API wire contract, or the cryptographic key
hierarchy; it is the operational counterpart to the architecture documents.

# Reference Architecture

The estate is a single VPC per region with private subnets for the workloads and Private Service Access
for the managed datastores. Only the external HTTP(S) Load Balancer is internet-facing; every workload
and datastore is private and reaches Google APIs over Private Google Access.

```
                    Cloud DNS (qayd.com, api.qayd.com, ws.qayd.com)
                                        │
                                        ▼
              External HTTPS Load Balancer + Cloud CDN + Cloud Armor (WAF)
                                        │
              ┌─────────────────────────┼──────────────────────────┐
              ▼                         ▼                           ▼
      static/web assets          URL map → api backend       URL map → ws backend
      (GCS bucket backend)       (serverless NEG / GKE)      (WebSocket, GKE/Cloud Run)
                                        │                           │
   ┌────────────────────── VPC (me-central2) 10.60.0.0/16 ─────────────────────────┐
   │  subnet-apps  (GKE Autopilot  /  Cloud Run services):                         │
   │    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
   │    │ web  (Next)  │  │ api (Laravel)│  │ ai (FastAPI) │  │ reverb (WS)  │     │
   │    └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘     │
   │    ┌──────────────────────────────────────────────────┐                       │
   │    │ queue workers (per-queue deployment/service)     │  scheduler (1 replica)│
   │    │ realtime | default | ai | reports | integrations │                       │
   │    └──────────────────────────────────────────────────┘                       │
   │                                                                                │
   │  Private Service Access (VPC peering to Google-managed tenant net):            │
   │    ┌────────────────────┐  ┌────────────────────┐                             │
   │    │ Cloud SQL Postgres  │  │ Memorystore Redis  │   Private Google Access:    │
   │    │ (HA + read replica) │  │ (STANDARD_HA)      │   GCS, Secret Manager, AR,  │
   │    └────────────────────┘  └────────────────────┘   Pub/Sub, Cloud Monitoring │
   └────────────────────────────────────────────────────────────────────────────────┘
              │
              ▼
   Cloud Storage (documents, CMEK)  ·  Secret Manager  ·  Artifact Registry  ·  Cloud Monitoring/Trace
```

The three services and the Reverb/worker/scheduler roles run on **GKE Autopilot** by default, with
**Cloud Run** offered as the serverless, lower-operational-overhead alternative for the stateless HTTP
services. The datastores are managed: **Cloud SQL for PostgreSQL** (regional HA, read replicas) and
**Memorystore for Redis** (STANDARD_HA with a replica). Object storage is **Cloud Storage** with
customer-managed encryption keys (CMEK) and time-limited **V4 signed URLs**. Realtime is Laravel Reverb
as its own workload behind a WebSocket-capable HTTPS Load Balancer backend. The Redis-backed queues are
optionally augmented with **Pub/Sub** for the `integrations` queue where managed at-least-once delivery
and a dead-letter topic are wanted. Everything internet-facing is fronted by the **external HTTPS Load
Balancer + Cloud CDN + Cloud Armor** and **Cloud DNS**, terminating TLS with Google-managed
certificates.

# Service Mapping

Every QAYD component maps to exactly one primary GCP service. This table is the contract the rest of the
document elaborates. The "Cloud Run alt" column names the equivalent when the team chooses Cloud Run
over GKE for a stateless service.

| QAYD component | GCP service (GKE default) | Cloud Run alt | Notes |
|---|---|---|---|
| Web tier (Next.js 15, Node SSR) | GKE Deployment `qayd-web` + Cloud CDN (GCS backend for static) | Cloud Run `qayd-web` | Node SSR/BFF; static to a GCS bucket behind Cloud CDN. Holds only `NEXT_PUBLIC_*` + API base URL. Unprivileged. |
| API (Laravel 12, PHP 8.4) | GKE Deployment `qayd-api` behind HTTPS LB | Cloud Run `qayd-api` | nginx + php-fpm. Stateless. Autoscaled on CPU + request rate. |
| AI engine (FastAPI, Python) | GKE Deployment `qayd-ai` (GPU node pool if in-process models) | Cloud Run `qayd-ai` (GPU where available) | Reached only from the API. No database ingress. |
| Realtime (Laravel Reverb) | GKE Deployment `qayd-reverb` behind HTTPS LB (WebSocket) | GKE (WS long-lived) | State in Redis; scale on concurrent connections. `ws.qayd.com`. Long-lived WS favors GKE over Cloud Run. |
| Queue workers (6 queues) | One GKE Deployment **per queue** (`qayd-worker-<queue>`) | Cloud Run **jobs**/services per queue | `php artisan queue:work`; KEDA-scaled on backlog. |
| Scheduler (`schedule:run`) | GKE Deployment `qayd-scheduler`, **replicas = 1** | Cloud Scheduler → job | Single replica so cron never double-fires. |
| Primary database (PostgreSQL 15) | Cloud SQL for PostgreSQL, **Regional (HA)** | same | Private IP (Private Service Access); RLS on; SSL required. |
| Read replica(s) | Cloud SQL **read replica(s)** | same | Reporting/`reports` reads via a read DSN. |
| Cache / queue backend / locks | Memorystore for Redis (STANDARD_HA) | same | Sessions, cache, Laravel queues, `Cache::lock`, Reverb pub/sub. In-transit encryption + AUTH. |
| Object storage (documents) | Cloud Storage (`qayd-<env>-documents`) | same | CMEK; uniform bucket-level access; public access prevention; V4 signed URLs from the API. |
| Managed secrets store | Secret Manager (+ Runtime Config/env for non-secret) | same | Workload Identity access; rotation on DB + JWT key. |
| Container registry | Artifact Registry (`qayd/api`,`/web`,`/ai`,`/reverb`) | same | Immutable tags, vulnerability scanning, cleanup policy. |
| Managed queue alternative | Pub/Sub (+ dead-letter topic) for `integrations` | same | Optional durable at-least-once for webhook/bank-sync jobs. |
| CDN / TLS / WAF | Cloud CDN + Google-managed certs + **Cloud Armor** | same | Cloud Armor WAF/rate rules at the edge. |
| DNS | Cloud DNS (public + private zones) | same | Private zones for Private Google Access; health-checked records. |
| Observability | Cloud Monitoring + Cloud Logging + Cloud Trace | same | Container logs to Cloud Logging; Trace keyed by `X-Request-Id`. |
| Identity for workloads | **Workload Identity** → per-service Google service accounts | Cloud Run service identity | No long-lived keys inside pods/containers. |

**GKE Autopilot vs Cloud Run.** GKE Autopilot is the default: one platform for all six services, GPU
node pools for the AI engine, KEDA for per-queue scaling, and first-class long-lived WebSockets for
Reverb. Cloud Run is the recommended alternative for the stateless HTTP services (web, api, ai) when the
team wants a fully serverless, scale-to-zero substrate — it still supports Secret Manager, Workload
Identity, and request-based autoscaling. **Reverb stays on GKE** because Cloud Run's request-lifecycle
model is a poor fit for long-lived WebSocket connections. Service boundaries are identical regardless of
substrate.

# Networking & Security Groups

The network is default-deny, expressed with **VPC firewall rules** (target service accounts, not IP
ranges, wherever possible) and **Private Service Access** / **Private Google Access** for the managed
services. The internet reaches only the external HTTPS Load Balancer; the API is the only thing that
reaches the database; the AI engine has no inbound internet path and no path to the database at all.

**Subnets (VPC 10.60.0.0/16, me-central2):**

- **subnet-apps** — GKE nodes / Cloud Run VPC connector (all workloads). Egress via Cloud NAT.
- **Private Service Access range** — a reserved range peered to Google's tenant network for Cloud SQL
  and Memorystore private IPs.

**Firewall rules (identified by target service account; source is what the rule *allows in*):**

| Firewall rule (target SA) | Allows ingress from | On ports | Purpose |
|---|---|---|---|
| `fw-lb-to-api` | Google LB health-check + proxy ranges (`130.211.0.0/22`, `35.191.0.0/16`) | 8080 | API from the HTTPS LB only. |
| `fw-lb-to-web` | LB proxy ranges | 3000 | Next.js SSR from the LB. |
| `fw-lb-to-reverb` | LB proxy ranges | 8080 (WS) | Reverb from the LB. |
| `fw-web-to-api` | `sa-web` | 8080 | SSR/BFF → API. |
| `fw-api-to-ai` | `sa-api` **only** | 8000 | API → FastAPI AI engine. No LB, no public path. |
| Cloud SQL private IP | `sa-api`, `sa-worker`, `sa-scheduler` (via Private Service Access) | 5432 | **Not** `sa-web`, **not** `sa-ai`. |
| Memorystore private IP | `sa-api`, `sa-worker`, `sa-scheduler`, `sa-reverb` | 6379 | Cache/queue/locks/pub-sub. |
| `fw-deny-all` (implied) | — | — | Default-deny; only the above are permitted. |

The two crux rules that protect tenant and AI safety, reviewed on every change: **Cloud SQL admits
`sa-api`/`sa-worker`/`sa-scheduler` but never `sa-ai`** (enforcing that the AI engine never touches
PostgreSQL directly) and **never `sa-web`** (the unprivileged web tier reaches data only through the
API). Because rules target service accounts, an accidental IP overlap cannot grant the AI engine
database reach.

**Egress control.** Workloads reach Google services (GCS, Secret Manager, Artifact Registry, Pub/Sub,
Monitoring) over **Private Google Access**, so that traffic never traverses the public internet.
Outbound internet (bank aggregators, AI model providers, mail/SMS) egresses through **Cloud NAT** and,
where an allowlist is required, a **Secure Web Proxy** with FQDN rules for partner domains.

**Cloud Armor.** A Cloud Armor security policy (preconfigured WAF rules for SQLi/XSS + a per-IP rate
rule) is the edge shield on the external Load Balancer. The API's own Redis-based per-token, per-company
rate limiting remains the authoritative tenant-fair limiter.

# Provisioning (IaC)

The estate is **Terraform** (state in a GCS backend with object versioning for lock/history). There is
no console click-ops. Modules mirror the service map: `network`, `data` (cloudsql + memorystore),
`gke` (or `cloudrun`), `edge` (lb + cdn + armor + dns), `secrets`, and `observability`.

**Backend and provider (pinned to the GCC home region):**

```hcl
# terraform/providers.tf
terraform {
  required_version = ">= 1.7"
  required_providers {
    google = { source = "hashicorp/google", version = "~> 5.40" }
  }
  backend "gcs" {
    bucket = "qayd-tfstate-mec2"
    prefix = "prod"
  }
}

provider "google" {
  project = var.project_id
  region  = "me-central2"          # Dammam, KSA — GCC data residency (alt: me-central1 Doha)
}
```

**VPC, subnet, Private Service Access, Cloud NAT:**

```hcl
# terraform/network/main.tf
resource "google_compute_network" "vpc" {
  name                    = "qayd-${var.environment}-vpc"
  auto_create_subnetworks = false
}

resource "google_compute_subnetwork" "apps" {
  name          = "subnet-apps"
  ip_cidr_range = "10.60.16.0/20"
  region        = "me-central2"
  network       = google_compute_network.vpc.id
  private_ip_google_access = true                 # reach Google APIs privately
  secondary_ip_range {                            # GKE pods
    range_name    = "gke-pods"
    ip_cidr_range = "10.61.0.0/16"
  }
  secondary_ip_range {                            # GKE services
    range_name    = "gke-services"
    ip_cidr_range = "10.62.0.0/20"
  }
}

# Private Service Access: reserved range + VPC peering for Cloud SQL / Memorystore private IPs.
resource "google_compute_global_address" "psa_range" {
  name          = "qayd-psa-range"
  purpose       = "VPC_PEERING"
  address_type  = "INTERNAL"
  prefix_length = 20
  network       = google_compute_network.vpc.id
}

resource "google_service_networking_connection" "psa" {
  network                 = google_compute_network.vpc.id
  service                 = "servicenetworking.googleapis.com"
  reserved_peering_ranges = [google_compute_global_address.psa_range.name]
}

resource "google_compute_router" "router" {
  name    = "qayd-router"
  region  = "me-central2"
  network = google_compute_network.vpc.id
}

resource "google_compute_router_nat" "nat" {
  name                               = "qayd-nat"
  router                             = google_compute_router.router.name
  region                             = "me-central2"
  nat_ip_allocate_option             = "AUTO_ONLY"
  source_subnetwork_ip_ranges_to_nat = "ALL_SUBNETWORKS_ALL_IP_RANGES"
}
```

**Cloud SQL for PostgreSQL (regional HA + read replica, private IP, SSL, RLS-ready):**

```hcl
# terraform/data/cloudsql.tf
resource "google_sql_database_instance" "primary" {
  name             = "qayd-${var.environment}-pg"
  database_version = "POSTGRES_15"
  region           = "me-central2"
  depends_on       = [google_service_networking_connection.psa]

  settings {
    tier              = "db-custom-4-16384"        # 4 vCPU / 16 GB
    availability_type = "REGIONAL"                  # synchronous HA standby
    disk_type         = "PD_SSD"
    disk_size         = 200
    disk_autoresize   = true

    ip_configuration {
      ipv4_enabled    = false                       # private IP only
      private_network = google_compute_network.vpc.id
      require_ssl     = true
    }
    backup_configuration {
      enabled                        = true
      point_in_time_recovery_enabled = true         # WAL archiving for PITR
      start_time                     = "23:00"       # low-traffic GCC night
      transaction_log_retention_days = 7
      backup_retention_settings { retained_backups = 14 }
    }
    database_flags { name = "cloudsql.enable_pgaudit" value = "on" }
    # row_security is on by default in PG15; RLS policies are created by app migrations.
  }
  deletion_protection = true
}

resource "google_sql_database_instance" "replica" {
  name                 = "qayd-${var.environment}-pg-ro-1"
  database_version     = "POSTGRES_15"
  region               = "me-central2"
  master_instance_name = google_sql_database_instance.primary.name
  settings {
    tier              = "db-custom-2-8192"
    availability_type = "ZONAL"
    ip_configuration { ipv4_enabled = false, private_network = google_compute_network.vpc.id }
  }
}
```

**Memorystore for Redis (STANDARD_HA, in-transit encryption + AUTH):**

```hcl
# terraform/data/memorystore.tf
resource "google_redis_instance" "redis" {
  name               = "qayd-${var.environment}-redis"
  tier               = "STANDARD_HA"          # primary + replica, automatic failover
  memory_size_gb     = 5
  region             = "me-central2"
  authorized_network = google_compute_network.vpc.id
  connect_mode       = "PRIVATE_SERVICE_ACCESS"
  redis_version      = "REDIS_7_0"
  auth_enabled       = true
  transit_encryption_mode = "SERVER_AUTHENTICATION"
  maxmemory_policy   = "allkeys-lru"
}
```

**GKE Autopilot cluster with Workload Identity:**

```hcl
# terraform/gke/main.tf
resource "google_container_cluster" "aks" {
  name             = "qayd-${var.environment}-gke"
  location         = "me-central2"
  enable_autopilot = true                     # Google manages nodes; per-pod billing

  network    = google_compute_network.vpc.id
  subnetwork = google_compute_subnetwork.apps.id
  ip_allocation_policy {
    cluster_secondary_range_name  = "gke-pods"
    services_secondary_range_name = "gke-services"
  }
  workload_identity_config {                  # pods assume Google SAs, no static keys
    workload_pool = "${var.project_id}.svc.id.goog"
  }
  master_authorized_networks_config {}        # private control plane
  private_cluster_config {
    enable_private_nodes    = true
    enable_private_endpoint = false
  }
}
```

Workloads are deployed with Helm/Kustomize; the scheduler chart sets `replicas: 1` with no HPA, and
each worker chart carries a KEDA `ScaledObject` on its queue depth. On Cloud Run the same mapping is
`gcloud run deploy` per service with `--min-instances`/`--max-instances` and Secret Manager mounts.

**Workload Identity least-privilege (the API's service account can read the documents bucket and its own
secrets; the AI engine's cannot):**

```hcl
resource "google_service_account" "api" { account_id = "sa-api" }

resource "google_storage_bucket_iam_member" "api_docs" {
  bucket = google_storage_bucket.documents.name
  role   = "roles/storage.objectAdmin"          # object-level on this bucket only
  member = "serviceAccount:${google_service_account.api.email}"
}

resource "google_secret_manager_secret_iam_member" "api_secret" {
  secret_id = google_secret_manager_secret.api.id
  role      = "roles/secretmanager.secretAccessor"   # read its own secret, nothing else
  member    = "serviceAccount:${google_service_account.api.email}"
}

# Bind the Kubernetes SA to the Google SA (Workload Identity).
resource "google_service_account_iam_member" "api_wi" {
  service_account_id = google_service_account.api.name
  role               = "roles/iam.workloadIdentityUser"
  member             = "serviceAccount:${var.project_id}.svc.id.goog[qayd/qayd-api]"
}
```

# Configuration & Secrets

Configuration splits by sensitivity, and the split is enforced by *where a value can be read*
(SECRETS.md):

- **Public web config** — `NEXT_PUBLIC_API_BASE_URL`, `NEXT_PUBLIC_WS_URL`, feature flags. Baked into
  the web build. Contains **no** secret; this is the only tier that ships values to the browser.
- **Non-secret runtime config** — region, bucket names, queue names, log levels. Kept as plain env /
  ConfigMap and passed to pods/containers.
- **Secrets** — DB credentials, Redis AUTH string, `APP_KEY`, JWT RS256 signing key,
  `AI_ENGINE_SERVICE_KEY`, bank-aggregator tokens, Mailgun/SES and Twilio credentials, Sentry DSN.
  Stored in **Secret Manager**, read at runtime through **Workload Identity** (GKE) or a Secret Manager
  volume/env (Cloud Run) — never baked into an image, a log, or a repo file.

**Rotation.** DB credentials and the JWT signing key rotate on the platform schedule (SECRETS.md): the
JWT key follows 90-day rotation with a 14-day verify-only overlap — a new secret *version* is added in
Secret Manager, both versions are published to the API for the overlap, then the old version is
disabled. Workload Identity removes the need to rotate any workload-to-GCP credential at all (no keys
exist to leak).

**Mapping to the API's expected variables.** The backend variables in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md#configuration-and-environment)
resolve on GCP as: `DB_*` ← Cloud SQL private IP (via the Cloud SQL Auth Proxy sidecar) + Secret Manager
DB secret (write DSN to primary, read DSN to replica); `REDIS_*` ← Memorystore host + AUTH string over
TLS; `R2_*`/`AWS_*` ← the GCS bucket + the service account (no static keys — the client uses Workload
Identity); `REVERB_*` ← Reverb config + Secret Manager; `AI_ENGINE_BASE_URL` ← internal cluster DNS of
`qayd-ai`; `AI_ENGINE_SERVICE_KEY`, `SENTRY_DSN`, mail/SMS ← Secret Manager.

```bash
# Create secrets; values piped in out-of-band, never in the repo.
printf '%s' "base64:..."         | gcloud secrets create qayd-prod-app-key       --data-file=- --replication-policy=user-managed --locations=me-central2
gcloud secrets create qayd-prod-jwt-private-key --data-file=jwt_private.pem       --replication-policy=user-managed --locations=me-central2
printf '%s' "..."                | gcloud secrets create qayd-prod-ai-service-key --data-file=- --replication-policy=user-managed --locations=me-central2
```

Note the `--replication-policy=user-managed --locations=me-central2`: secrets are pinned to the GCC
region, not globally replicated, to keep key material in-region.

# Deployment Runbook

CI (GitHub Actions, per TECH_STACK) builds and pushes images to Artifact Registry; releases are
Terraform + `kubectl`/`helm` (GKE) or `gcloud run deploy` (Cloud Run). Database migrations are a gated
step run against the primary **before** new API pods take traffic. The API uses a **canary/blue-green**
rollout; other services roll.

**1. Build and push images to Artifact Registry:**

```bash
gcloud auth configure-docker me-central2-docker.pkg.dev
REG=me-central2-docker.pkg.dev/$PROJECT/qayd
for svc in api web ai reverb; do
  docker build -t $REG/$svc:$GIT_SHA -f deploy/$svc.Dockerfile .
  docker push $REG/$svc:$GIT_SHA               # immutable tag = commit SHA
done
```

**2. Run database migrations (gated, before new code takes traffic):**

```bash
# GKE: a one-off Job on the api image (with the Cloud SQL Auth Proxy sidecar); failure blocks release.
kubectl apply -f deploy/migrate-job.yaml        # runs: php artisan migrate --force
kubectl wait --for=condition=complete --timeout=600s job/qayd-api-migrate
# Cloud Run equivalent: gcloud run jobs execute qayd-api-migrate --wait
```

Migrations are **expand-then-contract** (add compatibly, deploy tolerant code, backfill, drop the old
shape in a later release) so blue and green API versions run against one schema during cutover. Never
ship a destructive migration with the code that depends on it.

**3. Deploy the services:**

```bash
# GKE — Helm upgrade per service with the new image tag.
for svc in web ai reverb worker-realtime worker-default worker-ai worker-reports worker-integrations scheduler; do
  helm upgrade qayd-$svc deploy/charts/$svc --set image.tag=$GIT_SHA --wait
done
# API — canary via two Deployments behind the same backend service (or Argo Rollouts).
helm upgrade qayd-api deploy/charts/api --set image.tag=$GIT_SHA --set strategy=canary --wait

# Cloud Run alternative (revision-based traffic splitting):
gcloud run deploy qayd-api --image $REG/api:$GIT_SHA --no-traffic --region me-central2
gcloud run services update-traffic qayd-api --to-revisions LATEST=10 --region me-central2  # 10% canary
```

Cloud Run's revision model gives traffic-weighted canary for free; on GKE the API uses two Deployments
or Argo Rollouts. Either way the canary slice is watched against Cloud Monitoring (5xx, p95 latency) and
promoted or rolled back.

**4. Verify.** `/health/ready` (from `routes/health.php`: DB, Redis, GCS, AI-engine reachability) must
be green on every service; smoke a tenant-scoped API call and a Reverb subscribe; confirm queue depth is
draining. **Rollback** re-points to the previous image tag / previous revision (`update-traffic
--to-revisions PREVIOUS=100`); because migrations were expand-only, the previous code runs against the
current schema without a down-migration.

**5. Scheduler cutover.** The scheduler runs one replica; on deploy the old pod stops and the new one
starts. A few seconds of no-scheduler is harmless — due jobs fire on the next minute. Never scale the
scheduler above 1. (On Cloud Run the scheduler is expressed as **Cloud Scheduler → a job**, which is
inherently single-fire.)

# Scaling & HA

**Statelessness is the enabler.** Web, API, AI, and Reverb hold no local state — sessions, cache, locks,
and broadcast pub/sub live in Memorystore — so any pod serves any request and scaling is pure. Only the
scheduler is a singleton.

**Autoscaling (HPA + KEDA on GKE; request-based on Cloud Run):**

| Service | Scale signal | Min / Max |
|---|---|---|
| `qayd-web` | CPU 60% + request rate | 2 / 12 |
| `qayd-api` | CPU 60% + LB request rate | 3 / 24 |
| `qayd-ai` | CPU / inference latency (custom metric) | 2 / 10 |
| `qayd-reverb` | concurrent connections (custom metric) | 2 / 8 |
| `qayd-worker-realtime` | KEDA Redis list length (near-zero target) | 2 / 8 |
| `qayd-worker-default` | KEDA Redis list length | 2 / 12 |
| `qayd-worker-ai` | KEDA Redis list length | 1 / 10 |
| `qayd-worker-reports` | KEDA Redis list length | 1 / 8 |
| `qayd-worker-integrations` | KEDA Pub/Sub undelivered messages (or Redis) | 1 / 6 |
| `qayd-scheduler` | none | **1 / 1** |

The `realtime` worker targets near-zero backlog so broadcasts never queue behind bulk work; `reports`
and `ai` are allowed to build backlog and scale into it. KEDA reads the Laravel queue's Redis list
length (or Pub/Sub subscription backlog for `integrations`).

**High availability.** Cloud SQL **Regional (HA)** keeps a synchronous standby in a second zone with
automatic failover (typically 60–120s) and **no committed data loss**; read replicas cover reporting and
can be promoted in a regional event. Memorystore **STANDARD_HA** fails over to its replica automatically.
GKE Autopilot spreads pods across zones **where the region is multi-zone**. Note the residency caveat:
if the chosen GCC region is single-zone for a given resource, in-region HA for that resource is limited
and the **cross-region DR path in [Backup & DR](#backup--dr) carries more weight** — this is an explicit
trade of some in-region redundancy for data residency, and it is deliberate.

**Connection management.** The **Cloud SQL Auth Proxy** sidecar handles TLS and IAM auth to the
database; **PgBouncer** in front of it caps connections so scaling the API to 24 pods does not exhaust
PostgreSQL. Transaction pooling is compatible with the per-request `SET LOCAL app.current_company_id`
because the setting is transaction-scoped.

# Observability

Everything is correlated by the `X-Request-Id` the API stamps on every request, job, AI-engine call, and
webhook (BACKEND_ARCHITECTURE, Observability).

- **Logs.** Container stdout/stderr flow to **Cloud Logging** automatically (GKE/Cloud Run), structured
  JSON carrying `request_id`, `company_id` (never PII), route, and status. Log-based metrics and
  alerting policies turn error patterns into signals.
- **Metrics.** GKE/LB/Cloud SQL/Memorystore emit **Cloud Monitoring** metrics natively (CPU, memory,
  request count, 5xx, DB connections, replica lag, cache evictions). The API publishes custom metrics
  (queue depth per queue, job runtime, AI-engine latency/error rate, broadcast throughput).
- **Traces.** **Cloud Trace** spans web→api→ai; the trace id is bound to `request_id` so a support
  ticket referencing a request id pulls the full trace. Sampling keeps cost bounded while always
  capturing failures.
- **Errors.** Unhandled exceptions go to **Sentry** (per TECH_STACK) with `request_id` and company id;
  the client gets the generic `500`. Cloud Monitoring covers the infra signals; Error Reporting groups
  crash signatures.
- **Health.** `routes/health.php` liveness/readiness back the LB backend health check and an uptime
  check; unhealthy-instance count pages on-call.
- **Alerts → paging.** Cloud Monitoring alerting policies (API 5xx, p95 latency, Cloud SQL
  CPU/replica-lag/disk, Memorystore evictions, queue backlog growth, Pub/Sub dead-letter non-empty)
  notify the on-call channel.

Prometheus/Grafana from TECH_STACK can run alongside (Google Managed Prometheus + Managed Grafana) for a
cross-cloud pane; the Cloud Monitoring path above is complete for GCP-only operation.

# Backup & DR

Backup and DR follow [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md);
this section states the GCP realization and the residency rule. **All backups stay in the GCC home
region** (or a second GCC region), never in a non-GCC region, unless a DPA permits otherwise.

- **Database.** Cloud SQL automated backups with **14-day** retention plus **point-in-time recovery**
  (transaction-log/WAL retention 7 days). PITR is the primary tool for "bad write at a known time";
  restore is always to a **new instance**, never in place. Recovered *posted* financial rows are
  re-created through the API's posting path, not re-inserted, so the immutable-ledger and audit
  guarantees hold (BACKUP_RECOVERY).
- **Objects.** The Cloud Storage documents bucket runs with **object versioning** on (an
  overwrite/delete is recoverable) and a lifecycle policy tiering old versions to Nearline/Coldline.
  For a regional-outage copy that still honors residency, use a **dual-region within GCC** where
  available, or scheduled **Storage Transfer** to a bucket in the alternate GCC region — never a
  non-GCC destination.
- **Redis.** Memorystore **RDB snapshots** + export to GCS. Redis holds cache/queue/ephemeral state;
  its loss degrades performance and drops in-flight queued jobs, not committed financial state —
  money-affecting jobs are idempotent and replay safely.
- **Secrets & config.** Secret Manager is durable and versioned (region-pinned as above); Terraform
  state in GCS is versioned.

**Targets.** Tier-0 financial tables target **RPO ≈ 5 minutes** and **RTO ≈ 1 hour** for a full
restore-to-new-instance, consistent with BACKUP_RECOVERY. Cloud SQL Regional HA and Memorystore
STANDARD_HA cover the common single-zone fault in seconds-to-minutes with **no data loss** — subject to
the region actually being multi-zone (the residency caveat above).

**Regional DR.** A full-region loss is the disaster case. The prepared path: a **Cloud SQL cross-region
read replica in the alternate GCC region** (me-central1, Doha) that can be promoted, GCS
transfer/dual-region for objects, Terraform to stand the estate up there from code, and Cloud DNS
failover to shift traffic once the standby is promoted. All DR copies target a GCC region only. Recovery
testing runs on a schedule (nightly restore-verify in an isolated project, per BACKUP_RECOVERY) — an
untested backup is not a backup, and the residency trade above makes the cross-region rehearsal *more*
important, not less.

# Cost Notes

- **GKE Autopilot vs Cloud Run.** Cloud Run scales to zero and is cheapest for spiky, low-baseline
  stateless services (web/api/ai off-peak, `reports`/`ai` workers); GKE Autopilot is per-pod-priced and
  efficient at steady scale, required for GPU and for Reverb's long-lived WebSockets. Pick one substrate
  per environment to avoid doubling the operational surface; a common split is Cloud Run for
  web/api/ai + GKE for reverb/workers.
- **Cloud NAT / egress.** Cloud NAT charges per gateway-hour and per GB processed; Private Google Access
  keeps Google-bound traffic off NAT and off the internet, a security and cost win. Watch cross-zone
  egress.
- **Cloud SQL.** Custom machine types plus **committed use discounts** on the steady primary + replica
  are a clear saving once sizing stabilizes; disk autoresize avoids over-provisioning PD_SSD.
- **Data residency has a price.** me-central2 (Dammam) / me-central1 (Doha) can price above us-central1
  / europe-west, and single-zone limitations may force the cross-region DR spend described above;
  residency is a compliance requirement, not a cost lever — do not move financial data out of region to
  save.
- **Log/trace volume.** Cloud Logging ingestion and Trace scale with traffic; set log retention and
  exclusion filters (drop health-check noise), sample Trace, and prefer log-based metrics over broad log
  scans for alerting.
- **Cloud CDN offload.** Cloud CDN in front of the GCS static bucket and cacheable API responses
  offloads the origin and cuts egress; serving the web tier's assets from the edge is the biggest
  web-tier lever.

A first production sizing (me-central2) is roughly: Cloud SQL `db-custom-4-16384` Regional + one
`db-custom-2-8192` read replica, Memorystore STANDARD_HA 5 GB, and GKE Autopilot / Cloud Run services in
the min counts above — a mid-four-figure USD/month baseline that scales with tenant load, dominated by
Cloud SQL and Memorystore until the worker fleets grow.

# End of Document
