<?php

use App\Models\CompanySetting;

describe('Public Terms Page', function () {

    it('renders the terms page', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['terms_and_conditions' => 'These are the terms and conditions.']);

        $this->get('/terms')
            ->assertSuccessful()
            ->assertSee('Terms')
            ->assertSee('These are the terms and conditions.');
    });

    it('shows fallback when terms are empty', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['terms_and_conditions' => null]);

        $this->get('/terms')
            ->assertSuccessful()
            ->assertSee('No Terms Available');
    });

    it('does not require authentication', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['terms_and_conditions' => 'Public terms content']);

        $this->get('/terms')
            ->assertSuccessful();
    });
});

describe('Public Privacy Page', function () {

    it('renders the privacy page', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['privacy_policy' => 'This is the privacy policy.']);

        $this->get('/privacy')
            ->assertSuccessful()
            ->assertSee('Privacy Policy')
            ->assertSee('This is the privacy policy.');
    });

    it('shows fallback when privacy policy is empty', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['privacy_policy' => null]);

        $this->get('/privacy')
            ->assertSuccessful()
            ->assertSee('No Privacy Policy Available');
    });
});

describe('Public Referral Terms Page', function () {

    it('renders the referral terms page', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['referral_terms' => 'These are the referral terms.']);

        $this->get('/referral-terms')
            ->assertSuccessful()
            ->assertSee('Referral Program Terms')
            ->assertSee('These are the referral terms.');
    });

    it('shows fallback when referral terms are empty', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['referral_terms' => null]);

        $this->get('/referral-terms')
            ->assertSuccessful()
            ->assertSee('No Referral Terms Available');
    });
});
