"""FastAPI application entrypoint for the QAYD AI engine."""

from fastapi import FastAPI

app = FastAPI(title="QAYD AI Engine")


@app.get("/health")
async def health() -> dict[str, str]:
    """Liveness probe used by the platform's green gates."""
    return {"status": "ok", "service": "qayd-ai"}
