"""
Apex Negotiations Python Service

FastAPI application for ML processing, web scraping, and media handling.
"""

from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from loguru import logger

from api.config import get_settings
from api.routers import health, images, media, scraping

settings = get_settings()


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
    description="ML processing, web scraping, and media handling for Apex Negotiations CRM",
    version="0.1.0",
    lifespan=lifespan,
)

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
