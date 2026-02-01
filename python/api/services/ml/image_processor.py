"""Core image processing utilities."""

import io
from pathlib import Path
from typing import Optional, Tuple

import cv2
import numpy as np
from loguru import logger
from PIL import Image, ImageEnhance, ImageFilter


class ImageProcessor:
    """Core image processing operations."""

    @staticmethod
    def load_image(image_path: str) -> np.ndarray:
        """Load an image from path or URL."""
        if image_path.startswith(("http://", "https://")):
            import httpx

            response = httpx.get(image_path)
            response.raise_for_status()
            image_bytes = io.BytesIO(response.content)
            pil_image = Image.open(image_bytes)
            return np.array(pil_image)
        else:
            return cv2.imread(image_path)

    @staticmethod
    def save_image(image: np.ndarray, output_path: str) -> str:
        """Save an image to path."""
        cv2.imwrite(output_path, image)
        return output_path

    @staticmethod
    def enhance_image(
        image: np.ndarray,
        brightness: float = 1.1,
        contrast: float = 1.1,
        sharpness: float = 1.2,
        color: float = 1.1,
    ) -> np.ndarray:
        """
        Enhance image quality.

        Args:
            image: Input image as numpy array (BGR)
            brightness: Brightness factor (1.0 = no change)
            contrast: Contrast factor (1.0 = no change)
            sharpness: Sharpness factor (1.0 = no change)
            color: Color saturation factor (1.0 = no change)

        Returns:
            Enhanced image as numpy array
        """
        # Convert BGR to RGB for PIL
        rgb_image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
        pil_image = Image.fromarray(rgb_image)

        # Apply enhancements
        if brightness != 1.0:
            enhancer = ImageEnhance.Brightness(pil_image)
            pil_image = enhancer.enhance(brightness)

        if contrast != 1.0:
            enhancer = ImageEnhance.Contrast(pil_image)
            pil_image = enhancer.enhance(contrast)

        if sharpness != 1.0:
            enhancer = ImageEnhance.Sharpness(pil_image)
            pil_image = enhancer.enhance(sharpness)

        if color != 1.0:
            enhancer = ImageEnhance.Color(pil_image)
            pil_image = enhancer.enhance(color)

        # Convert back to BGR numpy array
        enhanced = np.array(pil_image)
        return cv2.cvtColor(enhanced, cv2.COLOR_RGB2BGR)

    @staticmethod
    def auto_enhance(image: np.ndarray) -> np.ndarray:
        """
        Automatically enhance image using histogram equalization and denoising.
        """
        # Convert to LAB color space
        lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
        l, a, b = cv2.split(lab)

        # Apply CLAHE (Contrast Limited Adaptive Histogram Equalization) to L channel
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        l = clahe.apply(l)

        # Merge back
        lab = cv2.merge([l, a, b])
        enhanced = cv2.cvtColor(lab, cv2.COLOR_LAB2BGR)

        # Apply denoising
        enhanced = cv2.fastNlMeansDenoisingColored(enhanced, None, 5, 5, 7, 21)

        return enhanced

    @staticmethod
    def detect_blur(image: np.ndarray) -> Tuple[float, bool]:
        """
        Detect if image is blurry using Laplacian variance.

        Returns:
            Tuple of (blur_score, is_blurry)
            Lower score = more blurry
        """
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        laplacian_var = cv2.Laplacian(gray, cv2.CV_64F).var()

        # Threshold for blur detection (empirically determined)
        is_blurry = laplacian_var < 100

        return laplacian_var, is_blurry

    @staticmethod
    def remove_background_simple(image: np.ndarray) -> Tuple[np.ndarray, np.ndarray]:
        """
        Simple background removal using GrabCut algorithm.

        Returns:
            Tuple of (foreground_image, mask)
        """
        mask = np.zeros(image.shape[:2], np.uint8)

        # Create initial rectangle covering most of the image
        h, w = image.shape[:2]
        rect = (int(w * 0.05), int(h * 0.05), int(w * 0.9), int(h * 0.9))

        bgd_model = np.zeros((1, 65), np.float64)
        fgd_model = np.zeros((1, 65), np.float64)

        cv2.grabCut(image, mask, rect, bgd_model, fgd_model, 5, cv2.GC_INIT_WITH_RECT)

        # Create binary mask
        mask2 = np.where((mask == 2) | (mask == 0), 0, 1).astype("uint8")

        # Apply mask to image
        foreground = image * mask2[:, :, np.newaxis]

        return foreground, mask2 * 255

    @staticmethod
    def resize_image(
        image: np.ndarray, max_size: int = 1024, maintain_aspect: bool = True
    ) -> np.ndarray:
        """Resize image while maintaining aspect ratio."""
        h, w = image.shape[:2]

        if maintain_aspect:
            if h > w:
                if h > max_size:
                    ratio = max_size / h
                    new_h, new_w = max_size, int(w * ratio)
                else:
                    return image
            else:
                if w > max_size:
                    ratio = max_size / w
                    new_h, new_w = int(h * ratio), max_size
                else:
                    return image
        else:
            new_h, new_w = max_size, max_size

        return cv2.resize(image, (new_w, new_h), interpolation=cv2.INTER_AREA)
