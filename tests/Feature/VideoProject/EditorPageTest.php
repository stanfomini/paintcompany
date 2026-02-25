<?php

use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from studio', function () {
    $this->get(route('studio'))
        ->assertRedirect();
});

it('shows project list page for internal users', function () {
    $user = User::factory()->create(['type' => 'internal']);

    $this->actingAs($user)
        ->get(route('studio'))
        ->assertSuccessful();
});

it('shows project list page for agent users', function () {
    $user = User::factory()->create(['type' => 'agent']);

    $this->actingAs($user)
        ->get(route('studio'))
        ->assertSuccessful();
});

it('can create a new project from project list', function () {
    $user = User::factory()->create(['type' => 'internal']);

    \Livewire\Livewire::actingAs($user)
        ->test('studio.project-list')
        ->call('createProject');

    expect(VideoProject::where('user_id', $user->id)->count())->toBe(1);
});

it('can delete own project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    \Livewire\Livewire::actingAs($user)
        ->test('studio.project-list')
        ->call('deleteProject', $project->id);

    expect(VideoProject::count())->toBe(0);
});

it('cannot delete other users project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $otherProject = VideoProject::factory()->create();

    \Livewire\Livewire::actingAs($user)
        ->test('studio.project-list')
        ->call('deleteProject', $otherProject->id);

    expect(VideoProject::count())->toBe(1);
});

it('shows editor page for project owner', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('studio.edit', $project))
        ->assertSuccessful();
});

it('denies editor access to non-owner', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create();

    $this->actingAs($user)
        ->get(route('studio.edit', $project))
        ->assertForbidden();
});

it('shows search results in project list', function () {
    $user = User::factory()->create(['type' => 'internal']);
    VideoProject::factory()->create(['user_id' => $user->id, 'title' => 'Customer Testimonial']);
    VideoProject::factory()->create(['user_id' => $user->id, 'title' => 'Property Walkthrough']);

    \Livewire\Livewire::actingAs($user)
        ->test('studio.project-list')
        ->set('search', 'Testimonial')
        ->assertSee('Customer Testimonial')
        ->assertDontSee('Property Walkthrough');
});
