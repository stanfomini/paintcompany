<div>
    <flux:heading size="sm" class="mb-4">Notes</flux:heading>

    {{-- New Note Form --}}
    <form wire:submit="addNote" class="mb-6">
        <flux:field>
            <flux:textarea
                wire:model="newNoteContent"
                rows="3"
                placeholder="Add a note..."
            />
            <flux:error name="newNoteContent" />
        </flux:field>

        <div class="mt-3 flex items-center justify-between">
            @if ($this->showInternalToggle)
                <div class="flex items-center gap-2">
                    <flux:checkbox wire:model="newNoteIsInternal" label="Internal note" />
                    <flux:tooltip content="Internal notes are only visible to staff members">
                        <flux:icon name="question-mark-circle" class="w-4 h-4 text-zinc-400" />
                    </flux:tooltip>
                </div>
            @else
                <div></div>
            @endif

            <flux:button type="submit" variant="primary" size="sm">
                Add Note
            </flux:button>
        </div>
    </form>

    {{-- Notes List --}}
    @if ($this->notes->isEmpty())
        <div class="text-center py-8 border border-dashed border-zinc-200 dark:border-zinc-700 rounded-lg">
            <flux:icon name="chat-bubble-left-right" class="w-8 h-8 mx-auto text-zinc-400" />
            <flux:text class="mt-2 text-zinc-500">No notes yet. Add the first one above.</flux:text>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->notes as $note)
                <div
                    wire:key="note-{{ $note->id }}"
                    @class([
                        'border rounded-lg p-4',
                        'bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800' => $note->is_internal,
                        'border-zinc-200 dark:border-zinc-700' => !$note->is_internal,
                    ])
                >
                    {{-- Note Header --}}
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-2 flex-wrap">
                            <flux:text class="font-medium">{{ $note->user?->name ?? 'Unknown User' }}</flux:text>
                            @if ($note->is_internal)
                                <flux:badge size="sm" color="amber">Internal</flux:badge>
                            @endif
                            <flux:text class="text-sm text-zinc-500">
                                {{ $note->created_at->format('M j, Y g:i A') }}
                            </flux:text>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="chat-bubble-left"
                                wire:click="startReply({{ $note->id }})"
                                title="Reply"
                            />
                            @if ($note->user_id === auth()->id() || $this->showInternalToggle)
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        @if ($this->showInternalToggle)
                                            <flux:menu.item
                                                wire:click="toggleInternal({{ $note->id }})"
                                                icon="{{ $note->is_internal ? 'eye' : 'eye-slash' }}"
                                            >
                                                {{ $note->is_internal ? 'Make Public' : 'Make Internal' }}
                                            </flux:menu.item>
                                        @endif
                                        <flux:menu.item
                                            wire:click="deleteNote({{ $note->id }})"
                                            wire:confirm="Are you sure you want to delete this note? This will also delete all replies."
                                            icon="trash"
                                            variant="danger"
                                        >
                                            Delete
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>
                    </div>

                    {{-- Note Content --}}
                    <div class="mt-2">
                        <flux:text class="whitespace-pre-wrap">{{ $note->content }}</flux:text>
                    </div>

                    {{-- Replies --}}
                    @if ($note->replies->isNotEmpty())
                        <div class="mt-4 pl-4 border-l-2 border-zinc-200 dark:border-zinc-600 space-y-3">
                            @foreach ($note->replies as $reply)
                                <div
                                    wire:key="reply-{{ $reply->id }}"
                                    @class([
                                        'rounded-lg p-3',
                                        'bg-amber-50 dark:bg-amber-950/20' => $reply->is_internal,
                                        'bg-zinc-50 dark:bg-zinc-800/50' => !$reply->is_internal,
                                    ])
                                >
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <flux:text class="font-medium text-sm">{{ $reply->user?->name ?? 'Unknown User' }}</flux:text>
                                            @if ($reply->is_internal)
                                                <flux:badge size="sm" color="amber">Internal</flux:badge>
                                            @endif
                                            <flux:text class="text-xs text-zinc-500">
                                                {{ $reply->created_at->format('M j, Y g:i A') }}
                                            </flux:text>
                                        </div>
                                        @if ($reply->user_id === auth()->id() || $this->showInternalToggle)
                                            <flux:dropdown>
                                                <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" />
                                                <flux:menu>
                                                    @if ($this->showInternalToggle)
                                                        <flux:menu.item
                                                            wire:click="toggleInternal({{ $reply->id }})"
                                                            icon="{{ $reply->is_internal ? 'eye' : 'eye-slash' }}"
                                                        >
                                                            {{ $reply->is_internal ? 'Make Public' : 'Make Internal' }}
                                                        </flux:menu.item>
                                                    @endif
                                                    <flux:menu.item
                                                        wire:click="deleteNote({{ $reply->id }})"
                                                        wire:confirm="Are you sure you want to delete this reply?"
                                                        icon="trash"
                                                        variant="danger"
                                                    >
                                                        Delete
                                                    </flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        @endif
                                    </div>
                                    <div class="mt-1">
                                        <flux:text class="text-sm whitespace-pre-wrap">{{ $reply->content }}</flux:text>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Reply Form --}}
                    @if ($replyingToId === $note->id)
                        <div class="mt-4 pl-4 border-l-2 border-blue-300 dark:border-blue-600">
                            <form wire:submit="submitReply" class="space-y-3">
                                <flux:field>
                                    <flux:textarea
                                        wire:model="replyContent"
                                        rows="2"
                                        placeholder="Write a reply..."
                                        autofocus
                                    />
                                    <flux:error name="replyContent" />
                                </flux:field>

                                <div class="flex items-center justify-between">
                                    @if ($this->showInternalToggle)
                                        <div class="flex items-center gap-2">
                                            <flux:checkbox wire:model="replyIsInternal" label="Internal reply" />
                                            <flux:tooltip content="Internal replies are only visible to staff members">
                                                <flux:icon name="question-mark-circle" class="w-4 h-4 text-zinc-400" />
                                            </flux:tooltip>
                                        </div>
                                    @else
                                        <div></div>
                                    @endif

                                    <div class="flex gap-2">
                                        <flux:button type="button" variant="ghost" size="sm" wire:click="cancelReply">
                                            Cancel
                                        </flux:button>
                                        <flux:button type="submit" variant="primary" size="sm">
                                            Reply
                                        </flux:button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
