<?php

use App\Models\StudioTemplate;
use App\Models\User;
use App\Models\VideoProject;

it('has studio fields', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create();

    $project = VideoProject::create([
        'user_id' => $user->id,
        'title' => 'Test',
        'status' => 'draft',
        'creation_mode' => 'quick',
        'aspect_ratio' => '9:16',
        'ai_prompt' => 'Make a cool video',
        'template_id' => $template->id,
    ]);

    expect($project->creation_mode)->toBe('quick');
    expect($project->aspect_ratio)->toBe('9:16');
    expect($project->ai_prompt)->toBe('Make a cool video');
    expect($project->template->id)->toBe($template->id);
    expect($project->isQuickMode())->toBeTrue();
    expect($project->isAiGenerated())->toBeFalse();
});

it('defaults to classic mode', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::create([
        'user_id' => $user->id,
        'title' => 'Test',
        'status' => 'draft',
    ]);
    $project->refresh();
    expect($project->creation_mode)->toBe('classic');
    expect($project->aspect_ratio)->toBe('16:9');
});
