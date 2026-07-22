# Azure Deployment — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: AZURE
---

# Purpose

This document specifies how QAYD is deployed on **Microsoft Azure**: how each runtime component maps
onto an Azure managed service, how the virtual network is drawn and locked down, how the estate is
provisioned as code, and how it is released, scaled, observed, and recovered. It is the Azure-specific
realization of the deployment topology in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md#deployment-topology) and the
system decomposition in [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md).
Where this document and a foundation document disagree on *what a component is*, the foundation
document governs; this document governs only *how that component runs on Azure*. It is the sibling of
[AWS.md](../deployment/AWS.md) and [GCP.md](../deployment/GCP.md) and deliberately keeps the same
component→service structure so the three can be compared line for line.

QAYD is three independently deployable services — the Laravel 12 (PHP 8.4) **API**, the Next.js 15
**web** tier, and the FastAPI/Python **AI engine** — plus the stateful and supporting infrastructure
they depend on: a PostgreSQL 15 primary database, a Redis cache/queue/lock tier, Laravel Reverb
WebSocket nodes, object storage, a per-queue worker fleet, and one scheduler. The authoritative stack
is fixed by [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); this document only chooses the
Azure services that host it.

Three platform facts drive every decision and are load-bearing:

1. **The web tier is unprivileged.** The Next.js client bundle is public and may carry only
   `NEXT_PUBLIC_*` values. No Azure secret, database credential, or signing key is ever placed in the
   web tier's client bundle; the web server runtime holds only what it needs to call the API. See
   [../security/SECRETS.md](../security/SECRETS.md).
2. **Multi-tenancy is single-database with row-level security.** All tenants share one PostgreSQL
   server; isolation is enforced by RLS (`SET LOCAL app.current_company_id`) inside the API, per
   [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
   [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md). The deployment protects the
   one database rather than sharding tenants across databases.
3. **Data residency is a first-class constraint.** QAYD serves GCC customers; the default home region
   is **UAE North (Dubai)**, with **Qatar Central (Doha)** as the alternate GCC region. Customer
   financial data — the PostgreSQL server, the blob containers holding documents, and all backups —
   must remain in the chosen GCC region unless a signed data-processing agreement says otherwise.
   Region selection is a per-tenant-cohort deployment decision, not a runtime toggle.

This document does not restate business rules, the API wire contract, or the cryptographic key
hierarchy; it is the operational counterpart to the architecture documents.

# Reference Architecture

The estate is a single Virtual Network per region with subnets for the ingress edge, the application
workloads, and the private data tier, spread across three Availability Zones. Only Front Door and the
Application Gateway are internet-facing; every workload and datastore is private and reaches Azure
services over Private Endpoints.

```
                    Azure DNS (qayd.com, api.qayd.com, ws.qayd.com)
                                        │
                                        ▼
                    Azure Front Door (global CDN, TLS, WAF)
                                        │
              ┌─────────────────────────┼──────────────────────────┐
              ▼                         ▼                           ▼
      static/web assets         App Gateway (api.qayd.com)   App Gateway (ws.qayd.com)
      (Blob $web / CDN)          WAF v2, HTTPS 443           WebSocket 443
                                        │                           │
   ┌────────────────────── VNet (uaenorth) 10.50.0.0/16 ───────────────────────────┐
   │  snet-ingress:  Application Gateway subnet                                     │
   │                                                                                │
   │  snet-apps  (AKS node pool  /  Container Apps environment):                    │
   │    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
   │    │ web  (Next)  │  │ api (Laravel)│  │ ai (FastAPI) │  │ reverb (WS)  │     │
   │    └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘     │
   │    ┌──────────────────────────────────────────────────┐                       │
   │    │ queue workers (per-queue deployment)             │  scheduler (1 replica)│
   │    │ realtime | default | ai | reports | integrations │                       │
   │    └──────────────────────────────────────────────────┘                       │
   │                                                                                │
   │  snet-data (Private Endpoints only):                                           │
   │    ┌────────────────────┐  ┌────────────────────┐                             │
   │    │ PostgreSQL Flexible │  │ Azure Cache for    │   Private Endpoints:        │
   │    │ Server (HA + read   │  │ Redis (replica)    │   Blob, Key Vault, ACR,     │
   │    │ replica)            │  │                    │   Service Bus, Monitor      │
   │    └────────────────────┘  └────────────────────┘                             │
   └────────────────────────────────────────────────────────────────────────────────┘
              │
              ▼
   Blob Storage (documents, CMK)  ·  Key Vault  ·  ACR  ·  Azure Monitor / App Insights
```

The three services and the Reverb/worker/scheduler roles run as containers on **Azure Kubernetes
Service (AKS)** by default, with **Azure Container Apps** offered as the lower-operational-overhead
alternative for teams that do not want to run Kubernetes. The datastores are managed: **Azure Database
for PostgreSQL Flexible Server** (zone-redundant HA, read replicas) and **Azure Cache for Redis**
(Premium, replication + zone redundancy). Object storage is **Blob Storage** with customer-managed-key
encryption and time-limited SAS URLs. Realtime is Laravel Reverb as its own workload behind a
WebSocket-capable **Application Gateway**. The Redis-backed queues are optionally augmented with
**Azure Service Bus** for the `integrations` queue where managed at-least-once delivery and a
dead-letter queue are wanted. Everything internet-facing is fronted by **Azure Front Door** (CDN + WAF)
and **Azure DNS**, terminating TLS with managed certificates.

# Service Mapping

Every QAYD component maps to exactly one primary Azure service. This table is the contract the rest of
the document elaborates. The "Container Apps alt" column names the equivalent when the team chooses
Container Apps over AKS.

| QAYD component | Azure service (AKS default) | Container Apps alt | Notes |
|---|---|---|---|
| Web tier (Next.js 15, Node SSR) | AKS Deployment `qayd-web` + Front Door (Blob `$web` for static) | Container App `qayd-web` | Node SSR/BFF; static to Blob behind Front Door. Holds only `NEXT_PUBLIC_*` + API base URL. Unprivileged. |
| API (Laravel 12, PHP 8.4) | AKS Deployment `qayd-api` behind App Gateway | Container App `qayd-api` | nginx + php-fpm. Stateless. Autoscaled on CPU + request rate. |
| AI engine (FastAPI, Python) | AKS Deployment `qayd-ai` (GPU node pool if in-process models) | Container App `qayd-ai` | Reached only from the API. No database ingress. |
| Realtime (Laravel Reverb) | AKS Deployment `qayd-reverb` behind App Gateway (WebSocket) | Container App `qayd-reverb` | State in Redis; scale on concurrent connections. `ws.qayd.com` listener. |
| Queue workers (6 queues) | One AKS Deployment **per queue** (`qayd-worker-<queue>`) | One Container App per queue | `php artisan queue:work`; KEDA-scaled on backlog. |
| Scheduler (`schedule:run`) | AKS Deployment `qayd-scheduler`, **replicas = 1** | Container App `qayd-scheduler` min=max=1 | Single replica so cron never double-fires. |
| Primary database (PostgreSQL 15) | Azure Database for PostgreSQL **Flexible Server**, zone-redundant HA | same | Private access (VNet-injected / Private Endpoint); RLS on; `require_secure_transport=on`. |
| Read replica(s) | PostgreSQL Flexible Server **read replica(s)** | same | Reporting/`reports` reads via a read DSN. |
| Cache / queue backend / locks | Azure Cache for Redis (Premium, zone-redundant) | same | Sessions, cache, Laravel queues, `Cache::lock`, Reverb pub/sub. TLS-only. |
| Object storage (documents) | Azure Blob Storage (`qaydprod` account, `documents` container) | same | CMK via Key Vault; public access disabled; access via SAS URLs from the API. |
| Managed secrets store | Azure Key Vault (+ App Configuration for non-secret config) | same | CSI Secrets Store driver (AKS) / Key Vault refs (Container Apps). Rotation on DB + JWT key. |
| Container registry | Azure Container Registry (`qayd/api`,`/web`,`/ai`,`/reverb`) | same | Immutable tags, scan-on-push, retention policy. |
| Managed queue alternative | Azure Service Bus (+ DLQ) for `integrations` | same | Optional durable at-least-once for webhook/bank-sync jobs. |
| CDN / TLS / WAF | Azure Front Door (Premium) + managed certs | same | WAF policy at the edge; App Gateway WAF v2 regional. |
| DNS | Azure DNS (public + private zones) | same | Private DNS zones for Private Endpoints; health-probed records. |
| Observability | Azure Monitor + Log Analytics + Application Insights | same | Container logs to Log Analytics; App Insights traces keyed by `X-Request-Id`. |
| Identity for workloads | Microsoft Entra **Managed Identities** (workload identity on AKS) | Container App managed identity | No long-lived keys inside pods. |

**AKS vs Container Apps.** AKS is the default: full control, GPU node pools for the AI engine, KEDA for
per-queue scaling, and one platform for all six services. Container Apps is the recommended alternative
when the team wants Kubernetes-free operations — it still gives per-service scale-to-N, KEDA-style
scalers, managed ingress, and Key Vault-referenced secrets. The service boundaries are identical either
way; only the orchestration substrate changes. GPU for in-process AI models is the one case that pins
`qayd-ai` to an AKS GPU node pool.

# Networking & Security Groups

The network is default-deny, expressed with **Network Security Groups (NSGs)** per subnet and
**Private Endpoints** for every data service. The internet reaches only Front Door and the Application
Gateway; the API is the only thing that reaches the database; the AI engine has no inbound internet
path and no path to the database at all.

**Subnets (VNet 10.50.0.0/16, three zones):**

- **snet-ingress** — Application Gateway (WAF v2) only.
- **snet-apps** — AKS node pool / Container Apps environment (all workloads). Egress via NAT Gateway or
  Azure Firewall.
- **snet-data** — Private Endpoints for PostgreSQL, Redis, Blob, Key Vault, ACR, Service Bus, Monitor.

**NSG rules (source → destination is what the rule *allows in*):**

| NSG (on subnet/workload) | Allows inbound from | On ports | Purpose |
|---|---|---|---|
| `nsg-ingress` | Front Door service tag (`AzureFrontDoor.Backend`), 443 | 443 | Application Gateway. |
| `nsg-web` | App Gateway subnet | 3000 | Next.js SSR pods. |
| `nsg-api` | App Gateway subnet, web pods (SSR/BFF) | 8080 | Laravel API pods. |
| `nsg-reverb` | App Gateway subnet | 8080 (WS) | Reverb WebSocket pods. |
| `nsg-ai` | api pods **only** | 8000 | FastAPI AI engine. No gateway, no public path. |
| `nsg-workers` | (none inbound) | — | Egress-only; pull from Redis/Service Bus. |
| PostgreSQL Private Endpoint | api, workers, scheduler pods | 5432 | **Not** web, **not** ai. |
| Redis Private Endpoint | api, workers, scheduler, reverb pods | 6380 (TLS) | Cache/queue/locks/pub-sub. |

The two crux rules that protect tenant and AI safety, reviewed on every change: **the PostgreSQL
Private Endpoint NSG never admits the AI pods** (enforcing that the AI engine never touches PostgreSQL
directly) and **never admits the web pods** (the unprivileged web tier reaches data only through the
API).

**Egress control.** Workloads reach Azure services (Blob, Key Vault, ACR, Monitor, Service Bus) over
**Private Endpoints** with **Private DNS zones**, so that traffic never traverses the public internet.
Outbound internet (bank aggregators, AI model providers, mail/SMS) egresses through a **NAT Gateway**
or, where an allowlist is required, **Azure Firewall** with FQDN rules for partner domains.

**WAF.** A Front Door WAF policy (managed rule sets + rate limiting) is the global edge shield; App
Gateway WAF v2 is the regional layer in front of the API. The API's own Redis-based per-token,
per-company rate limiting remains the authoritative tenant-fair limiter.

# Provisioning (IaC)

The estate is provisioned as code. **Terraform** is the primary tool (state in an Azure Storage backend
with blob leasing for locking); **Bicep** snippets are given where a team standardizes on ARM-native
tooling. There is no portal click-ops. Modules mirror the service map: `network`, `data`
(postgres + redis), `aks` (or `containerapps`), `edge` (front door + waf + dns), `secrets`, and
`observability`.

**Backend and provider (pinned to the GCC home region):**

```hcl
# terraform/providers.tf
terraform {
  required_version = ">= 1.7"
  required_providers {
    azurerm = { source = "hashicorp/azurerm", version = "~> 3.110" }
  }
  backend "azurerm" {
    resource_group_name  = "qayd-tfstate-rg"
    storage_account_name = "qaydtfstateuaen"
    container_name       = "tfstate"
    key                  = "prod.terraform.tfstate"
  }
}

provider "azurerm" {
  features {}
}

variable "location" {
  default = "uaenorth"          # UAE North — GCC data residency (alt: qatarcentral)
}
```

**Resource group, VNet, three-tier subnets, NAT:**

```hcl
# terraform/network/main.tf
resource "azurerm_resource_group" "qayd" {
  name     = "qayd-${var.environment}-rg"
  location = var.location
  tags = { project = "qayd", data_class = "financial", managed_by = "terraform" }
}

resource "azurerm_virtual_network" "vnet" {
  name                = "qayd-${var.environment}-vnet"
  location            = var.location
  resource_group_name = azurerm_resource_group.qayd.name
  address_space       = ["10.50.0.0/16"]
}

resource "azurerm_subnet" "ingress" {
  name                 = "snet-ingress"
  resource_group_name  = azurerm_resource_group.qayd.name
  virtual_network_name = azurerm_virtual_network.vnet.name
  address_prefixes     = ["10.50.0.0/24"]
}

resource "azurerm_subnet" "apps" {
  name                 = "snet-apps"
  resource_group_name  = azurerm_resource_group.qayd.name
  virtual_network_name = azurerm_virtual_network.vnet.name
  address_prefixes     = ["10.50.16.0/20"]   # room for AKS pods/nodes
}

resource "azurerm_subnet" "data" {
  name                 = "snet-data"
  resource_group_name  = azurerm_resource_group.qayd.name
  virtual_network_name = azurerm_virtual_network.vnet.name
  address_prefixes     = ["10.50.64.0/22"]
  private_endpoint_network_policies_enabled = true
}

resource "azurerm_nat_gateway" "nat" {
  name                = "qayd-${var.environment}-nat"
  location            = var.location
  resource_group_name = azurerm_resource_group.qayd.name
  sku_name            = "Standard"
}
```

**PostgreSQL Flexible Server (zone-redundant HA + read replica, VNet-injected, TLS-forced):**

```hcl
# terraform/data/postgres.tf
resource "azurerm_postgresql_flexible_server" "primary" {
  name                   = "qayd-${var.environment}-pg"
  resource_group_name    = azurerm_resource_group.qayd.name
  location               = var.location
  version                = "15"
  sku_name               = "GP_Standard_D4ds_v5"
  storage_mb             = 262144
  auto_grow_enabled      = true
  zone                   = "1"
  delegated_subnet_id    = azurerm_subnet.data.id      # VNet-injected, private only
  private_dns_zone_id    = azurerm_private_dns_zone.pg.id
  backup_retention_days  = 14
  geo_redundant_backup_enabled = false                 # geo backup must stay in-GCC; see Backup & DR

  high_availability {
    mode                      = "ZoneRedundant"        # synchronous standby, another zone
    standby_availability_zone = "2"
  }

  administrator_login    = "qayd_admin"
  # Password sourced from Key Vault at apply; entra auth preferred for humans.
  administrator_password = var.pg_admin_password
}

resource "azurerm_postgresql_flexible_server_configuration" "ssl" {
  name      = "require_secure_transport"
  server_id = azurerm_postgresql_flexible_server.primary.id
  value     = "on"
}

resource "azurerm_postgresql_flexible_server_configuration" "rls" {
  name      = "row_security"
  server_id = azurerm_postgresql_flexible_server.primary.id
  value     = "on"
}

resource "azurerm_postgresql_flexible_server" "replica" {
  name                = "qayd-${var.environment}-pg-ro-1"
  resource_group_name = azurerm_resource_group.qayd.name
  location            = var.location
  create_mode         = "Replica"
  source_server_id    = azurerm_postgresql_flexible_server.primary.id
}
```

**Azure Cache for Redis (Premium, zone-redundant, TLS-only):**

```hcl
# terraform/data/redis.tf
resource "azurerm_redis_cache" "redis" {
  name                          = "qayd-${var.environment}-redis"
  resource_group_name           = azurerm_resource_group.qayd.name
  location                      = var.location
  capacity                      = 1
  family                        = "P"          # Premium — persistence, VNet, zones
  sku_name                      = "Premium"
  minimum_tls_version           = "1.2"
  public_network_access_enabled = false
  redis_configuration {
    maxmemory_policy = "allkeys-lru"
  }
  zones = ["1", "2"]
}
```

**AKS cluster with workload identity and a system + GPU-optional node pool:**

```hcl
# terraform/aks/main.tf
resource "azurerm_kubernetes_cluster" "aks" {
  name                = "qayd-${var.environment}-aks"
  location            = var.location
  resource_group_name = azurerm_resource_group.qayd.name
  dns_prefix          = "qayd-${var.environment}"

  oidc_issuer_enabled       = true
  workload_identity_enabled = true          # pods assume Entra identities, no static keys

  default_node_pool {
    name            = "system"
    vm_size         = "Standard_D4ds_v5"
    node_count      = 3
    zones           = ["1", "2", "3"]
    vnet_subnet_id  = azurerm_subnet.apps.id
    max_pods        = 60
  }

  network_profile {
    network_plugin = "azure"
    network_policy = "cilium"               # pod-level network policy
  }

  key_vault_secrets_provider {
    secret_rotation_enabled = true          # CSI Secrets Store driver
  }
}
```

Workloads are deployed with Helm/Kustomize onto AKS; the scheduler chart sets `replicas: 1` with no
HPA, and each worker chart carries a KEDA `ScaledObject` on its queue depth. On Container Apps the same
mapping is expressed with `az containerapp` (below) — one app per service, `--min-replicas`/
`--max-replicas`, and Key Vault secret references.

**Workload identity least-privilege (the API can read the documents container and its own secrets; the
AI engine can do neither):**

```hcl
resource "azurerm_role_assignment" "api_blob" {
  scope                = azurerm_storage_container.documents.resource_manager_id
  role_definition_name = "Storage Blob Data Contributor"   # object-level, not account admin
  principal_id         = azurerm_user_assigned_identity.api.principal_id
}

resource "azurerm_key_vault_access_policy" "api_kv" {
  key_vault_id = azurerm_key_vault.kv.id
  tenant_id    = data.azurerm_client_config.current.tenant_id
  object_id    = azurerm_user_assigned_identity.api.principal_id
  secret_permissions = ["Get"]                              # read, never write/delete
}
```

# Configuration & Secrets

Configuration splits by sensitivity, and the split is enforced by *where a value can be read*
(SECRETS.md):

- **Public web config** — `NEXT_PUBLIC_API_BASE_URL`, `NEXT_PUBLIC_WS_URL`, feature flags. Baked into
  the web build. Contains **no** secret; this is the only tier that ships values to the browser.
- **Non-secret runtime config** — region, container/account names, queue names, log levels. Stored in
  **Azure App Configuration** and passed as pod env.
- **Secrets** — DB credentials, Redis access key, `APP_KEY`, JWT RS256 signing key,
  `AI_ENGINE_SERVICE_KEY`, bank-aggregator tokens, Mailgun/SES and Twilio credentials, Sentry DSN.
  Stored in **Azure Key Vault**, surfaced to pods via the **CSI Secrets Store driver** (AKS) or Key
  Vault **references** (Container Apps), read through a **managed identity** — never baked into an
  image, a log, or a repo file.

**Rotation.** DB credentials and the JWT signing key rotate on the platform schedule (SECRETS.md): the
JWT key follows 90-day rotation with a 14-day verify-only overlap — a new secret version is added in
Key Vault, both versions are published to the API for the overlap, then the old version is disabled.
Managed identities remove the need to rotate any workload-to-Azure credential at all.

**Mapping to the API's expected variables.** The backend variables in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md#configuration-and-environment)
resolve on Azure as: `DB_*` ← Flexible Server FQDN + Key Vault DB secret (write DSN to primary, read
DSN to replica); `REDIS_*` ← Azure Cache hostname + access key (or Entra auth) over TLS 6380;
`R2_*`/`AWS_*` ← the Blob account/container + the managed identity (no static keys); `REVERB_*` ←
Reverb config + Key Vault; `AI_ENGINE_BASE_URL` ← internal cluster DNS of `qayd-ai`;
`AI_ENGINE_SERVICE_KEY`, `SENTRY_DSN`, mail/SMS ← Key Vault.

```bash
# Create secrets in Key Vault; values set out-of-band, never in the repo.
az keyvault secret set --vault-name qayd-prod-kv --name APP-KEY            --value "base64:..."
az keyvault secret set --vault-name qayd-prod-kv --name JWT-PRIVATE-KEY    --file jwt_private.pem
az keyvault secret set --vault-name qayd-prod-kv --name AI-ENGINE-SERVICE-KEY --value "..."
```

# Deployment Runbook

CI (GitHub Actions, per TECH_STACK) builds and pushes images to ACR; releases are Terraform +
`kubectl`/`helm` (AKS) or `az containerapp update` (Container Apps). Database migrations are a gated
step run against the primary **before** new API pods take traffic. The API uses a **blue/green** (or
canary) rollout; other services roll.

**1. Build and push images to ACR:**

```bash
az acr login --name qaydprodacr
for svc in api web ai reverb; do
  docker build -t qaydprodacr.azurecr.io/qayd/$svc:$GIT_SHA -f deploy/$svc.Dockerfile .
  docker push qaydprodacr.azurecr.io/qayd/$svc:$GIT_SHA          # immutable tag = commit SHA
done
```

**2. Run database migrations (gated, before new code takes traffic):**

```bash
# AKS: a one-off Job on the api image; failure blocks the release.
kubectl apply -f deploy/migrate-job.yaml           # runs: php artisan migrate --force
kubectl wait --for=condition=complete --timeout=600s job/qayd-api-migrate
# Container Apps equivalent: az containerapp job start --name qayd-api-migrate ...
```

Migrations are **expand-then-contract** (add compatibly, deploy tolerant code, backfill, drop the old
shape in a later release) so blue and green API versions run against one schema during cutover. Never
ship a destructive migration with the code that depends on it.

**3. Deploy the services:**

```bash
# AKS — Helm upgrade per service with the new image tag.
for svc in web ai reverb worker-realtime worker-default worker-ai worker-reports worker-integrations scheduler; do
  helm upgrade qayd-$svc deploy/charts/$svc --set image.tag=$GIT_SHA --wait
done
# API — blue/green via two Deployments + App Gateway backend swap (or Argo Rollouts canary).
helm upgrade qayd-api deploy/charts/api --set image.tag=$GIT_SHA --set strategy=bluegreen --wait

# Container Apps alternative (revision-based, built-in traffic splitting):
az containerapp update --name qayd-api --resource-group qayd-prod-rg \
  --image qaydprodacr.azurecr.io/qayd/api:$GIT_SHA
az containerapp ingress traffic set --name qayd-api --resource-group qayd-prod-rg \
  --revision-weight latest=10   # canary 10%, promote on healthy metrics
```

Container Apps' revision model gives traffic-weighted canary for free; on AKS the API uses a
blue/green backend swap or Argo Rollouts. Either way a canary slice is watched against App Insights
(5xx, p95 latency) and promoted or rolled back.

**4. Verify.** `/health/ready` (from `routes/health.php`: DB, Redis, Blob, AI-engine reachability) must
be green on every service; smoke a tenant-scoped API call and a Reverb subscribe; confirm queue depth
is draining. **Rollback** re-points to the previous image tag / previous revision; because migrations
were expand-only, the previous code runs against the current schema without a down-migration.

**5. Scheduler cutover.** The scheduler runs one replica; on deploy the old pod stops and the new one
starts. A few seconds of no-scheduler is harmless — due jobs fire on the next minute. Never scale the
scheduler above 1.

# Scaling & HA

**Statelessness is the enabler.** Web, API, AI, and Reverb hold no local state — sessions, cache,
locks, and broadcast pub/sub live in Azure Cache for Redis — so any pod serves any request and scaling
is pure. Only the scheduler is a singleton.

**Autoscaling (HPA + KEDA on AKS; built-in scale rules on Container Apps):**

| Service | Scale signal | Min / Max |
|---|---|---|
| `qayd-web` | CPU 60% + request rate | 2 / 12 |
| `qayd-api` | CPU 60% + App Gateway request rate | 3 / 24 |
| `qayd-ai` | CPU / inference latency (custom metric) | 2 / 10 |
| `qayd-reverb` | concurrent connections (custom metric) | 2 / 8 |
| `qayd-worker-realtime` | KEDA Redis list length (near-zero target) | 2 / 8 |
| `qayd-worker-default` | KEDA Redis list length | 2 / 12 |
| `qayd-worker-ai` | KEDA Redis list length | 1 / 10 |
| `qayd-worker-reports` | KEDA Redis list length | 1 / 8 |
| `qayd-worker-integrations` | KEDA Service Bus queue length (or Redis) | 1 / 6 |
| `qayd-scheduler` | none | **1 / 1** |

The `realtime` worker targets near-zero backlog so broadcasts never queue behind bulk work; `reports`
and `ai` are allowed to build backlog and scale into it. KEDA reads the Laravel queue's Redis list
length (or the Service Bus queue length for `integrations`) directly.

**High availability.** Three Availability Zones throughout. PostgreSQL Flexible Server **zone-redundant
HA** keeps a synchronous standby in another zone with automatic failover (typically 60–120s) and **no
committed data loss**; read replicas cover reporting and can be promoted in a regional event. Azure
Cache for Redis Premium with zone redundancy fails over to a replica automatically. AKS node pools span
zones; a lost zone removes at most one-third of capacity, refilled by autoscaling in the survivors. App
Gateway and Front Door are zone-redundant/global.

**Connection management.** **PgBouncer** (built into Flexible Server, or a sidecar) fronts PostgreSQL
so scaling the API to 24 pods does not exhaust connections; transaction pooling is compatible with the
per-request `SET LOCAL app.current_company_id` because the setting is transaction-scoped.

# Observability

Everything is correlated by the `X-Request-Id` the API stamps on every request, job, AI-engine call,
and webhook (BACKEND_ARCHITECTURE, Observability).

- **Logs.** Container stdout/stderr flow to a **Log Analytics workspace** (Container Insights), one
  table per environment, structured JSON carrying `request_id`, `company_id` (never PII), route, and
  status. KQL queries and log-based alerts turn error patterns into signals.
- **Metrics.** AKS/App Gateway/PostgreSQL/Redis emit **Azure Monitor** metrics natively (CPU, memory,
  request count, 5xx, DB connections, replica lag, cache evictions). The API publishes custom metrics
  (queue depth per queue, job runtime, AI-engine latency/error rate, broadcast throughput) to App
  Insights.
- **Traces.** **Application Insights** distributed tracing spans web→api→ai; the operation id is bound
  to `request_id` so a support ticket pulls the full trace. Sampling keeps cost bounded while always
  capturing failures.
- **Errors.** Unhandled exceptions go to **Sentry** (per TECH_STACK) with `request_id` and company id;
  the client gets the generic `500`. App Insights covers the infra signals.
- **Health.** `routes/health.php` liveness/readiness back the App Gateway backend health probe and an
  availability test; unhealthy-instance count pages on-call.
- **Alerts → paging.** Azure Monitor alert rules (API 5xx, p95 latency, PostgreSQL CPU/replica-lag/
  storage, Redis evictions, queue backlog growth, Service Bus DLQ non-empty) fire an Action Group to
  the on-call channel.

Prometheus/Grafana from TECH_STACK can run alongside (Azure Monitor managed Prometheus + Managed
Grafana) for a cross-cloud pane; the Azure Monitor path above is complete for Azure-only operation.

# Backup & DR

Backup and DR follow [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md);
this section states the Azure realization and the residency rule. **All backups stay in the GCC home
region** (or a second GCC region), never in a non-GCC region, unless a DPA permits otherwise — which is
why `geo_redundant_backup_enabled` is left `false` (Azure geo-redundant backup pairs UAE North with a
non-GCC region and must not be used for financial data).

- **Database.** Flexible Server automated backups with **14-day** retention plus **point-in-time
  restore** to any second in the window. PITR is the primary tool for "bad write at a known time";
  restore is always to a **new server**, never in place. Recovered *posted* financial rows are
  re-created through the API's posting path, not re-inserted, so the immutable-ledger and audit
  guarantees hold (BACKUP_RECOVERY).
- **Objects.** The Blob documents container runs with **blob versioning** and **soft delete** on (an
  overwrite/delete is recoverable) and a lifecycle policy tiering old versions to Cool/Archive.
  **Object replication** to a second **GCC-region** account gives a regional-outage copy that still
  honors residency.
- **Redis.** Azure Cache Premium **RDB persistence** + scheduled export to Blob. Redis holds
  cache/queue/ephemeral state; its loss degrades performance and drops in-flight queued jobs, not
  committed financial state — money-affecting jobs are idempotent and replay safely.
- **Secrets & config.** Key Vault is durable and versioned with soft-delete + purge protection; App
  Configuration and Terraform state are versioned.

**Targets.** Tier-0 financial tables target **RPO ≈ 5 minutes** and **RTO ≈ 1 hour** for a full
restore-to-new-server, consistent with BACKUP_RECOVERY. Zone-redundant HA (PostgreSQL, Redis) covers
the common single-zone fault in seconds-to-minutes with **no data loss**.

**Regional DR.** A full-region loss is the disaster case. The prepared path: PostgreSQL **geo-restore**
or a **cross-region read replica in the alternate GCC region** (Qatar Central), Terraform to stand the
estate up there from code, and Front Door / Azure DNS failover to shift traffic once the standby is
promoted. All DR copies target a GCC region only. Recovery testing runs on a schedule (nightly
restore-verify in an isolated subscription, per BACKUP_RECOVERY) — an untested backup is not a backup.

# Cost Notes

- **AKS vs Container Apps.** Container Apps has lower operational overhead and scales to zero for
  spiky, low-baseline services (a good fit for `reports`/`ai` workers off-peak); AKS is more
  cost-efficient at steady scale and required for GPU. Running both is possible but doubles the
  operational surface — pick one substrate per environment.
- **NAT / Private Endpoints.** NAT Gateway data processing and per-Private-Endpoint hourly charges are
  recurring; Private Endpoints keep Azure-bound traffic off the internet and off NAT, which is both a
  security and a cost win. Keep cross-zone chatter down.
- **PostgreSQL.** Ddsv5 (or Ev5 for memory-heavy) sizing with **reserved capacity** on the steady
  primary + replica is a clear saving once sizing stabilizes; storage auto-grow avoids over-provision.
- **Data residency has a price.** UAE North / Qatar Central can price above West Europe / East US;
  residency is a compliance requirement, not a cost lever — do not move financial data out of region to
  save.
- **Log volume.** Log Analytics ingestion and App Insights traces scale with traffic; set table
  retention (e.g. 30 days hot, archive tier for compliance), sample App Insights, and prefer
  metric/log-alert rules over broad KQL scans for alarming.
- **Front Door offload.** Front Door caching static assets and cacheable API responses offloads the
  origin and cuts egress; serving the web tier's assets from the edge is the biggest web-tier lever.

A first production sizing (UAE North) is roughly: PostgreSQL Flexible Server `GP_Standard_D4ds_v5`
zone-redundant + one read replica, Azure Cache Premium P1, and AKS/Container Apps services in the min
counts above — a mid-four-figure USD/month baseline that scales with tenant load, dominated by the
database and Redis Premium until the worker fleets grow.

# End of Document
