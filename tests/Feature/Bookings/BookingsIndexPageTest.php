<?php

use App\Models\AppointmentBooking;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->agentUser = User::factory()->agent()->create();
    $this->customerUser = User::factory()->customer()->create();
});

it('internal users can access bookings index page', function () {
    $this->actingAs($this->internalUser)
        ->get(route('bookings.index'))
        ->assertSuccessful()
        ->assertSee('Bookings');
});

it('agent users cannot access bookings index page', function () {
    $response = $this->actingAs($this->agentUser)
        ->get(route('bookings.index'));

    // auth.internal middleware redirects non-internal users
    expect($response->status())->toBeIn([302, 403]);
});

it('customer users cannot access bookings index page', function () {
    $response = $this->actingAs($this->customerUser)
        ->get(route('bookings.index'));

    // auth.internal middleware redirects non-internal users
    expect($response->status())->toBeIn([302, 403]);
});

it('guests are redirected to login', function () {
    $this->get(route('bookings.index'))
        ->assertRedirect(route('login'));
});

it('displays bookings in the table', function () {
    $booking = AppointmentBooking::factory()->create([
        'prospect_first_name' => 'Jane',
        'prospect_last_name' => 'Doe',
        'prospect_email' => 'jane@example.com',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->assertSee('Jane Doe')
        ->assertSee('jane@example.com');
});

it('can filter by status', function () {
    AppointmentBooking::factory()->confirmed()->create([
        'prospect_first_name' => 'Confirmed',
        'prospect_last_name' => 'Person',
    ]);
    AppointmentBooking::factory()->cancelled()->create([
        'prospect_first_name' => 'Cancelled',
        'prospect_last_name' => 'Person',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->set('statusFilter', 'confirmed')
        ->assertSee('Confirmed Person')
        ->assertDontSee('Cancelled Person');
});

it('can search by prospect name', function () {
    AppointmentBooking::factory()->create([
        'prospect_first_name' => 'Alice',
        'prospect_last_name' => 'Smith',
    ]);
    AppointmentBooking::factory()->create([
        'prospect_first_name' => 'Bob',
        'prospect_last_name' => 'Jones',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->set('search', 'Alice')
        ->assertSee('Alice Smith')
        ->assertDontSee('Bob Jones');
});

it('can mark a booking as completed', function () {
    $booking = AppointmentBooking::factory()->confirmed()->create();

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->call('markCompleted', $booking->id);

    expect($booking->fresh()->status)->toBe(AppointmentBooking::STATUS_COMPLETED);
});

it('can mark a booking as no-show', function () {
    $booking = AppointmentBooking::factory()->confirmed()->create();

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->call('markNoShow', $booking->id);

    expect($booking->fresh()->status)->toBe(AppointmentBooking::STATUS_NO_SHOW);
});

it('can cancel a booking with reason', function () {
    $booking = AppointmentBooking::factory()->confirmed()->create();

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->call('openCancelModal', $booking->id)
        ->assertSet('showCancelModal', true)
        ->set('cancellationReason', 'Prospect requested cancellation')
        ->call('confirmCancel')
        ->assertSet('showCancelModal', false);

    $booking->refresh();
    expect($booking->status)->toBe(AppointmentBooking::STATUS_CANCELLED)
        ->and($booking->cancellation_reason)->toBe('Prospect requested cancellation');
});

it('shows empty state when no bookings exist', function () {
    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->assertSee('No bookings found');
});

it('can filter by date range', function () {
    AppointmentBooking::factory()->create([
        'prospect_first_name' => 'Early',
        'prospect_last_name' => 'Bird',
        'booked_date' => '2026-03-01',
    ]);
    AppointmentBooking::factory()->create([
        'prospect_first_name' => 'Late',
        'prospect_last_name' => 'Comer',
        'booked_date' => '2026-04-15',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('bookings.index')
        ->set('dateFrom', '2026-04-01')
        ->set('dateTo', '2026-04-30')
        ->assertSee('Late Comer')
        ->assertDontSee('Early Bird');
});
