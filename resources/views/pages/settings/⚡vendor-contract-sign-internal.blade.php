<?php

use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Services\VendorContractService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public VendorContract $vendorContract;

    public ?int $vendorId = null;

    public string $signatureData = '';

    public string $signatureType = 'typed';

    public bool $showSignatureModal = false;

    public function mount(int $vendor, int $contract): void
    {
        $this->vendorContract = VendorContract::with(['vendor', 'signers.user', 'vendorSignature'])
            ->findOrFail($contract);

        if ($this->vendorContract->vendor_id !== $vendor) {
            abort(404);
        }

        $this->vendorId = $vendor;
    }

    #[Computed]
    public function currentUserSigner(): ?VendorContractSigner
    {
        return $this->vendorContract->signers
            ->where('user_id', auth()->id())
            ->first();
    }

    #[Computed]
    public function canSign(): bool
    {
        $signer = $this->currentUserSigner;

        return $signer
            && ! $signer->hasSigned()
            && $this->vendorContract->isPendingInternal();
    }

    public function sign(): void
    {
        $this->validate([
            'signatureData' => ['required', 'string', 'min:1'],
            'signatureType' => ['required', 'in:typed,drawn'],
        ]);

        app(VendorContractService::class)->signAsInternal(
            $this->vendorContract,
            auth()->user(),
            $this->signatureData,
            $this->signatureType,
        );

        $this->vendorContract->refresh();
        $this->vendorContract->load('signers.user');
        $this->signatureData = '';
        $this->signatureType = 'typed';
        $this->showSignatureModal = false;
    }

    public function sendToVendor(): void
    {
        app(VendorContractService::class)->sendToVendor($this->vendorContract);

        $this->vendorContract->refresh();
    }

    public function recallToDraft(): void
    {
        app(VendorContractService::class)->recallToDraft($this->vendorContract);

        $this->vendorContract->refresh();

        $this->redirect(route('vendor-contracts.edit', [
            'vendor' => $this->vendorId,
            'contract' => $this->vendorContract->id,
        ]));
    }

    public function openSignatureModal(): void
    {
        $this->showSignatureModal = true;
    }

    public function closeSignatureModal(): void
    {
        $this->showSignatureModal = false;
        $this->signatureData = '';
        $this->signatureType = 'typed';
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="$vendorContract->title" :subheading="__('Review and sign contract')" :fullWidth="true">
        <div class="my-6 w-full space-y-6">

            {{-- Breadcrumb --}}
            <nav class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                <a href="{{ route('vendors.edit') }}" class="hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Vendors') }}</a>
                <flux:icon name="chevron-right" variant="mini" class="h-4 w-4" />
                <span>{{ $vendorContract->vendor->name }}</span>
                <flux:icon name="chevron-right" variant="mini" class="h-4 w-4" />
                <span class="text-zinc-900 dark:text-white">{{ __('Sign Contract') }}</span>
            </nav>

            {{-- Header with Status Badge --}}
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="lg">{{ $vendorContract->title }}</flux:heading>
                @php
                    $badgeVariant = match($vendorContract->status) {
                        'draft' => 'warning',
                        'pending_internal' => 'warning',
                        'pending_vendor' => 'primary',
                        'fully_executed' => 'success',
                        'expired' => 'danger',
                        'invalidated' => 'danger',
                        default => 'default',
                    };
                @endphp
                <flux:badge :variant="$badgeVariant" size="sm">
                    {{ $vendorContract->getStatusLabel() }}
                </flux:badge>
            </div>

            {{-- Contract Content --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-4">{{ __('Contract Content') }}</flux:heading>
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    {!! $vendorContract->content !!}
                </div>
            </div>

            {{-- Signing Progress --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-4">{{ __('Signing Progress') }}</flux:heading>
                <div class="space-y-3">
                    @foreach ($vendorContract->signers as $signer)
                        <div wire:key="signer-{{ $signer->id }}" class="flex items-center justify-between rounded-lg border border-zinc-100 px-4 py-3 dark:border-zinc-700">
                            <div class="flex items-center gap-3">
                                @if ($signer->hasSigned())
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                                        <flux:icon name="check" variant="mini" class="h-4 w-4 text-green-600 dark:text-green-400" />
                                    </div>
                                @else
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
                                        <flux:icon name="clock" variant="mini" class="h-4 w-4 text-zinc-400 dark:text-zinc-500" />
                                    </div>
                                @endif
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $signer->user?->name ?? __('Unknown User') }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Signer #:order', ['order' => $signer->order + 1]) }}</p>
                                </div>
                            </div>
                            <div>
                                @if ($signer->hasSigned())
                                    <span class="text-sm font-medium text-green-600 dark:text-green-400">
                                        {{ __('Signed') }} {{ $signer->signed_at->format('M j, Y g:ia') }}
                                    </span>
                                @else
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Pending') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Signature Section (only for assigned unsigned signers on pending_internal contracts) --}}
            @if ($this->canSign)
                <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-4">{{ __('Your Signature') }}</flux:heading>
                    <flux:text class="mb-4 text-zinc-500 dark:text-zinc-400">
                        {{ __('Please review the contract above and provide your signature below.') }}
                    </flux:text>
                    <flux:button variant="primary" wire:click="openSignatureModal">
                        {{ __('Sign Contract') }}
                    </flux:button>
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex flex-wrap items-center gap-3">
                @if ($vendorContract->isPendingVendor())
                    <flux:button variant="primary" wire:click="sendToVendor" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="sendToVendor">{{ __('Send to Vendor') }}</span>
                        <span wire:loading wire:target="sendToVendor">{{ __('Sending...') }}</span>
                    </flux:button>
                @endif

                @if ($vendorContract->isPendingInternal())
                    <flux:button variant="danger" wire:click="recallToDraft" wire:confirm="{{ __('Recall this contract to draft? All signatures will be cleared.') }}">
                        {{ __('Recall to Draft') }}
                    </flux:button>
                @endif

                <a href="{{ route('vendors.edit') }}" class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300">
                    <flux:icon name="arrow-left" variant="mini" class="h-4 w-4" />
                    {{ __('Back to Vendors') }}
                </a>
            </div>
        </div>
    </x-pages::settings.layout>

    {{-- Signature Modal --}}
    <flux:modal wire:model="showSignatureModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Sign Contract') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                    {{ __('Provide your signature to sign this contract.') }}
                </flux:text>
            </div>

            <form wire:submit="sign" class="space-y-5">
                {{-- Signature Type Tabs --}}
                <div>
                    <flux:label class="mb-2">{{ __('Signature') }} <span class="text-red-500">*</span></flux:label>
                    <div class="mb-4 flex overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <button
                            type="button"
                            wire:click="$set('signatureType', 'typed')"
                            class="flex-1 px-4 py-2.5 text-sm font-medium transition-colors {{ $signatureType === 'typed' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}"
                        >
                            <span class="flex items-center justify-center gap-2">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                {{ __('Type Signature') }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="$set('signatureType', 'drawn')"
                            class="flex-1 border-l border-zinc-200 px-4 py-2.5 text-sm font-medium transition-colors dark:border-zinc-700 {{ $signatureType === 'drawn' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}"
                        >
                            <span class="flex items-center justify-center gap-2">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                                {{ __('Draw Signature') }}
                            </span>
                        </button>
                    </div>

                    {{-- Type Signature Input --}}
                    @if ($signatureType === 'typed')
                        <div class="space-y-3">
                            <flux:input
                                wire:model.live="signatureData"
                                type="text"
                                :placeholder="__('Type your full name')"
                                class="text-lg"
                            />

                            @if (strlen($signatureData) > 0)
                                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                    <flux:text size="xs" class="mb-2 text-zinc-500 dark:text-zinc-400">{{ __('Preview:') }}</flux:text>
                                    <div
                                        class="text-3xl text-zinc-900 dark:text-white"
                                        style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;"
                                    >
                                        {{ $signatureData }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Draw Signature Canvas --}}
                    @if ($signatureType === 'drawn')
                        <div
                            x-data="signaturePad()"
                            x-init="init()"
                            class="space-y-2"
                        >
                            <div class="relative">
                                <canvas
                                    x-ref="canvas"
                                    class="h-40 w-full cursor-crosshair touch-none rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800"
                                ></canvas>
                                <div
                                    x-show="isEmpty"
                                    class="pointer-events-none absolute inset-0 flex items-center justify-center"
                                >
                                    <flux:text class="text-zinc-400 dark:text-zinc-500">
                                        {{ __('Sign here') }}
                                    </flux:text>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    x-on:click="clear()"
                                >
                                    {{ __('Clear') }}
                                </flux:button>
                            </div>
                            <input type="hidden" wire:model="signatureData" x-ref="signatureInput" />
                        </div>
                    @endif

                    <flux:error name="signatureData" />
                </div>

                {{-- Action Buttons --}}
                <div class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-4 sm:flex-row sm:justify-end dark:border-zinc-700">
                    <flux:button
                        type="button"
                        variant="ghost"
                        wire:click="closeSignatureModal"
                    >
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="sign"
                    >
                        <span wire:loading.remove wire:target="sign">{{ __('Sign Contract') }}</span>
                        <span wire:loading wire:target="sign" class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ __('Processing...') }}
                        </span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</section>

{{-- Signature Pad Alpine Component --}}
@script
<script>
    Alpine.data('signaturePad', () => ({
        signaturePad: null,
        isEmpty: true,

        init() {
            this.$nextTick(() => {
                this.initCanvas();
                window.addEventListener('resize', () => this.resizeCanvas());
            });
        },

        initCanvas() {
            const canvas = this.$refs.canvas;
            if (!canvas || !window.SignaturePad) {
                setTimeout(() => this.initCanvas(), 100);
                return;
            }

            this.signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: document.documentElement.classList.contains('dark') ? '#ffffff' : '#000000',
                minWidth: 1,
                maxWidth: 2.5,
            });

            this.resizeCanvas();

            this.signaturePad.addEventListener('beginStroke', () => {
                this.isEmpty = false;
            });

            this.signaturePad.addEventListener('endStroke', () => {
                this.updateSignatureData();
            });
        },

        resizeCanvas() {
            const canvas = this.$refs.canvas;
            if (!canvas) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const rect = canvas.getBoundingClientRect();

            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            if (this.signaturePad) {
                this.signaturePad.clear();
                this.isEmpty = true;
                this.$refs.signatureInput.value = '';
                this.$wire.set('signatureData', '');
            }
        },

        clear() {
            if (this.signaturePad) {
                this.signaturePad.clear();
                this.isEmpty = true;
                this.$refs.signatureInput.value = '';
                this.$wire.set('signatureData', '');
            }
        },

        updateSignatureData() {
            if (this.signaturePad && !this.signaturePad.isEmpty()) {
                const data = this.signaturePad.toDataURL('image/png');
                this.$refs.signatureInput.value = data;
                this.$wire.set('signatureData', data);
            }
        }
    }));
</script>
@endscript
