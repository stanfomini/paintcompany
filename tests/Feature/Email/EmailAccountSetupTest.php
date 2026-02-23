<?php

use App\Models\EmailAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

describe('Email Account Setup', function () {
    it('creates email account', function () {
        Livewire::actingAs($this->user)
            ->test('email.account-setup')
            ->set('address', 'newuser')
            ->set('displayName', 'New User')
            ->set('signatureHtml', '<p>Best regards</p>')
            ->call('create')
            ->assertRedirect(route('email.inbox'));

        $account = EmailAccount::where('user_id', $this->user->id)->first();

        expect($account)->not->toBeNull();
        expect($account->address)->toBe('newuser');
        expect($account->display_name)->toBe('New User');
        expect($account->signature_html)->toBe('<p>Best regards</p>');
    });

    it('rejects duplicate addresses', function () {
        EmailAccount::factory()->create(['address' => 'taken']);

        Livewire::actingAs($this->user)
            ->test('email.account-setup')
            ->set('address', 'taken')
            ->set('displayName', 'Test')
            ->call('create')
            ->assertHasErrors(['address']);
    });

    it('rejects invalid address characters', function () {
        Livewire::actingAs($this->user)
            ->test('email.account-setup')
            ->set('address', 'UPPER CASE!')
            ->set('displayName', 'Test')
            ->call('create')
            ->assertHasErrors(['address']);
    });

    it('redirects to inbox after setup', function () {
        Livewire::actingAs($this->user)
            ->test('email.account-setup')
            ->set('address', 'validuser')
            ->set('displayName', 'Valid User')
            ->call('create')
            ->assertRedirect(route('email.inbox'));
    });
});
