# Chunked Video Upload Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the single-file 200MB upload with chunked uploads (reusing existing infrastructure) so users can upload videos of any size, and improve server-side compression to 1080p/CRF 28.

**Architecture:** The frontend upload modal in `⚡my-videos.blade.php` currently sends one XHR POST. We switch it to: start session → slice file into 5MB chunks → upload each chunk → finalize. The backend already has all three endpoints from the recorder feature. We also update the Python video processor to use better compression (1080p max, CRF 28, preset slow).

**Tech Stack:** Alpine.js (frontend upload logic), Laravel (chunk endpoints), Python/FFmpeg (compression)

---

### Task 1: Update Backend — Accept More Mime Types in Chunked Upload

**Files:**
- Modify: `app/Http/Controllers/VideoRecordingUploadController.php:61-100`

**Step 1: Update `startChunkedUpload` to handle MOV/MKV file extensions**

The current extension logic only handles mp4 vs webm. Add support for quicktime (MOV) and matroska (MKV):

```php
// In startChunkedUpload(), replace the extension line:
$extension = match (true) {
    str_contains($request->input('mime_type'), 'mp4') => 'mp4',
    str_contains($request->input('mime_type'), 'quicktime') => 'mov',
    str_contains($request->input('mime_type'), 'matroska') => 'mkv',
    default => 'webm',
};
```

**Step 2: Run existing chunked upload tests to verify nothing breaks**

Run: `php artisan test --compact tests/Feature/VideoRecording/VideoRecordingTest.php --filter="chunked"`
Expected: All chunked upload tests pass.

**Step 3: Write test for MOV/MKV mime types in chunked upload**

Add to `tests/Feature/VideoRecording/VideoRecordingTest.php`:

```php
it('can start chunked upload with mp4 mime type', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/mp4',
            'title' => 'MP4 Upload',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->original_path)->toEndWith('.mp4');
});

it('can start chunked upload with quicktime mime type', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/quicktime',
            'title' => 'MOV Upload',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->original_path)->toEndWith('.mov');
});

it('can start chunked upload with matroska mime type', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/x-matroska',
            'title' => 'MKV Upload',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->original_path)->toEndWith('.mkv');
});
```

**Step 4: Run the new tests**

Run: `php artisan test --compact tests/Feature/VideoRecording/VideoRecordingTest.php --filter="mime type"`
Expected: 3 tests pass.

**Step 5: Commit**

```bash
git add app/Http/Controllers/VideoRecordingUploadController.php tests/Feature/VideoRecording/VideoRecordingTest.php
git commit -m "feat: support MOV/MKV mime types in chunked upload"
```

---

### Task 2: Update Frontend — Switch Upload Modal to Chunked Upload

**Files:**
- Modify: `resources/views/components/media/⚡my-videos.blade.php:204-334` (the `@script` block)

**Step 1: Replace the `upload()` method with chunked upload logic**

Replace the entire `Alpine.data('videoUploader', ...)` block with a chunked upload implementation:

```javascript
Alpine.data('videoUploader', () => ({
    showModal: false,
    file: null,
    title: '',
    uploading: false,
    progress: 0,
    uploadComplete: false,
    errorMessage: '',
    abortController: null,
    cancelled: false,

    // Chunked upload config
    CHUNK_SIZE: 5 * 1024 * 1024, // 5MB chunks
    MAX_FILE_SIZE: 5 * 1024 * 1024 * 1024, // 5GB max
    MAX_RETRIES: 3,

    openModal() {
        this.showModal = true;
        this.resetState();
    },

    closeModal() {
        if (this.uploading) {
            this.cancelled = true;
            if (this.abortController) {
                this.abortController.abort();
            }
        }
        this.showModal = false;
        if (this.uploadComplete) {
            $wire.dispatch('video-uploaded');
        }
    },

    resetState() {
        this.file = null;
        this.title = '';
        this.uploading = false;
        this.progress = 0;
        this.uploadComplete = false;
        this.errorMessage = '';
        this.abortController = null;
        this.cancelled = false;
    },

    selectFile(event) {
        const file = event.target.files[0];
        if (file) this.setFile(file);
    },

    handleDrop(event) {
        const file = event.dataTransfer.files[0];
        if (file) this.setFile(file);
    },

    setFile(file) {
        this.errorMessage = '';
        const allowedTypes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'];

        if (!allowedTypes.includes(file.type)) {
            this.errorMessage = '{{ __("Invalid file type. Please select MP4, WebM, MOV, or MKV.") }}';
            return;
        }

        if (file.size > this.MAX_FILE_SIZE) {
            this.errorMessage = '{{ __("File is too large. Maximum size is 5GB.") }}';
            return;
        }

        this.file = file;
        if (!this.title) {
            this.title = file.name.replace(/\.[^/.]+$/, '').replace(/[_-]/g, ' ');
        }
    },

    formatSize(bytes) {
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    },

    async upload() {
        if (!this.file) return;

        this.uploading = true;
        this.progress = 0;
        this.errorMessage = '';
        this.cancelled = false;

        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const totalChunks = Math.ceil(this.file.size / this.CHUNK_SIZE);

        try {
            // Step 1: Start chunked upload session
            const startResponse = await fetch('/api/video-recordings/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    mime_type: this.file.type,
                    title: this.title || null,
                }),
            });

            if (!startResponse.ok) {
                const err = await startResponse.json().catch(() => ({}));
                throw new Error(err.message || 'Failed to start upload session.');
            }

            const { id: recordingId } = await startResponse.json();

            // Step 2: Upload chunks sequentially
            for (let i = 0; i < totalChunks; i++) {
                if (this.cancelled) return;

                const start = i * this.CHUNK_SIZE;
                const end = Math.min(start + this.CHUNK_SIZE, this.file.size);
                const chunk = this.file.slice(start, end);

                let success = false;
                for (let attempt = 0; attempt < this.MAX_RETRIES; attempt++) {
                    if (this.cancelled) return;

                    try {
                        this.abortController = new AbortController();
                        const formData = new FormData();
                        formData.append('chunk', chunk, `chunk_${i}`);
                        formData.append('chunk_index', i);

                        const chunkResponse = await fetch(`/api/video-recordings/${recordingId}/chunk`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json',
                            },
                            body: formData,
                            signal: this.abortController.signal,
                        });

                        if (!chunkResponse.ok) {
                            const err = await chunkResponse.json().catch(() => ({}));
                            throw new Error(err.message || `Chunk ${i} upload failed.`);
                        }

                        success = true;
                        break;
                    } catch (e) {
                        if (e.name === 'AbortError') return;
                        if (attempt === this.MAX_RETRIES - 1) throw e;
                        // Wait before retry (exponential backoff)
                        await new Promise(r => setTimeout(r, 1000 * (attempt + 1)));
                    }
                }

                if (!success) throw new Error('Failed to upload chunk after retries.');

                this.progress = Math.round(((i + 1) / totalChunks) * 100);
            }

            if (this.cancelled) return;

            // Step 3: Finalize
            const finalizeResponse = await fetch(`/api/video-recordings/${recordingId}/finalize`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });

            if (!finalizeResponse.ok) {
                const err = await finalizeResponse.json().catch(() => ({}));
                throw new Error(err.message || 'Failed to finalize upload.');
            }

            this.uploading = false;
            this.uploadComplete = true;

        } catch (e) {
            if (this.cancelled) return;
            this.uploading = false;
            this.errorMessage = e.message || '{{ __("Upload failed. Please try again.") }}';
        }
    }
}));
```

**Step 2: Update the size display text in the drop zone**

In the template, change the size hint from "200MB" to "5GB":

```blade
<span class="text-xs text-zinc-400">{{ __('MP4, WebM, MOV, MKV up to 5GB') }}</span>
```

**Step 3: Update progress display to show uploaded/total size**

Replace the progress `<p>` tag with more useful info:

```blade
<p class="text-xs text-zinc-500">
    <span x-text="file ? formatSize(file.size * progress / 100) + ' / ' + formatSize(file.size) : ''"></span>
</p>
```

**Step 4: Test manually by verifying the component still renders**

Run: `php artisan test --compact tests/Feature/Media/VideoUploadTest.php --filter="renders the upload button"`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/views/components/media/⚡my-videos.blade.php
git commit -m "feat: switch upload modal to chunked upload for large file support"
```

---

### Task 3: Update Python Video Processor — Better Compression

**Files:**
- Modify: `python/api/services/media/video_processor.py:60-86`

**Step 1: Update the scale and compression settings**

Change max resolution from 1280x720 to 1920x1080 and use CRF 28 with slow preset:

In `video_processor.py`, update the dimension calculation block:

```python
if iw > 0 and ih > 0:
    scale_w = min(1920 / iw, 1.0)
    scale_h = min(1080 / ih, 1.0)
    scale_factor = min(scale_w, scale_h)
    tw = int(iw * scale_factor)
    th = int(ih * scale_factor)
    # Ensure even dimensions (libx264 requirement)
    tw = tw - (tw % 2)
    th = th - (th % 2)
    vf_filter = f"scale={tw}:{th}"
    logger.info(f"Input {iw}x{ih} -> target {tw}x{th}")
```

Update the transcode command:

```python
transcode_cmd = [
    "ffmpeg", "-i", input_path,
    "-c:v", "libx264", "-preset", "slow", "-crf", "28",
    "-c:a", "aac", "-b:a", "128k",
    "-movflags", "+faststart",
    "-vf", vf_filter,
    "-y", video_path,
]
```

**Step 2: Increase the job timeout for larger files**

In `app/Jobs/Python/ProcessVideoRecordingJob.php`, increase the timeout from 300 to 900 seconds (15 minutes) to handle large video transcoding:

```php
public int $timeout = 900;
```

**Step 3: Commit**

```bash
git add python/api/services/media/video_processor.py app/Jobs/Python/ProcessVideoRecordingJob.php
git commit -m "feat: improve video compression - 1080p max, CRF 28, slow preset"
```

---

### Task 4: Write Integration Test for Full Chunked File Upload Flow

**Files:**
- Modify: `tests/Feature/Media/VideoUploadTest.php`

**Step 1: Add a full end-to-end chunked upload test**

Add to `tests/Feature/Media/VideoUploadTest.php`:

```php
describe('Chunked Video Upload (File Upload)', function () {
    it('uploads a large file via chunked upload flow', function () {
        Storage::fake();
        Queue::fake();

        $user = User::factory()->internal()->create();

        // Step 1: Start session
        $startResponse = $this->actingAs($user)
            ->postJson('/api/video-recordings/start', [
                'mime_type' => 'video/mp4',
                'title' => 'Large Training Video',
            ]);

        $startResponse->assertCreated()
            ->assertJsonStructure(['id', 'status', 'share_token']);

        $recordingId = $startResponse->json('id');
        $recording = VideoRecording::find($recordingId);

        // Step 2: Upload multiple chunks (simulating a larger file)
        $chunkData = str_repeat('x', 1024); // 1KB chunks for test

        for ($i = 0; $i < 3; $i++) {
            $chunk = UploadedFile::fake()->createWithContent("chunk_{$i}.mp4", $chunkData);

            $this->actingAs($user)
                ->postJson("/api/video-recordings/{$recordingId}/chunk", [
                    'chunk' => $chunk,
                    'chunk_index' => $i,
                ])
                ->assertSuccessful()
                ->assertJsonPath('received', true)
                ->assertJsonPath('chunk_index', $i);
        }

        // Step 3: Finalize
        $this->actingAs($user)
            ->postJson("/api/video-recordings/{$recordingId}/finalize")
            ->assertSuccessful()
            ->assertJsonPath('status', 'processing');

        // Verify recording was created and is processing
        $recording->refresh();
        expect($recording->status)->toBe('processing')
            ->and($recording->title)->toBe('Large Training Video')
            ->and($recording->file_size_bytes)->toBe(3072); // 3 x 1024

        Queue::assertPushed(ProcessVideoRecordingJob::class);
    });

    it('handles upload cancellation gracefully', function () {
        Storage::fake();

        $user = User::factory()->internal()->create();

        // Start session
        $startResponse = $this->actingAs($user)
            ->postJson('/api/video-recordings/start', [
                'mime_type' => 'video/mp4',
            ]);

        $recordingId = $startResponse->json('id');

        // Upload one chunk
        $chunk = UploadedFile::fake()->createWithContent('chunk_0.mp4', str_repeat('x', 512));
        $this->actingAs($user)
            ->postJson("/api/video-recordings/{$recordingId}/chunk", [
                'chunk' => $chunk,
                'chunk_index' => 0,
            ])
            ->assertSuccessful();

        // Don't finalize - simulating cancellation
        // Recording should stay in uploading state
        $recording = VideoRecording::find($recordingId);
        expect($recording->status)->toBe('uploading');
    });
});
```

**Step 2: Run the new tests**

Run: `php artisan test --compact tests/Feature/Media/VideoUploadTest.php --filter="Chunked Video Upload"`
Expected: 2 tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/Media/VideoUploadTest.php
git commit -m "test: add integration tests for chunked file upload flow"
```

---

### Task 5: Run Full Test Suite & Format Code

**Step 1: Format code with Pint**

Run: `vendor/bin/pint --dirty`

**Step 2: Run all video-related tests**

Run: `php artisan test --compact tests/Feature/VideoRecording/VideoRecordingTest.php tests/Feature/Media/VideoUploadTest.php`
Expected: All tests pass.

**Step 3: Commit any formatting changes**

```bash
git add -A
git commit -m "style: format code with Pint"
```

**Step 4: Ask user if they want to run the full test suite**

---

### Summary of Changes

| File | Change |
|------|--------|
| `VideoRecordingUploadController.php` | Add MOV/MKV extension mapping in `startChunkedUpload` |
| `⚡my-videos.blade.php` | Replace single XHR with chunked upload (start→chunks→finalize), 5GB limit, retry logic, cancel support |
| `video_processor.py` | 1920x1080 max, CRF 28, preset slow |
| `ProcessVideoRecordingJob.php` | Increase timeout to 900s |
| `VideoUploadTest.php` | Add chunked upload integration tests |
| `VideoRecordingTest.php` | Add mime type tests for MOV/MKV |
