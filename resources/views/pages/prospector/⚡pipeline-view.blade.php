<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Campaign Lists')]
class extends Component
{
    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function agent(): ?Agent
    {
        return Agent::where('user_id', Auth::id())->first();
    }

    #[Computed]
    public function campaigns(): \Illuminate\Support\Collection
    {
        if (! $this->agent) {
            return collect();
        }

        $companyIds = $this->agent->getCompanyIds();

        return Campaign::whereIn('company_id', $companyIds)
            ->with('company')
            ->withCount([
                'campaignContacts as queued_count' => fn ($q) => $q->where('status', 'queued'),
                'campaignContacts as completed_count' => fn ($q) => $q->where('status', 'completed'),
                'campaignContacts as total_count',
            ])
            ->orderBy('company_id')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Campaign Lists') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage and monitor your dialer campaigns.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('prospector.campaigns')" wire:navigate>
            {{ __('Manage Campaigns') }}
        </flux:button>
    </div>

    @if ($this->campaigns->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="megaphone" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No campaigns found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create a campaign to start dialing your contact lists.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" :href="route('prospector.campaigns')" wire:navigate>
                    {{ __('Create Campaign') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->campaigns as $campaign)
                @php
                    $total = $campaign->total_count ?? 0;
                    $completed = $campaign->completed_count ?? 0;
                    $queued = $campaign->queued_count ?? 0;
                    $progress = $total > 0 ? round(($completed / $total) * 100) : 0;
                    $connectionRate = $campaign->getConnectionRate();
                    $companyColor = $campaign->company?->color ?? '#6b7280';

                    $statusColor = match ($campaign->status) {
                        'active' => 'green',
                        'paused' => 'amber',
                        'completed' => 'blue',
                        default => 'zinc',
                    };

                    $cardOpacity = in_array($campaign->status, ['paused', 'completed']) ? 'opacity-75' : '';
                @endphp

                <a
                    href="{{ route('prospector.campaigns.show', $campaign->id) }}"
                    wire:navigate
                    class="group block rounded-xl border border-zinc-200 bg-white shadow-sm transition-all hover:border-zinc-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600 {{ $cardOpacity }}"
                >
                    {{-- Card header with company color accent --}}
                    <div class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                        <div
                            class="size-3 shrink-0 rounded-full"
                            style="background-color: {{ $companyColor }}"
                        ></div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 group-hover:text-zinc-700 dark:text-zinc-100 dark:group-hover:text-white">
                                {{ $campaign->name }}
                            </p>
                            @if ($campaign->company)
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $campaign->company->name }}
                                </p>
                            @endif
                        </div>
                        <flux:badge size="sm" :color="$statusColor" class="shrink-0">
                            {{ ucfirst($campaign->status) }}
                        </flux:badge>
                    </div>

                    <div class="px-4 py-3 space-y-3">
                        {{-- Progress bar --}}
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                <span>{{ __('Progress') }}</span>
                                <span>{{ $completed }} / {{ $total }} {{ __('contacts') }}</span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                <div
                                    class="h-full rounded-full transition-all {{ $campaign->status === 'completed' ? 'bg-blue-500' : 'bg-green-500' }}"
                                    style="width: {{ $progress }}%"
                                ></div>
                            </div>
                        </div>

                        {{-- Stats row --}}
                        <div class="grid grid-cols-3 divide-x divide-zinc-100 dark:divide-zinc-700">
                            <div class="pr-3 text-center">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ number_format($queued) }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Queued') }}</p>
                            </div>
                            <div class="px-3 text-center">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ number_format($completed) }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</p>
                            </div>
                            <div class="pl-3 text-center">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $connectionRate }}%</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Connected') }}</p>
                            </div>
                        </div>

                        {{-- Call rules summary --}}
                        <div class="flex items-center gap-1 text-xs text-zinc-400 dark:text-zinc-500">
                            <flux:icon name="phone" variant="micro" class="shrink-0" />
                            <span>
                                @if ($campaign->max_calls_per_day)
                                    {{ $campaign->max_calls_per_day }}x/day
                                @else
                                    {{ __('No daily limit') }}
                                @endif

                                @if ($campaign->max_total_attempts)
                                    &middot; {{ $campaign->max_total_attempts }} max
                                @endif

                                @if ($campaign->max_days_in_campaign)
                                    &middot; {{ $campaign->max_days_in_campaign }} {{ __('days') }}
                                @endif
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
