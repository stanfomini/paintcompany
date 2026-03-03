<?php

use App\Services\StudioAiService;
use Livewire\Component;

new class extends Component {
    public string $prompt = '';
    public bool $isProcessing = false;
    public ?string $statusMessage = null;
    public ?int $generatedProjectId = null;

    public function generate(): void
    {
        $this->validate(['prompt' => 'required|min:3']);
        $this->isProcessing = true;
        $this->statusMessage = 'AI is working...';

        try {
            $service = $this->resolveAiService();
            $project = $service->generateFromPrompt($this->prompt);
            $this->generatedProjectId = $project->id;
            $this->statusMessage = 'Video project created!';
            $this->dispatch('ai-project-created', projectId: $project->id);
        } catch (\Exception $e) {
            $this->statusMessage = 'Something went wrong. Please try again.';
            report($e);
        } finally {
            $this->isProcessing = false;
        }
    }

    public function resetPrompt(): void
    {
        $this->prompt = '';
        $this->statusMessage = null;
        $this->generatedProjectId = null;
    }

    protected function resolveAiService(): StudioAiService
    {
        if (app()->bound(StudioAiService::class)) {
            return app(StudioAiService::class);
        }

        return new StudioAiService(auth()->user());
    }
};
?>

<div>
    <div class="flex items-center gap-2">
        <div class="flex-1">
            <flux:input
                wire:model="prompt"
                wire:keydown.enter="generate"
                placeholder="{{ __('Ask AI to create a video...') }}"
                icon="sparkles"
                :disabled="$isProcessing"
            />
        </div>
        <flux:button wire:click="generate" :disabled="$isProcessing" variant="primary" icon="sparkles">
            {{ $isProcessing ? __('Working...') : __('Ask AI') }}
        </flux:button>
    </div>

    @if($statusMessage)
        <div class="mt-2 text-sm {{ $generatedProjectId ? 'text-green-500' : ($isProcessing ? 'text-zinc-400' : 'text-red-400') }}">
            @if($isProcessing)
                <span class="inline-flex items-center gap-2">
                    <svg class="animate-spin size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    {{ $statusMessage }}
                </span>
            @else
                {{ $statusMessage }}
                @if($generatedProjectId)
                    <a href="{{ route('studio.create', $generatedProjectId) }}" class="underline font-medium ml-1">
                        {{ __('Open in editor') }} &rarr;
                    </a>
                @endif
            @endif
        </div>
    @endif
</div>
