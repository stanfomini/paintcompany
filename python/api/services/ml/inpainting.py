"""Image inpainting (object removal) service."""

from typing import Optional, Tuple

import cv2
import numpy as np
from loguru import logger


class ImageInpainter:
    """
    Remove objects from images using inpainting techniques.

    Supports:
    - OpenCV Telea inpainting
    - OpenCV Navier-Stokes inpainting
    - (Future) LaMa deep learning inpainting
    """

    def __init__(self):
        """Initialize the inpainter."""
        self.lama_model = None  # Placeholder for LaMa model
        logger.info("ImageInpainter initialized (using OpenCV inpainting)")

    def inpaint(
        self,
        image: np.ndarray,
        mask: np.ndarray,
        method: str = "telea",
        radius: int = 5,
    ) -> np.ndarray:
        """
        Remove objects indicated by mask from image.

        Args:
            image: BGR image as numpy array
            mask: Binary mask where 255 = area to remove, 0 = keep
            method: Inpainting method ('telea', 'ns', or 'lama')
            radius: Inpainting radius for OpenCV methods

        Returns:
            Inpainted image as numpy array
        """
        # Ensure mask is grayscale
        if len(mask.shape) == 3:
            mask = cv2.cvtColor(mask, cv2.COLOR_BGR2GRAY)

        # Ensure mask is binary
        _, mask = cv2.threshold(mask, 127, 255, cv2.THRESH_BINARY)

        if method == "lama":
            return self._inpaint_lama(image, mask)
        elif method == "ns":
            return self._inpaint_ns(image, mask, radius)
        else:  # default to telea
            return self._inpaint_telea(image, mask, radius)

    def _inpaint_telea(
        self, image: np.ndarray, mask: np.ndarray, radius: int
    ) -> np.ndarray:
        """
        Telea inpainting algorithm.

        Fast and works well for small regions.
        """
        return cv2.inpaint(image, mask, radius, cv2.INPAINT_TELEA)

    def _inpaint_ns(
        self, image: np.ndarray, mask: np.ndarray, radius: int
    ) -> np.ndarray:
        """
        Navier-Stokes based inpainting.

        Better for larger regions but slower.
        """
        return cv2.inpaint(image, mask, radius, cv2.INPAINT_NS)

    def _inpaint_lama(self, image: np.ndarray, mask: np.ndarray) -> np.ndarray:
        """
        LaMa deep learning inpainting.

        Best quality but requires model to be loaded.
        Falls back to Telea if model not available.
        """
        if self.lama_model is None:
            logger.warning("LaMa model not loaded, falling back to Telea")
            return self._inpaint_telea(image, mask, 10)

        # TODO: Implement LaMa inference
        # This would require loading the LaMa model
        pass

    def expand_mask(
        self, mask: np.ndarray, expansion: int = 5, iterations: int = 1
    ) -> np.ndarray:
        """
        Expand mask to include surrounding pixels.

        Useful for removing artifacts around objects.

        Args:
            mask: Binary mask
            expansion: Kernel size for dilation
            iterations: Number of dilation iterations

        Returns:
            Expanded mask
        """
        kernel = cv2.getStructuringElement(
            cv2.MORPH_ELLIPSE, (expansion, expansion)
        )
        return cv2.dilate(mask, kernel, iterations=iterations)

    def create_mask_from_color(
        self,
        image: np.ndarray,
        color_bgr: Tuple[int, int, int],
        tolerance: int = 30,
    ) -> np.ndarray:
        """
        Create a mask based on color similarity.

        Args:
            image: BGR image
            color_bgr: Target color in BGR format
            tolerance: Color tolerance (0-255)

        Returns:
            Binary mask where similar colors are 255
        """
        lower = np.array([max(0, c - tolerance) for c in color_bgr])
        upper = np.array([min(255, c + tolerance) for c in color_bgr])

        mask = cv2.inRange(image, lower, upper)
        return mask

    def remove_object_interactive(
        self,
        image: np.ndarray,
        points: list,
        mode: str = "foreground",
    ) -> Tuple[np.ndarray, np.ndarray]:
        """
        Remove object using interactive selection points.

        Uses GrabCut algorithm with point hints.

        Args:
            image: BGR image
            points: List of (x, y) points indicating object
            mode: 'foreground' to remove, 'background' to keep

        Returns:
            Tuple of (inpainted_image, mask)
        """
        if not points:
            return image, np.zeros(image.shape[:2], dtype=np.uint8)

        # Create initial mask
        mask = np.zeros(image.shape[:2], np.uint8)

        # Create rectangle from points
        points_array = np.array(points)
        x_min, y_min = points_array.min(axis=0)
        x_max, y_max = points_array.max(axis=0)

        # Add padding
        h, w = image.shape[:2]
        padding = 20
        x_min = max(0, x_min - padding)
        y_min = max(0, y_min - padding)
        x_max = min(w, x_max + padding)
        y_max = min(h, y_max + padding)

        rect = (x_min, y_min, x_max - x_min, y_max - y_min)

        # Run GrabCut
        bgd_model = np.zeros((1, 65), np.float64)
        fgd_model = np.zeros((1, 65), np.float64)

        cv2.grabCut(
            image, mask, rect, bgd_model, fgd_model, 5, cv2.GC_INIT_WITH_RECT
        )

        # Create binary mask
        if mode == "foreground":
            # Mark foreground for removal
            final_mask = np.where((mask == 1) | (mask == 3), 255, 0).astype(np.uint8)
        else:
            # Mark background for removal
            final_mask = np.where((mask == 0) | (mask == 2), 255, 0).astype(np.uint8)

        # Expand mask slightly
        final_mask = self.expand_mask(final_mask, 3, 1)

        # Inpaint
        result = self.inpaint(image, final_mask, method="telea", radius=7)

        return result, final_mask
