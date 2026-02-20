<?php

use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Services\DoorKnockService;
use App\Services\LocationIQService;
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
        return SalesTerritory::active()->orderBy('name')->get();
    }

    /**
     * Territory polygons for rendering on the map.
     */
    #[Computed]
    public function territoryPolygons(): array
    {
        return SalesTerritory::active()
            ->whereNotNull('boundary')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
                'boundary' => $t->boundary,
            ])->toArray();
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Map --}}
            <div class="lg:col-span-2">
                <div
                    wire:ignore
                    x-data="territoryMap({
                        clickToPlace: true,
                        geolocatePin: true,
                        zoom: {{ count($this->territoryPolygons) > 0 ? 12 : 14 }},
                        center: {{ json_encode($this->mapCenter) }},
                        polygons: {{ json_encode($this->territoryPolygons) }}
                    })"
                    x-on:map-clicked.window="$wire.dropPin($event.detail.lat, $event.detail.lng)"
                    x-on:viewport-changed.window="$wire.updateViewport($event.detail.neLat, $event.detail.neLng, $event.detail.swLat, $event.detail.swLng)"
                    class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden relative"
                >
                    {{-- GPS Status Bar --}}
                    <div class="absolute top-3 right-3 z-[1000] flex items-center gap-2">
                        <button
                            type="button"
                            x-on:click="requestGeolocation(false)"
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
                                idle: '{{ __('Drop Pin Here') }}',
                                requesting: '{{ __('Getting GPS...') }}',
                                granted: '{{ __('Drop Pin Here') }}',
                                denied: '{{ __('GPS Denied') }}',
                                unavailable: '{{ __('GPS Unavailable') }}'
                            }[gpsStatus]"></span>
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

                    <div x-ref="map" class="h-[500px] lg:h-[600px] w-full"></div>
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
                        <flux:callout.text>{{ __('Tap the map or use the GPS button to drop a pin at your location. The address will be auto-filled.') }}</flux:callout.text>
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
