<?php

namespace App\Livewire\Chat;

use App\Models\Deal;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateThread extends Component
{
    public bool $showModal = false;

    public string $type = 'direct'; // 'direct' or 'deal'

    public ?int $selectedUserId = null;

    public ?int $selectedDealId = null;

    public string $subject = '';

    public string $userSearch = '';

    public string $dealSearch = '';

    public function openModal(): void
    {
        $this->reset(['type', 'selectedUserId', 'selectedDealId', 'subject', 'userSearch', 'dealSearch']);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    #[Computed]
    public function availableUsers(): Collection
    {
        $user = auth()->user();
        $chatService = app(ChatService::class);

        $query = User::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($this->userSearch) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->userSearch.'%')
                    ->orWhere('email', 'like', '%'.$this->userSearch.'%');
            });
        }

        // Filter to users the current user can chat with
        return $query->get()->filter(fn (User $target) => $chatService->canChatWith($user, $target)
        )->take(20);
    }

    #[Computed]
    public function availableDeals(): Collection
    {
        $user = auth()->user();

        $query = Deal::query()
            ->with(['customer', 'property'])
            ->whereDoesntHave('chatThread') // Only deals without a chat thread
            ->orderByDesc('created_at');

        // Internal users can see all deals, agents only their referred deals
        if (! $user->isInternal()) {
            $query->where('agent_id', $user->id);
        }

        if ($this->dealSearch) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->dealSearch.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', '%'.$this->dealSearch.'%')
                        ->orWhere('last_name', 'like', '%'.$this->dealSearch.'%'));
            });
        }

        return $query->take(20)->get();
    }

    public function createThread(): void
    {
        $user = auth()->user();
        $chatService = app(ChatService::class);

        if ($this->type === 'deal' && $this->selectedDealId) {
            $deal = Deal::findOrFail($this->selectedDealId);

            // Create deal thread
            $thread = $chatService->getOrCreateDealThread($deal, $user);

            // Add relevant participants
            if ($deal->customer?->user) {
                $thread->addParticipant($deal->customer->user);
            }
            if ($deal->agent) {
                $thread->addParticipant($deal->agent);
            }
            if ($deal->assignedTo) {
                $thread->addParticipant($deal->assignedTo);
            }

            $this->closeModal();
            $this->dispatch('thread-created');
            $this->redirect(route('chat.show', $thread), navigate: true);

            return;
        }

        if ($this->type === 'direct' && $this->selectedUserId) {
            $targetUser = User::findOrFail($this->selectedUserId);

            // Verify permission
            if (! $chatService->canChatWith($user, $targetUser)) {
                $this->addError('selectedUserId', 'You cannot start a chat with this user.');

                return;
            }

            $thread = $chatService->getOrCreateDirectThread(
                $user,
                $targetUser,
                $this->subject ?: null
            );

            $this->closeModal();
            $this->dispatch('thread-created');
            $this->redirect(route('chat.show', $thread), navigate: true);

            return;
        }

        $this->addError('general', 'Please select a user or deal to start a conversation.');
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->type = 'direct';
    }

    public function selectDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
        $this->type = 'deal';
    }

    public function render(): View
    {
        return view('livewire.chat.create-thread');
    }
}
