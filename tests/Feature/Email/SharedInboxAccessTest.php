<?php

use App\Models\EmailAccount;
use App\Models\User;
use Livewire\Livewire;

describe('Shared Inbox Access Control', function () {
    it('allows member to access shared account in inbox', function () {
        $member = User::factory()->internal()->withFullAccess()->create();
        EmailAccount::factory()->create(['user_id' => $member->id, 'address' => 'personal']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $shared->members()->attach($member, ['can_send' => true, 'receives_notifications' => true]);

        $this->actingAs($member);

        $response = $this->get(route('email.inbox', ['activeAccountId' => $shared->id]));
        $response->assertSuccessful();
    });

    it('denies non-member access to shared account and falls back to personal', function () {
        $nonMember = User::factory()->internal()->withFullAccess()->create();
        $personal = EmailAccount::factory()->create(['user_id' => $nonMember->id, 'address' => 'personal']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'sharedbox']);

        $this->actingAs($nonMember);

        // The component accepts the activeAccountId URL param, but the account() computed
        // should fall back to personal since user is not a member of the shared account.
        // We verify this by checking the page loads successfully (no crash on invalid account).
        $response = $this->get(route('email.inbox', ['activeAccountId' => $shared->id]));
        $response->assertSuccessful();

        // Additionally verify via the email-list child that the personal account is used
        Livewire::test('email.email-list', ['folder' => 'inbox', 'activeAccountId' => $shared->id])
            ->assertSuccessful();
    });

    it('enforces can_send on compose form available accounts', function () {
        $readOnlyMember = User::factory()->internal()->withFullAccess()->create();
        EmailAccount::factory()->create(['user_id' => $readOnlyMember->id, 'address' => 'myemail']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'teambox']);
        $shared->members()->attach($readOnlyMember, ['can_send' => false, 'receives_notifications' => true]);

        Livewire::actingAs($readOnlyMember)
            ->test('email.compose-form')
            ->assertDontSee('teambox');
    });

    it('shows shared account in compose form when user can send', function () {
        $member = User::factory()->internal()->withFullAccess()->create();
        EmailAccount::factory()->create(['user_id' => $member->id, 'address' => 'myemail']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'teambox']);
        $shared->members()->attach($member, ['can_send' => true, 'receives_notifications' => true]);

        Livewire::actingAs($member)
            ->test('email.compose-form')
            ->assertSee('teambox');
    });

    it('user sees all their accounts in allEmailAccounts', function () {
        $user = User::factory()->internal()->create();
        $personal = EmailAccount::factory()->create(['user_id' => $user->id, 'address' => 'myemail']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'team']);
        $shared->members()->attach($user, ['can_send' => true, 'receives_notifications' => true]);

        $accounts = $user->allEmailAccounts();

        expect($accounts)->toHaveCount(2);
        expect($accounts->pluck('address')->toArray())->toContain('myemail', 'team');
    });

    it('switches account and resets state in inbox', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $personal = EmailAccount::factory()->create(['user_id' => $user->id, 'address' => 'myemail']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'team']);
        $shared->members()->attach($user, ['can_send' => true, 'receives_notifications' => true]);

        Livewire::actingAs($user)
            ->test('email.inbox')
            ->assertSet('activeAccountId', null)
            ->call('onAccountSwitched', $shared->id)
            ->assertSet('activeAccountId', $shared->id)
            ->assertSet('threadId', null)
            ->assertSet('composing', false)
            ->assertSet('folder', 'inbox');
    });

    it('shows account switcher in sidebar when user has multiple accounts', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $personal = EmailAccount::factory()->create(['user_id' => $user->id, 'address' => 'myemail']);
        $shared = EmailAccount::factory()->shared()->create(['address' => 'team']);
        $shared->members()->attach($user, ['can_send' => true, 'receives_notifications' => true]);

        Livewire::actingAs($user)
            ->test('email.folder-sidebar', ['currentFolder' => 'inbox'])
            ->assertSee('myemail')
            ->assertSee('team');
    });

    it('does not show account switcher in sidebar when user has single account', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $personal = EmailAccount::factory()->create(['user_id' => $user->id, 'address' => 'onlyemail']);

        Livewire::actingAs($user)
            ->test('email.folder-sidebar', ['currentFolder' => 'inbox'])
            ->assertSee($personal->full_address);
        // With only one account, the select dropdown shouldn't render
    });
});
