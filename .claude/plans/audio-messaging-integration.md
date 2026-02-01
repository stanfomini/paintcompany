# Audio Messaging Integration Plan

## Overview

Integrate voice message recording into the existing chat system. Users can record audio messages that are automatically transcribed and sent to recipients who can listen to the audio and read the transcription.

## Existing Infrastructure

### Chat System (Laravel)
- **Models**: `ChatMessage`, `ChatThread`, `ChatAttachment`
- **Components**: `MessageComposer` (Livewire) handles message sending
- **Message Types**: `text`, `file`, `image`, `system`
- **Service**: `ChatService` handles message creation and attachments

### Python Service
- **Endpoint**: `POST /media/transcribe/upload` - Transcribes audio files
- **WebSocket**: `/media/ws/audio` - Real-time audio streaming (walkie-talkie)
- **Transcription**: Uses OpenAI Whisper for speech-to-text
- **Supported Formats**: mp3, wav, m4a, webm, ogg, flac, aac

### Laravel-Python Bridge
- **Service**: `PythonBridgeService` - HTTP communication with Python service
- **Config**: `PYTHON_SERVICE_URL` in `.env`

## Implementation Plan

### 1. Add Audio Message Type

**File**: `app/Models/ChatMessage.php`
```php
public const TYPE_AUDIO = 'audio';

// Metadata structure for audio messages:
// [
//     'audio_url' => 'storage/chat-audio/uuid.webm',
//     'audio_duration' => 12.5,  // seconds
//     'transcription' => 'Hello, this is a voice message...',
//     'transcription_status' => 'completed', // pending, completed, failed
// ]
```

### 2. Create Audio Recording Service

**File**: `app/Services/AudioMessageService.php`

Responsibilities:
- Save uploaded audio to storage
- Call Python service for transcription
- Return audio URL and transcription text

```php
class AudioMessageService
{
    public function __construct(
        private PythonBridgeService $python
    ) {}

    public function processAudioMessage(UploadedFile $audio): array
    {
        // 1. Store audio file
        $path = $audio->store('chat-audio', 'public');

        // 2. Get transcription from Python service
        $transcription = $this->transcribe($audio);

        return [
            'audio_url' => Storage::disk('public')->url($path),
            'audio_path' => $path,
            'audio_duration' => $this->getAudioDuration($audio),
            'transcription' => $transcription['text'] ?? null,
            'transcription_status' => $transcription['success'] ? 'completed' : 'failed',
        ];
    }

    private function transcribe(UploadedFile $audio): array
    {
        return $this->python->transcribeAudio($audio->path());
    }
}
```

### 3. Add Transcription to PythonBridgeService

**File**: `app/Services/PythonBridgeService.php`

```php
public function transcribeAudio(string $filePath): array
{
    $response = Http::timeout(60)
        ->attach('file', file_get_contents($filePath), basename($filePath))
        ->post($this->baseUrl . '/media/transcribe/upload', [
            'model' => 'base',
        ]);

    if ($response->successful()) {
        return $response->json();
    }

    return ['success' => false, 'error' => 'Transcription failed'];
}
```

### 4. Update MessageComposer Component

**File**: `app/Livewire/Chat/MessageComposer.php`

Add audio recording state and methods:

```php
public bool $isRecording = false;
public ?string $recordedAudio = null;  // Base64 or temp file path

public function startRecording(): void
{
    $this->isRecording = true;
    $this->dispatch('start-audio-recording');
}

public function stopRecording(): void
{
    $this->isRecording = false;
    $this->dispatch('stop-audio-recording');
}

public function processRecordedAudio(string $audioBase64): void
{
    // Decode and save to temp file
    $audioData = base64_decode($audioBase64);
    $tempPath = tempnam(sys_get_temp_dir(), 'audio_') . '.webm';
    file_put_contents($tempPath, $audioData);

    // Process through AudioMessageService
    $audioService = app(AudioMessageService::class);
    $result = $audioService->processAudioMessage(
        new \Illuminate\Http\UploadedFile($tempPath, 'recording.webm')
    );

    // Send as audio message
    $this->sendAudioMessage($result);

    // Cleanup
    unlink($tempPath);
}

private function sendAudioMessage(array $audioData): void
{
    $chatService = app(ChatService::class);

    $chatService->sendMessage(
        $this->thread,
        auth()->user(),
        '', // No text content
        [
            'type' => ChatMessage::TYPE_AUDIO,
            'metadata' => $audioData,
        ]
    );

    $this->dispatch('message-sent');
}
```

### 5. Update MessageComposer View

**File**: `resources/views/livewire/chat/message-composer.blade.php`

Add recording UI with Alpine.js:

```blade
<div x-data="audioRecorder(@this)">
    {{-- Existing message input --}}

    {{-- Audio Recording Button --}}
    <button
        type="button"
        @mousedown="startRecording()"
        @mouseup="stopRecording()"
        @mouseleave="stopRecording()"
        @touchstart.prevent="startRecording()"
        @touchend.prevent="stopRecording()"
        :class="recording ? 'bg-red-500 animate-pulse' : 'bg-zinc-700'"
        class="p-3 rounded-full text-white"
    >
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
            <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
        </svg>
    </button>

    {{-- Recording Indicator --}}
    <div x-show="recording" class="flex items-center gap-2 text-red-500">
        <span class="w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
        <span x-text="formatTime(recordingTime)"></span>
    </div>
</div>

@script
<script>
Alpine.data('audioRecorder', (wire) => ({
    recording: false,
    recordingTime: 0,
    mediaRecorder: null,
    audioChunks: [],
    timer: null,

    async startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
            this.audioChunks = [];

            this.mediaRecorder.ondataavailable = (e) => {
                this.audioChunks.push(e.data);
            };

            this.mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(this.audioChunks, { type: 'audio/webm' });
                const base64 = await this.blobToBase64(audioBlob);
                wire.call('processRecordedAudio', base64);

                // Stop all tracks
                stream.getTracks().forEach(track => track.stop());
            };

            this.mediaRecorder.start();
            this.recording = true;
            this.recordingTime = 0;
            this.timer = setInterval(() => this.recordingTime++, 1000);

        } catch (err) {
            console.error('Failed to start recording:', err);
        }
    },

    stopRecording() {
        if (this.mediaRecorder && this.recording) {
            this.mediaRecorder.stop();
            this.recording = false;
            clearInterval(this.timer);
        }
    },

    blobToBase64(blob) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result.split(',')[1]);
            reader.readAsDataURL(blob);
        });
    },

    formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
}));
</script>
@endscript
```

### 6. Display Audio Messages

**File**: `resources/views/livewire/chat/message-item.blade.php`

```blade
@if ($message->type === 'audio')
    <div class="audio-message p-3 rounded-lg bg-zinc-100 dark:bg-zinc-800">
        {{-- Audio Player --}}
        <audio
            controls
            class="w-full max-w-xs"
            src="{{ $message->metadata['audio_url'] }}"
        ></audio>

        {{-- Transcription --}}
        @if ($message->metadata['transcription'])
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400 italic">
                "{{ $message->metadata['transcription'] }}"
            </p>
        @elseif ($message->metadata['transcription_status'] === 'pending')
            <p class="mt-2 text-xs text-zinc-500">
                <span class="animate-pulse">Transcribing...</span>
            </p>
        @endif

        {{-- Duration --}}
        @if ($message->metadata['audio_duration'])
            <span class="text-xs text-zinc-500">
                {{ gmdate('i:s', $message->metadata['audio_duration']) }}
            </span>
        @endif
    </div>
@endif
```

### 7. Database Migration (if needed)

The existing `ChatMessage` model already supports:
- `type` column for message types
- `metadata` JSON column for additional data

No migration needed unless you want to add a dedicated `audio_url` column.

## API Endpoints Used

### Python Service

1. **Transcribe Uploaded Audio**
   ```
   POST /media/transcribe/upload
   Content-Type: multipart/form-data

   file: <audio file>
   model: "base" (tiny, base, small, medium, large)
   language: null (auto-detect)

   Response:
   {
       "success": true,
       "text": "Transcribed text here...",
       "segments": [...],
       "language": "en",
       "duration": 12.5
   }
   ```

2. **Check Transcription Status**
   ```
   GET /media/status

   Response:
   {
       "whisper_available": true,
       "supported_formats": [".mp3", ".wav", ".webm", ...]
   }
   ```

## User Flow

1. User clicks/holds the microphone button in chat
2. Browser requests microphone permission (first time only)
3. Audio recording starts, indicator shows recording time
4. User releases button to stop recording
5. Audio is encoded to base64 and sent to Livewire
6. Laravel saves audio to storage
7. Laravel calls Python service for transcription
8. Message is created with audio URL and transcription
9. Other participants see audio player with transcription text
10. Real-time update via Laravel Reverb broadcasting

## Testing

```php
// tests/Feature/Chat/AudioMessageTest.php

it('can send an audio message', function () {
    $user = User::factory()->create();
    $thread = ChatThread::factory()->create();

    // Mock the Python service
    $this->mock(PythonBridgeService::class)
        ->shouldReceive('transcribeAudio')
        ->andReturn([
            'success' => true,
            'text' => 'Hello, this is a test message',
        ]);

    Livewire::actingAs($user)
        ->test(MessageComposer::class, ['thread' => $thread])
        ->call('processRecordedAudio', base64_encode('fake audio data'))
        ->assertDispatched('message-sent');

    expect($thread->messages()->count())->toBe(1);
    expect($thread->messages()->first()->type)->toBe('audio');
});
```

## Files to Create/Modify

### New Files
- `app/Services/AudioMessageService.php`
- `tests/Feature/Chat/AudioMessageTest.php`

### Modified Files
- `app/Models/ChatMessage.php` - Add TYPE_AUDIO constant
- `app/Services/PythonBridgeService.php` - Add transcribeAudio method
- `app/Livewire/Chat/MessageComposer.php` - Add recording methods
- `resources/views/livewire/chat/message-composer.blade.php` - Add recording UI
- `resources/views/livewire/chat/message-item.blade.php` - Display audio messages

## Environment Variables

Ensure these are set in `.env`:
```
PYTHON_SERVICE_URL=http://127.0.0.1:8000
PYTHON_SERVICE_ENABLED=true
```

## Dependencies

- Browser: MediaRecorder API (supported in all modern browsers)
- Python: OpenAI Whisper (`pip install openai-whisper`)
- Laravel: No additional packages needed
