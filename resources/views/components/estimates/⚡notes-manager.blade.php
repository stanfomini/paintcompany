<?php

use App\Models\Estimate;
use App\Models\EstimateNote;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    public string $newNoteContent = '';

    public bool $newNoteIsInternal = false;

    public ?int $replyingToId = null;

    public string $replyContent = '';

    public bool $replyIsInternal = false;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    /**
     * Get root notes with their replies eager loaded.
     *
     * @return Collection<int, EstimateNote>
     */
    #[Computed]
    public function notes(): Collection
    {
        $query = EstimateNote::where('estimate_id', $this->estimate->id)
            ->rootNotes()
            ->with(['user', 'replies.user'])
            ->latest();

        // Non-internal users can only see external notes
        if (! $this->showInternalToggle) {
            $query->external();
        }

        return $query->get()->map(function (EstimateNote $note) {
            // Filter replies for non-internal users
            if (! $this->showInternalToggle) {
                $note->setRelation('replies', $note->replies->filter(fn ($reply) => ! $reply->is_internal));
            }

            return $note;
        });
    }

    /**
     * Whether to show the internal toggle (only for internal users).
     */
    #[Computed]
    public function showInternalToggle(): bool
    {
        return auth()->user()?->isInternal() ?? false;
    }

    /**
     * Add a new root note.
     */
    public function addNote(): void
    {
        $this->validate([
            'newNoteContent' => ['required', 'string', 'max:5000'],
        ], [
            'newNoteContent.required' => __('Please enter a note.'),
            'newNoteContent.max' => __('Note cannot exceed 5000 characters.'),
        ]);

        // Only internal users can create internal notes
        $isInternal = $this->showInternalToggle && $this->newNoteIsInternal;

        EstimateNote::createFromRequest(
            estimate: $this->estimate,
            content: $this->newNoteContent,
            isInternal: $isInternal
        );

        $this->reset(['newNoteContent', 'newNoteIsInternal']);
        $this->dispatch('notes-updated');

        unset($this->notes);
    }

    /**
     * Start replying to a note.
     */
    public function startReply(int $noteId): void
    {
        $this->replyingToId = $noteId;
        $this->replyContent = '';
        $this->replyIsInternal = false;
    }

    /**
     * Cancel the current reply.
     */
    public function cancelReply(): void
    {
        $this->reset(['replyingToId', 'replyContent', 'replyIsInternal']);
        $this->resetValidation('replyContent');
    }

    /**
     * Submit a reply to a note.
     */
    public function submitReply(): void
    {
        $this->validate([
            'replyContent' => ['required', 'string', 'max:5000'],
        ], [
            'replyContent.required' => __('Please enter a reply.'),
            'replyContent.max' => __('Reply cannot exceed 5000 characters.'),
        ]);

        // Verify the parent note exists and belongs to this estimate
        $parentNote = EstimateNote::where('id', $this->replyingToId)
            ->where('estimate_id', $this->estimate->id)
            ->firstOrFail();

        // Only internal users can create internal notes
        $isInternal = $this->showInternalToggle && $this->replyIsInternal;

        EstimateNote::create([
            'estimate_id' => $this->estimate->id,
            'user_id' => auth()->id(),
            'parent_id' => $parentNote->id,
            'content' => $this->replyContent,
            'is_internal' => $isInternal,
            'ip_address' => request()->ip(),
        ]);

        $this->reset(['replyingToId', 'replyContent', 'replyIsInternal']);
        $this->dispatch('notes-updated');

        unset($this->notes);
    }

    /**
     * Delete a note (cascades to replies via database constraint).
     */
    public function deleteNote(int $id): void
    {
        $note = EstimateNote::where('id', $id)
            ->where('estimate_id', $this->estimate->id)
            ->first();

        if (! $note) {
            return;
        }

        // Authorization: user can only delete their own notes, or internal users can delete any
        if ($note->user_id !== auth()->id() && ! $this->showInternalToggle) {
            return;
        }

        $note->delete();
        $this->dispatch('notes-updated');

        unset($this->notes);
    }

    /**
     * Toggle the internal flag on a note (internal users only).
     */
    public function toggleInternal(int $id): void
    {
        if (! $this->showInternalToggle) {
            return;
        }

        $note = EstimateNote::where('id', $id)
            ->where('estimate_id', $this->estimate->id)
            ->first();

        if (! $note) {
            return;
        }

        $note->update(['is_internal' => ! $note->is_internal]);
        $this->dispatch('notes-updated');

        unset($this->notes);
    }
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">{{ __('Notes') }}</flux:heading>

    {{-- New Note Form --}}
    <form wire:submit="addNote" class="mb-6">
        <flux:field>
            <flux:textarea
                wire:model="newNoteContent"
                rows="3"
                placeholder="{{ __('Add a note...') }}"
            />
            <flux:error name="newNoteContent" />
        </flux:field>

        <div class="mt-3 flex items-center justify-between">
            @if ($this->showInternalToggle)
                <div class="flex items-center gap-2">
                    <flux:checkbox wire:model="newNoteIsInternal" :label="__('Internal note')" />
                    <flux:tooltip :content="__('Internal notes are only visible to staff members')">
                        <flux:icon name="question-mark-circle" class="w-4 h-4 text-zinc-400" />
                    </flux:tooltip>
                </div>
            @else
                <div></div>
            @endif

            <flux:button type="submit" variant="primary" size="sm">
                {{ __('Add Note') }}
            </flux:button>
        </div>
    </form>

    {{-- Notes List --}}
    @if ($this->notes->isEmpty())
        <div class="text-center py-8 border border-dashed border-zinc-200 dark:border-zinc-700 rounded-lg">
            <flux:icon name="chat-bubble-left-right" class="w-8 h-8 mx-auto text-zinc-400" />
            <flux:text class="mt-2 text-zinc-500">{{ __('No notes yet. Add the first one above.') }}</flux:text>
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
                            <flux:text class="font-medium">{{ $note->user?->name ?? __('Unknown User') }}</flux:text>
                            @if ($note->is_internal)
                                <flux:badge size="sm" color="amber">{{ __('Internal') }}</flux:badge>
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
                                                {{ $note->is_internal ? __('Make Public') : __('Make Internal') }}
                                            </flux:menu.item>
                                        @endif
                                        <flux:menu.item
                                            wire:click="deleteNote({{ $note->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this note? This will also delete all replies.') }}"
                                            icon="trash"
                                            variant="danger"
                                        >
                                            {{ __('Delete') }}
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
                                            <flux:text class="font-medium text-sm">{{ $reply->user?->name ?? __('Unknown User') }}</flux:text>
                                            @if ($reply->is_internal)
                                                <flux:badge size="sm" color="amber">{{ __('Internal') }}</flux:badge>
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
                                                            {{ $reply->is_internal ? __('Make Public') : __('Make Internal') }}
                                                        </flux:menu.item>
                                                    @endif
                                                    <flux:menu.item
                                                        wire:click="deleteNote({{ $reply->id }})"
                                                        wire:confirm="{{ __('Are you sure you want to delete this reply?') }}"
                                                        icon="trash"
                                                        variant="danger"
                                                    >
                                                        {{ __('Delete') }}
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
                                        placeholder="{{ __('Write a reply...') }}"
                                        autofocus
                                    />
                                    <flux:error name="replyContent" />
                                </flux:field>

                                <div class="flex items-center justify-between">
                                    @if ($this->showInternalToggle)
                                        <div class="flex items-center gap-2">
                                            <flux:checkbox wire:model="replyIsInternal" :label="__('Internal reply')" />
                                            <flux:tooltip :content="__('Internal replies are only visible to staff members')">
                                                <flux:icon name="question-mark-circle" class="w-4 h-4 text-zinc-400" />
                                            </flux:tooltip>
                                        </div>
                                    @else
                                        <div></div>
                                    @endif

                                    <div class="flex gap-2">
                                        <flux:button type="button" variant="ghost" size="sm" wire:click="cancelReply">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                        <flux:button type="submit" variant="primary" size="sm">
                                            {{ __('Reply') }}
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
