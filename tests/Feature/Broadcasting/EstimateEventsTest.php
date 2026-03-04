<?php

use App\Events\EstimateAreaUpdated;
use App\Events\EstimateNoteAdded;
use App\Events\EstimateOptionsToggled;
use App\Events\EstimatePhotoUpdated;
use App\Events\EstimateSignatureAdded;
use App\Events\EstimateStatusChanged;
use App\Events\EstimateVideoUpdated;
use App\Models\Estimate;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->estimate = Estimate::factory()->create();
});

describe('Estimate events implement ShouldBroadcast', function () {

    it('EstimateAreaUpdated implements ShouldBroadcast', function () {
        $event = new EstimateAreaUpdated($this->estimate->id, 1, 'created', 'John');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('EstimatePhotoUpdated implements ShouldBroadcast', function () {
        $event = new EstimatePhotoUpdated($this->estimate->id, 1, 'uploaded', 'John');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('EstimateVideoUpdated implements ShouldBroadcast', function () {
        $event = new EstimateVideoUpdated($this->estimate->id, 1, 'completed', 'John');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('EstimateOptionsToggled implements ShouldBroadcast', function () {
        $event = new EstimateOptionsToggled($this->estimate->id, 1, true, 'Jane');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('EstimateSignatureAdded implements ShouldBroadcast', function () {
        $event = new EstimateSignatureAdded($this->estimate, 'Jane Doe', now()->toIso8601String());

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('EstimateNoteAdded implements ShouldBroadcast', function () {
        $event = new EstimateNoteAdded($this->estimate->id, 'internal', 'John');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('EstimateStatusChanged implements ShouldBroadcast', function () {
        $event = new EstimateStatusChanged($this->estimate, 'draft', 'sent');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });
});

describe('Estimate events have correct broadcastOn channels', function () {

    it('EstimateAreaUpdated broadcasts on estimate channel', function () {
        $event = new EstimateAreaUpdated($this->estimate->id, 1, 'created');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-estimate.'.$this->estimate->id);
    });

    it('EstimatePhotoUpdated broadcasts on estimate channel', function () {
        $event = new EstimatePhotoUpdated($this->estimate->id, 5, 'uploaded');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-estimate.'.$this->estimate->id);
    });

    it('EstimateVideoUpdated broadcasts on estimate channel', function () {
        $event = new EstimateVideoUpdated($this->estimate->id, 3, 'completed');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-estimate.'.$this->estimate->id);
    });

    it('EstimateOptionsToggled broadcasts on estimate channel', function () {
        $event = new EstimateOptionsToggled($this->estimate->id, 2, true);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-estimate.'.$this->estimate->id);
    });

    it('EstimateSignatureAdded broadcasts on estimate and deal channels', function () {
        $estimate = Estimate::factory()->create(['deal_id' => \App\Models\Deal::factory()]);
        $event = new EstimateSignatureAdded($estimate, 'Jane', now()->toIso8601String());
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(2);
        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-estimate.'.$estimate->id)
            ->toContain('private-deal.'.$estimate->deal_id);
    });

    it('EstimateSignatureAdded broadcasts only estimate channel when no deal', function () {
        $estimate = Estimate::factory()->create();
        // Simulate no deal by setting deal_id to null on the model (without saving)
        $estimate->deal_id = null;
        $event = new EstimateSignatureAdded($estimate, 'Jane', now()->toIso8601String());
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-estimate.'.$estimate->id);
    });

    it('EstimateNoteAdded broadcasts on estimate channel', function () {
        $event = new EstimateNoteAdded($this->estimate->id, 'internal');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-estimate.'.$this->estimate->id);
    });

    it('EstimateStatusChanged broadcasts on estimate, internal-team, and deal channels', function () {
        $estimate = Estimate::factory()->create(['deal_id' => \App\Models\Deal::factory()]);
        $event = new EstimateStatusChanged($estimate, 'draft', 'sent');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(3);
        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-estimate.'.$estimate->id)
            ->toContain('private-internal-team')
            ->toContain('private-deal.'.$estimate->deal_id);
    });

    it('all estimate event channels are PrivateChannel instances', function () {
        $events = [
            new EstimateAreaUpdated($this->estimate->id, 1, 'created'),
            new EstimatePhotoUpdated($this->estimate->id, 1, 'uploaded'),
            new EstimateVideoUpdated($this->estimate->id, 1, 'completed'),
            new EstimateOptionsToggled($this->estimate->id, 1, true),
            new EstimateSignatureAdded($this->estimate, 'Jane', now()->toIso8601String()),
            new EstimateNoteAdded($this->estimate->id, 'internal'),
            new EstimateStatusChanged($this->estimate, 'draft', 'sent'),
        ];

        foreach ($events as $event) {
            foreach ($event->broadcastOn() as $channel) {
                expect($channel)->toBeInstanceOf(PrivateChannel::class);
            }
        }
    });
});

describe('Estimate events have correct broadcastAs names', function () {

    it('EstimateAreaUpdated broadcasts as estimate.area.updated', function () {
        $event = new EstimateAreaUpdated($this->estimate->id, 1, 'created');

        expect($event->broadcastAs())->toBe('estimate.area.updated');
    });

    it('EstimatePhotoUpdated broadcasts as estimate.photo.updated', function () {
        $event = new EstimatePhotoUpdated($this->estimate->id, 1, 'uploaded');

        expect($event->broadcastAs())->toBe('estimate.photo.updated');
    });

    it('EstimateVideoUpdated broadcasts as estimate.video.updated', function () {
        $event = new EstimateVideoUpdated($this->estimate->id, 1, 'completed');

        expect($event->broadcastAs())->toBe('estimate.video.updated');
    });

    it('EstimateOptionsToggled broadcasts as estimate.options.toggled', function () {
        $event = new EstimateOptionsToggled($this->estimate->id, 1, true);

        expect($event->broadcastAs())->toBe('estimate.options.toggled');
    });

    it('EstimateSignatureAdded broadcasts as estimate.signature.added', function () {
        $event = new EstimateSignatureAdded($this->estimate, 'Jane', now()->toIso8601String());

        expect($event->broadcastAs())->toBe('estimate.signature.added');
    });

    it('EstimateNoteAdded broadcasts as estimate.note.added', function () {
        $event = new EstimateNoteAdded($this->estimate->id, 'internal');

        expect($event->broadcastAs())->toBe('estimate.note.added');
    });

    it('EstimateStatusChanged broadcasts as estimate.status.changed', function () {
        $event = new EstimateStatusChanged($this->estimate, 'draft', 'sent');

        expect($event->broadcastAs())->toBe('estimate.status.changed');
    });
});

describe('Estimate event payloads are correct', function () {

    it('EstimateAreaUpdated broadcastWith contains correct keys', function () {
        $event = new EstimateAreaUpdated($this->estimate->id, 42, 'updated', 'John');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'area_id', 'action', 'actor_name']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['area_id'])->toBe(42);
        expect($payload['action'])->toBe('updated');
        expect($payload['actor_name'])->toBe('John');
    });

    it('EstimatePhotoUpdated broadcastWith contains correct keys', function () {
        $event = new EstimatePhotoUpdated($this->estimate->id, 10, 'deleted', 'Jane');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'photo_id', 'action', 'actor_name']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['photo_id'])->toBe(10);
        expect($payload['action'])->toBe('deleted');
        expect($payload['actor_name'])->toBe('Jane');
    });

    it('EstimateVideoUpdated broadcastWith contains correct keys', function () {
        $event = new EstimateVideoUpdated($this->estimate->id, 7, 'processing', 'Bob');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'recording_id', 'status', 'actor_name']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['recording_id'])->toBe(7);
        expect($payload['status'])->toBe('processing');
        expect($payload['actor_name'])->toBe('Bob');
    });

    it('EstimateOptionsToggled broadcastWith contains correct keys', function () {
        $event = new EstimateOptionsToggled($this->estimate->id, 3, false, 'Customer');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'option_id', 'selected', 'customer_name']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['option_id'])->toBe(3);
        expect($payload['selected'])->toBeFalse();
        expect($payload['customer_name'])->toBe('Customer');
    });

    it('EstimateSignatureAdded broadcastWith contains correct keys', function () {
        $signedAt = now()->toIso8601String();
        $event = new EstimateSignatureAdded($this->estimate, 'Jane Doe', $signedAt);
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'signer_name', 'signed_at']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['signer_name'])->toBe('Jane Doe');
        expect($payload['signed_at'])->toBe($signedAt);
    });

    it('EstimateNoteAdded broadcastWith contains correct keys', function () {
        $event = new EstimateNoteAdded($this->estimate->id, 'external', 'Alice');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'note_type', 'actor_name']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['note_type'])->toBe('external');
        expect($payload['actor_name'])->toBe('Alice');
    });

    it('EstimateStatusChanged broadcastWith contains correct keys', function () {
        $event = new EstimateStatusChanged($this->estimate, 'sent', 'accepted');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['estimate_id', 'estimate_number', 'old_status', 'new_status']);
        expect($payload['estimate_id'])->toBe($this->estimate->id);
        expect($payload['old_status'])->toBe('sent');
        expect($payload['new_status'])->toBe('accepted');
    });
});

describe('Estimate event payloads are under 1KB', function () {

    it('all estimate event payloads are under 1KB', function () {
        $events = [
            new EstimateAreaUpdated($this->estimate->id, 1, 'created', 'John Doe'),
            new EstimatePhotoUpdated($this->estimate->id, 1, 'uploaded', 'John Doe'),
            new EstimateVideoUpdated($this->estimate->id, 1, 'completed', 'John Doe'),
            new EstimateOptionsToggled($this->estimate->id, 1, true, 'Customer Name'),
            new EstimateSignatureAdded($this->estimate, 'Jane Doe', now()->toIso8601String()),
            new EstimateNoteAdded($this->estimate->id, 'internal', 'John Doe'),
            new EstimateStatusChanged($this->estimate, 'draft', 'sent'),
        ];

        foreach ($events as $event) {
            $payload = json_encode($event->broadcastWith());
            expect(strlen($payload))->toBeLessThan(1024);
        }
    });
});

describe('Estimate events can be dispatched with Event::fake()', function () {

    it('EstimateAreaUpdated can be faked and dispatched', function () {
        Event::fake([EstimateAreaUpdated::class]);

        EstimateAreaUpdated::dispatch($this->estimate->id, 5, 'updated', 'John');

        Event::assertDispatched(EstimateAreaUpdated::class, function ($event) {
            return $event->estimateId === $this->estimate->id
                && $event->areaId === 5
                && $event->action === 'updated'
                && $event->actorName === 'John';
        });
    });

    it('EstimatePhotoUpdated can be faked and dispatched', function () {
        Event::fake([EstimatePhotoUpdated::class]);

        EstimatePhotoUpdated::dispatch($this->estimate->id, 10, 'deleted', 'Jane');

        Event::assertDispatched(EstimatePhotoUpdated::class, function ($event) {
            return $event->estimateId === $this->estimate->id
                && $event->photoId === 10
                && $event->action === 'deleted';
        });
    });

    it('EstimateOptionsToggled can be faked and dispatched', function () {
        Event::fake([EstimateOptionsToggled::class]);

        EstimateOptionsToggled::dispatch($this->estimate->id, 3, true, 'Customer');

        Event::assertDispatched(EstimateOptionsToggled::class, function ($event) {
            return $event->estimateId === $this->estimate->id
                && $event->optionId === 3
                && $event->selected === true;
        });
    });

    it('EstimateSignatureAdded can be faked and dispatched', function () {
        Event::fake([EstimateSignatureAdded::class]);

        $signedAt = now()->toIso8601String();
        EstimateSignatureAdded::dispatch($this->estimate, 'Jane Doe', $signedAt);

        Event::assertDispatched(EstimateSignatureAdded::class, function ($event) {
            return $event->estimate->id === $this->estimate->id
                && $event->signerName === 'Jane Doe';
        });
    });

    it('EstimateStatusChanged can be faked and dispatched', function () {
        Event::fake([EstimateStatusChanged::class]);

        EstimateStatusChanged::dispatch($this->estimate, 'draft', 'sent');

        Event::assertDispatched(EstimateStatusChanged::class, function ($event) {
            return $event->estimate->id === $this->estimate->id
                && $event->oldStatus === 'draft'
                && $event->newStatus === 'sent';
        });
    });
});
