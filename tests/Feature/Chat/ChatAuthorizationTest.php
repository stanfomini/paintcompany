<?php

use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

describe('ChatThread view authorization', function () {
    it('allows internal user to view thread they are in', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        expect(Gate::forUser($internal)->allows('view', $thread))->toBeTrue();
    });

    it('prevents internal user from viewing thread they are not in', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($internal)->allows('view', $thread))->toBeFalse();
    });

    it('allows agent to view thread they are in', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('view', $thread))->toBeTrue();
    });

    it('prevents agent from viewing thread they are not in', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($otherAgent);

        expect(Gate::forUser($agent)->allows('view', $thread))->toBeFalse();
    });

    it('allows customer to view deal thread they are in', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('view', $thread))->toBeTrue();
    });

    it('prevents customer from viewing thread they are not in', function () {
        $customer = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($otherCustomer);

        expect(Gate::forUser($customer)->allows('view', $thread))->toBeFalse();
    });

    it('prevents user from viewing thread they have left', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->removeParticipant($user);

        expect(Gate::forUser($user)->allows('view', $thread))->toBeFalse();
    });
});

describe('ChatThread sendMessage authorization', function () {
    it('allows participant to send message', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        expect(Gate::forUser($user)->allows('sendMessage', $thread))->toBeTrue();
    });

    it('prevents non-participant from sending message', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($user)->allows('sendMessage', $thread))->toBeFalse();
    });

    it('prevents user who left from sending message', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->removeParticipant($user);

        expect(Gate::forUser($user)->allows('sendMessage', $thread))->toBeFalse();
    });
});

describe('ChatThread sendInternalMessage authorization', function () {
    it('allows internal user to send internal messages when participant', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        expect(Gate::forUser($internal)->allows('sendInternalMessage', $thread))->toBeTrue();
    });

    it('prevents internal user from sending internal messages when not participant', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($internal)->allows('sendInternalMessage', $thread))->toBeFalse();
    });

    it('prevents agent from sending internal messages', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('sendInternalMessage', $thread))->toBeFalse();
    });

    it('prevents customer from sending internal messages', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('sendInternalMessage', $thread))->toBeFalse();
    });
});

describe('ChatThread addParticipant authorization', function () {
    it('allows only internal users to add participants', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($internal)->allows('addParticipant', $thread))->toBeTrue();
    });

    it('prevents agents from adding participants', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('addParticipant', $thread))->toBeFalse();
    });

    it('prevents customers from adding participants', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('addParticipant', $thread))->toBeFalse();
    });
});

describe('ChatThread removeParticipant authorization', function () {
    it('allows only internal users to remove participants', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($internal)->allows('removeParticipant', $thread))->toBeTrue();
    });

    it('prevents agents from removing participants', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('removeParticipant', $thread))->toBeFalse();
    });

    it('prevents customers from removing participants', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('removeParticipant', $thread))->toBeFalse();
    });
});

describe('ChatThread update authorization', function () {
    it('allows internal user to update any thread they are in', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        expect(Gate::forUser($internal)->allows('update', $thread))->toBeTrue();
    });

    it('allows internal user to update thread they are not in', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($internal)->allows('update', $thread))->toBeTrue();
    });

    it('allows agent to update thread they are in', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('update', $thread))->toBeTrue();
    });

    it('prevents agent from updating thread they are not in', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($agent)->allows('update', $thread))->toBeFalse();
    });

    it('allows customer to update thread they are in', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('update', $thread))->toBeTrue();
    });

    it('prevents customer from updating thread they are not in', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($customer)->allows('update', $thread))->toBeFalse();
    });
});

describe('ChatThread delete authorization', function () {
    it('allows only internal users to delete threads', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($internal)->allows('delete', $thread))->toBeTrue();
    });

    it('prevents agents from deleting threads', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('delete', $thread))->toBeFalse();
    });

    it('prevents customers from deleting threads', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('delete', $thread))->toBeFalse();
    });
});

describe('ChatThread create authorization', function () {
    it('allows any authenticated user to create threads', function () {
        $internal = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        expect(Gate::forUser($internal)->allows('create', ChatThread::class))->toBeTrue();
        expect(Gate::forUser($agent)->allows('create', ChatThread::class))->toBeTrue();
        expect(Gate::forUser($customer)->allows('create', ChatThread::class))->toBeTrue();
    });
});

describe('ChatThread viewAny authorization', function () {
    it('allows any authenticated user to view their threads list', function () {
        $internal = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        expect(Gate::forUser($internal)->allows('viewAny', ChatThread::class))->toBeTrue();
        expect(Gate::forUser($agent)->allows('viewAny', ChatThread::class))->toBeTrue();
        expect(Gate::forUser($customer)->allows('viewAny', ChatThread::class))->toBeTrue();
    });
});

describe('Authorization integration scenarios', function () {
    it('internal user can view, send, update thread', function () {
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        expect(Gate::forUser($internal)->allows('view', $thread))->toBeTrue();
        expect(Gate::forUser($internal)->allows('sendMessage', $thread))->toBeTrue();
        expect(Gate::forUser($internal)->allows('sendInternalMessage', $thread))->toBeTrue();
        expect(Gate::forUser($internal)->allows('update', $thread))->toBeTrue();
        expect(Gate::forUser($internal)->allows('addParticipant', $thread))->toBeTrue();
        expect(Gate::forUser($internal)->allows('removeParticipant', $thread))->toBeTrue();
        expect(Gate::forUser($internal)->allows('delete', $thread))->toBeTrue();
    });

    it('agent has limited permissions as participant', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        expect(Gate::forUser($agent)->allows('view', $thread))->toBeTrue();
        expect(Gate::forUser($agent)->allows('sendMessage', $thread))->toBeTrue();
        expect(Gate::forUser($agent)->allows('sendInternalMessage', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('update', $thread))->toBeTrue();
        expect(Gate::forUser($agent)->allows('addParticipant', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('removeParticipant', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('delete', $thread))->toBeFalse();
    });

    it('customer has limited permissions as participant', function () {
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        expect(Gate::forUser($customer)->allows('view', $thread))->toBeTrue();
        expect(Gate::forUser($customer)->allows('sendMessage', $thread))->toBeTrue();
        expect(Gate::forUser($customer)->allows('sendInternalMessage', $thread))->toBeFalse();
        expect(Gate::forUser($customer)->allows('update', $thread))->toBeTrue();
        expect(Gate::forUser($customer)->allows('addParticipant', $thread))->toBeFalse();
        expect(Gate::forUser($customer)->allows('removeParticipant', $thread))->toBeFalse();
        expect(Gate::forUser($customer)->allows('delete', $thread))->toBeFalse();
    });

    it('non-participant has no permissions', function () {
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();

        expect(Gate::forUser($agent)->allows('view', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('sendMessage', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('sendInternalMessage', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('update', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('addParticipant', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('removeParticipant', $thread))->toBeFalse();
        expect(Gate::forUser($agent)->allows('delete', $thread))->toBeFalse();
    });
});
