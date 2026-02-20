<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::getInstance();
});

// ─── Toast Container ─────────────────────────────────────────────────

describe('Toast Container Component', function () {
    it('renders for internal users on dashboard', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSeeLivewire('shared.toast-container');
    });

    it('renders the toast container component successfully', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user);

        Livewire::test('shared.toast-container')
            ->assertStatus(200)
            ->assertSeeHtml('data-toast-container');
    });

    it('renders for crew users', function () {
        $user = User::factory()->crew()->create();

        $this->actingAs($user, 'crew')
            ->get(route('crew.dashboard'))
            ->assertSeeLivewire('shared.toast-container');
    });

    it('renders for agent users', function () {
        $user = User::factory()->agent()->create();

        $this->actingAs($user, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSeeLivewire('shared.toast-container');
    });

    it('renders for customer users', function () {
        $user = User::factory()->customer()->create();

        $this->actingAs($user, 'customer')
            ->get(route('customer.dashboard'))
            ->assertSeeLivewire('shared.toast-container');
    });
});

// ─── Flash Messages → Toast ──────────────────────────────────────────

describe('Flash Messages Dispatch Toasts', function () {
    it('dispatches success toast from session flash', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->withSession(['success' => 'Record saved successfully'])
            ->get(route('dashboard'));

        $response->assertSee("dispatch('toast'", escape: false);
        $response->assertSee('Record saved successfully', escape: false);
    });

    it('dispatches error toast from session flash', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->withSession(['error' => 'Something went wrong'])
            ->get(route('dashboard'));

        $response->assertSee("dispatch('toast'", escape: false);
        $response->assertSee('Something went wrong', escape: false);
    });

    it('dispatches warning toast from session flash', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->withSession(['warning' => 'Check your input'])
            ->get(route('dashboard'));

        $response->assertSee("dispatch('toast'", escape: false);
        $response->assertSee('Check your input', escape: false);
    });

    it('dispatches info toast from session flash', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->withSession(['info' => 'New feature available'])
            ->get(route('dashboard'));

        $response->assertSee("dispatch('toast'", escape: false);
        $response->assertSee('New feature available', escape: false);
    });

    it('does not dispatch toast when no session flash', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->get(route('dashboard'));

        $response->assertDontSee("dispatch('toast'", escape: false);
    });
});

// ─── New Lead Alert ──────────────────────────────────────────────────

describe('New Lead Alert Dispatches Toast', function () {
    it('renders for internal users with deals.view permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user);

        Livewire::test('notifications.new-lead-alert')
            ->assertStatus(200);
    });

    it('dispatches toast event on new lead', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user);

        $otherUser = User::factory()->internal()->create(['name' => 'Other User']);

        Livewire::test('notifications.new-lead-alert')
            ->call('handleNewLead', [
                'deal' => [
                    'id' => 1,
                    'title' => 'Test Deal',
                    'customer' => ['full_name' => 'John Smith'],
                    'created_by' => ['id' => $otherUser->id, 'name' => 'Other User'],
                    'created_at' => now()->toISOString(),
                ],
            ])
            ->assertDispatched('toast')
            ->assertDispatched('play-notification-sound');
    });

    it('does not dispatch toast for own leads', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user);

        Livewire::test('notifications.new-lead-alert')
            ->call('handleNewLead', [
                'deal' => [
                    'id' => 1,
                    'title' => 'Test Deal',
                    'customer' => ['full_name' => 'John Smith'],
                    'created_by' => ['id' => $user->id, 'name' => $user->name],
                    'created_at' => now()->toISOString(),
                ],
            ])
            ->assertNotDispatched('toast')
            ->assertNotDispatched('play-notification-sound');
    });

    it('still plays notification sound on new lead', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user);

        $otherUser = User::factory()->internal()->create();

        Livewire::test('notifications.new-lead-alert')
            ->call('handleNewLead', [
                'deal' => [
                    'id' => 1,
                    'title' => 'Test Deal',
                    'customer' => ['full_name' => 'Jane Doe'],
                    'created_by' => ['id' => $otherUser->id, 'name' => 'Other'],
                    'created_at' => now()->toISOString(),
                ],
            ])
            ->assertDispatched('play-notification-sound');
    });
});

// ─── Notification Center Unaffected ──────────────────────────────────

describe('Notification Center Still Works', function () {
    it('renders notification bell for internal users', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user);

        Livewire::test('notifications.notification-center')
            ->assertStatus(200)
            ->call('toggleOpen')
            ->assertSee(__('Notifications'));
    });
});
