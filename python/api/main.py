"""
Painting Made Easy Denver Python Service

FastAPI application for ML processing, web scraping, and media handling.
"""

from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from loguru import logger
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import JSONResponse

from api.config import get_settings
from api.routers import documents, health, images, media, scraping, streaming

settings = get_settings()


class APIKeyMiddleware(BaseHTTPMiddleware):
    """Validate API key on all requests except health checks."""

    async def dispatch(self, request, call_next):
        # Allow health check and root without auth
        if request.url.path in ["/", "/health", "/health/detailed"]:
            return await call_next(request)

        # Skip auth if no key configured (development mode)
        if not settings.python_api_key:
            return await call_next(request)

        api_key = request.headers.get("X-API-Key")
        if api_key != settings.python_api_key:
            return JSONResponse(
                status_code=403,
                content={"detail": "Invalid or missing API key"},
            )

        return await call_next(request)


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan handler for startup/shutdown."""
    # Startup
    logger.info(f"Starting {settings.service_name}...")
    logger.info(f"Debug mode: {settings.debug}")
    logger.info(f"GPU enabled: {settings.ml_gpu_enabled}")

    yield

    # Shutdown
    logger.info(f"Shutting down {settings.service_name}...")


app = FastAPI(
    title="Apex Python Service",
    description="ML processing, web scraping, and media handling for Painting Made Easy Denver CRM",
    version="0.1.0",
    lifespan=lifespan,
)

# API key authentication middleware
app.add_middleware(APIKeyMiddleware)

# CORS middleware for Laravel communication
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1",
        "http://localhost",
        settings.laravel_url,
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Register routers
app.include_router(health.router, tags=["Health"])
app.include_router(images.router, prefix="/images", tags=["Images"])
app.include_router(scraping.router, prefix="/scrape", tags=["Scraping"])
app.include_router(media.router, prefix="/media", tags=["Media"])
app.include_router(streaming.router, prefix="/streaming", tags=["Streaming"])
app.include_router(documents.router, prefix="/documents", tags=["Documents"])


@app.get("/")
async def root():
    """Root endpoint."""
    return {
        "service": settings.service_name,
        "version": "0.1.0",
        "status": "running",
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "api.main:app",
        host=settings.host,
        port=settings.port,
        reload=settings.debug,
    )
