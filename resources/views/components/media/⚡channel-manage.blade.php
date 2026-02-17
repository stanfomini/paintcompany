<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Manage Channel'])]
class extends Component
{
    public VideoChannel $channel;

    public bool $showVideoPickerModal = false;

    #[Computed]
    public function channelVideos()
    {
        return $this->channel->items()
            ->with('video')
            ->orderBy('sort_order')
            ->get();
    }

    public function removeVideo(int $itemId): void
    {
        VideoChannelItem::where('video_channel_id', $this->channel->id)
            ->where('id', $itemId)
            ->delete();

        unset($this->channelVideos);
    }

    public function togglePublished(int $itemId): void
    {
        $item = VideoChannelItem::where('video_channel_id', $this->channel->id)
            ->findOrFail($itemId);

        $item->update([
            'published_at' => $item->published_at ? null : now(),
        ]);

        unset($this->channelVideos);
    }

    public function reorderVideos(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $itemId) {
            VideoChannelItem::where('id', $itemId)
                ->where('video_channel_id', $this->channel->id)
                ->update(['sort_order' => $index]);
        }

        unset($this->channelVideos);
    }

    #[On('video-added')]
    public function onVideoAdded(): void
    {
        $this->showVideoPickerModal = false;
        unset($this->channelVideos);
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    {{-- Channel Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl">{{ $channel->name }}</flux:heading>
                <flux:badge :variant="$channel->visibility === 'public' ? 'primary' : 'warning'">
                    {{ ucfirst($channel->visibility) }}
                </flux:badge>
            </div>
            @if($channel->description)
                <flux:text class="mt-1 text-zinc-500">{{ $channel->description }}</flux:text>
            @endif
            <flux:text class="mt-1 text-sm text-zinc-400">
                {{ $this->channelVideos->count() }} {{ Str::plural('video', $this->channelVideos->count()) }}
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="primary" size="sm" icon="plus" wire:click="$set('showVideoPickerModal', true)">
                {{ __('Add Video') }}
            </flux:button>
            <flux:button variant="ghost" size="sm" :href="route('media.index', ['tab' => 'channels'])" wire:navigate>
                {{ __('Back') }}
            </flux:button>
        </div>
    </div>

    {{-- Videos List --}}
    @if($this->channelVideos->isEmpty())
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="video-camera" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No videos in this channel') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Add videos to start building your channel.') }}</flux:text>
        </div>
    @else
        <div class="space-y-2" wire:sortable="reorderVideos">
            @foreach($this->channelVideos as $item)
                <div wire:key="item-{{ $item->id }}" wire:sortable.item="{{ $item->id }}" class="flex items-center gap-4 p-4 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div wire:sortable.handle class="cursor-grab text-zinc-400 hover:text-zinc-600">
                        <flux:icon name="bars-3" class="h-5 w-5" />
                    </div>

                    {{-- Thumbnail --}}
                    <div class="w-24 h-14 bg-zinc-100 dark:bg-zinc-700 rounded overflow-hidden shrink-0">
                        @if($item->video?->getThumbnailUrl())
                            <img src="{{ $item->video->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover" />
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <flux:icon name="video-camera" class="h-5 w-5 text-zinc-400" />
                            </div>
                        @endif
                    </div>

                    {{-- Video Info --}}
                    <div class="min-w-0 flex-1">
                        <flux:text class="font-medium truncate">{{ $item->video?->title ?? __('Untitled') }}</flux:text>
                        <div class="flex items-center gap-2 mt-1">
                            @if($item->video?->formatted_duration)
                                <flux:text class="text-xs text-zinc-500">{{ $item->video->formatted_duration }}</flux:text>
                            @endif
                            @if($item->published_at)
                                <flux:badge size="sm" color="green">{{ __('Published') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                            @endif
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-2 shrink-0">
                        <flux:button size="sm" variant="ghost" wire:click="togglePublished({{ $item->id }})">
                            {{ $item->published_at ? __('Unpublish') : __('Publish') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeVideo({{ $item->id }})" wire:confirm="{{ __('Remove this video from the channel?') }}" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Video Picker Modal --}}
    <flux:modal wire:model="showVideoPickerModal" class="max-w-lg">
        @if($showVideoPickerModal)
            @livewire('media.channel-video-picker', ['channelId' => $channel->id], key('picker-' . $channel->id))
        @endif
    </flux:modal>
</div>
