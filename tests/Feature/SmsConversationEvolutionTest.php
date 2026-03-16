<?php

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;

it('can create conversation with status and current_step_id', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    $step = SmsBotStep::factory()->create(['flow_id' => $flow->id]);

    $convo = SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'current_step_id' => $step->id,
        'status' => 'in_progress',
    ]);

    expect($convo->status)->toBe('in_progress')
        ->and($convo->current_step_id)->toBe($step->id);
});

it('can create conversation without project_preview_id', function () {
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => null,
        'status' => 'in_progress',
    ]);

    expect($convo->project_preview_id)->toBeNull()
        ->and($convo->exists)->toBeTrue();
})->skip(fn () => \DB::getDriverName() === 'sqlite', 'SQLite cannot alter column to nullable');

it('currentStep relationship works', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    $step = SmsBotStep::factory()->create(['flow_id' => $flow->id, 'step_type' => 'greeting']);

    $convo = SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'current_step_id' => $step->id,
    ]);

    expect($convo->currentStep)->not->toBeNull()
        ->and($convo->currentStep->id)->toBe($step->id)
        ->and($convo->currentStep->step_type)->toBe('greeting');
});

it('isActive checks status field', function () {
    $active = SmsConversation::factory()->create(['status' => 'in_progress']);
    $completed = SmsConversation::factory()->completed()->create();
    $takeover = SmsConversation::factory()->humanTakeover()->create();

    expect($active->isActive())->toBeTrue()
        ->and($completed->isActive())->toBeFalse()
        ->and($takeover->isActive())->toBeFalse();
});

it('isActive returns false when expired', function () {
    $expired = SmsConversation::factory()->create([
        'status' => 'in_progress',
        'expires_at' => now()->subHour(),
    ]);

    expect($expired->isActive())->toBeFalse();
});

it('isActive returns true when no expiry set', function () {
    $noExpiry = SmsConversation::factory()->create([
        'status' => 'in_progress',
        'expires_at' => null,
    ]);

    expect($noExpiry->isActive())->toBeTrue();
});

it('isHumanTakeover checks status field', function () {
    $takeover = SmsConversation::factory()->humanTakeover()->create();
    $active = SmsConversation::factory()->create(['status' => 'in_progress']);

    expect($takeover->isHumanTakeover())->toBeTrue()
        ->and($active->isHumanTakeover())->toBeFalse();
});

it('isAbandoned checks status field', function () {
    $abandoned = SmsConversation::factory()->abandoned()->create();
    $active = SmsConversation::factory()->create(['status' => 'in_progress']);

    expect($abandoned->isAbandoned())->toBeTrue()
        ->and($active->isAbandoned())->toBeFalse();
});

it('scopeActive filters by status and expiry', function () {
    SmsConversation::factory()->create(['status' => 'in_progress', 'expires_at' => now()->addDay()]);
    SmsConversation::factory()->create(['status' => 'in_progress', 'expires_at' => null]);
    SmsConversation::factory()->completed()->create();
    SmsConversation::factory()->create(['status' => 'in_progress', 'expires_at' => now()->subHour()]);

    $active = SmsConversation::query()->active()->get();

    expect($active)->toHaveCount(2);
});

it('findActiveByPhone returns active conversation', function () {
    $phone = '+13035551234';
    SmsConversation::factory()->create([
        'phone' => $phone,
        'status' => 'in_progress',
        'expires_at' => now()->addDay(),
    ]);

    $found = SmsConversation::findActiveByPhone($phone);
    expect($found)->not->toBeNull()
        ->and($found->phone)->toBe($phone);

    $notFound = SmsConversation::findActiveByPhone('+10000000000');
    expect($notFound)->toBeNull();
});

it('fillable includes new columns', function () {
    $convo = new SmsConversation;
    $fillable = $convo->getFillable();

    expect($fillable)->toContain('current_step_id')
        ->toContain('status');
});
