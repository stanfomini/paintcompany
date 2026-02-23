<?php

use App\Models\EmailAccount;
use App\Models\User;
use Livewire\Livewire;

it('blocks access without email.manage permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('email.access');

    Livewire::actingAs($user)
        ->test('pages::settings.email-accounts')
        ->assertForbidden();
});

it('allows access with email.manage permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('email.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.email-accounts')
        ->assertSuccessful()
        ->assertSee('Email Accounts');
});

it('lists existing email accounts', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create(['name' => 'Jane Smith']);

    EmailAccount::create([
        'user_id' => $employee->id,
        'address' => 'jane.smith',
        'display_name' => 'Jane Smith',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->assertSee('jane.smith')
        ->assertSee('Jane Smith');
});

it('creates a new email account for a user', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create(['name' => 'Bob Sales']);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('openCreateModal')
        ->assertSet('showModal', true)
        ->set('userId', $employee->id)
        ->set('address', 'bob.sales')
        ->set('displayName', 'Bob Sales')
        ->call('save')
        ->assertSet('showModal', false);

    expect(EmailAccount::where('address', 'bob.sales')->exists())->toBeTrue();
    expect(EmailAccount::where('user_id', $employee->id)->first()->display_name)->toBe('Bob Sales');
});

it('prevents duplicate email addresses', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $user1 = User::factory()->internal()->create();
    $user2 = User::factory()->internal()->create();

    EmailAccount::create([
        'user_id' => $user1->id,
        'address' => 'taken',
        'display_name' => 'User One',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('openCreateModal')
        ->set('userId', $user2->id)
        ->set('address', 'taken')
        ->call('save')
        ->assertHasErrors('address');
});

it('prevents user from having two accounts', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create();

    EmailAccount::create([
        'user_id' => $employee->id,
        'address' => 'existing',
        'display_name' => 'Employee',
    ]);

    $newUser = User::factory()->internal()->create();

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('openCreateModal')
        ->set('userId', $employee->id)
        ->set('address', 'another')
        ->call('save')
        ->assertHasErrors('userId');
});

it('edits an existing email account', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create();

    $account = EmailAccount::create([
        'user_id' => $employee->id,
        'address' => 'old.address',
        'display_name' => 'Old Name',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('openEditModal', $account->id)
        ->assertSet('editingId', $account->id)
        ->assertSet('address', 'old.address')
        ->set('address', 'new.address')
        ->set('displayName', 'New Name')
        ->call('save')
        ->assertSet('showModal', false);

    $account->refresh();
    expect($account->address)->toBe('new.address');
    expect($account->display_name)->toBe('New Name');
});

it('toggles account active status', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create();

    $account = EmailAccount::create([
        'user_id' => $employee->id,
        'address' => 'test',
        'display_name' => 'Test',
        'is_active' => true,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('toggleActive', $account->id);

    expect($account->fresh()->is_active)->toBeFalse();
});

it('reassigns account to a different user', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $oldOwner = User::factory()->internal()->create(['name' => 'Old Owner']);
    $newOwner = User::factory()->internal()->create(['name' => 'New Owner']);

    $account = EmailAccount::create([
        'user_id' => $oldOwner->id,
        'address' => 'shared.account',
        'display_name' => 'Shared Account',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('openReassignModal', $account->id)
        ->assertSet('showReassignModal', true)
        ->assertSet('reassigningAddress', 'shared.account')
        ->set('reassignToUserId', $newOwner->id)
        ->call('reassign')
        ->assertSet('showReassignModal', false);

    expect($account->fresh()->user_id)->toBe($newOwner->id);
});

it('prevents reassigning to a user who already has an account', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $user1 = User::factory()->internal()->create();
    $user2 = User::factory()->internal()->create();

    EmailAccount::create(['user_id' => $user1->id, 'address' => 'user1', 'display_name' => 'User 1']);
    $account2 = EmailAccount::create(['user_id' => $user2->id, 'address' => 'user2', 'display_name' => 'User 2']);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('openReassignModal', $account2->id)
        ->set('reassignToUserId', $user1->id)
        ->call('reassign')
        ->assertHasErrors('reassignToUserId');
});

it('deletes an email account', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = User::factory()->internal()->create();

    $account = EmailAccount::create([
        'user_id' => $employee->id,
        'address' => 'to.delete',
        'display_name' => 'To Delete',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->call('confirmDelete', $account->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete')
        ->assertSet('showDeleteModal', false);

    expect(EmailAccount::find($account->id))->toBeNull();
});

it('searches accounts by address and user name', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $user1 = User::factory()->internal()->create(['name' => 'Alice Wonder']);
    $user2 = User::factory()->internal()->create(['name' => 'Bob Builder']);

    EmailAccount::create(['user_id' => $user1->id, 'address' => 'alice', 'display_name' => 'Alice']);
    EmailAccount::create(['user_id' => $user2->id, 'address' => 'bob', 'display_name' => 'Bob']);

    Livewire::actingAs($admin)
        ->test('pages::settings.email-accounts')
        ->assertSee('alice')
        ->assertSee('bob')
        ->set('search', 'alice')
        ->assertSee('alice')
        ->assertDontSee('bob');
});
