{{-- E-Signature Modal for Estimate Proposal --}}
<flux:modal wire:model="showSignatureModal" class="w-full max-w-lg">
    <div class="space-y-6">
        {{-- Modal Header --}}
        <div>
            <flux:heading size="lg">Sign to Accept Estimate</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Please provide your signature to accept this estimate.
            </flux:text>
        </div>

        <form wire:submit="submitSignature" class="space-y-5">
            {{-- Signer Information --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Your Name <span class="text-red-500">*</span></flux:label>
                    <flux:input
                        wire:model="signerName"
                        type="text"
                        placeholder="Full name"
                        required
                    />
                    <flux:error name="signerName" />
                </flux:field>

                <flux:field>
                    <flux:label>Your Email <span class="text-red-500">*</span></flux:label>
                    <flux:input
                        wire:model="signerEmail"
                        type="email"
                        placeholder="email@example.com"
                        required
                    />
                    <flux:error name="signerEmail" />
                </flux:field>
            </div>

            {{-- Signature Type Tabs --}}
            <div>
                <flux:label class="mb-2">Signature <span class="text-red-500">*</span></flux:label>
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
                            Draw Signature
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
                            Type Signature
                        </span>
                    </button>
                </div>

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
                                class="w-full h-40 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg bg-white dark:bg-zinc-800 cursor-crosshair touch-none"
                            ></canvas>
                            <div
                                x-show="isEmpty"
                                class="absolute inset-0 flex items-center justify-center pointer-events-none"
                            >
                                <flux:text class="text-zinc-400 dark:text-zinc-500">
                                    Sign here
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
                                Clear
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
                            placeholder="Type your full name"
                            class="text-lg"
                        />

                        {{-- Signature Preview --}}
                        @if (strlen($signatureData) > 0)
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-800">
                                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400 mb-2">Preview:</flux:text>
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

            {{-- Terms Preview --}}
            @if ($estimate->terms)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-zinc-50 dark:bg-zinc-800/50 max-h-32 overflow-y-auto">
                    <flux:text size="xs" class="font-medium text-zinc-600 dark:text-zinc-300 mb-2">Terms & Conditions:</flux:text>
                    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 whitespace-pre-wrap">{{ Str::limit($estimate->terms, 200) }}</flux:text>
                </div>
                @if (Str::length($estimate->terms) > 200)
                    <button type="button" wire:click="openTermsModal" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                        View Full Terms
                    </button>
                @endif
            @endif

            {{-- Terms Checkbox --}}
            <flux:field>
                <div class="flex items-start gap-3">
                    <flux:checkbox
                        wire:model.live="acceptedTerms"
                        id="acceptedTerms"
                    />
                    <label for="acceptedTerms" class="text-sm text-zinc-600 dark:text-zinc-400 cursor-pointer">
                        I have read and agree to the terms and conditions. I understand that by signing this document electronically, I am legally bound to the terms of this estimate.
                        <span class="text-red-500">*</span>
                    </label>
                </div>
                <flux:error name="acceptedTerms" />
            </flux:field>

            {{-- Action Buttons --}}
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="closeSignatureModal"
                >
                    Cancel
                </flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="submitSignature"
                    :disabled="!$this->canSubmitSignature"
                >
                    <span wire:loading.remove wire:target="submitSignature">
                        Sign & Accept Estimate
                    </span>
                    <span wire:loading wire:target="submitSignature" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Processing...
                    </span>
                </flux:button>
            </div>
        </form>
    </div>
</flux:modal>

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
                // Try again in a moment if SignaturePad isn't loaded yet
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

            // Restore drawing after resize
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
