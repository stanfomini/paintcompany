<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use App\Models\VideoRecording;
use Livewire\Livewire;

describe('Media Section Component', function () {
    it('renders with photos tab by default', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $deal = Deal::factory()->create(['property_id' => $property->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('shared.media-section', ['property' => $property, 'job' => $job, 'context' => 'job-internal'])
            ->assertStatus(200)
            ->assertSet('mediaTab', 'photos')
            ->assertSee('Photos')
            ->assertSee('Videos');
    });

    it('can switch to videos tab', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $deal = Deal::factory()->create(['property_id' => $property->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('shared.media-section', ['property' => $property, 'job' => $job, 'context' => 'job-internal'])
            ->call('switchToTab', 'videos')
            ->assertSet('mediaTab', 'videos');
    });

    it('shows video unviewed count badge', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $deal = Deal::factory()->create(['property_id' => $property->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        // Create a video recording linked to this job
        VideoRecording::factory()->ready()->create([
            'recordable_type' => Job::class,
            'recordable_id' => $job->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('shared.media-section', ['property' => $property, 'job' => $job, 'context' => 'job-internal']);

        expect($component->get('videoUnviewedCount'))->toBe(1);
    });

    it('marks videos viewed when switching to videos tab', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $deal = Deal::factory()->create(['property_id' => $property->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        VideoRecording::factory()->ready()->create([
            'recordable_type' => Job::class,
            'recordable_id' => $job->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('shared.media-section', ['property' => $property, 'job' => $job, 'context' => 'job-internal']);

        expect($component->get('videoUnviewedCount'))->toBe(1);

        $component->call('switchToTab', 'videos');

        expect($component->get('videoUnviewedCount'))->toBe(0);
    });

    it('works with null property', function () {
        $user = User::factory()->internal()->create();
        $job = Job::factory()->create();

        Livewire::actingAs($user)
            ->test('shared.media-section', ['job' => $job, 'context' => 'job-internal'])
            ->assertStatus(200)
            ->assertSet('mediaTab', 'photos')
            ->assertSee('No property linked');
    });

    it('works in readOnly mode', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $deal = Deal::factory()->create(['property_id' => $property->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('shared.media-section', ['property' => $property, 'job' => $job, 'context' => 'job-customer', 'readOnly' => true])
            ->assertStatus(200)
            ->assertSet('readOnly', true);
    });

    it('includes estimate videos for jobs with estimates', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $estimate = Estimate::factory()->create();
        $deal = Deal::factory()->create(['property_id' => $property->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id, 'estimate_id' => $estimate->id]);

        // Create a video linked to the estimate
        VideoRecording::factory()->ready()->create([
            'recordable_type' => Estimate::class,
            'recordable_id' => $estimate->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('shared.media-section', ['property' => $property, 'job' => $job, 'context' => 'job-internal']);

        // Video from estimate should be counted as unviewed
        expect($component->get('videoUnviewedCount'))->toBe(1);
    });
});
