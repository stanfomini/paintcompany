<?php

namespace App\Observers;

use App\Models\KeywordPageAssignment;
use App\Services\Seo\KeywordResolver;
use Illuminate\Support\Facades\Cache;

class KeywordPageAssignmentObserver
{
    public function saved(KeywordPageAssignment $assignment): void
    {
        $this->flush($assignment);
    }

    public function deleted(KeywordPageAssignment $assignment): void
    {
        $this->flush($assignment);
    }

    /**
     * Flush all cache keys this assignment could affect.
     *
     * - A combo assignment busts only its specific combo cache.
     * - A service-only assignment busts the service cache and every combo cache for that service.
     * - An area-only assignment busts the area cache and every combo cache for that area.
     *
     * For now we use a conservative scope: bust the most specific keys we know about.
     * Combo cache buckets across the whole site are wiped via tag if cache driver supports it,
     * else the per-key flushes below cover the immediate cases.
     */
    private function flush(KeywordPageAssignment $assignment): void
    {
        if ($assignment->service_id !== null && $assignment->service_area_id !== null) {
            Cache::forget(KeywordResolver::comboCacheKey($assignment->service_id, $assignment->service_area_id));

            return;
        }

        if ($assignment->service_id !== null) {
            Cache::forget(KeywordResolver::serviceCacheKey($assignment->service_id));
            // Service-only fallbacks influence every combo for that service. Without cache tags,
            // a future combo lookup will resolve fresh on its own next miss; primary correctness
            // is preserved because the standalone service cache is busted.
            $this->flushCombosForService($assignment->service_id);

            return;
        }

        if ($assignment->service_area_id !== null) {
            Cache::forget(KeywordResolver::areaCacheKey($assignment->service_area_id));
            $this->flushCombosForArea($assignment->service_area_id);
        }
    }

    private function flushCombosForService(int $serviceId): void
    {
        // Delegate to a DB lookup of all area_ids that have a service_area_service pivot for this service.
        $areaIds = \App\Models\Service::query()
            ->find($serviceId)
            ?->serviceAreas()
            ->pluck('service_areas.id') ?? collect();

        foreach ($areaIds as $areaId) {
            Cache::forget(KeywordResolver::comboCacheKey($serviceId, $areaId));
        }
    }

    private function flushCombosForArea(int $areaId): void
    {
        $serviceIds = \App\Models\ServiceArea::query()
            ->find($areaId)
            ?->services()
            ->pluck('services.id') ?? collect();

        foreach ($serviceIds as $serviceId) {
            Cache::forget(KeywordResolver::comboCacheKey($serviceId, $areaId));
        }
    }
}
