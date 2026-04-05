<?php

namespace App\Observers\Prospector;

use App\Services\Prospector\ModelBridgeService;
use Illuminate\Database\Eloquent\Model;

class ProspectorSyncObserver
{
    public function updated(Model $model): void
    {
        if (ModelBridgeService::$syncing) {
            return;
        }

        $prospectorType = match ($model::class) {
            \App\Models\Prospector\Company::class => 'companies',
            \App\Models\Prospector\Contact::class => 'contacts',
            default => null,
        };

        if (! $prospectorType || ! ModelBridgeService::isConfigured($prospectorType)) {
            return;
        }

        try {
            app(ModelBridgeService::class)->exportToHost($model, $prospectorType);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Model bridge sync failed (Prospector → host)', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'type' => $prospectorType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
