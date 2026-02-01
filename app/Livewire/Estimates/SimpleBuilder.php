<?php

namespace App\Livewire\Estimates;

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SimpleBuilder extends Component
{
    public ?Deal $deal = null;

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
            'title.required' => 'Please enter a title for the estimate.',
            'totalSqft.required' => 'Please enter the total square footage.',
            'totalSqft.min' => 'Square footage must be at least 1.',
            'ratePerSqft.required' => 'Please enter the rate per square foot.',
            'ratePerSqft.min' => 'Rate must be greater than 0.',
        ];
    }

    public function mount(?Deal $deal = null, ?Estimate $estimate = null): void
    {
        $settings = CompanySetting::getInstance();

        // Load defaults from company settings
        $this->taxRate = (float) ($settings->default_tax_rate ?? 0);
        $this->depositPercent = (float) ($settings->default_deposit_percent ?? 30);
        $this->ratePerSqft = (float) ($settings->default_interior_sqft_rate ?? 4.50);

        // Set default valid until based on company settings
        $validityDays = $settings->estimate_validity_days ?? 30;
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
            'interior' => (float) ($settings->default_interior_sqft_rate ?? 4.50),
            'exterior' => (float) ($settings->default_exterior_sqft_rate ?? 5.50),
            'both' => (float) ($settings->default_interior_sqft_rate ?? 4.50),
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

            $this->dispatch('estimate-updated');
            session()->flash('success', 'Estimate updated successfully.');
        } else {
            $estimateData['created_by'] = auth()->id();
            $estimateData['status'] = 'draft'; // Always create as draft first

            $this->estimate = Estimate::create($estimateData);

            // Now mark as sent if requested (this generates the public token)
            if ($markAsSent) {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-created');
            session()->flash('success', 'Estimate created successfully.');
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

    public function render(): View
    {
        $pageTitle = $this->isEditing
            ? 'Edit Simple Estimate: '.$this->estimate->estimate_number
            : 'Create Simple Estimate';

        return view('livewire.estimates.simple-builder', [
            'projectTypes' => [
                'interior' => 'Interior',
                'exterior' => 'Exterior',
                'both' => 'Interior & Exterior',
            ],
        ])->layout('layouts.app', ['title' => $pageTitle]);
    }
}
