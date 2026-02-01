<?php

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    // Company Information
    public string $company_name = '';
    public string $company_email = '';
    public string $company_phone = '';
    public string $address_line_1 = '';
    public string $address_line_2 = '';
    public string $city = '';
    public string $state = '';
    public string $zip_code = '';

    // Defaults
    public string $default_tax_rate = '';
    public string $default_payment_terms_days = '';
    public string $estimate_validity_days = '';
    public string $default_deposit_percent = '';

    // Labor Configuration
    public string $base_hourly_wage = '';
    public string $payroll_burden_multiplier = '';

    // Margins & Markup
    public string $net_profit_margin_percent = '';
    public string $overhead_percent = '';
    public string $marketing_percent = '';
    public string $sales_percent = '';
    public string $production_management_percent = '';
    public string $markup_percent = '';

    // Simple Estimate Defaults
    public string $default_interior_sqft_rate = '';
    public string $default_exterior_sqft_rate = '';
    public string $default_sales_discount_percent = '';
    public string $material_tax_rate = '';

    // Production Settings
    public string $cleanup_hours_ratio = '';
    public string $sundries_cost_per_prep_hour = '';
    public string $spray_paint_coverage_multiplier = '';
    public string $brush_roll_coverage_multiplier = '';

    // Misc Fees
    public string $recycle_fee_per_gallon = '';
    public string $wood_replacement_minimum = '';

    // Computed values for display
    public string $effective_hourly_rate = '';
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

        // Defaults
        $this->default_tax_rate = $this->formatDecimal($settings->default_tax_rate);
        $this->default_payment_terms_days = $settings->default_payment_terms_days !== null ? (string) $settings->default_payment_terms_days : '';
        $this->estimate_validity_days = $settings->estimate_validity_days !== null ? (string) $settings->estimate_validity_days : '';
        $this->default_deposit_percent = $this->formatDecimal($settings->default_deposit_percent, '30.00');

        // Labor Configuration
        $this->base_hourly_wage = $this->formatDecimal($settings->base_hourly_wage, '25.00');
        $this->payroll_burden_multiplier = $this->formatDecimal($settings->payroll_burden_multiplier, '1.35');

        // Margins & Markup
        $this->net_profit_margin_percent = $this->formatDecimal($settings->net_profit_margin_percent, '30.00');
        $this->overhead_percent = $this->formatDecimal($settings->overhead_percent, '12.00');
        $this->marketing_percent = $this->formatDecimal($settings->marketing_percent);
        $this->sales_percent = $this->formatDecimal($settings->sales_percent);
        $this->production_management_percent = $this->formatDecimal($settings->production_management_percent);
        $this->markup_percent = $this->formatDecimal($settings->markup_percent, '45.00');

        // Simple Estimate Defaults
        $this->default_interior_sqft_rate = $this->formatDecimal($settings->default_interior_sqft_rate, '4.50');
        $this->default_exterior_sqft_rate = $this->formatDecimal($settings->default_exterior_sqft_rate, '3.50');
        $this->default_sales_discount_percent = $this->formatDecimal($settings->default_sales_discount_percent, '10.00');
        $this->material_tax_rate = $this->formatDecimal($settings->material_tax_rate, '7.50');

        // Production Settings
        $this->cleanup_hours_ratio = $this->formatDecimal($settings->cleanup_hours_ratio, '16.00');
        $this->sundries_cost_per_prep_hour = $this->formatDecimal($settings->sundries_cost_per_prep_hour, '3.30');
        $this->spray_paint_coverage_multiplier = $this->formatDecimal($settings->spray_paint_coverage_multiplier, '2.00');
        $this->brush_roll_coverage_multiplier = $this->formatDecimal($settings->brush_roll_coverage_multiplier, '1.30');

        // Misc Fees
        $this->recycle_fee_per_gallon = $this->formatDecimal($settings->recycle_fee_per_gallon, '0.54');
        $this->wood_replacement_minimum = $this->formatDecimal($settings->wood_replacement_minimum, '300.00');

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
        if (in_array($property, ['base_hourly_wage', 'payroll_burden_multiplier', 'net_profit_margin_percent', 'overhead_percent', 'markup_percent'])) {
            $this->updateCalculatedValues();
        }
    }

    private function updateCalculatedValues(): void
    {
        $wage = (float) ($this->base_hourly_wage ?: 25);
        $burden = (float) ($this->payroll_burden_multiplier ?: 1.35);
        $effectiveRate = $wage * $burden;
        $this->effective_hourly_rate = number_format($effectiveRate, 2);

        // Calculate sample: 100 sqft at 50 sqft/hr production rate, 2 coats, $20 materials
        $hours = 100 / 50; // 2 hours
        $laborCost = $hours * $effectiveRate * 2; // 2 coats
        $materialCost = 20 * 2; // 2 coats
        $baseCost = $laborCost + $materialCost;

        $profit = (float) ($this->net_profit_margin_percent ?: 30);
        $overhead = (float) ($this->overhead_percent ?: 12);
        $markup = (float) ($this->markup_percent ?: 45);

        $withProfit = $baseCost * (1 + $profit / 100);
        $withOverhead = $withProfit * (1 + $overhead / 100);
        $withMarkup = $withOverhead * (1 + $markup / 100);

        $this->sample_price_calculation = number_format($withMarkup, 2);
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

            // Defaults
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'estimate_validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'default_deposit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Labor Configuration
            'base_hourly_wage' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'payroll_burden_multiplier' => ['nullable', 'numeric', 'min:1', 'max:3'],

            // Margins & Markup
            'net_profit_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'overhead_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'marketing_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sales_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'production_management_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],

            // Simple Estimate Defaults
            'default_interior_sqft_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_exterior_sqft_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_sales_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'material_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Production Settings
            'cleanup_hours_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sundries_cost_per_prep_hour' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'spray_paint_coverage_multiplier' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'brush_roll_coverage_multiplier' => ['nullable', 'numeric', 'min:0.1', 'max:10'],

            // Misc Fees
            'recycle_fee_per_gallon' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'wood_replacement_minimum' => ['nullable', 'numeric', 'min:0', 'max:10000'],
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

            // Defaults
            'default_tax_rate' => $validated['default_tax_rate'] !== '' ? $validated['default_tax_rate'] : 0,
            'default_payment_terms_days' => $validated['default_payment_terms_days'] !== '' ? $validated['default_payment_terms_days'] : 30,
            'estimate_validity_days' => $validated['estimate_validity_days'] !== '' ? $validated['estimate_validity_days'] : 30,
            'default_deposit_percent' => $validated['default_deposit_percent'] !== '' ? $validated['default_deposit_percent'] : 30,

            // Labor Configuration
            'base_hourly_wage' => $validated['base_hourly_wage'] !== '' ? $validated['base_hourly_wage'] : 25.00,
            'payroll_burden_multiplier' => $validated['payroll_burden_multiplier'] !== '' ? $validated['payroll_burden_multiplier'] : 1.35,

            // Margins & Markup
            'net_profit_margin_percent' => $validated['net_profit_margin_percent'] !== '' ? $validated['net_profit_margin_percent'] : 30.00,
            'overhead_percent' => $validated['overhead_percent'] !== '' ? $validated['overhead_percent'] : 12.00,
            'marketing_percent' => $validated['marketing_percent'] !== '' ? $validated['marketing_percent'] : null,
            'sales_percent' => $validated['sales_percent'] !== '' ? $validated['sales_percent'] : null,
            'production_management_percent' => $validated['production_management_percent'] !== '' ? $validated['production_management_percent'] : null,
            'markup_percent' => $validated['markup_percent'] !== '' ? $validated['markup_percent'] : 45.00,

            // Simple Estimate Defaults
            'default_interior_sqft_rate' => $validated['default_interior_sqft_rate'] !== '' ? $validated['default_interior_sqft_rate'] : 4.50,
            'default_exterior_sqft_rate' => $validated['default_exterior_sqft_rate'] !== '' ? $validated['default_exterior_sqft_rate'] : 3.50,
            'default_sales_discount_percent' => $validated['default_sales_discount_percent'] !== '' ? $validated['default_sales_discount_percent'] : 10.00,
            'material_tax_rate' => $validated['material_tax_rate'] !== '' ? $validated['material_tax_rate'] : 7.50,

            // Production Settings
            'cleanup_hours_ratio' => $validated['cleanup_hours_ratio'] !== '' ? $validated['cleanup_hours_ratio'] : 16.00,
            'sundries_cost_per_prep_hour' => $validated['sundries_cost_per_prep_hour'] !== '' ? $validated['sundries_cost_per_prep_hour'] : 3.30,
            'spray_paint_coverage_multiplier' => $validated['spray_paint_coverage_multiplier'] !== '' ? $validated['spray_paint_coverage_multiplier'] : 2.00,
            'brush_roll_coverage_multiplier' => $validated['brush_roll_coverage_multiplier'] !== '' ? $validated['brush_roll_coverage_multiplier'] : 1.30,

            // Misc Fees
            'recycle_fee_per_gallon' => $validated['recycle_fee_per_gallon'] !== '' ? $validated['recycle_fee_per_gallon'] : 0.54,
            'wood_replacement_minimum' => $validated['wood_replacement_minimum'] !== '' ? $validated['wood_replacement_minimum'] : 300.00,
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
            <flux:heading size="sm">Company Information</flux:heading>

            <flux:input wire:model="company_name" :label="__('Company Name')" type="text" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="company_email" :label="__('Company Email')" type="email" />
                <flux:input wire:model="company_phone" :label="__('Company Phone')" type="text" />
            </div>

            <flux:separator />

            {{-- Address Section --}}
            <flux:heading size="sm">Address</flux:heading>

            <flux:input wire:model="address_line_1" :label="__('Address Line 1')" type="text" />
            <flux:input wire:model="address_line_2" :label="__('Address Line 2')" type="text" />

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="city" :label="__('City')" type="text" />
                <flux:input wire:model="state" :label="__('State')" type="text" />
                <flux:input wire:model="zip_code" :label="__('ZIP Code')" type="text" />
            </div>

            <flux:separator />

            {{-- Estimate Defaults Section --}}
            <flux:heading size="sm">Estimate Defaults</flux:heading>

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
            <flux:heading size="sm">Labor Configuration</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Configure your base labor costs. The effective hourly rate is calculated as: Base Wage × Payroll Burden.
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model.live="base_hourly_wage" :label="__('Base Hourly Wage ($)')" type="number" step="0.01" min="0" max="500" />
                <flux:input wire:model.live="payroll_burden_multiplier" :label="__('Payroll Burden Multiplier')" type="number" step="0.01" min="1" max="3" />
            </div>

            <flux:callout variant="info" icon="calculator">
                <flux:callout.heading>Effective Hourly Rate: ${{ $effective_hourly_rate }}</flux:callout.heading>
                <flux:callout.text>This is the actual cost per labor hour including payroll burden (taxes, insurance, benefits).</flux:callout.text>
            </flux:callout>

            <flux:separator />

            {{-- Margins & Markup Section --}}
            <flux:heading size="sm">Margins & Markup</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                These margins are applied sequentially to the base cost: Cost × (1 + Profit%) × (1 + Overhead%) × (1 + Markup%).
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model.live="net_profit_margin_percent" :label="__('Net Profit Margin (%)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model.live="overhead_percent" :label="__('Overhead (%)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model.live="markup_percent" :label="__('Markup (%)')" type="number" step="0.01" min="0" max="200" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="marketing_percent" :label="__('Marketing (%) (Optional)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="sales_percent" :label="__('Sales (%) (Optional)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="production_management_percent" :label="__('Prod. Mgmt (%) (Optional)')" type="number" step="0.01" min="0" max="100" />
            </div>

            <flux:callout variant="success" icon="currency-dollar">
                <flux:callout.heading>Sample Price: ${{ $sample_price_calculation }}</flux:callout.heading>
                <flux:callout.text>Example: 100 sqft wall at 50 sqft/hr, 2 coats, $20 materials → Final price with all margins.</flux:callout.text>
            </flux:callout>

            <flux:separator />

            {{-- Simple Estimate Defaults Section --}}
            <flux:heading size="sm">Simple Estimate ($/sqft)</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Default rates for quick $/sqft estimates.
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
            <flux:heading size="sm">Production Settings</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Fine-tune production rate calculations.
            </flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="cleanup_hours_ratio" :label="__('Cleanup Hours Ratio')" type="number" step="0.01" min="0" max="100" description="Hours of painting before 1 hour of cleanup" />
                <flux:input wire:model="sundries_cost_per_prep_hour" :label="__('Sundries Cost ($/prep hr)')" type="number" step="0.01" min="0" max="100" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="spray_paint_coverage_multiplier" :label="__('Spray Coverage Multiplier')" type="number" step="0.01" min="0.1" max="10" description="Production rate multiplier for spray application" />
                <flux:input wire:model="brush_roll_coverage_multiplier" :label="__('Brush/Roll Coverage Multiplier')" type="number" step="0.01" min="0.1" max="10" description="Production rate multiplier for brush/roll application" />
            </div>

            <flux:separator />

            {{-- Miscellaneous Fees Section --}}
            <flux:heading size="sm">Miscellaneous Fees</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="recycle_fee_per_gallon" :label="__('Recycle Fee ($/gallon)')" type="number" step="0.01" min="0" max="100" />
                <flux:input wire:model="wood_replacement_minimum" :label="__('Wood Replacement Minimum ($)')" type="number" step="0.01" min="0" max="10000" />
            </div>

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
