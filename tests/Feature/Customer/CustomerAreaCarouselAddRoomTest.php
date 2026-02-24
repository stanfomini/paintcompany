<?php

use App\Events\CustomerContributionAdded;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->forCustomer($this->customer)->create();
    $this->estimate = Estimate::factory()->forDeal($this->deal)->sent()->create();

    $this->category = EstimateTemplateCategory::factory()->room()->create();
    $this->template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->category->id,
        'name' => 'Living Room',
        'scope_type' => 'interior',
        'is_active' => true,
        'is_configurable' => true,
        'metadata' => [
            'sizes' => [
                'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                'medium' => ['sqft' => 225, 'label' => 'Medium (225 sqft)'],
                'large' => ['sqft' => 350, 'label' => 'Large (350 sqft)'],
            ],
            'default_size' => 'medium',
        ],
    ]);
});

describe('Area Carousel - Add Room', function () {
    it('can add a room from template when no estimator areas exist', function () {
        Event::fake([CustomerContributionAdded::class]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('addRoom', $this->template->id, 'medium');

        $area = $this->estimate->fresh()->areas->first();
        expect($area)->not->toBeNull();
        expect($area->name)->toBe('Living Room');
        expect($area->scope_type)->toBe('interior');
        expect($area->measurement_type)->toBe('sqft');
        expect((float) $area->direct_sqft)->toBe(225.0);
        expect($area->is_customer_added)->toBeTrue();

        Event::assertDispatched(CustomerContributionAdded::class);
    });

    it('creates area with correct size for small selection', function () {
        Event::fake([CustomerContributionAdded::class]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('addRoom', $this->template->id, 'small');

        $area = $this->estimate->fresh()->areas->first();
        expect((float) $area->direct_sqft)->toBe(150.0);
    });

    it('creates area with correct size for large selection', function () {
        Event::fake([CustomerContributionAdded::class]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('addRoom', $this->template->id, 'large');

        $area = $this->estimate->fresh()->areas->first();
        expect((float) $area->direct_sqft)->toBe(350.0);
    });

    it('navigates to new room after adding', function () {
        Event::fake([CustomerContributionAdded::class]);

        $component = Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('addRoom', $this->template->id, 'medium');

        $component->assertSet('currentAreaIndex', 0);
    });

    it('reports canAddRooms true when no estimator areas exist', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('canAddRooms', true);
    });

    it('reports canAddRooms false when estimator areas exist', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['is_customer_added' => false]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('canAddRooms', false);
    });

    it('can remove a customer-added room', function () {
        Event::fake([CustomerContributionAdded::class]);

        $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Customer Room',
            'is_customer_added' => true,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('removeCustomerRoom', $area->id);

        expect(EstimateArea::find($area->id))->toBeNull();
        Event::assertDispatched(CustomerContributionAdded::class);
    });

    it('cannot remove an estimator-created room', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Estimator Room',
            'is_customer_added' => false,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('removeCustomerRoom', $area->id);

        expect(EstimateArea::find($area->id))->not->toBeNull();
    });
});

describe('Area Carousel - Tabs', function () {
    it('defaults to details tab', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->assertSet('activeTab', 'details');
    });

    it('can switch between tabs', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('switchTab', 'photos')
            ->assertSet('activeTab', 'photos')
            ->call('switchTab', 'notes')
            ->assertSet('activeTab', 'notes')
            ->call('switchTab', 'details')
            ->assertSet('activeTab', 'details');
    });

    it('resets tab when navigating areas', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create(['sort_order' => 0]);
        EstimateArea::factory()->forEstimate($this->estimate)->create(['sort_order' => 1]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $this->estimate])
            ->call('switchTab', 'photos')
            ->assertSet('activeTab', 'photos')
            ->call('nextArea')
            ->assertSet('activeTab', 'details');
    });
});
