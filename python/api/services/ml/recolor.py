"""Surface recoloring service."""

import re
from typing import Optional, Tuple

import cv2
import numpy as np
from loguru import logger


class SurfaceRecolorizer:
    """
    Recolor surfaces in images while preserving texture and lighting.

    Supports:
    - Full image recoloring
    - Masked region recoloring
    - Texture preservation
    - Lighting preservation
    """

    # Common paint colors (Sherwin-Williams inspired)
    PAINT_COLORS = {
        "sea_salt": "#C0D6D2",
        "agreeable_gray": "#D4CDC5",
        "accessible_beige": "#D4C8B5",
        "repose_gray": "#C2BEB8",
        "mindful_gray": "#B4AFA7",
        "pure_white": "#F5F5F5",
        "extra_white": "#FAFAFA",
        "alabaster": "#F2EDE3",
        "dover_white": "#F0E8DA",
        "creamy": "#F5EAD6",
        "navy_blue": "#2C3E50",
        "tricorn_black": "#2E2E2E",
        "iron_ore": "#4A4A4A",
        "comfort_gray": "#C3C0B8",
        "worldly_gray": "#ABA99E",
    }

    def __init__(self):
        """Initialize the recolorizer."""
        logger.info("SurfaceRecolorizer initialized")

    def recolor(
        self,
        image: np.ndarray,
        target_color: str,
        mask: Optional[np.ndarray] = None,
        preserve_texture: bool = True,
        intensity: float = 0.8,
    ) -> np.ndarray:
        """
        Recolor an image or masked region.

        Args:
            image: BGR image as numpy array
            target_color: Hex color code or color name
            mask: Optional binary mask (255 = recolor, 0 = keep)
            preserve_texture: Whether to preserve original texture
            intensity: Color intensity (0-1), lower = more subtle

        Returns:
            Recolored image as numpy array
        """
        # Parse target color
        rgb = self._parse_color(target_color)
        if rgb is None:
            raise ValueError(f"Invalid color: {target_color}")

        # Convert target to BGR
        target_bgr = np.array([rgb[2], rgb[1], rgb[0]], dtype=np.float32)

        if mask is None:
            # Full image recolor
            return self._recolor_full(image, target_bgr, preserve_texture, intensity)
        else:
            # Masked recolor
            return self._recolor_masked(
                image, target_bgr, mask, preserve_texture, intensity
            )

    def _parse_color(self, color: str) -> Optional[Tuple[int, int, int]]:
        """Parse color string to RGB tuple."""
        # Check if it's a named color
        color_lower = color.lower().replace(" ", "_").replace("-", "_")
        if color_lower in self.PAINT_COLORS:
            color = self.PAINT_COLORS[color_lower]

        # Parse hex color
        if color.startswith("#"):
            color = color[1:]

        if len(color) == 6:
            try:
                r = int(color[0:2], 16)
                g = int(color[2:4], 16)
                b = int(color[4:6], 16)
                return (r, g, b)
            except ValueError:
                return None

        # Try to parse RGB format
        match = re.match(r"rgb\((\d+),\s*(\d+),\s*(\d+)\)", color.lower())
        if match:
            return (int(match.group(1)), int(match.group(2)), int(match.group(3)))

        return None

    def _recolor_full(
        self,
        image: np.ndarray,
        target_bgr: np.ndarray,
        preserve_texture: bool,
        intensity: float,
    ) -> np.ndarray:
        """Recolor the full image."""
        if preserve_texture:
            return self._recolor_preserve_texture(image, target_bgr, intensity)
        else:
            return self._recolor_simple(image, target_bgr, intensity)

    def _recolor_masked(
        self,
        image: np.ndarray,
        target_bgr: np.ndarray,
        mask: np.ndarray,
        preserve_texture: bool,
        intensity: float,
    ) -> np.ndarray:
        """Recolor only masked regions."""
        # Ensure mask is binary
        if len(mask.shape) == 3:
            mask = cv2.cvtColor(mask, cv2.COLOR_BGR2GRAY)
        mask = (mask > 127).astype(np.uint8) * 255

        # Recolor the full image
        if preserve_texture:
            recolored = self._recolor_preserve_texture(image, target_bgr, intensity)
        else:
            recolored = self._recolor_simple(image, target_bgr, intensity)

        # Blend using mask
        mask_3ch = cv2.cvtColor(mask, cv2.COLOR_GRAY2BGR).astype(np.float32) / 255.0

        # Feather the mask edges for smoother blending
        mask_blurred = cv2.GaussianBlur(mask_3ch, (21, 21), 0)

        result = (
            image.astype(np.float32) * (1 - mask_blurred)
            + recolored.astype(np.float32) * mask_blurred
        )

        return result.astype(np.uint8)

    def _recolor_preserve_texture(
        self, image: np.ndarray, target_bgr: np.ndarray, intensity: float
    ) -> np.ndarray:
        """
        Recolor while preserving texture and lighting.

        Uses LAB color space to modify color while keeping luminance.
        """
        # Convert to LAB
        lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB).astype(np.float32)

        # Get target color in LAB
        target_rgb = np.array(
            [[[[target_bgr[2], target_bgr[1], target_bgr[0]]]]], dtype=np.uint8
        )
        target_lab = cv2.cvtColor(target_rgb, cv2.COLOR_RGB2LAB).astype(np.float32)
        target_a = target_lab[0, 0, 0, 1]
        target_b = target_lab[0, 0, 0, 2]

        # Modify A and B channels while keeping L (luminance)
        lab[:, :, 1] = lab[:, :, 1] * (1 - intensity) + target_a * intensity
        lab[:, :, 2] = lab[:, :, 2] * (1 - intensity) + target_b * intensity

        # Clip values to valid range
        lab = np.clip(lab, 0, 255).astype(np.uint8)

        # Convert back to BGR
        return cv2.cvtColor(lab, cv2.COLOR_LAB2BGR)

    def _recolor_simple(
        self, image: np.ndarray, target_bgr: np.ndarray, intensity: float
    ) -> np.ndarray:
        """Simple color overlay with blending."""
        # Create solid color image
        color_image = np.full_like(image, target_bgr, dtype=np.float32)

        # Blend with original
        result = (
            image.astype(np.float32) * (1 - intensity) + color_image * intensity
        )

        return result.astype(np.uint8)

    def color_transfer(
        self, source: np.ndarray, target: np.ndarray, mask: Optional[np.ndarray] = None
    ) -> np.ndarray:
        """
        Transfer colors from target image to source image.

        Useful for making surfaces match a reference color scheme.

        Args:
            source: Source image to recolor
            target: Target image with desired colors
            mask: Optional mask for source image

        Returns:
            Source image with target's color characteristics
        """
        # Convert to LAB
        source_lab = cv2.cvtColor(source, cv2.COLOR_BGR2LAB).astype(np.float32)
        target_lab = cv2.cvtColor(target, cv2.COLOR_BGR2LAB).astype(np.float32)

        # Compute statistics
        source_mean = np.mean(source_lab, axis=(0, 1))
        source_std = np.std(source_lab, axis=(0, 1))
        target_mean = np.mean(target_lab, axis=(0, 1))
        target_std = np.std(target_lab, axis=(0, 1))

        # Prevent division by zero
        source_std = np.where(source_std == 0, 1, source_std)

        # Transfer
        result_lab = (source_lab - source_mean) * (target_std / source_std) + target_mean

        # Clip and convert back
        result_lab = np.clip(result_lab, 0, 255).astype(np.uint8)
        result = cv2.cvtColor(result_lab, cv2.COLOR_LAB2BGR)

        if mask is not None:
            # Apply only to masked region
            if len(mask.shape) == 3:
                mask = cv2.cvtColor(mask, cv2.COLOR_BGR2GRAY)
            mask_3ch = cv2.cvtColor(mask, cv2.COLOR_GRAY2BGR).astype(np.float32) / 255.0
            result = (
                source.astype(np.float32) * (1 - mask_3ch)
                + result.astype(np.float32) * mask_3ch
            ).astype(np.uint8)

        return result

    def get_available_colors(self) -> dict:
        """Return dictionary of available paint color names and their hex codes."""
        return self.PAINT_COLORS.copy()
