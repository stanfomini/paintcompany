<?php

use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function invitations()
    {
        return app(InvitationService::class)->getPendingInvitations(Auth::user());
    }

    public function cancelInvitation(int $invitationId): void
    {
        $invitation = Invitation::find($invitationId);
        $invitationService = app(InvitationService::class);

        if ($invitation && $invitationService->cancelInvitation($invitation, Auth::user())) {
            session()->flash('success', 'Invitation cancelled.');
            $this->dispatch('invitation-cancelled');
        }
    }

    public function resendInvitation(int $invitationId): void
    {
        $invitation = Invitation::find($invitationId);

        if ($invitation && $invitation->isPending()) {
            $invitation->notify(new \App\Notifications\InvitationNotification($invitation));
            session()->flash('success', "Invitation resent to {$invitation->email}");
        }
    }
}; ?>

<div>
    @if (session('success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('success') }}
        </flux:callout>
    @endif

    @if ($this->invitations->count() > 0)
        <div class="space-y-3">
            <flux:heading size="sm">Pending Invitations</flux:heading>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                @foreach ($this->invitations as $invitation)
                    <div wire:key="invitation-{{ $invitation->id }}" class="p-4 flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <flux:text class="font-medium truncate">{{ $invitation->email }}</flux:text>
                            <div class="flex items-center gap-2 mt-1">
                                <flux:badge size="sm" variant="outline">
                                    {{ ucfirst($invitation->invited_user_type === 'internal' ? 'Team Member' : $invitation->invited_user_type) }}
                                </flux:badge>
                                <flux:text size="sm" class="text-zinc-500">
                                    Expires {{ $invitation->expires_at->diffForHumans() }}
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            <flux:button
                                wire:click="resendInvitation({{ $invitation->id }})"
                                wire:loading.attr="disabled"
                                variant="ghost"
                                size="sm"
                            >
                                <flux:icon name="arrow-path" class="w-4 h-4" />
                            </flux:button>
                            <flux:button
                                wire:click="cancelInvitation({{ $invitation->id }})"
                                wire:loading.attr="disabled"
                                wire:confirm="Are you sure you want to cancel this invitation?"
                                variant="ghost"
                                size="sm"
                                class="text-red-500 hover:text-red-700"
                            >
                                <flux:icon name="x-mark" class="w-4 h-4" />
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <flux:text class="text-zinc-500 text-center py-4">
            No pending invitations.
        </flux:text>
    @endif
</div>
