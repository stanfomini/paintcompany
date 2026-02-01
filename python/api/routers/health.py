"""Health check endpoints."""

import platform
import sys
from datetime import datetime

from fastapi import APIRouter
from pydantic import BaseModel

from api.config import get_settings

router = APIRouter()
settings = get_settings()


class HealthResponse(BaseModel):
    """Health check response model."""

    status: str
    service: str
    version: str
    timestamp: str
    python_version: str
    platform: str
    gpu_enabled: bool


class DetailedHealthResponse(HealthResponse):
    """Detailed health check with component status."""

    components: dict[str, dict]


@router.get("/health", response_model=HealthResponse)
async def health_check():
    """
    Basic health check endpoint.

    Returns service status for monitoring and Laravel integration testing.
    """
    return HealthResponse(
        status="healthy",
        service=settings.service_name,
        version="0.1.0",
        timestamp=datetime.utcnow().isoformat(),
        python_version=sys.version.split()[0],
        platform=platform.system(),
        gpu_enabled=settings.ml_gpu_enabled,
    )


@router.get("/health/detailed", response_model=DetailedHealthResponse)
async def detailed_health_check():
    """
    Detailed health check with component status.

    Checks availability of ML models, S3 connection, etc.
    """
    components = {}

    # Check ML availability
    try:
        import torch

        components["ml"] = {
            "status": "available",
            "torch_version": torch.__version__,
            "cuda_available": torch.cuda.is_available(),
            "cuda_device": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        }
    except ImportError:
        components["ml"] = {"status": "not_installed"}

    # Check scraping availability
    try:
        import playwright.sync_api

        components["scraping"] = {
            "status": "available",
            "playwright": True,
        }
    except ImportError:
        components["scraping"] = {"status": "not_installed"}

    # Check media availability
    try:
        import whisper

        components["media"] = {
            "status": "available",
            "whisper_available": True,
        }
    except ImportError:
        components["media"] = {"status": "not_installed"}

    # Check S3 configuration
    components["s3"] = {
        "status": "configured" if settings.aws_bucket else "not_configured",
        "bucket": settings.aws_bucket or None,
        "region": settings.aws_default_region,
    }

    return DetailedHealthResponse(
        status="healthy",
        service=settings.service_name,
        version="0.1.0",
        timestamp=datetime.utcnow().isoformat(),
        python_version=sys.version.split()[0],
        platform=platform.system(),
        gpu_enabled=settings.ml_gpu_enabled,
        components=components,
    )
