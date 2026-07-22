# Self-Hosting on Customer Infrastructure — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: SELF_HOSTING
---

# Purpose

This document specifies how QAYD is deployed on **infrastructure the customer owns and controls** — an
on-premises server, a single VPS, or a private data-center rack — rather than QAYD's managed cloud. It
is the single-node, customer-operated specialization of the topology defined in
[DEPLOYMENT.md](./DEPLOYMENT.md): the same three deployables (Laravel 12 API, Next.js 15 web, FastAPI AI
engine) and the same data plane (PostgreSQL 15, Redis, Laravel Reverb, object storage, queue workers),
collapsed onto one machine with an all-in-one Docker Compose stack. Where [DEPLOYMENT.md](./DEPLOYMENT.md)
describes the elastic Kubernetes production shape and [CLOUD.md](./CLOUD.md) maps components to managed
services, this document describes the opposite end: everything on one host, operated by the customer's
own IT staff, with QAYD's cloud unreachable or deliberately absent.

Self-hosting exists for customers whose regulatory, contractual, or sovereignty posture forbids their
financial data leaving their premises — banks, government-adjacent entities, and enterprises under
strict GCC/Kuwait data-residency obligations (see [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md)).
The trade is explicit: the customer gains full physical control of the ledger and its backups, and in
exchange takes on the operational responsibilities QAYD's SRE team otherwise carries — patching,
backup verification, TLS renewal, and capacity planning. This document is written so that a competent
sysadmin can stand up, secure, back up, upgrade, and recover a QAYD instance from exact commands
without QAYD's help desk in the loop.

The invariants from [DEPLOYMENT.md](./DEPLOYMENT.md) do not relax on a single node: the web tier stays
unprivileged and holds no database credentials, secrets are never baked into images or the browser
bundle (`NEXT_PUBLIC_*` only), migrations gate every start, and PostgreSQL Row-Level Security remains
the tenant boundary of last resort. A smaller machine does not mean a weaker security model.

# Topology / Architecture

A self-hosted QAYD is the reference single-node stack: one host, one Docker Compose project, all
services as containers, all state on local volumes (or a customer-attached NAS/SAN).

```
                    ┌──────────────────────── Customer host ────────────────────────┐
   Users (LAN /     │                                                                │
   VPN / internet)  │   ┌─────────────┐                                              │
        │           │   │  Caddy /     │  TLS termination, HTTP→HTTPS, routing        │
        └──────────▶│   │  nginx (TLS) │                                              │
                    │   └──────┬───────┘                                              │
                    │          │                                                      │
                    │   ┌──────┴────────────────┐                                     │
                    │   ▼                        ▼                                     │
                    │ ┌──────────┐        ┌──────────────┐                            │
                    │ │  web     │─/api/v1▶│  api         │◀── reverb (WS)             │
                    │ │ Next.js  │        │  Laravel     │                            │
                    │ └──────────┘        │  php-fpm     │                            │
                    │                     └──┬────────┬──┘                            │
                    │                        │        │  HTTP + ai queue              │
                    │            ┌───────────┘        ▼                               │
                    │            │              ┌──────────┐                          │
                    │   ┌────────┴───┐          │  ai      │ FastAPI (no DB creds)    │
                    │   ▼            ▼          └──────────┘                          │
                    │ ┌──────┐  ┌──────┐  ┌────────┐  ┌──────────┐  ┌────────────┐    │
                    │ │worker│  │sched │  │postgres│  │  redis   │  │ minio /     │    │
                    │ │ (x1) │  │ (x1) │  │  15    │  │          │  │ objects     │    │
                    │ └──────┘  └──────┘  └────────┘  └──────────┘  └────────────┘    │
                    │                        │             │              │           │
                    │                        ▼             ▼              ▼           │
                    │                    /var/qayd/pg   /var/qayd/redis  /var/qayd/obj│
                    │                    (local volumes, backed up nightly)           │
                    └───────────────────────────────────────────────────────────────┘
```

The only structural difference from cloud production is **object storage**: a self-hosted instance
uses MinIO (S3-compatible) as a local container instead of Cloudflare R2, so no attachment ever leaves
the host. The API tier talks to MinIO with the same S3 client and the same `R2_*`/`AWS_*` variables —
only the endpoint changes. Everything else — the migration gate, the per-queue worker, the singleton
scheduler, Reverb for realtime — is byte-for-byte the same image as cloud.

# Prerequisites

**Hardware sizing.** Size by the number of active users and monthly document volume. These are
starting points for a single host; scale up before, not after, resource pressure.

| Tier | Active users | Host CPU | RAM | Disk (SSD/NVMe) | Notes |
|---|---|---|---|---|---|
| Evaluation | ≤ 5 | 2 vCPU | 4 GB | 40 GB | single-tenant trials; AI features light |
| Small business | ≤ 25 | 4 vCPU | 8 GB | 100 GB | 1–3 companies; typical SME |
| Department | ≤ 100 | 8 vCPU | 16 GB | 250 GB | several companies; heavier reporting |
| Enterprise single-node | ≤ 300 | 16 vCPU | 32 GB | 500 GB+ | consider splitting DB to its own host |

Above ~300 concurrent users or when the ledger grows past a few tens of millions of lines, a single
node is the wrong shape — move the customer to the Kubernetes topology in [DEPLOYMENT.md](./DEPLOYMENT.md)
or the managed model in [CLOUD.md](./CLOUD.md). Disk is dominated by PostgreSQL WAL/data and by
attachments in MinIO; provision at least 3× the expected ledger size to leave room for backups and WAL.

**Software.**

| Requirement | Version | Notes |
|---|---|---|
| Linux host | any modern LTS (Ubuntu 22.04+, RHEL 9+) | 64-bit, systemd |
| Docker Engine | 24+ | with the Compose v2 plugin |
| Docker Compose | v2.20+ | |
| A domain or hostname | — | for TLS; a LAN hostname works with an internal CA |
| Outbound HTTPS (optional) | — | only if using cloud LLM/OCR; not required air-gapped |

**Access to images.** The customer needs either pull access to QAYD's private registry
(`registry.qayd.io`) with a license-scoped token, or, for air-gapped sites, an offline image bundle
(`qayd-<version>-images.tar`) delivered out of band and loaded with `docker load`.

# Components & Services

The self-hosted stack runs the identical container images as cloud production; only the composition and
the object-storage backend differ.

| Service | Image | Replicas (single-node) | Purpose |
|---|---|---|---|
| `caddy` | `caddy:2` | 1 | TLS termination, reverse proxy, HTTP→HTTPS |
| `web` | `qayd/web` | 1 | Next.js SSR/BFF; unprivileged |
| `api` | `qayd/api` (php-fpm) | 1 | Laravel API; the only privileged tier |
| `worker` | `qayd/api` | 1 (all queues) | queue processing |
| `scheduler` | `qayd/api` | 1 (singleton) | `schedule:run` cron |
| `reverb` | `qayd/api` | 1 | WebSocket broadcast |
| `ai` | `qayd/ai` | 1 | FastAPI reasoning/OCR; no DB creds |
| `postgres` | `postgres:15` | 1 | source of truth |
| `redis` | `redis:7` | 1 | cache/queue/session/locks |
| `minio` | `minio/minio` | 1 | S3-compatible object storage |
| `migrate` | `qayd/api` | run-once | migration gate |

On a single node the worker consumes all queues in one process (`--queue=realtime,default,ai,reports,
integrations,maintenance`), preserving priority order. If the AI features are heavily used, the customer
may split a second worker dedicated to the `ai` queue — the compose file supports it by adding one
service with `--queue=ai`.

# Configuration & Secrets

Self-hosted secrets live in a single root-owned `.env` file (mode `600`) on the host, plus generated
credentials for Postgres, Redis, MinIO, and the Reverb app. **QAYD never ships default passwords in an
image**; the install script generates them on first run. The `NEXT_PUBLIC_*` boundary is unchanged: the
web container receives only public values.

```bash
# /opt/qayd/.env   (chmod 600, owned by root)
QAYD_VERSION=1.8.2
QAYD_DOMAIN=qayd.acme-corp.local

# --- generated on install; never a shipped default ---
DB_PASSWORD=__generated__
REDIS_PASSWORD=__generated__
APP_KEY=base64:__generated__
MINIO_ROOT_USER=qayd
MINIO_ROOT_PASSWORD=__generated__
REVERB_APP_ID=__generated__
REVERB_APP_KEY=__generated__
REVERB_APP_SECRET=__generated__
AI_ENGINE_SERVICE_KEY=__generated__

# --- object storage points at the local MinIO ---
R2_ENDPOINT=http://minio:9000
R2_BUCKET=qayd
R2_ACCESS_KEY_ID=qayd
R2_SECRET_ACCESS_KEY=${MINIO_ROOT_PASSWORD}

# --- public (baked into web bundle) ---
NEXT_PUBLIC_API_BASE_URL=https://qayd.acme-corp.local/api
NEXT_PUBLIC_REVERB_HOST=qayd.acme-corp.local

# --- AI provider: cloud OR local model. Air-gapped sites use a local LLM. ---
LLM_MODE=local                         # local | cloud
LLM_PROVIDER_KEY=                      # empty when LLM_MODE=local
OCR_MODE=local                         # local (Tesseract) | cloud (Document AI)
```

For **air-gapped** installs (below), `LLM_MODE=local` and `OCR_MODE=local` remove every outbound
dependency: the AI engine uses a bundled local model and Tesseract OCR, and no key ever leaves the host.
Cloud LLM/OCR remains available for connected sites that accept the outbound call.

Secrets rotation on a self-hosted node is the customer's responsibility and follows
[../security/SECRETS.md](../security/SECRETS.md): rotate the `.env` value, rebuild config cache
(`docker compose exec api php artisan config:cache`), and restart the affected services.

# Step-by-Step / Runbook

## Install

```bash
# 1. Fetch the release bundle (connected site)
curl -fsSL https://get.qayd.io/install.sh -o install.sh
sudo bash install.sh --domain qayd.acme-corp.local --version 1.8.2

# --- OR, air-gapped site: load images from the offline bundle ---
docker load -i qayd-1.8.2-images.tar

# 2. The installer:
#    - writes /opt/qayd/docker-compose.yml and /opt/qayd/.env (600)
#    - generates all passwords / keys
#    - creates /var/qayd/{pg,redis,obj} volume dirs
#    - obtains TLS (Let's Encrypt if public; internal CA cert if LAN)

# 3. Start the stack
cd /opt/qayd
docker compose up -d

# 4. The migrate service runs first and gates the rest.
docker compose logs -f migrate      # wait for "Migrations complete"

# 5. Create the first admin + first company
docker compose exec api php artisan qayd:install-admin \
  --email admin@acme-corp.local --name "System Admin"
```

The all-in-one compose file is the [DEPLOYMENT.md](./DEPLOYMENT.md) reference compose with the MinIO
service added and Caddy in front for TLS:

```yaml
# /opt/qayd/docker-compose.yml   (self-host additions over the reference)
services:
  caddy:
    image: caddy:2
    ports: ["80:80", "443:443"]
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddydata:/data
    depends_on: [web, api, reverb]

  minio:
    image: minio/minio
    command: ["server", "/data", "--console-address", ":9001"]
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
    volumes: ["/var/qayd/obj:/data"]
    healthcheck:
      test: ["CMD", "mc", "ready", "local"]
      interval: 15s

  createbucket:                       # one-shot: ensure the qayd bucket exists
    image: minio/mc
    depends_on: { minio: { condition: service_healthy } }
    entrypoint: >
      /bin/sh -c "mc alias set local http://minio:9000 $$MINIO_ROOT_USER $$MINIO_ROOT_PASSWORD;
                  mc mb -p local/qayd || true;
                  mc version enable local/qayd || true"
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}

volumes:
  caddydata:
```

```
# /opt/qayd/Caddyfile
{$QAYD_DOMAIN} {
    encode gzip
    @api path /api/*
    handle @api { reverse_proxy api:9000 }
    @ws path /app/*                       # Reverb WebSocket path
    handle @ws { reverse_proxy reverb:8080 }
    handle { reverse_proxy web:3000 }     # everything else → Next.js
}
```

Caddy obtains and renews TLS automatically for a public domain. For a LAN-only hostname, the installer
provisions a certificate from the customer's internal CA and mounts it; `tls /etc/caddy/qayd.crt
/etc/caddy/qayd.key` replaces automatic issuance.

## TLS setup

- **Public domain:** Caddy uses Let's Encrypt automatically — no action beyond a resolvable A record and
  open ports 80/443.
- **LAN / internal:** the customer supplies a cert from their internal CA (or the installer generates a
  self-signed one and prints the fingerprint to distribute to clients). HTTP is always redirected to
  HTTPS; the API and Reverb are never served in cleartext.

## Operational runbook (day-2)

| Task | Command |
|---|---|
| Status | `docker compose ps` |
| Tail logs | `docker compose logs -f api worker` |
| Health | `docker compose exec api php artisan qayd:health` |
| Restart a service | `docker compose restart api` |
| Queue backlog | `docker compose exec api php artisan queue:monitor redis:default` |
| Rebuild config after `.env` change | `docker compose exec api php artisan config:cache && docker compose restart api worker scheduler` |
| Put in maintenance | `docker compose exec api php artisan down` |
| Resume | `docker compose exec api php artisan up` |

# Scaling

A self-hosted node scales **vertically first**. The single lever is host size (see the sizing table);
within that, the customer can:

- Add a dedicated `ai`-queue worker if OCR/analysis backs up, without touching the rest of the stack.
- Move PostgreSQL to its own host (point `DB_HOST` at it, keep the app containers on the app host) as
  the first horizontal split before considering Kubernetes.
- Increase php-fpm workers and Postgres `max_connections`/`shared_buffers` via mounted config for a
  larger box.

When vertical scaling is exhausted (roughly the "Enterprise single-node" row), the correct move is not
a bigger single node but a migration to the multi-node Kubernetes topology in
[DEPLOYMENT.md](./DEPLOYMENT.md) — the images and migrations are identical, so the data migrates with a
standard `pg_dump`/restore and an object-store sync.

# Networking

A self-hosted instance is typically **not** internet-facing. Recommended posture:

- Bind the host's published ports to the LAN interface or behind the customer's VPN; do not expose
  Postgres (`5432`), Redis (`6379`), or MinIO (`9000/9001`) outside the Docker network at all — only
  Caddy's `443` (and `80` for redirect) is published.
- The Docker Compose default bridge network already isolates inter-service traffic; the data services
  have **no** published ports in the compose file, so they are reachable only from sibling containers.
- If remote access is required, front the host with the customer's existing VPN or a reverse proxy in a
  DMZ — never publish the API directly without the customer's WAF/edge.

The privilege gradient from [DEPLOYMENT.md](./DEPLOYMENT.md) holds: within the compose network, `web`
and `ai` cannot reach the database because they hold no credentials and, additionally, the customer may
apply an internal firewall (`iptables`/`nftables`) rule denying `web`/`ai` container IPs egress to the
`postgres` container.

# Observability

Self-hosted observability is local-first, because a customer site may have no cloud telemetry egress:

- **Health:** `php artisan qayd:health` and Caddy's `/health` give an at-a-glance status; the installer
  can register a systemd timer that alerts (email/webhook) on a failing probe.
- **Metrics (optional):** the compose bundle ships an *optional* Prometheus + Grafana pair the customer
  can enable (`docker compose --profile metrics up -d`) for local dashboards; nothing is sent off-host.
- **Logs:** structured JSON to the Docker log driver; the customer ships them to their own SIEM if they
  have one. Audit logs remain durable in PostgreSQL per
  [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md).
- **Sentry (optional):** if the site permits outbound HTTPS, `SENTRY_DSN` can point at the customer's
  own Sentry; air-gapped sites leave it empty.

# Backup & DR

On a self-hosted node the customer owns backup and recovery entirely. The stack ships a scheduled
backup job; the customer is responsible for **copying backups off the host** (to a NAS, tape, or their
own object store) and for periodically testing a restore.

```bash
# /opt/qayd/backup.sh   (run nightly by a systemd timer the installer creates)
set -euo pipefail
STAMP=$(date +%Y%m%d-%H%M%S)
DEST=/var/qayd/backups/$STAMP
mkdir -p "$DEST"

# 1. PostgreSQL — logical dump (consistent, restorable anywhere)
docker compose exec -T postgres \
  pg_dump -U qayd_app -d qayd -Fc > "$DEST/qayd-db.dump"

# 2. Object storage — mirror the MinIO bucket
docker compose run --rm minio-mc \
  mc mirror local/qayd "$DEST/objects"

# 3. Config + secrets (encrypt this — it holds credentials)
gpg --encrypt -r backups@acme-corp.local -o "$DEST/env.gpg" /opt/qayd/.env

# 4. Prune local copies older than 14 days (off-host copy is the real retention)
find /var/qayd/backups -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
echo "backup $STAMP complete"
```

**Restore** onto a fresh host:

```bash
# 1. Install the same QAYD version, but do NOT create an admin yet
sudo bash install.sh --domain qayd.acme-corp.local --version 1.8.2 --no-start

# 2. Restore the database
cat qayd-db.dump | docker compose run --rm -T postgres \
  pg_restore -U qayd_app -d qayd --clean --if-exists

# 3. Restore objects
docker compose run --rm minio-mc mc mirror ./objects local/qayd

# 4. Start; the migration gate reconciles any version delta, then verify
docker compose up -d
docker compose exec api php artisan qayd:health
```

The DR posture for a single node is deliberately simpler than the cloud's cross-region model in
[MULTI_REGION.md](./MULTI_REGION.md): there is one site, so DR is "restore onto a spare host from an
off-host backup." Customers with stricter RTO/RPO needs should either run a warm standby host with
streaming replication (point a second `postgres` at the primary's WAL) or move to the managed model.
The **RPO is the backup interval** (nightly by default; tighten with more frequent dumps or continuous
WAL archiving to the NAS) and the **RTO is the time to provision a host and restore** (typically under
an hour for SME data volumes).

# Security

Self-hosting shifts more of the security surface onto the customer, so the shipped defaults are
hardened and the responsibilities are explicit.

- **No default credentials.** Every password/key is generated on install; the installer refuses to
  start with a placeholder value.
- **Web/AI hold no DB creds.** The [DEPLOYMENT.md](./DEPLOYMENT.md) privilege gradient is preserved on
  one host; a compromised web or AI container cannot read the ledger.
- **RLS still enforced.** PostgreSQL Row-Level Security is on regardless of node count; the app role is
  `NOBYPASSRLS` per [../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md). Most single-node
  installs are single-tenant, but multi-tenant self-hosting (below) relies on RLS exactly as cloud does.
- **Encryption at rest** is the customer's responsibility for the host disks (LUKS/dm-crypt or the
  hypervisor's encryption); application-layer field encryption of PII/bank numbers still applies per
  [../security/ENCRYPTION.md](../security/ENCRYPTION.md), with the field-KEK held in the local `.env`
  (or the customer's own KMS if they run one).
- **Patching is the customer's duty.** QAYD publishes signed release bundles; the customer applies them
  (see Upgrades). Base-image CVEs are addressed by pulling the new QAYD version, which rebuilds on
  patched bases.
- **`.env` is the crown jewel.** Mode `600`, root-owned, encrypted in backups; whoever reads it holds
  the database password and the field-KEK.

# Single-tenant vs multi-tenant self-host

QAYD supports both on a customer's own hardware; the difference is a licensing and configuration
choice, not a different build.

| | Single-tenant self-host | Multi-tenant self-host |
|---|---|---|
| Companies | one company (or one corporate group) | many independent companies on one instance |
| Who runs it | the customer, for themselves | a partner/reseller hosting for their clients |
| RLS role | still on; effectively one tenant | load-bearing — the boundary between clients |
| Backup granularity | whole instance | whole instance; per-company export via `qayd:export-company` |
| Typical size | SME / department | reseller platform, department shared services |

Multi-tenant self-hosting is the same shared-schema, RLS-enforced model as cloud
([../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)); the operator must treat RLS as the
security guarantee, not a convenience, and should run the tenant-isolation feature tests
([../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)) after any upgrade. A reseller
who needs hard physical isolation between clients runs one QAYD instance per client instead.

# Upgrades

Upgrades are the same migration-gated release as cloud, executed by the customer:

```bash
cd /opt/qayd
# 1. Back up FIRST (never upgrade without a fresh, tested backup)
./backup.sh

# 2. Pull the new version's images (or docker load the offline bundle)
sed -i 's/^QAYD_VERSION=.*/QAYD_VERSION=1.9.0/' .env
docker compose pull            # connected site
# docker load -i qayd-1.9.0-images.tar   # air-gapped

# 3. Recreate; the migrate service runs the (expand-only) migration gate first
docker compose up -d
docker compose logs -f migrate

# 4. Verify, then done
docker compose exec api php artisan qayd:health
```

Migrations are expand-only across a version, so if a smoke test fails the customer rolls back by
resetting `QAYD_VERSION` to the prior tag and `docker compose up -d` — the old code is still
schema-compatible. QAYD documents any release that requires a manual data step or a maintenance window
in its release notes; the default upgrade is zero-touch beyond these four commands.

# Air-gapped considerations

For sites with **no outbound internet** at all:

- **Images** arrive as an offline tarball (`docker load`), verified against a published checksum and
  signature.
- **AI is fully local.** `LLM_MODE=local` runs a bundled local model inside the AI container; `OCR_MODE
  =local` uses Tesseract. No LLM/OCR provider key is needed and no document text leaves the host —
  satisfying the strictest residency posture in [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md).
- **TLS** uses the customer's internal CA; there is no Let's Encrypt reachability.
- **Licensing** is offline: a signed license file (`qayd.license`) placed in `/opt/qayd/` is validated
  locally by the API against QAYD's public key — no phone-home. The license encodes the permitted user
  count and feature set and an expiry; the API enforces it at startup and warns before expiry. Because
  validation is offline and cryptographic, an air-gapped instance never needs to reach QAYD to keep
  running.
- **Updates** are delivered on physical/portable media as new offline bundles plus a new license if the
  term renewed.

# What's supported vs cloud-only

Some capabilities depend on QAYD's managed infrastructure and are unavailable, degraded, or
customer-supplied when self-hosting.

| Capability | Self-hosted | Notes |
|---|---|---|
| Full accounting / AI / reporting | supported | identical images |
| Local (offline) AI models | supported | required for air-gapped |
| Cloud LLM/OCR providers | optional | needs outbound HTTPS + customer's provider key |
| Object storage | supported (MinIO) | local; no data leaves host |
| Multi-region HA / auto-failover | **cloud-only** | single node has no cross-region story; see [MULTI_REGION.md](./MULTI_REGION.md) |
| Managed backups / PITR to two regions | **cloud-only** | self-host uses local dumps + off-host copy |
| Autoscaling | **cloud-only** | self-host scales vertically |
| QAYD-operated 24/7 SRE, patching | **cloud-only** | self-host is customer-operated |
| Third-party integrations (banks, gov APIs) | supported if reachable | require the site to allow the specific outbound endpoints |
| SSO / SAML / SCIM | supported | points at the customer's own IdP |
| Managed email/SMS delivery | customer-supplied | configure the customer's own SMTP/SMS gateway |

The rule of thumb: everything that is *product* (accounting correctness, isolation, AI, audit) is fully
supported on a customer's own hardware; everything that is *managed operations* (elastic scale,
cross-region DR, 24/7 SRE) is what a customer trades away by choosing to self-host. For customers who
want the product guarantees without the operational burden, [CLOUD.md](./CLOUD.md) is the managed path.

# End of Document
