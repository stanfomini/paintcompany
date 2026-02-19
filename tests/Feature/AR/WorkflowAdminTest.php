<?php

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use Database\Seeders\WorkflowSeeder;
use Livewire\Livewire;

// =========================================================================
// Authorization
// =========================================================================

it('renders workflow settings page for user with settings.workflows permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $this->actingAs($user)
        ->get(route('workflows.edit'))
        ->assertSuccessful();
});

it('shows 403 for unauthorized user without settings.workflows permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('workflows.edit'))
        ->assertForbidden();
});

it('denies access for non-internal user types', function () {
    $customer = User::factory()->customer()->create();

    $response = $this->actingAs($customer)
        ->get(route('workflows.edit'));

    // Non-internal users get redirected or forbidden depending on middleware
    expect($response->status())->toBeIn([302, 403]);
});

// =========================================================================
// Listing Workflows
// =========================================================================

it('displays existing workflows in the list', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    Workflow::factory()->create([
        'name' => 'Test Display Workflow',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->assertSee('Test Display Workflow');
});

// =========================================================================
// Active Toggle
// =========================================================================

it('can toggle workflow active state', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $workflow = Workflow::factory()->create(['is_active' => false]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('toggleActive', $workflow->id);

    expect($workflow->fresh()->is_active)->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('toggleActive', $workflow->id);

    expect($workflow->fresh()->is_active)->toBeFalse();
});

// =========================================================================
// Deleting Workflows
// =========================================================================

it('can delete a non-system workflow', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $workflow = Workflow::factory()->create([
        'name' => 'Deletable Workflow',
        'is_system' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('confirmDelete', $workflow->id, 'Deletable Workflow')
        ->assertSet('showDeleteModal', true)
        ->call('deleteWorkflow')
        ->assertSet('showDeleteModal', false);

    expect(Workflow::find($workflow->id))->toBeNull();
});

it('cannot delete a system workflow', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $workflow = Workflow::factory()->system()->create(['name' => 'System Workflow']);

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('confirmDelete', $workflow->id, 'System Workflow')
        ->call('deleteWorkflow');

    expect(Workflow::find($workflow->id))->not->toBeNull();
});

// =========================================================================
// Run History
// =========================================================================

it('can view workflow run history', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $workflow = Workflow::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('viewRunHistory', $workflow->id)
        ->assertSet('showRunHistory', true)
        ->assertSet('runHistoryWorkflowId', $workflow->id);
});

// =========================================================================
// Seeder Verification
// =========================================================================

it('creates expected engine workflows when seeder runs', function () {
    $seeder = new WorkflowSeeder;
    $seeder->run();

    // Verify the 4 engine-based workflows exist
    expect(Workflow::where('name', 'Door Knock Flat Rate')->exists())->toBeTrue();
    expect(Workflow::where('name', 'Sales Commission on Signed Estimate')->exists())->toBeTrue();
    expect(Workflow::where('name', 'Painting Crew Deposit')->exists())->toBeTrue();
    expect(Workflow::where('name', 'Painting Crew Final')->exists())->toBeTrue();

    // Verify Door Knock Flat Rate has correct trigger and action
    $doorKnock = Workflow::where('name', 'Door Knock Flat Rate')
        ->with(['triggers', 'actions'])
        ->first();

    expect($doorKnock->is_system)->toBeTrue();
    expect($doorKnock->is_active)->toBeTrue();
    expect($doorKnock->triggers)->toHaveCount(1);
    expect($doorKnock->triggers->first()->trigger_model)->toBe('deal');
    expect($doorKnock->triggers->first()->trigger_event)->toBe('status_changed');
    expect($doorKnock->actions)->toHaveCount(1);
    expect($doorKnock->actions->first()->action_type)->toBe(WorkflowAction::ACTION_CREATE_FLAT_RATE);
    expect((float) $doorKnock->actions->first()->action_config['amount'])->toBe(50.0);
    expect($doorKnock->actions->first()->target_user_field)->toBe('lead_generator_id');

    // Verify Painting Crew Deposit has service category
    $crewDeposit = Workflow::where('name', 'Painting Crew Deposit')
        ->with('serviceCategory')
        ->first();
    expect($crewDeposit->serviceCategory)->not->toBeNull();
    expect($crewDeposit->serviceCategory->slug)->toBe('painting');
});
