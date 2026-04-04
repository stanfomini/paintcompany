<?php

namespace App\Jobs\Prospector;

use App\Models\Prospector\ImportLog;
use App\Services\Prospector\ModelBridgeService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public array $hostIds,
        public string $hostModelClass,
        public string $prospectorType,
        public int $importLogId,
    ) {}

    public function handle(ModelBridgeService $bridge): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importLog = ImportLog::find($this->importLogId);
        $hostModels = ($this->hostModelClass)::whereIn('id', $this->hostIds)->get();

        foreach ($hostModels as $hostModel) {
            try {
                $result = $bridge->importFromHost($hostModel, $this->prospectorType);

                if ($result) {
                    if ($result->wasRecentlyCreated) {
                        $importLog?->incrementCreated();
                    } else {
                        $importLog?->incrementUpdated();
                    }
                }

                $importLog?->incrementProcessed();
            } catch (\Exception $e) {
                $importLog?->incrementErrors([
                    'external_id' => $hostModel->getKey(),
                    'message' => $e->getMessage(),
                ]);

                Log::warning('SyncBatchJob: record failed', [
                    'host_model' => $this->hostModelClass,
                    'host_id' => $hostModel->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
