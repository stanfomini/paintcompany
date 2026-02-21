<?php

use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;

it('creates a territory budget', function () {
    $territory = SalesTerritory::factory()->region()->create();

    $budget = TerritoryBudget::factory()->monthly()->create([
        'territory_id' => $territory->id,
        'marketing_budget' => 2500,
        'commission_budget_pct' => 12,
    ]);

    expect($budget->territory->id)->toBe($territory->id);
    expect($budget->marketing_budget)->toBe('2500.00');
    expect($budget->commission_budget_pct)->toBe('12.00');
});

it('prevents duplicate budgets for same territory period', function () {
    $territory = SalesTerritory::factory()->create();

    TerritoryBudget::factory()->create([
        'territory_id' => $territory->id,
        'period_type' => 'monthly',
        'period_start' => '2026-02-01',
    ]);

    expect(fn () => TerritoryBudget::factory()->create([
        'territory_id' => $territory->id,
        'period_type' => 'monthly',
        'period_start' => '2026-02-01',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('gets budgets from territory', function () {
    $territory = SalesTerritory::factory()->create();
    TerritoryBudget::factory()->monthly()->create(['territory_id' => $territory->id]);
    TerritoryBudget::factory()->quarterly()->create(['territory_id' => $territory->id]);
    TerritoryBudget::factory()->yearly()->create(['territory_id' => $territory->id]);

    expect($territory->budgets)->toHaveCount(3);
});
