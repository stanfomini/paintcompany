<?php

use App\Models\AgentListing;
use App\Models\User;

describe('Listing Flyer Access', function () {
    it('allows authenticated agent to download their own listing flyer', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Test Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
        ]);

        $response = $this->actingAs($agent, 'agent')
            ->get(route('agent.listings.flyer', $listing));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename=open-house-flyer-123-test-street.pdf');
    });

    it('returns 403 when agent tries to download another agent listing flyer', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($otherAgent)->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.listings.flyer', $listing))
            ->assertForbidden();
    });

    it('redirects guests to agent login', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        $this->get(route('agent.listings.flyer', $listing))
            ->assertRedirect(route('agent.login'));
    });

    it('redirects internal users away from agent flyer route', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        $this->actingAs($internalUser, 'agent')
            ->get(route('agent.listings.flyer', $listing))
            ->assertRedirect();
    });
});

describe('Listing Flyer PDF Content', function () {
    it('contains the listing address', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '456 Unique Property Lane',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip' => '60601',
        ]);

        $response = $this->actingAs($agent, 'agent')
            ->get(route('agent.listings.flyer', $listing));

        $response->assertSuccessful();

        // The PDF content is binary, but we can check that the view data is being passed correctly
        // by testing the view directly
        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->toContain('456 Unique Property Lane');
        expect($view)->toContain('Chicago');
        expect($view)->toContain('IL');
        expect($view)->toContain('60601');
    });

    it('contains QR code SVG', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        // Test the view contains the QR code placeholder
        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg xmlns="http://www.w3.org/2000/svg" class="qr-test"></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->toContain('<svg xmlns="http://www.w3.org/2000/svg" class="qr-test"></svg>');
    });

    it('contains agent contact information', function () {
        $agent = User::factory()->agent()->create([
            'name' => 'Jane Smith',
            'email' => 'jane.smith@example.com',
        ]);
        $agent->agentProfile->update([
            'company_name' => 'Smith Realty',
            'phone' => '555-123-4567',
        ]);

        $listing = AgentListing::factory()->forAgent($agent)->create();

        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $agent->refresh(),
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->toContain('Jane Smith');
        expect($view)->toContain('jane.smith@example.com');
        expect($view)->toContain('Smith Realty');
        expect($view)->toContain('555-123-4567');
    });

    it('contains sign-up URL text', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        $signupUrl = url("/open-house/{$listing->id}");

        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => $signupUrl,
        ])->render();

        // The URL should appear without the protocol
        expect($view)->toContain("open-house/{$listing->id}");
    });

    it('displays open house date when set', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => '2025-03-15 14:00:00',
        ]);

        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->toContain('Saturday, March 15, 2025 at 2:00 PM');
    });

    it('hides open house date section when not set', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => null,
        ]);

        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->not->toContain('Date &amp; Time');
    });

    it('contains Apex Negotiations branding', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->toContain('Apex Negotiations');
        expect($view)->toContain('Property Tax Savings');
    });

    it('contains services offered text', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        $view = view('pdf.open-house-flyer', [
            'listing' => $listing,
            'agent' => $listing->agent,
            'qrCodeSvg' => '<svg></svg>',
            'signupUrl' => url("/open-house/{$listing->id}"),
        ])->render();

        expect($view)->toContain('Free Property Tax Savings Consultation');
        expect($view)->toContain('property tax appeals');
    });
});

describe('Listing Flyer PDF Generation', function () {
    it('generates PDF with correct filename based on address', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '789 Oak Avenue Unit 5B',
        ]);

        $response = $this->actingAs($agent, 'agent')
            ->get(route('agent.listings.flyer', $listing));

        $response->assertSuccessful();
        $response->assertHeader('content-disposition', 'attachment; filename=open-house-flyer-789-oak-avenue-unit-5b.pdf');
    });

    it('returns response with PDF content type', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        $response = $this->actingAs($agent, 'agent')
            ->get(route('agent.listings.flyer', $listing));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    });
});
