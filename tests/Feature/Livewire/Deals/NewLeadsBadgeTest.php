<?php

use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

it('shows count of deals in lead stage', function () {
    $user = User::factory()->internal()->create();

    Deal::factory()->count(3)->create(['stage' => 'lead']);
    Deal::factory()->create(['stage' => 'quoted']);
    Deal::factory()->create(['stage' => 'signed']);

    Livewire::actingAs($user)
        ->test('deals.new-leads-badge')
        ->assertSee('3')
        ->assertSeeHtml('bg-blue-500');
});

it('hides badge when no new leads exist', function () {
    $user = User::factory()->internal()->create();

    Deal::factory()->create(['stage' => 'quoted']);

    Livewire::actingAs($user)
        ->test('deals.new-leads-badge')
        ->assertDontSeeHtml('bg-blue-500');
});

it('updates count after refresh', function () {
    $user = User::factory()->internal()->create();

    Deal::factory()->count(2)->create(['stage' => 'lead']);

    $component = Livewire::actingAs($user)
        ->test('deals.new-leads-badge')
        ->assertSee('2');

    Deal::factory()->create(['stage' => 'lead']);

    $component->call('refreshCount')
        ->assertSee('3');
});
