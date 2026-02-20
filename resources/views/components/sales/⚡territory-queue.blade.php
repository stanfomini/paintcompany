<?php

use App\Models\TerritoryOffer;
use App\Services\TerritoryDispatchService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    public function mount(): void
    {
        //
    }

    #[Computed]
    public function pendingOffers()
    {
        return TerritoryOffer::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereHas('eligibleUsers', function ($q) {
                $q->where('user_id', Auth::id())
                    ->where('response', 'pending');
            })
            ->with(['territory', 'eligibleUsers' => function ($q) {
                $q->where('user_id', Auth::id());
            }])
            ->orderBy('expires_at')
            ->get();
    }

    #[Computed]
    public function claimedToday()
    {
        return TerritoryOffer::query()
            ->where('status', 'claimed')
            ->where('claimed_by', Auth::id())
            ->where('work_date', '>=', today())
            ->with('territory')
            ->orderBy('work_date')
            ->get();
    }

    #[Computed]
    public function recentActivity()
    {
        return TerritoryOffer::query()
            ->where('status', 'claimed')
            ->where('work_date', '>=', today())
            ->with(['territory', 'claimedByUser'])
            ->latest('claimed_at')
            ->limit(10)
            ->get();
    }

    public function claimOffer(int $offerId): void
    {
        $offer = TerritoryOffer::findOrFail($offerId);
        $service = app(TerritoryDispatchService::class);

        $result = $service->claimOffer($offer, Auth::user());

        if ($result) {
            session()->flash('success', 'Territory claimed successfully!');
        } else {
            session()->flash('error', 'This territory has already been claimed or the offer has expired.');
        }

        unset($this->pendingOffers, $this->claimedToday, $this->recentActivity);
    }

    public function declineOffer(int $offerId): void
    {
        $offer = TerritoryOffer::findOrFail($offerId);
        $service = app(TerritoryDispatchService::class);

        $service->declineOffer($offer, Auth::user());

        unset($this->pendingOffers);
    }

    #[On('echo-private:territory-dispatch.user.{userId},.territory.offered')]
    public function handleNewOffer(): void
    {
        unset($this->pendingOffers);
    }

    public function getUserIdProperty(): int
    {
        return Auth::id();
    }
};

?>

<div>
    <div class="mx-auto max-w-4xl py-6 space-y-8">
        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Territory Queue') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Accept or decline territory assignments for door-to-door sales.') }}</flux:text>
            </div>
            <flux:badge color="blue" size="lg">
                {{ $this->pendingOffers->count() }} {{ __('Active Offers') }}
            </flux:badge>
        </div>

        {{-- Active Offers --}}
        @if($this->pendingOffers->isNotEmpty())
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Available Territories') }}</flux:heading>

                @foreach($this->pendingOffers as $offer)
                    <div
                        wire:key="offer-{{ $offer->id }}"
                        x-data="offerCountdown('{{ $offer->expires_at->toIso8601String() }}')"
                        class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm transition-all"
                        :class="{ 'border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20': isUrgent, 'opacity-50 pointer-events-none': expired }"
                    >
                        <div class="flex items-start justify-between gap-4">
                            {{-- Territory Info --}}
                            <div class="flex items-start gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg" style="background-color: {{ $offer->territory->color }}20; border: 2px solid {{ $offer->territory->color }}">
                                    <flux:icon name="map" class="size-6" style="color: {{ $offer->territory->color }}" />
                                </div>
                                <div>
                                    <flux:heading size="lg">{{ $offer->territory->name }}</flux:heading>
                                    @if($offer->territory->description)
                                        <flux:text class="mt-0.5">{{ $offer->territory->description }}</flux:text>
                                    @endif
                                    <div class="mt-2 flex items-center gap-3 text-sm">
                                        <flux:badge size="sm" color="zinc">
                                            <flux:icon name="calendar" class="size-3.5 mr-1" />
                                            {{ $offer->work_date->format('M j, Y') }}
                                        </flux:badge>
                                        <flux:badge size="sm" color="zinc">
                                            #{{ $offer->eligibleUsers->first()?->pivot->queue_position ?? '?' }} {{ __('in queue') }}
                                        </flux:badge>
                                    </div>
                                </div>
                            </div>

                            {{-- Countdown Timer --}}
                            <div class="text-right shrink-0">
                                <div
                                    class="text-2xl font-mono font-bold tabular-nums"
                                    :class="isUrgent ? 'text-red-600 dark:text-red-400 animate-pulse' : 'text-zinc-700 dark:text-zinc-300'"
                                    x-text="display"
                                ></div>
                                <flux:text size="sm">{{ __('remaining') }}</flux:text>
                            </div>
                        </div>

                        {{-- Action Buttons --}}
                        <div class="mt-4 flex items-center gap-3">
                            <flux:button
                                variant="primary"
                                wire:click="claimOffer({{ $offer->id }})"
                                wire:confirm="{{ __('Accept this territory assignment?') }}"
                                class="grow"
                                x-bind:disabled="expired"
                            >
                                <flux:icon name="check" class="size-4 mr-1" />
                                {{ __('Accept Territory') }}
                            </flux:button>
                            <flux:button
                                variant="ghost"
                                wire:click="declineOffer({{ $offer->id }})"
                                wire:confirm="{{ __('Decline this territory? You cannot undo this.') }}"
                                x-bind:disabled="expired"
                            >
                                {{ __('Decline') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <flux:callout icon="signal" variant="outline">
                <flux:callout.heading>{{ __('No Active Offers') }}</flux:callout.heading>
                <flux:callout.text>{{ __('You don\'t have any pending territory offers. Check back soon or ask your manager to dispatch territories.') }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- My Claimed Territories --}}
        @if($this->claimedToday->isNotEmpty())
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('My Territories') }}</flux:heading>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach($this->claimedToday as $claimed)
                        <div wire:key="claimed-{{ $claimed->id }}" class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded" style="background-color: {{ $claimed->territory->color }}">
                                    <flux:icon name="check" class="size-4 text-white" />
                                </div>
                                <div>
                                    <flux:heading size="sm">{{ $claimed->territory->name }}</flux:heading>
                                    <flux:text size="sm">{{ $claimed->work_date->format('M j, Y') }}</flux:text>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Recent Activity Feed --}}
        @if($this->recentActivity->isNotEmpty())
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Recent Claims') }}</flux:heading>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    @foreach($this->recentActivity as $activity)
                        <div wire:key="activity-{{ $activity->id }}" class="flex items-center gap-3 px-4 py-3">
                            <flux:avatar :name="$activity->claimedByUser?->name ?? 'Unknown'" size="sm" />
                            <div class="flex-1 min-w-0">
                                <flux:text>
                                    <strong>{{ $activity->claimedByUser?->name }}</strong>
                                    {{ __('claimed') }}
                                    <strong>{{ $activity->territory->name }}</strong>
                                </flux:text>
                            </div>
                            <flux:text size="sm" class="shrink-0">
                                {{ $activity->claimed_at?->diffForHumans() }}
                            </flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Reverb Listeners for Real-Time Claim/Expire on Active Offers --}}
    <script>
        let subscribedOffers = new Set();

        function subscribeToOffers(wire) {
            if (!window.Echo) return;

            let raw = wire.pendingOffers || [];
            let offers = Array.isArray(raw) ? raw : Object.values(raw);
            offers.forEach(offer => {
                if (subscribedOffers.has(offer.id)) return;
                subscribedOffers.add(offer.id);

                Echo.private(`territory-dispatch.offer.${offer.id}`)
                    .listen('.territory.claimed', () => {
                        wire.$refresh();
                    })
                    .listen('.territory.offer.expired', () => {
                        wire.$refresh();
                    });
            });
        }

        // Subscribe on initial load
        subscribeToOffers($wire);

        // Re-subscribe after component updates (new offers may appear)
        Livewire.hook('morph.updated', () => {
            queueMicrotask(() => subscribeToOffers($wire));
        });
    </script>
</div>
