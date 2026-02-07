<?php

use App\Models\Account;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showAccountModal = false;
    public ?int $editingAccountId = null;
    public string $accountCode = '';
    public string $accountName = '';
    public string $accountType = 'expense';
    public ?int $parentId = null;

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('accounting.manage') ?? false;
    }

    #[Computed]
    public function accountsByType(): array
    {
        $settings = CompanySetting::getInstance();

        $accounts = Account::where('company_setting_id', $settings->id)
            ->orderBy('code')
            ->get();

        $grouped = [];
        foreach (Account::TYPES as $type => $label) {
            $grouped[$type] = [
                'label' => $label,
                'accounts' => $accounts->where('type', $type)->values(),
            ];
        }

        return $grouped;
    }

    public function openCreateModal(): void
    {
        $this->authorize('accounting.manage');

        $this->reset(['editingAccountId', 'accountCode', 'accountName', 'parentId']);
        $this->accountType = 'expense';
        $this->showAccountModal = true;
    }

    public function openEditModal(int $accountId): void
    {
        $this->authorize('accounting.manage');

        $account = Account::findOrFail($accountId);
        $this->editingAccountId = $account->id;
        $this->accountCode = $account->code;
        $this->accountName = $account->name;
        $this->accountType = $account->type;
        $this->parentId = $account->parent_id;
        $this->showAccountModal = true;
    }

    public function saveAccount(): void
    {
        $this->authorize('accounting.manage');

        $this->validate([
            'accountCode' => 'required|string|max:20',
            'accountName' => 'required|string|max:255',
            'accountType' => 'required|in:asset,liability,equity,revenue,expense',
        ]);

        $settings = CompanySetting::getInstance();

        if ($this->editingAccountId) {
            $account = Account::findOrFail($this->editingAccountId);
            $account->update([
                'code' => $this->accountCode,
                'name' => $this->accountName,
                'type' => $this->accountType,
                'normal_balance' => Account::defaultNormalBalance($this->accountType),
                'parent_id' => $this->parentId,
            ]);
        } else {
            Account::create([
                'company_setting_id' => $settings->id,
                'code' => $this->accountCode,
                'name' => $this->accountName,
                'type' => $this->accountType,
                'normal_balance' => Account::defaultNormalBalance($this->accountType),
                'parent_id' => $this->parentId,
                'is_system' => false,
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }

        $this->showAccountModal = false;
        unset($this->accountsByType);
    }

    public function toggleActive(int $accountId): void
    {
        $this->authorize('accounting.manage');

        $account = Account::findOrFail($accountId);
        $account->update(['is_active' => ! $account->is_active]);
        unset($this->accountsByType);
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">Chart of Accounts</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Manage your accounting chart of accounts.
            </flux:text>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                Add Account
            </flux:button>
        @endif
    </div>

    {{-- Account Groups --}}
    @foreach ($this->accountsByType as $type => $group)
        <div wire:key="group-{{ $type }}" class="mb-6">
            <div class="mb-2 flex items-center gap-2">
                <flux:heading size="md">{{ $group['label'] }}</flux:heading>
                <flux:badge size="sm" color="zinc">{{ $group['accounts']->count() }}</flux:badge>
            </div>

            @if ($group['accounts']->isEmpty())
                <flux:text class="text-zinc-400">No {{ strtolower($group['label']) }} accounts.</flux:text>
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Code</th>
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Normal Balance</th>
                                <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                @if ($this->canManage)
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @foreach ($group['accounts'] as $account)
                                <tr wire:key="account-{{ $account->id }}" class="{{ ! $account->is_active ? 'opacity-50' : '' }}">
                                    <td class="px-4 py-2 text-sm font-mono text-zinc-900 dark:text-zinc-100">{{ $account->code }}</td>
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                        {{ $account->name }}
                                        @if ($account->is_system)
                                            <flux:badge size="sm" color="zinc" class="ml-1">System</flux:badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ ucfirst($account->normal_balance) }}
                                    </td>
                                    <td class="px-4 py-2 text-center text-sm">
                                        <flux:badge size="sm" :color="$account->is_active ? 'green' : 'red'">
                                            {{ $account->is_active ? 'Active' : 'Inactive' }}
                                        </flux:badge>
                                    </td>
                                    @if ($this->canManage)
                                        <td class="px-4 py-2 text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                @if (! $account->is_system)
                                                    <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditModal({{ $account->id }})" />
                                                @endif
                                                <flux:button variant="ghost" size="sm"
                                                    :icon="$account->is_active ? 'eye-slash' : 'eye'"
                                                    wire:click="toggleActive({{ $account->id }})"
                                                    wire:confirm="{{ $account->is_active ? 'Deactivate this account?' : 'Reactivate this account?' }}" />
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    {{-- Account Modal --}}
    @if ($this->canManage)
        <flux:modal wire:model="showAccountModal" class="max-w-lg">
            <form wire:submit="saveAccount">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ $editingAccountId ? 'Edit Account' : 'Add Account' }}</flux:heading>

                    <flux:field>
                        <flux:label>Account Code</flux:label>
                        <flux:input wire:model="accountCode" placeholder="e.g. 5500" />
                        <flux:error name="accountCode" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Account Name</flux:label>
                        <flux:input wire:model="accountName" placeholder="e.g. Office Supplies" />
                        <flux:error name="accountName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Account Type</flux:label>
                        <flux:select wire:model="accountType">
                            @foreach (App\Models\Account::TYPES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="accountType" />
                    </flux:field>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button variant="ghost" wire:click="$set('showAccountModal', false)" type="button">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingAccountId ? 'Update' : 'Create' }}</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
