<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateView;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Estimate Preview', function () {
    it('requires authentication for preview route', function () {
        $estimate = Estimate::factory()->sent()->create();
        $estimate->generatePublicToken();

        $this->get(route('estimates.preview', ['token' => $estimate->public_token]))
            ->assertRedirect('/login');
    });

    it('loads proposal page without recording a view', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['email' => 'test@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->sent()->create(['created_by' => $user->id]);
        $estimate->generatePublicToken();

        $this->actingAs($user)
            ->get(route('estimates.preview', ['token' => $estimate->public_token]))
            ->assertSuccessful();

        expect(EstimateView::where('estimate_id', $estimate->id)->count())->toBe(0);
    });

    it('generates a token for draft estimates via getPreviewUrl', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        expect($estimate->public_token)->toBeNull();

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('getPreviewUrl');

        $estimate->refresh();
        expect($estimate->public_token)->not->toBeNull();
        expect($estimate->status)->toBe('draft');
    });

    it('does not change estimate status when generating preview token', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        $estimate->ensurePublicToken();

        expect($estimate->status)->toBe('draft');
        expect($estimate->sent_at)->toBeNull();
        expect($estimate->public_token)->not->toBeNull();
    });

    it('reuses existing valid token', function () {
        $estimate = Estimate::factory()->sent()->create();
        $originalToken = $estimate->generatePublicToken();

        $returned = $estimate->ensurePublicToken();

        expect($returned)->toBe($originalToken);
    });

    it('regenerates expired token', function () {
        $estimate = Estimate::factory()->sent()->create();
        $estimate->public_token = 'expired-token';
        $estimate->public_token_expires_at = now()->subDay();
        $estimate->save();

        $newToken = $estimate->ensurePublicToken();

        expect($newToken)->not->toBe('expired-token');
        expect($estimate->isPublicTokenValid())->toBeTrue();
    });

    it('records views on public proposal route', function () {
        $customer = Customer::factory()->create(['email' => 'test@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->sent()->create();
        $estimate->generatePublicToken();

        $this->get(route('estimates.public', ['token' => $estimate->public_token]))
            ->assertSuccessful();

        expect(EstimateView::where('estimate_id', $estimate->id)->count())->toBe(1);
    });

    it('shows preview button on estimate show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Preview');
    });
});
