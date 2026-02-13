<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('requires authentication to access lead sources settings', function () {
    $this->get(route('lead-sources.edit'))
        ->assertRedirect(route('login'));
});

it('requires settings.lead_sources permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('lead-sources.edit'))
        ->assertForbidden();
});

it('allows users with permission to access lead sources settings', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get(route('lead-sources.edit'))
        ->assertOk();
});

it('displays existing lead sources', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    LeadSource::factory()->create(['name' => 'Facebook Ads', 'slug' => 'facebook_ads', 'sort_order' => 1]);
    LeadSource::factory()->create(['name' => 'Google Ads', 'slug' => 'google_ads', 'sort_order' => 2]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->assertSee('Facebook Ads')
        ->assertSee('Google Ads');
});

it('can add a custom lead source', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('openAddModal')
        ->set('name', 'Yard Signs')
        ->set('icon', 'star')
        ->set('color', 'green')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lead_sources', [
        'name' => 'Yard Signs',
        'slug' => 'yard_signs',
        'icon' => 'star',
        'color' => 'green',
        'type' => 'custom',
        'is_system' => false,
    ]);
});

it('auto-generates slug from name', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('openAddModal')
        ->set('name', 'My New Source')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lead_sources', [
        'name' => 'My New Source',
        'slug' => 'my_new_source',
    ]);
});

it('validates required name when saving', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('openAddModal')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('validates unique name when saving', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    LeadSource::factory()->create(['name' => 'Existing Source', 'slug' => 'existing_source']);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('openAddModal')
        ->set('name', 'Existing Source')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('can edit a custom lead source', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $source = LeadSource::factory()->create(['name' => 'Old Name', 'slug' => 'old_name', 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('openEditModal', $source->id)
        ->set('name', 'New Name')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lead_sources', [
        'id' => $source->id,
        'name' => 'New Name',
    ]);
});

it('cannot delete system lead sources', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $source = LeadSource::factory()->system()->create(['name' => 'Referral', 'slug' => 'referral', 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('confirmDelete', $source->id);

    $this->assertDatabaseHas('lead_sources', ['id' => $source->id]);
});

it('cannot delete sources with customer references', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $source = LeadSource::factory()->create(['name' => 'Has Customers', 'slug' => 'has_customers', 'sort_order' => 1]);
    Customer::factory()->create(['lead_source_id' => $source->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('confirmDelete', $source->id);

    $this->assertDatabaseHas('lead_sources', ['id' => $source->id]);
});

it('cannot delete sources with deal references', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $source = LeadSource::factory()->create(['name' => 'Has Deals', 'slug' => 'has_deals', 'sort_order' => 1]);
    Deal::factory()->create(['lead_source_id' => $source->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('confirmDelete', $source->id);

    $this->assertDatabaseHas('lead_sources', ['id' => $source->id]);
});

it('can delete unreferenced custom sources', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $source = LeadSource::factory()->create(['name' => 'Deletable', 'slug' => 'deletable', 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('confirmDelete', $source->id)
        ->call('delete');

    $this->assertDatabaseMissing('lead_sources', ['id' => $source->id]);
});

it('can toggle lead source active status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $source = LeadSource::factory()->create(['is_active' => true, 'sort_order' => 1]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('toggleActive', $source->id);

    $source->refresh();
    expect($source->is_active)->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('toggleActive', $source->id);

    $source->refresh();
    expect($source->is_active)->toBeTrue();
});

it('can reorder lead sources', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $first = LeadSource::factory()->create(['name' => 'First', 'slug' => 'first', 'sort_order' => 1]);
    $second = LeadSource::factory()->create(['name' => 'Second', 'slug' => 'second', 'sort_order' => 2]);

    Livewire::actingAs($user)
        ->test('pages::settings.lead-sources')
        ->call('moveDown', $first->id);

    $first->refresh();
    $second->refresh();

    expect($first->sort_order)->toBeGreaterThan($second->sort_order);
});
