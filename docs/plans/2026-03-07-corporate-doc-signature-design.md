# Corporate Document Signature Placement

## Overview

Click-to-place signature, initials, and date stamps on corporate document PDFs. Users enter "signing mode" in the document editor, place items on a pdf.js-rendered canvas, then save once to create a new document version with all placements stamped onto the PDF.

## Placeable Items

- **Signature** — User's stored signature from settings (drawn = base64 PNG, typed = cursive text)
- **Initials** — Auto-derived from user's name (first + last initial, uppercase, cursive style)
- **Date** — Current date formatted as "Month Day, Year"

## Architecture

### Frontend (Alpine.js + pdf.js)

- Replace iframe PDF preview with pdf.js canvas renderer when in signing mode
- Toolbar: Signature | Initials | Date buttons — select one, click on canvas to place
- Each placement renders as a draggable overlay positioned absolutely over the canvas
- User can place multiple items across pages, remove any placement
- "Save Signed Document" sends all placements to server in one request

### Placement Data Structure

```json
[
  {"type": "signature", "page": 1, "x": 0.15, "y": 0.85, "width": 0.25},
  {"type": "initials", "page": 2, "x": 0.80, "y": 0.92, "width": 0.08},
  {"type": "date", "page": 1, "x": 0.55, "y": 0.87, "width": 0.15}
]
```

Coordinates as percentages (0-1) of page dimensions for resolution independence.

### Backend

- `CorporateDocumentService::applySignatures($document, $placements, $signatureData)` — orchestrates the flow
- `PythonBridgeService::applySignaturesToPdf($pdfPath, $placements, $signatureData)` — calls Python
- Python endpoint `POST /documents/apply-signatures` — receives PDF + placements + signature image, returns stamped PDF bytes
- Python uses reportlab + PyPDF2 (or pypdf) to overlay images/text at specified coordinates
- Creates new document version via existing versioning system

### Initials Derivation

From `Auth::user()->name`: first char of first word + first char of last word, uppercased. Rendered in cursive font matching typed signature style.

### Date Stamp

Current date as "March 7, 2026". Standard serif font, smaller size than signature.

## Files to Create/Modify

| File | Change |
|------|--------|
| `resources/views/components/corporate/⚡document-editor.blade.php` | Add sign mode, pdf.js canvas, placement toolbar, Alpine component |
| `app/Services/CorporateDocumentService.php` | Add `applySignatures()` method |
| `app/Services/PythonBridgeService.php` | Add `applySignaturesToPdf()` method |
| `python/api/routers/documents.py` | New `POST /documents/apply-signatures` endpoint |
| `python/api/services/documents.py` | New service for PDF signature overlay |

## User Flow

1. Open document editor, click "Sign Document"
2. pdf.js renders PDF pages as canvases
3. Select tool (Signature/Initials/Date), click on page to place
4. Overlay appears at click position, draggable to reposition
5. Place multiple items, remove unwanted ones
6. Click "Save Signed Document"
7. Server stamps placements onto PDF via Python service
8. New version created, editor refreshes to signed version

## Dependencies

- pdf.js (CDN or npm) for client-side PDF rendering
- reportlab + pypdf Python packages for server-side PDF manipulation
- Existing signature data on `internal_users` table
- Existing Python bridge service infrastructure
