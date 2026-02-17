<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\User;

describe('GenerateDailyFollowUps command', function () {
    it('creates follow-up tasks for active customers', function () {
        $user = User::factory()->customer()->create();

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        $this->assertDatabaseHas('activities', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'type' => 'task',
            'automation_key' => 'daily_followup',
            'title' => "Follow up with {$user->name}",
            'priority' => 'normal',
            'is_completed' => false,
        ]);
    });

    it('creates follow-up tasks for active agents', function () {
        $user = User::factory()->agent()->create();

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        $this->assertDatabaseHas('activities', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'automation_key' => 'daily_followup',
            'title' => "Follow up with {$user->name}",
        ]);
    });

    it('creates follow-up tasks for active crew members', function () {
        $user = User::factory()->crew()->create();

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        $this->assertDatabaseHas('activities', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'automation_key' => 'daily_followup',
            'title' => "Follow up with {$user->name}",
        ]);
    });

    it('skips users who already have an incomplete future follow-up task', function () {
        $user = User::factory()->customer()->create();

        Activity::factory()
            ->task()
            ->forUser($user)
            ->automated('daily_followup')
            ->create([
                'is_completed' => false,
                'due_at' => now()->addDay()->setTime(9, 0),
            ]);

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        expect(Activity::where('automation_key', 'daily_followup')
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->count()
        )->toBe(1);
    });

    it('skips users who already have an incomplete task due today', function () {
        $user = User::factory()->customer()->create();

        Activity::factory()
            ->task()
            ->forUser($user)
            ->automated('daily_followup')
            ->create([
                'is_completed' => false,
                'due_at' => today()->setTime(9, 0),
            ]);

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        expect(Activity::where('automation_key', 'daily_followup')
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->count()
        )->toBe(1);
    });

    it('skips users marked as lost', function () {
        $user = User::factory()->customer()->create([
            'follow_up_status' => 'lost',
        ]);

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        $this->assertDatabaseMissing('activities', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'automation_key' => 'daily_followup',
        ]);
    });

    it('skips inactive users', function () {
        $user = User::factory()->customer()->inactive()->create();

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        $this->assertDatabaseMissing('activities', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'automation_key' => 'daily_followup',
        ]);
    });

    it('assigns task to customer assigned_to when available', function () {
        $internalUser = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();

        Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internalUser->id,
        ]);

        $this->artisan('followups:generate-daily')
            ->assertSuccessful();

        $this->assertDatabaseHas('activities', [
            'subject_type' => User::class,
            'subject_id' => $customerUser->id,
            'automation_key' => 'daily_followup',
            'assigned_to' => $internalUser->id,
            'user_id' => $internalUser->id,
        ]);
    });
});
