# Video Call System - Job Walk Implementation Plan

## Overview
Build a production-ready video call system integrated into the Job Progress page. Uses WebRTC P2P with Reverb signaling, HLS recording via Python bridge, and S3-ready storage.

## Current State
- Working test page at `/video-call-test` using Reverb presence channels for signaling
- WebRTC P2P with STUN servers (Google public)
- Multi-guard auth (internal, customer, crew, agent)
- Layout: `layouts/video-call.blade.php` (works for all user types)
- Broadcast channel: `video-call.{roomId}` in `routes/channels.php`
- Python FastAPI service exists at `python/api/` with media endpoints (currently unused for video)
- Python service has ZERO authentication - must be secured before production

## Key Files
- `resources/views/components/video-call/⚡test.blade.php` - Current working test page
- `routes/channels.php` - Broadcast channel auth
- `routes/web.php` - Video call test route
- `layouts/video-call.blade.php` - Multi-guard layout
- `python/api/routers/media.py` - Python media endpoints
- `python/api/services/media/` - Audio/video services
- `app/Services/PythonBridgeService.php` - Laravel↔Python HTTP client
- `config/python.php` - Python service config
- `app/Models/Job.php` - Job model
- `resources/views/components/crew/job-progress/` - Job progress views

---

## SECTION 1: Video Quality & Participant Limits

### 1A. Lower Video Quality for Mobile
**File**: `resources/views/components/video-call/⚡test.blade.php`

Change the `getUserMedia` constraints from:
```js
video: {
    facingMode: this.useFrontCamera ? 'user' : 'environment',
    width: { ideal: 1280 },
    height: { ideal: 720 },
}
```

To optimized mobile-friendly settings:
```js
video: {
    facingMode: this.useFrontCamera ? 'user' : 'environment',
    width: { ideal: 640, max: 960 },
    height: { ideal: 360, max: 540 },
    frameRate: { ideal: 20, max: 24 },
}
```

Also add bandwidth constraints on the RTCPeerConnection after creating the offer/answer:
```js
// After pc.createOffer() or pc.createAnswer()
const sender = pc.getSenders().find(s => s.track?.kind === 'video');
if (sender) {
    const params = sender.getParameters();
    if (!params.encodings) params.encodings = [{}];
    params.encodings[0].maxBitrate = 500000; // 500kbps - good for mobile
    params.encodings[0].maxFramerate = 20;
    await sender.setParameters(params);
}
```

This keeps wall colors visible (640x360 is still clear) while being 4x lighter than 1280x720.

### 1B. Max 2 Video Streams + Audio-Only After That
Add a `maxVideoParticipants: 2` config. When 2 video streams exist, new participants join audio-only.

In the Alpine data:
```js
maxVideoParticipants: 2,
isVideoParticipant: false, // Set during join
```

When joining:
- Count existing video participants from the presence `.here()` callback
- If < 2 video slots available → join with video
- If >= 2 video slots → join audio-only (skip getUserMedia video track)
- Whisper a `participant-type` message so others know who has video

### 1C. Presenter Mode Selection
When starting a call, show a pre-call lobby screen:
- "Join as Presenter" (video + audio, your camera shows to everyone)
- "Join as Viewer" (audio only, you can talk but no video)
- Only 2 presenter slots available; if both taken, presenter button is disabled

---

## SECTION 2: Job-Integrated Video Rooms

### 2A. Database: Add video_calls table
```bash
php artisan make:migration create_video_calls_table
```

Schema:
```php
Schema::create('video_calls', function (Blueprint $table) {
    $table->id();
    $table->foreignId('job_id')->constrained('painting_jobs')->cascadeOnDelete();
    $table->foreignId('initiated_by')->constrained('users');
    $table->string('room_id')->unique(); // UUID for the Reverb channel
    $table->string('status')->default('waiting'); // waiting, active, ended
    $table->string('recording_path')->nullable(); // S3/local path to HLS recording
    $table->string('recording_disk')->nullable(); // 'local' or 's3'
    $table->integer('duration_seconds')->nullable();
    $table->json('participants')->nullable(); // [{user_id, name, type, joined_at, left_at}]
    $table->timestamp('started_at')->nullable();
    $table->timestamp('ended_at')->nullable();
    $table->timestamps();
});
```

### 2B. VideoCall Model
```bash
php artisan make:model VideoCall --factory
```

Key relationships:
```php
public function job(): BelongsTo { return $this->belongsTo(Job::class, 'job_id'); }
public function initiator(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by'); }
```

Accessors:
```php
public function getRecordingUrlAttribute(): ?string
{
    if (!$this->recording_path) return null;
    $disk = $this->recording_disk ?? config('filesystems.default');
    return Storage::disk($disk)->temporaryUrl($this->recording_path, now()->addHours(2));
}
```

### 2C. Job Progress Page Integration
**File to modify**: The job progress Livewire component (check `resources/views/components/crew/job-progress/` or similar)

Add a "Start Video Call" button in the job progress page:
- Shows for internal users, crew assigned to the job, and the customer
- When clicked → creates a `VideoCall` record with `status: waiting` and broadcasts to the job channel
- Other participants see an incoming call notification (use existing job broadcast channel `job.{jobId}`)

Create a new Livewire SFC component: `resources/views/components/video-call/⚡job-call.blade.php`
- Takes `$jobId` as parameter
- Shows the pre-call lobby (presenter vs viewer selection)
- Embeds the video call UI
- Can be shown as a modal or expandable panel within the job page
- "Full Screen" button to expand

### 2D. Incoming Call Notification
Use the existing broadcast channel `job.{jobId}` to send a Laravel event:

```bash
php artisan make:event VideoCallStarted
```

```php
class VideoCallStarted implements ShouldBroadcast
{
    public function __construct(
        public VideoCall $videoCall,
        public string $initiatorName,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('job.' . $this->videoCall->job_id)];
    }
}
```

On the job progress page, listen for this event and show a call notification banner:
- "🎥 {name} started a video call" with "Join" and "Dismiss" buttons
- Auto-dismiss after 60 seconds
- Plays a notification sound (optional)

### 2E. Update Broadcast Channel Auth
Add a dedicated channel for video call presence if needed, or reuse the job channels.
The existing `job.{jobId}`, `customer.job.{jobId}`, and `crew.job.{jobId}` channels already handle per-role auth.

For the video call room itself, update `routes/channels.php`:
```php
Broadcast::channel('video-call.{roomId}', function (User $user, string $roomId) {
    // Verify user has access to the job associated with this room
    $videoCall = \App\Models\VideoCall::where('room_id', $roomId)->first();
    if (!$videoCall) return false;

    $job = $videoCall->job;
    // Check if user is internal, assigned crew, or the customer
    if ($user->isInternal()) return ['id' => $user->id, 'name' => $user->name];
    if ($job->crews()->where('crew_user_id', $user->id)->exists()) return ['id' => $user->id, 'name' => $user->name];
    if ($job->deal?->customer?->user_id === $user->id) return ['id' => $user->id, 'name' => $user->name];

    return false;
}, ['guards' => ['web', 'agent', 'customer', 'crew']]);
```

---

## SECTION 3: Python Service Security (MUST DO FIRST)

### 3A. Add API Key Authentication to Python Service

**File**: `python/api/config.py` - Add:
```python
python_api_key: str = ""  # Required in production
```

**File**: `python/api/main.py` - Add middleware:
```python
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import JSONResponse

class APIKeyMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request, call_next):
        # Allow health check without auth
        if request.url.path in ["/", "/health"]:
            return await call_next(request)

        # Skip auth if no key configured (development)
        if not settings.python_api_key:
            return await call_next(request)

        api_key = request.headers.get("X-API-Key")
        if api_key != settings.python_api_key:
            return JSONResponse(status_code=403, content={"detail": "Invalid API key"})

        return await call_next(request)

app.add_middleware(APIKeyMiddleware)
```

For WebSocket endpoints, validate via query parameter:
```python
@router.websocket("/ws/video")
async def websocket_video_stream(websocket: WebSocket):
    token = websocket.query_params.get("token")
    if settings.python_api_key and token != settings.python_api_key:
        await websocket.close(code=4001, reason="Unauthorized")
        return
    await websocket.accept()
    # ... rest of handler
```

### 3B. Update Laravel PythonBridgeService
**File**: `app/Services/PythonBridgeService.php`

Add API key header to all requests:
```php
protected function client(?int $timeout = null): PendingRequest
{
    return Http::baseUrl($this->baseUrl)
        ->timeout($timeout ?? $this->timeout)
        ->retry($this->retryAttempts, $this->retryDelay)
        ->withHeaders([
            'X-API-Key' => config('python.api_key'),
        ])
        ->acceptJson()
        ->asJson();
}
```

### 3C. Update config/python.php
Add:
```php
'api_key' => env('PYTHON_API_KEY', ''),
```

### 3D. Add to .env.example
```
PYTHON_API_KEY=  # Generate with: php -r "echo bin2hex(random_bytes(32));"
```

---

## SECTION 4: HLS Recording via Python Bridge

### 4A. Python HLS Recording Service
**New file**: `python/api/services/media/hls_recorder.py`

Architecture:
- Client (browser) uses MediaRecorder API to capture the video stream
- Sends video chunks to Laravel via a POST endpoint
- Laravel forwards to Python service
- Python uses FFmpeg to convert chunks to HLS segments (.m3u8 + .ts files)
- Segments stored to local disk or S3

The flow:
1. When recording starts, browser creates a MediaRecorder on the remote stream
2. Every 5 seconds, the recorder produces a blob chunk
3. Chunk is uploaded to `/api/video-calls/{id}/chunk` (Laravel endpoint)
4. Laravel streams it to Python `POST /media/record/chunk`
5. Python appends to FFmpeg process stdin
6. FFmpeg outputs HLS segments to a temp directory
7. When recording stops, Python finalizes the HLS manifest
8. Laravel moves the final HLS files to the configured storage disk

Python HLS recorder class:
```python
class HLSRecorder:
    def __init__(self, recording_id: str, output_dir: str):
        self.recording_id = recording_id
        self.output_dir = output_dir
        self.process = None  # FFmpeg subprocess

    async def start(self):
        """Start FFmpeg process for HLS segmenting."""
        os.makedirs(self.output_dir, exist_ok=True)
        self.process = await asyncio.create_subprocess_exec(
            'ffmpeg', '-i', 'pipe:0',
            '-c:v', 'libx264', '-preset', 'ultrafast',
            '-c:a', 'aac',
            '-f', 'hls',
            '-hls_time', '6',           # 6-second segments
            '-hls_list_size', '0',       # Keep all segments in manifest
            '-hls_segment_filename', f'{self.output_dir}/segment_%04d.ts',
            f'{self.output_dir}/playlist.m3u8',
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )

    async def write_chunk(self, data: bytes):
        """Write a media chunk to FFmpeg."""
        if self.process and self.process.stdin:
            self.process.stdin.write(data)
            await self.process.stdin.drain()

    async def stop(self) -> str:
        """Stop recording and return the manifest path."""
        if self.process and self.process.stdin:
            self.process.stdin.close()
            await self.process.wait()
        return f'{self.output_dir}/playlist.m3u8'
```

### 4B. Python Recording Endpoints
**File**: `python/api/routers/media.py` - Add:

```python
@router.post("/record/start")
async def start_recording(request: StartRecordingRequest):
    """Start an HLS recording session."""
    recorder = HLSRecorder(request.recording_id, output_dir=f"/tmp/recordings/{request.recording_id}")
    await recorder.start()
    # Store recorder in registry
    return {"status": "recording", "recording_id": request.recording_id}

@router.post("/record/chunk")
async def upload_chunk(recording_id: str = Form(...), chunk: UploadFile = File(...)):
    """Receive a media chunk and pipe to FFmpeg."""
    data = await chunk.read()
    recorder = recording_registry.get(recording_id)
    if recorder:
        await recorder.write_chunk(data)
    return {"status": "ok", "bytes_received": len(data)}

@router.post("/record/stop")
async def stop_recording(request: StopRecordingRequest):
    """Stop recording and return the HLS manifest path."""
    recorder = recording_registry.get(request.recording_id)
    if recorder:
        manifest_path = await recorder.stop()
        return {"status": "complete", "manifest_path": manifest_path}
    return {"status": "not_found"}
```

### 4C. Laravel Recording Controller
**New file**: `app/Http/Controllers/VideoCallRecordingController.php`

Endpoints:
- `POST /api/video-calls/{videoCall}/recording/start` - Starts recording
- `POST /api/video-calls/{videoCall}/recording/chunk` - Receives chunk from browser, forwards to Python
- `POST /api/video-calls/{videoCall}/recording/stop` - Stops recording, moves files to storage

### 4D. Browser-Side Recording
In the video call Alpine component, when recording is enabled:
```js
startRecording() {
    const stream = this.getRecordableStream(); // Combined remote + local streams
    this.mediaRecorder = new MediaRecorder(stream, {
        mimeType: 'video/webm;codecs=vp8,opus',
        videoBitsPerSecond: 500000,
    });

    this.mediaRecorder.ondataavailable = async (event) => {
        if (event.data.size > 0) {
            const formData = new FormData();
            formData.append('chunk', event.data);
            formData.append('recording_id', this.recordingId);
            await fetch(`/api/video-calls/${this.videoCallId}/recording/chunk`, {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
        }
    };

    this.mediaRecorder.start(5000); // Chunk every 5 seconds
}
```

---

## SECTION 5: S3-Ready Storage with Signed URLs

### 5A. Filesystem Configuration
**File**: `config/filesystems.php` - Add a disk:

```php
'video-recordings' => [
    'driver' => env('VIDEO_RECORDINGS_DISK', 'local'),
    'root' => storage_path('app/video-recordings'),
    // When switching to S3/Wasabi:
    // 'driver' => 's3',
    // 'key' => env('WASABI_ACCESS_KEY_ID'),
    // 'secret' => env('WASABI_SECRET_ACCESS_KEY'),
    // 'region' => env('WASABI_DEFAULT_REGION', 'us-east-1'),
    // 'bucket' => env('WASABI_BUCKET'),
    // 'endpoint' => env('WASABI_ENDPOINT', 'https://s3.wasabisys.com'),
    // 'use_path_style_endpoint' => true,
],
```

### 5B. Environment Variables (.env.example)
```
# Video Recording Storage
VIDEO_RECORDINGS_DISK=local

# Wasabi S3 (for production)
# VIDEO_RECORDINGS_DISK=s3
# WASABI_ACCESS_KEY_ID=
# WASABI_SECRET_ACCESS_KEY=
# WASABI_DEFAULT_REGION=us-east-1
# WASABI_BUCKET=apex-video-recordings
# WASABI_ENDPOINT=https://s3.wasabisys.com
```

### 5C. Recording Storage Service
**New file**: `app/Services/VideoRecordingService.php`

```php
class VideoRecordingService
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('filesystems.disks.video-recordings.driver', 'local');
    }

    public function store(VideoCall $videoCall, string $localHlsDirectory): void
    {
        $basePath = "recordings/{$videoCall->job_id}/{$videoCall->id}";

        // Upload all HLS files (manifest + segments)
        foreach (File::files($localHlsDirectory) as $file) {
            Storage::disk('video-recordings')->putFileAs(
                $basePath,
                $file,
                $file->getFilename()
            );
        }

        $videoCall->update([
            'recording_path' => "{$basePath}/playlist.m3u8",
            'recording_disk' => 'video-recordings',
        ]);
    }

    public function getPlaybackUrl(VideoCall $videoCall): ?string
    {
        if (!$videoCall->recording_path) return null;

        return Storage::disk('video-recordings')
            ->temporaryUrl($videoCall->recording_path, now()->addHours(4));
    }
}
```

### 5D. HLS Playback Component
Create a simple HLS video player component using hls.js:

**File**: `resources/views/components/video-call/⚡recording-player.blade.php`

Uses hls.js (CDN or npm) to play .m3u8 files from signed S3 URLs:
```js
import Hls from 'hls.js';

if (Hls.isSupported()) {
    const hls = new Hls();
    hls.loadSource(signedUrl);
    hls.attachMedia(videoElement);
}
```

### 5E. Recording Access in Job Progress
In the job progress page, show a "Recordings" section:
- Lists all completed video calls for this job with timestamps and duration
- Click to play → opens the HLS player with a signed URL
- Signed URLs expire after 4 hours (configurable)
- Only participants of the job can access recordings

---

## SECTION 6: Testing Plan

### Feature Tests
1. `VideoCallCreationTest` - Creating video calls for jobs
2. `VideoCallAuthorizationTest` - Only job participants can join/view
3. `VideoCallRecordingTest` - Recording start/stop/storage
4. `VideoCallBroadcastTest` - Events broadcast correctly
5. `VideoCallPresenceChannelTest` - Channel auth for all user types

### Browser Tests (Pest 4)
1. Start a video call from job progress page
2. Join as viewer (audio-only)
3. Recording playback works

---

## Implementation Order

1. **Python Security** (Section 3) - API key auth, must be done first
2. **Video Quality** (Section 1A) - Quick win, change constraints
3. **Participant Limits** (Section 1B-1C) - Presenter/viewer mode
4. **Database & Model** (Section 2A-2B) - VideoCall table
5. **Job Integration** (Section 2C-2E) - UI in job progress page
6. **HLS Recording** (Section 4) - Python FFmpeg pipeline
7. **S3 Storage** (Section 5) - Storage service + signed URLs
8. **Tests** (Section 6) - Full test coverage

## Dependencies
- FFmpeg must be installed on the server (`sudo apt install ffmpeg`)
- hls.js npm package for playback (`npm install hls.js`)
- Python service must be running for recording (not needed for basic calls)
- Wasabi S3 credentials for production storage
