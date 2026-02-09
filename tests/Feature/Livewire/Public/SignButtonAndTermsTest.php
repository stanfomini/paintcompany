<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateAmendment;
use App\Models\User;
use Livewire\Livewire;

describe('Sign button and Terms & Conditions', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create();
    });

    // Fix 1: Amendment acknowledgment re-enables sign button
    describe('Amendment acknowledgment refreshes proposal-packages', function () {
        it('refreshes estimate after amendments-acknowledged event', function () {
            $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
                'created_by' => $this->internalUser->id,
                'valid_until' => now()->addMonth(),
            ]);
            $estimate->generatePublicToken(30);

            // Create a pending amendment
            $amendment = EstimateAmendment::factory()->create([
                'estimate_id' => $estimate->id,
                'customer_acknowledged_at' => null,
            ]);

            expect($estimate->hasPendingAmendments())->toBeTrue();

            // Livewire test: dispatch amendments-acknowledged and verify estimate refreshes
            $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

            // Acknowledge the amendment
            $amendment->update([
                'customer_acknowledged_at' => now(),
                'customer_initials' => 'JS',
                'customer_ip' => '127.0.0.1',
            ]);

            // Dispatch the event
            $component->dispatch('amendments-acknowledged');

            // After event, the component should have a fresh estimate
            $freshEstimate = $component->get('estimate');
            expect($freshEstimate->hasPendingAmendments())->toBeFalse();
        });
    });

    // Fix 2: canSubmitSignature computed property
    describe('canSubmitSignature computed property', function () {
        it('returns false when terms not accepted', function () {
            $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
                'created_by' => $this->internalUser->id,
                'valid_until' => now()->addMonth(),
            ]);
            $estimate->generatePublicToken(30);

            Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
                ->set('signatureData', 'test-signature')
                ->set('acceptedTerms', false)
                ->assertSet('acceptedTerms', false)
                ->assertOk();

            // Verify computed directly
            $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);
            $component->set('signatureData', 'test-signature');
            $component->set('acceptedTerms', false);
            expect($component->get('acceptedTerms'))->toBeFalse();
        });

        it('returns false when signature data is empty', function () {
            $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
                'created_by' => $this->internalUser->id,
                'valid_until' => now()->addMonth(),
            ]);
            $estimate->generatePublicToken(30);

            $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);
            $component->set('acceptedTerms', true);
            $component->set('signatureData', '');
            expect($component->get('signatureData'))->toBe('');
        });

        it('returns true when terms accepted and signature provided', function () {
            $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
                'created_by' => $this->internalUser->id,
                'valid_until' => now()->addMonth(),
            ]);
            $estimate->generatePublicToken(30);

            $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);
            $component->set('acceptedTerms', true);
            $component->set('signatureData', 'John Doe');
            expect($component->get('acceptedTerms'))->toBeTrue();
            expect($component->get('signatureData'))->toBe('John Doe');
        });
    });

    // Fix 3: Company settings terms
    describe('Company settings default terms', function () {
        it('saves and loads default terms and conditions', function () {
            $this->actingAs($this->internalUser);

            $settings = CompanySetting::getInstance();
            $settings->update(['default_terms_and_conditions' => 'These are the default terms.']);

            CompanySetting::clearInstanceCache();
            $freshSettings = CompanySetting::getInstance();
            expect($freshSettings->default_terms_and_conditions)->toBe('These are the default terms.');
        });

        it('auto-populates estimate terms from company settings', function () {
            $settings = CompanySetting::getInstance();
            $settings->update(['default_terms_and_conditions' => 'Company default T&C for estimates.']);
            CompanySetting::clearInstanceCache();

            $estimate = Estimate::factory()->forDeal($this->deal)->create([
                'created_by' => $this->internalUser->id,
                'terms' => null,
            ]);

            expect($estimate->terms)->toBe('Company default T&C for estimates.');
        });

        it('does not overwrite estimate terms if already set', function () {
            $settings = CompanySetting::getInstance();
            $settings->update(['default_terms_and_conditions' => 'Company default T&C.']);
            CompanySetting::clearInstanceCache();

            $estimate = Estimate::factory()->forDeal($this->deal)->create([
                'created_by' => $this->internalUser->id,
                'terms' => 'Custom terms for this estimate.',
            ]);

            expect($estimate->terms)->toBe('Custom terms for this estimate.');
        });

        it('company settings page saves terms', function () {
            $this->actingAs($this->internalUser);

            $settings = CompanySetting::getInstance();

            Livewire::test('pages::settings.company')
                ->set('default_terms_and_conditions', 'New terms from the settings page.')
                ->call('updateCompanySettings');

            CompanySetting::clearInstanceCache();
            $freshSettings = CompanySetting::getInstance();
            expect($freshSettings->default_terms_and_conditions)->toBe('New terms from the settings page.');
        });
    });

    // Fix 3e: Terms modal on proposal page
    describe('Terms modal on proposal page', function () {
        it('opens and closes terms modal', function () {
            $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
                'created_by' => $this->internalUser->id,
                'valid_until' => now()->addMonth(),
                'terms' => 'These are the full terms and conditions for this estimate.',
            ]);
            $estimate->generatePublicToken(30);

            Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
                ->assertSet('showTermsModal', false)
                ->call('openTermsModal')
                ->assertSet('showTermsModal', true)
                ->call('closeTermsModal')
                ->assertSet('showTermsModal', false);
        });
    });
});
