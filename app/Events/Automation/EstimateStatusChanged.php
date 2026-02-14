<?php

namespace App\Events\Automation;

use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EstimateStatusChanged extends AutomationEvent
{
    public function __construct(
        public Estimate $estimate,
        public ?string $oldStatus,
        public string $newStatus,
        public ?User $user = null,
    ) {}

    public function getEventName(): string
    {
        return match ($this->newStatus) {
            'sent' => 'estimate.sent',
            'accepted' => 'estimate.signed',
            'rejected' => 'estimate.rejected',
            default => 'estimate.status_changed',
        };
    }

    public function getSubject(): Model
    {
        return $this->estimate;
    }

    public function getContext(): array
    {
        return [
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'user_id' => $this->user?->id,
            'estimate_id' => $this->estimate->id,
        ];
    }
}
