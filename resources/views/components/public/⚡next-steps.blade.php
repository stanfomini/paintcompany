<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component {
    //
}; ?>

<div class="min-h-screen bg-zinc-50 flex items-center justify-center p-6">
    <div class="w-full max-w-2xl">
        {{-- Header --}}
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 text-[#10B981] mb-6">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-3">Thanks! Here's what happens next...</h1>
            <p class="text-zinc-600 text-lg">We've received your request and are on it.</p>
        </div>

        {{-- Steps --}}
        <div class="bg-white rounded-2xl shadow-lg p-8 sm:p-10 mb-8">
            <div class="space-y-8">
                {{-- Step 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center font-bold text-sm">1</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">We're reviewing your request right now</h3>
                        <p class="text-zinc-600">Our team looks at every submission the same day. We'll assess your project and start preparing your estimate.</p>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center font-bold text-sm">2</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">Check your email for a portal invitation</h3>
                        <p class="text-zinc-600">You'll receive an email with a link to your personal customer portal, where you can view your estimate, photos, and project details.</p>
                    </div>
                </div>

                {{-- Step 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center font-bold text-sm">3</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">Review and sign your estimate online</h3>
                        <p class="text-zinc-600">Your detailed proposal will appear in your portal with transparent pricing. When you're ready, sign digitally right from your phone or computer.</p>
                    </div>
                </div>

                {{-- Step 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#10B981] text-white flex items-center justify-center font-bold text-sm">4</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">We schedule, prep, and paint</h3>
                        <p class="text-zinc-600">You track everything in real time through your portal. From crew dispatch to final walkthrough, you'll always know what's happening.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Speed callout --}}
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-8">
            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 text-[#F59E0B] mb-3">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
            </div>
            <p class="font-semibold text-amber-900 text-lg">Most customers receive their estimate the same day</p>
        </div>

        {{-- Back to home --}}
        <div class="text-center">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-[#2563EB] hover:text-blue-700 font-medium transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                Back to Home
            </a>
        </div>
    </div>
</div>
