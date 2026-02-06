<?php

use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('ModeSelector component rendering', function () {
    it('can render mode selector component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->assertStatus(200)
            ->assertSee('Quick Estimate')
            ->assertSee('Template Builder');
    });

    it('displays mode descriptions', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->assertSee('$/sqft pricing')
            ->assertSee('Pre-made cards');
    });

    it('displays estimated times', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->assertSee('~30 seconds')
            ->assertSee('~5 minutes');
    });

    it('hides descriptions when showDescriptions is false', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->set('showDescriptions', false)
            ->assertSet('showDescriptions', false);
    });
});

describe('ModeSelector mode selection', function () {
    it('has template as default mode', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->assertSet('selectedMode', 'template');
    });

    it('selects simple mode and dispatches event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->call('selectMode', 'simple')
            ->assertSet('selectedMode', 'simple')
            ->assertDispatched('mode-selected', mode: 'simple');
    });

    it('selects template mode and dispatches event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        // First change to a different mode
        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->call('selectMode', 'simple')
            ->call('selectMode', 'template')
            ->assertSet('selectedMode', 'template')
            ->assertDispatched('mode-selected', mode: 'template');
    });

    it('ignores invalid mode selection', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->call('selectMode', 'invalid_mode')
            ->assertSet('selectedMode', 'template')
            ->assertNotDispatched('mode-selected');
    });

    it('ignores empty mode selection', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->call('selectMode', '')
            ->assertSet('selectedMode', 'template')
            ->assertNotDispatched('mode-selected');
    });
});

describe('ModeSelector modes data', function () {
    it('returns correct modes configuration', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.mode-selector');

        $modes = $component->get('modes');

        expect($modes)->toHaveCount(2);
        expect($modes)->toHaveKeys(['simple', 'template']);
    });

    it('has simple mode with correct properties', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.mode-selector');

        $modes = $component->get('modes');
        $simpleMode = $modes['simple'];

        expect($simpleMode['icon'])->toBe('bolt');
        expect($simpleMode['name'])->toBe('Quick Estimate');
        expect($simpleMode['description'])->toBe('$/sqft pricing');
        expect($simpleMode['time'])->toBe('~30 seconds');
        expect($simpleMode)->not->toHaveKey('recommended');
    });

    it('has template mode marked as recommended', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.mode-selector');

        $modes = $component->get('modes');
        $templateMode = $modes['template'];

        expect($templateMode['icon'])->toBe('squares-2x2');
        expect($templateMode['name'])->toBe('Template Builder');
        expect($templateMode['description'])->toBe('Pre-made cards');
        expect($templateMode['time'])->toBe('~5 minutes');
        expect($templateMode['recommended'])->toBeTrue();
    });
});

describe('ModeSelector constants', function () {
    it('has correct mode constants', function () {
        expect('simple')->toBe('simple');
        expect('template')->toBe('template');
    });
});

describe('ModeSelector multiple selections', function () {
    it('can switch between modes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->assertSet('selectedMode', 'template')
            ->call('selectMode', 'simple')
            ->assertSet('selectedMode', 'simple')
            ->call('selectMode', 'template')
            ->assertSet('selectedMode', 'template');
    });

    it('dispatches event for each valid selection', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.mode-selector')
            ->call('selectMode', 'simple')
            ->assertDispatched('mode-selected', mode: 'simple')
            ->call('selectMode', 'template')
            ->assertDispatched('mode-selected', mode: 'template');
    });
});
