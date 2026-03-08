"""Stamp signatures, initials, and date text onto a PDF using PyMuPDF."""

import base64
import io
import struct
from datetime import datetime
from pathlib import Path

import fitz  # PyMuPDF
from loguru import logger
from PIL import Image, ImageDraw, ImageFont

# Path to cursive font for typed signatures
CURSIVE_FONT_PATH = Path(__file__).parent / "fonts" / "DancingScript.ttf"


class SignatureStamperService:
    """Overlay signature images, initials, and date stamps onto PDF pages."""

    def apply_signatures(
        self,
        pdf_bytes: bytes,
        placements: list[dict],
        signature_data: str | None = None,
        signature_type: str | None = None,
        user_name: str | None = None,
    ) -> bytes:
        """
        Stamp placements onto a PDF and return the modified bytes.

        Each placement: {type, page, x, y, width}
        - type: "signature", "initials", "date"
        - page: 1-based page number
        - x, y: position as fraction (0-1) of page dimensions (y from top)
        - width: width as fraction of page width
        """
        if not placements:
            return pdf_bytes

        doc = fitz.open(stream=pdf_bytes, filetype="pdf")

        for placement in placements:
            try:
                ptype = placement.get("type")
                page_num = int(placement.get("page", 1)) - 1
                x_pct = float(placement.get("x", 0))
                y_pct = float(placement.get("y", 0))
                w_pct = float(placement.get("width", 0.2))

                if page_num < 0 or page_num >= len(doc):
                    logger.warning(
                        f"Skipping placement on invalid page {page_num + 1}"
                    )
                    continue

                page = doc[page_num]
                pw = page.rect.width
                ph = page.rect.height

                x = x_pct * pw
                y = y_pct * ph
                w = w_pct * pw
                h_pct = float(placement.get("height", 0))
                h = h_pct * ph if h_pct > 0 else None

                if ptype == "signature":
                    self._stamp_signature(
                        page, x, y, w, h, signature_data, signature_type
                    )
                elif ptype == "initials":
                    self._stamp_initials(page, x, y, w, h, user_name)
                elif ptype == "date":
                    self._stamp_date(page, x, y, w, h)
                elif ptype == "text":
                    text_content = placement.get("text", "")
                    self._stamp_text(page, x, y, w, h or w * 0.4, text_content)
                else:
                    logger.warning(f"Unknown placement type: {ptype}")
            except Exception as e:
                logger.error(f"Failed to stamp placement {placement}: {e}")
                continue

        result = doc.tobytes()
        doc.close()
        return result

    def _render_cursive_text_image(
        self, text: str, font_size: int = 48, color: tuple = (0, 0, 0)
    ) -> bytes:
        """Render text using the cursive font into a transparent PNG image."""
        try:
            font = ImageFont.truetype(str(CURSIVE_FONT_PATH), font_size)
        except Exception:
            logger.warning("Cursive font not found, falling back to default")
            font = ImageFont.load_default()

        # Measure text size
        dummy_img = Image.new("RGBA", (1, 1), (0, 0, 0, 0))
        draw = ImageDraw.Draw(dummy_img)
        bbox = draw.textbbox((0, 0), text, font=font)
        text_w = bbox[2] - bbox[0] + 10
        text_h = bbox[3] - bbox[1] + 10

        # Render on transparent background
        img = Image.new("RGBA", (text_w, text_h), (0, 0, 0, 0))
        draw = ImageDraw.Draw(img)
        draw.text((-bbox[0] + 5, -bbox[1] + 5), text, fill=(*color, 255), font=font)

        buf = io.BytesIO()
        img.save(buf, format="PNG")
        return buf.getvalue()

    def _stamp_signature(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        h: float | None,
        signature_data: str | None,
        signature_type: str | None,
    ) -> None:
        """Stamp a signature image or cursive text onto the page."""
        if not signature_data:
            logger.warning("No signature data provided")
            return

        if signature_type == "drawn" and signature_data.startswith("data:image"):
            try:
                _, b64 = signature_data.split(",", 1)
                img_bytes = base64.b64decode(b64)
                self._place_image(page, x, y, w, img_bytes, h)
            except Exception as e:
                logger.error(f"Failed to place signature image: {e}")
                page.insert_text(
                    fitz.Point(x, y + 14),
                    "[Signed]",
                    fontname="tiit",
                    fontsize=14,
                    color=(0, 0, 0.5),
                )
        else:
            # Typed signature — render as cursive image for handwriting look
            target_h = h if h and h > 0 else w * 0.3
            font_size = max(int(target_h * 1.5), 24)
            img_bytes = self._render_cursive_text_image(
                signature_data, font_size=font_size
            )
            self._place_image(page, x, y, w, img_bytes, h)

    def _stamp_initials(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        h: float | None,
        user_name: str | None,
    ) -> None:
        """Stamp auto-derived initials onto the page in cursive."""
        initials = self._derive_initials(user_name)
        target_h = h if h and h > 0 else w * 0.7
        font_size = max(int(target_h * 1.5), 24)
        img_bytes = self._render_cursive_text_image(
            initials, font_size=font_size
        )
        self._place_image(page, x, y, w, img_bytes, h)

    def _stamp_date(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        h: float | None,
    ) -> None:
        """Stamp the current date as 'Month Day, Year'."""
        date_str = datetime.now().strftime("%B %d, %Y")
        fontsize = min(w * 0.25, 12)
        if h and h > 0:
            fontsize = min(h * 0.7, 12)
        if fontsize < 8:
            fontsize = 11
        page.insert_text(
            fitz.Point(x, y + fontsize),
            date_str,
            fontname="tiro",
            fontsize=fontsize,
            color=(0, 0, 0),
        )

    def _stamp_text(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        h: float,
        text: str,
    ) -> None:
        """Stamp arbitrary text onto the page, wrapping within the given width and height."""
        if not text or not text.strip():
            return

        fontsize = min(w * 0.12, 11)
        if fontsize < 7:
            fontsize = 10

        lines = text.strip().split("\n")
        line_height = fontsize * 1.4
        current_y = y + fontsize
        max_y = y + h  # Don't render beyond the box height

        for line in lines:
            if current_y > max_y:
                break

            # Wrap long lines within the width
            words = line.split()
            if not words:
                current_y += line_height
                continue

            current_line = ""
            for word in words:
                test_line = f"{current_line} {word}".strip()
                # Approximate text width: ~0.5 * fontsize per character
                approx_width = len(test_line) * fontsize * 0.5
                if approx_width > w and current_line:
                    if current_y > max_y:
                        break
                    page.insert_text(
                        fitz.Point(x, current_y),
                        current_line,
                        fontname="tiro",
                        fontsize=fontsize,
                        color=(0, 0, 0),
                    )
                    current_y += line_height
                    current_line = word
                else:
                    current_line = test_line

            if current_line and current_y <= max_y:
                page.insert_text(
                    fitz.Point(x, current_y),
                    current_line,
                    fontname="tiro",
                    fontsize=fontsize,
                    color=(0, 0, 0),
                )
                current_y += line_height

    def _place_image(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        img_bytes: bytes,
        h: float | None = None,
    ) -> None:
        """Place a PNG image onto the page at the given position and width."""
        if not h or h <= 0:
            aspect = self._get_image_aspect(img_bytes)
            h = w * aspect
        rect = fitz.Rect(x, y, x + w, y + h)
        page.insert_image(rect, stream=img_bytes)

    @staticmethod
    def _get_image_aspect(img_bytes: bytes) -> float:
        """Get aspect ratio (h/w) from PNG header bytes."""
        try:
            # PNG header: 8 magic bytes, then IHDR chunk (4 len + 4 type + 4 width + 4 height)
            if img_bytes[:4] == b"\x89PNG":
                width = struct.unpack(">I", img_bytes[16:20])[0]
                height = struct.unpack(">I", img_bytes[20:24])[0]
                if width > 0:
                    return height / width
        except Exception:
            pass
        return 0.4  # Default aspect ratio for signatures

    @staticmethod
    def _derive_initials(name: str | None) -> str:
        """Derive initials from a full name: first char of first + last word."""
        if not name:
            return "XX"
        parts = name.strip().split()
        if len(parts) == 1:
            return parts[0][0].upper()
        return (parts[0][0] + parts[-1][0]).upper()
