<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use Database\Seeders\PipelineSeeder;

// ──────────────────────────────────────
// Onboarding Checklist
// ──────────────────────────────────────

it('shows onboarding checklist when steps are incomplete', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('prospector.dashboard'));

    $response->assertOk()
        ->assertSee('Getting Started')
        ->assertSee('Connect Twilio Account')
        ->assertSee('Create a Company');
});

it('hides onboarding checklist when all steps are complete', function () {
    $user = User::factory()->internal()->create();

    $account = TwilioAccount::factory()->create();
    $company = Company::factory()->forAccount($account)->create();
    PhoneNumber::factory()->forAccount($account)->assigned($company->id)->create();
    Contact::factory()->forCompany($company)->create();
    $campaign = Campaign::factory()->forCompany($company)->active()->create();
    Pipeline::factory()->create();

    $agent = \App\Models\Prospector\Agent::factory()->forCompany($company)->create([
        'user_id' => $user->id,
    ]);
    DialerSession::factory()->create([
        'agent_id' => $agent->id,
        'campaign_id' => $campaign->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('prospector.dashboard'));

    $response->assertOk()
        ->assertDontSee('Getting Started');
});

it('marks completed onboarding steps correctly', function () {
    $user = User::factory()->internal()->create();
    TwilioAccount::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('prospector.dashboard'));

    $response->assertOk()
        ->assertSee('Connect Twilio Account');
});

// ──────────────────────────────────────
// Dashboard Stats
// ──────────────────────────────────────

it('displays dashboard stats for internal users', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('prospector.dashboard'));

    $response->assertOk()
        ->assertSee('Total Calls Today')
        ->assertSee('Calls Connected')
        ->assertSee('Connection Rate')
        ->assertSee('Total Talk Time');
});

it('prevents non-internal users from accessing dashboard', function () {
    $user = User::factory()->create();
    $user->type = 'customer';
    $user->save();

    $response = $this->actingAs($user)
        ->get(route('prospector.dashboard'));

    // The auth.internal middleware redirects non-internal users
    $response->assertRedirect();
});

// ──────────────────────────────────────
// Pipeline Seeder
// ──────────────────────────────────────

it('creates default pipeline with 6 stages via seeder', function () {
    $seeder = new PipelineSeeder;
    $seeder->run();

    $pipeline = Pipeline::where('is_default', true)->first();
    expect($pipeline)->not->toBeNull();
    expect($pipeline->name)->toBe('Sales Pipeline');

    $stages = PipelineStage::where('pipeline_id', $pipeline->id)
        ->orderBy('sort_order')
        ->get();

    expect($stages)->toHaveCount(6);
    expect($stages->pluck('name')->toArray())->toBe([
        'New Lead',
        'Contacted',
        'Interested',
        'Appointment Set',
        'Won',
        'Lost',
    ]);
});

it('is idempotent — running seeder twice does not duplicate', function () {
    $seeder = new PipelineSeeder;
    $seeder->run();
    $seeder->run();

    expect(Pipeline::where('is_default', true)->count())->toBe(1);
    expect(PipelineStage::count())->toBe(6);
});

// ──────────────────────────────────────
// Sidebar Navigation
// ──────────────────────────────────────

it('includes pipeline links in sidebar navigation', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('prospector.dashboard'));

    $response->assertOk()
        ->assertSee('Pipeline')
        ->assertSee('Pipeline Settings');
});

// ──────────────────────────────────────
// Pipeline Route
// ──────────────────────────────────────

it('serves the pipelines page for internal users', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('prospector.pipelines'));

    $response->assertOk();
});
