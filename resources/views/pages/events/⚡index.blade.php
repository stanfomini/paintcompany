<?php

use App\Models\LiveEvent;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Events')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $serviceAreaId = '';

    public function boot(): void
    {
        if (auth()->user()?->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function serviceAreas(): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceArea::query()->orderBy('city')->get();
    }

    #[Computed]
    public function events(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return LiveEvent::query()
            ->with(['serviceArea', 'service'])
            ->when($this->status === 'upcoming', fn ($q) => $q->upcoming())
            ->when($this->status === 'live', fn ($q) => $q->live())
            ->when($this->status === 'ended', fn ($q) => $q->ended())
            ->when($this->status === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))
            ->when($this->serviceAreaId, fn ($q) => $q->forArea((int) $this->serviceAreaId))
            ->orderByDesc('scheduled_at')
            ->paginate(10);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedServiceAreaId(): void
    {
        $this->resetPage();
    }

    public function cancelEvent(int $eventId): void
    {
        if (auth()->user()?->type !== 'internal') {
            abort(403);
        }

        $event = LiveEvent::findOrFail($eventId);

        if (in_array($event->status, ['ended', 'cancelled'])) {
            session()->flash('error', __('This event cannot be cancelled.'));

            return;
        }

        app(\App\Services\LiveEventService::class)->cancelEvent($event);

        unset($this->events);

        session()->flash('success', __('Event cancelled successfully.'));
    }

    protected function getStatusBadgeColor(string $status): string
    {
        return match ($status) {
            'draft' => 'zinc',
            'scheduled' => 'blue',
            'live' => 'red',
            'ended' => 'zinc',
            'cancelled' => 'amber',
            default => 'zinc',
        };
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Events') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage live events for your audience.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('events.create')">
            {{ __('Create Event') }}
        </flux:button>
    </div>

    @if (session('success'))
        <flux:callout color="green" icon="check-circle" class="mb-4">
            <flux:callout.text>{{ session('success') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (session('error'))
        <flux:callout color="red" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        {{-- Status tabs --}}
        <flux:radio.group wire:model.live="status" variant="segmented">
            <flux:radio value="all" label="{{ __('All') }}" />
            <flux:radio value="upcoming" label="{{ __('Upcoming') }}" />
            <flux:radio value="live" label="{{ __('Live') }}" />
            <flux:radio value="ended" label="{{ __('Ended') }}" />
            <flux:radio value="cancelled" label="{{ __('Cancelled') }}" />
        </flux:radio.group>

        {{-- Service area filter --}}
        <flux:select wire:model.live="serviceAreaId" placeholder="{{ __('All Areas') }}" class="w-48">
            <flux:select.option value="">{{ __('All Areas') }}</flux:select.option>
            @foreach ($this->serviceAreas as $area)
                <flux:select.option value="{{ $area->id }}">{{ $area->city }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- Events list --}}
    @if ($this->events->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="calendar" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No events found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create your first event to get started.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" :href="route('events.create')">
                    {{ __('Create Event') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($this->events as $event)
                <div wire:key="event-{{ $event->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center gap-4 min-w-0 flex-1">
                        {{-- Status indicator --}}
                        <div class="shrink-0">
                            @if ($event->status === 'live')
                                <span class="relative flex size-3">
                                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                                </span>
                            @else
                                <span @class([
                                    'inline-flex size-3 rounded-full',
                                    'bg-zinc-300 dark:bg-zinc-600' => in_array($event->status, ['draft', 'ended']),
                                    'bg-blue-500' => $event->status === 'scheduled',
                                    'bg-amber-500' => $event->status === 'cancelled',
                                ])></span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium truncate">{{ $event->title }}</span>
                                <flux:badge size="sm" :color="$this->getStatusBadgeColor($event->status)">
                                    {{ ucfirst($event->status) }}
                                </flux:badge>
                            </div>

                            <div class="mt-1 flex items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($event->scheduled_at)
                                    <span class="flex items-center gap-1">
                                        <flux:icon name="calendar" variant="micro" />
                                        {{ $event->scheduled_at->format('M j, Y g:i A') }}
                                    </span>
                                @endif

                                @if ($event->serviceArea)
                                    <span class="flex items-center gap-1">
                                        <flux:icon name="map-pin" variant="micro" />
                                        {{ $event->serviceArea->city }}
                                    </span>
                                @endif

                                @if ($event->service)
                                    <span class="flex items-center gap-1">
                                        <flux:icon name="wrench-screwdriver" variant="micro" />
                                        {{ $event->service->title }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1 shrink-0">
                        <flux:button size="sm" variant="ghost" icon="pencil" :href="route('events.edit', $event)" title="{{ __('Edit') }}" />

                        @if (in_array($event->status, ['scheduled', 'live']))
                            <flux:button size="sm" variant="ghost" icon="signal" :href="route('events.manage', $event)" title="{{ __('Control Room') }}" />
                        @endif

                        @if (! in_array($event->status, ['ended', 'cancelled']))
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="x-circle"
                                wire:click="cancelEvent({{ $event->id }})"
                                wire:confirm="{{ __('Are you sure you want to cancel this event?') }}"
                                title="{{ __('Cancel Event') }}"
                            />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->events->links() }}
        </div>
    @endif
</div>
