<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateAmendment;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::create(['company_name' => 'Test Company']);
});

it('renders pending amendment with change details', function () {
    $estimate = Estimate::factory()->sent()->create();
    $amendment = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create([
        'amendment_number' => 1,
        'changes' => [
            ['category' => 'surface', 'action' => 'modified', 'label' => 'Living Room - Walls', 'field' => 'Coats', 'old_value' => '2', 'new_value' => '3'],
        ],
        'previous_total' => 5000,
        'new_total' => 5500,
    ]);

    Livewire::test('public.amendment-acknowledgment', ['amendment' => $amendment])
        ->assertSee('Amendment #1')
        ->assertSee('Living Room - Walls')
        ->assertSee('Coats');
});

it('shows acknowledged state for acknowledged amendment', function () {
    $estimate = Estimate::factory()->sent()->create();
    $amendment = EstimateAmendment::factory()->forEstimate($estimate)->acknowledged()->create([
        'amendment_number' => 1,
        'customer_initials' => 'AB',
    ]);

    Livewire::test('public.amendment-acknowledgment', ['amendment' => $amendment])
        ->assertSee('Acknowledged by AB');
});

it('does not show acknowledged state for pending amendment', function () {
    $estimate = Estimate::factory()->sent()->create();
    $amendment = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create([
        'amendment_number' => 1,
    ]);

    Livewire::test('public.amendment-acknowledgment', ['amendment' => $amendment])
        ->assertDontSee('Acknowledged by');
});
