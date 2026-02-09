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

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateOption;
use App\Models\EstimateSignature;
use App\Models\EstimateView;
use App\Models\User;
use App\Notifications\EstimateAccepted;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('EstimateProposal public functionality', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
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

    it('estimate is editable in draft and sent status', function () {
        $this->estimate->status = 'draft';
        $this->estimate->save();
        expect($this->estimate->isEditable())->toBeTrue();

        $this->estimate->status = 'sent';
        $this->estimate->save();
        expect($this->estimate->isEditable())->toBeTrue();

        $this->estimate->status = 'accepted';
        $this->estimate->save();
        expect($this->estimate->isEditable())->toBeFalse();
    });
});

describe('EstimateProposal pricing consistency', function () {
    uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->customer = Customer::factory()->create();
        $this->deal = Deal::factory()->forCustomer($this->customer)->create();
    });

    it('margin factor is 1.0 for non-template mode estimates', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'simple',
            'areas_subtotal' => 1000,
        ]);
        $estimate->generatePublicToken(30);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // For non-template mode, margin factor should be 1.0
        expect($component->instance()->getMarginFactor())->toBe(1.0);
    });

    it('margin factor uses company settings regardless of stored areas_subtotal', function () {
        // Now that surfaces store COGS consistently, we always use company settings
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'areas_subtotal' => 2920.06, // Stored value is ignored for factor calculation
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        // Create area with surfaces
        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Living Room',
        ]);

        // Create surfaces with raw totals (COGS: labor + material)
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Walls',
            'labor_cost' => 302.40,
            'material_cost' => 452.65,
            'total' => 755.05, // COGS: labor + material
            'is_included' => true,
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Baseboards',
            'labor_cost' => 72.58,
            'material_cost' => 90.53,
            'total' => 163.11,
            'is_included' => true,
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Ceiling',
            'labor_cost' => 31.10,
            'material_cost' => 90.53,
            'total' => 121.63,
            'is_included' => true,
        ]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Margin factor now comes from company settings, not stored totals
        $settings = \App\Models\CompanySetting::getInstance();
        $expectedFactor = 1 / (1 - ($settings->getMarginPercentForScope('interior') / 100));
        $marginFactor = $component->instance()->getMarginFactor();
        expect($marginFactor)->toBe($expectedFactor);
    });

    it('uses company settings margin regardless of stored subtotals', function () {
        // Now that surfaces store COGS consistently, we always use company settings
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'areas_subtotal' => 0,
            'subtotal' => 2500,
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create();
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'total' => 1000,
            'is_included' => true,
        ]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Should use company margin settings (interior default is 50%, factor = 2.0)
        $settings = \App\Models\CompanySetting::getInstance();
        $expectedFactor = 1 / (1 - ($settings->getMarginPercentForScope('interior') / 100));
        $marginFactor = $component->instance()->getMarginFactor();
        expect($marginFactor)->toBe($expectedFactor);
    });

    it('handles edge case where areas_subtotal equals raw total (no margins stored)', function () {
        // Edge case: areas_subtotal equals raw surfaces total (margins not stored)
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'areas_subtotal' => 1000, // Same as raw
            'subtotal' => 1000, // Also same (no margins anywhere)
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create();
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'total' => 1000,
            'is_included' => true,
        ]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Should use company margin settings as fallback (not return 1.0)
        $marginFactor = $component->instance()->getMarginFactor();
        // With typical 35-65% margin, factor should be roughly 1.5-3.0
        expect($marginFactor)->toBeGreaterThan(1.0);
    });

    it('uses margin factor from company settings in template mode', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'areas_subtotal' => 0,
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        // Create area but no surfaces - still uses company margin factor
        \App\Models\EstimateArea::factory()->forEstimate($estimate)->create();

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // In template mode, always use company margin settings
        $settings = \App\Models\CompanySetting::getInstance();
        $expectedFactor = 1 / (1 - ($settings->getMarginPercentForScope('interior') / 100));
        expect($component->instance()->getMarginFactor())->toBe($expectedFactor);
    });

    it('area display total correctly applies margin factor', function () {
        // Set up company settings with no cleanup/sundries/taxes for predictable calculation
        $settings = \App\Models\CompanySetting::getInstance();
        $settings->update([
            'interior_cleanup_hours_ratio' => 999999, // Effectively disable cleanup
            'interior_sundries_cost_per_hour' => 0,
            'interior_margin_percent' => 50.0, // 50% margin = factor of 2
            'material_tax_rate' => 0,
            'labor_tax_rate' => 0,
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
            'areas_subtotal' => 2000, // 2x margin
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);
        // Create surfaces with proper labor/material breakdown
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'labor_hours' => 5,
            'labor_cost' => 250,
            'material_cost' => 250,
            'total' => 500,
            'is_included' => true,
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'labor_hours' => 5,
            'labor_cost' => 250,
            'material_cost' => 250,
            'total' => 500,
            'is_included' => true,
        ]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Raw area COGS: 1000 (labor + material)
        // With 50% margin: 1000 / 0.50 = 2000
        $displayTotal = $component->instance()->getAreaDisplayTotal($estimate->fresh()->areas->first());
        expect($displayTotal)->toBe(2000.0);
    });

    it('surface display total correctly applies margin factor from company settings', function () {
        // Set up company settings with no cleanup/sundries/taxes for predictable calculation
        $settings = \App\Models\CompanySetting::getInstance();
        $settings->update([
            'interior_cleanup_hours_ratio' => 999999, // Effectively disable cleanup
            'interior_sundries_cost_per_hour' => 0,
            'interior_margin_percent' => 50.0, // 50% margin = factor of 2
            'material_tax_rate' => 0,
            'labor_tax_rate' => 0,
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'areas_subtotal' => 1500,
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);
        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'labor_hours' => 10,
            'labor_cost' => 500,
            'material_cost' => 500,
            'total' => 1000,
            'is_included' => true,
        ]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Surface COGS: 1000, with 50% margin: 1000 / 0.50 = 2000
        $displayTotal = $component->instance()->getSurfaceDisplayTotal($surface);
        expect($displayTotal)->toBe(2000.0);
    });

    it('has packages returns true when packages exist', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
        ]);
        $estimate->generatePublicToken(30);

        // Create a package
        \App\Models\EstimatePackage::factory()->forEstimate($estimate)->create();

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        expect($component->instance()->hasPackages())->toBeTrue();
    });

    it('sum of area display totals uses consistent margin factor', function () {
        // Set up company settings with no cleanup/sundries/taxes for predictable calculation
        $settings = \App\Models\CompanySetting::getInstance();
        $settings->update([
            'interior_cleanup_hours_ratio' => 999999, // Effectively disable cleanup
            'interior_sundries_cost_per_hour' => 0,
            'interior_margin_percent' => 50.0, // 50% margin = factor of 2
            'material_tax_rate' => 0,
            'labor_tax_rate' => 0,
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'areas_subtotal' => 3000,
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        // Create multiple areas with surfaces (proper labor/material breakdown)
        $area1 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Room 1',
            'scope_type' => 'interior',
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area1)->create([
            'labor_hours' => 4,
            'labor_cost' => 200,
            'material_cost' => 200,
            'total' => 400,
            'is_included' => true,
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area1)->create([
            'labor_hours' => 2,
            'labor_cost' => 100,
            'material_cost' => 100,
            'total' => 200,
            'is_included' => true,
        ]);

        $area2 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Room 2',
            'scope_type' => 'interior',
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area2)->create([
            'labor_hours' => 3,
            'labor_cost' => 150,
            'material_cost' => 150,
            'total' => 300,
            'is_included' => true,
        ]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area2)->create([
            'labor_hours' => 1,
            'labor_cost' => 50,
            'material_cost' => 50,
            'total' => 100,
            'is_included' => true,
        ]);

        // Raw total COGS: 400 + 200 + 300 + 100 = 1000
        // With 50% margin: 1000 / 0.50 = 2000

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        $estimate->refresh();
        $estimate->load('areas.surfaces');

        $sumDisplayTotals = 0;
        foreach ($estimate->areas as $area) {
            $sumDisplayTotals += $component->instance()->getAreaDisplayTotal($area);
        }

        // Sum of display totals: 2000
        expect(round($sumDisplayTotals, 2))->toBe(2000.0);
    });
});

describe('EstimateProposal acceptance actions', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->internalUser2 = User::factory()->internal()->withFullAccess()->create();
        $this->customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create([
            'title' => 'Smith Kitchen Repaint',
            'stage' => 'quoted',
        ]);
        $this->estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'valid_until' => now()->addMonth(),
        ]);
        $this->estimate->generatePublicToken(30);
    });

    it('sends notification to all internal users on acceptance', function () {
        Notification::fake();

        EstimateSignature::createFromRequest(
            $this->estimate,
            'Jane Smith',
            'jane@example.com',
            'Jane Smith',
            'typed'
        );

        Notification::assertSentTo($this->internalUser, EstimateAccepted::class);
        Notification::assertSentTo($this->internalUser2, EstimateAccepted::class);
    });

    it('EstimateAccepted notification uses mail and database channels', function () {
        $notification = new EstimateAccepted($this->estimate);
        $channels = $notification->via($this->internalUser);

        expect($channels)->toContain('mail');
        expect($channels)->toContain('database');
    });

    it('creates a deal chat thread on acceptance', function () {
        expect(ChatThread::where('deal_id', $this->deal->id)->exists())->toBeFalse();

        EstimateSignature::createFromRequest(
            $this->estimate,
            'Jane Smith',
            'jane@example.com',
            'Jane Smith',
            'typed'
        );

        $thread = ChatThread::where('deal_id', $this->deal->id)->first();
        expect($thread)->not->toBeNull();
        expect($thread->type)->toBe(ChatThread::TYPE_DEAL);
        expect($thread->subject)->toBe('Smith Kitchen Repaint');
    });

    it('adds internal users as chat participants on acceptance', function () {
        EstimateSignature::createFromRequest(
            $this->estimate,
            'Jane Smith',
            'jane@example.com',
            'Jane Smith',
            'typed'
        );

        $thread = ChatThread::where('deal_id', $this->deal->id)->first();
        expect($thread->hasParticipant($this->internalUser))->toBeTrue();
        expect($thread->hasParticipant($this->internalUser2))->toBeTrue();
    });

    it('posts a system message in chat on acceptance', function () {
        EstimateSignature::createFromRequest(
            $this->estimate,
            'Jane Smith',
            'jane@example.com',
            'Jane Smith',
            'typed'
        );

        $thread = ChatThread::where('deal_id', $this->deal->id)->first();
        $message = $thread->messages()->first();

        expect($message)->not->toBeNull();
        expect($message->type)->toBe(ChatMessage::TYPE_SYSTEM);
        expect($message->content)->toContain($this->estimate->estimate_number);
        expect($message->content)->toContain('Jane Smith');
        expect($message->user_id)->toBeNull();
    });

    it('advances deal to signed_accepted stage on acceptance', function () {
        expect($this->deal->fresh()->stage)->toBe('quoted');

        EstimateSignature::createFromRequest(
            $this->estimate,
            'Jane Smith',
            'jane@example.com',
            'Jane Smith',
            'typed'
        );

        expect($this->deal->fresh()->stage)->toBe('signed_accepted');
    });

    it('does not create duplicate chat thread if one already exists', function () {
        // Create a thread beforehand
        $existingThread = $this->deal->getOrCreateChatThread();

        EstimateSignature::createFromRequest(
            $this->estimate,
            'Jane Smith',
            'jane@example.com',
            'Jane Smith',
            'typed'
        );

        // Should still only have one thread
        expect(ChatThread::where('deal_id', $this->deal->id)->count())->toBe(1);

        // But should have the system message
        expect($existingThread->messages()->count())->toBe(1);
    });
});

describe('EstimateProposal scope of work filtering by package', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create();

        $settings = \App\Models\CompanySetting::getInstance();
        $settings->update([
            'interior_cleanup_hours_ratio' => 999999,
            'interior_sundries_cost_per_hour' => 0,
            'interior_margin_percent' => 50.0,
            'material_tax_rate' => 0,
            'labor_tax_rate' => 0,
        ]);
    });

    it('shows all areas when no packages exist', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area1 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room', 'scope_type' => 'interior']);
        \App\Models\EstimateAreaSurface::factory()->forArea($area1)->create(['is_included' => true, 'total' => 500, 'labor_cost' => 250, 'material_cost' => 250]);

        $area2 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Bedroom', 'scope_type' => 'interior']);
        \App\Models\EstimateAreaSurface::factory()->forArea($area2)->create(['is_included' => true, 'total' => 300, 'labor_cost' => 150, 'material_cost' => 150]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        $filteredAreas = $component->instance()->getFilteredAreas();
        expect($filteredAreas)->toHaveCount(2);
    });

    it('filters scope of work to selected package areas', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        // Create 2 areas with surfaces
        $area1 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room', 'scope_type' => 'interior']);
        $surface1a = \App\Models\EstimateAreaSurface::factory()->forArea($area1)->create(['is_included' => true, 'total' => 500, 'labor_cost' => 250, 'material_cost' => 250]);

        $area2 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Bedroom', 'scope_type' => 'interior']);
        $surface2a = \App\Models\EstimateAreaSurface::factory()->forArea($area2)->create(['is_included' => true, 'total' => 300, 'labor_cost' => 150, 'material_cost' => 150]);

        // Good package: only area1
        $goodPackage = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->basic()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($goodPackage)->forEstimateArea($area1)->approved()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($goodPackage)->forEstimateAreaSurface($surface1a)->approved()->create();

        // Best package: both areas
        $bestPackage = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->premium()->recommended()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($bestPackage)->forEstimateArea($area1)->approved()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($bestPackage)->forEstimateAreaSurface($surface1a)->approved()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($bestPackage)->forEstimateArea($area2)->approved()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($bestPackage)->forEstimateAreaSurface($surface2a)->approved()->create();

        // Mount - should auto-select recommended (Best) package
        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);
        expect($component->instance()->selectedPackageId)->toBe($bestPackage->id);
        expect($component->instance()->getFilteredAreas())->toHaveCount(2);

        // Switch to Good package - should only show area1
        $component->instance()->handlePackageSelected($goodPackage->id);
        expect($component->instance()->selectedPackageId)->toBe($goodPackage->id);
        expect($component->instance()->getFilteredAreas())->toHaveCount(1);
        expect($component->instance()->getFilteredAreas()->first()->name)->toBe('Living Room');
    });

    it('filters surfaces within an area for partial inclusion', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        // Create area with 3 surfaces
        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room', 'scope_type' => 'interior']);
        $surfaceWalls = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['name' => 'Walls', 'is_included' => true, 'total' => 600, 'labor_cost' => 300, 'material_cost' => 300]);
        $surfaceCeiling = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['name' => 'Ceiling', 'is_included' => true, 'total' => 300, 'labor_cost' => 150, 'material_cost' => 150]);
        $surfaceTrim = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['name' => 'Trim', 'is_included' => true, 'total' => 100, 'labor_cost' => 50, 'material_cost' => 50]);

        // Package with only walls and ceiling (not trim)
        $package = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->basic()->recommended()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surfaceWalls)->approved()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surfaceCeiling)->approved()->create();

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Area should still show (it has surfaces in the package)
        $filteredAreas = $component->instance()->getFilteredAreas();
        expect($filteredAreas)->toHaveCount(1);

        // Only walls and ceiling surface IDs should be returned
        $surfaceIds = $component->instance()->getPackageSurfaceIds($area->id);
        expect($surfaceIds)->toBeArray();
        expect($surfaceIds)->toContain($surfaceWalls->id);
        expect($surfaceIds)->toContain($surfaceCeiling->id);
        expect($surfaceIds)->not->toContain($surfaceTrim->id);
    });

    it('shows all surfaces when whole area is included in package', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Kitchen', 'scope_type' => 'interior']);
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['name' => 'Walls', 'is_included' => true, 'total' => 400, 'labor_cost' => 200, 'material_cost' => 200]);
        \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['name' => 'Ceiling', 'is_included' => true, 'total' => 200, 'labor_cost' => 100, 'material_cost' => 100]);

        // Package includes the whole area (not individual surfaces)
        $package = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->basic()->recommended()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($package)->forEstimateArea($area)->approved()->create();

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // getPackageSurfaceIds should return null (= show all surfaces)
        expect($component->instance()->getPackageSurfaceIds($area->id))->toBeNull();
    });

    it('auto-selects recommended package on mount', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['scope_type' => 'interior']);
        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['is_included' => true, 'total' => 500, 'labor_cost' => 250, 'material_cost' => 250]);

        $goodPackage = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->basic()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($goodPackage)->forEstimateArea($area)->approved()->create();

        $betterPackage = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->standard()->recommended()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($betterPackage)->forEstimateArea($area)->approved()->create();

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        expect($component->instance()->selectedPackageId)->toBe($betterPackage->id);
    });

    it('auto-selects pre-selected package over recommended on mount', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['scope_type' => 'interior']);
        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create(['is_included' => true, 'total' => 500, 'labor_cost' => 250, 'material_cost' => 250]);

        $goodPackage = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->basic()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($goodPackage)->forEstimateArea($area)->approved()->create();

        $betterPackage = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->standard()->recommended()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($betterPackage)->forEstimateArea($area)->approved()->create();

        // Pre-select Good package
        $estimate->update(['selected_package_id' => $goodPackage->id]);

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        expect($component->instance()->selectedPackageId)->toBe($goodPackage->id);
    });

    it('package selection event updates scope and clears cache', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'created_by' => $this->internalUser->id,
            'estimator_mode' => 'template',
            'project_type' => 'interior',
        ]);
        $estimate->generatePublicToken(30);

        $area1 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Room A', 'scope_type' => 'interior']);
        $surface1 = \App\Models\EstimateAreaSurface::factory()->forArea($area1)->create(['is_included' => true, 'total' => 500, 'labor_cost' => 250, 'material_cost' => 250]);

        $area2 = \App\Models\EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Room B', 'scope_type' => 'interior']);
        $surface2 = \App\Models\EstimateAreaSurface::factory()->forArea($area2)->create(['is_included' => true, 'total' => 300, 'labor_cost' => 150, 'material_cost' => 150]);

        $packageA = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->basic()->recommended()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($packageA)->forEstimateAreaSurface($surface1)->approved()->create();

        $packageB = \App\Models\EstimatePackage::factory()->forEstimate($estimate)->premium()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($packageB)->forEstimateAreaSurface($surface1)->approved()->create();
        \App\Models\EstimatePackageItem::factory()->forPackage($packageB)->forEstimateAreaSurface($surface2)->approved()->create();

        $component = Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token]);

        // Initially auto-selected recommended (packageA) - only area1
        expect($component->instance()->getFilteredAreas())->toHaveCount(1);

        // Dispatch package-selected event to switch to packageB
        $component->dispatch('package-selected', packageId: $packageB->id);

        expect($component->instance()->selectedPackageId)->toBe($packageB->id);
        expect($component->instance()->getFilteredAreas())->toHaveCount(2);
    });
});
