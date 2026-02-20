<?php

namespace App\Console\Commands;

use App\Jobs\PollExternalApiJob;
use App\Models\ExternalApiSource;
use Illuminate\Console\Command;

class PollExternalApisCommand extends Command
{
    protected $signature = 'api-sources:poll';

    protected $description = 'Poll active external API sources that are due for fetching';

    public function handle(): int
    {
        $sources = ExternalApiSource::where('is_active', true)->get();

        $dispatched = 0;

        foreach ($sources as $source) {
            if ($source->shouldPollNow()) {
                PollExternalApiJob::dispatch($source->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} API poll job(s).");

        return self::SUCCESS;
    }
}
