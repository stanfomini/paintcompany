<?php

use App\Models\ColorVisualization;
use App\Models\SmsConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores sms_conversation_id on color visualization', function () {
    $conversation = SmsConversation::factory()->create();

    $visualization = ColorVisualization::factory()->create([
        'sms_conversation_id' => $conversation->id,
    ]);

    $visualization->refresh();

    expect($visualization->sms_conversation_id)->toBe($conversation->id);
});

it('has smsConversation relationship', function () {
    $conversation = SmsConversation::factory()->create();

    $visualization = ColorVisualization::factory()->create([
        'sms_conversation_id' => $conversation->id,
    ]);

    expect($visualization->smsConversation)->toBeInstanceOf(SmsConversation::class);
    expect($visualization->smsConversation->id)->toBe($conversation->id);
});

it('allows null sms_conversation_id', function () {
    $visualization = ColorVisualization::factory()->create([
        'sms_conversation_id' => null,
    ]);

    $visualization->refresh();

    expect($visualization->sms_conversation_id)->toBeNull();
    expect($visualization->smsConversation)->toBeNull();
});
