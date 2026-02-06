<?php

use App\Models\User;
use Livewire\Livewire;

describe('ModeSelector component', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    });

    it('renders successfully', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->assertStatus(200);
    });

    it('defaults to template mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->assertSet('selectedMode', 'template');
    });

    it('shows descriptions by default', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->assertSet('showDescriptions', true);
    });

    it('can select simple mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->call('selectMode', 'simple')
            ->assertSet('selectedMode', 'simple')
            ->assertDispatched('mode-selected', mode: 'simple');
    });

    it('can select template mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->set('selectedMode', 'simple')
            ->call('selectMode', 'template')
            ->assertSet('selectedMode', 'template')
            ->assertDispatched('mode-selected', mode: 'template');
    });

    it('does not change mode for invalid mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->call('selectMode', 'invalid-mode')
            ->assertSet('selectedMode', 'template')
            ->assertNotDispatched('mode-selected');
    });

    it('returns both modes from getModes', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector');

        $instance = $component->instance();
        $modes = $instance->getModes();

        expect($modes)->toHaveKeys([
            'simple',
            'template',
        ]);

        // Verify simple mode
        expect($modes['simple']['icon'])->toBe('bolt');
        expect($modes['simple']['name'])->toBe('Quick Estimate');
        expect($modes['simple']['description'])->toBe('$/sqft pricing');
        expect($modes['simple']['time'])->toBe('~30 seconds');

        // Verify template mode (recommended)
        expect($modes['template']['icon'])->toBe('squares-2x2');
        expect($modes['template']['name'])->toBe('Template Builder');
        expect($modes['template']['description'])->toBe('Pre-made cards');
        expect($modes['template']['time'])->toBe('~5 minutes');
        expect($modes['template']['recommended'])->toBeTrue();
    });

    it('renders all mode buttons', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector')
            ->assertSee('Quick Estimate')
            ->assertSee('Template Builder')
            ->assertSee('$/sqft pricing')
            ->assertSee('Pre-made cards')
            ->assertSee('~30 seconds')
            ->assertSee('~5 minutes')
            ->assertSee('Recommended');
    });

    it('can hide descriptions', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.mode-selector', ['showDescriptions' => false])
            ->assertSet('showDescriptions', false)
            ->assertSee('Quick Estimate')
            ->assertDontSee('$/sqft pricing');
    });

    it('mode constants have correct values', function () {
        expect('simple')->toBe('simple');
        expect('template')->toBe('template');
    });
});
