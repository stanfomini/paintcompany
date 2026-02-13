<?php

use App\Models\RoiCalculation;
use Livewire\Livewire;

describe('Public ROI Viewer', function () {
    it('shows the ROI viewer page for a valid token', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create();

        $this->get(route('roi.public', ['token' => $calc->public_token]))
            ->assertOk()
            ->assertSeeLivewire('public.roi-viewer');
    });

    it('returns 404 for an invalid token', function () {
        $this->get(route('roi.public', ['token' => 'invalid-token-abc123']))
            ->assertNotFound();
    });

    it('returns 410 for an expired token', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create([
            'public_token_expires_at' => now()->subDay(),
        ]);

        $this->get(route('roi.public', ['token' => $calc->public_token]))
            ->assertGone();
    });

    it('shows selling-specific framing for selling intent', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->selling()->create();

        Livewire::test('public.roi-viewer', ['token' => $calc->public_token])
            ->assertSee('increase your listing price');
    });

    it('shows buying-specific framing for buying intent', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->buying()->create();

        Livewire::test('public.roi-viewer', ['token' => $calc->public_token])
            ->assertSee('bring this home to its full potential');
    });

    it('displays the cost range data', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create([
            'estimated_cost_low' => 5000.00,
            'estimated_cost_high' => 8000.00,
        ]);

        Livewire::test('public.roi-viewer', ['token' => $calc->public_token])
            ->assertSee('5,000')
            ->assertSee('8,000');
    });

    it('displays the value increase data', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create([
            'estimated_value_increase_low' => 5350.00,
            'estimated_value_increase_high' => 20000.00,
        ]);

        Livewire::test('public.roi-viewer', ['token' => $calc->public_token])
            ->assertSee('5,350')
            ->assertSee('20,000');
    });

    it('displays the ROI percentage range', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create([
            'roi_percent_low' => 107.00,
            'roi_percent_high' => 500.00,
        ]);

        Livewire::test('public.roi-viewer', ['token' => $calc->public_token])
            ->assertSee('107%')
            ->assertSee('500%');
    });

    it('shows disclaimer text', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create();

        Livewire::test('public.roi-viewer', ['token' => $calc->public_token])
            ->assertSee('Estimates vary by area, property condition, and listing strategy');
    });

    it('renders without authentication', function () {
        $calc = RoiCalculation::factory()->withPublicToken()->create();

        $this->assertGuest();

        $this->get(route('roi.public', ['token' => $calc->public_token]))
            ->assertOk();
    });
});
