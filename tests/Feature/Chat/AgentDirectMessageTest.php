<?php

use App\Models\ChatThread;
use App\Models\User;
use App\Services\ChatService;
use Livewire\Livewire;

describe('Agent direct message functionality', function () {
    it('agent can create a direct thread with internal user', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($agent, $internal, 'Agent question');

        expect($thread)->toBeInstanceOf(ChatThread::class);
        expect($thread->type)->toBe(ChatThread::TYPE_DIRECT);
        expect($thread->hasParticipant($agent))->toBeTrue();
        expect($thread->hasParticipant($internal))->toBeTrue();
    });

    it('agent create-thread component shows direct message and deal chat tabs', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent');

        Livewire::test('agent.chat.create-thread')
            ->call('openModal')
            ->assertSee('Direct Message')
            ->assertSee('Deal Chat');
    });

    it('agent can create direct thread via component', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $this->actingAs($agent, 'agent');

        Livewire::test('agent.chat.create-thread')
            ->call('openModal')
            ->set('type', 'direct')
            ->call('selectUser', $internal->id)
            ->set('subject', 'Quick question')
            ->call('createThread')
            ->assertRedirect();

        $thread = ChatThread::query()
            ->ofType(ChatThread::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $agent->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $internal->id))
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->subject)->toBe('Quick question');
    });

    it('agent can view direct thread', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($agent, $internal, 'Test DM');

        $this->actingAs($agent, 'agent');

        Livewire::test('agent.chat.thread-view', ['thread' => $thread])
            ->assertStatus(200);
    });

    it('agent thread list shows direct thread badge', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);
        $chatService->getOrCreateDirectThread($agent, $internal, 'My DM');

        $this->actingAs($agent, 'agent');

        Livewire::test('agent.chat.thread-list')
            ->assertSee('Direct');
    });

    it('agent create-thread shows available internal users for direct messages', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create(['name' => 'Jane Manager']);

        $this->actingAs($agent, 'agent');

        Livewire::test('agent.chat.create-thread')
            ->call('openModal')
            ->set('type', 'direct')
            ->assertSee('Jane Manager');
    });

    it('agent cannot create direct thread with another agent', function () {
        $agent1 = User::factory()->agent()->create();
        $agent2 = User::factory()->agent()->create();

        $chatService = app(ChatService::class);

        // Agents can't chat with other agents per the permission matrix
        expect($chatService->canChatWith($agent1, $agent2))->toBeFalse();
    });
});
