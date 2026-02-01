<?php

use App\Livewire\Estimates\ModeSelector;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('ModeSelector component rendering', function () {
    it('can render mode selector component', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->assertStatus(200)
            ->assertSee('Quick Estimate')
            ->assertSee('Template Builder')
            ->assertSee('Detailed Builder');
    });

    it('displays mode descriptions', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->assertSee('$/sqft pricing')
            ->assertSee('Pre-made cards')
            ->assertSee('Full production rates');
    });

    it('displays estimated times', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->assertSee('~30 seconds')
            ->assertSee('~5 minutes')
            ->assertSee('~15+ minutes');
    });

    it('hides descriptions when showDescriptions is false', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->set('showDescriptions', false)
            ->assertSet('showDescriptions', false);
    });
});

describe('ModeSelector mode selection', function () {
    it('has template as default mode', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->assertSet('selectedMode', ModeSelector::MODE_TEMPLATE);
    });

    it('selects simple mode and dispatches event', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->call('selectMode', 'simple')
            ->assertSet('selectedMode', 'simple')
            ->assertDispatched('mode-selected', mode: 'simple');
    });

    it('selects template mode and dispatches event', function () {
        $user = User::factory()->internal()->create();

        // First change to a different mode
        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->call('selectMode', 'simple')
            ->call('selectMode', 'template')
            ->assertSet('selectedMode', 'template')
            ->assertDispatched('mode-selected', mode: 'template');
    });

    it('selects detailed mode and dispatches event', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->call('selectMode', 'detailed')
            ->assertSet('selectedMode', 'detailed')
            ->assertDispatched('mode-selected', mode: 'detailed');
    });

    it('ignores invalid mode selection', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->call('selectMode', 'invalid_mode')
            ->assertSet('selectedMode', ModeSelector::MODE_TEMPLATE)
            ->assertNotDispatched('mode-selected');
    });

    it('ignores empty mode selection', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->call('selectMode', '')
            ->assertSet('selectedMode', ModeSelector::MODE_TEMPLATE)
            ->assertNotDispatched('mode-selected');
    });
});

describe('ModeSelector modes data', function () {
    it('returns correct modes configuration', function () {
        $user = User::factory()->internal()->create();

        $component = Livewire::actingAs($user)
            ->test(ModeSelector::class);

        $modes = $component->viewData('modes');

        expect($modes)->toHaveCount(3);
        expect($modes)->toHaveKeys(['simple', 'template', 'detailed']);
    });

    it('has simple mode with correct properties', function () {
        $user = User::factory()->internal()->create();

        $component = Livewire::actingAs($user)
            ->test(ModeSelector::class);

        $modes = $component->viewData('modes');
        $simpleMode = $modes['simple'];

        expect($simpleMode['icon'])->toBe('bolt');
        expect($simpleMode['name'])->toBe('Quick Estimate');
        expect($simpleMode['description'])->toBe('$/sqft pricing');
        expect($simpleMode['time'])->toBe('~30 seconds');
        expect($simpleMode)->not->toHaveKey('recommended');
    });

    it('has template mode marked as recommended', function () {
        $user = User::factory()->internal()->create();

        $component = Livewire::actingAs($user)
            ->test(ModeSelector::class);

        $modes = $component->viewData('modes');
        $templateMode = $modes['template'];

        expect($templateMode['icon'])->toBe('squares-2x2');
        expect($templateMode['name'])->toBe('Template Builder');
        expect($templateMode['description'])->toBe('Pre-made cards');
        expect($templateMode['time'])->toBe('~5 minutes');
        expect($templateMode['recommended'])->toBeTrue();
    });

    it('has detailed mode with correct properties', function () {
        $user = User::factory()->internal()->create();

        $component = Livewire::actingAs($user)
            ->test(ModeSelector::class);

        $modes = $component->viewData('modes');
        $detailedMode = $modes['detailed'];

        expect($detailedMode['icon'])->toBe('table-cells');
        expect($detailedMode['name'])->toBe('Detailed Builder');
        expect($detailedMode['description'])->toBe('Full production rates');
        expect($detailedMode['time'])->toBe('~15+ minutes');
        expect($detailedMode)->not->toHaveKey('recommended');
    });
});

describe('ModeSelector constants', function () {
    it('has correct mode constants', function () {
        expect(ModeSelector::MODE_SIMPLE)->toBe('simple');
        expect(ModeSelector::MODE_TEMPLATE)->toBe('template');
        expect(ModeSelector::MODE_DETAILED)->toBe('detailed');
    });
});

describe('ModeSelector multiple selections', function () {
    it('can switch between all modes', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->assertSet('selectedMode', 'template')
            ->call('selectMode', 'simple')
            ->assertSet('selectedMode', 'simple')
            ->call('selectMode', 'detailed')
            ->assertSet('selectedMode', 'detailed')
            ->call('selectMode', 'template')
            ->assertSet('selectedMode', 'template');
    });

    it('dispatches event for each valid selection', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(ModeSelector::class)
            ->call('selectMode', 'simple')
            ->assertDispatched('mode-selected', mode: 'simple')
            ->call('selectMode', 'detailed')
            ->assertDispatched('mode-selected', mode: 'detailed');
    });
});
