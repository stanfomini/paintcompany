<?php

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->internal()->withFullAccess()->create();
});

describe('Shared Inbox Settings', function () {
    it('creates a shared email account', function () {
        $member1 = User::factory()->internal()->create();
        $member2 = User::factory()->internal()->create();

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openCreateModal')
            ->set('accountType', 'shared')
            ->set('address', 'hello')
            ->set('displayName', 'Painting Made Easy Denver')
            ->set('memberIds', [$member1->id, $member2->id])
            ->call('save')
            ->assertSet('showModal', false);

        $account = EmailAccount::where('address', 'hello')->first();
        expect($account)->not->toBeNull();
        expect($account->type)->toBe('shared');
        expect($account->user_id)->toBeNull();
        expect($account->members)->toHaveCount(2);
    });

    it('requires members for shared account', function () {
        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openCreateModal')
            ->set('accountType', 'shared')
            ->set('address', 'team')
            ->set('displayName', 'Team')
            ->set('memberIds', [])
            ->call('save')
            ->assertHasErrors(['memberIds']);
    });

    it('edits shared account and updates members', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member1 = User::factory()->internal()->create();
        $member2 = User::factory()->internal()->create();
        $shared->members()->attach([$member1->id]);

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openEditModal', $shared->id)
            ->set('displayName', 'Updated Name')
            ->set('memberIds', [$member1->id, $member2->id])
            ->call('save')
            ->assertSet('showModal', false);

        $shared->refresh();
        expect($shared->display_name)->toBe('Updated Name');
        expect($shared->members)->toHaveCount(2);
    });

    it('sets default notify user for shared account', function () {
        $defaultUser = User::factory()->internal()->create();

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openCreateModal')
            ->set('accountType', 'shared')
            ->set('address', 'hello')
            ->set('displayName', 'Team Inbox')
            ->set('defaultNotifyUserId', $defaultUser->id)
            ->set('memberIds', [$defaultUser->id])
            ->call('save')
            ->assertSet('showModal', false);

        $account = EmailAccount::where('address', 'hello')->first();
        expect($account->default_notify_user_id)->toBe($defaultUser->id);
    });

    it('deletes shared account and cascades pivot records', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member = User::factory()->internal()->create();
        $shared->members()->attach($member);

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('confirmDelete', $shared->id)
            ->call('delete');

        expect(EmailAccount::find($shared->id))->toBeNull();
        expect(DB::table('email_account_user')->where('email_account_id', $shared->id)->count())->toBe(0);
    });

    it('shows shared badge in accounts list', function () {
        EmailAccount::factory()->shared()->create(['address' => 'team']);

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->assertSee('team')
            ->assertSee('Shared');
    });

    it('shows manage members button for shared accounts', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'team']);
        $member = User::factory()->internal()->create();
        $shared->members()->attach($member);

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openMembersModal', $shared->id)
            ->assertSet('showMembersModal', true)
            ->assertSet('managingMembersId', $shared->id);

        expect($this->admin)->not->toBeNull();
    });

    it('adds and removes members from shared account', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'team']);
        $member1 = User::factory()->internal()->create();
        $member2 = User::factory()->internal()->create();
        $shared->members()->attach($member1);

        $component = Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openMembersModal', $shared->id);

        // Add second member
        $component
            ->set('addMemberId', $member2->id)
            ->call('addMember');

        expect($shared->fresh()->members)->toHaveCount(2);

        // Remove first member
        $component->call('removeMember', $member1->id);

        expect($shared->fresh()->members)->toHaveCount(1);
        expect($shared->fresh()->members->first()->id)->toBe($member2->id);
    });

    it('toggles member permissions', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'team']);
        $member = User::factory()->internal()->create();
        $shared->members()->attach($member, ['can_send' => true, 'receives_notifications' => true]);

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openMembersModal', $shared->id)
            ->call('toggleMemberPermission', $member->id, 'can_send');

        $pivot = $shared->members()->where('users.id', $member->id)->first()->pivot;
        expect((bool) $pivot->can_send)->toBeFalse();
        expect((bool) $pivot->receives_notifications)->toBeTrue();
    });

    it('does not allow opening members modal for personal accounts', function () {
        $personal = EmailAccount::factory()->create(['address' => 'personal']);

        Livewire::actingAs($this->admin)
            ->test('pages::settings.email-accounts')
            ->call('openMembersModal', $personal->id)
            ->assertSet('showMembersModal', false);
    });
});
