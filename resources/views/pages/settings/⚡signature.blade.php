<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $signatureType = 'typed';
    public string $signatureData = '';
    public bool $hasExistingSignature = false;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->type !== 'internal') {
            abort(403);
        }

        $internalUser = $user->internalUser;
        if ($internalUser && $internalUser->hasSignature()) {
            $this->signatureType = $internalUser->signature_type;
            $this->signatureData = $internalUser->signature_data ?? '';
            $this->hasExistingSignature = true;
        }
    }

    public function saveSignature(): void
    {
        $user = Auth::user();

        if ($user->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'signatureType' => 'required|in:drawn,typed',
            'signatureData' => 'required|string',
        ], [
            'signatureData.required' => $this->signatureType === 'drawn'
                ? 'Please draw your signature.'
                : 'Please type your signature.',
        ]);

        $internalUser = $user->internalUser;
        $internalUser->update([
            'signature_data' => $this->signatureData,
            'signature_type' => $this->signatureType,
            'signature_updated_at' => now(),
        ]);

        $this->hasExistingSignature = true;
        $this->dispatch('signature-saved');
    }

    public function clearSignature(): void
    {
        $user = Auth::user();

        if ($user->type !== 'internal') {
            abort(403);
        }

        $user->internalUser->update([
            'signature_data' => null,
            'signature_type' => null,
            'signature_updated_at' => null,
        ]);

        $this->signatureData = '';
        $this->hasExistingSignature = false;
        $this->dispatch('signature-cleared');
    }
}; ?>

{{-- Signature Pad JS CDN --}}
@assets
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
@endassets

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Signature Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Signature')" :subheading="__('Configure your personal signature for proposals and estimates')">
        <form wire:submit="saveSignature" class="my-6 w-full space-y-6">

            {{-- Current Signature Preview --}}
            @if ($hasExistingSignature && $signatureData)
                <div class="mb-6">
                    <flux:heading size="sm">Current Signature</flux:heading>
                    <div class="mt-2 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-800">
                        @if ($signatureType === 'drawn')
                            <img src="{{ $signatureData }}" alt="Your signature" class="max-h-20" />
                        @else
                            <div
                                class="text-3xl text-zinc-900 dark:text-white"
                                style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;"
                            >
                                {{ $signatureData }}
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Signature Type Tabs --}}
            <div>
                <flux:label class="mb-2">Signature Type</flux:label>
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
                        x-data="signaturePadSettings()"
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
                                Clear Canvas
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

            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>Your signature will appear on proposals</flux:callout.heading>
                <flux:callout.text>Your signature will be displayed in the "Prepared By" section of estimates and proposals you create, building trust with customers.</flux:callout.text>
            </flux:callout>

            {{-- Action Buttons --}}
            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">
                    {{ __('Save Signature') }}
                </flux:button>

                @if ($hasExistingSignature)
                    <flux:button
                        type="button"
                        variant="ghost"
                        wire:click="clearSignature"
                        wire:confirm="Are you sure you want to remove your signature?"
                    >
                        {{ __('Remove Signature') }}
                    </flux:button>
                @endif

                <x-action-message class="me-3" on="signature-saved">
                    {{ __('Saved.') }}
                </x-action-message>

                <x-action-message class="me-3" on="signature-cleared">
                    {{ __('Removed.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>

{{-- Signature Pad Alpine Component --}}
@script
<script>
    Alpine.data('signaturePadSettings', () => ({
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
