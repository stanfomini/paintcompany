<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can add a scene to a project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('addScene', 'solo')
        ->assertHasNoErrors();

    expect($project->scenes()->count())->toBe(1);
    expect($project->scenes->first()->layout)->toBe('solo');
});

it('can add split and pip scenes', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('addScene', 'split')
        ->call('addScene', 'pip');

    $scenes = $project->scenes()->orderBy('sort_order')->get();
    expect($scenes)->toHaveCount(2);
    expect($scenes[0]->layout)->toBe('split');
    expect($scenes[1]->layout)->toBe('pip');
});

it('auto-increments scene sort order', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('addScene')
        ->call('addScene')
        ->call('addScene');

    $orders = $project->scenes()->orderBy('sort_order')->pluck('sort_order')->toArray();
    expect($orders)->toBe([0, 1, 2]);
});

it('can remove a scene', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeScene', $scene->id);

    expect($project->scenes()->count())->toBe(0);
});

it('selects next scene when active scene is removed', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene1 = VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 0]);
    $scene2 = VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->set('activeSceneId', $scene1->id)
        ->call('removeScene', $scene1->id)
        ->assertSet('activeSceneId', $scene2->id);
});

it('can update scene properties', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateScene', $scene->id, 'name', 'Intro Scene')
        ->call('updateScene', $scene->id, 'layout', 'split')
        ->call('updateScene', $scene->id, 'transition_type', 'fade');

    $scene->refresh();
    expect($scene->name)->toBe('Intro Scene');
    expect($scene->layout)->toBe('split');
    expect($scene->transition_type)->toBe('fade');
});

it('rejects invalid scene fields', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'name' => 'Original']);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateScene', $scene->id, 'user_id', 999);

    $scene->refresh();
    expect($scene->name)->toBe('Original');
});

it('can reorder scenes', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene1 = VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 0, 'name' => 'First']);
    $scene2 = VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 1, 'name' => 'Second']);
    $scene3 = VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 2, 'name' => 'Third']);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('reorderScenes', [$scene3->id, $scene1->id, $scene2->id]);

    expect($scene3->fresh()->sort_order)->toBe(0);
    expect($scene1->fresh()->sort_order)->toBe(1);
    expect($scene2->fresh()->sort_order)->toBe(2);
});

it('can update project title', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'title' => 'Old Title']);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateTitle', 'New Title');

    expect($project->fresh()->title)->toBe('New Title');
});

it('provides project data as computed', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'layout' => 'solo']);

    $component = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project]);

    $projectData = $component->get('projectData');
    expect($projectData)->toBeArray()
        ->and($projectData['id'])->toBe($project->id)
        ->and($projectData['scenes'])->toHaveCount(1)
        ->and($projectData['scenes'][0]['layout'])->toBe('solo');
});
