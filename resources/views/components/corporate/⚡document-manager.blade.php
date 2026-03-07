<?php

use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Services\CorporateDocumentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $corporationId;
    public string $filterType = '';
    public bool $showUploadForm = false;
    public bool $showTemplateForm = false;
    public string $templateType = '';
    public bool $showEditor = false;
    public ?int $editingDocumentId = null;
    public array $expandedVersions = [];

    // Upload form
    public string $uploadTitle = '';
    public string $uploadType = 'operating_agreement';
    public $uploadFile = null;

    // Template form - Board Resolution
    public string $tplDirectorName = '';
    public string $tplResolutionDate = '';
    public string $tplShareholderName = '';
    public string $tplShareholderEntityType = '';
    public string $tplShareholderEin = '';
    public string $tplShareClass = 'Common';
    public int $tplShareCount = 0;
    public string $tplConsideration = '';

    // Template form - Stock Ledger
    public string $tplDateIssued = '';
    public string $tplLedgerShareholderName = '';
    public string $tplShareholderAddress = '';
    public string $tplLedgerShareClass = 'Common';
    public int $tplSharesIssued = 0;
    public string $tplLedgerConsideration = '';
    public int $tplAuthorizedShares = 0;

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function corporation(): Corporation
    {
        return Corporation::findOrFail($this->corporationId);
    }

    #[Computed]
    public function documents(): \Illuminate\Database\Eloquent\Collection
    {
        $query = CorporateDocument::where('corporation_id', $this->corporationId)
            ->whereNull('parent_document_id')
            ->withCount('children')
            ->latest();

        if ($this->filterType) {
            $query->where('type', $this->filterType);
        }

        return $query->get();
    }

    public function toggleUploadForm(): void
    {
        $this->showUploadForm = ! $this->showUploadForm;
        $this->resetUploadForm();
    }

    public function resetUploadForm(): void
    {
        $this->uploadTitle = '';
        $this->uploadType = 'operating_agreement';
        $this->uploadFile = null;
        $this->resetErrorBag();
    }

    public function saveDocument(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        $this->validate([
            'uploadTitle' => ['required', 'string', 'max:255'],
            'uploadType' => ['required', 'string', 'in:' . implode(',', array_keys(CorporateDocument::TYPES))],
            'uploadFile' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        try {
            app(CorporateDocumentService::class)->uploadDocument(
                $this->corporation,
                $this->uploadFile,
                $this->uploadTitle,
                $this->uploadType,
                Auth::user(),
            );
        } catch (\Throwable $e) {
            $this->addError('uploadFile', __('Upload failed: :message', ['message' => $e->getMessage()]));

            return;
        }

        $this->showUploadForm = false;
        $this->resetUploadForm();
        unset($this->documents);
    }

    public function showTemplate(string $type): void
    {
        $this->showUploadForm = false;
        $this->showTemplateForm = true;
        $this->templateType = $type;
        $this->resetTemplateForm();
    }

    public function resetTemplateForm(): void
    {
        $this->tplDirectorName = '';
        $this->tplResolutionDate = '';
        $this->tplShareholderName = '';
        $this->tplShareholderEntityType = '';
        $this->tplShareholderEin = '';
        $this->tplShareClass = 'Common';
        $this->tplShareCount = 0;
        $this->tplConsideration = '';
        $this->tplDateIssued = '';
        $this->tplLedgerShareholderName = '';
        $this->tplShareholderAddress = '';
        $this->tplLedgerShareClass = 'Common';
        $this->tplSharesIssued = 0;
        $this->tplLedgerConsideration = '';
        $this->tplAuthorizedShares = 0;
        $this->resetErrorBag();
    }

    public function cancelTemplate(): void
    {
        $this->showTemplateForm = false;
        $this->templateType = '';
        $this->resetTemplateForm();
    }

    public function generateFromTemplate(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        if ($this->templateType === 'board_resolution') {
            $this->validate([
                'tplDirectorName' => ['required', 'string', 'max:255'],
                'tplResolutionDate' => ['required', 'string', 'max:255'],
                'tplShareholderName' => ['required', 'string', 'max:255'],
                'tplShareClass' => ['required', 'string', 'max:50'],
                'tplShareCount' => ['required', 'integer', 'min:1'],
                'tplConsideration' => ['required', 'string', 'max:500'],
            ]);

            $templateData = [
                'corporation_name' => $this->corporation->name,
                'state' => $this->corporation->state_of_formation ?? 'Colorado',
                'director_name' => $this->tplDirectorName,
                'resolution_date' => $this->tplResolutionDate,
                'shareholder_name' => $this->tplShareholderName,
                'shareholder_entity_type' => $this->tplShareholderEntityType,
                'shareholder_ein' => $this->tplShareholderEin,
                'share_class' => $this->tplShareClass,
                'share_count' => $this->tplShareCount,
                'consideration' => $this->tplConsideration,
                'certificated' => false,
            ];

            $title = 'Board Resolution - Issue Shares';
        } elseif ($this->templateType === 'stock_ledger') {
            $this->validate([
                'tplDateIssued' => ['required', 'string', 'max:255'],
                'tplLedgerShareholderName' => ['required', 'string', 'max:255'],
                'tplLedgerShareClass' => ['required', 'string', 'max:50'],
                'tplSharesIssued' => ['required', 'integer', 'min:1'],
                'tplLedgerConsideration' => ['required', 'string', 'max:500'],
                'tplAuthorizedShares' => ['required', 'integer', 'min:1'],
            ]);

            $templateData = [
                'corporation_name' => $this->corporation->name,
                'authorized_shares' => [
                    ['class' => $this->tplLedgerShareClass, 'authorized' => $this->tplAuthorizedShares],
                ],
                'entries' => [[
                    'date' => $this->tplDateIssued,
                    'shareholder' => $this->tplLedgerShareholderName,
                    'address' => $this->tplShareholderAddress,
                    'class' => $this->tplLedgerShareClass,
                    'shares' => $this->tplSharesIssued,
                    'certificate_number' => 'Uncertificated',
                    'consideration' => $this->tplLedgerConsideration,
                ]],
            ];

            $title = 'Stock Ledger';
        } else {
            return;
        }

        try {
            app(CorporateDocumentService::class)->createFromTemplate(
                $this->corporation,
                $this->templateType,
                $title,
                $templateData,
                Auth::user(),
            );
        } catch (\Throwable $e) {
            $this->addError('templateType', __('Generation failed: :message', ['message' => $e->getMessage()]));

            return;
        }

        $this->showTemplateForm = false;
        $this->templateType = '';
        $this->resetTemplateForm();
        unset($this->documents);
    }

    public function openEditor(int $documentId): void
    {
        $this->editingDocumentId = $documentId;
        $this->showEditor = true;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->editingDocumentId = null;
        unset($this->documents);
    }

    public function deleteDocument(int $id): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        CorporateDocument::where('id', $id)
            ->where('corporation_id', $this->corporationId)
            ->delete();

        unset($this->documents);
    }

    public function toggleVersions(int $documentId): void
    {
        if (in_array($documentId, $this->expandedVersions)) {
            $this->expandedVersions = array_values(array_diff($this->expandedVersions, [$documentId]));
        } else {
            $this->expandedVersions[] = $documentId;
        }
    }

    public function getVersions(int $documentId): \Illuminate\Database\Eloquent\Collection
    {
        return CorporateDocument::where('parent_document_id', $documentId)
            ->orderByDesc('version')
            ->get();
    }

    public function getLatestVersion(CorporateDocument $doc): CorporateDocument
    {
        return CorporateDocument::where('parent_document_id', $doc->id)
            ->orderByDesc('version')
            ->first() ?? $doc;
    }
};
?>

<div>
    @if ($showEditor && $editingDocumentId)
        <div class="mb-4">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="closeEditor">
                {{ __('Back to Documents') }}
            </flux:button>
        </div>
        <livewire:dynamic-component
            :component="'corporate.document-editor'"
            :document-id="$editingDocumentId"
            :key="'doc-editor-' . $editingDocumentId"
        />
    @else
        {{-- Header --}}
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('Documents') }}</flux:heading>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="filterType" size="sm" class="w-48">
                    <flux:select.option value="">{{ __('All Types') }}</flux:select.option>
                    @foreach (CorporateDocument::TYPES as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->canManage)
                    @if ($showUploadForm || $showTemplateForm)
                        <flux:button variant="ghost" size="sm" wire:click="{{ $showUploadForm ? 'toggleUploadForm' : 'cancelTemplate' }}" icon="x-mark">
                            {{ __('Cancel') }}
                        </flux:button>
                    @else
                        <flux:dropdown>
                            <flux:button variant="primary" size="sm" icon="plus">
                                {{ __('Add Document') }}
                            </flux:button>
                            <flux:menu>
                                <flux:menu.item wire:click="toggleUploadForm" icon="arrow-up-tray">
                                    {{ __('Upload PDF') }}
                                </flux:menu.item>
                                <flux:separator />
                                @foreach (CorporateDocument::TEMPLATE_TYPES as $key => $label)
                                    <flux:menu.item wire:click="showTemplate('{{ $key }}')" icon="document-text">
                                        {{ __('Generate: :type', ['type' => $label]) }}
                                    </flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                @endif
            </div>
        </div>

        {{-- Inline Upload Form --}}
        @if ($showUploadForm)
            <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-5 dark:border-blue-800 dark:bg-blue-900/20">
                <flux:heading size="sm" class="mb-4">{{ __('Upload Document') }}</flux:heading>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Title') }}</flux:label>
                        <flux:input wire:model="uploadTitle" placeholder="Operating Agreement - 2024" />
                        <flux:error name="uploadTitle" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Document Type') }}</flux:label>
                        <flux:select wire:model="uploadType">
                            @foreach (CorporateDocument::TYPES as $key => $label)
                                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="uploadType" />
                    </flux:field>
                </div>

                <div class="mt-4">
                    <flux:field>
                        <flux:label>{{ __('PDF File') }}</flux:label>
                        <input type="file" wire:model="uploadFile" accept=".pdf" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100 dark:text-zinc-400 dark:file:bg-blue-900/30 dark:file:text-blue-400" />
                        <flux:error name="uploadFile" />
                    </flux:field>
                </div>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <div wire:loading wire:target="uploadFile" class="text-sm text-blue-600 dark:text-blue-400">
                        {{ __('Uploading file...') }}
                    </div>
                    <flux:button variant="ghost" size="sm" wire:click="toggleUploadForm">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" size="sm" wire:click="saveDocument" wire:loading.attr="disabled" wire:target="saveDocument">
                        <span wire:loading.remove wire:target="saveDocument">{{ __('Save Document') }}</span>
                        <span wire:loading wire:target="saveDocument">{{ __('Saving...') }}</span>
                    </flux:button>
                </div>
            </div>
        @endif

        {{-- Template Form --}}
        @if ($showTemplateForm)
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50/50 p-5 dark:border-green-800 dark:bg-green-900/20">
                <flux:heading size="sm" class="mb-4">
                    {{ __('Generate: :type', ['type' => CorporateDocument::TEMPLATE_TYPES[$templateType] ?? $templateType]) }}
                </flux:heading>
                <flux:error name="templateType" />

                @if ($templateType === 'board_resolution')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Director Name') }}</flux:label>
                            <flux:input wire:model="tplDirectorName" placeholder="Matt Stanford" />
                            <flux:error name="tplDirectorName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Resolution Date') }}</flux:label>
                            <flux:input wire:model="tplResolutionDate" placeholder="March 3, 2026" />
                            <flux:error name="tplResolutionDate" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Shareholder Name') }}</flux:label>
                            <flux:input wire:model="tplShareholderName" placeholder="Quick Technologies" />
                            <flux:error name="tplShareholderName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Entity Type (optional)') }}</flux:label>
                            <flux:input wire:model="tplShareholderEntityType" placeholder="Wyoming C-Corp" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Shareholder EIN (optional)') }}</flux:label>
                            <flux:input wire:model="tplShareholderEin" placeholder="39-3345899" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Share Class') }}</flux:label>
                            <flux:input wire:model="tplShareClass" placeholder="Common" />
                            <flux:error name="tplShareClass" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Number of Shares') }}</flux:label>
                            <flux:input wire:model="tplShareCount" type="number" placeholder="2000000" />
                            <flux:error name="tplShareCount" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Consideration') }}</flux:label>
                            <flux:input wire:model="tplConsideration" placeholder="$2,000 cash" />
                            <flux:error name="tplConsideration" />
                        </flux:field>
                    </div>
                @elseif ($templateType === 'stock_ledger')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Authorized Shares') }}</flux:label>
                            <flux:input wire:model="tplAuthorizedShares" type="number" placeholder="5000000" />
                            <flux:error name="tplAuthorizedShares" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Share Class') }}</flux:label>
                            <flux:input wire:model="tplLedgerShareClass" placeholder="Common" />
                            <flux:error name="tplLedgerShareClass" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Date Issued') }}</flux:label>
                            <flux:input wire:model="tplDateIssued" placeholder="March 3, 2026" />
                            <flux:error name="tplDateIssued" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Shareholder Name') }}</flux:label>
                            <flux:input wire:model="tplLedgerShareholderName" placeholder="Quick Technologies" />
                            <flux:error name="tplLedgerShareholderName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Shareholder Address (optional)') }}</flux:label>
                            <flux:input wire:model="tplShareholderAddress" placeholder="1500 N Grant St, Denver, CO" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Shares Issued') }}</flux:label>
                            <flux:input wire:model="tplSharesIssued" type="number" placeholder="2000000" />
                            <flux:error name="tplSharesIssued" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Consideration') }}</flux:label>
                            <flux:input wire:model="tplLedgerConsideration" placeholder="$2,000 cash" />
                            <flux:error name="tplLedgerConsideration" />
                        </flux:field>
                    </div>
                @endif

                <div class="mt-4 flex items-center justify-end gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="cancelTemplate">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" size="sm" wire:click="generateFromTemplate" wire:loading.attr="disabled" wire:target="generateFromTemplate">
                        <span wire:loading.remove wire:target="generateFromTemplate">{{ __('Generate Document') }}</span>
                        <span wire:loading wire:target="generateFromTemplate">{{ __('Generating...') }}</span>
                    </flux:button>
                </div>
            </div>
        @endif

        {{-- Document List --}}
        @if ($this->documents->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon name="document-text" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="sm" class="mt-3">{{ __('No documents yet') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Upload a PDF to get started.') }}</flux:text>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->documents as $doc)
                    @php
                        $latest = $this->getLatestVersion($doc);
                        $hasVersions = $doc->children_count > 0;
                        $isExpanded = in_array($doc->id, $expandedVersions);
                    @endphp
                    <div wire:key="doc-{{ $doc->id }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        {{-- Main document row --}}
                        <div class="flex items-start justify-between p-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $doc->title }}</flux:text>
                                    <flux:badge size="sm" color="blue">{{ CorporateDocument::TYPES[$doc->type] ?? $doc->type }}</flux:badge>
                                    <flux:badge size="sm" variant="outline">v{{ $latest->version }}</flux:badge>
                                    @if ($doc->source === 'template')
                                        <flux:badge size="sm" color="green">{{ __('Generated') }}</flux:badge>
                                    @endif
                                    @if ($latest->status === 'processing')
                                        <flux:badge size="sm" color="amber">
                                            <span class="flex items-center gap-1">
                                                <span class="size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                                                {{ __('Processing') }}
                                            </span>
                                        </flux:badge>
                                    @elseif ($latest->status === 'error')
                                        <flux:badge size="sm" color="red">{{ __('Error') }}</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $doc->original_filename }}
                                    @if ($doc->file_size)
                                        &middot; {{ number_format($doc->file_size / 1024, 0) }} KB
                                    @endif
                                    @if ($doc->page_count)
                                        &middot; {{ $doc->page_count }} {{ __('pages') }}
                                    @endif
                                    &middot; {{ $doc->created_at->diffForHumans() }}
                                </flux:text>
                                @if ($latest->parse_error)
                                    <flux:text class="mt-1 text-xs text-red-500">{{ $latest->parse_error }}</flux:text>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                @if ($hasVersions)
                                    <flux:button size="xs" variant="ghost" wire:click="toggleVersions({{ $doc->id }})" icon="{{ $isExpanded ? 'chevron-up' : 'chevron-down' }}">
                                        {{ $doc->children_count + 1 }} {{ __('versions') }}
                                    </flux:button>
                                @endif
                                @if ($latest->status === 'ready')
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditor({{ $latest->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('corporate.document.pdf', $latest)">
                                        {{ __('PDF') }}
                                    </flux:button>
                                @endif
                                @if ($this->canManage)
                                    <flux:button size="xs" variant="ghost" icon="trash" class="text-red-500" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="{{ __('Delete this document and all versions?') }}" />
                                @endif
                            </div>
                        </div>

                        {{-- Version list (expandable) --}}
                        @if ($hasVersions && $isExpanded)
                            <div class="border-t border-zinc-100 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-800/30">
                                <flux:text class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Version History') }}</flux:text>

                                {{-- Original (v1) --}}
                                <div class="flex items-center justify-between rounded px-2 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" variant="outline">v{{ $doc->version }}</flux:badge>
                                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Original') }}</flux:text>
                                        <flux:text class="text-xs text-zinc-400">{{ $doc->created_at->format('M j, Y g:i A') }}</flux:text>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        @if ($doc->status === 'ready')
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditor({{ $doc->id }})">
                                                {{ __('View') }}
                                            </flux:button>
                                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('corporate.document.pdf', $doc)">
                                                {{ __('PDF') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>

                                {{-- Child versions --}}
                                @foreach ($this->getVersions($doc->id) as $version)
                                    <div wire:key="ver-{{ $version->id }}" class="flex items-center justify-between rounded px-2 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm" variant="outline">v{{ $version->version }}</flux:badge>
                                            @if ($version->edited_content)
                                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Edited') }}</flux:text>
                                            @endif
                                            <flux:text class="text-xs text-zinc-400">{{ $version->created_at->format('M j, Y g:i A') }}</flux:text>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditor({{ $version->id }})">
                                                {{ __('View') }}
                                            </flux:button>
                                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('corporate.document.pdf', $version)">
                                                {{ __('PDF') }}
                                            </flux:button>
                                            @if ($this->canManage)
                                                <flux:button size="xs" variant="ghost" icon="trash" class="text-red-500" wire:click="deleteDocument({{ $version->id }})" wire:confirm="{{ __('Delete this version?') }}" />
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
