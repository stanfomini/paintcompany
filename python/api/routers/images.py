"""Image processing endpoints."""

import io
import os
import tempfile
import uuid
from enum import Enum
from typing import Optional

import cv2
import httpx
import numpy as np
from fastapi import APIRouter, File, HTTPException, UploadFile
from loguru import logger
from PIL import Image
from pydantic import BaseModel

from api.services.ml.image_processor import ImageProcessor
from api.services.ml.inpainting import ImageInpainter
from api.services.ml.recolor import SurfaceRecolorizer
from api.services.ml.substrate import SubstrateDetector

router = APIRouter()

# Initialize services
image_processor = ImageProcessor()
substrate_detector = SubstrateDetector()
recolorizer = SurfaceRecolorizer()
inpainter = ImageInpainter()


class ImageOperation(str, Enum):
    """Available image operations."""

    ENHANCE = "enhance"
    AUTO_ENHANCE = "auto_enhance"
    SEGMENT = "segment"
    INPAINT = "inpaint"
    DETECT_SUBSTRATE = "detect_substrate"
    RECOLOR = "recolor"
    REMOVE_BACKGROUND = "remove_background"
    DETECT_BLUR = "detect_blur"


class ProcessImageRequest(BaseModel):
    """Request model for image processing."""

    image_url: str
    operations: list[ImageOperation]
    options: Optional[dict] = None


class SegmentRequest(BaseModel):
    """Request model for image segmentation."""

    image_url: str
    prompt: Optional[str] = None


class InpaintRequest(BaseModel):
    """Request model for inpainting (object removal)."""

    image_url: str
    mask_url: str
    method: str = "telea"
    radius: int = 5


class RecolorRequest(BaseModel):
    """Request model for surface recoloring."""

    image_url: str
    mask_url: Optional[str] = None
    target_color: str
    preserve_texture: bool = True
    intensity: float = 0.8


class SubstrateDetectionRequest(BaseModel):
    """Request model for substrate detection."""

    image_url: str
    detect_regions: bool = False
    grid_size: int = 4


class ProcessImageResponse(BaseModel):
    """Response model for processed images."""

    success: bool
    result_url: Optional[str] = None
    result_base64: Optional[str] = None
    operations_applied: list[str]
    metadata: Optional[dict] = None
    error: Optional[str] = None


class SubstrateDetectionResponse(BaseModel):
    """Response model for substrate detection."""

    success: bool
    substrate: Optional[str] = None
    confidence: Optional[float] = None
    regions: Optional[list[dict]] = None
    all_detections: Optional[dict] = None
    texture_features: Optional[dict] = None
    error: Optional[str] = None


def load_image_from_url(url: str) -> np.ndarray:
    """Load an image from URL."""
    response = httpx.get(url, timeout=30.0)
    response.raise_for_status()
    image_bytes = io.BytesIO(response.content)
    pil_image = Image.open(image_bytes)
    # Convert to BGR numpy array
    if pil_image.mode == "RGBA":
        pil_image = pil_image.convert("RGB")
    return cv2.cvtColor(np.array(pil_image), cv2.COLOR_RGB2BGR)


def encode_image_base64(image: np.ndarray) -> str:
    """Encode image as base64 string."""
    import base64

    _, buffer = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 90])
    return base64.b64encode(buffer).decode("utf-8")


@router.post("/process", response_model=ProcessImageResponse)
async def process_image(request: ProcessImageRequest):
    """
    Process an image with specified operations.

    Operations are applied in the order specified.
    """
    try:
        # Load image
        image = load_image_from_url(request.image_url)
        options = request.options or {}
        metadata = {}

        # Apply operations
        for op in request.operations:
            if op == ImageOperation.ENHANCE:
                brightness = options.get("brightness", 1.1)
                contrast = options.get("contrast", 1.1)
                sharpness = options.get("sharpness", 1.2)
                color = options.get("color", 1.1)
                image = image_processor.enhance_image(
                    image, brightness, contrast, sharpness, color
                )

            elif op == ImageOperation.AUTO_ENHANCE:
                image = image_processor.auto_enhance(image)

            elif op == ImageOperation.DETECT_BLUR:
                blur_score, is_blurry = image_processor.detect_blur(image)
                metadata["blur_score"] = blur_score
                metadata["is_blurry"] = is_blurry

            elif op == ImageOperation.REMOVE_BACKGROUND:
                image, mask = image_processor.remove_background_simple(image)
                metadata["background_removed"] = True

            elif op == ImageOperation.DETECT_SUBSTRATE:
                detection = substrate_detector.detect(image)
                metadata["substrate"] = detection

            elif op == ImageOperation.RECOLOR:
                target_color = options.get("target_color", "#C0D6D2")
                intensity = options.get("intensity", 0.8)
                image = recolorizer.recolor(
                    image, target_color, preserve_texture=True, intensity=intensity
                )

        # Encode result
        result_base64 = encode_image_base64(image)

        return ProcessImageResponse(
            success=True,
            result_base64=result_base64,
            operations_applied=[op.value for op in request.operations],
            metadata=metadata,
        )

    except httpx.HTTPError as e:
        logger.error(f"Failed to load image: {e}")
        return ProcessImageResponse(
            success=False,
            operations_applied=[],
            error=f"Failed to load image from URL: {str(e)}",
        )
    except Exception as e:
        logger.exception(f"Image processing error: {e}")
        return ProcessImageResponse(
            success=False,
            operations_applied=[],
            error=str(e),
        )


@router.post("/segment", response_model=ProcessImageResponse)
async def segment_image(request: SegmentRequest):
    """
    Segment objects in an image.

    Note: Full SAM segmentation requires the model to be loaded.
    Currently uses simple background segmentation.
    """
    try:
        image = load_image_from_url(request.image_url)

        # Use simple GrabCut segmentation
        foreground, mask = image_processor.remove_background_simple(image)

        return ProcessImageResponse(
            success=True,
            result_base64=encode_image_base64(mask),
            operations_applied=["segment"],
            metadata={
                "method": "grabcut",
                "note": "SAM model not loaded - using GrabCut fallback",
            },
        )

    except Exception as e:
        logger.exception(f"Segmentation error: {e}")
        return ProcessImageResponse(
            success=False,
            operations_applied=[],
            error=str(e),
        )


@router.post("/inpaint", response_model=ProcessImageResponse)
async def inpaint_image(request: InpaintRequest):
    """
    Remove objects from image using inpainting.

    Requires a mask indicating areas to remove.
    """
    try:
        image = load_image_from_url(request.image_url)
        mask = load_image_from_url(request.mask_url)

        # Convert mask to grayscale if needed
        if len(mask.shape) == 3:
            mask = cv2.cvtColor(mask, cv2.COLOR_BGR2GRAY)

        # Inpaint
        result = inpainter.inpaint(
            image, mask, method=request.method, radius=request.radius
        )

        return ProcessImageResponse(
            success=True,
            result_base64=encode_image_base64(result),
            operations_applied=["inpaint"],
            metadata={
                "method": request.method,
                "radius": request.radius,
            },
        )

    except Exception as e:
        logger.exception(f"Inpainting error: {e}")
        return ProcessImageResponse(
            success=False,
            operations_applied=[],
            error=str(e),
        )


@router.post("/recolor", response_model=ProcessImageResponse)
async def recolor_surface(request: RecolorRequest):
    """
    Recolor a surface in the image.

    Uses mask to identify target surface, then applies color transformation
    while preserving texture and lighting.
    """
    try:
        image = load_image_from_url(request.image_url)

        mask = None
        if request.mask_url:
            mask = load_image_from_url(request.mask_url)
            if len(mask.shape) == 3:
                mask = cv2.cvtColor(mask, cv2.COLOR_BGR2GRAY)

        result = recolorizer.recolor(
            image,
            request.target_color,
            mask=mask,
            preserve_texture=request.preserve_texture,
            intensity=request.intensity,
        )

        return ProcessImageResponse(
            success=True,
            result_base64=encode_image_base64(result),
            operations_applied=["recolor"],
            metadata={
                "target_color": request.target_color,
                "preserve_texture": request.preserve_texture,
                "intensity": request.intensity,
            },
        )

    except Exception as e:
        logger.exception(f"Recoloring error: {e}")
        return ProcessImageResponse(
            success=False,
            operations_applied=[],
            error=str(e),
        )


@router.post("/detect-substrate", response_model=SubstrateDetectionResponse)
async def detect_substrate(request: SubstrateDetectionRequest):
    """
    Detect painting substrate/surface type in an image.

    Identifies: drywall, wood, brick, stucco, metal, concrete, vinyl.
    """
    try:
        image = load_image_from_url(request.image_url)

        if request.detect_regions:
            regions = substrate_detector.detect_regions(image, request.grid_size)
            # Find dominant substrate
            substrates = [r["substrate"] for r in regions]
            dominant = max(set(substrates), key=substrates.count)
            avg_confidence = np.mean(
                [r["confidence"] for r in regions if r["substrate"] == dominant]
            )

            return SubstrateDetectionResponse(
                success=True,
                substrate=dominant,
                confidence=float(avg_confidence),
                regions=regions,
            )
        else:
            detection = substrate_detector.detect(image)

            return SubstrateDetectionResponse(
                success=True,
                substrate=detection["substrate"],
                confidence=detection["confidence"],
                all_detections=detection["all_scores"],
                texture_features=detection["texture_features"],
            )

    except Exception as e:
        logger.exception(f"Substrate detection error: {e}")
        return SubstrateDetectionResponse(
            success=False,
            error=str(e),
        )


@router.post("/upload")
async def upload_image(file: UploadFile = File(...)):
    """
    Upload an image for processing.

    Returns the processed image info.
    """
    if not file.content_type or not file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="File must be an image")

    try:
        # Read image
        contents = await file.read()
        nparr = np.frombuffer(contents, np.uint8)
        image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)

        if image is None:
            raise HTTPException(status_code=400, detail="Could not decode image")

        # Get image info
        h, w = image.shape[:2]

        return {
            "success": True,
            "filename": file.filename,
            "content_type": file.content_type,
            "width": w,
            "height": h,
            "size_bytes": len(contents),
        }

    except Exception as e:
        logger.exception(f"Upload error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.get("/colors")
async def get_available_colors():
    """Get list of available paint colors for recoloring."""
    return {
        "colors": recolorizer.get_available_colors(),
        "usage": "Use color name or hex code in recolor requests",
    }
