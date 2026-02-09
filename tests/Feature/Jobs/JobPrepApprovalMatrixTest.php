<?php

use App\Models\JobPrepApproval;
use App\Models\JobPrepPlan;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobPrepService;

beforeEach(function () {
    $this->service = app(JobPrepService::class);

    $this->paintingCategory = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Interior and exterior painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );

    $this->cleaningCategory = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'description' => 'Post-painting cleanup', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );
});

describe('approveSection', function () {
    it('creates a JobPrepApproval row for the given party and service', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $plan = JobPrepPlan::factory()->create();

        $this->service->approveSection($plan, $user, 'team', $this->paintingCategory->id);

        expect(JobPrepApproval::where('job_prep_plan_id', $plan->id)
            ->where('service_category_id', $this->paintingCategory->id)
            ->where('party', 'team')
            ->exists()
        )->toBeTrue();
    });

    it('sets approved=true, approved_by, and approved_at', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $plan = JobPrepPlan::factory()->create();

        $this->service->approveSection($plan, $user, 'team', $this->paintingCategory->id);

        $approval = JobPrepApproval::where('job_prep_plan_id', $plan->id)
            ->where('service_category_id', $this->paintingCategory->id)
            ->where('party', 'team')
            ->first();

        expect($approval->approved)->toBeTrue()
            ->and($approval->approved_by)->toBe($user->id)
            ->and($approval->approved_at)->not->toBeNull();
    });
});

describe('revokeServiceApprovals', function () {
    it('resets approvals for a specific service', function () {
        $plan = JobPrepPlan::factory()->create();

        // Create approved rows for painting
        $teamApproval = JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        $crewApproval = JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        $this->service->revokeServiceApprovals($plan, $this->paintingCategory->id);

        expect($teamApproval->fresh()->approved)->toBeFalse()
            ->and($teamApproval->fresh()->approved_by)->toBeNull()
            ->and($teamApproval->fresh()->approved_at)->toBeNull()
            ->and($crewApproval->fresh()->approved)->toBeFalse();
    });

    it('does not revoke the excepted party', function () {
        $plan = JobPrepPlan::factory()->create();

        $teamApproval = JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        $crewApproval = JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        $customerApproval = JobPrepApproval::factory()->customer()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        $this->service->revokeServiceApprovals($plan, $this->paintingCategory->id, exceptParty: 'team');

        expect($teamApproval->fresh()->approved)->toBeTrue()
            ->and($crewApproval->fresh()->approved)->toBeFalse()
            ->and($customerApproval->fresh()->approved)->toBeFalse();
    });
});

describe('proposeChange', function () {
    it('resets other parties approvals when a change is proposed', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $plan = JobPrepPlan::factory()->create();

        // Create a service setting for painting
        $plan->serviceSettings()->create([
            'service_category_id' => $this->paintingCategory->id,
            'crew_size' => 2,
            'hours_per_day' => 8,
            'total_labor_hours' => 16,
            'total_cleanup_hours' => 2,
            'total_days' => 1,
        ]);

        // All three parties have approved painting
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);
        $crewApproval = JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);
        $customerApproval = JobPrepApproval::factory()->customer()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        // Internal user proposes a change (maps to "team" party)
        $this->service->proposeChange($plan, $this->paintingCategory->id, $internalUser, ['crew_size' => 3]);

        // Team approval should remain (proposer's party is excepted)
        $teamApproval = JobPrepApproval::where('job_prep_plan_id', $plan->id)
            ->where('service_category_id', $this->paintingCategory->id)
            ->where('party', 'team')
            ->first();
        expect($teamApproval->approved)->toBeTrue();

        // Crew and customer approvals should be revoked
        expect($crewApproval->fresh()->approved)->toBeFalse()
            ->and($customerApproval->fresh()->approved)->toBeFalse();
    });
});

describe('isFullyApproved', function () {
    it('returns true when all services have all 3 parties approved', function () {
        $plan = JobPrepPlan::factory()->create();

        // Create approvals for painting - all 3 parties
        foreach (['team', 'crew', 'customer'] as $party) {
            JobPrepApproval::factory()->approved()->create([
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $this->paintingCategory->id,
                'party' => $party,
            ]);
        }

        // Create approvals for cleaning - all 3 parties
        foreach (['team', 'crew', 'customer'] as $party) {
            JobPrepApproval::factory()->approved()->create([
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $this->cleaningCategory->id,
                'party' => $party,
            ]);
        }

        expect($plan->isFullyApproved())->toBeTrue();
    });

    it('returns false when any service is missing an approval', function () {
        $plan = JobPrepPlan::factory()->create();

        // Painting: all 3 approved
        foreach (['team', 'crew', 'customer'] as $party) {
            JobPrepApproval::factory()->approved()->create([
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $this->paintingCategory->id,
                'party' => $party,
            ]);
        }

        // Cleaning: only team and crew approved, customer NOT approved
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaningCategory->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaningCategory->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaningCategory->id,
            'approved' => false,
        ]);

        expect($plan->isFullyApproved())->toBeFalse();
    });

    it('uses legacy fallback when no approval rows exist', function () {
        $plan = JobPrepPlan::factory()->approved()->create();

        // No JobPrepApproval rows exist, legacy columns are all true
        expect($plan->approvals()->count())->toBe(0)
            ->and($plan->isFullyApproved())->toBeTrue();

        // Now test legacy false case
        $partialPlan = JobPrepPlan::factory()->teamApproved()->create();

        expect($partialPlan->approvals()->count())->toBe(0)
            ->and($partialPlan->isFullyApproved())->toBeFalse();
    });
});

describe('isServiceVisibleToCustomer', function () {
    it('returns true when team AND crew have approved for that service', function () {
        $plan = JobPrepPlan::factory()->create();

        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        expect($plan->isServiceVisibleToCustomer($this->paintingCategory->id))->toBeTrue();
    });

    it('returns false when only team has approved', function () {
        $plan = JobPrepPlan::factory()->create();

        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->paintingCategory->id,
        ]);

        // No crew approval exists at all
        expect($plan->isServiceVisibleToCustomer($this->paintingCategory->id))->toBeFalse();
    });
});
