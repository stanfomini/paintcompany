<?php

use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewView;
use App\Models\User;

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

it('shows login CTA for guests on project journey timeline', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Login to unlock')
        ->assertSee('Enable Your Portal');
});

it('hides login CTA for authenticated customer guard users', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user, 'customer')->get("/preview/{$preview->public_token}");

    $response->assertDontSee('Login to unlock');
});

it('hides login CTA for authenticated default guard users', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user)->get("/preview/{$preview->public_token}");

    $response->assertDontSee('Login to unlock');
});

it('shows both journey timelines', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Estimate Journey')
        ->assertSee('Project Journey')
        ->assertSee('Book')
        ->assertSee('Preview')
        ->assertSee('Estimate Visit');
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

it('shows login CTAs for guests', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    // Project Journey CTA
    $response->assertSee('Login to unlock');
    // Footer login link
    $response->assertSee('Login to your portal');
});

it('shows Enable Your Portal when customer has no user account', function () {
    $customer = Customer::factory()->create(['user_id' => null]);
    $preview = ProjectPreview::factory()->create(['customer_id' => $customer->id]);

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
