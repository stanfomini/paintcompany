<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->settings = CompanySetting::getInstance();

    // Ensure system allocations are created for tests
    $this->settings->ensureSystemAllocations();
});

describe('Allocations Settings Page', function () {
    test('internal user can view allocations page', function () {
        $this->actingAs($this->user)
            ->get(route('allocations.edit'))
            ->assertOk()
            ->assertSee('Business Allocations');
    });

    test('non-internal user cannot view allocations page', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('allocations.edit'))
            ->assertForbidden();
    });

    test('displays existing allocations', function () {
        // System allocations are seeded by default
        $allocation = CompanyAllocation::first();

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->assertSee($allocation->name)
            ->assertSee($allocation->formatted_value);
    });

    test('can add new custom allocation with percent type', function () {
        $initialCount = CompanyAllocation::count();

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Equipment Reserve')
            ->set('type', 'percent_of_revenue')
            ->set('value', '5')
            ->set('description', 'Monthly equipment fund')
            ->set('is_active', true)
            ->call('save');

        expect(CompanyAllocation::count())->toBe($initialCount + 1);

        $allocation = CompanyAllocation::where('name', 'Equipment Reserve')->first();
        expect($allocation)->not->toBeNull()
            ->and($allocation->type)->toBe('percent_of_revenue')
            ->and((float) $allocation->value)->toBe(5.0)
            ->and($allocation->is_system)->toBeFalse();
    });

    test('can add new custom allocation with fixed per job type', function () {
        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Growth Loan')
            ->set('type', 'fixed_per_job')
            ->set('value', '150')
            ->set('description', 'Monthly loan payment allocation')
            ->call('save');

        $allocation = CompanyAllocation::where('name', 'Growth Loan')->first();
        expect($allocation)->not->toBeNull()
            ->and($allocation->type)->toBe('fixed_per_job')
            ->and((float) $allocation->value)->toBe(150.0);
    });

    test('can add new custom allocation with per labor hour type', function () {
        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Fuel Costs')
            ->set('type', 'per_labor_hour')
            ->set('value', '3.50')
            ->call('save');

        $allocation = CompanyAllocation::where('name', 'Fuel Costs')->first();
        expect($allocation)->not->toBeNull()
            ->and($allocation->type)->toBe('per_labor_hour')
            ->and((float) $allocation->value)->toBe(3.50);
    });

    test('can edit system allocation value', function () {
        $allocation = CompanyAllocation::where('is_system', true)->first();
        $originalValue = (float) $allocation->value;

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openEditModal', $allocation->id)
            ->set('value', '10')
            ->call('save');

        $allocation->refresh();
        expect((float) $allocation->value)->toBe(10.0)
            ->and($allocation->is_system)->toBeTrue(); // Still a system allocation
    });

    test('can edit custom allocation fully', function () {
        $allocation = CompanyAllocation::factory()->create([
            'company_setting_id' => $this->settings->id,
            'name' => 'Test Allocation',
            'type' => 'percent_of_revenue',
            'value' => 5,
            'is_system' => false,
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openEditModal', $allocation->id)
            ->set('name', 'Updated Name')
            ->set('type', 'fixed_per_job')
            ->set('value', '200')
            ->call('save');

        $allocation->refresh();
        expect($allocation->name)->toBe('Updated Name')
            ->and($allocation->type)->toBe('fixed_per_job')
            ->and((float) $allocation->value)->toBe(200.0);
    });

    test('can delete custom allocation', function () {
        $allocation = CompanyAllocation::factory()->create([
            'company_setting_id' => $this->settings->id,
            'name' => 'Delete Me',
            'is_system' => false,
        ]);
        $id = $allocation->id;

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('delete', $id);

        expect(CompanyAllocation::find($id))->toBeNull();
    });

    test('cannot delete system allocation', function () {
        $allocation = CompanyAllocation::where('is_system', true)->first();
        $id = $allocation->id;

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('delete', $id);

        // Should still exist
        expect(CompanyAllocation::find($id))->not->toBeNull();
    });

    test('can toggle allocation active status', function () {
        $allocation = CompanyAllocation::first();
        $originalStatus = $allocation->is_active;

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('toggleActive', $allocation->id);

        $allocation->refresh();
        expect($allocation->is_active)->toBe(! $originalStatus);
    });

    test('validation requires name', function () {
        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', '')
            ->set('type', 'percent_of_revenue')
            ->set('value', '5')
            ->call('save')
            ->assertHasErrors(['name']);
    });

    test('validation requires value', function () {
        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Test')
            ->set('type', 'percent_of_revenue')
            ->set('value', '')
            ->call('save')
            ->assertHasErrors(['value']);
    });

    test('percent type value capped at 100', function () {
        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Too Much')
            ->set('type', 'percent_of_revenue')
            ->set('value', '150')
            ->call('save')
            ->assertHasErrors(['value']);
    });

    test('shows allocation summary', function () {
        // Create allocations of each type
        CompanyAllocation::factory()->create([
            'company_setting_id' => $this->settings->id,
            'type' => 'fixed_per_job',
            'value' => 100,
            'is_active' => true,
            'is_system' => false,
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::settings.allocations')
            ->assertSee('Active Allocation Summary')
            ->assertSee('% of Revenue')
            ->assertSee('Fixed/Job')
            ->assertSee('Per Labor Hour');
    });
});

describe('Allocations in Cost Breakdown', function () {
    test('breakdown uses dynamic allocations when configured', function () {
        // The seeded allocations should be used
        $service = new \App\Services\EstimateCostBreakdownService;
        $customerPrice = 1000;
        $settings = $service->getCurrentSettings(
            \App\Models\Estimate::factory()->create()
        );

        $allocations = $service->calculateOverheadAllocations($customerPrice, $settings, 10);

        // Should have 'items' key indicating dynamic allocations
        expect($allocations)->toHaveKey('items')
            ->and($allocations['items'])->toBeArray()
            ->and(count($allocations['items']))->toBeGreaterThan(0);
    });
});
