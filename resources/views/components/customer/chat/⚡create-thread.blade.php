<?php

use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $showModal = false;

    public ?int $selectedUserId = null;

    public string $subject = '';

    public string $userSearch = '';

    public function openModal(): void
    {
        $this->reset(['selectedUserId', 'subject', 'userSearch']);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    #[Computed]
    public function availableUsers(): Collection
    {
        $user = auth('customer')->user();
        $chatService = app(ChatService::class);

        // Customers can message internal users
        $query = User::query()
            ->where('id', '!=', $user->id)
            ->where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name');

        if ($this->userSearch) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->userSearch.'%')
                    ->orWhere('email', 'like', '%'.$this->userSearch.'%');
            });
        }

        // Filter to users the current user can chat with
        return $query->get()->filter(
            fn (User $target) => $chatService->canChatWith($user, $target)
        )->take(20);
    }

    public function createThread(): void
    {
        $user = auth('customer')->user();
        $chatService = app(ChatService::class);

        if (! $this->selectedUserId) {
            $this->addError('selectedUserId', __('Please select a team member to message.'));

            return;
        }

        $targetUser = User::findOrFail($this->selectedUserId);

        // Verify permission
        if (! $chatService->canChatWith($user, $targetUser)) {
            $this->addError('selectedUserId', __('You cannot start a chat with this user.'));

            return;
        }

        $thread = $chatService->getOrCreateDirectThread(
            $user,
            $targetUser,
            $this->subject ?: null
        );

        $this->closeModal();
        $this->dispatch('thread-created');
        $this->redirect(route('customer.chat.show', $thread), navigate: true);
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
    }
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        {{ __('New Message') }}
    </flux:button>

    <flux:modal wire:model="showModal" name="create-thread" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Message the Team') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Start a direct conversation with a team member.') }}</flux:text>
            </div>

            @error('general')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            {{-- User Selection --}}
            <div class="space-y-4">
                <flux:input
                    wire:model.live.debounce.300ms="userSearch"
                    placeholder="{{ __('Search team members...') }}"
                    icon="magnifying-glass"
                />

                @if ($selectedUserId)
                    @php $selectedUser = \App\Models\User::find($selectedUserId); @endphp
                    @if ($selectedUser)
                        <div class="flex items-center justify-between p-3 border border-teal-500 bg-teal-50 dark:bg-teal-900/20 rounded-lg">
                            <div class="flex items-center gap-3">
                                <flux:avatar name="{{ $selectedUser->name }}" size="sm" />
                                <div>
                                    <flux:text class="font-medium">{{ $selectedUser->name }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $selectedUser->email }}</flux:text>
                                </div>
                            </div>
                            <flux:button wire:click="$set('selectedUserId', null)" variant="ghost" size="sm" icon="x-mark" />
                        </div>
                    @endif
                @else
                    <div class="max-h-64 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->availableUsers as $user)
                            <button
                                wire:key="user-{{ $user->id }}"
                                wire:click="selectUser({{ $user->id }})"
                                class="w-full flex items-center gap-3 p-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-left transition-colors"
                            >
                                <flux:avatar name="{{ $user->name }}" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <flux:text class="font-medium truncate">{{ $user->name }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500 truncate">{{ $user->email }}</flux:text>
                                </div>
                            </button>
                        @empty
                            <div class="p-4 text-center">
                                <flux:text class="text-zinc-500">
                                    @if ($userSearch)
                                        {{ __('No team members found matching ":search"', ['search' => $userSearch]) }}
                                    @else
                                        {{ __('No team members available') }}
                                    @endif
                                </flux:text>
                            </div>
                        @endforelse
                    </div>
                @endif

                @if ($selectedUserId)
                    <flux:input
                        wire:model="subject"
                        label="{{ __('Subject (optional)') }}"
                        placeholder="{{ __('What\'s this about?') }}"
                    />
                @endif

                @error('selectedUserId')
                    <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                @enderror
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeModal" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button
                    wire:click="createThread"
                    variant="primary"
                    :disabled="!$selectedUserId"
                >
                    {{ __('Start Conversation') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
