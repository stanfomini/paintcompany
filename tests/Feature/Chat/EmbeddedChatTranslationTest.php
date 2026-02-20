<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Services\JobDispatchService;
use Livewire\Livewire;

describe('Crew Job Show - Translation Toggle', function () {
    it('shows translation toggle for Spanish text messages', function () {
        $crew = User::factory()->crew()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        // Dispatch and accept the job so the chat section renders
        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        $job->crews()->updateExistingPivot($crew->id, ['status' => 'accepted']);

        // Create deal chat thread with a Spanish message
        $thread = ChatThread::factory()->forDeal($job->deal)->create();
        $thread->addParticipant($crew);
        $thread->addParticipant($internalUser);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->spanishWithTranslation()
            ->create();

        app()->setLocale('en');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('See translation');
    });

    it('does not show translation toggle for same-language messages on crew page', function () {
        $crew = User::factory()->crew()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        $job->crews()->updateExistingPivot($crew->id, ['status' => 'accepted']);

        $thread = ChatThread::factory()->forDeal($job->deal)->create();
        $thread->addParticipant($crew);
        $thread->addParticipant($internalUser);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->englishWithTranslation()
            ->create();

        app()->setLocale('en');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertDontSee('See translation');
    });

    it('shows translation toggle for audio transcriptions on crew page', function () {
        $crew = User::factory()->crew()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        $job->crews()->updateExistingPivot($crew->id, ['status' => 'accepted']);

        $thread = ChatThread::factory()->forDeal($job->deal)->create();
        $thread->addParticipant($crew);
        $thread->addParticipant($internalUser);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->state(fn () => [
                'type' => ChatMessage::TYPE_AUDIO,
                'content' => 'Necesito más pintura',
                'metadata' => [
                    'audio_url' => 'https://example.com/audio.webm',
                    'audio_path' => 'chat-audio/test.webm',
                    'audio_duration' => 5.0,
                    'transcription' => 'Necesito más pintura',
                    'transcription_status' => 'completed',
                    'detected_lang' => 'es',
                    'translations' => [
                        'en' => 'I need more paint',
                    ],
                ],
            ])
            ->create();

        app()->setLocale('en');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('See translation');
    });
});

describe('Customer Job Progress - Translation Toggle', function () {
    it('shows translation toggle for Spanish text messages', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $thread = ChatThread::factory()->forDeal($deal)->create();
        $thread->addParticipant($customerUser);
        $thread->addParticipant($internalUser);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->spanishWithTranslation()
            ->create();

        app()->setLocale('en');

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('See translation');
    });

    it('does not show translation toggle for same-language messages on customer page', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $thread = ChatThread::factory()->forDeal($deal)->create();
        $thread->addParticipant($customerUser);
        $thread->addParticipant($internalUser);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->englishWithTranslation()
            ->create();

        app()->setLocale('en');

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertDontSee('See translation');
    });

    it('shows translation toggle for audio transcriptions on customer page', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $thread = ChatThread::factory()->forDeal($deal)->create();
        $thread->addParticipant($customerUser);
        $thread->addParticipant($internalUser);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->state(fn () => [
                'type' => ChatMessage::TYPE_AUDIO,
                'content' => 'Necesito más pintura',
                'metadata' => [
                    'audio_url' => 'https://example.com/audio.webm',
                    'audio_path' => 'chat-audio/test.webm',
                    'audio_duration' => 5.0,
                    'transcription' => 'Necesito más pintura',
                    'transcription_status' => 'completed',
                    'detected_lang' => 'es',
                    'translations' => [
                        'en' => 'I need more paint',
                    ],
                ],
            ])
            ->create();

        app()->setLocale('en');

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('See translation');
    });
});
