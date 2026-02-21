<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
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

    #[Validate('required|in:region,zone,block')]
    public string $level = 'block';

    public ?int $parentId = null;

    public ?array $boundary = null;

    public ?int $assignedUserId = null;

    public ?float $centerLat = null;

    public ?float $centerLng = null;

    // UI state
    public string $activeTab = 'map';
    public string $pnlPeriod = 'month';
    public array $visibleLevels = ['region', 'zone', 'block'];

    public ?int $editingId = null;

    public bool $showForm = false;

    public function mount(?SalesTerritory $territory = null): void
    {
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

        if (count($this->visibleLevels) < 3) {
            $query->whereIn('level', $this->visibleLevels);
        }

        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->with('assignedUser')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'color' => $t->color ?? '#3B82F6',
            'boundary' => $t->boundary,
            'level' => $t->level,
            'assigned_user' => $t->assignedUser?->name,
            'level_label' => match ($t->level) {
                'region' => __('Region'),
                'zone' => __('Zone'),
                'block' => __('Block'),
                default => ucfirst($t->level),
            },
        ])->toArray();
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

    #[Computed]
    public function mapBounds(): ?array
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);

        $territories = SalesTerritory::whereIn('id', $visibleIds)
            ->whereNotNull('boundary')
            ->where('status', 'active')
            ->get(['boundary']);

        if ($territories->isEmpty()) {
            return null;
        }

        $allLats = [];
        $allLngs = [];
        foreach ($territories as $t) {
            if (isset($t->boundary['coordinates'][0])) {
                foreach ($t->boundary['coordinates'][0] as $coord) {
                    $allLngs[] = $coord[0];
                    $allLats[] = $coord[1];
                }
            }
        }

        if (empty($allLats)) {
            return null;
        }

        return [
            [min($allLats), min($allLngs)],
            [max($allLats), max($allLngs)],
        ];
    }

    #[Computed]
    public function availableParents(): \Illuminate\Support\Collection
    {
        $parentLevel = match ($this->level) {
            'zone' => 'region',
            'block' => 'zone',
            default => null,
        };

        if (! $parentLevel) {
            return collect();
        }

        return SalesTerritory::where('level', $parentLevel)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'level']);
    }

    #[Computed]
    public function hierarchicalTerritories(): \Illuminate\Support\Collection
    {
        return SalesTerritory::where('level', 'region')
            ->where('status', 'active')
            ->withCount('doorKnocks')
            ->with(['children' => function ($q) {
                $q->where('status', 'active')
                    ->withCount('doorKnocks')
                    ->with(['children' => function ($q2) {
                        $q2->where('status', 'active')
                            ->withCount('doorKnocks')
                            ->with('assignedUser')
                            ->orderBy('name');
                    }, 'assignedUser'])
                    ->orderBy('name');
            }, 'assignedUser'])
            ->orderBy('name')
            ->get();
    }

    public function parentBoundary(): ?array
    {
        if (! $this->parentId) {
            return null;
        }

        $parent = SalesTerritory::find($this->parentId);
        if (! $parent || ! $parent->boundary) {
            return null;
        }

        return [
            'name' => $parent->name,
            'color' => $parent->color ?? '#6B7280',
            'boundary' => $parent->boundary,
        ];
    }

    public function updatedLevel(): void
    {
        $this->parentId = null;
        unset($this->availableParents);
        $this->dispatch('show-parent-context', boundary: null);
    }

    public function toggleLevel(string $level): void
    {
        if (in_array($level, $this->visibleLevels)) {
            $this->visibleLevels = array_values(array_diff($this->visibleLevels, [$level]));
        } else {
            $this->visibleLevels[] = $level;
        }
        unset($this->territoryPolygons);
        $this->dispatch('territory-polygons', polygons: $this->territoryPolygons);
    }

    public function updatedParentId(): void
    {
        $this->dispatch('show-parent-context', boundary: $this->parentBoundary());
    }

    public function updatedActiveTab(string $value): void
    {
        if ($value === 'hierarchy') {
            $user = auth()->user();
            if (! $user->can('territories.manage_regions')
                && ! $user->can('territories.manage_zones')
                && ! $user->can('territories.assign_team')
            ) {
                $this->activeTab = 'map';
            }
        }
    }

    public function canOnlyManageBlocks(): bool
    {
        $user = auth()->user();

        return $user->can('territories.manage_blocks')
            && ! $user->can('sales_territories.manage')
            && ! $user->can('territories.manage_regions')
            && ! $user->can('territories.manage_zones');
    }

    public function createTerritory(): void
    {
        $this->resetForm();

        if ($this->canOnlyManageBlocks()) {
            $this->level = 'block';
            // Auto-set parent to user's primary zone
            $user = auth()->user();
            $scopeService = app(PermissionScopeService::class);
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $zone = SalesTerritory::whereIn('id', $visibleIds)
                ->where('level', 'zone')
                ->where('status', 'active')
                ->first();
            if ($zone) {
                $this->parentId = $zone->id;
                $this->dispatch('show-parent-context', boundary: $this->parentBoundary());
            }
        }

        $this->showForm = true;
    }

    public function editTerritory(int $id): void
    {
        $territory = SalesTerritory::findOrFail($id);
        $this->editingId = $id;
        $this->name = $territory->name;
        $this->description = $territory->description;
        $this->color = $territory->color;
        $this->level = $territory->level ?? 'block';
        $this->parentId = $territory->parent_id;
        $this->boundary = $territory->boundary;
        $this->assignedUserId = $territory->assigned_user_id;
        $this->centerLat = $territory->center_lat;
        $this->centerLng = $territory->center_lng;
        $this->showForm = true;

        unset($this->availableParents);

        if ($this->parentId) {
            $this->dispatch('show-parent-context', boundary: $this->parentBoundary());
        }
    }

    public function saveTerritory(): void
    {
        // Enforce block level for block-only users
        if ($this->canOnlyManageBlocks()) {
            $this->level = 'block';
        }

        $this->validate();

        // Validate parent requirement based on level
        if (in_array($this->level, ['zone', 'block'])) {
            $this->validate(['parentId' => 'required|exists:sales_territories,id'], [
                'parentId.required' => __('A parent territory is required for :level level.', ['level' => $this->level]),
            ]);

            // Validate parent is the correct level
            $parent = SalesTerritory::find($this->parentId);
            $expectedParentLevel = $this->level === 'zone' ? 'region' : 'zone';
            if ($parent && $parent->level !== $expectedParentLevel) {
                $this->addError('parentId', __('Parent must be a :level.', ['level' => $expectedParentLevel]));

                return;
            }
        }

        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'level' => $this->level,
            'parent_id' => in_array($this->level, ['zone', 'block']) ? $this->parentId : null,
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
        unset($this->territories, $this->territoryPolygons, $this->hierarchicalTerritories);
        $this->dispatch('territory-polygons', polygons: $this->territoryPolygons);
    }

    public function deleteTerritory(int $id): void
    {
        SalesTerritory::findOrFail($id)->delete();
        session()->flash('success', __('Territory deleted.'));
        unset($this->territories, $this->territoryPolygons, $this->hierarchicalTerritories);
        $this->dispatch('territory-polygons', polygons: $this->territoryPolygons);
    }

    public function archiveTerritory(int $id): void
    {
        SalesTerritory::findOrFail($id)->update(['status' => 'archived']);
        session()->flash('success', __('Territory archived.'));
        unset($this->territories, $this->hierarchicalTerritories, $this->territoryPolygons);
        $this->dispatch('territory-polygons', polygons: $this->territoryPolygons);
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
        $this->reset(['name', 'description', 'color', 'level', 'parentId', 'boundary', 'assignedUserId', 'centerLat', 'centerLng', 'editingId', 'showForm']);
        $this->color = '#3B82F6';
        $this->level = 'block';
        $this->dispatch('show-parent-context', boundary: null);
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
            @canany(['sales_territories.manage', 'territories.manage_blocks'])
                <flux:button variant="primary" wire:click="createTerritory">
                    <flux:icon name="plus" class="size-4 mr-1" />
                    {{ $this->canOnlyManageBlocks() ? __('New Block') : __('New Territory') }}
                </flux:button>
            @endcanany
        </div>

        {{-- Tab Navigation --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-4" aria-label="Tabs">
                <button wire:click="$set('activeTab', 'map')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'map' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Map
                </button>
                @canany(['territories.manage_regions', 'territories.manage_zones', 'territories.assign_team'])
                <button wire:click="$set('activeTab', 'hierarchy')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'hierarchy' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Hierarchy
                </button>
                @endcanany
                @canany(['territories.manage_zones', 'territories.dispatch_blocks'])
                <button wire:click="$set('activeTab', 'schedule')"
                    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'schedule' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                    Schedule
                </button>
                @endcanany
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
                {{-- Level Filter Checkboxes --}}
                <div class="flex items-center gap-3 mb-3">
                    <flux:text size="sm" class="text-zinc-500 mr-1">{{ __('Show:') }}</flux:text>
                    @foreach(['region' => __('Regions'), 'zone' => __('Zones'), 'block' => __('Blocks')] as $value => $label)
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:click="toggleLevel('{{ $value }}')"
                                {{ in_array($value, $visibleLevels) ? 'checked' : '' }}
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                            />
                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div
                    wire:ignore
                    x-data="territoryMap({
                        draw: {{ Auth::user()->canAny(['sales_territories.manage', 'territories.manage_blocks']) ? 'true' : 'false' }},
                        geolocate: {{ $this->mapBounds ? 'false' : 'true' }},
                        zoom: {{ $this->mapBounds ? 12 : 5 }},
                        center: {{ json_encode($this->mapCenter) }},
                        bounds: {{ json_encode($this->mapBounds) }},
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

                        <div class="grid grid-cols-2 gap-3">
                            <flux:field>
                                <flux:label>{{ __('Level') }}</flux:label>
                                @if($this->canOnlyManageBlocks())
                                    <flux:input value="{{ __('Block') }}" disabled />
                                @else
                                    <flux:select wire:model.live="level">
                                        <option value="region">{{ __('Region') }}</option>
                                        <option value="zone">{{ __('Zone') }}</option>
                                        <option value="block">{{ __('Block') }}</option>
                                    </flux:select>
                                @endif
                                @error('level') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                            </flux:field>

                            @if($level !== 'region')
                                <flux:field>
                                    <flux:label>{{ $level === 'zone' ? __('Parent Region') : __('Parent Zone') }}</flux:label>
                                    <flux:select wire:model.live="parentId">
                                        <option value="">{{ __('Select...') }}</option>
                                        @foreach($this->availableParents as $parent)
                                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                        @endforeach
                                    </flux:select>
                                    @error('parentId') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                                </flux:field>
                            @endif
                        </div>

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

                        <div class="flex items-center gap-2">
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

                            @if($parentId && $this->parentBoundary())
                                <flux:badge color="sky" size="sm">
                                    <flux:icon name="map" class="size-3 mr-1" />
                                    {{ __('Parent shown on map') }}
                                </flux:badge>
                            @endif
                        </div>

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

                {{-- Territory Hierarchy Tree --}}
                <div class="space-y-1">
                    @forelse($this->hierarchicalTerritories as $region)
                        <div wire:key="region-{{ $region->id }}" x-data="{ open: true }">
                            {{-- Region --}}
                            <div class="rounded-lg border border-purple-200 dark:border-purple-800/50 bg-purple-50/50 dark:bg-purple-900/10 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2 cursor-pointer" x-on:click="open = !open">
                                        <svg class="size-4 text-zinc-400 transition-transform" :class="open && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                        <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $region->color }}"></div>
                                        <div>
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-sm font-semibold">{{ $region->name }}</span>
                                                <flux:badge size="sm" color="purple">{{ __('Region') }}</flux:badge>
                                            </div>
                                            <flux:text size="xs">{{ $region->door_knocks_count }} {{ __('knocks') }}@if($region->assignedUser) &middot; {{ $region->assignedUser->name }}@endif</flux:text>
                                        </div>
                                    </div>
                                    @can('sales_territories.manage')
                                        <flux:dropdown>
                                            <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" />
                                            <flux:menu>
                                                <flux:menu.item wire:click="editTerritory({{ $region->id }})" icon="pencil">{{ __('Edit') }}</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item wire:click="archiveTerritory({{ $region->id }})" icon="archive-box" wire:confirm="{{ __('Archive this territory?') }}">{{ __('Archive') }}</flux:menu.item>
                                                <flux:menu.item wire:click="deleteTerritory({{ $region->id }})" icon="trash" variant="danger" wire:confirm="{{ __('Permanently delete this territory and all related data?') }}">{{ __('Delete') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endcan
                                </div>
                            </div>

                            {{-- Zones --}}
                            <div x-show="open" x-collapse class="ml-4 mt-1 space-y-1">
                                @foreach($region->children as $zone)
                                    <div wire:key="zone-{{ $zone->id }}" x-data="{ zoneOpen: true }">
                                        <div class="rounded-lg border border-sky-200 dark:border-sky-800/50 bg-sky-50/50 dark:bg-sky-900/10 p-2.5">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="flex items-center gap-2 cursor-pointer" x-on:click="zoneOpen = !zoneOpen">
                                                    <svg class="size-3.5 text-zinc-400 transition-transform" :class="zoneOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                                    <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $zone->color }}"></div>
                                                    <div>
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="text-sm font-medium">{{ $zone->name }}</span>
                                                            <flux:badge size="sm" color="sky">{{ __('Zone') }}</flux:badge>
                                                        </div>
                                                        <flux:text size="xs">{{ $zone->door_knocks_count }} {{ __('knocks') }}@if($zone->assignedUser) &middot; {{ $zone->assignedUser->name }}@endif</flux:text>
                                                    </div>
                                                </div>
                                                @can('sales_territories.manage')
                                                    <flux:dropdown>
                                                        <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" />
                                                        <flux:menu>
                                                            <flux:menu.item wire:click="editTerritory({{ $zone->id }})" icon="pencil">{{ __('Edit') }}</flux:menu.item>
                                                            <flux:menu.separator />
                                                            <flux:menu.item wire:click="archiveTerritory({{ $zone->id }})" icon="archive-box" wire:confirm="{{ __('Archive this territory?') }}">{{ __('Archive') }}</flux:menu.item>
                                                            <flux:menu.item wire:click="deleteTerritory({{ $zone->id }})" icon="trash" variant="danger" wire:confirm="{{ __('Permanently delete this territory and all related data?') }}">{{ __('Delete') }}</flux:menu.item>
                                                        </flux:menu>
                                                    </flux:dropdown>
                                                @endcan
                                            </div>
                                        </div>

                                        {{-- Blocks --}}
                                        <div x-show="zoneOpen" x-collapse class="ml-4 mt-1 space-y-1">
                                            @foreach($zone->children as $block)
                                                <div wire:key="block-{{ $block->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2.5">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <div class="flex items-center gap-2">
                                                            <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $block->color }}"></div>
                                                            <div>
                                                                <div class="flex items-center gap-1.5">
                                                                    <span class="text-sm">{{ $block->name }}</span>
                                                                    <flux:badge size="sm">{{ __('Block') }}</flux:badge>
                                                                </div>
                                                                <flux:text size="xs">{{ $block->door_knocks_count }} {{ __('knocks') }}@if($block->assignedUser) &middot; {{ $block->assignedUser->name }}@endif</flux:text>
                                                            </div>
                                                        </div>
                                                        @can('sales_territories.manage')
                                                            <flux:dropdown>
                                                                <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" />
                                                                <flux:menu>
                                                                    <flux:menu.item wire:click="editTerritory({{ $block->id }})" icon="pencil">{{ __('Edit') }}</flux:menu.item>
                                                                    @can('territory_dispatch.manage')
                                                                        <flux:menu.item wire:click="openDispatch({{ $block->id }})" icon="paper-airplane">{{ __('Dispatch') }}</flux:menu.item>
                                                                    @endcan
                                                                    <flux:menu.separator />
                                                                    <flux:menu.item wire:click="archiveTerritory({{ $block->id }})" icon="archive-box" wire:confirm="{{ __('Archive this territory?') }}">{{ __('Archive') }}</flux:menu.item>
                                                                    <flux:menu.item wire:click="deleteTerritory({{ $block->id }})" icon="trash" variant="danger" wire:confirm="{{ __('Permanently delete this territory and all related data?') }}">{{ __('Delete') }}</flux:menu.item>
                                                                </flux:menu>
                                                            </flux:dropdown>
                                                        @endcan
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        @if($this->territories->isEmpty())
                            <flux:callout icon="map" variant="outline">
                                <flux:callout.heading>{{ __('No Territories') }}</flux:callout.heading>
                                <flux:callout.text>{{ __('Create your first territory to start managing sales areas.') }}</flux:callout.text>
                            </flux:callout>
                        @endif
                    @endforelse

                    {{-- Orphan territories (no parent, not regions) --}}
                    @php
                        $orphans = $this->territories->filter(fn($t) => $t->level !== 'region' && !$t->parent_id);
                    @endphp
                    @if($orphans->isNotEmpty())
                        <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:text size="xs" class="text-zinc-400 mb-2">{{ __('Unassigned Territories') }}</flux:text>
                            @foreach($orphans as $orphan)
                                <div wire:key="orphan-{{ $orphan->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2.5 mb-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $orphan->color }}"></div>
                                            <div>
                                                <span class="text-sm">{{ $orphan->name }}</span>
                                                <flux:text size="xs">{{ $orphan->door_knocks_count }} {{ __('knocks') }}</flux:text>
                                            </div>
                                        </div>
                                        @can('sales_territories.manage')
                                            <flux:button variant="ghost" size="xs" icon="pencil" wire:click="editTerritory({{ $orphan->id }})" />
                                        @endcan
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @elseif($activeTab === 'hierarchy')
            <livewire:sales.territory-hierarchy />
        @elseif($activeTab === 'schedule')
            <livewire:sales.territory-schedule />
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

    @assets
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js" crossorigin=""></script>
    @endassets
</div>
