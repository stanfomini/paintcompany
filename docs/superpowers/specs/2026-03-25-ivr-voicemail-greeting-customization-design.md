# IVR & Voicemail Greeting Customization

**Date**: 2026-03-25
**Status**: Approved

## Problem

1. **Voicemail upload bug**: The phone settings UI saves voicemail greeting type as `'upload'`, but `TwilioVoiceService::generateVoicemailTwiml()` checks for `'audio'`. Uploaded greetings never play — TTS is always used as fallback.
2. **No IVR greeting customization**: The IVR greeting is auto-generated from menu option labels ("Press 1 for Estimates..."). There is no way to write custom greeting text or upload a recorded IVR greeting.
3. **Hardcoded TTS voice**: All TTS uses `Polly.Joanna` with no way to change it.

## Solution

### 1. Fix voicemail type mismatch

There are three different type strings in the codebase:
- UI validation: `'tts'` or `'upload'` (canonical values)
- `TwilioVoiceService`: checks for `'audio'` (wrong)
- `TwilioVoiceServiceTest`: uses `'text'` (wrong — passes by accident via else branch)

Fix: canonical values are `'tts'` and `'upload'`. Change the service check from `'audio'` to `'upload'`. Update the existing test from `'text'` to `'tts'`.

### 2. New CompanySetting columns

Add via migration:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `tts_voice` | string | `'Polly.Joanna'` | Shared TTS voice for all voice responses |
| `ivr_greeting_type` | string | `'auto'` | `'auto'` / `'tts'` / `'upload'` |
| `ivr_greeting_text` | text, nullable | null | Custom TTS greeting for IVR |
| `ivr_greeting_path` | string, nullable | null | Uploaded audio file path for IVR |

### 3. TTS voice selector

Add a voice dropdown to the **General Settings** section of the phone system settings page. One voice for the entire system (voicemail, IVR, whisper, fallback messages).

Available voices (all US English, Amazon Polly via Twilio):

| Voice | Gender | Type |
|-------|--------|------|
| Polly.Joanna | Female | Standard |
| Polly.Matthew | Male | Standard |
| Polly.Salli | Female | Standard |
| Polly.Kendra | Female | Standard |
| Polly.Joey | Male | Standard |
| Polly.Ruth | Female | Neural |
| Polly.Stephen | Male | Neural |

### 4. IVR greeting UI

Add to the IVR section (visible when IVR is enabled), before the menu options:

- **Greeting Type** dropdown:
  - **Auto-generate** (default) — builds greeting from menu option labels, current behavior
  - **Custom Text-to-Speech** — user writes the full greeting text
  - **Upload Audio** — user uploads an audio file
- If TTS: textarea for custom greeting text (max 1000 chars)
- If Upload: file input (mp3/wav/ogg/m4a, max 10MB), shows current file if one exists

### 5. TwilioVoiceService changes

All methods that call `$response->say()` use `$settings->tts_voice` instead of hardcoded `'Polly.Joanna'`.

**`generateVoicemailTwiml()`**:
- Fix type check: `'audio'` -> `'upload'`
- Use `$settings->tts_voice` for voice parameter

**`generateIvrMenuTwiml()`**:
- Check `$settings->ivr_greeting_type`:
  - `'upload'` + path exists: `$gather->play(storage_url($path))`
  - `'tts'` + text is non-empty: `$gather->say($text, ['voice' => $settings->tts_voice])`
  - `'auto'` (default): current behavior, auto-build from menu labels using `$gather->say()`
- **Fallback**: if `'tts'` but text is empty, or `'upload'` but path is null, fall back to `'auto'` behavior
- **Extension prompt**: when greeting type is `'auto'`, the extension prompt is auto-appended. When `'tts'`, the user is responsible for including extension instructions in their text. When `'upload'`, no extension prompt is appended (audio cannot be modified).
- Use `$settings->tts_voice` for all say() calls

**Other hardcoded references** (whisper announcements, fallback messages in webhook controller): use `$settings->tts_voice`.

### 6. Files changed

| File | Change |
|------|--------|
| `database/migrations/xxxx_add_ivr_greeting_fields.php` | Add 4 columns to company_settings |
| `app/Models/CompanySetting.php` | Add to $fillable |
| `app/Services/TwilioVoiceService.php` | Fix voicemail bug, IVR greeting logic, dynamic voice |
| `app/Http/Controllers/TwilioVoiceWebhookController.php` | Use dynamic voice for whisper |
| `resources/views/pages/settings/⚡phone-system.blade.php` | Voice selector in General, IVR greeting UI, new Livewire properties (`$ttsVoice`, `$ivrGreetingType`, `$ivrGreetingText`, `$ivrGreetingFile`, `$existingIvrGreetingPath`) |
| `tests/Feature/Voice/PhoneSystemSettingsTest.php` | Test new settings fields |
| `tests/Feature/Voice/IvrHandlingTest.php` | Test IVR greeting types + fallbacks |
| `tests/Feature/Voice/VoicemailJobTest.php` | Fix for upload type |
| `tests/Feature/Voice/TwilioVoiceServiceTest.php` | Fix `'text'` -> `'tts'`, test voice selection in TwiML |
| `tests/Feature/Voice/WhisperTest.php` | Update hardcoded `Polly.Joanna` assertion |

### 7a. Validation rules

**`saveGeneral()`** adds: `'ttsVoice' => ['required', 'string', 'in:Polly.Joanna,Polly.Matthew,...']`

**`saveIvr()`** adds:
- `'ivrGreetingType' => ['required', 'in:auto,tts,upload']`
- `'ivrGreetingText' => ['nullable', 'string', 'max:1000']`
- `'ivrGreetingFile' => ['nullable', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:10240']`

**IVR file storage**: `->store('ivr-greetings', 'public')` (mirrors voicemail pattern).

### 7b. Scope exclusions

- **Voice preview**: out of scope for v1 (would require playing audio in the browser)
- **Orphan file cleanup**: when switching IVR greeting type, old uploaded files are not deleted (acceptable for v1)

### 7. Testing

- Voicemail with `'upload'` type actually plays the uploaded file
- Voicemail with `'tts'` type uses custom text and selected voice
- IVR with `'auto'` generates greeting from menu labels
- IVR with `'tts'` uses custom greeting text
- IVR with `'upload'` plays uploaded audio file
- Voice selection persists and flows through to TwiML output (assert voice attribute in XML)
- Settings UI saves/loads all new fields correctly
- IVR fallback: TTS with empty text falls back to auto-generate
- IVR fallback: upload with no file falls back to auto-generate
- Whisper announcements use dynamic voice
- Invalid IVR greeting file type rejected by validation
