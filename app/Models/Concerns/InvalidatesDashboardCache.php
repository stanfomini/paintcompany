<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Cache;

trait InvalidatesDashboardCache
{
    public static function bootInvalidatesDashboardCache(): void
    {
        static::saved(function () {
            Cache::forget('dashboard.cash_flow');
            Cache::forget('dashboard.alerts');
            Cache::forget('dashboard.pipeline');
            Cache::forget('dashboard.jobs');
        });

        static::deleted(function () {
            Cache::forget('dashboard.cash_flow');
            Cache::forget('dashboard.alerts');
            Cache::forget('dashboard.pipeline');
            Cache::forget('dashboard.jobs');
        });
    }
}
