"""PDF parsing service using pdfminer.six."""

import re
import statistics
from typing import Optional

from loguru import logger

from pdfminer.high_level import extract_pages
from pdfminer.layout import (
    LAParams,
    LTAnno,
    LTChar,
    LTFigure,
    LTPage,
    LTTextBox,
    LTTextLine,
)


class PdfParserError(Exception):
    """Raised when PDF parsing fails."""

    pass


class PdfParserService:
    """Parse PDF documents into structured sections."""

    # Patterns for detecting list items
    BULLET_PATTERN = re.compile(r"^\s*[\u2022\-\*\u25CF\u25CB\u25AA\u25AB\u2023\u27A4]\s+")
    NUMBERED_PATTERN = re.compile(r"^\s*\d+[\.\)]\s+")
    LETTER_PATTERN = re.compile(r"^\s*[a-zA-Z][\.\)]\s+")

    def parse(self, file_path: str) -> list[dict]:
        """
        Parse a PDF file into structured sections.

        Args:
            file_path: Absolute path to the PDF file.

        Returns:
            List of ParsedSection dicts with keys: type, text, level (for headings).
        """
        try:
            sections: list[dict] = []
            font_sizes: list[float] = []

            # First pass: collect all font sizes to determine heading thresholds
            laparams = LAParams(
                line_margin=0.5,
                word_margin=0.1,
                char_margin=2.0,
                boxes_flow=0.5,
            )

            pages = list(extract_pages(file_path, laparams=laparams))

            if not pages:
                return []

            # Collect font sizes from all text elements
            for page in pages:
                self._collect_font_sizes(page, font_sizes)

            if not font_sizes:
                # No text found (possibly scanned PDF)
                return []

            avg_font_size = statistics.mean(font_sizes)
            # Use median for more robust threshold against outliers
            median_font_size = statistics.median(font_sizes)

            # Heading thresholds relative to median
            h1_threshold = median_font_size * 1.4
            h2_threshold = median_font_size * 1.15

            # Second pass: extract structured content
            for page in pages:
                page_sections = self._extract_page_sections(
                    page, avg_font_size, h1_threshold, h2_threshold
                )
                sections.extend(page_sections)

            # Merge consecutive paragraphs that were split across text boxes
            sections = self._merge_consecutive_paragraphs(sections)

            return sections

        except Exception as e:
            logger.error(f"PDF parsing failed for {file_path}: {e}")
            raise PdfParserError(f"Failed to parse PDF: {e}") from e

    def _collect_font_sizes(self, element, font_sizes: list[float]) -> None:
        """Recursively collect font sizes from layout elements."""
        if isinstance(element, LTChar):
            if element.size > 0:
                font_sizes.append(element.size)
        elif isinstance(element, (LTTextBox, LTTextLine, LTFigure, LTPage)):
            for child in element:
                self._collect_font_sizes(child, font_sizes)

    def _get_dominant_font_size(self, element) -> float:
        """Get the most common font size in a text element."""
        sizes: list[float] = []
        if isinstance(element, (LTTextBox, LTTextLine)):
            for child in element:
                if isinstance(child, LTTextLine):
                    for char in child:
                        if isinstance(char, LTChar) and char.size > 0:
                            sizes.append(char.size)
                elif isinstance(child, LTChar) and child.size > 0:
                    sizes.append(child.size)

        if not sizes:
            return 0.0

        # Return the most common font size
        return statistics.mode(sizes) if len(sizes) > 1 else sizes[0]

    def _is_bold(self, element) -> bool:
        """Check if text element uses a bold font."""
        for child in element:
            if isinstance(child, LTTextLine):
                for char in child:
                    if isinstance(char, LTChar):
                        fontname = char.fontname.lower() if char.fontname else ""
                        if "bold" in fontname or "black" in fontname:
                            return True
            elif isinstance(child, LTChar):
                fontname = child.fontname.lower() if child.fontname else ""
                if "bold" in fontname or "black" in fontname:
                    return True
        return False

    def _extract_page_sections(
        self,
        page: LTPage,
        avg_font_size: float,
        h1_threshold: float,
        h2_threshold: float,
    ) -> list[dict]:
        """Extract sections from a single page."""
        sections: list[dict] = []

        for element in page:
            if isinstance(element, LTTextBox):
                text = element.get_text().strip()

                if not text:
                    continue

                # Strip excessive whitespace within text
                text = re.sub(r"\s+", " ", text).strip()

                font_size = self._get_dominant_font_size(element)
                is_bold = self._is_bold(element)

                # Determine section type
                section = self._classify_text(
                    text, font_size, is_bold,
                    h1_threshold, h2_threshold, avg_font_size,
                )
                sections.append(section)

            elif isinstance(element, LTFigure):
                # Recurse into figures (some PDFs nest text in figures)
                for child in element:
                    if isinstance(child, LTTextBox):
                        text = child.get_text().strip()
                        if text:
                            text = re.sub(r"\s+", " ", text).strip()
                            font_size = self._get_dominant_font_size(child)
                            is_bold = self._is_bold(child)
                            section = self._classify_text(
                                text, font_size, is_bold,
                                h1_threshold, h2_threshold, avg_font_size,
                            )
                            sections.append(section)

        return sections

    def _classify_text(
        self,
        text: str,
        font_size: float,
        is_bold: bool,
        h1_threshold: float,
        h2_threshold: float,
        avg_font_size: float,
    ) -> dict:
        """Classify a text block as heading, list_item, or paragraph."""
        # Check for list items first
        if self.BULLET_PATTERN.match(text):
            cleaned = self.BULLET_PATTERN.sub("", text).strip()
            return {"type": "list_item", "text": cleaned}

        if self.NUMBERED_PATTERN.match(text):
            cleaned = self.NUMBERED_PATTERN.sub("", text).strip()
            return {"type": "list_item", "text": cleaned}

        if self.LETTER_PATTERN.match(text):
            cleaned = self.LETTER_PATTERN.sub("", text).strip()
            return {"type": "list_item", "text": cleaned}

        # Check for headings by font size
        if font_size >= h1_threshold:
            return {"type": "heading", "level": 1, "text": text}

        if font_size >= h2_threshold or (is_bold and font_size >= avg_font_size):
            return {"type": "heading", "level": 2, "text": text}

        # Check for short bold text (likely a heading)
        if is_bold and len(text) < 100 and "\n" not in text:
            return {"type": "heading", "level": 2, "text": text}

        # Default to paragraph
        return {"type": "paragraph", "text": text}

    def _merge_consecutive_paragraphs(self, sections: list[dict]) -> list[dict]:
        """Merge consecutive paragraphs that were likely split across text boxes."""
        if not sections:
            return sections

        merged: list[dict] = [sections[0]]

        for section in sections[1:]:
            prev = merged[-1]

            # Merge consecutive paragraphs if the previous one doesn't end with
            # sentence-ending punctuation
            if (
                prev["type"] == "paragraph"
                and section["type"] == "paragraph"
                and prev["text"]
                and not prev["text"][-1] in ".!?:;"
            ):
                prev["text"] = prev["text"] + " " + section["text"]
            else:
                merged.append(section)

        return merged
