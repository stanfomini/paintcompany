<?php

use App\Models\CrewSignedContract;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Notifications\CrewCompliancePackNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
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

    // Email compliance pack
    public bool $showEmailComplianceModal = false;

    public string $complianceEmailRecipient = '';

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
            'crewProfile.onboardingCallConfirmedBy',
            'crewJobs.deal.customer',
            'crewJobs.deal.property',
            'crewSignedContracts',
            'crewW9Form',
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
        session()->flash('success', __('Profile updated successfully.'));
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
        session()->flash('success', __('Compliance information updated successfully.'));
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
        session()->flash('success', __('Crew activated successfully.'));
    }

    public function deactivate(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->user->update(['is_active' => false]);
        $this->user->refresh();
        session()->flash('success', __('Crew deactivated successfully.'));
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

    #[Computed]
    public function signedContracts()
    {
        return $this->user->crewSignedContracts->sortBy('contract_type');
    }

    #[Computed]
    public function onboardingStep(): int
    {
        return $this->user->crewProfile?->getCurrentOnboardingStep() ?? 1;
    }

    #[Computed]
    public function onboardingProgress(): int
    {
        return $this->user->crewProfile?->getOnboardingProgressPercent() ?? 0;
    }

    public function confirmOnboardingCall(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->user->crewProfile->confirmOnboardingCall(auth()->user());
        $this->user->refresh();
        $this->user->load('crewProfile.onboardingCallConfirmedBy');
        session()->flash('success', __('Onboarding call confirmed successfully.'));
    }

    public function markOnboardingComplete(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->user->update(['needs_onboarding' => false]);
        $this->user->crewProfile->update(['onboarding_completed_at' => now()]);
        $this->user->refresh();
        session()->flash('success', __('Onboarding marked as complete.'));
    }

    public function emailCompliancePack(): void
    {
        abort_unless(auth()->user()->can('people.view'), 403);

        $this->validate([
            'complianceEmailRecipient' => 'required|email',
        ]);

        Notification::route('mail', $this->complianceEmailRecipient)
            ->notify(new CrewCompliancePackNotification($this->user));

        $this->showEmailComplianceModal = false;
        $this->complianceEmailRecipient = '';
        session()->flash('success', __('Compliance pack emailed to :email.', ['email' => $this->complianceEmailRecipient ?: __('recipient')]));
    }

    public function resetOnboarding(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->user->update(['needs_onboarding' => true]);
        $this->user->crewProfile->update(['onboarding_completed_at' => null]);
        $this->user->refresh();
        session()->flash('success', __('Onboarding has been reset. Crew must complete onboarding again.'));
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('crews.index')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <flux:heading size="xl">{{ $user->name }}</flux:heading>
                @if ($user->crewProfile?->serviceCategory)
                    <flux:badge size="sm" color="{{ $user->crewProfile->serviceCategory->color ?? 'zinc' }}">
                        {{ __($user->crewProfile->serviceCategory->name) }}
                    </flux:badge>
                @endif
                @if ($user->is_active)
                    <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                @endif
                @can('people.edit')
                    @if ($user->is_active)
                        <flux:button wire:click="deactivate" size="sm" variant="ghost" icon="x-circle" :wire:confirm="__('Are you sure you want to deactivate this crew? They will be unable to log in.')">
                            {{ __('Deactivate') }}
                        </flux:button>
                    @else
                        <flux:button wire:click="activate" size="sm" variant="primary" icon="check-circle">
                            {{ __('Activate') }}
                        </flux:button>
                    @endif
                @endcan
                @php
                    $complianceStatus = $user->crewProfile?->getComplianceStatus() ?? 'compliant';
                @endphp
                @if ($complianceStatus === 'expired')
                    <flux:badge size="sm" color="red">{{ __('Compliance Expired') }}</flux:badge>
                @elseif ($complianceStatus === 'warning')
                    <flux:badge size="sm" color="yellow">{{ __('Expiring Soon') }}</flux:badge>
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
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Crew Size') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->crewProfile?->crew_size ?? 1 }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Hourly Rate') }}</flux:text>
                <flux:heading size="xl" class="mt-1">
                    @if ($user->crewProfile?->hourly_rate)
                        ${{ number_format($user->crewProfile->hourly_rate, 2) }}
                    @else
                        -
                    @endif
                </flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Active Jobs') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->activeJobs->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Completed Jobs') }}</flux:text>
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
                    {{ __('Overview') }}
                </button>
                <button
                    wire:click="setTab('onboarding')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'onboarding' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Onboarding') }}
                    @if ($user->needs_onboarding)
                        <span class="ml-2 inline-flex items-center justify-center w-2 h-2 bg-amber-500 rounded-full"></span>
                    @endif
                </button>
                <button
                    wire:click="setTab('compliance')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'compliance' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Insurance & Compliance') }}
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
                        {{ __('Edit Profile') }}
                    </flux:button>
                </div>
            @endcan

            <div class="grid gap-6 md:grid-cols-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Contact Information') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->email }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->phone ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Company') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->company_name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Crew Details') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Crew Size') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->crew_size ?? 1 }} {{ Str::plural('person', $user->crewProfile?->crew_size ?? 1) }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Hourly Rate') }}</dt>
                            <dd class="mt-1">
                                <flux:text>
                                    @if ($user->crewProfile?->hourly_rate)
                                        ${{ number_format($user->crewProfile->hourly_rate, 2) }}/hr
                                    @else
                                        {{ __('Not set') }}
                                    @endif
                                </flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Crew Lead Bonus') }}</dt>
                            <dd class="mt-1">
                                <flux:text>
                                    @if ($user->crewProfile?->bonus_percent)
                                        {{ number_format($user->crewProfile->bonus_percent, 1) }}% {{ __('of job price (conditional)') }}
                                    @else
                                        {{ __('Not set') }}
                                    @endif
                                </flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Specialties') }}</dt>
                            <dd class="mt-1">
                                @if ($user->crewProfile?->specialties && count($user->crewProfile->specialties) > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($user->crewProfile->specialties as $specialty)
                                            <flux:badge size="sm">{{ ucfirst($specialty) }}</flux:badge>
                                        @endforeach
                                    </div>
                                @else
                                    <flux:text class="text-zinc-400">{{ __('None specified') }}</flux:text>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($user->crewProfile?->bio)
                <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Bio') }}</flux:heading>
                    <flux:text>{{ $user->crewProfile->bio }}</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'onboarding')
            <div class="space-y-6">
                {{-- Onboarding Status Card --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('Onboarding Status') }}</flux:heading>
                        @if ($user->needs_onboarding)
                            <flux:badge size="sm" color="amber">{{ __('In Progress') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                        @endif
                    </div>

                    @if ($user->needs_onboarding)
                        {{-- Progress Bar --}}
                        <div class="mb-4">
                            <div class="flex justify-between text-sm mb-1">
                                <flux:text class="text-zinc-500">Step {{ $this->onboardingStep }} of 5</flux:text>
                                <flux:text class="text-zinc-500">{{ $this->onboardingProgress }}%</flux:text>
                            </div>
                            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2">
                                <div class="bg-amber-500 h-2 rounded-full transition-all" style="width: {{ $this->onboardingProgress }}%"></div>
                            </div>
                        </div>

                        {{-- Step Checklist --}}
                        <div class="space-y-2">
                            @php
                                $step = $this->onboardingStep;
                                $profile = $user->crewProfile;
                            @endphp
                            <div class="flex items-center gap-2 text-sm">
                                <flux:icon name="check-circle" class="size-5 text-green-500" />
                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Pay preview viewed') }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                @if ($profile?->had_onboarding_call)
                                    <flux:icon name="check-circle" class="size-5 text-green-500" />
                                @else
                                    <flux:icon name="ellipsis-horizontal-circle" class="size-5 text-zinc-400" />
                                @endif
                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Onboarding call') }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                @if ($this->signedContracts->count() >= 3)
                                    <flux:icon name="check-circle" class="size-5 text-green-500" />
                                @else
                                    <flux:icon name="ellipsis-horizontal-circle" class="size-5 text-zinc-400" />
                                @endif
                                <span class="text-zinc-600 dark:text-zinc-400">Contracts signed ({{ $this->signedContracts->count() }}/3)</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                @if ($step >= 5)
                                    <flux:icon name="check-circle" class="size-5 text-green-500" />
                                @else
                                    <flux:icon name="ellipsis-horizontal-circle" class="size-5 text-zinc-400" />
                                @endif
                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Documents uploaded') }}</span>
                            </div>
                        </div>

                        @can('people.edit')
                            <div class="flex gap-3 mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:button wire:click="markOnboardingComplete" size="sm" variant="primary" :wire:confirm="__('This will skip remaining onboarding steps and grant full access. Continue?')">
                                    {{ __('Mark as Complete') }}
                                </flux:button>
                            </div>
                        @endcan
                    @else
                        <div class="flex items-center gap-2 text-sm">
                            <flux:icon name="check-circle" class="size-5 text-green-500" />
                            <span class="text-zinc-600 dark:text-zinc-400">
                                {{ __('Completed') }} {{ $user->crewProfile?->onboarding_completed_at?->format('M j, Y \a\t g:ia') }}
                            </span>
                        </div>

                        @can('people.edit')
                            <div class="flex gap-3 mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:button wire:click="resetOnboarding" size="sm" variant="ghost" :wire:confirm="__('This will require the crew member to go through onboarding again. Continue?')">
                                    {{ __('Reset Onboarding') }}
                                </flux:button>
                            </div>
                        @endcan
                    @endif
                </div>

                {{-- Onboarding Call --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('Onboarding Call') }}</flux:heading>
                        @if ($user->crewProfile?->had_onboarding_call)
                            <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Not Confirmed') }}</flux:badge>
                        @endif
                    </div>

                    @if ($user->crewProfile?->had_onboarding_call)
                        <dl class="space-y-2">
                            @if ($user->crewProfile?->onboardingCallConfirmedBy)
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Confirmed By') }}</dt>
                                    <dd class="mt-1">
                                        <flux:text>{{ $user->crewProfile->onboardingCallConfirmedBy->name }}</flux:text>
                                    </dd>
                                </div>
                            @endif
                            @if ($user->crewProfile?->onboarding_call_confirmed_at)
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Confirmed At') }}</dt>
                                    <dd class="mt-1">
                                        <flux:text>{{ $user->crewProfile->onboarding_call_confirmed_at->format('M j, Y \a\t g:ia') }}</flux:text>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    @else
                        <flux:text class="text-zinc-500 mb-4">{{ __('The crew member has not had their onboarding call yet.') }}</flux:text>
                        @can('people.edit')
                            <flux:button wire:click="confirmOnboardingCall" size="sm" variant="primary" icon="phone">
                                {{ __('Mark Call Complete') }}
                            </flux:button>
                        @endcan
                    @endif
                </div>

                {{-- Signed Contracts --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Signed Contracts') }}</flux:heading>

                    @if ($this->signedContracts->count() > 0)
                        <div class="space-y-4">
                            @foreach ($this->signedContracts as $contract)
                                <div wire:key="contract-{{ $contract->id }}" x-data="{ open: false }" class="border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                    <button type="button" @click="open = !open" class="flex w-full items-center justify-between px-4 py-3 text-left">
                                        <div class="flex items-center gap-3">
                                            <flux:icon name="document-check" class="size-5 text-green-500" />
                                            <div>
                                                <flux:text class="font-medium">{{ $contract->type_label }}</flux:text>
                                                <flux:text class="text-xs text-zinc-500">
                                                    Signed {{ $contract->signed_at->format('M j, Y \a\t g:ia') }}
                                                    by {{ $contract->signer_name }}
                                                </flux:text>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm" color="{{ $contract->signature_type === 'drawn' ? 'blue' : 'zinc' }}">
                                                {{ ucfirst($contract->signature_type) }}
                                            </flux:badge>
                                            <flux:icon.chevron-down class="size-4 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                        </div>
                                    </button>

                                    <div x-show="open" x-collapse>
                                        <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-3 space-y-3">
                                            {{-- Contract Content --}}
                                            @if ($contract->contract_content)
                                                <div>
                                                    <flux:text class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Contract Content') }}</flux:text>
                                                    <div class="max-h-48 overflow-y-auto rounded-lg bg-zinc-50 p-3 text-sm leading-relaxed text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 whitespace-pre-wrap">{{ $contract->contract_content }}</div>
                                                </div>
                                            @endif

                                            {{-- Signature Display --}}
                                            <div>
                                                <flux:text class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Signature') }}</flux:text>
                                                @if ($contract->signature_type === 'drawn')
                                                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-2 inline-block">
                                                        <img src="{{ $contract->signature_data }}" alt="Signature" class="h-16 max-w-xs" />
                                                    </div>
                                                @else
                                                    <div class="text-2xl text-zinc-900 dark:text-white" style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;">
                                                        {{ $contract->signature_data }}
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="grid grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Email') }}:</span>
                                                    <span class="ml-1">{{ $contract->signer_email }}</span>
                                                </div>
                                                <div>
                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('IP') }}:</span>
                                                    <span class="ml-1">{{ $contract->ip_address }}</span>
                                                </div>
                                            </div>

                                            <div class="pt-2">
                                                <flux:button variant="ghost" size="sm" icon="arrow-down-tray" href="{{ route('crews.documents.contract', $contract) }}" target="_blank">
                                                    {{ __('Download PDF') }}
                                                </flux:button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-6">
                            <flux:icon name="document" class="mx-auto h-10 w-10 text-zinc-400" />
                            <flux:text class="mt-2 text-zinc-500">{{ __('No contracts signed yet.') }}</flux:text>
                        </div>
                    @endif

                    @php
                        $signedTypes = $this->signedContracts->pluck('contract_type')->toArray();
                        $missingContracts = array_diff(
                            [CrewSignedContract::TYPE_COLORADO_COMPLIANCE, CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT, CrewSignedContract::TYPE_BACKGROUND_CHECK],
                            $signedTypes,
                        );
                    @endphp
                    @if (count($missingContracts) > 0)
                        <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:text class="text-sm text-zinc-500">
                                Missing: {{ implode(', ', array_map(fn ($t) => CrewSignedContract::TYPES[$t], $missingContracts)) }}
                            </flux:text>
                        </div>
                    @endif
                </div>

                {{-- W9 Form --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('W9 Form') }}</flux:heading>
                        @if ($user->crewW9Form)
                            <flux:badge size="sm" color="green">{{ __('On File') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Not Submitted') }}</flux:badge>
                        @endif
                    </div>

                    @if ($user->crewW9Form)
                        @php $w9 = $user->crewW9Form; @endphp
                        <dl class="grid grid-cols-2 gap-4">
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</dt>
                                <dd class="mt-1"><flux:text>{{ $w9->name }}</flux:text></dd>
                            </div>
                            @if ($w9->business_name)
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Business Name') }}</dt>
                                    <dd class="mt-1"><flux:text>{{ $w9->business_name }}</flux:text></dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Tax Classification') }}</dt>
                                <dd class="mt-1"><flux:text>{{ $w9->tax_classification_label }}</flux:text></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">TIN ({{ strtoupper($w9->tin_type) }})</dt>
                                <dd class="mt-1"><flux:text>{{ $w9->masked_tin }}</flux:text></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                                <dd class="mt-1"><flux:text>{{ $w9->address }}</flux:text></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('City/State/ZIP') }}</dt>
                                <dd class="mt-1"><flux:text>{{ $w9->city_state_zip }}</flux:text></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Signed') }}</dt>
                                <dd class="mt-1"><flux:text>{{ $w9->signed_at?->format('M j, Y') ?? '-' }}</flux:text></dd>
                            </div>
                        </dl>

                        {{-- W9 Signature --}}
                        @if ($w9->signature_data)
                            <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:text class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Signature') }}</flux:text>
                                @if ($w9->signature_type === 'drawn')
                                    <div class="inline-block rounded-lg border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                                        <img src="{{ $w9->signature_data }}" alt="W-9 Signature" class="h-16 max-w-xs" />
                                    </div>
                                @else
                                    <div class="text-2xl text-zinc-900 dark:text-white" style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;">
                                        {{ $w9->signature_data }}
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:button variant="ghost" size="sm" icon="arrow-down-tray" href="{{ route('crews.documents.w9', $user) }}" target="_blank">
                                {{ __('Download W-9 PDF') }}
                            </flux:button>
                        </div>
                    @else
                        <div class="text-center py-6">
                            <flux:icon name="document" class="mx-auto h-10 w-10 text-zinc-400" />
                            <flux:text class="mt-2 text-zinc-500">{{ __('W9 form has not been submitted yet.') }}</flux:text>
                        </div>
                    @endif
                </div>

                {{-- Driver's License --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('Driver\'s License') }}</flux:heading>
                        @if ($user->crewProfile?->drivers_license_front)
                            <flux:badge size="sm" color="green">{{ __('On File') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Not Uploaded') }}</flux:badge>
                        @endif
                    </div>

                    @if ($user->crewProfile?->drivers_license_front || $user->crewProfile?->drivers_license_back)
                        <div class="grid gap-4 sm:grid-cols-2">
                            @if ($user->crewProfile->drivers_license_front)
                                <div>
                                    <flux:text class="text-sm text-zinc-500 mb-2">{{ __('Front') }}</flux:text>
                                    <a href="{{ Storage::url($user->crewProfile->drivers_license_front) }}" target="_blank" class="block">
                                        <img src="{{ Storage::url($user->crewProfile->drivers_license_front) }}" alt="{{ __('Driver\'s License Front') }}" class="h-32 w-auto rounded-lg border border-zinc-200 object-cover shadow-sm transition hover:shadow-md dark:border-zinc-700" />
                                    </a>
                                </div>
                            @endif
                            @if ($user->crewProfile->drivers_license_back)
                                <div>
                                    <flux:text class="text-sm text-zinc-500 mb-2">{{ __('Back') }}</flux:text>
                                    <a href="{{ Storage::url($user->crewProfile->drivers_license_back) }}" target="_blank" class="block">
                                        <img src="{{ Storage::url($user->crewProfile->drivers_license_back) }}" alt="{{ __('Driver\'s License Back') }}" class="h-32 w-auto rounded-lg border border-zinc-200 object-cover shadow-sm transition hover:shadow-md dark:border-zinc-700" />
                                    </a>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="text-center py-6">
                            <flux:icon name="identification" class="mx-auto h-10 w-10 text-zinc-400" />
                            <flux:text class="mt-2 text-zinc-500">{{ __('No driver\'s license uploaded yet.') }}</flux:text>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($activeTab === 'compliance')
            <div class="flex items-center justify-between mb-4">
                <div class="flex gap-2">
                    <flux:button variant="primary" size="sm" icon="arrow-down-tray" href="{{ route('crews.documents.compliance-pack', $user) }}" target="_blank">
                        {{ __('Download Compliance Pack') }}
                    </flux:button>
                    <flux:button wire:click="$set('showEmailComplianceModal', true)" size="sm" icon="envelope" variant="ghost">
                        {{ __('Email Compliance Pack') }}
                    </flux:button>
                </div>
                @can('people.edit')
                    <flux:button wire:click="openComplianceModal" size="sm" icon="pencil">
                        {{ __('Edit Compliance Info') }}
                    </flux:button>
                @endcan
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <!-- Insurance Information -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('Insurance') }}</flux:heading>
                        @if ($user->crewProfile?->isInsuranceExpired())
                            <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                        @elseif ($user->crewProfile?->isInsuranceExpiringSoon())
                            <flux:badge size="sm" color="yellow">{{ __('Expiring Soon') }}</flux:badge>
                        @elseif ($user->crewProfile?->insurance_expiration_date)
                            <flux:badge size="sm" color="green">{{ __('Valid') }}</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Insurance Company') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->insurance_company ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Policy Number') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->policy_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Coverage Period') }}</dt>
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
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Document') }}</dt>
                                <dd class="mt-1">
                                    @if ($user->crewProfile->insurance_photo_type === 'url')
                                        <a href="{{ $user->crewProfile->insurance_photo }}" target="_blank" class="text-blue-600 hover:underline">
                                            {{ __('View Insurance Document') }}
                                        </a>
                                    @else
                                        <a href="{{ Storage::url($user->crewProfile->insurance_photo) }}" target="_blank" class="text-blue-600 hover:underline">
                                            {{ __('View Insurance Document') }}
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
                        <flux:heading size="sm">{{ __('Workers\' Compensation') }}</flux:heading>
                        @if ($user->crewProfile?->isWorkersCompExpired())
                            <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                        @elseif ($user->crewProfile?->isWorkersCompExpiringSoon())
                            <flux:badge size="sm" color="yellow">{{ __('Expiring Soon') }}</flux:badge>
                        @elseif ($user->crewProfile?->workers_comp_expiration_date)
                            <flux:badge size="sm" color="green">{{ __('Valid') }}</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Carrier') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->workers_comp_carrier ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Policy Number') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->workers_comp_policy_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Coverage Period') }}</dt>
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
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Document') }}</dt>
                                <dd class="mt-1">
                                    @if ($user->crewProfile->workers_comp_document_type === 'url')
                                        <a href="{{ $user->crewProfile->workers_comp_document }}" target="_blank" class="text-blue-600 hover:underline">
                                            {{ __("View Workers' Comp Document") }}
                                        </a>
                                    @else
                                        <a href="{{ Storage::url($user->crewProfile->workers_comp_document) }}" target="_blank" class="text-blue-600 hover:underline">
                                            {{ __("View Workers' Comp Document") }}
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
                        <flux:heading size="sm">{{ __('Contractor License') }}</flux:heading>
                        @if ($user->crewProfile?->isLicenseExpired())
                            <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                        @elseif ($user->crewProfile?->isLicenseExpiringSoon())
                            <flux:badge size="sm" color="yellow">{{ __('Expiring Soon') }}</flux:badge>
                        @elseif ($user->crewProfile?->license_expiration_date)
                            <flux:badge size="sm" color="green">{{ __('Valid') }}</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('License Number') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->license_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Expiration Date') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->crewProfile?->license_expiration_date?->format('M j, Y') ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- W9 Information -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('W-9') }}</flux:heading>
                        @if ($user->crewW9Form)
                            <flux:badge size="sm" color="green">{{ __('On File') }}</flux:badge>
                        @endif
                    </div>
                    @if ($user->crewW9Form)
                        @php $w9c = $user->crewW9Form; @endphp
                        <dl class="space-y-3">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</dt>
                                    <dd class="mt-1"><flux:text>{{ $w9c->name }}</flux:text></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Tax Classification') }}</dt>
                                    <dd class="mt-1"><flux:text>{{ $w9c->tax_classification_label }}</flux:text></dd>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">TIN ({{ strtoupper($w9c->tin_type) }})</dt>
                                    <dd class="mt-1"><flux:text>{{ $w9c->masked_tin }}</flux:text></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Signed') }}</dt>
                                    <dd class="mt-1"><flux:text>{{ $w9c->signed_at?->format('M j, Y') ?? '-' }}</flux:text></dd>
                                </div>
                            </div>
                            @if ($w9c->signature_data)
                                <div>
                                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Signature') }}</dt>
                                    <dd>
                                        @if ($w9c->signature_type === 'drawn')
                                            <div class="inline-block rounded-lg border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                                                <img src="{{ $w9c->signature_data }}" alt="W-9 Signature" class="h-12 max-w-xs" />
                                            </div>
                                        @else
                                            <div class="text-2xl text-zinc-900 dark:text-white" style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;">
                                                {{ $w9c->signature_data }}
                                            </div>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>
                        <div class="mt-3">
                            <flux:button variant="ghost" size="sm" icon="arrow-down-tray" href="{{ route('crews.documents.w9', $user) }}" target="_blank">
                                {{ __('Download W-9 PDF') }}
                            </flux:button>
                        </div>
                    @else
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Received Date') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $user->crewProfile?->w9_received_date?->format('M j, Y') ?? '-' }}</flux:text>
                                </dd>
                            </div>
                        </dl>
                    @endif
                </div>

                <!-- Internal Notes -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Internal Notes') }}</flux:heading>
                    <flux:text class="whitespace-pre-line">{{ $user->crewProfile?->notes ?? __('No notes added.') }}</flux:text>
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
                                    {{ __('Job #') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Title / Customer') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Property') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Stage') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Scheduled Date') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Actions') }}
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
                                            {{ __('View') }}
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
                    <flux:heading size="sm" class="mt-4">{{ __('No active jobs') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('This crew has no active jobs assigned.') }}</flux:text>
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
                                    {{ __('Job #') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Title / Customer') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Property') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Completed Date') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Actions') }}
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
                                            {{ __('View') }}
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
                    <flux:heading size="sm" class="mt-4">{{ __('No completed jobs') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('This crew has not completed any jobs yet.') }}</flux:text>
                </div>
            @endif
        @endif
    </div>

    <!-- Edit Profile Modal -->
    <flux:modal wire:model="showEditProfileModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Edit Profile') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="editName" />
                <flux:error name="editName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Company Name') }}</flux:label>
                <flux:input wire:model="editCompanyName" />
                <flux:error name="editCompanyName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Phone') }}</flux:label>
                <flux:input wire:model="editPhone" type="tel" />
                <flux:error name="editPhone" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Crew Size') }}</flux:label>
                <flux:input wire:model="editCrewSize" type="number" min="1" max="20" />
                <flux:error name="editCrewSize" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Hourly Rate') }}</flux:label>
                <flux:input wire:model="editHourlyRate" type="number" step="0.01" min="0" prefix="$" />
                <flux:error name="editHourlyRate" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Crew Lead Bonus (%)') }}</flux:label>
                <flux:input wire:model="editBonusPercent" type="number" step="0.5" min="0" max="100" />
                <flux:text class="text-xs text-zinc-500 mt-1">{{ __('% of job price paid to crew lead if they meet contract standards') }}</flux:text>
                <flux:error name="editBonusPercent" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Service Category') }}</flux:label>
                <flux:select wire:model="editServiceCategoryId" placeholder="{{ __('Select service...') }}">
                    @foreach (ServiceCategory::active()->ordered()->get() as $category)
                        <flux:select.option value="{{ $category->id }}">{{ __($category->name) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editServiceCategoryId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Specialties') }}</flux:label>
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
                <flux:label>{{ __('Bio') }}</flux:label>
                <flux:textarea wire:model="editBio" rows="3" />
                <flux:error name="editBio" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showEditProfileModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="saveProfile" variant="primary">{{ __('Save Profile') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Edit Compliance Modal -->
    <flux:modal wire:model="showComplianceModal" variant="flyout" class="max-w-xl">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Edit Compliance Information') }}</flux:heading>

            <flux:separator />

            <flux:heading size="sm">{{ __('Insurance') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Insurance Company') }}</flux:label>
                    <flux:input wire:model="editInsuranceCompany" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Policy Number') }}</flux:label>
                    <flux:input wire:model="editPolicyNumber" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Start Date') }}</flux:label>
                    <flux:input wire:model="editInsuranceStartDate" type="date" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Expiration Date') }}</flux:label>
                    <flux:input wire:model="editInsuranceExpirationDate" type="date" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Insurance Document') }}</flux:label>
                <div class="flex gap-4 mb-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editInsurancePhotoType" value="url" class="text-zinc-800">
                        <span class="text-sm">{{ __('URL') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editInsurancePhotoType" value="file" class="text-zinc-800">
                        <span class="text-sm">{{ __('Upload File') }}</span>
                    </label>
                </div>
                @if ($editInsurancePhotoType === 'url')
                    <flux:input wire:model="editInsurancePhotoUrl" placeholder="https://..." />
                @else
                    <input type="file" wire:model="editInsurancePhotoFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300" accept=".pdf,.jpg,.jpeg,.png" />
                @endif
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">{{ __('Workers\' Compensation') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Carrier Name') }}</flux:label>
                    <flux:input wire:model="editWorkersCompCarrier" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Policy Number') }}</flux:label>
                    <flux:input wire:model="editWorkersCompPolicyNumber" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Start Date') }}</flux:label>
                    <flux:input wire:model="editWorkersCompStartDate" type="date" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Expiration Date') }}</flux:label>
                    <flux:input wire:model="editWorkersCompExpirationDate" type="date" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Workers\' Comp Document') }}</flux:label>
                <div class="flex gap-4 mb-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editWorkersCompDocumentType" value="url" class="text-zinc-800">
                        <span class="text-sm">{{ __('URL') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editWorkersCompDocumentType" value="file" class="text-zinc-800">
                        <span class="text-sm">{{ __('Upload File') }}</span>
                    </label>
                </div>
                @if ($editWorkersCompDocumentType === 'url')
                    <flux:input wire:model="editWorkersCompDocumentUrl" placeholder="https://..." />
                @else
                    <input type="file" wire:model="editWorkersCompDocumentFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300" accept=".pdf,.jpg,.jpeg,.png" />
                @endif
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">{{ __('W9') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('W9 Received Date') }}</flux:label>
                <flux:input wire:model="editW9ReceivedDate" type="date" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('W9 Document') }}</flux:label>
                <div class="flex gap-4 mb-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editW9PhotoType" value="url" class="text-zinc-800">
                        <span class="text-sm">{{ __('URL') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="editW9PhotoType" value="file" class="text-zinc-800">
                        <span class="text-sm">{{ __('Upload File') }}</span>
                    </label>
                </div>
                @if ($editW9PhotoType === 'url')
                    <flux:input wire:model="editW9PhotoUrl" placeholder="https://..." />
                @else
                    <input type="file" wire:model="editW9PhotoFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300" accept=".pdf,.jpg,.jpeg,.png" />
                @endif
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">{{ __('Contractor License') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('License Number') }}</flux:label>
                    <flux:input wire:model="editLicenseNumber" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Expiration Date') }}</flux:label>
                    <flux:input wire:model="editLicenseExpirationDate" type="date" />
                </flux:field>
            </div>

            <flux:separator />

            <flux:field>
                <flux:label>{{ __('Internal Notes') }}</flux:label>
                <flux:textarea wire:model="editNotes" rows="3" placeholder="{{ __('Add any notes about this crew...') }}" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showComplianceModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="saveCompliance" variant="primary">{{ __('Save Compliance Info') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Email Compliance Pack Modal -->
    <flux:modal wire:model="showEmailComplianceModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Email Compliance Pack') }}</flux:heading>
            <flux:text class="text-zinc-500">{{ __('Send the compliance pack PDF for :name to the email address below.', ['name' => $user->name]) }}</flux:text>

            <flux:field>
                <flux:label>{{ __('Recipient Email') }}</flux:label>
                <flux:input wire:model="complianceEmailRecipient" type="email" :placeholder="__('email@example.com')" />
                <flux:error name="complianceEmailRecipient" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-2">
                <flux:button wire:click="$set('showEmailComplianceModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="emailCompliancePack" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
