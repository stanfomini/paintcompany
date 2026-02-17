<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ListingScraperService
{
    protected PythonBridgeService $python;

    public function __construct(PythonBridgeService $python)
    {
        $this->python = $python;
    }

    /**
     * Check if scraping is available.
     */
    public function isAvailable(): bool
    {
        return $this->python->isEnabled() && $this->python->healthCheck();
    }

    /**
     * Scrape a listing URL and return mapped data.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public function scrapeAndMap(string $url): array
    {
        if (! $this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'Scraping service is not available.',
            ];
        }

        // Validate URL
        if (! $this->isValidListingUrl($url)) {
            return [
                'success' => false,
                'error' => 'Please enter a valid Zillow or Realtor.com listing URL.',
            ];
        }

        try {
            $result = $this->python->scrapeListing($url);

            if (! ($result['success'] ?? false)) {
                $error = $result['error'] ?? 'Failed to scrape listing.';

                // Check if blocked by anti-bot measures
                if ($result['data']['blocked'] ?? false) {
                    $error = 'The listing site is blocking automated requests. Please enter the property details manually.';
                }

                return [
                    'success' => false,
                    'error' => $error,
                    'blocked' => $result['data']['blocked'] ?? false,
                ];
            }

            $data = $result['data'] ?? [];

            return [
                'success' => true,
                'data' => $this->mapToFormFields($data),
                'raw' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Listing scraper error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'An error occurred while scraping. Please try again.',
            ];
        }
    }

    /**
     * Check if URL is a valid listing URL.
     */
    public function isValidListingUrl(string $url): bool
    {
        $url = strtolower($url);

        return Str::contains($url, ['zillow.com', 'realtor.com']);
    }

    /**
     * Detect the source from URL.
     */
    public function detectSource(string $url): ?string
    {
        $url = strtolower($url);

        if (Str::contains($url, 'zillow.com')) {
            return 'zillow';
        }

        if (Str::contains($url, 'realtor.com')) {
            return 'realtor';
        }

        return null;
    }

    /**
     * Map scraped data to form fields.
     */
    protected function mapToFormFields(array $data): array
    {
        $address = $this->parseAddress($data['address'] ?? '');

        return [
            // Address components
            'address' => $address['street'],
            'city' => $address['city'],
            'state' => $address['state'],
            'zip' => $address['zip'],

            // Property details
            'listing_price' => $data['price'] ?? null,
            'square_footage' => $data['sqft'] ?? null,
            'year_built' => $data['year_built'] ?? null,
            'type' => $this->mapPropertyType($data['property_type'] ?? ''),
            'bedrooms' => $data['bedrooms'] ?? null,
            'bathrooms' => $data['bathrooms'] ?? null,
            'lot_size' => $data['lot_size'] ?? null,

            // Text fields
            'description' => $data['description'] ?? '',
            'name' => $address['street'], // Use street address as name

            // Agent info
            'agent_name' => $data['agent_name'] ?? null,
            'agent_phone' => $data['agent_phone'] ?? null,
            'mls_number' => $data['mls_number'] ?? null,

            // Photos
            'photos' => $data['photos'] ?? [],

            // Source URL
            'source_url' => $data['url'] ?? null,
            'source' => $data['source'] ?? null,
        ];
    }

    /**
     * Parse a full address string into components.
     */
    protected function parseAddress(string $fullAddress): array
    {
        $result = [
            'street' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
        ];

        if (empty($fullAddress)) {
            return $result;
        }

        // Common patterns:
        // "123 Main St, Austin, TX 78701"
        // "123 Main St, Austin, Texas 78701"
        // "123 Main Street Austin TX 78701"

        // Try to match: Street, City, State ZIP
        $pattern = '/^(.+?),\s*(.+?),\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)?$/i';

        if (preg_match($pattern, $fullAddress, $matches)) {
            $result['street'] = trim($matches[1]);
            $result['city'] = trim($matches[2]);
            $result['state'] = strtoupper(trim($matches[3]));
            $result['zip'] = trim($matches[4] ?? '');

            return $result;
        }

        // Try alternate pattern without comma after city
        $pattern2 = '/^(.+?),\s*(.+?)\s+([A-Z]{2})\s*(\d{5}(?:-\d{4})?)?$/i';

        if (preg_match($pattern2, $fullAddress, $matches)) {
            $result['street'] = trim($matches[1]);
            $result['city'] = trim($matches[2]);
            $result['state'] = strtoupper(trim($matches[3]));
            $result['zip'] = trim($matches[4] ?? '');

            return $result;
        }

        // Fallback: just use the whole thing as street
        $result['street'] = $fullAddress;

        return $result;
    }

    /**
     * Map property type from scraper to our types.
     */
    protected function mapPropertyType(?string $type): string
    {
        if (empty($type)) {
            return 'residential';
        }

        $type = strtolower($type);

        $commercialTypes = ['commercial', 'office', 'retail', 'industrial', 'warehouse', 'business'];
        $industrialTypes = ['industrial', 'warehouse', 'manufacturing', 'factory'];

        foreach ($industrialTypes as $keyword) {
            if (Str::contains($type, $keyword)) {
                return 'industrial';
            }
        }

        foreach ($commercialTypes as $keyword) {
            if (Str::contains($type, $keyword)) {
                return 'commercial';
            }
        }

        return 'residential';
    }

    /**
     * Download and store photos from URLs.
     *
     * @param  array<string>  $photoUrls
     * @return array<string> Local file paths
     */
    public function downloadPhotos(array $photoUrls, string $storagePath = 'listing-photos'): array
    {
        $downloadedPaths = [];

        foreach ($photoUrls as $index => $url) {
            try {
                $contents = file_get_contents($url);

                if ($contents === false) {
                    continue;
                }

                // Determine extension from URL or default to jpg
                $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = Str::uuid().'.'.$extension;
                $path = $storagePath.'/'.$filename;

                \Illuminate\Support\Facades\Storage::put($path, $contents);
                $downloadedPaths[] = $path;

                // Limit to 10 photos
                if (count($downloadedPaths) >= 10) {
                    break;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to download listing photo', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $downloadedPaths;
    }
}
