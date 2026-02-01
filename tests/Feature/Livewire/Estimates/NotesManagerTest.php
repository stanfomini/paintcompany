<?php

use App\Livewire\Estimates\NotesManager;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateNote;
use App\Models\User;
use Livewire\Livewire;

describe('NotesManager component', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->internalUser->id,
        ]);
    });

    it('renders for internal user', function () {
        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->assertStatus(200);
    });

    it('can add note', function () {
        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->set('newNoteContent', 'This is a test note.')
            ->call('addNote')
            ->assertHasNoErrors()
            ->assertSet('newNoteContent', '')
            ->assertDispatched('notes-updated');

        $note = EstimateNote::where('estimate_id', $this->estimate->id)
            ->where('content', 'This is a test note.')
            ->first();

        expect($note)->not->toBeNull();
        expect($note->user_id)->toBe($this->internalUser->id);
        expect($note->is_internal)->toBeFalse();
        expect($note->parent_id)->toBeNull();
    });

    it('can add internal note for internal users', function () {
        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->set('newNoteContent', 'This is an internal note.')
            ->set('newNoteIsInternal', true)
            ->call('addNote')
            ->assertHasNoErrors();

        $note = EstimateNote::where('estimate_id', $this->estimate->id)
            ->where('content', 'This is an internal note.')
            ->first();

        expect($note)->not->toBeNull();
        expect($note->is_internal)->toBeTrue();
    });

    it('non-internal users cannot add internal notes', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->set('newNoteContent', 'Customer note.')
            ->set('newNoteIsInternal', true)
            ->call('addNote');

        $note = EstimateNote::where('estimate_id', $this->estimate->id)
            ->where('content', 'Customer note.')
            ->first();

        expect($note)->not->toBeNull();
        // Non-internal users cannot set internal flag even if they try
        expect($note->is_internal)->toBeFalse();
    });

    it('can reply to note', function () {
        $parentNote = EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Parent note',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('startReply', $parentNote->id)
            ->assertSet('replyingToId', $parentNote->id)
            ->set('replyContent', 'This is a reply.')
            ->call('submitReply')
            ->assertHasNoErrors()
            ->assertSet('replyingToId', null)
            ->assertSet('replyContent', '')
            ->assertDispatched('notes-updated');

        $reply = EstimateNote::where('parent_id', $parentNote->id)->first();
        expect($reply)->not->toBeNull();
        expect($reply->content)->toBe('This is a reply.');
        expect($reply->estimate_id)->toBe($this->estimate->id);
    });

    it('can delete own note', function () {
        $note = EstimateNote::factory()->forEstimate($this->estimate)->create([
            'user_id' => $this->internalUser->id,
            'content' => 'To be deleted',
        ]);

        expect(EstimateNote::where('id', $note->id)->exists())->toBeTrue();

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('deleteNote', $note->id)
            ->assertDispatched('notes-updated');

        expect(EstimateNote::where('id', $note->id)->exists())->toBeFalse();
    });

    it('internal users can delete any note', function () {
        $otherUser = User::factory()->customer()->create();
        $note = EstimateNote::factory()->forEstimate($this->estimate)->create([
            'user_id' => $otherUser->id,
            'content' => 'Other user note',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('deleteNote', $note->id)
            ->assertDispatched('notes-updated');

        expect(EstimateNote::where('id', $note->id)->exists())->toBeFalse();
    });

    it('non-internal users cannot delete others notes', function () {
        $customerUser = User::factory()->customer()->create();
        $note = EstimateNote::factory()->forEstimate($this->estimate)->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Internal user note',
        ]);

        Livewire::actingAs($customerUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('deleteNote', $note->id);

        // Note should still exist since customer cannot delete internal user's note
        expect(EstimateNote::where('id', $note->id)->exists())->toBeTrue();
    });

    it('internal notes not visible to non-internal users', function () {
        $customerUser = User::factory()->customer()->create();

        // Create internal and external notes
        EstimateNote::factory()->forEstimate($this->estimate)->internal()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Internal only note',
        ]);
        EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Public note',
        ]);

        $component = Livewire::actingAs($customerUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate]);

        // Access the computed property through the instance
        $notes = $component->instance()->notes;
        expect($notes)->toHaveCount(1);
        expect($notes->first()->content)->toBe('Public note');
    });

    it('internal users can see all notes', function () {
        EstimateNote::factory()->forEstimate($this->estimate)->internal()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Internal note',
        ]);
        EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'External note',
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate]);

        $notes = $component->instance()->notes;
        expect($notes)->toHaveCount(2);
    });

    it('notes are threaded properly', function () {
        $parentNote = EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Parent note',
            'parent_id' => null,
        ]);

        EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Reply 1',
            'parent_id' => $parentNote->id,
        ]);

        EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
            'content' => 'Reply 2',
            'parent_id' => $parentNote->id,
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate]);

        $notes = $component->instance()->notes;
        // Only root notes are returned in the main collection
        expect($notes)->toHaveCount(1);

        // Replies should be loaded via relationship
        $rootNote = $notes->first();
        expect($rootNote->replies)->toHaveCount(2);
    });

    it('validates note content is required', function () {
        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->set('newNoteContent', '')
            ->call('addNote')
            ->assertHasErrors(['newNoteContent']);
    });

    it('validates reply content is required', function () {
        $parentNote = EstimateNote::factory()->forEstimate($this->estimate)->create([
            'user_id' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('startReply', $parentNote->id)
            ->set('replyContent', '')
            ->call('submitReply')
            ->assertHasErrors(['replyContent']);
    });

    it('validates note content max length', function () {
        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->set('newNoteContent', str_repeat('a', 5001))
            ->call('addNote')
            ->assertHasErrors(['newNoteContent']);
    });

    it('can cancel reply', function () {
        $parentNote = EstimateNote::factory()->forEstimate($this->estimate)->create([
            'user_id' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('startReply', $parentNote->id)
            ->assertSet('replyingToId', $parentNote->id)
            ->set('replyContent', 'Draft reply')
            ->call('cancelReply')
            ->assertSet('replyingToId', null)
            ->assertSet('replyContent', '');
    });

    it('internal user can toggle note internal flag', function () {
        $note = EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('toggleInternal', $note->id)
            ->assertDispatched('notes-updated');

        $note->refresh();
        expect($note->is_internal)->toBeTrue();
    });

    it('non-internal user cannot toggle internal flag', function () {
        $customerUser = User::factory()->customer()->create();
        $note = EstimateNote::factory()->forEstimate($this->estimate)->external()->create([
            'user_id' => $customerUser->id,
        ]);

        Livewire::actingAs($customerUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('toggleInternal', $note->id);

        $note->refresh();
        expect($note->is_internal)->toBeFalse();
    });

    it('cannot delete note from another estimate', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherNote = EstimateNote::factory()->forEstimate($otherEstimate)->create([
            'user_id' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate])
            ->call('deleteNote', $otherNote->id);

        expect(EstimateNote::where('id', $otherNote->id)->exists())->toBeTrue();
    });

    it('shows internal toggle for internal users', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate]);

        expect($component->instance()->showInternalToggle)->toBeTrue();
    });

    it('hides internal toggle for non-internal users', function () {
        $customerUser = User::factory()->customer()->create();

        $component = Livewire::actingAs($customerUser)
            ->test(NotesManager::class, ['estimate' => $this->estimate]);

        expect($component->instance()->showInternalToggle)->toBeFalse();
    });
});
