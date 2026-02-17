<?php

use App\Models\Applicant;
use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// Hire Modal uses EmployeeService
// ──────────────────────────────────────────────────

describe('Hire modal uses EmployeeService', function () {
    it('creates employee via EmployeeService::hireApplicant and initializes onboarding', function () {
        Notification::fake();

        $admin = User::factory()->internal()->create();
        $admin->givePermissionTo('hiring.manage');

        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->task()->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);

        $applicant = Applicant::factory()->create(['stage' => 'offer']);

        Livewire::actingAs($admin)
            ->test('hiring.hire-modal')
            ->call('open', $applicant->id)
            ->set('job_title', 'Lead Painter')
            ->set('pay_rate', '25')
            ->set('pay_type', 'hourly')
            ->set('password', 'Password123!')
            ->call('hire')
            ->assertHasNoErrors();

        $applicant->refresh();
        expect($applicant->stage)->toBe('hired');
        expect($applicant->converted_user_id)->not->toBeNull();

        $profile = EmployeeProfile::where('user_id', $applicant->converted_user_id)->first();
        expect($profile)->not->toBeNull();
        expect($profile->job_title)->toBe('Lead Painter');

        // Onboarding was initialized
        expect(EmployeeOnboardingProgress::where('employee_profile_id', $profile->id)->count())->toBe(1);
    });
});

// ──────────────────────────────────────────────────
// Employee Onboarding Wizard
// ──────────────────────────────────────────────────

describe('Employee onboarding wizard', function () {
    it('does not set onboarding_completed_at on completeOnboarding', function () {
        $employee = EmployeeProfile::factory()->create([
            'phone' => null,
            'onboarding_completed_at' => null,
        ]);
        $this->actingAs($employee->user);

        Livewire::test('employees.employee-onboarding')
            ->set('phone', '555-123-4567')
            ->set('dateOfBirth', '1990-01-15')
            ->set('step', 4)
            ->set('addressLine1', '123 Main St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zipCode', '80202')
            ->set('emergencyContactName', 'Jane Doe')
            ->set('emergencyContactPhone', '555-987-6543')
            ->set('emergencyContactRelation', 'Spouse')
            ->call('completeOnboarding')
            ->assertRedirect(route('my.onboarding'));

        $employee->refresh();
        expect($employee->onboarding_completed_at)->toBeNull();
        expect($employee->phone)->toBe('555-123-4567');
    });

    it('redirects to my.onboarding after completing wizard', function () {
        $employee = EmployeeProfile::factory()->create([
            'phone' => null,
            'onboarding_completed_at' => null,
        ]);
        $this->actingAs($employee->user);

        Livewire::test('employees.employee-onboarding')
            ->set('phone', '555-111-2222')
            ->set('dateOfBirth', '1985-06-20')
            ->set('step', 4)
            ->set('addressLine1', '456 Oak Ave')
            ->set('city', 'Boulder')
            ->set('state', 'CO')
            ->set('zipCode', '80301')
            ->set('emergencyContactName', 'John Smith')
            ->set('emergencyContactPhone', '555-333-4444')
            ->set('emergencyContactRelation', 'Parent')
            ->call('completeOnboarding')
            ->assertRedirect(route('my.onboarding'));
    });
});

// ──────────────────────────────────────────────────
// Dashboard Redirect Logic
// ──────────────────────────────────────────────────

describe('Dashboard redirect logic', function () {
    it('redirects to onboarding wizard when profile is empty and not onboarded', function () {
        $employee = EmployeeProfile::factory()->create([
            'phone' => null,
            'address_line_1' => null,
            'onboarding_completed_at' => null,
        ]);
        $this->actingAs($employee->user);

        Livewire::test('dashboard')
            ->assertRedirect(route('employee.onboarding'));
    });

    it('does not redirect when profile has data but onboarding incomplete', function () {
        $employee = EmployeeProfile::factory()->create([
            'phone' => '555-123-4567',
            'address_line_1' => '123 Main St',
            'onboarding_completed_at' => null,
        ]);
        $this->actingAs($employee->user);

        Livewire::test('dashboard')
            ->assertNoRedirect()
            ->assertSee('Onboarding in progress');
    });

    it('does not show onboarding banner when onboarding is complete', function () {
        $employee = EmployeeProfile::factory()->onboarded()->create();
        $this->actingAs($employee->user);

        Livewire::test('dashboard')
            ->assertNoRedirect()
            ->assertDontSee('Onboarding in progress');
    });

    it('shows pending items count in onboarding banner', function () {
        $employee = EmployeeProfile::factory()->create([
            'phone' => '555-123-4567',
            'address_line_1' => '123 Main St',
            'onboarding_completed_at' => null,
        ]);

        $checklist = OnboardingChecklist::factory()->create();
        $items = OnboardingChecklistItem::factory()->count(3)->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);

        foreach ($items as $item) {
            EmployeeOnboardingProgress::factory()->create([
                'employee_profile_id' => $employee->id,
                'onboarding_checklist_item_id' => $item->id,
                'status' => 'pending',
            ]);
        }

        $this->actingAs($employee->user);

        Livewire::test('dashboard')
            ->assertNoRedirect()
            ->assertSee('Onboarding in progress')
            ->assertSee('3 items remaining');
    });
});
