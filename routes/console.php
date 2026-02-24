<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crew:check-compliance')->dailyAt('07:00');
Schedule::command('followups:generate-daily')->dailyAt('07:00');
Schedule::command('meetings:send-reminders')->everyFiveMinutes();
Schedule::command('priority-scores:recalculate')->everyFifteenMinutes();
Schedule::command('workflows:process-waiting')->everyFiveMinutes();
Schedule::command('workflows:run-scheduled')->everyFifteenMinutes();
Schedule::command('workflows:cleanup-stale')->daily();
Schedule::command('sla:calculate-metrics')->dailyAt('06:00');
Schedule::command('documents:check-expiration')->dailyAt('08:00');
Schedule::command('territory:expire-offers')->everyMinute();
Schedule::command('api-sources:poll')->everyMinute();
Schedule::command('sitemap:generate')->weekly();
Schedule::job(new \App\Jobs\CheckMessengerTimeoutsJob)->everyFiveMinutes();
Schedule::call(function () {
    app(\App\Services\PlannerDelegationService::class)->expireStale();
})->daily()->name('planner:expire-delegations');
