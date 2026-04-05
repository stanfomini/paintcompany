<?php

namespace App\Console\Commands\Prospector;

use App\Jobs\Prospector\SyncBatchJob;
use App\Models\Prospector\ImportLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class ImportCommand extends Command
{
    protected $signature = 'prospector:import
        {type : The type to import (companies or contacts)}
        {--chunk=100 : Records per batch job}';

    protected $description = 'Import host app records into Prospector via the Model Bridge';

    public function handle(): int
    {
        $type = $this->argument('type');

        if (! in_array($type, ['companies', 'contacts'])) {
            $this->error('Type must be "companies" or "contacts".');

            return self::FAILURE;
        }

        $config = config("prospector-bridge.{$type}");

        if (! $config || ! $config['model']) {
            $this->error("No model configured for {$type} in config/prospector-bridge.php");

            return self::FAILURE;
        }

        if (! app(\App\Services\Prospector\SettingsService::class)->get('bridge_enabled', false)) {
            $this->error('Model Bridge is disabled. Enable it in Prospector Settings → AI Service.');

            return self::FAILURE;
        }

        $modelClass = $config['model'];

        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");

            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk');
        $total = $modelClass::count();

        if ($total === 0) {
            $this->info("No {$type} records found to import.");

            return self::SUCCESS;
        }

        $this->info("Importing {$total} {$type} from {$modelClass}...");

        $importLog = ImportLog::create([
            'type' => $type,
            'status' => 'pending',
        ]);
        $importLog->markRunning($total);

        $jobs = [];
        $modelClass::select('id')->orderBy('id')->chunk($chunkSize, function ($chunk) use (&$jobs, $modelClass, $type, $importLog) {
            $jobs[] = new SyncBatchJob(
                hostIds: $chunk->pluck('id')->all(),
                hostModelClass: $modelClass,
                prospectorType: $type,
                importLogId: $importLog->id,
            );
        });

        if (empty($jobs)) {
            $importLog->markCompleted();
            $this->info('No records to process.');

            return self::SUCCESS;
        }

        $batch = Bus::batch($jobs)
            ->name("Prospector Import: {$type}")
            ->onQueue('default')
            ->then(function () use ($importLog) {
                $importLog->markCompleted();
            })
            ->catch(function ($batch, $e) use ($importLog) {
                $importLog->markFailed($e->getMessage());
            })
            ->dispatch();

        $this->info("Dispatched {$batch->totalJobs} batch jobs (ID: {$batch->id}).");
        $this->info("Track progress: Import Log #{$importLog->id}");

        return self::SUCCESS;
    }
}
