<?php

use App\Jobs\GenerateSceneCaptionsJob;
use App\Models\VideoProject;
use App\Models\VideoProjectClip;
use App\Models\VideoProjectOverlay;
use App\Models\VideoProjectScene;
use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Editor'])]
class extends Component {
    public VideoProject $project;
    public ?int $activeSceneId = null;
    public bool $showMediaPicker = false;
    public ?string $pendingSlot = null;

    public function mount(VideoProject $videoProject): void
    {
        abort_unless($videoProject->user_id === auth()->id(), 403);
        $this->project = $videoProject;
        $this->activeSceneId = $videoProject->scenes()->first()?->id;
    }

    // --- Project ---

    public function updateTitle(string $title): void
    {
        $this->project->update(['title' => $title]);
    }

    // --- Scene CRUD ---

    public function addScene(string $layout = 'solo'): void
    {
        $maxOrder = $this->project->scenes()->max('sort_order') ?? -1;

        $scene = $this->project->scenes()->create([
            'layout' => $layout,
            'sort_order' => $maxOrder + 1,
            'name' => __('Scene :number', ['number' => $maxOrder + 2]),
        ]);

        $this->activeSceneId = $scene->id;
    }

    public function removeScene(int $sceneId): void
    {
        $scene = $this->project->scenes()->findOrFail($sceneId);
        $scene->delete();

        if ($this->activeSceneId === $sceneId) {
            $this->activeSceneId = $this->project->scenes()->first()?->id;
        }
    }

    public function reorderScenes(array $order): void
    {
        foreach ($order as $index => $sceneId) {
            $this->project->scenes()
                ->where('id', $sceneId)
                ->update(['sort_order' => $index]);
        }
    }

    public function updateScene(int $sceneId, string $field, mixed $value): void
    {
        $allowed = ['name', 'layout', 'duration_ms', 'trim_start_ms', 'trim_end_ms', 'transition_type', 'transition_duration_ms', 'background_color'];
        if (! in_array($field, $allowed)) {
            return;
        }

        $this->project->scenes()
            ->where('id', $sceneId)
            ->update([$field => $value]);
    }

    public function selectScene(int $sceneId): void
    {
        $this->activeSceneId = $sceneId;
    }

    // --- Clip Assignment ---

    public function openMediaPicker(string $slot = 'main'): void
    {
        $this->pendingSlot = $slot;
        $this->showMediaPicker = true;
    }

    #[On('media-selected')]
    public function onMediaSelected(string $type, int $id): void
    {
        if (! $this->activeSceneId || ! $this->pendingSlot) {
            return;
        }

        $scene = $this->project->scenes()->findOrFail($this->activeSceneId);

        $scene->clips()->updateOrCreate(
            ['slot' => $this->pendingSlot],
            [
                'source_type' => $type,
                'source_id' => $id,
                'sort_order' => $scene->clips()->max('sort_order') + 1,
            ]
        );

        $this->showMediaPicker = false;
        $this->pendingSlot = null;
    }

    public function removeClip(int $clipId): void
    {
        VideoProjectClip::where('id', $clipId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->delete();
    }

    public function updateClip(int $clipId, string $field, mixed $value): void
    {
        $allowed = ['volume', 'in_point_ms', 'out_point_ms', 'fit_mode'];
        if (! in_array($field, $allowed)) {
            return;
        }

        VideoProjectClip::where('id', $clipId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->update([$field => $value]);
    }

    // --- Overlay CRUD ---

    public function addOverlay(string $type = 'text', string $content = ''): void
    {
        if (! $this->activeSceneId) {
            return;
        }

        $scene = $this->project->scenes()->findOrFail($this->activeSceneId);

        $scene->overlays()->create([
            'type' => $type,
            'content' => $content ?: __('New text'),
            'position_x' => 0.5,
            'position_y' => 0.5,
            'sort_order' => $scene->overlays()->max('sort_order') + 1,
            'style' => ['font_size' => 32, 'color' => '#FFFFFF', 'font_weight' => 'bold'],
        ]);
    }

    public function updateOverlay(int $overlayId, array $data): void
    {
        $allowed = ['content', 'style', 'position_x', 'position_y', 'width', 'height', 'start_ms', 'end_ms'];

        VideoProjectOverlay::where('id', $overlayId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->update(collect($data)->only($allowed)->toArray());
    }

    public function removeOverlay(int $overlayId): void
    {
        VideoProjectOverlay::where('id', $overlayId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->delete();
    }

    // --- Captions ---

    public function generateCaptions(int $sceneId): void
    {
        $scene = VideoProjectScene::where('id', $sceneId)
            ->whereHas('project', fn ($q) => $q->where('user_id', auth()->id()))
            ->first();

        if (! $scene) {
            return;
        }

        GenerateSceneCaptionsJob::dispatch($scene);

        $this->dispatch('captions-generating');
    }

    public function clearCaptions(int $sceneId): void
    {
        $scene = VideoProjectScene::where('id', $sceneId)
            ->whereHas('project', fn ($q) => $q->where('user_id', auth()->id()))
            ->first();

        if (! $scene) {
            return;
        }

        $scene->overlays()->where('type', 'caption')->delete();
    }

    // --- Voiceover CRUD ---

    public function removeVoiceover(int $voiceoverId): void
    {
        VideoProjectVoiceover::where('id', $voiceoverId)
            ->whereHas('project', fn ($q) => $q->where('user_id', auth()->id()))
            ->delete();
    }

    public function updateVoiceover(int $voiceoverId, string $field, mixed $value): void
    {
        $allowed = ['volume', 'start_ms', 'sort_order'];
        if (! in_array($field, $allowed)) {
            return;
        }

        VideoProjectVoiceover::where('id', $voiceoverId)
            ->whereHas('project', fn ($q) => $q->where('user_id', auth()->id()))
            ->update([$field => $value]);
    }

    // --- Render ---

    public function linkRenderedVideo(int $videoRecordingId): void
    {
        $recording = VideoRecording::findOrFail($videoRecordingId);

        $this->project->update([
            'status' => 'rendered',
            'video_recording_id' => $recording->id,
            'duration_seconds' => $recording->duration_seconds,
        ]);
    }

    // --- Distribution ---

    public function updateVisibility(string $visibility): void
    {
        if (! in_array($visibility, ['public', 'internal', 'unlisted'])) {
            return;
        }

        $this->project->update(['visibility' => $visibility]);
    }

    public function getShareUrl(): string
    {
        return url("/studio/share/{$this->project->share_token}");
    }

    // --- Computed ---

    #[Computed]
    public function scenes(): Collection
    {
        return $this->project->scenes()
            ->with(['clips.source', 'overlays'])
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function voiceovers(): Collection
    {
        return $this->project->voiceovers()
            ->with('recording')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function activeScene(): ?VideoProjectScene
    {
        if (! $this->activeSceneId) {
            return null;
        }

        return $this->scenes->firstWhere('id', $this->activeSceneId);
    }

    #[Computed]
    public function projectData(): array
    {
        return [
            'id' => $this->project->id,
            'resolution_width' => $this->project->resolution_width,
            'resolution_height' => $this->project->resolution_height,
            'fps' => $this->project->fps,
            'scenes' => $this->scenes->map(fn (VideoProjectScene $scene) => [
                'id' => $scene->id,
                'name' => $scene->name,
                'layout' => $scene->layout,
                'duration_ms' => $scene->getEffectiveDurationMs(),
                'trim_start_ms' => $scene->trim_start_ms,
                'trim_end_ms' => $scene->trim_end_ms,
                'transition_type' => $scene->transition_type,
                'transition_duration_ms' => $scene->transition_duration_ms,
                'background_color' => $scene->background_color,
                'clips' => $scene->clips->map(fn (VideoProjectClip $clip) => [
                    'id' => $clip->id,
                    'slot' => $clip->slot,
                    'source_type' => $clip->source_type,
                    'source_id' => $clip->source_id,
                    'url' => $clip->source instanceof VideoRecording ? $clip->source->getVideoUrl() : ($clip->source?->url ?? null),
                    'thumbnail' => $clip->source instanceof VideoRecording ? $clip->source->getThumbnailUrl() : ($clip->source?->thumbnail_url ?? null),
                    'in_point_ms' => $clip->in_point_ms,
                    'out_point_ms' => $clip->out_point_ms,
                    'volume' => $clip->volume,
                    'fit_mode' => $clip->fit_mode,
                ])->toArray(),
                'overlays' => $scene->overlays->map(fn (VideoProjectOverlay $overlay) => [
                    'id' => $overlay->id,
                    'type' => $overlay->type,
                    'content' => $overlay->content,
                    'style' => $overlay->style ?? [],
                    'position_x' => (float) $overlay->position_x,
                    'position_y' => (float) $overlay->position_y,
                    'width' => $overlay->width ? (float) $overlay->width : null,
                    'height' => $overlay->height ? (float) $overlay->height : null,
                    'start_ms' => $overlay->start_ms,
                    'end_ms' => $overlay->end_ms,
                ])->toArray(),
            ])->toArray(),
            'voiceovers' => $this->voiceovers->map(fn (VideoProjectVoiceover $vo) => [
                'id' => $vo->id,
                'recording_id' => $vo->video_recording_id,
                'url' => $vo->recording?->getVideoUrl(),
                'duration_seconds' => $vo->recording?->duration_seconds,
                'start_ms' => $vo->start_ms,
                'volume' => $vo->volume,
                'sort_order' => $vo->sort_order,
            ])->toArray(),
        ];
    }
};
?>

<div
    x-data="studioEditor({
        projectData: @js($this->projectData),
        csrfToken: @js(csrf_token()),
    })"
    class="flex flex-col h-[calc(100vh-64px)]"
>
    {{-- Top Bar --}}
    <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="flex items-center gap-3">
            <a href="{{ route('studio') }}" wire:navigate class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                <flux:icon name="arrow-left" class="size-5" />
            </a>
            <input
                type="text"
                value="{{ $project->title }}"
                @blur="$wire.updateTitle($event.target.value)"
                @keydown.enter="$event.target.blur()"
                class="text-lg font-semibold bg-transparent border-none focus:outline-none focus:ring-0 text-zinc-900 dark:text-white max-w-xs"
            />
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" @click="play()" x-show="!isPlaying && !isRecording" variant="ghost">
                <flux:icon name="play" class="size-4" />
                {{ __('Preview') }}
            </flux:button>
            <flux:button size="sm" @click="pause()" x-show="isPlaying && !isRecording" variant="ghost">
                <flux:icon name="pause" class="size-4" />
                {{ __('Pause') }}
            </flux:button>

            {{-- Recording Controls --}}
            <template x-if="!isRecording">
                <flux:button @click="startFinalRecording()" variant="primary" size="sm">
                    <div class="size-3 rounded-full bg-red-500 mr-1.5"></div>
                    {{ __('Record Final') }}
                </flux:button>
            </template>
            <template x-if="isRecording">
                <div class="flex items-center gap-3">
                    <flux:button @click="stopFinalRecording()" size="sm" class="!bg-red-600 hover:!bg-red-700 !text-white">
                        <flux:icon name="stop" class="size-4 mr-1" />
                        {{ __('Stop') }}
                    </flux:button>
                    <div class="flex items-center gap-2">
                        <div class="w-32 h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                            <div class="h-full bg-red-500 rounded-full transition-all" :style="`width: ${recordingProgress}%`"></div>
                        </div>
                        <span class="text-xs text-zinc-500 tabular-nums" x-text="recordingProgress + '%'"></span>
                    </div>
                    <div class="size-2 rounded-full bg-red-500 animate-pulse"></div>
                </div>
            </template>
            <template x-if="recordingError">
                <p class="text-xs text-red-500" x-text="recordingError"></p>
            </template>
        </div>
    </div>

    {{-- Main Editor Area --}}
    <div class="flex-1 flex overflow-hidden">
        {{-- Left Panel: Scene List --}}
        <div class="w-56 shrink-0 border-r border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 overflow-y-auto">
            <div class="p-3">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Scenes') }}</span>
                    <flux:dropdown>
                        <flux:button size="sm" variant="ghost" icon="plus" />
                        <flux:menu>
                            <flux:menu.item wire:click="addScene('solo')" icon="square-2-stack">
                                {{ __('Solo') }}
                            </flux:menu.item>
                            <flux:menu.item wire:click="addScene('split')" icon="view-columns">
                                {{ __('Split Screen') }}
                            </flux:menu.item>
                            <flux:menu.item wire:click="addScene('pip')" icon="squares-2x2">
                                {{ __('Picture in Picture') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                @if($this->scenes->isEmpty())
                    <div class="text-center py-8 text-zinc-400 text-sm">
                        {{ __('Add a scene to start') }}
                    </div>
                @else
                    <div class="space-y-1">
                        @foreach($this->scenes as $scene)
                            <div
                                wire:key="scene-{{ $scene->id }}"
                                wire:click="selectScene({{ $scene->id }})"
                                class="group cursor-pointer rounded-lg p-2 transition-colors {{ $activeSceneId === $scene->id ? 'bg-blue-50 dark:bg-blue-950/50 border border-blue-200 dark:border-blue-800' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800 border border-transparent' }}"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            @if($scene->layout === 'solo')
                                                <flux:icon name="square-2-stack" class="size-3.5 text-zinc-400 shrink-0" />
                                            @elseif($scene->layout === 'split')
                                                <flux:icon name="view-columns" class="size-3.5 text-zinc-400 shrink-0" />
                                            @elseif($scene->layout === 'pip')
                                                <flux:icon name="squares-2x2" class="size-3.5 text-zinc-400 shrink-0" />
                                            @endif
                                            <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300 truncate">{{ $scene->name ?: __('Scene :n', ['n' => $loop->iteration]) }}</span>
                                        </div>
                                        <span class="text-[10px] text-zinc-400 mt-0.5 block">
                                            {{ number_format($scene->getEffectiveDurationMs() / 1000, 1) }}s
                                        </span>
                                    </div>
                                    <button
                                        wire:click.stop="removeScene({{ $scene->id }})"
                                        wire:confirm="{{ __('Delete this scene?') }}"
                                        class="opacity-0 group-hover:opacity-100 transition-opacity text-zinc-400 hover:text-red-500"
                                    >
                                        <flux:icon name="x-mark" class="size-3.5" />
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Center: Video Composition Canvas --}}
        <div class="flex-1 flex flex-col bg-zinc-900">
            <div class="flex-1 flex items-center justify-center p-4">
                <div
                    x-ref="compositionContainer"
                    class="relative bg-black rounded-lg overflow-hidden shadow-2xl"
                    style="aspect-ratio: 16/9; max-width: 100%; max-height: 100%;"
                >
                    @if($this->activeScene)
                        {{-- Video slots rendered by Alpine based on layout --}}
                        <template x-for="clip in activeSceneData?.clips || []" :key="clip.id">
                            <div :class="getSlotClasses(activeSceneData?.layout, clip.slot)">
                                <template x-if="clip.url">
                                    <video
                                        :src="clip.url"
                                        :data-clip-id="clip.id"
                                        x-ref.dynamic="'video-' + clip.id"
                                        class="w-full h-full"
                                        :class="clip.fit_mode === 'contain' ? 'object-contain' : (clip.fit_mode === 'fill' ? 'object-fill' : 'object-cover')"
                                        muted
                                        playsinline
                                        preload="metadata"
                                    ></video>
                                </template>
                                <template x-if="!clip.url && clip.thumbnail">
                                    <img :src="clip.thumbnail" class="w-full h-full object-cover" />
                                </template>
                            </div>
                        </template>

                        {{-- Overlays --}}
                        <template x-for="overlay in activeSceneData?.overlays || []" :key="overlay.id">
                            <div
                                class="absolute pointer-events-none"
                                :style="`left: ${overlay.position_x * 100}%; top: ${overlay.position_y * 100}%; ${overlay.width ? 'width: ' + (overlay.width * 100) + '%' : ''}`"
                                x-show="isOverlayVisible(overlay)"
                            >
                                <template x-if="overlay.type === 'text' || overlay.type === 'caption'">
                                    <span
                                        :style="`
                                            font-size: ${overlay.style?.font_size || 24}px;
                                            color: ${overlay.style?.color || '#FFFFFF'};
                                            font-weight: ${overlay.style?.font_weight || 'normal'};
                                            ${overlay.style?.bg_color ? 'background-color: ' + overlay.style.bg_color + '; padding: 4px 8px; border-radius: 4px;' : ''}
                                        `"
                                        x-text="overlay.content"
                                    ></span>
                                </template>
                            </div>
                        </template>
                    @else
                        <div class="absolute inset-0 flex items-center justify-center text-zinc-600">
                            <div class="text-center">
                                <flux:icon name="film" class="size-12 mx-auto mb-2" />
                                <p>{{ __('Add a scene to start editing') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Playback Controls --}}
            <div class="shrink-0 px-4 py-2 flex items-center gap-3 border-t border-zinc-800">
                <button @click="isPlaying ? pause() : play()" class="text-white hover:text-blue-400 transition-colors">
                    <template x-if="!isPlaying">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                    </template>
                    <template x-if="isPlaying">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M5.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75A.75.75 0 007.25 3h-1.5zM12.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75a.75.75 0 00-.75-.75h-1.5z"/></svg>
                    </template>
                </button>
                <span class="text-xs text-zinc-400 font-mono" x-text="formatTime(currentTimeMs)"></span>
                <div class="flex-1 h-1 bg-zinc-800 rounded-full cursor-pointer" @click="seekToPercent($event)">
                    <div class="h-full bg-blue-500 rounded-full transition-all" :style="'width: ' + playbackPercent + '%'"></div>
                </div>
                <span class="text-xs text-zinc-400 font-mono" x-text="formatTime(totalDurationMs)"></span>
            </div>
        </div>

        {{-- Right Panel: Scene Properties --}}
        <div class="w-72 shrink-0 border-l border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-y-auto">
            @if($this->activeScene)
                <div class="p-4 space-y-6">
                    {{-- Scene Settings --}}
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Scene Settings') }}</h3>
                        <div class="space-y-3">
                            <flux:field>
                                <flux:label>{{ __('Name') }}</flux:label>
                                <flux:input size="sm" value="{{ $this->activeScene->name }}" @blur="$wire.updateScene({{ $activeSceneId }}, 'name', $event.target.value)" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Layout') }}</flux:label>
                                <flux:select size="sm" wire:change="updateScene({{ $activeSceneId }}, 'layout', $event.target.value)">
                                    <option value="solo" {{ $this->activeScene->layout === 'solo' ? 'selected' : '' }}>{{ __('Solo') }}</option>
                                    <option value="split" {{ $this->activeScene->layout === 'split' ? 'selected' : '' }}>{{ __('Split Screen') }}</option>
                                    <option value="pip" {{ $this->activeScene->layout === 'pip' ? 'selected' : '' }}>{{ __('Picture in Picture') }}</option>
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Transition') }}</flux:label>
                                <flux:select size="sm" wire:change="updateScene({{ $activeSceneId }}, 'transition_type', $event.target.value)">
                                    <option value="cut" {{ $this->activeScene->transition_type === 'cut' ? 'selected' : '' }}>{{ __('Cut') }}</option>
                                    <option value="fade" {{ $this->activeScene->transition_type === 'fade' ? 'selected' : '' }}>{{ __('Fade') }}</option>
                                    <option value="dissolve" {{ $this->activeScene->transition_type === 'dissolve' ? 'selected' : '' }}>{{ __('Dissolve') }}</option>
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Background') }}</flux:label>
                                <input type="color" value="{{ $this->activeScene->background_color }}" @change="$wire.updateScene({{ $activeSceneId }}, 'background_color', $event.target.value)" class="w-full h-8 rounded cursor-pointer" />
                            </flux:field>
                        </div>
                    </div>

                    <flux:separator />

                    {{-- Clips --}}
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Media Clips') }}</h3>

                        @php
                            $slots = match($this->activeScene->layout) {
                                'solo' => ['main' => __('Main')],
                                'split' => ['main' => __('Left'), 'secondary' => __('Right')],
                                'pip' => ['main' => __('Main'), 'pip' => __('PiP Corner')],
                                default => ['main' => __('Main')],
                            };
                        @endphp

                        <div class="space-y-2">
                            @foreach($slots as $slot => $label)
                                @php $clip = $this->activeScene->clips->firstWhere('slot', $slot); @endphp
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $label }}</span>
                                        @if($clip)
                                            <button wire:click="removeClip({{ $clip->id }})" class="text-zinc-400 hover:text-red-500">
                                                <flux:icon name="x-mark" class="size-3.5" />
                                            </button>
                                        @endif
                                    </div>
                                    @if($clip)
                                        <div class="flex items-center gap-2">
                                            <div class="w-16 aspect-video bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden shrink-0">
                                                @if($clip->source instanceof \App\Models\VideoRecording && $clip->source->getThumbnailUrl())
                                                    <img src="{{ $clip->source->getThumbnailUrl() }}" class="w-full h-full object-cover" />
                                                @else
                                                    <div class="w-full h-full flex items-center justify-center">
                                                        <flux:icon name="video-camera" class="size-4 text-zinc-400" />
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="min-w-0 text-xs text-zinc-600 dark:text-zinc-400 truncate">
                                                {{ $clip->source?->title ?? $clip->source?->caption ?? __('Media') }}
                                            </div>
                                        </div>
                                        {{-- Volume slider --}}
                                        <div class="mt-2">
                                            <label class="text-[10px] text-zinc-500">{{ __('Volume') }}: <span x-text="'{{ $clip->volume }}%'"></span></label>
                                            <input type="range" min="0" max="100" value="{{ $clip->volume }}" @change="$wire.updateClip({{ $clip->id }}, 'volume', parseInt($event.target.value))" class="w-full h-1 accent-blue-500" />
                                        </div>
                                    @else
                                        <button wire:click="openMediaPicker('{{ $slot }}')" class="w-full py-3 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg text-xs text-zinc-500 hover:border-blue-400 hover:text-blue-500 transition-colors">
                                            <flux:icon name="plus" class="size-4 mx-auto mb-1" />
                                            {{ __('Add Media') }}
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <flux:separator />

                    {{-- Overlays --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Overlays') }}</h3>
                            <flux:button size="sm" variant="ghost" wire:click="addOverlay" icon="plus" />
                        </div>

                        @if($this->activeScene->overlays->isEmpty())
                            <p class="text-xs text-zinc-400 text-center py-3">{{ __('No overlays') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach($this->activeScene->overlays as $overlay)
                                    <div wire:key="overlay-{{ $overlay->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-2">
                                        <div class="flex items-center justify-between mb-1">
                                            <flux:badge size="sm" color="{{ $overlay->isCaption() ? 'blue' : ($overlay->isImage() ? 'green' : 'zinc') }}">
                                                {{ ucfirst($overlay->type) }}
                                            </flux:badge>
                                            <button wire:click="removeOverlay({{ $overlay->id }})" class="text-zinc-400 hover:text-red-500">
                                                <flux:icon name="x-mark" class="size-3.5" />
                                            </button>
                                        </div>
                                        <flux:input size="sm" value="{{ $overlay->content }}" @blur="$wire.updateOverlay({{ $overlay->id }}, { content: $event.target.value })" class="mt-1" />
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <flux:separator />

                    {{-- Voiceovers --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Voiceovers') }}</h3>
                        </div>

                        <livewire:studio.voiceover-recorder :video-project="$project" wire:key="voiceover-recorder" />

                        @if($this->voiceovers->isNotEmpty())
                            <div class="mt-3 space-y-2">
                                @foreach($this->voiceovers as $voiceover)
                                    <div wire:key="voiceover-{{ $voiceover->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-2">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="flex items-center gap-1.5">
                                                <flux:icon name="microphone" class="size-3.5 text-zinc-400 shrink-0" />
                                                <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                    {{ __('Voiceover :n', ['n' => $loop->iteration]) }}
                                                </span>
                                            </div>
                                            <button
                                                wire:click="removeVoiceover({{ $voiceover->id }})"
                                                wire:confirm="{{ __('Delete this voiceover?') }}"
                                                class="text-zinc-400 hover:text-red-500"
                                            >
                                                <flux:icon name="x-mark" class="size-3.5" />
                                            </button>
                                        </div>

                                        <div class="flex items-center gap-2 text-[10px] text-zinc-500 mb-1.5">
                                            @if($voiceover->recording?->duration_seconds)
                                                <span>{{ gmdate('i:s', $voiceover->recording->duration_seconds) }}</span>
                                            @endif
                                            <span>{{ __('Starts at :time', ['time' => number_format($voiceover->start_ms / 1000, 1) . 's']) }}</span>
                                        </div>

                                        {{-- Volume slider --}}
                                        <div>
                                            <label class="text-[10px] text-zinc-500">{{ __('Volume') }}: {{ $voiceover->volume }}%</label>
                                            <input
                                                type="range"
                                                min="0"
                                                max="100"
                                                value="{{ $voiceover->volume }}"
                                                @change="$wire.updateVoiceover({{ $voiceover->id }}, 'volume', parseInt($event.target.value))"
                                                class="w-full h-1 accent-blue-500"
                                            />
                                        </div>

                                        {{-- Start offset --}}
                                        <div class="mt-1">
                                            <label class="text-[10px] text-zinc-500">{{ __('Start offset (ms)') }}:</label>
                                            <flux:input
                                                size="sm"
                                                type="number"
                                                min="0"
                                                value="{{ $voiceover->start_ms }}"
                                                @blur="$wire.updateVoiceover({{ $voiceover->id }}, 'start_ms', parseInt($event.target.value))"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Caption Controls --}}
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 mt-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Captions') }}</span>
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="generateCaptions({{ $this->activeScene->id }})" size="sm" variant="ghost">
                                <flux:icon name="language" class="size-4 mr-1" />
                                {{ __('Generate') }}
                            </flux:button>
                            <flux:button wire:click="clearCaptions({{ $this->activeScene->id }})" size="sm" variant="ghost" class="text-red-600 dark:text-red-400">
                                {{ __('Clear') }}
                            </flux:button>
                        </div>
                        <div wire:loading wire:target="generateCaptions" class="mt-2">
                            <p class="text-xs text-zinc-500 animate-pulse">{{ __('Generating captions via AI...') }}</p>
                        </div>
                    </div>

                    {{-- Audio Controls --}}
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 mt-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Audio') }}</span>
                        </div>
                        <flux:button x-on:click="autoLevelAudio()" size="sm" variant="ghost" x-show="Object.keys(gainNodes || {}).length > 0">
                            <flux:icon name="adjustments-horizontal" class="size-4 mr-1" />
                            <span x-text="audioLeveled ? '{{ __('Re-Level Audio') }}' : '{{ __('Auto-Level Audio') }}'"></span>
                        </flux:button>
                        <p x-show="Object.keys(gainNodes || {}).length === 0" class="text-xs text-zinc-400">{{ __('Play to enable audio controls') }}</p>
                    </div>
                </div>
            @else
                <div class="flex items-center justify-center h-full text-zinc-400 text-sm">
                    <p>{{ __('Select a scene') }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Timeline Bar --}}
    <div class="shrink-0 border-t border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3">
        <div class="flex items-center gap-2 overflow-x-auto">
            @foreach($this->scenes as $scene)
                <div
                    wire:key="timeline-{{ $scene->id }}"
                    wire:click="selectScene({{ $scene->id }})"
                    class="shrink-0 cursor-pointer rounded px-3 py-1.5 text-xs font-medium transition-colors {{ $activeSceneId === $scene->id ? 'bg-blue-500 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
                    style="min-width: {{ max(60, ($scene->getEffectiveDurationMs() / 1000) * 8) }}px"
                >
                    {{ $scene->name ?: __('Scene :n', ['n' => $loop->iteration]) }}
                    <span class="opacity-60 ml-1">{{ number_format($scene->getEffectiveDurationMs() / 1000, 1) }}s</span>
                </div>
            @endforeach

            @if($this->scenes->isNotEmpty())
                <span class="text-xs text-zinc-400 ml-2">
                    {{ __('Total') }}: {{ number_format($project->getComputedDurationMs() / 1000, 1) }}s
                </span>
            @endif
        </div>
    </div>

    {{-- Media Picker Modal --}}
    @if($showMediaPicker)
        <livewire:studio.media-picker wire:key="media-picker" />
    @endif
</div>

@script
<script>
Alpine.data('studioEditor', (config) => ({
    projectData: config.projectData,
    isPlaying: false,
    isRecording: false,
    currentTimeMs: 0,
    totalDurationMs: 0,
    masterVolume: 100,
    playbackTimer: null,
    playbackPercent: 0,
    audioContext: null,
    gainNodes: {},
    audioLeveled: false,
    voiceoverElements: {},
    voiceoverGainNodes: {},

    init() {
        this.computeTotalDuration();

        Livewire.hook('morph.updated', () => {
            this.$nextTick(() => {
                this.projectData = this.$wire.projectData;
                this.computeTotalDuration();
            });
        });
    },

    get activeSceneData() {
        const activeId = this.$wire.activeSceneId;
        return this.projectData?.scenes?.find(s => s.id === activeId) || null;
    },

    computeTotalDuration() {
        this.totalDurationMs = (this.projectData?.scenes || []).reduce((sum, s) => sum + (s.duration_ms || 5000), 0);
    },

    getSlotClasses(layout, slot) {
        const base = 'absolute overflow-hidden';
        if (layout === 'solo') {
            return `${base} inset-0`;
        }
        if (layout === 'split') {
            if (slot === 'main') { return `${base} left-0 top-0 w-1/2 h-full`; }
            if (slot === 'secondary') { return `${base} right-0 top-0 w-1/2 h-full`; }
        }
        if (layout === 'pip') {
            if (slot === 'main') { return `${base} inset-0`; }
            if (slot === 'pip') { return `${base} right-[4%] bottom-[4%] w-1/4 h-1/4 rounded-lg shadow-lg z-10`; }
        }
        return `${base} inset-0`;
    },

    isOverlayVisible(overlay) {
        if (!this.isPlaying) { return true; }
        const time = this.currentTimeMs;
        if (time < (overlay.start_ms || 0)) { return false; }
        if (overlay.end_ms && time > overlay.end_ms) { return false; }
        return true;
    },

    play() {
        this.isPlaying = true;
        const startTime = Date.now() - this.currentTimeMs;

        this.playbackTimer = setInterval(() => {
            this.currentTimeMs = Date.now() - startTime;
            this.playbackPercent = this.totalDurationMs > 0 ? (this.currentTimeMs / this.totalDurationMs) * 100 : 0;

            if (this.currentTimeMs >= this.totalDurationMs) {
                this.pause();
                this.currentTimeMs = 0;
                this.playbackPercent = 0;
            }
        }, 50);

        // Connect audio nodes and play all video elements
        this.connectAudioNodes();
        this.$el.querySelectorAll('video[data-clip-id]').forEach(v => {
            v.play().catch(() => {});
        });

        // Play voiceover audio tracks
        this.playVoiceovers();
    },

    pause() {
        this.isPlaying = false;
        if (this.playbackTimer) {
            clearInterval(this.playbackTimer);
            this.playbackTimer = null;
        }
        this.$el.querySelectorAll('video[data-clip-id]').forEach(v => v.pause());
        this.pauseVoiceovers();
    },

    seekToPercent(event) {
        const rect = event.currentTarget.getBoundingClientRect();
        const percent = (event.clientX - rect.left) / rect.width;
        this.currentTimeMs = Math.floor(percent * this.totalDurationMs);
        this.playbackPercent = percent * 100;
    },

    formatTime(ms) {
        const totalSec = Math.floor(ms / 1000);
        const min = Math.floor(totalSec / 60);
        const sec = totalSec % 60;
        return `${min}:${sec.toString().padStart(2, '0')}`;
    },

    ensureAudioContext() {
        if (!this.audioContext) {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        return this.audioContext;
    },

    connectAudioNodes() {
        const ctx = this.ensureAudioContext();
        this.gainNodes = {};

        this.$el.querySelectorAll('video[data-clip-id]').forEach(video => {
            const clipId = video.dataset.clipId;
            if (!clipId || this.gainNodes[clipId]) { return; }

            try {
                const source = ctx.createMediaElementSource(video);
                const gainNode = ctx.createGain();
                source.connect(gainNode);
                gainNode.connect(ctx.destination);
                this.gainNodes[clipId] = gainNode;
                video.muted = false;
            } catch (e) {
                // Source may already be connected
            }
        });
    },

    autoLevelAudio() {
        if (!this.audioContext) { return; }

        const levels = [];

        Object.entries(this.gainNodes).forEach(([id, gainNode]) => {
            const analyser = this.audioContext.createAnalyser();
            analyser.fftSize = 2048;
            gainNode.connect(analyser);

            const dataArray = new Float32Array(analyser.frequencyBinCount);
            analyser.getFloatTimeDomainData(dataArray);

            let sum = 0;
            for (let i = 0; i < dataArray.length; i++) {
                sum += dataArray[i] * dataArray[i];
            }
            const rms = Math.sqrt(sum / dataArray.length);
            levels.push({ id, rms, gainNode });
        });

        if (levels.length === 0) { return; }

        const avgRms = levels.reduce((s, l) => s + l.rms, 0) / levels.length;
        const targetRms = Math.max(avgRms, 0.01);

        levels.forEach(({ rms, gainNode }) => {
            if (rms > 0.001) {
                const adjustment = targetRms / rms;
                gainNode.gain.value = Math.min(Math.max(adjustment, 0.1), 5.0);
            }
        });

        this.audioLeveled = true;
    },

    playVoiceovers() {
        const voiceovers = this.projectData?.voiceovers || [];
        const ctx = this.ensureAudioContext();

        voiceovers.forEach(vo => {
            if (!vo.url) return;

            let audio = this.voiceoverElements[vo.id];
            if (!audio) {
                audio = new Audio(vo.url);
                audio.preload = 'auto';
                this.voiceoverElements[vo.id] = audio;

                try {
                    const source = ctx.createMediaElementSource(audio);
                    const gain = ctx.createGain();
                    gain.gain.value = (vo.volume || 100) / 100;
                    source.connect(gain);
                    gain.connect(ctx.destination);
                    this.voiceoverGainNodes[vo.id] = gain;
                } catch (e) {
                    // Source may already be connected
                }
            }

            if (this.voiceoverGainNodes[vo.id]) {
                this.voiceoverGainNodes[vo.id].gain.value = (vo.volume || 100) / 100;
            }

            const offsetSec = Math.max(0, (this.currentTimeMs - (vo.start_ms || 0)) / 1000);
            if (this.currentTimeMs >= (vo.start_ms || 0)) {
                audio.currentTime = offsetSec;
                audio.play().catch(() => {});
            }
        });
    },

    pauseVoiceovers() {
        Object.values(this.voiceoverElements).forEach(audio => {
            audio.pause();
        });
    },

    recordingProgress: 0,
    recordingError: null,
    activeRecorder: null,
    activeCheckInterval: null,
    recordingAudioCtx: null,
    activeSceneIndex: 0,

    async startFinalRecording() {
        if (this.isRecording) return;
        this.isRecording = true;
        this.recordingProgress = 0;
        this.recordingError = null;

        try {
            // 1. Create offscreen canvas at project resolution
            const canvas = document.createElement('canvas');
            canvas.width = this.projectData.resolution_width || 1920;
            canvas.height = this.projectData.resolution_height || 1080;
            const ctx = canvas.getContext('2d');

            // 2. Create audio context for mixing into recording
            this.recordingAudioCtx = new AudioContext();
            const destination = this.recordingAudioCtx.createMediaStreamDestination();

            // Connect all video elements through gain nodes to the recording destination
            this.$el.querySelectorAll('video[data-clip-id]').forEach(video => {
                try {
                    const source = this.recordingAudioCtx.createMediaElementSource(video);
                    const clipId = video.dataset.clipId;
                    const clipVolume = this.gainNodes[clipId]?.gain?.value ?? 1.0;
                    const gain = this.recordingAudioCtx.createGain();
                    gain.gain.value = clipVolume;
                    source.connect(gain);
                    gain.connect(destination);
                    gain.connect(this.recordingAudioCtx.destination);
                    video.muted = false;
                } catch (e) {
                    // Element may already be connected
                }
            });

            // Connect voiceover audio elements
            Object.entries(this.voiceoverElements || {}).forEach(([id, audio]) => {
                try {
                    const source = this.recordingAudioCtx.createMediaElementSource(audio);
                    const voVolume = this.voiceoverGainNodes[id]?.gain?.value ?? 1.0;
                    const gain = this.recordingAudioCtx.createGain();
                    gain.gain.value = voVolume;
                    source.connect(gain);
                    gain.connect(destination);
                    gain.connect(this.recordingAudioCtx.destination);
                } catch (e) {
                    // Element may already be connected
                }
            });

            // 3. Combine canvas video stream + mixed audio
            const videoStream = canvas.captureStream(30);
            const combined = new MediaStream([
                ...videoStream.getVideoTracks(),
                ...destination.stream.getAudioTracks(),
            ]);

            // 4. MediaRecorder setup
            const mimeType = (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus'))
                ? 'video/webm;codecs=vp9,opus'
                : 'video/webm';

            const recorder = new MediaRecorder(combined, {
                mimeType,
                videoBitsPerSecond: 5000000,
            });

            // 5. Start chunked upload session
            const startResponse = await fetch('/api/video-recordings/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    title: (this.projectData.title || 'Project') + ' - Rendered',
                    mime_type: mimeType,
                }),
            });

            if (!startResponse.ok) throw new Error('Failed to start upload session');
            const { id: recordingId } = await startResponse.json();

            let chunkIndex = 0;

            // 6. Handle data chunks - stream to server
            recorder.ondataavailable = async (e) => {
                if (e.data.size > 0) {
                    const formData = new FormData();
                    formData.append('chunk', e.data, `chunk_${chunkIndex}.webm`);
                    formData.append('chunk_index', chunkIndex);
                    chunkIndex++;

                    try {
                        await fetch(`/api/video-recordings/${recordingId}/chunk`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': config.csrfToken,
                                'Accept': 'application/json',
                            },
                            body: formData,
                        });
                    } catch (err) {
                        console.error('Chunk upload failed:', err);
                    }
                }
            };

            // 7. Canvas compositing loop
            let animationId;
            const renderFrame = () => {
                if (!this.isRecording) return;
                this.compositeToCanvas(ctx, canvas);
                animationId = requestAnimationFrame(renderFrame);
            };

            // 8. Handle recording stop - finalize upload
            recorder.onstop = async () => {
                cancelAnimationFrame(animationId);

                await fetch(`/api/video-recordings/${recordingId}/finalize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                });

                this.$wire.linkRenderedVideo(recordingId);
                this.isRecording = false;
                this.recordingProgress = 100;

                if (this.recordingAudioCtx) {
                    this.recordingAudioCtx.close().catch(() => {});
                    this.recordingAudioCtx = null;
                }
            };

            // 9. Scene progression based on elapsed time
            const totalDuration = this.getTotalDurationMs();
            const startTime = Date.now();

            this.activeCheckInterval = setInterval(() => {
                const elapsed = Date.now() - startTime;
                this.recordingProgress = Math.min(99, Math.round((elapsed / totalDuration) * 100));
                this.advanceTimeline(elapsed);

                if (elapsed >= totalDuration) {
                    clearInterval(this.activeCheckInterval);
                    this.activeCheckInterval = null;
                    recorder.stop();
                }
            }, 100);

            // 10. Start everything
            recorder.start(1000); // 1-second chunks
            this.seekTo(0);
            this.play();
            renderFrame();

            this.activeRecorder = recorder;

        } catch (error) {
            console.error('Recording failed:', error);
            this.isRecording = false;
            this.recordingError = error.message;
        }
    },

    stopFinalRecording() {
        if (this.activeRecorder && this.activeRecorder.state !== 'inactive') {
            this.activeRecorder.stop();
        }
        if (this.activeCheckInterval) {
            clearInterval(this.activeCheckInterval);
            this.activeCheckInterval = null;
        }
        this.pause();
    },

    compositeToCanvas(ctx, canvas) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const scene = this.getActiveSceneData();
        if (!scene) return;

        // Background color
        ctx.fillStyle = scene.background_color || '#000000';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Draw clips in their layout slots
        (scene.clips || []).forEach(clip => {
            const video = this.$el.querySelector(`video[data-clip-id="${clip.id}"]`);
            if (!video || video.readyState < 2) return;

            const slot = this.getSlotPosition(scene.layout, clip.slot);
            const dx = slot.x * canvas.width;
            const dy = slot.y * canvas.height;
            const dw = slot.w * canvas.width;
            const dh = slot.h * canvas.height;

            if (clip.fit_mode === 'contain') {
                const vRatio = video.videoWidth / video.videoHeight;
                const sRatio = dw / dh;
                let sw, sh, sx, sy;
                if (vRatio > sRatio) {
                    sw = dw;
                    sh = dw / vRatio;
                    sx = dx;
                    sy = dy + (dh - sh) / 2;
                } else {
                    sh = dh;
                    sw = dh * vRatio;
                    sx = dx + (dw - sw) / 2;
                    sy = dy;
                }
                ctx.drawImage(video, sx, sy, sw, sh);
            } else {
                ctx.drawImage(video, dx, dy, dw, dh);
            }
        });

        // Draw overlays
        (scene.overlays || []).forEach(overlay => {
            if (!this.isOverlayVisible(overlay)) return;

            const style = overlay.style || {};
            const fontSize = (style.font_size || 24) * (canvas.height / 1080);
            const x = overlay.position_x * canvas.width;
            const y = overlay.position_y * canvas.height;

            ctx.font = `${style.font_weight || 'normal'} ${fontSize}px sans-serif`;

            if (style.bg_color) {
                const metrics = ctx.measureText(overlay.content || '');
                const padding = 8 * (canvas.height / 1080);
                ctx.fillStyle = style.bg_color;
                ctx.fillRect(x - padding, y - fontSize, metrics.width + padding * 2, fontSize + padding);
            }

            ctx.fillStyle = style.color || '#FFFFFF';
            ctx.textAlign = style.alignment || 'left';
            ctx.fillText(overlay.content || '', x, y);
        });
    },

    getSlotPosition(layout, slot) {
        if (layout === 'split') {
            if (slot === 'main') return { x: 0, y: 0, w: 0.5, h: 1 };
            if (slot === 'secondary') return { x: 0.5, y: 0, w: 0.5, h: 1 };
        }
        if (layout === 'pip') {
            if (slot === 'main') return { x: 0, y: 0, w: 1, h: 1 };
            if (slot === 'pip') return { x: 0.72, y: 0.72, w: 0.25, h: 0.25 };
        }
        return { x: 0, y: 0, w: 1, h: 1 };
    },

    getTotalDurationMs() {
        if (!this.projectData || !this.projectData.scenes) return 0;
        return this.projectData.scenes.reduce((total, scene) => {
            return total + (scene.duration_ms || 5000);
        }, 0);
    },

    advanceTimeline(elapsedMs) {
        if (!this.projectData || !this.projectData.scenes) return;

        let accumulated = 0;
        for (let i = 0; i < this.projectData.scenes.length; i++) {
            const sceneDuration = this.projectData.scenes[i].duration_ms || 5000;
            if (elapsedMs >= accumulated && elapsedMs < accumulated + sceneDuration) {
                if (this.activeSceneIndex !== i) {
                    this.activeSceneIndex = i;
                    this.$wire.set('activeSceneId', this.projectData.scenes[i].id);
                }
                this.currentTimeMs = elapsedMs - accumulated;
                return;
            }
            accumulated += sceneDuration;
        }
    },

    getActiveSceneData() {
        if (!this.projectData || !this.projectData.scenes) return null;
        return this.projectData.scenes[this.activeSceneIndex] || this.projectData.scenes[0];
    },

    seekTo(ms) {
        this.currentTimeMs = ms;
        this.playbackPercent = this.totalDurationMs > 0 ? (ms / this.totalDurationMs) * 100 : 0;
    },
}));
</script>
@endscript
