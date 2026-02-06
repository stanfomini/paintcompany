<?php

use App\Models\User;
use Livewire\Livewire;

it('displays crew settings page for authenticated crew', function () {
    $crew = User::factory()->crew()->create();

    $this->actingAs($crew, 'crew')
        ->get(route('crew.settings'))
        ->assertOk()
        ->assertSeeLivewire('crew.settings');
});

it('loads crew profile data on mount', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update([
        'company_name' => 'Test Crew LLC',
        'phone' => '555-123-4567',
        'crew_size' => 5,
        'hourly_rate' => 35.00,
        'bio' => 'We are the best painters.',
        'specialties' => ['Interior Painting', 'Exterior Painting'],
    ]);

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->assertSet('company_name', 'Test Crew LLC')
        ->assertSet('phone', '555-123-4567')
        ->assertSet('crew_size', 5)
        ->assertSet('hourly_rate', '35.00')
        ->assertSet('bio', 'We are the best painters.')
        ->assertSet('specialties', ['Interior Painting', 'Exterior Painting']);
});

it('can update crew size', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['crew_size' => 2]);

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('crew_size', 4)
        ->call('save')
        ->assertHasNoErrors();

    expect($crew->crewProfile->fresh()->crew_size)->toBe(4);
});

it('can update company name', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('company_name', 'Elite Painters Inc')
        ->call('save')
        ->assertHasNoErrors();

    expect($crew->crewProfile->fresh()->company_name)->toBe('Elite Painters Inc');
});

it('can update phone number', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('phone', '555-987-6543')
        ->call('save')
        ->assertHasNoErrors();

    expect($crew->crewProfile->fresh()->phone)->toBe('555-987-6543');
});

it('can update hourly rate', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('hourly_rate', '45.50')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $crew->crewProfile->fresh()->hourly_rate)->toBe(45.50);
});

it('can update bio', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('bio', 'Experienced painters with 10 years in business.')
        ->call('save')
        ->assertHasNoErrors();

    expect($crew->crewProfile->fresh()->bio)->toBe('Experienced painters with 10 years in business.');
});

it('can toggle specialties', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['specialties' => []]);

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->assertSet('specialties', [])
        ->call('toggleSpecialty', 'Interior Painting')
        ->assertSet('specialties', ['Interior Painting'])
        ->call('toggleSpecialty', 'Exterior Painting')
        ->assertSet('specialties', ['Interior Painting', 'Exterior Painting'])
        ->call('toggleSpecialty', 'Interior Painting')
        ->assertSet('specialties', ['Exterior Painting']);
});

it('can save specialties', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->call('toggleSpecialty', 'Cabinet Refinishing')
        ->call('toggleSpecialty', 'Deck Staining')
        ->call('save')
        ->assertHasNoErrors();

    $specialties = $crew->crewProfile->fresh()->specialties;
    expect($specialties)->toContain('Cabinet Refinishing')
        ->and($specialties)->toContain('Deck Staining');
});

it('requires company name', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('company_name', '')
        ->call('save')
        ->assertHasErrors(['company_name' => 'required']);
});

it('requires crew size to be at least 1', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('crew_size', 0)
        ->call('save')
        ->assertHasErrors(['crew_size' => 'min']);
});

it('validates crew size max is 50', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('crew_size', 51)
        ->call('save')
        ->assertHasErrors(['crew_size' => 'max']);
});

it('saves all settings together', function () {
    $crew = User::factory()->crew()->create();

    Livewire::actingAs($crew, 'crew')
        ->test('crew.settings')
        ->set('company_name', 'Full Save Test LLC')
        ->set('phone', '555-000-1111')
        ->set('crew_size', 6)
        ->set('hourly_rate', '30.00')
        ->set('bio', 'Full save test bio')
        ->call('toggleSpecialty', 'Commercial')
        ->call('save')
        ->assertHasNoErrors();

    $profile = $crew->crewProfile->fresh();
    expect($profile->company_name)->toBe('Full Save Test LLC')
        ->and($profile->phone)->toBe('555-000-1111')
        ->and($profile->crew_size)->toBe(6)
        ->and((float) $profile->hourly_rate)->toBe(30.00)
        ->and($profile->bio)->toBe('Full save test bio')
        ->and($profile->specialties)->toContain('Commercial');
});

it('settings link appears in crew navigation', function () {
    $crew = User::factory()->crew()->create();

    $this->actingAs($crew, 'crew')
        ->get(route('crew.dashboard'))
        ->assertSee('Settings');
});
