<?php

use App\Models\Deal;
use App\Models\LandingPageShowcasePhoto;
use App\Models\PropertyPhoto;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public bool $showPicker = false;

    public string $dealSearch = '';

    public ?int $selectedDealId = null;

    public ?int $selectedBeforePhotoId = null;

    public ?int $selectedAfterPhotoId = null;

    public string $selectingMode = 'before';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage'), 403);
    }

    #[Computed]
    public function showcasePairs()
    {
        return LandingPageShowcasePhoto::query()
            ->with('deal.property', 'deal.customer')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function deals(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Deal::query()
            ->whereHas('property', function ($q) {
                $q->whereHas('photos', fn ($pq) => $pq->where('category', 'reference'));
            })
            ->with([
                'customer',
                'property.photos' => fn ($q) => $q->where('category', 'reference')->orderBy('sort_order')->limit(4),
            ]);

        if ($this->dealSearch) {
            $search = '%'.$this->dealSearch.'%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search)
                            ->orWhere('phone', 'like', $search)
                            ->orWhere('email', 'like', $search);
                    })
                    ->orWhereHas('property', fn ($pq) => $pq->where('address', 'like', $search));
            });
        }

        return $query->latest()->paginate(12);
    }

    #[Computed]
    public function selectedDeal()
    {
        if (! $this->selectedDealId) {
            return null;
        }

        return Deal::with('customer', 'property')->find($this->selectedDealId);
    }

    #[Computed]
    public function dealPhotos(): \Illuminate\Support\Collection
    {
        if (! $this->selectedDealId) {
            return collect();
        }

        $deal = Deal::with(['property.photos', 'estimates.photos'])->find($this->selectedDealId);

        if (! $deal) {
            return collect();
        }

        $photos = collect();

        if ($deal->property) {
            $photos = $photos->merge($deal->property->photos);
        }

        $photos = $photos->merge($deal->estimates->flatMap->photos);

        return $photos->unique('id')->sortBy('category')->values();
    }

    public function updatedDealSearch(): void
    {
        $this->resetPage();
    }

    public function openPicker(): void
    {
        $this->dealSearch = '';
        $this->selectedDealId = null;
        $this->selectedBeforePhotoId = null;
        $this->selectedAfterPhotoId = null;
        $this->selectingMode = 'before';
        $this->showPicker = true;
        $this->resetPage();
        unset($this->deals);
        unset($this->dealPhotos);
        unset($this->selectedDeal);
    }

    public function selectDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
        $this->selectedBeforePhotoId = null;
        $this->selectedAfterPhotoId = null;
        $this->selectingMode = 'before';
        unset($this->dealPhotos);
        unset($this->selectedDeal);
    }

    public function backToDeals(): void
    {
        $this->selectedDealId = null;
        $this->selectedBeforePhotoId = null;
        $this->selectedAfterPhotoId = null;
        $this->selectingMode = 'before';
        unset($this->dealPhotos);
        unset($this->selectedDeal);
    }

    public function selectPhoto(int $photoId): void
    {
        if ($this->selectingMode === 'before') {
            if ($this->selectedBeforePhotoId === $photoId) {
                $this->selectedBeforePhotoId = null;
            } else {
                $this->selectedBeforePhotoId = $photoId;
                if ($this->selectedAfterPhotoId === $photoId) {
                    $this->selectedAfterPhotoId = null;
                }
                $this->selectingMode = 'after';
            }
        } else {
            if ($this->selectedAfterPhotoId === $photoId) {
                $this->selectedAfterPhotoId = null;
            } else {
                $this->selectedAfterPhotoId = $photoId;
                if ($this->selectedBeforePhotoId === $photoId) {
                    $this->selectedBeforePhotoId = null;
                }
            }
        }
    }

    public function setMode(string $mode): void
    {
        $this->selectingMode = $mode;
    }

    public function selectBefore(int $photoId): void
    {
        $this->selectingMode = 'before';
        $this->selectPhoto($photoId);
    }

    public function selectAfter(int $photoId): void
    {
        $this->selectingMode = 'after';
        $this->selectPhoto($photoId);
    }

    public function savePair(): void
    {
        if (! $this->selectedBeforePhotoId || ! $this->selectedAfterPhotoId) {
            return;
        }

        $beforePhoto = PropertyPhoto::find($this->selectedBeforePhotoId);
        $afterPhoto = PropertyPhoto::find($this->selectedAfterPhotoId);

        if (! $beforePhoto || ! $afterPhoto) {
            return;
        }

        $maxSort = LandingPageShowcasePhoto::max('sort_order') ?? 0;

        LandingPageShowcasePhoto::create([
            'before_photo_path' => $beforePhoto->path,
            'after_photo_path' => $afterPhoto->path,
            'before_thumbnail_path' => $beforePhoto->thumbnail_path,
            'after_thumbnail_path' => $afterPhoto->thumbnail_path,
            'disk' => $beforePhoto->disk,
            'deal_id' => $this->selectedDealId,
            'sort_order' => $maxSort + 1,
        ]);

        $this->showPicker = false;
        unset($this->showcasePairs);
    }

    public function togglePair(int $pairId): void
    {
        $pair = LandingPageShowcasePhoto::findOrFail($pairId);
        $pair->update(['is_active' => ! $pair->is_active]);
        unset($this->showcasePairs);
    }

    public function updateCaption(int $pairId, string $caption): void
    {
        $pair = LandingPageShowcasePhoto::findOrFail($pairId);
        $pair->update(['caption' => $caption ?: null]);
        unset($this->showcasePairs);
    }

    public function removePair(int $pairId): void
    {
        LandingPageShowcasePhoto::where('id', $pairId)->delete();
        unset($this->showcasePairs);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="lg">{{ __('Showcase Photos') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-1">{{ __('Select before & after photos from deals with reference photos to showcase on the landing page.') }}</flux:text>
        </div>
        <flux:button size="sm" variant="primary" icon="plus" wire:click="openPicker">
            {{ __('Add Pair') }}
        </flux:button>
    </div>

    {{-- Existing pairs --}}
    @if($this->showcasePairs->isEmpty())
        <div class="text-center py-12 border border-dashed border-zinc-300 dark:border-zinc-700 rounded-lg">
            <flux:icon name="photo" class="h-8 w-8 text-zinc-400 mx-auto mb-3" />
            <flux:text class="text-zinc-500">{{ __('No showcase pairs yet. Add before/after photos from your deals.') }}</flux:text>
        </div>
    @else
        <div class="space-y-4">
            @foreach($this->showcasePairs as $pair)
                <div wire:key="pair-{{ $pair->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
                    {{-- Header --}}
                    <div class="px-6 py-4 flex items-center justify-between border-b border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center gap-3 min-w-0">
                            <flux:badge size="sm" :color="$pair->is_active ? 'green' : 'zinc'">
                                {{ $pair->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                            <div class="min-w-0">
                                <flux:heading size="sm" class="truncate">{{ $pair->deal?->title ?? __('Showcase Pair') }}</flux:heading>
                                <div class="flex items-center gap-2 text-xs text-zinc-500">
                                    @if($pair->deal?->customer)
                                        <span>{{ $pair->deal->customer->name }}</span>
                                    @endif
                                    @if($pair->deal?->property?->address)
                                        <span>&middot;</span>
                                        <span>{{ $pair->deal->property->address }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <flux:button size="sm" variant="ghost" icon="{{ $pair->is_active ? 'eye-slash' : 'eye' }}" wire:click="togglePair({{ $pair->id }})" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removePair({{ $pair->id }})" wire:confirm="{{ __('Remove this showcase pair?') }}" />
                        </div>
                    </div>

                    {{-- Caption --}}
                    <div class="px-6 py-3 border-b border-zinc-100 dark:border-zinc-800">
                        <flux:field>
                            <flux:label>{{ __('Caption') }}</flux:label>
                            <flux:input
                                size="sm"
                                value="{{ $pair->caption }}"
                                placeholder="{{ __('e.g. Kitchen renovation — Sherwin-Williams Sea Salt') }}"
                                wire:blur="updateCaption({{ $pair->id }}, $event.target.value)"
                            />
                        </flux:field>
                    </div>

                    {{-- Photo previews --}}
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <flux:text class="text-xs font-semibold text-zinc-500 mb-2">{{ __('Before') }}</flux:text>
                                <div class="aspect-[4/3] bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                                    @if($pair->before_thumbnail_url)
                                        <img src="{{ $pair->before_thumbnail_url }}" alt="{{ __('Before') }}" class="w-full h-full object-cover" loading="lazy" />
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <flux:icon name="photo" class="h-6 w-6 text-zinc-400" />
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <flux:text class="text-xs font-semibold text-zinc-500 mb-2">{{ __('After') }}</flux:text>
                                <div class="aspect-[4/3] bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                                    @if($pair->after_thumbnail_url)
                                        <img src="{{ $pair->after_thumbnail_url }}" alt="{{ __('After') }}" class="w-full h-full object-cover" loading="lazy" />
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <flux:icon name="photo" class="h-6 w-6 text-zinc-400" />
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Deal Photo Picker Modal --}}
    <flux:modal wire:model="showPicker" class="max-w-4xl">
        @if(! $selectedDealId)
            {{-- Step 1: Browse deals with reference photos --}}
            <flux:heading size="lg" class="mb-1">{{ __('Select a Deal') }}</flux:heading>
            <flux:text class="text-zinc-500 mb-4">{{ __('Showing deals with reference photos. Select one to pick before & after images.') }}</flux:text>

            <flux:input
                wire:model.live.debounce.300ms="dealSearch"
                placeholder="{{ __('Search by customer name, phone, address...') }}"
                icon="magnifying-glass"
                class="mb-4"
            />

            <div class="max-h-[32rem] overflow-y-auto -mx-2">
                @if($this->deals->count() > 0)
                    <div class="space-y-3 px-2">
                        @foreach($this->deals as $deal)
                            @php
                                $referencePhotos = $deal->property?->photos ?? collect();
                            @endphp
                            <button
                                wire:key="picker-deal-{{ $deal->id }}"
                                wire:click="selectDeal({{ $deal->id }})"
                                type="button"
                                class="w-full text-left rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 hover:border-blue-500 dark:hover:border-blue-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <div class="flex items-start gap-4">
                                    {{-- Reference photo previews --}}
                                    <div class="shrink-0 flex gap-1.5">
                                        @foreach($referencePhotos->take(3) as $refPhoto)
                                            <div class="w-16 h-16 rounded-md bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                                <img src="{{ $refPhoto->url }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                                            </div>
                                        @endforeach
                                        @if($referencePhotos->count() > 3)
                                            <div class="w-16 h-16 rounded-md bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                                <span class="text-xs font-medium text-zinc-500">+{{ $referencePhotos->count() - 3 }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Deal info --}}
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 truncate">{{ $deal->title }}</p>
                                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 mt-0.5">
                                            @if($deal->customer)
                                                <span>{{ $deal->customer->name }}</span>
                                                @if($deal->customer->phone)
                                                    <span>&middot; {{ $deal->customer->phone }}</span>
                                                @endif
                                            @endif
                                        </div>
                                        @if($deal->property?->address)
                                            <p class="text-xs text-zinc-400 truncate mt-0.5">{{ $deal->property->address }}</p>
                                        @endif
                                        <div class="flex items-center gap-2 mt-1.5">
                                            <flux:badge size="sm" color="zinc">{{ $deal->stage_label }}</flux:badge>
                                            <span class="text-xs text-zinc-400">{{ $deal->created_at->format('M j, Y') }}</span>
                                            <flux:badge size="sm" color="blue">{{ $referencePhotos->count() }} {{ __('reference') }}</flux:badge>
                                        </div>
                                    </div>

                                    <flux:icon name="chevron-right" class="h-5 w-5 text-zinc-300 shrink-0 mt-1" />
                                </div>
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-4 px-2">{{ $this->deals->links() }}</div>
                @else
                    <div class="text-center py-12">
                        <flux:icon name="photo" class="h-8 w-8 text-zinc-300 mx-auto mb-3" />
                        <flux:text class="text-zinc-500">
                            {{ $dealSearch ? __('No deals with reference photos match your search.') : __('No deals with reference photos found.') }}
                        </flux:text>
                    </div>
                @endif
            </div>

            <div class="flex justify-end mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('showPicker', false)">{{ __('Cancel') }}</flux:button>
            </div>

        @else
            {{-- Step 2: Browse deal photos & pick before/after --}}
            @php $deal = $this->selectedDeal; @endphp

            {{-- Back + deal info --}}
            <div class="flex items-center gap-3 mb-4">
                <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="backToDeals">
                    {{ __('Back') }}
                </flux:button>
                <div class="min-w-0">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $deal?->title ?? __('Deal') }}</span>
                    <p class="text-xs text-zinc-500">
                        {{ $deal?->customer?->name }}
                        @if($deal?->property?->address)
                            &middot; {{ $deal->property->address }}
                        @endif
                    </p>
                </div>
            </div>

            {{-- Mode selector + selection preview --}}
            <div class="grid grid-cols-2 gap-3 mb-4">
                {{-- Before selection --}}
                <div
                    wire:click="setMode('before')"
                    class="relative rounded-lg border-2 p-3 cursor-pointer transition-all {{ $selectingMode === 'before' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                >
                    <div class="flex items-center gap-2 mb-2">
                        @if($selectingMode === 'before')
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                        @elseif($selectedBeforePhotoId)
                            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        @else
                            <span class="w-4 h-4 rounded-full border-2 border-zinc-300 inline-block shrink-0"></span>
                        @endif
                        <span class="text-sm font-semibold {{ $selectingMode === 'before' ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                            {{ __('Before') }}
                        </span>
                    </div>
                    @if($selectedBeforePhotoId)
                        @php $beforePreview = $this->dealPhotos->firstWhere('id', $selectedBeforePhotoId); @endphp
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden">
                            @if($beforePreview)
                                <img src="{{ $beforePreview->url }}" alt="{{ __('Before') }}" class="w-full h-full object-cover block" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="photo" class="h-5 w-5 text-zinc-400" />
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded flex items-center justify-center">
                            <span class="text-xs text-zinc-400">{{ $selectingMode === 'before' ? __('Click a photo below') : __('Not selected') }}</span>
                        </div>
                    @endif
                </div>

                {{-- After selection --}}
                <div
                    wire:click="setMode('after')"
                    class="relative rounded-lg border-2 p-3 cursor-pointer transition-all {{ $selectingMode === 'after' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                >
                    <div class="flex items-center gap-2 mb-2">
                        @if($selectingMode === 'after')
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-500 animate-pulse"></span>
                        @elseif($selectedAfterPhotoId)
                            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        @else
                            <span class="w-4 h-4 rounded-full border-2 border-zinc-300 inline-block shrink-0"></span>
                        @endif
                        <span class="text-sm font-semibold {{ $selectingMode === 'after' ? 'text-blue-700 dark:text-blue-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                            {{ __('After') }}
                        </span>
                    </div>
                    @if($selectedAfterPhotoId)
                        @php $afterPreview = $this->dealPhotos->firstWhere('id', $selectedAfterPhotoId); @endphp
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden">
                            @if($afterPreview)
                                <img src="{{ $afterPreview->url }}" alt="{{ __('After') }}" class="w-full h-full object-cover block" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="photo" class="h-5 w-5 text-zinc-400" />
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded flex items-center justify-center">
                            <span class="text-xs text-zinc-400">{{ $selectingMode === 'after' ? __('Click a photo below') : __('Not selected') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Instruction --}}
            <div class="text-center mb-3">
                <flux:text class="text-sm">
                    @if($selectingMode === 'before')
                        <span class="text-amber-600 dark:text-amber-400 font-medium">{{ __('Click a photo to set as the BEFORE image') }}</span>
                    @else
                        <span class="text-blue-600 dark:text-blue-400 font-medium">{{ __('Click a photo to set as the AFTER image') }}</span>
                    @endif
                </flux:text>
            </div>

            {{-- All deal photos grouped by category --}}
            @php
                $photos = $this->dealPhotos;
                $grouped = $photos->groupBy('category');
            @endphp

            <div class="max-h-[22rem] overflow-y-auto space-y-4">
                @foreach($grouped as $category => $categoryPhotos)
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <h4 class="text-sm font-medium text-zinc-600 dark:text-zinc-400">
                                {{ \App\Models\PropertyPhoto::CATEGORIES[$category] ?? ucfirst($category) }}
                            </h4>
                            <flux:badge size="sm" color="zinc">{{ $categoryPhotos->count() }}</flux:badge>
                        </div>
                        <div class="grid grid-cols-4 sm:grid-cols-5 gap-2">
                            @foreach($categoryPhotos as $photo)
                                @php
                                    $isSelectedBefore = $selectedBeforePhotoId === $photo->id;
                                    $isSelectedAfter = $selectedAfterPhotoId === $photo->id;
                                    $ringClasses = $isSelectedBefore
                                        ? 'ring-2 ring-amber-500'
                                        : ($isSelectedAfter
                                            ? 'ring-2 ring-blue-500'
                                            : 'ring-0 hover:ring-2 ' . ($selectingMode === 'before' ? 'hover:ring-amber-400' : 'hover:ring-blue-400'));
                                @endphp
                                <button
                                    wire:key="photo-{{ $photo->id }}"
                                    wire:click="selectPhoto({{ $photo->id }})"
                                    type="button"
                                    class="relative aspect-square bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden {{ $ringClasses }} transition-all focus:outline-none"
                                >
                                    <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? $photo->category_label }}" class="w-full h-full object-cover" loading="lazy" />

                                    @if($isSelectedBefore)
                                        <div class="absolute inset-0 bg-amber-500/20"></div>
                                        <div class="absolute top-1.5 left-1.5">
                                            <span class="inline-block px-2 py-0.5 text-[11px] font-bold bg-amber-500 text-white rounded shadow">{{ __('BEFORE') }}</span>
                                        </div>
                                    @elseif($isSelectedAfter)
                                        <div class="absolute inset-0 bg-blue-500/20"></div>
                                        <div class="absolute top-1.5 left-1.5">
                                            <span class="inline-block px-2 py-0.5 text-[11px] font-bold bg-blue-500 text-white rounded shadow">{{ __('AFTER') }}</span>
                                        </div>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if($photos->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="photo" class="h-8 w-8 text-zinc-300 mx-auto mb-3" />
                        <flux:text class="text-zinc-500">{{ __('No photos found for this deal.') }}</flux:text>
                    </div>
                @endif
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="backToDeals">{{ __('Back') }}</flux:button>
                <div class="flex items-center gap-3">
                    <flux:button variant="ghost" wire:click="$set('showPicker', false)">{{ __('Cancel') }}</flux:button>
                    @if($selectedBeforePhotoId && $selectedAfterPhotoId)
                        <flux:button variant="primary" wire:click="savePair">{{ __('Save Pair') }}</flux:button>
                    @else
                        <flux:button variant="primary" disabled>{{ __('Select Both Photos') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
