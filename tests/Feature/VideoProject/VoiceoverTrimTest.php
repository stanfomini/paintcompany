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
