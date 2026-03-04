<?php

use App\Models\Estimate;
use App\Models\EstimateActivity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    public int $limit = 20;

    public bool $showAll = false;

    /** @var array<string, string> */
    protected array $activityIcons = [
        'viewed' => 'eye',
        'sent' => 'paper-airplane',
        'accepted' => 'check-circle',
        'rejected' => 'x-circle',
        'option_toggled' => 'adjustments-horizontal',
        'optional_item_toggled' => 'adjustments-horizontal',
        'note_added' => 'chat-bubble-left',
        'signature_added' => 'pencil-square',
        'photo_added' => 'photo',
        'amendment_created' => 'pencil-square',
        'amendment_acknowledged' => 'check-badge',
        'package_selected' => 'cube',
        'package_tier_selected' => 'cube',
        'packages_modified' => 'wrench-screwdriver',
    ];

    /** @var array<string, string> */
    protected array $activityColors = [
        'viewed' => 'blue',
        'sent' => 'indigo',
        'accepted' => 'green',
        'rejected' => 'red',
        'option_toggled' => 'amber',
        'optional_item_toggled' => 'amber',
        'note_added' => 'cyan',
        'signature_added' => 'emerald',
        'photo_added' => 'purple',
        'amendment_created' => 'amber',
        'amendment_acknowledged' => 'green',
        'package_selected' => 'indigo',
        'package_tier_selected' => 'indigo',
        'packages_modified' => 'amber',
    ];

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    public function getListeners(): array
    {
        return [
            'estimate-updated' => 'refreshActivities',
            'options-updated' => 'refreshActivities',
            'photos-updated' => 'refreshActivities',
            'notes-updated' => 'refreshActivities',
            "echo-private:estimate.{$this->estimate->id},.estimate.area.updated" => 'refreshActivities',
            "echo-private:estimate.{$this->estimate->id},.estimate.photo.updated" => 'refreshActivities',
            "echo-private:estimate.{$this->estimate->id},.estimate.status.changed" => 'refreshActivities',
            "echo-private:estimate.{$this->estimate->id},.estimate.signature.added" => 'refreshActivities',
            "echo-private:estimate.{$this->estimate->id},.estimate.note.added" => 'refreshActivities',
            "echo-private:estimate.{$this->estimate->id},.estimate.options.toggled" => 'refreshActivities',
        ];
    }

    public function loadMore(): void
    {
        $this->limit += 20;
    }

    public function toggleShowAll(): void
    {
        $this->showAll = ! $this->showAll;

        if ($this->showAll) {
            $this->limit = $this->getTotalCount();
        } else {
            $this->limit = 20;
        }
    }

    /**
     * Get activities for the estimate.
     *
     * @return Collection<int, EstimateActivity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->getActivities();
    }

    #[Computed]
    public function totalCount(): int
    {
        return $this->getTotalCount();
    }

    public function getActivities(): Collection
    {
        return $this->estimate
            ->activities()
            ->with('user')
            ->orderByDesc('created_at')
            ->limit($this->limit)
            ->get();
    }

    /**
     * Get the total count of activities.
     */
    public function getTotalCount(): int
    {
        return $this->estimate->activities()->count();
    }

    /**
     * Check if there are more activities to load.
     */
    public function getHasMoreProperty(): bool
    {
        return $this->getTotalCount() > $this->limit;
    }

    /**
     * Refresh activities when estimate is updated.
     */
    public function refreshActivities(): void
    {
        unset($this->activities);
        unset($this->totalCount);
    }

    /**
     * Get the icon for an activity type.
     */
    public function getActivityIcon(string $type): string
    {
        return $this->activityIcons[$type] ?? 'information-circle';
    }

    /**
     * Get the color for an activity type.
     */
    public function getActivityColor(string $type): string
    {
        return $this->activityColors[$type] ?? 'zinc';
    }

    /**
     * Get the user display name for an activity.
     */
    public function getActivityUserName(EstimateActivity $activity): string
    {
        if ($activity->user) {
            return $activity->user->name;
        }

        // Check metadata for customer info
        if ($activity->metadata && isset($activity->metadata['customer_name'])) {
            return $activity->metadata['customer_name'];
        }

        return __('Anonymous');
    }
}; ?>

<div>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="sm">{{ __('Activity') }}</flux:heading>
            @if ($this->totalCount > 0)
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $this->totalCount }} {{ Str::plural('activity', $this->totalCount) }}
                </flux:text>
            @endif
        </div>

        @if ($this->activities->isEmpty())
            <div class="text-center py-8">
                <flux:icon name="clock" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No activity recorded yet.') }}</flux:text>
            </div>
        @else
            <div class="relative">
                {{-- Timeline vertical line --}}
                <div class="absolute left-4 top-2 bottom-2 w-0.5 bg-zinc-200 dark:bg-zinc-700"></div>

                <div class="space-y-4">
                    @foreach ($this->activities as $activity)
                        <div wire:key="activity-{{ $activity->id }}" class="relative flex gap-4">
                            {{-- Icon with background --}}
                            @php
                                $color = $this->getActivityColor($activity->activity_type);
                                $iconColorClasses = match ($color) {
                                    'blue' => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
                                    'indigo' => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400',
                                    'green' => 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400',
                                    'red' => 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
                                    'amber' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
                                    'cyan' => 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400',
                                    'emerald' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
                                    'purple' => 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400',
                                    default => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                };
                            @endphp
                            <div class="relative z-10 flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center {{ $iconColorClasses }}">
                                <flux:icon name="{{ $this->getActivityIcon($activity->activity_type) }}" class="w-4 h-4" />
                            </div>

                            {{-- Content --}}
                            <div class="flex-1 min-w-0 pt-0.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <flux:text class="font-medium">{{ $activity->activity_type_label }}</flux:text>
                                    <span class="text-zinc-300 dark:text-zinc-600">|</span>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $this->getActivityUserName($activity) }}
                                    </flux:text>
                                </div>

                                @if ($activity->description)
                                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-300 mt-1">
                                        {{ $activity->description }}
                                    </flux:text>
                                @endif

                                <div class="flex items-center gap-3 mt-1">
                                    <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ $activity->created_at->diffForHumans() }}
                                    </flux:text>

                                    @if ($activity->ip_address)
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                            {{ $activity->ip_address }}
                                        </flux:text>
                                    @endif
                                </div>

                                {{-- Metadata details (expandable for complex data) --}}
                                @if ($activity->metadata && count($activity->metadata) > 0)
                                    @php
                                        $displayableMetadata = collect($activity->metadata)
                                            ->except(['customer_name'])
                                            ->filter(fn($value) => !is_array($value) && !is_null($value));
                                    @endphp
                                    @if ($displayableMetadata->isNotEmpty())
                                        <details class="mt-2">
                                            <summary class="text-xs text-zinc-400 dark:text-zinc-500 cursor-pointer hover:text-zinc-600 dark:hover:text-zinc-300">
                                                {{ __('Show details') }}
                                            </summary>
                                            <div class="mt-2 text-xs bg-zinc-50 dark:bg-zinc-800 rounded p-2 space-y-1">
                                                @foreach ($displayableMetadata as $key => $value)
                                                    <div class="flex gap-2">
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ Str::headline($key) }}:</span>
                                                        <span class="text-zinc-700 dark:text-zinc-300">{{ $value }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Show more button --}}
            @if ($this->hasMore)
                <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700 text-center">
                    <flux:button variant="ghost" size="sm" wire:click="loadMore" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="loadMore">{{ __('Show more') }}</span>
                        <span wire:loading wire:target="loadMore">{{ __('Loading...') }}</span>
                    </flux:button>
                </div>
            @elseif ($this->totalCount > 20 && !$this->hasMore)
                <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700 text-center">
                    <flux:button variant="ghost" size="sm" wire:click="toggleShowAll">
                        {{ $showAll ? __('Show less') : __('Show all') }}
                    </flux:button>
                </div>
            @endif
        @endif
    </div>
</div>
