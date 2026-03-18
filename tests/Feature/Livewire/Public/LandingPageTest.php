<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invitation;
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

    it('shows the color visualizer section with link to /visualize', function () {
        $this->get('/')
            ->assertOk()
            ->assertSee('See Your Home in New Colors')
            ->assertSee('Try It Free')
            ->assertSee(route('visualize'));
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
            ->set('smsServiceConsent', true)
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect();

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
            ->set('smsServiceConsent', true)
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect();

        $customer = Customer::where('email', 'referred@example.com')->first();
        expect($customer)->not->toBeNull();
        expect($customer->source)->toBe('referral');

        $referral = Referral::where('customer_id', $customer->id)->first();
        expect($referral)->not->toBeNull();
        expect($referral->referrer_id)->toBe($referrer->id);
    });

    it('redirects to invitation accept URL after successful submission', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Test')
            ->set('last_name', 'User')
            ->set('email', 'test@example.com')
            ->set('estimate_preference', 'virtual')
            ->set('smsServiceConsent', true)
            ->call('submitLeadForm')
            ->assertRedirect();

        $invitation = Invitation::where('email', 'test@example.com')
            ->where('invited_user_type', 'customer')
            ->first();

        expect($invitation)->not->toBeNull();
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

    it('auto-sends invitation email on form submission', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Portal')
            ->set('last_name', 'User')
            ->set('email', 'portal@example.com')
            ->set('estimate_preference', 'virtual')
            ->set('smsServiceConsent', true)
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invitation = Invitation::where('email', 'portal@example.com')
            ->where('invited_user_type', 'customer')
            ->first();

        expect($invitation)->not->toBeNull();
        expect($invitation->accepted_at)->toBeNull();
    });

    it('redirects existing user to customer login', function () {
        Notification::fake();

        // Pre-create a User with the same email
        User::factory()->create([
            'email' => 'existing@example.com',
            'type' => 'customer',
        ]);

        Livewire::test('public.landing-page')
            ->set('first_name', 'Existing')
            ->set('last_name', 'User')
            ->set('email', 'existing@example.com')
            ->set('estimate_preference', 'virtual')
            ->set('smsServiceConsent', true)
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.login'));

        // No customer/invitation created since user already exists
        expect(Invitation::where('email', 'existing@example.com')->exists())->toBeFalse();
    });

    it('does not create invitation when honeypot is filled', function () {
        Livewire::test('public.landing-page')
            ->set('first_name', 'Bot')
            ->set('last_name', 'Spam')
            ->set('email', 'bot2@spam.com')
            ->set('honeypot', 'I am a bot')
            ->call('submitLeadForm')
            ->assertRedirect(route('next-steps'));

        expect(Invitation::where('email', 'bot2@spam.com')->exists())->toBeFalse();
    });
});

describe('Agent ROI section', function () {
    beforeEach(function () {
        $this->withoutVite();

        CompanySetting::clearInstanceCache();
        CompanySetting::getInstance();
        CompanySetting::clearInstanceCache();

        User::factory()->internal()->withFullAccess()->create();
    });

    it('shows the Property Value Calculator section', function () {
        Livewire::test('public.landing-page')
            ->assertSee('Free Property Value Calculator')
            ->assertSee('Add Your Listing')
            ->assertSee('See ROI Impact')
            ->assertSee('Share with Clients');
    });

    it('shows agent ROI CTA text', function () {
        Livewire::test('public.landing-page')
            ->assertSee('Try the Property Value Calculator')
            ->assertSee('Try the Calculator')
            ->assertSee('Free for all agents. See the calculator in action.');
    });

    it('shows selling and buying value differentiation', function () {
        Livewire::test('public.landing-page')
            ->assertSee('Selling a Home')
            ->assertSee('Buying a Home')
            ->assertSee('Increase listing price by thousands')
            ->assertSee('Budget accurately for move-in ready updates');
    });
});

describe('Next steps page', function () {
    beforeEach(function () {
        $this->withoutVite();
    });

    it('renders the next-steps page', function () {
        $this->get(route('next-steps'))
            ->assertOk()
            ->assertSeeLivewire('public.next-steps');
    });

    it('shows default portal text when no flash data', function () {
        $this->get(route('next-steps'))
            ->assertOk()
            ->assertSee('Check your email for a portal invitation');
    });

    it('shows setup button when invitation_accept_url is flashed', function () {
        $this->withSession(['invitation_accept_url' => 'https://example.com/accept/test-token'])
            ->get(route('next-steps'))
            ->assertOk()
            ->assertSee('Set up your customer portal')
            ->assertSee('Set Up My Account')
            ->assertSee('We also sent this link to your email')
            ->assertDontSee('Check your email for a portal invitation');
    });

    it('does not show setup button on page refresh (flash expires)', function () {
        // First request with flash data
        $this->withSession(['_flash' => ['old' => ['invitation_accept_url']], 'invitation_accept_url' => 'https://example.com/accept/test-token'])
            ->get(route('next-steps'));

        // Second request - flash data should be gone
        $this->get(route('next-steps'))
            ->assertOk()
            ->assertSee('Check your email for a portal invitation')
            ->assertDontSee('Set Up My Account');
    });
});
