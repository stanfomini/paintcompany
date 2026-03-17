<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateAiMessage;
use App\Models\EstimateAiThread;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('ai.estimate_builder');
    $this->deal = Deal::factory()->create();
    $this->estimate = Estimate::factory()->for($this->deal)->create();

    CompanySetting::firstOrCreate(
        ['id' => 1],
        ['company_name' => 'Test Company', 'company_email' => 'test@example.com']
    );
});

// =========================================================================
// THREAD CREATION
// =========================================================================

it('auto-creates a thread when sending the first message', function () {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->set('aiChatInput', 'Add a medium living room')
        ->call('sendAiChatMessage');

    expect(EstimateAiThread::where('estimate_id', $this->estimate->id)->count())->toBe(1);

    $thread = EstimateAiThread::where('estimate_id', $this->estimate->id)->first();
    expect($thread->name)->toBe('Thread 1');
    expect($thread->is_processing)->toBeTrue();

    // User message saved to DB
    expect($thread->messages()->where('role', 'user')->count())->toBe(1);
    expect($thread->messages()->first()->content)->toBe('Add a medium living room');
});

it('creates a named thread via createAiThread', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->call('createAiThread', 'Front of House')
        ->assertSet('activeThreadId', EstimateAiThread::first()->id);

    $thread = EstimateAiThread::where('estimate_id', $this->estimate->id)->first();
    expect($thread->name)->toBe('Front of House');
});

it('creates multiple threads', function () {
    $component = Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->call('createAiThread', 'Front of House')
        ->call('createAiThread', 'Left Side');

    expect(EstimateAiThread::where('estimate_id', $this->estimate->id)->count())->toBe(2);

    // Active thread should be the last one created
    $lastThread = EstimateAiThread::where('estimate_id', $this->estimate->id)->orderByDesc('id')->first();
    $component->assertSet('activeThreadId', $lastThread->id);
});

// =========================================================================
// THREAD SWITCHING
// =========================================================================

it('switches between threads', function () {
    $thread1 = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);
    $thread2 = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 2',
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->assertSet('activeThreadId', $thread2->id)
        ->call('switchThread', $thread1->id)
        ->assertSet('activeThreadId', $thread1->id);
});

// =========================================================================
// THREAD RENAME & DELETE
// =========================================================================

it('renames a thread', function () {
    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->call('renameThread', $thread->id, 'Kitchen Area');

    expect($thread->fresh()->name)->toBe('Kitchen Area');
});

it('deletes a thread and switches to another', function () {
    $thread1 = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);
    $thread2 = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 2',
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->assertSet('activeThreadId', $thread2->id)
        ->call('deleteThread', $thread2->id)
        ->assertSet('activeThreadId', $thread1->id);

    expect(EstimateAiThread::find($thread2->id))->toBeNull();
});

it('cannot delete a processing thread', function () {
    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
        'is_processing' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->call('deleteThread', $thread->id);

    expect(EstimateAiThread::find($thread->id))->not->toBeNull();
});

// =========================================================================
// MESSAGE SENDING
// =========================================================================

it('blocks sending to a processing thread', function () {
    Queue::fake();

    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
        'is_processing' => true,
        'cache_key' => 'test-key',
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->set('aiChatInput', 'Another message')
        ->call('sendAiChatMessage')
        ->assertSet('aiError', 'This thread is still processing. Switch to another thread or wait.');
});

it('dispatches job with thread id and history', function () {
    Queue::fake();

    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);

    // Add a prior message to test history
    EstimateAiMessage::create([
        'thread_id' => $thread->id,
        'role' => 'user',
        'content' => 'First message',
    ]);
    EstimateAiMessage::create([
        'thread_id' => $thread->id,
        'role' => 'assistant',
        'content' => 'First response',
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->set('aiChatInput', 'Make it bigger')
        ->call('sendAiChatMessage');

    Queue::assertPushed(\App\Jobs\ProcessEstimateAiChat::class, function ($job) use ($thread) {
        return $job->threadId === $thread->id
            && $job->userMessage === 'Make it bigger'
            && count($job->history) === 2
            && $job->history[0]['content'] === 'First message'
            && $job->history[1]['content'] === 'First response';
    });
});

// =========================================================================
// POLLING
// =========================================================================

it('polls and refreshes completed threads', function () {
    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
        'is_processing' => true,
        'cache_key' => 'test-cache-key',
    ]);

    EstimateAiMessage::create([
        'thread_id' => $thread->id,
        'role' => 'user',
        'content' => 'Add a room',
    ]);

    // Simulate job completion: assistant message written, cache updated
    EstimateAiMessage::create([
        'thread_id' => $thread->id,
        'role' => 'assistant',
        'content' => 'Added a living room!',
    ]);
    Cache::put('test-cache-key', ['status' => 'complete', 'response' => 'Added a living room!'], now()->addMinutes(10));

    $component = Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->call('pollAiResponse');

    // Cache should be forgotten after poll
    expect(Cache::get('test-cache-key'))->toBeNull();
});

// =========================================================================
// CLEAR THREAD
// =========================================================================

it('clears messages for active thread only', function () {
    $thread1 = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);
    $thread2 = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 2',
    ]);

    EstimateAiMessage::create(['thread_id' => $thread1->id, 'role' => 'user', 'content' => 'msg1']);
    EstimateAiMessage::create(['thread_id' => $thread2->id, 'role' => 'user', 'content' => 'msg2']);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate])
        ->call('switchThread', $thread1->id)
        ->call('clearAiThread');

    expect(EstimateAiMessage::where('thread_id', $thread1->id)->count())->toBe(0);
    expect(EstimateAiMessage::where('thread_id', $thread2->id)->count())->toBe(1);
});

// =========================================================================
// PERSISTENCE
// =========================================================================

it('loads existing threads on mount', function () {
    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Front of House',
    ]);
    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => 'Hello']);
    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'Hi!']);

    $component = Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['estimate' => $this->estimate]);

    $threads = $component->get('aiThreads');
    expect($threads)->toHaveCount(1);
    expect($threads->first()->name)->toBe('Front of House');
    expect($threads->first()->messages)->toHaveCount(2);
    $component->assertSet('activeThreadId', $thread->id);
});

// =========================================================================
// CASCADE DELETE
// =========================================================================

it('cascade deletes threads and messages when estimate is deleted', function () {
    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);
    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => 'msg']);

    $this->estimate->delete();

    expect(EstimateAiThread::count())->toBe(0);
    expect(EstimateAiMessage::count())->toBe(0);
});

// =========================================================================
// MODEL METHODS
// =========================================================================

it('returns conversation history excluding errors', function () {
    $thread = EstimateAiThread::create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Thread 1',
    ]);

    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => 'Hello']);
    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'Hi!']);
    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'error', 'content' => 'Something broke']);
    EstimateAiMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => 'Try again']);

    $history = $thread->getConversationHistory();

    expect($history)->toHaveCount(3);
    expect($history[0]['role'])->toBe('user');
    expect($history[1]['role'])->toBe('assistant');
    expect($history[2]['role'])->toBe('user');
});
