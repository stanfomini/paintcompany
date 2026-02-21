<?php

use App\Models\User;
use Livewire\Livewire;

it('renders territory manager with tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Map')
        ->assertSee('Hierarchy')
        ->assertSee('Dispatch')
        ->assertSee('Analytics')
        ->assertSet('activeTab', 'map');
});

it('switches between tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'hierarchy')
        ->assertSet('activeTab', 'hierarchy');
});

it('hides dispatch tab without dispatch permission', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Dispatch');
});

it('hides analytics tab without analytics permission', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Analytics');
});
