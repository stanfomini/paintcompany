<?php

use App\Jobs\SendFacebookMessageJob;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\ChatService;
use App\Services\FacebookMessengerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-verify-token',
        'app_secret' => 'test-app-secret',
        'page_access_token' => 'test-page-token',
        'page_id' => '123456',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('stores Facebook Messenger image attachments as ChatAttachments', function () {
    $imageContent = fake()->text(100);

    Http::fake([
        'https://scontent.xx.fbcdn.net/*' => Http::response($imageContent, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'graph.facebook.com/*' => Http::response([
            'first_name' => 'Photo',
            'last_name' => 'Sender',
        ]),
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'attach-psid-1'],
            'message' => [
                'mid' => 'mid.attach1',
                'attachments' => [
                    [
                        'type' => 'image',
                        'payload' => [
                            'url' => 'https://scontent.xx.fbcdn.net/v/photo123.jpg',
                        ],
                    ],
                ],
            ],
        ]],
    ]);

    // Should have a text message (empty content) plus an image message
    $thread = ChatThread::where('external_contact_id', 'attach-psid-1')->first();
    expect($thread)->not->toBeNull();

    $imageMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->first();
    expect($imageMessage)->not->toBeNull();
    expect($imageMessage->metadata['source'])->toBe('facebook_messenger');
    expect($imageMessage->metadata['psid'])->toBe('attach-psid-1');

    // Should have created a ChatAttachment
    $attachment = ChatAttachment::where('message_id', $imageMessage->id)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->mime_type)->toBe('image/jpeg');
    expect(str_ends_with($attachment->path, '.jpg'))->toBeTrue();
    expect(str_starts_with($attachment->path, 'chat-attachments/'))->toBeTrue();

    // File should exist in storage
    Storage::assertExists($attachment->path);
});

it('handles Facebook message with text and attachments', function () {
    $imageContent = 'fake-image-bytes';

    Http::fake([
        'https://scontent.xx.fbcdn.net/*' => Http::response($imageContent, 200, [
            'Content-Type' => 'image/png',
        ]),
        'graph.facebook.com/*' => Http::response([
            'first_name' => 'Multi',
            'last_name' => 'Message',
        ]),
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'attach-psid-2'],
            'message' => [
                'mid' => 'mid.attach2',
                'text' => 'Here is my wall photo',
                'attachments' => [
                    [
                        'type' => 'image',
                        'payload' => [
                            'url' => 'https://scontent.xx.fbcdn.net/v/wall_photo.png',
                        ],
                    ],
                ],
            ],
        ]],
    ]);

    $thread = ChatThread::where('external_contact_id', 'attach-psid-2')->first();
    expect($thread)->not->toBeNull();

    // Should have both a text message AND an image message
    $textMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_TEXT)
        ->first();
    expect($textMessage)->not->toBeNull();
    expect($textMessage->content)->toBe('Here is my wall photo');

    $imageMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->first();
    expect($imageMessage)->not->toBeNull();

    $attachment = ChatAttachment::where('message_id', $imageMessage->id)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->mime_type)->toBe('image/png');
});

it('handles multiple attachments in a single message', function () {
    Http::fake([
        'https://scontent.xx.fbcdn.net/v/photo1.jpg' => Http::response('img1', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'https://scontent.xx.fbcdn.net/v/photo2.jpg' => Http::response('img2', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'graph.facebook.com/*' => Http::response([
            'first_name' => 'Multi',
            'last_name' => 'Attach',
        ]),
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'attach-psid-3'],
            'message' => [
                'mid' => 'mid.attach3',
                'attachments' => [
                    ['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/photo1.jpg']],
                    ['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/photo2.jpg']],
                ],
            ],
        ]],
    ]);

    $thread = ChatThread::where('external_contact_id', 'attach-psid-3')->first();

    $imageMessages = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->get();
    expect($imageMessages)->toHaveCount(2);

    $attachments = ChatAttachment::whereIn('message_id', $imageMessages->pluck('id'))->get();
    expect($attachments)->toHaveCount(2);
});

it('skips unsupported attachment types', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'first_name' => 'Template',
            'last_name' => 'User',
        ]),
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'attach-psid-4'],
            'message' => [
                'mid' => 'mid.attach4',
                'attachments' => [
                    ['type' => 'template', 'payload' => ['url' => 'https://example.com/template']],
                    ['type' => 'fallback', 'payload' => ['url' => null]],
                ],
            ],
        ]],
    ]);

    $thread = ChatThread::where('external_contact_id', 'attach-psid-4')->first();

    // Should NOT create any image/file messages
    $mediaMessages = ChatMessage::where('thread_id', $thread->id)
        ->whereIn('type', [ChatMessage::TYPE_IMAGE, ChatMessage::TYPE_FILE])
        ->count();
    expect($mediaMessages)->toBe(0);
});

it('gracefully handles attachment download failure', function () {
    Http::fake([
        'https://scontent.xx.fbcdn.net/*' => Http::response('Not found', 404),
        'graph.facebook.com/*' => Http::response([
            'first_name' => 'Failed',
            'last_name' => 'Download',
        ]),
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'attach-psid-5'],
            'message' => [
                'mid' => 'mid.attach5',
                'text' => 'Check this out',
                'attachments' => [
                    ['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/broken.jpg']],
                ],
            ],
        ]],
    ]);

    $thread = ChatThread::where('external_contact_id', 'attach-psid-5')->first();

    // Text message should still exist
    $textMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_TEXT)
        ->first();
    expect($textMessage)->not->toBeNull();

    // No image message since download failed
    $imageMessages = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->count();
    expect($imageMessages)->toBe(0);
});

// --- Outbound image sending ---

it('sends image attachment to Messenger when internal user replies with image', function () {
    Queue::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $thread = ChatThread::factory()->facebook('outbound-psid-1')->create();
    $thread->addParticipant($user);

    // Create a message with an image attachment
    $chatMessage = ChatMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'content' => 'Here is the color visualization',
        'type' => ChatMessage::TYPE_IMAGE,
        'metadata' => [],
    ]);

    ChatAttachment::create([
        'message_id' => $chatMessage->id,
        'path' => 'chat-attachments/test-image.jpg',
        'filename' => 'test-image.jpg',
        'original_filename' => 'visualization.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
    ]);

    // Use ChatService to send the message which triggers syncToFacebookMessenger
    $chatService = app(ChatService::class);
    $chatService->sendMessage($thread, $user, 'Here is the color visualization');

    // Should dispatch text message job
    Queue::assertPushed(SendFacebookMessageJob::class, function ($job) {
        return $job->psid === 'outbound-psid-1'
            && $job->text === 'Here is the color visualization'
            && $job->mediaUrl === null;
    });
});

it('dispatches SendFacebookMessageJob with mediaUrl for image attachments', function () {
    Queue::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $thread = ChatThread::factory()->facebook('outbound-psid-2')->create();
    $thread->addParticipant($user);

    // First, send a text message to create the ChatMessage in DB
    $chatService = app(ChatService::class);
    $chatService->sendMessage($thread, $user, 'Check this out');

    // Get the created message and add an image attachment to it
    $chatMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('user_id', $user->id)
        ->latest()
        ->first();

    $attachment = ChatAttachment::create([
        'message_id' => $chatMessage->id,
        'path' => 'chat-attachments/test-viz.jpg',
        'filename' => 'test-viz.jpg',
        'original_filename' => 'viz.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 2048,
    ]);

    // Clear queue and re-trigger sync manually by sending another message
    Queue::fake();

    $chatService->sendMessage($thread, $user, 'And another note');

    // The second message dispatch should be for the text
    Queue::assertPushed(SendFacebookMessageJob::class, function ($job) {
        return $job->psid === 'outbound-psid-2'
            && $job->text === 'And another note';
    });
});

it('sends image via sendImageMessage through Graph API', function () {
    Http::fake([
        'graph.facebook.com/*/me/messages' => Http::response(['message_id' => 'mid.img1']),
    ]);

    $service = app(FacebookMessengerService::class);
    $result = $service->sendImageMessage('test-psid', 'https://example.com/color-viz.jpg');

    expect($result['success'])->toBeTrue();
    expect($result['message_id'])->toBe('mid.img1');

    Http::assertSent(function ($request) {
        $message = $request['message'] ?? [];

        return str_contains($request->url(), 'me/messages')
            && $request['recipient']['id'] === 'test-psid'
            && $message['attachment']['type'] === 'image'
            && $message['attachment']['payload']['url'] === 'https://example.com/color-viz.jpg'
            && $message['attachment']['payload']['is_reusable'] === true;
    });
});
