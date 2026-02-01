<?php

namespace App\Livewire\Public;

use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateSignature;
use App\Models\EstimateView;
use App\Services\EstimateCalculationService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.public')]
class EstimateProposal extends Component
{
    public Estimate $estimate;

    public string $token = '';

    public bool $showSignatureModal = false;

    #[Validate('required|string|max:255')]
    public string $signerName = '';

    #[Validate('required|email|max:255')]
    public string $signerEmail = '';

    #[Validate('required|in:drawn,typed')]
    public string $signatureType = 'typed';

    #[Validate('required|string')]
    public string $signatureData = '';

    #[Validate('accepted')]
    public bool $acceptedTerms = false;

    public function mount(string $token): void
    {
        $estimate = Estimate::byPublicToken($token)
            ->with([
                'deal.customer',
                'items',
                'areas.surfaces.productionRate',
                'options',
                'packages',
                'photos' => fn ($q) => $q->forProposal()->orderBy('sort_order'),
                'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
                'signature',
                'createdBy.internalUser',
            ])
            ->first();

        if (! $estimate) {
            abort(404, 'Proposal not found.');
        }

        if (! $estimate->isPublicTokenValid()) {
            abort(410, 'This proposal link has expired.');
        }

        $this->estimate = $estimate;
        $this->token = $token;

        // Pre-fill signer info from customer if available
        if ($estimate->deal?->customer) {
            $customer = $estimate->deal->customer;
            $this->signerName = trim($customer->first_name.' '.$customer->last_name);
            $this->signerEmail = $customer->email ?? '';
        }

        // Record view
        EstimateView::record($estimate);

        // Log activity
        EstimateActivity::log(
            $estimate,
            'viewed',
            'Proposal viewed via public link',
            ['ip_address' => request()->ip()]
        );
    }

    public function toggleOption(int $optionId): void
    {
        // Cannot modify if already accepted
        if ($this->estimate->status === 'accepted') {
            return;
        }

        $option = $this->estimate->options()->find($optionId);

        if (! $option) {
            return;
        }

        $option->is_selected = ! $option->is_selected;
        $option->save();

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'option_toggled',
            $option->is_selected
                ? "Option '{$option->name}' selected"
                : "Option '{$option->name}' deselected",
            [
                'option_id' => $optionId,
                'option_name' => $option->name,
                'is_selected' => $option->is_selected,
            ]
        );

        // Recalculate totals
        $calculationService = app(EstimateCalculationService::class);
        $calculationService->recalculateEstimateTotals($this->estimate);

        // Refresh the estimate
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'items',
            'areas.surfaces.productionRate',
            'options',
            'packages',
            'photos' => fn ($q) => $q->forProposal()->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
            'createdBy.internalUser',
        ]);
    }

    public function openSignatureModal(): void
    {
        // Validate estimate can be accepted
        if ($this->estimate->status === 'accepted') {
            session()->flash('error', 'This proposal has already been accepted.');

            return;
        }

        if ($this->estimate->status === 'rejected') {
            session()->flash('error', 'This proposal has been rejected and cannot be accepted.');

            return;
        }

        if ($this->estimate->isExpired()) {
            session()->flash('error', 'This proposal has expired and cannot be accepted.');

            return;
        }

        $this->showSignatureModal = true;
    }

    #[On('proceed-to-signature')]
    public function handleProceedToSignature(array $data = []): void
    {
        // This is triggered from the ProposalPackages component
        $this->openSignatureModal();
    }

    public function closeSignatureModal(): void
    {
        $this->showSignatureModal = false;
        $this->reset(['signatureData']);
        $this->resetValidation();
    }

    public function submitSignature(): void
    {
        $this->validate([
            'signerName' => 'required|string|max:255',
            'signerEmail' => 'required|email|max:255',
            'signatureType' => 'required|in:drawn,typed',
            'signatureData' => 'required|string',
            'acceptedTerms' => 'accepted',
        ], [
            'signerName.required' => 'Please enter your name.',
            'signerEmail.required' => 'Please enter your email address.',
            'signerEmail.email' => 'Please enter a valid email address.',
            'signatureData.required' => $this->signatureType === 'drawn'
                ? 'Please draw your signature.'
                : 'Please type your signature.',
            'acceptedTerms.accepted' => 'You must accept the terms and conditions.',
        ]);

        // Create signature (model automatically marks estimate as accepted)
        EstimateSignature::createFromRequest(
            $this->estimate,
            $this->signerName,
            $this->signerEmail,
            $this->signatureData,
            $this->signatureType
        );

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'accepted',
            'Proposal accepted and signed',
            [
                'signer_name' => $this->signerName,
                'signer_email' => $this->signerEmail,
                'signature_type' => $this->signatureType,
            ]
        );

        // Dispatch event for any listeners
        $this->dispatch('estimate-accepted', estimateId: $this->estimate->id);

        // Close modal and refresh
        $this->showSignatureModal = false;

        // Refresh the estimate to show accepted state
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'items',
            'areas.surfaces.productionRate',
            'options',
            'photos' => fn ($q) => $q->forProposal()->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
        ]);

        session()->flash('success', 'Thank you! Your proposal has been accepted.');
    }

    public function getSelectedOptionsTotal(): float
    {
        return (float) $this->estimate->options
            ->where('is_selected', true)
            ->sum('calculated_price');
    }

    public function getGrandTotal(): float
    {
        $subtotal = (float) $this->estimate->items_subtotal
            + (float) $this->estimate->areas_subtotal
            + $this->getSelectedOptionsTotal();

        $taxAmount = $subtotal * ((float) $this->estimate->tax_rate / 100);
        $discountAmount = (float) $this->estimate->discount_amount;

        return $subtotal + $taxAmount - $discountAmount;
    }

    public function canAccept(): bool
    {
        return in_array($this->estimate->status, ['sent', 'draft'])
            && ! $this->estimate->isExpired()
            && ! $this->estimate->hasSignature();
    }

    public function render(): View
    {
        return view('livewire.public.estimate-proposal', [
            'selectedOptionsTotal' => $this->getSelectedOptionsTotal(),
            'grandTotal' => $this->getGrandTotal(),
            'canAccept' => $this->canAccept(),
        ]);
    }
}
