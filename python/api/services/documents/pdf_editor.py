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

        Strategy: find the matching block in the PDF, then apply the user's
        edit as a diff to the ORIGINAL formatted block text (preserving
        indentation, line structure, padding, font). Only the changed
        characters are modified; everything else stays exactly as-is.
        """
        changes = self._find_changes(original_sections, edited_sections)
        if not changes:
            logger.info("No text changes detected, returning original PDF")
            return pdf_bytes

        logger.info(f"Applying {len(changes)} text replacement(s) to PDF")

        doc = fitz.open(stream=pdf_bytes, filetype="pdf")
        all_blocks = self._index_blocks(doc)

        # Match changes to blocks and collect per-page work
        page_work: dict[int, list[dict]] = {}
        matched: set[tuple[int, int]] = set()

        for change in changes:
            match = self._find_match(
                change["old_text"], all_blocks, matched
            )
            if not match:
                logger.warning(
                    f"Change {change['index']}: no match for "
                    f"'{change['old_text'][:60]}...'"
                )
                continue

            page_num, block_indices, _ = match
            for bi in block_indices:
                matched.add((page_num, bi))

            blocks = [all_blocks[page_num][bi]["block"] for bi in block_indices]
            if page_num not in page_work:
                page_work[page_num] = []
            page_work[page_num].append(
                {
                    "blocks": blocks,
                    "old_section": change["old_text"],
                    "new_section": change["new_text"],
                }
            )
            logger.debug(
                f"Change {change['index']}: matched {len(block_indices)} "
                f"block(s) on page {page_num + 1}"
            )

        # Apply edits page by page
        for page_num, work_items in page_work.items():
            page = doc[page_num]
            self._apply_page_edits(page, work_items)

        result = doc.tobytes()
        doc.close()

        total = sum(len(w) for w in page_work.values())
        logger.info(
            f"PDF edits applied: {total} replacement(s) across "
            f"{len(page_work)} page(s)"
        )
        return result

    def _apply_page_edits(self, page, work_items: list[dict]) -> None:
        """Apply all edits on a single page, preserving original formatting."""
        render_jobs = []

        # Phase 1: Analyze each block's original lines and prepare edits
        for item in work_items:
            blocks = item["blocks"]
            old_section = item["old_section"]
            new_section = item["new_section"]

            # Extract the original lines with full formatting from the PDF
            original_lines = self._get_block_lines(blocks)
            if not original_lines:
                continue

            # Compute the union rect for redaction
            rects = [fitz.Rect(b["bbox"]) for b in blocks]
            union_rect = rects[0]
            for r in rects[1:]:
                union_rect |= r

            # Apply the user's edit as a diff to the original formatted text
            new_lines = self._apply_diff_to_lines(
                original_lines, old_section, new_section
            )

            # Add redaction
            annot = page.add_redact_annot(union_rect)
            annot.set_colors(fill=(1, 1, 1))

            render_jobs.append(
                {
                    "new_lines": new_lines,
                    "original_lines": original_lines,
                    "rect": union_rect,
                }
            )

        if not render_jobs:
            return

        # Phase 2: Apply all redactions at once
        page.apply_redactions(images=0, graphics=0)

        # Phase 3: Re-render each edit's lines at original positions
        for job in render_jobs:
            self._render_lines(page, job)

    def _get_block_lines(self, blocks: list[dict]) -> list[dict]:
        """
        Extract every line from the matched blocks with full formatting info.

        Returns list of dicts with: text, origin, font, size, flags, color
        """
        lines = []
        for block in blocks:
            for line in block.get("lines", []):
                # Combine all spans in the line to get full text
                full_text = ""
                first_span = None
                for span in line.get("spans", []):
                    full_text += span.get("text", "")
                    if first_span is None:
                        first_span = span

                if first_span is None:
                    continue

                origin = first_span.get("origin")
                if not origin:
                    bbox = first_span.get("bbox", [0, 0, 0, 0])
                    origin = (bbox[0], bbox[3])

                lines.append(
                    {
                        "text": full_text,
                        "origin": origin,
                        "font": first_span.get("font", "Courier"),
                        "size": first_span.get("size", 10.0),
                        "flags": first_span.get("flags", 0),
                        "color": first_span.get("color", 0),
                    }
                )
        return lines

    def _apply_diff_to_lines(
        self,
        original_lines: list[dict],
        old_section: str,
        new_section: str,
    ) -> list[str]:
        """
        Apply the user's section edit to the original formatted block text.

        Instead of replacing with raw edited text (which loses formatting),
        we find what actually changed and apply only that change to the
        original text, preserving indentation, padding, and line structure.
        """
        # Get the original block text preserving line structure
        orig_line_texts = [l["text"] for l in original_lines]

        # Join stripped lines to get the normalized block content
        orig_stripped = " ".join(t.strip() for t in orig_line_texts)
        orig_norm = re.sub(r"\s+", " ", orig_stripped).strip()

        # Normalize the section texts for comparison
        old_norm = re.sub(r"\s+", " ", old_section).strip()
        new_norm = re.sub(r"\s+", " ", new_section).strip()

        # Detect the type of edit by comparing old_section vs new_section
        # and apply it to the original formatted text

        # Determine line width and indentation from original
        line_width = max(len(t) for t in orig_line_texts) if orig_line_texts else 80
        first_line = orig_line_texts[0] if orig_line_texts else ""
        leading_spaces = len(first_line) - len(first_line.lstrip())

        # Case 1: Simple append (user added text at the end)
        if new_norm.startswith(old_norm) and len(new_norm) > len(old_norm):
            added_text = new_section[len(old_section):]
            # Append to the content of the last line
            last_line = orig_line_texts[-1]
            last_content = last_line.rstrip()
            new_last = last_content + added_text
            result = list(orig_line_texts[:-1])
            if len(new_last) <= line_width:
                result.append(new_last.ljust(line_width))
            else:
                # Need to wrap the overflow
                result.extend(
                    self._wrap_preserving_format(
                        new_last, line_width, leading_spaces
                    )
                )
            return result

        # Case 2: Simple prepend (user added text at the start)
        if new_norm.endswith(old_norm) and len(new_norm) > len(old_norm):
            added_text = new_section[: len(new_section) - len(old_section)]
            first_content = orig_line_texts[0].lstrip()
            new_first = " " * leading_spaces + added_text + first_content
            result = []
            if len(new_first) <= line_width:
                result.append(new_first.ljust(line_width))
            else:
                result.extend(
                    self._wrap_preserving_format(
                        new_first, line_width, leading_spaces
                    )
                )
            result.extend(orig_line_texts[1:])
            return result

        # Case 3: In-place replacement within the text
        # Find what changed using SequenceMatcher on the normalized text
        # and apply those changes to the original formatted text
        modified_content = self._apply_inline_diff(
            orig_norm, old_norm, new_norm
        )

        # Re-wrap the modified content into lines matching original format
        return self._wrap_preserving_format(
            " " * leading_spaces + modified_content,
            line_width,
            leading_spaces,
        )

    def _apply_inline_diff(
        self, orig_formatted: str, old_section: str, new_section: str
    ) -> str:
        """
        Apply the diff between old_section and new_section to the
        orig_formatted text, preserving characters that didn't change.
        """
        # Find the diff between old and new section text
        sm = SequenceMatcher(None, old_section.lower(), new_section.lower())
        opcodes = sm.get_opcodes()

        # Map the changes to the original formatted text
        # First find where old_section content appears in orig_formatted
        orig_lower = orig_formatted.lower()
        old_lower = old_section.lower()

        # Try to find the section text in the formatted text
        start = orig_lower.find(old_lower)
        if start == -1:
            # Fuzzy find: try to find a close match
            # Fall back to just using the new section text
            return new_section

        # Build result: prefix + modified section + suffix
        prefix = orig_formatted[:start]
        suffix = orig_formatted[start + len(old_section) :]
        original_match = orig_formatted[start : start + len(old_section)]

        # Apply the opcodes to the original matched text
        result = []
        for tag, i1, i2, j1, j2 in opcodes:
            if tag == "equal":
                # Use characters from the ORIGINAL (preserves case/formatting)
                result.append(original_match[i1:i2])
            elif tag == "replace":
                result.append(new_section[j1:j2])
            elif tag == "insert":
                result.append(new_section[j1:j2])
            # 'delete': skip those characters

        return prefix + "".join(result) + suffix

    def _wrap_preserving_format(
        self, text: str, line_width: int, indent: int
    ) -> list[str]:
        """Wrap text to line_width, preserving indentation and padding."""
        content = text.strip()
        avail = line_width - indent
        if avail <= 0:
            avail = line_width

        if len(content) <= avail:
            return [(" " * indent + content).ljust(line_width)]

        wrapped = textwrap.wrap(content, width=avail) or [content]
        result = []
        for i, line in enumerate(wrapped):
            padded = (" " * indent + line).ljust(line_width)
            result.append(padded)
        return result

    def _render_lines(self, page, job: dict) -> None:
        """Render the edited lines at the original positions."""
        new_lines = job["new_lines"]
        original_lines = job["original_lines"]

        if not original_lines:
            return

        # Get font info from first original line
        first = original_lines[0]
        fontname = self._map_font(first["font"], [first["flags"]])
        fontsize = first["size"]

        color_int = first["color"]
        r = ((color_int >> 16) & 0xFF) / 255
        g = ((color_int >> 8) & 0xFF) / 255
        b = (color_int & 0xFF) / 255
        color = (r, g, b)

        # Calculate line spacing from original
        if len(original_lines) >= 2:
            line_spacing = (
                original_lines[1]["origin"][1] - original_lines[0]["origin"][1]
            )
        else:
            line_spacing = fontsize

        # Render each new line at the corresponding position
        for i, line_text in enumerate(new_lines):
            if i < len(original_lines):
                origin = original_lines[i]["origin"]
            else:
                # Extra line: continue below the last original line
                last_y = original_lines[-1]["origin"][1]
                extra = i - len(original_lines) + 1
                origin = (
                    original_lines[0]["origin"][0],
                    last_y + line_spacing * extra,
                )

            page.insert_text(
                fitz.Point(origin[0], origin[1]),
                line_text,
                fontname=fontname,
                fontsize=fontsize,
                color=color,
            )

    # --- Matching logic ---

    def _find_changes(
        self, original: list[dict], edited: list[dict]
    ) -> list[dict]:
        changes = []
        for i, (orig, edit) in enumerate(zip(original, edited)):
            old = orig.get("text", "").strip()
            new = edit.get("text", "").strip()
            if old != new and old:
                changes.append(
                    {"index": i, "old_text": old, "new_text": new}
                )
        return changes

    def _index_blocks(self, doc) -> list[list[dict]]:
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
        old_norm = self._normalize(old_text)
        if not old_norm:
            return None

        # Strategy 1: Exact match
        for pn, blocks in enumerate(all_blocks):
            for bi, binfo in enumerate(blocks):
                if (pn, bi) in matched:
                    continue
                if old_norm == binfo["norm"]:
                    return (pn, [bi], fitz.Rect(binfo["block"]["bbox"]))

        # Strategy 2: Multi-block match
        for pn, blocks in enumerate(all_blocks):
            for bi, binfo in enumerate(blocks):
                if (pn, bi) in matched:
                    continue
                prefix_len = min(30, len(binfo["norm"]))
                if not old_norm.startswith(binfo["norm"][:prefix_len]):
                    continue
                combined = binfo["norm"]
                group = [bi]
                for bj in range(bi + 1, min(bi + 10, len(blocks))):
                    if (pn, bj) in matched:
                        break
                    combined += " " + blocks[bj]["norm"]
                    group.append(bj)
                    if SequenceMatcher(None, old_norm, combined).ratio() > 0.85:
                        rects = [
                            fitz.Rect(blocks[g]["block"]["bbox"]) for g in group
                        ]
                        union = rects[0]
                        for r in rects[1:]:
                            union |= r
                        return (pn, group, union)

        # Strategy 3: Fuzzy match
        best, best_ratio = None, 0.7
        for pn, blocks in enumerate(all_blocks):
            for bi, binfo in enumerate(blocks):
                if (pn, bi) in matched:
                    continue
                ratio = SequenceMatcher(None, old_norm, binfo["norm"]).ratio()
                if ratio > best_ratio:
                    best_ratio = ratio
                    best = (pn, bi)
        if best:
            pn, bi = best
            return (pn, [bi], fitz.Rect(all_blocks[pn][bi]["block"]["bbox"]))

        return None

    # --- Helpers ---

    def _extract_block_text(self, block: dict) -> str:
        parts = []
        for line in block.get("lines", []):
            line_text = ""
            for span in line.get("spans", []):
                line_text += span.get("text", "")
            parts.append(line_text)
        return " ".join(parts).strip()

    def _normalize(self, text: str) -> str:
        text = BULLET_PATTERN.sub("", text)
        text = NUMBERED_PATTERN.sub("", text)
        text = LETTER_PATTERN.sub("", text)
        return re.sub(r"\s+", " ", text).strip().lower()

    def _map_font(self, pdf_font_name: str, flags_list: list[int]) -> str:
        name = (
            pdf_font_name.lower()
            .replace("-", "")
            .replace(" ", "")
            .replace("_", "")
        )
        is_bold = any(k in name for k in ("bold", "black", "heavy"))
        is_italic = any(k in name for k in ("italic", "oblique"))
        if not is_bold and not is_italic and flags_list:
            f = max(set(flags_list), key=flags_list.count)
            is_bold = is_bold or bool(f & (1 << 4))
            is_italic = is_italic or bool(f & (1 << 1))

        if any(
            k in name
            for k in (
                "times", "tiro", "serif", "georgia", "garamond",
                "cambria", "palatino", "bookman",
            )
        ):
            fam = "ti"
        elif any(
            k in name
            for k in ("courier", "cour", "mono", "consolas", "menlo")
        ):
            fam = "co"
        elif "symbol" in name:
            return "symb"
        elif "zapf" in name or "dingbat" in name:
            return "zadb"
        else:
            fam = "he"

        v = {
            "he": {"base": "helv", "b": "hebo", "i": "heit", "bi": "hebi"},
            "ti": {"base": "tiro", "b": "tibo", "i": "tiit", "bi": "tibi"},
            "co": {"base": "cour", "b": "cobo", "i": "coit", "bi": "cobi"},
        }[fam]

        if is_bold and is_italic:
            return v["bi"]
        if is_bold:
            return v["b"]
        if is_italic:
            return v["i"]
        return v["base"]
