<?php

use App\Models\MarketingStrategy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Automated Campaigns | Marketing')]
class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('marketing.view')) {
            abort(403);
        }
    }

    // Config arrays for each strategy
    public array $neighborhoodConfig = [];
    public array $referralConfig = [];
    public array $reviewConfig = [];
    public array $reengagementConfig = [];

    public function mount(): void
    {
        $strategies = MarketingStrategy::where('category', 'automated')->get()->keyBy('key');

        $this->neighborhoodConfig = array_merge([
            'radius_miles' => 1.0,
            'delay_days_after_completion' => 3,
            'notes' => '',
        ], $strategies->get('neighborhood_campaigns')?->configuration ?? []);

        $this->referralConfig = array_merge([
            'referrer_reward' => '$150 cash',
            'referee_discount' => '$150 off',
            'sms_template' => 'Thanks for choosing Painting Made Easy! Love your new look? Share this link with a friend — you both get $150. {referral_link}',
            'delay_days_after_completion' => 7,
        ], $strategies->get('referral_program')?->configuration ?? []);

        $this->reviewConfig = array_merge([
            'sms_template' => 'Hi! Thanks for trusting us with your painting project. Would you share your experience? It helps other Denver homeowners find us. Google: {google_review_link}',
            'google_review_url' => '',
            'yelp_review_url' => '',
            'delay_days_after_completion' => 3,
        ], $strategies->get('review_requests')?->configuration ?? []);

        $this->reengagementConfig = array_merge([
            'months_since_last_job' => 24,
            'sms_template' => 'Hi {first_name}! It\'s been a while since we painted your home. Ready for a refresh? Get an instant quote: {estimate_link}',
            'exclude_active_deals' => true,
        ], $strategies->get('sms_reengagement')?->configuration ?? []);
    }

    /**
     * All automated strategies keyed by their key column.
     *
     * @return \Illuminate\Support\Collection<string, MarketingStrategy>
     */
    #[Computed]
    public function strategies(): \Illuminate\Support\Collection
    {
        return MarketingStrategy::where('category', 'automated')
            ->orderBy('name')
            ->get()
            ->keyBy('key');
    }

    /**
     * Toggle a strategy between active and paused.
     */
    public function toggleStrategy(int $id): void
    {
        if (! Auth::user()?->can('marketing.manage')) {
            abort(403);
        }

        $strategy = MarketingStrategy::findOrFail($id);

        if ($strategy->isActive()) {
            $strategy->pause();
        } else {
            $strategy->activate();
        }

        unset($this->strategies);
    }

    /**
     * Save configuration for a specific strategy.
     */
    public function saveStrategyConfig(string $strategyKey): void
    {
        if (! Auth::user()?->can('marketing.manage')) {
            abort(403);
        }

        $configMap = [
            'neighborhood_campaigns' => 'neighborhoodConfig',
            'referral_program' => 'referralConfig',
            'review_requests' => 'reviewConfig',
            'sms_reengagement' => 'reengagementConfig',
        ];

        $validationRules = [
            'neighborhood_campaigns' => [
                'neighborhoodConfig.radius_miles' => 'required|numeric|min:0.1|max:50',
                'neighborhoodConfig.delay_days_after_completion' => 'required|integer|min:0|max:90',
                'neighborhoodConfig.notes' => 'nullable|string|max:1000',
            ],
            'referral_program' => [
                'referralConfig.referrer_reward' => 'required|string|max:100',
                'referralConfig.referee_discount' => 'required|string|max:100',
                'referralConfig.sms_template' => 'required|string|max:500',
                'referralConfig.delay_days_after_completion' => 'required|integer|min:0|max:90',
            ],
            'review_requests' => [
                'reviewConfig.sms_template' => 'required|string|max:500',
                'reviewConfig.google_review_url' => 'nullable|url|max:500',
                'reviewConfig.yelp_review_url' => 'nullable|url|max:500',
                'reviewConfig.delay_days_after_completion' => 'required|integer|min:0|max:90',
            ],
            'sms_reengagement' => [
                'reengagementConfig.months_since_last_job' => 'required|integer|min:1|max:120',
                'reengagementConfig.sms_template' => 'required|string|max:500',
                'reengagementConfig.exclude_active_deals' => 'boolean',
            ],
        ];

        if (! isset($configMap[$strategyKey])) {
            return;
        }

        $this->validate($validationRules[$strategyKey] ?? []);

        $strategy = MarketingStrategy::where('key', $strategyKey)->firstOrFail();
        $property = $configMap[$strategyKey];
        $strategy->update(['configuration' => $this->{$property}]);

        unset($this->strategies);

        session()->flash("config-saved-{$strategyKey}", __('Configuration saved.'));
    }

    /**
     * Badge color for a given strategy status.
     */
    public function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'green',
            'paused' => 'red',
            'setting_up' => 'yellow',
            default => 'zinc',
        };
    }
}; ?>

<section class="w-full">
    @include('pages.marketing.layout', ['heading' => __('Automated Campaigns'), 'subheading' => __('Automations that run when jobs complete')])

    <div class="space-y-6">

        @if ($this->strategies->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="cog-6-tooth" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('No automated strategies found. Run the MarketingStrategySeeder to create them.') }}
                </flux:text>
            </div>
        @endif

        {{-- ========= NEIGHBORHOOD CAMPAIGNS ========= --}}
        @if ($neighborhood = $this->strategies->get('neighborhood_campaigns'))
            <div
                wire:key="strategy-neighborhood"
                x-data="{ open: false }"
                class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
            >
                {{-- Header --}}
                <button
                    @click="open = !open"
                    type="button"
                    class="flex w-full items-center justify-between px-5 py-4 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon name="home-modern" class="size-5 text-zinc-400" />
                        <flux:text class="text-base font-semibold text-zinc-900 dark:text-white">
                            {{ $neighborhood->name }}
                        </flux:text>
                        <flux:badge size="sm" color="{{ $this->statusColor($neighborhood->status) }}">
                            {{ ucfirst(str_replace('_', ' ', $neighborhood->status)) }}
                        </flux:badge>
                    </div>
                    <div class="flex items-center gap-3">
                        @can('marketing.manage')
                            <div @click.stop>
                                @if ($neighborhood->isActive())
                                    <flux:button size="xs" variant="ghost" icon="pause" wire:click="toggleStrategy({{ $neighborhood->id }})" wire:loading.attr="disabled">
                                        {{ __('Pause') }}
                                    </flux:button>
                                @else
                                    <flux:button size="xs" variant="ghost" icon="play" wire:click="toggleStrategy({{ $neighborhood->id }})" wire:loading.attr="disabled">
                                        {{ __('Activate') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endcan
                        <flux:icon name="chevron-down" class="size-5 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                    </div>
                </button>

                {{-- Expandable Content --}}
                <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
                    <div class="space-y-6 p-5">

                        {{-- Playbook --}}
                        <div>
                            <flux:heading size="sm" class="mb-2">{{ __('Playbook') }}</flux:heading>
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/50">
                                <flux:text class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    {{ __('After completing a painting job, target 100-500 neighboring homes with EDDM postcards and geofenced digital ads. One neighbor conversion ($3,000-5,000 job) pays for 10x the marketing cost.') }}
                                </flux:text>
                            </div>
                        </div>

                        {{-- Expected ROI --}}
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                            <div class="flex items-center gap-2 mb-2">
                                <flux:icon name="chart-bar" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                <flux:text class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Expected ROI') }}</flux:text>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Cost per Job Site') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">$325 - $485</flux:text>
                                </div>
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Return on Investment') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">5-15x</flux:text>
                                </div>
                            </div>
                        </div>

                        {{-- Configuration --}}
                        @can('marketing.manage')
                            <div>
                                <flux:heading size="sm" class="mb-3">{{ __('Configuration') }}</flux:heading>

                                @if (session('config-saved-neighborhood_campaigns'))
                                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                                        {{ session('config-saved-neighborhood_campaigns') }}
                                    </div>
                                @endif

                                <form wire:submit="saveStrategyConfig('neighborhood_campaigns')" class="space-y-4">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Target Radius (miles)') }}</flux:label>
                                            <flux:input wire:model="neighborhoodConfig.radius_miles" type="number" step="0.1" min="0.1" max="50" />
                                            <flux:error name="neighborhoodConfig.radius_miles" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label>{{ __('Delay After Job Completion (days)') }}</flux:label>
                                            <flux:input wire:model="neighborhoodConfig.delay_days_after_completion" type="number" min="0" max="90" />
                                            <flux:error name="neighborhoodConfig.delay_days_after_completion" />
                                        </flux:field>
                                    </div>

                                    <flux:field>
                                        <flux:label>{{ __('Notes') }}</flux:label>
                                        <flux:textarea wire:model="neighborhoodConfig.notes" rows="3" :placeholder="__('Internal notes about this campaign...')" />
                                        <flux:error name="neighborhoodConfig.notes" />
                                    </flux:field>

                                    <div class="flex justify-end">
                                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveStrategyConfig('neighborhood_campaigns')">
                                            <span wire:loading.remove wire:target="saveStrategyConfig('neighborhood_campaigns')">{{ __('Save Configuration') }}</span>
                                            <span wire:loading wire:target="saveStrategyConfig('neighborhood_campaigns')">{{ __('Saving...') }}</span>
                                        </flux:button>
                                    </div>
                                </form>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
        @endif

        {{-- ========= REFERRAL PROGRAM ========= --}}
        @if ($referral = $this->strategies->get('referral_program'))
            <div
                wire:key="strategy-referral"
                x-data="{ open: false }"
                class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
            >
                {{-- Header --}}
                <button
                    @click="open = !open"
                    type="button"
                    class="flex w-full items-center justify-between px-5 py-4 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon name="gift" class="size-5 text-zinc-400" />
                        <flux:text class="text-base font-semibold text-zinc-900 dark:text-white">
                            {{ $referral->name }}
                        </flux:text>
                        <flux:badge size="sm" color="{{ $this->statusColor($referral->status) }}">
                            {{ ucfirst(str_replace('_', ' ', $referral->status)) }}
                        </flux:badge>
                    </div>
                    <div class="flex items-center gap-3">
                        @can('marketing.manage')
                            <div @click.stop>
                                @if ($referral->isActive())
                                    <flux:button size="xs" variant="ghost" icon="pause" wire:click="toggleStrategy({{ $referral->id }})" wire:loading.attr="disabled">
                                        {{ __('Pause') }}
                                    </flux:button>
                                @else
                                    <flux:button size="xs" variant="ghost" icon="play" wire:click="toggleStrategy({{ $referral->id }})" wire:loading.attr="disabled">
                                        {{ __('Activate') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endcan
                        <flux:icon name="chevron-down" class="size-5 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                    </div>
                </button>

                {{-- Expandable Content --}}
                <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
                    <div class="space-y-6 p-5">

                        {{-- Playbook --}}
                        <div>
                            <flux:heading size="sm" class="mb-2">{{ __('Playbook') }}</flux:heading>
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/50">
                                <flux:text class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    {{ __('Send post-job SMS with referral link and reward. Referred leads close at 50-70%. Double-sided rewards work best.') }}
                                </flux:text>
                            </div>
                        </div>

                        {{-- Expected ROI --}}
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                            <div class="flex items-center gap-2 mb-2">
                                <flux:icon name="chart-bar" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                <flux:text class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Expected ROI') }}</flux:text>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Cost per Referral Paid') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">$100 - $200</flux:text>
                                </div>
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Return on Investment') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">10-20x</flux:text>
                                </div>
                            </div>
                        </div>

                        {{-- Configuration --}}
                        @can('marketing.manage')
                            <div>
                                <flux:heading size="sm" class="mb-3">{{ __('Configuration') }}</flux:heading>

                                @if (session('config-saved-referral_program'))
                                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                                        {{ session('config-saved-referral_program') }}
                                    </div>
                                @endif

                                <form wire:submit="saveStrategyConfig('referral_program')" class="space-y-4">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Referrer Reward') }}</flux:label>
                                            <flux:input wire:model="referralConfig.referrer_reward" type="text" :placeholder="__('$150 cash')" />
                                            <flux:error name="referralConfig.referrer_reward" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label>{{ __('Referee Discount') }}</flux:label>
                                            <flux:input wire:model="referralConfig.referee_discount" type="text" :placeholder="__('$150 off')" />
                                            <flux:error name="referralConfig.referee_discount" />
                                        </flux:field>
                                    </div>

                                    <flux:field>
                                        <flux:label>{{ __('SMS Template') }}</flux:label>
                                        <flux:textarea wire:model="referralConfig.sms_template" rows="3" />
                                        <flux:description>{{ __('Available variables: {referral_link}, {first_name}, {company_name}') }}</flux:description>
                                        <flux:error name="referralConfig.sms_template" />
                                    </flux:field>

                                    <flux:field>
                                        <flux:label>{{ __('Delay After Job Completion (days)') }}</flux:label>
                                        <flux:input wire:model="referralConfig.delay_days_after_completion" type="number" min="0" max="90" />
                                        <flux:error name="referralConfig.delay_days_after_completion" />
                                    </flux:field>

                                    <div class="flex justify-end">
                                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveStrategyConfig('referral_program')">
                                            <span wire:loading.remove wire:target="saveStrategyConfig('referral_program')">{{ __('Save Configuration') }}</span>
                                            <span wire:loading wire:target="saveStrategyConfig('referral_program')">{{ __('Saving...') }}</span>
                                        </flux:button>
                                    </div>
                                </form>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
        @endif

        {{-- ========= REVIEW REQUESTS ========= --}}
        @if ($review = $this->strategies->get('review_requests'))
            <div
                wire:key="strategy-review"
                x-data="{ open: false }"
                class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
            >
                {{-- Header --}}
                <button
                    @click="open = !open"
                    type="button"
                    class="flex w-full items-center justify-between px-5 py-4 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon name="star" class="size-5 text-zinc-400" />
                        <flux:text class="text-base font-semibold text-zinc-900 dark:text-white">
                            {{ $review->name }}
                        </flux:text>
                        <flux:badge size="sm" color="{{ $this->statusColor($review->status) }}">
                            {{ ucfirst(str_replace('_', ' ', $review->status)) }}
                        </flux:badge>
                    </div>
                    <div class="flex items-center gap-3">
                        @can('marketing.manage')
                            <div @click.stop>
                                @if ($review->isActive())
                                    <flux:button size="xs" variant="ghost" icon="pause" wire:click="toggleStrategy({{ $review->id }})" wire:loading.attr="disabled">
                                        {{ __('Pause') }}
                                    </flux:button>
                                @else
                                    <flux:button size="xs" variant="ghost" icon="play" wire:click="toggleStrategy({{ $review->id }})" wire:loading.attr="disabled">
                                        {{ __('Activate') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endcan
                        <flux:icon name="chevron-down" class="size-5 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                    </div>
                </button>

                {{-- Expandable Content --}}
                <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
                    <div class="space-y-6 p-5">

                        {{-- Playbook --}}
                        <div>
                            <flux:heading size="sm" class="mb-2">{{ __('Playbook') }}</flux:heading>
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/50">
                                <flux:text class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    {{ __('Post-job SMS asking for Google/Yelp review. Reviews mentioning your technology and service area feed directly into AI recommendations. Cost: $0. ROI: Compounding.') }}
                                </flux:text>
                            </div>
                        </div>

                        {{-- Expected ROI --}}
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                            <div class="flex items-center gap-2 mb-2">
                                <flux:icon name="chart-bar" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                <flux:text class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Expected ROI') }}</flux:text>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Cost') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">{{ __('$0 (free)') }}</flux:text>
                                </div>
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Return on Investment') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Compounding') }}</flux:text>
                                </div>
                            </div>
                        </div>

                        {{-- Configuration --}}
                        @can('marketing.manage')
                            <div>
                                <flux:heading size="sm" class="mb-3">{{ __('Configuration') }}</flux:heading>

                                @if (session('config-saved-review_requests'))
                                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                                        {{ session('config-saved-review_requests') }}
                                    </div>
                                @endif

                                <form wire:submit="saveStrategyConfig('review_requests')" class="space-y-4">
                                    <flux:field>
                                        <flux:label>{{ __('SMS Template') }}</flux:label>
                                        <flux:textarea wire:model="reviewConfig.sms_template" rows="3" />
                                        <flux:description>{{ __('Available variables: {google_review_link}, {yelp_review_link}, {first_name}, {company_name}') }}</flux:description>
                                        <flux:error name="reviewConfig.sms_template" />
                                    </flux:field>

                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Google Review URL') }}</flux:label>
                                            <flux:input wire:model="reviewConfig.google_review_url" type="url" placeholder="https://g.page/..." />
                                            <flux:error name="reviewConfig.google_review_url" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label>{{ __('Yelp Review URL') }}</flux:label>
                                            <flux:input wire:model="reviewConfig.yelp_review_url" type="url" placeholder="https://www.yelp.com/biz/..." />
                                            <flux:error name="reviewConfig.yelp_review_url" />
                                        </flux:field>
                                    </div>

                                    <flux:field>
                                        <flux:label>{{ __('Delay After Job Completion (days)') }}</flux:label>
                                        <flux:input wire:model="reviewConfig.delay_days_after_completion" type="number" min="0" max="90" />
                                        <flux:error name="reviewConfig.delay_days_after_completion" />
                                    </flux:field>

                                    <div class="flex justify-end">
                                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveStrategyConfig('review_requests')">
                                            <span wire:loading.remove wire:target="saveStrategyConfig('review_requests')">{{ __('Save Configuration') }}</span>
                                            <span wire:loading wire:target="saveStrategyConfig('review_requests')">{{ __('Saving...') }}</span>
                                        </flux:button>
                                    </div>
                                </form>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
        @endif

        {{-- ========= SMS RE-ENGAGEMENT ========= --}}
        @if ($reengagement = $this->strategies->get('sms_reengagement'))
            <div
                wire:key="strategy-reengagement"
                x-data="{ open: false }"
                class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
            >
                {{-- Header --}}
                <button
                    @click="open = !open"
                    type="button"
                    class="flex w-full items-center justify-between px-5 py-4 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon name="chat-bubble-left-right" class="size-5 text-zinc-400" />
                        <flux:text class="text-base font-semibold text-zinc-900 dark:text-white">
                            {{ $reengagement->name }}
                        </flux:text>
                        <flux:badge size="sm" color="{{ $this->statusColor($reengagement->status) }}">
                            {{ ucfirst(str_replace('_', ' ', $reengagement->status)) }}
                        </flux:badge>
                    </div>
                    <div class="flex items-center gap-3">
                        @can('marketing.manage')
                            <div @click.stop>
                                @if ($reengagement->isActive())
                                    <flux:button size="xs" variant="ghost" icon="pause" wire:click="toggleStrategy({{ $reengagement->id }})" wire:loading.attr="disabled">
                                        {{ __('Pause') }}
                                    </flux:button>
                                @else
                                    <flux:button size="xs" variant="ghost" icon="play" wire:click="toggleStrategy({{ $reengagement->id }})" wire:loading.attr="disabled">
                                        {{ __('Activate') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endcan
                        <flux:icon name="chevron-down" class="size-5 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                    </div>
                </button>

                {{-- Expandable Content --}}
                <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
                    <div class="space-y-6 p-5">

                        {{-- Playbook --}}
                        <div>
                            <flux:heading size="sm" class="mb-2">{{ __('Playbook') }}</flux:heading>
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/50">
                                <flux:text class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    {{ __('Target past customers at 2-3 year intervals for repaints. 98% open rate on SMS. Warm leads with existing relationship.') }}
                                </flux:text>
                            </div>
                        </div>

                        {{-- Expected ROI --}}
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                            <div class="flex items-center gap-2 mb-2">
                                <flux:icon name="chart-bar" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                <flux:text class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Expected ROI') }}</flux:text>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Cost per SMS') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">~$0.01</flux:text>
                                </div>
                                <div>
                                    <flux:text class="text-xs text-emerald-600/70 dark:text-emerald-400/70">{{ __('Return on Investment') }}</flux:text>
                                    <flux:text class="font-semibold text-emerald-700 dark:text-emerald-300">50-100x</flux:text>
                                </div>
                            </div>
                        </div>

                        {{-- Configuration --}}
                        @can('marketing.manage')
                            <div>
                                <flux:heading size="sm" class="mb-3">{{ __('Configuration') }}</flux:heading>

                                @if (session('config-saved-sms_reengagement'))
                                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                                        {{ session('config-saved-sms_reengagement') }}
                                    </div>
                                @endif

                                <form wire:submit="saveStrategyConfig('sms_reengagement')" class="space-y-4">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Months Since Last Job') }}</flux:label>
                                            <flux:input wire:model="reengagementConfig.months_since_last_job" type="number" min="1" max="120" />
                                            <flux:description>{{ __('Send re-engagement SMS after this many months since last completed job.') }}</flux:description>
                                            <flux:error name="reengagementConfig.months_since_last_job" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label>{{ __('Exclude Active Deals') }}</flux:label>
                                            <flux:switch wire:model="reengagementConfig.exclude_active_deals" :label="__('Skip customers with open deals')" />
                                            <flux:error name="reengagementConfig.exclude_active_deals" />
                                        </flux:field>
                                    </div>

                                    <flux:field>
                                        <flux:label>{{ __('SMS Template') }}</flux:label>
                                        <flux:textarea wire:model="reengagementConfig.sms_template" rows="3" />
                                        <flux:description>{{ __('Available variables: {first_name}, {last_name}, {estimate_link}, {company_name}') }}</flux:description>
                                        <flux:error name="reengagementConfig.sms_template" />
                                    </flux:field>

                                    <div class="flex justify-end">
                                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveStrategyConfig('sms_reengagement')">
                                            <span wire:loading.remove wire:target="saveStrategyConfig('sms_reengagement')">{{ __('Save Configuration') }}</span>
                                            <span wire:loading wire:target="saveStrategyConfig('sms_reengagement')">{{ __('Saving...') }}</span>
                                        </flux:button>
                                    </div>
                                </form>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
        @endif

    </div>
</section>
