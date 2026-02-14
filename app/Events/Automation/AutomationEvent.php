<?php

namespace App\Events\Automation;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class AutomationEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The event name used by the workflow engine.
     */
    abstract public function getEventName(): string;

    /**
     * The subject model that triggered this event.
     */
    abstract public function getSubject(): Model;

    /**
     * Additional context data for the workflow engine.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return [];
    }
}
