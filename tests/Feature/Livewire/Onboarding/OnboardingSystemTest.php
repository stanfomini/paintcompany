<?php

use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\User;

// ──────────────────────────────────────────────────
// OnboardingChecklist Model Tests
// ──────────────────────────────────────────────────

describe('OnboardingChecklist model', function () {
    it('creates a checklist with default values', function () {
        $checklist = OnboardingChecklist::factory()->create();

        expect($checklist->is_active)->toBeTrue();
        expect($checklist->sort_order)->toBe(0);
        expect($checklist->role_name)->toBeNull();
    });

    it('scopes to active checklists', function () {
        OnboardingChecklist::factory()->create(['is_active' => true]);
        OnboardingChecklist::factory()->inactive()->create();

        expect(OnboardingChecklist::active()->count())->toBe(1);
    });

    it('scopes for role includes null and matching roles', function () {
        OnboardingChecklist::factory()->create(['role_name' => null]);
        OnboardingChecklist::factory()->forRole('Painter')->create();
        OnboardingChecklist::factory()->forRole('Manager')->create();

        $forPainter = OnboardingChecklist::forRole('Painter')->get();

        expect($forPainter)->toHaveCount(2); // null + Painter
    });

    it('has many items ordered by sort_order', function () {
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'sort_order' => 2,
        ]);
        OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
            'sort_order' => 1,
        ]);

        $items = $checklist->items;

        expect($items)->toHaveCount(2);
        expect($items->first()->sort_order)->toBe(1);
        expect($items->last()->sort_order)->toBe(2);
    });
});

// ──────────────────────────────────────────────────
// OnboardingChecklistItem Model Tests
// ──────────────────────────────────────────────────

describe('OnboardingChecklistItem model', function () {
    it('belongs to a checklist', function () {
        $checklist = OnboardingChecklist::factory()->create();
        $item = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);

        expect($item->checklist->id)->toBe($checklist->id);
    });

    it('supports required flag', function () {
        $required = OnboardingChecklistItem::factory()->create(['is_required' => true]);
        $optional = OnboardingChecklistItem::factory()->optional()->create();

        expect($required->is_required)->toBeTrue();
        expect($optional->is_required)->toBeFalse();
    });

    it('supports different types', function () {
        $task = OnboardingChecklistItem::factory()->task()->create();
        $doc = OnboardingChecklistItem::factory()->document()->create();
        $contract = OnboardingChecklistItem::factory()->contract()->create();
        $training = OnboardingChecklistItem::factory()->training()->create();

        expect($task->type)->toBe('task');
        expect($doc->type)->toBe('document');
        expect($contract->type)->toBe('contract');
        expect($training->type)->toBe('training');
    });

    it('has many progress records', function () {
        $item = OnboardingChecklistItem::factory()->create();
        EmployeeOnboardingProgress::factory()->create([
            'onboarding_checklist_item_id' => $item->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'onboarding_checklist_item_id' => $item->id,
        ]);

        expect($item->progressRecords)->toHaveCount(2);
    });
});

// ──────────────────────────────────────────────────
// EmployeeOnboardingProgress Model Tests
// ──────────────────────────────────────────────────

describe('EmployeeOnboardingProgress model', function () {
    it('creates progress record with pending status', function () {
        $progress = EmployeeOnboardingProgress::factory()->create();

        expect($progress->status)->toBe('pending');
        expect($progress->completed_at)->toBeNull();
    });

    it('marks progress as completed', function () {
        $progress = EmployeeOnboardingProgress::factory()->completed()->create();

        expect($progress->status)->toBe('completed');
        expect($progress->completed_at)->not->toBeNull();
    });

    it('supports skipped status', function () {
        $progress = EmployeeOnboardingProgress::factory()->skipped()->create();

        expect($progress->status)->toBe('skipped');
    });

    it('supports in-progress status', function () {
        $progress = EmployeeOnboardingProgress::factory()->inProgress()->create();

        expect($progress->status)->toBe('in_progress');
    });

    it('belongs to employee profile', function () {
        $employee = EmployeeProfile::factory()->create();
        $progress = EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $employee->id,
        ]);

        expect($progress->employeeProfile->id)->toBe($employee->id);
    });

    it('belongs to checklist item', function () {
        $item = OnboardingChecklistItem::factory()->create();
        $progress = EmployeeOnboardingProgress::factory()->create([
            'onboarding_checklist_item_id' => $item->id,
        ]);

        expect($progress->checklistItem->id)->toBe($item->id);
    });

    it('tracks who completed the item', function () {
        $user = User::factory()->internal()->create();
        $progress = EmployeeOnboardingProgress::factory()->completed()->create([
            'completed_by' => $user->id,
        ]);

        expect($progress->completedByUser->id)->toBe($user->id);
    });

    it('calculates progress percentage for employee', function () {
        $checklist = OnboardingChecklist::factory()->create();
        $item1 = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);
        $item2 = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);
        $item3 = OnboardingChecklistItem::factory()->create([
            'onboarding_checklist_id' => $checklist->id,
        ]);

        $employee = EmployeeProfile::factory()->create();

        // Complete 2 out of 3 items
        EmployeeOnboardingProgress::factory()->completed()->create([
            'employee_profile_id' => $employee->id,
            'onboarding_checklist_item_id' => $item1->id,
        ]);
        EmployeeOnboardingProgress::factory()->completed()->create([
            'employee_profile_id' => $employee->id,
            'onboarding_checklist_item_id' => $item2->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $employee->id,
            'onboarding_checklist_item_id' => $item3->id,
            'status' => 'pending',
        ]);

        $totalItems = $checklist->items()->count();
        $completedItems = EmployeeOnboardingProgress::query()
            ->where('employee_profile_id', $employee->id)
            ->where('status', 'completed')
            ->count();

        $percentage = ($completedItems / $totalItems) * 100;

        expect($totalItems)->toBe(3);
        expect($completedItems)->toBe(2);
        expect(round($percentage, 1))->toBe(66.7);
    });

    it('stores notes on progress record', function () {
        $progress = EmployeeOnboardingProgress::factory()->completed()->create([
            'notes' => 'Employee completed training with flying colors',
        ]);

        expect($progress->notes)->toBe('Employee completed training with flying colors');
    });
});
