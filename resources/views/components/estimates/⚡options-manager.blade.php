<?php

use App\Models\Estimate;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateOption;
use App\Models\ServiceCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    /** @var array<int, array{id: int, name: string, description: ?string, pricing_type: string, fixed_price: ?float, surface_id: ?int, multiplier: ?float, calculated_price: float, is_selected: bool, is_recommended: bool}> */
    public array $options = [];

    public bool $showModal = false;

    public ?int $editingOptionId = null;

    public string $optionName = '';

    public string $optionDescription = '';

    public string $optionPricingType = 'fixed';

    public float $optionFixedPrice = 0;

    public ?int $optionSurfaceId = null;

    public float $optionMultiplier = 1;

    public bool $optionIsRecommended = false;

    public ?int $optionServiceCategoryId = null;

    protected function rules(): array
    {
        return [
            'optionName' => ['required', 'string', 'max:255'],
            'optionDescription' => ['nullable', 'string', 'max:1000'],
            'optionPricingType' => ['required', 'string', 'in:fixed,per_sqft,per_surface'],
            'optionFixedPrice' => ['required_if:optionPricingType,fixed', 'numeric', 'min:0'],
            'optionSurfaceId' => ['required_if:optionPricingType,per_sqft', 'required_if:optionPricingType,per_surface', 'nullable', 'integer', 'exists:estimate_area_surfaces,id'],
            'optionMultiplier' => ['required_if:optionPricingType,per_sqft', 'required_if:optionPricingType,per_surface', 'numeric', 'min:0'],
            'optionIsRecommended' => ['boolean'],
            'optionServiceCategoryId' => ['nullable', 'integer', 'exists:service_categories,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'optionName.required' => 'Please enter an option name.',
            'optionFixedPrice.required_if' => 'Please enter a fixed price.',
            'optionSurfaceId.required_if' => 'Please select a surface.',
            'optionMultiplier.required_if' => 'Please enter a multiplier.',
        ];
    }

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
        $this->loadOptions();
    }

    public function loadOptions(): void
    {
        $this->options = $this->estimate->options()
            ->with(['surface.area', 'serviceCategory'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (EstimateOption $option) {
                return [
                    'id' => $option->id,
                    'name' => $option->name,
                    'description' => $option->description,
                    'pricing_type' => $option->pricing_type,
                    'fixed_price' => (float) $option->fixed_price,
                    'surface_id' => $option->surface_id,
                    'surface_name' => $option->surface?->name,
                    'area_name' => $option->surface?->area?->name,
                    'multiplier' => (float) $option->multiplier,
                    'calculated_price' => (float) $option->calculated_price,
                    'is_selected' => $option->is_selected,
                    'is_recommended' => $option->is_recommended,
                    'service_category_id' => $option->service_category_id,
                    'category_name' => $option->serviceCategory?->name,
                ];
            })
            ->toArray();
    }

    public function openModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editingOptionId = $id;

        if ($id) {
            $option = EstimateOption::find($id);
            if ($option && $option->estimate_id === $this->estimate->id) {
                $this->optionName = $option->name;
                $this->optionDescription = $option->description ?? '';
                $this->optionPricingType = $option->pricing_type;
                $this->optionFixedPrice = (float) $option->fixed_price;
                $this->optionSurfaceId = $option->surface_id;
                $this->optionMultiplier = (float) ($option->multiplier ?? 1);
                $this->optionIsRecommended = $option->is_recommended;
                $this->optionServiceCategoryId = $option->service_category_id;
            }
        } else {
            $this->optionName = '';
            $this->optionDescription = '';
            $this->optionPricingType = 'fixed';
            $this->optionFixedPrice = 0;
            $this->optionSurfaceId = null;
            $this->optionMultiplier = 1;
            $this->optionIsRecommended = false;
            $this->optionServiceCategoryId = null;
        }

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->editingOptionId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'estimate_id' => $this->estimate->id,
            'name' => $this->optionName,
            'description' => $this->optionDescription ?: null,
            'pricing_type' => $this->optionPricingType,
            'fixed_price' => $this->optionPricingType === 'fixed' ? $this->optionFixedPrice : null,
            'surface_id' => in_array($this->optionPricingType, ['per_sqft', 'per_surface']) ? $this->optionSurfaceId : null,
            'multiplier' => in_array($this->optionPricingType, ['per_sqft', 'per_surface']) ? $this->optionMultiplier : null,
            'is_recommended' => $this->optionIsRecommended,
            'service_category_id' => $this->optionServiceCategoryId,
        ];

        if ($this->editingOptionId) {
            $option = EstimateOption::find($this->editingOptionId);
            if ($option && $option->estimate_id === $this->estimate->id) {
                $option->update($data);
            }
        } else {
            $maxSortOrder = $this->estimate->options()->max('sort_order') ?? -1;
            $data['sort_order'] = $maxSortOrder + 1;
            $data['is_selected'] = false;
            EstimateOption::create($data);
        }

        $this->estimate->calculateTotals();
        $this->estimate->save();

        $this->loadOptions();
        $this->closeModal();
        $this->dispatch('options-updated');
    }

    public function delete(int $id): void
    {
        $option = EstimateOption::find($id);
        if ($option && $option->estimate_id === $this->estimate->id) {
            $option->delete();

            $this->estimate->calculateTotals();
            $this->estimate->save();

            $this->loadOptions();
            $this->dispatch('options-updated');
        }
    }

    public function toggleSelected(int $id): void
    {
        $option = EstimateOption::find($id);
        if ($option && $option->estimate_id === $this->estimate->id) {
            $option->is_selected = ! $option->is_selected;
            $option->save();

            $this->estimate->calculateTotals();
            $this->estimate->save();

            $this->loadOptions();
            $this->dispatch('options-updated');
        }
    }

    #[Computed]
    public function optionsTotal(): float
    {
        return collect($this->options)
            ->where('is_selected', true)
            ->sum('calculated_price');
    }

    #[Computed]
    public function serviceCategories(): Collection
    {
        return ServiceCategory::active()->ordered()->get();
    }

    #[Computed]
    public function surfaces(): Collection
    {
        return EstimateAreaSurface::query()
            ->whereHas('area', function ($query) {
                $query->where('estimate_id', $this->estimate->id);
            })
            ->with('area')
            ->get()
            ->map(function (EstimateAreaSurface $surface) {
                return [
                    'id' => $surface->id,
                    'name' => $surface->area->name.' - '.$surface->name,
                    'quantity' => (float) $surface->quantity,
                    'total' => (float) $surface->total,
                ];
            });
    }

    public function getPricingTypeLabel(string $type): string
    {
        return EstimateOption::PRICING_TYPES[$type] ?? $type;
    }
}; ?>

<div>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg">
        <div class="flex items-center justify-between p-4 border-b border-zinc-200 dark:border-zinc-700">
            <flux:heading size="sm">Options</flux:heading>
            <flux:button wire:click="openModal" type="button" variant="ghost" size="sm" icon="plus">
                Add Option
            </flux:button>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($options as $option)
                <div wire:key="option-{{ $option['id'] }}" class="p-4">
                    <div class="flex items-start gap-4">
                        <div class="pt-1">
                            <flux:checkbox
                                wire:click="toggleSelected({{ $option['id'] }})"
                                :checked="$option['is_selected']"
                            />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <flux:text class="font-medium {{ $option['is_selected'] ? '' : 'text-zinc-500 dark:text-zinc-400' }}">
                                    {{ $option['name'] }}
                                </flux:text>

                                @if ($option['is_recommended'])
                                    <flux:badge color="green" size="sm">Recommended</flux:badge>
                                @endif

                                @if ($option['category_name'])
                                    <flux:badge color="blue" size="sm">{{ $option['category_name'] }}</flux:badge>
                                @endif

                                <flux:badge color="zinc" size="sm">
                                    {{ $this->getPricingTypeLabel($option['pricing_type']) }}
                                </flux:badge>
                            </div>

                            @if ($option['description'])
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-1">
                                    {{ $option['description'] }}
                                </flux:text>
                            @endif

                            @if ($option['surface_name'] && $option['area_name'])
                                <flux:text size="xs" class="text-zinc-400 dark:text-zinc-500">
                                    Based on: {{ $option['area_name'] }} - {{ $option['surface_name'] }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="text-right">
                            <flux:text class="font-semibold {{ $option['is_selected'] ? 'text-green-600 dark:text-green-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                ${{ number_format($option['calculated_price'], 2) }}
                            </flux:text>
                        </div>

                        <div class="flex items-center gap-1">
                            <flux:button
                                wire:click="openModal({{ $option['id'] }})"
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="pencil"
                            />
                            <flux:button
                                wire:click="delete({{ $option['id'] }})"
                                wire:confirm="Are you sure you want to delete this option?"
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                            />
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <flux:icon name="list-bullet" class="w-12 h-12 mx-auto text-zinc-400 dark:text-zinc-500 mb-3" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">No options added yet</flux:text>
                    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500 mt-1">
                        Options allow customers to add additional services to their estimate.
                    </flux:text>
                </div>
            @endforelse
        </div>

        @if (count($options) > 0)
            <div class="flex justify-between items-center p-4 bg-zinc-50 dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700 rounded-b-lg">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ collect($options)->where('is_selected', true)->count() }} of {{ count($options) }} options selected
                </flux:text>
                <div class="text-right">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Selected Options Total</flux:text>
                    <flux:text class="text-lg font-bold">${{ number_format($this->optionsTotal, 2) }}</flux:text>
                </div>
            </div>
        @endif
    </div>

    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingOptionId ? 'Edit Option' : 'Add Option' }}
            </flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input
                        wire:model="optionName"
                        placeholder="e.g., Premium Paint Upgrade"
                    />
                    <flux:error name="optionName" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea
                        wire:model="optionDescription"
                        rows="2"
                        placeholder="Describe what this option includes..."
                    />
                    <flux:error name="optionDescription" />
                </flux:field>

                @if ($this->serviceCategories->count() > 1)
                    <flux:field>
                        <flux:label>Service Category</flux:label>
                        <flux:select wire:model="optionServiceCategoryId">
                            <option value="">Painting (default)</option>
                            @foreach ($this->serviceCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="optionServiceCategoryId" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Pricing Type</flux:label>
                    <flux:select wire:model.live="optionPricingType">
                        <option value="fixed">Fixed Price</option>
                        <option value="per_sqft">Per Square Foot</option>
                        <option value="per_surface">Per Surface (Multiplier)</option>
                    </flux:select>
                    <flux:error name="optionPricingType" />
                </flux:field>

                @if ($optionPricingType === 'fixed')
                    <flux:field>
                        <flux:label>Fixed Price ($)</flux:label>
                        <flux:input
                            wire:model="optionFixedPrice"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                        />
                        <flux:error name="optionFixedPrice" />
                    </flux:field>
                @else
                    <flux:field>
                        <flux:label>Surface</flux:label>
                        <flux:select wire:model="optionSurfaceId">
                            <option value="">Select a surface...</option>
                            @foreach ($this->surfaces as $surface)
                                <option value="{{ $surface['id'] }}">
                                    {{ $surface['name'] }} ({{ number_format($surface['quantity'], 0) }} sqft)
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="optionSurfaceId" />
                        @if ($this->surfaces->isEmpty())
                            <flux:text size="sm" class="text-amber-600 dark:text-amber-400 mt-1">
                                No surfaces available. Add areas with surfaces first.
                            </flux:text>
                        @endif
                    </flux:field>

                    <flux:field>
                        <flux:label>
                            @if ($optionPricingType === 'per_sqft')
                                Price Per Square Foot ($)
                            @else
                                Multiplier
                            @endif
                        </flux:label>
                        <flux:input
                            wire:model="optionMultiplier"
                            type="number"
                            step="0.001"
                            min="0"
                            placeholder="{{ $optionPricingType === 'per_sqft' ? '0.50' : '1.5' }}"
                        />
                        <flux:error name="optionMultiplier" />
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-1">
                            @if ($optionPricingType === 'per_sqft')
                                Price = Square footage x Price per sqft
                            @else
                                Price = Surface base price x Multiplier
                            @endif
                        </flux:text>
                    </flux:field>
                @endif

                <div class="flex items-center gap-2">
                    <flux:switch wire:model="optionIsRecommended" />
                    <flux:label>Mark as Recommended</flux:label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeModal" type="button" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button wire:click="save" type="button" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">
                        {{ $editingOptionId ? 'Update Option' : 'Add Option' }}
                    </span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
