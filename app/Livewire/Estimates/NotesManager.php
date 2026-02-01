<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateNote;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotesManager extends Component
{
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
            'newNoteContent.required' => 'Please enter a note.',
            'newNoteContent.max' => 'Note cannot exceed 5000 characters.',
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
            'replyContent.required' => 'Please enter a reply.',
            'replyContent.max' => 'Reply cannot exceed 5000 characters.',
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

    public function render(): View
    {
        return view('livewire.estimates.notes-manager');
    }
}
