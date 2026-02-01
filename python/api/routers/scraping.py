"""Web scraping endpoints."""

import uuid
from enum import Enum
from typing import Any, Dict, Optional

from fastapi import APIRouter, BackgroundTasks, HTTPException
from loguru import logger
from pydantic import BaseModel, HttpUrl

from ..config import get_settings
from ..services.scraper.realtor import RealtorScraper
from ..services.scraper.zillow import ZillowScraper

settings = get_settings()

router = APIRouter()

# In-memory job store (in production, use Redis or a database)
scraping_jobs: Dict[str, Dict[str, Any]] = {}


class ScrapingSource(str, Enum):
    """Supported scraping sources."""

    ZILLOW = "zillow"
    REALTOR = "realtor"
    MLS = "mls"
    GENERIC = "generic"


class ScrapeListingRequest(BaseModel):
    """Request model for scraping a real estate listing."""

    url: HttpUrl
    source: Optional[ScrapingSource] = None  # Auto-detect if not provided
    selectors: Optional[dict] = None  # Custom CSS selectors for generic scraping


class ScrapeSearchRequest(BaseModel):
    """Request model for scraping search results."""

    source: ScrapingSource
    location: str
    filters: Optional[dict] = None
    max_results: int = 50


class ListingData(BaseModel):
    """Scraped listing data."""

    url: str
    source: str
    address: Optional[str] = None
    price: Optional[float] = None
    bedrooms: Optional[int] = None
    bathrooms: Optional[float] = None
    sqft: Optional[int] = None
    lot_size: Optional[str] = None
    year_built: Optional[int] = None
    property_type: Optional[str] = None
    description: Optional[str] = None
    photos: Optional[list[str]] = None
    agent_name: Optional[str] = None
    agent_phone: Optional[str] = None
    mls_number: Optional[str] = None
    raw_data: Optional[dict] = None


class ScrapeResponse(BaseModel):
    """Response model for scraping operations."""

    success: bool
    data: Optional[ListingData] = None
    error: Optional[str] = None
    warnings: Optional[list[str]] = None


class ScrapeSearchResponse(BaseModel):
    """Response model for search scraping."""

    success: bool
    listings: Optional[list[ListingData]] = None
    total_found: int = 0
    error: Optional[str] = None


class ScrapeJobResponse(BaseModel):
    """Response model for async scraping jobs."""

    success: bool
    job_id: str
    status: str
    message: str


class JobStatusResponse(BaseModel):
    """Response model for job status."""

    job_id: str
    status: str
    result: Optional[ListingData] = None
    error: Optional[str] = None


def detect_source_from_url(url: str) -> ScrapingSource:
    """Auto-detect the scraping source from URL."""
    url_lower = url.lower()
    if "zillow.com" in url_lower:
        return ScrapingSource.ZILLOW
    elif "realtor.com" in url_lower:
        return ScrapingSource.REALTOR
    elif "mls" in url_lower:
        return ScrapingSource.MLS
    else:
        return ScrapingSource.GENERIC


def convert_to_listing_data(raw_data: Dict[str, Any]) -> ListingData:
    """Convert raw scraper data to ListingData model."""
    return ListingData(
        url=raw_data.get("url", ""),
        source=raw_data.get("source", "unknown"),
        address=raw_data.get("address"),
        price=raw_data.get("price"),
        bedrooms=raw_data.get("bedrooms"),
        bathrooms=raw_data.get("bathrooms"),
        sqft=raw_data.get("sqft"),
        lot_size=raw_data.get("lot_size"),
        year_built=raw_data.get("year_built"),
        property_type=raw_data.get("property_type"),
        description=raw_data.get("description"),
        photos=raw_data.get("photos"),
        agent_name=raw_data.get("agent_name"),
        agent_phone=raw_data.get("agent_phone"),
        mls_number=raw_data.get("mls_number"),
        raw_data=raw_data,
    )


async def process_scraping_job(job_id: str, url: str, source: ScrapingSource):
    """Background task to process a scraping job."""
    scraping_jobs[job_id]["status"] = "running"

    try:
        if source == ScrapingSource.ZILLOW:
            async with ZillowScraper() as scraper:
                result = await scraper.scrape_listing(url)
        elif source == ScrapingSource.REALTOR:
            async with RealtorScraper() as scraper:
                result = await scraper.scrape_listing(url)
        else:
            scraping_jobs[job_id]["status"] = "failed"
            scraping_jobs[job_id]["error"] = f"Source {source.value} not supported for async scraping"
            return

        if "error" in result:
            scraping_jobs[job_id]["status"] = "failed"
            scraping_jobs[job_id]["error"] = result.get("error")
        else:
            scraping_jobs[job_id]["status"] = "completed"
            scraping_jobs[job_id]["result"] = convert_to_listing_data(result)

    except Exception as e:
        logger.exception(f"Error in background scraping job {job_id}: {e}")
        scraping_jobs[job_id]["status"] = "failed"
        scraping_jobs[job_id]["error"] = str(e)


@router.post("/listing", response_model=ScrapeResponse)
async def scrape_listing(request: ScrapeListingRequest):
    """
    Scrape a single real estate listing.

    Supports Zillow and Realtor.com listings.
    Auto-detects source from URL if not specified.
    """
    url_str = str(request.url)

    # Auto-detect source
    source = request.source or detect_source_from_url(url_str)

    warnings = []

    # Use proxy if configured
    proxy = settings.scraping_proxy_url if settings.scraping_proxy_url else None
    if proxy:
        logger.info(f"Using scraping proxy: {proxy[:30]}...")

    try:
        if source == ScrapingSource.ZILLOW:
            async with ZillowScraper(proxy=proxy) as scraper:
                result = await scraper.scrape_listing(url_str)
        elif source == ScrapingSource.REALTOR:
            async with RealtorScraper(proxy=proxy) as scraper:
                result = await scraper.scrape_listing(url_str)
        elif source == ScrapingSource.MLS:
            return ScrapeResponse(
                success=False,
                error="MLS scraping requires specific MLS credentials and is not yet implemented.",
                warnings=["Consider using Zillow or Realtor.com as alternative sources."],
            )
        elif source == ScrapingSource.GENERIC:
            return ScrapeResponse(
                success=False,
                error="Generic scraping with custom selectors is not yet implemented.",
                warnings=["Please provide a URL from Zillow or Realtor.com for best results."],
            )
        else:
            return ScrapeResponse(
                success=False,
                error=f"Unknown scraping source: {source}",
            )

        # Check for errors in the result
        if "error" in result:
            return ScrapeResponse(
                success=False,
                error=result.get("error"),
                warnings=warnings,
            )

        # Convert to ListingData
        listing_data = convert_to_listing_data(result)

        # Add warnings if data seems incomplete
        if not listing_data.address:
            warnings.append("Could not extract address from listing.")
        if not listing_data.price:
            warnings.append("Could not extract price from listing.")

        return ScrapeResponse(
            success=True,
            data=listing_data,
            warnings=warnings if warnings else None,
        )

    except Exception as e:
        logger.exception(f"Error scraping listing: {e}")
        return ScrapeResponse(
            success=False,
            error=f"Scraping failed: {str(e)}",
            warnings=["The website may be blocking automated requests. Try again later."],
        )


@router.post("/search", response_model=ScrapeSearchResponse)
async def scrape_search(request: ScrapeSearchRequest):
    """
    Scrape search results from a real estate site.

    Returns multiple listings matching the search criteria.
    """
    try:
        if request.source == ScrapingSource.ZILLOW:
            async with ZillowScraper() as scraper:
                results = await scraper.scrape_search(
                    location=request.location,
                    filters=request.filters,
                    max_results=request.max_results,
                )
        elif request.source == ScrapingSource.REALTOR:
            async with RealtorScraper() as scraper:
                results = await scraper.scrape_search(
                    location=request.location,
                    filters=request.filters,
                    max_results=request.max_results,
                )
        elif request.source == ScrapingSource.MLS:
            return ScrapeSearchResponse(
                success=False,
                listings=[],
                total_found=0,
                error="MLS search scraping requires specific credentials and is not yet implemented.",
            )
        else:
            return ScrapeSearchResponse(
                success=False,
                listings=[],
                total_found=0,
                error=f"Search scraping for {request.source.value} is not yet implemented.",
            )

        # Convert results to ListingData
        listings = [convert_to_listing_data(r) for r in results]

        return ScrapeSearchResponse(
            success=True,
            listings=listings,
            total_found=len(listings),
        )

    except Exception as e:
        logger.exception(f"Error scraping search: {e}")
        return ScrapeSearchResponse(
            success=False,
            listings=[],
            total_found=0,
            error=f"Search scraping failed: {str(e)}",
        )


@router.post("/listing/async", response_model=ScrapeJobResponse)
async def scrape_listing_async(
    request: ScrapeListingRequest, background_tasks: BackgroundTasks
):
    """
    Queue a scraping job for async processing.

    Returns a job ID that can be used to check status.
    Useful for batch scraping or when immediate response isn't needed.
    """
    url_str = str(request.url)
    job_id = str(uuid.uuid4())

    # Auto-detect source
    source = request.source or detect_source_from_url(url_str)

    # Check if source is supported
    if source not in [ScrapingSource.ZILLOW, ScrapingSource.REALTOR]:
        return ScrapeJobResponse(
            success=False,
            job_id=job_id,
            status="failed",
            message=f"Async scraping for {source.value} is not yet supported. Use Zillow or Realtor.com URLs.",
        )

    # Initialize job record
    scraping_jobs[job_id] = {
        "status": "queued",
        "url": url_str,
        "source": source.value,
        "result": None,
        "error": None,
    }

    # Add to background tasks
    background_tasks.add_task(process_scraping_job, job_id, url_str, source)

    return ScrapeJobResponse(
        success=True,
        job_id=job_id,
        status="queued",
        message=f"Scraping job queued. Poll /scraping/job/{job_id} for status.",
    )


@router.get("/job/{job_id}", response_model=JobStatusResponse)
async def get_scraping_job_status(job_id: str):
    """Get the status of an async scraping job."""
    if job_id not in scraping_jobs:
        raise HTTPException(
            status_code=404,
            detail=f"Job {job_id} not found. Jobs expire after server restart.",
        )

    job = scraping_jobs[job_id]

    return JobStatusResponse(
        job_id=job_id,
        status=job["status"],
        result=job.get("result"),
        error=job.get("error"),
    )


@router.delete("/job/{job_id}")
async def delete_scraping_job(job_id: str):
    """Delete a completed or failed scraping job."""
    if job_id not in scraping_jobs:
        raise HTTPException(
            status_code=404,
            detail=f"Job {job_id} not found.",
        )

    job = scraping_jobs[job_id]
    if job["status"] == "running":
        raise HTTPException(
            status_code=400,
            detail="Cannot delete a running job. Wait for it to complete.",
        )

    del scraping_jobs[job_id]

    return {"message": f"Job {job_id} deleted successfully."}


@router.get("/sources")
async def list_supported_sources():
    """List all supported scraping sources with their status."""
    return {
        "sources": [
            {
                "name": "zillow",
                "display_name": "Zillow",
                "status": "active",
                "features": ["listings", "search", "photos"],
                "note": "May be blocked by anti-bot measures",
            },
            {
                "name": "realtor",
                "display_name": "Realtor.com",
                "status": "active",
                "features": ["listings", "search", "photos", "agent_info"],
                "note": "May be blocked by anti-bot measures",
            },
            {
                "name": "mls",
                "display_name": "MLS (Multiple Listing Service)",
                "status": "planned",
                "features": ["listings", "photos"],
                "note": "Requires MLS access credentials - not yet implemented",
            },
            {
                "name": "generic",
                "display_name": "Generic Web Scraping",
                "status": "planned",
                "features": ["custom_selectors"],
                "note": "Custom selector support - not yet implemented",
            },
        ]
    }
