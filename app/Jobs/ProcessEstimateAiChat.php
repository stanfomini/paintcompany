<?php

namespace App\Jobs;

use App\Ai\Agents\EstimateBuilderAgent;
use App\Events\EstimateAiToolCompleted;
use App\Events\EstimateAreaUpdated;
use App\Models\Estimate;
use App\Models\EstimateAiMessage;
use App\Models\EstimateAiThread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessEstimateAiChat implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    /**
     * @param  array<int, array{role: string, content: string}>  $history  Prior conversation messages
     */
    public function __construct(
        public int $estimateId,
        public ?int $threadId = null,
        public string $cacheKey = '',
        public string $userMessage = '',
        public array $history = [],
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        try {
            $estimate = Estimate::findOrFail($this->estimateId);

            $agent = new EstimateBuilderAgent($estimate);
            $agent->withHistory($this->history);

            // Set up progress tracking via the gateway's tool invocation callback
            $cacheKey = $this->cacheKey;
            $gateway = app(\App\Ai\Gateway\XaiTextGateway::class);
            $gateway->onToolInvocation(
                invoking: function ($tool, $args) use ($cacheKey) {
                    $label = $this->getToolProgressLabel($tool);
                    Cache::put("{$cacheKey}:progress", [
                        'label' => $label,
                        'tool' => class_basename($tool),
                        'timestamp' => now()->toIso8601String(),
                    ], now()->addMinutes(5));
                },
                invoked: function ($tool, $args, $result) use ($cacheKey) {
                    // Clear progress after tool completes
                    Cache::forget("{$cacheKey}:progress");

                    $isMutating = $this->isDataMutatingTool($tool);

                    // Broadcast tool completion for real-time UI updates
                    EstimateAiToolCompleted::dispatch(
                        estimateId: $this->estimateId,
                        toolLabel: $this->getToolProgressLabel($tool),
                        dataChanged: $isMutating,
                    );

                    // Also fire EstimateAreaUpdated for area-mutating tools
                    // so ⚡show.blade.php and ⚡estimate-proposal.blade.php auto-refresh
                    if ($isMutating) {
                        EstimateAreaUpdated::dispatch(
                            estimateId: $this->estimateId,
                            areaId: 0,
                            action: 'ai-update',
                            actorName: 'AI Builder',
                        );
                    }
                },
            );

            $response = $agent->prompt($this->userMessage);
            $responseText = (string) $response;

            // Save assistant message to DB
            if ($this->threadId) {
                EstimateAiMessage::create([
                    'thread_id' => $this->threadId,
                    'role' => 'assistant',
                    'content' => $responseText,
                ]);

                EstimateAiThread::find($this->threadId)?->markComplete();
            }

            Cache::put($this->cacheKey, [
                'status' => 'complete',
                'response' => $responseText,
            ], now()->addMinutes(10));
        } catch (\Throwable $e) {
            Log::error('AI Estimate Builder job failed', [
                'estimate_id' => $this->estimateId,
                'thread_id' => $this->threadId,
                'message' => $this->userMessage,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $userMessage = str_contains($e->getMessage(), 'XAI Error')
                ? 'The AI service returned an error. Please try again in a moment.'
                : 'AI error: '.$e->getMessage();

            // Save error message to DB
            if ($this->threadId) {
                EstimateAiMessage::create([
                    'thread_id' => $this->threadId,
                    'role' => 'error',
                    'content' => $userMessage,
                ]);

                EstimateAiThread::find($this->threadId)?->markComplete();
            }

            Cache::put($this->cacheKey, [
                'status' => 'error',
                'response' => $userMessage,
            ], now()->addMinutes(10));
        }
    }

    /**
     * Map tool class to user-friendly progress label.
     */
    protected function getToolProgressLabel(object $tool): string
    {
        $className = class_basename($tool);

        return match ($className) {
            'SearchTemplatesAiTool' => 'Searching templates...',
            'ListProductionRatesAiTool' => 'Looking up production rates...',
            'ListPaintProductsAiTool' => 'Browsing paint products...',
            'ListSubstratesAiTool' => 'Checking surface conditions...',
            'ListNestedTemplatesAiTool' => 'Finding addons...',
            'GetPricingConfigAiTool' => 'Loading pricing config...',
            'GetEstimateStateAiTool' => 'Reading estimate...',
            'UpdateEstimateSettingsAiTool' => 'Updating settings...',
            'GetEstimateSummaryAiTool' => 'Generating summary...',
            'CreateAreaFromTemplateAiTool' => 'Creating room from template...',
            'CreateCustomAreaAiTool' => 'Creating custom area...',
            'AddSurfaceToAreaAiTool' => 'Adding surface...',
            'UpdateSurfaceAiTool' => 'Updating surface...',
            'RemoveSurfaceAiTool' => 'Removing surface...',
            'AddAddonToAreaAiTool' => 'Adding addon...',
            'RemoveAreaAiTool' => 'Removing area...',
            'UpdateAreaDimensionsAiTool' => 'Updating dimensions...',
            'PreviewAreaPricingAiTool' => 'Calculating pricing...',
            'SetEstimatePaintConfigAiTool' => 'Setting paint...',
            'SetAreaPaintProductAiTool' => 'Setting area paint...',
            'SetTierPricingAiTool' => 'Configuring tiers...',
            'RecalculateEstimateAiTool' => 'Recalculating totals...',
            'CreateProductionRateAiTool' => 'Creating production rate...',
            'CreateTemplateAiTool' => 'Creating template...',
            'CreateNestedTemplateAiTool' => 'Creating nested template...',
            'CreateEstimateItemAiTool' => 'Adding line item...',
            'AddCostItemAiTool' => 'Adding cost item...',
            'RemoveCostItemAiTool' => 'Removing cost item...',
            'ListCostItemsAiTool' => 'Listing cost items...',
            'SaveAiContextAiTool' => 'Saving notes...',
            'GetProjectContextAiTool' => 'Loading project context...',
            'WriteEstimateSummaryAiTool' => 'Writing summary...',
            default => 'Working...',
        };
    }

    /**
     * Determine if a tool modifies estimate data (vs read-only discovery).
     */
    protected function isDataMutatingTool(object $tool): bool
    {
        $mutatingTools = [
            'CreateAreaFromTemplateAiTool',
            'CreateCustomAreaAiTool',
            'AddSurfaceToAreaAiTool',
            'UpdateSurfaceAiTool',
            'RemoveSurfaceAiTool',
            'UpdateSurfaceTierOverridesAiTool',
            'AddAddonToAreaAiTool',
            'RemoveAreaAiTool',
            'UpdateAreaDimensionsAiTool',
            'SetEstimatePaintConfigAiTool',
            'SetAreaPaintProductAiTool',
            'SetTierPricingAiTool',
            'RecalculateEstimateAiTool',
            'UpdateEstimateSettingsAiTool',
            'CreateEstimateItemAiTool',
            'AddCostItemAiTool',
            'RemoveCostItemAiTool',
            'SetAreaPackageTiersAiTool',
            'WriteEstimateSummaryAiTool',
        ];

        return in_array(class_basename($tool), $mutatingTools);
    }

    /**
     * Handle job failure (e.g., timeout).
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('AI Estimate Builder job failed (external)', [
            'estimate_id' => $this->estimateId,
            'thread_id' => $this->threadId,
            'message' => $this->userMessage,
            'error' => $exception?->getMessage(),
        ]);

        $errorMessage = 'The AI request timed out. Please try a simpler prompt.';

        // Save error message to DB
        if ($this->threadId) {
            EstimateAiMessage::create([
                'thread_id' => $this->threadId,
                'role' => 'error',
                'content' => $errorMessage,
            ]);

            EstimateAiThread::find($this->threadId)?->markComplete();
        }

        Cache::put($this->cacheKey, [
            'status' => 'error',
            'response' => $errorMessage,
        ], now()->addMinutes(10));
    }
}
