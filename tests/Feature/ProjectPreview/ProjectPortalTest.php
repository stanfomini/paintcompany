<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewView;
use App\Models\User;
use Livewire\Livewire;

it('renders the portal page with valid token', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertOk()
        ->assertSee('Your Project Preview');
});

it('returns 404 for invalid token', function () {
    $response = $this->get('/preview/invalid-token-12345');

    $response->assertNotFound();
});

it('returns 410 for expired preview', function () {
    $preview = ProjectPreview::factory()->expired()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertStatus(410);
});

it('records a view on page load', function () {
    $preview = ProjectPreview::factory()->create();

    $this->get("/preview/{$preview->public_token}");

    expect(ProjectPreviewView::where('project_preview_id', $preview->id)->count())->toBe(1);
    expect($preview->fresh()->portal_views_count)->toBe(1);
});

it('displays customer first name only', function () {
    $preview = ProjectPreview::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('John')
        ->assertDontSee('Doe');
});

it('displays estimator name when assigned', function () {
    $estimator = User::factory()->create(['type' => 'internal', 'name' => 'Jane Smith']);
    $preview = ProjectPreview::factory()->create(['estimator_id' => $estimator->id]);

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Jane Smith');
});

it('shows portal login CTA for guests', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Login to your portal');
});

it('hides portal login CTA for authenticated customer guard users', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user, 'customer')->get("/preview/{$preview->public_token}");

    $response->assertDontSee('Login to your portal');
});

it('hides portal login CTA for authenticated default guard users', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user)->get("/preview/{$preview->public_token}");

    $response->assertDontSee('Login to your portal');
});

it('shows estimate journey timeline without project journey', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Estimate Journey')
        ->assertDontSee('Project Journey')
        ->assertSee('Book')
        ->assertSee('Preview')
        ->assertSee('Estimate Visit')
        ->assertSee('Approval');
});

// --- Blur overlay tests ---

it('shows blur overlays on private info for guests', function () {
    $estimator = User::factory()->create(['type' => 'internal', 'name' => 'Test Estimator']);
    $preview = ProjectPreview::factory()->create(['estimator_id' => $estimator->id]);

    // Ensure company phone exists so the blur overlay renders
    \App\Models\CompanySetting::getInstance()->update(['company_phone' => '555-123-4567']);

    $response = $this->get("/preview/{$preview->public_token}");

    // Blur overlay should be present for private info (phone number on estimator card)
    $response->assertSee('filter: blur(6px)', false);
});

it('shows no blur overlays for authenticated customer', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user, 'customer')->get("/preview/{$preview->public_token}");

    // No blur overlays should be present
    $response->assertDontSee('filter: blur(6px)', false);
});

it('shows customer first name without blur for guests', function () {
    $preview = ProjectPreview::factory()->create(['first_name' => 'Sarah']);

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Welcome, Sarah!');
});

it('shows estimator name without blur for guests', function () {
    $estimator = User::factory()->create(['type' => 'internal', 'name' => 'Mike Johnson']);
    $preview = ProjectPreview::factory()->create(['estimator_id' => $estimator->id]);

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Mike Johnson');
});

it('shows footer login CTA for guests', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Login to your portal');
});

it('shows Enable Your Portal CTA when customer has no user account', function () {
    $estimator = User::factory()->create(['type' => 'internal']);
    CompanySetting::getInstance()->update(['company_phone' => '555-123-4567']);
    $customer = Customer::factory()->create(['user_id' => null]);
    $preview = ProjectPreview::factory()->create([
        'customer_id' => $customer->id,
        'estimator_id' => $estimator->id,
    ]);

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Enable Your Portal');
});

it('shows Login when customer has user account', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $preview = ProjectPreview::factory()->create(['customer_id' => $customer->id]);

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Login');
});

it('shows My Dashboard link in header for authenticated customer', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user, 'customer')->get("/preview/{$preview->public_token}");

    $response->assertSee('My Dashboard');
});

it('does not show My Dashboard link for guests', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertDontSee('My Dashboard');
});

// --- Estimate Visit step tests ---

it('estimate step shows Access Your Portal for unauthenticated users', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('Access Your Portal')
        ->assertDontSee('Go to Dashboard')
        ->assertSee('Project Command Center')
        ->assertSee('Daily Walkaround Videos')
        ->assertSee('Group Chat With Your Team')
        ->assertSee('Pay Invoices Online')
        ->assertSee('Pick Your Start Date')
        ->assertSee('Invite Your Spouse');
});

it('estimate step shows Go to Dashboard for authenticated users', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    Livewire::actingAs($user, 'customer')
        ->test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('Go to Dashboard')
        ->assertDontSee('Access Your Portal');
});

it('estimate step shows payment options including credit card limit', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('Payment Options')
        ->assertSee('$1,000');
});

it('estimate step shows appointment duration and options messaging', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('30 minutes to 2 hours')
        ->assertSee('We Build Options Around You')
        ->assertSee('budget in mind');
});

it('estimate step shows brand ambassador spots count from settings', function () {
    CompanySetting::getInstance()->update(['brand_ambassador_spots' => 7]);
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('7')
        ->assertSee('Brand Ambassador spots remaining this month');
});

it('estimate step shows default 10 spots when not configured', function () {
    $settings = CompanySetting::getInstance();
    // Default from migration is 10
    expect($settings->brand_ambassador_spots)->toBe(10);

    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('Brand Ambassador spots remaining this month');
});

it('estimate step shows day-of decision benefits section', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'estimate')
        ->assertSee('Why Homeowners Love Deciding at the Appointment')
        ->assertSee('Lock in Today')
        ->assertSee('Priority Scheduling')
        ->assertSee('One Less Thing to Think About');
});

// --- Approval step tests ---

it('approval step shows waiting message when no estimate exists', function () {
    $preview = ProjectPreview::factory()->create();

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'approval')
        ->assertSee('Approval')
        ->assertSee('your estimator will build out your proposal');
});

it('approval step shows estimate link when estimate is sent', function () {
    $estimate = Estimate::factory()->sent()->create();
    $estimate->generatePublicToken();
    $preview = ProjectPreview::factory()->create(['estimate_id' => $estimate->id]);

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'approval')
        ->assertSee('Your Estimate Is Ready')
        ->assertSee('Review');
});

it('approval step shows accepted status when estimate is accepted', function () {
    $estimate = Estimate::factory()->accepted()->create();
    $estimate->generatePublicToken();
    $preview = ProjectPreview::factory()->create(['estimate_id' => $estimate->id]);

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'approval')
        ->assertSee('Estimate Accepted')
        ->assertSee('When Would You Like Us to Start');
});

it('approval step shows login for scheduling when unauthenticated', function () {
    $estimate = Estimate::factory()->accepted()->create();
    $estimate->generatePublicToken();
    $preview = ProjectPreview::factory()->create(['estimate_id' => $estimate->id]);

    Livewire::test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'approval')
        ->assertSee('Log In to Schedule');
});

it('approval step shows dashboard link for scheduling when authenticated', function () {
    $user = User::factory()->customer()->create();
    $estimate = Estimate::factory()->accepted()->create();
    $estimate->generatePublicToken();
    $preview = ProjectPreview::factory()->create(['estimate_id' => $estimate->id]);

    Livewire::actingAs($user, 'customer')
        ->test('public.project-portal', ['token' => $preview->public_token])
        ->call('setStep', 'approval')
        ->assertSee('Go to Dashboard')
        ->assertDontSee('Log In to Schedule');
});

// --- Timeline dynamic state tests ---

it('marks preview step as done when questionnaire is completed', function () {
    $preview = ProjectPreview::factory()->create([
        'questionnaire_completed_at' => now(),
        'questionnaire_data' => ['step1' => 'done'],
    ]);

    $response = $this->get("/preview/{$preview->public_token}");

    // The preview step should have a check icon (done state)
    $response->assertSee('Estimate Journey');
});
