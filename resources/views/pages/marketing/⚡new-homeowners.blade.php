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
#[Title('New Homeowners | Marketing')]
class extends Component
{
    /** @var array<string, string> */
    public array $notes = [];

    /** Manual tracking stats for new homeowner strategy. */
    public string $postcardsSent = '';

    public string $responses = '';

    public string $leadsCreated = '';

    public string $monthlyCost = '';

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

            if ($strategy->key === 'new_homeowner_triggers') {
                $this->postcardsSent = (string) ($strategy->getConfig('stats.postcards_sent') ?? '');
                $this->responses = (string) ($strategy->getConfig('stats.responses') ?? '');
                $this->leadsCreated = (string) ($strategy->getConfig('stats.leads_created') ?? '');
                $this->monthlyCost = (string) ($strategy->getConfig('stats.monthly_cost') ?? '');
            }
        }
    }

    /** @return Collection<int, MarketingStrategy> */
    #[Computed]
    public function strategies(): Collection
    {
        return MarketingStrategy::where('category', 'new_homeowners')
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

    public function saveStats(): void
    {
        if (! Auth::user()?->can('marketing.manage')) {
            abort(403);
        }

        $this->validate([
            'postcardsSent' => ['nullable', 'integer', 'min:0'],
            'responses' => ['nullable', 'integer', 'min:0'],
            'leadsCreated' => ['nullable', 'integer', 'min:0'],
            'monthlyCost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $strategy = MarketingStrategy::where('key', 'new_homeowner_triggers')->firstOrFail();

        $strategy->setConfig('stats.postcards_sent', $this->postcardsSent !== '' ? (int) $this->postcardsSent : null);
        $strategy->setConfig('stats.responses', $this->responses !== '' ? (int) $this->responses : null);
        $strategy->setConfig('stats.leads_created', $this->leadsCreated !== '' ? (int) $this->leadsCreated : null);
        $strategy->setConfig('stats.monthly_cost', $this->monthlyCost !== '' ? (float) $this->monthlyCost : null);

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
    @include('pages.marketing.layout', ['heading' => __('New Homeowners'), 'subheading' => __('Target homeowners within 60 days of purchase')])

    <div class="space-y-6">
        @forelse ($this->strategies as $strategy)
            <div
                wire:key="strategy-{{ $strategy->id }}"
                x-data="{ open: true }"
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
                        <flux:badge size="sm" color="zinc">$99/month + ~$200/month postcards</flux:badge>
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
                            <flux:text class="text-zinc-700 dark:text-zinc-300">
                                {{ __('60% of new homeowners paint within 6 months of purchase. PropertyRadar monitors Denver deed transfers weekly. Documented 1,439% ROI in one case study. Denver pending contracts up 16% in 2026.') }}
                            </flux:text>
                        </div>

                        {{-- Setup Checklist --}}
                        <div>
                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Setup Checklist') }}</flux:text>
                            <ol class="list-inside list-decimal space-y-1.5">
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Sign up for PropertyRadar ($99/month)') }}</li>
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Configure Denver metro monitoring') }}</li>
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Set filters: residential, single-family, deed transfers') }}</li>
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Set up PRINTgenie postcard automation') }}</li>
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Design postcard featuring your color visualizer and instant pricing') }}</li>
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Configure 30-day delay after purchase') }}</li>
                                <li class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Track responses') }}</li>
                            </ol>
                        </div>

                        {{-- Expected Cost & ROI --}}
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <flux:text class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Expected Cost') }}</flux:text>
                                <flux:text class="text-zinc-700 dark:text-zinc-300">{{ __('$99/month (PropertyRadar) + ~$200/month postcards') }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Expected ROI') }}</flux:text>
                                <flux:text class="font-semibold text-green-600 dark:text-green-400">{{ __('10-15x (based on industry data)') }}</flux:text>
                            </div>
                        </div>

                        <flux:separator />

                        {{-- Manual Stats Section --}}
                        <div>
                            <flux:text class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Monthly Metrics') }}</flux:text>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div>
                                    <flux:input
                                        wire:model="postcardsSent"
                                        type="number"
                                        label="{{ __('Postcards Sent') }}"
                                        placeholder="0"
                                        min="0"
                                    />
                                </div>
                                <div>
                                    <flux:input
                                        wire:model="responses"
                                        type="number"
                                        label="{{ __('Responses') }}"
                                        placeholder="0"
                                        min="0"
                                    />
                                </div>
                                <div>
                                    <flux:input
                                        wire:model="leadsCreated"
                                        type="number"
                                        label="{{ __('Leads Created') }}"
                                        placeholder="0"
                                        min="0"
                                    />
                                </div>
                                <div>
                                    <flux:input
                                        wire:model="monthlyCost"
                                        type="number"
                                        label="{{ __('Monthly Cost ($)') }}"
                                        placeholder="0.00"
                                        min="0"
                                        step="0.01"
                                    />
                                </div>
                            </div>

                            @can('marketing.manage')
                                <div class="mt-3 flex justify-end">
                                    <flux:button
                                        size="xs"
                                        variant="primary"
                                        wire:click="saveStats"
                                        wire:loading.attr="disabled"
                                        wire:target="saveStats"
                                    >
                                        <span wire:loading.remove wire:target="saveStats">{{ __('Save Metrics') }}</span>
                                        <span wire:loading wire:target="saveStats">{{ __('Saving...') }}</span>
                                    </flux:button>
                                </div>
                            @endcan

                            @if ($postcardsSent && $monthlyCost && (int) $postcardsSent > 0)
                                <div class="mt-3 rounded-lg border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ __('Cost per postcard') }}: ${{ number_format((float) $monthlyCost / (int) $postcardsSent, 2) }}
                                        @if ($responses && (int) $responses > 0)
                                            &middot; {{ __('Response rate') }}: {{ number_format(((int) $responses / (int) $postcardsSent) * 100, 1) }}%
                                            &middot; {{ __('Cost per response') }}: ${{ number_format((float) $monthlyCost / (int) $responses, 2) }}
                                        @endif
                                    </flux:text>
                                </div>
                            @endif
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
                <flux:icon name="home-modern" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('No new homeowner strategies configured yet. Run the MarketingStrategySeeder to get started.') }}
                </flux:text>
            </div>
        @endforelse
    </div>
</section>
