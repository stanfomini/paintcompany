<div>
    <flux:button wire:click="openModal" icon="plus">
        New Conversation
    </flux:button>

    <flux:modal wire:model="showModal" name="create-thread" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Start a Conversation</flux:heading>
                <flux:text class="mt-1 text-zinc-500">Choose who you'd like to chat with.</flux:text>
            </div>

            @error('general')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            {{-- Type Selection --}}
            <div class="flex gap-2">
                <flux:button
                    wire:click="$set('type', 'direct')"
                    :variant="$type === 'direct' ? 'primary' : 'ghost'"
                    size="sm"
                >
                    Direct Message
                </flux:button>
                <flux:button
                    wire:click="$set('type', 'deal')"
                    :variant="$type === 'deal' ? 'primary' : 'ghost'"
                    size="sm"
                >
                    Deal Chat
                </flux:button>
            </div>

            @if ($type === 'direct')
                {{-- User Selection --}}
                <div class="space-y-4">
                    <flux:input
                        wire:model.live.debounce.300ms="userSearch"
                        placeholder="Search users by name or email..."
                        icon="magnifying-glass"
                    />

                    @if ($selectedUserId)
                        @php $selectedUser = \App\Models\User::find($selectedUserId); @endphp
                        @if ($selectedUser)
                            <div class="flex items-center justify-between p-3 border border-blue-500 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
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
                                        <flux:text class="text-sm text-zinc-500 truncate">
                                            {{ $user->email }}
                                            <flux:badge size="sm" class="ml-2">{{ ucfirst($user->type) }}</flux:badge>
                                        </flux:text>
                                    </div>
                                </button>
                            @empty
                                <div class="p-4 text-center">
                                    <flux:text class="text-zinc-500">
                                        @if ($userSearch)
                                            No users found matching "{{ $userSearch }}"
                                        @else
                                            No users available to chat with
                                        @endif
                                    </flux:text>
                                </div>
                            @endforelse
                        </div>
                    @endif

                    @if ($selectedUserId)
                        <flux:input
                            wire:model="subject"
                            label="Subject (optional)"
                            placeholder="What's this about?"
                        />
                    @endif

                    @error('selectedUserId')
                        <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                    @enderror
                </div>
            @else
                {{-- Deal Selection --}}
                <div class="space-y-4">
                    <flux:input
                        wire:model.live.debounce.300ms="dealSearch"
                        placeholder="Search deals..."
                        icon="magnifying-glass"
                    />

                    @if ($selectedDealId)
                        @php $selectedDeal = \App\Models\Deal::with('customer')->find($selectedDealId); @endphp
                        @if ($selectedDeal)
                            <div class="flex items-center justify-between p-3 border border-blue-500 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                <div>
                                    <flux:text class="font-medium">{{ $selectedDeal->title }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">
                                        {{ $selectedDeal->customer?->full_name ?? 'No customer' }}
                                    </flux:text>
                                </div>
                                <flux:button wire:click="$set('selectedDealId', null)" variant="ghost" size="sm" icon="x-mark" />
                            </div>
                        @endif
                    @else
                        <div class="max-h-64 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                            @forelse ($this->availableDeals as $deal)
                                <button
                                    wire:key="deal-{{ $deal->id }}"
                                    wire:click="selectDeal({{ $deal->id }})"
                                    class="w-full flex items-center justify-between p-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-left transition-colors"
                                >
                                    <div class="min-w-0 flex-1">
                                        <flux:text class="font-medium truncate">{{ $deal->title }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500 truncate">
                                            {{ $deal->customer?->full_name ?? 'No customer' }}
                                        </flux:text>
                                    </div>
                                    <flux:badge size="sm" color="blue">{{ $deal->stage_label }}</flux:badge>
                                </button>
                            @empty
                                <div class="p-4 text-center">
                                    <flux:text class="text-zinc-500">
                                        @if ($dealSearch)
                                            No deals found matching "{{ $dealSearch }}"
                                        @else
                                            No deals available without a chat thread
                                        @endif
                                    </flux:text>
                                </div>
                            @endforelse
                        </div>
                    @endif
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button
                    wire:click="createThread"
                    variant="primary"
                    :disabled="($type === 'direct' && !$selectedUserId) || ($type === 'deal' && !$selectedDealId)"
                >
                    Start Conversation
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
