<?php

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

it('shows login CTA for guests', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('Unlock More Features')
        ->assertSee('Create Free Account');
});

it('hides login CTA for authenticated users', function () {
    $user = User::factory()->create(['type' => 'customer']);
    $preview = ProjectPreview::factory()->create();

    $response = $this->actingAs($user)->get("/preview/{$preview->public_token}");

    $response->assertDontSee('Unlock More Features');
});

it('shows What to Expect guide', function () {
    $preview = ProjectPreview::factory()->create();

    $response = $this->get("/preview/{$preview->public_token}");

    $response->assertSee('What to Expect')
        ->assertSee('Estimate Visit')
        ->assertSee('Paint Day');
});
