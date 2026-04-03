# RVM In-Browser Recording

## Context

RVM templates currently require uploading a pre-recorded audio file (.mp3/.wav). Users want to record voicemail greetings directly in the browser without leaving the page or using external tools.

## Scope

Add a "Record" option alongside the existing file upload in `⚡rvm-manager.blade.php`. No new files, migrations, or service changes.

## Implementation

### Browser Recording (Alpine + MediaRecorder API)

Alpine data properties:
- `recordingMode: 'upload'` — toggle between 'upload' and 'record'
- `isRecording: false` — mic is actively capturing
- `recordedBlob: null` — the recorded audio blob
- `recordedUrl: null` — object URL for playback preview
- `recordingDuration: 0` — seconds elapsed during recording

Recording flow:
1. User clicks "Record" tab → `recordingMode = 'record'`
2. User clicks red Record button → `navigator.mediaDevices.getUserMedia({ audio: true })`
3. `MediaRecorder` captures audio in `audio/webm` format (broadest browser support)
4. Timer counts up. Max 120 seconds (auto-stop).
5. User clicks Stop → `MediaRecorder.stop()` → blob created
6. Preview: Play/Re-record buttons. User can listen before committing.
7. User clicks "Use This Recording" → blob converted to File, set on Livewire `$this->audioFile` via `$wire.upload('audioFile', file)`

### Server Side (Livewire)

No changes to `saveTemplate()`. The uploaded blob arrives as a regular file upload via Livewire's temporary upload mechanism. Only change:

- Add `webm` to the validation mimes: `mimes:mp3,wav,webm`
- Duration: use the actual `recordingDuration` from Alpine instead of file-size estimate. Pass via a hidden `$wire` property `recordedDuration`.

### UI Layout

In the create/edit modal, replace the single file input with:

```
[Upload File] [Record]          ← tab toggle

--- Upload tab ---
[Choose File] (.mp3, .wav, .webm)

--- Record tab ---
[ ● Record ]                    ← idle state
[ ■ Stop  00:15 ]               ← recording state (red pulse + timer)
[ ▶ Play ] [ ↺ Re-record ] [ ✓ Use This Recording ]  ← review state
```

### Key Files

- `resources/views/pages/prospector/⚡rvm-manager.blade.php` — all changes here

### Audio Format

`MediaRecorder` with `audio/webm;codecs=opus`. WebM/Opus is supported by all modern browsers and Twilio accepts it for playback. Falls back to `audio/webm` if codec not supported.

### Constraints

- Max recording: 120 seconds (auto-stops)
- Requires HTTPS (getUserMedia requirement — already on HTTPS)
- Requires mic permission (browser prompts automatically)

## Verification

1. Open RVM Manager → Create Template → click Record tab
2. Click Record → speak → click Stop
3. Click Play → hear playback
4. Click "Use This Recording" → enter name → Save
5. Verify template appears in list with correct duration
6. Test the template by dropping a voicemail — confirm audio plays correctly
