<?php

use App\Models\EstimatePackage;
use App\Models\ProjectPreview;
use Livewire\Component;

new class extends Component
{
    public ProjectPreview $preview;

    public int $currentStep = 1;

    public bool $showInterstitial = false;

    // Step 1: What's driving this project?
    public string $step1Motivation = '';

    public string $step1Notes = '';

    // Step 2: What does your ideal result look like?
    public string $step2Outcome = '';

    public string $step2Notes = '';

    // Step 3: Which areas need attention?
    public array $step3Areas = [];

    public string $step3Notes = '';

    // Step 4: Anything we should know about?
    public array $step4Logistics = [];

    public string $step4Notes = '';

    // Step 5: How do you think about this investment?
    public string $step5Investment = '';

    public string $step5Timeline = '';

    public string $step5Notes = '';

    public bool $completed = false;

    public function mount(): void
    {
        $data = $this->preview->questionnaire_data ?? [];

        if ($this->preview->isQuestionnaireCompleted()) {
            $this->completed = true;
            $this->loadAllSteps($data);

            return;
        }

        $this->loadAllSteps($data);
        $this->currentStep = $this->getNextIncompleteStep($data);
    }

    public function nextStep(): void
    {
        $this->saveCurrentStep();

        if ($this->currentStep === 5) {
            $this->completeQuestionnaire();

            return;
        }

        $this->showInterstitial = true;
    }

    public function continueFromInterstitial(): void
    {
        $this->showInterstitial = false;
        $this->currentStep++;
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function selectMotivation(string $value): void
    {
        $this->step1Motivation = $value;
    }

    public function selectOutcome(string $value): void
    {
        $this->step2Outcome = $value;
    }

    public function toggleArea(string $value): void
    {
        if (in_array($value, $this->step3Areas)) {
            $this->step3Areas = array_values(array_diff($this->step3Areas, [$value]));
        } else {
            $this->step3Areas[] = $value;
        }
    }

    public function toggleLogistic(string $value): void
    {
        // "None" deselects everything else; selecting anything else deselects "none"
        if ($value === 'none') {
            $this->step4Logistics = ['none'];

            return;
        }

        $this->step4Logistics = array_values(array_diff($this->step4Logistics, ['none']));

        if (in_array($value, $this->step4Logistics)) {
            $this->step4Logistics = array_values(array_diff($this->step4Logistics, [$value]));
        } else {
            $this->step4Logistics[] = $value;
        }
    }

    public function selectInvestment(string $value): void
    {
        $this->step5Investment = $value;
    }

    public function selectTimeline(string $value): void
    {
        $this->step5Timeline = $value;
    }

    protected function saveCurrentStep(): void
    {
        $data = $this->preview->questionnaire_data ?? [];

        $stepData = match ($this->currentStep) {
            1 => ['motivation' => $this->step1Motivation, 'notes' => $this->step1Notes],
            2 => ['desired_outcome' => $this->step2Outcome, 'notes' => $this->step2Notes],
            3 => ['areas' => $this->step3Areas, 'notes' => $this->step3Notes],
            4 => ['logistics' => $this->step4Logistics, 'notes' => $this->step4Notes],
            5 => ['investment' => $this->step5Investment, 'timeline' => $this->step5Timeline, 'notes' => $this->step5Notes],
            default => [],
        };

        $data["step_{$this->currentStep}"] = $stepData;

        $this->preview->update(['questionnaire_data' => $data]);
    }

    protected function completeQuestionnaire(): void
    {
        $this->saveCurrentStep();

        $this->preview->update([
            'questionnaire_completed_at' => now(),
            'questionnaire_discount_percent' => 5.00,
        ]);

        // Apply discount to existing estimate packages if estimate already exists
        $this->applyDiscountToExistingPackages();

        $this->preview->refresh();
        $this->completed = true;

        $this->dispatch('questionnaire-completed');
    }

    protected function applyDiscountToExistingPackages(): void
    {
        $estimateId = $this->preview->estimate_id;

        if (! $estimateId && $this->preview->deal_id) {
            $estimateId = \App\Models\Estimate::where('deal_id', $this->preview->deal_id)->value('id');
        }

        if (! $estimateId) {
            return;
        }

        EstimatePackage::where('estimate_id', $estimateId)->each(function (EstimatePackage $package) {
            $package->update(['discount_percent' => 5.00]);
            $package->calculateTotals();
            $package->save();
        });
    }

    protected function loadAllSteps(array $data): void
    {
        if (! empty($data['step_1'])) {
            $this->step1Motivation = $data['step_1']['motivation'] ?? '';
            $this->step1Notes = $data['step_1']['notes'] ?? '';
        }

        if (! empty($data['step_2'])) {
            $this->step2Outcome = $data['step_2']['desired_outcome'] ?? '';
            $this->step2Notes = $data['step_2']['notes'] ?? '';
        }

        if (! empty($data['step_3'])) {
            $this->step3Areas = $data['step_3']['areas'] ?? [];
            $this->step3Notes = $data['step_3']['notes'] ?? '';
        }

        if (! empty($data['step_4'])) {
            $this->step4Logistics = $data['step_4']['logistics'] ?? [];
            $this->step4Notes = $data['step_4']['notes'] ?? '';
        }

        if (! empty($data['step_5'])) {
            $this->step5Investment = $data['step_5']['investment'] ?? '';
            $this->step5Timeline = $data['step_5']['timeline'] ?? '';
            $this->step5Notes = $data['step_5']['notes'] ?? '';
        }
    }

    protected function getNextIncompleteStep(array $data): int
    {
        for ($i = 1; $i <= 5; $i++) {
            if (empty($data["step_{$i}"])) {
                return $i;
            }
        }

        return 5;
    }
};
?>

<div class="w-full">
    @if($completed)
        {{-- ========================================== --}}
        {{-- COMPLETION CARD                            --}}
        {{-- ========================================== --}}
        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-2xl overflow-hidden shadow-sm">
            {{-- Celebration header --}}
            <div class="bg-gradient-to-br from-emerald-500 to-teal-600 px-6 py-8 text-center text-white">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-white/20 flex items-center justify-center">
                    <flux:icon name="check-circle" class="w-9 h-9 text-white" />
                </div>
                <flux:heading size="xl" class="!text-white">{{ __("You're all set!") }}</flux:heading>
                <p class="mt-2 text-emerald-100 text-sm">{{ __('Your project planner is complete.') }}</p>
            </div>

            <div class="p-6 space-y-5">
                {{-- Discount badge --}}
                <div class="flex items-center gap-4 p-5 rounded-xl bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-300 dark:border-amber-700">
                    <div class="w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center shrink-0">
                        <flux:icon name="tag" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <p class="font-bold text-amber-900 dark:text-amber-100 text-lg">{{ __("You've unlocked 5% off your estimate") }}</p>
                        <p class="text-sm text-amber-700 dark:text-amber-300 mt-0.5">{{ __('Applied automatically when your estimate is created.') }}</p>
                    </div>
                </div>

                {{-- Summary --}}
                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Your Answers') }}</flux:heading>

                    @php
                        $motivationLabels = [
                            'selling' => 'Selling / Staging',
                            'moved_in' => 'Just moved in',
                            'fresh_look' => 'Fresh look',
                            'wear_tear' => 'Wear & tear',
                            'remodeling' => 'Remodeling',
                            'other' => 'Other',
                        ];
                        $outcomeLabels = [
                            'modern_clean' => 'Modern & clean',
                            'warm_cozy' => 'Warm & cozy',
                            'bold_dramatic' => 'Bold & dramatic',
                            'classic_timeless' => 'Classic & timeless',
                            'match_existing' => 'Match existing',
                            'not_sure' => 'Not sure yet',
                            'want_options' => 'Want options',
                        ];
                        $areaLabels = [
                            'living_areas' => 'Living areas',
                            'bedrooms' => 'Bedrooms',
                            'kitchen' => 'Kitchen',
                            'bathrooms' => 'Bathrooms',
                            'hallways_stairs' => 'Hallways & stairs',
                            'exterior' => 'Exterior',
                            'trim_doors_cabinets' => 'Trim, doors & cabinets',
                            'ceiling_work' => 'Ceiling work',
                            'other' => 'Other',
                        ];
                        $investmentLabels = [
                            'affordable' => 'Affordable',
                            'good_quality' => 'Good quality',
                            'premium' => 'Premium',
                            'the_best' => 'The best',
                            'want_options' => 'Want options',
                        ];
                        $timelineLabels = [
                            'asap' => 'ASAP',
                            '2_4_weeks' => '2-4 weeks',
                            '1_3_months' => '1-3 months',
                            'flexible' => 'Flexible',
                        ];
                    @endphp

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-700 text-sm">
                        @if($step1Motivation)
                            <div class="flex items-start justify-between gap-4 py-2.5">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Driving this project') }}</span>
                                <span class="font-medium text-zinc-900 dark:text-white text-right">{{ $motivationLabels[$step1Motivation] ?? $step1Motivation }}</span>
                            </div>
                        @endif

                        @if($step2Outcome)
                            <div class="flex items-start justify-between gap-4 py-2.5">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Ideal result') }}</span>
                                <span class="font-medium text-zinc-900 dark:text-white text-right">{{ $outcomeLabels[$step2Outcome] ?? $step2Outcome }}</span>
                            </div>
                        @endif

                        @if(count($step3Areas) > 0)
                            <div class="flex items-start justify-between gap-4 py-2.5">
                                <span class="text-zinc-500 dark:text-zinc-400 shrink-0">{{ __('Areas') }}</span>
                                <div class="flex flex-wrap gap-1 justify-end">
                                    @foreach($step3Areas as $area)
                                        <flux:badge size="sm">{{ $areaLabels[$area] ?? $area }}</flux:badge>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if($step5Investment)
                            <div class="flex items-start justify-between gap-4 py-2.5">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Investment mindset') }}</span>
                                <span class="font-medium text-zinc-900 dark:text-white text-right">{{ $investmentLabels[$step5Investment] ?? $step5Investment }}</span>
                            </div>
                        @endif

                        @if($step5Timeline)
                            <div class="flex items-start justify-between gap-4 py-2.5">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Timeline') }}</span>
                                <span class="font-medium text-zinc-900 dark:text-white text-right">{{ $timelineLabels[$step5Timeline] ?? $step5Timeline }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Estimator note --}}
                <div class="flex items-start gap-3 p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                    <flux:icon name="user-circle" class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" />
                    <p class="text-sm text-blue-800 dark:text-blue-200">
                        {{ __('Your estimator will review your answers before your appointment so they can arrive fully prepared.') }}
                    </p>
                </div>
            </div>
        </div>
    @else
        {{-- ========================================== --}}
        {{-- QUESTIONNAIRE FLOW                         --}}
        {{-- ========================================== --}}

        {{-- Progress bar --}}
        <div class="mb-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Step :current of 5', ['current' => $currentStep]) }}</span>
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ ($currentStep - 1) * 20 }}%</span>
            </div>
            <div class="flex gap-1.5">
                @for($i = 1; $i <= 5; $i++)
                    <div class="h-1.5 flex-1 rounded-full transition-all duration-300 {{ $i < $currentStep ? 'bg-emerald-500' : ($i === $currentStep ? 'bg-blue-500' : 'bg-zinc-200 dark:bg-zinc-700') }}"></div>
                @endfor
            </div>
        </div>

        {{-- Discount teaser (only on step 1) --}}
        @if($currentStep === 1 && !$showInterstitial)
            <div class="mb-5 flex items-center gap-4 px-5 py-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-300 dark:border-amber-700">
                <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center shrink-0">
                    <flux:icon name="gift" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                </div>
                <p class="text-base text-amber-900 dark:text-amber-100">
                    {{ __('Complete this quick project planner and unlock') }}
                    <span class="font-bold text-amber-700 dark:text-amber-200">{{ __('5% off') }}</span>
                    {{ __('your estimate') }}
                </p>
            </div>
        @endif

        @if($showInterstitial)
            {{-- ========================================== --}}
            {{-- INTERSTITIAL CARD                          --}}
            {{-- ========================================== --}}
            @php
                $interstitials = [
                    1 => [
                        'icon' => 'clipboard-document-check',
                        'title' => __('We come prepared'),
                        'text' => __('We pre-plan every job. Your estimator arrives knowing your space, your goals, and exactly what needs to happen. No surprises.'),
                    ],
                    2 => [
                        'icon' => 'calendar-days',
                        'title' => __('Scheduled by the week'),
                        'text' => __("We schedule by week — you'll know the exact days your crew arrives. Most projects start within 1-2 weeks of approval."),
                    ],
                    3 => [
                        'icon' => 'document-text',
                        'title' => __('Transparent pricing'),
                        'text' => __('Every room gets a detailed, itemized estimate. No hidden fees, no vague "per room" pricing.'),
                    ],
                    4 => [
                        'icon' => 'home',
                        'title' => __('Your home stays livable'),
                        'text' => __('We move furniture, cover floors, and clean up every day. Your home stays livable throughout the project.'),
                    ],
                ];
                $card = $interstitials[$currentStep] ?? $interstitials[1];
            @endphp

            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-2xl overflow-hidden shadow-sm">
                <div class="px-8 py-10 sm:py-12">
                    <div class="max-w-lg mx-auto text-center">
                        <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center">
                            <flux:icon name="{{ $card['icon'] }}" class="w-8 h-8 text-zinc-700 dark:text-zinc-300" />
                        </div>
                        <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 dark:text-white leading-tight">
                            {{ $card['title'] }}
                        </h2>
                        <p class="mt-4 text-lg sm:text-xl leading-relaxed text-zinc-600 dark:text-zinc-300">
                            {{ $card['text'] }}
                        </p>
                    </div>
                </div>
                <div class="px-8 py-5 border-t border-zinc-100 dark:border-zinc-700 text-center">
                    <flux:button wire:click="continueFromInterstitial" variant="primary" class="w-full sm:w-auto text-base px-8 py-3">
                        {{ __('Continue') }}
                        <flux:icon name="arrow-right" variant="mini" class="w-5 h-5 ml-1.5" />
                    </flux:button>
                </div>
            </div>
        @else
            {{-- ========================================== --}}
            {{-- QUESTION CARDS                             --}}
            {{-- ========================================== --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-2xl overflow-hidden shadow-sm">

                {{-- STEP 1: What's driving this project? --}}
                @if($currentStep === 1)
                    <div class="p-6 space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __("What's driving this project?") }}</flux:heading>
                            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('This helps your estimator tailor the approach.') }}</flux:text>
                        </div>

                        @php
                            $motivations = [
                                'selling' => ['label' => __('Selling / Staging'), 'icon' => 'home-modern'],
                                'moved_in' => ['label' => __('Just moved in'), 'icon' => 'key'],
                                'fresh_look' => ['label' => __('Fresh look'), 'icon' => 'sparkles'],
                                'wear_tear' => ['label' => __('Wear & tear'), 'icon' => 'wrench-screwdriver'],
                                'remodeling' => ['label' => __('Remodeling'), 'icon' => 'squares-plus'],
                                'other' => ['label' => __('Other'), 'icon' => 'ellipsis-horizontal-circle'],
                            ];
                        @endphp

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($motivations as $key => $option)
                                <button
                                    wire:click="selectMotivation('{{ $key }}')"
                                    wire:key="motivation-{{ $key }}"
                                    type="button"
                                    class="flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all text-center
                                        {{ $step1Motivation === $key
                                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-500/30'
                                            : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-300 dark:hover:border-blue-700 bg-white dark:bg-zinc-800' }}"
                                >
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center
                                        {{ $step1Motivation === $key
                                            ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400'
                                            : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400' }}">
                                        <flux:icon name="{{ $option['icon'] }}" class="w-5 h-5" />
                                    </div>
                                    <span class="text-sm font-medium {{ $step1Motivation === $key ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                        {{ $option['label'] }}
                                    </span>
                                </button>
                            @endforeach
                        </div>

                        <div>
                            <flux:textarea
                                wire:model="step1Notes"
                                :label="__('Anything else about what\'s driving this?')"
                                placeholder="{{ __('Optional — share any details that would help us prepare...') }}"
                                rows="2"
                            />
                        </div>
                    </div>
                @endif

                {{-- STEP 2: What does your ideal result look like? --}}
                @if($currentStep === 2)
                    <div class="p-6 space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('What does your ideal result look like?') }}</flux:heading>
                            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('We can bring color ideas and inspiration to your appointment.') }}</flux:text>
                        </div>

                        @php
                            $outcomes = [
                                'modern_clean' => ['label' => __('Modern & clean'), 'icon' => 'cube-transparent'],
                                'warm_cozy' => ['label' => __('Warm & cozy'), 'icon' => 'fire'],
                                'bold_dramatic' => ['label' => __('Bold & dramatic'), 'icon' => 'bolt'],
                                'classic_timeless' => ['label' => __('Classic & timeless'), 'icon' => 'star'],
                                'match_existing' => ['label' => __('Match existing'), 'icon' => 'swatch'],
                                'not_sure' => ['label' => __('Not sure yet'), 'icon' => 'question-mark-circle'],
                                'want_options' => ['label' => __('Want options'), 'icon' => 'squares-2x2'],
                            ];
                        @endphp

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($outcomes as $key => $option)
                                <button
                                    wire:click="selectOutcome('{{ $key }}')"
                                    wire:key="outcome-{{ $key }}"
                                    type="button"
                                    class="flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all text-center
                                        {{ $step2Outcome === $key
                                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-500/30'
                                            : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-300 dark:hover:border-blue-700 bg-white dark:bg-zinc-800' }}"
                                >
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center
                                        {{ $step2Outcome === $key
                                            ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400'
                                            : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400' }}">
                                        <flux:icon name="{{ $option['icon'] }}" class="w-5 h-5" />
                                    </div>
                                    <span class="text-sm font-medium {{ $step2Outcome === $key ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                        {{ $option['label'] }}
                                    </span>
                                </button>
                            @endforeach
                        </div>

                        <div>
                            <flux:textarea
                                wire:model="step2Notes"
                                :label="__('Any specific ideas or inspiration?')"
                                placeholder="{{ __('Optional — Pinterest links, color names, photos you\'ve seen...') }}"
                                rows="2"
                            />
                        </div>
                    </div>
                @endif

                {{-- STEP 3: Which areas need attention? --}}
                @if($currentStep === 3)
                    <div class="p-6 space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Which areas need attention?') }}</flux:heading>
                            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Select all that apply. This helps us scope the project.') }}</flux:text>
                        </div>

                        @php
                            $areas = [
                                'living_areas' => ['label' => __('Living areas'), 'icon' => 'tv'],
                                'bedrooms' => ['label' => __('Bedrooms'), 'icon' => 'moon'],
                                'kitchen' => ['label' => __('Kitchen'), 'icon' => 'fire'],
                                'bathrooms' => ['label' => __('Bathrooms'), 'icon' => 'beaker'],
                                'hallways_stairs' => ['label' => __('Hallways & stairs'), 'icon' => 'arrows-right-left'],
                                'exterior' => ['label' => __('Exterior'), 'icon' => 'home'],
                                'trim_doors_cabinets' => ['label' => __('Trim, doors & cabinets'), 'icon' => 'rectangle-group'],
                                'ceiling_work' => ['label' => __('Ceiling work'), 'icon' => 'arrow-up-circle'],
                                'other' => ['label' => __('Other'), 'icon' => 'ellipsis-horizontal-circle'],
                            ];
                        @endphp

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($areas as $key => $option)
                                @php $isSelected = in_array($key, $step3Areas); @endphp
                                <button
                                    wire:click="toggleArea('{{ $key }}')"
                                    wire:key="area-{{ $key }}"
                                    type="button"
                                    class="relative flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all text-center
                                        {{ $isSelected
                                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-500/30'
                                            : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-300 dark:hover:border-blue-700 bg-white dark:bg-zinc-800' }}"
                                >
                                    @if($isSelected)
                                        <div class="absolute top-2 right-2">
                                            <flux:icon name="check-circle" class="w-5 h-5 text-blue-500" />
                                        </div>
                                    @endif
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center
                                        {{ $isSelected
                                            ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400'
                                            : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400' }}">
                                        <flux:icon name="{{ $option['icon'] }}" class="w-5 h-5" />
                                    </div>
                                    <span class="text-sm font-medium {{ $isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                        {{ $option['label'] }}
                                    </span>
                                </button>
                            @endforeach
                        </div>

                        <div>
                            <flux:textarea
                                wire:model="step3Notes"
                                :label="__('Anything else about the areas?')"
                                placeholder="{{ __('Optional — specific rooms, surfaces, or areas of concern...') }}"
                                rows="2"
                            />
                        </div>
                    </div>
                @endif

                {{-- STEP 4: Anything we should know about? --}}
                @if($currentStep === 4)
                    <div class="p-6 space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Anything we should know about?') }}</flux:heading>
                            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Select any that apply so we can plan accordingly.') }}</flux:text>
                        </div>

                        @php
                            $logistics = [
                                'pets' => ['label' => __('Pets'), 'icon' => 'heart'],
                                'working_from_home' => ['label' => __('Working from home'), 'icon' => 'computer-desktop'],
                                'high_ceilings' => ['label' => __('High ceilings (10ft+)'), 'icon' => 'arrows-pointing-out'],
                                'wallpaper' => ['label' => __('Wallpaper to remove'), 'icon' => 'scissors'],
                                'peeling_damaged' => ['label' => __('Peeling / damaged surfaces'), 'icon' => 'exclamation-triangle'],
                                'hoa_restrictions' => ['label' => __('HOA restrictions'), 'icon' => 'shield-check'],
                                'want_options' => ['label' => __('I want options'), 'icon' => 'adjustments-horizontal'],
                                'none' => ['label' => __('None of the above'), 'icon' => 'minus-circle'],
                            ];
                        @endphp

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($logistics as $key => $option)
                                @php $isSelected = in_array($key, $step4Logistics); @endphp
                                <button
                                    wire:click="toggleLogistic('{{ $key }}')"
                                    wire:key="logistic-{{ $key }}"
                                    type="button"
                                    class="relative flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all text-center
                                        {{ $isSelected
                                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-500/30'
                                            : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-300 dark:hover:border-blue-700 bg-white dark:bg-zinc-800' }}"
                                >
                                    @if($isSelected)
                                        <div class="absolute top-2 right-2">
                                            <flux:icon name="check-circle" class="w-5 h-5 text-blue-500" />
                                        </div>
                                    @endif
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center
                                        {{ $isSelected
                                            ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400'
                                            : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400' }}">
                                        <flux:icon name="{{ $option['icon'] }}" class="w-5 h-5" />
                                    </div>
                                    <span class="text-sm font-medium {{ $isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                        {{ $option['label'] }}
                                    </span>
                                </button>
                            @endforeach
                        </div>

                        <div>
                            <flux:textarea
                                wire:model="step4Notes"
                                :label="__('Any other details?')"
                                placeholder="{{ __('Optional — anything that would help us prepare...') }}"
                                rows="2"
                            />
                        </div>
                    </div>
                @endif

                {{-- STEP 5: How do you think about this investment? --}}
                @if($currentStep === 5)
                    <div class="p-6 space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('How do you think about this investment?') }}</flux:heading>
                            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('This helps us recommend the right products and approach.') }}</flux:text>
                        </div>

                        {{-- Investment mindset --}}
                        <div class="space-y-3">
                            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Investment mindset') }}</label>

                            @php
                                $investments = [
                                    'affordable' => ['label' => __('Affordable'), 'desc' => __('Budget-friendly')],
                                    'good_quality' => ['label' => __('Good quality'), 'desc' => __('Best value')],
                                    'premium' => ['label' => __('Premium'), 'desc' => __('Top materials')],
                                    'the_best' => ['label' => __('The best'), 'desc' => __('No compromises')],
                                    'want_options' => ['label' => __('Want options'), 'desc' => __('Show me tiers')],
                                ];
                            @endphp

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                @foreach($investments as $key => $option)
                                    <button
                                        wire:click="selectInvestment('{{ $key }}')"
                                        wire:key="investment-{{ $key }}"
                                        type="button"
                                        class="flex flex-col items-center gap-1 p-4 rounded-xl border-2 transition-all text-center
                                            {{ $step5Investment === $key
                                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-500/30'
                                                : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-300 dark:hover:border-blue-700 bg-white dark:bg-zinc-800' }}"
                                    >
                                        <span class="text-sm font-semibold {{ $step5Investment === $key ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                            {{ $option['label'] }}
                                        </span>
                                        <span class="text-xs {{ $step5Investment === $key ? 'text-blue-500 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500' }}">
                                            {{ $option['desc'] }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Timeline --}}
                        <div class="space-y-3">
                            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('When would you like to start?') }}</label>

                            @php
                                $timelines = [
                                    'asap' => ['label' => __('ASAP'), 'icon' => 'bolt'],
                                    '2_4_weeks' => ['label' => __('2-4 weeks'), 'icon' => 'calendar'],
                                    '1_3_months' => ['label' => __('1-3 months'), 'icon' => 'calendar-days'],
                                    'flexible' => ['label' => __('Flexible'), 'icon' => 'clock'],
                                ];
                            @endphp

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                @foreach($timelines as $key => $option)
                                    <button
                                        wire:click="selectTimeline('{{ $key }}')"
                                        wire:key="timeline-{{ $key }}"
                                        type="button"
                                        class="flex flex-col items-center gap-2 p-3 rounded-xl border-2 transition-all text-center
                                            {{ $step5Timeline === $key
                                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-500/30'
                                                : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-300 dark:hover:border-blue-700 bg-white dark:bg-zinc-800' }}"
                                    >
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center
                                            {{ $step5Timeline === $key
                                                ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400'
                                                : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400' }}">
                                            <flux:icon name="{{ $option['icon'] }}" class="w-4 h-4" />
                                        </div>
                                        <span class="text-sm font-medium {{ $step5Timeline === $key ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                            {{ $option['label'] }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <flux:textarea
                                wire:model="step5Notes"
                                :label="__('Anything else we should know?')"
                                placeholder="{{ __('Optional — budget range, special requests, questions...') }}"
                                rows="2"
                            />
                        </div>
                    </div>
                @endif

                {{-- Navigation --}}
                <div class="px-6 py-4 bg-zinc-50 dark:bg-zinc-900/50 border-t border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                    @if($currentStep > 1)
                        <flux:button wire:click="previousStep" variant="ghost" size="sm">
                            <flux:icon name="arrow-left" variant="mini" class="w-4 h-4 mr-1" />
                            {{ __('Back') }}
                        </flux:button>
                    @else
                        <div></div>
                    @endif

                    <flux:button
                        wire:click="nextStep"
                        variant="primary"
                        size="sm"
                    >
                        @if($currentStep === 5)
                            {{ __('Complete') }}
                            <flux:icon name="check" variant="mini" class="w-4 h-4 ml-1" />
                        @else
                            {{ __('Next') }}
                            <flux:icon name="arrow-right" variant="mini" class="w-4 h-4 ml-1" />
                        @endif
                    </flux:button>
                </div>
            </div>
        @endif
    @endif
</div>
