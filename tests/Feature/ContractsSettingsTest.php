<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

describe('Contracts Settings Page Access', function () {

    it('internal user with full access can view the page', function () {
        Livewire::test('pages::settings.contracts')
            ->assertSuccessful()
            ->assertSee('Contracts');
    });

    it('loads existing legal documents from company settings', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'terms_and_conditions' => 'Test terms content',
            'privacy_policy' => 'Test privacy content',
            'referral_terms' => 'Test referral content',
        ]);

        Livewire::test('pages::settings.contracts')
            ->assertSuccessful()
            ->assertSet('terms_and_conditions', 'Test terms content')
            ->assertSet('privacy_policy', 'Test privacy content')
            ->assertSet('referral_terms', 'Test referral content');
    });

    it('customer user cannot access the page', function () {
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);

        Livewire::test('pages::settings.contracts')
            ->assertForbidden();
    });

    it('agent user cannot access the page', function () {
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent);

        Livewire::test('pages::settings.contracts')
            ->assertForbidden();
    });

    it('crew user cannot access the page', function () {
        $crew = User::factory()->crew()->create();
        $this->actingAs($crew);

        Livewire::test('pages::settings.contracts')
            ->assertForbidden();
    });

    it('settings route is accessible for internal user', function () {
        $this->get(route('contracts.edit'))
            ->assertSuccessful();
    });
});

describe('Save Legal Documents', function () {

    it('can save terms and conditions', function () {
        Livewire::test('pages::settings.contracts')
            ->set('terms_and_conditions', 'Updated terms and conditions text')
            ->call('saveTerms')
            ->assertHasNoErrors();

        expect(CompanySetting::getInstance()->fresh()->terms_and_conditions)
            ->toBe('Updated terms and conditions text');
    });

    it('can save privacy policy', function () {
        Livewire::test('pages::settings.contracts')
            ->set('privacy_policy', 'Updated privacy policy text')
            ->call('savePrivacy')
            ->assertHasNoErrors();

        expect(CompanySetting::getInstance()->fresh()->privacy_policy)
            ->toBe('Updated privacy policy text');
    });

    it('can save referral terms', function () {
        Livewire::test('pages::settings.contracts')
            ->set('referral_terms', 'Updated referral terms text')
            ->call('saveReferral')
            ->assertHasNoErrors();

        expect(CompanySetting::getInstance()->fresh()->referral_terms)
            ->toBe('Updated referral terms text');
    });

    it('can save terms and conditions with Spanish version', function () {
        Livewire::test('pages::settings.contracts')
            ->set('terms_and_conditions', 'English terms')
            ->set('terms_and_conditions_es', 'Terminos en espanol')
            ->call('saveTerms')
            ->assertHasNoErrors();

        $settings = CompanySetting::getInstance()->fresh();
        expect($settings->terms_and_conditions)->toBe('English terms');
        expect($settings->terms_and_conditions_es)->toBe('Terminos en espanol');
    });

    it('can save privacy policy with Spanish version', function () {
        Livewire::test('pages::settings.contracts')
            ->set('privacy_policy', 'English privacy')
            ->set('privacy_policy_es', 'Politica de privacidad')
            ->call('savePrivacy')
            ->assertHasNoErrors();

        $settings = CompanySetting::getInstance()->fresh();
        expect($settings->privacy_policy)->toBe('English privacy');
        expect($settings->privacy_policy_es)->toBe('Politica de privacidad');
    });

    it('can save crew contracts with Spanish versions', function () {
        Livewire::test('pages::settings.contracts')
            ->set('crew_contractor_agreement', 'English agreement')
            ->set('crew_contractor_agreement_es', 'Acuerdo en espanol')
            ->call('saveCrewAgreement')
            ->assertHasNoErrors();

        $settings = CompanySetting::getInstance()->fresh();
        expect($settings->crew_contractor_agreement)->toBe('English agreement');
        expect($settings->crew_contractor_agreement_es)->toBe('Acuerdo en espanol');
    });

    it('loads Spanish content on mount', function () {
        CompanySetting::getInstance()->update([
            'terms_and_conditions_es' => 'Spanish terms',
            'privacy_policy_es' => 'Spanish privacy',
        ]);

        Livewire::test('pages::settings.contracts')
            ->assertSet('terms_and_conditions_es', 'Spanish terms')
            ->assertSet('privacy_policy_es', 'Spanish privacy');
    });

    it('saves each document independently', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'terms_and_conditions' => 'Original terms',
            'privacy_policy' => 'Original privacy',
            'referral_terms' => 'Original referral',
        ]);

        Livewire::test('pages::settings.contracts')
            ->set('privacy_policy', 'New privacy text')
            ->call('savePrivacy');

        $settings = CompanySetting::getInstance()->fresh();
        expect($settings->terms_and_conditions)->toBe('Original terms');
        expect($settings->privacy_policy)->toBe('New privacy text');
        expect($settings->referral_terms)->toBe('Original referral');
    });
});

describe('Localized Contract Helper', function () {

    it('returns English content when locale is en', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'terms_and_conditions' => 'English terms',
            'terms_and_conditions_es' => 'Spanish terms',
        ]);

        app()->setLocale('en');

        expect($settings->getLocalizedContract('terms_and_conditions'))->toBe('English terms');
    });

    it('returns Spanish content when locale is es and translation exists', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'terms_and_conditions' => 'English terms',
            'terms_and_conditions_es' => 'Spanish terms',
        ]);

        app()->setLocale('es');

        expect($settings->getLocalizedContract('terms_and_conditions'))->toBe('Spanish terms');
    });

    it('falls back to English when locale is es but no translation exists', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'privacy_policy' => 'English privacy',
            'privacy_policy_es' => null,
        ]);

        app()->setLocale('es');

        expect($settings->getLocalizedContract('privacy_policy'))->toBe('English privacy');
    });

    it('falls back to English when Spanish translation is empty string', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'referral_terms' => 'English referral',
            'referral_terms_es' => '',
        ]);

        app()->setLocale('es');

        expect($settings->getLocalizedContract('referral_terms'))->toBe('English referral');
    });

    it('works for crew contract fields', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'crew_contractor_agreement' => 'English agreement',
            'crew_contractor_agreement_es' => 'Acuerdo del contratista',
        ]);

        app()->setLocale('es');

        expect($settings->getLocalizedContract('crew_contractor_agreement'))->toBe('Acuerdo del contratista');
    });
});
