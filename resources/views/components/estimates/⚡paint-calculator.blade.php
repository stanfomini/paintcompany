<?php

use App\Models\Estimate;
use App\Models\EstimateAreaSurface;
use App\Services\PaintCalculatorService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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
}; ?>

<div>
    {{-- Standalone Calculator Form --}}
    <div class="space-y-6">
        {{-- Input Section --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Square Footage --}}
            <flux:field>
                <flux:label>Square Footage</flux:label>
                <flux:input
                    type="number"
                    wire:model.live.debounce.300ms="sqft"
                    min="0"
                    step="0.01"
                    placeholder="Enter sqft"
                />
            </flux:field>

            {{-- Number of Coats --}}
            <flux:field>
                <flux:label>Number of Coats</flux:label>
                <flux:select wire:model.live="coats">
                    <option value="1">1 Coat</option>
                    <option value="2">2 Coats</option>
                    <option value="3">3 Coats</option>
                </flux:select>
            </flux:field>

            {{-- Application Method --}}
            <flux:field>
                <flux:label>Application Method</flux:label>
                <flux:select wire:model.live="applicationMethod">
                    <option value="brush_roll">Brush/Roll ({{ $this->coverageMultipliers['brush_roll'] }}x coverage)</option>
                    <option value="spray">Spray ({{ $this->coverageMultipliers['spray'] }}x coverage)</option>
                </flux:select>
            </flux:field>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Paint Brand --}}
            <flux:field>
                <flux:label>Paint Brand</flux:label>
                <flux:select wire:model.live="paintBrand">
                    @foreach ($this->paintBrands as $brand)
                        <option value="{{ $brand }}">{{ ucwords(str_replace('_', ' ', $brand)) }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Paint Type --}}
            <flux:field>
                <flux:label>Paint Type</flux:label>
                <flux:select wire:model.live="paintType">
                    @foreach ($this->paintTypes as $type)
                        <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>

        {{-- Selected Paint Info --}}
        @if ($this->selectedPaintDetails)
            <flux:callout icon="paint-brush" color="blue">
                <strong>{{ ucwords(str_replace('_', ' ', $paintBrand)) }} - {{ ucwords(str_replace('_', ' ', $paintType)) }}</strong><br>
                Price: ${{ number_format($this->selectedPaintDetails['price'], 2) }}/gallon |
                Coverage: {{ $this->selectedPaintDetails['coverage_sqft'] }} sqft/gallon (base)
            </flux:callout>
        @endif

        {{-- Results Section --}}
        @if (!empty($calculation))
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-zinc-50 dark:bg-zinc-800/50">
                <h3 class="text-lg font-semibold mb-4">Paint Calculation Results</h3>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {{-- Gallons Needed --}}
                    <div class="text-center p-3 bg-white dark:bg-zinc-800 rounded-lg shadow-sm">
                        <div class="text-3xl font-bold text-blue-600 dark:text-blue-400">
                            {{ $calculation['gallons_rounded'] ?? 0 }}
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">Gallons Needed</div>
                        <div class="text-xs text-zinc-400">({{ $calculation['gallons'] ?? 0 }} exact)</div>
                    </div>

                    {{-- Coverage Used --}}
                    <div class="text-center p-3 bg-white dark:bg-zinc-800 rounded-lg shadow-sm">
                        <div class="text-3xl font-bold text-green-600 dark:text-green-400">
                            {{ $calculation['coverage_used'] ?? 0 }}
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">Sqft/Gallon</div>
                        <div class="text-xs text-zinc-400">(adjusted for method)</div>
                    </div>

                    {{-- Paint Cost --}}
                    <div class="text-center p-3 bg-white dark:bg-zinc-800 rounded-lg shadow-sm">
                        <div class="text-3xl font-bold text-amber-600 dark:text-amber-400">
                            ${{ number_format($calculation['paint_cost'] ?? 0, 2) }}
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">Paint Cost</div>
                        <div class="text-xs text-zinc-400">${{ number_format($calculation['price_per_gallon'] ?? 0, 2) }}/gal</div>
                    </div>

                    {{-- Total Cost --}}
                    <div class="text-center p-3 bg-white dark:bg-zinc-800 rounded-lg shadow-sm">
                        <div class="text-3xl font-bold text-purple-600 dark:text-purple-400">
                            ${{ number_format($calculation['total_cost'] ?? 0, 2) }}
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">Total Material</div>
                        <div class="text-xs text-zinc-400">+${{ number_format($calculation['recycle_fee'] ?? 0, 2) }} recycle</div>
                    </div>
                </div>

                {{-- Breakdown Table --}}
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            <tr>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">Total Area</td>
                                <td class="py-2 text-right font-medium">{{ number_format($sqft, 2) }} sqft</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">Total Coverage Needed ({{ $coats }} coats)</td>
                                <td class="py-2 text-right font-medium">{{ number_format($sqft * $coats, 2) }} sqft</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">Adjusted Coverage ({{ $applicationMethod === 'spray' ? 'Spray' : 'Brush/Roll' }})</td>
                                <td class="py-2 text-right font-medium">{{ $calculation['coverage_used'] ?? 0 }} sqft/gallon</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">Paint Price</td>
                                <td class="py-2 text-right font-medium">${{ number_format($calculation['price_per_gallon'] ?? 0, 2) }}/gallon</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">Gallons × Price</td>
                                <td class="py-2 text-right font-medium">${{ number_format($calculation['paint_cost'] ?? 0, 2) }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-zinc-500 dark:text-zinc-400">Recycle Fee ({{ $calculation['gallons_rounded'] ?? 0 }} × $0.54)</td>
                                <td class="py-2 text-right font-medium">${{ number_format($calculation['recycle_fee'] ?? 0, 2) }}</td>
                            </tr>
                            <tr class="font-semibold">
                                <td class="py-2">Total Material Cost</td>
                                <td class="py-2 text-right">${{ number_format($calculation['total_cost'] ?? 0, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($sqft > 0)
            <flux:callout icon="exclamation-triangle" color="amber">
                Unable to calculate. Please check paint selection.
            </flux:callout>
        @else
            <flux:callout icon="information-circle" color="zinc">
                Enter square footage to see paint calculation.
            </flux:callout>
        @endif

        {{-- Apply to Surface Button (if surfaceId provided) --}}
        @if ($surfaceId && !empty($calculation))
            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="applyToSurface">
                    Apply to Surface
                </flux:button>
            </div>
        @endif
    </div>
</div>
