<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Landing page', function () {
    beforeEach(function () {
        // Prevent Vite manifest errors in tests
        $this->withoutVite();

        CompanySetting::clearInstanceCache();
        $settings = CompanySetting::getInstance();
        $settings->update([
            'referral_reward_amount' => 50.00,
            'referral_program_enabled' => true,
        ]);
        CompanySetting::clearInstanceCache();

        // Ensure an internal user exists for created_by attribution
        User::factory()->internal()->withFullAccess()->create();
    });

    it('renders the landing page at /', function () {
        $this->get('/')
            ->assertOk()
            ->assertSeeLivewire('public.landing-page');
    });

    it('captures referral code from URL query param', function () {
        $user = User::factory()->internal()->create();
        $user->getOrCreateReferralCode();

        Livewire::withQueryParams(['ref' => $user->referral_code])
            ->test('public.landing-page')
            ->assertSet('referralCode', $user->referral_code);
    });

    it('validates required fields on form submission', function () {
        Livewire::test('public.landing-page')
            ->set('first_name', '')
            ->set('last_name', '')
            ->set('email', '')
            ->call('submitLeadForm')
            ->assertHasErrors(['first_name', 'last_name', 'email']);
    });

    it('validates email format', function () {
        Livewire::test('public.landing-page')
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'not-an-email')
            ->call('submitLeadForm')
            ->assertHasErrors(['email']);
    });

    it('creates customer and deal on form submission', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'jane@example.com')
            ->set('phone', '303-555-1234')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect(route('next-steps'));

        expect(Customer::where('email', 'jane@example.com')->exists())->toBeTrue();
        $customer = Customer::where('email', 'jane@example.com')->first();
        expect($customer->first_name)->toBe('Jane');
        expect($customer->last_name)->toBe('Doe');
        expect(Deal::where('customer_id', $customer->id)->exists())->toBeTrue();
    });

    it('creates referral when submitted with valid referral code', function () {
        Notification::fake();

        $referrer = User::factory()->internal()->create();
        $referrer->getOrCreateReferralCode();

        Livewire::withQueryParams(['ref' => $referrer->referral_code])
            ->test('public.landing-page')
            ->set('first_name', 'Referred')
            ->set('last_name', 'Customer')
            ->set('email', 'referred@example.com')
            ->set('estimate_preference', 'in_person')
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect(route('next-steps'));

        $customer = Customer::where('email', 'referred@example.com')->first();
        expect($customer)->not->toBeNull();
        expect($customer->source)->toBe('referral');

        $referral = Referral::where('customer_id', $customer->id)->first();
        expect($referral)->not->toBeNull();
        expect($referral->referrer_id)->toBe($referrer->id);
    });

    it('redirects to next-steps after successful submission', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Test')
            ->set('last_name', 'User')
            ->set('email', 'test@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm')
            ->assertRedirect(route('next-steps'));
    });

    it('handles honeypot by redirecting without creating records', function () {
        Livewire::test('public.landing-page')
            ->set('first_name', 'Bot')
            ->set('last_name', 'Spam')
            ->set('email', 'bot@spam.com')
            ->set('honeypot', 'I am a bot')
            ->call('submitLeadForm')
            ->assertRedirect(route('next-steps'));

        expect(Customer::where('email', 'bot@spam.com')->exists())->toBeFalse();
    });
});
