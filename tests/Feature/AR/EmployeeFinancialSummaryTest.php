<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->viewer = User::factory()->internal()->withFullAccess()->create();

    $this->employee = User::factory()->internal()->create();
    $this->profile = EmployeeProfile::factory()->create([
        'user_id' => $this->employee->id,
        'job_title' => 'Sales Rep',
    ]);
});

it('renders the financial summary page for user with permission', function () {
    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertStatus(200)
        ->assertSee($this->employee->name)
        ->assertSee('As Lead Generator')
        ->assertSee('As Salesperson')
        ->assertSee('As Production Manager')
        ->assertSee('Commissions Summary')
        ->assertSee('Flat Rates')
        ->assertSee('Door Knock Activity')
        ->assertSee('Payables Owed');
});

it('shows 403 for unauthorized user', function () {
    $unauthorized = User::factory()->internal()->create();

    Livewire::actingAs($unauthorized)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertForbidden();
});

it('shows deals where user is lead generator', function () {
    $deal = Deal::factory()->create([
        'lead_generator_id' => $this->employee->id,
        'title' => 'Lead Gen Deal Alpha',
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('Lead Gen Deal Alpha');
});

it('shows deals where user is salesperson', function () {
    $deal = Deal::factory()->create([
        'assigned_to' => $this->employee->id,
        'title' => 'Sales Deal Beta',
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('Sales Deal Beta');
});

it('shows jobs where user is production manager', function () {
    $job = Job::factory()->withProductionManager($this->employee)->create([
        'title' => 'Managed Job Gamma',
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('Managed Job Gamma');
});

it('shows commissions summary with correct totals', function () {
    CommissionEntry::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'amount' => 500.00,
        'status' => 'pending',
    ]);
    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $this->profile->id,
        'amount' => 300.00,
    ]);
    CommissionEntry::factory()->paid()->create([
        'employee_profile_id' => $this->profile->id,
        'amount' => 200.00,
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('$500.00')
        ->assertSee('$300.00')
        ->assertSee('$200.00');
});

it('shows flat rate commission entries', function () {
    CommissionEntry::factory()->flat(150)->create([
        'employee_profile_id' => $this->profile->id,
        'source_label' => 'Door Knock Bonus',
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('Door Knock Bonus')
        ->assertSee('$150.00');
});

it('shows door knock stats with conversion rate', function () {
    DoorKnock::factory()->count(8)->create(['user_id' => $this->employee->id, 'deal_id' => null]);
    DoorKnock::factory()->count(2)->create([
        'user_id' => $this->employee->id,
        'deal_id' => Deal::factory(),
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('10')  // total knocks
        ->assertSee('20%');  // conversion rate
});

it('shows payables owed to user', function () {
    $job = Job::factory()->create();
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'payee_user_id' => $this->employee->id,
        'description' => 'Labor Payment for Smith Job',
        'amount' => 1250.00,
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('Labor Payment for Smith Job')
        ->assertSee('$1,250.00');
});

it('shows employee job title in header', function () {
    Livewire::actingAs($this->viewer)
        ->test('team.financial-summary', ['user' => $this->employee])
        ->assertSee('Sales Rep');
});
