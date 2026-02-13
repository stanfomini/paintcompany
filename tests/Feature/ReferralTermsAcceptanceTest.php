<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

describe('Referral Terms Acceptance Gate', function () {

    it('shows terms acceptance gate when user has not accepted', function () {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'referral_program_enabled' => true,
            'referral_terms' => 'These are the referral program terms.',
        ]);

        Livewire::test('shared.referral-dashboard')
            ->assertSee('Referral Program Terms')
            ->assertSee('These are the referral program terms.')
            ->assertSee('Accept')
            ->assertDontSee('Your Referral Link');
    });

    it('shows referral dashboard when user has accepted terms', function () {
        $user = User::factory()->customer()->create([
            'referral_terms_accepted_at' => now(),
            'referral_terms_ip_address' => '127.0.0.1',
            'referral_terms_initials' => 'JD',
        ]);
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'referral_program_enabled' => true,
            'referral_reward_amount' => 50,
        ]);

        Livewire::test('shared.referral-dashboard')
            ->assertSee('Your Referral Link')
            ->assertDontSee('Referral Program Terms');
    });

    it('can accept referral terms with initials', function () {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'referral_program_enabled' => true,
            'referral_terms' => 'Terms content here.',
        ]);

        Livewire::test('shared.referral-dashboard')
            ->set('acceptCheckbox', true)
            ->set('acceptInitials', 'JD')
            ->call('acceptReferralTerms')
            ->assertHasNoErrors();

        $user->refresh();
        expect($user->referral_terms_accepted_at)->not->toBeNull();
        expect($user->referral_terms_initials)->toBe('JD');
        expect($user->referral_terms_ip_address)->not->toBeNull();
    });

    it('requires initials to accept terms', function () {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update(['referral_program_enabled' => true]);

        Livewire::test('shared.referral-dashboard')
            ->set('acceptCheckbox', true)
            ->set('acceptInitials', '')
            ->call('acceptReferralTerms')
            ->assertHasErrors(['acceptInitials']);
    });

    it('requires checkbox to accept terms', function () {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update(['referral_program_enabled' => true]);

        Livewire::test('shared.referral-dashboard')
            ->set('acceptCheckbox', false)
            ->set('acceptInitials', 'JD')
            ->call('acceptReferralTerms')
            ->assertHasErrors(['acceptCheckbox']);
    });

    it('requires initials to be at least 2 characters', function () {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update(['referral_program_enabled' => true]);

        Livewire::test('shared.referral-dashboard')
            ->set('acceptCheckbox', true)
            ->set('acceptInitials', 'J')
            ->call('acceptReferralTerms')
            ->assertHasErrors(['acceptInitials']);
    });

    it('shows disabled message when referral program is not enabled', function () {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $settings = CompanySetting::getInstance();
        $settings->update(['referral_program_enabled' => false]);

        Livewire::test('shared.referral-dashboard')
            ->assertSee('Referral Program Unavailable');
    });
});

describe('User Model - Referral Terms', function () {

    it('hasAcceptedReferralTerms returns false when not accepted', function () {
        $user = User::factory()->customer()->create();
        expect($user->hasAcceptedReferralTerms())->toBeFalse();
    });

    it('hasAcceptedReferralTerms returns true when accepted', function () {
        $user = User::factory()->customer()->create([
            'referral_terms_accepted_at' => now(),
        ]);
        expect($user->hasAcceptedReferralTerms())->toBeTrue();
    });
});
