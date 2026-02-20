<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationIQService
{
    /**
     * Resolve the LocationIQ API key from .env config or database.
     */
    private function getApiKey(): ?string
    {
        return config('services.locationiq.key')
            ?: (CompanySetting::getInstance()->locationiq_api_key ?: null);
    }

    /**
     * Reverse geocode coordinates to a human-readable address.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        $apiKey = $this->getApiKey();

        if (! $apiKey) {
            return $this->nominatimReverseGeocode($lat, $lng);
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

    /**
     * Reverse geocode to structured data { address, city, state, zip }.
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string, display_name: ?string}|null
     */
    public function reverseGeocodeStructured(float $lat, float $lng): ?array
    {
        $apiKey = $this->getApiKey();

        if (! $apiKey) {
            return $this->nominatimReverseGeocodeStructured($lat, $lng);
        }

        try {
            $response = Http::timeout(10)->get('https://us1.locationiq.com/v1/reverse', [
                'key' => $apiKey,
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'json',
                'addressdetails' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $this->parseAddressResponse($data);
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('LocationIQ structured geocode error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Get cached geocode result or fetch from API.
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string}|null
     */
    public function getCachedOrFetch(float $lat, float $lng): ?array
    {
        // Round to 5 decimal places for cache matching (~1m precision)
        $roundedLat = round($lat, 5);
        $roundedLng = round($lng, 5);

        $cached = DB::table('geocode_cache')
            ->where('lat', $roundedLat)
            ->where('lng', $roundedLng)
            ->first();

        if ($cached) {
            return [
                'address' => $cached->address,
                'city' => $cached->city,
                'state' => $cached->state,
                'zip' => $cached->zip,
            ];
        }

        $result = $this->reverseGeocodeStructured($lat, $lng);

        if ($result) {
            DB::table('geocode_cache')->insert([
                'lat' => $roundedLat,
                'lng' => $roundedLng,
                'address' => $result['address'] ?? '',
                'city' => $result['city'],
                'state' => $result['state'],
                'zip' => $result['zip'],
                'raw_response' => json_encode($result),
                'created_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Free Nominatim (OpenStreetMap) reverse geocode - no API key required.
     */
    private function nominatimReverseGeocode(float $lat, float $lng): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ApexNegotiations/1.0'])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                ]);

            if ($response->successful()) {
                return $response->json('display_name');
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Nominatim reverse geocode error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Free Nominatim (OpenStreetMap) structured reverse geocode - no API key required.
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string, display_name: ?string}|null
     */
    private function nominatimReverseGeocodeStructured(float $lat, float $lng): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ApexNegotiations/1.0'])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                    'addressdetails' => 1,
                ]);

            if ($response->successful()) {
                return $this->parseAddressResponse($response->json());
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Nominatim structured geocode error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Parse address response from LocationIQ or Nominatim (same format).
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string, display_name: ?string}
     */
    private function parseAddressResponse(array $data): array
    {
        $address = $data['address'] ?? [];

        return [
            'address' => trim(($address['house_number'] ?? '').' '.($address['road'] ?? '')),
            'city' => $address['city'] ?? $address['town'] ?? $address['village'] ?? null,
            'state' => $address['state'] ?? null,
            'zip' => $address['postcode'] ?? null,
            'display_name' => $data['display_name'] ?? null,
        ];
    }
}
