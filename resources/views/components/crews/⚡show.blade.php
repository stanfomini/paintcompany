<?php

use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public User $user;

    public string $activeTab = 'overview';

    // Edit modal states
    public bool $showEditProfileModal = false;

    public bool $showComplianceModal = false;

    // Profile edit fields
    public string $editName = '';

    public string $editCompanyName = '';

    public string $editPhone = '';

    public int $editCrewSize = 1;

    public array $editSpecialties = [];

    public ?string $editHourlyRate = null;

    public ?string $editBonusPercent = null;

    public ?int $editServiceCategoryId = null;

    public string $editBio = '';

    // Compliance edit fields
    public string $editInsuranceCompany = '';

    public string $editPolicyNumber = '';

    public ?string $editInsuranceStartDate = null;

    public ?string $editInsuranceExpirationDate = null;

    public ?string $editInsurancePhotoUrl = null;

    public $editInsurancePhotoFile = null;

    public string $editInsurancePhotoType = 'url';

    public ?string $editW9PhotoUrl = null;

    public $editW9PhotoFile = null;

    public string $editW9PhotoType = 'url';

    public ?string $editW9ReceivedDate = null;

    public string $editLicenseNumber = '';

    public ?string $editLicenseExpirationDate = null;

    public string $editNotes = '';

    // Workers' Comp edit fields
    public string $editWorkersCompCarrier = '';

    public string $editWorkersCompPolicyNumber = '';

    public ?string $editWorkersCompStartDate = null;

    public ?string $editWorkersCompExpirationDate = null;

    public ?string $editWorkersCompDocumentUrl = null;

    public $editWorkersCompDocumentFile = null;

    public string $editWorkersCompDocumentType = 'url';

    public function mount(User $user): void
    {
        if (! $user->isCrew()) {
            abort(404);
        }

        $this->user = $user->load([
            'crewProfile.serviceCategory',
            'crewJobs.deal.customer',
            'crewJobs.deal.property',
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function openEditProfileModal(): void
    {
        $profile = $this->user->crewProfile;

        $this->editName = $this->user->name;
        $this->editCompanyName = $profile?->company_name ?? '';
        $this->editPhone = $profile?->phone ?? '';
        $this->editCrewSize = $profile?->crew_size ?? 1;
        $this->editSpecialties = $profile?->specialties ?? [];
        $this->editHourlyRate = $profile?->hourly_rate;
        $this->editBonusPercent = $profile?->bonus_percent;
        $this->editServiceCategoryId = $profile?->service_category_id;
        $this->editBio = $profile?->bio ?? '';

        $this->showEditProfileModal = true;
    }

    public function saveProfile(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->validate([
            'editName' => 'required|string|max:255',
            'editCompanyName' => 'nullable|string|max:255',
            'editPhone' => 'nullable|string|max:20',
            'editCrewSize' => 'required|integer|min:1|max:20',
            'editHourlyRate' => 'nullable|numeric|min:0',
            'editBonusPercent' => 'nullable|numeric|min:0|max:100',
            'editServiceCategoryId' => 'nullable|exists:service_categories,id',
            'editBio' => 'nullable|string',
        ]);

        $this->user->update(['name' => $this->editName]);

        $this->user->crewProfile->update([
            'company_name' => $this->editCompanyName ?: null,
            'phone' => $this->editPhone ?: null,
            'crew_size' => $this->editCrewSize,
            'specialties' => $this->editSpecialties,
            'hourly_rate' => $this->editHourlyRate ?: null,
            'bonus_percent' => $this->editBonusPercent ?: null,
            'service_category_id' => $this->editServiceCategoryId,
            'bio' => $this->editBio ?: null,
        ]);

        $this->user->refresh();
        $this->showEditProfileModal = false;
        session()->flash('success', 'Profile updated successfully.');
    }

    public function openComplianceModal(): void
    {
        $profile = $this->user->crewProfile;

        $this->editInsuranceCompany = $profile?->insurance_company ?? '';
        $this->editPolicyNumber = $profile?->policy_number ?? '';
        $this->editInsuranceStartDate = $profile?->insurance_start_date?->format('Y-m-d');
        $this->editInsuranceExpirationDate = $profile?->insurance_expiration_date?->format('Y-m-d');
        $this->editInsurancePhotoType = $profile?->insurance_photo_type ?? 'url';
        $this->editInsurancePhotoUrl = $this->editInsurancePhotoType === 'url' ? $profile?->insurance_photo : null;
        $this->editInsurancePhotoFile = null;

        $this->editW9PhotoType = $profile?->w9_photo_type ?? 'url';
        $this->editW9PhotoUrl = $this->editW9PhotoType === 'url' ? $profile?->w9_photo : null;
        $this->editW9PhotoFile = null;
        $this->editW9ReceivedDate = $profile?->w9_received_date?->format('Y-m-d');

        $this->editWorkersCompCarrier = $profile?->workers_comp_carrier ?? '';
        $this->editWorkersCompPolicyNumber = $profile?->workers_comp_policy_number ?? '';
        $this->editWorkersCompStartDate = $profile?->workers_comp_start_date?->format('Y-m-d');
        $this->editWorkersCompExpirationDate = $profile?->workers_comp_expiration_date?->format('Y-m-d');
        $this->editWorkersCompDocumentType = $profile?->workers_comp_document_type ?? 'url';
        $this->editWorkersCompDocumentUrl = $this->editWorkersCompDocumentType === 'url' ? $profile?->workers_comp_document : null;
        $this->editWorkersCompDocumentFile = null;

        $this->editLicenseNumber = $profile?->license_number ?? '';
        $this->editLicenseExpirationDate = $profile?->license_expiration_date?->format('Y-m-d');
        $this->editNotes = $profile?->notes ?? '';

        $this->showComplianceModal = true;
    }

    public function saveCompliance(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->validate([
            'editInsuranceCompany' => 'nullable|string|max:255',
            'editPolicyNumber' => 'nullable|string|max:255',
            'editInsuranceStartDate' => 'nullable|date',
            'editInsuranceExpirationDate' => 'nullable|date',
            'editInsurancePhotoUrl' => 'nullable|url|max:500',
            'editInsurancePhotoFile' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'editW9PhotoUrl' => 'nullable|url|max:500',
            'editW9PhotoFile' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'editW9ReceivedDate' => 'nullable|date',
            'editWorkersCompCarrier' => 'nullable|string|max:255',
            'editWorkersCompPolicyNumber' => 'nullable|string|max:255',
            'editWorkersCompStartDate' => 'nullable|date',
            'editWorkersCompExpirationDate' => 'nullable|date',
            'editWorkersCompDocumentUrl' => 'nullable|url|max:500',
            'editWorkersCompDocumentFile' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'editLicenseNumber' => 'nullable|string|max:100',
            'editLicenseExpirationDate' => 'nullable|date',
            'editNotes' => 'nullable|string',
        ]);

        $data = [
            'insurance_company' => $this->editInsuranceCompany ?: null,
            'policy_number' => $this->editPolicyNumber ?: null,
            'insurance_start_date' => $this->editInsuranceStartDate ?: null,
            'insurance_expiration_date' => $this->editInsuranceExpirationDate ?: null,
            'w9_received_date' => $this->editW9ReceivedDate ?: null,
            'workers_comp_carrier' => $this->editWorkersCompCarrier ?: null,
            'workers_comp_policy_number' => $this->editWorkersCompPolicyNumber ?: null,
            'workers_comp_start_date' => $this->editWorkersCompStartDate ?: null,
            'workers_comp_expiration_date' => $this->editWorkersCompExpirationDate ?: null,
            'license_number' => $this->editLicenseNumber ?: null,
            'license_expiration_date' => $this->editLicenseExpirationDate ?: null,
            'notes' => $this->editNotes ?: null,
        ];

        // Handle insurance photo
        if ($this->editInsurancePhotoType === 'file' && $this->editInsurancePhotoFile) {
            $path = $this->editInsurancePhotoFile->store('crew-documents', 'public');
            $data['insurance_photo'] = $path;
            $data['insurance_photo_type'] = 'file';
        } elseif ($this->editInsurancePhotoType === 'url' && $this->editInsurancePhotoUrl) {
            $data['insurance_photo'] = $this->editInsurancePhotoUrl;
            $data['insurance_photo_type'] = 'url';
        }

        // Handle W9 photo
        if ($this->editW9PhotoType === 'file' && $this->editW9PhotoFile) {
            $path = $this->editW9PhotoFile->store('crew-documents', 'public');
            $data['w9_photo'] = $path;
            $data['w9_photo_type'] = 'file';
        } elseif ($this->editW9PhotoType === 'url' && $this->editW9PhotoUrl) {
            $data['w9_photo'] = $this->editW9PhotoUrl;
            $data['w9_photo_type'] = 'url';
        }

        // Handle Workers' Comp document
        if ($this->editWorkersCompDocumentType === 'file' && $this->editWorkersCompDocumentFile) {
            $path = $this->editWorkersCompDocumentFile->store('crew-documents', 'public');
            $data['workers_comp_document'] = $path;
            $data['workers_comp_document_type'] = 'file';
        } elseif ($this->editWorkersCompDocumentType === 'url' && $this->editWorkersCompDocumentUrl) {
            $data['workers_comp_document'] = $this->editWorkersCompDocumentUrl;
            $data['workers_comp_document_type'] = 'url';
        }

        $this->user->crewProfile->update($data);

        $this->user->refresh();
        $this->showComplianceModal = false;
        session()->flash('success', 'Compliance information updated successfully.');
    }

    public function toggleSpecialty(string $specialty): void
    {
        if (in_array($specialty, $this->editSpecialties)) {
            $this->editSpecialties = array_values(array_filter($this->editSpecialties, fn ($s) => $s !== $specialty));
        } else {
            $this->editSpecialties[] = $specialty;
        }
    }

    public function activate(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->user->update(['is_active' => true]);
        $this->user->refresh();
        session()->flash('success', 'Crew activated successfully.');
    }

    public function deactivate(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->user->update(['is_active' => false]);
        $this->user->refresh();
        session()->flash('success', 'Crew deactivated successfully.');
    }

    #[Computed]
    public function activeJobs()
    {
        return $this->user->crewJobs
            ->filter(fn ($job) => $job->stage !== 'completed')
            ->sortByDesc('scheduled_date');
    }

    #[Computed]
    public function completedJobs()
    {
        return $this->user->crewJobs
            ->filter(fn ($job) => $job->stage === 'completed')
            ->sortByDesc('actual_end_date');
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('crews.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <flux:heading size="xl">{{ $user->name }}</flux:heading>
                @if ($user->crewProfile?->serviceCategory)
                    <flux:badge size="sm" color="{{ $user->crewProfile->serviceCategory->color ?? 'zinc' }}">
                        {{ $user->crewProfile->serviceCategory->name }}
                    </flux:badge>
                @endif
                @if ($user->is_active)
                    <flux:badge size="sm" color="green">Active</flux:badge>
                @else
                    <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                @endif
                @can('people.edit')
                    @if ($user->is_active)
                        <flux:button wire:click="deactivate" size="sm" variant="ghost" icon="x-circle" wire:confirm="Are you sure you want to deactivate this crew? They will be unable to log in.">
                            Deactivate
                        </flux:button>
                    @else
                        <flux:button wire:click="activate" size="sm" variant="primary" icon="check-circle">
                            Activate
                        </flux:button>
                    @endif
                @endcan
                @php
                    $complianceStatus = $user->crewProfile?->getComplianceStatus() ?? 'compliant';
                @endphp
                @if ($complianceStatus === 'expired')
                    <flux:badge size="sm" color="red">Compliance Expired</flux:badge>
                @elseif ($complianceStatus === 'warning')
                    <flux:badge size="sm" color="yellow">Expiring Soon</flux:badge>
                @endif
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <!-- Stats Overview -->
        <div class="grid gap-4 md:grid-cols-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Crew Size</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->crewProfile?->crew_size ?? 1 }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Hourly Rate</flux:text>
                <flux:heading size="xl" class="mt-1">
                    @if ($user->crewProfile?->hourly_rate)
                        ${{ number_format($user->crewProfile->hourly_rate, 2) }}
                    @else
                        -
                    @endif
                </flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Active Jobs</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->activeJobs->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Completed Jobs</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->completedJobs->count() }}</flux:heading>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
            <nav class="-mb-px flex space-x-8">
                <button
                    wire:click="setTab('overview')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'overview' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Overview
                </button>
                <button
                    wire:click="setTab('compliance')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'compliance' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Insurance & Compliance
                    @if ($complianceStatus === 'expired')
                        <span class="ml-2 inline-flex items-center justify-center w-2 h-2 bg-red-500 rounded-full"></span>
                    @elseif ($complianceStatus === 'warning')
                        <span class="ml-2 inline-flex items-center justify-center w-2 h-2 bg-yellow-500 rounded-full"></span>
                    @endif
                </button>
                <button
                    wire:click="setTab('active-jobs')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'active-jobs' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Active Jobs ({{ $this->activeJobs->count() }})
                </button>
                <button
                    wire:click="setTab('completed-jobs')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'completed-jobs' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Completed Jobs ({{ $this->completedJobs->count() }})
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        @if ($activeTab === 'overview')
            @can('people.edit')
                <div class="flex justify-end mb-4">
                    <flux:button wire:click="openEditProfileModal" size="sm" icon="pencil">
                        Edit Profile
                    </flux:button>
                </div>
            @endcan

            <div class="grid gap-6 md:grid-cols-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Contact Information</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Email</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->email }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Phone</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->phone ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Company</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->company_name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Crew Details</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Crew Size</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->crew_size ?? 1 }} {{ Str::plural('person', $user->crewProfile?->crew_size ?? 1) }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Hourly Rate</dt>
                            <dd class="mt-1">
                                <flux:text>
                                    @if ($user->crewProfile?->hourly_rate)
                                        ${{ number_format($user->crewProfile->hourly_rate, 2) }}/hr
                                    @else
                                        Not set
                                    @endif
                                </flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Crew Lead Bonus</dt>
                            <dd class="mt-1">
                                <flux:text>
                                    @if ($user->crewProfile?->bonus_percent)
                                        {{ number_format($user->crewProfile->bonus_percent, 1) }}% of job price (conditional)
                                    @else
                                        Not set
                                    @endif
                                </flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Specialties</dt>
                            <dd class="mt-1">
                                @if ($user->crewProfile?->specialties && count($user->crewProfile->specialties) > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($user->crewProfile->specialties as $specialty)
                                            <flux:badge size="sm">{{ ucfirst($specialty) }}</flux:badge>
                                        @endforeach
                                    </div>
                                @else
                                    <flux:text class="text-zinc-400">None specified</flux:text>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($user->crewProfile?->bio)
                <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Bio</flux:heading>
                    <flux:text>{{ $user->crewProfile->bio }}</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'compliance')
            @can('people.edit')
                <div class="flex justify-end mb-4">
                    <flux:button wire:click="openComplianceModal" size="sm" icon="pencil">
                        Edit Compliance Info
                    </flux:button>
                </div>
            @endcan

            <div class="grid gap-6 md:grid-cols-2">
                <!-- Insurance Information -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Insurance</flux:heading>
                        @if ($user->crewProfile?->isInsuranceExpired())
                            <flux:badge size="sm" color="red">Expired</flux:badge>
                        @elseif ($user->crewProfile?->isInsuranceExpiringSoon())
                            <flux:badge size="sm" color="yellow">Expiring Soon</flux:badge>
                        @elseif ($user->crewProfile?->insurance_expiration_date)
                            <flux:badge size="sm" color="green">Valid</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Insurance Company</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->insurance_company ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Policy Number</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->policy_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Coverage Period</dt>
                            <dd class="mt-1">
                                <flux:text>
                                    @if ($user->crewProfile?->insurance_start_date && $user->crewProfile?->insurance_expiration_date)
                                        {{ $user->crewProfile->insurance_start_date->format('M j, Y') }} - {{ $user->crewProfile->insurance_expiration_date->format('M j, Y') }}
                                    @else
                                        -
                                    @endif
                                </flux:text>
                            </dd>
                        </div>
                        @if ($user->crewProfile?->insurance_photo)
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Document</dt>
                                <dd class="mt-1">
                                    @if ($user->crewProfile->insurance_photo_type === 'url')
                                        <a href="{{ $user->crewProfile->insurance_photo }}" target="_blank" class="text-blue-600 hover:underline">
                                            View Insurance Document
                                        </a>
                                    @else
                                        <a href="{{ Storage::url($user->crewProfile->insurance_photo) }}" target="_blank" class="text-blue-600 hover:underline">
                                            View Insurance Document
                                        </a>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <!-- Workers' Compensation Insurance -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Workers' Compensation</flux:heading>
                        @if ($user->crewProfile?->isWorkersCompExpired())
                            <flux:badge size="sm" color="red">Expired</flux:badge>
                        @elseif ($user->crewProfile?->isWorkersCompExpiringSoon())
                            <flux:badge size="sm" color="yellow">Expiring Soon</flux:badge>
                        @elseif ($user->crewProfile?->workers_comp_expiration_date)
                            <flux:badge size="sm" color="green">Valid</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Carrier</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->workers_comp_carrier ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Policy Number</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->workers_comp_policy_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Coverage Period</dt>
                            <dd class="mt-1">
                                <flux:text>
                                    @if ($user->crewProfile?->workers_comp_start_date && $user->crewProfile?->workers_comp_expiration_date)
                                        {{ $user->crewProfile->workers_comp_start_date->format('M j, Y') }} - {{ $user->crewProfile->workers_comp_expiration_date->format('M j, Y') }}
                                    @else
                                        -
                                    @endif
                                </flux:text>
                            </dd>
                        </div>
                        @if ($user->crewProfile?->workers_comp_document)
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Document</dt>
                                <dd class="mt-1">
                                    @if ($user->crewProfile->workers_comp_document_type === 'url')
                                        <a href="{{ $user->crewProfile->workers_comp_document }}" target="_blank" class="text-blue-600 hover:underline">
                                            View Workers' Comp Document
                                        </a>
                                    @else
                                        <a href="{{ Storage::url($user->crewProfile->workers_comp_document) }}" target="_blank" class="text-blue-600 hover:underline">
                                            View Workers' Comp Document
                                        </a>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <!-- License Information -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Contractor License</flux:heading>
                        @if ($user->crewProfile?->isLicenseExpired())
                            <flux:badge size="sm" color="red">Expired</flux:badge>
                        @elseif ($user->crewProfile?->isLicenseExpiringSoon())
                            <flux:badge size="sm" color="yellow">Expiring Soon</flux:badge>
                        @elseif ($user->crewProfile?->license_expiration_date)
                            <flux:badge size="sm" color="green">Valid</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">License Number</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->license_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Expiration Date</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->license_expiration_date?->format('M j, Y') ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- W9 Information -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">W9</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Received Date</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->w9_received_date?->format('M j, Y') ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        @if ($user->crewProfile?->w9_photo)
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Document</dt>
                                <dd class="mt-1">
                                    @if ($user->crewProfile->w9_photo_type === 'url')
                                        <a href="{{ $user->crewProfile->w9_photo }}" target="_blank" class="text-blue-600 hover:underline">
                                            View W9 Document
                                        </a>
                                    @else
                                        <a href="{{ Storage::url($user->crewProfile->w9_photo) }}" target="_blank" class="text-blue-600 hover:underline">
                                            View W9 Document
                                        </a>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <!-- Internal Notes -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Internal Notes</flux:heading>
                    <flux:text class="whitespace-pre-line">{{ $user->crewProfile?->notes ?? 'No notes added.' }}</flux:text>
                </div>
            </div>
        @endif

        @if ($activeTab === 'active-jobs')
            @if ($this->activeJobs->count() > 0)
                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Job #
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Title / Customer
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Property
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Stage
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Scheduled Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->activeJobs as $job)
                                <tr wire:key="job-{{ $job->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $job->job_number }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $job->title }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $job->deal?->customer?->full_name ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $job->deal?->property?->address ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:badge size="sm" color="blue">{{ ucwords(str_replace('_', ' ', $job->stage)) }}</flux:badge>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $job->scheduled_date?->format('M j, Y') ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <flux:button variant="ghost" size="sm" icon="eye" :href="route('jobs.show', $job)" wire:navigate>
                                            View
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="clipboard-document-check" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">No active jobs</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">This crew has no active jobs assigned.</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'completed-jobs')
            @if ($this->completedJobs->count() > 0)
                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Job #
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Title / Customer
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Property
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Completed Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->completedJobs as $job)
                                <tr wire:key="job-{{ $job->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $job->job_number }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $job->title }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $job->deal?->customer?->full_name ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $job->deal?->property?->address ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $job->actual_end_date?->format('M j, Y') ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <flux:button variant="ghost" size="sm" icon="eye" :href="route('jobs.show', $job)" wire:navigate>
                                            View
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">No completed jobs</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">This crew has not completed any jobs yet.</flux:text>
                </div>
            @endif
        @endif
    </div>

    <!-- Edit Profile Modal -->
    <flux:modal wire:model="showEditProfileModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">Edit Profile</flux:heading>

            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="editName" />
                <flux:error name="editName" />
            </flux:field>

            <flux:field>
                <flux:label>Company Name</flux:label>
                <flux:input wire:model="editCompanyName" />
                <flux:error name="editCompanyName" />
            </flux:field>

            <flux:field>
                <flux:label>Phone</flux:label>
                <flux:input wire:model="editPhone" type="tel" />
                <flux:error name="editPhone" />
            </flux:field>

            <flux:field>
                <flux:label>Crew Size</flux:label>
                <flux:input wire:model="editCrewSize" type="number" min="1" max="20" />
                <flux:error name="editCrewSize" />
            </flux:field>

            <flux:field>
                <flux:label>Hourly Rate</flux:label>
                <flux:input wire:model="editHourlyRate" type="number" step="0.01" min="0" prefix="$" />
                <flux:error name="editHourlyRate" />
            </flux:field>

            <flux:field>
                <flux:label>Crew Lead Bonus (%)</flux:label>
                <flux:input wire:model="editBonusPercent" type="number" step="0.5" min="0" max="100" />
                <flux:text class="text-xs text-zinc-500 mt-1">% of job price paid to crew lead if they meet contract standards</flux:text>
                <flux:error name="editBonusPercent" />
            </flux:field>

            <flux:field>
                <flux:label>Service Category</flux:label>
                <flux:select wire:model="editServiceCategoryId" placeholder="Select service...">
                    @foreach (ServiceCategory::active()->ordered()->get() as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editServiceCategoryId" />
            </flux:field>

            <flux:field>
                <flux:label>Specialties</flux:label>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach (['interior', 'exterior', 'deck', 'cabinet', 'commercial', 'residential', 'trim', 'stain'] as $specialty)
                        <button
                            type="button"
                            wire:click="toggleSpecialty('{{ $specialty }}')"
                            class="px-3 py-1 rounded-full text-sm {{ in_array($specialty, $editSpecialties) ? 'bg-zinc-800 text-white dark:bg-white dark:text-zinc-800' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300' }}"
                        >
                            {{ ucfirst($specialty) }}
                        </button>
                    @endforeach
                </div>
            </flux:field>

            <flux:field>
                <flux:label>Bio</flux:label>
                <flux:textarea wire:model="editBio" rows="3" />
                <flux:error name="editBio" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showEditProfileModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="saveProfile" variant="primary">Save Profile</flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Edit Compliance Modal -->
    <flux:modal wire:model="showComplianceModal" variant="flyout" class="max-w-xl">
        <div class="space-y-6">
            <flux:heading size="lg">Edit Compliance Information</flux:heading>

            <flux:separator />

            <flux:heading size="sm">Insurance</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Insurance Company</flux:label>
                    <flux:input wire:model="editInsuranceCompany" />
                </flux:field>

                <flux:field>
                    <flux:label>Policy Number</flux:label>
                    <flux:input wire:model="editPolicyNumber" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Start Date</flux:label>
                    <flux:input wire:model="editInsuranceStartDate" type="date" />
                </flux:field>

                <flux:field>
                    <flux:label>Expiration Date</flux:label>
                    <flux:input wire:model="editInsuranceExpirationDate" type="date" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Insurance Document</flux:label>
                <div class="flex gap-4 mb-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editInsurancePhotoType" value="url" class="text-zinc-800">
                        <span class="text-sm">URL</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editInsurancePhotoType" value="file" class="text-zinc-800">
                        <span class="text-sm">Upload File</span>
                    </label>
                </div>
                @if ($editInsurancePhotoType === 'url')
                    <flux:input wire:model="editInsurancePhotoUrl" placeholder="https://..." />
                @else
                    <input type="file" wire:model="editInsurancePhotoFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300" accept=".pdf,.jpg,.jpeg,.png" />
                @endif
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">Workers' Compensation</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Carrier Name</flux:label>
                    <flux:input wire:model="editWorkersCompCarrier" />
                </flux:field>

                <flux:field>
                    <flux:label>Policy Number</flux:label>
                    <flux:input wire:model="editWorkersCompPolicyNumber" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Start Date</flux:label>
                    <flux:input wire:model="editWorkersCompStartDate" type="date" />
                </flux:field>

                <flux:field>
                    <flux:label>Expiration Date</flux:label>
                    <flux:input wire:model="editWorkersCompExpirationDate" type="date" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Workers' Comp Document</flux:label>
                <div class="flex gap-4 mb-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editWorkersCompDocumentType" value="url" class="text-zinc-800">
                        <span class="text-sm">URL</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editWorkersCompDocumentType" value="file" class="text-zinc-800">
                        <span class="text-sm">Upload File</span>
                    </label>
                </div>
                @if ($editWorkersCompDocumentType === 'url')
                    <flux:input wire:model="editWorkersCompDocumentUrl" placeholder="https://..." />
                @else
                    <input type="file" wire:model="editWorkersCompDocumentFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300" accept=".pdf,.jpg,.jpeg,.png" />
                @endif
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">W9</flux:heading>

            <flux:field>
                <flux:label>W9 Received Date</flux:label>
                <flux:input wire:model="editW9ReceivedDate" type="date" />
            </flux:field>

            <flux:field>
                <flux:label>W9 Document</flux:label>
                <div class="flex gap-4 mb-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editW9PhotoType" value="url" class="text-zinc-800">
                        <span class="text-sm">URL</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editW9PhotoType" value="file" class="text-zinc-800">
                        <span class="text-sm">Upload File</span>
                    </label>
                </div>
                @if ($editW9PhotoType === 'url')
                    <flux:input wire:model="editW9PhotoUrl" placeholder="https://..." />
                @else
                    <input type="file" wire:model="editW9PhotoFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300" accept=".pdf,.jpg,.jpeg,.png" />
                @endif
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">Contractor License</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>License Number</flux:label>
                    <flux:input wire:model="editLicenseNumber" />
                </flux:field>

                <flux:field>
                    <flux:label>Expiration Date</flux:label>
                    <flux:input wire:model="editLicenseExpirationDate" type="date" />
                </flux:field>
            </div>

            <flux:separator />

            <flux:field>
                <flux:label>Internal Notes</flux:label>
                <flux:textarea wire:model="editNotes" rows="3" placeholder="Add any notes about this crew..." />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showComplianceModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="saveCompliance" variant="primary">Save Compliance Info</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
