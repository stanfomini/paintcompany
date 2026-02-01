<?php

namespace App\Livewire\ProductionRates;

use App\Models\NestedAreaTemplate;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use App\Services\ProductionRateService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $activeTab = 'interior';

    public ?int $editingCategoryId = null;

    public ?int $editingRateId = null;

    public bool $showCategoryModal = false;

    public bool $showRateModal = false;

    public bool $showDeleteModal = false;

    public ?string $deleteType = null;

    public ?int $deleteId = null;

    // Calculator modal properties
    public bool $showCalculatorModal = false;

    public ?int $calculatorRateId = null;

    public float $calculatorSqft = 500;

    public int $calculatorCoats = 2;

    public string $calculatorScopeType = 'interior';

    /** @var array<string, mixed>|null */
    public ?array $calculatorResult = null;

    // Category form state
    public string $categoryName = '';

    public string $categoryDescription = '';

    // Rate form state
    public string $rateName = '';

    public string $rateDescription = '';

    public string $rateUnitType = 'sqft';

    public string $rateProductionRate = '';

    public string $rateHourlyLaborRate = '';

    public string $rateMaterialCostPerUnit = '';

    public int $rateDefaultCoats = 2;

    public ?int $rateCategoryId = null;

    // ===== SUBSTRATE PROPERTIES =====
    public string $substrateSearch = '';

    public string $substrateScopeFilter = 'all';

    public string $substrateCategoryFilter = 'all';

    public bool $showSubstrateModal = false;

    public ?Substrate $editingSubstrate = null;

    public string $substrateName = '';

    public string $substrateCode = '';

    public string $substrateDescription = '';

    public string $substrateScopeType = 'interior';

    public string $substrateCategory = 'standard';

    public bool $substrateRequiresPrime = false;

    public bool $substrateRequiresSand = false;

    public bool $substrateRequiresScrape = false;

    public bool $substrateRequiresWash = false;

    public float $substratePrepTimeMultiplier = 1.0;

    public float $substratePaintTimeMultiplier = 1.0;

    public float $substrateMaterialMultiplier = 1.0;

    public ?int $substrateDefaultProductionRate = null;

    public bool $substrateIsActive = true;

    public int $substrateSortOrder = 0;

    // ===== PAINT CATALOG PROPERTIES =====
    public string $paintSearch = '';

    public string $paintBrandFilter = 'all';

    public string $paintScopeFilter = 'all';

    public string $paintTypeFilter = 'all';

    public bool $showBrandModal = false;

    public ?PaintBrand $editingBrand = null;

    public string $brandName = '';

    public string $brandCode = '';

    public string $brandWebsite = '';

    public bool $brandIsActive = true;

    public int $brandSortOrder = 0;

    public bool $showProductModal = false;

    public ?PaintProduct $editingProduct = null;

    public ?int $productBrandId = null;

    public string $productName = '';

    public string $productCode = '';

    public string $productDescription = '';

    public string $productSheen = 'eggshell';

    public string $productType = 'paint';

    public string $productScopeType = 'interior';

    public string $productSurfaceType = 'walls';

    public float $pricePerGallon = 0;

    public ?float $pricePerFiveGallon = null;

    public int $coverageBrushRoll = 400;

    public int $coverageSpray = 550;

    public bool $isPremium = false;

    public bool $isLowVoc = false;

    public bool $isSelfPriming = false;

    public int $recommendedCoats = 2;

    public float $secondCoatMultiplier = 1.25;

    public bool $productIsActive = true;

    public int $productSortOrder = 0;

    // ===== NESTED AREA TEMPLATE PROPERTIES =====
    public string $templateSearch = '';

    public string $templateCategoryFilter = 'all';

    public bool $showTemplateModal = false;

    public ?NestedAreaTemplate $editingTemplate = null;

    public string $templateName = '';

    public string $templateCode = '';

    public string $templateDescription = '';

    public string $templateCategory = 'closet';

    public string $templateScopeType = 'interior';

    public float $templateFixedPrice = 0;

    public bool $templateIncludeInPackagePricing = true;

    public float $templateDefaultLength = 4;

    public float $templateDefaultWidth = 3;

    public float $templateDefaultHeight = 8;

    public bool $templateIncludesWalls = true;

    public bool $templateIncludesCeiling = true;

    public bool $templateIncludesTrim = false;

    public bool $templateIncludesDoor = false;

    public ?int $templateSubstrateId = null;

    public ?int $templateDefaultPaintProductId = null;

    public bool $templateIsActive = true;

    public int $templateSortOrder = 0;

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function openCategoryModal(?int $id = null): void
    {
        $this->resetCategoryForm();
        $this->editingCategoryId = $id;

        if ($id) {
            $category = ProductionRateCategory::find($id);
            if ($category) {
                $this->categoryName = $category->name;
                $this->categoryDescription = $category->description ?? '';
            }
        }

        $this->showCategoryModal = true;
    }

    public function saveCategory(): void
    {
        $this->validate([
            'categoryName' => 'required|string|max:255',
            'categoryDescription' => 'nullable|string|max:1000',
        ]);

        if ($this->editingCategoryId) {
            $category = ProductionRateCategory::findOrFail($this->editingCategoryId);
            $category->update([
                'name' => $this->categoryName,
                'description' => $this->categoryDescription,
            ]);
        } else {
            $maxSortOrder = ProductionRateCategory::where('type', $this->activeTab)->max('sort_order') ?? 0;
            ProductionRateCategory::create([
                'name' => $this->categoryName,
                'type' => $this->activeTab,
                'description' => $this->categoryDescription,
                'sort_order' => $maxSortOrder + 1,
                'is_active' => true,
            ]);
        }

        $this->showCategoryModal = false;
        $this->resetCategoryForm();
        unset($this->categories);
    }

    public function openRateModal(?int $id = null, ?int $categoryId = null): void
    {
        $this->resetRateForm();
        $this->editingRateId = $id;
        $this->rateCategoryId = $categoryId;

        if ($id) {
            $rate = ProductionRate::find($id);
            if ($rate) {
                $this->rateName = $rate->name;
                $this->rateDescription = $rate->description ?? '';
                $this->rateUnitType = $rate->unit_type;
                $this->rateProductionRate = (string) $rate->production_rate;
                $this->rateHourlyLaborRate = (string) $rate->hourly_labor_rate;
                $this->rateMaterialCostPerUnit = (string) $rate->material_cost_per_unit;
                $this->rateDefaultCoats = $rate->default_coats ?? 2;
                $this->rateCategoryId = $rate->category_id;
            }
        }

        $this->showRateModal = true;
    }

    public function saveRate(): void
    {
        $this->validate([
            'rateName' => 'required|string|max:255',
            'rateDescription' => 'nullable|string|max:1000',
            'rateUnitType' => 'required|in:sqft,lnft,unit,hour',
            'rateProductionRate' => 'required|numeric|min:0.01',
            'rateHourlyLaborRate' => 'required|numeric|min:0',
            'rateMaterialCostPerUnit' => 'required|numeric|min:0',
            'rateDefaultCoats' => 'required|integer|min:1|max:5',
            'rateCategoryId' => 'required|exists:production_rate_categories,id',
        ]);

        $defaultCoatMultipliers = [
            '1' => 1.0,
            '2' => 1.8,
            '3' => 2.5,
        ];

        if ($this->editingRateId) {
            $rate = ProductionRate::findOrFail($this->editingRateId);
            $rate->update([
                'name' => $this->rateName,
                'description' => $this->rateDescription,
                'unit_type' => $this->rateUnitType,
                'production_rate' => $this->rateProductionRate,
                'hourly_labor_rate' => $this->rateHourlyLaborRate,
                'material_cost_per_unit' => $this->rateMaterialCostPerUnit,
                'default_coats' => $this->rateDefaultCoats,
                'category_id' => $this->rateCategoryId,
            ]);
        } else {
            $maxSortOrder = ProductionRate::where('category_id', $this->rateCategoryId)->max('sort_order') ?? 0;
            ProductionRate::create([
                'name' => $this->rateName,
                'description' => $this->rateDescription,
                'unit_type' => $this->rateUnitType,
                'production_rate' => $this->rateProductionRate,
                'hourly_labor_rate' => $this->rateHourlyLaborRate,
                'material_cost_per_unit' => $this->rateMaterialCostPerUnit,
                'default_coats' => $this->rateDefaultCoats,
                'category_id' => $this->rateCategoryId,
                'coat_multipliers' => $defaultCoatMultipliers,
                'sort_order' => $maxSortOrder + 1,
                'is_active' => true,
            ]);
        }

        $this->showRateModal = false;
        $this->resetRateForm();
        unset($this->categories);
    }

    public function confirmDelete(string $type, int $id): void
    {
        $this->deleteType = $type;
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if ($this->deleteType === 'category' && $this->deleteId) {
            $category = ProductionRateCategory::find($this->deleteId);
            if ($category) {
                $category->rates()->delete();
                $category->delete();
            }
        } elseif ($this->deleteType === 'rate' && $this->deleteId) {
            ProductionRate::destroy($this->deleteId);
        }

        $this->showDeleteModal = false;
        $this->deleteType = null;
        $this->deleteId = null;
        unset($this->categories);
    }

    public function seedDefaults(): void
    {
        $service = new ProductionRateService;
        $service->seedDefaultRates();
        unset($this->categories);
    }

    public function openCalculator(int $rateId): void
    {
        $rate = ProductionRate::find($rateId);
        $this->calculatorRateId = $rateId;
        $this->calculatorSqft = 500;
        $this->calculatorCoats = 2;
        // Default scope type based on rate category
        $this->calculatorScopeType = $rate?->category?->type ?? 'interior';
        $this->calculatorResult = null;
        $this->showCalculatorModal = true;

        // Calculate initial result
        $this->calculatePreview();
    }

    /**
     * Calculate preview using PBP (Painting Business Pricing) formula.
     *
     * Uses scope-specific rates from CompanySetting.
     */
    public function calculatePreview(): void
    {
        if (! $this->calculatorRateId) {
            $this->calculatorResult = null;

            return;
        }

        $service = app(ProductionRateService::class);
        $this->calculatorResult = $service->testCalculatorPBP(
            quantity: $this->calculatorSqft,
            rateId: $this->calculatorRateId,
            coats: $this->calculatorCoats,
            scopeType: $this->calculatorScopeType
        );
    }

    public function updatedCalculatorSqft(): void
    {
        if ($this->calculatorRateId) {
            $this->calculatePreview();
        }
    }

    public function updatedCalculatorCoats(): void
    {
        if ($this->calculatorRateId) {
            $this->calculatePreview();
        }
    }

    public function updatedCalculatorScopeType(): void
    {
        if ($this->calculatorRateId) {
            $this->calculatePreview();
        }
    }

    public function closeCalculator(): void
    {
        $this->showCalculatorModal = false;
        $this->calculatorRateId = null;
        $this->calculatorSqft = 500;
        $this->calculatorCoats = 2;
        $this->calculatorScopeType = 'interior';
        $this->calculatorResult = null;
    }

    /**
     * @return Collection<int, ProductionRateCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ProductionRateCategory::query()
            ->byType($this->activeTab)
            ->with(['rates' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
    }

    protected function resetCategoryForm(): void
    {
        $this->categoryName = '';
        $this->categoryDescription = '';
        $this->editingCategoryId = null;
    }

    protected function resetRateForm(): void
    {
        $this->rateName = '';
        $this->rateDescription = '';
        $this->rateUnitType = 'sqft';
        $this->rateProductionRate = '';
        $this->rateHourlyLaborRate = '';
        $this->rateMaterialCostPerUnit = '';
        $this->rateDefaultCoats = 2;
        $this->rateCategoryId = null;
        $this->editingRateId = null;
    }

    // ===== SUBSTRATE METHODS =====
    public function updatedSubstrateSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSubstrateScopeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSubstrateCategoryFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function substrates()
    {
        $query = Substrate::query()->orderBy('sort_order');

        if ($this->substrateSearch) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->substrateSearch}%")
                    ->orWhere('code', 'like', "%{$this->substrateSearch}%");
            });
        }

        if ($this->substrateScopeFilter !== 'all') {
            $query->where('scope_type', $this->substrateScopeFilter);
        }

        if ($this->substrateCategoryFilter !== 'all') {
            $query->where('category', $this->substrateCategoryFilter);
        }

        return $query->paginate(15);
    }

    public function openSubstrateModal(?int $id = null): void
    {
        $this->resetSubstrateForm();

        if ($id) {
            $this->editingSubstrate = Substrate::findOrFail($id);
            $this->substrateName = $this->editingSubstrate->name;
            $this->substrateCode = $this->editingSubstrate->code;
            $this->substrateDescription = $this->editingSubstrate->description ?? '';
            $this->substrateScopeType = $this->editingSubstrate->scope_type;
            $this->substrateCategory = $this->editingSubstrate->category;
            $this->substrateRequiresPrime = $this->editingSubstrate->requires_prime;
            $this->substrateRequiresSand = $this->editingSubstrate->requires_sand;
            $this->substrateRequiresScrape = $this->editingSubstrate->requires_scrape;
            $this->substrateRequiresWash = $this->editingSubstrate->requires_wash;
            $this->substratePrepTimeMultiplier = (float) $this->editingSubstrate->prep_time_multiplier;
            $this->substratePaintTimeMultiplier = (float) $this->editingSubstrate->paint_time_multiplier;
            $this->substrateMaterialMultiplier = (float) $this->editingSubstrate->material_multiplier;
            $this->substrateDefaultProductionRate = $this->editingSubstrate->default_production_rate;
            $this->substrateIsActive = $this->editingSubstrate->is_active;
            $this->substrateSortOrder = $this->editingSubstrate->sort_order;
        }

        $this->showSubstrateModal = true;
    }

    public function closeSubstrateModal(): void
    {
        $this->showSubstrateModal = false;
        $this->resetSubstrateForm();
    }

    protected function resetSubstrateForm(): void
    {
        $this->editingSubstrate = null;
        $this->substrateName = '';
        $this->substrateCode = '';
        $this->substrateDescription = '';
        $this->substrateScopeType = 'interior';
        $this->substrateCategory = 'standard';
        $this->substrateRequiresPrime = false;
        $this->substrateRequiresSand = false;
        $this->substrateRequiresScrape = false;
        $this->substrateRequiresWash = false;
        $this->substratePrepTimeMultiplier = 1.0;
        $this->substratePaintTimeMultiplier = 1.0;
        $this->substrateMaterialMultiplier = 1.0;
        $this->substrateDefaultProductionRate = null;
        $this->substrateIsActive = true;
        $this->substrateSortOrder = 0;
        $this->resetValidation();
    }

    public function saveSubstrate(): void
    {
        $this->validate([
            'substrateName' => ['required', 'string', 'max:255'],
            'substrateCode' => ['required', 'string', 'max:50', $this->editingSubstrate ? "unique:substrates,code,{$this->editingSubstrate->id}" : 'unique:substrates,code'],
            'substrateDescription' => ['nullable', 'string', 'max:1000'],
            'substrateScopeType' => ['required', 'in:interior,exterior,both'],
            'substrateCategory' => ['required', 'in:standard,wood,masonry,metal'],
            'substratePrepTimeMultiplier' => ['required', 'numeric', 'min:0.1', 'max:10'],
            'substratePaintTimeMultiplier' => ['required', 'numeric', 'min:0.1', 'max:10'],
            'substrateMaterialMultiplier' => ['required', 'numeric', 'min:0.1', 'max:10'],
            'substrateDefaultProductionRate' => ['nullable', 'integer', 'min:1'],
            'substrateSortOrder' => ['required', 'integer', 'min:0'],
        ]);

        $data = [
            'name' => $this->substrateName,
            'code' => strtoupper($this->substrateCode),
            'description' => $this->substrateDescription ?: null,
            'scope_type' => $this->substrateScopeType,
            'category' => $this->substrateCategory,
            'requires_prime' => $this->substrateRequiresPrime,
            'requires_sand' => $this->substrateRequiresSand,
            'requires_scrape' => $this->substrateRequiresScrape,
            'requires_wash' => $this->substrateRequiresWash,
            'prep_time_multiplier' => $this->substratePrepTimeMultiplier,
            'paint_time_multiplier' => $this->substratePaintTimeMultiplier,
            'material_multiplier' => $this->substrateMaterialMultiplier,
            'default_production_rate' => $this->substrateDefaultProductionRate,
            'is_active' => $this->substrateIsActive,
            'sort_order' => $this->substrateSortOrder,
        ];

        if ($this->editingSubstrate) {
            $this->editingSubstrate->update($data);
            session()->flash('success', 'Substrate updated successfully.');
        } else {
            Substrate::create($data);
            session()->flash('success', 'Substrate created successfully.');
        }

        $this->closeSubstrateModal();
        unset($this->substrates);
    }

    public function deleteSubstrate(int $id): void
    {
        $substrate = Substrate::findOrFail($id);

        if ($substrate->estimateAreas()->count() > 0) {
            session()->flash('error', 'Cannot delete substrate - it is used by estimate areas.');

            return;
        }

        $substrate->delete();
        session()->flash('success', 'Substrate deleted successfully.');
        unset($this->substrates);
    }

    public function toggleSubstrateActive(int $id): void
    {
        $substrate = Substrate::findOrFail($id);
        $substrate->update(['is_active' => ! $substrate->is_active]);
        unset($this->substrates);
    }

    // ===== PAINT CATALOG METHODS =====
    public function updatedPaintSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function paintBrands()
    {
        return PaintBrand::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function paintProducts()
    {
        $query = PaintProduct::with('brand')
            ->orderBy('paint_brand_id')
            ->orderBy('sort_order');

        if ($this->paintSearch) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->paintSearch}%")
                    ->orWhere('code', 'like', "%{$this->paintSearch}%");
            });
        }

        if ($this->paintBrandFilter !== 'all') {
            $query->where('paint_brand_id', $this->paintBrandFilter);
        }

        if ($this->paintScopeFilter !== 'all') {
            $query->forScope($this->paintScopeFilter);
        }

        if ($this->paintTypeFilter !== 'all') {
            $query->where('product_type', $this->paintTypeFilter);
        }

        return $query->paginate(15);
    }

    public function openBrandModal(?int $id = null): void
    {
        $this->resetBrandForm();

        if ($id) {
            $this->editingBrand = PaintBrand::findOrFail($id);
            $this->brandName = $this->editingBrand->name;
            $this->brandCode = $this->editingBrand->code;
            $this->brandWebsite = $this->editingBrand->website_url ?? '';
            $this->brandIsActive = $this->editingBrand->is_active;
            $this->brandSortOrder = $this->editingBrand->sort_order;
        }

        $this->showBrandModal = true;
    }

    public function closeBrandModal(): void
    {
        $this->showBrandModal = false;
        $this->resetBrandForm();
    }

    protected function resetBrandForm(): void
    {
        $this->editingBrand = null;
        $this->brandName = '';
        $this->brandCode = '';
        $this->brandWebsite = '';
        $this->brandIsActive = true;
        $this->brandSortOrder = 0;
        $this->resetValidation();
    }

    public function saveBrand(): void
    {
        $this->validate([
            'brandName' => ['required', 'string', 'max:255'],
            'brandCode' => ['required', 'string', 'max:20', $this->editingBrand ? "unique:paint_brands,code,{$this->editingBrand->id}" : 'unique:paint_brands,code'],
            'brandWebsite' => ['nullable', 'url', 'max:255'],
            'brandSortOrder' => ['required', 'integer', 'min:0'],
        ]);

        $data = [
            'name' => $this->brandName,
            'code' => strtoupper($this->brandCode),
            'website_url' => $this->brandWebsite ?: null,
            'is_active' => $this->brandIsActive,
            'sort_order' => $this->brandSortOrder,
        ];

        if ($this->editingBrand) {
            $this->editingBrand->update($data);
            session()->flash('success', 'Brand updated successfully.');
        } else {
            PaintBrand::create($data);
            session()->flash('success', 'Brand created successfully.');
        }

        $this->closeBrandModal();
        unset($this->paintBrands);
    }

    public function deleteBrand(int $id): void
    {
        $brand = PaintBrand::findOrFail($id);

        if ($brand->products()->count() > 0) {
            session()->flash('error', 'Cannot delete brand - it has products.');

            return;
        }

        $brand->delete();
        session()->flash('success', 'Brand deleted successfully.');
        unset($this->paintBrands);
    }

    public function openProductModal(?int $id = null): void
    {
        $this->resetProductForm();

        if ($id) {
            $this->editingProduct = PaintProduct::findOrFail($id);
            $this->productBrandId = $this->editingProduct->paint_brand_id;
            $this->productName = $this->editingProduct->name;
            $this->productCode = $this->editingProduct->code;
            $this->productDescription = $this->editingProduct->description ?? '';
            $this->productSheen = $this->editingProduct->sheen;
            $this->productType = $this->editingProduct->product_type;
            $this->productScopeType = $this->editingProduct->scope_type;
            $this->productSurfaceType = $this->editingProduct->surface_type;
            $this->pricePerGallon = (float) $this->editingProduct->price_per_gallon;
            $this->pricePerFiveGallon = $this->editingProduct->price_per_five_gallon ? (float) $this->editingProduct->price_per_five_gallon : null;
            $this->coverageBrushRoll = $this->editingProduct->coverage_sqft_brush_roll;
            $this->coverageSpray = $this->editingProduct->coverage_sqft_spray;
            $this->isPremium = $this->editingProduct->is_premium;
            $this->isLowVoc = $this->editingProduct->is_low_voc;
            $this->isSelfPriming = $this->editingProduct->is_self_priming;
            $this->recommendedCoats = $this->editingProduct->recommended_coats;
            $this->secondCoatMultiplier = (float) $this->editingProduct->second_coat_coverage_multiplier;
            $this->productIsActive = $this->editingProduct->is_active;
            $this->productSortOrder = $this->editingProduct->sort_order;
        }

        $this->showProductModal = true;
    }

    public function closeProductModal(): void
    {
        $this->showProductModal = false;
        $this->resetProductForm();
    }

    protected function resetProductForm(): void
    {
        $this->editingProduct = null;
        $this->productBrandId = null;
        $this->productName = '';
        $this->productCode = '';
        $this->productDescription = '';
        $this->productSheen = 'eggshell';
        $this->productType = 'paint';
        $this->productScopeType = 'interior';
        $this->productSurfaceType = 'walls';
        $this->pricePerGallon = 0;
        $this->pricePerFiveGallon = null;
        $this->coverageBrushRoll = 400;
        $this->coverageSpray = 550;
        $this->isPremium = false;
        $this->isLowVoc = false;
        $this->isSelfPriming = false;
        $this->recommendedCoats = 2;
        $this->secondCoatMultiplier = 1.25;
        $this->productIsActive = true;
        $this->productSortOrder = 0;
        $this->resetValidation();
    }

    public function saveProduct(): void
    {
        $this->validate([
            'productBrandId' => ['required', 'exists:paint_brands,id'],
            'productName' => ['required', 'string', 'max:255'],
            'productCode' => ['required', 'string', 'max:50', $this->editingProduct ? "unique:paint_products,code,{$this->editingProduct->id}" : 'unique:paint_products,code'],
            'productSheen' => ['required', 'in:flat,matte,eggshell,satin,semi-gloss,gloss'],
            'productType' => ['required', 'in:paint,primer,stain,sealer'],
            'productScopeType' => ['required', 'in:interior,exterior,both'],
            'productSurfaceType' => ['required', 'string', 'max:50'],
            'pricePerGallon' => ['required', 'numeric', 'min:0'],
            'pricePerFiveGallon' => ['nullable', 'numeric', 'min:0'],
            'coverageBrushRoll' => ['required', 'integer', 'min:50'],
            'coverageSpray' => ['required', 'integer', 'min:50'],
            'recommendedCoats' => ['required', 'integer', 'min:1', 'max:5'],
            'secondCoatMultiplier' => ['required', 'numeric', 'min:1', 'max:2'],
            'productSortOrder' => ['required', 'integer', 'min:0'],
        ]);

        $data = [
            'paint_brand_id' => $this->productBrandId,
            'name' => $this->productName,
            'code' => strtoupper($this->productCode),
            'description' => $this->productDescription ?: null,
            'sheen' => $this->productSheen,
            'product_type' => $this->productType,
            'scope_type' => $this->productScopeType,
            'surface_type' => $this->productSurfaceType,
            'price_per_gallon' => $this->pricePerGallon,
            'price_per_five_gallon' => $this->pricePerFiveGallon,
            'coverage_sqft_brush_roll' => $this->coverageBrushRoll,
            'coverage_sqft_spray' => $this->coverageSpray,
            'is_premium' => $this->isPremium,
            'is_low_voc' => $this->isLowVoc,
            'is_self_priming' => $this->isSelfPriming,
            'recommended_coats' => $this->recommendedCoats,
            'second_coat_coverage_multiplier' => $this->secondCoatMultiplier,
            'is_active' => $this->productIsActive,
            'sort_order' => $this->productSortOrder,
        ];

        if ($this->editingProduct) {
            $this->editingProduct->update($data);
            session()->flash('success', 'Product updated successfully.');
        } else {
            PaintProduct::create($data);
            session()->flash('success', 'Product created successfully.');
        }

        $this->closeProductModal();
        unset($this->paintProducts);
    }

    public function deleteProduct(int $id): void
    {
        $product = PaintProduct::findOrFail($id);

        if ($product->areaSelections()->count() > 0) {
            session()->flash('error', 'Cannot delete product - it is used in estimates.');

            return;
        }

        $product->delete();
        session()->flash('success', 'Product deleted successfully.');
        unset($this->paintProducts);
    }

    public function toggleProductActive(int $id): void
    {
        $product = PaintProduct::findOrFail($id);
        $product->update(['is_active' => ! $product->is_active]);
        unset($this->paintProducts);
    }

    // ===== NESTED AREA TEMPLATE METHODS =====
    public function updatedTemplateSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTemplateCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTemplateScopeType(): void
    {
        // Reset substrate and paint selections when scope changes
        // since they may no longer be valid for the new scope
        $this->templateSubstrateId = null;
        $this->templateDefaultPaintProductId = null;
        unset($this->availableSubstrates, $this->availablePaintProducts);
    }

    #[Computed]
    public function nestedTemplates()
    {
        $query = NestedAreaTemplate::query()
            ->with(['substrate', 'defaultPaintProduct.brand'])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($this->templateSearch) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->templateSearch}%")
                    ->orWhere('code', 'like', "%{$this->templateSearch}%");
            });
        }

        if ($this->templateCategoryFilter !== 'all') {
            $query->where('category', $this->templateCategoryFilter);
        }

        return $query->paginate(15);
    }

    /**
     * Get available substrates for template selection.
     *
     * Filters by the current template scope type if set.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Substrate>
     */
    #[Computed]
    public function availableSubstrates()
    {
        $query = Substrate::active()->orderBy('sort_order');

        if ($this->templateScopeType) {
            $query->forScope($this->templateScopeType);
        }

        return $query->get();
    }

    /**
     * Get available paint products for template selection.
     *
     * Filters by the current template scope type if set.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PaintProduct>
     */
    #[Computed]
    public function availablePaintProducts()
    {
        $query = PaintProduct::active()->with('brand')->orderBy('paint_brand_id')->orderBy('name');

        if ($this->templateScopeType) {
            $query->forScope($this->templateScopeType);
        }

        return $query->get();
    }

    public function openTemplateModal(?int $id = null): void
    {
        $this->resetTemplateForm();

        if ($id) {
            $this->editingTemplate = NestedAreaTemplate::findOrFail($id);
            $this->templateName = $this->editingTemplate->name;
            $this->templateCode = $this->editingTemplate->code;
            $this->templateDescription = $this->editingTemplate->description ?? '';
            $this->templateCategory = $this->editingTemplate->category;
            $this->templateScopeType = $this->editingTemplate->scope_type;
            $this->templateFixedPrice = (float) $this->editingTemplate->fixed_price;
            $this->templateIncludeInPackagePricing = $this->editingTemplate->include_in_package_pricing;
            $this->templateDefaultLength = (float) $this->editingTemplate->default_length;
            $this->templateDefaultWidth = (float) $this->editingTemplate->default_width;
            $this->templateDefaultHeight = (float) $this->editingTemplate->default_height;
            $this->templateIncludesWalls = $this->editingTemplate->includes_walls;
            $this->templateIncludesCeiling = $this->editingTemplate->includes_ceiling;
            $this->templateIncludesTrim = $this->editingTemplate->includes_trim;
            $this->templateIncludesDoor = $this->editingTemplate->includes_door;
            $this->templateSubstrateId = $this->editingTemplate->substrate_id;
            $this->templateDefaultPaintProductId = $this->editingTemplate->default_paint_product_id;
            $this->templateIsActive = $this->editingTemplate->is_active;
            $this->templateSortOrder = $this->editingTemplate->sort_order;
        }

        $this->showTemplateModal = true;
    }

    public function closeTemplateModal(): void
    {
        $this->showTemplateModal = false;
        $this->resetTemplateForm();
    }

    protected function resetTemplateForm(): void
    {
        $this->editingTemplate = null;
        $this->templateName = '';
        $this->templateCode = '';
        $this->templateDescription = '';
        $this->templateCategory = 'closet';
        $this->templateScopeType = 'interior';
        $this->templateFixedPrice = 0;
        $this->templateIncludeInPackagePricing = true;
        $this->templateDefaultLength = 4;
        $this->templateDefaultWidth = 3;
        $this->templateDefaultHeight = 8;
        $this->templateIncludesWalls = true;
        $this->templateIncludesCeiling = true;
        $this->templateIncludesTrim = false;
        $this->templateIncludesDoor = false;
        $this->templateSubstrateId = null;
        $this->templateDefaultPaintProductId = null;
        $this->templateIsActive = true;
        $this->templateSortOrder = 0;
        $this->resetValidation();
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'templateName' => ['required', 'string', 'max:255'],
            'templateCode' => ['required', 'string', 'max:50', $this->editingTemplate ? "unique:nested_area_templates,code,{$this->editingTemplate->id}" : 'unique:nested_area_templates,code'],
            'templateDescription' => ['nullable', 'string', 'max:1000'],
            'templateCategory' => ['required', 'in:closet,bathroom,utility,other'],
            'templateScopeType' => ['required', 'in:interior,exterior'],
            'templateFixedPrice' => ['required', 'numeric', 'min:0'],
            'templateDefaultLength' => ['required', 'numeric', 'min:0.5'],
            'templateDefaultWidth' => ['required', 'numeric', 'min:0.5'],
            'templateDefaultHeight' => ['required', 'numeric', 'min:1'],
            'templateSubstrateId' => ['nullable', 'exists:substrates,id'],
            'templateDefaultPaintProductId' => ['nullable', 'exists:paint_products,id'],
            'templateSortOrder' => ['required', 'integer', 'min:0'],
        ]);

        $data = [
            'name' => $this->templateName,
            'code' => strtoupper($this->templateCode),
            'description' => $this->templateDescription ?: null,
            'category' => $this->templateCategory,
            'scope_type' => $this->templateScopeType,
            'fixed_price' => $this->templateFixedPrice,
            'include_in_package_pricing' => $this->templateIncludeInPackagePricing,
            'default_length' => $this->templateDefaultLength,
            'default_width' => $this->templateDefaultWidth,
            'default_height' => $this->templateDefaultHeight,
            'includes_walls' => $this->templateIncludesWalls,
            'includes_ceiling' => $this->templateIncludesCeiling,
            'includes_trim' => $this->templateIncludesTrim,
            'includes_door' => $this->templateIncludesDoor,
            'substrate_id' => $this->templateSubstrateId,
            'default_paint_product_id' => $this->templateDefaultPaintProductId,
            'is_active' => $this->templateIsActive,
            'sort_order' => $this->templateSortOrder,
        ];

        if ($this->editingTemplate) {
            $this->editingTemplate->update($data);
            session()->flash('success', 'Template updated successfully.');
        } else {
            NestedAreaTemplate::create($data);
            session()->flash('success', 'Template created successfully.');
        }

        $this->closeTemplateModal();
        unset($this->nestedTemplates);
    }

    public function deleteTemplate(int $id): void
    {
        $template = NestedAreaTemplate::findOrFail($id);

        if ($template->estimateAreas()->count() > 0) {
            session()->flash('error', 'Cannot delete template - it is used in estimates.');

            return;
        }

        $template->delete();
        session()->flash('success', 'Template deleted successfully.');
        unset($this->nestedTemplates);
    }

    public function toggleTemplateActive(int $id): void
    {
        $template = NestedAreaTemplate::findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
        unset($this->nestedTemplates);
    }

    public function duplicateTemplate(int $id): void
    {
        $template = NestedAreaTemplate::findOrFail($id);

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name.' (Copy)';
        $newTemplate->code = $template->code.'-COPY';
        $newTemplate->save();

        session()->flash('success', 'Template duplicated successfully.');
        unset($this->nestedTemplates);
    }

    public function render(): View
    {
        return view('livewire.production-rates.index')
            ->layout('layouts.app', ['title' => 'Production Rates']);
    }
}
