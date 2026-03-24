<?php

use App\Models\CompanySetting;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
});

it('renders sms bot settings page', function () {
    $this->actingAs($this->internalUser)
        ->get(route('settings.sms-bot'))
        ->assertSuccessful()
        ->assertSee('SMS Bot Settings')
        ->assertSee('Bot Flows');
});

it('blocks non-internal users from sms bot settings', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)
        ->get(route('settings.sms-bot'))
        ->assertForbidden();
});

it('creates a new keyword-triggered flow and redirects to builder', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->set('showCreateFlow', true)
        ->set('newFlowName', 'COLOR Flow')
        ->set('newFlowTrigger', 'keyword')
        ->set('newFlowKeyword', 'color')
        ->set('newFlowCompletion', 'create_lead')
        ->set('newFlowTimeout', 1440)
        ->set('newFlowDelay', 0)
        ->set('newFlowBusinessHours', true)
        ->call('createFlow')
        ->assertRedirect();

    $flow = SmsBotFlow::first();
    expect($flow)->not->toBeNull()
        ->and($flow->name)->toBe('COLOR Flow')
        ->and($flow->trigger_type)->toBe('keyword')
        ->and($flow->trigger_keyword)->toBe('COLOR')
        ->and($flow->completion_action)->toBe('create_lead')
        ->and($flow->timeout_minutes)->toBe(1440)
        ->and($flow->business_hours_only)->toBeTrue()
        ->and($flow->status)->toBe('draft');
});

it('creates a new_lead flow without keyword', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->set('showCreateFlow', true)
        ->set('newFlowName', 'Lead Flow')
        ->set('newFlowTrigger', 'new_lead')
        ->set('newFlowCompletion', 'none')
        ->set('newFlowTimeout', 10080)
        ->call('createFlow')
        ->assertRedirect();

    $flow = SmsBotFlow::first();
    expect($flow)->not->toBeNull()
        ->and($flow->trigger_type)->toBe('new_lead')
        ->and($flow->trigger_keyword)->toBeNull()
        ->and($flow->completion_action)->toBeNull();
});

it('requires keyword when trigger type is keyword', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->set('showCreateFlow', true)
        ->set('newFlowName', 'Test Flow')
        ->set('newFlowTrigger', 'keyword')
        ->set('newFlowKeyword', '')
        ->call('createFlow')
        ->assertHasErrors(['newFlowKeyword']);
});

it('renders flow builder for existing flow', function () {
    $flow = SmsBotFlow::factory()->keyword('COLOR')->create();
    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Welcome!',
    ]);
    SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'What color?',
    ]);

    $this->actingAs($this->internalUser)
        ->get(route('settings.sms-bot.flows.edit', $flow))
        ->assertSuccessful()
        ->assertSee('SMS Bot Settings')
        ->assertSee('Save Flow');
});

it('renders flow builder create page', function () {
    $this->actingAs($this->internalUser)
        ->get(route('settings.sms-bot.flows.create'))
        ->assertSuccessful()
        ->assertSee('New Flow')
        ->assertSee('Save Flow');
});

it('saves flow with steps', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot-flow-builder')
        ->set('flowName', 'Test Flow')
        ->set('triggerType', 'keyword')
        ->set('triggerKeyword', 'TEST')
        ->set('completionAction', 'none')
        ->set('timeoutMinutes', 60)
        ->set('steps', [
            [
                'id' => null,
                'step_type' => 'greeting',
                'message_text' => 'Hello!',
                'media_url' => '',
                'source_step_index' => '',
                'wait_for_media' => false,
                'quick_reply_options' => [],
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => 3,
                'next_step_index' => '',
            ],
            [
                'id' => null,
                'step_type' => 'question',
                'message_text' => 'What color?',
                'media_url' => '',
                'source_step_index' => '',
                'wait_for_media' => false,
                'quick_reply_options' => [],
                'field_mapping' => 'customer.notes',
                'is_required' => true,
                'delay_seconds' => 3,
                'next_step_index' => '',
            ],
            [
                'id' => null,
                'step_type' => 'photo_prompt',
                'message_text' => 'Send a photo!',
                'media_url' => '',
                'source_step_index' => '',
                'wait_for_media' => true,
                'quick_reply_options' => [],
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => 3,
                'next_step_index' => '',
            ],
        ])
        ->call('saveFlow')
        ->assertHasNoErrors();

    $flow = SmsBotFlow::where('name', 'Test Flow')->first();
    expect($flow)->not->toBeNull()
        ->and($flow->trigger_keyword)->toBe('TEST')
        ->and($flow->steps)->toHaveCount(3);

    $steps = $flow->steps->sortBy('sort_order')->values();
    expect($steps[0]->step_type)->toBe('greeting')
        ->and($steps[0]->sort_order)->toBe(1)
        ->and($steps[1]->step_type)->toBe('question')
        ->and($steps[1]->field_mapping)->toBe('customer.notes')
        ->and($steps[2]->step_type)->toBe('photo_prompt')
        ->and($steps[2]->wait_for_media)->toBeTrue();
});

it('saves ai_visualization step with source_step_id', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot-flow-builder')
        ->set('flowName', 'Viz Flow')
        ->set('triggerType', 'keyword')
        ->set('triggerKeyword', 'VIZ')
        ->set('completionAction', 'none')
        ->set('timeoutMinutes', 60)
        ->set('steps', [
            [
                'id' => null,
                'step_type' => 'question',
                'message_text' => 'What color?',
                'media_url' => '',
                'source_step_index' => '',
                'wait_for_media' => false,
                'quick_reply_options' => [],
                'field_mapping' => null,
                'is_required' => true,
                'delay_seconds' => 3,
                'next_step_index' => '',
            ],
            [
                'id' => null,
                'step_type' => 'ai_visualization',
                'message_text' => 'Generating preview...',
                'media_url' => '',
                'source_step_index' => '0',
                'wait_for_media' => false,
                'quick_reply_options' => [],
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => 3,
                'next_step_index' => '',
            ],
        ])
        ->call('saveFlow')
        ->assertHasNoErrors();

    $flow = SmsBotFlow::where('name', 'Viz Flow')->first();
    $steps = $flow->steps->sortBy('sort_order')->values();

    expect($steps[1]->step_type)->toBe('ai_visualization')
        ->and($steps[1]->source_step_id)->toBe($steps[0]->id);
});

it('shows recent conversations with correct status badges', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
    ]);

    SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'phone' => '+15551234567',
        'status' => 'in_progress',
        'current_step_id' => $questionStep->id,
        'message_count' => 3,
        'photo_count' => 1,
    ]);

    SmsConversation::factory()->completed()->create([
        'flow_id' => $flow->id,
        'phone' => '+15559876543',
        'message_count' => 8,
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->assertSee('+15551234567')
        ->assertSee('In progress')
        ->assertSee('+15559876543')
        ->assertSee('Completed');
});

it('toggles flow status', function () {
    $flow = SmsBotFlow::factory()->draft()->create();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->call('toggleFlowStatus', $flow->id);

    expect($flow->fresh()->status)->toBe('active');
});

it('deletes a flow', function () {
    $flow = SmsBotFlow::factory()->create();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->call('deleteFlow', $flow->id);

    expect(SmsBotFlow::find($flow->id))->toBeNull();
});

it('shows keyword badge on keyword-triggered flows', function () {
    SmsBotFlow::factory()->keyword('COLOR')->create(['name' => 'Color Viz Flow']);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->assertSee('Color Viz Flow')
        ->assertSee('COLOR');
});

it('loads existing flow steps into builder', function () {
    $flow = SmsBotFlow::factory()->keyword('TEST')->create();
    $greeting = SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Hello there!',
    ]);
    $question = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'What color?',
        'field_mapping' => 'customer.notes',
    ]);

    $component = Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot-flow-builder', ['flow' => $flow->id]);

    $component->assertSet('flowName', $flow->name)
        ->assertSet('triggerType', 'keyword')
        ->assertSet('triggerKeyword', 'TEST');

    $steps = $component->get('steps');
    expect($steps)->toHaveCount(2)
        ->and($steps[0]['step_type'])->toBe('greeting')
        ->and($steps[0]['message_text'])->toBe('Hello there!')
        ->and($steps[1]['step_type'])->toBe('question')
        ->and($steps[1]['field_mapping'])->toBe('customer.notes');
});

it('saves media_message step with media_url', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot-flow-builder')
        ->set('flowName', 'Media Flow')
        ->set('triggerType', 'new_lead')
        ->set('completionAction', 'none')
        ->set('timeoutMinutes', 60)
        ->set('steps', [
            [
                'id' => null,
                'step_type' => 'media_message',
                'message_text' => 'Check this out!',
                'media_url' => 'color-tutorial',
                'source_step_index' => '',
                'wait_for_media' => false,
                'quick_reply_options' => [],
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => 3,
                'next_step_index' => '',
            ],
        ])
        ->call('saveFlow')
        ->assertHasNoErrors();

    $step = SmsBotStep::where('step_type', 'media_message')->first();
    expect($step)->not->toBeNull()
        ->and($step->media_url)->toBe('color-tutorial')
        ->and($step->message_text)->toBe('Check this out!');
});

it('renders welcome sms section on settings page', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->assertSee('Welcome SMS')
        ->assertSee('Automatically send a welcome text');
});

it('toggles welcome sms enabled', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['welcome_sms_enabled' => true]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->assertSet('welcomeSmsEnabled', true)
        ->call('toggleWelcomeSms')
        ->assertSet('welcomeSmsEnabled', false);

    expect(CompanySetting::getInstance()->fresh()->welcome_sms_enabled)->toBeFalse();
});

it('saves welcome sms message templates', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->set('welcomeSmsWithDeal', 'Hi {name}! Your portal: {portal_link}')
        ->set('welcomeSmsWithoutDeal', 'Hi {name}! Welcome to {company}.')
        ->call('saveWelcomeSmsSettings');

    $settings = CompanySetting::getInstance()->fresh();
    expect($settings->welcome_sms_with_deal)->toBe('Hi {name}! Your portal: {portal_link}')
        ->and($settings->welcome_sms_without_deal)->toBe('Hi {name}! Welcome to {company}.');
});

it('saves empty welcome sms templates as null', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'welcome_sms_with_deal' => 'existing message',
        'welcome_sms_without_deal' => 'existing message',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->set('welcomeSmsWithDeal', '')
        ->set('welcomeSmsWithoutDeal', '')
        ->call('saveWelcomeSmsSettings');

    $settings = CompanySetting::getInstance()->fresh();
    expect($settings->welcome_sms_with_deal)->toBeNull()
        ->and($settings->welcome_sms_without_deal)->toBeNull();
});

it('loads existing welcome sms settings on mount', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'welcome_sms_enabled' => false,
        'welcome_sms_with_deal' => 'Custom deal message',
        'welcome_sms_without_deal' => 'Custom no-deal message',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.sms-bot')
        ->assertSet('welcomeSmsEnabled', false)
        ->assertSet('welcomeSmsWithDeal', 'Custom deal message')
        ->assertSet('welcomeSmsWithoutDeal', 'Custom no-deal message');
});
