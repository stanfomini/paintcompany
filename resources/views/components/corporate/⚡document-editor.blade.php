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
</div>
