<?php

use App\Models\EmployeeContract;
use App\Notifications\ContractSentForSigning;
use App\Services\ContractService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.app')]
#[Title('Contract Details')]
class extends Component
{
    use WithFileUploads;

    public EmployeeContract $employeeContract;

    public bool $showCountersignModal = false;

    public string $countersignData = '';

    public $documentUpload;

    // Employer signing (PDF contracts)
    public bool $showEmployerSigning = false;

    /** @var array<string, string> */
    public array $employerFieldValues = [];

    // Employer signing (rich text contracts)
    public bool $showRichTextEmployerSign = false;

    public string $richTextSignatureType = 'typed';

    public string $richTextTypedSignature = '';

    public string $richTextDrawnSignature = '';

    public function boot(): void
    {
        if (! auth()->user()?->can('employees.view')) {
            abort(403);
        }
    }

    public function mount(EmployeeContract $employeeContract): void
    {
        $this->employeeContract = $employeeContract->load(['employeeProfile.user', 'template', 'documents.uploadedBy']);
    }

    #[Computed]
    public function isPdfContract(): bool
    {
        $template = $this->employeeContract->template;

        return $template && $template->content_type === 'pdf' && $template->pdf_path;
    }

    #[Computed]
    public function employerFields(): array
    {
        if (! $this->isPdfContract) {
            return [];
        }

        $fields = $this->employeeContract->template->fields['fields'] ?? [];

        return array_values(array_filter($fields, fn ($f) => ($f['signer_role'] ?? 'employee') === 'employer'));
    }

    #[Computed]
    public function hasUnsignedEmployerFields(): bool
    {
        if (empty($this->employerFields)) {
            return false;
        }

        $fieldValues = $this->employeeContract->field_values ?? [];

        foreach ($this->employerFields as $field) {
            if (in_array($field['type'], ['signature', 'initials']) && empty($fieldValues[$field['id']] ?? '')) {
                return true;
            }
        }

        return false;
    }

    public function sendContract(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        // If PDF with unsigned employer fields, redirect to employer signing
        if ($this->isPdfContract && $this->hasUnsignedEmployerFields) {
            $this->openEmployerSigning();

            return;
        }

        // If rich text contract, require employer signature before sending
        if (! $this->isPdfContract && $this->employeeContract->template) {
            $this->showRichTextEmployerSign = true;

            return;
        }

        app(ContractService::class)->sendContract($this->employeeContract);
        $this->employeeContract->refresh();
        session()->flash('status', __('Contract sent successfully.'));
    }

    public function openEmployerSigning(): void
    {
        $this->employerFieldValues = $this->employeeContract->field_values ?? [];
        $this->showEmployerSigning = true;
    }

    public function signAsEmployerAndSend(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        // Validate all required employer signature/initials fields
        $missing = [];
        foreach ($this->employerFields as $field) {
            if (in_array($field['type'], ['signature', 'initials'])
                && ($field['required'] ?? false)
                && empty($this->employerFieldValues[$field['id']] ?? '')) {
                $missing[] = $field['label'];
            }
        }

        if (! empty($missing)) {
            $this->addError('employerFieldValues', __('Please sign all required fields: :fields', ['fields' => implode(', ', $missing)]));

            return;
        }

        $service = app(ContractService::class);

        // Get first employer signature value for countersign data
        $firstSig = collect($this->employerFields)
            ->first(fn ($f) => $f['type'] === 'signature' && ! empty($this->employerFieldValues[$f['id']] ?? ''));
        $sigData = $firstSig ? ($this->employerFieldValues[$firstSig['id']] ?? '') : auth()->user()->name;

        $service->saveEmployerFieldsAndSend(
            $this->employeeContract,
            $this->employerFieldValues,
            auth()->user(),
            $sigData,
        );

        $this->showEmployerSigning = false;
        $this->employeeContract->refresh();
        session()->flash('status', __('Contract signed and sent successfully.'));
    }

    public function signRichTextAndSend(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        if ($this->richTextSignatureType === 'typed') {
            $this->validate(['richTextTypedSignature' => 'required|string|max:255']);
            $sigData = $this->richTextTypedSignature;
        } else {
            $this->validate(['richTextDrawnSignature' => 'required|string']);
            $sigData = $this->richTextDrawnSignature;
        }

        $service = app(ContractService::class);
        $service->countersignAndSend($this->employeeContract, auth()->user(), $sigData);

        $this->showRichTextEmployerSign = false;
        $this->employeeContract->refresh();
        session()->flash('status', __('Contract signed and sent successfully.'));
    }

    public function resendContract(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        if (! in_array($this->employeeContract->status, ['sent', 'viewed'])) {
            return;
        }

        $this->employeeContract->load('employeeProfile.user');
        $this->employeeContract->employeeProfile->user->notify(
            new ContractSentForSigning($this->employeeContract)
        );
        session()->flash('status', __('Contract email resent.'));
    }

    public function countersign(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        $this->validate([
            'countersignData' => 'required|string|max:10000',
        ]);

        app(ContractService::class)->countersignContract(
            $this->employeeContract,
            auth()->user(),
            $this->countersignData,
        );

        $this->employeeContract->refresh();
        $this->showCountersignModal = false;
        $this->countersignData = '';
        session()->flash('status', __('Contract countersigned successfully.'));
    }

    public function voidContract(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        app(ContractService::class)->voidContract($this->employeeContract);
        $this->employeeContract->refresh();
        session()->flash('status', __('Contract voided.'));
    }

    public function uploadDocument(): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        $this->validate([
            'documentUpload' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
        ]);

        $path = $this->documentUpload->store('contract-documents', config('filesystems.default'));

        $this->employeeContract->documents()->create([
            'name' => $this->documentUpload->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $this->documentUpload->getSize(),
            'file_type' => $this->documentUpload->getMimeType(),
            'uploaded_by' => auth()->id(),
        ]);

        $this->reset('documentUpload');
        $this->employeeContract->load('documents.uploadedBy');
        session()->flash('status', __('Document uploaded.'));
    }

    public function deleteDocument(int $documentId): void
    {
        if (! auth()->user()?->can('employees.edit')) {
            abort(403);
        }

        $document = $this->employeeContract->documents()->findOrFail($documentId);

        \Illuminate\Support\Facades\Storage::delete($document->file_path);
        $document->delete();

        $this->employeeContract->load('documents.uploadedBy');
        session()->flash('status', __('Document deleted.'));
    }

    public function downloadDocument(int $documentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $document = $this->employeeContract->documents()->findOrFail($documentId);

        return \Illuminate\Support\Facades\Storage::download(
            $document->file_path,
            $document->name
        );
    }

    #[Computed]
    public function statusColor(): string
    {
        return match ($this->employeeContract->status) {
            'draft' => 'zinc',
            'sent' => 'blue',
            'viewed' => 'amber',
            'signed' => 'green',
            'voided' => 'red',
            default => 'zinc',
        };
    }

    #[Computed]
    public function statusLabel(): string
    {
        return match ($this->employeeContract->status) {
            'draft' => __('Draft'),
            'sent' => __('Sent'),
            'viewed' => __('Viewed'),
            'signed' => __('Signed'),
            'voided' => __('Voided'),
            default => ucfirst($this->employeeContract->status),
        };
    }

    #[Computed]
    public function signingUrl(): string
    {
        return route('contracts.sign', $this->employeeContract->token);
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" icon="arrow-left" :href="route('employees.show', $employeeContract->employee_profile_id)" wire:navigate />
            <div>
                <flux:heading size="xl">{{ $employeeContract->title }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $employeeContract->employeeProfile?->user?->name ?? __('Unknown Employee') }}
                </flux:text>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <flux:badge :color="$this->statusColor" size="lg">{{ $this->statusLabel }}</flux:badge>
        </div>
    </div>

    {{-- Metadata --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Template') }}</flux:text>
            <flux:text class="mt-1 font-semibold">{{ $employeeContract->template?->name ?? __('N/A') }}</flux:text>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Sent') }}</flux:text>
            <flux:text class="mt-1 font-semibold">{{ $employeeContract->sent_at?->format('M j, Y g:i A') ?? __('Not sent') }}</flux:text>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Viewed') }}</flux:text>
            <flux:text class="mt-1 font-semibold">{{ $employeeContract->viewed_at?->format('M j, Y g:i A') ?? __('Not viewed') }}</flux:text>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Signed') }}</flux:text>
            <flux:text class="mt-1 font-semibold">{{ $employeeContract->signed_at?->format('M j, Y g:i A') ?? __('Not signed') }}</flux:text>
        </div>
    </div>

    {{-- PDF Document --}}
    @if ($employeeContract->pdf_path)
        <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">
                    {{ $employeeContract->isSigned() ? __('Signed Document') : __('Uploaded Document') }}
                </flux:heading>
                @if ($employeeContract->isSigned())
                    <flux:button variant="primary" size="sm" icon="arrow-down-tray" :href="route('contracts.pdf', $employeeContract)">
                        {{ __('Download Signed PDF') }}
                    </flux:button>
                @endif
            </div>
            <iframe
                src="{{ route('contracts.document', $employeeContract) }}"
                class="h-[700px] w-full rounded-lg border border-zinc-200 dark:border-zinc-700"
                title="{{ $employeeContract->title }}"
            ></iframe>
        </div>
    @endif

    {{-- Contract Content --}}
    @if ($employeeContract->content)
        <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">{{ __('Contract Content') }}</flux:heading>
            <div class="prose max-w-none dark:prose-invert">
                {!! $employeeContract->content !!}
            </div>
        </div>
    @endif

    {{-- Signature Info --}}
    @if($employeeContract->isSigned())
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-6 dark:border-green-800 dark:bg-green-900/20">
            <flux:heading size="lg" class="mb-4 text-green-800 dark:text-green-200">{{ __('Signature') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <flux:text class="text-xs font-medium uppercase tracking-wide text-green-600 dark:text-green-400">{{ __('Signed By') }}</flux:text>
                    <flux:text class="mt-1 font-semibold text-green-800 dark:text-green-200">{{ $employeeContract->signer_name }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs font-medium uppercase tracking-wide text-green-600 dark:text-green-400">{{ __('Date') }}</flux:text>
                    <flux:text class="mt-1 font-semibold text-green-800 dark:text-green-200">{{ $employeeContract->signed_at->format('M j, Y g:i A') }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs font-medium uppercase tracking-wide text-green-600 dark:text-green-400">{{ __('Type') }}</flux:text>
                    <flux:text class="mt-1 font-semibold text-green-800 dark:text-green-200">{{ ucfirst($employeeContract->signature_type) }}</flux:text>
                </div>
            </div>
            {{-- Actual Signature Display --}}
            <div class="mt-4 rounded border border-green-200 bg-white p-4 dark:border-green-700 dark:bg-green-900/30">
                @if($employeeContract->signature_type === 'drawn')
                    <img src="{{ $employeeContract->signature_data }}" alt="{{ __('Employee Signature') }}" class="max-h-20" />
                @else
                    <p class="text-2xl italic text-green-800 dark:text-green-200" style="font-family: 'Brush Script MT', 'Georgia', cursive;">{{ $employeeContract->signature_data }}</p>
                @endif
            </div>
        </div>
    @endif

    {{-- Countersignature Info --}}
    @if($employeeContract->countersigned_at)
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20">
            <flux:heading size="lg" class="mb-4 text-blue-800 dark:text-blue-200">{{ __('Countersignature') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <flux:text class="text-xs font-medium uppercase tracking-wide text-blue-600 dark:text-blue-400">{{ __('Countersigned By') }}</flux:text>
                    <flux:text class="mt-1 font-semibold text-blue-800 dark:text-blue-200">{{ $employeeContract->countersignedBy?->name ?? __('Unknown') }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs font-medium uppercase tracking-wide text-blue-600 dark:text-blue-400">{{ __('Date') }}</flux:text>
                    <flux:text class="mt-1 font-semibold text-blue-800 dark:text-blue-200">{{ $employeeContract->countersigned_at->format('M j, Y g:i A') }}</flux:text>
                </div>
            </div>
            {{-- Countersignature Display --}}
            @if($employeeContract->countersign_data)
                <div class="mt-4 rounded border border-blue-200 bg-white p-4 dark:border-blue-700 dark:bg-blue-900/30">
                    @if(str_starts_with($employeeContract->countersign_data, 'data:'))
                        <img src="{{ $employeeContract->countersign_data }}" alt="{{ __('Employer Signature') }}" class="max-h-20" />
                    @else
                        <p class="text-2xl italic text-blue-800 dark:text-blue-200" style="font-family: 'Brush Script MT', 'Georgia', cursive;">{{ $employeeContract->countersign_data }}</p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Documents Section --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-4">{{ __('Documents') }}</flux:heading>

        @if($employeeContract->documents->isNotEmpty())
            <div class="mb-4 divide-y divide-zinc-200 rounded border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach($employeeContract->documents as $document)
                    <div wire:key="doc-{{ $document->id }}" class="flex items-center justify-between p-3">
                        <div class="flex items-center gap-3">
                            <flux:icon name="document" class="size-5 text-zinc-400" />
                            <div>
                                <flux:text class="font-medium">{{ $document->name }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $document->human_file_size }} &middot; {{ $document->uploadedBy?->name ?? __('Unknown') }} &middot; {{ $document->created_at->format('M j, Y') }}
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" icon="arrow-down-tray" wire:click="downloadDocument({{ $document->id }})" />
                            @can('employees.edit')
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="deleteDocument({{ $document->id }})" wire:confirm="{{ __('Delete this document?') }}" class="text-red-500 hover:text-red-700" />
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <flux:text class="mb-4 text-zinc-500 dark:text-zinc-400">{{ __('No documents attached.') }}</flux:text>
        @endif

        @can('employees.edit')
            <form wire:submit="uploadDocument" class="flex items-end gap-3">
                <div class="grow">
                    <flux:field>
                        <flux:label>{{ __('Upload Document') }}</flux:label>
                        <input type="file" wire:model="documentUpload" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600" />
                        <flux:error name="documentUpload" />
                    </flux:field>
                </div>
                <flux:button type="submit" variant="primary" size="sm" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="documentUpload">
                    {{ __('Upload') }}
                </flux:button>
            </form>
        @endcan
    </div>

    {{-- Action Buttons (Admin Only) --}}
    @can('employees.edit')
        <div class="flex flex-wrap gap-2">
            @if($employeeContract->status === 'draft')
                @if ($this->isPdfContract && $this->hasUnsignedEmployerFields)
                    <flux:button variant="primary" icon="pencil-square" wire:click="openEmployerSigning">
                        {{ __('Sign & Send Contract') }}
                    </flux:button>
                @else
                    <flux:button variant="primary" icon="paper-airplane" wire:click="sendContract">
                        {{ __('Send Contract') }}
                    </flux:button>
                @endif
            @endif

            @if(in_array($employeeContract->status, ['sent', 'viewed']))
                <flux:button variant="subtle" icon="paper-airplane" wire:click="resendContract">
                    {{ __('Resend Email') }}
                </flux:button>
                <flux:button
                    variant="subtle"
                    icon="link"
                    x-data
                    x-on:click="navigator.clipboard.writeText('{{ $this->signingUrl }}').then(() => $flux.toast('{{ __('Signing link copied!') }}'))"
                >
                    {{ __('Copy Signing Link') }}
                </flux:button>
            @endif

            @if($employeeContract->isSigned() && !$employeeContract->countersigned_at)
                <flux:button variant="primary" icon="pencil-square" wire:click="$set('showCountersignModal', true)">
                    {{ __('Countersign') }}
                </flux:button>
            @endif

            @if(!in_array($employeeContract->status, ['voided', 'signed']))
                <flux:button variant="danger" icon="x-circle" wire:click="voidContract" wire:confirm="{{ __('Are you sure you want to void this contract?') }}">
                    {{ __('Void Contract') }}
                </flux:button>
            @endif

            <flux:button variant="subtle" icon="arrow-down-tray" :href="route('contracts.pdf', $employeeContract)">
                {{ __('Download PDF') }}
            </flux:button>
        </div>
    @endcan

    {{-- Countersign Modal (post-signing) --}}
    <flux:modal wire:model="showCountersignModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Countersign Contract') }}</flux:heading>
            <flux:text>{{ __('Type your name to countersign this contract as the company representative.') }}</flux:text>
            <flux:input wire:model="countersignData" :label="__('Your Name / Signature')" :placeholder="auth()->user()?->name" />
            <div class="flex justify-end gap-2">
                <flux:button variant="subtle" wire:click="$set('showCountersignModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="countersign">{{ __('Countersign') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Employer PDF Signing Modal --}}
    <flux:modal wire:model="showEmployerSigning" class="!max-w-4xl" variant="flyout">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Sign as Employer') }}</flux:heading>
            <flux:text>{{ __('Sign your fields before sending this contract to the employee.') }}</flux:text>

            @error('employerFieldValues')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            @if ($this->isPdfContract && $showEmployerSigning)
                <div x-data="employerPdfSigner({
                    pdfUrl: '{{ route('contracts.document', $employeeContract) }}',
                    fields: @js($employeeContract->template->fields['fields'] ?? []),
                    fieldValues: @entangle('employerFieldValues'),
                })">
                    {{-- PDF Canvas with field overlays --}}
                    <div class="relative mx-auto overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900" x-ref="pdfContainer">
                        <canvas x-ref="pdfCanvas" wire:ignore class="block w-full"></canvas>

                        {{-- Field overlays --}}
                        <template x-for="field in currentPageFields" :key="field.id">
                            <div
                                class="absolute"
                                :style="`left: ${field.x_pct}%; top: ${field.y_pct}%; width: ${field.width_pct}%; height: ${field.height_pct}%;`"
                            >
                                {{-- Employer signature/initials fields - interactive --}}
                                <template x-if="(field.signer_role || 'employee') === 'employer' && (field.type === 'signature' || field.type === 'initials')">
                                    <div
                                        class="flex h-full w-full cursor-pointer items-center justify-center overflow-hidden border-2 border-dashed transition"
                                        :class="fieldValues[field.id]
                                            ? 'border-green-400 bg-green-50/80 dark:border-green-600 dark:bg-green-900/30'
                                            : 'border-blue-400 bg-blue-50/80 hover:bg-blue-100 dark:border-blue-600 dark:bg-blue-900/30'"
                                        x-on:click="openCapture(field.id, field.type)"
                                    >
                                        <template x-if="!fieldValues[field.id]">
                                            <span class="text-center text-xs text-blue-600 dark:text-blue-300" x-text="field.type === 'initials' ? '{{ __('Click to add initials') }}' : '{{ __('Click to sign') }}'"></span>
                                        </template>
                                        <template x-if="fieldValues[field.id] && fieldValues[field.id].startsWith('data:')">
                                            <img :src="fieldValues[field.id]" class="max-h-full max-w-full object-contain" alt="Signature" />
                                        </template>
                                        <template x-if="fieldValues[field.id] && !fieldValues[field.id].startsWith('data:')">
                                            <span class="text-sm italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;" x-text="fieldValues[field.id]"></span>
                                        </template>
                                    </div>
                                </template>

                                {{-- Employer text/date fields - editable --}}
                                <template x-if="(field.signer_role || 'employee') === 'employer' && field.type === 'text'">
                                    <input
                                        type="text"
                                        class="h-full w-full border border-blue-300 bg-blue-50/90 px-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-blue-600 dark:bg-blue-900/50"
                                        :style="`font-size: ${Math.max(10, field.font_size || 12)}px;`"
                                        :value="fieldValues[field.id] || ''"
                                        x-on:input="fieldValues[field.id] = $event.target.value; $wire.set('employerFieldValues.' + field.id, $event.target.value)"
                                        :placeholder="field.label"
                                    />
                                </template>

                                <template x-if="(field.signer_role || 'employee') === 'employer' && field.type === 'date'">
                                    <input
                                        type="date"
                                        class="h-full w-full border border-amber-300 bg-amber-50/90 px-1 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-amber-600 dark:bg-amber-900/50"
                                        :style="`font-size: ${Math.max(10, field.font_size || 12)}px;`"
                                        :value="fieldValues[field.id] || ''"
                                        x-on:change="fieldValues[field.id] = $event.target.value; $wire.set('employerFieldValues.' + field.id, $event.target.value)"
                                    />
                                </template>

                                {{-- Employee fields - gray placeholder --}}
                                <template x-if="(field.signer_role || 'employee') === 'employee'">
                                    <div class="flex h-full w-full items-center justify-center border border-dashed border-zinc-300 bg-zinc-100/80 dark:border-zinc-600 dark:bg-zinc-800/50">
                                        <span class="text-center text-xs text-zinc-400 dark:text-zinc-500">{{ __('Employee will fill') }}</span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Inline Signature Capture Modal --}}
                        <div
                            x-show="captureFieldId !== null"
                            x-transition.opacity
                            class="absolute inset-0 z-10 flex items-center justify-center bg-black/40"
                            x-on:click.self="cancelCapture()"
                        >
                            <div class="mx-4 w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" x-on:click.stop>
                                <div class="mb-4 flex items-center justify-between">
                                    <flux:heading size="sm">
                                        <span x-text="captureFieldType === 'initials' ? '{{ __('Add Initials') }}' : '{{ __('Add Signature') }}'"></span>
                                    </flux:heading>
                                    <button type="button" x-on:click="cancelCapture()" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>

                                <div class="mb-4 flex gap-2">
                                    <button type="button" class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="captureType === 'typed' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                        x-on:click="captureType = 'typed'">{{ __('Type') }}</button>
                                    <button type="button" class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="captureType === 'drawn' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                        x-on:click="captureType = 'drawn'; $nextTick(() => initCaptureCanvas())">{{ __('Draw') }}</button>
                                </div>

                                <div x-show="captureType === 'typed'" class="mb-4">
                                    <input type="text" x-model="captureTypedValue"
                                        class="w-full rounded-md border border-zinc-300 px-3 py-2 text-lg dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                        :placeholder="captureFieldType === 'initials' ? '{{ __('Type your initials') }}' : '{{ __('Type your full name') }}'"
                                        x-on:keydown.enter="confirmCapture()" />
                                    <div x-show="captureTypedValue" class="mt-3 rounded border border-zinc-200 bg-zinc-50 p-3 text-center dark:border-zinc-700 dark:bg-zinc-800">
                                        <p class="text-2xl italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;" x-text="captureTypedValue"></p>
                                    </div>
                                </div>

                                <div x-show="captureType === 'drawn'" class="mb-4">
                                    <div class="overflow-hidden rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                                        <canvas x-ref="captureCanvas" width="400" height="150" class="w-full cursor-crosshair touch-none"
                                            x-on:mousedown="startCaptureDraw($event)" x-on:mousemove="onCaptureDraw($event)"
                                            x-on:mouseup="stopCaptureDraw()" x-on:mouseleave="stopCaptureDraw()"
                                            x-on:touchstart="startCaptureDraw($event)" x-on:touchmove="onCaptureDraw($event)" x-on:touchend="stopCaptureDraw()"></canvas>
                                    </div>
                                    <div class="mt-2 flex justify-end">
                                        <button type="button" x-on:click="clearCaptureDraw()" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Clear') }}</button>
                                    </div>
                                </div>

                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" x-on:click="cancelCapture()">{{ __('Cancel') }}</flux:button>
                                    <flux:button size="sm" variant="primary" x-on:click="confirmCapture()">{{ __('Confirm') }}</flux:button>
                                </div>
                            </div>
                        </div>

                        {{-- Loading --}}
                        <div x-show="loading" class="absolute inset-0 flex items-center justify-center bg-white/70 dark:bg-zinc-900/70">
                            <flux:icon name="arrow-path" class="size-8 animate-spin text-zinc-400" />
                        </div>
                    </div>

                    {{-- Page Navigation --}}
                    <div class="mt-3 flex items-center justify-center gap-4" x-show="totalPages > 1">
                        <button class="rounded px-2 py-1 text-sm hover:bg-zinc-200 disabled:opacity-50 dark:hover:bg-zinc-700" x-on:click="prevPage()" :disabled="currentPage <= 1">&larr; {{ __('Previous') }}</button>
                        <span class="text-sm" x-text="'{{ __('Page') }} ' + currentPage + ' {{ __('of') }} ' + totalPages"></span>
                        <button class="rounded px-2 py-1 text-sm hover:bg-zinc-200 disabled:opacity-50 dark:hover:bg-zinc-700" x-on:click="nextPage()" :disabled="currentPage >= totalPages">{{ __('Next') }} &rarr;</button>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button variant="subtle" wire:click="$set('showEmployerSigning', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" icon="paper-airplane" wire:click="signAsEmployerAndSend">
                    {{ __('Sign & Send') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Rich Text Employer Signature Modal --}}
    <flux:modal wire:model="showRichTextEmployerSign" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Sign as Employer') }}</flux:heading>
            <flux:text>{{ __('Add your signature before sending this contract to the employee.') }}</flux:text>

            {{-- Type / Draw Toggle --}}
            <div class="flex gap-2">
                <flux:button
                    wire:click="$set('richTextSignatureType', 'typed')"
                    :variant="$richTextSignatureType === 'typed' ? 'primary' : 'ghost'"
                    size="sm"
                >{{ __('Type Signature') }}</flux:button>
                <flux:button
                    wire:click="$set('richTextSignatureType', 'drawn')"
                    :variant="$richTextSignatureType === 'drawn' ? 'primary' : 'ghost'"
                    size="sm"
                >{{ __('Draw Signature') }}</flux:button>
            </div>

            @if ($richTextSignatureType === 'typed')
                <flux:field>
                    <flux:label>{{ __('Type Your Signature') }}</flux:label>
                    <flux:input wire:model="richTextTypedSignature" :placeholder="auth()->user()?->name" />
                    <flux:error name="richTextTypedSignature" />
                </flux:field>
                @if ($richTextTypedSignature)
                    <div class="rounded border border-zinc-200 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-800">
                        <p class="text-2xl italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;">{{ $richTextTypedSignature }}</p>
                    </div>
                @endif
            @else
                <div x-data="{
                    canvas: null, ctx: null, drawing: false,
                    init() {
                        this.canvas = this.$refs.richTextSignCanvas;
                        this.ctx = this.canvas.getContext('2d');
                        this.ctx.strokeStyle = '#1f2937';
                        this.ctx.lineWidth = 2;
                        this.ctx.lineCap = 'round';
                    },
                    startDrawing(e) {
                        this.drawing = true;
                        const rect = this.canvas.getBoundingClientRect();
                        const x = (e.clientX || e.touches[0].clientX) - rect.left;
                        const y = (e.clientY || e.touches[0].clientY) - rect.top;
                        this.ctx.beginPath();
                        this.ctx.moveTo(x, y);
                    },
                    draw(e) {
                        if (!this.drawing) return;
                        e.preventDefault();
                        const rect = this.canvas.getBoundingClientRect();
                        const x = (e.clientX || e.touches[0].clientX) - rect.left;
                        const y = (e.clientY || e.touches[0].clientY) - rect.top;
                        this.ctx.lineTo(x, y);
                        this.ctx.stroke();
                    },
                    stopDrawing() {
                        this.drawing = false;
                        $wire.set('richTextDrawnSignature', this.canvas.toDataURL());
                    },
                    clear() {
                        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                        $wire.set('richTextDrawnSignature', '');
                    }
                }">
                    <flux:text class="mb-2 text-sm font-medium">{{ __('Draw Your Signature') }}</flux:text>
                    <div class="overflow-hidden rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                        <canvas x-ref="richTextSignCanvas" width="400" height="150" class="w-full cursor-crosshair touch-none"
                            x-on:mousedown="startDrawing($event)" x-on:mousemove="draw($event)"
                            x-on:mouseup="stopDrawing()" x-on:mouseleave="stopDrawing()"
                            x-on:touchstart="startDrawing($event)" x-on:touchmove="draw($event)" x-on:touchend="stopDrawing()"></canvas>
                    </div>
                    <div class="mt-2 flex justify-end">
                        <flux:button x-on:click="clear()" size="xs" variant="ghost">{{ __('Clear') }}</flux:button>
                    </div>
                    <flux:error name="richTextDrawnSignature" />
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button variant="subtle" wire:click="$set('showRichTextEmployerSign', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" icon="paper-airplane" wire:click="signRichTextAndSend">{{ __('Sign & Send') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($this->isPdfContract && $showEmployerSigning)
        @assets
            @vite('resources/js/pdf-viewer.js')
        @endassets

        @script
        <script>
            Alpine.data('employerPdfSigner', (config) => {
                let rawPdfDoc = null;
                let captureCanvasCtx = null;

                return {
                    totalPages: 0,
                    currentPage: 1,
                    loading: true,
                    fieldValues: config.fieldValues,

                    captureFieldId: null,
                    captureFieldType: 'signature',
                    captureType: 'typed',
                    captureTypedValue: '',
                    captureDrawnValue: '',
                    captureDrawing: false,

                    get currentPageFields() {
                        return config.fields.filter(f => (f.page || 1) === this.currentPage);
                    },

                    async init() {
                        if (!window.PdfViewer) {
                            this.loading = false;
                            return;
                        }
                        try {
                            rawPdfDoc = await window.PdfViewer.loadPdf(config.pdfUrl);
                            this.totalPages = rawPdfDoc.numPages;
                            await this.renderPage();
                        } catch (e) {
                            console.error('Failed to load PDF:', e);
                        } finally {
                            this.loading = false;
                        }
                    },

                    openCapture(fieldId, fieldType) {
                        this.captureFieldId = fieldId;
                        this.captureFieldType = fieldType || 'signature';
                        this.captureType = 'typed';
                        this.captureTypedValue = '';
                        this.captureDrawnValue = '';
                        captureCanvasCtx = null;
                    },

                    confirmCapture() {
                        if (!this.captureFieldId) return;
                        const value = this.captureType === 'typed' ? this.captureTypedValue : this.captureDrawnValue;
                        if (!value) return;
                        this.fieldValues[this.captureFieldId] = value;
                        $wire.set('employerFieldValues.' + this.captureFieldId, value);
                        this.captureFieldId = null;
                    },

                    cancelCapture() { this.captureFieldId = null; },

                    initCaptureCanvas() {
                        const canvas = this.$refs.captureCanvas;
                        if (!canvas) return;
                        captureCanvasCtx = canvas.getContext('2d');
                        captureCanvasCtx.strokeStyle = '#1f2937';
                        captureCanvasCtx.lineWidth = 2;
                        captureCanvasCtx.lineCap = 'round';
                        captureCanvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                        this.captureDrawnValue = '';
                    },

                    startCaptureDraw(e) {
                        if (!captureCanvasCtx) this.initCaptureCanvas();
                        this.captureDrawing = true;
                        const canvas = this.$refs.captureCanvas;
                        const rect = canvas.getBoundingClientRect();
                        const scaleX = canvas.width / rect.width;
                        const scaleY = canvas.height / rect.height;
                        const x = ((e.clientX || e.touches[0].clientX) - rect.left) * scaleX;
                        const y = ((e.clientY || e.touches[0].clientY) - rect.top) * scaleY;
                        captureCanvasCtx.beginPath();
                        captureCanvasCtx.moveTo(x, y);
                    },

                    onCaptureDraw(e) {
                        if (!this.captureDrawing || !captureCanvasCtx) return;
                        e.preventDefault();
                        const canvas = this.$refs.captureCanvas;
                        const rect = canvas.getBoundingClientRect();
                        const scaleX = canvas.width / rect.width;
                        const scaleY = canvas.height / rect.height;
                        const x = ((e.clientX || e.touches[0].clientX) - rect.left) * scaleX;
                        const y = ((e.clientY || e.touches[0].clientY) - rect.top) * scaleY;
                        captureCanvasCtx.lineTo(x, y);
                        captureCanvasCtx.stroke();
                    },

                    stopCaptureDraw() {
                        if (!this.captureDrawing) return;
                        this.captureDrawing = false;
                        const canvas = this.$refs.captureCanvas;
                        if (canvas) this.captureDrawnValue = canvas.toDataURL();
                    },

                    clearCaptureDraw() {
                        const canvas = this.$refs.captureCanvas;
                        if (canvas && captureCanvasCtx) captureCanvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                        this.captureDrawnValue = '';
                    },

                    async renderPage() {
                        if (!rawPdfDoc) return;
                        const canvas = this.$refs.pdfCanvas;
                        await window.PdfViewer.renderPage(rawPdfDoc, this.currentPage, canvas, 1.5);
                    },

                    async prevPage() {
                        if (this.currentPage > 1) { this.currentPage--; await this.renderPage(); }
                    },

                    async nextPage() {
                        if (this.currentPage < this.totalPages) { this.currentPage++; await this.renderPage(); }
                    },
                };
            });
        </script>
        @endscript
    @endif
</div>
