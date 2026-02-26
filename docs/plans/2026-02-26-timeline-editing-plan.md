# Timeline Editing Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add non-destructive trimming, zoom, drag-to-reposition, and voiceover captioning to the Studio Editor timeline.

**Architecture:** Extend existing timeline with resize handles on video clips and voiceovers (same pattern as text/caption overlays). Add zoom via CSS transform + horizontal scroll. Compute timeline width from longest track. Add caption source selection for voiceover transcription.

**Tech Stack:** Livewire 4 SFC, Alpine.js, Laravel migrations, Pest tests

---

### Task 1: Migration — Add trim fields to voiceovers

**Files:**
- Create: `database/migrations/2026_02_26_XXXXXX_add_trim_fields_to_video_project_voiceovers_table.php`

**Step 1: Create migration**

```bash
php artisan make:migration add_trim_fields_to_video_project_voiceovers_table --no-interaction
```

**Step 2: Write migration code**

```php
public function up(): void
{
    Schema::table('video_project_voiceovers', function (Blueprint $table) {
        $table->unsignedInteger('in_point_ms')->default(0)->after('start_ms');
        $table->unsignedInteger('out_point_ms')->nullable()->after('in_point_ms');
    });
}

public function down(): void
{
    Schema::table('video_project_voiceovers', function (Blueprint $table) {
        $table->dropColumn(['in_point_ms', 'out_point_ms']);
    });
}
```

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Commit**

```bash
git add database/migrations/*add_trim_fields_to_video_project_voiceovers*
git commit -m "feat: add in_point_ms and out_point_ms to video_project_voiceovers"
```

---

### Task 2: Update VideoProjectVoiceover model

**Files:**
- Modify: `app/Models/VideoProjectVoiceover.php`
- Modify: `database/factories/VideoProjectVoiceoverFactory.php`

**Step 1: Write the failing test**

Create test file `tests/Feature/VideoProject/VoiceoverTrimTest.php`:

```php
<?php

use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores trim points on voiceover', function () {
    $voiceover = VideoProjectVoiceover::factory()->create([
        'in_point_ms' => 1500,
        'out_point_ms' => 8000,
    ]);

    expect($voiceover->in_point_ms)->toBe(1500)
        ->and($voiceover->out_point_ms)->toBe(8000);
});

it('computes effective duration from trim points', function () {
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    $voiceover = VideoProjectVoiceover::factory()->create([
        'video_recording_id' => $recording->id,
        'in_point_ms' => 2000,
        'out_point_ms' => 7000,
    ]);

    expect($voiceover->getDurationMs())->toBe(5000);
});

it('falls back to recording duration minus in_point when no out_point', function () {
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    $voiceover = VideoProjectVoiceover::factory()->create([
        'video_recording_id' => $recording->id,
        'in_point_ms' => 3000,
        'out_point_ms' => null,
    ]);

    expect($voiceover->getDurationMs())->toBe(7000);
});

it('returns full recording duration with no trim', function () {
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 15]);
    $voiceover = VideoProjectVoiceover::factory()->create([
        'video_recording_id' => $recording->id,
        'in_point_ms' => 0,
        'out_point_ms' => null,
    ]);

    expect($voiceover->getDurationMs())->toBe(15000);
});

it('has withTrim factory state', function () {
    $voiceover = VideoProjectVoiceover::factory()->withTrim(500, 4000)->create();

    expect($voiceover->in_point_ms)->toBe(500)
        ->and($voiceover->out_point_ms)->toBe(4000);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/VideoProject/VoiceoverTrimTest.php
```

Expected: FAIL (methods don't exist yet)

**Step 3: Update model**

In `app/Models/VideoProjectVoiceover.php`, add `in_point_ms` and `out_point_ms` to `$fillable` and `casts()`, and add `getDurationMs()`:

```php
protected $fillable = [
    'video_project_id',
    'video_recording_id',
    'start_ms',
    'in_point_ms',
    'out_point_ms',
    'volume',
    'sort_order',
];

protected function casts(): array
{
    return [
        'start_ms' => 'integer',
        'in_point_ms' => 'integer',
        'out_point_ms' => 'integer',
        'volume' => 'integer',
        'sort_order' => 'integer',
    ];
}

/**
 * Get the effective duration of this voiceover in milliseconds.
 *
 * Uses out_point_ms if set, otherwise falls back to the recording's
 * duration. Returns the range from in_point_ms to the end point.
 */
public function getDurationMs(): int
{
    $inPoint = $this->in_point_ms ?? 0;

    if ($this->out_point_ms) {
        return $this->out_point_ms - $inPoint;
    }

    $recording = $this->recording;

    if ($recording && $recording->duration_seconds) {
        return (int) ($recording->duration_seconds * 1000) - $inPoint;
    }

    return 5000 - $inPoint;
}
```

**Step 4: Add `withTrim` factory state**

In `database/factories/VideoProjectVoiceoverFactory.php`, add:

```php
public function withTrim(int $in = 0, int $out = 5000): static
{
    return $this->state(fn () => [
        'in_point_ms' => $in,
        'out_point_ms' => $out,
    ]);
}
```

**Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/VideoProject/VoiceoverTrimTest.php
```

Expected: PASS

**Step 6: Commit**

```bash
git add app/Models/VideoProjectVoiceover.php database/factories/VideoProjectVoiceoverFactory.php tests/Feature/VideoProject/VoiceoverTrimTest.php
git commit -m "feat: add trim support to VideoProjectVoiceover model"
```

---

### Task 3: Update editor PHP — voiceover trim fields and projectData

**Files:**
- Modify: `resources/views/components/studio/⚡editor.blade.php` (PHP section, lines 254-265 and 391-400)

**Step 1: Write the failing test**

Create test file `tests/Feature/Studio/EditorTimelineTest.php`:

```php
<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectScene;
use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows updating voiceover in_point_ms', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $voiceover = VideoProjectVoiceover::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'in_point_ms', 2000);

    expect($voiceover->fresh()->in_point_ms)->toBe(2000);
});

it('allows updating voiceover out_point_ms', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $voiceover = VideoProjectVoiceover::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'out_point_ms', 5000);

    expect($voiceover->fresh()->out_point_ms)->toBe(5000);
});

it('includes voiceover trim fields in projectData', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'in_point_ms' => 1000,
        'out_point_ms' => 8000,
    ]);

    $component = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project]);

    $voiceovers = $component->viewData('__instance')->projectData['voiceovers'] ?? [];
    // Alternative: read from the component directly
    $pd = $component->get('projectData');
    $vo = $pd['voiceovers'][0] ?? null;

    expect($vo)->not->toBeNull()
        ->and($vo['in_point_ms'])->toBe(1000)
        ->and($vo['out_point_ms'])->toBe(8000);
});

it('allows updating clip in_point_ms via updateClip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = \App\Models\VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'in_point_ms' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'in_point_ms', 3000);

    expect($clip->fresh()->in_point_ms)->toBe(3000);
});

it('allows updating clip out_point_ms via updateClip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = \App\Models\VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'out_point_ms', 7000);

    expect($clip->fresh()->out_point_ms)->toBe(7000);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Studio/EditorTimelineTest.php
```

Expected: FAIL — `updateVoiceover` doesn't allow `in_point_ms`/`out_point_ms`, and `projectData` doesn't include them

**Step 3: Update the editor PHP section**

In `resources/views/components/studio/⚡editor.blade.php`:

1. **Line ~256** — Update `updateVoiceover()` allowed fields:

Change:
```php
$allowed = ['volume', 'start_ms', 'sort_order'];
```
To:
```php
$allowed = ['volume', 'start_ms', 'in_point_ms', 'out_point_ms', 'sort_order'];
```

2. **Lines ~391-400** — Update voiceover data in `projectData()` computed:

Change the voiceover mapping to include trim fields:
```php
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
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Studio/EditorTimelineTest.php
```

Expected: PASS

**Step 5: Commit**

```bash
git add resources/views/components/studio/⚡editor.blade.php tests/Feature/Studio/EditorTimelineTest.php
git commit -m "feat: editor PHP supports voiceover trim and includes trim in projectData"
```

---

### Task 4: Caption generation from voiceover source

**Files:**
- Modify: `app/Services/CaptionGenerationService.php`
- Modify: `app/Jobs/GenerateSceneCaptionsJob.php`
- Modify: `resources/views/components/studio/⚡editor.blade.php` (PHP section — `generateCaptions` method)

**Step 1: Write the failing test**

Add to `tests/Feature/Studio/EditorTimelineTest.php`:

```php
it('generates captions from voiceover source', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->withDuration(10000)->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    $voiceover = VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
    ]);

    // Mock the Python bridge to return transcription segments
    $this->mock(\App\Services\PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'segments' => [
                ['text' => 'Hello world', 'start' => 0.0, 'end' => 2.0],
                ['text' => 'This is a test', 'start' => 2.5, 'end' => 4.5],
            ],
        ]);
    });

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('generateCaptions', $scene->id, 'voiceover');

    $captions = $scene->overlays()->where('type', 'caption')->get();
    expect($captions)->toHaveCount(2)
        ->and($captions[0]->content)->toBe('Hello world')
        ->and($captions[1]->content)->toBe('This is a test');
});

it('generates captions from video source by default', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->withDuration(10000)->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    \App\Models\VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => 'video-recording',
        'source_id' => $recording->id,
    ]);

    $this->mock(\App\Services\PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'segments' => [
                ['text' => 'From video', 'start' => 0.0, 'end' => 3.0],
            ],
        ]);
    });

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('generateCaptions', $scene->id, 'video');

    $captions = $scene->overlays()->where('type', 'caption')->get();
    expect($captions)->toHaveCount(1)
        ->and($captions[0]->content)->toBe('From video');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="generates captions from"
```

**Step 3: Update CaptionGenerationService**

In `app/Services/CaptionGenerationService.php`, add a method for voiceover transcription:

```php
/**
 * Generate caption segments from a voiceover recording.
 *
 * @return array<int, array{text: string, start_ms: int, end_ms: int}>
 */
public function generateFromVoiceover(VideoProjectScene $scene): array
{
    $project = $scene->project;
    $voiceover = $project->voiceovers()->with('recording')->first();

    if (! $voiceover || ! $voiceover->recording?->getVideoUrl()) {
        return [];
    }

    try {
        $result = $this->python->transcribeAudio($voiceover->recording->getVideoUrl());
    } catch (\Exception $e) {
        report($e);
        return [];
    }

    if (! isset($result['segments'])) {
        return [];
    }

    $segments = [];
    $voOffset = $voiceover->start_ms ?? 0;
    $inPoint = $voiceover->in_point_ms ?? 0;

    foreach ($result['segments'] as $segment) {
        $segStartMs = (int) ($segment['start'] * 1000);
        $segEndMs = (int) ($segment['end'] * 1000);

        // Skip segments before the in_point trim
        if ($segEndMs <= $inPoint) {
            continue;
        }

        // Adjust for in_point trim and voiceover offset on timeline
        $startMs = $voOffset + max(0, $segStartMs - $inPoint);
        $endMs = $voOffset + ($segEndMs - $inPoint);

        // If out_point is set, skip segments beyond it
        if ($voiceover->out_point_ms && $segStartMs >= $voiceover->out_point_ms) {
            continue;
        }

        $segments[] = [
            'text' => trim($segment['text']),
            'start_ms' => $startMs,
            'end_ms' => $endMs,
        ];
    }

    return $segments;
}
```

**Step 4: Update GenerateSceneCaptionsJob**

In `app/Jobs/GenerateSceneCaptionsJob.php`, add `$source` parameter:

```php
public function __construct(
    public VideoProjectScene $scene,
    public string $source = 'video',
) {}

public function handle(CaptionGenerationService $service): void
{
    $segments = $this->source === 'voiceover'
        ? $service->generateFromVoiceover($this->scene)
        : $service->generateForScene($this->scene);

    // Rest stays the same...
}
```

**Step 5: Update editor `generateCaptions` method**

In `resources/views/components/studio/⚡editor.blade.php`, update the `generateCaptions` method (around line 219):

```php
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
```

**Step 6: Run tests**

```bash
php artisan test --compact --filter="generates captions from"
```

Expected: PASS

**Step 7: Commit**

```bash
git add app/Services/CaptionGenerationService.php app/Jobs/GenerateSceneCaptionsJob.php resources/views/components/studio/⚡editor.blade.php tests/Feature/Studio/EditorTimelineTest.php
git commit -m "feat: caption generation from voiceover audio source"
```

---

### Task 5: Timeline Blade — trim handles on clips and voiceovers

**Files:**
- Modify: `resources/views/components/studio/⚡editor.blade.php` (Blade template, timeline section lines ~990-1120)

**Step 1: Update video clip bars with trim handles and drag**

Replace the video track section (lines ~993-1013) with:

```blade
{{-- Video Track --}}
<div class="flex items-center h-7">
    <span class="w-16 shrink-0 text-[10px] font-medium text-zinc-400 uppercase tracking-wider pr-2 text-right">{{ __('Video') }}</span>
    <div class="flex-1 relative h-full bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden" @click="seekOnTimeline($event)">
        @foreach($this->activeScene->clips as $clip)
            @php
                $clipInMs = $clip->in_point_ms ?? 0;
                $clipDur = $clip->getDurationMs();
                $clipLeft = $timelineDurationMs > 0 ? ($clipInMs / $timelineDurationMs) * 100 : 0;
                $clipWidth = $timelineDurationMs > 0 ? ($clipDur / $timelineDurationMs) * 100 : 100;
                $totalSourceMs = ($clip->source?->duration_seconds ?? 5) * 1000;
            @endphp
            <div
                wire:key="tl-clip-{{ $clip->id }}"
                class="absolute top-0.5 bottom-0.5 rounded bg-blue-500/80 text-white flex items-center px-1.5 text-[10px] truncate group"
                style="left: {{ $clipLeft }}%; width: {{ min(100, $clipWidth) }}%;"
                title="{{ $clip->source?->title ?? __('Clip') }} ({{ number_format($clipDur / 1000, 1) }}s)"
            >
                {{-- Left trim handle --}}
                <div class="absolute left-0 top-0 bottom-0 w-1.5 cursor-col-resize bg-blue-700/60 opacity-0 group-hover:opacity-100 rounded-l z-10"
                     @mousedown.stop="startResizeClip($event, {{ $clip->id }}, 'left', {{ $clipInMs }}, {{ $clip->out_point_ms ?? 'null' }}, {{ $totalSourceMs }})"></div>
                {{-- Drag body --}}
                <div class="flex-1 cursor-grab active:cursor-grabbing truncate flex items-center"
                     @mousedown.stop="startDragClip($event, {{ $clip->id }}, {{ $clipInMs }}, {{ $clip->out_point_ms ?? 'null' }}, {{ $timelineDurationMs }})">
                    <flux:icon name="video-camera" class="size-3 mr-1 shrink-0 opacity-70" />
                    <span class="truncate">{{ $clip->source?->title ?? __('Clip') }}</span>
                </div>
                {{-- Right trim handle --}}
                <div class="absolute right-0 top-0 bottom-0 w-1.5 cursor-col-resize bg-blue-700/60 opacity-0 group-hover:opacity-100 rounded-r z-10"
                     @mousedown.stop="startResizeClip($event, {{ $clip->id }}, 'right', {{ $clipInMs }}, {{ $clip->out_point_ms ?? 'null' }}, {{ $totalSourceMs }})"></div>
            </div>
        @endforeach
    </div>
</div>
```

**Step 2: Update voiceover bars with trim handles**

Replace the voiceover track section (lines ~1088-1120) with:

```blade
{{-- Voiceover Track --}}
@if($this->voiceovers->isNotEmpty())
    <div class="flex items-center h-7">
        <span class="w-16 shrink-0 text-[10px] font-medium text-zinc-400 uppercase tracking-wider pr-2 text-right">{{ __('Audio') }}</span>
        <div class="flex-1 relative h-full bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden" @click="seekOnTimeline($event)">
            @foreach($this->voiceovers as $vo)
                @php
                    $voStart = $vo->start_ms ?? 0;
                    $voInPoint = $vo->in_point_ms ?? 0;
                    $voDur = $vo->getDurationMs();
                    $voLeft = $timelineDurationMs > 0 ? ($voStart / $timelineDurationMs) * 100 : 0;
                    $voWidth = $timelineDurationMs > 0 ? ($voDur / $timelineDurationMs) * 100 : 50;
                    $totalVoMs = ($vo->recording?->duration_seconds ?? 5) * 1000;
                @endphp
                <div
                    wire:key="tl-vo-{{ $vo->id }}"
                    class="absolute top-0.5 bottom-0.5 rounded bg-purple-500/80 text-white flex items-center px-1.5 text-[10px] truncate group"
                    style="left: {{ $voLeft }}%; width: {{ min(100 - $voLeft, $voWidth) }}%;"
                    title="{{ __('Voiceover') }} ({{ number_format($voDur / 1000, 1) }}s)"
                >
                    {{-- Left trim handle --}}
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 cursor-col-resize bg-purple-700/60 opacity-0 group-hover:opacity-100 rounded-l z-10"
                         @mousedown.stop="startResizeVoiceover($event, {{ $vo->id }}, 'left', {{ $voInPoint }}, {{ $vo->out_point_ms ?? 'null' }}, {{ $totalVoMs }}, {{ $voStart }})"></div>
                    {{-- Drag body (reposition) --}}
                    <div class="flex-1 cursor-grab active:cursor-grabbing truncate flex items-center"
                         @mousedown.stop="startDragVoiceover($event, {{ $vo->id }}, {{ $voStart }}, {{ $timelineDurationMs }})">
                        <flux:icon name="microphone" class="size-3 mr-1 shrink-0 opacity-70" />
                        <span class="truncate">{{ __('Voiceover :n', ['n' => $loop->iteration]) }}</span>
                    </div>
                    {{-- Right trim handle --}}
                    <div class="absolute right-0 top-0 bottom-0 w-1.5 cursor-col-resize bg-purple-700/60 opacity-0 group-hover:opacity-100 rounded-r z-10"
                         @mousedown.stop="startResizeVoiceover($event, {{ $vo->id }}, 'right', {{ $voInPoint }}, {{ $vo->out_point_ms ?? 'null' }}, {{ $totalVoMs }}, {{ $voStart }})"></div>
                    {{-- Delete button --}}
                    <button
                        wire:click="removeVoiceover({{ $vo->id }})"
                        wire:confirm="{{ __('Delete this voiceover?') }}"
                        class="ml-auto shrink-0 opacity-0 group-hover:opacity-100 hover:text-red-300 transition-opacity"
                        @mousedown.stop
                    >
                        <flux:icon name="x-mark" class="size-3" />
                    </button>
                </div>
            @endforeach
        </div>
    </div>
@endif
```

**Step 3: Compute timelineDurationMs from longest track**

Replace the `$sceneDurationMs` computation (lines ~968-971) with:

```blade
@php
    $sceneDurationMs = $this->activeScene->getEffectiveDurationMs();
    // Compute timeline duration from longest track
    $timelineDurationMs = $sceneDurationMs;
    // Check voiceover end points
    foreach ($this->voiceovers as $vo) {
        $voEndMs = ($vo->start_ms ?? 0) + $vo->getDurationMs();
        $timelineDurationMs = max($timelineDurationMs, $voEndMs);
    }
    // Check clip end points
    foreach ($this->activeScene->clips as $clip) {
        $clipEndMs = ($clip->in_point_ms ?? 0) + $clip->getDurationMs();
        $timelineDurationMs = max($timelineDurationMs, $clipEndMs);
    }
    // Check overlay end points
    foreach ($this->activeScene->overlays as $overlay) {
        $timelineDurationMs = max($timelineDurationMs, $overlay->end_ms ?? 0);
    }
    $sceneDurationSec = $timelineDurationMs / 1000;
@endphp
```

Update the time ruler to use `$timelineDurationMs` instead of `$sceneDurationMs` for tick positions, and update the text/caption overlay track sections to use `$timelineDurationMs` for their width calculations too.

**Step 4: Update caption controls UI — add source dropdown**

Replace the caption controls section (lines ~905-922) with:

```blade
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
```

**Step 5: Commit**

```bash
git add resources/views/components/studio/⚡editor.blade.php
git commit -m "feat: trim handles on clips/voiceovers, timeline duration from longest track, caption source dropdown"
```

---

### Task 6: Alpine JS — resize and drag methods for clips and voiceovers

**Files:**
- Modify: `resources/views/components/studio/⚡editor.blade.php` (Alpine JS section, after `startResize` method around line ~2627)

**Step 1: Add `startResizeClip` method**

Add this method to the Alpine component (before the closing `}));`):

```javascript
startResizeClip(event, clipId, side, inPointMs, outPointMs, totalSourceMs) {
    event.preventDefault();
    const track = event.target.closest('.relative');
    if (!track) return;

    const rect = track.getBoundingClientRect();
    const startX = event.clientX;
    const origIn = inPointMs || 0;
    const origOut = outPointMs || totalSourceMs;

    const onMove = (e) => {
        const deltaX = e.clientX - startX;
        const pxToMs = this.getTimelineDurationMs() / rect.width;
        const deltaMs = Math.round(deltaX * pxToMs);

        let newIn = origIn;
        let newOut = origOut;

        if (side === 'left') {
            newIn = Math.max(0, Math.min(origOut - 100, origIn + deltaMs));
        } else {
            newOut = Math.min(totalSourceMs, Math.max(origIn + 100, origOut + deltaMs));
        }

        const bar = event.target.closest('[wire\\:key]');
        if (bar) {
            const tlDur = this.getTimelineDurationMs();
            bar.style.left = ((newIn / tlDur) * 100) + '%';
            bar.style.width = (((newOut - newIn) / tlDur) * 100) + '%';
        }

        this.dragging = { clipId, inPointMs: newIn, outPointMs: newOut };
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);

        if (this.dragging && this.dragging.clipId) {
            this.$wire.updateClip(this.dragging.clipId, 'in_point_ms', this.dragging.inPointMs);
            this.$wire.updateClip(this.dragging.clipId, 'out_point_ms', this.dragging.outPointMs);
            this.dragging = null;
        }
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
},
```

**Step 2: Add `startResizeVoiceover` method**

```javascript
startResizeVoiceover(event, voiceoverId, side, inPointMs, outPointMs, totalSourceMs, startMs) {
    event.preventDefault();
    const track = event.target.closest('.relative');
    if (!track) return;

    const rect = track.getBoundingClientRect();
    const startX = event.clientX;
    const origIn = inPointMs || 0;
    const origOut = outPointMs || totalSourceMs;

    const onMove = (e) => {
        const deltaX = e.clientX - startX;
        const pxToMs = this.getTimelineDurationMs() / rect.width;
        const deltaMs = Math.round(deltaX * pxToMs);

        let newIn = origIn;
        let newOut = origOut;

        if (side === 'left') {
            newIn = Math.max(0, Math.min(origOut - 100, origIn + deltaMs));
        } else {
            newOut = Math.min(totalSourceMs, Math.max(origIn + 100, origOut + deltaMs));
        }

        const bar = event.target.closest('[wire\\:key]');
        if (bar) {
            const tlDur = this.getTimelineDurationMs();
            const effectiveDur = newOut - newIn;
            bar.style.left = ((startMs / tlDur) * 100) + '%';
            bar.style.width = ((effectiveDur / tlDur) * 100) + '%';
        }

        this.dragging = { voiceoverId, inPointMs: newIn, outPointMs: newOut };
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);

        if (this.dragging && this.dragging.voiceoverId && this.dragging.inPointMs !== undefined) {
            this.$wire.updateVoiceover(this.dragging.voiceoverId, 'in_point_ms', this.dragging.inPointMs);
            this.$wire.updateVoiceover(this.dragging.voiceoverId, 'out_point_ms', this.dragging.outPointMs);
            this.dragging = null;
        }
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
},
```

**Step 3: Add `startDragClip` method**

```javascript
startDragClip(event, clipId, inPointMs, outPointMs, timelineDurationMs) {
    event.preventDefault();
    const track = event.target.closest('.relative');
    if (!track) return;

    const rect = track.getBoundingClientRect();
    const startX = event.clientX;
    const origIn = inPointMs || 0;
    const duration = (outPointMs || timelineDurationMs) - origIn;

    const onMove = (e) => {
        const deltaX = e.clientX - startX;
        const pxToMs = timelineDurationMs / rect.width;
        const deltaMs = Math.round(deltaX * pxToMs);
        const newIn = Math.max(0, origIn + deltaMs);

        const bar = event.target.closest('[wire\\:key]');
        if (bar) {
            bar.style.left = ((newIn / timelineDurationMs) * 100) + '%';
        }

        this.dragging = { clipId, inPointMs: newIn, outPointMs: newIn + duration };
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);

        if (this.dragging && this.dragging.clipId) {
            this.$wire.updateClip(this.dragging.clipId, 'in_point_ms', this.dragging.inPointMs);
            if (this.dragging.outPointMs) {
                this.$wire.updateClip(this.dragging.clipId, 'out_point_ms', this.dragging.outPointMs);
            }
            this.dragging = null;
        }
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
},
```

**Step 4: Add `getTimelineDurationMs` helper**

```javascript
getTimelineDurationMs() {
    const scene = this.activeSceneData;
    if (!scene) return 5000;

    let maxMs = scene.duration_ms || 5000;

    // Check clips
    (scene.clips || []).forEach(c => {
        const clipEnd = (c.in_point_ms || 0) + (c.out_point_ms ? c.out_point_ms - (c.in_point_ms || 0) : (c.actual_duration_ms || 5000));
        maxMs = Math.max(maxMs, clipEnd);
    });

    // Check voiceovers
    (this.projectData?.voiceovers || []).forEach(vo => {
        const voStart = vo.start_ms || 0;
        const voIn = vo.in_point_ms || 0;
        const voOut = vo.out_point_ms || ((vo.duration_seconds || 5) * 1000);
        const voEnd = voStart + (voOut - voIn);
        maxMs = Math.max(maxMs, voEnd);
    });

    return maxMs;
},
```

**Step 5: Update playback to honor trim points**

In `playVoiceovers()` and `syncVoiceovers()`, account for `in_point_ms`/`out_point_ms`:

In `syncVoiceovers()`, update the time calculation:

```javascript
// Replace existing voStart/voDuration/voEnd calculation with:
const voStartMs = vo.start_ms || 0;
const voInPoint = vo.in_point_ms || 0;
const voOutPoint = vo.out_point_ms || ((vo.duration_seconds || 0) * 1000);
const voDurationMs = voOutPoint - voInPoint;
const voEndMs = voStartMs + voDurationMs;

if (this.currentTimeMs >= voStartMs && this.currentTimeMs <= voEndMs) {
    if (audio.paused) {
        // Offset into recording = in_point + elapsed time since voiceover started
        const offsetSec = (voInPoint + (this.currentTimeMs - voStartMs)) / 1000;
        audio.currentTime = offsetSec;
        audio.play().catch(() => {});
    }
} else {
    if (!audio.paused) {
        audio.pause();
    }
}
```

Apply the same in_point offset in `playVoiceovers()`:

```javascript
const voInPoint = vo.in_point_ms || 0;
const offsetSec = Math.max(0, (voInPoint + this.currentTimeMs - (vo.start_ms || 0)) / 1000);
```

**Step 6: Commit**

```bash
git add resources/views/components/studio/⚡editor.blade.php
git commit -m "feat: Alpine JS resize/drag methods for clips and voiceovers, playback honors trim"
```

---

### Task 7: Timeline zoom

**Files:**
- Modify: `resources/views/components/studio/⚡editor.blade.php` (Blade timeline + Alpine JS)

**Step 1: Add zoom state to Alpine**

In the Alpine data initialization (around line 1236), add:

```javascript
timelineZoom: 1,
timelineScrollLeft: 0,
```

**Step 2: Add zoom controls below timeline tracks**

After the tracks container closing `</div>` (around line 1121) and before the `@else` clause, add:

```blade
{{-- Zoom Controls --}}
<div class="flex items-center gap-2 px-3 py-1.5 border-t border-zinc-100 dark:border-zinc-800">
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
```

**Step 3: Wrap timeline ruler + tracks in a scrollable zoom container**

Wrap the ruler and tracks container in a scrollable wrapper:

```blade
<div class="overflow-x-auto" x-ref="tracksWrapper" @wheel.prevent="handleTimelineScroll($event)">
    <div :style="'width: ' + (timelineZoom * 100) + '%'">
        {{-- Time Ruler --}}
        ...existing ruler code...

        {{-- Tracks Container --}}
        ...existing tracks code...
    </div>
</div>
```

**Step 4: Add scroll-to-zoom handler in Alpine**

```javascript
handleTimelineScroll(event) {
    if (event.ctrlKey || event.metaKey) {
        // Zoom with Ctrl/Cmd + scroll
        const delta = event.deltaY > 0 ? -0.5 : 0.5;
        this.timelineZoom = Math.max(1, Math.min(10, this.timelineZoom + delta));
    } else {
        // Normal horizontal scroll
        const wrapper = this.$refs.tracksWrapper;
        if (wrapper) {
            wrapper.scrollLeft += event.deltaY;
        }
    }
},
```

**Step 5: Commit**

```bash
git add resources/views/components/studio/⚡editor.blade.php
git commit -m "feat: timeline zoom with slider, scroll wheel, and fit button"
```

---

### Task 8: Run all tests and fix any issues

**Step 1: Run the new tests**

```bash
php artisan test --compact tests/Feature/VideoProject/VoiceoverTrimTest.php tests/Feature/Studio/EditorTimelineTest.php
```

**Step 2: Run existing editor/studio tests**

```bash
php artisan test --compact tests/Feature/Studio/ tests/Feature/VideoProject/
```

**Step 3: Fix any failures, then commit**

```bash
git add -A
git commit -m "fix: resolve test failures from timeline editing changes"
```

**Step 4: Run Pint for code formatting**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "style: format code with Pint"
```
