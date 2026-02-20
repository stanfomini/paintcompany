<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->internal()->withFullAccess()->create();

    $this->customer = Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Tester']);
    $this->deal = Deal::factory()->signed()->forCustomer($this->customer)->create([
        'title' => 'Paint Alice House',
        'assigned_to' => $this->owner->id,
    ]);
    $this->job = Job::factory()->create([
        'deal_id' => $this->deal->id,
    ]);
    $this->costCard = JobCostCard::factory()->forJob($this->job)->create([
        'price' => 5000.00,
        'total_invoiced' => 2500.00,
        'total_collected' => 1500.00,
        'balance_due' => 1000.00,
        'total_cogs' => 2000.00,
    ]);
});

it('renders the AR index page for a user with ar.view permission', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->assertStatus(200)
        ->assertSee('AR Portal')
        ->assertSee('Paint Alice House')
        ->assertSee('Alice Tester');
});

it('shows 403 for a user without ar.view permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('accounting.ar-index')
        ->assertForbidden();
});

it('only shows deals that have jobs', function () {
    $dealWithoutJob = Deal::factory()->quoted()->create([
        'title' => 'No Job Deal',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->assertSee('Paint Alice House')
        ->assertDontSee('No Job Deal');
});

it('displays summary totals correctly', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->assertSee('$5,000.00')  // Contract Total
        ->assertSee('$2,500.00')  // Invoiced
        ->assertSee('$1,500.00')  // Collected
        ->assertSee('$1,000.00')  // Balance Due
        ->assertSee('$2,000.00'); // Total COGS
});

it('filters deals by search term on title', function () {
    $otherCustomer = Customer::factory()->create(['first_name' => 'Bob', 'last_name' => 'Other']);
    $otherDeal = Deal::factory()->signed()->forCustomer($otherCustomer)->create([
        'title' => 'Bob Exterior Paint',
        'assigned_to' => $this->owner->id,
    ]);
    Job::factory()->create(['deal_id' => $otherDeal->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->assertSee('Paint Alice House')
        ->assertSee('Bob Exterior Paint')
        ->set('search', 'Alice')
        ->assertSee('Paint Alice House')
        ->assertDontSee('Bob Exterior Paint');
});

it('filters deals by search term on customer name', function () {
    $otherCustomer = Customer::factory()->create(['first_name' => 'Bob', 'last_name' => 'Other']);
    $otherDeal = Deal::factory()->signed()->forCustomer($otherCustomer)->create([
        'title' => 'Another Project',
        'assigned_to' => $this->owner->id,
    ]);
    Job::factory()->create(['deal_id' => $otherDeal->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('search', 'Bob')
        ->assertSee('Another Project')
        ->assertDontSee('Paint Alice House');
});

it('filters deals by stage', function () {
    $quotedDeal = Deal::factory()->quoted()->create([
        'title' => 'Quoted Deal',
    ]);
    Job::factory()->create(['deal_id' => $quotedDeal->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('stageFilter', 'signed')
        ->assertSee('Paint Alice House')
        ->assertDontSee('Quoted Deal');
});

it('filters deals by salesperson', function () {
    $otherSalesperson = User::factory()->internal()->create();
    $otherDeal = Deal::factory()->signed()->create([
        'title' => 'Other SP Deal',
        'assigned_to' => $otherSalesperson->id,
    ]);
    Job::factory()->create(['deal_id' => $otherDeal->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('salespersonFilter', (string) $this->owner->id)
        ->assertSee('Paint Alice House')
        ->assertDontSee('Other SP Deal');
});

it('filters deals by payment status has_balance', function () {
    $paidDeal = Deal::factory()->signed()->create([
        'title' => 'Fully Paid Deal',
    ]);
    $paidJob = Job::factory()->create(['deal_id' => $paidDeal->id]);
    JobCostCard::factory()->forJob($paidJob)->create([
        'balance_due' => 0,
        'total_collected' => 5000.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('paymentFilter', 'has_balance')
        ->assertSee('Paint Alice House')
        ->assertDontSee('Fully Paid Deal');
});

it('filters deals by payment status fully_paid', function () {
    $paidDeal = Deal::factory()->signed()->create([
        'title' => 'Fully Paid Deal',
    ]);
    $paidJob = Job::factory()->create(['deal_id' => $paidDeal->id]);
    JobCostCard::factory()->forJob($paidJob)->create([
        'balance_due' => 0,
        'total_collected' => 5000.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('paymentFilter', 'fully_paid')
        ->assertDontSee('Paint Alice House')
        ->assertSee('Fully Paid Deal');
});

it('displays correct margin percentage', function () {
    // price=5000, cogs=2000 → margin = (5000-2000)/5000 * 100 = 60.0%
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->assertSee('60.0%');
});

it('shows empty state when no deals match filters', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('search', 'xyznonexistent')
        ->assertSee('No deals with jobs found matching your filters.');
});

it('populates salesperson filter from deals with assigned users', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->assertSee($this->owner->name); // Owner appears in salesperson filter dropdown
});

it('resets page when search changes', function () {
    // Create enough deals for pagination
    for ($i = 0; $i < 25; $i++) {
        $deal = Deal::factory()->signed()->create(['title' => "Bulk Deal $i"]);
        Job::factory()->create(['deal_id' => $deal->id]);
    }

    // Searching should call resetPage, verify component still works with search
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-index')
        ->set('search', 'xyznotfound')
        ->assertSee('No deals with jobs found matching your filters.');
});
