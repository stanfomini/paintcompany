# Public Chat Audio Recording Design

**Date**: 2026-03-03
**Status**: Approved

## Problem

The public-facing support chats (landing page guest chat and setter/booking support chat) only support text messages. Internal chat already has full audio recording with VU meter, preview, transcription. Customers and setters should have the same audio experience.

## Solution

Add audio recording and playback to both `⚡guest-chat.blade.php` and `⚡setter-support-chat.blade.php`. The booking page gets audio for free since it embeds the setter chat.

### Server-Side

**New method**: `ChatService::sendGuestAudioMessage(ChatThread, string $content, array $audioData, array $metadata)` — creates a `type=audio` message with `user_id=null`, stores audio URL/path/duration/transcription in message metadata, broadcasts `MessageSent`, dispatches unread email jobs. Follows the same pattern as `sendGuestMessage()` but for audio type.

**Reuse**: `AudioMessageService::processBase64Audio()` for storage and transcription — no changes needed.

### Client-Side

Both public chat components get:
- `processRecordedAudio(base64, mimeType)` Livewire method — calls AudioMessageService, then sendGuestAudioMessage
- Alpine JS audio recorder inline (extracted pattern from message-composer): MediaRecorder, VU meter, preview, silence detection
- Audio message playback in the message list — `<audio controls>` for audio-type messages with transcription display

### Components Changed

| File | Change |
|---|---|
| `app/Services/ChatService.php` | Add `sendGuestAudioMessage()` |
| `resources/views/components/public/⚡guest-chat.blade.php` | Add audio recording UI + processRecordedAudio + audio playback |
| `resources/views/components/public/⚡setter-support-chat.blade.php` | Same |

### Audio Flow

```
Guest clicks mic → MediaRecorder starts → VU meter animates
Guest clicks stop → Preview plays → Guest clicks send
→ blob to base64 → Livewire processRecordedAudio()
→ AudioMessageService::processBase64Audio() (stores to S3, transcribes via Python)
→ ChatService::sendGuestAudioMessage() (creates audio message, user_id=null)
→ broadcast MessageSent → internal users see audio in comms hub
```
