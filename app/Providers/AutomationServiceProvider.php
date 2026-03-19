<?php

namespace App\Providers;

use App\Automation\Registry;
use App\Events\Automation\ApiPollReceived;
use App\Events\Automation\ApplicationReceived;
use App\Events\Automation\ContactCreated;
use App\Events\Automation\DealCreated;
use App\Events\Automation\DealStageChanged;
use App\Events\Automation\DoorKnockRecorded;
use App\Events\Automation\EstimateStatusChanged;
use App\Events\Automation\InboundMessengerReceived;
use App\Events\Automation\InboundSmsReceived;
use App\Events\Automation\JobStageChanged;
use App\Events\Automation\TaskStatusChanged;
use App\Events\Automation\WebhookReceived;
use App\Listeners\AutomationEventListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Registry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel's Event::listen does not match parent classes for child events,
        // so each concrete event must be registered explicitly.
        $events = [
            ApiPollReceived::class,
            ApplicationReceived::class,
            ContactCreated::class,
            DealCreated::class,
            DealStageChanged::class,
            DoorKnockRecorded::class,
            EstimateStatusChanged::class,
            InboundMessengerReceived::class,
            InboundSmsReceived::class,
            JobStageChanged::class,
            TaskStatusChanged::class,
            WebhookReceived::class,
        ];

        foreach ($events as $event) {
            Event::listen($event, AutomationEventListener::class);
        }
    }
}
