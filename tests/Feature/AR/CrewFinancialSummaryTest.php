<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->internal()->withFullAccess()->create();

    $this->crewUser = User::factory()->crew()->create(['name' => 'Test Crew Member']);

    $this->customer = Customer::factory()->create();
    $this->deal = Deal::factory()->signed()->forCustomer($this->customer)->create([
        'assigned_to' => $this->owner->id,
    ]);
    $this->job = Job::factory()->create([
        'deal_id' => $this->deal->id,
    ]);

    // Attach crew to job
    $this->job->crews()->attach($this->crewUser->id, [
        'status' => 'accepted',
        'dispatched_at' => now()->subDays(3),
        'responded_at' => now()->subDays(2),
    ]);
});

// ── Financial Summary Page ──

it('renders crew financial summary page for user with crew.view_financials permission', function () {
    Livewire::actingAs($this->owner)
        ->test('crew.financial-summary', ['user' => $this->crewUser])
        ->assertStatus(200)
        ->assertSee('Test Crew Member')
        ->assertSee('Total Earned')
        ->assertSee('Total Paid')
        ->assertSee('Outstanding')
        ->assertSee('Jobs')
        ->assertSee('All Payables');
});

it('shows 403 for user without crew.view_financials permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('crew.financial-summary', ['user' => $this->crewUser])
        ->assertForbidden();
});

it('shows correct job payables for the crew member', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $this->crewUser->id,
        'payee_type' => 'crew',
        'description' => 'Crew labor for interior',
        'amount' => 2500.00,
        'status' => JobPayable::STATUS_APPROVED,
    ]);

    // Payable for a different user - should not appear
    $otherCrew = User::factory()->crew()->create();
    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $otherCrew->id,
        'payee_type' => 'crew',
        'description' => 'Other crew payment',
        'amount' => 1000.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('crew.financial-summary', ['user' => $this->crewUser])
        ->assertSee('Crew labor for interior')
        ->assertSee('$2,500.00')
        ->assertDontSee('Other crew payment');
});

it('calculates totals correctly', function () {
    // Approved payable
    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $this->crewUser->id,
        'payee_type' => 'crew',
        'description' => 'Labor deposit',
        'amount' => 1000.00,
        'status' => JobPayable::STATUS_APPROVED,
    ]);

    // Paid payable
    JobPayable::factory()->paid()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $this->crewUser->id,
        'payee_type' => 'crew',
        'description' => 'Final payment',
        'amount' => 3000.00,
    ]);

    // Pending payable (should not count in earned)
    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $this->crewUser->id,
        'payee_type' => 'crew',
        'description' => 'Pending bonus',
        'amount' => 500.00,
        'status' => JobPayable::STATUS_PENDING,
    ]);

    $component = Livewire::actingAs($this->owner)
        ->test('crew.financial-summary', ['user' => $this->crewUser]);

    // Total earned = approved + paid = 1000 + 3000 = 4000
    expect($component->get('totalEarned'))->toBe(4000.0);

    // Total paid = 3000
    expect($component->get('totalPaid'))->toBe(3000.0);

    // Outstanding = 4000 - 3000 = 1000
    expect($component->get('totalOutstanding'))->toBe(1000.0);
});

it('shows jobs assigned to the crew member', function () {
    Livewire::actingAs($this->owner)
        ->test('crew.financial-summary', ['user' => $this->crewUser])
        ->assertSee($this->job->title)
        ->assertSee($this->job->job_number);
});

// ── Self-Service Financials Tab on Crew Job Show ──

it('shows crew payables in the financials tab of job show', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $this->crewUser->id,
        'payee_type' => 'crew',
        'description' => 'Crew deposit payment',
        'amount' => 1500.00,
        'status' => JobPayable::STATUS_PAID,
        'paid_at' => now(),
        'paid_via' => 'check',
        'reference_number' => 'CHK-1234',
    ]);

    Livewire::actingAs($this->crewUser, 'crew')
        ->test('crew.job-show', ['job' => $this->job])
        ->call('setTab', 'financials')
        ->assertSee('Your Payables')
        ->assertSee('Crew deposit payment')
        ->assertSee('$1,500.00')
        ->assertSee('CHK-1234');
});

it('only shows the logged-in crew payables not other crew payables', function () {
    // Payable for the logged-in crew
    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $this->crewUser->id,
        'payee_type' => 'crew',
        'description' => 'My crew payment',
        'amount' => 2000.00,
        'status' => JobPayable::STATUS_APPROVED,
    ]);

    // Payable for another crew on the same job
    $otherCrew = User::factory()->crew()->create();
    $this->job->crews()->attach($otherCrew->id, ['status' => 'accepted']);
    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_user_id' => $otherCrew->id,
        'payee_type' => 'crew',
        'description' => 'Other crew secret payment',
        'amount' => 5000.00,
        'status' => JobPayable::STATUS_APPROVED,
    ]);

    Livewire::actingAs($this->crewUser, 'crew')
        ->test('crew.job-show', ['job' => $this->job])
        ->call('setTab', 'financials')
        ->assertSee('My crew payment')
        ->assertDontSee('Other crew secret payment');
});
