<?php

use App\Models\MarketingStrategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Paid Advertising | Marketing')]
class extends Component
{
    /** @var array<string, string> */
    public array $notes = [];

    public function boot(): void
    {
        if (! auth()->user()?->can('marketing.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        foreach ($this->strategies as $strategy) {
            $this->notes[$strategy->key] = $strategy->notes ?? '';
        }
    }

    /** @return Collection<int, MarketingStrategy> */
    #[Computed]
    public function strategies(): Collection
    {
        return MarketingStrategy::where('category', 'paid_ads')
            ->orderBy('name')
            ->get();
    }

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

    public function saveNotes(string $key): void
    {
        if (! Auth::user()?->can('marketing.manage')) {
            abort(403);
        }

        $strategy = MarketingStrategy::where('key', $key)->firstOrFail();
        $strategy->update(['notes' => $this->notes[$key] ?? '']);

        unset($this->strategies);
    }

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
    @include('pages.marketing.layout', ['heading' => __('Paid Advertising'), 'subheading' => __('External platform guides with status tracking')])

    <div class="space-y-6">
        @forelse ($this->strategies as $strategy)
            @php
                $content = match ($strategy->key) {
                    'google_lsa' => [
                        'why' => 'Pay-per-lead ($40-80), appears above all search results, "Google Guaranteed" badge, 31% lead-to-customer conversion.',
                        'steps' => [
                            'Create Google Business Profile',
                            'Apply for Google Guarantee',
                            'Pass background check',
                            'Set service areas (Denver metro)',
                            'Set weekly budget',
                            'Configure lead preferences',
                            'Set up lead notifications',
                            'Connect to your SMS bot for follow-up',
                        ],
                        'budget' => '$500-2,000/month',
                        'bot' => 'Inbound calls from LSA can trigger your SMS bot flow for automated follow-up and scheduling.',
                    ],
                    'geofencing' => [
                        'why' => 'Target people who visit Sherwin-Williams, Home Depot, Benjamin Moore. Highest intent signal — they\'re already buying paint.',
                        'steps' => [
                            'Set up Meta Business Manager',
                            'Create custom audiences by location',
                            'Add paint store addresses as geofence targets',
                            'Design ad creative featuring your color visualizer',
                            'Set daily budget',
                            'Link ads to Messenger bot flow',
                        ],
                        'budget' => '$1,000-3,000/month',
                        'bot' => 'Ad click → Messenger bot conversation (tracked as entry_source: \'ad\'). Track performance in Bot Tracker tab.',
                    ],
                    'retargeting' => [
                        'why' => 'Returning visitors are 70% more likely to convert. Cheapest paid channel.',
                        'steps' => [
                            'Install Meta Pixel on your website',
                            'Install Google Remarketing tag',
                            'Create retargeting audiences (visited color visualizer, viewed estimate page)',
                            'Launch retargeting campaigns',
                        ],
                        'budget' => '$300-600/month',
                        'bot' => 'Retargeted visitors return to your site → lead form or chat widget → bot flow.',
                    ],
                    'nextdoor' => [
                        'why' => 'Hyper-local, trusted neighbor platform. Home services dominate Nextdoor.',
                        'steps' => [
                            'Claim business page on Nextdoor',
                            'Get neighbor recommendations',
                            'Set up Nextdoor Ads Manager',
                            'Target specific neighborhoods',
                            'Link to booking page',
                        ],
                        'budget' => '$500-1,500/month',
                        'bot' => null,
                    ],
                    'ctv' => [
                        'why' => 'ZIP-code level targeting, 98% ad completion rate. Strategus is headquartered in Denver.',
                        'steps' => [
                            'Contact Strategus (Denver-based)',
                            'Create 15-30 second video',
                            'Configure ZIP-code targeting',
                            'Launch campaign',
                            'Track cross-device conversions',
                        ],
                        'budget' => '$2,000-5,000/month',
                        'bot' => 'Brand awareness play — when they search later, they find you in LSA and organic.',
                    ],
                    default => [
                        'why' => '',
                        'steps' => [],
                        'budget' => '',
                        'bot' => null,
                    ],
                };
            @endphp

            <div
                wire:key="strategy-{{ $strategy->id }}"
                x-data="{ open: false }"
                class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"
            >
                {{-- Card Header --}}
                <button
                    @click="open = !open"
                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon x-show="!open" name="chevron-right" class="size-4 shrink-0 text-zinc-400" />
                        <flux:icon x-show="open" x-cloak name="chevron-down" class="size-4 shrink-0 text-zinc-400" />
                        <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $strategy->name }}</flux:text>
                    </div>
                    <div class="flex items-center gap-3">
                        <flux:badge size="sm" color="zinc">{{ $content['budget'] }}</flux:badge>
                        <flux:badge size="sm" color="{{ $this->statusColor($strategy->status) }}">
                            {{ ucfirst(str_replace('_', ' ', $strategy->status)) }}
                        </flux:badge>
                    </div>
                </button>

                {{-- Card Body --}}
                <div x-show="open" x-cloak x-collapse class="border-t border-zinc-200 px-5 py-5 dark:border-zinc-700">
                    <div class="space-y-5">
                        {{-- Why --}}
                        <div>
                            <flux:text class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Why') }}</flux:text>
                            <flux:text class="text-zinc-700 dark:text-zinc-300">{{ $content['why'] }}</flux:text>
                        </div>

                        {{-- Setup Checklist --}}
                        <div>
                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Setup Checklist') }}</flux:text>
                            <ol class="list-inside list-decimal space-y-1.5">
                                @foreach ($content['steps'] as $step)
                                    <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>

                        {{-- Expected Cost --}}
                        <div>
                            <flux:text class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Expected Cost') }}</flux:text>
                            <flux:text class="text-zinc-700 dark:text-zinc-300">{{ $content['budget'] }}</flux:text>
                        </div>

                        {{-- Bot Connection --}}
                        @if ($content['bot'])
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/30">
                                <div class="flex items-start gap-2">
                                    <flux:icon name="cpu-chip" class="mt-0.5 size-4 shrink-0 text-blue-600 dark:text-blue-400" />
                                    <div>
                                        <flux:text class="mb-0.5 text-xs font-semibold text-blue-700 dark:text-blue-300">{{ __('Bot Connection') }}</flux:text>
                                        <flux:text class="text-sm text-blue-600 dark:text-blue-400">{{ $content['bot'] }}</flux:text>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <flux:separator />

                        {{-- Status Toggle --}}
                        @can('marketing.manage')
                            <div class="flex items-center gap-3">
                                <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Status') }}:</flux:text>
                                @if ($strategy->status === 'active')
                                    <flux:button
                                        size="xs"
                                        variant="filled"
                                        icon="pause"
                                        wire:click="toggleStrategy({{ $strategy->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleStrategy({{ $strategy->id }})"
                                    >
                                        {{ __('Pause') }}
                                    </flux:button>
                                @else
                                    <flux:button
                                        size="xs"
                                        variant="primary"
                                        icon="play"
                                        wire:click="toggleStrategy({{ $strategy->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleStrategy({{ $strategy->id }})"
                                    >
                                        {{ __('Activate') }}
                                    </flux:button>
                                @endif

                                @if ($strategy->activated_at)
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ __('Activated') }}: {{ $strategy->activated_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                        @endcan

                        {{-- Notes --}}
                        <div>
                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Notes') }}</flux:text>
                            <flux:textarea
                                wire:model="notes.{{ $strategy->key }}"
                                rows="3"
                                placeholder="{{ __('Add notes about this strategy...') }}"
                            />
                            @can('marketing.manage')
                                <div class="mt-2 flex justify-end">
                                    <flux:button
                                        size="xs"
                                        variant="primary"
                                        wire:click="saveNotes('{{ $strategy->key }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="saveNotes('{{ $strategy->key }}')"
                                    >
                                        <span wire:loading.remove wire:target="saveNotes('{{ $strategy->key }}')">{{ __('Save Notes') }}</span>
                                        <span wire:loading wire:target="saveNotes('{{ $strategy->key }}')">{{ __('Saving...') }}</span>
                                    </flux:button>
                                </div>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="megaphone" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('No paid advertising strategies configured yet. Run the MarketingStrategySeeder to get started.') }}
                </flux:text>
            </div>
        @endforelse
    </div>
</section>
