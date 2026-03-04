<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\LandingPageShowcasePhoto;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// --- Auth ---

it('blocks showcase tab for non-admin users', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('media.landing-page-showcase')
        ->assertForbidden();
});

it('shows showcase tab for media.manage users', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->assertSuccessful();
});

// --- Deal browsing (only deals with reference photos) ---

it('only shows deals that have reference photos', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $propertyWithRef = Property::factory()->create();
    $dealWithRef = Deal::factory()->forProperty($propertyWithRef)->create(['title' => 'Deal With References']);
    PropertyPhoto::factory()->forProperty($propertyWithRef)->reference()->create();

    $propertyNoRef = Property::factory()->create();
    $dealNoRef = Deal::factory()->forProperty($propertyNoRef)->create(['title' => 'Deal Without References']);
    PropertyPhoto::factory()->forProperty($propertyNoRef)->before()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->assertSee('Deal With References')
        ->assertDontSee('Deal Without References');
});

it('shows deals ordered by most recent first', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $oldProperty = Property::factory()->create();
    $oldDeal = Deal::factory()->forProperty($oldProperty)->create(['title' => 'Older Job', 'created_at' => now()->subMonth()]);
    PropertyPhoto::factory()->forProperty($oldProperty)->reference()->create();

    $newProperty = Property::factory()->create();
    $newDeal = Deal::factory()->forProperty($newProperty)->create(['title' => 'Recent Job', 'created_at' => now()]);
    PropertyPhoto::factory()->forProperty($newProperty)->reference()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->assertSeeInOrder(['Recent Job', 'Older Job']);
});

it('shows reference photo thumbnails on deal cards', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->create(['title' => 'Showcase Deal']);
    $refPhoto = PropertyPhoto::factory()->forProperty($property)->reference()->create(['caption' => 'Nice ref shot']);

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->assertSee('Showcase Deal')
        ->assertSee('1 reference');
});

it('searches deals by customer name', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $customer1 = \App\Models\Customer::factory()->create(['first_name' => 'Sarah', 'last_name' => 'Johnson']);
    $property1 = Property::factory()->create(['customer_id' => $customer1->id]);
    $deal1 = Deal::factory()->forProperty($property1)->create(['title' => 'Deal One', 'customer_id' => $customer1->id]);
    PropertyPhoto::factory()->forProperty($property1)->reference()->create();

    $customer2 = \App\Models\Customer::factory()->create(['first_name' => 'Mike', 'last_name' => 'Thompson']);
    $property2 = Property::factory()->create(['customer_id' => $customer2->id]);
    $deal2 = Deal::factory()->forProperty($property2)->create(['title' => 'Deal Two', 'customer_id' => $customer2->id]);
    PropertyPhoto::factory()->forProperty($property2)->reference()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->set('dealSearch', 'Sarah')
        ->assertSee('Deal One')
        ->assertDontSee('Deal Two');
});

it('searches deals by customer phone', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $customer = \App\Models\Customer::factory()->create(['phone' => '303-555-1234']);
    $property = Property::factory()->create(['customer_id' => $customer->id]);
    $deal = Deal::factory()->forProperty($property)->create(['title' => 'Phone Deal', 'customer_id' => $customer->id]);
    PropertyPhoto::factory()->forProperty($property)->reference()->create();

    $property2 = Property::factory()->create();
    $deal2 = Deal::factory()->forProperty($property2)->create(['title' => 'Other Deal']);
    PropertyPhoto::factory()->forProperty($property2)->reference()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->set('dealSearch', '303-555')
        ->assertSee('Phone Deal')
        ->assertDontSee('Other Deal');
});

it('searches deals by property address', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $property = Property::factory()->create(['address' => '456 Oak Avenue']);
    $deal = Deal::factory()->forProperty($property)->create(['title' => 'Oak Ave Deal']);
    PropertyPhoto::factory()->forProperty($property)->reference()->create();

    $property2 = Property::factory()->create(['address' => '789 Pine St']);
    $deal2 = Deal::factory()->forProperty($property2)->create(['title' => 'Pine St Deal']);
    PropertyPhoto::factory()->forProperty($property2)->reference()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->set('dealSearch', 'Oak Ave')
        ->assertSee('Oak Ave Deal')
        ->assertDontSee('Pine St Deal');
});

// --- Photo browsing (all photos from deal) ---

it('shows all photos from property when deal is selected', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->create();

    PropertyPhoto::factory()->forProperty($property)->reference()->create(['caption' => 'Reference shot']);
    PropertyPhoto::factory()->forProperty($property)->before()->create(['caption' => 'Before shot']);
    PropertyPhoto::factory()->forProperty($property)->after()->create(['caption' => 'After shot']);

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->call('selectDeal', $deal->id)
        ->assertSee('Reference shot')
        ->assertSee('Before shot')
        ->assertSee('After shot');
});

it('shows photos from estimates when deal is selected', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->create();
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

    PropertyPhoto::factory()->forProperty($property)->reference()->create(['caption' => 'Ref photo']);
    PropertyPhoto::factory()->forProperty($property)->forEstimate($estimate)->create(['caption' => 'Estimate photo']);

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->call('selectDeal', $deal->id)
        ->assertSee('Ref photo')
        ->assertSee('Estimate photo');
});

// --- Pair CRUD ---

it('adds a before/after pair from deal photos', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->create();

    $beforePhoto = PropertyPhoto::factory()->forProperty($property)->before()->create();
    $afterPhoto = PropertyPhoto::factory()->forProperty($property)->after()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->call('selectDeal', $deal->id)
        ->call('selectBefore', $beforePhoto->id)
        ->call('selectAfter', $afterPhoto->id)
        ->call('savePair');

    expect(LandingPageShowcasePhoto::count())->toBe(1);

    $pair = LandingPageShowcasePhoto::first();
    expect($pair->before_photo_path)->toBe($beforePhoto->path);
    expect($pair->after_photo_path)->toBe($afterPhoto->path);
    expect($pair->deal_id)->toBe($deal->id);
    expect($pair->is_active)->toBeTrue();
});

it('toggles a photo selection off when clicked again', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->create();
    $photo = PropertyPhoto::factory()->forProperty($property)->before()->create();

    $component = Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->call('selectDeal', $deal->id)
        ->call('selectBefore', $photo->id);

    expect($component->get('selectedBeforePhotoId'))->toBe($photo->id);

    $component->call('selectBefore', $photo->id);

    expect($component->get('selectedBeforePhotoId'))->toBeNull();
});

it('toggles showcase pair active state', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $pair = LandingPageShowcasePhoto::factory()->create();

    expect($pair->is_active)->toBeTrue();

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('togglePair', $pair->id);

    $pair->refresh();
    expect($pair->is_active)->toBeFalse();
});

it('removes a showcase pair', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $pair = LandingPageShowcasePhoto::factory()->create();

    expect(LandingPageShowcasePhoto::count())->toBe(1);

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('removePair', $pair->id);

    expect(LandingPageShowcasePhoto::count())->toBe(0);
});

it('updates caption on a showcase pair', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $pair = LandingPageShowcasePhoto::factory()->create(['caption' => null]);

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('updateCaption', $pair->id, 'Kitchen makeover');

    $pair->refresh();
    expect($pair->caption)->toBe('Kitchen makeover');
});

it('clears caption when empty string is provided', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $pair = LandingPageShowcasePhoto::factory()->create(['caption' => 'Old caption']);

    Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('updateCaption', $pair->id, '');

    $pair->refresh();
    expect($pair->caption)->toBeNull();
});

it('navigates back to deal list from photo view', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create(['title' => 'Test Deal']);

    $component = Livewire::actingAs($admin)
        ->test('media.landing-page-showcase')
        ->call('openPicker')
        ->call('selectDeal', $deal->id);

    expect($component->get('selectedDealId'))->toBe($deal->id);

    $component->call('backToDeals');

    expect($component->get('selectedDealId'))->toBeNull();
});

// --- Public landing page ---

it('renders active showcase photos on public landing page', function () {
    $pair = LandingPageShowcasePhoto::factory()->create([
        'caption' => 'Beautiful kitchen transformation',
        'is_active' => true,
    ]);

    Livewire::test('public.landing-page')
        ->assertSee('Beautiful kitchen transformation');
});

it('does not render inactive showcase photos on public landing page', function () {
    LandingPageShowcasePhoto::factory()->inactive()->create([
        'caption' => 'Hidden showcase pair',
    ]);

    Livewire::test('public.landing-page')
        ->assertDontSee('Hidden showcase pair');
});

it('falls back to placeholder when no active showcase photos exist', function () {
    Livewire::test('public.landing-page')
        ->assertSee(__('Before'))
        ->assertSee(__('After'));
});
