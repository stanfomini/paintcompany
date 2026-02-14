<?php

namespace App\Listeners;

use App\Events\Automation\AutomationEvent;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\Log;

class AutomationEventListener
{
    public function __construct(
        protected WorkflowEngine $engine
    ) {}

    public function handle(AutomationEvent $event): void
    {
        try {
            $this->engine->handleEvent(
                $event->getEventName(),
                $event->getSubject(),
                $event->getContext(),
            );
        } catch (\Throwable $e) {
            Log::error("Automation event handler failed: {$e->getMessage()}", [
                'event' => $event->getEventName(),
                'subject_type' => get_class($event->getSubject()),
                'subject_id' => $event->getSubject()->getKey(),
            ]);
        }
    }
}
