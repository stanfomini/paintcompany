<?php

use App\Models\Referral;
use App\Services\ReferralService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $search = '';

    public ?int $markingRewardId = null;
    public string $rewardNotes = '';

    public function boot(): void
    {
        if (! auth()->user()?->can('referrals.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function referrals()
    {
        return Referral::query()
            ->with(['referrer', 'customer', 'deal'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, function ($q) {
                $q->whereHas('customer', function ($q) {
                    $q->where('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                })->orWhereHas('referrer', function ($q) {
                    $q->where('name', 'like', "%{$this->search}%");
                });
            })
            ->latest()
            ->get();
    }

    #[Computed]
    public function statusCounts(): array
    {
        return [
            'all' => Referral::count(),
            'lead' => Referral::where('status', Referral::STATUS_LEAD)->count(),
            'quoted' => Referral::where('status', Referral::STATUS_QUOTED)->count(),
            'signed' => Referral::where('status', Referral::STATUS_SIGNED)->count(),
            'deposit_paid' => Referral::where('status', Referral::STATUS_DEPOSIT_PAID)->count(),
            'reward_sent' => Referral::where('status', Referral::STATUS_REWARD_SENT)->count(),
        ];
    }

    public function openMarkReward(int $id): void
    {
        $this->markingRewardId = $id;
        $this->rewardNotes = '';
    }

    public function markRewardSent(): void
    {
        $referral = Referral::findOrFail($this->markingRewardId);
        app(ReferralService::class)->markRewardSent($referral, $this->rewardNotes ?: null);

        $this->markingRewardId = null;
        $this->rewardNotes = '';

        unset($this->referrals, $this->statusCounts);
    }

    public function cancelMarkReward(): void
    {
        $this->markingRewardId = null;
        $this->rewardNotes = '';
    }
};
?>

<div>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Referrals') }}</flux:heading>
                    <flux:subheading>{{ __('Track and manage referral program activity.') }}</flux:subheading>
                </div>
            </div>

            {{-- Status filter tabs --}}
            <div class="mb-6 flex flex-wrap items-center gap-2">
                <flux:button
                    size="sm"
                    :variant="$statusFilter === '' ? 'primary' : 'ghost'"
                    wire:click="$set('statusFilter', '')"
                >
                    {{ __('All') }} ({{ $this->statusCounts['all'] }})
                </flux:button>
                @foreach (Referral::STATUSES as $key => $label)
                    <flux:button
                        size="sm"
                        :variant="$statusFilter === $key ? 'primary' : 'ghost'"
                        wire:click="$set('statusFilter', '{{ $key }}')"
                    >
                        {{ $label }} ({{ $this->statusCounts[$key] ?? 0 }})
                    </flux:button>
                @endforeach
            </div>

            {{-- Search --}}
            <div class="mb-6">
                <flux:input wire:model.live.debounce="search" :placeholder="__('Search by customer or referrer name...')" icon="magnifying-glass" />
            </div>

            {{-- Table --}}
            <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ __('Referrer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Customer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Deal') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Reward') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @forelse ($this->referrals as $referral)
                            <tr wire:key="admin-ref-{{ $referral->id }}">
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                    {{ $referral->referrer?->name ?? 'Unknown' }}
                                    <div class="text-xs text-zinc-400">{{ $referral->referrer?->referral_payment_method }} {{ $referral->referrer?->referral_payment_handle }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                                    {{ $referral->customer?->full_name ?? 'Unknown' }}
                                </td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                    @if ($referral->deal)
                                        <a href="{{ route('deals.show', $referral->deal) }}" class="text-blue-600 hover:underline dark:text-blue-400">
                                            {{ $referral->deal->title }}
                                        </a>
                                    @else
                                        --
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $badgeColor = match($referral->status) {
                                            'lead' => 'zinc',
                                            'quoted' => 'sky',
                                            'signed' => 'indigo',
                                            'deposit_paid' => 'emerald',
                                            'reward_sent' => 'emerald',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge color="{{ $badgeColor }}" size="sm">{{ $referral->status_label }}</flux:badge>
                                </td>
                                <td class="px-4 py-3">
                                    ${{ number_format($referral->reward_amount, 0) }}
                                    @if ($referral->reward_sent_at)
                                        <div class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('Sent') }} {{ $referral->reward_sent_at->format('M j') }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                    {{ $referral->created_at->format('M j, Y') }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($referral->status === 'deposit_paid')
                                        <flux:button size="sm" variant="primary" wire:click="openMarkReward({{ $referral->id }})">
                                            {{ __('Mark Sent') }}
                                        </flux:button>
                                    @elseif ($referral->reward_notes)
                                        <flux:tooltip content="{{ $referral->reward_notes }}">
                                            <flux:badge size="sm" color="zinc">{{ __('Notes') }}</flux:badge>
                                        </flux:tooltip>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    {{ __('No referrals found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Mark Reward Sent Modal --}}
        <flux:modal name="mark-reward-modal" :show="$markingRewardId !== null">
            <div class="space-y-4">
                <flux:heading size="lg">Mark Reward as Sent</flux:heading>
                <flux:subheading>Confirm that you have sent the referral reward to the referrer.</flux:subheading>

                <flux:field>
                    <flux:label>Notes (optional)</flux:label>
                    <flux:textarea wire:model="rewardNotes" placeholder="e.g., Sent via Venmo on 2/9" rows="3" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancelMarkReward">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="markRewardSent">Confirm Sent</flux:button>
                </div>
            </div>
        </flux:modal>
</div>
