<?php

use App\Models\CompanySetting;
use App\Models\CrewSignedContract;
use App\Models\CrewW9Form;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\CrewOnboardingCallRequested;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.crew')] class extends Component {
    use WithFileUploads;

    public int $currentStep = 1;

    // Step 2: Call
    public bool $callSelfConfirmed = false;
    public ?string $callDate = null;
    public ?string $callTimeSlot = null;
    public string $callNotes = '';

    // Step 3: Contracts
    public int $currentContractIndex = 0;
    public string $contractSignatureData = '';
    public string $contractSignatureType = 'typed';

    // Step 4: Documents
    public $insuranceFile = null;
    public $workersCompFile = null;
    public $driversLicenseFront = null;
    public $driversLicenseBack = null;

    // Step 4: Insurance metadata
    public string $insuranceCompany = '';
    public string $insurancePolicyNumber = '';
    public ?string $insuranceStartDate = null;
    public ?string $insuranceExpirationDate = null;

    // Step 4: Workers comp metadata
    public string $workersCompCarrier = '';
    public string $workersCompPolicyNumber = '';
    public ?string $workersCompStartDate = null;
    public ?string $workersCompExpirationDate = null;

    // Step 4: W9 form fields
    public string $w9Name = '';
    public string $w9BusinessName = '';
    public string $w9TaxClassification = 'individual';
    public string $w9LlcTaxClassification = '';
    public string $w9OtherClassification = '';
    public string $w9ExemptPayeeCode = '';
    public string $w9FatcaExemptionCode = '';
    public string $w9Address = '';
    public string $w9CityStateZip = '';
    public string $w9TinType = 'ssn';
    public string $w9Tin = '';
    public string $w9SignatureData = '';
    public string $w9SignatureType = 'typed';
    public bool $w9Certified = false;

    public function mount(): void
    {
        $user = auth('crew')->user();
        $profile = $user->crewProfile;

        if (! $user->needs_onboarding) {
            $this->redirect(route('crew.dashboard'));
            return;
        }

        // Determine starting step based on what's complete
        $this->currentStep = $profile ? $profile->getCurrentOnboardingStep() : 1;

        // Pre-fill insurance/workers comp metadata from profile
        if ($profile) {
            $this->insuranceCompany = $profile->insurance_company ?? '';
            $this->insurancePolicyNumber = $profile->policy_number ?? '';
            $this->insuranceStartDate = $profile->insurance_start_date?->format('Y-m-d');
            $this->insuranceExpirationDate = $profile->insurance_expiration_date?->format('Y-m-d');
            $this->workersCompCarrier = $profile->workers_comp_carrier ?? '';
            $this->workersCompPolicyNumber = $profile->workers_comp_policy_number ?? '';
            $this->workersCompStartDate = $profile->workers_comp_start_date?->format('Y-m-d');
            $this->workersCompExpirationDate = $profile->workers_comp_expiration_date?->format('Y-m-d');
        }

        // Pre-fill W9 if data exists
        $w9 = $user->crewW9Form;
        if ($w9) {
            $this->w9Name = $w9->name;
            $this->w9BusinessName = $w9->business_name ?? '';
            $this->w9TaxClassification = $w9->tax_classification;
            $this->w9LlcTaxClassification = $w9->llc_tax_classification ?? '';
            $this->w9OtherClassification = $w9->other_classification ?? '';
            $this->w9Address = $w9->address;
            $this->w9CityStateZip = $w9->city_state_zip;
            $this->w9TinType = $w9->tin_type;
        } else {
            $this->w9Name = $user->name;
        }

        // Pre-fill company info for W9 requester
        $settings = CompanySetting::getInstance();
        if (empty($this->w9Address)) {
            // Leave blank for crew to fill
        }
    }

    #[Computed]
    public function settings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function user()
    {
        return auth('crew')->user();
    }

    #[Computed]
    public function profile()
    {
        return $this->user->crewProfile;
    }

    #[Computed]
    public function signedContracts(): array
    {
        return $this->user->crewSignedContracts()->pluck('contract_type')->toArray();
    }

    #[Computed]
    public function contractTypes(): array
    {
        return [
            CrewSignedContract::TYPE_COLORADO_COMPLIANCE => [
                'label' => 'Colorado Contractor Compliance',
                'content' => $this->settings->getLocalizedContract('crew_colorado_compliance_contract') ?? '',
            ],
            CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT => [
                'label' => 'Contractor Agreement',
                'content' => $this->settings->getLocalizedContract('crew_contractor_agreement') ?? '',
            ],
            CrewSignedContract::TYPE_BACKGROUND_CHECK => [
                'label' => 'Background Check Consent',
                'content' => $this->settings->getLocalizedContract('crew_background_check_consent') ?? '',
            ],
        ];
    }

    #[Computed]
    public function unsignedContracts(): array
    {
        $signed = $this->signedContracts;

        return collect($this->contractTypes)
            ->filter(fn ($contract, $type) => ! in_array($type, $signed))
            ->toArray();
    }

    #[Computed]
    public function sampleEstimate(): ?Estimate
    {
        return \Database\Seeders\SampleEstimateSeeder::ensureExists();
    }

    // Step 1: Pitch
    public function completeStep1(): void
    {
        $this->currentStep = 2;
    }

    // Step 2: Call
    public function confirmCall(): void
    {
        $profile = $this->user->crewProfile;
        $profile->update([
            'had_onboarding_call' => true,
        ]);

        $this->callSelfConfirmed = true;
        $this->currentStep = 3;
    }

    public function skipCall(): void
    {
        // Allow progression but don't mark as confirmed
        $this->currentStep = 3;
    }

    public function scheduleCall(): void
    {
        $this->validate([
            'callDate' => 'required|date|after_or_equal:today',
            'callTimeSlot' => 'required|in:morning,afternoon,evening',
        ]);

        $timeMap = [
            'morning' => '09:00:00',
            'afternoon' => '13:00:00',
            'evening' => '17:00:00',
        ];

        $scheduledAt = Carbon::parse($this->callDate.' '.$timeMap[$this->callTimeSlot]);

        $profile = $this->user->crewProfile;
        $profile->update([
            'onboarding_call_scheduled_at' => $scheduledAt,
            'onboarding_call_notes' => $this->callNotes ?: null,
        ]);

        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $internalUser) {
            $internalUser->notify(new CrewOnboardingCallRequested(
                crew: $this->user,
                requestedAt: $scheduledAt,
                notes: $this->callNotes ?: null,
            ));
        }

        $this->currentStep = 3;
    }

    // Step 3: Contracts
    public function signContract(string $contractType): void
    {
        $this->validate([
            'contractSignatureData' => 'required|string|min:2',
        ], [
            'contractSignatureData.required' => __('Please provide your signature.'),
        ]);

        $contractTypes = $this->contractTypes;
        if (! isset($contractTypes[$contractType])) {
            return;
        }

        CrewSignedContract::sign(
            user: $this->user,
            contractType: $contractType,
            contractContent: $contractTypes[$contractType]['content'],
            signatureData: $this->contractSignatureData,
            signatureType: $this->contractSignatureType,
        );

        $this->contractSignatureData = '';
        $this->contractSignatureType = 'typed';

        // Check if all contracts are signed
        unset($this->signedContracts);
        unset($this->unsignedContracts);

        if ($this->user->crewSignedContracts()->count() >= 3) {
            $this->currentStep = 4;
        }
    }

    // Step 4: Documents
    public function uploadInsurance(): void
    {
        $this->validate([
            'insuranceFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'insuranceCompany' => 'required|string|max:255',
            'insurancePolicyNumber' => 'required|string|max:255',
            'insuranceStartDate' => 'required|date',
            'insuranceExpirationDate' => 'required|date|after:insuranceStartDate',
        ]);

        $path = $this->insuranceFile->store('crew-documents/insurance', 'public');
        $this->profile->update([
            'insurance_photo' => $path,
            'insurance_photo_type' => 'file',
            'insurance_company' => $this->insuranceCompany,
            'policy_number' => $this->insurancePolicyNumber,
            'insurance_start_date' => $this->insuranceStartDate,
            'insurance_expiration_date' => $this->insuranceExpirationDate,
        ]);
        $this->insuranceFile = null;
    }

    public function uploadWorkersComp(): void
    {
        $this->validate([
            'workersCompFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'workersCompCarrier' => 'required|string|max:255',
            'workersCompPolicyNumber' => 'required|string|max:255',
            'workersCompStartDate' => 'required|date',
            'workersCompExpirationDate' => 'required|date|after:workersCompStartDate',
        ]);

        $path = $this->workersCompFile->store('crew-documents/workers-comp', 'public');
        $this->profile->update([
            'workers_comp_document' => $path,
            'workers_comp_document_type' => 'file',
            'workers_comp_carrier' => $this->workersCompCarrier,
            'workers_comp_policy_number' => $this->workersCompPolicyNumber,
            'workers_comp_start_date' => $this->workersCompStartDate,
            'workers_comp_expiration_date' => $this->workersCompExpirationDate,
        ]);
        $this->workersCompFile = null;
    }

    public function uploadDriversLicense(): void
    {
        $this->validate([
            'driversLicenseFront' => 'required|file|mimes:jpg,jpeg,png|max:10240',
            'driversLicenseBack' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
        ]);

        $frontPath = $this->driversLicenseFront->store('crew-documents/license', 'public');
        $backPath = $this->driversLicenseBack?->store('crew-documents/license', 'public');

        $this->profile->update([
            'drivers_license_front' => $frontPath,
            'drivers_license_back' => $backPath,
        ]);

        $this->driversLicenseFront = null;
        $this->driversLicenseBack = null;
    }

    public function saveW9(): void
    {
        $this->validate([
            'w9Name' => 'required|string|max:255',
            'w9TaxClassification' => 'required|in:individual,c_corp,s_corp,partnership,trust,llc,other',
            'w9Address' => 'required|string|max:255',
            'w9CityStateZip' => 'required|string|max:255',
            'w9TinType' => 'required|in:ssn,ein',
            'w9Tin' => 'required|string|min:9|max:11',
            'w9SignatureData' => 'required|string|min:2',
            'w9Certified' => 'accepted',
        ], [
            'w9Certified.accepted' => __('You must certify the information is correct.'),
            'w9SignatureData.required' => __('Please provide your signature.'),
        ]);

        CrewW9Form::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'name' => $this->w9Name,
                'business_name' => $this->w9BusinessName ?: null,
                'tax_classification' => $this->w9TaxClassification,
                'llc_tax_classification' => $this->w9LlcTaxClassification ?: null,
                'other_classification' => $this->w9OtherClassification ?: null,
                'exempt_payee_code' => $this->w9ExemptPayeeCode ?: null,
                'fatca_exemption_code' => $this->w9FatcaExemptionCode ?: null,
                'address' => $this->w9Address,
                'city_state_zip' => $this->w9CityStateZip,
                'requester_name' => $this->settings->company_name,
                'tin_type' => $this->w9TinType,
                'tin' => $this->w9Tin,
                'signature_data' => $this->w9SignatureData,
                'signature_type' => $this->w9SignatureType,
                'signed_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]
        );

        // Also set w9_received_date on crew profile
        $this->profile->update(['w9_received_date' => now()]);
    }

    public function advanceToComplete(): void
    {
        $missing = $this->getMissingDocuments();

        if (count($missing) > 0) {
            $this->addError('documents', __('Please complete all required documents before continuing: :items', [
                'items' => implode(', ', $missing),
            ]));
            return;
        }

        $this->currentStep = 5;
    }

    // Step 5: Complete
    public function completeOnboarding(): void
    {
        $missing = $this->getMissingDocuments();

        if (count($missing) > 0) {
            $this->addError('documents', __('Please complete all required documents before finishing: :items', [
                'items' => implode(', ', $missing),
            ]));
            $this->currentStep = 4;
            return;
        }

        $this->user->update(['needs_onboarding' => false]);
        $this->profile->update(['onboarding_completed_at' => now()]);

        session()->flash('success', __('Welcome aboard! Your onboarding is complete.'));
        $this->redirect(route('crew.dashboard'));
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= 5) {
            if ($step === 5) {
                $this->advanceToComplete();
                return;
            }
            $this->currentStep = $step;
        }
    }

    /**
     * Get list of missing required documents for step 4 completion.
     */
    protected function getMissingDocuments(): array
    {
        $missing = [];
        $profile = $this->profile;
        $user = $this->user;

        if ($user->crewSignedContracts()->count() < 3) {
            $missing[] = __('all 3 contracts signed');
        }

        if (empty($profile->insurance_photo)) {
            $missing[] = __('insurance certificate');
        }

        if (empty($profile->workers_comp_document)) {
            $missing[] = __('workers comp certificate');
        }

        if (empty($profile->drivers_license_front)) {
            $missing[] = __("driver's license");
        }

        if (! $user->crewW9Form()->exists()) {
            $missing[] = __('W-9 form');
        }

        return $missing;
    }
};
?>

<div>
    {{-- Progress Stepper (always visible) --}}
    <div class="mx-auto {{ $currentStep === 1 ? 'max-w-4xl' : 'max-w-3xl' }} px-4 pt-8 sm:px-6 lg:px-8">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                @foreach([
                    1 => __('Opportunity'),
                    2 => __('Call'),
                    3 => __('Contracts'),
                    4 => __('Documents'),
                    5 => __('Complete'),
                ] as $step => $label)
                    <div class="flex flex-col items-center {{ $step < 5 ? 'flex-1' : '' }}">
                        <button
                            wire:click="goToStep({{ $step }})"
                            class="flex size-10 items-center justify-center rounded-full text-sm font-medium transition-colors
                                {{ $currentStep === $step
                                    ? 'bg-blue-600 text-white'
                                    : ($currentStep > $step
                                        ? 'bg-emerald-500 text-white'
                                        : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400') }}"
                        >
                            @if($currentStep > $step)
                                <flux:icon.check class="size-5" />
                            @else
                                {{ $step }}
                            @endif
                        </button>
                        <span class="mt-1.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
                    </div>
                    @if($step < 5)
                        <div class="mx-2 mt-[-20px] h-0.5 flex-1 {{ $currentStep > $step ? 'bg-emerald-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    {{-- STEP 1: The Opportunity --}}
    @if($currentStep === 1)
        <div class="mx-auto max-w-4xl space-y-6 px-4 pb-8 sm:px-6 lg:px-8">

            {{-- Welcome Banner --}}
            <div class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-8 text-white sm:px-8">
                <h1 class="text-center text-2xl font-bold sm:text-3xl">{{ __('Welcome to the Team!') }}</h1>
                <p class="mx-auto mt-3 max-w-2xl text-center text-blue-100">
                    {{ __('We do things differently. No surprises, no guesswork, and no nickel-and-diming. Here\'s exactly what working with us looks like.') }}
                </p>
            </div>

            {{-- How You Get Paid --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8">
                <flux:heading size="lg" class="mb-5">{{ __('How You Get Paid') }}</flux:heading>

                <div class="space-y-6">
                    {{-- Hourly Rate + Burden --}}
                    <div class="flex gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
                            <flux:icon name="banknotes" class="size-5 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Hourly Rate + Payroll Burden') }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                {!! __('We pay your crew an hourly rate <strong>plus</strong> a payroll burden to cover taxes, insurance, and workers\' comp. This means you get paid more than just the base rate.') !!}
                            </p>
                            <div class="mt-3 rounded-lg bg-emerald-50 p-4 dark:bg-emerald-900/20">
                                <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">{{ __('Example:') }}</p>
                                <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                                    {!! __('If we set the rate at <strong>$35/hr</strong> and the payroll burden is <strong>35%</strong> for tax, insurance, and workers\' comp &mdash; you actually get paid <strong>$47.25/hr</strong> per painter. That\'s the real number, not just the base.') !!}
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Paint at Market Costs --}}
                    <div class="flex gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30">
                            <flux:icon name="paint-brush" class="size-5 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Paint Estimated at Near Market Cost') }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                {!! __('We estimate paint at near retail pricing in the job breakdown. If you have supplier discounts or contractor pricing, <strong>you keep the difference</strong>. The work order tells you exactly what brand, product, and how many gallons &mdash; you just buy it and profit from your relationships.') !!}
                            </p>
                        </div>
                    </div>

                    {{-- Flexible Schedule --}}
                    <div class="flex gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                            <flux:icon name="calendar-days" class="size-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Pick Your Own Jobs') }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('Log in, see what\'s available, and accept the jobs that work for your schedule. You see the hours, days, and pay before you commit. No pressure, no mandatory assignments.') }}
                            </p>
                        </div>
                    </div>

                    {{-- Bonus for Using Tools --}}
                    <div class="flex gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/30">
                            <flux:icon name="star" class="size-5 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">{{ __('Bonus Pay for Working the Plan') }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('Every job comes with a plan. Use our built-in tools to follow it and you earn a bonus on top of your hourly rate. These tools are part of every job:') }}
                            </p>
                            <ul class="mt-2 space-y-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                                <li class="flex items-start gap-2">
                                    <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-purple-500" />
                                    <span>{!! __('<strong>Job prep tools</strong> &mdash; checklists and prep steps before you start painting') !!}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-purple-500" />
                                    <span>{!! __('<strong>Photo documentation</strong> &mdash; before, during, and after photos built right in (like CompanyCam, but free)') !!}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-purple-500" />
                                    <span>{!! __('<strong>Status updates</strong> &mdash; keep us and the customer informed as you work the plan') !!}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-purple-500" />
                                    <span>{!! __('<strong>Video walkthroughs</strong> &mdash; quick video updates right from the job site') !!}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            {{-- What We Expect --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8">
                <flux:heading size="lg" class="mb-5">{{ __('What We Expect') }}</flux:heading>
                <div class="space-y-4">
                    <div class="flex gap-3">
                        <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{!! __('<strong class="text-zinc-900 dark:text-white">Work the plan.</strong> Every job has a breakdown with rooms, surfaces, hours, and paint. Follow it and communicate through the app.') !!}</p>
                    </div>
                    <div class="flex gap-3">
                        <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{!! __('<strong class="text-zinc-900 dark:text-white">One app for everything.</strong> No juggling multiple logins or apps. Job details, photos, communication, and updates are all in one place.') !!}</p>
                    </div>
                    <div class="flex gap-3">
                        <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{!! __('<strong class="text-zinc-900 dark:text-white">No hidden costs or surprises.</strong> You see exactly what the job pays, what paint is needed, and how many hours before you accept.') !!}</p>
                    </div>
                    <div class="flex gap-3">
                        <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{!! __('<strong class="text-zinc-900 dark:text-white">Clean, professional service.</strong> We take care of our customers. Show up prepared, follow the plan, and deliver quality work.') !!}</p>
                    </div>
                </div>
            </div>

            {{-- Sample Job Introduction --}}
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-5 py-4 dark:border-blue-800 dark:bg-blue-900/20">
                <div class="flex items-start gap-3">
                    <flux:icon name="eye" class="mt-0.5 size-5 shrink-0 text-blue-600 dark:text-blue-400" />
                    <div>
                        <p class="font-medium text-blue-900 dark:text-blue-200">{{ __('Sample Job Breakdown') }}</p>
                        <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
                            {{ __('Below is a sample work order for a 4-room interior repaint. Every job you accept will look exactly like this -- rooms, surfaces, labor hours, paint products, crew schedule, and pay. Take a look, then continue when you\'re ready.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Full Painter Breakdown --}}
            @if($this->sampleEstimate)
                @livewire('estimates.painter-breakdown', ['estimate' => $this->sampleEstimate, 'previewMode' => true], key('sample-breakdown'))
            @endif

            {{-- Sticky Continue Button --}}
            <div class="sticky bottom-0 z-10 -mx-4 border-t border-zinc-200 bg-white/95 px-4 py-4 backdrop-blur-sm dark:border-zinc-700 dark:bg-zinc-800/95 sm:-mx-6 sm:px-6">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Step :step of 5', ['step' => 1]) }}</span>
                    <flux:button wire:click="completeStep1" variant="primary">
                        {{ __('Looks Great -- Continue') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @else

    {{-- Steps 2-5: Normal card layout --}}
    <div class="mx-auto max-w-3xl px-4 pb-8 sm:px-6 lg:px-8">

            {{-- Step Content --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8">

                {{-- STEP 2: Onboarding Call --}}
                @if($currentStep === 2)
                    <div class="space-y-6">
                        <div class="text-center">
                            <flux:heading size="xl">{{ __('Onboarding Call') }}</flux:heading>
                            <flux:subheading class="mt-2">
                                {{ __('We\'d love to chat with you before you start taking jobs.') }}
                            </flux:subheading>
                        </div>

                        @if($this->profile->had_onboarding_call)
                            <div class="rounded-lg bg-emerald-50 p-6 text-center dark:bg-emerald-900/20">
                                <flux:icon.check-circle class="mx-auto size-12 text-emerald-500" />
                                <p class="mt-3 text-lg font-medium text-emerald-700 dark:text-emerald-300">{{ __('Call Confirmed!') }}</p>
                                <p class="text-sm text-emerald-600 dark:text-emerald-400">{{ __('Your onboarding call has been completed.') }}</p>
                            </div>
                        @elseif($this->profile->onboarding_call_scheduled_at)
                            <div class="rounded-lg bg-blue-50 p-6 text-center dark:bg-blue-900/20">
                                <flux:icon.calendar-days class="mx-auto size-12 text-blue-500" />
                                <p class="mt-3 text-lg font-medium text-blue-700 dark:text-blue-300">{{ __('Call Scheduled') }}</p>
                                <p class="text-sm text-blue-600 dark:text-blue-400">
                                    {{ $this->profile->onboarding_call_scheduled_at->format('l, M j, Y \a\t g:i A') }}
                                </p>
                                <p class="mt-2 text-sm text-blue-600 dark:text-blue-400">{{ __('We\'ll be in touch to confirm the time.') }}</p>
                            </div>
                        @else
                            <div class="rounded-lg bg-zinc-50 p-6 dark:bg-zinc-800">
                                <div class="space-y-4">
                                    <div class="flex items-start gap-3">
                                        <flux:icon.clock class="mt-0.5 size-5 text-blue-500" />
                                        <div>
                                            <p class="font-medium text-zinc-900 dark:text-white">{{ __('What to Expect') }}</p>
                                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                                {{ __('A 15-20 minute call to discuss how jobs work, expectations, scheduling, and answer your questions.') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Scheduling Form --}}
                            <div class="space-y-4">
                                <flux:heading size="lg">{{ __('Pick a Time') }}</flux:heading>

                                <flux:field>
                                    <flux:label>{{ __('Preferred Date') }} <span class="text-red-500">*</span></flux:label>
                                    <flux:input wire:model="callDate" type="date" :min="now()->format('Y-m-d')" />
                                    @error('callDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </flux:field>

                                <div>
                                    <flux:label class="mb-2">{{ __('Preferred Time') }} <span class="text-red-500">*</span></flux:label>
                                    <div class="grid grid-cols-3 gap-3">
                                        @foreach([
                                            'morning' => __('Morning (8-11am)'),
                                            'afternoon' => __('Afternoon (12-3pm)'),
                                            'evening' => __('Evening (4-6pm)'),
                                        ] as $slot => $label)
                                            <label
                                                class="flex cursor-pointer items-center justify-center rounded-lg border-2 px-3 py-3 text-center text-sm font-medium transition-colors
                                                    {{ $callTimeSlot === $slot
                                                        ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-900/30 dark:text-blue-300'
                                                        : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' }}"
                                            >
                                                <input type="radio" wire:model.live="callTimeSlot" value="{{ $slot }}" class="sr-only" />
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('callTimeSlot') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <flux:field>
                                    <flux:label>{{ __('Notes (optional)') }}</flux:label>
                                    <flux:textarea wire:model="callNotes" :placeholder="__('Any questions or preferences for the call...')" rows="2" />
                                </flux:field>

                                <flux:button wire:click="scheduleCall" variant="primary" class="w-full">
                                    {{ __('Schedule Call') }}
                                </flux:button>
                            </div>
                        @endif

                        <div class="flex items-center justify-between pt-4">
                            <flux:button wire:click="goToStep(1)" variant="ghost">
                                {{ __('Back') }}
                            </flux:button>
                            <div class="flex gap-3">
                                @if($this->profile->had_onboarding_call || $this->profile->onboarding_call_scheduled_at)
                                    <flux:button wire:click="goToStep(3)" variant="primary">
                                        {{ __('Continue') }}
                                    </flux:button>
                                @else
                                    <flux:button wire:click="skipCall" variant="ghost">
                                        {{ __('Skip for Now') }}
                                    </flux:button>
                                    <flux:button wire:click="confirmCall" variant="primary">
                                        {{ __('I\'ve Had My Call') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </div>

                {{-- STEP 3: Contracts --}}
                @elseif($currentStep === 3)
                    <div class="space-y-6">
                        <div class="text-center">
                            <flux:heading size="xl">{{ __('Review & Sign Contracts') }}</flux:heading>
                            <flux:subheading class="mt-2">
                                {{ __('Please review and sign each contract to continue.') }}
                                {{ __(':signed of :total signed.', ['signed' => count($this->signedContracts), 'total' => count($this->contractTypes)]) }}
                            </flux:subheading>
                        </div>

                        {{-- Contract List --}}
                        @foreach($this->contractTypes as $type => $contract)
                            @php $isSigned = in_array($type, $this->signedContracts); @endphp
                            <div x-data="{ expanded: {{ !$isSigned && array_key_first($this->unsignedContracts) === $type ? 'true' : 'false' }} }"
                                 class="rounded-lg border {{ $isSigned ? 'border-emerald-200 dark:border-emerald-800' : 'border-zinc-200 dark:border-zinc-700' }}">
                                <button
                                    type="button"
                                    @click="expanded = !expanded"
                                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                                >
                                    <div class="flex items-center gap-3">
                                        @if($isSigned)
                                            <flux:icon.check-circle class="size-5 text-emerald-500" />
                                        @else
                                            <flux:icon.document-text class="size-5 text-zinc-400" />
                                        @endif
                                        <div>
                                            <p class="font-medium text-zinc-900 dark:text-white">{{ $contract['label'] }}</p>
                                            @if($isSigned)
                                                <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('Signed') }}</p>
                                            @else
                                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requires signature') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="expanded ? 'rotate-180' : ''" />
                                </button>

                                <div x-show="expanded" x-collapse>
                                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                                        {{-- Contract Content --}}
                                        <div class="mb-4 max-h-64 overflow-y-auto rounded-lg bg-zinc-50 p-4 text-sm leading-relaxed text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 whitespace-pre-wrap">{{ $contract['content'] ?: __('Contract content not yet configured.') }}</div>

                                        @if(! $isSigned && ! empty($contract['content']))
                                            {{-- Signature Area --}}
                                            <div class="space-y-4">
                                                {{-- Signature Type Toggle --}}
                                                <div>
                                                    <flux:label class="mb-2">{{ __('Your Signature') }}</flux:label>
                                                    <div class="mb-3 flex overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                                                        <button
                                                            type="button"
                                                            wire:click="$set('contractSignatureType', 'typed')"
                                                            class="flex-1 px-4 py-2 text-sm font-medium transition-colors {{ $contractSignatureType === 'typed' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400' }}"
                                                        >
                                                            {{ __('Type Signature') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            wire:click="$set('contractSignatureType', 'drawn')"
                                                            class="flex-1 border-l border-zinc-200 px-4 py-2 text-sm font-medium transition-colors dark:border-zinc-700 {{ $contractSignatureType === 'drawn' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400' }}"
                                                        >
                                                            {{ __('Draw Signature') }}
                                                        </button>
                                                    </div>

                                                    @if($contractSignatureType === 'typed')
                                                        <flux:input
                                                            wire:model.live="contractSignatureData"
                                                            type="text"
                                                            :placeholder="__('Type your full name')"
                                                        />
                                                        @if(strlen($contractSignatureData) > 0)
                                                            <div class="mt-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                                                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Preview:') }}</p>
                                                                <div class="mt-1 text-2xl text-zinc-900 dark:text-white" style="font-family: 'Brush Script MT', 'Segoe Script', cursive;">
                                                                    {{ $contractSignatureData }}
                                                                </div>
                                                            </div>
                                                        @endif
                                                    @else
                                                        <div
                                                            x-data="signaturePad()"
                                                            x-init="init()"
                                                            class="space-y-2"
                                                            wire:key="contract-sig-{{ $type }}"
                                                        >
                                                            <div class="relative">
                                                                <canvas
                                                                    x-ref="canvas"
                                                                    class="h-32 w-full cursor-crosshair touch-none rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800"
                                                                ></canvas>
                                                                <div
                                                                    x-show="isEmpty"
                                                                    class="pointer-events-none absolute inset-0 flex items-center justify-center"
                                                                >
                                                                    <span class="text-zinc-400 dark:text-zinc-500">{{ __('Sign here') }}</span>
                                                                </div>
                                                            </div>
                                                            <div class="flex justify-end">
                                                                <flux:button type="button" variant="ghost" size="sm" x-on:click="clear()">{{ __('Clear') }}</flux:button>
                                                            </div>
                                                            <input type="hidden" wire:model="contractSignatureData" x-ref="signatureInput" />
                                                        </div>
                                                    @endif

                                                    @error('contractSignatureData')
                                                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                <flux:button
                                                    wire:click="signContract('{{ $type }}')"
                                                    variant="primary"
                                                    class="w-full"
                                                    wire:loading.attr="disabled"
                                                    wire:target="signContract"
                                                >
                                                    <span wire:loading.remove wire:target="signContract('{{ $type }}')">{{ __('Sign :label', ['label' => $contract['label']]) }}</span>
                                                    <span wire:loading wire:target="signContract('{{ $type }}')">{{ __('Signing...') }}</span>
                                                </flux:button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div class="flex items-center justify-between pt-4">
                            <flux:button wire:click="goToStep(2)" variant="ghost">{{ __('Back') }}</flux:button>
                            @if(count($this->signedContracts) >= 3)
                                <flux:button wire:click="goToStep(4)" variant="primary">{{ __('Continue') }}</flux:button>
                            @endif
                        </div>
                    </div>

                {{-- STEP 4: Documents --}}
                @elseif($currentStep === 4)
                    <div class="space-y-6">
                        <div class="text-center">
                            <flux:heading size="xl">{{ __('Compliance Documents') }}</flux:heading>
                            <flux:subheading class="mt-2">
                                {{ __('Upload your compliance documents. Items already on file are marked complete.') }}
                            </flux:subheading>
                        </div>

                        {{-- Insurance Certificate --}}
                        <div class="rounded-lg border {{ $this->profile->insurance_photo ? 'border-emerald-200 dark:border-emerald-800' : 'border-red-200 dark:border-red-800' }} border-l-4 p-5"
                             x-data="{ open: {{ empty($this->profile->insurance_photo) ? 'true' : 'false' }} }">
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left">
                                <div class="flex items-center gap-3">
                                    @if($this->profile->insurance_photo)
                                        <div class="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                            <flux:icon.check-circle class="size-6 text-emerald-500" />
                                        </div>
                                    @else
                                        <div class="flex size-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                                            <flux:icon.document-arrow-up class="size-6 text-red-500 dark:text-red-400" />
                                        </div>
                                    @endif
                                    <div>
                                        <p class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Insurance Certificate') }}</p>
                                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Upload your general liability insurance certificate') }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($this->profile->insurance_photo)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ __('ON FILE') }}</span>
                                    @else
                                        <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ __('REQUIRED') }}</span>
                                    @endif
                                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                </div>
                            </button>
                            <div x-show="open" x-collapse class="mt-4">
                                <form wire:submit="uploadInsurance" class="space-y-3">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Insurance Company') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="insuranceCompany" :placeholder="__('e.g. State Farm')" />
                                            @error('insuranceCompany') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Policy Number') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="insurancePolicyNumber" :placeholder="__('e.g. POL-123456')" />
                                            @error('insurancePolicyNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Start Date') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="insuranceStartDate" type="date" />
                                            @error('insuranceStartDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Expiration Date') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="insuranceExpirationDate" type="date" />
                                            @error('insuranceExpirationDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                    </div>
                                    <div class="flex items-end gap-3">
                                        <flux:field class="flex-1">
                                            <flux:label>{{ __('Certificate Document') }} <span class="text-red-500">*</span></flux:label>
                                            <input type="file" wire:model="insuranceFile" accept=".pdf,.jpg,.jpeg,.png" class="text-sm" />
                                            @error('insuranceFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="insuranceFile,uploadInsurance">
                                            {{ __('Upload') }}
                                        </flux:button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- Workers Comp --}}
                        <div class="rounded-lg border {{ $this->profile->workers_comp_document ? 'border-emerald-200 dark:border-emerald-800' : 'border-amber-200 dark:border-amber-800' }} border-l-4 p-5"
                             x-data="{ open: {{ empty($this->profile->workers_comp_document) ? 'true' : 'false' }} }">
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left">
                                <div class="flex items-center gap-3">
                                    @if($this->profile->workers_comp_document)
                                        <div class="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                            <flux:icon.check-circle class="size-6 text-emerald-500" />
                                        </div>
                                    @else
                                        <div class="flex size-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                                            <flux:icon.document-arrow-up class="size-6 text-amber-500 dark:text-amber-400" />
                                        </div>
                                    @endif
                                    <div>
                                        <p class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Workers Comp Certificate') }}</p>
                                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Upload your workers compensation insurance certificate') }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($this->profile->workers_comp_document)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ __('ON FILE') }}</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('REQUIRED') }}</span>
                                    @endif
                                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                </div>
                            </button>
                            <div x-show="open" x-collapse class="mt-4">
                                <form wire:submit="uploadWorkersComp" class="space-y-3">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Carrier Name') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="workersCompCarrier" :placeholder="__('e.g. Pinnacol Assurance')" />
                                            @error('workersCompCarrier') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Policy Number') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="workersCompPolicyNumber" :placeholder="__('e.g. WC-789012')" />
                                            @error('workersCompPolicyNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Start Date') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="workersCompStartDate" type="date" />
                                            @error('workersCompStartDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Expiration Date') }} <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="workersCompExpirationDate" type="date" />
                                            @error('workersCompExpirationDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                    </div>
                                    <div class="flex items-end gap-3">
                                        <flux:field class="flex-1">
                                            <flux:label>{{ __('Certificate Document') }} <span class="text-red-500">*</span></flux:label>
                                            <input type="file" wire:model="workersCompFile" accept=".pdf,.jpg,.jpeg,.png" class="text-sm" />
                                            @error('workersCompFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="workersCompFile,uploadWorkersComp">
                                            {{ __('Upload') }}
                                        </flux:button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- Driver's License --}}
                        <div class="rounded-lg border {{ $this->profile->drivers_license_front ? 'border-emerald-200 dark:border-emerald-800' : 'border-amber-200 dark:border-amber-800' }} border-l-4 p-5"
                             x-data="{ open: {{ empty($this->profile->drivers_license_front) ? 'true' : 'false' }} }">
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left">
                                <div class="flex items-center gap-3">
                                    @if($this->profile->drivers_license_front)
                                        <div class="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                            <flux:icon.check-circle class="size-6 text-emerald-500" />
                                        </div>
                                    @else
                                        <div class="flex size-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                                            <flux:icon.identification class="size-6 text-amber-500 dark:text-amber-400" />
                                        </div>
                                    @endif
                                    <div>
                                        <p class="text-base font-semibold text-zinc-900 dark:text-white">{{ __("Driver's License") }}</p>
                                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Photo of the front of your license (back is optional)') }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($this->profile->drivers_license_front)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ __('ON FILE') }}</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('REQUIRED') }}</span>
                                    @endif
                                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                </div>
                            </button>
                            <div x-show="open" x-collapse class="mt-4">
                                <form wire:submit="uploadDriversLicense" class="space-y-3">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Front (required)') }}</flux:label>
                                            <input type="file" wire:model="driversLicenseFront" accept=".jpg,.jpeg,.png" class="text-sm" />
                                            @error('driversLicenseFront') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Back (optional)') }}</flux:label>
                                            <input type="file" wire:model="driversLicenseBack" accept=".jpg,.jpeg,.png" class="text-sm" />
                                            @error('driversLicenseBack') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                        </flux:field>
                                    </div>
                                    <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="driversLicenseFront,driversLicenseBack,uploadDriversLicense">
                                        {{ __('Upload License') }}
                                    </flux:button>
                                </form>
                            </div>
                        </div>

                        {{-- W9 Form --}}
                        @php $hasW9 = $this->user->crewW9Form()->exists(); @endphp
                        <div class="rounded-lg border {{ $hasW9 ? 'border-emerald-200 dark:border-emerald-800' : 'border-red-200 dark:border-red-800' }} border-l-4 p-5"
                             x-data="{ open: {{ !$hasW9 ? 'true' : 'false' }} }">
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left">
                                <div class="flex items-center gap-3">
                                    @if($hasW9)
                                        <div class="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                            <flux:icon.check-circle class="size-6 text-emerald-500" />
                                        </div>
                                    @else
                                        <div class="flex size-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                                            <flux:icon.document-text class="size-6 text-red-500 dark:text-red-400" />
                                        </div>
                                    @endif
                                    <div>
                                        <p class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('W-9 Form') }}</p>
                                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('IRS taxpayer identification form for independent contractors') }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($hasW9)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ __('ON FILE') }}</span>
                                    @else
                                        <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ __('REQUIRED') }}</span>
                                    @endif
                                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                </div>
                            </button>
                            <div x-show="open" x-collapse class="mt-4">
                                <form wire:submit="saveW9" class="space-y-4">
                                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Form W-9: Request for Taxpayer Identification Number') }}</p>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('IRS Form W-9 (Rev. March 2024)') }}</p>
                                    </div>

                                    {{-- Line 1: Name --}}
                                    <flux:field>
                                        <flux:label>{!! __('1. Name (as shown on your income tax return)') !!} <span class="text-red-500">*</span></flux:label>
                                        <flux:input wire:model="w9Name" />
                                        <flux:error name="w9Name" />
                                    </flux:field>

                                    {{-- Line 2: Business name --}}
                                    <flux:field>
                                        <flux:label>{{ __('2. Business name/disregarded entity name (if different)') }}</flux:label>
                                        <flux:input wire:model="w9BusinessName" />
                                    </flux:field>

                                    {{-- Line 3: Tax Classification --}}
                                    <flux:field>
                                        <flux:label>{!! __('3. Federal tax classification') !!} <span class="text-red-500">*</span></flux:label>
                                        <flux:select wire:model.live="w9TaxClassification">
                                            @foreach(\App\Models\CrewW9Form::TAX_CLASSIFICATIONS as $value => $label)
                                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:error name="w9TaxClassification" />
                                    </flux:field>

                                    @if($w9TaxClassification === 'llc')
                                        <flux:field>
                                            <flux:label>{{ __('LLC tax classification') }}</flux:label>
                                            <flux:select wire:model="w9LlcTaxClassification">
                                                <flux:select.option value="">{{ __('Select...') }}</flux:select.option>
                                                <flux:select.option value="C">{{ __('C - C corporation') }}</flux:select.option>
                                                <flux:select.option value="S">{{ __('S - S corporation') }}</flux:select.option>
                                                <flux:select.option value="P">{{ __('P - Partnership') }}</flux:select.option>
                                            </flux:select>
                                        </flux:field>
                                    @endif

                                    @if($w9TaxClassification === 'other')
                                        <flux:field>
                                            <flux:label>{{ __('Other classification') }}</flux:label>
                                            <flux:input wire:model="w9OtherClassification" :placeholder="__('Describe...')" />
                                        </flux:field>
                                    @endif

                                    {{-- Line 4: Exemptions --}}
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('4. Exempt payee code (if any)') }}</flux:label>
                                            <flux:input wire:model="w9ExemptPayeeCode" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('FATCA exemption code (if any)') }}</flux:label>
                                            <flux:input wire:model="w9FatcaExemptionCode" />
                                        </flux:field>
                                    </div>

                                    {{-- Line 5-6: Address --}}
                                    <flux:field>
                                        <flux:label>{!! __('5. Address (number, street, apt/suite)') !!} <span class="text-red-500">*</span></flux:label>
                                        <flux:input wire:model="w9Address" />
                                        <flux:error name="w9Address" />
                                    </flux:field>

                                    <flux:field>
                                        <flux:label>{!! __('6. City, state, and ZIP code') !!} <span class="text-red-500">*</span></flux:label>
                                        <flux:input wire:model="w9CityStateZip" :placeholder="__('Denver, CO 80202')" />
                                        <flux:error name="w9CityStateZip" />
                                    </flux:field>

                                    {{-- TIN --}}
                                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                        <flux:label class="mb-3">{!! __('Taxpayer Identification Number (TIN)') !!} <span class="text-red-500">*</span></flux:label>

                                        <div class="mb-3 flex gap-4">
                                            <label class="flex items-center gap-2">
                                                <input type="radio" wire:model.live="w9TinType" value="ssn" class="text-blue-600" />
                                                <span class="text-sm">{{ __('Social Security Number') }}</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="radio" wire:model.live="w9TinType" value="ein" class="text-blue-600" />
                                                <span class="text-sm">{{ __('Employer Identification Number') }}</span>
                                            </label>
                                        </div>

                                        <flux:input
                                            wire:model="w9Tin"
                                            type="password"
                                            :placeholder="$w9TinType === 'ssn' ? '___-__-____' : '__-_______'"
                                            maxlength="11"
                                        />
                                        <flux:error name="w9Tin" />
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('Your TIN is encrypted and stored securely.') }}
                                        </p>
                                    </div>

                                    {{-- Certification --}}
                                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                        <p class="mb-3 text-sm font-medium text-zinc-900 dark:text-white">{{ __('Certification') }}</p>
                                        <p class="mb-3 text-xs text-zinc-600 dark:text-zinc-400">
                                            {{ __('Under penalties of perjury, I certify that: (1) The number shown on this form is my correct taxpayer identification number, (2) I am not subject to backup withholding, (3) I am a U.S. citizen or other U.S. person, and (4) The FATCA code(s) entered on this form (if any) indicating that I am exempt from FATCA reporting is correct.') }}
                                        </p>
                                        <label class="flex items-start gap-2">
                                            <flux:checkbox wire:model.live="w9Certified" />
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('I certify that the information provided is correct.') }} <span class="text-red-500">*</span></span>
                                        </label>
                                        @error('w9Certified') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>

                                    {{-- Signature --}}
                                    <div>
                                        <flux:label class="mb-2">{!! __('Signature') !!} <span class="text-red-500">*</span></flux:label>
                                        <div class="mb-3 flex overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                                            <button type="button" wire:click="$set('w9SignatureType', 'typed')"
                                                class="flex-1 px-4 py-2 text-sm font-medium transition-colors {{ $w9SignatureType === 'typed' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                {{ __('Type') }}
                                            </button>
                                            <button type="button" wire:click="$set('w9SignatureType', 'drawn')"
                                                class="flex-1 border-l border-zinc-200 px-4 py-2 text-sm font-medium transition-colors dark:border-zinc-700 {{ $w9SignatureType === 'drawn' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                {{ __('Draw') }}
                                            </button>
                                        </div>

                                        @if($w9SignatureType === 'typed')
                                            <flux:input wire:model.live="w9SignatureData" type="text" :placeholder="__('Type your full name')" />
                                            @if(strlen($w9SignatureData) > 0)
                                                <div class="mt-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                                                    <div class="text-2xl text-zinc-900 dark:text-white" style="font-family: 'Brush Script MT', 'Segoe Script', cursive;">
                                                        {{ $w9SignatureData }}
                                                    </div>
                                                </div>
                                            @endif
                                        @else
                                            <div x-data="signaturePad()" x-init="init()" class="space-y-2" wire:key="w9-sig">
                                                <div class="relative">
                                                    <canvas x-ref="canvas" class="h-32 w-full cursor-crosshair touch-none rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800"></canvas>
                                                    <div x-show="isEmpty" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                                        <span class="text-zinc-400">{{ __('Sign here') }}</span>
                                                    </div>
                                                </div>
                                                <div class="flex justify-end">
                                                    <flux:button type="button" variant="ghost" size="sm" x-on:click="clear()">{{ __('Clear') }}</flux:button>
                                                </div>
                                                <input type="hidden" wire:model="w9SignatureData" x-ref="signatureInput" />
                                            </div>
                                        @endif
                                        @error('w9SignatureData') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>

                                    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="saveW9">
                                        <span wire:loading.remove wire:target="saveW9">{{ __('Submit W-9 Form') }}</span>
                                        <span wire:loading wire:target="saveW9">{{ __('Saving...') }}</span>
                                    </flux:button>
                                </form>
                            </div>
                        </div>

                        @error('documents')
                            <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                                <div class="flex items-start gap-3">
                                    <flux:icon name="exclamation-triangle" class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />
                                    <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                                </div>
                            </div>
                        @enderror

                        <div class="flex items-center justify-between pt-4">
                            <flux:button wire:click="goToStep(3)" variant="ghost">{{ __('Back') }}</flux:button>
                            <flux:button wire:click="advanceToComplete" variant="primary">
                                {{ __('Continue') }}
                            </flux:button>
                        </div>
                    </div>

                {{-- STEP 5: Complete --}}
                @elseif($currentStep === 5)
                    <div class="space-y-6 text-center">
                        <div>
                            <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                <flux:icon.check class="size-8 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <flux:heading size="xl">{{ __("You're All Set!") }}</flux:heading>
                            <flux:subheading class="mt-2">
                                {{ __('Your onboarding is complete. You can now browse and accept available jobs.') }}
                            </flux:subheading>
                        </div>

                        {{-- Summary --}}
                        <div class="mx-auto max-w-sm space-y-2 text-left">
                            <div class="flex items-center gap-2 text-sm">
                                <flux:icon.check-circle class="size-4 text-emerald-500" />
                                <span class="text-zinc-700 dark:text-zinc-300">{{ __('Pay structure reviewed') }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                <flux:icon.check-circle class="size-4 {{ $this->profile->had_onboarding_call ? 'text-emerald-500' : 'text-amber-500' }}" />
                                <span class="text-zinc-700 dark:text-zinc-300">
                                    {{ __('Onboarding call') }} {{ $this->profile->had_onboarding_call ? __('completed') : __('(pending team confirmation)') }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                <flux:icon.check-circle class="size-4 text-emerald-500" />
                                <span class="text-zinc-700 dark:text-zinc-300">{{ __(':count contracts signed', ['count' => count($this->signedContracts)]) }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                <flux:icon.check-circle class="size-4 text-emerald-500" />
                                <span class="text-zinc-700 dark:text-zinc-300">{{ __('Compliance documents uploaded') }}</span>
                            </div>
                        </div>

                        <flux:button wire:click="completeOnboarding" variant="primary">
                            {{ __('Go to Dashboard') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif
    {{-- Signature Pad Alpine Component --}}
    @script
    <script>
        Alpine.data('signaturePad', () => ({
            signaturePad: null,
            isEmpty: true,

            init() {
                this.$nextTick(() => {
                    this.initCanvas();
                    window.addEventListener('resize', () => this.resizeCanvas());
                });
            },

            initCanvas() {
                const canvas = this.$refs.canvas;
                if (!canvas || !window.SignaturePad) {
                    setTimeout(() => this.initCanvas(), 100);
                    return;
                }

                this.signaturePad = new SignaturePad(canvas, {
                    backgroundColor: 'rgba(255, 255, 255, 0)',
                    penColor: document.documentElement.classList.contains('dark') ? '#ffffff' : '#000000',
                    minWidth: 1,
                    maxWidth: 2.5,
                });

                this.resizeCanvas();

                this.signaturePad.addEventListener('beginStroke', () => {
                    this.isEmpty = false;
                });

                this.signaturePad.addEventListener('endStroke', () => {
                    this.updateSignatureData();
                });
            },

            resizeCanvas() {
                const canvas = this.$refs.canvas;
                if (!canvas) return;

                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                const rect = canvas.getBoundingClientRect();

                canvas.width = rect.width * ratio;
                canvas.height = rect.height * ratio;
                canvas.getContext('2d').scale(ratio, ratio);

                if (this.signaturePad) {
                    this.signaturePad.clear();
                    this.isEmpty = true;
                    if (this.$refs.signatureInput) {
                        this.$refs.signatureInput.value = '';
                        this.$wire.set(this.$refs.signatureInput.getAttribute('wire:model'), '');
                    }
                }
            },

            clear() {
                if (this.signaturePad) {
                    this.signaturePad.clear();
                    this.isEmpty = true;
                    if (this.$refs.signatureInput) {
                        this.$refs.signatureInput.value = '';
                        this.$wire.set(this.$refs.signatureInput.getAttribute('wire:model'), '');
                    }
                }
            },

            updateSignatureData() {
                if (this.signaturePad && !this.signaturePad.isEmpty()) {
                    const data = this.signaturePad.toDataURL('image/png');
                    if (this.$refs.signatureInput) {
                        this.$refs.signatureInput.value = data;
                        this.$wire.set(this.$refs.signatureInput.getAttribute('wire:model'), data);
                    }
                }
            }
        }));
    </script>
    @endscript
</div>
