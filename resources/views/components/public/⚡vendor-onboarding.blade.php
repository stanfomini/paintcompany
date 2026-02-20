<?php

use App\Models\CompanySetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Notifications\VendorOnboardingCompleted;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.public')] #[Title('Vendor Onboarding')] class extends Component {
    use WithFileUploads;

    public string $token = '';
    public ?Vendor $vendor = null;
    public bool $completed = false;

    // Vendor info form
    public string $vendorName = '';
    public string $contactName = '';
    public string $vendorEmail = '';
    public string $vendorPhone = '';
    public string $addressLine1 = '';
    public string $addressLine2 = '';
    public string $vendorCity = '';
    public string $vendorState = '';
    public string $vendorZip = '';
    public string $vendorCategory = '';
    public string $vendorNotes = '';

    // Compliance toggles
    public bool $w9OnFile = false;
    public bool $holdHarmless = false;
    public bool $certificateOfInsurance = false;
    public ?string $insuranceExpiration = null;
    public bool $workersComp = false;
    public ?string $workersCompExpiration = null;
    public bool $rejectionForm = false;

    // File uploads
    public $w9File = null;
    public $insuranceFile = null;
    public $workersCompFile = null;
    public $holdHarmlessFile = null;
    public $otherFiles = [];

    public function mount(string $token): void
    {
        $vendor = Vendor::byPublicToken($token)->first();

        if (! $vendor) {
            abort(404, 'Vendor onboarding link not found.');
        }

        if (! $vendor->isPublicTokenValid()) {
            abort(410, 'This onboarding link has expired.');
        }

        $this->token = $token;
        $this->vendor = $vendor;

        if ($vendor->hasCompletedOnboarding()) {
            $this->completed = true;

            return;
        }

        // Pre-fill from existing vendor data
        $this->vendorName = $vendor->name ?? '';
        $this->contactName = $vendor->contact_name ?? '';
        $this->vendorEmail = $vendor->email ?? '';
        $this->vendorPhone = $vendor->phone ?? '';
        $this->addressLine1 = $vendor->address_line_1 ?? '';
        $this->addressLine2 = $vendor->address_line_2 ?? '';
        $this->vendorCity = $vendor->city ?? '';
        $this->vendorState = $vendor->state ?? '';
        $this->vendorZip = $vendor->zip ?? '';
        $this->vendorCategory = $vendor->category ?? 'supplier';
        $this->vendorNotes = $vendor->notes ?? '';
        $this->w9OnFile = $vendor->w9_on_file ?? false;
        $this->holdHarmless = $vendor->hold_harmless ?? false;
        $this->certificateOfInsurance = $vendor->certificate_of_insurance ?? false;
        $this->insuranceExpiration = $vendor->insurance_expiration?->format('Y-m-d');
        $this->workersComp = $vendor->workers_comp ?? false;
        $this->workersCompExpiration = $vendor->workers_comp_expiration?->format('Y-m-d');
        $this->rejectionForm = $vendor->rejection_form ?? false;
    }

    #[Computed]
    public function companyName(): string
    {
        return CompanySetting::getInstance()->company_name ?? config('app.name');
    }

    #[Computed]
    public function existingDocuments()
    {
        return $this->vendor?->documents()->orderByDesc('created_at')->get() ?? collect();
    }

    public function submit(): void
    {
        $this->validate([
            'vendorName' => ['required', 'string', 'max:255'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'vendorEmail' => ['nullable', 'email', 'max:255'],
            'vendorPhone' => ['nullable', 'string', 'max:50'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'vendorCity' => ['nullable', 'string', 'max:100'],
            'vendorState' => ['nullable', 'string', 'max:50'],
            'vendorZip' => ['nullable', 'string', 'max:20'],
            'vendorCategory' => ['required', 'in:supplier,subcontractor,service,other'],
            'vendorNotes' => ['nullable', 'string'],
            'insuranceExpiration' => ['nullable', 'date'],
            'workersCompExpiration' => ['nullable', 'date'],
            'w9File' => ['nullable', 'file', 'max:10240'],
            'insuranceFile' => ['nullable', 'file', 'max:10240'],
            'workersCompFile' => ['nullable', 'file', 'max:10240'],
            'holdHarmlessFile' => ['nullable', 'file', 'max:10240'],
            'otherFiles.*' => ['nullable', 'file', 'max:10240'],
        ]);

        // Update vendor info
        $this->vendor->update([
            'name' => $this->vendorName,
            'contact_name' => $this->contactName ?: null,
            'email' => $this->vendorEmail ?: null,
            'phone' => $this->vendorPhone ?: null,
            'address_line_1' => $this->addressLine1 ?: null,
            'address_line_2' => $this->addressLine2 ?: null,
            'city' => $this->vendorCity ?: null,
            'state' => $this->vendorState ?: null,
            'zip' => $this->vendorZip ?: null,
            'category' => $this->vendorCategory,
            'notes' => $this->vendorNotes ?: null,
            'w9_on_file' => $this->w9OnFile || $this->w9File !== null,
            'hold_harmless' => $this->holdHarmless || $this->holdHarmlessFile !== null,
            'certificate_of_insurance' => $this->certificateOfInsurance || $this->insuranceFile !== null,
            'insurance_expiration' => ($this->certificateOfInsurance || $this->insuranceFile) ? $this->insuranceExpiration : null,
            'workers_comp' => $this->workersComp || $this->workersCompFile !== null,
            'workers_comp_expiration' => ($this->workersComp || $this->workersCompFile) ? $this->workersCompExpiration : null,
            'rejection_form' => ! $this->workersComp && ! $this->workersCompFile ? $this->rejectionForm : false,
        ]);

        $docsUploaded = 0;

        // Upload documents
        $fileMap = [
            'w9File' => ['type' => VendorDocument::TYPE_W9, 'name' => 'W-9 Form'],
            'insuranceFile' => ['type' => VendorDocument::TYPE_INSURANCE, 'name' => 'Certificate of Insurance'],
            'workersCompFile' => ['type' => VendorDocument::TYPE_WORKERS_COMP, 'name' => 'Workers Comp Certificate'],
            'holdHarmlessFile' => ['type' => VendorDocument::TYPE_HOLD_HARMLESS, 'name' => 'Hold Harmless Agreement'],
        ];

        foreach ($fileMap as $prop => $meta) {
            if ($this->{$prop}) {
                $path = $this->{$prop}->store('vendor-documents', config('filesystems.default'));

                VendorDocument::create([
                    'vendor_id' => $this->vendor->id,
                    'name' => $meta['name'],
                    'type' => $meta['type'],
                    'file_path' => $path,
                    'original_filename' => $this->{$prop}->getClientOriginalName(),
                    'status' => VendorDocument::STATUS_PENDING,
                    'expires_at' => match ($meta['type']) {
                        VendorDocument::TYPE_INSURANCE => $this->insuranceExpiration,
                        VendorDocument::TYPE_WORKERS_COMP => $this->workersCompExpiration,
                        default => null,
                    },
                ]);

                $docsUploaded++;
            }
        }

        // Handle other files
        if (! empty($this->otherFiles)) {
            foreach ($this->otherFiles as $file) {
                $path = $file->store('vendor-documents', config('filesystems.default'));

                VendorDocument::create([
                    'vendor_id' => $this->vendor->id,
                    'name' => $file->getClientOriginalName(),
                    'type' => VendorDocument::TYPE_OTHER,
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'status' => VendorDocument::STATUS_PENDING,
                ]);

                $docsUploaded++;
            }
        }

        $this->vendor->markOnboardingComplete();
        $this->completed = true;

        // Notify internal users with vendors.manage permission
        $managers = User::where('type', 'internal')
            ->permission('vendors.manage')
            ->get();

        foreach ($managers as $manager) {
            $manager->notify(new VendorOnboardingCompleted($this->vendor, $docsUploaded));
        }
    }
};
?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-900 py-8 px-4">
    <div class="mx-auto max-w-2xl">
        {{-- Header --}}
        <div class="text-center mb-8">
            <flux:heading size="xl">{{ $this->companyName }}</flux:heading>
            <flux:text class="mt-2 text-lg">{{ __('Vendor Onboarding') }}</flux:text>
        </div>

        @if ($completed)
            {{-- Completion state --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <div class="mx-auto w-16 h-16 rounded-full bg-green-100 dark:bg-green-900 flex items-center justify-center mb-4">
                    <flux:icon name="check-circle" class="w-10 h-10 text-green-600 dark:text-green-400" />
                </div>
                <flux:heading size="lg">{{ __('Thank You!') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Your vendor information has been submitted successfully. Our team will review your documents and follow up if anything additional is needed.') }}
                </flux:text>
            </div>
        @else
            {{-- Onboarding form --}}
            <form wire:submit="submit" class="space-y-6">
                {{-- Business Information --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <flux:heading size="lg">{{ __('Business Information') }}</flux:heading>
                    <flux:text>{{ __('Please fill in your business details below.') }}</flux:text>

                    <flux:input wire:model="vendorName" :label="__('Business / Company Name')" required />
                    <flux:input wire:model="contactName" :label="__('Primary Contact Name')" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:input wire:model="vendorEmail" type="email" :label="__('Email')" />
                        <flux:input wire:model="vendorPhone" :label="__('Phone')" />
                    </div>

                    <flux:input wire:model="addressLine1" :label="__('Address Line 1')" />
                    <flux:input wire:model="addressLine2" :label="__('Address Line 2')" />

                    <div class="grid grid-cols-5 gap-3">
                        <div class="col-span-2">
                            <flux:input wire:model="vendorCity" :label="__('City')" />
                        </div>
                        <div class="col-span-1">
                            <flux:input wire:model="vendorState" :label="__('State')" />
                        </div>
                        <div class="col-span-2">
                            <flux:input wire:model="vendorZip" :label="__('Zip')" />
                        </div>
                    </div>

                    <flux:select wire:model="vendorCategory" :label="__('Vendor Type')">
                        @foreach(\App\Models\Vendor::CATEGORIES as $val => $label)
                            <flux:select.option value="{{ $val }}">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                {{-- Document Uploads --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Documents') }}</flux:heading>
                        <flux:text>{{ __('Please upload the following documents. Accepted formats: PDF, JPG, PNG (max 10MB each).') }}</flux:text>
                    </div>

                    {{-- W-9 --}}
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('W-9 Form') }}
                            <span class="text-red-500">*</span>
                        </label>
                        <input type="file" wire:model="w9File" accept=".pdf,.jpg,.jpeg,.png"
                            class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                            file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                            file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700
                            dark:file:bg-blue-900/50 dark:file:text-blue-300
                            hover:file:bg-blue-100 dark:hover:file:bg-blue-900" />
                        <div wire:loading wire:target="w9File" class="text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</div>
                        @error('w9File') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Certificate of Insurance --}}
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Certificate of Insurance') }}
                        </label>
                        <input type="file" wire:model="insuranceFile" accept=".pdf,.jpg,.jpeg,.png"
                            class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                            file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                            file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700
                            dark:file:bg-blue-900/50 dark:file:text-blue-300
                            hover:file:bg-blue-100 dark:hover:file:bg-blue-900" />
                        <div wire:loading wire:target="insuranceFile" class="text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</div>
                        @error('insuranceFile') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                        @if($insuranceFile)
                            <flux:input wire:model="insuranceExpiration" type="date" :label="__('Insurance Expiration Date')" />
                        @endif
                    </div>

                    {{-- Workers Comp --}}
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Workers Compensation Certificate') }}
                        </label>
                        <input type="file" wire:model="workersCompFile" accept=".pdf,.jpg,.jpeg,.png"
                            class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                            file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                            file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700
                            dark:file:bg-blue-900/50 dark:file:text-blue-300
                            hover:file:bg-blue-100 dark:hover:file:bg-blue-900" />
                        <div wire:loading wire:target="workersCompFile" class="text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</div>
                        @error('workersCompFile') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                        @if($workersCompFile)
                            <flux:input wire:model="workersCompExpiration" type="date" :label="__('Workers Comp Expiration Date')" />
                        @endif

                        @if(!$workersCompFile)
                            <div class="mt-2">
                                <flux:checkbox wire:model="rejectionForm" :label="__('I have a workers comp rejection form on file instead')" />
                            </div>
                        @endif
                    </div>

                    {{-- Hold Harmless --}}
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Hold Harmless Agreement') }}
                        </label>
                        <input type="file" wire:model="holdHarmlessFile" accept=".pdf,.jpg,.jpeg,.png"
                            class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                            file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                            file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700
                            dark:file:bg-blue-900/50 dark:file:text-blue-300
                            hover:file:bg-blue-100 dark:hover:file:bg-blue-900" />
                        <div wire:loading wire:target="holdHarmlessFile" class="text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</div>
                        @error('holdHarmlessFile') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Other Documents --}}
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Other Documents (licenses, certifications, etc.)') }}
                        </label>
                        <input type="file" wire:model="otherFiles" accept=".pdf,.jpg,.jpeg,.png" multiple
                            class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                            file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                            file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700
                            dark:file:bg-blue-900/50 dark:file:text-blue-300
                            hover:file:bg-blue-100 dark:hover:file:bg-blue-900" />
                        <div wire:loading wire:target="otherFiles" class="text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</div>
                        @error('otherFiles.*') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Previously uploaded --}}
                    @if($this->existingDocuments->isNotEmpty())
                        <div class="mt-4">
                            <flux:heading size="sm" class="mb-2">{{ __('Previously Uploaded') }}</flux:heading>
                            <div class="space-y-1">
                                @foreach($this->existingDocuments as $doc)
                                    <div wire:key="doc-{{ $doc->id }}" class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                                        <flux:icon name="document" variant="mini" />
                                        <span>{{ $doc->name }}</span>
                                        <flux:badge size="sm" :color="$doc->status === 'approved' ? 'green' : ($doc->status === 'rejected' ? 'red' : 'amber')">
                                            {{ $doc->getStatusLabel() }}
                                        </flux:badge>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Additional Notes --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <flux:heading size="lg">{{ __('Additional Notes') }}</flux:heading>
                    <flux:textarea wire:model="vendorNotes" :placeholder="__('Any additional information you would like to share...')" rows="3" />
                </div>

                {{-- Submit --}}
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submit">{{ __('Submit Information') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Submitting...') }}</span>
                    </flux:button>
                </div>
            </form>
        @endif
    </div>
</div>
