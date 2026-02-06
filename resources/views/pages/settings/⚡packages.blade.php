<?php

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
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

    public bool $showEditModal = false;
    public ?int $editingPackageId = null;

    // Edit form fields
    public string $editName = '';
    public string $editTier = '';
    public string $editDescription = '';
    public string $editWarrantyMonths = '';
    public string $editWarrantyPercent = '';
    public bool $editIsDefault = false;
    public bool $editIsPopular = false;
    public bool $editIsActive = true;

    // Item management
    public bool $showItemModal = false;
    public ?int $editingItemId = null;
    public ?int $itemPackageId = null;
    public string $itemName = '';
    public string $itemDescription = '';
    public string $itemPricingType = 'fixed';
    public string $itemCost = '';
    public string $itemPrice = '';
    public string $itemPercent = '';
    public bool $itemIsOptional = true;
    public bool $itemIsIncludedByDefault = true;

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->loadPackages();
    }

    public function loadPackages(): void
    {
        $this->packages = PackageTemplate::query()
            ->with('items')
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
            ])
            ->toArray();
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
                $this->editWarrantyMonths = (string) $package->warranty_months;
                $this->editWarrantyPercent = (string) $package->warranty_percent;
                $this->editIsDefault = $package->is_default;
                $this->editIsPopular = $package->is_popular;
                $this->editIsActive = $package->is_active;
            }
        } else {
            $this->editName = '';
            $this->editTier = '';
            $this->editDescription = '';
            $this->editWarrantyMonths = '0';
            $this->editWarrantyPercent = '0';
            $this->editIsDefault = false;
            $this->editIsPopular = false;
            $this->editIsActive = true;
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
            'editWarrantyMonths' => ['required', 'integer', 'min:0', 'max:120'],
            'editWarrantyPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'editIsDefault' => ['boolean'],
            'editIsPopular' => ['boolean'],
            'editIsActive' => ['boolean'],
        ]);

        // If setting as default, clear other defaults
        if ($this->editIsDefault) {
            PackageTemplate::where('is_default', true)
                ->when($this->editingPackageId, fn ($q) => $q->where('id', '!=', $this->editingPackageId))
                ->update(['is_default' => false]);
        }

        $data = [
            'name' => $validated['editName'],
            'tier' => $validated['editTier'],
            'description' => $validated['editDescription'] ?: null,
            'warranty_months' => (int) $validated['editWarrantyMonths'],
            'warranty_percent' => (float) $validated['editWarrantyPercent'],
            'is_default' => $this->editIsDefault,
            'is_popular' => $this->editIsPopular,
            'is_active' => $this->editIsActive,
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
    public function openItemModal(int $packageId, ?int $itemId = null): void
    {
        $this->resetValidation();
        $this->itemPackageId = $packageId;
        $this->editingItemId = $itemId;

        if ($itemId) {
            $item = PackageTemplateItem::find($itemId);
            if ($item) {
                $this->itemName = $item->name;
                $this->itemDescription = $item->description ?? '';
                $this->itemPricingType = $item->pricing_type;
                $this->itemCost = (string) $item->cost;
                $this->itemPrice = (string) $item->price;
                $this->itemPercent = $item->percent ? (string) $item->percent : '';
                $this->itemIsOptional = $item->is_optional;
                $this->itemIsIncludedByDefault = $item->is_included_by_default;
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
        }

        $this->showItemModal = true;
    }

    public function closeItemModal(): void
    {
        $this->showItemModal = false;
        $this->editingItemId = null;
        $this->itemPackageId = null;
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
        ];

        if ($this->itemPricingType === 'fixed') {
            $rules['itemPrice'] = ['required', 'numeric', 'min:0'];
        } else {
            $rules['itemPercent'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        $this->validate($rules);

        $data = [
            'package_template_id' => $this->itemPackageId,
            'name' => $this->itemName,
            'description' => $this->itemDescription ?: null,
            'pricing_type' => $this->itemPricingType,
            'cost' => $this->itemCost !== '' ? (float) $this->itemCost : 0,
            'price' => $this->itemPricingType === 'fixed' ? (float) $this->itemPrice : 0,
            'percent' => $this->itemPricingType === 'percent' ? (float) $this->itemPercent : null,
            'is_optional' => $this->itemIsOptional,
            'is_included_by_default' => $this->itemIsIncludedByDefault,
        ];

        if ($this->editingItemId) {
            PackageTemplateItem::find($this->editingItemId)->update($data);
        } else {
            $maxOrder = PackageTemplateItem::where('package_template_id', $this->itemPackageId)->max('sort_order') ?? 0;
            $data['sort_order'] = $maxOrder + 1;
            PackageTemplateItem::create($data);
        }

        $this->closeItemModal();
        $this->loadPackages();
        $this->dispatch('item-saved');
    }

    public function deleteItem(int $itemId): void
    {
        PackageTemplateItem::find($itemId)?->delete();
        $this->loadPackages();
        $this->dispatch('item-deleted');
    }

    public function seedDefaultPackages(): void
    {
        // Only seed if no packages exist
        if (PackageTemplate::count() > 0) {
            return;
        }

        // Good tier
        $good = PackageTemplate::create([
            'name' => 'Good',
            'tier' => 'good',
            'description' => 'Quality workmanship with basic warranty coverage.',
            'warranty_months' => 3,
            'warranty_percent' => 1.00,
            'is_default' => false,
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Better tier
        $better = PackageTemplate::create([
            'name' => 'Better',
            'tier' => 'better',
            'description' => 'Enhanced warranty with post-project cleaning included.',
            'warranty_months' => 6,
            'warranty_percent' => 3.00,
            'is_default' => true,
            'is_popular' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        PackageTemplateItem::create([
            'package_template_id' => $better->id,
            'name' => 'Post-Project Cleaning',
            'description' => 'Professional cleaning of all work areas after project completion.',
            'pricing_type' => 'fixed',
            'cost' => 200,
            'price' => 400,
            'is_optional' => true,
            'is_included_by_default' => true,
            'sort_order' => 1,
        ]);

        // Best tier
        $best = PackageTemplate::create([
            'name' => 'Best',
            'tier' => 'best',
            'description' => 'Premium service with full warranty, cleaning, and color consultation.',
            'warranty_months' => 12,
            'warranty_percent' => 5.00,
            'is_default' => false,
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        PackageTemplateItem::create([
            'package_template_id' => $best->id,
            'name' => 'Post-Project Cleaning',
            'description' => 'Professional cleaning of all work areas after project completion.',
            'pricing_type' => 'fixed',
            'cost' => 200,
            'price' => 400,
            'is_optional' => true,
            'is_included_by_default' => true,
            'sort_order' => 1,
        ]);

        PackageTemplateItem::create([
            'package_template_id' => $best->id,
            'name' => 'Color Consultation',
            'description' => 'In-home consultation with our color expert to help you choose the perfect colors.',
            'pricing_type' => 'fixed',
            'cost' => 50,
            'price' => 125,
            'is_optional' => true,
            'is_included_by_default' => true,
            'sort_order' => 2,
        ]);

        $this->loadPackages();
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
                    Packages add value on top of the painting work. Each tier includes warranty coverage and optional add-on services.
                    The painting scope is the same across all packages - packages differentiate by warranty and services.
                </flux:callout.text>
            </flux:callout>

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
                                    <div class="mt-2 flex items-center gap-4 text-sm">
                                        <span class="flex items-center gap-1 text-zinc-600 dark:text-zinc-400">
                                            <flux:icon name="shield-check" class="h-4 w-4" />
                                            {{ $package['warranty_description'] }}
                                        </span>
                                        @if ($package['warranty_percent'] > 0)
                                            <span class="text-zinc-500">({{ $package['warranty_percent'] }}% of job)</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:button wire:click="openEditModal({{ $package['id'] }})" variant="ghost" size="sm" icon="pencil" />
                                    <flux:button wire:click="deletePackage({{ $package['id'] }})" wire:confirm="Delete this package and all its items?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700" />
                                </div>
                            </div>

                            {{-- Items --}}
                            @if (count($package['items']) > 0)
                                <div class="mt-4 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <flux:text size="sm" class="font-medium">Add-on Services</flux:text>
                                        <flux:button wire:click="openItemModal({{ $package['id'] }})" variant="ghost" size="xs" icon="plus">
                                            Add Item
                                        </flux:button>
                                    </div>
                                    <div class="space-y-2">
                                        @foreach ($package['items'] as $item)
                                            <div class="flex items-center justify-between bg-zinc-50 dark:bg-zinc-800/50 rounded px-3 py-2" wire:key="item-{{ $item['id'] }}">
                                                <div class="flex items-center gap-3">
                                                    <flux:icon name="{{ $item['is_included_by_default'] ? 'check-circle' : 'circle' }}" class="h-4 w-4 {{ $item['is_included_by_default'] ? 'text-green-500' : 'text-zinc-400' }}" />
                                                    <div>
                                                        <flux:text size="sm" class="font-medium">{{ $item['name'] }}</flux:text>
                                                        <flux:text size="xs" class="text-zinc-500">
                                                            {{ $item['price_display'] }}
                                                            @if ($item['is_optional'])
                                                                <span class="text-zinc-400">&bull; Optional</span>
                                                            @else
                                                                <span class="text-zinc-400">&bull; Required</span>
                                                            @endif
                                                        </flux:text>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <flux:button wire:click="openItemModal({{ $package['id'] }}, {{ $item['id'] }})" variant="ghost" size="xs" icon="pencil" />
                                                    <flux:button wire:click="deleteItem({{ $item['id'] }})" wire:confirm="Delete this item?" variant="ghost" size="xs" icon="trash" class="text-red-600 hover:text-red-700" />
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="mt-4 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                                    <div class="flex items-center justify-between">
                                        <flux:text size="sm" class="text-zinc-400">No add-on services configured</flux:text>
                                        <flux:button wire:click="openItemModal({{ $package['id'] }})" variant="ghost" size="xs" icon="plus">
                                            Add Item
                                        </flux:button>
                                    </div>
                                </div>
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
                            $warrantyAmount = $paintingTotal * ($package['warranty_percent'] / 100);
                            $itemsTotal = collect($package['items'])->where('is_included_by_default', true)->sum(fn($i) => $i['pricing_type'] === 'percent' ? ($paintingTotal * ($i['percent'] / 100)) : $i['price']);
                            $packageTotal = $paintingTotal + $warrantyAmount + $itemsTotal;
                        @endphp
                        <flux:card class="p-4 text-center {{ $package['is_popular'] ? 'ring-2 ring-blue-500' : '' }}" wire:key="calc-{{ $package['id'] }}">
                            @if ($package['is_popular'])
                                <flux:badge color="blue" size="sm" class="mb-2">Popular</flux:badge>
                            @endif
                            <flux:heading size="base">{{ $package['name'] }}</flux:heading>
                            <flux:heading size="xl" class="mt-2 text-blue-600 dark:text-blue-400">${{ number_format($packageTotal, 0) }}</flux:heading>
                            <div class="mt-3 text-xs text-zinc-500 space-y-1">
                                <div>Painting: ${{ number_format($paintingTotal, 0) }}</div>
                                <div>Warranty: ${{ number_format($warrantyAmount, 0) }}</div>
                                @if ($itemsTotal > 0)
                                    <div>Add-ons: ${{ number_format($itemsTotal, 0) }}</div>
                                @endif
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Edit Package Modal --}}
        <flux:modal wire:model.live="showEditModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading size="lg">{{ $editingPackageId ? 'Edit Package' : 'Add Package' }}</flux:heading>

                <flux:input wire:model="editName" label="Package Name" placeholder="e.g., Good, Better, Best" required />

                <flux:input wire:model="editTier" label="Tier Identifier" placeholder="e.g., good, better, best" description="Unique identifier used internally" required />

                <flux:textarea wire:model="editDescription" label="Description" rows="2" placeholder="Brief description of this package tier" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="editWarrantyMonths" type="number" label="Warranty (months)" min="0" max="120" required />
                    <flux:input wire:model="editWarrantyPercent" type="number" step="0.01" label="Warranty Cost (%)" min="0" max="100" description="% of painting total" required />
                </div>

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
                <flux:heading size="lg">{{ $editingItemId ? 'Edit Service Item' : 'Add Service Item' }}</flux:heading>

                <flux:input wire:model="itemName" label="Service Name" placeholder="e.g., Post-Project Cleaning" required />

                <flux:textarea wire:model="itemDescription" label="Description" rows="2" placeholder="Brief description of this service" />

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
                </div>

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
