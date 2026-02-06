<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\User;
use Livewire\Livewire;

describe('ActivityTimeline component', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->internalUser->id,
        ]);
    });

    it('renders with activities', function () {
        EstimateActivity::factory()->forEstimate($this->estimate)->viewed()->create([
            'description' => 'Estimate was viewed',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate])
            ->assertStatus(200)
            ->assertSee('Estimate was viewed');
    });

    it('activities are ordered by created_at desc', function () {
        $olderActivity = EstimateActivity::factory()->forEstimate($this->estimate)->create([
            'description' => 'Older Activity',
            'created_at' => now()->subDays(2),
        ]);
        $newerActivity = EstimateActivity::factory()->forEstimate($this->estimate)->create([
            'description' => 'Newer Activity',
            'created_at' => now(),
        ]);
        $middleActivity = EstimateActivity::factory()->forEstimate($this->estimate)->create([
            'description' => 'Middle Activity',
            'created_at' => now()->subDay(),
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        $activities = $component->get('activities');
        expect($activities->first()->id)->toBe($newerActivity->id);
        expect($activities->last()->id)->toBe($olderActivity->id);
    });

    it('load more functionality works', function () {
        // Create 25 activities
        EstimateActivity::factory()->count(25)->forEstimate($this->estimate)->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        // Default limit is 20
        expect($component->get('limit'))->toBe(20);
        expect($component->get('activities'))->toHaveCount(20);

        // Load more
        $component->call('loadMore');

        expect($component->get('limit'))->toBe(40);
        expect($component->get('activities'))->toHaveCount(25);
    });

    it('has more property returns correct value', function () {
        // Create 25 activities
        EstimateActivity::factory()->count(25)->forEstimate($this->estimate)->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        // With 25 activities and limit of 20, hasMore should be true
        expect($component->instance()->getHasMoreProperty())->toBeTrue();

        // After loading all (limit goes to 40, but only 25 exist)
        $component->call('loadMore');

        // Need to get fresh instance after loadMore
        expect($component->instance()->getHasMoreProperty())->toBeFalse();
    });

    it('toggle show all functionality works', function () {
        // Create 30 activities
        EstimateActivity::factory()->count(30)->forEstimate($this->estimate)->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        expect($component->get('showAll'))->toBeFalse();
        expect($component->get('limit'))->toBe(20);

        // Toggle to show all
        $component->call('toggleShowAll');

        expect($component->get('showAll'))->toBeTrue();
        expect($component->get('limit'))->toBe(30);

        // Toggle back
        $component->call('toggleShowAll');

        expect($component->get('showAll'))->toBeFalse();
        expect($component->get('limit'))->toBe(20);
    });

    it('activity icons are mapped correctly', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        $instance = $component->instance();

        expect($instance->getActivityIcon('viewed'))->toBe('eye');
        expect($instance->getActivityIcon('sent'))->toBe('paper-airplane');
        expect($instance->getActivityIcon('accepted'))->toBe('check-circle');
        expect($instance->getActivityIcon('rejected'))->toBe('x-circle');
        expect($instance->getActivityIcon('option_toggled'))->toBe('adjustments-horizontal');
        expect($instance->getActivityIcon('note_added'))->toBe('chat-bubble-left');
        expect($instance->getActivityIcon('signature_added'))->toBe('pencil-square');
        expect($instance->getActivityIcon('photo_added'))->toBe('photo');
        expect($instance->getActivityIcon('unknown_type'))->toBe('information-circle');
    });

    it('activity colors are mapped correctly', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        $instance = $component->instance();

        expect($instance->getActivityColor('viewed'))->toBe('blue');
        expect($instance->getActivityColor('sent'))->toBe('indigo');
        expect($instance->getActivityColor('accepted'))->toBe('green');
        expect($instance->getActivityColor('rejected'))->toBe('red');
        expect($instance->getActivityColor('option_toggled'))->toBe('amber');
        expect($instance->getActivityColor('note_added'))->toBe('cyan');
        expect($instance->getActivityColor('signature_added'))->toBe('emerald');
        expect($instance->getActivityColor('photo_added'))->toBe('purple');
        expect($instance->getActivityColor('unknown_type'))->toBe('zinc');
    });

    it('shows empty state when no activities', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate])
            ->assertStatus(200);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        expect($component->get('activities'))->toHaveCount(0);
        expect($component->get('totalCount'))->toBe(0);
    });

    it('displays user name for activities with user', function () {
        $user = User::factory()->internal()->withFullAccess()->create(['name' => 'John Doe']);
        EstimateActivity::factory()->forEstimate($this->estimate)->byUser($user)->create([
            'description' => 'Test activity',
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        $activity = $component->get('activities')->first();
        $userName = $component->instance()->getActivityUserName($activity);

        expect($userName)->toBe('John Doe');
    });

    it('displays anonymous for activities without user', function () {
        EstimateActivity::factory()->forEstimate($this->estimate)->anonymous()->create([
            'description' => 'Anonymous activity',
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        $activity = $component->get('activities')->first();
        $userName = $component->instance()->getActivityUserName($activity);

        expect($userName)->toBe('Anonymous');
    });

    it('displays customer name from metadata when available', function () {
        EstimateActivity::factory()->forEstimate($this->estimate)->anonymous()->create([
            'description' => 'Customer viewed estimate',
            'metadata' => ['customer_name' => 'Jane Smith'],
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        $activity = $component->get('activities')->first();
        $userName = $component->instance()->getActivityUserName($activity);

        expect($userName)->toBe('Jane Smith');
    });

    it('refreshes on estimate-updated event', function () {
        EstimateActivity::factory()->forEstimate($this->estimate)->create([
            'description' => 'Original activity',
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        // Create a new activity after component mounted
        EstimateActivity::factory()->forEstimate($this->estimate)->create([
            'description' => 'New activity after mount',
        ]);

        // Dispatch the event
        $component->dispatch('estimate-updated');

        expect($component->get('activities'))->toHaveCount(2);
    });

    it('refreshes on options-updated event', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        EstimateActivity::factory()->forEstimate($this->estimate)->optionToggled()->create([
            'description' => 'Option toggled',
        ]);

        $component->dispatch('options-updated');

        expect($component->get('activities'))->toHaveCount(1);
    });

    it('refreshes on photos-updated event', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        EstimateActivity::factory()->forEstimate($this->estimate)->photoAdded()->create([
            'description' => 'Photo added',
        ]);

        $component->dispatch('photos-updated');

        expect($component->get('activities'))->toHaveCount(1);
    });

    it('refreshes on notes-updated event', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        EstimateActivity::factory()->forEstimate($this->estimate)->noteAdded()->create([
            'description' => 'Note added',
        ]);

        $component->dispatch('notes-updated');

        expect($component->get('activities'))->toHaveCount(1);
    });

    it('displays all activity types', function () {
        EstimateActivity::factory()->forEstimate($this->estimate)->viewed()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->sent()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->accepted()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->rejected()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->optionToggled()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->noteAdded()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->signatureAdded()->create();
        EstimateActivity::factory()->forEstimate($this->estimate)->photoAdded()->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        expect($component->get('activities'))->toHaveCount(8);
    });

    it('returns correct total count', function () {
        EstimateActivity::factory()->count(15)->forEstimate($this->estimate)->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.activity-timeline', ['estimate' => $this->estimate]);

        expect($component->get('totalCount'))->toBe(15);
    });
});
