<?php

use App\Models\VideoRecording;
use Livewire\Component;

new class extends Component {
    public VideoRecording $video;

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
        abort_unless($this->video->user_id === auth()->id(), 403);
        $this->video->delete();
        $this->dispatch('video-deleted');
    }
};
?>

<div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
    {{-- Thumbnail --}}
    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
        @if($video->getThumbnailUrl())
            <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" />
        @else
            <div class="w-full h-full flex items-center justify-center">
                <flux:icon name="video-camera" class="h-8 w-8 text-zinc-400" />
            </div>
        @endif

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
    </div>

    {{-- Info --}}
    <div class="p-3">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                <flux:text class="font-medium truncate">{{ $video->title ?? __('Untitled Video') }}</flux:text>
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
            </div>

            <flux:dropdown position="bottom" align="end">
                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                <flux:menu>
                    <flux:menu.item icon="link" wire:click="copyLink">{{ __('Copy Link') }}</flux:menu.item>
                    <flux:menu.item icon="pencil" :href="route('media.video.show', $video)" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="{{ __('Are you sure you want to delete this video?') }}">{{ __('Delete') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>
</div>
