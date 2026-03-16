<?php

use App\Models\ProjectPreview;
use Livewire\Livewire;

it('renders questionnaire component with step 1', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSee("What's driving this project")
        ->assertSee('Step 1 of 5');
});

it('saves step 1 data when clicking next', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->set('step1Motivation', 'selling')
        ->set('step1Notes', 'Need it done before listing in April')
        ->call('nextStep');

    $preview->refresh();
    $data = $preview->questionnaire_data;

    expect($data['step_1']['motivation'])->toBe('selling');
    expect($data['step_1']['notes'])->toBe('Need it done before listing in April');
});

it('shows interstitial after completing a step', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->set('step1Motivation', 'fresh_look')
        ->call('nextStep')
        ->assertSet('showInterstitial', true)
        ->assertSee('No surprises');
});

it('advances to next step after continuing from interstitial', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->set('step1Motivation', 'fresh_look')
        ->call('nextStep')
        ->call('continueFromInterstitial')
        ->assertSet('currentStep', 2)
        ->assertSet('showInterstitial', false)
        ->assertSee('What does your ideal result look like');
});

it('resumes at correct step for partial completion', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSet('currentStep', 3)
        ->assertSee('Which areas need attention');
});

it('loads existing answers when resuming', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'remodeling', 'notes' => 'Kitchen remodel'],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSet('step1Motivation', 'remodeling')
        ->assertSet('step1Notes', 'Kitchen remodel');
});

it('saves multi-select areas in step 3', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'fresh_look', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->set('step3Areas', ['living_areas', 'kitchen', 'hallways_stairs'])
        ->set('step3Notes', 'Open floor plan')
        ->call('nextStep');

    $preview->refresh();
    $data = $preview->questionnaire_data;

    expect($data['step_3']['areas'])->toBe(['living_areas', 'kitchen', 'hallways_stairs']);
    expect($data['step_3']['notes'])->toBe('Open floor plan');
});

it('saves multi-select logistics in step 4', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'fresh_look', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->set('step4Logistics', ['pets', 'working_from_home'])
        ->set('step4Notes', '2 dogs, crated during work')
        ->call('nextStep');

    $preview->refresh();
    $data = $preview->questionnaire_data;

    expect($data['step_4']['logistics'])->toBe(['pets', 'working_from_home']);
    expect($data['step_4']['notes'])->toBe('2 dogs, crated during work');
});

it('completing all 5 steps sets completed_at and discount', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
            'step_4' => ['logistics' => ['pets'], 'notes' => ''],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSet('currentStep', 5)
        ->set('step5Investment', 'good_quality')
        ->set('step5Timeline', '2_4_weeks')
        ->set('step5Notes', '')
        ->call('nextStep')
        ->assertSet('completed', true)
        ->assertDispatched('questionnaire-completed');

    $preview->refresh();
    expect($preview->questionnaire_completed_at)->not->toBeNull();
    expect((float) $preview->questionnaire_discount_percent)->toBe(5.00);
});

it('shows completion card when already completed', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
            'step_4' => ['logistics' => [], 'notes' => ''],
            'step_5' => ['investment' => 'good_quality', 'timeline' => '2_4_weeks', 'notes' => ''],
        ],
        'questionnaire_completed_at' => now(),
        'questionnaire_discount_percent' => 5.00,
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSet('completed', true)
        ->assertSee("You're all set")
        ->assertSee('5% off');
});

it('can go back to previous step without interstitial', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSet('currentStep', 2)
        ->call('previousStep')
        ->assertSet('currentStep', 1)
        ->assertSet('showInterstitial', false);
});

it('saves free-text notes per step', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->set('step1Motivation', 'other')
        ->set('step1Notes', 'Rental property needs refreshing before new tenants')
        ->call('nextStep');

    $preview->refresh();
    expect($preview->questionnaire_data['step_1']['notes'])->toBe('Rental property needs refreshing before new tenants');
});

it('step 5 goes directly to completion without interstitial', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
            'step_4' => ['logistics' => [], 'notes' => ''],
        ],
    ]);

    Livewire::test('public.project-questionnaire', ['preview' => $preview])
        ->assertSet('currentStep', 5)
        ->set('step5Investment', 'premium')
        ->set('step5Timeline', 'asap')
        ->call('nextStep')
        ->assertSet('showInterstitial', false)
        ->assertSet('completed', true);
});

it('model helpers work correctly', function () {
    $incomplete = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
        ],
    ]);

    expect($incomplete->isQuestionnaireCompleted())->toBeFalse();
    expect($incomplete->questionnaireProgress())->toBe(2);

    $complete = ProjectPreview::factory()->create([
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
            'step_4' => ['logistics' => [], 'notes' => ''],
            'step_5' => ['investment' => 'good_quality', 'timeline' => 'flexible', 'notes' => ''],
        ],
        'questionnaire_completed_at' => now(),
        'questionnaire_discount_percent' => 5.00,
    ]);

    expect($complete->isQuestionnaireCompleted())->toBeTrue();
    expect($complete->questionnaireProgress())->toBe(5);
});
