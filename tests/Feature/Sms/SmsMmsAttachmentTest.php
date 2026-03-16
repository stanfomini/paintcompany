<?php

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone);
        $mock->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
        $mock->shouldReceive('sendMms')->andReturn(['success' => true, 'sid' => 'SM456', 'error' => null]);
    });
});

it('stores inbound MMS photos as ChatAttachments on SMS threads', function () {
    Http::fake([
        'https://api.twilio.com/media/photo1.jpg' => Http::response('fake-image-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035559001',
        'Body' => '',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/photo1.jpg',
    ]);

    $response->assertStatus(200);

    // Should have created a chat thread with an image message
    $thread = ChatThread::whereHas('messages', function ($q) {
        $q->where('type', ChatMessage::TYPE_IMAGE);
    })->first();
    expect($thread)->not->toBeNull();

    $imageMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->first();
    expect($imageMessage)->not->toBeNull();
    expect($imageMessage->metadata['source'])->toBe('sms');

    // Should have created a ChatAttachment
    $attachment = ChatAttachment::where('message_id', $imageMessage->id)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->mime_type)->toBe('image/jpeg');
    expect(str_starts_with($attachment->path, 'chat-attachments/'))->toBeTrue();
    expect(str_ends_with($attachment->path, '.jpg'))->toBeTrue();

    Storage::assertExists($attachment->path);
});

it('handles MMS with both text and photos', function () {
    Http::fake([
        'https://api.twilio.com/media/wall.jpg' => Http::response('fake-image-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035559002',
        'Body' => 'Here is my wall photo',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/wall.jpg',
    ]);

    $response->assertStatus(200);

    // Find the thread created for this phone
    $session = SmsSession::where('phone_number', '+13035559002')->first();
    expect($session)->not->toBeNull();

    $thread = $session->thread;

    // Should have a text message
    $textMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_TEXT)
        ->first();
    expect($textMessage)->not->toBeNull();
    expect($textMessage->content)->toBe('Here is my wall photo');

    // Should also have an image message with attachment
    $imageMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->first();
    expect($imageMessage)->not->toBeNull();

    $attachment = ChatAttachment::where('message_id', $imageMessage->id)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->mime_type)->toBe('image/jpeg');
});

it('stores multiple MMS photos as separate attachments', function () {
    Http::fake([
        'https://api.twilio.com/media/photo1.jpg' => Http::response('img1-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'https://api.twilio.com/media/photo2.png' => Http::response('img2-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035559003',
        'Body' => 'Two photos',
        'NumMedia' => '2',
        'MediaUrl0' => 'https://api.twilio.com/media/photo1.jpg',
        'MediaUrl1' => 'https://api.twilio.com/media/photo2.png',
    ]);

    $response->assertStatus(200);

    $session = SmsSession::where('phone_number', '+13035559003')->first();
    expect($session)->not->toBeNull();

    $thread = $session->thread;

    // Should have image messages for each photo
    $imageMessages = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->get();
    expect($imageMessages)->toHaveCount(2);

    $attachments = ChatAttachment::whereIn('message_id', $imageMessages->pluck('id'))->get();
    expect($attachments)->toHaveCount(2);

    // Verify different mime types
    $mimeTypes = $attachments->pluck('mime_type')->sort()->values()->all();
    expect($mimeTypes)->toBe(['image/jpeg', 'image/png']);

    // Both files should exist in storage
    foreach ($attachments as $att) {
        Storage::assertExists($att->path);
    }
});

it('stores MMS photos on existing session threads', function () {
    // Create an existing session with a thread
    $user = User::factory()->internal()->create();
    $thread = ChatThread::factory()->create(['type' => ChatThread::TYPE_SUPPORT]);
    $thread->addParticipant($user);

    $session = SmsSession::create([
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'phone_number' => '+13035559004',
        'expires_at' => now()->addHours(24),
    ]);

    Http::fake([
        'https://api.twilio.com/media/existing.jpg' => Http::response('image-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035559004',
        'Body' => 'Check this wall',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/existing.jpg',
    ]);

    $response->assertStatus(200);

    // Image should be on the existing thread
    $imageMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->first();
    expect($imageMessage)->not->toBeNull();

    $attachment = ChatAttachment::where('message_id', $imageMessage->id)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->mime_type)->toBe('image/jpeg');

    Storage::assertExists($attachment->path);
});

it('gracefully handles MMS download failure', function () {
    Http::fake([
        'https://api.twilio.com/media/broken.jpg' => Http::response('Not found', 404),
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035559005',
        'Body' => 'Photo attached',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/broken.jpg',
    ]);

    $response->assertStatus(200);

    // Text message should still exist
    $session = SmsSession::where('phone_number', '+13035559005')->first();
    expect($session)->not->toBeNull();

    $textMessage = ChatMessage::where('thread_id', $session->thread_id)
        ->where('type', ChatMessage::TYPE_TEXT)
        ->first();
    expect($textMessage)->not->toBeNull();
    expect($textMessage->content)->toBe('Photo attached');

    // No image message since download failed
    $imageCount = ChatMessage::where('thread_id', $session->thread_id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->count();
    expect($imageCount)->toBe(0);
});
