<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('TaskList scope: shows assigned tasks', function () {
    it('shows tasks assigned to user even if created by someone else', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $creator = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->forDeal($deal)->byUser($creator)->create([
            'title' => 'Assigned To Me',
            'assigned_to' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('Assigned To Me');
    });

    it('shows tasks created by user with no assignee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Created By Me',
            'assigned_to' => null,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('Created By Me');
    });

    it('does not show tasks created by and assigned to other users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->forDeal($deal)->byUser($otherUser)->create([
            'title' => 'Not My Task',
            'assigned_to' => $otherUser->id,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertDontSee('Not My Task');
    });

    it('can toggle completion on tasks assigned to user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $creator = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->forDeal($deal)->byUser($creator)->create([
            'title' => 'Toggle Assigned Task',
            'assigned_to' => $user->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
    });

    it('cannot toggle completion on unrelated tasks', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->forDeal($deal)->byUser($otherUser)->create([
            'title' => 'Not My Task',
            'assigned_to' => $otherUser->id,
            'is_completed' => false,
        ]);

        expect(fn () => Livewire::actingAs($user)
            ->test('activities.task-list')
            ->call('toggleComplete', $activity->id)
        )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });
});
