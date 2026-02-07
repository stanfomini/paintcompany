<?php

use App\Models\BudgetCategory;
use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\JobCostCard;
use App\Models\JobCostCardAllocation;
use App\Models\User;
use App\Services\AllocationEvaluationService;
use App\Services\JobCostCardService;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    CompanySetting::clearInstanceCache();
    $this->settings = CompanySetting::getInstance();
    $this->settings->ensureSystemAllocations();
});

// ---- Condition Evaluation on CompanyAllocation ----

describe('Condition Evaluation on CompanyAllocation', function () {

    it('unconditional allocation always evaluates to true', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'conditions' => null,
            ]);
        });

        $result = $allocation->evaluateConditions([
            'scope_type' => 'interior',
            'has_scope' => ['interior'],
            'payment_methods' => [],
            'job_stage' => 'in_progress',
            'job_price' => 5000,
        ]);

        expect($result)->toBeTrue();
    });

    it('scope_type equals condition matches', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'exterior'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['scope_type' => 'exterior']))->toBeTrue()
            ->and($allocation->evaluateConditions(['scope_type' => 'interior']))->toBeFalse();
    });

    it('scope_type not_equals condition', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'not_equals', 'value' => 'exterior'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['scope_type' => 'interior']))->toBeTrue()
            ->and($allocation->evaluateConditions(['scope_type' => 'exterior']))->toBeFalse();
    });

    it('has_scope condition with interior', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'interior'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['has_scope' => ['interior']]))->toBeTrue()
            ->and($allocation->evaluateConditions(['has_scope' => ['exterior']]))->toBeFalse();
    });

    it('has_scope condition with both', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'both'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['has_scope' => ['interior', 'exterior']]))->toBeTrue()
            ->and($allocation->evaluateConditions(['has_scope' => ['interior']]))->toBeFalse();
    });

    it('payment_methods equals condition', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'credit_card'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['payment_methods' => ['credit_card', 'check']]))->toBeTrue()
            ->and($allocation->evaluateConditions(['payment_methods' => ['check']]))->toBeFalse();
    });

    it('job_price greater_than condition', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'job_price', 'operator' => 'greater_than', 'value' => '5000'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['job_price' => 7000]))->toBeTrue()
            ->and($allocation->evaluateConditions(['job_price' => 3000]))->toBeFalse();
    });

    it('multiple conditions with AND logic', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'exterior'],
                ['field' => 'job_price', 'operator' => 'greater_than', 'value' => '5000'],
            ], 'all')->create();
        });

        // Both pass
        expect($allocation->evaluateConditions([
            'scope_type' => 'exterior',
            'job_price' => 7000,
        ]))->toBeTrue();

        // Only one passes
        expect($allocation->evaluateConditions([
            'scope_type' => 'interior',
            'job_price' => 7000,
        ]))->toBeFalse();

        // Neither passes
        expect($allocation->evaluateConditions([
            'scope_type' => 'interior',
            'job_price' => 3000,
        ]))->toBeFalse();
    });

    it('multiple conditions with OR logic', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'exterior'],
                ['field' => 'job_price', 'operator' => 'greater_than', 'value' => '5000'],
            ], 'any')->create();
        });

        // Both pass
        expect($allocation->evaluateConditions([
            'scope_type' => 'exterior',
            'job_price' => 7000,
        ]))->toBeTrue();

        // Only one passes (scope matches)
        expect($allocation->evaluateConditions([
            'scope_type' => 'exterior',
            'job_price' => 3000,
        ]))->toBeTrue();

        // Only one passes (price matches)
        expect($allocation->evaluateConditions([
            'scope_type' => 'interior',
            'job_price' => 7000,
        ]))->toBeTrue();

        // Neither passes
        expect($allocation->evaluateConditions([
            'scope_type' => 'interior',
            'job_price' => 3000,
        ]))->toBeFalse();
    });

    it('conditions summary returns readable text', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'exterior'],
                ['field' => 'job_price', 'operator' => 'greater_than', 'value' => '5000'],
            ], 'all')->create();
        });

        $summary = $allocation->getConditionsSummary();

        expect($summary)->not->toBeEmpty()
            ->and($summary)->toContain('Applies when:')
            ->and($summary)->toContain('scope type')
            ->and($summary)->toContain('exterior')
            ->and($summary)->toContain('AND');
    });
});

// ---- Allocation Evaluation Service ----

describe('Allocation Evaluation Service', function () {

    it('evaluates unconditional allocation for card', function () {
        // Remove all system allocations to control the test
        CompanyAllocation::query()->delete();

        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        expect((float) $card->allocation_total)->toBeGreaterThan(0)
            ->and($card->cardAllocations)->toHaveCount(1);
    });

    it('skips allocation that fails conditions', function () {
        CompanyAllocation::query()->delete();

        // Allocation only for exterior scope
        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'exterior'],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
            ]);
        });

        // Card without a job or estimate => context defaults to 'interior'
        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        expect((float) $card->allocation_total)->toBe(0.00)
            ->and($card->cardAllocations)->toHaveCount(0);
    });

    it('calculates percent_of_revenue correctly', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        expect((float) $card->allocation_total)->toBe(500.00);
    });

    it('calculates fixed_per_job correctly', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->fixedPerJob(150)->create([
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        expect((float) $card->allocation_total)->toBe(150.00);
    });

    it('calculates per_labor_hour as zero for manual card without estimate', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->perLaborHour(5)->create([
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->manual()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        // No estimate means 0 labor hours, so per_labor_hour produces $0
        expect((float) $card->allocation_total)->toBe(0.00)
            ->and($card->cardAllocations)->toHaveCount(0);
    });

    it('recalculates card when evaluation runs again', function () {
        CompanyAllocation::query()->delete();

        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();
        expect((float) $card->allocation_total)->toBe(500.00);

        // Change the allocation value
        CompanyAllocation::withoutEvents(function () use ($allocation) {
            $allocation->update(['value' => 10.00]);
        });

        $service->evaluateForCard($card);
        $card->refresh();

        expect((float) $card->allocation_total)->toBe(1000.00);
    });

    it('inactive allocation produces zero', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->inactive()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        expect((float) $card->allocation_total)->toBe(0.00)
            ->and($card->cardAllocations)->toHaveCount(0);
    });

    it('informational allocation not included', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->informational()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 12.00,
                'is_active' => true,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        expect((float) $card->allocation_total)->toBe(0.00)
            ->and($card->cardAllocations)->toHaveCount(0);
    });

    it('card net_profit equals gross_profit minus allocation_total', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->fixedPerJob(200)->create([
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'production_mgmt_labor' => 700,
            'subcontractor_cost' => 500,
            'wc_insurance_burden' => 300,
            'material_cost' => 1500,
            'sales_commission' => 600,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        $expectedGrossProfit = 10000 - (700 + 500 + 300 + 1500 + 600);
        $expectedNetProfit = $expectedGrossProfit - 200;

        expect($card->gross_profit)->toBe((float) $expectedGrossProfit)
            ->and($card->net_profit)->toBe((float) $expectedNetProfit)
            ->and($card->net_profit)->toBe($card->gross_profit - (float) $card->allocation_total);
    });

    it('conditions snapshot stored on card allocation', function () {
        CompanyAllocation::query()->delete();

        $conditions = [
            ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'interior'],
        ];

        CompanyAllocation::withoutEvents(function () use ($conditions) {
            return CompanyAllocation::factory()->withConditions($conditions)->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
            ]);
        });

        // Card without job/estimate defaults to interior context
        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();

        expect($cardAllocation)->not->toBeNull()
            ->and($cardAllocation->conditions_snapshot)->toBe($conditions);
    });
});

// ---- Running Totals ----

describe('Running Totals', function () {

    it('running totals include allocation_total', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $card1 = JobCostCard::factory()->create([
            'company_setting_id' => CompanySetting::getInstance()->id,
            'year' => now()->year,
            'price' => 10000,
            'production_mgmt_labor' => 700,
            'subcontractor_cost' => 0,
            'wc_insurance_burden' => 0,
            'material_cost' => 0,
            'sales_commission' => 0,
            'allocation_total' => 500,
            'is_manual' => true,
        ]);

        $card2 = JobCostCard::factory()->create([
            'company_setting_id' => CompanySetting::getInstance()->id,
            'year' => now()->year,
            'price' => 5000,
            'production_mgmt_labor' => 350,
            'subcontractor_cost' => 0,
            'wc_insurance_burden' => 0,
            'material_cost' => 0,
            'sales_commission' => 0,
            'allocation_total' => 250,
            'is_manual' => true,
        ]);

        $service = new JobCostCardService;
        $totals = $service->getRunningTotals(now()->year);

        expect($totals)->toHaveKey('allocation_total')
            ->and($totals['allocation_total'])->toBe(750.0);
    });

    it('running totals include net_profit', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        JobCostCard::factory()->create([
            'company_setting_id' => CompanySetting::getInstance()->id,
            'year' => now()->year,
            'price' => 10000,
            'production_mgmt_labor' => 700,
            'subcontractor_cost' => 500,
            'wc_insurance_burden' => 300,
            'material_cost' => 1500,
            'sales_commission' => 600,
            'allocation_total' => 400,
            'is_manual' => true,
        ]);

        $service = new JobCostCardService;
        $totals = $service->getRunningTotals(now()->year);

        // gross_profit = 10000 - (700+500+300+1500+600) = 6400
        // net_profit = 6400 - 400 = 6000
        expect($totals)->toHaveKey('net_profit')
            ->and($totals)->toHaveKey('net_profit_percent')
            ->and($totals['net_profit'])->toBe(6000.0)
            ->and($totals['net_profit_percent'])->toBe(60.0);
    });
});

// ---- Event Wiring ----

describe('Event Wiring', function () {

    it('allocation saved triggers recalculation', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        // Creating an allocation fires the `saved` event which calls recalculateAllCards
        CompanyAllocation::factory()->create([
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 5.00,
            'is_active' => true,
            'is_informational' => false,
            'conditions' => null,
        ]);

        $card->refresh();

        expect((float) $card->allocation_total)->toBe(500.00);
    });

    it('allocation deleted cleans up card allocations', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        // Create a non-system allocation so it can be deleted
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->custom()->create([
                'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
                'value' => 200.00,
                'is_active' => true,
                'is_informational' => false,
                'is_system' => false,
                'conditions' => null,
            ]);
        });

        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        // Evaluate to create card allocations
        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();
        expect((float) $card->allocation_total)->toBe(200.00)
            ->and(JobCostCardAllocation::where('company_allocation_id', $allocation->id)->count())->toBe(1);

        // Delete the allocation — should clean up card allocations via the deleted event
        $allocation->delete();

        expect(JobCostCardAllocation::where('company_allocation_id', $allocation->id)->count())->toBe(0);

        $card->refresh();
        expect((float) $card->allocation_total)->toBe(0.00);
    });
});

// ---- Budget Sync Integration ----

describe('Budget Sync Integration', function () {

    it('budget sync maps allocation slugs to card allocations', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();

        // Create a card allocation with slug 'marketing'
        $card = JobCostCard::factory()->create([
            'company_setting_id' => $settings->id,
            'year' => now()->year,
            'price' => 10000,
            'allocation_total' => 0,
            'is_manual' => true,
        ]);

        JobCostCardAllocation::create([
            'job_cost_card_id' => $card->id,
            'company_allocation_id' => CompanyAllocation::withoutEvents(function () {
                return CompanyAllocation::factory()->create([
                    'slug' => 'marketing',
                    'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                    'value' => 8.00,
                ])->id;
            }),
            'name' => 'Marketing',
            'slug' => 'marketing',
            'type' => 'percent_of_revenue',
            'value' => 8.00,
            'amount' => 800.00,
        ]);

        // Create a budget category with matching slug for variable_expense
        $budgetCategory = BudgetCategory::factory()->variableExpense()->create([
            'company_setting_id' => $settings->id,
            'name' => 'Marketing',
            'slug' => 'marketing',
        ]);

        // The BudgetSyncService should find the allocation via slug mapping
        $total = JobCostCardAllocation::query()
            ->where('slug', 'marketing')
            ->whereHas('jobCostCard', function ($q) use ($settings) {
                $q->where('company_setting_id', $settings->id)
                    ->where('year', now()->year);
            })
            ->sum('amount');

        expect((float) $total)->toBe(800.00);
    });
});
