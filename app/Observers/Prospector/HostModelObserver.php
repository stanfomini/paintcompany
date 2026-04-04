<?php

namespace App\Observers\Prospector;

use App\Services\Prospector\ModelBridgeService;
use Illuminate\Database\Eloquent\Model;

class HostModelObserver
{
    public function __construct(
        private string $prospectorType,
    ) {}

    public function created(Model $model): void
    {
        $this->sync($model);
    }

    public function updated(Model $model): void
    {
        $this->sync($model);
    }

    public function deleted(Model $model): void
    {
        // Don't delete Prospector records — just log
        // The Prospector record may still be needed for call history
    }

    private function sync(Model $model): void
    {
        if (ModelBridgeService::$syncing) {
            return;
        }

        try {
            app(ModelBridgeService::class)->importFromHost($model, $this->prospectorType);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Model bridge sync failed (host → Prospector)', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'type' => $this->prospectorType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
