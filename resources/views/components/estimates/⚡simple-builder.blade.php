<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Services\EstimateAmendmentService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

new class extends Component {
    public ?Deal $deal = null;

    public array $projectTypes = [
        'interior' => 'Interior',
        'exterior' => 'Exterior',
        'both' => 'Interior & Exterior',
    ];

    public function getTranslatedProjectTypes(): array
    {
        return [
            'interior' => __('Interior'),
            'exterior' => __('Exterior'),
            'both' => __('Interior & Exterior'),
        ];
    }

    public ?Estimate $estimate = null;

    public bool $isEditing = false;

    public string $title = '';

    public float $totalSqft = 0;

    public float $ratePerSqft = 4.50;

    public string $projectType = 'interior';

    public float $taxRate = 0;

    public float $discountAmount = 0;

    public float $depositPercent = 30;

    public string $notes = '';

    public ?string $validUntil = null;

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'totalSqft' => ['required', 'numeric', 'min:1'],
            'ratePerSqft' => ['required', 'numeric', 'min:0.01'],
            'projectType' => ['required', 'string', 'in:interior,exterior,both'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'discountAmount' => ['required', 'numeric', 'min:0'],
            'depositPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'validUntil' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    protected function messages(): array
    {
        return [
            'title.required' => __('Please enter a title for the estimate.'),
            'totalSqft.required' => __('Please enter the total square footage.'),
            'totalSqft.min' => __('Square footage must be at least 1.'),
            'ratePerSqft.required' => __('Please enter the rate per square foot.'),
            'ratePerSqft.min' => __('Rate must be greater than 0.'),
        ];
    }

    public function mount(?Deal $deal = null, ?Estimate $estimate = null): void
    {
        $settings = CompanySetting::getInstance();

        // Load defaults from company settings
        $this->taxRate = (float) $settings->default_tax_rate;
        $this->depositPercent = (float) $settings->default_deposit_percent;
        $this->ratePerSqft = (float) $settings->default_interior_sqft_rate;

        // Set default valid until based on company settings
        $validityDays = (int) $settings->estimate_validity_days;
        $this->validUntil = now()->addDays($validityDays)->format('Y-m-d');

        if ($estimate && $estimate->exists) {
            $this->estimate = $estimate;
            $this->deal = $estimate->deal;
            $this->isEditing = true;

            $this->title = $estimate->title;
            $this->totalSqft = (float) $estimate->simple_sqft;
            $this->ratePerSqft = (float) $estimate->simple_rate_per_sqft;
            $this->projectType = $estimate->project_type ?? 'interior';
            $this->taxRate = (float) $estimate->tax_rate;
            $this->discountAmount = (float) $estimate->discount_amount;
            $this->depositPercent = (float) $estimate->deposit_percent;
            $this->notes = $estimate->notes ?? '';
            $this->validUntil = $estimate->valid_until?->format('Y-m-d');
        } else {
            $this->deal = $deal;
        }
    }

    public function updatedProjectType(): void
    {
        $settings = CompanySetting::getInstance();

        $this->ratePerSqft = match ($this->projectType) {
            'interior' => (float) $settings->default_interior_sqft_rate,
            'exterior' => (float) $settings->default_exterior_sqft_rate,
            'both' => (float) $settings->default_interior_sqft_rate,
            default => $this->ratePerSqft,
        };
    }

    public function getSubtotalProperty(): float
    {
        return $this->totalSqft * $this->ratePerSqft;
    }

    public function getTaxAmountProperty(): float
    {
        return $this->subtotal * ($this->taxRate / 100);
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxAmount - $this->discountAmount;
    }

    public function getDepositAmountProperty(): float
    {
        return $this->total * ($this->depositPercent / 100);
    }

    public function getBalanceDueProperty(): float
    {
        return $this->total - $this->depositAmount;
    }

    public function save(bool $markAsSent = false): void
    {
        $this->validate();

        $estimateData = [
            'deal_id' => $this->deal->id,
            'title' => $this->title,
            'estimator_mode' => Estimate::MODE_SIMPLE,
            'project_type' => $this->projectType,
            'simple_sqft' => $this->totalSqft,
            'simple_rate_per_sqft' => $this->ratePerSqft,
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'discount_amount' => $this->discountAmount,
            'total' => $this->total,
            'deposit_percent' => $this->depositPercent,
            'deposit_amount' => $this->depositAmount,
            'balance_due' => $this->balanceDue,
            'notes' => $this->notes ?: null,
            'valid_until' => $this->validUntil ?: null,
        ];

        if ($this->isEditing) {
            $this->estimate->update($estimateData);

            if ($markAsSent && $this->estimate->status === 'draft') {
                $this->estimate->markAsSent();
            }

            // Create amendment if editing a sent estimate
            if ($this->estimate->status === 'sent' && $this->estimate->sent_snapshot) {
                $amendmentService = app(EstimateAmendmentService::class);
                $amendment = $amendmentService->createAmendment($this->estimate, auth()->user());
                if ($amendment) {
                    session()->flash('success', __("Estimate updated. Amendment #:number created — customer will be notified.", ['number' => $amendment->amendment_number]));
                } else {
                    session()->flash('success', __('Estimate updated (no material changes detected).'));
                }
            } else {
                session()->flash('success', __('Estimate updated successfully.'));
            }

            $this->dispatch('estimate-updated');
        } else {
            $estimateData['created_by'] = auth()->id();
            $estimateData['status'] = 'draft'; // Always create as draft first

            $this->estimate = Estimate::create($estimateData);

            // Now mark as sent if requested (this generates the public token)
            if ($markAsSent) {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-created');
            session()->flash('success', __('Estimate created successfully.'));
        }

        $this->redirect(route('estimates.show', $this->estimate), navigate: true);
    }

    public function saveAsDraft(): void
    {
        $this->save(false);
    }

    public function saveAndSend(): void
    {
        $this->save(true);
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                @if ($isEditing)
                    <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate>
                        {{ __('Back') }}
                    </flux:button>
                @else
                    <flux:button variant="ghost" :href="route('deals.show', $deal)" icon="arrow-left" wire:navigate>
                        {{ __('Back to Deal') }}
                    </flux:button>
                @endif
                <flux:heading size="xl">
                    @if ($isEditing)
                        {{ __('Edit Simple Estimate:') }} {{ $estimate->estimate_number }}
                    @else
                        {{ __('Quick Estimate ($/Sq Ft)') }}
                    @endif
                </flux:heading>
            </div>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        <form wire:submit.prevent="saveAsDraft">
            <div class="space-y-6">
                {{-- Deal Information --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Deal Information') }}</flux:heading>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Deal') }}</dt>
                            <dd class="mt-1">
                                <flux:text class="font-medium">{{ $deal->title }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $deal->customer?->full_name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </div>
                </div>

                {{-- Estimate Details --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Estimate Details') }}</flux:heading>
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>{{ __('Title') }}</flux:label>
                            <flux:input wire:model="title" :placeholder="__('e.g., Interior Painting - Whole House')" />
                            <flux:error name="title" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Project Type') }}</flux:label>
                            <div class="flex gap-6 mt-2">
                                @foreach ($this->getTranslatedProjectTypes() as $value => $label)
                                    <flux:radio wire:model.live="projectType" value="{{ $value }}" :label="$label" />
                                @endforeach
                            </div>
                            <flux:error name="projectType" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Valid Until') }}</flux:label>
                            <flux:input wire:model="validUntil" type="date" />
                            <flux:error name="validUntil" />
                        </flux:field>
                    </div>
                </div>

                {{-- Square Footage & Rate --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Pricing') }}</flux:heading>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Total Square Feet') }}</flux:label>
                            <flux:input wire:model.live="totalSqft" type="number" step="0.01" min="1" placeholder="0.00" />
                            <flux:error name="totalSqft" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Rate per Sq Ft ($)') }}</flux:label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">$</span>
                                </div>
                                <flux:input wire:model.live="ratePerSqft" type="number" step="0.01" min="0.01" placeholder="0.00" class="pl-7" />
                            </div>
                            <flux:error name="ratePerSqft" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Tax Rate (%)') }}</flux:label>
                            <div class="relative">
                                <flux:input wire:model.live="taxRate" type="number" step="0.01" min="0" max="100" placeholder="0.00" class="pr-8" />
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">%</span>
                                </div>
                            </div>
                            <flux:error name="taxRate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Discount ($)') }}</flux:label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">$</span>
                                </div>
                                <flux:input wire:model.live="discountAmount" type="number" step="0.01" min="0" placeholder="0.00" class="pl-7" />
                            </div>
                            <flux:error name="discountAmount" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Deposit (%)') }}</flux:label>
                            <div class="relative">
                                <flux:input wire:model.live="depositPercent" type="number" step="0.01" min="0" max="100" placeholder="0.00" class="pr-8" />
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">%</span>
                                </div>
                            </div>
                            <flux:error name="depositPercent" />
                        </flux:field>
                    </div>
                </div>

                {{-- Summary Card --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 bg-zinc-50 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-4">{{ __('Estimate Summary') }}</flux:heading>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ number_format($totalSqft, 0) }} {{ __('sq ft') }} x ${{ number_format($ratePerSqft, 2) }}/{{ __('sq ft') }}
                            </dt>
                            <dd class="font-medium">${{ number_format($this->subtotal, 2) }}</dd>
                        </div>
                        <div class="flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                            <dt>{{ __('Subtotal') }}</dt>
                            <dd>${{ number_format($this->subtotal, 2) }}</dd>
                        </div>
                        @if ($taxRate > 0)
                            <div class="flex justify-between text-sm">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Tax') }} ({{ number_format($taxRate, 2) }}%)</dt>
                                <dd>${{ number_format($this->taxAmount, 2) }}</dd>
                            </div>
                        @endif
                        @if ($discountAmount > 0)
                            <div class="flex justify-between text-sm">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Discount') }}</dt>
                                <dd class="text-red-600 dark:text-red-400">-${{ number_format($discountAmount, 2) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                            <dt class="text-lg font-semibold">{{ __('Total') }}</dt>
                            <dd class="text-xl font-bold">${{ number_format($this->total, 2) }}</dd>
                        </div>
                        <div class="flex justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Deposit') }} ({{ number_format($depositPercent, 0) }}%)</dt>
                            <dd class="font-medium">${{ number_format($this->depositAmount, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Balance Due') }}</dt>
                            <dd class="font-medium">${{ number_format($this->balanceDue, 2) }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- Notes --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Notes') }}</flux:heading>
                    <flux:field>
                        <flux:textarea wire:model="notes" rows="4" :placeholder="__('Additional notes for the customer...')" />
                        <flux:error name="notes" />
                    </flux:field>
                </div>

                {{-- Actions --}}
                <div class="flex justify-end gap-3">
                    @if ($isEditing)
                        <flux:button variant="ghost" :href="route('estimates.show', $estimate)" wire:navigate>
                            {{ __('Cancel') }}
                        </flux:button>
                    @else
                        <flux:button variant="ghost" :href="route('deals.show', $deal)" wire:navigate>
                            {{ __('Cancel') }}
                        </flux:button>
                    @endif
                    <flux:button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAsDraft">{{ __('Save as Draft') }}</span>
                        <span wire:loading wire:target="saveAsDraft">{{ __('Saving...') }}</span>
                    </flux:button>
                    <flux:button wire:click="saveAndSend" type="button" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAndSend">{{ __('Save & Send') }}</span>
                        <span wire:loading wire:target="saveAndSend">{{ __('Sending...') }}</span>
                    </flux:button>
                </div>
            </div>
        </form>
    </div>
</div>
