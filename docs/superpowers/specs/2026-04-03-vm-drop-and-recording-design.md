# VM Drop on Active Call + Browser Recording Fix

## Date: 2026-04-03
## Status: Design approved

---

## Problem

1. **No in-call VM drop**: The existing RVM system makes a *new* outbound call to deliver voicemail. There's no way to play a pre-recorded message on the *current* call when a machine is detected. The agent stays connected to dead air.

2. **No auto-drop on AMD**: When AMD detects a machine during auto-dial, nothing happens except a UI indicator. The agent must manually handle it.

3. **Browser recordings produce dead air**: MediaRecorder captures WebM/Opus format. Twilio's `<Play>` doesn't support WebM. Recordings upload but produce silence when played back via Twilio. Additionally, browser playback of the recorded file may not work reliably across all browsers.

## Solution

### Feature 1: VM Drop on Active Call

When AMD detects a machine (auto) or the agent clicks "Drop VM" (manual):

1. Server redirects the contact's call leg to play the pre-recorded audio: `$client->calls($contactCallSid)->update(['twiml' => '<Play>{mp3_url}</Play><Hangup/>'])`
2. Server removes the agent from the conference (in session mode) or the call ends naturally
3. Call is marked `completed` with disposition `voicemail`
4. Agent auto-advances to next contact in auto-dial mode

### Feature 2: Browser Recording → MP3 Conversion

Keep MediaRecorder capturing WebM/Opus (best browser support). After Livewire upload, convert server-side to MP3 via ffmpeg before saving the template. The stored `audio_path` always points to an MP3 file that both Twilio and browsers can play natively.

---

## Architecture

### VM Drop Flow (Auto — AMD Detection)

```
Contact's phone answers (machine)
  → Twilio fires AMD callback to /prospector/webhook/twilio/amd
  → ProspectorWebhookController::amdCallback()
  → AmdService determines: machine detected
  → Server looks up company's default_rvm_template_id
  → If template exists:
      1. Get audio URL from template (public MP3 URL)
      2. Redirect contact's call: $client->calls($callSid)->update(['twiml' => '<Play>url</Play><Hangup/>'])
      3. Remove agent from conference (session mode) or hang up agent's call
      4. Update Call record: status=completed, disposition=voicemail
      5. Broadcast DialerCallEnded with disposition='voicemail'
      6. DialerSession auto-advances to next contact
  → If no template: fall through to existing behavior (agent sees "Machine Detected")
```

### VM Drop Flow (Manual — Agent Clicks Button)

```
Agent hears voicemail greeting (AMD didn't catch it, or AMD disabled)
  → Agent clicks "Drop VM" button in dialer UI
  → Livewire: dropVoicemailOnCall()
      1. Find active call's twilio_call_sid and conference
      2. Get company's default RVM template
      3. Redirect contact's call to <Play> TwiML
      4. Remove agent from conference (session mode)
      5. Update Call: status=completed, disposition=voicemail
      6. Show disposition panel (pre-filled as voicemail)
      7. In auto-dial: DialerSession auto-advances after disposition
```

### Recording + Conversion Flow

```
Agent opens RVM Manager → clicks Record tab
  → Browser: MediaRecorder captures audio/webm;codecs=opus
  → On stop: blob → File → @this.upload('audioFile', file)
  → Livewire receives temporary WebM file
  → Agent clicks "Create Template"
  → saveTemplate():
      1. Store WebM to temp location
      2. Convert via ffmpeg: webm → mp3
         Command: ffmpeg -i input.webm -codec:a libmp3lame -qscale:a 2 output.mp3
      3. Store MP3 to storage/app/public/rvm-templates/{ulid}.mp3
      4. Delete temp WebM
      5. Save template with audio_path pointing to MP3
      6. Duration calculated from ffmpeg output or MediaRecorder timer
```

---

## Files to Modify

### 1. `app/Services/Prospector/ConferenceService.php`
- Add `playAudioOnCall(string $callSid, string $audioUrl): void` — redirects a call to `<Play>` TwiML with `<Hangup/>`

### 2. `app/Http/Controllers/Prospector/ProspectorWebhookController.php`
- Rewrite AMD auto-drop logic in `amdCallback()`:
  - Instead of calling `RvmService::drop()` (new call), use `ConferenceService::playAudioOnCall()` on the existing call
  - Remove agent from conference
  - Update Call record with disposition `voicemail`
  - Broadcast `DialerCallEnded`

### 3. `resources/views/pages/prospector/⚡dialer.blade.php`
- Add `dropVoicemailOnCall(): void` Livewire method
  - Gets company's default RVM template
  - Calls `ConferenceService::playAudioOnCall()` on contact's call SID
  - Removes agent from conference (session mode) or hangs up
  - Sets `callStatus = 'ended'`, `showDisposition = true`
  - Auto-fills disposition as `voicemail`
- Add "Drop VM" button to the connected call UI (visible when agent is on a call)
- Wire AMD auto-drop: when `DialerCallEnded` arrives with `disposition=voicemail`, auto-advance without showing disposition panel

### 4. `resources/views/pages/prospector/⚡rvm-manager.blade.php`
- Update `saveTemplate()` to convert WebM → MP3 via ffmpeg after upload
- Add conversion helper method: `convertToMp3(string $inputPath): string`
- Ensure audio preview in browser uses the converted MP3 URL
- Update validation to accept webm uploads but store as mp3

### 5. `app/Services/Prospector/RvmService.php`
- Update `getAudioUrl()` to always return a full HTTPS URL (for Twilio `<Play>`)
- Existing `drop()` method stays for standalone RVM (ringless voicemail via new call) — used by drip sequences etc.

---

## Template Source

Company's `default_rvm_template_id` field (already exists on `prospector_companies` table). When dropping a VM:
1. Look up the contact's company → `default_rvm_template_id`
2. If not set → skip auto-drop, agent sees "Machine Detected" indicator
3. If set → use that template's audio for the drop

---

## UI Changes

### Dialer — Connected Call State
Add a "Drop VM" button alongside existing Mute/End Call buttons:
```
[Mute] [Drop VM] [End Call]
```
- Only visible when `callStatus === 'connected'`
- Uses company's default RVM template
- If no template configured, button is disabled with tooltip "No VM template — configure in RVM Manager"

### Dialer — Auto-Drop Behavior
When AMD auto-drops and `DialerCallEnded` arrives with `disposition=voicemail`:
- Skip showing the disposition panel
- Auto-advance to next contact after 500ms delay
- Show brief flash notification: "VM Dropped — advancing..."

---

## Audio Format Details

- **Browser recording**: WebM/Opus (best MediaRecorder support across Chrome, Firefox, Safari)
- **Server conversion**: `ffmpeg -i input.webm -codec:a libmp3lame -qscale:a 2 output.mp3`
- **Stored format**: MP3 (universally playable by Twilio, browsers, and mobile)
- **Upload validation**: Accept `webm`, `mp3`, `wav` — convert non-mp3 to mp3
- **ffmpeg available**: Confirmed on both dev (`6.1.1`) and production (`4.4.2`)

---

## Verification

1. **Auto-drop**: Start session → auto-dial → AMD detects machine → VM plays on contact's call → agent auto-advances → call record shows disposition=voicemail
2. **Manual drop**: Agent on call → clicks "Drop VM" → VM plays → disposition panel shows (pre-filled voicemail)
3. **No template**: Agent on call → "Drop VM" button disabled → AMD machine detection shows indicator only
4. **Recording**: Open RVM Manager → Record tab → record audio → save → file is MP3 → play back in browser works → use for VM drop works
5. **Upload**: Upload existing MP3/WAV → saves correctly → plays via Twilio
6. **Call cleanup**: After VM drop, no orphaned conference connections — call ends cleanly
