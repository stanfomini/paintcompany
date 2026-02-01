"""Substrate (paint surface) detection service."""

from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np
from loguru import logger


class SubstrateDetector:
    """
    Detects painting substrate/surface types from images.

    Supported substrates:
    - drywall: Smooth, uniform texture
    - wood: Grain patterns, warm tones
    - brick: Regular pattern, rough texture
    - stucco: Rough, irregular texture
    - metal: Smooth, reflective
    - concrete: Gray, rough texture
    - vinyl: Smooth, uniform, often with seams
    """

    SUBSTRATES = [
        "drywall",
        "wood",
        "brick",
        "stucco",
        "metal",
        "concrete",
        "vinyl",
        "unknown",
    ]

    def __init__(self):
        """Initialize the substrate detector."""
        self.model = None  # Placeholder for trained model
        logger.info("SubstrateDetector initialized (using texture analysis)")

    def detect(self, image: np.ndarray) -> Dict:
        """
        Detect substrate type from image.

        Args:
            image: BGR image as numpy array

        Returns:
            Dictionary with:
            - substrate: Detected substrate type
            - confidence: Confidence score (0-1)
            - all_scores: Scores for all substrate types
            - texture_features: Extracted texture features
        """
        # Extract features
        features = self._extract_features(image)

        # Classify based on features (rule-based for now)
        scores = self._classify(features)

        # Get best match
        best_idx = np.argmax(scores)
        best_substrate = self.SUBSTRATES[best_idx]
        confidence = scores[best_idx]

        return {
            "substrate": best_substrate,
            "confidence": float(confidence),
            "all_scores": {
                self.SUBSTRATES[i]: float(scores[i]) for i in range(len(scores))
            },
            "texture_features": {
                k: float(v) if isinstance(v, (int, float, np.floating)) else v
                for k, v in features.items()
            },
        }

    def _extract_features(self, image: np.ndarray) -> Dict:
        """Extract texture and color features from image."""
        # Convert to grayscale
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

        # Convert to HSV
        hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)

        # Texture features using Laplacian
        laplacian = cv2.Laplacian(gray, cv2.CV_64F)
        texture_variance = laplacian.var()

        # Edge density using Canny
        edges = cv2.Canny(gray, 50, 150)
        edge_density = np.sum(edges > 0) / edges.size

        # Color features
        mean_hue = np.mean(hsv[:, :, 0])
        mean_saturation = np.mean(hsv[:, :, 1])
        mean_value = np.mean(hsv[:, :, 2])

        # Uniformity (inverse of standard deviation)
        color_std = np.std(gray)
        uniformity = 1 / (1 + color_std)

        # Local Binary Pattern for texture
        lbp_features = self._compute_lbp(gray)

        # Gabor filter responses for pattern detection
        gabor_energy = self._compute_gabor_energy(gray)

        return {
            "texture_variance": texture_variance,
            "edge_density": edge_density,
            "mean_hue": mean_hue,
            "mean_saturation": mean_saturation,
            "mean_value": mean_value,
            "uniformity": uniformity,
            "lbp_mean": lbp_features["mean"],
            "lbp_std": lbp_features["std"],
            "gabor_energy": gabor_energy,
            "color_std": color_std,
        }

    def _compute_lbp(self, gray: np.ndarray) -> Dict:
        """Compute simple LBP-like texture features."""
        # Simplified LBP using local variance
        kernel = np.ones((3, 3), np.float32) / 9
        local_mean = cv2.filter2D(gray.astype(np.float32), -1, kernel)
        local_var = cv2.filter2D((gray.astype(np.float32) - local_mean) ** 2, -1, kernel)

        return {"mean": np.mean(local_var), "std": np.std(local_var)}

    def _compute_gabor_energy(self, gray: np.ndarray) -> float:
        """Compute Gabor filter energy for pattern detection."""
        total_energy = 0
        for theta in [0, np.pi / 4, np.pi / 2, 3 * np.pi / 4]:
            kernel = cv2.getGaborKernel(
                (21, 21), sigma=5, theta=theta, lambd=10, gamma=0.5, psi=0
            )
            filtered = cv2.filter2D(gray, cv2.CV_64F, kernel)
            total_energy += np.mean(np.abs(filtered))

        return total_energy / 4

    def _classify(self, features: Dict) -> np.ndarray:
        """
        Classify substrate based on extracted features.

        This is a rule-based classifier. For production, replace with
        a trained CNN model.
        """
        scores = np.zeros(len(self.SUBSTRATES))

        # Drywall: Smooth, uniform, low texture
        if features["texture_variance"] < 500 and features["uniformity"] > 0.02:
            scores[0] += 0.4
        if features["edge_density"] < 0.05:
            scores[0] += 0.3
        if features["mean_saturation"] < 50:
            scores[0] += 0.2

        # Wood: Grain patterns, warm colors
        if features["gabor_energy"] > 20:  # Has directional patterns
            scores[1] += 0.3
        if 10 < features["mean_hue"] < 30:  # Orange/brown hue
            scores[1] += 0.3
        if features["texture_variance"] > 200 and features["texture_variance"] < 2000:
            scores[1] += 0.2

        # Brick: Regular pattern, rough texture, red tones
        if features["edge_density"] > 0.1:
            scores[2] += 0.2
        if 0 < features["mean_hue"] < 15 or features["mean_hue"] > 170:  # Red hue
            scores[2] += 0.3
        if features["texture_variance"] > 1000:
            scores[2] += 0.2

        # Stucco: Very rough texture, irregular
        if features["texture_variance"] > 2000:
            scores[3] += 0.4
        if features["lbp_std"] > 100:
            scores[3] += 0.3

        # Metal: Very smooth, may be reflective
        if features["texture_variance"] < 100:
            scores[4] += 0.3
        if features["uniformity"] > 0.05:
            scores[4] += 0.3
        if features["mean_saturation"] < 30:
            scores[4] += 0.2

        # Concrete: Gray, rough texture
        if features["mean_saturation"] < 40:
            scores[5] += 0.2
        if 90 < features["mean_value"] < 180:  # Medium brightness
            scores[5] += 0.2
        if 500 < features["texture_variance"] < 2000:
            scores[5] += 0.3

        # Vinyl: Very smooth, uniform color
        if features["texture_variance"] < 300:
            scores[6] += 0.3
        if features["color_std"] < 30:
            scores[6] += 0.3
        if features["edge_density"] < 0.03:
            scores[6] += 0.2

        # Unknown: Default low score
        scores[7] = 0.1

        # Normalize scores
        total = np.sum(scores)
        if total > 0:
            scores = scores / total

        return scores

    def detect_regions(
        self, image: np.ndarray, grid_size: int = 4
    ) -> List[Dict]:
        """
        Detect substrate types in different regions of the image.

        Useful for images with multiple surface types.

        Args:
            image: BGR image as numpy array
            grid_size: Number of grid divisions (grid_size x grid_size)

        Returns:
            List of dictionaries with region info and substrate detection
        """
        h, w = image.shape[:2]
        cell_h, cell_w = h // grid_size, w // grid_size

        regions = []
        for i in range(grid_size):
            for j in range(grid_size):
                y1, y2 = i * cell_h, (i + 1) * cell_h
                x1, x2 = j * cell_w, (j + 1) * cell_w

                region_image = image[y1:y2, x1:x2]
                detection = self.detect(region_image)

                regions.append(
                    {
                        "region": {"x1": x1, "y1": y1, "x2": x2, "y2": y2},
                        "row": i,
                        "col": j,
                        **detection,
                    }
                )

        return regions
