<?php

use App\Models\CorporateDocument;
use App\Services\CorporateDocumentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $documentId;
    public array $sections = [];
    public bool $hasChanges = false;
    public string $activeTab = 'original';
    public bool $signingMode = false;

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $doc = CorporateDocument::findOrFail($this->documentId);
        $this->sections = $doc->getContentForExport() ?? [];
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
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

    public function enterSigningMode(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        if (! $this->hasSignature) {
            $this->dispatch('show-notification', type: 'error', message: 'Please set up your signature in Settings first.');

            return;
        }

        $this->signingMode = true;
        $this->activeTab = 'signing';
    }

    public function exitSigningMode(): void
    {
        $this->signingMode = false;
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
        $this->activeTab = 'original';
        unset($this->document, $this->allVersions, $this->hasPdfToView, $this->hasSignature, $this->signaturePreview);
    }

    private function deriveInitials(string $name): string
    {
        $parts = explode(' ', trim($name));
        if (count($parts) === 1) {
            return strtoupper(mb_substr($parts[0], 0, 1));
        }

        return strtoupper(mb_substr($parts[0], 0, 1).mb_substr(end($parts), 0, 1));
    }

    #[Computed]
    public function document(): CorporateDocument
    {
        return CorporateDocument::findOrFail($this->documentId);
    }

    #[Computed]
    public function allVersions(): \Illuminate\Database\Eloquent\Collection
    {
        $doc = $this->document;
        $parentId = $doc->parent_document_id ?? $doc->id;

        // Get parent + all children
        return CorporateDocument::where('id', $parentId)
            ->orWhere('parent_document_id', $parentId)
            ->orderBy('version')
            ->get();
    }

    #[Computed]
    public function hasPdfToView(): bool
    {
        $doc = $this->document;

        // Edited versions can always generate a PDF
        if ($doc->edited_content) {
            return true;
        }

        // Original version needs the uploaded file
        return $doc->original_file_path && \Illuminate\Support\Facades\Storage::exists($doc->original_file_path);
    }

    public function switchVersion(int $versionId): void
    {
        $this->documentId = $versionId;
        $doc = CorporateDocument::findOrFail($versionId);
        $this->sections = $doc->getContentForExport() ?? [];
        $this->hasChanges = false;
        $this->activeTab = 'original';
        unset($this->document, $this->allVersions, $this->hasPdfToView);
    }

    public function updatedSections(): void
    {
        $this->hasChanges = true;
    }

    public function addSection(string $type = 'paragraph', ?int $afterIndex = null): void
    {
        $newSection = match ($type) {
            'heading' => ['type' => 'heading', 'level' => 2, 'text' => ''],
            'list_item' => ['type' => 'list_item', 'text' => ''],
            default => ['type' => 'paragraph', 'text' => ''],
        };

        if ($afterIndex !== null && $afterIndex < count($this->sections)) {
            array_splice($this->sections, $afterIndex + 1, 0, [$newSection]);
        } else {
            $this->sections[] = $newSection;
        }

        $this->hasChanges = true;
    }

    public function removeSection(int $index): void
    {
        unset($this->sections[$index]);
        $this->sections = array_values($this->sections);
        $this->hasChanges = true;
    }

    public function moveSection(int $index, string $direction): void
    {
        $newIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($newIndex < 0 || $newIndex >= count($this->sections)) {
            return;
        }

        $temp = $this->sections[$index];
        $this->sections[$index] = $this->sections[$newIndex];
        $this->sections[$newIndex] = $temp;
        $this->hasChanges = true;
    }

    public function save(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        $doc = CorporateDocument::findOrFail($this->documentId);
        $updated = app(CorporateDocumentService::class)->saveEdits($doc, $this->sections);

        if ($updated->id !== $doc->id) {
            $this->documentId = $updated->id;
        }

        $this->hasChanges = false;
        unset($this->document, $this->allVersions, $this->hasPdfToView);
    }
};
?>

<div>
    {{-- Document Info --}}
    <div class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="sm">{{ $this->document->title }}</flux:heading>
                    @if ($hasChanges)
                        <flux:badge size="sm" color="amber">{{ __('Unsaved changes') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ CorporateDocument::TYPES[$this->document->type] ?? $this->document->type }}
                    @if ($this->document->edited_content)
                        &middot; {{ __('Edited') }}
                    @endif
                </flux:text>
            </div>
            <div class="flex items-center gap-2">
                {{-- Version selector --}}
                @if ($this->allVersions->count() > 1)
                    <select wire:change="switchVersion($event.target.value)" class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                        @foreach ($this->allVersions as $ver)
                            <option value="{{ $ver->id }}" {{ $ver->id === $documentId ? 'selected' : '' }}>
                                v{{ $ver->version }}{{ $ver->edited_content ? ' (edited)' : '' }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <flux:badge size="sm" variant="outline">v{{ $this->document->version }}</flux:badge>
                @endif
                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('corporate.document.pdf', $this->document)">
                    {{ __('Download PDF') }}
                </flux:button>
                @if ($this->canManage && $this->hasPdfToView && $this->hasSignature && !$signingMode)
                    <flux:button size="sm" variant="primary" icon="pencil-square" wire:click="enterSigningMode">
                        {{ __('Sign Document') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="mb-4 flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">
        @if ($this->hasPdfToView)
            <button
                wire:click="$set('activeTab', 'original')"
                class="rounded-md px-4 py-2 text-sm font-medium transition {{ $activeTab === 'original' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >
                {{ __('PDF Preview') }}
            </button>
        @endif
        <button
            wire:click="$set('activeTab', 'editor')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $activeTab === 'editor' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
        >
            {{ __('Edit Sections') }}
        </button>
        @if ($signingMode)
            <button
                wire:click="$set('activeTab', 'signing')"
                class="rounded-md px-4 py-2 text-sm font-medium transition {{ $activeTab === 'signing' ? 'bg-green-600 text-white shadow-sm' : 'text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-200' }}"
            >
                {{ __('Sign Document') }}
            </button>
        @endif
    </div>

    {{-- PDF Viewer --}}
    @if ($activeTab === 'original' && $this->hasPdfToView)
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <iframe
                src="{{ route('corporate.document.view', $this->document) }}#toolbar=1&navpanes=1&view=FitH"
                class="h-[calc(100vh-12rem)] min-h-[600px] w-full"
                title="{{ $this->document->title }}"
                type="application/pdf"
            ></iframe>
        </div>
    @endif

    {{-- Section Editor --}}
    @if ($activeTab === 'editor')
        {{-- Editor Controls --}}
        @if ($this->canManage)
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <flux:button size="sm" variant="ghost" wire:click="addSection('heading')">
                    + {{ __('Heading') }}
                </flux:button>
                <flux:button size="sm" variant="ghost" wire:click="addSection('paragraph')">
                    + {{ __('Paragraph') }}
                </flux:button>
                <flux:button size="sm" variant="ghost" wire:click="addSection('list_item')">
                    + {{ __('List Item') }}
                </flux:button>
                <flux:button size="sm" variant="primary" wire:click="save" :disabled="!$hasChanges">
                    {{ __('Save') }}
                </flux:button>
            </div>
        @endif

        {{-- Sections --}}
        @if (empty($sections))
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-zinc-500">{{ __('No content sections. The document may still be processing.') }}</flux:text>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($sections as $i => $section)
                    <div wire:key="section-{{ $i }}" class="group relative rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        {{-- Controls --}}
                        @if ($this->canManage)
                            <div class="absolute -right-1 -top-1 hidden gap-0.5 rounded bg-white shadow dark:bg-zinc-800 group-hover:flex">
                                <button wire:click="moveSection({{ $i }}, 'up')" class="p-1.5 text-zinc-400 hover:text-zinc-600" title="{{ __('Move up') }}">
                                    <flux:icon name="chevron-up" variant="mini" class="size-4" />
                                </button>
                                <button wire:click="moveSection({{ $i }}, 'down')" class="p-1.5 text-zinc-400 hover:text-zinc-600" title="{{ __('Move down') }}">
                                    <flux:icon name="chevron-down" variant="mini" class="size-4" />
                                </button>
                                <button wire:click="removeSection({{ $i }})" class="p-1.5 text-red-400 hover:text-red-600" title="{{ __('Remove') }}">
                                    <flux:icon name="x-mark" variant="mini" class="size-4" />
                                </button>
                            </div>
                        @endif

                        <div class="flex items-start gap-3">
                            <flux:badge size="sm" variant="outline" class="mt-1.5 shrink-0">
                                {{ $section['type'] === 'heading' ? 'H' . ($section['level'] ?? 1) : ($section['type'] === 'list_item' ? 'LI' : 'P') }}
                            </flux:badge>

                            <div class="min-w-0 flex-1">
                                @if ($section['type'] === 'heading')
                                    <div class="flex items-center gap-3">
                                        <flux:select wire:model.live="sections.{{ $i }}.level" size="sm" class="w-20">
                                            <flux:select.option value="1">H1</flux:select.option>
                                            <flux:select.option value="2">H2</flux:select.option>
                                            <flux:select.option value="3">H3</flux:select.option>
                                        </flux:select>
                                        <flux:input wire:model.live.debounce.500ms="sections.{{ $i }}.text" size="sm" class="flex-1 font-bold" />
                                    </div>
                                @else
                                    <flux:textarea wire:model.live.debounce.500ms="sections.{{ $i }}.text" rows="4" class="w-full text-sm" />
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Signing Mode --}}
    @if ($activeTab === 'signing' && $signingMode)
        <div
            x-data="documentSigner({
                pdfUrl: '{{ route('corporate.document.view', $this->document) }}',
                signaturePreview: @js($this->signaturePreview),
            })"
            x-init="init()"
            class="space-y-4"
        >
            {{-- Signing Toolbar --}}
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-950">
                <span class="mr-2 text-sm font-medium text-green-800 dark:text-green-200">{{ __('Place:') }}</span>

                <button
                    type="button"
                    x-on:click="selectTool('signature')"
                    :class="activeTool === 'signature' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                >
                    {{ __('Signature') }}
                </button>
                <button
                    type="button"
                    x-on:click="selectTool('initials')"
                    :class="activeTool === 'initials' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                >
                    {{ __('Initials') }}
                </button>
                <button
                    type="button"
                    x-on:click="selectTool('date')"
                    :class="activeTool === 'date' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                >
                    {{ __('Date') }}
                </button>

                <div class="ml-auto flex items-center gap-2">
                    <span class="text-xs text-green-600 dark:text-green-400" x-text="placements.length + ' item(s) placed'"></span>
                    <button
                        type="button"
                        x-on:click="cancelSigning()"
                        class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="saveSigning()"
                        :disabled="placements.length === 0"
                        class="rounded-md bg-green-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        {{ __('Save Signed Document') }}
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
                            x-on:click="handleCanvasClick($event, pageIndex)"
                            :class="activeTool ? 'cursor-crosshair' : 'cursor-default'"
                        ></canvas>

                        {{-- Placement overlays --}}
                        <template x-for="(p, pi) in getPagePlacements(pageIndex)" :key="p.id">
                            <div
                                class="group absolute cursor-move select-none"
                                :style="getPlacementStyle(p, pageData)"
                                x-on:mousedown="startDrag($event, p)"
                                x-on:touchstart.prevent="startDrag($event, p)"
                            >
                                {{-- Remove button --}}
                                <button
                                    type="button"
                                    x-on:click.stop="removePlacement(p.id)"
                                    class="absolute -right-2 -top-2 z-20 hidden size-5 items-center justify-center rounded-full bg-red-500 text-xs text-white shadow group-hover:flex"
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
                                            <span class="text-xl" style="font-family: 'Brush Script MT', 'Segoe Script', cursive;" x-text="signaturePreview.data"></span>
                                        </template>
                                    </div>
                                </template>

                                {{-- Initials preview --}}
                                <template x-if="p.type === 'initials'">
                                    <div class="rounded border border-blue-400/50 bg-blue-50/80 px-2 py-1">
                                        <span class="text-lg font-semibold" style="font-family: 'Brush Script MT', 'Segoe Script', cursive;" x-text="signaturePreview.initials"></span>
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
                        <span>{{ __('Loading PDF pages...') }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@script
<script>
    Alpine.data('documentSigner', (config) => ({
        pdfUrl: config.pdfUrl,
        signaturePreview: config.signaturePreview,
        activeTool: null,
        placements: [],
        pages: [],
        loading: true,
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
