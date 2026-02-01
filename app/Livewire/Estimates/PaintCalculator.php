<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAreaSurface;
use App\Services\PaintCalculatorService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PaintCalculator extends Component
{
    public ?int $estimateId = null;

    public ?int $surfaceId = null;

    public float $sqft = 0;

    public int $coats = 2;

    public string $applicationMethod = 'brush_roll';

    public string $paintBrand = 'sherwin_williams';

    public string $paintType = 'superpaint';

    public bool $showModal = false;

    public array $calculation = [];

    protected PaintCalculatorService $calculatorService;

    public function boot(PaintCalculatorService $calculatorService): void
    {
        $this->calculatorService = $calculatorService;
    }

    public function mount(?int $estimateId = null, ?int $surfaceId = null): void
    {
        $this->estimateId = $estimateId;
        $this->surfaceId = $surfaceId;

        // Load surface data if provided
        if ($this->surfaceId) {
            $surface = EstimateAreaSurface::find($this->surfaceId);
            if ($surface) {
                $this->sqft = (float) $surface->quantity;
                $this->coats = $surface->coats ?? 2;
                $this->applicationMethod = $surface->application_method ?? 'brush_roll';
                $this->paintBrand = $surface->paint_brand ?? 'sherwin_williams';
                $this->paintType = $surface->paint_type ?? 'superpaint';
            }
        }

        $this->calculate();
    }

    #[Computed]
    public function paintBrands(): array
    {
        return $this->calculatorService->getAvailableBrands();
    }

    #[Computed]
    public function paintTypes(): array
    {
        return $this->calculatorService->getPaintTypesForBrand($this->paintBrand);
    }

    #[Computed]
    public function allPaints(): array
    {
        return $this->calculatorService->getAllPaintsFlat();
    }

    #[Computed]
    public function coverageMultipliers(): array
    {
        return $this->calculatorService->getCoverageMultipliers();
    }

    #[Computed]
    public function selectedPaintDetails(): ?array
    {
        return $this->calculatorService->getPaintDetails($this->paintBrand, $this->paintType);
    }

    public function updatedPaintBrand(): void
    {
        // Reset paint type to first available for this brand
        $types = $this->calculatorService->getPaintTypesForBrand($this->paintBrand);
        $this->paintType = $types[0] ?? 'superpaint';
        $this->calculate();
    }

    public function updatedPaintType(): void
    {
        $this->calculate();
    }

    public function updatedSqft(): void
    {
        $this->calculate();
    }

    public function updatedCoats(): void
    {
        $this->calculate();
    }

    public function updatedApplicationMethod(): void
    {
        $this->calculate();
    }

    public function calculate(): void
    {
        if ($this->sqft <= 0) {
            $this->calculation = [];

            return;
        }

        $result = $this->calculatorService->calculatePaintCost(
            $this->sqft,
            $this->paintBrand,
            $this->paintType,
            $this->coats,
            $this->applicationMethod
        );

        $this->calculation = $result ?? [];
    }

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function applyToSurface(): void
    {
        if (! $this->surfaceId) {
            return;
        }

        $surface = EstimateAreaSurface::find($this->surfaceId);
        if (! $surface) {
            return;
        }

        $surface->update([
            'application_method' => $this->applicationMethod,
            'paint_brand' => $this->paintBrand,
            'paint_type' => $this->paintType,
            'paint_gallons' => $this->calculation['gallons_rounded'] ?? null,
            'paint_cost' => $this->calculation['total_cost'] ?? null,
        ]);

        $this->dispatch('surface-paint-updated', surfaceId: $this->surfaceId);
        $this->closeModal();
    }

    public function calculateForEstimate(): array
    {
        if (! $this->estimateId) {
            return [];
        }

        $estimate = Estimate::with('areas.surfaces')->find($this->estimateId);
        if (! $estimate) {
            return [];
        }

        return $this->calculatorService->calculateForEstimate(
            $estimate,
            $this->paintBrand,
            $this->paintType
        );
    }

    public function render()
    {
        return view('livewire.estimates.paint-calculator');
    }
}
