# AWS Deployment — QAYD Deployment
Version: 1.0
Status: Design Specification
Module: Deployment
Submodule: AWS
---

# Purpose

This document specifies how QAYD is deployed on **Amazon Web Services**: how each of the platform's
runtime components maps onto a concrete AWS managed service, how the network is drawn and locked down,
how the estate is provisioned as code, and how it is operated, scaled, observed, and recovered. It is
the AWS-specific realization of the deployment topology sketched in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md#deployment-topology) and the
system decomposition in [../foundation/SYSTEM_ARCHITECTURE.md](../foundation/SYSTEM_ARCHITECTURE.md).
Where this document and a foundation document disagree on *what a component is*, the foundation
document governs; this document governs only *how that component runs on AWS*.

QAYD is not a single application. It is three independently deployable services — the Laravel 12
(PHP 8.4) **API**, the Next.js 15 **web** tier, and the FastAPI/Python **AI engine** — plus the
stateful and supporting infrastructure they depend on: a PostgreSQL 15 primary database, a Redis
cache/queue/lock tier, Laravel Reverb WebSocket nodes, object storage, and a fleet of queue workers
and one scheduler. The authoritative stack is fixed by
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) and is not open to substitution here; this
document only chooses the AWS services that host it.

Three platform facts shape every decision below and are repeated because they are load-bearing:

1. **The web tier is unprivileged.** The Next.js bundle is public and may carry only `NEXT_PUBLIC_*`
   values. No AWS secret, database credential, or signing key is ever placed in the web tier's client
   bundle. The web tier's server runtime (SSR/BFF) holds only the secrets it needs to call the API,
   nothing more. See [../security/SECRETS.md](../security/SECRETS.md).
2. **Multi-tenancy is single-database with row-level security.** All tenants share one PostgreSQL
   cluster; isolation is enforced by RLS (`SET LOCAL app.current_company_id`) inside the API, per
   [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
   [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md). The deployment does **not**
   shard tenants across databases; it protects the one database accordingly.
3. **Data residency is a first-class constraint.** QAYD serves GCC customers and the default home
   region is **me-central-1 (UAE, Dubai)**, with **me-south-1 (Bahrain)** as the alternate GCC region.
   Customer financial data — the RDS cluster, S3 buckets holding documents, and backups — must remain
   in the chosen GCC region unless a signed data-processing agreement says otherwise. Region selection
   is a per-tenant-cohort deployment decision, not a runtime toggle.

This document does not restate business rules, the API wire contract, or the cryptographic key
hierarchy. It is the operational counterpart to the architecture documents and the AWS sibling of
[AZURE.md](../deployment/AZURE.md) and [GCP.md](../deployment/GCP.md).

# Reference Architecture

The estate is a single VPC per region with public, private-application, and private-data subnet tiers
across three Availability Zones. Only the load balancers live in public subnets; every workload and
every datastore lives in private subnets and reaches the internet, when it must, through NAT.

```
                        Route 53 (qayd.com, api.qayd.com, ws.qayd.com)
                                        │
                                        ▼
                         CloudFront (CDN, TLS via ACM, WAF)
                                        │
              ┌─────────────────────────┼──────────────────────────┐
              ▼                         ▼                           ▼
      static/web assets          ALB  (api.qayd.com)        ALB/NLB (ws.qayd.com)
      (S3 origin, OAC)           HTTPS 443                  WebSocket 443
                                        │                           │
   ┌────────────────────────── VPC (me-central-1) ─────────────────────────────────┐
   │  Public subnets  (AZ-a / AZ-b / AZ-c):  ALB nodes, NAT gateways                │
   │                                                                                │
   │  Private-app subnets (AZ-a / AZ-b / AZ-c):                                     │
   │    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
   │    │ web  (Next)  │  │ api (Laravel)│  │ ai (FastAPI) │  │ reverb (WS)  │     │
   │    │ ECS Fargate  │  │ ECS Fargate  │  │ ECS Fargate  │  │ ECS Fargate  │     │
   │    └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘     │
   │    ┌──────────────────────────────────────────────────┐                       │
   │    │ queue workers  (per-queue ECS services)          │  scheduler (1 task)   │
   │    │ realtime | default | ai | reports | integrations │                       │
   │    └──────────────────────────────────────────────────┘                       │
   │                                                                                │
   │  Private-data subnets (AZ-a / AZ-b / AZ-c):                                    │
   │    ┌──────────────┐  ┌──────────────┐   VPC endpoints: S3 (gateway),           │
   │    │ RDS Postgres │  │ ElastiCache  │   Secrets Manager, ECR, CloudWatch,      │
   │    │ Multi-AZ +   │  │ Redis (repl) │   SQS (interface, private)               │
   │    │ read replica │  │              │                                          │
   │    └──────────────┘  └──────────────┘                                          │
   └────────────────────────────────────────────────────────────────────────────────┘
              │
              ▼
      S3 (documents bucket, SSE-KMS)  ·  Secrets Manager  ·  ECR  ·  CloudWatch/X-Ray
```

The three services and the Reverb/worker/scheduler roles run as **ECS services on AWS Fargate** —
serverless containers, no EC2 hosts to patch. Fargate is the default; a note at the end of
[Service Mapping](#service-mapping) covers when to move the AI engine (GPU) or a cost-sensitive
worker fleet to EKS or EC2. The datastores are managed: **RDS for PostgreSQL** (Multi-AZ, one or more
read replicas) and **ElastiCache for Redis** (replication group, Multi-AZ). Object storage is **S3**
with server-side KMS encryption and time-limited pre-signed URLs. Realtime is Laravel Reverb running
as its own ECS service behind a WebSocket-capable ALB; the Redis-backed queues are optionally
augmented or replaced with **SQS** for the `integrations` queue where at-least-once managed delivery
and a dead-letter queue are wanted.

Everything the browser touches (web assets, API, WebSocket) is fronted by CloudFront and Route 53,
terminating TLS with ACM certificates and filtered by AWS WAF. Cloudflare from TECH_STACK can sit in
front of CloudFront as the public edge; the AWS-native path below is complete on its own.

# Service Mapping

Every QAYD component maps to exactly one primary AWS service. This table is the contract the rest of
the document elaborates.

| QAYD component | AWS service | Configuration notes |
|---|---|---|
| Web tier (Next.js 15, Node SSR) | ECS Fargate service `qayd-web` + CloudFront (S3 origin for static) | Node server for SSR/BFF; static export to S3 behind CloudFront OAC. Holds only `NEXT_PUBLIC_*` + an API base URL. Unprivileged. |
| API (Laravel 12, PHP 8.4, php-fpm+nginx) | ECS Fargate service `qayd-api` | Task = nginx + php-fpm containers. Stateless. Behind ALB `api.qayd.com`. Autoscaled on CPU + ALB request count. |
| AI engine (FastAPI, Python) | ECS Fargate service `qayd-ai` (Fargate; EKS/EC2-GPU if models run in-process) | Reached only from the API's security group and the internal API. No database ingress. |
| Realtime (Laravel Reverb, WebSockets) | ECS Fargate service `qayd-reverb` behind ALB (WebSocket) | Sticky not required (state in Redis); scale on concurrent connections. Separate listener `ws.qayd.com`. |
| Queue workers (`realtime`,`default`,`ai`,`reports`,`integrations`,`maintenance`) | One ECS Fargate service **per queue** (`qayd-worker-<queue>`) | `php artisan queue:work` per service; each autoscaled independently on backlog depth. |
| Scheduler (`schedule:run`) | ECS Fargate service `qayd-scheduler`, **desired count fixed at 1** | Single task so cron never double-fires; work is dispatched onto queues. |
| Primary database (PostgreSQL 15) | Amazon RDS for PostgreSQL, Multi-AZ | Instance in private-data subnets; one AZ standby; RLS enabled; `rds.force_ssl=1`. |
| Read replica(s) | RDS read replica(s) | Reporting/`reports` queue reads routed here via a read DSN. |
| Cache / queue backend / locks | Amazon ElastiCache for Redis (cluster-mode-disabled replication group, Multi-AZ) | Sessions, cache, Laravel queues, `Cache::lock`, Reverb pub/sub. Encryption in-transit + at-rest. |
| Object storage (documents, PDFs, exports) | Amazon S3 (`qayd-<env>-documents`) | SSE-KMS; Block Public Access on; access only via pre-signed URLs minted by the API. |
| Managed secrets store | AWS Secrets Manager (+ SSM Parameter Store for non-secret config) | Rotation for DB creds and JWT signing key; injected into tasks at start, never baked into images. |
| Container registry | Amazon ECR (`qayd/api`, `qayd/web`, `qayd/ai`, `qayd/reverb`) | Immutable tags, scan-on-push, lifecycle policy to expire old images. |
| Managed realtime/queue alternative | Amazon SQS (+ DLQ) for `integrations` | Optional: durable at-least-once delivery for webhook/bank-sync jobs. |
| CDN / TLS / WAF | CloudFront + ACM + AWS WAF | ACM cert in `us-east-1` for CloudFront; regional ACM cert for the ALBs. |
| DNS | Route 53 (public hosted zone + private hosted zone) | Health-checked records; latency/failover routing for multi-region later. |
| Observability | CloudWatch Logs/Metrics/Alarms + AWS X-Ray | Container logs via `awslogs`/`awsfirelens`; X-Ray traces keyed by `X-Request-Id`. |
| Identity for workloads | IAM task roles (one per service) + IAM execution role | Least-privilege; no long-lived keys inside tasks. |

**When not Fargate.** Fargate is the default for all six services. Two exceptions are anticipated:
(a) if the AI engine runs models in-process and needs GPU, `qayd-ai` moves to an **EKS** node group
with GPU instances (or ECS on EC2 GPU); (b) a very large, steady worker fleet may be cheaper on
**EC2** capacity providers or EKS with the Cluster Autoscaler/Karpenter. Neither exception changes the
service boundaries — only the compute substrate under one ECS/EKS service.

# Networking & Security Groups

The network is default-deny. Subnet tiers and security groups encode the trust boundaries from
[../foundation/SECURITY_ARCHITECTURE.md](../foundation/SECURITY_ARCHITECTURE.md): the internet reaches
only the load balancers; the API is the only thing that reaches the database; the AI engine has no
inbound path from the internet and no path to the database at all.

**Subnet tiers (per AZ, ×3):**

- **Public** — ALBs and NAT gateways only. Route to Internet Gateway.
- **Private-app** — all ECS tasks (web, api, ai, reverb, workers, scheduler). Egress via NAT.
- **Private-data** — RDS and ElastiCache. No route to NAT; reach AWS APIs via VPC endpoints only.

**Security groups (source → destination is what a group *allows in*):**

| Security group | Allows inbound from | On ports | Purpose |
|---|---|---|---|
| `sg-alb-public` | CloudFront prefix list / 0.0.0.0/0 (443) | 443 | Public ALBs (api + ws). |
| `sg-web` | `sg-alb-public` | 3000 | Next.js SSR tasks. |
| `sg-api` | `sg-alb-public`, `sg-web` (SSR/BFF) | 8080 | Laravel API tasks. |
| `sg-reverb` | `sg-alb-public` | 8080 (WS) | Reverb WebSocket tasks. |
| `sg-ai` | `sg-api` **only** | 8000 | FastAPI AI engine. No LB, no public path. |
| `sg-workers` | (none inbound) | — | Egress-only; workers pull from Redis/SQS. |
| `sg-rds` | `sg-api`, `sg-workers`, `sg-scheduler` | 5432 | PostgreSQL. **Not** `sg-web`, **not** `sg-ai`. |
| `sg-redis` | `sg-api`, `sg-workers`, `sg-scheduler`, `sg-reverb` | 6379 | ElastiCache Redis. |
| `sg-vpce` | `sg-*` app groups | 443 | Interface VPC endpoints. |

Two rules in that table are the crux of tenant and AI safety and must be reviewed on every change:
**`sg-rds` never lists `sg-ai`** (the AI engine has no database reachability, enforcing the
architecture invariant that the AI engine never touches PostgreSQL directly), and **`sg-rds` never
lists `sg-web`** (the unprivileged web tier cannot reach the database; it goes through the API).

**Egress control.** App tasks reach AWS services (S3, Secrets Manager, ECR, CloudWatch, SQS) through
**VPC endpoints** so that traffic never leaves the AWS network. Outbound internet (bank aggregators,
AI model providers, email/SMS gateways) goes through NAT and is optionally funnelled through a
managed egress firewall (AWS Network Firewall) with an allowlist of partner domains.

**WAF.** AWS WAF is attached to CloudFront and the API ALB with the AWS managed core rule set, a rate
rule per source IP, and a rule set for SQLi/XSS. The API's own rate limiting (Redis, per token +
company) is the authoritative tenant-fair limiter; WAF is the coarse edge shield.

# Provisioning (IaC)

The estate is Terraform. State lives in an S3 backend with a DynamoDB lock table; there is no
click-ops. Modules mirror the service map: `network`, `data` (rds + elasticache), `ecs-service`
(reused for each of the six services), `edge` (cloudfront + waf + route53), `secrets`, and
`observability`. Below are the load-bearing excerpts — enough to stand the estate up, not the entire
root module.

**Backend and provider (pinned to the GCC home region):**

```hcl
# terraform/providers.tf
terraform {
  required_version = ">= 1.7"
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.60" }
  }
  backend "s3" {
    bucket         = "qayd-tf-state-mec1"
    key            = "prod/terraform.tfstate"
    region         = "me-central-1"        # UAE — GCC data residency
    dynamodb_table = "qayd-tf-locks"
    encrypt        = true
  }
}

provider "aws" {
  region = "me-central-1"
  default_tags {
    tags = {
      Project     = "qayd"
      Environment = var.environment
      DataClass   = "financial"
      ManagedBy   = "terraform"
    }
  }
}

# CloudFront/WAF certs must live in us-east-1 — a second aliased provider.
provider "aws" {
  alias  = "edge"
  region = "us-east-1"
}
```

**Network (VPC, three-tier subnets, NAT, endpoints):**

```hcl
# terraform/network/main.tf
module "vpc" {
  source  = "terraform-aws-modules/vpc/aws"
  version = "~> 5.8"

  name = "qayd-${var.environment}"
  cidr = "10.40.0.0/16"
  azs  = ["me-central-1a", "me-central-1b", "me-central-1c"]

  public_subnets   = ["10.40.0.0/20", "10.40.16.0/20", "10.40.32.0/20"]
  private_subnets  = ["10.40.64.0/20", "10.40.80.0/20", "10.40.96.0/20"]  # app tier
  database_subnets = ["10.40.128.0/22", "10.40.132.0/22", "10.40.136.0/22"] # data tier

  enable_nat_gateway     = true
  single_nat_gateway     = false          # one NAT per AZ for HA
  create_database_subnet_group = true

  enable_flow_log                      = true
  flow_log_destination_type            = "cloud-watch-logs"
  flow_log_max_aggregation_interval    = 60
}

# S3 gateway endpoint (free) + interface endpoints for the private-data tier.
resource "aws_vpc_endpoint" "s3" {
  vpc_id            = module.vpc.vpc_id
  service_name      = "com.amazonaws.me-central-1.s3"
  vpc_endpoint_type = "Gateway"
  route_table_ids   = module.vpc.private_route_table_ids
}

locals {
  interface_endpoints = ["secretsmanager", "ecr.api", "ecr.dkr", "logs", "sqs", "kms"]
}

resource "aws_vpc_endpoint" "interface" {
  for_each            = toset(local.interface_endpoints)
  vpc_id              = module.vpc.vpc_id
  service_name        = "com.amazonaws.me-central-1.${each.value}"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = module.vpc.private_subnets
  security_group_ids  = [aws_security_group.vpce.id]
  private_dns_enabled = true
}
```

**RDS PostgreSQL (Multi-AZ + read replica, KMS, SSL-forced, RLS-ready):**

```hcl
# terraform/data/rds.tf
resource "aws_db_instance" "primary" {
  identifier                   = "qayd-${var.environment}-pg"
  engine                       = "postgres"
  engine_version               = "15.6"
  instance_class               = "db.r6g.xlarge"
  allocated_storage            = 200
  max_allocated_storage        = 2000          # storage autoscaling
  storage_type                 = "gp3"
  storage_encrypted            = true
  kms_key_id                   = aws_kms_key.rds.arn
  multi_az                     = true          # synchronous standby in a 2nd AZ
  db_subnet_group_name         = module.vpc.database_subnet_group_name
  vpc_security_group_ids       = [aws_security_group.rds.id]
  username                     = "qayd_admin"
  manage_master_user_password  = true          # RDS-managed secret in Secrets Manager
  master_user_secret_kms_key_id = aws_kms_key.rds.arn
  backup_retention_period      = 14
  backup_window                = "23:00-23:45" # low-traffic GCC night
  copy_tags_to_snapshot        = true
  deletion_protection          = true
  performance_insights_enabled = true
  auto_minor_version_upgrade   = true
  parameter_group_name         = aws_db_parameter_group.pg15.name
}

resource "aws_db_parameter_group" "pg15" {
  family = "postgres15"
  parameter { name = "rds.force_ssl"                 value = "1" }
  parameter { name = "log_min_duration_statement"    value = "1000" }   # slow-query log
  parameter { name = "row_security"                  value = "on" }     # RLS honored
}

resource "aws_db_instance" "replica" {
  identifier          = "qayd-${var.environment}-pg-ro-1"
  replicate_source_db = aws_db_instance.primary.identifier
  instance_class      = "db.r6g.large"
  multi_az            = false
  performance_insights_enabled = true
}
```

**ElastiCache Redis (replication group, Multi-AZ, encrypted):**

```hcl
# terraform/data/redis.tf
resource "aws_elasticache_replication_group" "redis" {
  replication_group_id       = "qayd-${var.environment}-redis"
  description                = "QAYD cache/queue/locks/reverb"
  engine                     = "redis"
  engine_version             = "7.1"
  node_type                  = "cache.r6g.large"
  num_cache_clusters         = 2               # primary + replica
  automatic_failover_enabled = true
  multi_az_enabled           = true
  at_rest_encryption_enabled = true
  transit_encryption_enabled = true
  auth_token                 = var.redis_auth_token   # sourced from Secrets Manager
  subnet_group_name          = aws_elasticache_subnet_group.redis.name
  security_group_ids         = [aws_security_group.redis.id]
  snapshot_retention_limit   = 7
}
```

**Reusable ECS Fargate service module (used for all six services):**

```hcl
# terraform/ecs-service/main.tf  (invoked once per service)
resource "aws_ecs_task_definition" "this" {
  family                   = "qayd-${var.service}"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.cpu
  memory                   = var.memory
  execution_role_arn       = var.execution_role_arn   # pull image, read secrets
  task_role_arn            = var.task_role_arn         # app's runtime AWS permissions

  container_definitions = jsonencode([{
    name      = var.service
    image     = "${var.ecr_repo}:${var.image_tag}"
    essential = true
    portMappings = [{ containerPort = var.container_port }]
    # Secrets injected at start from Secrets Manager — never in the image.
    secrets = [for k, arn in var.secret_arns : { name = k, valueFrom = arn }]
    environment = [for k, v in var.env : { name = k, value = v }]
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = "/qayd/${var.environment}/${var.service}"
        "awslogs-region"        = "me-central-1"
        "awslogs-stream-prefix" = var.service
      }
    }
  }])
}

resource "aws_ecs_service" "this" {
  name            = "qayd-${var.service}"
  cluster         = var.cluster_arn
  task_definition = aws_ecs_task_definition.this.arn
  desired_count   = var.desired_count
  launch_type     = "FARGATE"
  network_configuration {
    subnets         = var.private_app_subnets
    security_groups = [var.security_group_id]
    assign_public_ip = false
  }
  dynamic "load_balancer" {
    for_each = var.target_group_arn == null ? [] : [1]
    content {
      target_group_arn = var.target_group_arn
      container_name   = var.service
      container_port   = var.container_port
    }
  }
  deployment_controller { type = "ECS" }   # rolling; CodeDeploy for blue/green on api
}
```

The scheduler service is provisioned with `desired_count = 1` and **no** autoscaling target — the one
place a fixed count is intentional. Worker services are provisioned once per queue with the same
module and a queue-depth autoscaling policy (see [Scaling & HA](#scaling--ha)).

**Least-privilege task roles.** Each service gets its own IAM task role. The API can read its DB
secret and put objects in the documents bucket; the AI engine can do neither. Illustrative policy for
`qayd-api`:

```hcl
data "aws_iam_policy_document" "api_task" {
  statement {                                   # read only its own secrets
    actions   = ["secretsmanager:GetSecretValue"]
    resources = [aws_secretsmanager_secret.api.arn, aws_db_instance.primary.master_user_secret[0].secret_arn]
  }
  statement {                                   # documents bucket, object-level only
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.documents.arn}/*"]
  }
  statement {                                   # decrypt with the documents CMK
    actions   = ["kms:Decrypt", "kms:GenerateDataKey"]
    resources = [aws_kms_key.documents.arn]
  }
}
```

# Configuration & Secrets

Configuration splits cleanly by sensitivity, and the split is enforced by *where a value can be
read*, per [../security/SECRETS.md](../security/SECRETS.md):

- **Public web config** — `NEXT_PUBLIC_API_BASE_URL`, `NEXT_PUBLIC_WS_URL`, feature flags. Baked into
  the web build or served as build-time env. Contains **no** secret. This is the only tier that ships
  values to the browser.
- **Non-secret runtime config** — region, bucket names, queue names, log levels. Stored in **SSM
  Parameter Store** (String) and passed as task `environment`.
- **Secrets** — DB credentials, Redis auth token, `APP_KEY`, JWT RS256 signing key,
  `AI_ENGINE_SERVICE_KEY`, bank-aggregator tokens, Mailgun/SES and Twilio credentials, Sentry DSN.
  Stored in **AWS Secrets Manager**, injected into ECS tasks via the task definition's `secrets`
  block (resolved by the execution role at container start), and **never** written to an image, a log,
  or an environment file in the repo.

**Rotation.** RDS master credentials use Secrets Manager managed rotation. The JWT signing key follows
the platform's 90-day rotation with a 14-day verify-only overlap (SECRETS.md): a new key version is
staged in Secrets Manager, both versions are published to the API for the overlap, then the old
version is retired. Rotation is a Terraform + Lambda-rotation-function concern, not a manual task.

**Mapping to the API's expected variables.** The backend reads the variables listed in
[../backend/BACKEND_ARCHITECTURE.md](../backend/BACKEND_ARCHITECTURE.md#configuration-and-environment).
On AWS they resolve as: `DB_*` ← RDS endpoint + Secrets Manager DB secret (write DSN to the primary,
read DSN to the replica); `REDIS_*` ← ElastiCache primary endpoint + auth token; `R2_*`/`AWS_*` ←
S3 bucket + the task role (no static keys — the SDK uses the task role); `REVERB_*` ← Reverb service
config + Secrets Manager; `AI_ENGINE_BASE_URL` ← internal DNS name of `qayd-ai`;
`AI_ENGINE_SERVICE_KEY`, `SENTRY_DSN`, mail/SMS ← Secrets Manager.

```bash
# One secret per service, JSON-shaped; created by Terraform, values set out-of-band.
aws secretsmanager create-secret \
  --name qayd/prod/api \
  --region me-central-1 \
  --kms-key-id alias/qayd-secrets \
  --secret-string '{"APP_KEY":"base64:...","JWT_PRIVATE_KEY":"-----BEGIN...","AI_ENGINE_SERVICE_KEY":"..."}'
```

# Deployment Runbook

Images are built and pushed by CI (GitHub Actions, per TECH_STACK), and releases are Terraform +
`aws ecs` service updates. The API uses **CodeDeploy blue/green**; the web, AI, reverb, worker, and
scheduler services use ECS **rolling** deployments. Database migrations are a gated release step run
against the primary **before** new API tasks take traffic.

**1. Build and push images (per service):**

```bash
AWS_REGION=me-central-1
ACCOUNT=$(aws sts get-caller-identity --query Account --output text)
REGISTRY=$ACCOUNT.dkr.ecr.$AWS_REGION.amazonaws.com
aws ecr get-login-password --region $AWS_REGION | docker login --username AWS --password-stdin $REGISTRY

for svc in api web ai reverb; do
  docker build -t $REGISTRY/qayd/$svc:$GIT_SHA -f deploy/$svc.Dockerfile .
  docker push $REGISTRY/qayd/$svc:$GIT_SHA        # immutable tag = commit SHA
done
```

**2. Run database migrations (gated, before new code takes traffic):**

```bash
# One-off ECS task on the api image; exits non-zero on failure and blocks the release.
aws ecs run-task \
  --cluster qayd-prod \
  --task-definition qayd-api-migrate \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[$APP_SUBNETS],securityGroups=[$SG_API],assignPublicIp=DISABLED}" \
  --overrides '{"containerOverrides":[{"name":"api","command":["php","artisan","migrate","--force"]}]}'
```

Migrations must be **expand-then-contract** (add columns/tables compatibly, deploy code that tolerates
both shapes, backfill, then remove the old shape in a later release) so blue and green API versions
can run against one schema during the cutover. Never ship a destructive migration in the same release
as the code that depends on it.

**3. Deploy the services.** Point each ECS service at the new image tag and let ECS/CodeDeploy roll:

```bash
# Rolling services (web, ai, reverb, workers, scheduler):
for svc in web ai reverb worker-realtime worker-default worker-ai worker-reports worker-integrations scheduler; do
  aws ecs update-service --cluster qayd-prod --service qayd-$svc \
    --force-new-deployment --task-definition qayd-$svc:$NEW_REVISION
done

# Blue/green API via CodeDeploy: register the new task def, then create a deployment.
aws deploy create-deployment \
  --application-name qayd-api \
  --deployment-group-name qayd-api-bluegreen \
  --revision "revisionType=AppSpecContent,appSpecContent={content='...taskdef:$NEW_REVISION...'}"
```

CodeDeploy shifts a canary slice of API traffic to the green target group, watches the CloudWatch
alarms (5xx rate, p95 latency, unhealthy hosts), and either promotes to 100% or auto-rolls-back on
alarm. The old (blue) task set is kept for the bake window before termination.

**4. Verify.** Readiness probe (`/health/ready` from `routes/health.php`, checking DB, Redis, S3, and
AI-engine reachability) must be green on every service; smoke a tenant-scoped API call and a Reverb
subscribe; confirm queue depth is draining. **Rollback** is a re-point to the previous image tag (or a
CodeDeploy stop-and-rollback for the API); because migrations were expand-only, the previous code runs
against the current schema without a down-migration.

**5. Scheduler cutover.** The scheduler is a single task; on deploy ECS stops the old task and starts
the new one. A few seconds of no-scheduler is harmless — due jobs fire on the next minute. Never scale
the scheduler above 1.

# Scaling & HA

**Statelessness is the enabler.** Web, API, AI, and Reverb hold no local state — sessions, cache,
locks, and broadcast pub/sub live in ElastiCache — so any task can serve any request and horizontal
scaling is pure. Only the scheduler is a singleton, and it does no request work.

**Autoscaling policies (Application Auto Scaling on ECS):**

| Service | Scale signal | Target | Min / Max |
|---|---|---|---|
| `qayd-web` | ALB requests-per-target + CPU 60% | keep p95 latency low | 2 / 12 |
| `qayd-api` | ALB requests-per-target + CPU 60% | request headroom | 3 / 24 |
| `qayd-ai` | CPU/inference latency (custom CW metric) | queue-depth aware | 2 / 10 |
| `qayd-reverb` | concurrent connections (custom CW metric) | connection headroom | 2 / 8 |
| `qayd-worker-realtime` | queue depth (near-zero target) | 0 backlog | 2 / 8 |
| `qayd-worker-default` | queue depth | drain | 2 / 12 |
| `qayd-worker-ai` | queue depth | throughput | 1 / 10 |
| `qayd-worker-reports` | queue depth | throughput | 1 / 8 |
| `qayd-worker-integrations` | SQS `ApproximateNumberOfMessages` (or Redis depth) | drain | 1 / 6 |
| `qayd-scheduler` | none | — | **1 / 1** |

The `realtime` worker's target backlog is near zero so notifications and broadcasts never queue behind
bulk work; `reports` and `ai` are allowed to build a backlog and scale into it. A custom CloudWatch
metric published by the API (queue depth per Laravel queue) drives the per-queue policies.

**High availability.** Three AZs throughout. RDS Multi-AZ gives an automatic synchronous-standby
failover on primary loss (typically 60–120s); the read replica covers reporting and can be promoted in
a regional event. ElastiCache Multi-AZ with automatic failover promotes a replica on primary loss. ECS
services keep tasks spread across AZs; a lost AZ removes at most one-third of each service's capacity,
which autoscaling refills in the survivors. ALBs are inherently multi-AZ.

**Connection management.** PgBouncer (as a sidecar or a small ECS service) sits between the API/worker
fleets and RDS so that scaling the API to 24 tasks does not exhaust PostgreSQL's connection limit;
transaction-pooling mode is compatible with the per-request `SET LOCAL app.current_company_id` used by
RLS because the setting is scoped to the transaction.

# Observability

Everything is correlated by the `X-Request-Id` the API stamps on every request, job, AI-engine call,
and webhook (BACKEND_ARCHITECTURE, Observability).

- **Logs.** Container stdout/stderr ship to **CloudWatch Logs** via the `awslogs` driver, one log
  group per service per environment (`/qayd/prod/api`, …). Structured JSON lines carry `request_id`,
  `company_id` (never PII), route, and status. Metric filters turn error patterns into CloudWatch
  metrics.
- **Metrics.** ECS/ALB/RDS/ElastiCache publish CloudWatch metrics natively (CPU, memory, request
  count, 5xx, DB connections, replica lag, cache evictions). The API publishes custom metrics: queue
  depth per queue, job runtime, AI-engine call latency/error rate, broadcast throughput.
- **Traces.** **AWS X-Ray** traces span the web→api→ai path; the trace id is bound to `request_id` so
  a support ticket referencing a request id pulls the full trace. Sampling is tuned to keep cost
  bounded while always capturing errors.
- **Errors.** Unhandled exceptions go to **Sentry** (per TECH_STACK) with `request_id` and company id;
  the client gets the generic `500`. CloudWatch alarms cover the infrastructure signals.
- **Health.** `routes/health.php` exposes liveness and readiness; the ALB target groups use readiness
  for registration, and CloudWatch alarms on unhealthy-host count page on-call.
- **Alarms → paging.** CloudWatch alarms (API 5xx rate, p95 latency, RDS CPU/replica-lag/free-storage,
  Redis evictions, queue backlog growth, DLQ non-empty) route through SNS to the on-call channel.

Prometheus/Grafana from TECH_STACK can be run alongside (Amazon Managed Prometheus + Managed Grafana)
if a single pane across clouds is wanted; the CloudWatch path above is complete for AWS-only operation.

# Backup & DR

Backup and DR follow [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md);
this section states the AWS realization and the residency rule. **All backups stay in the GCC home
region** (or a second GCC region), never in a non-GCC region, unless a DPA permits otherwise.

- **Database.** RDS automated backups with **14-day** retention plus **point-in-time recovery** (5-min
  granularity via continuous WAL archiving). A daily manual/`copy-tags` snapshot is retained longer for
  compliance. PITR is the primary tool for the "bad write at a known time" case; restore is always to a
  **new instance**, never in place. Recovered *posted* financial rows are re-created through the API's
  posting path, not re-inserted, so the immutable-ledger and audit guarantees hold (BACKUP_RECOVERY).
- **Objects.** S3 documents bucket has **versioning** on (so an overwrite/delete is recoverable) and a
  lifecycle policy transitioning old versions to cheaper storage. Optional **same-region** (not
  cross-region) replication to a second GCC-region bucket for a regional-outage copy that still honors
  residency.
- **Redis.** ElastiCache daily snapshots (7-day retention). Redis holds cache/queue/ephemeral state;
  its loss degrades performance and drops in-flight queued jobs, not committed financial state — which
  is why money-affecting jobs are idempotent and replay safely (BACKEND_ARCHITECTURE, Queues).
- **Secrets & config.** Secrets Manager and Parameter Store are themselves durable and versioned;
  Terraform state (the estate's definition) is versioned in S3.

**Targets.** Tier-0 financial tables target **RPO ≈ 5 minutes** (PITR granularity) and **RTO ≈ 1 hour**
for a full restore-to-new-instance, consistent with BACKUP_RECOVERY. Multi-AZ failover (RDS,
ElastiCache) covers the common single-AZ fault with seconds-to-minutes recovery and **no data loss**.

**Regional DR.** A full-region loss is the disaster case. The prepared path: RDS **cross-region
snapshot copy to the alternate GCC region** (me-south-1) on a schedule, Terraform to stand the estate
up there from code, and Route 53 failover records to shift traffic once the standby is promoted.
Cross-region replication targets a GCC region only. Recovery testing runs on a schedule (nightly
restore-verify in an isolated account, per BACKUP_RECOVERY) — a backup that has never been restored is
not a backup.

# Cost Notes

- **Fargate vs EC2/EKS.** Fargate removes host management and is right for bursty, independently scaled
  services; its per-vCPU premium is worth it until a fleet is large and steady. The likely first move
  is the `default`/`ai` worker fleets or the AI engine (if GPU) onto **EC2 capacity providers** or
  **EKS + Karpenter** with Spot for interruptible work (reports, bulk OCR — never the `realtime`
  worker or the scheduler on Spot).
- **NAT and data transfer.** Per-AZ NAT gateways plus their data-processing charges are a recurring
  cost; the S3 **gateway** endpoint (free) and interface endpoints for Secrets Manager/ECR/CloudWatch
  cut NAT bytes and keep AWS-bound traffic off the internet. Keep cross-AZ chatter down by co-locating
  a request's hops where possible.
- **RDS.** Graviton (`r6g`) instances are cheaper per unit of performance; a Reserved Instance or
  Savings Plan on the steady primary + replica is a straightforward saving once sizing is stable.
  Storage autoscaling avoids over-provisioning gp3 up front.
- **Data residency has a price.** GCC regions (me-central-1, me-south-1) can price above us-east-1/eu;
  residency is a compliance requirement, not a cost lever — do not "save" by moving financial data out
  of region.
- **Log/metric volume.** CloudWatch Logs ingestion and X-Ray traces scale with traffic; set log
  retention (e.g. 30 days hot, export to S3/Glacier for compliance), sample X-Ray, and prefer metric
  filters over full-text log scans for alarming.
- **CDN offload.** CloudFront in front of S3 static assets and cacheable API responses offloads the
  origin and cuts egress; the biggest lever for the web tier is serving assets from the edge, not from
  Fargate.

A first production sizing (me-central-1) is roughly: RDS `db.r6g.xlarge` Multi-AZ + one `r6g.large`
replica, ElastiCache two `cache.r6g.large` nodes, and Fargate services in the min counts above — a
mid-four-figure USD/month baseline that scales with tenant load, dominated by RDS and NAT until the
worker fleets grow.

# End of Document
