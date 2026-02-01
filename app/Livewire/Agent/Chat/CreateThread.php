<?php

namespace App\Livewire\Agent\Chat;

use App\Models\Deal;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateThread extends Component
{
    public bool $showModal = false;

    public ?int $selectedDealId = null;

    public string $dealSearch = '';

    public function openModal(): void
    {
        $this->reset(['selectedDealId', 'dealSearch']);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    #[Computed]
    public function availableDeals(): Collection
    {
        $user = auth('agent')->user();

        $query = Deal::query()
            ->with(['customer', 'property'])
            ->where('agent_id', $user->id) // Only agent's referred deals
            ->whereDoesntHave('chatThread') // Only deals without a chat thread
            ->orderByDesc('created_at');

        if ($this->dealSearch) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->dealSearch.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', '%'.$this->dealSearch.'%')
                        ->orWhere('last_name', 'like', '%'.$this->dealSearch.'%'));
            });
        }

        return $query->take(20)->get();
    }

    public function selectDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
    }

    public function createThread(): void
    {
        $user = auth('agent')->user();
        $chatService = app(ChatService::class);

        if (! $this->selectedDealId) {
            $this->addError('general', 'Please select a deal to start a conversation.');

            return;
        }

        $deal = Deal::where('agent_id', $user->id)
            ->findOrFail($this->selectedDealId);

        // Create deal thread
        $thread = $chatService->getOrCreateDealThread($deal, $user);

        // Add relevant participants
        if ($deal->customer?->user) {
            $thread->addParticipant($deal->customer->user);
        }
        if ($deal->assignedTo) {
            $thread->addParticipant($deal->assignedTo);
        }

        $this->closeModal();
        $this->dispatch('thread-created');
        $this->redirect(route('agent.chat.show', $thread), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.agent.chat.create-thread');
    }
}
