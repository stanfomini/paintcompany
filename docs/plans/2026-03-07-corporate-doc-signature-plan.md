# Corporate Document Signature Placement - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Click-to-place signature, initials, and date stamps on corporate document PDFs via pdf.js canvas with server-side stamping.

**Architecture:** Frontend renders PDF pages via pdf.js in a signing mode on the document editor. User selects a tool (Signature/Initials/Date), clicks on a page to place an overlay, repositions via drag. On save, all placements (type, page, x%, y%, width%) are sent to the server. Laravel calls Python, which stamps images/text onto the PDF using PyMuPDF (fitz). A new document version is created.

**Tech Stack:** Alpine.js + pdf.js (frontend), Livewire (signing actions), PyMuPDF/fitz (Python PDF stamping), existing PythonBridgeService + CorporateDocumentService

---

## Task 1: Python Signature Overlay Service

**Files:**
- Create: `python/api/services/documents/signature_stamper.py`

**Step 1: Create the signature stamper service**

```python
"""Stamp signatures, initials, and date text onto a PDF using PyMuPDF."""

import base64
import io
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
            ptype = placement.get("type")
            page_num = int(placement.get("page", 1)) - 1  # Convert to 0-based
            x_pct = float(placement.get("x", 0))
            y_pct = float(placement.get("y", 0))
            w_pct = float(placement.get("width", 0.2))

            if page_num < 0 or page_num >= len(doc):
                logger.warning(f"Skipping placement on invalid page {page_num + 1}")
                continue

            page = doc[page_num]
            page_rect = page.rect
            pw = page_rect.width
            ph = page_rect.height

            x = x_pct * pw
            y = y_pct * ph
            w = w_pct * pw

            if ptype == "signature":
                self._stamp_signature(page, x, y, w, signature_data, signature_type)
            elif ptype == "initials":
                self._stamp_initials(page, x, y, w, user_name)
            elif ptype == "date":
                self._stamp_date(page, x, y, w)
            else:
                logger.warning(f"Unknown placement type: {ptype}")

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
            # Extract base64 PNG from data URL
            header, b64 = signature_data.split(",", 1)
            img_bytes = base64.b64decode(b64)
            self._place_image(page, x, y, w, img_bytes)
        else:
            # Typed signature — render as text
            h = w * 0.4  # Aspect ratio for text
            fontsize = min(h * 0.7, 24)
            page.insert_text(
                fitz.Point(x, y + fontsize),
                signature_data,
                fontname="tiit",  # Times Italic for cursive feel
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
        fontsize = min(w * 0.6, 20)
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
        fontsize = min(w * 0.35, 12)
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
        # Determine image aspect ratio
        img_doc = fitz.open(stream=img_bytes, filetype="png")
        img_page = img_doc[0]
        img_rect = img_page.rect
        aspect = img_rect.height / img_rect.width if img_rect.width else 0.4
        img_doc.close()

        h = w * aspect
        rect = fitz.Rect(x, y, x + w, y + h)
        page.insert_image(rect, stream=img_bytes)

    @staticmethod
    def _derive_initials(name: str | None) -> str:
        """Derive initials from a full name: first char of first + last word."""
        if not name:
            return "XX"
        parts = name.strip().split()
        if len(parts) == 1:
            return parts[0][0].upper()
        return (parts[0][0] + parts[-1][0]).upper()
```

**Step 2: Verify file created and syntax is valid**

Run: `cd python && python -c "from api.services.documents.signature_stamper import SignatureStamperService; print('OK')"`
Expected: `OK`

---

## Task 2: Python API Endpoint

**Files:**
- Modify: `python/api/routers/documents.py`

**Step 1: Add the `/apply-signatures` endpoint**

Add at the bottom of `documents.py`:

```python
from ..services.documents.signature_stamper import SignatureStamperService

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
```

**Step 2: Verify endpoint loads**

Run: `cd python && python -c "from api.routers.documents import router; print('Routes:', [r.path for r in router.routes])"`
Expected: output includes `/apply-signatures`

---

## Task 3: Laravel PythonBridgeService Method

**Files:**
- Modify: `app/Services/PythonBridgeService.php` (add method after `applyDocumentEdits`)

**Step 1: Add `applySignaturesToPdf()` method**

Add after the `applyDocumentEdits` method (around line 507):

```php
/**
 * Apply signature/initials/date placements to a PDF via Python service.
 *
 * @param  array<int, array{type: string, page: int, x: float, y: float, width: float}>  $placements
 * @return string|null Modified PDF bytes, or null on failure
 */
public function applySignaturesToPdf(
    string $filePath,
    array $placements,
    ?string $signatureData = null,
    ?string $signatureType = null,
    ?string $userName = null,
): ?string {
    $this->ensureEnabled();

    if (! file_exists($filePath)) {
        Log::error('PDF file not found for signature stamping', ['file' => $filePath]);

        return null;
    }

    try {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout(60)
            ->retry(2, 1000);

        $apiKey = config('python.api_key');
        if ($apiKey) {
            $request->withHeaders(['X-API-Key' => $apiKey]);
        }

        $formData = [
            'placements' => json_encode($placements),
        ];

        if ($signatureData !== null) {
            $formData['signature_data'] = $signatureData;
        }
        if ($signatureType !== null) {
            $formData['signature_type'] = $signatureType;
        }
        if ($userName !== null) {
            $formData['user_name'] = $userName;
        }

        $response = $request
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post('/documents/apply-signatures', $formData);

        if ($response->successful()) {
            return $response->body();
        }

        Log::warning('Python signature stamping returned non-success', [
            'status' => $response->status(),
        ]);

        return null;
    } catch (\Exception $e) {
        Log::warning('Signature stamping via Python service failed', [
            'error' => $e->getMessage(),
        ]);

        return null;
    }
}
```

---

## Task 4: Laravel CorporateDocumentService Method

**Files:**
- Modify: `app/Services/CorporateDocumentService.php`

**Step 1: Add `applySignatures()` method**

Add after `saveEdits()` (around line 92):

```php
/**
 * Apply signature/initials/date placements to a document's PDF.
 * Creates a new version with the stamped PDF.
 *
 * @param  array<int, array{type: string, page: int, x: float, y: float, width: float}>  $placements
 */
public function applySignatures(
    CorporateDocument $document,
    array $placements,
    ?string $signatureData = null,
    ?string $signatureType = null,
    ?string $userName = null,
): CorporateDocument {
    // Get the PDF to stamp onto
    $pdfPath = $document->original_file_path;

    if (! $pdfPath || ! Storage::exists($pdfPath)) {
        throw new \RuntimeException('Document has no PDF file to stamp.');
    }

    // Download to temp file
    $tempFile = tempnam(sys_get_temp_dir(), 'corp_sign_').'.pdf';
    file_put_contents($tempFile, Storage::get($pdfPath));

    try {
        $python = app(PythonBridgeService::class);

        if (! $python->isEnabled()) {
            throw new \RuntimeException('Python service is not enabled.');
        }

        $modifiedPdfBytes = $python->applySignaturesToPdf(
            $tempFile,
            $placements,
            $signatureData,
            $signatureType,
            $userName,
        );

        if (! $modifiedPdfBytes) {
            throw new \RuntimeException('Python service failed to stamp signatures.');
        }

        // Find the root parent
        $parentId = $document->parent_document_id ?? $document->id;
        $parent = $document->parent_document_id
            ? CorporateDocument::find($parentId)
            : $document;

        $latestVersion = CorporateDocument::where('parent_document_id', $parentId)
            ->max('version') ?? $parent->version;

        // Store the signed PDF
        $signedPath = "corporate-documents/{$document->corporation_id}/signed-v"
            .($latestVersion + 1).'-'.Str::uuid().'.pdf';
        Storage::put($signedPath, $modifiedPdfBytes);

        // Create new version
        return CorporateDocument::create([
            'corporation_id' => $document->corporation_id,
            'parent_document_id' => $parentId,
            'uploaded_by' => $document->uploaded_by,
            'title' => $document->title,
            'type' => $document->type,
            'original_file_path' => $signedPath,
            'original_filename' => $document->original_filename,
            'version' => $latestVersion + 1,
            'status' => 'ready',
            'parsed_content' => $parent->parsed_content ?? $document->parsed_content,
            'edited_content' => $document->edited_content,
            'file_size' => strlen($modifiedPdfBytes),
            'page_count' => $document->page_count,
        ]);
    } finally {
        @unlink($tempFile);
    }
}
```

---

## Task 5: Tests for Backend (Python + Laravel)

**Files:**
- Create: `tests/Feature/Corporate/DocumentSignatureTest.php`

**Step 1: Write tests**

```php
<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use App\Services\CorporateDocumentService;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();

    // Create a document with a fake PDF
    $pdfPath = "corporate-documents/{$this->corporation->id}/test.pdf";
    Storage::put($pdfPath, '%PDF-1.4 fake');

    $this->document = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 1,
        'original_file_path' => $pdfPath,
        'parsed_content' => [['type' => 'paragraph', 'text' => 'Test content']],
    ]);
});

it('creates a new version when applying signatures', function () {
    $modifiedPdf = '%PDF-1.4 signed content';

    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')
        ->once()
        ->andReturn($modifiedPdf);
    app()->instance(PythonBridgeService::class, $mock);

    $placements = [
        ['type' => 'signature', 'page' => 1, 'x' => 0.15, 'y' => 0.85, 'width' => 0.25],
    ];

    $service = app(CorporateDocumentService::class);
    $newVersion = $service->applySignatures(
        $this->document,
        $placements,
        'data:image/png;base64,abc123',
        'drawn',
        'Matt Stanford',
    );

    expect($newVersion->version)->toBe(2);
    expect($newVersion->parent_document_id)->toBe($this->document->id);
    expect($newVersion->corporation_id)->toBe($this->document->corporation_id);
    Storage::assertExists($newVersion->original_file_path);
});

it('passes correct arguments to python bridge', function () {
    $placements = [
        ['type' => 'signature', 'page' => 1, 'x' => 0.15, 'y' => 0.85, 'width' => 0.25],
        ['type' => 'initials', 'page' => 2, 'x' => 0.80, 'y' => 0.92, 'width' => 0.08],
        ['type' => 'date', 'page' => 1, 'x' => 0.55, 'y' => 0.87, 'width' => 0.15],
    ];

    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')
        ->once()
        ->withArgs(function ($filePath, $p, $sigData, $sigType, $name) use ($placements) {
            return $p === $placements
                && $sigData === 'typed-sig'
                && $sigType === 'typed'
                && $name === 'Matt Stanford';
        })
        ->andReturn('%PDF-1.4 signed');
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures(
        $this->document,
        $placements,
        'typed-sig',
        'typed',
        'Matt Stanford',
    );
});

it('throws when python service is disabled', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures($this->document, [['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2]]);
})->throws(\RuntimeException::class, 'Python service is not enabled');

it('throws when python service fails', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')->andReturn(null);
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures($this->document, [['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2]]);
})->throws(\RuntimeException::class, 'Python service failed');

it('throws when document has no PDF', function () {
    $docNoPdf = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'original_file_path' => null,
    ]);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures($docNoPdf, [['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2]]);
})->throws(\RuntimeException::class, 'no PDF file');

it('preserves edited content in signed version', function () {
    $editedDoc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 2,
        'parent_document_id' => $this->document->id,
        'original_file_path' => $this->document->original_file_path,
        'edited_content' => [['type' => 'paragraph', 'text' => 'Edited content']],
    ]);

    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')->andReturn('%PDF-1.4 signed');
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $newVersion = $service->applySignatures($editedDoc, [
        ['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2],
    ]);

    expect($newVersion->version)->toBe(3);
    expect($newVersion->parent_document_id)->toBe($this->document->id);
    expect($newVersion->edited_content)->toBe([['type' => 'paragraph', 'text' => 'Edited content']]);
});
```

**Step 2: Run tests**

Run: `php artisan test --compact tests/Feature/Corporate/DocumentSignatureTest.php`
Expected: All 6 tests pass

---

## Task 6: Document Editor Signing Mode (Livewire + Alpine + pdf.js)

**Files:**
- Modify: `resources/views/components/corporate/⚡document-editor.blade.php`

This is the largest task. The document editor needs:

1. A "Sign Document" button that enters signing mode
2. pdf.js rendering of PDF pages (replaces iframe)
3. A toolbar with Signature / Initials / Date tools
4. Click-to-place overlays on the canvas
5. Draggable repositioning
6. A "Save Signed Document" action that sends placements to server

**Step 1: Add Livewire signing properties and action**

Add to the PHP class:

```php
public bool $signingMode = false;
public array $signaturePlacements = [];

public function enterSigningMode(): void
{
    if (! $this->canManage) {
        abort(403);
    }

    // Check user has a signature
    $internalUser = Auth::user()->internalUser;
    if (! $internalUser || ! $internalUser->hasSignature()) {
        $this->dispatch('show-notification', type: 'error', message: 'Please set up your signature in Settings first.');
        return;
    }

    $this->signingMode = true;
    $this->activeTab = 'signing';
}

public function exitSigningMode(): void
{
    $this->signingMode = false;
    $this->signaturePlacements = [];
    $this->activeTab = 'original';
}

public function saveSignatures(array $placements): void
{
    if (! $this->canManage) {
        abort(403);
    }

    if (empty($placements)) {
        return;
    }

    $user = Auth::user();
    $internalUser = $user->internalUser;

    $doc = CorporateDocument::findOrFail($this->documentId);
    $service = app(CorporateDocumentService::class);

    $newVersion = $service->applySignatures(
        $doc,
        $placements,
        $internalUser->signature_data,
        $internalUser->signature_type,
        $user->name,
    );

    $this->documentId = $newVersion->id;
    $this->signingMode = false;
    $this->signaturePlacements = [];
    $this->activeTab = 'original';
    unset($this->document, $this->allVersions, $this->hasPdfToView);
}

#[Computed]
public function hasSignature(): bool
{
    $internalUser = Auth::user()?->internalUser;
    return $internalUser && $internalUser->hasSignature();
}

#[Computed]
public function signaturePreview(): array
{
    $internalUser = Auth::user()?->internalUser;
    if (! $internalUser || ! $internalUser->hasSignature()) {
        return [];
    }
    return [
        'type' => $internalUser->signature_type,
        'data' => $internalUser->signature_data,
        'initials' => $this->deriveInitials(Auth::user()->name),
    ];
}

private function deriveInitials(string $name): string
{
    $parts = explode(' ', trim($name));
    if (count($parts) === 1) {
        return strtoupper(mb_substr($parts[0], 0, 1));
    }
    return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
}
```

**Step 2: Add the signing tab button and toolbar to the template**

Add a "Sign Document" button in the header area (after Download PDF button):

```blade
@if ($this->canManage && $this->hasPdfToView && $this->hasSignature && !$signingMode)
    <flux:button size="sm" variant="primary" icon="pencil-square" wire:click="enterSigningMode">
        {{ __('Sign Document') }}
    </flux:button>
@endif
```

Add `signing` to the tab navigation:

```blade
@if ($signingMode)
    <button
        wire:click="$set('activeTab', 'signing')"
        class="rounded-md px-4 py-2 text-sm font-medium transition {{ $activeTab === 'signing' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
    >
        {{ __('Sign Document') }}
    </button>
@endif
```

**Step 3: Add the signing mode UI (pdf.js canvas + Alpine component)**

Add this block after the Section Editor `@endif`:

```blade
{{-- Signing Mode --}}
@if ($activeTab === 'signing' && $signingMode)
    @assets
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs" type="module"></script>
    @endassets

    <div
        x-data="documentSigner({
            pdfUrl: '{{ route('corporate.document.view', $this->document) }}',
            signaturePreview: @js($this->signaturePreview),
        })"
        x-init="init()"
        class="space-y-4"
    >
        {{-- Signing Toolbar --}}
        <div class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-950">
            <span class="mr-2 text-sm font-medium text-green-800 dark:text-green-200">Place:</span>

            <button
                type="button"
                @click="selectTool('signature')"
                :class="activeTool === 'signature' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
            >
                Signature
            </button>
            <button
                type="button"
                @click="selectTool('initials')"
                :class="activeTool === 'initials' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
            >
                Initials
            </button>
            <button
                type="button"
                @click="selectTool('date')"
                :class="activeTool === 'date' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
            >
                Date
            </button>

            <div class="ml-auto flex items-center gap-2">
                <span class="text-xs text-green-600 dark:text-green-400" x-text="placements.length + ' item(s) placed'"></span>
                <button
                    type="button"
                    @click="cancelSigning()"
                    class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="saveSigning()"
                    :disabled="placements.length === 0"
                    class="rounded-md bg-green-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                >
                    Save Signed Document
                </button>
            </div>
        </div>

        {{-- PDF Pages with Overlays --}}
        <div x-ref="pdfContainer" class="space-y-4">
            <template x-for="(pageData, pageIndex) in pages" :key="pageIndex">
                <div
                    class="relative mx-auto overflow-hidden rounded-lg border border-zinc-200 bg-white shadow dark:border-zinc-700"
                    :style="'width:' + pageData.width + 'px'"
                >
                    {{-- Page number badge --}}
                    <div class="absolute left-2 top-2 z-10 rounded bg-black/50 px-2 py-0.5 text-xs text-white" x-text="'Page ' + (pageIndex + 1)"></div>

                    {{-- Canvas --}}
                    <canvas
                        :id="'pdf-page-' + pageIndex"
                        @click="handleCanvasClick($event, pageIndex)"
                        :class="activeTool ? 'cursor-crosshair' : 'cursor-default'"
                    ></canvas>

                    {{-- Placement overlays --}}
                    <template x-for="(p, pi) in getPagePlacements(pageIndex)" :key="p.id">
                        <div
                            class="group absolute cursor-move select-none"
                            :style="getPlacementStyle(p, pageData)"
                            @mousedown="startDrag($event, p)"
                            @touchstart.prevent="startDrag($event, p)"
                        >
                            {{-- Remove button --}}
                            <button
                                type="button"
                                @click.stop="removePlacement(p.id)"
                                class="absolute -right-2 -top-2 z-20 hidden size-5 items-center justify-center rounded-full bg-red-500 text-white shadow group-hover:flex"
                            >
                                &times;
                            </button>

                            {{-- Signature preview --}}
                            <template x-if="p.type === 'signature'">
                                <div class="rounded border border-green-400/50 bg-green-50/80 px-2 py-1">
                                    <template x-if="signaturePreview.type === 'drawn'">
                                        <img :src="signaturePreview.data" class="h-10 max-w-full" />
                                    </template>
                                    <template x-if="signaturePreview.type !== 'drawn'">
                                        <span class="text-xl" style="font-family: 'Brush Script MT', cursive;" x-text="signaturePreview.data"></span>
                                    </template>
                                </div>
                            </template>

                            {{-- Initials preview --}}
                            <template x-if="p.type === 'initials'">
                                <div class="rounded border border-blue-400/50 bg-blue-50/80 px-2 py-1">
                                    <span class="text-lg font-semibold" style="font-family: 'Brush Script MT', cursive;" x-text="signaturePreview.initials"></span>
                                </div>
                            </template>

                            {{-- Date preview --}}
                            <template x-if="p.type === 'date'">
                                <div class="rounded border border-amber-400/50 bg-amber-50/80 px-2 py-1">
                                    <span class="text-sm" x-text="currentDate"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Loading state --}}
            <div x-show="loading" class="flex items-center justify-center py-12">
                <div class="flex items-center gap-3 text-zinc-500">
                    <svg class="size-5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span>Loading PDF pages...</span>
                </div>
            </div>
        </div>
    </div>
@endif
```

**Step 4: Add the Alpine.js `documentSigner` component**

Add at the bottom of the file, inside `@script`:

```blade
@script
<script>
    Alpine.data('documentSigner', (config) => ({
        pdfUrl: config.pdfUrl,
        signaturePreview: config.signaturePreview,
        activeTool: null,
        placements: [],
        pages: [],
        loading: true,
        dragState: null,
        nextId: 1,

        currentDate: new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),

        async init() {
            await this.loadPdf();
        },

        async loadPdf() {
            this.loading = true;

            // Dynamic import for pdf.js ESM
            const pdfjsLib = await import('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs');
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.worker.min.mjs';

            try {
                const pdf = await pdfjsLib.getDocument(this.pdfUrl).promise;
                const scale = 1.5;

                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const viewport = page.getViewport({ scale });

                    this.pages.push({
                        width: Math.round(viewport.width),
                        height: Math.round(viewport.height),
                        origWidth: viewport.width / scale,
                        origHeight: viewport.height / scale,
                    });

                    await this.$nextTick();

                    const canvas = document.getElementById('pdf-page-' + (i - 1));
                    if (canvas) {
                        canvas.width = viewport.width;
                        canvas.height = viewport.height;
                        canvas.style.width = viewport.width + 'px';
                        canvas.style.height = viewport.height + 'px';

                        const ctx = canvas.getContext('2d');
                        await page.render({ canvasContext: ctx, viewport }).promise;
                    }
                }
            } catch (e) {
                console.error('Failed to load PDF:', e);
            }

            this.loading = false;
        },

        selectTool(tool) {
            this.activeTool = this.activeTool === tool ? null : tool;
        },

        handleCanvasClick(event, pageIndex) {
            if (!this.activeTool) return;

            const rect = event.target.getBoundingClientRect();
            const pageData = this.pages[pageIndex];

            const x = (event.clientX - rect.left) / pageData.width;
            const y = (event.clientY - rect.top) / pageData.height;

            const widths = { signature: 0.25, initials: 0.08, date: 0.15 };

            this.placements.push({
                id: this.nextId++,
                type: this.activeTool,
                page: pageIndex + 1,
                x: Math.max(0, Math.min(x, 1)),
                y: Math.max(0, Math.min(y, 1)),
                width: widths[this.activeTool] || 0.2,
            });
        },

        getPagePlacements(pageIndex) {
            return this.placements.filter(p => p.page === pageIndex + 1);
        },

        getPlacementStyle(p, pageData) {
            const left = p.x * pageData.width;
            const top = p.y * pageData.height;
            return `left:${left}px;top:${top}px;`;
        },

        removePlacement(id) {
            this.placements = this.placements.filter(p => p.id !== id);
        },

        startDrag(event, placement) {
            const isTouch = event.type === 'touchstart';
            const startX = isTouch ? event.touches[0].clientX : event.clientX;
            const startY = isTouch ? event.touches[0].clientY : event.clientY;

            const pageData = this.pages[placement.page - 1];
            const origX = placement.x;
            const origY = placement.y;

            const onMove = (e) => {
                const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
                const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;

                const dx = (clientX - startX) / pageData.width;
                const dy = (clientY - startY) / pageData.height;

                placement.x = Math.max(0, Math.min(origX + dx, 1));
                placement.y = Math.max(0, Math.min(origY + dy, 1));
            };

            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.removeEventListener('touchmove', onMove);
                document.removeEventListener('touchend', onUp);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove);
            document.addEventListener('touchend', onUp);
        },

        cancelSigning() {
            this.$wire.exitSigningMode();
        },

        saveSigning() {
            if (this.placements.length === 0) return;

            // Send only the placement data (no id needed server-side)
            const data = this.placements.map(p => ({
                type: p.type,
                page: p.page,
                x: p.x,
                y: p.y,
                width: p.width,
            }));

            this.$wire.saveSignatures(data);
        },
    }));
</script>
@endscript
```

---

## Task 7: Tests for Document Editor Signing UI

**Files:**
- Create: `tests/Feature/Corporate/DocumentEditorSigningTest.php`

**Step 1: Write Livewire component tests**

```php
<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use App\Services\CorporateDocumentService;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo(['business_plan.view', 'business_plan.manage']);
    $this->actingAs($this->user);

    // Set up user signature
    $this->user->internalUser->update([
        'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        'signature_type' => 'drawn',
        'signature_updated_at' => now(),
    ]);

    // Create document with fake PDF
    $pdfPath = "corporate-documents/{$this->corporation->id}/test.pdf";
    Storage::put($pdfPath, '%PDF-1.4 fake');

    $this->document = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 1,
        'original_file_path' => $pdfPath,
        'parsed_content' => [['type' => 'paragraph', 'text' => 'Test']],
    ]);
});

it('shows sign document button for managers with signature', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->assertSee('Sign Document');
});

it('hides sign document button for non-managers', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('business_plan.view');
    $this->actingAs($viewer);

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->assertDontSee('Sign Document');
});

it('hides sign document button when user has no signature', function () {
    $this->user->internalUser->update([
        'signature_data' => null,
        'signature_type' => null,
    ]);

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->assertDontSee('Sign Document');
});

it('enters signing mode', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->assertSet('signingMode', true)
        ->assertSet('activeTab', 'signing');
});

it('exits signing mode', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->call('exitSigningMode')
        ->assertSet('signingMode', false)
        ->assertSet('activeTab', 'original');
});

it('saves signatures and creates new version', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')->andReturn('%PDF-1.4 signed');
    app()->instance(PythonBridgeService::class, $mock);

    $placements = [
        ['type' => 'signature', 'page' => 1, 'x' => 0.15, 'y' => 0.85, 'width' => 0.25],
    ];

    $component = Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->call('saveSignatures', $placements);

    $component->assertSet('signingMode', false);

    // A new version should have been created
    $newVersion = CorporateDocument::where('parent_document_id', $this->document->id)->first();
    expect($newVersion)->not->toBeNull();
    expect($newVersion->version)->toBe(2);
});

it('does nothing when saving empty placements', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->call('saveSignatures', []);

    expect(CorporateDocument::where('parent_document_id', $this->document->id)->count())->toBe(0);
});

it('prevents non-managers from entering signing mode', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('business_plan.view');
    $viewer->internalUser->update(['signature_data' => 'test', 'signature_type' => 'typed']);
    $this->actingAs($viewer);

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->assertForbidden();
});

it('derives initials correctly', function () {
    $component = Livewire::test('corporate.document-editor', ['documentId' => $this->document->id]);

    $preview = $component->get('signaturePreview');
    // User name from factory - we just check it has initials key
    expect($preview)->toHaveKey('initials');
    expect(strlen($preview['initials']))->toBeGreaterThanOrEqual(1);
    expect(strlen($preview['initials']))->toBeLessThanOrEqual(2);
});
```

**Step 2: Run tests**

Run: `php artisan test --compact tests/Feature/Corporate/DocumentEditorSigningTest.php`
Expected: All 9 tests pass

---

## Task 8: Run All Corporate Tests + Lint

**Step 1: Run full corporate test suite**

Run: `php artisan test --compact --filter=Corporate`
Expected: All tests pass (existing + new)

**Step 2: Lint**

Run: `vendor/bin/pint --dirty`
Expected: Clean or auto-fixed

**Step 3: Commit**

```bash
git add -A
git commit -m "feat: click-to-place signature, initials, and date stamps on corporate document PDFs"
```

---

## Verification

1. Run `php artisan test --compact --filter=Corporate` — all tests pass
2. Run `php artisan test --compact --filter=DocumentSignature` — service tests pass
3. Run `php artisan test --compact --filter=DocumentEditorSigning` — UI tests pass
4. After deployment: open a corporate document, click "Sign Document", place items, save
5. Download the signed PDF and verify overlays appear at the correct positions
