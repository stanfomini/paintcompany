<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateOption;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class OptionsManager extends Component
{
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
            ->with('surface.area')
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
            }
        } else {
            $this->optionName = '';
            $this->optionDescription = '';
            $this->optionPricingType = 'fixed';
            $this->optionFixedPrice = 0;
            $this->optionSurfaceId = null;
            $this->optionMultiplier = 1;
            $this->optionIsRecommended = false;
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

    public function render(): View
    {
        return view('livewire.estimates.options-manager');
    }
}
