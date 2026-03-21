<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\FbLandingPageOffer;
use App\Models\FbLandingPageView;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Facebook offer landing page', function () {
    beforeEach(function () {
        $this->withoutVite();

        CompanySetting::clearInstanceCache();
        CompanySetting::getInstance();
        CompanySetting::clearInstanceCache();

        // Ensure an internal user exists for created_by attribution
        User::factory()->internal()->withFullAccess()->create();
    });

    it('renders the offer page for an active offer', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'test-offer']);

        $this->get('/offer/test-offer')
            ->assertOk()
            ->assertSeeLivewire('public.fb-offer');
    });

    it('returns 404 for non-existent slug', function () {
        $this->get('/offer/does-not-exist')
            ->assertNotFound();
    });

    it('returns 404 for inactive offer', function () {
        FbLandingPageOffer::factory()->inactive()->create(['slug' => 'inactive-offer']);

        $this->get('/offer/inactive-offer')
            ->assertNotFound();
    });

    it('displays the offer headline and subheadline', function () {
        $offer = FbLandingPageOffer::factory()->create([
            'slug' => 'headline-test',
            'headline' => 'Amazing Paint Deal',
            'subheadline' => 'Get a free estimate today',
        ]);

        $this->get('/offer/headline-test')
            ->assertOk()
            ->assertSee('Amazing Paint Deal')
            ->assertSee('Get a free estimate today');
    });

    it('displays offer bullets', function () {
        $offer = FbLandingPageOffer::factory()->create([
            'slug' => 'bullets-test',
            'offer_bullets' => ['Free color consult', 'Licensed painters', 'Cleanup included'],
        ]);

        $this->get('/offer/bullets-test')
            ->assertOk()
            ->assertSee('Free color consult')
            ->assertSee('Licensed painters')
            ->assertSee('Cleanup included');
    });

    it('displays the CTA text on the button', function () {
        $offer = FbLandingPageOffer::factory()->create([
            'slug' => 'cta-test',
            'cta_text' => 'Get My Free Quote',
        ]);

        $this->get('/offer/cta-test')
            ->assertOk()
            ->assertSee('Get My Free Quote');
    });

    it('records a page view on mount', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'view-test']);

        $this->get('/offer/view-test');

        expect(FbLandingPageView::where('fb_landing_page_offer_id', $offer->id)->count())->toBe(1);
    });

    it('captures UTM parameters on page view', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'utm-test']);

        $this->get('/offer/utm-test?utm_source=facebook&utm_medium=cpc&utm_campaign=spring&fbclid=abc123');

        $view = FbLandingPageView::where('fb_landing_page_offer_id', $offer->id)->first();

        expect($view->utm_source)->toBe('facebook')
            ->and($view->utm_medium)->toBe('cpc')
            ->and($view->utm_campaign)->toBe('spring')
            ->and($view->fbclid)->toBe('abc123');
    });

    it('validates required fields on form submission', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'validate-test']);

        Livewire::test('public.fb-offer', ['slug' => 'validate-test'])
            ->set('first_name', '')
            ->set('last_name', '')
            ->set('email', '')
            ->call('submitLeadForm')
            ->assertHasErrors(['first_name', 'last_name', 'email']);
    });

    it('validates email format', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'email-test']);

        Livewire::test('public.fb-offer', ['slug' => 'email-test'])
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'not-an-email')
            ->call('submitLeadForm')
            ->assertHasErrors(['email']);
    });

    it('creates customer and deal on form submission with facebook_ads source', function () {
        Notification::fake();

        $offer = FbLandingPageOffer::factory()->create(['slug' => 'submit-test']);

        Livewire::test('public.fb-offer', ['slug' => 'submit-test'])
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'jane-fb@example.com')
            ->set('phone', '303-555-1234')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm');

        $customer = Customer::where('email', 'jane-fb@example.com')->first();
        expect($customer)->not->toBeNull()
            ->and($customer->first_name)->toBe('Jane')
            ->and($customer->last_name)->toBe('Doe');

        $deal = Deal::where('customer_id', $customer->id)->first();
        expect($deal)->not->toBeNull()
            ->and($deal->lead_source)->toBe('facebook_ads')
            ->and($deal->stage)->toBe('lead');
    });

    it('marks the page view as converted after form submission', function () {
        Notification::fake();

        $offer = FbLandingPageOffer::factory()->create(['slug' => 'convert-test']);

        Livewire::test('public.fb-offer', ['slug' => 'convert-test'])
            ->set('first_name', 'Convert')
            ->set('last_name', 'Test')
            ->set('email', 'convert@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm');

        $view = FbLandingPageView::where('fb_landing_page_offer_id', $offer->id)->first();
        expect($view->converted)->toBeTrue()
            ->and($view->customer_id)->not->toBeNull()
            ->and($view->deal_id)->not->toBeNull();
    });

    it('redirects existing users to customer login', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'existing-test']);
        $user = User::factory()->create(['email' => 'existing@example.com']);

        Livewire::test('public.fb-offer', ['slug' => 'existing-test'])
            ->set('first_name', 'Existing')
            ->set('last_name', 'User')
            ->set('email', 'existing@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm')
            ->assertRedirect(route('customer.login'));
    });

    it('silently redirects bots via honeypot', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'honeypot-test']);

        Livewire::test('public.fb-offer', ['slug' => 'honeypot-test'])
            ->set('honeypot', 'bot-filled-this')
            ->set('first_name', 'Bot')
            ->set('last_name', 'User')
            ->set('email', 'bot@spam.com')
            ->call('submitLeadForm')
            ->assertRedirect(route('next-steps'));

        expect(Customer::where('email', 'bot@spam.com')->count())->toBe(0);
    });

    it('assigns variant deterministically based on visitor ID and offer ID', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'variant-test']);

        // First visit sets a variant
        $component = Livewire::test('public.fb-offer', ['slug' => 'variant-test']);
        $variant = $component->get('variant');
        $visitorId = $component->get('visitorId');

        expect($variant)->toBeIn(['a', 'b']);

        // Same visitor ID + offer ID should produce the same variant
        $expectedVariant = crc32($visitorId.$offer->id) % 2 === 0 ? 'a' : 'b';
        expect($variant)->toBe($expectedVariant);
    });

    it('shows the form section with required fields', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'form-test']);

        $this->get('/offer/form-test')
            ->assertOk()
            ->assertSee('Get Your Free Estimate')
            ->assertSee('First Name')
            ->assertSee('Last Name')
            ->assertSee('Email');
    });

    it('shows success message after form submission fallback', function () {
        Notification::fake();

        $offer = FbLandingPageOffer::factory()->create(['slug' => 'success-test']);

        // Mock InvitationService to throw so we hit the fallback path
        $mock = Mockery::mock(\App\Services\InvitationService::class);
        $mock->shouldReceive('sendInvitation')->andThrow(new \Exception('Test failure'));
        app()->instance(\App\Services\InvitationService::class, $mock);

        Livewire::test('public.fb-offer', ['slug' => 'success-test'])
            ->set('first_name', 'Success')
            ->set('last_name', 'Test')
            ->set('email', 'success@example.com')
            ->set('estimate_preference', 'in_person')
            ->call('submitLeadForm')
            ->assertSet('formSubmitted', true)
            ->assertSee("You're In!", escape: false);
    });

    it('sets sms_marketing_consent_at when marketing consent is checked', function () {
        Notification::fake();

        $offer = FbLandingPageOffer::factory()->create(['slug' => 'sms-consent-test']);

        $mock = Mockery::mock(\App\Services\InvitationService::class);
        $mock->shouldReceive('sendInvitation')->andThrow(new \Exception('Test failure'));
        app()->instance(\App\Services\InvitationService::class, $mock);

        Livewire::test('public.fb-offer', ['slug' => 'sms-consent-test'])
            ->set('first_name', 'SMS')
            ->set('last_name', 'Consent')
            ->set('email', 'sms-consent@example.com')
            ->set('phone', '303-555-9999')
            ->set('estimate_preference', 'virtual')
            ->set('smsServiceConsent', true)
            ->set('smsMarketingConsent', true)
            ->call('submitLeadForm');

        $customer = Customer::where('email', 'sms-consent@example.com')->first();
        expect($customer)->not->toBeNull()
            ->and($customer->sms_marketing_consent_at)->not->toBeNull();
    });

    it('does not set sms_marketing_consent_at when marketing consent is unchecked', function () {
        Notification::fake();

        $offer = FbLandingPageOffer::factory()->create(['slug' => 'no-sms-consent-test']);

        $mock = Mockery::mock(\App\Services\InvitationService::class);
        $mock->shouldReceive('sendInvitation')->andThrow(new \Exception('Test failure'));
        app()->instance(\App\Services\InvitationService::class, $mock);

        Livewire::test('public.fb-offer', ['slug' => 'no-sms-consent-test'])
            ->set('first_name', 'No')
            ->set('last_name', 'Consent')
            ->set('email', 'no-sms-consent@example.com')
            ->set('phone', '303-555-8888')
            ->set('estimate_preference', 'virtual')
            ->set('smsServiceConsent', false)
            ->set('smsMarketingConsent', false)
            ->call('submitLeadForm');

        $customer = Customer::where('email', 'no-sms-consent@example.com')->first();
        expect($customer)->not->toBeNull()
            ->and($customer->sms_marketing_consent_at)->toBeNull();
    });

    it('displays SMS consent checkboxes on the form', function () {
        $offer = FbLandingPageOffer::factory()->create(['slug' => 'consent-display-test']);

        $this->get('/offer/consent-display-test')
            ->assertOk()
            ->assertSee('I agree to receive service-related text messages from')
            ->assertSee('promotional offers, seasonal discounts, and painting tips');
    });
});
