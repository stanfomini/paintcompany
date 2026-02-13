<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealCollaborator;
use App\Services\DealCollaboratorService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $dealId;

    public bool $showInviteModal = false;

    public string $inviteEmail = '';

    #[Computed]
    public function deal(): ?Deal
    {
        return Deal::with(['customer', 'activeCollaborators.customer'])->find($this->dealId);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return Customer::where('user_id', auth('customer')->id())->first();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return $this->deal && $this->customer && $this->deal->isOwnedBy($this->customer);
    }

    #[Computed]
    public function isCollaborator(): bool
    {
        if (! $this->deal || ! $this->customer) {
            return false;
        }

        return ! $this->isOwner && $this->deal->customerCanAccess($this->customer);
    }

    #[Computed]
    public function collaboration(): ?DealCollaborator
    {
        if (! $this->isCollaborator || ! $this->customer) {
            return null;
        }

        return $this->deal->activeCollaborators()
            ->where('customer_id', $this->customer->id)
            ->first();
    }

    public function invite(): void
    {
        $this->validate([
            'inviteEmail' => 'required|email',
        ]);

        try {
            app(DealCollaboratorService::class)->inviteCollaborator(
                $this->deal,
                auth('customer')->user(),
                $this->inviteEmail,
            );

            $this->reset('inviteEmail', 'showInviteModal');
            unset($this->deal);
            session()->flash('collaborator-success', __('Invitation sent successfully.'));
        } catch (\InvalidArgumentException $e) {
            $this->addError('inviteEmail', $e->getMessage());
        }
    }

    public function revoke(int $collaboratorId): void
    {
        $collaborator = DealCollaborator::where('deal_id', $this->dealId)
            ->where('id', $collaboratorId)
            ->firstOrFail();

        app(DealCollaboratorService::class)->revokeAccess($collaborator);
        unset($this->deal);
        session()->flash('collaborator-success', __('Access revoked.'));
    }

    public function extend(int $collaboratorId): void
    {
        $collaborator = DealCollaborator::where('deal_id', $this->dealId)
            ->where('id', $collaboratorId)
            ->firstOrFail();

        app(DealCollaboratorService::class)->extendAccess($collaborator);
        unset($this->deal);
        session()->flash('collaborator-success', __('Access extended by 30 days.'));
    }
}; ?>

<div>
    @if ($this->isCollaborator && $this->collaboration)
        {{-- Collaborator View: "Shared with you" badge --}}
        <flux:callout variant="info">
            <flux:callout.heading>{{ __('Shared Project') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Shared by :name. Your access expires in :days days.', [
                    'name' => $this->deal->customer->full_name,
                    'days' => $this->collaboration->daysRemaining(),
                ]) }}
            </flux:callout.text>
        </flux:callout>
    @elseif ($this->isOwner)
        {{-- Owner View: Manage collaborators --}}
        @if (session('collaborator-success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('collaborator-success') }}
            </flux:callout>
        @endif

        <flux:card>
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">{{ __('Shared Access') }}</flux:heading>
                <flux:button
                    wire:click="$set('showInviteModal', true)"
                    variant="primary"
                    size="sm"
                    icon="user-plus"
                >
                    {{ __('Share Project') }}
                </flux:button>
            </div>

            @if ($this->deal->activeCollaborators->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($this->deal->activeCollaborators as $collab)
                        <div
                            wire:key="collab-{{ $collab->id }}"
                            class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <flux:avatar size="sm" :initials="substr($collab->customer->first_name, 0, 1) . substr($collab->customer->last_name, 0, 1)" class="bg-zinc-200 dark:bg-zinc-700 shrink-0" />
                                <div class="min-w-0">
                                    <flux:text class="font-medium truncate">{{ $collab->customer->full_name }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate">{{ $collab->customer->email }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <flux:badge size="sm" color="blue">
                                    {{ __(':count days left', ['count' => $collab->daysRemaining()]) }}
                                </flux:badge>
                                <flux:button
                                    wire:click="extend({{ $collab->id }})"
                                    wire:confirm="{{ __('Extend access by 30 more days?') }}"
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-path"
                                />
                                <flux:button
                                    wire:click="revoke({{ $collab->id }})"
                                    wire:confirm="{{ __('Are you sure you want to revoke access?') }}"
                                    variant="ghost"
                                    size="sm"
                                    icon="x-mark"
                                    class="text-red-500 hover:text-red-700"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-4 text-center">
                    <flux:icon name="user-group" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('No one else has access to this project. Invite someone to share it.') }}
                    </flux:text>
                </div>
            @endif
        </flux:card>

        {{-- Invite Modal --}}
        <flux:modal wire:model="showInviteModal" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Share Project') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Invite someone to view and participate in this project for 30 days. They will not be able to sign estimates.') }}
                </flux:text>

                <form wire:submit="invite">
                    <flux:field>
                        <flux:label>{{ __('Email Address') }}</flux:label>
                        <flux:input
                            wire:model="inviteEmail"
                            type="email"
                            placeholder="spouse@example.com"
                        />
                        <flux:error name="inviteEmail" />
                    </flux:field>

                    <div class="flex justify-end gap-2 mt-4">
                        <flux:button wire:click="$set('showInviteModal', false)" variant="ghost">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button type="submit" variant="primary" icon="paper-airplane">
                            {{ __('Send Invitation') }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</div>
