<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

it('renders the create page for internal users with permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $this->actingAs($user)
        ->get('/settings/messenger/flows/create')
        ->assertSuccessful()
        ->assertSee('Save Flow');
});

it('renders the edit page for an existing flow', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create(['name' => 'Lead Qualification']);

    $this->actingAs($user)
        ->get("/settings/messenger/flows/{$flow->id}/edit")
        ->assertSuccessful()
        ->assertSee('Lead Qualification');
});

it('forbids access for users without messenger.manage permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/settings/messenger/flows/create')
        ->assertForbidden();
});

it('forbids access for non-internal users', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->get('/settings/messenger/flows/create')
        ->assertForbidden();
});

it('loads existing flow data into the component', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create([
        'name' => 'My Test Flow',
        'greeting_message' => 'Welcome!',
        'timeout_minutes' => 60,
        'business_hours_only' => true,
    ]);

    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What is your name?',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder', ['flow' => $flow->id])
        ->assertSet('flowName', 'My Test Flow')
        ->assertSet('greetingMessage', 'Welcome!')
        ->assertSet('timeoutMinutes', 60)
        ->assertSet('businessHoursOnly', true)
        ->assertCount('steps', 1);
});

it('can save a new flow with steps', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->set('flowName', 'New Flow')
        ->set('greetingMessage', 'Hello there!')
        ->set('timeoutMinutes', 45)
        ->call('addStepOfType', 'text_question')
        ->set('steps.0.message_text', 'What is your name?')
        ->call('saveFlow')
        ->assertHasNoErrors();

    expect(MessengerBotFlow::where('name', 'New Flow')->exists())->toBeTrue();

    $flow = MessengerBotFlow::where('name', 'New Flow')->first();
    expect($flow->greeting_message)->toBe('Hello there!');
    expect($flow->timeout_minutes)->toBe(45);
    expect($flow->steps)->toHaveCount(1);
    expect($flow->steps->first()->message_text)->toBe('What is your name?');
});

it('can add and remove steps', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $component = Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->assertCount('steps', 0)
        ->call('addStepOfType', 'text_question')
        ->assertCount('steps', 1)
        ->call('addStepOfType', 'quick_reply')
        ->assertCount('steps', 2)
        ->call('removeStep', 0)
        ->assertCount('steps', 1);

    expect($component->get('steps.0.step_type'))->toBe('quick_reply');
});

it('can move steps up and down', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $component = Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->call('addStepOfType', 'greeting')
        ->set('steps.0.message_text', 'Hello')
        ->call('addStepOfType', 'text_question')
        ->set('steps.1.message_text', 'Your name?')
        ->call('moveStepDown', 0);

    expect($component->get('steps.0.step_type'))->toBe('text_question');
    expect($component->get('steps.1.step_type'))->toBe('greeting');
});

it('can duplicate a step', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $component = Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->call('addStepOfType', 'text_question')
        ->set('steps.0.message_text', 'Original question')
        ->call('duplicateStep', 0)
        ->assertCount('steps', 2);

    expect($component->get('steps.1.message_text'))->toBe('Original question');
    expect($component->get('steps.1.id'))->toBeNull();
});

it('can activate and deactivate a flow', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create(['status' => 'draft']);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder', ['flow' => $flow->id])
        ->call('activateFlow')
        ->assertSet('flowStatus', 'active');

    expect($flow->fresh()->status)->toBe('active');

    $component->call('deactivateFlow')
        ->assertSet('flowStatus', 'inactive');

    expect($flow->fresh()->status)->toBe('inactive');
});

it('validates required fields when saving', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->set('flowName', '')
        ->call('saveFlow')
        ->assertHasErrors(['flowName']);
});

it('validates step messages are required when saving', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->set('flowName', 'Test Flow')
        ->call('addStepOfType', 'text_question')
        ->call('saveFlow')
        ->assertHasErrors(['steps.0.message_text']);
});

it('can add and remove quick reply options', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $component = Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder')
        ->call('addStepOfType', 'quick_reply');

    // Quick reply starts with 2 options
    expect($component->get('steps.0.quick_reply_options'))->toHaveCount(2);

    $component->call('addQuickReplyOption', 0);
    expect($component->get('steps.0.quick_reply_options'))->toHaveCount(3);

    $component->call('removeQuickReplyOption', 0, 0);
    expect($component->get('steps.0.quick_reply_options'))->toHaveCount(2);
});

it('updates existing flow on save', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create(['name' => 'Original']);

    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Old question',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.bot-flow-builder', ['flow' => $flow->id])
        ->set('flowName', 'Updated Name')
        ->set('steps.0.message_text', 'New question')
        ->call('saveFlow')
        ->assertHasNoErrors();

    $flow->refresh();
    expect($flow->name)->toBe('Updated Name');
    expect($flow->steps->first()->message_text)->toBe('New question');
});
