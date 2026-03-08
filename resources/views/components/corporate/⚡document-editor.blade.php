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
    public string $sessionSignatureName = '';
    public string $sessionSignatureData = '';
    public string $sessionSignatureType = 'typed';

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
        // Prefer session signature over stored signature
        if ($this->sessionSignatureData) {
            return [
                'type' => $this->sessionSignatureType,
                'data' => $this->sessionSignatureData,
                'initials' => $this->deriveInitials($this->sessionSignatureName ?: Auth::user()->name),
            ];
        }

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

        $user = Auth::user();
        $internalUser = $user->internalUser;

        // Pre-fill from stored signature if available
        if ($internalUser && $internalUser->hasSignature()) {
            $this->sessionSignatureData = $internalUser->signature_data ?? '';
            $this->sessionSignatureType = $internalUser->signature_type ?? 'typed';
        }
        $this->sessionSignatureName = $user->name;

        $this->signingMode = true;
        $this->activeTab = 'signing';
    }

    public function exitSigningMode(): void
    {
        $this->signingMode = false;
        $this->activeTab = 'original';
    }

    public function saveSignatures(array $placements, ?string $signatureData = null, ?string $signatureType = null, ?string $signerName = null): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        if (empty($placements)) {
            return;
        }

        // Use passed-in session signature, falling back to stored values
        $finalSignatureData = $signatureData ?: $this->sessionSignatureData;
        $finalSignatureType = $signatureType ?: $this->sessionSignatureType;
        $finalSignerName = $signerName ?: $this->sessionSignatureName ?: Auth::user()->name;

        if (empty($finalSignatureData)) {
            $this->dispatch('show-notification', type: 'error', message: 'Please create a signature before saving.');

            return;
        }

        $doc = CorporateDocument::findOrFail($this->documentId);
        $service = app(CorporateDocumentService::class);

        $newVersion = $service->applySignatures(
            $doc,
            $placements,
            $finalSignatureData,
            $finalSignatureType,
            $finalSignerName,
        );

        $this->documentId = $newVersion->id;
        $this->signingMode = false;
        $this->activeTab = 'original';
        $this->sessionSignatureData = '';
        $this->sessionSignatureName = '';
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
                @if ($this->canManage && $this->hasPdfToView && !$signingMode)
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
                signerName: @js($sessionSignatureName),
                signatureType: @js($sessionSignatureType),
                signatureData: @js($sessionSignatureData),
            })"
            x-init="init()"
            class="space-y-4"
        >
            {{-- Signature Setup Panel --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Signing As') }}</span>
                    <button type="button" x-on:click="showSignatureSetup = !showSignatureSetup; if (showSignatureSetup && sigType === 'drawn') $nextTick(() => initSigPad())" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400">
                        <span x-text="showSignatureSetup ? 'Collapse' : 'Change Signature'"></span>
                    </button>
                </div>

                {{-- Compact preview when collapsed --}}
                <div x-show="!showSignatureSetup" class="flex items-center gap-4">
                    <div class="flex items-center gap-2 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-1.5 dark:border-zinc-600 dark:bg-zinc-700">
                        <template x-if="sigType === 'drawn' && sigData">
                            <img :src="sigData" class="h-8 max-w-[120px]" />
                        </template>
                        <template x-if="sigType !== 'drawn' && sigData">
                            <span class="text-lg" style="font-family: 'Brush Script MT', 'Segoe Script', cursive;" x-text="sigData"></span>
                        </template>
                        <template x-if="!sigData">
                            <span class="text-sm italic text-zinc-400">{{ __('No signature set') }}</span>
                        </template>
                    </div>
                    <span class="text-sm text-zinc-600 dark:text-zinc-400" x-text="signerName"></span>
                </div>

                {{-- Expanded signature setup --}}
                <div x-show="showSignatureSetup" x-cloak class="space-y-3">
                    {{-- Signer Name/Title --}}
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('Name / Title') }}</label>
                        <input
                            type="text"
                            x-model="signerName"
                            class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                            placeholder="e.g. Matt Stanford, Director"
                        />
                    </div>

                    {{-- Signature Type Toggle --}}
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('Signature') }}</label>
                        <div class="mb-2 flex overflow-hidden rounded-md border border-zinc-200 dark:border-zinc-600">
                            <button
                                type="button"
                                x-on:click="switchSigType('typed')"
                                :class="sigType === 'typed' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                                class="flex-1 px-3 py-1.5 text-xs font-medium transition"
                            >
                                {{ __('Type') }}
                            </button>
                            <button
                                type="button"
                                x-on:click="switchSigType('drawn')"
                                :class="sigType === 'drawn' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                                class="flex-1 border-l border-zinc-200 px-3 py-1.5 text-xs font-medium transition dark:border-zinc-600"
                            >
                                {{ __('Draw') }}
                            </button>
                        </div>

                        {{-- Typed input --}}
                        <template x-if="sigType === 'typed'">
                            <div class="space-y-2">
                                <input
                                    type="text"
                                    x-model="sigData"
                                    x-on:input="updatePreview()"
                                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                                    placeholder="Type your signature"
                                />
                                <div x-show="sigData" class="rounded-md border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-600 dark:bg-zinc-700">
                                    <span class="text-2xl" style="font-family: 'Brush Script MT', 'Segoe Script', cursive;" x-text="sigData"></span>
                                </div>
                            </div>
                        </template>

                        {{-- Draw canvas --}}
                        <div x-show="sigType === 'drawn'" class="space-y-2">
                            <div class="relative">
                                <canvas
                                    x-ref="sigCanvas"
                                    class="h-40 w-full cursor-crosshair touch-none rounded-md border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800"
                                ></canvas>
                                <div x-show="sigPadEmpty" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                    <span class="text-xs text-zinc-400">{{ __('Draw your signature here') }}</span>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <button type="button" x-on:click="clearSigPad()" class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                                    {{ __('Clear') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Signing Toolbar --}}
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-950">
                <span class="mr-2 text-sm font-medium text-green-800 dark:text-green-200">{{ __('Place:') }}</span>

                <button
                    type="button"
                    x-on:click="selectTool('signature')"
                    :disabled="!sigData"
                    :class="activeTool === 'signature' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed"
                >
                    {{ __('Signature') }}
                </button>
                <button
                    type="button"
                    x-on:click="selectTool('initials')"
                    :disabled="!signerName"
                    :class="activeTool === 'initials' ? 'bg-green-600 text-white' : 'bg-white text-green-700 hover:bg-green-100 dark:bg-zinc-800 dark:text-green-300'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed"
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

                <div class="mx-1 h-6 w-px bg-green-300 dark:bg-green-700"></div>

                <button
                    type="button"
                    x-on:click="selectTool('text')"
                    :class="activeTool === 'text' ? 'bg-purple-600 text-white' : 'bg-white text-purple-700 hover:bg-purple-100 dark:bg-zinc-800 dark:text-purple-300'"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                >
                    {{ __('Text Field') }}
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
                        :disabled="placements.length === 0 || (hasSignaturePlacements() && !sigData)"
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
                        class="relative mx-auto overflow-visible rounded-lg border border-zinc-200 bg-white shadow dark:border-zinc-700"
                        :style="'width: fit-content'"
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
                                :style="getPlacementStyle(p, pageData) + 'width:' + (p.boxWidth || 150) + 'px; height:' + (p.boxHeight || 40) + 'px;'"
                                x-on:mousedown="startDrag($event, p)"
                                x-on:touchstart.prevent="startDrag($event, p)"
                            >
                                {{-- Delete button --}}
                                <button
                                    type="button"
                                    x-on:click.stop="removePlacement(p.id)"
                                    class="absolute -right-2 -top-2 z-30 flex size-5 items-center justify-center rounded-full bg-red-500 text-xs text-white shadow-md hover:bg-red-600"
                                >
                                    &times;
                                </button>

                                {{-- Resize handle --}}
                                <div
                                    x-on:mousedown.stop.prevent="startResize($event, p, pageData)"
                                    x-on:touchstart.stop.prevent="startResize($event, p, pageData)"
                                    class="absolute -bottom-1 -right-1 z-30 flex size-4 cursor-nwse-resize items-center justify-center rounded-sm bg-white shadow ring-1 ring-zinc-300"
                                >
                                    <svg class="size-2.5 text-zinc-500" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M22 22H20V20H22V22ZM22 18H18V22H16V16H22V18ZM22 14H14V22H12V12H22V14Z"/>
                                    </svg>
                                </div>

                                {{-- Signature --}}
                                <template x-if="p.type === 'signature'">
                                    <div class="flex size-full items-center justify-center overflow-hidden rounded border border-green-400/50 bg-green-50/80 px-2 py-1">
                                        <template x-if="sigType === 'drawn' && sigData">
                                            <img :src="sigData" class="max-h-full max-w-full object-contain" />
                                        </template>
                                        <template x-if="sigType !== 'drawn' && sigData">
                                            <span
                                                class="whitespace-nowrap text-zinc-800"
                                                style="font-family: 'Brush Script MT', 'Segoe Script', cursive;"
                                                :style="'font-size:' + Math.min(Math.max((p.boxHeight || 45) * 0.6, 10), 36) + 'px;'"
                                                x-text="sigData"
                                            ></span>
                                        </template>
                                        <template x-if="!sigData">
                                            <span class="text-xs italic text-green-600">Signature</span>
                                        </template>
                                    </div>
                                </template>

                                {{-- Initials --}}
                                <template x-if="p.type === 'initials'">
                                    <div class="flex size-full items-center justify-center overflow-hidden rounded border border-blue-400/50 bg-blue-50/80 px-2 py-1">
                                        <span
                                            class="font-semibold text-zinc-800"
                                            style="font-family: 'Brush Script MT', 'Segoe Script', cursive;"
                                            :style="'font-size:' + Math.min(Math.max((p.boxHeight || 35) * 0.6, 10), 28) + 'px;'"
                                            x-text="getInitials()"
                                        ></span>
                                    </div>
                                </template>

                                {{-- Date --}}
                                <template x-if="p.type === 'date'">
                                    <div class="flex size-full items-center overflow-hidden rounded border border-amber-400/50 bg-amber-50/80 px-2 py-1">
                                        <span
                                            class="whitespace-nowrap text-zinc-800"
                                            :style="'font-size:' + Math.min(Math.max((p.boxHeight || 22) * 0.6, 8), 14) + 'px;'"
                                            x-text="currentDate"
                                        ></span>
                                    </div>
                                </template>

                                {{-- Text field --}}
                                <template x-if="p.type === 'text'">
                                    <div class="size-full overflow-hidden rounded border border-purple-400/50 bg-purple-50/80">
                                        <textarea
                                            x-on:mousedown.stop
                                            x-on:touchstart.stop
                                            x-on:click.stop
                                            x-model="p.text"
                                            class="block size-full resize-none border-none bg-transparent px-2 py-1 text-xs text-zinc-800 placeholder-purple-400 focus:outline-none focus:ring-0"
                                            placeholder="Type text here..."
                                        ></textarea>
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

@assets
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
@endassets

@script
<script>
    Alpine.data('documentSigner', (config) => ({
        pdfUrl: config.pdfUrl,
        signaturePreview: config.signaturePreview,
        signerName: config.signerName || '',
        sigType: config.signatureType || 'typed',
        sigData: config.signatureData || '',
        showSignatureSetup: !config.signatureData,
        sigPad: null,
        sigPadEmpty: true,
        activeTool: null,
        placements: [],
        pages: [],
        loading: true,
        nextId: 1,

        currentDate: new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),

        async init() {
            await this.loadPdf();
            if (this.sigType === 'drawn' && this.showSignatureSetup) {
                this.$nextTick(() => this.initSigPad());
            }
        },

        async loadPdf() {
            this.loading = true;

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

            const defaults = {
                signature: { w: 150, h: 45 },
                initials:  { w: 50,  h: 35 },
                date:      { w: 130, h: 22 },
                text:      { w: 220, h: 70 },
            };
            const size = defaults[this.activeTool] || { w: 150, h: 40 };

            const placement = {
                id: this.nextId++,
                type: this.activeTool,
                page: pageIndex + 1,
                x: Math.max(0, Math.min(x, 1)),
                y: Math.max(0, Math.min(y, 1)),
                width: size.w / pageData.width,
                boxWidth: size.w,
                boxHeight: size.h,
            };

            if (this.activeTool === 'text') {
                placement.text = '';
            }

            this.placements.push(placement);
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

            // Check if any signature placements exist and require sigData
            if (this.hasSignaturePlacements() && !this.sigData) return;

            // Filter out empty text placements
            const validPlacements = this.placements.filter(p => {
                if (p.type === 'text') return p.text && p.text.trim().length > 0;
                return true;
            });

            if (validPlacements.length === 0) return;

            const data = validPlacements.map(p => {
                const pg = this.pages[p.page - 1];
                const item = {
                    type: p.type,
                    page: p.page,
                    x: p.x,
                    y: p.y,
                    width: p.boxWidth && pg ? p.boxWidth / pg.width : p.width,
                };
                if (pg && p.boxHeight) {
                    item.height = p.boxHeight / pg.height;
                }
                if (p.type === 'text') {
                    item.text = p.text;
                }
                return item;
            });

            this.$wire.saveSignatures(data, this.sigData, this.sigType, this.signerName);
        },

        hasSignaturePlacements() {
            return this.placements.some(p => p.type === 'signature');
        },

        getInitials() {
            if (!this.signerName) return 'XX';
            const parts = this.signerName.trim().split(/\s+/);
            if (parts.length === 1) return parts[0][0].toUpperCase();
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        },

        updatePreview() {
            // Typed signature is live via x-model, no extra work needed
        },

        switchSigType(type) {
            this.sigType = type;
            if (type === 'typed') {
                this.sigData = '';
                this.sigPadEmpty = true;
            } else {
                this.sigData = '';
                this.$nextTick(() => {
                    // Re-init since canvas may need resize after becoming visible
                    this.initSigPad();
                });
            }
        },

        initSigPad() {
            const canvas = this.$refs.sigCanvas;
            if (!canvas || !window.SignaturePad) {
                setTimeout(() => this.initSigPad(), 100);
                return;
            }

            // Canvas must be visible to get correct dimensions
            const rect = canvas.getBoundingClientRect();
            if (rect.width === 0) {
                setTimeout(() => this.initSigPad(), 50);
                return;
            }

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            this.sigPad = new SignaturePad(canvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: document.documentElement.classList.contains('dark') ? '#ffffff' : '#000000',
                minWidth: 1,
                maxWidth: 2.5,
            });

            const self = this;
            this.sigPad.addEventListener('beginStroke', () => {
                self.sigPadEmpty = false;
            });

            this.sigPad.addEventListener('endStroke', () => {
                self.sigData = self.sigPad.toDataURL('image/png');
            });
        },

        clearSigPad() {
            if (this.sigPad) {
                this.sigPad.clear();
                this.sigPadEmpty = true;
                this.sigData = '';
            }
        },

        startResize(event, placement, pageData) {
            const isTouch = event.type === 'touchstart';
            const startX = isTouch ? event.touches[0].clientX : event.clientX;
            const startY = isTouch ? event.touches[0].clientY : event.clientY;
            const origW = placement.boxWidth || 150;
            const origH = placement.boxHeight || 40;

            const mins = {
                signature: { w: 60, h: 20 },
                initials:  { w: 25, h: 18 },
                date:      { w: 60, h: 14 },
                text:      { w: 80, h: 24 },
            };
            const min = mins[placement.type] || { w: 40, h: 16 };

            const onMove = (e) => {
                const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
                const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;

                placement.boxWidth = Math.max(min.w, origW + (clientX - startX));
                placement.boxHeight = Math.max(min.h, origH + (clientY - startY));
                placement.width = placement.boxWidth / pageData.width;
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
    }));
</script>
@endscript
