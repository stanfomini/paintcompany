<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateAmendment;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::create(['company_name' => 'Test Company']);
});

it('estimate is editable when status is sent', function () {
    $estimate = Estimate::factory()->sent()->create();

    expect($estimate->isEditable())->toBeTrue();
});

it('estimate is editable when status is draft', function () {
    $estimate = Estimate::factory()->draft()->create();

    expect($estimate->isEditable())->toBeTrue();
});

it('estimate is not editable when accepted', function () {
    $estimate = Estimate::factory()->accepted()->create();

    expect($estimate->isEditable())->toBeFalse();
});

it('estimate is not editable when rejected', function () {
    $estimate = Estimate::factory()->rejected()->create();

    expect($estimate->isEditable())->toBeFalse();
});

it('hasPendingAmendments returns true when unacknowledged amendments exist', function () {
    $estimate = Estimate::factory()->sent()->create();
    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create();

    expect($estimate->hasPendingAmendments())->toBeTrue();
});

it('hasPendingAmendments returns false when all amendments acknowledged', function () {
    $estimate = Estimate::factory()->sent()->create();
    EstimateAmendment::factory()->forEstimate($estimate)->acknowledged()->create();

    expect($estimate->hasPendingAmendments())->toBeFalse();
});

it('hasPendingAmendments returns false when no amendments exist', function () {
    $estimate = Estimate::factory()->sent()->create();

    expect($estimate->hasPendingAmendments())->toBeFalse();
});

it('pendingAmendmentsCount returns correct count', function () {
    $estimate = Estimate::factory()->sent()->create();
    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create(['amendment_number' => 1]);
    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create(['amendment_number' => 2]);
    EstimateAmendment::factory()->forEstimate($estimate)->acknowledged()->create(['amendment_number' => 3]);

    expect($estimate->pendingAmendmentsCount())->toBe(2);
});

it('markAsSent captures sent_snapshot', function () {
    Notification::fake();

    $estimate = Estimate::factory()->draft()->create();

    $estimate->markAsSent();

    expect($estimate->fresh()->sent_snapshot)->not->toBeNull()
        ->and($estimate->fresh()->sent_snapshot)->toHaveKeys(['areas', 'packages', 'options', 'totals']);
});

it('amendment model calculates total change correctly', function () {
    $amendment = EstimateAmendment::factory()->create([
        'previous_total' => 5000.00,
        'new_total' => 5500.00,
    ]);

    expect($amendment->getTotalChangeAmount())->toBe(500.0)
        ->and($amendment->getTotalChangeFormatted())->toBe('+$500.00');
});

it('amendment model calculates negative change correctly', function () {
    $amendment = EstimateAmendment::factory()->create([
        'previous_total' => 5000.00,
        'new_total' => 4500.00,
    ]);

    expect($amendment->getTotalChangeAmount())->toBe(-500.0)
        ->and($amendment->getTotalChangeFormatted())->toBe('-$500.00');
});

it('allows saving a sent estimate with past valid_until date as draft', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create([
        'estimator_mode' => Estimate::MODE_TEMPLATE,
        'valid_until' => now()->subDays(10),
        'created_by' => $user->id,
    ]);

    $category = EstimateTemplateCategory::factory()->room()->create();
    $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->call('addTemplate', $template->id)
        ->set('title', 'Updated Sent Estimate')
        ->call('saveAsDraft')
        ->assertHasNoErrors(['validUntil'])
        ->assertDispatched('estimate-updated');

    $estimate->refresh();
    expect($estimate->title)->toBe('Updated Sent Estimate');
});

it('captures sent_snapshot on first edit of sent estimate without snapshot', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create([
        'estimator_mode' => Estimate::MODE_TEMPLATE,
        'sent_snapshot' => null,
        'created_by' => $user->id,
    ]);

    $category = EstimateTemplateCategory::factory()->room()->create();
    $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->call('addTemplate', $template->id)
        ->set('title', 'Updated')
        ->call('saveAsDraft')
        ->assertHasNoErrors();

    $estimate->refresh();
    expect($estimate->sent_snapshot)->not->toBeNull();
});
