<?php

use App\Models\User;
use App\Models\VendorContract;
use App\Services\VendorContractService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app')]
class extends Component
{
    public ?int $vendorId = null;

    public ?int $contractId = null;

    public VendorContract $vendorContract;

    public string $title = '';

    public string $content = '';

    public ?string $expiresAt = null;

    public ?string $notes = null;

    /** @var array<int> */
    public array $selectedSignerIds = [];

    public function boot(): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }
    }

    public function mount(int $vendor, int $contract): void
    {
        $this->vendorContract = VendorContract::with(['vendor', 'signers'])->findOrFail($contract);

        if ($this->vendorContract->vendor_id !== $vendor) {
            abort(404);
        }

        $this->vendorId = $vendor;
        $this->contractId = $contract;
        $this->title = $this->vendorContract->title ?? '';
        $this->content = $this->vendorContract->content ?? '';
        $this->expiresAt = $this->vendorContract->expires_at?->format('Y-m-d');
        $this->notes = $this->vendorContract->notes;
        $this->selectedSignerIds = $this->vendorContract->signers->pluck('user_id')->all();
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('type', 'internal')->orderBy('name')->get();
    }

    public function saveContent(): void
    {
        try {
            app(VendorContractService::class)->updateContent($this->vendorContract, $this->content);
            $this->dispatch('notify', type: 'success', message: __('Contract content saved.'));
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function saveDetails(): void
    {
        if (! $this->vendorContract->isDraft()) {
            $this->dispatch('notify', type: 'error', message: __('Details can only be updated while the contract is in draft status.'));

            return;
        }

        $this->vendorContract->update([
            'title' => $this->title,
            'expires_at' => $this->expiresAt ?: null,
            'notes' => $this->notes ?: null,
        ]);

        $this->dispatch('notify', type: 'success', message: __('Contract details saved.'));
    }

    public function updateSigners(): void
    {
        try {
            app(VendorContractService::class)->updateSigners($this->vendorContract, $this->selectedSignerIds);
            $this->vendorContract->load('signers');
            $this->dispatch('notify', type: 'success', message: __('Signers updated.'));
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function sendForSigning(): void
    {
        try {
            app(VendorContractService::class)->sendForInternalSigning($this->vendorContract);

            $this->redirectRoute('vendor-contracts.sign-internal', [
                'vendor' => $this->vendorId,
                'contract' => $this->contractId,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }
}; ?>

<div>
    <div class="mx-auto max-w-7xl">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('vendors.edit')" icon="arrow-left" wire:navigate>
                    {{ __('Vendors') }}
                </flux:button>
                <div>
                    <flux:heading size="xl">{{ __('Edit Contract') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $vendorContract->vendor->name ?? '' }} &middot; {{ $vendorContract->getStatusLabel() }}
                    </flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if ($vendorContract->isDraft())
                    <flux:button wire:click="saveContent" variant="ghost" icon="check">
                        {{ __('Save Draft') }}
                    </flux:button>
                    <flux:button wire:click="sendForSigning" variant="primary" icon="paper-airplane">
                        {{ __('Send for Internal Signing') }}
                    </flux:button>
                @else
                    <flux:badge size="sm" :color="match($vendorContract->status) {
                        'pending_internal' => 'amber',
                        'pending_vendor' => 'blue',
                        'fully_executed' => 'green',
                        'expired' => 'red',
                        'invalidated' => 'zinc',
                        default => 'zinc',
                    }">{{ $vendorContract->getStatusLabel() }}</flux:badge>
                @endif
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Main Content Area --}}
            <div class="lg:col-span-2">
                @if ($vendorContract->isDraft())
                    {{-- Title --}}
                    <div class="mb-4">
                        <flux:field>
                            <flux:label>{{ __('Title') }}</flux:label>
                            <flux:input wire:model="title" />
                        </flux:field>
                    </div>

                    {{-- Trix Editor --}}
                    <link rel="stylesheet" href="https://unpkg.com/trix@2.1.12/dist/trix.css">
                    <script src="https://unpkg.com/trix@2.1.12/dist/trix.umd.min.js"></script>
                    <style>
                        trix-toolbar { background: transparent; border-bottom: 1px solid rgb(228 228 231); border-radius: 0.5rem 0.5rem 0 0; padding: 0.75rem; }
                        trix-toolbar .trix-button-group { margin-bottom: 0; }
                        trix-toolbar .trix-button { padding: 0.375rem 0.5rem; }
                        .dark trix-toolbar { border-color: rgb(63 63 70); }
                        .dark trix-toolbar .trix-button { color: rgb(161 161 170); }
                        .dark trix-toolbar .trix-button.trix-active { background: rgb(39 39 42); }
                        .dark trix-toolbar .trix-button-group { border-color: rgb(63 63 70); }
                        trix-editor:empty:not(:focus)::before { color: rgb(161 161 170); }
                    </style>
                    <script>
                        document.addEventListener('trix-file-accept', function(e) { e.preventDefault(); });
                    </script>

                    <div class="overflow-hidden rounded-lg border border-zinc-200 shadow-sm dark:border-zinc-700">
                        <input id="contract-content" type="hidden" value="{{ $content }}" />
                        <trix-editor
                            input="contract-content"
                            class="trix-content prose prose-lg max-w-none dark:prose-invert min-h-[500px] bg-white dark:bg-zinc-900 px-8 py-6"
                            x-on:trix-change="$wire.set('content', $event.target.value)"
                            placeholder="{{ __('Enter your contract content here...') }}"
                        ></trix-editor>
                    </div>
                    <div class="mt-2">
                        <flux:error name="content" />
                    </div>
                @else
                    {{-- Read-only content --}}
                    <div class="mb-4">
                        <flux:heading size="lg">{{ $vendorContract->title }}</flux:heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="trix-content prose prose-lg max-w-none dark:prose-invert">
                            {!! $vendorContract->content !!}
                        </div>
                    </div>
                @endif
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">
                {{-- Details Section --}}
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('Details') }}</flux:heading>

                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>{{ __('Expiration Date') }}</flux:label>
                            <flux:input type="date" wire:model="expiresAt" :disabled="!$vendorContract->isDraft()" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Notes') }}</flux:label>
                            <flux:textarea wire:model="notes" rows="3" :disabled="!$vendorContract->isDraft()" placeholder="{{ __('Internal notes...') }}" />
                        </flux:field>

                        @if ($vendorContract->isDraft())
                            <flux:button wire:click="saveDetails" size="sm" variant="ghost" class="w-full">
                                {{ __('Save Details') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                {{-- Signers Section --}}
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('Internal Signers') }}</flux:heading>

                    <div class="space-y-2">
                        @foreach ($this->internalUsers as $internalUser)
                            <label wire:key="signer-{{ $internalUser->id }}" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <input
                                    type="checkbox"
                                    value="{{ $internalUser->id }}"
                                    wire:model="selectedSignerIds"
                                    class="rounded border-zinc-300 dark:border-zinc-600"
                                    @disabled(!$vendorContract->isDraft())
                                />
                                <span class="text-sm">{{ $internalUser->name }}</span>
                            </label>
                        @endforeach
                    </div>

                    @if ($vendorContract->isDraft())
                        <flux:button wire:click="updateSigners" size="sm" variant="ghost" class="mt-3 w-full">
                            {{ __('Update Signers') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
