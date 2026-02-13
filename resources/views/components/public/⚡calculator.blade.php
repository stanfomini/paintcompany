<?php

use App\Models\Activity;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $agentEmail = '';

    public string $agentName = '';

    public string $agentCompany = '';

    public string $agentPhone = '';

    public ?string $agentMeetingDate = null;

    public ?string $agentMeetingTime = null;

    public bool $showAgentModal = false;

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

        $firstInternal = User::where('type', 'internal')->first();

        $meetingDateTime = null;
        if ($this->agentMeetingDate) {
            $timeStr = $this->agentMeetingTime ?: '09:00';
            $meetingDateTime = Carbon::parse($this->agentMeetingDate . ' ' . $timeStr);
        }

        if ($firstInternal) {
            Activity::create([
                'user_id' => $firstInternal->id,
                'type' => $this->agentMeetingDate ? 'meeting' : 'task',
                'title' => "New Agent: {$this->agentName} from {$this->agentCompany}",
                'description' => "Company: {$this->agentCompany}\nPhone: {$this->agentPhone}\nMeeting: " . ($meetingDateTime ? $meetingDateTime->format('M j, Y g:i A') : 'Not scheduled'),
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

        try {
            $invitationService = app(InvitationService::class);

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
            \Illuminate\Support\Facades\Log::warning('Agent auto-invite failed: ' . $e->getMessage());
        }

        $this->redirect(route('next-steps'));
    }
}; ?>

<div>
    {{-- Hero Section --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-blue-50 to-white py-16 sm:py-24">
        <div class="absolute inset-0 pointer-events-none" style="background-image: radial-gradient(circle at 20% 50%, rgba(59, 130, 246, 0.06) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(16, 185, 129, 0.06) 0%, transparent 50%);"></div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12 sm:mb-16">
                <flux:badge color="blue" class="mb-4">{{ __('Free for Agents') }}</flux:badge>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-zinc-900 tracking-tight">
                    {{ __('Property Value') }} <span class="text-blue-600">{{ __('Calculator') }}</span>
                </h1>
                <p class="mt-4 text-lg sm:text-xl text-zinc-600 max-w-2xl mx-auto">
                    {{ __('Show your clients exactly how a professional paint job increases their home\'s value. Data-backed ROI estimates in seconds.') }}
                </p>
            </div>

            {{-- Interactive Preview Mockup --}}
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-2xl shadow-2xl border border-zinc-200 overflow-hidden">
                    {{-- Mockup Header --}}
                    <div class="bg-zinc-900 px-6 py-3 flex items-center gap-2">
                        <div class="flex gap-1.5">
                            <div class="w-3 h-3 rounded-full bg-red-400"></div>
                            <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                            <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                        </div>
                        <div class="flex-1 text-center">
                            <span class="text-xs text-zinc-400 font-mono">{{ __('Agent Dashboard') }} &mdash; {{ __('ROI Calculator') }}</span>
                        </div>
                    </div>

                    {{-- Mockup Content --}}
                    <div class="p-6 sm:p-8">
                        {{-- Example Property Input Display --}}
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                            <div class="bg-zinc-50 rounded-xl p-4 text-center">
                                <p class="text-xs text-zinc-500 mb-1">{{ __('Property Value') }}</p>
                                <p class="text-lg font-bold text-zinc-900">$450,000</p>
                            </div>
                            <div class="bg-zinc-50 rounded-xl p-4 text-center">
                                <p class="text-xs text-zinc-500 mb-1">{{ __('Square Feet') }}</p>
                                <p class="text-lg font-bold text-zinc-900">2,200</p>
                            </div>
                            <div class="bg-zinc-50 rounded-xl p-4 text-center">
                                <p class="text-xs text-zinc-500 mb-1">{{ __('Bedrooms') }}</p>
                                <p class="text-lg font-bold text-zinc-900">3</p>
                            </div>
                            <div class="bg-zinc-50 rounded-xl p-4 text-center">
                                <p class="text-xs text-zinc-500 mb-1">{{ __('Scope') }}</p>
                                <p class="text-lg font-bold text-zinc-900">{{ __('Interior') }}</p>
                            </div>
                        </div>

                        {{-- Example Results --}}
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                            <div class="bg-blue-50 border border-blue-100 rounded-xl p-5 text-center">
                                <p class="text-xs font-semibold text-blue-600 mb-1 uppercase tracking-wide">{{ __('Estimated Cost') }}</p>
                                <p class="text-2xl sm:text-3xl font-extrabold text-zinc-900">$4,200 &ndash; $5,700</p>
                                <p class="text-xs text-zinc-500 mt-1">{{ __('Professional interior painting') }}</p>
                            </div>
                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-5 text-center">
                                <p class="text-xs font-semibold text-emerald-600 mb-1 uppercase tracking-wide">{{ __('Value Increase') }}</p>
                                <p class="text-2xl sm:text-3xl font-extrabold text-emerald-700">$4,500 &ndash; $6,100</p>
                                <p class="text-xs text-zinc-500 mt-1">{{ __('Based on industry research') }}</p>
                            </div>
                            <div class="bg-purple-50 border border-purple-100 rounded-xl p-5 text-center">
                                <p class="text-xs font-semibold text-purple-600 mb-1 uppercase tracking-wide">{{ __('Return on Investment') }}</p>
                                <p class="text-2xl sm:text-3xl font-extrabold text-purple-700">107% &ndash; 145%</p>
                                <p class="text-xs text-zinc-500 mt-1">{{ __('Every $1 spent returns $1.07+') }}</p>
                            </div>
                        </div>

                        {{-- Example Value Bar --}}
                        <div class="bg-zinc-50 rounded-xl p-5">
                            <p class="text-sm font-semibold text-zinc-700 mb-3">{{ __('Before & After Value') }}</p>
                            <div class="space-y-3">
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-zinc-500">{{ __('Current Value') }}</span>
                                        <span class="font-semibold text-zinc-700">$450,000</span>
                                    </div>
                                    <div class="w-full bg-zinc-200 rounded-full h-3">
                                        <div class="bg-zinc-400 h-3 rounded-full" style="width: 100%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-emerald-600 font-medium">{{ __('After Painting') }}</span>
                                        <span class="font-semibold text-emerald-600">$456,100</span>
                                    </div>
                                    <div class="w-full bg-zinc-200 rounded-full h-3">
                                        <div class="bg-emerald-500 h-3 rounded-full" style="width: 100%"></div>
                                    </div>
                                </div>
                            </div>
                            <p class="text-center text-sm font-medium text-emerald-600 mt-3">{{ __(':amount estimated increase', ['amount' => '+$6,100']) }}</p>
                        </div>
                    </div>
                </div>
            </div>

                <p class="text-xs text-zinc-400 text-center mt-6 max-w-xl mx-auto">
                    {{ __('Estimates vary by area, property condition, and listing strategy. Contact us for a personalized estimate.') }}
                </p>
        </div>
    </section>

    {{-- How It Works --}}
    <section class="py-16 sm:py-20 bg-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900">{{ __('How It Works') }}</h2>
                <p class="text-lg text-zinc-600 mt-3 max-w-2xl mx-auto">{{ __('Three steps to data-backed painting ROI for your clients.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-100 text-blue-600 mb-4">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    </div>
                    <div class="text-sm font-bold text-blue-600 mb-1">{{ __('Step 1') }}</div>
                    <h3 class="text-lg font-bold text-zinc-900 mb-2">{{ __('Add Your Listing') }}</h3>
                    <p class="text-zinc-600">{{ __('Enter the property address, value, sqft, and bedrooms right from your dashboard.') }}</p>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-emerald-100 text-emerald-600 mb-4">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    </div>
                    <div class="text-sm font-bold text-emerald-600 mb-1">{{ __('Step 2') }}</div>
                    <h3 class="text-lg font-bold text-zinc-900 mb-2">{{ __('Get Instant ROI') }}</h3>
                    <p class="text-zinc-600">{{ __('See how painting impacts property value with industry-backed data. Cost ranges, value increase, and ROI percentages.') }}</p>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-amber-100 text-amber-600 mb-4">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>
                    </div>
                    <div class="text-sm font-bold text-amber-600 mb-1">{{ __('Step 3') }}</div>
                    <h3 class="text-lg font-bold text-zinc-900 mb-2">{{ __('Share with Clients') }}</h3>
                    <p class="text-zinc-600">{{ __('Send a branded ROI report to your client with one click. They see exactly how painting pays for itself.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Selling vs Buying --}}
    <section class="py-16 sm:py-20 bg-zinc-50">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900">{{ __('Works for Sellers & Buyers') }}</h2>
                <p class="text-lg text-zinc-600 mt-3 max-w-2xl mx-auto">{{ __('Different messaging, same powerful data.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                {{-- Selling Card --}}
                <div class="bg-white rounded-2xl p-8 border border-emerald-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" /></svg>
                        </div>
                        <h3 class="text-xl font-bold text-zinc-900">{{ __('Selling a Home') }}</h3>
                    </div>
                    <p class="text-zinc-600 mb-4">Your client sees: &ldquo;A paint refresh could increase your listing price by $4,500&ndash;$6,100.&rdquo;</p>
                    <ul class="space-y-2">
                        <li class="flex items-start gap-2 text-sm text-zinc-600">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ __('Highest-ROI pre-listing improvement') }}
                        </li>
                        <li class="flex items-start gap-2 text-sm text-zinc-600">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ __('Helps justify listing price to buyers') }}
                        </li>
                        <li class="flex items-start gap-2 text-sm text-zinc-600">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            2&ndash;5% home value increase (exterior)
                        </li>
                    </ul>
                </div>

                {{-- Buying Card --}}
                <div class="bg-white rounded-2xl p-8 border border-blue-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-blue-100 text-blue-600">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        <h3 class="text-xl font-bold text-zinc-900">{{ __('Buying a Home') }}</h3>
                    </div>
                    <p class="text-zinc-600 mb-4">Your client sees: &ldquo;Budget $4,200&ndash;$5,700 for painting to bring this home to its full potential.&rdquo;</p>
                    <ul class="space-y-2">
                        <li class="flex items-start gap-2 text-sm text-zinc-600">
                            <svg class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ __('Accurate move-in budget planning') }}
                        </li>
                        <li class="flex items-start gap-2 text-sm text-zinc-600">
                            <svg class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ __('Shows you\'ve thought of everything') }}
                        </li>
                        <li class="flex items-start gap-2 text-sm text-zinc-600">
                            <svg class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ __('Negotiation leverage for repair credits') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA Section --}}
    <section class="py-16 sm:py-24 bg-gradient-to-b from-white to-blue-50">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Ready to Impress Your Clients?') }}</h2>
            <p class="text-lg text-zinc-600 mb-8 max-w-xl mx-auto">
                {{ __('Join hundreds of Denver agents who use our free calculator to win more listings and delight buyers.') }}
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <flux:button variant="primary" wire:click="$set('showAgentModal', true)" class="w-full sm:w-auto px-8 py-4 text-lg">
                    {{ __('Join Our Agent Network') }}
                </flux:button>
                <a href="{{ route('home') }}#agent-section" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-semibold transition-colors">
                    {{ __('Learn More') }}
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                </a>
            </div>
            <p class="text-sm text-zinc-500 mt-6">{{ __('No credit card required. Free forever for agents.') }}</p>
        </div>
    </section>

    {{-- Agent Signup Modal --}}
    @if ($showAgentModal)
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
</div>
