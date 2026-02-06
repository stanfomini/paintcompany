<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Services\ChatService;
use App\Services\JobDispatchService;
use Livewire\Livewire;

describe('Crew Chat Thread List', function () {
    it('renders thread list page', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-list')
            ->assertStatus(200)
            ->assertSee('Messages');
    });

    it('shows threads for jobs dispatched to crew', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create(['title' => 'Paint Job Alpha']);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        // Create a chat thread for this deal
        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        $chatService->sendMessage($thread, $internal, 'Hello crew!');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-list')
            ->assertSee('Hello crew!');
    });

    it('does not show threads for unrelated deals', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create(['title' => 'Unrelated Deal']);

        // No job dispatched to this crew
        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        $chatService->sendMessage($thread, $internal, 'Secret message');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-list')
            ->assertDontSee('Secret message');
    });

    it('shows empty state when no messages', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-list')
            ->assertSee('No Messages');
    });
});

describe('Crew Chat Thread View', function () {
    it('renders thread view for dispatched crew', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-view', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Back to Messages');
    });

    it('denies access to crew not dispatched to the job', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-view', ['thread' => $thread])
            ->assertNotFound();
    });

    it('denies access to non-deal threads', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_DIRECT,
            'subject' => 'Direct Message',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($internal);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-view', ['thread' => $thread])
            ->assertNotFound();
    });

    it('shows messages in thread', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        $chatService->sendMessage($thread, $internal, 'Welcome to the project!');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-view', ['thread' => $thread])
            ->assertSee('Welcome to the project!');
    });

    it('adds crew as participant on first view', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);

        expect($thread->hasParticipant($crew))->toBeFalse();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-view', ['thread' => $thread]);

        expect($thread->fresh()->hasParticipant($crew))->toBeTrue();
    });

    it('shows empty state when no messages', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_DEAL,
            'deal_id' => $deal->id,
            'subject' => $deal->title,
            'last_message_at' => now(),
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.thread-view', ['thread' => $thread])
            ->assertSee('No messages yet');
    });
});

describe('Crew Chat Message Composer', function () {
    it('renders message composer', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        $thread->addParticipant($crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Send');
    });

    it('can send a text message', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        $thread->addParticipant($crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->set('message', 'On my way to the job site!')
            ->call('send')
            ->assertDispatched('message-sent');

        expect($thread->messages()->where('content', 'On my way to the job site!')->exists())->toBeTrue();
    });

    it('validates message is required', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        $thread->addParticipant($crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->set('message', '')
            ->call('send')
            ->assertHasErrors(['message']);
    });

    it('prevents sending if not participant', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $deal = Deal::factory()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $internal);
        // Not adding crew as participant

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->set('message', 'Should fail')
            ->call('send')
            ->assertHasErrors(['message']);
    });
});

describe('Chat Permissions for Crew', function () {
    it('allows crew to chat with internal users', function () {
        $chatService = app(ChatService::class);
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);

        expect($chatService->canChatWith($crew, $internal))->toBeTrue();
    });

    it('does not allow crew to chat with agents', function () {
        $chatService = app(ChatService::class);
        $crew = User::factory()->crew()->create();
        $agent = User::factory()->agent()->create();

        expect($chatService->canChatWith($crew, $agent))->toBeFalse();
    });

    it('does not allow crew to chat with customers', function () {
        $chatService = app(ChatService::class);
        $crew = User::factory()->crew()->create();
        $customer = User::factory()->customer()->create();

        expect($chatService->canChatWith($crew, $customer))->toBeFalse();
    });

    it('allows internal to chat with crew', function () {
        $chatService = app(ChatService::class);
        $internal = User::factory()->withFullAccess()->create(['type' => 'internal']);
        $crew = User::factory()->crew()->create();

        expect($chatService->canChatWith($internal, $crew))->toBeTrue();
    });
});
