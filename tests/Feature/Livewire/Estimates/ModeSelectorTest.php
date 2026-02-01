<?php

use App\Livewire\Estimates\ModeSelector;
use App\Models\User;
use Livewire\Livewire;

describe('ModeSelector component', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->create();
    });

    it('renders successfully', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->assertStatus(200);
    });

    it('defaults to template mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->assertSet('selectedMode', ModeSelector::MODE_TEMPLATE);
    });

    it('shows descriptions by default', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->assertSet('showDescriptions', true);
    });

    it('can select simple mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->call('selectMode', ModeSelector::MODE_SIMPLE)
            ->assertSet('selectedMode', ModeSelector::MODE_SIMPLE)
            ->assertDispatched('mode-selected', mode: ModeSelector::MODE_SIMPLE);
    });

    it('can select template mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->set('selectedMode', ModeSelector::MODE_SIMPLE)
            ->call('selectMode', ModeSelector::MODE_TEMPLATE)
            ->assertSet('selectedMode', ModeSelector::MODE_TEMPLATE)
            ->assertDispatched('mode-selected', mode: ModeSelector::MODE_TEMPLATE);
    });

    it('can select detailed mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->call('selectMode', ModeSelector::MODE_DETAILED)
            ->assertSet('selectedMode', ModeSelector::MODE_DETAILED)
            ->assertDispatched('mode-selected', mode: ModeSelector::MODE_DETAILED);
    });

    it('does not change mode for invalid mode', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->call('selectMode', 'invalid-mode')
            ->assertSet('selectedMode', ModeSelector::MODE_TEMPLATE)
            ->assertNotDispatched('mode-selected');
    });

    it('returns all three modes from getModes', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class);

        $instance = $component->instance();
        $modes = $instance->getModes();

        expect($modes)->toHaveKeys([
            ModeSelector::MODE_SIMPLE,
            ModeSelector::MODE_TEMPLATE,
            ModeSelector::MODE_DETAILED,
        ]);

        // Verify simple mode
        expect($modes[ModeSelector::MODE_SIMPLE]['icon'])->toBe('bolt');
        expect($modes[ModeSelector::MODE_SIMPLE]['name'])->toBe('Quick Estimate');
        expect($modes[ModeSelector::MODE_SIMPLE]['description'])->toBe('$/sqft pricing');
        expect($modes[ModeSelector::MODE_SIMPLE]['time'])->toBe('~30 seconds');

        // Verify template mode (recommended)
        expect($modes[ModeSelector::MODE_TEMPLATE]['icon'])->toBe('squares-2x2');
        expect($modes[ModeSelector::MODE_TEMPLATE]['name'])->toBe('Template Builder');
        expect($modes[ModeSelector::MODE_TEMPLATE]['description'])->toBe('Pre-made cards');
        expect($modes[ModeSelector::MODE_TEMPLATE]['time'])->toBe('~5 minutes');
        expect($modes[ModeSelector::MODE_TEMPLATE]['recommended'])->toBeTrue();

        // Verify detailed mode
        expect($modes[ModeSelector::MODE_DETAILED]['icon'])->toBe('table-cells');
        expect($modes[ModeSelector::MODE_DETAILED]['name'])->toBe('Detailed Builder');
        expect($modes[ModeSelector::MODE_DETAILED]['description'])->toBe('Full production rates');
        expect($modes[ModeSelector::MODE_DETAILED]['time'])->toBe('~15+ minutes');
    });

    it('renders all mode buttons', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class)
            ->assertSee('Quick Estimate')
            ->assertSee('Template Builder')
            ->assertSee('Detailed Builder')
            ->assertSee('$/sqft pricing')
            ->assertSee('Pre-made cards')
            ->assertSee('Full production rates')
            ->assertSee('~30 seconds')
            ->assertSee('~5 minutes')
            ->assertSee('~15+ minutes')
            ->assertSee('Recommended');
    });

    it('can hide descriptions', function () {
        Livewire::actingAs($this->internalUser)
            ->test(ModeSelector::class, ['showDescriptions' => false])
            ->assertSet('showDescriptions', false)
            ->assertSee('Quick Estimate')
            ->assertDontSee('$/sqft pricing');
    });

    it('mode constants have correct values', function () {
        expect(ModeSelector::MODE_SIMPLE)->toBe('simple');
        expect(ModeSelector::MODE_TEMPLATE)->toBe('template');
        expect(ModeSelector::MODE_DETAILED)->toBe('detailed');
    });
});
