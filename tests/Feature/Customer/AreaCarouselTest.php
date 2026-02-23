<?php

use App\Events\CustomerContributionAdded;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->forCustomer($this->customer)->create();
    $this->estimate = Estimate::factory()->forDeal($this->deal)->sent()->create();
});

describe('Area Carousel - Rendering', function () {
    it('renders the carousel for an authenticated customer', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Living Room']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertStatus(200)
            ->assertSee('Living Room');
    });

    it('shows the first area by default', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen', 'sort_order' => 0]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Bedroom', 'sort_order' => 1]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('currentAreaIndex', 0)
            ->assertSee('Kitchen')
            ->assertSee('1 / 2');
    });

    it('shows empty state when no areas exist', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSee('No Rooms Yet');
    });

    it('shows the first-time guide overlay by default', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('showGuide', true)
            ->assertSee('Review Your Rooms')
            ->assertSee('Got It');
    });

    it('denies access when customer does not own the deal', function () {
        $otherCustomer = Customer::factory()->create();
        $otherDeal = Deal::factory()->forCustomer($otherCustomer)->create();
        $otherEstimate = Estimate::factory()->forDeal($otherDeal)->sent()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $otherEstimate])
            ->assertForbidden();
    });
});

describe('Area Carousel - Navigation', function () {
    it('navigates to the next area', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen', 'sort_order' => 0]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Bedroom', 'sort_order' => 1]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('currentAreaIndex', 0)
            ->call('nextArea')
            ->assertSet('currentAreaIndex', 1)
            ->assertSee('Bedroom');
    });

    it('navigates to the previous area', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen', 'sort_order' => 0]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Bedroom', 'sort_order' => 1]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('nextArea')
            ->assertSet('currentAreaIndex', 1)
            ->call('previousArea')
            ->assertSet('currentAreaIndex', 0)
            ->assertSee('Kitchen');
    });

    it('does not go below zero on previousArea', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('currentAreaIndex', 0)
            ->call('previousArea')
            ->assertSet('currentAreaIndex', 0);
    });

    it('does not go past the last area on nextArea', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen', 'sort_order' => 0]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Bedroom', 'sort_order' => 1]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('nextArea')
            ->assertSet('currentAreaIndex', 1)
            ->call('nextArea')
            ->assertSet('currentAreaIndex', 1);
    });

    it('jumps to a specific area with goToArea', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen', 'sort_order' => 0]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Bedroom', 'sort_order' => 1]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Office', 'sort_order' => 2]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('goToArea', 2)
            ->assertSet('currentAreaIndex', 2)
            ->assertSee('Office');
    });

    it('ignores invalid goToArea index', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('goToArea', 5)
            ->assertSet('currentAreaIndex', 0);
    });
});

describe('Area Carousel - Notes', function () {
    it('saves area customer notes', function () {
        Event::fake([CustomerContributionAdded::class]);
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->set("areaCustomerNotes.{$area->id}", 'I want light sage green walls')
            ->call('saveAreaNotes', $area->id);

        expect($area->fresh()->customer_notes)->toBe('I want light sage green walls');
        Event::assertDispatched(CustomerContributionAdded::class);
    });

    it('loads existing area notes on mount', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Kitchen',
            'customer_notes' => 'Existing note from before',
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet("areaCustomerNotes.{$area->id}", 'Existing note from before');
    });
});

describe('Area Carousel - Checklists', function () {
    it('saves area customer checklist', function () {
        Event::fake([CustomerContributionAdded::class]);
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->set("areaCustomerChecklists.{$area->id}", ['needs_repair', 'water_damage'])
            ->call('saveAreaChecklist', $area->id);

        expect($area->fresh()->customer_checklist)->toBe(['needs_repair', 'water_damage']);
        Event::assertDispatched(CustomerContributionAdded::class);
    });

    it('loads existing checklists on mount', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Kitchen',
            'customer_checklist' => ['peeling_paint'],
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet("areaCustomerChecklists.{$area->id}", ['peeling_paint']);
    });
});

describe('Area Carousel - Checklist Groups', function () {
    it('returns general and interior groups for interior scope', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['scope_type' => 'interior']);

        $component = Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate]);

        $groups = $component->call('getAreaChecklistGroups', 'interior')->json();
        // Should have General + Interior, but NOT Exterior
        $component->assertSee('General')
            ->assertSee('Interior')
            ->assertSee('Needs repair work')
            ->assertSee('Has wallpaper to remove');
    });

    it('returns general and exterior groups for exterior scope', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['scope_type' => 'exterior']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSee('General')
            ->assertSee('Exterior')
            ->assertSee('Wood rot present');
    });

    it('returns all groups for both scope', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['scope_type' => 'both']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSee('General')
            ->assertSee('Interior')
            ->assertSee('Exterior');
    });
});

describe('Area Carousel - Guide', function () {
    it('dismisses the guide', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('showGuide', true)
            ->call('dismissGuide')
            ->assertSet('showGuide', false);
    });
});

describe('Area Carousel - Close', function () {
    it('dispatches close event', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('close')
            ->assertDispatched('close-area-carousel');
    });
});
