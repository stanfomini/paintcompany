<?php

use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Leads on Pipeline', function () {
    it('displays agent leads section when activities exist for agent users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create(['name' => 'Test Agent Smith']);

        Activity::factory()->meeting()->create([
            'subject_type' => User::class,
            'subject_id' => $agent->id,
            'title' => 'Meet with Agent Smith',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Agent Leads')
            ->assertSee('Test Agent Smith')
            ->assertSee('Meet with Agent Smith');
    });

    it('hides agent leads section when no agent activities exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertDontSee('Agent Leads');
    });

    it('does not show completed agent lead activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create(['name' => 'Completed Agent']);

        Activity::factory()->meeting()->completed()->create([
            'subject_type' => User::class,
            'subject_id' => $agent->id,
            'title' => 'Already done meeting',
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertDontSee('Agent Leads')
            ->assertDontSee('Already done meeting');
    });

    it('does not show activities for non-agent users in agent leads', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = User::factory()->create(['type' => 'customer']);

        Activity::factory()->create([
            'subject_type' => User::class,
            'subject_id' => $customer->id,
            'title' => 'Customer activity',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertDontSee('Agent Leads')
            ->assertDontSee('Customer activity');
    });

    it('can claim an agent lead by assigning to current user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        $activity = Activity::factory()->create([
            'subject_type' => User::class,
            'subject_id' => $agent->id,
            'title' => 'Claimable Lead',
            'is_completed' => false,
            'assigned_to' => null,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Assign to Me')
            ->call('claimAgentLead', $activity->id);

        $activity->refresh();
        expect($activity->assigned_to)->toBe($user->id);
    });

    it('can complete an agent lead activity', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        $activity = Activity::factory()->create([
            'subject_type' => User::class,
            'subject_id' => $agent->id,
            'title' => 'Completable Lead',
            'is_completed' => false,
            'assigned_to' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Mark Done')
            ->call('completeAgentLead', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
        expect($activity->completed_at)->not->toBeNull();
    });

    it('shows type badge on agent lead cards', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Activity::factory()->meeting()->create([
            'subject_type' => User::class,
            'subject_id' => $agent->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Meeting');
    });

    it('shows assignee name when agent lead is claimed', function () {
        $user = User::factory()->internal()->withFullAccess()->create(['name' => 'John Claimer']);
        $agent = User::factory()->agent()->create();

        Activity::factory()->create([
            'subject_type' => User::class,
            'subject_id' => $agent->id,
            'title' => 'Assigned Lead',
            'is_completed' => false,
            'assigned_to' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Assigned: John Claimer');
    });
});
