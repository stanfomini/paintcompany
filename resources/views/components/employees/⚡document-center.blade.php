<?php

use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\ContractSentForSigning;
use App\Notifications\DocumentRejected;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Document Center')]
class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('employees.view')) {
            abort(403);
        }
    }

    #[Url(as: 'tab')]
    public string $activeTab = 'pending-reviews';

    // Reject modal
    public bool $showRejectModal = false;
    public ?int $rejectDocumentId = null;
    public string $rejectNotes = '';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function approveDocument(int $id): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $document = EmployeeDocument::findOrFail($id);
        $document->approve(auth()->user());

        unset($this->pendingReviews, $this->tabCounts);
        session()->flash('success', __('Document approved.'));
    }

    public function openRejectModal(int $id): void
    {
        $this->rejectDocumentId = $id;
        $this->rejectNotes = '';
        $this->showRejectModal = true;
    }

    public function rejectDocument(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $this->validate([
            'rejectNotes' => 'required|string|max:1000',
        ]);

        $document = EmployeeDocument::findOrFail($this->rejectDocumentId);
        $document->reject(auth()->user(), $this->rejectNotes);

        $employee = $document->employeeProfile->user;
        if ($employee) {
            $employee->notify(new DocumentRejected($document));
        }

        $this->showRejectModal = false;
        $this->rejectDocumentId = null;
        $this->rejectNotes = '';
        unset($this->pendingReviews, $this->tabCounts);
        session()->flash('success', __('Document rejected. The employee has been notified.'));
    }

    public function resendContractNotification(int $id): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $contract = EmployeeContract::with('employeeProfile.user')->findOrFail($id);
        $employee = $contract->employeeProfile->user;

        if ($employee) {
            $employee->notify(new ContractSentForSigning($contract));
        }

        session()->flash('success', __('Signature reminder sent to :name.', ['name' => $employee?->name ?? 'employee']));
    }

    #[Computed]
    public function pendingReviews()
    {
        return EmployeeDocument::query()
            ->pending()
            ->with(['employeeProfile.user', 'uploader'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function pendingSignatures()
    {
        return EmployeeContract::query()
            ->pending()
            ->with(['employeeProfile.user', 'template'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function recentlySigned()
    {
        return EmployeeContract::query()
            ->where('status', 'signed')
            ->with(['employeeProfile.user', 'template'])
            ->latest('signed_at')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function expiringDocuments()
    {
        return EmployeeDocument::query()
            ->expiringSoon()
            ->where('status', 'approved')
            ->with(['employeeProfile.user'])
            ->orderBy('expires_at')
            ->get();
    }

    #[Computed]
    public function tabCounts(): array
    {
        return [
            'pending-reviews' => EmployeeDocument::pending()->count(),
            'pending-signatures' => EmployeeContract::pending()->count(),
            'recently-signed' => EmployeeContract::where('status', 'signed')->count(),
            'expiring' => EmployeeDocument::expiringSoon()->where('status', 'approved')->count(),
        ];
    }
};
?>

<div>
    <div class="mx-auto max-w-6xl">
        {{-- Header --}}
        <div class="mb-6">
            <flux:heading size="xl">{{ __('Document Center') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Review employee documents, track contract signatures, and manage expiring documents.') }}
            </flux:text>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Tab Navigation --}}
        <div class="mb-6 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex space-x-6">
                @foreach ([
                    'pending-reviews' => __('Pending Reviews'),
                    'pending-signatures' => __('Pending Signatures'),
                    'recently-signed' => __('Recently Signed'),
                    'expiring' => __('Expiring Soon'),
                ] as $tab => $label)
                    <button
                        wire:click="setTab('{{ $tab }}')"
                        class="border-b-2 px-1 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-zinc-800 text-zinc-900 dark:border-zinc-200 dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                    >
                        {{ $label }}
                        @if (($this->tabCounts[$tab] ?? 0) > 0)
                            <span class="ml-1.5 rounded-full {{ $activeTab === $tab ? 'bg-zinc-200 dark:bg-zinc-600' : 'bg-zinc-100 dark:bg-zinc-700' }} px-2 py-0.5 text-xs">
                                {{ $this->tabCounts[$tab] }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Pending Reviews Tab --}}
        @if ($activeTab === 'pending-reviews')
            @if ($this->pendingReviews->isEmpty())
                <div class="rounded-lg border border-zinc-200 p-12 text-center dark:border-zinc-700">
                    <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-green-500" />
                    <flux:heading size="sm" class="mt-4">{{ __('No Pending Reviews') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('All employee documents have been reviewed.') }}</flux:text>
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Employee') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Document') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Uploaded') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->pendingReviews as $document)
                                <tr wire:key="review-{{ $document->id }}">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <flux:avatar name="{{ $document->employeeProfile->user->name ?? '' }}" size="sm" />
                                            <div>
                                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $document->employeeProfile->user->name ?? '-' }}</flux:text>
                                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $document->employeeProfile->employee_number }}</flux:text>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $document->name }}</flux:text>
                                        @if ($document->notes)
                                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ Str::limit($document->notes, 40) }}</flux:text>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <flux:badge size="sm" color="{{ match($document->type) { 'w9' => 'blue', 'i9' => 'blue', 'direct_deposit' => 'green', 'insurance' => 'amber', 'license' => 'purple', 'certification' => 'cyan', default => 'zinc' } }}">
                                            {{ $document->getTypeLabel() }}
                                        </flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $document->created_at->format('M j, Y') }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <flux:button :href="route('employees.show', $document->employee_profile_id)" size="xs" variant="ghost" icon="eye" wire:navigate>
                                                {{ __('View') }}
                                            </flux:button>
                                            @can('employees.edit')
                                                <flux:button wire:click="approveDocument({{ $document->id }})" size="xs" variant="primary" icon="check">
                                                    {{ __('Approve') }}
                                                </flux:button>
                                                <flux:button wire:click="openRejectModal({{ $document->id }})" size="xs" variant="ghost" icon="x-mark" class="text-red-600 dark:text-red-400">
                                                    {{ __('Reject') }}
                                                </flux:button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        {{-- Pending Signatures Tab --}}
        @if ($activeTab === 'pending-signatures')
            @if ($this->pendingSignatures->isEmpty())
                <div class="rounded-lg border border-zinc-200 p-12 text-center dark:border-zinc-700">
                    <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-green-500" />
                    <flux:heading size="sm" class="mt-4">{{ __('No Pending Signatures') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('All contracts have been signed.') }}</flux:text>
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Employee') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Contract') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Sent') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->pendingSignatures as $contract)
                                <tr wire:key="sig-{{ $contract->id }}">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <flux:avatar name="{{ $contract->employeeProfile->user->name ?? '' }}" size="sm" />
                                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $contract->employeeProfile->user->name ?? '-' }}</flux:text>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $contract->title }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <flux:badge size="sm" color="{{ match($contract->status) { 'sent' => 'blue', 'viewed' => 'amber', default => 'zinc' } }}">
                                            {{ ucfirst($contract->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $contract->sent_at?->format('M j, Y') ?? '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <flux:button :href="route('contracts.show', $contract)" size="xs" variant="ghost" icon="eye" wire:navigate>
                                                {{ __('View') }}
                                            </flux:button>
                                            @can('employees.edit')
                                                <flux:button wire:click="resendContractNotification({{ $contract->id }})" size="xs" variant="ghost" icon="paper-airplane">
                                                    {{ __('Remind') }}
                                                </flux:button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        {{-- Recently Signed Tab --}}
        @if ($activeTab === 'recently-signed')
            @if ($this->recentlySigned->isEmpty())
                <div class="rounded-lg border border-zinc-200 p-12 text-center dark:border-zinc-700">
                    <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">{{ __('No Signed Contracts') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('No contracts have been signed yet.') }}</flux:text>
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Employee') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Contract') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Signed') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->recentlySigned as $contract)
                                <tr wire:key="signed-{{ $contract->id }}">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <flux:avatar name="{{ $contract->employeeProfile->user->name ?? '' }}" size="sm" />
                                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $contract->employeeProfile->user->name ?? '-' }}</flux:text>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $contract->title }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $contract->signed_at?->format('M j, Y g:ia') ?? '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <flux:button :href="route('contracts.show', $contract)" size="xs" variant="ghost" icon="eye" wire:navigate>
                                            {{ __('View') }}
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        {{-- Expiring Soon Tab --}}
        @if ($activeTab === 'expiring')
            @if ($this->expiringDocuments->isEmpty())
                <div class="rounded-lg border border-zinc-200 p-12 text-center dark:border-zinc-700">
                    <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-green-500" />
                    <flux:heading size="sm" class="mt-4">{{ __('No Expiring Documents') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('No approved documents are expiring within the next 30 days.') }}</flux:text>
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Employee') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Document') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Expires') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->expiringDocuments as $document)
                                <tr wire:key="expiring-{{ $document->id }}">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <flux:avatar name="{{ $document->employeeProfile->user->name ?? '' }}" size="sm" />
                                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $document->employeeProfile->user->name ?? '-' }}</flux:text>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $document->name }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <flux:badge size="sm" color="{{ match($document->type) { 'w9' => 'blue', 'i9' => 'blue', 'direct_deposit' => 'green', 'insurance' => 'amber', 'license' => 'purple', 'certification' => 'cyan', default => 'zinc' } }}">
                                            {{ $document->getTypeLabel() }}
                                        </flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm">
                                        <span class="font-medium text-amber-600 dark:text-amber-400">
                                            {{ $document->expires_at->format('M j, Y') }}
                                        </span>
                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $document->expires_at->diffForHumans() }}
                                        </flux:text>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <flux:button :href="route('employees.show', $document->employee_profile_id)" size="xs" variant="ghost" icon="eye" wire:navigate>
                                            {{ __('View Employee') }}
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>

    {{-- Reject Document Modal --}}
    <flux:modal wire:model="showRejectModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Reject Document') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __('Please provide a reason for rejecting this document. The employee will be notified.') }}
            </flux:text>

            <flux:field>
                <flux:label>{{ __('Reason for Rejection') }}</flux:label>
                <flux:textarea wire:model="rejectNotes" rows="3" placeholder="{{ __('e.g. Document is illegible, wrong form version, missing signature...') }}" />
                <flux:error name="rejectNotes" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showRejectModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="rejectDocument" variant="danger">{{ __('Reject Document') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
