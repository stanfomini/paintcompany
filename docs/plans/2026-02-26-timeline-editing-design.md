# Studio Timeline Editing Design

**Date**: 2026-02-26
**Status**: Approved

## Overview

Add professional timeline editing to the Studio Editor: trim handles on video clips and voiceovers, timeline zoom, drag-to-reposition for all items, independent track durations, and caption generation from voiceover audio.

## Requirements

- Non-destructive trimming (in/out points, original files untouched)
- Timeline auto-expands to show the longest track
- Trim handles show on hover (consistent with existing text/caption overlay pattern)
- Zoom in/out on timeline for precision editing
- All items draggable to reposition on timeline
- Caption generation from voiceover audio (not just video)

## Design

### 1. Database: Voiceover Trim Fields

Add `in_point_ms` and `out_point_ms` to `video_project_voiceovers` table. Video clips already have these fields.

```
video_project_voiceovers:
  + in_point_ms   unsigned int, default 0
  + out_point_ms  unsigned int, nullable
```

### 2. Timeline Duration = Longest Track

Compute `timelineDurationMs` as the max of:
- Scene's stored `duration_ms`
- Video clips: `clip position + clip effective duration`
- Voiceovers: `vo.start_ms + vo.getEffectiveDurationMs()`
- Overlay end times

All tracks share this time scale so everything aligns visually. If audio extends past video, you see it.

### 3. Trim Handles on Video Clips

Left/right resize handles on the blue video clip bars (show on hover). Left handle = `in_point_ms`, right handle = `out_point_ms`. New `startResizeClip()` Alpine method calls `$wire.updateClip()`.

### 4. Trim Handles on Voiceovers

Same pattern on purple voiceover bars. Left = `in_point_ms` (trim dead space), right = `out_point_ms` (trim end). Body drag still repositions via `start_ms`.

### 5. Timeline Zoom

- Zoom slider below timeline (range: fit-to-window through 10x)
- Scroll wheel on timeline area zooms in/out
- When zoomed in, timeline scrolls horizontally
- "Fit" button snaps to see everything
- Zoom centers on playhead

### 6. Drag to Reposition Video Clips

Video clips become draggable on timeline body. Dragging adjusts the clip's `in_point_ms` position within the scene. Same pattern as existing voiceover drag.

### 7. Caption Source Selection

Replace single "Generate" button with dropdown:
- "From video audio" (existing behavior)
- "From voiceover" (transcribes voiceover recording)

`CaptionGenerationService::generateForScene()` accepts optional voiceover source. Voiceover's `start_ms` offsets caption timestamps for timeline alignment.

### 8. Playback Honors Trim Points

During playback, videos start at `in_point_ms / 1000` and stop at `out_point_ms / 1000`. Same for voiceover audio elements.

## Files to Change

| File | Change |
|------|--------|
| New migration | Add `in_point_ms`, `out_point_ms` to voiceovers |
| `VideoProjectVoiceover.php` | Add fields, add `getDurationMs()` |
| `CaptionGenerationService.php` | Accept optional voiceover source |
| `GenerateSceneCaptionsJob.php` | Pass source type through |
| `⚡editor.blade.php` (PHP) | Update `generateCaptions()`, `updateVoiceover()`, `projectData` |
| `⚡editor.blade.php` (Blade) | Trim handles, zoom slider, caption dropdown, timeline duration |
| `⚡editor.blade.php` (Alpine) | Resize/drag methods, zoom, playback trim |
| Tests | Voiceover trim, clip trim, caption from voiceover |

## Existing Infrastructure

- `VideoProjectClip` already has `in_point_ms`, `out_point_ms`, `getDurationMs()`
- `updateClip()` already allows `in_point_ms`, `out_point_ms` fields
- Text/caption overlays already have working resize handles (`startResize()`)
- Voiceovers already have drag-to-reposition (`startDragVoiceover()`)
- `PythonBridgeService::transcribeAudio()` handles audio transcription
