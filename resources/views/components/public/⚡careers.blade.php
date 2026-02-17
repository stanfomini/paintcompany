<?php

use App\Models\CompanySetting;
use App\Models\JobListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    #[Computed]
    public function listings(): \Illuminate\Database\Eloquent\Collection
    {
        return JobListing::query()
            ->active()
            ->published()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    #[Computed]
    public function companyName(): string
    {
        $settings = CompanySetting::first();

        return $settings?->company_name ?? config('app.name');
    }
}; ?>

<div>
    {{-- Nav Bar --}}
    <nav class="fixed top-0 inset-x-0 z-50 bg-white/80 backdrop-blur-md border-b border-zinc-200/60 dark:bg-zinc-900/80 dark:border-zinc-700/60">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 flex items-center justify-between h-12">
            <a href="/" class="text-sm font-bold text-zinc-900 dark:text-white tracking-tight">{{ $this->companyName }}</a>
            <div class="flex items-center gap-3 text-xs">
                <a href="{{ route('login') }}" class="text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Team Login</a>
            </div>
        </div>
    </nav>

    {{-- Hero --}}
    <section class="relative bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white overflow-hidden pt-12">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-4xl mx-auto px-4 sm:px-6 pt-16 sm:pt-20 pb-12 sm:pb-16 text-center">
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight mb-4 leading-tight">Join Our Team</h1>
            <p class="text-lg sm:text-xl text-blue-100 mb-6 max-w-2xl mx-auto leading-relaxed">Build a career with {{ $this->companyName }}. We offer competitive pay, professional development, and a team-focused culture.</p>
        </div>
    </section>

    {{-- Job Listings --}}
    <section class="bg-zinc-50 dark:bg-zinc-900 py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            @if($this->listings->isEmpty())
                <div class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-zinc-100 dark:bg-zinc-800 mb-4">
                        <flux:icon.briefcase class="size-8 text-zinc-400" />
                    </div>
                    <h2 class="text-xl font-semibold text-zinc-900 dark:text-white mb-2">No Open Positions Right Now</h2>
                    <p class="text-zinc-500 dark:text-zinc-400 mb-6">We don't have any specific openings at the moment, but we're always looking for great people.</p>
                </div>
            @else
                <h2 class="text-2xl font-bold text-zinc-900 dark:text-white mb-6">Open Positions</h2>
                <div class="space-y-4">
                    @foreach($this->listings as $listing)
                        <div wire:key="listing-{{ $listing->id }}" class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $listing->title }}</h3>
                                    <div class="flex flex-wrap items-center gap-3 mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                                        @if($listing->location)
                                            <span class="flex items-center gap-1">
                                                <flux:icon.map-pin class="size-4" />
                                                {{ $listing->location }}
                                            </span>
                                        @endif
                                        <span class="flex items-center gap-1">
                                            <flux:icon.clock class="size-4" />
                                            {{ JobListing::EMPLOYMENT_TYPES[$listing->employment_type] ?? $listing->employment_type }}
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <flux:icon.banknotes class="size-4" />
                                            {{ $listing->getPayRangeDisplay() }}
                                        </span>
                                    </div>
                                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300 line-clamp-2">{{ Str::limit(strip_tags($listing->description), 200) }}</p>
                                </div>
                                <div class="shrink-0">
                                    <a href="{{ route('careers.apply', ['jobListing' => $listing]) }}" wire:navigate>
                                        <flux:button variant="primary">Apply Now</flux:button>
                                    </a>
                                </div>
                            </div>
                            @if($listing->remainingPositions() > 1)
                                <div class="mt-3">
                                    <flux:badge color="green" size="sm">{{ $listing->remainingPositions() }} positions available</flux:badge>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- General Application Card --}}
            <div class="mt-8 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-950/30 dark:to-indigo-950/30 rounded-xl border border-blue-200 dark:border-blue-800 p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Don't See the Right Fit?</h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300 mt-1">Submit a general application and we'll reach out when a matching position opens up.</p>
                    </div>
                    <a href="{{ route('careers.apply') }}" wire:navigate>
                        <flux:button variant="filled">General Application</flux:button>
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Why Work With Us --}}
    <section class="bg-white dark:bg-zinc-800 py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl font-bold text-zinc-900 dark:text-white mb-8 text-center">Why Work With Us</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @php
                    $benefits = [
                        ['icon' => 'currency-dollar', 'title' => 'Competitive Pay', 'desc' => 'Above-market wages with performance bonuses and consistent year-round work.'],
                        ['icon' => 'academic-cap', 'title' => 'Training & Growth', 'desc' => 'Paid training programs, certifications, and clear paths for advancement.'],
                        ['icon' => 'users', 'title' => 'Great Team Culture', 'desc' => 'Supportive environment with team events, mentorship, and mutual respect.'],
                        ['icon' => 'shield-check', 'title' => 'Safety First', 'desc' => 'Full safety equipment, OSHA compliance, and ongoing safety training.'],
                        ['icon' => 'clock', 'title' => 'Work-Life Balance', 'desc' => 'Predictable schedules, PTO, and flexible hours where possible.'],
                        ['icon' => 'wrench-screwdriver', 'title' => 'Quality Tools', 'desc' => 'Professional-grade equipment and premium paints provided on every job.'],
                    ];
                @endphp
                @foreach($benefits as $benefit)
                    <div class="text-center p-4">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900/40 mb-3">
                            <flux:icon :name="$benefit['icon']" class="size-6 text-blue-600 dark:text-blue-400" />
                        </div>
                        <h3 class="font-semibold text-zinc-900 dark:text-white mb-1">{{ $benefit['title'] }}</h3>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $benefit['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-zinc-100 dark:bg-zinc-900 border-t border-zinc-200 dark:border-zinc-700 py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            &copy; {{ date('Y') }} {{ $this->companyName }}. All rights reserved.
        </div>
    </footer>
</div>
