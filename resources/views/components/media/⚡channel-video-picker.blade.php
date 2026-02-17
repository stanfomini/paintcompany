<?php

use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $channelId;

    public string $search = '';

    #[Computed]
    public function availableVideos()
    {
        $existingIds = VideoChannelItem::where('video_channel_id', $this->channelId)
            ->pluck('video_recording_id');

        return VideoRecording::query()
            ->where('status', 'ready')
            ->whereNotIn('id', $existingIds)
            ->when($this->search, function ($query) {
                $query->where('title', 'like', "%{$this->search}%");
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function addVideo(int $recordingId): void
    {
        $maxSort = VideoChannelItem::where('video_channel_id', $this->channelId)->max('sort_order') ?? -1;

        VideoChannelItem::create([
            'video_channel_id' => $this->channelId,
            'video_recording_id' => $recordingId,
            'sort_order' => $maxSort + 1,
        ]);

        unset($this->availableVideos);
        $this->dispatch('video-added');
    }
};
?>

<div>
    <flux:heading size="lg" class="mb-4">{{ __('Add Video to Channel') }}</flux:heading>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search videos...') }}" icon="magnifying-glass" class="mb-4" />

    @if($this->availableVideos->isEmpty())
        <flux:text class="text-zinc-500 text-center py-6">{{ __('No videos available to add.') }}</flux:text>
    @else
        <div class="space-y-2 max-h-96 overflow-y-auto">
            @foreach($this->availableVideos as $video)
                <div wire:key="pick-{{ $video->id }}" class="flex items-center gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <div class="w-16 h-10 bg-zinc-100 dark:bg-zinc-700 rounded overflow-hidden shrink-0">
                        @if($video->getThumbnailUrl())
                            <img src="{{ $video->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover" />
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <flux:icon name="video-camera" class="h-4 w-4 text-zinc-400" />
                            </div>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <flux:text class="font-medium truncate text-sm">{{ $video->title ?? __('Untitled') }}</flux:text>
                        @if($video->formatted_duration)
                            <flux:text class="text-xs text-zinc-500">{{ $video->formatted_duration }}</flux:text>
                        @endif
                    </div>
                    <flux:button size="sm" variant="primary" wire:click="addVideo({{ $video->id }})">
                        {{ __('Add') }}
                    </flux:button>
                </div>
            @endforeach
        </div>
    @endif
</div>
