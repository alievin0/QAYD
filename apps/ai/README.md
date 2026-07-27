# qayd-ai — AI engine

The **AI engine** for QAYD. It proposes financial insights, classifications, and
suggestions; it **never writes to the ledger directly**. All persistence and
authority stay with the Laravel API (`apps/api`), which is the only service
allowed to reach this engine. The AI engine is not exposed to end users or the
public internet — it is reachable **only through Laravel** (proposes, never writes).

Sprint 1 scope (story S1-01): a typed `GET /health` route plus green quality
gates. No real AI work lands this sprint.

## Stack

- Python **3.12** (pinned via `.python-version`; avoids 3.14 wheel gaps)
- [FastAPI](https://fastapi.tiangolo.com/) + [uvicorn](https://www.uvicorn.org/)
- Managed with [uv](https://docs.astral.sh/uv/) (src layout, packaged app)

## Setup

```bash
uv sync
```

## Run

```bash
uv run uvicorn qayd_ai.main:app --reload --port 8001
```

Then check the health route:

```bash
curl http://127.0.0.1:8001/health
# {"status":"ok","service":"qayd-ai"}
```

## Quality gates

All four must pass (they are the S1-01 green gates):

```bash
uv sync              # resolve + install into .venv
uv run ruff check .  # lint (E, F, I, UP, B; line-length 100)
uv run mypy src      # strict type-check
uv run pytest -q     # tests
```
