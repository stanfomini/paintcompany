<?php

use App\Events\CustomerContributionAdded;
use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\PackageTemplate;
use App\Models\User;
use App\Services\ChatService;
use App\Services\MeetingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.customer', ['title' => 'Estimate Details'])] class extends Component
{
    public Estimate $estimate;

    public string $activeTab = 'photos';

    public bool $showAreaCarousel = false;

    public string $customerNotes = '';

    public string $listingUrl = '';

    /** @var array<string> */
    public array $customerChecklist = [];

    /** @var array<int, string> */
    public array $areaCustomerNotes = [];

    /** @var array<int, array<string>> */
    public array $areaCustomerChecklists = [];

    public string $meetingTitle = '';
    public string $meetingDateTime = '';
    public string $meetingType = 'in_person';
    public string $meetingDescription = '';
    public ?int $meetingTeamMember = null;

    /** @var array<string, array<string, string>> */
    private const CUSTOMER_CHECKLIST_GROUPS = [
        'common' => [
            'color_consultation' => 'Color consultation needed',
            'drywall_repair' => 'Drywall repair needed',
        ],
        'interior' => [
            'accent_walls' => 'Accent walls (different color)',
            'cabinet_painting' => 'Cabinet painting',
            'ceiling_paint' => 'Ceiling painting',
            'trim_paint' => 'Trim & baseboards',
            'door_painting' => 'Door painting',
            'wallpaper_removal' => 'Wallpaper removal',
            'popcorn_ceiling' => 'Popcorn ceiling removal',
        ],
        'exterior' => [
            'deck_staining' => 'Deck/fence staining',
            'pressure_washing' => 'Pressure washing',
            'garage_floor' => 'Garage floor coating',
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const AREA_CHECKLIST_GROUPS = [
        'common' => [
            'needs_repair' => 'Needs repair work',
            'peeling_paint' => 'Peeling or flaking paint',
        ],
        'interior' => [
            'has_wallpaper' => 'Has wallpaper to remove',
            'water_damage' => 'Water damage visible',
            'dark_to_light' => 'Going from dark to light color',
        ],
        'exterior' => [
            'high_ceiling' => 'High/vaulted ceiling',
            'wood_rot' => 'Wood rot present',
            'chalking_paint' => 'Chalking or oxidized paint',
        ],
    ];

    public function mount(Estimate $estimate): void
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer || ! $estimate->deal->customerCanAccess($customer)) {
            throw new NotFoundHttpException('Estimate not found.');
        }

        $this->estimate = $estimate->load([
            'deal.customer',
            'deal.property',
            'deal.chatThread',
            'areas.surfaces.productionRate',
            'options.serviceCategory',
            'packages.items.itemable',
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
        ]);

        $this->customerNotes = $this->estimate->customer_notes ?? '';
        $this->customerChecklist = $this->estimate->customer_checklist ?? [];
        $this->listingUrl = $this->estimate->deal->listing_url ?? '';

        foreach ($this->estimate->areas as $area) {
            $this->areaCustomerNotes[$area->id] = $area->customer_notes ?? '';
            $this->areaCustomerChecklists[$area->id] = $area->customer_checklist ?? [];
        }

        if (in_array($this->estimate->status, ['sent', 'accepted'])) {
            $this->activeTab = 'proposal';
        } else {
            $this->activeTab = 'photos';
        }

        $this->ensureChatParticipant();
        $this->estimate->load('deal.chatThread');
    }

    public function boot(): void
    {
        $user = Auth::guard('customer')->user();
        if (! $user || ! $user->isCustomer()) {
            abort(403);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->estimate) {
            return [];
        }

        $listeners = [
            "echo-private:estimate.{$this->estimate->id},area.updated" => 'onEstimateUpdated',
            "echo-private:estimate.{$this->estimate->id},estimate.updated" => 'onEstimateUpdated',
            "echo-private:estimate.{$this->estimate->id},.customer.contribution" => 'onEstimateUpdated',
            "echo-private:estimate.{$this->estimate->id},.estimate.status.changed" => 'onEstimateUpdated',
        ];

        // Job-level events if estimate has a job
        $job = $this->estimate->job;
        if ($job) {
            $listeners["echo-private:customer.job.{$job->id},stage.changed"] = 'onEstimateUpdated';
            $listeners["echo-private:customer.job.{$job->id},date.updated"] = 'onEstimateUpdated';
            $listeners["echo-private:customer.job.{$job->id},photo.updated"] = 'onEstimateUpdated';
        }

        // Chat thread listener
        $thread = $this->estimate->deal?->chatThread;
        if ($thread) {
            $listeners["echo-private:chat.thread.{$thread->id},.message.sent"] = 'onChatMessageReceived';
        }

        return $listeners;
    }

    public function onEstimateUpdated(): void
    {
        $this->estimate->refresh();
        $this->estimate->load(['areas.surfaces']);
    }

    public function onChatMessageReceived(): void
    {
        unset($this->chatMessages);
        $thread = $this->chatThread;
        if ($thread) {
            $user = Auth::guard('customer')->user();
            app(ChatService::class)->markThreadAsRead($thread, $user);
        }
    }

    #[Computed]
    public function chatThread(): ?ChatThread
    {
        return $this->estimate->deal?->chatThread;
    }

    #[Computed]
    public function chatMessages()
    {
        $thread = $this->chatThread;
        if (! $thread) {
            return collect();
        }

        $user = Auth::guard('customer')->user();

        return app(ChatService::class)->getMessagesForThread($thread, $user, 30);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return Customer::where('user_id', auth('customer')->id())->first();
    }

    #[Computed]
    public function meetingActivities()
    {
        return $this->estimate->deal?->activities()
            ->where('type', 'meeting')
            ->whereNotNull('meeting_type')
            ->with(['proposedBy', 'videoCall'])
            ->orderBy('due_at')
            ->get() ?? collect();
    }

    #[Computed]
    public function teamMembers()
    {
        return User::where('type', 'internal')->orderBy('name')->get(['id', 'name']);
    }

    public function proposeMeeting(): void
    {
        $this->validate([
            'meetingTitle' => 'required|string|max:255',
            'meetingDateTime' => 'required|date|after:now',
            'meetingType' => 'required|in:in_person,virtual',
            'meetingDescription' => 'nullable|string|max:1000',
        ]);

        $user = Auth::guard('customer')->user();
        $deal = $this->estimate->deal;

        app(MeetingService::class)->proposeMeeting(
            deal: $deal,
            proposer: $user,
            title: $this->meetingTitle,
            dateTime: Carbon::parse($this->meetingDateTime),
            meetingType: $this->meetingType,
            teamMemberId: $this->meetingTeamMember,
            description: $this->meetingDescription ?: null,
            estimateId: $this->estimate->id,
        );

        $this->reset(['meetingTitle', 'meetingDateTime', 'meetingType', 'meetingDescription', 'meetingTeamMember']);
        unset($this->meetingActivities);

        session()->flash('success', __('Meeting proposed successfully.'));
    }

    public function confirmMeeting(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);
        $user = Auth::guard('customer')->user();

        app(MeetingService::class)->confirmMeeting($activity, $user);
        unset($this->meetingActivities);

        session()->flash('success', __('Meeting confirmed.'));
    }

    public function declineMeeting(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);
        $user = Auth::guard('customer')->user();

        app(MeetingService::class)->declineMeeting($activity, $user);
        unset($this->meetingActivities);

        session()->flash('success', __('Meeting declined.'));
    }

    /**
     * Save customer notes and per-area notes.
     */
    public function saveNotes(): void
    {
        $this->estimate->update([
            'customer_notes' => $this->customerNotes,
        ]);

        foreach ($this->areaCustomerNotes as $areaId => $notes) {
            $this->estimate->areas()->where('id', $areaId)->update([
                'customer_notes' => $notes,
            ]);
        }

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'notes',
            $user->name,
            'Updated project notes',
        );

        session()->flash('success', __('Notes saved successfully.'));
    }

    /**
     * Save the customer checklist selections.
     */
    public function saveChecklist(): void
    {
        $this->estimate->update([
            'customer_checklist' => $this->customerChecklist,
        ]);

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'checklist',
            $user->name,
            'Updated project checklist',
        );
    }

    /**
     * Save per-area checklists.
     */
    public function saveAreaChecklist(int $areaId): void
    {
        $checklist = $this->areaCustomerChecklists[$areaId] ?? [];

        $this->estimate->areas()->where('id', $areaId)->update([
            'customer_checklist' => $checklist,
        ]);

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'area_checklist',
            $user->name,
            'Updated area checklist',
        );
    }

    /**
     * Save a listing URL for the property.
     */
    public function saveListing(): void
    {
        $this->validate([
            'listingUrl' => 'nullable|url',
        ]);

        $this->estimate->deal->update([
            'listing_url' => $this->listingUrl ?: null,
        ]);

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'listing',
            $user->name,
            'Added listing link',
        );

        session()->flash('success', __('Listing link saved.'));
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        $thread = $this->chatThread;
        if ($thread) {
            $user = Auth::guard('customer')->user();
            app(ChatService::class)->markThreadAsRead($thread, $user);
        }
    }

    /**
     * Get customer checklist options filtered by project scope type.
     *
     * @return array<string, string>
     */
    public function getCustomerChecklistOptions(): array
    {
        return $this->filterChecklistByScope(
            self::CUSTOMER_CHECKLIST_GROUPS,
            $this->estimate->project_type,
        );
    }

    /**
     * Get area checklist options filtered by scope type.
     *
     * @return array<string, string>
     */
    public function getAreaChecklistOptions(?string $scopeType = null): array
    {
        return $this->filterChecklistByScope(
            self::AREA_CHECKLIST_GROUPS,
            $scopeType ?? $this->estimate->project_type,
        );
    }

    /**
     * Filter grouped checklist options based on scope type.
     *
     * @param  array<string, array<string, string>>  $groups
     * @return array<string, string>
     */
    private function filterChecklistByScope(array $groups, ?string $scopeType): array
    {
        $options = $groups['common'] ?? [];

        if ($scopeType === 'interior') {
            return array_merge($options, $groups['interior'] ?? []);
        }

        if ($scopeType === 'exterior') {
            return array_merge($options, $groups['exterior'] ?? []);
        }

        // 'both' or null - return all options
        return array_merge($options, $groups['interior'] ?? [], $groups['exterior'] ?? []);
    }

    public function openAreaCarousel(): void
    {
        $this->showAreaCarousel = true;
    }

    public function closeAreaCarousel(): void
    {
        $this->showAreaCarousel = false;
    }

    #[Computed]
    public function hasProposalPackages(): bool
    {
        return $this->estimate->packages->isNotEmpty()
            || PackageTemplate::active()->exists();
    }

    #[Computed]
    public function usePackageTemplates(): bool
    {
        return $this->estimate->packages->isEmpty()
            && PackageTemplate::active()->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function packagePreviewData(): array
    {
        if ($this->usePackageTemplates) {
            return $this->buildTemplatePackageData();
        }

        if ($this->estimate->packages->isNotEmpty()) {
            return $this->buildLegacyPackageData();
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTemplatePackageData(): array
    {
        $templates = PackageTemplate::active()
            ->with('defaultItems')
            ->ordered()
            ->get();

        if ($templates->isEmpty()) {
            return [];
        }

        $paintingTotal = (float) $this->estimate->areas_subtotal;

        return $templates->map(function (PackageTemplate $template) use ($paintingTotal) {
            $templateItems = $template->defaultItems->isNotEmpty()
                ? $template->defaultItems
                : $template->items;

            $warrantyItems = [];
            $serviceItems = [];
            $includedTotal = 0;

            foreach ($templateItems as $item) {
                if (! $item->is_included_by_default) {
                    continue;
                }

                $itemPrice = $item->calculatePrice($paintingTotal);
                $includedTotal += $itemPrice;
                $isWarranty = $item->pricing_type === 'percent' && $item->is_customer_selectable;

                $entry = ['name' => $item->name, 'price' => $itemPrice];

                if ($isWarranty) {
                    $warrantyItems[] = $entry;
                } else {
                    $serviceItems[] = $entry;
                }
            }

            return [
                'name' => $template->name,
                'tier' => $template->tier,
                'tier_label' => ucfirst($template->tier),
                'description' => $template->description,
                'total' => $paintingTotal + $includedTotal,
                'is_recommended' => $template->is_popular,
                'is_selected' => false,
                'warranty_items' => $warrantyItems,
                'service_items' => $serviceItems,
            ];
        })->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildLegacyPackageData(): array
    {
        return $this->estimate->packages->map(function ($package) {
            $items = $package->items
                ->where('is_included', true)
                ->where('customer_approved', true)
                ->map(fn ($item) => [
                    'name' => $item->name ?? $item->itemable?->name ?? __('Item'),
                    'price' => (float) ($item->price ?? 0),
                    'is_warranty' => str_contains(strtolower($item->name ?? ''), 'warranty'),
                ])->values()->toArray();

            return [
                'name' => $package->name,
                'tier' => $package->tier,
                'tier_label' => $package->tier_label,
                'description' => $package->description,
                'total' => (float) $package->total,
                'is_recommended' => $package->is_recommended,
                'is_selected' => $package->is_selected,
                'warranty_years' => $package->warranty_years,
                'warranty_price' => (float) ($package->warranty_price ?? 0),
                'items' => $items,
            ];
        })->toArray();
    }

    /**
     * Ensure the customer is a participant in the deal's chat thread.
     */
    private function ensureChatParticipant(): void
    {
        $user = Auth::guard('customer')->user();
        $deal = $this->estimate->deal;

        if ($deal) {
            $thread = $deal->getOrCreateChatThread();
            if (! $thread->hasParticipant($user)) {
                $thread->addParticipant($user);
            }
        }
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center gap-4 mb-6">
            <flux:button variant="ghost" :href="route('customer.dashboard')" icon="arrow-left" wire:navigate>
                {{ __('Back') }}
            </flux:button>
            <div class="flex-1">
                <flux:heading size="xl">{{ $estimate->title ?: $estimate->estimate_number }}</flux:heading>
                <flux:text class="text-zinc-500">{{ $estimate->deal->title }}</flux:text>
            </div>
            <flux:badge size="lg" :color="match($estimate->status) {
                'draft' => 'zinc',
                'sent' => 'blue',
                'accepted' => 'green',
                'rejected' => 'red',
                'expired' => 'yellow',
                default => 'zinc',
            }">
                {{ $estimate->status_label }}
            </flux:badge>
        </div>

        {{-- Quick Info Bar --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 mb-6 px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-lg text-sm">
            @if ($estimate->deal?->property)
                <div class="flex items-center gap-2">
                    <flux:icon name="map-pin" class="w-4 h-4 text-zinc-400 shrink-0" />
                    <flux:text class="text-zinc-600 dark:text-zinc-300">
                        {{ $estimate->deal->property->full_address ?: $estimate->deal->property->address }}
                    </flux:text>
                </div>
            @endif
            @if ($estimate->total && in_array($estimate->status, ['sent', 'accepted']))
                <div class="flex items-center gap-2">
                    <flux:icon name="currency-dollar" class="w-4 h-4 text-zinc-400 shrink-0" />
                    <flux:text class="text-zinc-600 dark:text-zinc-300">
                        ${{ number_format($estimate->total, 2) }}
                    </flux:text>
                </div>
            @endif
            @if ($estimate->areas->count() > 0)
                <div class="flex items-center gap-2">
                    <flux:icon name="squares-2x2" class="w-4 h-4 text-zinc-400 shrink-0" />
                    <flux:text class="text-zinc-600 dark:text-zinc-300">
                        {{ __(':count areas', ['count' => $estimate->areas->count()]) }}
                    </flux:text>
                </div>
            @endif
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- View Your Rooms Button --}}
        @if ($estimate->areas->count() > 0)
            <div class="mb-6">
                <button
                    wire:click="openAreaCarousel"
                    class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold text-base rounded-xl shadow hover:from-blue-700 hover:to-indigo-700 transition-all flex items-center justify-center gap-2 min-h-[48px]"
                >
                    <flux:icon name="squares-2x2" class="w-5 h-5" />
                    {{ __('View Your Rooms') }}
                    <flux:badge size="sm" color="blue" class="ml-1 bg-white/20 text-white border-0">
                        {{ $estimate->areas->count() }}
                    </flux:badge>
                </button>
            </div>
        @endif

        {{-- Tab Navigation --}}
        <div x-data class="mb-6">
            <div class="flex flex-wrap gap-1 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                {{-- Photos & Videos --}}
                <button
                    wire:click="$set('activeTab', 'photos')"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $activeTab === 'photos' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                >
                    <flux:icon name="photo" class="w-4 h-4" />
                    {{ __('Photos & Videos') }}
                </button>

                {{-- Proposal --}}
                @if (in_array($estimate->status, ['sent', 'accepted']))
                    <button
                        wire:click="$set('activeTab', 'proposal')"
                        class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $activeTab === 'proposal' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                    >
                        <flux:icon name="document-text" class="w-4 h-4" />
                        {{ __('Proposal') }}
                    </button>
                @endif

                {{-- Overview --}}
                <button
                    wire:click="$set('activeTab', 'overview')"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $activeTab === 'overview' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                >
                    <flux:icon name="pencil-square" class="w-4 h-4" />
                    {{ __('Overview') }}
                </button>
            </div>
        </div>

        {{-- ============================================================== --}}
        {{-- TAB: Photos & Videos --}}
        {{-- ============================================================== --}}
        @if ($activeTab === 'photos')
            <div class="space-y-6">
                {{-- Project Photos (Full Gallery with Upload, Annotation, Comments) --}}
                @if ($estimate->deal?->property)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <livewire:shared.photo-gallery
                            :property="$estimate->deal->property"
                            :estimate="$estimate"
                            context="estimate-customer"
                            :key="'customer-estimate-photos-'.$estimate->id"
                        />
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="photo" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text class="text-zinc-500">{{ __('No property linked to this estimate yet.') }}</flux:text>
                    </div>
                @endif

                {{-- Video Walkarounds --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden print:hidden">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 flex items-center gap-2">
                        <flux:icon name="video-camera" class="w-5 h-5 text-zinc-500" />
                        <flux:heading size="sm">{{ __('Video Walkarounds') }}</flux:heading>
                    </div>
                    <div class="p-4">
                        <livewire:estimates.video-walkarounds
                            :recordable="$estimate"
                            :key="'collab-walkarounds-'.$estimate->id"
                        />
                    </div>
                </div>

            </div>

        {{-- ============================================================== --}}
        {{-- TAB: Proposal --}}
        {{-- ============================================================== --}}
        @elseif ($activeTab === 'proposal')
            <div class="space-y-6">
                {{-- Status Banner --}}
                @if ($estimate->status === 'accepted')
                    <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg flex items-center gap-3">
                        <flux:icon name="check-circle" class="w-6 h-6 text-green-500 shrink-0" />
                        <div>
                            <flux:text class="font-medium text-green-800 dark:text-green-300">{{ __('Estimate Accepted') }}</flux:text>
                            @if ($estimate->accepted_at)
                                <flux:text class="text-sm text-green-600 dark:text-green-400">
                                    {{ __('Accepted') }} {{ $estimate->accepted_at->diffForHumans() }}
                                </flux:text>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- CTA Banner --}}
                @php
                    if (! $estimate->public_token || ! $estimate->isPublicTokenValid()) {
                        $estimate->generatePublicToken(365);
                    }
                    $publicUrl = $estimate->getPublicUrl();
                @endphp
                <div class="rounded-lg bg-gradient-to-r from-blue-600 to-blue-700 p-5 text-white">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">
                                @if ($estimate->status === 'sent' && ! $estimate->hasSignature())
                                    {{ __('Review & Sign Your Proposal') }}
                                @else
                                    {{ __('View Full Proposal') }}
                                @endif
                            </h3>
                            <p class="text-sm text-blue-100 mt-1">
                                @if ($estimate->status === 'sent' && ! $estimate->hasSignature())
                                    {{ __('Ready to move forward? Review the complete proposal and sign to accept.') }}
                                @else
                                    {{ __('View the complete proposal with all details and options.') }}
                                @endif
                            </p>
                        </div>
                        <a
                            href="{{ $publicUrl }}"
                            target="_blank"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-blue-700 font-semibold rounded-lg hover:bg-blue-50 transition-colors shrink-0"
                        >
                            <flux:icon name="arrow-top-right-on-square" class="w-4 h-4" />
                            {{ __('Review Full Proposal') }}
                        </a>
                    </div>
                </div>

                {{-- Package Cards --}}
                @if ($this->hasProposalPackages)
                    @include('partials.proposal-preview.packages', ['packageData' => $this->packagePreviewData])
                @endif

                {{-- Scope of Work --}}
                @if ($estimate->areas->isNotEmpty())
                    @include('partials.proposal-preview.scope-of-work', ['estimate' => $estimate])
                @endif

                {{-- Options (non-package mode only) --}}
                @if (! $this->hasProposalPackages && $estimate->options->isNotEmpty())
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-4">{{ __('Optional Add-ons') }}</flux:heading>
                        <div class="space-y-2">
                            @foreach ($estimate->options as $option)
                                <div class="flex items-center justify-between py-2" wire:key="preview-option-{{ $option->id }}">
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" :color="$option->is_selected ? 'green' : 'zinc'">
                                            {{ $option->is_selected ? __('Included') : __('Not Included') }}
                                        </flux:badge>
                                        <flux:text class="text-sm">{{ $option->name }}</flux:text>
                                    </div>
                                    <flux:text class="text-sm font-medium">+${{ number_format($option->calculated_price, 2) }}</flux:text>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Pricing Summary --}}
                @include('partials.proposal-preview.pricing-summary', [
                    'estimate' => $estimate,
                    'hasPackages' => $this->hasProposalPackages,
                    'packageData' => $this->packagePreviewData,
                ])

                {{-- Notes --}}
                @if ($estimate->estimateNotes->isNotEmpty())
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-3">{{ __('Notes') }}</flux:heading>
                        <div class="space-y-3">
                            @foreach ($estimate->estimateNotes as $note)
                                <div wire:key="preview-note-{{ $note->id }}" class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-3">
                                    <flux:text class="text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $note->content }}</flux:text>
                                    @if ($note->user)
                                        <flux:text class="text-xs text-zinc-500 mt-2">
                                            - {{ $note->user->name }}, {{ $note->created_at->format('M j, Y') }}
                                        </flux:text>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Terms --}}
                @if ($estimate->terms)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-3">{{ __('Terms & Conditions') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400 whitespace-pre-wrap">{{ $estimate->terms }}</flux:text>
                    </div>
                @endif
            </div>

        {{-- ============================================================== --}}
        {{-- TAB: Overview --}}
        {{-- ============================================================== --}}
        @elseif ($activeTab === 'overview')
            <div class="space-y-6">
                {{-- What I'm Looking For --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('What I\'m Looking For') }}</flux:heading>

                    <flux:field class="mb-4">
                        <flux:label>{{ __('Describe what you\'re looking for') }}</flux:label>
                        <flux:textarea
                            wire:model.blur="customerNotes"
                            wire:change="saveNotes"
                            rows="4"
                            :placeholder="__('Tell us about your vision - colors you like, areas of concern, special requests...')"
                        />
                    </flux:field>

                    {{-- Checklist Grid --}}
                    <div class="mt-4">
                        <flux:label class="mb-3">{{ __('Services I\'m interested in') }}</flux:label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($this->getCustomerChecklistOptions() as $key => $label)
                                <label
                                    class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors
                                        {{ in_array($key, $customerChecklist) ? 'border-blue-300 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                                    wire:key="checklist-{{ $key }}"
                                >
                                    <flux:checkbox
                                        wire:model="customerChecklist"
                                        value="{{ $key }}"
                                        wire:change="saveChecklist"
                                    />
                                    <flux:text class="text-sm">{{ __($label) }}</flux:text>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Listing Link --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Property Listing Link') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500 mb-4">
                        {{ __('If your home is listed online (Zillow, Redfin, etc.), share the link so the team can reference it.') }}
                    </flux:text>

                    <form wire:submit="saveListing" class="flex items-end gap-3">
                        <flux:field class="flex-1">
                            <flux:input
                                wire:model="listingUrl"
                                type="url"
                                placeholder="https://www.zillow.com/homedetails/..."
                            />
                            <flux:error name="listingUrl" />
                        </flux:field>
                        <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="saveListing">{{ __('Save') }}</span>
                            <span wire:loading wire:target="saveListing">{{ __('Saving...') }}</span>
                        </flux:button>
                    </form>

                    @if ($estimate->deal?->listing_url)
                        <div class="mt-3 flex items-center gap-2">
                            <flux:icon name="link" class="h-4 w-4 text-zinc-400 shrink-0" />
                            <a href="{{ $estimate->deal->listing_url }}" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 underline hover:no-underline break-all">
                                {{ $estimate->deal->listing_url }}
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ============================================================== --}}
        {{-- Persistent Chat Section (below tabs) --}}
        {{-- ============================================================== --}}
        @if ($this->chatThread)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mt-6 overflow-hidden">
                {{-- Chat Header --}}
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="chat-bubble-left-right" class="w-5 h-5 text-zinc-500" />
                        <flux:heading size="sm">{{ __('Project Conversation') }}</flux:heading>
                    </div>
                    <flux:text class="text-xs text-zinc-400 mt-1">{{ __('Discuss your project with the team.') }}</flux:text>
                </div>

                {{-- Messages --}}
                <div
                    class="max-h-80 overflow-y-auto p-4 space-y-3 bg-white dark:bg-zinc-900"
                    x-data="{
                        scrollToBottom() {
                            this.$el.scrollTop = this.$el.scrollHeight;
                        }
                    }"
                    x-init="
                        scrollToBottom();
                        const observer = new MutationObserver(() => $nextTick(() => scrollToBottom()));
                        observer.observe($el, { childList: true, subtree: true });
                    "
                    @message-sent.window="$nextTick(() => scrollToBottom())"
                >
                    @forelse ($this->chatMessages as $message)
                        @php
                            $isOwnMessage = $message->user_id === auth('customer')->id();
                            $isSystem = $message->type === 'system';
                        @endphp

                        <div wire:key="msg-{{ $message->id }}" class="{{ $isOwnMessage ? 'flex justify-end' : 'flex justify-start' }}">
                            @if ($isSystem)
                                <div class="w-full text-center py-1">
                                    <flux:text class="text-xs text-zinc-400 italic">{{ $message->content }}</flux:text>
                                </div>
                            @else
                                <div class="flex items-start gap-2 max-w-[80%] {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                    @if ($message->user)
                                        <flux:avatar size="xs" :initials="$message->user->initials()" class="bg-zinc-200 dark:bg-zinc-700 shrink-0" />
                                    @else
                                        <div class="w-6 h-6 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center shrink-0">
                                            <flux:icon name="user" class="w-3 h-3 text-zinc-500" />
                                        </div>
                                    @endif

                                    <div class="{{ $isOwnMessage ? 'text-right' : 'text-left' }}">
                                        <div class="flex items-center gap-1 mb-0.5 {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                            <flux:text class="text-xs font-medium text-zinc-500">{{ $message->user?->name ?? __('Unknown') }}</flux:text>
                                            <flux:text class="text-xs text-zinc-400">{{ $message->created_at->diffForHumans() }}</flux:text>
                                        </div>
                                        <div class="rounded-lg px-3 py-2 text-sm {{ $isOwnMessage ? 'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' }}">
                                            @if ($message->isAudio())
                                                <div class="flex items-center gap-2">
                                                    <flux:icon name="microphone" class="w-4 h-4 {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-400' }}" />
                                                    <audio controls preload="metadata" class="h-8 max-w-[200px]">
                                                        <source src="{{ $message->metadata['audio_url'] ?? '' }}">
                                                    </audio>
                                                </div>
                                                @if (! empty($message->metadata['transcription']))
                                                    <flux:text class="text-xs italic mt-1 {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                        "{{ $message->metadata['transcription'] }}"
                                                    </flux:text>
                                                @endif
                                            @else
                                                <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>
                                            @endif
                                        </div>

                                        @if ($message->attachments->isNotEmpty())
                                            <div class="mt-1 space-y-1">
                                                @foreach ($message->attachments as $attachment)
                                                    <div class="flex items-center gap-1 text-xs {{ $isOwnMessage ? 'justify-end' : 'justify-start' }}">
                                                        <flux:icon name="paper-clip" class="w-3 h-3 text-zinc-400" />
                                                        <flux:text class="text-zinc-500">{{ $attachment->original_filename }}</flux:text>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-6">
                            <flux:icon name="chat-bubble-left" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text class="text-sm text-zinc-500">{{ __('No messages yet. Send a message to discuss your project.') }}</flux:text>
                        </div>
                    @endforelse
                </div>

                {{-- Message Composer --}}
                <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3">
                    <livewire:customer.chat.message-composer :thread="$this->chatThread" :key="'estimate-composer-'.$this->chatThread->id" />
                </div>
            </div>
        @endif
    </div>

    {{-- Area Carousel Overlay --}}
    @if ($showAreaCarousel)
        <div
            x-data
            x-on:close-area-carousel.window="$wire.closeAreaCarousel()"
        >
            <livewire:customer.estimates.area-carousel
                :estimate="$estimate"
                :key="'show-carousel-'.$estimate->id"
            />
        </div>
    @endif
</div>
