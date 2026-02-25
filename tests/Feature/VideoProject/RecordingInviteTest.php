<?php

use App\Models\RecordingInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can create a recording invite', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.invite-manager')
        ->set('showCreateForm', true)
        ->set('title', 'Customer Testimonial')
        ->set('instructions', 'Please record a 30-second testimonial')
        ->set('maxDuration', 60)
        ->set('expiresInHours', 48)
        ->call('createInvite')
        ->assertHasNoErrors();

    expect(RecordingInvite::count())->toBe(1);
    $invite = RecordingInvite::first();
    expect($invite->title)->toBe('Customer Testimonial')
        ->and($invite->max_duration_seconds)->toBe(60)
        ->and($invite->user_id)->toBe($user->id);
});

it('validates required fields', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.invite-manager')
        ->set('title', '')
        ->call('createInvite')
        ->assertHasErrors(['title']);
});

it('lists user invites', function () {
    $user = User::factory()->create(['type' => 'internal']);
    RecordingInvite::factory()->create(['user_id' => $user->id, 'title' => 'My Invite']);
    RecordingInvite::factory()->create(['title' => 'Other Invite']);

    Livewire::actingAs($user)
        ->test('studio.invite-manager')
        ->assertSee('My Invite')
        ->assertDontSee('Other Invite');
});

it('can delete own invite', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $invite = RecordingInvite::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.invite-manager')
        ->call('deleteInvite', $invite->id);

    expect(RecordingInvite::count())->toBe(0);
});

it('cannot delete other users invite', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $otherInvite = RecordingInvite::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.invite-manager')
        ->call('deleteInvite', $otherInvite->id);

    expect(RecordingInvite::count())->toBe(1);
});
