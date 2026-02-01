<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateProposalSent;
use App\Notifications\EstimateRejected;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public Estimate $estimate;

    public bool $showSendModal = false;

    public ?string $publicUrl = null;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate->load(['deal.customer', 'createdBy', 'items', 'templateUsages.template', 'packages']);
        $this->publicUrl = $this->estimate->getPublicUrl();
    }

    #[On('estimate-updated')]
    public function refreshEstimate(): void
    {
        $this->estimate = $this->estimate->fresh(['deal.customer', 'createdBy', 'items', 'templateUsages.template', 'packages']);
        $this->publicUrl = $this->estimate->getPublicUrl();
    }

    public function openSendModal(): void
    {
        if ($this->estimate->status !== 'draft') {
            session()->flash('error', 'Only draft estimates can be sent.');

            return;
        }

        $customer = $this->estimate->deal?->customer;
        if (! $customer || ! $customer->email) {
            session()->flash('error', 'Customer email is required to send the proposal.');

            return;
        }

        if (! $this->estimate->hasCreatorSignature()) {
            session()->flash('error', 'You must configure your signature before sending proposals. Go to Settings > Signature to add your signature.');

            return;
        }

        $this->showSendModal = true;
    }

    public function closeSendModal(): void
    {
        $this->showSendModal = false;
    }

    public function sendProposal(): void
    {
        if ($this->estimate->status !== 'draft') {
            session()->flash('error', 'Only draft estimates can be sent.');
            $this->showSendModal = false;

            return;
        }

        $customer = $this->estimate->deal?->customer;
        if (! $customer || ! $customer->email) {
            session()->flash('error', 'Customer email is required to send the proposal.');
            $this->showSendModal = false;

            return;
        }

        if (! $this->estimate->hasCreatorSignature()) {
            session()->flash('error', 'You must configure your signature before sending proposals. Go to Settings > Signature to add your signature.');
            $this->showSendModal = false;

            return;
        }

        $this->estimate->markAsSent();
        $this->publicUrl = $this->estimate->getPublicUrl();

        $customer->notify(new EstimateProposalSent($this->estimate));

        $this->showSendModal = false;
        $this->refreshEstimate();
        session()->flash('success', 'Proposal sent to '.$customer->email);
    }

    public function copyPublicUrl(): void
    {
        if ($this->publicUrl) {
            $this->dispatch('copy-to-clipboard', url: $this->publicUrl);
        }
    }

    public function markAsSent(): void
    {
        if ($this->estimate->status !== 'draft') {
            session()->flash('error', 'Only draft estimates can be marked as sent.');

            return;
        }

        $this->estimate->markAsSent();
        $this->refreshEstimate();
        session()->flash('success', 'Estimate marked as sent.');
    }

    public function markAsAccepted(): void
    {
        if (! in_array($this->estimate->status, ['sent', 'draft'])) {
            session()->flash('error', 'This estimate cannot be marked as accepted.');

            return;
        }

        // Internal staff can force accept without signature
        $this->estimate->markAsAccepted(force: true);
        $this->refreshEstimate();
        session()->flash('success', 'Estimate marked as accepted.');
    }

    public function markAsRejected(?string $reason = null): void
    {
        if (! in_array($this->estimate->status, ['sent', 'draft'])) {
            session()->flash('error', 'This estimate cannot be marked as rejected.');

            return;
        }

        $this->estimate->markAsRejected();

        // Notify all internal users
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new EstimateRejected($this->estimate, $reason));
        }

        $this->refreshEstimate();
        session()->flash('success', 'Estimate marked as rejected.');
    }

    public function getStatusColorProperty(): string
    {
        return match ($this->estimate->status) {
            'draft' => 'zinc',
            'sent' => 'blue',
            'accepted' => 'green',
            'rejected' => 'red',
            'expired' => 'yellow',
            default => 'zinc',
        };
    }

    public function render(): View
    {
        return view('livewire.estimates.show')
            ->layout('layouts.app', ['title' => 'Estimate: '.$this->estimate->estimate_number]);
    }
}
