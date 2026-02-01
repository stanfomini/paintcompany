<?php

use App\Models\User;
use Livewire\Livewire;

it('denies access to signature settings for non-internal users', function () {
    $agentUser = User::factory()->create(['type' => 'agent']);

    $this->actingAs($agentUser)
        ->get(route('signature.edit'))
        ->assertForbidden();
});

it('allows internal users to access signature settings', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);

    $this->actingAs($internalUser)
        ->get(route('signature.edit'))
        ->assertOk();
});

it('can save a typed signature', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($internalUser)
        ->test('pages::settings.signature')
        ->set('signatureType', 'typed')
        ->set('signatureData', 'John Smith')
        ->call('saveSignature')
        ->assertDispatched('signature-saved');

    $internalUser->refresh();
    expect($internalUser->internalUser->signature_data)->toBe('John Smith');
    expect($internalUser->internalUser->signature_type)->toBe('typed');
    expect($internalUser->internalUser->signature_updated_at)->not->toBeNull();
});

it('can save a drawn signature', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);
    $base64Image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    Livewire::actingAs($internalUser)
        ->test('pages::settings.signature')
        ->set('signatureType', 'drawn')
        ->set('signatureData', $base64Image)
        ->call('saveSignature')
        ->assertDispatched('signature-saved');

    $internalUser->refresh();
    expect($internalUser->internalUser->signature_data)->toBe($base64Image);
    expect($internalUser->internalUser->signature_type)->toBe('drawn');
});

it('requires signature data when saving', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($internalUser)
        ->test('pages::settings.signature')
        ->set('signatureType', 'typed')
        ->set('signatureData', '')
        ->call('saveSignature')
        ->assertHasErrors(['signatureData']);
});

it('can clear an existing signature', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);
    $internalUser->internalUser->update([
        'signature_data' => 'John Smith',
        'signature_type' => 'typed',
        'signature_updated_at' => now(),
    ]);

    Livewire::actingAs($internalUser)
        ->test('pages::settings.signature')
        ->call('clearSignature')
        ->assertDispatched('signature-cleared');

    $internalUser->refresh();
    expect($internalUser->internalUser->signature_data)->toBeNull();
    expect($internalUser->internalUser->signature_type)->toBeNull();
    expect($internalUser->internalUser->signature_updated_at)->toBeNull();
});

it('shows existing signature on mount', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);
    $internalUser->internalUser->update([
        'signature_data' => 'Jane Doe',
        'signature_type' => 'typed',
        'signature_updated_at' => now(),
    ]);

    Livewire::actingAs($internalUser)
        ->test('pages::settings.signature')
        ->assertSet('signatureData', 'Jane Doe')
        ->assertSet('signatureType', 'typed')
        ->assertSet('hasExistingSignature', true);
});

it('shows signature link in settings nav for internal users', function () {
    $internalUser = User::factory()->create(['type' => 'internal']);

    $this->actingAs($internalUser)
        ->get(route('profile.edit'))
        ->assertSee('Signature');
});

it('does not show signature link for non-internal users', function () {
    $agentUser = User::factory()->create(['type' => 'agent']);

    $this->actingAs($agentUser)
        ->get(route('profile.edit'))
        ->assertDontSee(route('signature.edit'));
});
