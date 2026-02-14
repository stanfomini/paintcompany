<?php

namespace App\Providers;

use App\Automation\Registry;
use App\Events\Automation\AutomationEvent;
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
        // Listen for all automation events (base class listener)
        Event::listen(AutomationEvent::class, AutomationEventListener::class);
    }
}
