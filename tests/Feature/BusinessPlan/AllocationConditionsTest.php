<?php

use App\Models\BudgetCategory;
use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JobCostCardAllocation;
use App\Models\PaintProduct;
use App\Models\Payment;
use App\Models\Substrate;
use App\Models\User;
use App\Services\AllocationEvaluationService;
use App\Services\JobCostCardService;
use Livewire\Livewire;

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
                ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['payment_methods' => ['stripe', 'check']]))->toBeTrue()
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

// ---- Expanded Context Fields ----

describe('Expanded Context Fields', function () {

    it('buildContext returns all expected keys', function () {
        $card = JobCostCard::factory()->create(['price' => 5000]);

        $service = app(AllocationEvaluationService::class);
        $context = $service->buildContext($card);

        $expectedKeys = [
            'scope_type', 'has_scope', 'payment_methods', 'job_stage', 'job_price',
            'total_sqft', 'total_labor_hours', 'area_count', 'crew_size', 'estimator_mode',
            'lead_source', 'has_agent_referral', 'is_repeat_customer', 'customer_source',
            'uses_premium_paint', 'max_coats', 'total_material_cost',
            'has_spray_application', 'substrate_categories', 'has_complex_substrate', 'requires_prime',
            'has_subcontractor', 'has_nested_areas', 'has_options_selected', 'deposit_percent',
            'month', 'season',
        ];

        foreach ($expectedKeys as $key) {
            expect($context)->toHaveKey($key);
        }
    });

    it('gathers total_sqft from estimate areas', function () {
        $estimate = Estimate::factory()->create(['subtotal' => 5000]);
        // length=10, width=10, height=10 → wall_sqft=400, ceiling_sqft=100
        $area1 = EstimateArea::factory()->forEstimate($estimate)->create([
            'length' => 10, 'width' => 10, 'height' => 10,
        ]);
        // length=15, width=10, height=8 → wall_sqft=400, ceiling_sqft=150
        $area2 = EstimateArea::factory()->forEstimate($estimate)->create([
            'length' => 15, 'width' => 10, 'height' => 8,
        ]);

        $job = Job::factory()->create(['estimate_id' => $estimate->id]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        // Area 1: wall=400 + ceiling=100 = 500; Area 2: wall=400 + ceiling=150 = 550
        expect($context['total_sqft'])->toBe(1050.0)
            ->and($context['area_count'])->toBe(2);
    });

    it('gathers labor hours and material cost from surfaces', function () {
        $estimate = Estimate::factory()->create(['subtotal' => 5000]);
        $area = EstimateArea::factory()->forEstimate($estimate)->create();
        EstimateAreaSurface::factory()->forArea($area)->create([
            'labor_hours' => 8.5,
            'material_cost' => 120.00,
            'is_included' => true,
            'coats' => 2,
        ]);
        EstimateAreaSurface::factory()->forArea($area)->create([
            'labor_hours' => 4.0,
            'material_cost' => 60.00,
            'is_included' => true,
            'coats' => 1,
        ]);

        $job = Job::factory()->create(['estimate_id' => $estimate->id]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['total_labor_hours'])->toBe(12.5)
            ->and($context['total_material_cost'])->toBe(180.0)
            ->and($context['max_coats'])->toBe(2);
    });

    it('detects spray application from area', function () {
        $estimate = Estimate::factory()->create(['subtotal' => 5000]);
        EstimateArea::factory()->forEstimate($estimate)->create([
            'application_method' => 'spray',
        ]);

        $job = Job::factory()->create(['estimate_id' => $estimate->id]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['has_spray_application'])->toBeTrue();
    });

    it('detects complex substrate from high multiplier', function () {
        $substrate = Substrate::factory()->peelingPaint()->create();

        $estimate = Estimate::factory()->create(['subtotal' => 5000]);
        EstimateArea::factory()->forEstimate($estimate)->create([
            'substrate_id' => $substrate->id,
        ]);

        $job = Job::factory()->create(['estimate_id' => $estimate->id]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['has_complex_substrate'])->toBeTrue()
            ->and($context['requires_prime'])->toBeTrue();
    });

    it('detects premium paint from surface paint product', function () {
        $premiumPaint = PaintProduct::factory()->create(['is_premium' => true]);

        $estimate = Estimate::factory()->create(['subtotal' => 5000]);
        $area = EstimateArea::factory()->forEstimate($estimate)->create();
        EstimateAreaSurface::factory()->forArea($area)->create([
            'paint_product_id' => $premiumPaint->id,
            'is_included' => true,
        ]);

        $job = Job::factory()->create(['estimate_id' => $estimate->id]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['uses_premium_paint'])->toBeTrue();
    });

    it('detects agent referral from deal', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['subtotal' => 5000]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['has_agent_referral'])->toBeTrue();
    });

    it('detects repeat customer from multiple deals', function () {
        $customer = Customer::factory()->create();
        Deal::factory()->forCustomer($customer)->create();
        $deal2 = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal2)->create(['subtotal' => 5000]);
        $job = Job::factory()->create([
            'deal_id' => $deal2->id,
            'estimate_id' => $estimate->id,
        ]);

        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['is_repeat_customer'])->toBeTrue();
    });

    it('derives season from month correctly', function () {
        $card = JobCostCard::factory()->create(['price' => 5000]);
        $service = app(AllocationEvaluationService::class);
        $context = $service->buildContext($card);

        // Season depends on current month; just verify it's one of the valid values
        expect($context['season'])->toBeIn(['winter', 'spring', 'summer', 'fall'])
            ->and($context['month'])->toBeGreaterThanOrEqual(1)
            ->and($context['month'])->toBeLessThanOrEqual(12);
    });

    it('detects lead source from deal', function () {
        $deal = Deal::factory()->create(['lead_source' => 'google_ads']);
        $job = Job::factory()->create(['deal_id' => $deal->id]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['lead_source'])->toBe('google_ads');
    });

    it('detects subcontractor from card cost', function () {
        $card = JobCostCard::factory()->create([
            'price' => 5000,
            'subcontractor_cost' => 500,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['has_subcontractor'])->toBeTrue();
    });

    it('gets crew_size from job', function () {
        $job = Job::factory()->create(['required_crew_size' => 5]);
        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'price' => 5000,
        ]);

        $context = app(AllocationEvaluationService::class)->buildContext($card);

        expect($context['crew_size'])->toBe(5);
    });
});

// ---- Boolean Condition Evaluation ----

describe('Boolean Condition Evaluation', function () {

    it('evaluates boolean true condition', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'has_agent_referral', 'operator' => 'equals', 'value' => true],
            ])->create();
        });

        expect($allocation->evaluateConditions(['has_agent_referral' => true]))->toBeTrue()
            ->and($allocation->evaluateConditions(['has_agent_referral' => false]))->toBeFalse();
    });

    it('evaluates boolean false condition', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'has_subcontractor', 'operator' => 'equals', 'value' => false],
            ])->create();
        });

        expect($allocation->evaluateConditions(['has_subcontractor' => false]))->toBeTrue()
            ->and($allocation->evaluateConditions(['has_subcontractor' => true]))->toBeFalse();
    });

    it('evaluates boolean with string values', function () {
        // UI sends "1" and "0" as strings
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'uses_premium_paint', 'operator' => 'equals', 'value' => '1'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['uses_premium_paint' => true]))->toBeTrue()
            ->and($allocation->evaluateConditions(['uses_premium_paint' => false]))->toBeFalse();
    });

    it('evaluates season condition', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'season', 'operator' => 'equals', 'value' => 'winter'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['season' => 'winter']))->toBeTrue()
            ->and($allocation->evaluateConditions(['season' => 'summer']))->toBeFalse();
    });

    it('evaluates lead_source with in operator for array', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'lead_source', 'operator' => 'in', 'value' => ['google_ads', 'thumbtack']],
            ])->create();
        });

        expect($allocation->evaluateConditions(['lead_source' => 'google_ads']))->toBeTrue()
            ->and($allocation->evaluateConditions(['lead_source' => 'thumbtack']))->toBeTrue()
            ->and($allocation->evaluateConditions(['lead_source' => 'referral']))->toBeFalse();
    });

    it('evaluates substrate_categories with in operator', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'substrate_categories', 'operator' => 'equals', 'value' => 'masonry'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['substrate_categories' => ['masonry', 'wood']]))->toBeTrue()
            ->and($allocation->evaluateConditions(['substrate_categories' => ['standard']]))->toBeFalse();
    });

    it('evaluates crew_size with greater_than operator', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'crew_size', 'operator' => 'greater_than', 'value' => '3'],
            ])->create();
        });

        expect($allocation->evaluateConditions(['crew_size' => 5]))->toBeTrue()
            ->and($allocation->evaluateConditions(['crew_size' => 2]))->toBeFalse();
    });
});

// ---- Default Allocations Seeder ----

describe('Default Allocations Seeder', function () {

    it('seeds 12 default allocations', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $defaults = CompanyAllocation::where('company_setting_id', $settings->id)
            ->where('is_system', false)
            ->get();

        expect($defaults)->toHaveCount(12);
    });

    it('default allocations are all inactive', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $activeDefaults = CompanyAllocation::where('company_setting_id', $settings->id)
            ->where('is_system', false)
            ->where('is_active', true)
            ->count();

        expect($activeDefaults)->toBe(0);
    });

    it('most default allocations have conditions', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        // Equipment & Supplies uses tiers instead of top-level conditions
        $withoutConditions = CompanyAllocation::where('company_setting_id', $settings->id)
            ->where('is_system', false)
            ->whereNull('conditions')
            ->count();

        expect($withoutConditions)->toBe(1);
    });

    it('seeder is idempotent', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();
        $settings->seedDefaultAllocations(); // Run twice

        $defaults = CompanyAllocation::where('company_setting_id', $settings->id)
            ->where('is_system', false)
            ->count();

        expect($defaults)->toBe(12);
    });

    it('payment processing fee has tiers for different methods', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $ppf = CompanyAllocation::where('slug', 'payment_processing_fee')->first();

        expect($ppf)->not->toBeNull()
            ->and($ppf->hasTiers())->toBeTrue()
            ->and($ppf->value_tiers)->toHaveCount(3)
            ->and($ppf->value_tiers[0]['label'])->toBe('Stripe')
            ->and((float) $ppf->value_tiers[0]['value'])->toBe(2.99)
            ->and($ppf->value_tiers[1]['label'])->toBe('Check')
            ->and($ppf->value_tiers[1]['type'])->toBe('fixed_per_job')
            ->and($ppf->value_tiers[2]['label'])->toBe('ACH');
    });

    it('payment processing fee resolves correct tier per method', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $ppf = CompanyAllocation::where('slug', 'payment_processing_fee')->first();

        // Stripe tier
        $tier = $ppf->resolveTier(['payment_methods' => ['stripe']]);
        expect($tier)->not->toBeNull()
            ->and($tier['label'])->toBe('Stripe')
            ->and($tier['value'])->toBe(2.99);

        // Check tier (fixed)
        $tier = $ppf->resolveTier(['payment_methods' => ['check']]);
        expect($tier['label'])->toBe('Check')
            ->and($tier['type'])->toBe('fixed_per_job')
            ->and($tier['value'])->toBe(50.00);

        // ACH tier (fixed)
        $tier = $ppf->resolveTier(['payment_methods' => ['ach']]);
        expect($tier['label'])->toBe('ACH')
            ->and($tier['value'])->toBe(5.00);
    });

    it('agent referral allocation applies only with agent', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $referral = CompanyAllocation::where('slug', 'agent_referral_commission')->first();

        expect($referral->evaluateConditions(['has_agent_referral' => true]))->toBeTrue()
            ->and($referral->evaluateConditions(['has_agent_referral' => false]))->toBeFalse();
    });

    it('small job minimum applies only under threshold', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $smallJob = CompanyAllocation::where('slug', 'small_job_minimum_overhead')->first();

        expect($smallJob->evaluateConditions(['job_price' => 1000]))->toBeTrue()
            ->and($smallJob->evaluateConditions(['job_price' => 2000]))->toBeFalse();
    });

    it('winter premium applies only in winter', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $winter = CompanyAllocation::where('slug', 'winter_season_premium')->first();

        expect($winter->evaluateConditions(['season' => 'winter']))->toBeTrue()
            ->and($winter->evaluateConditions(['season' => 'summer']))->toBeFalse();
    });

    it('seeder removes deprecated slugs', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();

        // Create old-style allocations
        CompanyAllocation::withoutEvents(function () use ($settings) {
            CompanyAllocation::factory()->create([
                'company_setting_id' => $settings->id,
                'slug' => 'credit_card_processing_fee',
                'is_system' => false,
            ]);
            CompanyAllocation::factory()->create([
                'company_setting_id' => $settings->id,
                'slug' => 'ach_processing_fee',
                'is_system' => false,
            ]);
        });

        $settings->seedDefaultAllocations();

        expect(CompanyAllocation::where('slug', 'credit_card_processing_fee')->exists())->toBeFalse()
            ->and(CompanyAllocation::where('slug', 'ach_processing_fee')->exists())->toBeFalse()
            ->and(CompanyAllocation::where('slug', 'payment_processing_fee')->exists())->toBeTrue();
    });

    it('equipment and supplies has scope-based tiers', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $equip = CompanyAllocation::where('slug', 'equipment_and_supplies')->first();

        expect($equip)->not->toBeNull()
            ->and($equip->hasTiers())->toBeTrue()
            ->and($equip->value_tiers)->toHaveCount(2)
            ->and($equip->value_tiers[0]['label'])->toBe('Exterior')
            ->and((float) $equip->value_tiers[0]['value'])->toBe(3.50)
            ->and($equip->value_tiers[1]['label'])->toBe('Interior')
            ->and((float) $equip->value_tiers[1]['value'])->toBe(1.50);
    });

    it('lead cost recovery has source-based tiers', function () {
        CompanyAllocation::query()->delete();
        CompanySetting::clearInstanceCache();

        $settings = CompanySetting::getInstance();
        $settings->seedDefaultAllocations();

        $lead = CompanyAllocation::where('slug', 'lead_cost_recovery')->first();

        expect($lead)->not->toBeNull()
            ->and($lead->hasTiers())->toBeTrue()
            ->and($lead->value_tiers)->toHaveCount(3);

        $tier = $lead->resolveTier(['lead_source' => 'google_ads']);
        expect($tier['label'])->toBe('Google Ads')
            ->and($tier['value'])->toBe(5.00);

        $tier = $lead->resolveTier(['lead_source' => 'thumbtack']);
        expect($tier['label'])->toBe('Thumbtack')
            ->and($tier['value'])->toBe(3.00);
    });
});

// ---- Value Tiers ----

describe('Value Tiers', function () {

    it('hasTiers returns false for allocation without tiers', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'value_tiers' => null,
            ]);
        });

        expect($allocation->hasTiers())->toBeFalse();
    });

    it('hasTiers returns true for allocation with tiers', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Tier A', 'type' => 'percent_of_revenue', 'value' => 5.0, 'conditions' => [
                    ['field' => 'season', 'operator' => 'equals', 'value' => 'winter'],
                ]],
            ])->create();
        });

        expect($allocation->hasTiers())->toBeTrue();
    });

    it('resolveTier returns first matching tier', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
                ['label' => 'Check', 'type' => 'fixed_per_job', 'value' => 50.00, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'check'],
                ]],
            ])->create();
        });

        $tier = $allocation->resolveTier(['payment_methods' => ['stripe']]);

        expect($tier)->not->toBeNull()
            ->and($tier['label'])->toBe('Stripe')
            ->and($tier['value'])->toBe(2.99);
    });

    it('resolveTier returns null when no tier matches', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
            ])->create();
        });

        $tier = $allocation->resolveTier(['payment_methods' => ['cash']]);

        expect($tier)->toBeNull();
    });

    it('resolveAndCalculate uses matching tier', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
            ]);
        });

        $result = $allocation->resolveAndCalculate(
            ['payment_methods' => ['stripe']],
            10000.00
        );

        expect($result['type'])->toBe('percent_of_revenue')
            ->and($result['value'])->toBe(2.99)
            ->and($result['amount'])->toBe(299.00)
            ->and($result['tier_label'])->toBe('Stripe');
    });

    it('resolveAndCalculate falls back to base when no tier matches', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
            ]);
        });

        $result = $allocation->resolveAndCalculate(
            ['payment_methods' => ['cash']],
            10000.00
        );

        expect($result['type'])->toBe('percent_of_revenue')
            ->and($result['value'])->toBe(3.00)
            ->and($result['amount'])->toBe(300.00)
            ->and($result['tier_label'])->toBeNull();
    });

    it('tiered allocation with mixed types calculates correctly', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
                ['label' => 'Check', 'type' => 'fixed_per_job', 'value' => 50.00, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'check'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
            ]);
        });

        // Stripe → percent
        $result = $allocation->resolveAndCalculate(
            ['payment_methods' => ['stripe']],
            10000.00
        );
        expect($result['amount'])->toBe(299.00)
            ->and($result['type'])->toBe('percent_of_revenue');

        // Check → fixed
        $result = $allocation->resolveAndCalculate(
            ['payment_methods' => ['check']],
            10000.00
        );
        expect($result['amount'])->toBe(50.00)
            ->and($result['type'])->toBe('fixed_per_job');
    });

    it('tier with multiple conditions requires all to match', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Winter Exterior', 'type' => 'per_labor_hour', 'value' => 5.00, 'conditions' => [
                    ['field' => 'season', 'operator' => 'equals', 'value' => 'winter'],
                    ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
                'value' => 2.00,
                'is_active' => true,
            ]);
        });

        // Both match
        $tier = $allocation->resolveTier([
            'season' => 'winter',
            'has_scope' => ['exterior'],
        ]);
        expect($tier)->not->toBeNull()
            ->and($tier['label'])->toBe('Winter Exterior');

        // Only one matches
        $tier = $allocation->resolveTier([
            'season' => 'summer',
            'has_scope' => ['exterior'],
        ]);
        expect($tier)->toBeNull();
    });

    it('applied tier label is stored in JCC allocation record', function () {
        CompanyAllocation::query()->delete();

        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'interior'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
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

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();

        expect($cardAllocation)->not->toBeNull()
            ->and($cardAllocation->applied_tier)->toBe('Stripe')
            ->and((float) $cardAllocation->amount)->toBe(299.00);
    });

    it('backward compatible: allocations without tiers work unchanged', function () {
        CompanyAllocation::query()->delete();

        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
                'value_tiers' => null,
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

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();
        expect($cardAllocation->applied_tier)->toBeNull();
    });

    it('factory withTiers state works correctly', function () {
        $tiers = [
            ['label' => 'Test', 'type' => 'fixed_per_job', 'value' => 100, 'conditions' => []],
        ];

        $allocation = CompanyAllocation::withoutEvents(function () use ($tiers) {
            return CompanyAllocation::factory()->withTiers($tiers)->create();
        });

        expect($allocation->hasTiers())->toBeTrue()
            ->and($allocation->value_tiers)->toHaveCount(1)
            ->and($allocation->value_tiers[0]['label'])->toBe('Test');
    });

    it('tier with no conditions always matches', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Default Tier', 'type' => 'fixed_per_job', 'value' => 100.00, 'conditions' => []],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
            ]);
        });

        $tier = $allocation->resolveTier(['anything' => 'context']);

        expect($tier)->not->toBeNull()
            ->and($tier['label'])->toBe('Default Tier')
            ->and($tier['value'])->toBe(100.00);
    });

    it('resolveAndCalculate with per_labor_hour tier', function () {
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Exterior', 'type' => 'per_labor_hour', 'value' => 3.50, 'conditions' => [
                    ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
                'value' => 1.50,
                'is_active' => true,
            ]);
        });

        $result = $allocation->resolveAndCalculate(
            ['has_scope' => ['exterior']],
            10000.00,
            20.0
        );

        expect($result['type'])->toBe('per_labor_hour')
            ->and($result['value'])->toBe(3.50)
            ->and($result['amount'])->toBe(70.00)
            ->and($result['tier_label'])->toBe('Exterior');

        // Fallback for interior
        $result = $allocation->resolveAndCalculate(
            ['has_scope' => ['interior']],
            10000.00,
            20.0
        );

        expect($result['amount'])->toBe(30.00)
            ->and($result['tier_label'])->toBeNull();
    });
});

// ---- Payment Tier End-to-End Evaluation ----

describe('Payment Tier End-to-End Evaluation', function () {

    it('evaluates stripe tier via service with real payment data', function () {
        CompanyAllocation::query()->delete();

        // Create allocation with payment method tiers
        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
                ['label' => 'Check', 'type' => 'fixed_per_job', 'value' => 50.00, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'check'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        // Set up job with stripe payment
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['subtotal' => 10000]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $invoice = Invoice::factory()->forJob($job)->create();
        Payment::factory()->forInvoice($invoice)->stripe()->create([
            'amount' => 10000,
            'status' => 'completed',
        ]);

        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();

        expect($cardAllocation)->not->toBeNull()
            ->and($cardAllocation->applied_tier)->toBe('Stripe')
            ->and((float) $cardAllocation->amount)->toBe(299.00)
            ->and($cardAllocation->type)->toBe('percent_of_revenue');
    });

    it('evaluates check tier via service with real payment data', function () {
        CompanyAllocation::query()->delete();

        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
                ['label' => 'Check', 'type' => 'fixed_per_job', 'value' => 50.00, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'check'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['subtotal' => 10000]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $invoice = Invoice::factory()->forJob($job)->create();
        Payment::factory()->forInvoice($invoice)->check()->create([
            'amount' => 10000,
            'status' => 'completed',
        ]);

        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();

        expect($cardAllocation)->not->toBeNull()
            ->and($cardAllocation->applied_tier)->toBe('Check')
            ->and((float) $cardAllocation->amount)->toBe(50.00)
            ->and($cardAllocation->type)->toBe('fixed_per_job');
    });

    it('falls back to base when no payment method matches tier', function () {
        CompanyAllocation::query()->delete();

        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        // Card without any job/payment → fallback to base
        $card = JobCostCard::factory()->create([
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();

        expect($cardAllocation)->not->toBeNull()
            ->and($cardAllocation->applied_tier)->toBeNull()
            ->and((float) $cardAllocation->amount)->toBe(300.00)
            ->and($cardAllocation->type)->toBe('percent_of_revenue');
    });
});

// ---- Livewire Save → Evaluation Roundtrip ----

describe('Livewire Save to Evaluation Roundtrip', function () {

    it('allocation saved via component evaluates correctly against a job cost card', function () {
        $this->actingAs($this->user);
        CompanyAllocation::query()->where('is_system', false)->delete();

        // Save an allocation with a gate condition via the Livewire component
        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Exterior Premium')
            ->set('type', 'percent_of_revenue')
            ->set('value', '5')
            ->set('allocation_rules', [
                [
                    'field' => 'scope_type',
                    'operator' => 'equals',
                    'condition_value' => 'exterior',
                    'has_override' => false,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '',
                    'label' => '',
                ],
            ])
            ->call('save');

        $allocation = CompanyAllocation::where('slug', 'exterior_premium')->first();
        expect($allocation)->not->toBeNull()
            ->and($allocation->conditions)->toHaveCount(1);

        // Evaluate against an exterior card
        $estimate = Estimate::factory()->create(['subtotal' => 10000, 'project_type' => 'exterior']);
        EstimateArea::factory()->forEstimate($estimate)->create(['scope_type' => 'exterior']);
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        $card = JobCostCard::factory()->create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        // Re-evaluate (the saved event already did this, but card was created after)
        $service = app(AllocationEvaluationService::class);
        $service->evaluateForCard($card);

        $card->refresh();

        $cardAllocation = JobCostCardAllocation::where('job_cost_card_id', $card->id)
            ->where('company_allocation_id', $allocation->id)
            ->first();

        expect($cardAllocation)->not->toBeNull()
            ->and((float) $cardAllocation->amount)->toBe(500.00);

        // Evaluate against an interior card — should NOT apply
        $interiorEstimate = Estimate::factory()->create(['subtotal' => 10000, 'project_type' => 'interior']);
        $interiorJob = Job::factory()->create(['estimate_id' => $interiorEstimate->id]);

        $interiorCard = JobCostCard::factory()->create([
            'job_id' => $interiorJob->id,
            'estimate_id' => $interiorEstimate->id,
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        $service->evaluateForCard($interiorCard);

        $interiorCardAllocation = JobCostCardAllocation::where('job_cost_card_id', $interiorCard->id)
            ->where('company_allocation_id', $allocation->id)
            ->first();

        expect($interiorCardAllocation)->toBeNull();
    });
});

// ---- Payment Triggers Allocation Re-evaluation ----

describe('Payment Triggers Allocation Re-evaluation', function () {

    it('syncPaymentSummary re-evaluates allocations when payment is recorded', function () {
        CompanyAllocation::query()->delete();

        // Create allocation with payment method tiers (Stripe=2.99%, Check=$50 fixed)
        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
                ['label' => 'Check', 'type' => 'fixed_per_job', 'value' => 50.00, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'check'],
                ]],
            ])->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00, // base/fallback
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        // Set up job with card (no payments yet → base allocation)
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['subtotal' => 10000]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $card = JobCostCard::factory()->forJob($job)->create([
            'estimate_id' => $estimate->id,
            'price' => 10000,
            'allocation_total' => 0,
        ]);

        // Initial evaluation → base fallback (no payments = 3.00% = $300)
        app(AllocationEvaluationService::class)->evaluateForCard($card);
        $card->refresh();

        $cardAlloc = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();
        expect($cardAlloc->applied_tier)->toBeNull()
            ->and((float) $cardAlloc->amount)->toBe(300.00);

        // Record a check payment
        $invoice = Invoice::factory()->forJob($job)->create(['total' => 10000]);
        Payment::factory()->forInvoice($invoice)->check()->create([
            'amount' => 10000,
            'status' => 'completed',
        ]);

        // syncPaymentSummary should now re-evaluate allocations
        app(JobCostCardService::class)->syncPaymentSummary($job);

        $card->refresh();
        $cardAlloc = JobCostCardAllocation::where('job_cost_card_id', $card->id)->first();

        expect($cardAlloc->applied_tier)->toBe('Check')
            ->and((float) $cardAlloc->amount)->toBe(50.00)
            ->and($cardAlloc->type)->toBe('fixed_per_job');
    });

    it('syncPaymentSummary updates payment totals and allocations together', function () {
        CompanyAllocation::query()->delete();

        // Simple unconditional allocation
        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->create([
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_active' => true,
                'is_informational' => false,
                'conditions' => null,
            ]);
        });

        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['subtotal' => 8000]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $card = JobCostCard::factory()->forJob($job)->create([
            'estimate_id' => $estimate->id,
            'price' => 8000,
            'allocation_total' => 0,
        ]);

        Invoice::factory()->forJob($job)->sent()->create([
            'subtotal' => 8000,
            'total' => 8000,
            'amount_paid' => 5000,
            'balance_due' => 3000,
        ]);

        app(JobCostCardService::class)->syncPaymentSummary($job);

        $card->refresh();

        // Payment totals updated
        expect((float) $card->total_invoiced)->toBe(8000.00)
            ->and((float) $card->total_collected)->toBe(5000.00)
            ->and((float) $card->balance_due)->toBe(3000.00);

        // Allocations re-evaluated
        expect((float) $card->allocation_total)->toBe(400.00); // 5% of 8000
    });
});

// ---- Estimate Context Builder ----

describe('Estimate Context Builder', function () {

    it('buildContextFromEstimate returns all expected keys', function () {
        $estimate = Estimate::factory()->create(['subtotal' => 5000]);

        $service = app(AllocationEvaluationService::class);
        $context = $service->buildContextFromEstimate($estimate);

        $expectedKeys = [
            'scope_type', 'has_scope', 'payment_methods', 'job_stage', 'job_price',
            'total_sqft', 'total_labor_hours', 'area_count', 'crew_size', 'estimator_mode',
            'lead_source', 'has_agent_referral', 'is_repeat_customer', 'customer_source',
            'uses_premium_paint', 'max_coats', 'total_material_cost',
            'has_spray_application', 'substrate_categories', 'has_complex_substrate', 'requires_prime',
            'has_subcontractor', 'has_nested_areas', 'has_options_selected', 'deposit_percent',
            'month', 'season',
        ];

        foreach ($expectedKeys as $key) {
            expect($context)->toHaveKey($key);
        }
    });

    it('buildContextFromEstimate has empty payment_methods', function () {
        $estimate = Estimate::factory()->create(['subtotal' => 5000]);

        $context = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($estimate);

        expect($context['payment_methods'])->toBe([]);
    });

    it('buildContextFromEstimate gets scope from estimate areas', function () {
        $estimate = Estimate::factory()->create([
            'subtotal' => 5000,
            'project_type' => 'exterior',
        ]);

        EstimateArea::factory()->forEstimate($estimate)->create(['scope_type' => 'exterior']);

        $context = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($estimate);

        expect($context['scope_type'])->toBe('exterior')
            ->and($context['has_scope'])->toContain('exterior');
    });

    it('buildContextFromEstimate gathers sizing from areas and surfaces', function () {
        $estimate = Estimate::factory()->create(['subtotal' => 5000]);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'length' => 10, 'width' => 10, 'height' => 10,
        ]);
        EstimateAreaSurface::factory()->forArea($area)->create([
            'labor_hours' => 8.5,
            'material_cost' => 120.00,
            'is_included' => true,
            'coats' => 2,
        ]);

        $context = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($estimate);

        expect($context['total_sqft'])->toBeGreaterThan(0)
            ->and($context['total_labor_hours'])->toBe(8.5)
            ->and($context['total_material_cost'])->toBe(120.0)
            ->and($context['max_coats'])->toBe(2)
            ->and($context['area_count'])->toBe(1);
    });

    it('buildContextFromEstimate detects agent referral', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['subtotal' => 5000]);

        $context = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($estimate);

        expect($context['has_agent_referral'])->toBeTrue();
    });

    it('estimate context enables scope-conditional allocation in breakdown', function () {
        CompanyAllocation::query()->delete();

        // Allocation that only applies to exterior scope
        CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'scope_type', 'operator' => 'equals', 'value' => 'exterior'],
            ])->create([
                'name' => 'Exterior Surcharge',
                'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
                'value' => 200.00,
                'is_active' => true,
                'is_informational' => false,
            ]);
        });

        // Exterior estimate
        $exteriorEstimate = Estimate::factory()->create([
            'subtotal' => 5000,
            'project_type' => 'exterior',
        ]);
        EstimateArea::factory()->forEstimate($exteriorEstimate)->create([
            'scope_type' => 'exterior',
        ]);

        $service = new \App\Services\EstimateCostBreakdownService;
        $context = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($exteriorEstimate);
        $breakdown = $service->calculateBreakdown($exteriorEstimate, $context);

        // Exterior surcharge should be present in allocations
        $allocationNames = collect($breakdown['allocations']['items'] ?? [])->pluck('name')->toArray();
        expect($allocationNames)->toContain('Exterior Surcharge');

        // Interior estimate — surcharge should NOT appear
        $interiorEstimate = Estimate::factory()->create([
            'subtotal' => 5000,
            'project_type' => 'interior',
        ]);

        $interiorContext = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($interiorEstimate);
        $interiorBreakdown = $service->calculateBreakdown($interiorEstimate, $interiorContext);

        $interiorNames = collect($interiorBreakdown['allocations']['items'] ?? [])->pluck('name')->toArray();
        expect($interiorNames)->not->toContain('Exterior Surcharge');
    });
});
