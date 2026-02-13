<?php

namespace App\Jobs;

use App\Events\ListingDataScraped;
use App\Models\Deal;
use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ScrapeListingForDealJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public Deal $deal,
        public string $url,
    ) {}

    public function handle(PythonBridgeService $python): void
    {
        try {
            $result = $python->scrapeListing($this->url);

            $this->deal->update([
                'listing_data' => $result,
                'listing_scraped_at' => now(),
            ]);

            $summary = '';
            if (! empty($result['sqft'])) {
                $summary .= $result['sqft'].' sqft';
            }
            if (! empty($result['beds'])) {
                $summary .= ($summary ? ', ' : '').$result['beds'].' bed';
            }
            if (! empty($result['baths'])) {
                $summary .= ($summary ? ', ' : '').$result['baths'].' bath';
            }

            ListingDataScraped::dispatch($this->deal->id, true, $summary ?: 'Property data imported');
        } catch (\Throwable $e) {
            Log::warning('Listing scrape failed for deal '.$this->deal->id.': '.$e->getMessage());

            ListingDataScraped::dispatch($this->deal->id, false, 'Could not import listing data');
        }
    }
}
