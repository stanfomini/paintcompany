<?php

use App\Models\CompanySetting;
use App\Models\CrewSignedContract;
use App\Models\CrewW9Form;
use App\Models\User;
use App\Notifications\CrewCompliancePackNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->internal = User::factory()->internal()->withFullAccess()->create();
    $this->crew = User::factory()->crew()->create();
    $this->crew->crewProfile()->update([
        'insurance_company' => 'Test Insurance Co',
        'policy_number' => 'POL-123',
        'insurance_start_date' => now()->subMonths(6),
        'insurance_expiration_date' => now()->addMonths(6),
        'workers_comp_carrier' => 'Workers Comp Co',
        'workers_comp_policy_number' => 'WC-456',
        'workers_comp_start_date' => now()->subMonths(6),
        'workers_comp_expiration_date' => now()->addMonths(6),
    ]);

    CompanySetting::getInstance()->update([
        'company_name' => 'Test Painting Co',
    ]);
});

describe('W-9 PDF', function () {
    it('requires authentication', function () {
        $this->get(route('crews.documents.w9', $this->crew))
            ->assertRedirect();
    });

    it('requires people.view permission', function () {
        $unprivileged = User::factory()->internal()->create();

        $this->actingAs($unprivileged)
            ->get(route('crews.documents.w9', $this->crew))
            ->assertForbidden();
    });

    it('returns 404 when crew has no W-9', function () {
        $this->actingAs($this->internal)
            ->get(route('crews.documents.w9', $this->crew))
            ->assertNotFound();
    });

    it('returns 404 for non-crew users', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($this->internal)
            ->get(route('crews.documents.w9', $customer))
            ->assertNotFound();
    });

    it('generates W-9 PDF when data exists', function () {
        CrewW9Form::factory()->for($this->crew, 'user')->create();

        $response = $this->actingAs($this->internal)
            ->get(route('crews.documents.w9', $this->crew));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    });
});

describe('Contract PDF', function () {
    it('requires authentication', function () {
        $contract = CrewSignedContract::factory()->for($this->crew, 'user')->create();

        $this->get(route('crews.documents.contract', $contract))
            ->assertRedirect();
    });

    it('requires people.view permission', function () {
        $unprivileged = User::factory()->internal()->create();
        $contract = CrewSignedContract::factory()->for($this->crew, 'user')->create();

        $this->actingAs($unprivileged)
            ->get(route('crews.documents.contract', $contract))
            ->assertForbidden();
    });

    it('generates contract PDF', function () {
        $contract = CrewSignedContract::factory()
            ->for($this->crew, 'user')
            ->contractorAgreement()
            ->create();

        $response = $this->actingAs($this->internal)
            ->get(route('crews.documents.contract', $contract));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    });
});

describe('Compliance Pack PDF', function () {
    it('requires authentication', function () {
        $this->get(route('crews.documents.compliance-pack', $this->crew))
            ->assertRedirect();
    });

    it('requires people.view permission', function () {
        $unprivileged = User::factory()->internal()->create();

        $this->actingAs($unprivileged)
            ->get(route('crews.documents.compliance-pack', $this->crew))
            ->assertForbidden();
    });

    it('returns 404 for non-crew users', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($this->internal)
            ->get(route('crews.documents.compliance-pack', $customer))
            ->assertNotFound();
    });

    it('generates compliance pack PDF', function () {
        CrewSignedContract::factory()->for($this->crew, 'user')->coloradoCompliance()->create();
        CrewSignedContract::factory()->for($this->crew, 'user')->contractorAgreement()->create();
        CrewSignedContract::factory()->for($this->crew, 'user')->backgroundCheck()->create();
        CrewW9Form::factory()->for($this->crew, 'user')->create();

        $response = $this->actingAs($this->internal)
            ->get(route('crews.documents.compliance-pack', $this->crew));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    });

    it('generates compliance pack even with partial data', function () {
        $response = $this->actingAs($this->internal)
            ->get(route('crews.documents.compliance-pack', $this->crew));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    });
});

describe('Email Compliance Pack', function () {
    it('requires people.view permission to view crew page', function () {
        $unprivileged = User::factory()->internal()->create();

        $this->actingAs($unprivileged)
            ->get(route('crews.show', $this->crew))
            ->assertForbidden();
    });

    it('validates email address', function () {
        Livewire::actingAs($this->internal)
            ->test('crews.show', ['user' => $this->crew])
            ->set('complianceEmailRecipient', 'not-an-email')
            ->call('emailCompliancePack')
            ->assertHasErrors(['complianceEmailRecipient']);
    });

    it('sends compliance pack notification', function () {
        Notification::fake();

        CrewW9Form::factory()->for($this->crew, 'user')->create();

        Livewire::actingAs($this->internal)
            ->test('crews.show', ['user' => $this->crew])
            ->set('complianceEmailRecipient', 'contractor@example.com')
            ->call('emailCompliancePack')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(CrewCompliancePackNotification::class);
    });
});
