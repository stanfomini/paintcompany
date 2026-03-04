<?php

namespace App\Jobs;

use App\Services\WorkflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EvaluateWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [5, 15];

    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public string $modelType,
        public int $modelId,
        public string $modelClass,
        public string $event,
        public array $changedFields = [],
    ) {}

    public function handle(): void
    {
        $model = $this->modelClass::find($this->modelId);

        if (! $model) {
            return;
        }

        try {
            app(WorkflowService::class)->evaluate($this->modelType, $model, $this->event, $this->changedFields);
        } catch (\Throwable $e) {
            Log::error("EvaluateWorkflowJob error: {$e->getMessage()}", [
                'model' => $this->modelClass,
                'model_id' => $this->modelId,
                'event' => $this->event,
            ]);

            throw $e;
        }
    }
}
