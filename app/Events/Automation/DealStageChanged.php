<?php

namespace App\Events\Automation;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DealStageChanged extends AutomationEvent
{
    public function __construct(
        public Deal $deal,
        public string $fromStage,
        public string $toStage,
        public ?User $user = null,
    ) {}

    public function getEventName(): string
    {
        return 'deal.stage_changed';
    }

    public function getSubject(): Model
    {
        return $this->deal;
    }

    public function getContext(): array
    {
        return [
            'from_stage' => $this->fromStage,
            'to_stage' => $this->toStage,
            'user_id' => $this->user?->id,
        ];
    }
}
