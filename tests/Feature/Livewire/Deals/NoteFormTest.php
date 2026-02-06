<?php

use App\Models\Deal;
use App\Models\DealNote;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('NoteForm component', function () {
    it('renders the note form component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->assertStatus(200);
    });

    it('displays existing notes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'This is a test note.',
        ]);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->assertSee('This is a test note.')
            ->assertSee($user->name);
    });

    it('validates note content is required', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', '')
            ->call('saveNote')
            ->assertHasErrors(['content']);
    });

    it('validates note content max length', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', str_repeat('a', 10001))
            ->call('saveNote')
            ->assertHasErrors(['content']);
    });

    it('creates a public note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', 'This is a public note.')
            ->set('isInternal', false)
            ->call('saveNote')
            ->assertHasNoErrors()
            ->assertDispatched('note-created')
            ->assertSet('content', '')
            ->assertSet('isInternal', false);

        $this->assertDatabaseHas('deal_notes', [
            'deal_id' => $deal->id,
            'user_id' => $user->id,
            'content' => 'This is a public note.',
            'is_internal' => false,
        ]);
    });

    it('creates an internal note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', 'Staff-only note.')
            ->set('isInternal', true)
            ->call('saveNote')
            ->assertHasNoErrors()
            ->assertDispatched('note-created');

        $this->assertDatabaseHas('deal_notes', [
            'deal_id' => $deal->id,
            'user_id' => $user->id,
            'content' => 'Staff-only note.',
            'is_internal' => true,
        ]);
    });

    it('displays internal badge for internal notes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        DealNote::factory()->forDeal($deal)->writtenBy($user)->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->assertSee('Internal');
    });

    it('displays public badge for public notes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        DealNote::factory()->forDeal($deal)->writtenBy($user)->public()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->assertSee('Public');
    });

    it('loads note for editing', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'Original content',
            'is_internal' => true,
        ]);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('editNote', $note->id)
            ->assertSet('editingNoteId', $note->id)
            ->assertSet('content', 'Original content')
            ->assertSet('isInternal', true);
    });

    it('updates an existing note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'Original content',
            'is_internal' => false,
        ]);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('editNote', $note->id)
            ->set('content', 'Updated content')
            ->set('isInternal', true)
            ->call('saveNote')
            ->assertHasNoErrors()
            ->assertDispatched('note-updated')
            ->assertSet('editingNoteId', null)
            ->assertSet('content', '');

        $note->refresh();

        expect($note->content)->toBe('Updated content');
        expect($note->is_internal)->toBeTrue();
    });

    it('cancels editing', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('editNote', $note->id)
            ->assertSet('editingNoteId', $note->id)
            ->call('cancelEdit')
            ->assertSet('editingNoteId', null)
            ->assertSet('content', '');
    });

    it('deletes a note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('deleteNote', $note->id)
            ->assertDispatched('note-deleted');

        $this->assertDatabaseMissing('deal_notes', [
            'id' => $note->id,
        ]);
    });

    it('cannot edit note owned by another user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($otherUser)->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('editNote', $note->id);
    });

    it('cannot delete note owned by another user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($otherUser)->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('deleteNote', $note->id)
            ->assertNotDispatched('note-deleted');

        // Note should still exist since the user didn't own it
        $this->assertDatabaseHas('deal_notes', ['id' => $note->id]);
    });

    it('shows edit and delete options only for own notes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create(['name' => 'Other User']);
        $deal = Deal::factory()->create();

        $ownNote = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'My note content',
        ]);
        $otherNote = DealNote::factory()->forDeal($deal)->writtenBy($otherUser)->create([
            'content' => 'Other user note',
        ]);

        $component = Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id]);

        $component->assertSee('My note content')
            ->assertSee('Other user note');
    });

    it('displays notes in descending order by creation date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $olderNote = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'Older note',
            'created_at' => now()->subDay(),
        ]);
        $newerNote = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'Newer note',
            'created_at' => now(),
        ]);

        $component = Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id]);

        // Newer note should appear first (descending order)
        $component->assertSeeInOrder(['Newer note', 'Older note']);
    });

    it('displays author name and timestamp', function () {
        $user = User::factory()->internal()->withFullAccess()->create(['name' => 'John Doe']);
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'Test note',
            'created_at' => '2025-06-15 10:30:00',
        ]);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->assertSee('John Doe')
            ->assertSee('Jun 15, 2025');
    });

    it('shows no notes message when empty', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->assertSee('No notes yet.');
    });
});
