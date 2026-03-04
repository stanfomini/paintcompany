<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->agentUser = User::factory()->agent()->create();
    $this->customerUser = User::factory()->customer()->create();
});

it('internal users can access booking settings page', function () {
    $this->actingAs($this->internalUser)
        ->get(route('settings.booking'))
        ->assertSuccessful()
        ->assertSee('Booking Settings');
});

it('agent users cannot access booking settings page', function () {
    $this->actingAs($this->agentUser)
        ->get(route('settings.booking'))
        ->assertForbidden();
});

it('customer users cannot access booking settings page', function () {
    $this->actingAs($this->customerUser)
        ->get(route('settings.booking'))
        ->assertForbidden();
});

it('guests are redirected to login', function () {
    $this->get(route('settings.booking'))
        ->assertRedirect(route('login'));
});

it('loads current booking settings on mount', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'booking_enabled' => true,
        'booking_slot_duration_minutes' => 90,
        'booking_buffer_minutes' => 30,
        'booking_lookahead_days' => 14,
        'booking_min_notice_hours' => 48,
    ]);
    CompanySetting::clearInstanceCache();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->assertSet('bookingEnabled', true)
        ->assertSet('slotDuration', '90')
        ->assertSet('bufferMinutes', 30)
        ->assertSet('lookaheadDays', 14)
        ->assertSet('minNoticeHours', 48);
});

it('can save booking settings', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->set('bookingEnabled', true)
        ->set('slotDuration', '30')
        ->set('bufferMinutes', 20)
        ->set('lookaheadDays', 21)
        ->set('minNoticeHours', 12)
        ->call('save')
        ->assertSet('saved', true);

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->booking_enabled)->toBeTrue()
        ->and($settings->booking_slot_duration_minutes)->toBe(30)
        ->and($settings->booking_buffer_minutes)->toBe(20)
        ->and($settings->booking_lookahead_days)->toBe(21)
        ->and($settings->booking_min_notice_hours)->toBe(12);
});

it('can save bookable estimator ids', function () {
    $estimator1 = User::factory()->internal()->create();
    $estimator2 = User::factory()->internal()->create();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->set('bookableEstimatorIds', [$estimator1->id, $estimator2->id])
        ->call('save')
        ->assertSet('saved', true);

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->getBookableEstimatorIds())
        ->toContain($estimator1->id)
        ->toContain($estimator2->id);
});

it('validates slot duration must be valid option', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->set('slotDuration', '45')
        ->call('save')
        ->assertHasErrors('slotDuration');
});

it('validates buffer minutes range', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->set('bufferMinutes', 999)
        ->call('save')
        ->assertHasErrors('bufferMinutes');
});

it('validates lookahead days range', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->set('lookaheadDays', 200)
        ->call('save')
        ->assertHasErrors('lookaheadDays');
});

it('validates min notice hours range', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->set('minNoticeHours', 500)
        ->call('save')
        ->assertHasErrors('minNoticeHours');
});

it('lists internal users for estimator selection', function () {
    $estimator = User::factory()->internal()->create(['name' => 'Test Estimator']);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.booking-config')
        ->assertSee('Test Estimator');
});
