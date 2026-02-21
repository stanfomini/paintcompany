# Chunked Video Upload + Compression Design

**Date:** 2026-02-21
**Status:** Approved
**Goal:** Enable uploading videos of any size (1GB+) through the Media Hub, with server-side compression to reduce S3 storage costs.

## Problem

The "Upload Video" button in Media Hub uses a single-file XHR POST capped at 200MB. An 8-minute video at high quality can easily exceed 1GB, making it impossible to upload and share via the media hub. The chunked upload infrastructure already exists for the recorder but isn't wired to the file upload flow.

## Solution: Phase 1 — Chunked Upload + Server-Side Compression

### Frontend (`⚡my-videos.blade.php`)

Replace single XHR upload with chunked flow reusing existing backend endpoints:

1. **Start session:** `POST /api/video-recordings/start` with `mime_type`, `title`
2. **Upload chunks:** Slice file into 5MB chunks, `POST /api/video-recordings/{id}/chunk` sequentially
3. **Finalize:** `POST /api/video-recordings/{id}/finalize` — triggers processing
4. **Progress:** Track chunks sent / total chunks for progress bar
5. **Size limit:** Remove 200MB cap, allow up to 5GB
6. **Retry:** On chunk failure, retry that chunk up to 3 times before showing error
7. **Cancel:** Abort mid-upload, clean up server-side temp file

### Backend (`VideoRecordingUploadController.php`)

- `startChunkedUpload()`: Accept `video/quicktime` and `video/x-matroska` mime types (MOV, MKV) in addition to existing mp4/webm
- `store()`: Keep as legacy fallback, no changes needed
- Existing chunk + finalize endpoints work as-is

### Compression (`video_processor.py`)

Update FFmpeg settings for uploaded (non-recorded) videos:

| Setting | Current | New |
|---------|---------|-----|
| Max resolution | 1280x720 | 1920x1080 |
| CRF | 23 | 28 |
| Preset | medium | slow |
| Audio | 128k AAC | 128k AAC (unchanged) |

Expected compression: 1GB source → ~150-250MB processed output.

### Storage Optimization (`ProcessVideoRecordingJob.php`)

After successful processing, delete the original uploaded file from S3. Only keep the compressed/transcoded version + thumbnail.

## Solution: Phase 2 — Direct-to-S3 Multipart (Future)

For multi-GB files where server-proxied chunks are too slow:

- Laravel generates presigned S3 multipart upload URLs
- Frontend uploads chunks directly to S3 (bypasses server)
- S3 CompleteMultipartUpload triggers processing via webhook/polling
- Not building this now; documented for future reference

## Files to Change

| File | Change |
|------|--------|
| `resources/views/components/media/⚡my-videos.blade.php` | Replace `upload()` with chunked upload flow |
| `app/Http/Controllers/VideoRecordingUploadController.php` | Add mime types to `startChunkedUpload` |
| `python/api/services/media/video_processor.py` | Update compression: 1080p max, CRF 28, preset slow |
| `app/Jobs/Python/ProcessVideoRecordingJob.php` | Delete original after successful processing |
| Tests | Add chunked file upload tests, update existing tests |

## Non-Goals

- Browser-side compression (unreliable, complex)
- Direct-to-S3 upload (Phase 2)
- Changing the recorder upload flow (already works)
