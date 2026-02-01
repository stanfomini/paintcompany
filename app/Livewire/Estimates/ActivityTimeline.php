<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateActivity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class ActivityTimeline extends Component
{
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
        'note_added' => 'chat-bubble-left',
        'signature_added' => 'pencil-square',
        'photo_added' => 'photo',
    ];

    /** @var array<string, string> */
    protected array $activityColors = [
        'viewed' => 'blue',
        'sent' => 'indigo',
        'accepted' => 'green',
        'rejected' => 'red',
        'option_toggled' => 'amber',
        'note_added' => 'cyan',
        'signature_added' => 'emerald',
        'photo_added' => 'purple',
    ];

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
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
    #[On('estimate-updated')]
    #[On('options-updated')]
    #[On('photos-updated')]
    #[On('notes-updated')]
    public function refreshActivities(): void
    {
        // The view will re-render automatically, refreshing the activities
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

        return 'Anonymous';
    }

    public function render(): View
    {
        return view('livewire.estimates.activity-timeline', [
            'activities' => $this->getActivities(),
            'totalCount' => $this->getTotalCount(),
        ]);
    }
}
