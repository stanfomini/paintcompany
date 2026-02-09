<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\JobPrepApproval;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->create(['customer_id' => $this->customer->id]);
    $this->painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
    $this->cleaning = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'description' => 'Cleaning services', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );
});

describe('Gated Service Visibility', function () {
    it('only shows services where both team and crew have approved', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        // Painting tasks
        JobPrepTask::factory()->surface()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Paint Living Room Walls',
        ]);

        // Cleaning tasks
        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
            'title' => 'Deep Clean Windows',
        ]);

        // Painting: team + crew approved = visible
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);

        // Cleaning: only team approved = NOT visible
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
        ]);
        JobPrepApproval::factory()->crew()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Paint Living Room Walls')
            ->assertDontSee('Deep Clean Windows')
            ->assertSee('Additional service details will appear once confirmed by the team');
    });

    it('shows all services when both team and crew approve all', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Paint Hallway',
        ]);
        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
            'title' => 'Clean Garage',
        ]);

        // Both services: team + crew approved
        foreach ([$this->painting, $this->cleaning] as $cat) {
            JobPrepApproval::factory()->team()->approved()->create([
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $cat->id,
            ]);
            JobPrepApproval::factory()->crew()->approved()->create([
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $cat->id,
            ]);
            JobPrepApproval::factory()->customer()->create([
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $cat->id,
            ]);
        }

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Paint Hallway')
            ->assertSee('Clean Garage')
            ->assertDontSee('Additional service details will appear once confirmed by the team');
    });

    it('hides all services when neither team nor crew has approved', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Painting Task Hidden',
        ]);

        // No approvals set at all, but approval rows exist
        JobPrepApproval::factory()->team()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->crew()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertDontSee('Painting Task Hidden')
            ->assertSee('Additional service details will appear once confirmed by the team');
    });
});

describe('Per-Section Approval', function () {
    it('can approve a visible service section', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Paint Kitchen',
        ]);

        // team + crew approved
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Approve Painting Section')
            ->call('approveServiceSection', $this->painting->id);

        $approval = JobPrepApproval::where('job_prep_plan_id', $plan->id)
            ->where('service_category_id', $this->painting->id)
            ->where('party', 'customer')
            ->first();

        expect($approval->approved)->toBeTrue();
        expect($approval->approved_by)->toBe($this->customerUser->id);
    });

    it('cannot approve a hidden service section', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
            'title' => 'Clean Windows',
        ]);

        // Only team approved, crew NOT approved = not visible
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
        ]);
        JobPrepApproval::factory()->crew()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->cleaning->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('approveServiceSection', $this->cleaning->id);

        $approval = JobPrepApproval::where('job_prep_plan_id', $plan->id)
            ->where('service_category_id', $this->cleaning->id)
            ->where('party', 'customer')
            ->first();

        expect($approval->approved)->toBeFalse();
    });

    it('shows approved confirmation after customer approves a section', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Paint Bedroom',
        ]);

        // All three approved
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->customer()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'approved_by' => $this->customerUser->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('You approved this section')
            ->assertDontSee('Approve Painting Section');
    });
});

describe('Task Description Display', function () {
    it('shows task description text when available', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Prep Walls',
            'description' => 'Sand and prime all surfaces before painting',
        ]);

        // Make visible
        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Sand and prime all surfaces before painting');
    });
});

describe('Legacy Plan Compatibility', function () {
    it('shows legacy tri-party approval when no service-based approvals exist', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'title' => 'Legacy Task',
        ]);

        // No approval rows = legacy mode
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Plan Approval')
            ->assertSee('Approve Plan');
    });

    it('can approve legacy plan', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'title' => 'Legacy Task',
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('approvePrepPlan');

        $plan->refresh();
        expect($plan->customer_approved)->toBeTrue();
    });
});

describe('Service Headers and Badges', function () {
    it('displays service name in section headers with approval badges', function () {
        $job = Job::factory()->create([
            'deal_id' => $this->deal->id,
            'stage' => 'job_prep',
        ]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
            'title' => 'Paint Task',
        ]);

        JobPrepApproval::factory()->team()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->crew()->approved()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);
        JobPrepApproval::factory()->customer()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Painting Tasks');
    });
});
