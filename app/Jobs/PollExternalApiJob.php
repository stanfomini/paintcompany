<?php

namespace App\Jobs;

use App\Events\Automation\ApiPollReceived;
use App\Models\ExternalApiSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

class PollExternalApiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $sourceId,
    ) {}

    public function handle(): void
    {
        $source = ExternalApiSource::find($this->sourceId);

        if (! $source || ! $source->is_active) {
            return;
        }

        if (! $source->url) {
            return;
        }

        try {
            $request = $source->buildHttpRequest();
            $method = strtolower($source->http_method ?? 'get');
            $response = $request->{$method}($source->url);

            if (! $response->successful()) {
                $source->update([
                    'last_polled_at' => now(),
                    'error_count' => $source->error_count + 1,
                    'last_error' => "HTTP {$response->status()}: ".substr($response->body(), 0, 500),
                ]);

                return;
            }

            $data = $response->json();
            $responseHash = md5(json_encode($data));

            // Extract records using data_path
            $records = $this->extractRecords($data, $source->data_path);

            // Deduplicate
            $processedIds = $source->processed_ids ?? [];
            $newRecords = [];

            foreach ($records as $record) {
                $dedupKey = $this->getDedupKey($record, $source->dedup_field);

                if (! in_array($dedupKey, $processedIds, true)) {
                    $newRecords[] = $record;
                    $processedIds[] = $dedupKey;
                }
            }

            // Cap processed_ids at 1000
            if (count($processedIds) > 1000) {
                $processedIds = array_slice($processedIds, -1000);
            }

            // Update source metadata
            $source->update([
                'last_response' => $data,
                'last_response_hash' => $responseHash,
                'processed_ids' => $processedIds,
                'last_polled_at' => now(),
                'last_success_at' => now(),
                'poll_count' => $source->poll_count + 1,
                'last_error' => null,
            ]);

            // Fire events for each new record
            foreach ($newRecords as $record) {
                event(new ApiPollReceived($source, $record));
            }
        } catch (\Exception $e) {
            $source->update([
                'last_polled_at' => now(),
                'error_count' => $source->error_count + 1,
                'last_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract records from the response using a dot-notation data path.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractRecords(mixed $data, ?string $dataPath): array
    {
        if (! is_array($data)) {
            return [];
        }

        if ($dataPath) {
            $extracted = Arr::get($data, $dataPath);
        } else {
            $extracted = $data;
        }

        if (! is_array($extracted)) {
            return [];
        }

        // If it's a list of records, return as-is
        if (array_is_list($extracted) && ! empty($extracted)) {
            return $extracted;
        }

        // If it's a single associative array, wrap it
        if (! empty($extracted) && ! array_is_list($extracted)) {
            return [$extracted];
        }

        return [];
    }

    /**
     * Generate a dedup key for a record.
     */
    protected function getDedupKey(mixed $record, ?string $dedupField): string
    {
        if ($dedupField && is_array($record)) {
            $value = Arr::get($record, $dedupField);

            return $value !== null ? (string) $value : md5(json_encode($record));
        }

        return md5(json_encode($record));
    }
}
