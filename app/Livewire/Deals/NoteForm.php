<?php

namespace App\Livewire\Deals;

use App\Models\DealNote;
use Livewire\Component;

class NoteForm extends Component
{
    public int $dealId;

    public string $content = '';

    public bool $isInternal = false;

    public ?int $editingNoteId = null;

    public function mount(int $dealId): void
    {
        $this->dealId = $dealId;
    }

    public function saveNote(): void
    {
        $this->validate([
            'content' => ['required', 'string', 'max:10000'],
            'isInternal' => ['boolean'],
        ]);

        if ($this->editingNoteId) {
            $note = DealNote::where('id', $this->editingNoteId)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            $note->update([
                'content' => $this->content,
                'is_internal' => $this->isInternal,
            ]);

            $this->dispatch('note-updated');
            session()->flash('note-success', 'Note updated successfully.');
        } else {
            DealNote::create([
                'deal_id' => $this->dealId,
                'user_id' => auth()->id(),
                'content' => $this->content,
                'is_internal' => $this->isInternal,
            ]);

            $this->dispatch('note-created');
            session()->flash('note-success', 'Note added successfully.');
        }

        $this->resetForm();
    }

    public function editNote(int $noteId): void
    {
        $note = DealNote::where('id', $noteId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $this->editingNoteId = $note->id;
        $this->content = $note->content;
        $this->isInternal = $note->is_internal;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function deleteNote(int $noteId): void
    {
        $note = DealNote::where('id', $noteId)
            ->where('user_id', auth()->id())
            ->first();

        if ($note) {
            $note->delete();
            $this->dispatch('note-deleted');
            session()->flash('note-success', 'Note deleted successfully.');
        }
    }

    protected function resetForm(): void
    {
        $this->reset(['content', 'isInternal', 'editingNoteId']);
        $this->resetValidation();
    }

    public function render()
    {
        $notes = DealNote::where('deal_id', $this->dealId)
            ->with('user')
            ->latest()
            ->get();

        return view('livewire.deals.note-form', [
            'notes' => $notes,
        ]);
    }
}
