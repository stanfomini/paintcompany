<?php

use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentPassedEstimate;
use App\Services\InvitationService;
use App\Services\PropertyValueCalculatorService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.agent', ['title' => 'Dashboard'])] class extends Component {
    public ?int $selectedDealId = null;

    public ?float $roiPropertyValue = null;
    public ?int $roiSqft = null;
    public ?int $roiBedrooms = null;
    public string $roiScope = 'interior';
    public string $roiIntent = 'selling';
    public ?array $roiResults = null;

    public bool $showPassModal = false;
    public string $passEmail = '';
    public string $passFirstName = '';
    public string $passLastName = '';

    public function mount(): void
    {
        $firstDeal = $this->agentDeals->first();

        if ($firstDeal) {
            $this->selectedDealId = $firstDeal->id;
        }
    }

    public function selectDeal(int $id): void
    {
        $deal = $this->agentDeals->firstWhere('id', $id);

        if ($deal) {
            $this->selectedDealId = $deal->id;
            $this->roiResults = null;
            unset($this->selectedDeal, $this->selectedEstimate, $this->actionItems);
        }
    }

    public function calculateRoi(): void
    {
        $this->validate([
            'roiPropertyValue' => ['required', 'numeric', 'min:1'],
            'roiSqft' => ['required', 'integer', 'min:1'],
            'roiBedrooms' => ['required', 'integer', 'min:1', 'max:8'],
            'roiScope' => ['required', 'in:interior,exterior,both'],
            'roiIntent' => ['required', 'in:selling,buying'],
        ]);

        $service = app(PropertyValueCalculatorService::class);
        $this->roiResults = $service->calculate(
            $this->roiPropertyValue,
            $this->roiSqft,
            $this->roiBedrooms,
            $this->roiScope,
            $this->roiIntent
        );
    }

    public function populateFromListing(): void
    {
        $deal = $this->selectedDeal;

        if (! $deal) {
            return;
        }

        $this->roiPropertyValue = $deal->listing_value ? (float) $deal->listing_value : ($deal->property?->listing_price ? (float) $deal->property->listing_price : null);
        $this->roiSqft = $deal->listing_sqft ?? $deal->property?->square_footage;
        $this->roiBedrooms = $deal->listing_bedrooms ?? 3;
        $this->roiScope = $deal->roi_scope ?? 'interior';
        $this->roiIntent = $deal->agent_intent ?? 'selling';
        $this->roiResults = null;
    }

    #[On('openPassToCustomer')]
    public function openPassModal(int $dealId): void
    {
        $deal = Deal::query()
            ->forAgent(auth('agent')->user())
            ->where('id', $dealId)
            ->first();

        if (! $deal || $deal->customer_id) {
            return;
        }

        $this->selectedDealId = $dealId;
        $this->reset('passEmail', 'passFirstName', 'passLastName');
        $this->resetValidation();
        $this->showPassModal = true;
    }

    public function passToCustomer(): void
    {
        $this->validate([
            'passEmail' => ['required', 'email'],
            'passFirstName' => ['required', 'string', 'max:255'],
            'passLastName' => ['required', 'string', 'max:255'],
        ]);

        $deal = Deal::query()
            ->forAgent(auth('agent')->user())
            ->where('id', $this->selectedDealId)
            ->first();

        if (! $deal) {
            return;
        }

        if ($deal->customer_id) {
            $this->addError('passEmail', __('This deal already has a customer assigned.'));

            return;
        }

        $agent = auth('agent')->user();

        // Check if a customer user already exists with this email
        $existingUser = User::where('email', $this->passEmail)->where('type', 'customer')->first();

        if ($existingUser) {
            // Existing customer - assign deal directly
            $customer = Customer::where('user_id', $existingUser->id)->first();

            if (! $customer) {
                $customer = Customer::create([
                    'user_id' => $existingUser->id,
                    'created_by' => $agent->id,
                    'first_name' => $this->passFirstName,
                    'last_name' => $this->passLastName,
                    'email' => $this->passEmail,
                    'referred_by' => $agent->id,
                    'status' => 'active',
                    'source' => 'agent',
                ]);
            }

            $deal->update(['customer_id' => $customer->id]);

            // Notify the existing customer user
            $existingUser->notify(new AgentPassedEstimate($deal, $agent));
        } else {
            // Check if a non-customer user exists with this email
            $nonCustomerUser = User::where('email', $this->passEmail)->first();

            if ($nonCustomerUser) {
                $this->addError('passEmail', __('A user with this email already exists with a different account type.'));

                return;
            }

            // New customer - create Customer record and send invitation
            $customer = Customer::create([
                'created_by' => $agent->id,
                'first_name' => $this->passFirstName,
                'last_name' => $this->passLastName,
                'email' => $this->passEmail,
                'referred_by' => $agent->id,
                'status' => 'active',
                'source' => 'agent',
            ]);

            $deal->update(['customer_id' => $customer->id]);

            // Send invitation via InvitationService
            $invitationService = app(InvitationService::class);
            $invitationService->sendInvitation($agent, $this->passEmail, 'customer', dealId: $deal->id);
        }

        $this->showPassModal = false;
        $this->reset('passEmail', 'passFirstName', 'passLastName');
        unset($this->agentDeals, $this->selectedDeal, $this->selectedEstimate, $this->actionItems);
        $this->dispatch('customer-assigned');
    }

    public function markNotInterested(int $dealId): void
    {
        $deal = Deal::query()
            ->forAgent(auth('agent')->user())
            ->where('id', $dealId)
            ->first();

        if (! $deal) {
            return;
        }

        if (in_array($deal->stage, Deal::PAST_DEAL_STAGES)) {
            return;
        }

        $deal->moveTo('lost');

        // Create activity for internal team
        $firstInternal = User::where('type', 'internal')->first();
        if ($firstInternal) {
            \App\Models\Activity::create([
                'user_id' => $firstInternal->id,
                'subject_type' => Deal::class,
                'subject_id' => $deal->id,
                'type' => 'note',
                'title' => 'Agent marked listing as not interested: ' . ($deal->property?->address ?? $deal->title),
            ]);
        }

        // Clear computed caches
        unset($this->agentDeals, $this->activeDeals, $this->pastDeals, $this->selectedDeal, $this->selectedEstimate, $this->actionItems);

        // Auto-select first remaining active deal
        $firstActive = $this->activeDeals->first();
        $this->selectedDealId = $firstActive?->id;
    }

    public function startVideoCall(?int $dealId = null): void
    {
        $videoCall = \App\Models\VideoCall::create([
            'callable_type' => \App\Models\Deal::class,
            'callable_id' => $dealId,
            'initiated_by' => auth('agent')->id(),
            'room_id' => 'call-'.\Illuminate\Support\Str::uuid(),
            'status' => 'pending',
        ]);

        $this->redirect(route('video-call.test', $videoCall->room_id));
    }

    #[Computed]
    public function agentDeals()
    {
        return Deal::query()
            ->forAgent(auth('agent')->user())
            ->with(['property', 'estimates', 'chatThread'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function pastDeals()
    {
        return $this->agentDeals->filter(fn (Deal $d) => in_array($d->stage, Deal::PAST_DEAL_STAGES));
    }

    #[Computed]
    public function activeDeals()
    {
        return $this->agentDeals->reject(fn (Deal $d) => in_array($d->stage, Deal::PAST_DEAL_STAGES));
    }

    #[Computed]
    public function selectedDeal(): ?Deal
    {
        if (! $this->selectedDealId) {
            return null;
        }

        return $this->agentDeals->firstWhere('id', $this->selectedDealId);
    }

    #[Computed]
    public function selectedEstimate(): ?Estimate
    {
        $deal = $this->selectedDeal;

        if (! $deal) {
            return null;
        }

        return $deal->estimates->first();
    }

    /**
     * Action items for the selected deal (mirrors customer dashboard pattern).
     *
     * @return array<int, array{type: string, icon: string, text: string, action: string|null}>
     */
    #[Computed]
    public function actionItems(): array
    {
        $deal = $this->selectedDeal;

        if (! $deal) {
            return [];
        }

        $items = [];
        $estimate = $this->selectedEstimate;

        // Estimate is ready but not yet passed to customer
        if ($estimate && $estimate->status === 'sent' && ! $deal->customer_id) {
            $items[] = [
                'type' => 'pass_to_customer',
                'icon' => 'user-plus',
                'text' => __('Estimate is ready! Pass it to your client.'),
                'action' => 'pass',
            ];
        }

        // Estimate is still being prepared (draft)
        if ($estimate && $estimate->status === 'draft') {
            $items[] = [
                'type' => 'estimate_in_progress',
                'icon' => 'clock',
                'text' => __('Our team is preparing your estimate.'),
                'action' => null,
            ];
        }

        // No estimate yet
        if (! $estimate) {
            $items[] = [
                'type' => 'awaiting_estimate',
                'icon' => 'document-text',
                'text' => __('Your listing has been received. An estimate will be created soon.'),
                'action' => null,
            ];
        }

        // Customer accepted estimate
        if ($estimate && $estimate->status === 'accepted') {
            $items[] = [
                'type' => 'estimate_accepted',
                'icon' => 'check-circle',
                'text' => __('Your client accepted the estimate! Project is underway.'),
                'action' => null,
            ];
        }

        return $items;
    }

    /**
     * Agent-friendly status for the deal timeline.
     */
    public function dealStatusForTimeline(Deal $deal): array
    {
        $estimate = $deal->estimates->first();

        // Stage 1: Lead/Estimate
        $estimateStatus = 'future';
        $estimateLabel = __('Pending');
        if ($estimate) {
            if (in_array($estimate->status, ['accepted', 'sent'])) {
                $estimateStatus = $estimate->status === 'accepted' ? 'completed' : 'attention';
                $estimateLabel = $estimate->status === 'accepted' ? __('Accepted') : __('Ready');
            } else {
                $estimateStatus = 'in_progress';
                $estimateLabel = __('In progress');
            }
        }

        // Stage 2: Customer
        $customerStatus = 'future';
        $customerLabel = __('Not assigned');
        if ($deal->customer_id) {
            $customerStatus = 'completed';
            $customerLabel = __('Assigned');
        } elseif ($estimate && $estimate->status === 'sent') {
            $customerStatus = 'attention';
            $customerLabel = __('Ready to send');
        }

        // Stage 3: Active/Complete
        $projectStatus = 'future';
        $projectLabel = __('Upcoming');
        if (in_array($deal->stage, ['signed', 'completed'])) {
            $projectStatus = $deal->stage === 'completed' ? 'completed' : 'in_progress';
            $projectLabel = $deal->stage === 'completed' ? __('Completed') : __('Active');
        }

        return compact('estimateStatus', 'estimateLabel', 'customerStatus', 'customerLabel', 'projectStatus', 'projectLabel');
    }

    #[Computed]
    public function totalListings(): int
    {
        return Property::query()->forAgent(auth('agent')->user())->count();
    }

    #[Computed]
    public function activeListings(): int
    {
        return $this->activeDeals->count();
    }

    #[Computed]
    public function referredCustomers(): int
    {
        return Customer::query()->referredByAgent(auth('agent')->user())->count();
    }

    #[Computed]
    public function performanceMetrics(): array
    {
        return $this->calculatePerformanceMetrics(auth('agent')->user());
    }

    #[Computed]
    public function monthlyDealCounts(): array
    {
        return $this->getMonthlyDealCounts(auth('agent')->user());
    }

    /**
     * @return array{conversion_rate: float, average_deal_value: float, total_referral_value: float}
     */
    public function calculatePerformanceMetrics($agent): array
    {
        $totalDeals = Deal::query()->forAgent($agent)->count();
        $wonDeals = Deal::query()->forAgent($agent)->won()->count();

        $conversionRate = $totalDeals > 0 ? ($wonDeals / $totalDeals) * 100 : 0;

        $avgDealValue = Deal::query()
            ->forAgent($agent)
            ->avg(DB::raw('COALESCE(actual_value, estimated_value, 0)')) ?? 0;

        $totalReferralValue = Deal::query()
            ->forAgent($agent)
            ->sum(DB::raw('COALESCE(estimated_value, 0)'));

        return [
            'conversion_rate' => round((float) $conversionRate, 1),
            'average_deal_value' => (float) $avgDealValue,
            'total_referral_value' => (float) $totalReferralValue,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getMonthlyDealCounts($agent): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthKey = $date->format('M Y');

            $count = Deal::query()
                ->forAgent($agent)
                ->whereMonth('created_at', $date->month)
                ->whereYear('created_at', $date->year)
                ->count();

            $months[$monthKey] = $count;
        }

        return $months;
    }

    private function stageBadgeColor(string $stage): string
    {
        return match ($stage) {
            'lead' => 'blue',
            'not_contacted' => 'zinc',
            'follow_up' => 'yellow',
            'quote_scheduled' => 'purple',
            'quoted' => 'indigo',
            'signed' => 'emerald',
            'completed' => 'green',
            'lost' => 'red',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }

    #[Computed]
    public function upcomingMeetings(): \Illuminate\Database\Eloquent\Collection
    {
        $agent = auth('agent')->user();
        $dealIds = Deal::query()->forAgent($agent)->pluck('id');

        return Activity::query()
            ->where('subject_type', Deal::class)
            ->whereIn('subject_id', $dealIds)
            ->whereNotNull('meeting_type')
            ->whereIn('meeting_status', ['proposed', 'confirmed'])
            ->where('due_at', '>=', now())
            ->with(['subject.property', 'subject.customer', 'proposedBy', 'videoCall'])
            ->orderBy('due_at')
            ->limit(5)
            ->get();
    }
}; ?>

<div class="overflow-x-hidden">
    <div class="space-y-4 sm:space-y-6">
        {{-- Header --}}
        <div>
            <flux:heading size="xl">{{ __('Welcome back, :name!', ['name' => auth('agent')->user()->name]) }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Your listings and estimates hub') }}
            </flux:text>
        </div>

        @if ($this->agentDeals->isEmpty())
            {{-- Empty State --}}
            <flux:callout variant="info">
                <flux:callout.heading>{{ __('Welcome to Your Dashboard') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Add your first listing to get started. We\'ll create an estimate, you can calculate ROI for clients, and pass estimates to your customers.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Ready to Get Started?') }}</flux:heading>
                        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                            {{ __('Add a property listing and we\'ll prepare a free estimate for your client.') }}
                        </flux:text>
                    </div>
                    <flux:button variant="primary" icon="plus" wire:click="$dispatch('openCreateModal')" class="w-full sm:w-auto">
                        {{ __('Add Your First Listing') }}
                    </flux:button>
                </div>
            </flux:card>
        @else
            {{-- Listing Switcher (mirrors customer project switcher) --}}
            <flux:card class="!p-3 overflow-hidden">
                {{-- Mobile: native select --}}
                <div class="sm:hidden">
                    <div class="flex items-center gap-2 w-full">
                        <div class="min-w-0 flex-1">
                            <select
                                wire:change="selectDeal($event.target.value)"
                                class="w-full truncate rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                @foreach ($this->activeDeals as $deal)
                                    <option
                                        wire:key="deal-mobile-{{ $deal->id }}"
                                        value="{{ $deal->id }}"
                                        {{ $deal->id === $this->selectedDealId ? 'selected' : '' }}
                                    >{{ $deal->property?->address ?? $deal->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <flux:button wire:click="$dispatch('openCreateModal')" variant="ghost" size="sm" icon="plus" class="shrink-0" />
                    </div>

                    @if ($this->selectedDeal)
                        <div class="mt-2 flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/50">
                            <span class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $this->selectedDeal->title }}</span>
                            <button wire:click="markNotInterested({{ $this->selectedDeal->id }})"
                                wire:confirm="{{ __('Remove this listing? It will move to Past Listings.') }}"
                                class="ml-2 shrink-0 text-zinc-400 hover:text-red-500 transition-colors"
                                title="{{ __('Not Interested') }}">
                                <flux:icon name="x-mark" class="w-4 h-4" />
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Desktop: horizontal buttons --}}
                <div class="hidden sm:flex sm:flex-wrap sm:items-center sm:gap-2">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mr-1">{{ __('Listings:') }}</flux:text>

                    @foreach ($this->activeDeals as $deal)
                        @php
                            $isSelected = $deal->id === $this->selectedDealId;
                        @endphp
                        <button
                            wire:key="deal-switcher-{{ $deal->id }}"
                            wire:click="selectDeal({{ $deal->id }})"
                            class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors {{ $isSelected ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950' : 'border-zinc-200 bg-white hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600' }}"
                        >
                            <div class="text-left min-w-0">
                                <div class="font-medium truncate {{ $isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-900 dark:text-zinc-100' }}">
                                    {{ $deal->property?->address ?? $deal->title }}
                                </div>
                            </div>
                            <button wire:click.stop="markNotInterested({{ $deal->id }})"
                                wire:confirm="{{ __('Remove this listing? It will move to Past Listings.') }}"
                                class="text-zinc-400 hover:text-red-500 transition-colors"
                                title="{{ __('Not Interested') }}">
                                <flux:icon name="x-mark" class="w-3.5 h-3.5" />
                            </button>
                        </button>
                    @endforeach

                    <flux:button wire:click="$dispatch('openCreateModal')" variant="ghost" size="sm" icon="plus">
                        {{ __('Add Listing') }}
                    </flux:button>

                    @if ($this->pastDeals->isNotEmpty())
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="clock">
                                {{ __('Past') }}
                                <flux:badge size="sm" color="zinc" class="ml-1">{{ $this->pastDeals->count() }}</flux:badge>
                            </flux:button>

                            <flux:menu class="w-80">
                                @foreach ($this->pastDeals as $pastDeal)
                                    <flux:menu.item wire:key="past-{{ $pastDeal->id }}" wire:click="selectDeal({{ $pastDeal->id }})">
                                        <div class="flex items-center justify-between w-full">
                                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $pastDeal->property?->address ?? $pastDeal->title }}</span>
                                            <flux:badge size="sm" color="{{ $this->stageBadgeColor($pastDeal->stage) }}" class="ml-2 shrink-0">
                                                {{ Deal::STAGES[$pastDeal->stage] ?? ucfirst($pastDeal->stage) }}
                                            </flux:badge>
                                        </div>
                                    </flux:menu.item>
                                    @if (! $loop->last)
                                        <flux:separator />
                                    @endif
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            </flux:card>

            @if ($this->selectedDeal)
                @php
                    $timeline = $this->dealStatusForTimeline($this->selectedDeal);
                @endphp

                {{-- Status Timeline --}}
                <flux:card class="!px-2 sm:!px-6">
                    <div class="flex items-center justify-between gap-2 sm:gap-4">
                        {{-- Estimate Stage --}}
                        <div class="flex flex-1 flex-col items-center text-center">
                            @php
                                $estColor = match($timeline['estimateStatus']) {
                                    'completed' => 'text-green-500 dark:text-green-400',
                                    'in_progress' => 'text-blue-500 dark:text-blue-400',
                                    'attention' => 'text-amber-500 dark:text-amber-400',
                                    default => 'text-zinc-300 dark:text-zinc-600',
                                };
                                $estBg = match($timeline['estimateStatus']) {
                                    'completed' => 'bg-green-100 dark:bg-green-900',
                                    'in_progress' => 'bg-blue-100 dark:bg-blue-900',
                                    'attention' => 'bg-amber-100 dark:bg-amber-900',
                                    default => 'bg-zinc-100 dark:bg-zinc-800',
                                };
                            @endphp
                            <div class="rounded-full p-2 sm:p-3 {{ $estBg }}">
                                <flux:icon name="document-text" class="h-5 w-5 sm:h-6 sm:w-6 {{ $estColor }}" />
                            </div>
                            <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Estimate') }}</flux:text>
                            <flux:text class="text-[10px] sm:text-xs text-zinc-500 dark:text-zinc-400">{{ $timeline['estimateLabel'] }}</flux:text>
                        </div>

                        {{-- Connector --}}
                        <div class="h-0.5 flex-1 max-w-8 sm:max-w-12 {{ $timeline['estimateStatus'] === 'completed' ? 'bg-green-300 dark:bg-green-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>

                        {{-- Customer Stage --}}
                        <div class="flex flex-1 flex-col items-center text-center">
                            @php
                                $custColor = match($timeline['customerStatus']) {
                                    'completed' => 'text-green-500 dark:text-green-400',
                                    'attention' => 'text-amber-500 dark:text-amber-400',
                                    default => 'text-zinc-300 dark:text-zinc-600',
                                };
                                $custBg = match($timeline['customerStatus']) {
                                    'completed' => 'bg-green-100 dark:bg-green-900',
                                    'attention' => 'bg-amber-100 dark:bg-amber-900',
                                    default => 'bg-zinc-100 dark:bg-zinc-800',
                                };
                            @endphp
                            <div class="rounded-full p-2 sm:p-3 {{ $custBg }}">
                                <flux:icon name="user-plus" class="h-5 w-5 sm:h-6 sm:w-6 {{ $custColor }}" />
                            </div>
                            <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Customer') }}</flux:text>
                            <flux:text class="text-[10px] sm:text-xs text-zinc-500 dark:text-zinc-400">{{ $timeline['customerLabel'] }}</flux:text>
                        </div>

                        {{-- Connector --}}
                        <div class="h-0.5 flex-1 max-w-8 sm:max-w-12 {{ $timeline['customerStatus'] === 'completed' ? 'bg-green-300 dark:bg-green-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>

                        {{-- Project Stage --}}
                        <div class="flex flex-1 flex-col items-center text-center">
                            @php
                                $projColor = match($timeline['projectStatus']) {
                                    'completed' => 'text-green-500 dark:text-green-400',
                                    'in_progress' => 'text-blue-500 dark:text-blue-400',
                                    default => 'text-zinc-300 dark:text-zinc-600',
                                };
                                $projBg = match($timeline['projectStatus']) {
                                    'completed' => 'bg-green-100 dark:bg-green-900',
                                    'in_progress' => 'bg-blue-100 dark:bg-blue-900',
                                    default => 'bg-zinc-100 dark:bg-zinc-800',
                                };
                            @endphp
                            <div class="rounded-full p-2 sm:p-3 {{ $projBg }}">
                                <flux:icon name="paint-brush" class="h-5 w-5 sm:h-6 sm:w-6 {{ $projColor }}" />
                            </div>
                            <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Project') }}</flux:text>
                            <flux:text class="text-[10px] sm:text-xs text-zinc-500 dark:text-zinc-400">{{ $timeline['projectLabel'] }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                {{-- Action Items Banner --}}
                @if (count($this->actionItems) > 0)
                    <flux:callout variant="warning">
                        <flux:callout.heading>{{ __('Action Needed') }}</flux:callout.heading>
                        <flux:callout.text>
                            <ul class="mt-2 space-y-3">
                                @foreach ($this->actionItems as $item)
                                    <li class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                        <span class="flex items-center gap-2 min-w-0">
                                            <flux:icon name="{{ $item['icon'] }}" class="h-4 w-4 shrink-0" />
                                            <span class="text-sm">{{ $item['text'] }}</span>
                                        </span>
                                        @if ($item['action'] === 'pass')
                                            <flux:button variant="primary" size="sm"
                                                wire:click="$dispatch('openPassToCustomer', { dealId: {{ $this->selectedDealId }} })"
                                                class="w-full sm:w-auto shrink-0">
                                                {{ __('Pass to Customer') }}
                                            </flux:button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Estimate Section --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">{{ __('Estimate') }}</flux:heading>

                    @if ($this->selectedEstimate)
                        @php
                            $estimate = $this->selectedEstimate;
                            $badgeColor = match($estimate->status) {
                                'draft' => 'zinc',
                                'sent' => 'yellow',
                                'accepted' => 'green',
                                'rejected' => 'red',
                                default => 'zinc',
                            };
                        @endphp
                        <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-4">
                            <div class="flex items-center justify-between gap-2 mb-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <flux:icon name="document-text" class="h-5 w-5 shrink-0 text-zinc-400" />
                                    <span class="font-medium truncate">{{ $estimate->estimate_number }}</span>
                                </div>
                                <flux:badge size="sm" color="{{ $badgeColor }}" class="shrink-0">
                                    {{ $estimate->status_label ?? ucfirst($estimate->status) }}
                                </flux:badge>
                            </div>

                            @if ($estimate->status === 'draft')
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('Our team is preparing your estimate. You\'ll be notified when it\'s ready.') }}
                                </flux:text>
                            @elseif ($estimate->status === 'sent')
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    @if ((float) ($estimate->total ?? 0) > 0)
                                        <flux:text class="text-lg font-semibold">${{ number_format($estimate->total, 2) }}</flux:text>
                                    @endif
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        @if ($estimate->public_token)
                                            <flux:button variant="ghost" size="sm" icon="arrow-top-right-on-square"
                                                href="{{ route('estimates.public', $estimate->public_token) }}" target="_blank">
                                                {{ __('View Proposal') }}
                                            </flux:button>
                                        @endif
                                        @if (! $this->selectedDeal->customer_id)
                                            <flux:button variant="primary" size="sm" icon="user-plus"
                                                wire:click="$dispatch('openPassToCustomer', { dealId: {{ $this->selectedDealId }} })">
                                                {{ __('Pass to Customer') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            @elseif ($estimate->status === 'accepted')
                                <div class="flex items-center gap-2">
                                    <flux:icon name="check-circle" class="h-5 w-5 text-emerald-500" />
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400 font-medium">
                                        {{ __('Your client accepted the estimate!') }}
                                    </flux:text>
                                </div>
                                @if ((float) ($estimate->total ?? 0) > 0)
                                    <flux:text class="text-lg font-semibold mt-2">${{ number_format($estimate->total, 2) }}</flux:text>
                                @endif
                            @endif

                            <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:button variant="ghost" size="sm" icon="eye"
                                    href="{{ route('agent.estimates.show', $estimate) }}" wire:navigate>
                                    {{ __('View & Contribute') }}
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                            <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('No estimate yet. Our team will create one soon.') }}</flux:text>
                        </div>
                    @endif
                </flux:card>

                {{-- Property Details & Contact --}}
                <div class="grid grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-2">
                    {{-- Property Info Card --}}
                    @if ($this->selectedDeal->property)
                        <flux:card>
                            <div class="flex items-start justify-between mb-4">
                                <flux:heading size="lg">{{ __('Property') }}</flux:heading>
                                @if ($this->selectedDeal->agent_intent)
                                    <flux:badge color="{{ $this->selectedDeal->agent_intent === 'buying' ? 'blue' : 'amber' }}">
                                        {{ $this->selectedDeal->agent_intent === 'buying' ? __('Buying') : __('Selling') }}
                                    </flux:badge>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 gap-3 sm:gap-4">
                                <div class="col-span-2">
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</flux:text>
                                    <flux:text class="font-medium break-words">{{ $this->selectedDeal->property->full_address }}</flux:text>
                                </div>
                                @if ($this->selectedDeal->listing_url)
                                    <div class="col-span-2">
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Listing Link') }}</flux:text>
                                        <a href="{{ $this->selectedDeal->listing_url }}" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 underline hover:no-underline break-all">
                                            {{ Str::limit($this->selectedDeal->listing_url, 60) }}
                                        </a>
                                    </div>
                                @endif
                                @if ($this->selectedDeal->listing_sqft ?? $this->selectedDeal->property->square_footage)
                                    <div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sqft') }}</flux:text>
                                        <flux:text class="font-medium">{{ number_format($this->selectedDeal->listing_sqft ?? $this->selectedDeal->property->square_footage) }}</flux:text>
                                    </div>
                                @endif
                                @if ($this->selectedDeal->listing_bedrooms)
                                    <div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Bedrooms') }}</flux:text>
                                        <flux:text class="font-medium">{{ $this->selectedDeal->listing_bedrooms }}</flux:text>
                                    </div>
                                @endif
                                @if ($this->selectedDeal->listing_value ?? $this->selectedDeal->property->listing_price)
                                    <div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Listing Price') }}</flux:text>
                                        <flux:text class="font-medium">${{ number_format($this->selectedDeal->listing_value ?? $this->selectedDeal->property->listing_price, 0) }}</flux:text>
                                    </div>
                                @endif
                            </div>
                        </flux:card>
                    @endif

                    {{-- Contact Card --}}
                    <flux:card>
                        <flux:heading size="lg" class="mb-2">{{ __('Need Help?') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Have questions about this listing or estimate? Message our team directly.') }}
                        </flux:text>
                        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                            @if ($this->selectedDeal->chatThread)
                                <flux:button variant="primary" icon="chat-bubble-left-right"
                                    href="{{ route('agent.chat.show', $this->selectedDeal->chatThread) }}" class="w-full sm:w-auto">
                                    {{ __('Open Chat') }}
                                </flux:button>
                            @else
                                <flux:button variant="primary" icon="chat-bubble-left-right"
                                    href="{{ route('agent.chat.index') }}" class="w-full sm:w-auto">
                                    {{ __('Send a Message') }}
                                </flux:button>
                            @endif
                            <flux:button variant="ghost" icon="video-camera"
                                wire:click="startVideoCall({{ $this->selectedDealId }})" class="w-full sm:w-auto">
                                {{ __('Video Call') }}
                            </flux:button>
                        </div>
                    </flux:card>
                </div>

                {{-- ROI Calculator --}}
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="lg">{{ __('ROI Calculator') }}</flux:heading>
                        @if ($this->selectedDeal)
                            <flux:button variant="ghost" size="sm" wire:click="populateFromListing" icon="arrow-down-tray">
                                {{ __('Auto-fill from Listing') }}
                            </flux:button>
                        @endif
                    </div>

                    <div class="space-y-6">
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                            <div>
                                <flux:input
                                    wire:model="roiPropertyValue"
                                    label="{{ __('Property Value ($)') }}"
                                    type="number"
                                    placeholder="450000"
                                    min="1"
                                    step="1000"
                                />
                            </div>
                            <div>
                                <flux:input
                                    wire:model="roiSqft"
                                    label="{{ __('Sqft') }}"
                                    type="number"
                                    placeholder="2000"
                                    min="1"
                                />
                            </div>
                            <div>
                                <flux:select wire:model="roiBedrooms" label="{{ __('Bedrooms') }}">
                                    @for ($i = 1; $i <= 8; $i++)
                                        <option value="{{ $i }}">{{ $i }}</option>
                                    @endfor
                                </flux:select>
                            </div>
                            <div x-data="{ scope: @entangle('roiScope') }">
                                <flux:text class="text-sm font-medium mb-2">{{ __('Scope') }}</flux:text>
                                <div class="flex gap-1">
                                    @foreach (['interior' => __('Int'), 'exterior' => __('Ext'), 'both' => __('Both')] as $val => $label)
                                        <button type="button"
                                            @click="scope = '{{ $val }}'"
                                            :class="scope === '{{ $val }}' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                            class="flex-1 px-2 py-1.5 rounded-lg text-xs font-medium transition-colors"
                                        >{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <div x-data="{ intent: @entangle('roiIntent') }">
                                <flux:text class="text-sm font-medium mb-2">{{ __('Intent') }}</flux:text>
                                <div class="flex gap-1">
                                    @foreach (['selling' => __('Selling'), 'buying' => __('Buying')] as $val => $label)
                                        <button type="button"
                                            @click="intent = '{{ $val }}'"
                                            :class="intent === '{{ $val }}' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                            class="flex-1 px-2 py-1.5 rounded-lg text-xs font-medium transition-colors"
                                        >{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        @error('roiPropertyValue') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
                        @error('roiSqft') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
                        @error('roiBedrooms') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror

                        <flux:button variant="primary" wire:click="calculateRoi" icon="calculator">
                            {{ __('Calculate ROI') }}
                        </flux:button>

                        {{-- Results --}}
                        @if ($roiResults)
                            <flux:separator />

                            <div>
                                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-3">
                                    @if ($roiIntent === 'selling')
                                        {{ __('A paint refresh could increase listing price by:') }}
                                    @else
                                        {{ __('Budget for painting to bring this home to its full potential:') }}
                                    @endif
                                </flux:text>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div class="rounded-xl bg-blue-50 dark:bg-blue-900/30 border border-blue-100 dark:border-blue-800 p-4 text-center">
                                        <flux:text class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wide">{{ __('Estimated Cost') }}</flux:text>
                                        <flux:text class="text-xl font-bold mt-1">
                                            ${{ number_format($roiResults['cost_low'], 0) }} &ndash; ${{ number_format($roiResults['cost_high'], 0) }}
                                        </flux:text>
                                    </div>
                                    <div class="rounded-xl bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-100 dark:border-emerald-800 p-4 text-center">
                                        <flux:text class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wide">{{ __('Value Increase') }}</flux:text>
                                        <flux:text class="text-xl font-bold mt-1 text-emerald-700 dark:text-emerald-300">
                                            ${{ number_format($roiResults['value_increase_low'], 0) }} &ndash; ${{ number_format($roiResults['value_increase_high'], 0) }}
                                        </flux:text>
                                    </div>
                                    <div class="rounded-xl bg-purple-50 dark:bg-purple-900/30 border border-purple-100 dark:border-purple-800 p-4 text-center">
                                        <flux:text class="text-xs font-semibold text-purple-600 dark:text-purple-400 uppercase tracking-wide">{{ __('ROI') }}</flux:text>
                                        <flux:text class="text-xl font-bold mt-1 text-purple-700 dark:text-purple-300">
                                            {{ number_format($roiResults['roi_percent_low'], 0) }}% &ndash; {{ number_format($roiResults['roi_percent_high'], 0) }}%
                                        </flux:text>
                                    </div>
                                </div>

                                {{-- Before/After Value Bar --}}
                                @if ($roiPropertyValue > 0)
                                    <div class="mt-4 rounded-xl bg-zinc-50 dark:bg-zinc-800 p-4 space-y-3">
                                        <div>
                                            <div class="flex justify-between text-sm mb-1">
                                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Current Value') }}</span>
                                                <span class="font-semibold">${{ number_format($roiPropertyValue, 0) }}</span>
                                            </div>
                                            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                                                <div class="bg-zinc-400 dark:bg-zinc-500 h-3 rounded-full" style="width: 100%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="flex justify-between text-sm mb-1">
                                                <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ __('After Painting') }}</span>
                                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">${{ number_format($roiPropertyValue + $roiResults['value_increase_high'], 0) }}</span>
                                            </div>
                                            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                                                <div class="bg-emerald-500 h-3 rounded-full" style="width: 100%"></div>
                                            </div>
                                        </div>
                                        <p class="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                            +${{ number_format($roiResults['value_increase_high'], 0) }} {{ __('estimated increase') }}
                                        </p>
                                    </div>
                                @endif
                            </div>

                            <flux:text class="text-xs text-zinc-400 dark:text-zinc-500 italic">
                                {{ __('Estimates vary by area, property condition, and listing strategy. Request a detailed estimate for accurate pricing.') }}
                            </flux:text>
                        @endif
                    </div>
                </flux:card>
            @endif

            {{-- Upcoming Meetings --}}
            @if ($this->upcomingMeetings->isNotEmpty())
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="lg">{{ __('Upcoming Meetings') }}</flux:heading>
                        <flux:badge size="sm" color="blue">{{ $this->upcomingMeetings->count() }}</flux:badge>
                    </div>
                    <div class="space-y-3">
                        @foreach ($this->upcomingMeetings as $meeting)
                            <div wire:key="dashboard-meeting-{{ $meeting->id }}" class="flex items-start gap-3 rounded-lg border p-3 {{ $meeting->meeting_status === 'confirmed' ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                                <div class="rounded-lg p-2 shrink-0 {{ $meeting->meeting_type === 'virtual' ? 'bg-blue-100 dark:bg-blue-900' : 'bg-zinc-100 dark:bg-zinc-800' }}">
                                    <flux:icon name="{{ $meeting->meeting_type === 'virtual' ? 'video-camera' : 'map-pin' }}" class="h-4 w-4 {{ $meeting->meeting_type === 'virtual' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-600 dark:text-zinc-400' }}" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="text-sm font-medium truncate">{{ $meeting->title }}</flux:text>
                                        <flux:badge size="sm" color="{{ $meeting->meeting_status === 'confirmed' ? 'green' : 'yellow' }}">
                                            {{ ucfirst($meeting->meeting_status) }}
                                        </flux:badge>
                                    </div>
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $meeting->due_at->format('M j, g:i A') }}
                                        @if ($meeting->subject?->property)
                                            &middot; {{ $meeting->subject->property->address ?? $meeting->subject->title }}
                                        @endif
                                    </flux:text>
                                    @if ($meeting->subject?->assigned_to)
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                            {{ __('Team: :name', ['name' => User::find($meeting->subject->assigned_to)?->name ?? __('Unassigned')]) }}
                                        </flux:text>
                                    @endif
                                </div>
                                @if ($meeting->estimate_id)
                                    <a href="{{ route('agent.estimates.show', $meeting->estimate_id) }}" class="shrink-0">
                                        <flux:icon name="arrow-top-right-on-square" class="h-4 w-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" />
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endif

            {{-- Stats (collapsible on mobile) --}}
            <div x-data="{ open: false }">
                {{-- Mobile Toggle --}}
                <div class="lg:hidden mb-4">
                    <flux:button variant="ghost" class="w-full" @click="open = !open">
                        <span x-text="open ? '{{ __('Hide Stats') }}' : '{{ __('View Stats') }}'"></span>
                        <flux:icon name="chevron-down" class="h-4 w-4 ml-1 transition-transform" x-bind:class="open && 'rotate-180'" />
                    </flux:button>
                </div>

                <div class="space-y-6" x-bind:class="{ 'hidden lg:block': !open, 'block': open }">
                    {{-- Listings & Referrals Row --}}
                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <flux:card>
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-blue-100 dark:bg-blue-900 p-2.5 shrink-0">
                                    <flux:icon name="building-office" class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Listings') }}</flux:text>
                                    <flux:text class="text-xl font-semibold">{{ $this->totalListings }}</flux:text>
                                </div>
                            </div>
                        </flux:card>

                        <flux:card>
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-green-100 dark:bg-green-900 p-2.5 shrink-0">
                                    <flux:icon name="check-circle" class="h-5 w-5 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Active') }}</flux:text>
                                    <flux:text class="text-xl font-semibold">{{ $this->activeListings }}</flux:text>
                                </div>
                            </div>
                        </flux:card>

                        <flux:card>
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-purple-100 dark:bg-purple-900 p-2.5 shrink-0">
                                    <flux:icon name="users" class="h-5 w-5 text-purple-600 dark:text-purple-400" />
                                </div>
                                <div>
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Customers') }}</flux:text>
                                    <flux:text class="text-xl font-semibold">{{ $this->referredCustomers }}</flux:text>
                                </div>
                            </div>
                        </flux:card>

                        <flux:card>
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-indigo-100 dark:bg-indigo-900 p-2.5 shrink-0">
                                    <flux:icon name="chart-bar" class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Conversion') }}</flux:text>
                                    <flux:text class="text-xl font-semibold">{{ $this->performanceMetrics['conversion_rate'] }}%</flux:text>
                                </div>
                            </div>
                        </flux:card>
                    </div>

                    {{-- Deal Trend (6-month grid) --}}
                    <flux:card>
                        <flux:heading size="lg" class="mb-4">{{ __('Deal Trend (Last 6 Months)') }}</flux:heading>
                        <div class="grid grid-cols-3 gap-4 sm:grid-cols-6">
                            @foreach ($this->monthlyDealCounts as $month => $count)
                                <div wire:key="month-{{ $loop->index }}" class="text-center p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $month }}</flux:text>
                                    <flux:text class="text-xl font-bold mt-1">{{ $count }}</flux:text>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                </div>
            </div>
        @endif
    </div>

    {{-- Pass to Customer Modal --}}
    @if ($this->selectedDeal && ! $this->selectedDeal->customer_id)
        <flux:modal wire:model="showPassModal" name="pass-to-customer" class="md:w-96">
            <form wire:submit="passToCustomer" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Pass to Customer') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Enter the customer\'s details to share this estimate with them.') }}</flux:text>
                </div>

                <flux:input wire:model="passEmail" label="{{ __('Email') }}" type="email" placeholder="customer@example.com" />
                <flux:input wire:model="passFirstName" label="{{ __('First Name') }}" placeholder="John" />
                <flux:input wire:model="passLastName" label="{{ __('Last Name') }}" placeholder="Doe" />

                <div class="flex">
                    <flux:spacer />
                    <flux:button type="submit" variant="primary" icon="user-plus">{{ __('Send to Customer') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
