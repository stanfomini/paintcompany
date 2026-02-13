<?php

use App\Models\RoiCalculation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    public RoiCalculation $calculation;

    public string $token = '';

    /** @var float Interactive budget value (between cost_low and cost_high) */
    public float $budget = 0;

    public function mount(string $token): void
    {
        $calculation = RoiCalculation::where('public_token', $token)
            ->with(['deal.customer', 'agent'])
            ->first();

        if (! $calculation) {
            abort(404, 'ROI calculation not found.');
        }

        if (! $calculation->isPublicTokenValid()) {
            abort(410, 'This ROI link has expired.');
        }

        $this->calculation = $calculation;
        $this->token = $token;
        $this->budget = round(((float) $calculation->estimated_cost_low + (float) $calculation->estimated_cost_high) / 2);
    }

    #[Computed]
    public function estimatedValueIncrease(): float
    {
        $costLow = (float) $this->calculation->estimated_cost_low;
        $costHigh = (float) $this->calculation->estimated_cost_high;
        $valueLow = (float) $this->calculation->estimated_value_increase_low;
        $valueHigh = (float) $this->calculation->estimated_value_increase_high;

        if ($costHigh <= $costLow) {
            return $valueLow;
        }

        $fraction = ($this->budget - $costLow) / ($costHigh - $costLow);
        $fraction = max(0, min(1, $fraction));

        return round($valueLow + ($valueHigh - $valueLow) * $fraction);
    }

    #[Computed]
    public function currentRoiPercent(): float
    {
        if ($this->budget <= 0) {
            return 0;
        }

        return round(($this->estimatedValueIncrease / $this->budget) * 100, 1);
    }

    #[Computed]
    public function afterValue(): float
    {
        return (float) $this->calculation->property_value + $this->estimatedValueIncrease;
    }

    #[Computed]
    public function scopeLabel(): string
    {
        return match ($this->calculation->scope) {
            'interior' => __('Interior Painting'),
            'exterior' => __('Exterior Painting'),
            'both' => __('Interior & Exterior Painting'),
            default => __('Painting'),
        };
    }

    #[Computed]
    public function intentHeadline(): string
    {
        if ($this->calculation->intent === 'selling') {
            return __('A paint refresh could increase your listing price by $:low - $:high', [
                'low' => number_format((float) $this->calculation->estimated_value_increase_low, 0),
                'high' => number_format((float) $this->calculation->estimated_value_increase_high, 0),
            ]);
        }

        return __('Budget $:low - $:high for painting to bring this home to its full potential', [
            'low' => number_format((float) $this->calculation->estimated_cost_low, 0),
            'high' => number_format((float) $this->calculation->estimated_cost_high, 0),
        ]);
    }
};
?>

<div class="py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-3xl mx-auto space-y-8">

        {{-- Intent Headline --}}
        <div class="text-center space-y-2">
            <h1 class="text-2xl sm:text-3xl font-bold text-zinc-900 dark:text-white">
                {{ __('Property ROI Analysis') }}
            </h1>
            <p class="text-lg text-blue-600 dark:text-blue-400 font-medium">
                {{ $this->intentHeadline }}
            </p>
        </div>

        {{-- Property Details Card --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">{{ __('Property Overview') }}</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Property Value') }}</p>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $calculation->property_value, 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Square Feet') }}</p>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ number_format($calculation->estimated_sqft) }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Bedrooms') }}</p>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $calculation->bedroom_count }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Scope') }}</p>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->scopeLabel }}</p>
                </div>
            </div>
        </div>

        {{-- Interactive Budget Slider --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">{{ __('Explore Your Investment') }}</h2>
            <div class="space-y-4">
                <div class="flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                    <span>${{ number_format((float) $calculation->estimated_cost_low, 0) }}</span>
                    <span class="font-semibold text-blue-600 dark:text-blue-400 text-lg">${{ number_format($this->budget, 0) }}</span>
                    <span>${{ number_format((float) $calculation->estimated_cost_high, 0) }}</span>
                </div>
                <input
                    type="range"
                    wire:model.live="budget"
                    min="{{ (float) $calculation->estimated_cost_low }}"
                    max="{{ (float) $calculation->estimated_cost_high }}"
                    step="100"
                    class="w-full h-2 bg-zinc-200 dark:bg-zinc-600 rounded-lg appearance-none cursor-pointer accent-blue-600"
                />
                <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center">
                    {{ __('Drag the slider to see how your investment affects your return') }}
                </p>
            </div>
        </div>

        {{-- ROI Metrics --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-center">
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Estimated Cost') }}</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-white">
                    ${{ number_format((float) $calculation->estimated_cost_low, 0) }} - ${{ number_format((float) $calculation->estimated_cost_high, 0) }}
                </p>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-center">
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Value Increase') }}</p>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    ${{ number_format((float) $calculation->estimated_value_increase_low, 0) }} - ${{ number_format((float) $calculation->estimated_value_increase_high, 0) }}
                </p>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-center">
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">{{ __('ROI Range') }}</p>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {{ number_format((float) $calculation->roi_percent_low, 0) }}% - {{ number_format((float) $calculation->roi_percent_high, 0) }}%
                </p>
            </div>
        </div>

        {{-- Before / After Value Comparison --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">{{ __('Before & After Value') }}</h2>
            <div class="space-y-4">
                {{-- Before --}}
                <div>
                    <div class="flex justify-between mb-1">
                        <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Current Value') }}</span>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $calculation->property_value, 0) }}</span>
                    </div>
                    <div class="w-full bg-zinc-200 dark:bg-zinc-600 rounded-full h-4">
                        <div class="bg-zinc-400 dark:bg-zinc-500 h-4 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
                {{-- After --}}
                @php
                    $beforeValue = (float) $calculation->property_value;
                    $afterVal = $this->afterValue;
                    $barWidth = $beforeValue > 0 ? min(($afterVal / $beforeValue) * 100, 100) : 100;
                @endphp
                <div>
                    <div class="flex justify-between mb-1">
                        <span class="text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ __('After Painting') }}</span>
                        <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">${{ number_format($afterVal, 0) }}</span>
                    </div>
                    <div class="w-full bg-zinc-200 dark:bg-zinc-600 rounded-full h-4">
                        <div class="bg-emerald-500 h-4 rounded-full" style="width: {{ $barWidth }}%"></div>
                    </div>
                </div>
                <p class="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">
                    {{ __(':amount estimated increase at current budget', ['amount' => '+$' . number_format($this->estimatedValueIncrease, 0)]) }}
                </p>
            </div>
        </div>

        {{-- CTA --}}
        <div class="text-center space-y-4">
            @auth('customer')
                <a href="{{ route('customer.dashboard') }}" class="inline-flex items-center justify-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                    {{ __('View Your Estimate') }}
                </a>
            @else
                <a href="{{ route('home') }}" class="inline-flex items-center justify-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                    {{ __('Get Started') }}
                </a>
            @endauth
        </div>

        {{-- Disclaimer --}}
        <div class="text-center">
            <p class="text-xs text-zinc-400 dark:text-zinc-500 max-w-xl mx-auto">
                {{ __('Estimates vary by area, property condition, and listing strategy. These are industry-average estimates based on property characteristics and market data. Actual costs and value increases may vary depending on condition, materials, scope of work, and local market factors. Contact us for a detailed estimate.') }}
            </p>
        </div>
    </div>
</div>
