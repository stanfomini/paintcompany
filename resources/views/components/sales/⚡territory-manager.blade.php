<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use App\Services\TerritoryDispatchService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    // Territory form fields
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    #[Validate('required|string|max:7')]
    public string $color = '#3B82F6';

    public ?array $boundary = null;

    public ?int $assignedUserId = null;

    public ?float $centerLat = null;

    public ?float $centerLng = null;

    // UI state
    public string $activeTab = 'map';
    public string $pnlPeriod = 'month';

    public ?int $editingId = null;

    public bool $showForm = false;

    public bool $showDispatchModal = false;

    public ?int $dispatchTerritoryId = null;

    public ?string $dispatchDate = null;

    public int $dispatchExpiry = 30;

    public function mount(?SalesTerritory $territory = null): void
    {
        $this->dispatchDate = now()->addDay()->toDateString();

        if ($territory?->exists) {
            $this->editTerritory($territory->id);
        }
    }

    #[Computed]
    public function territories()
    {
        return SalesTerritory::query()
            ->withCount('doorKnocks')
            ->with('assignedUser')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function internalUsers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function territoryPolygons(): array
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::query()
            ->whereNotNull('boundary')
            ->where('status', 'active');

        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'color' => $t->color ?? '#3B82F6',
            'boundary' => $t->boundary,
            'level' => $t->level,
        ])->toArray();
    }

    #[Computed]
    public function dispatchableBlocks(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::query()
            ->where('level', 'block')
            ->where('status', 'active')
            ->with(['assignedUser', 'parent'])
            ->orderBy('name');

        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->get();
    }

    #[Computed]
    public function pnlData(): array
    {
        $pnlService = app(\App\Services\TerritoryPnlService::class);
        $scopeService = app(PermissionScopeService::class);
        $user = auth()->user();

        [$start, $end] = match ($this->pnlPeriod) {
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };

        $query = SalesTerritory::where('level', 'region');
        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        $regions = $query->get();
        $results = [];

        foreach ($regions as $region) {
            $results[] = $pnlService->calculatePnlRollup($region, $start, $end);
        }

        return $results;
    }

    /**
     * Smart center: first territory center, or US center.
     */
    #[Computed]
    public function mapCenter(): array
    {
        $territory = SalesTerritory::whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->first();

        if ($territory) {
            return [(float) $territory->center_lat, (float) $territory->center_lng];
        }

        return [39.8283, -98.5795];
    }

    public function createTerritory(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function editTerritory(int $id): void
    {
        $territory = SalesTerritory::findOrFail($id);
        $this->editingId = $id;
        $this->name = $territory->name;
        $this->description = $territory->description;
        $this->color = $territory->color;
        $this->boundary = $territory->boundary;
        $this->assignedUserId = $territory->assigned_user_id;
        $this->centerLat = $territory->center_lat;
        $this->centerLng = $territory->center_lng;
        $this->showForm = true;
    }

    public function saveTerritory(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'boundary' => $this->boundary,
            'assigned_user_id' => $this->assignedUserId,
            'center_lat' => $this->centerLat,
            'center_lng' => $this->centerLng,
        ];

        if ($this->editingId) {
            SalesTerritory::findOrFail($this->editingId)->update($data);
            session()->flash('success', __('Territory updated.'));
        } else {
            SalesTerritory::create($data);
            session()->flash('success', __('Territory created.'));
        }

        $this->resetForm();
        unset($this->territories, $this->territoryPolygons);
    }

    public function deleteTerritory(int $id): void
    {
        SalesTerritory::findOrFail($id)->delete();
        session()->flash('success', __('Territory deleted.'));
        unset($this->territories, $this->territoryPolygons);
    }

    public function archiveTerritory(int $id): void
    {
        SalesTerritory::findOrFail($id)->update(['status' => 'archived']);
        session()->flash('success', __('Territory archived.'));
        unset($this->territories);
    }

    public function openDispatch(int $territoryId): void
    {
        $this->dispatchTerritoryId = $territoryId;
        $this->showDispatchModal = true;
    }

    public function dispatchTerritory(): void
    {
        $territory = SalesTerritory::findOrFail($this->dispatchTerritoryId);
        $service = app(TerritoryDispatchService::class);
        $workDate = \Carbon\Carbon::parse($this->dispatchDate);

        $eligibleReps = $service->getEligibleReps($territory, $workDate);

        if ($eligibleReps->isEmpty()) {
            session()->flash('error', __('No eligible reps found for this date.'));
            $this->showDispatchModal = false;

            return;
        }

        $service->createOffer($territory, $workDate, $eligibleReps->pluck('id')->toArray(), $this->dispatchExpiry);

        session()->flash('success', __('Territory dispatched to :count reps.', ['count' => $eligibleReps->count()]));
        $this->showDispatchModal = false;
    }

    public function setBoundary(array $boundary): void
    {
        $this->boundary = $boundary;

        // Calculate center from polygon
        if (isset($boundary['coordinates'][0])) {
            $coords = $boundary['coordinates'][0];
            $lats = array_column($coords, 1);
            $lngs = array_column($coords, 0);
            $this->centerLat = array_sum($lats) / count($lats);
            $this->centerLng = array_sum($lngs) / count($lngs);
        }
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'description', 'color', 'boundary', 'assignedUserId', 'centerLat', 'centerLng', 'editingId', 'showForm']);
        $this->color = '#3B82F6';
    }
};

?>

<div>
    <div class="mx-auto max-w-7xl py-6 space-y-6">
        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Territory Manager') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Create and manage sales territories, draw boundaries, and dispatch to reps.') }}</flux:text>
            </div>
            @can('sales_territories.manage')
                <flux:button variant="primary" wire:click="createTerritory">
                    <flux:icon name="plus" class="size-4 mr-1" />
                    {{ __('New Territory') }}
                </flux:button>
            @endcan
        </div>

        {{-- Tab Navigation --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-4" aria-label="Tabs">
                <button wire:click="$set('activeTab', 'map')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'map' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Map
                </button>
                <button wire:click="$set('activeTab', 'hierarchy')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'hierarchy' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Hierarchy
                </button>
                @can('territories.dispatch_blocks')
                <button wire:click="$set('activeTab', 'dispatch')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'dispatch' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Dispatch
                </button>
                @endcan
                @can('analytics.territory_pnl')
                <button wire:click="$set('activeTab', 'analytics')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'analytics' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Analytics
                </button>
                @endcan
            </nav>
        </div>

        {{-- Tab Content --}}
        @if($activeTab === 'map')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Map Column --}}
            <div class="lg:col-span-2">
                <div
                    wire:ignore
                    x-data="territoryMap({
                        draw: {{ Auth::user()->can('sales_territories.manage') ? 'true' : 'false' }},
                        geolocate: true,
                        zoom: {{ count($this->territoryPolygons) > 0 ? 10 : 5 }},
                        center: {{ json_encode($this->mapCenter) }},
                        polygons: {{ json_encode($this->territoryPolygons) }}
                    })"
                    x-on:polygon-created.window="$wire.setBoundary($event.detail.boundary)"
                    class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden relative"
                >
                    {{-- My Location Button --}}
                    <div class="absolute top-3 right-3 z-[1000]">
                        <button
                            type="button"
                            x-on:click="requestGeolocation(true)"
                            class="flex items-center gap-1.5 rounded-lg bg-white dark:bg-zinc-800 px-3 py-2 text-sm font-medium shadow-md border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors"
                            :class="{
                                'text-blue-600': gpsStatus === 'granted',
                                'text-red-500': gpsStatus === 'denied',
                                'text-amber-500 animate-pulse': gpsStatus === 'requesting',
                                'text-zinc-600 dark:text-zinc-300': gpsStatus === 'idle'
                            }"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            <span x-text="{
                                idle: '{{ __('My Location') }}',
                                requesting: '{{ __('Getting GPS...') }}',
                                granted: '{{ __('My Location') }}',
                                denied: '{{ __('GPS Denied') }}',
                                unavailable: '{{ __('GPS Unavailable') }}'
                            }[gpsStatus]"></span>
                        </button>
                    </div>

                    <div x-ref="map" class="h-[500px] w-full"></div>
                </div>

                @if(count($this->territoryPolygons) === 0)
                    <flux:text size="sm" class="mt-2 text-zinc-400 text-center">
                        {{ __('No territory boundaries drawn yet. Click "New Territory" and use the polygon tool on the map to draw one.') }}
                    </flux:text>
                @endif
            </div>

            {{-- Territory List / Form Column --}}
            <div class="space-y-4">
                @if($showForm)
                    {{-- Territory Form --}}
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-4">
                        <flux:heading size="lg">
                            {{ $editingId ? __('Edit Territory') : __('New Territory') }}
                        </flux:heading>

                        <flux:field>
                            <flux:label>{{ __('Name') }}</flux:label>
                            <flux:input wire:model="name" placeholder="{{ __('e.g. Downtown East') }}" />
                            @error('name') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Description') }}</flux:label>
                            <flux:textarea wire:model="description" rows="2" placeholder="{{ __('Optional notes...') }}" />
                        </flux:field>

                        <div class="grid grid-cols-2 gap-3">
                            <flux:field>
                                <flux:label>{{ __('Color') }}</flux:label>
                                <input type="color" wire:model="color" class="h-10 w-full rounded-lg border border-zinc-300 dark:border-zinc-600 cursor-pointer" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Assign To') }}</flux:label>
                                <flux:select wire:model="assignedUserId">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach($this->internalUsers as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                        </div>

                        @if($boundary)
                            <flux:badge color="green" size="sm">
                                <flux:icon name="check" class="size-3 mr-1" />
                                {{ __('Boundary drawn on map') }}
                            </flux:badge>
                        @else
                            <flux:callout variant="outline" icon="information-circle">
                                <flux:callout.text>{{ __('Use the polygon draw tool (top-left of map) to draw the territory boundary.') }}</flux:callout.text>
                            </flux:callout>
                        @endif

                        <div class="flex gap-2">
                            <flux:button variant="primary" wire:click="saveTerritory" class="grow">
                                {{ $editingId ? __('Update') : __('Create') }}
                            </flux:button>
                            <flux:button variant="ghost" wire:click="$set('showForm', false)">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </div>
                @endif

                {{-- Territory List --}}
                <div class="space-y-2">
                    @foreach($this->territories as $territory)
                        <div wire:key="territory-{{ $territory->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-4 w-4 rounded-full shrink-0" style="background-color: {{ $territory->color }}"></div>
                                    <div>
                                        <flux:heading size="sm">{{ $territory->name }}</flux:heading>
                                        <flux:text size="sm">
                                            {{ $territory->door_knocks_count }} {{ __('knocks') }}
                                            @if($territory->assignedUser)
                                                &middot; {{ $territory->assignedUser->name }}
                                            @endif
                                            @if($territory->status === 'archived')
                                                &middot; <span class="text-amber-500">{{ __('Archived') }}</span>
                                            @endif
                                        </flux:text>
                                    </div>
                                </div>

                                @can('sales_territories.manage')
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item wire:click="editTerritory({{ $territory->id }})" icon="pencil">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            @can('territory_dispatch.manage')
                                                <flux:menu.item wire:click="openDispatch({{ $territory->id }})" icon="paper-airplane">
                                                    {{ __('Dispatch') }}
                                                </flux:menu.item>
                                            @endcan
                                            <flux:menu.separator />
                                            <flux:menu.item wire:click="archiveTerritory({{ $territory->id }})" icon="archive-box" wire:confirm="{{ __('Archive this territory?') }}">
                                                {{ __('Archive') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="deleteTerritory({{ $territory->id }})" icon="trash" variant="danger" wire:confirm="{{ __('Permanently delete this territory and all related data?') }}">
                                                {{ __('Delete') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcan
                            </div>
                        </div>
                    @endforeach

                    @if($this->territories->isEmpty())
                        <flux:callout icon="map" variant="outline">
                            <flux:callout.heading>{{ __('No Territories') }}</flux:callout.heading>
                            <flux:callout.text>{{ __('Create your first territory to start managing sales areas.') }}</flux:callout.text>
                        </flux:callout>
                    @endif
                </div>
            </div>
        </div>
        @elseif($activeTab === 'hierarchy')
            <livewire:sales.territory-hierarchy />
        @elseif($activeTab === 'dispatch')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Block Dispatch</flux:heading>
                </div>

                <div class="grid gap-3">
                    @forelse($this->dispatchableBlocks as $block)
                        <div wire:key="dispatch-{{ $block->id }}" class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <div>
                                <p class="font-medium text-sm">{{ $block->name }}</p>
                                <p class="text-xs text-zinc-500">{{ $block->parent?->name ?? 'No zone' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($block->assignedUser)
                                    <flux:badge size="sm">{{ $block->assignedUser->name }}</flux:badge>
                                @else
                                    <flux:badge variant="warning" size="sm">Unassigned</flux:badge>
                                @endif
                                <flux:button size="xs" wire:click="openDispatch({{ $block->id }})">Dispatch</flux:button>
                            </div>
                        </div>
                    @empty
                        <p class="text-center py-8 text-zinc-500">No blocks available for dispatch.</p>
                    @endforelse
                </div>
            </div>
        @elseif($activeTab === 'analytics')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Territory P&L</flux:heading>
                    <flux:select wire:model.live="pnlPeriod" class="w-32">
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="quarter">This Quarter</option>
                        <option value="year">This Year</option>
                    </flux:select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse($this->pnlData as $pnl)
                        <div wire:key="pnl-{{ $pnl['territory_id'] }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="font-semibold text-sm">{{ $pnl['territory_name'] }}</h3>
                                <flux:badge size="sm" variant="{{ $pnl['within_budget'] ? 'success' : 'danger' }}">
                                    {{ $pnl['budget_pct'] }}%
                                </flux:badge>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span class="text-zinc-500">Revenue</span>
                                    <p class="font-medium">${{ number_format($pnl['revenue'], 0) }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500">Commissions</span>
                                    <p class="font-medium">${{ number_format($pnl['commission_costs'], 0) }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500">Marketing</span>
                                    <p class="font-medium">${{ number_format($pnl['marketing_costs'], 0) }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500">Margin</span>
                                    <p class="font-medium {{ $pnl['margin'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                        \${{ number_format($pnl['margin'], 0) }}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs text-zinc-500 mb-1">
                                    <span>Commission Budget</span>
                                    <span>{{ $pnl['budget_pct'] }}% / {{ $pnl['budget_target_pct'] }}%</span>
                                </div>
                                <div class="h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all {{ $pnl['within_budget'] ? 'bg-green-500' : 'bg-red-500' }}"
                                         style="width: {{ min($pnl['budget_pct'] / max($pnl['budget_target_pct'], 1) * 100, 100) }}%"></div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-center py-8 text-zinc-500 col-span-full">No territory data for this period.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    {{-- Dispatch Modal --}}
    @can('territory_dispatch.manage')
    <flux:modal wire:model="showDispatchModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Dispatch Territory') }}</flux:heading>
            <flux:text>{{ __('Send this territory as an offer to eligible sales reps. They will receive a real-time notification and can accept or decline.') }}</flux:text>

            <flux:field>
                <flux:label>{{ __('Work Date') }}</flux:label>
                <flux:input type="date" wire:model="dispatchDate" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Offer Expiry (minutes)') }}</flux:label>
                <flux:input type="number" wire:model="dispatchExpiry" min="5" max="120" />
                <flux:text size="sm" class="text-zinc-400">{{ __('Offer auto-expires after this many minutes.') }}</flux:text>
            </flux:field>

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" wire:click="dispatchTerritory" class="grow">
                    <flux:icon name="paper-airplane" class="size-4 mr-1" />
                    {{ __('Dispatch Now') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showDispatchModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @endcan

    @assets
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js" crossorigin=""></script>
    @endassets
</div>
