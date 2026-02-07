<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->withFullAccess()->create(['type' => 'internal']);
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $this->estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $this->internalUser->id,
        'status' => 'draft',
    ]);
});

it('cannot open send modal without creator signature', function () {
    Livewire::actingAs($this->internalUser)
        ->test('estimates.show', ['estimate' => $this->estimate])
        ->call('openSendModal')
        ->assertSet('showSendModal', false);

    expect($this->estimate->fresh()->status)->toBe('draft');
});

it('can open send modal when creator has signature', function () {
    $this->internalUser->internalUser->update([
        'signature_data' => 'John Smith',
        'signature_type' => 'typed',
        'signature_updated_at' => now(),
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('estimates.show', ['estimate' => $this->estimate])
        ->call('openSendModal')
        ->assertSet('showSendModal', true);
});

it('can send proposal when creator has signature', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $this->internalUser->internalUser->update([
        'signature_data' => 'John Smith',
        'signature_type' => 'typed',
        'signature_updated_at' => now(),
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('estimates.show', ['estimate' => $this->estimate])
        ->call('sendProposal');

    expect($this->estimate->fresh()->status)->toBe('sent');
});

it('cannot send proposal without signature', function () {
    \Illuminate\Support\Facades\Notification::fake();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.show', ['estimate' => $this->estimate])
        ->set('showSendModal', true)
        ->call('sendProposal')
        ->assertSet('showSendModal', false);

    expect($this->estimate->fresh()->status)->toBe('draft');
    \Illuminate\Support\Facades\Notification::assertNothingSent();
});

it('model hasCreatorSignature returns false when no signature', function () {
    $this->actingAs($this->internalUser);
    expect($this->estimate->hasCreatorSignature())->toBeFalse();
});

it('model hasCreatorSignature returns true when signature exists', function () {
    $this->actingAs($this->internalUser);
    $this->internalUser->internalUser->update([
        'signature_data' => 'John Smith',
        'signature_type' => 'typed',
        'signature_updated_at' => now(),
    ]);

    expect($this->estimate->fresh()->hasCreatorSignature())->toBeTrue();
});

it('model hasCreatorSignature returns false for drawn signature with only type', function () {
    $this->actingAs($this->internalUser);
    $this->internalUser->internalUser->update([
        'signature_data' => null,
        'signature_type' => 'drawn',
    ]);

    expect($this->estimate->fresh()->hasCreatorSignature())->toBeFalse();
});

it('model hasCreatorSignature returns true for drawn signature with data', function () {
    $this->actingAs($this->internalUser);
    $this->internalUser->internalUser->update([
        'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        'signature_type' => 'drawn',
        'signature_updated_at' => now(),
    ]);

    expect($this->estimate->fresh()->hasCreatorSignature())->toBeTrue();
});

it('hasCreatorSignature checks the authenticated user not the creator', function () {
    // Estimate created by internalUser (no signature)
    // A different user WITH a signature tries to send
    $sender = User::factory()->withFullAccess()->create(['type' => 'internal']);
    $sender->internalUser->update([
        'signature_data' => 'Sender Name',
        'signature_type' => 'typed',
        'signature_updated_at' => now(),
    ]);

    $this->actingAs($sender);
    expect($this->estimate->hasCreatorSignature())->toBeTrue();
});
