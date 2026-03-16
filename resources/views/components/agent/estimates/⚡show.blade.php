<?php

use App\Events\AgentContributionAdded;
use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\PackageTemplate;
use App\Services\ChatService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.agent', ['title' => 'Estimate Details'])] class extends Component
{
    public Estimate $estimate;

    public string $activeTab = 'input';

    public string $agentNotes = '';

    public string $listingUrl = '';

    /** @var array<string> */
    public array $agentChecklist = [];

    /** @var array<int, string> */
    public array $areaAgentNotes = [];

    /** @var array<int, array<string>> */
    public array $areaAgentChecklists = [];

    /** @var array<string, array<string, string>> */
    private const AGENT_CHECKLIST_GROUPS = [
        'common' => [
            'vacant_property' => 'Property is vacant',
            'tenant_occupied' => 'Tenant occupied',
            'hoa_restrictions' => 'HOA color restrictions',
            'timeline_urgent' => 'Urgent timeline',
        ],
        'interior' => [
            'showing_active' => 'Active showings (need flexible schedule)',
            'staging_planned' => 'Staging planned',
            'smoke_damage' => 'Smoke/odor damage',
            'pet_damage' => 'Pet damage visible',
            'dark_colors' => 'Dark colors throughout',
            'dated_wallpaper' => 'Dated wallpaper present',
        ],
        'exterior' => [
            'curb_appeal_priority' => 'Curb appeal is top priority',
            'wood_siding' => 'Wood siding (may need prep)',
            'stucco_damage' => 'Stucco damage visible',
            'fading_severe' => 'Severe fading/chalking',
            'multiple_colors' => 'Multiple body/trim colors needed',
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
        $agent = auth('agent')->user();

        if (! $agent || ! $estimate->deal || $estimate->deal->agent_id !== $agent->id) {
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

        $this->agentNotes = $this->estimate->agent_notes ?? '';
        $this->agentChecklist = $this->estimate->agent_checklist ?? [];
        $this->listingUrl = $this->estimate->deal->listing_url ?? '';

        foreach ($this->estimate->areas as $area) {
            $this->areaAgentNotes[$area->id] = $area->agent_notes ?? '';
            $this->areaAgentChecklists[$area->id] = $area->agent_checklist ?? [];
        }

        if (in_array($this->estimate->status, ['sent', 'accepted'])) {
            $this->activeTab = 'proposal';
        }

        $this->ensureChatParticipant();
        $this->estimate->load('deal.chatThread');
    }

    public function boot(): void
    {
        $user = Auth::guard('agent')->user();
        if (! $user || ! $user->isAgent()) {
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
            "echo-private:estimate.{$this->estimate->id},.agent.contribution" => 'onEstimateUpdated',
            "echo-private:estimate.{$this->estimate->id},.customer.contribution" => 'onEstimateUpdated',
            "echo-private:estimate.{$this->estimate->id},.estimate.status.changed" => 'onEstimateUpdated',
        ];

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
            $user = Auth::guard('agent')->user();
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

        $user = Auth::guard('agent')->user();

        return app(ChatService::class)->getMessagesForThread($thread, $user, 30);
    }

    /**
     * Save agent notes and per-area notes.
     */
    public function saveNotes(): void
    {
        $this->estimate->update([
            'agent_notes' => $this->agentNotes,
        ]);

        foreach ($this->areaAgentNotes as $areaId => $notes) {
            $this->estimate->areas()->where('id', $areaId)->update([
                'agent_notes' => $notes,
            ]);
        }

        $user = Auth::guard('agent')->user();
        AgentContributionAdded::dispatch(
            $this->estimate->id,
            'notes',
            $user->name,
            'Updated agent observations',
        );

        session()->flash('success', __('Notes saved successfully.'));
    }

    /**
     * Save the agent checklist selections.
     */
    public function saveChecklist(): void
    {
        $this->estimate->update([
            'agent_checklist' => $this->agentChecklist,
        ]);

        $user = Auth::guard('agent')->user();
        AgentContributionAdded::dispatch(
            $this->estimate->id,
            'checklist',
            $user->name,
            'Updated property checklist',
        );
    }

    /**
     * Save per-area checklists.
     */
    public function saveAreaChecklist(int $areaId): void
    {
        $checklist = $this->areaAgentChecklists[$areaId] ?? [];

        $this->estimate->areas()->where('id', $areaId)->update([
            'agent_checklist' => $checklist,
        ]);

        $user = Auth::guard('agent')->user();
        AgentContributionAdded::dispatch(
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

        $user = Auth::guard('agent')->user();
        AgentContributionAdded::dispatch(
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
            $user = Auth::guard('agent')->user();
            app(ChatService::class)->markThreadAsRead($thread, $user);
        }
    }

    /**
     * Get agent checklist options filtered by project scope type.
     *
     * @return array<string, string>
     */
    public function getAgentChecklistOptions(): array
    {
        return $this->filterChecklistByScope(
            self::AGENT_CHECKLIST_GROUPS,
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

        return array_merge($options, $groups['interior'] ?? [], $groups['exterior'] ?? []);
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

    public function getBookingUrl(): string
    {
        $deal = $this->estimate->deal;
        if (! $deal?->customer) {
            return route('booking.general');
        }

        $service = app(\App\Services\AppointmentBookingService::class);
        $token = $service->generatePrefillToken($deal->customer);

        return url('/book?prefill='.$token);
    }

    /**
     * Ensure the agent is a participant in the deal's chat thread.
     */
    private function ensureChatParticipant(): void
    {
        $user = Auth::guard('agent')->user();
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
            <flux:button variant="ghost" :href="route('agent.dashboard')" icon="arrow-left" wire:navigate>
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
                        {{ $estimate->areas->count() }} {{ Str::plural('area', $estimate->areas->count()) }}
                    </flux:text>
                </div>
            @endif
            <div class="ml-auto flex items-center gap-2">
                <flux:button size="sm" icon="phone" variant="ghost" :href="$this->getBookingUrl()">
                    {{ __('Book a Call') }}
                </flux:button>
                <flux:button size="sm" icon="calendar-days" variant="ghost" :href="$this->getBookingUrl()">
                    {{ __('Book a Visit') }}
                </flux:button>
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Tab Navigation --}}
        <div class="mb-6">
            <div class="flex flex-wrap gap-1 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                {{-- My Observations --}}
                <button
                    wire:click="$set('activeTab', 'input')"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $activeTab === 'input' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                >
                    <flux:icon name="pencil-square" class="w-4 h-4" />
                    {{ __('My Observations') }}
                </button>

                {{-- Photos & Videos --}}
                <button
                    wire:click="$set('activeTab', 'photos')"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $activeTab === 'photos' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                >
                    <flux:icon name="photo" class="w-4 h-4" />
                    {{ __('Photos & Videos') }}
                </button>

                {{-- Scope of Work --}}
                @if ($estimate->areas->count() > 0)
                    <button
                        wire:click="$set('activeTab', 'scope')"
                        class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-md transition-colors {{ $activeTab === 'scope' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                    >
                        <flux:icon name="clipboard-document-list" class="w-4 h-4" />
                        {{ __('Scope of Work') }}
                    </button>
                @endif

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

            </div>
        </div>

        {{-- ============================================================== --}}
        {{-- TAB: My Observations --}}
        {{-- ============================================================== --}}
        @if ($activeTab === 'input')
            <div class="space-y-6">
                {{-- Property Observations --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Property Observations') }}</flux:heading>

                    <flux:field class="mb-4">
                        <flux:label>{{ __('Notes about the property') }}</flux:label>
                        <flux:textarea
                            wire:model.blur="agentNotes"
                            wire:change="saveNotes"
                            rows="4"
                            :placeholder="__('Share what you\'ve observed - condition, special considerations, client priorities, access notes...')"
                        />
                    </flux:field>

                    {{-- Checklist Grid --}}
                    <div class="mt-4">
                        <flux:label class="mb-3">{{ __('Property conditions & considerations') }}</flux:label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($this->getAgentChecklistOptions() as $key => $label)
                                <label
                                    class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors
                                        {{ in_array($key, $agentChecklist) ? 'border-blue-300 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                                    wire:key="checklist-{{ $key }}"
                                >
                                    <flux:checkbox
                                        wire:model="agentChecklist"
                                        value="{{ $key }}"
                                        wire:change="saveChecklist"
                                    />
                                    <flux:text class="text-sm">{{ __($label) }}</flux:text>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Per-Area Notes --}}
                @if ($estimate->areas->count() > 0)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-4">{{ __('Notes by Area') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500 mb-4">
                            {{ __('Add specific observations for each area.') }}
                        </flux:text>

                        <div class="space-y-4">
                            @foreach ($estimate->areas as $area)
                                <div class="p-4 bg-zinc-50 dark:bg-zinc-800 rounded-lg" wire:key="area-note-{{ $area->id }}">
                                    <div class="flex items-center gap-2 mb-2">
                                        <flux:text class="font-medium text-sm">{{ $area->name }}</flux:text>
                                        <flux:badge size="sm" :color="$area->scope_type === 'exterior' ? 'orange' : 'blue'">
                                            {{ ucfirst($area->scope_type ?? 'interior') }}
                                        </flux:badge>
                                    </div>
                                    <flux:textarea
                                        wire:model.blur="areaAgentNotes.{{ $area->id }}"
                                        wire:change="saveNotes"
                                        rows="2"
                                        :placeholder="__('Any notes for this area...')"
                                        class="text-sm"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Listing Link --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Property Listing Link') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500 mb-4">
                        {{ __('Share the property listing link (Zillow, Redfin, etc.) so the team can reference it.') }}
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

        {{-- ============================================================== --}}
        {{-- TAB: Photos & Videos --}}
        {{-- ============================================================== --}}
        @elseif ($activeTab === 'photos')
            <div class="space-y-6">
                @if ($estimate->deal?->property)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <livewire:shared.photo-gallery
                            :property="$estimate->deal->property"
                            :estimate="$estimate"
                            context="estimate-agent"
                            :key="'agent-estimate-photos-'.$estimate->id"
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
                            :key="'agent-walkarounds-'.$estimate->id"
                        />
                    </div>
                </div>

            </div>

        {{-- ============================================================== --}}
        {{-- TAB: Scope of Work --}}
        {{-- ============================================================== --}}
        @elseif ($activeTab === 'scope')
            <div class="space-y-4">
                @forelse ($estimate->areas as $area)
                    <div
                        class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden"
                        x-data="{ expanded: false }"
                        wire:key="scope-area-{{ $area->id }}"
                    >
                        {{-- Area Header --}}
                        <button
                            type="button"
                            x-on:click="expanded = !expanded"
                            class="w-full flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700/50 transition-colors text-left"
                        >
                            <div class="flex items-center gap-3">
                                <flux:text class="font-medium">{{ $area->name }}</flux:text>
                                <flux:badge size="sm" :color="$area->scope_type === 'exterior' ? 'orange' : 'blue'">
                                    {{ ucfirst($area->scope_type ?? 'interior') }}
                                </flux:badge>
                            </div>
                            <flux:icon name="chevron-down" class="w-5 h-5 text-zinc-400 transition-transform" x-bind:class="expanded && 'rotate-180'" />
                        </button>

                        {{-- Expanded Surface Details --}}
                        <div x-show="expanded" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
                            @if ($area->surfaces->count() > 0)
                                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($area->surfaces as $surface)
                                        <div class="flex items-center justify-between px-4 py-3" wire:key="surface-{{ $surface->id }}">
                                            <flux:text class="text-sm">{{ $surface->name }}</flux:text>
                                            <div class="flex items-center gap-3">
                                                @if ($surface->quantity)
                                                    <flux:text class="text-sm text-zinc-500">
                                                        {{ number_format($surface->quantity, 0) }} {{ $surface->unit ?? 'sqft' }}
                                                    </flux:text>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="p-4">
                                    <flux:text class="text-sm text-zinc-500">{{ __('No surface details available yet.') }}</flux:text>
                                </div>
                            @endif

                            {{-- Per-Area Checklist --}}
                            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50">
                                <flux:label class="mb-2">{{ __('Area Conditions') }}</flux:label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @foreach ($this->getAreaChecklistOptions($area->scope_type) as $key => $label)
                                        <label
                                            class="flex items-center gap-2 p-2 rounded cursor-pointer text-sm
                                                {{ in_array($key, $areaAgentChecklists[$area->id] ?? []) ? 'text-blue-700 dark:text-blue-400' : 'text-zinc-600 dark:text-zinc-400' }}"
                                            wire:key="area-{{ $area->id }}-check-{{ $key }}"
                                        >
                                            <flux:checkbox
                                                wire:model="areaAgentChecklists.{{ $area->id }}"
                                                value="{{ $key }}"
                                                wire:change="saveAreaChecklist({{ $area->id }})"
                                            />
                                            {{ __($label) }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Per-Area Notes --}}
                            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:textarea
                                    wire:model.blur="areaAgentNotes.{{ $area->id }}"
                                    wire:change="saveNotes"
                                    rows="2"
                                    :placeholder="__('Notes for :area...', ['area' => $area->name])"
                                    class="text-sm"
                                />
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="clipboard-document-list" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:heading size="sm" class="mb-1">{{ __('Scope Being Developed') }}</flux:heading>
                        <flux:text class="text-zinc-500 text-sm">{{ __('Our team is working on defining the scope of work for this project.') }}</flux:text>
                    </div>
                @endforelse
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
                @if ($estimate->public_token && $estimate->isPublicTokenValid())
                    @php $publicUrl = $estimate->getPublicUrl(); @endphp
                    <div class="rounded-lg bg-gradient-to-r from-blue-600 to-blue-700 p-5 text-white">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">{{ __('View Full Proposal') }}</h3>
                                <p class="text-sm text-blue-100 mt-1">{{ __('See exactly what your client will see when they review the proposal.') }}</p>
                            </div>
                            <a
                                href="{{ $publicUrl }}"
                                target="_blank"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-blue-700 font-semibold rounded-lg hover:bg-blue-50 transition-colors shrink-0"
                            >
                                <flux:icon name="arrow-top-right-on-square" class="w-4 h-4" />
                                {{ __('View Full Proposal') }}
                            </a>
                        </div>
                    </div>
                @endif

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
                    <flux:text class="text-xs text-zinc-400 mt-1">{{ __('Discuss this project with the team.') }}</flux:text>
                </div>

                {{-- Messages --}}
                <div class="max-h-80 overflow-y-auto p-4 space-y-3 bg-white dark:bg-zinc-900" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @forelse ($this->chatMessages as $message)
                        @php
                            $isOwnMessage = $message->user_id === auth('agent')->id();
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
                                            <flux:text class="text-xs font-medium text-zinc-500">{{ $message->user?->name ?? 'Unknown' }}</flux:text>
                                            <flux:text class="text-xs text-zinc-400">{{ $message->created_at->diffForHumans() }}</flux:text>
                                        </div>
                                        <div class="rounded-lg px-3 py-2 text-sm {{ $isOwnMessage ? 'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' }}">
                                            @if ($message->isAudio())
                                                <div class="flex items-center gap-2">
                                                    <flux:icon name="microphone" class="w-4 h-4 {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-400' }}" />
                                                    <audio controls preload="metadata" class="h-8 max-w-[200px]">
                                                        <source src="{{ $message->getAudioUrl() ?? '' }}">
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
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-6">
                            <flux:icon name="chat-bubble-left" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text class="text-sm text-zinc-500">{{ __('No messages yet. Send a message to discuss this project.') }}</flux:text>
                        </div>
                    @endforelse
                </div>

                {{-- Message Composer --}}
                <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3">
                    <livewire:agent.chat.message-composer :thread="$this->chatThread" :key="'agent-estimate-composer-'.$this->chatThread->id" />
                </div>
            </div>
        @endif
    </div>
</div>
