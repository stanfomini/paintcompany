<?php

use App\Models\EmployeeContract;
use App\Services\ContractService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('components.layouts.public')]
#[Title('Sign Contract')]
class extends Component
{
    public EmployeeContract $contract;

    public string $token = '';

    public string $signerName = '';

    public string $signatureType = 'typed';

    public string $typedSignature = '';

    public string $drawnSignature = '';

    public bool $agreed = false;

    public bool $signed = false;

    /** @var array<string, string> */
    public array $fieldValues = [];

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->contract = EmployeeContract::where('token', $token)->firstOrFail();
        $this->contract->load(['template', 'employeeProfile']);

        // Only the contract's employee or admins with employees.view can access
        $isOwner = auth()->id() === $this->contract->employeeProfile?->user_id;
        $isAdmin = auth()->user()?->can('employees.view');
        abort_unless($isOwner || $isAdmin, 403);

        if ($this->contract->isSigned()) {
            $this->signed = true;

            return;
        }

        if ($this->contract->isExpired()) {
            abort(410, __('This contract has expired.'));
        }

        if ($this->contract->status === 'voided') {
            abort(410, __('This contract has been voided.'));
        }

        // Pre-fill field values from contract (merge-tagged values)
        $this->fieldValues = $this->contract->field_values ?? [];

        $this->contract->markViewed();
    }

    #[Computed]
    public function isPdfContract(): bool
    {
        return $this->contract->template
            && $this->contract->template->content_type === 'pdf'
            && $this->contract->template->pdf_path;
    }

    #[Computed]
    public function templateFields(): array
    {
        if (! $this->isPdfContract) {
            return [];
        }

        return $this->contract->template->fields['fields'] ?? [];
    }

    #[Computed]
    public function templatePages(): array
    {
        if (! $this->isPdfContract) {
            return [];
        }

        return $this->contract->template->fields['pages'] ?? [];
    }

    #[Computed]
    public function currentPageFields(): array
    {
        return $this->templateFields;
    }

    #[Computed]
    public function requiredFieldIds(): array
    {
        return collect($this->templateFields)
            ->filter(fn (array $f) => $f['required'] ?? false)
            ->pluck('id')
            ->all();
    }

    public function sign(): void
    {
        $this->validate([
            'signerName' => 'required|string|max:255',
            'agreed' => 'accepted',
        ]);

        $service = app(ContractService::class);

        if ($this->isPdfContract) {
            // Validate all required employee signature/initials fields are filled
            $missingFields = [];
            foreach ($this->templateFields as $field) {
                $signerRole = $field['signer_role'] ?? 'employee';
                if ($signerRole !== 'employee') {
                    continue;
                }

                if (in_array($field['type'], ['signature', 'initials'])
                    && ($field['required'] ?? false)
                    && empty($this->fieldValues[$field['id']] ?? '')) {
                    $missingFields[] = $field['label'];
                }
            }

            if (! empty($missingFields)) {
                $this->addError('fieldValues', __('Please sign all required fields: :fields', [
                    'fields' => implode(', ', $missingFields),
                ]));

                return;
            }

            // Use first signature field value as the contract's signature_data
            $firstSig = collect($this->templateFields)
                ->first(fn (array $f) => $f['type'] === 'signature'
                    && ($f['signer_role'] ?? 'employee') === 'employee'
                    && ! empty($this->fieldValues[$f['id']] ?? ''));

            $signatureData = $firstSig ? ($this->fieldValues[$firstSig['id']] ?? '') : '';
            $signatureType = str_starts_with($signatureData, 'data:') ? 'drawn' : 'typed';

            $sigPayload = [
                'signature_data' => $signatureData,
                'signature_type' => $signatureType,
                'signer_name' => $this->signerName,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ];

            $service->signPdfContract($this->contract, $sigPayload, $this->fieldValues);
        } else {
            if ($this->signatureType === 'typed') {
                $this->validate(['typedSignature' => 'required|string|max:255']);
                $signatureData = $this->typedSignature;
            } else {
                $this->validate(['drawnSignature' => 'required|string']);
                $signatureData = $this->drawnSignature;
            }

            $sigPayload = [
                'signature_data' => $signatureData,
                'signature_type' => $this->signatureType,
                'signer_name' => $this->signerName,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ];

            $service->signContract($this->contract, $sigPayload);
        }

        $this->contract->refresh();
        $this->signed = true;
    }
}; ?>

<div>
    <div class="mx-auto max-w-3xl px-4 py-8">
        @if ($signed)
            {{-- Signed Confirmation --}}
            <div class="rounded-lg border border-green-200 bg-green-50 p-8 text-center dark:border-green-800 dark:bg-green-900/20">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/40">
                    <flux:icon name="check-circle" class="h-10 w-10 text-green-600 dark:text-green-400" />
                </div>
                <flux:heading size="xl" class="mb-2">{{ __('Contract Signed') }}</flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ __('This contract was signed by :name on :date.', [
                        'name' => $contract->signer_name,
                        'date' => $contract->signed_at->format('M j, Y \a\t g:ia'),
                    ]) }}
                </flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="arrow-down-tray" :href="route('contracts.sign.pdf', $contract->token)">
                        {{ __('Download Signed Copy') }}
                    </flux:button>
                </div>
            </div>

            {{-- Show uploaded PDF if present --}}
            @if ($contract->pdf_path)
                <div class="mt-8 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <iframe
                        src="{{ route('contracts.sign.document', $contract->token) }}"
                        class="h-[700px] w-full rounded-lg"
                        title="{{ $contract->title }}"
                    ></iframe>
                </div>
            @endif

            {{-- Show contract content below --}}
            @if ($contract->content)
                <div class="mt-8 rounded-lg border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg" class="mb-4">{{ $contract->title }}</flux:heading>
                    <div class="prose max-w-none dark:prose-invert">
                        {!! $contract->content !!}
                    </div>
                </div>
            @endif
        @else
            {{-- Contract Header --}}
            <div class="mb-6">
                <flux:heading size="xl">{{ $contract->title }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                    {{ __('Please review and sign the contract below.') }}
                </flux:text>
            </div>

            @if ($this->isPdfContract)
                {{-- PDF Signing Experience --}}
                <div class="mb-8" x-data="pdfSigner({
                    pdfUrl: '{{ route('contracts.sign.template-pdf', $contract->token) }}',
                    fields: @js($this->templateFields),
                    fieldValues: @entangle('fieldValues'),
                })">
                    {{-- PDF Canvas with field overlays --}}
                    <div class="relative mx-auto overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900" x-ref="pdfContainer">
                        <canvas x-ref="pdfCanvas" wire:ignore class="block w-full"></canvas>

                        {{-- Field overlays rendered on top of PDF --}}
                        <template x-for="field in currentPageFields" :key="field.id">
                            <div
                                class="absolute"
                                :style="`left: ${field.x_pct}%; top: ${field.y_pct}%; width: ${field.width_pct}%; height: ${field.height_pct}%;`"
                            >
                                {{-- Text field (employee only) --}}
                                <template x-if="field.type === 'text' && (field.signer_role || 'employee') === 'employee'">
                                    <input
                                        type="text"
                                        class="h-full w-full border border-blue-300 bg-blue-50/90 px-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-blue-600 dark:bg-blue-900/50"
                                        :style="`font-size: ${Math.max(10, field.font_size || 12)}px;`"
                                        :readonly="field.merge_tag && fieldValues[field.id]"
                                        :value="fieldValues[field.id] || ''"
                                        x-on:input="fieldValues[field.id] = $event.target.value; $wire.set('fieldValues.' + field.id, $event.target.value)"
                                        :placeholder="field.label"
                                    />
                                </template>

                                {{-- Date field (employee only) --}}
                                <template x-if="field.type === 'date' && (field.signer_role || 'employee') === 'employee'">
                                    <input
                                        type="date"
                                        class="h-full w-full border border-amber-300 bg-amber-50/90 px-1 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-amber-600 dark:bg-amber-900/50"
                                        :style="`font-size: ${Math.max(10, field.font_size || 12)}px;`"
                                        :readonly="field.merge_tag && fieldValues[field.id]"
                                        :value="fieldValues[field.id] || ''"
                                        x-on:change="fieldValues[field.id] = $event.target.value; $wire.set('fieldValues.' + field.id, $event.target.value)"
                                    />
                                </template>

                                {{-- Signature/Initials field (employee) --}}
                                <template x-if="(field.type === 'signature' || field.type === 'initials') && (field.signer_role || 'employee') === 'employee'">
                                    <div
                                        class="flex h-full w-full cursor-pointer items-center justify-center overflow-hidden border-2 border-dashed transition"
                                        :class="fieldValues[field.id]
                                            ? 'border-green-400 bg-green-50/80 dark:border-green-600 dark:bg-green-900/30'
                                            : 'border-purple-400 bg-purple-50/80 hover:bg-purple-100 dark:border-purple-600 dark:bg-purple-900/30'"
                                        x-on:click="openCapture(field.id, field.type)"
                                    >
                                        <template x-if="!fieldValues[field.id]">
                                            <span class="text-center text-xs text-purple-600 dark:text-purple-300" x-text="field.type === 'initials' ? '{{ __('Click to add initials') }}' : '{{ __('Click to sign') }}'"></span>
                                        </template>
                                        <template x-if="fieldValues[field.id] && fieldValues[field.id].startsWith('data:')">
                                            <img :src="fieldValues[field.id]" class="max-h-full max-w-full object-contain" alt="Signature" />
                                        </template>
                                        <template x-if="fieldValues[field.id] && !fieldValues[field.id].startsWith('data:')">
                                            <span class="text-sm italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;" x-text="fieldValues[field.id]"></span>
                                        </template>
                                    </div>
                                </template>

                                {{-- Checkbox field (employee only) --}}
                                <template x-if="field.type === 'checkbox' && (field.signer_role || 'employee') === 'employee'">
                                    <label class="flex h-full w-full cursor-pointer items-center justify-center border border-green-300 bg-green-50/80 dark:border-green-600 dark:bg-green-900/30">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-green-300 text-green-600"
                                            :checked="fieldValues[field.id] === 'true'"
                                            x-on:change="const val = $event.target.checked ? 'true' : 'false'; fieldValues[field.id] = val; $wire.set('fieldValues.' + field.id, val)"
                                        />
                                    </label>
                                </template>

                                {{-- Employer fields - show value if filled, placeholder if not --}}
                                <template x-if="(field.signer_role || 'employee') === 'employer'">
                                    <div class="flex h-full w-full items-center justify-center border border-dashed"
                                         :class="fieldValues[field.id]
                                            ? 'border-blue-300 bg-blue-50/80 dark:border-blue-600 dark:bg-blue-900/30'
                                            : 'border-zinc-300 bg-zinc-100/80 dark:border-zinc-600 dark:bg-zinc-800/50'">
                                        {{-- Typed text (signature, initials, text, date) --}}
                                        <template x-if="fieldValues[field.id] && !fieldValues[field.id].startsWith('data:')">
                                            <span class="px-1 text-sm italic text-blue-700 dark:text-blue-300"
                                                  :style="(field.type === 'signature' || field.type === 'initials') ? 'font-family: Brush Script MT, cursive;' : ''"
                                                  x-text="fieldValues[field.id]"></span>
                                        </template>
                                        {{-- Drawn image --}}
                                        <template x-if="fieldValues[field.id] && fieldValues[field.id].startsWith('data:')">
                                            <img :src="fieldValues[field.id]" class="max-h-full max-w-full object-contain" alt="Employer Signature" />
                                        </template>
                                        {{-- Placeholder --}}
                                        <template x-if="!fieldValues[field.id]">
                                            <span class="text-center text-xs text-zinc-400 dark:text-zinc-500">{{ __('Employer will sign') }}</span>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Inline Signature Capture Modal --}}
                        <div
                            x-show="captureFieldId !== null"
                            x-transition.opacity
                            class="absolute inset-0 z-10 flex items-center justify-center bg-black/40"
                            x-on:click.self="cancelCapture()"
                        >
                            <div class="mx-4 w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" x-on:click.stop>
                                <div class="mb-4 flex items-center justify-between">
                                    <flux:heading size="sm">
                                        <span x-text="captureFieldType === 'initials' ? '{{ __('Add Initials') }}' : '{{ __('Add Signature') }}'"></span>
                                    </flux:heading>
                                    <button type="button" x-on:click="cancelCapture()" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>

                                {{-- Type / Draw Toggle --}}
                                <div class="mb-4 flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="captureType === 'typed' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'"
                                        x-on:click="captureType = 'typed'"
                                    >{{ __('Type') }}</button>
                                    <button
                                        type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="captureType === 'drawn' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'"
                                        x-on:click="captureType = 'drawn'; $nextTick(() => initCaptureCanvas())"
                                    >{{ __('Draw') }}</button>
                                </div>

                                {{-- Typed input --}}
                                <div x-show="captureType === 'typed'" class="mb-4">
                                    <input
                                        type="text"
                                        x-model="captureTypedValue"
                                        class="w-full rounded-md border border-zinc-300 px-3 py-2 text-lg dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                        :placeholder="captureFieldType === 'initials' ? '{{ __('Type your initials') }}' : '{{ __('Type your full name') }}'"
                                        x-on:keydown.enter="confirmCapture()"
                                    />
                                    <div x-show="captureTypedValue" class="mt-3 rounded border border-zinc-200 bg-zinc-50 p-3 text-center dark:border-zinc-700 dark:bg-zinc-800">
                                        <p class="text-2xl italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;" x-text="captureTypedValue"></p>
                                    </div>
                                </div>

                                {{-- Draw canvas --}}
                                <div x-show="captureType === 'drawn'" class="mb-4">
                                    <div class="overflow-hidden rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                                        <canvas
                                            x-ref="captureCanvas"
                                            width="400"
                                            height="150"
                                            class="w-full cursor-crosshair touch-none"
                                            x-on:mousedown="startCaptureDraw($event)"
                                            x-on:mousemove="onCaptureDraw($event)"
                                            x-on:mouseup="stopCaptureDraw()"
                                            x-on:mouseleave="stopCaptureDraw()"
                                            x-on:touchstart="startCaptureDraw($event)"
                                            x-on:touchmove="onCaptureDraw($event)"
                                            x-on:touchend="stopCaptureDraw()"
                                        ></canvas>
                                    </div>
                                    <div class="mt-2 flex justify-end">
                                        <button type="button" x-on:click="clearCaptureDraw()" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Clear') }}</button>
                                    </div>
                                </div>

                                {{-- Confirm / Cancel --}}
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" x-on:click="cancelCapture()">{{ __('Cancel') }}</flux:button>
                                    <flux:button size="sm" variant="primary" x-on:click="confirmCapture()">{{ __('Confirm') }}</flux:button>
                                </div>
                            </div>
                        </div>

                        {{-- Loading --}}
                        <div x-show="loading" class="absolute inset-0 flex items-center justify-center bg-white/70 dark:bg-zinc-900/70">
                            <flux:icon name="arrow-path" class="size-8 animate-spin text-zinc-400" />
                        </div>
                    </div>

                    {{-- Page Navigation with Progress --}}
                    <div class="mt-3 flex flex-col items-center gap-2" x-show="totalPages > 1">
                        {{-- Clickable page dots with completion status --}}
                        <div class="flex items-center gap-1">
                            <template x-for="p in totalPages" :key="p">
                                <button
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium transition"
                                    :class="{
                                        'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900': currentPage === p,
                                        'bg-green-100 text-green-700 ring-1 ring-green-300 dark:bg-green-900/40 dark:text-green-400': currentPage !== p && pageStatuses[p]?.complete,
                                        'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400': currentPage !== p && !pageStatuses[p]?.complete,
                                    }"
                                    x-on:click="currentPage = p; renderPage()"
                                    x-text="p"
                                ></button>
                            </template>
                        </div>
                        {{-- Prev / Next --}}
                        <div class="flex items-center gap-4">
                            <button class="text-sm hover:underline disabled:opacity-50" x-on:click="prevPage()" :disabled="currentPage <= 1">&larr; {{ __('Previous') }}</button>
                            <span class="text-xs text-zinc-500" x-text="'{{ __('Page') }} ' + currentPage + ' {{ __('of') }} ' + totalPages"></span>
                            <button class="text-sm hover:underline disabled:opacity-50" x-on:click="nextPage()" :disabled="currentPage >= totalPages">{{ __('Next') }} &rarr;</button>
                        </div>
                    </div>
                </div>
            @else
                {{-- Original: Uploaded PDF Document --}}
                @if ($contract->pdf_path)
                    <div class="mb-8 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <iframe
                            src="{{ route('contracts.sign.document', $contract->token) }}"
                            class="h-[700px] w-full rounded-lg"
                            title="{{ $contract->title }}"
                        ></iframe>
                    </div>
                @endif

                {{-- Contract Content --}}
                @if ($contract->content)
                    <div class="mb-8 rounded-lg border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="prose max-w-none dark:prose-invert">
                            {!! $contract->content !!}
                        </div>
                    </div>
                @endif
            @endif

            {{-- Signature Section --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" class="mb-6">{{ __('Sign Contract') }}</flux:heading>

                {{-- Full Name --}}
                <div class="mb-6">
                    <flux:field>
                        <flux:label>{{ __('Full Legal Name') }}</flux:label>
                        <flux:input wire:model="signerName" placeholder="{{ __('Enter your full name') }}" />
                        <flux:error name="signerName" />
                    </flux:field>
                </div>

                @if (! $this->isPdfContract)
                    {{-- Signature Type Toggle (non-PDF contracts only) --}}
                    <div class="mb-6">
                        <flux:text class="mb-2 text-sm font-medium">{{ __('Signature Method') }}</flux:text>
                        <div class="flex gap-2">
                            <flux:button
                                wire:click="$set('signatureType', 'typed')"
                                variant="{{ $signatureType === 'typed' ? 'primary' : 'ghost' }}"
                                size="sm"
                            >
                                {{ __('Type Signature') }}
                            </flux:button>
                            <flux:button
                                wire:click="$set('signatureType', 'drawn')"
                                variant="{{ $signatureType === 'drawn' ? 'primary' : 'ghost' }}"
                                size="sm"
                            >
                                {{ __('Draw Signature') }}
                            </flux:button>
                        </div>
                    </div>

                    @if ($signatureType === 'typed')
                        {{-- Typed Signature --}}
                        <div class="mb-6">
                            <flux:field>
                                <flux:label>{{ __('Type Your Signature') }}</flux:label>
                                <flux:input wire:model="typedSignature" placeholder="{{ __('Type your full name as signature') }}" class="font-cursive text-2xl" />
                                <flux:error name="typedSignature" />
                            </flux:field>
                            @if ($typedSignature)
                                <div class="mt-3 rounded border border-zinc-200 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-800">
                                    <p class="text-2xl italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;">{{ $typedSignature }}</p>
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- Drawn Signature Canvas --}}
                        <div class="mb-6" x-data="{
                            canvas: null,
                            ctx: null,
                            drawing: false,
                            init() {
                                this.canvas = this.$refs.signatureCanvas;
                                this.ctx = this.canvas.getContext('2d');
                                this.ctx.strokeStyle = '#1f2937';
                                this.ctx.lineWidth = 2;
                                this.ctx.lineCap = 'round';
                            },
                            startDrawing(e) {
                                this.drawing = true;
                                const rect = this.canvas.getBoundingClientRect();
                                const x = (e.clientX || e.touches[0].clientX) - rect.left;
                                const y = (e.clientY || e.touches[0].clientY) - rect.top;
                                this.ctx.beginPath();
                                this.ctx.moveTo(x, y);
                            },
                            draw(e) {
                                if (!this.drawing) return;
                                e.preventDefault();
                                const rect = this.canvas.getBoundingClientRect();
                                const x = (e.clientX || e.touches[0].clientX) - rect.left;
                                const y = (e.clientY || e.touches[0].clientY) - rect.top;
                                this.ctx.lineTo(x, y);
                                this.ctx.stroke();
                            },
                            stopDrawing() {
                                this.drawing = false;
                                $wire.set('drawnSignature', this.canvas.toDataURL());
                            },
                            clear() {
                                this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                                $wire.set('drawnSignature', '');
                            }
                        }">
                            <flux:text class="mb-2 text-sm font-medium">{{ __('Draw Your Signature') }}</flux:text>
                            <div class="overflow-hidden rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                                <canvas
                                    x-ref="signatureCanvas"
                                    width="600"
                                    height="200"
                                    class="w-full cursor-crosshair touch-none"
                                    x-on:mousedown="startDrawing($event)"
                                    x-on:mousemove="draw($event)"
                                    x-on:mouseup="stopDrawing()"
                                    x-on:mouseleave="stopDrawing()"
                                    x-on:touchstart="startDrawing($event)"
                                    x-on:touchmove="draw($event)"
                                    x-on:touchend="stopDrawing()"
                                ></canvas>
                            </div>
                            <div class="mt-2 flex justify-end">
                                <flux:button x-on:click="clear()" size="xs" variant="ghost">{{ __('Clear') }}</flux:button>
                            </div>
                            <flux:error name="drawnSignature" />
                        </div>
                    @endif
                @else
                    {{-- PDF contracts: field validation error --}}
                    @error('fieldValues')
                        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                            {{ $message }}
                        </div>
                    @enderror
                @endif

                {{-- Agreement Checkbox --}}
                <div class="mb-6">
                    <label class="flex items-start gap-3">
                        <flux:checkbox wire:model="agreed" class="mt-0.5" />
                        <flux:text class="text-sm">
                            {{ __('I have read and agree to the terms of this contract. I understand that this electronic signature is legally binding.') }}
                        </flux:text>
                    </label>
                    <flux:error name="agreed" />
                </div>

                {{-- Sign Button --}}
                <flux:button wire:click="sign" variant="primary" class="w-full">
                    {{ __('Sign Contract') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if ($this->isPdfContract && !$signed)
        @assets
            @vite('resources/js/pdf-viewer.js')
        @endassets

        @script
        <script>
            // NOTE: pdfDoc stored outside Alpine reactive system to avoid Proxy wrapping,
            // which breaks pdfjs-dist private class fields
            Alpine.data('pdfSigner', (config) => {
                let rawPdfDoc = null;
                let captureCanvasCtx = null;

                return {
                totalPages: 0,
                currentPage: 1,
                loading: true,
                fieldValues: config.fieldValues,

                // Inline capture modal state
                captureFieldId: null,
                captureFieldType: 'signature',
                captureType: 'typed',
                captureTypedValue: '',
                captureDrawnValue: '',
                captureDrawing: false,

                get currentPageFields() {
                    return config.fields.filter(f => (f.page || 1) === this.currentPage);
                },

                get pageStatuses() {
                    const statuses = {};
                    for (let p = 1; p <= this.totalPages; p++) {
                        const pageFields = config.fields.filter(f =>
                            (f.page || 1) === p && (f.signer_role || 'employee') === 'employee'
                        );
                        const requiredFields = pageFields.filter(f => f.required && (f.type === 'signature' || f.type === 'initials'));
                        const filledRequired = requiredFields.filter(f => !!this.fieldValues[f.id]);
                        statuses[p] = {
                            hasFields: pageFields.length > 0,
                            total: requiredFields.length,
                            filled: filledRequired.length,
                            complete: requiredFields.length === 0 || filledRequired.length === requiredFields.length,
                        };
                    }
                    return statuses;
                },

                async init() {
                    if (!window.PdfViewer) {
                        this.loading = false;
                        return;
                    }

                    try {
                        rawPdfDoc = await window.PdfViewer.loadPdf(config.pdfUrl);
                        this.totalPages = rawPdfDoc.numPages;
                        await this.renderPage();
                    } catch (e) {
                        console.error('Failed to load PDF:', e);
                    } finally {
                        this.loading = false;
                    }
                },

                // Signature capture modal
                openCapture(fieldId, fieldType) {
                    this.captureFieldId = fieldId;
                    this.captureFieldType = fieldType || 'signature';
                    this.captureType = 'typed';
                    this.captureTypedValue = '';
                    this.captureDrawnValue = '';
                    captureCanvasCtx = null;
                },

                confirmCapture() {
                    if (!this.captureFieldId) return;
                    const value = this.captureType === 'typed'
                        ? this.captureTypedValue
                        : this.captureDrawnValue;
                    if (!value) return;
                    this.fieldValues[this.captureFieldId] = value;
                    $wire.set('fieldValues.' + this.captureFieldId, value);
                    this.captureFieldId = null;
                },

                cancelCapture() {
                    this.captureFieldId = null;
                },

                initCaptureCanvas() {
                    const canvas = this.$refs.captureCanvas;
                    if (!canvas) return;
                    captureCanvasCtx = canvas.getContext('2d');
                    captureCanvasCtx.strokeStyle = '#1f2937';
                    captureCanvasCtx.lineWidth = 2;
                    captureCanvasCtx.lineCap = 'round';
                    captureCanvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                    this.captureDrawnValue = '';
                },

                startCaptureDraw(e) {
                    if (!captureCanvasCtx) this.initCaptureCanvas();
                    this.captureDrawing = true;
                    const canvas = this.$refs.captureCanvas;
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const x = ((e.clientX || e.touches[0].clientX) - rect.left) * scaleX;
                    const y = ((e.clientY || e.touches[0].clientY) - rect.top) * scaleY;
                    captureCanvasCtx.beginPath();
                    captureCanvasCtx.moveTo(x, y);
                },

                onCaptureDraw(e) {
                    if (!this.captureDrawing || !captureCanvasCtx) return;
                    e.preventDefault();
                    const canvas = this.$refs.captureCanvas;
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const x = ((e.clientX || e.touches[0].clientX) - rect.left) * scaleX;
                    const y = ((e.clientY || e.touches[0].clientY) - rect.top) * scaleY;
                    captureCanvasCtx.lineTo(x, y);
                    captureCanvasCtx.stroke();
                },

                stopCaptureDraw() {
                    if (!this.captureDrawing) return;
                    this.captureDrawing = false;
                    const canvas = this.$refs.captureCanvas;
                    if (canvas) {
                        this.captureDrawnValue = canvas.toDataURL();
                    }
                },

                clearCaptureDraw() {
                    const canvas = this.$refs.captureCanvas;
                    if (canvas && captureCanvasCtx) {
                        captureCanvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                    }
                    this.captureDrawnValue = '';
                },

                async renderPage() {
                    if (!rawPdfDoc) return;
                    const canvas = this.$refs.pdfCanvas;
                    await window.PdfViewer.renderPage(rawPdfDoc, this.currentPage, canvas, 1.5);
                },

                async prevPage() {
                    if (this.currentPage > 1) {
                        this.currentPage--;
                        await this.renderPage();
                    }
                },

                async nextPage() {
                    if (this.currentPage < this.totalPages) {
                        this.currentPage++;
                        await this.renderPage();
                    }
                },
            };
            });
        </script>
        @endscript
    @endif
</div>
