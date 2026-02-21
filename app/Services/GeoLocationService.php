<?php

namespace App\Services;

use App\Models\SalesTerritory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoLocationService
{
    /**
     * Detect location from IP address using ip-api.com (free, no API key needed).
     * Returns null on failure. Caches results by IP for 24 hours.
     */
    public function detectFromIp(string $ip): ?array
    {
        if (in_array($ip, ['127.0.0.1', '::1']) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return Cache::remember("geo_ip:{$ip}", now()->addHours(24), function () use ($ip) {
            try {
                $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,city,regionName,zip,lat,lon',
                ]);

                if ($response->successful() && $response->json('status') === 'success') {
                    return [
                        'city' => $response->json('city'),
                        'state' => $response->json('regionName'),
                        'zip' => $response->json('zip'),
                        'lat' => (float) $response->json('lat'),
                        'lng' => (float) $response->json('lon'),
                    ];
                }
            } catch (\Exception $e) {
                Log::debug('GeoLocation detection failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            }

            return null;
        });
    }

    /**
     * Find the SalesTerritory containing the given coordinates.
     * Checks blocks first (most specific), then zones, then regions.
     */
    public function findTerritoryForCoordinates(float $lat, float $lng): ?SalesTerritory
    {
        $query = SalesTerritory::active()
            ->whereNotNull('boundary');

        if (DB::getDriverName() === 'mysql') {
            $query->orderByRaw("FIELD(level, 'block', 'zone', 'region')");
        } else {
            $query->orderByRaw("CASE level WHEN 'block' THEN 1 WHEN 'zone' THEN 2 WHEN 'region' THEN 3 ELSE 4 END");
        }

        $territories = $query->get();

        foreach ($territories as $territory) {
            if ($territory->containsPoint($lat, $lng)) {
                return $territory;
            }
        }

        return null;
    }

    /**
     * Get the local team members for a territory (and its ancestors).
     */
    public function getLocalTeam(SalesTerritory $territory): array
    {
        $team = [
            'territory' => $territory,
            'manager' => $territory->manager,
            'estimators' => $territory->estimators()->with('employeeProfile')->get(),
            'knockers' => $territory->knockers()->with('employeeProfile')->get(),
        ];

        if ($territory->parent && ! $team['manager']) {
            $team['manager'] = $territory->parent->manager;
        }

        return $team;
    }
}
