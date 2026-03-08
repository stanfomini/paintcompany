"""Document processing endpoints."""

import json
import os
import tempfile
from typing import Optional

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from fastapi.responses import Response
from loguru import logger
from pydantic import BaseModel

from ..services.documents.pdf_editor import PdfEditorService
from ..services.documents.pdf_parser import PdfParserError, PdfParserService
from ..services.documents.signature_stamper import SignatureStamperService

router = APIRouter()


class ParseResponse(BaseModel):
    """Response model for PDF parsing."""

    success: bool
    sections: Optional[list[dict]] = None
    page_count: Optional[int] = None
    error: Optional[str] = None


@router.post("/parse", response_model=ParseResponse)
async def parse_document(file: UploadFile = File(...)):
    """
    Parse a PDF document into structured sections.

    Accepts a PDF file upload and returns structured sections
    (headings, paragraphs, list items) extracted from the document.
    """
    # Validate content type
    allowed_types = [
        "application/pdf",
        "application/x-pdf",
        # Some clients send octet-stream for PDFs
        "application/octet-stream",
    ]

    if file.content_type and file.content_type not in allowed_types:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported file type: {file.content_type}. Only PDF files are accepted.",
        )

    # Validate filename extension
    filename = file.filename or "document.pdf"
    if not filename.lower().endswith(".pdf"):
        raise HTTPException(
            status_code=400,
            detail="Only PDF files are accepted.",
        )

    temp_file_path = None

    try:
        # Read the uploaded file
        pdf_bytes = await file.read()

        if len(pdf_bytes) == 0:
            raise HTTPException(
                status_code=400,
                detail="Uploaded file is empty.",
            )

        logger.info(
            f"Received PDF for parsing: {filename}, size: {len(pdf_bytes)} bytes"
        )

        # Write to temporary file (pdfminer requires a file path)
        with tempfile.NamedTemporaryFile(
            suffix=".pdf", delete=False, prefix="pdf_parse_"
        ) as tmp_file:
            tmp_file.write(pdf_bytes)
            temp_file_path = tmp_file.name

        # Parse the PDF
        parser = PdfParserService()
        sections = parser.parse(temp_file_path)

        # Count pages by re-extracting (lightweight since already parsed)
        from pdfminer.high_level import extract_pages as count_pages

        page_count = sum(1 for _ in count_pages(temp_file_path))

        logger.info(
            f"Parsed {filename}: {len(sections)} sections, {page_count} pages"
        )

        return ParseResponse(
            success=True,
            sections=sections,
            page_count=page_count,
        )

    except HTTPException:
        raise
    except PdfParserError as e:
        logger.error(f"PDF parsing error: {e}")
        return ParseResponse(
            success=False,
            error=str(e),
        )
    except Exception as e:
        logger.error(f"Unexpected PDF parsing error: {e}")
        return ParseResponse(
            success=False,
            error=f"Failed to parse PDF: {str(e)}",
        )
    finally:
        # Clean up temporary file
        if temp_file_path and os.path.exists(temp_file_path):
            try:
                os.unlink(temp_file_path)
                logger.debug(f"Cleaned up temporary file: {temp_file_path}")
            except Exception as e:
                logger.warning(f"Failed to clean up temporary file: {e}")


@router.post("/apply-edits")
async def apply_edits(
    file: UploadFile = File(...),
    original_sections: str = Form(...),
    edited_sections: str = Form(...),
):
    """
    Apply text edits to a PDF document while preserving original formatting.

    Accepts the original PDF file, the originally parsed sections, and the
    edited sections. Returns a modified PDF with text replacements applied.
    """
    try:
        pdf_bytes = await file.read()

        if len(pdf_bytes) == 0:
            raise HTTPException(status_code=400, detail="Uploaded file is empty.")

        orig = json.loads(original_sections)
        edited = json.loads(edited_sections)

        logger.info(
            f"Applying edits to PDF: {len(pdf_bytes)} bytes, "
            f"{len(orig)} original sections, {len(edited)} edited sections"
        )

        editor = PdfEditorService()
        modified_bytes = editor.apply_edits(pdf_bytes, orig, edited)

        return Response(
            content=modified_bytes,
            media_type="application/pdf",
            headers={"Content-Disposition": "inline"},
        )

    except json.JSONDecodeError as e:
        raise HTTPException(
            status_code=400,
            detail=f"Invalid JSON in sections: {str(e)}",
        )
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"PDF edit failed: {e}")
        raise HTTPException(
            status_code=500,
            detail=f"Failed to apply edits: {str(e)}",
        )


@router.post("/apply-signatures")
async def apply_signatures(
    file: UploadFile = File(...),
    placements: str = Form(...),
    signature_data: Optional[str] = Form(None),
    signature_type: Optional[str] = Form(None),
    user_name: Optional[str] = Form(None),
):
    """
    Stamp signature, initials, and date placements onto a PDF.

    Accepts the PDF file, a JSON array of placements, and optional
    signature data (base64 PNG or typed text).
    """
    try:
        pdf_bytes = await file.read()

        if len(pdf_bytes) == 0:
            raise HTTPException(status_code=400, detail="Uploaded file is empty.")

        parsed_placements = json.loads(placements)

        logger.info(
            f"Applying {len(parsed_placements)} signature placement(s) to PDF: "
            f"{len(pdf_bytes)} bytes"
        )

        stamper = SignatureStamperService()
        modified_bytes = stamper.apply_signatures(
            pdf_bytes,
            parsed_placements,
            signature_data=signature_data,
            signature_type=signature_type,
            user_name=user_name,
        )

        return Response(
            content=modified_bytes,
            media_type="application/pdf",
            headers={"Content-Disposition": "inline"},
        )

    except json.JSONDecodeError as e:
        raise HTTPException(
            status_code=400,
            detail=f"Invalid JSON in placements: {str(e)}",
        )
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Signature stamping failed: {e}")
        raise HTTPException(
            status_code=500,
            detail=f"Failed to apply signatures: {str(e)}",
        )
