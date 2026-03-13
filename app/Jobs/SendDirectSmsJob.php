<?php

namespace App\Jobs;

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDirectSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        public string $phone,
        public string $message,
        public User $sender,
        public ?Customer $customer = null,
        public ?ChatThread $thread = null,
    ) {}

    public function handle(): void
    {
        $twilio = app(TwilioService::class);

        $result = $twilio->sendSms($this->phone, $this->message);

        if (! $result['success']) {
            Log::error('SendDirectSmsJob: failed to send SMS', [
                'phone' => $this->phone,
                'error' => $result['error'],
            ]);

            return;
        }

        // Store the sent message in a chat thread
        $thread = $this->thread ?? $this->findOrCreateSmsThread();

        $chatService = app(ChatService::class);
        $chatService->sendMessage(
            thread: $thread,
            sender: $this->sender,
            content: $this->message,
            options: ['metadata' => ['source' => 'sms', 'direction' => 'outbound']],
        );

        // Create/update SMS session for reply routing
        $formattedPhone = $twilio->formatE164($this->phone);

        SmsSession::updateOrCreate(
            ['phone_number' => $formattedPhone ?? $this->phone, 'thread_id' => $thread->id],
            [
                'user_id' => $this->customer?->user_id,
                'customer_id' => $this->customer?->id,
                'expires_at' => now()->addHours(24),
            ]
        );
    }

    private function findOrCreateSmsThread(): ChatThread
    {
        $twilio = app(TwilioService::class);
        $formattedPhone = $twilio->formatE164($this->phone) ?? $this->phone;

        // Look for existing SMS thread for this customer
        if ($this->customer) {
            $existingThread = ChatThread::query()
                ->where('customer_id', $this->customer->id)
                ->where('source', ChatThread::SOURCE_SMS_INBOUND)
                ->latest()
                ->first();

            if ($existingThread) {
                if (! $existingThread->hasParticipant($this->sender)) {
                    $existingThread->addParticipant($this->sender);
                }

                return $existingThread;
            }
        }

        // Look for existing SMS session thread
        $session = SmsSession::where('phone_number', $formattedPhone)
            ->whereHas('thread')
            ->latest()
            ->first();

        if ($session) {
            $thread = $session->thread;
            if (! $thread->hasParticipant($this->sender)) {
                $thread->addParticipant($this->sender);
            }

            return $thread;
        }

        // Create new SMS thread
        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'customer_id' => $this->customer?->id,
            'subject' => "SMS from {$formattedPhone}",
            'last_message_at' => now(),
        ]);

        $thread->addParticipant($this->sender);

        // Add internal users with website leads permission
        $internalUsers = User::where('type', 'internal')
            ->where('is_active', true)
            ->permission('chat.view_website_leads')
            ->get();

        if ($internalUsers->isEmpty()) {
            $internalUsers = User::where('type', 'internal')
                ->where('is_active', true)
                ->get();
        }

        foreach ($internalUsers as $user) {
            if (! $thread->hasParticipant($user)) {
                $thread->addParticipant($user);
            }
        }

        return $thread;
    }
}
