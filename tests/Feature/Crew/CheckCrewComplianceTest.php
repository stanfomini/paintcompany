<?php

use App\Models\Activity;
use App\Models\User;
use App\Notifications\CrewComplianceExpiring;
use Illuminate\Support\Facades\Notification;

describe('crew:check-compliance command', function () {
    it('creates a task when insurance is expiring soon', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(15),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')
            ->assertSuccessful()
            ->expectsOutputToContain('1 new alert(s) created');

        expect(Activity::where('subject_type', User::class)
            ->where('subject_id', $crew->id)
            ->where('type', 'task')
            ->where('title', 'like', 'Crew Compliance Alert:%')
            ->count())->toBe(1);
    });

    it('creates a task when workers comp is expired', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'workers_comp_expiration_date' => now()->subDays(5),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        $activity = Activity::where('subject_id', $crew->id)
            ->where('type', 'task')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->description)->toContain("Workers' Compensation");
        expect($activity->description)->toContain('EXPIRED');
    });

    it('creates a task when license is expiring soon', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'license_expiration_date' => now()->addDays(20),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        $activity = Activity::where('subject_id', $crew->id)
            ->where('type', 'task')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->description)->toContain('Contractor License');
    });

    it('sends notifications to internal users', function () {
        $internal1 = User::factory()->internal()->withFullAccess()->create();
        $internal2 = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(10),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        Notification::assertSentTo($internal1, CrewComplianceExpiring::class);
        Notification::assertSentTo($internal2, CrewComplianceExpiring::class);
    });

    it('does not create duplicate tasks for the same crew', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(15),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();
        $this->artisan('crew:check-compliance')->assertSuccessful();

        expect(Activity::where('subject_id', $crew->id)
            ->where('type', 'task')
            ->where('title', 'like', 'Crew Compliance Alert:%')
            ->count())->toBe(1);
    });

    it('creates new task after previous one is completed', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(15),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        // Complete the task
        Activity::where('subject_id', $crew->id)->first()->markAsCompleted();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        expect(Activity::where('subject_id', $crew->id)
            ->where('type', 'task')
            ->where('title', 'like', 'Crew Compliance Alert:%')
            ->count())->toBe(2);
    });

    it('skips compliant crews', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addMonths(6),
            'workers_comp_expiration_date' => now()->addMonths(6),
            'license_expiration_date' => now()->addYear(),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')
            ->assertSuccessful()
            ->expectsOutputToContain('0 new alert(s) created');

        expect(Activity::where('subject_id', $crew->id)->count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('skips inactive crews', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->inactive()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->subDays(5),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')
            ->assertSuccessful()
            ->expectsOutputToContain('0 new alert(s) created');

        Notification::assertNothingSent();
    });

    it('sets due date to now for expired documents', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->subDays(5),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        $activity = Activity::where('subject_id', $crew->id)->first();
        expect($activity->due_at->isToday())->toBeTrue();
    });

    it('sets due date to 7 days for expiring soon documents', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(20),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        $activity = Activity::where('subject_id', $crew->id)->first();
        expect($activity->due_at->isFuture())->toBeTrue();
        expect((int) abs(now()->diffInDays($activity->due_at)))->toBeBetween(6, 8);
    });

    it('includes multiple expiring items in one task', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->subDays(5),
            'workers_comp_expiration_date' => now()->addDays(10),
            'license_expiration_date' => now()->addDays(15),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        $activity = Activity::where('subject_id', $crew->id)->first();
        expect($activity->description)->toContain('General Liability Insurance');
        expect($activity->description)->toContain("Workers' Compensation");
        expect($activity->description)->toContain('Contractor License');
        // Has expired items, so should say EXPIRED
        expect($activity->description)->toContain('EXPIRED');
    });

    it('assigns task to internal user', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(15),
        ]);

        Notification::fake();

        $this->artisan('crew:check-compliance')->assertSuccessful();

        $activity = Activity::where('subject_id', $crew->id)->first();
        expect($activity->user_id)->toBe($internal->id);
        expect($activity->assigned_to)->toBe($internal->id);
    });
});
