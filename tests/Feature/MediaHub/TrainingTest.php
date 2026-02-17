<?php

use App\Models\TrainingPath;
use App\Models\TrainingPathStep;
use App\Models\TrainingProgress;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================
// Training List (Admin Tab)
// =========================================================

it('shows training list for users with manage_training permission', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['name' => 'New Hire Onboarding']);
    TrainingPathStep::factory()->count(3)->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->assertSeeHtml('data-training-path="New Hire Onboarding"')
        ->assertSee('3 steps')
        ->assertOk();
});

it('denies access to training list without permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->assertForbidden();
});

it('can create a training path from the list', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->set('newPathName', 'Safety Training')
        ->set('newPathDescription', 'Important safety procedures')
        ->set('newPathVisibility', 'employees')
        ->set('newPathRequired', true)
        ->call('createPath')
        ->assertSet('showCreateModal', false);

    expect(TrainingPath::where('name', 'Safety Training')->first())
        ->not->toBeNull()
        ->visibility->toBe('employees')
        ->is_required->toBeTrue();
});

it('validates required name when creating path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->set('newPathName', '')
        ->call('createPath')
        ->assertHasErrors('newPathName');
});

it('can toggle training path active status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->call('toggleActive', $path->id);

    expect($path->fresh()->is_active)->toBeFalse();
});

it('can delete a training path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->call('deletePath', $path->id);

    expect(TrainingPath::find($path->id))->toBeNull();
});

it('shows required badge for required paths', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    TrainingPath::factory()->required()->create(['name' => 'Mandatory Safety']);

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->assertSee('Required')
        ->assertSeeHtml('data-training-path="Mandatory Safety"');
});

it('shows inactive badge for inactive paths', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    TrainingPath::factory()->inactive()->create(['name' => 'Old Training']);

    Livewire::actingAs($user)
        ->test('media.training-list')
        ->assertSee('Inactive');
});

// =========================================================
// Training Path Editor
// =========================================================

it('loads training path details in editor', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create([
        'name' => 'Painting Basics',
        'description' => 'Learn the basics',
        'visibility' => 'employees',
    ]);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->assertSet('name', 'Painting Basics')
        ->assertSet('description', 'Learn the basics')
        ->assertSet('visibility', 'employees');
});

it('can save training path details', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->set('name', 'Updated Name')
        ->set('description', 'New description')
        ->set('visibility', 'crews')
        ->set('isRequired', true)
        ->call('saveDetails');

    expect($path->fresh())
        ->name->toBe('Updated Name')
        ->description->toBe('New description')
        ->visibility->toBe('crews')
        ->is_required->toBeTrue();
});

it('can add a video step to training path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $video = VideoRecording::factory()->ready()->create(['user_id' => $user->id, 'title' => 'How to Paint']);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->call('addStep', $video->id);

    expect($path->steps()->count())->toBe(1);
    expect($path->steps()->first())
        ->video_recording_id->toBe($video->id)
        ->title->toBe('How to Paint')
        ->is_required->toBeTrue();
});

it('excludes already-added videos from available list', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $addedVideo = VideoRecording::factory()->ready()->create(['user_id' => $user->id, 'title' => 'Already Added']);
    $availableVideo = VideoRecording::factory()->ready()->create(['user_id' => $user->id, 'title' => 'Available']);

    TrainingPathStep::factory()->create([
        'training_path_id' => $path->id,
        'video_recording_id' => $addedVideo->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path]);

    $availableVideos = $component->get('availableVideos');
    expect($availableVideos->pluck('id')->toArray())->toContain($availableVideo->id);
    expect($availableVideos->pluck('id')->toArray())->not->toContain($addedVideo->id);
});

it('can remove a step from training path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->call('removeStep', $step->id);

    expect(TrainingPathStep::find($step->id))->toBeNull();
});

it('can toggle step required status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create([
        'training_path_id' => $path->id,
        'is_required' => true,
    ]);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->call('toggleStepRequired', $step->id);

    expect($step->fresh()->is_required)->toBeFalse();
});

it('can reorder steps up', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 0]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->call('moveStepUp', $step2->id);

    // step2 should now have lower sort_order than step1
    expect($step2->fresh()->sort_order)->toBeLessThan($step1->fresh()->sort_order);
});

it('can reorder steps down', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 0]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->call('moveStepDown', $step1->id);

    expect($step1->fresh()->sort_order)->toBeGreaterThan($step2->fresh()->sort_order);
});

it('can update step title', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create([
        'training_path_id' => $path->id,
        'title' => 'Old Title',
    ]);

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->call('updateStepTitle', $step->id, 'New Title');

    expect($step->fresh()->title)->toBe('New Title');
});

it('denies access to training editor without permission', function () {
    $user = User::factory()->internal()->create();
    $path = TrainingPath::factory()->create();

    Livewire::actingAs($user)
        ->test('media.training-manage', ['trainingPath' => $path])
        ->assertForbidden();
});

// =========================================================
// Training Dashboard
// =========================================================

it('shows training dashboard with completion matrix', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create(['name' => 'Test Employee']);
    $path = TrainingPath::factory()->create(['name' => 'Onboarding']);
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'title' => 'Step One']);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'title' => 'Step Two']);

    TrainingProgress::factory()->completed()->create([
        'user_id' => $employee->id,
        'training_path_step_id' => $step1->id,
    ]);

    Livewire::actingAs($user)
        ->test('media.training-dashboard')
        ->assertSee('Onboarding')
        ->assertSee('Test Employee')
        ->assertSee('50%')
        ->assertOk();
});

it('denies access to training dashboard without permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('media.training-dashboard')
        ->assertForbidden();
});

it('can filter dashboard by training path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path1 = TrainingPath::factory()->create(['name' => 'Path One']);
    $path2 = TrainingPath::factory()->create(['name' => 'Path Two']);
    TrainingPathStep::factory()->create(['training_path_id' => $path1->id, 'title' => 'Step Alpha']);
    TrainingPathStep::factory()->create(['training_path_id' => $path2->id, 'title' => 'Step Beta']);

    Livewire::actingAs($user)
        ->test('media.training-dashboard')
        ->set('pathFilter', $path2->id)
        ->assertSee('Step Beta');
});

it('shows summary stats on training dashboard', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $employee1 = User::factory()->internal()->create();
    $employee2 = User::factory()->internal()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);

    TrainingProgress::factory()->completed()->create([
        'user_id' => $employee1->id,
        'training_path_step_id' => $step->id,
    ]);

    Livewire::actingAs($user)
        ->test('media.training-dashboard')
        ->assertSeeHtml('data-summary-stats')
        ->assertSee('Active Paths')
        ->assertSee('Employees')
        ->assertSee('Avg. Completion')
        ->assertOk();
});

// =========================================================
// Training Center (Employee View)
// =========================================================

it('shows assigned training paths to employee', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['name' => 'Employee Onboarding', 'visibility' => 'all_internal']);
    TrainingPathStep::factory()->count(2)->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-center')
        ->assertSeeHtml('data-training-path="Employee Onboarding"')
        ->assertOk();
});

it('shows correct completion percentage for training path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['visibility' => 'all_internal']);
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);

    TrainingProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $step1->id,
    ]);

    Livewire::actingAs($user)
        ->test('media.training-center')
        ->assertSee('50%');
});

it('shows 100% for fully completed path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['visibility' => 'all_internal']);
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);

    TrainingProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $step->id,
    ]);

    Livewire::actingAs($user)
        ->test('media.training-center')
        ->assertSee('100%')
        ->assertSee('Complete');
});

it('does not show inactive training paths', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $activePath = TrainingPath::factory()->create(['name' => 'Active Training', 'visibility' => 'all_internal']);
    $inactivePath = TrainingPath::factory()->inactive()->create(['name' => 'Inactive Training', 'visibility' => 'all_internal']);

    Livewire::actingAs($user)
        ->test('media.training-center')
        ->assertSeeHtml('data-training-path="Active Training"')
        ->assertDontSee('Inactive Training');
});

it('filters paths by visibility for employees', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $allPath = TrainingPath::factory()->create(['name' => 'All Internal Path', 'visibility' => 'all_internal']);
    $empPath = TrainingPath::factory()->forEmployees()->create(['name' => 'Employees Only Path']);
    $crewPath = TrainingPath::factory()->forCrews()->create(['name' => 'Crews Only Path']);

    Livewire::actingAs($user)
        ->test('media.training-center')
        ->assertSeeHtml('data-training-path="All Internal Path"')
        ->assertSeeHtml('data-training-path="Employees Only Path"')
        ->assertDontSee('Crews Only Path');
});

it('shows empty state when no training paths assigned', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.training-center')
        ->assertSee('No training assigned');
});

// =========================================================
// Route Tests
// =========================================================

it('can access training dashboard route', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get(route('media.training.dashboard'))
        ->assertOk();
});

it('can access training manage route', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();

    $this->actingAs($user)
        ->get(route('media.training.manage', $path))
        ->assertOk();
});

it('can access my training route', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get(route('my.training'))
        ->assertOk();
});

it('can access training player route', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    $this->actingAs($user)
        ->get(route('my.training.play', [$path, $step]))
        ->assertOk();
});

it('redirects unauthenticated users from training routes', function () {
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    $this->get(route('media.training.dashboard'))->assertRedirect();
    $this->get(route('media.training.manage', $path))->assertRedirect();
    $this->get(route('my.training'))->assertRedirect();
    $this->get(route('my.training.play', [$path, $step]))->assertRedirect();
});

// =========================================================
// Training Progress Tracking
// =========================================================

it('correctly calculates completion for path with mixed required and optional steps', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['visibility' => 'all_internal']);
    $reqStep1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    $reqStep2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    $optStep = TrainingPathStep::factory()->optional()->create(['training_path_id' => $path->id]);

    // Complete 1 of 2 required steps
    TrainingProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $reqStep1->id,
    ]);

    // Optional step completed shouldn't affect required percentage
    TrainingProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $optStep->id,
    ]);

    expect($path->getCompletionPercentageForUser($user))->toBe(50.0);
});

// =========================================================
// Training Player
// =========================================================

it('loads training player for a step', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $video = VideoRecording::factory()->ready()->create(['user_id' => $user->id, 'title' => 'Paint Like a Pro']);
    $step = TrainingPathStep::factory()->create([
        'training_path_id' => $path->id,
        'video_recording_id' => $video->id,
        'title' => 'Learn Painting',
    ]);

    Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step])
        ->assertSee('Learn Painting')
        ->assertOk();
});

it('returns 404 if step does not belong to path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path1 = TrainingPath::factory()->create();
    $path2 = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path2->id]);

    Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path1, 'step' => $step])
        ->assertNotFound();
});

it('updates progress when watch percentage reported', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step])
        ->call('updateProgress', 50);

    $progress = TrainingProgress::where('user_id', $user->id)
        ->where('training_path_step_id', $step->id)
        ->first();

    expect($progress)
        ->not->toBeNull()
        ->watch_percentage->toBe(50)
        ->completed_at->toBeNull();
});

it('auto-completes at 90% watch percentage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step])
        ->call('updateProgress', 90)
        ->assertSet('isCompleted', true);

    $progress = TrainingProgress::where('user_id', $user->id)
        ->where('training_path_step_id', $step->id)
        ->first();

    expect($progress)
        ->watch_percentage->toBe(100)
        ->completed_at->not->toBeNull();
});

it('auto-completes at 100% watch percentage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step])
        ->call('updateProgress', 100)
        ->assertSet('isCompleted', true);

    expect(TrainingProgress::where('user_id', $user->id)
        ->where('training_path_step_id', $step->id)
        ->first()
        ->completed_at)->not->toBeNull();
});

it('does not decrease progress when lower percentage reported', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step = TrainingPathStep::factory()->create(['training_path_id' => $path->id]);

    Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step])
        ->call('updateProgress', 60)
        ->call('updateProgress', 30);

    $progress = TrainingProgress::where('user_id', $user->id)
        ->where('training_path_step_id', $step->id)
        ->first();

    expect($progress->watch_percentage)->toBe(60);
});

it('shows next and previous step navigation', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 0, 'title' => 'Step One']);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 1, 'title' => 'Step Two']);
    $step3 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 2, 'title' => 'Step Three']);

    $component = Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step2]);

    expect($component->get('previousStep')->id)->toBe($step1->id);
    expect($component->get('nextStep')->id)->toBe($step3->id);
});

it('has no previous step on first step', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 0]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 1]);

    $component = Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step1]);

    expect($component->get('previousStep'))->toBeNull();
    expect($component->get('nextStep')->id)->toBe($step2->id);
});

it('has no next step on last step', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create();
    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 0]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 1]);

    $component = Livewire::actingAs($user)
        ->test('media.training-player', ['trainingPath' => $path, 'step' => $step2]);

    expect($component->get('previousStep')->id)->toBe($step1->id);
    expect($component->get('nextStep'))->toBeNull();
});

it('returns 100% completion for path with no required steps', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $path = TrainingPath::factory()->create(['visibility' => 'all_internal']);
    TrainingPathStep::factory()->optional()->create(['training_path_id' => $path->id]);

    expect($path->getCompletionPercentageForUser($user))->toBe(100.0);
});
