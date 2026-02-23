<?php

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Activity;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\EstimateAccepted;
use App\Notifications\EstimateRejected;
use App\Notifications\JobCreated;
use App\Notifications\MeetingProposed;
use App\Notifications\TaskAssigned;

it('EstimateAccepted returns valid WebPushMessage', function () {
    $estimate = Estimate::factory()->accepted()->create();
    $estimate->load('deal.customer');

    $notification = new EstimateAccepted($estimate);
    $user = User::factory()->internal()->create();

    $message = $notification->toWebPush($user);

    expect($message)->toBeInstanceOf(WebPushMessage::class);
    expect($message->title)->toBe('Estimate Accepted');
    expect($message->body)->toContain($estimate->estimate_number);
    expect($message->url)->toBe("/estimates/{$estimate->id}");
    expect($message->tag)->toBe("estimate-{$estimate->id}");
});

it('EstimateRejected returns valid WebPushMessage', function () {
    $estimate = Estimate::factory()->rejected()->create();
    $estimate->load('deal.customer');

    $notification = new EstimateRejected($estimate, 'Too expensive');
    $user = User::factory()->internal()->create();

    $message = $notification->toWebPush($user);

    expect($message)->toBeInstanceOf(WebPushMessage::class);
    expect($message->title)->toBe('Estimate Rejected');
    expect($message->body)->toContain($estimate->estimate_number);
    expect($message->url)->toBe("/estimates/{$estimate->id}");
    expect($message->tag)->toBe("estimate-{$estimate->id}");
});

it('JobCreated returns valid WebPushMessage', function () {
    $job = Job::factory()->create();

    $notification = new JobCreated($job);
    $user = User::factory()->internal()->create();

    $message = $notification->toWebPush($user);

    expect($message)->toBeInstanceOf(WebPushMessage::class);
    expect($message->title)->toBe('New Job Created');
    expect($message->body)->toContain($job->job_number);
    expect($message->url)->toBe("/jobs/{$job->id}");
    expect($message->tag)->toBe("job-{$job->id}");
});

it('TaskAssigned returns valid WebPushMessage', function () {
    $assigner = User::factory()->internal()->create(['name' => 'John Manager']);
    $activity = Activity::factory()->task()->byUser($assigner)->create();

    $notification = new TaskAssigned($activity);
    $assignee = User::factory()->internal()->create();

    $message = $notification->toWebPush($assignee);

    expect($message)->toBeInstanceOf(WebPushMessage::class);
    expect($message->title)->toBe('Task Assigned');
    expect($message->body)->toContain('John Manager');
    expect($message->body)->toContain($activity->title);
    expect($message->url)->toBe('/planner');
    expect($message->tag)->toBe("task-{$activity->id}");
});

it('MeetingProposed returns valid WebPushMessage', function () {
    $proposer = User::factory()->internal()->create(['name' => 'Jane Sales']);
    $activity = Activity::factory()->scheduledMeeting()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $user = User::factory()->internal()->create();

    $message = $notification->toWebPush($user);

    expect($message)->toBeInstanceOf(WebPushMessage::class);
    expect($message->title)->toBe('Meeting Proposed');
    expect($message->body)->toContain('Jane Sales');
    expect($message->url)->toBe('/dashboard');
    expect($message->tag)->toBe("meeting-{$activity->id}");
});

it('WebPushMessage toArray returns correct structure', function () {
    $message = new WebPushMessage(
        title: 'Test Title',
        body: 'Test body text',
        url: '/test/path',
        tag: 'test-tag',
    );

    $array = $message->toArray();

    expect($array)->toHaveKeys(['title', 'body', 'url', 'icon', 'tag']);
    expect($array['title'])->toBe('Test Title');
    expect($array['body'])->toBe('Test body text');
    expect($array['url'])->toBe('/test/path');
    expect($array['tag'])->toBe('test-tag');
    expect($array['icon'])->toBe('/icons/icon-192.png');
});

it('via includes WebPushChannel when user wants push', function () {
    $estimate = Estimate::factory()->accepted()->create();

    $notification = new EstimateAccepted($estimate);

    $user = User::factory()->internal()->create([
        'notification_preferences' => [
            'estimates' => ['email' => true, 'push' => true],
        ],
    ]);

    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'public_key' => 'test-public-key',
        'auth_token' => 'test-auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    $channels = $notification->via($user);

    expect($channels)->toContain('mail');
    expect($channels)->toContain(WebPushChannel::class);
    expect($channels)->toContain('database');
});

it('via excludes WebPushChannel when user disables push', function () {
    $estimate = Estimate::factory()->accepted()->create();

    $notification = new EstimateAccepted($estimate);

    $user = User::factory()->internal()->create([
        'notification_preferences' => [
            'estimates' => ['email' => true, 'push' => false],
        ],
    ]);

    $channels = $notification->via($user);

    expect($channels)->toContain('mail');
    expect($channels)->not->toContain(WebPushChannel::class);
    expect($channels)->toContain('database');
});

it('all notification classes have correct pushCategory', function () {
    $estimateNotifications = [
        \App\Notifications\EstimateAccepted::class,
        \App\Notifications\EstimateRejected::class,
        \App\Notifications\EstimateProposalSent::class,
        \App\Notifications\EstimateAmended::class,
        \App\Notifications\EstimatePackageUpdated::class,
        \App\Notifications\EstimateReadyForAgent::class,
        \App\Notifications\ProposalViewed::class,
        \App\Notifications\AgentPassedEstimate::class,
    ];

    foreach ($estimateNotifications as $class) {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('pushCategory');
        expect($property->getDefaultValue())->toBe('estimates', "{$class} should have pushCategory 'estimates'");
    }

    $chatNotifications = [
        \App\Notifications\NewSupportChatNotification::class,
        \App\Notifications\UnreadChatMessage::class,
    ];

    foreach ($chatNotifications as $class) {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('pushCategory');
        expect($property->getDefaultValue())->toBe('chat', "{$class} should have pushCategory 'chat'");
    }

    $taskNotifications = [
        \App\Notifications\TaskAssigned::class,
    ];

    foreach ($taskNotifications as $class) {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('pushCategory');
        expect($property->getDefaultValue())->toBe('tasks', "{$class} should have pushCategory 'tasks'");
    }

    $paymentNotifications = [
        \App\Notifications\PaymentReceived::class,
        \App\Notifications\PayStubAvailable::class,
        \App\Notifications\InvoiceSent::class,
    ];

    foreach ($paymentNotifications as $class) {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('pushCategory');
        expect($property->getDefaultValue())->toBe('payments', "{$class} should have pushCategory 'payments'");
    }

    $documentNotifications = [
        \App\Notifications\DocumentExpiring::class,
        \App\Notifications\DocumentRejected::class,
        \App\Notifications\DocumentUploadedForReview::class,
        \App\Notifications\ContractSentForSigning::class,
    ];

    foreach ($documentNotifications as $class) {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty('pushCategory');
        expect($property->getDefaultValue())->toBe('documents', "{$class} should have pushCategory 'documents'");
    }
});
