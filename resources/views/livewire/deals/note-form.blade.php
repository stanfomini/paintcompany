<div>
    @if (session('note-success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('note-success') }}
        </flux:callout>
    @endif

    <form wire:submit="saveNote" class="mb-6">
        <flux:field>
            <flux:textarea
                wire:model="content"
                rows="3"
                placeholder="Add a note..."
            />
            <flux:error name="content" />
        </flux:field>

        <div class="mt-3 flex items-center justify-between">
            <flux:checkbox wire:model="isInternal" label="Internal note (staff-only)" />

            <div class="flex gap-2">
                @if ($editingNoteId)
                    <flux:button variant="ghost" wire:click="cancelEdit" type="button">
                        Cancel
                    </flux:button>
                @endif
                <flux:button type="submit" variant="primary" size="sm">
                    {{ $editingNoteId ? 'Update Note' : 'Add Note' }}
                </flux:button>
            </div>
        </div>
    </form>

    @if ($notes->isEmpty())
        <flux:text class="text-zinc-500">No notes yet.</flux:text>
    @else
        <div class="space-y-4">
            @foreach ($notes as $note)
                <div wire:key="note-{{ $note->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-2">
                            <flux:text class="font-medium">{{ $note->user->name }}</flux:text>
                            @if ($note->is_internal)
                                <flux:badge size="sm" color="yellow">Internal</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">Public</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:text class="text-sm text-zinc-500">
                                {{ $note->created_at->format('M j, Y g:i A') }}
                            </flux:text>
                            @if ($note->user_id === auth()->id())
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="editNote({{ $note->id }})" icon="pencil">
                                            Edit
                                        </flux:menu.item>
                                        <flux:menu.item
                                            wire:click="deleteNote({{ $note->id }})"
                                            wire:confirm="Are you sure you want to delete this note?"
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
                    <div class="mt-2">
                        <flux:text class="whitespace-pre-wrap">{{ $note->content }}</flux:text>
                    </div>
                    @if ($note->updated_at->gt($note->created_at))
                        <div class="mt-2">
                            <flux:text class="text-xs text-zinc-400">
                                Edited {{ $note->updated_at->format('M j, Y g:i A') }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
