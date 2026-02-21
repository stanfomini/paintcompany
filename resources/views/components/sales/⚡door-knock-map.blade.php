<?php

use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Services\DoorKnockService;
use App\Services\LocationIQService;
use App\Services\PermissionScopeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    // Map state
    public ?float $lat = null;
    public ?float $lng = null;
    public bool $pinDropped = false;

    // Knock form
    #[Validate('required|string|max:500')]
    public string $address = '';
    public ?string $city = null;
    public ?string $state = null;
    public ?string $zip = null;

    #[Validate('required|in:not_home,interested,not_interested,appointment_set,sold,come_back')]
    public string $status = 'not_home';

    public ?string $customerName = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $notes = null;

    // Filter state
    public ?int $filterTerritory = null;
    public bool $showForm = false;

    // Viewport bounds for marker loading
    public ?float $neLat = null;
    public ?float $neLng = null;
    public ?float $swLat = null;
    public ?float $swLng = null;

    public function mount(): void
    {
        //
    }

    /**
     * Smart default center for the map.
     * Priority: user's claimed territory center > first active territory > US center.
     */
    #[Computed]
    public function mapCenter(): array
    {
        // Check if user has a claimed territory for today with coordinates
        $claimed = \App\Models\TerritoryOffer::query()
            ->where('claimed_by', Auth::id())
            ->where('status', 'claimed')
            ->whereDate('work_date', today())
            ->with('territory')
            ->first();

        if ($claimed?->territory?->center_lat && $claimed?->territory?->center_lng) {
            return [(float) $claimed->territory->center_lat, (float) $claimed->territory->center_lng];
        }

        // Fall back to first active territory with coordinates
        $territory = SalesTerritory::active()
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->first();

        if ($territory) {
            return [(float) $territory->center_lat, (float) $territory->center_lng];
        }

        return [39.8283, -98.5795]; // US center fallback
    }

    #[Computed]
    public function territories()
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::active()->orderBy('name');

        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->get();
    }

    /**
     * Territory polygons for rendering on the map.
     */
    #[Computed]
    public function territoryPolygons(): array
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::active()->whereNotNull('boundary');

        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
                'boundary' => $t->boundary,
                'level' => $t->level,
            ])->toArray();
    }

    #[Computed]
    public function todayStats(): array
    {
        $user = auth()->user();
        $today = now()->startOfDay();

        $knocks = DoorKnock::where('user_id', $user->id)
            ->where('knocked_at', '>=', $today)
            ->get();

        return [
            'total' => $knocks->count(),
            'appointments' => $knocks->where('status', 'appointment_set')->count(),
            'interested' => $knocks->where('status', 'interested')->count(),
            'not_home' => $knocks->where('status', 'not_home')->count(),
            'conversion' => $knocks->count() > 0
                ? round(($knocks->whereIn('status', ['interested', 'appointment_set', 'sold'])->count() / $knocks->count()) * 100)
                : 0,
        ];
    }

    #[Computed]
    public function knockMarkers(): array
    {
        if (! $this->neLat || ! $this->swLat) {
            return [];
        }

        $knocks = DoorKnock::query()
            ->inBounds($this->neLat, $this->neLng, $this->swLat, $this->swLng)
            ->when($this->filterTerritory, fn ($q) => $q->where('territory_id', $this->filterTerritory))
            ->with('user:id,name')
            ->latest('knocked_at')
            ->limit(300)
            ->get();

        return $knocks->map(fn ($k) => [
            'id' => $k->id,
            'lat' => (float) $k->lat,
            'lng' => (float) $k->lng,
            'color' => DoorKnock::STATUS_COLORS[$k->status] ?? '#9CA3AF',
            'popup' => '<strong>' . e($k->address) . '</strong><br>'
                . '<span style="color:' . (DoorKnock::STATUS_COLORS[$k->status] ?? '#9CA3AF') . '">'
                . (DoorKnock::STATUSES[$k->status] ?? $k->status)
                . '</span><br>'
                . '<small>' . e($k->user?->name) . ' &middot; ' . $k->knocked_at->diffForHumans() . '</small>'
                . ($k->customer_name ? '<br><small>' . e($k->customer_name) . '</small>' : ''),
        ])->toArray();
    }

    public function updateViewport(float $neLat, float $neLng, float $swLat, float $swLng): void
    {
        $this->neLat = $neLat;
        $this->neLng = $neLng;
        $this->swLat = $swLat;
        $this->swLng = $swLng;

        unset($this->knockMarkers);
        $this->dispatch('markers-updated', markers: $this->knockMarkers);
    }

    /**
     * Reverse-geocode a location without dropping a pin (for stop detection suggestions).
     */
    public function detectAddress(float $lat, float $lng): void
    {
        try {
            $locationService = app(LocationIQService::class);
            $result = $locationService->getCachedOrFetch($lat, $lng);
            $address = $result['address'] ?? '';
            $this->dispatch('address-detected', address: $address);
        } catch (\Exception $e) {
            $this->dispatch('address-detected', address: __('Address unavailable'));
        }
    }

    public function dropPin(float $lat, float $lng): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->pinDropped = true;
        $this->showForm = true;

        // Auto reverse-geocode
        try {
            $locationService = app(LocationIQService::class);
            $result = $locationService->getCachedOrFetch($lat, $lng);

            if ($result) {
                $this->address = $result['address'] ?? '';
                $this->city = $result['city'] ?? null;
                $this->state = $result['state'] ?? null;
                $this->zip = $result['zip'] ?? null;
            }
        } catch (\Exception $e) {
            // Geocoding failed - user can type address manually
        }

        // Tell Alpine to place/move the pin marker
        $this->dispatch('pin-placed', lat: $lat, lng: $lng);
    }

    public function saveKnock(): void
    {
        $this->validate();

        $service = app(DoorKnockService::class);

        $service->recordKnock(Auth::user(), [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'status' => $this->status,
            'customer_name' => $this->customerName,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
        ]);

        session()->flash('success', __('Knock recorded!'));

        $this->resetKnockForm();
        unset($this->knockMarkers);
        $this->dispatch('markers-updated', markers: $this->knockMarkers);
    }

    public function convertToLead(int $knockId): void
    {
        $knock = DoorKnock::findOrFail($knockId);
        $service = app(DoorKnockService::class);

        $deal = $service->convertToLead($knock);
        session()->flash('success', __('Lead created! Deal #:id', ['id' => $deal->id]));
    }

    private function resetKnockForm(): void
    {
        $this->reset(['lat', 'lng', 'pinDropped', 'showForm', 'address', 'city', 'state', 'zip', 'status', 'customerName', 'phone', 'email', 'notes']);
        $this->status = 'not_home';
    }
};

?>

<div>
    <div class="mx-auto max-w-7xl py-6 space-y-4">
        {{-- Header --}}
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <flux:heading size="xl">{{ __('Door Knocks') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Record and track door-to-door sales visits.') }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                {{-- Territory Filter --}}
                <flux:select wire:model.live="filterTerritory" class="w-48">
                    <option value="">{{ __('All Territories') }}</option>
                    @foreach($this->territories as $territory)
                        <option value="{{ $territory->id }}">{{ $territory->name }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- Today's Stats Bar --}}
        <div class="flex items-center gap-2 sm:gap-4 px-2 sm:px-3 py-1.5 sm:py-2 bg-zinc-50 dark:bg-zinc-800 rounded-lg text-xs sm:text-sm">
            <span class="font-medium">Today:</span>
            <span>{{ $this->todayStats['total'] }} knocks</span>
            <span class="text-green-600">{{ $this->todayStats['appointments'] }} appts</span>
            <span class="text-blue-600 hidden sm:inline">{{ $this->todayStats['interested'] }} interested</span>
            <span>{{ $this->todayStats['conversion'] }}%</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Map --}}
            <div class="lg:col-span-2">
                <div
                    wire:ignore
                    x-data="territoryMap({
                        clickToPlace: true,
                        trackLocation: true,
                        zoom: {{ count($this->territoryPolygons) > 0 ? 12 : 14 }},
                        center: {{ json_encode($this->mapCenter) }},
                        polygons: {{ json_encode($this->territoryPolygons) }}
                    })"
                    x-on:map-clicked.window="$wire.dropPin($event.detail.lat, $event.detail.lng)"
                    x-on:viewport-changed.window="$wire.updateViewport($event.detail.neLat, $event.detail.neLng, $event.detail.swLat, $event.detail.swLng)"
                    class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden relative"
                >
                    {{-- Tracking Controls --}}
                    <div class="absolute top-3 right-3 z-[1000] flex items-center gap-2">
                        {{-- Re-center button (shown when follow mode is off) --}}
                        <button
                            x-show="!followMode && gpsStatus === 'granted'"
                            x-cloak
                            type="button"
                            x-on:click="recenter()"
                            class="flex items-center gap-1.5 rounded-lg bg-white dark:bg-zinc-800 px-3 py-2 text-sm font-medium shadow-md border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors text-blue-600"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25" />
                            </svg>
                            {{ __('Re-center') }}
                        </button>

                        {{-- Tracking toggle --}}
                        <button
                            type="button"
                            x-on:click="watchId !== null ? stopTracking() : startTracking()"
                            class="flex items-center gap-1.5 rounded-lg bg-white dark:bg-zinc-800 px-3 py-2 text-sm font-medium shadow-md border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors"
                            :class="{
                                'text-green-600': gpsStatus === 'granted' && watchId !== null,
                                'text-red-500': gpsStatus === 'denied',
                                'text-amber-500 animate-pulse': gpsStatus === 'requesting',
                                'text-zinc-600 dark:text-zinc-300': gpsStatus === 'idle' || watchId === null
                            }"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            <span x-text="
                                gpsStatus === 'denied' ? '{{ __('GPS Denied') }}'
                                : gpsStatus === 'unavailable' ? '{{ __('GPS Unavailable') }}'
                                : gpsStatus === 'requesting' ? '{{ __('Getting GPS...') }}'
                                : watchId !== null ? '{{ __('Tracking') }}'
                                : '{{ __('Start Tracking') }}'
                            "></span>
                            <span x-show="watchId !== null && gpsStatus === 'granted'" class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                            </span>
                        </button>
                    </div>

                    {{-- GPS Denied Help Banner --}}
                    <div
                        x-show="gpsStatus === 'denied'"
                        x-cloak
                        class="absolute bottom-0 left-0 right-0 z-[1000] bg-amber-50 dark:bg-amber-900/50 border-t border-amber-200 dark:border-amber-700 px-4 py-2 text-sm text-amber-800 dark:text-amber-200"
                    >
                        {{ __('Location access was denied. You can still tap the map to place pins, or enable location in your browser settings.') }}
                    </div>

                    {{-- Stop Detection Toast (address suggestion with confirm) --}}
                    <div
                        x-show="stopDetected"
                        x-cloak
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-2"
                        class="absolute bottom-14 left-3 right-3 z-[1000] bg-blue-50 dark:bg-blue-900/50 border border-blue-200 dark:border-blue-700 rounded-lg px-3 py-2 text-sm text-blue-800 dark:text-blue-200 flex items-center gap-2"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                        </svg>
                        <span x-show="!detectedAddress" class="animate-pulse">{{ __('Detecting address...') }}</span>
                        <span x-show="detectedAddress" x-text="detectedAddress" class="truncate"></span>
                        <button
                            x-show="detectedAddress"
                            x-cloak
                            type="button"
                            x-on:click="confirmStopPin()"
                            class="shrink-0 ml-auto rounded-md bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700 transition-colors"
                        >
                            {{ __('Drop Pin') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="dismissStopDetection()"
                            class="shrink-0 text-blue-400 hover:text-blue-600"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {{-- Too Far Toast (1000-foot radius enforcement) --}}
                    <div
                        x-show="tooFar"
                        x-cloak
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-2"
                        class="absolute bottom-14 left-3 right-3 z-[1000] bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-700 rounded-lg px-4 py-2 text-sm text-red-800 dark:text-red-200 flex items-center gap-2"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        {{ __('Pin must be within 1,000 feet of your location.') }}
                    </div>

                    <div x-ref="map" class="h-[calc(100vh-14rem)] sm:h-[500px] lg:h-[600px] w-full"></div>
                </div>
            </div>

            {{-- Knock Form Panel --}}
            <div>
                @if($showForm && $pinDropped)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-4">
                        <flux:heading size="lg">{{ __('Record Knock') }}</flux:heading>

                        {{-- Address (auto-filled from geocode) --}}
                        <flux:field>
                            <flux:label>{{ __('Address') }}</flux:label>
                            <flux:input wire:model="address" wire:loading.attr="disabled" wire:target="dropPin" placeholder="{{ __('Loading address...') }}" />
                            @error('address') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                        </flux:field>

                        <div class="grid grid-cols-3 gap-2">
                            <flux:field>
                                <flux:label>{{ __('City') }}</flux:label>
                                <flux:input wire:model="city" size="sm" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('State') }}</flux:label>
                                <flux:input wire:model="state" size="sm" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('ZIP') }}</flux:label>
                                <flux:input wire:model="zip" size="sm" />
                            </flux:field>
                        </div>

                        {{-- Status Selection - Big Colored Touch Targets --}}
                        <div>
                            <flux:label class="mb-2">{{ __('Result') }}</flux:label>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach(DoorKnock::STATUSES as $key => $label)
                                    <button
                                        type="button"
                                        wire:click="$set('status', '{{ $key }}')"
                                        class="flex items-center gap-2 rounded-lg border-2 p-3 text-sm font-medium transition-all
                                            {{ $status === $key
                                                ? 'border-current ring-2 ring-offset-1 bg-opacity-10'
                                                : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300' }}"
                                        style="{{ $status === $key ? 'color: ' . DoorKnock::STATUS_COLORS[$key] . '; border-color: ' . DoorKnock::STATUS_COLORS[$key] . '; background-color: ' . DoorKnock::STATUS_COLORS[$key] . '15' : '' }}"
                                    >
                                        <span class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ DoorKnock::STATUS_COLORS[$key] }}"></span>
                                        {{ __($label) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <flux:separator />

                        {{-- Customer Info (optional) --}}
                        <flux:field>
                            <flux:label>{{ __('Customer Name') }}</flux:label>
                            <flux:input wire:model="customerName" placeholder="{{ __('Optional') }}" />
                        </flux:field>

                        <div class="grid grid-cols-2 gap-2">
                            <flux:field>
                                <flux:label>{{ __('Phone') }}</flux:label>
                                <flux:input wire:model="phone" type="tel" placeholder="{{ __('Optional') }}" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Email') }}</flux:label>
                                <flux:input wire:model="email" type="email" placeholder="{{ __('Optional') }}" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>{{ __('Notes') }}</flux:label>
                            <flux:textarea wire:model="notes" rows="2" placeholder="{{ __('Any observations...') }}" />
                        </flux:field>

                        {{-- Coordinates info --}}
                        <flux:text size="sm" class="text-zinc-400">
                            {{ __('Location') }}: {{ number_format($lat, 5) }}, {{ number_format($lng, 5) }}
                        </flux:text>

                        {{-- Save Button --}}
                        <div class="flex gap-2">
                            <flux:button variant="primary" wire:click="saveKnock" class="grow">
                                <flux:icon name="check" class="size-4 mr-1" />
                                {{ __('Save Knock') }}
                            </flux:button>
                            <flux:button variant="ghost" wire:click="$set('showForm', false)">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </div>
                @else
                    {{-- Instructions --}}
                    <flux:callout icon="map-pin" variant="outline">
                        <flux:callout.heading>{{ __('Record a Door Knock') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Walk the neighborhood — the map follows you. Stop at a house for 15 seconds and the pin drops automatically. Or tap the map to place a pin manually.') }}</flux:callout.text>
                    </flux:callout>

                    {{-- Status Legend --}}
                    <div class="mt-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                        <flux:heading size="sm" class="mb-3">{{ __('Pin Colors') }}</flux:heading>
                        <div class="space-y-2">
                            @foreach(DoorKnock::STATUSES as $key => $label)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ DoorKnock::STATUS_COLORS[$key] }}"></span>
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ __($label) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Quick Stats --}}
                    @php
                        $todayCount = \App\Models\DoorKnock::where('user_id', Auth::id())
                            ->whereDate('knocked_at', today())
                            ->count();
                    @endphp
                    @if($todayCount > 0)
                        <div class="mt-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-4">
                            <flux:heading size="sm">{{ __('Today\'s Knocks') }}</flux:heading>
                            <flux:heading size="xl" class="text-green-600 dark:text-green-400">{{ $todayCount }}</flux:heading>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @assets
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js" crossorigin=""></script>
    @endassets
</div>
