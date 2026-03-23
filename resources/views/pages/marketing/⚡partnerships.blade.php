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
#[Title('Partnerships & Offline | Marketing')]
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
        return MarketingStrategy::where('category', 'partnerships')
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
    @include('pages.marketing.layout', ['heading' => __('Partnerships & Offline'), 'subheading' => __('Relationship-based and physical marketing strategies')])

    <div class="space-y-6">
        @forelse ($this->strategies as $strategy)
            @php
                $content = match ($strategy->key) {
                    'sherwin_williams' => [
                        'steps' => [
                            'Visit local SW store and ask about PRO+ program',
                            'Set up contractor account',
                            'Build relationship with store manager',
                            'Ask about in-store referral program',
                            'Leave business cards at paint desk',
                        ],
                        'roi' => '2-5 referrals/month once established',
                    ],
                    'home_inspectors' => [
                        'steps' => [
                            'Identify top 10 home inspectors in Denver',
                            'Offer $50-100 referral fee per completed job',
                            'Provide branded materials for their inspection reports',
                            'Follow up monthly',
                        ],
                        'roi' => '$50-100 cost per lead, high close rate',
                    ],
                    'hoa_property_managers' => [
                        'steps' => [
                            'Research HOAs in target neighborhoods',
                            'Attend HOA board meetings',
                            'Provide competitive multi-unit proposals',
                            'Focus on spring/fall painting seasons',
                        ],
                        'roi' => '12-18 month sales cycle but $10K-50K+ contract value',
                    ],
                    'vehicle_wraps' => [
                        'steps' => [
                            'Design wrap featuring color visualizer QR code',
                            'Get quotes from local wrap shops ($2,500-5,000)',
                            'QR code links to booking page',
                            'Track QR scans',
                        ],
                        'roi' => 'One-time cost, 40K-70K impressions/day, 3-5 year lifespan',
                    ],
                    'yard_signs' => [
                        'steps' => [
                            'Order yard signs ($3-5 each)',
                            'Place at every job site (get homeowner permission)',
                            'Leave door hangers on 20-30 nearby homes',
                            'Include QR code to booking page',
                        ],
                        'roi' => '$3.38 per thousand impressions',
                    ],
                    'events' => [
                        'steps' => [
                            'Register for Denver Home Show (March)',
                            'Set up booth with color visualizer demo',
                            'Attend HOA networking events',
                            'Join local BNI or Chamber of Commerce',
                        ],
                        'roi' => '10-20 qualified leads per event',
                    ],
                    default => [
                        'steps' => [],
                        'roi' => '',
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
                    <flux:badge size="sm" color="{{ $this->statusColor($strategy->status) }}">
                        {{ ucfirst(str_replace('_', ' ', $strategy->status)) }}
                    </flux:badge>
                </button>

                {{-- Card Body --}}
                <div x-show="open" x-cloak x-collapse class="border-t border-zinc-200 px-5 py-5 dark:border-zinc-700">
                    <div class="space-y-5">
                        {{-- How to Start --}}
                        <div>
                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('How to Start') }}</flux:text>
                            <ol class="list-inside list-decimal space-y-1.5">
                                @foreach ($content['steps'] as $step)
                                    <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>

                        {{-- Expected ROI --}}
                        <div>
                            <flux:text class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Expected ROI') }}</flux:text>
                            <flux:text class="font-medium text-zinc-700 dark:text-zinc-300">{{ $content['roi'] }}</flux:text>
                        </div>

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
                <flux:icon name="user-group" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('No partnership strategies configured yet. Run the MarketingStrategySeeder to get started.') }}
                </flux:text>
            </div>
        @endforelse
    </div>
</section>
