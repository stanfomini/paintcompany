<?php

use App\Models\Job;
use App\Models\User;
use Livewire\Livewire;

it('shows count of jobs in estimate_accepted stage', function () {
    $user = User::factory()->internal()->create();

    Job::factory()->create(['stage' => 'estimate_accepted']);
    Job::factory()->create(['stage' => 'estimate_accepted']);
    Job::factory()->create(['stage' => 'in_progress']);

    Livewire::actingAs($user)
        ->test('jobs.accepted-badge')
        ->assertSee('2')
        ->assertSeeHtml('bg-emerald-500');
});

it('hides badge when no accepted jobs exist', function () {
    $user = User::factory()->internal()->create();

    Job::factory()->create(['stage' => 'in_progress']);

    Livewire::actingAs($user)
        ->test('jobs.accepted-badge')
        ->assertDontSeeHtml('bg-emerald-500');
});

it('updates count after refresh', function () {
    $user = User::factory()->internal()->create();

    Job::factory()->create(['stage' => 'estimate_accepted']);

    $component = Livewire::actingAs($user)
        ->test('jobs.accepted-badge')
        ->assertSee('1');

    Job::factory()->create(['stage' => 'estimate_accepted']);

    $component->call('refreshCount')
        ->assertSee('2');
});
