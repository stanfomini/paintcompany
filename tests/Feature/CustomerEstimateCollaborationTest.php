<?php

use App\Events\CustomerContributionAdded;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

// Test: customer can view their estimate collaboration page
it('shows estimate collaboration page to customer', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.estimates.show', $estimate))
        ->assertSuccessful();
});

// Test: customer can view draft estimates (collaboration mode)
it('allows customer to view draft estimates', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.estimates.show', $estimate))
        ->assertSuccessful();
});

// Test: customer cannot view other customer's estimates
it('denies access to other customer estimates', function () {
    $user = User::factory()->customer()->create();
    Customer::factory()->create(['user_id' => $user->id]);

    $otherCustomer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $otherCustomer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.estimates.show', $estimate))
        ->assertNotFound();
});

// Test: customer can save notes
it('saves customer notes on estimate', function () {
    Event::fake([CustomerContributionAdded::class]);

    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->set('customerNotes', 'I want light blue accent walls in the living room')
        ->call('saveNotes');

    expect($estimate->fresh()->customer_notes)->toBe('I want light blue accent walls in the living room');
});

// Test: customer can save checklist
it('saves customer checklist', function () {
    Event::fake([CustomerContributionAdded::class]);

    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->set('customerChecklist', ['accent_walls', 'color_consultation'])
        ->call('saveChecklist');

    expect($estimate->fresh()->customer_checklist)->toBe(['accent_walls', 'color_consultation']);
});

// Test: customer can save listing URL
it('saves listing URL to deal', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->set('listingUrl', 'https://www.zillow.com/homedetails/123-main-st/12345678_zpid/')
        ->call('saveListing')
        ->assertHasNoErrors();

    expect($deal->fresh()->listing_url)->toBe('https://www.zillow.com/homedetails/123-main-st/12345678_zpid/');
});

// Test: scope tab doesn't show pricing
it('does not expose pricing data to customer', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
        'total' => 5000.00,
        'subtotal' => 5000.00,
    ]);

    // Only check that the component doesn't assert see the subtotal in draft mode
    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->set('activeTab', 'scope')
        ->assertDontSee('5,000.00');
});

// Test: proposal tab visible only for sent/accepted
it('shows proposal tab for sent estimates', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create([
        'public_token' => 'test-token',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->assertSee('Proposal');
});

// Test: draft estimate does not show proposal tab
it('hides proposal tab for draft estimates', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->assertDontSee('Proposal');
});

// Test: fires event when contribution added
it('fires customer contribution event on notes save', function () {
    Event::fake([CustomerContributionAdded::class]);

    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate])
        ->set('customerNotes', 'Some notes')
        ->call('saveNotes');

    Event::assertDispatched(CustomerContributionAdded::class, function ($event) use ($estimate) {
        return $event->estimateId === $estimate->id && $event->type === 'notes';
    });
});

// Issue 1: Dynamic checklists - interior-only hides exterior options
it('filters customer checklist options for interior projects', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
        'project_type' => 'interior',
    ]);

    $component = Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate]);

    $options = $component->instance()->getCustomerChecklistOptions();

    // Interior options should be present
    expect($options)->toHaveKey('accent_walls');
    expect($options)->toHaveKey('color_consultation');
    expect($options)->toHaveKey('cabinet_painting');

    // Exterior options should NOT be present
    expect($options)->not->toHaveKey('deck_staining');
    expect($options)->not->toHaveKey('pressure_washing');
    expect($options)->not->toHaveKey('garage_floor');
});

it('filters customer checklist options for exterior projects', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
        'project_type' => 'exterior',
    ]);

    $component = Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate]);

    $options = $component->instance()->getCustomerChecklistOptions();

    // Exterior options should be present
    expect($options)->toHaveKey('deck_staining');
    expect($options)->toHaveKey('pressure_washing');
    expect($options)->toHaveKey('color_consultation');

    // Interior options should NOT be present
    expect($options)->not->toHaveKey('accent_walls');
    expect($options)->not->toHaveKey('cabinet_painting');
    expect($options)->not->toHaveKey('wallpaper_removal');
});

it('shows all checklist options when project type is both', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
        'project_type' => 'both',
    ]);

    $component = Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate]);

    $options = $component->instance()->getCustomerChecklistOptions();

    // Both interior and exterior should be present
    expect($options)->toHaveKey('accent_walls');
    expect($options)->toHaveKey('deck_staining');
    expect($options)->toHaveKey('color_consultation');
});

it('filters area checklist by scope type', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
        'project_type' => 'interior',
    ]);

    $component = Livewire::actingAs($user, 'customer')
        ->test('customer.estimates.show', ['estimate' => $estimate]);

    // Interior area checklist
    $interiorOptions = $component->instance()->getAreaChecklistOptions('interior');
    expect($interiorOptions)->toHaveKey('has_wallpaper');
    expect($interiorOptions)->toHaveKey('needs_repair');
    expect($interiorOptions)->not->toHaveKey('wood_rot');

    // Exterior area checklist
    $exteriorOptions = $component->instance()->getAreaChecklistOptions('exterior');
    expect($exteriorOptions)->toHaveKey('wood_rot');
    expect($exteriorOptions)->toHaveKey('needs_repair');
    expect($exteriorOptions)->not->toHaveKey('has_wallpaper');
});
