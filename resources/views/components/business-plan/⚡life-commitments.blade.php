<?php

use App\Models\CompanySetting;
use App\Models\LifeCommitment;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $area = '';

    public string $commitment = '';

    public string $action_type = 'ongoing';

    public function boot(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function commitments()
    {
        return LifeCommitment::query()
            ->where('company_setting_id', CompanySetting::getInstance()->id)
            ->ordered()
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('business_plan.manage') ?? false;
    }

    public function openCreateModal(): void
    {
        $this->authorize('business_plan.manage');

        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);

        $this->editingId = $commitment->id;
        $this->area = $commitment->area;
        $this->commitment = $commitment->commitment;
        $this->action_type = $commitment->action_type;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'area' => ['required', 'string', 'max:255'],
            'commitment' => ['required', 'string', 'max:5000'],
            'action_type' => ['required', 'string', 'in:daily,weekly,monthly,quarterly,annual,ongoing'],
        ]);

        $companySettingId = CompanySetting::getInstance()->id;

        if ($this->editingId) {
            $record = LifeCommitment::findOrFail($this->editingId);
            $record->update([
                'area' => $this->area,
                'commitment' => $this->commitment,
                'action_type' => $this->action_type,
            ]);
        } else {
            $maxSort = LifeCommitment::where('company_setting_id', $companySettingId)->max('sort_order') ?? -1;

            LifeCommitment::create([
                'company_setting_id' => $companySettingId,
                'area' => $this->area,
                'commitment' => $this->commitment,
                'action_type' => $this->action_type,
                'sort_order' => $maxSort + 1,
            ]);
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->commitments);
    }

    public function delete(int $id): void
    {
        $this->authorize('business_plan.manage');

        LifeCommitment::findOrFail($id)->delete();
        unset($this->commitments);
    }

    public function moveUp(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);
        $companySettingId = CompanySetting::getInstance()->id;

        $previous = LifeCommitment::query()
            ->where('company_setting_id', $companySettingId)
            ->where('sort_order', '<', $commitment->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($previous) {
            $tempSort = $commitment->sort_order;
            $commitment->update(['sort_order' => $previous->sort_order]);
            $previous->update(['sort_order' => $tempSort]);
            unset($this->commitments);
        }
    }

    public function moveDown(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);
        $companySettingId = CompanySetting::getInstance()->id;

        $next = LifeCommitment::query()
            ->where('company_setting_id', $companySettingId)
            ->where('sort_order', '>', $commitment->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            $tempSort = $commitment->sort_order;
            $commitment->update(['sort_order' => $next->sort_order]);
            $next->update(['sort_order' => $tempSort]);
            unset($this->commitments);
        }
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->area = '';
        $this->commitment = '';
        $this->action_type = 'ongoing';
        $this->resetValidation();
    }

    protected function actionTypeLabel(string $type): string
    {
        return match ($type) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'annual' => 'Annual',
            'ongoing' => 'Ongoing',
            default => ucfirst($type),
        };
    }

    protected function actionTypeBadgeColor(string $type): string
    {
        return match ($type) {
            'daily' => 'green',
            'weekly' => 'blue',
            'monthly' => 'purple',
            'quarterly' => 'amber',
            'annual' => 'red',
            'ongoing' => 'zinc',
            default => 'zinc',
        };
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="lg">Life Commitments</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Track personal and business commitments by area of life.
            </flux:text>
        </div>

        @if ($this->canManage)
            <flux:button wire:click="openCreateModal" variant="primary" icon="plus" size="sm">
                Add Commitment
            </flux:button>
        @endif
    </div>

    {{-- Table --}}
    @if ($this->commitments->isEmpty())
        <flux:callout variant="outline" icon="heart">
            <flux:callout.heading>No commitments yet</flux:callout.heading>
            <flux:callout.text>Add your first life commitment to start tracking your personal and business goals.</flux:callout.text>
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Area</flux:table.column>
                <flux:table.column>Commitment</flux:table.column>
                <flux:table.column>Action Type</flux:table.column>
                @if ($this->canManage)
                    <flux:table.column align="end">Actions</flux:table.column>
                @endif
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->commitments as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell variant="strong">{{ $item->area }}</flux:table.cell>
                        <flux:table.cell>{{ $item->commitment }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$this->actionTypeBadgeColor($item->action_type)" inset="top bottom">
                                {{ $this->actionTypeLabel($item->action_type) }}
                            </flux:badge>
                        </flux:table.cell>
                        @if ($this->canManage)
                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button wire:click="moveUp({{ $item->id }})" variant="ghost" size="sm" icon="chevron-up" />
                                    <flux:button wire:click="moveDown({{ $item->id }})" variant="ghost" size="sm" icon="chevron-down" />
                                    <flux:button wire:click="openEditModal({{ $item->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                    <flux:button wire:click="delete({{ $item->id }})" wire:confirm="Are you sure you want to delete this commitment?" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-700" />
                                </div>
                            </flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:w-96">
        <form wire:submit="save">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'Edit Commitment' : 'Add Commitment' }}</flux:heading>
                    <flux:text class="mt-2">Define a personal or business commitment to track.</flux:text>
                </div>

                <flux:field>
                    <flux:label>Area</flux:label>
                    <flux:input wire:model="area" placeholder="e.g., Physical Health, Family, Business" />
                    <flux:error name="area" />
                </flux:field>

                <flux:field>
                    <flux:label>Commitment</flux:label>
                    <flux:textarea wire:model="commitment" rows="3" placeholder="e.g., Exercise 4x per week" />
                    <flux:error name="commitment" />
                </flux:field>

                <flux:field>
                    <flux:label>Action Type</flux:label>
                    <flux:select wire:model="action_type">
                        <flux:select.option value="daily">Daily</flux:select.option>
                        <flux:select.option value="weekly">Weekly</flux:select.option>
                        <flux:select.option value="monthly">Monthly</flux:select.option>
                        <flux:select.option value="quarterly">Quarterly</flux:select.option>
                        <flux:select.option value="annual">Annual</flux:select.option>
                        <flux:select.option value="ongoing">Ongoing</flux:select.option>
                    </flux:select>
                    <flux:error name="action_type" />
                </flux:field>

                <div class="flex justify-end gap-3">
                    <flux:button variant="ghost" wire:click="$set('showModal', false)" type="button">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? 'Update' : 'Create' }}
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
