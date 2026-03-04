<?php

use App\Events\DealNoteAdded;
use App\Events\DealUpdated;
use App\Models\Deal;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->deal = Deal::factory()->create();
});

describe('Deal events implement ShouldBroadcast', function () {

    it('DealUpdated implements ShouldBroadcast', function () {
        $event = new DealUpdated($this->deal, 'John', 'Title changed');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('DealNoteAdded implements ShouldBroadcast', function () {
        $event = new DealNoteAdded($this->deal->id, 'John', 'note');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });
});

describe('Deal events have correct broadcastOn channels', function () {

    it('DealUpdated broadcasts on deal channel', function () {
        $event = new DealUpdated($this->deal);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-deal.'.$this->deal->id);
    });

    it('DealNoteAdded broadcasts on deal channel', function () {
        $event = new DealNoteAdded($this->deal->id);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-deal.'.$this->deal->id);
    });

    it('all deal event channels are PrivateChannel instances', function () {
        $events = [
            new DealUpdated($this->deal),
            new DealNoteAdded($this->deal->id),
        ];

        foreach ($events as $event) {
            foreach ($event->broadcastOn() as $channel) {
                expect($channel)->toBeInstanceOf(PrivateChannel::class);
            }
        }
    });
});

describe('Deal events have correct broadcastAs names', function () {

    it('DealUpdated broadcasts as deal.updated', function () {
        $event = new DealUpdated($this->deal);

        expect($event->broadcastAs())->toBe('deal.updated');
    });

    it('DealNoteAdded broadcasts as deal.note.added', function () {
        $event = new DealNoteAdded($this->deal->id);

        expect($event->broadcastAs())->toBe('deal.note.added');
    });
});

describe('Deal event payloads are correct', function () {

    it('DealUpdated broadcastWith contains correct keys', function () {
        $event = new DealUpdated($this->deal, 'John Doe', 'Stage updated');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['deal_id', 'updated_by', 'changes_summary']);
        expect($payload['deal_id'])->toBe($this->deal->id);
        expect($payload['updated_by'])->toBe('John Doe');
        expect($payload['changes_summary'])->toBe('Stage updated');
    });

    it('DealNoteAdded broadcastWith contains correct keys', function () {
        $event = new DealNoteAdded($this->deal->id, 'Jane', 'task');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['deal_id', 'actor_name', 'note_type']);
        expect($payload['deal_id'])->toBe($this->deal->id);
        expect($payload['actor_name'])->toBe('Jane');
        expect($payload['note_type'])->toBe('task');
    });
});

describe('Deal event payloads are under 1KB', function () {

    it('all deal event payloads are under 1KB', function () {
        $events = [
            new DealUpdated($this->deal, 'John Doe', 'Updated stage and notes'),
            new DealNoteAdded($this->deal->id, 'Jane', 'note'),
        ];

        foreach ($events as $event) {
            $payload = json_encode($event->broadcastWith());
            expect(strlen($payload))->toBeLessThan(1024);
        }
    });
});

describe('Deal events can be dispatched with Event::fake()', function () {

    it('DealUpdated can be faked and dispatched', function () {
        Event::fake([DealUpdated::class]);

        DealUpdated::dispatch($this->deal, 'John', 'Title changed');

        Event::assertDispatched(DealUpdated::class, function ($event) {
            return $event->deal->id === $this->deal->id
                && $event->updatedBy === 'John'
                && $event->changesSummary === 'Title changed';
        });
    });

    it('DealNoteAdded can be faked and dispatched', function () {
        Event::fake([DealNoteAdded::class]);

        DealNoteAdded::dispatch($this->deal->id, 'Jane', 'task');

        Event::assertDispatched(DealNoteAdded::class, function ($event) {
            return $event->dealId === $this->deal->id
                && $event->actorName === 'Jane'
                && $event->noteType === 'task';
        });
    });
});
