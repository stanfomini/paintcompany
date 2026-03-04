<?php

use App\Models\VideoRecording;
use Livewire\Component;

new class extends Component {
    public VideoRecording $video;

    public bool $showCreator = false;

    public function copyLink(): void
    {
        $url = $this->video->getShareableUrl();
        $this->js("
            navigator.clipboard.writeText('{$url}')
                .then(() => { \$wire.dispatch('notify', { message: 'Link copied to clipboard!' }) })
                .catch(() => { window.prompt('Copy this link:', '{$url}') })
        ");
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->video);
        $this->video->delete();
        $this->dispatch('video-deleted');
    }
};
?>

<div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden group">
    {{-- Thumbnail (clickable) --}}
    <a href="{{ route('media.video.show', $video) }}" wire:navigate class="block aspect-video bg-zinc-100 dark:bg-zinc-800 relative cursor-pointer">
        @if($video->getThumbnailUrl())
            <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" loading="lazy" />
        @else
            <div class="w-full h-full flex items-center justify-center">
                <flux:icon name="video-camera" class="h-8 w-8 text-zinc-400" />
            </div>
        @endif

        {{-- Play icon overlay on hover --}}
        <div class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/30 transition-colors">
            <div class="opacity-0 group-hover:opacity-100 transition-opacity bg-black/60 rounded-full p-3">
                <flux:icon name="play" class="h-8 w-8 text-white" />
            </div>
        </div>

        @if($video->formatted_duration)
            <span class="absolute bottom-2 right-2 bg-black/75 text-white text-xs px-1.5 py-0.5 rounded">
                {{ $video->formatted_duration }}
            </span>
        @endif

        @if($video->isProcessing())
            <div class="absolute inset-0 bg-black/50 flex items-center justify-center">
                <flux:badge color="yellow">{{ __('Processing') }}</flux:badge>
            </div>
        @endif
    </a>

    {{-- Info --}}
    <div class="p-3">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                <a href="{{ route('media.video.show', $video) }}" wire:navigate class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                    <flux:text class="font-medium truncate">{{ $video->title ?? __('Untitled Video') }}</flux:text>
                </a>
                <div class="flex items-center gap-2 mt-1">
                    @php
                        $visibilityColors = [
                            'public' => 'green',
                            'unlisted' => 'zinc',
                            'internal' => 'blue',
                        ];
                    @endphp
                    <flux:badge size="sm" color="{{ $visibilityColors[$video->visibility] ?? 'zinc' }}">
                        {{ ucfirst($video->visibility) }}
                    </flux:badge>
                    <flux:text class="text-xs text-zinc-500">
                        {{ $video->views_count ?? 0 }} {{ __('views') }}
                    </flux:text>
                </div>
                @if($showCreator)
                    <flux:text class="text-xs text-zinc-500">
                        {{ $video->user?->name ?? __('Company') }}
                    </flux:text>
                @endif
            </div>

            <flux:dropdown position="bottom" align="end">
                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                <flux:menu>
                    <flux:menu.item icon="link" wire:click="copyLink">{{ __('Copy Link') }}</flux:menu.item>
                    @if($video->getPlayablePath())
                        <flux:menu.item icon="arrow-down-tray" :href="route('video-recordings.download', $video)">{{ __('Download') }}</flux:menu.item>
                    @endif
                    @can('update', $video)
                        <flux:menu.item icon="pencil" :href="route('media.video.show', $video)" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                    @endcan
                    @can('delete', $video)
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="{{ __('Are you sure you want to delete this video?') }}">{{ __('Delete') }}</flux:menu.item>
                    @endcan
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>
</div>
