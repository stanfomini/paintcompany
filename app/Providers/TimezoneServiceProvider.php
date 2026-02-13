<?php

namespace App\Providers;

use App\Models\CompanySetting;
use Illuminate\Support\ServiceProvider;

class TimezoneServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * Sets the application timezone from the company settings.
     * Single-tenant app: one company = one timezone for everything.
     */
    public function boot(): void
    {
        try {
            $timezone = CompanySetting::getInstance()->timezone;

            if ($timezone) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
            }
        } catch (\Throwable) {
            // Table may not exist on fresh install / during migrations.
            // Fall back to config/app.php default.
        }
    }
}
