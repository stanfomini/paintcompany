<?php

use App\Models\Activity;
use App\Models\CompanySetting;
use App\Models\LeadSource;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Professional House Painting Denver | Painting Made Easy Denver')] class extends Component
{
    public string $referralCode = '';

    #[Computed]
    public function structuredData(): string
    {
        $company = CompanySetting::getInstance();
        $name = $company->company_name ?? config('app.name');
        $phone = $company->company_phone ?? '';
        $address = $company->company_address ?? '';
        $city = $company->company_city ?? 'Denver';
        $state = $company->company_state ?? 'CO';
        $zip = $company->company_zip ?? '';

        $localBusiness = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            '@id' => url('/').'#business',
            'name' => $name,
            'description' => 'Professional house painting services in Denver, CO. Interior & exterior painting with real-time project tracking and a satisfaction guarantee.',
            'url' => url('/'),
            'telephone' => $phone,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $address,
                'addressLocality' => $city,
                'addressRegion' => $state,
                'postalCode' => $zip,
                'addressCountry' => 'US',
            ],
            'areaServed' => ['@type' => 'City', 'name' => 'Denver', 'sameAs' => 'https://en.wikipedia.org/wiki/Denver'],
            'priceRange' => '$$',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $organization = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $name,
            'url' => url('/'),
            'logo' => asset('apple-touch-icon.png'),
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => $phone,
                'contactType' => 'customer service',
                'areaServed' => 'US',
                'availableLanguage' => 'English',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $services = json_encode([
            ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'Interior House Painting', 'description' => 'Professional interior painting for homes in the Denver metro area. Walls, ceilings, trim, and cabinets.', 'provider' => ['@id' => url('/').'#business'], 'areaServed' => ['@type' => 'City', 'name' => 'Denver']],
            ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'Exterior House Painting', 'description' => 'Professional exterior painting and staining for Denver homes. Siding, trim, decks, and fences.', 'provider' => ['@id' => url('/').'#business'], 'areaServed' => ['@type' => 'City', 'name' => 'Denver']],
            ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'Cabinet Painting', 'description' => 'Kitchen and bathroom cabinet painting and refinishing in Denver, CO.', 'provider' => ['@id' => url('/').'#business'], 'areaServed' => ['@type' => 'City', 'name' => 'Denver']],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $faq = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'How long does it take to paint a house?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Most interior projects take 2-5 days depending on the number of rooms and prep work needed. Exterior projects typically take 3-7 days. We provide a detailed timeline with your estimate so you know exactly what to expect.']],
                ['@type' => 'Question', 'name' => 'What paint brands do you use?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'We use premium paints from Sherwin-Williams and Benjamin Moore. Our most popular choices include Sherwin-Williams Duration for exteriors and Benjamin Moore Regal Select for interiors. We are happy to work with your preferred brand and color.']],
                ['@type' => 'Question', 'name' => 'Do I need to move my furniture?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'No. Our crew handles all furniture moving and protection as part of every job. We cover floors, move furniture to the center of the room, and put everything back when we are done. It is all included in your quote.']],
                ['@type' => 'Question', 'name' => 'How do virtual estimates work?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Simply submit photos of the areas you want painted through our customer portal. Our estimator reviews them, measures using advanced tools, and delivers a detailed quote, usually within 24 hours. No need to schedule an in-home visit.']],
                ['@type' => 'Question', 'name' => 'Are you licensed and insured?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. We are fully licensed, bonded, and insured in the state of Colorado. We carry general liability and workers compensation coverage on every job for your peace of mind.']],
                ['@type' => 'Question', 'name' => 'What is included in your guarantee?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Every project comes with our Cleaner Than We Found It guarantee. If you find any drips, missed spots, or mess after we leave, we come back and fix it at no charge. We also warranty our workmanship for two years.']],
                ['@type' => 'Question', 'name' => 'How much does it cost to paint a house in Denver?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Interior painting in Denver typically ranges from $2,500 to $8,000 for an average home, depending on the number of rooms, prep work, and paint quality. Exterior projects range from $4,000 to $12,000. Get a free, no-obligation estimate for your specific project.']],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return '<script type="application/ld+json">'.$localBusiness.'</script>'
            .'<script type="application/ld+json">'.$organization.'</script>'
            .'<script type="application/ld+json">'.$services.'</script>'
            .'<script type="application/ld+json">'.$faq.'</script>';
    }

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

    // Scarcity modal properties
    public string $scarcityEmail = '';

    public string $scarcityPreference = '';

    public bool $showScarcityModal = false;

    // Agent signup modal properties
    public string $agentEmail = '';

    public string $agentName = '';

    public string $agentCompany = '';

    public string $agentPhone = '';

    public ?string $agentMeetingDate = null;

    public ?string $agentMeetingTime = null;

    public bool $showAgentModal = false;

    public function mount(): void
    {
        $this->referralCode = request()->query('ref', '');
    }

    public function submitLeadForm(): void
    {
        // Honeypot check
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
            'referralCode' => ['nullable', 'string', 'max:50'],
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

        $service = app(\App\Services\ReferralService::class);
        $referralCode = $validated['referralCode'] ?? null;
        unset($validated['referralCode']);
        $service->createFromLeadForm($validated, $referralCode ?: null);

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
                $this->redirect($acceptUrl);

                return;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Auto-invite failed: '.$e->getMessage());
        }

        // Fallback if invitation creation fails
        $this->redirect(route('next-steps'));
    }

    public function openScarcityModal(string $preference): void
    {
        $this->scarcityPreference = $preference;
        $this->showScarcityModal = true;
    }

    public function submitScarcityLead(): void
    {
        $this->validate([
            'scarcityEmail' => ['required', 'email'],
        ], [
            'scarcityEmail.required' => 'Please enter your email address.',
            'scarcityEmail.email' => 'Please enter a valid email address.',
        ]);

        // Check if user already exists
        $existingUser = User::where('email', $this->scarcityEmail)->first();
        if ($existingUser) {
            session()->flash('info', 'You already have an account. Please log in.');
            $this->redirect(route('customer.login'));

            return;
        }

        // Create Customer + Deal via ReferralService
        $service = app(\App\Services\ReferralService::class);
        $result = $service->createFromLeadForm([
            'first_name' => explode('@', $this->scarcityEmail)[0],
            'last_name' => '',
            'email' => $this->scarcityEmail,
            'estimate_preference' => $this->scarcityPreference,
        ]);

        // Set lead source on customer
        $leadSource = LeadSource::findBySlug('landing_page_scarcity');
        if ($leadSource) {
            $result['customer']->update(['lead_source_id' => $leadSource->id]);
        }

        // Store prefill data
        session()->put('landing_page_prefill', [
            'estimate_preference' => $this->scarcityPreference,
        ]);

        // Create invitation and redirect to accept URL
        try {
            $invitationService = app(\App\Services\InvitationService::class);
            $firstInternal = User::where('type', 'internal')->first();

            if ($firstInternal) {
                $invitation = $invitationService->sendInvitation(
                    $firstInternal,
                    $this->scarcityEmail,
                    'customer'
                );
                $acceptUrl = $invitationService->generateAcceptUrl($invitation);
                $this->redirect($acceptUrl);

                return;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Scarcity invite failed: '.$e->getMessage());
        }

        // Fallback
        $this->redirect(route('next-steps'));
    }

    public function submitAgentSignup(): void
    {
        $this->validate([
            'agentEmail' => ['required', 'email'],
            'agentName' => ['required', 'string', 'max:255'],
            'agentCompany' => ['required', 'string', 'max:255'],
            'agentPhone' => ['required', 'string', 'max:50'],
            'agentMeetingDate' => ['nullable', 'date', 'after:today'],
            'agentMeetingTime' => ['nullable', 'date_format:H:i'],
        ], [
            'agentEmail.required' => 'Please enter your email address.',
            'agentEmail.email' => 'Please enter a valid email address.',
            'agentName.required' => 'Please enter your name.',
            'agentCompany.required' => 'Please enter your company name.',
            'agentPhone.required' => 'Please enter your phone number.',
        ]);

        // Create activity for internal team to track agent interest
        $firstInternal = User::where('type', 'internal')->first();

        $meetingDateTime = null;
        if ($this->agentMeetingDate) {
            $timeStr = $this->agentMeetingTime ?: '09:00';
            $meetingDateTime = Carbon::parse($this->agentMeetingDate.' '.$timeStr);
        }

        if ($firstInternal) {
            Activity::create([
                'user_id' => $firstInternal->id,
                'type' => $this->agentMeetingDate ? 'meeting' : 'task',
                'title' => "New Agent: {$this->agentName} from {$this->agentCompany}",
                'description' => "Company: {$this->agentCompany}\nPhone: {$this->agentPhone}\nMeeting: ".($meetingDateTime ? $meetingDateTime->format('M j, Y g:i A') : 'Not scheduled'),
                'due_at' => $meetingDateTime,
            ]);
        }

        if ($meetingDateTime) {
            session()->flash('meeting_scheduled', [
                'date' => $meetingDateTime->toIso8601String(),
                'title' => 'Painting Made Easy Denver - Agent Partnership Meeting',
                'name' => $this->agentName,
            ]);
        }

        // Send portal invitation email so agent can set up their password
        try {
            $invitationService = app(\App\Services\InvitationService::class);

            if ($firstInternal) {
                $invitation = $invitationService->sendInvitation(
                    $firstInternal,
                    $this->agentEmail,
                    'agent'
                );
                $acceptUrl = $invitationService->generateAcceptUrl($invitation);
                session()->flash('invitation_accept_url', $acceptUrl);
            }
        } catch (\Exception $e) {
            // User may already exist - don't fail the form
            \Illuminate\Support\Facades\Log::warning('Agent auto-invite failed: '.$e->getMessage());
        }

        $this->redirect(route('next-steps'));
    }
}; ?>

<div>
    @push('seo_meta')
    <meta name="description" content="Professional house painting services in Denver, CO. Interior &amp; exterior painting with real-time project tracking, daily photo updates, and a Cleaner Than We Found It guarantee. Free estimates in 24 hours.">
    <link rel="canonical" href="{{ url('/') }}">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="Professional House Painting Denver | Painting Made Easy Denver">
    <meta property="og:description" content="Denver homeowners get a stress-free painting experience with real-time project tracking, daily photo updates, and a satisfaction guarantee.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Professional House Painting Denver | Painting Made Easy Denver">
    <meta name="twitter:description" content="Denver homeowners get a stress-free painting experience with real-time project tracking, daily photo updates, and a satisfaction guarantee.">
    @endpush

    @push('structured_data')
    {!! $this->structuredData !!}
    @endpush

    {{-- Section 0: Top Nav Bar (Sticky) --}}
    @php $companyPhone = \App\Models\CompanySetting::getInstance()->company_phone; @endphp
    <nav class="fixed top-0 inset-x-0 z-50 bg-white/95 backdrop-blur-md border-b border-zinc-200/60 shadow-sm">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 flex items-center justify-between h-14">
            {{-- Left: Logo + Phone --}}
            <div class="flex items-center gap-4">
                <a href="/" class="text-sm font-bold text-zinc-900 tracking-tight">{{ __('Painting Made Easy Denver') }}</a>
                @if($companyPhone)
                    <a href="tel:{{ $companyPhone }}" class="hidden sm:inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full hover:bg-emerald-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                        {{ $companyPhone }}
                    </a>
                @endif
            </div>

            {{-- Right: Portal Login Buttons (desktop) --}}
            <div class="hidden md:flex items-center gap-2">
                <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 0h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                    {{ __('Team') }}
                </a>
                <a href="{{ route('agent.login') }}" class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" /></svg>
                    {{ __('Agent') }}
                </a>
                <a href="{{ route('customer.login') }}" class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                    {{ __('Customer') }}
                </a>
                <a href="{{ route('crew.login') }}" class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full bg-zinc-100 text-zinc-700 hover:bg-zinc-200 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                    {{ __('Crew') }}
                </a>
            </div>

            {{-- Right: Mobile login dropdown --}}
            <div class="md:hidden flex items-center gap-2" x-data="{ loginOpen: false }">
                @if($companyPhone)
                    <a href="tel:{{ $companyPhone }}" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-50 text-emerald-700">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                    </a>
                @endif
                <div class="relative">
                    <button x-on:click="loginOpen = !loginOpen" class="inline-flex items-center gap-1 text-xs font-medium px-3 py-1.5 rounded-full bg-zinc-100 text-zinc-700 hover:bg-zinc-200 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>
                        {{ __('Login') }}
                    </button>
                    <div x-show="loginOpen" x-on:click.outside="loginOpen = false" x-transition class="absolute right-0 mt-2 w-44 bg-white rounded-xl shadow-lg border border-zinc-200 py-1 z-50">
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50">{{ __('Team Portal') }}</a>
                        <a href="{{ route('agent.login') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50">{{ __('Agent Portal') }}</a>
                        <a href="{{ route('customer.login') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50">{{ __('Customer Portal') }}</a>
                        <a href="{{ route('crew.login') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50">{{ __('Crew Portal') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    {{-- Floating Click-to-Call (mobile only) --}}
    @if($companyPhone)
    <a href="tel:{{ $companyPhone }}" class="fixed bottom-4 left-4 z-40 sm:hidden inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-500 text-white shadow-lg hover:bg-emerald-600 transition-colors">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
    </a>
    @endif

    {{-- Section 1: Hero --}}
    <section class="relative bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white overflow-hidden pt-12">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-12 sm:pb-16 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight mb-6 leading-tight">{!! __('Your Home, Beautifully Transformed. <span class="text-emerald-400">Guaranteed.</span>') !!}</h1>
            <p class="text-lg sm:text-xl text-blue-100 mb-8 max-w-3xl mx-auto leading-relaxed">{{ __('Denver homeowners get a stress-free painting experience with real-time project tracking, daily photo updates, and a "Cleaner Than We Found It" guarantee. No ghosting. No surprises. No mess.') }}</p>
            <a href="#get-estimate" class="inline-flex items-center gap-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                {{ __('Get Your Free Estimate in 24 Hours') }}
            </a>
        </div>
        {{-- Trust Bar --}}
        <div class="relative border-t border-white/10">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center text-sm text-blue-100">
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <span class="font-medium">{{ __('5-Star Rated') }}</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                        <span class="font-medium">{{ __('Licensed & Insured') }}</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-5 h-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                        <span class="font-medium">{{ __('Same-Day Estimates') }}</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-5 h-5 text-rose-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
                        <span class="font-medium">{{ __('100% Satisfaction') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Section 2: Problem Stack --}}
    <section class="bg-zinc-50 py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Hiring a Painter Shouldn\'t Feel Like a Gamble') }}</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-10">
                @php
                    $painPoints = [
                        __('Painters who ghost you after the estimate'),
                        __('Surprise charges that weren\'t in the quote'),
                        __('Paint on your floors, furniture, and landscaping'),
                        __('No idea when the job will actually be done'),
                        __('Having to chase someone down for updates'),
                        __('Sloppy work that needs to be redone'),
                    ];
                @endphp
                @foreach ($painPoints as $pain)
                    <div class="flex items-start gap-3 bg-white rounded-lg p-4 shadow-sm">
                        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        <span class="text-zinc-700 text-base">{{ $pain }}</span>
                    </div>
                @endforeach
            </div>
            <div class="bg-white border-l-4 border-blue-500 rounded-r-lg p-6 shadow-sm">
                <p class="text-zinc-700 text-lg italic">{{ __('"We\'ve heard this story hundreds of times. That\'s why we built a system that makes sure it never happens to you."') }}</p>
            </div>
        </div>
    </section>

    {{-- Section 3: Promo Video --}}
    <section class="bg-[#0F1A2E] w-full py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center mb-8">
            <h2 class="text-2xl sm:text-3xl font-bold text-white mb-2">{{ __('See Why Denver Homeowners Love Working With Us') }}</h2>
        </div>
        <div class="w-full max-w-[1600px] mx-auto">
            <div id="promo-player-root" class="w-full aspect-video bg-zinc-900"></div>
            @vite('resources/js/promo-player.tsx')
        </div>
    </section>

    {{-- Post-Video CTA Banner --}}
    <section class="bg-gradient-to-r from-emerald-500 to-emerald-600 py-8 sm:py-10">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="text-xl sm:text-2xl text-white font-medium mb-5">{{ __('Ready to see how easy painting can be?') }}</p>
            <a href="#get-estimate" class="inline-flex items-center gap-3 bg-white text-emerald-700 font-bold px-10 py-4 rounded-xl text-lg transition-colors shadow-xl hover:bg-zinc-50">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                {{ __('Get Your Free Estimate') }}
            </a>
        </div>
    </section>

    {{-- Section 4a: Cleanliness Pillar --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div>
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 mb-4">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z" /></svg>
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Cleaner Than We Found It -- Guaranteed') }}</h2>
                    <p class="text-lg text-zinc-600 mb-6">{{ __('Your home is your sanctuary. We treat every surface, piece of furniture, and square inch of flooring as if it were our own.') }}</p>
                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Full surface protection on floors, counters, and furniture') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Daily cleanup at end of each work day') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Final walkthrough cleanliness checklist before sign-off') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Landscaping and exterior areas left spotless') }}</span>
                        </li>
                    </ul>
                </div>
                <div class="hidden lg:flex items-center justify-center">
                    <div class="w-full max-w-sm bg-emerald-50 rounded-2xl p-8 text-center">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-emerald-100 text-emerald-600 mb-4">
                            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                        </div>
                        <p class="text-emerald-800 font-bold text-xl">{{ __('"Cleaner Than We Found It"') }}</p>
                        <p class="text-emerald-600 mt-2">{{ __('Our guarantee on every job') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Section 4b: Stay Informed Pillar --}}
    <section class="bg-zinc-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div class="hidden lg:flex items-center justify-center order-first">
                    <div class="w-full max-w-sm bg-blue-50 rounded-2xl p-8 text-center">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-blue-100 text-blue-600 mb-4">
                            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                        </div>
                        <p class="text-blue-800 font-bold text-xl">{{ __('Real-Time Tracking') }}</p>
                        <p class="text-blue-600 mt-2">{{ __('Your project at your fingertips') }}</p>
                    </div>
                </div>
                <div>
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-4">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Know Exactly What\'s Happening. Every Step.') }}</h2>
                    <p class="text-lg text-zinc-600 mb-6">{{ __('No more wondering when the crew is coming or what happened today. Your dedicated customer portal gives you real-time visibility into every stage of your project.') }}</p>
                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Live project tracker from estimate to completion') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Daily photo updates of progress in your portal') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Direct messaging with your crew -- no phone tag') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Notifications when milestones are reached') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Section 4c: Preparation Pillar --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div>
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-100 text-amber-600 mb-4">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.58-3.28a.774.774 0 010-1.337l5.58-3.283A.774.774 0 0112.59 8v6.342a.774.774 0 01-1.17.628zM21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('The Part Nobody Sees Is The Part That Matters Most') }}</h2>
                    <p class="text-lg text-zinc-600 mb-6">{{ __('Great paint jobs don\'t start with paint. They start with meticulous preparation. Every surface gets a documented prep plan before a single coat goes on.') }}</p>
                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Surface-by-surface prep plan for every room') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Proper priming to ensure adhesion and coverage') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Caulking, patching, and sanding where needed') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Substrate-specific approach for each surface type') }}</span>
                        </li>
                    </ul>
                </div>
                <div class="hidden lg:flex items-center justify-center">
                    <div class="w-full max-w-sm bg-amber-50 rounded-2xl p-8 text-center">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-amber-100 text-amber-600 mb-4">
                            <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.58-3.28a.774.774 0 010-1.337l5.58-3.283A.774.774 0 0112.59 8v6.342a.774.774 0 01-1.17.628zM21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <p class="text-amber-800 font-bold text-xl">{{ __('Preparation First') }}</p>
                        <p class="text-amber-600 mt-2">{{ __('The foundation of every great finish') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Section 5: "You're Not Alone" --}}
    <section class="bg-gradient-to-r from-blue-50 to-emerald-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('You Don\'t Have to Navigate This Alone') }}</h2>
                <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ __('Invite your spouse, parent, or a friend to follow your project through every stage. They\'ll see the same updates, photos, and progress you do.') }}</p>
            </div>

            {{-- Stage Timeline --}}
            <div class="mb-12">
                <div class="hidden sm:flex items-center justify-between max-w-3xl mx-auto relative">
                    <div class="absolute top-5 left-0 right-0 h-0.5 bg-zinc-300"></div>
                    @foreach (\App\Models\Job::STAGES as $key => $label)
                        <div class="relative flex flex-col items-center text-center z-10">
                            <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold shadow-md">
                                {{ $loop->iteration }}
                            </div>
                            <span class="text-xs sm:text-sm font-medium text-zinc-700 mt-2 max-w-[100px] leading-tight">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
                {{-- Mobile stacked timeline --}}
                <div class="sm:hidden space-y-3">
                    @foreach (\App\Models\Job::STAGES as $key => $label)
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold shrink-0">
                                {{ $loop->iteration }}
                            </div>
                            <span class="text-sm font-medium text-zinc-700">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 text-center">
                <div class="bg-white rounded-xl p-6 shadow-sm">
                    <svg class="w-8 h-8 text-blue-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                    <h3 class="font-bold text-zinc-900 mb-1">{{ __('Shared Progress') }}</h3>
                    <p class="text-sm text-zinc-600">{{ __('Everyone sees the same stage updates and timeline') }}</p>
                </div>
                <div class="bg-white rounded-xl p-6 shadow-sm">
                    <svg class="w-8 h-8 text-blue-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" /></svg>
                    <h3 class="font-bold text-zinc-900 mb-1">{{ __('Photo Access') }}</h3>
                    <p class="text-sm text-zinc-600">{{ __('Daily photos shared with everyone on the project') }}</p>
                </div>
                <div class="bg-white rounded-xl p-6 shadow-sm">
                    <svg class="w-8 h-8 text-blue-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                    <h3 class="font-bold text-zinc-900 mb-1">{{ __('Chat Access') }}</h3>
                    <p class="text-sm text-zinc-600">{{ __('Direct messaging with the crew and project manager') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Section 6: Value Stack --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-6">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Here\'s What Most Painting Companies Won\'t Give You') }}</h2>
            </div>
            <div class="max-w-3xl mx-auto mb-10">
                <p class="text-zinc-600 text-center text-lg">{{ __('Think of the London Underground map. The real system underneath is incredibly complex -- but the map makes it simple. That\'s what we\'ve done for house painting. All the complexity of scheduling, prep, materials, and crew management is handled behind the scenes. You just see a clean, simple experience.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
                @php
                    $valueItems = [
                        ['icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z', 'title' => __('Real-Time Project Tracker'), 'desc' => __('Watch your project move through every stage from your phone or computer.')],
                        ['icon' => 'M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316zM16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z', 'title' => __('Daily Photo Updates'), 'desc' => __('See exactly what was accomplished each day with timestamped photos.')],
                        ['icon' => 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z', 'title' => __('Direct Crew Messaging'), 'desc' => __('Message your crew or project manager directly through the app.')],
                        ['icon' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z', 'title' => __('Invite Someone You Trust'), 'desc' => __('Add a spouse, parent, or friend to follow along with your project.')],
                        ['icon' => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15a2.25 2.25 0 012.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z', 'title' => __('Surface-by-Surface Prep Plan'), 'desc' => __('Every surface gets its own preparation plan documented before work begins.')],
                        ['icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z', 'title' => __('Digital Proposal with Packages'), 'desc' => __('Compare options side-by-side with transparent, itemized pricing.')],
                        ['icon' => 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15a2.25 2.25 0 012.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0118 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3l1.5 1.5 3-3.75', 'title' => __('Post-Job Walkthrough'), 'desc' => __('A final inspection together to make sure everything meets your standards.')],
                    ];
                @endphp
                @foreach ($valueItems as $item)
                    <div class="bg-zinc-50 rounded-xl p-6">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-blue-100 text-blue-600 mb-3">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg>
                        </div>
                        <h3 class="font-bold text-zinc-900 mb-1">{{ $item['title'] }}</h3>
                        <p class="text-sm text-zinc-600">{{ $item['desc'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-xl p-6 text-center">
                <p class="text-blue-800 text-lg font-medium">{{ __('"We didn\'t just hire painters. We built a system that removes every ounce of uncertainty from the process."') }}</p>
            </div>
        </div>
    </section>

    {{-- Section 7: Stacked Guarantees --}}
    <section class="bg-[#1B2D4F] py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-white mb-4">{{ __('Triple Guarantee. Zero Risk.') }}</h2>
                <p class="text-blue-200 text-lg max-w-2xl mx-auto">{{ __('Every project is backed by three iron-clad guarantees that protect you from start to finish.') }}</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/10">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-500/20 text-emerald-400 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">{{ __('Satisfaction Guarantee') }}</h3>
                    <p class="text-blue-200 text-sm">{{ __('If you\'re not thrilled with the result, we\'ll make it right. Period. No questions, no runaround, no fine print.') }}</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/10">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-sky-500/20 text-sky-400 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">{{ __('Cleanliness Guarantee') }}</h3>
                    <p class="text-blue-200 text-sm">{{ __('Your home will be cleaner than we found it. Protected surfaces, daily cleanup, and a final walkthrough to prove it.') }}</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/10">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-amber-500/20 text-amber-400 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">{{ __('Communication Guarantee') }}</h3>
                    <p class="text-blue-200 text-sm">{{ __('You\'ll never have to wonder what\'s going on. Real-time updates, daily photos, and a direct line to your crew.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Section 8: Scarcity / Availability --}}
    <section class="bg-gradient-to-r from-amber-50 to-amber-100 py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-200 text-amber-700 mb-4">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('We Only Take On What We Can Do Right') }}</h2>
            <p class="text-lg text-zinc-700 max-w-2xl mx-auto mb-8">{{ __('Because we assign dedicated crews and provide real-time tracking on every job, we limit how many projects we take each month. This ensures every homeowner gets the attention they deserve -- not a rushed, assembly-line experience.') }}</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-6">
                <button wire:click="openScarcityModal('in_person')" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-[#1B2D4F] hover:bg-[#162544] text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                    {{ __('Schedule an In-Person Estimate') }}
                </button>
                <button wire:click="openScarcityModal('virtual')" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" /></svg>
                    {{ __('Get a Virtual Estimate Today') }}
                </button>
            </div>
            <p class="text-sm text-zinc-500">{{ __('Virtual estimates use your Zillow listing photos or photos you upload -- no visit needed.') }}</p>
        </div>

        {{-- Scarcity Modal --}}
        @if($showScarcityModal)
        <flux:modal wire:model="showScarcityModal">
            <flux:heading>{{ $scarcityPreference === 'virtual' ? __('Get Your Virtual Estimate') : __('Schedule Your In-Person Estimate') }}</flux:heading>
            <form wire:submit="submitScarcityLead">
                <flux:field class="mt-4">
                    <flux:label>{{ __('Email Address') }}</flux:label>
                    <flux:input wire:model="scarcityEmail" type="email" required />
                    <flux:error name="scarcityEmail" />
                </flux:field>
                <div class="mt-4 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showScarcityModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">
                        <span wire:loading.remove wire:target="submitScarcityLead">{{ __('Get Started') }}</span>
                        <span wire:loading wire:target="submitScarcityLead">{{ __('Submitting...') }}</span>
                    </flux:button>
                </div>
            </form>
        </flux:modal>
        @endif
    </section>

    {{-- Section 9: Agent Partner --}}
    <section class="bg-zinc-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div>
                    <flux:badge color="blue" class="mb-4">{{ __('For Real Estate Agents') }}</flux:badge>
                    <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('The Easiest Referral Partner You\'ll Ever Have') }}</h2>
                    <p class="text-lg text-zinc-600 mb-6">{{ __('When your clients need painting done before listing or after closing, you need a partner who won\'t make you look bad. We make you look great.') }}</p>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Your clients get white-glove service that reflects well on you') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Track every referral and get paid automatically -- no chasing invoices') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Access our free Property Value Calculator for instant ROI estimates') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            <span class="text-zinc-700">{{ __('Your own agent dashboard to see project status and referral earnings') }}</span>
                        </li>
                    </ul>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <flux:button variant="primary" wire:click="$set('showAgentModal', true)">{{ __('Join Our Agent Network') }}</flux:button>
                        <a href="{{ route('calculator') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25v-.008zm2.25-4.5h.008v.008H10.5v-.008zm0 2.25h.008v.008H10.5v-.008zm0 2.25h.008v.008H10.5v-.008zm2.25-4.5h.008v.008H12.75v-.008zm0 2.25h.008v.008H12.75v-.008zm2.25-4.5h.008v.008H15v-.008zm0 2.25h.008v.008H15v-.008zm2.25-4.5h.008v.008H17.25v-.008z" /></svg>
                            {{ __('Try the Calculator') }}
                        </a>
                    </div>
                </div>
                <div class="hidden lg:flex items-center justify-center">
                    <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-8 text-center border border-zinc-200">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 text-blue-600 mb-4">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" /></svg>
                        </div>
                        <p class="text-zinc-900 font-bold text-xl mb-1">{{ __('Agent Dashboard') }}</p>
                        <p class="text-zinc-500 text-sm">{{ __('Track referrals, earnings, and project status all in one place.') }}</p>
                    </div>
                </div>
            </div>

            {{-- Property Value Calculator Subsection --}}
            <div class="mt-16 pt-12 border-t border-zinc-200">
                <div class="text-center mb-10">
                    <h3 class="text-2xl sm:text-3xl font-bold text-zinc-900 mb-3">{{ __('Free Property Value Calculator') }}</h3>
                    <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ __('Whether you\'re listing a home or helping a buyer, see exactly how a professional paint job impacts property value.') }}</p>
                </div>

                {{-- 3-Step Visual --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-12">
                    <div class="bg-white rounded-xl p-6 text-center shadow-sm">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        </div>
                        <div class="text-sm font-bold text-blue-600 mb-1">{{ __('Step 1') }}</div>
                        <h4 class="font-bold text-zinc-900 mb-1">{{ __('Add Your Listing') }}</h4>
                        <p class="text-sm text-zinc-600">{{ __('Enter the property details and current condition.') }}</p>
                    </div>
                    <div class="bg-white rounded-xl p-6 text-center shadow-sm">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                        </div>
                        <div class="text-sm font-bold text-emerald-600 mb-1">{{ __('Step 2') }}</div>
                        <h4 class="font-bold text-zinc-900 mb-1">{{ __('See ROI Impact') }}</h4>
                        <p class="text-sm text-zinc-600">{{ __('Get instant estimates on how paint increases value.') }}</p>
                    </div>
                    <div class="bg-white rounded-xl p-6 text-center shadow-sm">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-100 text-amber-600 mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>
                        </div>
                        <div class="text-sm font-bold text-amber-600 mb-1">{{ __('Step 3') }}</div>
                        <h4 class="font-bold text-zinc-900 mb-1">{{ __('Share with Clients') }}</h4>
                        <p class="text-sm text-zinc-600">{{ __('Present data-backed recommendations to homeowners.') }}</p>
                    </div>
                </div>

                {{-- Selling vs Buying --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-10">
                    <div class="bg-white rounded-xl p-6 border border-emerald-200">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-emerald-100 text-emerald-600">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" /></svg>
                            </div>
                            <h4 class="font-bold text-zinc-900 text-lg">{{ __('Selling a Home') }}</h4>
                        </div>
                        <p class="text-zinc-600">{{ __('Increase listing price by thousands with a strategic paint job. Fresh paint is one of the highest-ROI improvements a seller can make.') }}</p>
                    </div>
                    <div class="bg-white rounded-xl p-6 border border-blue-200">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-blue-100 text-blue-600">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                            </div>
                            <h4 class="font-bold text-zinc-900 text-lg">{{ __('Buying a Home') }}</h4>
                        </div>
                        <p class="text-zinc-600">{{ __('Budget accurately for move-in ready updates. Help your buyers understand the true cost of making a new house feel like home.') }}</p>
                    </div>
                </div>

                {{-- CTA --}}
                <div class="text-center">
                    <a href="{{ route('calculator') }}" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25v-.008zm2.25-4.5h.008v.008H10.5v-.008zm0 2.25h.008v.008H10.5v-.008zm0 2.25h.008v.008H10.5v-.008zm2.25-4.5h.008v.008H12.75v-.008zm0 2.25h.008v.008H12.75v-.008zm2.25-4.5h.008v.008H15v-.008zm0 2.25h.008v.008H15v-.008zm2.25-4.5h.008v.008H17.25v-.008z" /></svg>
                        {{ __('Try the Property Value Calculator') }}
                    </a>
                    <p class="text-sm text-zinc-500 mt-3">{{ __('Free for all agents. See the calculator in action.') }}</p>
                </div>
            </div>
        </div>

        {{-- Agent Signup Modal --}}
        @if($showAgentModal)
        <flux:modal wire:model="showAgentModal">
            <flux:heading>{{ __('Join Our Agent Network') }}</flux:heading>
            <form wire:submit="submitAgentSignup">
                <div class="space-y-4 mt-4">
                    <flux:field>
                        <flux:label>{{ __('Email') }}</flux:label>
                        <flux:input wire:model="agentEmail" type="email" required />
                        <flux:error name="agentEmail" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Full Name') }}</flux:label>
                        <flux:input wire:model="agentName" required />
                        <flux:error name="agentName" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Company Name') }}</flux:label>
                        <flux:input wire:model="agentCompany" required />
                        <flux:error name="agentCompany" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Phone Number') }}</flux:label>
                        <flux:input wire:model="agentPhone" required />
                        <flux:error name="agentPhone" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Schedule a Meet? (Optional)') }}</flux:label>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model="agentMeetingDate" type="date" />
                            <flux:input wire:model="agentMeetingTime" type="time" />
                        </div>
                        <flux:error name="agentMeetingDate" />
                        <flux:error name="agentMeetingTime" />
                    </flux:field>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showAgentModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">
                        <span wire:loading.remove wire:target="submitAgentSignup">{{ __('Join Network') }}</span>
                        <span wire:loading wire:target="submitAgentSignup">{{ __('Submitting...') }}</span>
                    </flux:button>
                </div>
            </form>
        </flux:modal>
        @endif
    </section>

    {{-- Section 10: Referral Program --}}
    <section class="bg-gradient-to-r from-emerald-500 to-emerald-600 py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center text-white">
            <h2 class="text-3xl sm:text-4xl font-bold mb-4">{{ __('Earn $:amount for Every Referral', ['amount' => number_format(CompanySetting::getInstance()->referral_reward_amount ?? 100)]) }}</h2>
            <p class="text-emerald-100 text-lg max-w-2xl mx-auto mb-10">{{ __('Know someone who needs painting? Send them our way and get paid when the job is complete. It\'s that simple.') }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-10">
                <div class="bg-white/15 backdrop-blur-sm rounded-xl p-6">
                    <div class="text-3xl font-bold mb-2">1</div>
                    <h3 class="font-bold text-lg mb-1">{{ __('Sign Up') }}</h3>
                    <p class="text-emerald-100 text-sm">{{ __('Create your free account and get your unique referral link.') }}</p>
                </div>
                <div class="bg-white/15 backdrop-blur-sm rounded-xl p-6">
                    <div class="text-3xl font-bold mb-2">2</div>
                    <h3 class="font-bold text-lg mb-1">{{ __('Share') }}</h3>
                    <p class="text-emerald-100 text-sm">{{ __('Send your link to friends, family, and neighbors who need painting.') }}</p>
                </div>
                <div class="bg-white/15 backdrop-blur-sm rounded-xl p-6">
                    <div class="text-3xl font-bold mb-2">3</div>
                    <h3 class="font-bold text-lg mb-1">{{ __('Get Paid') }}</h3>
                    <p class="text-emerald-100 text-sm">{{ __('Receive $:amount when the job is completed. No cap on earnings.', ['amount' => number_format(CompanySetting::getInstance()->referral_reward_amount ?? 100)]) }}</p>
                </div>
            </div>
            <a href="#get-estimate" class="inline-flex items-center gap-2 bg-white text-emerald-700 font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl hover:bg-zinc-50">
                {{ __('Get Started Today') }}
            </a>
        </div>
    </section>

    {{-- Section 10.5: FAQ --}}
    <section id="faq" class="bg-white py-16 sm:py-20">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-3">{{ __('Frequently Asked Questions') }}</h2>
                <p class="text-zinc-600 text-lg">{{ __('Everything you need to know before your painting project.') }}</p>
            </div>

            <div class="space-y-3" x-data="{ openFaq: null }">
                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 1 ? null : 1" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('How long does it take to paint a house?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 1" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('Most interior projects take 2-5 days depending on the number of rooms and prep work needed. Exterior projects typically take 3-7 days. We provide a detailed timeline with your estimate so you know exactly what to expect.') }}</div>
                    </div>
                </div>

                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 2 ? null : 2" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('What paint brands do you use?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 2" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('We use premium paints from Sherwin-Williams and Benjamin Moore. Our most popular choices include Sherwin-Williams Duration for exteriors and Benjamin Moore Regal Select for interiors. We are happy to work with your preferred brand and color.') }}</div>
                    </div>
                </div>

                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 3 ? null : 3" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('Do I need to move my furniture?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 3" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('No. Our crew handles all furniture moving and protection as part of every job. We cover floors, move furniture to the center of the room, and put everything back when we are done. It is all included in your quote.') }}</div>
                    </div>
                </div>

                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 4 ? null : 4" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('How do virtual estimates work?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 4" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('Simply submit photos of the areas you want painted through our customer portal. Our estimator reviews them, measures using advanced tools, and delivers a detailed quote, usually within 24 hours. No need to schedule an in-home visit.') }}</div>
                    </div>
                </div>

                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 5 ? null : 5" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('Are you licensed and insured?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 5" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('Yes. We are fully licensed, bonded, and insured in the state of Colorado. We carry general liability and workers compensation coverage on every job for your peace of mind.') }}</div>
                    </div>
                </div>

                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 6 ? null : 6" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('What is included in your guarantee?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 6 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 6" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('Every project comes with our Cleaner Than We Found It guarantee. If you find any drips, missed spots, or mess after we leave, we come back and fix it at no charge. We also warranty our workmanship for two years.') }}</div>
                    </div>
                </div>

                <div class="border border-zinc-200 rounded-xl overflow-hidden">
                    <button x-on:click="openFaq = openFaq === 7 ? null : 7" class="w-full flex items-center justify-between px-6 py-4 text-left bg-white hover:bg-zinc-50 transition-colors">
                        <span class="font-semibold text-zinc-900">{{ __('How much does it cost to paint a house in Denver?') }}</span>
                        <svg class="w-5 h-5 text-zinc-400 shrink-0 transition-transform duration-200" :class="openFaq === 7 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="openFaq === 7" x-collapse>
                        <div class="px-6 pb-4 text-zinc-600">{{ __('Interior painting in Denver typically ranges from $2,500 to $8,000 for an average home, depending on the number of rooms, prep work, and paint quality. Exterior projects range from $4,000 to $12,000. Get a free, no-obligation estimate for your specific project.') }}</div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-10">
                <a href="#get-estimate" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    {{ __('Still Have Questions? Get a Free Estimate') }}
                </a>
            </div>
        </div>
    </section>

    {{-- Section 11: Lead Capture Form --}}
    <section id="get-estimate" class="bg-zinc-100 py-16 sm:py-20">
        <div class="max-w-2xl mx-auto px-6">
            <div class="bg-white rounded-2xl shadow-lg p-8 sm:p-10">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-bold text-zinc-900 mb-2">Get Your Free Estimate</h2>
                    <p class="text-zinc-600">Tell us about your project and we'll get back to you fast.</p>
                </div>

                <form wire:submit="submitLeadForm" class="space-y-5">
                    {{-- Honeypot --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="website_url">Website</label>
                        <input type="text" name="website_url" id="website_url" wire:model="honeypot" tabindex="-1" autocomplete="off" />
                    </div>

                    {{-- Name --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="first_name" class="block text-sm font-medium text-zinc-700 mb-1">First Name <span class="text-red-500">*</span></label>
                            <input type="text" id="first_name" wire:model="first_name" placeholder="First name" required
                                class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                            @error('first_name') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="last_name" class="block text-sm font-medium text-zinc-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" id="last_name" wire:model="last_name" placeholder="Last name" required
                                class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                            @error('last_name') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-zinc-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" id="email" wire:model="email" placeholder="you@example.com" required
                            class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                        @error('email') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Phone --}}
                    <div>
                        <label for="phone" class="block text-sm font-medium text-zinc-700 mb-1">Phone</label>
                        <input type="tel" id="phone" wire:model="phone" placeholder="(555) 123-4567"
                            class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                        @error('phone') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Address --}}
                    <div>
                        <label for="address" class="block text-sm font-medium text-zinc-700 mb-1">Property Address</label>
                        <input type="text" id="address" wire:model="address" placeholder="123 Main Street"
                            class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                        @error('address') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- City / State / Zip --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="city" class="block text-sm font-medium text-zinc-700 mb-1">City</label>
                            <input type="text" id="city" wire:model="city" placeholder="Denver"
                                class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                            @error('city') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="state" class="block text-sm font-medium text-zinc-700 mb-1">State</label>
                            <input type="text" id="state" wire:model="state" placeholder="CO"
                                class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                            @error('state') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label for="zip" class="block text-sm font-medium text-zinc-700 mb-1">Zip</label>
                            <input type="text" id="zip" wire:model="zip" placeholder="80202"
                                class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none" />
                            @error('zip') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Estimate Preference --}}
                    <fieldset>
                        <legend class="block text-sm font-medium text-zinc-700 mb-2">How would you like your estimate? <span class="text-red-500">*</span></legend>
                        <div class="flex gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="estimate_preference" value="virtual" class="text-blue-600 focus:ring-blue-500" />
                                <span class="text-sm text-zinc-700">Virtual Estimate</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="estimate_preference" value="in_person" class="text-blue-600 focus:ring-blue-500" />
                                <span class="text-sm text-zinc-700">In-Person Estimate</span>
                            </label>
                        </div>
                        @error('estimate_preference') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                    </fieldset>

                    {{-- Description --}}
                    <div>
                        <label for="description" class="block text-sm font-medium text-zinc-700 mb-1">Tell us about your project (optional)</label>
                        <textarea id="description" wire:model="description" rows="3" placeholder="Which rooms? Interior, exterior, or both? Any special requests?"
                            class="w-full rounded-lg border border-zinc-300 px-4 py-2.5 text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none resize-y"></textarea>
                        @error('description') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Hidden referral code --}}
                    <input type="hidden" wire:model="referralCode" />

                    {{-- Submit --}}
                    <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-semibold py-3.5 rounded-lg text-lg transition-colors shadow-md flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="submitLeadForm">Get My Free Estimate Now &mdash; No Obligation</span>
                        <span wire:loading wire:target="submitLeadForm">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Submitting...
                        </span>
                    </button>
                </form>
            </div>
        </div>
    </section>

    {{-- Section 12: Speed Messaging --}}
    <section class="bg-white py-10 sm:py-12">
        <div class="max-w-3xl mx-auto px-6 text-center">
            <div class="inline-flex items-center gap-3">
                <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 text-amber-500">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                </div>
                <p class="text-lg sm:text-xl font-bold text-zinc-900">Most Denver Homeowners Receive Their Estimate Within Hours</p>
            </div>
        </div>
    </section>

    {{-- Section 13: Meet Your Local Team (Geo-personalized) --}}
    <livewire:public.local-team />

    {{-- Section 14: Footer --}}
    <footer class="bg-[#1B2D4F] text-white py-10">
        <div class="max-w-5xl mx-auto px-6">
            {{-- Service Areas Links --}}
            @php $footerAreas = \App\Models\ServiceArea::active()->orderBy('city')->get(); @endphp
            @if($footerAreas->isNotEmpty())
            <div class="mb-8 pb-8 border-b border-white/10">
                <h3 class="text-sm font-bold text-blue-200 uppercase tracking-wider mb-4">{{ __('Service Areas') }}</h3>
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                    @foreach($footerAreas as $footerArea)
                        <a href="{{ route('areas.show', $footerArea->slug) }}" class="text-blue-300 hover:text-white transition-colors">{{ $footerArea->city }}</a>
                    @endforeach
                    <a href="{{ route('areas.index') }}" class="text-emerald-400 hover:text-emerald-300 transition-colors font-medium">{{ __('View All') }} &rarr;</a>
                </div>
            </div>
            @endif
            <div class="flex flex-col sm:flex-row items-center justify-between gap-6">
                <p class="text-sm text-blue-200">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <a href="{{ route('areas.index') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Service Areas') }}</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.terms') }}" class="text-blue-200 hover:text-white transition-colors">Terms</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.privacy') }}" class="text-blue-200 hover:text-white transition-colors">Privacy</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.referral-terms') }}" class="text-blue-200 hover:text-white transition-colors">Referral Terms</a>
                </div>
            </div>
        </div>
    </footer>

    {{-- Live Chat Widget --}}
    <livewire:public.guest-chat />
</div>
