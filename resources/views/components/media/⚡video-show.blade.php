<?php

use App\Models\VideoRecording;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Video Detail'])]
class extends Component
{
    public VideoRecording $recording;

    public string $activeSection = 'edit';

    public function setSection(string $section): void
    {
        $this->activeSection = $section;
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    {{-- Back Link --}}
    <div class="mb-4">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('media.index')" wire:navigate>
            {{ __('Back to Media Hub') }}
        </flux:button>
    </div>

    {{-- Video Player --}}
    @if($recording->getVideoUrl() || $recording->getStreamUrl())
        <div class="aspect-video bg-black rounded-lg overflow-hidden mb-6">
            <video
                controls
                preload="metadata"
                class="w-full h-full"
                @if($recording->getThumbnailUrl()) poster="{{ $recording->getThumbnailUrl() }}" @endif
            >
                @if($recording->getVideoUrl())
                    <source src="{{ $recording->getVideoUrl() }}" type="{{ $recording->mime_type ?? 'video/mp4' }}">
                @endif
                @if($recording->getStreamUrl())
                    <source src="{{ $recording->getStreamUrl() }}" type="{{ $recording->mime_type ?? 'video/mp4' }}">
                @endif
                {{ __('Your browser does not support the video tag.') }}
            </video>
        </div>
    @endif

    {{-- Title & Meta --}}
    <div class="mb-6">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="xl">{{ $recording->title ?? __('Untitled Video') }}</flux:heading>
            @if($recording->getPlayablePath())
                <flux:button variant="ghost" size="sm" icon="arrow-down-tray" :href="route('video-recordings.download', $recording)">
                    {{ __('Download') }}
                </flux:button>
            @endif
        </div>
        @if($recording->description)
            <flux:text class="mt-2 text-zinc-500">{{ $recording->description }}</flux:text>
        @endif
    </div>

    {{-- Section Tabs --}}
    <div class="flex gap-2 mb-6 border-b border-zinc-200 dark:border-zinc-700 pb-3">
        <flux:button size="sm" :variant="$activeSection === 'edit' ? 'filled' : 'ghost'" icon="pencil" wire:click="setSection('edit')">
            {{ __('Edit') }}
        </flux:button>
        <flux:button size="sm" :variant="$activeSection === 'analytics' ? 'filled' : 'ghost'" icon="chart-bar" wire:click="setSection('analytics')">
            {{ __('Analytics') }}
        </flux:button>
        @can('media.send_emails')
            <flux:button size="sm" :variant="$activeSection === 'email' ? 'filled' : 'ghost'" icon="envelope" wire:click="setSection('email')">
                {{ __('Send Email') }}
            </flux:button>
        @endcan
    </div>

    {{-- Section Content --}}
    @if($activeSection === 'edit')
        @livewire('media.video-edit-form', ['recordingId' => $recording->id], key('edit-' . $recording->id))
    @elseif($activeSection === 'analytics')
        @livewire('media.video-analytics', ['recordingId' => $recording->id], key('analytics-' . $recording->id))
    @elseif($activeSection === 'email')
        @livewire('media.video-send-email', ['recordingId' => $recording->id], key('email-' . $recording->id))
    @endif
</div>
