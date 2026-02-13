<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.budget_mappings')) {
            abort(403);
        }
    }

    public string $successMessage = '';

    #[Computed]
    public function sections(): array
    {
        $settings = CompanySetting::getInstance();

        $categories = BudgetCategory::where('company_setting_id', $settings->id)
            ->whereIn('section', [
                BudgetCategory::SECTION_VEHICLE_EXPENSE,
                BudgetCategory::SECTION_BUSINESS_EXPENSE,
                BudgetCategory::SECTION_VARIABLE_EXPENSE,
            ])
            ->with('accounts')
            ->orderBy('section')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('section');

        return $categories->toArray();
    }

    #[Computed]
    public function expenseAccounts(): \Illuminate\Support\Collection
    {
        $settings = CompanySetting::getInstance();

        return Account::where('company_setting_id', $settings->id)
            ->where('type', Account::TYPE_EXPENSE)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    public function updateMapping(int $categoryId, ?string $accountId): void
    {
        $this->authorize('settings.budget_mappings');

        $settings = CompanySetting::getInstance();
        $category = BudgetCategory::where('company_setting_id', $settings->id)
            ->findOrFail($categoryId);

        // Remove existing mappings for this category
        Account::where('company_setting_id', $settings->id)
            ->where('budget_category_id', $category->id)
            ->update(['budget_category_id' => null]);

        // Set new mapping if an account was selected
        if ($accountId) {
            $account = Account::where('company_setting_id', $settings->id)
                ->findOrFail($accountId);
            $account->update(['budget_category_id' => $category->id]);
        }

        unset($this->sections);
        $this->successMessage = __('Updated mapping for :name.', ['name' => __($category->name)]);
    }
};
?>

<x-pages::settings.layout :heading="__('Budget Mappings')" :subheading="__('Map budget categories to GL accounts for accurate expense tracking.')" :fullWidth="true">
    <div>
        @if ($successMessage)
            <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-300"
                 x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
                {{ $successMessage }}
            </div>
        @endif

        @foreach ([
            BudgetCategory::SECTION_VEHICLE_EXPENSE => __('Vehicle Expenses'),
            BudgetCategory::SECTION_BUSINESS_EXPENSE => __('Business Expenses'),
            BudgetCategory::SECTION_VARIABLE_EXPENSE => __('Variable Expenses (COGS)'),
        ] as $sectionKey => $sectionLabel)
            @if (isset($this->sections[$sectionKey]))
                <div class="mb-8">
                    <flux:heading size="lg" class="mb-3">{{ $sectionLabel }}</flux:heading>

                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Budget Category') }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('GL Account') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                                @foreach ($this->sections[$sectionKey] as $category)
                                    <tr wire:key="mapping-{{ $category['id'] }}">
                                        <td class="whitespace-nowrap px-4 py-2 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $category['name'] }}
                                        </td>
                                        <td class="px-4 py-2">
                                            @php
                                                $currentAccountId = collect($category['accounts'] ?? [])->first()['id'] ?? '';
                                            @endphp
                                            <flux:select
                                                wire:change="updateMapping({{ $category['id'] }}, $event.target.value)"
                                                class="w-80"
                                            >
                                                <option value="">{{ __('-- No mapping --') }}</option>
                                                @foreach ($this->expenseAccounts as $account)
                                                    <option value="{{ $account->id }}" @selected($account->id == $currentAccountId)>
                                                        {{ $account->code }} - {{ $account->name }}
                                                    </option>
                                                @endforeach
                                            </flux:select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endforeach

        <flux:callout variant="outline" icon="information-circle" class="mt-4">
            <flux:callout.heading>{{ __('How mappings work') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Each budget category is linked to a GL account. When you record overhead expenses or sync actuals, the system uses these mappings to route money to the correct account. Categories without a mapping will not sync actuals from journal entries.') }}
            </flux:callout.text>
        </flux:callout>
    </div>
</x-pages::settings.layout>
