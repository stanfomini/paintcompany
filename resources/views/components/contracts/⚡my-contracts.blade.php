<?php

use App\Models\EmployeeContract;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('My Contracts')]
class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->employeeProfile) {
            abort(403);
        }
    }

    #[Computed]
    public function contracts()
    {
        return EmployeeContract::query()
            ->where('employee_profile_id', auth()->user()->employeeProfile->id)
            ->whereNot('status', 'draft')
            ->with('template')
            ->latest()
            ->get();
    }

    #[Computed]
    public function pendingContracts()
    {
        return $this->contracts->whereIn('status', ['sent', 'viewed']);
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'zinc',
            'sent' => 'blue',
            'viewed' => 'amber',
            'signed' => 'green',
            'voided' => 'red',
            default => 'zinc',
        };
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl">{{ __('My Contracts') }}</flux:heading>
        <flux:text class="mt-1">{{ __('View and sign your employment contracts.') }}</flux:text>
    </div>

    {{-- Pending Contracts Alert --}}
    @if($this->pendingContracts->isNotEmpty())
        <div class="mb-6">
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Action Required') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ trans_choice('You have :count contract awaiting your signature.|You have :count contracts awaiting your signature.', $this->pendingContracts->count()) }}
                </flux:callout.text>
            </flux:callout>
        </div>
    @endif

    {{-- Contracts Table --}}
    @if($this->contracts->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="document-text" class="mx-auto mb-3 size-10 text-zinc-400 dark:text-zinc-500" />
            <flux:heading size="lg">{{ __('No Contracts Yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your contracts will appear here once they are created.') }}</flux:text>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Title') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Sent') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Signed') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->contracts as $contract)
                        <tr wire:key="contract-{{ $contract->id }}">
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $contract->title }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <flux:badge :color="$this->statusColor($contract->status)" size="sm">
                                    {{ ucfirst($contract->status) }}
                                </flux:badge>
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $contract->sent_at?->format('M j, Y') ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $contract->signed_at?->format('M j, Y') ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if(in_array($contract->status, ['sent', 'viewed']))
                                        <flux:button variant="primary" size="sm" icon="pencil-square" :href="route('contracts.sign', $contract->token)">
                                            {{ __('Review & Sign') }}
                                        </flux:button>
                                    @else
                                        <flux:button variant="subtle" size="sm" icon="eye" :href="route('contracts.sign', $contract->token)">
                                            {{ __('View') }}
                                        </flux:button>
                                    @endif
                                    @if($contract->isSigned())
                                        <flux:button variant="ghost" size="sm" icon="arrow-down-tray" :href="route('contracts.sign.pdf', $contract->token)">
                                            {{ __('Download') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
