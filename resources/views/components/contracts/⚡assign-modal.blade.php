<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Services\ContractService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public EmployeeProfile $employeeProfile;

    public bool $showModal = false;

    public ?int $selectedTemplateId = null;

    public string $customContent = '';

    public bool $sendImmediately = false;

    public bool $showPreview = false;

    public string $previewHtml = '';

    // Employer signing step
    public int $step = 1;

    /** @var array<string, string> */
    public array $employerFieldValues = [];

    // Rich text employer signature
    public string $employerSignatureType = 'typed';

    public string $employerTypedSignature = '';

    public string $employerDrawnSignature = '';

    #[On('open-assign-contract')]
    public function openModal(): void
    {
        $this->selectedTemplateId = null;
        $this->customContent = '';
        $this->sendImmediately = false;
        $this->showPreview = false;
        $this->previewHtml = '';
        $this->step = 1;
        $this->employerFieldValues = [];
        $this->employerSignatureType = 'typed';
        $this->employerTypedSignature = '';
        $this->employerDrawnSignature = '';
        $this->showModal = true;
    }

    #[Computed]
    public function templates()
    {
        return ContractTemplate::query()
            ->active()
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedTemplate(): ?ContractTemplate
    {
        return $this->selectedTemplateId ? ContractTemplate::find($this->selectedTemplateId) : null;
    }

    #[Computed]
    public function isSelectedPdf(): bool
    {
        return $this->selectedTemplate?->content_type === 'pdf';
    }

    #[Computed]
    public function selectedTemplateEmployerFields(): array
    {
        if (! $this->isSelectedPdf) {
            return [];
        }

        $fields = $this->selectedTemplate->fields['fields'] ?? [];

        return array_values(array_filter($fields, fn ($f) => ($f['signer_role'] ?? 'employee') === 'employer'));
    }

    #[Computed]
    public function templateRequiresEmployerSignature(): bool
    {
        if ($this->isSelectedPdf) {
            return ! empty($this->selectedTemplateEmployerFields);
        }

        // Rich text contracts always get an employer signature
        return $this->selectedTemplate !== null;
    }

    public function updatedSelectedTemplateId(): void
    {
        $this->step = 1;
        $this->employerFieldValues = [];

        if ($this->selectedTemplateId) {
            $template = ContractTemplate::find($this->selectedTemplateId);
            if ($template && $template->content_type === 'rich_text') {
                $service = app(ContractService::class);
                $resolved = $service->resolveMergeTags($template->content ?? '', $this->employeeProfile);
                $this->customContent = $resolved;
            } else {
                $this->customContent = '';
            }
            $this->showPreview = false;
            $this->previewHtml = '';

            // Pre-fill merge-tagged field values for PDF templates
            if ($template && $template->content_type === 'pdf') {
                $service = app(ContractService::class);
                $this->employerFieldValues = $service->resolvePdfMergeTags($template, $this->employeeProfile);
            }
        } else {
            $this->customContent = '';
        }
    }

    public function togglePreview(): void
    {
        if (! $this->showPreview) {
            $this->previewHtml = nl2br(e($this->customContent));
        }
        $this->showPreview = ! $this->showPreview;
    }

    public function proceedToSigning(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        // Validate step 1 fields
        if ($this->isSelectedPdf) {
            $this->validate([
                'selectedTemplateId' => 'required|exists:contract_templates,id',
            ]);
        } else {
            $this->validate([
                'selectedTemplateId' => 'required|exists:contract_templates,id',
                'customContent' => 'required|string|max:100000',
            ]);
        }

        $this->showModal = false;
        $this->step = 2;
    }

    public function backToDetails(): void
    {
        $this->step = 1;
        $this->showModal = true;
    }

    public function assignContract(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $template = ContractTemplate::findOrFail($this->selectedTemplateId);
        $service = app(ContractService::class);

        // If template requires employer signature and we haven't been through signing step, go there
        if ($this->templateRequiresEmployerSignature && $this->step === 1) {
            $this->proceedToSigning();

            return;
        }

        if ($template->content_type === 'pdf') {
            $this->validate([
                'selectedTemplateId' => 'required|exists:contract_templates,id',
            ]);

            // Validate required employer signature fields
            if (! empty($this->selectedTemplateEmployerFields)) {
                $missing = [];
                foreach ($this->selectedTemplateEmployerFields as $field) {
                    if (in_array($field['type'], ['signature', 'initials'])
                        && ($field['required'] ?? false)
                        && empty($this->employerFieldValues[$field['id']] ?? '')) {
                        $missing[] = $field['label'];
                    }
                }

                if (! empty($missing)) {
                    $this->addError('employerFieldValues', __('Please sign all required fields: :fields', ['fields' => implode(', ', $missing)]));

                    return;
                }
            }

            $contract = $service->createPdfContractFromTemplate($template, $this->employeeProfile);

            // Merge employer field values into the contract
            if (! empty($this->employerFieldValues)) {
                $existing = $contract->field_values ?? [];
                $merged = array_merge($existing, $this->employerFieldValues);
                $contract->update(['field_values' => $merged]);
            }
        } else {
            $this->validate([
                'selectedTemplateId' => 'required|exists:contract_templates,id',
                'customContent' => 'required|string|max:100000',
            ]);

            // Validate rich text employer signature
            if ($this->employerSignatureType === 'typed') {
                $this->validate(['employerTypedSignature' => 'required|string|max:255']);
                $sigData = $this->employerTypedSignature;
            } else {
                $this->validate(['employerDrawnSignature' => 'required|string']);
                $sigData = $this->employerDrawnSignature;
            }

            $contract = $service->createContractFromTemplate(
                $template,
                $this->employeeProfile,
                $this->customContent,
            );
        }

        // Save countersign data
        $countersignData = null;
        if ($template->content_type === 'pdf' && ! empty($this->selectedTemplateEmployerFields)) {
            $firstSig = collect($this->selectedTemplateEmployerFields)
                ->first(fn ($f) => $f['type'] === 'signature' && ! empty($this->employerFieldValues[$f['id']] ?? ''));
            $countersignData = $firstSig ? ($this->employerFieldValues[$firstSig['id']] ?? '') : auth()->user()->name;
        } elseif ($template->content_type !== 'pdf') {
            $countersignData = $sigData ?? null;
        }

        if ($countersignData) {
            $contract->update([
                'countersigned_by' => auth()->id(),
                'countersigned_at' => now(),
                'countersign_data' => $countersignData,
            ]);
        }

        if ($this->sendImmediately) {
            $service->sendContract($contract);
            session()->flash('success', __('Contract created, signed, and sent to employee.'));
        } else {
            session()->flash('success', __('Contract saved as draft with your signature.'));
        }

        $this->showModal = false;
        $this->dispatch('contract-assigned');
    }
}; ?>

<div>
    {{-- Step 1: Flyout Modal for Template Selection --}}
    <flux:modal wire:model="showModal" variant="flyout" class="max-w-2xl">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Assign Contract') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __('Select a template and send a contract to :name.', ['name' => $employeeProfile->user->name ?? '']) }}
            </flux:text>

            {{-- Template Selection --}}
            <flux:field>
                <flux:label>{{ __('Contract Template') }}</flux:label>
                <flux:select wire:model.live="selectedTemplateId">
                    <flux:select.option value="">{{ __('Select a template...') }}</flux:select.option>
                    @foreach ($this->templates as $template)
                        <flux:select.option value="{{ $template->id }}">
                            {{ $template->name }} (v{{ $template->version }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="selectedTemplateId" />
            </flux:field>

            @if ($selectedTemplateId)
                @if ($this->isSelectedPdf)
                    {{-- PDF Template Notice --}}
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                        <div class="flex items-center gap-3">
                            <flux:icon name="document" class="size-5 text-blue-500" />
                            <div>
                                <flux:text class="font-medium text-blue-800 dark:text-blue-200">{{ __('PDF Template') }}</flux:text>
                                <flux:text class="text-sm text-blue-600 dark:text-blue-400">{{ __('Fields will be auto-filled with employee data. The employee will complete and sign the form online.') }}</flux:text>
                            </div>
                        </div>
                    </div>

                    @if (!empty($this->selectedTemplateEmployerFields))
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                            <div class="flex items-center gap-3">
                                <flux:icon name="pencil-square" class="size-5 text-amber-500" />
                                <div>
                                    <flux:text class="font-medium text-amber-800 dark:text-amber-200">{{ __('Employer Signature Required') }}</flux:text>
                                    <flux:text class="text-sm text-amber-600 dark:text-amber-400">{{ __('This template has fields that require your signature. You will sign in the next step.') }}</flux:text>
                                </div>
                            </div>
                        </div>
                    @endif
                @else
                    {{-- Preview/Edit Toggle --}}
                    <div class="flex items-center justify-between">
                        <flux:text class="text-sm font-medium">{{ __('Contract Content') }}</flux:text>
                        <flux:button wire:click="togglePreview" size="xs" variant="ghost" icon="eye">
                            {{ $showPreview ? __('Edit') : __('Preview') }}
                        </flux:button>
                    </div>

                    @if ($showPreview)
                        <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="prose max-w-none dark:prose-invert text-sm">
                                {!! $previewHtml !!}
                            </div>
                        </div>
                    @else
                        <flux:field>
                            <flux:textarea
                                wire:model="customContent"
                                rows="16"
                                class="font-mono text-sm"
                                placeholder="{{ __('Contract content with merge tags resolved...') }}"
                            />
                            <flux:description>{{ __('You can customize the content before sending.') }}</flux:description>
                            <flux:error name="customContent" />
                        </flux:field>
                    @endif
                @endif

                {{-- Send Option --}}
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <label class="flex items-center gap-3">
                        <flux:checkbox wire:model="sendImmediately" />
                        <div>
                            <flux:text class="font-medium">{{ __('Send immediately') }}</flux:text>
                            <flux:text class="text-sm text-zinc-500">{{ __('Send the contract to the employee for signing right away.') }}</flux:text>
                        </div>
                    </label>
                </div>
            @endif

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                @if ($selectedTemplateId && $this->templateRequiresEmployerSignature)
                    <flux:button wire:click="proceedToSigning" variant="primary" :disabled="!$selectedTemplateId">
                        {{ __('Next: Sign Contract') }}
                    </flux:button>
                @else
                    <flux:button wire:click="assignContract" variant="primary" :disabled="!$selectedTemplateId">
                        {{ $sendImmediately ? __('Create & Send') : __('Save as Draft') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>

    {{-- Step 2: Full-Screen Signing Overlay --}}
    @if ($step === 2)
        <div class="fixed inset-0 z-50 flex flex-col bg-white dark:bg-zinc-900" wire:transition>
            {{-- Top Bar --}}
            <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <div class="flex items-center gap-3">
                    <flux:button variant="ghost" size="sm" icon="arrow-left" wire:click="backToDetails" />
                    <div>
                        <flux:heading size="lg">{{ __('Sign as Employer') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $this->selectedTemplate?->name }} &middot; {{ $employeeProfile->user->name ?? '' }}
                        </flux:text>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <flux:button wire:click="backToDetails" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button wire:click="assignContract" variant="primary" icon="paper-airplane">
                        {{ $sendImmediately ? __('Sign, Create & Send') : __('Sign & Save as Draft') }}
                    </flux:button>
                </div>
            </div>

            @error('employerFieldValues')
                <div class="border-b border-red-200 bg-red-50 px-6 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            {{-- Signing Content --}}
            <div class="flex-1 overflow-y-auto">
                @if ($this->isSelectedPdf)
                    {{-- PDF Employer Signing - full width --}}
                    <div class="mx-auto max-w-5xl p-6" x-data="assignEmployerSigner({
                        pdfUrl: '{{ route('contracts.templates.assign-preview', $this->selectedTemplate) }}',
                        fields: @js($this->selectedTemplate->fields['fields'] ?? []),
                        fieldValues: @entangle('employerFieldValues'),
                    })">
                        <div class="relative mx-auto overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 shadow-lg dark:border-zinc-700 dark:bg-zinc-900" x-ref="pdfContainer">
                            <canvas x-ref="pdfCanvas" wire:ignore class="block w-full"></canvas>

                            {{-- Field overlays --}}
                            <template x-for="field in currentPageFields" :key="field.id">
                                <div class="absolute" :style="`left: ${field.x_pct}%; top: ${field.y_pct}%; width: ${field.width_pct}%; height: ${field.height_pct}%;`">
                                    {{-- Employer signature/initials fields - interactive --}}
                                    <template x-if="(field.signer_role || 'employee') === 'employer' && (field.type === 'signature' || field.type === 'initials')">
                                        <div class="flex h-full w-full cursor-pointer items-center justify-center overflow-hidden border-2 border-dashed transition"
                                            :class="fieldValues[field.id]
                                                ? 'border-green-400 bg-green-50/80 dark:border-green-600 dark:bg-green-900/30'
                                                : 'border-blue-400 bg-blue-50/80 hover:bg-blue-100 dark:border-blue-600 dark:bg-blue-900/30'"
                                            x-on:click="openCapture(field.id, field.type)">
                                            <template x-if="!fieldValues[field.id]">
                                                <span class="text-center text-xs text-blue-600 dark:text-blue-300" x-text="field.type === 'initials' ? '{{ __('Click to add initials') }}' : '{{ __('Click to sign') }}'"></span>
                                            </template>
                                            <template x-if="fieldValues[field.id] && fieldValues[field.id].startsWith('data:')">
                                                <img :src="fieldValues[field.id]" class="max-h-full max-w-full object-contain" alt="Signature" />
                                            </template>
                                            <template x-if="fieldValues[field.id] && !fieldValues[field.id].startsWith('data:')">
                                                <span class="text-sm italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;" x-text="fieldValues[field.id]"></span>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Employer text/date fields --}}
                                    <template x-if="(field.signer_role || 'employee') === 'employer' && field.type === 'text'">
                                        <input type="text" class="h-full w-full border border-blue-300 bg-blue-50/90 px-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-blue-600 dark:bg-blue-900/50"
                                            :style="`font-size: ${Math.max(10, field.font_size || 12)}px;`"
                                            :value="fieldValues[field.id] || ''"
                                            x-on:input="fieldValues[field.id] = $event.target.value; $wire.set('employerFieldValues.' + field.id, $event.target.value)"
                                            :placeholder="field.label" />
                                    </template>

                                    <template x-if="(field.signer_role || 'employee') === 'employer' && field.type === 'date'">
                                        <input type="date" class="h-full w-full border border-amber-300 bg-amber-50/90 px-1 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-amber-600 dark:bg-amber-900/50"
                                            :style="`font-size: ${Math.max(10, field.font_size || 12)}px;`"
                                            :value="fieldValues[field.id] || ''"
                                            x-on:change="fieldValues[field.id] = $event.target.value; $wire.set('employerFieldValues.' + field.id, $event.target.value)" />
                                    </template>

                                    {{-- Employee fields - gray placeholder --}}
                                    <template x-if="(field.signer_role || 'employee') === 'employee'">
                                        <div class="flex h-full w-full items-center justify-center border border-dashed border-zinc-300 bg-zinc-100/80 dark:border-zinc-600 dark:bg-zinc-800/50">
                                            <span class="text-center text-xs text-zinc-400 dark:text-zinc-500">{{ __('Employee will fill') }}</span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Inline Signature Capture Modal --}}
                            <div x-show="captureFieldId !== null" x-transition.opacity class="absolute inset-0 z-10 flex items-center justify-center bg-black/40" x-on:click.self="cancelCapture()">
                                <div class="mx-4 w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" x-on:click.stop>
                                    <div class="mb-4 flex items-center justify-between">
                                        <flux:heading size="sm">
                                            <span x-text="captureFieldType === 'initials' ? '{{ __('Add Initials') }}' : '{{ __('Add Signature') }}'"></span>
                                        </flux:heading>
                                        <button type="button" x-on:click="cancelCapture()" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>

                                    <div class="mb-4 flex gap-2">
                                        <button type="button" class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                            :class="captureType === 'typed' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                            x-on:click="captureType = 'typed'">{{ __('Type') }}</button>
                                        <button type="button" class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                            :class="captureType === 'drawn' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                            x-on:click="captureType = 'drawn'; $nextTick(() => initCaptureCanvas())">{{ __('Draw') }}</button>
                                    </div>

                                    <div x-show="captureType === 'typed'" class="mb-4">
                                        <input type="text" x-model="captureTypedValue"
                                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-lg dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                            :placeholder="captureFieldType === 'initials' ? '{{ __('Type your initials') }}' : '{{ __('Type your full name') }}'"
                                            x-on:keydown.enter="confirmCapture()" />
                                        <div x-show="captureTypedValue" class="mt-3 rounded border border-zinc-200 bg-zinc-50 p-3 text-center dark:border-zinc-700 dark:bg-zinc-800">
                                            <p class="text-2xl italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;" x-text="captureTypedValue"></p>
                                        </div>
                                    </div>

                                    <div x-show="captureType === 'drawn'" class="mb-4">
                                        <div class="overflow-hidden rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                                            <canvas x-ref="captureCanvas" width="400" height="150" class="w-full cursor-crosshair touch-none"
                                                x-on:mousedown="startCaptureDraw($event)" x-on:mousemove="onCaptureDraw($event)"
                                                x-on:mouseup="stopCaptureDraw()" x-on:mouseleave="stopCaptureDraw()"
                                                x-on:touchstart="startCaptureDraw($event)" x-on:touchmove="onCaptureDraw($event)" x-on:touchend="stopCaptureDraw()"></canvas>
                                        </div>
                                        <div class="mt-2 flex justify-end">
                                            <button type="button" x-on:click="clearCaptureDraw()" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Clear') }}</button>
                                        </div>
                                    </div>

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

                        {{-- Page Navigation --}}
                        <div class="mt-4 flex items-center justify-center gap-4" x-show="totalPages > 1">
                            <button class="rounded px-3 py-1.5 text-sm hover:bg-zinc-200 disabled:opacity-50 dark:hover:bg-zinc-700" x-on:click="prevPage()" :disabled="currentPage <= 1">&larr; {{ __('Previous') }}</button>
                            <span class="text-sm text-zinc-500" x-text="'{{ __('Page') }} ' + currentPage + ' {{ __('of') }} ' + totalPages"></span>
                            <button class="rounded px-3 py-1.5 text-sm hover:bg-zinc-200 disabled:opacity-50 dark:hover:bg-zinc-700" x-on:click="nextPage()" :disabled="currentPage >= totalPages">{{ __('Next') }} &rarr;</button>
                        </div>
                    </div>
                @else
                    {{-- Rich Text Employer Signature - centered card --}}
                    <div class="mx-auto max-w-lg p-6">
                        <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="lg" class="mb-2">{{ __('Company Signature') }}</flux:heading>
                            <flux:text class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Add your signature as the company representative before assigning this contract.') }}</flux:text>

                            <div class="space-y-4">
                                <div class="flex gap-2">
                                    <flux:button wire:click="$set('employerSignatureType', 'typed')" :variant="$employerSignatureType === 'typed' ? 'primary' : 'ghost'" size="sm">{{ __('Type Signature') }}</flux:button>
                                    <flux:button wire:click="$set('employerSignatureType', 'drawn')" :variant="$employerSignatureType === 'drawn' ? 'primary' : 'ghost'" size="sm">{{ __('Draw Signature') }}</flux:button>
                                </div>

                                @if ($employerSignatureType === 'typed')
                                    <flux:field>
                                        <flux:label>{{ __('Type Your Signature') }}</flux:label>
                                        <flux:input wire:model="employerTypedSignature" :placeholder="auth()->user()?->name" />
                                        <flux:error name="employerTypedSignature" />
                                    </flux:field>
                                    @if ($employerTypedSignature)
                                        <div class="rounded border border-zinc-200 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-800">
                                            <p class="text-2xl italic text-zinc-800 dark:text-zinc-200" style="font-family: 'Brush Script MT', cursive;">{{ $employerTypedSignature }}</p>
                                        </div>
                                    @endif
                                @else
                                    <div x-data="{
                                        canvas: null, ctx: null, drawing: false,
                                        init() {
                                            this.canvas = this.$refs.assignSignCanvas;
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
                                            this.ctx.beginPath(); this.ctx.moveTo(x, y);
                                        },
                                        draw(e) {
                                            if (!this.drawing) return; e.preventDefault();
                                            const rect = this.canvas.getBoundingClientRect();
                                            const x = (e.clientX || e.touches[0].clientX) - rect.left;
                                            const y = (e.clientY || e.touches[0].clientY) - rect.top;
                                            this.ctx.lineTo(x, y); this.ctx.stroke();
                                        },
                                        stopDrawing() {
                                            this.drawing = false;
                                            $wire.set('employerDrawnSignature', this.canvas.toDataURL());
                                        },
                                        clear() {
                                            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                                            $wire.set('employerDrawnSignature', '');
                                        }
                                    }">
                                        <flux:text class="mb-2 text-sm font-medium">{{ __('Draw Your Signature') }}</flux:text>
                                        <div class="overflow-hidden rounded-lg border-2 border-dashed border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                                            <canvas x-ref="assignSignCanvas" width="500" height="180" class="w-full cursor-crosshair touch-none"
                                                x-on:mousedown="startDrawing($event)" x-on:mousemove="draw($event)"
                                                x-on:mouseup="stopDrawing()" x-on:mouseleave="stopDrawing()"
                                                x-on:touchstart="startDrawing($event)" x-on:touchmove="draw($event)" x-on:touchend="stopDrawing()"></canvas>
                                        </div>
                                        <div class="mt-2 flex justify-end">
                                            <flux:button x-on:click="clear()" size="xs" variant="ghost">{{ __('Clear') }}</flux:button>
                                        </div>
                                        <flux:error name="employerDrawnSignature" />
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($step === 2 && $this->isSelectedPdf)
        @assets
            @vite('resources/js/pdf-viewer.js')
        @endassets

        @script
        <script>
            Alpine.data('assignEmployerSigner', (config) => {
                let rawPdfDoc = null;
                let captureCanvasCtx = null;

                return {
                    totalPages: 0,
                    currentPage: 1,
                    loading: true,
                    fieldValues: config.fieldValues,

                    captureFieldId: null,
                    captureFieldType: 'signature',
                    captureType: 'typed',
                    captureTypedValue: '',
                    captureDrawnValue: '',
                    captureDrawing: false,

                    get currentPageFields() {
                        return config.fields.filter(f => (f.page || 1) === this.currentPage);
                    },

                    async init() {
                        if (!window.PdfViewer) { this.loading = false; return; }
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
                        const value = this.captureType === 'typed' ? this.captureTypedValue : this.captureDrawnValue;
                        if (!value) return;
                        this.fieldValues[this.captureFieldId] = value;
                        $wire.set('employerFieldValues.' + this.captureFieldId, value);
                        this.captureFieldId = null;
                    },

                    cancelCapture() { this.captureFieldId = null; },

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
                        if (canvas) this.captureDrawnValue = canvas.toDataURL();
                    },

                    clearCaptureDraw() {
                        const canvas = this.$refs.captureCanvas;
                        if (canvas && captureCanvasCtx) captureCanvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                        this.captureDrawnValue = '';
                    },

                    async renderPage() {
                        if (!rawPdfDoc) return;
                        const canvas = this.$refs.pdfCanvas;
                        await window.PdfViewer.renderPage(rawPdfDoc, this.currentPage, canvas, 1.5);
                    },

                    async prevPage() {
                        if (this.currentPage > 1) { this.currentPage--; await this.renderPage(); }
                    },

                    async nextPage() {
                        if (this.currentPage < this.totalPages) { this.currentPage++; await this.renderPage(); }
                    },
                };
            });
        </script>
        @endscript
    @endif
</div>
