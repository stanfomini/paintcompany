<?php

use App\Models\VendorContract;
use App\Services\VendorContractService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    public VendorContract $contract;

    public string $token = '';

    #[Validate('required|string|max:255')]
    public string $signerName = '';

    #[Validate('required|email|max:255')]
    public string $signerEmail = '';

    #[Validate('nullable|string|max:255')]
    public string $signerTitle = '';

    #[Validate('nullable|string|max:255')]
    public string $signerPhone = '';

    #[Validate('required|string')]
    public string $signatureData = '';

    #[Validate('required|in:drawn,typed')]
    public string $signatureType = 'typed';

    #[Validate('accepted')]
    public bool $acceptedTerms = false;

    public bool $isSigned = false;

    public bool $canVendorSign = false;

    public function mount(string $token): void
    {
        $contract = VendorContract::byPublicToken($token)
            ->with(['vendor', 'signers.user', 'vendorSignature'])
            ->first();

        if (! $contract) {
            abort(404, 'Contract not found.');
        }

        if (! $contract->isPublicTokenValid()) {
            abort(410, 'This contract link has expired.');
        }

        $this->contract = $contract;
        $this->token = $token;

        if ($contract->vendorSignature || $contract->isFullyExecuted()) {
            $this->isSigned = true;
        }

        // Vendor can only sign when status is pending_vendor
        // During pending_internal, this page is preview-only
        $this->canVendorSign = $contract->isPendingVendor() && ! $this->isSigned;
    }

    public function submitSignature(): void
    {
        $this->validate();

        $service = new VendorContractService;

        $service->signAsVendor(
            contract: $this->contract,
            name: $this->signerName,
            email: $this->signerEmail,
            signatureData: $this->signatureData,
            signatureType: $this->signatureType,
            request: request(),
            signerTitle: $this->signerTitle ?: null,
            signerPhone: $this->signerPhone ?: null,
        );

        $this->isSigned = true;
    }
};
?>

@section('robots', 'noindex, nofollow')

<div class="max-w-4xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    {{-- Contract Header --}}
    <div class="mb-8">
        <flux:heading size="xl">{{ $contract->title }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
            {{ __('Vendor Agreement') }} &mdash; {{ $contract->vendor->name }}
        </flux:text>
    </div>

    {{-- Contract Content --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 sm:p-8 mb-8">
        <div class="prose dark:prose-invert max-w-none">
            {!! $contract->content !!}
        </div>
    </div>

    {{-- Internal Signatures Section --}}
    @if ($contract->signers->isNotEmpty())
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 sm:p-8 mb-8">
            <flux:heading size="lg" class="mb-4">{{ __('Internal Signatures') }}</flux:heading>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($contract->signers as $signer)
                    <div wire:key="signer-{{ $signer->id }}" class="flex items-center justify-between py-3 {{ $loop->first ? 'pt-0' : '' }} {{ $loop->last ? 'pb-0' : '' }}">
                        <div class="flex items-center gap-3">
                            @if ($signer->hasSigned())
                                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30">
                                    <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            @else
                                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-zinc-100 dark:bg-zinc-700">
                                    <svg class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            @endif
                            <div>
                                <flux:text class="font-medium text-zinc-900 dark:text-white">
                                    {{ $signer->user?->name }}
                                </flux:text>
                                @if ($signer->hasSigned())
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                        {{ __('Signed') }} {{ $signer->signed_at->format('M j, Y g:i A') }}
                                    </flux:text>
                                @else
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                        {{ __('Pending') }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Vendor Signature Display (after signing) --}}
    @if ($isSigned && $contract->vendorSignature)
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 sm:p-8 mb-8">
            <flux:heading size="lg" class="mb-4">{{ __('Vendor Signature') }}</flux:heading>
            <div class="space-y-2">
                <div class="text-sm"><strong>{{ __('Name') }}:</strong> {{ $contract->vendorSignature->signer_name }}</div>
                @if ($contract->vendorSignature->signer_title)
                    <div class="text-sm"><strong>{{ __('Title') }}:</strong> {{ $contract->vendorSignature->signer_title }}</div>
                @endif
                <div class="text-sm"><strong>{{ __('Email') }}:</strong> {{ $contract->vendorSignature->signer_email }}</div>
                @if ($contract->vendorSignature->signer_phone)
                    <div class="text-sm"><strong>{{ __('Phone') }}:</strong> {{ $contract->vendorSignature->signer_phone }}</div>
                @endif
                <div class="mt-3 border-t border-zinc-200 dark:border-zinc-700 pt-3">
                    @if ($contract->vendorSignature->signature_type === 'typed')
                        <div class="text-2xl text-zinc-900 dark:text-white" style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;">
                            {{ $contract->vendorSignature->signature_data }}
                        </div>
                    @elseif ($contract->vendorSignature->signature_type === 'drawn' && $contract->vendorSignature->signature_data)
                        <img src="{{ $contract->vendorSignature->signature_data }}" alt="Signature" class="max-h-16" />
                    @endif
                    <div class="text-xs text-zinc-500 mt-1">{{ __('Signed') }} {{ $contract->vendorSignature->signed_at->format('F j, Y \a\t g:i A') }}</div>
                </div>
            </div>
        </div>
    @endif

    {{-- Signed Status Banner + PDF Download --}}
    @if ($isSigned)
        <div class="bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800 p-6 sm:p-8 text-center mb-8">
            <div class="flex items-center justify-center w-16 h-16 rounded-full bg-green-100 dark:bg-green-900/40 mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <flux:heading size="lg" class="text-green-900 dark:text-green-100">
                {{ __('Contract Fully Executed') }}
            </flux:heading>
            <flux:text class="mt-2 text-green-700 dark:text-green-300">
                {{ __('This contract has been signed by all parties. A copy has been emailed to you.') }}
            </flux:text>

            @if ($contract->isFullyExecuted() && $contract->executed_pdf_path)
                <div class="mt-6">
                    <flux:button
                        variant="primary"
                        :href="route('vendor.contract.pdf', ['token' => $token])"
                        target="_blank"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        {{ __('Download Signed Contract PDF') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @elseif ($canVendorSign)
        {{-- Signing Form --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 sm:p-8">
            <flux:heading size="lg" class="mb-6">{{ __('Sign This Contract') }}</flux:heading>

            <form wire:submit="submitSignature" class="space-y-6">
                {{-- Signer Information --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Your Name') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input
                            wire:model="signerName"
                            type="text"
                            :placeholder="__('Full name')"
                            required
                        />
                        <flux:error name="signerName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Your Email') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input
                            wire:model="signerEmail"
                            type="email"
                            placeholder="email@example.com"
                            required
                        />
                        <flux:error name="signerEmail" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Title / Role') }}</flux:label>
                        <flux:input
                            wire:model="signerTitle"
                            type="text"
                            :placeholder="__('e.g., Principal, Owner')"
                        />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Phone') }}</flux:label>
                        <flux:input
                            wire:model="signerPhone"
                            type="tel"
                            :placeholder="__('Phone number')"
                        />
                    </flux:field>
                </div>

                {{-- Signature Type Tabs --}}
                <div>
                    <flux:label class="mb-2">{{ __('Signature') }} <span class="text-red-500">*</span></flux:label>
                    <div class="flex rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
                        <button
                            type="button"
                            wire:click="$set('signatureType', 'drawn')"
                            class="flex-1 px-4 py-2.5 text-sm font-medium transition-colors {{ $signatureType === 'drawn' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}"
                        >
                            <span class="flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                                {{ __('Draw Signature') }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="$set('signatureType', 'typed')"
                            class="flex-1 px-4 py-2.5 text-sm font-medium transition-colors border-l border-zinc-200 dark:border-zinc-700 {{ $signatureType === 'typed' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}"
                        >
                            <span class="flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                {{ __('Type Signature') }}
                            </span>
                        </button>
                    </div>

                    {{-- Draw Signature Canvas --}}
                    @if ($signatureType === 'drawn')
                        <div
                            x-data="vendorSignaturePad()"
                            x-init="init()"
                            class="space-y-2"
                        >
                            <div class="relative">
                                <canvas
                                    x-ref="canvas"
                                    class="w-full h-40 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg bg-white dark:bg-zinc-800 cursor-crosshair touch-none"
                                ></canvas>
                                <div
                                    x-show="isEmpty"
                                    class="absolute inset-0 flex items-center justify-center pointer-events-none"
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
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-800">
                                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Preview:') }}</flux:text>
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

                    <flux:error name="signatureData" />
                </div>

                {{-- Terms Checkbox --}}
                <flux:field>
                    <div class="flex items-start gap-3">
                        <flux:checkbox
                            wire:model.live="acceptedTerms"
                            id="vendorAcceptedTerms"
                        />
                        <label for="vendorAcceptedTerms" class="text-sm text-zinc-600 dark:text-zinc-400 cursor-pointer">
                            {{ __('I have read and agree to the terms of this contract. I understand that by signing this document electronically, I am legally bound to the terms stated above.') }}
                            <span class="text-red-500">*</span>
                        </label>
                    </div>
                    <flux:error name="acceptedTerms" />
                </flux:field>

                {{-- Submit Button --}}
                <div class="flex justify-end pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="submitSignature"
                    >
                        <span wire:loading.remove wire:target="submitSignature">
                            {{ __('Sign Contract') }}
                        </span>
                        <span wire:loading wire:target="submitSignature" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ __('Processing...') }}
                        </span>
                    </flux:button>
                </div>
            </form>
        </div>
    @elseif ($contract->isPendingInternal())
        {{-- Preview Notice (internal signing in progress) --}}
        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 p-6 sm:p-8 text-center">
            <flux:heading size="lg" class="text-amber-900 dark:text-amber-100">
                {{ __('Contract Preview') }}
            </flux:heading>
            <flux:text class="mt-2 text-amber-700 dark:text-amber-300">
                {{ __('This contract is currently being reviewed and signed internally. The signing form will be available once all internal signatures have been collected.') }}
            </flux:text>
        </div>
    @endif
</div>

{{-- Signature Pad Alpine Component --}}
@script
<script>
    Alpine.data('vendorSignaturePad', () => ({
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
