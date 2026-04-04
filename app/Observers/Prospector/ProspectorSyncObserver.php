<?php

namespace App\Observers\Prospector;

use App\Services\Prospector\ModelBridgeService;
use Illuminate\Database\Eloquent\Model;

class ProspectorSyncObserver
{
    public function __construct(
        private string $prospectorType,
    ) {}

    public function updated(Model $model): void
    {
        if (ModelBridgeService::$syncing) {
            return;
        }

        if (! ModelBridgeService::isConfigured($this->prospectorType)) {
            return;
        }

        try {
            app(ModelBridgeService::class)->exportToHost($model, $this->prospectorType);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Model bridge sync failed (Prospector → host)', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'type' => $this->prospectorType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
