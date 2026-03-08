"""Stamp signatures, initials, and date text onto a PDF using PyMuPDF."""

import base64
import struct
from datetime import datetime

import fitz  # PyMuPDF
from loguru import logger


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

                if ptype == "signature":
                    self._stamp_signature(
                        page, x, y, w, signature_data, signature_type
                    )
                elif ptype == "initials":
                    self._stamp_initials(page, x, y, w, user_name)
                elif ptype == "date":
                    self._stamp_date(page, x, y, w)
                else:
                    logger.warning(f"Unknown placement type: {ptype}")
            except Exception as e:
                logger.error(f"Failed to stamp placement {placement}: {e}")
                continue

        result = doc.tobytes()
        doc.close()
        return result

    def _stamp_signature(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
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
                self._place_image(page, x, y, w, img_bytes)
            except Exception as e:
                logger.error(f"Failed to place signature image: {e}")
                # Fallback: render as text "[Signature]"
                page.insert_text(
                    fitz.Point(x, y + 14),
                    "[Signed]",
                    fontname="tiit",
                    fontsize=14,
                    color=(0, 0, 0.5),
                )
        else:
            # Typed signature
            fontsize = min(w * 0.15, 24)
            if fontsize < 8:
                fontsize = 14
            page.insert_text(
                fitz.Point(x, y + fontsize),
                signature_data,
                fontname="tiit",
                fontsize=fontsize,
                color=(0, 0, 0),
            )

    def _stamp_initials(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        user_name: str | None,
    ) -> None:
        """Stamp auto-derived initials onto the page."""
        initials = self._derive_initials(user_name)
        fontsize = min(w * 0.5, 20)
        if fontsize < 8:
            fontsize = 14
        page.insert_text(
            fitz.Point(x, y + fontsize),
            initials,
            fontname="tiit",
            fontsize=fontsize,
            color=(0, 0, 0),
        )

    def _stamp_date(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
    ) -> None:
        """Stamp the current date as 'Month Day, Year'."""
        date_str = datetime.now().strftime("%B %d, %Y")
        fontsize = min(w * 0.25, 12)
        if fontsize < 8:
            fontsize = 11
        page.insert_text(
            fitz.Point(x, y + fontsize),
            date_str,
            fontname="tiro",
            fontsize=fontsize,
            color=(0, 0, 0),
        )

    def _place_image(
        self,
        page: fitz.Page,
        x: float,
        y: float,
        w: float,
        img_bytes: bytes,
    ) -> None:
        """Place a PNG image onto the page at the given position and width."""
        # Get image dimensions from PNG header or fallback
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
