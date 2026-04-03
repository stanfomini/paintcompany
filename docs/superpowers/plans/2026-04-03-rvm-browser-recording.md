# RVM In-Browser Recording Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Record" option in the RVM template modal so users can record voicemail directly from their browser mic instead of uploading a file.

**Architecture:** All changes in one file (`⚡rvm-manager.blade.php`). Alpine.js `MediaRecorder` captures audio as WebM/Opus blob. Blob is uploaded to Livewire via `$wire.upload()` and stored identically to file uploads. A `recordedDuration` Livewire property passes the accurate recording length.

**Tech Stack:** Alpine.js, MediaRecorder API, Livewire file uploads, Flux UI components

---

### Task 1: Add Livewire properties and update validation

**Files:**
- Modify: `resources/views/pages/prospector/⚡rvm-manager.blade.php:26-31` (properties) and `:147-174` (saveTemplate)

- [ ] **Step 1: Add `recordedDuration` property**

In the PHP class, after line 30 (`public $audioFile = null;`), add:

```php
public ?int $recordedDuration = null;
```

- [ ] **Step 2: Update validation to accept webm**

In `saveTemplate()`, change both validation rules (lines 155 and 157) from:

```php
$rules['audioFile'] = ['required', 'file', 'mimes:mp3,wav', 'max:10240'];
```
```php
$rules['audioFile'] = ['nullable', 'file', 'mimes:mp3,wav', 'max:10240'];
```

To:

```php
$rules['audioFile'] = ['required', 'file', 'mimes:mp3,wav,webm', 'max:10240'];
```
```php
$rules['audioFile'] = ['nullable', 'file', 'mimes:mp3,wav,webm', 'max:10240'];
```

- [ ] **Step 3: Use recordedDuration when available**

In `saveTemplate()`, change the duration line (line 173) from:

```php
$data['duration_seconds'] = $this->estimateAudioDuration($this->audioFile);
```

To:

```php
$data['duration_seconds'] = $this->recordedDuration ?? $this->estimateAudioDuration($this->audioFile);
```

- [ ] **Step 4: Reset recordedDuration in resetTemplateForm**

In `resetTemplateForm()` (line 334-340), add after `$this->audioFile = null;`:

```php
$this->recordedDuration = null;
```

- [ ] **Step 5: Update file input accept attribute**

On line 681, change:

```html
accept="audio/mp3,audio/wav,audio/mpeg,.mp3,.wav"
```

To:

```html
accept="audio/mp3,audio/wav,audio/mpeg,audio/webm,.mp3,.wav,.webm"
```

- [ ] **Step 6: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add "resources/views/pages/prospector/⚡rvm-manager.blade.php"
git commit -m "feat(prospector): add webm support and recordedDuration for browser recording"
```

---

### Task 2: Build the recording UI in the template modal

**Files:**
- Modify: `resources/views/pages/prospector/⚡rvm-manager.blade.php:650-701` (template modal)

- [ ] **Step 1: Replace the audio file section in the modal**

Replace lines 676-691 (the `<div>` containing the file input, from `<div>` with `<flux:label>{{ __('Audio File') }}` through the closing `</div>` after the `wire:loading` block) with the following:

```blade
<div x-data="{
    mode: 'upload',
    isRecording: false,
    hasRecording: false,
    recordingDuration: 0,
    mediaRecorder: null,
    audioChunks: [],
    recordedUrl: null,
    timerInterval: null,

    async startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus' : 'audio/webm';
            this.mediaRecorder = new MediaRecorder(stream, { mimeType });
            this.audioChunks = [];
            this.recordingDuration = 0;

            this.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) this.audioChunks.push(e.data);
            };

            this.mediaRecorder.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                const blob = new Blob(this.audioChunks, { type: mimeType });
                if (this.recordedUrl) URL.revokeObjectURL(this.recordedUrl);
                this.recordedUrl = URL.createObjectURL(blob);
                this.hasRecording = true;

                // Upload blob to Livewire as a file
                const file = new File([blob], 'recording.webm', { type: mimeType });
                @this.upload('audioFile', file);
                @this.set('recordedDuration', this.recordingDuration);
            };

            this.mediaRecorder.start(1000);
            this.isRecording = true;

            this.timerInterval = setInterval(() => {
                this.recordingDuration++;
                if (this.recordingDuration >= 120) this.stopRecording();
            }, 1000);
        } catch (err) {
            alert('Microphone access denied. Please allow microphone permission and try again.');
        }
    },

    stopRecording() {
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        this.isRecording = false;
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    },

    reRecord() {
        this.hasRecording = false;
        this.recordingDuration = 0;
        if (this.recordedUrl) {
            URL.revokeObjectURL(this.recordedUrl);
            this.recordedUrl = null;
        }
        @this.set('audioFile', null);
        @this.set('recordedDuration', null);
    },

    formatTime(s) {
        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }
}">
    <flux:label class="mb-2">{{ __('Audio') }}</flux:label>

    {{-- Tab toggle --}}
    <div class="mb-3 flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
        <button type="button" @click="mode = 'upload'"
            :class="mode === 'upload' ? 'bg-white shadow dark:bg-zinc-700' : ''"
            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition">
            {{ __('Upload File') }}
        </button>
        <button type="button" @click="mode = 'record'"
            :class="mode === 'record' ? 'bg-white shadow dark:bg-zinc-700' : ''"
            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition">
            {{ __('Record') }}
        </button>
    </div>

    {{-- Upload tab --}}
    <div x-show="mode === 'upload'" x-cloak>
        <input
            type="file"
            wire:model="audioFile"
            accept="audio/mp3,audio/wav,audio/mpeg,audio/webm,.mp3,.wav,.webm"
            class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
        />
        <div wire:loading wire:target="audioFile" class="mt-2">
            <flux:text class="text-sm text-blue-600">{{ __('Uploading...') }}</flux:text>
        </div>
    </div>

    {{-- Record tab --}}
    <div x-show="mode === 'record'" x-cloak>
        {{-- Idle: show record button --}}
        <template x-if="!isRecording && !hasRecording">
            <button type="button" @click="startRecording()"
                class="flex w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed border-zinc-300 px-4 py-6 text-zinc-500 transition hover:border-red-400 hover:text-red-500 dark:border-zinc-600 dark:hover:border-red-500">
                <span class="inline-block size-4 rounded-full bg-red-500"></span>
                <span class="font-medium">{{ __('Click to Record') }}</span>
            </button>
        </template>

        {{-- Recording: show stop + timer --}}
        <template x-if="isRecording">
            <div class="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-900/20">
                <span class="inline-block size-3 animate-pulse rounded-full bg-red-500"></span>
                <span class="font-mono text-lg font-semibold text-red-700 dark:text-red-400" x-text="formatTime(recordingDuration)"></span>
                <span class="text-sm text-red-600 dark:text-red-400">{{ __('Recording...') }}</span>
                <button type="button" @click="stopRecording()"
                    class="ml-auto rounded-md bg-red-600 px-3 py-1 text-sm font-medium text-white hover:bg-red-700">
                    {{ __('Stop') }}
                </button>
            </div>
        </template>

        {{-- Review: play, re-record, use --}}
        <template x-if="!isRecording && hasRecording">
            <div class="space-y-3">
                <div class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 dark:border-green-800 dark:bg-green-900/20">
                    <span class="text-sm font-medium text-green-700 dark:text-green-400" x-text="formatTime(recordingDuration) + ' recorded'"></span>
                </div>
                <audio :src="recordedUrl" controls class="h-10 w-full"></audio>
                <div class="flex gap-2">
                    <button type="button" @click="reRecord()"
                        class="flex-1 rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800">
                        {{ __('Re-record') }}
                    </button>
                </div>
            </div>
        </template>
    </div>

    @error('audioFile')
        <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text>
    @enderror
</div>
```

- [ ] **Step 2: Run pint and build**

```bash
vendor/bin/pint --dirty --format agent
npm run build
```

- [ ] **Step 3: Commit**

```bash
git add "resources/views/pages/prospector/⚡rvm-manager.blade.php"
git commit -m "feat(prospector): add in-browser RVM recording with MediaRecorder API"
```

---

### Task 3: Manual verification

- [ ] **Step 1: Open RVM Manager**

Navigate to `/prospector/rvm` on production.

- [ ] **Step 2: Test upload tab still works**

Click "New Template" → Upload tab → upload an MP3 → save. Verify it creates the template with correct duration.

- [ ] **Step 3: Test record tab**

Click "New Template" → Record tab → click "Click to Record" → speak for 5 seconds → click Stop → listen to playback → enter name + company → Save.

Verify:
- Template appears in list
- Duration shows ~5 seconds (not the old file-size estimate)
- Audio preview plays back correctly

- [ ] **Step 4: Test re-record flow**

Click "New Template" → Record tab → record → stop → click "Re-record" → record again → stop → save. Verify the second recording is saved, not the first.

- [ ] **Step 5: Test 120-second auto-stop**

Start recording and wait. At 2:00 the recording should auto-stop.
