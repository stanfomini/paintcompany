<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?\App\Models\Estimate $estimate = null;

    public bool $aiBuildInProgress = false;

    public ?string $aiBuildStatus = null;

    public ?string $aiBuildMessage = null;

    public function mount(?\App\Models\Estimate $estimate = null): void
    {
        $this->estimate = $estimate;

        // Restore AI build state from cache (survives page refresh)
        if ($this->estimate) {
            $cached = Cache::get("ai-build-estimate:{$this->estimate->id}");
            if ($cached && in_array($cached['status'] ?? '', ['building', 'sending'])) {
                $startedAt = isset($cached['started_at']) ? \Carbon\Carbon::parse($cached['started_at']) : null;
                if ($startedAt && $startedAt->diffInMinutes(now()) > 10) {
                    Cache::forget("ai-build-estimate:{$this->estimate->id}");
                } else {
                    $this->aiBuildInProgress = true;
                    $this->aiBuildStatus = $cached['status'];
                    $this->aiBuildMessage = $cached['message'] ?? 'AI is building your estimate...';
                }
            }
        }
    }

    public function triggerAiBuild(): void
    {
        if (! config('ai-build.enabled')) {
            $this->aiBuildMessage = 'AI Build is not currently available.';

            return;
        }

        if (! $this->estimate) {
            return;
        }

        $this->aiBuildInProgress = true;
        $this->aiBuildStatus = 'sending';
        $this->aiBuildMessage = null;

        try {
            $response = Http::withHeaders([
                'X-AI-Build-Secret' => config('ai-build.api_secret'),
            ])->post(config('ai-build.dev_server_url').'/webhooks/ai-build', [
                'estimate_id' => $this->estimate->id,
            ]);

            if ($response->successful()) {
                $this->aiBuildStatus = 'building';
                $this->aiBuildMessage = 'AI is building your estimate. This may take a few minutes...';

                // Persist build state to cache so it survives page refresh
                Cache::put(
                    "ai-build-estimate:{$this->estimate->id}",
                    ['status' => 'building', 'message' => $this->aiBuildMessage, 'started_at' => now()->toISOString()],
                    now()->addMinutes(15)
                );
            } else {
                $this->aiBuildInProgress = false;
                $this->aiBuildStatus = 'failed';
                $this->aiBuildMessage = $response->json('error', 'Failed to start AI build.');
            }
        } catch (\Exception $e) {
            $this->aiBuildInProgress = false;
            $this->aiBuildStatus = 'failed';
            $this->aiBuildMessage = 'Could not connect to AI build service.';
        }
    }

    /**
     * Poll for AI build status updates (runs every 5s while build is in progress).
     */
    public function pollAiBuildStatus(): void
    {
        if (! $this->estimate) {
            return;
        }

        $cached = Cache::get("ai-build-estimate:{$this->estimate->id}");

        if (! $cached) {
            // Build finished or was never started
            $this->aiBuildInProgress = false;

            return;
        }

        $this->aiBuildInProgress = true;
        $this->aiBuildStatus = $cached['status'] ?? 'building';
        $this->aiBuildMessage = $cached['message'] ?? 'AI is working...';

        // If final status, clear cache and refresh areas in-place
        if (in_array($cached['status'] ?? '', ['completed', 'partial', 'failed', 'nothing_to_build', 'unreadable_blueprints'])) {
            Cache::forget("ai-build-estimate:{$this->estimate->id}");
            $this->aiBuildInProgress = false;

            if (in_array($cached['status'], ['completed', 'partial'])) {
                $this->dispatch('refreshEstimateFromDb');
            }
        }
    }

    #[On('echo-private:estimate.{estimate.id},ai.build.completed')]
    public function onAiBuildCompleted(array $data): void
    {
        $this->aiBuildStatus = $data['status'];

        // Progress update -- keep spinner going, just update the message
        if ($data['status'] === 'building') {
            $this->aiBuildInProgress = true;
            $this->aiBuildMessage = $data['summary'] ?? 'AI is working...';

            return;
        }

        // Final status -- stop spinner
        $this->aiBuildInProgress = false;

        // Clear the cache since we got the final status via Reverb
        if ($this->estimate) {
            Cache::forget("ai-build-estimate:{$this->estimate->id}");
        }

        $this->aiBuildMessage = match ($data['status']) {
            'completed' => "AI built {$data['areas_created']} areas with {$data['surfaces_created']} surfaces! Refreshing...",
            'partial' => "AI built {$data['areas_created']} areas but had some issues. Please review.",
            'nothing_to_build' => 'No blueprints, photos, or notes found. Upload materials first.',
            'unreadable_blueprints' => "Couldn't extract text from the blueprints. They may need manual entry.",
            'failed' => $data['error_message'] ?? 'AI build encountered an error.',
            default => 'Build finished with unknown status.',
        };

        if (in_array($data['status'], ['completed', 'partial'])) {
            $this->dispatch('refreshEstimateFromDb');
        }
    }
}; ?>

<div>
    @if ($estimate)
        @if (config('ai-build.enabled'))
            <div class="mb-4 p-3 bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-950/30 dark:to-blue-950/30 rounded-lg border border-purple-200 dark:border-purple-800">
                @if ($aiBuildInProgress)
                    <div wire:poll.5s.visible="pollAiBuildStatus" class="flex items-center gap-2 text-sm text-purple-700 dark:text-purple-300">
                        <flux:icon name="arrow-path" class="w-4 h-4 animate-spin" />
                        <span>{{ $aiBuildMessage ?? 'AI is working...' }}</span>
                    </div>
                @elseif ($aiBuildMessage)
                    <div class="text-sm mb-2 {{ $aiBuildStatus === 'failed' || str_contains($aiBuildStatus ?? '', 'nothing') ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $aiBuildMessage }}
                    </div>
                    <flux:button wire:click="triggerAiBuild" size="sm" class="w-full" variant="primary">
                        <flux:icon name="sparkles" class="w-4 h-4 mr-1" />
                        {{ __('Retry AI Build') }}
                    </flux:button>
                @else
                    <flux:button wire:click="triggerAiBuild" wire:loading.attr="disabled" size="sm" class="w-full" variant="primary">
                        <flux:icon name="sparkles" class="w-4 h-4 mr-1" />
                        <span wire:loading.remove wire:target="triggerAiBuild">{{ $estimate->areas()->count() > 0 ? __('AI Rebuild from Blueprints') : __('AI Build from Blueprints') }}</span>
                        <span wire:loading wire:target="triggerAiBuild">{{ __('Starting...') }}</span>
                    </flux:button>
                    <p class="text-xs text-purple-600 dark:text-purple-400 mt-1 text-center">
                        {{ __('Reads blueprints & photos to auto-build estimate') }}
                    </p>
                @endif
            </div>
        @endif
    @endif
</div>
