<?php

use App\Models\CompanySetting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $referralCode = '';

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

        $service = app(\App\Services\ReferralService::class);
        $referralCode = $validated['referralCode'] ?? null;
        unset($validated['referralCode']);
        $service->createFromLeadForm($validated, $referralCode ?: null);

        $this->redirect(route('next-steps'));
    }
}; ?>

<div>
    {{-- Hero Section --}}
    <section class="relative bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-5xl mx-auto px-6 py-24 sm:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight mb-4">Painting Made Easy Denver</h1>
            <p class="text-xl sm:text-2xl text-blue-100 mb-10 max-w-2xl mx-auto">Professional Painting, Simplified</p>
            <a href="#get-estimate" class="inline-flex items-center gap-2 bg-[#10B981] hover:bg-emerald-600 text-white font-semibold px-8 py-4 rounded-lg text-lg transition-colors shadow-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                Get Your Free Estimate
            </a>
        </div>
    </section>

    {{-- Promo Video Section --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <h2 class="text-3xl font-bold text-zinc-900 mb-8">See How It Works</h2>
            <div id="promo-player-root" class="rounded-xl overflow-hidden shadow-xl aspect-video bg-zinc-100"></div>
            @vite('resources/js/promo-player.tsx')
        </div>
    </section>

    {{-- Value Props --}}
    <section class="bg-zinc-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8">
                {{-- Instant Estimates --}}
                <div class="text-center p-6">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-blue-100 text-[#2563EB] mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-zinc-900 mb-2">Instant Estimates</h3>
                    <p class="text-zinc-600">Get your estimate in hours, not days. Our streamlined process means less waiting and more painting.</p>
                </div>
                {{-- Real-Time Updates --}}
                <div class="text-center p-6">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-sky-100 text-[#38BDF8] mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-zinc-900 mb-2">Real-Time Updates</h3>
                    <p class="text-zinc-600">Track your project from anywhere. Your dedicated portal keeps you informed every step of the way.</p>
                </div>
                {{-- Quality Guaranteed --}}
                <div class="text-center p-6">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-100 text-[#10B981] mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-zinc-900 mb-2">Quality Guaranteed</h3>
                    <p class="text-zinc-600">Licensed, insured, and satisfaction guaranteed. We stand behind every brushstroke.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- How It Works --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-6">
            <h2 class="text-3xl font-bold text-zinc-900 text-center mb-12">How It Works</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                {{-- Step 1: Request --}}
                <div class="text-center">
                    <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-full bg-[#1B2D4F] text-white mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15a2.25 2.25 0 012.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" /></svg>
                        <span class="absolute -top-1 -right-1 w-6 h-6 bg-[#F59E0B] text-white text-xs font-bold rounded-full flex items-center justify-center">1</span>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">Request</h3>
                    <p class="text-sm text-zinc-600">Fill out our quick form below</p>
                </div>
                {{-- Step 2: Review Proposal --}}
                <div class="text-center">
                    <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-full bg-[#1B2D4F] text-white mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                        <span class="absolute -top-1 -right-1 w-6 h-6 bg-[#F59E0B] text-white text-xs font-bold rounded-full flex items-center justify-center">2</span>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">Review Proposal</h3>
                    <p class="text-sm text-zinc-600">Receive a detailed proposal in your portal</p>
                </div>
                {{-- Step 3: Sign Digitally --}}
                <div class="text-center">
                    <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-full bg-[#1B2D4F] text-white mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" /></svg>
                        <span class="absolute -top-1 -right-1 w-6 h-6 bg-[#F59E0B] text-white text-xs font-bold rounded-full flex items-center justify-center">3</span>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">Sign Digitally</h3>
                    <p class="text-sm text-zinc-600">Approve and sign right from your phone</p>
                </div>
                {{-- Step 4: We Paint --}}
                <div class="text-center">
                    <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-full bg-[#10B981] text-white mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.764m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                        <span class="absolute -top-1 -right-1 w-6 h-6 bg-[#F59E0B] text-white text-xs font-bold rounded-full flex items-center justify-center">4</span>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-1">We Paint</h3>
                    <p class="text-sm text-zinc-600">Sit back and track progress in real time</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Lead Capture Form --}}
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
                    <button type="submit" class="w-full bg-[#2563EB] hover:bg-blue-700 text-white font-semibold py-3.5 rounded-lg text-lg transition-colors shadow-md flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="submitLeadForm">Get Your Free Estimate</span>
                        <span wire:loading wire:target="submitLeadForm">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Submitting...
                        </span>
                    </button>
                </form>
            </div>
        </div>
    </section>

    {{-- Speed Messaging --}}
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-3xl mx-auto px-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-100 text-[#F59E0B] mb-4">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
            </div>
            <h2 class="text-2xl font-bold text-zinc-900 mb-3">Your estimate could be in your inbox within hours</h2>
            <p class="text-zinc-600 max-w-xl mx-auto">We review every request the same day. Once we have your details, we'll prepare a detailed proposal with transparent pricing and send it straight to your customer portal.</p>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-[#1B2D4F] text-white py-10">
        <div class="max-w-5xl mx-auto px-6">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-6">
                <p class="text-sm text-blue-200">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <a href="{{ route('login') }}" class="text-blue-200 hover:text-white transition-colors">Team Login</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('agent.login') }}" class="text-blue-200 hover:text-white transition-colors">Agent Portal</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('customer.login') }}" class="text-blue-200 hover:text-white transition-colors">Customer Portal</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('crew.login') }}" class="text-blue-200 hover:text-white transition-colors">Crew Portal</a>
                </div>
            </div>
        </div>
    </footer>
</div>
