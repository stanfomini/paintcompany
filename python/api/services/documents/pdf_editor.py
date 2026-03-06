"""PDF editing service using PyMuPDF to apply text edits to original PDFs."""

import re
import textwrap
from difflib import SequenceMatcher

import fitz  # PyMuPDF
from loguru import logger


# Patterns for stripping list prefixes (mirrors what the parser strips)
BULLET_PATTERN = re.compile(
    r"^\s*[\u2022\-\*\u25CF\u25CB\u25AA\u25AB\u2023\u27A4]\s+"
)
NUMBERED_PATTERN = re.compile(r"^\s*\d+[\.\)]\s+")
LETTER_PATTERN = re.compile(r"^\s*[a-zA-Z][\.\)]\s+")


class PdfEditorService:
    """Apply text edits to a PDF while preserving original formatting."""

    def apply_edits(
        self,
        pdf_bytes: bytes,
        original_sections: list[dict],
        edited_sections: list[dict],
    ) -> bytes:
        """
        Apply section edits to a PDF document.

        Compares original and edited sections, finds matching text in the PDF,
        and replaces them while preserving the original document layout.
        """
        changes = self._find_changes(original_sections, edited_sections)
        if not changes:
            logger.info("No text changes detected, returning original PDF")
            return pdf_bytes

        logger.info(f"Applying {len(changes)} text replacement(s) to PDF")

        doc = fitz.open(stream=pdf_bytes, filetype="pdf")

        # Index all text blocks using PyMuPDF's own extraction
        all_blocks = self._index_blocks(doc)

        # Match each change to PDF blocks and collect replacements per page
        page_replacements: dict[int, list[tuple]] = {}
        matched_blocks: set[tuple[int, int]] = set()

        for change in changes:
            match = self._find_match(change["old_text"], all_blocks, matched_blocks)
            if match:
                page_num, block_indices, _ = match
                blocks = [all_blocks[page_num][bi]["block"] for bi in block_indices]
                if page_num not in page_replacements:
                    page_replacements[page_num] = []
                page_replacements[page_num].append((blocks, change["new_text"]))
                for bi in block_indices:
                    matched_blocks.add((page_num, bi))
                logger.debug(
                    f"Change {change['index']}: matched {len(block_indices)} block(s) "
                    f"on page {page_num + 1}"
                )
            else:
                logger.warning(
                    f"Change {change['index']}: no match found for "
                    f"'{change['old_text'][:80]}...'"
                )

        # Apply replacements page by page
        for page_num, replacements in page_replacements.items():
            page = doc[page_num]
            self._apply_page_replacements(page, replacements)

        result = doc.tobytes()
        doc.close()

        total = sum(len(r) for r in page_replacements.values())
        logger.info(
            f"PDF edits applied: {total} replacement(s) across "
            f"{len(page_replacements)} page(s)"
        )
        return result

    def _apply_page_replacements(
        self, page, replacements: list[tuple]
    ) -> None:
        """
        Apply all replacements on a single page.

        For each replacement:
        1. Extract the original line positions, fonts, and spacing from the block
        2. Redact the block area (batch all redactions first)
        3. Re-render the new text line by line at the original positions
        """
        render_tasks = []

        # Phase 1: Analyze each block and prepare redaction + render info
        for blocks, new_text in replacements:
            # Collect line info from all blocks in this match group
            line_info = self._extract_line_info(blocks)
            if not line_info:
                continue

            # Compute the union rect of all matched blocks
            rects = [fitz.Rect(b["bbox"]) for b in blocks]
            union_rect = rects[0]
            for r in rects[1:]:
                union_rect |= r

            # Add redaction annotation
            annot = page.add_redact_annot(union_rect)
            annot.set_colors(fill=(1, 1, 1))

            render_tasks.append((union_rect, new_text, line_info))

        if not render_tasks:
            return

        # Phase 2: Apply all redactions at once
        page.apply_redactions(images=0, graphics=0)

        # Phase 3: Re-render each replacement's text line by line
        for union_rect, new_text, line_info in render_tasks:
            self._render_text_at_positions(page, union_rect, new_text, line_info)

    def _extract_line_info(self, blocks: list[dict]) -> dict | None:
        """
        Extract formatting and position info from block lines.

        Returns dict with: x_start, y_start, line_spacing, font, size, color,
                          chars_per_line, content_width
        """
        origins = []
        font_name = None
        font_size = None
        font_flags = 0
        color_int = 0

        for block in blocks:
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    origin = span.get("origin")
                    if origin:
                        origins.append(origin)
                    elif span.get("bbox"):
                        # Fallback: use bbox bottom-left as baseline
                        bbox = span["bbox"]
                        origins.append((bbox[0], bbox[3]))

                    if font_name is None:
                        font_name = span.get("font", "Courier")
                        font_size = span.get("size", 10.0)
                        font_flags = span.get("flags", 0)
                        color_int = span.get("color", 0)
                    break  # One span per line is enough for position

        if not origins or font_size is None:
            return None

        # Calculate line spacing from consecutive origins
        if len(origins) >= 2:
            spacings = [
                origins[i + 1][1] - origins[i][1]
                for i in range(len(origins) - 1)
                if origins[i + 1][1] > origins[i][1]
            ]
            line_spacing = spacings[0] if spacings else font_size
        else:
            line_spacing = font_size

        # Map font to base14
        mapped_font = self._map_font(font_name, [font_flags])

        # Is it monospaced?
        is_mono = bool(font_flags & 8) or any(
            k in font_name.lower() for k in ("courier", "mono", "consolas")
        )

        # Calculate content width from block bbox
        all_bboxes = [fitz.Rect(b["bbox"]) for b in blocks]
        union = all_bboxes[0]
        for r in all_bboxes[1:]:
            union |= r
        content_width = union.width

        # Calculate chars per line for monospace fonts
        if is_mono:
            # Courier character width = size * 0.6
            char_width = font_size * 0.6
            chars_per_line = int(content_width / char_width)
        else:
            # Approximate for proportional fonts
            char_width = font_size * 0.5
            chars_per_line = int(content_width / char_width)

        # Convert color
        r = ((color_int >> 16) & 0xFF) / 255
        g = ((color_int >> 8) & 0xFF) / 255
        b = (color_int & 0xFF) / 255

        return {
            "x_start": origins[0][0],
            "y_start": origins[0][1],
            "line_spacing": line_spacing,
            "fontname": mapped_font,
            "fontsize": font_size,
            "color": (r, g, b),
            "chars_per_line": max(chars_per_line, 20),
            "content_width": content_width,
            "is_mono": is_mono,
        }

    def _render_text_at_positions(
        self, page, rect: fitz.Rect, new_text: str, info: dict
    ) -> None:
        """Render new text line by line matching the original positions."""
        x = info["x_start"]
        y = info["y_start"]
        fontname = info["fontname"]
        fontsize = info["fontsize"]
        color = info["color"]
        line_spacing = info["line_spacing"]
        chars_per_line = info["chars_per_line"]

        # Wrap the new text to fit within the original content width
        wrapped = self._wrap_text(new_text, chars_per_line)

        for line_text in wrapped:
            page.insert_text(
                fitz.Point(x, y),
                line_text,
                fontname=fontname,
                fontsize=fontsize,
                color=color,
            )
            y += line_spacing

    def _wrap_text(self, text: str, chars_per_line: int) -> list[str]:
        """Wrap text to fit within a character width, preserving existing breaks."""
        if not text:
            return [""]

        # If text already fits on one line, no wrapping needed
        if len(text) <= chars_per_line:
            return [text]

        # Use textwrap for clean word-boundary wrapping
        return textwrap.wrap(
            text,
            width=chars_per_line,
            break_long_words=True,
            break_on_hyphens=True,
        ) or [""]

    # --- Matching logic ---

    def _find_changes(
        self, original: list[dict], edited: list[dict]
    ) -> list[dict]:
        """Compare sections and return list of changes."""
        changes = []
        for i, (orig, edit) in enumerate(zip(original, edited)):
            old = orig.get("text", "").strip()
            new = edit.get("text", "").strip()
            if old != new and old:
                changes.append(
                    {
                        "index": i,
                        "old_text": old,
                        "new_text": new,
                    }
                )
        return changes

    def _index_blocks(self, doc) -> list[list[dict]]:
        """Build an index of all text blocks in the document."""
        all_blocks = []
        for page_num in range(len(doc)):
            page = doc[page_num]
            text_dict = page.get_text("dict")
            blocks = []
            for block in text_dict.get("blocks", []):
                if block.get("type") != 0:
                    continue
                block_text = self._extract_block_text(block)
                if not block_text.strip():
                    continue
                blocks.append(
                    {
                        "block": block,
                        "text": block_text,
                        "norm": self._normalize(block_text),
                    }
                )
            all_blocks.append(blocks)
        return all_blocks

    def _find_match(
        self,
        old_text: str,
        all_blocks: list[list[dict]],
        matched: set[tuple[int, int]],
    ) -> tuple[int, list[int], fitz.Rect] | None:
        """
        Find the best matching block(s) for old_text.

        Returns (page_num, block_indices, union_rect) or None.

        Strategies:
        1. Exact normalized match to a single block
        2. Multi-block match (section spans consecutive blocks)
        3. Best fuzzy match above threshold
        """
        old_norm = self._normalize(old_text)
        if not old_norm:
            return None

        # Strategy 1: Exact match
        for page_num, blocks in enumerate(all_blocks):
            for bi, binfo in enumerate(blocks):
                if (page_num, bi) in matched:
                    continue
                if old_norm == binfo["norm"]:
                    rect = fitz.Rect(binfo["block"]["bbox"])
                    return (page_num, [bi], rect)

        # Strategy 2: Multi-block match
        for page_num, blocks in enumerate(all_blocks):
            for bi, binfo in enumerate(blocks):
                if (page_num, bi) in matched:
                    continue
                prefix_len = min(30, len(binfo["norm"]))
                if not old_norm.startswith(binfo["norm"][:prefix_len]):
                    continue

                combined = binfo["norm"]
                group = [bi]

                for bj in range(bi + 1, min(bi + 10, len(blocks))):
                    if (page_num, bj) in matched:
                        break
                    combined += " " + blocks[bj]["norm"]
                    group.append(bj)

                    ratio = SequenceMatcher(None, old_norm, combined).ratio()
                    if ratio > 0.85:
                        rects = [
                            fitz.Rect(blocks[g]["block"]["bbox"]) for g in group
                        ]
                        union_rect = rects[0]
                        for r in rects[1:]:
                            union_rect |= r
                        return (page_num, group, union_rect)

        # Strategy 3: Best fuzzy match
        best = None
        best_ratio = 0.7

        for page_num, blocks in enumerate(all_blocks):
            for bi, binfo in enumerate(blocks):
                if (page_num, bi) in matched:
                    continue
                ratio = SequenceMatcher(None, old_norm, binfo["norm"]).ratio()
                if ratio > best_ratio:
                    best_ratio = ratio
                    best = (page_num, bi)

        if best:
            page_num, bi = best
            rect = fitz.Rect(all_blocks[page_num][bi]["block"]["bbox"])
            logger.debug(f"Fuzzy match with similarity {best_ratio:.2f}")
            return (page_num, [bi], rect)

        return None

    # --- Helpers ---

    def _extract_block_text(self, block: dict) -> str:
        """Extract all text from a PyMuPDF block dict."""
        parts = []
        for line in block.get("lines", []):
            line_text = ""
            for span in line.get("spans", []):
                line_text += span.get("text", "")
            parts.append(line_text)
        return " ".join(parts).strip()

    def _normalize(self, text: str) -> str:
        """Normalize text for comparison."""
        text = BULLET_PATTERN.sub("", text)
        text = NUMBERED_PATTERN.sub("", text)
        text = LETTER_PATTERN.sub("", text)
        return re.sub(r"\s+", " ", text).strip().lower()

    def _map_font(self, pdf_font_name: str, flags_list: list[int]) -> str:
        """Map a PDF font name to a PyMuPDF base14 font name."""
        name = (
            pdf_font_name.lower()
            .replace("-", "")
            .replace(" ", "")
            .replace("_", "")
        )

        is_bold = any(k in name for k in ("bold", "black", "heavy"))
        is_italic = any(k in name for k in ("italic", "oblique"))

        if not is_bold and not is_italic and flags_list:
            dominant_flags = max(set(flags_list), key=flags_list.count)
            is_bold = is_bold or bool(dominant_flags & (1 << 4))
            is_italic = is_italic or bool(dominant_flags & (1 << 1))

        if any(
            k in name
            for k in (
                "times", "tiro", "serif", "georgia", "garamond",
                "cambria", "palatino", "bookman",
            )
        ):
            family = "ti"
        elif any(
            k in name
            for k in ("courier", "cour", "mono", "consolas", "menlo")
        ):
            family = "co"
        elif "symbol" in name:
            return "symb"
        elif "zapf" in name or "dingbat" in name:
            return "zadb"
        else:
            family = "he"

        variants = {
            "he": {"base": "helv", "b": "hebo", "i": "heit", "bi": "hebi"},
            "ti": {"base": "tiro", "b": "tibo", "i": "tiit", "bi": "tibi"},
            "co": {"base": "cour", "b": "cobo", "i": "coit", "bi": "cobi"},
        }

        v = variants[family]
        if is_bold and is_italic:
            return v["bi"]
        if is_bold:
            return v["b"]
        if is_italic:
            return v["i"]
        return v["base"]
