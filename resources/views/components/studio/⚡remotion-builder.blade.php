<?php

use App\Models\RemotionBuildRequest;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $prompt = '';

    public ?int $activeBuildId = null;

    public function mount(): void
    {
        // Restore active build if one is still running
        $activeBuild = RemotionBuildRequest::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', ['pending', 'building'])
            ->latest()
            ->first();

        if ($activeBuild) {
            $this->activeBuildId = $activeBuild->id;
        }
    }

    public function generate(): void
    {
        abort_unless(auth()->check(), 403);

        $this->validate([
            'prompt' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $build = RemotionBuildRequest::create([
            'user_id' => auth()->id(),
            'prompt' => $this->prompt,
            'status' => 'pending',
        ]);

        \Illuminate\Support\Facades\Artisan::queue('remotion:build', ['requestId' => $build->id]);

        $this->activeBuildId = $build->id;
        $this->prompt = '';
    }

    public function cancelBuild(): void
    {
        if (! $this->activeBuildId) {
            return;
        }

        $build = RemotionBuildRequest::find($this->activeBuildId);

        if ($build && $build->user_id === auth()->id()) {
            $build->markFailed('Cancelled by user');
            exec("tmux kill-session -t remotion-build-{$build->id} 2>/dev/null");
        }

        $this->activeBuildId = null;
    }

    #[Computed]
    public function activeBuild(): ?RemotionBuildRequest
    {
        if (! $this->activeBuildId) {
            return null;
        }

        return RemotionBuildRequest::find($this->activeBuildId);
    }

    #[Computed]
    public function recentBuilds(): \Illuminate\Database\Eloquent\Collection
    {
        return RemotionBuildRequest::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->limit(5)
            ->get();
    }
}; ?>

<div>
    {{-- Build Form --}}
    @if (!$this->activeBuild || in_array($this->activeBuild->status, ['completed', 'failed']))
        <div class="space-y-3">
            <flux:textarea
                wire:model="prompt"
                placeholder="{{ __('Describe the video you want to create...') }}"
                rows="3"
            />
            @error('prompt')
                <p class="text-xs text-red-500">{{ $message }}</p>
            @enderror
            <flux:button wire:click="generate" wire:loading.attr="disabled" variant="primary" size="sm" class="w-full">
                <span wire:loading.remove wire:target="generate">
                    <flux:icon name="sparkles" class="size-4 mr-1" />
                    {{ __('Generate Video') }}
                </span>
                <span wire:loading wire:target="generate">
                    {{ __('Starting...') }}
                </span>
            </flux:button>
        </div>
    @endif

    {{-- Active Build Progress --}}
    @if ($this->activeBuild && in_array($this->activeBuild->status, ['pending', 'building']))
        <div class="rounded-lg border border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-900/20 p-3 space-y-2">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="size-2 rounded-full bg-yellow-500 animate-pulse"></div>
                    <span class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                        {{ $this->activeBuild->status === 'pending' ? __('Queued...') : __('Building...') }}
                    </span>
                </div>
                <flux:button wire:click="cancelBuild" size="sm" variant="ghost" class="text-red-600 dark:text-red-400">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
            <p class="text-xs text-zinc-600 dark:text-zinc-400 truncate">{{ $this->activeBuild->prompt }}</p>
        </div>
    @endif

    {{-- Completed Build --}}
    @if ($this->activeBuild && $this->activeBuild->status === 'completed')
        <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-3 space-y-2">
            <div class="flex items-center gap-2">
                <flux:icon name="check-circle" class="size-4 text-green-600" />
                <span class="text-sm font-medium text-green-800 dark:text-green-200">{{ __('Video Ready!') }}</span>
            </div>
            <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Your AI-generated video is available in the media picker.') }}</p>
        </div>
    @endif

    {{-- Failed Build --}}
    @if ($this->activeBuild && $this->activeBuild->status === 'failed')
        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3 space-y-2">
            <div class="flex items-center gap-2">
                <flux:icon name="x-circle" class="size-4 text-red-600" />
                <span class="text-sm font-medium text-red-800 dark:text-red-200">{{ __('Build Failed') }}</span>
            </div>
            @if ($this->activeBuild->error_message)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $this->activeBuild->error_message }}</p>
            @endif
        </div>
    @endif

    {{-- Recent Builds --}}
    @if ($this->recentBuilds->isNotEmpty())
        <div class="mt-4 space-y-1">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Recent') }}</span>
            @foreach ($this->recentBuilds as $build)
                <div wire:key="build-{{ $build->id }}" class="flex items-center justify-between text-xs py-1">
                    <span class="truncate text-zinc-600 dark:text-zinc-400 max-w-[200px]">{{ $build->prompt }}</span>
                    @if ($build->status === 'completed')
                        <flux:badge size="sm" color="green">{{ __('Done') }}</flux:badge>
                    @elseif ($build->status === 'failed')
                        <flux:badge size="sm" color="red">{{ __('Failed') }}</flux:badge>
                    @elseif ($build->status === 'building')
                        <flux:badge size="sm" color="yellow">{{ __('Building') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ __('Queued') }}</flux:badge>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div wire:poll.5s="$refresh" class="hidden"></div>
</div>
