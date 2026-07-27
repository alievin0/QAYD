# QAYD — developer workflow (Sprint-1 story S1-03).
#
# Thin, discoverable wrappers over the per-app toolchains (Composer / pnpm / uv) and the local data
# services in infrastructure/docker/docker-compose.yml. The gate suites mirror .github/workflows/ci.yml
# so "green locally" and "green in CI" mean the same thing. Recipes are tab-indented (Make requirement).
#
# Quick start:  make install && make up && make migrate && make seed
# Ports/secrets come from env / per-app .env (copied from each app's .env.example); none are committed.

COMPOSE := docker compose -f infrastructure/docker/docker-compose.yml

.DEFAULT_GOAL := help
.PHONY: help install up down migrate seed fresh test test-api test-web test-ai

help: ## Show this help
	@awk 'BEGIN{FS=":.*##"; printf "QAYD developer targets\n\n"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install all deps (composer + pnpm + uv)
	cd apps/api && composer install --no-interaction --prefer-dist
	pnpm install --frozen-lockfile
	cd apps/ai && uv sync

up: ## Start local data services (Postgres 16 + Redis 7) and wait for health
	$(COMPOSE) up -d --wait

down: ## Stop local data services
	$(COMPOSE) down

migrate: ## Apply DB migrations (creates qayd_app role + RLS policies)
	cd apps/api && php artisan migrate --force

seed: ## Seed the RBAC catalogue + default roles
	cd apps/api && php artisan db:seed --force

fresh: ## Drop, re-migrate, and seed the database from scratch
	cd apps/api && php artisan migrate:fresh --seed --force

test: test-api test-web test-ai ## Run all three codebases' gate suites

test-api: ## Backend gates (pint + phpstan + pest)
	cd apps/api && ./vendor/bin/pint --test
	cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=1G
	cd apps/api && ./vendor/bin/pest

test-web: ## Frontend gates (packages build + lint + typecheck + test + i18n)
	pnpm -r --filter './packages/*' run build
	pnpm --filter web run lint
	pnpm --filter web run typecheck
	pnpm --filter web run test
	pnpm --filter web run i18n:check

test-ai: ## AI gates (ruff + mypy + pytest)
	cd apps/ai && uv run ruff check .
	cd apps/ai && uv run mypy src
	cd apps/ai && uv run pytest
