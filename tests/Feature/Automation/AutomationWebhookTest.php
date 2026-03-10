<?php

use App\Events\Automation\WebhookReceived;
use App\Models\AutomationWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

it('accepts valid webhook and dispatches event', function () {
    Event::fake([WebhookReceived::class]);

    $webhook = AutomationWebhook::factory()->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}", [
        'event' => 'order.completed',
        'data' => ['order_id' => 123],
    ])->assertSuccessful()
        ->assertJson(['status' => 'received']);

    Event::assertDispatched(WebhookReceived::class, function ($event) use ($webhook) {
        return $event->webhook->is($webhook)
            && $event->payload['event'] === 'order.completed';
    });
});

it('returns 404 for invalid token', function () {
    $this->postJson('/webhooks/automation/invalid-token', [
        'test' => true,
    ])->assertNotFound();
});

it('returns 403 for inactive webhook', function () {
    $webhook = AutomationWebhook::factory()->inactive()->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}", [
        'test' => true,
    ])->assertForbidden();
});

it('returns 401 for failed bearer auth', function () {
    $webhook = AutomationWebhook::factory()->withBearerAuth('correct-token')->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}", ['test' => true], [
        'Authorization' => 'Bearer wrong-token',
    ])->assertUnauthorized();
});

it('accepts valid bearer auth', function () {
    Event::fake([WebhookReceived::class]);

    $webhook = AutomationWebhook::factory()->withBearerAuth('correct-token')->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}", ['test' => true], [
        'Authorization' => 'Bearer correct-token',
    ])->assertSuccessful();

    Event::assertDispatched(WebhookReceived::class);
});

it('returns 401 for failed api key auth', function () {
    $webhook = AutomationWebhook::factory()->withApiKeyAuth('X-Api-Key', 'correct-key')->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}", ['test' => true], [
        'X-Api-Key' => 'wrong-key',
    ])->assertUnauthorized();
});

it('accepts valid api key auth', function () {
    Event::fake([WebhookReceived::class]);

    $webhook = AutomationWebhook::factory()->withApiKeyAuth('X-Api-Key', 'correct-key')->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}", ['test' => true], [
        'X-Api-Key' => 'correct-key',
    ])->assertSuccessful();

    Event::assertDispatched(WebhookReceived::class);
});

it('updates webhook metadata on trigger', function () {
    Event::fake([WebhookReceived::class]);

    $webhook = AutomationWebhook::factory()->create([
        'created_by' => $this->user->id,
    ]);

    expect($webhook->trigger_count)->toBeIn([0, null]);
    expect($webhook->last_triggered_at)->toBeNull();

    $this->postJson("/webhooks/automation/{$webhook->token}", [
        'foo' => 'bar',
    ])->assertSuccessful();

    $webhook->refresh();
    expect($webhook->trigger_count)->toBe(1);
    expect($webhook->last_triggered_at)->not->toBeNull();
    expect($webhook->last_payload)->toBe(['foo' => 'bar']);
});

it('generates correct webhook url', function () {
    $webhook = AutomationWebhook::factory()->create([
        'created_by' => $this->user->id,
    ]);

    expect($webhook->getWebhookUrl())->toContain("/webhooks/automation/{$webhook->token}");
});

it('returns 401 for failed query param auth', function () {
    $webhook = AutomationWebhook::factory()->withQueryParamAuth('api_key', 'correct-key')->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}?api_key=wrong-key", [
        'test' => true,
    ])->assertUnauthorized();
});

it('accepts valid query param auth', function () {
    Event::fake([WebhookReceived::class]);

    $webhook = AutomationWebhook::factory()->withQueryParamAuth('api_key', 'correct-key')->create([
        'created_by' => $this->user->id,
    ]);

    $this->postJson("/webhooks/automation/{$webhook->token}?api_key=correct-key", [
        'test' => true,
    ])->assertSuccessful();

    Event::assertDispatched(WebhookReceived::class);
});

it('generates full webhook url with query param auth', function () {
    $webhook = AutomationWebhook::factory()->withQueryParamAuth('api_key', 'my-secret')->create([
        'created_by' => $this->user->id,
    ]);

    $fullUrl = $webhook->getFullWebhookUrl();
    expect($fullUrl)->toContain("/webhooks/automation/{$webhook->token}?api_key=my-secret");
});

it('generates plain webhook url when no query param auth', function () {
    $webhook = AutomationWebhook::factory()->create([
        'created_by' => $this->user->id,
    ]);

    expect($webhook->getFullWebhookUrl())->toBe($webhook->getWebhookUrl());
});
