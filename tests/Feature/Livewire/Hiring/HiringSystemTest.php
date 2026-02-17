<?php

use App\Models\Applicant;
use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeOnboardingProgress;
use App\Models\JobListing;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\User;
use App\Notifications\EmployeeWelcome;
use App\Services\EmployeeService;
use Illuminate\Support\Facades\Notification;

// ──────────────────────────────────────────────────
// JobListing Model Tests
// ──────────────────────────────────────────────────

describe('JobListing model', function () {
    it('creates a listing with default values', function () {
        $listing = JobListing::factory()->create();

        expect($listing->status)->toBe('active');
        expect($listing->positions_filled)->toBe(0);
        expect($listing->published_at)->not->toBeNull();
        expect($listing->qr_code_token)->not->toBeNull();
    });

    it('auto-generates qr_code_token on creation', function () {
        $listing = JobListing::factory()->create();

        expect($listing->qr_code_token)->not->toBeNull();
        expect(strlen($listing->qr_code_token))->toBe(36); // UUID length
    });

    it('identifies active listings', function () {
        $listing = JobListing::factory()->create(['status' => 'active']);

        expect($listing->isActive())->toBeTrue();
        expect($listing->isClosed())->toBeFalse();
    });

    it('identifies closed listings', function () {
        $listing = JobListing::factory()->closed()->create();

        expect($listing->isClosed())->toBeTrue();
        expect($listing->isActive())->toBeFalse();
    });

    it('calculates remaining positions', function () {
        $listing = JobListing::factory()->create([
            'positions_available' => 5,
            'positions_filled' => 3,
        ]);

        expect($listing->remainingPositions())->toBe(2);
    });

    it('returns zero remaining when all positions filled', function () {
        $listing = JobListing::factory()->create([
            'positions_available' => 2,
            'positions_filled' => 2,
        ]);

        expect($listing->remainingPositions())->toBe(0);
    });

    it('does not return negative remaining positions', function () {
        $listing = JobListing::factory()->create([
            'positions_available' => 2,
            'positions_filled' => 5,
        ]);

        expect($listing->remainingPositions())->toBe(0);
    });

    it('displays pay range with min and max', function () {
        $listing = JobListing::factory()->create([
            'pay_range_min' => 20.00,
            'pay_range_max' => 35.00,
        ]);

        expect($listing->getPayRangeDisplay())->toBe('$20 - $35');
    });

    it('displays pay range with min only', function () {
        $listing = JobListing::factory()->create([
            'pay_range_min' => 18.00,
            'pay_range_max' => null,
        ]);

        expect($listing->getPayRangeDisplay())->toBe('From $18');
    });

    it('displays pay range with max only', function () {
        $listing = JobListing::factory()->create([
            'pay_range_min' => null,
            'pay_range_max' => 45.00,
        ]);

        expect($listing->getPayRangeDisplay())->toBe('Up to $45');
    });

    it('displays Competitive when no pay range set', function () {
        $listing = JobListing::factory()->create([
            'pay_range_min' => null,
            'pay_range_max' => null,
        ]);

        expect($listing->getPayRangeDisplay())->toBe('Competitive');
    });

    it('scopes to active listings', function () {
        JobListing::factory()->create(['status' => 'active']);
        JobListing::factory()->draft()->create();
        JobListing::factory()->closed()->create();

        expect(JobListing::active()->count())->toBe(1);
    });

    it('scopes to published listings', function () {
        JobListing::factory()->create(['published_at' => now()]);
        JobListing::factory()->draft()->create(); // published_at = null

        expect(JobListing::published()->count())->toBe(1);
    });

    it('has many applicants', function () {
        $listing = JobListing::factory()->create();
        Applicant::factory()->count(3)->create(['job_listing_id' => $listing->id]);

        expect($listing->applicants)->toHaveCount(3);
    });

    it('belongs to creator', function () {
        $user = User::factory()->internal()->create();
        $listing = JobListing::factory()->create(['created_by' => $user->id]);

        expect($listing->createdBy->id)->toBe($user->id);
    });
});

// ──────────────────────────────────────────────────
// Applicant Model Tests
// ──────────────────────────────────────────────────

describe('Applicant model', function () {
    it('creates applicant with default applied stage', function () {
        $applicant = Applicant::factory()->create();

        expect($applicant->stage)->toBe('applied');
        expect($applicant->source)->toBe('web');
    });

    it('returns full name', function () {
        $applicant = Applicant::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);

        expect($applicant->getFullName())->toBe('John Smith');
    });

    it('advances through pipeline stages', function () {
        $applicant = Applicant::factory()->create(['stage' => 'applied']);

        $applicant->advanceStage();
        expect($applicant->stage)->toBe('screening');

        $applicant->advanceStage();
        expect($applicant->stage)->toBe('phone_interview');

        $applicant->advanceStage();
        expect($applicant->stage)->toBe('in_person');

        $applicant->advanceStage();
        expect($applicant->stage)->toBe('background_check');

        $applicant->advanceStage();
        expect($applicant->stage)->toBe('offer');

        $applicant->advanceStage();
        expect($applicant->stage)->toBe('hired');
        expect($applicant->hired_at)->not->toBeNull();
    });

    it('does not advance past hired stage', function () {
        $applicant = Applicant::factory()->hired()->create();

        $applicant->advanceStage();

        expect($applicant->stage)->toBe('hired');
    });

    it('does not advance rejected applicants', function () {
        $applicant = Applicant::factory()->rejected()->create();

        $applicant->advanceStage();

        expect($applicant->stage)->toBe('rejected');
    });

    it('rejects applicant with reason', function () {
        $applicant = Applicant::factory()->create(['stage' => 'screening']);

        $applicant->reject('Not enough experience');

        $applicant->refresh();

        expect($applicant->stage)->toBe('rejected');
        expect($applicant->rejection_reason)->toBe('Not enough experience');
    });

    it('rejects applicant without reason', function () {
        $applicant = Applicant::factory()->create(['stage' => 'applied']);

        $applicant->reject();

        expect($applicant->stage)->toBe('rejected');
        expect($applicant->rejection_reason)->toBeNull();
    });

    it('identifies hired applicants', function () {
        $hired = Applicant::factory()->hired()->create();
        $notHired = Applicant::factory()->create(['stage' => 'offer']);

        expect($hired->isHired())->toBeTrue();
        expect($notHired->isHired())->toBeFalse();
    });

    it('belongs to job listing', function () {
        $listing = JobListing::factory()->create();
        $applicant = Applicant::factory()->create(['job_listing_id' => $listing->id]);

        expect($applicant->jobListing->id)->toBe($listing->id);
    });

    it('supports rating field', function () {
        $applicant = Applicant::factory()->create(['rating' => 4]);

        expect($applicant->rating)->toBe(4);
    });

    it('has stage constants defined', function () {
        expect(Applicant::STAGES)->toContain('applied');
        expect(Applicant::STAGES)->toContain('screening');
        expect(Applicant::STAGES)->toContain('hired');
        expect(Applicant::STAGES)->toContain('rejected');
    });

    it('has source constants defined', function () {
        expect(Applicant::SOURCES)->toHaveKey('web');
        expect(Applicant::SOURCES)->toHaveKey('qr_code');
        expect(Applicant::SOURCES)->toHaveKey('manual');
        expect(Applicant::SOURCES)->toHaveKey('referral');
    });

    it('sets hired_at when advancing to hired stage', function () {
        $applicant = Applicant::factory()->create(['stage' => 'offer']);

        expect($applicant->hired_at)->toBeNull();

        $applicant->advanceStage();

        expect($applicant->stage)->toBe('hired');
        expect($applicant->hired_at)->not->toBeNull();
    });
});

// ──────────────────────────────────────────────────
// Hire → Onboard → Contract Flow Tests
// ──────────────────────────────────────────────────

describe('hireApplicant onboarding and contracts', function () {
    it('creates onboarding progress records from active checklists', function () {
        Notification::fake();

        $checklist = OnboardingChecklist::factory()->create(['is_active' => true]);
        $items = OnboardingChecklistItem::factory()->count(3)->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);

        $applicant = Applicant::factory()->create(['stage' => 'offer']);

        $profile = app(EmployeeService::class)->hireApplicant($applicant);

        expect(EmployeeOnboardingProgress::where('employee_profile_id', $profile->id)->count())->toBe(3);

        foreach ($items as $item) {
            expect(EmployeeOnboardingProgress::where([
                'employee_profile_id' => $profile->id,
                'onboarding_checklist_item_id' => $item->id,
                'status' => 'pending',
            ])->exists())->toBeTrue();
        }
    });

    it('creates onboarding records for role-specific checklists', function () {
        Notification::fake();

        $listing = JobListing::factory()->create(['title' => 'Painter']);
        $applicant = Applicant::factory()->create([
            'job_listing_id' => $listing->id,
            'stage' => 'offer',
        ]);

        // General checklist (no role) - should match
        $general = OnboardingChecklist::factory()->create(['role_name' => null]);
        OnboardingChecklistItem::factory()->count(2)->create([
            'onboarding_checklist_id' => $general->id,
        ]);

        // Role-specific checklist matching "Painter" - should match
        $roleSpecific = OnboardingChecklist::factory()->forRole('Painter')->create();
        OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $roleSpecific->id,
        ]);

        // Role-specific checklist for different role - should NOT match
        $otherRole = OnboardingChecklist::factory()->forRole('Estimator')->create();
        OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $otherRole->id,
        ]);

        $profile = app(EmployeeService::class)->hireApplicant($applicant);

        // 2 general + 1 painter = 3 total (not the Estimator one)
        expect(EmployeeOnboardingProgress::where('employee_profile_id', $profile->id)->count())->toBe(3);
    });

    it('skips inactive checklists during onboarding', function () {
        Notification::fake();

        $active = OnboardingChecklist::factory()->create(['is_active' => true]);
        OnboardingChecklistItem::factory()->count(2)->create([
            'onboarding_checklist_id' => $active->id,
        ]);

        $inactive = OnboardingChecklist::factory()->inactive()->create();
        OnboardingChecklistItem::factory()->count(3)->create([
            'onboarding_checklist_id' => $inactive->id,
        ]);

        $applicant = Applicant::factory()->create(['stage' => 'offer']);
        $profile = app(EmployeeService::class)->hireApplicant($applicant);

        // Only 2 from the active checklist, not the 3 from the inactive one
        expect(EmployeeOnboardingProgress::where('employee_profile_id', $profile->id)->count())->toBe(2);
    });

    it('assigns active contract templates when assignDefaultContracts is called', function () {
        Notification::fake();

        ContractTemplate::factory()->count(2)->create(['is_active' => true]);
        ContractTemplate::factory()->inactive()->create();

        $applicant = Applicant::factory()->create(['stage' => 'offer']);
        $service = app(EmployeeService::class);
        $profile = $service->hireApplicant($applicant);

        // Contracts are not assigned during hire (assigned post-onboarding)
        expect(EmployeeContract::where('employee_profile_id', $profile->id)->count())->toBe(0);

        // Explicitly assign contracts (called from onboarding component)
        $service->assignDefaultContracts($profile);

        // Only 2 active templates should create contracts, auto-sent
        expect(EmployeeContract::where('employee_profile_id', $profile->id)->count())->toBe(2);
        expect(EmployeeContract::where('employee_profile_id', $profile->id)->first()->status)->toBe('sent');
    });

    it('resolves merge tags in assigned contracts', function () {
        Notification::fake();

        $template = ContractTemplate::factory()->create([
            'content' => 'Welcome {employee_name} to {company_name}!',
        ]);

        $applicant = Applicant::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'stage' => 'offer',
        ]);

        $service = app(EmployeeService::class);
        $profile = $service->hireApplicant($applicant);
        $service->assignDefaultContracts($profile);
        $contract = EmployeeContract::where('employee_profile_id', $profile->id)->first();

        expect($contract->content)->toContain('Welcome Jane Doe');
    });

    it('sends welcome notification when applicant is hired', function () {
        Notification::fake();

        $applicant = Applicant::factory()->create(['stage' => 'offer']);
        $profile = app(EmployeeService::class)->hireApplicant($applicant);

        Notification::assertSentTo(
            $profile->user,
            EmployeeWelcome::class,
            function (EmployeeWelcome $notification) use ($profile) {
                return $notification->employeeProfile->id === $profile->id;
            }
        );
    });

    it('handles hire with no checklists or templates gracefully', function () {
        Notification::fake();

        // No checklists or templates in DB
        $applicant = Applicant::factory()->create(['stage' => 'offer']);
        $profile = app(EmployeeService::class)->hireApplicant($applicant);

        expect($profile)->not->toBeNull();
        expect(EmployeeOnboardingProgress::where('employee_profile_id', $profile->id)->count())->toBe(0);
        expect(EmployeeContract::where('employee_profile_id', $profile->id)->count())->toBe(0);

        Notification::assertSentTo($profile->user, EmployeeWelcome::class);
    });
});
