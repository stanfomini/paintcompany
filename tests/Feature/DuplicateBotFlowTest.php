<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerSetting;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update(['verify_token' => 'test-token']);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

// ─── Messenger Bot Flow ───

it('can duplicate a messenger bot flow', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::create([
        'name' => 'Lead Qualifier',
        'status' => 'active',
        'trigger_keyword' => 'PAINT',
        'greeting_message' => 'Hello!',
        'timeout_minutes' => 30,
        'business_hours_only' => true,
    ]);

    MessengerBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => 'greeting',
        'message_text' => 'Welcome!',
    ]);

    MessengerBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => 'text_question',
        'message_text' => 'What is your name?',
        'field_mapping' => 'customer.first_name',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->call('duplicateFlow', $flow->id);

    $copy = MessengerBotFlow::where('name', 'Lead Qualifier (Copy)')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->status)->toBe('draft')
        ->and($copy->trigger_keyword)->toBe('PAINT')
        ->and($copy->greeting_message)->toBe('Hello!')
        ->and($copy->business_hours_only)->toBeTrue()
        ->and($copy->steps)->toHaveCount(2);

    $copiedSteps = $copy->steps->sortBy('sort_order')->values();
    expect($copiedSteps[0]->step_type)->toBe('greeting')
        ->and($copiedSteps[0]->message_text)->toBe('Welcome!')
        ->and($copiedSteps[1]->step_type)->toBe('text_question')
        ->and($copiedSteps[1]->field_mapping)->toBe('customer.first_name');
});

it('remaps messenger bot flow branching when duplicating', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::create([
        'name' => 'Branching Flow',
        'status' => 'active',
        'timeout_minutes' => 30,
    ]);

    $step1 = MessengerBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => 'quick_reply',
        'message_text' => 'Interior or exterior?',
    ]);

    $step2 = MessengerBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => 'text_question',
        'message_text' => 'Interior details?',
    ]);

    $step3 = MessengerBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'step_type' => 'text_question',
        'message_text' => 'Exterior details?',
    ]);

    $step1->update([
        'quick_reply_options' => [
            ['title' => 'Interior', 'payload' => 'interior', 'next_step_id' => $step2->id],
            ['title' => 'Exterior', 'payload' => 'exterior', 'next_step_id' => $step3->id],
        ],
    ]);

    $step2->update(['next_step_id' => $step3->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->call('duplicateFlow', $flow->id);

    $copy = MessengerBotFlow::where('name', 'Branching Flow (Copy)')->first();
    $copiedSteps = $copy->steps->sortBy('sort_order')->values();

    $newStep1 = $copiedSteps[0];
    $newStep2 = $copiedSteps[1];
    $newStep3 = $copiedSteps[2];

    expect($newStep1->id)->not->toBe($step1->id)
        ->and($newStep2->id)->not->toBe($step2->id)
        ->and($newStep3->id)->not->toBe($step3->id);

    $options = $newStep1->quick_reply_options;
    expect($options[0]['next_step_id'])->toBe($newStep2->id)
        ->and($options[1]['next_step_id'])->toBe($newStep3->id);

    expect($newStep2->next_step_id)->toBe($newStep3->id);
});

// ─── SMS Bot Flow ───

it('can duplicate an sms bot flow', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $flow = SmsBotFlow::factory()->active()->keyword('PAINT')->create([
        'name' => 'SMS Lead Flow',
        'timeout_minutes' => 60,
        'business_hours_only' => false,
    ]);

    SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => 'greeting',
        'message_text' => 'Hi there!',
    ]);

    SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => 'question',
        'message_text' => 'What is your address?',
        'field_mapping' => 'customer.address',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.sms-bot')
        ->call('duplicateFlow', $flow->id);

    $copy = SmsBotFlow::where('name', 'SMS Lead Flow (Copy)')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->status)->toBe('draft')
        ->and($copy->trigger_type)->toBe('keyword')
        ->and($copy->trigger_keyword)->toBe('PAINT')
        ->and($copy->steps)->toHaveCount(2);

    $copiedSteps = $copy->steps->sortBy('sort_order')->values();
    expect($copiedSteps[0]->step_type)->toBe('greeting')
        ->and($copiedSteps[1]->field_mapping)->toBe('customer.address');
});

it('remaps sms bot flow branching when duplicating', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $flow = SmsBotFlow::factory()->active()->create([
        'name' => 'SMS Branching',
        'trigger_type' => 'new_lead',
        'timeout_minutes' => 60,
    ]);

    $step1 = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => 'quick_reply',
        'message_text' => 'Interior or exterior?',
    ]);

    $step2 = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => 'question',
        'message_text' => 'Interior details?',
    ]);

    $step3 = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'step_type' => 'photo_prompt',
        'message_text' => 'Send a photo',
        'source_step_id' => $step1->id,
    ]);

    $step1->update([
        'quick_reply_options' => [
            ['label' => 'Interior', 'value' => 'interior', 'next_step_id' => $step2->id],
            ['label' => 'Exterior', 'value' => 'exterior', 'next_step_id' => $step3->id],
        ],
        'next_step_id' => $step2->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.sms-bot')
        ->call('duplicateFlow', $flow->id);

    $copy = SmsBotFlow::where('name', 'SMS Branching (Copy)')->first();
    $copiedSteps = $copy->steps->sortBy('sort_order')->values();

    $newStep1 = $copiedSteps[0];
    $newStep2 = $copiedSteps[1];
    $newStep3 = $copiedSteps[2];

    $options = $newStep1->quick_reply_options;
    expect($options[0]['next_step_id'])->toBe($newStep2->id)
        ->and($options[1]['next_step_id'])->toBe($newStep3->id);

    expect($newStep1->next_step_id)->toBe($newStep2->id);
    expect($newStep3->source_step_id)->toBe($newStep1->id);
});
