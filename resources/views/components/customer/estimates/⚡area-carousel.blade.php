<?php

use App\Events\CustomerContributionAdded;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\PropertyPhoto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Estimate $estimate;

    public int $currentAreaIndex = 0;

    public string $activeTab = 'details';

    /** @var array<int, string> */
    public array $areaCustomerNotes = [];

    /** @var array<int, array<string>> */
    public array $areaCustomerChecklists = [];

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $areaPhotos = [];

    public bool $showGuide = true;

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
            abort(403);
        }

        $this->estimate = $estimate->load(['areas.surfaces.productionRate']);

        foreach ($this->estimate->areas as $area) {
            $this->areaCustomerNotes[$area->id] = $area->customer_notes ?? '';
            $this->areaCustomerChecklists[$area->id] = $area->customer_checklist ?? [];
        }

        $this->showGuide = ! session('area_carousel_guide_dismissed', false);
    }

    #[Computed]
    public function areas()
    {
        return $this->estimate->areas;
    }

    #[Computed]
    public function currentArea()
    {
        return $this->areas[$this->currentAreaIndex] ?? null;
    }

    #[Computed]
    public function totalAreas(): int
    {
        return $this->areas->count();
    }

    #[Computed]
    public function previousAreaName(): ?string
    {
        $prevIndex = $this->currentAreaIndex - 1;

        return $prevIndex >= 0 ? $this->areas[$prevIndex]->name : null;
    }

    #[Computed]
    public function nextAreaName(): ?string
    {
        $nextIndex = $this->currentAreaIndex + 1;

        return $nextIndex < $this->totalAreas ? $this->areas[$nextIndex]->name : null;
    }

    #[Computed]
    public function canAddRooms(): bool
    {
        return $this->estimate->areas()
            ->where('is_customer_added', false)
            ->count() === 0;
    }

    #[Computed]
    public function roomTemplates()
    {
        return EstimateTemplate::query()
            ->active()
            ->configurable()
            ->whereHas('templateCategory', fn ($q) => $q->where('type', 'room'))
            ->orderBy('sort_order')
            ->get();
    }

    public function nextArea(): void
    {
        if ($this->currentAreaIndex < $this->totalAreas - 1) {
            $this->currentAreaIndex++;
            $this->clearAreaComputeds();
        }
    }

    public function previousArea(): void
    {
        if ($this->currentAreaIndex > 0) {
            $this->currentAreaIndex--;
            $this->clearAreaComputeds();
        }
    }

    public function goToArea(int $index): void
    {
        if ($index >= 0 && $index < $this->totalAreas) {
            $this->currentAreaIndex = $index;
            $this->clearAreaComputeds();
        }
    }

    public function dismissGuide(): void
    {
        $this->showGuide = false;
        session(['area_carousel_guide_dismissed' => true]);
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function saveAreaNotes(int $areaId): void
    {
        $notes = $this->areaCustomerNotes[$areaId] ?? '';

        $this->estimate->areas()->where('id', $areaId)->update([
            'customer_notes' => $notes,
        ]);

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'notes',
            $user->name,
            'Updated area notes',
        );
    }

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

    public function addRoom(int $templateId, string $size = 'medium'): void
    {
        $template = EstimateTemplate::findOrFail($templateId);
        $sizes = $template->metadata['sizes'] ?? [];
        $sizeData = $sizes[$size] ?? $sizes['medium'] ?? ['sqft' => 200];

        $area = $this->estimate->areas()->create([
            'name' => $template->name,
            'scope_type' => ($template->scope_type === 'exterior') ? 'exterior' : 'interior',
            'measurement_type' => 'sqft',
            'direct_sqft' => $sizeData['sqft'],
            'is_customer_added' => true,
            'sort_order' => $this->estimate->areas()->count(),
        ]);

        $this->estimate->refresh();
        unset($this->areas, $this->totalAreas, $this->canAddRooms);

        $this->currentAreaIndex = $this->areas->count() - 1;

        $this->areaCustomerNotes[$area->id] = '';
        $this->areaCustomerChecklists[$area->id] = [];

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'room_added',
            $user->name,
            "Added {$template->name} ({$size})",
        );
    }

    #[On('room-added')]
    public function onRoomAdded(int $templateId, string $size): void
    {
        $this->addRoom($templateId, $size);
    }

    public function removeCustomerRoom(int $areaId): void
    {
        $area = $this->estimate->areas()->where('id', $areaId)->where('is_customer_added', true)->first();
        if (! $area) {
            return;
        }

        $areaName = $area->name;
        $area->delete();
        $this->estimate->refresh();
        unset($this->areas, $this->totalAreas, $this->canAddRooms);

        if ($this->currentAreaIndex >= $this->areas->count()) {
            $this->currentAreaIndex = max(0, $this->areas->count() - 1);
        }

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'room_removed',
            $user->name,
            "Removed {$areaName}",
        );
    }

    public function uploadAreaPhoto(int $areaId): void
    {
        $this->validate([
            'areaPhotos.*' => 'image|max:10240',
        ]);

        foreach ($this->areaPhotos as $photo) {
            $storedPath = $photo->store('property-photos', 'public');

            PropertyPhoto::create([
                'estimate_id' => $this->estimate->id,
                'estimate_area_id' => $areaId,
                'path' => $storedPath,
                'disk' => 'public',
                'original_filename' => $photo->getClientOriginalName(),
                'file_size' => $photo->getSize(),
                'mime_type' => $photo->getMimeType(),
            ]);
        }

        $this->areaPhotos = [];

        $user = Auth::guard('customer')->user();
        CustomerContributionAdded::dispatch(
            $this->estimate->id,
            'photo_uploaded',
            $user->name,
            'Uploaded photos',
        );
    }

    /**
     * Get checklist groups filtered by scope type for the current area.
     *
     * @return array<int, array{label: string, color: string, items: array<string, string>}>
     */
    public function getAreaChecklistGroups(?string $scopeType = null): array
    {
        $groups = [];
        $groups[] = ['label' => 'General', 'color' => 'zinc', 'items' => self::AREA_CHECKLIST_GROUPS['common']];

        if ($scopeType !== 'exterior') {
            $groups[] = ['label' => 'Interior', 'color' => 'blue', 'items' => self::AREA_CHECKLIST_GROUPS['interior']];
        }

        if ($scopeType !== 'interior') {
            $groups[] = ['label' => 'Exterior', 'color' => 'orange', 'items' => self::AREA_CHECKLIST_GROUPS['exterior']];
        }

        return $groups;
    }

    public function close(): void
    {
        $this->dispatch('close-area-carousel');
    }

    private function clearAreaComputeds(): void
    {
        unset($this->currentArea, $this->previousAreaName, $this->nextAreaName);
        $this->activeTab = 'details';
    }
}; ?>

<div
    x-data="{
        touchStartX: 0,
        touchEndX: 0,
        handleTouchStart(e) { this.touchStartX = e.changedTouches[0].screenX; },
        handleTouchEnd(e) {
            this.touchEndX = e.changedTouches[0].screenX;
            let diff = this.touchStartX - this.touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) $wire.nextArea();
                else $wire.previousArea();
            }
        },
        savedIndicators: {},
        showSaved(areaId) {
            this.savedIndicators[areaId] = true;
            setTimeout(() => { this.savedIndicators[areaId] = false; }, 2000);
        },
    }"
    x-on:keydown.escape.window="$wire.close()"
    x-on:keydown.arrow-left.window="$wire.previousArea()"
    x-on:keydown.arrow-right.window="$wire.nextArea()"
    x-on:touchstart.passive="handleTouchStart($event)"
    x-on:touchend.passive="handleTouchEnd($event)"
    class="fixed inset-0 z-50 bg-white dark:bg-zinc-900 flex flex-col"
>
    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-4 py-3 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
        <button
            wire:click="close"
            class="flex items-center justify-center w-11 h-11 rounded-xl bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-800/60 transition-colors shadow-sm"
            title="{{ __('Close') }}"
        >
            <flux:icon name="x-mark" class="w-6 h-6" />
        </button>

        @if ($this->currentArea)
            <div class="flex items-center gap-2 min-w-0 mx-3">
                <span class="text-base font-bold text-zinc-900 dark:text-zinc-100 truncate">{{ $this->currentArea->name }}</span>
                <flux:badge size="sm" :color="($this->currentArea->scope_type ?? 'interior') === 'exterior' ? 'orange' : 'blue'">
                    {{ ucfirst($this->currentArea->scope_type ?? 'interior') }}
                </flux:badge>
            </div>
        @endif

        <div class="flex items-center gap-2 shrink-0">
            <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-zinc-100 dark:bg-zinc-800 px-3 py-1.5 rounded-lg">
                {{ $this->currentAreaIndex + 1 }} / {{ $this->totalAreas }}
            </span>

            @if ($this->canAddRooms)
                <button
                    wire:click="$dispatch('open-room-picker')"
                    class="flex items-center justify-center w-11 h-11 rounded-xl bg-green-100 dark:bg-green-900/40 text-green-600 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-800/60 transition-colors shadow-sm"
                    title="{{ __('Add Room') }}"
                >
                    <flux:icon name="plus" class="w-6 h-6" />
                </button>
            @endif
        </div>
    </div>

    {{-- First-time Guide Overlay --}}
    @if ($showGuide)
        <div
            class="absolute inset-0 z-[60] bg-black/60 flex items-center justify-center p-6"
            x-on:click.self="$wire.dismissGuide()"
        >
            <div class="bg-white dark:bg-zinc-800 rounded-2xl p-6 sm:p-8 max-w-sm w-full text-center shadow-2xl">
                <div class="flex items-center justify-center gap-6 mb-6">
                    <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="arrow-left" class="w-6 h-6" />
                        <span class="text-sm font-medium">{{ __('Swipe') }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                        <flux:icon name="hand-raised" class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                        <span class="text-sm font-medium">{{ __('Swipe') }}</span>
                        <flux:icon name="arrow-right" class="w-6 h-6" />
                    </div>
                </div>
                <flux:heading size="lg" class="mb-2">{{ __('Review Your Rooms') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400 text-sm mb-6">
                    {{ __('Swipe left and right to see each room. Leave notes and flag any conditions for the team.') }}
                </flux:text>
                <button
                    wire:click="dismissGuide"
                    class="w-full py-3 bg-blue-600 text-white font-bold text-base rounded-xl hover:bg-blue-700 transition-colors"
                >
                    {{ __('Got It') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Area Content (scrollable) --}}
    @if ($this->currentArea)
        @php $area = $this->currentArea; @endphp
        <div class="flex-1 overflow-y-auto" wire:key="area-slide-{{ $area->id }}">
            <div class="p-4 sm:p-6 max-w-2xl mx-auto w-full space-y-6">

                {{-- Area Name + Size --}}
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $area->name }}</h2>
                    <div class="flex items-center gap-3 mt-1">
                        <flux:badge size="sm" :color="($area->scope_type ?? 'interior') === 'exterior' ? 'orange' : 'blue'">
                            {{ ucfirst($area->scope_type ?? 'interior') }}
                        </flux:badge>
                        @if ($area->wall_sqft > 0)
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ number_format($area->wall_sqft, 0) }} {{ __('sqft walls') }}
                            </flux:text>
                        @endif
                    </div>
                </div>

                {{-- Tabs --}}
                <div class="flex gap-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg p-1">
                    @foreach (['details' => 'Details', 'photos' => 'Photos', 'notes' => 'Notes'] as $tabKey => $tabLabel)
                        <button
                            wire:click="switchTab('{{ $tabKey }}')"
                            wire:key="tab-{{ $tabKey }}"
                            class="flex-1 py-2 px-3 text-sm font-medium rounded-md transition-colors min-h-[40px]
                                {{ $activeTab === $tabKey ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                        >
                            {{ __($tabLabel) }}
                        </button>
                    @endforeach
                </div>

                {{-- Details Tab --}}
                @if ($activeTab === 'details')
                    {{-- Surfaces --}}
                    @if ($area->surfaces->count() > 0)
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-3">{{ __('Surfaces') }}</h3>
                            <div class="bg-zinc-50 dark:bg-zinc-800 rounded-xl divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($area->surfaces as $surface)
                                    <div class="flex items-center justify-between px-4 py-3" wire:key="carousel-surface-{{ $surface->id }}">
                                        <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $surface->name }}</flux:text>
                                        @if ($surface->quantity)
                                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                                {{ number_format($surface->quantity, 0) }} {{ $surface->unit ?? 'sqft' }}
                                            </flux:text>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Area Conditions Checklist (grouped) --}}
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-3">{{ __('What needs attention?') }}</h3>
                        <div class="space-y-4">
                            @foreach ($this->getAreaChecklistGroups($area->scope_type) as $group)
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="inline-block w-2 h-2 rounded-full bg-{{ $group['color'] }}-400"></span>
                                        <span class="text-xs font-semibold text-{{ $group['color'] }}-600 dark:text-{{ $group['color'] }}-400 uppercase tracking-wide">{{ __($group['label']) }}</span>
                                    </div>
                                    <div class="space-y-1">
                                        @foreach ($group['items'] as $key => $label)
                                            <label
                                                class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors min-h-[48px]
                                                    {{ in_array($key, $areaCustomerChecklists[$area->id] ?? []) ? 'border-blue-300 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                                                wire:key="carousel-check-{{ $area->id }}-{{ $key }}"
                                            >
                                                <flux:checkbox
                                                    wire:model="areaCustomerChecklists.{{ $area->id }}"
                                                    value="{{ $key }}"
                                                    wire:change="saveAreaChecklist({{ $area->id }})"
                                                    x-on:change="showSaved({{ $area->id }})"
                                                />
                                                <flux:text class="text-sm">{{ __($label) }}</flux:text>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Remove button for customer-added rooms --}}
                    @if ($area->is_customer_added)
                        <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <button
                                wire:click="removeCustomerRoom({{ $area->id }})"
                                wire:confirm="{{ __('Remove this room?') }}"
                                class="w-full py-3 text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"
                            >
                                {{ __('Remove This Room') }}
                            </button>
                        </div>
                    @endif
                @endif

                {{-- Photos Tab --}}
                @if ($activeTab === 'photos')
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-3">{{ __('Photos') }}</h3>

                        {{-- Existing photos for this area --}}
                        @php
                            $photos = PropertyPhoto::where('estimate_id', $estimate->id)
                                ->where('estimate_area_id', $area->id)
                                ->get();
                        @endphp

                        @if ($photos->isNotEmpty())
                            <div class="grid grid-cols-2 gap-2 mb-4">
                                @foreach ($photos as $photo)
                                    <div wire:key="area-photo-{{ $photo->id }}" class="relative aspect-square rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                                        <img src="{{ Storage::disk($photo->disk ?? 'public')->url($photo->path) }}" alt="{{ $photo->original_filename }}" class="w-full h-full object-cover" />
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Upload area --}}
                        <div class="border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-xl p-6 text-center">
                            <flux:icon name="camera" class="w-8 h-8 mx-auto text-zinc-400 dark:text-zinc-500 mb-2" />
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Take a photo or upload from gallery') }}</flux:text>
                            <label class="inline-flex items-center gap-2 px-4 py-3 bg-blue-600 text-white font-medium text-sm rounded-xl hover:bg-blue-700 cursor-pointer transition-colors min-h-[48px]">
                                <flux:icon name="arrow-up-tray" class="w-4 h-4" />
                                {{ __('Upload Photos') }}
                                <input
                                    type="file"
                                    wire:model="areaPhotos"
                                    accept="image/*"
                                    capture="environment"
                                    multiple
                                    class="hidden"
                                />
                            </label>
                            <div wire:loading wire:target="areaPhotos" class="mt-2">
                                <flux:text class="text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</flux:text>
                            </div>
                            @if (!empty($areaPhotos))
                                <div class="mt-3">
                                    <flux:button wire:click="uploadAreaPhoto({{ $area->id }})" variant="primary" size="sm">
                                        {{ __('Save Photos') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Notes Tab --}}
                @if ($activeTab === 'notes')
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-3">{{ __('Your Notes') }}</h3>
                        <flux:textarea
                            wire:model.blur="areaCustomerNotes.{{ $area->id }}"
                            wire:change="saveAreaNotes({{ $area->id }})"
                            x-on:change="showSaved({{ $area->id }})"
                            rows="4"
                            :placeholder="__('Describe any specific requests for this room - colors, concerns, special instructions...')"
                            class="text-base"
                        />
                        <div
                            class="mt-2 flex items-center gap-1 text-sm text-green-600 dark:text-green-400 transition-opacity duration-300"
                            x-show="savedIndicators[{{ $area->id }}]"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                        >
                            <flux:icon name="check-circle" class="w-4 h-4" />
                            <span>{{ __('Saved') }}</span>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    @else
        <div class="flex-1 overflow-y-auto">
            <div class="p-4 sm:p-6 max-w-2xl mx-auto w-full">
                @if ($this->canAddRooms)
                    <div class="text-center mb-6">
                        <div class="w-16 h-16 mx-auto rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center mb-4">
                            <flux:icon name="home" class="w-8 h-8 text-green-600 dark:text-green-400" />
                        </div>
                        <flux:heading size="lg" class="mb-2">{{ __('Tell Us About Your Home') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400 mb-6">{{ __('Add the rooms you\'d like painted. Pick a size and we\'ll handle the rest.') }}</flux:text>
                    </div>
                    <livewire:customer.estimates.room-picker :key="'room-picker-inline'" />
                @else
                    <div class="text-center p-6">
                        <flux:icon name="squares-2x2" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:heading size="lg" class="mb-2">{{ __('No Rooms Yet') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Your team is still setting up the rooms for this project.') }}</flux:text>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Bottom Navigation Bar --}}
    @if ($this->totalAreas > 0)
        <div class="shrink-0 bg-white dark:bg-zinc-900 border-t border-zinc-200 dark:border-zinc-700 px-4 py-3 safe-area-bottom">
            <div class="flex items-center justify-between gap-3 max-w-2xl mx-auto">
                {{-- Previous --}}
                <button
                    wire:click="previousArea"
                    @if ($this->currentAreaIndex === 0) disabled @endif
                    class="flex items-center gap-1.5 px-4 py-3 rounded-xl text-sm font-bold transition-colors min-h-[48px] shadow-sm
                        {{ $this->currentAreaIndex === 0
                            ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-300 dark:text-zinc-600 cursor-not-allowed'
                            : 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 hover:bg-blue-200 dark:hover:bg-blue-800/60' }}"
                >
                    <flux:icon name="arrow-left" class="w-4 h-4" />
                    <span class="hidden sm:inline truncate max-w-[100px]">{{ $this->previousAreaName ?? __('Previous') }}</span>
                    <span class="sm:hidden">{{ __('Prev') }}</span>
                </button>

                {{-- Dot Indicators --}}
                <div class="flex items-center gap-1.5 overflow-hidden">
                    @foreach ($this->areas as $index => $dotArea)
                        <button
                            wire:click="goToArea({{ $index }})"
                            wire:key="dot-{{ $dotArea->id }}"
                            class="w-2.5 h-2.5 rounded-full transition-all shrink-0
                                {{ $index === $this->currentAreaIndex ? 'bg-blue-600 dark:bg-blue-400 scale-125' : 'bg-zinc-300 dark:bg-zinc-600 hover:bg-zinc-400 dark:hover:bg-zinc-500' }}"
                            title="{{ $dotArea->name }}"
                        ></button>
                    @endforeach
                </div>

                {{-- Next --}}
                <button
                    wire:click="nextArea"
                    @if ($this->currentAreaIndex >= $this->totalAreas - 1) disabled @endif
                    class="flex items-center gap-1.5 px-4 py-3 rounded-xl text-sm font-bold transition-colors min-h-[48px] shadow-sm
                        {{ $this->currentAreaIndex >= $this->totalAreas - 1
                            ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-300 dark:text-zinc-600 cursor-not-allowed'
                            : 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 hover:bg-blue-200 dark:hover:bg-blue-800/60' }}"
                >
                    <span class="hidden sm:inline truncate max-w-[100px]">{{ $this->nextAreaName ?? __('Next') }}</span>
                    <span class="sm:hidden">{{ __('Next') }}</span>
                    <flux:icon name="arrow-right" class="w-4 h-4" />
                </button>
            </div>
        </div>
    @endif
</div>
