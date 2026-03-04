<?php

use App\Models\PropertyPhoto;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Quick Creator'])]
class extends Component {
    public VideoProject $videoProject;

    public string $title = '';

    public ?int $pipBackgroundIndex = null;

    public bool $showTextEditor = false;

    public string $overlayText = '';

    public string $overlayPosition = 'bottom-center';

    public string $overlayColor = '#ffffff';

    public string $overlaySize = 'md';

    public bool $showExportModal = false;

    public bool $isRendering = false;

    public bool $publishToHub = false;

    public function mount(VideoProject $videoProject): void
    {
        abort_unless($videoProject->user_id === auth()->id(), 403);

        $this->videoProject = $videoProject;
        $this->title = $videoProject->title;

        if ($videoProject->scenes()->count() === 0) {
            $videoProject->scenes()->create([
                'sort_order' => 0,
                'name' => 'Main',
                'layout' => 'solo',
            ]);
        }
    }

    #[Computed]
    public function clips()
    {
        return $this->videoProject->scenes()
            ->with('clips.source')
            ->orderBy('sort_order')
            ->get()
            ->flatMap(fn ($scene) => $scene->clips->sortBy('sort_order'))
            ->values();
    }

    #[Computed]
    public function overlays()
    {
        return $this->videoProject->scenes()
            ->with('overlays')
            ->orderBy('sort_order')
            ->get()
            ->flatMap(fn ($scene) => $scene->overlays)
            ->values();
    }

    public function addClip(string $type, int $id): void
    {
        $typeMap = [
            'property-photo' => PropertyPhoto::class,
            'video-recording' => VideoRecording::class,
            PropertyPhoto::class => PropertyPhoto::class,
            VideoRecording::class => VideoRecording::class,
        ];

        $modelClass = $typeMap[$type] ?? null;
        if (! $modelClass) {
            return;
        }

        $scene = $this->videoProject->scenes()->firstOrCreate(
            [],
            ['sort_order' => 0, 'name' => 'Main', 'layout' => 'solo']
        );

        $nextOrder = ($scene->clips()->max('sort_order') ?? -1) + 1;

        $scene->clips()->create([
            'slot' => 'main',
            'source_type' => $modelClass,
            'source_id' => $id,
            'sort_order' => $nextOrder,
            'volume' => 100,
            'fit_mode' => 'cover',
        ]);

        unset($this->clips);
    }

    public function addRecordedClip(int $recordingId): void
    {
        $this->addClip('video-recording', $recordingId);
    }

    public function removeClip(int $index): void
    {
        $clips = $this->clips;

        if (isset($clips[$index])) {
            $clips[$index]->delete();
            unset($this->clips);
        }
    }

    public function reorderClips(array $orderedIds): void
    {
        foreach ($orderedIds as $sortOrder => $clipId) {
            $this->videoProject->scenes()
                ->first()
                ?->clips()
                ->where('id', $clipId)
                ->update(['sort_order' => $sortOrder]);
        }

        unset($this->clips);
    }

    public function saveTitle(): void
    {
        $this->validate(['title' => 'required|string|min:1']);

        $this->videoProject->title = $this->title;
        $this->videoProject->save();
    }

    public function setAspectRatio(string $ratio): void
    {
        if (! in_array($ratio, ['9:16', '16:9', '1:1'])) {
            return;
        }

        $this->videoProject->update(['aspect_ratio' => $ratio]);
    }

    public function setTransition(int $clipIndex, string $type, int $durationMs = 500): void
    {
        $validTypes = ['cut', 'fade', 'slide-left', 'slide-right', 'zoom'];
        if (! in_array($type, $validTypes)) {
            return;
        }

        $clips = $this->clips;
        if (! isset($clips[$clipIndex])) {
            return;
        }

        $clips[$clipIndex]->update([
            'transition_type' => $type,
            'transition_duration_ms' => $durationMs,
        ]);

        unset($this->clips);
    }

    public function setClipSpeed(int $index, float $speed): void
    {
        $clips = $this->clips;
        if (! isset($clips[$index])) {
            return;
        }

        if (! in_array($speed, [0.5, 1.0, 1.5, 2.0])) {
            return;
        }

        $clip = $clips[$index];
        $metadata = $clip->metadata ?? [];
        $metadata['speed'] = $speed;
        $clip->update(['metadata' => $metadata]);

        unset($this->clips);
    }

    public function addTextOverlay(): void
    {
        $this->validate([
            'overlayText' => 'required|min:1',
            'overlayPosition' => 'required',
        ]);

        $scene = $this->videoProject->scenes()->orderBy('sort_order')->first();
        if (! $scene) {
            return;
        }

        $scene->overlays()->create([
            'type' => 'text',
            'content' => $this->overlayText,
            'sort_order' => $scene->overlays()->count(),
            'style' => [
                'position' => $this->overlayPosition,
                'color' => $this->overlayColor,
                'size' => $this->overlaySize,
            ],
        ]);

        $this->overlayText = '';
        $this->showTextEditor = false;
        unset($this->overlays);
    }

    public function removeOverlay(int $index): void
    {
        $overlays = $this->overlays;
        if (isset($overlays[$index])) {
            $overlays[$index]->delete();
            unset($this->overlays);
        }
    }

    public function setClipAsPipBackground(?int $index): void
    {
        if ($index !== null) {
            $clips = $this->clips;
            if (! isset($clips[$index])) {
                return;
            }
        }
        $this->pipBackgroundIndex = $index;
    }

    public function clearPipBackground(): void
    {
        $this->pipBackgroundIndex = null;
    }

    public function openExportModal(): void
    {
        $this->showExportModal = true;
    }

    public function startRender(): void
    {
        $this->isRendering = true;
        $this->videoProject->update(['status' => 'rendering']);
        $this->showExportModal = false;
    }

    public function publishToHub(): void
    {
        $recording = $this->videoProject->renderedVideo;
        if (! $recording) {
            return;
        }

        $recording->update(['available_in_hub' => true]);
        $this->publishToHub = true;
    }

    public function unpublishFromHub(): void
    {
        $recording = $this->videoProject->renderedVideo;
        if (! $recording) {
            return;
        }

        $recording->update(['available_in_hub' => false]);
        $this->publishToHub = false;
    }

    #[Computed]
    public function shareUrl(): ?string
    {
        return $this->videoProject->share_token
            ? url('/studio/share/'.$this->videoProject->share_token)
            : null;
    }

    #[Computed]
    public function downloadUrl(): ?string
    {
        $recording = $this->videoProject->renderedVideo;
        if (! $recording || ! $recording->isReady()) {
            return null;
        }

        return $recording->getVideoUrl();
    }

    #[Computed]
    public function isProjectRendered(): bool
    {
        return $this->videoProject->status === 'rendered'
            && $this->videoProject->renderedVideo?->isReady();
    }

    #[On('media-selected')]
    public function onMediaSelected(string $type, int $id): void
    {
        $this->addClip($type, $id);
    }
};
?>

<div
    x-data="quickCreator()"
    class="flex flex-col h-[calc(100vh-4rem)] bg-zinc-950"
>
    {{-- Top bar --}}
    <div class="flex items-center justify-between px-4 py-3 bg-zinc-900 border-b border-zinc-800">
        <a href="{{ route('studio') }}" wire:navigate class="text-zinc-400 hover:text-white transition-colors">
            <flux:icon name="arrow-left" class="size-5" />
        </a>
        <div class="flex-1 mx-4">
            <input
                type="text"
                wire:model.blur="title"
                wire:change="saveTitle"
                class="w-full bg-transparent text-white text-center text-sm font-medium border-0 focus:ring-0 focus:outline-none placeholder-zinc-500"
                placeholder="{{ __('Untitled Project') }}"
            />
        </div>
        <flux:button wire:click="openExportModal" variant="primary" size="sm">
            {{ __('Done') }}
        </flux:button>
    </div>

    {{-- Aspect ratio pills --}}
    <div class="flex items-center justify-center gap-2 px-4 py-2 bg-zinc-900/50">
        @foreach(['9:16' => '9:16', '16:9' => '16:9', '1:1' => '1:1'] as $ratio => $label)
            <button
                wire:click="setAspectRatio('{{ $ratio }}')"
                class="px-3 py-1 text-xs font-medium rounded-full transition-colors {{ $videoProject->aspect_ratio === $ratio ? 'bg-white text-zinc-900' : 'text-zinc-400 hover:text-white hover:bg-zinc-800' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- PIP indicator --}}
    <div x-show="pipMode" x-cloak class="flex items-center justify-center gap-2 text-blue-400 text-sm px-3 py-1 bg-zinc-900/50">
        <flux:icon name="squares-2x2" class="size-4" />
        <span>{{ __('PIP Mode') }}</span>
        <button @click="exitPipMode(); $wire.clearPipBackground()" class="text-zinc-400 hover:text-white transition-colors">
            <flux:icon name="x-mark" class="size-4" />
        </button>
    </div>

    {{-- Preview area --}}
    <div class="flex-1 flex items-center justify-center p-4 overflow-hidden">
        <div
            class="relative bg-zinc-800 rounded-lg overflow-hidden flex items-center justify-center
                {{ $videoProject->aspect_ratio === '9:16' ? 'h-full max-h-full aspect-[9/16]' : '' }}
                {{ $videoProject->aspect_ratio === '16:9' ? 'w-full max-w-full aspect-video' : '' }}
                {{ $videoProject->aspect_ratio === '1:1' ? 'h-full max-h-full aspect-square' : '' }}"
        >
            {{-- PIP preview layout (background + camera corner) --}}
            <template x-if="pipMode && (recordingState === 'previewing' || recordingState === 'recording' || recordingState === 'uploading')">
                <div class="relative w-full h-full">
                    <template x-if="pipBackgroundType === 'image'">
                        <img :src="pipBackgroundUrl" class="w-full h-full object-cover" />
                    </template>
                    <template x-if="pipBackgroundType === 'video'">
                        <video :src="pipBackgroundUrl" class="w-full h-full object-cover" autoplay loop muted></video>
                    </template>
                    <video x-ref="pipCameraPreview" autoplay muted playsinline
                        class="absolute bottom-3 right-3 w-1/4 h-1/4 object-cover rounded-lg border-2 border-white shadow-lg">
                    </video>
                </div>
            </template>

            {{-- Camera preview (when recording, non-PIP) --}}
            <video
                x-ref="cameraPreview"
                autoplay
                muted
                playsinline
                x-show="!pipMode && (recordingState === 'previewing' || recordingState === 'recording' || recordingState === 'uploading')"
                class="w-full h-full object-cover"
                x-cloak
            ></video>

            {{-- Recording indicator overlay --}}
            <div
                x-show="recordingState === 'recording'"
                x-cloak
                class="absolute top-3 left-3 flex items-center gap-2"
            >
                <span class="flex items-center gap-1.5 bg-red-600 text-white text-xs font-bold px-2 py-1 rounded-full">
                    <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
                    REC
                </span>
                <span class="text-white text-sm font-mono bg-black/50 px-2 py-0.5 rounded" x-text="formatTime(recordingSeconds)"></span>
            </div>

            {{-- Upload progress overlay --}}
            <div
                x-show="recordingState === 'uploading'"
                x-cloak
                class="absolute inset-0 bg-black/50 flex items-center justify-center"
            >
                <div class="text-center">
                    <span class="text-white text-sm font-medium">{{ __('Saving...') }}</span>
                    <div class="w-48 h-2 bg-zinc-700 rounded-full overflow-hidden mt-2">
                        <div class="h-full bg-blue-500 transition-all rounded-full" :style="`width: ${uploadProgress}%`"></div>
                    </div>
                </div>
            </div>

            {{-- Clip preview content (when not recording) --}}
            <template x-if="recordingState === 'idle' && previewUrl && previewType === 'image'">
                <img :src="previewUrl" alt="" class="w-full h-full object-cover" />
            </template>
            <template x-if="recordingState === 'idle' && previewUrl && previewType === 'video'">
                <video :src="previewUrl" controls class="w-full h-full object-cover"></video>
            </template>
            <template x-if="recordingState === 'idle' && !previewUrl">
                <div class="text-center p-8">
                    <flux:icon name="photo" class="mx-auto size-12 text-zinc-600" />
                    <p class="mt-3 text-sm text-zinc-500">{{ __('Add media to get started') }}</p>
                    <button
                        @click="$dispatch('open-media-browser')"
                        class="mt-3 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-500 rounded-lg transition-colors"
                    >
                        {{ __('Browse Media') }}
                    </button>
                </div>
            </template>

            {{-- Overlay preview rendering --}}
            @foreach($this->overlays as $overlay)
                @php
                    $pos = $overlay->style['position'] ?? 'bottom-center';
                    $posClasses = match($pos) {
                        'top-left' => 'top-4 left-4',
                        'top-center' => 'top-4 left-1/2 -translate-x-1/2',
                        'top-right' => 'top-4 right-4',
                        'center' => 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2',
                        'bottom-left' => 'bottom-4 left-4',
                        'bottom-center' => 'bottom-4 left-1/2 -translate-x-1/2',
                        'bottom-right' => 'bottom-4 right-4',
                        default => 'bottom-4 left-1/2 -translate-x-1/2',
                    };
                    $sizeClasses = match($overlay->style['size'] ?? 'md') {
                        'sm' => 'text-sm',
                        'md' => 'text-lg',
                        'lg' => 'text-2xl',
                        'xl' => 'text-4xl',
                        default => 'text-lg',
                    };
                @endphp
                <div
                    wire:key="overlay-preview-{{ $overlay->id }}"
                    class="absolute font-bold pointer-events-none {{ $posClasses }} {{ $sizeClasses }}"
                    style="color: {{ $overlay->style['color'] ?? '#ffffff' }}; text-shadow: 0 2px 4px rgba(0,0,0,0.8);"
                >
                    {{ $overlay->content }}
                </div>
            @endforeach
        </div>
    </div>

    {{-- Recording controls --}}
    <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-3">
        <div class="flex items-center justify-center gap-4">
            {{-- Start Camera (when idle) --}}
            <button
                x-show="recordingState === 'idle'"
                @click="startCamera()"
                class="flex items-center gap-2 px-4 py-2 bg-red-500 hover:bg-red-400 text-white text-sm font-medium rounded-full transition-colors"
            >
                <flux:icon name="video-camera" class="size-4" />
                {{ __('Record') }}
            </button>

            {{-- Camera controls (when previewing) --}}
            <div x-show="recordingState === 'previewing'" x-cloak class="flex items-center gap-4">
                <button @click="flipCamera()" class="p-2 text-zinc-400 hover:text-white transition-colors rounded-full hover:bg-zinc-800">
                    <flux:icon name="arrow-path" class="size-5" />
                </button>
                <button
                    @click="startRecording()"
                    class="w-16 h-16 rounded-full bg-red-500 hover:bg-red-400 border-4 border-white transition-colors"
                ></button>
                <button @click="stopCamera()" class="p-2 text-zinc-400 hover:text-white transition-colors text-sm">
                    {{ __('Done') }}
                </button>
            </div>

            {{-- Stop recording (when recording) --}}
            <div x-show="recordingState === 'recording'" x-cloak class="flex items-center gap-4">
                <button
                    @click="stopRecording()"
                    class="w-16 h-16 rounded-full bg-red-500 border-4 border-white flex items-center justify-center transition-colors hover:bg-red-400"
                >
                    <div class="w-6 h-6 bg-white rounded-sm"></div>
                </button>
            </div>

            {{-- Upload indicator (when uploading) --}}
            <div x-show="recordingState === 'uploading'" x-cloak class="flex items-center gap-3">
                <span class="text-white text-sm">{{ __('Saving...') }}</span>
                <div class="w-32 h-2 bg-zinc-700 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-500 transition-all rounded-full" :style="`width: ${uploadProgress}%`"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Clip strip --}}
    <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-3">
        <div class="flex items-center gap-0 overflow-x-auto pb-1">
            @forelse($this->clips as $index => $clip)
                <div
                    wire:key="clip-{{ $clip->id }}"
                    class="shrink-0 relative group cursor-pointer rounded-lg overflow-hidden border-2 transition-colors
                        {{ $index === 0 ? 'border-blue-500' : 'border-zinc-700 hover:border-zinc-500' }}"
                    :class="{ 'border-blue-500': activeClipIndex === {{ $index }}, 'border-zinc-700 hover:border-zinc-500': activeClipIndex !== {{ $index }} }"
                    @click="setActiveClip(
                        {{ $index }},
                        '{{ $clip->source instanceof \App\Models\PropertyPhoto ? $clip->source->url : ($clip->source instanceof \App\Models\VideoRecording ? ($clip->source->getThumbnailUrl() ?? '') : '') }}',
                        '{{ $clip->source instanceof \App\Models\VideoRecording ? 'video' : 'image' }}'
                    )"
                >
                    <div class="w-16 h-16 bg-zinc-800">
                        @if($clip->source instanceof \App\Models\PropertyPhoto)
                            <img src="{{ $clip->source->url }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                        @elseif($clip->source instanceof \App\Models\VideoRecording && $clip->source->getThumbnailUrl())
                            <img src="{{ $clip->source->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <flux:icon name="film" class="size-6 text-zinc-600" />
                            </div>
                        @endif
                    </div>

                    {{-- Duration badge for video --}}
                    @if($clip->source instanceof \App\Models\VideoRecording && $clip->source->formatted_duration)
                        <span class="absolute bottom-0.5 right-0.5 bg-black/75 text-white text-[10px] px-1 rounded">
                            {{ $clip->source->formatted_duration }}
                        </span>
                    @endif

                    {{-- Speed badge --}}
                    @if(($clip->metadata['speed'] ?? 1.0) != 1.0)
                        <span class="absolute top-0.5 left-0.5 bg-yellow-500 text-black text-[10px] font-bold px-1 rounded">
                            {{ $clip->metadata['speed'] }}x
                        </span>
                    @endif

                    {{-- PIP background button --}}
                    @php
                        $clipUrl = $clip->source instanceof \App\Models\PropertyPhoto
                            ? $clip->source->url
                            : ($clip->source instanceof \App\Models\VideoRecording ? ($clip->source->getThumbnailUrl() ?? '') : '');
                        $clipType = $clip->source instanceof \App\Models\VideoRecording ? 'video' : 'image';
                    @endphp
                    <button
                        wire:click.stop="setClipAsPipBackground({{ $index }})"
                        @click.stop="enterPipMode('{{ $clipUrl }}', '{{ $clipType }}')"
                        class="absolute bottom-0.5 left-0.5 p-0.5 bg-black/60 rounded text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-blue-500/80"
                        title="{{ __('Use as PIP background') }}"
                    >
                        <flux:icon name="squares-2x2" class="size-3" />
                    </button>

                    {{-- Delete button --}}
                    <button
                        wire:click.stop="removeClip({{ $index }})"
                        class="absolute top-0.5 right-0.5 bg-black/75 text-white rounded-full p-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <flux:icon name="x-mark" class="size-3" />
                    </button>
                </div>

                {{-- Transition button between clips --}}
                @if(! $loop->last)
                    @php
                        $nextClip = $this->clips[$index + 1] ?? null;
                        $currentTransition = $nextClip?->transition_type;
                        $transitionLabel = match($currentTransition) {
                            'fade' => 'F',
                            'slide-left' => 'SL',
                            'slide-right' => 'SR',
                            'zoom' => 'Z',
                            'cut' => 'C',
                            default => null,
                        };
                    @endphp
                    <div x-data="{ showTransitions: false }" class="relative shrink-0" wire:key="transition-{{ $clip->id }}">
                        <button
                            @click="showTransitions = !showTransitions"
                            class="w-6 h-16 flex items-center justify-center text-zinc-500 hover:text-white transition-colors"
                        >
                            @if($transitionLabel)
                                <span class="text-[10px] font-bold text-blue-400">{{ $transitionLabel }}</span>
                            @else
                                <flux:icon name="chevron-right" class="size-3" />
                            @endif
                        </button>

                        {{-- Transition picker dropdown --}}
                        <div
                            x-show="showTransitions"
                            @click.outside="showTransitions = false"
                            x-cloak
                            class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 bg-zinc-800 border border-zinc-700 rounded-lg p-1.5 z-10 min-w-[120px]"
                        >
                            @foreach(['cut' => 'Cut', 'fade' => 'Fade', 'slide-left' => 'Slide L', 'slide-right' => 'Slide R', 'zoom' => 'Zoom'] as $type => $label)
                                <button
                                    wire:click="setTransition({{ $index + 1 }}, '{{ $type }}')"
                                    @click="showTransitions = false"
                                    class="block w-full text-left px-3 py-1.5 text-sm rounded transition-colors {{ $currentTransition === $type ? 'bg-blue-500/20 text-blue-400' : 'text-zinc-300 hover:bg-zinc-700' }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @empty
            @endforelse

            {{-- Add clip button --}}
            <button
                @click="$dispatch('open-media-browser')"
                class="shrink-0 w-16 h-16 rounded-lg border-2 border-dashed border-zinc-700 hover:border-zinc-500 flex items-center justify-center text-zinc-500 hover:text-zinc-400 transition-colors ml-2"
            >
                <flux:icon name="plus" class="size-6" />
            </button>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-2">
        <div class="flex items-center justify-center gap-6">
            <button wire:click="$set('showTextEditor', true)" class="flex flex-col items-center gap-1 text-zinc-400 hover:text-white transition-colors">
                <flux:icon name="bars-3-bottom-left" class="size-5" />
                <span class="text-xs">{{ __('Text') }}</span>
            </button>
            <div x-data="{ showSpeed: false }" class="relative">
                <button @click="showSpeed = !showSpeed" class="flex flex-col items-center gap-1 text-zinc-400 hover:text-white transition-colors">
                    <flux:icon name="forward" class="size-5" />
                    <span class="text-xs">{{ __('Speed') }}</span>
                </button>
                <div
                    x-show="showSpeed"
                    @click.outside="showSpeed = false"
                    x-cloak
                    class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 bg-zinc-800 border border-zinc-700 rounded-lg p-1.5 z-10"
                >
                    @foreach([0.5, 1.0, 1.5, 2.0] as $speed)
                        <button
                            wire:click="setClipSpeed(activeClipIndex, {{ $speed }})"
                            @click="showSpeed = false"
                            class="block w-full text-left px-3 py-1.5 text-sm text-zinc-300 hover:bg-zinc-700 rounded whitespace-nowrap"
                        >
                            {{ $speed }}x
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Text editor panel --}}
    @if($showTextEditor)
        <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-3">
            <div class="bg-zinc-800 p-4 rounded-xl border border-zinc-700">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-white font-medium text-sm">{{ __('Add Text') }}</span>
                    <button wire:click="$set('showTextEditor', false)" class="text-zinc-400 hover:text-white">
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>
                <flux:input wire:model="overlayText" placeholder="{{ __('Enter text...') }}" class="mb-3" />
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach(['top-left' => 'TL', 'top-center' => 'TC', 'top-right' => 'TR', 'center' => 'C', 'bottom-left' => 'BL', 'bottom-center' => 'BC', 'bottom-right' => 'BR'] as $pos => $label)
                        <button
                            wire:click="$set('overlayPosition', '{{ $pos }}')"
                            class="px-2 py-1 rounded text-xs transition-colors {{ $overlayPosition === $pos ? 'bg-blue-500 text-white' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <flux:button wire:click="addTextOverlay" variant="primary" size="sm" class="w-full">
                    {{ __('Add Text') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Overlay list --}}
    @if($this->overlays->isNotEmpty())
        <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-2 space-y-1">
            @foreach($this->overlays as $index => $overlay)
                <div wire:key="overlay-list-{{ $overlay->id }}" class="flex items-center justify-between bg-zinc-800 px-3 py-2 rounded">
                    <span class="text-white text-sm truncate">{{ $overlay->content }}</span>
                    <button wire:click="removeOverlay({{ $index }})" class="text-zinc-400 hover:text-red-400 transition-colors shrink-0 ml-2">
                        <flux:icon name="trash" class="size-4" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Media browser --}}
    <livewire:studio.media-browser />

    {{-- Export modal --}}
    @if($showExportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" wire:click.self="$set('showExportModal', false)">
            <div class="bg-zinc-800 rounded-2xl border border-zinc-700 w-full max-w-md mx-4 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-white">{{ __('Export Video') }}</h3>
                    <button wire:click="$set('showExportModal', false)" class="text-zinc-400 hover:text-white">
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    {{-- Render button --}}
                    @if($videoProject->status === 'draft' || $videoProject->status === 'failed')
                        <flux:button wire:click="startRender" variant="primary" class="w-full" icon="play">
                            {{ __('Render Video') }}
                        </flux:button>
                        <p class="text-xs text-zinc-400">{{ __('Combines your clips into a final video.') }}</p>
                    @elseif($videoProject->status === 'rendering')
                        <div class="flex items-center gap-3 text-yellow-400">
                            <svg class="animate-spin size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span>{{ __('Rendering... This may take a few minutes.') }}</span>
                        </div>
                    @endif

                    {{-- Download (when rendered) --}}
                    @if($this->isProjectRendered)
                        <a href="{{ $this->downloadUrl }}" target="_blank"
                            class="flex items-center gap-3 w-full px-4 py-3 bg-zinc-700 rounded-lg text-white hover:bg-zinc-600 transition-colors">
                            <flux:icon name="arrow-down-tray" class="size-5" />
                            <span>{{ __('Download Video') }}</span>
                        </a>
                    @endif

                    {{-- Publish to Hub --}}
                    @if($this->isProjectRendered)
                        <div class="flex items-center justify-between px-4 py-3 bg-zinc-700 rounded-lg">
                            <div class="flex items-center gap-3 text-white">
                                <flux:icon name="globe-alt" class="size-5" />
                                <span>{{ __('Publish to Media Hub') }}</span>
                            </div>
                            <flux:switch
                                wire:click="{{ $publishToHub ? 'unpublishFromHub' : 'publishToHub' }}"
                                :checked="$publishToHub" />
                        </div>
                    @endif

                    {{-- Share Link --}}
                    @if($this->shareUrl)
                        <div class="px-4 py-3 bg-zinc-700 rounded-lg">
                            <div class="flex items-center gap-3 text-white mb-2">
                                <flux:icon name="link" class="size-5" />
                                <span>{{ __('Share Link') }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="text" value="{{ $this->shareUrl }}" readonly
                                    class="flex-1 bg-zinc-800 border border-zinc-600 rounded px-3 py-1.5 text-sm text-zinc-300" />
                                <button onclick="navigator.clipboard.writeText('{{ $this->shareUrl }}')"
                                    class="px-3 py-1.5 bg-zinc-600 text-white rounded text-sm hover:bg-zinc-500 transition-colors">
                                    {{ __('Copy') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Back to studio --}}
                    <a href="{{ route('studio') }}" wire:navigate
                        class="flex items-center justify-center gap-2 w-full px-4 py-3 bg-zinc-900 rounded-lg text-zinc-400 hover:text-white transition-colors text-sm">
                        <flux:icon name="arrow-left" class="size-4" />
                        {{ __('Back to Studio') }}
                    </a>
                </div>
            </div>
        </div>
    @endif
</div>
