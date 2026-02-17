<?php

use App\Models\Applicant;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

// ── Hiring Dashboard ──────────────────────────────────────────────

it('renders the hiring dashboard', function () {
    $this->get(route('hiring.index'))
        ->assertSuccessful()
        ->assertSee('Hiring Dashboard');
});

it('shows correct stats on the hiring dashboard', function () {
    $listing = JobListing::factory()->create();
    Applicant::factory()->count(3)->for($listing)->create(['stage' => 'applied']);
    Applicant::factory()->for($listing)->create(['stage' => 'screening']);
    Applicant::factory()->for($listing)->hired()->create();

    Livewire::test('hiring.index')
        ->assertSee('5') // total applicants
        ->assertSuccessful();
});

it('denies access to hiring dashboard without permission', function () {
    $this->actingAs(User::factory()->internal()->create());

    $this->get(route('hiring.index'))
        ->assertForbidden();
});

// ── Job Listings ──────────────────────────────────────────────────

it('renders the job listings page', function () {
    $this->get(route('hiring.listings'))
        ->assertSuccessful()
        ->assertSee('Job Listings');
});

it('shows job listings in the table', function () {
    $listing = JobListing::factory()->create(['title' => 'Lead Painter']);

    Livewire::test('hiring.job-listings')
        ->assertSee('Lead Painter')
        ->assertSuccessful();
});

it('can activate a draft listing', function () {
    $listing = JobListing::factory()->draft()->create();

    Livewire::test('hiring.job-listings')
        ->call('activate', $listing->id);

    expect($listing->fresh()->status)->toBe('active');
    expect($listing->fresh()->published_at)->not->toBeNull();
});

it('can close an active listing', function () {
    $listing = JobListing::factory()->create(['status' => 'active']);

    Livewire::test('hiring.job-listings')
        ->call('close', $listing->id);

    expect($listing->fresh()->status)->toBe('closed');
    expect($listing->fresh()->closed_at)->not->toBeNull();
});

it('can search listings by title', function () {
    JobListing::factory()->create(['title' => 'Lead Painter']);
    JobListing::factory()->create(['title' => 'Color Consultant']);

    Livewire::test('hiring.job-listings')
        ->set('search', 'Color')
        ->assertSee('Color Consultant')
        ->assertDontSee('Lead Painter');
});

// ── QR Code ──────────────────────────────────────────────────────

it('generates a QR code SVG for an active listing', function () {
    $listing = JobListing::factory()->create(['status' => 'active']);

    Livewire::test('hiring.job-listings')
        ->call('showQrCode', $listing->id)
        ->assertSet('showQrModal', true)
        ->assertSee($listing->qr_code_token);
});

it('generates valid SVG content in the QR code', function () {
    $listing = JobListing::factory()->create(['status' => 'active']);

    $component = Livewire::test('hiring.job-listings')
        ->call('showQrCode', $listing->id);

    expect($component->get('qrCodeSvg'))->toContain('<svg');
    expect($component->get('qrCodeUrl'))->toContain("/careers/qr/{$listing->qr_code_token}");
});

it('shows QR Code menu item only for active listings', function () {
    $activeListing = JobListing::factory()->create(['status' => 'active']);
    $draftListing = JobListing::factory()->draft()->create();

    $component = Livewire::test('hiring.job-listings');

    // The active listing should render the Show QR Code button
    $html = $component->html();
    expect($html)->toContain("showQrCode({$activeListing->id})");
    expect($html)->not->toContain("showQrCode({$draftListing->id})");
});

// ── Listing Form Modal ────────────────────────────────────────────

it('can create a new job listing via modal', function () {
    Livewire::test('hiring.listing-form')
        ->call('open')
        ->set('title', 'Apprentice Painter')
        ->set('description', 'Entry level painting position')
        ->set('location', 'Dallas, TX')
        ->set('pay_range_min', '15')
        ->set('pay_range_max', '20')
        ->set('employment_type', 'full_time')
        ->set('positions_available', 2)
        ->call('save')
        ->assertHasNoErrors();

    expect(JobListing::where('title', 'Apprentice Painter')->exists())->toBeTrue();
});

it('can edit an existing listing via modal', function () {
    $listing = JobListing::factory()->create(['title' => 'Old Title']);

    Livewire::test('hiring.listing-form')
        ->call('open', $listing->id)
        ->assertSet('title', 'Old Title')
        ->set('title', 'Updated Title')
        ->call('save')
        ->assertHasNoErrors();

    expect($listing->fresh()->title)->toBe('Updated Title');
});

it('validates required fields on listing form', function () {
    Livewire::test('hiring.listing-form')
        ->call('open')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title']);
});

// ── Applicant Pipeline ────────────────────────────────────────────

it('renders the applicant pipeline', function () {
    $this->get(route('hiring.pipeline'))
        ->assertSuccessful()
        ->assertSee('Applicant Pipeline');
});

it('shows applicants in the correct pipeline columns', function () {
    $listing = JobListing::factory()->create();
    Applicant::factory()->for($listing)->create(['first_name' => 'John', 'last_name' => 'Doe', 'stage' => 'applied']);
    Applicant::factory()->for($listing)->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'stage' => 'screening']);

    Livewire::test('hiring.applicant-pipeline')
        ->assertSee('John Doe')
        ->assertSee('Jane Smith')
        ->assertSuccessful();
});

it('can advance an applicant stage from pipeline', function () {
    $applicant = Applicant::factory()->create(['stage' => 'applied']);

    Livewire::test('hiring.applicant-pipeline')
        ->call('advanceStage', $applicant->id);

    expect($applicant->fresh()->stage)->toBe('screening');
});

it('can filter pipeline by job listing', function () {
    $listing1 = JobListing::factory()->create(['title' => 'Painter A']);
    $listing2 = JobListing::factory()->create(['title' => 'Painter B']);
    Applicant::factory()->for($listing1)->create(['first_name' => 'Alice', 'last_name' => 'One']);
    Applicant::factory()->for($listing2)->create(['first_name' => 'Bob', 'last_name' => 'Two']);

    Livewire::test('hiring.applicant-pipeline')
        ->set('listingFilter', $listing1->id)
        ->assertSee('Alice One')
        ->assertDontSee('Bob Two');
});

// ── Pipeline Drag-and-Drop (moveToStage) ────────────────────────────

it('can move an applicant to any stage via moveToStage', function () {
    $applicant = Applicant::factory()->create(['stage' => 'applied']);

    Livewire::test('hiring.applicant-pipeline')
        ->call('moveToStage', $applicant->id, 'in_person');

    expect($applicant->fresh()->stage)->toBe('in_person');
});

it('sets hired_at when moving to hired stage', function () {
    $applicant = Applicant::factory()->create(['stage' => 'screening']);

    expect($applicant->hired_at)->toBeNull();

    Livewire::test('hiring.applicant-pipeline')
        ->call('moveToStage', $applicant->id, 'hired');

    $applicant->refresh();
    expect($applicant->stage)->toBe('hired');
    expect($applicant->hired_at)->not->toBeNull();
});

it('can move an applicant to rejected stage', function () {
    $applicant = Applicant::factory()->create(['stage' => 'offer']);

    Livewire::test('hiring.applicant-pipeline')
        ->call('moveToStage', $applicant->id, 'rejected');

    expect($applicant->fresh()->stage)->toBe('rejected');
});

it('ignores invalid stages in moveToStage', function () {
    $applicant = Applicant::factory()->create(['stage' => 'applied']);

    Livewire::test('hiring.applicant-pipeline')
        ->call('moveToStage', $applicant->id, 'nonexistent_stage');

    expect($applicant->fresh()->stage)->toBe('applied');
});

it('denies moveToStage without hiring.manage permission', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('hiring.view');

    $applicant = Applicant::factory()->create(['stage' => 'applied']);

    $this->actingAs($viewer);

    Livewire::test('hiring.applicant-pipeline')
        ->call('moveToStage', $applicant->id, 'screening')
        ->assertForbidden();
});

it('can move applicant backward in the pipeline', function () {
    $applicant = Applicant::factory()->create(['stage' => 'background_check']);

    Livewire::test('hiring.applicant-pipeline')
        ->call('moveToStage', $applicant->id, 'screening');

    expect($applicant->fresh()->stage)->toBe('screening');
});

// ── Applicant Detail Modal ────────────────────────────────────────

it('can open applicant detail modal', function () {
    $applicant = Applicant::factory()->create([
        'first_name' => 'Test',
        'last_name' => 'Applicant',
        'email' => 'test@example.com',
    ]);

    Livewire::test('hiring.applicant-detail')
        ->call('open', $applicant->id)
        ->assertSet('show', true)
        ->assertSee('Test Applicant')
        ->assertSee('test@example.com');
});

it('can set applicant rating', function () {
    $applicant = Applicant::factory()->create();

    Livewire::test('hiring.applicant-detail')
        ->call('open', $applicant->id)
        ->call('setRating', 4);

    expect($applicant->fresh()->rating)->toBe(4);
});

it('can reject an applicant with reason', function () {
    $applicant = Applicant::factory()->create(['stage' => 'screening']);

    Livewire::test('hiring.applicant-detail')
        ->call('open', $applicant->id)
        ->set('rejectionReason', 'Not qualified')
        ->call('reject');

    expect($applicant->fresh()->stage)->toBe('rejected');
    expect($applicant->fresh()->rejection_reason)->toBe('Not qualified');
});

it('can make an offer to an applicant', function () {
    $applicant = Applicant::factory()->create(['stage' => 'in_person']);

    Livewire::test('hiring.applicant-detail')
        ->call('open', $applicant->id)
        ->set('offerAmount', '25.00')
        ->set('offerType', 'hourly')
        ->call('makeOffer');

    $applicant->refresh();
    expect($applicant->stage)->toBe('offer');
    expect((float) $applicant->offer_amount)->toBe(25.00);
});

it('can schedule an interview', function () {
    $applicant = Applicant::factory()->create(['stage' => 'screening']);

    Livewire::test('hiring.applicant-detail')
        ->call('open', $applicant->id)
        ->set('interviewDate', '2026-03-01T10:00')
        ->call('scheduleInterview');

    expect($applicant->fresh()->interview_date)->not->toBeNull();
});

it('can add a note to an applicant', function () {
    $applicant = Applicant::factory()->create(['notes' => null]);

    Livewire::test('hiring.applicant-detail')
        ->call('open', $applicant->id)
        ->set('newNote', 'Great candidate')
        ->call('addNote');

    expect($applicant->fresh()->notes)->toContain('Great candidate');
});

// ── Hire Modal ────────────────────────────────────────────────────

it('can open the hire modal', function () {
    $applicant = Applicant::factory()->create([
        'first_name' => 'Hire',
        'last_name' => 'Me',
        'offer_amount' => '22.00',
        'offer_type' => 'hourly',
    ]);

    Livewire::test('hiring.hire-modal')
        ->call('open', $applicant->id)
        ->assertSet('show', true)
        ->assertSet('pay_rate', '22.00')
        ->assertSet('pay_type', 'hourly')
        ->assertSee('Hire Me');
});

it('can hire an applicant and create employee', function () {
    $listing = JobListing::factory()->create(['positions_filled' => 0]);
    $applicant = Applicant::factory()->for($listing)->create([
        'first_name' => 'New',
        'last_name' => 'Hire',
        'email' => 'newhire@example.com',
        'stage' => 'offer',
        'offer_amount' => '20.00',
        'offer_type' => 'hourly',
    ]);

    Livewire::test('hiring.hire-modal')
        ->call('open', $applicant->id)
        ->set('job_title', 'Painter')
        ->set('department', 'Operations')
        ->set('pay_rate', '20.00')
        ->set('pay_type', 'hourly')
        ->set('password', 'Password123!')
        ->call('hire')
        ->assertHasNoErrors();

    $applicant->refresh();
    expect($applicant->stage)->toBe('hired');
    expect($applicant->hired_at)->not->toBeNull();
    expect($applicant->converted_user_id)->not->toBeNull();

    // Verify user account was created
    expect(User::where('email', 'newhire@example.com')->exists())->toBeTrue();

    // Verify positions_filled incremented
    expect($listing->fresh()->positions_filled)->toBe(1);
});

it('prevents hiring if email already exists', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $applicant = Applicant::factory()->create([
        'email' => 'existing@example.com',
        'stage' => 'offer',
    ]);

    Livewire::test('hiring.hire-modal')
        ->call('open', $applicant->id)
        ->set('job_title', 'Painter')
        ->set('pay_rate', '20.00')
        ->set('password', 'Password123!')
        ->call('hire')
        ->assertHasErrors(['password']);

    // Applicant should still be in offer stage
    expect($applicant->fresh()->stage)->toBe('offer');
});
