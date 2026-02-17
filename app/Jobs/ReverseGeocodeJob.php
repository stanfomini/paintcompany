<?php

namespace App\Jobs;

use App\Models\TimeEntry;
use App\Services\LocationIQService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReverseGeocodeJob implements ShouldQueue
{
    use Queueable;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $entryId,
        public float $latitude,
        public float $longitude,
        public string $type,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LocationIQService $locationIQ): void
    {
        $entry = TimeEntry::find($this->entryId);

        if (! $entry) {
            return;
        }

        try {
            $address = $locationIQ->reverseGeocode($this->latitude, $this->longitude);

            if ($address) {
                $field = $this->type.'_address';
                $entry->update([$field => $address]);
            }
        } catch (\Throwable $e) {
            Log::warning("Reverse geocode failed for entry {$this->entryId}: ".$e->getMessage());

            throw $e;
        }
    }
}
