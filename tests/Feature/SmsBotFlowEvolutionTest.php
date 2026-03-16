<?php

use App\Models\SmsBotFlow;

it('can create a flow with trigger_keyword and completion_action', function () {
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create([
        'completion_action' => 'create_lead',
    ]);

    expect($flow->trigger_keyword)->toBe('COLOR')
        ->and($flow->trigger_type)->toBe('keyword')
        ->and($flow->completion_action)->toBe('create_lead')
        ->and($flow->isActive())->toBeTrue();
});

it('finds active flow by keyword', function () {
    SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    SmsBotFlow::factory()->draft()->keyword('PROMO')->create();

    $found = SmsBotFlow::findByKeyword('color');
    expect($found)->not->toBeNull()
        ->and($found->trigger_keyword)->toBe('COLOR');

    $notFound = SmsBotFlow::findByKeyword('promo');
    expect($notFound)->toBeNull();
});

it('findByKeyword trims and uppercases input', function () {
    SmsBotFlow::factory()->active()->keyword('HELLO')->create();

    expect(SmsBotFlow::findByKeyword('  hello  '))->not->toBeNull()
        ->and(SmsBotFlow::findByKeyword('Hello'))->not->toBeNull();
});

it('findByKeyword returns null for non-existent keyword', function () {
    SmsBotFlow::factory()->active()->keyword('COLOR')->create();

    expect(SmsBotFlow::findByKeyword('NONEXISTENT'))->toBeNull();
});

it('findForTrigger still works for programmatic triggers', function () {
    $flow = SmsBotFlow::factory()->active()->create([
        'trigger_type' => 'new_lead',
    ]);

    $found = SmsBotFlow::findForTrigger('new_lead');
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($flow->id);
});

it('fillable includes new columns and excludes old message columns', function () {
    $flow = new SmsBotFlow;
    $fillable = $flow->getFillable();

    expect($fillable)->toContain('trigger_keyword')
        ->toContain('completion_action')
        ->not->toContain('greeting_message')
        ->not->toContain('photo_prompt_message')
        ->not->toContain('visualization_message')
        ->not->toContain('portal_link_message');
});
