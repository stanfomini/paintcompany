<?php

use App\Models\PtoRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('My PTO')]
class extends Component {
    public bool $showCreateForm = false;

    public string $formType = 'vacation';

    public string $formStartDate = '';

    public string $formEndDate = '';

    public string $formHours = '8.0';

    public string $formNotes = '';

    public function boot(): void
    {
        if (! auth()->user()?->employeeProfile) {
            abort(403);
        }
    }

    #[Computed]
    public function ptoRequests()
    {
        return auth()->user()->employeeProfile
            ->ptoRequests()
            ->with('approvedBy')
            ->latest()
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $yearStart = now()->startOfYear();
        $requests = auth()->user()->employeeProfile
            ->ptoRequests()
            ->where('created_at', '>=', $yearStart)
            ->get();

        return [
            'total' => $requests->count(),
            'pending' => $requests->where('status', 'pending')->count(),
            'approved' => $requests->where('status', 'approved')->count(),
            'denied' => $requests->where('status', 'denied')->count(),
        ];
    }

    public function openCreateForm(): void
    {
        $this->resetCreateForm();
        $this->showCreateForm = true;
    }

    public function submitRequest(): void
    {
        $this->validate([
            'formType' => 'required|in:vacation,sick,personal,bereavement,other',
            'formStartDate' => 'required|date',
            'formEndDate' => 'required|date|after_or_equal:formStartDate',
            'formHours' => 'required|numeric|min:0.5',
        ]);

        PtoRequest::create([
            'employee_profile_id' => auth()->user()->employeeProfile->id,
            'type' => $this->formType,
            'start_date' => $this->formStartDate,
            'end_date' => $this->formEndDate,
            'hours' => (float) $this->formHours,
            'notes' => $this->formNotes ?: null,
        ]);

        $this->showCreateForm = false;
        $this->resetCreateForm();
        unset($this->ptoRequests, $this->stats);
        session()->flash('success', __('PTO request submitted.'));
    }

    public function cancelRequest(int $id): void
    {
        $request = PtoRequest::findOrFail($id);

        if ($request->employee_profile_id !== auth()->user()->employeeProfile->id) {
            return;
        }

        if (! $request->isPending()) {
            return;
        }

        $request->delete();
        unset($this->ptoRequests, $this->stats);
        session()->flash('success', __('PTO request cancelled.'));
    }

    private function resetCreateForm(): void
    {
        $this->formType = 'vacation';
        $this->formStartDate = '';
        $this->formEndDate = '';
        $this->formHours = '8.0';
        $this->formNotes = '';
        $this->resetValidation();
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('My PTO') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="openCreateForm">
                {{ __('Request PTO') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 text-center">
                <flux:text class="text-sm text-zinc-500">{{ __('Total Requested') }}</flux:text>
                <flux:heading size="lg">{{ $this->stats['total'] }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 text-center">
                <flux:text class="text-sm text-zinc-500">{{ __('Approved') }}</flux:text>
                <flux:heading size="lg" class="text-green-600 dark:text-green-400">{{ $this->stats['approved'] }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 text-center">
                <flux:text class="text-sm text-zinc-500">{{ __('Pending') }}</flux:text>
                <flux:heading size="lg" class="text-amber-600 dark:text-amber-400">{{ $this->stats['pending'] }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 text-center">
                <flux:text class="text-sm text-zinc-500">{{ __('Denied') }}</flux:text>
                <flux:heading size="lg" class="text-red-600 dark:text-red-400">{{ $this->stats['denied'] }}</flux:heading>
            </div>
        </div>

        {{-- Requests Table --}}
        @if ($this->ptoRequests->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Type') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Start Date') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('End Date') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Hours') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Submitted') }}</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->ptoRequests as $request)
                            <tr wire:key="pto-{{ $request->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="capitalize">{{ str_replace('_', ' ', $request->type) }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $request->start_date->format('M j, Y') }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $request->end_date->format('M j, Y') }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $request->hours }}h</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($request->status === 'pending')
                                        <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                                    @elseif ($request->status === 'approved')
                                        <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                                    @elseif ($request->status === 'denied')
                                        <flux:badge size="sm" color="red">{{ __('Denied') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="text-sm text-zinc-500">{{ $request->created_at->format('M j, Y') }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    @if ($request->isPending())
                                        <flux:button size="sm" variant="danger" wire:click="cancelRequest({{ $request->id }})"
                                            wire:confirm="{{ __('Are you sure you want to cancel this request?') }}">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                    @elseif ($request->status === 'denied' && $request->denial_reason)
                                        <flux:tooltip content="{{ $request->denial_reason }}">
                                            <flux:text class="text-sm text-zinc-400 cursor-help">{{ __('Reason') }}</flux:text>
                                        </flux:tooltip>
                                    @else
                                        <flux:text class="text-sm text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="calendar-days" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No PTO requests') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">{{ __('You have not submitted any PTO requests yet.') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Create PTO Modal --}}
    <flux:modal wire:model="showCreateForm" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Request PTO') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Type') }}</flux:label>
                <flux:select wire:model="formType">
                    <flux:select.option value="vacation">{{ __('Vacation') }}</flux:select.option>
                    <flux:select.option value="sick">{{ __('Sick') }}</flux:select.option>
                    <flux:select.option value="personal">{{ __('Personal') }}</flux:select.option>
                    <flux:select.option value="bereavement">{{ __('Bereavement') }}</flux:select.option>
                    <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
                </flux:select>
                @error('formType') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Start Date') }}</flux:label>
                    <flux:input type="date" wire:model="formStartDate" />
                    @error('formStartDate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('End Date') }}</flux:label>
                    <flux:input type="date" wire:model="formEndDate" />
                    @error('formEndDate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Total Hours') }}</flux:label>
                <flux:input type="number" wire:model="formHours" step="0.5" min="0.5" />
                @error('formHours') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes (optional)') }}</flux:label>
                <flux:textarea wire:model="formNotes" rows="2" />
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showCreateForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="submitRequest">{{ __('Submit Request') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
