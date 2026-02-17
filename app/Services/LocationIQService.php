<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationIQService
{
    /**
     * Reverse geocode coordinates to a human-readable address.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        $apiKey = CompanySetting::getInstance()->locationiq_api_key;

        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://us1.locationiq.com/v1/reverse', [
                'key' => $apiKey,
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'json',
            ]);

            if ($response->successful()) {
                return $response->json('display_name');
            }

            Log::warning('LocationIQ reverse geocode failed', [
                'status' => $response->status(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('LocationIQ reverse geocode error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Calculate the distance between two GPS coordinates in feet using the Haversine formula.
     */
    public function calculateDistanceFeet(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusFeet = 20902231; // Earth radius in feet

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusFeet * $c, 0);
    }
}
