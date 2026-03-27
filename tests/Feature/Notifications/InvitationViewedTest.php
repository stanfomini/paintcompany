<?php

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationViewed;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
});

it('sends notification to inviter on first visit', function () {
    $inviter = User::factory()->internal()->create();
    $invitation = Invitation::factory()->forAgent()->create(['inviter_id' => $inviter->id]);

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    Notification::assertSentTo($inviter, InvitationViewed::class);
});

it('does not send duplicate notification on second visit', function () {
    $inviter = User::factory()->internal()->create();
    $invitation = Invitation::factory()->forAgent()->create(['inviter_id' => $inviter->id]);

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);
    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    Notification::assertSentToTimes($inviter, InvitationViewed::class, 1);
});

it('sets first_viewed_at on first visit', function () {
    $invitation = Invitation::factory()->forAgent()->create();

    expect($invitation->first_viewed_at)->toBeNull();

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    $invitation->refresh();
    expect($invitation->first_viewed_at)->not->toBeNull();
});

it('does not overwrite first_viewed_at on second visit', function () {
    $invitation = Invitation::factory()->forAgent()->create();

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    $invitation->refresh();
    $firstViewedAt = $invitation->first_viewed_at;

    $this->travel(5)->minutes();

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    $invitation->refresh();
    expect($invitation->first_viewed_at->toDateTimeString())->toBe($firstViewedAt->toDateTimeString());
});

it('includes correct data in notification payload', function () {
    $inviter = User::factory()->internal()->create();
    $invitation = Invitation::factory()->forAgent()->create([
        'inviter_id' => $inviter->id,
        'email' => 'agent@example.com',
    ]);

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    Notification::assertSentTo($inviter, InvitationViewed::class, function ($notification) use ($invitation, $inviter) {
        $data = $notification->toArray($inviter);

        return $data['invitation_id'] === $invitation->id
            && $data['agent_email'] === 'agent@example.com'
            && $data['invited_user_type'] === 'agent'
            && $data['type'] === 'invitation_viewed';
    });
});

it('uses database, broadcast, and mail channels by default', function () {
    $notification = new InvitationViewed(Invitation::factory()->create());

    expect($notification->via(new stdClass))->toBe(['database', 'broadcast', 'mail']);
});

it('does not notify for expired invitations', function () {
    $invitation = Invitation::factory()->forAgent()->expired()->create();

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    Notification::assertNothingSent();
});

it('does not notify for already accepted invitations', function () {
    $invitation = Invitation::factory()->forAgent()->accepted()->create();

    Livewire::test('invitations.accept-invitation', ['token' => $invitation->token]);

    Notification::assertNothingSent();
});
