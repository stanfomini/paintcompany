<?php

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    // Company Information
    public string $company_name = '';
    public string $company_email = '';
    public string $company_phone = '';
    public string $address_line_1 = '';
    public string $address_line_2 = '';
    public string $city = '';
    public string $state = '';
    public string $zip_code = '';

    // Timezone
    public string $timezone = 'America/New_York';

    // Defaults
    public string $accounting_mode = 'cash';
    public string $tax_reserve_percent = '30';
    public string $profit_reserve_percent = '5';
    public string $default_tax_rate = '';
    public string $default_payment_terms_days = '';
    public string $estimate_validity_days = '';
    public string $default_deposit_percent = '';

    // Labor Configuration
    public string $base_hourly_wage = '';
    public string $interior_hourly_wage = '';
    public string $exterior_hourly_wage = '';
    public string $payroll_burden_multiplier = '';

    // Margins & Markup
    public string $net_profit_margin_percent = '';
    public string $markup_percent = '';
    public string $margin_percent = '';
    public string $interior_margin_percent = '';
    public string $exterior_margin_percent = '';

    // Simple Estimate Defaults
    public string $default_interior_sqft_rate = '';
    public string $default_exterior_sqft_rate = '';
    public string $default_sales_discount_percent = '';
    public string $material_tax_rate = '';

    // Production Settings
    public string $cleanup_hours_ratio = '';
    public string $interior_cleanup_hours_ratio = '';
    public string $exterior_cleanup_hours_ratio = '';
    public string $sundries_cost_per_prep_hour = '';
    public string $interior_sundries_cost_per_hour = '';
    public string $exterior_sundries_cost_per_hour = '';
    public string $spray_paint_coverage_multiplier = '';
    public string $brush_roll_coverage_multiplier = '';

    // Misc Fees
    public string $recycle_fee_per_gallon = '';
    public string $wood_replacement_minimum = '';

    // Crew & Dispatch Settings
    public string $total_painters = '';
    public string $default_required_crew_size = '';

    // Proposal Display
    public string $excluded_surfaces_display = 'hidden';

    // Brand Ambassador Program
    public string $brand_ambassador_spots = '10';

    // Computed values for display
    public string $effective_hourly_rate = '';
    public string $interior_effective_rate = '';
    public string $exterior_effective_rate = '';
    public string $sample_price_calculation = '';

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $settings = CompanySetting::getInstance();

        // Company Information
        $this->company_name = $settings->company_name ?? '';
        $this->company_email = $settings->company_email ?? '';
        $this->company_phone = $settings->company_phone ?? '';
        $this->address_line_1 = $settings->address_line_1 ?? '';
        $this->address_line_2 = $settings->address_line_2 ?? '';
        $this->city = $settings->city ?? '';
        $this->state = $settings->state ?? '';
        $this->zip_code = $settings->zip_code ?? '';

        // Timezone
        $this->timezone = $settings->timezone ?? 'America/New_York';

        // Defaults
        $this->accounting_mode = $settings->accounting_mode ?? 'cash';
        $this->tax_reserve_percent = $this->formatDecimal($settings->tax_reserve_percent, '30.00');
        $this->profit_reserve_percent = $this->formatDecimal($settings->profit_reserve_percent, '5.00');
        $this->default_tax_rate = $this->formatDecimal($settings->default_tax_rate);
        $this->default_payment_terms_days = $settings->default_payment_terms_days !== null ? (string) $settings->default_payment_terms_days : '';
        $this->estimate_validity_days = $settings->estimate_validity_days !== null ? (string) $settings->estimate_validity_days : '';
        $this->default_deposit_percent = $this->formatDecimal($settings->default_deposit_percent, '30.00');

        // Labor Configuration
        $this->base_hourly_wage = $this->formatDecimal($settings->base_hourly_wage, '25.00');
        $this->interior_hourly_wage = $this->formatDecimal($settings->interior_hourly_wage, '21.00');
        $this->exterior_hourly_wage = $this->formatDecimal($settings->exterior_hourly_wage, '27.00');
        $this->payroll_burden_multiplier = $this->formatDecimal($settings->payroll_burden_multiplier, '1.35');

        // Margins & Markup
        $this->net_profit_margin_percent = $this->formatDecimal($settings->net_profit_margin_percent, '30.00');
        $this->markup_percent = $this->formatDecimal($settings->markup_percent, '45.00');
        $this->margin_percent = $this->formatDecimal($settings->margin_percent, '45.00');
        $this->interior_margin_percent = $this->formatDecimal($settings->interior_margin_percent, '50.00');
        $this->exterior_margin_percent = $this->formatDecimal($settings->exterior_margin_percent, '41.00');

        // Simple Estimate Defaults
        $this->default_interior_sqft_rate = $this->formatDecimal($settings->default_interior_sqft_rate, '4.50');
        $this->default_exterior_sqft_rate = $this->formatDecimal($settings->default_exterior_sqft_rate, '3.50');
        $this->default_sales_discount_percent = $this->formatDecimal($settings->default_sales_discount_percent, '10.00');
        $this->material_tax_rate = $this->formatDecimal($settings->material_tax_rate, '7.50');

        // Production Settings
        $this->cleanup_hours_ratio = $this->formatDecimal($settings->cleanup_hours_ratio, '16.00');
        $this->interior_cleanup_hours_ratio = $this->formatDecimal($settings->interior_cleanup_hours_ratio, '16.00');
        $this->exterior_cleanup_hours_ratio = $this->formatDecimal($settings->exterior_cleanup_hours_ratio, '20.00');
        $this->sundries_cost_per_prep_hour = $this->formatDecimal($settings->sundries_cost_per_prep_hour, '3.30');
        $this->interior_sundries_cost_per_hour = $this->formatDecimal($settings->interior_sundries_cost_per_hour, '3.30');
        $this->exterior_sundries_cost_per_hour = $this->formatDecimal($settings->exterior_sundries_cost_per_hour, '1.00');
        $this->spray_paint_coverage_multiplier = $this->formatDecimal($settings->spray_paint_coverage_multiplier, '2.00');
        $this->brush_roll_coverage_multiplier = $this->formatDecimal($settings->brush_roll_coverage_multiplier, '1.30');

        // Misc Fees
        $this->recycle_fee_per_gallon = $this->formatDecimal($settings->recycle_fee_per_gallon, '0.54');
        $this->wood_replacement_minimum = $this->formatDecimal($settings->wood_replacement_minimum, '300.00');

        // Crew & Dispatch Settings
        $this->total_painters = $settings->total_painters !== null ? (string) $settings->total_painters : '4';
        $this->default_required_crew_size = $settings->default_required_crew_size !== null ? (string) $settings->default_required_crew_size : '2';

        // Proposal Display
        $this->excluded_surfaces_display = $settings->excluded_surfaces_display ?? 'hidden';

        // Brand Ambassador Program
        $this->brand_ambassador_spots = $settings->brand_ambassador_spots !== null ? (string) $settings->brand_ambassador_spots : '10';

        $this->updateCalculatedValues();
    }

    private function formatDecimal($value, string $default = ''): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public function updated($property): void
    {
        $rateFields = [
            'base_hourly_wage', 'interior_hourly_wage', 'exterior_hourly_wage',
            'payroll_burden_multiplier', 'net_profit_margin_percent', 'overhead_percent',
            'markup_percent', 'margin_percent', 'interior_margin_percent', 'exterior_margin_percent',
        ];

        if (in_array($property, $rateFields)) {
            $this->updateCalculatedValues();
        }
    }

    private function updateCalculatedValues(): void
    {
        $burden = (float) ($this->payroll_burden_multiplier ?: 1.35);

        $baseWage = (float) ($this->base_hourly_wage ?: 25);
        $this->effective_hourly_rate = number_format($baseWage * $burden, 2);

        $interiorWage = (float) ($this->interior_hourly_wage ?: 21);
        $this->interior_effective_rate = number_format($interiorWage * $burden, 2);

        $exteriorWage = (float) ($this->exterior_hourly_wage ?: 27);
        $this->exterior_effective_rate = number_format($exteriorWage * $burden, 2);

        // Calculate sample: 100 sqft at 50 sqft/hr production rate, 2 coats, $20 materials (interior)
        $hours = 100 / 50; // 2 hours
        $interiorEffective = $interiorWage * $burden;
        $laborCost = $hours * $interiorEffective * 2; // 2 coats
        $materialCost = 20 * 2; // 2 coats
        $cogs = $laborCost + $materialCost;

        // PBP formula: OTD = COGS / (margin% / 100)
        $margin = (float) ($this->interior_margin_percent ?: 50);

        if ($margin <= 0) {
            $this->sample_price_calculation = number_format($cogs, 2);
            return;
        }

        $otd = $cogs / ($margin / 100);
        $this->sample_price_calculation = number_format($otd, 2);
    }

    public function updateCompanySettings(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            // Company Information
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip_code' => ['nullable', 'string', 'max:20'],

            // Timezone
            'timezone' => ['required', 'timezone:all'],

            // Defaults
            'accounting_mode' => ['required', 'in:cash,accrual,modified_cash'],
            'tax_reserve_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'profit_reserve_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'estimate_validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'default_deposit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Labor Configuration
            'base_hourly_wage' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'interior_hourly_wage' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'exterior_hourly_wage' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'payroll_burden_multiplier' => ['nullable', 'numeric', 'min:1', 'max:3'],

            // Margins & Markup
            'net_profit_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'interior_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'exterior_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],

            // Simple Estimate Defaults
            'default_interior_sqft_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_exterior_sqft_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_sales_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'material_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Production Settings
            'cleanup_hours_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interior_cleanup_hours_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'exterior_cleanup_hours_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sundries_cost_per_prep_hour' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interior_sundries_cost_per_hour' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'exterior_sundries_cost_per_hour' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'spray_paint_coverage_multiplier' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'brush_roll_coverage_multiplier' => ['nullable', 'numeric', 'min:0.1', 'max:10'],

            // Misc Fees
            'recycle_fee_per_gallon' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'wood_replacement_minimum' => ['nullable', 'numeric', 'min:0', 'max:10000'],

            // Crew & Dispatch Settings
            'total_painters' => ['nullable', 'integer', 'min:1', 'max:100'],
            'default_required_crew_size' => ['nullable', 'integer', 'min:1', 'max:20'],

            // Proposal Display
            'excluded_surfaces_display' => ['required', 'in:hidden,shown'],

            // Brand Ambassador Program
            'brand_ambassador_spots' => ['nullable', 'integer', 'min:0', 'max:100'],

        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            // Company Information
            'company_name' => $validated['company_name'],
            'company_email' => $validated['company_email'] ?: null,
            'company_phone' => $validated['company_phone'] ?: null,
            'address_line_1' => $validated['address_line_1'] ?: null,
            'address_line_2' => $validated['address_line_2'] ?: null,
            'city' => $validated['city'] ?: null,
            'state' => $validated['state'] ?: null,
            'zip_code' => $validated['zip_code'] ?: null,

            // Timezone
            'timezone' => $validated['timezone'],

            // Defaults
            'accounting_mode' => $validated['accounting_mode'],
            'tax_reserve_percent' => $validated['tax_reserve_percent'],
            'profit_reserve_percent' => $validated['profit_reserve_percent'],
            'default_tax_rate' => $validated['default_tax_rate'] !== '' ? $validated['default_tax_rate'] : 0,
            'default_payment_terms_days' => $validated['default_payment_terms_days'] !== '' ? $validated['default_payment_terms_days'] : 30,
            'estimate_validity_days' => $validated['estimate_validity_days'] !== '' ? $validated['estimate_validity_days'] : 30,
            'default_deposit_percent' => $validated['default_deposit_percent'] !== '' ? $validated['default_deposit_percent'] : 30,

            // Labor Configuration
            'base_hourly_wage' => $validated['base_hourly_wage'] !== '' ? $validated['base_hourly_wage'] : 25.00,
            'interior_hourly_wage' => $validated['interior_hourly_wage'] !== '' ? $validated['interior_hourly_wage'] : 21.00,
            'exterior_hourly_wage' => $validated['exterior_hourly_wage'] !== '' ? $validated['exterior_hourly_wage'] : 27.00,
            'payroll_burden_multiplier' => $validated['payroll_burden_multiplier'] !== '' ? $validated['payroll_burden_multiplier'] : 1.35,

            // Margins & Markup
            'net_profit_margin_percent' => $validated['net_profit_margin_percent'] !== '' ? $validated['net_profit_margin_percent'] : 30.00,
            'markup_percent' => $validated['markup_percent'] !== '' ? $validated['markup_percent'] : 45.00,
            'margin_percent' => $validated['margin_percent'] !== '' ? $validated['margin_percent'] : 45.00,
            'interior_margin_percent' => $validated['interior_margin_percent'] !== '' ? $validated['interior_margin_percent'] : 50.00,
            'exterior_margin_percent' => $validated['exterior_margin_percent'] !== '' ? $validated['exterior_margin_percent'] : 41.00,

            // Simple Estimate Defaults
            'default_interior_sqft_rate' => $validated['default_interior_sqft_rate'] !== '' ? $validated['default_interior_sqft_rate'] : 4.50,
            'default_exterior_sqft_rate' => $validated['default_exterior_sqft_rate'] !== '' ? $validated['default_exterior_sqft_rate'] : 3.50,
            'default_sales_discount_percent' => $validated['default_sales_discount_percent'] !== '' ? $validated['default_sales_discount_percent'] : 10.00,
            'material_tax_rate' => $validated['material_tax_rate'] !== '' ? $validated['material_tax_rate'] : 7.50,

            // Production Settings
            'cleanup_hours_ratio' => $validated['cleanup_hours_ratio'] !== '' ? $validated['cleanup_hours_ratio'] : 16.00,
            'interior_cleanup_hours_ratio' => $validated['interior_cleanup_hours_ratio'] !== '' ? $validated['interior_cleanup_hours_ratio'] : 16.00,
            'exterior_cleanup_hours_ratio' => $validated['exterior_cleanup_hours_ratio'] !== '' ? $validated['exterior_cleanup_hours_ratio'] : 20.00,
            'sundries_cost_per_prep_hour' => $validated['sundries_cost_per_prep_hour'] !== '' ? $validated['sundries_cost_per_prep_hour'] : 3.30,
            'interior_sundries_cost_per_hour' => $validated['interior_sundries_cost_per_hour'] !== '' ? $validated['interior_sundries_cost_per_hour'] : 3.30,
            'exterior_sundries_cost_per_hour' => $validated['exterior_sundries_cost_per_hour'] !== '' ? $validated['exterior_sundries_cost_per_hour'] : 1.00,
            'spray_paint_coverage_multiplier' => $validated['spray_paint_coverage_multiplier'] !== '' ? $validated['spray_paint_coverage_multiplier'] : 2.00,
            'brush_roll_coverage_multiplier' => $validated['brush_roll_coverage_multiplier'] !== '' ? $validated['brush_roll_coverage_multiplier'] : 1.30,

            // Misc Fees
            'recycle_fee_per_gallon' => $validated['recycle_fee_per_gallon'] !== '' ? $validated['recycle_fee_per_gallon'] : 0.54,
            'wood_replacement_minimum' => $validated['wood_replacement_minimum'] !== '' ? $validated['wood_replacement_minimum'] : 300.00,

            // Crew & Dispatch Settings
            'total_painters' => $validated['total_painters'] !== '' ? $validated['total_painters'] : 4,
            'default_required_crew_size' => $validated['default_required_crew_size'] !== '' ? $validated['default_required_crew_size'] : 2,

            // Proposal Display
            'excluded_surfaces_display' => $validated['excluded_surfaces_display'],

            // Brand Ambassador Program
            'brand_ambassador_spots' => $validated['brand_ambassador_spots'] !== '' ? $validated['brand_ambassador_spots'] : 10,

        ]);

        $this->dispatch('company-settings-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Company Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Company')" :subheading="__('Manage your company information and pricing configuration')">
        <form wire:submit="updateCompanySettings" class="my-6 w-full space-y-6">

            {{-- Company Information Section --}}
            <flux:heading size="sm">{{ __('Company Information') }}</flux:heading>

            <flux:input wire:model="company_name" :label="__('Company Name')" type="text" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="company_email" :label="__('Company Email')" type="email" />
                <flux:input wire:model="company_phone" :label="__('Company Phone')" type="text" />
            </div>

            <flux:separator />

            {{-- Address Section --}}
            <flux:heading size="sm">{{ __('Address') }}</flux:heading>

            <flux:input wire:model="address_line_1" :label="__('Address Line 1')" type="text" />
            <flux:input wire:model="address_line_2" :label="__('Address Line 2')" type="text" />

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="city" :label="__('City')" type="text" />
                <flux:input wire:model="state" :label="__('State')" type="text" />
                <flux:input wire:model="zip_code" :label="__('ZIP Code')" type="text" />
            </div>

            <flux:separator />

            {{-- Timezone Section --}}
            <flux:heading size="sm">{{ __('Timezone') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Used for calendar feeds, scheduling, and time displays across the application.') }}
            </flux:text>

            <flux:select wire:model="timezone" :label="__('Company Timezone')">
                @foreach ([
                    'America/New_York' => 'Eastern Time (ET)',
                    'America/Chicago' => 'Central Time (CT)',
                    'America/Denver' => 'Mountain Time (MT)',
                    'America/Los_Angeles' => 'Pacific Time (PT)',
                    'America/Anchorage' => 'Alaska Time (AKT)',
                    'Pacific/Honolulu' => 'Hawaii Time (HT)',
                    'America/Phoenix' => 'Arizona (no DST)',
                ] as $tz => $label)
                    <option value="{{ $tz }}">{{ __($label) }}</option>
                @endforeach
            </flux:select>

            <flux:separator />

            {{-- Estimate Defaults Section --}}
            <flux:heading size="sm">{{ __('Estimate Defaults') }}</flux:heading>

            <flux:select wire:model="accounting_mode" :label="__('Accounting Method')" :description="__('Cash = revenue when paid. Accrual = revenue when invoiced. Modified Cash = hybrid approach.')">
                <option value="cash">{{ __('Cash Basis') }}</option>
                <option value="accrual">{{ __('Accrual Basis') }}</option>
                <option value="modified_cash">{{ __('Modified Cash') }}</option>
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="tax_reserve_percent" :label="__('Income Tax Reserve (%)')" type="number" step="0.01" min="0" max="100" :description="__('Percentage of net profit set aside for estimated income taxes in journal entries.')" />
                <flux:input wire:model="profit_reserve_percent" :label="__('Profit Reserve (%)')" type="number" step="0.01" min="0" max="100" :description="__('Profit First: percentage of net profit moved to profit reserve. The remainder goes to retained earnings.')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="default_tax_rate" :label="__('Default Tax Rate (%)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="default_deposit_percent" :label="__('Deposit Required (%)')" type="number" step="0.01" min="0" max="100" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="default_payment_terms_days" :label="__('Payment Terms (days)')" type="number" min="0" max="365" />
                <flux:input wire:model="estimate_validity_days" :label="__('Estimate Validity (days)')" type="number" min="1" max="365" />
            </div>

            <flux:separator />

            {{-- Labor Configuration Section --}}
            <flux:heading size="sm">{{ __('Labor Configuration') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Configure labor costs per scope. Effective rate = Base Wage × Payroll Burden Multiplier.') }}
            </flux:text>

            <flux:input wire:model.live="payroll_burden_multiplier" :label="__('Payroll Burden Multiplier')" type="number" step="0.01" min="1" max="3" :description="__('Covers FICA, workers comp, insurance (e.g., 1.35 = 35% burden)')" />

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model.live="base_hourly_wage" :label="__('Default Wage ($/hr)')" type="number" step="0.01" min="0" max="500" :description="__('Fallback if scope not set')" />
                <flux:input wire:model.live="interior_hourly_wage" :label="__('Interior Wage ($/hr)')" type="number" step="0.01" min="0" max="500" />
                <flux:input wire:model.live="exterior_hourly_wage" :label="__('Exterior Wage ($/hr)')" type="number" step="0.01" min="0" max="500" />
            </div>

            <flux:callout variant="info" icon="calculator">
                <flux:callout.heading>{{ __('Effective Hourly Rates') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Interior') }}: ${{ $interior_effective_rate }}/hr &nbsp;|&nbsp;
                    {{ __('Exterior') }}: ${{ $exterior_effective_rate }}/hr &nbsp;|&nbsp;
                    {{ __('Default') }}: ${{ $effective_hourly_rate }}/hr
                </flux:callout.text>
            </flux:callout>

            <flux:separator />

            {{-- Margin Configuration Section --}}
            <flux:heading size="sm">{{ __('Pricing Margin') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Margin represents the COGS percentage of the sale price. Formula: OTD = COGS / (margin% / 100). Example: 50% means COGS is 50% of the price (2x multiplier).') }}
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model.live="margin_percent" :label="__('Default Margin (%)')" type="number" step="0.01" min="0" max="99.99" :description="__('Fallback if scope not set')" />
                <flux:input wire:model.live="interior_margin_percent" :label="__('Interior Margin (%)')" type="number" step="0.01" min="0" max="99.99" :description="__('50% = 2x COGS')" />
                <flux:input wire:model.live="exterior_margin_percent" :label="__('Exterior Margin (%)')" type="number" step="0.01" min="0" max="99.99" :description="__('41% = ~2.44x COGS')" />
            </div>

            <flux:callout variant="success" icon="currency-dollar">
                <flux:callout.heading>{{ __('Sample Interior Price') }}: ${{ $sample_price_calculation }}</flux:callout.heading>
                <flux:callout.text>{{ __('Example: 100 sqft wall at 50 sqft/hr, 2 coats, $20 materials using interior rates') }} → OTD {{ __('with') }} {{ $interior_margin_percent ?: '50' }}% {{ __('margin') }}.</flux:callout.text>
            </flux:callout>

            <flux:input wire:model="net_profit_margin_percent" :label="__('Target Net Profit (%)')" type="number" step="0.01" min="0" max="100" :description="__('Your target net profit after all allocations. Used for comparison in cost breakdowns.')" />

            <flux:separator />

            {{-- Simple Estimate Defaults Section --}}
            <flux:heading size="sm">{{ __('Simple Estimate ($/sqft)') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Default rates for quick $/sqft estimates.') }}
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="default_interior_sqft_rate" :label="__('Interior Rate ($/sqft)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="default_exterior_sqft_rate" :label="__('Exterior Rate ($/sqft)')" type="number" step="0.01" min="0" max="100" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="default_sales_discount_percent" :label="__('Sales Discount (%)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="material_tax_rate" :label="__('Material Tax Rate (%)')" type="number" step="0.01" min="0" max="100" />
            </div>

            <flux:separator />

            {{-- Production Settings Section --}}
            <flux:heading size="sm">{{ __('Production Settings') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Configure cleanup ratios and sundries costs per scope. Cleanup ratio = painting hours per 1 hour cleanup.') }}
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="cleanup_hours_ratio" :label="__('Default Cleanup Ratio')" type="number" step="0.01" min="0" max="100" :description="__('Fallback if scope not set')" />
                <flux:input wire:model="interior_cleanup_hours_ratio" :label="__('Interior Cleanup Ratio')" type="number" step="0.01" min="0" max="100" :description="__('e.g., 16 = 1hr cleanup per 16hrs paint')" />
                <flux:input wire:model="exterior_cleanup_hours_ratio" :label="__('Exterior Cleanup Ratio')" type="number" step="0.01" min="0" max="100" :description="__('e.g., 20 = 1hr cleanup per 20hrs paint')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="sundries_cost_per_prep_hour" :label="__('Default Sundries ($/hr)')" type="number" step="0.01" min="0" max="100" :description="__('Fallback if scope not set')" />
                <flux:input wire:model="interior_sundries_cost_per_hour" :label="__('Interior Sundries ($/hr)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="exterior_sundries_cost_per_hour" :label="__('Exterior Sundries ($/hr)')" type="number" step="0.01" min="0" max="100" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="spray_paint_coverage_multiplier" :label="__('Spray Coverage Multiplier')" type="number" step="0.01" min="0.1" max="10" :description="__('Production rate multiplier for spray application')" />
                <flux:input wire:model="brush_roll_coverage_multiplier" :label="__('Brush/Roll Coverage Multiplier')" type="number" step="0.01" min="0.1" max="10" :description="__('Production rate multiplier for brush/roll application')" />
            </div>

            <flux:separator />

            {{-- Crew & Dispatch Settings Section --}}
            <flux:heading size="sm">{{ __('Crew & Dispatch Settings') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Configure your painting team size for job dispatching. Jobs are dispatched to registered crews whose crew size meets the required minimum.') }}
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="total_painters" :label="__('Total Painters')" type="number" min="1" max="100" :description="__('Total number of painters in your company')" />
                <flux:input wire:model="default_required_crew_size" :label="__('Default Required Crew Size')" type="number" min="1" max="20" :description="__('Default minimum crew size for new jobs')" />
            </div>

            <flux:separator />

            {{-- Miscellaneous Fees Section --}}
            <flux:heading size="sm">{{ __('Miscellaneous Fees') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="recycle_fee_per_gallon" :label="__('Recycle Fee ($/gallon)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="wood_replacement_minimum" :label="__('Wood Replacement Minimum ($)')" type="number" step="0.01" min="0" max="10000" />
            </div>

            <flux:separator />

            {{-- Proposal Display Section --}}
            <flux:heading size="sm">{{ __('Proposal Display') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Excluded Surfaces on Proposals') }}</flux:label>
                <flux:select wire:model="excluded_surfaces_display">
                    <option value="hidden">{{ __('Hidden — excluded surfaces are not shown') }}</option>
                    <option value="shown">{{ __('Shown — excluded surfaces appear grayed out') }}</option>
                </flux:select>
                <flux:description>{{ __('When a surface is excluded from a package tier, choose whether to hide it completely or show it grayed out on the proposal.') }}</flux:description>
            </flux:field>

            <flux:separator />

            {{-- Brand Ambassador Program Section --}}
            <flux:heading size="sm">{{ __('Brand Ambassador Program') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Update daily. Shown as a countdown to customers on the Estimate Visit page.') }}
            </flux:text>

            <flux:input wire:model="brand_ambassador_spots" :label="__('Spots Remaining This Month')" type="number" min="0" max="100" :description="__('Number of brand ambassador spots available. Set to 0 to show applications closed.')" />

            <flux:separator />

            {{-- Save Button --}}
            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('Save Settings') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="company-settings-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
