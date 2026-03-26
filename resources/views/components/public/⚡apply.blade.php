<?php

use App\Models\Applicant;
use App\Models\CompanySetting;
use App\Models\JobListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.guest')] class extends Component
{
    use WithFileUploads;

    public ?JobListing $jobListing = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public $resume = null;

    public string $cover_letter = '';

    public string $source = 'web';

    public bool $submitted = false;

    public function mount(?JobListing $jobListing = null): void
    {
        if ($jobListing?->exists) {
            if (! $jobListing->isActive()) {
                $this->redirect(route('careers'));

                return;
            }
            $this->jobListing = $jobListing;
        }

        $this->source = request()->query('source', 'web');
    }

    public function submit(): void
    {
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'resume' => ['nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
        ], [
            'first_name.required' => 'Please enter your first name.',
            'last_name.required' => 'Please enter your last name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'resume.max' => 'Resume must be under 5MB.',
            'resume.mimes' => 'Resume must be a PDF, DOC, or DOCX file.',
        ]);

        $resumePath = null;
        if ($this->resume) {
            $resumePath = $this->resume->store('resumes', config('filesystems.default'));
        }

        Applicant::create([
            'job_listing_id' => $this->jobListing?->id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'resume_path' => $resumePath,
            'cover_letter' => $validated['cover_letter'] ?? null,
            'source' => in_array($this->source, array_keys(Applicant::SOURCES)) ? $this->source : 'web',
            'stage' => 'applied',
        ]);

        $this->submitted = true;
    }

    #[Computed]
    public function companyName(): string
    {
        $settings = CompanySetting::first();

        return $settings?->company_name ?? config('app.name');
    }
}; ?>

@section('robots', 'noindex, nofollow')

<div>
    {{-- Nav Bar --}}
    <nav class="fixed top-0 inset-x-0 z-50 bg-white/80 backdrop-blur-md border-b border-zinc-200/60 dark:bg-zinc-900/80 dark:border-zinc-700/60">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 flex items-center justify-between h-12">
            <a href="/" class="text-sm font-bold text-zinc-900 dark:text-white tracking-tight">{{ $this->companyName }}</a>
            <a href="{{ route('careers') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">
                &larr; Back to Careers
            </a>
        </div>
    </nav>

    <div class="pt-20 pb-16 bg-zinc-50 dark:bg-zinc-900 min-h-screen">
        <div class="max-w-2xl mx-auto px-4 sm:px-6">

            @if($submitted)
                {{-- Success State --}}
                <div class="text-center py-16">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 dark:bg-green-900/40 mb-4">
                        <flux:icon.check-circle class="size-10 text-green-600 dark:text-green-400" />
                    </div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white mb-2">Application Submitted!</h1>
                    <p class="text-zinc-500 dark:text-zinc-400 mb-6 max-w-md mx-auto">Thank you for your interest in joining our team. We'll review your application and get back to you soon.</p>
                    <a href="{{ route('careers') }}" wire:navigate>
                        <flux:button variant="primary">Back to Careers</flux:button>
                    </a>
                </div>
            @else
                {{-- Header --}}
                <div class="mb-8">
                    @if($jobListing)
                        <flux:badge color="blue" size="sm" class="mb-3">{{ JobListing::EMPLOYMENT_TYPES[$jobListing->employment_type] ?? $jobListing->employment_type }}</flux:badge>
                        <h1 class="text-2xl sm:text-3xl font-bold text-zinc-900 dark:text-white">Apply: {{ $jobListing->title }}</h1>
                        @if($jobListing->location)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 flex items-center gap-1">
                                <flux:icon.map-pin class="size-4" />
                                {{ $jobListing->location }}
                            </p>
                        @endif
                        @if($jobListing->pay_range_min || $jobListing->pay_range_max)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 flex items-center gap-1">
                                <flux:icon.banknotes class="size-4" />
                                {{ $jobListing->getPayRangeDisplay() }}
                            </p>
                        @endif
                    @else
                        <h1 class="text-2xl sm:text-3xl font-bold text-zinc-900 dark:text-white">General Application</h1>
                        <p class="text-zinc-500 dark:text-zinc-400 mt-1">Tell us about yourself and we'll reach out when a matching position opens.</p>
                    @endif
                </div>

                {{-- Application Form --}}
                <form wire:submit="submit" class="space-y-6">
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 shadow-sm space-y-5">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Your Information</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>First Name *</flux:label>
                                <flux:input wire:model="first_name" placeholder="John" />
                                <flux:error name="first_name" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Last Name *</flux:label>
                                <flux:input wire:model="last_name" placeholder="Smith" />
                                <flux:error name="last_name" />
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Email *</flux:label>
                                <flux:input type="email" wire:model="email" placeholder="john@example.com" />
                                <flux:error name="email" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Phone</flux:label>
                                <flux:input type="tel" wire:model="phone" placeholder="(555) 123-4567" />
                                <flux:error name="phone" />
                            </flux:field>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 shadow-sm space-y-5">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Resume & Cover Letter</h2>

                        <flux:field>
                            <flux:label>Resume</flux:label>
                            <input
                                type="file"
                                wire:model="resume"
                                accept=".pdf,.doc,.docx"
                                class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                                    file:mr-4 file:py-2 file:px-4
                                    file:rounded-lg file:border-0
                                    file:text-sm file:font-semibold
                                    file:bg-blue-50 file:text-blue-700
                                    dark:file:bg-blue-900/40 dark:file:text-blue-400
                                    hover:file:bg-blue-100 dark:hover:file:bg-blue-900/60
                                    cursor-pointer"
                            />
                            <div class="text-xs text-zinc-400 dark:text-zinc-500 mt-1">PDF, DOC, or DOCX. Max 5MB.</div>
                            <flux:error name="resume" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Cover Letter</flux:label>
                            <flux:textarea wire:model="cover_letter" rows="5" placeholder="Tell us why you'd be a great fit..." />
                            <flux:error name="cover_letter" />
                        </flux:field>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('careers') }}" wire:navigate>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </a>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="submit">Submit Application</span>
                            <span wire:loading wire:target="submit">Submitting...</span>
                        </flux:button>
                    </div>
                </form>

                {{-- Job Description (if specific listing) --}}
                @if($jobListing)
                    <div class="mt-10 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-3">About This Position</h2>
                        <div class="prose prose-sm dark:prose-invert max-w-none text-zinc-600 dark:text-zinc-300">
                            {!! nl2br(e($jobListing->description)) !!}
                        </div>
                        @if($jobListing->requirements)
                            <h3 class="text-base font-semibold text-zinc-900 dark:text-white mt-6 mb-2">Requirements</h3>
                            <div class="prose prose-sm dark:prose-invert max-w-none text-zinc-600 dark:text-zinc-300">
                                {!! nl2br(e($jobListing->requirements)) !!}
                            </div>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
