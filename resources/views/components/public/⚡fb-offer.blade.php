<?php

use App\DTOs\LeadResolutionResult;
use App\Models\CompanySetting;
use App\Models\FbLandingPageOffer;
use App\Models\FbLandingPageView;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest-light')] #[Title('Special Offer')] class extends Component
{
    public string $slug = '';

    public ?int $offerId = null;

    public string $variant = 'a';

    public string $visitorId = '';

    // Form fields
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $estimate_preference = 'virtual';

    public string $description = '';

    public string $honeypot = '';

    public bool $smsServiceConsent = false;

    public bool $smsMarketingConsent = false;

    public bool $formSubmitted = false;

    public function mount(string $slug): void
    {
        $offer = FbLandingPageOffer::where('slug', $slug)->active()->firstOrFail();
        $this->slug = $slug;
        $this->offerId = $offer->id;

        // Cookie-based visitor ID for A/B testing
        $this->visitorId = request()->cookie('fb_ab_visitor') ?? '';

        if (empty($this->visitorId)) {
            $this->visitorId = Str::uuid()->toString();
            cookie()->queue(cookie('fb_ab_visitor', $this->visitorId, 60 * 24 * 90));
        }

        // Deterministic A/B variant assignment
        $this->variant = crc32($this->visitorId . $offer->id) % 2 === 0 ? 'a' : 'b';

        // Record page view with UTM params
        FbLandingPageView::create([
            'fb_landing_page_offer_id' => $offer->id,
            'variant' => $this->variant,
            'visitor_id' => $this->visitorId,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'referrer' => request()->header('referer'),
            'utm_source' => request()->query('utm_source'),
            'utm_medium' => request()->query('utm_medium'),
            'utm_campaign' => request()->query('utm_campaign'),
            'utm_content' => request()->query('utm_content'),
            'fbclid' => request()->query('fbclid'),
        ]);
    }

    #[Computed]
    public function offer(): ?FbLandingPageOffer
    {
        if (! $this->offerId) {
            return null;
        }

        return FbLandingPageOffer::with('videoRecording')->find($this->offerId);
    }

    #[Computed]
    public function videoUrl(): ?string
    {
        $offer = $this->offer;

        if ($this->variant !== 'b' || ! $offer?->video_recording_id || ! $offer->videoRecording) {
            return null;
        }

        return $offer->videoRecording->getVideoUrl();
    }

    public function submitLeadForm(): void
    {
        // Honeypot check — silently redirect bots
        if (! empty($this->honeypot)) {
            $this->redirect(route('next-steps'));

            return;
        }

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'estimate_preference' => ['required', 'in:virtual,in_person'],
            'description' => ['nullable', 'string', 'max:5000'],
            'smsServiceConsent' => ['nullable', 'boolean'],
            'smsMarketingConsent' => ['nullable', 'boolean'],
        ], [
            'first_name.required' => 'Please enter your first name.',
            'last_name.required' => 'Please enter your last name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        // Check if user already exists
        $existingUser = User::where('email', $validated['email'])->first();
        if ($existingUser) {
            session()->flash('info', 'You already have an account. Please log in.');
            $this->redirect(route('customer.login'));

            return;
        }

        // Create customer + deal via deduplication service
        $dedup = app(\App\Services\LeadDeduplicationService::class);
        $createdBy = User::where('type', 'internal')->first()?->id;

        $result = $dedup->resolveOrCreate(
            customerData: [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'zip' => $validated['zip'] ?? null,
                'source' => 'online',
                'estimate_preference' => $validated['estimate_preference'] ?? null,
                'status' => 'active',
                'created_by' => $createdBy,
                'sms_marketing_consent_at' => $this->smsMarketingConsent ? now() : null,
            ],
            dealData: [
                'title' => 'Painting Estimate - ' . trim($validated['first_name'] . ' ' . $validated['last_name']),
                'description' => $validated['description'] ?? null,
                'stage' => 'lead',
                'lead_source' => 'facebook_ads',
                'created_by' => $createdBy,
            ],
            options: ['source_label' => 'Facebook Ad Landing Page'],
        );

        // Mark the view as converted
        $view = FbLandingPageView::where('fb_landing_page_offer_id', $this->offerId)
            ->where('visitor_id', $this->visitorId)
            ->latest()
            ->first();

        if ($view) {
            $view->markConverted($result->customer, $result->deal);
        }

        // Store prefill data for onboarding
        session()->put('landing_page_prefill', [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'estimate_preference' => $this->estimate_preference,
            'description' => $this->description,
        ]);

        // Create invitation and redirect to accept URL
        try {
            $invitationService = app(\App\Services\InvitationService::class);
            $firstInternal = User::where('type', 'internal')->first();

            if ($firstInternal) {
                $invitation = $invitationService->sendInvitation(
                    $firstInternal,
                    $validated['email'],
                    'customer'
                );
                $acceptUrl = $invitationService->generateAcceptUrl($invitation);
                $this->dispatch('fb-conversion');
                $this->redirect($acceptUrl);

                return;
            }
        } catch (\Exception $e) {
            Log::warning('FB offer auto-invite failed: ' . $e->getMessage());
        }

        // Fallback if invitation creation fails
        $this->dispatch('fb-conversion');
        $this->formSubmitted = true;
    }
};
?>

@section('robots', 'noindex, nofollow')

<div>
    @php
        $offer = $this->offer;
        $company = \App\Models\CompanySetting::getInstance();
    @endphp

    @if(! $offer)
        <div class="min-h-screen flex items-center justify-center">
            <p class="text-zinc-500">Offer not found.</p>
        </div>
    @else

    {{-- FB Pixel --}}
    @if($offer->fb_pixel_id)
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ $offer->fb_pixel_id }}');
        fbq('track', 'PageView');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ $offer->fb_pixel_id }}&ev=PageView&noscript=1"/>
    </noscript>
    @endif

    @script
    <script>
        Livewire.on('fb-conversion', () => {
            if (typeof fbq === 'function') {
                fbq('track', 'Lead');
            }
        });
    </script>
    @endscript

    {{-- 1. Minimal Nav --}}
    <nav class="bg-white border-b border-zinc-200 px-4 py-3">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
            <img src="{{ asset('apple-touch-icon.png') }}" alt="{{ $company->company_name ?? config('app.name') }}" class="h-10 w-10 rounded-lg">
            @if($company->company_phone)
                <a href="tel:{{ $company->company_phone }}" class="text-sm font-medium text-zinc-700 hover:text-emerald-600 flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    {{ $company->company_phone }}
                </a>
            @endif
        </div>
    </nav>

    {{-- 2. Hero Section (split layout) --}}
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                {{-- Left: Copy --}}
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full bg-emerald-100 text-emerald-700 px-4 py-1.5 text-sm font-semibold mb-4">
                        LIMITED OFFER
                    </div>
                    <h1 class="text-4xl sm:text-5xl font-bold text-zinc-900 mb-4">{{ $offer->headline }}</h1>
                    @if($offer->subheadline)
                        <p class="text-lg text-zinc-600 mb-6">{{ $offer->subheadline }}</p>
                    @endif
                    @if($offer->offer_bullets)
                        <ul class="space-y-3 mb-8">
                            @foreach($offer->offer_bullets as $bullet)
                                <li class="flex items-start gap-3" wire:key="bullet-{{ $loop->index }}">
                                    <svg class="w-5 h-5 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    <span class="text-zinc-700">{{ $bullet }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="#get-estimate" class="inline-flex items-center gap-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg hover:shadow-xl">
                        {{ $offer->cta_text }}
                    </a>
                </div>

                {{-- Right: Photo (A) or Video (B) --}}
                <div class="rounded-2xl overflow-hidden shadow-2xl bg-zinc-100 aspect-[4/3]">
                    @if($variant === 'b' && $offer->video_recording_id && $this->videoUrl)
                        <video
                            autoplay muted loop playsinline
                            class="w-full h-full object-cover"
                            @if($offer->videoRecording?->thumbnail_path)
                                poster="{{ \Illuminate\Support\Facades\Storage::disk($offer->videoRecording->disk ?? 'public')->url($offer->videoRecording->thumbnail_path) }}"
                            @endif
                        >
                            <source src="{{ $this->videoUrl }}" type="video/mp4">
                        </video>
                    @elseif($offer->hero_photo_path)
                        <img src="{{ $offer->hero_photo_url }}" alt="{{ $offer->headline }}" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-emerald-100 to-blue-100">
                            <svg class="w-16 h-16 text-emerald-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z"/></svg>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- 3. Trust Bar --}}
    <section class="bg-zinc-50 border-y border-zinc-200 py-5">
        <div class="max-w-5xl mx-auto px-4">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center text-sm text-zinc-600">
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <span class="font-medium">5-Star Rated</span>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                    <span class="font-medium">Licensed & Insured</span>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="font-medium">Satisfaction Guaranteed</span>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                    <span class="font-medium">Same-Day Response</span>
                </div>
            </div>
        </div>
    </section>

    {{-- 4. What You Get Section --}}
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 text-center mb-10">What You Get</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="text-center p-6 rounded-xl bg-zinc-50">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-3">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"/></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">Free Color Tools</h3>
                    <p class="text-sm text-zinc-600">AI-powered visualizer to see colors on your walls before you commit.</p>
                </div>
                <div class="text-center p-6 rounded-xl bg-zinc-50">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 mb-3">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">Options-Based Estimates</h3>
                    <p class="text-sm text-zinc-600">Choose what fits your budget. No pressure, no surprises.</p>
                </div>
                <div class="text-center p-6 rounded-xl bg-zinc-50">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-100 text-amber-600 mb-3">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0118 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3l1.5 1.5 3-3.75"/></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">Written Project Plan</h3>
                    <p class="text-sm text-zinc-600">Know exactly what to expect before any work begins.</p>
                </div>
                <div class="text-center p-6 rounded-xl bg-zinc-50">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-rose-100 text-rose-600 mb-3">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">All the Extras</h3>
                    <p class="text-sm text-zinc-600">Furniture moving, floor protection, daily cleanup — included.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- 5. Lead Form --}}
    <section id="get-estimate" class="bg-zinc-50 py-12 sm:py-16">
        <div class="max-w-2xl mx-auto px-4 sm:px-6">
            @if($formSubmitted)
                <div class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 mb-4">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    </div>
                    <h2 class="text-2xl font-bold text-zinc-900 mb-2">You're In!</h2>
                    <p class="text-zinc-600">We'll be in touch within a few hours with your custom estimate. Check your email for next steps.</p>
                </div>
            @else
                <div class="text-center mb-8">
                    <h2 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-2">Get Your Free Estimate</h2>
                    <p class="text-zinc-600">Fill out the form below and we'll get back to you within hours — not days.</p>
                </div>
                <form wire:submit="submitLeadForm" class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 space-y-5">
                    {{-- Honeypot --}}
                    <div class="hidden" aria-hidden="true">
                        <input type="text" wire:model="honeypot" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <flux:input wire:model="first_name" label="First Name" required />
                            @error('first_name') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <flux:input wire:model="last_name" label="Last Name" required />
                            @error('last_name') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <flux:input wire:model="email" label="Email" type="email" required />
                            @error('email') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <flux:input wire:model="phone" label="Phone" type="tel" />
                            @error('phone') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- SMS Service Consent (optional) --}}
                    <div class="mt-2">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="smsServiceConsent" class="mt-0.5 text-emerald-600" />
                            <span class="text-xs text-zinc-500">
                                I agree to receive service-related text messages from {{ config('app.name') }} (appointment updates, estimate notifications, project info).
                                Message frequency varies. Msg &amp; data rates may apply. Reply STOP to cancel. Reply HELP for help.
                                <a href="{{ route('legal.privacy') }}" class="underline">Privacy Policy</a> |
                                <a href="{{ route('legal.terms') }}" class="underline">Terms</a>
                            </span>
                        </label>
                    </div>

                    {{-- SMS Marketing Consent (optional) --}}
                    <div class="mt-2">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="smsMarketingConsent" class="mt-0.5 text-emerald-600" />
                            <span class="text-xs text-zinc-500">
                                I'd also like to receive promotional offers, seasonal discounts, and painting tips from {{ config('app.name') }} via text.
                                Message frequency varies. Msg &amp; data rates may apply. Reply STOP to cancel. Reply HELP for help.
                                <a href="{{ route('legal.privacy') }}" class="underline">Privacy Policy</a> |
                                <a href="{{ route('legal.terms') }}" class="underline">Terms</a>
                            </span>
                        </label>
                    </div>

                    <div>
                        <flux:input wire:model="address" label="Street Address" />
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div>
                            <flux:input wire:model="city" label="City" />
                        </div>
                        <div>
                            <flux:input wire:model="state" label="State" />
                        </div>
                        <div>
                            <flux:input wire:model="zip" label="ZIP" />
                        </div>
                    </div>
                    <div>
                        <flux:select wire:model="estimate_preference" label="Estimate Preference">
                            <option value="virtual">Virtual Estimate (fastest)</option>
                            <option value="in_person">In-Person Estimate</option>
                        </flux:select>
                    </div>
                    <div>
                        <flux:textarea wire:model="description" label="Tell us about your project (optional)" rows="3" />
                    </div>
                    <flux:button type="submit" variant="primary" class="w-full !bg-emerald-600 hover:!bg-emerald-700 !py-3 !text-lg" wire:loading.attr="disabled">
                        <span wire:loading.remove>{{ $offer->cta_text }}</span>
                        <span wire:loading>Submitting...</span>
                    </flux:button>
                    <p class="text-xs text-zinc-400 text-center">Your information is secure. We never share your data with third parties.</p>
                </form>
            @endif
        </div>
    </section>

    {{-- 6. Urgency Bar --}}
    <section class="bg-gradient-to-r from-amber-500 to-orange-500 py-4">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="text-white font-bold text-base sm:text-lg">LIMITED: Feature your home & save up to 25% on your project</p>
        </div>
    </section>

    {{-- 7. Minimal Footer --}}
    <footer class="bg-zinc-900 py-8">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="text-zinc-400 text-sm">&copy; {{ date('Y') }} {{ $company->company_name ?? config('app.name') }}. All rights reserved.</p>
            <div class="mt-2 flex items-center justify-center gap-4 text-zinc-500 text-xs">
                <a href="{{ route('legal.privacy') }}" class="hover:text-zinc-300">Privacy Policy</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-zinc-300">Terms of Service</a>
            </div>
        </div>
    </footer>

    @endif
</div>
