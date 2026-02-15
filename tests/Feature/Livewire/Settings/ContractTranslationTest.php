<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

it('translates English content to Spanish field', function () {
    Livewire::test('pages::settings.contracts')
        ->set('terms_and_conditions', 'Hello world')
        ->call('translateToSpanish', 'terms_and_conditions')
        ->assertSet('terms_and_conditions_es', fn ($value) => ! empty($value) && is_string($value));
});

it('does nothing when English field is empty', function () {
    Livewire::test('pages::settings.contracts')
        ->set('terms_and_conditions', '')
        ->set('terms_and_conditions_es', 'existing spanish')
        ->call('translateToSpanish', 'terms_and_conditions')
        ->assertSet('terms_and_conditions_es', 'existing spanish');
});

it('does nothing when English field is only whitespace', function () {
    Livewire::test('pages::settings.contracts')
        ->set('privacy_policy', '   ')
        ->set('privacy_policy_es', 'existing')
        ->call('translateToSpanish', 'privacy_policy')
        ->assertSet('privacy_policy_es', 'existing');
});

it('translates each contract field to its Spanish counterpart', function (string $field) {
    Livewire::test('pages::settings.contracts')
        ->set($field, 'Test content')
        ->call('translateToSpanish', $field)
        ->assertSet($field.'_es', fn ($value) => ! empty($value) && is_string($value));
})->with([
    'terms_and_conditions',
    'privacy_policy',
    'referral_terms',
    'crew_colorado_compliance_contract',
    'crew_contractor_agreement',
    'crew_background_check_consent',
]);
