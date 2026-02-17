<?php

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\DocumentUploadedForReview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.app')]
#[Title('My Documents')]
class extends Component {
    use WithFileUploads;

    public function boot(): void
    {
        if (! auth()->user()?->employeeProfile) {
            abort(403);
        }
    }

    public string $statusFilter = 'all';

    // Upload modal
    public bool $showUploadModal = false;
    public string $docName = '';
    public string $docType = 'other';
    public string $docNotes = '';
    public string $docExpiresAt = '';
    public $docFile = null;

    // Re-upload modal
    public bool $showReuploadModal = false;
    public ?int $reuploadDocumentId = null;
    public $reuploadFile = null;

    public function setFilter(string $filter): void
    {
        $this->statusFilter = $filter;
    }

    public function openUploadModal(): void
    {
        $this->docName = '';
        $this->docType = 'other';
        $this->docNotes = '';
        $this->docExpiresAt = '';
        $this->docFile = null;
        $this->showUploadModal = true;
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'docName' => 'required|string|max:255',
            'docType' => 'required|in:' . implode(',', array_keys(EmployeeDocument::TYPES)),
            'docFile' => 'required|file|max:10240',
            'docNotes' => 'nullable|string|max:1000',
            'docExpiresAt' => 'nullable|date',
        ]);

        $path = $this->docFile->store('employee-documents', config('filesystems.default'));

        $document = EmployeeDocument::create([
            'employee_profile_id' => auth()->user()->employeeProfile->id,
            'uploaded_by' => auth()->id(),
            'name' => $this->docName,
            'type' => $this->docType,
            'file_path' => $path,
            'status' => 'pending',
            'notes' => $this->docNotes ?: null,
            'expires_at' => $this->docExpiresAt ?: null,
        ]);

        $document->load('employeeProfile.user');

        // Notify admins
        $admins = User::query()
            ->where('type', 'internal')
            ->where('is_active', true)
            ->permission('employees.edit')
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new DocumentUploadedForReview($document));
        }

        $this->showUploadModal = false;
        unset($this->documents);
        session()->flash('success', __('Document uploaded successfully. It will be reviewed shortly.'));
    }

    public function openReuploadModal(int $documentId): void
    {
        $document = EmployeeDocument::where('employee_profile_id', auth()->user()->employeeProfile->id)
            ->where('status', 'rejected')
            ->findOrFail($documentId);

        $this->reuploadDocumentId = $document->id;
        $this->reuploadFile = null;
        $this->showReuploadModal = true;
    }

    public function reuploadDocument(): void
    {
        $this->validate([
            'reuploadFile' => 'required|file|max:10240',
        ]);

        $document = EmployeeDocument::where('employee_profile_id', auth()->user()->employeeProfile->id)
            ->where('status', 'rejected')
            ->findOrFail($this->reuploadDocumentId);

        // Delete old file
        \Illuminate\Support\Facades\Storage::delete($document->file_path);

        $path = $this->reuploadFile->store('employee-documents', config('filesystems.default'));

        $document->update([
            'file_path' => $path,
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ]);

        $document->load('employeeProfile.user');

        // Notify admins
        $admins = User::query()
            ->where('type', 'internal')
            ->where('is_active', true)
            ->permission('employees.edit')
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new DocumentUploadedForReview($document));
        }

        $this->showReuploadModal = false;
        $this->reuploadDocumentId = null;
        unset($this->documents);
        session()->flash('success', __('Document re-uploaded successfully. It will be reviewed shortly.'));
    }

    public function downloadDocument(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $document = EmployeeDocument::where('employee_profile_id', auth()->user()->employeeProfile->id)
            ->findOrFail($id);

        return \Illuminate\Support\Facades\Storage::download(
            $document->file_path,
            $document->name . '.' . pathinfo($document->file_path, PATHINFO_EXTENSION)
        );
    }

    #[Computed]
    public function documents()
    {
        $query = EmployeeDocument::query()
            ->where('employee_profile_id', auth()->user()->employeeProfile->id)
            ->with(['uploader', 'reviewer'])
            ->latest();

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return $query->get();
    }

    #[Computed]
    public function statusCounts(): array
    {
        $profileId = auth()->user()->employeeProfile->id;

        return [
            'all' => EmployeeDocument::where('employee_profile_id', $profileId)->count(),
            'pending' => EmployeeDocument::where('employee_profile_id', $profileId)->pending()->count(),
            'approved' => EmployeeDocument::where('employee_profile_id', $profileId)->approved()->count(),
            'rejected' => EmployeeDocument::where('employee_profile_id', $profileId)->rejected()->count(),
        ];
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'amber',
            'approved' => 'green',
            'rejected' => 'red',
            default => 'zinc',
        };
    }
};
?>

<div>
    <div class="mx-auto max-w-4xl">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('My Documents') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                    {{ __('Upload and manage your employment documents.') }}
                </flux:text>
            </div>
            <flux:button wire:click="openUploadModal" icon="arrow-up-tray" variant="primary">
                {{ __('Upload Document') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Status Filter Tabs --}}
        <div class="mb-6 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex space-x-6">
                @foreach (['all' => __('All'), 'pending' => __('Pending'), 'approved' => __('Approved'), 'rejected' => __('Rejected')] as $filter => $label)
                    <button
                        wire:click="setFilter('{{ $filter }}')"
                        class="border-b-2 px-1 py-3 text-sm font-medium {{ $statusFilter === $filter ? 'border-zinc-800 text-zinc-900 dark:border-zinc-200 dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                    >
                        {{ $label }}
                        @if ($this->statusCounts[$filter] > 0)
                            <span class="ml-1.5 rounded-full bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-700">{{ $this->statusCounts[$filter] }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Documents List --}}
        @if ($this->documents->isEmpty())
            <div class="rounded-lg border border-zinc-200 p-12 text-center dark:border-zinc-700">
                <flux:icon name="folder-open" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No Documents') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($statusFilter === 'all')
                        {{ __('You haven\'t uploaded any documents yet.') }}
                    @else
                        {{ __('No documents with this status.') }}
                    @endif
                </flux:text>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->documents as $document)
                    <div wire:key="doc-{{ $document->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $document->name }}</flux:text>
                                    <flux:badge size="sm" color="{{ match($document->type) { 'w9' => 'blue', 'i9' => 'blue', 'direct_deposit' => 'green', 'insurance' => 'amber', 'license' => 'purple', 'certification' => 'cyan', default => 'zinc' } }}">
                                        {{ $document->getTypeLabel() }}
                                    </flux:badge>
                                    <flux:badge size="sm" color="{{ $this->statusColor($document->status) }}">
                                        {{ $document->getStatusLabel() }}
                                    </flux:badge>
                                </div>

                                <div class="mt-1 flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>{{ __('Uploaded') }} {{ $document->created_at->format('M j, Y') }}</span>
                                    @if ($document->expires_at)
                                        <span class="{{ $document->isExpired() ? 'text-red-600 dark:text-red-400 font-medium' : ($document->isExpiringSoon() ? 'text-amber-600 dark:text-amber-400' : '') }}">
                                            {{ __('Expires') }} {{ $document->expires_at->format('M j, Y') }}
                                            @if ($document->isExpired())
                                                ({{ __('Expired') }})
                                            @elseif ($document->isExpiringSoon())
                                                ({{ __('Soon') }})
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                @if ($document->notes)
                                    <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $document->notes }}</flux:text>
                                @endif

                                {{-- Review info --}}
                                @if ($document->isRejected() && $document->review_notes)
                                    <div class="mt-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                                        <span class="font-medium">{{ __('Rejection reason:') }}</span> {{ $document->review_notes }}
                                    </div>
                                @endif

                                @if ($document->isApproved() && $document->reviewer)
                                    <flux:text class="mt-1 text-xs text-green-600 dark:text-green-400">
                                        {{ __('Approved by') }} {{ $document->reviewer->name }} {{ __('on') }} {{ $document->reviewed_at->format('M j, Y') }}
                                    </flux:text>
                                @endif
                            </div>

                            <div class="flex items-center gap-1">
                                <flux:button wire:click="downloadDocument({{ $document->id }})" size="xs" variant="ghost" icon="arrow-down-tray">
                                    {{ __('Download') }}
                                </flux:button>
                                @if ($document->isRejected())
                                    <flux:button wire:click="openReuploadModal({{ $document->id }})" size="xs" variant="primary" icon="arrow-path">
                                        {{ __('Re-upload') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Upload Document Modal --}}
    <flux:modal wire:model="showUploadModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Upload Document') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Document Name') }}</flux:label>
                <flux:input wire:model="docName" placeholder="{{ __('e.g. W-9 Form 2026') }}" />
                <flux:error name="docName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Document Type') }}</flux:label>
                <flux:select wire:model="docType">
                    @foreach (\App\Models\EmployeeDocument::TYPES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="docType" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>
                <input type="file" wire:model="docFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300" />
                <flux:description>{{ __('Max 10MB. PDF, images, and common document formats accepted.') }}</flux:description>
                <flux:error name="docFile" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Expiration Date') }}</flux:label>
                <flux:input wire:model="docExpiresAt" type="date" />
                <flux:description>{{ __('Optional. Set if the document has an expiry date (e.g. licenses, certifications).') }}</flux:description>
                <flux:error name="docExpiresAt" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="docNotes" rows="2" placeholder="{{ __('Optional notes about this document...') }}" />
                <flux:error name="docNotes" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showUploadModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="uploadDocument" variant="primary">
                    <div wire:loading.remove wire:target="uploadDocument">{{ __('Upload') }}</div>
                    <div wire:loading wire:target="uploadDocument">{{ __('Uploading...') }}</div>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Re-upload Modal --}}
    <flux:modal wire:model="showReuploadModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Re-upload Document') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __('Upload a corrected version of the rejected document.') }}
            </flux:text>

            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>
                <input type="file" wire:model="reuploadFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300" />
                <flux:description>{{ __('Max 10MB. PDF, images, and common document formats accepted.') }}</flux:description>
                <flux:error name="reuploadFile" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showReuploadModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="reuploadDocument" variant="primary">
                    <div wire:loading.remove wire:target="reuploadDocument">{{ __('Upload') }}</div>
                    <div wire:loading wire:target="reuploadDocument">{{ __('Uploading...') }}</div>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
