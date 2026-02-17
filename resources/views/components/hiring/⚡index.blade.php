<?php

use App\Models\Applicant;
use App\Models\JobListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Hiring')]
class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('hiring.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function totalApplicants(): int
    {
        return Applicant::count();
    }

    #[Computed]
    public function newThisWeek(): int
    {
        return Applicant::where('created_at', '>=', now()->startOfWeek())->count();
    }

    #[Computed]
    public function inPipeline(): int
    {
        return Applicant::whereNotIn('stage', ['hired', 'rejected'])->count();
    }

    #[Computed]
    public function hiredThisMonth(): int
    {
        return Applicant::where('stage', 'hired')
            ->where('hired_at', '>=', now()->startOfMonth())
            ->count();
    }

    #[Computed]
    public function recentApplicants()
    {
        return Applicant::with('jobListing')
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function activeListings()
    {
        return JobListing::active()
            ->withCount('applicants')
            ->latest()
            ->limit(5)
            ->get();
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Hiring Dashboard') }}</flux:heading>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="rectangle-stack" :href="route('hiring.listings')" wire:navigate>
                    {{ __('Job Listings') }}
                </flux:button>
            </div>
        </div>

        {{-- Stats Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-5 bg-white dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Applicants') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->totalApplicants }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-5 bg-white dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('New This Week') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->newThisWeek }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-5 bg-white dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('In Pipeline') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->inPipeline }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-5 bg-white dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Hired This Month') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->hiredThisMonth }}</flux:heading>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Recent Applicants --}}
            <div class="lg:col-span-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Recent Applicants') }}</flux:heading>
                        <flux:button variant="ghost" size="sm" :href="route('hiring.pipeline')" wire:navigate>
                            {{ __('View Pipeline') }}
                        </flux:button>
                    </div>

                    @if ($this->recentApplicants->count() > 0)
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->recentApplicants as $applicant)
                                <div wire:key="applicant-{{ $applicant->id }}" class="px-6 py-3 flex items-center justify-between">
                                    <div class="min-w-0">
                                        <flux:text class="font-medium truncate">{{ $applicant->getFullName() }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $applicant->jobListing?->title ?? __('No listing') }} &middot; {{ $applicant->created_at->diffForHumans() }}</flux:text>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0 ml-4">
                                        @php
                                            $stageColors = [
                                                'applied' => 'zinc',
                                                'screening' => 'blue',
                                                'phone_interview' => 'indigo',
                                                'in_person' => 'purple',
                                                'background_check' => 'yellow',
                                                'offer' => 'amber',
                                                'hired' => 'green',
                                                'rejected' => 'red',
                                            ];
                                        @endphp
                                        <flux:badge size="sm" color="{{ $stageColors[$applicant->stage] ?? 'zinc' }}">
                                            {{ Applicant::STAGE_LABELS[$applicant->stage] ?? ucfirst($applicant->stage) }}
                                        </flux:badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="p-8 text-center">
                            <flux:icon name="user-group" class="mx-auto h-10 w-10 text-zinc-400" />
                            <flux:text class="mt-2 text-zinc-500">{{ __('No applicants yet.') }}</flux:text>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Active Job Listings --}}
            <div>
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Active Listings') }}</flux:heading>
                        <flux:button variant="ghost" size="sm" :href="route('hiring.listings')" wire:navigate>
                            {{ __('View All') }}
                        </flux:button>
                    </div>

                    @if ($this->activeListings->count() > 0)
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->activeListings as $listing)
                                <div wire:key="listing-{{ $listing->id }}" class="px-6 py-3">
                                    <flux:text class="font-medium">{{ $listing->title }}</flux:text>
                                    <div class="flex items-center gap-3 mt-1">
                                        <flux:text class="text-sm text-zinc-500">{{ $listing->applicants_count }} {{ __('applicants') }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $listing->positions_filled }}/{{ $listing->positions_available }} {{ __('filled') }}</flux:text>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="p-8 text-center">
                            <flux:icon name="briefcase" class="mx-auto h-10 w-10 text-zinc-400" />
                            <flux:text class="mt-2 text-zinc-500">{{ __('No active listings.') }}</flux:text>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
