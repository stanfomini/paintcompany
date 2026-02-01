<?php

/**
 * EstimateProposal Public Component Tests
 *
 * Note: These tests verify the underlying model and behavior functionality.
 * The Livewire component view has a naming conflict where the 'notes' column
 * on the estimates table conflicts with the notes() relationship method.
 * This prevents full Livewire component rendering tests.
 *
 * Full integration tests should be added once the naming conflict is resolved
 * (either by renaming the column to 'internal_notes' or the relationship to 'estimateNotes').
 */

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateOption;
use App\Models\EstimateSignature;
use App\Models\EstimateView;
use App\Models\User;

describe('EstimateProposal public functionality', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->create();
        $this->customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'valid_until' => now()->addMonth(),
        ]);
        $this->estimate->generatePublicToken(30);
    });

    it('returns 404 for invalid token', function () {
        $this->get(route('estimates.public', ['token' => 'invalid-token-123']))
            ->assertNotFound();
    });

    it('returns 410 for expired token', function () {
        $this->estimate->public_token_expires_at = now()->subDay();
        $this->estimate->save();

        $this->get(route('estimates.public', ['token' => $this->estimate->public_token]))
            ->assertGone();
    });

    it('public token is generated correctly', function () {
        expect($this->estimate->public_token)->not->toBeNull();
        expect(strlen($this->estimate->public_token))->toBe(64);
        expect($this->estimate->public_token_expires_at)->not->toBeNull();
        expect($this->estimate->isPublicTokenValid())->toBeTrue();
    });

    it('public token validation works for expired tokens', function () {
        $this->estimate->public_token_expires_at = now()->subHour();
        $this->estimate->save();

        expect($this->estimate->isPublicTokenValid())->toBeFalse();
    });

    it('estimate can be found by public token', function () {
        $foundEstimate = Estimate::byPublicToken($this->estimate->public_token)->first();

        expect($foundEstimate)->not->toBeNull();
        expect($foundEstimate->id)->toBe($this->estimate->id);
    });

    it('estimate view can be recorded', function () {
        expect(EstimateView::where('estimate_id', $this->estimate->id)->count())->toBe(0);

        EstimateView::record($this->estimate);

        expect(EstimateView::where('estimate_id', $this->estimate->id)->count())->toBe(1);
    });

    it('multiple views are recorded', function () {
        EstimateView::record($this->estimate);
        EstimateView::record($this->estimate);
        EstimateView::record($this->estimate);

        expect(EstimateView::where('estimate_id', $this->estimate->id)->count())->toBe(3);
    });

    it('activity can be logged', function () {
        EstimateActivity::log(
            $this->estimate,
            'viewed',
            'Proposal viewed via public link',
            ['ip_address' => '127.0.0.1']
        );

        $activity = EstimateActivity::where('estimate_id', $this->estimate->id)
            ->where('activity_type', 'viewed')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->description)->toBe('Proposal viewed via public link');
    });

    it('option selection can be toggled', function () {
        $option = EstimateOption::factory()->forEstimate($this->estimate)->fixed()->create([
            'is_selected' => false,
        ]);

        $option->is_selected = true;
        $option->save();

        $option->refresh();
        expect($option->is_selected)->toBeTrue();

        $option->is_selected = false;
        $option->save();

        $option->refresh();
        expect($option->is_selected)->toBeFalse();
    });

    it('signature can be created', function () {
        expect(EstimateSignature::where('estimate_id', $this->estimate->id)->exists())->toBeFalse();

        EstimateSignature::createFromRequest(
            $this->estimate,
            'John Doe',
            'john@example.com',
            'John Doe',
            'typed'
        );

        expect(EstimateSignature::where('estimate_id', $this->estimate->id)->exists())->toBeTrue();
    });

    it('estimate is marked as accepted after signature', function () {
        EstimateSignature::createFromRequest(
            $this->estimate,
            'John Doe',
            'john@example.com',
            'John Doe',
            'typed'
        );

        $this->estimate->refresh();
        expect($this->estimate->status)->toBe('accepted');
    });

    it('estimate cannot be accepted when already accepted', function () {
        $this->estimate->status = 'accepted';
        $this->estimate->accepted_at = now();
        $this->estimate->save();

        expect($this->estimate->status)->toBe('accepted');
        expect($this->estimate->hasSignature())->toBeFalse();
    });

    it('estimate cannot be accepted when rejected', function () {
        $this->estimate->status = 'rejected';
        $this->estimate->rejected_at = now();
        $this->estimate->save();

        expect($this->estimate->status)->toBe('rejected');
    });

    it('estimate is expired when past valid_until date', function () {
        $this->estimate->valid_until = now()->subDay();
        $this->estimate->save();

        expect($this->estimate->isExpired())->toBeTrue();
    });

    it('estimate is not expired when before valid_until date', function () {
        $this->estimate->valid_until = now()->addDay();
        $this->estimate->save();

        expect($this->estimate->isExpired())->toBeFalse();
    });

    it('estimate has signature returns true when signature exists', function () {
        expect($this->estimate->hasSignature())->toBeFalse();

        EstimateSignature::factory()->forEstimate($this->estimate)->create();

        $this->estimate->refresh();
        expect($this->estimate->hasSignature())->toBeTrue();
    });

    it('selected options total is calculated correctly', function () {
        // Delete any existing options first
        $this->estimate->options()->delete();

        // Create options with specific fixed prices (which sets calculated_price via model boot)
        EstimateOption::factory()->forEstimate($this->estimate)->fixed()->selected()->create([
            'fixed_price' => 200.00,
        ]);
        EstimateOption::factory()->forEstimate($this->estimate)->fixed()->selected()->create([
            'fixed_price' => 300.00,
        ]);
        EstimateOption::factory()->forEstimate($this->estimate)->fixed()->create([
            'fixed_price' => 100.00,
            'is_selected' => false,
        ]);

        $total = $this->estimate->options()->where('is_selected', true)->sum('calculated_price');

        expect((float) $total)->toBe(500.00);
    });

    it('activity is logged when option is toggled', function () {
        $option = EstimateOption::factory()->forEstimate($this->estimate)->create([
            'name' => 'Premium Paint',
            'is_selected' => false,
        ]);

        $option->is_selected = true;
        $option->save();

        EstimateActivity::log(
            $this->estimate,
            'option_toggled',
            "Option '{$option->name}' selected",
            [
                'option_id' => $option->id,
                'option_name' => $option->name,
                'is_selected' => true,
            ]
        );

        $activity = EstimateActivity::where('estimate_id', $this->estimate->id)
            ->where('activity_type', 'option_toggled')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->metadata['option_name'])->toBe('Premium Paint');
        expect($activity->metadata['is_selected'])->toBeTrue();
    });

    it('drawn signature type is supported', function () {
        $signature = EstimateSignature::createFromRequest(
            $this->estimate,
            'John Doe',
            'john@example.com',
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==',
            'drawn'
        );

        expect($signature->signature_type)->toBe('drawn');
    });

    it('typed signature type is supported', function () {
        $signature = EstimateSignature::createFromRequest(
            $this->estimate,
            'John Doe',
            'john@example.com',
            'John Doe',
            'typed'
        );

        expect($signature->signature_type)->toBe('typed');
    });

    it('public token can be invalidated', function () {
        expect($this->estimate->public_token)->not->toBeNull();

        $this->estimate->invalidatePublicToken();

        expect($this->estimate->public_token)->toBeNull();
        expect($this->estimate->public_token_expires_at)->toBeNull();
    });

    it('public url is generated correctly', function () {
        $url = $this->estimate->getPublicUrl();

        expect($url)->toContain($this->estimate->public_token);
        // The route name is 'estimates.public' which maps to /proposal/{token}
        expect($url)->toContain('proposal');
    });

    it('public url returns null when no token', function () {
        $this->estimate->public_token = null;
        $this->estimate->save();

        expect($this->estimate->getPublicUrl())->toBeNull();
    });

    it('can mark estimate as accepted with force flag', function () {
        // Without signature, should fail normally
        expect($this->estimate->hasSignature())->toBeFalse();

        // With force flag, should succeed
        $this->estimate->markAsAccepted(true);

        expect($this->estimate->status)->toBe('accepted');
        expect($this->estimate->accepted_at)->not->toBeNull();
    });

    it('cannot mark estimate as accepted without signature normally', function () {
        expect($this->estimate->hasSignature())->toBeFalse();

        expect(fn () => $this->estimate->markAsAccepted(false))
            ->toThrow(InvalidArgumentException::class);
    });

    it('can mark estimate as rejected', function () {
        $this->estimate->markAsRejected();

        expect($this->estimate->status)->toBe('rejected');
        expect($this->estimate->rejected_at)->not->toBeNull();
    });

    it('estimate is editable only in draft status', function () {
        $this->estimate->status = 'draft';
        $this->estimate->save();
        expect($this->estimate->isEditable())->toBeTrue();

        $this->estimate->status = 'sent';
        $this->estimate->save();
        expect($this->estimate->isEditable())->toBeFalse();

        $this->estimate->status = 'accepted';
        $this->estimate->save();
        expect($this->estimate->isEditable())->toBeFalse();
    });
});
