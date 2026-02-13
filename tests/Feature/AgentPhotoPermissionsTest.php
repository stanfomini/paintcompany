<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\PhotoService;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent Photo Permissions', function () {
    it('allows agent to upload photos to their listing property', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        $photoService = app(PhotoService::class);

        expect($photoService->canUpload($agent, $property))->toBeTrue();
    });

    it('allows agent to upload photos to property linked via deal', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->create();
        Deal::factory()->referredByAgent($agent)->forProperty($property)->create();

        $photoService = app(PhotoService::class);

        expect($photoService->canUpload($agent, $property))->toBeTrue();
    });

    it('denies agent upload to unrelated property', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->create();

        $photoService = app(PhotoService::class);

        expect($photoService->canUpload($agent, $property))->toBeFalse();
    });

    it('allows agent to annotate photos on their property', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $photo = PropertyPhoto::factory()->for($property)->create();

        $photoService = app(PhotoService::class);

        expect($photoService->canAnnotate($agent, $photo))->toBeTrue();
    });

    it('allows agent to comment on photos on their property', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $photo = PropertyPhoto::factory()->for($property)->create();

        $photoService = app(PhotoService::class);

        expect($photoService->canComment($agent, $photo))->toBeTrue();
    });

    it('returns allowed upload categories for agents', function () {
        $agent = User::factory()->agent()->create();

        $photoService = app(PhotoService::class);
        $categories = $photoService->getAllowedUploadCategories($agent);

        expect($categories)->toBe(['before', 'reference', 'general']);
    });

    it('shows upload button on agent estimate photos tab', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();
        $estimate = Estimate::factory()->for($deal)->create(['status' => 'draft']);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('activeTab', 'photos')
            ->assertSee('Upload Photos');
    });

    it('denies annotate and comment for agent on unrelated property', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->create();
        $photo = PropertyPhoto::factory()->for($property)->create();

        $photoService = app(PhotoService::class);

        expect($photoService->canAnnotate($agent, $photo))->toBeFalse();
        expect($photoService->canComment($agent, $photo))->toBeFalse();
    });
});
