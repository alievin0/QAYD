# Deployment Overview & Topology — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: DEPLOYMENT
---

# Purpose

This document is the master deployment specification for QAYD, the AI Financial Operating System. It
defines how the platform's three deployable services — the Laravel 12 backend API, the Next.js 15 web
client, and the FastAPI AI engine — together with their backing data plane (PostgreSQL, Redis, Laravel
Reverb, object storage, and queue workers) are containerized, wired together, released, and operated in
production. Every other document in this folder specializes one slice of what is established here:
[SELF_HOSTING.md](./SELF_HOSTING.md) collapses this topology onto a single customer-owned machine,
[CLOUD.md](./CLOUD.md) maps each component to a managed cloud equivalent, and
[MULTI_REGION.md](./MULTI_REGION.md) extends it across regions for high availability and data residency.
Where a specialized document is silent, this document governs.

The deployment model exists to preserve, at runtime, the same three properties the backend enforces at
the code level (see [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)):
**correctness** (a release never takes traffic against a schema it was not built for; migrations gate
the rollout), **isolation** (the web tier is unprivileged and cannot reach the database; only the
backend holds business-state credentials), and **authority** (secrets are injected at deploy time from a
vault, never baked into an image or shipped in the browser bundle). The authoritative stack is fixed by
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) and is not open to substitution here:
Next.js 15 on Node.js for web, Laravel 12 on PHP 8.4+ for the API, FastAPI on Python for the AI engine,
PostgreSQL 15 as the primary database, Redis for cache/session/queue/locks, Laravel Reverb for realtime
WebSockets, and Cloudflare R2 (or S3-compatible) for object storage.

This document does not restate the internal request lifecycle (that is
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md)), the database's replication
and backup mechanics ([../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md)),
or how secrets are custodied and rotated ([../security/SECRETS.md](../security/SECRETS.md)). It describes
the *shape of the running system* and the *procedure by which new code becomes that running system*.

The audience is the platform engineer building the CI/CD pipeline, the SRE running a production release,
and any engineer who needs to understand where their service runs and what it is allowed to talk to.

# Topology / Architecture

QAYD deploys as **three application tiers** in front of a **shared data plane**. The tiers are
stateless and horizontally scalable; the data plane is stateful and scaled vertically or by managed
service. The single most important structural fact is the privilege gradient: traffic flows
`client → web → API → data`, and **only the API tier holds credentials to the database, Redis, R2, and
the AI service key**. The web tier is an unprivileged renderer that calls `/api/v1` exactly as a
browser would; the AI engine is a peer the backend calls, never a component with database access.

```
                          Internet
                             │
                 ┌───────────┴───────────┐
                 │  Cloudflare  (CDN /    │
                 │  WAF / DDoS / TLS)     │
                 └───────────┬───────────┘
                             ▼
                     ┌───────────────┐
                     │ Load Balancer │  (L7, TLS termination inside VPC optional)
                     └───┬───────┬───┘
             ┌───────────┘       └───────────┐
             ▼                               ▼
     ┌───────────────┐               ┌───────────────┐
     │  WEB TIER     │  /api/v1 →    │  API TIER      │
     │  Next.js 15   │──────────────▶│  Laravel 12    │
     │  (Node.js)    │   (server-    │  (php-fpm+nginx)│
     │  SSR / BFF    │    side fetch)│  stateless     │
     └───────────────┘               └───┬───────┬────┘
             ▲                            │       │
             │ browser (public bundle,    │       │  HTTP + `ai` queue
             │ NEXT_PUBLIC_* only)        │       ▼
             │                            │  ┌───────────────┐
     ┌───────┴───────┐                    │  │  AI TIER      │
     │  Reverb LB    │◀───────────────────┤  │  FastAPI      │
     │  (WebSocket)  │   broadcast         │  │  (Python)     │
     └───────┬───────┘                    │  │  NO DB access │
             ▼                            │  └───────┬───────┘
     ┌───────────────┐                    │          │ internal /api/v1
     │ Reverb node(s)│                    │          │ (scoped service token)
     └───────────────┘                    │          └────────┐
                                          ▼                   ▼
        ┌──────────────────────────────────────────────────────────┐
        │                      DATA PLANE                            │
        │                                                            │
        │  PostgreSQL 15        Redis            Object Storage      │
        │  primary + replica   (cache/queue/     (R2 / S3)          │
        │                       session/locks)                       │
        │                                                            │
        │  Queue Workers  ── consume Redis ──▶ realtime|default|ai|  │
        │  (per-queue)                          reports|integrations │
        │                                                            │
        │  Scheduler node (single schedule:run cron)                 │
        └──────────────────────────────────────────────────────────┘
```

Roles and why they are separated:

| Tier / role | Service | State | Scales on | Holds DB creds? |
|---|---|---|---|---|
| Edge | Cloudflare | none | managed | no |
| Load balancer | ALB / nginx | none | managed | no |
| Web | Next.js 15 (Node) | none | request latency, CPU | **no** |
| API | Laravel 12 (php-fpm) | none (Redis-backed sessions) | request latency, CPU | yes |
| Realtime | Laravel Reverb | WS connections | concurrent connections | Redis only |
| Workers | Laravel queue workers | none | backlog depth per queue | yes |
| Scheduler | Laravel `schedule:run` | none (singleton) | fixed 1 | yes |
| AI | FastAPI (Python) | none | request/queue backlog | **no** (calls API) |
| DB | PostgreSQL 15 | durable | vertical + read replica | — |
| Cache/queue | Redis | ephemeral/durable-queue | memory, ops/s | — |
| Objects | R2 / S3 | durable | managed | — |

The web tier and the API tier are two distinct deployables precisely so that the browser-facing
renderer never becomes a privileged component. If the Next.js process is compromised, it holds no
database credentials and no AI service key — it can only make the same permission-checked `/api/v1`
calls a user could. This is the deployment-time expression of the "one API, many surfaces" rule in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md).

# Prerequisites

Before any environment can be provisioned, the following must exist. These are the invariants every
deployment target (single-node, Kubernetes, managed cloud) shares.

**Tooling.**

| Tool | Minimum version | Used for |
|---|---|---|
| Docker Engine | 24+ | building and running images |
| Docker Compose | v2.20+ | single-node and local orchestration |
| Kubernetes | 1.28+ | production orchestration |
| Helm | 3.14+ | packaging the production release |
| Terraform | 1.7+ | provisioning cloud data-plane resources |
| PostgreSQL client | 15 | migration gate, DR drills |
| Node.js | 20 LTS | building the web tier |
| PHP | 8.4 | building the API tier |
| Python | 3.12 | building the AI tier |

**Data plane reachable.** A PostgreSQL 15 primary (with a read replica in production), a Redis
instance, and an S3-compatible bucket must be provisioned and reachable from the API tier's network
segment before the first release. See [../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md).

**Secrets available.** A secrets source (environment injection in dev, a vault/secrets-manager in
staging/production) must hold every key in the [Configuration & Secrets](#configuration--secrets)
table. No image is ever built with secrets inside it. See [../security/SECRETS.md](../security/SECRETS.md).

**DNS and TLS.** Public hostnames (`app.qayd.io`, `api.qayd.io`, `ws.qayd.io`, `ai.qayd.internal`)
resolve and have certificates — via Cloudflare at the edge and/or cert-manager inside the cluster.

**Migration authority.** A database role permitted to run DDL (`qayd_migrator`) that is *distinct*
from the runtime application role (`qayd_app`, which is `NOBYPASSRLS` and has no DDL grant). The
migration gate uses `qayd_migrator`; running pods use `qayd_app`.

# Components & Services

## The three deployables

Each service ships as its own OCI image built from its own Dockerfile. They share nothing at build time
and communicate only over the network.

### API tier — Laravel 12 (`backend/Dockerfile`)

A multi-stage build: Composer install with a full toolchain in the builder stage, then a slim runtime
image running php-fpm behind nginx (or a single `php-fpm` image fronted by an nginx sidecar in
Kubernetes). The **same image** runs as web dyno, queue worker, and scheduler — only the container
command differs, which guarantees the three roles run identical code and config.

```dockerfile
# backend/Dockerfile
# ---- builder ----
FROM php:8.4-fpm-alpine AS builder
RUN apk add --no-cache $PHPIZE_DEPS git unzip icu-dev postgresql-dev linux-headers \
 && docker-php-ext-install pdo_pgsql intl bcmath pcntl opcache \
 && pecl install redis && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
 && php artisan config:clear

# ---- runtime ----
FROM php:8.4-fpm-alpine AS runtime
RUN apk add --no-cache icu-libs postgresql-libs \
 && docker-php-ext-install pdo_pgsql intl bcmath pcntl opcache \
 && pecl install redis && docker-php-ext-enable redis
WORKDIR /app
COPY --from=builder /app /app
COPY deploy/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
# Non-root runtime user
RUN addgroup -g 1000 qayd && adduser -D -u 1000 -G qayd qayd \
 && chown -R qayd:qayd /app/storage /app/bootstrap/cache
USER qayd
EXPOSE 9000
# Default command = php-fpm (web role). Worker/scheduler override the command.
CMD ["php-fpm", "-F"]
```

The container command selects the role:

| Role | Container command |
|---|---|
| Web (HTTP) | `php-fpm -F` (fronted by nginx) |
| Queue worker | `php artisan queue:work redis --queue=<queue> --max-time=3600 --tries=3` |
| Scheduler | `supercronic` running `php artisan schedule:run` every minute (singleton) |
| Reverb | `php artisan reverb:start --host=0.0.0.0 --port=8080` |

### Web tier — Next.js 15 (`web/Dockerfile`)

A standalone Next.js output build. The critical deployment rule: **only `NEXT_PUBLIC_*` variables reach
the browser bundle.** Every other value the web tier needs (the internal API URL, any server-side
token) is read at runtime by the Node server process, never inlined at build. Build-time public vars
are baked; server-only vars are injected as runtime env into the container.

```dockerfile
# web/Dockerfile
# ---- deps ----
FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# ---- builder ----
FROM node:20-alpine AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# ONLY public values are baked into the client bundle at build time.
ARG NEXT_PUBLIC_API_BASE_URL
ARG NEXT_PUBLIC_REVERB_HOST
ARG NEXT_PUBLIC_APP_ENV
RUN npm run build           # next.config.js: output: 'standalone'

# ---- runtime ----
FROM node:20-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production
RUN addgroup -g 1001 nodejs && adduser -D -u 1001 -G nodejs nextjs
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public ./public
USER nextjs
EXPOSE 3000
CMD ["node", "server.js"]
```

### AI tier — FastAPI (`ai/Dockerfile`)

A Python image serving FastAPI under Uvicorn/Gunicorn. It holds no database credentials; its only
outbound authority is the internal `/api/v1` (called with a short-lived scoped service token) plus the
LLM/OCR provider keys. See [../ai/AI_ARCHITECTURE.md](../ai/AI_ARCHITECTURE.md).

```dockerfile
# ai/Dockerfile
FROM python:3.12-slim AS runtime
ENV PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/*
COPY requirements.txt ./
RUN pip install -r requirements.txt
COPY . .
RUN useradd -u 1000 -m qayd && chown -R qayd /app
USER qayd
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
  CMD curl -fsS http://localhost:8000/health || exit 1
CMD ["gunicorn", "app.main:app", "-k", "uvicorn.workers.UvicornWorker", \
     "-w", "4", "-b", "0.0.0.0:8000", "--timeout", "60"]
```

## The data plane

| Component | Role | Notes |
|---|---|---|
| PostgreSQL 15 | source of truth | primary + read replica; RLS-enforced multi-tenancy |
| Redis | cache, session, queue, locks | one logical instance, multiple DBs or key prefixes |
| Object storage | PDFs, invoices, attachments, reports | Cloudflare R2 or S3; API tier holds the keys |
| Laravel Reverb | WebSocket broadcast | reads payloads from Redis; stateless per-connection |

# Configuration & Secrets

Configuration is 12-factor: everything that varies between environments is an environment variable, and
**secrets are injected at deploy time from a vault, never committed and never baked into an image.** The
canonical secret custody model is [../security/SECRETS.md](../security/SECRETS.md); this section lists
what each service consumes.

| Variable | Consumed by | Public? | Purpose |
|---|---|---|---|
| `APP_KEY` | API | secret | Laravel encryption key |
| `APP_URL` | API | config | canonical API URL |
| `DB_HOST`,`DB_PORT`,`DB_DATABASE`,`DB_USERNAME`,`DB_PASSWORD` | API, workers, scheduler | secret | Postgres primary |
| `DB_READ_HOST` | API, workers | config | read replica DSN |
| `REDIS_HOST`,`REDIS_PASSWORD` | API, workers, Reverb | secret | cache/queue/session |
| `QUEUE_CONNECTION=redis`,`CACHE_STORE=redis`,`SESSION_DRIVER=redis` | API, workers | config | drivers |
| `REVERB_APP_ID`,`REVERB_APP_KEY`,`REVERB_APP_SECRET`,`REVERB_HOST` | API, Reverb | secret | broadcasting |
| `AI_ENGINE_BASE_URL` | API | config | FastAPI base URL |
| `AI_ENGINE_SERVICE_KEY` | API | secret | signs scoped internal service tokens |
| `QAYD_API_INTERNAL_URL` | AI | config | internal `/api/v1` base |
| `LLM_PROVIDER_KEY`,`OCR_PROVIDER_KEY` | AI | secret | reasoning / document extraction |
| `R2_ACCESS_KEY_ID`,`R2_SECRET_ACCESS_KEY`,`R2_BUCKET`,`R2_ENDPOINT` | API, workers | secret | object storage |
| `MAILGUN_*` / `SES_*`, `TWILIO_*` | API, workers | secret | email / SMS gateways |
| `SENTRY_DSN` | all | config | error tracking |
| `NEXT_PUBLIC_API_BASE_URL` | Web (browser) | **public** | API base for browser fetches |
| `NEXT_PUBLIC_REVERB_HOST` | Web (browser) | **public** | WebSocket host |

The `NEXT_PUBLIC_*` prefix is the deployment-enforced boundary: the Next.js build inlines only those,
so a leaked web image or a viewed browser bundle exposes nothing beyond public endpoints. A CI check
fails the web build if any non-`NEXT_PUBLIC_*` secret name appears in the client bundle. See
[SELF_HOSTING.md](./SELF_HOSTING.md) and [CLOUD.md](./CLOUD.md) for how the secret source differs by
target.

In production, Laravel config is cached (`php artisan config:cache`) so no `env()` is read outside
config files at runtime; this means **config cache must be rebuilt on every deploy** after env changes.

# Step-by-Step / Runbook

## Reference single-node deploy (docker-compose)

The reference single-node topology runs every service on one host. It is the basis for self-hosting
(see [SELF_HOSTING.md](./SELF_HOSTING.md)) and for staging smoke tests. It is **not** the production
shape — production uses Kubernetes (below) — but it is the canonical, runnable definition of how the
services fit together.

```yaml
# docker-compose.yml  (reference single-node)
x-api-env: &api-env
  APP_ENV: production
  DB_HOST: postgres
  DB_DATABASE: qayd
  DB_USERNAME: qayd_app
  DB_PASSWORD: ${DB_PASSWORD}
  REDIS_HOST: redis
  QUEUE_CONNECTION: redis
  CACHE_STORE: redis
  SESSION_DRIVER: redis
  AI_ENGINE_BASE_URL: http://ai:8000
  AI_ENGINE_SERVICE_KEY: ${AI_ENGINE_SERVICE_KEY}
  R2_ENDPOINT: ${R2_ENDPOINT}
  R2_BUCKET: ${R2_BUCKET}
  R2_ACCESS_KEY_ID: ${R2_ACCESS_KEY_ID}
  R2_SECRET_ACCESS_KEY: ${R2_SECRET_ACCESS_KEY}

services:
  postgres:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: qayd
      POSTGRES_USER: qayd_app
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes: [pgdata:/var/lib/postgresql/data]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U qayd_app -d qayd"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    command: ["redis-server", "--requirepass", "${REDIS_PASSWORD}", "--appendonly", "yes"]
    volumes: [redisdata:/data]
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  migrate:                       # migration gate — runs once, must exit 0 before api starts
    image: qayd/api:${TAG}
    command: ["php", "artisan", "migrate", "--force", "--isolated"]
    environment: *api-env
    depends_on:
      postgres: { condition: service_healthy }

  api:
    image: qayd/api:${TAG}
    command: ["php-fpm", "-F"]
    environment: *api-env
    depends_on:
      migrate: { condition: service_completed_successfully }
      redis: { condition: service_healthy }
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://localhost:9000/../ || php artisan qayd:health"]
      interval: 15s

  worker:
    image: qayd/api:${TAG}
    command: ["php", "artisan", "queue:work", "redis",
              "--queue=realtime,default,ai,reports,integrations,maintenance",
              "--max-time=3600", "--tries=3"]
    environment: *api-env
    depends_on:
      migrate: { condition: service_completed_successfully }
    deploy: { replicas: 2 }

  scheduler:
    image: qayd/api:${TAG}
    command: ["sh", "-c", "supercronic /app/deploy/cron/schedule.cron"]
    environment: *api-env
    depends_on:
      migrate: { condition: service_completed_successfully }

  reverb:
    image: qayd/api:${TAG}
    command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
    environment: *api-env
    ports: ["8080:8080"]

  ai:
    image: qayd/ai:${TAG}
    environment:
      QAYD_API_INTERNAL_URL: http://api:9000
      LLM_PROVIDER_KEY: ${LLM_PROVIDER_KEY}
      OCR_PROVIDER_KEY: ${OCR_PROVIDER_KEY}
    depends_on:
      api: { condition: service_started }

  web:
    image: qayd/web:${TAG}
    environment:
      NEXT_PUBLIC_API_BASE_URL: ${NEXT_PUBLIC_API_BASE_URL}
      NEXT_PUBLIC_REVERB_HOST: ${NEXT_PUBLIC_REVERB_HOST}
    ports: ["3000:3000"]
    depends_on:
      api: { condition: service_started }

volumes:
  pgdata:
  redisdata:
```

The `migrate` service is the **migration gate**: `api`, `worker`, and `scheduler` all declare
`depends_on: migrate: service_completed_successfully`, so no application container serves traffic or
processes a job until the schema matches the image. This is the compose-level expression of the
release rule below.

## Production shape (Kubernetes / Helm)

In production every role is a separate Kubernetes `Deployment` (or `StatefulSet` for Reverb), driven by
one Helm chart. The chart renders one image, five workloads, and a pre-install/pre-upgrade migration
Job.

```yaml
# helm/qayd/values.yaml  (excerpt)
image:
  registry: registry.qayd.io
  api: { repository: qayd/api, tag: "" }      # tag set per release
  web: { repository: qayd/web, tag: "" }
  ai:  { repository: qayd/ai,  tag: "" }

api:
  replicas: 3
  resources: { requests: { cpu: 500m, memory: 512Mi }, limits: { cpu: "2", memory: 1Gi } }
  autoscaling: { enabled: true, minReplicas: 3, maxReplicas: 20, targetCPUUtilizationPercentage: 65 }

workers:
  - { name: realtime,     queue: "realtime",     replicas: 2, maxReplicas: 6 }
  - { name: default,      queue: "default",      replicas: 3, maxReplicas: 12 }
  - { name: ai,           queue: "ai",           replicas: 2, maxReplicas: 10 }
  - { name: reports,      queue: "reports",      replicas: 2, maxReplicas: 8 }
  - { name: integrations, queue: "integrations", replicas: 1, maxReplicas: 4 }

scheduler:
  replicas: 1                                   # MUST be exactly 1 — cron singleton

reverb:
  replicas: 2
  service: { type: ClusterIP, port: 8080 }

web:
  replicas: 3
  autoscaling: { enabled: true, minReplicas: 3, maxReplicas: 12 }

ai:
  replicas: 2
  autoscaling: { enabled: true, minReplicas: 2, maxReplicas: 8 }
```

```yaml
# helm/qayd/templates/migrate-job.yaml  (the migration gate)
apiVersion: batch/v1
kind: Job
metadata:
  name: {{ .Release.Name }}-migrate-{{ .Values.image.api.tag }}
  annotations:
    "helm.sh/hook": pre-install,pre-upgrade
    "helm.sh/hook-weight": "-5"
    "helm.sh/hook-delete-policy": before-hook-creation
spec:
  backoffLimit: 0                # a failed migration fails the release; nothing rolls out
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: migrate
          image: "{{ .Values.image.registry }}/{{ .Values.image.api.repository }}:{{ .Values.image.api.tag }}"
          command: ["php","artisan","migrate","--force","--isolated"]
          envFrom:
            - secretRef: { name: qayd-api-secrets }
            - configMapRef: { name: qayd-api-config }
```

Because the migration Job is a Helm `pre-upgrade` hook with `backoffLimit: 0`, **a failed migration
aborts the release before a single new pod takes traffic.** The old pods keep serving; the deploy is a
no-op. This is non-negotiable for a system of record.

The API Deployment declares liveness and readiness probes so the rollout only shifts traffic to pods
that are actually ready (DB, Redis, R2, and AI-engine reachable):

```yaml
# helm/qayd/templates/api-deployment.yaml  (probes excerpt)
readinessProbe:
  httpGet: { path: /health/ready, port: 9000 }
  initialDelaySeconds: 5
  periodSeconds: 10
  failureThreshold: 3
livenessProbe:
  httpGet: { path: /health/live, port: 9000 }
  initialDelaySeconds: 10
  periodSeconds: 15
strategy:
  type: RollingUpdate
  rollingUpdate: { maxSurge: 25%, maxUnavailable: 0 }   # zero-downtime
```

`routes/health.php` (see [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md))
distinguishes liveness (process up) from readiness (dependencies reachable), so a pod whose database
connection is not yet warm is never sent traffic.

## Release procedure (runbook)

The standard production release is a **rolling deploy gated by migrations**. For risky schema or
runtime changes, use the blue-green variant (below).

1. **Build & tag.** CI builds all three images from the merge commit, tags them with the immutable
   short SHA, runs the test suites, and pushes to the registry. (See [CI/CD](#cicd).)
2. **Promote.** A human (or an auto-promotion rule) sets `image.*.tag=<sha>` for the target
   environment's Helm values.
3. **Migration gate.** `helm upgrade` runs the pre-upgrade migration Job. Migrations must be
   **expand-only / backward-compatible** (add column, add nullable, backfill async) so the currently
   running old code keeps working against the new schema. Destructive changes are a later, separate
   "contract" migration after the new code is fully rolled out.
4. **Rolling update.** New pods start, pass readiness, and receive traffic; old pods drain
   (`maxUnavailable: 0`). Workers finish in-flight jobs (`--max-time` + `SIGTERM` grace) before exit.
5. **Verify.** Smoke checks hit `/health/ready`, a canary `/api/v1` read, and the web homepage. Error
   rate and latency are watched on the dashboards for the bake window.
6. **Rollback if needed.** `helm rollback <release>` returns pods to the previous image. Because
   migrations were expand-only, the old code is still schema-compatible — rollback is safe without a
   down-migration.

## Zero-downtime & blue-green

For the common case, the rolling strategy above with `maxUnavailable: 0` and expand-only migrations is
zero-downtime. For high-risk releases, a blue-green cut is used:

```
              ┌──────── blue (current, live) ───────┐
   LB weight  │ api-blue  web-blue  ai-blue          │  100%
              └─────────────────────────────────────┘
   (deploy green alongside, run migration gate, warm, smoke)
              ┌──────── green (new, dark) ───────────┐
              │ api-green web-green ai-green          │  0% → 10% → 100%
              └─────────────────────────────────────┘
   flip LB weight; keep blue warm for the bake window; then scale blue to 0
```

Green shares the same data plane as blue, so migrations must remain compatible with both colors while
both are live — the same expand-only rule. The flip is a load-balancer weight change, instantly
reversible during the bake window.

# Scaling

The tiers scale independently because they are stateless and Redis-backed; the data plane scales by a
different discipline.

| Component | Scaling signal | Mechanism |
|---|---|---|
| Web (Next.js) | request latency, CPU | HPA, `minReplicas` floor |
| API (Laravel) | request latency, CPU | HPA; any pod serves any request (Redis sessions) |
| Reverb | concurrent WS connections | HPA on connection count; sticky-less (Redis pub/sub) |
| Queue workers | backlog depth **per queue** | KEDA/HPA per queue; `ai`/`reports` scale without starving `realtime` |
| Scheduler | none | fixed at 1 (singleton) — never scale |
| AI engine | request + `ai`-queue backlog | HPA; an outage degrades AI features only |
| PostgreSQL | read load | add read replicas; writes stay on primary; vertical for write throughput |
| Redis | memory, ops/s | vertical, then cluster mode |

The scaling story mirrors [../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md): the
`realtime` queue must stay near-empty, so it is scaled aggressively and isolated from bulk `ai`/`reports`
work. The scheduler is the one component that must **never** run more than one replica, or cron jobs
fire twice; the Helm chart hard-pins `scheduler.replicas: 1` and uses a leader lock as defense in depth.

# Networking

The network enforces the privilege gradient. Only the load balancer is internet-facing; the data plane
is never reachable from outside the cluster/VPC.

```
Internet ──▶ Cloudflare ──▶ LB (public subnet)
                              │
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
   web pods            api pods              reverb pods     (app subnet, private)
        │                     │                     │
        └──── /api/v1 ────────┤                     │
                              ▼                     │
                        ai pods (app subnet)        │
                              │                     │
        ┌─────────────────────┴──────────┬──────────┘
        ▼                                ▼
   PostgreSQL / Redis / R2       (data subnet, private, no egress to internet)
```

Enforced with Kubernetes `NetworkPolicy` (or VPC security groups): the data-subnet accepts connections
**only** from the API tier, workers, scheduler, and Reverb — never from web or AI pods. The AI pod may
reach the API tier and the LLM/OCR provider egress, nothing else. TLS terminates at Cloudflare and again
at the ingress; internal service-to-service traffic runs over the cluster network (mTLS optional via
service mesh). Public hostnames map: `app.qayd.io → web`, `api.qayd.io → api`, `ws.qayd.io → reverb`,
`ai.qayd.internal → ai` (never public).

# Observability

Every deployment target ships the same observability surface (see
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md) and TECH_STACK):

- **Health probes.** `/health/live` and `/health/ready` on the API; `/health` on the AI engine; a
  `next` health route on the web tier. The orchestrator uses these for readiness gating and restarts.
- **Metrics.** Prometheus scrapes request latency, queue depth per queue, job runtime, DB pool usage,
  AI-engine call latency/error rate, Reverb connection count, and web SSR latency. Grafana dashboards
  visualize them; alerts fire on queue backlog, error-rate spikes, and probe failures.
- **Errors.** Sentry receives unhandled exceptions from all three services, tagged with `request_id`,
  company id (never PII), and release SHA — so an error is tied to the exact deployed version.
- **Correlation.** `X-Request-Id` propagates client → web → API → AI engine → queues, so one id traces
  a request across every deployable.
- **Logs.** Structured JSON to stdout, shipped by the platform log agent; audit logs are durable and
  separate (see [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)).

Release health is judged on the four golden signals plus queue backlog during the bake window; a
regression aborts the rollout.

# Backup & DR

Deployments are cattle; the data plane is not. Application tiers can be rebuilt from images at any time,
so DR planning centers on PostgreSQL, Redis (queue durability), and object storage.

- **PostgreSQL** is backed up and replicated per
  [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md): pgBackRest
  full/differential + continuous WAL archiving, a synchronous standby, and an async cross-region DR
  standby. Point-in-time recovery is the primary restore path.
- **Object storage** (R2/S3) uses versioning and cross-region replication; attachments are immutable
  once written.
- **Redis** holds queues; workers' jobs are idempotent and money-affecting jobs carry natural
  idempotency keys, so a Redis loss re-drives safely rather than double-posting.
- **Stateless tiers** need no backup — a redeploy from a tagged image plus injected secrets fully
  reconstitutes web, API, workers, scheduler, Reverb, and AI.

Cross-region failover, RTO/RPO targets, and the failover runbook are specified in
[MULTI_REGION.md](./MULTI_REGION.md).

# Security

Deployment-time security controls that back the code-level model:

- **Least privilege by tier.** Only API/workers/scheduler/Reverb hold data-plane credentials; web and
  AI hold none. Network policy enforces it independently of credentials.
- **Secrets at deploy time only.** Injected from a vault/secrets-manager as env or mounted files; never
  in images, never in the repo, never in the browser bundle (`NEXT_PUBLIC_*` boundary). Rotation per
  [../security/SECRETS.md](../security/SECRETS.md).
- **Distinct DB roles.** `qayd_migrator` (DDL, migration gate only) vs `qayd_app` (`NOBYPASSRLS`,
  runtime, no DDL). RLS is the tenant boundary of last resort per
  [../security/TENANT_ISOLATION.md](../security/TENANT_ISOLATION.md).
- **Non-root containers.** All three images run as an unprivileged UID; read-only root filesystem where
  possible; drop all Linux capabilities.
- **Image provenance.** Images are built in CI from a pinned base, scanned for CVEs, and referenced by
  immutable digest in production; the registry is private.
- **Edge protection.** Cloudflare WAF, DDoS, and TLS in front of every public hostname.

# CI/CD

CI/CD is GitHub Actions per TECH_STACK. The pipeline builds and tests all three deployables and deploys
via Helm with the migration gate.

```yaml
# .github/workflows/deploy.yml  (excerpt)
name: build-test-deploy
on:
  push: { branches: [main] }
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres: { image: postgres:15, env: { POSTGRES_PASSWORD: test }, ports: ["5432:5432"] }
      redis:    { image: redis:7, ports: ["6379:6379"] }
    steps:
      - uses: actions/checkout@v4
      - name: Backend — Pest + PHPStan + Pint
        run: |
          composer install --no-interaction
          ./vendor/bin/pint --test
          ./vendor/bin/phpstan analyse --no-progress
          php artisan test --parallel
      - name: Web — typecheck + lint + build + no-secret check
        run: |
          npm ci && npm run typecheck && npm run lint && npm run build
          ./scripts/assert-no-server-secrets-in-bundle.sh   # fails if a non-NEXT_PUBLIC_ secret leaks
      - name: AI — ruff + pytest
        run: pip install -r ai/requirements.txt && ruff check ai && pytest ai

  build:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build & push images (immutable SHA tag)
        run: |
          TAG=${GITHUB_SHA::12}
          for svc in api web ai; do
            docker build -t registry.qayd.io/qayd/$svc:$TAG -f $svc/Dockerfile .
            docker push registry.qayd.io/qayd/$svc:$TAG
          done

  deploy-staging:
    needs: build
    runs-on: ubuntu-latest
    environment: staging
    steps:
      - name: Helm upgrade (runs pre-upgrade migration gate)
        run: |
          TAG=${GITHUB_SHA::12}
          helm upgrade --install qayd ./helm/qayd \
            --namespace qayd --create-namespace \
            --set image.api.tag=$TAG --set image.web.tag=$TAG --set image.ai.tag=$TAG \
            --wait --atomic --timeout 10m
```

`--atomic` makes the Helm release self-rolling-back on failure; `--wait` blocks until pods are ready.
Because the migration Job is a `pre-upgrade` hook with `backoffLimit: 0`, a migration failure fails the
whole `helm upgrade` and no new code takes traffic. Production promotion is a manual approval gate that
reuses the same job against the `production` environment and, for high-risk changes, the blue-green flow.

Environments:

| Environment | Purpose | Data plane | Promotion |
|---|---|---|---|
| local | developer laptop | docker-compose, disposable | n/a |
| staging | pre-prod verification | managed, prod-like, synthetic data | automatic on merge to `main` |
| production | live customers | managed HA, real data | manual approval; blue-green for risky changes |

# End of Document
