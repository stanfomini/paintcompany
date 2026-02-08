<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateOption;
use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

describe('Estimate Options Service Category', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->user->id,
        ]);
        $this->painting = ServiceCategory::firstOrCreate(
            ['slug' => 'painting'],
            ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
        );
        $this->cleaning = ServiceCategory::firstOrCreate(
            ['slug' => 'cleaning'],
            ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
        );
    });

    it('can create an estimate option with a service category', function () {
        // Use per_surface pricing to avoid multiplier null constraint
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create();
        $surface = EstimateAreaSurface::factory()->forArea($area)->wall()->create([
            'quantity' => 200,
            'total' => 1000.00,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.options-manager', ['estimate' => $this->estimate])
            ->call('openModal')
            ->set('optionName', 'Post-Paint Cleanup')
            ->set('optionPricingType', 'per_surface')
            ->set('optionSurfaceId', $surface->id)
            ->set('optionMultiplier', 1.5)
            ->set('optionServiceCategoryId', $this->cleaning->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $option = EstimateOption::where('estimate_id', $this->estimate->id)
            ->where('name', 'Post-Paint Cleanup')
            ->first();

        expect($option)->not->toBeNull();
        expect($option->service_category_id)->toBe($this->cleaning->id);
        expect($option->serviceCategory->slug)->toBe('cleaning');
    });

    it('allows null service_category_id for painting-default options', function () {
        $option = EstimateOption::create([
            'estimate_id' => $this->estimate->id,
            'name' => 'Premium Paint Upgrade',
            'pricing_type' => 'fixed',
            'fixed_price' => 200,
            'multiplier' => 1,
            'is_selected' => false,
            'is_recommended' => false,
            'sort_order' => 0,
            'service_category_id' => null,
        ]);

        expect($option->service_category_id)->toBeNull();
    });

    it('loads service category when editing an option', function () {
        $option = EstimateOption::factory()->forEstimate($this->estimate)->create([
            'name' => 'Deep Cleaning',
            'service_category_id' => $this->cleaning->id,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.options-manager', ['estimate' => $this->estimate])
            ->call('openModal', $option->id)
            ->assertSet('optionServiceCategoryId', $this->cleaning->id)
            ->assertSet('optionName', 'Deep Cleaning');
    });

    it('shows category badge in options list', function () {
        EstimateOption::factory()->forEstimate($this->estimate)->create([
            'name' => 'Deep Cleaning',
            'service_category_id' => $this->cleaning->id,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.options-manager', ['estimate' => $this->estimate])
            ->assertSee('Cleaning')
            ->assertSee('Deep Cleaning');
    });

    it('groups options by service category on public proposal', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->user->id,
        ]);
        $estimate->generatePublicToken(30);
        $estimate->save();

        EstimateOption::factory()->forEstimate($estimate)->create([
            'name' => 'Paint Upgrade',
            'service_category_id' => $this->painting->id,
        ]);
        EstimateOption::factory()->forEstimate($estimate)->create([
            'name' => 'Deep Cleaning',
            'service_category_id' => $this->cleaning->id,
        ]);

        Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
            ->assertSee('Paint Upgrade')
            ->assertSee('Deep Cleaning')
            ->assertSee('Painting')
            ->assertSee('Cleaning');
    });

    it('does not show category headers when all options are same category', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->user->id,
        ]);
        $estimate->generatePublicToken(30);
        $estimate->save();

        EstimateOption::factory()->forEstimate($estimate)->create([
            'name' => 'Paint Upgrade',
            'service_category_id' => $this->painting->id,
        ]);
        EstimateOption::factory()->forEstimate($estimate)->create([
            'name' => 'Extra Coat',
            'service_category_id' => $this->painting->id,
        ]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Both options should be visible
        $component->assertSee('Paint Upgrade')
            ->assertSee('Extra Coat');
    });

    it('updates service category on existing option', function () {
        // Use per_surface pricing to avoid multiplier null constraint
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create();
        $surface = EstimateAreaSurface::factory()->forArea($area)->wall()->create([
            'quantity' => 200,
            'total' => 1000.00,
        ]);

        $option = EstimateOption::factory()->forEstimate($this->estimate)->create([
            'name' => 'Misc Service',
            'pricing_type' => 'per_surface',
            'surface_id' => $surface->id,
            'multiplier' => 1.5,
            'service_category_id' => null,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.options-manager', ['estimate' => $this->estimate])
            ->call('openModal', $option->id)
            ->assertSet('optionServiceCategoryId', null)
            ->set('optionServiceCategoryId', $this->cleaning->id)
            ->call('save')
            ->assertHasNoErrors();

        $option->refresh();
        expect($option->service_category_id)->toBe($this->cleaning->id);
    });
});
