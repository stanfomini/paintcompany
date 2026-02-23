<?php

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\User;

it('skips sending when user has no push subscriptions', function () {
    $user = User::factory()->create();
    $channel = new WebPushChannel;

    $notification = new class extends \Illuminate\Notifications\Notification
    {
        public function toWebPush($notifiable): WebPushMessage
        {
            return new WebPushMessage(title: 'Test', body: 'Test body');
        }
    };

    // Should not throw - just returns early
    $channel->send($user, $notification);

    expect(true)->toBeTrue();
});

it('creates WebPushMessage with correct defaults', function () {
    $message = new WebPushMessage(title: 'Hello', body: 'World');
    $array = $message->toArray();

    expect($array)->toMatchArray([
        'title' => 'Hello',
        'body' => 'World',
        'url' => '/',
        'icon' => '/icons/icon-192.png',
        'tag' => 'default',
    ]);
});

it('creates WebPushMessage with custom values', function () {
    $message = new WebPushMessage(
        title: 'Estimate Accepted',
        body: 'Jane accepted #EST-001',
        url: '/estimates/5',
        icon: '/icons/estimate.png',
        tag: 'estimate-5',
    );
    $array = $message->toArray();

    expect($array)->toMatchArray([
        'title' => 'Estimate Accepted',
        'body' => 'Jane accepted #EST-001',
        'url' => '/estimates/5',
        'icon' => '/icons/estimate.png',
        'tag' => 'estimate-5',
    ]);
});
