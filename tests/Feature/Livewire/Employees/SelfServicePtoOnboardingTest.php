<?php

use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\PtoRequest;
use App\Models\User;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// My PTO Tests
// ──────────────────────────────────────────────────

describe('My PTO', function () {
    it('renders for employee', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.pto'))
            ->assertSuccessful();
    });

    it('denies access for non-employees', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('my.pto'))
            ->assertForbidden();
    });

    it('can submit a PTO request', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('scheduling.my-pto')
            ->set('formType', 'vacation')
            ->set('formStartDate', now()->addWeek()->toDateString())
            ->set('formEndDate', now()->addWeek()->addDays(2)->toDateString())
            ->set('formHours', '24.0')
            ->set('formNotes', 'Family trip')
            ->call('submitRequest')
            ->assertHasNoErrors();

        expect(PtoRequest::where('employee_profile_id', $employee->id)->count())->toBe(1);

        $request = PtoRequest::where('employee_profile_id', $employee->id)->first();
        expect($request->type)->toBe('vacation');
        expect($request->notes)->toBe('Family trip');
        expect($request->status)->toBe('pending');
    });

    it('shows PTO request in list after submission', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $pto = PtoRequest::factory()->create([
            'employee_profile_id' => $employee->id,
            'type' => 'vacation',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test('scheduling.my-pto')
            ->assertSee('Vacation');
    });

    it('can cancel a pending request', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $pto = PtoRequest::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test('scheduling.my-pto')
            ->call('cancelRequest', $pto->id)
            ->assertHasNoErrors();

        expect(PtoRequest::find($pto->id))->toBeNull();
    });

    it('cannot cancel an approved request', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $pto = PtoRequest::factory()->approved()->create([
            'employee_profile_id' => $employee->id,
        ]);

        Livewire::actingAs($user)
            ->test('scheduling.my-pto')
            ->call('cancelRequest', $pto->id);

        expect(PtoRequest::find($pto->id))->not->toBeNull();
    });

    it('shows stats for current year', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        PtoRequest::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'pending',
        ]);
        PtoRequest::factory()->approved()->create([
            'employee_profile_id' => $employee->id,
        ]);
        PtoRequest::factory()->denied()->create([
            'employee_profile_id' => $employee->id,
        ]);

        Livewire::actingAs($user)
            ->test('scheduling.my-pto')
            ->assertSee('Total Requested')
            ->assertSee('Approved')
            ->assertSee('Pending')
            ->assertSee('Denied');
    });
});

// ──────────────────────────────────────────────────
// My Onboarding Tests
// ──────────────────────────────────────────────────

describe('My Onboarding', function () {
    it('renders for employee', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.onboarding'))
            ->assertSuccessful();
    });

    it('denies access for non-employees', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('my.onboarding'))
            ->assertForbidden();
    });

    it('shows checklist items', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'job_title' => 'Painter',
        ]);

        $checklist = OnboardingChecklist::factory()->create(['is_active' => true, 'role_name' => null]);
        $item = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'title' => 'Read Safety Manual',
            'is_required' => true,
        ]);

        Livewire::actingAs($user)
            ->test('onboarding.my-progress')
            ->assertSee('Read Safety Manual')
            ->assertSee('Required');
    });

    it('can toggle item completion', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'job_title' => 'Painter',
        ]);

        $checklist = OnboardingChecklist::factory()->create(['is_active' => true, 'role_name' => null]);
        $item = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'title' => 'Complete W-4 Form',
            'is_required' => true,
        ]);

        Livewire::actingAs($user)
            ->test('onboarding.my-progress')
            ->call('toggleItem', $item->id);

        $progress = EmployeeOnboardingProgress::where('employee_profile_id', $employee->id)
            ->where('onboarding_checklist_item_id', $item->id)
            ->first();

        expect($progress->status)->toBe('completed');
        expect($progress->completed_at)->not->toBeNull();
    });

    it('shows progress percentage', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'job_title' => 'Painter',
        ]);

        $checklist = OnboardingChecklist::factory()->create(['is_active' => true, 'role_name' => null]);
        $item1 = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'title' => 'Item One',
            'is_required' => true,
        ]);
        $item2 = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'title' => 'Item Two',
            'is_required' => true,
        ]);

        Livewire::actingAs($user)
            ->test('onboarding.my-progress')
            ->assertSee('0/2')
            ->assertSee('0%')
            ->call('toggleItem', $item1->id)
            ->assertSee('1/2')
            ->assertSee('50%');
    });

    it('can toggle item back to pending', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'job_title' => 'Painter',
        ]);

        $checklist = OnboardingChecklist::factory()->create(['is_active' => true, 'role_name' => null]);
        $item = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'title' => 'Test Item',
            'is_required' => true,
        ]);

        Livewire::actingAs($user)
            ->test('onboarding.my-progress')
            ->call('toggleItem', $item->id)
            ->call('toggleItem', $item->id);

        $progress = EmployeeOnboardingProgress::where('employee_profile_id', $employee->id)
            ->where('onboarding_checklist_item_id', $item->id)
            ->first();

        expect($progress->status)->toBe('pending');
        expect($progress->completed_at)->toBeNull();
    });
});
