<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateAmendment;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::create(['company_name' => 'Test Company']);
});

it('shows disabled sign button and review link when pending amendments exist', function () {
    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);
    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create();

    $response = $this->get(route('estimates.public', ['token' => $estimate->public_token]));
    $response->assertOk();
    $response->assertSee('Review and acknowledge changes made to this estimate before signing');
});

it('allows signature after all amendments acknowledged', function () {
    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);
    EstimateAmendment::factory()->forEstimate($estimate)->acknowledged()->create();

    $response = $this->get(route('estimates.public', ['token' => $estimate->public_token]));
    $response->assertOk();
    $response->assertDontSee('Review and acknowledge changes made to this estimate before signing');
});

it('shows pending amendments section with single acknowledgment form', function () {
    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);
    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create([
        'amendment_number' => 1,
        'changes' => [
            ['category' => 'area', 'action' => 'added', 'label' => 'Kitchen', 'details' => 'New area added: Kitchen'],
        ],
    ]);

    $response = $this->get(route('estimates.public', ['token' => $estimate->public_token]));
    $response->assertOk();
    $response->assertSee('Action Required: Review Changes');
    $response->assertSee('I Acknowledge All Changes');
});

it('shows change history for acknowledged amendments', function () {
    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);
    EstimateAmendment::factory()->forEstimate($estimate)->acknowledged()->create([
        'amendment_number' => 1,
        'customer_initials' => 'JD',
        'changes' => [
            ['category' => 'surface', 'action' => 'modified', 'label' => 'Bedroom - Walls', 'field' => 'Coats', 'old_value' => '2', 'new_value' => '3'],
        ],
    ]);

    $response = $this->get(route('estimates.public', ['token' => $estimate->public_token]));
    $response->assertOk();
    $response->assertSee('Change History');
    $response->assertSee('Acknowledged (JD)');
});

it('shows no amendment sections when none exist', function () {
    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);

    $response = $this->get(route('estimates.public', ['token' => $estimate->public_token]));
    $response->assertOk();
    $response->assertDontSee('Action Required: Review Changes');
    $response->assertDontSee('Change History');
});

it('acknowledges all pending amendments with single initials', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create([
        'created_by' => $internalUser->id,
        'valid_until' => now()->addMonth(),
    ]);
    $estimate->generatePublicToken(30);

    $amendment1 = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create(['amendment_number' => 1]);
    $amendment2 = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create(['amendment_number' => 2]);
    $amendment3 = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create(['amendment_number' => 3]);

    $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
        ->set('amendmentInitials', 'JD')
        ->call('acknowledgeAllAmendments')
        ->assertHasNoErrors();

    $amendment1->refresh();
    $amendment2->refresh();
    $amendment3->refresh();

    expect($amendment1->isAcknowledged())->toBeTrue()
        ->and($amendment1->customer_initials)->toBe('JD')
        ->and($amendment2->isAcknowledged())->toBeTrue()
        ->and($amendment2->customer_initials)->toBe('JD')
        ->and($amendment3->isAcknowledged())->toBeTrue()
        ->and($amendment3->customer_initials)->toBe('JD');
});

it('validates initials required for acknowledgment', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create([
        'created_by' => $internalUser->id,
        'valid_until' => now()->addMonth(),
    ]);
    $estimate->generatePublicToken(30);

    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create();

    Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
        ->set('amendmentInitials', '')
        ->call('acknowledgeAllAmendments')
        ->assertHasErrors(['amendmentInitials' => 'required']);
});

it('validates initials minimum length for acknowledgment', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->sent()->create([
        'created_by' => $internalUser->id,
        'valid_until' => now()->addMonth(),
    ]);
    $estimate->generatePublicToken(30);

    EstimateAmendment::factory()->forEstimate($estimate)->pending()->create();

    Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
        ->set('amendmentInitials', 'A')
        ->call('acknowledgeAllAmendments')
        ->assertHasErrors(['amendmentInitials' => 'min']);
});
