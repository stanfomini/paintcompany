<?php

use App\Livewire\AreaTemplates\Builder;
use App\Livewire\AreaTemplates\Index;
use App\Models\EstimateTemplate;
use App\Models\User;

use function Pest\Laravel\actingAs;

describe('Area Templates Routes', function () {
    it('redirects unauthenticated users to login from index', function () {
        $this->get(route('area-templates.index'))
            ->assertRedirect(route('login'));
    });

    it('redirects unauthenticated users to login from create', function () {
        $this->get(route('area-templates.create'))
            ->assertRedirect(route('login'));
    });

    it('redirects non-internal users from area templates index', function () {
        $customer = User::factory()->customer()->create();

        actingAs($customer)
            ->get(route('area-templates.index'))
            ->assertForbidden();
    });

    it('allows internal users to access area templates index', function () {
        $user = User::factory()->internal()->create();

        actingAs($user)
            ->get(route('area-templates.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class);
    });

    it('allows internal users to access area templates create', function () {
        $user = User::factory()->internal()->create();

        actingAs($user)
            ->get(route('area-templates.create'))
            ->assertOk()
            ->assertSeeLivewire(Builder::class);
    });

    it('allows internal users to access area templates edit', function () {
        $user = User::factory()->internal()->create();
        $template = EstimateTemplate::factory()->create([
            'is_configurable' => true,
            'visibility' => EstimateTemplate::VISIBILITY_COMPANY,
        ]);

        actingAs($user)
            ->get(route('area-templates.edit', $template))
            ->assertOk()
            ->assertSeeLivewire(Builder::class);
    });

    it('generates correct route URLs', function () {
        expect(route('area-templates.index'))->toContain('/production-rates/area-templates');
        expect(route('area-templates.create'))->toContain('/production-rates/area-templates/create');
        expect(route('area-templates.edit', 1))->toContain('/production-rates/area-templates/1/edit');
    });
});
