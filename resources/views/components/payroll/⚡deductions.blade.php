<?php

use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Employee Deductions')]
class extends Component {
    public EmployeeProfile $employee;

    public bool $showModal = false;

    public ?int $editingId = null;

    // Form fields
    public string $type = 'health_insurance';

    public string $description = '';

    public string $amount = '';

    public bool $is_percentage = false;

    public bool $is_pretax = true;

    public string $effective_date = '';

    public string $end_date = '';

    public function boot(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }
    }

    public function mount(EmployeeProfile $employeeProfile): void
    {
        $this->employee = $employeeProfile->load('user');
    }

    #[Computed]
    public function deductions()
    {
        return $this->employee->deductions()
            ->orderByDesc('is_active')
            ->orderBy('type')
            ->get();
    }

    #[Computed]
    public function types(): array
    {
        return [
            'health_insurance' => 'Health Insurance',
            'dental' => 'Dental',
            'vision' => 'Vision',
            '401k' => '401(k)',
            'hsa' => 'HSA',
            'garnishment' => 'Garnishment',
            'child_support' => 'Child Support',
            'other' => 'Other',
        ];
    }

    public function openAdd(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $deduction = EmployeeDeduction::findOrFail($id);
        $this->editingId = $deduction->id;
        $this->type = $deduction->type;
        $this->description = $deduction->description;
        $this->amount = (string) $deduction->amount;
        $this->is_percentage = $deduction->is_percentage;
        $this->is_pretax = $deduction->is_pretax;
        $this->effective_date = $deduction->effective_date?->toDateString() ?? '';
        $this->end_date = $deduction->end_date?->toDateString() ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'type' => ['required', 'in:health_insurance,dental,vision,401k,hsa,garnishment,child_support,other'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'is_percentage' => ['boolean'],
            'is_pretax' => ['boolean'],
            'effective_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ]);

        $data = [
            'employee_profile_id' => $this->employee->id,
            'type' => $this->type,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'is_percentage' => $this->is_percentage,
            'is_pretax' => $this->is_pretax,
            'effective_date' => $this->effective_date ?: null,
            'end_date' => $this->end_date ?: null,
        ];

        if ($this->editingId) {
            EmployeeDeduction::where('id', $this->editingId)->update($data);
        } else {
            EmployeeDeduction::create($data);
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->deductions);
    }

    public function toggleActive(int $id): void
    {
        $deduction = EmployeeDeduction::findOrFail($id);
        $deduction->update(['is_active' => ! $deduction->is_active]);
        unset($this->deductions);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->type = 'health_insurance';
        $this->description = '';
        $this->amount = '';
        $this->is_percentage = false;
        $this->is_pretax = true;
        $this->effective_date = '';
        $this->end_date = '';
    }
};
?>

<div>
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400 mb-2">
            <a href="{{ route('employees.show', $employee) }}" class="hover:text-zinc-700 dark:hover:text-zinc-200" wire:navigate>
                {{ $employee->user->name }}
            </a>
            <span>/</span>
            <span>Deductions</span>
        </div>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">Deductions - {{ $employee->user->name }}</flux:heading>
                <flux:text class="mt-1">{{ $employee->employee_number }} - Manage payroll deductions.</flux:text>
            </div>

            <flux:button variant="primary" wire:click="openAdd">
                Add Deduction
            </flux:button>
        </div>
    </div>

    {{-- Deductions List --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Tax Treatment</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($this->deductions as $deduction)
                    <tr wire:key="ded-{{ $deduction->id }}" class="{{ !$deduction->is_active ? 'opacity-50' : '' }}">
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                            {{ $this->types[$deduction->type] ?? ucfirst($deduction->type) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $deduction->description }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            @if($deduction->is_percentage)
                                {{ number_format($deduction->amount, 2) }}%
                            @else
                                ${{ number_format($deduction->amount, 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :variant="$deduction->is_pretax ? 'primary' : 'outline'" size="sm">
                                {{ $deduction->is_pretax ? 'Pre-tax' : 'Post-tax' }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :variant="$deduction->is_active ? 'success' : 'outline'" size="sm">
                                {{ $deduction->is_active ? 'Active' : 'Inactive' }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="openEdit({{ $deduction->id }})">
                                    Edit
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $deduction->id }})">
                                    {{ $deduction->is_active ? 'Deactivate' : 'Activate' }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            No deductions set up for this employee.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit' : 'Add' }} Deduction</flux:heading>

            <flux:field>
                <flux:label>Deduction Type</flux:label>
                <flux:select wire:model="type">
                    @foreach($this->types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="type" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:input wire:model="description" placeholder="e.g., Blue Cross PPO" />
                <flux:error name="description" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Amount</flux:label>
                    <flux:input type="number" step="0.01" wire:model="amount" placeholder="0.00" />
                    <flux:error name="amount" />
                </flux:field>

                <div class="flex items-end pb-1">
                    <flux:checkbox wire:model="is_percentage" label="Percentage of gross pay" />
                </div>
            </div>

            <div class="flex gap-6">
                <flux:radio.group wire:model="is_pretax" label="Tax Treatment">
                    <flux:radio value="1" label="Pre-tax" />
                    <flux:radio value="" label="Post-tax" />
                </flux:radio.group>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Effective Date (optional)</flux:label>
                    <flux:input type="date" wire:model="effective_date" />
                    <flux:error name="effective_date" />
                </flux:field>

                <flux:field>
                    <flux:label>End Date (optional)</flux:label>
                    <flux:input type="date" wire:model="end_date" />
                    <flux:error name="end_date" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="save">
                    {{ $editingId ? 'Update' : 'Add' }} Deduction
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
