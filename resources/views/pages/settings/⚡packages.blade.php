<?php

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.packages')) {
            abort(403);
        }
    }

    /** @var array<int, array> */
    public array $packages = [];

    /** @var array<int, array> */
    public array $items = [];

    /** @var array<int, array> */
    public array $serviceCategories = [];
    public ?int $filterCategoryId = null;
    public ?int $itemFilterCategoryId = null;

    public bool $showEditModal = false;
    public ?int $editingPackageId = null;

    // Edit form fields
    public string $editName = '';
    public string $editTier = '';
    public string $editDescription = '';
    public bool $editIsDefault = false;
    public bool $editIsPopular = false;
    public bool $editIsActive = true;
    public string $editServiceCategoryId = '';

    // Item management
    public bool $showItemModal = false;
    public ?int $editingItemId = null;
    public string $itemName = '';
    public string $itemDescription = '';
    public string $itemPricingType = 'fixed';
    public string $itemCost = '';
    public string $itemPrice = '';
    public string $itemPercent = '';
    public bool $itemIsOptional = true;
    public bool $itemIsIncludedByDefault = true;
    public string $itemServiceCategoryId = '';
    public bool $itemIsCustomerSelectable = false;
    public array $itemDefaultPackages = [];

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->loadServiceCategories();
        $this->loadPackages();
        $this->loadItems();
    }

    public function loadServiceCategories(): void
    {
        $this->serviceCategories = ServiceCategory::active()
            ->ordered()
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])
            ->toArray();

        // Default to first category if available
        if (! $this->filterCategoryId && count($this->serviceCategories) > 0) {
            $this->filterCategoryId = $this->serviceCategories[0]['id'];
        }
        if (! $this->itemFilterCategoryId && count($this->serviceCategories) > 0) {
            $this->itemFilterCategoryId = $this->serviceCategories[0]['id'];
        }
    }

    public function updatedFilterCategoryId(): void
    {
        $this->loadPackages();
    }

    public function updatedItemFilterCategoryId(): void
    {
        $this->loadItems();
    }

    public function loadPackages(): void
    {
        $this->packages = PackageTemplate::query()
            ->when($this->filterCategoryId, fn ($q) => $q->where('service_category_id', $this->filterCategoryId))
            ->with(['items', 'defaultItems'])
            ->ordered()
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'tier' => $p->tier,
                'description' => $p->description,
                'warranty_months' => $p->warranty_months,
                'warranty_percent' => (float) $p->warranty_percent,
                'warranty_description' => $p->warranty_description,
                'is_default' => $p->is_default,
                'is_popular' => $p->is_popular,
                'is_active' => $p->is_active,
                'sort_order' => $p->sort_order,
                'items' => $p->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'description' => $i->description,
                    'pricing_type' => $i->pricing_type,
                    'pricing_type_label' => $i->pricing_type_label,
                    'cost' => (float) $i->cost,
                    'price' => (float) $i->price,
                    'percent' => $i->percent ? (float) $i->percent : null,
                    'price_display' => $i->price_display,
                    'is_optional' => $i->is_optional,
                    'is_included_by_default' => $i->is_included_by_default,
                ])->toArray(),
                'default_items' => $p->defaultItems->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'pricing_type' => $i->pricing_type,
                    'price_display' => $i->price_display,
                    'is_included_by_default' => $i->is_included_by_default,
                    'is_customer_selectable' => $i->is_customer_selectable,
                ])->toArray(),
            ])
            ->toArray();
    }

    public function loadItems(): void
    {
        $query = PackageTemplateItem::query()
            ->with('defaultPackages')
            ->when($this->itemFilterCategoryId, fn ($q) => $q->where('service_category_id', $this->itemFilterCategoryId))
            ->orderBy('sort_order');

        $this->items = $query->get()->map(fn ($i) => [
            'id' => $i->id,
            'name' => $i->name,
            'description' => $i->description,
            'pricing_type' => $i->pricing_type,
            'pricing_type_label' => $i->pricing_type_label,
            'cost' => (float) $i->cost,
            'price' => (float) $i->price,
            'percent' => $i->percent ? (float) $i->percent : null,
            'price_display' => $i->price_display,
            'is_optional' => $i->is_optional,
            'is_included_by_default' => $i->is_included_by_default,
            'is_customer_selectable' => $i->is_customer_selectable,
            'service_category_id' => $i->service_category_id,
            'default_packages' => $i->defaultPackages->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->toArray(),
        ])->toArray();
    }

    public function openEditModal(?int $packageId = null): void
    {
        $this->resetValidation();
        $this->editingPackageId = $packageId;

        if ($packageId) {
            $package = PackageTemplate::find($packageId);
            if ($package) {
                $this->editName = $package->name;
                $this->editTier = $package->tier;
                $this->editDescription = $package->description ?? '';
                $this->editIsDefault = $package->is_default;
                $this->editIsPopular = $package->is_popular;
                $this->editIsActive = $package->is_active;
                $this->editServiceCategoryId = (string) ($package->service_category_id ?? '');
            }
        } else {
            $this->editName = '';
            $this->editTier = '';
            $this->editDescription = '';
            $this->editIsDefault = false;
            $this->editIsPopular = false;
            $this->editIsActive = true;
            $this->editServiceCategoryId = (string) ($this->filterCategoryId ?? '');
        }

        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingPackageId = null;
        $this->resetValidation();
    }

    public function savePackage(): void
    {
        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editTier' => ['required', 'string', 'max:50'],
            'editDescription' => ['nullable', 'string', 'max:1000'],
            'editIsDefault' => ['boolean'],
            'editIsPopular' => ['boolean'],
            'editIsActive' => ['boolean'],
            'editServiceCategoryId' => ['nullable', 'exists:service_categories,id'],
        ]);

        $categoryId = $this->editServiceCategoryId !== '' ? (int) $this->editServiceCategoryId : null;

        // If setting as default, clear other defaults within same service category
        if ($this->editIsDefault) {
            PackageTemplate::where('is_default', true)
                ->where('service_category_id', $categoryId)
                ->when($this->editingPackageId, fn ($q) => $q->where('id', '!=', $this->editingPackageId))
                ->update(['is_default' => false]);
        }

        $data = [
            'name' => $validated['editName'],
            'tier' => $validated['editTier'],
            'description' => $validated['editDescription'] ?: null,
            'is_default' => $this->editIsDefault,
            'is_popular' => $this->editIsPopular,
            'is_active' => $this->editIsActive,
            'service_category_id' => $categoryId,
        ];

        if ($this->editingPackageId) {
            PackageTemplate::find($this->editingPackageId)->update($data);
        } else {
            $maxOrder = PackageTemplate::max('sort_order') ?? 0;
            $data['sort_order'] = $maxOrder + 1;
            PackageTemplate::create($data);
        }

        $this->closeEditModal();
        $this->loadPackages();
        $this->dispatch('package-saved');
    }

    public function deletePackage(int $packageId): void
    {
        PackageTemplate::find($packageId)?->delete();
        $this->loadPackages();
        $this->dispatch('package-deleted');
    }

    // Item management methods
    public function openItemModal(?int $itemId = null): void
    {
        $this->resetValidation();
        $this->editingItemId = $itemId;

        if ($itemId) {
            $item = PackageTemplateItem::with('defaultPackages')->find($itemId);
            if ($item) {
                $this->itemName = $item->name;
                $this->itemDescription = $item->description ?? '';
                $this->itemPricingType = $item->pricing_type;
                $this->itemCost = (string) $item->cost;
                $this->itemPrice = (string) $item->price;
                $this->itemPercent = $item->percent ? (string) $item->percent : '';
                $this->itemIsOptional = $item->is_optional;
                $this->itemIsIncludedByDefault = $item->is_included_by_default;
                $this->itemServiceCategoryId = (string) ($item->service_category_id ?? '');
                $this->itemIsCustomerSelectable = $item->is_customer_selectable;
                $this->itemDefaultPackages = $item->defaultPackages->pluck('id')->toArray();
            }
        } else {
            $this->itemName = '';
            $this->itemDescription = '';
            $this->itemPricingType = 'fixed';
            $this->itemCost = '';
            $this->itemPrice = '';
            $this->itemPercent = '';
            $this->itemIsOptional = true;
            $this->itemIsIncludedByDefault = true;
            $this->itemServiceCategoryId = (string) ($this->itemFilterCategoryId ?? '');
            $this->itemIsCustomerSelectable = false;
            $this->itemDefaultPackages = [];
        }

        $this->showItemModal = true;
    }

    public function closeItemModal(): void
    {
        $this->showItemModal = false;
        $this->editingItemId = null;
        $this->resetValidation();
    }

    public function saveItem(): void
    {
        $rules = [
            'itemName' => ['required', 'string', 'max:255'],
            'itemDescription' => ['nullable', 'string', 'max:1000'],
            'itemPricingType' => ['required', 'in:fixed,percent'],
            'itemCost' => ['nullable', 'numeric', 'min:0'],
            'itemIsOptional' => ['boolean'],
            'itemIsIncludedByDefault' => ['boolean'],
            'itemIsCustomerSelectable' => ['boolean'],
            'itemServiceCategoryId' => ['nullable', 'exists:service_categories,id'],
            'itemDefaultPackages' => ['array'],
            'itemDefaultPackages.*' => ['integer', 'exists:package_templates,id'],
        ];

        if ($this->itemPricingType === 'fixed') {
            $rules['itemPrice'] = ['required', 'numeric', 'min:0'];
        } else {
            $rules['itemPercent'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        $this->validate($rules);

        $categoryId = $this->itemServiceCategoryId !== '' ? (int) $this->itemServiceCategoryId : null;

        $data = [
            'name' => $this->itemName,
            'description' => $this->itemDescription ?: null,
            'pricing_type' => $this->itemPricingType,
            'cost' => $this->itemCost !== '' ? (float) $this->itemCost : 0,
            'price' => $this->itemPricingType === 'fixed' ? (float) $this->itemPrice : 0,
            'percent' => $this->itemPricingType === 'percent' ? (float) $this->itemPercent : null,
            'is_optional' => $this->itemIsOptional,
            'is_included_by_default' => $this->itemIsIncludedByDefault,
            'is_customer_selectable' => $this->itemIsCustomerSelectable,
            'service_category_id' => $categoryId,
        ];

        if ($this->editingItemId) {
            $item = PackageTemplateItem::find($this->editingItemId);
            $item->update($data);
        } else {
            $maxOrder = PackageTemplateItem::max('sort_order') ?? 0;
            $data['sort_order'] = $maxOrder + 1;
            $item = PackageTemplateItem::create($data);
        }

        $item->defaultPackages()->sync($this->itemDefaultPackages);

        $this->closeItemModal();
        $this->loadPackages();
        $this->loadItems();
        $this->dispatch('item-saved');
    }

    public function deleteItem(int $itemId): void
    {
        PackageTemplateItem::find($itemId)?->delete();
        $this->loadPackages();
        $this->loadItems();
        $this->dispatch('item-deleted');
    }

    public function seedDefaultPackages(): void
    {
        // Only seed if no packages exist for current category
        $query = PackageTemplate::query();
        if ($this->filterCategoryId) {
            $query->where('service_category_id', $this->filterCategoryId);
        }
        if ($query->count() > 0) {
            return;
        }

        $categoryId = $this->filterCategoryId;

        // Good tier
        $good = PackageTemplate::create([
            'name' => 'Good',
            'tier' => 'good',
            'description' => 'Quality workmanship with basic warranty coverage.',
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => false,
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 1,
            'service_category_id' => $categoryId,
        ]);

        // Better tier
        $better = PackageTemplate::create([
            'name' => 'Better',
            'tier' => 'better',
            'description' => 'Enhanced warranty with post-project cleaning included.',
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => true,
            'is_popular' => true,
            'is_active' => true,
            'sort_order' => 2,
            'service_category_id' => $categoryId,
        ]);

        // Best tier
        $best = PackageTemplate::create([
            'name' => 'Best',
            'tier' => 'best',
            'description' => 'Premium service with full warranty, cleaning, and color consultation.',
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => false,
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 3,
            'service_category_id' => $categoryId,
        ]);

        // Create standalone warranty items linked via pivot
        $warranty3m = PackageTemplateItem::create([
            'name' => '3-Month Warranty',
            'description' => 'Basic warranty coverage for 3 months.',
            'pricing_type' => 'percent',
            'cost' => 0,
            'price' => 0,
            'percent' => 1.00,
            'is_optional' => true,
            'is_included_by_default' => true,
            'is_customer_selectable' => true,
            'service_category_id' => $categoryId,
            'sort_order' => 1,
        ]);
        $warranty3m->defaultPackages()->attach([$good->id]);

        $warranty6m = PackageTemplateItem::create([
            'name' => '6-Month Warranty',
            'description' => 'Extended warranty coverage for 6 months.',
            'pricing_type' => 'percent',
            'cost' => 0,
            'price' => 0,
            'percent' => 3.00,
            'is_optional' => true,
            'is_included_by_default' => true,
            'is_customer_selectable' => true,
            'service_category_id' => $categoryId,
            'sort_order' => 2,
        ]);
        $warranty6m->defaultPackages()->attach([$better->id]);

        $warranty1y = PackageTemplateItem::create([
            'name' => '1-Year Warranty',
            'description' => 'Full warranty coverage for 1 year.',
            'pricing_type' => 'percent',
            'cost' => 0,
            'price' => 0,
            'percent' => 5.00,
            'is_optional' => true,
            'is_included_by_default' => true,
            'is_customer_selectable' => true,
            'service_category_id' => $categoryId,
            'sort_order' => 3,
        ]);
        $warranty1y->defaultPackages()->attach([$best->id]);

        // Create standalone service items linked via pivot
        $cleaning = PackageTemplateItem::create([
            'name' => 'Post-Project Cleaning',
            'description' => 'Professional cleaning of all work areas after project completion.',
            'pricing_type' => 'fixed',
            'cost' => 200,
            'price' => 400,
            'is_optional' => true,
            'is_included_by_default' => true,
            'is_customer_selectable' => false,
            'service_category_id' => $categoryId,
            'sort_order' => 4,
        ]);
        $cleaning->defaultPackages()->attach([$better->id, $best->id]);

        $consultation = PackageTemplateItem::create([
            'name' => 'Color Consultation',
            'description' => 'In-home consultation with our color expert to help you choose the perfect colors.',
            'pricing_type' => 'fixed',
            'cost' => 50,
            'price' => 125,
            'is_optional' => true,
            'is_included_by_default' => true,
            'is_customer_selectable' => false,
            'service_category_id' => $categoryId,
            'sort_order' => 5,
        ]);
        $consultation->defaultPackages()->attach([$best->id]);

        $this->loadPackages();
        $this->loadItems();
        $this->dispatch('packages-seeded');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Package Templates') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Packages')" :subheading="__('Configure Good/Better/Best package tiers for proposals')">
        <div class="my-6 w-full space-y-6">

            {{-- Info callout --}}
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>Value-Add Package System</flux:callout.heading>
                <flux:callout.text>
                    Packages add value on top of the painting work. Each tier differentiates by the items assigned to it (warranty, services, etc.).
                    Manage items in the Item Catalog below and assign them to packages as defaults.
                </flux:callout.text>
            </flux:callout>

            {{-- Service category tabs --}}
            @if (count($serviceCategories) > 1)
                <div class="flex gap-2">
                    @foreach ($serviceCategories as $cat)
                        <flux:button
                            wire:click="$set('filterCategoryId', {{ $cat['id'] }})"
                            variant="{{ $filterCategoryId === $cat['id'] ? 'primary' : 'ghost' }}"
                            size="sm"
                        >
                            {{ $cat['name'] }}
                        </flux:button>
                    @endforeach
                </div>
            @endif

            {{-- Action buttons --}}
            <div class="flex items-center justify-between">
                <flux:heading size="sm">Package Tiers</flux:heading>
                <div class="flex gap-2">
                    @if (count($packages) === 0)
                        <flux:button wire:click="seedDefaultPackages" variant="ghost" size="sm" icon="sparkles">
                            Seed Defaults
                        </flux:button>
                    @endif
                    <flux:button wire:click="openEditModal()" variant="primary" size="sm" icon="plus">
                        Add Package
                    </flux:button>
                </div>
            </div>

            {{-- Packages list --}}
            @if (count($packages) === 0)
                <flux:card class="p-8 text-center">
                    <flux:icon name="cube" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="base" class="mt-4">No packages configured</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">
                        Click "Seed Defaults" to create Good/Better/Best tiers, or add custom packages.
                    </flux:text>
                </flux:card>
            @else
                <div class="space-y-4">
                    @foreach ($packages as $package)
                        <flux:card class="p-4 {{ !$package['is_active'] ? 'opacity-50' : '' }}" wire:key="package-{{ $package['id'] }}">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <flux:heading size="base">{{ $package['name'] }}</flux:heading>
                                        @if ($package['is_default'])
                                            <flux:badge color="blue" size="sm">Default</flux:badge>
                                        @endif
                                        @if ($package['is_popular'])
                                            <flux:badge color="amber" size="sm">Popular</flux:badge>
                                        @endif
                                        @if (!$package['is_active'])
                                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                                        @endif
                                    </div>
                                    @if ($package['description'])
                                        <flux:text size="sm" class="mt-1 text-zinc-500">{{ $package['description'] }}</flux:text>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:button wire:click="openEditModal({{ $package['id'] }})" variant="ghost" size="sm" icon="pencil" />
                                    <flux:button wire:click="deletePackage({{ $package['id'] }})" wire:confirm="Delete this package and all its items?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700" />
                                </div>
                            </div>

                            {{-- Default items linked via pivot --}}
                            @if (count($package['default_items']) > 0)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($package['default_items'] as $di)
                                        <flux:badge size="sm" color="{{ $di['is_customer_selectable'] ? 'green' : 'zinc' }}">
                                            {{ $di['name'] }} ({{ $di['price_display'] }})
                                        </flux:badge>
                                    @endforeach
                                </div>
                            @else
                                <flux:text size="sm" class="mt-3 text-zinc-400">No default items assigned</flux:text>
                            @endif
                        </flux:card>
                    @endforeach
                </div>
            @endif

            {{-- Sample calculation --}}
            @if (count($packages) > 0)
                <flux:separator />
                <flux:heading size="sm">Sample Calculation</flux:heading>
                <flux:text size="sm" class="text-zinc-500 mb-4">
                    Example pricing for a $2,920 painting job:
                </flux:text>
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ($packages as $package)
                        @php
                            $paintingTotal = 2920;
                            // Pull warranty from default items (percent type items flagged as customer selectable)
                            $defaultItems = collect($package['default_items'])->where('is_included_by_default', true);
                            // Legacy items from direct FK relationship
                            $legacyItems = collect($package['items'])->where('is_included_by_default', true);
                            // Warranty amount from legacy warranty_percent (backwards compatible)
                            $legacyWarrantyAmount = $paintingTotal * ($package['warranty_percent'] / 100);
                            // Items total from default items (pivot)
                            $defaultItemsTotal = 0;
                            foreach ($this->items as $catalogItem) {
                                $isDefault = collect($catalogItem['default_packages'])->contains('id', $package['id']);
                                if ($isDefault && $catalogItem['is_included_by_default']) {
                                    if ($catalogItem['pricing_type'] === 'percent') {
                                        $defaultItemsTotal += $paintingTotal * (($catalogItem['percent'] ?? 0) / 100);
                                    } else {
                                        $defaultItemsTotal += $catalogItem['price'];
                                    }
                                }
                            }
                            // Legacy items total (direct FK)
                            $legacyItemsTotal = $legacyItems->sum(fn($i) => $i['pricing_type'] === 'percent' ? ($paintingTotal * (($i['percent'] ?? 0) / 100)) : $i['price']);
                            $packageTotal = $paintingTotal + $legacyWarrantyAmount + $legacyItemsTotal + $defaultItemsTotal;
                        @endphp
                        <flux:card class="p-4 text-center {{ $package['is_popular'] ? 'ring-2 ring-blue-500' : '' }}" wire:key="calc-{{ $package['id'] }}">
                            @if ($package['is_popular'])
                                <flux:badge color="blue" size="sm" class="mb-2">Popular</flux:badge>
                            @endif
                            <flux:heading size="base">{{ $package['name'] }}</flux:heading>
                            <flux:heading size="xl" class="mt-2 text-blue-600 dark:text-blue-400">${{ number_format($packageTotal, 0) }}</flux:heading>
                            <div class="mt-3 text-xs text-zinc-500 space-y-1">
                                <div>Painting: ${{ number_format($paintingTotal, 0) }}</div>
                                @if ($legacyWarrantyAmount > 0)
                                    <div>Warranty (legacy): ${{ number_format($legacyWarrantyAmount, 0) }}</div>
                                @endif
                                @if ($legacyItemsTotal > 0)
                                    <div>Add-ons (legacy): ${{ number_format($legacyItemsTotal, 0) }}</div>
                                @endif
                                @if ($defaultItemsTotal > 0)
                                    <div>Default Items: ${{ number_format($defaultItemsTotal, 0) }}</div>
                                @endif
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif

            {{-- Item Catalog Section --}}
            <flux:separator />

            <div class="space-y-4">
                <div>
                    <flux:heading size="sm">Item Catalog</flux:heading>
                    <flux:text size="sm" class="text-zinc-500">Manage standalone items that can be assigned to any package</flux:text>
                </div>

                {{-- Item service category filter tabs --}}
                @if (count($serviceCategories) > 1)
                    <div class="flex gap-2">
                        @foreach ($serviceCategories as $cat)
                            <flux:button
                                wire:click="$set('itemFilterCategoryId', {{ $cat['id'] }})"
                                variant="{{ $itemFilterCategoryId === $cat['id'] ? 'primary' : 'ghost' }}"
                                size="sm"
                            >
                                {{ $cat['name'] }}
                            </flux:button>
                        @endforeach
                        <flux:button
                            wire:click="$set('itemFilterCategoryId', null)"
                            variant="{{ $itemFilterCategoryId === null ? 'primary' : 'ghost' }}"
                            size="sm"
                        >
                            All
                        </flux:button>
                    </div>
                @endif

                {{-- Add Item button --}}
                <div class="flex justify-end">
                    <flux:button wire:click="openItemModal()" variant="primary" size="sm" icon="plus">
                        Add Item
                    </flux:button>
                </div>

                {{-- Items list --}}
                @if (count($items) === 0)
                    <flux:card class="p-8 text-center">
                        <flux:icon name="rectangle-stack" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="base" class="mt-4">No items in catalog</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">
                            Add items like warranty, cleaning services, or color consultation to assign to packages.
                        </flux:text>
                    </flux:card>
                @else
                    <div class="space-y-2">
                        @foreach ($items as $item)
                            <div class="flex items-center justify-between bg-zinc-50 dark:bg-zinc-800/50 rounded-lg px-4 py-3" wire:key="catalog-item-{{ $item['id'] }}">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    @if ($item['is_included_by_default'])
                                        <flux:icon name="check-circle" class="h-5 w-5 shrink-0 text-green-500" />
                                    @else
                                        <svg class="h-5 w-5 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><circle cx="12" cy="12" r="9.75" /></svg>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <flux:text size="sm" class="font-medium">{{ $item['name'] }}</flux:text>
                                            <flux:badge size="sm" color="zinc">{{ $item['price_display'] }}</flux:badge>
                                            @if ($item['is_customer_selectable'])
                                                <flux:badge size="sm" color="green">Customer Selectable</flux:badge>
                                            @endif
                                            @if ($item['is_optional'])
                                                <flux:badge size="sm" color="zinc" class="opacity-60">Optional</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="amber">Required</flux:badge>
                                            @endif
                                        </div>
                                        @if (count($item['default_packages']) > 0)
                                            <div class="flex items-center gap-1 mt-1 flex-wrap">
                                                <flux:text size="xs" class="text-zinc-400">Defaults to:</flux:text>
                                                @foreach ($item['default_packages'] as $dp)
                                                    <flux:badge size="sm" color="blue">{{ $dp['name'] }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-1 shrink-0 ml-2">
                                    <flux:button wire:click="openItemModal({{ $item['id'] }})" variant="ghost" size="xs" icon="pencil" />
                                    <flux:button wire:click="deleteItem({{ $item['id'] }})" wire:confirm="Delete this item?" variant="ghost" size="xs" icon="trash" class="text-red-600 hover:text-red-700" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Edit Package Modal --}}
        <flux:modal wire:model.live="showEditModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading size="lg">{{ $editingPackageId ? 'Edit Package' : 'Add Package' }}</flux:heading>

                <flux:input wire:model="editName" label="Package Name" placeholder="e.g., Good, Better, Best" required />

                <flux:input wire:model="editTier" label="Tier Identifier" placeholder="e.g., good, better, best" description="Unique identifier used internally" required />

                @if (count($serviceCategories) > 0)
                    <flux:select wire:model="editServiceCategoryId" label="Service Category">
                        <option value="">-- None --</option>
                        @foreach ($serviceCategories as $cat)
                            <option value="{{ $cat['id'] }}">{{ $cat['name'] }}</option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:textarea wire:model="editDescription" label="Description" rows="2" placeholder="Brief description of this package tier" />

                <div class="flex flex-wrap gap-4">
                    <flux:checkbox wire:model="editIsDefault" label="Default selection" />
                    <flux:checkbox wire:model="editIsPopular" label="Show 'Popular' badge" />
                    <flux:checkbox wire:model="editIsActive" label="Active" />
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="closeEditModal" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="savePackage" variant="primary">
                        {{ $editingPackageId ? 'Save Changes' : 'Create Package' }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Edit Item Modal --}}
        <flux:modal wire:model.live="showItemModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading size="lg">{{ $editingItemId ? 'Edit Item' : 'Add Item' }}</flux:heading>

                <flux:input wire:model="itemName" label="Item Name" placeholder="e.g., Post-Project Cleaning, 1-Year Warranty" required />

                <flux:textarea wire:model="itemDescription" label="Description" rows="2" placeholder="Brief description of this item" />

                @if (count($serviceCategories) > 0)
                    <flux:select wire:model="itemServiceCategoryId" label="Service Category">
                        <option value="">-- None --</option>
                        @foreach ($serviceCategories as $cat)
                            <option value="{{ $cat['id'] }}">{{ $cat['name'] }}</option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model.live="itemPricingType" label="Pricing Type">
                    <option value="fixed">Fixed Price</option>
                    <option value="percent">Percentage of Job</option>
                </flux:select>

                @if ($itemPricingType === 'fixed')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="itemCost" type="number" step="0.01" label="Your Cost ($)" min="0" placeholder="200" />
                        <flux:input wire:model="itemPrice" type="number" step="0.01" label="Customer Price ($)" min="0" placeholder="400" required />
                    </div>
                @else
                    <flux:input wire:model="itemPercent" type="number" step="0.01" label="Percentage of Job (%)" min="0" max="100" placeholder="5" required />
                @endif

                <div class="flex flex-wrap gap-4">
                    <flux:checkbox wire:model="itemIsOptional" label="Customer can toggle on/off" />
                    <flux:checkbox wire:model="itemIsIncludedByDefault" label="Included by default" />
                    <flux:checkbox wire:model="itemIsCustomerSelectable" label="Visible to customer on proposal" />
                </div>

                {{-- Default to Packages (checkboxes) --}}
                @if (count($packages) > 0)
                    <div>
                        <flux:text size="sm" class="font-medium mb-2">Default to Packages</flux:text>
                        <flux:text size="xs" class="text-zinc-500 mb-3">Select which packages this item should be included in by default</flux:text>
                        <div class="space-y-2">
                            @foreach ($packages as $pkg)
                                <label class="flex items-center gap-2 cursor-pointer" wire:key="pkg-checkbox-{{ $pkg['id'] }}">
                                    <input
                                        type="checkbox"
                                        value="{{ $pkg['id'] }}"
                                        wire:model="itemDefaultPackages"
                                        class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800"
                                    />
                                    <flux:text size="sm">{{ $pkg['name'] }}</flux:text>
                                    @if ($pkg['is_default'])
                                        <flux:badge size="sm" color="blue">Default</flux:badge>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="closeItemModal" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveItem" variant="primary">
                        {{ $editingItemId ? 'Save Changes' : 'Add Item' }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Action messages --}}
        <x-action-message class="me-3" on="package-saved">Package saved.</x-action-message>
        <x-action-message class="me-3" on="package-deleted">Package deleted.</x-action-message>
        <x-action-message class="me-3" on="item-saved">Item saved.</x-action-message>
        <x-action-message class="me-3" on="item-deleted">Item deleted.</x-action-message>
        <x-action-message class="me-3" on="packages-seeded">Default packages created.</x-action-message>
    </x-pages::settings.layout>
</section>
