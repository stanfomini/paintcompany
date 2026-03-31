<?php

use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use App\Services\Prospector\NumberSelectionService;
use App\Services\Prospector\PhoneNumberService;

beforeEach(function () {
    $this->user = User::factory()->create(['type' => 'internal']);
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

// ─── Number Preview (11A) ────────────────────────────────

it('previews local presence number for matching contact area code', function () {
    $localNumber = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'area_code' => '303',
        'role' => 'local_presence',
    ]);

    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'phone_number_id' => null,
        'use_local_presence' => true,
    ]);

    $contact = Contact::factory()->forCompany($this->company)->withPhone('+13035551234')->create();

    $service = new NumberSelectionService;
    $preview = $service->previewNumberForContact($contact, $this->company, $campaign);

    expect($preview)->not->toBeNull();
    expect($preview->id)->toBe($localNumber->id);
});

it('previews main number when no local match exists', function () {
    $mainNumber = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $this->company->update(['main_phone_number_id' => $mainNumber->id]);

    $contact = Contact::factory()->forCompany($this->company)->withPhone('+19995559999')->create();

    $service = new NumberSelectionService;
    $preview = $service->previewNumberForContact($contact, $this->company);

    expect($preview)->not->toBeNull();
    expect($preview->id)->toBe($mainNumber->id);
});

it('returns null preview when no number available', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();

    $service = new NumberSelectionService;
    $preview = $service->previewNumberForContact($contact, $this->company);

    expect($preview)->toBeNull();
});

// ─── Number Pool Grouping (11B) ──────────────────────────

it('groups numbers by company and role', function () {
    // Create main number
    PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    // Create local presence numbers
    PhoneNumber::factory()->forAccount($this->account)->localPresence()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'area_code' => '303',
    ]);

    PhoneNumber::factory()->forAccount($this->account)->localPresence()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'area_code' => '720',
    ]);

    // Create shadow number
    PhoneNumber::factory()->forAccount($this->account)->shadow()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $service = new PhoneNumberService;
    $pools = $service->getNumberPoolsByCompany();

    expect($pools)->toHaveCount(1);

    $pool = $pools->first();
    expect($pool['company']->id)->toBe($this->company->id);
    expect($pool['main'])->not->toBeNull();
    expect($pool['main']->role)->toBe('main');
    expect($pool['local_presence'])->toHaveCount(2);
    expect($pool['shadow'])->toHaveCount(1);
});

it('returns empty pools when no numbers assigned', function () {
    $service = new PhoneNumberService;
    $pools = $service->getNumberPoolsByCompany();

    expect($pools)->toHaveCount(0);
});

// ─── Daily Usage Stats (11D) ─────────────────────────────

it('gets today usage by number', function () {
    $mainNumber = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'phone_number' => '+13035550100',
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    // Create calls from today
    Call::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'from_number' => '+13035550100',
        'started_at' => now(),
    ]);

    // Create a call from yesterday (should not count)
    Call::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'from_number' => '+13035550100',
        'started_at' => now()->subDay(),
    ]);

    $service = new PhoneNumberService;
    $usage = $service->getTodayUsageByNumber($this->company);

    expect($usage)->toHaveKey('+13035550100');
    expect($usage['+13035550100'])->toBe(3);
});

it('gets daily usage stats for last 7 days', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();

    // Create calls across multiple days
    Call::factory()->count(5)->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'from_number' => '+13035550100',
        'started_at' => now(),
    ]);

    Call::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'from_number' => '+13035550100',
        'started_at' => now()->subDays(2),
    ]);

    $service = new PhoneNumberService;
    $stats = $service->getDailyUsageStats($this->company, 7);

    expect($stats)->not->toBeEmpty();

    $todayStats = collect($stats)->firstWhere('date', now()->format('Y-m-d'));
    expect($todayStats)->not->toBeNull();
    expect($todayStats['call_count'])->toBe(5);
});

it('returns empty stats when no calls exist', function () {
    $service = new PhoneNumberService;
    $stats = $service->getDailyUsageStats($this->company, 7);

    expect($stats)->toBeEmpty();
});

// ─── Phone Numbers Page (11B) ────────────────────────────

it('renders phone numbers page with pool view', function () {
    PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)->test('pages::prospector.phone-numbers')
        ->assertStatus(200)
        ->assertSee($this->company->name)
        ->assertSee('Pool View');
});

it('renders phone numbers page with table view', function () {
    PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)->test('pages::prospector.phone-numbers')
        ->set('view', 'table')
        ->assertStatus(200)
        ->assertSee('Table View');
});

it('opens purchase modal', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.phone-numbers')
        ->call('openPurchaseModal')
        ->assertSet('showPurchaseModal', true);
});

it('validates area code for number search', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.phone-numbers')
        ->set('purchaseAreaCode', 'abc')
        ->set('purchaseAccountId', $this->account->id)
        ->call('searchNumbers')
        ->assertHasErrors(['purchaseAreaCode']);
});

it('opens health dashboard modal', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.phone-numbers')
        ->call('openHealthDashboard', $this->company->id)
        ->assertSet('showHealthModal', true)
        ->assertSet('healthCompanyId', $this->company->id);
});

it('shows today usage count in table view', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'phone_number' => '+13035550100',
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    Call::factory()->count(5)->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'from_number' => '+13035550100',
        'started_at' => now(),
    ]);

    Livewire::actingAs($this->user)->test('pages::prospector.phone-numbers')
        ->set('view', 'table')
        ->assertSee('5');
});
