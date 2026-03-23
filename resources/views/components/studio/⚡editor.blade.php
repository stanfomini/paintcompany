<?php

use App\Jobs\GenerateSceneCaptionsJob;
use App\Models\Job;
use App\Models\PropertyPhoto;
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

    public string $mediaTab = 'my_videos';
    public string $mediaSearch = '';

    public function openMediaPicker(string $slot = 'main'): void
    {
        $this->pendingSlot = $slot;
        $this->showMediaPicker = true;
    }

    public function selectMedia(string $type, int $id): void
    {
        if (! $this->activeSceneId || ! $this->pendingSlot) {
            return;
        }

        if (! in_array($type, ['video-recording', 'property-photo'])) {
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

    #[Computed]
    public function pickerVideos()
    {
        return VideoRecording::where('user_id', auth()->id())
            ->where('status', 'ready')
            ->when($this->mediaSearch, fn ($q) => $q->where('title', 'like', "%{$this->mediaSearch}%"))
            ->latest()
            ->limit(24)
            ->get();
    }

    #[Computed]
    public function pickerJobVideos()
    {
        return VideoRecording::whereHasMorph('recordable', [Job::class])
            ->where('status', 'ready')
            ->when($this->mediaSearch, fn ($q) => $q->where('title', 'like', "%{$this->mediaSearch}%"))
            ->latest()
            ->limit(24)
            ->get();
    }

    #[Computed]
    public function pickerPhotos()
    {
        return PropertyPhoto::query()
            ->when($this->mediaSearch, fn ($q) => $q->where('caption', 'like', "%{$this->mediaSearch}%"))
            ->latest()
            ->limit(24)
            ->get();
    }

    public function removeClip(int $clipId): void
    {
        VideoProjectClip::where('id', $clipId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->delete();
    }

    public function updateClip(int $clipId, string $field, mixed $value): void
    {
        $allowed = ['volume', 'in_point_ms', 'out_point_ms', 'start_ms', 'fit_mode'];
        if (! in_array($field, $allowed)) {
            return;
        }

        VideoProjectClip::where('id', $clipId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->update([$field => $value]);
    }

    /**
     * Persist the timeline state from the client-side Alpine editor.
     * Handles bulk upsert of clips and voiceovers, and deletes removed items.
     *
     * @param  array<int, array{id: int|string, scene_id: int, in_point_ms: int, out_point_ms: int|null, start_ms: int, volume: int, sort_order: int, slot: string, source_type: string, source_id: int, fit_mode: string}>  $clips
     * @param  array<int, array{id: int|string, in_point_ms: int, out_point_ms: int|null, start_ms: int, volume: int, sort_order: int}>  $voiceovers
     */
    public function saveTimelineState(array $clips, array $voiceovers): void
    {
        $projectSceneIds = $this->project->scenes()->pluck('id')->toArray();

        // --- Clips ---
        $existingClipIds = [];
        foreach ($clips as $clipData) {
            $sceneId = (int) ($clipData['scene_id'] ?? 0);
            if (! in_array($sceneId, $projectSceneIds)) {
                continue;
            }

            $clipId = $clipData['id'] ?? null;

            // Temp IDs (from client-side splits) start with 'temp_'
            $isTemp = is_string($clipId) && str_starts_with($clipId, 'temp_');

            $attributes = [
                'video_project_scene_id' => $sceneId,
                'slot' => $clipData['slot'] ?? 'main',
                'source_type' => $clipData['source_type'] ?? 'video-recording',
                'source_id' => (int) ($clipData['source_id'] ?? 0),
                'in_point_ms' => (int) ($clipData['in_point_ms'] ?? 0),
                'out_point_ms' => isset($clipData['out_point_ms']) ? (int) $clipData['out_point_ms'] : null,
                'start_ms' => (int) ($clipData['start_ms'] ?? 0),
                'volume' => (int) ($clipData['volume'] ?? 100),
                'fit_mode' => $clipData['fit_mode'] ?? 'cover',
                'sort_order' => (int) ($clipData['sort_order'] ?? 0),
            ];

            if ($isTemp || ! $clipId) {
                $clip = VideoProjectClip::create($attributes);
                $existingClipIds[] = $clip->id;
            } else {
                VideoProjectClip::where('id', (int) $clipId)
                    ->whereIn('video_project_scene_id', $projectSceneIds)
                    ->update($attributes);
                $existingClipIds[] = (int) $clipId;
            }
        }

        // Delete clips that were removed client-side
        if (! empty($existingClipIds)) {
            VideoProjectClip::whereIn('video_project_scene_id', $projectSceneIds)
                ->whereNotIn('id', $existingClipIds)
                ->delete();
        }

        // --- Voiceovers ---
        $existingVoIds = [];
        foreach ($voiceovers as $voData) {
            $voId = $voData['id'] ?? null;
            $isTemp = is_string($voId) && str_starts_with($voId, 'temp_');

            $attributes = [
                'video_project_id' => $this->project->id,
                'video_recording_id' => (int) ($voData['recording_id'] ?? $voData['video_recording_id'] ?? 0),
                'in_point_ms' => (int) ($voData['in_point_ms'] ?? 0),
                'out_point_ms' => isset($voData['out_point_ms']) ? (int) $voData['out_point_ms'] : null,
                'start_ms' => (int) ($voData['start_ms'] ?? 0),
                'volume' => (int) ($voData['volume'] ?? 100),
                'sort_order' => (int) ($voData['sort_order'] ?? 0),
            ];

            if ($isTemp || ! $voId) {
                $vo = VideoProjectVoiceover::create($attributes);
                $existingVoIds[] = $vo->id;
            } else {
                VideoProjectVoiceover::where('id', (int) $voId)
                    ->where('video_project_id', $this->project->id)
                    ->update($attributes);
                $existingVoIds[] = (int) $voId;
            }
        }

        // Delete voiceovers that were removed client-side
        if (! empty($existingVoIds)) {
            VideoProjectVoiceover::where('video_project_id', $this->project->id)
                ->whereNotIn('id', $existingVoIds)
                ->delete();
        }

        // Clear computed caches
        unset($this->scenes, $this->voiceovers, $this->projectData);
    }

    /**
     * Split a clip at the given source-relative ms position.
     * Creates two clips from the same source with adjusted in/out points.
     */
    public function splitClip(int $clipId, int $splitAtMs): void
    {
        $clip = VideoProjectClip::where('id', $clipId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->first();

        if (! $clip) {
            return;
        }

        $inPoint = $clip->in_point_ms ?? 0;
        $outPoint = $clip->out_point_ms;

        // splitAtMs is relative to the source — must be between in and out
        if ($splitAtMs <= $inPoint || ($outPoint && $splitAtMs >= $outPoint)) {
            return;
        }

        // Shift all subsequent clips' sort_order up by 1
        VideoProjectClip::where('video_project_scene_id', $clip->video_project_scene_id)
            ->where('sort_order', '>', $clip->sort_order)
            ->increment('sort_order');

        // Create second clip (right half) with sort_order right after original
        VideoProjectClip::create([
            'video_project_scene_id' => $clip->video_project_scene_id,
            'slot' => $clip->slot.'_split_'.now()->timestamp,
            'source_type' => $clip->source_type,
            'source_id' => $clip->source_id,
            'in_point_ms' => $splitAtMs,
            'out_point_ms' => $outPoint,
            'volume' => $clip->volume,
            'fit_mode' => $clip->fit_mode,
            'sort_order' => $clip->sort_order + 1,
        ]);

        // Update original clip to end at split point (left half)
        $clip->update(['out_point_ms' => $splitAtMs]);
    }

    /**
     * Split a voiceover at the given source-relative ms position.
     */
    public function splitVoiceover(int $voiceoverId, int $splitAtMs): void
    {
        $vo = VideoProjectVoiceover::where('id', $voiceoverId)
            ->where('video_project_id', $this->project->id)
            ->first();

        if (! $vo) {
            return;
        }

        $inPoint = $vo->in_point_ms ?? 0;
        $outPoint = $vo->out_point_ms;
        $totalMs = ($vo->recording?->duration_seconds ?? 5) * 1000;
        $effectiveOut = $outPoint ?? $totalMs;

        if ($splitAtMs <= $inPoint || $splitAtMs >= $effectiveOut) {
            return;
        }

        // Duration of left half (used to position right half on timeline)
        $leftDuration = $splitAtMs - $inPoint;
        $rightStart = ($vo->start_ms ?? 0) + $leftDuration;

        // Create second voiceover (right half)
        VideoProjectVoiceover::create([
            'video_project_id' => $this->project->id,
            'video_recording_id' => $vo->video_recording_id,
            'in_point_ms' => $splitAtMs,
            'out_point_ms' => $outPoint,
            'start_ms' => $rightStart,
            'volume' => $vo->volume,
            'sort_order' => ($vo->sort_order ?? 0) + 1,
        ]);

        // Update original to end at split point
        $vo->update(['out_point_ms' => $splitAtMs]);
    }

    // --- Overlay CRUD ---

    public function addOverlay(string $type = 'text', string $content = '', ?int $atMs = null): void
    {
        if (! $this->activeSceneId) {
            return;
        }

        $scene = $this->project->scenes()->findOrFail($this->activeSceneId);
        $sceneDuration = $scene->getEffectiveDurationMs();
        $startMs = $atMs !== null ? max(0, min($atMs, $sceneDuration)) : 0;

        $scene->overlays()->create([
            'type' => $type,
            'content' => $content ?: __('New text'),
            'position_x' => 0.5,
            'position_y' => 0.5,
            'start_ms' => $startMs,
            'end_ms' => $sceneDuration,
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

    public function updateOverlayBatch(array $overlays): void
    {
        $allowed = ['content', 'style', 'position_x', 'position_y', 'width', 'height', 'start_ms', 'end_ms'];

        foreach ($overlays as $overlayData) {
            $overlayId = (int) ($overlayData['id'] ?? 0);
            if (! $overlayId) {
                continue;
            }

            VideoProjectOverlay::where('id', $overlayId)
                ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
                ->update(collect($overlayData)->only($allowed)->toArray());
        }
    }

    public function removeOverlay(int $overlayId): void
    {
        VideoProjectOverlay::where('id', $overlayId)
            ->whereHas('scene', fn ($q) => $q->where('video_project_id', $this->project->id))
            ->delete();
    }

    // --- Captions ---

    public function generateCaptions(int $sceneId, string $source = 'video'): void
    {
        $scene = VideoProjectScene::where('id', $sceneId)
            ->whereHas('project', fn ($q) => $q->where('user_id', auth()->id()))
            ->first();

        if (! $scene) {
            return;
        }

        GenerateSceneCaptionsJob::dispatchSync($scene, $source);
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
        $allowed = ['volume', 'start_ms', 'in_point_ms', 'out_point_ms', 'sort_order'];
        if (! in_array($field, $allowed)) {
            return;
        }

        VideoProjectVoiceover::where('id', $voiceoverId)
            ->whereHas('project', fn ($q) => $q->where('user_id', auth()->id()))
            ->update([$field => $value]);
    }

    #[On('voiceover-finalized')]
    public function onVoiceoverFinalized(int $recordingId): void
    {
        // Ensure the recording is linked to this project so it stays out of the media hub
        $recording = VideoRecording::find($recordingId);
        if ($recording && ! $recording->recordable_type) {
            $recording->update([
                'recordable_type' => VideoProject::class,
                'recordable_id' => $this->project->id,
            ]);
        }

        $this->project->voiceovers()->create([
            'video_recording_id' => $recordingId,
            'start_ms' => 0,
            'volume' => 100,
            'sort_order' => $this->project->voiceovers()->count(),
        ]);

        unset($this->voiceovers, $this->projectData);
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
                    'stream_url' => $clip->source instanceof VideoRecording ? $clip->source->getStreamUrl() : null,
                    'thumbnail' => $clip->source instanceof VideoRecording ? $clip->source->getThumbnailUrl() : ($clip->source?->thumbnail_url ?? null),
                    'in_point_ms' => $clip->in_point_ms,
                    'out_point_ms' => $clip->out_point_ms,
                    'start_ms' => $clip->start_ms,
                    'volume' => $clip->volume,
                    'fit_mode' => $clip->fit_mode,
                    'sort_order' => $clip->sort_order,
                    'source_title' => $clip->source?->title ?? $clip->source?->caption ?? __('Clip'),
                    'duration_seconds' => $clip->source instanceof VideoRecording ? $clip->source->duration_seconds : null,
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
                'in_point_ms' => $vo->in_point_ms,
                'out_point_ms' => $vo->out_point_ms,
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
    {{-- Hidden data source for Alpine to read after Livewire morph --}}
    <div x-ref="projectDataSource" class="hidden" data-project-json="{{ json_encode($this->projectData) }}"></div>

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
            <flux:button size="sm" @click="playAll()" x-show="!isPlaying && !isRecording" variant="ghost">
                <flux:icon name="play" class="size-4" />
                {{ __('Preview All') }}
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
                    class="relative bg-black rounded-lg overflow-hidden shadow-2xl w-full cursor-pointer"
                    style="aspect-ratio: 16/9;"
                    @click="isPlaying ? pause() : playScene()"
                >
                    {{-- Play/Pause overlay indicator --}}
                    <div
                        class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none transition-opacity duration-300"
                        :class="isPlaying ? 'opacity-0' : 'opacity-100'"
                    >
                        <div class="bg-black/50 rounded-full p-4" x-show="!isPlaying" x-transition.opacity.duration.200ms>
                            <svg class="w-10 h-10 text-white/80" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                        </div>
                    </div>

                    {{-- Client-side playAll overlay: renders ALL scenes, toggles visibility via JS --}}
                    <div x-ref="playAllContainer" class="absolute inset-0 z-10" style="display: none;" wire:ignore></div>

                    {{-- Fabric.js overlay canvas (on top of video, below play indicator) --}}
                    <div class="absolute inset-0 z-20 pointer-events-none" wire:ignore>
                        <canvas x-ref="overlayCanvas" class="w-full h-full pointer-events-auto"></canvas>
                    </div>

                    {{-- Server-rendered single-scene view (normal editing mode) --}}
                    <div x-ref="singleSceneContainer" class="absolute inset-0">
                    @if($this->activeScene)
                        {{-- Video clips rendered server-side to avoid Livewire morph issues with x-for --}}
                        @foreach($this->activeScene->clips as $clip)
                            @php
                                $isVideo = $clip->source instanceof \App\Models\VideoRecording;
                                // Use the stream endpoint (supports range requests required for HTML5 video)
                                $clipStreamUrl = $isVideo ? $clip->source->getStreamUrl() : null;
                                // Signed/presigned URL as fallback (better for S3 direct delivery)
                                $clipSignedUrl = $isVideo ? $clip->source->getVideoUrl() : null;
                                $clipThumb = $isVideo
                                    ? $clip->source->getThumbnailUrl()
                                    : ($clip->source?->url ?? null);
                                $hasPlayableVideo = $clipStreamUrl || $clipSignedUrl;
                                $slotClasses = match($this->activeScene->layout) {
                                    'split' => match($clip->slot) {
                                        'main' => 'absolute overflow-hidden left-0 top-0 w-1/2 h-full',
                                        'secondary' => 'absolute overflow-hidden right-0 top-0 w-1/2 h-full',
                                        default => 'absolute overflow-hidden inset-0',
                                    },
                                    'pip' => match($clip->slot) {
                                        'main' => 'absolute overflow-hidden inset-0',
                                        'pip' => 'absolute overflow-hidden right-[4%] bottom-[4%] w-1/4 h-1/4 rounded-lg shadow-lg z-10',
                                        default => 'absolute overflow-hidden inset-0',
                                    },
                                    default => 'absolute overflow-hidden inset-0',
                                };
                                $fitClass = match($clip->fit_mode) {
                                    'contain' => 'object-contain',
                                    'fill' => 'object-fill',
                                    default => 'object-cover',
                                };
                            @endphp
                            <div wire:key="preview-clip-{{ $clip->id }}" class="{{ $slotClasses }}">
                                @if($hasPlayableVideo)
                                    <video
                                        src="{{ $clipStreamUrl ?? $clipSignedUrl }}"
                                        @if($clipThumb) poster="{{ $clipThumb }}" @endif
                                        data-clip-id="{{ $clip->id }}"
                                        @if($clipSignedUrl && $clipStreamUrl) data-fallback-url="{{ $clipSignedUrl }}" @endif
                                        class="w-full h-full {{ $fitClass }}"
                                        muted
                                        playsinline
                                        preload="auto"
                                        x-on:error="handleVideoError($event)"
                                    ></video>
                                @elseif($clipThumb)
                                    <img src="{{ $clipThumb }}" class="w-full h-full object-cover" alt="" loading="lazy" />
                                @else
                                    <div class="w-full h-full flex items-center justify-center bg-zinc-900 text-zinc-500">
                                        <div class="text-center">
                                            <flux:icon name="exclamation-triangle" class="size-8 mx-auto mb-1" />
                                            <p class="text-xs">{{ __('Video unavailable') }}</p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        {{-- Overlays rendered server-side --}}
                        @foreach($this->activeScene->overlays as $overlay)
                            @php
                                $hasTimeBounds = $overlay->start_ms !== null || $overlay->end_ms !== null;
                                if ($hasTimeBounds) {
                                    $checks = [];
                                    if ($overlay->start_ms !== null) $checks[] = 'sceneTimeMs >= ' . $overlay->start_ms;
                                    if ($overlay->end_ms !== null) $checks[] = 'sceneTimeMs <= ' . $overlay->end_ms;
                                    $showExpr = implode(' && ', $checks);
                                } else {
                                    $showExpr = 'true';
                                }
                            @endphp
                            <div
                                wire:key="preview-overlay-{{ $overlay->id }}"
                                class="absolute cursor-grab active:cursor-grabbing z-20 select-none"
                                style="left: {{ $overlay->position_x * 100 }}%; top: {{ $overlay->position_y * 100 }}%; {{ $overlay->width ? 'width: ' . ($overlay->width * 100) . '%' : '' }}"
                                x-show="{{ $showExpr }}"
                                @mousedown.stop="startOverlayDrag($event, {{ $overlay->id }}, {{ $overlay->position_x }}, {{ $overlay->position_y }})"
                                @click.stop
                            >
                                @if(in_array($overlay->type, ['text', 'caption']))
                                    <span class="pointer-events-none inline-block {{ $overlay->width ? 'w-full text-center' : '' }}" style="
                                        font-size: {{ $overlay->style['font_size'] ?? 24 }}px;
                                        color: {{ $overlay->style['color'] ?? '#FFFFFF' }};
                                        font-weight: {{ $overlay->style['font_weight'] ?? 'normal' }};
                                        {{ isset($overlay->style['bg_color']) ? 'background-color: ' . $overlay->style['bg_color'] . '; padding: 4px 8px; border-radius: 4px;' : '' }}
                                    ">{{ $overlay->content }}</span>
                                @endif
                            </div>
                        @endforeach
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
            </div>

            {{-- Playback Controls --}}
            <div class="shrink-0 px-4 py-2 flex items-center gap-3 border-t border-zinc-800">
                <button @click="isPlaying ? pause() : playScene()" class="text-white hover:text-blue-400 transition-colors">
                    <svg x-show="!isPlaying" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                    <svg x-show="isPlaying" x-cloak class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M5.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75A.75.75 0 007.25 3h-1.5zM12.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75a.75.75 0 00-.75-.75h-1.5z"/></svg>
                </button>
                <span class="text-xs text-zinc-400 font-mono" x-text="formatTime(sceneTimeMs)"></span>
                <div class="flex-1 h-1 bg-zinc-800 rounded-full cursor-pointer" @click="seekToPercent($event)">
                    <div class="h-full bg-blue-500 rounded-full transition-all" :style="'width: ' + scenePlaybackPercent + '%'"></div>
                </div>
                <span class="text-xs text-zinc-400 font-mono" x-text="formatTime(sceneDurationMs)"></span>
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
                                                    <img src="{{ $clip->source->getThumbnailUrl() }}" class="w-full h-full object-cover" loading="lazy" />
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
                                        <div class="mt-2" x-data="{ clipVol: {{ $clip->volume }} }">
                                            <label class="text-[10px] text-zinc-500">{{ __('Volume') }}: <span x-text="clipVol + '%'"></span></label>
                                            <input
                                                type="range"
                                                min="0"
                                                max="200"
                                                x-model="clipVol"
                                                x-on:input="(() => { const v = document.querySelector('video[data-clip-id=\'{{ $clip->id }}\']'); if (v) v.volume = Math.min(1, parseInt($event.target.value) / 100); })()"
                                                x-on:change="$wire.updateClip({{ $clip->id }}, 'volume', parseInt($event.target.value))"
                                                class="w-full h-2 accent-blue-500 cursor-pointer"
                                            />
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
                            <flux:button size="sm" variant="ghost" @click="$wire.addOverlay('text', '', Math.round(getSceneLocalTimeMs()))" icon="plus" />
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

                                        {{-- Timing controls --}}
                                        <div class="grid grid-cols-2 gap-1.5 mt-1.5">
                                            <div>
                                                <label class="text-[10px] text-zinc-500">{{ __('Start (s)') }}</label>
                                                <flux:input
                                                    size="sm"
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    value="{{ $overlay->start_ms ? number_format($overlay->start_ms / 1000, 1) : '0.0' }}"
                                                    @blur="$wire.updateOverlay({{ $overlay->id }}, { start_ms: Math.round(parseFloat($event.target.value || 0) * 1000) })"
                                                />
                                            </div>
                                            <div>
                                                <label class="text-[10px] text-zinc-500">{{ __('End (s)') }}</label>
                                                <flux:input
                                                    size="sm"
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    value="{{ $overlay->end_ms ? number_format($overlay->end_ms / 1000, 1) : number_format($this->activeScene->getEffectiveDurationMs() / 1000, 1) }}"
                                                    @blur="$wire.updateOverlay({{ $overlay->id }}, { end_ms: Math.round(parseFloat($event.target.value || 0) * 1000) })"
                                                />
                                            </div>
                                        </div>
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

                                        {{-- Audio Preview --}}
                                        @if($voiceover->recording?->getVideoUrl())
                                            <div class="mb-1.5">
                                                <audio controls preload="metadata" class="w-full h-7 [&::-webkit-media-controls-panel]:h-7">
                                                    <source src="{{ $voiceover->recording->getVideoUrl() }}" type="{{ $voiceover->recording->mime_type ?? 'audio/webm' }}">
                                                </audio>
                                            </div>
                                        @endif

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
                            <flux:dropdown>
                                <flux:button size="sm" variant="ghost">
                                    <flux:icon name="language" class="size-4 mr-1" />
                                    {{ __('Generate') }}
                                    <flux:icon name="chevron-down" class="size-3 ml-1" />
                                </flux:button>
                                <flux:menu>
                                    <flux:menu.item wire:click="generateCaptions({{ $this->activeScene->id }}, 'video')" icon="video-camera">
                                        {{ __('From Video Audio') }}
                                    </flux:menu.item>
                                    @if($this->voiceovers->isNotEmpty())
                                        <flux:menu.item wire:click="generateCaptions({{ $this->activeScene->id }}, 'voiceover')" icon="microphone">
                                            {{ __('From Voiceover') }}
                                        </flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
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

    {{-- Timeline Editor --}}
    <div class="shrink-0 border-t border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900"
         x-ref="timelineRoot"
    >
        {{-- Scene Tabs --}}
        <div class="flex items-center gap-1 px-3 pt-2 pb-1 border-b border-zinc-100 dark:border-zinc-800 overflow-x-auto">
            @foreach($this->scenes as $scene)
                <button
                    wire:key="tl-scene-{{ $scene->id }}"
                    wire:click="selectScene({{ $scene->id }})"
                    class="shrink-0 rounded-t px-3 py-1 text-[11px] font-medium transition-colors {{ $activeSceneId === $scene->id ? 'bg-blue-500 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
                >
                    {{ $scene->name ?: __('Scene :n', ['n' => $loop->iteration]) }}
                    <span class="opacity-60 ml-1">{{ number_format($scene->getEffectiveDurationMs() / 1000, 1) }}s</span>
                </button>
            @endforeach
            @if($this->scenes->isNotEmpty())
                <span class="text-[10px] text-zinc-400 ml-auto shrink-0">
                    {{ __('Total') }}: {{ number_format($project->getComputedDurationMs() / 1000, 1) }}s
                </span>
            @endif
        </div>

        @if($this->activeScene)
            @php
                $sceneDurationMs = $this->activeScene->getEffectiveDurationMs();
                // Compute timeline duration from longest track
                $timelineDurationMs = $sceneDurationMs;
                foreach ($this->voiceovers as $vo) {
                    $voEndMs = ($vo->start_ms ?? 0) + $vo->getDurationMs();
                    $timelineDurationMs = max($timelineDurationMs, $voEndMs);
                }
                foreach ($this->activeScene->clips as $clip) {
                    $clipEndMs = ($clip->in_point_ms ?? 0) + $clip->getDurationMs();
                    $timelineDurationMs = max($timelineDurationMs, $clipEndMs);
                }
                foreach ($this->activeScene->overlays as $overlay) {
                    $timelineDurationMs = max($timelineDurationMs, $overlay->end_ms ?? 0);
                }
                $sceneDurationSec = $timelineDurationMs / 1000;
            @endphp

            <div class="overflow-x-auto mx-3 mt-1" x-ref="tracksWrapper" @wheel.prevent="handleTimelineScroll($event)">
            <div :style="'width: ' + (timelineZoom * 100) + '%'">
            {{-- Time Ruler --}}
            <div class="timeline-ruler relative h-5" x-ref="timelineRuler">
                <div class="absolute inset-0 flex items-end" @click="seekOnTimeline($event)">
                    @for($t = 0; $t <= $sceneDurationSec; $t++)
                        <div class="absolute bottom-0 flex flex-col items-center" style="left: {{ $timelineDurationMs > 0 ? ($t * 1000 / $timelineDurationMs) * 100 : 0 }}%">
                            <span class="text-[9px] text-zinc-400 leading-none mb-0.5">{{ $t }}s</span>
                            <div class="w-px h-1.5 bg-zinc-300 dark:bg-zinc-600"></div>
                        </div>
                    @endfor
                </div>
                {{-- Playhead --}}
                <div class="timeline-playhead absolute top-0 bottom-0 w-px bg-red-500 z-30 pointer-events-none transition-[left] duration-75"
                     :style="'left: ' + getScenePlayheadPercent() + '%'">
                    <div class="absolute -top-0.5 -left-1 w-2 h-2 bg-red-500 rounded-full"></div>
                </div>
            </div>

            {{-- Tracks Container (Alpine-rendered, wire:ignore to prevent Livewire morphs) --}}
            <div class="mb-1 space-y-1" x-ref="tracksContainer" wire:ignore @click.self="deselectTimelineItem()">
                {{-- Video Track --}}
                <div class="flex items-center h-10">
                    <span class="w-16 shrink-0 text-[10px] font-medium text-zinc-400 uppercase tracking-wider pr-2 text-right">{{ __('Video') }}</span>
                    <div class="flex-1 relative h-full bg-zinc-100 dark:bg-zinc-800 rounded" @click.self="deselectTimelineItem(); seekOnTimeline($event)">
                        <template x-for="clip in getActiveSceneClips()" :key="'tl-clip-' + clip.id">
                            <div
                                class="absolute top-1 bottom-1 rounded-md text-white flex items-center text-[10px] truncate cursor-grab select-none touch-none"
                                :class="isSelected('clip', clip.id) ? 'bg-blue-600 ring-2 ring-blue-300 ring-offset-1 shadow-lg z-20' : 'bg-blue-500 hover:bg-blue-600 z-10'"
                                :style="'left: ' + getClipLeft(clip) + '%; width: ' + getClipWidth(clip) + '%;'"
                                @mousedown.stop="startDrag($event, 'clip', clip.id)"
                                @click.stop="selectTimelineItem('clip', clip.id, clip.in_point_ms || 0, getClipOutMs(clip))"
                            >
                                {{-- Left trim handle --}}
                                <div class="absolute left-0 top-0 bottom-0 w-2 cursor-col-resize bg-white/30 hover:bg-white/60 rounded-l-md z-30" @mousedown.stop="startTrim($event, 'clip', clip.id, 'left')"></div>
                                {{-- Content --}}
                                <div class="flex items-center px-3 min-w-0 pointer-events-none">
                                    <svg class="size-3 mr-1 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M3.25 4A2.25 2.25 0 0 0 1 6.25v7.5A2.25 2.25 0 0 0 3.25 16h7.5A2.25 2.25 0 0 0 13 13.75v-7.5A2.25 2.25 0 0 0 10.75 4h-7.5ZM19 4.75a.75.75 0 0 0-1.28-.53l-3 3a.75.75 0 0 0-.22.53v4.5c0 .199.079.39.22.53l3 3a.75.75 0 0 0 1.28-.53V4.75Z"/></svg>
                                    <span class="truncate" x-text="getClipLabel(clip)"></span>
                                    <span class="ml-auto text-[9px] opacity-60 tabular-nums shrink-0 pl-1" x-text="(getClipDurationMs(clip) / 1000).toFixed(1) + 's'"></span>
                                </div>
                                {{-- Right trim handle --}}
                                <div class="absolute right-0 top-0 bottom-0 w-2 cursor-col-resize bg-white/30 hover:bg-white/60 rounded-r-md z-30" @mousedown.stop="startTrim($event, 'clip', clip.id, 'right')"></div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Text Overlays Track --}}
                <template x-if="getActiveSceneOverlays('text').length > 0">
                    <div class="flex items-center h-10">
                        <span class="w-16 shrink-0 text-[10px] font-medium text-zinc-400 uppercase tracking-wider pr-2 text-right">{{ __('Text') }}</span>
                        <div class="flex-1 relative h-full bg-zinc-100 dark:bg-zinc-800 rounded" @click.self="deselectTimelineItem(); seekOnTimeline($event)">
                            <template x-for="overlay in getActiveSceneOverlays('text')" :key="'tl-text-' + overlay.id">
                                <div
                                    class="absolute top-1 bottom-1 rounded-md text-white flex items-center px-2 text-[10px] truncate cursor-pointer select-none transition-all"
                                    :class="isSelected('overlay', overlay.id) ? 'bg-amber-600 ring-2 ring-amber-300 ring-offset-1 shadow-lg z-20' : 'bg-amber-500 hover:bg-amber-600 z-10'"
                                    :style="'left: ' + getOverlayLeft(overlay) + '%; width: ' + getOverlayWidth(overlay) + '%;'"
                                    @click.stop="selectTimelineItem('overlay', overlay.id, overlay.start_ms || 0, overlay.end_ms || getTimelineDurationMs())"
                                >
                                    <span class="truncate" x-text="(overlay.content || '').substring(0, 20)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Caption Track --}}
                <template x-if="getActiveSceneOverlays('caption').length > 0">
                    <div class="flex items-center h-10">
                        <span class="w-16 shrink-0 text-[10px] font-medium text-zinc-400 uppercase tracking-wider pr-2 text-right">{{ __('Captions') }}</span>
                        <div class="flex-1 relative h-full bg-zinc-100 dark:bg-zinc-800 rounded" @click.self="deselectTimelineItem(); seekOnTimeline($event)">
                            <template x-for="overlay in getActiveSceneOverlays('caption')" :key="'tl-cap-' + overlay.id">
                                <div
                                    class="absolute top-1 bottom-1 rounded-md text-white flex items-center px-2 text-[10px] truncate cursor-pointer select-none transition-all"
                                    :class="isSelected('overlay', overlay.id) ? 'bg-cyan-600 ring-2 ring-cyan-300 ring-offset-1 shadow-lg z-20' : 'bg-cyan-500 hover:bg-cyan-600 z-10'"
                                    :style="'left: ' + getOverlayLeft(overlay) + '%; width: ' + getOverlayWidth(overlay) + '%;'"
                                    @click.stop="selectTimelineItem('overlay', overlay.id, overlay.start_ms || 0, overlay.end_ms || getTimelineDurationMs())"
                                >
                                    <span class="truncate" x-text="(overlay.content || '').substring(0, 20)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Voiceover Track --}}
                <template x-if="(projectData?.voiceovers || []).length > 0">
                    <div class="flex items-center h-10">
                        <span class="w-16 shrink-0 text-[10px] font-medium text-zinc-400 uppercase tracking-wider pr-2 text-right">{{ __('Audio') }}</span>
                        <div class="flex-1 relative h-full bg-zinc-100 dark:bg-zinc-800 rounded" @click.self="deselectTimelineItem(); seekOnTimeline($event)">
                            <template x-for="(vo, voIdx) in (projectData?.voiceovers || [])" :key="'tl-vo-' + vo.id">
                                <div
                                    class="absolute top-1 bottom-1 rounded-md text-white flex items-center text-[10px] truncate cursor-grab select-none touch-none"
                                    :class="isSelected('voiceover', vo.id) ? 'bg-purple-600 ring-2 ring-purple-300 ring-offset-1 shadow-lg z-20' : 'bg-purple-500 hover:bg-purple-600 z-10'"
                                    :style="'left: ' + getVoiceoverLeft(vo) + '%; width: ' + getVoiceoverWidth(vo) + '%;'"
                                    @mousedown.stop="startDrag($event, 'voiceover', vo.id)"
                                    @click.stop="selectTimelineItem('voiceover', vo.id, vo.in_point_ms || 0, getVoiceoverOutMs(vo), vo.start_ms || 0)"
                                >
                                    {{-- Left trim handle --}}
                                    <div class="absolute left-0 top-0 bottom-0 w-2 cursor-col-resize bg-white/30 hover:bg-white/60 rounded-l-md z-30" @mousedown.stop="startTrim($event, 'voiceover', vo.id, 'left')"></div>
                                    {{-- Content --}}
                                    <div class="flex items-center px-3 min-w-0 pointer-events-none">
                                        <svg class="size-3 mr-1 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M7 4a3 3 0 0 1 6 0v6a3 3 0 1 1-6 0V4Z"/><path d="M5.5 9.643a.75.75 0 0 0-1.5 0V10c0 3.06 2.29 5.585 5.25 5.954V17.5h-1.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-1.5v-1.546A6.001 6.001 0 0 0 16 10v-.357a.75.75 0 0 0-1.5 0V10a4.5 4.5 0 0 1-9 0v-.357Z"/></svg>
                                        <span class="truncate" x-text="'Voiceover ' + (voIdx + 1)"></span>
                                        <span class="ml-auto text-[9px] opacity-60 tabular-nums shrink-0 pl-1" x-text="(getVoiceoverDurationMs(vo) / 1000).toFixed(1) + 's'"></span>
                                    </div>
                                    {{-- Right trim handle --}}
                                    <div class="absolute right-0 top-0 bottom-0 w-2 cursor-col-resize bg-white/30 hover:bg-white/60 rounded-r-md z-30" @mousedown.stop="startTrim($event, 'voiceover', vo.id, 'right')"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            </div>{{-- zoom inner --}}
            </div>{{-- zoom wrapper --}}

            {{-- Selected Item Editor (Alpine-reactive, no DOM hacks) --}}
            <div x-show="selectedItem" x-cloak wire:ignore class="mx-3 mb-2 p-2.5 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-2">
                {{-- Row 1: Trim controls --}}
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                          :class="{
                              'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300': selectedItem?.type === 'clip',
                              'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300': selectedItem?.type === 'voiceover',
                              'bg-cyan-100 text-cyan-700 dark:bg-cyan-900 dark:text-cyan-300': selectedItem?.type === 'overlay'
                          }"
                          x-text="selectedItem?.type === 'clip' ? '{{ __('Video Clip') }}' : (selectedItem?.type === 'voiceover' ? '{{ __('Voiceover') }}' : '{{ __('Overlay') }}')"
                    ></span>

                    <div class="flex items-center gap-1.5">
                        <label class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('In') }}</label>
                        <input type="number" min="0" step="100" x-model.number="editInMs"
                               class="w-20 h-6 text-[11px] px-1.5 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 tabular-nums text-center" />
                        <span class="text-[9px] text-zinc-400">ms</span>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <label class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Out') }}</label>
                        <input type="number" min="0" step="100" x-model.number="editOutMs"
                               class="w-20 h-6 text-[11px] px-1.5 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 tabular-nums text-center" />
                        <span class="text-[9px] text-zinc-400">ms</span>
                    </div>

                    <div x-show="selectedItem?.type === 'voiceover'" class="flex items-center gap-1.5">
                        <label class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Start at') }}</label>
                        <input type="number" min="0" step="100" x-model.number="editStartMs"
                               class="w-20 h-6 text-[11px] px-1.5 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 tabular-nums text-center" />
                        <span class="text-[9px] text-zinc-400">ms</span>
                    </div>

                    <span class="text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 tabular-nums"
                          x-text="((editOutMs - editInMs) / 1000).toFixed(1) + 's'"></span>

                    <div class="flex items-center gap-1.5 ml-auto">
                        <flux:button size="xs" variant="primary" @click="applyTrimLocal()">
                            {{ __('Apply') }}
                        </flux:button>
                        <flux:button size="xs" variant="ghost" @click="deselectTimelineItem()">
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </div>

                {{-- Row 2: Split, Merge & Delete actions --}}
                <div class="flex items-center gap-3 flex-wrap border-t border-zinc-200 dark:border-zinc-700 pt-2">
                    <div x-show="selectedItem?.type === 'clip' || selectedItem?.type === 'voiceover'" class="flex items-center gap-1.5">
                        <label class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Split at') }}</label>
                        <input type="number" step="100" x-model.number="editSplitMs"
                               :min="editInMs + 1" :max="editOutMs - 1"
                               class="w-20 h-6 text-[11px] px-1.5 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 tabular-nums text-center" />
                        <span class="text-[9px] text-zinc-400">ms</span>
                        <flux:button size="xs" variant="ghost" @click="splitSelectedItemLocal()" class="!text-orange-600 dark:!text-orange-400">
                            <flux:icon name="scissors" class="size-3.5" />
                            {{ __('Split') }}
                        </flux:button>
                    </div>

                    <template x-if="canMergeSelected()">
                        <flux:button size="xs" variant="ghost" @click="mergeSelectedLocal()" class="!text-green-600 dark:!text-green-400">
                            <flux:icon name="link" class="size-3.5" />
                            {{ __('Merge') }}
                        </flux:button>
                    </template>

                    <div class="ml-auto">
                        <flux:button size="xs" variant="ghost" @click="if(confirm('{{ __('Delete this item?') }}')) deleteItemLocal()" class="!text-red-600 dark:!text-red-400">
                            <flux:icon name="trash" class="size-3.5" />
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </div>

                {{-- Saving indicator --}}
                <div x-show="saveTimerActive" x-cloak class="text-[10px] text-zinc-400 text-right">
                    {{ __('Saving...') }}
                </div>
            </div>

            {{-- Zoom & Undo/Redo Controls --}}
            <div class="flex items-center gap-2 px-3 py-1.5 border-t border-zinc-100 dark:border-zinc-800">
                <button @click="undo()" :disabled="undoStack.length === 0" class="text-[10px] font-medium transition-colors" :class="undoStack.length > 0 ? 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' : 'text-zinc-300 dark:text-zinc-600 cursor-not-allowed'" title="{{ __('Undo (Ctrl+Z)') }}">
                    <flux:icon name="arrow-uturn-left" class="size-3.5" />
                </button>
                <button @click="redo()" :disabled="redoStack.length === 0" class="text-[10px] font-medium transition-colors" :class="redoStack.length > 0 ? 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' : 'text-zinc-300 dark:text-zinc-600 cursor-not-allowed'" title="{{ __('Redo (Ctrl+Shift+Z)') }}">
                    <flux:icon name="arrow-uturn-right" class="size-3.5" />
                </button>
                <div class="w-px h-4 bg-zinc-200 dark:bg-zinc-700"></div>
                <button @click="timelineZoom = 1; $refs.tracksWrapper.scrollLeft = 0" class="text-[10px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 font-medium">
                    {{ __('Fit') }}
                </button>
                <flux:icon name="minus" class="size-3 text-zinc-400" />
                <input
                    type="range"
                    min="1"
                    max="10"
                    step="0.5"
                    x-model.number="timelineZoom"
                    class="w-24 h-1 accent-blue-500"
                />
                <flux:icon name="plus" class="size-3 text-zinc-400" />
                <span class="text-[10px] text-zinc-400 tabular-nums" x-text="timelineZoom + 'x'"></span>
            </div>
        @else
            <div class="px-4 py-3 text-center text-xs text-zinc-400">{{ __('Select a scene to see timeline') }}</div>
        @endif
    </div>

    {{-- Media Picker Modal --}}
    <flux:modal wire:model="showMediaPicker" class="max-w-4xl">
        <div class="p-4">
            <flux:heading size="lg" class="mb-4">{{ __('Select Media') }}</flux:heading>

            {{-- Tab bar --}}
            <div class="flex gap-2 mb-4 border-b border-zinc-200 dark:border-zinc-700 pb-3">
                @foreach(['my_videos' => ['video-camera', 'My Videos'], 'job_videos' => ['briefcase', 'Job Videos'], 'photos' => ['photo', 'Photos']] as $tab => [$icon, $label])
                    <flux:button
                        size="sm"
                        :variant="$mediaTab === $tab ? 'filled' : 'ghost'"
                        icon="{{ $icon }}"
                        wire:click="$set('mediaTab', '{{ $tab }}')"
                    >
                        {{ __($label) }}
                    </flux:button>
                @endforeach
            </div>

            {{-- Search --}}
            <div class="mb-4">
                <flux:input wire:model.live.debounce.300ms="mediaSearch" placeholder="{{ __('Search...') }}" icon="magnifying-glass" />
            </div>

            {{-- Content --}}
            <div class="min-h-[300px] max-h-[60vh] overflow-y-auto">
                @if($mediaTab === 'my_videos')
                    @if($this->pickerVideos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->pickerVideos as $video)
                                <button wire:key="pv-{{ $video->id }}" type="button" wire:click="selectMedia('video-recording', {{ $video->id }})" class="group relative rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-blue-500 transition-colors text-left">
                                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                        @if($video->getThumbnailUrl())
                                            <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" loading="lazy" />
                                        @else
                                            <flux:icon name="video-camera" class="size-8 text-zinc-400" />
                                        @endif
                                    </div>
                                    <div class="p-2">
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 truncate">{{ $video->title ?: __('Untitled') }}</p>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500">
                            <flux:icon name="video-camera" class="size-10 mb-2" />
                            <p>{{ __('No videos found.') }}</p>
                        </div>
                    @endif

                @elseif($mediaTab === 'job_videos')
                    @if($this->pickerJobVideos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->pickerJobVideos as $video)
                                <button wire:key="jv-{{ $video->id }}" type="button" wire:click="selectMedia('video-recording', {{ $video->id }})" class="group relative rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-blue-500 transition-colors text-left">
                                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                        @if($video->getThumbnailUrl())
                                            <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" loading="lazy" />
                                        @else
                                            <flux:icon name="video-camera" class="size-8 text-zinc-400" />
                                        @endif
                                    </div>
                                    <div class="p-2">
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 truncate">{{ $video->title ?: __('Untitled') }}</p>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500">
                            <flux:icon name="briefcase" class="size-10 mb-2" />
                            <p>{{ __('No job videos found.') }}</p>
                        </div>
                    @endif

                @elseif($mediaTab === 'photos')
                    @if($this->pickerPhotos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->pickerPhotos as $photo)
                                <button wire:key="pp-{{ $photo->id }}" type="button" wire:click="selectMedia('property-photo', {{ $photo->id }})" class="group relative rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-blue-500 transition-colors text-left">
                                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                        @if($photo->url)
                                            <img src="{{ $photo->url }}" alt="{{ $photo->caption }}" class="w-full h-full object-cover" loading="lazy" />
                                        @else
                                            <flux:icon name="photo" class="size-8 text-zinc-400" />
                                        @endif
                                    </div>
                                    <div class="p-2">
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 truncate">{{ $photo->caption ?: __('Untitled') }}</p>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500">
                            <flux:icon name="photo" class="size-10 mb-2" />
                            <p>{{ __('No photos found.') }}</p>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </flux:modal>

    @assets
    @vite('resources/js/studio-editor.js')
    @endassets
</div>
