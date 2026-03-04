<?php

namespace App\Channels;

use App\Models\EmailMessage;
use App\Models\PushSubscription;
use App\Services\ChatService;
use Illuminate\Notifications\Notification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $subscriptions = $notifiable->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        /** @var WebPushMessage $message */
        $message = $notification->toWebPush($notifiable);
        $data = $message->toArray();
        $data['badge_count'] = $this->calculateBadgeCount($notifiable);
        $payload = json_encode($data);

        $auth = [
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ];

        $webPush = new WebPush($auth);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding,
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }

    private function calculateBadgeCount(object $notifiable): int
    {
        $count = $notifiable->unreadNotifications()->count();

        if ($notifiable->can('chat.access')) {
            $count += app(ChatService::class)->getUnreadCountForUser($notifiable);
        }

        if ($notifiable->can('email.access')) {
            $accountIds = $notifiable->allEmailAccounts()->pluck('id');
            if ($accountIds->isNotEmpty()) {
                $count += EmailMessage::whereIn('email_account_id', $accountIds)
                    ->withLabel('inbox')
                    ->unread()
                    ->count();
            }
        }

        return $count;
    }
}
