<?php

use App\Models\ContractTemplate;
use App\Services\ContractService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.app')]
#[Title('Edit Contract Template')]
class extends Component
{
    use WithFileUploads;

    public function boot(): void
    {
        if (! auth()->user()?->can('settings.contracts')) {
            abort(403);
        }
    }

    public ContractTemplate $contractTemplate;

    public string $name = '';

    public string $description = '';

    public string $content = '';

    public $pdfUpload;

    public bool $showPreview = false;

    public string $previewHtml = '';

    /** @var array{pages?: array, fields?: list<array>} */
    public array $pdfFields = [];

    public int $currentPage = 1;

    public function mount(ContractTemplate $contractTemplate): void
    {
        $this->contractTemplate = $contractTemplate;
        $this->name = $contractTemplate->name;
        $this->description = $contractTemplate->description ?? '';
        $this->content = $contractTemplate->content ?? '';
        $this->pdfFields = $contractTemplate->fields ?? ['pages' => [], 'fields' => []];

        if (! isset($this->pdfFields['pages'])) {
            $this->pdfFields['pages'] = [];
        }
        if (! isset($this->pdfFields['fields'])) {
            $this->pdfFields['fields'] = [];
        }
    }

    #[Computed]
    public function availableMergeTags(): array
    {
        return ContractTemplate::getAvailableMergeTags();
    }

    #[Computed]
    public function mergeTagGroups(): array
    {
        return [
            __('Employee') => [
                '{employee_name}',
                '{employee_number}',
                '{job_title}',
                '{department}',
                '{employee_address}',
                '{employee_phone}',
                '{employee_email}',
            ],
            __('Compensation') => [
                '{pay_rate}',
                '{pay_type}',
                '{hire_date}',
            ],
            __('Company') => [
                '{company_name}',
                '{company_address}',
                '{date}',
            ],
        ];
    }

    #[Computed]
    public function pdfPageCount(): int
    {
        return count($this->pdfFields['pages'] ?? []);
    }

    #[Computed]
    public function currentPageFields(): array
    {
        return array_values(array_filter(
            $this->pdfFields['fields'] ?? [],
            fn (array $f) => ($f['page'] ?? 1) === $this->currentPage,
        ));
    }

    public function addField(string $type): void
    {
        $id = 'field_'.uniqid();

        $this->pdfFields['fields'][] = [
            'id' => $id,
            'type' => $type,
            'label' => ucfirst($type),
            'merge_tag' => null,
            'signer_role' => 'employee',
            'page' => $this->currentPage,
            'x_pct' => 20.0,
            'y_pct' => 20.0,
            'width_pct' => $type === 'checkbox' ? 4.0 : 30.0,
            'height_pct' => $type === 'signature' ? 8.0 : 3.5,
            'required' => true,
            'font_size' => 12,
        ];
    }

    public function removeField(string $fieldId): void
    {
        $this->pdfFields['fields'] = array_values(array_filter(
            $this->pdfFields['fields'],
            fn (array $f) => $f['id'] !== $fieldId,
        ));
    }

    public function updateFieldPosition(string $fieldId, float $xPct, float $yPct): void
    {
        $this->pdfFields['fields'] = array_map(function (array $f) use ($fieldId, $xPct, $yPct) {
            if ($f['id'] === $fieldId) {
                $f['x_pct'] = round($xPct, 2);
                $f['y_pct'] = round($yPct, 2);
            }

            return $f;
        }, $this->pdfFields['fields']);
    }

    public function updateFieldSize(string $fieldId, float $widthPct, float $heightPct): void
    {
        $this->pdfFields['fields'] = array_map(function (array $f) use ($fieldId, $widthPct, $heightPct) {
            if ($f['id'] === $fieldId) {
                $f['width_pct'] = round(max(2, $widthPct), 2);
                $f['height_pct'] = round(max(1, $heightPct), 2);
            }

            return $f;
        }, $this->pdfFields['fields']);
    }

    public function updateFieldProperty(string $fieldId, string $prop, mixed $value): void
    {
        $allowed = ['label', 'merge_tag', 'required', 'font_size', 'signer_role'];
        if (! in_array($prop, $allowed)) {
            return;
        }

        $this->pdfFields['fields'] = array_map(function (array $f) use ($fieldId, $prop, $value) {
            if ($f['id'] === $fieldId) {
                $f[$prop] = $value;
            }

            return $f;
        }, $this->pdfFields['fields']);
    }

    public function saveFields(): void
    {
        $this->contractTemplate->update([
            'fields' => $this->pdfFields,
        ]);
        session()->flash('success', __('Field positions saved.'));
    }

    public function updatePageDimensions(int $pageNum, float $width, float $height): void
    {
        $this->pdfFields['pages'][(string) $pageNum] = [
            'width' => $width,
            'height' => $height,
        ];
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'content' => 'nullable|string|max:100000',
        ]);

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'content' => $this->content,
        ];

        $this->contractTemplate->update($data);
        session()->flash('success', __('Template saved successfully.'));
    }

    public function uploadPdf(): void
    {
        $this->validate([
            'pdfUpload' => 'required|file|mimes:pdf|max:10240',
        ]);

        $path = $this->pdfUpload->store('contract-templates', config('filesystems.default'));
        $this->contractTemplate->update(['pdf_path' => $path]);
        $this->pdfUpload = null;
        session()->flash('success', __('PDF uploaded successfully.'));
    }

    public function togglePreview(): void
    {
        if (! $this->showPreview) {
            $this->previewHtml = $this->generatePreview();
        }
        $this->showPreview = ! $this->showPreview;
    }

    private function generatePreview(): string
    {
        $sampleReplacements = [
            '{employee_name}' => 'John Smith',
            '{employee_number}' => 'EMP-001',
            '{job_title}' => 'Lead Painter',
            '{department}' => 'Production',
            '{pay_rate}' => '$25.00',
            '{pay_type}' => 'hourly',
            '{hire_date}' => now()->format('m/d/Y'),
            '{company_name}' => 'Apex Painting Co.',
            '{company_address}' => '123 Main St, Denver, CO 80202',
            '{date}' => now()->format('m/d/Y'),
            '{employee_address}' => '456 Oak Ave, Boulder, CO 80301',
            '{employee_phone}' => '(303) 555-1234',
            '{employee_email}' => 'john.smith@example.com',
        ];

        return str_replace(
            array_keys($sampleReplacements),
            array_values($sampleReplacements),
            $this->content
        );
    }

    public function bumpVersion(): void
    {
        $this->contractTemplate->increment('version');
        $this->contractTemplate->refresh();
        session()->flash('success', __('Version bumped to v:version.', ['version' => $this->contractTemplate->version]));
    }
}; ?>

<div>
    <div class="mx-auto max-w-7xl">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('contracts.templates')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <div>
                    <flux:heading size="xl">{{ __('Edit Template') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $contractTemplate->name }} &middot; v{{ $contractTemplate->version }}
                    </flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <flux:button wire:click="bumpVersion" size="sm" variant="ghost" icon="arrow-path">
                    {{ __('Bump Version') }}
                </flux:button>
                @if ($contractTemplate->content_type === 'rich_text')
                    <flux:button wire:click="togglePreview" size="sm" variant="{{ $showPreview ? 'primary' : 'ghost' }}" icon="eye">
                        {{ $showPreview ? __('Edit') : __('Preview') }}
                    </flux:button>
                @endif
                <flux:button wire:click="save" variant="primary" icon="check">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Name & Description --}}
        <div class="mb-6 grid gap-4 md:grid-cols-2">
            <flux:field>
                <flux:label>{{ __('Template Name') }}</flux:label>
                <flux:input wire:model="name" />
                <flux:error name="name" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:input wire:model="description" placeholder="{{ __('Brief description...') }}" />
                <flux:error name="description" />
            </flux:field>
        </div>

        @if ($contractTemplate->content_type === 'rich_text')
            <link rel="stylesheet" href="https://unpkg.com/trix@2.1.12/dist/trix.css">
            <script src="https://unpkg.com/trix@2.1.12/dist/trix.umd.min.js"></script>
            <style>
                trix-toolbar { background: transparent; border-bottom: 1px solid rgb(228 228 231); border-radius: 0.5rem 0.5rem 0 0; padding: 0.75rem; }
                trix-toolbar .trix-button-group { margin-bottom: 0; }
                trix-toolbar .trix-button { padding: 0.375rem 0.5rem; }
                .dark trix-toolbar { border-color: rgb(63 63 70); }
                .dark trix-toolbar .trix-button { color: rgb(161 161 170); }
                .dark trix-toolbar .trix-button.trix-active { background: rgb(39 39 42); }
                .dark trix-toolbar .trix-button-group { border-color: rgb(63 63 70); }
                trix-editor:empty:not(:focus)::before { color: rgb(161 161 170); }
            </style>
            <script>
                document.addEventListener('trix-file-accept', function(e) { e.preventDefault(); });
            </script>

            @if ($showPreview)
                {{-- Preview Mode --}}
                <div class="mx-auto max-w-4xl rounded-lg border border-zinc-200 bg-white p-10 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-6 flex items-center gap-2">
                        <flux:badge size="sm" color="blue">{{ __('Preview') }}</flux:badge>
                        <flux:text class="text-sm text-zinc-500">{{ __('Sample data shown for merge tags') }}</flux:text>
                    </div>
                    <div class="trix-content prose prose-lg max-w-none dark:prose-invert">
                        {!! $previewHtml !!}
                    </div>
                </div>
            @else
                {{-- Merge Tags Panel --}}
                <div x-data="{ open: false }" class="mb-4">
                    <button
                        type="button"
                        x-on:click="open = !open"
                        class="flex w-full items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-left transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                    >
                        <div class="flex items-center gap-2">
                            <flux:icon name="code-bracket" class="size-4 text-zinc-500" />
                            <flux:text class="text-sm font-medium">{{ __('Merge Tags') }}</flux:text>
                            <flux:text class="text-xs text-zinc-400">{{ __('Click to insert employee/company data') }}</flux:text>
                        </div>
                        <flux:icon name="chevron-down" class="size-4 text-zinc-400 transition" x-bind:class="open && 'rotate-180'" />
                    </button>

                    <div x-show="open" x-collapse class="mt-2 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex flex-wrap gap-x-8 gap-y-4">
                            @foreach ($this->mergeTagGroups as $group => $tags)
                                <div>
                                    <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ $group }}</flux:text>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($tags as $tag)
                                            <button
                                                type="button"
                                                x-on:click="
                                                    const trixEditor = document.querySelector('trix-editor');
                                                    if (trixEditor && trixEditor.editor) {
                                                        trixEditor.editor.insertString('{{ $tag }}');
                                                        $wire.set('content', document.getElementById('contract-content').value);
                                                    }
                                                "
                                                class="rounded-md bg-zinc-100 px-2.5 py-1 text-xs font-mono text-zinc-700 transition hover:bg-blue-100 hover:text-blue-700 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-blue-900 dark:hover:text-blue-300"
                                            >
                                                {{ $tag }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Full-Width Content Editor --}}
                <div class="overflow-hidden rounded-lg border border-zinc-200 shadow-sm dark:border-zinc-700">
                    <input id="contract-content" type="hidden" value="{{ $content }}" />
                    <trix-editor
                        input="contract-content"
                        class="trix-content prose prose-lg max-w-none dark:prose-invert min-h-[700px] bg-white dark:bg-zinc-900 px-8 py-6"
                        x-on:trix-change="$wire.set('content', $event.target.value)"
                        placeholder="{{ __('Enter your contract content here. Use merge tags like {employee_name} to personalize...') }}"
                    ></trix-editor>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <flux:error name="content" />
                    <flux:text class="text-xs text-zinc-400">
                        {{ str_word_count(strip_tags($content)) }} {{ str_word_count(strip_tags($content)) === 1 ? __('word') : __('words') }}
                    </flux:text>
                </div>
            @endif
        @else
            {{-- PDF Template --}}
            <div class="space-y-6">
                {{-- PDF Upload Section --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('PDF Template') }}</flux:heading>

                    @if ($contractTemplate->pdf_path)
                        <div class="mb-4 flex items-center gap-3 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                            <flux:icon name="document" class="h-8 w-8 text-zinc-400" />
                            <div>
                                <flux:text class="font-medium">{{ basename($contractTemplate->pdf_path) }}</flux:text>
                                <flux:text class="text-sm text-zinc-500">{{ __('Current PDF template') }}</flux:text>
                            </div>
                        </div>
                    @endif

                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>{{ __('Upload New PDF') }}</flux:label>
                            <input type="file" wire:model="pdfUpload" accept=".pdf" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-800 dark:file:text-zinc-300" />
                            <flux:error name="pdfUpload" />
                        </flux:field>

                        @if ($pdfUpload)
                            <flux:button wire:click="uploadPdf" variant="primary" size="sm">
                                {{ __('Upload PDF') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                {{-- PDF Field Placer --}}
                @if ($contractTemplate->pdf_path)
                    <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                        <div class="mb-4 flex items-center justify-between">
                            <flux:heading size="sm">{{ __('Place Form Fields') }}</flux:heading>
                            <flux:button wire:click="saveFields" variant="primary" size="sm" icon="check">
                                {{ __('Save Fields') }}
                            </flux:button>
                        </div>

                        <flux:text class="mb-4 text-sm text-zinc-500">
                            {{ __('Add fields and drag them to their positions on the PDF. Fields will be filled in when the contract is sent for signing.') }}
                        </flux:text>

                        {{-- Field Type Toolbar --}}
                        <div class="mb-4 flex flex-wrap gap-2">
                            <flux:button wire:click="addField('text')" size="sm" variant="ghost" icon="pencil">
                                {{ __('+ Text') }}
                            </flux:button>
                            <flux:button wire:click="addField('signature')" size="sm" variant="ghost" icon="pencil-square">
                                {{ __('+ Signature') }}
                            </flux:button>
                            <flux:button wire:click="addField('date')" size="sm" variant="ghost" icon="calendar">
                                {{ __('+ Date') }}
                            </flux:button>
                            <flux:button wire:click="addField('checkbox')" size="sm" variant="ghost" icon="check">
                                {{ __('+ Checkbox') }}
                            </flux:button>
                            <flux:button wire:click="addField('initials')" size="sm" variant="ghost" icon="user">
                                {{ __('+ Initials') }}
                            </flux:button>
                        </div>

                        {{-- PDF Canvas + Field Overlay --}}
                        <div
                            x-data="pdfFieldPlacer({
                                pdfUrl: '{{ route('contracts.templates.pdf-preview', $contractTemplate) }}',
                                currentPage: @entangle('currentPage'),
                            })"
                            x-init="loadDocument()"
                            class="relative"
                        >
                            {{-- Page Navigation --}}
                            <div class="mb-3 flex items-center justify-center gap-4" x-show="totalPages > 1">
                                <flux:button size="xs" variant="ghost" icon="chevron-left" x-on:click="prevPage()" x-bind:disabled="$wire.currentPage <= 1" />
                                <flux:text class="text-sm">
                                    {{ __('Page') }} <span x-text="$wire.currentPage"></span> {{ __('of') }} <span x-text="totalPages"></span>
                                </flux:text>
                                <flux:button size="xs" variant="ghost" icon="chevron-right" x-on:click="nextPage()" x-bind:disabled="$wire.currentPage >= totalPages" />
                            </div>

                            {{-- PDF + Field Overlay Container --}}
                            <div class="relative mx-auto overflow-hidden rounded-lg border border-zinc-300 bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900" x-ref="pdfContainer">
                                <canvas x-ref="pdfCanvas" wire:ignore class="block w-full"></canvas>

                                {{-- Draggable Field Overlays --}}
                                @foreach ($this->currentPageFields as $field)
                                    <div
                                        wire:key="field-{{ $field['id'] }}"
                                        x-data="draggableField('{{ $field['id'] }}')"
                                        class="group absolute cursor-move border-2 border-dashed rounded px-1 flex items-center justify-center text-xs font-medium transition-colors select-none
                                            {{ match($field['type']) {
                                                'signature', 'initials' => 'border-purple-400 bg-purple-50/80 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
                                                'checkbox' => 'border-green-400 bg-green-50/80 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                                'date' => 'border-amber-400 bg-amber-50/80 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                                default => 'border-blue-400 bg-blue-50/80 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                            } }}"
                                        style="left: {{ $field['x_pct'] }}%; top: {{ $field['y_pct'] }}%; width: {{ $field['width_pct'] }}%; height: {{ $field['height_pct'] }}%;"
                                        x-on:mousedown.prevent="startDrag($event)"
                                        x-on:touchstart.prevent="startDrag($event)"
                                    >
                                        <span class="truncate">{{ $field['label'] }}</span>

                                        {{-- Delete button --}}
                                        <button
                                            type="button"
                                            wire:click="removeField('{{ $field['id'] }}')"
                                            class="absolute -right-2 -top-2 hidden rounded-full bg-red-500 p-0.5 text-white shadow group-hover:block"
                                            x-on:mousedown.stop
                                        >
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Loading indicator --}}
                            <div x-show="loading" class="absolute inset-0 flex items-center justify-center bg-white/70 dark:bg-zinc-900/70">
                                <flux:icon name="arrow-path" class="size-8 animate-spin text-zinc-400" />
                            </div>
                        </div>

                        {{-- Field Properties Panel --}}
                        @if (count($this->currentPageFields) > 0)
                            <div class="mt-4 space-y-3">
                                <flux:heading size="sm">{{ __('Field Properties') }}</flux:heading>
                                @foreach ($this->currentPageFields as $field)
                                    <div wire:key="props-{{ $field['id'] }}" class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                        <flux:badge size="sm" :color="match($field['type']) { 'signature', 'initials' => 'purple', 'checkbox' => 'green', 'date' => 'amber', default => 'blue' }">
                                            {{ ucfirst($field['type']) }}
                                        </flux:badge>
                                        <input
                                            type="text"
                                            value="{{ $field['label'] }}"
                                            class="rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                            x-on:change="$wire.updateFieldProperty('{{ $field['id'] }}', 'label', $event.target.value)"
                                            placeholder="{{ __('Label') }}"
                                        />
                                        <select
                                            class="rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                            x-on:change="$wire.updateFieldProperty('{{ $field['id'] }}', 'merge_tag', $event.target.value || null)"
                                        >
                                            <option value="">{{ __('No merge tag') }}</option>
                                            @foreach (['employee_name', 'employee_number', 'job_title', 'department', 'pay_rate', 'pay_type', 'hire_date', 'company_name', 'company_address', 'date', 'employee_address', 'employee_phone', 'employee_email'] as $tag)
                                                <option value="{{ $tag }}" @selected(($field['merge_tag'] ?? '') === $tag)>{{ '{' . $tag . '}' }}</option>
                                            @endforeach
                                        </select>
                                        <select
                                            class="rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                            x-on:change="$wire.updateFieldProperty('{{ $field['id'] }}', 'signer_role', $event.target.value)"
                                            title="{{ __('Signer role') }}"
                                        >
                                            <option value="employee" @selected(($field['signer_role'] ?? 'employee') === 'employee')>{{ __('Employee Signs') }}</option>
                                            <option value="employer" @selected(($field['signer_role'] ?? 'employee') === 'employer')>{{ __('Employer Signs') }}</option>
                                        </select>
                                        <input
                                            type="number"
                                            value="{{ $field['font_size'] ?? 12 }}"
                                            class="w-16 rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                            x-on:change="$wire.updateFieldProperty('{{ $field['id'] }}', 'font_size', parseInt($event.target.value))"
                                            min="6"
                                            max="48"
                                            title="{{ __('Font size') }}"
                                        />
                                        <flux:button wire:click="removeField('{{ $field['id'] }}')" size="xs" variant="ghost" class="text-red-500">
                                            {{ __('Remove') }}
                                        </flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    @if ($contractTemplate->content_type === 'pdf' && $contractTemplate->pdf_path)
        @assets
            @vite('resources/js/pdf-viewer.js')
        @endassets

        @script
        <script>
            // PDF Field Placer Alpine component
            // NOTE: pdfDoc stored outside Alpine reactive system to avoid Proxy wrapping,
            // which breaks pdfjs-dist private class fields (#o, etc.)
            Alpine.data('pdfFieldPlacer', (config) => {
                let rawPdfDoc = null;

                return {
                    totalPages: 0,
                    loading: true,
                    scale: 1.5,

                    async loadDocument() {
                        if (!window.PdfViewer) {
                            this.loading = false;
                            return;
                        }

                        try {
                            rawPdfDoc = await window.PdfViewer.loadPdf(config.pdfUrl);
                            this.totalPages = rawPdfDoc.numPages;
                            await this.renderCurrentPage();
                        } catch (e) {
                            console.error('Failed to load PDF:', e);
                        } finally {
                            this.loading = false;
                        }
                    },

                    async renderCurrentPage() {
                        if (!rawPdfDoc) return;
                        const canvas = this.$refs.pdfCanvas;
                        const dims = await window.PdfViewer.renderPage(rawPdfDoc, this.$wire.currentPage, canvas, this.scale);

                        this.$wire.updatePageDimensions(this.$wire.currentPage, dims.width, dims.height);
                    },

                    async prevPage() {
                        if (this.$wire.currentPage > 1) {
                            this.$wire.currentPage--;
                            await this.$nextTick();
                            await this.renderCurrentPage();
                        }
                    },

                    async nextPage() {
                        if (this.$wire.currentPage < this.totalPages) {
                            this.$wire.currentPage++;
                            await this.$nextTick();
                            await this.renderCurrentPage();
                        }
                    },
                };
            });

            // Draggable field overlay Alpine component
            Alpine.data('draggableField', (fieldId) => ({
                dragging: false,
                startX: 0,
                startY: 0,
                startLeft: 0,
                startTop: 0,

                startDrag(e) {
                    this.dragging = true;
                    const point = e.touches ? e.touches[0] : e;
                    this.startX = point.clientX;
                    this.startY = point.clientY;
                    this.startLeft = this.$el.offsetLeft;
                    this.startTop = this.$el.offsetTop;

                    const moveHandler = (ev) => this.onMove(ev);
                    const upHandler = () => {
                        this.dragging = false;
                        document.removeEventListener('mousemove', moveHandler);
                        document.removeEventListener('mouseup', upHandler);
                        document.removeEventListener('touchmove', moveHandler);
                        document.removeEventListener('touchend', upHandler);
                    };

                    document.addEventListener('mousemove', moveHandler);
                    document.addEventListener('mouseup', upHandler);
                    document.addEventListener('touchmove', moveHandler, { passive: false });
                    document.addEventListener('touchend', upHandler);
                },

                onMove(e) {
                    if (!this.dragging) return;
                    e.preventDefault();

                    const point = e.touches ? e.touches[0] : e;
                    const dx = point.clientX - this.startX;
                    const dy = point.clientY - this.startY;

                    const container = this.$el.parentElement;
                    const containerRect = container.getBoundingClientRect();

                    const newLeft = Math.max(0, Math.min(this.startLeft + dx, containerRect.width - this.$el.offsetWidth));
                    const newTop = Math.max(0, Math.min(this.startTop + dy, containerRect.height - this.$el.offsetHeight));

                    this.$el.style.left = (newLeft / containerRect.width * 100) + '%';
                    this.$el.style.top = (newTop / containerRect.height * 100) + '%';

                    // Debounced update to Livewire
                    clearTimeout(this._dragTimeout);
                    this._dragTimeout = setTimeout(() => {
                        const xPct = newLeft / containerRect.width * 100;
                        const yPct = newTop / containerRect.height * 100;
                        this.$wire.updateFieldPosition(fieldId, xPct, yPct);
                    }, 100);
                },
            }));
        </script>
        @endscript
    @endif
</div>
