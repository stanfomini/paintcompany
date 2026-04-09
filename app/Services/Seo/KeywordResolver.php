<?php

namespace App\Services\Seo;

use App\Models\Keyword;
use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class KeywordResolver
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Resolve the keyword bundle (primary + secondary + supporting) for a service+area combo page.
     *
     * Specificity hierarchy when multiple matches exist:
     *   3 = combo-specific (both FKs set)
     *   2 = service-only fallback (service_id set, area null)
     *   1 = area-only fallback  (area_id set, service null)
     *
     * Each keyword appears at most once in the returned bundle, using its highest-specificity
     * matching assignment to determine its role.
     *
     * @return array{primary: ?Keyword, secondary: Collection<int, Keyword>, supporting: Collection<int, Keyword>}
     */
    public function forCombo(Service $service, ServiceArea $serviceArea): array
    {
        return Cache::remember(
            self::comboCacheKey($service->id, $serviceArea->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolveCombo($service, $serviceArea),
        );
    }

    /**
     * @return array{primary: ?Keyword, secondary: Collection<int, Keyword>, supporting: Collection<int, Keyword>}
     */
    public function forServiceOnly(Service $service): array
    {
        return Cache::remember(
            self::serviceCacheKey($service->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolveBy(
                Keyword::query()
                    ->forServiceOnly($service)
                    ->with(['assignments' => fn ($q) => $q->where('service_id', $service->id)->whereNull('service_area_id')])
                    ->get(),
                $service,
                null,
            ),
        );
    }

    /**
     * @return array{primary: ?Keyword, secondary: Collection<int, Keyword>, supporting: Collection<int, Keyword>}
     */
    public function forAreaOnly(ServiceArea $serviceArea): array
    {
        return Cache::remember(
            self::areaCacheKey($serviceArea->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolveBy(
                Keyword::query()
                    ->forAreaOnly($serviceArea)
                    ->with(['assignments' => fn ($q) => $q->whereNull('service_id')->where('service_area_id', $serviceArea->id)])
                    ->get(),
                null,
                $serviceArea,
            ),
        );
    }

    public static function comboCacheKey(int $serviceId, int $areaId): string
    {
        return "kw:combo:{$serviceId}:{$areaId}";
    }

    public static function serviceCacheKey(int $serviceId): string
    {
        return "kw:service:{$serviceId}";
    }

    public static function areaCacheKey(int $areaId): string
    {
        return "kw:area:{$areaId}";
    }

    /**
     * @return array{primary: ?Keyword, secondary: Collection<int, Keyword>, supporting: Collection<int, Keyword>}
     */
    private function resolveCombo(Service $service, ServiceArea $serviceArea): array
    {
        $keywords = Keyword::query()
            ->forCombo($service, $serviceArea)
            ->with(['assignments' => function ($q) use ($service, $serviceArea) {
                $q->where(function ($inner) use ($service, $serviceArea) {
                    $inner->where(function ($combo) use ($service, $serviceArea) {
                        $combo->where('service_id', $service->id)->where('service_area_id', $serviceArea->id);
                    })->orWhere(function ($svc) use ($service) {
                        $svc->where('service_id', $service->id)->whereNull('service_area_id');
                    })->orWhere(function ($area) use ($serviceArea) {
                        $area->whereNull('service_id')->where('service_area_id', $serviceArea->id);
                    });
                });
            }])
            ->get();

        return $this->resolveBy($keywords, $service, $serviceArea);
    }

    /**
     * Group resolved keywords by role using each keyword's most-specific matching assignment.
     *
     * @param  Collection<int, Keyword>  $keywords
     * @return array{primary: ?Keyword, secondary: Collection<int, Keyword>, supporting: Collection<int, Keyword>}
     */
    private function resolveBy(Collection $keywords, ?Service $service, ?ServiceArea $serviceArea): array
    {
        $primary = null;
        $secondary = collect();
        $supporting = collect();

        // For each keyword, pick its highest-specificity assignment that matches this page,
        // and bucket by that assignment's role.
        foreach ($keywords as $keyword) {
            $bestAssignment = $keyword->assignments
                ->filter(fn ($a) => $a->matches($service, $serviceArea))
                ->sortByDesc('specificity')
                ->first();

            if ($bestAssignment === null) {
                continue;
            }

            match ($bestAssignment->role) {
                'primary' => $primary ??= $keyword,
                'secondary' => $secondary->push($keyword),
                'supporting' => $supporting->push($keyword),
                default => null,
            };
        }

        return [
            'primary' => $primary,
            'secondary' => $secondary->values(),
            'supporting' => $supporting->values(),
        ];
    }
}
